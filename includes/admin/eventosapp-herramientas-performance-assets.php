<?php
if (!defined('ABSPATH')) exit;

function evapp_tools_perf_asset_task_ids($state){
    $state = is_array($state) ? $state : [];
    $ids = [];
    if (!empty($state['asset_task_ids']) && is_array($state['asset_task_ids'])) {
        $ids = array_merge($ids, $state['asset_task_ids']);
    }
    if (!empty($state['asset_tasks']) && is_array($state['asset_tasks'])) {
        $ids = array_merge($ids, array_values($state['asset_tasks']));
    }
    return array_values(array_filter(array_unique(array_map('absint', $ids))));
}

function evapp_tools_perf_asset_snapshot($state){
    $ids = evapp_tools_perf_asset_task_ids($state);
    $snapshot = [
        'task_ids'=>$ids,
        'tasks_total'=>count($ids),
        'active'=>0,
        'paused'=>0,
        'completed'=>0,
        'failed'=>0,
        'cancelled'=>0,
        'processed'=>0,
        'success'=>0,
        'errors'=>0,
        'skipped'=>0,
        'total_items'=>0,
        'done'=>empty($ids),
    ];

    if (empty($ids) || !function_exists('eventosapp_task_queue_get')) return $snapshot;

    foreach ($ids as $task_id) {
        $task = eventosapp_task_queue_get($task_id);
        if (!is_array($task)) {
            $snapshot['failed']++;
            continue;
        }

        $status = (string)($task['status'] ?? '');
        $snapshot['processed']   += absint($task['processed_items'] ?? 0);
        $snapshot['success']     += absint($task['success_items'] ?? 0);
        $snapshot['errors']      += absint($task['error_items'] ?? 0);
        $snapshot['skipped']     += absint($task['skipped_items'] ?? 0);
        $snapshot['total_items'] += absint($task['total_items'] ?? 0);

        if (in_array($status, ['queued','scheduled','running'], true)) $snapshot['active']++;
        elseif ($status === 'paused') $snapshot['paused']++;
        elseif ($status === 'completed') $snapshot['completed']++;
        elseif (in_array($status, ['failed','expired'], true)) $snapshot['failed']++;
        elseif ($status === 'cancelled') $snapshot['cancelled']++;
    }

    $snapshot['done'] = $snapshot['tasks_total'] > 0 && $snapshot['completed'] >= $snapshot['tasks_total'];
    return $snapshot;
}

function evapp_tools_perf_control_asset_tasks($state, $command){
    $command = sanitize_key((string)$command);
    foreach (evapp_tools_perf_asset_task_ids($state) as $task_id) {
        $task = function_exists('eventosapp_task_queue_get') ? eventosapp_task_queue_get($task_id) : null;
        if (!is_array($task)) continue;

        if ($command === 'pause' && in_array($task['status'], ['queued','scheduled','running'], true) && function_exists('eventosapp_task_queue_pause')) {
            eventosapp_task_queue_pause($task_id);
        } elseif ($command === 'cancel' && !in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true) && function_exists('eventosapp_task_queue_cancel')) {
            eventosapp_task_queue_cancel($task_id, 'Cancelada junto con la importación desde Herramientas.');
        } elseif ($command === 'resume' && $task['status'] === 'paused' && function_exists('eventosapp_task_queue_resume')) {
            eventosapp_task_queue_resume($task_id);
        }
    }
    if ($command === 'resume' && function_exists('eventosapp_task_queue_kick')) eventosapp_task_queue_kick();
}

function evapp_tools_perf_schedule_asset_shards($state_key, &$state, $parent_task){
    if (!function_exists('eventosapp_task_queue_create')) {
        return new WP_Error('evapp_tools_perf_queue_missing', 'La cola central no está disponible para generar anexos.');
    }

    $ticket_ids = isset($state['asset_ticket_ids']) && is_array($state['asset_ticket_ids'])
        ? array_values(array_filter(array_unique(array_map('absint', $state['asset_ticket_ids']))))
        : [];
    if (empty($ticket_ids)) return [];

    $queue_config = function_exists('eventosapp_task_queue_config')
        ? eventosapp_task_queue_config()
        : ['max_concurrent'=>3];
    $shard_count = min(3, max(1, absint($queue_config['max_concurrent'] ?? 3)), count($ticket_ids));
    $shards = array_fill(0, $shard_count, []);
    foreach ($ticket_ids as $index => $ticket_id) {
        $shards[$index % $shard_count][] = $ticket_id;
    }

    $existing = isset($state['asset_tasks']) && is_array($state['asset_tasks']) ? $state['asset_tasks'] : [];
    $event_id = absint($state['event_id'] ?? ($parent_task['event_id'] ?? 0));
    $event_title = get_the_title($event_id) ?: ('Evento '.$event_id);
    $asset_config = is_array($state['asset_config'] ?? null)
        ? $state['asset_config']
        : evapp_import_event_asset_config($event_id);

    foreach ($shards as $shard_index => $ids) {
        $key = (string)$shard_index;
        if (!empty($existing[$key]) && function_exists('eventosapp_task_queue_get')) {
            $known = eventosapp_task_queue_get(absint($existing[$key]));
            if (is_array($known)) continue;
        }

        $task_id = eventosapp_task_queue_create([
            'task_type'=>'ticket_import_assets',
            'task_group'=>'massive',
            'channel'=>'tickets',
            'title'=>'Anexos importación · '.$event_title.' · '.($shard_index + 1).'/'.$shard_count,
            'event_id'=>$event_id,
            'total_items'=>count($ids),
            'batch_size'=>6,
            'priority'=>18,
            'status'=>'paused',
            'payload'=>[
                'ticket_ids'=>$ids,
                'event_id'=>$event_id,
                'asset_config'=>$asset_config,
                'parent_task_id'=>absint($parent_task['id'] ?? 0),
                'state_key'=>(string)$state_key,
                'shard_index'=>$shard_index,
                'shard_count'=>$shard_count,
                'pipeline_version'=>EVENTOSAPP_TICKET_IMPORT_PERFORMANCE_VERSION,
            ],
            'notification_email'=>'',
            'created_by'=>absint($parent_task['created_by'] ?? get_current_user_id()),
        ]);

        if (is_wp_error($task_id)) return $task_id;

        $existing[$key] = absint($task_id);
        $state['asset_tasks'] = $existing;
        $state['asset_task_ids'] = array_values(array_map('absint', $existing));
        $state['asset_shards_total'] = $shard_count;
        $state['assets_status'] = 'queued';
        $state['asset_pipeline_version'] = EVENTOSAPP_TICKET_IMPORT_PERFORMANCE_VERSION;
        update_option($state_key, $state, false);
    }

    foreach (array_values(array_map('absint', $existing)) as $task_id) {
        $task = function_exists('eventosapp_task_queue_get') ? eventosapp_task_queue_get($task_id) : null;
        if (is_array($task) && $task['status'] === 'paused' && function_exists('eventosapp_task_queue_resume')) {
            eventosapp_task_queue_resume($task_id);
        }
    }
    if (function_exists('eventosapp_task_queue_kick')) eventosapp_task_queue_kick();

    return array_values(array_map('absint', $existing));
}

function evapp_tools_perf_monitor_assets($task, $state_key, &$state){
    $snapshot = evapp_tools_perf_asset_snapshot($state);
    $data_total = absint($state['total_rows'] ?? 0);
    $asset_total = absint($snapshot['total_items']);
    $pipeline_total = $data_total + $asset_total;

    $accounted_processed = absint($state['asset_parent_processed_accounted'] ?? 0);
    $accounted_success   = absint($state['asset_parent_success_accounted'] ?? 0);
    $accounted_errors    = absint($state['asset_parent_errors_accounted'] ?? 0);
    $accounted_skipped   = absint($state['asset_parent_skipped_accounted'] ?? 0);

    $processed_delta = max(0, absint($snapshot['processed']) - $accounted_processed);
    $success_delta   = max(0, absint($snapshot['success']) - $accounted_success);
    $errors_delta    = max(0, absint($snapshot['errors']) - $accounted_errors);
    $skipped_delta   = max(0, absint($snapshot['skipped']) - $accounted_skipped);

    $state['assets_processed'] = absint($snapshot['processed']);
    $state['assets_success'] = absint($snapshot['success']);
    $state['assets_errors'] = absint($snapshot['errors']);
    $state['assets_skipped'] = absint($snapshot['skipped']);
    $state['assets_total'] = $asset_total;
    $state['asset_parent_processed_accounted'] = absint($snapshot['processed']);
    $state['asset_parent_success_accounted'] = absint($snapshot['success']);
    $state['asset_parent_errors_accounted'] = absint($snapshot['errors']);
    $state['asset_parent_skipped_accounted'] = absint($snapshot['skipped']);
    $state['updated_at'] = time();

    if ($snapshot['failed'] > 0) {
        $state['status'] = 'error';
        $state['last_error'] = 'Una tarea paralela de anexos terminó con error.';
        update_option($state_key, $state, false);
        return [
            'processed'=>$processed_delta,
            'success'=>$success_delta,
            'errors'=>max(1, $errors_delta),
            'skipped'=>$skipped_delta,
            'next_cursor'=>$data_total + absint($snapshot['processed']),
            'total_items'=>$pipeline_total,
            'done'=>false,
            'fatal'=>true,
            'error_message'=>$state['last_error'],
            'logs'=>[['level'=>'error','message'=>$state['last_error']]],
        ];
    }

    if ($snapshot['cancelled'] > 0) {
        $state['status'] = 'error';
        $state['last_error'] = 'Una tarea paralela de anexos fue cancelada antes de terminar.';
        update_option($state_key, $state, false);
        return [
            'processed'=>$processed_delta,
            'success'=>$success_delta,
            'errors'=>max(1, $errors_delta),
            'skipped'=>$skipped_delta,
            'next_cursor'=>$data_total + absint($snapshot['processed']),
            'total_items'=>$pipeline_total,
            'done'=>false,
            'fatal'=>true,
            'error_message'=>$state['last_error'],
            'logs'=>[['level'=>'error','message'=>$state['last_error']]],
        ];
    }

    if (!empty($snapshot['done'])) {
        $state['assets_status'] = $snapshot['errors'] > 0 ? 'completed_with_errors' : 'completed';
        $state['done'] = 1;
        $state['status'] = 'done';
        $state['finished_at'] = current_time('mysql');
        $state['finished_at_ts'] = time();
        $state = evapp_import_merge_runtime_logs($state, [
            evapp_import_log_entry(
                'Fase de anexos completada: '.absint($snapshot['processed']).' ticket(s), '.
                absint($snapshot['errors']).' error(es).'
            ),
        ]);
        update_option($state_key, $state, false);

        return [
            'processed'=>$processed_delta,
            'success'=>$success_delta,
            'errors'=>$errors_delta,
            'skipped'=>$skipped_delta,
            'next_cursor'=>$pipeline_total,
            'total_items'=>$pipeline_total,
            'done'=>true,
            'logs'=>[[
                'level'=>$snapshot['errors'] > 0 ? 'warning' : 'success',
                'message'=>'Importación completa: datos y anexos finalizaron.',
            ]],
        ];
    }

    $new_status = $snapshot['paused'] > 0 && $snapshot['active'] === 0 ? 'paused' : 'running';
    if (($state['assets_status'] ?? '') !== $new_status) {
        $state = evapp_import_merge_runtime_logs($state, [
            evapp_import_log_entry(
                'Fase de anexos '.$new_status.': '.absint($snapshot['processed']).' de '.absint($snapshot['total_items']).'.'
            ),
        ]);
    }
    $state['assets_status'] = $new_status;
    $state['done'] = 0;
    $state['status'] = $new_status === 'paused' ? 'stopped' : 'running';
    update_option($state_key, $state, false);

    return [
        'processed'=>$processed_delta,
        'success'=>$success_delta,
        'errors'=>$errors_delta,
        'skipped'=>$skipped_delta,
        'next_cursor'=>$data_total + absint($snapshot['processed']),
        'total_items'=>$pipeline_total,
        'done'=>false,
        'logs'=>[],
    ];
}
