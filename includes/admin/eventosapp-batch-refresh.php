<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * EventosApp Batch Refresh - Funciones de Procesamiento
 *
 * VERSION 4.0 - Refresh personalizado con preservación de enlaces públicos
 *
 * Este archivo contiene las funciones de procesamiento de tickets para el sistema
 * de actualización por lote. El metabox y la UI viven en eventosapp-batch-processor.php.
 *
 * Modos soportados:
 * - complete: regenera todos los adjuntos activos del evento y los QR, preservando URLs existentes.
 * - qr_missing: crea únicamente los QR faltantes, sin tocar los QR existentes.
 * - custom: permite regenerar solo los recursos seleccionados: qrs, pdf, ics,
 *           android_wallet, apple_wallet, search_blob o QR individuales.
 *
 * Regla principal de esta versión:
 * - Si el archivo/enlace no existe, se crea normalmente.
 * - Si el archivo/enlace ya existe, se regenera el contenido pero se conserva la URL pública
 *   para no romper correos, piezas enviadas ni enlaces previamente compartidos.
 */

/**
 * Cantidad de tickets asociados a un evento (rápido)
 */
function eventosapp_count_tickets_by_event($evento_id) {
    global $wpdb;
    $evento_id = (int)$evento_id;
    if ($evento_id <= 0) return 0;
    $meta_key = '_eventosapp_ticket_evento_id';

    $sql = $wpdb->prepare("
        SELECT COUNT(p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = %d
        WHERE p.post_type = 'eventosapp_ticket' AND p.post_status <> 'auto-draft'
    ", $meta_key, $evento_id);

    $cnt = (int) $wpdb->get_var($sql);
    return $cnt;
}

/**
 * Recursos que el batch puede refrescar.
 * Esta función queda disponible para que eventosapp-batch-processor.php construya
 * checkboxes o controles de selección sin duplicar identificadores.
 */
function eventosapp_batch_refresh_available_assets() {
    return array(
        'qrs' => array(
            'label'       => 'Todos los QR',
            'description' => 'Regenera QR de Email, Google Wallet, Apple Wallet, PDF, WhatsApp, Escarapela y QR legacy manteniendo la URL si ya existía.',
        ),
        'pdf' => array(
            'label'       => 'PDF',
            'description' => 'Regenera o crea el PDF del ticket manteniendo el mismo enlace si ya existía.',
        ),
        'ics' => array(
            'label'       => 'ICS',
            'description' => 'Regenera o crea el archivo calendario ICS manteniendo el mismo enlace si ya existía.',
        ),
        'android_wallet' => array(
            'label'       => 'Android Wallet / Google Wallet',
            'description' => 'Regenera o crea el enlace de Google Wallet. Si ya había enlace guardado, se conserva.',
        ),
        'apple_wallet' => array(
            'label'       => 'Apple Wallet',
            'description' => 'Regenera o crea el archivo/enlace PKPASS manteniendo el mismo enlace si ya existía.',
        ),
        'whatsapp_assets' => array(
            'label'       => 'Piezas WhatsApp',
            'description' => 'Prepara landing pública, QR WhatsApp e imagen del mensaje usados en los envíos de WhatsApp.',
        ),
        'search_blob' => array(
            'label'       => 'Índice de búsqueda',
            'description' => 'Reconstruye el índice de búsqueda interno del ticket.',
        ),
        'qr_email' => array(
            'label'       => 'QR Email',
            'description' => 'Regenera únicamente el QR de Email.',
        ),
        'qr_google_wallet' => array(
            'label'       => 'QR Google Wallet',
            'description' => 'Regenera únicamente el QR usado por Google Wallet.',
        ),
        'qr_apple_wallet' => array(
            'label'       => 'QR Apple Wallet',
            'description' => 'Regenera únicamente el QR usado por Apple Wallet.',
        ),
        'qr_pdf' => array(
            'label'       => 'QR PDF',
            'description' => 'Regenera únicamente el QR usado dentro del PDF.',
        ),
        'qr_whatsapp' => array(
            'label'       => 'QR WhatsApp',
            'description' => 'Regenera únicamente el QR usado por la landing y los mensajes de WhatsApp.',
        ),
        'qr_badge' => array(
            'label'       => 'QR Escarapela',
            'description' => 'Regenera únicamente el QR de escarapela.',
        ),
        'qr_legacy' => array(
            'label'       => 'QR Legacy',
            'description' => 'Regenera únicamente el QR clásico basado en eventosapp_ticketID.',
        ),
    );
}

/**
 * Alias aceptados para mantener compatibilidad con nombres de UI o requests previos.
 */
function eventosapp_batch_refresh_asset_aliases() {
    return array(
        'qr'                 => 'qrs',
        'qrs_all'            => 'qrs',
        'all_qrs'            => 'qrs',
        'wallet_android'     => 'android_wallet',
        'google_wallet'      => 'android_wallet',
        'android'            => 'android_wallet',
        'wallet_google'      => 'android_wallet',
        'wallet_apple'       => 'apple_wallet',
        'ios_wallet'         => 'apple_wallet',
        'ios'                => 'apple_wallet',
        'apple'              => 'apple_wallet',
        'calendar'           => 'ics',
        'ical'               => 'ics',
        'search'             => 'search_blob',
        'search_index'       => 'search_blob',
        'whatsapp_assets'    => 'whatsapp_assets',
        'wapp_assets'        => 'whatsapp_assets',
        'whatsapp_ticket'    => 'whatsapp_assets',
        'email_qr'           => 'qr_email',
        'google_wallet_qr'   => 'qr_google_wallet',
        'android_wallet_qr'  => 'qr_google_wallet',
        'apple_wallet_qr'    => 'qr_apple_wallet',
        'pdf_qr'             => 'qr_pdf',
        'whatsapp_qr'        => 'qr_whatsapp',
        'wapp_qr'            => 'qr_whatsapp',
        'qr_wapp'            => 'qr_whatsapp',
        'badge_qr'           => 'qr_badge',
        'legacy_qr'          => 'qr_legacy',
    );
}

/**
 * Normaliza un nombre de recurso.
 */
function eventosapp_batch_refresh_normalize_asset($asset) {
    $asset = sanitize_key((string) $asset);
    if ($asset === '') return '';

    $aliases = eventosapp_batch_refresh_asset_aliases();
    if (isset($aliases[$asset])) {
        $asset = $aliases[$asset];
    }

    $available = eventosapp_batch_refresh_available_assets();
    return isset($available[$asset]) ? $asset : '';
}

/**
 * Convierte una lista de recursos en una lista válida, única y ordenada.
 */
function eventosapp_batch_refresh_sanitize_assets($assets) {
    if (is_string($assets)) {
        $assets = preg_split('/[\s,;|]+/', $assets);
    }

    if (!is_array($assets)) {
        return array();
    }

    $normalized = array();
    foreach ($assets as $asset) {
        if (is_array($asset)) {
            continue;
        }
        $key = eventosapp_batch_refresh_normalize_asset($asset);
        if ($key !== '' && !in_array($key, $normalized, true)) {
            $normalized[] = $key;
        }
    }

    return $normalized;
}

/**
 * Lee recursos enviados por POST/REQUEST. Permite que el processor mande:
 * assets[], refresh_assets[], eventosapp_batch_assets[] o eventosapp_refresh_assets[].
 */
function eventosapp_batch_refresh_assets_from_request() {
    $keys = array(
        'assets',
        'refresh_assets',
        'eventosapp_batch_assets',
        'eventosapp_refresh_assets',
    );

    foreach ($keys as $key) {
        if (isset($_POST[$key])) {
            return eventosapp_batch_refresh_sanitize_assets(wp_unslash($_POST[$key]));
        }
        if (isset($_REQUEST[$key])) {
            return eventosapp_batch_refresh_sanitize_assets(wp_unslash($_REQUEST[$key]));
        }
    }

    return array();
}

/**
 * Normaliza el modo de ejecución y devuelve la lista de recursos a procesar.
 */
function eventosapp_batch_refresh_normalize_mode($mode, $options = array()) {
    $result = array(
        'mode'       => 'complete',
        'assets'     => array('qrs', 'android_wallet', 'apple_wallet', 'pdf', 'ics', 'search_blob'),
        'qr_missing' => false,
        'custom'     => false,
    );

    if (is_array($mode)) {
        $assets = eventosapp_batch_refresh_sanitize_assets($mode);
        $result['mode']   = 'custom';
        $result['assets'] = $assets;
        $result['custom'] = true;
        return $result;
    }

    $mode = sanitize_key((string) $mode);

    if ($mode === 'qr_missing') {
        $result['mode']       = 'qr_missing';
        $result['assets']     = array('qrs');
        $result['qr_missing'] = true;
        return $result;
    }

    if ($mode === 'custom' || $mode === 'partial' || $mode === 'personalizado') {
        $assets = array();

        if (is_array($options) && isset($options['assets'])) {
            $assets = eventosapp_batch_refresh_sanitize_assets($options['assets']);
        }

        if (!$assets) {
            $assets = eventosapp_batch_refresh_assets_from_request();
        }

        $result['mode']   = 'custom';
        $result['assets'] = $assets;
        $result['custom'] = true;
        return $result;
    }

    $single_asset = eventosapp_batch_refresh_normalize_asset($mode);
    if ($single_asset !== '') {
        $result['mode']   = 'custom';
        $result['assets'] = array($single_asset);
        $result['custom'] = true;
        return $result;
    }

    return $result;
}

/**
 * Evalúa valores on/off guardados en metadatos.
 */
function eventosapp_batch_refresh_is_on($value) {
    return ($value === '1' || $value === 1 || $value === true || $value === 'true' || $value === 'yes' || $value === 'on');
}

/**
 * Indica si el recurso está activo en la configuración del evento.
 * Los QR y search_blob no dependen de checkbox del evento.
 */
function eventosapp_batch_refresh_event_asset_enabled($evento_id, $asset) {
    $evento_id = (int) $evento_id;

    if (strpos($asset, 'qr_') === 0 || $asset === 'qrs' || $asset === 'search_blob') {
        return true;
    }

    $meta_by_asset = array(
        'pdf'            => '_eventosapp_ticket_pdf',
        'ics'            => '_eventosapp_ticket_ics',
        'android_wallet'   => '_eventosapp_ticket_wallet_android',
        'apple_wallet'     => '_eventosapp_ticket_wallet_apple',
        'whatsapp_assets'  => '_eventosapp_ticket_whatsapp_enabled',
    );

    if (!isset($meta_by_asset[$asset])) {
        return true;
    }

    return eventosapp_batch_refresh_is_on(get_post_meta($evento_id, $meta_by_asset[$asset], true));
}

/**
 * Crea el contenedor de estadísticas.
 */
function eventosapp_batch_refresh_make_stats($mode, $assets) {
    return array(
        'mode'       => $mode,
        'assets'     => array_values($assets),
        'processed'  => 0,
        'generated'  => 0,
        'regenerated'=> 0,
        'preserved'  => 0,
        'skipped'    => 0,
        'deleted'    => 0,
        'failed'     => 0,
        'details'    => array(),
    );
}

/**
 * Agrega un detalle al log de estadísticas.
 */
function eventosapp_batch_refresh_add_detail(&$stats, $asset, $status, $message = '', $extra = array()) {
    if (!is_array($stats)) {
        return;
    }

    $detail = array(
        'asset'   => $asset,
        'status'  => $status,
        'message' => $message,
    );

    if (is_array($extra) && $extra) {
        $detail = array_merge($detail, $extra);
    }

    $stats['details'][] = $detail;

    if (isset($stats[$status])) {
        $stats[$status]++;
    }
}

/**
 * Convierte una URL local de uploads a ruta física.
 */
function eventosapp_batch_refresh_url_to_path($url) {
    $url = trim((string) $url);
    if ($url === '') return '';

    $clean_url = strtok($url, '?');
    $clean_url = strtok($clean_url, '#');

    $upload = wp_upload_dir();
    $baseurl = isset($upload['baseurl']) ? untrailingslashit($upload['baseurl']) : '';
    $basedir = isset($upload['basedir']) ? untrailingslashit($upload['basedir']) : '';

    if ($baseurl && $basedir && strpos($clean_url, $baseurl) === 0) {
        $relative = ltrim(substr($clean_url, strlen($baseurl)), '/');
        return trailingslashit($basedir) . $relative;
    }

    return '';
}

/**
 * Devuelve el primer valor no vacío de un grupo de metakeys.
 */
function eventosapp_batch_refresh_first_meta_value($ticket_id, $keys) {
    foreach ((array) $keys as $key) {
        $value = get_post_meta($ticket_id, $key, true);
        if ($value !== '' && $value !== null) {
            return $value;
        }
    }
    return '';
}

/**
 * Captura URLs y paths actuales de un recurso antes de regenerarlo.
 */
function eventosapp_batch_refresh_capture_resource($ticket_id, $url_keys = array(), $path_keys = array()) {
    $capture = array(
        'url_keys'     => array_values((array) $url_keys),
        'path_keys'    => array_values((array) $path_keys),
        'url_values'   => array(),
        'path_values'  => array(),
        'first_url'    => '',
        'first_path'   => '',
        'has_existing' => false,
    );

    foreach ($capture['url_keys'] as $key) {
        $value = get_post_meta($ticket_id, $key, true);
        $capture['url_values'][$key] = $value;
        if ($capture['first_url'] === '' && $value !== '' && $value !== null) {
            $capture['first_url'] = $value;
        }
    }

    foreach ($capture['path_keys'] as $key) {
        $value = get_post_meta($ticket_id, $key, true);
        $capture['path_values'][$key] = $value;
        if ($capture['first_path'] === '' && $value !== '' && $value !== null) {
            $capture['first_path'] = $value;
        }
    }

    if ($capture['first_path'] === '' && $capture['first_url'] !== '') {
        $capture['first_path'] = eventosapp_batch_refresh_url_to_path($capture['first_url']);
    }

    $capture['has_existing'] = ($capture['first_url'] !== '' || $capture['first_path'] !== '');

    return $capture;
}

/**
 * Elimina temporalmente metadatos para forzar que generadores que hacen early-return
 * vuelvan a crear el recurso. Los valores antiguos ya fueron capturados antes.
 */
function eventosapp_batch_refresh_delete_resource_meta($ticket_id, $url_keys = array(), $path_keys = array()) {
    foreach ((array) $url_keys as $key) {
        delete_post_meta($ticket_id, $key);
    }
    foreach ((array) $path_keys as $key) {
        delete_post_meta($ticket_id, $key);
    }
}

/**
 * Restaura el enlace público anterior después de regenerar.
 * Si el generador produjo un archivo nuevo local, copia su contenido encima del archivo viejo
 * y elimina el temporal para mantener una única URL pública vigente.
 */
function eventosapp_batch_refresh_preserve_existing_link($ticket_id, $asset, $old_capture, $url_keys = array(), $path_keys = array(), &$stats = null) {
    if (empty($old_capture['has_existing'])) {
        return false;
    }

    $new_capture = eventosapp_batch_refresh_capture_resource($ticket_id, $url_keys, $path_keys);

    $old_url  = isset($old_capture['first_url']) ? (string) $old_capture['first_url'] : '';
    $old_path = isset($old_capture['first_path']) ? (string) $old_capture['first_path'] : '';
    $new_url  = isset($new_capture['first_url']) ? (string) $new_capture['first_url'] : '';
    $new_path = isset($new_capture['first_path']) ? (string) $new_capture['first_path'] : '';

    if ($old_path === '' && $old_url !== '') {
        $old_path = eventosapp_batch_refresh_url_to_path($old_url);
    }
    if ($new_path === '' && $new_url !== '') {
        $new_path = eventosapp_batch_refresh_url_to_path($new_url);
    }

    $file_preserved = false;

    if ($old_path !== '' && $new_path !== '' && file_exists($new_path)) {
        $old_dir = dirname($old_path);
        if (!file_exists($old_dir)) {
            wp_mkdir_p($old_dir);
        }

        if (wp_normalize_path($old_path) !== wp_normalize_path($new_path)) {
            @copy($new_path, $old_path);
            @unlink($new_path);
        }

        if (file_exists($old_path)) {
            @touch($old_path);
            $file_preserved = true;
        }
    } elseif ($old_path !== '' && file_exists($old_path)) {
        $file_preserved = true;
    }

    // Restaurar URLs anteriores. Si solo una metakey tenía URL antes, se propaga a las
    // metakeys equivalentes que el generador suele usar para que el admin y los correos
    // sigan apuntando al enlace anterior.
    if ($old_url !== '') {
        foreach ((array) $url_keys as $key) {
            update_post_meta($ticket_id, $key, $old_url);
        }
    }

    if ($old_path !== '') {
        foreach ((array) $path_keys as $key) {
            update_post_meta($ticket_id, $key, $old_path);
        }
    }

    if (is_array($stats)) {
        $stats['preserved']++;
    }

    error_log("EventosApp Batch Refresh: {$asset} regenerado conservando enlace previo para ticket {$ticket_id}" . ($file_preserved ? ' (archivo sobrescrito)' : ''));

    return true;
}

/**
 * Ejecuta un generador de archivo/enlace preservando el enlace antiguo si existía.
 */
function eventosapp_batch_refresh_run_generator_preserving_link($ticket_id, $asset, $url_keys, $path_keys, $callback, &$stats, $force_delete_meta = true) {
    $ticket_id = (int) $ticket_id;
    $old_capture = eventosapp_batch_refresh_capture_resource($ticket_id, $url_keys, $path_keys);

    if ($force_delete_meta) {
        eventosapp_batch_refresh_delete_resource_meta($ticket_id, $url_keys, $path_keys);
    }

    try {
        call_user_func($callback, $ticket_id);
    } catch (\Throwable $e) {
        eventosapp_batch_refresh_add_detail($stats, $asset, 'failed', $e->getMessage());
        error_log("EventosApp Batch Refresh: Error regenerando {$asset} para ticket {$ticket_id}: " . $e->getMessage());
        return false;
    }

    $new_capture = eventosapp_batch_refresh_capture_resource($ticket_id, $url_keys, $path_keys);
    $has_result = !empty($new_capture['has_existing']);

    if (!empty($old_capture['has_existing'])) {
        eventosapp_batch_refresh_preserve_existing_link($ticket_id, $asset, $old_capture, $url_keys, $path_keys, $stats);
        eventosapp_batch_refresh_add_detail($stats, $asset, 'regenerated', 'Regenerado conservando el enlace existente.');
        return true;
    }

    if ($has_result) {
        eventosapp_batch_refresh_add_detail($stats, $asset, 'generated', 'Creado porque no existía enlace previo.');
        return true;
    }

    eventosapp_batch_refresh_add_detail($stats, $asset, 'skipped', 'El generador no produjo enlace. Verifica que la función esté activa y que el evento tenga habilitado este recurso.');
    return false;
}

/**
 * Limpia metadatos de un recurso desactivado. Mantiene el comportamiento histórico
 * del modo complete cuando una función del evento está apagada.
 */
function eventosapp_batch_refresh_cleanup_asset($ticket_id, $asset, &$stats) {
    switch ($asset) {
        case 'pdf':
            delete_post_meta($ticket_id, '_eventosapp_ticket_pdf_url');
            delete_post_meta($ticket_id, '_eventosapp_ticket_pdf_path');
            eventosapp_batch_refresh_add_detail($stats, $asset, 'deleted', 'PDF desactivado en el evento; metadatos limpiados.');
            return true;

        case 'ics':
            delete_post_meta($ticket_id, '_eventosapp_ticket_ics_url');
            delete_post_meta($ticket_id, '_eventosapp_ticket_ics_path');
            eventosapp_batch_refresh_add_detail($stats, $asset, 'deleted', 'ICS desactivado en el evento; metadatos limpiados.');
            return true;

        case 'android_wallet':
            if (function_exists('eventosapp_eliminar_enlace_wallet_android')) {
                try {
                    eventosapp_eliminar_enlace_wallet_android($ticket_id);
                } catch (\Throwable $e) {
                    error_log("EventosApp Batch Refresh: Error limpiando Android Wallet para ticket {$ticket_id}: " . $e->getMessage());
                }
            } else {
                delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_android');
                delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url');
            }
            eventosapp_batch_refresh_add_detail($stats, $asset, 'deleted', 'Android Wallet desactivado en el evento; metadatos limpiados.');
            return true;

        case 'apple_wallet':
            delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple');
            delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url');
            delete_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url');
            delete_post_meta($ticket_id, '_eventosapp_ticket_pkpass_path');
            eventosapp_batch_refresh_add_detail($stats, $asset, 'deleted', 'Apple Wallet desactivado en el evento; metadatos limpiados.');
            return true;

        case 'whatsapp_assets':
            eventosapp_batch_refresh_add_detail($stats, $asset, 'skipped', 'WhatsApp desactivado en el evento; no se eliminan piezas históricas para no romper enlaces ya enviados.');
            return true;
    }

    return false;
}

/**
 * Regenera el QR legacy del ticket conservando su ruta estable basada en eventosapp_ticketID.
 */
function eventosapp_batch_refresh_regenerate_legacy_qr($ticket_id, &$stats) {
    $ticket_id = (int) $ticket_id;
    $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);

    if (!$ticket_code) {
        eventosapp_batch_refresh_add_detail($stats, 'qr_legacy', 'failed', 'El ticket no tiene eventosapp_ticketID.');
        return false;
    }

    if (!class_exists('QRcode') && class_exists('EventosApp_QR_Manager')) {
        try {
            EventosApp_QR_Manager::get_instance();
        } catch (\Throwable $e) {
            // La instancia solo se intenta para cargar la librería QR.
        }
    }

    if (!class_exists('QRcode')) {
        eventosapp_batch_refresh_add_detail($stats, 'qr_legacy', 'failed', 'La librería QRcode no está disponible.');
        return false;
    }

    $upload = wp_upload_dir();
    $qr_dir = trailingslashit($upload['basedir']) . 'eventosapp-qr/';
    if (!file_exists($qr_dir)) {
        wp_mkdir_p($qr_dir);
    }

    $qr_file = $qr_dir . $ticket_code . '.png';
    $existed = file_exists($qr_file);

    try {
        QRcode::png($ticket_code, $qr_file, 'L', 6, 2);
        if (file_exists($qr_file)) {
            @touch($qr_file);
            eventosapp_batch_refresh_add_detail(
                $stats,
                'qr_legacy',
                $existed ? 'regenerated' : 'generated',
                $existed ? 'QR legacy regenerado conservando la ruta.' : 'QR legacy creado.'
            );
            if ($existed) {
                $stats['preserved']++;
            }
            return true;
        }
    } catch (\Throwable $e) {
        eventosapp_batch_refresh_add_detail($stats, 'qr_legacy', 'failed', $e->getMessage());
        error_log("EventosApp Batch Refresh: Error regenerando QR legacy para ticket {$ticket_id}: " . $e->getMessage());
        return false;
    }

    eventosapp_batch_refresh_add_detail($stats, 'qr_legacy', 'failed', 'No se pudo crear el archivo QR legacy.');
    return false;
}

/**
 * Regenera un QR específico manteniendo la misma URL de imagen si ya existía.
 */
function eventosapp_batch_refresh_regenerate_single_qr($ticket_id, $qr_type, &$stats, $missing_only = false) {
    $ticket_id = (int) $ticket_id;

    if (!class_exists('EventosApp_QR_Manager')) {
        eventosapp_batch_refresh_add_detail($stats, 'qr_' . $qr_type, 'failed', 'Clase EventosApp_QR_Manager no disponible.');
        return false;
    }

    try {
        $qr_manager = EventosApp_QR_Manager::get_instance();
    } catch (\Throwable $e) {
        eventosapp_batch_refresh_add_detail($stats, 'qr_' . $qr_type, 'failed', $e->getMessage());
        return false;
    }

    if (!$qr_manager || !method_exists($qr_manager, 'generate_qr_code')) {
        eventosapp_batch_refresh_add_detail($stats, 'qr_' . $qr_type, 'failed', 'Método generate_qr_code no disponible.');
        return false;
    }

    $all_qr_codes = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
    if (!is_array($all_qr_codes)) {
        $all_qr_codes = array();
    }

    $old_data = isset($all_qr_codes[$qr_type]) && is_array($all_qr_codes[$qr_type]) ? $all_qr_codes[$qr_type] : array();
    $old_url  = isset($old_data['url']) ? (string) $old_data['url'] : '';
    $old_path = isset($old_data['path']) ? (string) $old_data['path'] : '';

    if ($old_path === '' && $old_url !== '') {
        $old_path = eventosapp_batch_refresh_url_to_path($old_url);
    }

    $has_existing = ($old_url !== '' || $old_path !== '' || !empty($old_data['content']));

    $existing_file_is_available = false;
    if ($old_path !== '') {
        $existing_file_is_available = file_exists($old_path);
    } elseif ($old_url !== '') {
        // URL no local o no resoluble. Para no romper enlaces externos, se considera existente.
        $existing_file_is_available = true;
    }

    if ($missing_only && $has_existing && $existing_file_is_available) {
        eventosapp_batch_refresh_add_detail($stats, 'qr_' . $qr_type, 'skipped', 'Ya existía; no se toca en modo QR faltantes.');
        return true;
    }

    try {
        $new_data = $qr_manager->generate_qr_code($ticket_id, $qr_type);
    } catch (\Throwable $e) {
        eventosapp_batch_refresh_add_detail($stats, 'qr_' . $qr_type, 'failed', $e->getMessage());
        error_log("EventosApp Batch Refresh: Error regenerando QR {$qr_type} para ticket {$ticket_id}: " . $e->getMessage());
        return false;
    }

    if (!$new_data || !is_array($new_data)) {
        eventosapp_batch_refresh_add_detail($stats, 'qr_' . $qr_type, 'failed', 'El QR Manager no devolvió datos del QR.');
        return false;
    }

    $new_path = isset($new_data['path']) ? (string) $new_data['path'] : '';
    $new_url  = isset($new_data['url']) ? (string) $new_data['url'] : '';

    if ($new_path === '' && $new_url !== '') {
        $new_path = eventosapp_batch_refresh_url_to_path($new_url);
    }

    if ($has_existing && $old_url !== '') {
        if ($old_path !== '' && $new_path !== '' && file_exists($new_path)) {
            $old_dir = dirname($old_path);
            if (!file_exists($old_dir)) {
                wp_mkdir_p($old_dir);
            }

            if (wp_normalize_path($old_path) !== wp_normalize_path($new_path)) {
                @copy($new_path, $old_path);
                @unlink($new_path);
            }

            if (file_exists($old_path)) {
                @touch($old_path);
            }
        }

        $new_data['url'] = $old_url;
        if ($old_path !== '') {
            $new_data['path'] = $old_path;
        }
        $new_data['refreshed_date'] = current_time('mysql');
        $new_data['preserved_url']  = true;

        $all_qr_codes = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
        if (!is_array($all_qr_codes)) {
            $all_qr_codes = array();
        }
        $all_qr_codes[$qr_type] = $new_data;
        update_post_meta($ticket_id, '_eventosapp_qr_codes', $all_qr_codes);

        if (!empty($new_data['qr_id'])) {
            update_post_meta($ticket_id, '_eventosapp_qr_' . $qr_type, $new_data['qr_id']);
        }

        $stats['preserved']++;
        eventosapp_batch_refresh_add_detail($stats, 'qr_' . $qr_type, 'regenerated', 'QR regenerado conservando la URL existente.');
        return true;
    }

    eventosapp_batch_refresh_add_detail($stats, 'qr_' . $qr_type, 'generated', 'QR creado porque no existía previamente.');
    return true;
}

/**
 * Procesa QR según recurso solicitado.
 */
function eventosapp_batch_refresh_process_qrs($ticket_id, $asset, &$stats, $missing_only = false) {
    $qr_map = array(
        'qr_email'          => 'email',
        'qr_google_wallet'  => 'google_wallet',
        'qr_apple_wallet'   => 'apple_wallet',
        'qr_pdf'            => 'pdf',
        'qr_whatsapp'       => 'whatsapp',
        'qr_badge'          => 'badge',
    );

    if ($asset === 'qr_legacy') {
        return eventosapp_batch_refresh_regenerate_legacy_qr($ticket_id, $stats);
    }

    if (isset($qr_map[$asset])) {
        return eventosapp_batch_refresh_regenerate_single_qr($ticket_id, $qr_map[$asset], $stats, $missing_only);
    }

    $ok = true;
    foreach ($qr_map as $single_asset => $qr_type) {
        $single_ok = eventosapp_batch_refresh_regenerate_single_qr($ticket_id, $qr_type, $stats, $missing_only);
        if (!$single_ok) {
            $ok = false;
        }
    }

    if (!$missing_only) {
        $legacy_ok = eventosapp_batch_refresh_regenerate_legacy_qr($ticket_id, $stats);
        if (!$legacy_ok) {
            $ok = false;
        }
    } else {
        // En modo QR faltantes, el legacy solo se crea si no existe.
        $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
        $upload = wp_upload_dir();
        $legacy_path = $ticket_code ? trailingslashit($upload['basedir']) . 'eventosapp-qr/' . $ticket_code . '.png' : '';
        if (!$legacy_path || !file_exists($legacy_path)) {
            $legacy_ok = eventosapp_batch_refresh_regenerate_legacy_qr($ticket_id, $stats);
            if (!$legacy_ok) {
                $ok = false;
            }
        } else {
            eventosapp_batch_refresh_add_detail($stats, 'qr_legacy', 'skipped', 'Ya existía; no se toca en modo QR faltantes.');
        }
    }

    return $ok;
}

/**
 * Procesa PDF.
 */
function eventosapp_batch_refresh_process_pdf($ticket_id, &$stats) {
    if (!function_exists('eventosapp_ticket_generar_pdf')) {
        eventosapp_batch_refresh_add_detail($stats, 'pdf', 'failed', 'Función eventosapp_ticket_generar_pdf no disponible.');
        return false;
    }

    return eventosapp_batch_refresh_run_generator_preserving_link(
        $ticket_id,
        'pdf',
        array('_eventosapp_ticket_pdf_url'),
        array('_eventosapp_ticket_pdf_path'),
        function($id) {
            eventosapp_ticket_generar_pdf($id);
        },
        $stats,
        true
    );
}

/**
 * Procesa ICS.
 */
function eventosapp_batch_refresh_process_ics($ticket_id, &$stats) {
    if (!function_exists('eventosapp_ticket_generar_ics')) {
        eventosapp_batch_refresh_add_detail($stats, 'ics', 'failed', 'Función eventosapp_ticket_generar_ics no disponible.');
        return false;
    }

    return eventosapp_batch_refresh_run_generator_preserving_link(
        $ticket_id,
        'ics',
        array('_eventosapp_ticket_ics_url'),
        array('_eventosapp_ticket_ics_path'),
        function($id) {
            eventosapp_ticket_generar_ics($id);
        },
        $stats,
        true
    );
}

/**
 * Procesa Android Wallet / Google Wallet.
 */
function eventosapp_batch_refresh_process_android_wallet($ticket_id, &$stats) {
    if (!function_exists('eventosapp_generar_enlace_wallet_android')) {
        eventosapp_batch_refresh_add_detail($stats, 'android_wallet', 'failed', 'Función eventosapp_generar_enlace_wallet_android no disponible.');
        return false;
    }

    return eventosapp_batch_refresh_run_generator_preserving_link(
        $ticket_id,
        'android_wallet',
        array('_eventosapp_ticket_wallet_android_url', '_eventosapp_ticket_wallet_android'),
        array('_eventosapp_ticket_wallet_android_path'),
        function($id) {
            eventosapp_generar_enlace_wallet_android($id, false);
        },
        $stats,
        true
    );
}

/**
 * Procesa Apple Wallet / PKPASS.
 */
function eventosapp_batch_refresh_process_apple_wallet($ticket_id, &$stats) {
    if (!function_exists('eventosapp_apple_generate_pass') && !function_exists('eventosapp_generar_enlace_wallet_apple')) {
        eventosapp_batch_refresh_add_detail($stats, 'apple_wallet', 'failed', 'No hay función disponible para Apple Wallet.');
        return false;
    }

    return eventosapp_batch_refresh_run_generator_preserving_link(
        $ticket_id,
        'apple_wallet',
        array('_eventosapp_ticket_wallet_apple', '_eventosapp_ticket_wallet_apple_url', '_eventosapp_ticket_pkpass_url'),
        array('_eventosapp_ticket_pkpass_path', '_eventosapp_ticket_wallet_apple_path'),
        function($id) {
            if (function_exists('eventosapp_apple_generate_pass')) {
                eventosapp_apple_generate_pass($id);
            } elseif (function_exists('eventosapp_generar_enlace_wallet_apple')) {
                eventosapp_generar_enlace_wallet_apple($id);
            }
        },
        $stats,
        true
    );
}

/**
 * Procesa piezas WhatsApp del ticket.
 * Prepara la landing pública, QR WhatsApp e imagen del mensaje sin enviar el WhatsApp.
 */
function eventosapp_batch_refresh_process_whatsapp_assets($ticket_id, &$stats) {
    if (!function_exists('eventosapp_whatsapp_prepare_ticket_assets')) {
        eventosapp_batch_refresh_add_detail($stats, 'whatsapp_assets', 'skipped', 'Función eventosapp_whatsapp_prepare_ticket_assets no disponible.');
        return false;
    }

    try {
        $result = eventosapp_whatsapp_prepare_ticket_assets($ticket_id, array(
            'ensure_qr'              => true,
            'ensure_landing'         => true,
            'ensure_message_image'   => true,
            'refresh_enabled_assets' => false,
            'apply_variant'          => true,
            'rebuild_search_index'   => true,
            'source'                 => 'batch_refresh_whatsapp_assets',
        ));

        if (is_array($result) && !empty($result['ok'])) {
            eventosapp_batch_refresh_add_detail($stats, 'whatsapp_assets', 'generated', 'Piezas WhatsApp preparadas correctamente.');
            return true;
        }

        $message = 'No se pudieron preparar todas las piezas WhatsApp.';
        if (is_array($result) && !empty($result['errors']) && is_array($result['errors'])) {
            $message .= ' ' . implode(' | ', array_map('sanitize_text_field', $result['errors']));
        }
        eventosapp_batch_refresh_add_detail($stats, 'whatsapp_assets', 'failed', $message);
        return false;
    } catch (\Throwable $e) {
        eventosapp_batch_refresh_add_detail($stats, 'whatsapp_assets', 'failed', $e->getMessage());
        error_log("EventosApp Batch Refresh: Error preparando piezas WhatsApp para ticket {$ticket_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Procesa índice de búsqueda.
 */
function eventosapp_batch_refresh_process_search_blob($ticket_id, &$stats) {
    if (!function_exists('eventosapp_ticket_build_search_blob')) {
        eventosapp_batch_refresh_add_detail($stats, 'search_blob', 'skipped', 'Función eventosapp_ticket_build_search_blob no disponible.');
        return false;
    }

    try {
        eventosapp_ticket_build_search_blob($ticket_id);
        eventosapp_batch_refresh_add_detail($stats, 'search_blob', 'regenerated', 'Índice de búsqueda reconstruido.');
        return true;
    } catch (\Throwable $e) {
        eventosapp_batch_refresh_add_detail($stats, 'search_blob', 'failed', $e->getMessage());
        error_log("EventosApp Batch Refresh: Error reconstruyendo search blob para ticket {$ticket_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Procesar un ticket completo según el modo especificado.
 *
 * Compatibilidad:
 * - eventosapp_refresh_ticket_full($ticket_id, 'complete') mantiene respuesta true/false.
 * - eventosapp_refresh_ticket_full($ticket_id, 'qr_missing') devuelve estadísticas de QR faltantes.
 * - eventosapp_refresh_ticket_full($ticket_id, 'custom', ['assets' => ['pdf','ics']]) devuelve estadísticas.
 *
 * @param int          $ticket_id ID del ticket.
 * @param string|array $mode      'complete', 'qr_missing', 'custom' o array de recursos.
 * @param array        $options   Opcional. Para custom: ['assets' => ['qrs','pdf','ics','android_wallet','apple_wallet']].
 * @return mixed true en modo complete, array de estadísticas en modos qr_missing/custom, false si ticket inválido.
 */
function eventosapp_refresh_ticket_full($ticket_id, $mode = 'complete', $options = array()) {
    $ticket_id = (int)$ticket_id;
    if ($ticket_id <= 0) return false;

    if (get_post_type($ticket_id) !== 'eventosapp_ticket') {
        return false;
    }

    $evento_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    if (!$evento_id) return false;

    $normalized = eventosapp_batch_refresh_normalize_mode($mode, $options);
    $assets     = isset($normalized['assets']) && is_array($normalized['assets']) ? $normalized['assets'] : array();
    $stats      = eventosapp_batch_refresh_make_stats($normalized['mode'], $assets);

    // Compatibilidad con Variantes:
    // Todo refresh por lote debe recalcular primero la variante efectiva del ticket para que
    // los generadores posteriores usen la plantilla de correo, clase Google Wallet, branding
    // Android/Apple y demás overrides correctos antes de regenerar los anexos.
    if (function_exists('eventosapp_ticket_variants_prepare_ticket_for_batch_context')) {
        $variant_prepare = eventosapp_ticket_variants_prepare_ticket_for_batch_context(
            $ticket_id,
            $evento_id,
            'batch_refresh_' . sanitize_key((string) $normalized['mode']),
            array(
                'sync_google_classes' => true,
                'mark_assets_stale'   => false,
                'clear_assets_stale'  => false,
                'log'                 => true,
            )
        );
        $stats['details'][] = array(
            'asset' => 'variants',
            'status' => 'info',
            'message' => !empty($variant_prepare['applied'])
                ? 'Variante preparada antes del refresh: ' . sanitize_text_field($variant_prepare['variant_name'] ?: $variant_prepare['variant_key'])
                : 'Sin variante aplicable antes del refresh: ' . sanitize_text_field($variant_prepare['reason'] ?? 'not_applied'),
            'variant_key' => sanitize_text_field($variant_prepare['variant_key'] ?? ''),
            'changed' => !empty($variant_prepare['changed']) ? 1 : 0,
        );
    } elseif (function_exists('eventosapp_ticket_variants_apply_to_ticket')) {
        eventosapp_ticket_variants_apply_to_ticket($ticket_id, $evento_id, true);
        $stats['details'][] = array(
            'asset' => 'variants',
            'status' => 'info',
            'message' => 'Variante recalculada antes del refresh mediante fallback apply_to_ticket().',
        );
    }

    if (!$assets) {
        eventosapp_batch_refresh_add_detail($stats, 'batch', 'skipped', 'No se seleccionó ningún recurso para refrescar.');
        update_post_meta($ticket_id, '_eventosapp_last_batch_refresh', current_time('mysql'));
        update_post_meta($ticket_id, '_eventosapp_last_batch_mode', $normalized['mode']);
        update_post_meta($ticket_id, '_eventosapp_last_batch_assets', $assets);
        update_post_meta($ticket_id, '_eventosapp_last_batch_stats', $stats);
        return $stats;
    }

    foreach ($assets as $asset) {
        $stats['processed']++;

        $enabled = eventosapp_batch_refresh_event_asset_enabled($evento_id, $asset);

        if (!$enabled) {
            if (!$normalized['custom']) {
                eventosapp_batch_refresh_cleanup_asset($ticket_id, $asset, $stats);
            } else {
                eventosapp_batch_refresh_add_detail($stats, $asset, 'skipped', 'Recurso desactivado en la configuración del evento.');
            }
            continue;
        }

        if ($asset === 'qrs' || strpos($asset, 'qr_') === 0) {
            eventosapp_batch_refresh_process_qrs($ticket_id, $asset, $stats, !empty($normalized['qr_missing']));
            continue;
        }

        switch ($asset) {
            case 'pdf':
                eventosapp_batch_refresh_process_pdf($ticket_id, $stats);
                break;

            case 'ics':
                eventosapp_batch_refresh_process_ics($ticket_id, $stats);
                break;

            case 'android_wallet':
                eventosapp_batch_refresh_process_android_wallet($ticket_id, $stats);
                break;

            case 'apple_wallet':
                eventosapp_batch_refresh_process_apple_wallet($ticket_id, $stats);
                break;

            case 'whatsapp_assets':
                eventosapp_batch_refresh_process_whatsapp_assets($ticket_id, $stats);
                break;

            case 'search_blob':
                eventosapp_batch_refresh_process_search_blob($ticket_id, $stats);
                break;

            default:
                eventosapp_batch_refresh_add_detail($stats, $asset, 'skipped', 'Recurso no reconocido.');
                break;
        }
    }

    if (empty($stats['failed'])) {
        delete_post_meta($ticket_id, '_eventosapp_ticket_variant_assets_need_refresh');
        delete_post_meta($ticket_id, '_eventosapp_ticket_variant_assets_need_refresh_since');
        $stats['details'][] = array(
            'asset' => 'variants',
            'status' => 'info',
            'message' => 'Marca de anexos pendientes por variante limpiada después del refresh exitoso.',
        );
    }

    update_post_meta($ticket_id, '_eventosapp_last_batch_refresh', current_time('mysql'));
    update_post_meta($ticket_id, '_eventosapp_last_batch_mode', $normalized['mode']);
    update_post_meta($ticket_id, '_eventosapp_last_batch_assets', $assets);
    update_post_meta($ticket_id, '_eventosapp_last_batch_stats', $stats);

    // Metadatos resumidos para compatibilidad con reportes previos de QR faltantes.
    if (!empty($normalized['qr_missing'])) {
        update_post_meta($ticket_id, '_eventosapp_last_batch_qr_generated', (int) $stats['generated']);
        update_post_meta($ticket_id, '_eventosapp_last_batch_qr_skipped', (int) $stats['skipped']);
        return $stats;
    }

    if (!empty($normalized['custom'])) {
        return $stats;
    }

    return true;
}
