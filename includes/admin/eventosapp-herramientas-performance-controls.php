<?php
if (!defined('ABSPATH')) exit;

function evapp_tools_perf_register_queue_adapters(){
    if (!function_exists('eventosapp_task_queue_register_adapter')) return;

    eventosapp_task_queue_register_adapter('ticket_import', [
        'label'=>'Importación masiva de tickets',
        'group'=>'massive',
        'channel'=>'tickets',
        'batch_size'=>40,
        'min_batch_size'=>5,
        'max_batch_size'=>50,
        'process_batch'=>'evapp_tools_perf_task_queue_process_batch',
    ]);

    eventosapp_task_queue_register_adapter('ticket_import_assets', [
        'label'=>'Generación paralela de anexos de tickets',
        'group'=>'massive',
        'channel'=>'tickets',
        'batch_size'=>6,
        'min_batch_size'=>1,
        'max_batch_size'=>12,
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
