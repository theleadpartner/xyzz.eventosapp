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

/**
 * Determina si una plantilla de confirmación está aprobada para envío.
 */
function eventosapp_attendance_confirmation_admin_template_is_approved($template) {
    if ( function_exists('eventosapp_attendance_confirmation_whatsapp_template_is_approved') ) {
        return eventosapp_attendance_confirmation_whatsapp_template_is_approved($template);
    }
    if ( function_exists('eventosapp_whatsapp_is_template_approved') ) {
        return eventosapp_whatsapp_is_template_approved($template);
    }
    return in_array(strtoupper((string)($template['meta_status'] ?? '')), ['APPROVED','ACTIVE'], true);
}

/**
 * Resuelve la plantilla seleccionada conservando configuraciones guardadas y,
 * para nuevos envíos, priorizando una plantilla aprobada.
 */
function eventosapp_attendance_confirmation_admin_resolve_template_id($requested, $templates) {
    $requested = sanitize_key((string)$requested);
    $templates = is_array($templates) ? $templates : [];

    if ( $requested !== '' && isset($templates[$requested]) ) {
        return $requested;
    }

    foreach ( $templates as $template_id => $template ) {
        if ( eventosapp_attendance_confirmation_admin_template_is_approved($template) ) {
            return sanitize_key((string)$template_id);
        }
    }

    if ( isset($templates['attendance_confirmation']) ) {
        return 'attendance_confirmation';
    }

    $template_ids = array_keys($templates);
    $first = reset($template_ids);
    return $first ? sanitize_key((string)$first) : 'attendance_confirmation';
}

/**
 * URL del inventario especializado de plantillas de confirmación.
 */
function eventosapp_attendance_confirmation_admin_templates_url($args = []) {
    return add_query_arg(
        array_merge(['page'=>'eventosapp_attendance_confirmation_template'], is_array($args) ? $args : []),
        admin_url('admin.php')
    );
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

    eventosapp_attendance_confirmation_render_bulk_styles();
    echo '<div class="wrap evapp-attendance-wrap">';
    echo '<div class="evapp-attendance-page-head">';
    echo '<div><span class="evapp-attendance-kicker">EventosApp · Asistencia</span><h1>Confirmación Masiva de Asistencia</h1><p>Segmenta asistentes y envía la consulta por correo, WhatsApp o ambos canales, usando cualquiera de las plantillas de confirmación disponibles.</p></div>';
    echo '<div class="evapp-attendance-page-actions"><a class="button" href="' . esc_url(eventosapp_attendance_confirmation_admin_templates_url()) . '">Gestionar plantillas</a><a class="button button-primary" href="' . esc_url(eventosapp_attendance_confirmation_admin_templates_url(['view'=>'new'])) . '">Crear plantilla</a></div>';
    echo '</div>';

    eventosapp_attendance_confirmation_render_notice_from_query();

    echo '<div class="evapp-attendance-steps">';
    $tabs = [1=>'1. Configuración y filtros',2=>'2. Vista previa',3=>'3. Envío'];
    foreach ( $tabs as $number => $label ) {
        $url = add_query_arg(['page'=>'eventosapp_attendance_confirmation_bulk','step'=>$number], admin_url('admin.php'));
        if ( $segment_id && $number > 1 ) $url = add_query_arg('segment_id', $segment_id, $url);
        $class = $step === $number ? 'is-active' : ($step > $number ? 'is-complete' : '');
        echo '<a class="evapp-attendance-step ' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
    echo '</div>';

    if ( $step === 2 ) eventosapp_attendance_confirmation_render_bulk_preview($segment_id);
    elseif ( $step === 3 ) eventosapp_attendance_confirmation_render_bulk_send($segment_id);
    else eventosapp_attendance_confirmation_render_bulk_form();
    echo '</div>';
}

function eventosapp_attendance_confirmation_render_bulk_styles() {
    ?>
    <style>
    .evapp-attendance-wrap{max-width:1440px;margin-top:18px}
    .evapp-attendance-page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:22px;margin:18px 0 20px}
    .evapp-attendance-page-head h1{margin:0 0 7px;font-size:28px;line-height:1.2}
    .evapp-attendance-page-head p{margin:0;color:#646970;font-size:14px;line-height:1.5;max-width:860px}
    .evapp-attendance-kicker{display:block;color:#3858e9;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}
    .evapp-attendance-page-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
    .evapp-attendance-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:0 0 18px;max-width:980px}
    .evapp-attendance-step{display:block;text-decoration:none;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:11px 14px;color:#50575e;font-weight:600}
    .evapp-attendance-step:hover{color:#2271b1;border-color:#72aee6}
    .evapp-attendance-step.is-active{background:#2271b1;border-color:#2271b1;color:#fff}
    .evapp-attendance-step.is-complete{background:#edfaef;border-color:#b8dfc5;color:#16753b}
    .evapp-attendance-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
    .evapp-attendance-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px;margin-top:18px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
    .evapp-attendance-card h2,.evapp-attendance-card h3,.evapp-attendance-card h4{margin-top:0}
    .evapp-attendance-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:18px}
    .evapp-attendance-card-head h2,.evapp-attendance-card-head h3{margin:0 0 4px}
    .evapp-attendance-card-head p{margin:0;color:#646970}
    .evapp-attendance-field{margin-bottom:14px}
    .evapp-attendance-field:last-child{margin-bottom:0}
    .evapp-attendance-field label{display:block;font-weight:600;margin-bottom:6px;color:#1d2327}
    .evapp-attendance-field input[type=text],.evapp-attendance-field input[type=date],.evapp-attendance-field input[type=time],.evapp-attendance-field input[type=search],.evapp-attendance-field select,.evapp-attendance-field textarea{width:100%;max-width:none}
    .evapp-attendance-status-multiselect{display:flex;flex-wrap:wrap;gap:8px;padding:10px;border:1px solid #8c8f94;border-radius:4px;background:#fff;min-height:42px;box-sizing:border-box}
    .evapp-attendance-status-option{display:inline-flex!important;align-items:center;gap:6px;margin:0!important;padding:7px 10px;border:1px solid #dcdcde;border-radius:999px;background:#f6f7f7;color:#1d2327!important;font-weight:500!important;line-height:1.2;cursor:pointer;user-select:none}
    .evapp-attendance-status-option:hover{border-color:#72aee6;background:#f0f6fc}
    .evapp-attendance-status-option.is-selected{border-color:#2271b1;background:#e7f3ff;color:#0a4b78!important}
    .evapp-attendance-status-option input{margin:0!important}
    .evapp-attendance-status-multiselect-help{width:100%;margin:2px 0 0;color:#646970;font-size:12px;line-height:1.4}
    .evapp-attendance-inline{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
    .evapp-attendance-help{color:#646970;font-size:12px;line-height:1.45;margin:5px 0 0}
    .evapp-attendance-channel-switches{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}
    .evapp-attendance-channel-switch{display:flex;align-items:center;gap:8px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:999px;padding:8px 13px;font-weight:600}
    .evapp-attendance-channel-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
    .evapp-attendance-channel-card{border:1px solid #dcdcde;border-radius:12px;padding:18px;background:#fff;transition:.15s ease}
    .evapp-attendance-channel-card.is-disabled{opacity:.58;background:#f6f7f7}
    .evapp-attendance-channel-card h3{display:flex;align-items:center;gap:8px;margin:0 0 14px}
    .evapp-attendance-template-summary{background:#f0f6fc;border-left:4px solid #72aee6;padding:10px 12px;margin-top:12px;line-height:1.45}
    .evapp-attendance-template-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
    .evapp-attendance-table-wrap{overflow:auto;border:1px solid #e2e4e7;border-radius:10px}
    .evapp-attendance-table{width:100%;min-width:860px;border-collapse:collapse;background:#fff}
    .evapp-attendance-table th{background:#f6f7f7;color:#50575e;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
    .evapp-attendance-table th,.evapp-attendance-table td{padding:11px 12px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
    .evapp-attendance-table tr:last-child td{border-bottom:0}
    .evapp-attendance-badge{display:inline-block;padding:4px 9px;border-radius:999px;background:#eef3ff;color:#2745a6;font-size:11px;font-weight:700}
    .evapp-attendance-summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
    .evapp-attendance-summary-item{background:#f6f7f7;border:1px solid #e2e4e7;border-radius:10px;padding:14px}
    .evapp-attendance-summary-item strong{display:block;font-size:19px;line-height:1.2;margin-bottom:4px;color:#1d2327}
    .evapp-attendance-summary-item span{font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:.04em;font-weight:600}
    .evapp-attendance-progress{height:18px;background:#e5e7eb;border-radius:999px;overflow:hidden}
    .evapp-attendance-progress>span{display:block;height:100%;width:0;background:#2271b1;transition:width .2s}
    .evapp-attendance-log{max-height:280px;overflow:auto;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:10px}
    .evapp-attendance-log div{padding:6px 0;border-bottom:1px solid #ddd}
    .evapp-attendance-log div:last-child{border-bottom:0}
    .evapp-attendance-empty{background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:10px;padding:18px;text-align:center;color:#646970}
    @media(max-width:1000px){.evapp-attendance-grid,.evapp-attendance-channel-grid{grid-template-columns:1fr}.evapp-attendance-summary-grid{grid-template-columns:1fr 1fr}}
    @media(max-width:782px){.evapp-attendance-page-head{display:block}.evapp-attendance-page-actions{justify-content:flex-start;margin-top:14px}.evapp-attendance-steps{grid-template-columns:1fr}.evapp-attendance-summary-grid{grid-template-columns:1fr}}
    </style>
    <script>
    jQuery(function($){
        $(document)
            .off('change.evappAttendanceStatusAll', '.evapp-attendance-status-all')
            .on('change.evappAttendanceStatusAll', '.evapp-attendance-status-all', function(){
                const $group = $(this).closest('.evapp-attendance-status-multiselect');
                if (this.checked) {
                    $group.find('.evapp-attendance-status-value').prop('checked', false);
                } else if (!$group.find('.evapp-attendance-status-value:checked').length) {
                    this.checked = true;
                }
                $group.find('.evapp-attendance-status-option').each(function(){
                    $(this).toggleClass('is-selected', $(this).find('input').prop('checked'));
                });
            });

        $(document)
            .off('change.evappAttendanceStatusValue', '.evapp-attendance-status-value')
            .on('change.evappAttendanceStatusValue', '.evapp-attendance-status-value', function(){
                const $group = $(this).closest('.evapp-attendance-status-multiselect');
                const hasSelectedStatus = $group.find('.evapp-attendance-status-value:checked').length > 0;
                $group.find('.evapp-attendance-status-all').prop('checked', !hasSelectedStatus);
                $group.find('.evapp-attendance-status-option').each(function(){
                    $(this).toggleClass('is-selected', $(this).find('input').prop('checked'));
                });
            });
    });
    </script>
    <?php
}

function eventosapp_attendance_confirmation_render_filter_fields($prefix = 'filters', $event_id = 0, $values = []) {
    $values = is_array($values) ? $values : [];
    $localidades = eventosapp_attendance_confirmation_admin_localidades($event_id);
    $status_options = eventosapp_attendance_confirmation_status_options();
    $selected_statuses = function_exists('eventosapp_attendance_confirmation_sanitize_statuses')
        ? eventosapp_attendance_confirmation_sanitize_statuses($values['confirmation_status'] ?? [])
        : array_values(array_filter((array)($values['confirmation_status'] ?? [])));
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
            <div class="evapp-attendance-status-multiselect" role="group" aria-label="Estados de confirmación">
                <label class="evapp-attendance-status-option <?php echo empty($selected_statuses) ? 'is-selected' : ''; ?>">
                    <input type="checkbox" class="evapp-attendance-status-all" <?php checked(empty($selected_statuses)); ?>>
                    Todos
                </label>
                <?php foreach ($status_options as $key=>$label): ?>
                    <?php $is_selected = in_array($key, $selected_statuses, true); ?>
                    <label class="evapp-attendance-status-option <?php echo $is_selected ? 'is-selected' : ''; ?>">
                        <input
                            type="checkbox"
                            class="evapp-attendance-status-value"
                            name="<?php echo esc_attr($prefix); ?>[confirmation_status][]"
                            value="<?php echo esc_attr($key); ?>"
                            <?php checked($is_selected); ?>
                        >
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
                <p class="evapp-attendance-status-multiselect-help">Puedes seleccionar uno o varios estados. “Todos” no aplica filtro por estado.</p>
            </div>
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
    $selected_template_id = eventosapp_attendance_confirmation_admin_resolve_template_id($values['whatsapp_template_id'] ?? '', $wa_templates);
    $approved_templates = [];
    $other_templates = [];
    foreach ( $wa_templates as $template_id => $template ) {
        if ( eventosapp_attendance_confirmation_admin_template_is_approved($template) ) $approved_templates[$template_id] = $template;
        else $other_templates[$template_id] = $template;
    }
    $selected_template = $wa_templates[$selected_template_id] ?? null;
    ?>
    <div class="evapp-attendance-card evapp-attendance-message-card">
        <div class="evapp-attendance-card-head">
            <div><h2>Canales y plantillas</h2><p>Activa los canales que usarás y configura el contenido asociado a cada uno.</p></div>
            <a class="button" href="<?php echo esc_url(eventosapp_attendance_confirmation_admin_templates_url()); ?>">Inventario de plantillas</a>
        </div>

        <div class="evapp-attendance-channel-switches">
            <label class="evapp-attendance-channel-switch"><input class="evapp-attendance-channel-toggle" type="checkbox" data-channel="email" name="<?php echo esc_attr($name_prefix); ?>[channels][]" value="email" <?php checked(in_array('email',$channels,true)); ?>> Correo electrónico</label>
            <label class="evapp-attendance-channel-switch"><input class="evapp-attendance-channel-toggle" type="checkbox" data-channel="whatsapp" name="<?php echo esc_attr($name_prefix); ?>[channels][]" value="whatsapp" <?php checked(in_array('whatsapp',$channels,true)); ?>> WhatsApp</label>
        </div>

        <div class="evapp-attendance-channel-grid">
            <section class="evapp-attendance-channel-card" data-channel-panel="email">
                <h3>✉️ Correo electrónico</h3>
                <div class="evapp-attendance-field">
                    <label>Plantilla de correo</label>
                    <select name="<?php echo esc_attr($name_prefix); ?>[email_template]">
                        <?php foreach($email_templates as $file=>$label): ?><option value="<?php echo esc_attr($file); ?>" <?php selected($values['email_template']??'attendance-confirmation.html',$file); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                    </select>
                    <p class="evapp-attendance-help">Usa automáticamente el cabezote configurado en “Email del Ticket — Plantilla y Header”.</p>
                </div>
                <div class="evapp-attendance-field">
                    <label>Asunto</label>
                    <input type="text" name="<?php echo esc_attr($name_prefix); ?>[email_subject]" value="<?php echo esc_attr($values['email_subject']??'Confirma tu asistencia a {{evento_nombre}}'); ?>">
                    <p class="evapp-attendance-help">Variables: {{nombre_completo}}, {{evento_nombre}}, {{evento_fecha}}, {{evento_hora}}, {{evento_lugar}}.</p>
                </div>
                <div class="evapp-attendance-field">
                    <label>Mensaje adicional</label>
                    <textarea name="<?php echo esc_attr($name_prefix); ?>[email_message]" rows="5"><?php echo esc_textarea($values['email_message']??''); ?></textarea>
                </div>
            </section>

            <section class="evapp-attendance-channel-card" data-channel-panel="whatsapp">
                <h3>💬 WhatsApp</h3>
                <div class="evapp-attendance-field">
                    <label>Plantilla con botones Sí / No</label>
                    <select name="<?php echo esc_attr($name_prefix); ?>[whatsapp_template_id]" class="evapp-attendance-template-select">
                        <?php if(!$wa_templates): ?><option value="attendance_confirmation">No hay plantillas disponibles</option><?php endif; ?>
                        <?php if($approved_templates): ?><optgroup label="Aprobadas y listas para enviar"><?php foreach($approved_templates as $id=>$template): ?><option value="<?php echo esc_attr($id); ?>" <?php selected($selected_template_id,$id); ?>><?php echo esc_html(eventosapp_attendance_confirmation_admin_template_label($template)); ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                        <?php if($other_templates): ?><optgroup label="En preparación o revisión"><?php foreach($other_templates as $id=>$template): ?><option value="<?php echo esc_attr($id); ?>" <?php selected($selected_template_id,$id); ?>><?php echo esc_html(eventosapp_attendance_confirmation_admin_template_label($template)); ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                    </select>
                    <p class="evapp-attendance-help">Los envíos inmediatos solo aceptan plantillas APPROVED o ACTIVE. Las pendientes pueden dejarse seleccionadas en una programación futura.</p>
                </div>
                <div class="evapp-attendance-template-summary">
                    <strong><?php echo esc_html(count($wa_templates)); ?> plantilla<?php echo count($wa_templates)===1?'':'s'; ?> en inventario</strong><br>
                    <?php echo esc_html(count($approved_templates)); ?> aprobada<?php echo count($approved_templates)===1?'':'s'; ?> disponible<?php echo count($approved_templates)===1?'':'s'; ?> para envío.
                    <?php if(is_array($selected_template)): ?><br>Selección actual: <strong><?php echo esc_html($selected_template['title']??$selected_template['name']??$selected_template_id); ?></strong>.<?php endif; ?>
                </div>
                <div class="evapp-attendance-template-actions">
                    <a class="button button-primary" href="<?php echo esc_url(eventosapp_attendance_confirmation_admin_templates_url()); ?>">Gestionar inventario</a>
                    <a class="button" href="<?php echo esc_url(eventosapp_attendance_confirmation_admin_templates_url(['view'=>'new'])); ?>">Crear nueva plantilla</a>
                    <?php if($selected_template_id && isset($wa_templates[$selected_template_id])): ?><a class="button" href="<?php echo esc_url(eventosapp_attendance_confirmation_admin_templates_url(['view'=>'edit','template_id'=>$selected_template_id])); ?>">Editar seleccionada</a><?php endif; ?>
                </div>
                <?php if($context==='bulk'): ?><p class="evapp-attendance-help">La imagen real se toma del campo “Imagen para confirmación de asistencia” configurado dentro del metabox de WhatsApp del evento.</p><?php endif; ?>
            </section>
        </div>
    </div>
    <script>
    jQuery(function($){
        function refreshAttendanceChannels(){
            $('.evapp-attendance-channel-toggle').each(function(){
                const channel=$(this).data('channel');
                $('[data-channel-panel="'+channel+'"]').toggleClass('is-disabled',!this.checked);
            });
        }
        $(document).on('change','.evapp-attendance-channel-toggle',refreshAttendanceChannels);
        refreshAttendanceChannels();
    });
    </script>
    <?php
}

function eventosapp_attendance_confirmation_render_bulk_form() {
    eventosapp_attendance_confirmation_render_bulk_styles();
    $events = eventosapp_attendance_confirmation_admin_events();
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="evapp-attendance-segment-form">
        <input type="hidden" name="action" value="eventosapp_attendance_confirmation_create_segment">
        <?php wp_nonce_field('eventosapp_attendance_confirmation_create_segment','evapp_attendance_nonce'); ?>
        <div class="evapp-attendance-card">
            <div class="evapp-attendance-card-head"><div><h2>Evento y segmentación</h2><p>Selecciona el evento y define exactamente qué asistentes recibirán la consulta.</p></div></div>
            <div class="evapp-attendance-field"><label>Evento</label><select name="filters[evento_id]" id="evapp-attendance-event" required><option value="">Selecciona un evento</option><?php foreach($events as $event): ?><option value="<?php echo esc_attr($event->ID); ?>"><?php echo esc_html($event->post_title); ?></option><?php endforeach; ?></select></div>
            <div id="evapp-attendance-dynamic-filters"><div class="evapp-attendance-empty">Selecciona el evento para cargar localidades y campos adicionales.</div></div>
        </div>
        <?php eventosapp_attendance_confirmation_render_message_configuration([], 'bulk'); ?>
        <p><?php submit_button('Crear segmentación y previsualizar','primary','submit',false); ?></p>
    </form>
    <script>
    jQuery(function($){
        $('#evapp-attendance-event').on('change',function(){
            const id=$(this).val(), target=$('#evapp-attendance-dynamic-filters');
            if(!id){target.html('<div class="evapp-attendance-empty">Selecciona el evento para cargar localidades y campos adicionales.</div>');return;}
            target.html('<div class="evapp-attendance-empty">Cargando filtros…</div>');
            $.post(ajaxurl,{action:'eventosapp_attendance_confirmation_filter_fields',event_id:id,nonce:'<?php echo esc_js(wp_create_nonce('eventosapp_attendance_confirmation_filter_fields')); ?>'},function(r){
                target.html(r.success?r.data.html:'<p class="notice notice-error">'+(r.data&&r.data.message?r.data.message:'No se pudieron cargar los filtros.')+'</p>');
            });
        });

        $('#evapp-attendance-segment-form').on('submit', function(){
            const $button = $(this).find('button[type=submit], input[type=submit]').first();
            if ($button.prop('disabled')) return false;
            $button.prop('disabled', true);
            if ($button.is('input')) $button.val('Creando segmentación…');
            else $button.text('Creando segmentación…');
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
    if(in_array('whatsapp',$config['channels'],true)){
        $wa_template=eventosapp_attendance_confirmation_get_whatsapp_template($config['whatsapp_template_id']);
        if(!is_array($wa_template)||empty($wa_template['attendance_confirmation'])) wp_die('La plantilla WhatsApp seleccionada no existe en el inventario de confirmación.');
        if(!eventosapp_attendance_confirmation_admin_template_is_approved($wa_template)) wp_die('La plantilla WhatsApp seleccionada todavía no está aprobada o activa en Meta. Consulta su estado o selecciona otra plantilla antes de iniciar el envío.');
    }
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
    $channels=eventosapp_attendance_confirmation_sanitize_channels($segment['config']['channels']??[]);
    $template_label='No aplica';
    if(in_array('whatsapp',$channels,true)){
        $template=eventosapp_attendance_confirmation_get_whatsapp_template($segment['config']['whatsapp_template_id']??'');
        $template_label=is_array($template)?eventosapp_attendance_confirmation_admin_template_label($template):'Plantilla no disponible';
    }
    ?>
    <div class="evapp-attendance-card">
        <div class="evapp-attendance-card-head"><div><h2>Resumen de la segmentación</h2><p>Verifica el alcance y la configuración antes de iniciar.</p></div></div>
        <div class="evapp-attendance-summary-grid">
            <div class="evapp-attendance-summary-item"><strong><?php echo esc_html(count($ids)); ?></strong><span>Tickets encontrados</span></div>
            <div class="evapp-attendance-summary-item"><strong><?php echo esc_html(implode(' + ',array_map('eventosapp_attendance_confirmation_channel_label',$channels))); ?></strong><span>Canales</span></div>
            <div class="evapp-attendance-summary-item"><strong style="font-size:14px"><?php echo esc_html($template_label); ?></strong><span>Plantilla WhatsApp</span></div>
        </div>
    </div>
    <div class="evapp-attendance-card">
        <div class="evapp-attendance-card-head"><div><h2>Vista previa de destinatarios</h2><p>Se muestran como máximo los primeros 100 registros.</p></div></div>
        <?php if(!$ids): ?><div class="evapp-attendance-empty">No hay tickets para enviar con los filtros seleccionados.</div><?php else: ?><div class="evapp-attendance-table-wrap"><table class="evapp-attendance-table"><thead><tr><th>Ticket</th><th>Asistente</th><th>Contacto</th><th>Localidad / modalidad</th><th>Confirmación</th></tr></thead><tbody>
        <?php foreach(array_slice($ids,0,100) as $id): $values=eventosapp_attendance_confirmation_template_values($id); ?>
        <tr><td>#<?php echo esc_html($id); ?><br><small><?php echo esc_html(get_post_meta($id,'eventosapp_ticketID',true)); ?></small></td><td><?php echo esc_html($values['nombre_completo']); ?></td><td><?php echo esc_html(get_post_meta($id,'_eventosapp_asistente_email',true)); ?><br><?php echo esc_html(get_post_meta($id,'_eventosapp_asistente_tel',true)); ?></td><td><?php echo esc_html($values['localidad']); ?><br><small><?php echo esc_html($values['modalidad']); ?></small></td><td><span class="evapp-attendance-badge"><?php echo esc_html(eventosapp_attendance_confirmation_status_label(eventosapp_attendance_confirmation_get_status($id))); ?></span></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_attendance_confirmation_bulk')); ?>">Volver a configuración</a><?php if($ids): ?> <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page'=>'eventosapp_attendance_confirmation_bulk','step'=>3,'segment_id'=>$segment_id],admin_url('admin.php'))); ?>">Continuar al envío</a><?php endif; ?></p>
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
    $wa_templates=eventosapp_attendance_confirmation_get_whatsapp_templates(false);
    $selected_template_id=eventosapp_attendance_confirmation_admin_resolve_template_id('', $wa_templates);
    $approved_templates=array_filter($wa_templates,'eventosapp_attendance_confirmation_admin_template_is_approved');
    $has_approved_templates=!empty($approved_templates);
    $template_select_id='evapp-attendance-whatsapp-template-'.absint($post->ID);
    $template_button_id='evapp-attendance-whatsapp-send-'.absint($post->ID);
    ?>
    <style>
    .evapp-att-ticket-row{display:flex;justify-content:space-between;gap:8px;padding:7px 0;border-bottom:1px solid #eee}
    .evapp-att-ticket-history{max-height:220px;overflow:auto;margin-top:10px;background:#f6f7f7;padding:8px;border-radius:6px}
    .evapp-att-ticket-history div{padding:6px 0;border-bottom:1px solid #ddd;font-size:11px}
    .evapp-att-ticket-actions{display:grid;gap:8px;margin-top:12px}
    .evapp-att-ticket-whatsapp-panel{margin:0;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:7px}
    .evapp-att-ticket-actions select{width:100%;margin:7px 0}
    </style>
    <div class="evapp-att-ticket-row"><strong>Estado</strong><span class="evapp-attendance-badge"><?php echo esc_html(eventosapp_attendance_confirmation_status_label($status)); ?></span></div>
    <div class="evapp-att-ticket-row"><strong>Enviado por</strong><span><?php echo $sent?esc_html(implode(', ',array_map('eventosapp_attendance_confirmation_channel_label',$sent))):'—'; ?></span></div>
    <div class="evapp-att-ticket-row"><strong>Respondió por</strong><span><?php echo $responses?esc_html(implode(', ',array_map('eventosapp_attendance_confirmation_channel_label',$responses))):'—'; ?></span></div>
    <div class="evapp-att-ticket-row"><strong>Último envío</strong><span><?php echo esc_html(get_post_meta($post->ID,$keys['last_sent_at'],true)?:'—'); ?></span></div>
    <div class="evapp-att-ticket-row"><strong>Última respuesta</strong><span><?php echo esc_html(get_post_meta($post->ID,$keys['last_response_at'],true)?:'—'); ?></span></div>
    <?php if($conflict): ?><div class="notice notice-warning inline"><p>Hay respuestas diferentes registradas en el historial. El estado visible corresponde a la respuesta más reciente.</p></div><?php endif; ?>

    <div class="evapp-att-ticket-actions">
        <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action'=>'eventosapp_attendance_confirmation_send_ticket','ticket_id'=>$post->ID,'channels'=>'email'],admin_url('admin-post.php')),'eventosapp_attendance_confirmation_send_ticket')); ?>">Enviar correo de confirmación</a>

        <?php
        /**
         * No se debe imprimir un <form> dentro de un metabox del editor clásico.
         * WordPress ya envuelve toda la pantalla del ticket en el formulario #post;
         * un formulario anidado invalida el DOM, puede cerrar el formulario principal
         * y evita que el botón Actualizar envíe los campos y nonces del ticket.
         *
         * Cada opción aprobada guarda una URL admin-post firmada y el botón navega
         * a esa URL sin alterar el formulario principal del ticket.
         */
        ?>
        <div class="evapp-att-ticket-whatsapp-panel">
            <strong>Enviar por WhatsApp</strong>
            <select id="<?php echo esc_attr($template_select_id); ?>" <?php disabled(!$has_approved_templates); ?>>
                <?php if(!$wa_templates): ?><option value="">No hay plantillas disponibles</option><?php endif; ?>
                <?php foreach($wa_templates as $template_id=>$template):
                    $approved=eventosapp_attendance_confirmation_admin_template_is_approved($template);
                    $whatsapp_send_url=$approved
                        ? wp_nonce_url(
                            add_query_arg([
                                'action'=>'eventosapp_attendance_confirmation_send_ticket',
                                'ticket_id'=>$post->ID,
                                'channels'=>'whatsapp',
                                'whatsapp_template_id'=>$template_id,
                            ],admin_url('admin-post.php')),
                            'eventosapp_attendance_confirmation_send_ticket'
                        )
                        : '';
                ?>
                    <option value="<?php echo esc_url($whatsapp_send_url); ?>" <?php selected($selected_template_id,$template_id); ?> <?php disabled(!$approved); ?>><?php echo esc_html(eventosapp_attendance_confirmation_admin_template_label($template)); ?><?php echo $approved?'':' · No disponible'; ?></option>
                <?php endforeach; ?>
            </select>
            <button id="<?php echo esc_attr($template_button_id); ?>" class="button button-primary" type="button" <?php disabled(!$has_approved_templates); ?>>Enviar plantilla seleccionada</button>
            <p class="description" style="margin-bottom:0"><a href="<?php echo esc_url(eventosapp_attendance_confirmation_admin_templates_url()); ?>">Gestionar inventario</a></p>
        </div>
    </div>

    <script>
    (function(){
        const select=document.getElementById(<?php echo wp_json_encode($template_select_id); ?>);
        const button=document.getElementById(<?php echo wp_json_encode($template_button_id); ?>);
        if(!select||!button)return;

        const syncButtonState=function(){
            button.disabled=!select.value;
        };

        select.addEventListener('change',syncButtonState);
        button.addEventListener('click',function(event){
            event.preventDefault();
            if(!select.value)return;
            button.disabled=true;
            window.location.assign(select.value);
        });

        syncButtonState();
    })();
    </script>

    <?php if($history): ?><div class="evapp-att-ticket-history"><?php foreach(array_slice($history,0,25) as $entry): ?><div><strong><?php echo esc_html($entry['at']??''); ?></strong><br><?php echo esc_html($entry['message']??''); ?></div><?php endforeach; ?></div><?php endif; ?>
    <?php
}

add_action('admin_post_eventosapp_attendance_confirmation_send_ticket', function(){
    $ticket_id=absint($_REQUEST['ticket_id']??0);
    if(!$ticket_id||!current_user_can('edit_post',$ticket_id))wp_die('Permisos insuficientes.');
    check_admin_referer('eventosapp_attendance_confirmation_send_ticket');
    $channels=eventosapp_attendance_confirmation_sanitize_channels($_REQUEST['channels']??'email');
    $whatsapp_template_id=sanitize_key((string)($_REQUEST['whatsapp_template_id']??eventosapp_attendance_confirmation_whatsapp_template_id()));
    $result=eventosapp_attendance_confirmation_send_ticket($ticket_id,[
        'channels'=>$channels,
        'whatsapp_template_id'=>$whatsapp_template_id,
        'source'=>'ticket_metabox',
        'force'=>true,
        'source_key'=>'ticket_metabox:'.$ticket_id.':'.time(),
    ]);
    wp_safe_redirect(add_query_arg(['post'=>$ticket_id,'action'=>'edit','evapp_attendance_ok'=>!empty($result['ok'])?1:0,'evapp_attendance_msg'=>rawurlencode($result['message']??'Proceso ejecutado.')],admin_url('post.php')));exit;
});

add_action('admin_notices', function(){
    $screen=function_exists('get_current_screen')?get_current_screen():null;
    if(!$screen||$screen->post_type!=='eventosapp_ticket'||empty($_GET['evapp_attendance_msg']))return;
    $ok=!empty($_GET['evapp_attendance_ok']);$message=sanitize_text_field(wp_unslash($_GET['evapp_attendance_msg']));
    echo '<div class="notice '.($ok?'notice-success':'notice-error').' is-dismissible"><p><strong>Confirmación de asistencia:</strong> '.esc_html($message).'</p></div>';
});
