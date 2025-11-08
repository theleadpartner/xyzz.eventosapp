<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Funciones de Networking (doble autenticación)
 * - Creación de tabla
 * - Resolución de tickets (scanned / cc+apellido)
 * - Registro de interacciones
 * - Métricas por localidad
 * - Envío de resumen post-evento (cron + manual)
 */

//
// === Tabla e instalación perezosa ===
//
function eventosapp_net2_table_name(){
    global $wpdb;
    return $wpdb->prefix . 'eventosapp_networking';
}

function eventosapp_net2_maybe_create_table(){
    global $wpdb;
    $table = eventosapp_net2_table_name();

    // Evitar repetir si ya existe
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
        DB_NAME, $table
    ) );
    if ($exists) return;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        event_id BIGINT(20) UNSIGNED NOT NULL,
        reader_ticket_id BIGINT(20) UNSIGNED NOT NULL,
        read_ticket_id   BIGINT(20) UNSIGNED NOT NULL,
        reader_localidad VARCHAR(100) DEFAULT NULL,
        read_localidad   VARCHAR(100) DEFAULT NULL,
        ip  VARCHAR(45)  DEFAULT NULL,
        ua  VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY event_id (event_id),
        KEY reader_ticket_id (reader_ticket_id),
        KEY read_ticket_id (read_ticket_id),
        KEY event_reader_read (event_id, reader_ticket_id, read_ticket_id)
    ) {$charset_collate};";

    dbDelta($sql);
}
// Crear tabla al cargar (perezoso y seguro)
add_action('init', 'eventosapp_net2_maybe_create_table');

//
// === Helpers de resolución de tickets ===
//

/**
 * Buscar ticket por CC + Apellido dentro de un evento
 */
function eventosapp_net2_get_ticket_by_cc_last_event($event_id, $cc, $last){
    global $wpdb;
    if (!$event_id || !$cc || !$last) return 0;

    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
        '_eventosapp_asistente_cc', $cc
    ) );
    if (!$ids) return 0;

    $low_last = mb_strtolower($last, 'UTF-8');
    $found_id = 0;

    // Match exacto (case-insensitive)
    foreach ($ids as $cand){
        $cand = (int) $cand;
        if ((int) get_post_meta($cand, '_eventosapp_ticket_evento_id', true) !== (int) $event_id) continue;
        $db_last = (string) get_post_meta($cand, '_eventosapp_asistente_apellido', true);
        if ( mb_strtolower($db_last, 'UTF-8') === $low_last ){
            $found_id = $cand; break;
        }
    }
    if ($found_id) return $found_id;

    // Match flexible (contiene; ignora espacios/acentos)
    $norm = function($s){
        $s = strtolower( trim($s) );
        $s = iconv('UTF-8','ASCII//TRANSLIT', $s);
        $s = preg_replace('/\s+/', '', $s);
        return $s;
    };
    $needle = $norm($last);

    foreach ($ids as $cand){
        $cand = (int) $cand;
        if ((int) get_post_meta($cand, '_eventosapp_ticket_evento_id', true) !== (int) $event_id) continue;
        $db_last = (string) get_post_meta($cand, '_eventosapp_asistente_apellido', true);
        if ( strpos($norm($db_last), $needle) !== false ){
            return $cand;
        }
    }
    return 0;
}

/**
 * Normaliza string leído del QR (como en otros módulos)
 */
function eventosapp_net2_normalize_scanned($raw){
    $s = trim( (string)$raw );
    if (strpos($s, '/') !== false) {
        $parts = explode('/', $s);
        $s = end($parts);
    }
    $s = preg_replace('/\.(png|jpg|jpeg|pdf)$/i','', $s);
    $s = preg_replace('/-tn$/i','', $s);
    $s = ltrim($s, '#');
    return $s;
}

/**
 * Dado $event_id y $scanned (string del QR), devuelve post_id del ticket leído
 * - Busca por eventosapp_ticketID
 * - Si no, y si el evento permite preimpreso para networking, busca por eventosapp_ticket_preprintedID (numérico)
 */
function eventosapp_net2_find_ticket_by_scanned($event_id, $scanned){
    global $wpdb;
    $scanned = eventosapp_net2_normalize_scanned($scanned);
    if (!$scanned || !$event_id) return 0;

    // 1) ID público
    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s",
        'eventosapp_ticketID', $scanned
    ) );
    if ($ids) {
        foreach ($ids as $cand){
            if ((int) get_post_meta($cand, '_eventosapp_ticket_evento_id', true) === (int) $event_id){
                return (int)$cand;
            }
        }
    }

    // 2) Preimpreso si el evento lo permite para networking
    $allow_preprinted = ( get_post_meta($event_id, '_eventosapp_ticket_use_preprinted_qr_networking', true) === '1' );
    if ($allow_preprinted) {
        $num = preg_replace('/\D+/', '', $scanned);
        if ($num !== '') {
            $ids2 = $wpdb->get_col( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s",
                'eventosapp_ticket_preprintedID', $num
            ) );
            if ($ids2) {
                foreach ($ids2 as $cand){
                    if ((int) get_post_meta($cand, '_eventosapp_ticket_evento_id', true) === (int) $event_id){
                        return (int)$cand;
                    }
                }
            }
        }
    }

    return 0;
}

/**
 * Datos de contacto del ticket
 */
function eventosapp_net2_contact_payload($ticket_id){
    $first = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
    $last  = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
    $comp  = get_post_meta($ticket_id, '_eventosapp_asistente_empresa', true);
    $role  = get_post_meta($ticket_id, '_eventosapp_asistente_cargo', true);
    $loc   = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);

    // Email (varias llaves posibles)
    $email = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
    if (!$email) $email = get_post_meta($ticket_id, '_eventosapp_asistente_correo', true);

    // Teléfono (probamos llaves comunes)
    $phone = get_post_meta($ticket_id, '_eventosapp_asistente_telefono', true);
    if (!$phone) $phone = get_post_meta($ticket_id, '_eventosapp_asistente_celular', true);
    if (!$phone) $phone = get_post_meta($ticket_id, '_eventosapp_asistente_movil', true);
    if (!$phone) $phone = get_post_meta($ticket_id, '_eventosapp_asistente_phone', true);

    return [
        'ticket_id'   => (int)$ticket_id,
        'first_name'  => $first,
        'last_name'   => $last,
        'full_name'   => trim($first.' '.$last),
        'company'     => $comp,
        'designation' => $role,
        'localidad'   => $loc,
        'email'       => $email,
        'phone'       => $phone,
    ];
}



//
// === Registro de interacción ===
//
function eventosapp_net2_record_interaction($event_id, $reader_ticket_id, $scanned, $ctx = []){
    global $wpdb;
    eventosapp_net2_maybe_create_table(); // por si acaso

    $reader_ticket_id = (int) $reader_ticket_id;
    $read_ticket_id   = eventosapp_net2_find_ticket_by_scanned($event_id, $scanned);

    if ( ! $read_ticket_id ) {
        return new WP_Error('not_found', 'No se encontró el asistente para ese QR en este evento.');
    }

    // Validaciones
    $ev_read = (int) get_post_meta($read_ticket_id, '_eventosapp_ticket_evento_id', true);
    if ($ev_read !== (int) $event_id) {
        return new WP_Error('invalid_event', 'El ticket leído no pertenece a este evento.');
    }
    if ($read_ticket_id === $reader_ticket_id) {
        return new WP_Error('self_scan', 'No puedes escanear tu propio QR.');
    }

    $table = eventosapp_net2_table_name();

    // Dedupe corto (10s)
    $recent = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table}
         WHERE event_id=%d AND reader_ticket_id=%d AND read_ticket_id=%d
           AND created_at > (NOW() - INTERVAL 10 SECOND)
         LIMIT 1",
        $event_id, $reader_ticket_id, $read_ticket_id
    ) );

    $reader_loc = get_post_meta($reader_ticket_id, '_eventosapp_asistente_localidad', true);
    $read_loc   = get_post_meta($read_ticket_id,   '_eventosapp_asistente_localidad', true);

    if ( ! $recent ) {
        $wpdb->insert($table, [
            'event_id'         => (int)$event_id,
            'reader_ticket_id' => (int)$reader_ticket_id,
            'read_ticket_id'   => (int)$read_ticket_id,
            'reader_localidad' => $reader_loc,
            'read_localidad'   => $read_loc,
            'ip'               => isset($ctx['ip']) ? substr($ctx['ip'], 0, 45) : null,
            'ua'               => isset($ctx['ua']) ? substr($ctx['ua'], 0, 255) : null,
        ], ['%d','%d','%d','%s','%s','%s','%s']);
    }

    // Marcar que ambos usaron networking (para filtro de envío)
    update_post_meta($reader_ticket_id, '_eventosapp_networking_used', 1);
    update_post_meta($read_ticket_id,   '_eventosapp_networking_used', 1);

    // Agendar digest si aplica (una sola vez por evento)
    eventosapp_net2_maybe_schedule_event_digest($event_id);

    // Responder datos del leído
    return eventosapp_net2_contact_payload($read_ticket_id);
}

//
// === Cron: resumen 24h después del evento ===
//

/**
 * Obtiene el día final del evento (Y-m-d) desde helpers existentes.
 */
function eventosapp_net2_get_event_last_day($event_id){
    if ( function_exists('eventosapp_get_event_days') ) {
        $days = (array) eventosapp_get_event_days($event_id);
        if ($days){
            sort($days);
            return end($days);
        }
    }
    // Fallbacks: intenta con metas individuales
    $tipo = get_post_meta($event_id, '_eventosapp_tipo_fecha', true);
    if ($tipo === 'unica') {
        $f = get_post_meta($event_id, '_eventosapp_fecha_unica', true);
        return $f ? $f : gmdate('Y-m-d');
    }
    if ($tipo === 'consecutiva') {
        $fin = get_post_meta($event_id, '_eventosapp_fecha_fin', true);
        return $fin ? $fin : gmdate('Y-m-d');
    }
    return gmdate('Y-m-d');
}

function eventosapp_net2_event_timezone($event_id){
    $tz = get_post_meta($event_id, '_eventosapp_zona_horaria', true);
    if (!$tz) {
        $tz = wp_timezone_string();
        if (!$tz || $tz === 'UTC') {
            $offset = get_option('gmt_offset');
            $tz = $offset ? timezone_name_from_abbr('', $offset * 3600, 0) ?: 'UTC' : 'UTC';
        }
    }
    return $tz;
}

/**
 * Agenda un cron único para el evento si aún no está programado.
 * Hora sugerida: 09:00 del día siguiente al último día del evento.
 */
function eventosapp_net2_maybe_schedule_event_digest($event_id){
    $flag = get_post_meta($event_id, '_eventosapp_net_digest_cron_scheduled', true);
    if ($flag) return;

    $last_day = eventosapp_net2_get_event_last_day($event_id); // Y-m-d
    $tz = eventosapp_net2_event_timezone($event_id);
    try {
        $dt = new DateTime($last_day . ' 09:00:00', new DateTimeZone($tz));
        $dt->modify('+1 day');
    } catch(Exception $e){
        $dt = new DateTime('now', wp_timezone());
        $dt->modify('+1 day');
    }
    $ts = $dt->getTimestamp();

    if ( ! wp_next_scheduled('eventosapp_net2_digest_event', [$event_id]) ) {
        wp_schedule_single_event($ts, 'eventosapp_net2_digest_event', [$event_id]);
        update_post_meta($event_id, '_eventosapp_net_digest_cron_scheduled', 1);
    }
}
add_action('eventosapp_net2_digest_event', 'eventosapp_net2_run_event_digest');

/**
 * Ejecuta envío del resumen para todos los que usaron networking en ese evento.
 */
function eventosapp_net2_run_event_digest($event_id){
    global $wpdb;
    $table = eventosapp_net2_table_name();
    if ( ! $wpdb->get_var( $wpdb->prepare("SELECT 1 FROM {$table} WHERE event_id=%d LIMIT 1", $event_id) ) ) {
        return; // nada que enviar
    }

    // Recolectar tickets involucrados
    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT reader_ticket_id FROM {$table} WHERE event_id=%d", $event_id
    ) );
    $ids2= $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT read_ticket_id   FROM {$table} WHERE event_id=%d", $event_id
    ) );
    $all_ticket_ids = array_unique( array_map('intval', array_merge($ids ?: [], $ids2 ?: [])) );

    foreach ($all_ticket_ids as $ticket_id) {
        eventosapp_net2_send_digest_for_ticket($ticket_id, $event_id);
    }
    update_post_meta($event_id, '_eventosapp_net_digest_done', 1);
}

/**
 * Construye y envía el resumen para un ticket dado.
 * - Solo envía si no se envió antes (meta _eventosapp_net_digest_sent=1 en el ticket)
 */
function eventosapp_net2_send_digest_for_ticket($ticket_id, $event_id = 0, $args = []) {
    global $wpdb;

    $ticket_id = (int) $ticket_id;
    if (!$ticket_id) return false;

    // Opciones:
    // - force: ignora la marca _eventosapp_net_digest_sent (para reenvíos manuales)
    // - mark_sent: si true, marca _eventosapp_net_digest_sent=1 al enviar (solo envío automático)
    $args = wp_parse_args($args, [
        'force'     => false,
        'mark_sent' => true,
    ]);

    if (!$args['force']) {
        $already = get_post_meta($ticket_id, '_eventosapp_net_digest_sent', true);
        if ($already) return false;
    }

    if (!$event_id) {
        $event_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    }
    if (!$event_id) return false;

    $table = eventosapp_net2_table_name();

    // A quiénes escaneó (salientes)
    $outgoing = $wpdb->get_col( $wpdb->prepare(
        "SELECT read_ticket_id FROM {$table} WHERE event_id=%d AND reader_ticket_id=%d",
        $event_id, $ticket_id
    ) );
    // Quiénes lo escanearon (entrantes)
    $incoming = $wpdb->get_col( $wpdb->prepare(
        "SELECT reader_ticket_id FROM {$table} WHERE event_id=%d AND read_ticket_id=%d",
        $event_id, $ticket_id
    ) );

    if ( empty($outgoing) && empty($incoming) ) {
        return false; // no hubo interacciones
    }

    // Email destino
    $email_to = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
    if (!$email_to) $email_to = get_post_meta($ticket_id, '_eventosapp_asistente_correo', true);
    if (!$email_to) return false;

    $evento_nombre = get_the_title($event_id);
    $as_first = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
    $as_last  = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
    $as_name  = trim($as_first.' '.$as_last);

    // Contact payload helper (usa llaves reales del plugin)
    $contact = function($tid){
        $first = get_post_meta($tid, '_eventosapp_asistente_nombre', true);
        $last  = get_post_meta($tid, '_eventosapp_asistente_apellido', true);
        $comp  = get_post_meta($tid, '_eventosapp_asistente_empresa', true);
        $role  = get_post_meta($tid, '_eventosapp_asistente_cargo', true);
        $email = get_post_meta($tid, '_eventosapp_asistente_email', true);
        if (!$email) $email = get_post_meta($tid, '_eventosapp_asistente_correo', true);
        $phone = get_post_meta($tid, '_eventosapp_asistente_tel', true);
        if (!$phone) $phone = get_post_meta($tid, '_eventosapp_asistente_telefono', true);
        if (!$phone) $phone = get_post_meta($tid, '_eventosapp_asistente_cel', true);
        if (!$phone) $phone = get_post_meta($tid, '_eventosapp_asistente_celular', true);

        return [
            'full_name'   => trim($first.' '.$last),
            'designation' => $role,
            'company'     => $comp,
            'email'       => $email,
            'phone'       => $phone,
        ];
    };

    // Tabla HTML reutilizable
    $build_table = function($title, $ids) use ($contact){
        if (!$ids) return '';
        $ids = array_unique(array_map('intval', $ids));

        $th = 'padding:10px 12px;border-bottom:1px solid #e5e7eb;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:13px;color:#111827';
        $td = 'padding:10px 12px;border-bottom:1px solid #f1f5f9;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:13px;color:#111827';

        $html  = '<h3 style="margin:16px 0 8px">'.esc_html($title).'</h3>';
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:720px;border:1px solid #e5e7eb">';
        $html .= '<thead><tr style="background:#f8fafc;text-align:left">';
        $html .= '<th style="'.$th.'">Nombre + Apellidos</th>';
        $html .= '<th style="'.$th.'">Cargo</th>';
        $html .= '<th style="'.$th.'">Empresa</th>';
        $html .= '<th style="'.$th.'">Teléfono</th>';
        $html .= '<th style="'.$th.'">Correo</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($ids as $tid){
            $p = $contact($tid);
            $name  = esc_html($p['full_name'] ?: '(Sin nombre)');
            $role  = esc_html($p['designation'] ?: '');
            $comp  = esc_html($p['company'] ?: '');
            $phone = esc_html($p['phone'] ?: '');
            $mail  = $p['email'] ? '<a href="mailto:'.esc_attr($p['email']).'">'.esc_html($p['email']).'</a>' : '';

            $html .= '<tr>';
            $html .= '<td style="'.$td.'">'.$name.'</td>';
            $html .= '<td style="'.$td.'">'.$role.'</td>';
            $html .= '<td style="'.$td.'">'.$comp.'</td>';
            $html .= '<td style="'.$td.'">'.$phone.'</td>';
            $html .= '<td style="'.$td.'">'.$mail.'</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    };

    // Cuerpo
    $body  = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#111827">';
    $body .= '<p>Hola <b>'.esc_html($as_name).'</b>,</p>';
    $body .= '<p>¡Gracias por participar en el networking de <b>'.esc_html($evento_nombre).'</b>! Aquí tienes el resumen de tus nuevas conexiones:</p>';
    $body .= $build_table('Personas que tú escaneaste', $outgoing);
    $body .= $build_table('Personas que te escanearon', $incoming);
    $body .= '<p style="margin-top:16px;color:#6b7280">Este mensaje se envía automáticamente 24 h después del evento a quienes usaron el networking de doble autenticación.</p>';
    $body .= '</div>';

    // Enviar HTML
    $content_type_cb = function(){ return 'text/html'; };
    add_filter('wp_mail_content_type', $content_type_cb);
    $sent = wp_mail($email_to, 'Tus nuevas conexiones – ' . $evento_nombre, $body);
    remove_filter('wp_mail_content_type', $content_type_cb);

    if ($sent && $args['mark_sent']) {
        update_post_meta($ticket_id, '_eventosapp_net_digest_sent', 1);
    }
    return $sent;
}



//
// === Métricas por localidad (helpers) ===
//
function eventosapp_net2_metrics_by_localidad($event_id){
    global $wpdb;
    $table = eventosapp_net2_table_name();
    $out = [
        'outgoing' => [], // interacciones hechas por localidad del lector
        'incoming' => [], // interacciones recibidas por localidad del leído
    ];
    $rows1 = $wpdb->get_results( $wpdb->prepare(
        "SELECT COALESCE(reader_localidad,'') AS loc, COUNT(*) c FROM {$table} WHERE event_id=%d GROUP BY reader_localidad",
        $event_id
    ), ARRAY_A );
    foreach ($rows1 as $r){ $out['outgoing'][$r['loc']] = (int)$r['c']; }

    $rows2 = $wpdb->get_results( $wpdb->prepare(
        "SELECT COALESCE(read_localidad,'') AS loc, COUNT(*) c FROM {$table} WHERE event_id=%d GROUP BY read_localidad",
        $event_id
    ), ARRAY_A );
    foreach ($rows2 as $r){ $out['incoming'][$r['loc']] = (int)$r['c']; }

    return $out;
}

//
// === Botón manual de envío (admin-post) ===
//
add_action('admin_post_eventosapp_send_networking_digest', function(){
    if ( ! current_user_can('edit_posts') ) {
        wp_die('No autorizado', 403);
    }
    check_admin_referer('eventosapp_send_networking_digest');

    $ticket_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
    if (!$ticket_id) wp_die('Falta post_id');

    $event_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    if (!$event_id) wp_die('El ticket no pertenece a un evento');

    $ok = eventosapp_net2_send_digest_for_ticket($ticket_id, $event_id);

    $redirect = admin_url('post.php?post='.$ticket_id.'&action=edit');
    $redirect = add_query_arg(['netdigest' => $ok ? 'ok' : 'skip'], $redirect);
    wp_safe_redirect($redirect);
    exit;
});

add_action('admin_post_eventosapp_net2_resend_digest', 'eventosapp_net2_admin_resend_digest');
function eventosapp_net2_admin_resend_digest(){
    if ( ! current_user_can('edit_posts') ) wp_die('Unauthorized');

    $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
    if ( ! wp_verify_nonce($nonce, 'eventosapp_net2_resend_digest') ) wp_die('Bad nonce');

    $ticket_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
    if (!$ticket_id) wp_die('Missing ticket');

    $event_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);

    // Envío manual: force=true, mark_sent=false
    $ok = eventosapp_net2_send_digest_for_ticket($ticket_id, $event_id, [
        'force'     => true,
        'mark_sent' => false,
    ]);

    $url = add_query_arg([
        'post'                => $ticket_id,
        'action'              => 'edit',
        'evapp_netdigest'     => $ok ? 1 : 0,
        'evapp_netdigest_msg' => $ok ? 'Resumen enviado (manual) sin afectar el envío programado.' : 'No se envió. Verifica si existen interacciones.',
    ], admin_url('post.php'));

    wp_safe_redirect($url);
    exit;
}

