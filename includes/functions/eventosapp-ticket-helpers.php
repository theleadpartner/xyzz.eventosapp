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
        if (empty($_GET['evapp_vticket'])) return;

        $public_id = sanitize_text_field(wp_unslash($_GET['evapp_vticket']));
        $token     = sanitize_text_field(wp_unslash($_GET['evapp_vtoken'] ?? ''));
        $ticket_id = eventosapp_find_ticket_by_public_id($public_id);

        $valid = false;
        if ($ticket_id) {
            $stored_token = (string) get_post_meta($ticket_id, '_eventosapp_virtual_access_token', true);
            $valid = ($stored_token !== '' && $token !== '' && hash_equals($stored_token, $token));
        }

        if (!$ticket_id || !$valid || !eventosapp_ticket_is_virtual($ticket_id)) {
            status_header(404);
            nocache_headers();
            echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acceso no disponible</title></head><body style="font-family:Arial,sans-serif;background:#f6f8fb;margin:0;padding:32px"><div style="max-width:720px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:24px"><h1>Acceso no disponible</h1><p>El enlace del ticket no es válido o ya no está disponible.</p></div></body></html>';
            exit;
        }

        $evento_id        = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
        $evento_nombre    = $evento_id ? get_the_title($evento_id) : 'Evento';
        $asistente_nombre = trim((string) get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' . (string) get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true));
        $ticket_code      = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
        $state            = eventosapp_event_virtual_access_state($evento_id, $ticket_id);
        $platform         = $state['platform'] ?: 'Plataforma virtual';
        $enabled_at_txt   = '';

        if (!empty($state['enabled_at']) && $state['enabled_at'] instanceof DateTime) {
            $enabled_at_txt = $state['enabled_at']->format('Y-m-d H:i') . ' (' . $state['timezone'] . ')';
        }

        status_header(200);
        nocache_headers();
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($evento_nombre); ?> - Acceso virtual</title>
            <?php wp_head(); ?>
            <style>
                body.evapp-virtual-ticket-body{margin:0;background:#f6f8fb;font-family:Arial,Helvetica,sans-serif;color:#111827;}
                .evapp-virtual-ticket-wrap{max-width:780px;margin:0 auto;padding:32px 16px;}
                .evapp-virtual-ticket-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 10px 30px rgba(15,23,42,.06);overflow:hidden;}
                .evapp-virtual-ticket-head{padding:24px 24px 8px;}
                .evapp-virtual-ticket-head h1{margin:0 0 8px;font-size:28px;line-height:1.2;}
                .evapp-virtual-ticket-head p{margin:0;color:#6b7280;}
                .evapp-virtual-ticket-info{padding:0 24px 18px;}
                .evapp-virtual-ticket-kvs{background:#fafafa;border:1px dashed #e5e7eb;border-radius:12px;padding:14px;margin:16px 0;}
                .evapp-virtual-ticket-kv{margin:7px 0;}
                .evapp-virtual-ticket-kv b{display:inline-block;min-width:120px;}
                .evapp-virtual-ticket-actions{padding:22px 24px 28px;text-align:center;background:#f9fafb;border-top:1px solid #e5e7eb;}
                .evapp-virtual-ticket-button{display:inline-block;background:#111827;color:#fff!important;text-decoration:none;font-weight:700;border-radius:12px;padding:14px 22px;}
                .evapp-virtual-ticket-button-disabled{display:inline-block;background:#e5e7eb;color:#6b7280!important;text-decoration:none;font-weight:700;border-radius:12px;padding:14px 22px;cursor:not-allowed;}
                .evapp-virtual-ticket-note{margin:14px auto 0;max-width:560px;color:#6b7280;font-size:14px;line-height:1.45;}
            </style>
        </head>
        <body class="evapp-virtual-ticket-body">
            <div class="evapp-virtual-ticket-wrap">
                <div class="evapp-virtual-ticket-card">
                    <div class="evapp-virtual-ticket-head">
                        <h1><?php echo esc_html($evento_nombre); ?></h1>
                        <p>Acceso virtual del evento</p>
                    </div>
                    <div class="evapp-virtual-ticket-info">
                        <div class="evapp-virtual-ticket-kvs">
                            <div class="evapp-virtual-ticket-kv"><b>Asistente:</b> <?php echo esc_html($asistente_nombre ?: '—'); ?></div>
                            <div class="evapp-virtual-ticket-kv"><b>Ticket:</b> <?php echo esc_html($ticket_code ?: '—'); ?></div>
                            <div class="evapp-virtual-ticket-kv"><b>Modalidad:</b> Virtual</div>
                            <div class="evapp-virtual-ticket-kv"><b>Plataforma:</b> <?php echo esc_html($platform); ?></div>
                            <?php if ($enabled_at_txt): ?>
                                <div class="evapp-virtual-ticket-kv"><b>Disponible desde:</b> <?php echo esc_html($enabled_at_txt); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="evapp-virtual-ticket-actions">
                        <?php if (!empty($state['enabled'])): ?>
                            <a class="evapp-virtual-ticket-button" href="<?php echo esc_url($state['url']); ?>" target="_blank" rel="noopener noreferrer">Entrar al evento</a>
                            <p class="evapp-virtual-ticket-note">El enlace ya está habilitado. Si la plataforma abre en otra pestaña, conserva esta página como respaldo.</p>
                        <?php else: ?>
                            <span class="evapp-virtual-ticket-button-disabled">Botón no habilitado</span>
                            <p class="evapp-virtual-ticket-note"><?php echo esc_html(eventosapp_virtual_ticket_landing_message($state)); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        exit;
    }
    add_action('template_redirect', 'eventosapp_virtual_ticket_landing_router', 0);
}
