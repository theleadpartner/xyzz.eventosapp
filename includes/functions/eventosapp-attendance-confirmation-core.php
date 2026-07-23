<?php
/**
 * EventosApp - Motor de confirmación de asistencia.
 *
 * Archivo nuevo e independiente. Centraliza estados, trazabilidad, respuestas
 * públicas, envío por correo/WhatsApp, filtros y procesamiento programado.
 *
 * Ruta: includes/functions/eventosapp-attendance-confirmation-core.php
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! defined('EVENTOSAPP_ATTENDANCE_CONFIRMATION_VERSION') ) {
    define('EVENTOSAPP_ATTENDANCE_CONFIRMATION_VERSION', '2026.07.23.2');
}
if ( ! defined('EVENTOSAPP_ATTENDANCE_CONFIRMATION_SEGMENT_PREFIX') ) {
    define('EVENTOSAPP_ATTENDANCE_CONFIRMATION_SEGMENT_PREFIX', 'evapp_attendance_segment_');
}
if ( ! defined('EVENTOSAPP_ATTENDANCE_CONFIRMATION_BATCH_SIZE') ) {
    define('EVENTOSAPP_ATTENDANCE_CONFIRMATION_BATCH_SIZE', 5);
}
if ( ! defined('EVENTOSAPP_ATTENDANCE_CONFIRMATION_BATCH_DELAY') ) {
    define('EVENTOSAPP_ATTENDANCE_CONFIRMATION_BATCH_DELAY', 30);
}
if ( ! defined('EVENTOSAPP_ATTENDANCE_CONFIRMATION_MAX_EXECUTION_SECONDS') ) {
    define('EVENTOSAPP_ATTENDANCE_CONFIRMATION_MAX_EXECUTION_SECONDS', 20);
}
if ( ! defined('EVENTOSAPP_ATTENDANCE_CONFIRMATION_MEMORY_STOP_RATIO') ) {
    define('EVENTOSAPP_ATTENDANCE_CONFIRMATION_MEMORY_STOP_RATIO', 0.72);
}
if ( ! defined('EVENTOSAPP_ATTENDANCE_CONFIRMATION_LOCK_TTL') ) {
    define('EVENTOSAPP_ATTENDANCE_CONFIRMATION_LOCK_TTL', 180);
}
if ( ! defined('EVENTOSAPP_ATTENDANCE_CONFIRMATION_SCHEDULE_HOOK') ) {
    define('EVENTOSAPP_ATTENDANCE_CONFIRMATION_SCHEDULE_HOOK', 'eventosapp_attendance_confirmation_start_scheduled_job');
}
if ( ! defined('EVENTOSAPP_ATTENDANCE_CONFIRMATION_BATCH_HOOK') ) {
    define('EVENTOSAPP_ATTENDANCE_CONFIRMATION_BATCH_HOOK', 'eventosapp_attendance_confirmation_process_scheduled_batch');
}

/**
 * Metakeys del módulo.
 */
function eventosapp_attendance_confirmation_meta_keys() {
    return [
        'status'              => '_eventosapp_attendance_confirmation_status',
        'sent_channels'       => '_eventosapp_attendance_confirmation_sent_channels',
        'response_channels'   => '_eventosapp_attendance_confirmation_response_channels',
        'first_sent_at'       => '_eventosapp_attendance_confirmation_first_sent_at',
        'last_sent_at'        => '_eventosapp_attendance_confirmation_last_sent_at',
        'last_response_at'    => '_eventosapp_attendance_confirmation_last_response_at',
        'last_response'       => '_eventosapp_attendance_confirmation_last_response',
        'last_response_channel'=> '_eventosapp_attendance_confirmation_last_response_channel',
        'history'             => '_eventosapp_attendance_confirmation_history',
        'conflict'            => '_eventosapp_attendance_confirmation_conflict',
        'email_sent_at'       => '_eventosapp_attendance_confirmation_email_sent_at',
        'email_last_status'   => '_eventosapp_attendance_confirmation_email_last_status',
        'email_last_error'    => '_eventosapp_attendance_confirmation_email_last_error',
        'whatsapp_sent_at'    => '_eventosapp_attendance_confirmation_whatsapp_sent_at',
        'whatsapp_last_status'=> '_eventosapp_attendance_confirmation_whatsapp_last_status',
        'whatsapp_last_error' => '_eventosapp_attendance_confirmation_whatsapp_last_error',
        'whatsapp_message_id' => '_eventosapp_attendance_confirmation_whatsapp_message_id',
        'whatsapp_delivery_status' => '_eventosapp_attendance_confirmation_whatsapp_delivery_status',
        'whatsapp_delivery_at'     => '_eventosapp_attendance_confirmation_whatsapp_delivery_at',
    ];
}

function eventosapp_attendance_confirmation_status_options() {
    return [
        'si'           => 'Sí',
        'no'           => 'No',
        'no_responde'  => 'No responde',
        'sin_consulta' => 'Sin consulta',
    ];
}

function eventosapp_attendance_confirmation_channel_options() {
    return [
        'email'    => 'Correo electrónico',
        'whatsapp' => 'WhatsApp',
    ];
}

function eventosapp_attendance_confirmation_status_label($status) {
    $status = sanitize_key((string) $status);
    $options = eventosapp_attendance_confirmation_status_options();
    return $options[$status] ?? 'Sin consulta';
}

function eventosapp_attendance_confirmation_channel_label($channel) {
    $channel = sanitize_key((string) $channel);
    $options = eventosapp_attendance_confirmation_channel_options();
    return $options[$channel] ?? $channel;
}

function eventosapp_attendance_confirmation_sanitize_status($status, $fallback = 'sin_consulta') {
    $status = sanitize_key((string) $status);
    return array_key_exists($status, eventosapp_attendance_confirmation_status_options()) ? $status : $fallback;
}

/**
 * Normaliza uno o varios estados de confirmación.
 *
 * Acepta el valor escalar histórico, arreglos enviados por los nuevos
 * checkboxes y cadenas separadas por coma, espacio o "|". Mantener esta
 * compatibilidad evita romper programaciones y segmentos creados antes de
 * habilitar la selección múltiple.
 */
function eventosapp_attendance_confirmation_sanitize_statuses($statuses) {
    if ( is_string($statuses) ) {
        $decoded = json_decode($statuses, true);
        if ( is_array($decoded) ) {
            $statuses = $decoded;
        } else {
            $statuses = preg_split('/[\s,|]+/', $statuses);
        }
    } elseif ( is_scalar($statuses) ) {
        $statuses = [$statuses];
    }

    $statuses = is_array($statuses) ? $statuses : [];
    $allowed = array_keys(eventosapp_attendance_confirmation_status_options());
    $clean = [];

    foreach ( $statuses as $status ) {
        if ( is_array($status) || is_object($status) ) continue;
        $status = sanitize_key((string)$status);
        if ( in_array($status, $allowed, true) && ! in_array($status, $clean, true) ) {
            $clean[] = $status;
        }
    }

    return $clean;
}

/**
 * Conserva el formato escalar utilizado históricamente por los filtros.
 * Cuando hay varios valores se serializan con "|", formato que el
 * normalizador anterior puede volver a interpretar sin ambigüedad.
 */
function eventosapp_attendance_confirmation_serialize_statuses($statuses) {
    return implode('|', eventosapp_attendance_confirmation_sanitize_statuses($statuses));
}

function eventosapp_attendance_confirmation_sanitize_channels($channels) {
    if ( is_string($channels) ) {
        $channels = preg_split('/[\s,|]+/', $channels);
    }
    $channels = is_array($channels) ? $channels : [];
    $allowed = array_keys(eventosapp_attendance_confirmation_channel_options());
    $clean = [];
    foreach ( $channels as $channel ) {
        $channel = sanitize_key((string) $channel);
        if ( in_array($channel, $allowed, true) && ! in_array($channel, $clean, true) ) {
            $clean[] = $channel;
        }
    }
    return $clean;
}


/**
 * Registro canónico de campos de confirmación disponible para filtros,
 * métricas, exportaciones y motores de reglas.
 */
function eventosapp_attendance_confirmation_field_definitions() {
    return [
        'attendance_confirmation_status' => [
            'key'       => 'attendance_confirmation_status',
            'label'     => 'Confirmación de asistencia',
            'type'      => 'text',
            'source'    => 'computed',
            'meta_key'  => '_eventosapp_attendance_confirmation_status',
            'options'   => eventosapp_attendance_confirmation_status_options(),
        ],
        'attendance_confirmation_sent_channels' => [
            'key'       => 'attendance_confirmation_sent_channels',
            'label'     => 'Canales consultados para confirmación',
            'type'      => 'text',
            'source'    => 'computed',
            'meta_key'  => '_eventosapp_attendance_confirmation_sent_channels',
        ],
        'attendance_confirmation_response_channels' => [
            'key'       => 'attendance_confirmation_response_channels',
            'label'     => 'Canales de respuesta de confirmación',
            'type'      => 'text',
            'source'    => 'computed',
            'meta_key'  => '_eventosapp_attendance_confirmation_response_channels',
        ],
        'attendance_confirmation_last_response_channel' => [
            'key'       => 'attendance_confirmation_last_response_channel',
            'label'     => 'Último canal de respuesta',
            'type'      => 'text',
            'source'    => 'computed',
            'meta_key'  => '_eventosapp_attendance_confirmation_last_response_channel',
        ],
        'attendance_confirmation_last_response_at' => [
            'key'       => 'attendance_confirmation_last_response_at',
            'label'     => 'Fecha de última respuesta',
            'type'      => 'date',
            'source'    => 'system',
            'meta_key'  => '_eventosapp_attendance_confirmation_last_response_at',
        ],
    ];
}

/**
 * Construye una cláusula individual de estado.
 *
 * Se conserva como helper público por compatibilidad con integraciones que
 * pudieran haber empezado a utilizarlo desde la versión con selección múltiple.
 */
function eventosapp_attendance_confirmation_single_status_meta_query($status) {
    $status = eventosapp_attendance_confirmation_sanitize_status($status, '');
    if ( $status === '' ) return null;

    if ( $status === 'sin_consulta' ) {
        return [
            'relation' => 'OR',
            [
                'key'     => '_eventosapp_attendance_confirmation_status',
                'value'   => 'sin_consulta',
                'compare' => '=',
            ],
            [
                'key'     => '_eventosapp_attendance_confirmation_status',
                'compare' => 'NOT EXISTS',
            ],
        ];
    }

    return [
        'key'     => '_eventosapp_attendance_confirmation_status',
        'value'   => $status,
        'compare' => '=',
    ];
}

/**
 * Construye una consulta optimizada para uno o varios estados.
 *
 * La primera implementación generaba una cláusula OR por cada estado y, al
 * combinar "Sin consulta" con otro valor, anidaba además otro OR con
 * NOT EXISTS. WP_Meta_Query convertía esa estructura en varios JOIN sobre
 * wp_postmeta y podía volver muy lenta la creación del segmento.
 *
 * Esta versión agrupa todos los valores almacenados en una única comparación
 * IN y solo agrega NOT EXISTS cuando se solicita "Sin consulta". Si están
 * seleccionados todos los estados posibles, no agrega ningún filtro porque el
 * resultado equivale a "Todos".
 */
function eventosapp_attendance_confirmation_status_meta_query($status) {
    $statuses = eventosapp_attendance_confirmation_sanitize_statuses($status);
    if ( empty($statuses) ) return null;

    $all_statuses = array_keys(eventosapp_attendance_confirmation_status_options());
    if ( empty(array_diff($all_statuses, $statuses)) ) {
        return null;
    }

    $include_missing = in_array('sin_consulta', $statuses, true);
    $stored_clause = [
        'key'     => '_eventosapp_attendance_confirmation_status',
        'value'   => array_values($statuses),
        'compare' => 'IN',
    ];

    if ( ! $include_missing ) {
        return $stored_clause;
    }

    return [
        'relation' => 'OR',
        $stored_clause,
        [
            'key'     => '_eventosapp_attendance_confirmation_status',
            'compare' => 'NOT EXISTS',
        ],
    ];
}

function eventosapp_attendance_confirmation_get_channels($ticket_id, $type = 'sent') {
    $ticket_id = absint($ticket_id);
    if ( ! $ticket_id ) return [];
    $keys = eventosapp_attendance_confirmation_meta_keys();
    $meta_key = $type === 'response' ? $keys['response_channels'] : $keys['sent_channels'];
    return eventosapp_attendance_confirmation_sanitize_channels(get_post_meta($ticket_id, $meta_key, true));
}

function eventosapp_attendance_confirmation_format_channels($channels) {
    $labels = [];
    foreach ( eventosapp_attendance_confirmation_sanitize_channels($channels) as $channel ) {
        $labels[] = eventosapp_attendance_confirmation_channel_label($channel);
    }
    return implode(', ', $labels);
}

function eventosapp_attendance_confirmation_get_ticket_field_value($ticket_id, $field = 'attendance_confirmation_status', $display = true) {
    $ticket_id = absint($ticket_id);
    $field = sanitize_key((string) $field);
    if ( ! $ticket_id ) return '';

    switch ( $field ) {
        case 'attendance_confirmation_status':
        case 'confirmation_status':
            $status = eventosapp_attendance_confirmation_get_status($ticket_id);
            return $display ? eventosapp_attendance_confirmation_status_label($status) : $status;
        case 'attendance_confirmation_sent_channels':
            $channels = eventosapp_attendance_confirmation_get_channels($ticket_id, 'sent');
            return $display ? eventosapp_attendance_confirmation_format_channels($channels) : $channels;
        case 'attendance_confirmation_response_channels':
            $channels = eventosapp_attendance_confirmation_get_channels($ticket_id, 'response');
            return $display ? eventosapp_attendance_confirmation_format_channels($channels) : $channels;
        case 'attendance_confirmation_last_response_channel':
            $channel = sanitize_key((string)get_post_meta($ticket_id, '_eventosapp_attendance_confirmation_last_response_channel', true));
            return $display && $channel !== '' ? eventosapp_attendance_confirmation_channel_label($channel) : $channel;
        case 'attendance_confirmation_last_response_at':
            return sanitize_text_field((string)get_post_meta($ticket_id, '_eventosapp_attendance_confirmation_last_response_at', true));
    }

    return '';
}

/**
 * Filtros genéricos para módulos que ya consumen un registro común de campos.
 * Los módulos antiguos que mantienen listas propias se integran desde el archivo
 * eventosapp-attendance-confirmation-integrations.php.
 */
add_filter('eventosapp_ticket_field_registry', function($fields) {
    $fields = is_array($fields) ? $fields : [];
    return array_merge($fields, eventosapp_attendance_confirmation_field_definitions());
}, 20);

add_filter('eventosapp_ticket_filter_fields', function($fields) {
    $fields = is_array($fields) ? $fields : [];
    $fields['attendance_confirmation_status'] = 'Confirmación de asistencia';
    return $fields;
}, 20);

/**
 * Límites compartidos para procesamiento masivo. Son deliberadamente
 * conservadores y pueden ajustarse con el filtro correspondiente.
 */
function eventosapp_attendance_confirmation_bulk_limits($context = 'attendance_confirmation', $channels = []) {
    $context = sanitize_key((string)$context);
    $channels = eventosapp_attendance_confirmation_sanitize_channels($channels);
    $both_channels = count($channels) > 1;

    $limits = [
        'batch_size'         => $both_channels ? 3 : EVENTOSAPP_ATTENDANCE_CONFIRMATION_BATCH_SIZE,
        'delay_seconds'      => EVENTOSAPP_ATTENDANCE_CONFIRMATION_BATCH_DELAY,
        'ajax_delay_ms'      => 3000,
        'max_execution'      => EVENTOSAPP_ATTENDANCE_CONFIRMATION_MAX_EXECUTION_SECONDS,
        'memory_stop_ratio'  => EVENTOSAPP_ATTENDANCE_CONFIRMATION_MEMORY_STOP_RATIO,
        'lock_ttl'           => EVENTOSAPP_ATTENDANCE_CONFIRMATION_LOCK_TTL,
        'scan_size'          => 100,
    ];

    if ( $context === 'email_bulk' ) {
        $limits['batch_size'] = 5;
        $limits['ajax_delay_ms'] = 5000;
    } elseif ( $context === 'whatsapp_bulk' ) {
        $limits['batch_size'] = 5;
        $limits['ajax_delay_ms'] = 30000;
    }

    $limits = apply_filters('eventosapp_bulk_processing_limits', $limits, $context, $channels);
    $limits = is_array($limits) ? $limits : [];
    $limits['batch_size'] = max(1, min(10, absint($limits['batch_size'] ?? 5)));
    $limits['delay_seconds'] = max(10, min(300, absint($limits['delay_seconds'] ?? 30)));
    $limits['ajax_delay_ms'] = max(750, min(60000, absint($limits['ajax_delay_ms'] ?? 1800)));
    $limits['max_execution'] = max(8, min(45, absint($limits['max_execution'] ?? 20)));
    $limits['memory_stop_ratio'] = max(0.50, min(0.90, (float)($limits['memory_stop_ratio'] ?? 0.72)));
    $limits['lock_ttl'] = max(60, min(600, absint($limits['lock_ttl'] ?? 180)));
    $limits['scan_size'] = max(25, min(500, absint($limits['scan_size'] ?? 100)));
    return $limits;
}

function eventosapp_attendance_confirmation_memory_limit_bytes() {
    $value = trim((string)ini_get('memory_limit'));
    if ( $value === '' || $value === '-1' ) return 0;
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    if ( $unit === 'g' ) $number *= 1024;
    if ( in_array($unit, ['g', 'm'], true) ) $number *= 1024;
    if ( in_array($unit, ['g', 'm', 'k'], true) ) $number *= 1024;
    return max(0, (int)$number);
}

function eventosapp_attendance_confirmation_should_yield($started_at, $processed, $limits) {
    $started_at = (float)$started_at;
    $processed = absint($processed);
    $limits = is_array($limits) ? $limits : [];

    if ( $processed > 0 && (microtime(true) - $started_at) >= (float)($limits['max_execution'] ?? 20) ) {
        return true;
    }

    $memory_limit = eventosapp_attendance_confirmation_memory_limit_bytes();
    if ( $processed > 0 && $memory_limit > 0 ) {
        $ratio = memory_get_usage(true) / $memory_limit;
        if ( $ratio >= (float)($limits['memory_stop_ratio'] ?? 0.72) ) return true;
    }

    return false;
}

function eventosapp_attendance_confirmation_lock_option_name($scope) {
    return '_evapp_attendance_lock_' . substr(md5((string)$scope), 0, 24);
}

function eventosapp_attendance_confirmation_acquire_lock($scope, $ttl = EVENTOSAPP_ATTENDANCE_CONFIRMATION_LOCK_TTL) {
    $scope = sanitize_text_field((string)$scope);
    $ttl = max(30, absint($ttl));
    $option = eventosapp_attendance_confirmation_lock_option_name($scope);
    $now = time();
    $existing = get_option($option, null);

    if ( is_array($existing) && ! empty($existing['expires']) && absint($existing['expires']) <= $now ) {
        delete_option($option);
        $existing = null;
    }

    if ( is_array($existing) ) {
        return new WP_Error('bulk_locked', 'Ya existe un lote de este proceso en ejecución. Espera a que termine antes de iniciar otro.');
    }

    $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : wp_generate_password(32, false, false);
    $lock = [
        'token'      => $token,
        'scope'      => $scope,
        'created_at' => $now,
        'expires'    => $now + $ttl,
    ];

    if ( ! add_option($option, $lock, '', false) ) {
        return new WP_Error('bulk_locked', 'No se pudo obtener el bloqueo del lote porque otro proceso se adelantó.');
    }

    return $token;
}

function eventosapp_attendance_confirmation_release_lock($scope, $token) {
    $option = eventosapp_attendance_confirmation_lock_option_name($scope);
    $existing = get_option($option, null);
    if ( is_array($existing) && ! empty($existing['token']) && hash_equals((string)$existing['token'], (string)$token) ) {
        delete_option($option);
        return true;
    }
    return false;
}

function eventosapp_attendance_confirmation_get_status($ticket_id) {
    $ticket_id = absint($ticket_id);
    if ( ! $ticket_id ) return 'sin_consulta';
    $keys = eventosapp_attendance_confirmation_meta_keys();
    $stored = get_post_meta($ticket_id, $keys['status'], true);
    return eventosapp_attendance_confirmation_sanitize_status($stored, 'sin_consulta');
}

function eventosapp_attendance_confirmation_initialize_ticket($ticket_id) {
    $ticket_id = absint($ticket_id);
    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) return;
    $keys = eventosapp_attendance_confirmation_meta_keys();
    if ( ! metadata_exists('post', $ticket_id, $keys['status']) ) {
        add_post_meta($ticket_id, $keys['status'], 'sin_consulta', true);
    }
    if ( ! metadata_exists('post', $ticket_id, $keys['sent_channels']) ) {
        add_post_meta($ticket_id, $keys['sent_channels'], [], true);
    }
    if ( ! metadata_exists('post', $ticket_id, $keys['response_channels']) ) {
        add_post_meta($ticket_id, $keys['response_channels'], [], true);
    }
}
add_action('save_post_eventosapp_ticket', 'eventosapp_attendance_confirmation_initialize_ticket', 2, 1);

add_action('init', function() {
    if ( function_exists('register_post_meta') ) {
        register_post_meta('eventosapp_ticket', '_eventosapp_attendance_confirmation_status', [
            'type'              => 'string',
            'single'            => true,
            'default'           => 'sin_consulta',
            'sanitize_callback' => function($value) {
                return eventosapp_attendance_confirmation_sanitize_status($value, 'sin_consulta');
            },
            'show_in_rest'      => false,
            'auth_callback'     => function() { return current_user_can('edit_posts'); },
        ]);
    }
}, 12);

function eventosapp_attendance_confirmation_safe_history($history) {
    return is_array($history) ? $history : [];
}

function eventosapp_attendance_confirmation_append_history($ticket_id, $entry) {
    $ticket_id = absint($ticket_id);
    if ( ! $ticket_id ) return;
    $keys = eventosapp_attendance_confirmation_meta_keys();
    $history = eventosapp_attendance_confirmation_safe_history(get_post_meta($ticket_id, $keys['history'], true));
    $entry = is_array($entry) ? $entry : [];
    $entry = array_merge([
        'at'      => current_time('mysql'),
        'type'    => 'event',
        'channel' => '',
        'status'  => '',
        'message' => '',
        'source'  => '',
    ], $entry);
    $entry['at']      = sanitize_text_field((string) $entry['at']);
    $entry['type']    = sanitize_key((string) $entry['type']);
    $entry['channel'] = sanitize_key((string) $entry['channel']);
    $entry['status']  = sanitize_key((string) $entry['status']);
    $entry['message'] = sanitize_textarea_field((string) $entry['message']);
    $entry['source']  = sanitize_key((string) $entry['source']);
    if ( isset($entry['meta']) && function_exists('eventosapp_whatsapp_sanitize_log_context') ) {
        $entry['meta'] = eventosapp_whatsapp_sanitize_log_context($entry['meta']);
    }
    array_unshift($history, $entry);
    if ( count($history) > 100 ) $history = array_slice($history, 0, 100);
    update_post_meta($ticket_id, $keys['history'], $history);
}

function eventosapp_attendance_confirmation_add_unique_channel($ticket_id, $meta_key, $channel) {
    $ticket_id = absint($ticket_id);
    $channel = sanitize_key((string) $channel);
    if ( ! $ticket_id || ! array_key_exists($channel, eventosapp_attendance_confirmation_channel_options()) ) return [];
    $channels = eventosapp_attendance_confirmation_sanitize_channels(get_post_meta($ticket_id, $meta_key, true));
    if ( ! in_array($channel, $channels, true) ) $channels[] = $channel;
    update_post_meta($ticket_id, $meta_key, $channels);
    return $channels;
}

function eventosapp_attendance_confirmation_record_sent($ticket_id, $channel, $context = []) {
    $ticket_id = absint($ticket_id);
    $channel = sanitize_key((string) $channel);
    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) return false;
    if ( ! array_key_exists($channel, eventosapp_attendance_confirmation_channel_options()) ) return false;

    eventosapp_attendance_confirmation_initialize_ticket($ticket_id);
    $keys = eventosapp_attendance_confirmation_meta_keys();
    $now  = current_time('mysql');
    eventosapp_attendance_confirmation_add_unique_channel($ticket_id, $keys['sent_channels'], $channel);
    if ( ! get_post_meta($ticket_id, $keys['first_sent_at'], true) ) update_post_meta($ticket_id, $keys['first_sent_at'], $now);
    update_post_meta($ticket_id, $keys['last_sent_at'], $now);

    $status = eventosapp_attendance_confirmation_get_status($ticket_id);
    if ( ! in_array($status, ['si', 'no'], true) ) {
        update_post_meta($ticket_id, $keys['status'], 'no_responde');
        $status = 'no_responde';
    }

    if ( $channel === 'email' ) {
        update_post_meta($ticket_id, $keys['email_sent_at'], $now);
        update_post_meta($ticket_id, $keys['email_last_status'], 'enviado');
        delete_post_meta($ticket_id, $keys['email_last_error']);
    } elseif ( $channel === 'whatsapp' ) {
        update_post_meta($ticket_id, $keys['whatsapp_sent_at'], $now);
        update_post_meta($ticket_id, $keys['whatsapp_last_status'], 'aceptado_meta');
        delete_post_meta($ticket_id, $keys['whatsapp_last_error']);
    }

    eventosapp_attendance_confirmation_append_history($ticket_id, [
        'type'    => 'send',
        'channel' => $channel,
        'status'  => $status,
        'source'  => sanitize_key((string)($context['source'] ?? $context['context'] ?? 'manual')),
        'message' => 'Consulta de confirmación enviada por ' . eventosapp_attendance_confirmation_channel_label($channel) . '.',
        'meta'    => $context,
    ]);
    return true;
}

function eventosapp_attendance_confirmation_record_send_error($ticket_id, $channel, $message, $context = []) {
    $ticket_id = absint($ticket_id);
    $channel = sanitize_key((string) $channel);
    $keys = eventosapp_attendance_confirmation_meta_keys();
    if ( $channel === 'email' ) {
        update_post_meta($ticket_id, $keys['email_last_status'], 'error');
        update_post_meta($ticket_id, $keys['email_last_error'], sanitize_textarea_field((string) $message));
    } elseif ( $channel === 'whatsapp' ) {
        update_post_meta($ticket_id, $keys['whatsapp_last_status'], 'error');
        update_post_meta($ticket_id, $keys['whatsapp_last_error'], sanitize_textarea_field((string) $message));
    }
    eventosapp_attendance_confirmation_append_history($ticket_id, [
        'type'    => 'send_error',
        'channel' => $channel,
        'status'  => 'error',
        'source'  => sanitize_key((string)($context['source'] ?? $context['context'] ?? 'manual')),
        'message' => (string) $message,
        'meta'    => $context,
    ]);
}

function eventosapp_attendance_confirmation_record_response($ticket_id, $response, $channel, $context = []) {
    $ticket_id = absint($ticket_id);
    $response  = eventosapp_attendance_confirmation_sanitize_status($response, '');
    $channel   = sanitize_key((string) $channel);
    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) return new WP_Error('invalid_ticket', 'Ticket inválido.');
    if ( ! in_array($response, ['si', 'no'], true) ) return new WP_Error('invalid_response', 'Respuesta inválida.');
    if ( ! array_key_exists($channel, eventosapp_attendance_confirmation_channel_options()) ) return new WP_Error('invalid_channel', 'Canal inválido.');

    eventosapp_attendance_confirmation_initialize_ticket($ticket_id);
    $keys = eventosapp_attendance_confirmation_meta_keys();
    $previous = eventosapp_attendance_confirmation_get_status($ticket_id);
    $now = current_time('mysql');

    update_post_meta($ticket_id, $keys['status'], $response);
    update_post_meta($ticket_id, $keys['last_response'], $response);
    update_post_meta($ticket_id, $keys['last_response_at'], $now);
    update_post_meta($ticket_id, $keys['last_response_channel'], $channel);
    $channels = eventosapp_attendance_confirmation_add_unique_channel($ticket_id, $keys['response_channels'], $channel);

    if ( in_array($previous, ['si', 'no'], true) && $previous !== $response ) {
        update_post_meta($ticket_id, $keys['conflict'], '1');
    }

    eventosapp_attendance_confirmation_append_history($ticket_id, [
        'type'    => 'response',
        'channel' => $channel,
        'status'  => $response,
        'source'  => sanitize_key((string)($context['source'] ?? 'public')),
        'message' => 'El asistente respondió ' . eventosapp_attendance_confirmation_status_label($response) . ' por ' . eventosapp_attendance_confirmation_channel_label($channel) . '.',
        'meta'    => array_merge($context, [
            'previous_status' => $previous,
            'response_channels' => $channels,
        ]),
    ]);

    do_action('eventosapp_attendance_confirmation_response_recorded', $ticket_id, $response, $channel, $context);
    return [
        'ok'       => true,
        'ticket_id'=> $ticket_id,
        'status'   => $response,
        'channels' => $channels,
        'conflict' => get_post_meta($ticket_id, $keys['conflict'], true) === '1',
    ];
}

/**
 * Firma y payloads seguros para respuestas públicas y botones WhatsApp.
 */
function eventosapp_attendance_confirmation_signature($ticket_id, $response, $purpose = 'response') {
    $ticket_id = absint($ticket_id);
    $response = sanitize_key((string) $response);
    $purpose = sanitize_key((string) $purpose);
    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    return substr(hash_hmac('sha256', $purpose . '|' . $ticket_id . '|' . $event_id . '|' . $response, wp_salt('auth')), 0, 32);
}

function eventosapp_attendance_confirmation_verify_signature($ticket_id, $response, $signature, $purpose = 'response') {
    $expected = eventosapp_attendance_confirmation_signature($ticket_id, $response, $purpose);
    return is_string($signature) && $signature !== '' && hash_equals($expected, sanitize_text_field($signature));
}

function eventosapp_attendance_confirmation_public_response_url($ticket_id, $response) {
    $ticket_id = absint($ticket_id);
    $response = in_array(sanitize_key((string) $response), ['si','no'], true) ? sanitize_key((string) $response) : 'si';
    return add_query_arg([
        'eventosapp_attendance_confirmation' => '1',
        'ticket_id' => $ticket_id,
        'response'  => $response,
        'token'     => eventosapp_attendance_confirmation_signature($ticket_id, $response, 'email'),
    ], home_url('/'));
}

function eventosapp_attendance_confirmation_whatsapp_payload($ticket_id, $response) {
    $ticket_id = absint($ticket_id);
    $response = in_array(sanitize_key((string) $response), ['si','no'], true) ? sanitize_key((string) $response) : 'si';
    $sig = eventosapp_attendance_confirmation_signature($ticket_id, $response, 'whatsapp');
    return 'EVAPP_RSVP|' . $response . '|' . $ticket_id . '|' . $sig;
}

function eventosapp_attendance_confirmation_parse_whatsapp_payload($payload) {
    $payload = trim((string) $payload);
    if ( ! preg_match('/^EVAPP_RSVP\|(si|no)\|(\d+)\|([a-f0-9]{32})$/i', $payload, $m) ) return null;
    $response = sanitize_key(strtolower($m[1]));
    $ticket_id = absint($m[2]);
    $sig = strtolower($m[3]);
    if ( ! eventosapp_attendance_confirmation_verify_signature($ticket_id, $response, $sig, 'whatsapp') ) return null;
    return ['ticket_id' => $ticket_id, 'response' => $response];
}

function eventosapp_attendance_confirmation_render_public_result($ticket_id, $response, $ok, $message = '') {
    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $event_name = $event_id ? get_the_title($event_id) : 'el evento';
    $title = $ok ? 'Confirmación registrada' : 'No pudimos registrar tu respuesta';
    $badge = $ok ? eventosapp_attendance_confirmation_status_label($response) : 'Error';
    status_header($ok ? 200 : 400);
    nocache_headers();
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html($title); ?></title><style>
    body{margin:0;background:#f4f7fb;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#172033}.wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}.card{width:min(560px,100%);background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(20,40,80,.12);padding:38px;text-align:center}.icon{width:72px;height:72px;border-radius:50%;display:grid;place-items:center;margin:0 auto 20px;background:<?php echo $ok ? '#e8f8ef' : '#fdecec'; ?>;font-size:34px}.badge{display:inline-block;padding:8px 16px;border-radius:999px;background:#eef3ff;color:#2745a6;font-weight:700;margin:8px 0 18px}.card h1{font-size:28px;margin:0 0 12px}.card p{font-size:16px;line-height:1.6;color:#586174;margin:8px 0}.event{font-weight:700;color:#172033}.small{font-size:13px!important;margin-top:24px!important}</style></head><body><main class="wrap"><section class="card"><div class="icon"><?php echo $ok ? '✓' : '!'; ?></div><h1><?php echo esc_html($title); ?></h1><div class="badge"><?php echo esc_html($badge); ?></div><p><?php echo $ok ? 'Tu respuesta para <span class="event">' . esc_html($event_name) . '</span> quedó guardada correctamente.' : esc_html($message); ?></p><?php if ($ok): ?><p>Puedes cerrar esta ventana. Si cambias de decisión, puedes volver a usar el enlace recibido y quedará registrada la respuesta más reciente.</p><?php endif; ?><p class="small">EventosApp</p></section></main></body></html><?php
    exit;
}

add_action('template_redirect', function() {
    if ( empty($_GET['eventosapp_attendance_confirmation']) ) return;
    $ticket_id = absint($_GET['ticket_id'] ?? 0);
    $response  = sanitize_key((string)($_GET['response'] ?? ''));
    $token     = sanitize_text_field((string)($_GET['token'] ?? ''));
    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' || ! in_array($response, ['si','no'], true) || ! eventosapp_attendance_confirmation_verify_signature($ticket_id, $response, $token, 'email') ) {
        eventosapp_attendance_confirmation_render_public_result($ticket_id, $response, false, 'El enlace no es válido o está incompleto.');
    }
    $result = eventosapp_attendance_confirmation_record_response($ticket_id, $response, 'email', [
        'source' => 'email_link',
        'ip' => sanitize_text_field((string)($_SERVER['REMOTE_ADDR'] ?? '')),
        'user_agent' => sanitize_text_field((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
    ]);
    eventosapp_attendance_confirmation_render_public_result($ticket_id, $response, ! is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
}, 0);

/**
 * Datos del evento y ticket usados en mensajes.
 */
function eventosapp_attendance_confirmation_event_dates($event_id) {
    $event_id = absint($event_id);
    if ( ! $event_id ) return [];

    $normalize = static function($value) {
        $value = sanitize_text_field((string)$value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    };

    if ( function_exists('eventosapp_get_event_days') ) {
        $existing = eventosapp_get_event_days($event_id);
        if ( is_array($existing) ) {
            $days = [];
            foreach ( $existing as $day ) {
                $day = $normalize($day);
                if ( $day !== '' ) $days[] = $day;
            }
            $days = array_values(array_unique($days));
            sort($days);
            if ( ! empty($days) ) return $days;
        }
    }

    $type = sanitize_key((string)get_post_meta($event_id, '_eventosapp_tipo_fecha', true));
    $days = [];

    if ( $type === 'consecutiva' ) {
        $start = $normalize(get_post_meta($event_id, '_eventosapp_fecha_inicio', true));
        $end   = $normalize(get_post_meta($event_id, '_eventosapp_fecha_fin', true));
        if ( $start !== '' && $end !== '' && $start <= $end ) {
            try {
                $cursor = new DateTimeImmutable($start . ' 00:00:00');
                $limit  = new DateTimeImmutable($end . ' 00:00:00');
                $guard  = 0;
                while ( $cursor <= $limit && $guard < 3660 ) {
                    $days[] = $cursor->format('Y-m-d');
                    $cursor = $cursor->modify('+1 day');
                    $guard++;
                }
            } catch (Throwable $e) {
                $days = [];
            }
        }
    } elseif ( $type === 'noconsecutiva' ) {
        $stored = get_post_meta($event_id, '_eventosapp_fechas_noco', true);
        foreach ( is_array($stored) ? $stored : [] as $day ) {
            $day = $normalize($day);
            if ( $day !== '' ) $days[] = $day;
        }
    } else {
        $single = $normalize(get_post_meta($event_id, '_eventosapp_fecha_unica', true));
        if ( $single !== '' ) $days[] = $single;
    }

    if ( empty($days) ) {
        foreach (['_eventosapp_fecha_evento','_eventosapp_fecha_inicio','_eventosapp_fecha'] as $key) {
            $day = $normalize(get_post_meta($event_id, $key, true));
            if ( $day !== '' ) $days[] = $day;
        }
    }

    $days = array_values(array_unique($days));
    sort($days);
    return $days;
}

function eventosapp_attendance_confirmation_event_date_label($event_id) {
    $event_id = absint($event_id);
    if ( function_exists('eventosapp_whatsapp_get_event_date_label') ) {
        $label = eventosapp_whatsapp_get_event_date_label($event_id);
        if ( $label ) return $label;
    }
    $days = eventosapp_attendance_confirmation_event_dates($event_id);
    if ( ! empty($days) ) {
        $format_day = static function($day) {
            $ts = strtotime((string)$day);
            return $ts ? date_i18n(get_option('date_format'), $ts) : sanitize_text_field((string)$day);
        };
        $type = sanitize_key((string)get_post_meta($event_id, '_eventosapp_tipo_fecha', true));
        if ( $type === 'consecutiva' && count($days) > 1 ) {
            return $format_day(reset($days)) . ' al ' . $format_day(end($days));
        }
        return implode(', ', array_map($format_day, $days));
    }
    return 'Fecha por confirmar';
}

function eventosapp_attendance_confirmation_template_values($ticket_id) {
    $ticket_id = absint($ticket_id);
    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $first = trim((string)get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true));
    $last  = trim((string)get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true));
    $full  = trim($first . ' ' . $last);
    if ( $full === '' ) $full = 'Asistente';
    $address = trim((string)get_post_meta($event_id, '_eventosapp_direccion', true));
    if ( $address === '' ) $address = trim((string)get_post_meta($event_id, '_eventosapp_virtual_platform', true));
    if ( $address === '' ) $address = 'Lugar por confirmar';
    $start = trim((string)get_post_meta($event_id, '_eventosapp_hora_inicio', true));
    $end   = trim((string)get_post_meta($event_id, '_eventosapp_hora_cierre', true));
    $time  = trim($start . ($end !== '' ? ' - ' . $end : ''));
    if ( $time === '' ) $time = 'Hora por confirmar';
    return [
        'ticket_id'      => $ticket_id,
        'event_id'       => $event_id,
        'nombre'         => $first,
        'apellido'       => $last,
        'nombre_completo'=> $full,
        'evento_nombre'  => $event_id ? get_the_title($event_id) : 'Evento',
        'evento_fecha'   => eventosapp_attendance_confirmation_event_date_label($event_id),
        'evento_hora'    => $time,
        'evento_lugar'   => $address,
        'localidad'      => get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true),
        'modalidad'      => function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label($ticket_id) : '',
        'url_si'         => eventosapp_attendance_confirmation_public_response_url($ticket_id, 'si'),
        'url_no'         => eventosapp_attendance_confirmation_public_response_url($ticket_id, 'no'),
    ];
}

function eventosapp_attendance_confirmation_replace_tokens($text, $values) {
    $replace = [];
    foreach ( (array) $values as $key => $value ) {
        $replace['{{' . $key . '}}'] = is_scalar($value) ? (string)$value : '';
    }
    return strtr((string) $text, $replace);
}

/**
 * Plantillas de correo del módulo.
 */
function eventosapp_attendance_confirmation_email_templates_dir() {
    return trailingslashit(dirname(__DIR__)) . 'templates/email_confirmations/';
}

function eventosapp_attendance_confirmation_email_templates() {
    $templates = [];
    $dir = eventosapp_attendance_confirmation_email_templates_dir();
    if ( is_dir($dir) ) {
        foreach ( (array)glob($dir . '*.html') as $file ) {
            $base = basename($file);
            $templates[$base] = ucwords(str_replace(['-','_'], ' ', preg_replace('/\.html$/i', '', $base)));
        }
    }
    if ( empty($templates) ) $templates['attendance-confirmation.html'] = 'Confirmación de asistencia';
    return $templates;
}

function eventosapp_attendance_confirmation_email_template_html($template_name) {
    $template_name = sanitize_file_name((string) $template_name);
    if ( $template_name === '' ) $template_name = 'attendance-confirmation.html';
    $path = eventosapp_attendance_confirmation_email_templates_dir() . $template_name;
    if ( file_exists($path) && is_readable($path) ) return (string) file_get_contents($path);
    return '<!doctype html><html><body style="margin:0;background:#f4f6f8;font-family:Arial,sans-serif"><table width="100%" role="presentation"><tr><td align="center" style="padding:24px"><table width="620" style="max-width:620px;background:#fff;border-radius:12px;overflow:hidden"><tr><td>{{header_block}}</td></tr><tr><td style="padding:32px"><h1 style="font-size:26px">Confirma tu asistencia</h1><p>Hola <strong>{{nombre_completo}}</strong>, queremos confirmar tu asistencia a <strong>{{evento_nombre}}</strong>.</p><p><strong>Fecha:</strong> {{evento_fecha}}<br><strong>Hora:</strong> {{evento_hora}}<br><strong>Lugar:</strong> {{evento_lugar}}</p><p style="text-align:center"><a href="{{url_si}}" style="display:inline-block;background:#16854b;color:#fff;padding:13px 24px;border-radius:7px;text-decoration:none;margin:5px">Sí, asistiré</a><a href="{{url_no}}" style="display:inline-block;background:#b42318;color:#fff;padding:13px 24px;border-radius:7px;text-decoration:none;margin:5px">No podré asistir</a></p>{{custom_message}}</td></tr></table></td></tr></table></body></html>';
}

function eventosapp_attendance_confirmation_build_email_html($ticket_id, $args = []) {
    $ticket_id = absint($ticket_id);
    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $args = wp_parse_args(is_array($args) ? $args : [], [
        'template' => 'attendance-confirmation.html',
        'message'  => '',
    ]);
    $values = eventosapp_attendance_confirmation_template_values($ticket_id);
    $header_url = $event_id ? esc_url_raw((string)get_post_meta($event_id, '_eventosapp_email_header_img', true)) : '';
    if ( $header_url === '' ) $header_url = 'https://eventosapp.com/wp-content/uploads/2025/08/header_ticket_gen.jpg';
    $values['header_image'] = $header_url;
    $values['header_block'] = $header_url !== '' ? '<img src="' . esc_url($header_url) . '" alt="' . esc_attr($values['evento_nombre']) . '" style="display:block;width:100%;height:auto;border:0">' : '';
    $custom_message = trim((string)$args['message']);
    $values['custom_message'] = $custom_message !== '' ? '<div style="margin-top:24px;padding:16px;background:#f5f7fb;border-radius:8px;line-height:1.6">' . wpautop(wp_kses_post($custom_message)) . '</div>' : '';
    $html = eventosapp_attendance_confirmation_email_template_html($args['template']);
    return eventosapp_attendance_confirmation_replace_tokens($html, $values);
}

function eventosapp_attendance_confirmation_email_headers($event_id) {
    $event_id = absint($event_id);
    $site_host = parse_url(home_url(), PHP_URL_HOST);
    $site_host = preg_replace('/^www\./i', '', (string)$site_host);
    $from_email = sanitize_email('no-reply@' . ($site_host ?: 'localhost'));
    if ( ! $from_email ) $from_email = sanitize_email(get_option('admin_email'));
    $from_name = function_exists('eventosapp_event_from_name') ? eventosapp_event_from_name($event_id) : wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . preg_replace('/[\r\n]+/', ' ', $from_name) . ' <' . $from_email . '>',
    ];
    $reply_email = sanitize_email((string)get_post_meta($event_id, '_eventosapp_organizador_email', true));
    if ( $reply_email ) $headers[] = 'Reply-To: ' . $reply_email;
    return $headers;
}

function eventosapp_attendance_confirmation_send_email($ticket_id, $args = []) {
    $ticket_id = absint($ticket_id);
    $args = wp_parse_args(is_array($args) ? $args : [], [
        'template' => 'attendance-confirmation.html',
        'subject'  => 'Confirma tu asistencia a {{evento_nombre}}',
        'message'    => '',
        'source'     => 'manual',
        'source_key' => '',
        'force'      => false,
    ]);
    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) return ['ok'=>false,'message'=>'Ticket inválido.'];
    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $email = sanitize_email((string)get_post_meta($ticket_id, '_eventosapp_asistente_email', true));
    if ( ! $event_id ) return ['ok'=>false,'message'=>'Ticket sin evento asociado.'];

    $source_key = sanitize_text_field((string)$args['source_key']);
    if ( $source_key !== '' && empty($args['force']) ) {
        $last_source = get_post_meta($ticket_id, '_eventosapp_attendance_confirmation_email_last_source_key', true);
        if ( hash_equals((string)$last_source, $source_key) ) {
            return ['ok'=>true,'message'=>'Correo omitido porque este envío ya fue procesado.','skipped_duplicate'=>true,'recipient'=>$email];
        }
    }

    if ( ! $email ) {
        eventosapp_attendance_confirmation_record_send_error($ticket_id, 'email', 'El ticket no tiene correo electrónico válido.', $args);
        return ['ok'=>false,'message'=>'El ticket no tiene correo electrónico válido.'];
    }
    $values = eventosapp_attendance_confirmation_template_values($ticket_id);
    $subject = wp_strip_all_tags(eventosapp_attendance_confirmation_replace_tokens($args['subject'], $values));
    if ( $subject === '' ) $subject = 'Confirma tu asistencia a ' . $values['evento_nombre'];
    $html = eventosapp_attendance_confirmation_build_email_html($ticket_id, $args);
    $ok = wp_mail($email, $subject, $html, eventosapp_attendance_confirmation_email_headers($event_id));
    if ( $ok ) {
        eventosapp_attendance_confirmation_record_sent($ticket_id, 'email', array_merge($args, ['recipient'=>$email]));
        if ( $source_key !== '' ) {
            update_post_meta($ticket_id, '_eventosapp_attendance_confirmation_email_last_source_key', $source_key);
        }
        return ['ok'=>true,'message'=>'Correo aceptado por WordPress para envío.','recipient'=>$email];
    }
    eventosapp_attendance_confirmation_record_send_error($ticket_id, 'email', 'wp_mail() no aceptó el correo.', array_merge($args, ['recipient'=>$email]));
    return ['ok'=>false,'message'=>'WordPress no pudo aceptar el correo para envío.'];
}

/**
 * Plantilla WhatsApp de confirmación.
 */
function eventosapp_attendance_confirmation_whatsapp_template_id() {
    return 'attendance_confirmation';
}

function eventosapp_attendance_confirmation_get_whatsapp_template($template_id = '') {
    $template_id = sanitize_key((string)$template_id);
    if ( $template_id === '' ) $template_id = eventosapp_attendance_confirmation_whatsapp_template_id();
    if ( ! function_exists('eventosapp_whatsapp_templates_get_settings') ) return null;
    $settings = eventosapp_whatsapp_templates_get_settings();
    $templates = is_array($settings['templates'] ?? null) ? $settings['templates'] : [];
    if ( isset($templates[$template_id]) && is_array($templates[$template_id]) ) return $templates[$template_id];
    foreach ( $templates as $key => $template ) {
        if ( ! is_array($template) ) continue;
        if ( sanitize_key((string)($template['id'] ?? $key)) === $template_id || sanitize_key((string)($template['name'] ?? '')) === $template_id ) return $template;
    }
    return null;
}

function eventosapp_attendance_confirmation_get_whatsapp_templates($only_approved = false) {
    if ( ! function_exists('eventosapp_whatsapp_templates_get_settings') ) return [];
    $settings = eventosapp_whatsapp_templates_get_settings();
    $templates = is_array($settings['templates'] ?? null) ? $settings['templates'] : [];
    $out = [];
    foreach ( $templates as $key => $template ) {
        if ( ! is_array($template) || empty($template['attendance_confirmation']) ) continue;
        $approved = function_exists('eventosapp_whatsapp_is_template_approved')
            ? eventosapp_whatsapp_is_template_approved($template)
            : in_array(strtoupper((string)($template['meta_status'] ?? '')), ['APPROVED','ACTIVE'], true);
        if ( $only_approved && ! $approved ) continue;
        $id = sanitize_key((string)($template['id'] ?? $key));
        $out[$id] = $template;
    }
    return $out;
}

function eventosapp_attendance_confirmation_whatsapp_body_values($ticket_id) {
    $values = eventosapp_attendance_confirmation_template_values($ticket_id);
    return [
        1 => $values['nombre_completo'],
        2 => $values['evento_nombre'],
        3 => $values['evento_fecha'],
        4 => $values['evento_hora'],
        5 => $values['evento_lugar'],
        6 => $values['modalidad'],
    ];
}

function eventosapp_attendance_confirmation_build_whatsapp_components($template, $ticket_id, $event_id) {
    $components = [];
    $header_format = strtoupper(sanitize_key((string)($template['header_format'] ?? 'NONE')));
    if ( $header_format === 'IMAGE' ) {
        $image = esc_url_raw((string)get_post_meta($event_id, '_eventosapp_attendance_confirmation_whatsapp_image', true));
        if ( $image === '' ) {
            return ['ok'=>false,'message'=>'La plantilla exige imagen, pero el evento no tiene foto de confirmación configurada.','components'=>[]];
        }
        $components[] = [
            'type' => 'header',
            'parameters' => [[
                'type' => 'image',
                'image' => ['link' => $image],
            ]],
        ];
    }

    $body_map = $template['body_variable_map'] ?? [1,2,3,4,5];
    if ( is_string($body_map) ) {
        $decoded = json_decode($body_map, true);
        $body_map = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $body_map);
    }
    $body_map = is_array($body_map) ? array_values(array_unique(array_filter(array_map('absint', $body_map)))) : [];
    $body_values = eventosapp_attendance_confirmation_whatsapp_body_values($ticket_id);
    if ( ! empty($body_map) ) {
        $params = [];
        foreach ( $body_map as $number ) {
            $params[] = ['type'=>'text','text'=>sanitize_text_field((string)($body_values[$number] ?? '-'))];
        }
        $components[] = ['type'=>'body','parameters'=>$params];
    }

    $components[] = [
        'type' => 'button',
        'sub_type' => 'quick_reply',
        'index' => '0',
        'parameters' => [[
            'type' => 'payload',
            'payload' => eventosapp_attendance_confirmation_whatsapp_payload($ticket_id, 'si'),
        ]],
    ];
    $components[] = [
        'type' => 'button',
        'sub_type' => 'quick_reply',
        'index' => '1',
        'parameters' => [[
            'type' => 'payload',
            'payload' => eventosapp_attendance_confirmation_whatsapp_payload($ticket_id, 'no'),
        ]],
    ];
    return ['ok'=>true,'message'=>'Componentes preparados.','components'=>$components];
}

function eventosapp_attendance_confirmation_send_whatsapp($ticket_id, $args = []) {
    $ticket_id = absint($ticket_id);
    $args = wp_parse_args(is_array($args) ? $args : [], [
        'template_id' => eventosapp_attendance_confirmation_whatsapp_template_id(),
        'source'      => 'manual',
        'force'       => false,
        'source_key'  => '',
    ]);
    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) return ['ok'=>false,'message'=>'Ticket inválido.'];
    foreach (['eventosapp_whatsapp_get_settings','eventosapp_whatsapp_resolve_sender_settings','eventosapp_whatsapp_normalize_phone','eventosapp_whatsapp_api_send_message','eventosapp_whatsapp_build_template_payload'] as $required) {
        if ( ! function_exists($required) ) return ['ok'=>false,'message'=>'El módulo WhatsApp no está cargado: falta ' . $required . '().'];
    }
    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    if ( ! $event_id ) return ['ok'=>false,'message'=>'Ticket sin evento asociado.'];
    $template = eventosapp_attendance_confirmation_get_whatsapp_template($args['template_id']);
    if ( ! is_array($template) || empty($template['attendance_confirmation']) ) {
        $msg = 'La plantilla de confirmación seleccionada no existe.';
        eventosapp_attendance_confirmation_record_send_error($ticket_id, 'whatsapp', $msg, $args);
        return ['ok'=>false,'message'=>$msg];
    }
    $approved = function_exists('eventosapp_whatsapp_is_template_approved')
        ? eventosapp_whatsapp_is_template_approved($template)
        : in_array(strtoupper((string)($template['meta_status'] ?? '')), ['APPROVED','ACTIVE'], true);
    if ( ! $approved ) {
        $msg = 'La plantilla de confirmación todavía no está aprobada por Meta.';
        eventosapp_attendance_confirmation_record_send_error($ticket_id, 'whatsapp', $msg, $args);
        return ['ok'=>false,'message'=>$msg];
    }

    $base_settings = eventosapp_whatsapp_get_settings();
    $template_sender = function_exists('eventosapp_whatsapp_sanitize_phone_number_id')
        ? eventosapp_whatsapp_sanitize_phone_number_id($template['sender_phone_number_id'] ?? '')
        : preg_replace('/\D+/', '', (string)($template['sender_phone_number_id'] ?? ''));

    if ( $template_sender !== '' && function_exists('eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id') ) {
        $settings = eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id($template_sender, $base_settings);
        $resolved_sender = preg_replace('/\D+/', '', (string)($settings['phone_number_id'] ?? ''));
        if ( $resolved_sender !== $template_sender ) {
            $msg = 'El número emisor asignado a la plantilla ya no está disponible en la configuración de WhatsApp.';
            eventosapp_attendance_confirmation_record_send_error($ticket_id, 'whatsapp', $msg, $args);
            return ['ok'=>false,'message'=>$msg];
        }
    } else {
        $settings = eventosapp_whatsapp_resolve_sender_settings($event_id, $base_settings);
    }

    $phone_raw = get_post_meta($ticket_id, '_eventosapp_asistente_tel', true);
    $phone = eventosapp_whatsapp_normalize_phone($phone_raw, $settings['default_country_code'] ?? '57');
    if ( ! $phone ) {
        $msg = 'El ticket no tiene celular válido para WhatsApp.';
        eventosapp_attendance_confirmation_record_send_error($ticket_id, 'whatsapp', $msg, $args);
        return ['ok'=>false,'message'=>$msg];
    }

    $source_key = sanitize_text_field((string)$args['source_key']);
    if ( ! empty($source_key) && empty($args['force']) ) {
        $last_source = get_post_meta($ticket_id, '_eventosapp_attendance_confirmation_whatsapp_last_source_key', true);
        if ( $last_source === $source_key ) return ['ok'=>true,'message'=>'Envío omitido porque ya fue procesado.','skipped_duplicate'=>true];
    }

    $components = eventosapp_attendance_confirmation_build_whatsapp_components($template, $ticket_id, $event_id);
    if ( empty($components['ok']) ) {
        eventosapp_attendance_confirmation_record_send_error($ticket_id, 'whatsapp', $components['message'], $args);
        return ['ok'=>false,'message'=>$components['message']];
    }

    $payload = eventosapp_whatsapp_build_template_payload(
        sanitize_key((string)($template['name'] ?? '')),
        sanitize_text_field((string)($template['language'] ?? 'es')),
        $components['components']
    );
    $result = eventosapp_whatsapp_api_send_message($phone, $payload, $settings);
    $message_id = sanitize_text_field((string)($result['message_id'] ?? (function_exists('eventosapp_whatsapp_extract_message_id') ? eventosapp_whatsapp_extract_message_id($result['response'] ?? []) : '')));
    $keys = eventosapp_attendance_confirmation_meta_keys();

    if ( ! empty($result['ok']) ) {
        eventosapp_attendance_confirmation_record_sent($ticket_id, 'whatsapp', array_merge($args, [
            'recipient' => $phone,
            'template_name' => $template['name'] ?? '',
            'message_id' => $message_id,
        ]));
        if ( $message_id !== '' ) {
            update_post_meta($ticket_id, $keys['whatsapp_message_id'], $message_id);
            if ( function_exists('eventosapp_whatsapp_register_message_map') ) eventosapp_whatsapp_register_message_map($message_id, $ticket_id, 'attendance_confirmation', $phone);
        }
        update_post_meta($ticket_id, $keys['whatsapp_delivery_status'], 'pendiente_webhook');
        if ( $source_key !== '' ) update_post_meta($ticket_id, '_eventosapp_attendance_confirmation_whatsapp_last_source_key', $source_key);
        if ( function_exists('eventosapp_whatsapp_insert_central_log') ) {
            eventosapp_whatsapp_insert_central_log([
                'event_id' => $event_id,
                'ticket_id'=> $ticket_id,
                'recipient'=> $phone,
                'channel'  => 'confirmacion_asistencia',
                'context'  => sanitize_key((string)$args['source']),
                'status'   => 'aceptado_meta',
                'delivery_status' => 'pendiente_webhook',
                'message_id' => $message_id,
                'source_key' => $source_key,
                'sender_phone_number_id' => $settings['sender_phone_number_id'] ?? $settings['phone_number_id'] ?? '',
                'sender_label' => $settings['sender_phone_label'] ?? '',
                'transport' => 'template_quick_reply',
                'template_name' => $template['name'] ?? '',
                'http_code' => absint($result['http_code'] ?? 0),
                'message' => $result['message'] ?? 'Solicitud aceptada por Meta.',
                'meta' => ['attendance_confirmation'=>1,'response_buttons'=>['si','no']],
            ]);
        }
        return array_merge($result, ['message_id'=>$message_id,'recipient'=>$phone]);
    }

    $message = (string)($result['message'] ?? 'Error enviando WhatsApp.');
    eventosapp_attendance_confirmation_record_send_error($ticket_id, 'whatsapp', $message, array_merge($args, ['recipient'=>$phone]));
    return $result;
}

function eventosapp_attendance_confirmation_send_ticket($ticket_id, $config = []) {
    $ticket_id = absint($ticket_id);
    $config = wp_parse_args(is_array($config) ? $config : [], [
        'channels' => ['email'],
        'email_template' => 'attendance-confirmation.html',
        'email_subject'  => 'Confirma tu asistencia a {{evento_nombre}}',
        'email_message'  => '',
        'whatsapp_template_id' => eventosapp_attendance_confirmation_whatsapp_template_id(),
        'source' => 'manual',
        'source_key' => '',
        'force' => false,
    ]);
    $channels = eventosapp_attendance_confirmation_sanitize_channels($config['channels']);
    if ( empty($channels) ) return ['ok'=>false,'message'=>'No se seleccionó ningún canal.','results'=>[]];
    $results = [];
    foreach ( $channels as $channel ) {
        if ( $channel === 'email' ) {
            $results['email'] = eventosapp_attendance_confirmation_send_email($ticket_id, [
                'template' => $config['email_template'],
                'subject'  => $config['email_subject'],
                'message'  => $config['email_message'],
                'source'    => $config['source'],
                'source_key' => $config['source_key'],
                'force'      => ! empty($config['force']),
            ]);
        } elseif ( $channel === 'whatsapp' ) {
            $results['whatsapp'] = eventosapp_attendance_confirmation_send_whatsapp($ticket_id, [
                'template_id' => $config['whatsapp_template_id'],
                'source'      => $config['source'],
                'source_key'  => $config['source_key'],
                'force'       => ! empty($config['force']),
            ]);
        }
    }
    $ok_count = count(array_filter($results, function($result){ return is_array($result) && ! empty($result['ok']); }));
    return [
        'ok' => $ok_count > 0,
        'partial' => $ok_count > 0 && $ok_count < count($results),
        'message' => $ok_count === count($results) ? 'Todos los canales fueron procesados correctamente.' : ($ok_count > 0 ? 'Algunos canales fueron procesados y otros presentaron error.' : 'No se pudo procesar ningún canal.'),
        'results' => $results,
    ];
}

/**
 * Procesamiento de respuestas WhatsApp.
 */
function eventosapp_attendance_confirmation_extract_whatsapp_candidate($message) {
    $candidates = [];
    if ( ! empty($message['interactive']['button_reply']['id']) ) $candidates[] = $message['interactive']['button_reply']['id'];
    if ( ! empty($message['button']['payload']) ) $candidates[] = $message['button']['payload'];
    if ( ! empty($message['interactive']['button_reply']['title']) ) $candidates[] = $message['interactive']['button_reply']['title'];
    if ( ! empty($message['button']['text']) ) $candidates[] = $message['button']['text'];
    if ( ! empty($message['text']['body']) ) $candidates[] = $message['text']['body'];
    foreach ( $candidates as $candidate ) {
        $parsed = eventosapp_attendance_confirmation_parse_whatsapp_payload($candidate);
        if ( $parsed ) return $parsed;
    }
    return null;
}

add_action('eventosapp_whatsapp_webhook_inbound_message_received', function($message, $value = [], $entry = [], $change = [], $payload = []) {
    if ( ! is_array($message) ) return;
    $parsed = eventosapp_attendance_confirmation_extract_whatsapp_candidate($message);
    if ( ! $parsed ) return;
    eventosapp_attendance_confirmation_record_response($parsed['ticket_id'], $parsed['response'], 'whatsapp', [
        'source' => 'whatsapp_quick_reply',
        'message_id' => sanitize_text_field((string)($message['id'] ?? '')),
        'from' => sanitize_text_field((string)($message['from'] ?? '')),
        'reply_to_message_id' => sanitize_text_field((string)($message['context']['id'] ?? '')),
    ]);
}, 5, 5);

add_action('eventosapp_whatsapp_webhook_status_received', function($status, $mapped = [], $debug = []) {
    $message_id = sanitize_text_field((string)($status['id'] ?? $debug['message_id'] ?? ''));
    $ticket_id = absint($mapped['ticket_id'] ?? $debug['ticket_id'] ?? 0);
    if ( ! $ticket_id && $message_id !== '' ) {
        $query = new WP_Query([
            'post_type'=>'eventosapp_ticket','post_status'=>'any','fields'=>'ids','posts_per_page'=>1,'no_found_rows'=>true,
            'meta_key'=>'_eventosapp_attendance_confirmation_whatsapp_message_id','meta_value'=>$message_id,
        ]);
        $ticket_id = absint($query->posts[0] ?? 0);
    }
    if ( ! $ticket_id ) return;
    $keys = eventosapp_attendance_confirmation_meta_keys();
    $stored_message = get_post_meta($ticket_id, $keys['whatsapp_message_id'], true);
    if ( $message_id !== '' && $stored_message !== '' && $stored_message !== $message_id ) return;
    $delivery = sanitize_key((string)($status['status'] ?? $mapped['delivery_status'] ?? $debug['delivery_status'] ?? ''));
    if ( $delivery === '' ) return;
    update_post_meta($ticket_id, $keys['whatsapp_delivery_status'], $delivery);
    update_post_meta($ticket_id, $keys['whatsapp_delivery_at'], current_time('mysql'));
    eventosapp_attendance_confirmation_append_history($ticket_id, [
        'type'=>'delivery','channel'=>'whatsapp','status'=>$delivery,'source'=>'whatsapp_webhook',
        'message'=>'Estado de entrega WhatsApp: ' . $delivery . '.',
        'meta'=>['message_id'=>$message_id],
    ]);
}, 20, 3);

/**
 * Segmentación reutilizable por envío masivo y cron.
 */
function eventosapp_attendance_confirmation_sanitize_filters($filters) {
    $filters = is_array($filters) ? $filters : [];
    $clean = [];
    $scalar_keys = [
        'evento_id','localidad','modalidad','email_status','whatsapp_status',
        'delivery_status','created_from','created_to','event_date','creation_channel','search',
    ];
    foreach ( $scalar_keys as $key ) {
        if ( isset($filters[$key]) && is_scalar($filters[$key]) ) {
            $value = sanitize_text_field((string)$filters[$key]);
            if ( $value !== '' ) $clean[$key] = $value;
        }
    }

    if ( array_key_exists('confirmation_status', $filters) ) {
        $confirmation_statuses = eventosapp_attendance_confirmation_sanitize_statuses($filters['confirmation_status']);
        if ( count($confirmation_statuses) === 1 ) {
            // Conserva el valor escalar histórico cuando solo se escoge un estado.
            $clean['confirmation_status'] = reset($confirmation_statuses);
        } elseif ( count($confirmation_statuses) > 1 ) {
            // Los múltiples estados se conservan como arreglo; no se serializan
            // en una cadena artificial antes de construir la consulta.
            $clean['confirmation_status'] = array_values($confirmation_statuses);
        }
    }

    if ( ! empty($filters['extra_fields']) && is_array($filters['extra_fields']) ) {
        $clean['extra_fields'] = [];
        foreach ( $filters['extra_fields'] as $key => $value ) {
            $key = sanitize_key((string)$key);
            $value = is_scalar($value) ? sanitize_text_field((string)$value) : '';
            if ( $key !== '' && $value !== '' ) $clean['extra_fields'][$key] = $value;
        }
        if ( empty($clean['extra_fields']) ) unset($clean['extra_fields']);
    }
    return $clean;
}

function eventosapp_attendance_confirmation_build_query_args($filters, $posts_per_page = -1) {
    $filters = eventosapp_attendance_confirmation_sanitize_filters($filters);
    $args = [
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'posts_per_page' => (int)$posts_per_page,
        'no_found_rows'  => true,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'cache_results'  => false,
    ];
    $meta_query = ['relation'=>'AND'];
    $date_query = [];

    if ( ! empty($filters['evento_id']) ) $meta_query[] = ['key'=>'_eventosapp_ticket_evento_id','value'=>absint($filters['evento_id']),'compare'=>'='];
    if ( ! empty($filters['localidad']) ) $meta_query[] = ['key'=>'_eventosapp_asistente_localidad','value'=>$filters['localidad'],'compare'=>'='];

    if ( ! empty($filters['confirmation_status']) ) {
        $confirmation_statuses = eventosapp_attendance_confirmation_sanitize_statuses($filters['confirmation_status']);
        $contains_legacy_missing = in_array('sin_consulta', $confirmation_statuses, true);

        /*
         * La combinación de varios estados con "Sin consulta" requiere mezclar
         * valores existentes con registros que no tienen metakey. Hacerlo como
         * meta_query produce JOIN adicionales y fue el origen de la espera larga
         * al crear el segmento. En ese caso se mantiene la consulta SQL simple y
         * se valida el estado con la caché de metadatos en el post-filtro.
         */
        $use_post_filter = $contains_legacy_missing && count($confirmation_statuses) > 1;
        if ( ! $use_post_filter ) {
            $confirmation_clause = eventosapp_attendance_confirmation_status_meta_query($confirmation_statuses);
            if ( is_array($confirmation_clause) ) $meta_query[] = $confirmation_clause;
        }
    }

    if ( ! empty($filters['delivery_status']) ) $meta_query[] = ['key'=>'_eventosapp_attendance_confirmation_whatsapp_delivery_status','value'=>sanitize_key($filters['delivery_status']),'compare'=>'='];
    if ( ! empty($filters['creation_channel']) ) $meta_query[] = ['key'=>'_eventosapp_creation_channel','value'=>sanitize_key($filters['creation_channel']),'compare'=>'='];
    if ( ! empty($filters['extra_fields']) ) {
        foreach ( $filters['extra_fields'] as $key => $value ) {
            $meta_query[] = ['key'=>'_eventosapp_extra_' . sanitize_key($key),'value'=>$value,'compare'=>'LIKE'];
        }
    }
    if ( count($meta_query) > 1 ) $args['meta_query'] = $meta_query;

    if ( ! empty($filters['created_from']) || ! empty($filters['created_to']) ) {
        if ( ! empty($filters['created_from']) ) $date_query['after'] = $filters['created_from'] . ' 00:00:00';
        if ( ! empty($filters['created_to']) ) $date_query['before'] = $filters['created_to'] . ' 23:59:59';
        $date_query['inclusive'] = true;
        $args['date_query'] = [$date_query];
    }
    if ( ! empty($filters['search']) ) $args['s'] = $filters['search'];

    return $args;
}

function eventosapp_attendance_confirmation_ticket_matches_post_filters($ticket_id, $filters) {
    $ticket_id = absint($ticket_id);
    $filters = eventosapp_attendance_confirmation_sanitize_filters($filters);
    if ( ! $ticket_id ) return false;

    $modalidad = ! empty($filters['modalidad']) ? sanitize_key($filters['modalidad']) : '';
    if ( in_array($modalidad, ['presencial','virtual'], true) && function_exists('eventosapp_get_ticket_modalidad') ) {
        if ( eventosapp_get_ticket_modalidad($ticket_id) !== $modalidad ) return false;
    }

    if ( ! empty($filters['confirmation_status']) ) {
        $wanted_statuses = eventosapp_attendance_confirmation_sanitize_statuses($filters['confirmation_status']);
        $all_statuses = array_keys(eventosapp_attendance_confirmation_status_options());
        if ( ! empty($wanted_statuses) && ! empty(array_diff($all_statuses, $wanted_statuses)) ) {
            $ticket_status = eventosapp_attendance_confirmation_get_status($ticket_id);
            if ( ! in_array($ticket_status, $wanted_statuses, true) ) return false;
        }
    }

    if ( ! empty($filters['event_date']) ) {
        $date = sanitize_text_field((string)$filters['event_date']);
        $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if ( ! in_array($date, eventosapp_attendance_confirmation_event_dates($event_id), true) ) return false;
    }

    if ( ! empty($filters['email_status']) ) {
        $want = sanitize_key($filters['email_status']);
        $sent = in_array('email', eventosapp_attendance_confirmation_get_channels($ticket_id, 'sent'), true);
        if ( $want === 'enviado' && ! $sent ) return false;
        if ( $want === 'no_enviado' && $sent ) return false;
    }

    if ( ! empty($filters['whatsapp_status']) ) {
        $want = sanitize_key($filters['whatsapp_status']);
        $sent = in_array('whatsapp', eventosapp_attendance_confirmation_get_channels($ticket_id, 'sent'), true);
        if ( $want === 'enviado' && ! $sent ) return false;
        if ( $want === 'no_enviado' && $sent ) return false;
    }

    return true;
}

function eventosapp_attendance_confirmation_query_ids_after($args, $after_id = 0) {
    global $wpdb;
    $after_id = absint($after_id);
    $where_filter = null;

    if ( $after_id > 0 ) {
        $where_filter = function($where, $query) use ($wpdb, $after_id) {
            return $where . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $after_id);
        };
        add_filter('posts_where', $where_filter, 10, 2);
    }

    try {
        $query = new WP_Query($args);
        $ids = array_values(array_filter(array_map('absint', (array)$query->posts)));
    } finally {
        if ( $where_filter ) remove_filter('posts_where', $where_filter, 10);
    }

    return $ids;
}

/**
 * Obtiene una página de tickets sin cargar el universo completo en memoria.
 * El cursor es el último Post ID realmente examinado, por lo que no pierde
 * registros aunque existan filtros calculados después de la consulta SQL.
 */
function eventosapp_attendance_confirmation_get_filtered_tickets_page($filters, $after_id = 0, $limit = 5, $scan_size = 100) {
    $filters = eventosapp_attendance_confirmation_sanitize_filters($filters);
    $after_id = absint($after_id);
    $limit = max(1, min(100, absint($limit)));
    $scan_size = max($limit, min(500, absint($scan_size)));
    $found = [];
    $cursor = $after_id;
    $done = false;
    $scanned = 0;
    $guard = 0;

    while ( count($found) < $limit && ! $done && $guard < 100 ) {
        $guard++;
        $args = eventosapp_attendance_confirmation_build_query_args($filters, $scan_size);
        $page_ids = eventosapp_attendance_confirmation_query_ids_after($args, $cursor);
        if ( empty($page_ids) ) {
            $done = true;
            break;
        }

        update_meta_cache('post', $page_ids);
        foreach ( $page_ids as $ticket_id ) {
            $cursor = absint($ticket_id);
            $scanned++;
            if ( eventosapp_attendance_confirmation_ticket_matches_post_filters($ticket_id, $filters) ) {
                $found[] = $ticket_id;
                if ( count($found) >= $limit ) break;
            }
        }

        if ( count($page_ids) < $scan_size && $cursor >= max($page_ids) ) {
            $done = true;
        }

        unset($page_ids);
    }

    return [
        'ticket_ids'  => $found,
        'next_cursor' => $cursor,
        'done'        => $done,
        'scanned'     => $scanned,
    ];
}

function eventosapp_attendance_confirmation_get_filtered_tickets($filters) {
    $filters = eventosapp_attendance_confirmation_sanitize_filters($filters);
    $ids = [];
    $cursor = 0;
    $guard = 0;

    do {
        $guard++;
        $page = eventosapp_attendance_confirmation_get_filtered_tickets_page($filters, $cursor, 500, 500);
        $ids = array_merge($ids, (array)$page['ticket_ids']);
        $next_cursor = absint($page['next_cursor'] ?? 0);
        if ( $next_cursor <= $cursor && empty($page['done']) ) break;
        $cursor = $next_cursor;
    } while ( empty($page['done']) && $guard < 10000 );

    return array_values(array_unique(array_map('absint', $ids)));
}

function eventosapp_attendance_confirmation_segment_key($segment_id) {
    return EVENTOSAPP_ATTENDANCE_CONFIRMATION_SEGMENT_PREFIX . sanitize_key((string)$segment_id);
}

function eventosapp_attendance_confirmation_save_segment($segment_id, $segment, $expiration = 43200) {
    set_transient(eventosapp_attendance_confirmation_segment_key($segment_id), $segment, max(600, absint($expiration)));
}

function eventosapp_attendance_confirmation_get_segment($segment_id) {
    $segment = get_transient(eventosapp_attendance_confirmation_segment_key($segment_id));
    return is_array($segment) ? $segment : null;
}

/**
 * Logs, zona horaria y programación por evento.
 */
function eventosapp_attendance_confirmation_event_timezone_info($event_id) {
    $event_id = absint($event_id);
    $stored = $event_id ? sanitize_text_field((string)get_post_meta($event_id, '_eventosapp_zona_horaria', true)) : '';
    $stored_valid = false;

    if ( $stored !== '' ) {
        try {
            new DateTimeZone($stored);
            $stored_valid = true;
        } catch (Throwable $e) {
            $stored_valid = false;
        }
    }

    if ( function_exists('eventosapp_get_event_timezone_object') ) {
        $timezone = eventosapp_get_event_timezone_object($event_id);
    } else {
        $timezone = wp_timezone();
    }

    if ( ! $timezone instanceof DateTimeZone ) $timezone = wp_timezone();

    if ( $stored_valid ) {
        $source = 'event';
        $source_label = 'Zona horaria configurada en el evento';
        $warning = '';
    } elseif ( $stored !== '' ) {
        $source = 'wordpress_invalid_event_timezone';
        $source_label = 'Zona horaria general de WordPress';
        $warning = 'La zona horaria guardada en el evento no es válida y se está usando la zona general de WordPress.';
    } else {
        $source = 'wordpress';
        $source_label = 'Zona horaria general de WordPress';
        $warning = '';
    }

    $now = new DateTimeImmutable('now', $timezone);

    return [
        'object'       => $timezone,
        'name'         => $timezone->getName(),
        'source'       => $source,
        'source_label' => $source_label,
        'warning'      => $warning,
        'stored_value' => $stored,
        'current_time' => $now->format('Y-m-d H:i:s'),
        'utc_offset'   => $now->format('P'),
    ];
}

function eventosapp_attendance_confirmation_parse_event_datetime($event_id, $date, $time) {
    $date = sanitize_text_field((string)$date);
    $time = sanitize_text_field((string)$time);
    if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^\d{2}:\d{2}$/', $time) ) {
        return new WP_Error('invalid_datetime', 'Fecha u hora inválida.');
    }

    $tz_info = eventosapp_attendance_confirmation_event_timezone_info($event_id);
    $tz = $tz_info['object'];
    $local_value = $date . ' ' . $time;
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $local_value, $tz);
    $errors = DateTimeImmutable::getLastErrors();

    if ( ! $dt instanceof DateTimeImmutable || ($errors !== false && (! empty($errors['warning_count']) || ! empty($errors['error_count']))) ) {
        return new WP_Error('invalid_datetime', 'No se pudo interpretar la fecha programada en la zona horaria del evento.');
    }

    // Detecta horas inexistentes o normalizadas automáticamente por cambios DST.
    if ( $dt->format('Y-m-d H:i') !== $local_value ) {
        return new WP_Error('invalid_local_time', 'La hora indicada no existe en la zona horaria del evento por un cambio de horario. Selecciona otra hora.');
    }

    return [
        'datetime'       => $dt,
        'timestamp'      => $dt->getTimestamp(),
        'timezone'       => $tz_info['name'],
        'timezone_source'=> $tz_info['source'],
        'utc_offset'     => $dt->format('P'),
        'local'          => $dt->format('Y-m-d H:i:s'),
        'utc'            => $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
    ];
}

function eventosapp_attendance_confirmation_format_timestamp_for_event($event_id, $timestamp, $format = 'Y-m-d H:i:s T') {
    $timestamp = absint($timestamp);
    if ( ! $timestamp ) return '';
    $tz_info = eventosapp_attendance_confirmation_event_timezone_info($event_id);
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz_info['object'])->format($format);
}

function eventosapp_attendance_confirmation_schedule_diagnostics($event_id, $config = null) {
    $event_id = absint($event_id);
    if ( $config === null ) $config = get_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule', true);
    $config = is_array($config) ? $config : [];
    $schedule_id = sanitize_key((string)($config['schedule_id'] ?? ''));
    $next = $schedule_id !== '' ? wp_next_scheduled(EVENTOSAPP_ATTENDANCE_CONFIRMATION_SCHEDULE_HOOK, [$event_id, $schedule_id]) : false;
    $stored_timestamp = absint($config['timestamp'] ?? 0);

    return [
        'enabled'                  => ! empty($config['enabled']),
        'timezone'                 => sanitize_text_field((string)($config['timezone'] ?? eventosapp_attendance_confirmation_event_timezone_info($event_id)['name'])),
        'timezone_source'          => sanitize_key((string)($config['timezone_source'] ?? 'event')),
        'stored_timestamp'         => $stored_timestamp,
        'stored_event_time'        => $stored_timestamp ? eventosapp_attendance_confirmation_format_timestamp_for_event($event_id, $stored_timestamp) : '',
        'stored_utc_time'          => $stored_timestamp ? gmdate('Y-m-d H:i:s', $stored_timestamp) . ' UTC' : '',
        'next_cron_timestamp'      => $next ? absint($next) : 0,
        'next_cron_event_time'     => $next ? eventosapp_attendance_confirmation_format_timestamp_for_event($event_id, $next) : '',
        'next_cron_utc_time'       => $next ? gmdate('Y-m-d H:i:s', $next) . ' UTC' : '',
        'cron_matches_configuration'=> $next && $stored_timestamp ? abs($next - $stored_timestamp) <= 1 : false,
    ];
}

function eventosapp_attendance_confirmation_event_log($event_id, $entry) {
    $event_id = absint($event_id);
    if ( ! $event_id ) return;
    $log = get_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule_log', true);
    $log = is_array($log) ? $log : [];
    $tz_info = eventosapp_attendance_confirmation_event_timezone_info($event_id);
    $entry = array_merge([
        'at'                  => current_time('mysql'),
        'at_event_timezone'   => $tz_info['current_time'] . ' ' . $tz_info['name'],
        'at_utc'              => gmdate('Y-m-d H:i:s') . ' UTC',
        'type'                => 'info',
        'message'             => '',
        'meta'                => [],
    ], is_array($entry)?$entry:[]);
    $entry['at'] = sanitize_text_field((string)$entry['at']);
    $entry['at_event_timezone'] = sanitize_text_field((string)$entry['at_event_timezone']);
    $entry['at_utc'] = sanitize_text_field((string)$entry['at_utc']);
    $entry['type'] = sanitize_key((string)$entry['type']);
    $entry['message'] = sanitize_textarea_field((string)$entry['message']);
    if ( function_exists('eventosapp_whatsapp_sanitize_log_context') ) $entry['meta'] = eventosapp_whatsapp_sanitize_log_context($entry['meta']);
    array_unshift($log, $entry);
    if ( count($log) > 200 ) $log = array_slice($log, 0, 200);
    update_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule_log', $log);
}

function eventosapp_attendance_confirmation_unschedule_event($event_id) {
    $event_id = absint($event_id);
    $config = get_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule', true);
    if ( is_array($config) && ! empty($config['schedule_id']) ) {
        $args = [$event_id, sanitize_key((string)$config['schedule_id'])];
        while ( $timestamp = wp_next_scheduled(EVENTOSAPP_ATTENDANCE_CONFIRMATION_SCHEDULE_HOOK, $args) ) {
            wp_unschedule_event($timestamp, EVENTOSAPP_ATTENDANCE_CONFIRMATION_SCHEDULE_HOOK, $args);
        }
    }
}

function eventosapp_attendance_confirmation_schedule_event($event_id, $config) {
    $event_id = absint($event_id);
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
        return new WP_Error('invalid_event', 'Evento inválido.');
    }

    $config = is_array($config) ? $config : [];
    $enabled = ! empty($config['enabled']);
    $previous = get_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule', true);
    $previous = is_array($previous) ? $previous : [];

    if ( ! $enabled ) {
        eventosapp_attendance_confirmation_unschedule_event($event_id);
        delete_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule');
        eventosapp_attendance_confirmation_event_log($event_id, [
            'type'    => 'schedule_disabled',
            'message' => 'Programación desactivada.',
        ]);
        return ['ok'=>true,'message'=>'Programación desactivada.'];
    }

    $date = sanitize_text_field((string)($config['date'] ?? ''));
    $time = sanitize_text_field((string)($config['time'] ?? ''));
    $parsed = eventosapp_attendance_confirmation_parse_event_datetime($event_id, $date, $time);
    if ( is_wp_error($parsed) ) return $parsed;

    $timestamp = absint($parsed['timestamp']);
    if ( $timestamp <= time() + 60 ) {
        return new WP_Error('past_datetime', 'La fecha programada debe estar al menos un minuto en el futuro.');
    }

    $channels = eventosapp_attendance_confirmation_sanitize_channels($config['channels'] ?? []);
    if ( empty($channels) ) return new WP_Error('no_channels', 'Selecciona al menos un canal.');

    $schedule_id = sanitize_key('rsvp_' . $event_id . '_' . wp_generate_password(10, false, false));
    $stored = [
        'enabled'              => 1,
        'schedule_id'          => $schedule_id,
        'date'                 => $date,
        'time'                 => $time,
        'timestamp'            => $timestamp,
        'timezone'             => $parsed['timezone'],
        'timezone_source'      => $parsed['timezone_source'],
        'utc_offset'           => $parsed['utc_offset'],
        'scheduled_local'      => $parsed['local'],
        'scheduled_utc'        => $parsed['utc'],
        'channels'             => $channels,
        'filters'              => eventosapp_attendance_confirmation_sanitize_filters(array_merge((array)($config['filters'] ?? []), ['evento_id'=>$event_id])),
        'email_template'       => sanitize_file_name((string)($config['email_template'] ?? 'attendance-confirmation.html')),
        'email_subject'        => sanitize_text_field((string)($config['email_subject'] ?? 'Confirma tu asistencia a {{evento_nombre}}')),
        'email_message'        => sanitize_textarea_field((string)($config['email_message'] ?? '')),
        'whatsapp_template_id' => sanitize_key((string)($config['whatsapp_template_id'] ?? eventosapp_attendance_confirmation_whatsapp_template_id())),
        'created_at'           => current_time('mysql'),
        'created_by'           => get_current_user_id(),
    ];

    $scheduled = wp_schedule_single_event($timestamp, EVENTOSAPP_ATTENDANCE_CONFIRMATION_SCHEDULE_HOOK, [$event_id, $schedule_id], true);
    if ( is_wp_error($scheduled) ) return $scheduled;
    if ( ! $scheduled ) return new WP_Error('schedule_failed', 'WordPress no pudo registrar la tarea programada.');

    $verified_timestamp = wp_next_scheduled(EVENTOSAPP_ATTENDANCE_CONFIRMATION_SCHEDULE_HOOK, [$event_id, $schedule_id]);
    if ( ! $verified_timestamp || abs($verified_timestamp - $timestamp) > 1 ) {
        if ( $verified_timestamp ) wp_unschedule_event($verified_timestamp, EVENTOSAPP_ATTENDANCE_CONFIRMATION_SCHEDULE_HOOK, [$event_id, $schedule_id]);
        return new WP_Error('schedule_verification_failed', 'La tarea fue creada, pero WordPress no confirmó la misma fecha y hora. La configuración anterior se conserva.');
    }

    update_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule', $stored);

    if ( ! empty($previous['schedule_id']) ) {
        $previous_args = [$event_id, sanitize_key((string)$previous['schedule_id'])];
        while ( $previous_timestamp = wp_next_scheduled(EVENTOSAPP_ATTENDANCE_CONFIRMATION_SCHEDULE_HOOK, $previous_args) ) {
            wp_unschedule_event($previous_timestamp, EVENTOSAPP_ATTENDANCE_CONFIRMATION_SCHEDULE_HOOK, $previous_args);
        }
    }

    eventosapp_attendance_confirmation_event_log($event_id, [
        'type'    => 'scheduled',
        'message' => 'Envío programado para ' . $parsed['local'] . ' (' . $parsed['timezone'] . ', UTC' . $parsed['utc_offset'] . '). Equivalente UTC: ' . $parsed['utc'] . '.',
        'meta'    => array_merge($stored, ['cron_verified_timestamp'=>$verified_timestamp]),
    ]);

    return ['ok'=>true,'message'=>'Envío programado en la zona horaria del evento.','config'=>$stored];
}

add_action(EVENTOSAPP_ATTENDANCE_CONFIRMATION_SCHEDULE_HOOK, function($event_id, $schedule_id) {
    $event_id = absint($event_id);
    $schedule_id = sanitize_key((string)$schedule_id);
    $scope = 'attendance_schedule_start:' . $event_id . ':' . $schedule_id;
    $lock = eventosapp_attendance_confirmation_acquire_lock($scope, 180);
    if ( is_wp_error($lock) ) return;

    try {
        $config = get_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule', true);
        if ( ! is_array($config) || empty($config['enabled']) || sanitize_key((string)($config['schedule_id'] ?? '')) !== $schedule_id ) {
            eventosapp_attendance_confirmation_event_log($event_id, ['type'=>'cancelled','message'=>'La programación ya no estaba vigente al ejecutarse.']);
            return;
        }

        $job_id = sanitize_key('job_' . $event_id . '_' . time() . '_' . wp_generate_password(6, false, false));
        $job = [
            'job_id'       => $job_id,
            'event_id'     => $event_id,
            'schedule_id'  => $schedule_id,
            'cursor'       => 0,
            'processed'    => 0,
            'scanned'      => 0,
            'success'      => 0,
            'partial'      => 0,
            'errors'       => 0,
            'started_at'   => current_time('mysql'),
            'started_utc'  => gmdate('Y-m-d H:i:s'),
            'planned_timestamp' => absint($config['timestamp'] ?? 0),
            'config'       => $config,
        ];
        update_option('evapp_attendance_job_' . $job_id, $job, false);
        update_post_meta($event_id, '_eventosapp_attendance_confirmation_last_job_id', $job_id);
        $config['enabled'] = 0;
        $config['last_started_at'] = current_time('mysql');
        $config['last_job_id'] = $job_id;
        update_post_meta($event_id, '_eventosapp_attendance_confirmation_schedule', $config);

        eventosapp_attendance_confirmation_event_log($event_id, [
            'type'=>'job_started',
            'message'=>'Trabajo programado iniciado en lotes controlados.',
            'meta'=>[
                'job_id'=>$job_id,
                'planned_event_time'=>eventosapp_attendance_confirmation_format_timestamp_for_event($event_id, absint($job['planned_timestamp'])),
                'planned_utc'=>$job['planned_timestamp'] ? gmdate('Y-m-d H:i:s', $job['planned_timestamp']) : '',
                'actual_event_time'=>eventosapp_attendance_confirmation_format_timestamp_for_event($event_id, time()),
                'actual_utc'=>gmdate('Y-m-d H:i:s'),
            ],
        ]);

        wp_schedule_single_event(time() + 5, EVENTOSAPP_ATTENDANCE_CONFIRMATION_BATCH_HOOK, [$job_id]);
    } finally {
        eventosapp_attendance_confirmation_release_lock($scope, $lock);
    }
}, 10, 2);

add_action(EVENTOSAPP_ATTENDANCE_CONFIRMATION_BATCH_HOOK, function($job_id) {
    $job_id = sanitize_key((string)$job_id);
    $option_key = 'evapp_attendance_job_' . $job_id;
    $job = get_option($option_key, null);
    if ( ! is_array($job) ) return;

    $channels = eventosapp_attendance_confirmation_sanitize_channels($job['config']['channels'] ?? ['email']);
    $limits = eventosapp_attendance_confirmation_bulk_limits('attendance_confirmation_scheduled', $channels);
    $scope = 'attendance_scheduled_batch:' . $job_id;
    $lock = eventosapp_attendance_confirmation_acquire_lock($scope, $limits['lock_ttl']);
    if ( is_wp_error($lock) ) {
        wp_schedule_single_event(time() + $limits['delay_seconds'], EVENTOSAPP_ATTENDANCE_CONFIRMATION_BATCH_HOOK, [$job_id]);
        return;
    }

    try {
        $started = microtime(true);
        $page = eventosapp_attendance_confirmation_get_filtered_tickets_page(
            $job['config']['filters'] ?? ['evento_id'=>absint($job['event_id'])],
            absint($job['cursor'] ?? 0),
            $limits['batch_size'],
            $limits['scan_size']
        );

        $ticket_ids = (array)($page['ticket_ids'] ?? []);
        $processed_this_run = 0;
        $last_processed_ticket_id = 0;
        foreach ( $ticket_ids as $ticket_id ) {
            if ( eventosapp_attendance_confirmation_should_yield($started, $processed_this_run, $limits) ) break;

            $source_key = $job_id . ':' . absint($ticket_id);
            $result = eventosapp_attendance_confirmation_send_ticket($ticket_id, [
                'channels'=>$channels,
                'email_template'=>$job['config']['email_template'] ?? 'attendance-confirmation.html',
                'email_subject'=>$job['config']['email_subject'] ?? 'Confirma tu asistencia a {{evento_nombre}}',
                'email_message'=>$job['config']['email_message'] ?? '',
                'whatsapp_template_id'=>$job['config']['whatsapp_template_id'] ?? eventosapp_attendance_confirmation_whatsapp_template_id(),
                'source'=>'scheduled','source_key'=>$source_key,
            ]);
            if ( ! empty($result['ok']) && empty($result['partial']) ) $job['success']++;
            elseif ( ! empty($result['ok']) ) $job['partial']++;
            else $job['errors']++;
            $processed_this_run++;
            $last_processed_ticket_id = absint($ticket_id);
            $job['processed'] = absint($job['processed'] ?? 0) + 1;
        }

        /*
         * Solo se adopta el cursor completo del escaneo cuando todos los tickets
         * seleccionados para este lote fueron procesados. Si se activó el corte
         * preventivo por tiempo/memoria, se conserva el último ID realmente
         * procesado para que ningún destinatario quede saltado.
         */
        if ( $processed_this_run >= count($ticket_ids) ) {
            $job['cursor'] = absint($page['next_cursor'] ?? $job['cursor'] ?? 0);
            $job['scanned'] = absint($job['scanned'] ?? 0) + absint($page['scanned'] ?? 0);
        } elseif ( $last_processed_ticket_id > 0 ) {
            $job['cursor'] = $last_processed_ticket_id;
        }
        $job['updated_at'] = current_time('mysql');
        update_option($option_key, $job, false);

        $done = ! empty($page['done']) && $processed_this_run >= count($ticket_ids);
        if ( ! $done ) {
            $scheduled = wp_schedule_single_event(time() + $limits['delay_seconds'], EVENTOSAPP_ATTENDANCE_CONFIRMATION_BATCH_HOOK, [$job_id], true);
            if ( is_wp_error($scheduled) || ! $scheduled ) {
                eventosapp_attendance_confirmation_event_log(absint($job['event_id']), [
                    'type'=>'batch_reschedule_error',
                    'message'=>'No se pudo programar el siguiente lote. El trabajo puede reanudarse manualmente desde el log.',
                    'meta'=>['job_id'=>$job_id,'cursor'=>$job['cursor']],
                ]);
            }
            return;
        }

        $job['finished_at'] = current_time('mysql');
        $job['finished_utc'] = gmdate('Y-m-d H:i:s');
        update_post_meta(absint($job['event_id']), '_eventosapp_attendance_confirmation_last_job', $job);
        eventosapp_attendance_confirmation_event_log(absint($job['event_id']), [
            'type'=>'job_finished',
            'message'=>'Trabajo finalizado. Procesados: ' . absint($job['processed']) . ', correctos: ' . absint($job['success']) . ', parciales: ' . absint($job['partial']) . ', errores: ' . absint($job['errors']) . '.',
            'meta'=>[
                'job_id'=>$job_id,
                'processed'=>$job['processed'],
                'scanned'=>$job['scanned'],
                'success'=>$job['success'],
                'partial'=>$job['partial'],
                'errors'=>$job['errors'],
            ],
        ]);
        delete_option($option_key);
    } catch (Throwable $e) {
        $job['last_error'] = sanitize_text_field($e->getMessage());
        $job['updated_at'] = current_time('mysql');
        update_option($option_key, $job, false);
        eventosapp_attendance_confirmation_event_log(absint($job['event_id']), [
            'type'=>'batch_exception',
            'message'=>'El lote se detuvo de forma segura por un error y se reintentará.',
            'meta'=>['job_id'=>$job_id,'error'=>$e->getMessage()],
        ]);
        wp_schedule_single_event(time() + min(300, $limits['delay_seconds'] * 2), EVENTOSAPP_ATTENDANCE_CONFIRMATION_BATCH_HOOK, [$job_id]);
    } finally {
        eventosapp_attendance_confirmation_release_lock($scope, $lock);
    }
}, 10, 1);
