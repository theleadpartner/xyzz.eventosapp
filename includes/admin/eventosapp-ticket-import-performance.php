<?php
/**
 * EventosApp - Pipeline de alto rendimiento para importaciones masivas.
 *
 * El importador histórico conserva toda su validación, deduplicación y escritura
 * de tickets. Esta capa solo cambia el orden del trabajo cuando la importación
 * se envía a Cola y Tareas:
 *
 * 1. el task `ticket_import` procesa el CSV con los anexos pesados desactivados;
 * 2. los tickets que realmente fueron creados/actualizados quedan marcados como
 *    pendientes de anexos;
 * 3. al terminar el CSV se reparten esos tickets en hasta tres tasks
 *    `ticket_import_assets` independientes;
 * 4. la cola central decide cuántos workers puede ejecutar según memoria/carga.
 *
 * Así se mantiene el mismo resultado funcional sin obligar a una única tarea a
 * esperar QR/PDF/ICS/Wallet/WhatsApp ticket por ticket.
 *
 * El modo "en esta ventana" no cambia y queda como ruta de compatibilidad.
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! defined('EVENTOSAPP_TICKET_IMPORT_PERFORMANCE_VERSION') ) {
    define('EVENTOSAPP_TICKET_IMPORT_PERFORMANCE_VERSION', '2026.08.12.1');
}

/**
 * Sustituye únicamente el adaptador de importación después del registro central
 * (init 35) y añade el adaptador de anexos paralelos.
 */
function evapp_import_perf_register_queue_adapters() {
    if ( ! function_exists('eventosapp_task_queue_register_adapter') ) return;

    eventosapp_task_queue_register_adapter('ticket_import_assets', [
        'label'          => 'Generación paralela de anexos de tickets',
        'group'          => 'massive',
        'channel'        => 'tickets',
        'batch_size'     => 6,
        'min_batch_size' => 1,
        'max_batch_size' => 12,
        'process_batch'  => 'evapp_import_perf_assets_process_batch',
    ]);

    eventosapp_task_queue_register_adapter('ticket_import', [
        'label'          => 'Importación masiva de tickets',
        'group'          => 'massive',
        'channel'        => 'tickets',
        'batch_size'     => 40,
        'min_batch_size' => 5,
        'max_batch_size' => 50,
        'process_batch'  => 'evapp_import_perf_task_queue_process_batch',
    ]);
}
add_action('init', 'evapp_import_perf_register_queue_adapters', 60);

/**
 * Configuración temporal que hace que el importador histórico escriba todos los
 * datos del ticket pero no genere anexos costosos en la misma fila.
 */
function evapp_import_perf_deferred_asset_config() {
    return [
        'pdf'            => false,
        'ics'            => false,
        'wallet_android' => false,
        'wallet_apple'   => false,
        'whatsapp'       => false,
        'variants'       => false,
        'link_assistant' => false,
        'physical_qr'    => false,
    ];
}

/**
 * Señala de forma explícita que el resultado final del ticket aún no está listo.
 * `evapp_import_generate_assets_now()` elimina este meta cuando todo queda OK.
 */
function evapp_import_perf_mark_assets_pending($ticket_id, $event_id, $parent_task_id = 0) {
    $ticket_id = absint($ticket_id);
    if ( ! $ticket_id ) return;

    update_post_meta($ticket_id, '_eventosapp_import_assets_pending', [
        'at'             => current_time('mysql'),
        'event_id'       => absint($event_id),
        'parent_task_id' => absint($parent_task_id),
        'pipeline'       => EVENTOSAPP_TICKET_IMPORT_PERFORMANCE_VERSION,
    ]);
}

/**
 * Procesa una fila reutilizando exactamente el importador existente. Los anexos
 * se difieren, pero la vinculación con Asistentes se conserva en la fase de datos
 * porque no forma parte de `evapp_import_generate_assets_now()`.
 */
function evapp_import_perf_process_row_data($event_id, $data, $line, $asset_config, $parent_task_id = 0) {
    if ( ! function_exists('evapp_import_process_row_data') ) {
        return [
            'created'=>0,
            'updated'=>0,
            'skipped'=>0,
            'errors'=>1,
            'ticket_id'=>0,
            'needs_assets'=>0,
            'log'=>['message'=>'L'.absint($line).': el procesador base de importación no está disponible.'],
        ];
    }

    $asset_config = is_array($asset_config) ? $asset_config : [];
    $result = evapp_import_process_row_data(
        absint($event_id),
        is_array($data) ? $data : [],
        absint($line),
        evapp_import_perf_deferred_asset_config()
    );
    $result = is_array($result) ? $result : [];
    $ticket_id = absint($result['ticket_id'] ?? 0);
    $changed = ! empty($result['created']) || ! empty($result['updated']);

    $result['needs_assets'] = 0;

    if ( $ticket_id && $changed ) {
        if ( ! empty($asset_config['link_assistant']) && function_exists('evapp_process_vincular_asistente') ) {
            evapp_process_vincular_asistente($ticket_id);
        }
        evapp_import_perf_mark_assets_pending($ticket_id, $event_id, $parent_task_id);
        $result['needs_assets'] = 1;
    } elseif ( $ticket_id && get_post_meta($ticket_id, '_eventosapp_import_assets_pending', true) ) {
        // Recuperación de crash: si la fila quedó escrita antes del checkpoint,
        // el fingerprint la marcará duplicada en el retry, pero no perderá anexos.
        $result['needs_assets'] = 1;
    }

    return $result;
}

/**
 * Reparte los tickets en shards deterministas. El ID de cada task se persiste
 * inmediatamente para no duplicar shards si una creación posterior falla.
 */
function evapp_import_perf_schedule_asset_shards($state_key, &$state, $parent_task) {
    if ( ! function_exists('eventosapp_task_queue_create') ) {
        return new WP_Error('evapp_import_perf_queue_missing', 'La cola central no está disponible para generar anexos.');
    }

    $ticket_ids = isset($state['asset_ticket_ids']) && is_array($state['asset_ticket_ids'])
        ? array_values(array_filter(array_unique(array_map('absint', $state['asset_ticket_ids']))))
        : [];
    if ( empty($ticket_ids) ) return [];

    $queue_config = function_exists('eventosapp_task_queue_config')
        ? eventosapp_task_queue_config()
        : ['max_concurrent'=>3];
    $shard_count = min(3, max(1, absint($queue_config['max_concurrent'] ?? 3)), count($ticket_ids));
    $shards = array_fill(0, $shard_count, []);
    foreach ( $ticket_ids as $index => $ticket_id ) {
        $shards[$index % $shard_count][] = $ticket_id;
    }

    $existing = isset($state['asset_tasks']) && is_array($state['asset_tasks']) ? $state['asset_tasks'] : [];
    $event_id = absint($state['event_id'] ?? $parent_task['event_id'] ?? 0);
    $event_title = get_the_title($event_id) ?: ('Evento '.$event_id);
    $asset_config = is_array($state['asset_config'] ?? null)
        ? $state['asset_config']
        : (function_exists('evapp_import_event_asset_config') ? evapp_import_event_asset_config($event_id) : []);

    foreach ( $shards as $shard_index => $ids ) {
        $key = (string)$shard_index;
        if ( ! empty($existing[$key]) && function_exists('eventosapp_task_queue_get') ) {
            $known = eventosapp_task_queue_get(absint($existing[$key]));
            if ( is_array($known) ) continue;
        }

        $task_id = eventosapp_task_queue_create([
            'task_type'   => 'ticket_import_assets',
            'task_group'  => 'massive',
            'channel'     => 'tickets',
            'title'       => 'Anexos importación · '.$event_title.' · '.($shard_index + 1).'/'.$shard_count,
            'event_id'    => $event_id,
            'total_items' => count($ids),
            'batch_size'  => 6,
            'priority'    => 18,
            'payload'     => [
                'ticket_ids'       => $ids,
                'event_id'         => $event_id,
                'asset_config'     => $asset_config,
                'parent_task_id'   => absint($parent_task['id'] ?? 0),
                'parent_task_uuid' => sanitize_text_field((string)($parent_task['uuid'] ?? '')),
                'state_key'        => (string)$state_key,
                'shard_index'      => $shard_index,
                'shard_count'      => $shard_count,
                'pipeline_version' => EVENTOSAPP_TICKET_IMPORT_PERFORMANCE_VERSION,
            ],
            'notification_email' => '',
            'created_by'         => absint($parent_task['created_by'] ?? get_current_user_id()),
        ]);

        if ( is_wp_error($task_id) ) return $task_id;

        $existing[$key] = absint($task_id);
        $state['asset_tasks'] = $existing;
        $state['asset_task_ids'] = array_values(array_map('absint', $existing));
        $state['asset_shards_total'] = $shard_count;
        $state['asset_total'] = count($ticket_ids);
        $state['asset_pipeline_version'] = EVENTOSAPP_TICKET_IMPORT_PERFORMANCE_VERSION;
        update_option($state_key, $state, false);
    }

    return array_values(array_map('absint', $existing));
}

/**
 * Fase 1: lectura/validación/escritura del CSV sin anexos pesados por fila.
 */
function evapp_import_perf_task_queue_process_batch($task, $runtime) {
    $task = is_array($task) ? $task : [];
    $runtime = is_array($runtime) ? $runtime : [];
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    $state_key = (string)($payload['state_key'] ?? '');
    $state = $state_key !== '' ? get_option($state_key) : null;

    if ( ! is_array($state) ) {
        return [
            'processed'=>0,'success'=>0,'errors'=>1,'skipped'=>0,
            'next_cursor'=>absint($task['cursor_value'] ?? 0),
            'total_items'=>absint($task['total_items'] ?? 0),
            'done'=>false,'fatal'=>true,
            'error_message'=>'El estado persistente de la importación no existe.',
        ];
    }

    $event_id = absint($state['event_id'] ?? $payload['event_id'] ?? 0);
    $hash = (string)($state['file_hash'] ?? $payload['file_hash'] ?? '');
    $cursor = absint($task['cursor_value'] ?? 0);
    $total = absint($state['total_rows'] ?? $task['total_items'] ?? 0);
    $batch_size = max(1, absint($runtime['batch_size'] ?? 1));
    $started = isset($runtime['started_at']) ? (float)$runtime['started_at'] : microtime(true);
    $asset_config = is_array($state['asset_config'] ?? null)
        ? $state['asset_config']
        : (is_array($payload['asset_config'] ?? null)
            ? $payload['asset_config']
            : (function_exists('evapp_import_event_asset_config') ? evapp_import_event_asset_config($event_id) : []));

    if ( ! $event_id || ! $hash || empty($state['file']) || ! file_exists($state['file']) ) {
        return [
            'processed'=>0,'success'=>0,'errors'=>1,'skipped'=>0,
            'next_cursor'=>$cursor,'total_items'=>$total,'done'=>false,'fatal'=>true,
            'error_message'=>'El archivo CSV o los datos del evento ya no están disponibles.',
        ];
    }
    if ( ! function_exists('evapp_import_acquire_lock') || ! function_exists('evapp_import_release_lock') ) {
        return [
            'processed'=>0,'success'=>0,'errors'=>1,'skipped'=>0,
            'next_cursor'=>$cursor,'total_items'=>$total,'done'=>false,'fatal'=>true,
            'error_message'=>'El lock del importador no está disponible.',
        ];
    }

    $lock_token = evapp_import_acquire_lock($event_id, $hash, 180, $state_key);
    if ( ! $lock_token ) {
        return [
            'processed'=>0,'success'=>0,'errors'=>0,'skipped'=>0,
            'next_cursor'=>$cursor,'total_items'=>$total,'done'=>false,
            'logs'=>[['level'=>'warning','message'=>'La cola esperó porque otro lote de esta importación aún conserva el bloqueo.']],
        ];
    }

    try {
        if ( function_exists('evapp_import_prepare_event_assets_once') ) {
            evapp_import_prepare_event_assets_once($event_id, $state);
        }
        $state['queue_task_id'] = absint($task['id'] ?? 0);
        $state['execution_mode'] = 'background';
        $state['background_backend'] = 'task_queue';
        $state['status'] = 'running';
        $state['asset_config'] = $asset_config;
        $state['asset_pipeline_version'] = EVENTOSAPP_TICKET_IMPORT_PERFORMANCE_VERSION;
        if ( ! isset($state['asset_ticket_ids']) || ! is_array($state['asset_ticket_ids']) ) {
            $state['asset_ticket_ids'] = [];
        }
        update_option($state_key, $state, false);

        $fh = @fopen($state['file'], 'r');
        if ( ! $fh ) throw new RuntimeException('No se pudo abrir el archivo CSV.');

        $can_seek = ! empty($state['csv_byte_offset']) && absint($state['offset'] ?? 0) === $cursor;
        if ( $can_seek ) {
            fseek($fh, (int)$state['csv_byte_offset']);
            $line = $cursor + 1;
        } else {
            fgetcsv($fh);
            $line = 1;
            $skip_rows = 0;
            while ( $skip_rows < $cursor && fgetcsv($fh) !== false ) {
                $skip_rows++;
                $line++;
            }
        }

        $map = is_array($state['map'] ?? null) ? $state['map'] : [];
        $rev = [];
        foreach ( $map as $i => $mapped_key ) {
            if ( $mapped_key ) $rev[intval($i)] = $mapped_key;
        }

        $processed = $created = $updated = $errors = $skipped = $success = 0;
        $warning_logs = [];
        $reached_eof = false;
        $last_byte_offset = ftell($fh);
        $batch_asset_ids = [];

        while ( $processed < $batch_size ) {
            if (
                $processed > 0 &&
                isset($runtime['should_yield']) && is_callable($runtime['should_yield']) &&
                call_user_func($runtime['should_yield'], $started, $processed)
            ) {
                break;
            }

            // En la fase liviana basta comprobar el control cada 10 filas; una
            // pausa sigue respondiendo rápido y se evitan miles de SELECT.
            if ( $processed > 0 && ($processed % 10) === 0 && function_exists('eventosapp_task_queue_get') ) {
                $fresh = eventosapp_task_queue_get(absint($task['id'] ?? 0));
                if (
                    is_array($fresh) &&
                    ($fresh['status'] === 'paused' || in_array($fresh['status'], eventosapp_task_queue_terminal_statuses(), true))
                ) {
                    break;
                }
            }

            $row = fgetcsv($fh);
            if ( $row === false ) {
                $reached_eof = true;
                break;
            }

            $line++;
            $processed++;
            $cursor++;
            $last_byte_offset = ftell($fh);

            $data = [];
            foreach ( $rev as $i => $field ) {
                $data[$field] = isset($row[$i]) ? trim((string)$row[$i]) : '';
            }

            $row_result = evapp_import_perf_process_row_data(
                $event_id,
                $data,
                $line,
                $asset_config,
                absint($task['id'] ?? 0)
            );

            $created_delta = absint($row_result['created'] ?? 0);
            $updated_delta = absint($row_result['updated'] ?? 0);
            $error_delta   = absint($row_result['errors'] ?? 0);
            $skipped_delta = absint($row_result['skipped'] ?? 0);
            $ticket_id     = absint($row_result['ticket_id'] ?? 0);

            $created += $created_delta;
            $updated += $updated_delta;
            $errors  += $error_delta;
            $skipped += $skipped_delta;
            if ( ! $error_delta && ($created_delta || $updated_delta) ) $success++;

            if ( $ticket_id && ! empty($row_result['needs_assets']) ) {
                $batch_asset_ids[] = $ticket_id;
            }

            // No se escriben 4.000 logs de éxito. Errores/omisiones sí quedan
            // individualizados y al final se agrega un resumen de lote.
            if ( ! empty($row_result['log']) && ($error_delta || $skipped_delta) ) {
                $warning_logs[] = [
                    'level'   => $error_delta ? 'error' : 'warning',
                    'message' => sanitize_text_field((string)($row_result['log']['message'] ?? 'Fila procesada.')),
                ];
            }
        }
        fclose($fh);

        $done = $reached_eof || ($total > 0 && $cursor >= $total);
        $state['offset'] = $cursor;
        $state['created_count'] = absint($state['created_count'] ?? 0) + $created;
        $state['updated_existing'] = absint($state['updated_existing'] ?? 0) + $updated;
        $state['skipped_dup'] = absint($state['skipped_dup'] ?? 0) + $skipped;
        $state['error_count'] = absint($state['error_count'] ?? 0) + $errors;
        $state['csv_byte_offset'] = max(0, (int)$last_byte_offset);
        $state['updated_at'] = time();

        if ( $batch_asset_ids ) {
            $state['asset_ticket_ids'] = array_values(array_unique(array_merge(
                (array)$state['asset_ticket_ids'],
                array_map('absint', $batch_asset_ids)
            )));
        }

        $logs = $warning_logs;
        if ( $processed > 0 ) {
            $logs[] = [
                'level'   => $errors ? 'warning' : 'success',
                'message' => sprintf(
                    'Lote rápido: %d fila(s) · %d creadas · %d actualizadas · %d omitidas · %d errores. Datos guardados; anexos diferidos.',
                    $processed,
                    $created,
                    $updated,
                    $skipped,
                    $errors
                ),
            ];
        }

        if ( $done ) {
            $asset_tasks = evapp_import_perf_schedule_asset_shards($state_key, $state, $task);
            if ( is_wp_error($asset_tasks) ) throw new RuntimeException($asset_tasks->get_error_message());

            $state['done'] = 1;
            $state['status'] = 'done';
            $state['finished_at'] = current_time('mysql');
            $state['finished_at_ts'] = time();
            $state['asset_task_ids'] = array_values(array_map('absint', (array)$asset_tasks));
            $state['assets_status'] = $asset_tasks ? 'queued' : 'not_required';

            $logs[] = [
                'level'   => 'success',
                'message' => $asset_tasks
                    ? 'CSV completado. '.count($asset_tasks).' workers paralelos generarán los anexos de '.count((array)$state['asset_ticket_ids']).' ticket(s).'
                    : 'CSV completado. No quedaron tickets pendientes de anexos.',
                'context' => ['asset_task_ids'=>$state['asset_task_ids']],
            ];
        } else {
            $state['done'] = 0;
            $state['status'] = 'running';
        }

        if ( function_exists('evapp_import_merge_runtime_logs') ) {
            $runtime_logs = [];
            foreach ( $logs as $log ) {
                $runtime_logs[] = function_exists('evapp_import_log_entry')
                    ? evapp_import_log_entry($log['message'] ?? '')
                    : ['time'=>current_time('H:i:s'),'message'=>$log['message'] ?? ''];
            }
            $state = evapp_import_merge_runtime_logs($state, $runtime_logs);
        }
        update_option($state_key, $state, false);

        return [
            'processed'   => $processed,
            'success'     => $success,
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
            'processed'=>0,'success'=>0,'errors'=>1,'skipped'=>0,
            'next_cursor'=>$cursor,'total_items'=>$total,'done'=>false,'fatal'=>true,
            'error_message'=>$e->getMessage(),
            'logs'=>[['level'=>'error','message'=>'Importación rápida: '.$e->getMessage()]],
        ];
    } finally {
        evapp_import_release_lock($event_id, $hash, $lock_token, $state_key);
    }
}

/**
 * Fase 2: genera anexos sobre tickets distintos. Cada shard tiene su propio
 * cursor/lock de task, de modo que la cola puede ejecutar hasta tres a la vez.
 */
function evapp_import_perf_assets_process_batch($task, $runtime) {
    $task = is_array($task) ? $task : [];
    $runtime = is_array($runtime) ? $runtime : [];
    $payload = is_array($task['payload'] ?? null) ? $task['payload'] : [];
    $ticket_ids = isset($payload['ticket_ids']) && is_array($payload['ticket_ids'])
        ? array_values(array_filter(array_unique(array_map('absint', $payload['ticket_ids']))))
        : [];
    $event_id = absint($payload['event_id'] ?? $task['event_id'] ?? 0);
    $asset_config = is_array($payload['asset_config'] ?? null) ? $payload['asset_config'] : [];
    $cursor = min(count($ticket_ids), max(0, absint($task['cursor_value'] ?? 0)));
    $batch_size = max(1, absint($runtime['batch_size'] ?? 1));
    $started = isset($runtime['started_at']) ? (float)$runtime['started_at'] : microtime(true);

    if ( ! $event_id || empty($ticket_ids) || ! function_exists('evapp_import_generate_assets_now') ) {
        return [
            'processed'=>0,'success'=>0,'errors'=>1,'skipped'=>0,
            'next_cursor'=>$cursor,'total_items'=>count($ticket_ids),'done'=>false,'fatal'=>true,
            'error_message'=>'No hay tickets válidos o el generador de anexos no está disponible.',
        ];
    }

    $processed = $success = $errors = $skipped = 0;
    $logs = [];

    while ( $cursor < count($ticket_ids) && $processed < $batch_size ) {
        if (
            $processed > 0 &&
            isset($runtime['should_yield']) && is_callable($runtime['should_yield']) &&
            call_user_func($runtime['should_yield'], $started, $processed)
        ) {
            break;
        }

        if ( function_exists('eventosapp_task_queue_get') ) {
            $fresh = eventosapp_task_queue_get(absint($task['id'] ?? 0));
            if (
                is_array($fresh) &&
                ($fresh['status'] === 'paused' || in_array($fresh['status'], eventosapp_task_queue_terminal_statuses(), true))
            ) {
                break;
            }
        }

        $ticket_id = absint($ticket_ids[$cursor]);
        $cursor++;
        $processed++;

        if ( get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
            $skipped++;
            $logs[] = ['level'=>'warning','message'=>'Ticket #'.$ticket_id.' omitido: ya no existe como ticket.'];
            continue;
        }
        if ( absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true)) !== $event_id ) {
            $skipped++;
            $logs[] = ['level'=>'warning','message'=>'Ticket #'.$ticket_id.' omitido: pertenece a otro evento.'];
            continue;
        }

        try {
            $report = evapp_import_generate_assets_now($ticket_id, $event_id, $asset_config);
            if ( is_array($report) && ! empty($report['ok']) ) {
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

    if ( $processed > 0 ) {
        $logs[] = [
            'level'   => $errors ? 'warning' : 'success',
            'message' => sprintf(
                'Anexos en paralelo: %d ticket(s) · %d correctos · %d omitidos · %d con error.',
                $processed,
                $success,
                $skipped,
                $errors
            ),
        ];
    }

    return [
        'processed'    => $processed,
        'success'      => $success,
        'errors'       => $errors,
        'skipped'      => $skipped,
        'next_cursor'  => $cursor,
        'total_items'  => count($ticket_ids),
        'done'         => $cursor >= count($ticket_ids),
        'fatal'        => false,
        'error_message'=> '',
        'logs'         => $logs,
    ];
}

/**
 * La pantalla deja claro cuál opción activa la ruta de alto rendimiento.
 */
function evapp_import_perf_admin_hint() {
    if ( ! is_admin() || ! current_user_can('manage_options') ) return;
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $step = absint($_GET['step'] ?? 0);
    if ( $page !== 'eventosapp_tools' || $step !== 4 ) return;
    ?>
    <script id="eventosapp-ticket-import-performance-hint">
    (function(){
        var background = document.getElementById('evapp_background_import');
        if (background) background.textContent = 'Enviar a Cola y Tareas · modo rápido';

        var start = document.getElementById('evapp_start_import');
        if (!start || document.getElementById('evapp-import-perf-note')) return;
        var note = document.createElement('p');
        note.id = 'evapp-import-perf-note';
        note.style.cssText = 'background:#ecfdf5;border-left:4px solid #16a34a;padding:9px 11px;margin:0 0 14px;';
        note.innerHTML = '<strong>Modo rápido recomendado:</strong> Cola y Tareas guarda primero los datos y después reparte QR/PDF/ICS/Wallet/WhatsApp en hasta tres workers protegidos por la cola central. El modo “en esta ventana” se conserva por compatibilidad y sigue siendo secuencial.';
        start.parentNode.parentNode.insertBefore(note, start.parentNode);
    })();
    </script>
    <?php
}
add_action('admin_footer', 'evapp_import_perf_admin_hint', 1010);
