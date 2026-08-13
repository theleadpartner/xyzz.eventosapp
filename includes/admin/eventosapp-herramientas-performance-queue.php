<?php
if (!defined('ABSPATH')) exit;

function evapp_tools_perf_task_queue_process_batch($task, $runtime){
    $task = is_array($task) ? $task : [];
    $runtime = is_array($runtime) ? $runtime : [];
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    $state_key = (string)($payload['state_key'] ?? '');
    $state = $state_key !== '' ? get_option($state_key) : null;

    if (!is_array($state)) {
        return [
            'processed'=>0,'success'=>0,'errors'=>1,'skipped'=>0,
            'next_cursor'=>absint($task['cursor_value'] ?? 0),
            'total_items'=>absint($task['total_items'] ?? 0),
            'done'=>false,'fatal'=>true,
            'error_message'=>'El estado persistente de la importación no existe.',
        ];
    }

    $event_id = absint($state['event_id'] ?? ($payload['event_id'] ?? 0));
    $hash = (string)($state['file_hash'] ?? ($payload['file_hash'] ?? ''));
    $cursor = absint($task['cursor_value'] ?? 0);
    $total = absint($state['total_rows'] ?? ($task['total_items'] ?? 0));
    $batch_size = max(1, absint($runtime['batch_size'] ?? 1));
    $started = isset($runtime['started_at']) ? (float)$runtime['started_at'] : microtime(true);
    $asset_config = is_array($state['asset_config'] ?? null)
        ? $state['asset_config']
        : (is_array($payload['asset_config'] ?? null) ? $payload['asset_config'] : evapp_import_event_asset_config($event_id));

    // Cuando el CSV ya terminó, la tarea padre permanece viva hasta que los
    // shards terminen. Así Herramientas y Cola y Tareas no anuncian 100% final
    // antes de que los QR/PDF/Wallet hayan concluido.
    if (!empty(evapp_tools_perf_asset_task_ids($state)) && absint($state['offset'] ?? 0) >= $total) {
        return evapp_tools_perf_monitor_assets($task, $state_key, $state);
    }

    if (!$event_id || !$hash || empty($state['file']) || !file_exists($state['file'])) {
        return [
            'processed'=>0,'success'=>0,'errors'=>1,'skipped'=>0,
            'next_cursor'=>$cursor,'total_items'=>$total,'done'=>false,'fatal'=>true,
            'error_message'=>'El archivo CSV o los datos del evento ya no están disponibles.',
        ];
    }

    $lock_token = evapp_import_acquire_lock($event_id, $hash, 180, $state_key);
    if (!$lock_token) {
        return [
            'processed'=>0,'success'=>0,'errors'=>0,'skipped'=>0,
            'next_cursor'=>$cursor,'total_items'=>$total,'done'=>false,
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
        $state['asset_pipeline_version'] = EVENTOSAPP_TICKET_IMPORT_PERFORMANCE_VERSION;
        if (!isset($state['asset_ticket_ids']) || !is_array($state['asset_ticket_ids'])) $state['asset_ticket_ids'] = [];
        update_option($state_key, $state, false);

        $fh = @fopen($state['file'], 'r');
        if (!$fh) throw new RuntimeException('No se pudo abrir el archivo CSV.');

        $can_seek = !empty($state['csv_byte_offset']) && absint($state['offset'] ?? 0) === $cursor;
        if ($can_seek) {
            fseek($fh, (int)$state['csv_byte_offset']);
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
        $batch_asset_ids = [];

        while ($processed < $batch_size) {
            if ($processed > 0 && is_callable($runtime['should_yield'] ?? null) && call_user_func($runtime['should_yield'], $started, $processed)) {
                $logs[] = ['level'=>'warning','message'=>'El lote de datos cedió el turno por protección de recursos.'];
                break;
            }

            if ($processed > 0 && ($processed % 10) === 0 && function_exists('eventosapp_task_queue_get')) {
                $fresh = eventosapp_task_queue_get(absint($task['id'] ?? 0));
                if (is_array($fresh) && ($fresh['status'] === 'paused' || in_array($fresh['status'], eventosapp_task_queue_terminal_statuses(), true))) {
                    break;
                }
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
                $data[$field] = isset($row[$i]) ? trim((string)$row[$i]) : '';
            }

            $row_result = evapp_tools_perf_process_row_data($event_id, $data, $line, $asset_config);
            $created_delta = absint($row_result['created'] ?? 0);
            $updated_delta = absint($row_result['updated'] ?? 0);
            $error_delta = absint($row_result['errors'] ?? 0);
            $skipped_delta = absint($row_result['skipped'] ?? 0);
            $ticket_id = absint($row_result['ticket_id'] ?? 0);

            $created += $created_delta;
            $updated += $updated_delta;
            $errors += $error_delta;
            $skipped += $skipped_delta;
            if (!$error_delta && ($created_delta || $updated_delta)) $successful_rows++;
            if ($ticket_id && !empty($row_result['needs_assets'])) $batch_asset_ids[] = $ticket_id;

            if (!empty($row_result['log']) && ($error_delta || $skipped_delta)) {
                $logs[] = [
                    'level'=>$error_delta ? 'error' : 'warning',
                    'message'=>$row_result['log']['message'] ?? 'Fila procesada.',
                ];
            }
        }
        fclose($fh);

        $data_done = $reached_eof || ($total > 0 && $cursor >= $total);
        $state['offset'] = $cursor;
        $state['created_count'] = absint($state['created_count'] ?? 0) + $created;
        $state['updated_existing'] = absint($state['updated_existing'] ?? 0) + $updated;
        $state['skipped_dup'] = absint($state['skipped_dup'] ?? 0) + $skipped;
        $state['error_count'] = absint($state['error_count'] ?? 0) + $errors;
        $state['csv_byte_offset'] = max(0, (int)$last_byte_offset);
        $state['updated_at'] = time();

        if ($batch_asset_ids) {
            $state['asset_ticket_ids'] = array_values(array_unique(array_merge(
                (array)$state['asset_ticket_ids'],
                array_map('absint', $batch_asset_ids)
            )));
        }

        if ($processed > 0) {
            $logs[] = [
                'level'=>$errors ? 'warning' : 'success',
                'message'=>sprintf(
                    'Fase de datos: %d fila(s) · %d creadas · %d actualizadas · %d omitidas · %d errores.',
                    $processed, $created, $updated, $skipped, $errors
                ),
            ];
        }

        $parent_done = false;
        if ($data_done) {
            $asset_tasks = evapp_tools_perf_schedule_asset_shards($state_key, $state, $task);
            if (is_wp_error($asset_tasks)) throw new RuntimeException($asset_tasks->get_error_message());

            if ($asset_tasks) {
                $state['asset_task_ids'] = array_values(array_map('absint', $asset_tasks));
                $state['assets_status'] = 'running';
                $state['assets_total'] = count((array)$state['asset_ticket_ids']);
                $state['asset_parent_processed_accounted'] = 0;
                $state['asset_parent_success_accounted'] = 0;
                $state['asset_parent_errors_accounted'] = 0;
                $state['asset_parent_skipped_accounted'] = 0;
                $state['done'] = 0;
                $state['status'] = 'running';
                $logs[] = [
                    'level'=>'success',
                    'message'=>'CSV completado. '.count($asset_tasks).' tarea(s) paralela(s) procesarán anexos de '.count((array)$state['asset_ticket_ids']).' ticket(s).',
                ];
            } else {
                $parent_done = true;
                $state['done'] = 1;
                $state['status'] = 'done';
                $state['finished_at'] = current_time('mysql');
                $state['finished_at_ts'] = time();
                $logs[] = ['level'=>'success','message'=>'CSV completado. No quedaron anexos pendientes.'];
            }
        } else {
            $state['done'] = 0;
            $state['status'] = 'running';
        }

        $state = evapp_import_merge_runtime_logs($state, array_map(function($log){
            return evapp_import_log_entry($log['message'] ?? '');
        }, $logs));
        update_option($state_key, $state, false);

        $asset_total_for_parent = $data_done && !$parent_done
            ? count((array)$state['asset_ticket_ids'])
            : 0;
        $pipeline_total = $total + $asset_total_for_parent;

        return [
            'processed'=>$processed,
            'success'=>$successful_rows,
            'errors'=>$errors,
            'skipped'=>$skipped,
            'next_cursor'=>$cursor,
            'total_items'=>$pipeline_total,
            'done'=>$parent_done,
            'logs'=>$logs,
        ];
    } catch (Throwable $e) {
        $state['status'] = 'error';
        $state['last_error'] = $e->getMessage();
        $state['updated_at'] = time();
        update_option($state_key, $state, false);
        return [
            'processed'=>0,'success'=>0,'errors'=>1,'skipped'=>0,
            'next_cursor'=>$cursor,'total_items'=>$total,'done'=>false,'fatal'=>true,
            'error_message'=>$e->getMessage(),
            'logs'=>[['level'=>'error','message'=>'Importación rápida: '.$e->getMessage()]],
        ];
    } finally {
        evapp_import_release_lock($event_id, $hash, $lock_token, $state_key);
    }
}

function evapp_tools_perf_assets_process_batch($task, $runtime){
    $task = is_array($task) ? $task : [];
    $runtime = is_array($runtime) ? $runtime : [];
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    $ticket_ids = isset($payload['ticket_ids']) && is_array($payload['ticket_ids'])
        ? array_values(array_filter(array_unique(array_map('absint', $payload['ticket_ids']))))
        : [];
    $event_id = absint($payload['event_id'] ?? ($task['event_id'] ?? 0));
    $asset_config = is_array($payload['asset_config'] ?? null)
        ? $payload['asset_config']
        : evapp_import_event_asset_config($event_id);
    $cursor = min(count($ticket_ids), max(0, absint($task['cursor_value'] ?? 0)));
    $batch_size = max(1, absint($runtime['batch_size'] ?? 1));
    $started = isset($runtime['started_at']) ? (float)$runtime['started_at'] : microtime(true);

    if (!$event_id || empty($ticket_ids) || !function_exists('evapp_import_generate_assets_now')) {
        return [
            'processed'=>0,'success'=>0,'errors'=>1,'skipped'=>0,
            'next_cursor'=>$cursor,'total_items'=>count($ticket_ids),'done'=>false,'fatal'=>true,
            'error_message'=>'No hay tickets válidos o el generador de anexos no está disponible.',
        ];
    }

    $parent_task_id = absint($payload['parent_task_id'] ?? 0);
    if ($parent_task_id && function_exists('eventosapp_task_queue_get')) {
        $parent = eventosapp_task_queue_get($parent_task_id);
        if (is_array($parent)) {
            if ($parent['status'] === 'paused') {
                return [
                    'processed'=>0,'success'=>0,'errors'=>0,'skipped'=>0,
                    'next_cursor'=>$cursor,'total_items'=>count($ticket_ids),'done'=>false,
                    'logs'=>[],
                ];
            }
            if (in_array($parent['status'], ['cancelled','failed','expired','archived'], true)) {
                return [
                    'processed'=>0,'success'=>0,'errors'=>0,'skipped'=>0,
                    'next_cursor'=>count($ticket_ids),'total_items'=>count($ticket_ids),'done'=>true,
                    'logs'=>[['level'=>'warning','message'=>'Shard detenido porque la tarea padre ya no está activa.']],
                ];
            }
        }
    }

    $processed = $success = $errors = $skipped = 0;
    $logs = [];

    while ($cursor < count($ticket_ids) && $processed < $batch_size) {
        if ($processed > 0 && is_callable($runtime['should_yield'] ?? null) && call_user_func($runtime['should_yield'], $started, $processed)) {
            break;
        }

        if (function_exists('eventosapp_task_queue_get')) {
            $fresh = eventosapp_task_queue_get(absint($task['id'] ?? 0));
            if (is_array($fresh) && ($fresh['status'] === 'paused' || in_array($fresh['status'], eventosapp_task_queue_terminal_statuses(), true))) {
                break;
            }
        }

        $ticket_id = absint($ticket_ids[$cursor]);
        $cursor++;
        $processed++;

        if (get_post_type($ticket_id) !== 'eventosapp_ticket') {
            $skipped++;
            $logs[] = ['level'=>'warning','message'=>'Ticket #'.$ticket_id.' omitido: ya no existe como ticket.'];
            continue;
        }
        if (absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true)) !== $event_id) {
            $skipped++;
            $logs[] = ['level'=>'warning','message'=>'Ticket #'.$ticket_id.' omitido: pertenece a otro evento.'];
            continue;
        }

        try {
            $report = evapp_import_generate_assets_now($ticket_id, $event_id, $asset_config);
            if (is_array($report) && !empty($report['ok'])) {
                $success++;
            } else {
                $errors++;
                $messages = is_array($report) ? (array)($report['errors'] ?? []) : ['Respuesta inválida del generador.'];
                $logs[] = [
                    'level'=>'error',
                    'message'=>'Ticket #'.$ticket_id.' requiere reparar anexos: '.implode(' | ', array_map('sanitize_text_field', $messages)),
                ];
            }
        } catch (Throwable $e) {
            $errors++;
            $logs[] = ['level'=>'error','message'=>'Ticket #'.$ticket_id.' falló al generar anexos: '.$e->getMessage()];
        }
    }

    if ($processed > 0) {
        $logs[] = [
            'level'=>$errors ? 'warning' : 'success',
            'message'=>sprintf('Anexos: %d ticket(s) · %d correctos · %d omitidos · %d con error.', $processed, $success, $skipped, $errors),
        ];
    }

    return [
        'processed'=>$processed,
        'success'=>$success,
        'errors'=>$errors,
        'skipped'=>$skipped,
        'next_cursor'=>$cursor,
        'total_items'=>count($ticket_ids),
        'done'=>$cursor >= count($ticket_ids),
        'fatal'=>false,
        'error_message'=>'',
        'logs'=>$logs,
    ];
}
