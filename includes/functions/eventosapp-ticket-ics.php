<?php
/**
 * Generador de archivos ICS para tickets de EventosApp
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Genera (o regenera) el .ics para un ticket dado, si el evento lo tiene habilitado.
 *
 * @param int $ticket_id
 * @return void
 */
function eventosapp_ticket_generar_ics($ticket_id) {
    $evento_id = get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    if (!$evento_id) return;

    $ics_on = get_post_meta($evento_id, '_eventosapp_ticket_ics', true);
    if ($ics_on !== '1') return;

    // Datos del evento
    $evento_nombre   = get_the_title($evento_id);
    $organizador_cn  = get_post_meta($evento_id, '_eventosapp_organizador', true) ?: '';
    $organizador_mail= get_post_meta($evento_id, '_eventosapp_organizador_email', true) ?: get_bloginfo('admin_email');
    $lugar_evento    = get_post_meta($evento_id, '_eventosapp_direccion', true) ?: '';
    $zona_horaria    = get_post_meta($evento_id, '_eventosapp_zona_horaria', true) ?: 'America/Bogota';

    // Fechas del evento
    $tipo_fecha = get_post_meta($evento_id, '_eventosapp_tipo_fecha', true);
    $fechas = [];
    if ($tipo_fecha === 'unica') {
        $f = get_post_meta($evento_id, '_eventosapp_fecha_unica', true);
        if ($f) $fechas[] = $f;
    } elseif ($tipo_fecha === 'consecutiva') {
        $start = get_post_meta($evento_id, '_eventosapp_fecha_inicio', true);
        $end   = get_post_meta($evento_id, '_eventosapp_fecha_fin', true);
        if ($start && $end) {
            $d1 = new DateTime($start);
            $d2 = new DateTime($end);
            while ($d1 <= $d2) { $fechas[] = $d1->format('Y-m-d'); $d1->modify('+1 day'); }
        }
    } elseif ($tipo_fecha === 'noconsecutiva') {
        $fechas = get_post_meta($evento_id, '_eventosapp_fechas_noco', true);
        if (is_string($fechas)) $fechas = @unserialize($fechas);
        if (!is_array($fechas)) $fechas = [];
    }

    $hora_inicio = get_post_meta($evento_id, '_eventosapp_hora_inicio', true) ?: '09:00';
    $hora_fin    = get_post_meta($evento_id, '_eventosapp_hora_cierre', true) ?: '18:00';

    // Asistente
    $asistente_nombre = trim(get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' . get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true));
    $asistente_email  = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
    $ticket_code      = get_post_meta($ticket_id, 'eventosapp_ticketID', true);

    // Helpers ICS
    $esc = function($s) {
        // escapar \ , ; y saltos
        $s = str_replace(["\\", ";", ","], ["\\\\", "\\;", "\\,"], (string)$s);
        $s = str_replace(["\r\n", "\r", "\n"], "\\n", $s);
        return $s;
    };
    $fold = function($line) {
        // Pliega líneas a 75 octetos aprox (simple)
        $out = '';
        $len = strlen($line);
        $pos = 0;
        while ($pos < $len) {
            $chunk = substr($line, $pos, 73);
            $pos += strlen($chunk);
            $out .= $chunk . "\r\n";
            if ($pos < $len) $out .= ' ';
        }
        return rtrim($out, " \r\n") . "\r\n";
    };

    // Construcción base
    $ics  = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//EventosApp//Ticket//EN\r\n";
    // No usamos TZID: convertimos a UTC con sufijo Z
    $now_utc = gmdate('Ymd\THis\Z');

    $tz = new DateTimeZone($zona_horaria);
    $utc= new DateTimeZone('UTC');

    $uid_base = $ticket_code . '@' . $_SERVER['HTTP_HOST'];

    $i = 0;
    foreach ($fechas as $fecha) {
        if (!$fecha) continue;

        // Crear DateTime local y convertir a UTC
        $dt_start_local = DateTime::createFromFormat('Y-m-d H:i', $fecha.' '.$hora_inicio, $tz);
        $dt_end_local   = DateTime::createFromFormat('Y-m-d H:i', $fecha.' '.$hora_fin, $tz);
        if (!$dt_start_local || !$dt_end_local) continue;

        $dt_start_local->setTimezone($utc);
        $dt_end_local->setTimezone($utc);

        $dtstart_utc = $dt_start_local->format('Ymd\THis\Z');
        $dtend_utc   = $dt_end_local->format('Ymd\THis\Z');

        $uid = $uid_base . '-' . (++$i);

        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= $fold("UID:$uid");
        $ics .= $fold("DTSTAMP:$now_utc");
        $ics .= $fold("DTSTART:$dtstart_utc");
        $ics .= $fold("DTEND:$dtend_utc");
        $ics .= $fold("SUMMARY:".$esc($evento_nombre));
        if ($lugar_evento)   $ics .= $fold("LOCATION:".$esc($lugar_evento));

        $desc = "Ticket: {$ticket_code}\\nAsistente: {$asistente_nombre}\\nEmail: {$asistente_email}";
        $ics .= $fold("DESCRIPTION:".$esc($desc));

        // ORGANIZER válido solo si tenemos email:
        if (!empty($organizador_mail) && is_email($organizador_mail)) {
            $org = "ORGANIZER;CN=".$esc($organizador_cn).":mailto:".$organizador_mail;
            $ics .= $fold($org);
        }

        $ics .= "END:VEVENT\r\n";
    }

    $ics .= "END:VCALENDAR\r\n";

    // Guardar archivo
    $upload_dir = wp_upload_dir();
    $ics_dir = $upload_dir['basedir'] . '/eventosapp-ics/';
    if (!file_exists($ics_dir)) wp_mkdir_p($ics_dir);

    $ics_file = $ics_dir . $ticket_code . '.ics';
    file_put_contents($ics_file, $ics); // ya con \r\n

    update_post_meta($ticket_id, '_eventosapp_ticket_ics_url', $upload_dir['baseurl'] . '/eventosapp-ics/' . $ticket_code . '.ics');
}
