<?php
/**
 * EventosApp - Administración de Cola y Tareas.
 *
 * Ruta: includes/admin/eventosapp-task-queue-admin.php
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) exit;

function eventosapp_task_queue_admin_capability() {
    return apply_filters('eventosapp_task_queue_admin_capability', 'manage_options');
}

function eventosapp_task_queue_admin_can_manage() {
    return current_user_can(eventosapp_task_queue_admin_capability());
}

add_action('admin_menu', function() {
    add_submenu_page(
        'eventosapp_dashboard',
        'Cola y Tareas',
        'Cola y Tareas',
        eventosapp_task_queue_admin_capability(),
        'eventosapp_task_queue',
        'eventosapp_task_queue_render_admin_page',
        28
    );
}, 25);

function eventosapp_task_queue_admin_redirect($message, $ok = true, $task_id = 0) {
    $args = [
        'page' => 'eventosapp_task_queue',
        'evapp_queue_msg' => sanitize_text_field((string)$message),
        'evapp_queue_ok'  => $ok ? '1' : '0',
    ];
    if ( $task_id ) $args['task_id'] = absint($task_id);
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}

add_action('admin_post_eventosapp_task_queue_action', function() {
    if ( ! eventosapp_task_queue_admin_can_manage() ) wp_die('No autorizado.');
    check_admin_referer('eventosapp_task_queue_action');

    $action = sanitize_key((string)wp_unslash($_POST['queue_action'] ?? ''));
    $task_id = absint($_POST['task_id'] ?? 0);
    $ok = false;
    $message = 'Acción no reconocida.';

    if ( $action === 'delete_terminal' ) {
        $requested_status = sanitize_key((string)wp_unslash($_POST['terminal_status'] ?? ''));
        $deleted = eventosapp_task_queue_delete_terminal($requested_status);
        eventosapp_task_queue_admin_redirect('Se eliminaron ' . absint($deleted) . ' registros terminados.', true);
    }

    if ( $action === 'archive_selected' ) {
        $task_ids = isset($_POST['task_ids']) && is_array($_POST['task_ids'])
            ? array_map('absint', wp_unslash($_POST['task_ids']))
            : [];
        $result = eventosapp_task_queue_archive_many($task_ids);
        $message = 'Se archivaron ' . absint($result['archived'] ?? 0) . ' tareas seleccionadas.';
        if ( ! empty($result['skipped']) ) {
            $message .= ' Se omitieron ' . absint($result['skipped']) . ' porque no estaban finalizadas, canceladas, vencidas o con error.';
        }
        $ok = ! empty($result['archived']);
        eventosapp_task_queue_admin_redirect($message, $ok);
    }

    if ( $action === 'delete_selected' ) {
        $task_ids = isset($_POST['task_ids']) && is_array($_POST['task_ids'])
            ? array_map('absint', wp_unslash($_POST['task_ids']))
            : [];
        $result = eventosapp_task_queue_delete_many($task_ids);
        $message = 'Se eliminaron ' . absint($result['deleted'] ?? 0) . ' tareas seleccionadas.';
        if ( ! empty($result['cancelled']) ) {
            $message .= ' Se cancelaron ' . absint($result['cancelled']) . ' tareas activas antes de borrarlas.';
        }
        if ( ! empty($result['pending']) ) {
            $message .= ' ' . absint($result['pending']) . ' quedaron en eliminación segura mientras el worker termina el elemento activo.';
        }
        if ( ! empty($result['skipped']) ) {
            $message .= ' Se omitieron ' . absint($result['skipped']) . ' porque ya no existían o no pudieron cancelarse.';
        }
        $ok = ! empty($result['deleted']) || ! empty($result['pending']);
        eventosapp_task_queue_admin_redirect($message, $ok);
    }

    $task = eventosapp_task_queue_get($task_id);
    if ( ! $task ) eventosapp_task_queue_admin_redirect('La tarea no existe.', false);

    switch ( $action ) {
        case 'pause':
            $ok = eventosapp_task_queue_pause($task_id);
            $message = $ok ? 'Tarea pausada.' : 'La tarea no se puede pausar en su estado actual.';
            break;
        case 'resume':
            $ok = eventosapp_task_queue_resume($task_id);
            $message = $ok ? 'Tarea reanudada.' : 'Solo se pueden reanudar tareas pausadas.';
            break;
        case 'cancel':
            $reason = sanitize_text_field((string)wp_unslash($_POST['reason'] ?? 'Cancelada desde Cola y Tareas.'));
            $ok = eventosapp_task_queue_cancel($task_id, $reason);
            $message = $ok ? 'Tarea cancelada.' : 'La tarea ya terminó o no se puede cancelar.';
            break;
        case 'archive':
            $ok = eventosapp_task_queue_archive($task_id);
            $message = $ok ? 'Tarea archivada. Sus resultados y logs permanecen disponibles.' : 'Solo se pueden archivar tareas finalizadas, canceladas, vencidas o con error.';
            if ( $ok ) eventosapp_task_queue_admin_redirect($message, true);
            break;
        case 'reschedule':
            $date = sanitize_text_field((string)wp_unslash($_POST['schedule_date'] ?? ''));
            $time = sanitize_text_field((string)wp_unslash($_POST['schedule_time'] ?? ''));
            $parsed = eventosapp_task_queue_parse_event_local_datetime(absint($task['event_id'] ?? 0), $date, $time);
            if ( is_wp_error($parsed) ) {
                $ok = false;
                $message = $parsed->get_error_message();
            } elseif ( absint($parsed['timestamp'] ?? 0) <= time() ) {
                $ok = false;
                $message = 'La nueva programación debe estar en el futuro según la zona horaria del evento.';
            } else {
                $ok = eventosapp_task_queue_reschedule($task_id, $parsed['utc'], $parsed);
                $message = $ok
                    ? 'Tarea reprogramada para ' . $parsed['local'] . ' (' . $parsed['timezone'] . ').'
                    : 'No se pudo reprogramar la tarea.';
            }
            break;
        case 'delete':
            $waiting_for_worker = function_exists('eventosapp_task_queue_has_active_lock') && eventosapp_task_queue_has_active_lock($task_id);
            $ok = eventosapp_task_queue_delete($task_id);
            $message = $ok
                ? ($waiting_for_worker ? 'La tarea quedó en eliminación segura y se borrará al terminar el elemento activo.' : 'Tarea eliminada.')
                : 'Solo se pueden eliminar tareas finalizadas, canceladas, vencidas, con error o archivadas.';
            if ( $ok ) eventosapp_task_queue_admin_redirect($message, true);
            break;
        case 'update_email':
            $email = sanitize_email((string)wp_unslash($_POST['notification_email'] ?? ''));
            $ok = ($email === '' || is_email($email)) && eventosapp_task_queue_update($task_id, ['notification_email'=>$email]);
            $message = $ok ? 'Correo de notificación actualizado.' : 'El correo no es válido.';
            break;
    }

    eventosapp_task_queue_admin_redirect($message, $ok, $task_id);
});

add_action('wp_ajax_eventosapp_task_queue_status', function() {
    if ( ! eventosapp_task_queue_admin_can_manage() ) wp_send_json_error('No autorizado', 403);
    check_ajax_referer('eventosapp_task_queue_status', 'nonce');
    $task = eventosapp_task_queue_get(absint($_POST['task_id'] ?? 0));
    if ( ! $task ) wp_send_json_error('Tarea no encontrada', 404);
    $metrics = is_array($task['resource_metrics'] ?? null) ? $task['resource_metrics'] : [];
    wp_send_json_success([
        'id'        => $task['id'],
        'status'    => $task['status'],
        'status_label' => eventosapp_task_queue_statuses()[$task['status']] ?? $task['status'],
        'progress'  => eventosapp_task_queue_admin_progress($task),
        'processed' => $task['processed_items'],
        'total'     => $task['total_items'],
        'success'   => $task['success_items'],
        'errors'    => $task['error_items'],
        'skipped'   => $task['skipped_items'],
        'metrics'   => $metrics['last_batch'] ?? [],
        'updated_at'=> $task['updated_at'],
        'terminal'  => in_array($task['status'], eventosapp_task_queue_terminal_statuses(), true),
    ]);
});

function eventosapp_task_queue_admin_progress($task) {
    $total = max(0, absint($task['total_items'] ?? 0));
    $processed = max(0, absint($task['processed_items'] ?? 0));
    $status = sanitize_key((string)($task['status'] ?? ''));
    $archived_from = function_exists('eventosapp_task_queue_archived_from_status')
        ? eventosapp_task_queue_archived_from_status($task)
        : '';
    if ( $total < 1 ) return ($status === 'completed' || $archived_from === 'completed') ? 100 : 0;
    return min(100, max(0, (int)floor(($processed / $total) * 100)));
}

function eventosapp_task_queue_admin_format_bytes($bytes) {
    $bytes = max(0, (float)$bytes);
    if ( $bytes >= 1073741824 ) return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
    if ( $bytes >= 1048576 ) return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
    if ( $bytes >= 1024 ) return number_format($bytes / 1024, 2, ',', '.') . ' KB';
    return number_format($bytes, 0, ',', '.') . ' B';
}

function eventosapp_task_queue_admin_local_datetime($utc, $task = null, $format = 'd/m/Y H:i:s') {
    if ( ! $utc ) return '—';

    // Compatibilidad con llamadas antiguas donde el segundo argumento era el formato.
    if ( is_string($task) ) {
        $format = $task;
        $task = null;
    }

    try {
        $date = new DateTimeImmutable((string)$utc, new DateTimeZone('UTC'));
        $timezone = is_array($task)
            ? eventosapp_task_queue_task_timezone_object($task)
            : wp_timezone();
        return $date->setTimezone($timezone)->format($format);
    } catch (Throwable $e) {
        return sanitize_text_field((string)$utc);
    }
}

function eventosapp_task_queue_admin_utc_datetime($utc, $format = 'd/m/Y H:i:s') {
    if ( ! $utc ) return '—';
    try {
        return (new DateTimeImmutable((string)$utc, new DateTimeZone('UTC')))->format($format);
    } catch (Throwable $e) {
        return sanitize_text_field((string)$utc);
    }
}


function eventosapp_task_queue_admin_planned_local_datetime($task, $format = 'd/m/Y H:i:s') {
    $timestamp = eventosapp_task_queue_planned_timestamp(is_array($task) ? $task : []);
    return $timestamp ? eventosapp_task_queue_format_timestamp_for_task($task, $timestamp, $format) : '—';
}

function eventosapp_task_queue_admin_planned_utc_datetime($task, $format = 'd/m/Y H:i:s') {
    $timestamp = eventosapp_task_queue_planned_timestamp(is_array($task) ? $task : []);
    return $timestamp ? gmdate($format, $timestamp) : '—';
}

function eventosapp_task_queue_admin_task_label($task_type) {
    $adapter = eventosapp_task_queue_get_adapter($task_type);
    return is_array($adapter) ? (string)($adapter['label'] ?? $task_type) : $task_type;
}

function eventosapp_task_queue_admin_events() {
    return get_posts([
        'post_type'=>'eventosapp_event','post_status'=>['publish','draft','private'],
        'posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC',
    ]);
}

function eventosapp_task_queue_admin_clients() {
    $types = apply_filters('eventosapp_task_queue_client_post_types', ['eventosapp_client','eventosapp_cliente']);
    $clients = [];
    foreach ( (array)$types as $type ) {
        if ( ! post_type_exists($type) ) continue;
        $posts = get_posts(['post_type'=>$type,'post_status'=>'any','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
        foreach ( $posts as $post ) $clients[$post->ID] = $post;
    }
    return $clients;
}

function eventosapp_task_queue_admin_status_badge($status) {
    $labels = eventosapp_task_queue_statuses();
    return '<span class="evapp-queue-status status-' . esc_attr($status) . '">' . esc_html($labels[$status] ?? $status) . '</span>';
}

function eventosapp_task_queue_admin_action_form($task, $action, $label, $class = 'button', $extra = '') {
    $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="evapp-queue-inline-form">';
    $html .= '<input type="hidden" name="action" value="eventosapp_task_queue_action">';
    $html .= '<input type="hidden" name="queue_action" value="' . esc_attr($action) . '">';
    $html .= '<input type="hidden" name="task_id" value="' . absint($task['id']) . '">';
    $html .= wp_nonce_field('eventosapp_task_queue_action', '_wpnonce', true, false);
    $html .= $extra;
    $html .= '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    return $html;
}

function eventosapp_task_queue_render_admin_page() {
    if ( ! eventosapp_task_queue_admin_can_manage() ) wp_die('No tienes permisos para acceder a esta sección.');

    // Corrige primero los registros históricos para que la tabla nunca presente
    // como programada una tarea de un día anterior.
    eventosapp_task_queue_reconcile_scheduled_tasks(300);

    $task_id = absint($_GET['task_id'] ?? 0);
    eventosapp_task_queue_admin_styles();
    echo '<div class="wrap evapp-queue-wrap">';
    echo '<div class="evapp-queue-head"><div><span class="evapp-queue-kicker">EventosApp · Procesamiento</span><h1>Cola y Tareas</h1><p>Administra envíos masivos y programados, revisa consumo de recursos y permite que varias tareas compartan el servidor sin ejecutarse todas al mismo tiempo.</p></div><div><a class="button" href="' . esc_url(admin_url('admin.php?page=eventosapp_task_queue')) . '">Actualizar</a></div></div>';

    if ( isset($_GET['evapp_queue_msg']) ) {
        $ok = ! empty($_GET['evapp_queue_ok']);
        echo '<div class="notice ' . ($ok?'notice-success':'notice-error') . ' is-dismissible"><p>' . esc_html(wp_unslash($_GET['evapp_queue_msg'])) . '</p></div>';
    }

    eventosapp_task_queue_render_resource_summary();
    if ( $task_id ) eventosapp_task_queue_render_task_detail($task_id);
    else eventosapp_task_queue_render_task_list();
    echo '</div>';
}

function eventosapp_task_queue_render_resource_summary() {
    global $wpdb;
    $table = eventosapp_task_queue_table('tasks');
    $counts = $wpdb->get_results("SELECT status, COUNT(*) qty FROM {$table} GROUP BY status", OBJECT_K);
    $snapshot = eventosapp_task_queue_resource_snapshot();
    $config = eventosapp_task_queue_config();
    $active = absint($counts['running']->qty ?? 0);
    $queued = absint($counts['queued']->qty ?? 0) + absint($counts['scheduled']->qty ?? 0);
    ?>
    <div class="evapp-queue-summary">
        <div class="evapp-queue-summary-card"><span>Activas</span><strong><?php echo esc_html($active); ?> / <?php echo esc_html($config['max_concurrent']); ?></strong><small>Workers simultáneos máximos</small></div>
        <div class="evapp-queue-summary-card"><span>Pendientes</span><strong><?php echo esc_html($queued); ?></strong><small>En cola o programadas</small></div>
        <div class="evapp-queue-summary-card"><span>Memoria PHP</span><strong><?php echo esc_html(number_format((float)$snapshot['memory_percent'], 1, ',', '.')); ?>%</strong><small><?php echo esc_html(eventosapp_task_queue_admin_format_bytes($snapshot['memory_usage'])); ?> de <?php echo esc_html(eventosapp_task_queue_admin_format_bytes($snapshot['memory_limit'])); ?></small></div>
        <div class="evapp-queue-summary-card"><span>Carga 1 min</span><strong><?php echo $snapshot['load_1'] === null ? 'No disponible' : esc_html(number_format((float)$snapshot['load_1'], 2, ',', '.')); ?></strong><small><?php echo esc_html($snapshot['cpu_cores']); ?> núcleo(s) detectados</small></div>
    </div>
    <?php
}

function eventosapp_task_queue_render_task_list() {
    $statuses = eventosapp_task_queue_statuses();
    $status_filter = sanitize_key((string)($_GET['status'] ?? 'followup'));
    $status = '';
    $status_scope = 'unarchived';

    if ( $status_filter === 'all' ) {
        $status_scope = '';
    } elseif ( $status_filter === 'open' ) {
        $status_scope = 'open';
    } elseif ( $status_filter === 'terminal' ) {
        $status_scope = 'terminal';
    } elseif ( isset($statuses[$status_filter]) ) {
        $status = $status_filter;
        $status_scope = '';
    } elseif ( $status_filter !== 'followup' ) {
        $status_filter = 'followup';
    }

    $filters = [
        'status'=>$status,
        'status_scope'=>$status_scope,
        'event_id'=>absint($_GET['event_id'] ?? 0),
        'client_id'=>absint($_GET['client_id'] ?? 0),
        'event_date'=>sanitize_text_field((string)($_GET['event_date'] ?? '')),
        'channel'=>sanitize_key((string)($_GET['channel'] ?? '')),
        'task_group'=>sanitize_key((string)($_GET['task_group'] ?? '')),
        'task_type'=>sanitize_key((string)($_GET['task_type'] ?? '')),
        'search'=>sanitize_text_field((string)($_GET['s'] ?? '')),
        'page'=>max(1, absint($_GET['paged'] ?? 1)),
        'per_page'=>30,
    ];
    $result = eventosapp_task_queue_list($filters);
    $events = eventosapp_task_queue_admin_events();
    $clients = eventosapp_task_queue_admin_clients();
    $adapters = eventosapp_task_queue_adapters();
    $terminal_statuses = eventosapp_task_queue_terminal_statuses();
    $selectable_count = count($result['items']);
    ?>
    <form method="get" class="evapp-queue-filters">
        <input type="hidden" name="page" value="eventosapp_task_queue">
        <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="Buscar título, UUID o error">
        <select name="event_id"><option value="0">Todos los eventos</option><?php foreach($events as $event): ?><option value="<?php echo esc_attr($event->ID); ?>" <?php selected($filters['event_id'],$event->ID); ?>><?php echo esc_html($event->post_title); ?></option><?php endforeach; ?></select>
        <select name="client_id"><option value="0">Todos los clientes</option><?php foreach($clients as $client): ?><option value="<?php echo esc_attr($client->ID); ?>" <?php selected($filters['client_id'],$client->ID); ?>><?php echo esc_html($client->post_title); ?></option><?php endforeach; ?></select>
        <input type="date" name="event_date" value="<?php echo esc_attr($filters['event_date']); ?>" title="Fecha del evento">
        <select name="channel"><option value="">Todos los canales</option><?php foreach(['email'=>'Correo','whatsapp'=>'WhatsApp','flow'=>'Flow','mixed'=>'Mixto','email+whatsapp'=>'Correo + WhatsApp'] as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($filters['channel'],$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
        <select name="task_group"><option value="">Masivas y programadas</option><option value="massive" <?php selected($filters['task_group'],'massive'); ?>>Masivas</option><option value="scheduled" <?php selected($filters['task_group'],'scheduled'); ?>>Programadas</option></select>
        <select name="task_type"><option value="">Todos los tipos</option><?php foreach($adapters as $key=>$adapter): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($filters['task_type'],$key); ?>><?php echo esc_html($adapter['label'] ?? $key); ?></option><?php endforeach; ?></select>
        <select name="status">
            <option value="followup" <?php selected($status_filter,'followup'); ?>>En seguimiento (incluye finalizadas)</option>
            <option value="open" <?php selected($status_filter,'open'); ?>>Activas y pendientes</option>
            <option value="all" <?php selected($status_filter,'all'); ?>>Todas las tareas</option>
            <option value="terminal" <?php selected($status_filter,'terminal'); ?>>Todos los registros terminados</option>
            <?php foreach($statuses as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($status_filter,$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
        </select>
        <button class="button button-primary">Filtrar</button><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_task_queue')); ?>">Limpiar</a>
    </form>

    <div class="evapp-queue-toolbar">
        <div>
            <strong><?php echo esc_html(number_format_i18n($result['total'])); ?> tarea(s)</strong>
            <?php if($status_filter === 'followup'): ?><small class="evapp-queue-view-note">Las tareas finalizadas permanecen visibles hasta que las archives o elimines manualmente.</small><?php endif; ?>
        </div>
        <div class="evapp-queue-toolbar-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="evappQueueBulkForm" class="evapp-queue-bulk-form">
                <input type="hidden" name="action" value="eventosapp_task_queue_action">
                <?php wp_nonce_field('eventosapp_task_queue_action'); ?>
                <button type="submit" name="queue_action" value="archive_selected" class="button" id="evappQueueArchiveSelected" disabled>Archivar seleccionadas (0)</button>
                <button type="submit" name="queue_action" value="delete_selected" class="button evapp-button-danger" id="evappQueueDeleteSelected" disabled>Cancelar y borrar seleccionadas (0)</button>
            </form>
            <?php echo eventosapp_task_queue_admin_action_form(['id'=>0], 'delete_terminal', 'Borrar todos los registros terminados', 'button evapp-button-danger evapp-delete-all-terminal', '<input type="hidden" name="terminal_status" value="">'); ?>
        </div>
    </div>

    <div class="evapp-queue-table-wrap"><table class="widefat striped evapp-queue-table"><thead><tr>
        <th class="check-column"><input type="checkbox" id="evappQueueSelectAll" <?php disabled($selectable_count < 1); ?> aria-label="Seleccionar todas las tareas visibles"></th>
        <th>Tarea</th><th>Evento / Cliente</th><th>Tipo</th><th>Estado y progreso</th><th>Resultados</th><th>Último consumo</th><th>Programación</th><th>Acciones</th>
    </tr></thead><tbody>
    <?php if ( empty($result['items']) ): ?><tr><td colspan="9"><div class="evapp-queue-empty">No hay tareas con estos filtros.</div></td></tr><?php else: foreach($result['items'] as $task):
        $progress = eventosapp_task_queue_admin_progress($task);
        $metrics = is_array($task['resource_metrics'] ?? null) ? ($task['resource_metrics']['last_batch'] ?? []) : [];
        $after = is_array($metrics['after'] ?? null) ? $metrics['after'] : [];
        $client_title = $task['client_id'] ? get_the_title($task['client_id']) : '';
        $is_terminal = in_array($task['status'], $terminal_statuses, true);
        $is_archiveable = in_array($task['status'], eventosapp_task_queue_archiveable_statuses(), true);
        ?>
        <tr data-task-id="<?php echo esc_attr($task['id']); ?>">
            <th scope="row" class="check-column"><input type="checkbox" class="evapp-queue-select" name="task_ids[]" value="<?php echo esc_attr($task['id']); ?>" form="evappQueueBulkForm" data-archiveable="<?php echo $is_archiveable ? '1' : '0'; ?>" aria-label="Seleccionar <?php echo esc_attr($task['title']); ?>"></th>
            <td><strong><a href="<?php echo esc_url(eventosapp_task_queue_task_url($task['id'])); ?>"><?php echo esc_html($task['title']); ?></a></strong><br><code>#<?php echo esc_html($task['id']); ?> · <?php echo esc_html($task['uuid']); ?></code><br><small>Creada: <?php echo esc_html(eventosapp_task_queue_admin_local_datetime($task['created_at'], $task)); ?></small></td>
            <td><?php echo $task['event_id'] ? '<strong>'.esc_html(get_the_title($task['event_id'])).'</strong>' : '—'; ?><?php if($client_title): ?><br><small>Cliente: <?php echo esc_html($client_title); ?></small><?php endif; ?><?php if($task['event_date']): ?><br><small>Fecha evento: <?php echo esc_html($task['event_date']); ?></small><?php endif; ?></td>
            <td><?php echo esc_html(eventosapp_task_queue_admin_task_label($task['task_type'])); ?><br><small><?php echo $task['task_group']==='scheduled'?'Programada':'Masiva'; ?> · <?php echo esc_html($task['channel'] ?: 'Sin canal'); ?></small></td>
            <td><?php echo eventosapp_task_queue_admin_status_badge($task['status']); ?><div class="evapp-queue-progress"><span style="width:<?php echo esc_attr($progress); ?>%"></span></div><small><?php echo esc_html($progress); ?>% · <?php echo esc_html(number_format_i18n($task['processed_items'])); ?> / <?php echo esc_html(number_format_i18n($task['total_items'])); ?></small><?php if($task['last_error']): ?><p class="evapp-queue-error"><?php echo esc_html($task['last_error']); ?></p><?php endif; ?></td>
            <td><span class="evapp-queue-result ok">✓ <?php echo esc_html($task['success_items']); ?></span><br><span class="evapp-queue-result skip">↷ <?php echo esc_html($task['skipped_items']); ?></span><br><span class="evapp-queue-result error">× <?php echo esc_html($task['error_items']); ?></span></td>
            <td><?php if($after): ?><strong>Mem. <?php echo esc_html(number_format((float)($after['memory_percent']??0),1,',','.')); ?>%</strong><br><small>Pico <?php echo esc_html(number_format((float)($after['memory_peak_percent']??0),1,',','.')); ?>% · Carga <?php echo isset($after['load_1'])?esc_html(number_format((float)$after['load_1'],2,',','.')):'N/D'; ?></small><br><small><?php echo esc_html(number_format((float)($metrics['items_per_second']??0),2,',','.')); ?> ítems/s</small><?php else: ?>—<?php endif; ?></td>
            <td><?php $planned_timestamp = eventosapp_task_queue_planned_timestamp($task); ?><?php if($planned_timestamp): ?><strong><?php echo esc_html(eventosapp_task_queue_admin_planned_local_datetime($task)); ?></strong><br><small><?php echo esc_html(eventosapp_task_queue_task_timezone_name($task)); ?> · UTC: <?php echo esc_html(eventosapp_task_queue_admin_planned_utc_datetime($task)); ?></small><?php else: ?>Inmediata<?php endif; ?><br><small>Próximo turno: <?php echo esc_html(eventosapp_task_queue_admin_local_datetime($task['next_run_at'], $task)); ?></small></td>
            <td class="evapp-queue-actions"><a class="button button-small" href="<?php echo esc_url(eventosapp_task_queue_task_url($task['id'])); ?>">Gestionar</a><?php if($task['status']==='paused') echo eventosapp_task_queue_admin_action_form($task,'resume','Reanudar','button button-small'); elseif(!$is_terminal) echo eventosapp_task_queue_admin_action_form($task,'pause','Pausar','button button-small'); ?><?php if(!$is_terminal) echo eventosapp_task_queue_admin_action_form($task,'cancel','Cancelar','button button-small evapp-button-danger'); ?><?php if($is_archiveable) echo eventosapp_task_queue_admin_action_form($task,'archive','Archivar','button button-small evapp-archive-task'); ?></td>
        </tr>
    <?php endforeach; endif; ?></tbody></table></div>
    <?php
    if ( $result['pages'] > 1 ) {
        $base = remove_query_arg('paged');
        echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links(['base'=>add_query_arg('paged','%#%',$base),'format'=>'','current'=>$result['page'],'total'=>$result['pages']]) . '</div></div>';
    }
    ?>
    <script>
    jQuery(function($){
        const $all = $('#evappQueueSelectAll');
        const $boxes = $('.evapp-queue-select');
        const $archiveButton = $('#evappQueueArchiveSelected');
        const $deleteButton = $('#evappQueueDeleteSelected');
        const $bulkForm = $('#evappQueueBulkForm');
        let bulkAction = '';

        function updateBulkState(){
            const checked = $boxes.filter(':checked').length;
            const archiveable = $boxes.filter(':checked').filter(function(){ return $(this).data('archiveable') === 1; }).length;
            $archiveButton.prop('disabled', archiveable < 1).text('Archivar seleccionadas (' + archiveable + ')');
            $deleteButton.prop('disabled', checked < 1).text('Cancelar y borrar seleccionadas (' + checked + ')');
            $all.prop('checked', $boxes.length > 0 && checked === $boxes.length);
            $all.prop('indeterminate', checked > 0 && checked < $boxes.length);
        }

        $all.on('change', function(){
            $boxes.prop('checked', this.checked);
            updateBulkState();
        });
        $boxes.on('change', updateBulkState);
        $bulkForm.find('button[name="queue_action"]').on('click', function(){
            bulkAction = $(this).val();
        });
        $bulkForm.on('submit', function(event){
            const submitter = event.originalEvent && event.originalEvent.submitter ? event.originalEvent.submitter : null;
            const action = submitter ? $(submitter).val() : bulkAction;
            const count = $boxes.filter(':checked').length;
            const archiveable = $boxes.filter(':checked').filter(function(){ return $(this).data('archiveable') === 1; }).length;

            if ( action === 'archive_selected' ) {
                if ( archiveable < 1 || ! window.confirm('¿Archivar las ' + archiveable + ' tareas terminadas seleccionadas? Sus resultados y logs se conservarán.') ) {
                    event.preventDefault();
                }
                return;
            }

            if ( count < 1 || ! window.confirm('¿Cancelar las tareas activas seleccionadas y eliminar permanentemente las ' + count + ' tareas junto con sus logs?') ) {
                event.preventDefault();
            }
        });
        $('.evapp-archive-task').closest('form').on('submit', function(event){
            if ( ! window.confirm('¿Archivar esta tarea? Sus resultados y logs se conservarán y podrás consultarla con el filtro Archivada.') ) {
                event.preventDefault();
            }
        });
        $('.evapp-delete-all-terminal').closest('form').on('submit', function(event){
            if ( ! window.confirm('¿Eliminar permanentemente todos los registros finalizados, cancelados, vencidos, con error y archivados?') ) {
                event.preventDefault();
            }
        });
        updateBulkState();
    });
    </script>
    <?php
}

function eventosapp_task_queue_render_task_detail($task_id) {
    $task = eventosapp_task_queue_get($task_id);
    if ( ! $task ) {
        echo '<div class="notice notice-error"><p>La tarea no existe.</p></div><p><a class="button" href="' . esc_url(admin_url('admin.php?page=eventosapp_task_queue')) . '">Volver</a></p>';
        return;
    }
    $progress = eventosapp_task_queue_admin_progress($task);
    $metrics = is_array($task['resource_metrics'] ?? null) ? $task['resource_metrics'] : [];
    $last = is_array($metrics['last_batch'] ?? null) ? $metrics['last_batch'] : [];
    $after = is_array($last['after'] ?? null) ? $last['after'] : [];
    $logs = eventosapp_task_queue_get_logs($task_id, 300);
    $task_timezone = eventosapp_task_queue_task_timezone_object($task);
    $task_timezone_name = $task_timezone->getName();
    $planned_timestamp = eventosapp_task_queue_planned_timestamp($task);
    $planned_local = $planned_timestamp ? (new DateTimeImmutable('@' . $planned_timestamp))->setTimezone($task_timezone) : null;
    $schedule_date_value = $planned_local ? $planned_local->format('Y-m-d') : '';
    $schedule_time_value = $planned_local ? $planned_local->format('H:i') : '';
    ?>
    <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_task_queue')); ?>">← Volver a la cola</a></p>
    <div class="evapp-queue-detail" id="evappQueueTask" data-task-id="<?php echo esc_attr($task_id); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('eventosapp_task_queue_status')); ?>">
        <div class="evapp-queue-detail-main">
            <div class="evapp-queue-card">
                <div class="evapp-queue-card-head"><div><h2><?php echo esc_html($task['title']); ?></h2><p><code><?php echo esc_html($task['uuid']); ?></code></p></div><?php echo eventosapp_task_queue_admin_status_badge($task['status']); ?></div>
                <div class="evapp-queue-progress big"><span style="width:<?php echo esc_attr($progress); ?>%"></span></div>
                <div class="evapp-queue-detail-stats"><div><span>Progreso</span><strong><?php echo esc_html($progress); ?>%</strong></div><div><span>Procesados</span><strong><?php echo esc_html($task['processed_items']); ?> / <?php echo esc_html($task['total_items']); ?></strong></div><div><span>Correctos</span><strong><?php echo esc_html($task['success_items']); ?></strong></div><div><span>Omitidos</span><strong><?php echo esc_html($task['skipped_items']); ?></strong></div><div><span>Errores</span><strong><?php echo esc_html($task['error_items']); ?></strong></div></div>
                <?php if($task['last_error']): ?><div class="notice <?php echo $task['status']==='expired'?'notice-warning':'notice-error'; ?> inline"><p><?php echo esc_html($task['last_error']); ?></p></div><?php endif; ?>
            </div>

            <div class="evapp-queue-card"><h3>Historial técnico</h3><div class="evapp-queue-logs"><?php if(!$logs): ?><p>La tarea todavía no tiene registros.</p><?php else: foreach($logs as $log): ?><div class="log-<?php echo esc_attr($log['level']); ?>"><time><?php echo esc_html(eventosapp_task_queue_admin_local_datetime($log['created_at'], $task)); ?></time><strong><?php echo esc_html(strtoupper($log['level'])); ?></strong><span><?php echo esc_html($log['message']); ?></span><?php if(!empty($log['context'])): ?><details><summary>Contexto</summary><pre><?php echo esc_html(wp_json_encode($log['context'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?></pre></details><?php endif; ?></div><?php endforeach; endif; ?></div></div>
        </div>

        <aside class="evapp-queue-detail-side">
            <div class="evapp-queue-card"><h3>Controles</h3>
                <?php if($task['status']==='paused'): echo eventosapp_task_queue_admin_action_form($task,'resume','Reanudar tarea','button button-primary button-large'); elseif(!in_array($task['status'],eventosapp_task_queue_terminal_statuses(),true)): echo eventosapp_task_queue_admin_action_form($task,'pause','Pausar tarea','button button-secondary button-large'); endif; ?>
                <?php if(!in_array($task['status'],eventosapp_task_queue_terminal_statuses(),true)): echo eventosapp_task_queue_admin_action_form($task,'cancel','Cancelar tarea','button evapp-button-danger button-large','<input type="hidden" name="reason" value="Cancelada desde Cola y Tareas.">'); endif; ?>
                <?php if(in_array($task['status'],eventosapp_task_queue_archiveable_statuses(),true)): echo eventosapp_task_queue_admin_action_form($task,'archive','Archivar tarea','button button-secondary button-large evapp-archive-task'); endif; ?>
                <?php if(in_array($task['status'],eventosapp_task_queue_terminal_statuses(),true)): echo eventosapp_task_queue_admin_action_form($task,'delete','Eliminar registro','button evapp-button-danger button-large'); endif; ?>
                <hr><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="eventosapp_task_queue_action"><input type="hidden" name="queue_action" value="reschedule"><input type="hidden" name="task_id" value="<?php echo esc_attr($task_id); ?>"><?php wp_nonce_field('eventosapp_task_queue_action'); ?><label><strong>Reprogramar</strong></label><p class="description">La fecha y hora se interpretan en <strong><?php echo esc_html($task_timezone_name); ?></strong>, zona horaria del evento.</p><input type="date" name="schedule_date" value="<?php echo esc_attr($schedule_date_value); ?>" required><input type="time" name="schedule_time" value="<?php echo esc_attr($schedule_time_value); ?>" required><button class="button" type="submit">Guardar nueva fecha</button></form>
            </div>
            <div class="evapp-queue-card"><h3>Notificaciones</h3><p>Se envían correos al 20%, 40%, 60%, 80%, 100%, al cancelar y al fallar.</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="eventosapp_task_queue_action"><input type="hidden" name="queue_action" value="update_email"><input type="hidden" name="task_id" value="<?php echo esc_attr($task_id); ?>"><?php wp_nonce_field('eventosapp_task_queue_action'); ?><input type="email" name="notification_email" value="<?php echo esc_attr($task['notification_email']); ?>" placeholder="correo@dominio.com"><button class="button" type="submit">Actualizar correo</button></form></div>
            <div class="evapp-queue-card"><h3>Recursos</h3><?php if($after): ?><dl class="evapp-queue-dl"><dt>Memoria actual</dt><dd><?php echo esc_html(number_format((float)($after['memory_percent']??0),2,',','.')); ?>%</dd><dt>Pico de memoria</dt><dd><?php echo esc_html(number_format((float)($after['memory_peak_percent']??0),2,',','.')); ?>%</dd><dt>Carga 1 min</dt><dd><?php echo isset($after['load_1'])?esc_html(number_format((float)$after['load_1'],2,',','.')):'N/D'; ?></dd><dt>Duración lote</dt><dd><?php echo esc_html(number_format((float)($last['elapsed_seconds']??0),3,',','.')); ?> s</dd><dt>CPU del lote</dt><dd><?php echo isset($last['cpu_ms_delta']) && $last['cpu_ms_delta'] !== null ? esc_html(number_format((float)$last['cpu_ms_delta'],2,',','.').' ms') : 'N/D'; ?></dd><dt>Velocidad</dt><dd><?php echo esc_html(number_format((float)($last['items_per_second']??0),3,',','.')); ?> ítems/s</dd><dt>Lotes ejecutados</dt><dd><?php echo esc_html(absint($metrics['batches']??0)); ?></dd></dl><?php else: ?><p>Aún no hay métricas de ejecución.</p><?php endif; ?></div>
            <div class="evapp-queue-card"><h3>Datos</h3><dl class="evapp-queue-dl"><dt>Evento</dt><dd><?php echo $task['event_id']?esc_html(get_the_title($task['event_id'])):'—'; ?></dd><dt>Cliente</dt><dd><?php echo $task['client_id']?esc_html(get_the_title($task['client_id'])):'—'; ?></dd><dt>Canal</dt><dd><?php echo esc_html($task['channel'] ?: '—'); ?></dd><dt>Tipo</dt><dd><?php echo esc_html(eventosapp_task_queue_admin_task_label($task['task_type'])); ?></dd><dt>Zona horaria</dt><dd><?php echo esc_html($task_timezone_name); ?></dd><dt>Programada local</dt><dd><?php echo esc_html(eventosapp_task_queue_admin_planned_local_datetime($task)); ?></dd><dt>Programada UTC</dt><dd><?php echo esc_html(eventosapp_task_queue_admin_planned_utc_datetime($task)); ?></dd><dt>Próximo turno</dt><dd><?php echo esc_html(eventosapp_task_queue_admin_local_datetime($task['next_run_at'], $task)); ?></dd></dl></div>
        </aside>
    </div>
    <script>
    jQuery(function($){
        $('.evapp-archive-task').closest('form').on('submit', function(event){
            if ( ! window.confirm('¿Archivar esta tarea? Sus resultados y logs se conservarán y podrás consultarla con el filtro Archivada.') ) {
                event.preventDefault();
            }
        });
        const $box=$('#evappQueueTask'); if(!$box.length)return;
        const taskId=$box.data('task-id'), nonce=$box.data('nonce');
        let timer=window.setInterval(function(){
            $.post(ajaxurl,{action:'eventosapp_task_queue_status',task_id:taskId,nonce:nonce},function(r){
                if(!r||!r.success)return;
                if(r.data.terminal){window.clearInterval(timer);}
                if(r.data.status==='running'||r.data.status==='queued'){window.location.reload();}
            });
        },12000);
    });
    </script>
    <?php
}

function eventosapp_task_queue_admin_styles() {
    ?>
    <style>
    .evapp-queue-wrap{max-width:1600px}.evapp-queue-head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin:18px 0}.evapp-queue-head h1{margin:0 0 6px;font-size:30px}.evapp-queue-head p{margin:0;color:#646970;max-width:980px}.evapp-queue-kicker{font-size:12px;font-weight:700;color:#3782c4;text-transform:uppercase;letter-spacing:.06em}.evapp-queue-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}.evapp-queue-summary-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.04)}.evapp-queue-summary-card span,.evapp-queue-summary-card small{display:block;color:#646970}.evapp-queue-summary-card strong{display:block;font-size:25px;margin:6px 0}.evapp-queue-filters{display:grid;grid-template-columns:2fr repeat(7,minmax(130px,1fr));gap:8px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:14px;margin:18px 0}.evapp-queue-filters input,.evapp-queue-filters select{width:100%}.evapp-queue-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:12px 0}.evapp-queue-toolbar-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.evapp-queue-view-note{display:block;color:#646970;margin-top:3px}.evapp-queue-bulk-form{display:inline-block;margin:2px}.evapp-queue-inline-form{display:inline-block;margin:2px}.evapp-queue-table-wrap{overflow:auto;background:#fff;border:1px solid #dcdcde;border-radius:12px}.evapp-queue-table{border:0;min-width:1300px}.evapp-queue-table th{font-weight:700}.evapp-queue-table .check-column{width:34px;text-align:center;vertical-align:middle}.evapp-queue-table .check-column input{margin:0}.evapp-queue-status{display:inline-flex;padding:4px 9px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;background:#eef2f7;color:#334155}.status-running{background:#dbeafe;color:#1d4ed8}.status-queued{background:#ede9fe;color:#6d28d9}.status-scheduled{background:#e0f2fe;color:#0369a1}.status-paused{background:#fef3c7;color:#92400e}.status-completed{background:#dcfce7;color:#166534}.status-cancelled{background:#f1f5f9;color:#475569}.status-expired{background:#ffedd5;color:#9a3412}.status-failed{background:#fee2e2;color:#991b1b}.status-archived{background:#e2e8f0;color:#334155}.evapp-queue-progress{height:8px;background:#e5e7eb;border-radius:999px;overflow:hidden;margin:8px 0}.evapp-queue-progress span{display:block;height:100%;background:linear-gradient(90deg,#3782c4,#6d5dfc)}.evapp-queue-progress.big{height:18px}.evapp-queue-result{font-weight:700}.evapp-queue-result.ok{color:#15803d}.evapp-queue-result.skip{color:#a16207}.evapp-queue-result.error,.evapp-queue-error{color:#b91c1c}.evapp-queue-error{font-size:11px;max-width:240px}.evapp-button-danger{border-color:#d63638!important;color:#b32d2e!important}.evapp-queue-actions{white-space:nowrap}.evapp-queue-empty{text-align:center;padding:40px;color:#646970}.evapp-queue-detail{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:18px}.evapp-queue-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;margin-bottom:18px;box-shadow:0 1px 3px rgba(0,0,0,.04)}.evapp-queue-card h2,.evapp-queue-card h3{margin-top:0}.evapp-queue-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:15px}.evapp-queue-card-head p{margin:4px 0}.evapp-queue-detail-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin:18px 0}.evapp-queue-detail-stats div{background:#f8fafc;border-radius:9px;padding:12px;text-align:center}.evapp-queue-detail-stats span{display:block;color:#64748b;font-size:11px;text-transform:uppercase}.evapp-queue-detail-stats strong{display:block;font-size:20px;margin-top:4px}.evapp-queue-logs{max-height:650px;overflow:auto;background:#111827;color:#d1d5db;border-radius:9px;padding:12px}.evapp-queue-logs>div{display:grid;grid-template-columns:140px 70px 1fr;gap:8px;padding:7px;border-bottom:1px solid #263244}.evapp-queue-logs time{color:#94a3b8}.evapp-queue-logs strong{font-size:11px}.evapp-queue-logs .log-error strong{color:#f87171}.evapp-queue-logs .log-success strong{color:#4ade80}.evapp-queue-logs .log-warning strong{color:#facc15}.evapp-queue-logs details{grid-column:1/-1}.evapp-queue-logs pre{white-space:pre-wrap}.evapp-queue-detail-side form input[type=date],.evapp-queue-detail-side form input[type=time],.evapp-queue-detail-side form input[type=email],.evapp-queue-detail-side form button{width:100%;margin-top:8px}.evapp-queue-detail-side .button-large{width:100%;text-align:center;margin-bottom:8px}.evapp-queue-dl{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin:0}.evapp-queue-dl dt{color:#646970}.evapp-queue-dl dd{margin:0;text-align:right;font-weight:600}@media(max-width:1200px){.evapp-queue-filters{grid-template-columns:repeat(3,1fr)}.evapp-queue-summary{grid-template-columns:repeat(2,1fr)}.evapp-queue-detail{grid-template-columns:1fr}}@media(max-width:782px){.evapp-queue-head{display:block}.evapp-queue-summary,.evapp-queue-filters,.evapp-queue-detail-stats{grid-template-columns:1fr}.evapp-queue-toolbar{display:block}.evapp-queue-logs>div{grid-template-columns:1fr}.evapp-queue-logs details{grid-column:auto}}
    </style>
    <?php
}

/**
 * Aviso contextual en las pantallas masivas existentes.
 */
add_action('admin_notices', function() {
    if ( ! eventosapp_task_queue_admin_can_manage() ) return;
    $page = sanitize_key((string)($_GET['page'] ?? ''));
    $managed = ['eventosapp_email_masivo','eventosapp_whatsapp_masivo','eventosapp_whatsapp_flows_campaign','eventosapp_attendance_confirmation_bulk'];
    if ( ! in_array($page, $managed, true) ) return;
    echo '<div class="notice notice-info"><p><strong>Segundo plano activo:</strong> al iniciar esta campaña, EventosApp la enviará a la cola central. Puedes cerrar esta pestaña y administrarla desde <a href="' . esc_url(admin_url('admin.php?page=eventosapp_task_queue')) . '"><strong>Cola y Tareas</strong></a>.</p></div>';
});
