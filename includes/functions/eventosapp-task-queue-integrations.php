<?php
/**
 * EventosApp - Integraciones de la cola central de tareas.
 *
 * Conecta los procesos existentes de correo masivo, WhatsApp masivo,
 * WhatsApp Flows, confirmación de asistencia y recordatorios programados
 * con el motor central sin reemplazar sus funciones de envío por ticket.
 *
 * Ruta: includes/functions/eventosapp-task-queue-integrations.php
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Correo donde se notificará una tarea creada desde el administrador.
 */
function eventosapp_task_queue_current_notification_email() {
    $user = wp_get_current_user();
    $email = $user && $user->ID ? sanitize_email($user->user_email) : '';
    if ( ! $email || ! is_email($email) ) {
        $email = sanitize_email(get_option('admin_email'));
    }
    return is_email($email) ? $email : '';
}

function eventosapp_task_queue_ticket_ids($value) {
    return is_array($value)
        ? array_values(array_filter(array_unique(array_map('absint', $value))))
        : [];
}

function eventosapp_task_queue_task_url($task_id) {
    return add_query_arg(
        ['page'=>'eventosapp_task_queue','task_id'=>absint($task_id)],
        admin_url('admin.php')
    );
}

/**
 * Activa una tarea vencida sin ignorar una pausa administrativa.
 */
function eventosapp_task_queue_activate_due_task($task_id) {
    $task = eventosapp_task_queue_get($task_id);
    if ( ! $task || in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) || $task['status'] === 'paused' ) return false;

    $reconciled = eventosapp_task_queue_reconcile_scheduled_task($task_id);
    if ( in_array($reconciled, eventosapp_task_queue_terminal_statuses(), true) ) return false;

    $task = eventosapp_task_queue_get($task_id);
    if ( ! $task || in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) ) return false;

    if ( $task['status'] === 'scheduled' && (!$task['scheduled_at'] || strtotime($task['scheduled_at'] . ' UTC') <= time()) ) {
        eventosapp_task_queue_update($task_id, [
            'status'      => 'queued',
            'next_run_at' => current_time('mysql', true),
        ]);
    }
    eventosapp_task_queue_kick();
    return true;
}

/**
 * Contexto horario normalizado para guardar junto a una programación.
 */
function eventosapp_task_queue_schedule_context($event_id, $timestamp) {
    $event_id = absint($event_id);
    $timestamp = absint($timestamp);
    $timezone = eventosapp_task_queue_event_timezone_object($event_id);
    $local = $timestamp > 0
        ? (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)
        : null;

    return [
        'planned_timestamp' => $timestamp,
        'event_timezone'    => $timezone->getName(),
        'utc_offset'        => $local ? $local->format('P') : '',
        'scheduled_local'   => $local ? $local->format('Y-m-d H:i:s') : '',
        'scheduled_utc'     => $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) : '',
    ];
}

function eventosapp_task_queue_store_schedule_context($task_id, $event_id, $timestamp) {
    $task = eventosapp_task_queue_get($task_id);
    if ( ! $task ) return false;

    $timestamp = absint($timestamp);
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    if ( ! empty($payload['manual_schedule_override']) ) return true;

    $payload = array_merge($payload, eventosapp_task_queue_schedule_context($event_id, $timestamp));
    $updates = ['payload'=>$payload];

    /*
     * Versiones anteriores pudieron guardar scheduled_at como "ahora + 5".
     * Restauramos la equivalencia UTC real para impedir tanto ejecuciones
     * anticipadas como envíos tardíos. Una tarea que ya está corriendo conserva
     * su turno actual, pero su fecha planificada queda corregida para auditoría
     * y reconciliación.
     */
    if ( $timestamp > 0 && ($task['task_group'] ?? '') === 'scheduled' ) {
        $scheduled_utc = gmdate('Y-m-d H:i:s', $timestamp);
        $updates['scheduled_at'] = $scheduled_utc;

        if ( ! in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) && $task['status'] !== 'running' && $task['status'] !== 'paused' ) {
            $updates['status'] = $timestamp > time() ? 'scheduled' : 'queued';
            $updates['next_run_at'] = $timestamp > time() ? $scheduled_utc : current_time('mysql', true);
        }
    }

    return eventosapp_task_queue_update($task_id, $updates);
}

/**
 * Recupera el instante originalmente planificado por cada módulo.
 *
 * Esto corrige tareas antiguas cuyo scheduled_at fue reemplazado por la hora
 * de migración. Una reprogramación manual de la cola siempre tiene prioridad.
 */
function eventosapp_task_queue_integrations_planned_timestamp($timestamp, $task) {
    $task = is_array($task) ? $task : [];
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];

    if ( ! empty($payload['manual_schedule_override']) && ! empty($payload['planned_timestamp']) ) {
        return absint($payload['planned_timestamp']);
    }
    if ( ! empty($payload['planned_timestamp']) ) return absint($payload['planned_timestamp']);

    $event_id = absint($task['event_id'] ?? ($payload['event_id'] ?? 0));
    $task_type = sanitize_key((string)($task['task_type'] ?? ''));

    if ( $task_type === 'email_reminder_scheduled' && $event_id && function_exists('eventosapp_event_reminder_send_timestamp') ) {
        $source_timestamp = absint(eventosapp_event_reminder_send_timestamp($event_id));
        if ( $source_timestamp ) return $source_timestamp;
    }

    if ( $task_type === 'whatsapp_reminder_scheduled' && $event_id && function_exists('eventosapp_ticket_reminders_calculate_run_timestamp') ) {
        $item = is_array($payload['item'] ?? null) ? $payload['item'] : [];
        if ( empty($item) && function_exists('eventosapp_ticket_reminders_find_item') ) {
            $item = eventosapp_ticket_reminders_find_item($event_id, sanitize_key((string)($payload['reminder_id'] ?? '')));
        }
        if ( ! empty($item) ) {
            $source_timestamp = absint(eventosapp_ticket_reminders_calculate_run_timestamp($event_id, $item));
            if ( $source_timestamp ) return $source_timestamp;
        }
    }

    if ( $task_type === 'attendance_scheduled' && $event_id ) {
        $config = get_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule', true);
        $config = is_array($config) ? $config : [];
        $task_schedule_id = sanitize_key((string)($payload['schedule_id'] ?? ''));
        $config_schedule_id = sanitize_key((string)($config['schedule_id'] ?? ''));
        if ( ! empty($config['timestamp']) && ($task_schedule_id === '' || $config_schedule_id === $task_schedule_id) ) {
            return absint($config['timestamp']);
        }
    }

    return absint($timestamp);
}
add_filter('eventosapp_task_queue_planned_timestamp', 'eventosapp_task_queue_integrations_planned_timestamp', 10, 2);

/**
 * Convierte fechas históricas guardadas con current_time('mysql') a UTC sin
 * depender de la zona horaria configurada en PHP o en el servidor.
 */
function eventosapp_task_queue_history_site_datetime_to_utc($value) {
    $value = sanitize_text_field((string)$value);
    if ( $value === '' ) return '';

    try {
        return (new DateTimeImmutable($value, wp_timezone()))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Evidencia de ejecución tomada de los módulos históricos. Esto permite que la
 * cola diferencie una programación realmente ejecutada de una que simplemente
 * quedó en el pasado sin haberse enviado.
 */
function eventosapp_task_queue_integrations_execution_evidence($evidence, $task) {
    $evidence = is_array($evidence) ? $evidence : [];
    $task = is_array($task) ? $task : [];
    if ( ! empty($evidence['executed']) ) return $evidence;

    $event_id = absint($task['event_id'] ?? 0);
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    if ( ! $event_id ) return $evidence;

    if ( ($task['task_type'] ?? '') === 'email_reminder_scheduled' ) {
        $stats = get_post_meta($event_id, '_eventosapp_reminder_dispatch_stats', true);
        $stats = is_array($stats) ? $stats : [];
        $done_at = (string)get_post_meta($event_id, '_eventosapp_reminder_done', true);
        $completed_value = (string)($stats['completed_at'] ?? $done_at);
        $completed_ts = 0;
        $completed_utc = eventosapp_task_queue_history_site_datetime_to_utc($completed_value);
        if ( $completed_utc !== '' ) {
            try {
                $completed_ts = (new DateTimeImmutable($completed_utc, new DateTimeZone('UTC')))->getTimestamp();
            } catch (Throwable $e) {
                $completed_ts = 0;
            }
        }
        $scheduled_ts = eventosapp_task_queue_planned_timestamp($task);
        $same_queue = ! empty($stats['queue_task_id']) && absint($stats['queue_task_id']) === absint($task['id'] ?? 0);
        $has_completion = ! empty($stats['completed']) || $completed_ts > 0;
        $executed = $has_completion && ($same_queue || ($completed_ts > 0 && (!$scheduled_ts || $completed_ts >= ($scheduled_ts - 300))));
        if ( $executed ) {
            $success = absint($stats['sent_ok'] ?? 0);
            $errors = absint($stats['sent_fail'] ?? 0);
            $skipped = absint($stats['already_sent'] ?? 0) + absint($stats['no_email'] ?? 0) + absint($stats['filtered_out'] ?? 0);
            $processed = $success + $errors + $skipped;
            return [
                'executed'        => true,
                'source'          => 'email_reminder_history',
                'completed_at'    => $completed_ts ? gmdate('Y-m-d H:i:s', $completed_ts) : '',
                'total_items'     => max(absint($stats['queue_size'] ?? 0), $processed),
                'processed_items' => $processed,
                'success_items'   => $success,
                'error_items'     => $errors,
                'skipped_items'   => $skipped,
                'message'         => 'El módulo de recordatorios por correo registró la finalización.',
            ];
        }
    }

    if ( ($task['task_type'] ?? '') === 'whatsapp_reminder_scheduled' ) {
        $reminder_id = sanitize_key((string)($payload['reminder_id'] ?? ''));
        $signature = (string)($payload['signature'] ?? '');
        $executed_map = function_exists('eventosapp_ticket_reminders_get_executed_map')
            ? eventosapp_ticket_reminders_get_executed_map($event_id)
            : (defined('EVENTOSAPP_TICKET_REMINDERS_META_EXECUTED') ? get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_EXECUTED, true) : []);
        $executed_map = is_array($executed_map) ? $executed_map : [];
        $row = is_array($executed_map[$reminder_id] ?? null) ? $executed_map[$reminder_id] : [];
        $same_signature = $row && ($signature === '' || (! empty($row['signature']) && hash_equals((string)$row['signature'], $signature)));
        if ( $same_signature ) {
            $summary = is_array($row['summary'] ?? null) ? $row['summary'] : [];
            $success = absint($summary['sent_total'] ?? $summary['whatsapp_sent'] ?? 0);
            $errors = absint($summary['error_total'] ?? 0);
            $skipped = absint($summary['skipped_total'] ?? 0);
            $processed = $success + $errors + $skipped;
            return [
                'executed'        => true,
                'source'          => 'whatsapp_reminder_history',
                'completed_at'    => eventosapp_task_queue_history_site_datetime_to_utc($row['date'] ?? ''),
                'total_items'     => max(absint($summary['total_tickets'] ?? 0), $processed),
                'processed_items' => $processed,
                'success_items'   => $success,
                'error_items'     => $errors,
                'skipped_items'   => $skipped,
                'message'         => 'El historial del recordatorio WhatsApp contiene la misma firma de programación.',
            ];
        }
    }

    if ( ($task['task_type'] ?? '') === 'attendance_scheduled' ) {
        $config = get_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule', true);
        $config = is_array($config) ? $config : [];
        $schedule_id = sanitize_key((string)($payload['schedule_id'] ?? ''));
        $same_schedule = $schedule_id === '' || sanitize_key((string)($config['schedule_id'] ?? '')) === $schedule_id;
        $same_task = ! empty($config['last_queue_task_id']) && absint($config['last_queue_task_id']) === absint($task['id'] ?? 0);
        if ( $same_schedule && ($same_task || ! empty($config['last_finished_at'])) ) {
            return [
                'executed'        => true,
                'source'          => 'attendance_schedule_history',
                'completed_at'    => eventosapp_task_queue_history_site_datetime_to_utc($config['last_finished_at'] ?? ''),
                'total_items'     => absint($task['total_items'] ?? 0),
                'processed_items' => absint($task['processed_items'] ?? 0),
                'success_items'   => absint($task['success_items'] ?? 0),
                'error_items'     => absint($task['error_items'] ?? 0),
                'skipped_items'   => absint($task['skipped_items'] ?? 0),
                'message'         => 'La programación de confirmación registra una finalización previa.',
            ];
        }
    }

    return $evidence;
}
add_filter('eventosapp_task_queue_execution_evidence', 'eventosapp_task_queue_integrations_execution_evidence', 10, 2);

/**
 * Devuelve un resultado normalizado para cada elemento.
 */
function eventosapp_task_queue_item_result($ok, $message = '', $skipped = false, $context = []) {
    return [
        'ok'      => (bool)$ok,
        'skipped' => (bool)$skipped,
        'message' => sanitize_text_field((string)$message),
        'context' => is_array($context) ? $context : [],
    ];
}

/**
 * Procesador común para listas de IDs almacenadas en el payload.
 */
function eventosapp_task_queue_process_ticket_list($task, $runtime, $callback) {
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    $ticket_ids = eventosapp_task_queue_ticket_ids($payload['ticket_ids'] ?? []);
    $cursor = max(0, absint($task['cursor_value'] ?? 0));
    $batch_size = max(1, absint($runtime['batch_size'] ?? 1));
    $batch = array_slice($ticket_ids, $cursor, $batch_size);

    if ( ! empty($batch) ) update_meta_cache('post', $batch);

    $processed = 0;
    $success = 0;
    $errors = 0;
    $skipped = 0;
    $logs = [];
    $started = isset($runtime['started_at']) ? (float)$runtime['started_at'] : microtime(true);

    foreach ( $batch as $ticket_id ) {
        if ( $processed > 0 && is_callable($runtime['should_yield'] ?? null) && call_user_func($runtime['should_yield'], $started, $processed) ) {
            $logs[] = [
                'level'   => 'warning',
                'message' => 'El lote cedió el turno preventivamente por tiempo, memoria o carga del servidor.',
            ];
            break;
        }

        $processed++;
        try {
            $result = call_user_func($callback, absint($ticket_id), $payload, $task);
            $result = is_array($result) ? $result : eventosapp_task_queue_item_result(false, 'El adaptador devolvió una respuesta inválida.');
        } catch (Throwable $e) {
            $result = eventosapp_task_queue_item_result(false, $e->getMessage());
        }

        $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
        $ticket_label = $ticket_code !== '' ? $ticket_code : ('#' . $ticket_id);
        $message = trim((string)($result['message'] ?? ''));

        if ( ! empty($result['skipped']) ) {
            $skipped++;
            $level = 'warning';
            $state = 'Omitido';
        } elseif ( ! empty($result['ok']) ) {
            $success++;
            $level = 'success';
            $state = 'Correcto';
        } else {
            $errors++;
            $level = 'error';
            $state = 'Error';
        }

        $logs[] = [
            'level'   => $level,
            'message' => 'Ticket ' . $ticket_label . ' · ' . $state . ($message !== '' ? ' · ' . $message : ''),
            'context' => is_array($result['context'] ?? null) ? $result['context'] : [],
        ];
    }

    $next = $cursor + $processed;
    return [
        'processed'    => $processed,
        'success'      => $success,
        'errors'       => $errors,
        'skipped'      => $skipped,
        'next_cursor'  => $next,
        'total_items'  => count($ticket_ids),
        'done'         => $next >= count($ticket_ids),
        'logs'         => $logs,
    ];
}

/**
 * Actualiza el segmento histórico para que otras pantallas sigan mostrando
 * el vínculo con la tarea de segundo plano.
 */
function eventosapp_task_queue_update_segment_task($storage, $segment_id, $task_id, $extra = []) {
    $segment_id = sanitize_key((string)$segment_id);
    if ( $segment_id === '' ) return;

    $segment = null;
    if ( $storage === 'email' ) {
        $segment = get_option('evapp_email_segment_' . $segment_id);
    } elseif ( $storage === 'whatsapp' && function_exists('eventosapp_whatsapp_masivo_segment_option_key') ) {
        $segment = get_option(eventosapp_whatsapp_masivo_segment_option_key($segment_id));
    } elseif ( $storage === 'flow' && function_exists('eventosapp_whatsapp_flows_bulk_segment_option_key') ) {
        $segment = get_option(eventosapp_whatsapp_flows_bulk_segment_option_key($segment_id));
    } elseif ( $storage === 'attendance' && function_exists('eventosapp_attendance_confirmation_get_segment') ) {
        $segment = eventosapp_attendance_confirmation_get_segment($segment_id);
    }

    if ( ! is_array($segment) ) return;
    $segment['queue_task_id'] = absint($task_id);
    $segment['queue_task_url'] = eventosapp_task_queue_task_url($task_id);
    $segment['queue_updated_at'] = current_time('mysql');
    foreach ( (array)$extra as $key => $value ) $segment[$key] = $value;

    if ( $storage === 'email' ) {
        update_option('evapp_email_segment_' . $segment_id, $segment, false);
    } elseif ( $storage === 'whatsapp' ) {
        update_option(eventosapp_whatsapp_masivo_segment_option_key($segment_id), $segment, false);
    } elseif ( $storage === 'flow' ) {
        update_option(eventosapp_whatsapp_flows_bulk_segment_option_key($segment_id), $segment, false);
    } elseif ( $storage === 'attendance' ) {
        eventosapp_attendance_confirmation_save_segment($segment_id, $segment, DAY_IN_SECONDS * 7);
    }
}

/**
 * ADAPTADORES DE ENVÍOS MASIVOS.
 */
function eventosapp_task_queue_process_email_bulk($task, $runtime) {
    return eventosapp_task_queue_process_ticket_list($task, $runtime, function($ticket_id, $payload) {
        $variant = function_exists('eventosapp_email_masivo_prepare_ticket_variant')
            ? eventosapp_email_masivo_prepare_ticket_variant($ticket_id, 'email_bulk_background')
            : [];

        if ( ! function_exists('eventosapp_send_ticket_email_now') ) {
            return eventosapp_task_queue_item_result(false, 'La función de envío de correo no está disponible.');
        }

        $result = eventosapp_send_ticket_email_now($ticket_id, [
            'source'  => 'bulk_background',
            'force'   => false,
            'variant_context' => is_array($variant) ? [
                'applied'        => ! empty($variant['applied']),
                'reason'         => (string)($variant['reason'] ?? ''),
                'variant_key'    => (string)($variant['variant_key'] ?? ''),
                'variant_name'   => (string)($variant['variant_name'] ?? ''),
                'email_template' => (string)($variant['email_template'] ?? ''),
            ] : [],
        ]);

        $ok = is_array($result) ? ! empty($result[0]) : false;
        $message = is_array($result) ? (string)($result[1] ?? '') : 'Respuesta inválida del módulo de correo.';
        return eventosapp_task_queue_item_result($ok, $message ?: ($ok ? 'Correo enviado.' : 'No se pudo enviar el correo.'));
    });
}

function eventosapp_task_queue_process_whatsapp_bulk($task, $runtime) {
    return eventosapp_task_queue_process_ticket_list($task, $runtime, function($ticket_id, $payload) {
        $segment = is_array($payload['segment'] ?? null) ? $payload['segment'] : [];
        if ( ! function_exists('eventosapp_whatsapp_masivo_resolve_template_id_for_ticket') || ! function_exists('eventosapp_whatsapp_masivo_send_ticket_with_template') ) {
            return eventosapp_task_queue_item_result(false, 'El módulo WhatsApp masivo no está disponible.');
        }

        $template_id = eventosapp_whatsapp_masivo_resolve_template_id_for_ticket($ticket_id, $segment);
        if ( $template_id === '' ) {
            return eventosapp_task_queue_item_result(false, 'No existe una plantilla válida para la modalidad del ticket.');
        }

        $segment_id = sanitize_key((string)($payload['segment_id'] ?? ''));
        $result = eventosapp_whatsapp_masivo_send_ticket_with_template($ticket_id, $template_id, [
            'context'    => 'whatsapp_bulk_background',
            'force'      => false,
            'skip_rules' => empty($segment['respect_rules']),
            'source_key' => 'whatsapp_bulk_queue:' . $segment_id . ':' . $ticket_id . ':' . $template_id,
            'segment_id' => $segment_id,
        ]);

        $ok = is_array($result) && ! empty($result['ok']);
        $is_skipped = $ok && (! empty($result['skipped_duplicate']) || ! empty($result['skipped_rules']) || ! empty($result['skipped']));
        return eventosapp_task_queue_item_result($ok, $result['message'] ?? ($ok ? 'Solicitud aceptada por Meta.' : 'Error de WhatsApp.'), $is_skipped);
    });
}

function eventosapp_task_queue_process_flow_bulk($task, $runtime) {
    return eventosapp_task_queue_process_ticket_list($task, $runtime, function($ticket_id, $payload) {
        $segment = is_array($payload['segment'] ?? null) ? $payload['segment'] : [];
        $segment_id = sanitize_key((string)($payload['segment_id'] ?? ''));
        $flow_post_id = absint($segment['flow_post_id'] ?? 0);
        $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true)) ?: absint($segment['event_id'] ?? 0);
        $template_id = sanitize_key((string)($segment['flow_template_id'] ?? ''));

        if ( ! function_exists('eventosapp_whatsapp_send_ticket_satisfaction_flow') ) {
            return eventosapp_task_queue_item_result(false, 'La función de envío de plantilla Flow no está disponible.');
        }
        if ( ! $flow_post_id || $template_id === '' ) {
            return eventosapp_task_queue_item_result(false, 'El segmento no contiene Flow y plantilla válidos.');
        }

        if ( ! empty($segment['respect_rules']) && function_exists('eventosapp_whatsapp_ticket_passes_rules') ) {
            $rules = eventosapp_whatsapp_ticket_passes_rules($ticket_id, $event_id);
            if ( empty($rules['allowed']) ) {
                return eventosapp_task_queue_item_result(true, $rules['reason'] ?? 'Omitido por reglas del evento.', true);
            }
        }

        $source_key = 'whatsapp_flow_template_bulk_' . $segment_id . '_' . $flow_post_id . '_' . $template_id;
        if ( get_post_meta($ticket_id, '_eventosapp_whatsapp_last_source_key', true) === $source_key ) {
            return eventosapp_task_queue_item_result(true, 'Ya fue enviado en este mismo segmento.', true);
        }

        $result = eventosapp_whatsapp_send_ticket_satisfaction_flow($ticket_id, [
            'template_id'           => $template_id,
            'flow_post_id'          => $flow_post_id,
            'event_id'              => $event_id,
            'send_mode'             => 'template_flow_background_campaign',
            'context'               => 'whatsapp_flow_bulk_background',
            'source_key'            => $source_key,
            'sender_phone_number_id'=> sanitize_text_field((string)($segment['sender_phone_number_id'] ?? '')),
            'header_image_url'      => esc_url_raw((string)($segment['flow_template_header_image'] ?? '')),
            'skip_rules'            => true,
            'force'                 => true,
        ]);

        $ok = is_array($result) && ! empty($result['ok']);
        return eventosapp_task_queue_item_result($ok, $result['message'] ?? ($ok ? 'Flow aceptado por Meta.' : 'No se pudo enviar el Flow.'));
    });
}

function eventosapp_task_queue_process_attendance($task, $runtime) {
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];

    // Las programaciones resuelven el segmento al comenzar, no al guardarse.
    // Así incluyen tickets creados después de programar y antes del envío.
    if ( ($payload['source'] ?? '') === 'scheduled' && empty($payload['prepared']) ) {
        $ticket_ids = function_exists('eventosapp_attendance_confirmation_get_filtered_tickets')
            ? eventosapp_attendance_confirmation_get_filtered_tickets($payload['filters'] ?? ['evento_id'=>absint($task['event_id'])])
            : [];
        $payload['ticket_ids'] = eventosapp_task_queue_ticket_ids($ticket_ids);
        $payload['prepared'] = 1;
        eventosapp_task_queue_update($task['id'], [
            'payload'       => $payload,
            'total_items'   => count($payload['ticket_ids']),
            'cursor_value'  => 0,
        ]);
        $task['payload'] = $payload;
        $task['total_items'] = count($payload['ticket_ids']);
        $task['cursor_value'] = 0;
    }

    return eventosapp_task_queue_process_ticket_list($task, $runtime, function($ticket_id, $payload) {
        if ( ! function_exists('eventosapp_attendance_confirmation_send_ticket') ) {
            return eventosapp_task_queue_item_result(false, 'El motor de confirmación de asistencia no está disponible.');
        }
        $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];
        $config['source'] = ($payload['source'] ?? 'bulk') === 'scheduled' ? 'scheduled_queue' : 'bulk_queue';
        $config['source_key'] = sanitize_key((string)($payload['source_ref'] ?? 'attendance_queue')) . ':' . $ticket_id;
        $result = eventosapp_attendance_confirmation_send_ticket($ticket_id, $config);
        $ok = is_array($result) && ! empty($result['ok']);
        $partial = $ok && ! empty($result['partial']);
        return eventosapp_task_queue_item_result($ok, $result['message'] ?? ($ok ? 'Confirmación enviada.' : 'No se pudo enviar la confirmación.'), false, ['partial'=>$partial]);
    });
}

/**
 * ADAPTADORES DE RECORDATORIOS PROGRAMADOS.
 */
function eventosapp_task_queue_prepare_email_reminder_payload($task) {
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    if ( ! empty($payload['prepared']) ) return $payload;

    $event_id = absint($task['event_id'] ?? ($payload['event_id'] ?? 0));
    $tickets = get_posts([
        'post_type'=>'eventosapp_ticket','post_status'=>'any','fields'=>'ids','nopaging'=>true,
        'meta_query'=>[['key'=>'_eventosapp_ticket_evento_id','value'=>$event_id,'compare'=>'=']],
        'orderby'=>'ID','order'=>'ASC','no_found_rows'=>true,
    ]);
    $filters = get_post_meta($event_id, '_eventosapp_reminder_filters', true);
    $filters = is_array($filters) ? $filters : [];
    $queue = [];
    $stats = ['already_sent'=>0,'no_email'=>0,'filtered_out'=>0];

    foreach ( (array)$tickets as $ticket_id ) {
        if ( get_post_meta($ticket_id, '_eventosapp_ticket_reminder_sent', true) ) { $stats['already_sent']++; continue; }
        $email = sanitize_email(get_post_meta($ticket_id, '_eventosapp_asistente_email', true));
        if ( ! $email || ! is_email($email) ) { $stats['no_email']++; continue; }
        if ( ! empty($filters) && function_exists('eventosapp_ticket_passes_reminder_filters') && ! eventosapp_ticket_passes_reminder_filters($ticket_id, $filters) ) {
            $stats['filtered_out']++; continue;
        }
        $queue[] = absint($ticket_id);
    }

    $payload['prepared'] = 1;
    $payload['ticket_ids'] = $queue;
    $payload['dispatch_stats'] = $stats;
    eventosapp_task_queue_update($task['id'], ['payload'=>$payload,'total_items'=>count($queue),'cursor_value'=>0]);

    update_post_meta($event_id, '_eventosapp_reminder_queue_size', count($queue));
    update_post_meta($event_id, '_eventosapp_reminder_queue_begins', current_time('mysql'));
    update_post_meta($event_id, '_eventosapp_reminder_dispatch_stats', [
        'dispatched_at'=>current_time('mysql'),'total_event_tix'=>count((array)$tickets),
        'already_sent'=>$stats['already_sent'],'no_email'=>$stats['no_email'],'filtered_out'=>$stats['filtered_out'],
        'filter_count'=>count($filters),'queue_size'=>count($queue),'sent_ok'=>0,'sent_fail'=>0,
        'completed'=>empty($queue),'completed_at'=>empty($queue)?current_time('mysql'):'',
        'queue_task_id'=>absint($task['id']),
    ]);
    return $payload;
}

function eventosapp_task_queue_process_email_reminder($task, $runtime) {
    $payload = eventosapp_task_queue_prepare_email_reminder_payload($task);
    $task['payload'] = $payload;
    $task['total_items'] = count(eventosapp_task_queue_ticket_ids($payload['ticket_ids'] ?? []));
    $event_title = get_the_title(absint($task['event_id']));

    return eventosapp_task_queue_process_ticket_list($task, $runtime, function($ticket_id) use ($event_title) {
        if ( get_post_meta($ticket_id, '_eventosapp_ticket_reminder_sent', true) ) {
            return eventosapp_task_queue_item_result(true, 'Recordatorio ya enviado.', true);
        }
        if ( ! function_exists('eventosapp_send_ticket_email_now') ) {
            return eventosapp_task_queue_item_result(false, 'La función de correo no está disponible.');
        }
        $result = eventosapp_send_ticket_email_now($ticket_id, [
            'subject'=>'🔔 RECORDATORIO: 🎟️ Hoy es el evento ' . $event_title,
            'source'=>'reminder_queue','force'=>true,
        ]);
        $ok = is_array($result) && ! empty($result[0]);
        if ( $ok ) update_post_meta($ticket_id, '_eventosapp_ticket_reminder_sent', current_time('mysql'));
        return eventosapp_task_queue_item_result($ok, is_array($result) ? ($result[1] ?? '') : 'Respuesta inválida del correo.');
    });
}

function eventosapp_task_queue_prepare_whatsapp_reminder_payload($task) {
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    if ( ! empty($payload['prepared']) ) return $payload;
    $event_id = absint($task['event_id']);
    $ids = get_posts([
        'post_type'=>'eventosapp_ticket','post_status'=>['publish','pending','draft','private'],
        'posts_per_page'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','no_found_rows'=>true,
        'meta_query'=>[['key'=>'_eventosapp_ticket_evento_id','value'=>$event_id,'compare'=>'=']],
    ]);
    $payload['prepared'] = 1;
    $payload['ticket_ids'] = eventosapp_task_queue_ticket_ids($ids);
    eventosapp_task_queue_update($task['id'], ['payload'=>$payload,'total_items'=>count($payload['ticket_ids']),'cursor_value'=>0]);
    return $payload;
}

function eventosapp_task_queue_process_whatsapp_reminder($task, $runtime) {
    $payload = eventosapp_task_queue_prepare_whatsapp_reminder_payload($task);
    $task['payload'] = $payload;
    $task['total_items'] = count(eventosapp_task_queue_ticket_ids($payload['ticket_ids'] ?? []));

    return eventosapp_task_queue_process_ticket_list($task, $runtime, function($ticket_id, $payload, $task) {
        $event_id = absint($task['event_id']);
        $reminder_id = sanitize_key((string)($payload['reminder_id'] ?? ''));
        $item = function_exists('eventosapp_ticket_reminders_find_item')
            ? eventosapp_ticket_reminders_find_item($event_id, $reminder_id)
            : (is_array($payload['item'] ?? null) ? $payload['item'] : []);

        if ( empty($item) || ! function_exists('eventosapp_ticket_reminders_send_whatsapp') ) {
            return eventosapp_task_queue_item_result(false, 'El recordatorio o su función de envío ya no están disponibles.');
        }

        if ( function_exists('eventosapp_ticket_reminders_ticket_passes_rules') ) {
            $passes = eventosapp_ticket_reminders_ticket_passes_rules($ticket_id, $event_id);
            if ( empty($passes['allowed']) ) {
                if ( function_exists('eventosapp_ticket_reminders_add_ticket_history') ) {
                    eventosapp_ticket_reminders_add_ticket_history($ticket_id, $event_id, $item, 'whatsapp', 'skipped', $passes['reason'] ?? 'No cumple filtros.');
                }
                return eventosapp_task_queue_item_result(true, $passes['reason'] ?? 'Omitido por filtros.', true);
            }
        }

        if ( function_exists('eventosapp_ticket_reminders_ticket_already_sent') && eventosapp_ticket_reminders_ticket_already_sent($ticket_id, $reminder_id, 'whatsapp') ) {
            return eventosapp_task_queue_item_result(true, 'Ya enviado para este recordatorio.', true);
        }

        $result = eventosapp_ticket_reminders_send_whatsapp($ticket_id, $event_id, $item);
        $ok = is_array($result) && ! empty($result['ok']);
        $skipped = $ok && ! empty($result['skipped']);
        if ( function_exists('eventosapp_ticket_reminders_add_ticket_history') ) {
            eventosapp_ticket_reminders_add_ticket_history($ticket_id, $event_id, $item, 'whatsapp', $skipped?'skipped':($ok?'sent':'error'), $result['message'] ?? '');
        }
        return eventosapp_task_queue_item_result($ok, $result['message'] ?? '', $skipped);
    });
}

/**
 * Adaptador de importación masiva de tickets desde Herramientas.
 *
 * El procesamiento real permanece en eventosapp-herramientas.php para que el modo
 * ventana y Cola y Tareas compartan exactamente la misma validación, deduplicación,
 * creación y generación condicional de anexos.
 */
function eventosapp_task_queue_process_ticket_import($task, $runtime) {
    if ( ! function_exists('evapp_import_task_queue_process_batch') ) {
        return [
            'processed'     => 0,
            'success'       => 0,
            'errors'        => 1,
            'skipped'       => 0,
            'next_cursor'   => absint($task['cursor_value'] ?? 0),
            'total_items'   => absint($task['total_items'] ?? 0),
            'done'          => false,
            'fatal'         => true,
            'error_message' => 'El procesador de importación de Herramientas no está disponible.',
            'logs'          => [[
                'level'   => 'error',
                'message' => 'No se cargó evapp_import_task_queue_process_batch(). Revisa el orden de includes del plugin.',
            ]],
        ];
    }

    return evapp_import_task_queue_process_batch($task, $runtime);
}

/**
 * Registro de adaptadores después de que todos los módulos del plugin cargaron.
 */
function eventosapp_task_queue_register_integrations() {
    if ( ! function_exists('eventosapp_task_queue_register_adapter') ) return;

    eventosapp_task_queue_register_adapter('ticket_import', [
        'label'=>'Importación masiva de tickets','group'=>'massive','channel'=>'tickets',
        'batch_size'=>20,'min_batch_size'=>1,'max_batch_size'=>40,
        'process_batch'=>'eventosapp_task_queue_process_ticket_import',
    ]);
    eventosapp_task_queue_register_adapter('email_bulk', [
        'label'=>'Envío masivo de tickets por correo','group'=>'massive','channel'=>'email',
        'batch_size'=>12,'min_batch_size'=>2,'max_batch_size'=>30,
        'process_batch'=>'eventosapp_task_queue_process_email_bulk',
    ]);
    eventosapp_task_queue_register_adapter('whatsapp_bulk', [
        'label'=>'Envío masivo de tickets por WhatsApp','group'=>'massive','channel'=>'whatsapp',
        'batch_size'=>6,'min_batch_size'=>1,'max_batch_size'=>12,
        'process_batch'=>'eventosapp_task_queue_process_whatsapp_bulk',
    ]);
    eventosapp_task_queue_register_adapter('whatsapp_flow_bulk', [
        'label'=>'Envío masivo de WhatsApp Flow','group'=>'massive','channel'=>'flow',
        'batch_size'=>5,'min_batch_size'=>1,'max_batch_size'=>10,
        'process_batch'=>'eventosapp_task_queue_process_flow_bulk',
    ]);
    eventosapp_task_queue_register_adapter('attendance_bulk', [
        'label'=>'Confirmación masiva de asistencia','group'=>'massive','channel'=>'mixed',
        'batch_size'=>8,'min_batch_size'=>1,'max_batch_size'=>20,
        'process_batch'=>'eventosapp_task_queue_process_attendance',
    ]);
    eventosapp_task_queue_register_adapter('attendance_scheduled', [
        'label'=>'Confirmación de asistencia programada','group'=>'scheduled','channel'=>'mixed',
        'batch_size'=>8,'min_batch_size'=>1,'max_batch_size'=>20,
        'process_batch'=>'eventosapp_task_queue_process_attendance',
    ]);
    eventosapp_task_queue_register_adapter('email_reminder_scheduled', [
        'label'=>'Recordatorio programado por correo','group'=>'scheduled','channel'=>'email',
        'batch_size'=>15,'min_batch_size'=>2,'max_batch_size'=>35,
        'process_batch'=>'eventosapp_task_queue_process_email_reminder',
    ]);
    eventosapp_task_queue_register_adapter('whatsapp_reminder_scheduled', [
        'label'=>'Recordatorio programado por WhatsApp','group'=>'scheduled','channel'=>'whatsapp',
        'batch_size'=>6,'min_batch_size'=>1,'max_batch_size'=>12,
        'process_batch'=>'eventosapp_task_queue_process_whatsapp_reminder',
    ]);
}
add_action('init', 'eventosapp_task_queue_register_integrations', 35);

/**
 * Crea una tarea manual y responde con la forma esperada por la UI histórica.
 */
function eventosapp_task_queue_create_manual_task($type, $segment_id, $segment, $storage) {
    $ticket_ids = eventosapp_task_queue_ticket_ids($segment['ticket_ids'] ?? []);
    $event_id = absint($segment['event_id'] ?? ($segment['filters']['evento_id'] ?? 0));
    if ( ! $event_id && ! empty($ticket_ids) ) {
        $event_id = absint(get_post_meta(reset($ticket_ids), '_eventosapp_ticket_evento_id', true));
    }

    $existing = absint($segment['queue_task_id'] ?? 0);
    if ( $existing ) {
        $task = eventosapp_task_queue_get($existing);
        if ( $task && ! in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) ) return $existing;
    }

    $labels = [
        'email_bulk'=>'Correo masivo','whatsapp_bulk'=>'WhatsApp masivo',
        'whatsapp_flow_bulk'=>'WhatsApp Flow masivo','attendance_bulk'=>'Confirmación masiva de asistencia',
    ];
    $channels = [
        'email_bulk'=>'email','whatsapp_bulk'=>'whatsapp','whatsapp_flow_bulk'=>'flow',
        'attendance_bulk'=>implode('+', function_exists('eventosapp_attendance_confirmation_sanitize_channels') ? eventosapp_attendance_confirmation_sanitize_channels($segment['config']['channels'] ?? []) : ['mixed']),
    ];
    $payload = [
        'segment_id' => sanitize_key((string)$segment_id),
        'ticket_ids' => $ticket_ids,
        'segment'    => $segment,
        'config'     => is_array($segment['config'] ?? null) ? $segment['config'] : [],
        'source'     => 'bulk',
        'source_ref' => $type . ':' . sanitize_key((string)$segment_id),
    ];

    $task_id = eventosapp_task_queue_create([
        'task_type'          => $type,
        'task_group'         => 'massive',
        'channel'            => $channels[$type] ?? '',
        'title'              => ($labels[$type] ?? $type) . ($event_id ? ' · ' . get_the_title($event_id) : ''),
        'event_id'           => $event_id,
        'total_items'        => count($ticket_ids),
        'payload'            => $payload,
        'notification_email' => eventosapp_task_queue_current_notification_email(),
        'created_by'         => get_current_user_id(),
        'status'             => 'queued',
    ]);

    if ( is_wp_error($task_id) ) return $task_id;
    eventosapp_task_queue_update_segment_task($storage, $segment_id, $task_id, ['queue_status'=>'queued']);
    return $task_id;
}

function eventosapp_task_queue_intercept_email_bulk() {
    if ( ! current_user_can('manage_options') ) return;
    check_ajax_referer('eventosapp_email_masivo_process');
    $segment_id = sanitize_key((string)wp_unslash($_POST['segment_id'] ?? ''));
    $segment = get_option('evapp_email_segment_' . $segment_id);
    if ( ! is_array($segment) ) wp_send_json_error(['message'=>'Segmento no encontrado.'], 404);
    $task_id = eventosapp_task_queue_create_manual_task('email_bulk', $segment_id, $segment, 'email');
    if ( is_wp_error($task_id) ) wp_send_json_error(['message'=>$task_id->get_error_message()], 500);
    $total = count(eventosapp_task_queue_ticket_ids($segment['ticket_ids'] ?? []));
    wp_send_json_success([
        'processed'=>$total,'sent'=>0,'errors'=>0,'next_offset'=>$total,'retry_after_ms'=>0,
        'background'=>true,'task_id'=>$task_id,'task_url'=>eventosapp_task_queue_task_url($task_id),
        'logs'=>[['message'=>'La campaña fue enviada a segundo plano. Continúa desde Cola y Tareas.','type'=>'success']],
    ]);
}
add_action('wp_ajax_eventosapp_email_masivo_process_batch', 'eventosapp_task_queue_intercept_email_bulk', 0);

function eventosapp_task_queue_intercept_whatsapp_bulk() {
    if ( ! current_user_can('manage_options') ) return;
    check_ajax_referer('eventosapp_whatsapp_masivo_process');
    $segment_id = sanitize_key((string)wp_unslash($_POST['segment_id'] ?? ''));
    $segment = function_exists('eventosapp_whatsapp_masivo_segment_option_key') ? get_option(eventosapp_whatsapp_masivo_segment_option_key($segment_id)) : null;
    if ( ! is_array($segment) ) wp_send_json_error(['message'=>'Segmento no encontrado.'], 404);
    $task_id = eventosapp_task_queue_create_manual_task('whatsapp_bulk', $segment_id, $segment, 'whatsapp');
    if ( is_wp_error($task_id) ) wp_send_json_error(['message'=>$task_id->get_error_message()], 500);
    $total = count(eventosapp_task_queue_ticket_ids($segment['ticket_ids'] ?? []));
    wp_send_json_success([
        'processed'=>$total,'sent'=>0,'skipped'=>0,'errors'=>0,'next_offset'=>$total,'retry_after'=>0,
        'background'=>true,'task_id'=>$task_id,'task_url'=>eventosapp_task_queue_task_url($task_id),
        'logs'=>[['message'=>'La campaña fue enviada a segundo plano. Continúa desde Cola y Tareas.','type'=>'success']],
    ]);
}
add_action('wp_ajax_eventosapp_whatsapp_masivo_process_batch', 'eventosapp_task_queue_intercept_whatsapp_bulk', 0);

function eventosapp_task_queue_intercept_flow_bulk() {
    if ( ! current_user_can('manage_options') ) return;
    check_ajax_referer('eventosapp_whatsapp_flow_process');
    $segment_id = sanitize_key((string)wp_unslash($_POST['segment_id'] ?? ''));
    $segment = function_exists('eventosapp_whatsapp_flows_bulk_segment_option_key') ? get_option(eventosapp_whatsapp_flows_bulk_segment_option_key($segment_id)) : null;
    if ( ! is_array($segment) ) wp_send_json_error('Segmento no encontrado');
    $task_id = eventosapp_task_queue_create_manual_task('whatsapp_flow_bulk', $segment_id, $segment, 'flow');
    if ( is_wp_error($task_id) ) wp_send_json_error($task_id->get_error_message());
    $total = count(eventosapp_task_queue_ticket_ids($segment['ticket_ids'] ?? []));
    wp_send_json_success([
        'processed'=>$total,'sent'=>0,'skipped'=>0,'errors'=>0,'next_offset'=>$total,
        'background'=>true,'task_id'=>$task_id,'task_url'=>eventosapp_task_queue_task_url($task_id),
        'logs'=>[['message'=>'La campaña Flow fue enviada a segundo plano. Continúa desde Cola y Tareas.','type'=>'success']],
    ]);
}
add_action('wp_ajax_eventosapp_whatsapp_flow_process_batch', 'eventosapp_task_queue_intercept_flow_bulk', 0);

function eventosapp_task_queue_intercept_attendance_bulk() {
    if ( function_exists('eventosapp_attendance_confirmation_admin_can_manage') && ! eventosapp_attendance_confirmation_admin_can_manage() ) return;
    check_ajax_referer('eventosapp_attendance_confirmation_process_batch', 'nonce');
    $segment_id = sanitize_key((string)wp_unslash($_POST['segment_id'] ?? ''));
    $segment = function_exists('eventosapp_attendance_confirmation_get_segment') ? eventosapp_attendance_confirmation_get_segment($segment_id) : null;
    if ( ! is_array($segment) ) wp_send_json_error(['message'=>'La segmentación expiró.'], 404);
    $task_id = eventosapp_task_queue_create_manual_task('attendance_bulk', $segment_id, $segment, 'attendance');
    if ( is_wp_error($task_id) ) wp_send_json_error(['message'=>$task_id->get_error_message()], 500);
    $total = count(eventosapp_task_queue_ticket_ids($segment['ticket_ids'] ?? []));
    wp_send_json_success([
        'processed'=>$total,'next_offset'=>$total,'done'=>true,'success'=>0,'partial'=>0,'errors'=>0,
        'background'=>true,'task_id'=>$task_id,'task_url'=>eventosapp_task_queue_task_url($task_id),
        'log'=>['La campaña fue enviada a segundo plano. Continúa desde Cola y Tareas.'],
    ]);
}
add_action('wp_ajax_eventosapp_attendance_confirmation_process_batch', 'eventosapp_task_queue_intercept_attendance_bulk', 0);

/**
 * Sincronización de programaciones con la cola central.
 */
function eventosapp_task_queue_sync_email_reminder($event_id) {
    if ( ! function_exists('eventosapp_event_reminder_send_timestamp') ) return;
    $event_id = absint($event_id);
    $old_id = absint(get_post_meta($event_id, '_eventosapp_reminder_queue_task_id', true));
    $enabled = get_post_meta($event_id, '_eventosapp_reminder_enabled', true) === '1';
    $timestamp = $enabled ? absint(eventosapp_event_reminder_send_timestamp($event_id)) : 0;

    if ( ! $enabled || ! $timestamp ) {
        if ( $old_id ) eventosapp_task_queue_cancel($old_id, 'La programación de correo fue desactivada o quedó sin fecha válida.');
        delete_post_meta($event_id, '_eventosapp_reminder_queue_task_id');
        return;
    }

    $signature = md5($event_id . '|' . $timestamp . '|' . wp_json_encode(get_post_meta($event_id, '_eventosapp_reminder_filters', true)));
    $old_signature = get_post_meta($event_id, '_eventosapp_reminder_queue_signature', true);

    $old_task = $old_id ? eventosapp_task_queue_get($old_id) : null;
    if ( $old_task && hash_equals((string)$old_signature, $signature) ) {
        eventosapp_task_queue_store_schedule_context($old_id, $event_id, $timestamp);
        eventosapp_task_queue_reconcile_scheduled_task($old_id);
        return;
    }
    if ( $old_task ) eventosapp_task_queue_cancel($old_id, 'La programación fue reemplazada por una nueva configuración.');
    if ( $old_id && ! $old_task ) delete_post_meta($event_id, '_eventosapp_reminder_queue_task_id');

    // Una fecha de un día anterior no se transforma en envío inmediato.
    if ( eventosapp_task_queue_timestamp_is_previous_event_date($timestamp, $event_id) ) {
        update_post_meta($event_id, '_eventosapp_reminder_queue_signature', $signature);
        delete_post_meta($event_id, '_eventosapp_reminder_queue_task_id');
        return;
    }

    $task_id = eventosapp_task_queue_create([
        'task_type'=>'email_reminder_scheduled','task_group'=>'scheduled','channel'=>'email',
        'title'=>'Recordatorio por correo · ' . get_the_title($event_id),
        'event_id'=>$event_id,'scheduled_at'=>$timestamp,'next_run_at'=>$timestamp,'status'=>'scheduled',
        'total_items'=>0,'payload'=>array_merge(['event_id'=>$event_id,'prepared'=>0,'signature'=>$signature], eventosapp_task_queue_schedule_context($event_id, $timestamp)),
        'notification_email'=>eventosapp_task_queue_current_notification_email(),'created_by'=>get_current_user_id(),
    ]);
    if ( ! is_wp_error($task_id) ) {
        update_post_meta($event_id, '_eventosapp_reminder_queue_task_id', $task_id);
        update_post_meta($event_id, '_eventosapp_reminder_queue_signature', $signature);
    }
}

function eventosapp_task_queue_sync_ticket_reminders($event_id) {
    if ( ! defined('EVENTOSAPP_TICKET_REMINDERS_META_ITEMS') || ! function_exists('eventosapp_ticket_reminders_calculate_run_timestamp') ) return;
    $event_id = absint($event_id);
    $enabled = get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_ENABLED, true) === '1';
    $items = $enabled && function_exists('eventosapp_ticket_reminders_normalize_items')
        ? eventosapp_ticket_reminders_normalize_items(get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_ITEMS, true))
        : [];
    $map = get_post_meta($event_id, '_eventosapp_ticket_reminders_queue_task_ids', true);
    $map = is_array($map) ? $map : [];
    $next_map = [];

    foreach ( $items as $item ) {
        $reminder_id = sanitize_key((string)($item['id'] ?? ''));
        if ( $reminder_id === '' || ($item['enabled'] ?? '0') !== '1' ) continue;
        $run_at = absint(eventosapp_ticket_reminders_calculate_run_timestamp($event_id, $item));
        if ( ! $run_at ) continue;
        $signature = function_exists('eventosapp_ticket_reminders_item_signature')
            ? eventosapp_ticket_reminders_item_signature($event_id, $item)
            : md5(wp_json_encode($item) . '|' . $run_at);
        $old = is_array($map[$reminder_id] ?? null) ? $map[$reminder_id] : [];

        $old_task_id = absint($old['task_id'] ?? 0);
        $old_task = $old_task_id ? eventosapp_task_queue_get($old_task_id) : null;
        if ( $old_task && hash_equals((string)($old['signature'] ?? ''), (string)$signature) ) {
            eventosapp_task_queue_store_schedule_context($old_task_id, $event_id, $run_at);
            eventosapp_task_queue_reconcile_scheduled_task($old_task_id);
            $next_map[$reminder_id] = $old;
            continue;
        }
        if ( $old_task ) eventosapp_task_queue_cancel($old_task_id, 'El recordatorio fue reprogramado.');

        // Las programaciones de días anteriores se conservan en el historial del
        // módulo, pero no se recrean como una tarea inmediata de la cola.
        if ( eventosapp_task_queue_timestamp_is_previous_event_date($run_at, $event_id) ) {
            continue;
        }

        $schedule_at = $run_at;
        $task_id = eventosapp_task_queue_create([
            'task_type'=>'whatsapp_reminder_scheduled','task_group'=>'scheduled','channel'=>'whatsapp',
            'title'=>'Recordatorio WhatsApp · ' . (($item['name'] ?? '') ?: get_the_title($event_id)),
            'event_id'=>$event_id,'scheduled_at'=>$schedule_at,'next_run_at'=>$schedule_at,'status'=>'scheduled',
            'payload'=>array_merge(['event_id'=>$event_id,'reminder_id'=>$reminder_id,'item'=>$item,'prepared'=>0,'signature'=>$signature], eventosapp_task_queue_schedule_context($event_id, $run_at)),
            'total_items'=>0,'notification_email'=>eventosapp_task_queue_current_notification_email(),'created_by'=>get_current_user_id(),
        ]);
        if ( ! is_wp_error($task_id) ) {
            $next_map[$reminder_id] = ['task_id'=>$task_id,'signature'=>$signature,'scheduled_at'=>$schedule_at];
        }
    }

    foreach ( $map as $reminder_id => $old ) {
        if ( ! isset($next_map[$reminder_id]) && ! empty($old['task_id']) ) {
            $task = eventosapp_task_queue_get(absint($old['task_id']));
            if ( $task && ! in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) ) {
                eventosapp_task_queue_cancel(absint($old['task_id']), 'El recordatorio fue eliminado, desactivado o quedó históricamente vencido.');
            }
        }
    }
    update_post_meta($event_id, '_eventosapp_ticket_reminders_queue_task_ids', $next_map);
}

function eventosapp_task_queue_sync_attendance_schedule($event_id, $config = null) {
    static $syncing = [];
    $event_id = absint($event_id);
    if ( ! $event_id || ! empty($syncing[$event_id]) ) return;
    $syncing[$event_id] = true;

    try {
        $config = is_array($config) ? $config : get_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule', true);
        $old_id = absint($config['queue_task_id'] ?? get_post_meta($event_id, '_eventosapp_attendance_confirmation_queue_task_id', true));
        if ( ! is_array($config) || empty($config['enabled']) || empty($config['timestamp']) ) {
            if ( $old_id ) eventosapp_task_queue_cancel($old_id, 'La programación de confirmación fue desactivada.');
            delete_post_meta($event_id, '_eventosapp_attendance_confirmation_queue_task_id');
            return;
        }

        $timestamp = absint($config['timestamp']);
        $signature = md5((string)($config['schedule_id'] ?? '') . '|' . $timestamp . '|' . wp_json_encode($config['filters'] ?? []));
        $old_signature = get_post_meta($event_id, '_eventosapp_attendance_confirmation_queue_signature', true);
        $old_task = $old_id ? eventosapp_task_queue_get($old_id) : null;
        if ( $old_task && hash_equals((string)$old_signature, $signature) ) {
            eventosapp_task_queue_store_schedule_context($old_id, $event_id, $timestamp);
            eventosapp_task_queue_reconcile_scheduled_task($old_id);
            return;
        }
        if ( $old_task ) eventosapp_task_queue_cancel($old_id, 'La programación de confirmación fue reemplazada.');
        if ( $old_id && ! $old_task ) delete_post_meta($event_id, '_eventosapp_attendance_confirmation_queue_task_id');

        if ( eventosapp_task_queue_timestamp_is_previous_event_date($timestamp, $event_id) ) {
            $config['enabled'] = 0;
            $config['queue_status'] = 'expired';
            $config['expired_at'] = current_time('mysql');
            $config['expired_reason'] = 'La fecha programada pertenece a un día anterior en la zona horaria del evento y no se ejecutará tardíamente.';
            unset($config['queue_task_id'], $config['queue_task_url']);
            update_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule', $config);
            update_post_meta($event_id, '_eventosapp_attendance_confirmation_queue_signature', $signature);
            delete_post_meta($event_id, '_eventosapp_attendance_confirmation_queue_task_id');
            return;
        }

        $channels = function_exists('eventosapp_attendance_confirmation_sanitize_channels')
            ? eventosapp_attendance_confirmation_sanitize_channels($config['channels'] ?? [])
            : ['email'];
        $send_config = [
            'channels'=>$channels,'email_template'=>$config['email_template'] ?? 'attendance-confirmation.html',
            'email_subject'=>$config['email_subject'] ?? 'Confirma tu asistencia a {{evento_nombre}}',
            'email_message'=>$config['email_message'] ?? '',
            'whatsapp_template_id'=>$config['whatsapp_template_id'] ?? '',
        ];
        $schedule_at = $timestamp;
        $task_id = eventosapp_task_queue_create([
            'task_type'=>'attendance_scheduled','task_group'=>'scheduled','channel'=>implode('+', $channels),
            'title'=>'Confirmación programada · ' . get_the_title($event_id),
            'event_id'=>$event_id,'scheduled_at'=>$schedule_at,'next_run_at'=>$schedule_at,'status'=>'scheduled',
            'total_items'=>0,
            'payload'=>[
                'ticket_ids'=>[],'prepared'=>0,'filters'=>$config['filters'] ?? ['evento_id'=>$event_id],
                'config'=>$send_config,'source'=>'scheduled','source_ref'=>$signature,
                'schedule_id'=>$config['schedule_id'] ?? '',
            ] + eventosapp_task_queue_schedule_context($event_id, $timestamp),
            'notification_email'=>eventosapp_task_queue_current_notification_email(),'created_by'=>absint($config['created_by'] ?? get_current_user_id()),
        ]);
        if ( ! is_wp_error($task_id) ) {
            update_post_meta($event_id, '_eventosapp_attendance_confirmation_queue_task_id', $task_id);
            update_post_meta($event_id, '_eventosapp_attendance_confirmation_queue_signature', $signature);
            $config['queue_task_id'] = $task_id;
            $config['queue_task_url'] = eventosapp_task_queue_task_url($task_id);
            $config['queue_status'] = 'scheduled';
            unset($config['expired_at'], $config['expired_reason']);
            update_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule', $config);
        }
    } finally {
        unset($syncing[$event_id]);
    }
}

add_action('save_post_eventosapp_event', function($post_id) {
    if ( wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) ) return;
    eventosapp_task_queue_sync_email_reminder($post_id);
    eventosapp_task_queue_sync_ticket_reminders($post_id);
    eventosapp_task_queue_sync_attendance_schedule($post_id);
}, 280, 1);

add_action('updated_post_meta', function($meta_id, $post_id, $meta_key, $value) {
    if ( $meta_key === '_eventosapp_attendance_confirmation_schedule' && get_post_type($post_id) === 'eventosapp_event' ) {
        eventosapp_task_queue_sync_attendance_schedule($post_id, $value);
    }
}, 30, 4);
add_action('added_post_meta', function($meta_id, $post_id, $meta_key, $value) {
    if ( $meta_key === '_eventosapp_attendance_confirmation_schedule' && get_post_type($post_id) === 'eventosapp_event' ) {
        eventosapp_task_queue_sync_attendance_schedule($post_id, $value);
    }
}, 30, 4);

/**
 * Los hooks cron históricos quedan como disparadores de respaldo. En vez de
 * enviar por fuera de la cola, activan o crean la tarea central equivalente.
 */
add_action('init', function() {
    remove_action('eventosapp_dispatch_event_reminder', 'eventosapp_dispatch_event_reminder_cb', 10);
    remove_action('eventosapp_process_event_reminder_queue', 'eventosapp_process_event_reminder_queue_cb', 10);
    add_action('eventosapp_dispatch_event_reminder', function($event_id) {
        eventosapp_task_queue_sync_email_reminder($event_id);
        $task_id = absint(get_post_meta($event_id, '_eventosapp_reminder_queue_task_id', true));
        if ( $task_id ) eventosapp_task_queue_activate_due_task($task_id);
    }, 10, 1);

    if ( defined('EVENTOSAPP_TICKET_REMINDERS_CRON_HOOK') ) {
        remove_action(EVENTOSAPP_TICKET_REMINDERS_CRON_HOOK, 'eventosapp_ticket_reminders_run', 10);
        add_action(EVENTOSAPP_TICKET_REMINDERS_CRON_HOOK, function($event_id, $reminder_id) {
            eventosapp_task_queue_sync_ticket_reminders($event_id);
            $map = get_post_meta($event_id, '_eventosapp_ticket_reminders_queue_task_ids', true);
            $task_id = absint($map[sanitize_key($reminder_id)]['task_id'] ?? 0);
            if ( $task_id ) eventosapp_task_queue_activate_due_task($task_id);
        }, 10, 2);
    }

    if ( defined('EVENTOSAPP_ATTENDANCE_CONFIRMATION_SCHEDULE_HOOK') ) {
        remove_all_actions(EVENTOSAPP_ATTENDANCE_CONFIRMATION_SCHEDULE_HOOK);
        add_action(EVENTOSAPP_ATTENDANCE_CONFIRMATION_SCHEDULE_HOOK, function($event_id, $schedule_id) {
            $config = get_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule', true);
            if ( ! is_array($config) || sanitize_key((string)($config['schedule_id'] ?? '')) !== sanitize_key((string)$schedule_id) ) return;
            eventosapp_task_queue_sync_attendance_schedule($event_id, $config);
            $task_id = absint(get_post_meta($event_id, '_eventosapp_attendance_confirmation_queue_task_id', true));
            if ( $task_id ) eventosapp_task_queue_activate_due_task($task_id);
        }, 10, 2);
    }
}, 60);

/**
 * Actualiza trazabilidad histórica al terminar tareas programadas.
 */
add_action('eventosapp_task_queue_completed', function($task_id, $task) {
    if ( ! is_array($task) ) return;
    $event_id = absint($task['event_id'] ?? 0);
    if ( $task['task_type'] === 'email_reminder_scheduled' && $event_id ) {
        $stats = get_post_meta($event_id, '_eventosapp_reminder_dispatch_stats', true);
        $stats = is_array($stats) ? $stats : [];
        $stats['sent_ok'] = absint($task['success_items']);
        $stats['sent_fail'] = absint($task['error_items']);
        $stats['completed'] = true;
        $stats['completed_at'] = current_time('mysql');
        $stats['queue_task_id'] = absint($task_id);
        update_post_meta($event_id, '_eventosapp_reminder_dispatch_stats', $stats);
        update_post_meta($event_id, '_eventosapp_reminder_done', current_time('mysql'));
    }
    if ( $task['task_type'] === 'whatsapp_reminder_scheduled' && $event_id && function_exists('eventosapp_ticket_reminders_mark_executed') ) {
        $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
        $reminder_id = sanitize_key((string)($payload['reminder_id'] ?? ''));
        $summary = [
            'date'=>current_time('mysql'),'event_id'=>$event_id,'reminder_id'=>$reminder_id,
            'reminder_name'=>(string)($payload['item']['name'] ?? ''),'total_tickets'=>absint($task['total_items']),
            'sent_total'=>absint($task['success_items']),'skipped_total'=>absint($task['skipped_items']),
            'error_total'=>absint($task['error_items']),'whatsapp_sent'=>absint($task['success_items']),
            'queue_task_id'=>absint($task_id),
        ];
        update_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_LAST_RUN, $summary);
        eventosapp_ticket_reminders_mark_executed($event_id, $reminder_id, $summary);
        if ( function_exists('eventosapp_ticket_reminders_log') ) {
            eventosapp_ticket_reminders_log($event_id, 'Recordatorio WhatsApp finalizado por la cola central.', $summary, $summary['error_total'] ? 'warning' : 'success');
        }
    }
    if ( $task['task_type'] === 'attendance_scheduled' && $event_id && function_exists('eventosapp_attendance_confirmation_event_log') ) {
        eventosapp_attendance_confirmation_event_log($event_id, [
            'type'=>'queue_job_finished',
            'message'=>'Trabajo programado finalizado por la cola central. Procesados: ' . absint($task['processed_items']) . ', correctos: ' . absint($task['success_items']) . ', errores: ' . absint($task['error_items']) . '.',
            'meta'=>['queue_task_id'=>absint($task_id),'task_uuid'=>$task['uuid'] ?? ''],
        ]);
        $config = get_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule', true);
        if ( is_array($config) ) {
            $config['enabled'] = 0;
            $config['last_queue_task_id'] = absint($task_id);
            $config['last_finished_at'] = current_time('mysql');
            update_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule', $config);
        }
    }
}, 10, 2);

/**
 * Migración gradual de programaciones y colas heredadas existentes al activar
 * esta versión. Procesa pocos eventos por petición para no añadir picos de carga.
 */
function eventosapp_task_queue_migrate_existing_schedules() {
    global $wpdb;
    if ( ! function_exists('eventosapp_task_queue_create') ) return;

    $version = '2026.07.27.3';
    $done_version = get_option('eventosapp_task_queue_schedule_migration_version', '');
    $cursor = absint(get_option('eventosapp_task_queue_schedule_migration_cursor', 0));

    if ( $done_version !== $version ) {
        $event_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT 40",
            'eventosapp_event',
            $cursor
        ));

        foreach ( (array)$event_ids as $event_id ) {
            $event_id = absint($event_id);
            eventosapp_task_queue_sync_email_reminder($event_id);
            eventosapp_task_queue_sync_ticket_reminders($event_id);
            eventosapp_task_queue_sync_attendance_schedule($event_id);
            $cursor = max($cursor, $event_id);
        }

        if ( empty($event_ids) || count($event_ids) < 40 ) {
            update_option('eventosapp_task_queue_schedule_migration_version', $version, false);
            delete_option('eventosapp_task_queue_schedule_migration_cursor');
        } else {
            update_option('eventosapp_task_queue_schedule_migration_cursor', $cursor, false);
        }
    }

    // Recupera hasta cinco colas antiguas de recordatorios por correo.
    $legacy_email = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'eventosapp_rq\\_%' ESCAPE '\\\\'
         ORDER BY option_id ASC LIMIT 5",
        ARRAY_A
    );
    foreach ( (array)$legacy_email as $row ) {
        $name = (string)($row['option_name'] ?? '');
        $event_id = absint(substr($name, strlen('eventosapp_rq_')));
        $queue = maybe_unserialize($row['option_value'] ?? '');
        $queue = eventosapp_task_queue_ticket_ids($queue);
        if ( ! $event_id || empty($queue) ) {
            delete_option($name);
            continue;
        }
        $existing = absint(get_post_meta($event_id, '_eventosapp_reminder_queue_task_id', true));
        $existing_task = $existing ? eventosapp_task_queue_get($existing) : null;
        if ( ! $existing_task || in_array($existing_task['status'], eventosapp_task_queue_terminal_statuses(), true) ) {
            $task_id = eventosapp_task_queue_create([
                'task_type'=>'email_reminder_scheduled','task_group'=>'scheduled','channel'=>'email',
                'title'=>'Recordatorio por correo recuperado · ' . get_the_title($event_id),
                'event_id'=>$event_id,'status'=>'queued','total_items'=>count($queue),
                'payload'=>['event_id'=>$event_id,'prepared'=>1,'ticket_ids'=>$queue,'migrated_legacy_queue'=>1],
                'notification_email'=>sanitize_email(get_option('admin_email')),'created_by'=>0,
            ]);
            if ( ! is_wp_error($task_id) ) update_post_meta($event_id, '_eventosapp_reminder_queue_task_id', $task_id);
        }
        delete_option($name);
        wp_clear_scheduled_hook('eventosapp_process_event_reminder_queue', [$event_id]);
    }

    // Recupera hasta cinco trabajos de confirmación que estaban entre lotes.
    $legacy_attendance = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'evapp_attendance_job\\_%' ESCAPE '\\\\'
         ORDER BY option_id ASC LIMIT 5",
        ARRAY_A
    );
    foreach ( (array)$legacy_attendance as $row ) {
        $name = (string)($row['option_name'] ?? '');
        $job = maybe_unserialize($row['option_value'] ?? '');
        if ( ! is_array($job) ) { delete_option($name); continue; }
        $event_id = absint($job['event_id'] ?? 0);
        $config = is_array($job['config'] ?? null) ? $job['config'] : [];
        $all_ids = function_exists('eventosapp_attendance_confirmation_get_filtered_tickets')
            ? eventosapp_attendance_confirmation_get_filtered_tickets($config['filters'] ?? ['evento_id'=>$event_id])
            : [];
        $cursor_id = absint($job['cursor'] ?? 0);
        $remaining = array_values(array_filter(eventosapp_task_queue_ticket_ids($all_ids), static function($id) use ($cursor_id) {
            return absint($id) > $cursor_id;
        }));
        $channels = function_exists('eventosapp_attendance_confirmation_sanitize_channels')
            ? eventosapp_attendance_confirmation_sanitize_channels($config['channels'] ?? ['email'])
            : ['email'];
        $send_config = [
            'channels'=>$channels,'email_template'=>$config['email_template'] ?? 'attendance-confirmation.html',
            'email_subject'=>$config['email_subject'] ?? 'Confirma tu asistencia a {{evento_nombre}}',
            'email_message'=>$config['email_message'] ?? '',
            'whatsapp_template_id'=>$config['whatsapp_template_id'] ?? '',
        ];
        if ( $event_id && ! empty($remaining) ) {
            eventosapp_task_queue_create([
                'task_type'=>'attendance_scheduled','task_group'=>'scheduled','channel'=>implode('+',$channels),
                'title'=>'Confirmación programada recuperada · ' . get_the_title($event_id),
                'event_id'=>$event_id,'status'=>'queued','total_items'=>count($remaining),
                'payload'=>['ticket_ids'=>$remaining,'prepared'=>1,'config'=>$send_config,'source'=>'scheduled','source_ref'=>'legacy:' . sanitize_key((string)($job['job_id'] ?? ''))],
                'notification_email'=>sanitize_email(get_option('admin_email')),'created_by'=>0,
            ]);
        }
        $job_id = sanitize_key((string)($job['job_id'] ?? substr($name, strlen('evapp_attendance_job_'))));
        if ( defined('EVENTOSAPP_ATTENDANCE_CONFIRMATION_BATCH_HOOK') && $job_id !== '' ) {
            wp_clear_scheduled_hook(EVENTOSAPP_ATTENDANCE_CONFIRMATION_BATCH_HOOK, [$job_id]);
        }
        delete_option($name);
    }

    // Clasifica gradualmente las tareas históricas creadas por la versión anterior.
    eventosapp_task_queue_reconcile_scheduled_tasks(250);
}
add_action('init', 'eventosapp_task_queue_migrate_existing_schedules', 90);
