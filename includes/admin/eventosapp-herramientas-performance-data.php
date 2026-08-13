<?php
/**
 * EventosApp - Alto rendimiento del importador CSV de Herramientas.
 *
 * Este archivo pertenece exclusivamente al módulo:
 * includes/admin/eventosapp-herramientas.php
 *
 * No reemplaza los generadores de QR/PDF/ICS/Wallet/WhatsApp. La optimización
 * cambia el orden del trabajo cuando la importación se envía a Cola y Tareas:
 * primero persiste datos y después reparte los anexos en hasta tres tareas.
 */
if (!defined('ABSPATH')) exit;

if (!defined('EVENTOSAPP_TICKET_IMPORT_PERFORMANCE_VERSION')) {
    define('EVENTOSAPP_TICKET_IMPORT_PERFORMANCE_VERSION', '2026.08.12.2');
}

function evapp_tools_perf_mark_pending($ticket_id, $event_id){
    $ticket_id = absint($ticket_id);
    if (!$ticket_id) return;
    update_post_meta($ticket_id, '_eventosapp_import_assets_pending', [
        'at'       => current_time('mysql'),
        'event_id' => absint($event_id),
        'pipeline' => EVENTOSAPP_TICKET_IMPORT_PERFORMANCE_VERSION,
    ]);
}

function evapp_tools_perf_update_ticket_data($ticket_id, $event_id, $payload, $asset_config){
    $ticket_id = absint($ticket_id);
    $event_id = absint($event_id);
    if (!$ticket_id || !$event_id) return false;

    // La marca se escribe antes del fingerprint. Si PHP cae a mitad de la fila,
    // el retry puede reconstruir los datos y mantener pendiente la fase 2.
    evapp_tools_perf_mark_pending($ticket_id, $event_id);

    update_post_meta($ticket_id, '_eventosapp_asistente_nombre', sanitize_text_field($payload['first_name'] ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_apellido', sanitize_text_field($payload['last_name'] ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_email', sanitize_email($payload['email'] ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_tel', sanitize_text_field($payload['tel'] ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_empresa', sanitize_text_field($payload['empresa'] ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_nit', sanitize_text_field($payload['nit'] ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_cargo', sanitize_text_field($payload['cargo'] ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_cc', sanitize_text_field($payload['cc'] ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_ciudad', sanitize_text_field($payload['ciudad'] ?? ''));
    update_post_meta($ticket_id, '_eventosapp_asistente_pais', sanitize_text_field($payload['pais'] ?? 'Colombia'));
    update_post_meta($ticket_id, '_eventosapp_asistente_localidad', sanitize_text_field($payload['localidad'] ?? ''));
    update_post_meta($ticket_id, '_eventosapp_ticket_evento_id', $event_id);

    if (function_exists('eventosapp_ticket_sync_modalidad')) {
        eventosapp_ticket_sync_modalidad($ticket_id, $payload['modalidad'] ?? '');
    } else {
        update_post_meta($ticket_id, '_eventosapp_ticket_modalidad', sanitize_key($payload['modalidad'] ?? 'presencial'));
    }

    evapp_import_save_extra_fields($ticket_id, $event_id, $payload['extras'] ?? []);
    update_post_meta($ticket_id, '_eventosapp_import_fingerprint', (string)($payload['fingerprint'] ?? ''));

    if (function_exists('eventosapp_ticket_build_search_blob')) {
        eventosapp_ticket_build_search_blob($ticket_id);
    }
    if (!empty($asset_config['link_assistant']) && function_exists('evapp_process_vincular_asistente')) {
        evapp_process_vincular_asistente($ticket_id);
    }

    evapp_import_cache_ticket_by_cc($event_id, $payload['cc'] ?? '', $ticket_id);
    return true;
}

function evapp_tools_perf_process_row_data($event_id, $data, $line, $asset_config){
    global $wpdb;

    $event_id = absint($event_id);
    $data = is_array($data) ? $data : [];
    $line = absint($line);
    $asset_config = is_array($asset_config) ? $asset_config : evapp_import_event_asset_config($event_id);

    $nombre    = trim((string)($data['nombre'] ?? ''));
    $apellido  = trim((string)($data['apellido'] ?? ''));
    $email     = sanitize_email($data['email'] ?? '');
    $cc        = sanitize_text_field($data['cc'] ?? '');
    $localidad = trim((string)($data['localidad'] ?? ''));

    $result = [
        'created'=>0,
        'updated'=>0,
        'skipped'=>0,
        'errors'=>0,
        'ticket_id'=>0,
        'needs_assets'=>0,
        'log'=>null,
    ];

    if (!$nombre || !$apellido || (!$email && !$cc) || !$localidad) {
        $result['skipped'] = 1;
        $result['log'] = evapp_import_log_entry('L'.$line.': fila omitida por datos mínimos incompletos.');
        return $result;
    }

    $payload = evapp_import_payload_from_row($data, $event_id);
    $finger = (string)($payload['fingerprint'] ?? '');

    $duplicate_id = $wpdb->get_var($wpdb->prepare(
        "SELECT pm.post_id
           FROM {$wpdb->postmeta} pm
           INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
          WHERE pm.meta_key = '_eventosapp_import_fingerprint'
            AND pm.meta_value = %s
            AND p.post_type = 'eventosapp_ticket'
            AND p.post_status NOT IN ('trash','auto-draft')
          LIMIT 1",
        $finger
    ));

    if ($duplicate_id) {
        $duplicate_id = absint($duplicate_id);
        $result['ticket_id'] = $duplicate_id;

        if (get_post_meta($duplicate_id, '_eventosapp_import_assets_pending', true)) {
            if (evapp_tools_perf_update_ticket_data($duplicate_id, $event_id, $payload, $asset_config)) {
                $result['updated'] = 1;
                $result['needs_assets'] = 1;
                $result['log'] = evapp_import_log_entry('L'.$line.': fila recuperada desde fingerprint; datos verificados y anexos pendientes conservados.');
            } else {
                $result['errors'] = 1;
                $result['log'] = evapp_import_log_entry('L'.$line.': no se pudo recuperar completamente el ticket '.$duplicate_id.'.');
            }
        } else {
            $result['skipped'] = 1;
            $result['log'] = evapp_import_log_entry('L'.$line.': fingerprint duplicado, fila omitida.');
        }
        return $result;
    }

    $existing_ticket_id = false;
    if ($cc && function_exists('evapp_find_ticket_by_cedula_evento')) {
        $existing_ticket_id = evapp_find_ticket_by_cedula_evento($cc, $event_id);
    }

    if ($existing_ticket_id) {
        $ticket_id = absint($existing_ticket_id);
        if (evapp_tools_perf_update_ticket_data($ticket_id, $event_id, $payload, $asset_config)) {
            $result['updated'] = 1;
            $result['ticket_id'] = $ticket_id;
            $result['needs_assets'] = 1;
            $result['log'] = evapp_import_log_entry('L'.$line.': ticket '.$ticket_id.' actualizado; datos guardados y anexos diferidos.');
        } else {
            $result['errors'] = 1;
            $result['log'] = evapp_import_log_entry('L'.$line.': no se pudo actualizar el ticket '.$ticket_id.'.');
        }
        return $result;
    }

    // Reutiliza el creador histórico con su flag nativo de "skip heavy".
    // No se envían flags falsos al generador sobre tickets existentes, por lo que
    // esta ruta no borra anexos válidos mientras espera la fase paralela.
    $new_id = eventosapp_create_ticket_programmatically($event_id, $payload, 'import', true, $asset_config);
    if (!$new_id) {
        $result['errors'] = 1;
        $result['log'] = evapp_import_log_entry('L'.$line.': no se pudo crear el ticket. Revisa el log de PHP.');
        return $result;
    }

    update_post_meta($new_id, '_eventosapp_import_fingerprint', $finger);
    evapp_tools_perf_mark_pending($new_id, $event_id);

    if (!empty($asset_config['link_assistant']) && function_exists('evapp_process_vincular_asistente')) {
        evapp_process_vincular_asistente($new_id);
    }

    evapp_import_cache_ticket_by_cc($event_id, $cc, $new_id);
    $result['created'] = 1;
    $result['ticket_id'] = absint($new_id);
    $result['needs_assets'] = 1;
    $result['log'] = evapp_import_log_entry('L'.$line.': ticket '.$new_id.' creado; datos guardados y anexos diferidos.');
    return $result;
}
