<?php
/**
 * EventosApp - Filtros y log para WhatsApp Flow programado por evento.
 *
 * Extiende el metabox "WhatsApp Flows — Configuración y Programación" sin
 * duplicar el motor de segmentación del envío masivo. La audiencia se resuelve
 * justo cuando comienza la tarea y los filtros se procesan con
 * eventosapp_whatsapp_flows_bulk_get_filtered_tickets().
 *
 * Ruta: includes/admin/eventosapp-whatsapp-event-flow-schedule-controls.php
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! defined('EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_CONTROLS_VERSION') ) {
    define('EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_CONTROLS_VERSION', '2026.08.19.1');
}

/**
 * Normaliza los filtros del metabox con el mismo sanitizador del envío masivo.
 */
function eventosapp_whatsapp_event_flow_schedule_normalize_filters($event_id, $flow_post_id, $filters) {
    $event_id = absint($event_id);
    $flow_post_id = absint($flow_post_id);
    $filters = is_array($filters) ? $filters : [];

    if ( function_exists('eventosapp_whatsapp_flows_bulk_sanitize_filters') ) {
        $filters = eventosapp_whatsapp_flows_bulk_sanitize_filters($filters);
    } else {
        $clean = [];
        foreach ( $filters as $key => $value ) {
            $key = sanitize_key((string)$key);
            if ( $key === '' ) {
                continue;
            }
            if ( $key === 'extra_fields' && is_array($value) ) {
                $extra = [];
                foreach ( $value as $field_key => $field_value ) {
                    $field_key = sanitize_key((string)$field_key);
                    $field_value = is_scalar($field_value) ? sanitize_text_field((string)$field_value) : '';
                    if ( $field_key !== '' && $field_value !== '' ) {
                        $extra[$field_key] = $field_value;
                    }
                }
                if ( $extra ) {
                    $clean['extra_fields'] = $extra;
                }
                continue;
            }
            if ( is_scalar($value) ) {
                $value = sanitize_text_field((string)$value);
                if ( $value !== '' ) {
                    $clean[$key] = $value;
                }
            }
        }
        $filters = $clean;
    }

    if ( $event_id ) {
        $filters['evento_id'] = $event_id;
    }
    if ( $flow_post_id ) {
        $filters['flow_id'] = $flow_post_id;
    }

    // Evita combinaciones imposibles: responder implica haber recibido y enviado.
    if ( ($filters['flow_response_filter'] ?? '') === 'include_responded' ) {
        $filters['flow_received_filter'] = 'include_received';
        $filters['flow_sent_filter'] = 'include_sent';
    } elseif ( ($filters['flow_received_filter'] ?? '') === 'include_received' ) {
        $filters['flow_sent_filter'] = 'include_sent';
    }

    return $filters;
}

function eventosapp_whatsapp_event_flow_schedule_filter_signature($filters, $respect_rules = false) {
    $filters = is_array($filters) ? $filters : [];
    if ( isset($filters['extra_fields']) && is_array($filters['extra_fields']) ) {
        ksort($filters['extra_fields'], SORT_STRING);
    }
    ksort($filters, SORT_STRING);
    return hash('sha256', wp_json_encode([
        'filters'       => $filters,
        'respect_rules' => $respect_rules ? 1 : 0,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Localidades usadas por los tickets del evento.
 */
function eventosapp_whatsapp_event_flow_schedule_localidades($event_id) {
    global $wpdb;
    $event_id = absint($event_id);
    if ( ! $event_id ) {
        return [];
    }

    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT locality.meta_value
         FROM {$wpdb->postmeta} event_meta
         INNER JOIN {$wpdb->postmeta} locality
            ON locality.post_id = event_meta.post_id
           AND locality.meta_key = '_eventosapp_asistente_localidad'
         WHERE event_meta.meta_key = '_eventosapp_ticket_evento_id'
           AND event_meta.meta_value = %d
           AND locality.meta_value <> ''
         ORDER BY locality.meta_value ASC",
        $event_id
    ));

    return array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)$rows))));
}

function eventosapp_whatsapp_event_flow_schedule_select($name, $value, $options, $empty_label = 'Todos') {
    $options = is_array($options) ? $options : [];
    echo '<select name="' . esc_attr($name) . '">';
    echo '<option value="">' . esc_html($empty_label) . '</option>';
    foreach ( $options as $key => $label ) {
        echo '<option value="' . esc_attr((string)$key) . '" ' . selected((string)$value, (string)$key, false) . '>' . esc_html((string)$label) . '</option>';
    }
    echo '</select>';
}

function eventosapp_whatsapp_event_flow_schedule_format_task_datetime($value, $event_id) {
    $value = sanitize_text_field((string)$value);
    if ( $value === '' ) {
        return '';
    }
    try {
        $utc = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        $timezone = function_exists('eventosapp_whatsapp_event_hub_timezone_object')
            ? eventosapp_whatsapp_event_hub_timezone_object($event_id)
            : wp_timezone();
        return $utc->setTimezone($timezone)->format('Y-m-d H:i:s T');
    } catch (Throwable $e) {
        return $value;
    }
}

/**
 * Resumen legible de los filtros persistidos.
 */
function eventosapp_whatsapp_event_flow_schedule_filter_summary($filters, $respect_rules = false) {
    $filters = is_array($filters) ? $filters : [];
    $summary = [];

    $maps = [
        'flow_sent_filter' => function_exists('eventosapp_whatsapp_flows_bulk_flow_sent_filter_options') ? eventosapp_whatsapp_flows_bulk_flow_sent_filter_options() : [],
        'flow_received_filter' => function_exists('eventosapp_whatsapp_flows_bulk_flow_received_filter_options') ? eventosapp_whatsapp_flows_bulk_flow_received_filter_options() : [],
        'flow_response_filter' => function_exists('eventosapp_whatsapp_flows_bulk_flow_response_filter_options') ? eventosapp_whatsapp_flows_bulk_flow_response_filter_options() : [],
        'checkin_filter' => function_exists('eventosapp_whatsapp_flows_bulk_checkin_filter_options') ? eventosapp_whatsapp_flows_bulk_checkin_filter_options() : [],
        'whatsapp_status' => function_exists('eventosapp_whatsapp_flows_bulk_status_options') ? eventosapp_whatsapp_flows_bulk_status_options() : [],
        'delivery_status' => function_exists('eventosapp_whatsapp_flows_bulk_delivery_options') ? eventosapp_whatsapp_flows_bulk_delivery_options() : [],
        'modalidad' => function_exists('eventosapp_whatsapp_flows_bulk_modalidad_labels') ? eventosapp_whatsapp_flows_bulk_modalidad_labels() : [],
    ];

    foreach ( $maps as $key => $options ) {
        $selected = sanitize_text_field((string)($filters[$key] ?? ''));
        if ( $selected !== '' ) {
            $summary[] = $options[$selected] ?? ($key . ': ' . $selected);
        }
    }

    $simple_labels = [
        'localidad'      => 'Localidad',
        'event_date'     => 'Día del evento',
        'last_sent_from' => 'Último WhatsApp desde',
        'last_sent_to'   => 'Último WhatsApp hasta',
        'created_from'   => 'Ticket creado desde',
        'created_to'     => 'Ticket creado hasta',
    ];
    foreach ( $simple_labels as $key => $label ) {
        $value = sanitize_text_field((string)($filters[$key] ?? ''));
        if ( $value !== '' ) {
            $summary[] = $label . ': ' . $value;
        }
    }

    if ( ! empty($filters['extra_fields']) && is_array($filters['extra_fields']) ) {
        foreach ( $filters['extra_fields'] as $key => $value ) {
            $summary[] = 'Campo adicional ' . sanitize_text_field((string)$key) . ': ' . sanitize_text_field((string)$value);
        }
    }
    if ( $respect_rules ) {
        $summary[] = 'Respetar reglas de envío de Tickets WhatsApp';
    }

    return $summary;
}

/**
 * Captura el estado anterior antes de que el handler rc.31 reconstruya el meta.
 */
function eventosapp_whatsapp_event_flow_schedule_controls_capture_previous($post_id, $post) {
    $post_id = absint($post_id);
    if ( ! $post_id || ! function_exists('eventosapp_whatsapp_event_hub_is_event') || ! eventosapp_whatsapp_event_hub_is_event($post_id) ) {
        return;
    }
    $previous = get_post_meta($post_id, EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META, true);
    $GLOBALS['eventosapp_whatsapp_flow_schedule_previous_' . $post_id] = is_array($previous) ? $previous : [];
}
add_action('save_post_eventosapp_event', 'eventosapp_whatsapp_event_flow_schedule_controls_capture_previous', 170, 2);
add_action('save_post_eventosapp_events', 'eventosapp_whatsapp_event_flow_schedule_controls_capture_previous', 170, 2);

/**
 * Guarda filtros y los sincroniza con la tarea que rc.31 acaba de crear/reusar.
 */
function eventosapp_whatsapp_event_flow_schedule_controls_save($post_id, $post) {
    $post_id = absint($post_id);
    if ( ! $post_id || ! function_exists('eventosapp_whatsapp_event_hub_is_event') || ! eventosapp_whatsapp_event_hub_is_event($post_id) ) {
        return;
    }
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision($post_id) ) {
        return;
    }
    if ( ! isset($_POST['eventosapp_whatsapp_event_hub_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventosapp_whatsapp_event_hub_nonce'])), 'eventosapp_whatsapp_event_hub_save') ) {
        return;
    }
    if ( ! current_user_can('edit_post', $post_id) ) {
        return;
    }

    $raw = isset($_POST['evapp_whatsapp_flow_schedule']) && is_array($_POST['evapp_whatsapp_flow_schedule'])
        ? wp_unslash($_POST['evapp_whatsapp_flow_schedule'])
        : [];
    $raw_filters = isset($raw['filters']) && is_array($raw['filters']) ? $raw['filters'] : [];
    $respect_rules = ! empty($raw['respect_rules']);

    $schedule = get_post_meta($post_id, EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META, true);
    $schedule = is_array($schedule) ? $schedule : [];
    $flow_post_id = absint($schedule['flow_post_id'] ?? get_post_meta($post_id, '_eventosapp_whatsapp_satisfaction_flow_post_id', true));
    $filters = eventosapp_whatsapp_event_flow_schedule_normalize_filters($post_id, $flow_post_id, $raw_filters);
    $filter_signature = eventosapp_whatsapp_event_flow_schedule_filter_signature($filters, $respect_rules);

    $previous = $GLOBALS['eventosapp_whatsapp_flow_schedule_previous_' . $post_id] ?? [];
    $previous = is_array($previous) ? $previous : [];
    $previous_signature = (string)($previous['filter_signature'] ?? eventosapp_whatsapp_event_flow_schedule_filter_signature(
        is_array($previous['filters'] ?? null) ? $previous['filters'] : [],
        ! empty($previous['respect_rules'])
    ));

    $schedule['filters'] = $filters;
    $schedule['respect_rules'] = $respect_rules ? '1' : '0';
    $schedule['filter_signature'] = $filter_signature;
    $schedule['filters_updated_at'] = current_time('mysql');
    unset($schedule['filters_warning']);

    $task_id = absint($schedule['queue_task_id'] ?? 0);
    $task = ($task_id && function_exists('eventosapp_task_queue_get')) ? eventosapp_task_queue_get($task_id) : null;

    if ( is_array($task) && ! in_array((string)($task['status'] ?? ''), function_exists('eventosapp_task_queue_terminal_statuses') ? eventosapp_task_queue_terminal_statuses() : ['cancelled','completed','expired','failed','archived'], true) ) {
        $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
        $has_started = absint($task['processed_items'] ?? 0) > 0 || in_array((string)($task['status'] ?? ''), ['running'], true);

        if ( $has_started && ! hash_equals($previous_signature, $filter_signature) ) {
            // No cambiamos una audiencia que ya comenzó a procesarse.
            $payload_filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : (is_array($previous['filters'] ?? null) ? $previous['filters'] : []);
            $payload_respect_rules = ! empty($payload['respect_rules']) || ! empty($previous['respect_rules']);
            $schedule['filters'] = eventosapp_whatsapp_event_flow_schedule_normalize_filters($post_id, $flow_post_id, $payload_filters);
            $schedule['respect_rules'] = $payload_respect_rules ? '1' : '0';
            $schedule['filter_signature'] = eventosapp_whatsapp_event_flow_schedule_filter_signature($schedule['filters'], $payload_respect_rules);
            $schedule['filters_warning'] = 'La tarea ya había comenzado. Para mantener una audiencia consistente, los filtros activos no se modificaron durante la ejecución.';
            if ( function_exists('eventosapp_task_queue_add_log') ) {
                eventosapp_task_queue_add_log($task_id, 'warning', $schedule['filters_warning']);
            }
        } else {
            $payload['filters'] = $filters;
            $payload['respect_rules'] = $respect_rules ? 1 : 0;
            $payload['filter_signature'] = $filter_signature;
            $payload['filters_updated_at_utc'] = current_time('mysql', true);
            if ( function_exists('eventosapp_task_queue_update') ) {
                eventosapp_task_queue_update($task_id, ['payload' => $payload]);
            }
            if ( ! hash_equals($previous_signature, $filter_signature) && function_exists('eventosapp_task_queue_add_log') ) {
                eventosapp_task_queue_add_log($task_id, 'info', 'Filtros de audiencia actualizados desde el metabox WhatsApp Flows.', [
                    'filters'       => $filters,
                    'respect_rules' => $respect_rules ? 1 : 0,
                ]);
            }
        }
    }

    update_post_meta($post_id, EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META, $schedule);
    unset($GLOBALS['eventosapp_whatsapp_flow_schedule_previous_' . $post_id]);
}
add_action('save_post_eventosapp_event', 'eventosapp_whatsapp_event_flow_schedule_controls_save', 200, 2);
add_action('save_post_eventosapp_events', 'eventosapp_whatsapp_event_flow_schedule_controls_save', 200, 2);

/**
 * Procesador de la tarea programada con filtros del mismo motor masivo.
 */
function eventosapp_whatsapp_event_flow_schedule_controls_process($task, $runtime) {
    if ( ! function_exists('eventosapp_task_queue_process_flow_bulk') ) {
        return new WP_Error('flow_queue_adapter_missing', 'No está disponible el procesador de WhatsApp Flow de la cola central.');
    }

    $task = is_array($task) ? $task : [];
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    $event_id = absint($task['event_id'] ?? ($payload['event_id'] ?? 0));
    $flow_post_id = absint($payload['flow_post_id'] ?? 0);
    $ticket_ids = function_exists('eventosapp_task_queue_ticket_ids') ? eventosapp_task_queue_ticket_ids($payload['ticket_ids'] ?? []) : [];

    if ( empty($payload['prepared']) ) {
        $filters = eventosapp_whatsapp_event_flow_schedule_normalize_filters($event_id, $flow_post_id, is_array($payload['filters'] ?? null) ? $payload['filters'] : []);

        if ( function_exists('eventosapp_whatsapp_flows_bulk_get_filtered_tickets') ) {
            $ticket_ids = eventosapp_whatsapp_flows_bulk_get_filtered_tickets($filters);
        } elseif ( function_exists('eventosapp_whatsapp_event_flow_schedule_ticket_ids') ) {
            $ticket_ids = eventosapp_whatsapp_event_flow_schedule_ticket_ids($event_id);
        } else {
            $ticket_ids = [];
        }

        $ticket_ids = function_exists('eventosapp_task_queue_ticket_ids')
            ? eventosapp_task_queue_ticket_ids($ticket_ids)
            : array_values(array_unique(array_filter(array_map('absint', $ticket_ids))));

        $payload['filters'] = $filters;
        $payload['respect_rules'] = ! empty($payload['respect_rules']) ? 1 : 0;
        $payload['ticket_ids'] = $ticket_ids;
        $payload['prepared'] = 1;
        $payload['prepared_at_utc'] = current_time('mysql', true);
        $payload['audience_total'] = count($ticket_ids);

        if ( function_exists('eventosapp_task_queue_update') && ! empty($task['id']) ) {
            eventosapp_task_queue_update(absint($task['id']), [
                'payload'        => $payload,
                'total_items'    => count($ticket_ids),
                'cursor_value'   => 0,
                'processed_items'=> 0,
                'success_items'  => 0,
                'error_items'    => 0,
                'skipped_items'  => 0,
            ]);
        }
        if ( function_exists('eventosapp_task_queue_add_log') && ! empty($task['id']) ) {
            eventosapp_task_queue_add_log(absint($task['id']), 'info', 'Audiencia preparada con los filtros del envío masivo de Flows: ' . count($ticket_ids) . ' ticket(s) seleccionado(s).', [
                'filters'       => $filters,
                'respect_rules' => ! empty($payload['respect_rules']) ? 1 : 0,
                'audience_total'=> count($ticket_ids),
            ]);
        }

        $task['payload'] = $payload;
        $task['total_items'] = count($ticket_ids);
        $task['cursor_value'] = 0;
        $task['processed_items'] = 0;
        $task['success_items'] = 0;
        $task['error_items'] = 0;
        $task['skipped_items'] = 0;
    }

    $segment = [
        'event_id'                   => $event_id,
        'flow_post_id'               => $flow_post_id,
        'flow_template_id'           => sanitize_key((string)($payload['flow_template_id'] ?? '')),
        'sender_phone_number_id'     => sanitize_text_field((string)($payload['sender_phone_number_id'] ?? '')),
        'flow_template_header_image' => esc_url_raw((string)($payload['header_image_url'] ?? '')),
        'respect_rules'              => ! empty($payload['respect_rules']) ? 1 : 0,
        'filters'                    => is_array($payload['filters'] ?? null) ? $payload['filters'] : [],
    ];

    $task['payload']['segment_id'] = sanitize_key('scheduled_flow_' . absint($task['id'] ?? 0));
    $task['payload']['segment'] = $segment;
    $task['payload']['ticket_ids'] = $ticket_ids;

    $result = eventosapp_task_queue_process_flow_bulk($task, $runtime);

    if ( is_array($result) && ! empty($result['done']) && function_exists('eventosapp_task_queue_add_log') && ! empty($task['id']) ) {
        $processed = absint($task['processed_items'] ?? 0) + absint($result['processed'] ?? 0);
        $success = absint($task['success_items'] ?? 0) + absint($result['success'] ?? 0);
        $errors = absint($task['error_items'] ?? 0) + absint($result['errors'] ?? 0);
        $skipped = absint($task['skipped_items'] ?? 0) + absint($result['skipped'] ?? 0);
        $level = $errors > 0 ? 'warning' : 'success';
        eventosapp_task_queue_add_log(absint($task['id']), $level, 'Ejecución del Flow programado finalizada: ' . $processed . ' procesados, ' . $success . ' enviados, ' . $skipped . ' omitidos y ' . $errors . ' errores.', [
            'processed' => $processed,
            'success'   => $success,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ]);
    }

    return $result;
}

/**
 * rc.31 registraba el adaptador en prioridad 45. Se reemplaza únicamente el
 * callback de la misma tarea para sumar segmentación/log, conservando batches.
 */
add_action('init', function() {
    if ( ! function_exists('eventosapp_task_queue_register_adapter') ) {
        return;
    }
    eventosapp_task_queue_register_adapter('whatsapp_flow_scheduled', [
        'label'          => 'WhatsApp Flow programado por evento',
        'group'          => 'scheduled',
        'channel'        => 'flow',
        'batch_size'     => 5,
        'min_batch_size' => 1,
        'max_batch_size' => 10,
        'process_batch'  => 'eventosapp_whatsapp_event_flow_schedule_controls_process',
    ]);
}, 46);

/**
 * Añade filtros y log dentro del metabox existente sin duplicar su registro.
 */
function eventosapp_whatsapp_event_flow_schedule_controls_footer() {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( ! $screen || ! in_array((string)$screen->post_type, function_exists('eventosapp_whatsapp_event_hub_post_types') ? eventosapp_whatsapp_event_hub_post_types() : ['eventosapp_event','eventosapp_events'], true) ) {
        return;
    }

    $event_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
    if ( ! $event_id ) {
        global $post;
        $event_id = $post instanceof WP_Post ? absint($post->ID) : 0;
    }
    if ( ! $event_id ) {
        return;
    }

    $schedule = get_post_meta($event_id, EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_META, true);
    $schedule = is_array($schedule) ? $schedule : [];
    $flow_post_id = absint($schedule['flow_post_id'] ?? get_post_meta($event_id, '_eventosapp_whatsapp_satisfaction_flow_post_id', true));
    $filters = eventosapp_whatsapp_event_flow_schedule_normalize_filters($event_id, $flow_post_id, is_array($schedule['filters'] ?? null) ? $schedule['filters'] : []);
    $respect_rules = ! empty($schedule['respect_rules']);
    $localidades = eventosapp_whatsapp_event_flow_schedule_localidades($event_id);
    $event_days = function_exists('eventosapp_whatsapp_flows_bulk_get_event_valid_days') ? eventosapp_whatsapp_flows_bulk_get_event_valid_days($event_id) : [];
    $extra_fields = function_exists('eventosapp_whatsapp_flows_bulk_get_event_extra_fields_schema') ? eventosapp_whatsapp_flows_bulk_get_event_extra_fields_schema($event_id) : [];

    $task_id = absint($schedule['queue_task_id'] ?? 0);
    $task = ($task_id && function_exists('eventosapp_task_queue_get')) ? eventosapp_task_queue_get($task_id) : null;
    $logs = ($task_id && function_exists('eventosapp_task_queue_get_logs')) ? eventosapp_task_queue_get_logs($task_id, 40) : [];
    $logs = array_reverse(is_array($logs) ? $logs : []);
    $status_labels = function_exists('eventosapp_task_queue_statuses') ? eventosapp_task_queue_statuses() : [];
    $filter_summary = eventosapp_whatsapp_event_flow_schedule_filter_summary($filters, $respect_rules);
    $filters_warning = sanitize_text_field((string)($schedule['filters_warning'] ?? ''));
    ?>
    <div id="evapp-wa-flow-schedule-controls-source" style="display:none">
        <div class="evapp-wa-schedule-controls" data-evapp-flow-schedule-controls>
            <div class="evapp-wa-hub-section"><h4>3. Filtros de audiencia</h4><p>Usa la misma segmentación del envío masivo de Flows. Los filtros se evalúan justo cuando comienza la tarea para tomar el estado real de los asistentes en ese momento.</p></div>
            <div class="evapp-wa-schedule-box evapp-wa-filter-box">
                <?php if ( $filters_warning !== '' ) : ?><div class="evapp-wa-status is-error"><strong>Atención:</strong> <?php echo esc_html($filters_warning); ?></div><?php endif; ?>

                <div class="evapp-wa-filter-grid">
                    <label>Envío previo de este Flow<br><?php eventosapp_whatsapp_event_flow_schedule_select('evapp_whatsapp_flow_schedule[filters][flow_sent_filter]', $filters['flow_sent_filter'] ?? '', function_exists('eventosapp_whatsapp_flows_bulk_flow_sent_filter_options') ? eventosapp_whatsapp_flows_bulk_flow_sent_filter_options() : [], 'Todos'); ?></label>
                    <label>Recepción de este Flow<br><?php eventosapp_whatsapp_event_flow_schedule_select('evapp_whatsapp_flow_schedule[filters][flow_received_filter]', $filters['flow_received_filter'] ?? '', function_exists('eventosapp_whatsapp_flows_bulk_flow_received_filter_options') ? eventosapp_whatsapp_flows_bulk_flow_received_filter_options() : [], 'Todos'); ?></label>
                    <label>Respuesta de este Flow<br><?php eventosapp_whatsapp_event_flow_schedule_select('evapp_whatsapp_flow_schedule[filters][flow_response_filter]', $filters['flow_response_filter'] ?? '', function_exists('eventosapp_whatsapp_flows_bulk_flow_response_filter_options') ? eventosapp_whatsapp_flows_bulk_flow_response_filter_options() : [], 'Todos'); ?></label>
                    <label>Check-in del asistente<br><?php eventosapp_whatsapp_event_flow_schedule_select('evapp_whatsapp_flow_schedule[filters][checkin_filter]', $filters['checkin_filter'] ?? '', function_exists('eventosapp_whatsapp_flows_bulk_checkin_filter_options') ? eventosapp_whatsapp_flows_bulk_checkin_filter_options() : [], 'Todos'); ?><span class="evapp-wa-hub-help">Incluye check-in presencial y virtual. Si eliges un día, el check-in se valida para ese día.</span></label>
                    <label>Estado WhatsApp<br><?php eventosapp_whatsapp_event_flow_schedule_select('evapp_whatsapp_flow_schedule[filters][whatsapp_status]', $filters['whatsapp_status'] ?? '', function_exists('eventosapp_whatsapp_flows_bulk_status_options') ? eventosapp_whatsapp_flows_bulk_status_options() : [], 'Todos'); ?></label>
                    <label>Estado de entrega<br><?php eventosapp_whatsapp_event_flow_schedule_select('evapp_whatsapp_flow_schedule[filters][delivery_status]', $filters['delivery_status'] ?? '', function_exists('eventosapp_whatsapp_flows_bulk_delivery_options') ? eventosapp_whatsapp_flows_bulk_delivery_options() : [], 'Todos'); ?></label>
                    <label>Modalidad<br><?php eventosapp_whatsapp_event_flow_schedule_select('evapp_whatsapp_flow_schedule[filters][modalidad]', $filters['modalidad'] ?? '', function_exists('eventosapp_whatsapp_flows_bulk_modalidad_labels') ? eventosapp_whatsapp_flows_bulk_modalidad_labels() : [], 'Todas'); ?></label>
                    <label>Localidad<br><?php eventosapp_whatsapp_event_flow_schedule_select('evapp_whatsapp_flow_schedule[filters][localidad]', $filters['localidad'] ?? '', array_combine($localidades, $localidades) ?: [], 'Todas'); ?></label>
                    <label>Día del evento<br><?php eventosapp_whatsapp_event_flow_schedule_select('evapp_whatsapp_flow_schedule[filters][event_date]', $filters['event_date'] ?? '', array_combine($event_days, $event_days) ?: [], 'Todos los días'); ?></label>
                </div>

                <div class="evapp-wa-filter-subsection"><strong>Fechas de actividad</strong></div>
                <div class="evapp-wa-filter-grid">
                    <label>Último WhatsApp desde<br><input type="date" name="evapp_whatsapp_flow_schedule[filters][last_sent_from]" value="<?php echo esc_attr($filters['last_sent_from'] ?? ''); ?>"></label>
                    <label>Último WhatsApp hasta<br><input type="date" name="evapp_whatsapp_flow_schedule[filters][last_sent_to]" value="<?php echo esc_attr($filters['last_sent_to'] ?? ''); ?>"></label>
                    <label>Ticket creado desde<br><input type="date" name="evapp_whatsapp_flow_schedule[filters][created_from]" value="<?php echo esc_attr($filters['created_from'] ?? ''); ?>"></label>
                    <label>Ticket creado hasta<br><input type="date" name="evapp_whatsapp_flow_schedule[filters][created_to]" value="<?php echo esc_attr($filters['created_to'] ?? ''); ?>"></label>
                </div>

                <?php if ( ! empty($extra_fields) ) : ?>
                    <div class="evapp-wa-filter-subsection"><strong>Campos adicionales del evento</strong></div>
                    <div class="evapp-wa-filter-grid">
                        <?php foreach ( $extra_fields as $field ) :
                            $field_key = sanitize_key((string)($field['key'] ?? ''));
                            if ( $field_key === '' ) continue;
                            $field_label = sanitize_text_field((string)($field['label'] ?? $field_key));
                            $field_value = sanitize_text_field((string)($filters['extra_fields'][$field_key] ?? ''));
                            $field_options = is_array($field['options'] ?? null) ? $field['options'] : [];
                        ?>
                            <label><?php echo esc_html($field_label); ?><br>
                                <?php if ( ! empty($field_options) ) : ?>
                                    <?php eventosapp_whatsapp_event_flow_schedule_select('evapp_whatsapp_flow_schedule[filters][extra_fields][' . $field_key . ']', $field_value, array_combine($field_options, $field_options) ?: [], 'Todos'); ?>
                                <?php else : ?>
                                    <input type="text" name="evapp_whatsapp_flow_schedule[filters][extra_fields][<?php echo esc_attr($field_key); ?>]" value="<?php echo esc_attr($field_value); ?>">
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <p style="margin:14px 0 0"><label><input type="checkbox" name="evapp_whatsapp_flow_schedule[respect_rules]" value="1" <?php checked($respect_rules); ?>> Respetar también las reglas configuradas en <strong>WhatsApp — Tickets y Recordatorios</strong></label></p>
                <p class="evapp-wa-hub-help">Si no seleccionas filtros, la programación conserva el comportamiento amplio de rc.31 y toma todos los tickets del evento. Así no se alteran programaciones históricas por defecto.</p>
            </div>

            <div class="evapp-wa-hub-section"><h4>4. Log de la programación</h4><p>Resumen y trazabilidad de la tarea central. El detalle completo permanece disponible en Cola y Tareas.</p></div>
            <div class="evapp-wa-schedule-box evapp-wa-log-box">
                <?php if ( ! $task_id ) : ?>
                    <p>La programación todavía no tiene una tarea asociada. Al guardar una fecha/hora futura, el log comenzará con el registro de creación.</p>
                <?php elseif ( ! is_array($task) ) : ?>
                    <div class="evapp-wa-status is-error">La tarea #<?php echo esc_html((string)$task_id); ?> ya no está disponible en Cola y Tareas.</div>
                <?php else : ?>
                    <div class="evapp-wa-task-summary">
                        <div><strong>Estado</strong><span><?php echo esc_html($status_labels[$task['status']] ?? (string)$task['status']); ?></span></div>
                        <div><strong>Audiencia</strong><span><?php echo esc_html((string)absint($task['total_items'] ?? 0)); ?></span></div>
                        <div><strong>Procesados</strong><span><?php echo esc_html((string)absint($task['processed_items'] ?? 0)); ?></span></div>
                        <div><strong>Enviados</strong><span><?php echo esc_html((string)absint($task['success_items'] ?? 0)); ?></span></div>
                        <div><strong>Omitidos</strong><span><?php echo esc_html((string)absint($task['skipped_items'] ?? 0)); ?></span></div>
                        <div><strong>Errores</strong><span><?php echo esc_html((string)absint($task['error_items'] ?? 0)); ?></span></div>
                    </div>
                    <p class="evapp-wa-hub-help"><strong>Programado:</strong> <?php echo esc_html(eventosapp_whatsapp_event_flow_schedule_format_task_datetime($task['scheduled_at'] ?? '', $event_id)); ?><?php if ( ! empty($task['started_at']) ) : ?> · <strong>Inicio:</strong> <?php echo esc_html(eventosapp_whatsapp_event_flow_schedule_format_task_datetime($task['started_at'], $event_id)); ?><?php endif; ?><?php if ( ! empty($task['completed_at']) ) : ?> · <strong>Fin:</strong> <?php echo esc_html(eventosapp_whatsapp_event_flow_schedule_format_task_datetime($task['completed_at'], $event_id)); ?><?php endif; ?></p>
                    <?php if ( ! empty($filter_summary) ) : ?><div class="evapp-wa-active-filters"><strong>Filtros activos al guardar:</strong> <?php echo esc_html(implode(' · ', $filter_summary)); ?></div><?php else : ?><div class="evapp-wa-active-filters"><strong>Filtros activos:</strong> ninguno; audiencia completa del evento.</div><?php endif; ?>
                    <?php if ( ! empty($task['last_error']) ) : ?><div class="evapp-wa-status is-error"><strong>Último error:</strong> <?php echo esc_html((string)$task['last_error']); ?></div><?php endif; ?>

                    <div class="evapp-wa-log-list">
                        <?php if ( empty($logs) ) : ?>
                            <p>No hay entradas adicionales todavía.</p>
                        <?php else : ?>
                            <?php foreach ( $logs as $row ) :
                                $level = sanitize_key((string)($row['level'] ?? 'info'));
                                $created = eventosapp_whatsapp_event_flow_schedule_format_task_datetime($row['created_at'] ?? '', $event_id);
                            ?>
                                <div class="evapp-wa-log-row is-<?php echo esc_attr($level); ?>"><span class="evapp-wa-log-time"><?php echo esc_html($created); ?></span><span class="evapp-wa-log-level"><?php echo esc_html(strtoupper($level)); ?></span><span class="evapp-wa-log-message"><?php echo esc_html((string)($row['message'] ?? '')); ?></span></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ( function_exists('eventosapp_task_queue_task_url') ) : ?><p style="margin:12px 0 0"><a class="button" href="<?php echo esc_url(eventosapp_task_queue_task_url($task_id)); ?>">Abrir log completo en Cola y Tareas</a></p><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <style>
        #eventosapp_whatsapp_event_flows .evapp-wa-filter-grid{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:12px 16px;margin-top:10px}
        #eventosapp_whatsapp_event_flows .evapp-wa-filter-grid label{font-weight:600;line-height:1.4}
        #eventosapp_whatsapp_event_flows .evapp-wa-filter-grid select,#eventosapp_whatsapp_event_flows .evapp-wa-filter-grid input{width:100%;margin-top:4px}
        #eventosapp_whatsapp_event_flows .evapp-wa-filter-subsection{margin:18px 0 6px;padding-top:14px;border-top:1px solid #dcdcde}
        #eventosapp_whatsapp_event_flows .evapp-wa-task-summary{display:grid;grid-template-columns:repeat(6,minmax(90px,1fr));gap:8px}
        #eventosapp_whatsapp_event_flows .evapp-wa-task-summary>div{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:9px;text-align:center}
        #eventosapp_whatsapp_event_flows .evapp-wa-task-summary strong,#eventosapp_whatsapp_event_flows .evapp-wa-task-summary span{display:block}
        #eventosapp_whatsapp_event_flows .evapp-wa-task-summary span{font-size:18px;margin-top:2px}
        #eventosapp_whatsapp_event_flows .evapp-wa-active-filters{margin:12px 0;padding:9px 11px;background:#f0f6fc;border-left:4px solid #72aee6;line-height:1.5}
        #eventosapp_whatsapp_event_flows .evapp-wa-log-list{margin-top:12px;max-height:320px;overflow:auto;border:1px solid #dcdcde;background:#fff;border-radius:8px}
        #eventosapp_whatsapp_event_flows .evapp-wa-log-row{display:grid;grid-template-columns:170px 78px minmax(220px,1fr);gap:8px;padding:8px 10px;border-bottom:1px solid #f0f0f1;align-items:start;font-size:12px}
        #eventosapp_whatsapp_event_flows .evapp-wa-log-row:last-child{border-bottom:0}
        #eventosapp_whatsapp_event_flows .evapp-wa-log-row.is-error{background:#fcf0f1}#eventosapp_whatsapp_event_flows .evapp-wa-log-row.is-warning{background:#fcf9e8}#eventosapp_whatsapp_event_flows .evapp-wa-log-row.is-success{background:#edfaef}
        #eventosapp_whatsapp_event_flows .evapp-wa-log-level{font-weight:700}
        @media(max-width:1100px){#eventosapp_whatsapp_event_flows .evapp-wa-filter-grid{grid-template-columns:repeat(2,minmax(180px,1fr))}#eventosapp_whatsapp_event_flows .evapp-wa-task-summary{grid-template-columns:repeat(3,1fr)}}
        @media(max-width:782px){#eventosapp_whatsapp_event_flows .evapp-wa-filter-grid{grid-template-columns:1fr}#eventosapp_whatsapp_event_flows .evapp-wa-task-summary{grid-template-columns:repeat(2,1fr)}#eventosapp_whatsapp_event_flows .evapp-wa-log-row{grid-template-columns:1fr;gap:2px}}
    </style>
    <script>
    jQuery(function($){
        const $source=$('#evapp-wa-flow-schedule-controls-source');
        const $target=$('#eventosapp_whatsapp_event_flows .inside .evapp-wa-event-hub').first();
        if($source.length&&$target.length){
            $source.children('[data-evapp-flow-schedule-controls]').appendTo($target);
            $source.remove();
        }
    });
    </script>
    <?php
}
add_action('admin_footer-post.php', 'eventosapp_whatsapp_event_flow_schedule_controls_footer', 75);
add_action('admin_footer-post-new.php', 'eventosapp_whatsapp_event_flow_schedule_controls_footer', 75);
