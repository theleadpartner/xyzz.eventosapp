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


