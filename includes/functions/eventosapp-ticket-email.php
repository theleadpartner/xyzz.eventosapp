<?php
/**
 * Módulo de envío de correo de tickets (handler + render HTML)
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** ============ Helpers internos ============ */

if (!function_exists('eventosapp_email_templates_dir')) {
    function eventosapp_email_templates_dir(){
        // este archivo vive en includes/functions/, subimos a includes/ y luego templates/email_tickets/
        return trailingslashit( dirname(__FILE__, 2) ) . 'templates/email_tickets/';
    }
}

if (!function_exists('eventosapp_event_from_name')) {
    function eventosapp_event_from_name($evento_id){
        $custom = $evento_id ? (get_post_meta($evento_id, '_eventosapp_email_fromname', true) ?: '') : '';
        $name = $custom ?: wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        // Evitar header injection
        $name = preg_replace("/[\r\n]+/u", ' ', trim($name));
        return $name ?: 'EventosApp';
    }
}

/**
 * Devuelve la URL absoluta a un asset dentro de /includes/assets/ con ?ver=filemtime
 * $relative_from_includes: ruta relativa desde /includes/, p.ej. 'assets/graphics/wallet_icons/google_wallet_btn.png'
 */
if (!function_exists('eventosapp_asset_url_with_version')) {
    function eventosapp_asset_url_with_version($relative_from_includes){
        $includes_dir = trailingslashit(dirname(__FILE__, 2)); // .../tu-plugin/includes/
        $abs = $includes_dir . ltrim($relative_from_includes, '/');

        // Construir URL a partir del path físico (plugins viven bajo WP_CONTENT_DIR)
        $rel_to_content = ltrim(str_replace(WP_CONTENT_DIR, '', $abs), '/');
        $url = content_url($rel_to_content);

        if (file_exists($abs)) {
            $url = add_query_arg('ver', filemtime($abs), $url);
        }
        return esc_url_raw($url);
    }
}

if (!function_exists('eventosapp_build_subject_for_ticket')) {
    function eventosapp_build_subject_for_ticket($ticket_id, $fallback){
        $evento_id      = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
        $evento_nombre  = $evento_id ? get_the_title($evento_id) : 'Evento';
        $ticket_code    = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
        $asistente_nom  = trim(
            (string) get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' .
            (string) get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true)
        );

        $custom_subj = $evento_id ? (get_post_meta($evento_id, '_eventosapp_email_subject', true) ?: '') : '';
        if (!$custom_subj) {
            return $fallback ?: ('Tu ticket para ' . $evento_nombre);
        }

        // Reemplazar tokens simples
        $tokens = [
            '{{evento_nombre}}'    => $evento_nombre,
            '{{asistente_nombre}}' => $asistente_nom,
            '{{ticket_id}}'        => $ticket_code,
        ];
        $subject = strtr($custom_subj, $tokens);
        // Limpiar
        $subject = wp_strip_all_tags($subject);
        if (strlen($subject) > 190) $subject = mb_substr($subject, 0, 190);
        return $subject ?: ($fallback ?: ('Tu ticket para ' . $evento_nombre));
    }
}

/**
 * Hook: acción admin_post para disparar el envío manual desde la metabox.
 */
add_action('admin_post_eventosapp_send_ticket_email', 'eventosapp_send_ticket_email_handler');

/**
 * Handler del envío de correo del ticket (uso manual desde admin).
 * Ahora asegura que los enlaces de Wallet (Android/Apple) existan antes de construir el HTML,
 * y registra el historial de envíos.
 */
function eventosapp_send_ticket_email_handler() {
    $nonce = $_REQUEST['_wpnonce'] ?? ($_REQUEST['eventosapp_send_ticket_email_nonce'] ?? '');
    if (!$nonce || !wp_verify_nonce($nonce, 'eventosapp_send_ticket_email')) {
        wp_die('Nonce inválido');
    }

    $post_id = intval($_REQUEST['post_id'] ?? 0);
    if (!$post_id || get_post_type($post_id) !== 'eventosapp_ticket') {
        wp_die('Ticket inválido');
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_die('No tienes permisos para enviar este correo.');
    }

    // ¿A quién enviamos?
    $asistente_email = get_post_meta($post_id, '_eventosapp_asistente_email', true);
    $recipient = $asistente_email;

    // Si es admin y pasó un correo alterno
    if (current_user_can('manage_options') && !empty($_REQUEST['eventosapp_email_alt_from_metabox'])) {
        $alt = sanitize_email($_REQUEST['eventosapp_email_alt'] ?? '');
        if ($alt) {
            $recipient = $alt;
        }
    }

    if (!$recipient || !is_email($recipient)) {
        eventosapp_ticket_email_redirect($post_id, false, 'Correo destino inválido.');
        return;
    }

    $evento_id = (int) get_post_meta($post_id, '_eventosapp_ticket_evento_id', true);
    if (!$evento_id) {
        eventosapp_ticket_email_redirect($post_id, false, 'Ticket sin evento asignado.');
        return;
    }

    // Asegurar enlaces de Wallet antes de armar HTML
    $wa_on = get_post_meta($evento_id, '_eventosapp_ticket_wallet_android', true) === '1';
    $wi_on = get_post_meta($evento_id, '_eventosapp_ticket_wallet_apple', true)   === '1';

    if ($wa_on && !get_post_meta($post_id, '_eventosapp_ticket_wallet_android_url', true)) {
        if (function_exists('eventosapp_generate_wallet_android_ticket')) {
            eventosapp_generate_wallet_android_ticket($post_id);
        }
    }
    if ($wi_on && !get_post_meta($post_id, '_eventosapp_ticket_wallet_apple', true)) {
        if (function_exists('eventosapp_generate_wallet_apple_pass')) {
            eventosapp_generate_wallet_apple_pass($post_id);
        }
    }

    // Attachments
    $attachments = [];
    $pdf_on = get_post_meta($evento_id, '_eventosapp_ticket_pdf', true) === '1';
    $ics_on = get_post_meta($evento_id, '_eventosapp_ticket_ics', true) === '1';

    if ($pdf_on && function_exists('eventosapp_url_to_path')) {
        $pdf_url  = get_post_meta($post_id, '_eventosapp_ticket_pdf_url', true);
        $pdf_path = $pdf_url ? eventosapp_url_to_path($pdf_url) : '';
        if ($pdf_path && file_exists($pdf_path)) {
            $attachments[] = $pdf_path;
        }
    }
    if ($ics_on && function_exists('eventosapp_url_to_path')) {
        $ics_url  = get_post_meta($post_id, '_eventosapp_ticket_ics_url', true);
        $ics_path = $ics_url ? eventosapp_url_to_path($ics_url) : '';
        if ($ics_path && file_exists($ics_path)) {
            $attachments[] = $ics_path;
        }
    }

    // Asunto (respeta override por evento)
    $evento_nombre    = $evento_id ? get_the_title($evento_id) : 'Evento';
    $subject_fallback = '¡Inscripción confirmada! ' . $evento_nombre;
    $subject          = eventosapp_build_subject_for_ticket($post_id, $subject_fallback);

    // HTML
    $html = eventosapp_build_ticket_email_html($post_id);

    // Cabeceras: From con nombre personalizado (dominio propio para evitar DMARC)
    $site_host  = parse_url(home_url(), PHP_URL_HOST);
    if (!$site_host) {
        $site_host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    }
    $site_host  = preg_replace('/^www\./i', '', $site_host);
    $from_email = sanitize_email('no-reply@' . $site_host);
    if (!$from_email) {
        $admin_fallback = get_option('admin_email');
        $from_email = is_email($admin_fallback) ? $admin_fallback : 'no-reply@' . $site_host;
    }
    $from_name = eventosapp_event_from_name($evento_id);

    $organizador_email = $evento_id ? (get_post_meta($evento_id, '_eventosapp_organizador_email', true) ?: '') : '';
    $organizador_name  = $evento_id ? (get_post_meta($evento_id, '_eventosapp_organizador', true) ?: '') : '';

    $base_headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from_email . '>',
    ];
    if (is_email($organizador_email)) {
        $base_headers[] = 'Reply-To: ' . ($organizador_name ? $organizador_name : $from_name) . ' <' . $organizador_email . '>';
    }

    $headers = apply_filters(
        'eventosapp_ticket_email_headers',
        $base_headers,
        $post_id,
        ['source' => 'admin']
    );

    update_post_meta($post_id, '_eventosapp_ticket_email_debug_admin', [
        'at'          => current_time('mysql'),
        'recipient'   => $recipient,
        'subject'     => $subject,
        'attachments' => $attachments,
        'headers'     => $headers,
        'source'      => 'admin',
    ]);

    $ok = wp_mail($recipient, $subject, $html, $headers, $attachments);

    if ($ok) {
        $current_datetime = current_time('mysql');
        
        // Actualizar último envío (legacy)
        update_post_meta($post_id, '_eventosapp_ticket_last_email_to', sanitize_email($recipient));
        update_post_meta($post_id, '_eventosapp_ticket_last_email_at', $current_datetime);
        
        // === NUEVO: TRACKING DE HISTORIAL DE ENVÍOS ===
        
        // 1. Registrar PRIMERA fecha de envío (inmutable)
        $first_sent = get_post_meta($post_id, '_eventosapp_ticket_email_first_sent', true);
        if (empty($first_sent)) {
            update_post_meta($post_id, '_eventosapp_ticket_email_first_sent', $current_datetime);
        }
        
        // 2. Marcar status como "enviado"
        update_post_meta($post_id, '_eventosapp_ticket_email_sent_status', 'enviado');
        
        // 3. Actualizar historial de últimos 3 envíos
        $history = get_post_meta($post_id, '_eventosapp_ticket_email_history', true);
        if (!is_array($history)) {
            $history = [];
        }
        
        // Agregar nuevo envío al inicio del array
        array_unshift($history, [
            'fecha'        => $current_datetime,
            'destinatario' => $recipient,
            'source'       => 'manual',
        ]);
        
        // Mantener solo los últimos 3 envíos
        $history = array_slice($history, 0, 3);
        
        update_post_meta($post_id, '_eventosapp_ticket_email_history', $history);
        
        // === FIN TRACKING ===
        
        eventosapp_ticket_email_redirect($post_id, true, 'Correo enviado correctamente a ' . $recipient);
    } else {
        eventosapp_ticket_email_redirect($post_id, false, 'No se pudo enviar el correo. Revisa configuración SMTP/hosting.');
    }
}

/**
 * Redirección con aviso
 */
function eventosapp_ticket_email_redirect($post_id, $success, $msg) {
    $url = add_query_arg([
        'post'       => $post_id,
        'action'     => 'edit',
        'evapp_mail' => $success ? '1' : '0',
        'evapp_msg'  => rawurlencode($msg),
    ], admin_url('post.php'));
    wp_safe_redirect($url);
    exit;
}


/**
 * Envío programático de correo de ticket
 * Ahora registra historial de envíos: primera fecha, último envío y los 3 últimos envíos
 * 
 * @param int $ticket_id ID del ticket
 * @param array $args Argumentos opcionales
 * @return array [bool $success, string $message]
 */
function eventosapp_send_ticket_email_now($ticket_id, $args = []) {
    $ticket_id = intval($ticket_id);
    if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
        return [false, 'Ticket inválido'];
    }

    $defaults = [
        'recipient' => null,
        'subject'   => null,
        'source'    => 'manual',
        'force'     => false,
    ];
    $args   = wp_parse_args($args, $defaults);
    $source = is_string($args['source']) ? strtolower($args['source']) : 'manual';

    // Idempotencia: evitar reenvíos automáticos por webhook
    if ($source === 'webhook' && empty($args['force'])) {
        $already = get_post_meta($ticket_id, '_eventosapp_ticket_email_webhook_sent', true);
        if (!empty($already)) {
            return [false, 'Correo ya enviado por webhook: ' . $already];
        }
    }

    // Destinatario
    $asistente_email = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
    $recipient = !empty($args['recipient']) ? sanitize_email($args['recipient']) : $asistente_email;
    if (!$recipient || !is_email($recipient)) {
        return [false, 'Destinatario inválido: ' . $recipient];
    }

    $evento_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    if (!$evento_id) {
        return [false, 'Ticket sin evento asignado'];
    }

    // Asegurar enlaces de Wallet antes de armar HTML
    $wa_on = get_post_meta($evento_id, '_eventosapp_ticket_wallet_android', true) === '1';
    $wi_on = get_post_meta($evento_id, '_eventosapp_ticket_wallet_apple', true)   === '1';

    if ($wa_on && !get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url', true)) {
        if (function_exists('eventosapp_generate_wallet_android_ticket')) {
            eventosapp_generate_wallet_android_ticket($ticket_id);
        }
    }
    if ($wi_on && !get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple', true)) {
        if (function_exists('eventosapp_generate_wallet_apple_pass')) {
            eventosapp_generate_wallet_apple_pass($ticket_id);
        }
    }

    // Attachments
    $attachments = [];
    $pdf_on = get_post_meta($evento_id, '_eventosapp_ticket_pdf', true) === '1';
    $ics_on = get_post_meta($evento_id, '_eventosapp_ticket_ics', true) === '1';

    if ($pdf_on && function_exists('eventosapp_url_to_path')) {
        $pdf_url  = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
        $pdf_path = $pdf_url ? eventosapp_url_to_path($pdf_url) : '';
        if ($pdf_path && file_exists($pdf_path)) {
            $attachments[] = $pdf_path;
        }
    }
    if ($ics_on && function_exists('eventosapp_url_to_path')) {
        $ics_url  = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
        $ics_path = $ics_url ? eventosapp_url_to_path($ics_url) : '';
        if ($ics_path && file_exists($ics_path)) {
            $attachments[] = $ics_path;
        }
    }

    // Asunto
    $evento_nombre = $evento_id ? get_the_title($evento_id) : 'Evento';
    $fallback      = 'Tu ticket para ' . $evento_nombre;
    $subject       = $args['subject'] ?: eventosapp_build_subject_for_ticket($ticket_id, $fallback);

    // HTML
    $html = eventosapp_build_ticket_email_html($ticket_id);

    // Cabeceras
    $site_host  = parse_url(home_url(), PHP_URL_HOST);
    if (!$site_host) {
        $site_host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    }
    $site_host  = preg_replace('/^www\./i', '', $site_host);
    $from_email = sanitize_email('no-reply@' . $site_host);
    if (!$from_email) {
        $admin_fallback = get_option('admin_email');
        $from_email = is_email($admin_fallback) ? $admin_fallback : 'no-reply@' . $site_host;
    }
    $from_name = eventosapp_event_from_name($evento_id);

    $organizador_email = $evento_id ? (get_post_meta($evento_id, '_eventosapp_organizador_email', true) ?: '') : '';
    $organizador_name  = $evento_id ? (get_post_meta($evento_id, '_eventosapp_organizador', true) ?: '') : '';

    $base_headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from_email . '>',
    ];
    if (is_email($organizador_email)) {
        $base_headers[] = 'Reply-To: ' . ($organizador_name ? $organizador_name : $from_name) . ' <' . $organizador_email . '>';
    }

    $headers = apply_filters(
        'eventosapp_ticket_email_headers',
        $base_headers,
        $ticket_id,
        ['source' => $source]
    );

    // Debug info
    update_post_meta($ticket_id, '_eventosapp_ticket_email_debug', [
        'at'          => current_time('mysql'),
        'recipient'   => $recipient,
        'subject'     => $subject,
        'attachments' => $attachments,
        'headers'     => $headers,
        'source'      => $source,
    ]);

    // Enviar correo
    $ok = wp_mail($recipient, $subject, $html, $headers, $attachments);

    if ($ok) {
        $current_datetime = current_time('mysql');
        
        // Actualizar último envío (campos legacy mantenidos por compatibilidad)
        update_post_meta($ticket_id, '_eventosapp_ticket_last_email_to', $recipient);
        update_post_meta($ticket_id, '_eventosapp_ticket_last_email_at', $current_datetime);
        
        // === NUEVO: TRACKING DE HISTORIAL DE ENVÍOS ===
        
        // 1. Registrar PRIMERA fecha de envío (inmutable)
        $first_sent = get_post_meta($ticket_id, '_eventosapp_ticket_email_first_sent', true);
        if (empty($first_sent)) {
            update_post_meta($ticket_id, '_eventosapp_ticket_email_first_sent', $current_datetime);
        }
        
        // 2. Marcar status como "enviado"
        update_post_meta($ticket_id, '_eventosapp_ticket_email_sent_status', 'enviado');
        
        // 3. Actualizar historial de últimos 3 envíos
        $history = get_post_meta($ticket_id, '_eventosapp_ticket_email_history', true);
        if (!is_array($history)) {
            $history = [];
        }
        
        // Agregar nuevo envío al inicio del array
        array_unshift($history, [
            'fecha'       => $current_datetime,
            'destinatario' => $recipient,
            'source'      => $source,
        ]);
        
        // Mantener solo los últimos 3 envíos
        $history = array_slice($history, 0, 3);
        
        update_post_meta($ticket_id, '_eventosapp_ticket_email_history', $history);
        
        // === FIN TRACKING ===
        
        // Marca específica para webhook
        if ($source === 'webhook') {
            update_post_meta($ticket_id, '_eventosapp_ticket_email_webhook_sent', $current_datetime);
        }
        
        do_action('eventosapp_ticket_email_sent', $ticket_id, $recipient, $args);

        return [true, 'Correo enviado correctamente a ' . $recipient];
    }

    return [false, 'No se pudo enviar el correo. Revisa configuración SMTP/hosting.'];
}

/**
 * Construye el HTML del correo desde la plantilla seleccionada
 * Si Wallet (Android/Apple) está activo y la URL no existe aún, intenta generarla en caliente
 * para que los botones del email siempre aparezcan.
 */
function eventosapp_build_ticket_email_html($ticket_id) {
    $evento_id   = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);

    $header_img = get_post_meta($evento_id, '_eventosapp_email_header_img', true);
    if (!$header_img) {
        $header_img = 'https://eventosapp.com/wp-content/uploads/2025/08/header_ticket_gen.jpg';
    }

    // Flags del evento
    $pdf_on = get_post_meta($evento_id, '_eventosapp_ticket_pdf', true)            === '1';
    $ics_on = get_post_meta($evento_id, '_eventosapp_ticket_ics', true)            === '1';
    $wa_on  = get_post_meta($evento_id, '_eventosapp_ticket_wallet_android', true) === '1';
    $wi_on  = get_post_meta($evento_id, '_eventosapp_ticket_wallet_apple', true)   === '1';

    // Datos evento
    $evento_nombre = $evento_id ? get_the_title($evento_id) : '';
    $organizador   = $evento_id ? (get_post_meta($evento_id, '_eventosapp_organizador', true) ?: '') : '';
    $lugar_evento  = $evento_id ? (get_post_meta($evento_id, '_eventosapp_direccion', true) ?: '') : '';

    // Fecha legible
    $tipo_fecha = $evento_id ? get_post_meta($evento_id, '_eventosapp_tipo_fecha', true) : '';
    if ($tipo_fecha === 'unica') {
        $fecha_evento  = get_post_meta($evento_id, '_eventosapp_fecha_unica', true);
        $fecha_legible = $fecha_evento ? date_i18n('F d, Y', strtotime($fecha_evento)) : '';
    } elseif ($tipo_fecha === 'consecutiva') {
        $inicio        = get_post_meta($evento_id, '_eventosapp_fecha_inicio', true);
        $fecha_legible = $inicio ? date_i18n('F d, Y', strtotime($inicio)) : '';
    } else {
        $fechas = $evento_id ? get_post_meta($evento_id, '_eventosapp_fechas_noco', true) : [];
        if (is_string($fechas)) $fechas = @unserialize($fechas);
        if (!is_array($fechas)) $fechas = [];
        $fecha_legible = $fechas ? implode(', ', array_map(function($f){ return date_i18n('F d, Y', strtotime($f)); }, $fechas)) : '';
    }

    // Hora legible
    $hora_inicio = $evento_id ? (get_post_meta($evento_id, '_eventosapp_hora_inicio', true) ?: '') : '';
    $hora_cierre = $evento_id ? (get_post_meta($evento_id, '_eventosapp_hora_cierre', true) ?: '') : '';
    $tz_label    = $evento_id ? (get_post_meta($evento_id, '_eventosapp_zona_horaria', true) ?: '') : '';
    if (!$tz_label) $tz_label = wp_timezone_string();

    $hora_legible = '';
    if ($hora_inicio && $hora_cierre)        $hora_legible = $hora_inicio . ' – ' . $hora_cierre;
    elseif ($hora_inicio)                    $hora_legible = $hora_inicio;
    elseif ($hora_cierre)                    $hora_legible = 'Hasta ' . $hora_cierre;
    if ($hora_legible && $tz_label)          $hora_legible .= ' (' . $tz_label . ')';

    // Datos asistente
    $asistente_nombre = trim(get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' . get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true));
    $asistente_email  = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
    $asistente_tel    = get_post_meta($ticket_id, '_eventosapp_asistente_tel', true);
    $localidad        = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);

    // Recursos
    $qr_url  = $ticket_code ? eventosapp_get_ticket_qr_url($ticket_code) : '';
    $pdf_url = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
    $ics_url = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);

    // === Wallet URLs (con generación “just in time” si están activos) ===
    // Android
    $wallet_android_url = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url', true);
    if (!$wallet_android_url) {
        $wallet_android_url = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android', true);
    }
    if ($wa_on && empty($wallet_android_url) && function_exists('eventosapp_generar_enlace_wallet_android')) {
        eventosapp_generar_enlace_wallet_android($ticket_id, false);
        $wallet_android_url = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url', true) ?: get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android', true);
    }

    // Apple
    $wallet_apple_url = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple', true);
    if (!$wallet_apple_url) {
        $wallet_apple_url = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url', true)
                         ?: get_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url', true);
    }
    if ($wi_on && empty($wallet_apple_url)) {
        if (function_exists('eventosapp_apple_generate_pass')) {
            eventosapp_apple_generate_pass($ticket_id, false);
        } elseif (function_exists('eventosapp_generar_enlace_wallet_apple')) {
            eventosapp_generar_enlace_wallet_apple($ticket_id);
        }
        // Relee meta
        $wallet_apple_url = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple', true)
                         ?: get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url', true)
                         ?: get_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url', true);
    }

    // Mensaje adicional (opcional)
    $extra_msg = $evento_id ? (get_post_meta($evento_id, '_eventosapp_email_msg', true) ?: '') : '';
    $extra_msg = wp_strip_all_tags($extra_msg);
    $extra_block = '';
    if ($extra_msg !== '') {
        $extra_block = '<div class="kvs" style="margin-top:16px;"><h2>Mensaje del organizador</h2><p>' . nl2br(esc_html($extra_msg)) . '</p></div>';
    }

    // Cargar plantilla seleccionada
    $tpl_file = $evento_id ? (get_post_meta($evento_id, '_eventosapp_email_tpl', true) ?: 'email-ticket.html') : 'email-ticket.html';
    $tpl_dir  = eventosapp_email_templates_dir();
    $tpl_path = $tpl_dir . $tpl_file;

    // Fallback a la plantilla legacy si no existe la nueva ruta
    if (!is_readable($tpl_path)) {
        // legacy: includes/templates/email-ticket.html
        $legacy = trailingslashit( dirname(__FILE__) ) . '../templates/email-ticket.html';
        $tpl_path = is_readable($legacy) ? $legacy : '';
    }

    if (!$tpl_path || !is_readable($tpl_path)) {
        $body = '<h2>{{evento_nombre}}</h2><p>Hola {{asistente_nombre}}, aquí está tu ticket: {{ticket_id}}</p><p><img src="{{qr_url}}" alt="QR"></p>{{mensaje_extra_block}}';
    } else {
        $body = file_get_contents($tpl_path);
    }

    // === Rutas públicas a los botones gráficos de Wallet (dentro del plugin) con cache-busting ===
    $wallet_google_img = eventosapp_asset_url_with_version('assets/graphics/wallet_icons/google_wallet_btn.png');
    $wallet_apple_img  = eventosapp_asset_url_with_version('assets/graphics/wallet_icons/apple_wallet_btn.png');

    // Reemplazos base
    $replacements = [
        '{{evento_nombre}}'       => esc_html($evento_nombre),
        '{{organizador}}'         => esc_html($organizador),
        '{{fecha_evento}}'        => esc_html($fecha_legible),
        '{{hora_evento}}'         => esc_html($hora_legible),
        '{{lugar_evento}}'        => esc_html($lugar_evento),
        '{{asistente_nombre}}'    => esc_html($asistente_nombre),
        '{{asistente_email}}'     => esc_html($asistente_email),
        '{{asistente_tel}}'       => esc_html($asistente_tel),
        '{{asistente_localidad}}' => esc_html($localidad),
        '{{ticket_id}}'           => esc_html($ticket_code),
        '{{qr_url}}'              => esc_url($qr_url),
        '{{pdf_url}}'             => esc_url($pdf_url),
        '{{ics_url}}'             => esc_url($ics_url),
        '{{wallet_android_url}}'  => esc_url($wallet_android_url),
        '{{wallet_apple_url}}'    => esc_url($wallet_apple_url ?: '#'),
        '{{wallet_android_img}}'  => esc_url($wallet_google_img),
        '{{wallet_apple_img}}'    => esc_url($wallet_apple_img),
        '{{header_img}}'          => esc_url($header_img),
        '{{mensaje_extra_block}}' => $extra_block,
        '{{mensaje_extra}}'       => esc_html($extra_msg),
    ];
    $html = strtr($body, $replacements);

    // === Color de encabezados (h1 y h2) desde meta ===
    $h_color = $evento_id ? (get_post_meta($evento_id, '_eventosapp_email_heading_color', true) ?: '') : '';
    $h_color = sanitize_hex_color($h_color);
    if ($h_color) {
        $style = '<style type="text/css">h1{color:'.$h_color.' !important;} h2{color:'.$h_color.' !important;}</style>';
        if (strpos($html, '</head>') !== false) {
            $html = str_replace('</head>', $style . '</head>', $html);
        } else {
            // fallback por si la plantilla no tiene <head>
            $html = $style . $html;
        }
    }

    // === Limpiar botones según flags/urls
    $remove = function($pattern) use (&$html) { $html = preg_replace($pattern, '', $html); };

    // Wallet Google: borra anchor gráfico (clase wallet-google) y legacy textual
    if (!$wa_on || empty($wallet_android_url)) {
        $remove('/<a[^>]*class="[^"]*wallet-google[^"]*"[^>]*>.*?<\/a>/is');
        $remove('/<a[^>]*>(\s*)Añadir a Google Wallet(\s*)<\/a>/i');
    }

    // Wallet Apple: borra anchor gráfico (clase wallet-apple) y legacy textual
    if (!$wi_on || empty($wallet_apple_url)) {
        $remove('/<a[^>]*class="[^"]*wallet-apple[^"]*"[^>]*>.*?<\/a>/is');
        $remove('/<a[^>]*>(\s*)Añadir a Apple Wallet(\s*)<\/a>/i');
    }

    if (!$ics_on || empty($ics_url)) $remove('/<a[^>]*>(\s*)Añadir al Calendario\s*\(ICS\)(\s*)<\/a>/i');
    if (!$pdf_on || empty($pdf_url)) $remove('/<a[^>]*>(\s*)Descargar PDF(\s*)<\/a>/i');

    // Texto adjuntos dinámico (si alguna plantilla lo usa literal)
    $trozos = [];
    if ($pdf_on) $trozos[] = 'el PDF del ticket';
    if ($ics_on) $trozos[] = 'el archivo ICS para tu calendario';
    $texto = $trozos ? ('Adjuntamos ' . (count($trozos) === 2 ? $trozos[0] . ' y ' . $trozos[1] : $trozos[0]) . '.') : 'Incluimos tu QR de acceso.';
    $html = str_replace('Adjuntamos el PDF del ticket y el archivo ICS para tu calendario.', esc_html($texto), $html);

    // Si no quedan botones, quitar bloque actions
    if (!preg_match('/<a[^>]+class="btn[^"]*"/i', $html)) {
        $html = preg_replace('/<div class="actions"[^>]*>.*?<\/div>/is', '', $html);
    }

    return $html;
}

/* ===========================================================
 *           RECORDATORIOS — COLA EN LOTES CON WP-CRON
 * ===========================================================
 */

/** Intervalo minutely para el procesador de colas */
add_filter('cron_schedules', function($schedules){
    if (!isset($schedules['evapp_minutely'])) {
        $schedules['evapp_minutely'] = [
            'interval' => 60, // 1 min
            'display'  => __('Cada minuto (EventosApp)', 'eventosapp')
        ];
    }
    return $schedules;
});

/** Helpers de tiempo: timestamp de inicio del evento (primer día) */
function eventosapp_event_start_timestamp($evento_id){
    $tipo = get_post_meta($evento_id, '_eventosapp_tipo_fecha', true);
    $tz   = get_post_meta($evento_id, '_eventosapp_zona_horaria', true);
    $tz   = $tz ?: wp_timezone_string();

    $date = '';
    if ($tipo === 'unica') {
        $date = get_post_meta($evento_id, '_eventosapp_fecha_unica', true);
    } elseif ($tipo === 'consecutiva') {
        $date = get_post_meta($evento_id, '_eventosapp_fecha_inicio', true);
    } elseif ($tipo === 'noconsecutiva') {
        $fechas = get_post_meta($evento_id, '_eventosapp_fechas_noco', true);
        if (is_string($fechas)) $fechas = @unserialize($fechas);
        if (!is_array($fechas)) $fechas = [];
        sort($fechas);
        $date = $fechas ? $fechas[0] : '';
    }

    if (!$date) return 0;

    $hora = get_post_meta($evento_id, '_eventosapp_hora_inicio', true) ?: '00:00';
    try {
        $dt = new DateTimeImmutable($date.' '.$hora, new DateTimeZone($tz));
        return $dt->getTimestamp();
    } catch (\Throwable $e) {
        return strtotime($date.' '.$hora); // fallback
    }
}

/** Calcula timestamp exacto para disparar el recordatorio */
function eventosapp_event_reminder_send_timestamp($evento_id){
    $enabled = get_post_meta($evento_id, '_eventosapp_reminder_enabled', true) === '1';
    if (!$enabled) return 0;

    $amount = intval(get_post_meta($evento_id, '_eventosapp_reminder_amount', true));
    $unit   = get_post_meta($evento_id, '_eventosapp_reminder_unit', true) ?: 'hours';
    if ($amount < 0) $amount = 0;

    $event_ts = eventosapp_event_start_timestamp($evento_id);
    if (!$event_ts) return 0;

    $offset = 0;
    if ($unit === 'minutes') $offset = $amount * 60;
    elseif ($unit === 'days') $offset = $amount * 86400;
    else $offset = $amount * 3600; // hours (default)

    return max(0, $event_ts - $offset);
}

/** Keys para opciones/transients de la cola */
function eventosapp_reminder_queue_key($evento_id){ return 'eventosapp_rq_'.$evento_id; }
function eventosapp_reminder_lock_key($evento_id){ return 'eventosapp_rq_lock_'.$evento_id; }

/** Unschedule seguro de eventos programados por evento */
function eventosapp_unschedule_event_reminder_jobs($evento_id){
    // 1) Disparo único
    $next = wp_next_scheduled('eventosapp_dispatch_event_reminder', [intval($evento_id)]);
    while ($next) {
        wp_unschedule_event($next, 'eventosapp_dispatch_event_reminder', [intval($evento_id)]);
        $next = wp_next_scheduled('eventosapp_dispatch_event_reminder', [intval($evento_id)]);
    }
    // 2) Procesador de cola
    $next2 = wp_next_scheduled('eventosapp_process_event_reminder_queue', [intval($evento_id)]);
    while ($next2) {
        wp_unschedule_event($next2, 'eventosapp_process_event_reminder_queue', [intval($evento_id)]);
        $next2 = wp_next_scheduled('eventosapp_process_event_reminder_queue', [intval($evento_id)]);
    }
}

/** Programa (o reprograma) el disparo único del recordatorio para un evento */
function eventosapp_maybe_reschedule_event_reminder($evento_id){
    $evento_id = intval($evento_id);
    if (!$evento_id || get_post_type($evento_id) !== 'eventosapp_event') return;

    eventosapp_unschedule_event_reminder_jobs($evento_id);

    $ts = eventosapp_event_reminder_send_timestamp($evento_id);
    update_post_meta($evento_id, '_eventosapp_reminder_send_ts', $ts ? $ts : '');

    if ($ts && $ts > time()) {
        wp_schedule_single_event($ts, 'eventosapp_dispatch_event_reminder', [$evento_id]);
    }
    // Si el ts ya pasó, no hacemos nada (no disparo retroactivo)
}

/** Disparo único: construir cola con todos los tickets del evento y programar su procesamiento por lotes */
add_action('eventosapp_dispatch_event_reminder', 'eventosapp_dispatch_event_reminder_cb', 10, 1);
function eventosapp_dispatch_event_reminder_cb($evento_id){
    $evento_id = intval($evento_id);
    if (!$evento_id) return;

    // Verifica que aún esté habilitado
    if (get_post_meta($evento_id, '_eventosapp_reminder_enabled', true) !== '1') return;

    // Listar tickets del evento
    $tickets = get_posts([
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'nopaging'       => true,
        'meta_query'     => [
            [
                'key'   => '_eventosapp_ticket_evento_id',
                'value' => $evento_id,
            ]
        ]
    ]);

    if (!is_array($tickets)) $tickets = [];

    // Filtra por email válido y no enviados previamente
    $queue = [];
    foreach ($tickets as $tk) {
        $sent = get_post_meta($tk, '_eventosapp_ticket_reminder_sent', true);
        if ($sent) continue;
        $email = sanitize_email(get_post_meta($tk, '_eventosapp_asistente_email', true));
        if ($email && is_email($email)) {
            $queue[] = $tk;
        }
    }

    // Guarda cola (opción sin autoload)
    update_option(eventosapp_reminder_queue_key($evento_id), $queue, false);
    update_post_meta($evento_id, '_eventosapp_reminder_queue_size', count($queue));
    update_post_meta($evento_id, '_eventosapp_reminder_queue_begins', current_time('mysql'));

    if (!empty($queue)) {
        // Programa procesador minutely si no está programado
        if (!wp_next_scheduled('eventosapp_process_event_reminder_queue', [$evento_id])) {
            wp_schedule_event(time() + 60, 'evapp_minutely', 'eventosapp_process_event_reminder_queue', [$evento_id]);
        }
    } else {
        // Nada que procesar
        update_post_meta($evento_id, '_eventosapp_reminder_done', current_time('mysql'));
    }
}

/** Procesa la cola del evento en lotes para no saturar el servidor */
add_action('eventosapp_process_event_reminder_queue', 'eventosapp_process_event_reminder_queue_cb', 10, 1);
function eventosapp_process_event_reminder_queue_cb($evento_id){
    $evento_id = intval($evento_id);
    if (!$evento_id) return;

    // Lock simple para evitar carrera si el cron se solapa
    $lock_key = eventosapp_reminder_lock_key($evento_id);
    if (get_transient($lock_key)) return; // otro proceso corriendo
    set_transient($lock_key, 1, 55); // bloquea ~1min

    $key   = eventosapp_reminder_queue_key($evento_id);
    $queue = get_option($key, []);
    if (!is_array($queue)) $queue = [];

    if (empty($queue)) {
        // Fin: limpiar y desprogramar
        delete_option($key);
        delete_transient($lock_key);
        eventosapp_unschedule_event_reminder_jobs($evento_id);
        update_post_meta($evento_id, '_eventosapp_reminder_done', current_time('mysql'));
        return;
    }

    // Tamaño de lote (ritmo por minuto). Si no hay meta, usa el filtro/defecto 20.
    $default_batch = intval(apply_filters('eventosapp_reminder_batch_size', 20));
    $BATCH = intval(get_post_meta($evento_id, '_eventosapp_reminder_rate_per_minute', true));
    if ($BATCH < 1) $BATCH = $default_batch;

    $to_process = array_splice($queue, 0, $BATCH);

    $emoji       = '🔔';
    $event_title = get_the_title($evento_id);
    $subject     = $emoji . ' RECORDATORIO: 🎟️ Hoy es el evento ' . $event_title;

    $sent_ok = 0; $sent_fail = 0;

    foreach ($to_process as $ticket_id) {
        // doble chequeo por si ya se marcó
        if (get_post_meta($ticket_id, '_eventosapp_ticket_reminder_sent', true)) {
            continue;
        }
        // Enviar con subject de recordatorio y source 'reminder'
        list($ok,) = eventosapp_send_ticket_email_now($ticket_id, [
            'subject' => $subject,
            'source'  => 'reminder',
            'force'   => true,
        ]);

        if ($ok) {
            $sent_ok++;
            update_post_meta($ticket_id, '_eventosapp_ticket_reminder_sent', current_time('mysql'));
        } else {
            $sent_fail++;
        }

        // Pequeño respiro entre envíos del mismo lote para no pegar picos
        usleep(100000); // 0.1s aprox
    }

    // Actualiza cola y métricas
    update_option($key, $queue, false);
    update_post_meta($evento_id, '_eventosapp_reminder_batch_last', [
        'at'    => current_time('mysql'),
        'ok'    => $sent_ok,
        'fail'  => $sent_fail,
        'left'  => count($queue),
        'rate'  => $BATCH,
    ]);

    // Si terminó, desprograma
    if (empty($queue)) {
        delete_option($key);
        eventosapp_unschedule_event_reminder_jobs($evento_id);
        update_post_meta($evento_id, '_eventosapp_reminder_done', current_time('mysql'));
    }

    delete_transient($lock_key);
}


// Envío automático "central" cuando el ticket viene del frontend
add_action('save_post_eventosapp_ticket', function($post_id, $post, $update){
    if ( wp_is_post_revision($post_id) ) return;

    // Solo tickets marcados como creados desde el front
    $channel = get_post_meta($post_id, '_eventosapp_creation_channel', true);
    if ( $channel !== 'public' ) return;

    // Evita duplicados
    if ( get_post_meta($post_id, '_eventosapp_ticket_email_sent', true) ) return;

    $email = sanitize_email( get_post_meta($post_id, '_eventosapp_asistente_email', true) );
    if ( ! $email || ! is_email($email) ) return;

    if ( function_exists('eventosapp_send_ticket_email_now') ) {
        list($ok,) = eventosapp_send_ticket_email_now($post_id, [
            'recipient' => $email,
            'source'    => 'auto',
            'force'     => true,
        ]);
        if ( $ok ) {
            update_post_meta($post_id, '_eventosapp_ticket_email_sent', '1');
        }
    }
}, 200, 3);
