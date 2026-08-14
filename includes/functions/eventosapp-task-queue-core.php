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
    define('EVENTOSAPP_TASK_QUEUE_VERSION', '2026.08.14.1');
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
        'automatic_terminal_cleanup' => false,
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
    $config['automatic_terminal_cleanup'] = ! empty($config['automatic_terminal_cleanup']);
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
        'expired'   => 'Programación vencida',
        'failed'    => 'Error',
        'archived'  => 'Archivada',
    ];
}

function eventosapp_task_queue_terminal_statuses() {
    return ['cancelled', 'completed', 'expired', 'failed', 'archived'];
}

function eventosapp_task_queue_archiveable_statuses() {
    return ['cancelled', 'completed', 'expired', 'failed'];
}

function eventosapp_task_queue_archived_from_status($task) {
    $task = is_array($task) ? $task : [];
    if ( ($task['status'] ?? '') !== 'archived' ) return '';

    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    $archive = is_array($payload['queue_archive'] ?? null) ? $payload['queue_archive'] : [];
    $from_status = sanitize_key((string)($archive['from_status'] ?? ''));
    return in_array($from_status, eventosapp_task_queue_archiveable_statuses(), true) ? $from_status : '';
}

function eventosapp_task_queue_normalize_status($status, $fallback = 'queued') {
    $status = sanitize_key((string)$status);
    return array_key_exists($status, eventosapp_task_queue_statuses()) ? $status : $fallback;
}

/**
 * Zona horaria canónica de un evento.
 *
 * La cola nunca interpreta una hora programada con la zona horaria del
 * servidor. Siempre usa la zona configurada en el evento y solo cae en la
 * zona horaria de WordPress cuando el evento no tiene una zona válida.
 */
function eventosapp_task_queue_event_timezone_object($event_id = 0) {
    $event_id = absint($event_id);

    if ( $event_id && function_exists('eventosapp_get_event_timezone_object') ) {
        try {
            $timezone = eventosapp_get_event_timezone_object($event_id);
            if ( $timezone instanceof DateTimeZone ) return $timezone;
        } catch (Throwable $e) {
            // Se continúa con la lectura directa del metadato.
        }
    }

    $timezone_name = $event_id ? sanitize_text_field((string)get_post_meta($event_id, '_eventosapp_zona_horaria', true)) : '';
    if ( $timezone_name === '' ) $timezone_name = wp_timezone_string();

    try {
        return new DateTimeZone($timezone_name ?: 'UTC');
    } catch (Throwable $e) {
        return wp_timezone();
    }
}

function eventosapp_task_queue_event_timezone_name($event_id = 0) {
    return eventosapp_task_queue_event_timezone_object($event_id)->getName();
}

/**
 * Zona horaria asociada a una tarea.
 *
 * Las tareas nuevas guardan la zona usada al programarlas dentro del payload.
 * Para tareas antiguas se resuelve desde el evento, lo que permite corregirlas
 * sin modificar la estructura de la tabla.
 */
function eventosapp_task_queue_task_timezone_object($task) {
    $task = is_array($task) ? $task : [];
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    $timezone_name = sanitize_text_field((string)($payload['event_timezone'] ?? $payload['timezone'] ?? ''));

    if ( $timezone_name !== '' ) {
        try {
            return new DateTimeZone($timezone_name);
        } catch (Throwable $e) {
            // Si la zona histórica dejó de ser válida, se usa la del evento.
        }
    }

    return eventosapp_task_queue_event_timezone_object(absint($task['event_id'] ?? 0));
}

function eventosapp_task_queue_task_timezone_name($task) {
    return eventosapp_task_queue_task_timezone_object($task)->getName();
}

/**
 * Convierte una fecha/hora escrita por el administrador como hora local del
 * evento a un instante UTC inequívoco.
 */
function eventosapp_task_queue_parse_event_local_datetime($event_id, $date, $time) {
    $event_id = absint($event_id);
    $date = sanitize_text_field((string)$date);
    $time = sanitize_text_field((string)$time);

    if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^\d{2}:\d{2}$/', $time) ) {
        return new WP_Error('eventosapp_task_queue_invalid_datetime', 'La fecha o la hora no tienen un formato válido.');
    }

    $timezone = eventosapp_task_queue_event_timezone_object($event_id);
    $local_value = $date . ' ' . $time;
    $datetime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $local_value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();

    if ( ! $datetime instanceof DateTimeImmutable || ($errors !== false && (! empty($errors['warning_count']) || ! empty($errors['error_count']))) ) {
        return new WP_Error('eventosapp_task_queue_invalid_datetime', 'No se pudo interpretar la fecha y hora en la zona horaria del evento.');
    }

    if ( $datetime->format('Y-m-d H:i') !== $local_value ) {
        return new WP_Error('eventosapp_task_queue_invalid_local_time', 'La hora indicada no existe en la zona horaria del evento por un cambio de horario. Selecciona otra hora.');
    }

    $utc = $datetime->setTimezone(new DateTimeZone('UTC'));
    return [
        'timestamp'  => $datetime->getTimestamp(),
        'timezone'   => $timezone->getName(),
        'utc_offset' => $datetime->format('P'),
        'local'      => $datetime->format('Y-m-d H:i:s'),
        'utc'        => $utc->format('Y-m-d H:i:s'),
    ];
}

/**
 * Devuelve el instante planificado real de una tarea.
 *
 * El valor de la columna scheduled_at es UTC. Las integraciones pueden
 * reconstruir el instante original de tareas antiguas que fueron convertidas
 * erróneamente en "ahora + 5 segundos" mediante el filtro correspondiente.
 */
function eventosapp_task_queue_planned_timestamp($task) {
    $task = is_array($task) ? $task : [];
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];

    if ( ! empty($payload['manual_schedule_override']) && ! empty($payload['planned_timestamp']) ) {
        return max(0, absint($payload['planned_timestamp']));
    }

    $timestamp = 0;
    if ( ! empty($task['scheduled_at']) ) {
        try {
            $timestamp = (new DateTimeImmutable((string)$task['scheduled_at'], new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable $e) {
            $timestamp = 0;
        }
    }

    $timestamp = apply_filters('eventosapp_task_queue_planned_timestamp', $timestamp, $task);
    return max(0, absint($timestamp));
}

function eventosapp_task_queue_format_timestamp_for_task($task, $timestamp = 0, $format = 'd/m/Y H:i:s') {
    $task = is_array($task) ? $task : [];
    $timestamp = absint($timestamp ?: eventosapp_task_queue_planned_timestamp($task));
    if ( ! $timestamp ) return '';

    try {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(eventosapp_task_queue_task_timezone_object($task))
            ->format($format);
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Determina si un instante pertenece a un día anterior en la zona horaria del
 * evento. time() se usa únicamente como instante Unix; nunca como hora local
 * del servidor.
 */
function eventosapp_task_queue_timestamp_is_previous_event_date($timestamp, $event_id = 0, $timezone_name = '') {
    $timestamp = absint($timestamp);
    if ( ! $timestamp ) return false;

    try {
        $timezone = null;
        if ( $timezone_name !== '' ) $timezone = new DateTimeZone(sanitize_text_field((string)$timezone_name));
        if ( ! $timezone instanceof DateTimeZone ) $timezone = eventosapp_task_queue_event_timezone_object($event_id);

        $planned_date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format('Y-m-d');
        $current_date = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
        return $planned_date < $current_date;
    } catch (Throwable $e) {
        return false;
    }
}

function eventosapp_task_queue_task_is_previous_event_date($task) {
    $task = is_array($task) ? $task : [];
    $timestamp = eventosapp_task_queue_planned_timestamp($task);
    return $timestamp > 0 && eventosapp_task_queue_timestamp_is_previous_event_date(
        $timestamp,
        absint($task['event_id'] ?? 0),
        eventosapp_task_queue_task_timezone_name($task)
    );
}


/**
 * Permite terminar una tarea que sí comenzó durante el mismo día local para el
 * que estaba programada. Evita cortar a medianoche una campaña legítima, pero
 * no protege tareas históricas que comenzaron días o meses después.
 */
function eventosapp_task_queue_running_started_on_planned_event_date($task, $planned_timestamp = 0) {
    $task = is_array($task) ? $task : [];
    if ( ($task['status'] ?? '') !== 'running' || empty($task['started_at']) ) return false;

    $planned_timestamp = absint($planned_timestamp ?: eventosapp_task_queue_planned_timestamp($task));
    if ( ! $planned_timestamp ) return false;

    try {
        $timezone = eventosapp_task_queue_task_timezone_object($task);
        $planned_date = (new DateTimeImmutable('@' . $planned_timestamp))->setTimezone($timezone)->format('Y-m-d');
        $started_utc = new DateTimeImmutable((string)$task['started_at'], new DateTimeZone('UTC'));
        $started_date = $started_utc->setTimezone($timezone)->format('Y-m-d');
        return $planned_date === $started_date;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Helpers históricos conservados por compatibilidad. Estos wrappers siguen
 * usando la zona general de WordPress cuando no existe un evento asociado.
 */
function eventosapp_task_queue_site_today_start_utc() {
    try {
        return (new DateTimeImmutable('today', wp_timezone()))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return gmdate('Y-m-d 00:00:00');
    }
}

function eventosapp_task_queue_datetime_is_previous_site_date($datetime) {
    if ( ! $datetime ) return false;
    try {
        $timestamp = (new DateTimeImmutable((string)$datetime, new DateTimeZone('UTC')))->getTimestamp();
        return eventosapp_task_queue_timestamp_is_previous_event_date($timestamp, 0, wp_timezone()->getName());
    } catch (Throwable $e) {
        return false;
    }
}

function eventosapp_task_queue_timestamp_is_previous_site_date($timestamp) {
    return eventosapp_task_queue_timestamp_is_previous_event_date($timestamp, 0, wp_timezone()->getName());
}

/**
 * Normaliza la evidencia que permite reconocer que una programación histórica
 * ya fue ejecutada por la cola o por el sistema heredado que la originó.
 */
function eventosapp_task_queue_execution_evidence($task) {
    $task = is_array($task) ? $task : [];
    $evidence = [
        'executed'        => false,
        'source'          => '',
        'completed_at'    => '',
        'total_items'     => 0,
        'processed_items' => 0,
        'success_items'   => 0,
        'error_items'     => 0,
        'skipped_items'   => 0,
        'message'         => '',
    ];

    $total = absint($task['total_items'] ?? 0);
    $processed = absint($task['processed_items'] ?? 0);
    if ( $total > 0 && $processed >= $total ) {
        $evidence = [
            'executed'        => true,
            'source'          => 'queue_counters',
            'completed_at'    => (string)($task['completed_at'] ?? $task['updated_at'] ?? ''),
            'total_items'     => $total,
            'processed_items' => $processed,
            'success_items'   => absint($task['success_items'] ?? 0),
            'error_items'     => absint($task['error_items'] ?? 0),
            'skipped_items'   => absint($task['skipped_items'] ?? 0),
            'message'         => 'La cola ya había procesado todos los elementos.',
        ];
    }

    $filtered = apply_filters('eventosapp_task_queue_execution_evidence', $evidence, $task);
    $filtered = is_array($filtered) ? wp_parse_args($filtered, $evidence) : $evidence;
    $filtered['executed'] = ! empty($filtered['executed']);
    foreach (['total_items','processed_items','success_items','error_items','skipped_items'] as $key) {
        $filtered[$key] = max(0, absint($filtered[$key] ?? 0));
    }
    $filtered['source'] = sanitize_key((string)($filtered['source'] ?? ''));
    $filtered['completed_at'] = eventosapp_task_queue_normalize_datetime($filtered['completed_at'] ?? '');
    $filtered['message'] = sanitize_text_field((string)($filtered['message'] ?? ''));
    return $filtered;
}

/**
 * Corrige una programación de un día anterior según la zona horaria del evento.
 * Si existe evidencia de ejecución queda Finalizada; de lo contrario queda
 * Programación vencida y nunca vuelve a enviarse automáticamente.
 */
function eventosapp_task_queue_reconcile_scheduled_task($task_id) {
    $task = eventosapp_task_queue_get($task_id);
    if ( ! $task || ($task['task_group'] ?? '') !== 'scheduled' ) return false;
    if ( in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) ) return $task['status'];

    $planned_timestamp = eventosapp_task_queue_planned_timestamp($task);
    if ( ! $planned_timestamp || ! eventosapp_task_queue_task_is_previous_event_date($task) ) return false;

    // Una campaña que empezó correctamente durante el día local programado
    // puede terminar después de medianoche. Las ejecuciones iniciadas tarde,
    // en una fecha local distinta, sí se detienen y quedan vencidas.
    if ( eventosapp_task_queue_running_started_on_planned_event_date($task, $planned_timestamp) ) return false;

    $timezone_name = eventosapp_task_queue_task_timezone_name($task);
    $planned_local = eventosapp_task_queue_format_timestamp_for_task($task, $planned_timestamp);
    $evidence = eventosapp_task_queue_execution_evidence($task);
    $now = current_time('mysql', true);

    if ( ! empty($evidence['executed']) ) {
        $total = max(absint($task['total_items']), absint($evidence['total_items']));
        $processed = max(absint($task['processed_items']), absint($evidence['processed_items']));
        if ( $total > 0 ) $processed = max($processed, $total);

        eventosapp_task_queue_update($task['id'], [
            'status'          => 'completed',
            'total_items'     => $total,
            'processed_items' => $processed,
            'success_items'   => max(absint($task['success_items']), absint($evidence['success_items'])),
            'error_items'     => max(absint($task['error_items']), absint($evidence['error_items'])),
            'skipped_items'   => max(absint($task['skipped_items']), absint($evidence['skipped_items'])),
            'last_error'      => '',
            'next_run_at'     => null,
            'heartbeat_at'    => null,
            'completed_at'    => $evidence['completed_at'] ?: $now,
        ]);
        eventosapp_task_queue_add_log($task['id'], 'success', 'Programación histórica reconocida como ejecutada.', [
            'queue_scheduled_at_utc' => $task['scheduled_at'],
            'planned_timestamp'      => $planned_timestamp,
            'planned_local'          => $planned_local,
            'event_timezone'         => $timezone_name,
            'source'                 => $evidence['source'],
            'message'                => $evidence['message'],
        ]);
        do_action('eventosapp_task_queue_reconciled', $task['id'], 'completed', eventosapp_task_queue_get($task['id']), $evidence);
        return 'completed';
    }

    $message = 'La fecha programada ya pertenece a un día anterior en la zona horaria del evento y no existe evidencia de ejecución. La tarea fue marcada como programación vencida para impedir un envío tardío.';
    eventosapp_task_queue_update($task['id'], [
        'status'       => 'expired',
        'last_error'   => $message,
        'next_run_at'  => null,
        'heartbeat_at' => null,
        'completed_at' => $now,
    ]);
    eventosapp_task_queue_add_log($task['id'], 'warning', $message, [
        'queue_scheduled_at_utc' => $task['scheduled_at'],
        'planned_timestamp'      => $planned_timestamp,
        'planned_local'          => $planned_local,
        'planned_utc'            => gmdate('Y-m-d H:i:s', $planned_timestamp),
        'event_timezone'         => $timezone_name,
    ]);
    do_action('eventosapp_task_queue_expired', $task['id'], eventosapp_task_queue_get($task['id']));
    do_action('eventosapp_task_queue_reconciled', $task['id'], 'expired', eventosapp_task_queue_get($task['id']), $evidence);
    return 'expired';
}

/**
 * Reconciliación gradual para instalaciones que ya tenían muchas tareas.
 *
 * No filtra únicamente por scheduled_at porque versiones anteriores pudieron
 * reemplazar ese valor por la hora actual. Cada adaptador reconstruye el
 * instante planificado real antes de decidir si la tarea está vencida.
 */
function eventosapp_task_queue_reconcile_scheduled_tasks($limit = 200) {
    global $wpdb;

    $limit = min(500, max(1, absint($limit)));
    $lock_key = 'eventosapp_task_queue_reconcile_lock';
    if ( get_transient($lock_key) ) return 0;
    set_transient($lock_key, 1, 30);

    try {
        $table = eventosapp_task_queue_table('tasks');
        $terminal = eventosapp_task_queue_terminal_statuses();
        $placeholders = implode(',', array_fill(0, count($terminal), '%s'));
        $cursor = absint(get_option('eventosapp_task_queue_reconcile_cursor', 0));
        $params = array_merge($terminal, [$cursor, $limit]);

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE task_group='scheduled'
               AND status NOT IN ({$placeholders})
               AND id > %d
             ORDER BY id ASC
             LIMIT %d",
            $params
        ));

        if ( empty($ids) && $cursor > 0 ) {
            $params = array_merge($terminal, [0, $limit]);
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE task_group='scheduled'
                   AND status NOT IN ({$placeholders})
                   AND id > %d
                 ORDER BY id ASC
                 LIMIT %d",
                $params
            ));
        }

        $updated = 0;
        $last_id = 0;
        foreach ( (array)$ids as $task_id ) {
            $last_id = max($last_id, absint($task_id));
            if ( eventosapp_task_queue_reconcile_scheduled_task(absint($task_id)) ) $updated++;
        }

        if ( $last_id > 0 && count((array)$ids) >= $limit ) update_option('eventosapp_task_queue_reconcile_cursor', $last_id, false);
        else delete_option('eventosapp_task_queue_reconcile_cursor');

        return $updated;
    } finally {
        delete_transient($lock_key);
    }
}

add_action('init', function() {
    eventosapp_task_queue_reconcile_scheduled_tasks(200);
}, 45);

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

    $task_group = in_array($args['task_group'], ['massive','scheduled'], true)
        ? $args['task_group']
        : sanitize_key((string)($adapter['group'] ?? 'massive'));
    $payload = is_array($args['payload']) ? $args['payload'] : [];

    $scheduled_at = eventosapp_task_queue_normalize_datetime($args['scheduled_at']);
    $next_run_at  = eventosapp_task_queue_normalize_datetime($args['next_run_at']);
    $status = eventosapp_task_queue_normalize_status($args['status']);
    if ( $scheduled_at && strtotime($scheduled_at . ' UTC') > time() ) {
        $status = 'scheduled';
        if ( ! $next_run_at ) $next_run_at = $scheduled_at;
    }
    if ( ! $next_run_at ) $next_run_at = current_time('mysql', true);

    if ( $task_group === 'scheduled' ) {
        $planned_timestamp = ! empty($payload['planned_timestamp'])
            ? absint($payload['planned_timestamp'])
            : ($scheduled_at ? (strtotime($scheduled_at . ' UTC') ?: 0) : 0);
        $timezone = eventosapp_task_queue_event_timezone_object($event_id);
        if ( empty($payload['event_timezone']) ) $payload['event_timezone'] = $timezone->getName();
        if ( $planned_timestamp > 0 && empty($payload['planned_timestamp']) ) $payload['planned_timestamp'] = $planned_timestamp;
        if ( $planned_timestamp > 0 && empty($payload['scheduled_utc']) ) $payload['scheduled_utc'] = gmdate('Y-m-d H:i:s', $planned_timestamp);
        if ( $planned_timestamp > 0 && empty($payload['scheduled_local']) ) {
            $payload['scheduled_local'] = (new DateTimeImmutable('@' . $planned_timestamp))->setTimezone($timezone)->format('Y-m-d H:i:s');
        }
    }

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
            'task_group'         => $task_group,
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
            'payload'            => eventosapp_task_queue_json_encode($payload),
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
        'task_type'      => $task_type,
        'status'         => $status,
        'total'          => (int)$args['total_items'],
        'event_timezone' => $task_group === 'scheduled' ? ($payload['event_timezone'] ?? '') : '',
        'scheduled_utc'  => $scheduled_at,
        'scheduled_local'=> $payload['scheduled_local'] ?? '',
    ]);

    if ( $task_group === 'scheduled' ) eventosapp_task_queue_reconcile_scheduled_task($task_id);

    do_action('eventosapp_task_queue_created', $task_id, eventosapp_task_queue_get($task_id));
    eventosapp_task_queue_kick();
    return $task_id;
}

function eventosapp_task_queue_normalize_datetime($value) {
    if ( $value === null || $value === '' || $value === false ) return null;

    if ( $value instanceof DateTimeInterface ) {
        return gmdate('Y-m-d H:i:s', $value->getTimestamp());
    }

    if ( is_numeric($value) ) {
        $timestamp = (int)$value;
        return $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) : null;
    }

    $value = trim(sanitize_text_field((string)$value));
    if ( $value === '' ) return null;

    try {
        /*
         * El contrato interno de la cola es UTC. Una cadena sin offset se
         * interpreta explícitamente como UTC, nunca con date.timezone del
         * servidor PHP.
         */
        $datetime = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
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
        'status_scope'=> '',
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
    if ( $args['status'] !== '' ) {
        $where[] = 'status = %s';
        $params[] = eventosapp_task_queue_normalize_status($args['status']);
    } elseif ( $args['status_scope'] === 'unarchived' ) {
        $where[] = 'status <> %s';
        $params[] = 'archived';
    } elseif ( $args['status_scope'] === 'open' ) {
        $terminal = eventosapp_task_queue_terminal_statuses();
        $where[] = 'status NOT IN (' . implode(',', array_fill(0, count($terminal), '%s')) . ')';
        $params = array_merge($params, $terminal);
    } elseif ( $args['status_scope'] === 'terminal' ) {
        $terminal = eventosapp_task_queue_terminal_statuses();
        $where[] = 'status IN (' . implode(',', array_fill(0, count($terminal), '%s')) . ')';
        $params = array_merge($params, $terminal);
    }
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


/**
 * Archiva manualmente un registro terminal sin eliminar sus resultados, logs
 * ni métricas. La tarea deja de aparecer en la vista operativa predeterminada,
 * pero continúa disponible mediante el filtro Archivada o Todas las tareas.
 */
function eventosapp_task_queue_archive($task_id) {
    $task_id = absint($task_id);
    $task = eventosapp_task_queue_get($task_id);
    if ( ! $task || ! in_array($task['status'], eventosapp_task_queue_archiveable_statuses(), true) ) return false;

    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    $payload['queue_archive'] = [
        'from_status' => $task['status'],
        'archived_at' => current_time('mysql', true),
        'archived_by' => get_current_user_id(),
    ];

    $updated = eventosapp_task_queue_update($task_id, [
        'status'       => 'archived',
        'next_run_at'  => null,
        'heartbeat_at' => null,
        'payload'      => $payload,
    ]);
    if ( ! $updated ) return false;

    eventosapp_task_queue_add_log($task_id, 'info', 'Tarea archivada manualmente.', [
        'from_status' => $task['status'],
        'archived_by' => get_current_user_id(),
    ]);
    do_action('eventosapp_task_queue_archived', $task_id, eventosapp_task_queue_get($task_id), $task['status']);
    return true;
}

function eventosapp_task_queue_archive_many($task_ids) {
    $task_ids = is_array($task_ids)
        ? array_values(array_filter(array_unique(array_map('absint', $task_ids))))
        : [];
    if ( empty($task_ids) ) return ['archived'=>0, 'skipped'=>0, 'requested'=>0];

    $result = ['archived'=>0, 'skipped'=>0, 'requested'=>count($task_ids)];
    foreach ( $task_ids as $task_id ) {
        if ( eventosapp_task_queue_archive($task_id) ) $result['archived']++;
        else $result['skipped']++;
    }
    return $result;
}

function eventosapp_task_queue_reschedule($task_id, $datetime, $context = []) {
    $task = eventosapp_task_queue_get($task_id);
    if ( ! $task || $task['status'] === 'completed' ) return false;

    $scheduled = eventosapp_task_queue_normalize_datetime($datetime);
    if ( ! $scheduled ) return false;
    $timestamp = strtotime($scheduled . ' UTC') ?: 0;
    if ( $timestamp <= time() ) return false;

    $context = is_array($context) ? $context : [];
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    $timezone_name = sanitize_text_field((string)($context['timezone'] ?? eventosapp_task_queue_task_timezone_name($task)));
    try {
        $timezone = new DateTimeZone($timezone_name ?: eventosapp_task_queue_event_timezone_name(absint($task['event_id'] ?? 0)));
    } catch (Throwable $e) {
        $timezone = eventosapp_task_queue_event_timezone_object(absint($task['event_id'] ?? 0));
        $timezone_name = $timezone->getName();
    }

    if ( empty($payload['source_planned_timestamp']) ) {
        $payload['source_planned_timestamp'] = eventosapp_task_queue_planned_timestamp($task);
    }
    $payload['manual_schedule_override'] = 1;
    $payload['planned_timestamp'] = $timestamp;
    $payload['event_timezone'] = $timezone_name;
    $payload['scheduled_utc'] = gmdate('Y-m-d H:i:s', $timestamp);
    $payload['scheduled_local'] = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format('Y-m-d H:i:s');
    $payload['utc_offset'] = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format('P');

    eventosapp_task_queue_update($task_id, [
        'status'       => 'scheduled',
        'scheduled_at' => $scheduled,
        'next_run_at'  => $scheduled,
        'completed_at' => null,
        'heartbeat_at' => null,
        'last_error'   => '',
        'payload'      => $payload,
    ]);
    eventosapp_task_queue_add_log($task_id, 'info', 'Tarea reprogramada en la zona horaria del evento.', [
        'scheduled_local' => $payload['scheduled_local'],
        'event_timezone'  => $timezone_name,
        'utc_offset'      => $payload['utc_offset'],
        'scheduled_utc'   => $scheduled,
    ]);
    eventosapp_task_queue_kick();
    return true;
}

function eventosapp_task_queue_delete($task_id) {
    global $wpdb;
    $task_id = absint($task_id);
    $task = eventosapp_task_queue_get($task_id);
    if ( ! $task || ! in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) ) return false;

    // Un worker puede estar terminando el elemento que ya había comenzado antes
    // de la cancelación o vencimiento. En ese caso se difiere el borrado para
    // impedir que el proceso escriba sobre un registro eliminado.
    if ( function_exists('eventosapp_task_queue_has_active_lock') && eventosapp_task_queue_has_active_lock($task_id) ) {
        return eventosapp_task_queue_schedule_pending_deletion($task_id);
    }

    $wpdb->delete(eventosapp_task_queue_table('logs'), ['task_id'=>$task_id], ['%d']);
    return false !== $wpdb->delete(eventosapp_task_queue_table('tasks'), ['id'=>$task_id], ['%d']);
}

/**
 * Indica si un worker conserva un lock activo para la tarea.
 */
function eventosapp_task_queue_has_active_lock($task_id) {
    $key = eventosapp_task_queue_lock_key($task_id);
    $lock = get_option($key);
    if ( ! is_array($lock) ) return false;
    if ( absint($lock['expires'] ?? 0) < time() ) {
        delete_option($key);
        return false;
    }
    return true;
}

function eventosapp_task_queue_pending_deletions() {
    $pending = get_option('eventosapp_task_queue_pending_deletions', []);
    return is_array($pending) ? $pending : [];
}

function eventosapp_task_queue_schedule_pending_deletion($task_id) {
    $task_id = absint($task_id);
    if ( ! $task_id ) return false;
    $pending = eventosapp_task_queue_pending_deletions();
    $pending[(string)$task_id] = time();
    update_option('eventosapp_task_queue_pending_deletions', $pending, false);
    return true;
}

/**
 * Borra tareas cuya eliminación quedó esperando a que terminara el elemento
 * activo del worker. Nunca elimina una tarea mientras conserve un lock válido.
 */
function eventosapp_task_queue_process_pending_deletions($limit = 100) {
    $limit = min(500, max(1, absint($limit)));
    $pending = eventosapp_task_queue_pending_deletions();
    if ( empty($pending) ) return 0;

    $deleted = 0;
    $processed = 0;
    foreach ( array_keys($pending) as $task_id ) {
        if ( $processed >= $limit ) break;
        $processed++;
        $task_id = absint($task_id);
        $task = eventosapp_task_queue_get($task_id);

        if ( ! $task ) {
            unset($pending[(string)$task_id]);
            continue;
        }
        if ( ! in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) ) continue;
        if ( eventosapp_task_queue_has_active_lock($task_id) ) continue;

        if ( eventosapp_task_queue_delete($task_id) ) {
            unset($pending[(string)$task_id]);
            $deleted++;
        }
    }

    if ( empty($pending) ) delete_option('eventosapp_task_queue_pending_deletions');
    else update_option('eventosapp_task_queue_pending_deletions', $pending, false);
    return $deleted;
}
add_action('init', function() { eventosapp_task_queue_process_pending_deletions(100); }, 110);

/**
 * Cancela y elimina varias tareas de forma segura.
 *
 * Las tareas activas se cancelan primero. Si un worker está terminando un
 * elemento, la eliminación queda diferida hasta que libere su lock; de esta
 * forma no se corrompe el cursor ni se deja un proceso escribiendo sobre un
 * registro que ya no existe.
 */
function eventosapp_task_queue_delete_many($task_ids) {
    $task_ids = is_array($task_ids)
        ? array_values(array_filter(array_unique(array_map('absint', $task_ids))))
        : [];
    if ( empty($task_ids) ) return ['deleted'=>0, 'pending'=>0, 'cancelled'=>0, 'skipped'=>0, 'requested'=>0];

    $result = ['deleted'=>0, 'pending'=>0, 'cancelled'=>0, 'skipped'=>0, 'requested'=>count($task_ids)];

    foreach ( $task_ids as $task_id ) {
        $task = eventosapp_task_queue_get($task_id);
        if ( ! $task ) {
            $result['skipped']++;
            continue;
        }

        if ( ! in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) ) {
            if ( eventosapp_task_queue_cancel($task_id, 'Cancelada para eliminación masiva desde Cola y Tareas.') ) {
                $result['cancelled']++;
            }
            $task = eventosapp_task_queue_get($task_id);
        }

        if ( ! $task || ! in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) ) {
            $result['skipped']++;
            continue;
        }

        if ( eventosapp_task_queue_has_active_lock($task_id) ) {
            eventosapp_task_queue_schedule_pending_deletion($task_id);
            $result['pending']++;
            continue;
        }

        if ( eventosapp_task_queue_delete($task_id) ) $result['deleted']++;
        else $result['skipped']++;
    }

    return $result;
}

function eventosapp_task_queue_delete_terminal($status = '') {
    global $wpdb;
    $statuses = $status !== '' ? [eventosapp_task_queue_normalize_status($status)] : eventosapp_task_queue_terminal_statuses();
    $statuses = array_values(array_intersect($statuses, eventosapp_task_queue_terminal_statuses()));
    if ( empty($statuses) ) return 0;

    $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
    $tasks = eventosapp_task_queue_table('tasks');
    $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$tasks} WHERE status IN ({$placeholders})", $statuses));
    if ( empty($ids) ) return 0;

    $result = eventosapp_task_queue_delete_many(array_map('absint', $ids));
    return absint($result['deleted'] ?? 0);
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

/**
 * Cuenta CPUs descritas como rangos Linux, por ejemplo "0-3" o "0-1,4-5".
 */
function eventosapp_task_queue_count_cpu_range($value) {
    $value = trim((string)$value);
    if ( $value === '' ) return 0;

    $count = 0;
    foreach ( explode(',', $value) as $part ) {
        $part = trim($part);
        if ( $part === '' ) continue;

        if ( preg_match('/^(\d+)-(\d+)$/', $part, $matches) ) {
            $from = absint($matches[1]);
            $to   = absint($matches[2]);
            if ( $to >= $from ) $count += ($to - $from + 1);
        } elseif ( ctype_digit($part) ) {
            $count++;
        }
    }

    return max(0, $count);
}

/**
 * Detecta las vCPU realmente disponibles para PHP.
 *
 * La implementación anterior dependía de NUMBER_OF_PROCESSORS o /proc/cpuinfo.
 * En algunos entornos PHP-FPM/cgroup cualquiera de esas fuentes puede devolver
 * 1 o no estar disponible, aun cuando el VPS tenga varias vCPU. Ese falso
 * "1 core" reducía max_concurrent a 1 y convertía load_stop_per_core en un
 * umbral global demasiado bajo.
 */
function eventosapp_task_queue_cpu_cores() {
    /*
     * Se cachea solo la detección física/cgroup. El filtro se aplica en cada
     * llamada porque los perfiles del plugin pueden registrarse después de que
     * otro include haya consultado este helper durante el bootstrap.
     */
    static $detected_cores = null;

    if ( $detected_cores === null ) {
        $cores = 0;
        $constrained = [];

        // cgroup cpuset: suele ser la fuente más fiel cuando PHP está aislado.
        foreach ([
            '/sys/fs/cgroup/cpuset.cpus.effective',
            '/sys/fs/cgroup/cpuset/cpuset.cpus',
        ] as $path) {
            if ( ! is_readable($path) ) continue;
            $value = @file_get_contents($path);
            $count = is_string($value) ? eventosapp_task_queue_count_cpu_range($value) : 0;
            if ( $count > 0 ) $constrained[] = $count;
        }

        // cgroup v2 quota: "quota period". Si quota=max no impone límite.
        if ( is_readable('/sys/fs/cgroup/cpu.max') ) {
            $cpu_max = trim((string)@file_get_contents('/sys/fs/cgroup/cpu.max'));
            if ( preg_match('/^(\d+)\s+(\d+)$/', $cpu_max, $matches) ) {
                $quota  = max(1, (int)$matches[1]);
                $period = max(1, (int)$matches[2]);
                $quota_cores = max(1, (int)ceil($quota / $period));
                $constrained[] = $quota_cores;
            }
        }

        if ( ! empty($constrained) ) {
            $cores = min($constrained);
        }

        // Bare metal / VPS sin restricciones cgroup.
        if ( ! $cores && is_readable('/sys/devices/system/cpu/online') ) {
            $online = @file_get_contents('/sys/devices/system/cpu/online');
            $cores = is_string($online) ? eventosapp_task_queue_count_cpu_range($online) : 0;
        }

        if ( ! $cores && is_readable('/proc/cpuinfo') ) {
            $content = @file_get_contents('/proc/cpuinfo');
            if ( is_string($content) ) {
                $cores = max(0, preg_match_all('/^processor\s*:/m', $content));
            }
        }

        if ( ! $cores ) {
            $cores = absint(getenv('NUMBER_OF_PROCESSORS'));
        }
        if ( ! $cores ) $cores = 1;

        $detected_cores = min(256, max(1, (int)$cores));
    }

    /**
     * Permite que un perfil de infraestructura conocido corrija una detección
     * incompleta de PHP-FPM sin tener que duplicar el gobernador de recursos.
     * Se aplica siempre, incluso si la detección base ya quedó cacheada.
     */
    $cores = (int)apply_filters('eventosapp_task_queue_cpu_cores', $detected_cores);
    return min(256, max(1, $cores));
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

/**
 * Evalúa presión real de recursos y devuelve las razones que la activaron.
 *
 * Para carga se evita frenar por un pico instantáneo de load_1: se exige que
 * load_5 confirme presión sostenida, salvo que load_1 supere claramente el
 * umbral. Esto es especialmente importante en procesos con I/O y APIs remotas,
 * donde loadavg puede subir sin que CPU o RAM estén realmente agotadas.
 */
function eventosapp_task_queue_resource_pressure($snapshot = null) {
    $config = eventosapp_task_queue_config();
    $snapshot = is_array($snapshot) ? $snapshot : eventosapp_task_queue_resource_snapshot();

    $cores = max(1, absint($snapshot['cpu_cores'] ?? eventosapp_task_queue_cpu_cores()));
    $memory_percent = (float)($snapshot['memory_percent'] ?? 0);
    $memory_limit_percent = round($config['memory_stop_ratio'] * 100, 2);

    $load_1 = array_key_exists('load_1', $snapshot) && $snapshot['load_1'] !== null
        ? (float)$snapshot['load_1']
        : null;
    $load_5 = array_key_exists('load_5', $snapshot) && $snapshot['load_5'] !== null
        ? (float)$snapshot['load_5']
        : null;
    $load_limit = round($cores * $config['load_stop_per_core'], 2);
    $load_confirm_limit = round($load_limit * 0.80, 2);
    $load_hard_limit = round($load_limit * 1.35, 2);

    $reasons = [];

    if ( $memory_percent > 0 && $memory_percent >= $memory_limit_percent ) {
        $reasons[] = 'memory';
    }

    if ( $load_1 !== null && $load_1 >= $load_limit ) {
        $sustained = $load_5 === null || $load_5 >= $load_confirm_limit;
        $severe = $load_1 >= $load_hard_limit;
        if ( $sustained || $severe ) {
            $reasons[] = $severe ? 'load_severe' : 'load_sustained';
        }
    }

    return [
        'busy'    => ! empty($reasons),
        'reasons' => $reasons,
        'thresholds' => [
            'memory_percent'    => $memory_limit_percent,
            'load_1'            => $load_limit,
            'load_5_confirm'    => $load_confirm_limit,
            'load_1_hard'       => $load_hard_limit,
            'cpu_cores'         => $cores,
        ],
    ];
}

function eventosapp_task_queue_resources_busy($snapshot = null) {
    $pressure = eventosapp_task_queue_resource_pressure($snapshot);
    return ! empty($pressure['busy']);
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

    $metrics = is_array($task['resource_metrics'] ?? null) ? $task['resource_metrics'] : [];
    $configured = absint($metrics['configured_batch_size'] ?? 0);
    if ( ! $configured ) {
        $configured = absint($task['batch_size'] ?? ($adapter['batch_size'] ?? $config['default_batch_size']));
    }
    $configured = min($max, max($min, $configured));

    $base = min($max, max($min, absint($task['batch_size'] ?? $configured)));
    $last = is_array($metrics['last_batch'] ?? null) ? $metrics['last_batch'] : [];
    $after = is_array($last['after'] ?? null) ? $last['after'] : [];

    $memory = (float)($after['memory_percent'] ?? 0);
    $load = array_key_exists('load_1', $after) && $after['load_1'] !== null ? (float)$after['load_1'] : null;
    $cores = max(1, absint($after['cpu_cores'] ?? eventosapp_task_queue_cpu_cores()));
    $elapsed = (float)($last['elapsed_seconds'] ?? 0);
    $cpu_ms_delta = array_key_exists('cpu_ms_delta', $last) && $last['cpu_ms_delta'] !== null
        ? (float)$last['cpu_ms_delta']
        : null;
    $worker_cpu_ratio = ($elapsed > 0 && $cpu_ms_delta !== null)
        ? max(0, min(1.50, $cpu_ms_delta / ($elapsed * 1000)))
        : null;

    $pressure = ! empty($after)
        ? eventosapp_task_queue_resource_pressure($after)
        : ['busy'=>false, 'thresholds'=>[]];

    $memory_soft_limit = max(65, ((float)($pressure['thresholds']['memory_percent'] ?? 86)) - 8);
    $load_soft_limit = max(1, $cores * max(1.0, ((float)$config['load_stop_per_core'] * 0.80)));

    /*
     * La duración por sí sola ya no reduce el batch. Un envío puede tardar por
     * SMTP/Meta/Wallet sin estar consumiendo CPU o RAM local. El presupuesto de
     * ejecución ya corta el lote mediante should_yield(); degradar además el
     * tamaño persistente provocaba el efecto acumulativo observado en producción.
     */
    $local_pressure =
        ! empty($pressure['busy'])
        || ($memory > 0 && $memory >= $memory_soft_limit)
        || (
            $load !== null
            && $load >= $load_soft_limit
            && ($worker_cpu_ratio === null || $worker_cpu_ratio >= 0.70)
        );

    if ( $local_pressure ) {
        $base = max($min, (int)floor($base * 0.80));
    } elseif ( $base < $configured ) {
        // Recuperación gradual hacia el batch configurado después de un pico.
        $base = min($configured, max($base + 1, (int)ceil($base * 1.25)));
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

    // Antes de reservar workers se depuran programaciones de días anteriores.
    eventosapp_task_queue_reconcile_scheduled_tasks($config['dispatcher_limit'] * 4);

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

        if ( ($task['task_group'] ?? '') === 'scheduled' ) {
            eventosapp_task_queue_reconcile_scheduled_task($task_id);
            $task = eventosapp_task_queue_get($task_id);
            if ( ! $task ) return new WP_Error('eventosapp_task_queue_not_found', 'Tarea no encontrada.');
        }

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
        $pressure_before = eventosapp_task_queue_resource_pressure($before);
        if ( ! empty($pressure_before['busy']) ) {
            $delay = eventosapp_task_queue_config()['busy_delay_seconds'];
            eventosapp_task_queue_update($task_id, [
                'status'       => 'queued',
                'next_run_at'  => gmdate('Y-m-d H:i:s', time() + $delay),
                'heartbeat_at' => null,
            ]);

            $busy_context = array_merge($before, [
                'busy_reasons' => $pressure_before['reasons'] ?? [],
                'thresholds'   => $pressure_before['thresholds'] ?? [],
                'retry_seconds'=> $delay,
            ]);
            eventosapp_task_queue_add_log(
                $task_id,
                'warning',
                'El worker cedió el turno por presión real de recursos.',
                $busy_context
            );
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
        if ( empty($metrics['configured_batch_size']) ) {
            $metrics['configured_batch_size'] = max(
                1,
                absint($task['batch_size'] ?? ($adapter['batch_size'] ?? eventosapp_task_queue_config()['default_batch_size']))
            );
        }
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
        if ( $control && in_array($control['status'], eventosapp_task_queue_terminal_statuses(), true) ) {
            unset($update['last_error'], $update['status'], $update['completed_at'], $update['next_run_at']);
            eventosapp_task_queue_update($task_id, $update);
            eventosapp_task_queue_add_log($task_id, 'info', 'El worker terminó el elemento activo y respetó el estado terminal administrativo: ' . $control['status'] . '.');
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

        if ( $task && (in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) || $task['status'] === 'paused') ) {
            return new WP_Error('eventosapp_task_queue_exception', $e->getMessage());
        }

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
        eventosapp_task_queue_process_pending_deletions(25);
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
 * Mantenimiento automático conservador.
 *
 * Por defecto nunca elimina tareas terminales ni sus logs. Los registros solo
 * salen de la base de datos mediante una acción administrativa explícita. Se
 * limpian únicamente logs huérfanos y eliminaciones manuales que quedaron a la
 * espera de que un worker liberara su lock.
 *
 * La limpieza histórica puede reactivarse expresamente con el filtro de
 * configuración `automatic_terminal_cleanup`, manteniendo compatibilidad con
 * instalaciones que realmente necesiten una retención automática.
 */
function eventosapp_task_queue_cleanup() {
    global $wpdb;

    eventosapp_task_queue_process_pending_deletions(500);

    $config = eventosapp_task_queue_config();
    $tasks = eventosapp_task_queue_table('tasks');
    $logs = eventosapp_task_queue_table('logs');

    // Elimina únicamente logs cuyo registro padre ya no existe.
    $wpdb->query("DELETE task_logs FROM {$logs} task_logs LEFT JOIN {$tasks} tasks ON tasks.id = task_logs.task_id WHERE tasks.id IS NULL");

    if ( empty($config['automatic_terminal_cleanup']) ) return;

    $task_before = gmdate('Y-m-d H:i:s', time() - ($config['task_retention_days'] * DAY_IN_SECONDS));
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$tasks} WHERE status IN ('completed','cancelled','expired','failed','archived') AND updated_at < %s",
        $task_before
    ));
    foreach ( (array)$ids as $id ) eventosapp_task_queue_delete(absint($id));
}
add_action(EVENTOSAPP_TASK_QUEUE_CLEANUP_HOOK, 'eventosapp_task_queue_cleanup');
