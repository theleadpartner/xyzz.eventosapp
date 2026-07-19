<?php
/**
 * EventosApp - Integraciones del campo de confirmación de asistencia.
 *
 * Conecta el campo canónico de confirmación con módulos existentes que
 * mantienen registros de campos o filtros independientes:
 * - listado administrativo de tickets;
 * - recordatorios/notificaciones por reglas;
 * - campañas masivas de WhatsApp Flows;
 * - registros genéricos de campos de tickets.
 *
 * No reemplaza handlers existentes. Usa filtros, consultas y guardas previas
 * para conservar compatibilidad con las funciones actuales.
 *
 * Ruta: includes/functions/eventosapp-attendance-confirmation-integrations.php
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Opciones disponibles aunque el motor principal todavía no haya terminado
 * de cargar por una instalación parcial.
 */
function eventosapp_attendance_integrations_status_options() {
    return function_exists('eventosapp_attendance_confirmation_status_options')
        ? eventosapp_attendance_confirmation_status_options()
        : [
            'si'           => 'Sí',
            'no'           => 'No',
            'no_responde'  => 'No responde',
            'sin_consulta' => 'Sin consulta',
        ];
}

function eventosapp_attendance_integrations_status_label($status) {
    $status = sanitize_key((string)$status);
    $options = eventosapp_attendance_integrations_status_options();
    return $options[$status] ?? 'Sin consulta';
}

function eventosapp_attendance_integrations_get_status($ticket_id, $display = false) {
    $ticket_id = absint($ticket_id);
    if ( ! $ticket_id ) return $display ? 'Sin consulta' : 'sin_consulta';

    if ( function_exists('eventosapp_attendance_confirmation_get_ticket_field_value') ) {
        return eventosapp_attendance_confirmation_get_ticket_field_value(
            $ticket_id,
            'attendance_confirmation_status',
            (bool)$display
        );
    }

    $status = sanitize_key((string)get_post_meta($ticket_id, '_eventosapp_attendance_confirmation_status', true));
    if ( ! array_key_exists($status, eventosapp_attendance_integrations_status_options()) ) {
        $status = 'sin_consulta';
    }
    return $display ? eventosapp_attendance_integrations_status_label($status) : $status;
}

function eventosapp_attendance_integrations_get_field_value($ticket_id, $field, $display = false) {
    $ticket_id = absint($ticket_id);
    $field = sanitize_key((string)$field);
    if ( ! $ticket_id ) return '';

    if ( function_exists('eventosapp_attendance_confirmation_get_ticket_field_value') ) {
        $value = eventosapp_attendance_confirmation_get_ticket_field_value($ticket_id, $field, (bool)$display);
        if ( is_array($value) ) {
            return implode(', ', array_map('sanitize_key', $value));
        }
        return $value;
    }

    $map = [
        'attendance_confirmation_status'                => '_eventosapp_attendance_confirmation_status',
        'attendance_confirmation_sent_channels'         => '_eventosapp_attendance_confirmation_sent_channels',
        'attendance_confirmation_response_channels'     => '_eventosapp_attendance_confirmation_response_channels',
        'attendance_confirmation_last_response_channel' => '_eventosapp_attendance_confirmation_last_response_channel',
        'attendance_confirmation_last_response_at'      => '_eventosapp_attendance_confirmation_last_response_at',
    ];

    if ( $field === 'attendance_confirmation_status' ) {
        return eventosapp_attendance_integrations_get_status($ticket_id, $display);
    }
    if ( ! isset($map[$field]) ) return '';

    $value = get_post_meta($ticket_id, $map[$field], true);
    if ( is_array($value) ) {
        return implode(', ', array_map('sanitize_key', $value));
    }
    return sanitize_text_field((string)$value);
}

/**
 * Registro común para módulos que consumen filtros de campos.
 */
function eventosapp_attendance_integrations_add_filter_fields($fields) {
    $fields = is_array($fields) ? $fields : [];
    $fields['attendance_confirmation_status'] = 'Confirmación de asistencia';
    $fields['attendance_confirmation_sent_channels'] = 'Canales consultados para confirmación';
    $fields['attendance_confirmation_response_channels'] = 'Canales de respuesta de confirmación';
    $fields['attendance_confirmation_last_response_channel'] = 'Último canal de respuesta de confirmación';
    $fields['attendance_confirmation_last_response_at'] = 'Fecha de última respuesta de confirmación';
    return $fields;
}

foreach ([
    'eventosapp_ticket_filter_fields',
    'eventosapp_ticket_rule_fields',
    'eventosapp_notification_filter_fields',
    'eventosapp_webhook_condition_fields',
    'eventosapp_ticket_variant_condition_fields',
    'eventosapp_ticket_variant_fields',
    'eventosapp_webhook_conditional_fields',
] as $filter_name) {
    add_filter($filter_name, 'eventosapp_attendance_integrations_add_filter_fields', 30, 1);
}

/**
 * Metadatos espejo para motores antiguos que resuelven campos desconocidos
 * como `_eventosapp_extra_{campo}`. El valor canónico sigue estando en las
 * metas `_eventosapp_attendance_confirmation_*`; el espejo solo garantiza
 * compatibilidad sin reemplazar sus funciones actuales.
 */
function eventosapp_attendance_integrations_mirror_meta_map() {
    return [
        '_eventosapp_attendance_confirmation_status'
            => '_eventosapp_extra_attendance_confirmation_status',
        '_eventosapp_attendance_confirmation_sent_channels'
            => '_eventosapp_extra_attendance_confirmation_sent_channels',
        '_eventosapp_attendance_confirmation_response_channels'
            => '_eventosapp_extra_attendance_confirmation_response_channels',
        '_eventosapp_attendance_confirmation_last_response_channel'
            => '_eventosapp_extra_attendance_confirmation_last_response_channel',
        '_eventosapp_attendance_confirmation_last_response_at'
            => '_eventosapp_extra_attendance_confirmation_last_response_at',
    ];
}

function eventosapp_attendance_integrations_sync_ticket_mirrors($ticket_id) {
    $ticket_id = absint($ticket_id);
    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) return false;

    static $syncing = [];
    if ( ! empty($syncing[$ticket_id]) ) return false;
    $syncing[$ticket_id] = true;

    foreach ( eventosapp_attendance_integrations_mirror_meta_map() as $source => $target ) {
        if ( $source === '_eventosapp_attendance_confirmation_status' ) {
            $value = eventosapp_attendance_integrations_get_status($ticket_id, false);
        } else {
            $value = get_post_meta($ticket_id, $source, true);
        }

        if ( get_post_meta($ticket_id, $target, true) !== $value ) {
            update_post_meta($ticket_id, $target, $value);
        }
    }

    unset($syncing[$ticket_id]);
    return true;
}
add_action('save_post_eventosapp_ticket', 'eventosapp_attendance_integrations_sync_ticket_mirrors', 6, 1);

function eventosapp_attendance_integrations_sync_changed_meta($meta_id, $post_id, $meta_key, $meta_value) {
    $map = eventosapp_attendance_integrations_mirror_meta_map();
    if ( ! isset($map[$meta_key]) || get_post_type($post_id) !== 'eventosapp_ticket' ) return;
    eventosapp_attendance_integrations_sync_ticket_mirrors($post_id);
}
add_action('added_post_meta', 'eventosapp_attendance_integrations_sync_changed_meta', 20, 4);
add_action('updated_post_meta', 'eventosapp_attendance_integrations_sync_changed_meta', 20, 4);

/**
 * Migración gradual para tickets existentes. No carga toda la base:
 * procesa hasta 100 IDs por ejecución y programa el siguiente cursor.
 */
if ( ! defined('EVENTOSAPP_ATTENDANCE_FIELDS_MIGRATION_HOOK') ) {
    define('EVENTOSAPP_ATTENDANCE_FIELDS_MIGRATION_HOOK', 'eventosapp_attendance_fields_migrate_batch');
}
if ( ! defined('EVENTOSAPP_ATTENDANCE_FIELDS_MIGRATION_VERSION') ) {
    define('EVENTOSAPP_ATTENDANCE_FIELDS_MIGRATION_VERSION', '2026.07.19.1');
}

add_action('init', function() {
    if ( get_option('eventosapp_attendance_fields_migration_version') === EVENTOSAPP_ATTENDANCE_FIELDS_MIGRATION_VERSION ) {
        return;
    }
    if ( ! wp_next_scheduled(EVENTOSAPP_ATTENDANCE_FIELDS_MIGRATION_HOOK) ) {
        wp_schedule_single_event(time() + 30, EVENTOSAPP_ATTENDANCE_FIELDS_MIGRATION_HOOK);
    }
}, 30);

add_action(EVENTOSAPP_ATTENDANCE_FIELDS_MIGRATION_HOOK, function() {
    global $wpdb;
    $after_id = absint(get_option('eventosapp_attendance_fields_migration_cursor', 0));
    $limit = 100;

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID
         FROM {$wpdb->posts}
         WHERE post_type = %s
           AND post_status NOT IN ('trash','auto-draft')
           AND ID > %d
         ORDER BY ID ASC
         LIMIT %d",
        'eventosapp_ticket',
        $after_id,
        $limit
    ));
    $ids = array_values(array_filter(array_map('absint', (array)$ids)));

    foreach ( $ids as $ticket_id ) {
        if ( function_exists('eventosapp_attendance_confirmation_initialize_ticket') ) {
            eventosapp_attendance_confirmation_initialize_ticket($ticket_id);
        }
        eventosapp_attendance_integrations_sync_ticket_mirrors($ticket_id);
    }

    if ( count($ids) >= $limit ) {
        update_option('eventosapp_attendance_fields_migration_cursor', (int)end($ids), false);
        wp_schedule_single_event(time() + 30, EVENTOSAPP_ATTENDANCE_FIELDS_MIGRATION_HOOK);
    } else {
        delete_option('eventosapp_attendance_fields_migration_cursor');
        update_option(
            'eventosapp_attendance_fields_migration_version',
            EVENTOSAPP_ATTENDANCE_FIELDS_MIGRATION_VERSION,
            false
        );
    }
}, 10);

/**
 * Recordatorios/notificaciones por WhatsApp.
 * El módulo existente expone filtros para registrar campos y resolver valores.
 */
add_filter('eventosapp_ticket_reminders_filter_fields', function($fields, $event_id) {
    return eventosapp_attendance_integrations_add_filter_fields($fields);
}, 30, 2);

add_filter('eventosapp_ticket_reminders_filter_value', function($value, $ticket_id, $field) {
    $field = sanitize_key((string)$field);
    if ( strpos($field, 'attendance_confirmation_') !== 0 ) {
        return $value;
    }
    return eventosapp_attendance_integrations_get_field_value($ticket_id, $field, false);
}, 30, 3);

/**
 * Columna e indicador en el listado administrativo de tickets.
 */
add_filter('manage_eventosapp_ticket_posts_columns', function($columns) {
    $columns = is_array($columns) ? $columns : [];
    $new = [];
    $inserted = false;

    foreach ( $columns as $key => $label ) {
        $new[$key] = $label;
        if ( in_array($key, ['localidad', 'modalidad', 'date'], true) && ! $inserted ) {
            $new['attendance_confirmation'] = 'Confirmación';
            $inserted = true;
        }
    }
    if ( ! $inserted ) {
        $new['attendance_confirmation'] = 'Confirmación';
    }
    return $new;
}, 30);

add_action('manage_eventosapp_ticket_posts_custom_column', function($column, $post_id) {
    if ( $column !== 'attendance_confirmation' ) return;
    $status = eventosapp_attendance_integrations_get_status($post_id, false);
    $label = eventosapp_attendance_integrations_status_label($status);
    $styles = [
        'si'           => 'background:#dcfce7;color:#166534;border-color:#bbf7d0;',
        'no'           => 'background:#fee2e2;color:#991b1b;border-color:#fecaca;',
        'no_responde'  => 'background:#fef3c7;color:#92400e;border-color:#fde68a;',
        'sin_consulta' => 'background:#f3f4f6;color:#4b5563;border-color:#e5e7eb;',
    ];
    echo '<span style="display:inline-block;padding:3px 8px;border:1px solid;border-radius:999px;font-size:11px;font-weight:600;' .
        esc_attr($styles[$status] ?? $styles['sin_consulta']) . '">' . esc_html($label) . '</span>';
}, 30, 2);

add_action('restrict_manage_posts', function($post_type) {
    if ( $post_type !== 'eventosapp_ticket' ) return;
    $selected = isset($_GET['attendance_confirmation_status'])
        ? sanitize_key((string)wp_unslash($_GET['attendance_confirmation_status']))
        : '';
    echo '<select name="attendance_confirmation_status">';
    echo '<option value="">Todas las confirmaciones</option>';
    foreach ( eventosapp_attendance_integrations_status_options() as $value => $label ) {
        echo '<option value="' . esc_attr($value) . '" ' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
}, 30);

add_action('pre_get_posts', function($query) {
    if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) return;
    if ( $query->get('post_type') !== 'eventosapp_ticket' ) return;

    $status = isset($_GET['attendance_confirmation_status'])
        ? sanitize_key((string)wp_unslash($_GET['attendance_confirmation_status']))
        : '';
    if ( $status === '' ) return;

    $clause = function_exists('eventosapp_attendance_confirmation_status_meta_query')
        ? eventosapp_attendance_confirmation_status_meta_query($status)
        : null;
    if ( ! is_array($clause) ) return;

    $meta_query = $query->get('meta_query');
    $meta_query = is_array($meta_query) ? $meta_query : [];
    if ( empty($meta_query['relation']) ) $meta_query['relation'] = 'AND';
    $meta_query[] = $clause;
    $query->set('meta_query', $meta_query);
}, 30);

/**
 * Resuelve el filtro de confirmación usado por la campaña de WhatsApp Flows.
 */
function eventosapp_attendance_integrations_flow_confirmation_status() {
    if ( isset($_POST['filters']['confirmation_status']) ) {
        return sanitize_key((string)wp_unslash($_POST['filters']['confirmation_status']));
    }

    $page = isset($_GET['page']) ? sanitize_key((string)wp_unslash($_GET['page'])) : '';
    $segment_id = isset($_GET['segment_id']) ? sanitize_key((string)wp_unslash($_GET['segment_id'])) : '';
    if ( $page !== 'eventosapp_whatsapp_flows_campaign' || $segment_id === '' ) return '';

    if ( function_exists('eventosapp_whatsapp_flows_bulk_segment_option_key') ) {
        $segment = get_option(eventosapp_whatsapp_flows_bulk_segment_option_key($segment_id));
    } else {
        $segment = get_option('evapp_whatsapp_flow_segment_' . $segment_id);
    }

    if ( ! is_array($segment) || empty($segment['filters']['confirmation_status']) ) return '';
    return sanitize_key((string)$segment['filters']['confirmation_status']);
}

/**
 * Agrega el filtro a las consultas paginadas del módulo Flows sin modificar
 * directamente su archivo de 6.000+ líneas.
 */
add_action('pre_get_posts', function($query) {
    if ( ! is_admin() || ! $query instanceof WP_Query ) return;

    $post_type = $query->get('post_type');
    $is_ticket_query = $post_type === 'eventosapp_ticket'
        || (is_array($post_type) && in_array('eventosapp_ticket', $post_type, true));
    if ( ! $is_ticket_query ) return;

    $status = eventosapp_attendance_integrations_flow_confirmation_status();
    if ( $status === '' ) return;

    $clause = function_exists('eventosapp_attendance_confirmation_status_meta_query')
        ? eventosapp_attendance_confirmation_status_meta_query($status)
        : null;
    if ( ! is_array($clause) ) return;

    $meta_query = $query->get('meta_query');
    $meta_query = is_array($meta_query) ? $meta_query : [];
    if ( empty($meta_query['relation']) ) $meta_query['relation'] = 'AND';

    // Evita agregar la misma condición dos veces si otro componente vuelve a ejecutar el filtro.
    foreach ( $meta_query as $existing ) {
        if ( is_array($existing) && ($existing['key'] ?? '') === '_eventosapp_attendance_confirmation_status' ) {
            return;
        }
    }

    $meta_query[] = $clause;
    $query->set('meta_query', $meta_query);
}, 40);

/**
 * Aviso visible de protección en el módulo de campañas de Flows.
 */
add_action('admin_notices', function() {
    $page = isset($_GET['page']) ? sanitize_key((string)wp_unslash($_GET['page'])) : '';
    if ( $page !== 'eventosapp_whatsapp_flows_campaign' ) return;
    echo '<div class="notice notice-info"><p><strong>EventosApp:</strong> la campaña de Flows usa lotes máximos de 3 tickets y bloqueo de concurrencia para proteger los recursos del servidor.</p></div>';
});

/**
 * UI y columnas de vista previa para la campaña de WhatsApp Flows.
 */
add_action('admin_footer', function() {
    $page = isset($_GET['page']) ? sanitize_key((string)wp_unslash($_GET['page'])) : '';
    if ( $page !== 'eventosapp_whatsapp_flows_campaign' ) return;

    $step = max(1, absint($_GET['step'] ?? 1));
    $options = eventosapp_attendance_integrations_status_options();
    $segment_id = isset($_GET['segment_id']) ? sanitize_key((string)wp_unslash($_GET['segment_id'])) : '';
    $segment = [];
    if ( $segment_id !== '' ) {
        $segment = function_exists('eventosapp_whatsapp_flows_bulk_segment_option_key')
            ? get_option(eventosapp_whatsapp_flows_bulk_segment_option_key($segment_id))
            : get_option('evapp_whatsapp_flow_segment_' . $segment_id);
        $segment = is_array($segment) ? $segment : [];
    }

    $selected = sanitize_key((string)($segment['filters']['confirmation_status'] ?? ''));
    $preview_labels = [];
    if ( $step === 2 && ! empty($segment['ticket_ids']) && is_array($segment['ticket_ids']) ) {
        foreach ( array_slice(array_values(array_filter(array_map('absint', $segment['ticket_ids']))), 0, 150) as $ticket_id ) {
            $preview_labels[] = eventosapp_attendance_integrations_get_status($ticket_id, true);
        }
    }
    ?>
    <script>
    jQuery(function($){
        const statusOptions = <?php echo wp_json_encode($options); ?> || {};
        const selectedStatus = <?php echo wp_json_encode($selected); ?> || '';

        /*
         * El módulo original encadena lotes cada 500 ms. Esta envoltura solo
         * afecta el AJAX de campañas de Flows y garantiza 30 segundos entre
         * inicios de lote. No modifica otros AJAX del administrador.
         */
        if (!window.evappAttendanceFlowAjaxThrottleInstalled) {
            window.evappAttendanceFlowAjaxThrottleInstalled = true;
            const originalAjax = $.ajax;
            const minimumDelayMs = 30000;
            let lastBatchStartedAt = 0;
            let pendingBatchTimer = null;
            let pendingBatchDeferred = null;

            $.ajax = function(url, settings) {
                const options = (typeof url === 'object' && url !== null) ? url : (settings || {});
                const data = options && options.data && typeof options.data === 'object' ? options.data : {};
                if (String(data.action || '') !== 'eventosapp_whatsapp_flow_process_batch') {
                    return originalAjax.apply($, arguments);
                }

                const ajaxArguments = arguments;
                const deferred = $.Deferred();
                const proxy = deferred.promise();
                let activeRequest = null;
                let cancelled = false;

                const executeRequest = function() {
                    pendingBatchTimer = null;
                    pendingBatchDeferred = null;
                    if (cancelled) {
                        deferred.reject(null, 'abort', 'abort');
                        return;
                    }
                    lastBatchStartedAt = Date.now();
                    activeRequest = originalAjax.apply($, ajaxArguments);
                    if (activeRequest && typeof activeRequest.done === 'function') {
                        activeRequest.done(function(){ deferred.resolve.apply(deferred, arguments); });
                        activeRequest.fail(function(){ deferred.reject.apply(deferred, arguments); });
                    }
                };

                const elapsed = lastBatchStartedAt > 0 ? Date.now() - lastBatchStartedAt : minimumDelayMs;
                const wait = Math.max(0, minimumDelayMs - elapsed);
                if (wait > 0) {
                    pendingBatchDeferred = deferred;
                    pendingBatchTimer = window.setTimeout(executeRequest, wait);
                    $('#processStatus').html(
                        '<div class="evapp-info"><strong>Pausa de protección activa.</strong> El siguiente lote iniciará en aproximadamente ' +
                        Math.ceil(wait / 1000) + ' segundos.</div>'
                    );
                } else {
                    executeRequest();
                }

                proxy.abort = function() {
                    cancelled = true;
                    if (pendingBatchTimer) {
                        window.clearTimeout(pendingBatchTimer);
                        pendingBatchTimer = null;
                        pendingBatchDeferred = null;
                    }
                    if (activeRequest && typeof activeRequest.abort === 'function') {
                        activeRequest.abort();
                    }
                    deferred.reject(null, 'abort', 'abort');
                };
                return proxy;
            };

            $(document).on('click.evappAttendanceFlowThrottle', '#pauseProcess', function(){
                if (pendingBatchTimer) {
                    window.clearTimeout(pendingBatchTimer);
                    pendingBatchTimer = null;
                    if (pendingBatchDeferred) {
                        pendingBatchDeferred.reject(null, 'abort', 'abort');
                        pendingBatchDeferred = null;
                    }
                }
            });
        }

        if (<?php echo $step === 1 ? 'true' : 'false'; ?>) {
            const $form = $('#evappFlowBulkForm');
            if ($form.length && !$form.find('[name="filters[confirmation_status]"]').length) {
                let optionsHtml = '<option value="">-- Todos los estados --</option>';
                Object.keys(statusOptions).forEach(function(key){
                    optionsHtml += '<option value="' + $('<div>').text(key).html() + '">' + $('<div>').text(statusOptions[key]).html() + '</option>';
                });
                const fieldHtml =
                    '<label class="evapp-field evapp-attendance-flow-filter">' +
                    '<span>Confirmación de asistencia</span>' +
                    '<select name="filters[confirmation_status]">' + optionsHtml + '</select>' +
                    '<small class="description">Segmenta según Sí, No, No responde o Sin consulta.</small>' +
                    '</label>';
                const $reference = $form.find('[name="filters[checkin_status]"]').closest('.evapp-field');
                if ($reference.length) {
                    $reference.after(fieldHtml);
                } else {
                    const $row = $form.find('.evapp-row, .evapp-row-3').last();
                    if ($row.length) $row.append(fieldHtml);
                    else $form.find('button[type="submit"]').first().before(fieldHtml);
                }
            }
        }

        if (<?php echo $step === 2 ? 'true' : 'false'; ?>) {
            if (selectedStatus && statusOptions[selectedStatus]) {
                const $tagline = $('.evapp-flow-tagline').first();
                if ($tagline.length && !$tagline.find('.evapp-attendance-confirmation-tag').length) {
                    $tagline.append(
                        '<span class="evapp-flow-filter-tag evapp-attendance-confirmation-tag"><strong>Confirmación:</strong> ' +
                        $('<div>').text(statusOptions[selectedStatus]).html() + '</span>'
                    );
                }
            }

            const labels = <?php echo wp_json_encode($preview_labels); ?> || [];
            const $table = $('.evapp-card table.widefat').first();
            if ($table.length && labels.length) {
                let checkinIndex = -1;
                $table.find('thead th').each(function(index){
                    if ($(this).text().trim() === 'Check-in') checkinIndex = index;
                });
                if (checkinIndex >= 0 && !$table.find('thead th.evapp-attendance-confirmation-column').length) {
                    $table.find('thead th').eq(checkinIndex).after('<th class="evapp-attendance-confirmation-column">Confirmación</th>');
                    $table.find('tbody tr').each(function(index){
                        const label = labels[index] || 'Sin consulta';
                        $(this).find('td').eq(checkinIndex).after(
                            '<td><span class="evapp-pill gray">' + $('<div>').text(label).html() + '</span></td>'
                        );
                    });
                }
            }
        }
    });
    </script>
    <?php
}, 50);

/**
 * Límites conservadores específicos para campañas de WhatsApp Flows.
 */
add_filter('eventosapp_bulk_processing_limits', function($limits, $context, $channels) {
    if ( in_array($context, ['whatsapp_flow_bulk', 'whatsapp_flow_direct_campaign'], true) ) {
        $limits['batch_size'] = 3;
        $limits['delay_seconds'] = max(30, absint($limits['delay_seconds'] ?? 30));
        $limits['max_execution'] = min(20, absint($limits['max_execution'] ?? 20));
    }
    return $limits;
}, 30, 3);

function eventosapp_attendance_integrations_register_shutdown_lock($scope, $token) {
    if ( ! isset($GLOBALS['eventosapp_attendance_integration_locks']) || ! is_array($GLOBALS['eventosapp_attendance_integration_locks']) ) {
        $GLOBALS['eventosapp_attendance_integration_locks'] = [];
    }
    $GLOBALS['eventosapp_attendance_integration_locks'][] = [
        'scope' => (string)$scope,
        'token' => (string)$token,
    ];

    static $registered = false;
    if ( $registered ) return;
    $registered = true;

    register_shutdown_function(function() {
        if ( empty($GLOBALS['eventosapp_attendance_integration_locks']) || ! is_array($GLOBALS['eventosapp_attendance_integration_locks']) ) {
            return;
        }
        foreach ( $GLOBALS['eventosapp_attendance_integration_locks'] as $lock ) {
            if ( function_exists('eventosapp_attendance_confirmation_release_lock') ) {
                eventosapp_attendance_confirmation_release_lock($lock['scope'] ?? '', $lock['token'] ?? '');
            }
        }
    });
}

/**
 * Guarda previa para el AJAX masivo de Flows:
 * - impone lote máximo de 3;
 * - evita dos lotes simultáneos del mismo segmento;
 * - la liberación ocurre al cerrar la petición, incluso cuando el handler
 *   existente termina mediante wp_send_json_*().
 */
add_action('wp_ajax_eventosapp_whatsapp_flow_process_batch', function() {
    if ( ! current_user_can('manage_options') ) return;
    if ( ! check_ajax_referer('eventosapp_whatsapp_flow_process', '_wpnonce', false) ) return;

    $segment_id = sanitize_key((string)wp_unslash($_POST['segment_id'] ?? ''));
    if ( $segment_id === '' ) return;

    $limits = function_exists('eventosapp_attendance_confirmation_bulk_limits')
        ? eventosapp_attendance_confirmation_bulk_limits('whatsapp_flow_bulk', ['whatsapp'])
        : ['batch_size'=>3,'delay_seconds'=>30,'lock_ttl'=>180];

    $_POST['batch_size'] = min(3, max(1, absint($_POST['batch_size'] ?? ($limits['batch_size'] ?? 3))));

    if ( ! function_exists('eventosapp_attendance_confirmation_acquire_lock') ) return;
    $scope = 'whatsapp_flow_bulk:' . $segment_id;
    $token = eventosapp_attendance_confirmation_acquire_lock($scope, absint($limits['lock_ttl'] ?? 180));
    if ( is_wp_error($token) ) {
        wp_send_json_error([
            'message'     => $token->get_error_message(),
            'retry_after' => max(10, absint($limits['delay_seconds'] ?? 30)),
            'busy'        => true,
        ], 409);
    }
    eventosapp_attendance_integrations_register_shutdown_lock($scope, $token);
}, 1);

/**
 * La campaña directa antigua permitía hasta 100 tickets en una petición
 * síncrona. Se conserva la función, pero se limita a 3 por ejecución.
 */
add_action('admin_post_eventosapp_whatsapp_flow_campaign_send', function() {
    if ( ! current_user_can('manage_options') ) return;
    $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field((string)wp_unslash($_REQUEST['_wpnonce'])) : '';
    if ( $nonce === '' || ! wp_verify_nonce($nonce, 'eventosapp_whatsapp_flow_campaign_send') ) return;

    $_POST['campaign_limit'] = min(3, max(1, absint($_POST['campaign_limit'] ?? 3)));

    if ( ! function_exists('eventosapp_attendance_confirmation_acquire_lock') ) return;
    $flow_id = absint($_POST['flow_post_id'] ?? 0);
    $event_id = absint($_POST['campaign_event_id'] ?? 0);
    $scope = 'whatsapp_flow_direct:' . $flow_id . ':' . $event_id;
    $token = eventosapp_attendance_confirmation_acquire_lock($scope, 180);
    if ( is_wp_error($token) ) {
        wp_die(esc_html($token->get_error_message()), 'EventosApp', ['response'=>409]);
    }
    eventosapp_attendance_integrations_register_shutdown_lock($scope, $token);
}, 1);
