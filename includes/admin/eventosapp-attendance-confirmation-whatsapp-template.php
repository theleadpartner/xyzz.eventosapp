<?php
/**
 * EventosApp - Plantilla WhatsApp para confirmación de asistencia.
 *
 * Mantiene una plantilla prediseñada con botones QUICK_REPLY “Sí” y “No”,
 * compatible con la sección actual de Plantillas WhatsApp y con Meta Cloud API.
 *
 * Ruta: includes/admin/eventosapp-attendance-confirmation-whatsapp-template.php
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) exit;

function eventosapp_attendance_confirmation_whatsapp_template_defaults() {
    $now = current_time('mysql');
    return [
        'id'                     => 'attendance_confirmation',
        'attendance_confirmation'=> '1',
        'is_default'             => '1',
        'base_key'               => 'attendance_confirmation',
        'name'                   => 'eventosapp_confirmacion_asistencia_v1',
        'language'               => 'es',
        'category'               => 'UTILITY',
        'modality'               => 'custom',
        'title'                  => 'Confirmación de asistencia — Sí / No',
        'header_format'          => 'IMAGE',
        'header_text'            => '',
        'header_sample_handle'   => '',
        'header_sample_file_name'=> '',
        'header_sample_file_type'=> '',
        'header_sample_file_size'=> 0,
        'header_sample_uploaded_at'=>'',
        'body_text'              => "Hola {{1}}, queremos confirmar tu asistencia a *{{2}}*.\n\n📅 *Fecha:* {{3}}\n🕒 *Hora:* {{4}}\n📍 *Lugar:* {{5}}\n\nPor favor responde usando uno de los botones.",
        'body_examples'          => "María Pérez\nEvento Demo\n20 de mayo de 2026\n8:00 a. m.\nCentro de Convenciones",
        'body_text_meta'         => '',
        'body_variable_map'      => [1,2,3,4,5],
        'body_variable_signature'=> '',
        'footer_text'            => 'EventosApp',
        'button_mode'            => 'quick_reply',
        'button_count'           => '2',
        'button_1_text'          => 'Sí, asistiré',
        'button_1_url'           => '',
        'button_1_example'       => '',
        'button_2_text'          => 'No podré asistir',
        'button_2_url'           => '',
        'button_2_example'       => '',
        'sender_phone_number_id' => '',
        'sender_phone_label'     => 'Número por defecto',
        'waba_id'                => '',
        'meta_template_id'       => '',
        'meta_status'            => 'LOCAL',
        'meta_category'          => '',
        'meta_rejected_reason'   => '',
        'last_api_message'       => '',
        'last_api_response'      => [],
        'last_submitted_at'      => '',
        'last_checked_at'        => '',
        'created_at'             => $now,
        'updated_at'             => $now,
    ];
}

function eventosapp_attendance_confirmation_whatsapp_template_prepare_body($template) {
    $template = is_array($template) ? $template : [];
    if ( function_exists('eventosapp_whatsapp_templates_prepare_body_for_meta') ) {
        $prepared = eventosapp_whatsapp_templates_prepare_body_for_meta($template['body_text'] ?? '', $template['body_examples'] ?? '');
        $template['body_text_meta'] = sanitize_textarea_field((string)($prepared['text'] ?? $template['body_text'] ?? ''));
        $template['body_variable_map'] = is_array($prepared['variable_numbers'] ?? null) ? array_values(array_unique(array_map('absint', $prepared['variable_numbers']))) : [1,2,3,4,5];
        $template['body_variable_signature'] = sanitize_text_field((string)($prepared['signature'] ?? md5((string)($template['body_text'] ?? ''))));
    } else {
        $template['body_text_meta'] = sanitize_textarea_field((string)($template['body_text'] ?? ''));
        preg_match_all('/\{\{(\d+)\}\}/', (string)($template['body_text'] ?? ''), $matches);
        $template['body_variable_map'] = ! empty($matches[1]) ? array_values(array_unique(array_map('absint', $matches[1]))) : [];
        $template['body_variable_signature'] = md5((string)($template['body_text'] ?? ''));
    }
    return $template;
}

function eventosapp_attendance_confirmation_whatsapp_template_ensure() {
    if ( ! function_exists('eventosapp_whatsapp_templates_get_settings') || ! function_exists('eventosapp_whatsapp_templates_update_settings') ) return;
    $settings = eventosapp_whatsapp_templates_get_settings();
    $defaults = eventosapp_attendance_confirmation_whatsapp_template_defaults();
    $id = $defaults['id'];
    $stored_record = isset($settings['templates'][$id]) && is_array($settings['templates'][$id])
        ? $settings['templates'][$id]
        : [];
    $current = $stored_record;
    $status = strtoupper((string)($current['meta_status'] ?? 'LOCAL'));
    if ( empty($current) ) {
        $current = $defaults;
    } else {
        $current = wp_parse_args($current, $defaults);
        $current['attendance_confirmation'] = '1';
        $current['button_mode'] = 'quick_reply';
        $current['button_count'] = '2';
        if ( in_array($status, ['', 'LOCAL'], true) ) {
            foreach (['button_1_text','button_2_text'] as $key) {
                if ( trim((string)($current[$key] ?? '')) === '' ) $current[$key] = $defaults[$key];
            }
        }
    }
    $current = eventosapp_attendance_confirmation_whatsapp_template_prepare_body($current);

    // Evita escribir la opción global en cada carga del administrador cuando
    // la plantilla ya está exactamente sincronizada con sus valores locales.
    if ( maybe_serialize($stored_record) !== maybe_serialize($current) ) {
        $settings['templates'][$id] = $current;
        eventosapp_whatsapp_templates_update_settings($settings);
    }
}
add_action('admin_init', 'eventosapp_attendance_confirmation_whatsapp_template_ensure', 8);

function eventosapp_attendance_confirmation_whatsapp_template_get() {
    eventosapp_attendance_confirmation_whatsapp_template_ensure();
    if ( ! function_exists('eventosapp_whatsapp_templates_get_settings') ) return eventosapp_attendance_confirmation_whatsapp_template_defaults();
    $settings = eventosapp_whatsapp_templates_get_settings();
    return isset($settings['templates']['attendance_confirmation']) && is_array($settings['templates']['attendance_confirmation'])
        ? $settings['templates']['attendance_confirmation']
        : eventosapp_attendance_confirmation_whatsapp_template_defaults();
}

function eventosapp_attendance_confirmation_whatsapp_template_update($template) {
    if ( ! function_exists('eventosapp_whatsapp_templates_get_settings') || ! function_exists('eventosapp_whatsapp_templates_update_settings') ) return false;
    $settings = eventosapp_whatsapp_templates_get_settings();
    $template = eventosapp_attendance_confirmation_whatsapp_template_prepare_body($template);
    $settings['templates']['attendance_confirmation'] = $template;
    eventosapp_whatsapp_templates_update_settings($settings);
    return true;
}

add_action('admin_menu', function() {
    add_submenu_page(
        'eventosapp_dashboard',
        'Plantilla Confirmación WhatsApp',
        'Plantilla Confirmación',
        'manage_options',
        'eventosapp_attendance_confirmation_template',
        'eventosapp_attendance_confirmation_whatsapp_template_render_page',
        22
    );
}, 22);

function eventosapp_attendance_confirmation_whatsapp_template_sender_accounts() {
    return function_exists('eventosapp_whatsapp_get_phone_accounts') ? eventosapp_whatsapp_get_phone_accounts() : [];
}

function eventosapp_attendance_confirmation_whatsapp_template_build_meta_components($template) {
    $template = eventosapp_attendance_confirmation_whatsapp_template_prepare_body($template);
    $components = [];
    $header = strtoupper(sanitize_key((string)($template['header_format'] ?? 'NONE')));
    if ( $header === 'IMAGE' ) {
        $component = ['type'=>'HEADER','format'=>'IMAGE'];
        if ( ! empty($template['header_sample_handle']) ) {
            $component['example'] = ['header_handle'=>[sanitize_text_field((string)$template['header_sample_handle'])]];
        }
        $components[] = $component;
    } elseif ( $header === 'TEXT' && ! empty($template['header_text']) ) {
        $components[] = ['type'=>'HEADER','format'=>'TEXT','text'=>sanitize_text_field((string)$template['header_text'])];
    }

    $body = ['type'=>'BODY','text'=>sanitize_textarea_field((string)($template['body_text_meta'] ?: $template['body_text']))];
    $examples = preg_split('/\R+/', trim((string)($template['body_examples'] ?? '')));
    $examples = array_values(array_filter(array_map('sanitize_text_field', (array)$examples), 'strlen'));
    if ( ! empty($template['body_variable_map']) ) {
        $expected = count((array)$template['body_variable_map']);
        while ( count($examples) < $expected ) $examples[] = 'Ejemplo ' . (count($examples)+1);
        $body['example'] = ['body_text'=>[array_slice($examples,0,$expected)]];
    }
    $components[] = $body;
    if ( ! empty($template['footer_text']) ) $components[] = ['type'=>'FOOTER','text'=>sanitize_text_field((string)$template['footer_text'])];
    $components[] = [
        'type'=>'BUTTONS',
        'buttons'=>[
            ['type'=>'QUICK_REPLY','text'=>sanitize_text_field((string)($template['button_1_text'] ?? 'Sí, asistiré'))],
            ['type'=>'QUICK_REPLY','text'=>sanitize_text_field((string)($template['button_2_text'] ?? 'No podré asistir'))],
        ],
    ];
    return $components;
}

function eventosapp_attendance_confirmation_whatsapp_template_validate($template) {
    $errors = [];
    if ( trim((string)($template['name'] ?? '')) === '' ) $errors[] = 'Falta el nombre técnico de la plantilla.';
    if ( ! preg_match('/^[a-z0-9_]+$/', (string)($template['name'] ?? '')) ) $errors[] = 'El nombre técnico solo puede contener minúsculas, números y guion bajo.';
    if ( trim((string)($template['language'] ?? '')) === '' ) $errors[] = 'Falta el idioma.';
    if ( trim((string)($template['body_text'] ?? '')) === '' ) $errors[] = 'Falta el cuerpo del mensaje.';
    if ( trim((string)($template['button_1_text'] ?? '')) === '' || trim((string)($template['button_2_text'] ?? '')) === '' ) {
        $errors[] = 'Los dos botones deben tener texto.';
    }
    foreach ( [1, 2] as $button_number ) {
        $button_text = (string)($template['button_' . $button_number . '_text'] ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($button_text, 'UTF-8') : strlen($button_text);
        if ( $length > 25 ) $errors[] = 'El texto del botón ' . $button_number . ' no puede superar 25 caracteres.';
    }
    if ( strtoupper((string)($template['header_format'] ?? 'NONE')) === 'IMAGE' && trim((string)($template['header_sample_handle'] ?? '')) === '' ) $errors[] = 'Debes subir una imagen de muestra a Meta para obtener el Header Sample Handle.';
    if ( trim((string)($template['waba_id'] ?? '')) === '' ) $errors[] = 'Falta el WABA ID de la plantilla.';
    return $errors;
}

function eventosapp_attendance_confirmation_whatsapp_template_submit() {
    $template = eventosapp_attendance_confirmation_whatsapp_template_get();
    $errors = eventosapp_attendance_confirmation_whatsapp_template_validate($template);
    if ( $errors ) return ['ok'=>false,'message'=>implode(' ', $errors)];
    if ( ! function_exists('eventosapp_whatsapp_templates_api_request') ) return ['ok'=>false,'message'=>'No está disponible el cliente API del módulo Plantillas WhatsApp.'];
    $payload = [
        'name'=>sanitize_key((string)$template['name']),
        'language'=>sanitize_text_field((string)$template['language']),
        'category'=>function_exists('eventosapp_whatsapp_templates_sanitize_category') ? eventosapp_whatsapp_templates_sanitize_category($template['category'] ?? 'UTILITY') : 'UTILITY',
        'components'=>eventosapp_attendance_confirmation_whatsapp_template_build_meta_components($template),
    ];
    if ( ! empty($template['meta_template_id']) ) {
        $result = eventosapp_whatsapp_templates_api_request('POST', rawurlencode((string)$template['meta_template_id']), [
            'category'=>$payload['category'],'components'=>$payload['components'],
        ]);
    } else {
        $result = eventosapp_whatsapp_templates_api_request('POST', rawurlencode((string)$template['waba_id']) . '/message_templates', $payload);
    }
    $response = is_array($result['response'] ?? null) ? $result['response'] : [];
    $template['last_api_message'] = sanitize_text_field((string)($result['message'] ?? ''));
    $template['last_api_response'] = function_exists('eventosapp_whatsapp_sanitize_log_context') ? eventosapp_whatsapp_sanitize_log_context($result['response'] ?? []) : ($result['response'] ?? []);
    $template['last_submitted_at'] = current_time('mysql');
    if ( ! empty($result['ok']) ) {
        if ( ! empty($response['id']) ) $template['meta_template_id'] = sanitize_text_field((string)$response['id']);
        $template['meta_status'] = sanitize_text_field((string)($response['status'] ?? 'PENDING'));
        $template['meta_category'] = sanitize_text_field((string)($response['category'] ?? $payload['category']));
        $template['meta_rejected_reason'] = '';
    }
    $template['updated_at'] = current_time('mysql');
    eventosapp_attendance_confirmation_whatsapp_template_update($template);
    return $result;
}

function eventosapp_attendance_confirmation_whatsapp_template_save_from_post($existing) {
    $raw = isset($_POST['template']) && is_array($_POST['template']) ? wp_unslash($_POST['template']) : [];
    $template = wp_parse_args($existing, eventosapp_attendance_confirmation_whatsapp_template_defaults());
    $template['attendance_confirmation'] = '1';
    $template['button_mode'] = 'quick_reply';
    $template['button_count'] = '2';
    $template['name'] = sanitize_key((string)($raw['name'] ?? $template['name']));
    $template['language'] = preg_replace('/[^a-zA-Z_\-]/','', (string)($raw['language'] ?? $template['language']));
    $category_raw = strtoupper(sanitize_key((string)($raw['category'] ?? 'UTILITY')));
    $template['category'] = in_array($category_raw, ['UTILITY','MARKETING'], true) ? $category_raw : 'UTILITY';
    $template['title'] = sanitize_text_field((string)($raw['title'] ?? $template['title']));
    $header_format_raw = strtoupper(sanitize_key((string)($raw['header_format'] ?? 'IMAGE')));
    $template['header_format'] = in_array($header_format_raw, ['NONE','TEXT','IMAGE'], true) ? $header_format_raw : 'IMAGE';
    $template['header_text'] = sanitize_text_field((string)($raw['header_text'] ?? ''));
    $template['header_sample_handle'] = sanitize_text_field((string)($raw['header_sample_handle'] ?? $template['header_sample_handle']));
    $template['body_text'] = sanitize_textarea_field((string)($raw['body_text'] ?? $template['body_text']));
    $template['body_examples'] = sanitize_textarea_field((string)($raw['body_examples'] ?? $template['body_examples']));
    $template['footer_text'] = sanitize_text_field((string)($raw['footer_text'] ?? $template['footer_text']));
    $template['button_1_text'] = sanitize_text_field((string)($raw['button_1_text'] ?? 'Sí, asistiré'));
    $template['button_2_text'] = sanitize_text_field((string)($raw['button_2_text'] ?? 'No podré asistir'));
    $template['sender_phone_number_id'] = preg_replace('/\D+/', '', (string)($raw['sender_phone_number_id'] ?? ''));
    $template['waba_id'] = preg_replace('/\D+/', '', (string)($raw['waba_id'] ?? ''));
    $accounts = eventosapp_attendance_confirmation_whatsapp_template_sender_accounts();
    if ( isset($accounts[$template['sender_phone_number_id']]) ) $template['sender_phone_label'] = sanitize_text_field((string)($accounts[$template['sender_phone_number_id']]['alias'] ?? $accounts[$template['sender_phone_number_id']]['label'] ?? 'Número WhatsApp'));
    $template['updated_at'] = current_time('mysql');
    return eventosapp_attendance_confirmation_whatsapp_template_prepare_body($template);
}

add_action('admin_post_eventosapp_attendance_confirmation_template_action', function() {
    if ( ! current_user_can('manage_options') ) wp_die('Permisos insuficientes.');
    check_admin_referer('eventosapp_attendance_confirmation_template_action','evapp_attendance_template_nonce');
    $action = sanitize_key((string)($_POST['template_action'] ?? 'save'));
    $template = eventosapp_attendance_confirmation_whatsapp_template_save_from_post(eventosapp_attendance_confirmation_whatsapp_template_get());

    if ( ! empty($_FILES['header_sample_file']) && is_array($_FILES['header_sample_file']) && (int)($_FILES['header_sample_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ) {
        if ( function_exists('eventosapp_whatsapp_templates_upload_header_sample_to_meta') ) {
            $upload = eventosapp_whatsapp_templates_upload_header_sample_to_meta($_FILES['header_sample_file']);
            if ( ! empty($upload['ok']) ) {
                $template['header_sample_handle'] = sanitize_text_field((string)$upload['handle']);
                $template['header_sample_file_name'] = sanitize_file_name((string)($upload['file']['name'] ?? ''));
                $template['header_sample_file_type'] = sanitize_mime_type((string)($upload['file']['type'] ?? ''));
                $template['header_sample_file_size'] = absint($upload['file']['size'] ?? 0);
                $template['header_sample_uploaded_at'] = current_time('mysql');
                $message = $upload['message'] ?? 'Muestra subida a Meta.';
                $ok = true;
            } else {
                $message = $upload['message'] ?? 'No se pudo subir la muestra.';
                $ok = false;
            }
        } else {
            $message = 'No está disponible el cargador de muestras del módulo Plantillas WhatsApp.';
            $ok = false;
        }
    } else {
        $message = 'Plantilla guardada localmente.';
        $ok = true;
    }
    eventosapp_attendance_confirmation_whatsapp_template_update($template);

    if ( $action === 'submit' && $ok ) {
        $result = eventosapp_attendance_confirmation_whatsapp_template_submit();
        $ok = ! empty($result['ok']);
        $message = $result['message'] ?? ($ok ? 'Plantilla enviada a Meta.' : 'No se pudo enviar la plantilla a Meta.');
    } elseif ( $action === 'check' ) {
        if ( function_exists('eventosapp_whatsapp_templates_check_status') ) {
            $result = eventosapp_whatsapp_templates_check_status('attendance_confirmation');
            $ok = ! empty($result['ok']);
            $message = $result['message'] ?? 'Estado consultado.';
        } else {
            $ok = false; $message = 'No está disponible la consulta de estado del módulo Plantillas WhatsApp.';
        }
    }
    wp_safe_redirect(add_query_arg([
        'page'=>'eventosapp_attendance_confirmation_template','evapp_attendance_ok'=>$ok?1:0,'evapp_attendance_msg'=>rawurlencode($message),
    ], admin_url('admin.php')));exit;
});

function eventosapp_attendance_confirmation_whatsapp_template_render_page() {
    if ( ! current_user_can('manage_options') ) wp_die('Permisos insuficientes.');
    $template = eventosapp_attendance_confirmation_whatsapp_template_get();
    $accounts = eventosapp_attendance_confirmation_whatsapp_template_sender_accounts();
    $settings = function_exists('eventosapp_whatsapp_templates_get_settings') ? eventosapp_whatsapp_templates_get_settings() : [];
    if ( ! empty($_GET['evapp_attendance_msg']) ) {
        $ok=!empty($_GET['evapp_attendance_ok']);$message=sanitize_text_field(wp_unslash($_GET['evapp_attendance_msg']));
        echo '<div class="notice '.($ok?'notice-success':'notice-error').' is-dismissible"><p>'.esc_html($message).'</p></div>';
    }
    ?>
    <div class="wrap"><h1>Plantilla WhatsApp — Confirmación de Asistencia</h1><p><a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_templates')); ?>">← Volver a Plantillas WhatsApp</a></p>
    <style>.evapp-rsvp-template{max-width:1100px}.evapp-rsvp-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px;margin:18px 0}.evapp-rsvp-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.evapp-rsvp-field{margin-bottom:14px}.evapp-rsvp-field label{display:block;font-weight:600;margin-bottom:5px}.evapp-rsvp-field input,.evapp-rsvp-field select,.evapp-rsvp-field textarea{width:100%}.evapp-rsvp-help{font-size:12px;color:#646970}.evapp-rsvp-status{display:inline-block;padding:5px 10px;border-radius:999px;background:#eef3ff;color:#2745a6;font-weight:700}@media(max-width:800px){.evapp-rsvp-grid{grid-template-columns:1fr}}</style>
    <form class="evapp-rsvp-template" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="eventosapp_attendance_confirmation_template_action"><?php wp_nonce_field('eventosapp_attendance_confirmation_template_action','evapp_attendance_template_nonce'); ?>
    <div class="evapp-rsvp-card"><h2>Estado en Meta</h2><p><span class="evapp-rsvp-status"><?php echo esc_html(strtoupper((string)($template['meta_status']??'LOCAL'))); ?></span></p><p><strong>ID Meta:</strong> <?php echo esc_html($template['meta_template_id']??'—'); ?><br><strong>Último envío:</strong> <?php echo esc_html($template['last_submitted_at']??'—'); ?><br><strong>Última consulta:</strong> <?php echo esc_html($template['last_checked_at']??'—'); ?></p><?php if(!empty($template['last_api_message'])): ?><p><?php echo esc_html($template['last_api_message']); ?></p><?php endif; ?></div>
    <div class="evapp-rsvp-card"><h2>Identificación y conexión</h2><div class="evapp-rsvp-grid">
      <div class="evapp-rsvp-field"><label>Título interno</label><input type="text" name="template[title]" value="<?php echo esc_attr($template['title']); ?>"></div>
      <div class="evapp-rsvp-field"><label>Nombre técnico en Meta</label><input type="text" name="template[name]" value="<?php echo esc_attr($template['name']); ?>"><p class="evapp-rsvp-help">Solo minúsculas, números y guion bajo.</p></div>
      <div class="evapp-rsvp-field"><label>Idioma</label><input type="text" name="template[language]" value="<?php echo esc_attr($template['language']); ?>" placeholder="es"></div>
      <div class="evapp-rsvp-field"><label>Categoría</label><select name="template[category]"><option value="UTILITY" <?php selected($template['category'],'UTILITY'); ?>>Utility</option><option value="MARKETING" <?php selected($template['category'],'MARKETING'); ?>>Marketing</option></select></div>
      <div class="evapp-rsvp-field"><label>Número emisor</label><select name="template[sender_phone_number_id]"><option value="">Número por defecto</option><?php foreach($accounts as $id=>$account): ?><option value="<?php echo esc_attr($id); ?>" <?php selected($template['sender_phone_number_id']??'',$id); ?>><?php echo esc_html($account['label']??$id); ?></option><?php endforeach; ?></select></div>
      <div class="evapp-rsvp-field"><label>WABA ID</label><input type="text" name="template[waba_id]" value="<?php echo esc_attr($template['waba_id']?:($settings['waba_id']??'')); ?>"></div>
    </div></div>
    <div class="evapp-rsvp-card"><h2>Contenido</h2><div class="evapp-rsvp-field"><label>Formato de encabezado</label><select name="template[header_format]"><option value="IMAGE" <?php selected($template['header_format'],'IMAGE'); ?>>Imagen dinámica</option><option value="NONE" <?php selected($template['header_format'],'NONE'); ?>>Sin encabezado</option><option value="TEXT" <?php selected($template['header_format'],'TEXT'); ?>>Texto</option></select></div><div class="evapp-rsvp-field"><label>Texto del encabezado</label><input type="text" name="template[header_text]" value="<?php echo esc_attr($template['header_text']); ?>"></div>
    <div class="evapp-rsvp-field"><label>Imagen JPG/PNG de muestra para Meta</label><input type="file" name="header_sample_file" accept="image/jpeg,image/png"><p class="evapp-rsvp-help">La imagen real de cada evento se selecciona en el metabox de WhatsApp del evento. Este archivo solo genera el Header Sample Handle requerido para aprobación.</p></div>
    <div class="evapp-rsvp-field"><label>Header Sample Handle</label><input type="text" name="template[header_sample_handle]" value="<?php echo esc_attr($template['header_sample_handle']); ?>"></div>
    <div class="evapp-rsvp-field"><label>Cuerpo</label><textarea name="template[body_text]" rows="9"><?php echo esc_textarea($template['body_text']); ?></textarea><p class="evapp-rsvp-help">Variables disponibles: {{1}} nombre, {{2}} evento, {{3}} fecha, {{4}} hora, {{5}} lugar, {{6}} modalidad.</p></div>
    <div class="evapp-rsvp-field"><label>Ejemplos de variables</label><textarea name="template[body_examples]" rows="6"><?php echo esc_textarea($template['body_examples']); ?></textarea><p class="evapp-rsvp-help">Un ejemplo por línea y en el mismo orden de las variables usadas.</p></div>
    <div class="evapp-rsvp-field"><label>Pie</label><input type="text" name="template[footer_text]" value="<?php echo esc_attr($template['footer_text']); ?>"></div></div>
    <div class="evapp-rsvp-card"><h2>Botones de respuesta rápida</h2><div class="evapp-rsvp-grid"><div class="evapp-rsvp-field"><label>Botón afirmativo</label><input type="text" name="template[button_1_text]" value="<?php echo esc_attr($template['button_1_text']); ?>"></div><div class="evapp-rsvp-field"><label>Botón negativo</label><input type="text" name="template[button_2_text]" value="<?php echo esc_attr($template['button_2_text']); ?>"></div></div><p class="evapp-rsvp-help">El sistema añade payloads firmados al enviar para identificar el ticket y registrar la respuesta recibida por webhook.</p></div>
    <p><button class="button button-primary" name="template_action" value="save">Guardar localmente</button> <button class="button" name="template_action" value="submit">Guardar y enviar a Meta</button> <button class="button" name="template_action" value="check">Guardar y consultar estado</button></p>
    </form></div>
    <?php
}

/**
 * Enlace contextual desde la página existente de Plantillas WhatsApp y corrección
 * de enlaces de edición para que esta plantilla use su editor especializado.
 */
add_action('admin_notices', function() {
    if ( ! is_admin() || sanitize_key((string)($_GET['page'] ?? '')) !== 'eventosapp_whatsapp_templates' ) return;
    echo '<div class="notice notice-info"><p><strong>Confirmación de asistencia:</strong> la plantilla con botones rápidos Sí / No se administra en un editor especializado. <a class="button button-small" href="' . esc_url(admin_url('admin.php?page=eventosapp_attendance_confirmation_template')) . '">Abrir plantilla</a></p></div>';
});

add_action('admin_footer', function() {
    if ( sanitize_key((string)($_GET['page'] ?? '')) !== 'eventosapp_whatsapp_templates' ) return;
    $url = admin_url('admin.php?page=eventosapp_attendance_confirmation_template');
    ?><script>jQuery(function($){
        const specializedUrl='<?php echo esc_js($url); ?>';
        $('a[href*="attendance_confirmation"]').attr('href',specializedUrl);
        $('a[href*="template_id=attendance_confirmation"]').each(function(){
            const row=$(this).closest('tr');
            const actions=row.find('.evapp-wa-tpl-actions');
            if(actions.length){
                actions.html($('<a>',{class:'button button-primary',href:specializedUrl,text:'Abrir editor especializado'}));
            }
        });
    });</script><?php
});
