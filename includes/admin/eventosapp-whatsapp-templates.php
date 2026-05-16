<?php
/**
 * EventosApp - Plantillas WhatsApp para Meta
 *
 * Administra plantillas de WhatsApp Business Platform desde EventosApp.
 * Este archivo no reemplaza el envío actual de WhatsApp Tickets: agrega un
 * módulo administrativo independiente para crear, editar, enviar a Meta,
 * consultar estado y reutilizar plantillas prediseñadas por modalidad.
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! defined('EVENTOSAPP_WHATSAPP_TEMPLATES_OPTION') ) {
    define('EVENTOSAPP_WHATSAPP_TEMPLATES_OPTION', 'eventosapp_whatsapp_templates_settings');
}

/**
 * Registra el submenú debajo de WhatsApp Tickets.
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'eventosapp_dashboard',
        'Plantillas WhatsApp',
        'Plantillas WhatsApp',
        'manage_options',
        'eventosapp_whatsapp_templates',
        'eventosapp_whatsapp_templates_render_page'
    );
}, 21);

/**
 * URL base segura para botones de plantilla.
 */
function eventosapp_whatsapp_templates_button_url($action) {
    $action = sanitize_key((string) $action);

    $map = [
        'ticket_landing' => 'eventosapp_whatsapp_ticket_landing',
        'ticket_ics'     => 'eventosapp_whatsapp_ticket_ics',
        'virtual_access' => 'eventosapp_whatsapp_virtual_access',
    ];

    if ( ! isset($map[$action]) ) {
        $action = 'ticket_landing';
    }

    $url = add_query_arg([
        'action' => $map[$action],
        'ticket' => '{{1}}',
    ], admin_url('admin-post.php'));

    return str_replace(['%7B%7B1%7D%7D', '%7b%7b1%7d%7d'], '{{1}}', $url);
}

/**
 * Ejemplo completo de URL para aprobación de Meta.
 */
function eventosapp_whatsapp_templates_button_example_url($action) {
    return str_replace('{{1}}', 'ticket_demo_123', eventosapp_whatsapp_templates_button_url($action));
}

/**
 * Plantillas base recomendadas para EventosApp.
 */
function eventosapp_whatsapp_templates_default_records() {
    $now = current_time('mysql');

    return [
        'default_presencial' => [
            'id'                    => 'default_presencial',
            'is_default'            => '1',
            'base_key'              => 'presencial',
            'name'                  => 'eventosapp_ticket_presencial_v1',
            'language'              => 'es',
            'category'              => 'UTILITY',
            'modality'              => 'presencial',
            'title'                 => 'Ticket presencial con QR',
            'header_format'         => 'IMAGE',
            'header_text'           => '',
            'header_sample_handle'  => '',
            'body_text'             => "Hola {{1}}, tu inscripción para {{2}} está confirmada.\n\nFecha: {{3}}\nHora: {{4}}\nLugar: {{5}}\nModalidad: Presencial\n\nEl QR de ingreso se muestra en este mensaje. Para ver Wallet, PDF, calendario y más detalles, usa el botón Ver mi ticket.",
            'body_examples'         => "María Pérez\nEvento Demo\n20 de mayo de 2026\n8:00 a. m.\nCentro de Convenciones",
            'footer_text'           => 'EventosApp',
            'button_1_text'         => 'Ver mi ticket',
            'button_1_url'          => eventosapp_whatsapp_templates_button_url('ticket_landing'),
            'button_1_example'      => eventosapp_whatsapp_templates_button_example_url('ticket_landing'),
            'button_2_text'         => 'Agregar a agenda',
            'button_2_url'          => eventosapp_whatsapp_templates_button_url('ticket_ics'),
            'button_2_example'      => eventosapp_whatsapp_templates_button_example_url('ticket_ics'),
            'meta_template_id'      => '',
            'meta_status'           => 'LOCAL',
            'meta_category'         => '',
            'meta_rejected_reason'  => '',
            'last_api_message'      => '',
            'last_api_response'     => [],
            'last_submitted_at'     => '',
            'last_checked_at'       => '',
            'created_at'            => $now,
            'updated_at'            => $now,
        ],
        'default_virtual' => [
            'id'                    => 'default_virtual',
            'is_default'            => '1',
            'base_key'              => 'virtual',
            'name'                  => 'eventosapp_ticket_virtual_v1',
            'language'              => 'es',
            'category'              => 'UTILITY',
            'modality'              => 'virtual',
            'title'                 => 'Ticket virtual con acceso',
            'header_format'         => 'NONE',
            'header_text'           => '',
            'header_sample_handle'  => '',
            'body_text'             => "Hola {{1}}, tu inscripción para {{2}} está confirmada.\n\nFecha: {{3}}\nHora: {{4}}\nPlataforma: {{5}}\nModalidad: Virtual\n\nUsa el botón Ingresar al evento para acceder a la sesión virtual. El enlace estará disponible según la configuración del evento.",
            'body_examples'         => "María Pérez\nEvento Demo Virtual\n20 de mayo de 2026\n8:00 a. m.\nZoom",
            'footer_text'           => 'EventosApp',
            'button_1_text'         => 'Ingresar al evento',
            'button_1_url'          => eventosapp_whatsapp_templates_button_url('virtual_access'),
            'button_1_example'      => eventosapp_whatsapp_templates_button_example_url('virtual_access'),
            'button_2_text'         => 'Agregar a agenda',
            'button_2_url'          => eventosapp_whatsapp_templates_button_url('ticket_ics'),
            'button_2_example'      => eventosapp_whatsapp_templates_button_example_url('ticket_ics'),
            'meta_template_id'      => '',
            'meta_status'           => 'LOCAL',
            'meta_category'         => '',
            'meta_rejected_reason'  => '',
            'last_api_message'      => '',
            'last_api_response'     => [],
            'last_submitted_at'     => '',
            'last_checked_at'       => '',
            'created_at'            => $now,
            'updated_at'            => $now,
        ],
    ];
}

/**
 * Configuración base del módulo de plantillas.
 */
function eventosapp_whatsapp_templates_default_settings() {
    return [
        'waba_id'      => '',
        'templates'    => eventosapp_whatsapp_templates_default_records(),
        'last_sync_at' => '',
        'last_message' => '',
    ];
}

/**
 * Obtiene configuración del módulo y garantiza plantillas por defecto.
 */
function eventosapp_whatsapp_templates_get_settings() {
    $saved = get_option(EVENTOSAPP_WHATSAPP_TEMPLATES_OPTION, []);
    if ( ! is_array($saved) ) {
        $saved = [];
    }

    $defaults = eventosapp_whatsapp_templates_default_settings();
    $settings = wp_parse_args($saved, $defaults);

    if ( empty($settings['templates']) || ! is_array($settings['templates']) ) {
        $settings['templates'] = [];
    }

    $changed = false;
    foreach ( eventosapp_whatsapp_templates_default_records() as $default_id => $default_template ) {
        if ( empty($settings['templates'][$default_id]) || ! is_array($settings['templates'][$default_id]) ) {
            $settings['templates'][$default_id] = $default_template;
            $changed = true;
        } else {
            $settings['templates'][$default_id] = wp_parse_args($settings['templates'][$default_id], $default_template);
        }
    }

    if ( $changed ) {
        update_option(EVENTOSAPP_WHATSAPP_TEMPLATES_OPTION, $settings, false);
    }

    return $settings;
}

/**
 * Guarda configuración completa del módulo.
 */
function eventosapp_whatsapp_templates_update_settings($settings) {
    if ( ! is_array($settings) ) {
        $settings = eventosapp_whatsapp_templates_default_settings();
    }
    update_option(EVENTOSAPP_WHATSAPP_TEMPLATES_OPTION, $settings, false);
}

/**
 * Obtiene una plantilla por ID local.
 */
function eventosapp_whatsapp_templates_get_template($template_id) {
    $template_id = sanitize_key((string) $template_id);
    $settings = eventosapp_whatsapp_templates_get_settings();
    return isset($settings['templates'][$template_id]) && is_array($settings['templates'][$template_id]) ? $settings['templates'][$template_id] : null;
}

/**
 * Sanitiza nombres de plantilla aceptados por Meta.
 */
function eventosapp_whatsapp_templates_sanitize_template_name($name) {
    $name = strtolower((string) $name);
    $name = preg_replace('/[^a-z0-9_]+/', '_', $name);
    $name = trim($name, '_');
    return $name;
}

/**
 * Sanitiza URL de botón conservando {{1}} para URL dinámica.
 */
function eventosapp_whatsapp_templates_sanitize_url_template($url) {
    $url = trim((string) $url);
    if ( $url === '' ) {
        return '';
    }

    $placeholder = '__EVENTOSAPP_WA_VAR_1__';
    $url = str_replace('{{1}}', $placeholder, $url);
    $url = esc_url_raw($url);
    $url = str_replace($placeholder, '{{1}}', $url);

    return $url;
}

/**
 * Normaliza ejemplos del body a una fila compatible con Meta.
 */
function eventosapp_whatsapp_templates_body_examples_to_array($body_text, $examples_text) {
    $max_var = 0;
    if ( preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $body_text, $matches) ) {
        foreach ( $matches[1] as $number ) {
            $max_var = max($max_var, absint($number));
        }
    }

    if ( $max_var < 1 ) {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', (string) $examples_text);
    $examples = [];
    foreach ( $lines as $line ) {
        $line = sanitize_text_field($line);
        if ( $line !== '' ) {
            $examples[] = $line;
        }
    }

    $fallback = [
        'María Pérez',
        'Evento Demo',
        '20 de mayo de 2026',
        '8:00 a. m.',
        'Centro de Convenciones',
        'https://example.com/demo',
    ];

    for ( $i = 0; $i < $max_var; $i++ ) {
        if ( ! isset($examples[$i]) || $examples[$i] === '' ) {
            $examples[$i] = $fallback[$i] ?? ('Ejemplo ' . ($i + 1));
        }
    }

    return array_slice($examples, 0, $max_var);
}

/**
 * Normaliza plantilla desde POST o array.
 */
function eventosapp_whatsapp_templates_normalize_template($raw, $existing = []) {
    $raw = is_array($raw) ? $raw : [];
    $existing = is_array($existing) ? $existing : [];

    $id = ! empty($existing['id']) ? sanitize_key($existing['id']) : (! empty($raw['id']) ? sanitize_key($raw['id']) : 'tpl_' . wp_generate_uuid4());
    if ( $id === '' ) {
        $id = 'tpl_' . wp_generate_uuid4();
    }

    $category = ! empty($raw['category']) ? strtoupper(sanitize_key($raw['category'])) : ($existing['category'] ?? 'UTILITY');
    if ( $category !== 'UTILITY' ) {
        $category = 'UTILITY';
    }

    $language = ! empty($raw['language']) ? sanitize_text_field($raw['language']) : ($existing['language'] ?? 'es');
    $language = preg_replace('/[^a-zA-Z_\-]+/', '', $language);
    if ( $language === '' ) {
        $language = 'es';
    }

    $header_format = ! empty($raw['header_format']) ? strtoupper(sanitize_key($raw['header_format'])) : ($existing['header_format'] ?? 'NONE');
    if ( ! in_array($header_format, ['NONE', 'TEXT', 'IMAGE'], true) ) {
        $header_format = 'NONE';
    }

    $modality = ! empty($raw['modality']) ? sanitize_key($raw['modality']) : ($existing['modality'] ?? 'custom');
    if ( ! in_array($modality, ['presencial', 'virtual', 'custom'], true) ) {
        $modality = 'custom';
    }

    $template = wp_parse_args([
        'id'                   => $id,
        'is_default'           => ! empty($existing['is_default']) && $existing['is_default'] === '1' ? '1' : '0',
        'base_key'             => ! empty($existing['base_key']) ? sanitize_key($existing['base_key']) : $modality,
        'name'                 => eventosapp_whatsapp_templates_sanitize_template_name($raw['name'] ?? ($existing['name'] ?? '')),
        'language'             => $language,
        'category'             => $category,
        'modality'             => $modality,
        'title'                => sanitize_text_field($raw['title'] ?? ($existing['title'] ?? '')),
        'header_format'        => $header_format,
        'header_text'          => sanitize_text_field($raw['header_text'] ?? ($existing['header_text'] ?? '')),
        'header_sample_handle' => sanitize_text_field($raw['header_sample_handle'] ?? ($existing['header_sample_handle'] ?? '')),
        'body_text'            => sanitize_textarea_field($raw['body_text'] ?? ($existing['body_text'] ?? '')),
        'body_examples'        => sanitize_textarea_field($raw['body_examples'] ?? ($existing['body_examples'] ?? '')),
        'footer_text'          => sanitize_text_field($raw['footer_text'] ?? ($existing['footer_text'] ?? '')),
        'button_1_text'        => sanitize_text_field($raw['button_1_text'] ?? ($existing['button_1_text'] ?? '')),
        'button_1_url'         => eventosapp_whatsapp_templates_sanitize_url_template($raw['button_1_url'] ?? ($existing['button_1_url'] ?? '')),
        'button_1_example'     => esc_url_raw($raw['button_1_example'] ?? ($existing['button_1_example'] ?? '')),
        'button_2_text'        => sanitize_text_field($raw['button_2_text'] ?? ($existing['button_2_text'] ?? '')),
        'button_2_url'         => eventosapp_whatsapp_templates_sanitize_url_template($raw['button_2_url'] ?? ($existing['button_2_url'] ?? '')),
        'button_2_example'     => esc_url_raw($raw['button_2_example'] ?? ($existing['button_2_example'] ?? '')),
        'meta_template_id'     => sanitize_text_field($existing['meta_template_id'] ?? ''),
        'meta_status'          => sanitize_text_field($existing['meta_status'] ?? 'LOCAL'),
        'meta_category'        => sanitize_text_field($existing['meta_category'] ?? ''),
        'meta_rejected_reason' => sanitize_text_field($existing['meta_rejected_reason'] ?? ''),
        'last_api_message'     => sanitize_text_field($existing['last_api_message'] ?? ''),
        'last_api_response'    => isset($existing['last_api_response']) && is_array($existing['last_api_response']) ? $existing['last_api_response'] : [],
        'last_submitted_at'    => sanitize_text_field($existing['last_submitted_at'] ?? ''),
        'last_checked_at'      => sanitize_text_field($existing['last_checked_at'] ?? ''),
        'created_at'           => sanitize_text_field($existing['created_at'] ?? current_time('mysql')),
        'updated_at'           => current_time('mysql'),
    ], []);

    if ( $template['name'] === '' ) {
        $template['name'] = 'eventosapp_template_' . substr(md5($id), 0, 8);
    }

    if ( $template['title'] === '' ) {
        $template['title'] = $template['name'];
    }

    if ( strpos($template['button_1_url'], '{{1}}') !== false && $template['button_1_example'] === '' ) {
        $template['button_1_example'] = esc_url_raw(str_replace('{{1}}', 'ticket_demo_123', $template['button_1_url']));
    }
    if ( strpos($template['button_2_url'], '{{1}}') !== false && $template['button_2_example'] === '' ) {
        $template['button_2_example'] = esc_url_raw(str_replace('{{1}}', 'ticket_demo_123', $template['button_2_url']));
    }

    return $template;
}

/**
 * Valida plantilla antes de enviarla a Meta.
 */
function eventosapp_whatsapp_templates_validate_for_meta($template) {
    $errors = [];

    if ( empty($template['name']) ) {
        $errors[] = 'Falta el nombre técnico de la plantilla.';
    }

    if ( empty($template['body_text']) ) {
        $errors[] = 'Falta el cuerpo de la plantilla.';
    }

    if ( ! empty($template['header_format']) && $template['header_format'] === 'IMAGE' && empty($template['header_sample_handle']) ) {
        $errors[] = 'La plantilla usa encabezado de imagen. Para enviarla a Meta debes pegar un Header Sample Handle válido.';
    }

    $buttons = 0;
    foreach ( [1, 2] as $i ) {
        $text = trim((string)($template['button_' . $i . '_text'] ?? ''));
        $url  = trim((string)($template['button_' . $i . '_url'] ?? ''));
        if ( $text !== '' || $url !== '' ) {
            if ( $text === '' || $url === '' ) {
                $errors[] = 'Cada botón debe tener texto y URL.';
            }
            if ( substr_count($url, '{{1}}') > 1 ) {
                $errors[] = 'Cada botón URL solo puede usar una variable dinámica {{1}}.';
            }
            if ( strpos($url, '{{1}}') !== false && empty($template['button_' . $i . '_example']) ) {
                $errors[] = 'Cada botón con URL dinámica debe tener una URL de ejemplo completa.';
            }
            $buttons++;
        }
    }

    if ( $buttons > 2 ) {
        $errors[] = 'WhatsApp solo permite hasta 2 botones URL en esta estructura.';
    }

    return $errors;
}

/**
 * Construye componentes para crear/actualizar plantilla en Meta.
 */
function eventosapp_whatsapp_templates_build_meta_components($template) {
    $components = [];

    if ( ! empty($template['header_format']) && $template['header_format'] === 'IMAGE' ) {
        $components[] = [
            'type'    => 'HEADER',
            'format'  => 'IMAGE',
            'example' => [
                'header_handle' => [ $template['header_sample_handle'] ],
            ],
        ];
    } elseif ( ! empty($template['header_format']) && $template['header_format'] === 'TEXT' && ! empty($template['header_text']) ) {
        $components[] = [
            'type'   => 'HEADER',
            'format' => 'TEXT',
            'text'   => $template['header_text'],
        ];
    }

    $body_component = [
        'type' => 'BODY',
        'text' => $template['body_text'],
    ];

    $body_examples = eventosapp_whatsapp_templates_body_examples_to_array($template['body_text'], $template['body_examples']);
    if ( ! empty($body_examples) ) {
        $body_component['example'] = [
            'body_text' => [ $body_examples ],
        ];
    }
    $components[] = $body_component;

    if ( ! empty($template['footer_text']) ) {
        $components[] = [
            'type' => 'FOOTER',
            'text' => $template['footer_text'],
        ];
    }

    $buttons = [];
    foreach ( [1, 2] as $i ) {
        $text = trim((string)($template['button_' . $i . '_text'] ?? ''));
        $url  = trim((string)($template['button_' . $i . '_url'] ?? ''));
        if ( $text === '' || $url === '' ) {
            continue;
        }

        $button = [
            'type' => 'URL',
            'text' => $text,
            'url'  => $url,
        ];

        if ( strpos($url, '{{1}}') !== false && ! empty($template['button_' . $i . '_example']) ) {
            $button['example'] = [ $template['button_' . $i . '_example'] ];
        }

        $buttons[] = $button;
    }

    if ( ! empty($buttons) ) {
        $components[] = [
            'type'    => 'BUTTONS',
            'buttons' => array_slice($buttons, 0, 2),
        ];
    }

    return $components;
}

/**
 * Petición común a Meta Graph API para plantillas.
 */
function eventosapp_whatsapp_templates_api_request($method, $path, $body = null) {
    $method = strtoupper((string) $method);
    $wa_settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];
    $access_token = trim((string)($wa_settings['access_token'] ?? ''));
    $api_version  = preg_replace('/[^a-zA-Z0-9\.\-_]/', '', (string)($wa_settings['api_version'] ?? 'v23.0'));
    $timeout      = min(60, max(5, absint($wa_settings['request_timeout'] ?? 20)));

    if ( $api_version === '' ) {
        $api_version = 'v23.0';
    }

    if ( $access_token === '' ) {
        return [
            'ok' => false,
            'http_code' => 0,
            'message' => 'Falta Access Token en WhatsApp Tickets.',
            'response' => null,
        ];
    }

    if ( ! empty($wa_settings['dry_run']) && $wa_settings['dry_run'] === '1' ) {
        return [
            'ok' => true,
            'http_code' => 0,
            'message' => 'Modo prueba interno: solicitud de plantilla simulada, no se llamó a Meta.',
            'response' => [
                'dry_run' => true,
                'id' => 'dry_run_template_id',
                'status' => 'DRY_RUN',
                'category' => 'UTILITY',
            ],
        ];
    }

    $endpoint = sprintf('https://graph.facebook.com/%s/%s', rawurlencode($api_version), ltrim($path, '/'));

    $args = [
        'timeout' => $timeout,
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
        ],
        'method' => $method,
    ];

    if ( $body !== null ) {
        $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $response = wp_remote_request($endpoint, $args);

    if ( is_wp_error($response) ) {
        return [
            'ok' => false,
            'http_code' => 0,
            'message' => $response->get_error_message(),
            'response' => null,
        ];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw_body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($raw_body, true);
    $ok = $code >= 200 && $code < 300;

    if ( function_exists('eventosapp_whatsapp_log') ) {
        eventosapp_whatsapp_log($ok ? 'Plantilla WhatsApp API OK' : 'Plantilla WhatsApp API error', [
            'method' => $method,
            'path' => $path,
            'http_code' => $code,
            'response' => $decoded ?: $raw_body,
        ]);
    }

    $message = $ok ? 'Solicitud aceptada por Meta.' : eventosapp_whatsapp_templates_extract_api_error($decoded, $raw_body, $code);

    return [
        'ok' => $ok,
        'http_code' => $code,
        'message' => $message,
        'response' => $decoded ?: $raw_body,
    ];
}

/**
 * Extrae errores de Meta.
 */
function eventosapp_whatsapp_templates_extract_api_error($decoded, $raw_body, $code) {
    if ( function_exists('eventosapp_whatsapp_extract_api_error') ) {
        return eventosapp_whatsapp_extract_api_error($decoded, $raw_body, $code);
    }
    if ( is_array($decoded) && ! empty($decoded['error']['message']) ) {
        return 'Meta API: ' . sanitize_text_field($decoded['error']['message']);
    }
    return 'Meta API HTTP ' . (int) $code;
}

/**
 * Envia o reenvía una plantilla a Meta.
 */
function eventosapp_whatsapp_templates_submit_to_meta($template_id) {
    $template_id = sanitize_key((string) $template_id);
    $settings = eventosapp_whatsapp_templates_get_settings();

    if ( empty($settings['templates'][$template_id]) ) {
        return ['ok' => false, 'message' => 'Plantilla local no encontrada.'];
    }

    $waba_id = preg_replace('/\D+/', '', (string)($settings['waba_id'] ?? ''));
    if ( $waba_id === '' ) {
        return ['ok' => false, 'message' => 'Configura primero el WhatsApp Business Account ID.'];
    }

    $template = $settings['templates'][$template_id];
    $errors = eventosapp_whatsapp_templates_validate_for_meta($template);
    if ( ! empty($errors) ) {
        return ['ok' => false, 'message' => implode(' ', $errors)];
    }

    $payload = [
        'name'       => $template['name'],
        'language'   => $template['language'],
        'category'   => 'UTILITY',
        'components' => eventosapp_whatsapp_templates_build_meta_components($template),
    ];

    if ( ! empty($template['meta_template_id']) ) {
        $path = rawurlencode($template['meta_template_id']);
        $api_result = eventosapp_whatsapp_templates_api_request('POST', $path, [
            'category'   => 'UTILITY',
            'components' => $payload['components'],
        ]);
    } else {
        $path = rawurlencode($waba_id) . '/message_templates';
        $api_result = eventosapp_whatsapp_templates_api_request('POST', $path, $payload);
    }

    $response = is_array($api_result['response'] ?? null) ? $api_result['response'] : [];

    if ( ! empty($api_result['ok']) ) {
        if ( ! empty($response['id']) ) {
            $template['meta_template_id'] = sanitize_text_field((string) $response['id']);
        }
        if ( ! empty($response['status']) ) {
            $template['meta_status'] = sanitize_text_field((string) $response['status']);
        } elseif ( empty($template['meta_status']) || $template['meta_status'] === 'LOCAL' ) {
            $template['meta_status'] = 'PENDING';
        }
        if ( ! empty($response['category']) ) {
            $template['meta_category'] = sanitize_text_field((string) $response['category']);
        }
        $template['meta_rejected_reason'] = '';
        $template['last_submitted_at'] = current_time('mysql');
    }

    $template['last_api_message'] = sanitize_text_field((string)($api_result['message'] ?? ''));
    $template['last_api_response'] = $response;
    $template['updated_at'] = current_time('mysql');
    $settings['templates'][$template_id] = $template;
    eventosapp_whatsapp_templates_update_settings($settings);

    return $api_result;
}

/**
 * Consulta estado de una plantilla en Meta.
 */
function eventosapp_whatsapp_templates_check_status($template_id) {
    $template_id = sanitize_key((string) $template_id);
    $settings = eventosapp_whatsapp_templates_get_settings();

    if ( empty($settings['templates'][$template_id]) ) {
        return ['ok' => false, 'message' => 'Plantilla local no encontrada.'];
    }

    $template = $settings['templates'][$template_id];
    if ( empty($template['meta_template_id']) ) {
        return eventosapp_whatsapp_templates_sync_template_by_name($template_id);
    }

    $fields = 'id,name,status,category,language,rejected_reason,quality_score';
    $path = rawurlencode($template['meta_template_id']) . '?fields=' . rawurlencode($fields);
    $api_result = eventosapp_whatsapp_templates_api_request('GET', $path);

    if ( ! empty($api_result['ok']) && is_array($api_result['response']) ) {
        $response = $api_result['response'];
        $template['meta_template_id'] = ! empty($response['id']) ? sanitize_text_field((string)$response['id']) : $template['meta_template_id'];
        $template['meta_status'] = ! empty($response['status']) ? sanitize_text_field((string)$response['status']) : $template['meta_status'];
        $template['meta_category'] = ! empty($response['category']) ? sanitize_text_field((string)$response['category']) : $template['meta_category'];
        $template['meta_rejected_reason'] = ! empty($response['rejected_reason']) ? sanitize_text_field((string)$response['rejected_reason']) : '';
        $template['last_checked_at'] = current_time('mysql');
        $template['last_api_message'] = sanitize_text_field((string)($api_result['message'] ?? ''));
        $template['last_api_response'] = $response;
        $settings['templates'][$template_id] = $template;
        eventosapp_whatsapp_templates_update_settings($settings);
    }

    return $api_result;
}

/**
 * Busca una plantilla por nombre/idioma si no hay ID remoto guardado.
 */
function eventosapp_whatsapp_templates_sync_template_by_name($template_id) {
    $template_id = sanitize_key((string) $template_id);
    $settings = eventosapp_whatsapp_templates_get_settings();

    if ( empty($settings['templates'][$template_id]) ) {
        return ['ok' => false, 'message' => 'Plantilla local no encontrada.'];
    }

    $waba_id = preg_replace('/\D+/', '', (string)($settings['waba_id'] ?? ''));
    if ( $waba_id === '' ) {
        return ['ok' => false, 'message' => 'Configura primero el WhatsApp Business Account ID.'];
    }

    $template = $settings['templates'][$template_id];
    $fields = 'id,name,status,category,language,rejected_reason,quality_score';
    $path = rawurlencode($waba_id) . '/message_templates?limit=100&fields=' . rawurlencode($fields);
    $api_result = eventosapp_whatsapp_templates_api_request('GET', $path);

    if ( empty($api_result['ok']) || empty($api_result['response']['data']) || ! is_array($api_result['response']['data']) ) {
        return $api_result;
    }

    $found = null;
    foreach ( $api_result['response']['data'] as $remote ) {
        if ( ! is_array($remote) ) {
            continue;
        }
        if ( ($remote['name'] ?? '') === $template['name'] && ($remote['language'] ?? '') === $template['language'] ) {
            $found = $remote;
            break;
        }
    }

    if ( ! $found ) {
        return ['ok' => false, 'message' => 'No se encontró esta plantilla en Meta por nombre e idioma.', 'response' => $api_result['response']];
    }

    $template['meta_template_id'] = ! empty($found['id']) ? sanitize_text_field((string)$found['id']) : '';
    $template['meta_status'] = ! empty($found['status']) ? sanitize_text_field((string)$found['status']) : 'UNKNOWN';
    $template['meta_category'] = ! empty($found['category']) ? sanitize_text_field((string)$found['category']) : '';
    $template['meta_rejected_reason'] = ! empty($found['rejected_reason']) ? sanitize_text_field((string)$found['rejected_reason']) : '';
    $template['last_checked_at'] = current_time('mysql');
    $template['last_api_message'] = 'Plantilla sincronizada desde Meta por nombre e idioma.';
    $template['last_api_response'] = $found;
    $settings['templates'][$template_id] = $template;
    eventosapp_whatsapp_templates_update_settings($settings);

    return ['ok' => true, 'message' => 'Estado sincronizado desde Meta.', 'response' => $found];
}

/**
 * Sincroniza estados de plantillas locales con Meta.
 */
function eventosapp_whatsapp_templates_sync_all() {
    $settings = eventosapp_whatsapp_templates_get_settings();
    $waba_id = preg_replace('/\D+/', '', (string)($settings['waba_id'] ?? ''));

    if ( $waba_id === '' ) {
        return ['ok' => false, 'message' => 'Configura primero el WhatsApp Business Account ID.'];
    }

    $fields = 'id,name,status,category,language,rejected_reason,quality_score';
    $path = rawurlencode($waba_id) . '/message_templates?limit=100&fields=' . rawurlencode($fields);
    $api_result = eventosapp_whatsapp_templates_api_request('GET', $path);

    if ( empty($api_result['ok']) || empty($api_result['response']['data']) || ! is_array($api_result['response']['data']) ) {
        return $api_result;
    }

    $updated = 0;
    foreach ( $settings['templates'] as $local_id => $template ) {
        if ( ! is_array($template) ) {
            continue;
        }
        foreach ( $api_result['response']['data'] as $remote ) {
            if ( ! is_array($remote) ) {
                continue;
            }
            if ( ($remote['name'] ?? '') !== ($template['name'] ?? '') || ($remote['language'] ?? '') !== ($template['language'] ?? '') ) {
                continue;
            }
            $template['meta_template_id'] = ! empty($remote['id']) ? sanitize_text_field((string)$remote['id']) : ($template['meta_template_id'] ?? '');
            $template['meta_status'] = ! empty($remote['status']) ? sanitize_text_field((string)$remote['status']) : ($template['meta_status'] ?? 'UNKNOWN');
            $template['meta_category'] = ! empty($remote['category']) ? sanitize_text_field((string)$remote['category']) : ($template['meta_category'] ?? '');
            $template['meta_rejected_reason'] = ! empty($remote['rejected_reason']) ? sanitize_text_field((string)$remote['rejected_reason']) : '';
            $template['last_checked_at'] = current_time('mysql');
            $template['last_api_response'] = $remote;
            $settings['templates'][$local_id] = $template;
            $updated++;
            break;
        }
    }

    $settings['last_sync_at'] = current_time('mysql');
    $settings['last_message'] = 'Sincronización ejecutada. Plantillas locales actualizadas: ' . $updated;
    eventosapp_whatsapp_templates_update_settings($settings);

    return ['ok' => true, 'message' => $settings['last_message'], 'response' => $api_result['response']];
}

/**
 * Render principal del módulo.
 */
function eventosapp_whatsapp_templates_render_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes para acceder a esta página.');
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    $wa_settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];
    $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'list';
    $template_id = isset($_GET['template_id']) ? sanitize_key(wp_unslash($_GET['template_id'])) : '';
    $notice = isset($_GET['evapp_wa_tpl_msg']) ? sanitize_text_field(wp_unslash($_GET['evapp_wa_tpl_msg'])) : '';
    $notice_ok = isset($_GET['evapp_wa_tpl_ok']) ? sanitize_text_field(wp_unslash($_GET['evapp_wa_tpl_ok'])) === '1' : false;
    ?>
    <div class="wrap eventosapp-wa-templates">
        <h1>Plantillas WhatsApp</h1>
        <p>Administra las plantillas transaccionales de WhatsApp para tickets presenciales y virtuales. La aprobación final siempre la realiza Meta.</p>

        <?php if ( $notice !== '' ) : ?>
            <div class="notice <?php echo $notice_ok ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><strong>EventosApp:</strong> <?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>

        <style>
            .evapp-wa-tpl-card{background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:18px;margin:18px 0;max-width:1180px;box-sizing:border-box;}
            .evapp-wa-tpl-card h2{margin-top:0;}
            .evapp-wa-tpl-grid{display:grid;grid-template-columns:230px minmax(300px,720px);gap:12px 18px;align-items:start;}
            .evapp-wa-tpl-grid label{font-weight:600;padding-top:6px;}
            .evapp-wa-tpl-grid input[type="text"],.evapp-wa-tpl-grid textarea,.evapp-wa-tpl-grid select{width:100%;max-width:720px;}
            .evapp-wa-tpl-grid textarea{min-height:110px;font-family:Menlo,Consolas,monospace;}
            .evapp-wa-tpl-help{color:#646970;font-size:12px;margin:4px 0 0;line-height:1.45;}
            .evapp-wa-tpl-code{font-family:Menlo,Consolas,monospace;background:#f6f7f7;padding:2px 5px;border-radius:4px;}
            .evapp-wa-tpl-table{border-collapse:collapse;width:100%;background:#fff;margin-top:12px;}
            .evapp-wa-tpl-table th,.evapp-wa-tpl-table td{border:1px solid #dcdcde;padding:9px;text-align:left;vertical-align:top;}
            .evapp-wa-tpl-table th{background:#f6f7f7;}
            .evapp-wa-status{display:inline-block;border-radius:999px;padding:2px 8px;font-size:12px;font-weight:600;background:#f0f0f1;color:#1d2327;}
            .evapp-wa-status.APPROVED{background:#d1e7dd;color:#0f5132;}
            .evapp-wa-status.PENDING,.evapp-wa-status.IN_APPEAL{background:#fff3cd;color:#664d03;}
            .evapp-wa-status.REJECTED,.evapp-wa-status.PAUSED,.evapp-wa-status.DISABLED{background:#f8d7da;color:#842029;}
            .evapp-wa-tpl-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
            .evapp-wa-tpl-preview{white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px;line-height:1.45;}
            .evapp-wa-tpl-warning{background:#fff8e5;border-left:4px solid #dba617;padding:10px 12px;margin:12px 0;max-width:1180px;}
        </style>

        <div class="evapp-wa-tpl-card">
            <h2>Conexión con Meta para plantillas</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('eventosapp_whatsapp_templates_save_settings', 'eventosapp_whatsapp_templates_settings_nonce'); ?>
                <input type="hidden" name="action" value="eventosapp_whatsapp_templates_save_settings">
                <div class="evapp-wa-tpl-grid">
                    <label for="evapp_wa_tpl_waba_id">WhatsApp Business Account ID</label>
                    <div>
                        <input type="text" id="evapp_wa_tpl_waba_id" name="waba_id" value="<?php echo esc_attr($settings['waba_id'] ?? ''); ?>" placeholder="Ej: 123456789012345">
                        <p class="evapp-wa-tpl-help">Es el ID de la cuenta de WhatsApp Business, diferente al Phone Number ID. Se usa para crear y consultar plantillas en Meta.</p>
                    </div>

                    <label>Credenciales reutilizadas</label>
                    <div>
                        <p class="evapp-wa-tpl-help">
                            Este módulo usa la versión Graph API y el Access Token guardados en <strong>WhatsApp Tickets</strong>.
                            Estado del token: <strong><?php echo ! empty($wa_settings['access_token']) ? 'guardado' : 'no configurado'; ?></strong>.
                            Versión API: <span class="evapp-wa-tpl-code"><?php echo esc_html($wa_settings['api_version'] ?? 'v23.0'); ?></span>.
                        </p>
                    </div>
                </div>
                <?php submit_button('Guardar conexión de plantillas', 'primary', 'submit', false); ?>
            </form>
        </div>

        <div class="evapp-wa-tpl-warning">
            <strong>Importante:</strong> la plantilla presencial usa encabezado de imagen para poder enviar el QR como imagen dinámica cuando se use en el envío final. Para enviar esa plantilla a Meta por API debes agregar un <span class="evapp-wa-tpl-code">Header Sample Handle</span> válido de Meta. Sin ese handle, Meta rechazará la creación por API.
        </div>

        <?php
        if ( $view === 'edit' ) {
            eventosapp_whatsapp_templates_render_edit_form($template_id);
        } else {
            eventosapp_whatsapp_templates_render_list($settings);
        }
        ?>
    </div>
    <?php
}

/**
 * Render listado de plantillas.
 */
function eventosapp_whatsapp_templates_render_list($settings) {
    $templates = isset($settings['templates']) && is_array($settings['templates']) ? $settings['templates'] : [];
    ?>
    <div class="evapp-wa-tpl-card">
        <h2>Plantillas disponibles</h2>
        <p>
            Las dos plantillas por defecto ya quedan creadas localmente como base: una para modalidad presencial y otra para modalidad virtual. Puedes editarlas, duplicarlas o enviar su estructura a Meta para aprobación.
        </p>

        <p class="evapp-wa-tpl-actions">
            <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page' => 'eventosapp_whatsapp_templates', 'view' => 'edit', 'base' => 'presencial'], admin_url('admin.php'))); ?>">Crear nueva desde presencial</a>
            <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page' => 'eventosapp_whatsapp_templates', 'view' => 'edit', 'base' => 'virtual'], admin_url('admin.php'))); ?>">Crear nueva desde virtual</a>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <?php wp_nonce_field('eventosapp_whatsapp_templates_sync_all', 'eventosapp_whatsapp_templates_sync_nonce'); ?>
                <input type="hidden" name="action" value="eventosapp_whatsapp_templates_sync_all">
                <?php submit_button('Sincronizar estados desde Meta', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('Esto restaurará las dos plantillas base de EventosApp. No elimina las plantillas personalizadas.');">
                <?php wp_nonce_field('eventosapp_whatsapp_templates_reset_defaults', 'eventosapp_whatsapp_templates_reset_nonce'); ?>
                <input type="hidden" name="action" value="eventosapp_whatsapp_templates_reset_defaults">
                <?php submit_button('Restaurar plantillas base', 'secondary', 'submit', false); ?>
            </form>
        </p>

        <?php if ( ! empty($settings['last_sync_at']) ) : ?>
            <p class="evapp-wa-tpl-help">Última sincronización: <?php echo esc_html($settings['last_sync_at']); ?>. <?php echo esc_html($settings['last_message'] ?? ''); ?></p>
        <?php endif; ?>

        <table class="evapp-wa-tpl-table">
            <thead>
                <tr>
                    <th>Plantilla</th>
                    <th>Modalidad</th>
                    <th>Idioma / Categoría</th>
                    <th>Estado Meta</th>
                    <th>Botones</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $templates as $template_id => $template ) : ?>
                    <?php if ( ! is_array($template) ) continue; ?>
                    <?php $status = ! empty($template['meta_status']) ? strtoupper($template['meta_status']) : 'LOCAL'; ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($template['title'] ?? $template['name'] ?? $template_id); ?></strong><br>
                            <span class="evapp-wa-tpl-code"><?php echo esc_html($template['name'] ?? ''); ?></span>
                            <?php if ( ! empty($template['is_default']) && $template['is_default'] === '1' ) : ?><br><small>Plantilla base de EventosApp</small><?php endif; ?>
                            <?php if ( ! empty($template['meta_template_id']) ) : ?><br><small>ID Meta: <?php echo esc_html($template['meta_template_id']); ?></small><?php endif; ?>
                        </td>
                        <td><?php echo esc_html(eventosapp_whatsapp_templates_modality_label($template['modality'] ?? 'custom')); ?></td>
                        <td>
                            <?php echo esc_html($template['language'] ?? 'es'); ?><br>
                            <?php echo esc_html($template['category'] ?? 'UTILITY'); ?>
                        </td>
                        <td>
                            <span class="evapp-wa-status <?php echo esc_attr($status); ?>"><?php echo esc_html($status); ?></span>
                            <?php if ( ! empty($template['meta_rejected_reason']) ) : ?><br><small><?php echo esc_html($template['meta_rejected_reason']); ?></small><?php endif; ?>
                            <?php if ( ! empty($template['last_checked_at']) ) : ?><br><small>Consulta: <?php echo esc_html($template['last_checked_at']); ?></small><?php endif; ?>
                            <?php if ( ! empty($template['last_api_message']) ) : ?><br><small><?php echo esc_html($template['last_api_message']); ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?php if ( ! empty($template['button_1_text']) ) : ?>1. <?php echo esc_html($template['button_1_text']); ?><br><?php endif; ?>
                            <?php if ( ! empty($template['button_2_text']) ) : ?>2. <?php echo esc_html($template['button_2_text']); ?><?php endif; ?>
                        </td>
                        <td>
                            <div class="evapp-wa-tpl-actions">
                                <a class="button button-secondary" href="<?php echo esc_url(add_query_arg(['page' => 'eventosapp_whatsapp_templates', 'view' => 'edit', 'template_id' => $template_id], admin_url('admin.php'))); ?>">Editar</a>

                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                    <?php wp_nonce_field('eventosapp_whatsapp_templates_submit_' . $template_id, 'eventosapp_whatsapp_templates_submit_nonce'); ?>
                                    <input type="hidden" name="action" value="eventosapp_whatsapp_templates_submit">
                                    <input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>">
                                    <?php submit_button('Enviar / reenviar a Meta', 'primary small', 'submit', false); ?>
                                </form>

                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                    <?php wp_nonce_field('eventosapp_whatsapp_templates_check_' . $template_id, 'eventosapp_whatsapp_templates_check_nonce'); ?>
                                    <input type="hidden" name="action" value="eventosapp_whatsapp_templates_check">
                                    <input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>">
                                    <?php submit_button('Consultar estado', 'secondary small', 'submit', false); ?>
                                </form>

                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                    <?php wp_nonce_field('eventosapp_whatsapp_templates_duplicate_' . $template_id, 'eventosapp_whatsapp_templates_duplicate_nonce'); ?>
                                    <input type="hidden" name="action" value="eventosapp_whatsapp_templates_duplicate">
                                    <input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>">
                                    <?php submit_button('Duplicar', 'secondary small', 'submit', false); ?>
                                </form>

                                <?php if ( empty($template['is_default']) || $template['is_default'] !== '1' ) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('¿Eliminar esta plantilla local? No elimina la plantilla en Meta.');">
                                        <?php wp_nonce_field('eventosapp_whatsapp_templates_delete_' . $template_id, 'eventosapp_whatsapp_templates_delete_nonce'); ?>
                                        <input type="hidden" name="action" value="eventosapp_whatsapp_templates_delete">
                                        <input type="hidden" name="template_id" value="<?php echo esc_attr($template_id); ?>">
                                        <?php submit_button('Eliminar local', 'delete small', 'submit', false); ?>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Etiqueta legible de modalidad.
 */
function eventosapp_whatsapp_templates_modality_label($modality) {
    $labels = [
        'presencial' => 'Presencial',
        'virtual'    => 'Virtual',
        'custom'     => 'Personalizada',
    ];
    return $labels[$modality] ?? 'Personalizada';
}

/**
 * Render formulario de edición/creación.
 */
function eventosapp_whatsapp_templates_render_edit_form($template_id = '') {
    $settings = eventosapp_whatsapp_templates_get_settings();
    $template = null;
    $is_new = false;

    if ( $template_id !== '' && ! empty($settings['templates'][$template_id]) ) {
        $template = $settings['templates'][$template_id];
    } else {
        $base = isset($_GET['base']) ? sanitize_key(wp_unslash($_GET['base'])) : 'presencial';
        $defaults = eventosapp_whatsapp_templates_default_records();
        $source = $base === 'virtual' ? $defaults['default_virtual'] : $defaults['default_presencial'];
        $source['id'] = 'tpl_' . wp_generate_uuid4();
        $source['is_default'] = '0';
        $source['name'] = preg_replace('/_v\d+$/', '_custom_v1', $source['name']);
        $source['title'] = 'Nueva plantilla basada en ' . eventosapp_whatsapp_templates_modality_label($source['modality']);
        $source['meta_template_id'] = '';
        $source['meta_status'] = 'LOCAL';
        $source['meta_rejected_reason'] = '';
        $source['last_api_message'] = '';
        $source['last_api_response'] = [];
        $source['last_submitted_at'] = '';
        $source['last_checked_at'] = '';
        $source['created_at'] = current_time('mysql');
        $source['updated_at'] = current_time('mysql');
        $template = $source;
        $is_new = true;
    }

    $preview_payload = [
        'name'       => $template['name'] ?? '',
        'language'   => $template['language'] ?? 'es',
        'category'   => 'UTILITY',
        'components' => eventosapp_whatsapp_templates_build_meta_components($template),
    ];
    ?>
    <div class="evapp-wa-tpl-card">
        <h2><?php echo $is_new ? 'Crear plantilla WhatsApp' : 'Editar plantilla WhatsApp'; ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('eventosapp_whatsapp_templates_save_template', 'eventosapp_whatsapp_templates_save_nonce'); ?>
            <input type="hidden" name="action" value="eventosapp_whatsapp_templates_save_template">
            <input type="hidden" name="template[id]" value="<?php echo esc_attr($template['id'] ?? ''); ?>">
            <input type="hidden" name="existing_template_id" value="<?php echo esc_attr($is_new ? '' : ($template['id'] ?? '')); ?>">

            <div class="evapp-wa-tpl-grid">
                <label for="evapp_tpl_title">Título interno</label>
                <div>
                    <input type="text" id="evapp_tpl_title" name="template[title]" value="<?php echo esc_attr($template['title'] ?? ''); ?>">
                    <p class="evapp-wa-tpl-help">Solo se usa dentro de EventosApp.</p>
                </div>

                <label for="evapp_tpl_name">Nombre técnico Meta</label>
                <div>
                    <input type="text" id="evapp_tpl_name" name="template[name]" value="<?php echo esc_attr($template['name'] ?? ''); ?>" required>
                    <p class="evapp-wa-tpl-help">Usa minúsculas, números y guion bajo. Ejemplo: <span class="evapp-wa-tpl-code">eventosapp_ticket_presencial_v1</span>.</p>
                </div>

                <label for="evapp_tpl_language">Idioma</label>
                <div>
                    <input type="text" id="evapp_tpl_language" name="template[language]" value="<?php echo esc_attr($template['language'] ?? 'es'); ?>" required>
                    <p class="evapp-wa-tpl-help">Ejemplo recomendado: <span class="evapp-wa-tpl-code">es</span>.</p>
                </div>

                <label for="evapp_tpl_modality">Modalidad</label>
                <div>
                    <select id="evapp_tpl_modality" name="template[modality]">
                        <option value="presencial" <?php selected($template['modality'] ?? '', 'presencial'); ?>>Presencial</option>
                        <option value="virtual" <?php selected($template['modality'] ?? '', 'virtual'); ?>>Virtual</option>
                        <option value="custom" <?php selected($template['modality'] ?? '', 'custom'); ?>>Personalizada</option>
                    </select>
                </div>

                <label for="evapp_tpl_category">Categoría</label>
                <div>
                    <input type="text" id="evapp_tpl_category" name="template[category]" value="UTILITY" readonly>
                    <p class="evapp-wa-tpl-help">Se fuerza Utility porque son mensajes transaccionales de confirmación/acceso al evento.</p>
                </div>

                <label for="evapp_tpl_header_format">Encabezado</label>
                <div>
                    <select id="evapp_tpl_header_format" name="template[header_format]">
                        <option value="NONE" <?php selected($template['header_format'] ?? '', 'NONE'); ?>>Sin encabezado</option>
                        <option value="TEXT" <?php selected($template['header_format'] ?? '', 'TEXT'); ?>>Texto</option>
                        <option value="IMAGE" <?php selected($template['header_format'] ?? '', 'IMAGE'); ?>>Imagen dinámica</option>
                    </select>
                    <p class="evapp-wa-tpl-help">Para la plantilla presencial se usa Imagen dinámica para luego enviar el QR individual del ticket.</p>
                </div>

                <label for="evapp_tpl_header_text">Texto de encabezado</label>
                <div>
                    <input type="text" id="evapp_tpl_header_text" name="template[header_text]" value="<?php echo esc_attr($template['header_text'] ?? ''); ?>">
                    <p class="evapp-wa-tpl-help">Solo aplica si el encabezado es Texto.</p>
                </div>

                <label for="evapp_tpl_header_handle">Header Sample Handle</label>
                <div>
                    <input type="text" id="evapp_tpl_header_handle" name="template[header_sample_handle]" value="<?php echo esc_attr($template['header_sample_handle'] ?? ''); ?>">
                    <p class="evapp-wa-tpl-help">Obligatorio para enviar a Meta una plantilla con encabezado de imagen. Debe ser el handle de muestra de Meta, no una URL pública.</p>
                </div>

                <label for="evapp_tpl_body">Cuerpo</label>
                <div>
                    <textarea id="evapp_tpl_body" name="template[body_text]" required><?php echo esc_textarea($template['body_text'] ?? ''); ?></textarea>
                    <p class="evapp-wa-tpl-help">Variables aprobadas por Meta: <span class="evapp-wa-tpl-code">{{1}}</span> nombre del asistente, <span class="evapp-wa-tpl-code">{{2}}</span> evento, <span class="evapp-wa-tpl-code">{{3}}</span> fecha, <span class="evapp-wa-tpl-code">{{4}}</span> hora, <span class="evapp-wa-tpl-code">{{5}}</span> lugar o plataforma.</p>
                </div>

                <label for="evapp_tpl_body_examples">Ejemplos del cuerpo</label>
                <div>
                    <textarea id="evapp_tpl_body_examples" name="template[body_examples]" required><?php echo esc_textarea($template['body_examples'] ?? ''); ?></textarea>
                    <p class="evapp-wa-tpl-help">Un ejemplo por línea, en el mismo orden de las variables numéricas del cuerpo.</p>
                </div>

                <label for="evapp_tpl_footer">Footer</label>
                <div>
                    <input type="text" id="evapp_tpl_footer" name="template[footer_text]" value="<?php echo esc_attr($template['footer_text'] ?? ''); ?>">
                </div>

                <label>Botón 1</label>
                <div>
                    <input type="text" name="template[button_1_text]" value="<?php echo esc_attr($template['button_1_text'] ?? ''); ?>" placeholder="Texto del botón" style="margin-bottom:6px;">
                    <input type="text" name="template[button_1_url]" value="<?php echo esc_attr($template['button_1_url'] ?? ''); ?>" placeholder="URL con {{1}}" style="margin-bottom:6px;">
                    <input type="text" name="template[button_1_example]" value="<?php echo esc_attr($template['button_1_example'] ?? ''); ?>" placeholder="URL de ejemplo completa">
                    <p class="evapp-wa-tpl-help">El botón URL puede usar una sola variable <span class="evapp-wa-tpl-code">{{1}}</span> al final para el identificador público del ticket.</p>
                </div>

                <label>Botón 2</label>
                <div>
                    <input type="text" name="template[button_2_text]" value="<?php echo esc_attr($template['button_2_text'] ?? ''); ?>" placeholder="Texto del botón" style="margin-bottom:6px;">
                    <input type="text" name="template[button_2_url]" value="<?php echo esc_attr($template['button_2_url'] ?? ''); ?>" placeholder="URL con {{1}}" style="margin-bottom:6px;">
                    <input type="text" name="template[button_2_example]" value="<?php echo esc_attr($template['button_2_example'] ?? ''); ?>" placeholder="URL de ejemplo completa">
                    <p class="evapp-wa-tpl-help">La estructura queda limitada a dos botones URL para mantener compatibilidad con Meta.</p>
                </div>
            </div>

            <p>
                <?php submit_button('Guardar plantilla local', 'primary', 'submit', false); ?>
                <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_templates')); ?>">Volver al listado</a>
            </p>
        </form>
    </div>

    <div class="evapp-wa-tpl-card">
        <h2>Vista técnica del payload para Meta</h2>
        <pre class="evapp-wa-tpl-preview"><?php echo esc_html(wp_json_encode($preview_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
    </div>
    <?php
}

/**
 * Redirección con mensaje al módulo.
 */
function eventosapp_whatsapp_templates_redirect($ok, $message, $extra_args = []) {
    $args = array_merge([
        'page' => 'eventosapp_whatsapp_templates',
        'evapp_wa_tpl_ok' => $ok ? '1' : '0',
        'evapp_wa_tpl_msg' => rawurlencode((string) $message),
    ], $extra_args);

    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}

/**
 * Guarda conexión WABA ID.
 */
add_action('admin_post_eventosapp_whatsapp_templates_save_settings', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }
    if ( ! isset($_POST['eventosapp_whatsapp_templates_settings_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_settings_nonce'], 'eventosapp_whatsapp_templates_save_settings') ) {
        wp_die('Nonce inválido.');
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    $settings['waba_id'] = isset($_POST['waba_id']) ? preg_replace('/\D+/', '', (string) wp_unslash($_POST['waba_id'])) : '';
    eventosapp_whatsapp_templates_update_settings($settings);

    eventosapp_whatsapp_templates_redirect(true, 'Conexión de plantillas guardada.');
});

/**
 * Guarda plantilla local.
 */
add_action('admin_post_eventosapp_whatsapp_templates_save_template', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }
    if ( ! isset($_POST['eventosapp_whatsapp_templates_save_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_save_nonce'], 'eventosapp_whatsapp_templates_save_template') ) {
        wp_die('Nonce inválido.');
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    $existing_id = isset($_POST['existing_template_id']) ? sanitize_key(wp_unslash($_POST['existing_template_id'])) : '';
    $raw_template = isset($_POST['template']) && is_array($_POST['template']) ? wp_unslash($_POST['template']) : [];
    $existing = $existing_id && ! empty($settings['templates'][$existing_id]) ? $settings['templates'][$existing_id] : [];
    $template = eventosapp_whatsapp_templates_normalize_template($raw_template, $existing);

    if ( $existing_id && $existing_id !== $template['id'] && isset($settings['templates'][$existing_id]) ) {
        unset($settings['templates'][$existing_id]);
    }

    $settings['templates'][$template['id']] = $template;
    eventosapp_whatsapp_templates_update_settings($settings);

    eventosapp_whatsapp_templates_redirect(true, 'Plantilla local guardada.', [
        'view' => 'edit',
        'template_id' => $template['id'],
    ]);
});

/**
 * Duplica una plantilla local.
 */
add_action('admin_post_eventosapp_whatsapp_templates_duplicate', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }

    $template_id = isset($_POST['template_id']) ? sanitize_key(wp_unslash($_POST['template_id'])) : '';
    if ( ! isset($_POST['eventosapp_whatsapp_templates_duplicate_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_duplicate_nonce'], 'eventosapp_whatsapp_templates_duplicate_' . $template_id) ) {
        wp_die('Nonce inválido.');
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    if ( empty($settings['templates'][$template_id]) ) {
        eventosapp_whatsapp_templates_redirect(false, 'No se encontró la plantilla para duplicar.');
    }

    $copy = $settings['templates'][$template_id];
    $copy['id'] = 'tpl_' . wp_generate_uuid4();
    $copy['is_default'] = '0';
    $copy['title'] = 'Copia de ' . ($copy['title'] ?? $copy['name']);
    $copy['name'] = eventosapp_whatsapp_templates_sanitize_template_name(($copy['name'] ?? 'eventosapp_template') . '_copy_' . substr(md5($copy['id']), 0, 4));
    $copy['meta_template_id'] = '';
    $copy['meta_status'] = 'LOCAL';
    $copy['meta_rejected_reason'] = '';
    $copy['last_api_message'] = '';
    $copy['last_api_response'] = [];
    $copy['last_submitted_at'] = '';
    $copy['last_checked_at'] = '';
    $copy['created_at'] = current_time('mysql');
    $copy['updated_at'] = current_time('mysql');

    $settings['templates'][$copy['id']] = $copy;
    eventosapp_whatsapp_templates_update_settings($settings);

    eventosapp_whatsapp_templates_redirect(true, 'Plantilla duplicada. Puedes editarla antes de enviarla a Meta.', [
        'view' => 'edit',
        'template_id' => $copy['id'],
    ]);
});

/**
 * Elimina plantilla local no predeterminada.
 */
add_action('admin_post_eventosapp_whatsapp_templates_delete', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }

    $template_id = isset($_POST['template_id']) ? sanitize_key(wp_unslash($_POST['template_id'])) : '';
    if ( ! isset($_POST['eventosapp_whatsapp_templates_delete_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_delete_nonce'], 'eventosapp_whatsapp_templates_delete_' . $template_id) ) {
        wp_die('Nonce inválido.');
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    if ( ! empty($settings['templates'][$template_id]['is_default']) && $settings['templates'][$template_id]['is_default'] === '1' ) {
        eventosapp_whatsapp_templates_redirect(false, 'No puedes eliminar una plantilla base. Usa restaurar si necesitas volver al diseño original.');
    }

    unset($settings['templates'][$template_id]);
    eventosapp_whatsapp_templates_update_settings($settings);

    eventosapp_whatsapp_templates_redirect(true, 'Plantilla local eliminada.');
});

/**
 * Enviar / reenviar plantilla a Meta.
 */
add_action('admin_post_eventosapp_whatsapp_templates_submit', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }

    $template_id = isset($_POST['template_id']) ? sanitize_key(wp_unslash($_POST['template_id'])) : '';
    if ( ! isset($_POST['eventosapp_whatsapp_templates_submit_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_submit_nonce'], 'eventosapp_whatsapp_templates_submit_' . $template_id) ) {
        wp_die('Nonce inválido.');
    }

    $result = eventosapp_whatsapp_templates_submit_to_meta($template_id);
    eventosapp_whatsapp_templates_redirect(! empty($result['ok']), $result['message'] ?? 'Solicitud procesada.');
});

/**
 * Consultar estado de plantilla.
 */
add_action('admin_post_eventosapp_whatsapp_templates_check', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }

    $template_id = isset($_POST['template_id']) ? sanitize_key(wp_unslash($_POST['template_id'])) : '';
    if ( ! isset($_POST['eventosapp_whatsapp_templates_check_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_check_nonce'], 'eventosapp_whatsapp_templates_check_' . $template_id) ) {
        wp_die('Nonce inválido.');
    }

    $result = eventosapp_whatsapp_templates_check_status($template_id);
    eventosapp_whatsapp_templates_redirect(! empty($result['ok']), $result['message'] ?? 'Consulta procesada.');
});

/**
 * Sincroniza todas las plantillas locales con Meta.
 */
add_action('admin_post_eventosapp_whatsapp_templates_sync_all', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }
    if ( ! isset($_POST['eventosapp_whatsapp_templates_sync_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_sync_nonce'], 'eventosapp_whatsapp_templates_sync_all') ) {
        wp_die('Nonce inválido.');
    }

    $result = eventosapp_whatsapp_templates_sync_all();
    eventosapp_whatsapp_templates_redirect(! empty($result['ok']), $result['message'] ?? 'Sincronización procesada.');
});

/**
 * Restaura plantillas base sin eliminar personalizadas.
 */
add_action('admin_post_eventosapp_whatsapp_templates_reset_defaults', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }
    if ( ! isset($_POST['eventosapp_whatsapp_templates_reset_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_reset_nonce'], 'eventosapp_whatsapp_templates_reset_defaults') ) {
        wp_die('Nonce inválido.');
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    foreach ( eventosapp_whatsapp_templates_default_records() as $id => $template ) {
        $settings['templates'][$id] = $template;
    }
    eventosapp_whatsapp_templates_update_settings($settings);

    eventosapp_whatsapp_templates_redirect(true, 'Plantillas base restauradas.');
});

/**
 * Resuelve ticket por identificador público recibido desde botones WhatsApp.
 */
function eventosapp_whatsapp_templates_resolve_ticket_from_request() {
    $public = '';
    foreach ( ['ticket', 'ticket_pub', 'public_id', 'ticketID'] as $key ) {
        if ( isset($_GET[$key]) && $_GET[$key] !== '' ) {
            $public = sanitize_text_field(wp_unslash($_GET[$key]));
            break;
        }
    }

    if ( $public === '' ) {
        return 0;
    }

    if ( function_exists('eventosapp_find_ticket_by_public_id') ) {
        $ticket_id = eventosapp_find_ticket_by_public_id($public);
        if ( $ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket' ) {
            return $ticket_id;
        }
    }

    if ( ctype_digit($public) && current_user_can('edit_post', absint($public)) && get_post_type(absint($public)) === 'eventosapp_ticket' ) {
        return absint($public);
    }

    return 0;
}

/**
 * Obtiene URLs útiles del ticket para landing de WhatsApp.
 */
function eventosapp_whatsapp_templates_get_ticket_assets($ticket_id) {
    $ticket_id = absint($ticket_id);
    $event_id = $ticket_id ? absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true)) : 0;

    $qr_url = function_exists('eventosapp_whatsapp_ensure_qr_url') ? eventosapp_whatsapp_ensure_qr_url($ticket_id) : '';

    $ics_url = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
    if ( ! $ics_url && function_exists('eventosapp_ticket_generar_ics') ) {
        eventosapp_ticket_generar_ics($ticket_id);
        $ics_url = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
    }

    $pdf_url = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
    if ( ! $pdf_url && function_exists('eventosapp_ticket_generar_pdf') && ! (function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id)) ) {
        eventosapp_ticket_generar_pdf($ticket_id);
        $pdf_url = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
    }

    $google_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url', true);
    if ( ! $google_wallet ) {
        $google_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android', true);
    }

    $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url', true);
    if ( ! $apple_wallet ) {
        $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple', true);
    }
    if ( ! $apple_wallet ) {
        $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url', true);
    }

    $virtual_landing = '';
    if ( function_exists('eventosapp_get_virtual_landing_url') ) {
        $virtual_landing = eventosapp_get_virtual_landing_url($ticket_id);
    }

    $platform_url = function_exists('eventosapp_get_ticket_virtual_platform_url') ? eventosapp_get_ticket_virtual_platform_url($ticket_id) : ($event_id ? get_post_meta($event_id, '_eventosapp_virtual_url', true) : '');

    return [
        'qr' => esc_url_raw($qr_url),
        'ics' => esc_url_raw($ics_url),
        'pdf' => esc_url_raw($pdf_url),
        'google_wallet' => esc_url_raw($google_wallet),
        'apple_wallet' => esc_url_raw($apple_wallet),
        'virtual_landing' => esc_url_raw($virtual_landing),
        'platform_url' => esc_url_raw($platform_url),
    ];
}

/**
 * Render público de landing de ticket para el botón Ver mi ticket.
 */
add_action('admin_post_nopriv_eventosapp_whatsapp_ticket_landing', 'eventosapp_whatsapp_templates_render_public_ticket_landing');
add_action('admin_post_eventosapp_whatsapp_ticket_landing', 'eventosapp_whatsapp_templates_render_public_ticket_landing');

function eventosapp_whatsapp_templates_render_public_ticket_landing() {
    $ticket_id = eventosapp_whatsapp_templates_resolve_ticket_from_request();
    if ( ! $ticket_id ) {
        status_header(404);
        wp_die('Ticket no encontrado.');
    }

    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $assets = eventosapp_whatsapp_templates_get_ticket_assets($ticket_id);
    $nombre = trim(get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' . get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true));
    $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
    $modalidad = function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label($ticket_id) : get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
    $fecha = function_exists('eventosapp_whatsapp_get_event_date_label') ? eventosapp_whatsapp_get_event_date_label($event_id) : '';
    $hora_inicio = $event_id ? get_post_meta($event_id, '_eventosapp_hora_inicio', true) : '';
    $hora_cierre = $event_id ? get_post_meta($event_id, '_eventosapp_hora_cierre', true) : '';
    $direccion = $event_id ? get_post_meta($event_id, '_eventosapp_direccion', true) : '';

    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow', true);
    header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
    ?>
    <!doctype html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,nofollow">
        <title><?php echo esc_html(get_the_title($event_id)); ?> - Ticket</title>
        <style>
            body{margin:0;background:#f4f6f8;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
            .evapp-ticket-wrap{max-width:720px;margin:0 auto;padding:24px;}
            .evapp-ticket-card{background:#fff;border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:24px;}
            .evapp-ticket-title{margin:0 0 8px;font-size:26px;line-height:1.2;}
            .evapp-ticket-meta{color:#646970;line-height:1.5;margin:0 0 18px;}
            .evapp-ticket-qr{text-align:center;margin:20px 0;}
            .evapp-ticket-qr img{max-width:260px;width:100%;height:auto;border:1px solid #dcdcde;border-radius:14px;padding:10px;background:#fff;}
            .evapp-ticket-buttons{display:grid;grid-template-columns:1fr;gap:10px;margin-top:18px;}
            .evapp-ticket-button{display:block;text-align:center;text-decoration:none;background:#1d4ed8;color:#fff;padding:13px 16px;border-radius:12px;font-weight:700;}
            .evapp-ticket-button.secondary{background:#0f766e;}
            .evapp-ticket-button.neutral{background:#374151;}
            .evapp-ticket-details{border-top:1px solid #e5e7eb;margin-top:18px;padding-top:18px;line-height:1.6;}
            .evapp-ticket-small{font-size:12px;color:#646970;margin-top:18px;text-align:center;}
        </style>
    </head>
    <body>
        <main class="evapp-ticket-wrap">
            <section class="evapp-ticket-card">
                <h1 class="evapp-ticket-title"><?php echo esc_html(get_the_title($event_id)); ?></h1>
                <p class="evapp-ticket-meta">
                    <?php if ( $nombre ) : ?>Asistente: <strong><?php echo esc_html($nombre); ?></strong><br><?php endif; ?>
                    <?php if ( $ticket_code ) : ?>Ticket: <strong><?php echo esc_html($ticket_code); ?></strong><br><?php endif; ?>
                    <?php if ( $modalidad ) : ?>Modalidad: <strong><?php echo esc_html($modalidad); ?></strong><?php endif; ?>
                </p>

                <?php if ( ! empty($assets['qr']) ) : ?>
                    <div class="evapp-ticket-qr">
                        <img src="<?php echo esc_url($assets['qr']); ?>" alt="QR de ingreso">
                    </div>
                <?php endif; ?>

                <div class="evapp-ticket-buttons">
                    <?php if ( ! empty($assets['google_wallet']) ) : ?><a class="evapp-ticket-button" href="<?php echo esc_url($assets['google_wallet']); ?>" target="_blank" rel="noopener noreferrer">Agregar a Google Wallet</a><?php endif; ?>
                    <?php if ( ! empty($assets['apple_wallet']) ) : ?><a class="evapp-ticket-button" href="<?php echo esc_url($assets['apple_wallet']); ?>" target="_blank" rel="noopener noreferrer">Agregar a Apple Wallet</a><?php endif; ?>
                    <?php if ( ! empty($assets['ics']) ) : ?><a class="evapp-ticket-button secondary" href="<?php echo esc_url($assets['ics']); ?>" target="_blank" rel="noopener noreferrer">Agregar a agenda</a><?php endif; ?>
                    <?php if ( ! empty($assets['pdf']) ) : ?><a class="evapp-ticket-button neutral" href="<?php echo esc_url($assets['pdf']); ?>" target="_blank" rel="noopener noreferrer">Descargar PDF</a><?php endif; ?>
                    <?php if ( ! empty($assets['virtual_landing']) && (function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id)) ) : ?><a class="evapp-ticket-button" href="<?php echo esc_url($assets['virtual_landing']); ?>" target="_blank" rel="noopener noreferrer">Ingresar al evento virtual</a><?php endif; ?>
                </div>

                <div class="evapp-ticket-details">
                    <?php if ( $fecha ) : ?><div><strong>Fecha:</strong> <?php echo esc_html($fecha); ?></div><?php endif; ?>
                    <?php if ( $hora_inicio ) : ?><div><strong>Hora:</strong> <?php echo esc_html($hora_inicio . ($hora_cierre ? ' - ' . $hora_cierre : '')); ?></div><?php endif; ?>
                    <?php if ( $direccion ) : ?><div><strong>Lugar:</strong> <?php echo esc_html($direccion); ?></div><?php endif; ?>
                </div>

                <p class="evapp-ticket-small">Este enlace pertenece a EventosApp. No compartas tu ticket con terceros.</p>
            </section>
        </main>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Redirige al ICS desde botón WhatsApp.
 */
add_action('admin_post_nopriv_eventosapp_whatsapp_ticket_ics', 'eventosapp_whatsapp_templates_redirect_ticket_ics');
add_action('admin_post_eventosapp_whatsapp_ticket_ics', 'eventosapp_whatsapp_templates_redirect_ticket_ics');

function eventosapp_whatsapp_templates_redirect_ticket_ics() {
    $ticket_id = eventosapp_whatsapp_templates_resolve_ticket_from_request();
    if ( ! $ticket_id ) {
        status_header(404);
        wp_die('Ticket no encontrado.');
    }

    $assets = eventosapp_whatsapp_templates_get_ticket_assets($ticket_id);
    if ( empty($assets['ics']) ) {
        status_header(404);
        wp_die('No se encontró archivo ICS para este ticket.');
    }

    wp_safe_redirect($assets['ics']);
    exit;
}

/**
 * Redirige a acceso virtual desde botón WhatsApp.
 */
add_action('admin_post_nopriv_eventosapp_whatsapp_virtual_access', 'eventosapp_whatsapp_templates_redirect_virtual_access');
add_action('admin_post_eventosapp_whatsapp_virtual_access', 'eventosapp_whatsapp_templates_redirect_virtual_access');

function eventosapp_whatsapp_templates_redirect_virtual_access() {
    $ticket_id = eventosapp_whatsapp_templates_resolve_ticket_from_request();
    if ( ! $ticket_id ) {
        status_header(404);
        wp_die('Ticket no encontrado.');
    }

    $assets = eventosapp_whatsapp_templates_get_ticket_assets($ticket_id);
    $target = ! empty($assets['virtual_landing']) ? $assets['virtual_landing'] : $assets['platform_url'];

    if ( empty($target) ) {
        status_header(404);
        wp_die('No se encontró enlace virtual para este ticket.');
    }

    wp_safe_redirect($target);
    exit;
}
