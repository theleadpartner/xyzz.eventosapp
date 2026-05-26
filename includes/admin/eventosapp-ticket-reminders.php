<?php
// includes/admin/eventosapp-ticket-reminders.php
if ( ! defined('ABSPATH') ) exit;

/**
 * EventosApp - Recordatorios programados de ticket por WhatsApp.
 *
 * Este módulo agrega un metabox en eventosapp_event para programar recordatorios
 * de ticket únicamente por WhatsApp. Permite programar por fecha/hora exacta
 * o por anticipación frente a la hora inicial del evento, respetando la zona
 * horaria configurada en el evento.
 */

if ( ! defined('EVENTOSAPP_TICKET_REMINDERS_META_ENABLED') ) {
    define('EVENTOSAPP_TICKET_REMINDERS_META_ENABLED', '_eventosapp_ticket_reminders_enabled');
}
if ( ! defined('EVENTOSAPP_TICKET_REMINDERS_META_ITEMS') ) {
    define('EVENTOSAPP_TICKET_REMINDERS_META_ITEMS', '_eventosapp_ticket_reminders');
}
if ( ! defined('EVENTOSAPP_TICKET_REMINDERS_META_RULES') ) {
    define('EVENTOSAPP_TICKET_REMINDERS_META_RULES', '_eventosapp_ticket_reminder_rules');
}
if ( ! defined('EVENTOSAPP_TICKET_REMINDERS_META_SCHEDULED_KEYS') ) {
    define('EVENTOSAPP_TICKET_REMINDERS_META_SCHEDULED_KEYS', '_eventosapp_ticket_reminders_scheduled_keys');
}
if ( ! defined('EVENTOSAPP_TICKET_REMINDERS_META_EXECUTED') ) {
    define('EVENTOSAPP_TICKET_REMINDERS_META_EXECUTED', '_eventosapp_ticket_reminders_executed');
}
if ( ! defined('EVENTOSAPP_TICKET_REMINDERS_META_LAST_RUN') ) {
    define('EVENTOSAPP_TICKET_REMINDERS_META_LAST_RUN', '_eventosapp_ticket_reminders_last_run');
}
if ( ! defined('EVENTOSAPP_TICKET_REMINDERS_META_LOG') ) {
    define('EVENTOSAPP_TICKET_REMINDERS_META_LOG', '_eventosapp_ticket_reminders_log');
}
if ( ! defined('EVENTOSAPP_TICKET_REMINDERS_CRON_HOOK') ) {
    define('EVENTOSAPP_TICKET_REMINDERS_CRON_HOOK', 'eventosapp_ticket_reminder_cron');
}

function eventosapp_ticket_reminders_sanitize_log_context($value, $depth = 0) {
    if ( $depth > 4 ) {
        return '[max_depth]';
    }

    if ( is_array($value) ) {
        $clean = [];
        $count = 0;
        foreach ( $value as $key => $item ) {
            $count++;
            if ( $count > 80 ) {
                $clean['__truncated__'] = 'Se omitieron elementos adicionales del log.';
                break;
            }
            $safe_key = is_int($key) ? $key : sanitize_key((string)$key);
            if ( $safe_key === '' && ! is_int($key) ) {
                $safe_key = 'key_' . $count;
            }
            $clean[$safe_key] = eventosapp_ticket_reminders_sanitize_log_context($item, $depth + 1);
        }
        return $clean;
    }

    if ( is_bool($value) || is_int($value) || is_float($value) || $value === null ) {
        return $value;
    }

    if ( is_scalar($value) ) {
        $value = wp_strip_all_tags((string)$value);
        if ( strlen($value) > 800 ) {
            $value = substr($value, 0, 800) . '...';
        }
        return sanitize_text_field($value);
    }

    return sanitize_text_field(wp_strip_all_tags(print_r($value, true)));
}

/**
 * Log detallado para revisar cada actividad del módulo.
 */
function eventosapp_ticket_reminders_log($event_id, $message, $context = [], $level = 'info') {
    $event_id = absint($event_id);
    $level = sanitize_key((string)$level);
    if ( ! in_array($level, ['info', 'success', 'warning', 'error', 'debug'], true) ) {
        $level = 'info';
    }

    $entry = [
        'date'    => current_time('mysql'),
        'level'   => $level,
        'message' => sanitize_text_field((string)$message),
        'context' => eventosapp_ticket_reminders_sanitize_log_context(is_array($context) ? $context : []),
    ];

    if ( $event_id ) {
        $log = get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_LOG, true);
        if ( ! is_array($log) ) {
            $log = [];
        }
        $log[] = $entry;
        if ( count($log) > 500 ) {
            $log = array_slice($log, -500);
        }
        update_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_LOG, $log);
    }

    if ( defined('WP_DEBUG') && WP_DEBUG ) {
        error_log('EVENTOSAPP TICKET REMINDERS | ' . strtoupper($level) . ' | ' . $entry['message'] . ' | ' . wp_json_encode($entry['context']));
    }
}

function eventosapp_ticket_reminders_get_filter_fields($event_id = 0) {
    $fields = [
        'nombre'           => 'Nombre',
        'apellido'         => 'Apellido',
        'cedula'           => 'Cédula',
        'email'            => 'Correo electrónico',
        'telefono'         => 'Celular / WhatsApp',
        'empresa'          => 'Empresa',
        'nit'              => 'NIT',
        'cargo'            => 'Cargo',
        'ciudad'           => 'Ciudad',
        'pais'             => 'País',
        'localidad'        => 'Localidad',
        'modalidad'        => 'Modalidad del ticket',
        'creation_channel' => 'Canal de creación del ticket',
        'estado_pago'      => 'Estado de pago',
        'checkin'                  => 'Check-in presencial',
        'checkin_virtual'          => 'Check-in virtual',
        'whatsapp_sent_status'     => 'WhatsApp enviado / no enviado',
        'whatsapp_last_status'     => 'WhatsApp último estado local/API',
        'whatsapp_delivery_status' => 'WhatsApp estado webhook',
    ];

    $event_id = absint($event_id);

    if ( $event_id && function_exists('eventosapp_get_event_extra_fields') ) {
        $extra_fields = eventosapp_get_event_extra_fields($event_id);
        if ( is_array($extra_fields) ) {
            foreach ( $extra_fields as $extra ) {
                if ( empty($extra['key']) ) {
                    continue;
                }
                $key = sanitize_key($extra['key']);
                if ( $key === '' ) {
                    continue;
                }
                $label = ! empty($extra['label']) ? sanitize_text_field($extra['label']) : $key;
                $fields['extra:' . $key] = 'Campo adicional: ' . $label;
            }
        }
    }

    if ( $event_id ) {
        $sample_tickets = get_posts([
            'post_type'      => 'eventosapp_ticket',
            'post_status'    => ['publish', 'pending', 'draft', 'private'],
            'posts_per_page' => 50,
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'     => '_eventosapp_ticket_evento_id',
                'value'   => $event_id,
                'compare' => '=',
            ]],
        ]);

        foreach ( $sample_tickets as $ticket_id ) {
            $all_meta = get_post_meta($ticket_id);
            foreach ( $all_meta as $meta_key => $unused ) {
                if ( strpos($meta_key, '_eventosapp_extra_') !== 0 ) {
                    continue;
                }
                $extra_key = sanitize_key(substr($meta_key, strlen('_eventosapp_extra_')));
                if ( $extra_key === '' ) {
                    continue;
                }
                $field_key = 'extra:' . $extra_key;
                if ( ! isset($fields[$field_key]) ) {
                    $fields[$field_key] = 'Campo adicional: ' . $extra_key;
                }
            }
        }
    }

    return apply_filters('eventosapp_ticket_reminders_filter_fields', $fields, $event_id);
}

function eventosapp_ticket_reminders_get_filter_operators() {
    return [
        'equals'       => 'Es igual a',
        'not_equals'   => 'No es igual a',
        'contains'     => 'Contiene',
        'not_contains' => 'No contiene',
        'starts_with'  => 'Empieza por',
        'ends_with'    => 'Termina en',
        'empty'        => 'Está vacío',
        'not_empty'    => 'No está vacío',
    ];
}

function eventosapp_ticket_reminders_normalize_channels($channels = []) {
    return ['whatsapp' => '1'];
}

function eventosapp_ticket_reminders_normalize_items($items) {
    if ( ! is_array($items) ) {
        return [];
    }

    $clean = [];
    foreach ( $items as $item ) {
        if ( ! is_array($item) ) {
            continue;
        }

        $id = isset($item['id']) ? sanitize_key(wp_unslash($item['id'])) : '';
        if ( $id === '' ) {
            $id = 'rem_' . substr(md5(wp_json_encode($item) . microtime(true) . wp_rand()), 0, 12);
        }

        $schedule_type = isset($item['schedule_type']) ? sanitize_key(wp_unslash($item['schedule_type'])) : 'relative';
        if ( ! in_array($schedule_type, ['exact', 'relative'], true) ) {
            $schedule_type = 'relative';
        }

        $exact_datetime = isset($item['exact_datetime']) ? sanitize_text_field(wp_unslash($item['exact_datetime'])) : '';
        $exact_datetime = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $exact_datetime) ? $exact_datetime : '';

        $days    = isset($item['relative_days']) ? absint($item['relative_days']) : 0;
        $hours   = isset($item['relative_hours']) ? absint($item['relative_hours']) : 0;
        $minutes = isset($item['relative_minutes']) ? absint($item['relative_minutes']) : 0;

        if ( $schedule_type === 'relative' && $days === 0 && $hours === 0 && $minutes === 0 ) {
            $hours = 24;
        }

        $clean[] = [
            'id'               => $id,
            'enabled'          => isset($item['enabled']) ? '1' : '0',
            'name'             => isset($item['name']) ? sanitize_text_field(wp_unslash($item['name'])) : '',
            'channels'         => ['whatsapp' => '1'],
            'schedule_type'    => $schedule_type,
            'exact_datetime'   => $exact_datetime,
            'relative_days'    => $days,
            'relative_hours'   => $hours,
            'relative_minutes' => $minutes,
        ];
    }

    return $clean;
}

function eventosapp_ticket_reminders_normalize_rules($rules) {
    if ( ! is_array($rules) ) {
        return [];
    }

    $operators = eventosapp_ticket_reminders_get_filter_operators();
    $clean = [];

    foreach ( $rules as $rule ) {
        if ( ! is_array($rule) ) {
            continue;
        }

        $conditions = [];
        if ( ! empty($rule['conditions']) && is_array($rule['conditions']) ) {
            foreach ( $rule['conditions'] as $condition ) {
                if ( ! is_array($condition) ) {
                    continue;
                }
                $field = isset($condition['field']) ? sanitize_text_field(wp_unslash($condition['field'])) : '';
                $operator = isset($condition['operator']) ? sanitize_key(wp_unslash($condition['operator'])) : 'equals';
                $value = isset($condition['value']) ? sanitize_text_field(wp_unslash($condition['value'])) : '';

                if ( $field === '' ) {
                    continue;
                }
                if ( ! isset($operators[$operator]) ) {
                    $operator = 'equals';
                }

                $conditions[] = [
                    'field'    => $field,
                    'operator' => $operator,
                    'value'    => $value,
                ];
            }
        }

        $action = isset($rule['action']) ? sanitize_key(wp_unslash($rule['action'])) : 'allow';
        if ( ! in_array($action, ['allow', 'deny'], true) ) {
            $action = 'allow';
        }

        $match = isset($rule['match']) ? sanitize_key(wp_unslash($rule['match'])) : 'all';
        if ( ! in_array($match, ['all', 'any'], true) ) {
            $match = 'all';
        }

        $clean[] = [
            'enabled'    => isset($rule['enabled']) ? '1' : '0',
            'name'       => isset($rule['name']) ? sanitize_text_field(wp_unslash($rule['name'])) : '',
            'action'     => $action,
            'match'      => $match,
            'conditions' => $conditions,
        ];
    }

    return $clean;
}


function eventosapp_ticket_reminders_get_schedule_diagnostics($event_id, $items) {
    $event_id = absint($event_id);
    $items = is_array($items) ? $items : [];
    $rows = [];
    $now = time();
    $executed = get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_EXECUTED, true);
    $executed = is_array($executed) ? $executed : [];

    foreach ( $items as $item ) {
        $reminder_id = sanitize_key($item['id'] ?? '');
        $name = ! empty($item['name']) ? sanitize_text_field((string)$item['name']) : ($reminder_id !== '' ? $reminder_id : 'Recordatorio sin nombre');
        $enabled = ($item['enabled'] ?? '0') === '1';
        $run_at = $enabled ? eventosapp_ticket_reminders_calculate_run_timestamp($event_id, $item) : 0;
        $next_cron = ($enabled && $reminder_id !== '') ? wp_next_scheduled(EVENTOSAPP_TICKET_REMINDERS_CRON_HOOK, [$event_id, $reminder_id]) : false;
        $signature = ($enabled && $run_at > 0) ? eventosapp_ticket_reminders_item_signature($event_id, $item) : '';
        $executed_row = ($reminder_id !== '' && isset($executed[$reminder_id]) && is_array($executed[$reminder_id])) ? $executed[$reminder_id] : [];
        $executed_same_schedule = ! empty($executed_row['signature']) && $signature !== '' && hash_equals((string)$executed_row['signature'], (string)$signature);

        if ( ! $enabled ) {
            $status = 'Inactivo';
            $detail = 'No se programará mientras esté desactivado.';
        } elseif ( ! $run_at ) {
            $status = 'Fecha inválida';
            $detail = 'Revisa fecha/hora del evento o la fecha exacta del recordatorio.';
        } elseif ( $next_cron ) {
            $status = 'Programado en WP-Cron';
            $detail = 'WordPress tiene una tarea pendiente para este recordatorio.';
        } elseif ( $executed_same_schedule ) {
            $status = 'Ejecutado';
            $detail = 'Ya se ejecutó con esta misma programación.';
        } elseif ( $run_at <= $now ) {
            $status = 'Vencido sin tarea pendiente';
            $detail = 'La hora calculada ya pasó y no hay una tarea WP-Cron pendiente. Guarda/actualiza el evento para resincronizar.';
        } else {
            $status = 'No programado';
            $detail = 'La hora está en el futuro, pero no hay tarea WP-Cron pendiente. Guarda/actualiza el evento para resincronizar.';
        }

        $rows[] = [
            'id' => $reminder_id,
            'name' => $name,
            'status' => $status,
            'detail' => $detail,
            'run_at_event_timezone' => $run_at ? eventosapp_ticket_reminders_format_timestamp_for_event($event_id, $run_at) : '',
            'run_at_utc' => $run_at ? gmdate('Y-m-d H:i:s', $run_at) : '',
            'next_cron_event_timezone' => $next_cron ? eventosapp_ticket_reminders_format_timestamp_for_event($event_id, $next_cron) : '',
            'next_cron_utc' => $next_cron ? gmdate('Y-m-d H:i:s', $next_cron) : '',
            'last_execution' => ! empty($executed_row['date']) ? sanitize_text_field((string)$executed_row['date']) : '',
        ];
    }

    return $rows;
}

add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_ticket_reminders',
        'Recordatorios de Ticket por WhatsApp',
        'eventosapp_ticket_reminders_render_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
});

function eventosapp_ticket_reminders_render_metabox($post) {
    $enabled   = get_post_meta($post->ID, EVENTOSAPP_TICKET_REMINDERS_META_ENABLED, true) === '1' ? '1' : '0';
    $items     = eventosapp_ticket_reminders_normalize_items(get_post_meta($post->ID, EVENTOSAPP_TICKET_REMINDERS_META_ITEMS, true));
    $rules     = eventosapp_ticket_reminders_normalize_rules(get_post_meta($post->ID, EVENTOSAPP_TICKET_REMINDERS_META_RULES, true));
    $fields    = eventosapp_ticket_reminders_get_filter_fields($post->ID);
    $operators = eventosapp_ticket_reminders_get_filter_operators();
    $last_run  = get_post_meta($post->ID, EVENTOSAPP_TICKET_REMINDERS_META_LAST_RUN, true);
    $log       = get_post_meta($post->ID, EVENTOSAPP_TICKET_REMINDERS_META_LOG, true);
    $event_start = eventosapp_ticket_reminders_get_event_start_info($post->ID);
    $whatsapp_enabled = get_post_meta($post->ID, '_eventosapp_ticket_whatsapp_enabled', true) === '1';
    $schedule_diagnostics = eventosapp_ticket_reminders_get_schedule_diagnostics($post->ID, $items);

    if ( ! is_array($log) ) {
        $log = [];
    }

    wp_nonce_field('eventosapp_ticket_reminders_save', 'eventosapp_ticket_reminders_nonce');
    ?>
    <style>
        .evapp-reminders-box{border:1px solid #dcdcde;background:#fff;border-radius:10px;padding:14px;margin:10px 0;}
        .evapp-reminders-help{font-size:12px;color:#646970;margin:6px 0 0;line-height:1.45;}
        .evapp-reminders-empty{padding:12px;background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:8px;color:#50575e;}
        .evapp-reminders-warning{padding:10px 12px;background:#fff8e5;border:1px solid #f0c36d;border-left:4px solid #d63638;border-radius:8px;margin:10px 0;color:#3c434a;}
        .evapp-reminder-row{border:1px solid #ccd0d4;border-left:4px solid #22c55e;background:#fafafa;border-radius:10px;padding:14px;margin:14px 0;}
        .evapp-reminder-grid{display:grid;grid-template-columns:minmax(160px,1fr) minmax(190px,1fr);gap:12px;align-items:start;}
        .evapp-reminder-field{display:flex;flex-direction:column;gap:5px;margin-bottom:10px;}
        .evapp-reminder-field input[type="text"],.evapp-reminder-field input[type="datetime-local"],.evapp-reminder-field input[type="number"],.evapp-reminder-field select{width:100%;max-width:100%;}
        .evapp-reminder-checks{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:4px;}
        .evapp-reminder-channel-badge{display:inline-flex;align-items:center;gap:6px;background:#ecfdf5;border:1px solid #86efac;color:#166534;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:600;}
        .evapp-relative-grid{display:grid;grid-template-columns:repeat(3,minmax(90px,1fr));gap:8px;}
        .evapp-reminder-actions{display:flex;justify-content:flex-end;margin-top:6px;}
        .evapp-reminder-rules .evapp-reminder-rule{border:1px solid #ccd0d4;border-left:4px solid #f59e0b;border-radius:10px;background:#fff;margin:14px 0;padding:14px;}
        .evapp-reminder-rule-head{display:grid;grid-template-columns:90px minmax(160px,1fr) 160px 180px auto;gap:10px;align-items:end;margin-bottom:12px;}
        .evapp-reminder-rule-head input[type="text"],.evapp-reminder-rule-head select{width:100%;}
        .evapp-reminder-conditions-table{width:100%;border-collapse:collapse;background:#fff;}
        .evapp-reminder-conditions-table th,.evapp-reminder-conditions-table td{padding:8px;border-bottom:1px solid #eee;text-align:left;vertical-align:top;}
        .evapp-reminder-conditions-table select,.evapp-reminder-conditions-table input{width:100%;max-width:100%;}
        .evapp-reminder-status{display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:10px;margin:10px 0;}
        .evapp-reminder-status-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:12px;color:#475569;line-height:1.45;}
        .evapp-reminder-diagnostics-table{width:100%;border-collapse:collapse;background:#fff;margin:8px 0 14px;border:1px solid #dcdcde;}
        .evapp-reminder-diagnostics-table th,.evapp-reminder-diagnostics-table td{padding:8px;border-bottom:1px solid #eee;text-align:left;vertical-align:top;font-size:12px;}
        .evapp-reminder-diagnostics-table th{background:#f6f7f7;color:#1d2327;font-weight:700;}
        .evapp-reminder-diagnostics-table code{white-space:normal;word-break:break-word;}
        .evapp-reminder-log-wrap{max-height:360px;overflow:auto;background:#111827;border-radius:8px;padding:0;border:1px solid #111827;}
        .evapp-reminder-log-table{width:100%;border-collapse:collapse;color:#e5e7eb;font-size:12px;line-height:1.35;}
        .evapp-reminder-log-table th{position:sticky;top:0;background:#0f172a;color:#cbd5e1;text-align:left;padding:8px;border-bottom:1px solid rgba(255,255,255,.12);}
        .evapp-reminder-log-table td{vertical-align:top;padding:8px;border-bottom:1px solid rgba(255,255,255,.08);}
        .evapp-reminder-log-table code{color:#bfdbfe;background:transparent;padding:0;white-space:normal;word-break:break-word;}
        .evapp-reminder-level-success{color:#86efac;font-weight:700;}
        .evapp-reminder-level-error{color:#fca5a5;font-weight:700;}
        .evapp-reminder-level-warning{color:#fde68a;font-weight:700;}
        .evapp-reminder-level-info,.evapp-reminder-level-debug{color:#bfdbfe;font-weight:700;}
        @media (max-width: 980px){
            .evapp-reminder-grid,.evapp-reminder-rule-head,.evapp-reminder-status{grid-template-columns:1fr;}
            .evapp-relative-grid{grid-template-columns:1fr;}
            .evapp-reminder-conditions-table,.evapp-reminder-conditions-table thead,.evapp-reminder-conditions-table tbody,.evapp-reminder-conditions-table th,.evapp-reminder-conditions-table td,.evapp-reminder-conditions-table tr{display:block;width:100%;}
            .evapp-reminder-conditions-table thead{display:none;}
            .evapp-reminder-conditions-table td{border-bottom:0;padding:6px 0;}
            .evapp-reminder-conditions-table tr{border-bottom:1px solid #eee;padding:8px 0;}
        }
    </style>

    <div class="evapp-reminders-box">
        <label style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
            <input type="checkbox" name="eventosapp_ticket_reminders_enabled" value="1" <?php checked($enabled, '1'); ?>>
            <strong>Activar recordatorios programados por WhatsApp para este evento</strong>
        </label>
        <p class="evapp-reminders-help">
            Este metabox envía solamente recordatorios por WhatsApp. La programación se calcula con la zona horaria configurada en el evento, no con la hora UTC del servidor.
        </p>

        <?php if ( ! $whatsapp_enabled ) : ?>
            <div class="evapp-reminders-warning">
                <strong>WhatsApp no está activo en este evento.</strong><br>
                Activa “Mensajería de WhatsApp para tickets” en el metabox “Funciones Extra del Ticket”; de lo contrario los recordatorios quedarán omitidos.
            </div>
        <?php endif; ?>

        <div class="evapp-reminder-status">
            <div class="evapp-reminder-status-card">
                <strong>Referencia del evento</strong><br>
                <?php if ( ! empty($event_start['local_label']) ) : ?>
                    Hora del evento: <?php echo esc_html($event_start['local_label']); ?><br>
                    Zona horaria del evento: <?php echo esc_html($event_start['timezone']); ?><br>
                    UTC equivalente: <?php echo esc_html($event_start['utc_label']); ?>
                <?php else : ?>
                    Falta configurar fecha y hora de inicio del evento.
                <?php endif; ?>
            </div>
            <div class="evapp-reminder-status-card">
                <strong>Última ejecución</strong><br>
                <?php if ( is_array($last_run) && ! empty($last_run['date']) ) : ?>
                    <?php echo esc_html($last_run['date']); ?><br>
                    WhatsApp enviados: <?php echo esc_html($last_run['whatsapp_sent'] ?? 0); ?> · Omitidos: <?php echo esc_html($last_run['skipped_total'] ?? 0); ?> · Errores: <?php echo esc_html($last_run['error_total'] ?? 0); ?>
                <?php else : ?>
                    Sin ejecuciones registradas.
                <?php endif; ?>
            </div>
        </div>

        <h4>Diagnóstico de programación WP-Cron</h4>
        <p class="evapp-reminders-help">
            Esta tabla confirma la hora calculada del recordatorio, la tarea pendiente de WordPress y la última ejecución registrada. Si aparece "Vencido sin tarea pendiente" o "No programado", guarda/actualiza el evento para resincronizar la programación.
        </p>
        <?php if ( empty($schedule_diagnostics) ) : ?>
            <p class="evapp-reminders-empty">No hay recordatorios para diagnosticar.</p>
        <?php else : ?>
            <table class="evapp-reminder-diagnostics-table">
                <thead>
                    <tr>
                        <th>Recordatorio</th>
                        <th>Estado</th>
                        <th>Hora calculada</th>
                        <th>Tarea WP-Cron</th>
                        <th>Última ejecución</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $schedule_diagnostics as $diag ) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($diag['name']); ?></strong><br>
                                <?php if ( ! empty($diag['id']) ) : ?>
                                    <code><?php echo esc_html($diag['id']); ?></code>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html($diag['status']); ?></strong><br>
                                <?php echo esc_html($diag['detail']); ?>
                            </td>
                            <td>
                                <?php if ( ! empty($diag['run_at_event_timezone']) ) : ?>
                                    <?php echo esc_html($diag['run_at_event_timezone']); ?><br>
                                    <code>UTC: <?php echo esc_html($diag['run_at_utc']); ?></code>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( ! empty($diag['next_cron_event_timezone']) ) : ?>
                                    <?php echo esc_html($diag['next_cron_event_timezone']); ?><br>
                                    <code>UTC: <?php echo esc_html($diag['next_cron_utc']); ?></code>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?php echo ! empty($diag['last_execution']) ? esc_html($diag['last_execution']) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h4>Programación</h4>
        <div id="evapp-ticket-reminders-list">
            <?php if ( empty($items) ) : ?>
                <p class="evapp-reminders-empty" id="evapp-ticket-reminders-empty">No hay recordatorios configurados.</p>
            <?php else : ?>
                <?php foreach ( $items as $index => $item ) : ?>
                    <?php eventosapp_ticket_reminders_render_item_row($index, $item, $post->ID); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <p><button type="button" class="button button-secondary" id="evapp-ticket-reminders-add">+ Agregar recordatorio</button></p>

        <hr>
        <h4>Filtros de destinatarios</h4>
        <p class="evapp-reminders-help">
            Las reglas <strong>No enviar</strong> tienen prioridad sobre las reglas <strong>Enviar</strong>. Si no creas reglas, el recordatorio se enviará a todos los tickets del evento que tengan un celular válido para WhatsApp.
            Para modalidad usa <code>presencial</code> o <code>virtual</code>. Para canal de creación usa <code>manual</code>, <code>frontend</code>, <code>public</code>, <code>webhook</code> o <code>import</code>. Para enviar solo a tickets sin envío aceptado por Meta usa el campo <code>WhatsApp enviado / no enviado</code>, operador <code>Es igual a</code> y valor <code>no_enviado</code>.
        </p>

        <div class="evapp-reminder-rules" id="evapp-ticket-reminder-rules-list">
            <?php if ( empty($rules) ) : ?>
                <p class="evapp-reminders-empty" id="evapp-ticket-reminder-rules-empty">No hay filtros configurados. Se enviará a todos los asistentes válidos.</p>
            <?php else : ?>
                <?php foreach ( $rules as $rule_index => $rule ) : ?>
                    <?php eventosapp_ticket_reminders_render_rule_row($rule_index, $rule, $fields, $operators); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <p><button type="button" class="button button-secondary" id="evapp-ticket-reminder-add-rule">+ Agregar filtro</button></p>

        <hr>
        <h4>Log detallado de actividad</h4>
        <p class="evapp-reminders-help">Aquí puedes consultar la programación, inicio de ejecución, tickets omitidos, errores y envíos aceptados por Meta.</p>
        <?php eventosapp_ticket_reminders_render_log_table($log); ?>
    </div>

    <script type="text/html" id="tmpl-evapp-ticket-reminder-item">
        <?php eventosapp_ticket_reminders_render_item_row('__REMINDER_INDEX__', [
            'id'               => '',
            'enabled'          => '1',
            'name'             => '',
            'channels'         => ['whatsapp' => '1'],
            'schedule_type'    => 'relative',
            'exact_datetime'   => '',
            'relative_days'    => 1,
            'relative_hours'   => 0,
            'relative_minutes' => 0,
        ], $post->ID); ?>
    </script>

    <script type="text/html" id="tmpl-evapp-ticket-reminder-rule">
        <?php eventosapp_ticket_reminders_render_rule_row('__RULE_INDEX__', [
            'enabled'    => '1',
            'name'       => '',
            'action'     => 'allow',
            'match'      => 'all',
            'conditions' => [[
                'field'    => 'localidad',
                'operator' => 'equals',
                'value'    => '',
            ]],
        ], $fields, $operators); ?>
    </script>

    <script type="text/html" id="tmpl-evapp-ticket-reminder-condition">
        <?php eventosapp_ticket_reminders_render_condition_row('__RULE_INDEX__', '__COND_INDEX__', [
            'field'    => 'localidad',
            'operator' => 'equals',
            'value'    => '',
        ], $fields, $operators); ?>
    </script>

    <script>
    jQuery(function($){
        var reminderIndex = <?php echo (int) max(1, count($items)); ?>;
        var ruleIndex = <?php echo (int) max(1, count($rules)); ?>;

        function replaceTokens(html, map){
            Object.keys(map).forEach(function(key){
                html = html.split(key).join(map[key]);
            });
            return html;
        }

        function toggleScheduleBlocks($row){
            var type = $row.find('.evapp-reminder-schedule-type').val();
            $row.find('.evapp-reminder-exact-block').toggle(type === 'exact');
            $row.find('.evapp-reminder-relative-block').toggle(type !== 'exact');
        }

        $('#evapp-ticket-reminders-list .evapp-reminder-row').each(function(){
            toggleScheduleBlocks($(this));
        });

        $('#evapp-ticket-reminders-add').on('click', function(){
            $('#evapp-ticket-reminders-empty').remove();
            var html = $('#tmpl-evapp-ticket-reminder-item').html();
            html = replaceTokens(html, {'__REMINDER_INDEX__': reminderIndex});
            $('#evapp-ticket-reminders-list').append(html);
            toggleScheduleBlocks($('#evapp-ticket-reminders-list .evapp-reminder-row').last());
            reminderIndex++;
        });

        $(document).on('click', '.evapp-reminder-remove', function(){
            $(this).closest('.evapp-reminder-row').remove();
            if ($('#evapp-ticket-reminders-list .evapp-reminder-row').length === 0) {
                $('#evapp-ticket-reminders-list').append('<p class="evapp-reminders-empty" id="evapp-ticket-reminders-empty">No hay recordatorios configurados.</p>');
            }
        });

        $(document).on('change', '.evapp-reminder-schedule-type', function(){
            toggleScheduleBlocks($(this).closest('.evapp-reminder-row'));
        });

        $('#evapp-ticket-reminder-add-rule').on('click', function(){
            $('#evapp-ticket-reminder-rules-empty').remove();
            var html = $('#tmpl-evapp-ticket-reminder-rule').html();
            html = replaceTokens(html, {'__RULE_INDEX__': ruleIndex});
            $('#evapp-ticket-reminder-rules-list').append(html);
            ruleIndex++;
        });

        $(document).on('click', '.evapp-reminder-remove-rule', function(){
            $(this).closest('.evapp-reminder-rule').remove();
            if ($('#evapp-ticket-reminder-rules-list .evapp-reminder-rule').length === 0) {
                $('#evapp-ticket-reminder-rules-list').append('<p class="evapp-reminders-empty" id="evapp-ticket-reminder-rules-empty">No hay filtros configurados. Se enviará a todos los asistentes válidos.</p>');
            }
        });

        $(document).on('click', '.evapp-reminder-add-condition', function(){
            var $rule = $(this).closest('.evapp-reminder-rule');
            var rIdx = $rule.data('rule-index');
            var cIdx = $rule.find('tbody tr').length;
            var html = $('#tmpl-evapp-ticket-reminder-condition').html();
            html = replaceTokens(html, {'__RULE_INDEX__': rIdx, '__COND_INDEX__': cIdx});
            $rule.find('tbody').append(html);
        });

        $(document).on('click', '.evapp-reminder-remove-condition', function(){
            $(this).closest('tr').remove();
        });
    });
    </script>
    <?php
}

function eventosapp_ticket_reminders_render_log_table($log) {
    if ( ! is_array($log) || empty($log) ) {
        echo '<p class="evapp-reminders-empty">Todavía no hay actividades registradas.</p>';
        return;
    }

    $rows = array_reverse(array_slice($log, -120));
    ?>
    <div class="evapp-reminder-log-wrap">
        <table class="evapp-reminder-log-table">
            <thead>
                <tr>
                    <th style="width:140px;">Fecha</th>
                    <th style="width:90px;">Nivel</th>
                    <th>Actividad</th>
                    <th>Detalle</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $entry ) : ?>
                    <?php
                    $level = sanitize_key($entry['level'] ?? 'info');
                    $context = isset($entry['context']) && is_array($entry['context']) ? $entry['context'] : [];
                    ?>
                    <tr>
                        <td><?php echo esc_html($entry['date'] ?? ''); ?></td>
                        <td><span class="evapp-reminder-level-<?php echo esc_attr($level); ?>"><?php echo esc_html(strtoupper($level)); ?></span></td>
                        <td><?php echo esc_html($entry['message'] ?? ''); ?></td>
                        <td><?php echo ! empty($context) ? '<code>' . esc_html(wp_json_encode($context)) . '</code>' : ''; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function eventosapp_ticket_reminders_render_item_row($index, $item, $event_id) {
    $id = ! empty($item['id']) ? sanitize_key($item['id']) : '';
    $scheduled_label = $id ? eventosapp_ticket_reminders_get_scheduled_label($event_id, $id) : '';
    ?>
    <div class="evapp-reminder-row" data-reminder-index="<?php echo esc_attr($index); ?>">
        <input type="hidden" name="eventosapp_ticket_reminders[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($id); ?>">
        <input type="hidden" name="eventosapp_ticket_reminders[<?php echo esc_attr($index); ?>][channels][whatsapp]" value="1">
        <div class="evapp-reminder-grid">
            <div>
                <label class="evapp-reminder-field">
                    <span><strong>Nombre del recordatorio</strong></span>
                    <input type="text" name="eventosapp_ticket_reminders[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($item['name'] ?? ''); ?>" placeholder="Ej: 24 horas antes">
                </label>
                <div class="evapp-reminder-checks">
                    <label><input type="checkbox" name="eventosapp_ticket_reminders[<?php echo esc_attr($index); ?>][enabled]" value="1" <?php checked(($item['enabled'] ?? '0'), '1'); ?>> Activo</label>
                    <span class="evapp-reminder-channel-badge">Canal: WhatsApp</span>
                </div>
                <?php if ( $scheduled_label ) : ?>
                    <p class="evapp-reminders-help"><strong>Próxima ejecución:</strong> <?php echo esc_html($scheduled_label); ?></p>
                <?php endif; ?>
            </div>
            <div>
                <label class="evapp-reminder-field">
                    <span><strong>Tipo de programación</strong></span>
                    <select class="evapp-reminder-schedule-type" name="eventosapp_ticket_reminders[<?php echo esc_attr($index); ?>][schedule_type]">
                        <option value="relative" <?php selected(($item['schedule_type'] ?? 'relative'), 'relative'); ?>>Antes de la hora del evento</option>
                        <option value="exact" <?php selected(($item['schedule_type'] ?? 'relative'), 'exact'); ?>>Fecha y hora exacta</option>
                    </select>
                </label>
                <div class="evapp-reminder-exact-block">
                    <label class="evapp-reminder-field">
                        <span><strong>Fecha y hora exacta</strong></span>
                        <input type="datetime-local" name="eventosapp_ticket_reminders[<?php echo esc_attr($index); ?>][exact_datetime]" value="<?php echo esc_attr($item['exact_datetime'] ?? ''); ?>">
                    </label>
                    <p class="evapp-reminders-help">La fecha exacta se interpreta en la zona horaria del evento.</p>
                </div>
                <div class="evapp-reminder-relative-block">
                    <div class="evapp-relative-grid">
                        <label class="evapp-reminder-field">
                            <span>Días antes</span>
                            <input type="number" min="0" step="1" name="eventosapp_ticket_reminders[<?php echo esc_attr($index); ?>][relative_days]" value="<?php echo esc_attr(absint($item['relative_days'] ?? 0)); ?>">
                        </label>
                        <label class="evapp-reminder-field">
                            <span>Horas antes</span>
                            <input type="number" min="0" step="1" name="eventosapp_ticket_reminders[<?php echo esc_attr($index); ?>][relative_hours]" value="<?php echo esc_attr(absint($item['relative_hours'] ?? 0)); ?>">
                        </label>
                        <label class="evapp-reminder-field">
                            <span>Minutos antes</span>
                            <input type="number" min="0" step="1" name="eventosapp_ticket_reminders[<?php echo esc_attr($index); ?>][relative_minutes]" value="<?php echo esc_attr(absint($item['relative_minutes'] ?? 0)); ?>">
                        </label>
                    </div>
                </div>
            </div>
        </div>
        <div class="evapp-reminder-actions">
            <button type="button" class="button-link-delete evapp-reminder-remove">Eliminar recordatorio</button>
        </div>
    </div>
    <?php
}

function eventosapp_ticket_reminders_render_rule_row($rule_index, $rule, $fields, $operators) {
    $conditions = ! empty($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];
    ?>
    <div class="evapp-reminder-rule" data-rule-index="<?php echo esc_attr($rule_index); ?>">
        <div class="evapp-reminder-rule-head">
            <label>
                <span>Activo</span><br>
                <input type="checkbox" name="eventosapp_ticket_reminder_rules[<?php echo esc_attr($rule_index); ?>][enabled]" value="1" <?php checked(($rule['enabled'] ?? '0'), '1'); ?>>
            </label>
            <label>
                <span>Nombre</span><br>
                <input type="text" name="eventosapp_ticket_reminder_rules[<?php echo esc_attr($rule_index); ?>][name]" value="<?php echo esc_attr($rule['name'] ?? ''); ?>" placeholder="Ej: Solo VIP">
            </label>
            <label>
                <span>Acción</span><br>
                <select name="eventosapp_ticket_reminder_rules[<?php echo esc_attr($rule_index); ?>][action]">
                    <option value="allow" <?php selected(($rule['action'] ?? 'allow'), 'allow'); ?>>Enviar</option>
                    <option value="deny" <?php selected(($rule['action'] ?? 'allow'), 'deny'); ?>>No enviar</option>
                </select>
            </label>
            <label>
                <span>Coincidencia</span><br>
                <select name="eventosapp_ticket_reminder_rules[<?php echo esc_attr($rule_index); ?>][match]">
                    <option value="all" <?php selected(($rule['match'] ?? 'all'), 'all'); ?>>Todas las condiciones</option>
                    <option value="any" <?php selected(($rule['match'] ?? 'all'), 'any'); ?>>Cualquier condición</option>
                </select>
            </label>
            <button type="button" class="button-link-delete evapp-reminder-remove-rule">Eliminar filtro</button>
        </div>
        <table class="evapp-reminder-conditions-table">
            <thead>
                <tr><th>Campo</th><th>Operador</th><th>Valor</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ( $conditions as $condition_index => $condition ) : ?>
                    <?php eventosapp_ticket_reminders_render_condition_row($rule_index, $condition_index, $condition, $fields, $operators); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="button" class="button button-small evapp-reminder-add-condition">+ Agregar condición</button></p>
    </div>
    <?php
}

function eventosapp_ticket_reminders_render_condition_row($rule_index, $condition_index, $condition, $fields, $operators) {
    $field = $condition['field'] ?? 'localidad';
    $operator = $condition['operator'] ?? 'equals';
    $value = $condition['value'] ?? '';
    ?>
    <tr>
        <td>
            <select name="eventosapp_ticket_reminder_rules[<?php echo esc_attr($rule_index); ?>][conditions][<?php echo esc_attr($condition_index); ?>][field]">
                <?php foreach ( $fields as $key => $label ) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($field, $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <select name="eventosapp_ticket_reminder_rules[<?php echo esc_attr($rule_index); ?>][conditions][<?php echo esc_attr($condition_index); ?>][operator]">
                <?php foreach ( $operators as $key => $label ) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($operator, $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="text" name="eventosapp_ticket_reminder_rules[<?php echo esc_attr($rule_index); ?>][conditions][<?php echo esc_attr($condition_index); ?>][value]" value="<?php echo esc_attr($value); ?>" placeholder="Valor a comparar">
        </td>
        <td><button type="button" class="button-link-delete evapp-reminder-remove-condition">Eliminar</button></td>
    </tr>
    <?php
}

add_action('save_post_eventosapp_event', function($post_id, $post = null) {
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) ) return;
    if ( $post && isset($post->post_type) && $post->post_type !== 'eventosapp_event' ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;
    if ( ! isset($_POST['eventosapp_ticket_reminders_nonce']) || ! wp_verify_nonce($_POST['eventosapp_ticket_reminders_nonce'], 'eventosapp_ticket_reminders_save') ) return;

    eventosapp_ticket_reminders_clear_schedules($post_id);

    $enabled = isset($_POST['eventosapp_ticket_reminders_enabled']) ? '1' : '0';
    $items = isset($_POST['eventosapp_ticket_reminders']) && is_array($_POST['eventosapp_ticket_reminders']) ? $_POST['eventosapp_ticket_reminders'] : [];
    $rules = isset($_POST['eventosapp_ticket_reminder_rules']) && is_array($_POST['eventosapp_ticket_reminder_rules']) ? $_POST['eventosapp_ticket_reminder_rules'] : [];

    $items = eventosapp_ticket_reminders_normalize_items($items);
    $rules = eventosapp_ticket_reminders_normalize_rules($rules);

    update_post_meta($post_id, EVENTOSAPP_TICKET_REMINDERS_META_ENABLED, $enabled);
    update_post_meta($post_id, EVENTOSAPP_TICKET_REMINDERS_META_ITEMS, $items);
    update_post_meta($post_id, EVENTOSAPP_TICKET_REMINDERS_META_RULES, $rules);

    eventosapp_ticket_reminders_sync_event($post_id, 'save_post');
}, 80, 2);

function eventosapp_ticket_reminders_get_event_timezone($event_id) {
    if ( function_exists('eventosapp_get_event_timezone_object') ) {
        return eventosapp_get_event_timezone_object($event_id);
    }

    $tz_name = get_post_meta(absint($event_id), '_eventosapp_zona_horaria', true);
    if ( ! $tz_name ) {
        $tz_name = wp_timezone_string();
    }
    try {
        return new DateTimeZone($tz_name ?: 'UTC');
    } catch ( Exception $e ) {
        return wp_timezone();
    }
}

function eventosapp_ticket_reminders_normalize_date_value($date) {
    $date = trim((string)$date);
    if ( $date === '' ) {
        return '';
    }

    if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ) {
        return $date;
    }

    if ( preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $m) ) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    $timestamp = strtotime($date);
    if ( $timestamp ) {
        return gmdate('Y-m-d', $timestamp);
    }

    return '';
}

function eventosapp_ticket_reminders_parse_time_value($time) {
    $time = trim((string)$time);
    if ( $time === '' ) {
        return '00:00';
    }

    $time = str_replace(['.', '  '], ['', ' '], $time);
    $time = trim($time);

    if ( preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?$/i', $time, $m) ) {
        $hour = (int)$m[1];
        $minute = (int)$m[2];
        $ampm = isset($m[3]) ? strtoupper($m[3]) : '';

        if ( $minute < 0 || $minute > 59 ) {
            return '00:00';
        }

        if ( $ampm === 'PM' && $hour >= 1 && $hour <= 11 ) {
            $hour += 12;
        } elseif ( $ampm === 'AM' && $hour === 12 ) {
            $hour = 0;
        }

        if ( $hour < 0 || $hour > 23 ) {
            return '00:00';
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    if ( preg_match('/^(\d{1,2})\s*(AM|PM)$/i', $time, $m) ) {
        $hour = (int)$m[1];
        $ampm = strtoupper($m[2]);
        if ( $ampm === 'PM' && $hour >= 1 && $hour <= 11 ) {
            $hour += 12;
        } elseif ( $ampm === 'AM' && $hour === 12 ) {
            $hour = 0;
        }
        if ( $hour >= 0 && $hour <= 23 ) {
            return sprintf('%02d:00', $hour);
        }
    }

    return '00:00';
}

function eventosapp_ticket_reminders_get_event_days($event_id) {
    $event_id = absint($event_id);
    if ( function_exists('eventosapp_get_event_days') ) {
        $days = eventosapp_get_event_days($event_id);
        if ( is_array($days) && ! empty($days) ) {
            $normalized = [];
            foreach ( $days as $day ) {
                $date = eventosapp_ticket_reminders_normalize_date_value($day);
                if ( $date !== '' ) {
                    $normalized[] = $date;
                }
            }
            $normalized = array_values(array_unique($normalized));
            sort($normalized);
            if ( ! empty($normalized) ) {
                return $normalized;
            }
        }
    }

    $tipo = get_post_meta($event_id, '_eventosapp_tipo_fecha', true) ?: 'unica';
    $days = [];

    if ( $tipo === 'unica' ) {
        $date = eventosapp_ticket_reminders_normalize_date_value(get_post_meta($event_id, '_eventosapp_fecha_unica', true));
        if ( $date !== '' ) {
            $days[] = $date;
        }
    } elseif ( $tipo === 'consecutiva' ) {
        $start = eventosapp_ticket_reminders_normalize_date_value(get_post_meta($event_id, '_eventosapp_fecha_inicio', true));
        if ( $start !== '' ) {
            $days[] = $start;
        }
    } else {
        $dates = get_post_meta($event_id, '_eventosapp_fechas_noco', true);
        if ( is_string($dates) ) {
            $dates = preg_split('/[\r\n,]+/', $dates);
        }
        if ( is_array($dates) ) {
            foreach ( $dates as $date ) {
                $date = eventosapp_ticket_reminders_normalize_date_value($date);
                if ( $date !== '' ) {
                    $days[] = $date;
                }
            }
        }
    }

    $days = array_values(array_unique($days));
    sort($days);
    return $days;
}

function eventosapp_ticket_reminders_format_timestamp_for_event($event_id, $timestamp) {
    $timestamp = (int)$timestamp;
    if ( $timestamp <= 0 ) {
        return '';
    }

    $tz = eventosapp_ticket_reminders_get_event_timezone($event_id);
    try {
        $dt = new DateTimeImmutable('@' . $timestamp);
        $dt = $dt->setTimezone($tz);
        return $dt->format('d/m/Y H:i') . ' (' . $tz->getName() . ')';
    } catch ( Exception $e ) {
        return date_i18n('d/m/Y H:i', $timestamp);
    }
}

function eventosapp_ticket_reminders_get_event_start_info($event_id) {
    $event_id = absint($event_id);
    $days = eventosapp_ticket_reminders_get_event_days($event_id);
    $tz = eventosapp_ticket_reminders_get_event_timezone($event_id);

    if ( empty($days) ) {
        return [
            'timestamp'   => 0,
            'local_label' => '',
            'utc_label'   => '',
            'timezone'    => $tz->getName(),
            'raw_time'    => '',
        ];
    }

    $raw_time = get_post_meta($event_id, '_eventosapp_hora_inicio', true);
    $time = eventosapp_ticket_reminders_parse_time_value($raw_time);

    try {
        $dt = new DateTimeImmutable($days[0] . ' ' . $time . ':00', $tz);
    } catch ( Exception $e ) {
        return [
            'timestamp'   => 0,
            'local_label' => '',
            'utc_label'   => '',
            'timezone'    => $tz->getName(),
            'raw_time'    => (string)$raw_time,
        ];
    }

    return [
        'timestamp'   => $dt->getTimestamp(),
        'local_label' => $dt->format('d/m/Y H:i'),
        'utc_label'   => gmdate('Y-m-d H:i:s', $dt->getTimestamp()),
        'timezone'    => $tz->getName(),
        'raw_date'    => $days[0],
        'raw_time'    => (string)$raw_time,
        'parsed_time' => $time,
    ];
}

function eventosapp_ticket_reminders_calculate_run_timestamp($event_id, $item) {
    $event_id = absint($event_id);
    $tz = eventosapp_ticket_reminders_get_event_timezone($event_id);
    $type = $item['schedule_type'] ?? 'relative';

    if ( $type === 'exact' ) {
        $exact = $item['exact_datetime'] ?? '';
        if ( ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string)$exact) ) {
            return 0;
        }
        try {
            $dt = new DateTimeImmutable(str_replace('T', ' ', $exact) . ':00', $tz);
            return $dt->getTimestamp();
        } catch ( Exception $e ) {
            return 0;
        }
    }

    $start = eventosapp_ticket_reminders_get_event_start_info($event_id);
    if ( empty($start['timestamp']) ) {
        return 0;
    }

    $offset = (absint($item['relative_days'] ?? 0) * DAY_IN_SECONDS)
            + (absint($item['relative_hours'] ?? 0) * HOUR_IN_SECONDS)
            + (absint($item['relative_minutes'] ?? 0) * MINUTE_IN_SECONDS);

    return max(0, (int)$start['timestamp'] - (int)$offset);
}

function eventosapp_ticket_reminders_get_scheduled_label($event_id, $reminder_id) {
    $event_id = absint($event_id);
    $reminder_id = sanitize_key($reminder_id);
    if ( ! $event_id || $reminder_id === '' ) {
        return '';
    }

    $timestamp = wp_next_scheduled(EVENTOSAPP_TICKET_REMINDERS_CRON_HOOK, [$event_id, $reminder_id]);
    if ( ! $timestamp ) {
        return '';
    }

    return eventosapp_ticket_reminders_format_timestamp_for_event($event_id, $timestamp) . ' · UTC: ' . gmdate('Y-m-d H:i:s', $timestamp);
}

function eventosapp_ticket_reminders_clear_schedules($event_id) {
    $event_id = absint($event_id);
    if ( ! $event_id ) return;

    $keys = get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_SCHEDULED_KEYS, true);
    if ( ! is_array($keys) ) {
        $keys = [];
    }

    $items = get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_ITEMS, true);
    if ( is_array($items) ) {
        foreach ( $items as $item ) {
            if ( ! empty($item['id']) ) {
                $keys[] = sanitize_key($item['id']);
            }
        }
    }

    $keys = array_values(array_unique(array_filter(array_map('sanitize_key', $keys))));
    foreach ( $keys as $key ) {
        wp_clear_scheduled_hook(EVENTOSAPP_TICKET_REMINDERS_CRON_HOOK, [$event_id, $key]);
    }

    delete_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_SCHEDULED_KEYS);
}


function eventosapp_ticket_reminders_item_signature($event_id, $item) {
    $run_at = eventosapp_ticket_reminders_calculate_run_timestamp($event_id, $item);
    $data = [
        'run_at' => (int)$run_at,
        'schedule_type' => sanitize_key((string)($item['schedule_type'] ?? 'relative')),
        'exact_datetime' => sanitize_text_field((string)($item['exact_datetime'] ?? '')),
        'relative_days' => absint($item['relative_days'] ?? 0),
        'relative_hours' => absint($item['relative_hours'] ?? 0),
        'relative_minutes' => absint($item['relative_minutes'] ?? 0),
        'channels' => ['whatsapp' => '1'],
    ];
    return md5(wp_json_encode($data));
}

function eventosapp_ticket_reminders_get_executed_map($event_id) {
    $executed = get_post_meta(absint($event_id), EVENTOSAPP_TICKET_REMINDERS_META_EXECUTED, true);
    return is_array($executed) ? $executed : [];
}

function eventosapp_ticket_reminders_mark_executed($event_id, $reminder_id, $summary = []) {
    $event_id = absint($event_id);
    $reminder_id = sanitize_key($reminder_id);
    if ( ! $event_id || $reminder_id === '' ) {
        return;
    }

    $item = eventosapp_ticket_reminders_find_item($event_id, $reminder_id);
    $run_at = ! empty($item) ? eventosapp_ticket_reminders_calculate_run_timestamp($event_id, $item) : 0;
    $signature = ! empty($item) ? eventosapp_ticket_reminders_item_signature($event_id, $item) : '';

    $executed = eventosapp_ticket_reminders_get_executed_map($event_id);
    $executed[$reminder_id] = [
        'date' => current_time('mysql'),
        'timestamp' => time(),
        'run_at' => (int)$run_at,
        'signature' => $signature,
        'summary' => eventosapp_ticket_reminders_sanitize_log_context($summary),
    ];
    update_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_EXECUTED, $executed);
}

function eventosapp_ticket_reminders_sync_event($event_id, $reason = 'sync') {
    $event_id = absint($event_id);
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
        return;
    }

    eventosapp_ticket_reminders_clear_schedules($event_id);

    $enabled = get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_ENABLED, true) === '1';
    $items = eventosapp_ticket_reminders_normalize_items(get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_ITEMS, true));

    if ( ! $enabled || empty($items) ) {
        eventosapp_ticket_reminders_log($event_id, 'Recordatorios WhatsApp sin programación activa.', [
            'reason' => $reason,
            'enabled' => $enabled ? '1' : '0',
            'items' => count($items),
        ], 'info');
        return;
    }

    $scheduled_keys = [];
    $now = time();
    $grace_seconds = (int) apply_filters('eventosapp_ticket_reminders_due_grace_seconds', 3 * DAY_IN_SECONDS, $event_id);
    $executed = eventosapp_ticket_reminders_get_executed_map($event_id);

    foreach ( $items as $item ) {
        $reminder_id = sanitize_key($item['id'] ?? '');
        if ( $reminder_id === '' || ($item['enabled'] ?? '0') !== '1' ) {
            continue;
        }

        $run_at = eventosapp_ticket_reminders_calculate_run_timestamp($event_id, $item);
        if ( ! $run_at ) {
            eventosapp_ticket_reminders_log($event_id, 'Recordatorio omitido por fecha inválida.', [
                'reason' => $reason,
                'reminder_id' => $reminder_id,
                'name' => $item['name'] ?? '',
                'event_start' => eventosapp_ticket_reminders_get_event_start_info($event_id),
            ], 'error');
            continue;
        }

        $current_signature = eventosapp_ticket_reminders_item_signature($event_id, $item);
        $already_executed_same_schedule = isset($executed[$reminder_id])
            && is_array($executed[$reminder_id])
            && ! empty($executed[$reminder_id]['signature'])
            && hash_equals((string)$executed[$reminder_id]['signature'], (string)$current_signature);

        if ( $run_at <= $now && $already_executed_same_schedule ) {
            eventosapp_ticket_reminders_log($event_id, 'Recordatorio no reprogramado porque ya fue ejecutado con esta misma programación.', [
                'reason' => $reason,
                'reminder_id' => $reminder_id,
                'name' => $item['name'] ?? '',
                'run_at_event_timezone' => eventosapp_ticket_reminders_format_timestamp_for_event($event_id, $run_at),
                'run_at_utc' => gmdate('Y-m-d H:i:s', $run_at),
                'signature' => $current_signature,
            ], 'info');
            continue;
        }

        if ( $run_at < ($now - $grace_seconds) ) {
            eventosapp_ticket_reminders_log($event_id, 'Recordatorio omitido porque su fecha ya pasó fuera de la ventana de recuperación.', [
                'reason' => $reason,
                'reminder_id' => $reminder_id,
                'name' => $item['name'] ?? '',
                'run_at_event_timezone' => eventosapp_ticket_reminders_format_timestamp_for_event($event_id, $run_at),
                'run_at_utc' => gmdate('Y-m-d H:i:s', $run_at),
                'now_utc' => gmdate('Y-m-d H:i:s', $now),
                'grace_seconds' => $grace_seconds,
            ], 'warning');
            continue;
        }

        $schedule_at = $run_at;
        $recovered = false;
        if ( $schedule_at <= $now ) {
            $schedule_at = $now + 30;
            $recovered = true;
        }

        $scheduled = wp_schedule_single_event($schedule_at, EVENTOSAPP_TICKET_REMINDERS_CRON_HOOK, [$event_id, $reminder_id]);
        if ( $scheduled === false ) {
            eventosapp_ticket_reminders_log($event_id, 'No se pudo registrar el evento WP-Cron del recordatorio.', [
                'reason' => $reason,
                'reminder_id' => $reminder_id,
                'name' => $item['name'] ?? '',
                'schedule_at_event_timezone' => eventosapp_ticket_reminders_format_timestamp_for_event($event_id, $schedule_at),
                'schedule_at_utc' => gmdate('Y-m-d H:i:s', $schedule_at),
            ], 'error');
            continue;
        }

        $scheduled_keys[] = $reminder_id;
        eventosapp_ticket_reminders_log($event_id, $recovered ? 'Recordatorio vencido recuperado y programado para ejecución inmediata.' : 'Recordatorio programado.', [
            'reason' => $reason,
            'reminder_id' => $reminder_id,
            'name' => $item['name'] ?? '',
            'run_at_event_timezone' => eventosapp_ticket_reminders_format_timestamp_for_event($event_id, $run_at),
            'run_at_utc' => gmdate('Y-m-d H:i:s', $run_at),
            'scheduled_for_event_timezone' => eventosapp_ticket_reminders_format_timestamp_for_event($event_id, $schedule_at),
            'scheduled_for_utc' => gmdate('Y-m-d H:i:s', $schedule_at),
            'event_start' => eventosapp_ticket_reminders_get_event_start_info($event_id),
        ], 'success');
    }

    update_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_SCHEDULED_KEYS, array_values(array_unique($scheduled_keys)));
}

add_action(EVENTOSAPP_TICKET_REMINDERS_CRON_HOOK, 'eventosapp_ticket_reminders_run', 10, 2);

function eventosapp_ticket_reminders_find_item($event_id, $reminder_id) {
    $items = eventosapp_ticket_reminders_normalize_items(get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_ITEMS, true));
    foreach ( $items as $item ) {
        if ( sanitize_key($item['id'] ?? '') === sanitize_key($reminder_id) ) {
            return $item;
        }
    }
    return [];
}

function eventosapp_ticket_reminders_run($event_id, $reminder_id) {
    $event_id = absint($event_id);
    $reminder_id = sanitize_key($reminder_id);

    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
        return;
    }

    if ( get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_ENABLED, true) !== '1' ) {
        eventosapp_ticket_reminders_log($event_id, 'Ejecución cancelada: recordatorios desactivados.', ['reminder_id' => $reminder_id], 'warning');
        return;
    }

    $item = eventosapp_ticket_reminders_find_item($event_id, $reminder_id);
    if ( empty($item) || ($item['enabled'] ?? '0') !== '1' ) {
        eventosapp_ticket_reminders_log($event_id, 'Ejecución cancelada: recordatorio no encontrado o inactivo.', ['reminder_id' => $reminder_id], 'warning');
        return;
    }

    $rules = eventosapp_ticket_reminders_normalize_rules(get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_RULES, true));

    $summary = [
        'date'          => current_time('mysql'),
        'event_id'      => $event_id,
        'reminder_id'   => $reminder_id,
        'reminder_name' => $item['name'] ?? '',
        'sent_total'    => 0,
        'skipped_total' => 0,
        'error_total'   => 0,
        'whatsapp_sent' => 0,
        'details'       => [],
    ];

    eventosapp_ticket_reminders_log($event_id, 'Inicio de envío de recordatorio WhatsApp.', [
        'reminder_id' => $reminder_id,
        'name' => $item['name'] ?? '',
        'event_start' => eventosapp_ticket_reminders_get_event_start_info($event_id),
    ], 'info');

    $paged = 1;
    $total_tickets = 0;
    do {
        $query = new WP_Query([
            'post_type'      => 'eventosapp_ticket',
            'post_status'    => ['publish', 'pending', 'draft', 'private'],
            'posts_per_page' => 200,
            'paged'          => $paged,
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'     => '_eventosapp_ticket_evento_id',
                'value'   => $event_id,
                'compare' => '=',
            ]],
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        foreach ( $query->posts as $ticket_id ) {
            $ticket_id = absint($ticket_id);
            $total_tickets++;
            $phone_info = eventosapp_ticket_reminders_get_ticket_phone_info($ticket_id);

            $passes = eventosapp_ticket_reminders_ticket_passes_rules($ticket_id, $event_id, $rules);
            if ( empty($passes['allowed']) ) {
                $summary['skipped_total']++;
                $message = $passes['reason'] ?? 'No cumple filtros.';
                $summary['details'][] = ['ticket_id' => $ticket_id, 'channel' => 'whatsapp', 'status' => 'skipped_rules', 'message' => $message];
                eventosapp_ticket_reminders_add_ticket_history($ticket_id, $event_id, $item, 'whatsapp', 'skipped', $message);
                eventosapp_ticket_reminders_log($event_id, 'Ticket omitido por filtros del recordatorio.', [
                    'ticket_id' => $ticket_id,
                    'reminder_id' => $reminder_id,
                    'reason' => $message,
                    'phone_raw' => $phone_info['raw'],
                    'phone_normalized' => $phone_info['normalized'],
                ], 'warning');
                continue;
            }

            if ( eventosapp_ticket_reminders_ticket_already_sent($ticket_id, $reminder_id, 'whatsapp') ) {
                $summary['skipped_total']++;
                $message = 'Ya enviado previamente por WhatsApp para este recordatorio.';
                $summary['details'][] = ['ticket_id' => $ticket_id, 'channel' => 'whatsapp', 'status' => 'skipped_duplicate', 'message' => $message];
                eventosapp_ticket_reminders_log($event_id, 'Ticket omitido por duplicado.', [
                    'ticket_id' => $ticket_id,
                    'reminder_id' => $reminder_id,
                    'phone_raw' => $phone_info['raw'],
                    'phone_normalized' => $phone_info['normalized'],
                ], 'info');
                continue;
            }

            $result = eventosapp_ticket_reminders_send_whatsapp($ticket_id, $event_id, $item);
            $status = ! empty($result['ok']) ? 'sent' : 'error';
            if ( ! empty($result['skipped']) ) {
                $status = 'skipped';
            }

            eventosapp_ticket_reminders_add_ticket_history($ticket_id, $event_id, $item, 'whatsapp', $status, $result['message'] ?? '');

            if ( $status === 'sent' ) {
                $summary['sent_total']++;
                $summary['whatsapp_sent']++;
            } elseif ( $status === 'skipped' ) {
                $summary['skipped_total']++;
            } else {
                $summary['error_total']++;
            }

            $detail = [
                'ticket_id' => $ticket_id,
                'channel'   => 'whatsapp',
                'status'    => $status,
                'message'   => $result['message'] ?? '',
                'to'        => $result['to'] ?? $phone_info['normalized'],
                'http_code' => isset($result['http_code']) ? (int)$result['http_code'] : 0,
                'message_id'=> $result['message_id'] ?? '',
                'transport' => $result['transport'] ?? '',
                'template'  => $result['template_name'] ?? '',
            ];
            $summary['details'][] = $detail;

            eventosapp_ticket_reminders_log($event_id, $status === 'sent' ? 'WhatsApp de recordatorio enviado/aceptado por Meta.' : ($status === 'skipped' ? 'WhatsApp de recordatorio omitido.' : 'Error enviando WhatsApp de recordatorio.'), array_merge([
                'reminder_id' => $reminder_id,
                'phone_raw' => $phone_info['raw'],
                'phone_normalized' => $phone_info['normalized'],
            ], $detail), $status === 'sent' ? 'success' : ($status === 'skipped' ? 'warning' : 'error'));
        }

        $paged++;
    } while ( $query->max_num_pages >= $paged );

    if ( count($summary['details']) > 150 ) {
        $summary['details'] = array_slice($summary['details'], -150);
    }

    $summary['total_tickets'] = $total_tickets;
    update_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_LAST_RUN, $summary);
    eventosapp_ticket_reminders_mark_executed($event_id, $reminder_id, $summary);

    eventosapp_ticket_reminders_log($event_id, 'Recordatorio WhatsApp ejecutado.', [
        'reminder_id' => $reminder_id,
        'total_tickets' => $total_tickets,
        'sent_total' => $summary['sent_total'],
        'skipped_total' => $summary['skipped_total'],
        'error_total' => $summary['error_total'],
    ], $summary['error_total'] > 0 ? 'warning' : 'success');
}

function eventosapp_ticket_reminders_ticket_already_sent($ticket_id, $reminder_id, $channel) {
    $history = get_post_meta($ticket_id, '_eventosapp_ticket_reminder_history', true);
    if ( ! is_array($history) ) {
        return false;
    }

    foreach ( $history as $entry ) {
        if ( ! is_array($entry) ) continue;
        if ( ($entry['reminder_id'] ?? '') === $reminder_id && ($entry['channel'] ?? '') === $channel && ($entry['status'] ?? '') === 'sent' ) {
            return true;
        }
    }

    return false;
}

function eventosapp_ticket_reminders_add_ticket_history($ticket_id, $event_id, $item, $channel, $status, $message = '') {
    $ticket_id = absint($ticket_id);
    if ( ! $ticket_id ) return;

    $history = get_post_meta($ticket_id, '_eventosapp_ticket_reminder_history', true);
    if ( ! is_array($history) ) {
        $history = [];
    }

    $history[] = [
        'date'          => current_time('mysql'),
        'event_id'      => absint($event_id),
        'reminder_id'   => sanitize_key($item['id'] ?? ''),
        'reminder_name' => sanitize_text_field((string)($item['name'] ?? '')),
        'channel'       => 'whatsapp',
        'status'        => sanitize_key($status),
        'message'       => sanitize_text_field((string)$message),
    ];

    if ( count($history) > 120 ) {
        $history = array_slice($history, -120);
    }

    update_post_meta($ticket_id, '_eventosapp_ticket_reminder_history', $history);
}

function eventosapp_ticket_reminders_get_rule_field_value($ticket_id, $field) {
    $ticket_id = absint($ticket_id);
    $field = (string) $field;

    $map = [
        'nombre'      => '_eventosapp_asistente_nombre',
        'apellido'    => '_eventosapp_asistente_apellido',
        'cedula'      => '_eventosapp_asistente_cc',
        'email'       => '_eventosapp_asistente_email',
        'telefono'    => '_eventosapp_asistente_tel',
        'empresa'     => '_eventosapp_asistente_empresa',
        'nit'         => '_eventosapp_asistente_nit',
        'cargo'       => '_eventosapp_asistente_cargo',
        'ciudad'      => '_eventosapp_asistente_ciudad',
        'pais'        => '_eventosapp_asistente_pais',
        'localidad'   => '_eventosapp_asistente_localidad',
        'estado_pago' => '_eventosapp_estado_pago',
    ];

    if ( $field === 'modalidad' ) {
        return function_exists('eventosapp_get_ticket_modalidad') ? eventosapp_get_ticket_modalidad($ticket_id) : get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
    }

    if ( $field === 'creation_channel' ) {
        $channel = get_post_meta($ticket_id, '_eventosapp_creation_channel', true);
        if ( $channel === '' ) {
            $channel = get_post_meta($ticket_id, '_eventosapp_ticket_origin', true);
        }
        return sanitize_key($channel ?: 'manual');
    }

    if ( $field === 'checkin' ) {
        if ( function_exists('eventosapp_ticket_has_checkin_type') ) {
            return eventosapp_ticket_has_checkin_type($ticket_id, 'presencial') ? 'checked_in' : 'not_checked_in';
        }
        $status = get_post_meta($ticket_id, '_eventosapp_checkin_status', true);
        if ( is_array($status) ) {
            return in_array('checked_in', $status, true) ? 'checked_in' : 'not_checked_in';
        }
        return (string)$status;
    }

    if ( $field === 'checkin_virtual' ) {
        if ( function_exists('eventosapp_ticket_has_checkin_type') ) {
            return eventosapp_ticket_has_checkin_type($ticket_id, 'virtual') ? 'checked_in' : 'not_checked_in';
        }
        $status = get_post_meta($ticket_id, '_eventosapp_virtual_checkin_status', true);
        if ( is_array($status) ) {
            return in_array('checked_in', $status, true) ? 'checked_in' : 'not_checked_in';
        }
        return (string)$status;
    }

    if ( $field === 'whatsapp_sent_status' ) {
        if ( function_exists('eventosapp_whatsapp_get_send_tracking') ) {
            $tracking = eventosapp_whatsapp_get_send_tracking($ticket_id, true);
            $sent_status = is_array($tracking) && ! empty($tracking['sent_status']) ? sanitize_key((string)$tracking['sent_status']) : '';
            return $sent_status !== '' ? $sent_status : 'no_enviado';
        }

        $sent_status = sanitize_key((string)get_post_meta($ticket_id, '_eventosapp_whatsapp_sent_status', true));
        if ( $sent_status !== '' ) {
            return $sent_status;
        }

        $first_sent = get_post_meta($ticket_id, '_eventosapp_whatsapp_first_sent_at', true);
        $last_sent  = get_post_meta($ticket_id, '_eventosapp_whatsapp_last_sent_at', true);
        return ($first_sent !== '' || $last_sent !== '') ? 'enviado' : 'no_enviado';
    }

    if ( $field === 'whatsapp_last_status' ) {
        return sanitize_key((string)get_post_meta($ticket_id, '_eventosapp_whatsapp_last_status', true));
    }

    if ( $field === 'whatsapp_delivery_status' ) {
        return sanitize_key((string)get_post_meta($ticket_id, '_eventosapp_whatsapp_delivery_status', true));
    }

    if ( strpos($field, 'extra:') === 0 ) {
        $extra_key = sanitize_key(substr($field, 6));
        return get_post_meta($ticket_id, '_eventosapp_extra_' . $extra_key, true);
    }

    if ( isset($map[$field]) ) {
        return get_post_meta($ticket_id, $map[$field], true);
    }

    return apply_filters('eventosapp_ticket_reminders_filter_value', '', $ticket_id, $field);
}

function eventosapp_ticket_reminders_compare_values($actual, $operator, $expected) {
    $actual = is_scalar($actual) ? (string) $actual : '';
    $expected = is_scalar($expected) ? (string) $expected : '';

    $actual_norm = function_exists('remove_accents') ? remove_accents($actual) : $actual;
    $expected_norm = function_exists('remove_accents') ? remove_accents($expected) : $expected;

    $actual_norm = function_exists('mb_strtolower') ? mb_strtolower($actual_norm) : strtolower($actual_norm);
    $expected_norm = function_exists('mb_strtolower') ? mb_strtolower($expected_norm) : strtolower($expected_norm);

    switch ( $operator ) {
        case 'not_equals':
            return $actual_norm !== $expected_norm;
        case 'contains':
            return $expected_norm === '' ? true : strpos($actual_norm, $expected_norm) !== false;
        case 'not_contains':
            return $expected_norm === '' ? false : strpos($actual_norm, $expected_norm) === false;
        case 'starts_with':
            return $expected_norm === '' ? true : strpos($actual_norm, $expected_norm) === 0;
        case 'ends_with':
            if ( $expected_norm === '' ) return true;
            return substr($actual_norm, -strlen($expected_norm)) === $expected_norm;
        case 'empty':
            return trim($actual) === '';
        case 'not_empty':
            return trim($actual) !== '';
        case 'equals':
        default:
            return $actual_norm === $expected_norm;
    }
}

function eventosapp_ticket_reminders_rule_matches_ticket($ticket_id, $rule) {
    $conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];
    if ( empty($conditions) ) {
        return true;
    }

    $match = isset($rule['match']) && $rule['match'] === 'any' ? 'any' : 'all';
    $results = [];

    foreach ( $conditions as $condition ) {
        $field = isset($condition['field']) ? (string)$condition['field'] : '';
        $operator = isset($condition['operator']) ? (string)$condition['operator'] : 'equals';
        $expected = isset($condition['value']) ? (string)$condition['value'] : '';
        $actual = eventosapp_ticket_reminders_get_rule_field_value($ticket_id, $field);
        $results[] = eventosapp_ticket_reminders_compare_values($actual, $operator, $expected);
    }

    return $match === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
}

function eventosapp_ticket_reminders_ticket_passes_rules($ticket_id, $event_id, $rules = null) {
    if ( $rules === null ) {
        $rules = eventosapp_ticket_reminders_normalize_rules(get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_RULES, true));
    }

    if ( empty($rules) ) {
        return ['allowed' => true, 'reason' => 'Sin filtros: envío permitido.'];
    }

    $has_allow_rules = false;
    $matched_allow = false;

    foreach ( $rules as $rule_index => $rule ) {
        if ( empty($rule['enabled']) || $rule['enabled'] !== '1' ) {
            continue;
        }

        if ( ($rule['action'] ?? 'allow') === 'allow' ) {
            $has_allow_rules = true;
        }

        $matches = eventosapp_ticket_reminders_rule_matches_ticket($ticket_id, $rule);
        if ( ! $matches ) {
            continue;
        }

        if ( ($rule['action'] ?? 'allow') === 'deny' ) {
            return [
                'allowed' => false,
                'reason'  => 'Bloqueado por filtro: ' . (($rule['name'] ?? '') ?: ('Filtro #' . ((int)$rule_index + 1))),
            ];
        }

        if ( ($rule['action'] ?? 'allow') === 'allow' ) {
            $matched_allow = true;
        }
    }

    if ( $has_allow_rules && ! $matched_allow ) {
        return ['allowed' => false, 'reason' => 'No cumple ningún filtro de envío permitido.'];
    }

    return ['allowed' => true, 'reason' => $matched_allow ? 'Cumple filtro de envío.' : 'Sin filtro restrictivo aplicable.'];
}

function eventosapp_ticket_reminders_get_ticket_phone_info($ticket_id) {
    $ticket_id = absint($ticket_id);
    $raw = get_post_meta($ticket_id, '_eventosapp_asistente_tel', true);
    $normalized = '';

    if ( function_exists('eventosapp_whatsapp_get_settings') && function_exists('eventosapp_whatsapp_normalize_phone') ) {
        $settings = eventosapp_whatsapp_get_settings();
        $normalized = eventosapp_whatsapp_normalize_phone($raw, $settings['default_country_code'] ?? '');
    } else {
        $normalized = preg_replace('/\D+/', '', (string)$raw);
    }

    return [
        'raw' => sanitize_text_field((string)$raw),
        'normalized' => sanitize_text_field((string)$normalized),
    ];
}

function eventosapp_ticket_reminders_send_whatsapp($ticket_id, $event_id, $item) {
    $ticket_id = absint($ticket_id);
    $event_id = absint($event_id);
    $phone_info = eventosapp_ticket_reminders_get_ticket_phone_info($ticket_id);

    if ( ! function_exists('eventosapp_whatsapp_send_ticket') ) {
        return [
            'ok' => false,
            'message' => 'El módulo WhatsApp no está disponible.',
            'to' => $phone_info['normalized'],
        ];
    }

    if ( get_post_meta($event_id, '_eventosapp_ticket_whatsapp_enabled', true) !== '1' ) {
        return [
            'ok' => true,
            'skipped' => true,
            'message' => 'WhatsApp no está activo para este evento.',
            'to' => $phone_info['normalized'],
        ];
    }

    $source_key = 'ticket_reminder:' . $event_id . ':' . sanitize_key($item['id'] ?? '') . ':' . $ticket_id;
    $result = eventosapp_whatsapp_send_ticket($ticket_id, [
        'context'    => 'ticket_reminder',
        'source_key' => $source_key,
        'skip_rules' => true,
        'force'      => false,
    ]);

    if ( ! is_array($result) ) {
        return [
            'ok' => false,
            'message' => 'Respuesta inválida del módulo WhatsApp.',
            'to' => $phone_info['normalized'],
        ];
    }

    $result['to'] = $phone_info['normalized'];
    $result['http_code'] = isset($result['http_code']) ? (int)$result['http_code'] : (int)get_post_meta($ticket_id, '_eventosapp_whatsapp_last_http_code', true);
    $result['message_id'] = isset($result['message_id']) ? sanitize_text_field((string)$result['message_id']) : sanitize_text_field((string)get_post_meta($ticket_id, '_eventosapp_whatsapp_last_message_id', true));
    $result['transport'] = isset($result['transport']) ? sanitize_text_field((string)$result['transport']) : sanitize_text_field((string)get_post_meta($ticket_id, '_eventosapp_whatsapp_last_transport', true));
    $result['template_name'] = isset($result['template_name']) ? sanitize_text_field((string)$result['template_name']) : sanitize_text_field((string)get_post_meta($ticket_id, '_eventosapp_whatsapp_last_template_name', true));

    if ( ! empty($result['ok']) && empty($result['skipped_duplicate']) && empty($result['skipped_rules']) ) {
        $result['message'] = $result['message'] ?? 'WhatsApp de recordatorio enviado.';
        return $result;
    }

    if ( ! empty($result['skipped_duplicate']) || ! empty($result['skipped_rules']) ) {
        $result['ok'] = true;
        $result['skipped'] = true;
        $result['message'] = $result['message'] ?? 'WhatsApp omitido.';
        return $result;
    }

    $result['ok'] = false;
    $result['message'] = $result['message'] ?? 'No se pudo enviar WhatsApp.';
    return $result;
}

/**
 * Re-sincronización liviana para recuperar recordatorios si WP-Cron se retrasa
 * o si el archivo se instala después de configurar eventos.
 */
add_action('init', function() {
    if ( wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON) ) {
        return;
    }

    if ( get_transient('eventosapp_ticket_reminders_periodic_sync_lock') ) {
        return;
    }
    set_transient('eventosapp_ticket_reminders_periodic_sync_lock', 1, 15 * MINUTE_IN_SECONDS);

    $events = get_posts([
        'post_type'      => 'eventosapp_event',
        'post_status'    => ['publish', 'future', 'draft', 'pending', 'private'],
        'posts_per_page' => 50,
        'fields'         => 'ids',
        'meta_query'     => [[
            'key'     => EVENTOSAPP_TICKET_REMINDERS_META_ENABLED,
            'value'   => '1',
            'compare' => '=',
        ]],
    ]);

    foreach ( $events as $event_id ) {
        eventosapp_ticket_reminders_sync_event($event_id, 'periodic_sync');
    }
}, 30);
