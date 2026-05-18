<?php
/**
 * Helpers de tickets (IDs, checksum, consecutivos y fechas de evento)
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Genera una cadena aleatoria Base62 (0-9 A-Z a-z) de longitud $length.
 *
 * @param int $length
 * @return string
 */
function eventosapp_random_base62($length = 12) {
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/**
 * Checksum mod36 sencillo (0-9 A-Z) para detectar errores de tipeo.
 * No es criptográfico; sirve como verificador humano.
 *
 * @param string $str
 * @return string
 */
function eventosapp_checksum36($str) {
    $str = strtoupper($str);
    $sum = 0;
    $len = strlen($str);
    for ($i = 0; $i < $len; $i++) {
        $ch = $str[$i];
        if ($ch >= '0' && $ch <= '9') {
            $v = ord($ch) - ord('0');       // 0..9
        } elseif ($ch >= 'A' && $ch <= 'Z') {
            $v = ord($ch) - ord('A') + 10;  // 10..35
        } else {
            $v = 0;
        }
        // Pequeña mezcla: sum = sum*33 + v (tipo djb2) mod 36
        $sum = (($sum * 33) + $v) % 36;
    }
    return ($sum < 10) ? chr(ord('0') + $sum) : chr(ord('A') + ($sum - 10));
}

/**
 * Genera un ID público de ticket NO predecible.
 * Formato: tk + 12 Base62 + 1 checksum mod36 (ej: tkA9fL2...Q)
 *
 * @return string
 */
function eventosapp_generate_public_ticket_id() {
    $core  = eventosapp_random_base62(12);
    $check = eventosapp_checksum36($core);
    return 'tk' . $core . $check;
}

/**
 * Genera y asegura un ID único (reintentando si existiera).
 *
 * @return string
 */
function eventosapp_generate_unique_ticket_id() {
    do {
        $candidate = eventosapp_generate_public_ticket_id();
        $exists = get_posts([
            'post_type'   => 'eventosapp_ticket',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_key'    => 'eventosapp_ticketID',
            'meta_value'  => $candidate,
        ]);
    } while (!empty($exists));
    return $candidate;
}

/**
 * Incrementa y obtiene el consecutivo interno por evento (no público).
 * Guarda el próximo valor en el meta del evento: _eventosapp_event_seq_last
 *
 * @param int $evento_id
 * @return int
 */
function eventosapp_next_event_sequence($evento_id) {
    $last = (int) get_post_meta($evento_id, '_eventosapp_event_seq_last', true);
    $next = $last + 1;
    update_post_meta($evento_id, '_eventosapp_event_seq_last', $next);
    return $next;
}

/**
 * Obtiene los días válidos del evento, según su configuración (única, consecutiva, no consecutiva).
 *
 * @param int $evento_id
 * @return string[] Array de fechas 'Y-m-d'
 */
function eventosapp_get_event_days($evento_id) {
    $tipo = get_post_meta($evento_id, '_eventosapp_tipo_fecha', true);
    $days = [];

    if ($tipo === 'unica') {
        $fecha = get_post_meta($evento_id, '_eventosapp_fecha_unica', true);
        if ($fecha) $days[] = $fecha;
    } elseif ($tipo === 'consecutiva') {
        $inicio = get_post_meta($evento_id, '_eventosapp_fecha_inicio', true);
        $fin    = get_post_meta($evento_id, '_eventosapp_fecha_fin', true);
        if ($inicio && $fin && $fin >= $inicio) {
            $d1 = new DateTime($inicio);
            $d2 = new DateTime($fin);
            while ($d1 <= $d2) {
                $days[] = $d1->format('Y-m-d');
                $d1->modify('+1 day');
            }
        }
    } elseif ($tipo === 'noconsecutiva') {
        $fechas = get_post_meta($evento_id, '_eventosapp_fechas_noco', true);
        if (is_string($fechas)) $fechas = @unserialize($fechas);
        if (!is_array($fechas)) $fechas = [];
        foreach ($fechas as $f) {
            if ($f) $days[] = $f;
        }
    }
    return $days;
}

/**
 * Normaliza texto para búsquedas (sin acentos, minúsculas, espacios colapsados).
 */
function eventosapp_normalize_text($s){
    $s = wp_strip_all_tags((string)$s);
    $s = remove_accents($s);
    if (function_exists('mb_strtolower')) $s = mb_strtolower($s, 'UTF-8'); else $s = strtolower($s);
    // deja solo letras/números, @, puntos y guiones, el resto a espacios
    $s = preg_replace('/[^a-z0-9@\.\-_]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/**
 * Construye el "blob" indexado de un ticket.
 */
// includes/functions/eventosapp-ticket-helpers.php
function eventosapp_ticket_build_search_blob($ticket_id){
    if (get_post_type($ticket_id) !== 'eventosapp_ticket') return '';
    $parts = [];
    $parts[] = get_post_meta($ticket_id, '_eventosapp_asistente_nombre',   true);
    $parts[] = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
    $parts[] = get_post_meta($ticket_id, '_eventosapp_asistente_email',    true);
    $parts[] = get_post_meta($ticket_id, '_eventosapp_asistente_cc',       true);
    $parts[] = get_post_meta($ticket_id, 'eventosapp_ticketID',            true);
    // (opcional) empresa/cargo/localidad si quieres
    $parts[] = get_post_meta($ticket_id, '_eventosapp_asistente_empresa',   true);
    $parts[] = get_post_meta($ticket_id, '_eventosapp_asistente_cargo',     true);
    $parts[] = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);
    $parts[] = function_exists('eventosapp_get_ticket_modalidad_label')
        ? eventosapp_get_ticket_modalidad_label($ticket_id)
        : get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);

    $blob = eventosapp_normalize_text( implode(' ', array_filter(array_map('strval',$parts))) );
    update_post_meta($ticket_id, '_evapp_search_blob', $blob); // <--- importante
    return $blob;
}

/**
 * Inicializa el estado de envío de correo de un ticket como "no_enviado"
 * Se debe llamar cuando se crea un ticket nuevo que aún no ha recibido correo
 *
 * @param int $ticket_id ID del ticket
 * @return void
 */
function eventosapp_ticket_init_email_status($ticket_id) {
    // Solo inicializar si no existe ya un estado
    $existing_status = get_post_meta($ticket_id, '_eventosapp_ticket_email_sent_status', true);
    
    if (empty($existing_status)) {
        update_post_meta($ticket_id, '_eventosapp_ticket_email_sent_status', 'no_enviado');
    }
}



/* ============================================================
 * Modalidad del evento/ticket + acceso virtual
 * ============================================================ */

if (!function_exists('eventosapp_event_modalidad_options')) {
    function eventosapp_event_modalidad_options() {
        return [
            'presencial'          => 'Presencial',
            'virtual'             => 'Virtual',
            'presencial_virtual'  => 'Presencial y Virtual',
        ];
    }
}

if (!function_exists('eventosapp_ticket_modalidad_options')) {
    function eventosapp_ticket_modalidad_options() {
        return [
            'presencial' => 'Presencial',
            'virtual'    => 'Virtual',
        ];
    }
}

if (!function_exists('eventosapp_modalidad_normalize_string')) {
    function eventosapp_modalidad_normalize_string($value) {
        $value = wp_strip_all_tags((string) $value);
        $value = remove_accents($value);
        $value = strtolower(trim($value));
        $value = str_replace(['-', ' ', '/', '+', '&'], '_', $value);
        $value = preg_replace('/_+/u', '_', $value);
        return trim($value, '_');
    }
}

if (!function_exists('eventosapp_normalize_event_modalidad')) {
    function eventosapp_normalize_event_modalidad($value) {
        $value = eventosapp_modalidad_normalize_string($value);

        if (in_array($value, [
            'virtual',
            'online',
            'remoto',
            'remota',
            'digital',
            'streaming',
            'transmision',
            'transmision_virtual',
        ], true)) {
            return 'virtual';
        }

        if (in_array($value, [
            'presencial_virtual',
            'presencia_virtual',
            'presencial_y_virtual',
            'presencia_y_virtual',
            'virtual_presencial',
            'virtual_y_presencial',
            'online_presencial',
            'online_y_presencial',
            'presencial_online',
            'presencial_y_online',
            'hibrido',
            'hibrida',
            'hybrid',
            'hybrido',
            'mixto',
            'mixta',
        ], true)) {
            return 'presencial_virtual';
        }

        return 'presencial';
    }
}

if (!function_exists('eventosapp_normalize_ticket_modalidad')) {
    function eventosapp_normalize_ticket_modalidad($value) {
        $value = eventosapp_modalidad_normalize_string($value);

        if (in_array($value, [
            'virtual',
            'online',
            'remoto',
            'remota',
            'digital',
            'streaming',
            'transmision',
            'transmision_virtual',
        ], true)) {
            return 'virtual';
        }

        return 'presencial';
    }
}

if (!function_exists('eventosapp_get_event_modalidad')) {
    function eventosapp_get_event_modalidad($evento_id) {
        $evento_id = absint($evento_id);
        if (!$evento_id) return 'presencial';

        $raw = get_post_meta($evento_id, '_eventosapp_event_modalidad', true);
        if ($raw === '' || $raw === null) {
            $raw = get_post_meta($evento_id, '_eventosapp_modalidad_evento', true);
        }

        return eventosapp_normalize_event_modalidad($raw ?: 'presencial');
    }
}

if (!function_exists('eventosapp_get_event_modalidad_label')) {
    function eventosapp_get_event_modalidad_label($evento_id) {
        $opts = eventosapp_event_modalidad_options();
        $key  = eventosapp_get_event_modalidad($evento_id);
        return $opts[$key] ?? 'Presencial';
    }
}

if (!function_exists('eventosapp_resolve_ticket_modalidad')) {
    function eventosapp_resolve_ticket_modalidad($evento_id, $requested = '', $current = '') {
        $event_mode = eventosapp_get_event_modalidad($evento_id);

        if ($event_mode === 'virtual') {
            return 'virtual';
        }

        if ($event_mode === 'presencial') {
            return 'presencial';
        }

        $requested = trim((string) $requested);
        if ($requested !== '') {
            return eventosapp_normalize_ticket_modalidad($requested);
        }

        $current = trim((string) $current);
        if ($current !== '') {
            return eventosapp_normalize_ticket_modalidad($current);
        }

        return 'presencial';
    }
}

if (!function_exists('eventosapp_ticket_allowed_modalidades_for_event')) {
    function eventosapp_ticket_allowed_modalidades_for_event($evento_id) {
        $event_mode = eventosapp_get_event_modalidad($evento_id);

        if ($event_mode === 'virtual') {
            return ['virtual'];
        }

        if ($event_mode === 'presencial_virtual') {
            return ['presencial', 'virtual'];
        }

        return ['presencial'];
    }
}

if (!function_exists('eventosapp_ticket_modalidad_options_for_event')) {
    function eventosapp_ticket_modalidad_options_for_event($evento_id) {
        $all     = eventosapp_ticket_modalidad_options();
        $allowed = eventosapp_ticket_allowed_modalidades_for_event($evento_id);
        $out     = [];

        foreach ($allowed as $key) {
            if (isset($all[$key])) {
                $out[$key] = $all[$key];
            }
        }

        return $out ?: ['presencial' => 'Presencial'];
    }
}

if (!function_exists('eventosapp_get_ticket_modalidad')) {
    function eventosapp_get_ticket_modalidad($ticket_id) {
        $ticket_id = absint($ticket_id);
        if (!$ticket_id) return 'presencial';

        $evento_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
        $stored    = get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);

        if (!$evento_id) {
            return eventosapp_normalize_ticket_modalidad($stored ?: 'presencial');
        }

        return eventosapp_resolve_ticket_modalidad($evento_id, $stored, $stored);
    }
}

if (!function_exists('eventosapp_get_ticket_modalidad_label')) {
    function eventosapp_get_ticket_modalidad_label($ticket_id) {
        $opts = eventosapp_ticket_modalidad_options();
        $key  = eventosapp_get_ticket_modalidad($ticket_id);
        return $opts[$key] ?? 'Presencial';
    }
}

if (!function_exists('eventosapp_ticket_is_virtual')) {
    function eventosapp_ticket_is_virtual($ticket_id) {
        return eventosapp_get_ticket_modalidad($ticket_id) === 'virtual';
    }
}

if (!function_exists('eventosapp_ticket_clear_presential_assets')) {
    function eventosapp_ticket_clear_presential_assets($ticket_id) {
        $ticket_id = absint($ticket_id);
        if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') return;

        $meta_keys = [
            '_eventosapp_ticket_pdf_url',
            '_eventosapp_ticket_pdf_path',
            '_eventosapp_wallet_android_url',
            '_eventosapp_ticket_wallet_android',
            '_eventosapp_ticket_wallet_android_url',
            '_eventosapp_wallet_android_qr_url',
            '_eventosapp_wallet_android_qr_path',
            '_eventosapp_wallet_android_object_id',
            '_eventosapp_wallet_google_object_id',
            '_eventosapp_wallet_google_class_id_effective',
            '_eventosapp_wallet_effective_class_id',
            '_eventosapp_apple_wallet_url',
            '_eventosapp_ticket_wallet_apple',
            '_eventosapp_ticket_wallet_apple_url',
            '_eventosapp_ticket_pkpass_url',
            '_eventosapp_apple_wallet_path',
            '_eventosapp_apple_wallet_pkpass_url',
            '_eventosapp_apple_wallet_pkpass_path',
            '_eventosapp_qr_codes',
            '_eventosapp_ticket_qr_url',
            '_eventosapp_ticket_qr_path',
        ];

        foreach ($meta_keys as $meta_key) {
            delete_post_meta($ticket_id, $meta_key);
        }
    }
}

if (!function_exists('eventosapp_ticket_sync_modalidad')) {
    function eventosapp_ticket_sync_modalidad($ticket_id, $requested = '') {
        $ticket_id = absint($ticket_id);
        if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') return 'presencial';

        $evento_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
        $current   = get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
        $resolved  = eventosapp_resolve_ticket_modalidad($evento_id, $requested, $current);

        update_post_meta($ticket_id, '_eventosapp_ticket_modalidad', $resolved);

        if ($resolved === 'virtual') {
            eventosapp_ticket_clear_presential_assets($ticket_id);
        }

        return $resolved;
    }
}

if (!function_exists('eventosapp_ticket_requested_modalidad_from_request')) {
    function eventosapp_ticket_requested_modalidad_from_request() {
        $keys = [
            'eventosapp_ticket_modalidad',
            'eventosapp_asistente_modalidad',
            'eventosapp_modalidad',
            'modalidad',
            'ticket_modalidad',
            'ticket_modality',
            'attendance_mode',
            'modalidad_asistencia',
            'tipo_asistencia',
        ];

        foreach ($keys as $key) {
            if (isset($_POST[$key]) && $_POST[$key] !== '') {
                return sanitize_text_field(wp_unslash($_POST[$key]));
            }
        }

        if (!empty($_POST['eventosapp_extra']) && is_array($_POST['eventosapp_extra'])) {
            $extra_keys = ['modalidad', 'ticket_modalidad', 'ticket_modality', 'attendance_mode', 'modalidad_asistencia', 'tipo_asistencia'];
            foreach ($extra_keys as $key) {
                if (isset($_POST['eventosapp_extra'][$key]) && $_POST['eventosapp_extra'][$key] !== '') {
                    return sanitize_text_field(wp_unslash($_POST['eventosapp_extra'][$key]));
                }
            }
        }

        return '';
    }
}

if (!function_exists('eventosapp_ticket_ensure_modalidad_after_save')) {
    function eventosapp_ticket_ensure_modalidad_after_save($post_id, $post = null, $update = false) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (get_post_type($post_id) !== 'eventosapp_ticket') return;

        $requested = eventosapp_ticket_requested_modalidad_from_request();
        eventosapp_ticket_sync_modalidad($post_id, $requested);
    }
    add_action('save_post_eventosapp_ticket', 'eventosapp_ticket_ensure_modalidad_after_save', 998, 3);
}

if (!function_exists('eventosapp_virtual_ticket_token')) {
    function eventosapp_virtual_ticket_token($ticket_id) {
        $ticket_id = absint($ticket_id);
        if (!$ticket_id) return '';

        $token = (string) get_post_meta($ticket_id, '_eventosapp_virtual_access_token', true);
        if ($token === '') {
            $token = wp_generate_password(32, false, false);
            update_post_meta($ticket_id, '_eventosapp_virtual_access_token', $token);
        }
        return $token;
    }
}

if (!function_exists('eventosapp_find_ticket_by_public_id')) {
    function eventosapp_find_ticket_by_public_id($public_id) {
        $public_id = sanitize_text_field((string) $public_id);
        if ($public_id === '') return 0;

        $q = new WP_Query([
            'post_type'      => 'eventosapp_ticket',
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => 'eventosapp_ticketID',
                    'value'   => $public_id,
                    'compare' => '=',
                ],
            ],
        ]);

        return !empty($q->posts) ? (int) $q->posts[0] : 0;
    }
}

if (!function_exists('eventosapp_get_virtual_landing_url')) {
    function eventosapp_get_virtual_landing_url($ticket_id) {
        $ticket_id = absint($ticket_id);
        if (!$ticket_id) return '';

        $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
        if (!$ticket_code) return '';

        return add_query_arg([
            'evapp_vticket' => rawurlencode($ticket_code),
            'evapp_vtoken'  => rawurlencode(eventosapp_virtual_ticket_token($ticket_id)),
        ], home_url('/'));
    }
}

if (!function_exists('eventosapp_event_virtual_access_state')) {
    function eventosapp_event_virtual_access_state($evento_id, $ticket_id = 0) {
        $evento_id = absint($evento_id);
        $ticket_id = absint($ticket_id);

        $platform = $evento_id ? (get_post_meta($evento_id, '_eventosapp_virtual_platform', true) ?: '') : '';
        $url      = $evento_id ? (get_post_meta($evento_id, '_eventosapp_virtual_url', true) ?: '') : '';
        $raw_dt   = $evento_id ? (get_post_meta($evento_id, '_eventosapp_virtual_access_datetime', true) ?: '') : '';
        $tz_name  = $evento_id ? (get_post_meta($evento_id, '_eventosapp_zona_horaria', true) ?: wp_timezone_string()) : wp_timezone_string();

        try {
            $tz = new DateTimeZone($tz_name ?: 'America/Bogota');
        } catch (Exception $e) {
            $tz = wp_timezone();
        }

        try {
            $now = new DateTime('now', $tz);
        } catch (Exception $e) {
            $now = new DateTime('now');
        }

        $enabled_at = null;
        if ($raw_dt !== '') {
            $candidate = str_replace('T', ' ', $raw_dt);
            $enabled_at = DateTime::createFromFormat('Y-m-d H:i', $candidate, $tz);
            if (!$enabled_at) {
                try { $enabled_at = new DateTime($candidate, $tz); } catch (Exception $e) { $enabled_at = null; }
            }
        }

        $enabled = false;
        $reason  = '';

        if (!$evento_id) {
            $reason = 'no_event';
        } elseif (trim((string) $url) === '') {
            $reason = 'no_url';
        } elseif (!$enabled_at) {
            $reason = 'no_datetime';
        } elseif ($now < $enabled_at) {
            $reason = 'scheduled';
        } else {
            $enabled = true;
            $reason = 'enabled';
        }

        return [
            'enabled'      => $enabled,
            'reason'       => $reason,
            'url'          => esc_url_raw($url),
            'platform'     => sanitize_text_field($platform),
            'raw_datetime' => sanitize_text_field($raw_dt),
            'enabled_at'   => $enabled_at,
            'now'          => $now,
            'timezone'     => $tz->getName(),
            'landing_url'  => $ticket_id ? eventosapp_get_virtual_landing_url($ticket_id) : '',
        ];
    }
}


/**
 * Diseño compartido para tickets públicos, WhatsApp y landing virtual.
 * Centraliza fallbacks para que email, WhatsApp y landing usen los mismos cabezotes.
 */
if (!function_exists('eventosapp_ticket_system_default_header_url')) {
    function eventosapp_ticket_system_default_header_url() {
        return 'https://eventosapp.com/wp-content/uploads/2025/08/header_ticket_gen.jpg';
    }
}

if (!function_exists('eventosapp_get_whatsapp_template_default_media_url')) {
    function eventosapp_get_whatsapp_template_default_media_url($key) {
        $key = sanitize_key((string) $key);
        if ($key === '') return '';

        if (defined('EVENTOSAPP_WHATSAPP_TEMPLATES_OPTION')) {
            $settings = get_option(EVENTOSAPP_WHATSAPP_TEMPLATES_OPTION, []);
        } else {
            $settings = get_option('eventosapp_whatsapp_templates_settings', []);
        }

        if (!is_array($settings) || empty($settings[$key])) {
            return '';
        }

        return esc_url_raw((string) $settings[$key]);
    }
}

if (!function_exists('eventosapp_get_event_design_asset_url')) {
    function eventosapp_get_event_design_asset_url($event_id, $asset = 'landing_header') {
        $event_id = absint($event_id);
        $asset = sanitize_key((string) $asset);

        $email_header = $event_id ? esc_url_raw((string) get_post_meta($event_id, '_eventosapp_email_header_img', true)) : '';
        $system_header = eventosapp_ticket_system_default_header_url();

        if ($asset === 'whatsapp_qr_header') {
            $custom = $event_id ? esc_url_raw((string) get_post_meta($event_id, '_eventosapp_whatsapp_qr_header_img', true)) : '';
            if ($custom) return $custom;

            $default = eventosapp_get_whatsapp_template_default_media_url('default_whatsapp_qr_header_img');
            if ($default) return $default;

            $landing = $event_id ? esc_url_raw((string) get_post_meta($event_id, '_eventosapp_ticket_landing_header_img', true)) : '';
            if ($landing) return $landing;
            if ($email_header) return $email_header;
            return $system_header;
        }

        if ($asset === 'virtual_message_image') {
            $custom = $event_id ? esc_url_raw((string) get_post_meta($event_id, '_eventosapp_whatsapp_virtual_image_img', true)) : '';
            if ($custom) return $custom;

            $default = eventosapp_get_whatsapp_template_default_media_url('default_virtual_message_img');
            if ($default) return $default;

            $landing = $event_id ? esc_url_raw((string) get_post_meta($event_id, '_eventosapp_ticket_landing_header_img', true)) : '';
            if ($landing) return $landing;
            if ($email_header) return $email_header;
            return $system_header;
        }

        // landing_header / ticket_header
        $custom = $event_id ? esc_url_raw((string) get_post_meta($event_id, '_eventosapp_ticket_landing_header_img', true)) : '';
        if ($custom) return $custom;
        if ($email_header) return $email_header;

        $default = eventosapp_get_whatsapp_template_default_media_url('default_ticket_header_img');
        if ($default) return $default;

        return $system_header;
    }
}

if (!function_exists('eventosapp_get_ticket_design_asset_url')) {
    function eventosapp_get_ticket_design_asset_url($ticket_id, $asset = 'landing_header') {
        $ticket_id = absint($ticket_id);
        $event_id = $ticket_id ? absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true)) : 0;
        return eventosapp_get_event_design_asset_url($event_id, $asset);
    }
}

if (!function_exists('eventosapp_get_ticket_public_assets')) {
    function eventosapp_get_ticket_public_assets($ticket_id, $generate_missing = true) {
        $ticket_id = absint($ticket_id);
        $event_id  = $ticket_id ? absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true)) : 0;
        $is_virtual = $ticket_id && function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id);

        $qr_url = '';
        $qr_path = '';
        if (!$is_virtual) {
            $all_qrs = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
            if (is_array($all_qrs)) {
                if (!empty($all_qrs['whatsapp']['url'])) {
                    $qr_url = esc_url_raw((string) $all_qrs['whatsapp']['url']);
                    $qr_path = !empty($all_qrs['whatsapp']['path']) ? (string) $all_qrs['whatsapp']['path'] : '';
                } elseif (!empty($all_qrs['email']['url'])) {
                    $qr_url = esc_url_raw((string) $all_qrs['email']['url']);
                    $qr_path = !empty($all_qrs['email']['path']) ? (string) $all_qrs['email']['path'] : '';
                }
            }
            if (!$qr_url && function_exists('eventosapp_get_ticket_qr_url')) {
                $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
                $qr_url = $ticket_code ? esc_url_raw(eventosapp_get_ticket_qr_url($ticket_code)) : '';
            }
        }

        $ics_url = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
        if ($generate_missing && !$ics_url && function_exists('eventosapp_ticket_generar_ics')) {
            eventosapp_ticket_generar_ics($ticket_id);
            $ics_url = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
        }

        $pdf_url = '';
        if (!$is_virtual) {
            $pdf_url = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
            if ($generate_missing && !$pdf_url && function_exists('eventosapp_ticket_generar_pdf')) {
                eventosapp_ticket_generar_pdf($ticket_id);
                $pdf_url = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
            }
        }

        $google_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url', true);
        if (!$google_wallet) $google_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android', true);

        $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url', true);
        if (!$apple_wallet) $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple', true);
        if (!$apple_wallet) $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url', true);

        $fecha = '';
        if ($event_id) {
            if (function_exists('eventosapp_whatsapp_get_event_date_label')) {
                $fecha = eventosapp_whatsapp_get_event_date_label($event_id);
            } else {
                $tipo_fecha = get_post_meta($event_id, '_eventosapp_tipo_fecha', true) ?: 'unica';
                if ($tipo_fecha === 'unica') {
                    $fecha_raw = get_post_meta($event_id, '_eventosapp_fecha_unica', true);
                    $fecha = $fecha_raw ? date_i18n('F d, Y', strtotime($fecha_raw)) : '';
                } elseif ($tipo_fecha === 'consecutiva') {
                    $inicio = get_post_meta($event_id, '_eventosapp_fecha_inicio', true);
                    $fin = get_post_meta($event_id, '_eventosapp_fecha_fin', true);
                    $fecha = $inicio ? date_i18n('F d, Y', strtotime($inicio)) : '';
                    if ($fin) $fecha .= ' - ' . date_i18n('F d, Y', strtotime($fin));
                }
            }
        }

        $hora_inicio = $event_id ? (string) get_post_meta($event_id, '_eventosapp_hora_inicio', true) : '';
        $hora_cierre = $event_id ? (string) get_post_meta($event_id, '_eventosapp_hora_cierre', true) : '';
        $hora = trim($hora_inicio . ($hora_cierre ? ' - ' . $hora_cierre : ''));

        $organizador = '';
        if ($event_id) {
            $organizador = function_exists('eventosapp_get_nombre_organizador') ? eventosapp_get_nombre_organizador($event_id) : get_post_meta($event_id, '_eventosapp_organizador', true);
        }

        $virtual_landing = '';
        if ($ticket_id && function_exists('eventosapp_get_virtual_landing_url')) {
            $virtual_landing = eventosapp_get_virtual_landing_url($ticket_id);
        }

        return [
            'ticket_id' => $ticket_id,
            'event_id' => $event_id,
            'event_name' => $event_id ? get_the_title($event_id) : 'Evento',
            'organizador' => sanitize_text_field((string) $organizador),
            'asistente' => trim((string)get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' . (string)get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true)),
            'ticket_code' => get_post_meta($ticket_id, 'eventosapp_ticketID', true),
            'modalidad' => function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label($ticket_id) : get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true),
            'is_virtual' => (bool) $is_virtual,
            'fecha' => $fecha,
            'hora' => $hora,
            'lugar' => $event_id ? get_post_meta($event_id, '_eventosapp_direccion', true) : '',
            'platform' => $event_id ? get_post_meta($event_id, '_eventosapp_virtual_platform', true) : '',
            'platform_url' => function_exists('eventosapp_get_ticket_virtual_platform_url') ? eventosapp_get_ticket_virtual_platform_url($ticket_id) : ($event_id ? get_post_meta($event_id, '_eventosapp_virtual_url', true) : ''),
            'qr' => esc_url_raw((string) $qr_url),
            'qr_path' => $qr_path,
            'ics' => esc_url_raw((string) $ics_url),
            'pdf' => esc_url_raw((string) $pdf_url),
            'google_wallet' => esc_url_raw((string) $google_wallet),
            'apple_wallet' => esc_url_raw((string) $apple_wallet),
            'virtual_landing' => esc_url_raw((string) $virtual_landing),
            'header_img' => eventosapp_get_ticket_design_asset_url($ticket_id, 'landing_header'),
            'whatsapp_qr_header_img' => eventosapp_get_ticket_design_asset_url($ticket_id, 'whatsapp_qr_header'),
            'virtual_message_img' => eventosapp_get_ticket_design_asset_url($ticket_id, 'virtual_message_image'),
        ];
    }
}

if (!function_exists('eventosapp_virtual_ticket_landing_message')) {
    function eventosapp_virtual_ticket_landing_message($state) {
        $reason = is_array($state) ? ($state['reason'] ?? '') : '';
        if ($reason === 'no_url') return 'El enlace del evento virtual todavía no ha sido configurado por el organizador.';
        if ($reason === 'no_datetime') return 'El botón de ingreso todavía no está habilitado porque no se ha configurado la fecha y hora de activación.';
        if ($reason === 'scheduled') return 'El botón de ingreso todavía no está habilitado. Vuelve a intentarlo cuando llegue la fecha y hora de activación.';
        return 'El botón de ingreso todavía no está habilitado.';
    }
}

if (!function_exists('eventosapp_virtual_ticket_landing_router')) {
    function eventosapp_virtual_ticket_landing_router() {
        $public_id = '';
        $token = '';
        $ticket_id = 0;
        $valid = false;

        if (!empty($_GET['evapp_vticket'])) {
            $public_id = sanitize_text_field(wp_unslash($_GET['evapp_vticket']));
            $token     = sanitize_text_field(wp_unslash($_GET['evapp_vtoken'] ?? ''));
            $ticket_id = eventosapp_find_ticket_by_public_id($public_id);

            if ($ticket_id) {
                $stored_token = (string) get_post_meta($ticket_id, '_eventosapp_virtual_access_token', true);
                $valid = ($stored_token !== '' && $token !== '' && hash_equals($stored_token, $token));
            }
        } else {
            $request_path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
            $path_only = $request_path ? (string) wp_parse_url($request_path, PHP_URL_PATH) : '';
            $is_virtual_path = (strpos(trim($path_only, '/'), 'virtual/') === 0 || trim($path_only, '/') === 'virtual');

            if ($is_virtual_path && (!empty($_GET['ticket_pub']) || !empty($_GET['ticket_id']))) {
                if (!empty($_GET['ticket_pub'])) {
                    $public_id = sanitize_text_field(wp_unslash($_GET['ticket_pub']));
                    $ticket_id = eventosapp_find_ticket_by_public_id($public_id);
                } else {
                    $ticket_id = absint($_GET['ticket_id']);
                }
                $valid = $ticket_id > 0;
            } else {
                return;
            }
        }

        if (!$ticket_id || !$valid || !eventosapp_ticket_is_virtual($ticket_id)) {
            status_header(404);
            nocache_headers();
            echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acceso no disponible</title></head><body style="font-family:Arial,sans-serif;background:#f6f8fb;margin:0;padding:32px"><div style="max-width:720px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:24px"><h1>Acceso no disponible</h1><p>El enlace del ticket no es válido o ya no está disponible.</p></div></body></html>';
            exit;
        }

        $assets        = function_exists('eventosapp_get_ticket_public_assets') ? eventosapp_get_ticket_public_assets($ticket_id, true) : [];
        $evento_id      = absint($assets['event_id'] ?? get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        $evento_nombre  = (string)($assets['event_name'] ?? ($evento_id ? get_the_title($evento_id) : 'Evento'));
        $state          = eventosapp_event_virtual_access_state($evento_id, $ticket_id);
        $platform       = !empty($assets['platform']) ? $assets['platform'] : ($state['platform'] ?: 'Plataforma virtual');
        $enabled_at_txt = '';

        if (!empty($state['enabled_at']) && $state['enabled_at'] instanceof DateTime) {
            $enabled_at_txt = $state['enabled_at']->format('Y-m-d H:i') . ' (' . $state['timezone'] . ')';
        }

        $wallet_google_img = function_exists('eventosapp_asset_url_with_version') ? eventosapp_asset_url_with_version('assets/graphics/wallet_icons/google_wallet_btn.png') : '';
        $wallet_apple_img  = function_exists('eventosapp_asset_url_with_version') ? eventosapp_asset_url_with_version('assets/graphics/wallet_icons/apple_wallet_btn.png') : '';

        status_header(200);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex,nofollow">
            <title><?php echo esc_html($evento_nombre); ?> - Acceso virtual</title>
            <?php wp_head(); ?>
            <style>
                body.evapp-virtual-ticket-body{margin:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;}
                .evapp-ticket-wrap{max-width:760px;margin:0 auto;padding:28px 14px;}
                .evapp-ticket-card{background:#fff;border:1px solid #e5e7eb;border-radius:20px;box-shadow:0 14px 34px rgba(15,23,42,.10);overflow:hidden;}
                .evapp-ticket-header img{display:block;width:100%;height:auto;}
                .evapp-ticket-body{padding:26px 24px 18px;text-align:center;}
                .evapp-ticket-title{margin:0 0 8px;font-size:28px;line-height:1.18;color:#111827;}
                .evapp-ticket-subtitle{margin:0;color:#6b7280;font-size:15px;}
                .evapp-ticket-hero{margin:18px auto 0;max-width:520px;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb;background:#f9fafb;}
                .evapp-ticket-hero img{display:block;width:100%;height:auto;}
                .evapp-ticket-kvs{margin:20px 0 0;text-align:left;background:#f9fafb;border:1px dashed #d1d5db;border-radius:14px;padding:14px 16px;line-height:1.65;}
                .evapp-ticket-kv strong{display:inline-block;min-width:112px;color:#111827;}
                .evapp-ticket-actions{padding:22px 24px 28px;background:#f9fafb;border-top:1px solid #e5e7eb;text-align:center;}
                .evapp-ticket-button{display:inline-block;min-width:220px;text-align:center;text-decoration:none;background:#111827;color:#fff!important;padding:14px 22px;border-radius:12px;font-weight:800;margin:6px auto;box-sizing:border-box;}
                .evapp-ticket-button.secondary{background:#0f766e;}
                .evapp-ticket-button.neutral{background:#374151;}
                .evapp-ticket-button.disabled{background:#e5e7eb;color:#6b7280!important;cursor:not-allowed;}
                .evapp-ticket-note{margin:12px auto 0;max-width:560px;color:#6b7280;font-size:14px;line-height:1.45;}
                .evapp-wallet-row{display:flex;justify-content:center;align-items:center;gap:10px;flex-wrap:wrap;margin:10px 0 12px;}
                .evapp-wallet-row a{display:inline-block;line-height:0;}
                .evapp-wallet-row img{width:190px;max-width:100%;height:auto;}
                @media(max-width:560px){.evapp-ticket-body,.evapp-ticket-actions{padding-left:16px;padding-right:16px}.evapp-ticket-title{font-size:24px}.evapp-ticket-kv strong{display:block;min-width:0}.evapp-ticket-button{width:100%;}}
            </style>
        </head>
        <body class="evapp-virtual-ticket-body">
            <main class="evapp-ticket-wrap">
                <section class="evapp-ticket-card">
                    <?php if (!empty($assets['header_img'])): ?>
                        <div class="evapp-ticket-header"><img src="<?php echo esc_url($assets['header_img']); ?>" alt="<?php echo esc_attr($evento_nombre); ?>"></div>
                    <?php endif; ?>
                    <div class="evapp-ticket-body">
                        <h1 class="evapp-ticket-title"><?php echo esc_html($evento_nombre); ?></h1>
                        <p class="evapp-ticket-subtitle">Acceso virtual del evento</p>

                        <?php if (!empty($assets['virtual_message_img'])): ?>
                            <div class="evapp-ticket-hero"><img src="<?php echo esc_url($assets['virtual_message_img']); ?>" alt="Acceso virtual"></div>
                        <?php endif; ?>

                        <div class="evapp-ticket-kvs">
                            <?php if (!empty($assets['asistente'])): ?><div class="evapp-ticket-kv"><strong>Asistente:</strong> <?php echo esc_html($assets['asistente']); ?></div><?php endif; ?>
                            <?php if (!empty($assets['ticket_code'])): ?><div class="evapp-ticket-kv"><strong>Ticket:</strong> <?php echo esc_html($assets['ticket_code']); ?></div><?php endif; ?>
                            <?php if (!empty($assets['organizador'])): ?><div class="evapp-ticket-kv"><strong>Organizador:</strong> <?php echo esc_html($assets['organizador']); ?></div><?php endif; ?>
                            <div class="evapp-ticket-kv"><strong>Modalidad:</strong> Virtual</div>
                            <?php if (!empty($assets['fecha'])): ?><div class="evapp-ticket-kv"><strong>Fecha:</strong> <?php echo esc_html($assets['fecha']); ?></div><?php endif; ?>
                            <?php if (!empty($assets['hora'])): ?><div class="evapp-ticket-kv"><strong>Hora:</strong> <?php echo esc_html($assets['hora']); ?></div><?php endif; ?>
                            <div class="evapp-ticket-kv"><strong>Plataforma:</strong> <?php echo esc_html($platform); ?></div>
                            <?php if ($enabled_at_txt): ?><div class="evapp-ticket-kv"><strong>Disponible desde:</strong> <?php echo esc_html($enabled_at_txt); ?></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="evapp-ticket-actions">
                        <div class="evapp-wallet-row">
                            <?php if (!empty($assets['google_wallet']) && $wallet_google_img): ?><a href="<?php echo esc_url($assets['google_wallet']); ?>" target="_blank" rel="noopener noreferrer"><img src="<?php echo esc_url($wallet_google_img); ?>" alt="Agregar a Google Wallet"></a><?php endif; ?>
                            <?php if (!empty($assets['apple_wallet']) && $wallet_apple_img): ?><a href="<?php echo esc_url($assets['apple_wallet']); ?>" target="_blank" rel="noopener noreferrer"><img src="<?php echo esc_url($wallet_apple_img); ?>" alt="Agregar a Apple Wallet"></a><?php endif; ?>
                        </div>
                        <?php if (!empty($assets['ics'])): ?><a class="evapp-ticket-button secondary" href="<?php echo esc_url($assets['ics']); ?>" target="_blank" rel="noopener noreferrer">Agregar a agenda</a><?php endif; ?>
                        <?php if (!empty($assets['pdf'])): ?><a class="evapp-ticket-button neutral" href="<?php echo esc_url($assets['pdf']); ?>" target="_blank" rel="noopener noreferrer">Descargar PDF</a><?php endif; ?>

                        <?php if (!empty($state['enabled'])): ?>
                            <a class="evapp-ticket-button" href="<?php echo esc_url($state['url']); ?>" target="_blank" rel="noopener noreferrer">Entrar al evento</a>
                            <p class="evapp-ticket-note">El enlace ya está habilitado. Si la plataforma abre en otra pestaña, conserva esta página como respaldo.</p>
                        <?php else: ?>
                            <span class="evapp-ticket-button disabled">Botón no habilitado</span>
                            <p class="evapp-ticket-note"><?php echo esc_html(eventosapp_virtual_ticket_landing_message($state)); ?></p>
                        <?php endif; ?>
                    </div>
                </section>
            </main>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        exit;
    }
    add_action('template_redirect', 'eventosapp_virtual_ticket_landing_router', 0);
}
