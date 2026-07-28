<?php
/**
 * EventosApp - Cola central de tareas en segundo plano.
 *
 * Coordina envíos masivos y programados sin reemplazar los ejecutores de cada
 * módulo. Cada tarea avanza un lote por ejecución, lo que permite intercalar
 * varias tareas, pausar/reanudar/cancelar y proteger los recursos del servidor.
 *
 * Ruta: includes/functions/eventosapp-task-queue-core.php
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! defined('EVENTOSAPP_TASK_QUEUE_VERSION') ) {
    define('EVENTOSAPP_TASK_QUEUE_VERSION', '2026.07.27.1');
}
if ( ! defined('EVENTOSAPP_TASK_QUEUE_DB_VERSION') ) {
    define('EVENTOSAPP_TASK_QUEUE_DB_VERSION', '2026.07.27.1');
}
if ( ! defined('EVENTOSAPP_TASK_QUEUE_DISPATCH_HOOK') ) {
    define('EVENTOSAPP_TASK_QUEUE_DISPATCH_HOOK', 'eventosapp_task_queue_dispatch');
}
if ( ! defined('EVENTOSAPP_TASK_QUEUE_WORK_HOOK') ) {
    define('EVENTOSAPP_TASK_QUEUE_WORK_HOOK', 'eventosapp_task_queue_work');
}
if ( ! defined('EVENTOSAPP_TASK_QUEUE_CLEANUP_HOOK') ) {
    define('EVENTOSAPP_TASK_QUEUE_CLEANUP_HOOK', 'eventosapp_task_queue_cleanup');
}

/**
 * Tablas del módulo.
 */
function eventosapp_task_queue_table($name = 'tasks') {
    global $wpdb;
    $map = [
        'tasks' => $wpdb->prefix . 'eventosapp_tasks',
        'logs'  => $wpdb->prefix . 'eventosapp_task_logs',
    ];
    $name = sanitize_key((string)$name);
    return $map[$name] ?? '';
}

/**
 * Configuración central. Todos los valores pueden ajustarse por filtro.
 */
function eventosapp_task_queue_config() {
    $defaults = [
        'max_concurrent'          => 3,
        'dispatcher_limit'        => 12,
        'worker_lock_ttl'         => 240,
        'heartbeat_stale_seconds' => 300,
        'max_execution_seconds'   => 22,
        'memory_stop_ratio'       => 0.72,
        'load_stop_per_core'      => 1.30,
        'min_delay_seconds'       => 1,
        'normal_delay_seconds'    => 4,
        'busy_delay_seconds'      => 15,
        'max_consecutive_errors'  => 3,
        'log_retention_days'      => 45,
        'task_retention_days'     => 90,
        'default_batch_size'      => 10,
        'max_batch_size'          => 50,
    ];

    $config = apply_filters('eventosapp_task_queue_config', $defaults);
    $config = is_array($config) ? wp_parse_args($config, $defaults) : $defaults;

    $config['max_concurrent']          = min(8, max(1, absint($config['max_concurrent'])));
    $config['dispatcher_limit']        = min(50, max($config['max_concurrent'], absint($config['dispatcher_limit'])));
    $config['worker_lock_ttl']         = min(900, max(60, absint($config['worker_lock_ttl'])));
    $config['heartbeat_stale_seconds'] = min(1800, max(120, absint($config['heartbeat_stale_seconds'])));
    $config['max_execution_seconds']   = min(45, max(8, absint($config['max_execution_seconds'])));
    $config['memory_stop_ratio']       = min(0.92, max(0.45, (float)$config['memory_stop_ratio']));
    $config['load_stop_per_core']      = min(4.0, max(0.50, (float)$config['load_stop_per_core']));
    $config['min_delay_seconds']       = min(30, max(1, absint($config['min_delay_seconds'])));
    $config['normal_delay_seconds']    = min(60, max($config['min_delay_seconds'], absint($config['normal_delay_seconds'])));
    $config['busy_delay_seconds']      = min(300, max($config['normal_delay_seconds'], absint($config['busy_delay_seconds'])));
    $config['max_consecutive_errors']  = min(10, max(1, absint($config['max_consecutive_errors'])));
    $config['log_retention_days']      = min(365, max(7, absint($config['log_retention_days'])));
    $config['task_retention_days']     = min(730, max(7, absint($config['task_retention_days'])));
    $config['default_batch_size']      = min(100, max(1, absint($config['default_batch_size'])));
    $config['max_batch_size']          = min(250, max($config['default_batch_size'], absint($config['max_batch_size'])));

    return $config;
}

/**
 * Instalación idempotente.
 */
function eventosapp_task_queue_install_tables() {
    global $wpdb;

    $tasks = eventosapp_task_queue_table('tasks');
    $logs  = eventosapp_task_queue_table('logs');
    $charset = $wpdb->get_charset_collate();

    if ( ! function_exists('dbDelta') ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    $sql_tasks = "CREATE TABLE {$tasks} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid VARCHAR(64) NOT NULL,
        task_type VARCHAR(100) NOT NULL,
        task_group VARCHAR(40) NOT NULL DEFAULT 'massive',
        channel VARCHAR(80) NOT NULL DEFAULT '',
        title VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(40) NOT NULL DEFAULT 'queued',
        priority SMALLINT NOT NULL DEFAULT 10,
        event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        client_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        event_date DATE NULL,
        scheduled_at DATETIME NULL,
        next_run_at DATETIME NULL,
        total_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
        processed_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
        success_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
        error_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
        skipped_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
        cursor_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
        batch_size SMALLINT UNSIGNED NOT NULL DEFAULT 10,
        consecutive_errors SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        payload LONGTEXT NULL,
        resource_metrics LONGTEXT NULL,
        milestones LONGTEXT NULL,
        last_error TEXT NULL,
        notification_email VARCHAR(190) NOT NULL DEFAULT '',
        created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        started_at DATETIME NULL,
        heartbeat_at DATETIME NULL,
        updated_at DATETIME NOT NULL,
        completed_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uuid (uuid),
        KEY task_type (task_type),
        KEY task_group (task_group),
        KEY channel (channel),
        KEY status (status),
        KEY event_id (event_id),
        KEY client_id (client_id),
        KEY event_date (event_date),
        KEY scheduled_at (scheduled_at),
        KEY next_run_at (next_run_at),
        KEY heartbeat_at (heartbeat_at),
        KEY created_at (created_at)
    ) {$charset};";

    $sql_logs = "CREATE TABLE {$logs} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        task_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        level VARCHAR(20) NOT NULL DEFAULT 'info',
        message TEXT NOT NULL,
        context LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY task_id (task_id),
        KEY level (level),
        KEY created_at (created_at)
    ) {$charset};";

    dbDelta($sql_tasks);
    dbDelta($sql_logs);
    update_option('eventosapp_task_queue_db_version', EVENTOSAPP_TASK_QUEUE_DB_VERSION, false);
}

function eventosapp_task_queue_maybe_install() {
    if ( get_option('eventosapp_task_queue_db_version') !== EVENTOSAPP_TASK_QUEUE_DB_VERSION ) {
        eventosapp_task_queue_install_tables();
    }
}
add_action('init', 'eventosapp_task_queue_maybe_install', 4);

/**
 * Intervalo minutely y programación de dispatcher/limpieza.
 */
add_filter('cron_schedules', function($schedules) {
    if ( ! isset($schedules['eventosapp_minutely']) ) {
        $schedules['eventosapp_minutely'] = [
            'interval' => 60,
            'display'  => 'EventosApp cada minuto',
        ];
    }
    return $schedules;
});

function eventosapp_task_queue_schedule_cron() {
    if ( ! wp_next_scheduled(EVENTOSAPP_TASK_QUEUE_DISPATCH_HOOK) ) {
        wp_schedule_event(time() + 30, 'eventosapp_minutely', EVENTOSAPP_TASK_QUEUE_DISPATCH_HOOK);
    }
    if ( ! wp_next_scheduled(EVENTOSAPP_TASK_QUEUE_CLEANUP_HOOK) ) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', EVENTOSAPP_TASK_QUEUE_CLEANUP_HOOK);
    }
}
add_action('init', 'eventosapp_task_queue_schedule_cron', 25);

/**
 * Registro de adaptadores.
 */
function eventosapp_task_queue_register_adapter($task_type, $adapter) {
    $task_type = sanitize_key((string)$task_type);
    if ( $task_type === '' || ! is_array($adapter) || empty($adapter['process_batch']) || ! is_callable($adapter['process_batch']) ) {
        return false;
    }

    if ( ! isset($GLOBALS['eventosapp_task_queue_adapters']) || ! is_array($GLOBALS['eventosapp_task_queue_adapters']) ) {
        $GLOBALS['eventosapp_task_queue_adapters'] = [];
    }

    $GLOBALS['eventosapp_task_queue_adapters'][$task_type] = wp_parse_args($adapter, [
        'label'           => $task_type,
        'channel'         => '',
        'group'           => 'massive',
        'batch_size'      => 10,
        'min_batch_size'  => 1,
        'max_batch_size'  => 50,
        'per_item_delay'  => 0,
    ]);
    return true;
}

function eventosapp_task_queue_get_adapter($task_type) {
    $task_type = sanitize_key((string)$task_type);
    $adapters = isset($GLOBALS['eventosapp_task_queue_adapters']) && is_array($GLOBALS['eventosapp_task_queue_adapters'])
        ? $GLOBALS['eventosapp_task_queue_adapters']
        : [];
    return isset($adapters[$task_type]) && is_array($adapters[$task_type]) ? $adapters[$task_type] : null;
}

function eventosapp_task_queue_adapters() {
    return isset($GLOBALS['eventosapp_task_queue_adapters']) && is_array($GLOBALS['eventosapp_task_queue_adapters'])
        ? $GLOBALS['eventosapp_task_queue_adapters']
        : [];
}

/**
 * Serialización segura de JSON.
 */
function eventosapp_task_queue_json_encode($value) {
    return wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function eventosapp_task_queue_json_decode($value, $fallback = []) {
    if ( is_array($value) ) return $value;
    if ( ! is_string($value) || trim($value) === '' ) return $fallback;
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function eventosapp_task_queue_statuses() {
    return [
        'queued'    => 'En cola',
        'scheduled' => 'Programada',
        'running'   => 'En ejecución',
        'paused'    => 'Pausada',
        'cancelled' => 'Cancelada',
        'completed' => 'Finalizada',
        'failed'    => 'Error',
    ];
}

function eventosapp_task_queue_terminal_statuses() {
    return ['cancelled', 'completed', 'failed'];
}

function eventosapp_task_queue_normalize_status($status, $fallback = 'queued') {
    $status = sanitize_key((string)$status);
    return array_key_exists($status, eventosapp_task_queue_statuses()) ? $status : $fallback;
}

/**
 * Fecha principal del evento para filtros.
 */
function eventosapp_task_queue_event_date($event_id) {
    $event_id = absint($event_id);
    if ( ! $event_id ) return null;

    $type = sanitize_key((string)get_post_meta($event_id, '_eventosapp_tipo_fecha', true));
    $date = '';
    if ( $type === 'rango' ) {
        $date = get_post_meta($event_id, '_eventosapp_fecha_inicio', true);
    } elseif ( $type === 'noconsecutiva' ) {
        $dates = get_post_meta($event_id, '_eventosapp_fechas_noco', true);
        $dates = is_array($dates) ? array_values(array_filter(array_map('sanitize_text_field', $dates))) : [];
        sort($dates);
        $date = $dates ? reset($dates) : '';
    } else {
        $date = get_post_meta($event_id, '_eventosapp_fecha_unica', true);
    }

    $date = sanitize_text_field((string)$date);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
}

function eventosapp_task_queue_event_client_id($event_id) {
    $event_id = absint($event_id);
    return $event_id ? absint(get_post_meta($event_id, '_eventosapp_cliente_id', true)) : 0;
}

/**
 * Crea una tarea.
 */
function eventosapp_task_queue_create($args) {
    global $wpdb;

    $args = wp_parse_args(is_array($args) ? $args : [], [
        'task_type'          => '',
        'task_group'         => 'massive',
        'channel'            => '',
        'title'              => '',
        'status'             => 'queued',
        'priority'           => 10,
        'event_id'           => 0,
        'client_id'          => 0,
        'event_date'         => null,
        'scheduled_at'       => null,
        'next_run_at'        => null,
        'total_items'        => 0,
        'batch_size'         => 0,
        'payload'            => [],
        'notification_email' => '',
        'created_by'         => get_current_user_id(),
    ]);

    $task_type = sanitize_key((string)$args['task_type']);
    $adapter = eventosapp_task_queue_get_adapter($task_type);
    if ( $task_type === '' || ! $adapter ) {
        return new WP_Error('eventosapp_task_queue_adapter_missing', 'No existe un adaptador válido para este tipo de tarea.');
    }

    $event_id = absint($args['event_id']);
    $client_id = absint($args['client_id']);
    if ( ! $client_id && $event_id ) {
        $client_id = eventosapp_task_queue_event_client_id($event_id);
    }

    $event_date = $args['event_date'];
    if ( ! $event_date && $event_id ) {
        $event_date = eventosapp_task_queue_event_date($event_id);
    }
    $event_date = is_string($event_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date) ? $event_date : null;

    $scheduled_at = eventosapp_task_queue_normalize_datetime($args['scheduled_at']);
    $next_run_at  = eventosapp_task_queue_normalize_datetime($args['next_run_at']);
    $status = eventosapp_task_queue_normalize_status($args['status']);
    if ( $scheduled_at && strtotime($scheduled_at . ' UTC') > time() ) {
        $status = 'scheduled';
        if ( ! $next_run_at ) $next_run_at = $scheduled_at;
    }
    if ( ! $next_run_at ) $next_run_at = current_time('mysql', true);

    $config = eventosapp_task_queue_config();
    $adapter_batch = absint($adapter['batch_size'] ?? $config['default_batch_size']);
    $batch_size = absint($args['batch_size']);
    if ( ! $batch_size ) $batch_size = $adapter_batch ?: $config['default_batch_size'];
    $batch_size = min($config['max_batch_size'], max(1, $batch_size));

    $title = sanitize_text_field((string)$args['title']);
    if ( $title === '' ) {
        $title = sanitize_text_field((string)($adapter['label'] ?? $task_type));
    }

    $now = current_time('mysql', true);
    $uuid = wp_generate_uuid4();
    $inserted = $wpdb->insert(
        eventosapp_task_queue_table('tasks'),
        [
            'uuid'               => $uuid,
            'task_type'          => $task_type,
            'task_group'         => in_array($args['task_group'], ['massive','scheduled'], true) ? $args['task_group'] : sanitize_key((string)($adapter['group'] ?? 'massive')),
            'channel'            => sanitize_key((string)($args['channel'] ?: ($adapter['channel'] ?? ''))),
            'title'              => $title,
            'status'             => $status,
            'priority'           => max(-100, min(100, intval($args['priority']))),
            'event_id'           => $event_id,
            'client_id'          => $client_id,
            'event_date'         => $event_date,
            'scheduled_at'       => $scheduled_at,
            'next_run_at'        => $next_run_at,
            'total_items'        => max(0, (int)$args['total_items']),
            'processed_items'    => 0,
            'success_items'      => 0,
            'error_items'        => 0,
            'skipped_items'      => 0,
            'cursor_value'       => 0,
            'batch_size'         => $batch_size,
            'consecutive_errors' => 0,
            'payload'            => eventosapp_task_queue_json_encode(is_array($args['payload']) ? $args['payload'] : []),
            'resource_metrics'   => eventosapp_task_queue_json_encode([]),
            'milestones'         => eventosapp_task_queue_json_encode([]),
            'last_error'         => '',
            'notification_email' => sanitize_email((string)$args['notification_email']),
            'created_by'         => absint($args['created_by']),
            'created_at'         => $now,
            'updated_at'         => $now,
        ]
    );

    if ( ! $inserted ) {
        return new WP_Error('eventosapp_task_queue_insert_failed', $wpdb->last_error ?: 'No se pudo crear la tarea.');
    }

    $task_id = (int)$wpdb->insert_id;
    eventosapp_task_queue_add_log($task_id, 'info', 'Tarea creada y enviada a la cola.', [
        'task_type' => $task_type,
        'status'    => $status,
        'total'     => (int)$args['total_items'],
    ]);
    do_action('eventosapp_task_queue_created', $task_id, eventosapp_task_queue_get($task_id));
    eventosapp_task_queue_kick();
    return $task_id;
}

function eventosapp_task_queue_normalize_datetime($value) {
    if ( ! $value ) return null;
    if ( is_numeric($value) ) {
        return gmdate('Y-m-d H:i:s', (int)$value);
    }
    $value = sanitize_text_field((string)$value);
    $ts = strtotime($value);
    return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
}

/**
 * Lectura y normalización de tareas.
 */
function eventosapp_task_queue_hydrate($row) {
    if ( ! $row ) return null;
    $task = is_object($row) ? get_object_vars($row) : (array)$row;
    foreach (['id','priority','event_id','client_id','total_items','processed_items','success_items','error_items','skipped_items','cursor_value','batch_size','consecutive_errors','created_by'] as $key) {
        $task[$key] = isset($task[$key]) ? (int)$task[$key] : 0;
    }
    $task['payload']          = eventosapp_task_queue_json_decode($task['payload'] ?? '', []);
    $task['resource_metrics'] = eventosapp_task_queue_json_decode($task['resource_metrics'] ?? '', []);
    $task['milestones']       = eventosapp_task_queue_json_decode($task['milestones'] ?? '', []);
    $task['progress']         = $task['total_items'] > 0 ? min(100, round(($task['processed_items'] / $task['total_items']) * 100, 2)) : 0;
    $task['event_title']      = $task['event_id'] ? get_the_title($task['event_id']) : '';
    $task['client_title']     = $task['client_id'] ? get_the_title($task['client_id']) : '';
    return $task;
}

function eventosapp_task_queue_get($task_id) {
    global $wpdb;
    $task_id = absint($task_id);
    if ( ! $task_id ) return null;
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . eventosapp_task_queue_table('tasks') . ' WHERE id = %d',
        $task_id
    ), ARRAY_A);
    return eventosapp_task_queue_hydrate($row);
}

function eventosapp_task_queue_find_by_uuid($uuid) {
    global $wpdb;
    $uuid = sanitize_text_field((string)$uuid);
    if ( $uuid === '' ) return null;
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . eventosapp_task_queue_table('tasks') . ' WHERE uuid = %s',
        $uuid
    ), ARRAY_A);
    return eventosapp_task_queue_hydrate($row);
}

function eventosapp_task_queue_update($task_id, $data) {
    global $wpdb;
    $task_id = absint($task_id);
    if ( ! $task_id || ! is_array($data) || empty($data) ) return false;

    $allowed = [
        'task_type','task_group','channel','title','status','priority','event_id','client_id','event_date',
        'scheduled_at','next_run_at','total_items','processed_items','success_items','error_items','skipped_items',
        'cursor_value','batch_size','consecutive_errors','payload','resource_metrics','milestones','last_error',
        'notification_email','created_by','started_at','heartbeat_at','updated_at','completed_at'
    ];
    $clean = [];
    foreach ( $data as $key => $value ) {
        if ( ! in_array($key, $allowed, true) ) continue;
        if ( in_array($key, ['payload','resource_metrics','milestones'], true) && is_array($value) ) {
            $value = eventosapp_task_queue_json_encode($value);
        }
        $clean[$key] = $value;
    }
    if ( empty($clean) ) return false;
    if ( ! isset($clean['updated_at']) ) $clean['updated_at'] = current_time('mysql', true);
    return false !== $wpdb->update(eventosapp_task_queue_table('tasks'), $clean, ['id'=>$task_id]);
}

/**
 * Listado paginado con filtros administrativos.
 */
function eventosapp_task_queue_list($args = []) {
    global $wpdb;
    $args = wp_parse_args(is_array($args) ? $args : [], [
        'status'      => '',
        'event_id'    => 0,
        'client_id'   => 0,
        'event_date'  => '',
        'channel'     => '',
        'task_group'  => '',
        'task_type'   => '',
        'search'      => '',
        'page'        => 1,
        'per_page'    => 30,
        'orderby'     => 'created_at',
        'order'       => 'DESC',
    ]);

    $where = ['1=1'];
    $params = [];
    if ( $args['status'] !== '' ) { $where[] = 'status = %s'; $params[] = eventosapp_task_queue_normalize_status($args['status']); }
    if ( absint($args['event_id']) ) { $where[] = 'event_id = %d'; $params[] = absint($args['event_id']); }
    if ( absint($args['client_id']) ) { $where[] = 'client_id = %d'; $params[] = absint($args['client_id']); }
    if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$args['event_date']) ) { $where[] = 'event_date = %s'; $params[] = $args['event_date']; }
    if ( $args['channel'] !== '' ) { $where[] = 'channel = %s'; $params[] = sanitize_key($args['channel']); }
    if ( in_array($args['task_group'], ['massive','scheduled'], true) ) { $where[] = 'task_group = %s'; $params[] = $args['task_group']; }
    if ( $args['task_type'] !== '' ) { $where[] = 'task_type = %s'; $params[] = sanitize_key($args['task_type']); }
    if ( trim((string)$args['search']) !== '' ) {
        $like = '%' . $wpdb->esc_like(trim((string)$args['search'])) . '%';
        $where[] = '(title LIKE %s OR uuid LIKE %s OR last_error LIKE %s)';
        array_push($params, $like, $like, $like);
    }

    $allowed_orderby = ['id','created_at','updated_at','scheduled_at','next_run_at','priority','status','event_date','processed_items'];
    $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'created_at';
    $order = strtoupper((string)$args['order']) === 'ASC' ? 'ASC' : 'DESC';
    $per_page = min(100, max(1, absint($args['per_page'])));
    $page = max(1, absint($args['page']));
    $offset = ($page - 1) * $per_page;
    $table = eventosapp_task_queue_table('tasks');
    $where_sql = implode(' AND ', $where);

    $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
    $list_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
    $count_params = $params;
    $list_params = array_merge($params, [$per_page, $offset]);

    $total = (int)$wpdb->get_var($count_params ? $wpdb->prepare($count_sql, $count_params) : $count_sql);
    $rows  = $wpdb->get_results($wpdb->prepare($list_sql, $list_params), ARRAY_A);
    $items = array_values(array_filter(array_map('eventosapp_task_queue_hydrate', $rows)));

    return [
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'pages'    => max(1, (int)ceil($total / $per_page)),
    ];
}

/**
 * Logs.
 */
function eventosapp_task_queue_add_log($task_id, $level, $message, $context = []) {
    global $wpdb;
    $task_id = absint($task_id);
    if ( ! $task_id ) return false;
    $level = sanitize_key((string)$level);
    if ( ! in_array($level, ['debug','info','success','warning','error'], true) ) $level = 'info';
    $message = wp_strip_all_tags((string)$message);
    if ( function_exists('mb_substr') ) $message = mb_substr($message, 0, 4000);
    else $message = substr($message, 0, 4000);

    return (bool)$wpdb->insert(eventosapp_task_queue_table('logs'), [
        'task_id'   => $task_id,
        'level'     => $level,
        'message'   => $message,
        'context'   => eventosapp_task_queue_json_encode(is_array($context) ? $context : []),
        'created_at'=> current_time('mysql', true),
    ], ['%d','%s','%s','%s','%s']);
}

function eventosapp_task_queue_get_logs($task_id, $limit = 200) {
    global $wpdb;
    $task_id = absint($task_id);
    $limit = min(500, max(1, absint($limit)));
    if ( ! $task_id ) return [];
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . eventosapp_task_queue_table('logs') . ' WHERE task_id = %d ORDER BY id DESC LIMIT %d',
        $task_id,
        $limit
    ), ARRAY_A);
    foreach ( $rows as &$row ) {
        $row['id'] = (int)$row['id'];
        $row['task_id'] = (int)$row['task_id'];
        $row['context'] = eventosapp_task_queue_json_decode($row['context'] ?? '', []);
    }
    return $rows;
}

/**
 * Controles.
 */
function eventosapp_task_queue_pause($task_id) {
    $task = eventosapp_task_queue_get($task_id);
    if ( ! $task || in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) ) return false;
    eventosapp_task_queue_update($task_id, ['status'=>'paused','heartbeat_at'=>null]);
    eventosapp_task_queue_add_log($task_id, 'warning', 'Tarea pausada por un administrador.');
    return true;
}

function eventosapp_task_queue_resume($task_id) {
    $task = eventosapp_task_queue_get($task_id);
    if ( ! $task || $task['status'] !== 'paused' ) return false;
    eventosapp_task_queue_update($task_id, [
        'status'      => 'queued',
        'next_run_at' => current_time('mysql', true),
        'last_error'  => '',
    ]);
    eventosapp_task_queue_add_log($task_id, 'info', 'Tarea reanudada.');
    eventosapp_task_queue_kick();
    return true;
}

function eventosapp_task_queue_cancel($task_id, $reason = '') {
    $task = eventosapp_task_queue_get($task_id);
    if ( ! $task || in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) ) return false;
    $reason = sanitize_text_field((string)$reason);
    if ( $reason === '' ) $reason = 'Cancelada por un administrador.';
    eventosapp_task_queue_update($task_id, [
        'status'       => 'cancelled',
        'last_error'   => $reason,
        'heartbeat_at' => null,
        'completed_at' => current_time('mysql', true),
    ]);
    eventosapp_task_queue_add_log($task_id, 'warning', $reason);
    eventosapp_task_queue_send_status_email(eventosapp_task_queue_get($task_id), 'cancelled');
    do_action('eventosapp_task_queue_cancelled', $task_id, eventosapp_task_queue_get($task_id));
    return true;
}

function eventosapp_task_queue_reschedule($task_id, $datetime) {
    $task = eventosapp_task_queue_get($task_id);
    if ( ! $task || $task['status'] === 'completed' ) return false;
    $scheduled = eventosapp_task_queue_normalize_datetime($datetime);
    if ( ! $scheduled ) return false;
    eventosapp_task_queue_update($task_id, [
        'status'       => strtotime($scheduled . ' UTC') > time() ? 'scheduled' : 'queued',
        'scheduled_at' => $scheduled,
        'next_run_at'  => $scheduled,
        'completed_at' => null,
        'heartbeat_at' => null,
    ]);
    eventosapp_task_queue_add_log($task_id, 'info', 'Tarea reprogramada.', ['scheduled_at'=>$scheduled]);
    eventosapp_task_queue_kick();
    return true;
}

function eventosapp_task_queue_delete($task_id) {
    global $wpdb;
    $task = eventosapp_task_queue_get($task_id);
    if ( ! $task || ! in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) ) return false;
    $wpdb->delete(eventosapp_task_queue_table('logs'), ['task_id'=>absint($task_id)], ['%d']);
    return false !== $wpdb->delete(eventosapp_task_queue_table('tasks'), ['id'=>absint($task_id)], ['%d']);
}

function eventosapp_task_queue_delete_terminal($status = '') {
    global $wpdb;
    $statuses = $status !== '' ? [eventosapp_task_queue_normalize_status($status)] : eventosapp_task_queue_terminal_statuses();
    $statuses = array_values(array_intersect($statuses, eventosapp_task_queue_terminal_statuses()));
    if ( empty($statuses) ) return 0;
    $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
    $tasks = eventosapp_task_queue_table('tasks');
    $logs = eventosapp_task_queue_table('logs');
    $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$tasks} WHERE status IN ({$placeholders})", $statuses));
    if ( empty($ids) ) return 0;
    $id_placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$logs} WHERE task_id IN ({$id_placeholders})", array_map('absint', $ids)));
    return (int)$wpdb->query($wpdb->prepare("DELETE FROM {$tasks} WHERE id IN ({$id_placeholders})", array_map('absint', $ids)));
}

/**
 * Recursos del servidor.
 */
function eventosapp_task_queue_parse_bytes($value) {
    $value = trim((string)$value);
    if ( $value === '' || $value === '-1' ) return 0;
    $unit = strtolower(substr($value, -1));
    $bytes = (float)$value;
    if ( $unit === 'g' ) $bytes *= 1024 * 1024 * 1024;
    elseif ( $unit === 'm' ) $bytes *= 1024 * 1024;
    elseif ( $unit === 'k' ) $bytes *= 1024;
    return (int)$bytes;
}

function eventosapp_task_queue_cpu_cores() {
    static $cores = null;
    if ( $cores !== null ) return $cores;
    $cores = absint(getenv('NUMBER_OF_PROCESSORS'));
    if ( ! $cores && is_readable('/proc/cpuinfo') ) {
        $content = @file_get_contents('/proc/cpuinfo');
        if ( is_string($content) ) $cores = max(1, preg_match_all('/^processor\s*:/m', $content));
    }
    if ( ! $cores ) $cores = 1;
    return max(1, $cores);
}

function eventosapp_task_queue_resource_snapshot() {
    $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : [];
    $load = is_array($load) ? $load : [];
    $memory_limit = eventosapp_task_queue_parse_bytes(ini_get('memory_limit'));
    $memory_usage = memory_get_usage(true);
    $memory_peak  = memory_get_peak_usage(true);
    $cpu_ms = null;
    if ( function_exists('getrusage') ) {
        $r = @getrusage();
        if ( is_array($r) ) {
            $cpu_ms =
                ((float)($r['ru_utime.tv_sec'] ?? 0) * 1000) +
                ((float)($r['ru_utime.tv_usec'] ?? 0) / 1000) +
                ((float)($r['ru_stime.tv_sec'] ?? 0) * 1000) +
                ((float)($r['ru_stime.tv_usec'] ?? 0) / 1000);
        }
    }
    return [
        'at'                  => current_time('mysql', true),
        'memory_usage'        => (int)$memory_usage,
        'memory_peak'         => (int)$memory_peak,
        'memory_limit'        => (int)$memory_limit,
        'memory_percent'      => $memory_limit > 0 ? round(($memory_usage / $memory_limit) * 100, 2) : 0,
        'memory_peak_percent' => $memory_limit > 0 ? round(($memory_peak / $memory_limit) * 100, 2) : 0,
        'load_1'              => isset($load[0]) ? round((float)$load[0], 2) : null,
        'load_5'              => isset($load[1]) ? round((float)$load[1], 2) : null,
        'load_15'             => isset($load[2]) ? round((float)$load[2], 2) : null,
        'cpu_cores'           => eventosapp_task_queue_cpu_cores(),
        'cpu_ms_total'        => $cpu_ms,
    ];
}

function eventosapp_task_queue_resources_busy($snapshot = null) {
    $config = eventosapp_task_queue_config();
    $snapshot = is_array($snapshot) ? $snapshot : eventosapp_task_queue_resource_snapshot();
    if ( ! empty($snapshot['memory_percent']) && ((float)$snapshot['memory_percent'] / 100) >= $config['memory_stop_ratio'] ) return true;
    if ( $snapshot['load_1'] !== null ) {
        $max_load = max(1, (int)$snapshot['cpu_cores']) * $config['load_stop_per_core'];
        if ( (float)$snapshot['load_1'] >= $max_load ) return true;
    }
    return false;
}

function eventosapp_task_queue_should_yield($started_at, $processed = 0) {
    $config = eventosapp_task_queue_config();
    if ( $processed > 0 && (microtime(true) - (float)$started_at) >= $config['max_execution_seconds'] ) return true;
    return $processed > 0 && eventosapp_task_queue_resources_busy();
}

/**
 * Batch adaptativo según consumo y rendimiento reciente.
 */
function eventosapp_task_queue_effective_batch_size($task, $adapter) {
    $config = eventosapp_task_queue_config();
    $min = max(1, absint($adapter['min_batch_size'] ?? 1));
    $max = min($config['max_batch_size'], max($min, absint($adapter['max_batch_size'] ?? $config['max_batch_size'])));
    $base = min($max, max($min, absint($task['batch_size'] ?? ($adapter['batch_size'] ?? $config['default_batch_size']))));
    $metrics = is_array($task['resource_metrics'] ?? null) ? $task['resource_metrics'] : [];
    $last = is_array($metrics['last_batch'] ?? null) ? $metrics['last_batch'] : [];

    $memory = (float)($last['after']['memory_percent'] ?? 0);
    $load = $last['after']['load_1'] ?? null;
    $cores = max(1, absint($last['after']['cpu_cores'] ?? eventosapp_task_queue_cpu_cores()));
    $elapsed = (float)($last['elapsed_seconds'] ?? 0);

    if ( $memory >= 70 || ($load !== null && (float)$load >= ($cores * 1.1)) || $elapsed >= 20 ) {
        $base = max($min, (int)floor($base * 0.65));
    } elseif ( $memory > 0 && $memory < 50 && ($load === null || (float)$load < ($cores * 0.75)) && $elapsed > 0 && $elapsed < 8 ) {
        $base = min($max, max($base + 1, (int)ceil($base * 1.20)));
    }

    return max($min, min($max, $base));
}

/**
 * Locks.
 */
function eventosapp_task_queue_lock_key($task_id) {
    return 'eventosapp_task_queue_lock_' . absint($task_id);
}

function eventosapp_task_queue_acquire_lock($task_id) {
    $task_id = absint($task_id);
    $config = eventosapp_task_queue_config();
    $key = eventosapp_task_queue_lock_key($task_id);
    $token = wp_generate_uuid4();
    $value = ['token'=>$token,'expires'=>time()+$config['worker_lock_ttl']];
    if ( add_option($key, $value, '', 'no') ) return $token;
    $current = get_option($key);
    if ( ! is_array($current) || absint($current['expires'] ?? 0) < time() ) {
        delete_option($key);
        if ( add_option($key, $value, '', 'no') ) return $token;
    }
    return '';
}

function eventosapp_task_queue_release_lock($task_id, $token) {
    $key = eventosapp_task_queue_lock_key($task_id);
    $current = get_option($key);
    if ( is_array($current) && hash_equals((string)($current['token'] ?? ''), (string)$token) ) delete_option($key);
}

/**
 * Firma para workers loopback.
 */
function eventosapp_task_queue_worker_token($task_id) {
    return hash_hmac('sha256', 'eventosapp-task-' . absint($task_id), wp_salt('auth'));
}

function eventosapp_task_queue_verify_worker_token($task_id, $token) {
    $expected = eventosapp_task_queue_worker_token($task_id);
    return is_string($token) && hash_equals($expected, $token);
}

function eventosapp_task_queue_dispatch_token() {
    return hash_hmac('sha256', 'eventosapp-task-queue-dispatch', wp_salt('auth'));
}

function eventosapp_task_queue_verify_dispatch_token($token) {
    return is_string($token) && hash_equals(eventosapp_task_queue_dispatch_token(), $token);
}

add_action('rest_api_init', function() {
    register_rest_route('eventosapp/v1', '/task-queue/worker', [
        'methods'             => 'POST',
        'callback'            => function(WP_REST_Request $request) {
            $task_id = absint($request->get_param('task_id'));
            $token = sanitize_text_field((string)$request->get_param('token'));
            if ( ! $task_id || ! eventosapp_task_queue_verify_worker_token($task_id, $token) ) {
                return new WP_Error('eventosapp_task_queue_unauthorized', 'Firma de worker inválida.', ['status'=>403]);
            }
            $result = eventosapp_task_queue_process_task($task_id);
            return rest_ensure_response(['ok'=>!is_wp_error($result),'result'=>is_wp_error($result)?$result->get_error_message():$result]);
        },
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('eventosapp/v1', '/task-queue/dispatch', [
        'methods'             => 'POST',
        'callback'            => function(WP_REST_Request $request) {
            $token = sanitize_text_field((string)$request->get_param('token'));
            if ( ! eventosapp_task_queue_verify_dispatch_token($token) ) {
                return new WP_Error('eventosapp_task_queue_unauthorized', 'Firma de dispatcher inválida.', ['status'=>403]);
            }
            $delay = min(30, max(0, absint($request->get_param('delay'))));
            $dispatch_at = absint($request->get_param('dispatch_at'));
            if ( $delay > 0 ) sleep($delay);
            $lock_key = 'eventosapp_task_queue_loopback_dispatch_lock';
            if ( ! $dispatch_at || absint(get_transient($lock_key)) === $dispatch_at ) {
                delete_transient($lock_key);
            }
            eventosapp_task_queue_dispatch('loopback');
            return rest_ensure_response(['ok'=>true]);
        },
        'permission_callback' => '__return_true',
    ]);
});

function eventosapp_task_queue_spawn_worker($task_id) {
    $task_id = absint($task_id);
    if ( ! $task_id ) return false;
    $url = rest_url('eventosapp/v1/task-queue/worker');
    $response = wp_remote_post($url, [
        'timeout'   => 0.01,
        'blocking'  => false,
        'sslverify' => apply_filters('https_local_ssl_verify', false),
        'body'      => ['task_id'=>$task_id,'token'=>eventosapp_task_queue_worker_token($task_id)],
    ]);
    wp_schedule_single_event(time() + 15, EVENTOSAPP_TASK_QUEUE_WORK_HOOK, [$task_id]);
    return ! is_wp_error($response);
}

/**
 * Activa el dispatcher inmediatamente además del cron de respaldo.
 */
function eventosapp_task_queue_kick($delay = 0) {
    $delay = min(30, max(0, absint($delay)));
    $lock_key = 'eventosapp_task_queue_loopback_dispatch_lock';

    // Respaldo WP-Cron para instalaciones donde los loopbacks estén bloqueados.
    wp_schedule_single_event(time() + max(1, $delay), EVENTOSAPP_TASK_QUEUE_DISPATCH_HOOK, ['kick']);

    $dispatch_at = time() + $delay;
    $current_dispatch_at = absint(get_transient($lock_key));
    if ( $current_dispatch_at && $current_dispatch_at <= $dispatch_at ) return;
    set_transient($lock_key, $dispatch_at, max(5, $delay + 5));

    $response = wp_remote_post(rest_url('eventosapp/v1/task-queue/dispatch'), [
        'timeout'   => 0.01,
        'blocking'  => false,
        'sslverify' => apply_filters('https_local_ssl_verify', false),
        'body'      => [
            'token'       => eventosapp_task_queue_dispatch_token(),
            'delay'       => $delay,
            'dispatch_at' => $dispatch_at,
        ],
    ]);

    if ( is_wp_error($response) ) {
        delete_transient($lock_key);
    }
}

/**
 * Dispatcher justo: toma una sola oportunidad por tarea y respeta concurrencia.
 */
function eventosapp_task_queue_dispatch($reason = '') {
    global $wpdb;
    $config = eventosapp_task_queue_config();
    $table = eventosapp_task_queue_table('tasks');
    $now = current_time('mysql', true);
    $stale = gmdate('Y-m-d H:i:s', time() - $config['heartbeat_stale_seconds']);

    // Recuperar tareas que quedaron running por una caída del worker.
    $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET status='queued', heartbeat_at=NULL, next_run_at=%s, updated_at=%s
         WHERE status='running' AND (heartbeat_at IS NULL OR heartbeat_at < %s)",
        $now,
        $now,
        $stale
    ));

    $active = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE status='running' AND heartbeat_at >= %s",
        $stale
    ));
    $slots = max(0, $config['max_concurrent'] - $active);
    if ( $slots < 1 || eventosapp_task_queue_resources_busy() ) return;

    $limit = min($config['dispatcher_limit'], $slots);
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id FROM {$table}
         WHERE status IN ('queued','scheduled')
           AND (next_run_at IS NULL OR next_run_at <= %s)
           AND (scheduled_at IS NULL OR scheduled_at <= %s)
         ORDER BY priority DESC, COALESCE(next_run_at, created_at) ASC, updated_at ASC
         LIMIT %d",
        $now,
        $now,
        $limit
    ), ARRAY_A);

    foreach ( $rows as $row ) {
        $task_id = absint($row['id'] ?? 0);
        if ( ! $task_id ) continue;

        // Reserva atómica del cupo para impedir que dos dispatchers lancen el
        // mismo trabajo antes de que el loopback alcance a marcarlo running.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status='running', heartbeat_at=%s, updated_at=%s
             WHERE id=%d AND status IN ('queued','scheduled')
               AND (next_run_at IS NULL OR next_run_at <= %s)
               AND (scheduled_at IS NULL OR scheduled_at <= %s)",
            $now,
            $now,
            $task_id,
            $now,
            $now
        ));
        if ( $claimed ) eventosapp_task_queue_spawn_worker($task_id);
    }
}
add_action(EVENTOSAPP_TASK_QUEUE_DISPATCH_HOOK, 'eventosapp_task_queue_dispatch', 10, 1);
add_action(EVENTOSAPP_TASK_QUEUE_WORK_HOOK, 'eventosapp_task_queue_process_task', 10, 1);

/**
 * Procesa exactamente un lote.
 */
function eventosapp_task_queue_process_task($task_id) {
    $task_id = absint($task_id);
    $token = eventosapp_task_queue_acquire_lock($task_id);
    if ( $token === '' ) return new WP_Error('eventosapp_task_queue_busy', 'La tarea ya tiene un worker activo.');

    try {
        $task = eventosapp_task_queue_get($task_id);
        if ( ! $task ) return new WP_Error('eventosapp_task_queue_not_found', 'Tarea no encontrada.');
        if ( in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) || $task['status'] === 'paused' ) return $task;
        if ( $task['next_run_at'] && strtotime($task['next_run_at'] . ' UTC') > time() ) {
            $remaining = max(1, strtotime($task['next_run_at'] . ' UTC') - time());
            eventosapp_task_queue_kick(min(30, $remaining));
            return $task;
        }
        if ( $task['scheduled_at'] && strtotime($task['scheduled_at'] . ' UTC') > time() ) {
            eventosapp_task_queue_update($task_id, ['status'=>'scheduled','next_run_at'=>$task['scheduled_at']]);
            return eventosapp_task_queue_get($task_id);
        }

        $adapter = eventosapp_task_queue_get_adapter($task['task_type']);
        if ( ! $adapter ) {
            eventosapp_task_queue_fail($task_id, 'El adaptador de la tarea ya no está disponible.');
            return new WP_Error('eventosapp_task_queue_adapter_missing', 'Adaptador no disponible.');
        }

        $before = eventosapp_task_queue_resource_snapshot();
        if ( eventosapp_task_queue_resources_busy($before) ) {
            $delay = eventosapp_task_queue_config()['busy_delay_seconds'];
            eventosapp_task_queue_update($task_id, [
                'status'       => 'queued',
                'next_run_at'  => gmdate('Y-m-d H:i:s', time() + $delay),
                'heartbeat_at' => null,
            ]);
            eventosapp_task_queue_add_log($task_id, 'warning', 'El worker cedió el turno por consumo alto del servidor.', $before);
            eventosapp_task_queue_kick($delay);
            return eventosapp_task_queue_get($task_id);
        }

        $now = current_time('mysql', true);
        eventosapp_task_queue_update($task_id, [
            'status'       => 'running',
            'started_at'   => $task['started_at'] ?: $now,
            'heartbeat_at' => $now,
        ]);
        $task = eventosapp_task_queue_get($task_id);
        $batch_size = eventosapp_task_queue_effective_batch_size($task, $adapter);
        $started = microtime(true);

        $result = call_user_func($adapter['process_batch'], $task, [
            'batch_size'   => $batch_size,
            'started_at'   => $started,
            'should_yield' => 'eventosapp_task_queue_should_yield',
        ]);
        if ( ! is_array($result) ) {
            throw new RuntimeException('El adaptador devolvió una respuesta inválida.');
        }

        $processed = max(0, absint($result['processed'] ?? 0));
        $success   = max(0, absint($result['success'] ?? ($result['sent'] ?? 0)));
        $errors    = max(0, absint($result['errors'] ?? 0));
        $skipped   = max(0, absint($result['skipped'] ?? 0));
        $cursor    = array_key_exists('next_cursor', $result) ? max(0, absint($result['next_cursor'])) : ($task['cursor_value'] + $processed);
        $total     = array_key_exists('total_items', $result) ? max(0, absint($result['total_items'])) : $task['total_items'];
        $done      = ! empty($result['done']) || ($total > 0 && $cursor >= $total);
        $fatal     = ! empty($result['fatal']);
        $error_msg = sanitize_text_field((string)($result['error_message'] ?? ''));

        foreach ( (array)($result['logs'] ?? []) as $log ) {
            if ( is_string($log) ) eventosapp_task_queue_add_log($task_id, 'info', $log);
            elseif ( is_array($log) ) eventosapp_task_queue_add_log($task_id, $log['level'] ?? ($log['type'] ?? 'info'), $log['message'] ?? '', $log['context'] ?? []);
        }

        $after = eventosapp_task_queue_resource_snapshot();
        $elapsed = round(microtime(true) - $started, 4);
        $metrics = is_array($task['resource_metrics']) ? $task['resource_metrics'] : [];
        $metrics['last_batch'] = [
            'before'          => $before,
            'after'           => $after,
            'elapsed_seconds' => $elapsed,
            'batch_size'      => $batch_size,
            'processed'       => $processed,
            'success'         => $success,
            'errors'          => $errors,
            'skipped'         => $skipped,
            'items_per_second'=> $elapsed > 0 ? round($processed / $elapsed, 3) : 0,
            'cpu_ms_delta'    => ($before['cpu_ms_total'] !== null && $after['cpu_ms_total'] !== null)
                ? max(0, round((float)$after['cpu_ms_total'] - (float)$before['cpu_ms_total'], 3))
                : null,
        ];
        $metrics['max_memory_percent'] = max((float)($metrics['max_memory_percent'] ?? 0), (float)($after['memory_percent'] ?? 0));
        $metrics['max_memory_peak_percent'] = max((float)($metrics['max_memory_peak_percent'] ?? 0), (float)($after['memory_peak_percent'] ?? 0));
        $metrics['max_load_1'] = max((float)($metrics['max_load_1'] ?? 0), (float)($after['load_1'] ?? 0));
        $metrics['total_worker_seconds'] = round((float)($metrics['total_worker_seconds'] ?? 0) + $elapsed, 4);
        $metrics['batches'] = absint($metrics['batches'] ?? 0) + 1;

        $new_processed = min($total ?: PHP_INT_MAX, $task['processed_items'] + $processed);
        $new_consecutive = ($fatal || ($errors > 0 && $processed === 0)) ? ($task['consecutive_errors'] + 1) : 0;
        $update = [
            'total_items'        => $total,
            'processed_items'    => $new_processed,
            'success_items'      => $task['success_items'] + $success,
            'error_items'        => $task['error_items'] + $errors,
            'skipped_items'      => $task['skipped_items'] + $skipped,
            'cursor_value'       => $cursor,
            'batch_size'         => $batch_size,
            'consecutive_errors' => $new_consecutive,
            'resource_metrics'   => $metrics,
            'last_error'         => $error_msg,
            'heartbeat_at'       => null,
        ];

        /*
         * Una pausa o cancelación puede llegar mientras el adaptador termina el
         * elemento actual. Se vuelve a leer el estado antes de guardar para que
         * el worker nunca sobrescriba una decisión administrativa concurrente.
         */
        $control = eventosapp_task_queue_get($task_id);
        if ( $control && $control['status'] === 'cancelled' ) {
            unset($update['last_error']);
            eventosapp_task_queue_update($task_id, $update);
            eventosapp_task_queue_add_log($task_id, 'info', 'El worker terminó el elemento activo y respetó la cancelación.');
            return eventosapp_task_queue_get($task_id);
        }
        if ( $control && $control['status'] === 'paused' ) {
            $update['status'] = 'paused';
            $update['next_run_at'] = null;
            eventosapp_task_queue_update($task_id, $update);
            eventosapp_task_queue_add_log($task_id, 'info', 'El worker terminó el elemento activo y dejó la tarea pausada.');
            $paused = eventosapp_task_queue_get($task_id);
            eventosapp_task_queue_notify_milestones($paused, false);
            return $paused;
        }

        if ( $fatal || $new_consecutive >= eventosapp_task_queue_config()['max_consecutive_errors'] ) {
            eventosapp_task_queue_update($task_id, $update);
            eventosapp_task_queue_fail($task_id, $error_msg ?: 'La tarea se detuvo después de varios errores consecutivos.');
            return eventosapp_task_queue_get($task_id);
        }

        if ( $done ) {
            $update['status']       = 'completed';
            $update['completed_at'] = current_time('mysql', true);
            $update['next_run_at']  = null;
            eventosapp_task_queue_update($task_id, $update);
            eventosapp_task_queue_add_log($task_id, 'success', 'Tarea finalizada.', [
                'processed'=>$new_processed,
                'success'=>$task['success_items']+$success,
                'errors'=>$task['error_items']+$errors,
                'skipped'=>$task['skipped_items']+$skipped,
            ]);
            $completed = eventosapp_task_queue_get($task_id);
            eventosapp_task_queue_notify_milestones($completed, true);
            do_action('eventosapp_task_queue_completed', $task_id, $completed);
            return $completed;
        }

        $delay = eventosapp_task_queue_next_delay($after, $elapsed, $processed);
        $update['status'] = 'queued';
        $update['next_run_at'] = gmdate('Y-m-d H:i:s', time() + $delay);
        eventosapp_task_queue_update($task_id, $update);
        $updated = eventosapp_task_queue_get($task_id);
        eventosapp_task_queue_notify_milestones($updated, false);
        eventosapp_task_queue_kick($delay);
        return $updated;

    } catch (Throwable $e) {
        eventosapp_task_queue_add_log($task_id, 'error', 'Excepción del worker: ' . $e->getMessage());
        $task = eventosapp_task_queue_get($task_id);
        $errors = $task ? $task['consecutive_errors'] + 1 : 1;
        eventosapp_task_queue_update($task_id, [
            'status'             => 'queued',
            'consecutive_errors' => $errors,
            'last_error'         => $e->getMessage(),
            'heartbeat_at'       => null,
            'next_run_at'        => gmdate('Y-m-d H:i:s', time() + eventosapp_task_queue_config()['busy_delay_seconds']),
        ]);
        if ( $errors >= eventosapp_task_queue_config()['max_consecutive_errors'] ) {
            eventosapp_task_queue_fail($task_id, $e->getMessage());
        } else {
            eventosapp_task_queue_kick(eventosapp_task_queue_config()['busy_delay_seconds']);
        }
        return new WP_Error('eventosapp_task_queue_exception', $e->getMessage());
    } finally {
        eventosapp_task_queue_release_lock($task_id, $token);
    }
}

function eventosapp_task_queue_next_delay($snapshot, $elapsed, $processed) {
    $config = eventosapp_task_queue_config();
    if ( eventosapp_task_queue_resources_busy($snapshot) ) return $config['busy_delay_seconds'];
    if ( $processed > 0 && $elapsed < 5 && (float)($snapshot['memory_percent'] ?? 0) < 55 ) return $config['min_delay_seconds'];
    return $config['normal_delay_seconds'];
}

function eventosapp_task_queue_fail($task_id, $message) {
    $message = sanitize_text_field((string)$message);
    eventosapp_task_queue_update($task_id, [
        'status'       => 'failed',
        'last_error'   => $message,
        'heartbeat_at' => null,
        'completed_at' => current_time('mysql', true),
        'next_run_at'  => null,
    ]);
    eventosapp_task_queue_add_log($task_id, 'error', 'Tarea detenida: ' . $message);
    $task = eventosapp_task_queue_get($task_id);
    eventosapp_task_queue_send_status_email($task, 'failed');
    do_action('eventosapp_task_queue_failed', $task_id, $task);
}

/**
 * Correos de hitos y estados terminales.
 */
function eventosapp_task_queue_notify_milestones($task, $force_complete = false) {
    if ( ! is_array($task) || empty($task['notification_email']) || ! is_email($task['notification_email']) ) return;
    $milestones = is_array($task['milestones']) ? $task['milestones'] : [];
    $percent = $task['total_items'] > 0 ? (int)floor(($task['processed_items'] / $task['total_items']) * 100) : 0;
    if ( $force_complete ) $percent = 100;
    foreach ( [20,40,60,80,100] as $mark ) {
        if ( $percent >= $mark && empty($milestones[(string)$mark]) ) {
            $milestones[(string)$mark] = current_time('mysql', true);
            eventosapp_task_queue_update($task['id'], ['milestones'=>$milestones]);
            $fresh = eventosapp_task_queue_get($task['id']);
            eventosapp_task_queue_send_status_email($fresh, 'milestone', $mark);
        }
    }
}

function eventosapp_task_queue_send_status_email($task, $kind, $milestone = 0) {
    if ( ! is_array($task) || empty($task['notification_email']) || ! is_email($task['notification_email']) ) return false;
    $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $kind = sanitize_key((string)$kind);
    if ( $kind === 'milestone' ) {
        $subject = sprintf('[EventosApp] %d%% completado: %s', absint($milestone), $task['title']);
        $headline = sprintf('La tarea alcanzó el %d%%', absint($milestone));
    } elseif ( $kind === 'cancelled' ) {
        $subject = '[EventosApp] Tarea cancelada: ' . $task['title'];
        $headline = 'La tarea fue cancelada';
    } else {
        $subject = '[EventosApp] Error en tarea: ' . $task['title'];
        $headline = 'La tarea se detuvo por un error';
    }

    $url = add_query_arg(['page'=>'eventosapp_task_queue','task_id'=>$task['id']], admin_url('admin.php'));
    $html = '<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#1d2327">';
    $html .= '<h2>' . esc_html($headline) . '</h2>';
    $html .= '<p><strong>' . esc_html($task['title']) . '</strong></p>';
    $html .= '<table style="border-collapse:collapse;width:100%">';
    $rows = [
        'Estado'     => eventosapp_task_queue_statuses()[$task['status']] ?? $task['status'],
        'Procesados' => number_format_i18n($task['processed_items']) . ' / ' . number_format_i18n($task['total_items']),
        'Exitosos'   => number_format_i18n($task['success_items']),
        'Omitidos'   => number_format_i18n($task['skipped_items']),
        'Errores'    => number_format_i18n($task['error_items']),
        'Evento'     => $task['event_title'] ?: '—',
        'Canal'      => $task['channel'] ?: '—',
    ];
    foreach ( $rows as $label => $value ) {
        $html .= '<tr><td style="padding:8px;border-bottom:1px solid #ddd"><strong>' . esc_html($label) . '</strong></td><td style="padding:8px;border-bottom:1px solid #ddd">' . esc_html($value) . '</td></tr>';
    }
    $html .= '</table>';
    if ( ! empty($task['last_error']) ) $html .= '<p style="color:#b32d2e"><strong>Detalle:</strong> ' . esc_html($task['last_error']) . '</p>';
    $html .= '<p><a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 16px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px">Abrir cola de tareas</a></p>';
    $html .= '<p style="color:#646970;font-size:12px">Notificación automática de ' . esc_html($site) . '.</p></div>';

    return wp_mail($task['notification_email'], $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
}

/**
 * Limpieza automática.
 */
function eventosapp_task_queue_cleanup() {
    global $wpdb;
    $config = eventosapp_task_queue_config();
    $tasks = eventosapp_task_queue_table('tasks');
    $logs = eventosapp_task_queue_table('logs');
    $log_before = gmdate('Y-m-d H:i:s', time() - ($config['log_retention_days'] * DAY_IN_SECONDS));
    $task_before = gmdate('Y-m-d H:i:s', time() - ($config['task_retention_days'] * DAY_IN_SECONDS));
    $wpdb->query($wpdb->prepare("DELETE FROM {$logs} WHERE created_at < %s", $log_before));
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$tasks} WHERE status IN ('completed','cancelled','failed') AND updated_at < %s",
        $task_before
    ));
    foreach ( (array)$ids as $id ) eventosapp_task_queue_delete(absint($id));
}
add_action(EVENTOSAPP_TASK_QUEUE_CLEANUP_HOOK, 'eventosapp_task_queue_cleanup');
