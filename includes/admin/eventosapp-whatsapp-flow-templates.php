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

add_action('admin_enqueue_scripts', function($hook_suffix = '') {
    if ( empty($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== 'eventosapp_whatsapp_flow_templates' ) {
        return;
    }

    if ( function_exists('wp_enqueue_media') ) {
        wp_enqueue_media();
    }
}, 24);

function eventosapp_whatsapp_flow_templates_default_item() {
    return [
        'id'                     => '',
        'name'                   => '',
        'display_name'           => '',
        'language'               => 'es_CO',
        'category'               => 'UTILITY',
        'meta_category'          => '',
        'header_format'          => 'NONE',
        'header_text'            => '',
        'header_sample_handle'   => '',
        'header_sample_file_name'=> '',
        'header_sample_file_type'=> '',
        'header_sample_file_size'=> 0,
        'header_sample_uploaded_at' => '',
        'header_image_url'       => '',
        'body'                   => 'Hola {{1}}, queremos conocer tu opinión sobre {{2}}. Por favor responde esta breve encuesta.',
        'sample_1'               => 'Meme',
        'sample_2'               => 'el evento',
        'footer_text'            => '',
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

function eventosapp_whatsapp_flow_templates_supported_categories() {
    return [
        'UTILITY'        => 'Utility',
        'MARKETING'      => 'Marketing',
        'AUTHENTICATION' => 'Authentication',
    ];
}

function eventosapp_whatsapp_flow_templates_sanitize_category($category, $fallback = 'UTILITY') {
    $category = strtoupper(sanitize_key((string) $category));
    $fallback = strtoupper(sanitize_key((string) $fallback));
    $supported = eventosapp_whatsapp_flow_templates_supported_categories();

    if ( $category === '' ) {
        $category = $fallback;
    }

    if ( ! isset($supported[$category]) ) {
        $category = isset($supported[$fallback]) ? $fallback : 'UTILITY';
    }

    return $category;
}

function eventosapp_whatsapp_flow_templates_normalize_meta_category($category) {
    $category = strtoupper(sanitize_key((string) $category));
    return $category !== '' ? $category : '';
}

function eventosapp_whatsapp_flow_templates_category_label($category) {
    $category = eventosapp_whatsapp_flow_templates_normalize_meta_category($category);
    $labels = eventosapp_whatsapp_flow_templates_supported_categories();
    return $labels[$category] ?? ($category !== '' ? $category : 'Sin categoría');
}


function eventosapp_whatsapp_flow_templates_sanitize_header_format($format) {
    $format = strtoupper(sanitize_key((string) $format));
    return in_array($format, ['NONE', 'TEXT', 'IMAGE'], true) ? $format : 'NONE';
}

function eventosapp_whatsapp_flow_templates_sanitize_header_handle($handle) {
    if ( function_exists('eventosapp_whatsapp_templates_sanitize_header_handle') ) {
        return eventosapp_whatsapp_templates_sanitize_header_handle($handle);
    }

    $handle = trim((string) $handle);
    if ( $handle === '' ) {
        return '';
    }

    $handle = wp_strip_all_tags($handle);
    $handle = preg_replace('/[\r\n\t]+/', '', $handle);
    return trim($handle);
}

function eventosapp_whatsapp_flow_templates_has_header_sample_upload() {
    return ! empty($_FILES['flow_header_sample_file'])
        && is_array($_FILES['flow_header_sample_file'])
        && isset($_FILES['flow_header_sample_file']['error'])
        && (int) $_FILES['flow_header_sample_file']['error'] !== UPLOAD_ERR_NO_FILE;
}

function eventosapp_whatsapp_flow_templates_upload_header_sample_to_meta($file) {
    if ( function_exists('eventosapp_whatsapp_templates_upload_header_sample_to_meta') ) {
        return eventosapp_whatsapp_templates_upload_header_sample_to_meta($file);
    }

    return [
        'ok'      => false,
        'message' => 'No está disponible el helper de subida de Header Sample. Revisa que eventosapp-whatsapp-templates.php esté cargado antes de este módulo.',
    ];
}

function eventosapp_whatsapp_flow_templates_sanitize_phone_number_id($value) {
    return function_exists('eventosapp_whatsapp_sanitize_phone_number_id')
        ? eventosapp_whatsapp_sanitize_phone_number_id($value)
        : preg_replace('/\D+/', '', (string) $value);
}

function eventosapp_whatsapp_flow_templates_sanitize_waba_id($value) {
    return function_exists('eventosapp_whatsapp_sanitize_waba_id')
        ? eventosapp_whatsapp_sanitize_waba_id($value)
        : preg_replace('/\D+/', '', (string) $value);
}

function eventosapp_whatsapp_flow_templates_get_template_settings() {
    return function_exists('eventosapp_whatsapp_templates_get_settings') ? eventosapp_whatsapp_templates_get_settings() : [];
}

function eventosapp_whatsapp_flow_templates_get_default_phone_number_id($settings = null) {
    $settings = is_array($settings) ? $settings : (function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : []);
    return eventosapp_whatsapp_flow_templates_sanitize_phone_number_id($settings['phone_number_id'] ?? '');
}

function eventosapp_whatsapp_flow_templates_get_default_waba_id($settings = null) {
    $settings = is_array($settings) ? $settings : (function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : []);
    $template_settings = eventosapp_whatsapp_flow_templates_get_template_settings();

    $waba_id = eventosapp_whatsapp_flow_templates_sanitize_waba_id($template_settings['waba_id'] ?? '');
    if ( $waba_id === '' && function_exists('eventosapp_whatsapp_get_effective_webhook_waba_id') ) {
        $waba_id = eventosapp_whatsapp_flow_templates_sanitize_waba_id(eventosapp_whatsapp_get_effective_webhook_waba_id($settings));
    }
    if ( $waba_id === '' ) {
        $waba_id = eventosapp_whatsapp_flow_templates_sanitize_waba_id($settings['webhook_waba_id'] ?? '');
    }

    return $waba_id;
}

function eventosapp_whatsapp_flow_templates_get_phone_accounts($settings = null) {
    $settings = is_array($settings) ? $settings : (function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : []);
    return function_exists('eventosapp_whatsapp_get_phone_accounts') ? eventosapp_whatsapp_get_phone_accounts($settings) : [];
}

function eventosapp_whatsapp_flow_templates_resolve_sender_account($phone_number_id = '', $settings = null) {
    $settings = is_array($settings) ? $settings : (function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : []);
    $accounts = eventosapp_whatsapp_flow_templates_get_phone_accounts($settings);
    $phone_number_id = eventosapp_whatsapp_flow_templates_sanitize_phone_number_id($phone_number_id);
    $default_phone_number_id = eventosapp_whatsapp_flow_templates_get_default_phone_number_id($settings);

    if ( $phone_number_id === '' ) {
        $phone_number_id = $default_phone_number_id;
    }

    $is_default = ($phone_number_id === '' || $phone_number_id === $default_phone_number_id);
    if ( $phone_number_id !== '' && isset($accounts[$phone_number_id]) && is_array($accounts[$phone_number_id]) ) {
        $account = $accounts[$phone_number_id];
        $is_default = $is_default || ! empty($account['is_default']);
        return [
            'phone_number_id' => $phone_number_id,
            'alias'           => sanitize_text_field((string)($account['alias'] ?? 'Número WhatsApp')),
            'label'           => sanitize_text_field((string)($account['label'] ?? (($account['alias'] ?? 'Número WhatsApp') . ' — ' . $phone_number_id))),
            'is_default'      => $is_default,
        ];
    }

    return [
        'phone_number_id' => $phone_number_id,
        'alias'           => $phone_number_id !== '' ? 'Número no disponible' : 'Número por defecto',
        'label'           => $phone_number_id !== '' ? 'Número no disponible — ' . $phone_number_id : 'Número por defecto',
        'is_default'      => $is_default,
    ];
}

function eventosapp_whatsapp_flow_templates_get_template_waba_id($template, $settings = null) {
    $template = is_array($template) ? $template : [];
    $settings = is_array($settings) ? $settings : (function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : []);
    $sender_account = eventosapp_whatsapp_flow_templates_resolve_sender_account($template['sender_phone_number_id'] ?? '', $settings);
    $template_waba_id = eventosapp_whatsapp_flow_templates_sanitize_waba_id($template['waba_id'] ?? '');

    if ( ! empty($sender_account['is_default']) ) {
        $default_waba_id = eventosapp_whatsapp_flow_templates_get_default_waba_id($settings);
        return $default_waba_id !== '' ? $default_waba_id : $template_waba_id;
    }

    return $template_waba_id;
}

function eventosapp_whatsapp_flow_templates_category_status_message($requested_category, $meta_category = '') {
    $requested_category = eventosapp_whatsapp_flow_templates_sanitize_category($requested_category);
    $meta_category = eventosapp_whatsapp_flow_templates_normalize_meta_category($meta_category);

    if ( $meta_category === '' ) {
        return 'Meta todavía no ha devuelto una categoría final para esta plantilla.';
    }

    if ( $requested_category === $meta_category ) {
        return 'Categoría confirmada por Meta: ' . eventosapp_whatsapp_flow_templates_category_label($meta_category) . '.';
    }

    return 'Meta reclasificó esta plantilla de ' . eventosapp_whatsapp_flow_templates_category_label($requested_category) . ' a ' . eventosapp_whatsapp_flow_templates_category_label($meta_category) . '. Esto puede pasar cuando el contenido parece promocional o de seguimiento comercial.';
}



function eventosapp_whatsapp_flow_templates_sanitize_screen_id($screen, $fallback = '') {
    $screen = strtoupper(trim(remove_accents((string) $screen)));
    $screen = preg_replace('/[^A-Z0-9_-]+/', '_', $screen);
    $screen = trim((string) $screen, '_-');

    if ( $screen === '' && $fallback !== '' ) {
        return eventosapp_whatsapp_flow_templates_sanitize_screen_id($fallback);
    }

    return $screen;
}

function eventosapp_whatsapp_flow_templates_get_flow_screen_ids($flow_post_id) {
    static $cache = [];

    $flow_post_id = absint($flow_post_id);
    if ( ! $flow_post_id ) {
        return [];
    }
    if ( isset($cache[$flow_post_id]) ) {
        return $cache[$flow_post_id];
    }

    $ids = [];
    if ( function_exists('eventosapp_whatsapp_flows_build_flow_json') ) {
        $flow_json = eventosapp_whatsapp_flows_build_flow_json($flow_post_id);
        if ( is_array($flow_json) && ! empty($flow_json['screens']) && is_array($flow_json['screens']) ) {
            foreach ( $flow_json['screens'] as $screen ) {
                if ( ! is_array($screen) ) {
                    continue;
                }
                $screen_id = eventosapp_whatsapp_flow_templates_sanitize_screen_id($screen['id'] ?? '');
                if ( $screen_id !== '' ) {
                    $ids[] = $screen_id;
                }
            }
        }
    }

    if ( empty($ids) && function_exists('eventosapp_whatsapp_flows_get_flow_config') ) {
        $flow_config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);
        $screen_id = eventosapp_whatsapp_flow_templates_sanitize_screen_id($flow_config['screen_id'] ?? '');
        if ( $screen_id !== '' ) {
            $ids[] = $screen_id;
        }
    }

    $cache[$flow_post_id] = array_values(array_unique(array_filter($ids)));
    return $cache[$flow_post_id];
}

function eventosapp_whatsapp_flow_templates_resolve_navigate_screen($template, $preferred_screen = '') {
    $template = wp_parse_args(is_array($template) ? $template : [], eventosapp_whatsapp_flow_templates_default_item());
    $flow_post_id = absint($template['flow_post_id'] ?? 0);
    $valid_screens = $flow_post_id ? eventosapp_whatsapp_flow_templates_get_flow_screen_ids($flow_post_id) : [];

    $candidates = [];
    if ( $preferred_screen !== '' ) {
        $candidates[] = $preferred_screen;
    }
    if ( ! empty($template['navigate_screen']) ) {
        $candidates[] = $template['navigate_screen'];
    }
    if ( $flow_post_id && function_exists('eventosapp_whatsapp_flows_get_flow_config') ) {
        $flow_config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);
        if ( ! empty($flow_config['screen_id']) ) {
            $candidates[] = $flow_config['screen_id'];
        }
    }
    if ( ! empty($valid_screens[0]) ) {
        $candidates[] = $valid_screens[0];
    }
    $candidates[] = 'SURVEY';

    foreach ( $candidates as $candidate ) {
        $screen = eventosapp_whatsapp_flow_templates_sanitize_screen_id($candidate);
        if ( $screen === '' ) {
            continue;
        }
        if ( empty($valid_screens) || in_array($screen, $valid_screens, true) ) {
            return $screen;
        }
    }

    return ! empty($valid_screens[0]) ? $valid_screens[0] : '';
}

function eventosapp_whatsapp_flow_templates_prepare_template_for_meta($template, $preferred_screen = '') {
    $template = wp_parse_args(is_array($template) ? $template : [], eventosapp_whatsapp_flow_templates_default_item());
    $flow_post_id = absint($template['flow_post_id'] ?? 0);

    $template['category'] = eventosapp_whatsapp_flow_templates_sanitize_category($template['category'] ?? 'UTILITY');
    $template['meta_category'] = eventosapp_whatsapp_flow_templates_normalize_meta_category($template['meta_category'] ?? '');
    $template['header_format'] = eventosapp_whatsapp_flow_templates_sanitize_header_format($template['header_format'] ?? 'NONE');
    $template['header_text'] = sanitize_text_field((string)($template['header_text'] ?? ''));
    $template['header_sample_handle'] = eventosapp_whatsapp_flow_templates_sanitize_header_handle($template['header_sample_handle'] ?? '');
    $template['header_sample_file_name'] = sanitize_file_name((string)($template['header_sample_file_name'] ?? ''));
    $template['header_sample_file_type'] = sanitize_mime_type((string)($template['header_sample_file_type'] ?? ''));
    $template['header_sample_file_size'] = absint($template['header_sample_file_size'] ?? 0);
    $template['header_sample_uploaded_at'] = sanitize_text_field((string)($template['header_sample_uploaded_at'] ?? ''));
    $template['header_image_url'] = esc_url_raw((string)($template['header_image_url'] ?? ''));
    $template['footer_text'] = sanitize_text_field((string)($template['footer_text'] ?? ''));
    $template['navigate_screen'] = eventosapp_whatsapp_flow_templates_resolve_navigate_screen($template, $preferred_screen);

    if ( $flow_post_id && function_exists('eventosapp_whatsapp_flows_get_flow_config') ) {
        $flow_config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);
        if ( empty($template['meta_flow_id']) && ! empty($flow_config['meta_flow_id']) ) {
            $template['meta_flow_id'] = preg_replace('/\D+/', '', (string) $flow_config['meta_flow_id']);
        }
        if ( empty($template['waba_id']) && ! empty($flow_config['waba_id']) ) {
            $template['waba_id'] = eventosapp_whatsapp_flow_templates_sanitize_waba_id($flow_config['waba_id']);
        }
        if ( empty($template['sender_phone_number_id']) && ! empty($flow_config['sender_phone_number_id']) ) {
            $template['sender_phone_number_id'] = eventosapp_whatsapp_flow_templates_sanitize_phone_number_id($flow_config['sender_phone_number_id']);
        }
    }

    $template['meta_flow_id'] = preg_replace('/\D+/', '', (string)($template['meta_flow_id'] ?? ''));
    $template['waba_id'] = eventosapp_whatsapp_flow_templates_sanitize_waba_id($template['waba_id'] ?? '');
    $template['sender_phone_number_id'] = eventosapp_whatsapp_flow_templates_sanitize_phone_number_id($template['sender_phone_number_id'] ?? '');

    return $template;
}

function eventosapp_whatsapp_flow_templates_validate_before_meta($template, $payload) {
    $template = wp_parse_args(is_array($template) ? $template : [], eventosapp_whatsapp_flow_templates_default_item());
    $errors = [];
    $button = [];

    foreach ( (array)($payload['components'] ?? []) as $component ) {
        if ( is_array($component) && strtoupper((string)($component['type'] ?? '')) === 'BUTTONS' && ! empty($component['buttons'][0]) && is_array($component['buttons'][0]) ) {
            $button = $component['buttons'][0];
            break;
        }
    }

    $flow_id = preg_replace('/\D+/', '', (string)($button['flow_id'] ?? ''));
    $screen = eventosapp_whatsapp_flow_templates_sanitize_screen_id($button['navigate_screen'] ?? '');
    $flow_post_id = absint($template['flow_post_id'] ?? 0);
    $header_format = eventosapp_whatsapp_flow_templates_sanitize_header_format($template['header_format'] ?? 'NONE');

    if ( $flow_id === '' ) {
        $errors[] = 'La plantilla necesita un Flow ID de Meta creado para poder asociarlo al botón Flow.';
    }
    if ( $screen === '' ) {
        $errors[] = 'La plantilla necesita una pantalla inicial válida para el campo navigate_screen.';
    }
    if ( $header_format === 'TEXT' && trim((string)($template['header_text'] ?? '')) === '' ) {
        $errors[] = 'Seleccionaste encabezado de texto, pero falta el texto del encabezado.';
    }
    if ( $header_format === 'IMAGE' ) {
        if ( empty($template['header_sample_handle']) ) {
            $errors[] = 'Seleccionaste encabezado de imagen. Para enviar la plantilla a Meta debes subir una imagen de muestra y generar el Header Sample Handle.';
        } elseif ( preg_match('/^https?:\/\//i', (string) $template['header_sample_handle']) ) {
            $errors[] = 'El Header Sample Handle no puede ser una URL pública. Debe ser el handle que Meta devuelve al subir la imagen de muestra.';
        }
    }

    if ( $flow_post_id ) {
        $valid_screens = eventosapp_whatsapp_flow_templates_get_flow_screen_ids($flow_post_id);
        if ( ! empty($valid_screens) && $screen !== '' && ! in_array($screen, $valid_screens, true) ) {
            $errors[] = 'La pantalla inicial "' . $screen . '" no existe en el JSON local del Flow. Pantallas válidas: ' . implode(', ', $valid_screens) . '.';
        }
    }

    return $errors;
}

function eventosapp_whatsapp_flow_templates_build_flows_ui_metadata($flows) {
    $metadata = [];
    foreach ( is_array($flows) ? $flows : [] as $flow ) {
        $flow_id = absint($flow['id'] ?? 0);
        if ( ! $flow_id ) {
            continue;
        }
        $config = function_exists('eventosapp_whatsapp_flows_get_flow_config') ? eventosapp_whatsapp_flows_get_flow_config($flow_id) : [];
        $screen_ids = eventosapp_whatsapp_flow_templates_get_flow_screen_ids($flow_id);
        $metadata[$flow_id] = [
            'post_id'                 => $flow_id,
            'title'                   => sanitize_text_field((string)($flow['title'] ?? ($config['title'] ?? 'Flow #' . $flow_id))),
            'status'                  => sanitize_text_field((string)($config['status'] ?? ($flow['status'] ?? ''))),
            'waba_id'                 => eventosapp_whatsapp_flow_templates_sanitize_waba_id($config['waba_id'] ?? ''),
            'sender_phone_number_id'  => eventosapp_whatsapp_flow_templates_sanitize_phone_number_id($config['sender_phone_number_id'] ?? ''),
            'meta_flow_id'            => preg_replace('/\D+/', '', (string)($config['meta_flow_id'] ?? $flow['meta_flow_id'] ?? '')),
            'initial_screen'          => eventosapp_whatsapp_flow_templates_resolve_navigate_screen([
                'flow_post_id'    => $flow_id,
                'navigate_screen' => $config['screen_id'] ?? '',
            ]),
            'screen_ids'              => $screen_ids,
        ];
    }
    return $metadata;
}

function eventosapp_whatsapp_flow_templates_build_meta_payload($template) {
    $template = eventosapp_whatsapp_flow_templates_prepare_template_for_meta($template);
    $flow_id = preg_replace('/\D+/', '', (string)($template['meta_flow_id'] ?? ''));

    $button = [
        'type'    => 'FLOW',
        'text'    => sanitize_text_field((string)($template['button_text'] ?? 'Responder')),
        'flow_id' => $flow_id,
    ];

    $screen = eventosapp_whatsapp_flow_templates_resolve_navigate_screen($template);
    if ( $screen !== '' ) {
        $button['navigate_screen'] = $screen;
        $button['flow_action'] = 'navigate';
    }

    $components = [];
    $header_format = eventosapp_whatsapp_flow_templates_sanitize_header_format($template['header_format'] ?? 'NONE');
    if ( $header_format === 'IMAGE' ) {
        $components[] = [
            'type'    => 'HEADER',
            'format'  => 'IMAGE',
            'example' => [
                'header_handle' => [ eventosapp_whatsapp_flow_templates_sanitize_header_handle($template['header_sample_handle'] ?? '') ],
            ],
        ];
    } elseif ( $header_format === 'TEXT' && trim((string)($template['header_text'] ?? '')) !== '' ) {
        $components[] = [
            'type'   => 'HEADER',
            'format' => 'TEXT',
            'text'   => sanitize_text_field((string)($template['header_text'] ?? '')),
        ];
    }

    $example_body = [];
    $sample_1 = trim((string)($template['sample_1'] ?? ''));
    $sample_2 = trim((string)($template['sample_2'] ?? ''));
    if ( $sample_1 === '' ) {
        $sample_1 = 'Asistente';
    }
    if ( $sample_2 === '' ) {
        $sample_2 = 'Evento';
    }
    if ( strpos((string)($template['body'] ?? ''), '{{1}}') !== false ) {
        $example_body[] = sanitize_text_field($sample_1);
    }
    if ( strpos((string)($template['body'] ?? ''), '{{2}}') !== false ) {
        $example_body[] = sanitize_text_field($sample_2);
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
    $components[] = $body_component;

    if ( trim((string)($template['footer_text'] ?? '')) !== '' ) {
        $components[] = [
            'type' => 'FOOTER',
            'text' => sanitize_text_field((string)($template['footer_text'] ?? '')),
        ];
    }

    $components[] = [
        'type'    => 'BUTTONS',
        'buttons' => [$button],
    ];

    return [
        'name'       => eventosapp_whatsapp_flow_templates_template_name($template['name'] ?? ''),
        'language'   => sanitize_text_field((string)($template['language'] ?? 'es_CO')),
        'category'   => eventosapp_whatsapp_flow_templates_sanitize_category($template['category'] ?? 'UTILITY'),
        'components' => $components,
    ];
}

function eventosapp_whatsapp_flow_templates_build_send_payload($template, $flow_token = '', $context = []) {
    $template = wp_parse_args(is_array($template) ? $template : [], eventosapp_whatsapp_flow_templates_default_item());
    $template = eventosapp_whatsapp_flow_templates_prepare_template_for_meta($template);
    $context = is_array($context) ? $context : [];
    $parameters = [];

    if ( strpos((string)$template['body'], '{{1}}') !== false ) {
        $parameter_1 = trim((string)($context['name'] ?? ''));
        if ( $parameter_1 === '' ) {
            $parameter_1 = trim((string)($template['sample_1'] ?? ''));
        }
        if ( $parameter_1 === '' ) {
            $parameter_1 = 'Asistente';
        }
        $parameters[] = [
            'type' => 'text',
            'text' => $parameter_1,
        ];
    }
    if ( strpos((string)$template['body'], '{{2}}') !== false ) {
        $parameter_2 = trim((string)($context['event_name'] ?? ''));
        if ( $parameter_2 === '' ) {
            $parameter_2 = trim((string)($template['sample_2'] ?? ''));
        }
        if ( $parameter_2 === '' ) {
            $parameter_2 = 'Evento';
        }
        $parameters[] = [
            'type' => 'text',
            'text' => $parameter_2,
        ];
    }

    $components = [];
    if ( ($template['header_format'] ?? '') === 'IMAGE' && ! empty($template['header_image_url']) ) {
        $components[] = [
            'type'       => 'header',
            'parameters' => [
                [
                    'type'  => 'image',
                    'image' => [
                        'link' => esc_url_raw((string) $template['header_image_url']),
                    ],
                ],
            ],
        ];
    }

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
    $posted_template_id = sanitize_key((string)($_POST['template_id'] ?? ''));
    $save_mode = sanitize_key((string)($_POST['save_mode'] ?? 'save'));
    $template_id = $posted_template_id;

    if ( $template_id === '' || $save_mode === 'save_as_new' ) {
        $template_id = 'flow_tpl_' . wp_generate_password(12, false, false);
        $template_id = sanitize_key($template_id);
    }

    $existing = ($save_mode !== 'save_as_new' && isset($items[$template_id]) && is_array($items[$template_id])) ? $items[$template_id] : [];
    $flow_post_id = absint($_POST['flow_post_id'] ?? 0);
    $meta_flow_id = preg_replace('/\D+/', '', (string)($_POST['meta_flow_id'] ?? ''));
    $screen = eventosapp_whatsapp_flow_templates_sanitize_screen_id($_POST['navigate_screen'] ?? 'SURVEY');

    if ( $flow_post_id && function_exists('eventosapp_whatsapp_flows_get_flow_config') ) {
        $flow_config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);
        if ( $meta_flow_id === '' ) {
            $meta_flow_id = preg_replace('/\D+/', '', (string)($flow_config['meta_flow_id'] ?? ''));
        }
        $screen = eventosapp_whatsapp_flow_templates_resolve_navigate_screen([
            'flow_post_id'    => $flow_post_id,
            'navigate_screen' => $screen,
        ]);
    }

    $item = wp_parse_args([
        'id'                     => $template_id,
        'name'                   => eventosapp_whatsapp_flow_templates_template_name($_POST['template_name'] ?? ''),
        'display_name'           => sanitize_text_field((string)($_POST['display_name'] ?? '')),
        'language'               => sanitize_text_field((string)($_POST['language'] ?? 'es_CO')),
        'category'               => eventosapp_whatsapp_flow_templates_sanitize_category($_POST['category'] ?? 'UTILITY'),
        'meta_category'          => eventosapp_whatsapp_flow_templates_normalize_meta_category($existing['meta_category'] ?? ''),
        'header_format'          => eventosapp_whatsapp_flow_templates_sanitize_header_format($_POST['header_format'] ?? 'NONE'),
        'header_text'            => sanitize_text_field((string)($_POST['header_text'] ?? '')),
        'header_sample_handle'   => eventosapp_whatsapp_flow_templates_sanitize_header_handle($_POST['header_sample_handle'] ?? ($existing['header_sample_handle'] ?? '')),
        'header_sample_file_name'=> sanitize_file_name((string)($existing['header_sample_file_name'] ?? '')),
        'header_sample_file_type'=> sanitize_mime_type((string)($existing['header_sample_file_type'] ?? '')),
        'header_sample_file_size'=> absint($existing['header_sample_file_size'] ?? 0),
        'header_sample_uploaded_at' => sanitize_text_field((string)($existing['header_sample_uploaded_at'] ?? '')),
        'header_image_url'       => esc_url_raw((string)($_POST['header_image_url'] ?? ($existing['header_image_url'] ?? ''))),
        'body'                   => sanitize_textarea_field((string)($_POST['body'] ?? '')),
        'sample_1'               => sanitize_text_field((string)($_POST['sample_1'] ?? ($existing['sample_1'] ?? 'Meme'))),
        'sample_2'               => sanitize_text_field((string)($_POST['sample_2'] ?? ($existing['sample_2'] ?? 'el evento'))),
        'footer_text'            => sanitize_text_field((string)($_POST['footer_text'] ?? '')),
        'button_text'            => sanitize_text_field((string)($_POST['button_text'] ?? 'Responder encuesta')),
        'flow_post_id'           => $flow_post_id,
        'meta_flow_id'           => $meta_flow_id,
        'navigate_screen'        => $screen !== '' ? $screen : 'SURVEY',
        'waba_id'                => eventosapp_whatsapp_flow_templates_sanitize_waba_id($_POST['waba_id'] ?? ''),
        'sender_phone_number_id' => eventosapp_whatsapp_flow_templates_sanitize_phone_number_id($_POST['sender_phone_number_id'] ?? ''),
        'updated_at'             => current_time('mysql'),
    ], eventosapp_whatsapp_flow_templates_default_item());

    $item = eventosapp_whatsapp_flow_templates_prepare_template_for_meta($item, $screen);

    $upload_message = '';
    $notice_type = 'success';
    if ( eventosapp_whatsapp_flow_templates_has_header_sample_upload() ) {
        if ( $item['header_format'] !== 'IMAGE' ) {
            $notice_type = 'warning';
            $upload_message = 'Seleccionaste un archivo de muestra, pero el encabezado no está configurado como Imagen. La plantilla se guardó sin subir la muestra.';
        } else {
            $upload_result = eventosapp_whatsapp_flow_templates_upload_header_sample_to_meta($_FILES['flow_header_sample_file']);
            $item['last_meta_response'] = $upload_result;
            if ( ! empty($upload_result['ok']) ) {
                $file_meta = is_array($upload_result['file'] ?? null) ? $upload_result['file'] : [];
                $item['header_sample_handle'] = eventosapp_whatsapp_flow_templates_sanitize_header_handle($upload_result['handle'] ?? '');
                $item['header_sample_file_name'] = sanitize_file_name($file_meta['name'] ?? '');
                $item['header_sample_file_type'] = sanitize_mime_type($file_meta['type'] ?? '');
                $item['header_sample_file_size'] = absint($file_meta['size'] ?? 0);
                $item['header_sample_uploaded_at'] = current_time('mysql');
                $upload_message = $upload_result['message'] ?? 'Imagen de muestra subida a Meta y handle guardado.';
            } else {
                $notice_type = 'warning';
                $upload_message = $upload_result['message'] ?? 'No se pudo subir la imagen de muestra a Meta. La plantilla local quedó guardada.';
            }
        }
    }

    if ( empty($existing['created_at']) ) {
        $item['created_at'] = current_time('mysql');
    } else {
        $item['created_at'] = $existing['created_at'];
    }

    if ( $save_mode !== 'save_as_new' && ! empty($existing) ) {
        foreach ( ['meta_status', 'meta_template_id', 'last_meta_response', 'meta_category'] as $keep_key ) {
            if ( isset($existing[$keep_key]) && ( $keep_key !== 'last_meta_response' || $upload_message === '' ) ) {
                $item[$keep_key] = $existing[$keep_key];
            }
        }
    }

    $items[$template_id] = $item;
    eventosapp_whatsapp_flow_templates_save_all($items);

    $message = 'Plantilla Flow guardada localmente.';
    if ( $save_mode === 'save_as_new' ) {
        $message = 'Plantilla Flow guardada como nueva copia.';
    }
    if ( $upload_message !== '' ) {
        $message .= ' ' . $upload_message;
    }

    eventosapp_whatsapp_flow_templates_notice_redirect([
        'template_id' => $template_id,
        'flow_tpl_notice' => $notice_type,
        'flow_tpl_message' => rawurlencode($message),
    ]);
});



add_action('admin_post_eventosapp_whatsapp_flow_template_duplicate', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    check_admin_referer('eventosapp_whatsapp_flow_template_duplicate');

    $template_id = sanitize_key((string)($_POST['template_id'] ?? ''));
    $items = eventosapp_whatsapp_flow_templates_get_all();
    if ( empty($template_id) || empty($items[$template_id]) || ! is_array($items[$template_id]) ) {
        eventosapp_whatsapp_flow_templates_notice_redirect(['flow_tpl_notice' => 'error', 'flow_tpl_message' => rawurlencode('No se encontró la plantilla para duplicar.')]);
    }

    $new_id = sanitize_key('flow_tpl_' . wp_generate_password(12, false, false));
    $copy = wp_parse_args($items[$template_id], eventosapp_whatsapp_flow_templates_default_item());
    $copy['id'] = $new_id;
    $copy['display_name'] = trim((string)($copy['display_name'] ?: $copy['name'])) . ' (copia)';
    $copy['name'] = eventosapp_whatsapp_flow_templates_template_name(($copy['name'] ?: 'eventosapp_flow') . '_copy_' . substr($new_id, -6));
    $copy['meta_status'] = 'local_draft';
    $copy['meta_template_id'] = '';
    $copy['meta_category'] = '';
    $copy['last_meta_response'] = [];
    $copy['created_at'] = current_time('mysql');
    $copy['updated_at'] = current_time('mysql');

    $items[$new_id] = $copy;
    eventosapp_whatsapp_flow_templates_save_all($items);

    eventosapp_whatsapp_flow_templates_notice_redirect([
        'template_id' => $new_id,
        'flow_tpl_notice' => 'success',
        'flow_tpl_message' => rawurlencode('Plantilla duplicada. Puedes editarla sin afectar la original.'),
    ]);
});

add_action('admin_post_eventosapp_whatsapp_flow_template_delete', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    check_admin_referer('eventosapp_whatsapp_flow_template_delete');

    $template_id = sanitize_key((string)($_POST['template_id'] ?? ''));
    $items = eventosapp_whatsapp_flow_templates_get_all();
    if ( $template_id === '' || empty($items[$template_id]) ) {
        eventosapp_whatsapp_flow_templates_notice_redirect(['flow_tpl_notice' => 'error', 'flow_tpl_message' => rawurlencode('No se encontró la plantilla para eliminar.')]);
    }

    unset($items[$template_id]);
    eventosapp_whatsapp_flow_templates_save_all($items);

    eventosapp_whatsapp_flow_templates_notice_redirect([
        'flow_tpl_notice' => 'success',
        'flow_tpl_message' => rawurlencode('Plantilla Flow eliminada localmente.'),
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

    $settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];
    $template = eventosapp_whatsapp_flow_templates_prepare_template_for_meta($template);
    $waba_id = eventosapp_whatsapp_flow_templates_get_template_waba_id($template, $settings);
    if ( $waba_id === '' ) {
        eventosapp_whatsapp_flow_templates_notice_redirect(['template_id' => $template_id, 'flow_tpl_notice' => 'error', 'flow_tpl_message' => rawurlencode('Falta WABA ID para enviar la plantilla a Meta. Si usas un número distinto al principal, escribe el WABA de ese número en la plantilla.')]);
    }

    $payload = eventosapp_whatsapp_flow_templates_build_meta_payload($template);
    $validation_errors = eventosapp_whatsapp_flow_templates_validate_before_meta($template, $payload);
    if ( ! empty($validation_errors) ) {
        eventosapp_whatsapp_flow_templates_notice_redirect([
            'template_id'       => $template_id,
            'flow_tpl_notice'   => 'error',
            'flow_tpl_message'  => rawurlencode(implode(' ', $validation_errors)),
        ]);
    }

    $sender_settings = function_exists('eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id')
        ? eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id($template['sender_phone_number_id'] ?? '', $settings)
        : $settings;
    $result = function_exists('eventosapp_whatsapp_graph_api_request')
        ? eventosapp_whatsapp_graph_api_request('POST', $waba_id . '/message_templates', $payload, $sender_settings)
        : ['ok' => false, 'message' => 'No está disponible el cliente Graph API de WhatsApp Tickets.'];

    $items = eventosapp_whatsapp_flow_templates_get_all();
    if ( isset($items[$template_id]) ) {
        $items[$template_id]['waba_id'] = $waba_id;
        $items[$template_id]['sender_phone_number_id'] = eventosapp_whatsapp_flow_templates_sanitize_phone_number_id($template['sender_phone_number_id'] ?? '');
        $items[$template_id]['meta_flow_id'] = preg_replace('/\D+/', '', (string)($template['meta_flow_id'] ?? ''));
        $items[$template_id]['navigate_screen'] = eventosapp_whatsapp_flow_templates_sanitize_screen_id($template['navigate_screen'] ?? '');
        $items[$template_id]['last_meta_response'] = $result;
        $items[$template_id]['updated_at'] = current_time('mysql');
        if ( ! empty($result['ok']) ) {
            $response = is_array($result['response'] ?? null) ? $result['response'] : [];
            $items[$template_id]['meta_status'] = ! empty($response['status']) ? sanitize_key(strtolower((string)$response['status'])) : 'submitted';
            if ( ! empty($response['id']) ) {
                $items[$template_id]['meta_template_id'] = sanitize_text_field((string)$response['id']);
            }
            if ( ! empty($response['category']) ) {
                $items[$template_id]['meta_category'] = eventosapp_whatsapp_flow_templates_normalize_meta_category($response['category']);
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
            'waba_id' => $waba_id,
            'sender_phone_number_id' => $sender_settings['phone_number_id'] ?? '',
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

    $settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];
    $template = eventosapp_whatsapp_flow_templates_prepare_template_for_meta($template);
    $waba_id = eventosapp_whatsapp_flow_templates_get_template_waba_id($template, $settings);
    if ( $waba_id === '' ) {
        eventosapp_whatsapp_flow_templates_notice_redirect(['template_id' => $template_id, 'flow_tpl_notice' => 'error', 'flow_tpl_message' => rawurlencode('Falta WABA ID para consultar la plantilla en Meta.')]);
    }

    $sender_settings = function_exists('eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id')
        ? eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id($template['sender_phone_number_id'] ?? '', $settings)
        : $settings;
    $name = eventosapp_whatsapp_flow_templates_template_name($template['name'] ?? '');
    $path = $waba_id . '/message_templates?name=' . rawurlencode($name) . '&fields=id,name,status,category,language,components';
    $result = function_exists('eventosapp_whatsapp_graph_api_request')
        ? eventosapp_whatsapp_graph_api_request('GET', $path, null, $sender_settings)
        : ['ok' => false, 'message' => 'No está disponible el cliente Graph API de WhatsApp Tickets.'];

    $items = eventosapp_whatsapp_flow_templates_get_all();
    if ( isset($items[$template_id]) ) {
        $items[$template_id]['waba_id'] = $waba_id;
        $items[$template_id]['last_meta_response'] = $result;
        $items[$template_id]['updated_at'] = current_time('mysql');
        if ( ! empty($result['ok']) && is_array($result['response']['data'][0] ?? null) ) {
            $remote = $result['response']['data'][0];
            $items[$template_id]['meta_status'] = sanitize_key(strtolower((string)($remote['status'] ?? 'synced')));
            $items[$template_id]['meta_template_id'] = sanitize_text_field((string)($remote['id'] ?? ''));
            $items[$template_id]['meta_category'] = eventosapp_whatsapp_flow_templates_normalize_meta_category($remote['category'] ?? '');
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
    $test_sample_1 = sanitize_text_field((string)($_POST['test_sample_1'] ?? ''));
    $test_sample_2 = sanitize_text_field((string)($_POST['test_sample_2'] ?? ''));
    $test_header_image_url = esc_url_raw((string)($_POST['test_header_image_url'] ?? ''));
    $template = eventosapp_whatsapp_flow_templates_get($template_id);
    if ( empty($template) || $phone === '' ) {
        eventosapp_whatsapp_flow_templates_notice_redirect(['template_id' => $template_id, 'flow_tpl_notice' => 'error', 'flow_tpl_message' => rawurlencode('Plantilla o teléfono inválido.')]);
    }

    $template = eventosapp_whatsapp_flow_templates_prepare_template_for_meta($template);
    if ( $test_sample_1 !== '' ) {
        $template['sample_1'] = $test_sample_1;
    }
    if ( $test_sample_2 !== '' ) {
        $template['sample_2'] = $test_sample_2;
    }
    if ( ($template['header_format'] ?? '') === 'IMAGE' ) {
        if ( $test_header_image_url !== '' ) {
            $template['header_image_url'] = $test_header_image_url;
        }
        if ( empty($template['header_image_url']) ) {
            eventosapp_whatsapp_flow_templates_notice_redirect(['template_id' => $template_id, 'flow_tpl_notice' => 'error', 'flow_tpl_message' => rawurlencode('La plantilla tiene encabezado de imagen. Para enviar una prueba debes seleccionar o pegar una URL pública HTTPS en el campo Header de prueba.')]);
        }
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
    $template_id_raw = isset($_GET['template_id']) ? sanitize_key((string) $_GET['template_id']) : '';
    $template_id = $template_id_raw;
    $selected = ($template_id !== '' && $template_id !== '0') ? eventosapp_whatsapp_flow_templates_get($template_id) : [];
    if ( empty($selected) && ! empty($items) && $template_id_raw === '' ) {
        $first = reset($items);
        $selected = wp_parse_args($first, eventosapp_whatsapp_flow_templates_default_item());
        $template_id = $selected['id'];
    }

    $settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];
    $default_waba_id = eventosapp_whatsapp_flow_templates_get_default_waba_id($settings);
    $default_phone_number_id = eventosapp_whatsapp_flow_templates_get_default_phone_number_id($settings);
    $phone_accounts = eventosapp_whatsapp_flow_templates_get_phone_accounts($settings);
    $flows = function_exists('eventosapp_whatsapp_flows_get_all_for_select') ? eventosapp_whatsapp_flows_get_all_for_select() : [];
    $flows_ui_metadata = eventosapp_whatsapp_flow_templates_build_flows_ui_metadata($flows);
    $edit = ! empty($selected) ? wp_parse_args($selected, eventosapp_whatsapp_flow_templates_default_item()) : wp_parse_args([
        'waba_id' => $default_waba_id,
        'sender_phone_number_id' => $default_phone_number_id,
    ], eventosapp_whatsapp_flow_templates_default_item());
    $edit = eventosapp_whatsapp_flow_templates_prepare_template_for_meta($edit);
    $edit_screen_ids = ! empty($edit['flow_post_id']) ? eventosapp_whatsapp_flow_templates_get_flow_screen_ids($edit['flow_post_id']) : [];
    $effective_waba_id = eventosapp_whatsapp_flow_templates_get_template_waba_id($edit, $settings);
    $sender_account = eventosapp_whatsapp_flow_templates_resolve_sender_account($edit['sender_phone_number_id'] ?? '', $settings);
    $category_message = eventosapp_whatsapp_flow_templates_category_status_message($edit['category'] ?? 'UTILITY', $edit['meta_category'] ?? '');
    $category_mismatch = ! empty($edit['meta_category']) && eventosapp_whatsapp_flow_templates_sanitize_category($edit['category'] ?? 'UTILITY') !== eventosapp_whatsapp_flow_templates_normalize_meta_category($edit['meta_category'] ?? '');

    ?>
    <div class="wrap eventosapp-wa-flow-templates">
        <div class="evapp-page-hero">
            <h1>Plantillas Flow WhatsApp</h1>
            <p>Administra plantillas aprobables por Meta para abrir WhatsApp Flows, revisa rápidamente las plantillas existentes y ejecuta pruebas desde un bloque único sin perder de vista el Flow, el número emisor y el ticket usado.</p>
            <div class="evapp-workflow-pills">
                <span class="evapp-step-pill"><span>1</span> Edita contenido</span>
                <span class="evapp-step-pill"><span>2</span> Revisa Flow y cuenta Meta</span>
                <span class="evapp-step-pill"><span>3</span> Envía aprobación o prueba</span>
            </div>
        </div>
        <?php eventosapp_whatsapp_flow_templates_render_notices(); ?>
        <style>
            .eventosapp-wa-flow-templates{
                --evapp-border:#dcdcde;
                --evapp-soft:#f6f7f7;
                --evapp-muted:#646970;
                --evapp-blue:#0a5ea8;
                --evapp-blue-soft:#eef6ff;
                --evapp-warning:#fff8e5;
                --evapp-warning-border:#dba617;
                --evapp-danger:#b32d2e;
            }
            .eventosapp-wa-flow-templates .evapp-page-hero{
                background:linear-gradient(135deg,#ffffff 0%,#f0f6fc 100%);
                border:1px solid var(--evapp-border);
                border-radius:14px;
                padding:18px 20px;
                margin:12px 0 18px;
                box-shadow:0 1px 2px rgba(0,0,0,.04);
            }
            .eventosapp-wa-flow-templates .evapp-page-hero h1,
            .eventosapp-wa-flow-templates .evapp-page-hero p{margin:0;}
            .eventosapp-wa-flow-templates .evapp-page-hero h1{font-size:26px;line-height:1.2;}
            .eventosapp-wa-flow-templates .evapp-page-hero p{max-width:980px;color:#3c434a;margin-top:6px;font-size:14px;line-height:1.55;}
            .eventosapp-wa-flow-templates .evapp-workflow-pills{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;}
            .eventosapp-wa-flow-templates .evapp-step-pill{display:inline-flex;align-items:center;gap:7px;background:#fff;border:1px solid #c3d9ef;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:600;color:#1d3f5f;}
            .eventosapp-wa-flow-templates .evapp-step-pill span{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--evapp-blue);color:#fff;font-size:11px;}
            .eventosapp-wa-flow-templates .evapp-grid{display:grid;grid-template-columns:minmax(560px,1.08fr) minmax(520px,.92fr);gap:20px;align-items:start;}
            .eventosapp-wa-flow-templates .evapp-stack{display:flex;flex-direction:column;gap:16px;}
            .eventosapp-wa-flow-templates .evapp-card{background:#fff;border:1px solid var(--evapp-border);border-radius:12px;padding:0;margin:0;box-shadow:0 1px 2px rgba(0,0,0,.04);overflow:hidden;}
            .eventosapp-wa-flow-templates .evapp-card-header{padding:16px 18px;border-bottom:1px solid #edf0f2;background:#fbfbfc;}
            .eventosapp-wa-flow-templates .evapp-card-header h2{margin:0;font-size:18px;line-height:1.3;}
            .eventosapp-wa-flow-templates .evapp-card-header p{margin:5px 0 0;color:var(--evapp-muted);line-height:1.45;}
            .eventosapp-wa-flow-templates .evapp-card-body{padding:18px;}
            .eventosapp-wa-flow-templates .evapp-form-table{margin-top:0;}
            .eventosapp-wa-flow-templates .evapp-form-table th{width:180px;padding-top:18px;}
            .eventosapp-wa-flow-templates .evapp-form-table td{padding-top:14px;padding-bottom:14px;}
            .eventosapp-wa-flow-templates .evapp-section-row th,
            .eventosapp-wa-flow-templates .evapp-section-row td{padding:22px 0 8px;border-top:1px solid #edf0f2;}
            .eventosapp-wa-flow-templates .evapp-section-title{display:flex;align-items:center;gap:9px;font-size:15px;font-weight:700;color:#1d2327;}
            .eventosapp-wa-flow-templates .evapp-section-title span{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:var(--evapp-blue-soft);color:var(--evapp-blue);font-size:12px;font-weight:700;}
            .eventosapp-wa-flow-templates .evapp-muted{color:var(--evapp-muted);}
            .eventosapp-wa-flow-templates .evapp-pill{display:inline-block;border-radius:999px;background:var(--evapp-blue-soft);color:var(--evapp-blue);padding:4px 9px;font-size:12px;font-weight:600;white-space:nowrap;}
            .eventosapp-wa-flow-templates .evapp-pill.warn{background:#fff3cd;color:#664d03;}
            .eventosapp-wa-flow-templates .evapp-pill.error{background:#f8d7da;color:#842029;}
            .eventosapp-wa-flow-templates .evapp-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
            .eventosapp-wa-flow-templates textarea.code{width:100%;min-height:320px;font-family:Menlo,Consolas,monospace;font-size:12px;line-height:1.45;}
            .eventosapp-wa-flow-templates .evapp-help{color:var(--evapp-muted);font-size:12px;margin:5px 0 0;line-height:1.45;}
            .eventosapp-wa-flow-templates .evapp-info{background:#f0f6fc;border-left:4px solid #72aee6;padding:10px 12px;margin:10px 0;border-radius:0 8px 8px 0;}
            .eventosapp-wa-flow-templates .evapp-warning{background:var(--evapp-warning);border-left:4px solid var(--evapp-warning-border);padding:10px 12px;margin:10px 0;border-radius:0 8px 8px 0;}
            .eventosapp-wa-flow-templates .evapp-flow-detail{background:var(--evapp-soft);border:1px solid var(--evapp-border);border-radius:8px;padding:10px;margin-top:10px;line-height:1.5;}
            .eventosapp-wa-flow-templates .evapp-inline-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;align-items:start;max-width:780px;}
            .eventosapp-wa-flow-templates .evapp-inline-fields input,
            .eventosapp-wa-flow-templates .evapp-inline-fields select{width:100%;max-width:100%;}
            .eventosapp-wa-flow-templates .evapp-file-meta{background:var(--evapp-soft);border:1px solid var(--evapp-border);border-radius:8px;padding:9px;margin-top:8px;max-width:720px;word-break:break-word;}
            .eventosapp-wa-flow-templates .evapp-danger-link{color:var(--evapp-danger);border-color:var(--evapp-danger);}
            .eventosapp-wa-flow-templates select.regular-text,
            .eventosapp-wa-flow-templates input.regular-text{max-width:100%;}
            .eventosapp-wa-flow-templates .evapp-list-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;}
            .eventosapp-wa-flow-templates .evapp-list-toolbar p{margin:0;}
            .eventosapp-wa-flow-templates .evapp-template-list-wrap{border:1px solid var(--evapp-border);border-radius:10px;overflow:auto;max-height:720px;background:#fff;}
            .eventosapp-wa-flow-templates .evapp-template-list{border:0;margin:0;min-width:820px;}
            .eventosapp-wa-flow-templates .evapp-template-list thead th{position:sticky;top:0;z-index:1;background:#f6f7f7;border-bottom:1px solid var(--evapp-border);}
            .eventosapp-wa-flow-templates .evapp-template-list td{vertical-align:top;padding:12px 10px;}
            .eventosapp-wa-flow-templates .evapp-template-list .is-selected td{background:#f0f6fc;}
            .eventosapp-wa-flow-templates .evapp-template-name{font-weight:700;font-size:13px;}
            .eventosapp-wa-flow-templates .evapp-template-actions{display:flex;gap:6px;flex-wrap:wrap;min-width:180px;}
            .eventosapp-wa-flow-templates .evapp-empty-state{border:1px dashed #c3c4c7;background:#fbfbfc;border-radius:10px;padding:18px;text-align:center;color:var(--evapp-muted);}
            .eventosapp-wa-flow-templates .evapp-test-panel{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;align-items:end;}
            .eventosapp-wa-flow-templates .evapp-test-panel label{font-weight:600;display:block;}
            .eventosapp-wa-flow-templates .evapp-test-panel input{width:100%;margin-top:4px;}
            .eventosapp-wa-flow-templates .evapp-test-panel .evapp-test-actions{grid-column:1 / -1;display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:2px;}
            .eventosapp-wa-flow-templates .evapp-test-image-field{grid-column:1 / -1;background:#fbfbfc;border:1px solid #edf0f2;border-radius:10px;padding:12px;}
            .eventosapp-wa-flow-templates .evapp-test-image-field > label{margin-bottom:6px;}
            .eventosapp-wa-flow-templates .evapp-media-field{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
            .eventosapp-wa-flow-templates .evapp-media-field input{flex:1 1 360px;min-width:240px;}
            .eventosapp-wa-flow-templates .evapp-media-preview{display:none;margin-top:10px;}
            .eventosapp-wa-flow-templates .evapp-media-preview img{max-width:260px;max-height:140px;width:auto;height:auto;border:1px solid var(--evapp-border);border-radius:8px;background:#fff;padding:4px;}
            .eventosapp-wa-flow-templates .evapp-status-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:0 0 14px;}
            .eventosapp-wa-flow-templates .evapp-status-box{background:#fbfbfc;border:1px solid #edf0f2;border-radius:10px;padding:10px;}
            .eventosapp-wa-flow-templates .evapp-status-box strong{display:block;margin-bottom:3px;}
            @media(max-width:1280px){
                .eventosapp-wa-flow-templates .evapp-grid{grid-template-columns:1fr;}
                .eventosapp-wa-flow-templates .evapp-template-list-wrap{max-height:560px;}
            }
            @media(max-width:782px){
                .eventosapp-wa-flow-templates .evapp-page-hero{padding:14px;}
                .eventosapp-wa-flow-templates .evapp-card-header,
                .eventosapp-wa-flow-templates .evapp-card-body{padding:14px;}
                .eventosapp-wa-flow-templates .evapp-form-table th{width:auto;padding-bottom:4px;}
                .eventosapp-wa-flow-templates .evapp-test-panel,
                .eventosapp-wa-flow-templates .evapp-status-summary{grid-template-columns:1fr;}
            }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', function(){
                const flows = <?php echo wp_json_encode($flows_ui_metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?> || {};
                const accounts = <?php echo wp_json_encode($phone_accounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?> || {};
                const defaultWaba = <?php echo wp_json_encode((string) $default_waba_id); ?>;
                const defaultPhone = <?php echo wp_json_encode((string) $default_phone_number_id); ?>;
                const flowSelect = document.getElementById('eventosapp-wa-flow-template-flow-post-id');
                const metaFlowInput = document.getElementById('eventosapp-wa-flow-template-meta-flow-id');
                const screenSelect = document.getElementById('eventosapp-wa-flow-template-navigate-screen');
                const flowDetail = document.getElementById('eventosapp-wa-flow-detail');
                const senderSelect = document.getElementById('eventosapp-wa-flow-template-sender');
                const wabaInput = document.getElementById('eventosapp-wa-flow-template-waba-id');
                const wabaHint = document.getElementById('eventosapp-wa-flow-template-waba-hint');
                const headerFormat = document.getElementById('eventosapp-wa-flow-template-header-format');
                const headerTextRow = document.querySelector('[data-header-row="text"]');
                const headerImageRows = document.querySelectorAll('[data-header-row="image"]');
                const testHeaderImageRows = document.querySelectorAll('[data-test-header-row="image"]');
                const testHeaderImageInput = document.getElementById('evapp_flow_test_header_image_url');
                const testHeaderImageButton = document.getElementById('evapp_flow_test_header_image_btn');
                const testHeaderImagePreview = document.getElementById('evapp_flow_test_header_image_preview');

                function rebuildScreens(flowId, keepCurrent) {
                    if (!screenSelect) return;
                    const meta = flows[String(flowId)] || null;
                    const current = keepCurrent ? (screenSelect.value || screenSelect.getAttribute('data-current-screen') || '') : '';
                    const screens = meta && Array.isArray(meta.screen_ids) && meta.screen_ids.length ? meta.screen_ids : [current || (meta && meta.initial_screen ? meta.initial_screen : 'SURVEY')];
                    screenSelect.innerHTML = '';
                    screens.forEach(function(screen){
                        if (!screen) return;
                        const option = document.createElement('option');
                        option.value = screen;
                        option.textContent = screen;
                        screenSelect.appendChild(option);
                    });
                    if (current && screens.indexOf(current) !== -1) {
                        screenSelect.value = current;
                    } else {
                        screenSelect.value = meta && meta.initial_screen ? meta.initial_screen : (screens[0] || 'SURVEY');
                    }
                }

                function updateFlowDetail(flowId, keepCurrent) {
                    const meta = flows[String(flowId)] || null;
                    rebuildScreens(flowId, keepCurrent);
                    if (metaFlowInput && meta && meta.meta_flow_id && (!keepCurrent || !metaFlowInput.value)) {
                        metaFlowInput.value = meta.meta_flow_id;
                    }
                    if (meta && meta.waba_id && wabaInput && !wabaInput.value) {
                        wabaInput.value = meta.waba_id;
                    }
                    if (meta && meta.sender_phone_number_id && senderSelect && !senderSelect.value && accounts[meta.sender_phone_number_id]) {
                        senderSelect.value = meta.sender_phone_number_id;
                        updateWabaHint();
                    }
                    if (flowDetail) {
                        if (!meta) {
                            flowDetail.innerHTML = '<strong>Flow seleccionado:</strong> ninguno.';
                        } else {
                            flowDetail.innerHTML = '<strong>Flow seleccionado:</strong> #' + meta.post_id + ' · ' + escapeHtml(meta.title || '') + '<br><strong>Meta Flow ID:</strong> ' + escapeHtml(meta.meta_flow_id || 'Sin crear') + '<br><strong>Estado:</strong> ' + escapeHtml(meta.status || 'Sin estado') + '<br><strong>Pantallas:</strong> ' + escapeHtml((meta.screen_ids || []).join(', ') || meta.initial_screen || 'SURVEY');
                        }
                    }
                }

                function updateWabaHint() {
                    if (!senderSelect || !wabaInput || !wabaHint) return;
                    const selected = senderSelect.value || defaultPhone || '';
                    const account = accounts[selected] || null;
                    if ((!selected || (account && account.is_default)) && !wabaInput.value && defaultWaba) {
                        wabaInput.value = defaultWaba;
                    }
                    if (!selected || (account && account.is_default)) {
                        wabaHint.textContent = 'Este es el número principal. Para aprobación se usará el WABA global de Plantillas WhatsApp si está configurado; puedes dejar este campo con ese valor.';
                    } else {
                        wabaHint.textContent = 'Este número no es el principal. Escribe aquí el WABA ID que corresponde a este número antes de enviar a Meta.';
                    }
                }

                function updateHeaderRows() {
                    if (!headerFormat) return;
                    const value = headerFormat.value || 'NONE';
                    if (headerTextRow) headerTextRow.style.display = value === 'TEXT' ? '' : 'none';
                    headerImageRows.forEach(function(row){ row.style.display = value === 'IMAGE' ? '' : 'none'; });
                    testHeaderImageRows.forEach(function(row){ row.style.display = value === 'IMAGE' ? '' : 'none'; });
                }

                function updateTestHeaderPreview(value) {
                    if (!testHeaderImagePreview) return;
                    const url = String(value || '').trim();
                    if (!url) {
                        testHeaderImagePreview.style.display = 'none';
                        testHeaderImagePreview.innerHTML = '';
                        return;
                    }
                    testHeaderImagePreview.style.display = 'block';
                    testHeaderImagePreview.innerHTML = '<img src="' + escapeHtml(url) + '" alt="Vista previa header de prueba">';
                }

                function initTestHeaderMediaPicker() {
                    if (!testHeaderImageInput || !testHeaderImageButton) return;
                    testHeaderImageButton.addEventListener('click', function(event){
                        event.preventDefault();
                        if (!window.wp || !wp.media) {
                            alert('La librería multimedia de WordPress no está disponible en esta pantalla. Puedes pegar manualmente una URL pública HTTPS.');
                            return;
                        }
                        const frame = wp.media({
                            title: 'Seleccionar header de prueba',
                            button: { text: 'Usar esta imagen' },
                            library: { type: 'image' },
                            multiple: false
                        });
                        frame.on('select', function(){
                            const attachment = frame.state().get('selection').first();
                            const data = attachment ? attachment.toJSON() : {};
                            if (data && data.url) {
                                testHeaderImageInput.value = data.url;
                                updateTestHeaderPreview(data.url);
                            }
                        });
                        frame.open();
                    });
                    testHeaderImageInput.addEventListener('input', function(){
                        updateTestHeaderPreview(this.value);
                    });
                    updateTestHeaderPreview(testHeaderImageInput.value);
                }

                function escapeHtml(value) {
                    return String(value).replace(/[&<>'"]/g, function(ch){
                        return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[ch];
                    });
                }

                if (flowSelect) {
                    flowSelect.addEventListener('change', function(){
                        if (metaFlowInput) metaFlowInput.value = '';
                        updateFlowDetail(this.value, false);
                    });
                    updateFlowDetail(flowSelect.value, true);
                }
                if (senderSelect) {
                    senderSelect.addEventListener('change', updateWabaHint);
                    updateWabaHint();
                }
                if (headerFormat) {
                    headerFormat.addEventListener('change', updateHeaderRows);
                    updateHeaderRows();
                }
                initTestHeaderMediaPicker();
            });
        </script>

        <div class="evapp-grid">
            <div class="evapp-stack">
                <div class="evapp-card">
                    <div class="evapp-card-header">
                        <h2><?php echo ! empty($edit['id']) ? 'Editar plantilla Flow' : 'Crear plantilla Flow'; ?></h2>
                        <p>Completa el contenido visible para el usuario y la conexión con el Flow que abrirá el botón.</p>
                    </div>
                    <div class="evapp-card-body">
                        <form id="eventosapp-wa-flow-template-save-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('eventosapp_whatsapp_flow_template_save'); ?>
                            <input type="hidden" name="action" value="eventosapp_whatsapp_flow_template_save">
                            <input type="hidden" name="template_id" value="<?php echo esc_attr($edit['id']); ?>">

                            <table class="form-table evapp-form-table" role="presentation">
                                <tr class="evapp-section-row">
                                    <th colspan="2">
                                        <div class="evapp-section-title"><span>1</span> Identificación de la plantilla</div>
                                    </th>
                                </tr>
                                <tr>
                                    <th><label for="evapp_flow_display_name">Nombre interno</label></th>
                                    <td>
                                        <input id="evapp_flow_display_name" type="text" class="regular-text" name="display_name" value="<?php echo esc_attr($edit['display_name']); ?>" placeholder="Encuesta post evento">
                                        <p class="evapp-help">Solo lo verás dentro de EventosApp. Úsalo para reconocer la plantilla rápidamente.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="evapp_flow_template_name">Nombre Meta</label></th>
                                    <td>
                                        <input id="evapp_flow_template_name" type="text" class="regular-text" name="template_name" value="<?php echo esc_attr($edit['name']); ?>" placeholder="eventosapp_flow_encuesta_post_evento" required>
                                        <p class="evapp-help">Usa minúsculas, números y guion bajo. EventosApp lo normaliza automáticamente.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Idioma / categoría</th>
                                    <td>
                                        <div class="evapp-inline-fields">
                                            <label>Idioma<br><input type="text" name="language" value="<?php echo esc_attr($edit['language']); ?>" class="small-text"></label>
                                            <label>Categoría<br><select name="category"><?php foreach ( eventosapp_whatsapp_flow_templates_supported_categories() as $category_key => $category_label ) : ?><option value="<?php echo esc_attr($category_key); ?>" <?php selected(eventosapp_whatsapp_flow_templates_sanitize_category($edit['category']), $category_key); ?>><?php echo esc_html($category_label); ?></option><?php endforeach; ?></select></label>
                                        </div>
                                        <div class="<?php echo $category_mismatch ? 'evapp-warning' : 'evapp-info'; ?>"><?php echo esc_html($category_message); ?></div>
                                        <p class="evapp-help">Utility sirve para confirmaciones o seguimientos operativos. Marketing sirve para invitaciones, encuestas promocionales o mensajes comerciales. Authentication queda disponible para control administrativo, pero las plantillas Flow normalmente se aprueban como Utility o Marketing según el contenido.</p>
                                    </td>
                                </tr>

                                <tr class="evapp-section-row">
                                    <th colspan="2">
                                        <div class="evapp-section-title"><span>2</span> Encabezado y mensaje</div>
                                    </th>
                                </tr>
                                <tr>
                                    <th><label for="eventosapp-wa-flow-template-header-format">Encabezado</label></th>
                                    <td>
                                        <select id="eventosapp-wa-flow-template-header-format" name="header_format">
                                            <option value="NONE" <?php selected($edit['header_format'], 'NONE'); ?>>Sin encabezado</option>
                                            <option value="TEXT" <?php selected($edit['header_format'], 'TEXT'); ?>>Texto</option>
                                            <option value="IMAGE" <?php selected($edit['header_format'], 'IMAGE'); ?>>Imagen</option>
                                        </select>
                                        <p class="evapp-help">WhatsApp permite encabezado de texto o imagen en plantillas. Para imagen se necesita un Header Sample Handle para aprobación; el header de prueba se selecciona en el bloque Prueba rápida de plantilla.</p>
                                    </td>
                                </tr>
                                <tr data-header-row="text">
                                    <th><label for="evapp_flow_header_text">Texto de encabezado</label></th>
                                    <td>
                                        <input id="evapp_flow_header_text" type="text" class="regular-text" name="header_text" value="<?php echo esc_attr($edit['header_text']); ?>" maxlength="60">
                                        <p class="evapp-help">Solo aplica si el encabezado es Texto.</p>
                                    </td>
                                </tr>
                                <tr data-header-row="image">
                                    <th><label for="evapp_flow_header_handle">Header Sample Handle</label></th>
                                    <td>
                                        <input id="evapp_flow_header_handle" type="text" class="regular-text" name="header_sample_handle" value="<?php echo esc_attr($edit['header_sample_handle']); ?>" placeholder="Se genera al subir una imagen de muestra a Meta">
                                        <p class="evapp-help">No pegues una URL pública aquí. Debe ser el handle que devuelve Meta al subir la muestra con Resumable Upload API.</p>
                                        <?php if ( ! empty($edit['header_sample_handle']) ) : ?>
                                            <div class="evapp-file-meta"><strong>Handle guardado:</strong> <code><?php echo esc_html($edit['header_sample_handle']); ?></code><?php if ( ! empty($edit['header_sample_uploaded_at']) ) : ?><br><small>Última muestra subida: <?php echo esc_html($edit['header_sample_uploaded_at']); ?></small><?php endif; ?><?php if ( ! empty($edit['header_sample_file_name']) ) : ?><br><small>Archivo: <?php echo esc_html($edit['header_sample_file_name']); ?> · <?php echo esc_html($edit['header_sample_file_type']); ?> · <?php echo esc_html(size_format(absint($edit['header_sample_file_size']))); ?></small><?php endif; ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr data-header-row="image">
                                    <th><label for="evapp_flow_header_file">Imagen de muestra para Meta</label></th>
                                    <td>
                                        <input id="evapp_flow_header_file" type="file" name="flow_header_sample_file" accept="image/png,image/jpeg">
                                        <div class="evapp-info"><strong>Qué subir:</strong> una imagen JPG/JPEG o PNG de ejemplo, máximo 5 MB. EventosApp reutiliza el sistema de subida de Plantillas WhatsApp para generar el Header Sample Handle.</div>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="evapp_flow_body">Mensaje</label></th>
                                    <td>
                                        <textarea id="evapp_flow_body" class="large-text" rows="6" name="body"><?php echo esc_textarea($edit['body']); ?></textarea>
                                        <p class="evapp-help">Puedes usar {{1}} para nombre y {{2}} para nombre del evento. Los valores de prueba se definen en el bloque Prueba rápida de plantilla.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="evapp_flow_footer">Pie de plantilla</label></th>
                                    <td>
                                        <input id="evapp_flow_footer" type="text" class="regular-text" name="footer_text" value="<?php echo esc_attr($edit['footer_text']); ?>" maxlength="60">
                                        <p class="evapp-help">Opcional. Úsalo para marca o aviso corto.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="evapp_flow_button_text">Botón Flow</label></th>
                                    <td>
                                        <input id="evapp_flow_button_text" type="text" name="button_text" value="<?php echo esc_attr($edit['button_text']); ?>" maxlength="30" class="regular-text">
                                        <p class="evapp-help">Este texto aparece en el botón que abre el Flow.</p>
                                    </td>
                                </tr>

                                <tr class="evapp-section-row">
                                    <th colspan="2">
                                        <div class="evapp-section-title"><span>3</span> Flow y cuenta Meta</div>
                                    </th>
                                </tr>
                                <tr>
                                    <th>Flow asociado</th>
                                    <td>
                                        <select name="flow_post_id" id="eventosapp-wa-flow-template-flow-post-id" class="regular-text">
                                            <option value="0">Seleccionar Flow local / Flow ID Meta disponible</option>
                                            <?php foreach ( $flows_ui_metadata as $flow_id => $flow_meta ) : ?>
                                                <?php $flow_label = '#' . $flow_id . ' · ' . ($flow_meta['title'] ?: 'Flow sin título') . ' · ' . (! empty($flow_meta['meta_flow_id']) ? 'Meta Flow ID ' . $flow_meta['meta_flow_id'] : 'sin Meta Flow ID') . ' · ' . (! empty($flow_meta['status']) ? $flow_meta['status'] : 'sin estado'); ?>
                                                <option value="<?php echo esc_attr($flow_id); ?>" <?php selected(absint($edit['flow_post_id']), absint($flow_id)); ?>><?php echo esc_html($flow_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div id="eventosapp-wa-flow-detail" class="evapp-flow-detail"></div>
                                        <div class="evapp-inline-fields" style="margin-top:10px;">
                                            <label>Flow ID Meta<br><input type="text" class="regular-text" name="meta_flow_id" id="eventosapp-wa-flow-template-meta-flow-id" value="<?php echo esc_attr($edit['meta_flow_id']); ?>" placeholder="Se llena al escoger un Flow local con Meta ID"></label>
                                            <label>Pantalla inicial<br>
                                                <select class="regular-text" name="navigate_screen" id="eventosapp-wa-flow-template-navigate-screen" data-current-screen="<?php echo esc_attr($edit['navigate_screen']); ?>">
                                                    <?php if ( ! empty($edit_screen_ids) ) : ?>
                                                        <?php foreach ( $edit_screen_ids as $screen_id ) : ?>
                                                            <option value="<?php echo esc_attr($screen_id); ?>" <?php selected($edit['navigate_screen'], $screen_id); ?>><?php echo esc_html($screen_id); ?></option>
                                                        <?php endforeach; ?>
                                                    <?php else : ?>
                                                        <option value="<?php echo esc_attr($edit['navigate_screen'] ?: 'SURVEY'); ?>"><?php echo esc_html($edit['navigate_screen'] ?: 'SURVEY'); ?></option>
                                                    <?php endif; ?>
                                                </select>
                                            </label>
                                        </div>
                                        <p class="evapp-help">La lista muestra el ID interno, nombre, Meta Flow ID y estado. Al cambiar de Flow, se actualiza el Flow ID Meta y la pantalla inicial disponible.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Cuenta Meta</th>
                                    <td>
                                        <div class="evapp-inline-fields">
                                            <label>Número emisor<br><select id="eventosapp-wa-flow-template-sender" name="sender_phone_number_id" class="regular-text"><option value="">Usar número por defecto</option><?php foreach ( $phone_accounts as $account ) : ?><option value="<?php echo esc_attr($account['phone_number_id']); ?>" <?php selected($edit['sender_phone_number_id'] ?: $default_phone_number_id, $account['phone_number_id']); ?>><?php echo esc_html($account['label']); ?></option><?php endforeach; ?></select></label>
                                            <label>WABA ID de aprobación<br><input type="text" class="regular-text" name="waba_id" id="eventosapp-wa-flow-template-waba-id" value="<?php echo esc_attr($edit['waba_id'] ?: $effective_waba_id); ?>" placeholder="WhatsApp Business Account ID"></label>
                                        </div>
                                        <p class="evapp-help" id="eventosapp-wa-flow-template-waba-hint"></p>
                                        <p class="evapp-help"><strong>Cuenta efectiva:</strong> <?php echo esc_html($sender_account['label'] ?? 'Número por defecto'); ?> · <strong>WABA efectivo:</strong> <?php echo esc_html($effective_waba_id ?: 'Sin WABA configurado'); ?></p>
                                    </td>
                                </tr>
                            </table>
                            <p class="submit evapp-actions">
                                <button type="submit" name="save_mode" value="save" class="button button-primary">Guardar plantilla Flow</button>
                                <?php if ( ! empty($edit['id']) ) : ?><button type="submit" name="save_mode" value="save_as_new" class="button">Guardar como nueva plantilla</button><?php endif; ?>
                            </p>
                        </form>
                    </div>
                </div>

                <div class="evapp-card">
                    <div class="evapp-card-header">
                        <h2>Payload para Meta</h2>
                        <p>Vista técnica del payload que se enviará a Meta según la configuración actual guardada o cargada en el editor.</p>
                    </div>
                    <div class="evapp-card-body">
                        <textarea class="code" readonly><?php echo esc_textarea(wp_json_encode(eventosapp_whatsapp_flow_templates_build_meta_payload($edit), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="evapp-stack">
                <div class="evapp-card evapp-card--list">
                    <div class="evapp-card-header">
                        <h2>Plantillas creadas</h2>
                        <p>Lista ampliada con scroll para trabajar cómodo cuando existan muchas plantillas.</p>
                    </div>
                    <div class="evapp-card-body">
                        <div class="evapp-list-toolbar">
                            <p class="evapp-muted"><strong><?php echo esc_html(number_format_i18n(count($items))); ?></strong> plantilla(s) locales registradas.</p>
                            <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flow_templates&template_id=0')); ?>">Crear nueva plantilla</a>
                        </div>
                        <?php if ( empty($items) ) : ?>
                            <div class="evapp-empty-state">No hay plantillas Flow todavía. Crea la primera plantilla para asociarla a un WhatsApp Flow.</div>
                        <?php else : ?>
                            <div class="evapp-template-list-wrap" aria-label="Listado de plantillas Flow creadas">
                                <table class="widefat striped evapp-template-list">
                                    <thead>
                                        <tr>
                                            <th>Plantilla</th>
                                            <th>Cuenta / Flow</th>
                                            <th>Estado</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $items as $item ) : $item = eventosapp_whatsapp_flow_templates_prepare_template_for_meta(wp_parse_args($item, eventosapp_whatsapp_flow_templates_default_item())); $item_waba = eventosapp_whatsapp_flow_templates_get_template_waba_id($item, $settings); $item_sender = eventosapp_whatsapp_flow_templates_resolve_sender_account($item['sender_phone_number_id'] ?? '', $settings); ?>
                                            <tr class="<?php echo (! empty($edit['id']) && $item['id'] === $edit['id']) ? 'is-selected' : ''; ?>">
                                                <td>
                                                    <div class="evapp-template-name"><?php echo esc_html($item['display_name'] ?: $item['name']); ?></div>
                                                    <small><?php echo esc_html($item['name']); ?></small><br>
                                                    <small><?php echo esc_html($item['language']); ?> · <?php echo esc_html(eventosapp_whatsapp_flow_templates_category_label($item['category'])); ?><?php if ( ! empty($item['meta_category']) ) : ?> · Meta: <?php echo esc_html(eventosapp_whatsapp_flow_templates_category_label($item['meta_category'])); ?><?php endif; ?></small>
                                                </td>
                                                <td>
                                                    <small><?php echo esc_html($item_sender['label'] ?? 'Número por defecto'); ?></small><br>
                                                    <small>WABA: <?php echo esc_html($item_waba ?: '—'); ?></small><br>
                                                    <small>Flow: <?php echo esc_html($item['meta_flow_id'] ?: '—'); ?></small>
                                                </td>
                                                <td><span class="evapp-pill"><?php echo esc_html($item['meta_status']); ?></span></td>
                                                <td>
                                                    <div class="evapp-template-actions">
                                                        <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'eventosapp_whatsapp_flow_templates', 'template_id' => $item['id']], admin_url('admin.php'))); ?>">Abrir</a>
                                                        <?php eventosapp_whatsapp_flow_templates_post_button('eventosapp_whatsapp_flow_template_duplicate', 'eventosapp_whatsapp_flow_template_duplicate', 'Duplicar', $item['id'], 'button-small'); ?>
                                                        <?php eventosapp_whatsapp_flow_templates_post_button('eventosapp_whatsapp_flow_template_delete', 'eventosapp_whatsapp_flow_template_delete', 'Eliminar', $item['id'], 'button-small evapp-danger-link', 'return confirm(\'¿Eliminar esta plantilla local? Esta acción no elimina la plantilla aprobada en Meta.\');'); ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ( ! empty($edit['id']) ) : ?>
                    <div class="evapp-card">
                        <div class="evapp-card-header">
                            <h2>Aprobación y estado Meta</h2>
                            <p>Envía esta plantilla a aprobación o consulta su estado actual sin salir de la pantalla.</p>
                        </div>
                        <div class="evapp-card-body">
                            <div class="evapp-status-summary">
                                <div class="evapp-status-box"><strong>Estado Meta</strong><span class="evapp-pill"><?php echo esc_html($edit['meta_status']); ?></span></div>
                                <div class="evapp-status-box"><strong>Categoría solicitada</strong><?php echo esc_html(eventosapp_whatsapp_flow_templates_category_label($edit['category'])); ?><?php if ( ! empty($edit['meta_category']) ) : ?><br><small>Meta: <?php echo esc_html(eventosapp_whatsapp_flow_templates_category_label($edit['meta_category'])); ?></small><?php endif; ?></div>
                            </div>
                            <div class="evapp-actions">
                                <?php eventosapp_whatsapp_flow_templates_post_button('eventosapp_whatsapp_flow_template_submit_meta', 'eventosapp_whatsapp_flow_template_submit_meta', 'Enviar a aprobación', $edit['id'], 'button-primary'); ?>
                                <?php eventosapp_whatsapp_flow_templates_post_button('eventosapp_whatsapp_flow_template_sync_status', 'eventosapp_whatsapp_flow_template_sync_status', 'Consultar estado', $edit['id']); ?>
                            </div>
                            <details style="margin-top:12px;"><summary>Última respuesta técnica</summary><?php eventosapp_whatsapp_flow_templates_render_debug($edit['last_meta_response']); ?></details>
                        </div>
                    </div>

                    <div class="evapp-card">
                        <div class="evapp-card-header">
                            <h2>Prueba rápida de plantilla</h2>
                            <p>Personaliza los valores de prueba, agrega teléfono destino, opcionalmente un Ticket ID y envía desde el mismo bloque.</p>
                        </div>
                        <div class="evapp-card-body">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('eventosapp_whatsapp_flow_template_test_send'); ?>
                                <input type="hidden" name="action" value="eventosapp_whatsapp_flow_template_test_send">
                                <input type="hidden" name="template_id" value="<?php echo esc_attr($edit['id']); ?>">
                                <div class="evapp-test-panel">
                                    <label>Ejemplo {{1}} para prueba<br><input type="text" name="test_sample_1" value="<?php echo esc_attr($edit['sample_1']); ?>" placeholder="Nombre de prueba"></label>
                                    <label>Ejemplo {{2}} para prueba<br><input type="text" name="test_sample_2" value="<?php echo esc_attr($edit['sample_2']); ?>" placeholder="Evento de prueba"></label>
                                    <div class="evapp-test-image-field" data-test-header-row="image" style="<?php echo $edit['header_format'] === 'IMAGE' ? '' : 'display:none;'; ?>">
                                        <label for="evapp_flow_test_header_image_url">Header de prueba (imagen)</label>
                                        <div class="evapp-media-field">
                                            <input id="evapp_flow_test_header_image_url" type="url" name="test_header_image_url" value="<?php echo esc_attr($edit['header_image_url']); ?>" placeholder="Selecciona una imagen de la librería o pega una URL HTTPS pública">
                                            <button type="button" class="button" id="evapp_flow_test_header_image_btn">Usar imagen de la librería</button>
                                        </div>
                                        <div class="evapp-media-preview" id="evapp_flow_test_header_image_preview"></div>
                                        <p class="evapp-help">Se usa solo para esta prueba rápida cuando la plantilla guardada tiene encabezado de imagen. No reemplaza el Header Sample Handle usado para aprobación en Meta.</p>
                                    </div>
                                    <label>Teléfono destino<br><input type="text" name="test_phone" placeholder="573001112233" required></label>
                                    <label>Ticket ID opcional<br><input type="number" name="test_ticket_id" min="0" placeholder="0"></label>
                                    <div class="evapp-test-actions">
                                        <button type="submit" class="button button-primary">Enviar prueba</button>
                                        <span class="evapp-help">Si escribes un Ticket ID válido, la prueba usará los datos reales del ticket. Sin Ticket ID, usará los ejemplos escritos aquí.</span>
                                    </div>
                                </div>
                                <p class="evapp-help">Este método usa una plantilla aprobada con botón Flow. Si la plantilla tiene encabezado de imagen, selecciona el Header de prueba desde la librería multimedia o pega una URL pública HTTPS.</p>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
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

function eventosapp_whatsapp_flow_templates_post_button($action, $nonce_action, $label, $template_id, $class = '', $onsubmit = '') {
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:0;" <?php echo $onsubmit !== '' ? 'onsubmit="' . esc_attr($onsubmit) . '"' : ''; ?>>
        <?php wp_nonce_field($nonce_action); ?>
        <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
        <input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>">
        <button type="submit" class="button <?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button>
    </form>
    <?php
}
