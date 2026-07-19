<?php
/**
 * EventosApp - Administración de confirmación de asistencia.
 *
 * Agrega:
 * - Confirmación Masiva de Asistencia.
 * - Metabox de programación en eventosapp_event.
 * - Campo de imagen dentro del metabox existente de WhatsApp.
 * - Metabox de estado y trazabilidad en eventosapp_ticket.
 *
 * Ruta: includes/admin/eventosapp-attendance-confirmation-admin.php
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) exit;

function eventosapp_attendance_confirmation_admin_capability() {
    return apply_filters('eventosapp_attendance_confirmation_admin_capability', 'manage_options');
}

function eventosapp_attendance_confirmation_admin_can_manage() {
    return current_user_can(eventosapp_attendance_confirmation_admin_capability());
}

add_action('admin_menu', function() {
    add_submenu_page(
        'eventosapp_dashboard',
        'Confirmación Masiva de Asistencia',
        'Confirmación Masiva de Asistencia',
        eventosapp_attendance_confirmation_admin_capability(),
        'eventosapp_attendance_confirmation_bulk',
        'eventosapp_attendance_confirmation_render_bulk_page',
        31
    );
}, 24);

function eventosapp_attendance_confirmation_admin_events() {
    return get_posts([
        'post_type'=>'eventosapp_event','post_status'=>['publish','draft','private'],'posts_per_page'=>-1,
        'orderby'=>'title','order'=>'ASC',
    ]);
}

function eventosapp_attendance_confirmation_admin_localidades($event_id) {
    $raw = $event_id ? get_post_meta(absint($event_id), '_eventosapp_localidades', true) : [];
    if ( ! is_array($raw) ) return [];
    $out = [];
    foreach ( $raw as $item ) {
        if ( is_array($item) ) $item = $item['nombre'] ?? $item['label'] ?? $item['name'] ?? '';
        $item = sanitize_text_field((string)$item);
        if ( $item !== '' && ! in_array($item, $out, true) ) $out[] = $item;
    }
    return $out;
}

function eventosapp_attendance_confirmation_admin_extra_fields($event_id) {
    $event_id = absint($event_id);
    $fields = [];
    if ( function_exists('eventosapp_get_event_extra_fields') ) {
        $fields = eventosapp_get_event_extra_fields($event_id);
    } else {
        $fields = get_post_meta($event_id, '_eventosapp_extra_fields', true);
    }
    return is_array($fields) ? $fields : [];
}

function eventosapp_attendance_confirmation_admin_template_label($template) {
    if ( ! is_array($template) ) return 'Plantilla no disponible';
    $title = sanitize_text_field((string)($template['title'] ?? $template['name'] ?? 'Plantilla'));
    $name = sanitize_key((string)($template['name'] ?? ''));
    $status = strtoupper(sanitize_text_field((string)($template['meta_status'] ?? 'LOCAL')));
    return $title . ($name !== '' ? ' — ' . $name : '') . ' · ' . $status;
}

function eventosapp_attendance_confirmation_render_notice_from_query() {
    if ( empty($_GET['evapp_attendance_msg']) ) return;
    $ok = ! empty($_GET['evapp_attendance_ok']);
    $message = sanitize_text_field(wp_unslash($_GET['evapp_attendance_msg']));
    echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . ' is-dismissible"><p><strong>EventosApp:</strong> ' . esc_html($message) . '</p></div>';
}

function eventosapp_attendance_confirmation_render_bulk_page() {
    if ( ! eventosapp_attendance_confirmation_admin_can_manage() ) wp_die('No tienes permisos para acceder a esta sección.');
    $step = max(1, min(3, absint($_GET['step'] ?? 1)));
    $segment_id = sanitize_key((string)($_GET['segment_id'] ?? ''));
    echo '<div class="wrap evapp-attendance-wrap">';
    echo '<h1>Confirmación Masiva de Asistencia</h1>';
    echo '<p class="description">Envía la consulta de confirmación por correo, WhatsApp o ambos canales, usando segmentación avanzada.</p>';
    eventosapp_attendance_confirmation_render_notice_from_query();
    echo '<h2 class="nav-tab-wrapper">';
    $tabs = [1=>'Configuración y filtros',2=>'Vista previa',3=>'Envío'];
    foreach ( $tabs as $number => $label ) {
        $url = add_query_arg(['page'=>'eventosapp_attendance_confirmation_bulk','step'=>$number], admin_url('admin.php'));
        if ( $segment_id && $number > 1 ) $url = add_query_arg('segment_id', $segment_id, $url);
        echo '<a class="nav-tab ' . ($step === $number ? 'nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
    echo '</h2>';
    if ( $step === 2 ) eventosapp_attendance_confirmation_render_bulk_preview($segment_id);
    elseif ( $step === 3 ) eventosapp_attendance_confirmation_render_bulk_send($segment_id);
    else eventosapp_attendance_confirmation_render_bulk_form();
    echo '</div>';
}

function eventosapp_attendance_confirmation_render_bulk_styles() {
    ?>
    <style>
    .evapp-attendance-wrap{max-width:1320px}.evapp-attendance-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.evapp-attendance-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px;margin-top:18px}.evapp-attendance-card h2,.evapp-attendance-card h3{margin-top:0}.evapp-attendance-field{margin-bottom:14px}.evapp-attendance-field label{display:block;font-weight:600;margin-bottom:5px}.evapp-attendance-field input[type=text],.evapp-attendance-field input[type=date],.evapp-attendance-field input[type=time],.evapp-attendance-field select,.evapp-attendance-field textarea{width:100%;max-width:none}.evapp-attendance-inline{display:flex;gap:16px;align-items:center;flex-wrap:wrap}.evapp-attendance-help{color:#646970;font-size:12px;margin-top:4px}.evapp-attendance-table{width:100%;border-collapse:collapse}.evapp-attendance-table th,.evapp-attendance-table td{padding:9px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}.evapp-attendance-badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#eef3ff;color:#2745a6;font-size:11px;font-weight:600}.evapp-attendance-progress{height:18px;background:#e5e7eb;border-radius:999px;overflow:hidden}.evapp-attendance-progress>span{display:block;height:100%;width:0;background:#2271b1;transition:width .2s}.evapp-attendance-log{max-height:280px;overflow:auto;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px}.evapp-attendance-log div{padding:5px 0;border-bottom:1px solid #ddd}.evapp-attendance-log div:last-child{border-bottom:0}@media(max-width:900px){.evapp-attendance-grid{grid-template-columns:1fr}}
    </style>
    <?php
}

function eventosapp_attendance_confirmation_render_filter_fields($prefix = 'filters', $event_id = 0, $values = []) {
    $values = is_array($values) ? $values : [];
    $localidades = eventosapp_attendance_confirmation_admin_localidades($event_id);
    $status_options = eventosapp_attendance_confirmation_status_options();
    $creation_channels = function_exists('eventosapp_creation_channel_labels') ? eventosapp_creation_channel_labels() : [
        'public'=>'Inscripción Usuario','manual'=>'Manual','webhook'=>'Integración','import'=>'Importación',
    ];
    ?>
    <div class="evapp-attendance-grid">
        <div class="evapp-attendance-field">
            <label>Localidad</label>
            <select name="<?php echo esc_attr($prefix); ?>[localidad]" class="evapp-attendance-localidad-select">
                <option value="">Todas</option>
                <?php foreach ($localidades as $localidad): ?><option value="<?php echo esc_attr($localidad); ?>" <?php selected($values['localidad'] ?? '', $localidad); ?>><?php echo esc_html($localidad); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="evapp-attendance-field">
            <label>Modalidad del ticket</label>
            <select name="<?php echo esc_attr($prefix); ?>[modalidad]">
                <option value="">Todas</option><option value="presencial" <?php selected($values['modalidad'] ?? '', 'presencial'); ?>>Presencial</option><option value="virtual" <?php selected($values['modalidad'] ?? '', 'virtual'); ?>>Virtual</option>
            </select>
        </div>
        <div class="evapp-attendance-field">
            <label>Estado de confirmación</label>
            <select name="<?php echo esc_attr($prefix); ?>[confirmation_status]">
                <option value="">Todos</option>
                <?php foreach ($status_options as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($values['confirmation_status'] ?? '', $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="evapp-attendance-field">
            <label>Consulta por correo</label>
            <select name="<?php echo esc_attr($prefix); ?>[email_status]"><option value="">Todos</option><option value="enviado" <?php selected($values['email_status'] ?? '', 'enviado'); ?>>Enviado</option><option value="no_enviado" <?php selected($values['email_status'] ?? '', 'no_enviado'); ?>>No enviado</option></select>
        </div>
        <div class="evapp-attendance-field">
            <label>Consulta por WhatsApp</label>
            <select name="<?php echo esc_attr($prefix); ?>[whatsapp_status]"><option value="">Todos</option><option value="enviado" <?php selected($values['whatsapp_status'] ?? '', 'enviado'); ?>>Enviado</option><option value="no_enviado" <?php selected($values['whatsapp_status'] ?? '', 'no_enviado'); ?>>No enviado</option></select>
        </div>
        <div class="evapp-attendance-field">
            <label>Entrega de WhatsApp</label>
            <select name="<?php echo esc_attr($prefix); ?>[delivery_status]"><option value="">Todos</option><option value="pendiente_webhook" <?php selected($values['delivery_status'] ?? '', 'pendiente_webhook'); ?>>Pendiente de webhook</option><option value="sent" <?php selected($values['delivery_status'] ?? '', 'sent'); ?>>Enviado</option><option value="delivered" <?php selected($values['delivery_status'] ?? '', 'delivered'); ?>>Entregado</option><option value="read" <?php selected($values['delivery_status'] ?? '', 'read'); ?>>Leído</option><option value="failed" <?php selected($values['delivery_status'] ?? '', 'failed'); ?>>Fallido</option></select>
        </div>
        <div class="evapp-attendance-field">
            <label>Canal de creación</label>
            <select name="<?php echo esc_attr($prefix); ?>[creation_channel]"><option value="">Todos</option><?php foreach($creation_channels as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($values['creation_channel'] ?? '', $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
        </div>
        <div class="evapp-attendance-field">
            <label>Fecha específica del evento</label>
            <input type="date" name="<?php echo esc_attr($prefix); ?>[event_date]" value="<?php echo esc_attr($values['event_date'] ?? ''); ?>">
        </div>
        <div class="evapp-attendance-field">
            <label>Ticket creado desde</label>
            <input type="date" name="<?php echo esc_attr($prefix); ?>[created_from]" value="<?php echo esc_attr($values['created_from'] ?? ''); ?>">
        </div>
        <div class="evapp-attendance-field">
            <label>Ticket creado hasta</label>
            <input type="date" name="<?php echo esc_attr($prefix); ?>[created_to]" value="<?php echo esc_attr($values['created_to'] ?? ''); ?>">
        </div>
    </div>
    <?php if ($event_id): ?>
        <?php $extras = eventosapp_attendance_confirmation_admin_extra_fields($event_id); if ($extras): ?>
            <h4>Campos adicionales</h4>
            <div class="evapp-attendance-grid">
            <?php foreach ($extras as $field): $key=sanitize_key((string)($field['key']??'')); if(!$key)continue; $label=sanitize_text_field((string)($field['label']??$key)); $options=is_array($field['options']??null)?$field['options']:[]; ?>
                <div class="evapp-attendance-field"><label><?php echo esc_html($label); ?></label>
                <?php if($options): ?><select name="<?php echo esc_attr($prefix); ?>[extra_fields][<?php echo esc_attr($key); ?>]"><option value="">Todos</option><?php foreach($options as $option): ?><option value="<?php echo esc_attr($option); ?>" <?php selected($values['extra_fields'][$key]??'', $option); ?>><?php echo esc_html($option); ?></option><?php endforeach; ?></select>
                <?php else: ?><input type="text" name="<?php echo esc_attr($prefix); ?>[extra_fields][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($values['extra_fields'][$key]??''); ?>" placeholder="Contiene..."><?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif;
}

function eventosapp_attendance_confirmation_render_message_configuration($values = [], $context = 'bulk') {
    $values = is_array($values) ? $values : [];
    $name_prefix = $context === 'event' ? 'evapp_attendance_schedule' : 'config';
    $email_templates = eventosapp_attendance_confirmation_email_templates();
    $wa_templates = eventosapp_attendance_confirmation_get_whatsapp_templates(false);
    $channels = eventosapp_attendance_confirmation_sanitize_channels($values['channels'] ?? ['email']);
    ?>
    <div class="evapp-attendance-card">
        <h2>Canales y plantillas</h2>
        <div class="evapp-attendance-inline" style="margin-bottom:18px">
            <label><input type="checkbox" name="<?php echo esc_attr($name_prefix); ?>[channels][]" value="email" <?php checked(in_array('email',$channels,true)); ?>> Correo electrónico</label>
            <label><input type="checkbox" name="<?php echo esc_attr($name_prefix); ?>[channels][]" value="whatsapp" <?php checked(in_array('whatsapp',$channels,true)); ?>> WhatsApp</label>
        </div>
        <div class="evapp-attendance-grid">
            <div>
                <h3>Correo</h3>
                <div class="evapp-attendance-field"><label>Plantilla de correo</label><select name="<?php echo esc_attr($name_prefix); ?>[email_template]">
                    <?php foreach($email_templates as $file=>$label): ?><option value="<?php echo esc_attr($file); ?>" <?php selected($values['email_template']??'attendance-confirmation.html',$file); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                </select><p class="evapp-attendance-help">Usa automáticamente el cabezote configurado en “Email del Ticket — Plantilla y Header”.</p></div>
                <div class="evapp-attendance-field"><label>Asunto</label><input type="text" name="<?php echo esc_attr($name_prefix); ?>[email_subject]" value="<?php echo esc_attr($values['email_subject']??'Confirma tu asistencia a {{evento_nombre}}'); ?>"><p class="evapp-attendance-help">Variables: {{nombre_completo}}, {{evento_nombre}}, {{evento_fecha}}, {{evento_hora}}, {{evento_lugar}}.</p></div>
                <div class="evapp-attendance-field"><label>Mensaje adicional</label><textarea name="<?php echo esc_attr($name_prefix); ?>[email_message]" rows="5"><?php echo esc_textarea($values['email_message']??''); ?></textarea></div>
            </div>
            <div>
                <h3>WhatsApp</h3>
                <div class="evapp-attendance-field"><label>Plantilla con botones Sí / No</label><select name="<?php echo esc_attr($name_prefix); ?>[whatsapp_template_id]">
                    <?php if(!$wa_templates): ?><option value="attendance_confirmation">Plantilla no creada todavía</option><?php endif; ?>
                    <?php foreach($wa_templates as $id=>$template): ?><option value="<?php echo esc_attr($id); ?>" <?php selected($values['whatsapp_template_id']??'attendance_confirmation',$id); ?>><?php echo esc_html(eventosapp_attendance_confirmation_admin_template_label($template)); ?></option><?php endforeach; ?>
                </select><p class="evapp-attendance-help">Solo se enviarán plantillas con estado APPROVED o ACTIVE en Meta.</p></div>
                <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_attendance_confirmation_template')); ?>">Configurar plantilla de confirmación</a></p>
                <?php if($context==='bulk'): ?><p class="evapp-attendance-help">La imagen se toma del campo “Imagen para confirmación de asistencia” configurado dentro del metabox de WhatsApp del evento.</p><?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

function eventosapp_attendance_confirmation_render_bulk_form() {
    eventosapp_attendance_confirmation_render_bulk_styles();
    $events = eventosapp_attendance_confirmation_admin_events();
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eventosapp_attendance_confirmation_create_segment">
        <?php wp_nonce_field('eventosapp_attendance_confirmation_create_segment','evapp_attendance_nonce'); ?>
        <div class="evapp-attendance-card">
            <h2>Evento y segmentación</h2>
            <div class="evapp-attendance-field"><label>Evento</label><select name="filters[evento_id]" id="evapp-attendance-event" required><option value="">Selecciona un evento</option><?php foreach($events as $event): ?><option value="<?php echo esc_attr($event->ID); ?>"><?php echo esc_html($event->post_title); ?></option><?php endforeach; ?></select></div>
            <div id="evapp-attendance-dynamic-filters"><p class="description">Selecciona el evento para cargar localidades y campos adicionales.</p></div>
        </div>
        <?php eventosapp_attendance_confirmation_render_message_configuration([], 'bulk'); ?>
        <p><?php submit_button('Crear segmentación y previsualizar','primary','submit',false); ?></p>
    </form>
    <script>
    jQuery(function($){
        $('#evapp-attendance-event').on('change',function(){
            const id=$(this).val(), target=$('#evapp-attendance-dynamic-filters');
            if(!id){target.html('<p class="description">Selecciona el evento para cargar localidades y campos adicionales.</p>');return;}
            target.html('<p>Cargando filtros…</p>');
            $.post(ajaxurl,{action:'eventosapp_attendance_confirmation_filter_fields',event_id:id,nonce:'<?php echo esc_js(wp_create_nonce('eventosapp_attendance_confirmation_filter_fields')); ?>'},function(r){
                target.html(r.success?r.data.html:'<p class="notice notice-error">'+(r.data&&r.data.message?r.data.message:'No se pudieron cargar los filtros.')+'</p>');
            });
        });
    });
    </script>
    <?php
}

add_action('wp_ajax_eventosapp_attendance_confirmation_filter_fields', function(){
    if(!eventosapp_attendance_confirmation_admin_can_manage()) wp_send_json_error(['message'=>'Permisos insuficientes.'],403);
    check_ajax_referer('eventosapp_attendance_confirmation_filter_fields','nonce');
    $event_id=absint($_POST['event_id']??0);
    if(!$event_id||get_post_type($event_id)!=='eventosapp_event') wp_send_json_error(['message'=>'Evento inválido.'],400);
    ob_start(); eventosapp_attendance_confirmation_render_filter_fields('filters',$event_id,[]); $html=ob_get_clean();
    wp_send_json_success(['html'=>$html]);
});

function eventosapp_attendance_confirmation_sanitize_config($raw) {
    $raw = is_array($raw)?$raw:[];
    return [
        'channels'=>eventosapp_attendance_confirmation_sanitize_channels($raw['channels']??[]),
        'email_template'=>sanitize_file_name((string)($raw['email_template']??'attendance-confirmation.html')),
        'email_subject'=>sanitize_text_field((string)($raw['email_subject']??'Confirma tu asistencia a {{evento_nombre}}')),
        'email_message'=>sanitize_textarea_field((string)($raw['email_message']??'')),
        'whatsapp_template_id'=>sanitize_key((string)($raw['whatsapp_template_id']??eventosapp_attendance_confirmation_whatsapp_template_id())),
    ];
}

add_action('admin_post_eventosapp_attendance_confirmation_create_segment', function(){
    if(!eventosapp_attendance_confirmation_admin_can_manage()) wp_die('Permisos insuficientes.');
    check_admin_referer('eventosapp_attendance_confirmation_create_segment','evapp_attendance_nonce');
    $filters=eventosapp_attendance_confirmation_sanitize_filters(wp_unslash($_POST['filters']??[]));
    $config=eventosapp_attendance_confirmation_sanitize_config(wp_unslash($_POST['config']??[]));
    if(empty($filters['evento_id'])||get_post_type(absint($filters['evento_id']))!=='eventosapp_event') wp_die('Debes seleccionar un evento válido.');
    if(empty($config['channels'])) wp_die('Debes seleccionar al menos un canal.');
    $ticket_ids=eventosapp_attendance_confirmation_get_filtered_tickets($filters);
    $segment_id='seg_' . time() . '_' . strtolower(wp_generate_password(8,false,false));
    $segment=[
        'id'=>$segment_id,'created_at'=>current_time('mysql'),'created_by'=>get_current_user_id(),
        'filters'=>$filters,'config'=>$config,'ticket_ids'=>$ticket_ids,'total'=>count($ticket_ids),
        'processed'=>0,'success'=>0,'partial'=>0,'errors'=>0,'log'=>[],
    ];
    eventosapp_attendance_confirmation_save_segment($segment_id,$segment);
    wp_safe_redirect(add_query_arg(['page'=>'eventosapp_attendance_confirmation_bulk','step'=>2,'segment_id'=>$segment_id],admin_url('admin.php')));exit;
});

function eventosapp_attendance_confirmation_render_bulk_preview($segment_id) {
    eventosapp_attendance_confirmation_render_bulk_styles();
    $segment=eventosapp_attendance_confirmation_get_segment($segment_id);
    if(!$segment){echo '<div class="notice notice-error"><p>La segmentación expiró o no existe.</p></div>';return;}
    $ids=(array)$segment['ticket_ids'];
    ?>
    <div class="evapp-attendance-card"><h2>Resumen</h2><p><strong><?php echo esc_html(count($ids)); ?></strong> tickets cumplen los filtros.</p><p>Canales: <?php echo esc_html(implode(', ',array_map('eventosapp_attendance_confirmation_channel_label',$segment['config']['channels']))); ?></p></div>
    <div class="evapp-attendance-card"><h2>Vista previa de destinatarios</h2>
    <?php if(!$ids): ?><p>No hay tickets para enviar.</p><?php else: ?><table class="evapp-attendance-table"><thead><tr><th>Ticket</th><th>Asistente</th><th>Contacto</th><th>Localidad / modalidad</th><th>Confirmación</th></tr></thead><tbody>
    <?php foreach(array_slice($ids,0,100) as $id): $values=eventosapp_attendance_confirmation_template_values($id); ?>
    <tr><td>#<?php echo esc_html($id); ?><br><small><?php echo esc_html(get_post_meta($id,'eventosapp_ticketID',true)); ?></small></td><td><?php echo esc_html($values['nombre_completo']); ?></td><td><?php echo esc_html(get_post_meta($id,'_eventosapp_asistente_email',true)); ?><br><?php echo esc_html(get_post_meta($id,'_eventosapp_asistente_tel',true)); ?></td><td><?php echo esc_html($values['localidad']); ?><br><small><?php echo esc_html($values['modalidad']); ?></small></td><td><span class="evapp-attendance-badge"><?php echo esc_html(eventosapp_attendance_confirmation_status_label(eventosapp_attendance_confirmation_get_status($id))); ?></span></td></tr>
    <?php endforeach; ?></tbody></table><?php if(count($ids)>100): ?><p class="description">Se muestran los primeros 100 tickets.</p><?php endif; ?><?php endif; ?></div>
    <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_attendance_confirmation_bulk')); ?>">Volver</a><?php if($ids): ?> <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page'=>'eventosapp_attendance_confirmation_bulk','step'=>3,'segment_id'=>$segment_id],admin_url('admin.php'))); ?>">Continuar al envío</a><?php endif; ?></p>
    <?php
}

function eventosapp_attendance_confirmation_render_bulk_send($segment_id) {
    eventosapp_attendance_confirmation_render_bulk_styles();
    $segment=eventosapp_attendance_confirmation_get_segment($segment_id);
    if(!$segment){echo '<div class="notice notice-error"><p>La segmentación expiró o no existe.</p></div>';return;}
    $total=absint($segment['total']);
    $channels=eventosapp_attendance_confirmation_sanitize_channels($segment['config']['channels']??[]);
    $limits=function_exists('eventosapp_attendance_confirmation_bulk_limits')
        ? eventosapp_attendance_confirmation_bulk_limits('attendance_confirmation_bulk',$channels)
        : ['batch_size'=>5,'ajax_delay_ms'=>1800,'max_execution'=>20,'memory_stop_ratio'=>0.72];
    ?>
    <div class="evapp-attendance-card"><h2>Procesar envío</h2>
        <p>Se procesarán <strong><?php echo esc_html($total); ?></strong> tickets con protección de recursos.</p>
        <ul style="list-style:disc;padding-left:20px">
            <li>Máximo <?php echo esc_html($limits['batch_size']); ?> tickets por solicitud.</li>
            <li>Pausa mínima de <?php echo esc_html(number_format($limits['ajax_delay_ms']/1000,1,',','.')); ?> segundos entre lotes.</li>
            <li>Corte automático por tiempo de ejecución o consumo de memoria.</li>
            <li>Bloqueo de concurrencia para impedir dos lotes simultáneos del mismo segmento.</li>
        </ul>
        <p><button type="button" class="button button-primary button-hero" id="evapp-attendance-start" <?php disabled($total===0); ?>>Iniciar envío</button></p>
        <div class="evapp-attendance-progress"><span id="evapp-attendance-bar"></span></div>
        <p id="evapp-attendance-status">Listo para iniciar.</p><div class="evapp-attendance-log" id="evapp-attendance-log"></div>
    </div>
    <script>
    jQuery(function($){
        const total=<?php echo (int)$total; ?>, segment='<?php echo esc_js($segment_id); ?>', nonce='<?php echo esc_js(wp_create_nonce('eventosapp_attendance_confirmation_process_batch')); ?>';
        const defaultDelay=<?php echo (int)$limits['ajax_delay_ms']; ?>;
        let offset=0,running=false;
        const log=(text)=>$('#evapp-attendance-log').prepend($('<div>').text(text));
        function scheduleNext(delay){ window.setTimeout(run, Math.max(750,parseInt(delay||defaultDelay,10))); }
        function run(){
            if(!running)return;
            $.post(ajaxurl,{action:'eventosapp_attendance_confirmation_process_batch',segment_id:segment,offset:offset,nonce:nonce},function(r){
                if(!r.success){
                    running=false;
                    $('#evapp-attendance-status').text(r.data&&r.data.message?r.data.message:'Error procesando el lote.');
                    $('#evapp-attendance-start').prop('disabled',false);
                    return;
                }
                const data=r.data||{};
                if(data.busy){
                    $('#evapp-attendance-status').text(data.message||'Otro lote está terminando. Se reintentará automáticamente.');
                    scheduleNext(data.retry_after_ms||defaultDelay*2);
                    return;
                }
                offset=parseInt(data.next_offset||offset,10);
                const pct=total?Math.min(100,Math.round(offset/total*100)):100;
                $('#evapp-attendance-bar').css('width',pct+'%');
                $('#evapp-attendance-status').text('Procesados '+Math.min(offset,total)+' de '+total+' · Correctos '+data.success+' · Parciales '+data.partial+' · Errores '+data.errors);
                (data.log||[]).forEach(log);
                if(data.resource_guard_triggered){ log('El lote se pausó preventivamente por el límite de recursos; continuará desde el mismo punto.'); }
                if(data.done){running=false;$('#evapp-attendance-status').append(' · Finalizado.');$('#evapp-attendance-start').prop('disabled',true);return;}
                scheduleNext(data.retry_after_ms||defaultDelay);
            }).fail(function(){
                $('#evapp-attendance-status').text('Error de conexión con WordPress. Se reintentará sin adelantar el cursor.');
                scheduleNext(defaultDelay*2);
            });
        }
        $('#evapp-attendance-start').on('click',function(){if(running)return;running=true;$(this).prop('disabled',true);run();});
    });
    </script>
    <?php
}

add_action('wp_ajax_eventosapp_attendance_confirmation_process_batch', function(){
    if(!eventosapp_attendance_confirmation_admin_can_manage()) wp_send_json_error(['message'=>'Permisos insuficientes.'],403);
    check_ajax_referer('eventosapp_attendance_confirmation_process_batch','nonce');
    $segment_id=sanitize_key((string)($_POST['segment_id']??''));
    $offset=absint($_POST['offset']??0);
    $segment=eventosapp_attendance_confirmation_get_segment($segment_id);
    if(!$segment) wp_send_json_error(['message'=>'La segmentación expiró.'],404);

    $channels=eventosapp_attendance_confirmation_sanitize_channels($segment['config']['channels']??[]);
    $limits=eventosapp_attendance_confirmation_bulk_limits('attendance_confirmation_bulk',$channels);
    $scope='attendance_bulk_segment:'.$segment_id;
    $lock=eventosapp_attendance_confirmation_acquire_lock($scope,$limits['lock_ttl']);
    if(is_wp_error($lock)){
        wp_send_json_success([
            'busy'=>true,'message'=>$lock->get_error_message(),'processed'=>0,'next_offset'=>$offset,
            'done'=>false,'success'=>absint($segment['success']??0),'partial'=>absint($segment['partial']??0),
            'errors'=>absint($segment['errors']??0),'log'=>[],'retry_after_ms'=>$limits['ajax_delay_ms']*2,
        ]);
    }

    $response=[];
    try{
        $started=microtime(true);
        $batch=array_slice((array)$segment['ticket_ids'],$offset,$limits['batch_size']);
        $batch_log=[];
        $processed_now=0;
        $resource_guard=false;

        foreach($batch as $ticket_id){
            if(eventosapp_attendance_confirmation_should_yield($started,$processed_now,$limits)){
                $resource_guard=true;
                break;
            }
            $ticket_id=absint($ticket_id);
            if(!$ticket_id)continue;
            $result=eventosapp_attendance_confirmation_send_ticket($ticket_id,[
                'channels'=>$channels,'email_template'=>$segment['config']['email_template'],
                'email_subject'=>$segment['config']['email_subject'],'email_message'=>$segment['config']['email_message'],
                'whatsapp_template_id'=>$segment['config']['whatsapp_template_id'],'source'=>'bulk',
                'source_key'=>$segment_id . ':' . $ticket_id,
            ]);
            if(!empty($result['ok'])&&empty($result['partial'])){$segment['success']++;$state='OK';}
            elseif(!empty($result['ok'])){$segment['partial']++;$state='PARCIAL';}
            else{$segment['errors']++;$state='ERROR';}
            $segment['processed']++;
            $processed_now++;
            $batch_log[]='#'.$ticket_id.' · '.$state.' · '.sanitize_text_field((string)($result['message']??''));
        }

        $segment['log']=array_slice(array_merge($batch_log,(array)$segment['log']),0,150);
        eventosapp_attendance_confirmation_save_segment($segment_id,$segment);
        $next=$offset+$processed_now;
        $done=$next>=absint($segment['total']);
        $response=[
            'busy'=>false,'processed'=>$processed_now,'next_offset'=>$next,'done'=>$done,
            'success'=>absint($segment['success']),'partial'=>absint($segment['partial']),
            'errors'=>absint($segment['errors']),'log'=>$batch_log,
            'retry_after_ms'=>$limits['ajax_delay_ms'],'batch_size'=>$limits['batch_size'],
            'resource_guard_triggered'=>$resource_guard,
        ];

        if($processed_now===0&&!$done){
            $response['retry_after_ms']=$limits['ajax_delay_ms']*2;
        }
    }finally{
        eventosapp_attendance_confirmation_release_lock($scope,$lock);
    }

    wp_send_json_success($response);
});

/**
 * Metabox de programación por evento.
 */
add_action('add_meta_boxes', function(){
    add_meta_box(
        'eventosapp_attendance_confirmation_schedule',
        'Confirmación de Asistencia — Programación',
        'eventosapp_attendance_confirmation_render_event_metabox',
        'eventosapp_event','normal','high'
    );
    add_meta_box(
        'eventosapp_attendance_confirmation_ticket',
        'Confirmación de Asistencia',
        'eventosapp_attendance_confirmation_render_ticket_metabox',
        'eventosapp_ticket','side','high'
    );
});

function eventosapp_attendance_confirmation_render_event_metabox($post) {
    $config=get_post_meta($post->ID,'_eventosapp_attendance_confirmation_schedule',true);
    $config=is_array($config)?$config:[];
    $filters=is_array($config['filters']??null)?$config['filters']:[];
    $log=get_post_meta($post->ID,'_eventosapp_attendance_confirmation_schedule_log',true);$log=is_array($log)?$log:[];
    $last_error=get_post_meta($post->ID,'_eventosapp_attendance_confirmation_schedule_last_error',true);
    $timezone_info=function_exists('eventosapp_attendance_confirmation_event_timezone_info')?eventosapp_attendance_confirmation_event_timezone_info($post->ID):['name'=>wp_timezone_string(),'source'=>'wordpress','current_time'=>current_time('mysql'),'utc_offset'=>''];
    $schedule_diagnostics=function_exists('eventosapp_attendance_confirmation_schedule_diagnostics')?eventosapp_attendance_confirmation_schedule_diagnostics($post->ID,$config):[];
    wp_nonce_field('eventosapp_attendance_confirmation_save_event','eventosapp_attendance_event_nonce');
    eventosapp_attendance_confirmation_render_bulk_styles();
    ?>
    <?php if($last_error): ?><div class="notice notice-error inline"><p><?php echo esc_html($last_error); ?></p></div><?php endif; ?>
    <div class="evapp-attendance-inline"><label><input type="checkbox" name="evapp_attendance_schedule[enabled]" value="1" <?php checked(!empty($config['enabled'])); ?>> Activar envío programado</label><span class="description">WP-Cron ejecutará el trabajo cuando el sitio reciba tráfico a partir de la hora indicada.</span></div>
    <div class="evapp-attendance-card" style="background:#f0f6fc">
        <h3>Zona horaria efectiva</h3>
        <p><strong><?php echo esc_html($timezone_info['name']); ?></strong> · UTC<?php echo esc_html($timezone_info['utc_offset']); ?></p>
        <p class="description">La fecha y hora se interpretan como hora local del evento. Fuente: <?php echo esc_html($timezone_info['source_label'] ?? ($timezone_info['source']==='event'?'Zona horaria configurada en el evento':'Zona horaria general de WordPress')); ?>. Hora actual allí: <?php echo esc_html($timezone_info['current_time']); ?>.</p>
        <?php if(!empty($timezone_info['warning'])): ?><p style="color:#b32d2e"><strong>Atención:</strong> <?php echo esc_html($timezone_info['warning']); ?></p><?php endif; ?>
        <?php if(!empty($schedule_diagnostics['stored_event_time'])): ?>
            <p><strong>Configuración guardada:</strong> <?php echo esc_html($schedule_diagnostics['stored_event_time']); ?><br><strong>Equivalente UTC:</strong> <?php echo esc_html($schedule_diagnostics['stored_utc_time']); ?></p>
            <p><strong>WP-Cron pendiente:</strong> <?php echo esc_html($schedule_diagnostics['next_cron_event_time']?:'No encontrado'); ?><?php if(!empty($schedule_diagnostics['next_cron_utc_time'])): ?><br><strong>WP-Cron UTC:</strong> <?php echo esc_html($schedule_diagnostics['next_cron_utc_time']); ?><?php endif; ?></p>
            <?php if(empty($schedule_diagnostics['cron_matches_configuration'])): ?><p style="color:#b32d2e"><strong>Atención:</strong> la tarea pendiente no coincide con la configuración o no existe. Guarda el evento para resincronizar.</p><?php else: ?><p style="color:#008a20"><strong>Verificado:</strong> WP-Cron coincide con la hora configurada.</p><?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="evapp-attendance-grid" style="margin-top:15px"><div class="evapp-attendance-field"><label>Fecha exacta en <?php echo esc_html($timezone_info['name']); ?></label><input type="date" name="evapp_attendance_schedule[date]" value="<?php echo esc_attr($config['date']??''); ?>"></div><div class="evapp-attendance-field"><label>Hora exacta en <?php echo esc_html($timezone_info['name']); ?></label><input type="time" name="evapp_attendance_schedule[time]" value="<?php echo esc_attr($config['time']??''); ?>"></div></div>
    <?php eventosapp_attendance_confirmation_render_message_configuration($config,'event'); ?>
    <div class="evapp-attendance-card"><h3>Filtros del envío programado</h3><?php eventosapp_attendance_confirmation_render_filter_fields('evapp_attendance_schedule[filters]',$post->ID,$filters); ?></div>
    <div class="evapp-attendance-card"><h3>Log de programación</h3><?php if(!$log): ?><p class="description">Sin registros.</p><?php else: ?><div class="evapp-attendance-log"><?php foreach(array_slice($log,0,50) as $entry): ?><div><strong><?php echo esc_html($entry['at_event_timezone']??($entry['at']??'')); ?></strong> · <?php echo esc_html($entry['message']??''); ?></div><?php endforeach; ?></div><?php endif; ?></div>
    <?php
}

add_action('save_post_eventosapp_event', function($post_id){
    if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;
    if(wp_is_post_revision($post_id))return;
    if(!isset($_POST['eventosapp_attendance_event_nonce'])||!wp_verify_nonce($_POST['eventosapp_attendance_event_nonce'],'eventosapp_attendance_confirmation_save_event'))return;
    if(!current_user_can('edit_post',$post_id))return;

    $image=isset($_POST['eventosapp_attendance_confirmation_whatsapp_image'])?esc_url_raw(wp_unslash($_POST['eventosapp_attendance_confirmation_whatsapp_image'])):'';
    if($image!=='')update_post_meta($post_id,'_eventosapp_attendance_confirmation_whatsapp_image',$image);else delete_post_meta($post_id,'_eventosapp_attendance_confirmation_whatsapp_image');

    $raw=isset($_POST['evapp_attendance_schedule'])&&is_array($_POST['evapp_attendance_schedule'])?wp_unslash($_POST['evapp_attendance_schedule']):[];
    $config=eventosapp_attendance_confirmation_sanitize_config($raw);
    $config['enabled']=!empty($raw['enabled']);
    $config['date']=sanitize_text_field((string)($raw['date']??''));
    $config['time']=sanitize_text_field((string)($raw['time']??''));
    $config['filters']=eventosapp_attendance_confirmation_sanitize_filters(array_merge((array)($raw['filters']??[]),['evento_id'=>$post_id]));
    $result=eventosapp_attendance_confirmation_schedule_event($post_id,$config);
    if(is_wp_error($result))update_post_meta($post_id,'_eventosapp_attendance_confirmation_schedule_last_error',$result->get_error_message());else delete_post_meta($post_id,'_eventosapp_attendance_confirmation_schedule_last_error');
},120,1);

/**
 * Inserta el selector de imagen dentro del metabox existente de WhatsApp.
 */
add_action('admin_enqueue_scripts', function($hook){
    $screen=function_exists('get_current_screen')?get_current_screen():null;
    if($screen&&$screen->post_type==='eventosapp_event'&&in_array($hook,['post.php','post-new.php'],true))wp_enqueue_media();
});

add_action('admin_footer-post.php','eventosapp_attendance_confirmation_inject_whatsapp_image_field');
add_action('admin_footer-post-new.php','eventosapp_attendance_confirmation_inject_whatsapp_image_field');
function eventosapp_attendance_confirmation_inject_whatsapp_image_field(){
    $screen=function_exists('get_current_screen')?get_current_screen():null;
    if(!$screen||$screen->post_type!=='eventosapp_event')return;
    $post_id=absint($_GET['post']??0);
    $url=$post_id?get_post_meta($post_id,'_eventosapp_attendance_confirmation_whatsapp_image',true):'';
    ?>
    <script>
    jQuery(function($){
        const target=$('#eventosapp_event_whatsapp_visuals .inside'); if(!target.length)return;
        const html=`<div class="evapp-attendance-wa-image" style="margin-top:18px;padding-top:16px;border-top:1px solid #dcdcde"><h4 style="margin:0 0 8px">Imagen para confirmación de asistencia</h4><p class="description">Esta imagen se usa como encabezado dinámico de la plantilla WhatsApp con botones Sí y No.</p><input type="url" id="evapp-attendance-wa-image-url" name="eventosapp_attendance_confirmation_whatsapp_image" value="<?php echo esc_js($url); ?>" style="width:100%"><p><button type="button" class="button" id="evapp-attendance-wa-image-select">Seleccionar imagen</button> <button type="button" class="button-link-delete" id="evapp-attendance-wa-image-remove">Quitar</button></p><div id="evapp-attendance-wa-image-preview"><?php if($url): ?><img src="<?php echo esc_url($url); ?>" style="max-width:320px;width:100%;height:auto"><?php endif; ?></div></div>`;
        target.append(html);
        let frame;
        $('#evapp-attendance-wa-image-select').on('click',function(e){e.preventDefault();if(frame){frame.open();return;}frame=wp.media({title:'Seleccionar imagen de confirmación',button:{text:'Usar esta imagen'},multiple:false});frame.on('select',function(){const a=frame.state().get('selection').first().toJSON();$('#evapp-attendance-wa-image-url').val(a.url);$('#evapp-attendance-wa-image-preview').html('<img src="'+a.url+'" style="max-width:320px;width:100%;height:auto">');});frame.open();});
        $('#evapp-attendance-wa-image-remove').on('click',function(e){e.preventDefault();$('#evapp-attendance-wa-image-url').val('');$('#evapp-attendance-wa-image-preview').empty();});
    });
    </script>
    <?php
}

function eventosapp_attendance_confirmation_render_ticket_metabox($post){
    eventosapp_attendance_confirmation_initialize_ticket($post->ID);
    $keys=eventosapp_attendance_confirmation_meta_keys();
    $status=eventosapp_attendance_confirmation_get_status($post->ID);
    $sent=eventosapp_attendance_confirmation_sanitize_channels(get_post_meta($post->ID,$keys['sent_channels'],true));
    $responses=eventosapp_attendance_confirmation_sanitize_channels(get_post_meta($post->ID,$keys['response_channels'],true));
    $history=eventosapp_attendance_confirmation_safe_history(get_post_meta($post->ID,$keys['history'],true));
    $conflict=get_post_meta($post->ID,$keys['conflict'],true)==='1';
    ?>
    <style>.evapp-att-ticket-row{display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid #eee}.evapp-att-ticket-history{max-height:220px;overflow:auto;margin-top:10px;background:#f6f7f7;padding:8px;border-radius:6px}.evapp-att-ticket-history div{padding:6px 0;border-bottom:1px solid #ddd;font-size:11px}</style>
    <div class="evapp-att-ticket-row"><strong>Estado</strong><span class="evapp-attendance-badge"><?php echo esc_html(eventosapp_attendance_confirmation_status_label($status)); ?></span></div>
    <div class="evapp-att-ticket-row"><strong>Enviado por</strong><span><?php echo $sent?esc_html(implode(', ',array_map('eventosapp_attendance_confirmation_channel_label',$sent))):'—'; ?></span></div>
    <div class="evapp-att-ticket-row"><strong>Respondió por</strong><span><?php echo $responses?esc_html(implode(', ',array_map('eventosapp_attendance_confirmation_channel_label',$responses))):'—'; ?></span></div>
    <div class="evapp-att-ticket-row"><strong>Último envío</strong><span><?php echo esc_html(get_post_meta($post->ID,$keys['last_sent_at'],true)?:'—'); ?></span></div>
    <div class="evapp-att-ticket-row"><strong>Última respuesta</strong><span><?php echo esc_html(get_post_meta($post->ID,$keys['last_response_at'],true)?:'—'); ?></span></div>
    <?php if($conflict): ?><div class="notice notice-warning inline"><p>Hay respuestas diferentes registradas en el historial. El estado visible corresponde a la respuesta más reciente.</p></div><?php endif; ?>
    <p><a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action'=>'eventosapp_attendance_confirmation_send_ticket','ticket_id'=>$post->ID,'channels'=>'email'],admin_url('admin-post.php')),'eventosapp_attendance_confirmation_send_ticket')); ?>">Enviar correo</a> <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action'=>'eventosapp_attendance_confirmation_send_ticket','ticket_id'=>$post->ID,'channels'=>'whatsapp'],admin_url('admin-post.php')),'eventosapp_attendance_confirmation_send_ticket')); ?>">Enviar WhatsApp</a></p>
    <?php if($history): ?><div class="evapp-att-ticket-history"><?php foreach(array_slice($history,0,25) as $entry): ?><div><strong><?php echo esc_html($entry['at']??''); ?></strong><br><?php echo esc_html($entry['message']??''); ?></div><?php endforeach; ?></div><?php endif; ?>
    <?php
}

add_action('admin_post_eventosapp_attendance_confirmation_send_ticket', function(){
    $ticket_id=absint($_GET['ticket_id']??0);
    if(!$ticket_id||!current_user_can('edit_post',$ticket_id))wp_die('Permisos insuficientes.');
    check_admin_referer('eventosapp_attendance_confirmation_send_ticket');
    $channels=eventosapp_attendance_confirmation_sanitize_channels($_GET['channels']??'email');
    $result=eventosapp_attendance_confirmation_send_ticket($ticket_id,['channels'=>$channels,'source'=>'ticket_metabox','force'=>true,'source_key'=>'ticket_metabox:'.$ticket_id.':'.time()]);
    wp_safe_redirect(add_query_arg(['post'=>$ticket_id,'action'=>'edit','evapp_attendance_ok'=>!empty($result['ok'])?1:0,'evapp_attendance_msg'=>rawurlencode($result['message']??'Proceso ejecutado.')],admin_url('post.php')));exit;
});

add_action('admin_notices', function(){
    $screen=function_exists('get_current_screen')?get_current_screen():null;
    if(!$screen||$screen->post_type!=='eventosapp_ticket'||empty($_GET['evapp_attendance_msg']))return;
    $ok=!empty($_GET['evapp_attendance_ok']);$message=sanitize_text_field(wp_unslash($_GET['evapp_attendance_msg']));
    echo '<div class="notice '.($ok?'notice-success':'notice-error').' is-dismissible"><p><strong>Confirmación de asistencia:</strong> '.esc_html($message).'</p></div>';
});
