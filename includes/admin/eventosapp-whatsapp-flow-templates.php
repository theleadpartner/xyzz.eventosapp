<?php
/**
 * EventosApp - Plantillas WhatsApp para abrir Flows
 *
 * Módulo separado de eventosapp-whatsapp-templates.php. No modifica ni reutiliza
 * la administración existente de plantillas de tickets; solo crea y administra
 * plantillas cuyo botón abre un WhatsApp Flow.
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! defined('EVENTOSAPP_WHATSAPP_FLOW_TEMPLATES_OPTION') ) {
    define('EVENTOSAPP_WHATSAPP_FLOW_TEMPLATES_OPTION', 'eventosapp_whatsapp_flow_templates');
}

add_action('admin_menu', function() {
    add_submenu_page(
        'eventosapp_dashboard',
        'Plantillas Flow WhatsApp',
        'Plantillas Flow WhatsApp',
        'manage_options',
        'eventosapp_whatsapp_flow_templates',
        'eventosapp_whatsapp_flow_templates_render_page',
        25
    );
}, 23);

function eventosapp_whatsapp_flow_templates_default_item() {
    return [
        'id'                     => '',
        'name'                   => '',
        'display_name'           => '',
        'language'               => 'es_CO',
        'category'               => 'UTILITY',
        'body'                   => 'Hola {{1}}, queremos conocer tu opinión sobre {{2}}. Por favor responde esta breve encuesta.',
        'sample_1'               => 'Meme',
        'sample_2'               => 'el evento',
        'button_text'            => 'Responder encuesta',
        'flow_post_id'           => 0,
        'meta_flow_id'           => '',
        'navigate_screen'        => 'SURVEY',
        'waba_id'                => '',
        'sender_phone_number_id' => '',
        'meta_status'            => 'local_draft',
        'meta_template_id'       => '',
        'last_meta_response'     => [],
        'created_at'             => '',
        'updated_at'             => '',
    ];
}

function eventosapp_whatsapp_flow_templates_get_all() {
    $items = get_option(EVENTOSAPP_WHATSAPP_FLOW_TEMPLATES_OPTION, []);
    return is_array($items) ? $items : [];
}

function eventosapp_whatsapp_flow_templates_save_all($items) {
    update_option(EVENTOSAPP_WHATSAPP_FLOW_TEMPLATES_OPTION, is_array($items) ? $items : [], false);
}

function eventosapp_whatsapp_flow_templates_get($template_id) {
    $template_id = sanitize_key((string) $template_id);
    $items = eventosapp_whatsapp_flow_templates_get_all();
    return isset($items[$template_id]) && is_array($items[$template_id]) ? wp_parse_args($items[$template_id], eventosapp_whatsapp_flow_templates_default_item()) : [];
}

function eventosapp_whatsapp_flow_templates_notice_redirect($args = []) {
    $args = wp_parse_args($args, ['page' => 'eventosapp_whatsapp_flow_templates']);
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}

function eventosapp_whatsapp_flow_templates_template_name($raw) {
    $name = strtolower(remove_accents((string) $raw));
    $name = preg_replace('/[^a-z0-9_]+/', '_', $name);
    $name = trim($name, '_');
    if ( $name === '' ) {
        $name = 'eventosapp_flow_' . time();
    }
    return substr($name, 0, 512);
}

function eventosapp_whatsapp_flow_templates_build_meta_payload($template) {
    $template = wp_parse_args(is_array($template) ? $template : [], eventosapp_whatsapp_flow_templates_default_item());
    $flow_id = preg_replace('/\D+/', '', (string)($template['meta_flow_id'] ?? ''));
    if ( $flow_id === '' && ! empty($template['flow_post_id']) && function_exists('eventosapp_whatsapp_flows_get_flow_config') ) {
        $flow_config = eventosapp_whatsapp_flows_get_flow_config(absint($template['flow_post_id']));
        $flow_id = preg_replace('/\D+/', '', (string)($flow_config['meta_flow_id'] ?? ''));
    }

    $button = [
        'type'   => 'FLOW',
        'text'   => sanitize_text_field((string)($template['button_text'] ?? 'Responder')),
        'flow_id'=> $flow_id,
    ];

    $screen = sanitize_key((string)($template['navigate_screen'] ?? ''));
    if ( $screen !== '' ) {
        $button['navigate_screen'] = strtoupper($screen);
        $button['flow_action'] = 'navigate';
    }

    $example_body = [];
    if ( strpos((string)($template['body'] ?? ''), '{{1}}') !== false ) {
        $example_body[] = sanitize_text_field((string)($template['sample_1'] ?? 'Asistente'));
    }
    if ( strpos((string)($template['body'] ?? ''), '{{2}}') !== false ) {
        $example_body[] = sanitize_text_field((string)($template['sample_2'] ?? 'Evento'));
    }

    $body_component = [
        'type' => 'BODY',
        'text' => (string)($template['body'] ?? ''),
    ];
    if ( ! empty($example_body) ) {
        $body_component['example'] = [
            'body_text' => [$example_body],
        ];
    }

    return [
        'name'       => eventosapp_whatsapp_flow_templates_template_name($template['name'] ?? ''),
        'language'   => sanitize_text_field((string)($template['language'] ?? 'es_CO')),
        'category'   => sanitize_key((string)($template['category'] ?? 'UTILITY')),
        'components' => [
            $body_component,
            [
                'type'    => 'BUTTONS',
                'buttons' => [$button],
            ],
        ],
    ];
}

function eventosapp_whatsapp_flow_templates_build_send_payload($template, $flow_token = '', $context = []) {
    $template = wp_parse_args(is_array($template) ? $template : [], eventosapp_whatsapp_flow_templates_default_item());
    $context = is_array($context) ? $context : [];
    $parameters = [];

    if ( strpos((string)$template['body'], '{{1}}') !== false ) {
        $parameters[] = [
            'type' => 'text',
            'text' => (string)($context['name'] ?? $template['sample_1'] ?? 'Asistente'),
        ];
    }
    if ( strpos((string)$template['body'], '{{2}}') !== false ) {
        $parameters[] = [
            'type' => 'text',
            'text' => (string)($context['event_name'] ?? $template['sample_2'] ?? 'Evento'),
        ];
    }

    $components = [];
    if ( ! empty($parameters) ) {
        $components[] = [
            'type'       => 'body',
            'parameters' => $parameters,
        ];
    }

    $flow_action_data = [
        'eventosapp_flow_post_id' => (string) absint($template['flow_post_id'] ?? 0),
        'eventosapp_event_id'     => (string) absint($context['event_id'] ?? 0),
        'eventosapp_ticket_id'    => (string) absint($context['ticket_id'] ?? 0),
        'eventosapp_ticket_code'  => (string)($context['ticket_code'] ?? ''),
    ];

    $components[] = [
        'type'       => 'button',
        'sub_type'   => 'flow',
        'index'      => '0',
        'parameters' => [
            [
                'type'   => 'action',
                'action' => [
                    'flow_token'      => sanitize_text_field((string) $flow_token),
                    'flow_action_data'=> $flow_action_data,
                ],
            ],
        ],
    ];

    return [
        'type' => 'template',
        'template' => [
            'name' => eventosapp_whatsapp_flow_templates_template_name($template['name'] ?? ''),
            'language' => [
                'code' => sanitize_text_field((string)($template['language'] ?? 'es_CO')),
            ],
            'components' => $components,
        ],
    ];
}

add_action('admin_post_eventosapp_whatsapp_flow_template_save', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    check_admin_referer('eventosapp_whatsapp_flow_template_save');

    $items = eventosapp_whatsapp_flow_templates_get_all();
    $template_id = sanitize_key((string)($_POST['template_id'] ?? ''));
    if ( $template_id === '' ) {
        $template_id = 'flow_tpl_' . wp_generate_password(12, false, false);
        $template_id = sanitize_key($template_id);
    }

    $existing = isset($items[$template_id]) && is_array($items[$template_id]) ? $items[$template_id] : [];
    $flow_post_id = absint($_POST['flow_post_id'] ?? 0);
    $meta_flow_id = preg_replace('/\D+/', '', (string)($_POST['meta_flow_id'] ?? ''));
    $screen = sanitize_key((string)($_POST['navigate_screen'] ?? 'SURVEY'));

    if ( $flow_post_id && function_exists('eventosapp_whatsapp_flows_get_flow_config') ) {
        $flow_config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);
        if ( $meta_flow_id === '' ) {
            $meta_flow_id = preg_replace('/\D+/', '', (string)($flow_config['meta_flow_id'] ?? ''));
        }
        if ( $screen === '' && ! empty($flow_config['screen_id']) ) {
            $screen = sanitize_key($flow_config['screen_id']);
        }
    }

    $item = wp_parse_args([
        'id'                     => $template_id,
        'name'                   => eventosapp_whatsapp_flow_templates_template_name($_POST['template_name'] ?? ''),
        'display_name'           => sanitize_text_field((string)($_POST['display_name'] ?? '')),
        'language'               => sanitize_text_field((string)($_POST['language'] ?? 'es_CO')),
        'category'               => sanitize_key((string)($_POST['category'] ?? 'UTILITY')),
        'body'                   => sanitize_textarea_field((string)($_POST['body'] ?? '')),
        'sample_1'               => sanitize_text_field((string)($_POST['sample_1'] ?? '')),
        'sample_2'               => sanitize_text_field((string)($_POST['sample_2'] ?? '')),
        'button_text'            => sanitize_text_field((string)($_POST['button_text'] ?? 'Responder encuesta')),
        'flow_post_id'           => $flow_post_id,
        'meta_flow_id'           => $meta_flow_id,
        'navigate_screen'        => strtoupper($screen !== '' ? $screen : 'SURVEY'),
        'waba_id'                => function_exists('eventosapp_whatsapp_sanitize_waba_id') ? eventosapp_whatsapp_sanitize_waba_id($_POST['waba_id'] ?? '') : preg_replace('/\D+/', '', (string)($_POST['waba_id'] ?? '')),
        'sender_phone_number_id' => function_exists('eventosapp_whatsapp_sanitize_phone_number_id') ? eventosapp_whatsapp_sanitize_phone_number_id($_POST['sender_phone_number_id'] ?? '') : preg_replace('/\D+/', '', (string)($_POST['sender_phone_number_id'] ?? '')),
        'updated_at'             => current_time('mysql'),
    ], eventosapp_whatsapp_flow_templates_default_item());

    if ( empty($existing['created_at']) ) {
        $item['created_at'] = current_time('mysql');
    } else {
        $item['created_at'] = $existing['created_at'];
    }
    foreach ( ['meta_status', 'meta_template_id', 'last_meta_response'] as $keep_key ) {
        if ( isset($existing[$keep_key]) && ! isset($item[$keep_key]) ) {
            $item[$keep_key] = $existing[$keep_key];
        }
    }

    $items[$template_id] = $item;
    eventosapp_whatsapp_flow_templates_save_all($items);

    eventosapp_whatsapp_flow_templates_notice_redirect([
        'template_id' => $template_id,
        'flow_tpl_notice' => 'success',
        'flow_tpl_message' => rawurlencode('Plantilla Flow guardada localmente.'),
    ]);
});

add_action('admin_post_eventosapp_whatsapp_flow_template_submit_meta', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    check_admin_referer('eventosapp_whatsapp_flow_template_submit_meta');

    $template_id = sanitize_key((string)($_POST['template_id'] ?? ''));
    $template = eventosapp_whatsapp_flow_templates_get($template_id);
    if ( empty($template) ) {
        eventosapp_whatsapp_flow_templates_notice_redirect(['flow_tpl_notice' => 'error', 'flow_tpl_message' => rawurlencode('Plantilla inválida.')]);
    }

    $waba_id = function_exists('eventosapp_whatsapp_sanitize_waba_id') ? eventosapp_whatsapp_sanitize_waba_id($template['waba_id'] ?? '') : preg_replace('/\D+/', '', (string)($template['waba_id'] ?? ''));
    if ( $waba_id === '' && function_exists('eventosapp_whatsapp_get_settings') ) {
        $settings = eventosapp_whatsapp_get_settings();
        $waba_id = function_exists('eventosapp_whatsapp_get_effective_webhook_waba_id') ? eventosapp_whatsapp_get_effective_webhook_waba_id($settings) : ($settings['webhook_waba_id'] ?? '');
        $waba_id = preg_replace('/\D+/', '', (string) $waba_id);
    }
    if ( $waba_id === '' ) {
        eventosapp_whatsapp_flow_templates_notice_redirect(['template_id' => $template_id, 'flow_tpl_notice' => 'error', 'flow_tpl_message' => rawurlencode('Falta WABA ID para enviar la plantilla a Meta.')]);
    }

    $payload = eventosapp_whatsapp_flow_templates_build_meta_payload($template);
    if ( empty($payload['components'][1]['buttons'][0]['flow_id']) ) {
        eventosapp_whatsapp_flow_templates_notice_redirect(['template_id' => $template_id, 'flow_tpl_notice' => 'error', 'flow_tpl_message' => rawurlencode('La plantilla necesita un Flow ID de Meta publicado o al menos creado.')]);
    }

    $settings = function_exists('eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id') ? eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id($template['sender_phone_number_id'] ?? '') : (function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : []);
    $result = function_exists('eventosapp_whatsapp_graph_api_request')
        ? eventosapp_whatsapp_graph_api_request('POST', $waba_id . '/message_templates', $payload, $settings)
        : ['ok' => false, 'message' => 'No está disponible el cliente Graph API de WhatsApp Tickets.'];

    $items = eventosapp_whatsapp_flow_templates_get_all();
    if ( isset($items[$template_id]) ) {
        $items[$template_id]['last_meta_response'] = $result;
        $items[$template_id]['updated_at'] = current_time('mysql');
        if ( ! empty($result['ok']) ) {
            $items[$template_id]['meta_status'] = 'submitted';
            if ( is_array($result['response'] ?? null) && ! empty($result['response']['id']) ) {
                $items[$template_id]['meta_template_id'] = sanitize_text_field((string)$result['response']['id']);
            }
        } else {
            $items[$template_id]['meta_status'] = 'meta_error';
        }
        eventosapp_whatsapp_flow_templates_save_all($items);
    }

    if ( function_exists('eventosapp_whatsapp_add_activity_log') ) {
        eventosapp_whatsapp_add_activity_log(! empty($result['ok']) ? 'flow_template_enviada_meta' : 'flow_template_error_meta', [
            'template_id' => $template_id,
            'template_name' => $template['name'] ?? '',
            'payload' => $payload,
            'result' => $result,
        ]);
    }

    eventosapp_whatsapp_flow_templates_notice_redirect([
        'template_id' => $template_id,
        'flow_tpl_notice' => ! empty($result['ok']) ? 'success' : 'error',
        'flow_tpl_message' => rawurlencode($result['message'] ?? 'Solicitud enviada a Meta.'),
    ]);
});

add_action('admin_post_eventosapp_whatsapp_flow_template_sync_status', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    check_admin_referer('eventosapp_whatsapp_flow_template_sync_status');

    $template_id = sanitize_key((string)($_POST['template_id'] ?? ''));
    $template = eventosapp_whatsapp_flow_templates_get($template_id);
    if ( empty($template) ) {
        eventosapp_whatsapp_flow_templates_notice_redirect(['flow_tpl_notice' => 'error', 'flow_tpl_message' => rawurlencode('Plantilla inválida.')]);
    }

    $waba_id = function_exists('eventosapp_whatsapp_sanitize_waba_id') ? eventosapp_whatsapp_sanitize_waba_id($template['waba_id'] ?? '') : preg_replace('/\D+/', '', (string)($template['waba_id'] ?? ''));
    if ( $waba_id === '' && function_exists('eventosapp_whatsapp_get_settings') ) {
        $settings = eventosapp_whatsapp_get_settings();
        $waba_id = function_exists('eventosapp_whatsapp_get_effective_webhook_waba_id') ? eventosapp_whatsapp_get_effective_webhook_waba_id($settings) : ($settings['webhook_waba_id'] ?? '');
        $waba_id = preg_replace('/\D+/', '', (string) $waba_id);
    }

    $settings = function_exists('eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id') ? eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id($template['sender_phone_number_id'] ?? '') : (function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : []);
    $name = eventosapp_whatsapp_flow_templates_template_name($template['name'] ?? '');
    $path = $waba_id . '/message_templates?name=' . rawurlencode($name) . '&fields=id,name,status,category,language,components';
    $result = function_exists('eventosapp_whatsapp_graph_api_request')
        ? eventosapp_whatsapp_graph_api_request('GET', $path, null, $settings)
        : ['ok' => false, 'message' => 'No está disponible el cliente Graph API de WhatsApp Tickets.'];

    $items = eventosapp_whatsapp_flow_templates_get_all();
    if ( isset($items[$template_id]) ) {
        $items[$template_id]['last_meta_response'] = $result;
        $items[$template_id]['updated_at'] = current_time('mysql');
        if ( ! empty($result['ok']) && is_array($result['response']['data'][0] ?? null) ) {
            $items[$template_id]['meta_status'] = sanitize_key(strtolower((string)($result['response']['data'][0]['status'] ?? 'synced')));
            $items[$template_id]['meta_template_id'] = sanitize_text_field((string)($result['response']['data'][0]['id'] ?? ''));
        }
        eventosapp_whatsapp_flow_templates_save_all($items);
    }

    eventosapp_whatsapp_flow_templates_notice_redirect([
        'template_id' => $template_id,
        'flow_tpl_notice' => ! empty($result['ok']) ? 'success' : 'error',
        'flow_tpl_message' => rawurlencode(! empty($result['ok']) ? 'Estado consultado en Meta.' : ($result['message'] ?? 'No se pudo consultar el estado.')),
    ]);
});

add_action('admin_post_eventosapp_whatsapp_flow_template_test_send', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    check_admin_referer('eventosapp_whatsapp_flow_template_test_send');

    $template_id = sanitize_key((string)($_POST['template_id'] ?? ''));
    $phone = function_exists('eventosapp_whatsapp_flows_clean_phone') ? eventosapp_whatsapp_flows_clean_phone($_POST['test_phone'] ?? '') : preg_replace('/\D+/', '', (string)($_POST['test_phone'] ?? ''));
    $ticket_id = absint($_POST['test_ticket_id'] ?? 0);
    $template = eventosapp_whatsapp_flow_templates_get($template_id);
    if ( empty($template) || $phone === '' ) {
        eventosapp_whatsapp_flow_templates_notice_redirect(['template_id' => $template_id, 'flow_tpl_notice' => 'error', 'flow_tpl_message' => rawurlencode('Plantilla o teléfono inválido.')]);
    }

    $flow_post_id = absint($template['flow_post_id'] ?? 0);
    $context = $ticket_id && function_exists('eventosapp_whatsapp_flows_get_ticket_context') ? eventosapp_whatsapp_flows_get_ticket_context($ticket_id) : [];
    $event_id = absint($context['event_id'] ?? 0);
    $flow_token = function_exists('eventosapp_whatsapp_flows_make_flow_token') ? eventosapp_whatsapp_flows_make_flow_token($flow_post_id, $ticket_id, $event_id) : sanitize_key('flow_' . wp_generate_password(20, false, false));
    $payload = eventosapp_whatsapp_flow_templates_build_send_payload($template, $flow_token, $context);
    $settings = function_exists('eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id') ? eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id($template['sender_phone_number_id'] ?? '') : (function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : []);

    if ( function_exists('eventosapp_whatsapp_flows_insert_send_row') ) {
        $send_id = eventosapp_whatsapp_flows_insert_send_row([
            'flow_post_id' => $flow_post_id,
            'meta_flow_id' => preg_replace('/\D+/', '', (string)($template['meta_flow_id'] ?? '')),
            'event_id' => $event_id,
            'ticket_id' => $ticket_id,
            'phone' => $phone,
            'sender_phone_number_id' => $settings['phone_number_id'] ?? '',
            'flow_token' => $flow_token,
            'send_mode' => 'template_flow_test',
            'status' => 'queued',
            'request_json' => wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } else {
        $send_id = 0;
    }

    $result = function_exists('eventosapp_whatsapp_api_send_message')
        ? eventosapp_whatsapp_api_send_message($phone, $payload, $settings)
        : ['ok' => false, 'message' => 'No está disponible el envío base de WhatsApp.'];

    $message_id = ! empty($result['message_id']) ? sanitize_text_field((string) $result['message_id']) : '';
    if ( $send_id && function_exists('eventosapp_whatsapp_flows_update_send_row') ) {
        eventosapp_whatsapp_flows_update_send_row($send_id, [
            'wa_message_id' => $message_id,
            'status' => ! empty($result['ok']) ? 'sent_request' : 'failed_request',
            'response_json' => wp_json_encode($result['response'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => empty($result['ok']) ? ($result['message'] ?? '') : '',
        ]);
    }
    if ( $message_id !== '' && function_exists('eventosapp_whatsapp_register_message_map') ) {
        eventosapp_whatsapp_register_message_map($message_id, $ticket_id, 'whatsapp_flow_template', $phone);
    }

    eventosapp_whatsapp_flow_templates_notice_redirect([
        'template_id' => $template_id,
        'flow_tpl_notice' => ! empty($result['ok']) ? 'success' : 'error',
        'flow_tpl_message' => rawurlencode($result['message'] ?? 'Prueba ejecutada.'),
    ]);
});

function eventosapp_whatsapp_flow_templates_render_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }

    $items = eventosapp_whatsapp_flow_templates_get_all();
    $template_id = sanitize_key((string)($_GET['template_id'] ?? ''));
    $selected = $template_id ? eventosapp_whatsapp_flow_templates_get($template_id) : [];
    if ( empty($selected) && ! empty($items) ) {
        $first = reset($items);
        $selected = wp_parse_args($first, eventosapp_whatsapp_flow_templates_default_item());
        $template_id = $selected['id'];
    }

    $settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];
    $default_waba_id = function_exists('eventosapp_whatsapp_get_effective_webhook_waba_id') ? eventosapp_whatsapp_get_effective_webhook_waba_id($settings) : ($settings['webhook_waba_id'] ?? '');
    $phone_accounts = function_exists('eventosapp_whatsapp_get_phone_accounts') ? eventosapp_whatsapp_get_phone_accounts($settings) : [];
    $flows = function_exists('eventosapp_whatsapp_flows_get_all_for_select') ? eventosapp_whatsapp_flows_get_all_for_select() : [];
    $edit = ! empty($selected) ? wp_parse_args($selected, eventosapp_whatsapp_flow_templates_default_item()) : wp_parse_args([
        'waba_id' => $default_waba_id,
        'sender_phone_number_id' => $settings['phone_number_id'] ?? '',
    ], eventosapp_whatsapp_flow_templates_default_item());

    ?>
    <div class="wrap eventosapp-wa-flow-templates">
        <h1>Plantillas Flow WhatsApp</h1>
        <?php eventosapp_whatsapp_flow_templates_render_notices(); ?>
        <style>
            .eventosapp-wa-flow-templates .evapp-grid{display:grid;grid-template-columns:minmax(360px,1fr) minmax(320px,.8fr);gap:18px;align-items:start}.eventosapp-wa-flow-templates .evapp-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}.eventosapp-wa-flow-templates .evapp-card h2{margin-top:0}.eventosapp-wa-flow-templates .evapp-muted{color:#646970}.eventosapp-wa-flow-templates .evapp-pill{display:inline-block;border-radius:999px;background:#eef6ff;color:#0a5ea8;padding:4px 9px;font-size:12px;font-weight:600}.eventosapp-wa-flow-templates .evapp-actions{display:flex;gap:8px;flex-wrap:wrap}.eventosapp-wa-flow-templates textarea.code{width:100%;min-height:260px;font-family:Menlo,Consolas,monospace}@media(max-width:1100px){.eventosapp-wa-flow-templates .evapp-grid{grid-template-columns:1fr}}
        </style>

        <div class="evapp-grid">
            <div>
                <div class="evapp-card">
                    <h2><?php echo ! empty($edit['id']) ? 'Editar plantilla Flow' : 'Crear plantilla Flow'; ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('eventosapp_whatsapp_flow_template_save'); ?>
                        <input type="hidden" name="action" value="eventosapp_whatsapp_flow_template_save">
                        <input type="hidden" name="template_id" value="<?php echo esc_attr($edit['id']); ?>">

                        <table class="form-table" role="presentation">
                            <tr><th><label>Nombre interno</label></th><td><input type="text" class="regular-text" name="display_name" value="<?php echo esc_attr($edit['display_name']); ?>" placeholder="Encuesta post evento"></td></tr>
                            <tr><th><label>Nombre Meta</label></th><td><input type="text" class="regular-text" name="template_name" value="<?php echo esc_attr($edit['name']); ?>" placeholder="eventosapp_flow_encuesta_post_evento" required><p class="description">Usa minúsculas, números y guion bajo. EventosApp lo normaliza automáticamente.</p></td></tr>
                            <tr><th>Idioma / categoría</th><td><input type="text" name="language" value="<?php echo esc_attr($edit['language']); ?>" class="small-text"> <select name="category"><option value="UTILITY" <?php selected($edit['category'], 'UTILITY'); ?>>Utility</option><option value="MARKETING" <?php selected($edit['category'], 'MARKETING'); ?>>Marketing</option></select></td></tr>
                            <tr><th><label>Mensaje</label></th><td><textarea class="large-text" rows="5" name="body"><?php echo esc_textarea($edit['body']); ?></textarea><p class="description">Puedes usar {{1}} para nombre y {{2}} para nombre del evento. Meta exige ejemplos si hay variables.</p></td></tr>
                            <tr><th>Ejemplos</th><td><input type="text" name="sample_1" value="<?php echo esc_attr($edit['sample_1']); ?>" placeholder="Ejemplo {{1}}"> <input type="text" name="sample_2" value="<?php echo esc_attr($edit['sample_2']); ?>" placeholder="Ejemplo {{2}}"></td></tr>
                            <tr><th><label>Botón Flow</label></th><td><input type="text" name="button_text" value="<?php echo esc_attr($edit['button_text']); ?>" maxlength="30" class="regular-text"></td></tr>
                            <tr><th>Flow asociado</th><td>
                                <select name="flow_post_id">
                                    <option value="0">Seleccionar Flow local</option>
                                    <?php foreach ( $flows as $flow ) : ?>
                                        <option value="<?php echo esc_attr($flow['id']); ?>" <?php selected(absint($edit['flow_post_id']), absint($flow['id'])); ?>><?php echo esc_html($flow['title'] . ($flow['meta_flow_id'] ? ' — Meta ID ' . $flow['meta_flow_id'] : ' — sin Meta ID')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p><label>Flow ID Meta manual<br><input type="text" class="regular-text" name="meta_flow_id" value="<?php echo esc_attr($edit['meta_flow_id']); ?>"></label></p>
                                <p><label>Pantalla inicial<br><input type="text" class="regular-text" name="navigate_screen" value="<?php echo esc_attr($edit['navigate_screen']); ?>"></label></p>
                            </td></tr>
                            <tr><th>Cuenta Meta</th><td>
                                <p><label>WABA ID<br><input type="text" class="regular-text" name="waba_id" value="<?php echo esc_attr($edit['waba_id'] ?: $default_waba_id); ?>"></label></p>
                                <p><label>Número emisor<br><select name="sender_phone_number_id"><option value="">Usar número por defecto</option><?php foreach ( $phone_accounts as $account ) : ?><option value="<?php echo esc_attr($account['phone_number_id']); ?>" <?php selected($edit['sender_phone_number_id'], $account['phone_number_id']); ?>><?php echo esc_html($account['label']); ?></option><?php endforeach; ?></select></label></p>
                            </td></tr>
                        </table>
                        <p class="submit"><button type="submit" class="button button-primary">Guardar plantilla Flow</button></p>
                    </form>
                </div>

                <div class="evapp-card">
                    <h2>Payload para Meta</h2>
                    <textarea class="code" readonly><?php echo esc_textarea(wp_json_encode(eventosapp_whatsapp_flow_templates_build_meta_payload($edit), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></textarea>
                </div>
            </div>
            <div>
                <div class="evapp-card">
                    <h2>Plantillas Flow guardadas</h2>
                    <?php if ( empty($items) ) : ?>
                        <p>No hay plantillas Flow todavía.</p>
                    <?php else : ?>
                        <table class="widefat striped"><thead><tr><th>Plantilla</th><th>Estado</th><th>Acción</th></tr></thead><tbody>
                        <?php foreach ( $items as $item ) : $item = wp_parse_args($item, eventosapp_whatsapp_flow_templates_default_item()); ?>
                            <tr><td><strong><?php echo esc_html($item['display_name'] ?: $item['name']); ?></strong><br><small><?php echo esc_html($item['name']); ?></small></td><td><span class="evapp-pill"><?php echo esc_html($item['meta_status']); ?></span></td><td><a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'eventosapp_whatsapp_flow_templates', 'template_id' => $item['id']], admin_url('admin.php'))); ?>">Abrir</a></td></tr>
                        <?php endforeach; ?>
                        </tbody></table>
                    <?php endif; ?>
                    <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flow_templates&template_id=0')); ?>">Crear nueva</a></p>
                </div>

                <?php if ( ! empty($edit['id']) ) : ?>
                    <div class="evapp-card">
                        <h2>Acciones Meta</h2>
                        <p><strong>Estado Meta:</strong> <span class="evapp-pill"><?php echo esc_html($edit['meta_status']); ?></span></p>
                        <div class="evapp-actions">
                            <?php eventosapp_whatsapp_flow_templates_post_button('eventosapp_whatsapp_flow_template_submit_meta', 'eventosapp_whatsapp_flow_template_submit_meta', 'Enviar a aprobación', $edit['id'], 'button-primary'); ?>
                            <?php eventosapp_whatsapp_flow_templates_post_button('eventosapp_whatsapp_flow_template_sync_status', 'eventosapp_whatsapp_flow_template_sync_status', 'Consultar estado', $edit['id']); ?>
                        </div>
                        <details style="margin-top:12px;"><summary>Última respuesta técnica</summary><?php eventosapp_whatsapp_flow_templates_render_debug($edit['last_meta_response']); ?></details>
                    </div>

                    <div class="evapp-card">
                        <h2>Enviar prueba con plantilla</h2>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('eventosapp_whatsapp_flow_template_test_send'); ?>
                            <input type="hidden" name="action" value="eventosapp_whatsapp_flow_template_test_send">
                            <input type="hidden" name="template_id" value="<?php echo esc_attr($edit['id']); ?>">
                            <p><label>Teléfono destino<br><input type="text" class="regular-text" name="test_phone" placeholder="573001112233"></label></p>
                            <p><label>Ticket ID opcional<br><input type="number" class="small-text" name="test_ticket_id" min="0"></label></p>
                            <p><button type="submit" class="button button-primary">Enviar prueba</button></p>
                            <p class="description">Este método usa una plantilla aprobada con botón Flow. Es el camino recomendado para iniciar encuestas fuera de la ventana de conversación.</p>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

function eventosapp_whatsapp_flow_templates_render_notices() {
    if ( empty($_GET['flow_tpl_notice']) ) {
        return;
    }
    $type = sanitize_key((string) $_GET['flow_tpl_notice']);
    $message = isset($_GET['flow_tpl_message']) ? sanitize_text_field(wp_unslash($_GET['flow_tpl_message'])) : '';
    if ( $message === '' ) {
        return;
    }
    $class = $type === 'success' ? 'notice notice-success' : ($type === 'warning' ? 'notice notice-warning' : 'notice notice-error');
    echo '<div class="' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
}

function eventosapp_whatsapp_flow_templates_render_debug($value) {
    if ( function_exists('eventosapp_whatsapp_render_log_details') ) {
        eventosapp_whatsapp_render_log_details($value);
        return;
    }
    echo '<pre style="white-space:pre-wrap;word-break:break-word;background:#f6f7f7;border:1px solid #dcdcde;padding:8px;margin-top:8px;max-height:260px;overflow:auto;">' . esc_html(wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
}

function eventosapp_whatsapp_flow_templates_post_button($action, $nonce_action, $label, $template_id, $class = '') {
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:0;">
        <?php wp_nonce_field($nonce_action); ?>
        <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
        <input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>">
        <button type="submit" class="button <?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button>
    </form>
    <?php
}
