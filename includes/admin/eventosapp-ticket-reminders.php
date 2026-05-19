<?php
// includes/admin/eventosapp-ticket-reminders.php
if ( ! defined('ABSPATH') ) exit;

/**
 * EventosApp - Recordatorios programados de ticket.
 *
 * Este módulo agrega un metabox por evento para programar recordatorios de ticket
 * por fecha/hora exacta o por anticipación respecto a la hora de inicio del evento.
 * Incluye filtros con varios criterios para decidir a qué asistentes se envía o no.
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
if ( ! defined('EVENTOSAPP_TICKET_REMINDERS_META_LAST_RUN') ) {
    define('EVENTOSAPP_TICKET_REMINDERS_META_LAST_RUN', '_eventosapp_ticket_reminders_last_run');
}
if ( ! defined('EVENTOSAPP_TICKET_REMINDERS_META_LOG') ) {
    define('EVENTOSAPP_TICKET_REMINDERS_META_LOG', '_eventosapp_ticket_reminders_log');
}
if ( ! defined('EVENTOSAPP_TICKET_REMINDERS_CRON_HOOK') ) {
    define('EVENTOSAPP_TICKET_REMINDERS_CRON_HOOK', 'eventosapp_ticket_reminder_cron');
}

/**
 * Log liviano para depuración y metabox.
 */
function eventosapp_ticket_reminders_log($event_id, $message, $context = []) {
    $event_id = absint($event_id);
    $entry = [
        'date'    => current_time('mysql'),
        'message' => sanitize_text_field((string) $message),
        'context' => is_array($context) ? $context : [],
    ];

    if ( $event_id ) {
        $log = get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_LOG, true);
        if ( ! is_array($log) ) {
            $log = [];
        }
        $log[] = $entry;
        if ( count($log) > 80 ) {
            $log = array_slice($log, -80);
        }
        update_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_LOG, $log);
    }

    if ( defined('WP_DEBUG') && WP_DEBUG ) {
        error_log('EVENTOSAPP TICKET REMINDERS | ' . $entry['message'] . ' | ' . wp_json_encode($entry['context']));
    }
}

/**
 * Campos disponibles para filtros de recordatorios.
 */
function eventosapp_ticket_reminders_get_filter_fields($event_id = 0) {
    $fields = [
        'nombre'           => 'Nombre',
        'apellido'         => 'Apellido',
        'cedula'           => 'Cédula',
        'email'            => 'Correo electrónico',
        'telefono'         => 'Celular',
        'empresa'          => 'Empresa',
        'nit'              => 'NIT',
        'cargo'            => 'Cargo',
        'ciudad'           => 'Ciudad',
        'pais'             => 'País',
        'localidad'        => 'Localidad',
        'modalidad'        => 'Modalidad del ticket',
        'creation_channel' => 'Canal de creación del ticket',
        'estado_pago'      => 'Estado de pago',
        'checkin'          => 'Check-in presencial',
        'checkin_virtual'  => 'Check-in virtual',
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

    // Respaldo: si el esquema de campos adicionales no está cargado, detectar metakeys reales en tickets del evento.
    if ( $event_id ) {
        $sample_tickets = get_posts([
            'post_type'      => 'eventosapp_ticket',
            'post_status'    => ['publish', 'pending', 'draft', 'private'],
            'posts_per_page' => 30,
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

function eventosapp_ticket_reminders_normalize_channels($channels) {
    $channels = is_array($channels) ? $channels : [];
    $clean = [];
    if ( ! empty($channels['email']) ) {
        $clean['email'] = '1';
    }
    if ( ! empty($channels['whatsapp']) ) {
        $clean['whatsapp'] = '1';
    }
    if ( empty($clean) ) {
        $clean['email'] = '1';
    }
    return $clean;
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
            'channels'         => eventosapp_ticket_reminders_normalize_channels($item['channels'] ?? []),
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

add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_ticket_reminders',
        'Recordatorios de Ticket',
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

    if ( ! is_array($log) ) {
        $log = [];
    }

    wp_nonce_field('eventosapp_ticket_reminders_save', 'eventosapp_ticket_reminders_nonce');
    ?>
    <style>
        .evapp-reminders-box{border:1px solid #dcdcde;background:#fff;border-radius:10px;padding:14px;margin:10px 0;}
        .evapp-reminders-help{font-size:12px;color:#646970;margin:6px 0 0;line-height:1.45;}
        .evapp-reminders-empty{padding:12px;background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:8px;color:#50575e;}
        .evapp-reminder-row{border:1px solid #ccd0d4;border-left:4px solid #2271b1;background:#fafafa;border-radius:10px;padding:14px;margin:14px 0;}
        .evapp-reminder-grid{display:grid;grid-template-columns:minmax(160px,1fr) minmax(190px,1fr);gap:12px;align-items:start;}
        .evapp-reminder-field{display:flex;flex-direction:column;gap:5px;margin-bottom:10px;}
        .evapp-reminder-field input[type="text"],.evapp-reminder-field input[type="datetime-local"],.evapp-reminder-field input[type="number"],.evapp-reminder-field select{width:100%;max-width:100%;}
        .evapp-reminder-checks{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:4px;}
        .evapp-relative-grid{display:grid;grid-template-columns:repeat(3,minmax(90px,1fr));gap:8px;}
        .evapp-reminder-actions{display:flex;justify-content:flex-end;margin-top:6px;}
        .evapp-reminder-rules .evapp-reminder-rule{border:1px solid #ccd0d4;border-left:4px solid #f59e0b;border-radius:10px;background:#fff;margin:14px 0;padding:14px;}
        .evapp-reminder-rule-head{display:grid;grid-template-columns:90px minmax(160px,1fr) 160px 170px auto;gap:10px;align-items:end;margin-bottom:12px;}
        .evapp-reminder-rule-head input[type="text"],.evapp-reminder-rule-head select{width:100%;}
        .evapp-reminder-conditions-table{width:100%;border-collapse:collapse;background:#fff;}
        .evapp-reminder-conditions-table th,.evapp-reminder-conditions-table td{padding:8px;border-bottom:1px solid #eee;text-align:left;vertical-align:top;}
        .evapp-reminder-conditions-table select,.evapp-reminder-conditions-table input{width:100%;max-width:100%;}
        .evapp-reminder-status{display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:10px;margin:10px 0;}
        .evapp-reminder-status-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:12px;color:#475569;}
        .evapp-reminder-log{max-height:180px;overflow:auto;background:#111827;color:#e5e7eb;border-radius:8px;padding:10px;font-size:12px;line-height:1.35;}
        .evapp-reminder-log div{border-bottom:1px solid rgba(255,255,255,.08);padding:5px 0;}
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
            <strong>Activar recordatorios programados para este evento</strong>
        </label>
        <p class="evapp-reminders-help">
            Puedes programar recordatorios por una fecha/hora exacta o por anticipación frente a la hora de inicio del evento. Para eventos de varias fechas se toma como referencia la primera fecha del evento.
        </p>

        <div class="evapp-reminder-status">
            <div class="evapp-reminder-status-card">
                <strong>Referencia del evento</strong><br>
                <?php if ( ! empty($event_start['local_label']) ) : ?>
                    <?php echo esc_html($event_start['local_label']); ?><br>
                    Zona horaria: <?php echo esc_html($event_start['timezone']); ?>
                <?php else : ?>
                    Falta configurar fecha y hora de inicio del evento.
                <?php endif; ?>
            </div>
            <div class="evapp-reminder-status-card">
                <strong>Última ejecución</strong><br>
                <?php if ( is_array($last_run) && ! empty($last_run['date']) ) : ?>
                    <?php echo esc_html($last_run['date']); ?><br>
                    Enviados: <?php echo esc_html($last_run['sent_total'] ?? 0); ?> · Omitidos: <?php echo esc_html($last_run['skipped_total'] ?? 0); ?> · Errores: <?php echo esc_html($last_run['error_total'] ?? 0); ?>
                <?php else : ?>
                    Sin ejecuciones registradas.
                <?php endif; ?>
            </div>
        </div>

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
            Las reglas <strong>No enviar</strong> tienen prioridad sobre las reglas <strong>Enviar</strong>. Si no creas reglas, el recordatorio se enviará a todos los tickets del evento que tengan el dato requerido por el canal seleccionado.
            Para modalidad usa <code>presencial</code> o <code>virtual</code>. Para canal de creación usa <code>manual</code>, <code>frontend</code>, <code>public</code>, <code>webhook</code> o <code>import</code>.
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

        <?php if ( ! empty($log) ) : ?>
            <hr>
            <h4>Registro reciente</h4>
            <div class="evapp-reminder-log">
                <?php foreach ( array_reverse(array_slice($log, -20)) as $entry ) : ?>
                    <div>
                        <strong><?php echo esc_html($entry['date'] ?? ''); ?></strong> — <?php echo esc_html($entry['message'] ?? ''); ?>
                        <?php if ( ! empty($entry['context']) && is_array($entry['context']) ) : ?>
                            <br><code style="color:#bfdbfe;background:transparent;padding:0;"><?php echo esc_html(wp_json_encode($entry['context'])); ?></code>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script type="text/html" id="tmpl-evapp-ticket-reminder-item">
        <?php eventosapp_ticket_reminders_render_item_row('__REMINDER_INDEX__', [
            'id'               => '',
            'enabled'          => '1',
            'name'             => '',
            'channels'         => ['email' => '1'],
            'schedule_type'    => 'relative',
            'exact_datetime'   => '',
            'relative_days'    => 1,
            'relative_hours'   => 0,
            'relative_minutes' => 0,
        ], $post->ID); ?>
    </script>

    <script type="text/html" id="tmpl-evapp-ticket-reminder-rule">
        <?php eventosapp_ticket_reminders_render_rule_row('__RULE_INDEX__', [
            'enabled' => '1',
            'name' => '',
            'action' => 'allow',
            'match' => 'all',
            'conditions' => [],
        ], $fields, $operators); ?>
    </script>

    <script type="text/html" id="tmpl-evapp-ticket-reminder-condition">
        <?php eventosapp_ticket_reminders_render_condition_row('__RULE_INDEX__', '__COND_INDEX__', [], $fields, $operators); ?>
    </script>

    <script>
    jQuery(function($){
        var reminderIndex = $('#evapp-ticket-reminders-list .evapp-reminder-row').length;
        var ruleIndex = $('#evapp-ticket-reminder-rules-list .evapp-reminder-rule').length;

        function replaceTokens(html, tokens) {
            $.each(tokens, function(token, value){
                html = html.replace(new RegExp(token, 'g'), value);
            });
            return html;
        }

        function toggleScheduleBlocks($row) {
            var mode = $row.find('.evapp-reminder-schedule-type').val();
            $row.find('.evapp-reminder-exact-block').toggle(mode === 'exact');
            $row.find('.evapp-reminder-relative-block').toggle(mode !== 'exact');
        }

        $('#evapp-ticket-reminders-list .evapp-reminder-row').each(function(){ toggleScheduleBlocks($(this)); });

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

function eventosapp_ticket_reminders_render_item_row($index, $item, $event_id) {
    $id = ! empty($item['id']) ? sanitize_key($item['id']) : '';
    $scheduled_label = $id ? eventosapp_ticket_reminders_get_scheduled_label($event_id, $id) : '';
    ?>
    <div class="evapp-reminder-row" data-reminder-index="<?php echo esc_attr($index); ?>">
        <input type="hidden" name="eventosapp_ticket_reminders[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($id); ?>">
        <div class="evapp-reminder-grid">
            <div>
                <label class="evapp-reminder-field">
                    <span><strong>Nombre del recordatorio</strong></span>
                    <input type="text" name="eventosapp_ticket_reminders[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($item['name'] ?? ''); ?>" placeholder="Ej: 24 horas antes">
                </label>
                <div class="evapp-reminder-checks">
                    <label><input type="checkbox" name="eventosapp_ticket_reminders[<?php echo esc_attr($index); ?>][enabled]" value="1" <?php checked(($item['enabled'] ?? '0'), '1'); ?>> Activo</label>
                    <label><input type="checkbox" name="eventosapp_ticket_reminders[<?php echo esc_attr($index); ?>][channels][email]" value="1" <?php checked(! empty($item['channels']['email'])); ?>> Correo</label>
                    <label><input type="checkbox" name="eventosapp_ticket_reminders[<?php echo esc_attr($index); ?>][channels][whatsapp]" value="1" <?php checked(! empty($item['channels']['whatsapp'])); ?>> WhatsApp</label>
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

function eventosapp_ticket_reminders_get_event_days($event_id) {
    $event_id = absint($event_id);
    if ( function_exists('eventosapp_get_event_days') ) {
        $days = eventosapp_get_event_days($event_id);
        if ( is_array($days) && ! empty($days) ) {
            $days = array_values(array_filter($days, function($day) {
                return is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day);
            }));
            sort($days);
            return $days;
        }
    }

    $tipo = get_post_meta($event_id, '_eventosapp_tipo_fecha', true) ?: 'unica';
    $days = [];

    if ( $tipo === 'unica' ) {
        $date = get_post_meta($event_id, '_eventosapp_fecha_unica', true);
        if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date) ) {
            $days[] = $date;
        }
    } elseif ( $tipo === 'consecutiva' ) {
        $start = get_post_meta($event_id, '_eventosapp_fecha_inicio', true);
        $end   = get_post_meta($event_id, '_eventosapp_fecha_fin', true);
        if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$end) ) {
            $days[] = $start;
        }
    } else {
        $dates = get_post_meta($event_id, '_eventosapp_fechas_noco', true);
        if ( is_string($dates) ) {
            $dates = array_filter(array_map('trim', explode(',', $dates)));
        }
        if ( is_array($dates) ) {
            foreach ( $dates as $date ) {
                if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date) ) {
                    $days[] = $date;
                }
            }
        }
    }

    $days = array_values(array_unique($days));
    sort($days);
    return $days;
}

function eventosapp_ticket_reminders_get_event_start_info($event_id) {
    $event_id = absint($event_id);
    $days = eventosapp_ticket_reminders_get_event_days($event_id);
    if ( empty($days) ) {
        return [
            'timestamp'   => 0,
            'local_label' => '',
            'timezone'    => '',
        ];
    }

    $time = get_post_meta($event_id, '_eventosapp_hora_inicio', true);
    if ( ! is_string($time) || ! preg_match('/^\d{2}:\d{2}/', $time) ) {
        $time = '00:00';
    } else {
        $time = substr($time, 0, 5);
    }

    $tz = eventosapp_ticket_reminders_get_event_timezone($event_id);
    try {
        $dt = new DateTime($days[0] . ' ' . $time . ':00', $tz);
    } catch ( Exception $e ) {
        return [
            'timestamp'   => 0,
            'local_label' => '',
            'timezone'    => $tz->getName(),
        ];
    }

    return [
        'timestamp'   => $dt->getTimestamp(),
        'local_label' => date_i18n('d/m/Y H:i', $dt->getTimestamp()),
        'timezone'    => $tz->getName(),
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
            $dt = new DateTime(str_replace('T', ' ', $exact) . ':00', $tz);
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

    return date_i18n('d/m/Y H:i', $timestamp);
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

function eventosapp_ticket_reminders_sync_event($event_id, $reason = 'sync') {
    $event_id = absint($event_id);
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
        return;
    }

    eventosapp_ticket_reminders_clear_schedules($event_id);

    $enabled = get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_ENABLED, true) === '1';
    $items = eventosapp_ticket_reminders_normalize_items(get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_ITEMS, true));

    if ( ! $enabled || empty($items) ) {
        eventosapp_ticket_reminders_log($event_id, 'Recordatorios sin programación activa.', [
            'reason' => $reason,
            'enabled' => $enabled ? '1' : '0',
            'items' => count($items),
        ]);
        return;
    }

    $scheduled_keys = [];
    $now = time();

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
            ]);
            continue;
        }

        if ( $run_at <= ($now - 60) ) {
            eventosapp_ticket_reminders_log($event_id, 'Recordatorio omitido porque su fecha ya pasó.', [
                'reason' => $reason,
                'reminder_id' => $reminder_id,
                'name' => $item['name'] ?? '',
                'run_at' => date_i18n('Y-m-d H:i:s', $run_at),
            ]);
            continue;
        }

        if ( $run_at <= $now ) {
            $run_at = $now + 60;
        }

        wp_schedule_single_event($run_at, EVENTOSAPP_TICKET_REMINDERS_CRON_HOOK, [$event_id, $reminder_id]);
        $scheduled_keys[] = $reminder_id;
        eventosapp_ticket_reminders_log($event_id, 'Recordatorio programado.', [
            'reason' => $reason,
            'reminder_id' => $reminder_id,
            'name' => $item['name'] ?? '',
            'run_at' => date_i18n('Y-m-d H:i:s', $run_at),
        ]);
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
        eventosapp_ticket_reminders_log($event_id, 'Ejecución cancelada: recordatorios desactivados.', ['reminder_id' => $reminder_id]);
        return;
    }

    $item = eventosapp_ticket_reminders_find_item($event_id, $reminder_id);
    if ( empty($item) || ($item['enabled'] ?? '0') !== '1' ) {
        eventosapp_ticket_reminders_log($event_id, 'Ejecución cancelada: recordatorio no encontrado o inactivo.', ['reminder_id' => $reminder_id]);
        return;
    }

    $rules = eventosapp_ticket_reminders_normalize_rules(get_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_RULES, true));
    $channels = eventosapp_ticket_reminders_normalize_channels($item['channels'] ?? []);

    $summary = [
        'date'          => current_time('mysql'),
        'event_id'      => $event_id,
        'reminder_id'   => $reminder_id,
        'reminder_name' => $item['name'] ?? '',
        'sent_total'    => 0,
        'skipped_total' => 0,
        'error_total'   => 0,
        'email_sent'    => 0,
        'whatsapp_sent' => 0,
        'details'       => [],
    ];

    eventosapp_ticket_reminders_log($event_id, 'Inicio de envío de recordatorio.', [
        'reminder_id' => $reminder_id,
        'channels' => array_keys($channels),
    ]);

    $paged = 1;
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
            $passes = eventosapp_ticket_reminders_ticket_passes_rules($ticket_id, $event_id, $rules);
            if ( empty($passes['allowed']) ) {
                $summary['skipped_total']++;
                $summary['details'][] = ['ticket_id' => $ticket_id, 'status' => 'skipped_rules', 'message' => $passes['reason'] ?? 'No cumple filtros.'];
                eventosapp_ticket_reminders_add_ticket_history($ticket_id, $event_id, $item, 'general', 'skipped', $passes['reason'] ?? 'No cumple filtros.');
                continue;
            }

            foreach ( $channels as $channel => $enabled ) {
                if ( ! $enabled ) {
                    continue;
                }

                if ( eventosapp_ticket_reminders_ticket_already_sent($ticket_id, $reminder_id, $channel) ) {
                    $summary['skipped_total']++;
                    $summary['details'][] = ['ticket_id' => $ticket_id, 'channel' => $channel, 'status' => 'skipped_duplicate', 'message' => 'Ya enviado previamente.'];
                    continue;
                }

                if ( $channel === 'email' ) {
                    $result = eventosapp_ticket_reminders_send_email($ticket_id, $event_id, $item);
                } elseif ( $channel === 'whatsapp' ) {
                    $result = eventosapp_ticket_reminders_send_whatsapp($ticket_id, $event_id, $item);
                } else {
                    $result = ['ok' => false, 'message' => 'Canal no soportado.'];
                }

                $status = ! empty($result['ok']) ? 'sent' : 'error';
                if ( ! empty($result['skipped']) ) {
                    $status = 'skipped';
                }

                eventosapp_ticket_reminders_add_ticket_history($ticket_id, $event_id, $item, $channel, $status, $result['message'] ?? '');

                if ( $status === 'sent' ) {
                    $summary['sent_total']++;
                    if ( $channel === 'email' ) $summary['email_sent']++;
                    if ( $channel === 'whatsapp' ) $summary['whatsapp_sent']++;
                } elseif ( $status === 'skipped' ) {
                    $summary['skipped_total']++;
                } else {
                    $summary['error_total']++;
                }

                $summary['details'][] = [
                    'ticket_id' => $ticket_id,
                    'channel'   => $channel,
                    'status'    => $status,
                    'message'   => $result['message'] ?? '',
                ];
            }
        }

        $paged++;
    } while ( $query->max_num_pages >= $paged );

    if ( count($summary['details']) > 100 ) {
        $summary['details'] = array_slice($summary['details'], -100);
    }

    update_post_meta($event_id, EVENTOSAPP_TICKET_REMINDERS_META_LAST_RUN, $summary);
    eventosapp_ticket_reminders_log($event_id, 'Recordatorio ejecutado.', [
        'reminder_id' => $reminder_id,
        'sent_total' => $summary['sent_total'],
        'skipped_total' => $summary['skipped_total'],
        'error_total' => $summary['error_total'],
    ]);
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
        'channel'       => sanitize_key($channel),
        'status'        => sanitize_key($status),
        'message'       => sanitize_text_field((string)$message),
    ];

    if ( count($history) > 80 ) {
        $history = array_slice($history, -80);
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
        $status = get_post_meta($ticket_id, '_eventosapp_checkin_status', true);
        if ( is_array($status) ) {
            return in_array('checked_in', $status, true) ? 'checked_in' : 'not_checked_in';
        }
        return (string)$status;
    }

    if ( $field === 'checkin_virtual' ) {
        $status = get_post_meta($ticket_id, '_eventosapp_virtual_checkin_status', true);
        if ( is_array($status) ) {
            return in_array('checked_in', $status, true) ? 'checked_in' : 'not_checked_in';
        }
        return (string)$status;
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

function eventosapp_ticket_reminders_prepare_assets($ticket_id, $event_id) {
    $ticket_id = absint($ticket_id);
    $event_id = absint($event_id);

    if ( function_exists('eventosapp_ticket_generar_ics') ) {
        eventosapp_ticket_generar_ics($ticket_id);
    }

    global $eventosapp_qr_manager_instance;
    if ( is_object($eventosapp_qr_manager_instance) && method_exists($eventosapp_qr_manager_instance, 'generate_missing_qr_codes') ) {
        $eventosapp_qr_manager_instance->generate_missing_qr_codes($ticket_id);
    }

    if ( function_exists('eventosapp_ticket_generar_pdf') && !(function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id)) ) {
        eventosapp_ticket_generar_pdf($ticket_id);
    }

    do_action('eventosapp_ticket_reminders_prepare_assets', $ticket_id, $event_id);
}

function eventosapp_ticket_reminders_url_to_path($url) {
    $url = esc_url_raw((string)$url);
    if ( $url === '' ) {
        return '';
    }

    $uploads = wp_upload_dir();
    $baseurl = $uploads['baseurl'] ?? '';
    $basedir = $uploads['basedir'] ?? '';

    $url_no_query = strtok($url, '?');
    if ( $baseurl && $basedir && strpos($url_no_query, $baseurl) === 0 ) {
        $path = $basedir . substr($url_no_query, strlen($baseurl));
        return file_exists($path) ? $path : '';
    }

    return '';
}

function eventosapp_ticket_reminders_get_ticket_public_code($ticket_id) {
    if ( function_exists('eventosapp_whatsapp_get_ticket_public_code') ) {
        return eventosapp_whatsapp_get_ticket_public_code($ticket_id);
    }
    $public = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
    if ( ! $public ) {
        $public = get_post_meta($ticket_id, '_eventosapp_ticket_public_id', true);
    }
    return $public ?: (string)$ticket_id;
}

function eventosapp_ticket_reminders_get_ticket_links($ticket_id) {
    $ticket_id = absint($ticket_id);
    $public_code = eventosapp_ticket_reminders_get_ticket_public_code($ticket_id);

    $qr_url = '';
    $qr_codes = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
    if ( is_array($qr_codes) && ! empty($qr_codes['email']['url']) ) {
        $qr_url = esc_url_raw($qr_codes['email']['url']);
    }
    if ( ! $qr_url && function_exists('eventosapp_get_ticket_qr_url') ) {
        $legacy_id = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
        if ( $legacy_id ) {
            $qr_url = eventosapp_get_ticket_qr_url($legacy_id);
        }
    }

    $landing_url = '';
    if ( function_exists('eventosapp_whatsapp_public_ticket_landing_url') ) {
        $landing_url = eventosapp_whatsapp_public_ticket_landing_url($public_code);
    } elseif ( function_exists('eventosapp_whatsapp_templates_public_ticket_landing_url') ) {
        $landing_url = eventosapp_whatsapp_templates_public_ticket_landing_url($public_code);
    }

    $apple = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple', true);
    if ( ! $apple ) $apple = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url', true);
    if ( ! $apple ) $apple = get_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url', true);

    return [
        'ticket_code'    => $public_code,
        'landing_url'    => esc_url_raw($landing_url),
        'qr_url'         => esc_url_raw($qr_url),
        'pdf_url'        => esc_url_raw(get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true)),
        'ics_url'        => esc_url_raw(get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true)),
        'google_wallet'  => esc_url_raw(get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url', true)),
        'apple_wallet'   => esc_url_raw($apple),
    ];
}

function eventosapp_ticket_reminders_get_event_date_label($event_id) {
    if ( function_exists('eventosapp_whatsapp_get_event_date_label') ) {
        return eventosapp_whatsapp_get_event_date_label($event_id);
    }

    $days = eventosapp_ticket_reminders_get_event_days($event_id);
    if ( empty($days) ) {
        return '';
    }
    if ( count($days) === 1 ) {
        return date_i18n('d/m/Y', strtotime($days[0]));
    }
    return date_i18n('d/m/Y', strtotime($days[0])) . ' - ' . date_i18n('d/m/Y', strtotime(end($days)));
}

function eventosapp_ticket_reminders_build_email_html($ticket_id, $event_id, $item, $links) {
    $event_name = get_the_title($event_id);
    $first_name = trim((string)get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true));
    $last_name = trim((string)get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true));
    $full_name = trim($first_name . ' ' . $last_name);
    if ( $full_name === '' ) {
        $full_name = 'Asistente';
    }

    $fecha = eventosapp_ticket_reminders_get_event_date_label($event_id);
    $hora_inicio = get_post_meta($event_id, '_eventosapp_hora_inicio', true);
    $hora_cierre = get_post_meta($event_id, '_eventosapp_hora_cierre', true);
    $hora = trim($hora_inicio . ($hora_cierre ? ' - ' . $hora_cierre : ''));
    $modalidad = function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label($ticket_id) : '';
    $lugar = get_post_meta($event_id, '_eventosapp_direccion', true);
    if ( function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id) ) {
        $platform = get_post_meta($event_id, '_eventosapp_virtual_platform', true);
        $lugar = $platform ? ('Plataforma: ' . $platform) : 'Evento virtual';
    }

    $header_image = get_post_meta($ticket_id, '_eventosapp_ticket_email_header_image_url', true);
    if ( ! $header_image ) {
        $header_image = get_post_meta($event_id, '_eventosapp_email_header_img', true);
    }

    $button = function($url, $label) {
        if ( ! $url ) return '';
        return '<p style="margin:10px 0;"><a href="' . esc_url($url) . '" target="_blank" rel="noopener" style="display:inline-block;background:#2271b1;color:#fff;text-decoration:none;padding:10px 14px;border-radius:6px;font-weight:600;">' . esc_html($label) . '</a></p>';
    };

    ob_start();
    ?>
    <div style="font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:24px;color:#111827;">
        <div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e5e7eb;">
            <?php if ( $header_image ) : ?>
                <img src="<?php echo esc_url($header_image); ?>" alt="<?php echo esc_attr($event_name); ?>" style="width:100%;display:block;height:auto;">
            <?php endif; ?>
            <div style="padding:24px;">
                <h1 style="margin:0 0 10px;font-size:24px;color:#111827;">Recordatorio de tu ticket</h1>
                <p style="font-size:16px;line-height:1.55;margin:0 0 18px;">Hola <?php echo esc_html($full_name); ?>, te recordamos tu inscripción a <strong><?php echo esc_html($event_name); ?></strong>.</p>

                <table style="width:100%;border-collapse:collapse;margin:16px 0;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
                    <tr><td style="padding:10px;border-bottom:1px solid #e5e7eb;width:150px;"><strong>Evento</strong></td><td style="padding:10px;border-bottom:1px solid #e5e7eb;"><?php echo esc_html($event_name); ?></td></tr>
                    <?php if ( $fecha ) : ?><tr><td style="padding:10px;border-bottom:1px solid #e5e7eb;"><strong>Fecha</strong></td><td style="padding:10px;border-bottom:1px solid #e5e7eb;"><?php echo esc_html($fecha); ?></td></tr><?php endif; ?>
                    <?php if ( $hora ) : ?><tr><td style="padding:10px;border-bottom:1px solid #e5e7eb;"><strong>Hora</strong></td><td style="padding:10px;border-bottom:1px solid #e5e7eb;"><?php echo esc_html($hora); ?></td></tr><?php endif; ?>
                    <?php if ( $lugar ) : ?><tr><td style="padding:10px;border-bottom:1px solid #e5e7eb;"><strong>Lugar / plataforma</strong></td><td style="padding:10px;border-bottom:1px solid #e5e7eb;"><?php echo esc_html($lugar); ?></td></tr><?php endif; ?>
                    <?php if ( $modalidad ) : ?><tr><td style="padding:10px;"><strong>Modalidad</strong></td><td style="padding:10px;"><?php echo esc_html($modalidad); ?></td></tr><?php endif; ?>
                </table>

                <?php if ( ! empty($links['landing_url']) ) : ?>
                    <?php echo $button($links['landing_url'], 'Ver mi ticket'); ?>
                <?php endif; ?>

                <?php if ( ! empty($links['qr_url']) ) : ?>
                    <p style="margin:18px 0 8px;"><strong>QR de ingreso</strong></p>
                    <p style="margin:0 0 14px;"><img src="<?php echo esc_url($links['qr_url']); ?>" alt="QR del ticket" style="max-width:180px;height:auto;border:1px solid #e5e7eb;border-radius:8px;padding:8px;background:#fff;"></p>
                <?php endif; ?>

                <div style="margin-top:18px;">
                    <?php echo $button($links['pdf_url'] ?? '', 'Descargar PDF'); ?>
                    <?php echo $button($links['ics_url'] ?? '', 'Agregar a calendario'); ?>
                    <?php echo $button($links['google_wallet'] ?? '', 'Agregar a Google Wallet'); ?>
                    <?php echo $button($links['apple_wallet'] ?? '', 'Agregar a Apple Wallet'); ?>
                </div>

                <p style="font-size:12px;color:#6b7280;margin-top:22px;">Ticket: <?php echo esc_html($links['ticket_code'] ?? $ticket_id); ?></p>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function eventosapp_ticket_reminders_send_email($ticket_id, $event_id, $item) {
    $ticket_id = absint($ticket_id);
    $event_id = absint($event_id);

    $email = sanitize_email(get_post_meta($ticket_id, '_eventosapp_asistente_email', true));
    if ( ! $email || ! is_email($email) ) {
        return ['ok' => false, 'message' => 'El asistente no tiene correo válido.'];
    }

    eventosapp_ticket_reminders_prepare_assets($ticket_id, $event_id);
    $links = eventosapp_ticket_reminders_get_ticket_links($ticket_id);

    $subject = sprintf('Recordatorio: %s', get_the_title($event_id));
    $subject = apply_filters('eventosapp_ticket_reminders_email_subject', $subject, $ticket_id, $event_id, $item);
    $body = eventosapp_ticket_reminders_build_email_html($ticket_id, $event_id, $item, $links);
    $body = apply_filters('eventosapp_ticket_reminders_email_body', $body, $ticket_id, $event_id, $item, $links);

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $attachments = [];

    foreach ( ['pdf_url', 'ics_url'] as $link_key ) {
        if ( ! empty($links[$link_key]) ) {
            $path = eventosapp_ticket_reminders_url_to_path($links[$link_key]);
            if ( $path ) {
                $attachments[] = $path;
            }
        }
    }

    $sent = wp_mail($email, $subject, $body, $headers, $attachments);

    if ( $sent ) {
        update_post_meta($ticket_id, '_eventosapp_ticket_reminder_email_last_sent_at', current_time('mysql'));
        update_post_meta($ticket_id, '_eventosapp_ticket_reminder_email_last_to', $email);
        return ['ok' => true, 'message' => 'Correo de recordatorio enviado.'];
    }

    return ['ok' => false, 'message' => 'wp_mail no confirmó el envío del recordatorio.'];
}

function eventosapp_ticket_reminders_send_whatsapp($ticket_id, $event_id, $item) {
    $ticket_id = absint($ticket_id);
    $event_id = absint($event_id);

    if ( ! function_exists('eventosapp_whatsapp_send_ticket') ) {
        return ['ok' => false, 'message' => 'El módulo WhatsApp no está disponible.'];
    }

    if ( get_post_meta($event_id, '_eventosapp_ticket_whatsapp_enabled', true) !== '1' ) {
        return ['ok' => true, 'skipped' => true, 'message' => 'WhatsApp no está activo para este evento.'];
    }

    $source_key = 'ticket_reminder:' . $event_id . ':' . sanitize_key($item['id'] ?? '') . ':' . $ticket_id;
    $result = eventosapp_whatsapp_send_ticket($ticket_id, [
        'context'    => 'ticket_reminder',
        'source_key' => $source_key,
        'skip_rules' => true,
        'force'      => false,
    ]);

    if ( ! is_array($result) ) {
        return ['ok' => false, 'message' => 'Respuesta inválida del módulo WhatsApp.'];
    }

    if ( ! empty($result['ok']) && empty($result['skipped_duplicate']) && empty($result['skipped_rules']) ) {
        return ['ok' => true, 'message' => $result['message'] ?? 'WhatsApp de recordatorio enviado.'];
    }

    if ( ! empty($result['skipped_duplicate']) || ! empty($result['skipped_rules']) ) {
        return ['ok' => true, 'skipped' => true, 'message' => $result['message'] ?? 'WhatsApp omitido.'];
    }

    return ['ok' => false, 'message' => $result['message'] ?? 'No se pudo enviar WhatsApp.'];
}

/**
 * Re-sincronización liviana para recuperar recordatorios si el archivo se instala
 * después de que los eventos ya estaban creados o si WP-Cron perdió un evento.
 */
add_action('init', function() {
    if ( wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON) ) {
        return;
    }

    if ( get_transient('eventosapp_ticket_reminders_periodic_sync_lock') ) {
        return;
    }
    set_transient('eventosapp_ticket_reminders_periodic_sync_lock', 1, 6 * HOUR_IN_SECONDS);

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
