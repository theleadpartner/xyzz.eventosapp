<?php
if (!defined('ABSPATH')) exit;

/**
 * Herramientas por evento: Importador CSV de tickets (asistente 4 pasos) + envío masivo opcional.
 * OPTIMIZADO para evitar timeouts en servidores con recursos limitados.
 * Continuidad resiliente: reintentos automáticos ante cortes AJAX, 502/504 o respuestas incompletas.
 * Segundo plano opcional: worker firmado + WP-Cron, panel global de tareas y correos por hitos.
 *
 * URL: /wp-admin/admin.php?page=eventosapp_tools&event_id=ID
 */

//
// === Menú y accesos ===
//
add_action('admin_menu', function(){
    // Subpágina "oculta" colgada de EventosApp, pero accesible por URL y enlaces
    add_submenu_page(
        'eventosapp_dashboard',
        'Herramientas',
        'Herramientas',
        'manage_options',
        'eventosapp_tools',
        'eventosapp_tools_render',
        40
    );
}, 20);

// Enlace "Herramientas" en fila de eventos
add_filter('post_row_actions', function($actions, $post){
    if ($post->post_type === 'eventosapp_event' && current_user_can('manage_options')) {
        $url = add_query_arg([
            'page'     => 'eventosapp_tools',
            'event_id' => $post->ID,
        ], admin_url('admin.php'));
        $actions['evapp_tools'] = '<a href="'.esc_url($url).'">Herramientas</a>';
    }
    return $actions;
}, 10, 2);

//
// === Utilidades internas ===
//
function evapp_import_upload_dir() {
    $u = wp_upload_dir();
    $dir = trailingslashit($u['basedir']).'eventosapp-imports/';
    if (!file_exists($dir)) wp_mkdir_p($dir);
    return [$dir, trailingslashit($u['baseurl']).'eventosapp-imports/'];
}
function evapp_sanitize_header_key($s){
    $s = remove_accents(strtolower(trim($s)));
    $s = preg_replace('/[^a-z0-9_]+/','_',$s);
    $s = trim($s,'_');
    return $s;
}
function evapp_current_user_key(){
    $u = wp_get_current_user();
    return 'u'.($u && $u->ID ? $u->ID : 0);
}

/**
 * Convierte valores de php.ini como 256M o 1G a bytes.
 */
function evapp_import_parse_ini_bytes($value){
    $value = trim((string) $value);
    if ($value === '' || $value === '-1') return 0;

    $unit = strtolower(substr($value, -1));
    $bytes = (float) $value;
    if ($unit === 'g') $bytes *= 1024 * 1024 * 1024;
    elseif ($unit === 'm') $bytes *= 1024 * 1024;
    elseif ($unit === 'k') $bytes *= 1024;

    return (int) $bytes;
}

/**
 * Foto liviana de recursos del proceso PHP actual.
 * No ejecuta consultas adicionales y funciona aunque sys_getloadavg/getrusage no estén disponibles.
 */
function evapp_import_resource_snapshot(){
    $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
    if (!is_array($load)) $load = [];

    $cpu_ms = null;
    if (function_exists('getrusage')) {
        $usage = @getrusage();
        if (is_array($usage)) {
            $cpu_ms =
                ((float) ($usage['ru_utime.tv_sec'] ?? 0) * 1000) +
                ((float) ($usage['ru_utime.tv_usec'] ?? 0) / 1000) +
                ((float) ($usage['ru_stime.tv_sec'] ?? 0) * 1000) +
                ((float) ($usage['ru_stime.tv_usec'] ?? 0) / 1000);
        }
    }

    $memory_limit = evapp_import_parse_ini_bytes(ini_get('memory_limit'));
    $memory_usage = memory_get_usage(true);
    $memory_peak  = memory_get_peak_usage(true);

    return [
        'memory_usage'        => (int) $memory_usage,
        'memory_peak'         => (int) $memory_peak,
        'memory_limit'        => (int) $memory_limit,
        'memory_percent'      => $memory_limit > 0 ? round(($memory_usage / $memory_limit) * 100, 2) : 0,
        'memory_peak_percent' => $memory_limit > 0 ? round(($memory_peak / $memory_limit) * 100, 2) : 0,
        'load_1'         => isset($load[0]) ? round((float) $load[0], 2) : null,
        'load_5'         => isset($load[1]) ? round((float) $load[1], 2) : null,
        'load_15'        => isset($load[2]) ? round((float) $load[2], 2) : null,
        'cpu_ms_total'   => $cpu_ms,
    ];
}

/**
 * Tiempo máximo que una petición AJAX del importador puede trabajar antes de entregar el control.
 * Se deja margen al max_execution_time y se limita a 20 segundos para evitar timeouts del proxy/PHP.
 */
function evapp_import_time_budget_ms(){
    $max_execution = (int) ini_get('max_execution_time');
    $budget = 20000;

    if ($max_execution > 0) {
        $budget = min($budget, max(5000, ($max_execution - 5) * 1000));
    }

    return (int) apply_filters('eventosapp_import_time_budget_ms', $budget);
}

/**
 * Determina cuántas filas intentar en la siguiente petición.
 * El valor configurado es el techo; el promedio real por ticket evita solicitudes demasiado largas.
 */
function evapp_import_effective_batch_size($state, $time_budget_ms){
    $configured = max(1, min(50, (int) ($state['batch_size'] ?? 20)));
    $average_ms = (float) ($state['performance']['avg_ms_per_row'] ?? 0);
    $effective = $configured;

    if ($average_ms > 0) {
        $target = (int) floor(($time_budget_ms * 0.75) / max(1, $average_ms));
        $effective = max(1, min($configured, $target));
    }

    $last_memory = (float) ($state['performance']['last_batch']['memory_percent'] ?? 0);
    if ($last_memory >= 75) {
        $effective = max(1, (int) floor($effective * 0.6));
    }

    return $effective;
}

/**
 * Lock por importación para impedir que dos pestañas procesen el mismo offset al mismo tiempo.
 */
function evapp_import_lock_key($event_id, $hash, $scope = ''){
    $scope = $scope !== '' ? (string) $scope : evapp_current_user_key();
    return 'evapp_import_lock_'.md5((int) $event_id.'|'.(string) $hash.'|'.$scope);
}
function evapp_import_acquire_lock($event_id, $hash, $ttl = 90, $scope = ''){
    $key = evapp_import_lock_key($event_id, $hash, $scope);
    $token = wp_generate_uuid4();
    $payload = ['token'=>$token, 'expires'=>time() + max(30, (int) $ttl)];

    if (add_option($key, $payload, '', 'no')) {
        return $token;
    }

    $existing = get_option($key);
    if (!is_array($existing) || (int) ($existing['expires'] ?? 0) < time()) {
        delete_option($key);
        if (add_option($key, $payload, '', 'no')) {
            return $token;
        }
    }

    return '';
}
function evapp_import_release_lock($event_id, $hash, $token, $scope = ''){
    if (!$token) return;
    $key = evapp_import_lock_key($event_id, $hash, $scope);
    $existing = get_option($key);
    if (is_array($existing) && hash_equals((string) ($existing['token'] ?? ''), (string) $token)) {
        delete_option($key);
    }
}

/**
 * Crea y fusiona logs sin escribir la option completa por cada fila.
 */
function evapp_import_log_entry($message){
    return [
        'time'    => current_time('H:i:s'),
        'message' => wp_strip_all_tags((string) $message),
    ];
}
function evapp_import_merge_runtime_logs($state, $new_logs){
    $current = is_array($state['runtime_log'] ?? null) ? $state['runtime_log'] : [];
    foreach ((array) $new_logs as $entry) {
        if (is_array($entry) && isset($entry['message'])) {
            $current[] = $entry;
        }
    }
    $state['runtime_log'] = array_slice($current, -300);
    return $state;
}

/**
 * Normaliza los flags del metabox "Funciones Extra del Ticket".
 */
function evapp_import_flag_enabled($value){
    return $value === '1' || $value === 1 || $value === true || $value === 'yes' || $value === 'on';
}

/**
 * Fotografía inmutable de anexos y funciones costosas habilitadas para el evento.
 *
 * La importación utiliza esta configuración durante todo el proceso para evitar
 * repetir las mismas lecturas de postmeta en cada fila y, especialmente, para no
 * generar PDF, ICS, Wallet o recursos de WhatsApp cuando el evento no los usa.
 */
function evapp_import_event_asset_config($event_id){
    $event_id = (int) $event_id;

    $config = [
        'pdf'             => false,
        'ics'             => false,
        'wallet_android'  => false,
        'wallet_apple'    => false,
        'whatsapp'        => false,
        'variants'        => false,
        'link_assistant'  => false,
        'physical_qr'     => true,
    ];

    if (!$event_id) return $config;

    $config['pdf']            = evapp_import_flag_enabled(get_post_meta($event_id, '_eventosapp_ticket_pdf', true));
    $config['ics']            = evapp_import_flag_enabled(get_post_meta($event_id, '_eventosapp_ticket_ics', true));
    $config['wallet_android'] = evapp_import_flag_enabled(get_post_meta($event_id, '_eventosapp_ticket_wallet_android', true));
    $config['wallet_apple']   = evapp_import_flag_enabled(get_post_meta($event_id, '_eventosapp_ticket_wallet_apple', true));
    $config['whatsapp']       = evapp_import_flag_enabled(get_post_meta($event_id, '_eventosapp_ticket_whatsapp_enabled', true));
    $config['variants']       = evapp_import_flag_enabled(get_post_meta($event_id, '_eventosapp_ticket_variants_enabled', true));
    $config['link_assistant'] = evapp_import_flag_enabled(get_post_meta($event_id, '_eventosapp_ticket_vincular_asistente', true));

    return apply_filters('eventosapp_import_event_asset_config', $config, $event_id);
}

function evapp_import_asset_config_labels($config){
    $config = is_array($config) ? $config : [];
    $labels = ['QR presencial'];

    if (!empty($config['pdf']))            $labels[] = 'PDF';
    if (!empty($config['ics']))            $labels[] = 'ICS';
    if (!empty($config['wallet_android'])) $labels[] = 'Google Wallet';
    if (!empty($config['wallet_apple']))   $labels[] = 'Apple Wallet';
    if (!empty($config['whatsapp']))       $labels[] = 'recursos de WhatsApp';
    if (!empty($config['variants']))       $labels[] = 'variantes por reglas';
    if (!empty($config['link_assistant'])) $labels[] = 'vinculación con Asistentes';

    return $labels;
}

/**
 * Prepara una sola vez los recursos compartidos del evento antes del primer lote.
 * Las clases de Google Wallet por variante son de evento, no de ticket; sincronizarlas
 * una vez evita repetir una operación remota costosa para cada registro importado.
 */
function evapp_import_prepare_event_assets_once($event_id, &$state){
    if (!is_array($state)) return;
    if (!empty($state['event_assets_prepared'])) return;

    $config = is_array($state['asset_config'] ?? null)
        ? $state['asset_config']
        : evapp_import_event_asset_config($event_id);

    if (!empty($config['variants']) && !empty($config['wallet_android']) && function_exists('eventosapp_ticket_variants_sync_google_wallet_classes_for_event')) {
        try {
            eventosapp_ticket_variants_sync_google_wallet_classes_for_event($event_id, 'ticket_import_queue_prepare');
        } catch (Throwable $e) {
            $state = evapp_import_merge_runtime_logs($state, [
                evapp_import_log_entry('Aviso: no se pudo pre-sincronizar las clases Google Wallet de variantes: '.$e->getMessage()),
            ]);
        }
    }

    $state['asset_config'] = $config;
    $state['event_assets_prepared'] = 1;
    $state['asset_config_checked_at'] = time();
}

/**
 * Cachea datos del evento que antes se consultaban de nuevo para cada ticket del lote.
 */
function evapp_import_event_runtime_config($event_id){
    static $cache = [];
    $event_id = (int) $event_id;

    if (!isset($cache[$event_id])) {
        $schema = function_exists('eventosapp_get_event_extra_fields') ? eventosapp_get_event_extra_fields($event_id) : [];
        $extras_by_key = [];
        foreach ((array) $schema as $field) {
            if (!empty($field['key'])) $extras_by_key[$field['key']] = $field;
        }

        $sessions = get_post_meta($event_id, '_eventosapp_sesiones_internas', true);
        if (!is_array($sessions)) $sessions = [];

        $cache[$event_id] = [
            'extras_by_key' => $extras_by_key,
            'days'          => function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days($event_id) : [],
            'sessions'      => $sessions,
            'asset_config'  => evapp_import_event_asset_config($event_id),
        ];
    }

    return $cache[$event_id];
}

function evapp_import_save_extra_fields($ticket_id, $event_id, $extras){
    if (empty($extras) || !is_array($extras)) return;

    $config = evapp_import_event_runtime_config($event_id);
    foreach ($extras as $key => $value) {
        if (!isset($config['extras_by_key'][$key])) continue;
        $field = $config['extras_by_key'][$key];
        $normalized = function_exists('eventosapp_normalize_extra_value')
            ? eventosapp_normalize_extra_value($field, $value)
            : sanitize_text_field($value);
        update_post_meta($ticket_id, '_eventosapp_extra_'.$key, $normalized);
    }
}

/**
 * Respuesta uniforme para estado, controles y procesamiento.
 */
function evapp_import_public_state($state, $extra = []){
    $state = is_array($state) ? $state : [];
    $status = (string) ($state['status'] ?? 'ready');
    $performance = is_array($state['performance'] ?? null) ? $state['performance'] : [];
    $last_batch = is_array($performance['last_batch'] ?? null) ? $performance['last_batch'] : [];
    $total_rows = (int) ($state['total_rows'] ?? 0);
    $offset = (int) ($state['offset'] ?? 0);
    $avg_ms = (float) ($performance['avg_ms_per_row'] ?? 0);
    $remaining = max(0, $total_rows - $offset);
    $queue_task_id = absint($state['queue_task_id'] ?? 0);
    $queue_task_url = '';
    if ($queue_task_id) {
        $queue_task_url = function_exists('eventosapp_task_queue_task_url')
            ? eventosapp_task_queue_task_url($queue_task_id)
            : add_query_arg(['page'=>'eventosapp_task_queue','task_id'=>$queue_task_id], admin_url('admin.php'));
    }

    $base = [
        'status'           => $status,
        'terminal'         => in_array($status, ['done', 'cancelled', 'stopped', 'error'], true) ? 1 : 0,
        'should_continue'  => ($status === 'running' && empty($state['done'])) ? 1 : 0,
        'offset'           => $offset,
        'total_rows'       => $total_rows,
        'created_count'    => (int) ($state['created_count'] ?? 0),
        'updated_existing' => (int) ($state['updated_existing'] ?? 0),
        'skipped_dup'      => (int) ($state['skipped_dup'] ?? 0),
        'error_count'      => (int) ($state['error_count'] ?? 0),
        'runtime_log'      => is_array($state['runtime_log'] ?? null) ? $state['runtime_log'] : [],
        'done'             => !empty($state['done']) ? 1 : 0,
        'configured_batch' => (int) ($state['batch_size'] ?? 20),
        'effective_batch'  => (int) ($last_batch['effective_batch'] ?? ($state['batch_size'] ?? 20)),
        'performance'      => $performance,
        'resources'        => $last_batch,
        'eta_seconds'      => $avg_ms > 0 ? (int) ceil(($remaining * $avg_ms) / 1000) : 0,
        'execution_mode'   => (string) ($state['execution_mode'] ?? 'browser'),
        'background_active'=> (($state['execution_mode'] ?? 'browser') === 'background' && $status === 'running') ? 1 : 0,
        'background_backend' => (string) ($state['background_backend'] ?? ''),
        'task_id'          => $queue_task_id ?: (string) ($state['background_task_id'] ?? ''),
        'queue_task_id'    => $queue_task_id,
        'queue_task_url'   => $queue_task_url,
        'asset_config'     => is_array($state['asset_config'] ?? null) ? $state['asset_config'] : [],
        'asset_labels'     => evapp_import_asset_config_labels($state['asset_config'] ?? []),
        'notification_email' => sanitize_email($state['notification_email'] ?? ''),
        'last_error'       => (string) ($state['last_error'] ?? ''),
        'updated_at'       => (int) ($state['updated_at'] ?? 0),
    ];

    return array_merge($base, is_array($extra) ? $extra : []);
}

/**
 * Registro global de importaciones enviadas a segundo plano.
 * Guarda únicamente referencias al estado real para no duplicar datos pesados en wp_options.
 */
function evapp_import_background_registry_key(){
    return 'evapp_import_background_registry_v1';
}
function evapp_import_background_registry(){
    $registry = get_option(evapp_import_background_registry_key(), []);
    return is_array($registry) ? $registry : [];
}
function evapp_import_background_save_registry($registry){
    update_option(evapp_import_background_registry_key(), is_array($registry) ? $registry : [], false);
}
function evapp_import_background_get_entry($task_id){
    $task_id = sanitize_text_field((string) $task_id);
    if ($task_id === '') return null;
    $registry = evapp_import_background_registry();
    return isset($registry[$task_id]) && is_array($registry[$task_id]) ? $registry[$task_id] : null;
}
function evapp_import_background_register($state_key, &$state){
    $registry = evapp_import_background_registry();
    $task_id = sanitize_text_field($state['background_task_id'] ?? '');
    if ($task_id === '') {
        $task_id = 'evimp_'.str_replace('-', '', wp_generate_uuid4());
    }

    $owner_user_id = (int) ($state['owner_user_id'] ?? get_current_user_id());
    $state['background_task_id'] = $task_id;
    $state['owner_user_id'] = $owner_user_id;
    $state['execution_mode'] = 'background';
    $state['updated_at'] = time();

    $registry[$task_id] = [
        'task_id'       => $task_id,
        'state_key'     => (string) $state_key,
        'event_id'      => (int) ($state['event_id'] ?? 0),
        'hash'          => (string) ($state['file_hash'] ?? ''),
        'owner_user_id' => $owner_user_id,
        'created_at'    => (int) ($registry[$task_id]['created_at'] ?? time()),
        'updated_at'    => time(),
    ];
    evapp_import_background_save_registry($registry);
    return $task_id;
}
function evapp_import_background_touch($state_key, $state){
    if (!is_array($state) || empty($state['background_task_id'])) return;
    $registry = evapp_import_background_registry();
    $task_id = (string) $state['background_task_id'];
    if (!isset($registry[$task_id]) || !is_array($registry[$task_id])) {
        evapp_import_background_register($state_key, $state);
        return;
    }
    $registry[$task_id]['updated_at'] = time();
    $registry[$task_id]['event_id'] = (int) ($state['event_id'] ?? $registry[$task_id]['event_id'] ?? 0);
    $registry[$task_id]['hash'] = (string) ($state['file_hash'] ?? $registry[$task_id]['hash'] ?? '');
    evapp_import_background_save_registry($registry);
}
function evapp_import_background_resolve_state_key($event_id, $hash, $task_id = ''){
    $task_id = sanitize_text_field((string) $task_id);
    if ($task_id !== '') {
        $entry = evapp_import_background_get_entry($task_id);
        if (is_array($entry) && !empty($entry['state_key'])) {
            $entry_event = (int) ($entry['event_id'] ?? 0);
            $entry_hash  = (string) ($entry['hash'] ?? '');
            if ((!$event_id || $entry_event === (int) $event_id) && (!$hash || hash_equals($entry_hash, (string) $hash))) {
                return (string) $entry['state_key'];
            }
        }
    }
    return evapp_import_state_key($event_id, $hash);
}

/**
 * Sincroniza el estado histórico de Herramientas con la tarea de la cola central.
 * La cola es la autoridad del estado de ejecución; el estado de importación conserva
 * el cursor, los contadores específicos (creados/actualizados) y el log del CSV.
 */
function evapp_import_sync_from_task_queue($state_key, &$state, $persist = true){
    if (!is_array($state) || empty($state['queue_task_id']) || !function_exists('eventosapp_task_queue_get')) {
        return null;
    }

    $task = eventosapp_task_queue_get(absint($state['queue_task_id']));
    if (!is_array($task)) return null;

    $previous_status = (string) ($state['status'] ?? 'ready');
    $queue_status = (string) ($task['status'] ?? 'queued');

    if (in_array($queue_status, ['queued','scheduled','running'], true)) {
        $state['status'] = 'running';
        $state['done'] = 0;
        $state['stopped'] = 0;
        $state['cancelled'] = 0;
    } elseif ($queue_status === 'paused') {
        $state['status'] = 'stopped';
        $state['stopped'] = 1;
        $state['done'] = 0;
    } elseif ($queue_status === 'completed') {
        $state['status'] = 'done';
        $state['done'] = 1;
        $state['stopped'] = 0;
        $state['finished_at_ts'] = (int) ($state['finished_at_ts'] ?? time());
        if (empty($state['finished_at'])) $state['finished_at'] = current_time('mysql');
    } elseif ($queue_status === 'cancelled') {
        $state['status'] = 'cancelled';
        $state['cancelled'] = 1;
        $state['stopped'] = 1;
        $state['done'] = 0;
        $state['finished_at_ts'] = (int) ($state['finished_at_ts'] ?? time());
    } elseif (in_array($queue_status, ['failed','expired'], true)) {
        $state['status'] = 'error';
        $state['done'] = 0;
        $state['last_error'] = (string) ($task['last_error'] ?? 'La tarea de la cola terminó con error.');
        $state['finished_at_ts'] = (int) ($state['finished_at_ts'] ?? time());
    }

    $queue_metrics = is_array($task['resource_metrics'] ?? null) ? $task['resource_metrics'] : [];
    $queue_last = is_array($queue_metrics['last_batch'] ?? null) ? $queue_metrics['last_batch'] : [];
    $queue_after = is_array($queue_last['after'] ?? null) ? $queue_last['after'] : [];
    if ($queue_last) {
        $memory_percent = (float) ($queue_after['memory_percent'] ?? 0);
        $load_1 = $queue_after['load_1'] ?? null;
        $cores = max(1, absint($queue_after['cpu_cores'] ?? 1));
        $resource_level = ($memory_percent >= 72 || ($load_1 !== null && (float) $load_1 >= $cores * 1.30))
            ? 'high'
            : (($memory_percent >= 55 || ($load_1 !== null && (float) $load_1 >= $cores * 0.90)) ? 'warning' : 'normal');

        if (!isset($state['performance']) || !is_array($state['performance'])) $state['performance'] = [];
        $state['performance']['last_batch'] = [
            'effective_batch'    => absint($queue_last['batch_size'] ?? $task['batch_size'] ?? 0),
            'processed_rows'     => absint($queue_last['processed'] ?? 0),
            'elapsed_ms'         => round((float) ($queue_last['elapsed_seconds'] ?? 0) * 1000, 2),
            'rows_per_second'    => (float) ($queue_last['items_per_second'] ?? 0),
            'memory_usage'       => absint($queue_after['memory_usage'] ?? 0),
            'memory_peak'        => absint($queue_after['memory_peak'] ?? 0),
            'memory_limit'       => absint($queue_after['memory_limit'] ?? 0),
            'memory_percent'     => (float) ($queue_after['memory_percent'] ?? 0),
            'memory_peak_percent'=> (float) ($queue_after['memory_peak_percent'] ?? 0),
            'load_1'             => $queue_after['load_1'] ?? null,
            'load_5'             => $queue_after['load_5'] ?? null,
            'load_15'            => $queue_after['load_15'] ?? null,
            'cpu_ms'             => $queue_last['cpu_ms_delta'] ?? null,
            'db_queries'         => null,
            'resource_level'     => $resource_level,
        ];
        $processed_items = max(1, absint($task['processed_items'] ?? 0));
        $total_worker_seconds = (float) ($queue_metrics['total_worker_seconds'] ?? 0);
        if ($total_worker_seconds > 0) {
            $state['performance']['avg_ms_per_row'] = round(($total_worker_seconds * 1000) / $processed_items, 2);
        }
    }

    $state['execution_mode'] = 'background';
    $state['background_backend'] = 'task_queue';
    $state['queue_status'] = $queue_status;
    $state['updated_at'] = time();

    if ($previous_status !== $state['status']) {
        $state = evapp_import_merge_runtime_logs($state, [
            evapp_import_log_entry('Cola y Tareas cambió el estado de la importación a '.$queue_status.'.'),
        ]);
    }

    if ($persist) update_option($state_key, $state, false);
    return $task;
}

function evapp_import_find_state_by_queue_task($task_id){
    global $wpdb;
    $task_id = absint($task_id);
    if (!$task_id) return ['', null];

    $like = 'evapp_import_state_%';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 200",
        $like
    ), ARRAY_A);

    foreach ((array) $rows as $row) {
        $state = maybe_unserialize($row['option_value'] ?? null);
        if (is_array($state) && absint($state['queue_task_id'] ?? 0) === $task_id) {
            return [(string) $row['option_name'], $state];
        }
    }
    return ['', null];
}

function evapp_import_task_queue_terminal_sync($task_id, $task = null){
    $task = is_array($task) ? $task : (function_exists('eventosapp_task_queue_get') ? eventosapp_task_queue_get($task_id) : null);
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    $state_key = sanitize_text_field((string) ($payload['state_key'] ?? ''));
    $state = $state_key !== '' ? get_option($state_key) : null;

    // Compatibilidad con tareas creadas antes de guardar state_key en el payload.
    if (!$state_key || !is_array($state)) {
        [$state_key, $state] = evapp_import_find_state_by_queue_task($task_id);
    }
    if (!$state_key || !is_array($state)) return;
    evapp_import_sync_from_task_queue($state_key, $state, true);
}
add_action('eventosapp_task_queue_completed', 'evapp_import_task_queue_terminal_sync', 20, 2);
add_action('eventosapp_task_queue_failed', 'evapp_import_task_queue_terminal_sync', 20, 2);
add_action('eventosapp_task_queue_cancelled', 'evapp_import_task_queue_terminal_sync', 20, 2);

/**
 * Crea o reanuda la tarea central de importación.
 */
function evapp_import_create_task_queue_job($state_key, &$state){
    if (!function_exists('eventosapp_task_queue_create') || !function_exists('eventosapp_task_queue_get')) {
        return new WP_Error('evapp_import_queue_unavailable', 'El módulo Cola y Tareas no está disponible.');
    }

    $existing_id = absint($state['queue_task_id'] ?? 0);
    if ($existing_id) {
        $existing = eventosapp_task_queue_get($existing_id);
        if (is_array($existing) && !in_array($existing['status'], eventosapp_task_queue_terminal_statuses(), true)) {
            if ($existing['status'] === 'paused' && function_exists('eventosapp_task_queue_resume')) {
                eventosapp_task_queue_resume($existing_id);
            }
            $state['execution_mode'] = 'background';
            $state['background_backend'] = 'task_queue';
            $state['status'] = 'running';
            $state['stopped'] = 0;
            $state['cancelled'] = 0;
            $state['updated_at'] = time();
            update_option($state_key, $state, false);
            return $existing_id;
        }
    }

    $event_id = absint($state['event_id'] ?? 0);
    $total = absint($state['total_rows'] ?? 0);
    $offset = absint($state['offset'] ?? 0);
    $batch_size = max(1, min(50, absint($state['batch_size'] ?? 20)));
    $asset_config = is_array($state['asset_config'] ?? null)
        ? $state['asset_config']
        : evapp_import_event_asset_config($event_id);

    $payload = [
        'state_key'      => (string) $state_key,
        'event_id'       => $event_id,
        'file_hash'      => (string) ($state['file_hash'] ?? ''),
        'filename'       => (string) ($state['filename'] ?? ''),
        'asset_config'   => $asset_config,
        'source'         => 'tools_ticket_import',
        'source_ref'     => 'ticket_import:'.(string) ($state['file_hash'] ?? ''),
    ];

    $task_id = eventosapp_task_queue_create([
        'task_type'          => 'ticket_import',
        'task_group'         => 'massive',
        'channel'            => 'tickets',
        'title'              => 'Importación masiva de tickets · '.(get_the_title($event_id) ?: ('Evento '.$event_id)),
        'event_id'           => $event_id,
        'total_items'        => $total,
        'batch_size'         => $batch_size,
        'payload'            => $payload,
        'notification_email' => sanitize_email($state['notification_email'] ?? get_option('admin_email')),
        'created_by'         => absint($state['owner_user_id'] ?? get_current_user_id()),
        // Se crea pausada para guardar primero el cursor inicial. Así el dispatcher
        // nunca puede lanzar un worker con offset 0 cuando la ventana ya procesó filas.
        'status'             => 'paused',
        'priority'           => 20,
    ]);

    if (is_wp_error($task_id)) return $task_id;

    // Si el administrador ya procesó filas en la ventana, la cola continúa desde allí.
    eventosapp_task_queue_update($task_id, [
        'cursor_value'    => $offset,
        'processed_items' => $offset,
        'success_items'   => max(0, absint($state['created_count'] ?? 0) + absint($state['updated_existing'] ?? 0) - absint($state['error_count'] ?? 0)),
        'error_items'     => absint($state['error_count'] ?? 0),
        'skipped_items'   => absint($state['skipped_dup'] ?? 0),
    ]);

    $state['queue_task_id'] = absint($task_id);
    $state['execution_mode'] = 'background';
    $state['background_backend'] = 'task_queue';
    $state['status'] = 'running';
    $state['stopped'] = 0;
    $state['cancelled'] = 0;
    $state['asset_config'] = $asset_config;
    $state['updated_at'] = time();
    update_option($state_key, $state, false);

    eventosapp_task_queue_add_log($task_id, 'info', 'Configuración de anexos revisada antes de iniciar.', [
        'enabled_assets' => evapp_import_asset_config_labels($asset_config),
        'state_key'      => $state_key,
        'starting_row'   => $offset,
    ]);

    if (function_exists('eventosapp_task_queue_resume')) {
        eventosapp_task_queue_resume($task_id);
    } else {
        eventosapp_task_queue_update($task_id, [
            'status'      => 'queued',
            'next_run_at' => current_time('mysql', true),
        ]);
    }
    if (function_exists('eventosapp_task_queue_kick')) eventosapp_task_queue_kick();

    return absint($task_id);
}

/**
 * Canal de control independiente del estado pesado de la importación.
 * Evita que un worker que termina un lote con una copia antigua pueda sobrescribir
 * una orden de pausar o cancelar enviada desde otra petición.
 */
function evapp_import_control_option_key($state_key){
    return 'evapp_import_control_'.md5((string) $state_key);
}
function evapp_import_control_get($state_key, $fresh = false){
    $option_key = evapp_import_control_option_key($state_key);

    if ($fresh) {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option_key
        ));
        if ($raw === null) return [];
        $control = maybe_unserialize($raw);
    } else {
        $control = get_option($option_key, []);
    }

    return is_array($control) ? $control : [];
}
function evapp_import_control_set($state_key, $command, $task_id = ''){
    $allowed = ['browser','background','pause','cancel'];
    $command = sanitize_key((string) $command);
    if (!in_array($command, $allowed, true)) $command = 'browser';

    $current = evapp_import_control_get($state_key, true);
    $control = [
        'command'      => $command,
        'task_id'      => sanitize_text_field((string) $task_id),
        'requested_at' => time(),
        'requested_by' => (int) get_current_user_id(),
        'revision'     => (int) ($current['revision'] ?? 0) + 1,
    ];
    update_option(evapp_import_control_option_key($state_key), $control, false);
    return $control;
}
function evapp_import_control_command($state_key, $fresh = true){
    $control = evapp_import_control_get($state_key, $fresh);
    return sanitize_key((string) ($control['command'] ?? ''));
}
function evapp_import_control_reconcile_state($state_key, &$state, $persist = true){
    if (!is_array($state)) return '';

    $control = evapp_import_control_get($state_key, true);
    $command = sanitize_key((string) ($control['command'] ?? ''));
    $revision = (int) ($control['revision'] ?? 0);
    $applied_revision = (int) ($state['control_revision_applied'] ?? 0);
    $changed = false;
    $logs = [];

    if ($command === 'cancel') {
        if (($state['status'] ?? '') !== 'cancelled' || empty($state['cancelled']) || empty($state['stopped'])) {
            $changed = true;
        }
        $state['status'] = 'cancelled';
        $state['cancelled'] = 1;
        $state['stopped'] = 1;
        $state['done'] = 0;
        $state['updated_at'] = time();
        $state['finished_at_ts'] = (int) ($state['finished_at_ts'] ?? time());
        $state['background_token'] = '';
        if ($revision > $applied_revision) {
            $logs[] = evapp_import_log_entry('Cancelación confirmada por el canal de control persistente. No se ejecutarán más lotes.');
        }
    } elseif ($command === 'pause') {
        if (($state['status'] ?? '') !== 'stopped' || empty($state['stopped'])) {
            $changed = true;
        }
        $state['status'] = 'stopped';
        $state['stopped'] = 1;
        $state['done'] = 0;
        $state['updated_at'] = time();
        if ($revision > $applied_revision) {
            $logs[] = evapp_import_log_entry('Pausa confirmada por el canal de control persistente.');
        }
    }

    if ($revision > $applied_revision) {
        $state['control_revision_applied'] = $revision;
        $changed = true;
    }
    if ($logs) {
        $state = evapp_import_merge_runtime_logs($state, $logs);
    }

    if ($persist && $changed) {
        update_option($state_key, $state, false);
        evapp_import_background_touch($state_key, $state);
        if ($command === 'cancel' || $command === 'pause') {
            evapp_import_background_unschedule($state['background_task_id'] ?? '');
        }
    }

    return $command;
}
function evapp_import_background_control_allows_run($state){
    if (!is_array($state) || empty($state['background_task_id'])) return false;
    $entry = evapp_import_background_get_entry((string) $state['background_task_id']);
    if (!is_array($entry) || empty($entry['state_key'])) return false;
    $command = evapp_import_control_command((string) $entry['state_key'], true);
    return $command === '' || $command === 'background';
}

function evapp_import_background_cleanup_registry(){
    $registry = evapp_import_background_registry();
    $changed = false;
    $cutoff = time() - (7 * DAY_IN_SECONDS);
    foreach ($registry as $task_id => $entry) {
        $state_key = is_array($entry) ? (string) ($entry['state_key'] ?? '') : '';
        $state = $state_key ? get_option($state_key) : null;
        if (!is_array($state)) {
            unset($registry[$task_id]);
            $changed = true;
            continue;
        }
        $status = (string) ($state['status'] ?? 'ready');
        $finished = (int) ($state['finished_at_ts'] ?? $state['updated_at'] ?? $entry['updated_at'] ?? 0);
        if (in_array($status, ['done','cancelled'], true) && $finished > 0 && $finished < $cutoff) {
            unset($registry[$task_id]);
            $changed = true;
        }
    }
    if ($changed) evapp_import_background_save_registry($registry);
    return $registry;
}
function evapp_import_background_tasks(){
    $registry = evapp_import_background_cleanup_registry();
    $tasks = [];
    foreach ($registry as $task_id => $entry) {
        if (!is_array($entry) || empty($entry['state_key'])) continue;
        $state = get_option($entry['state_key']);
        if (!is_array($state)) continue;
        if (($state['execution_mode'] ?? 'browser') !== 'background') continue;
        $state['_state_key'] = (string) $entry['state_key'];
        $state['background_task_id'] = (string) $task_id;
        $tasks[] = $state;
    }
    usort($tasks, function($a, $b){
        return (int) ($b['updated_at'] ?? $b['started_at_ts'] ?? 0) <=> (int) ($a['updated_at'] ?? $a['started_at_ts'] ?? 0);
    });
    return $tasks;
}
function evapp_import_background_progress_url($state){
    return add_query_arg([
        'page'     => 'eventosapp_tools',
        'event_id' => (int) ($state['event_id'] ?? 0),
        'step'     => 4,
        'hash'     => (string) ($state['file_hash'] ?? ''),
        'task_id'  => (string) ($state['background_task_id'] ?? ''),
    ], admin_url('admin.php'));
}
function evapp_import_render_background_tasks_panel(){
    if (!current_user_can('manage_options')) return;
    $tasks = evapp_import_background_tasks();

    // Las importaciones nuevas viven en la cola central. Este acceso evita que la
    // pantalla Herramientas muestre un falso "sin tareas" cuando el trabajo ya fue
    // transferido y se está ejecutando correctamente desde Cola y Tareas.
    if (function_exists('eventosapp_task_queue_list')) {
        $queue_result = eventosapp_task_queue_list([
            'task_type'    => 'ticket_import',
            'status_scope' => 'unarchived',
            'page'         => 1,
            'per_page'     => 1,
        ]);
        $queue_total = absint($queue_result['total'] ?? 0);
        $queue_url = add_query_arg([
            'page'      => 'eventosapp_task_queue',
            'task_type' => 'ticket_import',
        ], admin_url('admin.php'));

        echo '<div class="card" style="max-width:none;padding:14px 16px;margin:14px 0 18px;border-left:4px solid #2271b1">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">';
        echo '<div><strong style="font-size:15px">Importaciones en Cola y Tareas</strong><div style="color:#646970;margin-top:3px">Los procesos nuevos se monitorean y controlan desde la cola central de EventosApp.</div></div>';
        echo '<div style="display:flex;align-items:center;gap:10px"><span style="background:#2271b1;color:#fff;border-radius:999px;padding:3px 9px;font-weight:600">'.$queue_total.'</span><a class="button button-primary" href="'.esc_url($queue_url).'">Abrir Cola y Tareas</a></div>';
        echo '</div></div>';
    }

    // Se conserva la visualización del worker anterior exclusivamente para tareas
    // que ya existían antes de esta integración o para el fallback de compatibilidad.
    if (!$tasks) return;

    echo '<div class="card" style="max-width:none;padding:0;margin:14px 0 18px;overflow:hidden">';
    echo '<div style="padding:14px 16px;background:#f6f7f7;border-bottom:1px solid #dcdcde;display:flex;justify-content:space-between;align-items:center;gap:10px">';
    echo '<div><strong style="font-size:15px">Procesos heredados de importación</strong><div style="color:#646970;margin-top:3px">Solo aparecen tareas creadas por el worker anterior de compatibilidad.</div></div>';
    echo '<span style="background:#646970;color:#fff;border-radius:999px;padding:3px 9px;font-weight:600">'.count($tasks).'</span>';
    echo '</div>';

    echo '<div style="padding:8px 14px 14px">';
    foreach ($tasks as $state) {
        $status = (string) ($state['status'] ?? 'ready');
        $status_labels = ['running'=>'Procesando','stopped'=>'Pausada','error'=>'Error','done'=>'Completada','cancelled'=>'Cancelada','ready'=>'Preparada'];
        $status_label = $status_labels[$status] ?? ucfirst($status);
        $total = max(0, (int) ($state['total_rows'] ?? 0));
        $offset = max(0, (int) ($state['offset'] ?? 0));
        $percent = $total > 0 ? min(100, (int) floor(($offset / $total) * 100)) : 0;
        $event_id = (int) ($state['event_id'] ?? 0);
        $event_title = get_the_title($event_id) ?: 'Evento sin título';
        $filename = (string) ($state['filename'] ?? 'CSV');
        $open_url = evapp_import_background_progress_url($state);
        $open = in_array($status, ['running','stopped','error'], true) ? ' open' : '';

        echo '<details'.$open.' style="border:1px solid #dcdcde;border-radius:8px;margin-top:10px;background:#fff">';
        echo '<summary style="cursor:pointer;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px">';
        echo '<span><strong>'.esc_html($event_title).' ['.$event_id.']</strong><br><small style="color:#646970">'.esc_html($filename).'</small></span>';
        echo '<span style="text-align:right"><strong>'.$percent.'%</strong><br><small>'.esc_html($status_label).'</small></span>';
        echo '</summary>';
        echo '<div style="padding:0 14px 14px;border-top:1px solid #f0f0f1">';
        echo '<div style="height:8px;background:#e5e7eb;border-radius:999px;overflow:hidden;margin:12px 0"><div style="height:100%;width:'.$percent.'%;background:#2271b1"></div></div>';
        echo '<p style="margin:6px 0;color:#50575e">Procesadas: <b>'.$offset.'</b> de <b>'.$total.'</b> · Creadas: <b>'.(int) ($state['created_count'] ?? 0).'</b> · Actualizadas: <b>'.(int) ($state['updated_existing'] ?? 0).'</b> · Omitidas: <b>'.(int) ($state['skipped_dup'] ?? 0).'</b></p>';
        if (!empty($state['last_error'])) echo '<p style="color:#b32d2e"><b>Último error:</b> '.esc_html($state['last_error']).'</p>';
        echo '<p style="margin:10px 0 0"><a class="button button-primary" href="'.esc_url($open_url).'">Abrir progreso y controles</a></p>';
        echo '</div></details>';
    }
    echo '</div></div>';
}

function evapp_import_background_unschedule($task_id){
    $task_id = sanitize_text_field((string) $task_id);
    if ($task_id === '') return;
    wp_clear_scheduled_hook('eventosapp_import_background_cron', [$task_id]);
}
function evapp_import_background_schedule($state, $delay = 60){
    if (($state['background_backend'] ?? '') === 'task_queue') return false;
    if (!is_array($state) || empty($state['background_task_id']) || ($state['execution_mode'] ?? '') !== 'background' || ($state['status'] ?? '') !== 'running') return false;
    if (!evapp_import_background_control_allows_run($state)) return false;
    $task_id = (string) $state['background_task_id'];
    $args = [$task_id];
    if (!wp_next_scheduled('eventosapp_import_background_cron', $args)) {
        wp_schedule_single_event(time() + max(2, (int) $delay), 'eventosapp_import_background_cron', $args);
    }
    if (function_exists('spawn_cron')) @spawn_cron(time());
    return true;
}
function evapp_import_background_dispatch($state){
    if (($state['background_backend'] ?? '') === 'task_queue') return false;
    if (!is_array($state) || empty($state['background_task_id']) || empty($state['background_token'])) return false;
    if (($state['execution_mode'] ?? '') !== 'background' || ($state['status'] ?? '') !== 'running') return false;
    if (!evapp_import_background_control_allows_run($state)) return false;

    evapp_import_background_schedule($state, 60);
    $response = wp_remote_post(admin_url('admin-ajax.php'), [
        'timeout'   => 0.01,
        'blocking'  => false,
        'sslverify' => apply_filters('https_local_ssl_verify', false),
        'body'      => [
            'action'  => 'eventosapp_import_background_worker',
            'task_id' => (string) $state['background_task_id'],
            'token'   => (string) $state['background_token'],
        ],
    ]);
    return !is_wp_error($response);
}
add_action('eventosapp_import_background_cron', function($task_id){
    $entry = evapp_import_background_get_entry($task_id);
    if (!is_array($entry) || empty($entry['state_key'])) return;
    $state = get_option($entry['state_key']);
    if (!is_array($state)) return;
    evapp_import_control_reconcile_state($entry['state_key'], $state, true);
    if (($state['execution_mode'] ?? '') !== 'background' || ($state['status'] ?? '') !== 'running') return;
    evapp_import_background_dispatch($state);
}, 10, 1);

function evapp_import_background_notification_recipient($state){
    $email = sanitize_email($state['notification_email'] ?? '');
    if ($email) return $email;
    $owner = !empty($state['owner_user_id']) ? get_userdata((int) $state['owner_user_id']) : null;
    if ($owner && is_email($owner->user_email)) return sanitize_email($owner->user_email);
    return sanitize_email(get_option('admin_email'));
}
function evapp_import_background_send_email($state, $kind, $value = 0, $message = ''){
    $to = evapp_import_background_notification_recipient($state);
    if (!$to) return false;
    $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $event_id = (int) ($state['event_id'] ?? 0);
    $event_title = get_the_title($event_id) ?: 'Evento';
    $total = (int) ($state['total_rows'] ?? 0);
    $offset = (int) ($state['offset'] ?? 0);
    $url = evapp_import_background_progress_url($state);

    if ($kind === 'error') {
        $subject = sprintf('[%s] Error en importación — %s [%d]', $site_name, $event_title, $event_id);
        $headline = 'La importación en segundo plano se detuvo por un error.';
    } else {
        $subject = sprintf('[%s] Importación %d%% — %s [%d]', $site_name, (int) $value, $event_title, $event_id);
        $headline = ((int) $value >= 100) ? 'La importación en segundo plano finalizó.' : 'La importación en segundo plano alcanzó el '.(int) $value.'%.';
    }

    $body = $headline."\n\n".
        "Evento: {$event_title} [{$event_id}]\n".
        "Archivo: ".($state['filename'] ?? '')."\n".
        "Filas procesadas: {$offset} de {$total}\n".
        "Tickets creados: ".(int) ($state['created_count'] ?? 0)."\n".
        "Tickets actualizados: ".(int) ($state['updated_existing'] ?? 0)."\n".
        "Duplicados/filas omitidas: ".(int) ($state['skipped_dup'] ?? 0)."\n";
    if ($message !== '') $body .= "Detalle: {$message}\n";
    $body .= "\nRevisar progreso y controles:\n{$url}\n";
    return wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}
function evapp_import_background_apply_notifications(&$state, $error_message = ''){
    if (!is_array($state) || ($state['execution_mode'] ?? '') !== 'background') return [];
    $logs = [];
    if (!isset($state['notified_milestones']) || !is_array($state['notified_milestones'])) $state['notified_milestones'] = [];

    if ($error_message !== '') {
        if (empty($state['error_notification_sent'])) {
            $sent = evapp_import_background_send_email($state, 'error', 0, $error_message);
            if ($sent) {
                $state['error_notification_sent'] = 1;
                $logs[] = evapp_import_log_entry('Notificación de error enviada a '.evapp_import_background_notification_recipient($state).'.');
            } else {
                $logs[] = evapp_import_log_entry('No se pudo confirmar el envío del correo de error; se volverá a intentar.');
            }
        }
        return $logs;
    }

    $total = (int) ($state['total_rows'] ?? 0);
    $offset = (int) ($state['offset'] ?? 0);
    $percent = $total > 0 ? min(100, (int) floor(($offset / $total) * 100)) : 0;
    foreach ([20,40,60,80,100] as $milestone) {
        if ($percent < $milestone || in_array($milestone, $state['notified_milestones'], true)) continue;
        $sent = evapp_import_background_send_email($state, 'progress', $milestone);
        if ($sent) {
            $state['notified_milestones'][] = $milestone;
            $logs[] = evapp_import_log_entry('Notificación de progreso '.$milestone.'% enviada a '.evapp_import_background_notification_recipient($state).'.');
        } else {
            $logs[] = evapp_import_log_entry('No se pudo confirmar el correo del '.$milestone.'%; se volverá a intentar en el siguiente lote.');
        }
    }
    $state['notified_milestones'] = array_values(array_unique(array_map('intval', $state['notified_milestones'])));
    return $logs;
}
function evapp_import_background_mark_error($state_key, &$state, $message){
    $state['status'] = 'error';
    $state['last_error'] = wp_strip_all_tags((string) $message);
    $state['updated_at'] = time();
    $state = evapp_import_merge_runtime_logs($state, array_merge(
        [evapp_import_log_entry('ERROR: '.$state['last_error'])],
        evapp_import_background_apply_notifications($state, $state['last_error'])
    ));
    update_option($state_key, $state, false);
    evapp_import_background_touch($state_key, $state);
    evapp_import_background_unschedule($state['background_task_id'] ?? '');
}

// === NUEVO: obtener/crear el usuario importador1 ===
function evapp_get_or_create_importer_user(){
    static $cached_user_id = null;
    if ($cached_user_id !== null) return $cached_user_id;

    $login = 'importador1';
    $email = 'importador1@eventosapp.com';

    // Primero por correo (requisito), luego por login como fallback
    $u = get_user_by('email', $email);
    if (!$u) { $u = get_user_by('login', $login); }
    if ($u && isset($u->ID)) {
        $cached_user_id = (int) $u->ID;
        return $cached_user_id;
    }

    // Crear sin notificar por correo
    $user_id = wp_insert_user([
        'user_login'   => $login,
        'user_email'   => $email,
        'user_pass'    => wp_generate_password(20, true, true),
        'display_name' => 'Importador 1',
        'first_name'   => 'Importador',
        'last_name'    => '1',
        'role'         => 'logistico', // suficiente para marcar autoría
    ]);

    if (is_wp_error($user_id)) {
        // Fallback: si falla, regresamos el usuario actual para no romper el flujo
        $cached_user_id = (int) get_current_user_id();
        return $cached_user_id;
    }
    $cached_user_id = (int) $user_id;
    return $cached_user_id;
}


/**
 * Genera CSV de plantilla (descarga). Incluye extras del evento.
 */
add_action('admin_post_eventosapp_csv_template', function(){
    if (!current_user_can('manage_options')) wp_die('No autorizado', '', 403);
    $event_id = intval($_GET['event_id'] ?? 0);
    if (!$event_id || get_post_type($event_id) !== 'eventosapp_event') wp_die('Evento inválido', '', 400);

    $filename = 'plantilla_tickets_evento_'.$event_id.'.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    $out = fopen('php://output','w');

    // Cabeceras base
    $headers = [
        'external_id_opcional', // para idempotencia/reintentos
        'nombre',
        'apellido',
        'email',
        'telefono',
        'empresa',
        'nit',
        'cargo',
        'cc',
        'ciudad',
        'pais',
        'localidad',
        'modalidad'
    ];

    // Extras del evento
    $extras = function_exists('eventosapp_get_event_extra_fields') ? eventosapp_get_event_extra_fields($event_id) : [];
    foreach ($extras as $f) {
        $headers[] = 'extra__'.$f['key']; // prefijo estable
    }

    fputcsv($out, $headers);

    // Fila de ejemplo
    $example = [
        'ABC-001',
        'Ana',
        'Pérez',
        'ana@example.com',
        '+57 300 1234567',
        'Mi Empresa SAS',
        '900123456',
        'Gerente',
        '1030xxx',
        'Barranquilla',
        'Colombia',
        'VIP',
        'presencial'
    ];
    foreach ($extras as $f) {
        // ejemplo genérico
        $example[] = ($f['type']==='select' && !empty($f['options'])) ? $f['options'][0] : 'valor';
    }
    fputcsv($out, $example);
    fclose($out);
    exit;
});

/**
 * Barra fija con selector de evento en la cabecera de "Herramientas".
 */
function evapp_tools_event_picker_bar($current_event_id = 0){
    // lista de eventos (publicados y borradores), ordenados por título
    $events = get_posts([
        'post_type'      => 'eventosapp_event',
        'post_status'    => ['publish','draft'],
        'posts_per_page' => 200,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    echo '<div class="evapp-tools-bar" style="display:flex;gap:10px;align-items:center;margin:8px 0 18px">';
    echo '<label for="evapp_event_picker" style="font-weight:600">Evento:</label>';
    echo '<select id="evapp_event_picker" style="min-width:360px">';

    // placeholder
    echo '<option value="">— Selecciona un evento —</option>';

    foreach ($events as $eid){
        $t = get_the_title($eid);
        $sel = selected($eid, $current_event_id, false);
        echo '<option value="'.esc_attr($eid).'" '.$sel.'>'.esc_html($t.' ['.$eid.']').'</option>';
    }
    echo '</select>';

    // enlace rápido para editar el evento actual
    if ($current_event_id){
        $edit = get_edit_post_link($current_event_id);
        if ($edit){
            echo '<a class="button" href="'.esc_url($edit).'">Editar evento</a>';
        }
    }
    echo '</div>';

    // JS: al cambiar de evento, te lleva a la misma página con step=1
    ?>
    <script>
    (function(){
      var sel = document.getElementById('evapp_event_picker');
      if (!sel) return;
      sel.addEventListener('change', function(){
        var v = this.value;
        var url = new URL(window.location.href);
        url.searchParams.set('page','eventosapp_tools');
        if (v) {
          url.searchParams.set('event_id', v);
          url.searchParams.set('step', '1');   // siempre empezamos en el paso 1
          url.searchParams.delete('hash');     // limpiamos estado previo
        } else {
          url.searchParams.delete('event_id');
          url.searchParams.set('step','1');
          url.searchParams.delete('hash');
        }
        window.location = url.toString();
      });
    })();
    </script>
    <?php
}

/**
 * Render de la pantalla Herramientas (asistente)
 */
function eventosapp_tools_render(){
    if (!current_user_can('manage_options')) wp_die('No autorizado', '', 403);

    $event_id = intval($_GET['event_id'] ?? 0);
    $step     = intval($_GET['step'] ?? 1);
    if ($step < 1 || $step > 4) $step = 1;

    echo '<div class="wrap" style="max-width:1100px">';
    echo '<h1>Herramientas</h1>';

    // ——— NUEVO: barra con selector de evento ———
    evapp_tools_event_picker_bar($event_id);

    // Panel global: permite volver a cualquier importación que siga o haya quedado en segundo plano.
    evapp_import_render_background_tasks_panel();

    // si todavía no hay evento seleccionado, paramos aquí (solo mostramos la barra y las tareas)
    if (!$event_id || get_post_type($event_id) !== 'eventosapp_event') {
        echo '<p>Elige un evento para iniciar el asistente de importación.</p>';
        echo '</div>';
        return;
    }

    $event = get_post($event_id);
    $step  = intval($_GET['step'] ?? 1);
    if ($step < 1 || $step > 4) $step = 1;

    $nonce = wp_create_nonce('evapp_tools_'.$event_id);

    $tpl_url = wp_nonce_url(
        add_query_arg(['action'=>'eventosapp_csv_template','event_id'=>$event_id], admin_url('admin-post.php')),
        'eventosapp_csv_template'
    );

    // Progreso previo (por hash+evento)
    $progress = evapp_import_get_latest_progress($event_id);

    echo '<div class="wrap" style="max-width:1100px">';
    echo '<h1>Herramientas — <span style="color:#555">'.esc_html($event->post_title).' ['.$event_id.']</span></h1>';

    // Migas del asistente
    echo '<h2 class="nav-tab-wrapper" style="margin-top:20px">';
    $tabs = ['1'=>'Subir CSV','2'=>'Mapear columnas','3'=>'Confirmar','4'=>'Importar'];
    foreach($tabs as $i=>$label){
        $cls = ($step===$i*1) ? ' nav-tab-active' : '';
        $url = add_query_arg(['page'=>'eventosapp_tools','event_id'=>$event_id,'step'=>$i], admin_url('admin.php'));
        echo '<a class="nav-tab'.$cls.'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
    }
    echo '</h2>';

    // Barra progreso previa
    if ($progress) {
        echo '<div style="background:#f6fbff;border:1px solid #bee3f8;padding:10px 12px;border-radius:8px;margin:8px 0 16px">';
        echo '<b>Importación previa detectada:</b> archivo <code>'.esc_html($progress['filename']).'</code> ';
        echo '(hash <code>'.esc_html(substr($progress['file_hash'],0,10)).'…</code>) — filas procesadas: <b>'.$progress['offset'].'</b>.';
        $progress_url = add_query_arg(['page'=>'eventosapp_tools','event_id'=>$event_id,'step'=>4,'hash'=>$progress['file_hash'],'task_id'=>$progress['background_task_id'] ?? ''], admin_url('admin.php'));
        echo ' <a href="'.esc_url($progress_url).'">Abrir progreso</a> o re-subir el <i>mismo</i> archivo para continuar.';
        echo '</div>';
    }

    // STEP 1: subir + plantilla
    if ($step === 1) {
        echo '<div class="card" style="padding:18px">';
        echo '<p>Descarga la <b>plantilla CSV</b> con los campos estándar y los <b>extras</b> configurados para este evento: ';
        echo '<a class="button button-secondary" href="'.esc_url($tpl_url).'">Descargar plantilla</a></p>';

        echo '<hr>';
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-ajax.php')).'">';
        echo '<input type="hidden" name="action" value="eventosapp_import_upload">';
        echo '<input type="hidden" name="event_id" value="'.esc_attr($event_id).'">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">';
        echo '<p><label><b>Elige tu CSV:</b><br>';
        echo '<input type="file" name="csv" accept=".csv,text/csv" required></label></p>';
        echo '<p><button class="button button-primary">Continuar</button></p>';
        echo '</form>';
        echo '</div>';
    }

    // STEP 2 y 3 y 4 renderizados por AJAX / redirecciones
    echo '</div>';
}

/**
 * Estructura de importación (estado por archivo)
 * option: evapp_import_state_{event_id}_{hash}_{userkey} = [
 *   'file' => PATH,
 *   'url'  => URL,
 *   'filename' => string,
 *   'file_hash' => string,
 *   'headers' => ['original'=>..., 'norm'=>...],
 *   'map' => ['csv_key' => 'field_key'],
 *   'offset' => int,
 *   'created_ids' => [ticket_post_id,...],
 *   'created_count' => int,
 *   'total_rows' => int,
 * ]
 */
function evapp_import_state_key($event_id, $hash){
    return 'evapp_import_state_'.$event_id.'_'.$hash.'_'.evapp_current_user_key();
}
function evapp_import_get_latest_progress($event_id){
    global $wpdb;
    $like = 'evapp_import_state_'.$event_id.'_%_'.evapp_current_user_key();
    $opt = $wpdb->get_var( $wpdb->prepare(
        "SELECT option_name
         FROM $wpdb->options
         WHERE option_name LIKE %s
         ORDER BY option_id DESC
         LIMIT 1",
         $like
    ) );
    if (!$opt) return null;
    $st = get_option($opt);
    return is_array($st) ? $st : null;
}

function evapp_import_append_log($event_id, $hash, $message){
    $key = evapp_import_state_key($event_id, $hash);
    $state = get_option($key);
    if (!is_array($state)) return;

    if (empty($state['runtime_log']) || !is_array($state['runtime_log'])) {
        $state['runtime_log'] = [];
    }

    $state['runtime_log'][] = [
        'time'    => current_time('H:i:s'),
        'message' => wp_strip_all_tags((string) $message),
    ];

    if (count($state['runtime_log']) > 300) {
        $state['runtime_log'] = array_slice($state['runtime_log'], -300);
    }

    update_option($key, $state, false);
}

function evapp_import_generate_assets_now($ticket_id, $event_id, $asset_config = null){
    $ticket_id = (int) $ticket_id;
    $event_id  = (int) $event_id;

    $report = [
        'ok'              => false,
        'ticket_id'       => $ticket_id,
        'event_id'        => $event_id,
        'modalidad'       => '',
        'generated'       => [],
        'skipped'         => [],
        'errors'          => [],
        'enabled_assets'  => [],
    ];

    if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
        $report['errors'][] = 'Ticket inválido.';
        return $report;
    }

    $runtime = evapp_import_event_runtime_config($event_id);
    $asset_config = is_array($asset_config)
        ? $asset_config
        : (is_array($runtime['asset_config'] ?? null) ? $runtime['asset_config'] : evapp_import_event_asset_config($event_id));
    $asset_config = wp_parse_args($asset_config, [
        'pdf'            => false,
        'ics'            => false,
        'wallet_android' => false,
        'wallet_apple'   => false,
        'whatsapp'       => false,
        'variants'       => false,
        'link_assistant' => false,
        'physical_qr'    => true,
    ]);
    $report['enabled_assets'] = evapp_import_asset_config_labels($asset_config);

    if (function_exists('eventosapp_ticket_sync_modalidad')) {
        $ticket_modalidad = eventosapp_ticket_sync_modalidad($ticket_id);
    } else {
        $ticket_modalidad = get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true) ?: 'presencial';
    }

    $is_virtual_ticket = ($ticket_modalidad === 'virtual');
    $report['modalidad'] = $ticket_modalidad;

    // Las variantes solo se evalúan cuando realmente están activas para el evento.
    // La sincronización de clases Google Wallet se realiza una sola vez antes del lote.
    if (!empty($asset_config['variants'])) {
        try {
            if (function_exists('eventosapp_ticket_variants_prepare_ticket_for_batch_context')) {
                eventosapp_ticket_variants_prepare_ticket_for_batch_context($ticket_id, $event_id, 'import_generate_assets', [
                    'sync_google_classes' => false,
                    'clear_assets_stale'  => true,
                    'log'                 => true,
                ]);
            } elseif (function_exists('eventosapp_ticket_variants_apply_to_ticket')) {
                eventosapp_ticket_variants_apply_to_ticket($ticket_id, $event_id, true);
            } else {
                $report['errors'][] = 'Variantes: el módulo de reglas no está disponible.';
            }
            if (empty($report['errors']) || strpos(implode(' | ', $report['errors']), 'Variantes:') === false) {
                $report['generated'][] = 'variante';
            }
        } catch (Throwable $e) {
            $report['errors'][] = 'Variante: '.$e->getMessage();
        }
    } else {
        $report['skipped'][] = 'variantes desactivadas';
    }

    if ($is_virtual_ticket) {
        if (function_exists('eventosapp_ticket_clear_presential_assets')) {
            eventosapp_ticket_clear_presential_assets($ticket_id);
        } else {
            delete_post_meta($ticket_id, '_eventosapp_ticket_pdf_url');
            delete_post_meta($ticket_id, '_eventosapp_wallet_android_url');
            delete_post_meta($ticket_id, '_eventosapp_apple_wallet_url');
            delete_post_meta($ticket_id, '_eventosapp_qr_codes');
        }
        $report['skipped'][] = 'QR/PDF/Wallet omitidos por modalidad virtual';
    } else {
        try {
            if (!empty($asset_config['physical_qr']) && class_exists('EventosApp_QR_Manager')) {
                $qr = EventosApp_QR_Manager::get_instance();
                if ($qr && method_exists($qr, 'generate_missing_qr_codes')) {
                    $qr->generate_missing_qr_codes($ticket_id);
                } elseif ($qr && method_exists($qr, 'generate_all_qr_codes')) {
                    $qr->generate_all_qr_codes($ticket_id);
                } elseif ($qr && method_exists($qr, 'regenerate_all_qr_codes_forced')) {
                    $existing_qrs = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
                    if (empty($existing_qrs)) {
                        $qr->regenerate_all_qr_codes_forced($ticket_id, true);
                    }
                }
                $generated_qrs = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
                if (is_array($generated_qrs) && !empty($generated_qrs)) {
                    $report['generated'][] = 'QR';
                } else {
                    $report['errors'][] = 'QR: el gestor no dejó códigos QR registrados en el ticket.';
                }
            } elseif (!empty($asset_config['physical_qr'])) {
                $report['errors'][] = 'QR: el gestor de códigos QR no está disponible.';
            }
        } catch (Throwable $e) {
            $report['errors'][] = 'QR: '.$e->getMessage();
        }
    }

    if (!empty($asset_config['ics'])) {
        try {
            if (function_exists('eventosapp_ticket_generar_ics')) {
                eventosapp_ticket_generar_ics($ticket_id);
                if (get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true)) {
                    $report['generated'][] = 'ICS';
                } else {
                    $report['errors'][] = 'ICS: no se registró la URL del archivo generado.';
                }
            } else {
                $report['errors'][] = 'ICS: la función generadora no está disponible.';
            }
        } catch (Throwable $e) {
            $report['errors'][] = 'ICS: '.$e->getMessage();
        }
    } else {
        delete_post_meta($ticket_id, '_eventosapp_ticket_ics_url');
        $report['skipped'][] = 'ICS desactivado';
    }

    if (!$is_virtual_ticket) {
        if (!empty($asset_config['wallet_android'])) {
            try {
                if (function_exists('eventosapp_generar_enlace_wallet_android')) {
                    $wallet_android_url = eventosapp_generar_enlace_wallet_android($ticket_id, false);
                    $wallet_android_url = $wallet_android_url ?: get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url', true);
                    if ($wallet_android_url) {
                        $report['generated'][] = 'Google Wallet';
                    } else {
                        $report['errors'][] = 'Google Wallet: no se generó el enlace del ticket.';
                    }
                } else {
                    $report['errors'][] = 'Google Wallet: la función generadora no está disponible.';
                }
            } catch (Throwable $e) {
                $report['errors'][] = 'Google Wallet: '.$e->getMessage();
            }
        } else {
            if (function_exists('eventosapp_eliminar_enlace_wallet_android')) {
                eventosapp_eliminar_enlace_wallet_android($ticket_id);
            }
            delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_android');
            delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url');
            delete_post_meta($ticket_id, '_eventosapp_wallet_google_object_id');
            delete_post_meta($ticket_id, '_eventosapp_wallet_google_class_id_effective');
            $report['skipped'][] = 'Google Wallet desactivado';
        }

        if (!empty($asset_config['wallet_apple'])) {
            try {
                if (function_exists('eventosapp_apple_generate_pass')) {
                    $wallet_apple_result = eventosapp_apple_generate_pass($ticket_id);
                } elseif (function_exists('eventosapp_generar_enlace_wallet_apple')) {
                    $wallet_apple_result = eventosapp_generar_enlace_wallet_apple($ticket_id);
                } else {
                    $wallet_apple_result = false;
                    $report['errors'][] = 'Apple Wallet: la función generadora no está disponible.';
                }
                $wallet_apple_url = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple', true)
                    ?: get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url', true)
                    ?: get_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url', true);
                if ($wallet_apple_result || $wallet_apple_url) {
                    $report['generated'][] = 'Apple Wallet';
                } elseif (strpos(implode(' | ', $report['errors']), 'Apple Wallet:') === false) {
                    $report['errors'][] = 'Apple Wallet: no se generó el archivo o enlace del pase.';
                }
            } catch (Throwable $e) {
                $report['errors'][] = 'Apple Wallet: '.$e->getMessage();
            }
        } else {
            delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple');
            delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url');
            delete_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url');
            $report['skipped'][] = 'Apple Wallet desactivado';
        }

        if (!empty($asset_config['pdf'])) {
            try {
                if (function_exists('eventosapp_ticket_generar_pdf')) {
                    eventosapp_ticket_generar_pdf($ticket_id);
                    if (get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true)) {
                        $report['generated'][] = 'PDF';
                    } else {
                        $report['errors'][] = 'PDF: no se registró la URL del archivo generado.';
                    }
                } else {
                    $report['errors'][] = 'PDF: la función generadora no está disponible.';
                }
            } catch (Throwable $e) {
                $report['errors'][] = 'PDF: '.$e->getMessage();
            }
        } else {
            delete_post_meta($ticket_id, '_eventosapp_ticket_pdf_url');
            $report['skipped'][] = 'PDF desactivado';
        }
    }

    // La preparación de imagen/mensaje de WhatsApp es costosa y solo se ejecuta
    // cuando el evento habilitó explícitamente la mensajería de tickets.
    if (!empty($asset_config['whatsapp']) && function_exists('eventosapp_whatsapp_prepare_ticket_assets')) {
        try {
            eventosapp_whatsapp_prepare_ticket_assets($ticket_id, [
                'event_id'               => $event_id,
                'context'                => $is_virtual_ticket ? 'import_generate_assets_virtual' : 'import_generate_assets_presencial',
                'apply_variant'          => false,
                'refresh_enabled_assets' => false,
                'ensure_qr'              => !$is_virtual_ticket,
                'ensure_landing'         => true,
                'ensure_message_image'   => true,
                'rebuild_search_index'   => false,
                'log'                    => true,
            ]);
            $report['generated'][] = 'WhatsApp';
        } catch (Throwable $e) {
            $report['errors'][] = 'WhatsApp: '.$e->getMessage();
        }
    } elseif (!empty($asset_config['whatsapp'])) {
        $report['errors'][] = 'WhatsApp: la función de preparación de recursos no está disponible.';
    } else {
        $report['skipped'][] = 'WhatsApp desactivado';
    }

    $report['ok'] = empty($report['errors']);
    update_post_meta($ticket_id, '_eventosapp_import_asset_report', $report);
    if ($report['ok']) {
        delete_post_meta($ticket_id, '_eventosapp_import_assets_pending');
    } else {
        update_post_meta($ticket_id, '_eventosapp_import_assets_pending', [
            'at'     => current_time('mysql'),
            'errors' => $report['errors'],
        ]);
    }
    return $report;
}


//
// === AJAX Paso 1 → recibe CSV, detecta cabeceras y pasa a Step 2 (mapeo) ===
//
add_action('wp_ajax_eventosapp_import_upload', function(){
    if (!current_user_can('manage_options')) wp_die('No autorizado', '', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $nonce    = $_POST['_wpnonce'] ?? '';
    if (!$event_id || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) wp_die('Solicitud inválida', '', 400);

    if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        wp_die('Archivo CSV requerido', '', 400);
    }

    // Mover a uploads
    [$dir, $baseurl] = evapp_import_upload_dir();
    $orig_name = sanitize_file_name($_FILES['csv']['name']);
    $tmp = $_FILES['csv']['tmp_name'];
    $dest = $dir . uniqid('evimp_') . '_' . $orig_name;
    if (!move_uploaded_file($tmp, $dest)) wp_die('No se pudo mover el archivo', '', 500);
    $url = $baseurl . basename($dest);

    // Hash del contenido
    $hash = sha1_file($dest);

    // Leer cabeceras
    $fh = fopen($dest, 'r');
    if (!$fh) wp_die('No se pudo abrir el CSV', '', 500);
    $headers = fgetcsv($fh);
    fclose($fh);
    if (!is_array($headers) || !$headers) wp_die('CSV sin cabeceras', '', 400);

    $headers_norm = [];
    foreach ($headers as $h) { $headers_norm[] = evapp_sanitize_header_key($h); }

 // Clave de estado por evento+hash+usuario
$key = evapp_import_state_key($event_id, $hash);
$existing = get_option($key);

// Estado base (datos del archivo recién subido)
$state = [
    'file'         => $dest,
    'url'          => $url,
    'filename'     => $orig_name,
    'file_hash'    => $hash,
    'headers'      => ['original'=>$headers, 'norm'=>$headers_norm],
    'map'          => [],
    'offset'       => 0,
    'created_ids'  => [],
    'created_count'=> 0,
    'event_id'     => $event_id,
    'total_rows'   => 0,
];

// ⬅️ NUEVO: si ya existía estado para este mismo hash, preservamos progreso y configuración
if (is_array($existing)) {
    $state['map']           = $existing['map']           ?? [];
    $state['offset']        = intval($existing['offset'] ?? 0);
    $state['created_ids']   = is_array($existing['created_ids'] ?? null) ? $existing['created_ids'] : [];
    $state['created_count'] = intval($existing['created_count'] ?? 0);
    $state['total_rows']    = intval($existing['total_rows'] ?? 0);
    if (isset($existing['queue_email']))  $state['queue_email']  = $existing['queue_email'];
    if (isset($existing['rate_per_min'])) $state['rate_per_min'] = $existing['rate_per_min'];
    foreach (['status','stopped','cancelled','done','runtime_log','batch_size','performance','updated_existing','skipped_dup','started_at','started_at_ts','finished_at','finished_at_ts','execution_mode','background_task_id','background_token','owner_user_id','notification_email','notified_milestones','error_notification_sent','last_error','updated_at'] as $preserve_key) {
        if (array_key_exists($preserve_key, $existing)) $state[$preserve_key] = $existing[$preserve_key];
    }
}

update_option($key, $state, false);

    // Redirigir a paso 2 (mapeo)
    $url2 = add_query_arg([
        'page'     => 'eventosapp_tools',
        'event_id' => $event_id,
        'step'     => 2,
        'hash'     => $hash,
    ], admin_url('admin.php'));
    wp_safe_redirect($url2);
    exit;
});

//
// === Render mapeo (step 2) ===
//
add_action('admin_init', function(){
    if (!is_admin() || !isset($_GET['page']) || $_GET['page']!=='eventosapp_tools') return;
    $step = intval($_GET['step'] ?? 0);
    if ($step !== 2) return;

    $event_id = intval($_GET['event_id'] ?? 0);
    $hash     = sanitize_text_field($_GET['hash'] ?? '');
    $state    = $hash ? get_option( evapp_import_state_key($event_id, $hash) ) : null;
    if (!$state) return;

    add_action('admin_notices', function() use ($state, $event_id, $hash){
        $headers = $state['headers']['original'];
        $headers_norm = $state['headers']['norm'];
        $nonce = wp_create_nonce('evapp_tools_'.$event_id);

        // Campos disponibles en plataforma
        $platform_fields = [
            'external_id' => 'ID externo (opcional, idempotencia)',
            'nombre'      => 'Nombre',
            'apellido'    => 'Apellido',
            'email'       => 'Email',
            'telefono'    => 'Teléfono',
            'empresa'     => 'Empresa',
            'nit'         => 'NIT',
            'cargo'       => 'Cargo',
            'cc'          => 'Cédula',
            'ciudad'      => 'Ciudad',
            'pais'        => 'País',
            'localidad'   => 'Localidad',
            'modalidad'   => 'Modalidad del ticket (presencial/virtual)',
        ];
        $extras = function_exists('eventosapp_get_event_extra_fields') ? eventosapp_get_event_extra_fields($event_id) : [];
        foreach ($extras as $f) {
            $platform_fields['extra__'.$f['key']] = 'Extra: '.$f['label'];
        }

        echo '<div class="wrap" style="max-width:1100px">';
        echo '<h2>Mapear columnas — archivo <code>'.esc_html($state['filename']).'</code></h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-ajax.php')).'">';
        echo '<input type="hidden" name="action" value="eventosapp_import_save_map">';
        echo '<input type="hidden" name="event_id" value="'.esc_attr($event_id).'">';
        echo '<input type="hidden" name="hash" value="'.esc_attr($hash).'">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">';

        echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>Columna CSV</th><th>Columna normalizada</th><th>Mapear a</th></tr></thead><tbody>';
        foreach ($headers as $i=>$raw){
            echo '<tr>';
            echo '<td>'.esc_html($raw).'</td>';
            echo '<td><code>'.esc_html($headers_norm[$i]).'</code></td>';
            echo '<td><select name="map['.$i.']">';
            echo '<option value="">— No importar —</option>';
            foreach ($platform_fields as $k=>$label){
                $sel = '';
                // Autopropuesta simple por coincidencia
                if ($headers_norm[$i] === evapp_sanitize_header_key($k)) $sel = ' selected';
                if ($headers_norm[$i] === evapp_sanitize_header_key('extra__'.str_replace('extra__','',$k))) $sel = ' selected';
                echo '<option value="'.esc_attr($k).'"'.$sel.'>'.esc_html($label.' ['.$k.']').'</option>';
            }
            echo '</select></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<p style="margin-top:12px"><b>Requeridos mínimos:</b> Nombre, Apellido, Email o CC, y Localidad.</p>';
        echo '<p><button class="button button-primary">Continuar</button></p>';
        echo '</form>';
        echo '</div>';
    });
});

//
// === Guardar mapeo → Step 3 (confirmación/validación) ===
//
add_action('wp_ajax_eventosapp_import_save_map', function(){
    if (!current_user_can('manage_options')) wp_die('No autorizado', '', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';
    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) wp_die('Solicitud inválida', '', 400);

    $state = get_option( evapp_import_state_key($event_id, $hash) );
    if (!$state) wp_die('Estado no encontrado', '', 404);

    $map = isset($_POST['map']) && is_array($_POST['map']) ? array_map('sanitize_text_field', $_POST['map']) : [];
    $state['map'] = $map;
    update_option( evapp_import_state_key($event_id, $hash), $state, false );

    // Redirigir a Step 3
    $url3 = add_query_arg([
        'page'=>'eventosapp_tools','event_id'=>$event_id,'step'=>3,'hash'=>$hash
    ], admin_url('admin.php'));
    wp_safe_redirect($url3);
    exit;
});

add_action('admin_init', function(){
    if (!is_admin() || !isset($_GET['page']) || $_GET['page']!=='eventosapp_tools') return;
    $step = intval($_GET['step'] ?? 0);
    if ($step !== 3) return;

    $event_id = intval($_GET['event_id'] ?? 0);
    $hash     = sanitize_text_field($_GET['hash'] ?? '');
    $state    = $hash ? get_option( evapp_import_state_key($event_id, $hash) ) : null;
    if (!$state) return;

    add_action('admin_notices', function() use ($state, $event_id, $hash){
        $nonce = wp_create_nonce('evapp_tools_'.$event_id);

        // Validar mapeo mínimo
        $csv_keys = $state['headers']['norm'];
        $map = $state['map'];
        $rev = []; // indice csv_i => field_key
        foreach ($map as $i=>$k) { if ($k) $rev[intval($i)] = $k; }

        $required = ['nombre','apellido','localidad'];
        $has_contact = (in_array('email', $rev, true) || in_array('cc', $rev, true));
        $missing = [];
        foreach ($required as $r) if (!in_array($r, $rev, true)) $missing[] = $r;

        echo '<div class="wrap" style="max-width:1100px">';
        echo '<h2>Confirmar importación</h2>';

        if ($missing || !$has_contact) {
            echo '<div class="notice notice-error"><p>';
            echo 'Campos obligatorios faltantes: <b>'.esc_html(implode(', ', $missing)).'</b>. ';
            if (!$has_contact) echo 'Debes mapear al menos <b>Email</b> o <b>CC</b>.';
            echo '</p></div>';
            $back = add_query_arg(['step'=>2], remove_query_arg([]));
            echo '<p><a class="button" href="'.esc_url($back).'">Volver al mapeo</a></p>';
            echo '</div>';
            return;
        }

			// Muestra un preview de 10 filas con validaciones básicas
			$fh = fopen($state['file'], 'r');
			$hdr = fgetcsv($fh);
			$preview = [];
			$line = 1;

			// ⬅️ NUEVO: saltar filas ya procesadas (offset actual)
			$offset_now = intval($state['offset'] ?? 0);
			$skipped = 0;
			while ($skipped < $offset_now && ($row = fgetcsv($fh)) !== false) { $skipped++; $line++; }

			while (($row = fgetcsv($fh)) !== false && count($preview) < 10) {
				$line++;
				$item = ['line'=>$line,'errors'=>[],'data'=>[]];
				foreach ($rev as $i=>$field){
					$val = $row[$i] ?? '';
					$item['data'][$field] = $val;
				}
				// Validaciones simples
				if (empty($item['data']['nombre']))    $item['errors'][]='nombre vacío';
				if (empty($item['data']['apellido']))  $item['errors'][]='apellido vacío';
				if (empty($item['data']['localidad'])) $item['errors'][]='localidad vacía';
				if (empty($item['data']['email']) && empty($item['data']['cc'])) $item['errors'][]='email/cc vacío';
				if (!empty($item['data']['email']) && !is_email($item['data']['email'])) $item['errors'][]='email inválido';
				$preview[] = $item;
			}
			fclose($fh);


        echo '<p>Archivo: <code>'.esc_html($state['filename']).'</code> — columnas mapeadas: <code>'.esc_html(count($rev)).'</code>.</p>';
        echo '<table class="widefat striped"><thead><tr><th>#</th><th>Errores</th><th>Datos (parciales)</th></tr></thead><tbody>';
        foreach ($preview as $it){
            echo '<tr>';
            echo '<td>'.intval($it['line']).'</td>';
            echo '<td>'.($it['errors'] ? '<span style="color:#b00">'.esc_html(implode('; ', $it['errors'])).'</span>' : '<span style="color:#0a0">OK</span>').'</td>';
            $show = [];
            foreach (['nombre','apellido','email','cc','localidad','modalidad'] as $k){
                if (isset($it['data'][$k])) $show[] = $k.': '.$it['data'][$k];
            }
            echo '<td>'.esc_html(implode(' | ', $show)).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Config de importación por lotes
        echo '<form method="post" action="'.esc_url(admin_url('admin-ajax.php')).'" style="margin-top:12px">';
        echo '<input type="hidden" name="action" value="eventosapp_import_confirm">';
        echo '<input type="hidden" name="event_id" value="'.esc_attr($event_id).'">';
        echo '<input type="hidden" name="hash" value="'.esc_attr($hash).'">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">';
        $asset_labels = evapp_import_asset_config_labels(evapp_import_event_asset_config($event_id));
        echo '<p><b>Procesamiento:</b> antes de iniciar se revisó el metabox de extras. Se generarán únicamente <strong>'.esc_html(implode(', ', $asset_labels)).'</strong>, respetando además la modalidad presencial o virtual de cada ticket. Este importador no programa correos.</p>';
        echo '<p><label>Lote objetivo: <input type="number" name="batch_size" value="20" min="1" max="50" style="width:80px"></label> <span style="color:#666">Recomendado: 20. El sistema puede cerrar antes el lote si alcanza el límite seguro de tiempo o memoria.</span></p>';
        echo '<p style="background:#f6f7f7;border-left:4px solid #2271b1;padding:9px 11px"><b>Control adaptativo:</b> se medirán tiempo por ticket, memoria PHP, CPU del proceso, consultas SQL y carga promedio del servidor. Con esos datos se ajustará automáticamente el tamaño efectivo de la siguiente petición sin superar el lote objetivo.</p>';
        echo '<p><button class="button button-primary">Empezar importación</button></p>';
        echo '</form>';

        echo '</div>';
    });
});

//
// === Step 4: confirmar → arrancar importación y mostrar progreso (10 en 10) ===
//
add_action('wp_ajax_eventosapp_import_confirm', function(){
    if (!current_user_can('manage_options')) wp_die('No autorizado', '', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';

    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) wp_die('Solicitud inválida', '', 400);

    $state = get_option(evapp_import_state_key($event_id, $hash));
    if (!$state) wp_die('Estado no encontrado', '', 404);

    if (!file_exists($state['file'])) {
        wp_die('El archivo CSV no existe', '', 500);
    }

    $fh = @fopen($state['file'], 'r');
    if (!$fh) {
        wp_die('No se pudo abrir el archivo CSV', '', 500);
    }

    $total_rows = 0;
    fgetcsv($fh);
    $csv_data_offset = ftell($fh);
    while (fgetcsv($fh) !== false) {
        $total_rows++;
    }
    fclose($fh);

    $batch_size = intval($_POST['batch_size'] ?? 20);
    $batch_size = max(1, min(50, $batch_size));

    $state_key = evapp_import_state_key($event_id, $hash);

    // Si se vuelve a confirmar el mismo CSV, detiene primero cualquier tarea anterior
    // para que nunca existan dos workers procesando el mismo archivo.
    $previous_queue_task_id = absint($state['queue_task_id'] ?? 0);
    if ($previous_queue_task_id && function_exists('eventosapp_task_queue_get') && function_exists('eventosapp_task_queue_cancel')) {
        $previous_task = eventosapp_task_queue_get($previous_queue_task_id);
        $terminal_statuses = function_exists('eventosapp_task_queue_terminal_statuses') ? eventosapp_task_queue_terminal_statuses() : ['cancelled','completed','expired','failed','archived'];
        if (is_array($previous_task) && !in_array($previous_task['status'], $terminal_statuses, true)) {
            eventosapp_task_queue_cancel($previous_queue_task_id, 'La importación fue reiniciada desde Herramientas.');
        }
    }

    $asset_config = evapp_import_event_asset_config($event_id);
    $asset_labels = evapp_import_asset_config_labels($asset_config);

    $state['total_rows']     = $total_rows;
    $state['batch_size']     = $batch_size;
    $state['status']         = 'ready';
    $state['offset']         = 0;
    $state['created_count']  = 0;
    $state['updated_existing'] = 0;
    $state['skipped_dup']    = 0;
    $state['error_count']    = 0;
    $state['csv_byte_offset']= (int) $csv_data_offset;
    $state['cancelled']      = 0;
    $state['stopped']        = 0;
    $state['done']           = 0;
    $state['runtime_log']    = [];
    $state['started_at']     = '';
    $state['finished_at']    = '';
    $state['last_error']     = '';
    $state['admin_notified'] = 0;
    $state['asset_config']   = $asset_config;
    $state['asset_config_checked_at'] = time();
    $state['event_assets_prepared'] = 0;
    $state['performance']    = [
        'batches'          => 0,
        'total_runtime_ms' => 0,
        'avg_ms_per_row'   => 0,
        'last_batch'       => [],
    ];
    $state['started_at_ts'] = 0;
    $state['finished_at_ts'] = 0;
    $state['execution_mode'] = 'browser';
    $state['background_backend'] = '';
    $state['queue_task_id'] = 0;
    $state['queue_status'] = '';
    $state['owner_user_id'] = get_current_user_id();
    $state['notification_email'] = '';
    $state['notified_milestones'] = [];
    $state['error_notification_sent'] = 0;
    $state['updated_at'] = time();
    if (!empty($state['background_task_id'])) evapp_import_background_unschedule($state['background_task_id']);

    $control = evapp_import_control_set($state_key, 'browser', '');
    $state['control_revision_applied'] = (int) ($control['revision'] ?? 0);
    $state = evapp_import_merge_runtime_logs($state, [
        evapp_import_log_entry('Importación preparada. Lote objetivo: '.$batch_size.' registro(s).'),
        evapp_import_log_entry('Configuración revisada antes de iniciar. Se generarán únicamente: '.implode(', ', $asset_labels).'.'),
    ]);
    update_option($state_key, $state, false);

    $url4 = add_query_arg([
        'page'     => 'eventosapp_tools',
        'event_id' => $event_id,
        'step'     => 4,
        'hash'     => $hash,
    ], admin_url('admin.php'));
    wp_safe_redirect($url4);
    exit;
});

add_action('admin_init', function(){
    if (!is_admin() || !isset($_GET['page']) || $_GET['page']!=='eventosapp_tools') return;
    $step = intval($_GET['step'] ?? 0);
    if ($step !== 4) return;

    $event_id = intval($_GET['event_id'] ?? 0);
    $hash     = sanitize_text_field($_GET['hash'] ?? '');
    $task_id  = sanitize_text_field($_GET['task_id'] ?? '');
    $state_key = $hash ? evapp_import_background_resolve_state_key($event_id, $hash, $task_id) : '';
    $state    = $state_key ? get_option($state_key) : null;
    if (!$state) return;

    add_action('admin_notices', function() use ($state, $event_id, $hash, $task_id){
        $nonce    = wp_create_nonce('evapp_tools_'.$event_id);
        $ajax_url = admin_url('admin-ajax.php');

        echo '<div style="border:1px solid #ccc; background:#fff; padding:1rem; margin-top:1rem; border-radius:4px;">';
        echo '<h3>Paso 4: Importar</h3>';
        echo '<p>Procesaremos el CSV por lotes controlados. Cada ticket conservará el QR requerido y solo generará los anexos habilitados en el evento.</p>';
        echo '<p><strong>Total de filas:</strong> '.intval($state['total_rows'] ?? 0).' | <strong>Lote objetivo:</strong> '.intval($state['batch_size'] ?? 20).' registro(s)</p>';
        echo '<p style="background:#ecfdf5;padding:8px;border-left:4px solid #16a34a;"><strong>Anexos y funciones detectados:</strong> '.esc_html(implode(', ', evapp_import_asset_config_labels($state['asset_config'] ?? []))).'. Los anexos desactivados no consumirán tiempo ni recursos durante esta importación.</p>';
        echo '<p style="background:#e7f5ff;padding:8px;border-left:4px solid #2271b1;"><strong>Importante:</strong> en modo normal la importación depende de esta ventana. Al usar <b>Enviar a Cola y Tareas</b>, el proceso quedará bajo la cola central, continuará aunque cierres el navegador y podrá pausarse, reanudarse, cancelarse y auditarse desde esa sección.</p>';

        echo '<p style="display:flex; gap:8px; flex-wrap:wrap; margin:0 0 14px 0;">';
        echo '<button id="evapp_start_import" class="button button-primary button-large">Iniciar / Reanudar en esta ventana</button>';
        echo '<button id="evapp_background_import" class="button button-secondary button-large">Enviar a Cola y Tareas</button>';
        echo '<button id="evapp_stop_import" class="button button-secondary button-large">Pausar</button>';
        echo '<button id="evapp_cancel_import" class="button button-link-delete">Cancelar proceso</button>';
        echo '</p>';

        echo '<style>';
        echo '.evapp-import-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:10px;margin:14px 0}';
        echo '.evapp-import-metric{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px 12px}';
        echo '.evapp-import-metric small{display:block;color:#646970;margin-bottom:4px}';
        echo '.evapp-import-metric strong{font-size:16px;color:#1d2327}';
        echo '</style>';

        echo '<div style="margin-top:1rem; padding:1rem; border:1px solid #ccc; background:#f9f9f9; border-radius:4px;">';
        echo '<div style="margin-bottom:0.5rem;"><strong>Progreso:</strong> <span id="evapp_status_badge" style="display:inline-block;padding:2px 8px;border-radius:10px;background:#eef2ff;color:#4338ca;">'.esc_html($state['status'] ?? 'ready').'</span> <span id="evapp_resource_level" style="display:inline-block;padding:2px 8px;border-radius:10px;background:#ecfdf5;color:#166534;margin-left:5px;">Recursos: esperando datos</span> <span id="evapp_connection_state" style="display:inline-block;padding:2px 8px;border-radius:10px;background:#f6f7f7;color:#50575e;margin-left:5px;">Continuidad: lista</span> <span id="evapp_background_state" style="display:inline-block;padding:2px 8px;border-radius:10px;background:#f6f7f7;color:#50575e;margin-left:5px;">Modo: ventana</span></div>';
        echo '<div style="margin-bottom:1rem;">';
        echo '<div style="background:#e0e0e0; height:24px; border-radius:4px; overflow:hidden; position:relative;">';
        echo '<div id="evapp_progress_bar" style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;"></div>';
        echo '<div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); font-size:12px; font-weight:600; color:#333;">';
        echo '<span id="evapp_progress_text">0%</span>';
        echo '</div></div></div>';
        echo '<p><strong>Offset:</strong> <span id="evapp_offset">0</span> | <strong>Creados:</strong> <span id="evapp_created">0</span> | <strong>Actualizados:</strong> <span id="evapp_updated">0</span> | <strong>Omitidos:</strong> <span id="evapp_skipped">0</span> | <strong>Errores:</strong> <span id="evapp_errors">0</span></p>';
        echo '<p id="evapp_queue_task_wrap" style="display:none;margin:8px 0 14px"><a id="evapp_queue_task_link" class="button" href="#">Abrir tarea en Cola y Tareas</a></p>';

        echo '<div class="evapp-import-metrics">';
        echo '<div class="evapp-import-metric"><small>Lote efectivo</small><strong id="evapp_effective_batch">—</strong></div>';
        echo '<div class="evapp-import-metric"><small>Velocidad</small><strong id="evapp_rate">—</strong></div>';
        echo '<div class="evapp-import-metric"><small>Tiempo último lote</small><strong id="evapp_batch_time">—</strong></div>';
        echo '<div class="evapp-import-metric"><small>Tiempo estimado</small><strong id="evapp_eta">—</strong></div>';
        echo '<div class="evapp-import-metric"><small>Memoria PHP</small><strong id="evapp_memory">—</strong></div>';
        echo '<div class="evapp-import-metric"><small>CPU del proceso</small><strong id="evapp_cpu">—</strong></div>';
        echo '<div class="evapp-import-metric"><small>Consultas SQL</small><strong id="evapp_queries">—</strong></div>';
        echo '<div class="evapp-import-metric"><small>Carga servidor 1/5/15 min</small><strong id="evapp_load">—</strong></div>';
        echo '</div>';

        echo '<div id="evapp_log" style="max-height:320px; overflow-y:auto; font-family:monospace; font-size:13px; background:#fff; padding:8px; border:1px solid #ccc; border-radius:4px;"></div>';
        echo '</div>';
        ?>
        <script>
        (function(){
          if (typeof ajaxurl === 'undefined') {
            var ajaxurl = '<?php echo esc_js($ajax_url); ?>';
          }

          const totalRows = <?php echo intval($state['total_rows'] ?? 0); ?>;
          const nonce     = '<?php echo esc_js($nonce); ?>';
          const eventId   = '<?php echo intval($event_id); ?>';
          const hash      = '<?php echo esc_js($hash); ?>';
          let taskId      = '<?php echo esc_js($task_id ?: ($state['background_task_id'] ?? '')); ?>';

          const btnStart  = document.getElementById('evapp_start_import');
          const btnBackground = document.getElementById('evapp_background_import');
          const btnStop   = document.getElementById('evapp_stop_import');
          const btnCancel = document.getElementById('evapp_cancel_import');
          const logBox    = document.getElementById('evapp_log');
          const txt       = document.getElementById('evapp_progress_text');
          const bar       = document.getElementById('evapp_progress_bar');
          const offsetEl  = document.getElementById('evapp_offset');
          const createdEl = document.getElementById('evapp_created');
          const updatedEl = document.getElementById('evapp_updated');
          const skippedEl = document.getElementById('evapp_skipped');
          const errorsEl  = document.getElementById('evapp_errors');
          const queueTaskWrap = document.getElementById('evapp_queue_task_wrap');
          const queueTaskLink = document.getElementById('evapp_queue_task_link');
          const badgeEl   = document.getElementById('evapp_status_badge');
          const resourceLevelEl = document.getElementById('evapp_resource_level');
          const connectionStateEl = document.getElementById('evapp_connection_state');
          const backgroundStateEl = document.getElementById('evapp_background_state');
          const effectiveBatchEl = document.getElementById('evapp_effective_batch');
          const rateEl = document.getElementById('evapp_rate');
          const batchTimeEl = document.getElementById('evapp_batch_time');
          const etaEl = document.getElementById('evapp_eta');
          const memoryEl = document.getElementById('evapp_memory');
          const cpuEl = document.getElementById('evapp_cpu');
          const queriesEl = document.getElementById('evapp_queries');
          const loadEl = document.getElementById('evapp_load');

          let busy = false;
          let statusBusy = false;
          let autoRun = false;
          let manualStopRequested = false;
          let consecutiveErrors = 0;
          let nextTickTimer = null;
          let lastStatusErrorAt = 0;
          let executionMode = '<?php echo esc_js($state['execution_mode'] ?? 'browser'); ?>';

          function addLog(line){
            if (!line) return;
            logBox.innerHTML += line + '<br>';
            logBox.scrollTop = logBox.scrollHeight;
          }

          function addThrottledStatusError(message){
            const now = Date.now();
            if ((now - lastStatusErrorAt) < 15000) return;
            lastStatusErrorAt = now;
            addLog('[' + new Date().toLocaleTimeString() + '] ' + message);
          }

          function formatBytes(bytes){
            bytes = Number(bytes || 0);
            if (!bytes) return '0 MB';
            const units = ['B','KB','MB','GB'];
            let index = 0;
            while (bytes >= 1024 && index < units.length - 1) {
              bytes = bytes / 1024;
              index++;
            }
            return bytes.toFixed(index === 0 ? 0 : 1) + ' ' + units[index];
          }

          function formatDuration(seconds){
            seconds = Math.max(0, parseInt(seconds || 0, 10));
            if (!seconds) return 'Calculando…';
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const secs = seconds % 60;
            if (hours > 0) return hours + 'h ' + minutes + 'm';
            if (minutes > 0) return minutes + 'm ' + secs + 's';
            return secs + 's';
          }

          function setConnectionState(level, text){
            const palette = {
              normal: ['#ecfdf5','#166534'],
              warning: ['#fff7ed','#9a3412'],
              error: ['#fee2e2','#991b1b'],
              idle: ['#f6f7f7','#50575e']
            };
            const selected = palette[level] || palette.idle;
            connectionStateEl.textContent = text;
            connectionStateEl.style.background = selected[0];
            connectionStateEl.style.color = selected[1];
          }

          function renderResourceLevel(resources){
            if (!resources || Object.keys(resources).length === 0) {
              resourceLevelEl.textContent = 'Recursos: esperando datos';
              resourceLevelEl.style.background = '#eef2ff';
              resourceLevelEl.style.color = '#4338ca';
              return;
            }
            const level = resources.resource_level || 'normal';
            const labels = {
              normal: 'Recursos: normales',
              warning: 'Recursos: moderados',
              high: 'Recursos: altos'
            };
            const palette = {
              normal: ['#ecfdf5','#166534'],
              warning: ['#fff7ed','#9a3412'],
              high: ['#fee2e2','#991b1b']
            };
            const selected = palette[level] || palette.normal;
            resourceLevelEl.textContent = labels[level] || labels.normal;
            resourceLevelEl.style.background = selected[0];
            resourceLevelEl.style.color = selected[1];
          }

          function isTerminalState(data){
            if (!data) return false;
            if (parseInt(data.terminal || 0, 10) === 1) return true;
            return !!data.done || ['done','cancelled','stopped','error'].indexOf(data.status) !== -1;
          }

          function render(data){
            const offset  = parseInt(data.offset || 0, 10);
            const created = parseInt(data.created_count || 0, 10);
            const updated = parseInt(data.updated_existing || 0, 10);
            const skipped = parseInt(data.skipped_dup || 0, 10);
            const errors  = parseInt(data.error_count || 0, 10);
            const percent = totalRows > 0 ? Math.min(100, Math.round((offset / totalRows) * 100)) : 0;
            const resources = data.resources || {};
            executionMode = data.execution_mode || executionMode || 'browser';
            taskId = data.task_id || '';

            const backgroundRunning = executionMode === 'background' && data.status === 'running';
            backgroundStateEl.textContent = backgroundRunning ? 'Modo: segundo plano activo' : (executionMode === 'background' ? 'Modo: segundo plano pausado' : 'Modo: ventana');
            backgroundStateEl.style.background = backgroundRunning ? '#e0f2fe' : '#f6f7f7';
            backgroundStateEl.style.color = backgroundRunning ? '#075985' : '#50575e';

            btnStart.style.display = backgroundRunning ? 'none' : '';
            btnBackground.style.display = ['done','cancelled'].indexOf(data.status) !== -1 ? 'none' : '';
            btnBackground.textContent = executionMode === 'background' && data.status !== 'running' ? 'Continuar en Cola y Tareas' : (backgroundRunning ? 'Cola y Tareas activa' : 'Enviar a Cola y Tareas');
            btnBackground.disabled = backgroundRunning;
            btnStop.style.display = ['running'].indexOf(data.status) !== -1 ? '' : 'none';
            btnCancel.style.display = ['done','cancelled'].indexOf(data.status) !== -1 ? 'none' : '';
            if (['done','cancelled'].indexOf(data.status) === -1) {
              btnCancel.disabled = false;
              btnCancel.textContent = 'Cancelar proceso';
            }

            offsetEl.textContent  = offset;
            createdEl.textContent = created;
            updatedEl.textContent = updated;
            skippedEl.textContent = skipped;
            errorsEl.textContent = errors;
            if (data.queue_task_url) {
              queueTaskLink.href = data.queue_task_url;
              queueTaskWrap.style.display = '';
            } else {
              queueTaskLink.href = '#';
              queueTaskWrap.style.display = 'none';
            }
            txt.textContent       = percent + '%';
            bar.style.width       = percent + '%';
            badgeEl.textContent   = data.status || 'ready';

            effectiveBatchEl.textContent = (resources.processed_rows || 0) + ' / ' + (data.effective_batch || data.configured_batch || '—');
            rateEl.textContent = resources.rows_per_second ? Number(resources.rows_per_second).toFixed(2) + ' tickets/s' : '—';
            batchTimeEl.textContent = resources.elapsed_ms ? (Number(resources.elapsed_ms) / 1000).toFixed(2) + ' s' : '—';
            etaEl.textContent = formatDuration(data.eta_seconds || 0);

            if (resources.memory_usage !== undefined) {
              const memoryPercent = Number(resources.memory_percent || 0).toFixed(1);
              const peakPercent = Number(resources.memory_peak_percent || 0).toFixed(1);
              const memoryLimitLabel = Number(resources.memory_limit || 0) > 0 ? formatBytes(resources.memory_limit) : 'sin límite';
              memoryEl.textContent = 'Actual ' + formatBytes(resources.memory_usage) + ' · pico ' + formatBytes(resources.memory_peak) + ' / ' + memoryLimitLabel + ' (' + memoryPercent + '% / ' + peakPercent + '%)';
            } else {
              memoryEl.textContent = '—';
            }

            cpuEl.textContent = resources.cpu_ms !== null && resources.cpu_ms !== undefined
              ? Number(resources.cpu_ms).toFixed(0) + ' ms'
              : 'No disponible';
            queriesEl.textContent = resources.db_queries !== undefined ? String(resources.db_queries) : '—';

            const loadParts = [resources.load_1, resources.load_5, resources.load_15].filter(function(value){
              return value !== null && value !== undefined;
            });
            loadEl.textContent = loadParts.length ? loadParts.join(' / ') : 'No disponible';
            renderResourceLevel(resources);

            if (Array.isArray(data.runtime_log)) {
              logBox.innerHTML = '';
              data.runtime_log.forEach(function(row){
                addLog('[' + row.time + '] ' + row.message);
              });
            }

            if (data.status === 'done') {
              bar.style.background = '#00a32a';
              setConnectionState('normal', 'Continuidad: completada');
            } else if (data.status === 'cancelled' || data.status === 'error') {
              bar.style.background = '#d63638';
              setConnectionState('error', data.status === 'cancelled' ? 'Continuidad: cancelada' : 'Continuidad: requiere revisión');
            } else if (data.status === 'stopped') {
              bar.style.background = '#dba617';
              setConnectionState('warning', 'Continuidad: detenida manualmente');
            } else {
              bar.style.background = '#2271b1';
              if (backgroundRunning) setConnectionState('normal', 'Continuidad: servidor en segundo plano');
              else if (autoRun) setConnectionState('normal', 'Continuidad: automática activa');
            }
          }

          function clearNextTick(){
            if (nextTickTimer) {
              window.clearTimeout(nextTickTimer);
              nextTickTimer = null;
            }
          }

          function scheduleNext(delay){
            if (!autoRun || manualStopRequested || isNaN(delay)) return;
            clearNextTick();
            nextTickTimer = window.setTimeout(function(){
              nextTickTimer = null;
              tick();
            }, Math.max(50, parseInt(delay || 0, 10)));
          }

          function retryDelay(attempt){
            const exponent = Math.min(Math.max(attempt - 1, 0), 5);
            return Math.min(15000, 750 * Math.pow(2, exponent));
          }

          function normalizeErrorMessage(json, response){
            if (json && typeof json.data === 'string' && json.data) return json.data;
            if (json && json.data && json.data.message) return json.data.message;
            if (json && json.message) return json.message;
            return 'HTTP ' + response.status + ': ' + response.statusText;
          }

          async function request(action){
            const fd = new FormData();
            fd.append('action', action);
            fd.append('event_id', eventId);
            fd.append('hash', hash);
            fd.append('_wpnonce', nonce);
            if (taskId) fd.append('task_id', taskId);

            let response;
            try {
              response = await fetch(ajaxurl, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                cache: 'no-store'
              });
            } catch (networkError) {
              networkError.retryable = true;
              networkError.httpStatus = 0;
              throw networkError;
            }

            const rawBody = await response.text();
            let json = null;
            try {
              json = rawBody ? JSON.parse(rawBody) : null;
            } catch (parseError) {
              const invalidResponse = new Error('El servidor devolvió una respuesta incompleta o no válida.');
              invalidResponse.httpStatus = response.status;
              invalidResponse.retryable = response.status === 0 || response.status >= 500 || response.ok;
              throw invalidResponse;
            }

            if (!response.ok || !json || !json.success) {
              const requestError = new Error(normalizeErrorMessage(json, response));
              requestError.httpStatus = response.status;
              requestError.retryable = response.status === 0 || response.status === 408 || response.status === 425 || response.status === 429 || response.status >= 500;
              throw requestError;
            }

            return json.data || {};
          }

          async function refreshStatus(allowAutoResume){
            if (statusBusy) return null;
            statusBusy = true;

            try {
              const data = await request('eventosapp_import_status');
              render(data);

              if (isTerminalState(data)) {
                autoRun = false;
                clearNextTick();
              } else if ((data.execution_mode || executionMode) === 'background') {
                autoRun = false;
                clearNextTick();
                setConnectionState('normal', 'Continuidad: servidor en segundo plano');
              } else if (allowAutoResume !== false && parseInt(data.should_continue || 0, 10) === 1 && !manualStopRequested) {
                if (!autoRun) {
                  autoRun = true;
                  setConnectionState('normal', 'Continuidad: recuperada automáticamente');
                }
                if (!busy && !nextTickTimer) scheduleNext(100);
              }

              return data;
            } catch (e) {
              addThrottledStatusError('No se pudo consultar el estado; se volverá a intentar: ' + e.message);
              return null;
            } finally {
              statusBusy = false;
            }
          }

          async function tick(){
            if (busy || !autoRun || manualStopRequested) return;

            if (navigator.onLine === false) {
              setConnectionState('warning', 'Continuidad: sin conexión, reintentando');
              scheduleNext(2000);
              return;
            }

            busy = true;
            btnStart.disabled = true;
            let nextDelay = 75;

            try {
              const data = await request('eventosapp_import_process');
              render(data);

              if (data.busy) {
                nextDelay = 750;
                setConnectionState('warning', 'Continuidad: esperando lote activo');
              } else {
                consecutiveErrors = 0;
                setConnectionState('normal', 'Continuidad: automática activa');
              }

              if (isTerminalState(data)) {
                autoRun = false;
                clearNextTick();
              }
            } catch (e) {
              consecutiveErrors++;

              if (e.retryable !== false && !manualStopRequested) {
                nextDelay = retryDelay(consecutiveErrors);
                autoRun = true;
                setConnectionState('warning', 'Continuidad: reintento ' + consecutiveErrors + ' en ' + Math.ceil(nextDelay / 1000) + ' s');
                addLog('[' + new Date().toLocaleTimeString() + '] Aviso transitorio: ' + e.message + '. El proceso seguirá automáticamente.');
                await refreshStatus(false);
              } else {
                autoRun = false;
                clearNextTick();
                addLog('[' + new Date().toLocaleTimeString() + '] ERROR no recuperable: ' + e.message);
                badgeEl.textContent = 'error';
                bar.style.background = '#d63638';
                setConnectionState('error', 'Continuidad: requiere revisión');
              }
            } finally {
              busy = false;
              btnStart.disabled = false;
              if (autoRun && !manualStopRequested) scheduleNext(nextDelay);
            }
          }

          async function startOrResume(){
            manualStopRequested = false;
            autoRun = true;
            consecutiveErrors = 0;
            clearNextTick();
            setConnectionState('normal', 'Continuidad: iniciando');

            try {
              const resumed = await request('eventosapp_import_resume');
              render(resumed);
              scheduleNext(50);
            } catch (e) {
              if (e.retryable !== false) {
                const delay = retryDelay(1);
                setConnectionState('warning', 'Continuidad: reintentando inicio');
                addLog('[' + new Date().toLocaleTimeString() + '] No se confirmó el inicio: ' + e.message + '. Se verificará y reintentará automáticamente.');
                const currentState = await refreshStatus(false);

                if (currentState && parseInt(currentState.should_continue || 0, 10) === 1) {
                  autoRun = true;
                  scheduleNext(delay);
                } else if (!manualStopRequested) {
                  clearNextTick();
                  nextTickTimer = window.setTimeout(function(){
                    nextTickTimer = null;
                    if (!manualStopRequested) startOrResume();
                  }, delay);
                }
              } else {
                autoRun = false;
                setConnectionState('error', 'Continuidad: no pudo iniciar');
                addLog('[' + new Date().toLocaleTimeString() + '] ERROR al iniciar/reanudar: ' + e.message);
              }
            }
          }

          btnStart.addEventListener('click', function(){
            startOrResume();
          });

          btnBackground.addEventListener('click', async function(){
            manualStopRequested = true;
            autoRun = false;
            clearNextTick();
            btnBackground.disabled = true;
            setConnectionState('warning', 'Continuidad: enviando al servidor');
            try {
              const data = await request('eventosapp_import_background');
              render(data);
              setConnectionState('normal', 'Continuidad: servidor en segundo plano');
              addLog('[' + new Date().toLocaleTimeString() + '] La importación quedó registrada en Cola y Tareas. Puedes cerrar esta página.');
            } catch (e) {
              btnBackground.disabled = false;
              manualStopRequested = false;
              setConnectionState('error', 'Continuidad: no se pudo activar segundo plano');
              addLog('[' + new Date().toLocaleTimeString() + '] ERROR al enviar a segundo plano: ' + e.message);
            }
          });

          btnStop.addEventListener('click', async function(){
            manualStopRequested = true;
            autoRun = false;
            clearNextTick();

            try {
              const data = await request('eventosapp_import_stop');
              render(data);
            } catch (e) {
              addLog('[' + new Date().toLocaleTimeString() + '] ERROR al detener: ' + e.message);
              setConnectionState('error', 'Continuidad: no se confirmó la detención');
            }
          });

          btnCancel.addEventListener('click', async function(){
            if (!window.confirm('Esto cancelará definitivamente la ejecución actual. Los tickets ya creados se conservan.')) {
              return;
            }

            manualStopRequested = true;
            autoRun = false;
            clearNextTick();
            btnCancel.disabled = true;
            const previousCancelText = btnCancel.textContent;
            btnCancel.textContent = 'Cancelando…';
            setConnectionState('warning', 'Continuidad: registrando cancelación');

            try {
              const data = await request('eventosapp_import_cancel');
              render(data);
              setConnectionState('error', 'Continuidad: cancelada');
              addLog('[' + new Date().toLocaleTimeString() + '] Cancelación confirmada. El servidor no ejecutará más lotes.');
            } catch (e) {
              btnCancel.disabled = false;
              btnCancel.textContent = previousCancelText;
              addLog('[' + new Date().toLocaleTimeString() + '] ERROR al cancelar: ' + e.message);
              setConnectionState('error', 'Continuidad: no se confirmó la cancelación');
            }
          });

          window.addEventListener('offline', function(){
            if (autoRun && !manualStopRequested) {
              setConnectionState('warning', 'Continuidad: esperando conexión');
            }
          });

          window.addEventListener('online', function(){
            if (autoRun && !manualStopRequested) {
              consecutiveErrors = 0;
              setConnectionState('normal', 'Continuidad: conexión recuperada');
              scheduleNext(100);
            }
          });

          document.addEventListener('visibilitychange', function(){
            if (!document.hidden) {
              refreshStatus(true);
              if (autoRun && !manualStopRequested) scheduleNext(100);
            }
          });

          window.setInterval(function(){
            refreshStatus(true);
          }, 2500);

          // En modo ventana recupera el bucle AJAX. En segundo plano solo observa el worker del servidor.
          refreshStatus(true);
        })();
        </script>
        <?php
        echo '</div>';
    });
});

add_action('wp_ajax_eventosapp_import_status', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('No autorizado', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $task_id  = sanitize_text_field($_POST['task_id'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';

    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) {
        wp_send_json_error('Solicitud inválida', 400);
    }

    $key = evapp_import_background_resolve_state_key($event_id, $hash, $task_id);
    $state = get_option($key);
    if (!is_array($state)) {
        wp_send_json_error('Estado no encontrado', 404);
    }

    evapp_import_control_reconcile_state($key, $state, true);
    if (!empty($state['queue_task_id'])) {
        evapp_import_sync_from_task_queue($key, $state, true);
    }

    if (($state['execution_mode'] ?? '') === 'background' && ($state['status'] ?? '') === 'running' && ($state['background_backend'] ?? '') !== 'task_queue') {
        evapp_import_background_schedule($state, 60);
    }
    wp_send_json_success(evapp_import_public_state($state));
});

add_action('wp_ajax_eventosapp_import_resume', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('No autorizado', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $task_id  = sanitize_text_field($_POST['task_id'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';

    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) {
        wp_send_json_error('Solicitud inválida', 400);
    }

    $key   = evapp_import_background_resolve_state_key($event_id, $hash, $task_id);
    $state = get_option($key);
    if (!is_array($state)) {
        wp_send_json_error('Estado no encontrado', 404);
    }

    $queue_task_id = absint($state['queue_task_id'] ?? 0);
    if ($queue_task_id && function_exists('eventosapp_task_queue_get')) {
        $queue_task = eventosapp_task_queue_get($queue_task_id);
        if (is_array($queue_task) && in_array($queue_task['status'], ['queued','scheduled','running'], true)) {
            wp_send_json_error('La tarea sigue activa en Cola y Tareas. Páusala o cancélala antes de continuar en esta ventana.', 409);
        }
        if (is_array($queue_task) && $queue_task['status'] === 'paused' && function_exists('eventosapp_task_queue_cancel')) {
            eventosapp_task_queue_cancel($queue_task_id, 'Continuación transferida nuevamente al modo ventana desde Herramientas.');
        }
        $state['queue_task_id'] = 0;
        $state['queue_status'] = '';
        $state['background_backend'] = '';
    }

    if (!empty($state['background_task_id'])) evapp_import_background_unschedule($state['background_task_id']);
    $control = evapp_import_control_set($key, 'browser', '');
    $state['control_revision_applied'] = (int) ($control['revision'] ?? 0);
    $state['execution_mode'] = 'browser';
    $state['status']    = 'running';
    $state['stopped']   = 0;
    $state['cancelled'] = 0;
    $state['updated_at'] = time();
    if (empty($state['started_at'])) {
        $state['started_at'] = current_time('mysql');
    }
    if (empty($state['started_at_ts'])) {
        $state['started_at_ts'] = time();
    }
    $state = evapp_import_merge_runtime_logs($state, [evapp_import_log_entry('Proceso reanudado.')]);
    update_option($key, $state, false);

    wp_send_json_success(evapp_import_public_state($state));
});

add_action('wp_ajax_eventosapp_import_stop', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('No autorizado', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $task_id  = sanitize_text_field($_POST['task_id'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';

    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) {
        wp_send_json_error('Solicitud inválida', 400);
    }

    $key   = evapp_import_background_resolve_state_key($event_id, $hash, $task_id);
    $state = get_option($key);
    if (!is_array($state)) {
        wp_send_json_error('Estado no encontrado', 404);
    }

    $queue_task_id = absint($state['queue_task_id'] ?? 0);
    if ($queue_task_id && function_exists('eventosapp_task_queue_pause')) {
        eventosapp_task_queue_pause($queue_task_id);
        $latest_state = get_option($key);
        if (is_array($latest_state)) $state = $latest_state;
    }
    $control = evapp_import_control_set($key, 'pause', $queue_task_id ?: ($state['background_task_id'] ?? $task_id));
    $state['control_revision_applied'] = (int) ($control['revision'] ?? 0);
    $state['status']  = 'stopped';
    $state['stopped'] = 1;
    $state['updated_at'] = time();
    if (!empty($state['background_task_id'])) evapp_import_background_unschedule($state['background_task_id']);
    $state = evapp_import_merge_runtime_logs($state, [evapp_import_log_entry('Proceso detenido por el usuario.')]);
    update_option($key, $state, false);
    evapp_import_background_touch($key, $state);

    wp_send_json_success(evapp_import_public_state($state));
});

add_action('wp_ajax_eventosapp_import_cancel', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('No autorizado', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $task_id  = sanitize_text_field($_POST['task_id'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';

    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) {
        wp_send_json_error('Solicitud inválida', 400);
    }

    $key   = evapp_import_background_resolve_state_key($event_id, $hash, $task_id);
    $state = get_option($key);
    if (!is_array($state)) {
        wp_send_json_error('Estado no encontrado', 404);
    }

    // La orden se guarda primero en una opción independiente. Así permanece vigente
    // aunque un worker activo termine su lote con una copia anterior del estado.
    $queue_task_id = absint($state['queue_task_id'] ?? 0);
    if ($queue_task_id && function_exists('eventosapp_task_queue_cancel')) {
        eventosapp_task_queue_cancel($queue_task_id, 'Cancelada desde Herramientas. Los tickets ya procesados se conservan.');
        $latest_state = get_option($key);
        if (is_array($latest_state)) $state = $latest_state;
    }
    $control = evapp_import_control_set($key, 'cancel', $queue_task_id ?: ($state['background_task_id'] ?? $task_id));
    $state['control_revision_applied'] = (int) ($control['revision'] ?? 0);
    $state['status']    = 'cancelled';
    $state['cancelled'] = 1;
    $state['stopped']   = 1;
    $state['done']      = 0;
    $state['updated_at'] = time();
    $state['finished_at_ts'] = time();
    $state['background_token'] = '';
    if (!empty($state['background_task_id'])) evapp_import_background_unschedule($state['background_task_id']);
    $state = evapp_import_merge_runtime_logs($state, [evapp_import_log_entry('Proceso cancelado por el usuario. El progreso queda registrado para evitar duplicados.')]);
    update_option($key, $state, false);
    evapp_import_background_touch($key, $state);

    wp_send_json_success(evapp_import_public_state($state));
});
/**
 * Activa o reanuda el procesamiento persistente del servidor.
 */
add_action('wp_ajax_eventosapp_import_background', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('No autorizado', 403);

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $task_id  = sanitize_text_field($_POST['task_id'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';
    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) wp_send_json_error('Solicitud inválida', 400);

    $key = evapp_import_background_resolve_state_key($event_id, $hash, $task_id);
    $state = get_option($key);
    if (!is_array($state)) wp_send_json_error('Estado no encontrado', 404);
    if (!empty($state['done']) || in_array(($state['status'] ?? ''), ['done','cancelled'], true)) wp_send_json_error('La importación ya finalizó o fue cancelada.', 409);

    $user = wp_get_current_user();
    $recipient = is_email($user->user_email) ? sanitize_email($user->user_email) : sanitize_email(get_option('admin_email'));
    $state['owner_user_id'] = (int) get_current_user_id();
    $state['notification_email'] = $recipient;
    $state['status'] = 'running';
    $state['stopped'] = 0;
    $state['cancelled'] = 0;
    $state['last_error'] = '';
    $state['updated_at'] = time();
    if (empty($state['started_at'])) $state['started_at'] = current_time('mysql');
    if (empty($state['started_at_ts'])) $state['started_at_ts'] = time();
    if (!is_array($state['asset_config'] ?? null)) $state['asset_config'] = evapp_import_event_asset_config($event_id);

    // Implementación principal: usa la cola central para compartir límites de recursos,
    // locks, controles y monitoreo con el resto de trabajos masivos de EventosApp.
    if (function_exists('eventosapp_task_queue_create') && function_exists('eventosapp_task_queue_get')) {
        $queue_task_id = evapp_import_create_task_queue_job($key, $state);
        if (is_wp_error($queue_task_id)) {
            wp_send_json_error($queue_task_id->get_error_message(), 500);
        }

        $control = evapp_import_control_set($key, 'background', $queue_task_id);
        $state['control_revision_applied'] = (int) ($control['revision'] ?? 0);
        $state['queue_task_id'] = absint($queue_task_id);
        $state['execution_mode'] = 'background';
        $state['background_backend'] = 'task_queue';
        $state = evapp_import_merge_runtime_logs($state, [
            evapp_import_log_entry('Importación enviada a Cola y Tareas. La cola central controlará recursos, concurrencia, pausa, reanudación y cancelación.'),
        ]);
        update_option($key, $state, false);
        if (function_exists('eventosapp_task_queue_kick')) eventosapp_task_queue_kick();

        wp_send_json_success(evapp_import_public_state($state, [
            'task_id' => absint($queue_task_id),
            'queue_task_id' => absint($queue_task_id),
        ]));
    }

    // Fallback conservado para instalaciones antiguas donde todavía no esté cargada la cola central.
    $state['execution_mode'] = 'background';
    $state['background_backend'] = 'legacy';
    $state['error_notification_sent'] = 0;
    $state['background_token'] = !empty($state['background_token']) ? (string) $state['background_token'] : wp_generate_password(64, false, false);
    $new_task_id = evapp_import_background_register($key, $state);
    $control = evapp_import_control_set($key, 'background', $new_task_id);
    $state['control_revision_applied'] = (int) ($control['revision'] ?? 0);
    $state = evapp_import_merge_runtime_logs($state, [evapp_import_log_entry('Aviso: Cola y Tareas no estaba disponible; se activó el worker heredado de compatibilidad.')]);
    update_option($key, $state, false);
    evapp_import_background_schedule($state, 60);
    evapp_import_background_dispatch($state);
    wp_send_json_success(evapp_import_public_state($state, ['task_id'=>$new_task_id]));
});


/**
 * Endpoint interno firmado. El worker adopta al propietario para reutilizar permisos y nonces,
 * pero la autenticación real es el token aleatorio guardado en el estado.
 */
function evapp_import_background_worker_handler(){
    $task_id = sanitize_text_field($_POST['task_id'] ?? '');
    $token = (string) ($_POST['token'] ?? '');
    $entry = evapp_import_background_get_entry($task_id);
    if (!is_array($entry) || empty($entry['state_key'])) wp_send_json_error('Tarea no encontrada', 404);
    $state = get_option($entry['state_key']);
    if (!is_array($state)) wp_send_json_error('Estado no encontrado', 404);
    // Validar el secreto antes de devolver cualquier dato de la tarea.
    if (empty($state['background_token']) || !hash_equals((string) $state['background_token'], $token)) wp_send_json_error('Token inválido', 403);
    evapp_import_control_reconcile_state($entry['state_key'], $state, true);
    if (($state['execution_mode'] ?? '') !== 'background' || ($state['status'] ?? '') !== 'running') wp_send_json_success(evapp_import_public_state($state));

    $owner_user_id = (int) ($state['owner_user_id'] ?? $entry['owner_user_id'] ?? 0);
    if (!$owner_user_id || !user_can($owner_user_id, 'manage_options')) {
        evapp_import_background_mark_error($entry['state_key'], $state, 'El usuario propietario del proceso ya no tiene permisos administrativos.');
        wp_send_json_error('Propietario sin permisos', 403);
    }
    wp_set_current_user($owner_user_id);

    register_shutdown_function(function() use ($entry){
        $last = error_get_last();
        if (!$last || !in_array((int) ($last['type'] ?? 0), [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)) return;
        $state = get_option($entry['state_key']);
        if (!is_array($state) || ($state['execution_mode'] ?? '') !== 'background' || ($state['status'] ?? '') !== 'running') return;
        $message = 'Error fatal: '.($last['message'] ?? 'desconocido').' en '.basename((string) ($last['file'] ?? '')).':'.(int) ($last['line'] ?? 0);
        evapp_import_background_mark_error($entry['state_key'], $state, $message);
    });

    $_POST['event_id'] = (int) ($state['event_id'] ?? 0);
    $_POST['hash'] = (string) ($state['file_hash'] ?? '');
    $_POST['task_id'] = $task_id;
    $_POST['_wpnonce'] = wp_create_nonce('evapp_tools_'.(int) $_POST['event_id']);
    $_REQUEST = array_merge($_REQUEST, $_POST);
    do_action('wp_ajax_eventosapp_import_process');
    wp_send_json_error('El procesador no respondió', 500);
}
add_action('wp_ajax_eventosapp_import_background_worker', 'evapp_import_background_worker_handler');
add_action('wp_ajax_nopriv_eventosapp_import_background_worker', 'evapp_import_background_worker_handler');

/**
 * Mantiene coherente la caché del helper global de deduplicación por cédula.
 * Evita que dos filas con la misma identificación creen dos tickets dentro del
 * mismo lote después de que una búsqueda negativa hubiera quedado cacheada.
 */
function evapp_import_cache_ticket_by_cc($event_id, $cc, $ticket_id){
    $event_id = absint($event_id);
    $ticket_id = absint($ticket_id);
    $cc = sanitize_text_field((string) $cc);
    if (!$event_id || !$ticket_id || $cc === '') return;
    wp_cache_set('evapp_ticket_cc_event_'.md5($event_id.'|'.$cc), $ticket_id, 'eventosapp_tickets', 60);
}

function evapp_import_payload_from_row($data, $event_id){
    $data = is_array($data) ? $data : [];
    $email = sanitize_email($data['email'] ?? '');
    $cc = sanitize_text_field($data['cc'] ?? '');
    $external_id = $data['external_id'] ?? '';
    $finger = $external_id
        ? 'ext:'.sanitize_text_field($external_id)
        : 'fp:'.md5(strtolower($email.'|'.$cc.'|'.($data['nombre'] ?? '').'|'.($data['apellido'] ?? '').'|'.absint($event_id)));

    $payload = [
        'first_name'  => $data['nombre'] ?? '',
        'last_name'   => $data['apellido'] ?? '',
        'email'       => $email,
        'tel'         => $data['telefono'] ?? '',
        'empresa'     => $data['empresa'] ?? '',
        'nit'         => $data['nit'] ?? '',
        'cargo'       => $data['cargo'] ?? '',
        'cc'          => $cc,
        'ciudad'      => $data['ciudad'] ?? '',
        'pais'        => $data['pais'] ?? 'Colombia',
        'localidad'   => $data['localidad'] ?? '',
        'modalidad'   => $data['modalidad'] ?? '',
        'extras'      => [],
        'fingerprint' => $finger,
    ];

    foreach ($data as $data_key => $data_value) {
        if (strpos($data_key, 'extra__') === 0) {
            $payload['extras'][substr($data_key, 7)] = $data_value;
        }
    }
    return $payload;
}

function evapp_import_update_ticket_from_payload($ticket_id, $event_id, $payload, $asset_config){
    $ticket_id = absint($ticket_id);
    $event_id = absint($event_id);
    if (!$ticket_id || !$event_id) return false;

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
    update_post_meta($ticket_id, '_eventosapp_import_fingerprint', (string) ($payload['fingerprint'] ?? ''));

    if (function_exists('eventosapp_ticket_build_search_blob')) {
        eventosapp_ticket_build_search_blob($ticket_id);
    }
    if (!empty($asset_config['link_assistant']) && function_exists('evapp_process_vincular_asistente')) {
        evapp_process_vincular_asistente($ticket_id);
    }

    $asset_report = evapp_import_generate_assets_now($ticket_id, $event_id, $asset_config);
    evapp_import_cache_ticket_by_cc($event_id, $payload['cc'] ?? '', $ticket_id);
    return is_array($asset_report) ? $asset_report : ['ok'=>false,'errors'=>['El generador de anexos devolvió una respuesta inválida.']];
}

/**
 * Procesa una fila ya mapeada. Este es el único punto usado tanto por el modo
 * ventana como por el adaptador de Cola y Tareas, evitando comportamientos distintos.
 */
function evapp_import_process_row_data($event_id, $data, $line, $asset_config){
    global $wpdb;
    $event_id = absint($event_id);
    $data = is_array($data) ? $data : [];
    $line = absint($line);
    $asset_config = is_array($asset_config) ? $asset_config : evapp_import_event_asset_config($event_id);

    $nombre    = trim((string) ($data['nombre'] ?? ''));
    $apellido  = trim((string) ($data['apellido'] ?? ''));
    $email     = sanitize_email($data['email'] ?? '');
    $cc        = sanitize_text_field($data['cc'] ?? '');
    $localidad = trim((string) ($data['localidad'] ?? ''));

    $result = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors'  => 0,
        'ticket_id' => 0,
        'log' => null,
    ];

    if (!$nombre || !$apellido || (!$email && !$cc) || !$localidad) {
        $result['skipped'] = 1;
        $result['log'] = evapp_import_log_entry('L'.$line.': fila omitida por datos mínimos incompletos.');
        return $result;
    }

    $payload = evapp_import_payload_from_row($data, $event_id);
    $finger = (string) ($payload['fingerprint'] ?? '');

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
        $result['skipped'] = 1;
        $result['ticket_id'] = absint($duplicate_id);
        $result['log'] = evapp_import_log_entry('L'.$line.': fingerprint duplicado, fila omitida.');
        return $result;
    }

    $existing_ticket_id = false;
    if ($cc && function_exists('evapp_find_ticket_by_cedula_evento')) {
        $existing_ticket_id = evapp_find_ticket_by_cedula_evento($cc, $event_id);
    }

    if ($existing_ticket_id) {
        $ticket_id = absint($existing_ticket_id);
        $asset_report = evapp_import_update_ticket_from_payload($ticket_id, $event_id, $payload, $asset_config);
        if (is_array($asset_report)) {
            $result['updated'] = 1;
            $result['ticket_id'] = $ticket_id;
            if (!empty($asset_report['ok'])) {
                $result['log'] = evapp_import_log_entry('L'.$line.': ticket '.$ticket_id.' actualizado; QR válidos conservados y anexos activos sincronizados.');
            } else {
                $result['errors'] = 1;
                $result['log'] = evapp_import_log_entry('L'.$line.': ticket '.$ticket_id.' actualizado, pero requiere reparar anexos: '.implode(' | ', (array) ($asset_report['errors'] ?? [])).'.');
            }
        } else {
            $result['errors'] = 1;
            $result['log'] = evapp_import_log_entry('L'.$line.': no se pudo actualizar el ticket '.$ticket_id.'.');
        }
        return $result;
    }

    $new_id = eventosapp_create_ticket_programmatically($event_id, $payload, 'import', false, $asset_config);
    if ($new_id) {
        update_post_meta($new_id, '_eventosapp_import_fingerprint', $finger);
        if (!empty($asset_config['link_assistant']) && function_exists('evapp_process_vincular_asistente')) {
            evapp_process_vincular_asistente($new_id);
        }
        evapp_import_cache_ticket_by_cc($event_id, $cc, $new_id);
        $result['created'] = 1;
        $result['ticket_id'] = absint($new_id);
        $asset_report = get_post_meta($new_id, '_eventosapp_import_asset_report', true);
        if (is_array($asset_report) && empty($asset_report['ok'])) {
            $result['errors'] = 1;
            $result['log'] = evapp_import_log_entry('L'.$line.': ticket '.$new_id.' creado, pero requiere reparar anexos: '.implode(' | ', (array) ($asset_report['errors'] ?? [])).'.');
        } else {
            $result['log'] = evapp_import_log_entry('L'.$line.': ticket '.$new_id.' creado con los anexos activos del evento.');
        }
    } else {
        $result['errors'] = 1;
        $result['log'] = evapp_import_log_entry('L'.$line.': no se pudo crear el ticket. Revisa el log de PHP.');
    }

    return $result;
}

/**
 * Procesador utilizado por el adaptador ticket_import de la cola central.
 */
function evapp_import_task_queue_process_batch($task, $runtime){
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    $state_key = (string) ($payload['state_key'] ?? '');
    $state = $state_key !== '' ? get_option($state_key) : null;

    if (!is_array($state)) {
        return [
            'processed'=>0, 'success'=>0, 'errors'=>1, 'skipped'=>0,
            'next_cursor'=>absint($task['cursor_value'] ?? 0),
            'total_items'=>absint($task['total_items'] ?? 0),
            'done'=>false, 'fatal'=>true,
            'error_message'=>'El estado persistente de la importación no existe.',
        ];
    }

    $event_id = absint($state['event_id'] ?? $payload['event_id'] ?? 0);
    $hash = (string) ($state['file_hash'] ?? $payload['file_hash'] ?? '');
    $cursor = absint($task['cursor_value'] ?? 0);
    $total = absint($state['total_rows'] ?? $task['total_items'] ?? 0);
    $batch_size = max(1, absint($runtime['batch_size'] ?? 1));
    $started = isset($runtime['started_at']) ? (float) $runtime['started_at'] : microtime(true);
    $asset_config = is_array($state['asset_config'] ?? null)
        ? $state['asset_config']
        : (is_array($payload['asset_config'] ?? null) ? $payload['asset_config'] : evapp_import_event_asset_config($event_id));

    if (!$event_id || !$hash || empty($state['file']) || !file_exists($state['file'])) {
        return [
            'processed'=>0, 'success'=>0, 'errors'=>1, 'skipped'=>0,
            'next_cursor'=>$cursor, 'total_items'=>$total, 'done'=>false, 'fatal'=>true,
            'error_message'=>'El archivo CSV o los datos del evento ya no están disponibles.',
        ];
    }

    $lock_token = evapp_import_acquire_lock($event_id, $hash, 180, $state_key);
    if (!$lock_token) {
        return [
            'processed'=>0, 'success'=>0, 'errors'=>0, 'skipped'=>0,
            'next_cursor'=>$cursor, 'total_items'=>$total, 'done'=>false,
            'logs'=>[['level'=>'warning','message'=>'La cola esperó porque otro lote de esta importación aún conserva el bloqueo.']],
        ];
    }

    try {
        evapp_import_prepare_event_assets_once($event_id, $state);
        $state['queue_task_id'] = absint($task['id'] ?? 0);
        $state['execution_mode'] = 'background';
        $state['background_backend'] = 'task_queue';
        $state['status'] = 'running';
        $state['asset_config'] = $asset_config;
        update_option($state_key, $state, false);

        $fh = @fopen($state['file'], 'r');
        if (!$fh) throw new RuntimeException('No se pudo abrir el archivo CSV.');

        $can_seek = !empty($state['csv_byte_offset']) && absint($state['offset'] ?? 0) === $cursor;
        if ($can_seek) {
            fseek($fh, (int) $state['csv_byte_offset']);
            $line = $cursor + 1;
        } else {
            fgetcsv($fh);
            $line = 1;
            $skip = 0;
            while ($skip < $cursor && fgetcsv($fh) !== false) {
                $skip++;
                $line++;
            }
        }

        $map = is_array($state['map'] ?? null) ? $state['map'] : [];
        $rev = [];
        foreach ($map as $i => $mapped_key) {
            if ($mapped_key) $rev[intval($i)] = $mapped_key;
        }

        $processed = $created = $updated = $errors = $skipped = $successful_rows = 0;
        $logs = [];
        $reached_eof = false;
        $last_byte_offset = ftell($fh);

        while ($processed < $batch_size) {
            if ($processed > 0 && is_callable($runtime['should_yield'] ?? null) && call_user_func($runtime['should_yield'], $started, $processed)) {
                $logs[] = ['level'=>'warning','message'=>'El lote cedió el turno preventivamente por tiempo, memoria o carga del servidor.'];
                break;
            }

            $fresh_task = function_exists('eventosapp_task_queue_get') ? eventosapp_task_queue_get(absint($task['id'] ?? 0)) : null;
            if (is_array($fresh_task) && ($fresh_task['status'] === 'paused' || in_array($fresh_task['status'], eventosapp_task_queue_terminal_statuses(), true))) {
                break;
            }

            $row = fgetcsv($fh);
            if ($row === false) {
                $reached_eof = true;
                break;
            }

            $line++;
            $processed++;
            $cursor++;
            $last_byte_offset = ftell($fh);

            $data = [];
            foreach ($rev as $i => $field) {
                $data[$field] = isset($row[$i]) ? trim((string) $row[$i]) : '';
            }

            $row_result = evapp_import_process_row_data($event_id, $data, $line, $asset_config);
            $created += absint($row_result['created'] ?? 0);
            $updated += absint($row_result['updated'] ?? 0);
            $row_errors = absint($row_result['errors'] ?? 0);
            $errors  += $row_errors;
            $skipped += absint($row_result['skipped'] ?? 0);
            if (!$row_errors && (absint($row_result['created'] ?? 0) || absint($row_result['updated'] ?? 0))) {
                $successful_rows++;
            }
            if (!empty($row_result['log'])) {
                $logs[] = ['level'=>!empty($row_result['errors']) ? 'error' : (!empty($row_result['skipped']) ? 'warning' : 'success'), 'message'=>$row_result['log']['message'] ?? 'Fila procesada.'];
            }
        }
        fclose($fh);

        $done = $reached_eof || ($total > 0 && $cursor >= $total);
        $state['offset'] = $cursor;
        $state['created_count'] = absint($state['created_count'] ?? 0) + $created;
        $state['updated_existing'] = absint($state['updated_existing'] ?? 0) + $updated;
        $state['skipped_dup'] = absint($state['skipped_dup'] ?? 0) + $skipped;
        $state['error_count'] = absint($state['error_count'] ?? 0) + $errors;
        $state['csv_byte_offset'] = max(0, (int) $last_byte_offset);
        $state['done'] = $done ? 1 : 0;
        $state['status'] = $done ? 'done' : 'running';
        $state['updated_at'] = time();
        if ($done) {
            $state['finished_at'] = current_time('mysql');
            $state['finished_at_ts'] = time();
        }
        $state = evapp_import_merge_runtime_logs($state, array_map(function($log){
            return evapp_import_log_entry($log['message'] ?? '');
        }, $logs));
        update_option($state_key, $state, false);

        return [
            'processed'   => $processed,
            'success'     => $successful_rows,
            'errors'      => $errors,
            'skipped'     => $skipped,
            'next_cursor' => $cursor,
            'total_items' => $total,
            'done'        => $done,
            'logs'        => $logs,
        ];
    } catch (Throwable $e) {
        $state['status'] = 'error';
        $state['last_error'] = $e->getMessage();
        $state['updated_at'] = time();
        update_option($state_key, $state, false);
        return [
            'processed'=>0, 'success'=>0, 'errors'=>1, 'skipped'=>0,
            'next_cursor'=>$cursor, 'total_items'=>$total, 'done'=>false, 'fatal'=>true,
            'error_message'=>$e->getMessage(),
            'logs'=>[['level'=>'error','message'=>'Importación: '.$e->getMessage()]],
        ];
    } finally {
        evapp_import_release_lock($event_id, $hash, $lock_token, $state_key);
    }
}

//
// === Procesar un lote adaptativo con medición de recursos ===
//
add_action('wp_ajax_eventosapp_import_process', function(){
    global $wpdb;

    $start_time      = microtime(true);
    $start_resources = evapp_import_resource_snapshot();
    $start_queries   = function_exists('get_num_queries') ? get_num_queries() : 0;

    if (!current_user_can('manage_options')) {
        wp_send_json_error('No autorizado', 403);
    }

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash     = sanitize_text_field($_POST['hash'] ?? '');
    $task_id  = sanitize_text_field($_POST['task_id'] ?? '');
    $nonce    = $_POST['_wpnonce'] ?? '';

    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) {
        wp_send_json_error('Solicitud inválida', 400);
    }

    $key   = evapp_import_background_resolve_state_key($event_id, $hash, $task_id);
    $state = get_option($key);
    if (!is_array($state)) {
        wp_send_json_error('Estado no encontrado', 404);
    }

    if (!empty($state['queue_task_id']) && ($state['background_backend'] ?? '') === 'task_queue') {
        $queue_task = evapp_import_sync_from_task_queue($key, $state, true);
        if (is_array($queue_task) && in_array($queue_task['status'], ['queued','scheduled','running'], true)) {
            wp_send_json_success(evapp_import_public_state($state, [
                'busy' => 1,
                'msg'  => 'La importación está siendo procesada por Cola y Tareas.',
            ]));
        }
    }

    $control_command = evapp_import_control_reconcile_state($key, $state, true);
    if ($control_command === 'cancel' || !empty($state['cancelled'])) {
        wp_send_json_success(evapp_import_public_state($state, ['msg'=>'Proceso cancelado.']));
    }
    if ($control_command === 'pause' || (!empty($state['stopped']) && ($state['status'] ?? '') !== 'running')) {
        wp_send_json_success(evapp_import_public_state($state, ['msg'=>'Proceso detenido.']));
    }

    $lock_token = evapp_import_acquire_lock($event_id, $hash, 120, $key);
    if (!$lock_token) {
        wp_send_json_success(evapp_import_public_state($state, [
            'busy' => 1,
            'msg'  => 'Otro lote de esta misma importación sigue en ejecución.',
        ]));
    }

    register_shutdown_function(function() use ($event_id, $hash, $lock_token, $key){
        evapp_import_release_lock($event_id, $hash, $lock_token, $key);
    });

    if (!empty($state['cancelled'])) {
        $state['status'] = 'cancelled';
        update_option($key, $state, false);
        wp_send_json_success(evapp_import_public_state($state, [
            'msg' => 'Proceso cancelado.',
        ]));
    }

    if (!empty($state['stopped']) && ($state['status'] ?? '') !== 'running') {
        $state['status'] = 'stopped';
        update_option($key, $state, false);
        wp_send_json_success(evapp_import_public_state($state, [
            'msg' => 'Proceso detenido.',
        ]));
    }

    $state['status'] = 'running';
    evapp_import_prepare_event_assets_once($event_id, $state);
    update_option($key, $state, false);

    $time_budget_ms = evapp_import_time_budget_ms();
    $effective_chunk = evapp_import_effective_batch_size($state, $time_budget_ms);
    $offset   = intval($state['offset'] ?? 0);
    $created  = intval($state['created_count'] ?? 0);
    $updated  = intval($state['updated_existing'] ?? 0);
    $skipped  = intval($state['skipped_dup'] ?? 0);
    $errors   = intval($state['error_count'] ?? 0);
    $asset_config = is_array($state['asset_config'] ?? null)
        ? $state['asset_config']
        : evapp_import_event_asset_config($event_id);

    $map = $state['map'] ?? [];
    $rev = [];
    foreach ($map as $i => $mapped_key) {
        if ($mapped_key) $rev[intval($i)] = $mapped_key;
    }

    if (empty($state['file']) || !file_exists($state['file'])) {
        $state['status']     = 'error';
        $state['last_error'] = 'El archivo CSV no existe.';
        if (($state['execution_mode'] ?? '') === 'background') evapp_import_background_mark_error($key, $state, $state['last_error']);
        else update_option($key, $state, false);
        wp_send_json_error('El archivo CSV no existe', 500);
    }

    $fh = @fopen($state['file'], 'r');
    if (!$fh) {
        $state['status']     = 'error';
        $state['last_error'] = 'No se pudo abrir el archivo CSV.';
        if (($state['execution_mode'] ?? '') === 'background') evapp_import_background_mark_error($key, $state, $state['last_error']);
        else update_option($key, $state, false);
        wp_send_json_error('No se pudo abrir el archivo', 500);
    }

    // Usa el byte offset persistido para no releer desde el inicio del CSV en cada lote.
    // Si el archivo o el estado provienen de una versión anterior, conserva el fallback histórico.
    $can_seek = !empty($state['csv_byte_offset']) && absint($state['offset'] ?? 0) === $offset;
    if ($can_seek) {
        fseek($fh, (int) $state['csv_byte_offset']);
        $line = $offset + 1;
    } else {
        fgetcsv($fh);
        $line = 1;
        while ($line < $offset + 1 && fgetcsv($fh) !== false) {
            $line++;
        }
    }

    $processed_now = 0;
    $created_now   = 0;
    $updated_now   = 0;
    $skipped_now   = 0;
    $errors_now    = 0;
    $batch_logs    = [];
    $new_created_ids = [];
    $stop_reason   = 'batch_limit';
    $reached_eof   = false;
    $last_byte_offset = ftell($fh);

    while ($processed_now < $effective_chunk) {
        // La orden de control vive fuera del estado pesado y se consulta directamente en BD.
        // De esta forma cancelar o pausar no puede perderse por una escritura tardía del worker.
        $control_command = evapp_import_control_command($key, true);
        if ($control_command === 'cancel') {
            $stop_reason = 'user_cancel';
            break;
        }
        if ($control_command === 'pause') {
            $stop_reason = 'user_stop';
            break;
        }

        // Se permite siempre al menos una fila. Después se aplican los guardas adaptativos.
        if ($processed_now > 0) {
            $elapsed_now_ms = (microtime(true) - $start_time) * 1000;
            if ($elapsed_now_ms >= $time_budget_ms) {
                $stop_reason = 'time_budget';
                break;
            }

            $current_resources = evapp_import_resource_snapshot();
            $memory_current_percent = (float) ($current_resources['memory_percent'] ?? 0);
            $memory_peak_percent = (float) ($current_resources['memory_peak_percent'] ?? 0);
            if (!empty($current_resources['memory_limit']) && ($memory_current_percent >= 82 || $memory_peak_percent >= 90)) {
                $stop_reason = 'memory_guard';
                break;
            }

        }

        $row = fgetcsv($fh);
        if ($row === false) {
            $reached_eof = true;
            $stop_reason = 'completed';
            break;
        }

        $line++;
        $processed_now++;

        $data = [];
        foreach ($rev as $i => $field) {
            $data[$field] = isset($row[$i]) ? trim((string) $row[$i]) : '';
        }

        $last_byte_offset = ftell($fh);

        $row_result = evapp_import_process_row_data($event_id, $data, $line, $asset_config);
        $created_delta = absint($row_result['created'] ?? 0);
        $updated_delta = absint($row_result['updated'] ?? 0);
        $skipped_delta = absint($row_result['skipped'] ?? 0);
        $error_delta   = absint($row_result['errors'] ?? 0);

        $created_now += $created_delta;
        $updated_now += $updated_delta;
        $skipped_now += $skipped_delta;
        $errors_now  += $error_delta;
        $created     += $created_delta;
        $updated     += $updated_delta;
        $skipped     += $skipped_delta;
        $errors      += $error_delta;

        if (!empty($row_result['ticket_id']) && $created_delta > 0) {
            $new_created_ids[] = absint($row_result['ticket_id']);
        }
        if (!empty($row_result['log'])) {
            $batch_logs[] = $row_result['log'];
        }

        $offset++;
    }

    fclose($fh);

    $total_rows = (int) ($state['total_rows'] ?? 0);
    $done = $reached_eof || ($total_rows > 0 && $offset >= $total_rows);
    $elapsed_ms = round((microtime(true) - $start_time) * 1000, 2);
    $end_resources = evapp_import_resource_snapshot();
    $end_queries = function_exists('get_num_queries') ? get_num_queries() : $start_queries;

    $cpu_ms = null;
    if ($start_resources['cpu_ms_total'] !== null && $end_resources['cpu_ms_total'] !== null) {
        $cpu_ms = max(0, round($end_resources['cpu_ms_total'] - $start_resources['cpu_ms_total'], 2));
    }

    $rows_per_second = $processed_now > 0 && $elapsed_ms > 0
        ? round($processed_now / ($elapsed_ms / 1000), 3)
        : 0;
    $batch_avg_ms = $processed_now > 0 ? round($elapsed_ms / $processed_now, 2) : 0;

    $resource_level = 'normal';
    $memory_pressure = max(
        (float) ($end_resources['memory_percent'] ?? 0),
        (float) ($end_resources['memory_peak_percent'] ?? 0)
    );
    if ($stop_reason === 'memory_guard' || $memory_pressure >= 85) {
        $resource_level = 'high';
    } elseif ($memory_pressure >= 70 || $elapsed_ms >= ($time_budget_ms * 0.9)) {
        $resource_level = 'warning';
    }

    $batch_metrics = [
        'processed_rows'   => $processed_now,
        'configured_batch' => (int) ($state['batch_size'] ?? 20),
        'effective_batch'  => $effective_chunk,
        'elapsed_ms'       => $elapsed_ms,
        'avg_ms_per_row'   => $batch_avg_ms,
        'rows_per_second'  => $rows_per_second,
        'cpu_ms'           => $cpu_ms,
        'db_queries'       => max(0, $end_queries - $start_queries),
        'memory_start'     => (int) $start_resources['memory_usage'],
        'memory_usage'     => (int) $end_resources['memory_usage'],
        'memory_peak'      => (int) $end_resources['memory_peak'],
        'memory_delta'     => (int) $end_resources['memory_usage'] - (int) $start_resources['memory_usage'],
        'memory_limit'     => (int) $end_resources['memory_limit'],
        'memory_percent'      => (float) $end_resources['memory_percent'],
        'memory_peak_percent' => (float) ($end_resources['memory_peak_percent'] ?? 0),
        'load_1'              => $end_resources['load_1'],
        'load_5'           => $end_resources['load_5'],
        'load_15'          => $end_resources['load_15'],
        'time_budget_ms'   => $time_budget_ms,
        'stop_reason'      => $stop_reason,
        'resource_level'   => $resource_level,
    ];

    // Recupera el estado más reciente y, después, la orden independiente de control.
    $latest_state = get_option($key);
    if (is_array($latest_state)) {
        $state = $latest_state;
    }
    $control_command = evapp_import_control_reconcile_state($key, $state, false);
    $cancel_requested = ($control_command === 'cancel') || !empty($state['cancelled']) || $stop_reason === 'user_cancel';
    $pause_requested  = ($control_command === 'pause') || (!empty($state['stopped']) && !$cancel_requested) || $stop_reason === 'user_stop';
    $final_done = $done && !$cancel_requested && !$pause_requested;

    $state['offset']           = $offset;
    $state['created_count']    = $created;
    $state['updated_existing'] = $updated;
    $state['skipped_dup']      = $skipped;
    $state['error_count']      = $errors;
    $state['csv_byte_offset']  = max(0, (int) $last_byte_offset);
    $state['done']             = $final_done ? 1 : 0;
    $state['updated_at']       = time();

    if (!isset($state['created_ids']) || !is_array($state['created_ids'])) {
        $state['created_ids'] = [];
    }
    if ($new_created_ids) {
        $state['created_ids'] = array_values(array_unique(array_merge($state['created_ids'], $new_created_ids)));
    }

    $performance = is_array($state['performance'] ?? null) ? $state['performance'] : [];
    $performance['batches'] = (int) ($performance['batches'] ?? 0) + 1;
    $performance['total_runtime_ms'] = (float) ($performance['total_runtime_ms'] ?? 0) + $elapsed_ms;
    $performance['total_processed_rows'] = (int) ($performance['total_processed_rows'] ?? 0) + $processed_now;
    $performance['avg_ms_per_row'] = $performance['total_processed_rows'] > 0
        ? round($performance['total_runtime_ms'] / $performance['total_processed_rows'], 2)
        : 0;
    $performance['last_batch'] = $batch_metrics;
    $state['performance'] = $performance;

    // Cancelar y pausar tienen prioridad sobre terminar el archivo. Esto impide que
    // una orden emitida al final del lote se convierta accidentalmente en "done".
    if ($cancel_requested) {
        $state['status'] = 'cancelled';
        $state['cancelled'] = 1;
        $state['stopped'] = 1;
        $state['background_token'] = '';
        $state['finished_at_ts'] = (int) ($state['finished_at_ts'] ?? time());
    } elseif ($pause_requested) {
        $state['status'] = 'stopped';
        $state['stopped'] = 1;
    } elseif ($final_done) {
        $state['status'] = 'done';
        $state['finished_at'] = current_time('mysql');
        $state['finished_at_ts'] = time();
    } else {
        $state['status'] = 'running';
    }

    $reason_labels = [
        'batch_limit' => 'límite del lote',
        'time_budget' => 'límite seguro de tiempo',
        'memory_guard'=> 'protección de memoria',
        'user_stop'   => 'detención solicitada',
        'user_cancel' => 'cancelación solicitada',
        'completed'   => 'fin del archivo',
    ];
    $reason_label = $reason_labels[$stop_reason] ?? $stop_reason;
    $summary_message = 'Lote procesado en '.number_format_i18n($elapsed_ms / 1000, 2).' s: '.$processed_now.' fila(s) | +'.$created_now.' nuevas | ↺'.$updated_now.' actualizadas | ✗'.$skipped_now.' omitidas | !'.$errors_now.' errores | '.number_format_i18n($rows_per_second, 2).' ticket(s)/s | cierre por '.$reason_label.'.';
    if (($state['status'] ?? '') === 'running' && in_array($stop_reason, ['batch_limit', 'time_budget', 'memory_guard'], true)) {
        $summary_message .= ' La importación continúa automáticamente con el siguiente lote.';
    }
    $batch_logs[] = evapp_import_log_entry($summary_message);

    if (($state['execution_mode'] ?? '') === 'background' && ($state['background_backend'] ?? '') !== 'task_queue' && in_array(($state['status'] ?? ''), ['running','done'], true)) {
        $batch_logs = array_merge($batch_logs, evapp_import_background_apply_notifications($state));
        if ($final_done) $state['admin_notified'] = 1;
    }

    if ($final_done && empty($state['admin_notified']) && ($state['execution_mode'] ?? 'browser') !== 'background') {
        $site_name       = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $admin_email     = get_option('admin_email');
        $event_title     = get_the_title($event_id);
        $filename        = $state['filename'] ?? '';
        $total_processed = (int) $state['offset'];

        if ($admin_email) {
            $subject = sprintf('[%s] Importación finalizada — %s [%d]', $site_name, $event_title, $event_id);
            $body = "Se completó una importación de tickets.\n\n".
                    "Sitio: {$site_name}\n".
                    "Evento: {$event_title} [{$event_id}]\n".
                    "Archivo: {$filename}\n".
                    "Filas procesadas: {$total_processed}\n".
                    "Tickets creados: ".(int) $state['created_count']."\n".
                    "Tickets actualizados: ".(int) $state['updated_existing']."\n".
                    "Duplicados omitidos: ".(int) $state['skipped_dup']."\n".
                    "Promedio medido: ".number_format_i18n((float) $performance['avg_ms_per_row'], 2)." ms por fila.\n".
                    "Los anexos se generaron dentro del mismo lote adaptativo.\n";
            wp_mail($admin_email, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
        }
        $state['admin_notified'] = 1;
        $batch_logs[] = evapp_import_log_entry('Importación finalizada. Correo de resumen enviado al administrador.');
    }

    $state = evapp_import_merge_runtime_logs($state, $batch_logs);
    update_option($key, $state, false);
    evapp_import_background_touch($key, $state);

    // Segunda comprobación justo después de guardar: si la orden llegó en la ventana
    // final de persistencia, se reaplica antes de liberar el lock o despachar otro worker.
    evapp_import_control_reconcile_state($key, $state, true);
    $confirmed_state = get_option($key);
    if (is_array($confirmed_state)) $state = $confirmed_state;

    evapp_import_release_lock($event_id, $hash, $lock_token, $key);

    if (($state['execution_mode'] ?? '') === 'background' && ($state['background_backend'] ?? '') !== 'task_queue') {
        if (($state['status'] ?? '') === 'running' && empty($state['done'])) {
            evapp_import_background_dispatch($state);
        } else {
            evapp_import_background_unschedule($state['background_task_id'] ?? '');
        }
    }

    wp_send_json_success(evapp_import_public_state($state, [
        'msg'  => $summary_message,
        'busy' => 0,
    ]));
});
//
// === Helper: crear ticket programáticamente (IMPORTACIÓN POR LOTES) ===
//
if (!function_exists('eventosapp_generate_unique_ticket_id')) {
    function eventosapp_generate_unique_ticket_id(){ return wp_generate_uuid4(); }
}
if (!function_exists('eventosapp_next_event_sequence')) {
    function eventosapp_next_event_sequence($event_id){
        $k = '_eventosapp_event_seq';
        $cur = (int) get_post_meta($event_id, $k, true);
        $cur++;
        update_post_meta($event_id, $k, $cur);
        return $cur;
    }
}

function eventosapp_create_ticket_programmatically($event_id, $p, $source = 'manual', $skip_heavy_operations = false, $asset_config = null){
    $importer_id = evapp_get_or_create_importer_user();
    $ticketID = eventosapp_generate_unique_ticket_id();
    $asset_config = is_array($asset_config) ? $asset_config : evapp_import_event_asset_config($event_id);

    // El título definitivo se establece desde el primer INSERT. Evita el segundo
    // wp_update_post(), que disparaba nuevamente save_post y podía duplicar trabajo pesado.
    $post_id = wp_insert_post([
        'post_type'   => 'eventosapp_ticket',
        'post_status' => 'publish',
        'post_title'  => $ticketID,
        'post_author' => $importer_id,
    ], true);
    if (is_wp_error($post_id)) return 0;

    update_post_meta($post_id, '_eventosapp_ticket_evento_id', (int) $event_id);
    update_post_meta($post_id, '_eventosapp_ticket_user_id', $importer_id);
    update_post_meta($post_id, '_eventosapp_creation_channel', $source);

    update_post_meta($post_id, '_eventosapp_asistente_nombre', sanitize_text_field($p['first_name'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_apellido', sanitize_text_field($p['last_name'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_email', sanitize_email($p['email'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_tel', sanitize_text_field($p['tel'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_empresa', sanitize_text_field($p['empresa'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_nit', sanitize_text_field($p['nit'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_cargo', sanitize_text_field($p['cargo'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_cc', sanitize_text_field($p['cc'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_ciudad', sanitize_text_field($p['ciudad'] ?? ''));
    update_post_meta($post_id, '_eventosapp_asistente_pais', sanitize_text_field($p['pais'] ?? 'Colombia'));
    update_post_meta($post_id, '_eventosapp_asistente_localidad', sanitize_text_field($p['localidad'] ?? ''));

    if (function_exists('eventosapp_ticket_sync_modalidad')) {
        eventosapp_ticket_sync_modalidad($post_id, $p['modalidad'] ?? '');
    } else {
        update_post_meta($post_id, '_eventosapp_ticket_modalidad', sanitize_key($p['modalidad'] ?? 'presencial'));
    }

    if (function_exists('eventosapp_ticket_init_email_status')) {
        eventosapp_ticket_init_email_status($post_id);
    }

    evapp_import_save_extra_fields($post_id, $event_id, $p['extras'] ?? []);

    update_post_meta($post_id, 'eventosapp_ticketID', $ticketID);
    $seq = eventosapp_next_event_sequence($event_id);
    update_post_meta($post_id, '_eventosapp_ticket_seq', (int) $seq);

    $event_config = evapp_import_event_runtime_config($event_id);
    $status_arr = [];
    foreach ($event_config['days'] as $day) {
        $status_arr[$day] = 'not_checked_in';
    }
    update_post_meta($post_id, '_eventosapp_checkin_status', $status_arr);
    update_post_meta($post_id, '_eventosapp_checkin_log', []);

    $localidad_ticket = sanitize_text_field($p['localidad'] ?? '');
    $accesos = [];
    foreach ($event_config['sessions'] as $session) {
        if (isset($session['nombre'], $session['localidades']) && is_array($session['localidades'])) {
            if ($localidad_ticket && in_array($localidad_ticket, $session['localidades'], true)) {
                $accesos[] = $session['nombre'];
            }
        }
    }
    if ($accesos) {
        update_post_meta($post_id, '_eventosapp_ticket_sesiones_acceso', $accesos);
    }

    if (!$skip_heavy_operations) {
        evapp_import_generate_assets_now($post_id, $event_id, $asset_config);
    } else {
        // Si se crea el ticket sin anexos pesados, al menos queda guardada su variante efectiva
        // para que una generación posterior use la configuración correcta.
        if (!empty($asset_config['variants']) && function_exists('eventosapp_ticket_variants_prepare_ticket_for_batch_context')) {
            eventosapp_ticket_variants_prepare_ticket_for_batch_context($post_id, $event_id, 'import_create_ticket_skip_assets', [
                'sync_google_classes' => false,
                'log'                 => true,
            ]);
        } elseif (!empty($asset_config['variants']) && function_exists('eventosapp_ticket_variants_apply_to_ticket')) {
            eventosapp_ticket_variants_apply_to_ticket($post_id, $event_id, false);
        }
    }

    if (function_exists('eventosapp_ticket_build_search_blob')) {
        eventosapp_ticket_build_search_blob($post_id);
    }

    return $post_id;
}
