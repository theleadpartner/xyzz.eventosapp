<?php
if (!defined('ABSPATH')) exit;

function evapp_tools_perf_register_queue_adapters(){
    if (!function_exists('eventosapp_task_queue_register_adapter')) return;

    eventosapp_task_queue_register_adapter('ticket_import', [
        'label'=>'Importación masiva de tickets',
        'group'=>'massive',
        'channel'=>'tickets',
        'batch_size'=>60,
        'min_batch_size'=>30,
        'max_batch_size'=>80,
        'process_batch'=>'evapp_tools_perf_task_queue_process_batch',
    ]);

    eventosapp_task_queue_register_adapter('ticket_import_assets', [
        'label'=>'Generación paralela de anexos de tickets',
        'group'=>'massive',
        'channel'=>'tickets',
        'batch_size'=>10,
        'min_batch_size'=>4,
        'max_batch_size'=>16,
        'process_batch'=>'evapp_tools_perf_assets_process_batch',
    ]);
}
add_action('init', 'evapp_tools_perf_register_queue_adapters', 60);

function evapp_tools_perf_request_state(){
    if (!current_user_can('manage_options')) return ['', null];

    $event_id = intval($_POST['event_id'] ?? 0);
    $hash = sanitize_text_field($_POST['hash'] ?? '');
    $task_id = sanitize_text_field($_POST['task_id'] ?? '');
    $nonce = $_POST['_wpnonce'] ?? '';
    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) return ['', null];

    $key = evapp_import_background_resolve_state_key($event_id, $hash, $task_id);
    $state = get_option($key);
    return is_array($state) ? [$key, $state] : ['', null];
}

function evapp_tools_perf_before_stop(){
    [, $state] = evapp_tools_perf_request_state();
    if (is_array($state)) evapp_tools_perf_control_asset_tasks($state, 'pause');
}
add_action('wp_ajax_eventosapp_import_stop', 'evapp_tools_perf_before_stop', 5);

function evapp_tools_perf_before_cancel(){
    [, $state] = evapp_tools_perf_request_state();
    if (is_array($state)) evapp_tools_perf_control_asset_tasks($state, 'cancel');
}
add_action('wp_ajax_eventosapp_import_cancel', 'evapp_tools_perf_before_cancel', 5);

function evapp_tools_perf_before_background(){
    [, $state] = evapp_tools_perf_request_state();
    if (is_array($state)) evapp_tools_perf_control_asset_tasks($state, 'resume');
}
add_action('wp_ajax_eventosapp_import_background', 'evapp_tools_perf_before_background', 5);

function evapp_tools_perf_before_browser_resume(){
    [, $state] = evapp_tools_perf_request_state();
    if (!is_array($state)) return;

    $snapshot = evapp_tools_perf_asset_snapshot($state);
    if (!empty($snapshot['tasks_total']) && empty($snapshot['done'])) {
        wp_send_json_error('La fase de datos ya terminó. Reanuda desde Cola y Tareas para completar los anexos.', 409);
    }
}
add_action('wp_ajax_eventosapp_import_resume', 'evapp_tools_perf_before_browser_resume', 5);

function evapp_tools_perf_before_confirm(){
    if (!current_user_can('manage_options')) return;
    $event_id = intval($_POST['event_id'] ?? 0);
    $hash = sanitize_text_field($_POST['hash'] ?? '');
    $nonce = $_POST['_wpnonce'] ?? '';
    if (!$event_id || !$hash || !wp_verify_nonce($nonce, 'evapp_tools_'.$event_id)) return;

    $key = evapp_import_state_key($event_id, $hash);
    $state = get_option($key);
    if (!is_array($state)) return;

    evapp_tools_perf_control_asset_tasks($state, 'cancel');
    foreach ([
        'asset_ticket_ids','asset_tasks','asset_task_ids','asset_shards_total',
        'asset_pipeline_version','assets_status','assets_processed','assets_success',
        'assets_errors','assets_skipped','assets_total','asset_parent_processed_accounted',
        'asset_parent_success_accounted','asset_parent_errors_accounted','asset_parent_skipped_accounted'
    ] as $state_key) {
        unset($state[$state_key]);
    }
    update_option($key, $state, false);
}
add_action('wp_ajax_eventosapp_import_confirm', 'evapp_tools_perf_before_confirm', 5);

/**
 * Ajustes visuales limitados al asistente de Herramientas.
 */
function evapp_tools_perf_admin_hint(){
    if (!is_admin() || !current_user_can('manage_options')) return;
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ($page !== 'eventosapp_tools') return;
    ?>
    <script id="eventosapp-tools-import-performance-hint">
    (function(){
        var background = document.getElementById('evapp_background_import');
        if (background) background.textContent = 'Enviar a Cola y Tareas · modo rápido';

        var batch = document.querySelector('input[name="batch_size"]');
        if (batch && String(batch.value) === '20') {
            batch.value = '40';
            var holder = batch.closest('p');
            if (holder) holder.innerHTML = holder.innerHTML.replace('Recomendado: 20.', 'Recomendado: 40 para Cola y Tareas.');
        }

        var start = document.getElementById('evapp_start_import');
        if (start && !document.getElementById('evapp-tools-perf-note')) {
            var note = document.createElement('p');
            note.id = 'evapp-tools-perf-note';
            note.style.cssText = 'background:#ecfdf5;border-left:4px solid #16a34a;padding:9px 11px;margin:0 0 14px;';
            note.innerHTML = '<strong>Modo rápido:</strong> guarda primero los datos del CSV y luego distribuye QR/PDF/ICS/Wallet/WhatsApp en hasta tres tareas protegidas por Cola y Tareas. El modo “en esta ventana” conserva el procesamiento histórico.';
            start.parentNode.parentNode.insertBefore(note, start.parentNode);
        }
    })();
    </script>
    <?php
}
add_action('admin_footer', 'evapp_tools_perf_admin_hint', 1010);

/**
 * Perfil global de rendimiento de Cola y Tareas para el KVM 4 vCPU / 16 GB.
 *
 * Se mantiene en esta capa de controles porque este archivo ya se carga en
 * todas las ejecuciones del plugin desde eventosapp-herramientas.php, incluidas
 * las llamadas REST de los workers. No reemplaza motores de envío; utiliza el
 * filtro y el registro público de adaptadores de la cola central.
 */
if (!defined('EVENTOSAPP_TASK_QUEUE_PERFORMANCE_PROFILE_VERSION')) {
    define('EVENTOSAPP_TASK_QUEUE_PERFORMANCE_PROFILE_VERSION', '2026.08.14.1');
}

if (!defined('EVENTOSAPP_TASK_QUEUE_PROFILE_CPU_CORES')) {
    define('EVENTOSAPP_TASK_QUEUE_PROFILE_CPU_CORES', 4);
}

/**
 * Este repositorio está calibrado para el KVM de 4 vCPU. Si PHP-FPM/cgroup
 * informa menos cores de los realmente asignados, no permitimos que esa
 * detección incompleta vuelva a convertir el perfil en 1 worker / load 2.0.
 *
 * Si la infraestructura se amplía y el núcleo detecta más vCPU, se respeta el
 * valor superior; max_concurrent continúa limitado a cuatro por este perfil.
 */
function eventosapp_task_queue_performance_profile_cpu_cores($cores){
    return max(EVENTOSAPP_TASK_QUEUE_PROFILE_CPU_CORES, absint($cores));
}
add_filter('eventosapp_task_queue_cpu_cores', 'eventosapp_task_queue_performance_profile_cpu_cores', 50, 1);

function eventosapp_task_queue_performance_profile_config($config){
    $config = is_array($config) ? $config : [];
    $cores = function_exists('eventosapp_task_queue_cpu_cores')
        ? max(1, absint(eventosapp_task_queue_cpu_cores()))
        : 1;

    // Hasta cuatro workers, sin superar las vCPU visibles para PHP.
    $config['max_concurrent'] = min(4, $cores);
    $config['dispatcher_limit'] = max(16, absint($config['dispatcher_limit'] ?? 0));

    // Mayor ventana útil por lote, conservando 5 s de margen frente al límite PHP.
    $desired_execution = 40;
    $php_max_execution = (int)ini_get('max_execution_time');
    if ($php_max_execution > 0) {
        $desired_execution = min($desired_execution, max(8, $php_max_execution - 5));
    }
    $config['max_execution_seconds'] = $desired_execution;

    // memory_stop_ratio mide el proceso PHP, no los 16 GB totales del VPS.
    $config['memory_stop_ratio'] = 0.86;

    // loadavg también incorpora esperas de I/O. Con detección fiable de 4 vCPU,
    // el corte nominal es 8.0; el núcleo exige además carga sostenida (load_5)
    // o un pico severo antes de ceder el worker.
    $config['load_stop_per_core'] = 2.00;

    // Reduce el tiempo muerto entre lotes; la protección sigue activa.
    $config['min_delay_seconds'] = 1;
    $config['normal_delay_seconds'] = 1;
    $config['busy_delay_seconds'] = 2;
    $config['max_batch_size'] = max(100, absint($config['max_batch_size'] ?? 0));

    return $config;
}
add_filter('eventosapp_task_queue_config', 'eventosapp_task_queue_performance_profile_config', 50, 1);

/**
 * Ajusta los adaptadores masivos afectados por la degradación acumulativa del
 * batch histórico. El piso evita que una tarea termine procesando un solo item
 * por lote después de una llamada remota lenta.
 */
function eventosapp_task_queue_performance_profile_register_massive_adapters(){
    if (!function_exists('eventosapp_task_queue_register_adapter')) return;

    if (function_exists('eventosapp_task_queue_process_email_bulk')) {
        eventosapp_task_queue_register_adapter('email_bulk', [
            'label'=>'Envío masivo de tickets por correo',
            'group'=>'massive',
            'channel'=>'email',
            'batch_size'=>20,
            'min_batch_size'=>8,
            'max_batch_size'=>32,
            'process_batch'=>'eventosapp_task_queue_process_email_bulk',
        ]);
    }

    if (function_exists('eventosapp_task_queue_process_whatsapp_bulk')) {
        eventosapp_task_queue_register_adapter('whatsapp_bulk', [
            'label'=>'Envío masivo de tickets por WhatsApp',
            'group'=>'massive',
            'channel'=>'whatsapp',
            'batch_size'=>12,
            'min_batch_size'=>6,
            'max_batch_size'=>20,
            'process_batch'=>'eventosapp_task_queue_process_whatsapp_bulk',
        ]);
    }

    if (function_exists('eventosapp_task_queue_process_flow_bulk')) {
        eventosapp_task_queue_register_adapter('whatsapp_flow_bulk', [
            'label'=>'Envío masivo de WhatsApp Flow',
            'group'=>'massive',
            'channel'=>'flow',
            'batch_size'=>10,
            'min_batch_size'=>5,
            'max_batch_size'=>16,
            'process_batch'=>'eventosapp_task_queue_process_flow_bulk',
        ]);
    }

    if (function_exists('eventosapp_task_queue_process_attendance')) {
        eventosapp_task_queue_register_adapter('attendance_bulk', [
            'label'=>'Confirmación masiva de asistencia',
            'group'=>'massive',
            'channel'=>'mixed',
            'batch_size'=>12,
            'min_batch_size'=>4,
            'max_batch_size'=>24,
            'process_batch'=>'eventosapp_task_queue_process_attendance',
        ]);
        eventosapp_task_queue_register_adapter('attendance_scheduled', [
            'label'=>'Confirmación de asistencia programada',
            'group'=>'scheduled',
            'channel'=>'mixed',
            'batch_size'=>10,
            'min_batch_size'=>4,
            'max_batch_size'=>20,
            'process_batch'=>'eventosapp_task_queue_process_attendance',
        ]);
    }
}
add_action('init', 'eventosapp_task_queue_performance_profile_register_massive_adapters', 80);

/**
 * Si el dispatcher cede por recursos altos y todavía hay tareas listas, fuerza
 * un reintento corto. Evita depender únicamente del cron de un minuto después
 * de un pico puntual de carga.
 */
function eventosapp_task_queue_performance_profile_dispatch_followup($reason=''){
    if (!function_exists('eventosapp_task_queue_resources_busy') ||
        !function_exists('eventosapp_task_queue_kick') ||
        !function_exists('eventosapp_task_queue_table') ||
        !eventosapp_task_queue_resources_busy()) {
        return;
    }

    global $wpdb;
    $table = eventosapp_task_queue_table('tasks');
    if (!$table) return;

    $now = current_time('mysql', true);
    $due = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table}
         WHERE status IN ('queued','scheduled')
           AND (next_run_at IS NULL OR next_run_at <= %s)
           AND (scheduled_at IS NULL OR scheduled_at <= %s)",
        $now,
        $now
    ));
    if ($due < 1) return;

    $config = function_exists('eventosapp_task_queue_config')
        ? eventosapp_task_queue_config()
        : ['busy_delay_seconds'=>2];
    eventosapp_task_queue_kick(max(1, min(10, absint($config['busy_delay_seconds'] ?? 2))));
}
if (defined('EVENTOSAPP_TASK_QUEUE_DISPATCH_HOOK')) {
    add_action(EVENTOSAPP_TASK_QUEUE_DISPATCH_HOOK, 'eventosapp_task_queue_performance_profile_dispatch_followup', 20, 1);
}
