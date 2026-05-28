<?php
/**
 * EventosApp - WhatsApp Flows
 *
 * Módulo independiente para crear, sincronizar, publicar, enviar y recibir
 * respuestas de WhatsApp Flows sin tocar la administración existente de
 * plantillas ni el flujo actual de tickets por WhatsApp.
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! defined('EVENTOSAPP_WHATSAPP_FLOWS_POST_TYPE') ) {
    define('EVENTOSAPP_WHATSAPP_FLOWS_POST_TYPE', 'eventosapp_wa_flow');
}

if ( ! defined('EVENTOSAPP_WHATSAPP_FLOWS_TABLE_VERSION') ) {
    define('EVENTOSAPP_WHATSAPP_FLOWS_TABLE_VERSION', '2026.05.28.1');
}

/**
 * Tablas propias del módulo.
 */
function eventosapp_whatsapp_flows_sends_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'eventosapp_whatsapp_flow_sends';
}

function eventosapp_whatsapp_flows_responses_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'eventosapp_whatsapp_flow_responses';
}

function eventosapp_whatsapp_flows_install_tables() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $sends_table     = eventosapp_whatsapp_flows_sends_table_name();
    $responses_table = eventosapp_whatsapp_flows_responses_table_name();

    $sql_sends = "CREATE TABLE {$sends_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        flow_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        meta_flow_id VARCHAR(120) NOT NULL DEFAULT '',
        event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        ticket_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        phone VARCHAR(80) NOT NULL DEFAULT '',
        sender_phone_number_id VARCHAR(80) NOT NULL DEFAULT '',
        flow_token VARCHAR(190) NOT NULL DEFAULT '',
        wa_message_id VARCHAR(220) NOT NULL DEFAULT '',
        send_mode VARCHAR(80) NOT NULL DEFAULT 'direct_flow',
        status VARCHAR(80) NOT NULL DEFAULT 'created',
        delivery_status VARCHAR(80) NOT NULL DEFAULT '',
        response_received TINYINT(1) NOT NULL DEFAULT 0,
        request_json LONGTEXT NULL,
        response_json LONGTEXT NULL,
        error_message TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        responded_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY flow_token (flow_token),
        KEY flow_post_id (flow_post_id),
        KEY meta_flow_id (meta_flow_id),
        KEY event_id (event_id),
        KEY ticket_id (ticket_id),
        KEY phone (phone),
        KEY sender_phone_number_id (sender_phone_number_id),
        KEY wa_message_id (wa_message_id(190)),
        KEY status (status),
        KEY delivery_status (delivery_status),
        KEY response_received (response_received),
        KEY created_at (created_at)
    ) {$charset_collate};";

    $sql_responses = "CREATE TABLE {$responses_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        send_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        flow_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        meta_flow_id VARCHAR(120) NOT NULL DEFAULT '',
        event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        ticket_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        phone VARCHAR(80) NOT NULL DEFAULT '',
        flow_token VARCHAR(190) NOT NULL DEFAULT '',
        wa_message_id VARCHAR(220) NOT NULL DEFAULT '',
        reply_to_message_id VARCHAR(220) NOT NULL DEFAULT '',
        response_json LONGTEXT NULL,
        response_summary LONGTEXT NULL,
        raw_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY wa_message_id (wa_message_id(190)),
        KEY send_id (send_id),
        KEY flow_post_id (flow_post_id),
        KEY meta_flow_id (meta_flow_id),
        KEY event_id (event_id),
        KEY ticket_id (ticket_id),
        KEY phone (phone),
        KEY flow_token (flow_token),
        KEY created_at (created_at)
    ) {$charset_collate};";

    if ( ! function_exists('dbDelta') ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    dbDelta($sql_sends);
    dbDelta($sql_responses);
    update_option('eventosapp_whatsapp_flows_table_version', EVENTOSAPP_WHATSAPP_FLOWS_TABLE_VERSION, false);
}

function eventosapp_whatsapp_flows_maybe_install_tables() {
    if ( get_option('eventosapp_whatsapp_flows_table_version') !== EVENTOSAPP_WHATSAPP_FLOWS_TABLE_VERSION ) {
        eventosapp_whatsapp_flows_install_tables();
    }
}
add_action('init', 'eventosapp_whatsapp_flows_maybe_install_tables', 7);

/**
 * CPT interno para conservar la configuración local de cada Flow.
 */
add_action('init', function() {
    register_post_type(EVENTOSAPP_WHATSAPP_FLOWS_POST_TYPE, [
        'labels' => [
            'name'          => 'WhatsApp Flows',
            'singular_name' => 'WhatsApp Flow',
        ],
        'public'       => false,
        'show_ui'      => false,
        'show_in_menu' => false,
        'supports'     => ['title'],
        'has_archive'  => false,
        'rewrite'      => false,
        'show_in_rest' => false,
    ]);
}, 8);

add_action('admin_menu', function() {
    add_submenu_page(
        'eventosapp_dashboard',
        'WhatsApp Flows',
        'WhatsApp Flows',
        'manage_options',
        'eventosapp_whatsapp_flows',
        'eventosapp_whatsapp_flows_render_page',
        24
    );
}, 22);

function eventosapp_whatsapp_flows_categories() {
    return [
        'SURVEY'              => 'Encuesta',
        'LEAD_GENERATION'     => 'Captura de leads',
        'CONTACT_US'          => 'Contacto',
        'CUSTOMER_SUPPORT'    => 'Atención al cliente',
        'APPOINTMENT_BOOKING' => 'Reserva de cita',
        'SIGN_UP'             => 'Registro',
        'SIGN_IN'             => 'Inicio de sesión',
        'OTHER'               => 'Otro',
    ];
}

function eventosapp_whatsapp_flows_question_types() {
    return [
        'radio'    => 'Selección única',
        'checkbox' => 'Selección múltiple',
        'dropdown' => 'Lista desplegable',
        'text'     => 'Texto corto',
        'textarea' => 'Texto largo',
    ];
}

function eventosapp_whatsapp_flows_default_questions() {
    return [
        [
            'slug'     => 'satisfaccion_general',
            'label'    => '¿Cómo calificas tu experiencia general en el evento?',
            'type'     => 'radio',
            'required' => '1',
            'options'  => [
                ['id' => 'excelente', 'title' => 'Excelente'],
                ['id' => 'buena', 'title' => 'Buena'],
                ['id' => 'regular', 'title' => 'Regular'],
                ['id' => 'mala', 'title' => 'Mala'],
            ],
        ],
        [
            'slug'     => 'recomendacion',
            'label'    => '¿Recomendarías este evento?',
            'type'     => 'radio',
            'required' => '1',
            'options'  => [
                ['id' => 'si', 'title' => 'Sí'],
                ['id' => 'no', 'title' => 'No'],
            ],
        ],
        [
            'slug'     => 'comentarios',
            'label'    => 'Cuéntanos algún comentario adicional',
            'type'     => 'textarea',
            'required' => '0',
            'options'  => [],
        ],
    ];
}


function eventosapp_whatsapp_flows_text_limit($text, $length) {
    $text = (string) $text;
    $length = max(1, absint($length));
    if ( function_exists('mb_substr') ) {
        return mb_substr($text, 0, $length);
    }
    return substr($text, 0, $length);
}

function eventosapp_whatsapp_flows_json_encode($value, $pretty = false) {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ( $pretty ) {
        $flags |= JSON_PRETTY_PRINT;
    }
    return wp_json_encode($value, $flags);
}

function eventosapp_whatsapp_flows_clean_phone($phone) {
    if ( function_exists('eventosapp_whatsapp_normalize_phone') ) {
        $settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];
        return eventosapp_whatsapp_normalize_phone($phone, $settings['default_country_code'] ?? '57');
    }
    return preg_replace('/\D+/', '', (string) $phone);
}

function eventosapp_whatsapp_flows_sanitize_slug($value, $fallback = '') {
    $slug = sanitize_key((string) $value);
    if ( $slug === '' ) {
        $slug = sanitize_key((string) $fallback);
    }
    return $slug !== '' ? $slug : 'campo';
}

function eventosapp_whatsapp_flows_normalize_options($raw_options) {
    $options = [];
    if ( is_string($raw_options) ) {
        $lines = preg_split('/\r\n|\r|\n/', $raw_options);
        foreach ( $lines as $line ) {
            $line = trim((string) $line);
            if ( $line === '' ) {
                continue;
            }
            $id = sanitize_key(remove_accents($line));
            if ( $id === '' ) {
                $id = 'opcion_' . (count($options) + 1);
            }
            $options[] = [
                'id'    => $id,
                'title' => sanitize_text_field($line),
            ];
        }
    } elseif ( is_array($raw_options) ) {
        foreach ( $raw_options as $idx => $option ) {
            if ( is_array($option) ) {
                $title = sanitize_text_field((string)($option['title'] ?? ($option['label'] ?? '')));
                $id    = sanitize_key((string)($option['id'] ?? ''));
            } else {
                $title = sanitize_text_field((string) $option);
                $id    = sanitize_key(remove_accents($title));
            }
            if ( $title === '' ) {
                continue;
            }
            if ( $id === '' ) {
                $id = 'opcion_' . ((int) $idx + 1);
            }
            $options[] = [
                'id'    => $id,
                'title' => $title,
            ];
        }
    }

    return array_slice($options, 0, 20);
}

function eventosapp_whatsapp_flows_normalize_questions($raw_questions) {
    $questions = [];
    $types = eventosapp_whatsapp_flows_question_types();

    if ( ! is_array($raw_questions) ) {
        return eventosapp_whatsapp_flows_default_questions();
    }

    foreach ( $raw_questions as $index => $question ) {
        if ( ! is_array($question) ) {
            continue;
        }

        $label = sanitize_text_field((string)($question['label'] ?? ''));
        if ( $label === '' ) {
            continue;
        }

        $type = sanitize_key((string)($question['type'] ?? 'radio'));
        if ( ! isset($types[$type]) ) {
            $type = 'radio';
        }

        $slug = eventosapp_whatsapp_flows_sanitize_slug($question['slug'] ?? '', 'pregunta_' . ((int) $index + 1));
        $options = eventosapp_whatsapp_flows_normalize_options($question['options'] ?? []);

        if ( in_array($type, ['radio', 'checkbox', 'dropdown'], true) && empty($options) ) {
            $options = [
                ['id' => 'opcion_1', 'title' => 'Opción 1'],
                ['id' => 'opcion_2', 'title' => 'Opción 2'],
            ];
        }

        $questions[] = [
            'slug'     => $slug,
            'label'    => $label,
            'type'     => $type,
            'required' => ! empty($question['required']) && $question['required'] !== '0' ? '1' : '0',
            'options'  => $options,
        ];
    }

    return ! empty($questions) ? array_slice($questions, 0, 30) : eventosapp_whatsapp_flows_default_questions();
}

function eventosapp_whatsapp_flows_get_all_for_select() {
    $posts = get_posts([
        'post_type'      => EVENTOSAPP_WHATSAPP_FLOWS_POST_TYPE,
        'post_status'    => ['publish', 'draft', 'private'],
        'posts_per_page' => 200,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    $items = [];
    foreach ( $posts as $post ) {
        $items[$post->ID] = [
            'id'           => $post->ID,
            'title'        => get_the_title($post),
            'meta_flow_id' => get_post_meta($post->ID, '_eventosapp_wa_flow_meta_id', true),
            'status'       => get_post_meta($post->ID, '_eventosapp_wa_flow_status', true),
        ];
    }
    return $items;
}

function eventosapp_whatsapp_flows_get_flow_config($flow_post_id) {
    $flow_post_id = absint($flow_post_id);
    if ( ! $flow_post_id || get_post_type($flow_post_id) !== EVENTOSAPP_WHATSAPP_FLOWS_POST_TYPE ) {
        return [];
    }

    $questions = get_post_meta($flow_post_id, '_eventosapp_wa_flow_questions', true);
    if ( ! is_array($questions) ) {
        $questions = eventosapp_whatsapp_flows_default_questions();
    }

    $config = [
        'id'                     => $flow_post_id,
        'title'                  => get_the_title($flow_post_id),
        'description'            => get_post_meta($flow_post_id, '_eventosapp_wa_flow_description', true),
        'category'               => get_post_meta($flow_post_id, '_eventosapp_wa_flow_category', true) ?: 'SURVEY',
        'cta'                    => get_post_meta($flow_post_id, '_eventosapp_wa_flow_cta', true) ?: 'Responder encuesta',
        'submit_label'           => get_post_meta($flow_post_id, '_eventosapp_wa_flow_submit_label', true) ?: 'Enviar respuestas',
        'screen_id'              => get_post_meta($flow_post_id, '_eventosapp_wa_flow_screen_id', true) ?: 'SURVEY',
        'status'                 => get_post_meta($flow_post_id, '_eventosapp_wa_flow_status', true) ?: 'local_draft',
        'meta_flow_id'           => get_post_meta($flow_post_id, '_eventosapp_wa_flow_meta_id', true),
        'waba_id'                => get_post_meta($flow_post_id, '_eventosapp_wa_flow_waba_id', true),
        'sender_phone_number_id' => get_post_meta($flow_post_id, '_eventosapp_wa_flow_sender_phone_number_id', true),
        'preview_url'            => get_post_meta($flow_post_id, '_eventosapp_wa_flow_preview_url', true),
        'last_meta_response'     => get_post_meta($flow_post_id, '_eventosapp_wa_flow_last_meta_response', true),
        'validation_errors'      => get_post_meta($flow_post_id, '_eventosapp_wa_flow_validation_errors', true),
        'questions'              => eventosapp_whatsapp_flows_normalize_questions($questions),
        'created_at_meta'        => get_post_meta($flow_post_id, '_eventosapp_wa_flow_created_at_meta', true),
        'published_at'           => get_post_meta($flow_post_id, '_eventosapp_wa_flow_published_at', true),
        'last_sync_at'           => get_post_meta($flow_post_id, '_eventosapp_wa_flow_last_sync_at', true),
    ];

    $config['category'] = array_key_exists($config['category'], eventosapp_whatsapp_flows_categories()) ? $config['category'] : 'SURVEY';
    $config['screen_id'] = eventosapp_whatsapp_flows_sanitize_slug($config['screen_id'], 'SURVEY');
    $config['screen_id'] = strtoupper($config['screen_id']);

    return $config;
}

function eventosapp_whatsapp_flows_build_flow_json($flow_post_id, $override_config = []) {
    $config = $flow_post_id ? eventosapp_whatsapp_flows_get_flow_config($flow_post_id) : [];
    $config = wp_parse_args($override_config, $config);

    $title        = sanitize_text_field((string)($config['title'] ?? 'Encuesta del evento'));
    $description  = sanitize_textarea_field((string)($config['description'] ?? 'Completa esta breve encuesta.'));
    $submit_label = sanitize_text_field((string)($config['submit_label'] ?? 'Enviar respuestas'));
    $screen_id    = eventosapp_whatsapp_flows_sanitize_slug($config['screen_id'] ?? 'SURVEY', 'SURVEY');
    $screen_id    = strtoupper($screen_id);
    $questions    = eventosapp_whatsapp_flows_normalize_questions($config['questions'] ?? []);

    $form_children = [];
    $payload = [
        'eventosapp_flow_post_id' => (string) absint($flow_post_id),
    ];

    foreach ( $questions as $index => $question ) {
        $slug = eventosapp_whatsapp_flows_sanitize_slug($question['slug'] ?? '', 'pregunta_' . ((int) $index + 1));
        $label = sanitize_text_field((string)($question['label'] ?? 'Pregunta'));
        $required = ! empty($question['required']) && $question['required'] !== '0';
        $type = sanitize_key((string)($question['type'] ?? 'radio'));
        $component = [
            'name'     => $slug,
            'label'    => $label,
            'required' => $required,
        ];

        if ( $type === 'text' ) {
            $component = array_merge(['type' => 'TextInput', 'input-type' => 'text'], $component);
        } elseif ( $type === 'textarea' ) {
            $component = array_merge(['type' => 'TextArea'], $component);
        } elseif ( $type === 'checkbox' ) {
            $component = array_merge(['type' => 'CheckboxGroup'], $component);
            $component['data-source'] = $question['options'];
        } elseif ( $type === 'dropdown' ) {
            $component = array_merge(['type' => 'Dropdown'], $component);
            $component['data-source'] = $question['options'];
        } else {
            $component = array_merge(['type' => 'RadioButtonsGroup'], $component);
            $component['data-source'] = $question['options'];
        }

        $form_children[] = $component;
        $payload[$slug] = '${form.' . $slug . '}';
    }

    $form_children[] = [
        'type'            => 'Footer',
        'label'           => $submit_label !== '' ? $submit_label : 'Enviar respuestas',
        'on-click-action' => [
            'name'    => 'complete',
            'payload' => $payload,
        ],
    ];

    $children = [];
    if ( $title !== '' ) {
        $children[] = ['type' => 'TextHeading', 'text' => $title];
    }
    if ( $description !== '' ) {
        $children[] = ['type' => 'TextBody', 'text' => $description];
    }
    $children[] = [
        'type'     => 'Form',
        'name'     => 'eventosapp_survey_form',
        'children' => $form_children,
    ];

    return [
        'version' => '7.1',
        'screens' => [
            [
                'id'       => $screen_id,
                'title'    => $title !== '' ? eventosapp_whatsapp_flows_text_limit($title, 35) : 'Encuesta',
                'terminal' => true,
                'success'  => true,
                'data'     => new stdClass(),
                'layout'   => [
                    'type'     => 'SingleColumnLayout',
                    'children' => $children,
                ],
            ],
        ],
    ];
}

function eventosapp_whatsapp_flows_write_temp_flow_json($flow_post_id) {
    $upload_dir = wp_upload_dir();
    if ( empty($upload_dir['basedir']) || ! wp_mkdir_p($upload_dir['basedir'] . '/eventosapp-whatsapp-flows') ) {
        return new WP_Error('flow_json_dir', 'No se pudo crear la carpeta temporal para el JSON del Flow.');
    }

    $json = eventosapp_whatsapp_flows_json_encode(eventosapp_whatsapp_flows_build_flow_json($flow_post_id), true);
    $path = trailingslashit($upload_dir['basedir']) . 'eventosapp-whatsapp-flows/flow-' . absint($flow_post_id) . '-' . time() . '.json';
    $saved = file_put_contents($path, $json);

    if ( $saved === false ) {
        return new WP_Error('flow_json_write', 'No se pudo escribir el archivo JSON temporal.');
    }

    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_json', $json);
    return $path;
}

function eventosapp_whatsapp_flows_add_activity($event, $context = []) {
    if ( function_exists('eventosapp_whatsapp_add_activity_log') ) {
        eventosapp_whatsapp_add_activity_log($event, $context);
    }
    if ( function_exists('eventosapp_whatsapp_log') ) {
        eventosapp_whatsapp_log('WhatsApp Flow | ' . $event, $context);
    }
}

function eventosapp_whatsapp_flows_get_effective_settings($event_id = 0, $sender_phone_number_id = '') {
    $settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];

    if ( $sender_phone_number_id !== '' && function_exists('eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id') ) {
        return eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id($sender_phone_number_id, $settings);
    }

    if ( $event_id && function_exists('eventosapp_whatsapp_resolve_sender_settings') ) {
        return eventosapp_whatsapp_resolve_sender_settings($event_id, $settings);
    }

    return $settings;
}

function eventosapp_whatsapp_flows_graph_request($method, $path, $body = null, $settings = null) {
    if ( ! function_exists('eventosapp_whatsapp_graph_api_request') ) {
        return [
            'ok'        => false,
            'http_code' => 0,
            'message'   => 'No está disponible el cliente Graph API de WhatsApp Tickets.',
            'response'  => null,
        ];
    }
    return eventosapp_whatsapp_graph_api_request($method, $path, $body, $settings);
}

function eventosapp_whatsapp_flows_graph_multipart_file_request($method, $path, $file_path, $fields = [], $settings = null) {
    $method = strtoupper((string) $method);
    $settings = is_array($settings) ? wp_parse_args($settings, eventosapp_whatsapp_default_settings()) : (function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : []);

    $access_token = trim((string)($settings['access_token'] ?? ''));
    $api_version = preg_replace('/[^a-zA-Z0-9\.\-_]/', '', (string)($settings['api_version'] ?? 'v23.0'));
    $timeout = min(90, max(10, absint($settings['request_timeout'] ?? 30)));

    if ( $api_version === '' ) {
        $api_version = 'v23.0';
    }

    if ( $access_token === '' ) {
        return [
            'ok'        => false,
            'http_code' => 0,
            'message'   => 'Falta Access Token en WhatsApp Tickets.',
            'response'  => null,
        ];
    }

    if ( ! is_readable($file_path) ) {
        return [
            'ok'        => false,
            'http_code' => 0,
            'message'   => 'El archivo JSON del Flow no existe o no es legible.',
            'response'  => null,
        ];
    }

    $endpoint = sprintf('https://graph.facebook.com/%s/%s', rawurlencode($api_version), ltrim((string) $path, '/'));

    if ( function_exists('curl_init') && function_exists('curl_file_create') ) {
        $post_fields = [];
        foreach ( $fields as $key => $value ) {
            $post_fields[$key] = (string) $value;
        }
        $post_fields['file'] = curl_file_create($file_path, 'application/json', 'flow.json');

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        $raw_body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ( $errno ) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'message'   => 'cURL: ' . $error,
                'response'  => null,
                'endpoint'  => $endpoint,
            ];
        }

        $decoded = json_decode((string) $raw_body, true);
        $ok = $code >= 200 && $code < 300;
        return [
            'ok'        => $ok,
            'http_code' => $code,
            'message'   => $ok ? 'Solicitud aceptada por Meta.' : (function_exists('eventosapp_whatsapp_extract_api_error') ? eventosapp_whatsapp_extract_api_error($decoded, (string) $raw_body, $code) : 'Meta API HTTP ' . $code),
            'response'  => $decoded ?: $raw_body,
            'endpoint'  => $endpoint,
        ];
    }

    $boundary = wp_generate_password(24, false, false);
    $body = '';
    foreach ( $fields as $key => $value ) {
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="' . sanitize_key($key) . '"' . "\r\n\r\n";
        $body .= (string) $value . "\r\n";
    }
    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Disposition: form-data; name="file"; filename="flow.json"' . "\r\n";
    $body .= 'Content-Type: application/json' . "\r\n\r\n";
    $body .= file_get_contents($file_path) . "\r\n";
    $body .= '--' . $boundary . '--' . "\r\n";

    $response = wp_remote_request($endpoint, [
        'method'  => $method,
        'timeout' => $timeout,
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
        ],
        'body' => $body,
    ]);

    if ( is_wp_error($response) ) {
        return [
            'ok'        => false,
            'http_code' => 0,
            'message'   => $response->get_error_message(),
            'response'  => null,
            'endpoint'  => $endpoint,
        ];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw_body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($raw_body, true);
    $ok = $code >= 200 && $code < 300;

    return [
        'ok'        => $ok,
        'http_code' => $code,
        'message'   => $ok ? 'Solicitud aceptada por Meta.' : (function_exists('eventosapp_whatsapp_extract_api_error') ? eventosapp_whatsapp_extract_api_error($decoded, $raw_body, $code) : 'Meta API HTTP ' . $code),
        'response'  => $decoded ?: $raw_body,
        'endpoint'  => $endpoint,
    ];
}

function eventosapp_whatsapp_flows_extract_meta_flow_id($response) {
    if ( ! is_array($response) ) {
        return '';
    }
    foreach ( ['id', 'flow_id'] as $key ) {
        if ( ! empty($response[$key]) ) {
            return preg_replace('/\D+/', '', (string) $response[$key]);
        }
    }
    if ( ! empty($response['data']['id']) ) {
        return preg_replace('/\D+/', '', (string) $response['data']['id']);
    }
    return '';
}

function eventosapp_whatsapp_flows_notice_redirect($args = []) {
    $args = wp_parse_args($args, [
        'page' => 'eventosapp_whatsapp_flows',
    ]);
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}

function eventosapp_whatsapp_flows_make_flow_token($flow_post_id, $ticket_id = 0, $event_id = 0) {
    $parts = [
        'evappflow',
        absint($flow_post_id),
        absint($event_id),
        absint($ticket_id),
        wp_generate_password(18, false, false),
        time(),
    ];
    return substr(sanitize_key(implode('_', $parts)), 0, 180);
}

function eventosapp_whatsapp_flows_get_ticket_context($ticket_id) {
    $ticket_id = absint($ticket_id);
    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        return [];
    }

    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $first = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
    $last = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);

    return [
        'ticket_id'    => $ticket_id,
        'event_id'     => $event_id,
        'event_name'   => $event_id ? get_the_title($event_id) : '',
        'ticket_code'  => function_exists('eventosapp_whatsapp_get_ticket_public_code') ? eventosapp_whatsapp_get_ticket_public_code($ticket_id) : get_post_meta($ticket_id, 'eventosapp_ticketID', true),
        'name'         => trim($first . ' ' . $last),
        'email'        => get_post_meta($ticket_id, '_eventosapp_asistente_email', true),
        'phone'        => get_post_meta($ticket_id, '_eventosapp_asistente_tel', true),
        'document'     => get_post_meta($ticket_id, '_eventosapp_asistente_cc', true),
        'company'      => get_post_meta($ticket_id, '_eventosapp_asistente_empresa', true),
        'position'     => get_post_meta($ticket_id, '_eventosapp_asistente_cargo', true),
        'city'         => get_post_meta($ticket_id, '_eventosapp_asistente_ciudad', true),
        'country'      => get_post_meta($ticket_id, '_eventosapp_asistente_pais', true),
        'localidad'    => get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true),
        'modalidad'    => function_exists('eventosapp_get_ticket_modalidad') ? eventosapp_get_ticket_modalidad($ticket_id) : get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true),
    ];
}

function eventosapp_whatsapp_flows_replace_vars($text, $context = []) {
    $text = (string) $text;
    $replacements = [];
    foreach ( $context as $key => $value ) {
        if ( is_scalar($value) ) {
            $replacements['{{' . $key . '}}'] = (string) $value;
        }
    }
    return strtr($text, $replacements);
}

function eventosapp_whatsapp_flows_insert_send_row($data) {
    global $wpdb;
    eventosapp_whatsapp_flows_maybe_install_tables();

    $now = current_time('mysql');
    $row = [
        'flow_post_id'           => absint($data['flow_post_id'] ?? 0),
        'meta_flow_id'           => sanitize_text_field((string)($data['meta_flow_id'] ?? '')),
        'event_id'               => absint($data['event_id'] ?? 0),
        'ticket_id'              => absint($data['ticket_id'] ?? 0),
        'phone'                  => sanitize_text_field((string)($data['phone'] ?? '')),
        'sender_phone_number_id' => sanitize_text_field((string)($data['sender_phone_number_id'] ?? '')),
        'flow_token'             => sanitize_text_field((string)($data['flow_token'] ?? '')),
        'wa_message_id'          => sanitize_text_field((string)($data['wa_message_id'] ?? '')),
        'send_mode'              => sanitize_key((string)($data['send_mode'] ?? 'direct_flow')),
        'status'                 => sanitize_key((string)($data['status'] ?? 'created')),
        'delivery_status'        => sanitize_key((string)($data['delivery_status'] ?? '')),
        'response_received'      => ! empty($data['response_received']) ? 1 : 0,
        'request_json'           => isset($data['request_json']) ? (string) $data['request_json'] : '',
        'response_json'          => isset($data['response_json']) ? (string) $data['response_json'] : '',
        'error_message'          => isset($data['error_message']) ? sanitize_textarea_field((string) $data['error_message']) : '',
        'created_at'             => $data['created_at'] ?? $now,
        'updated_at'             => $data['updated_at'] ?? $now,
        'responded_at'           => $data['responded_at'] ?? null,
    ];

    $wpdb->insert(eventosapp_whatsapp_flows_sends_table_name(), $row, [
        '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s',
    ]);

    return (int) $wpdb->insert_id;
}

function eventosapp_whatsapp_flows_update_send_row($send_id, $data) {
    global $wpdb;
    $send_id = absint($send_id);
    if ( ! $send_id ) {
        return false;
    }

    $allowed = [
        'wa_message_id'     => '%s',
        'status'            => '%s',
        'delivery_status'   => '%s',
        'response_received' => '%d',
        'request_json'      => '%s',
        'response_json'     => '%s',
        'error_message'     => '%s',
        'updated_at'        => '%s',
        'responded_at'      => '%s',
    ];

    $row = [];
    $formats = [];
    foreach ( $allowed as $key => $format ) {
        if ( array_key_exists($key, $data) ) {
            $row[$key] = $format === '%d' ? absint($data[$key]) : (string) $data[$key];
            $formats[] = $format;
        }
    }
    $row['updated_at'] = $row['updated_at'] ?? current_time('mysql');
    if ( ! in_array('%s', $formats, true) || ! array_key_exists('updated_at', $data) ) {
        $formats[] = '%s';
    }

    if ( empty($row) ) {
        return false;
    }

    return $wpdb->update(eventosapp_whatsapp_flows_sends_table_name(), $row, ['id' => $send_id], $formats, ['%d']) !== false;
}

function eventosapp_whatsapp_flows_find_send($args = []) {
    global $wpdb;
    eventosapp_whatsapp_flows_maybe_install_tables();

    $table = eventosapp_whatsapp_flows_sends_table_name();
    $where = [];
    $values = [];

    if ( ! empty($args['flow_token']) ) {
        $where[] = 'flow_token = %s';
        $values[] = sanitize_text_field((string) $args['flow_token']);
    }
    if ( ! empty($args['wa_message_id']) ) {
        $where[] = 'wa_message_id = %s';
        $values[] = sanitize_text_field((string) $args['wa_message_id']);
    }
    if ( ! empty($args['phone']) ) {
        $where[] = 'phone = %s';
        $values[] = sanitize_text_field((string) $args['phone']);
    }
    if ( ! empty($args['event_id']) ) {
        $where[] = 'event_id = %d';
        $values[] = absint($args['event_id']);
    }
    if ( ! empty($args['ticket_id']) ) {
        $where[] = 'ticket_id = %d';
        $values[] = absint($args['ticket_id']);
    }

    if ( empty($where) ) {
        return null;
    }

    $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 1';
    return $wpdb->get_row($wpdb->prepare($sql, $values), ARRAY_A);
}

function eventosapp_whatsapp_flows_find_latest_open_send_by_phone($phone) {
    global $wpdb;
    eventosapp_whatsapp_flows_maybe_install_tables();
    $phone = sanitize_text_field((string) $phone);
    if ( $phone === '' ) {
        return null;
    }
    $table = eventosapp_whatsapp_flows_sends_table_name();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE phone = %s AND response_received = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) ORDER BY id DESC LIMIT 1",
        $phone
    ), ARRAY_A);
}

function eventosapp_whatsapp_flows_send_direct_flow($flow_post_id, $to, $args = []) {
    $flow_post_id = absint($flow_post_id);
    $to = eventosapp_whatsapp_flows_clean_phone($to);
    $args = is_array($args) ? $args : [];

    if ( ! $flow_post_id || get_post_type($flow_post_id) !== EVENTOSAPP_WHATSAPP_FLOWS_POST_TYPE ) {
        return ['ok' => false, 'message' => 'Flow local inválido.'];
    }
    if ( $to === '' ) {
        return ['ok' => false, 'message' => 'El teléfono del destinatario está vacío o no es válido.'];
    }
    if ( ! function_exists('eventosapp_whatsapp_api_send_message') ) {
        return ['ok' => false, 'message' => 'No está disponible el envío base de WhatsApp.'];
    }

    $config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);
    $meta_flow_id = preg_replace('/\D+/', '', (string)($config['meta_flow_id'] ?? ''));
    if ( $meta_flow_id === '' ) {
        return ['ok' => false, 'message' => 'El Flow aún no tiene Flow ID de Meta. Primero créalo en Meta.'];
    }

    $ticket_id = absint($args['ticket_id'] ?? 0);
    $event_id = absint($args['event_id'] ?? 0);
    if ( $ticket_id && ! $event_id ) {
        $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    }

    $context = $ticket_id ? eventosapp_whatsapp_flows_get_ticket_context($ticket_id) : [];
    $context['event_id'] = $event_id ?: ($context['event_id'] ?? 0);
    $context['event_name'] = $context['event_name'] ?? ($event_id ? get_the_title($event_id) : '');
    $context['flow_name'] = $config['title'];

    $settings = eventosapp_whatsapp_flows_get_effective_settings($event_id, $args['sender_phone_number_id'] ?? ($config['sender_phone_number_id'] ?? ''));
    $sender_phone_number_id = function_exists('eventosapp_whatsapp_sanitize_phone_number_id') ? eventosapp_whatsapp_sanitize_phone_number_id($settings['phone_number_id'] ?? '') : preg_replace('/\D+/', '', (string)($settings['phone_number_id'] ?? ''));

    $flow_token = ! empty($args['flow_token']) ? sanitize_text_field((string) $args['flow_token']) : eventosapp_whatsapp_flows_make_flow_token($flow_post_id, $ticket_id, $event_id);
    $header_text = sanitize_text_field(eventosapp_whatsapp_flows_replace_vars($args['header_text'] ?? $config['title'], $context));
    $body_text = sanitize_textarea_field(eventosapp_whatsapp_flows_replace_vars($args['body_text'] ?? $config['description'], $context));
    $footer_text = sanitize_text_field(eventosapp_whatsapp_flows_replace_vars($args['footer_text'] ?? 'Responde desde WhatsApp de forma rápida y segura.', $context));
    $cta = sanitize_text_field((string)($args['cta'] ?? $config['cta']));
    $screen_id = strtoupper(eventosapp_whatsapp_flows_sanitize_slug($config['screen_id'] ?? 'SURVEY', 'SURVEY'));

    $payload = [
        'type' => 'interactive',
        'interactive' => [
            'type' => 'flow',
            'header' => [
                'type' => 'text',
                'text' => eventosapp_whatsapp_flows_text_limit($header_text !== '' ? $header_text : 'Encuesta', 60),
            ],
            'body' => [
                'text' => eventosapp_whatsapp_flows_text_limit($body_text !== '' ? $body_text : 'Completa esta encuesta.', 1024),
            ],
            'footer' => [
                'text' => eventosapp_whatsapp_flows_text_limit($footer_text, 60),
            ],
            'action' => [
                'name' => 'flow',
                'parameters' => [
                    'flow_message_version' => '3',
                    'flow_id'              => $meta_flow_id,
                    'flow_token'           => $flow_token,
                    'flow_cta'             => eventosapp_whatsapp_flows_text_limit($cta !== '' ? $cta : 'Responder', 30),
                    'flow_action'          => 'navigate',
                    'flow_action_payload'  => [
                        'screen' => $screen_id,
                        'data'   => [
                            'eventosapp_flow_post_id' => (string) $flow_post_id,
                            'eventosapp_event_id'     => (string) $event_id,
                            'eventosapp_ticket_id'    => (string) $ticket_id,
                            'eventosapp_ticket_code'  => (string)($context['ticket_code'] ?? ''),
                        ],
                    ],
                ],
            ],
        ],
    ];

    $send_id = eventosapp_whatsapp_flows_insert_send_row([
        'flow_post_id'           => $flow_post_id,
        'meta_flow_id'           => $meta_flow_id,
        'event_id'               => $event_id,
        'ticket_id'              => $ticket_id,
        'phone'                  => $to,
        'sender_phone_number_id' => $sender_phone_number_id,
        'flow_token'             => $flow_token,
        'send_mode'              => sanitize_key((string)($args['send_mode'] ?? 'direct_flow')),
        'status'                 => 'queued',
        'request_json'           => eventosapp_whatsapp_flows_json_encode($payload, true),
    ]);

    $result = eventosapp_whatsapp_api_send_message($to, $payload, $settings);
    $message_id = ! empty($result['message_id']) ? sanitize_text_field((string) $result['message_id']) : '';

    eventosapp_whatsapp_flows_update_send_row($send_id, [
        'wa_message_id' => $message_id,
        'status'        => ! empty($result['ok']) ? 'sent_request' : 'failed_request',
        'response_json' => eventosapp_whatsapp_flows_json_encode($result['response'] ?? $result, true),
        'error_message' => empty($result['ok']) ? (string)($result['message'] ?? 'Error al enviar Flow.') : '',
    ]);

    if ( $message_id !== '' && function_exists('eventosapp_whatsapp_register_message_map') ) {
        eventosapp_whatsapp_register_message_map($message_id, $ticket_id, 'whatsapp_flow_direct', $to);
    }

    eventosapp_whatsapp_flows_add_activity(! empty($result['ok']) ? 'flow_envio_directo_solicitado' : 'flow_envio_directo_error', [
        'send_id'      => $send_id,
        'flow_post_id' => $flow_post_id,
        'meta_flow_id' => $meta_flow_id,
        'event_id'     => $event_id,
        'ticket_id'    => $ticket_id,
        'to'           => $to,
        'message_id'   => $message_id,
        'result'       => $result,
    ]);

    $result['send_id'] = $send_id;
    $result['flow_token'] = $flow_token;
    return $result;
}

function eventosapp_whatsapp_flows_get_recent_sends($flow_post_id = 0, $limit = 50) {
    global $wpdb;
    eventosapp_whatsapp_flows_maybe_install_tables();
    $table = eventosapp_whatsapp_flows_sends_table_name();
    $limit = min(200, max(1, absint($limit)));
    $flow_post_id = absint($flow_post_id);
    if ( $flow_post_id ) {
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE flow_post_id = %d ORDER BY id DESC LIMIT %d", $flow_post_id, $limit), ARRAY_A);
    }
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
}

function eventosapp_whatsapp_flows_get_recent_responses($flow_post_id = 0, $limit = 50) {
    global $wpdb;
    eventosapp_whatsapp_flows_maybe_install_tables();
    $table = eventosapp_whatsapp_flows_responses_table_name();
    $limit = min(200, max(1, absint($limit)));
    $flow_post_id = absint($flow_post_id);
    if ( $flow_post_id ) {
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE flow_post_id = %d ORDER BY id DESC LIMIT %d", $flow_post_id, $limit), ARRAY_A);
    }
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
}

function eventosapp_whatsapp_flows_get_stats($flow_post_id = 0) {
    global $wpdb;
    eventosapp_whatsapp_flows_maybe_install_tables();
    $sends_table = eventosapp_whatsapp_flows_sends_table_name();
    $responses_table = eventosapp_whatsapp_flows_responses_table_name();
    $flow_post_id = absint($flow_post_id);

    if ( $flow_post_id ) {
        $sent = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$sends_table} WHERE flow_post_id = %d", $flow_post_id));
        $answered = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$responses_table} WHERE flow_post_id = %d", $flow_post_id));
        $delivered = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$sends_table} WHERE flow_post_id = %d AND delivery_status IN ('delivered','read')", $flow_post_id));
        $read = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$sends_table} WHERE flow_post_id = %d AND delivery_status = 'read'", $flow_post_id));
    } else {
        $sent = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sends_table}");
        $answered = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$responses_table}");
        $delivered = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sends_table} WHERE delivery_status IN ('delivered','read')");
        $read = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sends_table} WHERE delivery_status = 'read'");
    }

    return [
        'sent'      => $sent,
        'delivered' => $delivered,
        'read'      => $read,
        'answered'  => $answered,
        'rate'      => $sent > 0 ? round(($answered / $sent) * 100, 2) : 0,
    ];
}

function eventosapp_whatsapp_flows_format_response_summary($decoded) {
    if ( is_string($decoded) ) {
        $maybe = json_decode($decoded, true);
        if ( is_array($maybe) ) {
            $decoded = $maybe;
        }
    }
    if ( ! is_array($decoded) ) {
        return '';
    }

    $skip_keys = ['flow_token', 'eventosapp_flow_post_id', 'eventosapp_event_id', 'eventosapp_ticket_id', 'eventosapp_ticket_code'];
    $lines = [];
    foreach ( $decoded as $key => $value ) {
        if ( in_array($key, $skip_keys, true) ) {
            continue;
        }
        $label = ucwords(str_replace('_', ' ', sanitize_key((string) $key)));
        if ( is_array($value) ) {
            $value = implode(', ', array_map('sanitize_text_field', array_map('strval', $value)));
        } elseif ( is_bool($value) ) {
            $value = $value ? 'Sí' : 'No';
        } else {
            $value = sanitize_text_field((string) $value);
        }
        if ( $value !== '' ) {
            $lines[] = $label . ': ' . $value;
        }
    }
    return implode("\n", $lines);
}

function eventosapp_whatsapp_flows_extract_nfm_response($message) {
    if ( ! is_array($message) || sanitize_key((string)($message['type'] ?? '')) !== 'interactive' ) {
        return null;
    }
    $interactive = isset($message['interactive']) && is_array($message['interactive']) ? $message['interactive'] : [];
    if ( sanitize_key((string)($interactive['type'] ?? '')) !== 'nfm_reply' ) {
        return null;
    }
    $reply = isset($interactive['nfm_reply']) && is_array($interactive['nfm_reply']) ? $interactive['nfm_reply'] : [];
    $raw_response = (string)($reply['response_json'] ?? '');
    $decoded = json_decode($raw_response, true);
    if ( ! is_array($decoded) ) {
        $decoded = [];
    }
    return [
        'name'          => sanitize_text_field((string)($reply['name'] ?? '')),
        'body'          => sanitize_textarea_field((string)($reply['body'] ?? '')),
        'response_raw'  => $raw_response,
        'response_json' => $decoded,
    ];
}

add_action('eventosapp_whatsapp_webhook_inbound_message_received', 'eventosapp_whatsapp_flows_handle_inbound_response', 8, 5);
function eventosapp_whatsapp_flows_handle_inbound_response($message, $value = [], $entry = [], $change = [], $payload = []) {
    $nfm = eventosapp_whatsapp_flows_extract_nfm_response($message);
    if ( ! $nfm ) {
        return;
    }

    global $wpdb;
    eventosapp_whatsapp_flows_maybe_install_tables();

    $from_phone = isset($message['from']) ? eventosapp_whatsapp_flows_clean_phone($message['from']) : '';
    $wa_message_id = sanitize_text_field((string)($message['id'] ?? ''));
    $reply_to_message_id = sanitize_text_field((string)($message['context']['id'] ?? ''));
    $created_at = ! empty($message['timestamp']) ? date_i18n('Y-m-d H:i:s', absint($message['timestamp'])) : current_time('mysql');
    $response = $nfm['response_json'];
    $flow_token = sanitize_text_field((string)($response['flow_token'] ?? ($response['flowToken'] ?? '')));

    $send = null;
    if ( $flow_token !== '' ) {
        $send = eventosapp_whatsapp_flows_find_send(['flow_token' => $flow_token]);
    }
    if ( ! $send && $reply_to_message_id !== '' ) {
        $send = eventosapp_whatsapp_flows_find_send(['wa_message_id' => $reply_to_message_id]);
    }
    if ( ! $send && $from_phone !== '' ) {
        $send = eventosapp_whatsapp_flows_find_latest_open_send_by_phone($from_phone);
    }

    $send_id = $send ? absint($send['id'] ?? 0) : 0;
    $flow_post_id = $send ? absint($send['flow_post_id'] ?? 0) : absint($response['eventosapp_flow_post_id'] ?? 0);
    $event_id = $send ? absint($send['event_id'] ?? 0) : absint($response['eventosapp_event_id'] ?? 0);
    $ticket_id = $send ? absint($send['ticket_id'] ?? 0) : absint($response['eventosapp_ticket_id'] ?? 0);
    $meta_flow_id = $send ? sanitize_text_field((string)($send['meta_flow_id'] ?? '')) : '';

    if ( $flow_token === '' && $send && ! empty($send['flow_token']) ) {
        $flow_token = sanitize_text_field((string) $send['flow_token']);
    }

    $exists = $wa_message_id !== '' ? (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . eventosapp_whatsapp_flows_responses_table_name() . ' WHERE wa_message_id = %s LIMIT 1',
        $wa_message_id
    )) : 0;
    if ( $exists ) {
        return;
    }

    $summary = eventosapp_whatsapp_flows_format_response_summary($response);
    $wpdb->insert(eventosapp_whatsapp_flows_responses_table_name(), [
        'send_id'             => $send_id,
        'flow_post_id'        => $flow_post_id,
        'meta_flow_id'        => $meta_flow_id,
        'event_id'            => $event_id,
        'ticket_id'           => $ticket_id,
        'phone'               => $from_phone,
        'flow_token'          => $flow_token,
        'wa_message_id'       => $wa_message_id,
        'reply_to_message_id' => $reply_to_message_id,
        'response_json'       => eventosapp_whatsapp_flows_json_encode($response, true),
        'response_summary'    => $summary,
        'raw_json'            => eventosapp_whatsapp_flows_json_encode([
            'message' => $message,
            'value'   => $value,
            'nfm'     => $nfm,
        ], true),
        'created_at'          => $created_at,
    ], ['%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

    $response_id = (int) $wpdb->insert_id;

    if ( $send_id ) {
        eventosapp_whatsapp_flows_update_send_row($send_id, [
            'response_received' => 1,
            'status'            => 'answered',
            'responded_at'      => $created_at,
        ]);
    }

    if ( $ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket' ) {
        update_post_meta($ticket_id, '_eventosapp_whatsapp_flow_last_response', [
            'response_id'  => $response_id,
            'flow_post_id' => $flow_post_id,
            'meta_flow_id' => $meta_flow_id,
            'summary'      => $summary,
            'response'     => $response,
            'created_at'   => $created_at,
        ]);
        if ( function_exists('eventosapp_whatsapp_add_ticket_log') ) {
            eventosapp_whatsapp_add_ticket_log($ticket_id, 'flow_response_received', 'Respuesta de WhatsApp Flow recibida.', [
                'context'      => 'whatsapp_flow',
                'flow_post_id' => $flow_post_id,
                'response_id'  => $response_id,
                'summary'      => $summary,
            ], $from_phone, []);
        }
    }

    eventosapp_whatsapp_flows_add_activity('flow_respuesta_recibida', [
        'response_id'         => $response_id,
        'send_id'             => $send_id,
        'flow_post_id'        => $flow_post_id,
        'meta_flow_id'        => $meta_flow_id,
        'event_id'            => $event_id,
        'ticket_id'           => $ticket_id,
        'from'                => $from_phone,
        'wa_message_id'       => $wa_message_id,
        'reply_to_message_id' => $reply_to_message_id,
        'summary'             => $summary,
    ]);
}

add_action('eventosapp_whatsapp_webhook_status_received', 'eventosapp_whatsapp_flows_handle_status_update', 10, 2);
function eventosapp_whatsapp_flows_handle_status_update($status, $mapped = []) {
    if ( ! is_array($status) ) {
        return;
    }
    $message_id = sanitize_text_field((string)($status['id'] ?? ''));
    $delivery_status = sanitize_key((string)($status['status'] ?? ''));
    if ( $message_id === '' || $delivery_status === '' ) {
        return;
    }
    $send = eventosapp_whatsapp_flows_find_send(['wa_message_id' => $message_id]);
    if ( ! $send ) {
        return;
    }
    eventosapp_whatsapp_flows_update_send_row(absint($send['id']), [
        'delivery_status' => $delivery_status,
        'status'          => $delivery_status === 'failed' ? 'failed_webhook' : 'webhook_' . $delivery_status,
        'error_message'   => ! empty($status['errors'][0]['message']) ? sanitize_text_field((string) $status['errors'][0]['message']) : '',
        'response_json'   => eventosapp_whatsapp_flows_json_encode($status, true),
    ]);
}

/**
 * Acciones administrativas.
 */
add_action('admin_post_eventosapp_whatsapp_flow_save', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    check_admin_referer('eventosapp_whatsapp_flow_save');

    $flow_post_id = absint($_POST['flow_post_id'] ?? 0);
    $title = sanitize_text_field((string)($_POST['flow_title'] ?? ''));
    if ( $title === '' ) {
        $title = 'Encuesta WhatsApp Flow ' . current_time('YmdHis');
    }

    if ( $flow_post_id && get_post_type($flow_post_id) === EVENTOSAPP_WHATSAPP_FLOWS_POST_TYPE ) {
        wp_update_post([
            'ID'         => $flow_post_id,
            'post_title' => $title,
        ]);
    } else {
        $flow_post_id = wp_insert_post([
            'post_type'   => EVENTOSAPP_WHATSAPP_FLOWS_POST_TYPE,
            'post_title'  => $title,
            'post_status' => 'publish',
        ]);
    }

    if ( is_wp_error($flow_post_id) || ! $flow_post_id ) {
        eventosapp_whatsapp_flows_notice_redirect(['flow_notice' => 'error', 'flow_message' => rawurlencode('No se pudo guardar el Flow local.')]);
    }

    $category = sanitize_key((string)($_POST['flow_category'] ?? 'SURVEY'));
    $categories = eventosapp_whatsapp_flows_categories();
    if ( ! isset($categories[$category]) ) {
        $category = 'SURVEY';
    }

    update_post_meta($flow_post_id, '_eventosapp_wa_flow_description', sanitize_textarea_field((string)($_POST['flow_description'] ?? '')));
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_category', $category);
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_cta', sanitize_text_field((string)($_POST['flow_cta'] ?? 'Responder encuesta')));
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_submit_label', sanitize_text_field((string)($_POST['flow_submit_label'] ?? 'Enviar respuestas')));
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_screen_id', strtoupper(eventosapp_whatsapp_flows_sanitize_slug($_POST['flow_screen_id'] ?? 'SURVEY', 'SURVEY')));
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_waba_id', function_exists('eventosapp_whatsapp_sanitize_waba_id') ? eventosapp_whatsapp_sanitize_waba_id($_POST['flow_waba_id'] ?? '') : preg_replace('/\D+/', '', (string)($_POST['flow_waba_id'] ?? '')));
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_sender_phone_number_id', function_exists('eventosapp_whatsapp_sanitize_phone_number_id') ? eventosapp_whatsapp_sanitize_phone_number_id($_POST['flow_sender_phone_number_id'] ?? '') : preg_replace('/\D+/', '', (string)($_POST['flow_sender_phone_number_id'] ?? '')));
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_questions', eventosapp_whatsapp_flows_normalize_questions($_POST['questions'] ?? []));
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_json', eventosapp_whatsapp_flows_json_encode(eventosapp_whatsapp_flows_build_flow_json($flow_post_id), true));
    if ( ! get_post_meta($flow_post_id, '_eventosapp_wa_flow_status', true) ) {
        update_post_meta($flow_post_id, '_eventosapp_wa_flow_status', 'local_draft');
    }

    eventosapp_whatsapp_flows_notice_redirect([
        'flow_id'      => $flow_post_id,
        'flow_notice'  => 'success',
        'flow_message' => rawurlencode('Flow guardado localmente.'),
    ]);
});

add_action('admin_post_eventosapp_whatsapp_flow_create_meta', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    check_admin_referer('eventosapp_whatsapp_flow_create_meta');

    $flow_post_id = absint($_POST['flow_post_id'] ?? 0);
    $config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);
    if ( empty($config) ) {
        eventosapp_whatsapp_flows_notice_redirect(['flow_notice' => 'error', 'flow_message' => rawurlencode('Flow local inválido.')]);
    }

    $waba_id = function_exists('eventosapp_whatsapp_sanitize_waba_id') ? eventosapp_whatsapp_sanitize_waba_id($config['waba_id']) : preg_replace('/\D+/', '', (string)($config['waba_id'] ?? ''));
    if ( $waba_id === '' && function_exists('eventosapp_whatsapp_get_settings') ) {
        $settings = eventosapp_whatsapp_get_settings();
        $waba_id = function_exists('eventosapp_whatsapp_get_effective_webhook_waba_id') ? eventosapp_whatsapp_get_effective_webhook_waba_id($settings) : ($settings['webhook_waba_id'] ?? '');
        $waba_id = preg_replace('/\D+/', '', (string) $waba_id);
    }

    if ( $waba_id === '' ) {
        eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'error', 'flow_message' => rawurlencode('Falta WABA ID para crear el Flow en Meta.')]);
    }

    $settings = eventosapp_whatsapp_flows_get_effective_settings(0, $config['sender_phone_number_id'] ?? '');
    $body = [
        'name'       => sanitize_text_field($config['title']),
        'categories' => [$config['category']],
    ];

    $result = eventosapp_whatsapp_flows_graph_request('POST', $waba_id . '/flows', $body, $settings);
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_meta_response', $result);
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_sync_at', current_time('mysql'));

    if ( ! empty($result['ok']) ) {
        $meta_flow_id = eventosapp_whatsapp_flows_extract_meta_flow_id($result['response'] ?? []);
        if ( $meta_flow_id !== '' ) {
            update_post_meta($flow_post_id, '_eventosapp_wa_flow_meta_id', $meta_flow_id);
            update_post_meta($flow_post_id, '_eventosapp_wa_flow_waba_id', $waba_id);
            update_post_meta($flow_post_id, '_eventosapp_wa_flow_status', 'draft_meta');
            update_post_meta($flow_post_id, '_eventosapp_wa_flow_created_at_meta', current_time('mysql'));
        }
        eventosapp_whatsapp_flows_add_activity('flow_creado_en_meta', ['flow_post_id' => $flow_post_id, 'meta_flow_id' => $meta_flow_id, 'result' => $result]);
        eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'success', 'flow_message' => rawurlencode('Flow creado en Meta correctamente.')]);
    }

    eventosapp_whatsapp_flows_add_activity('flow_error_crear_en_meta', ['flow_post_id' => $flow_post_id, 'result' => $result]);
    eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'error', 'flow_message' => rawurlencode($result['message'] ?? 'Meta rechazó la creación del Flow.')]);
});

add_action('admin_post_eventosapp_whatsapp_flow_upload_json', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    check_admin_referer('eventosapp_whatsapp_flow_upload_json');

    $flow_post_id = absint($_POST['flow_post_id'] ?? 0);
    $config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);
    $meta_flow_id = preg_replace('/\D+/', '', (string)($config['meta_flow_id'] ?? ''));
    if ( $meta_flow_id === '' ) {
        eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'error', 'flow_message' => rawurlencode('Primero debes crear el Flow en Meta para obtener el Flow ID.')]);
    }

    $file_path = eventosapp_whatsapp_flows_write_temp_flow_json($flow_post_id);
    if ( is_wp_error($file_path) ) {
        eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'error', 'flow_message' => rawurlencode($file_path->get_error_message())]);
    }

    $settings = eventosapp_whatsapp_flows_get_effective_settings(0, $config['sender_phone_number_id'] ?? '');
    $result = eventosapp_whatsapp_flows_graph_multipart_file_request('POST', $meta_flow_id . '/assets', $file_path, [
        'name'       => 'flow.json',
        'asset_type' => 'FLOW_JSON',
    ], $settings);

    if ( file_exists($file_path) ) {
        @unlink($file_path);
    }

    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_meta_response', $result);
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_sync_at', current_time('mysql'));

    $validation_errors = [];
    if ( is_array($result['response'] ?? null) && isset($result['response']['validation_errors']) ) {
        $validation_errors = is_array($result['response']['validation_errors']) ? $result['response']['validation_errors'] : [];
    }
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_validation_errors', $validation_errors);

    if ( ! empty($result['ok']) ) {
        update_post_meta($flow_post_id, '_eventosapp_wa_flow_status', empty($validation_errors) ? 'json_uploaded' : 'json_with_validation_errors');
        eventosapp_whatsapp_flows_add_activity('flow_json_subido', ['flow_post_id' => $flow_post_id, 'meta_flow_id' => $meta_flow_id, 'validation_errors' => $validation_errors, 'result' => $result]);
        $msg = empty($validation_errors) ? 'JSON subido y validado por Meta.' : 'JSON subido, pero Meta reportó errores de validación. Revisa el detalle.';
        eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => empty($validation_errors) ? 'success' : 'warning', 'flow_message' => rawurlencode($msg)]);
    }

    eventosapp_whatsapp_flows_add_activity('flow_json_error', ['flow_post_id' => $flow_post_id, 'meta_flow_id' => $meta_flow_id, 'result' => $result]);
    eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'error', 'flow_message' => rawurlencode($result['message'] ?? 'Meta rechazó la subida del JSON.')]);
});

add_action('admin_post_eventosapp_whatsapp_flow_publish', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    check_admin_referer('eventosapp_whatsapp_flow_publish');

    $flow_post_id = absint($_POST['flow_post_id'] ?? 0);
    $config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);
    $meta_flow_id = preg_replace('/\D+/', '', (string)($config['meta_flow_id'] ?? ''));
    if ( $meta_flow_id === '' ) {
        eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'error', 'flow_message' => rawurlencode('Falta Flow ID de Meta.')]);
    }

    $settings = eventosapp_whatsapp_flows_get_effective_settings(0, $config['sender_phone_number_id'] ?? '');
    $result = eventosapp_whatsapp_flows_graph_request('POST', $meta_flow_id . '/publish', null, $settings);
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_meta_response', $result);
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_sync_at', current_time('mysql'));

    if ( ! empty($result['ok']) ) {
        update_post_meta($flow_post_id, '_eventosapp_wa_flow_status', 'published');
        update_post_meta($flow_post_id, '_eventosapp_wa_flow_published_at', current_time('mysql'));
        eventosapp_whatsapp_flows_add_activity('flow_publicado', ['flow_post_id' => $flow_post_id, 'meta_flow_id' => $meta_flow_id, 'result' => $result]);
        eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'success', 'flow_message' => rawurlencode('Flow publicado en Meta.')]);
    }

    eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'error', 'flow_message' => rawurlencode($result['message'] ?? 'Meta rechazó la publicación del Flow.')]);
});

add_action('admin_post_eventosapp_whatsapp_flow_refresh', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    check_admin_referer('eventosapp_whatsapp_flow_refresh');

    $flow_post_id = absint($_POST['flow_post_id'] ?? 0);
    $config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);
    $meta_flow_id = preg_replace('/\D+/', '', (string)($config['meta_flow_id'] ?? ''));
    if ( $meta_flow_id === '' ) {
        eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'error', 'flow_message' => rawurlencode('Falta Flow ID de Meta.')]);
    }

    $settings = eventosapp_whatsapp_flows_get_effective_settings(0, $config['sender_phone_number_id'] ?? '');
    $result = eventosapp_whatsapp_flows_graph_request('GET', $meta_flow_id . '?fields=id,name,status,categories,validation_errors', null, $settings);
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_meta_response', $result);
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_sync_at', current_time('mysql'));
    if ( ! empty($result['ok']) && is_array($result['response'] ?? null) ) {
        $response = $result['response'];
        if ( ! empty($response['status']) ) {
            update_post_meta($flow_post_id, '_eventosapp_wa_flow_status', sanitize_key(strtolower((string)$response['status'])));
        }
        if ( isset($response['validation_errors']) && is_array($response['validation_errors']) ) {
            update_post_meta($flow_post_id, '_eventosapp_wa_flow_validation_errors', $response['validation_errors']);
        }
        eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'success', 'flow_message' => rawurlencode('Estado sincronizado desde Meta.')]);
    }
    eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'error', 'flow_message' => rawurlencode($result['message'] ?? 'No se pudo consultar el estado del Flow.')]);
});

add_action('admin_post_eventosapp_whatsapp_flow_preview', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    check_admin_referer('eventosapp_whatsapp_flow_preview');

    $flow_post_id = absint($_POST['flow_post_id'] ?? 0);
    $config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);
    $meta_flow_id = preg_replace('/\D+/', '', (string)($config['meta_flow_id'] ?? ''));
    if ( $meta_flow_id === '' ) {
        eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'error', 'flow_message' => rawurlencode('Falta Flow ID de Meta.')]);
    }

    $settings = eventosapp_whatsapp_flows_get_effective_settings(0, $config['sender_phone_number_id'] ?? '');
    $result = eventosapp_whatsapp_flows_graph_request('GET', $meta_flow_id . '/preview', null, $settings);
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_meta_response', $result);
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_sync_at', current_time('mysql'));

    if ( ! empty($result['ok']) && is_array($result['response'] ?? null) ) {
        $preview_url = esc_url_raw((string)($result['response']['preview_url'] ?? ($result['response']['url'] ?? '')));
        if ( $preview_url !== '' ) {
            update_post_meta($flow_post_id, '_eventosapp_wa_flow_preview_url', $preview_url);
        }
        eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'success', 'flow_message' => rawurlencode('Vista previa solicitada a Meta.')]);
    }
    eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'error', 'flow_message' => rawurlencode($result['message'] ?? 'No se pudo obtener la vista previa.')]);
});

add_action('admin_post_eventosapp_whatsapp_flow_test_send', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    check_admin_referer('eventosapp_whatsapp_flow_test_send');

    $flow_post_id = absint($_POST['flow_post_id'] ?? 0);
    $phone = sanitize_text_field((string)($_POST['test_phone'] ?? ''));
    $ticket_id = absint($_POST['test_ticket_id'] ?? 0);
    $event_id = absint($_POST['test_event_id'] ?? 0);
    $result = eventosapp_whatsapp_flows_send_direct_flow($flow_post_id, $phone, [
        'ticket_id'  => $ticket_id,
        'event_id'   => $event_id,
        'send_mode'  => 'direct_test',
    ]);

    eventosapp_whatsapp_flows_notice_redirect([
        'flow_id'      => $flow_post_id,
        'flow_notice'  => ! empty($result['ok']) ? 'success' : 'error',
        'flow_message' => rawurlencode($result['message'] ?? 'Prueba ejecutada.'),
    ]);
});

add_action('admin_post_eventosapp_whatsapp_flow_campaign_send', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    check_admin_referer('eventosapp_whatsapp_flow_campaign_send');

    $flow_post_id = absint($_POST['flow_post_id'] ?? 0);
    $event_id = absint($_POST['campaign_event_id'] ?? 0);
    $limit = min(100, max(1, absint($_POST['campaign_limit'] ?? 25)));
    $offset = max(0, absint($_POST['campaign_offset'] ?? 0));

    if ( ! $flow_post_id || ! $event_id ) {
        eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'error', 'flow_message' => rawurlencode('Debes seleccionar Flow y evento para el envío por lote.')]);
    }

    $tickets = get_posts([
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => ['publish', 'draft', 'private'],
        'posts_per_page' => $limit,
        'offset'         => $offset,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => '_eventosapp_ticket_evento_id',
                'value'   => $event_id,
                'compare' => '=',
            ],
        ],
    ]);

    $ok_count = 0;
    $error_count = 0;
    foreach ( $tickets as $ticket_id ) {
        $phone = get_post_meta($ticket_id, '_eventosapp_asistente_tel', true);
        $result = eventosapp_whatsapp_flows_send_direct_flow($flow_post_id, $phone, [
            'ticket_id' => $ticket_id,
            'event_id'  => $event_id,
            'send_mode' => 'direct_campaign',
        ]);
        if ( ! empty($result['ok']) ) {
            $ok_count++;
        } else {
            $error_count++;
        }
        usleep(120000);
    }

    update_option('eventosapp_whatsapp_flow_last_campaign_result', [
        'flow_post_id' => $flow_post_id,
        'event_id'     => $event_id,
        'limit'        => $limit,
        'offset'       => $offset,
        'ok'           => $ok_count,
        'errors'       => $error_count,
        'processed'    => count($tickets),
        'created_at'   => current_time('mysql'),
    ], false);

    eventosapp_whatsapp_flows_notice_redirect([
        'flow_id'      => $flow_post_id,
        'flow_notice'  => $error_count ? 'warning' : 'success',
        'flow_message' => rawurlencode('Lote procesado. Enviados: ' . $ok_count . '. Errores: ' . $error_count . '.'),
    ]);
});

add_action('admin_post_eventosapp_whatsapp_flow_export_responses', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    check_admin_referer('eventosapp_whatsapp_flow_export_responses');

    $flow_post_id = absint($_GET['flow_id'] ?? ($_POST['flow_id'] ?? 0));
    global $wpdb;
    eventosapp_whatsapp_flows_maybe_install_tables();
    $table = eventosapp_whatsapp_flows_responses_table_name();
    if ( $flow_post_id ) {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE flow_post_id = %d ORDER BY id ASC", $flow_post_id), ARRAY_A);
    } else {
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A);
    }

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=eventosapp-whatsapp-flow-responses-' . date('Ymd-His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['response_id', 'flow_post_id', 'meta_flow_id', 'event_id', 'ticket_id', 'phone', 'flow_token', 'wa_message_id', 'reply_to_message_id', 'created_at', 'summary', 'response_json']);
    foreach ( $rows as $row ) {
        fputcsv($out, [
            $row['id'], $row['flow_post_id'], $row['meta_flow_id'], $row['event_id'], $row['ticket_id'], $row['phone'], $row['flow_token'], $row['wa_message_id'], $row['reply_to_message_id'], $row['created_at'], $row['response_summary'], $row['response_json'],
        ]);
    }
    fclose($out);
    exit;
});

/**
 * Render UI.
 */
function eventosapp_whatsapp_flows_render_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }

    eventosapp_whatsapp_flows_maybe_install_tables();
    $flow_id = absint($_GET['flow_id'] ?? 0);
    $flows = eventosapp_whatsapp_flows_get_all_for_select();
    $selected = $flow_id ? eventosapp_whatsapp_flows_get_flow_config($flow_id) : [];
    if ( empty($selected) && ! empty($flows) ) {
        $first = reset($flows);
        $selected = eventosapp_whatsapp_flows_get_flow_config($first['id']);
        $flow_id = absint($first['id']);
    }

    $settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];
    $default_waba_id = function_exists('eventosapp_whatsapp_get_effective_webhook_waba_id') ? eventosapp_whatsapp_get_effective_webhook_waba_id($settings) : ($settings['webhook_waba_id'] ?? '');
    $phone_accounts = function_exists('eventosapp_whatsapp_get_phone_accounts') ? eventosapp_whatsapp_get_phone_accounts($settings) : [];
    $categories = eventosapp_whatsapp_flows_categories();
    $question_types = eventosapp_whatsapp_flows_question_types();
    $stats = eventosapp_whatsapp_flows_get_stats($flow_id);
    $recent_sends = eventosapp_whatsapp_flows_get_recent_sends($flow_id, 25);
    $recent_responses = eventosapp_whatsapp_flows_get_recent_responses($flow_id, 25);
    $events = get_posts([
        'post_type'      => 'eventosapp_event',
        'post_status'    => ['publish', 'draft', 'private'],
        'posts_per_page' => 200,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    $new_config = [
        'id'                     => 0,
        'title'                  => '',
        'description'            => 'Hola {{name}}, queremos conocer tu opinión sobre {{event_name}}. Completa esta encuesta corta.',
        'category'               => 'SURVEY',
        'cta'                    => 'Responder encuesta',
        'submit_label'           => 'Enviar respuestas',
        'screen_id'              => 'SURVEY',
        'status'                 => 'local_draft',
        'meta_flow_id'           => '',
        'waba_id'                => $default_waba_id,
        'sender_phone_number_id' => $settings['phone_number_id'] ?? '',
        'preview_url'            => '',
        'questions'              => eventosapp_whatsapp_flows_default_questions(),
        'last_meta_response'     => [],
        'validation_errors'      => [],
    ];
    $edit_config = ! empty($selected) ? wp_parse_args($selected, $new_config) : $new_config;
    $json_preview = eventosapp_whatsapp_flows_json_encode(eventosapp_whatsapp_flows_build_flow_json($flow_id, $edit_config), true);

    ?>
    <div class="wrap eventosapp-wa-flows">
        <h1>WhatsApp Flows</h1>
        <?php eventosapp_whatsapp_flows_render_notices(); ?>

        <style>
            .eventosapp-wa-flows .evapp-grid{display:grid;grid-template-columns:minmax(360px,1.2fr) minmax(320px,.8fr);gap:18px;align-items:start}.eventosapp-wa-flows .evapp-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04);margin-bottom:16px}.eventosapp-wa-flows .evapp-card h2{margin-top:0}.eventosapp-wa-flows .evapp-muted{color:#646970}.eventosapp-wa-flows .evapp-pill{display:inline-block;border-radius:999px;background:#eef6ff;color:#0a5ea8;padding:4px 9px;font-size:12px;font-weight:600}.eventosapp-wa-flows .evapp-stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}.eventosapp-wa-flows .evapp-stat{background:#f6f7f7;border-radius:8px;padding:10px}.eventosapp-wa-flows .evapp-stat strong{display:block;font-size:22px}.eventosapp-wa-flows .evapp-question{border:1px solid #dcdcde;border-radius:8px;padding:12px;margin:12px 0;background:#fbfbfc}.eventosapp-wa-flows .evapp-question-head{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:8px}.eventosapp-wa-flows .evapp-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.eventosapp-wa-flows textarea.code{width:100%;min-height:280px;font-family:Menlo,Consolas,monospace}.eventosapp-wa-flows .widefat td{vertical-align:top}.eventosapp-wa-flows .evapp-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.eventosapp-wa-flows .evapp-response-pre{white-space:pre-wrap;max-height:100px;overflow:auto}.eventosapp-wa-flows .evapp-warning{border-left:4px solid #dba617;background:#fff8e5;padding:10px;margin:10px 0}.eventosapp-wa-flows .evapp-success{border-left:4px solid #00a32a;background:#edfaef;padding:10px;margin:10px 0}@media(max-width:1100px){.eventosapp-wa-flows .evapp-grid{grid-template-columns:1fr}.eventosapp-wa-flows .evapp-stat-grid{grid-template-columns:repeat(2,1fr)}.eventosapp-wa-flows .evapp-row{grid-template-columns:1fr}}
        </style>

        <div class="evapp-card">
            <div class="evapp-stat-grid">
                <div class="evapp-stat"><span>Envíos</span><strong><?php echo esc_html($stats['sent']); ?></strong></div>
                <div class="evapp-stat"><span>Entregados</span><strong><?php echo esc_html($stats['delivered']); ?></strong></div>
                <div class="evapp-stat"><span>Leídos</span><strong><?php echo esc_html($stats['read']); ?></strong></div>
                <div class="evapp-stat"><span>Respondidos</span><strong><?php echo esc_html($stats['answered']); ?></strong></div>
                <div class="evapp-stat"><span>Tasa</span><strong><?php echo esc_html($stats['rate']); ?>%</strong></div>
            </div>
        </div>

        <div class="evapp-grid">
            <div>
                <div class="evapp-card">
                    <h2><?php echo $flow_id ? 'Editar Flow' : 'Crear Flow'; ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="evapp-wa-flow-form">
                        <?php wp_nonce_field('eventosapp_whatsapp_flow_save'); ?>
                        <input type="hidden" name="action" value="eventosapp_whatsapp_flow_save">
                        <input type="hidden" name="flow_post_id" value="<?php echo esc_attr($edit_config['id']); ?>">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th><label for="flow_title">Nombre</label></th>
                                <td><input type="text" class="regular-text" id="flow_title" name="flow_title" value="<?php echo esc_attr($edit_config['title']); ?>" placeholder="Encuesta post evento" required></td>
                            </tr>
                            <tr>
                                <th><label for="flow_description">Mensaje / descripción</label></th>
                                <td><textarea class="large-text" rows="3" id="flow_description" name="flow_description"><?php echo esc_textarea($edit_config['description']); ?></textarea><p class="description">Puedes usar variables como {{name}}, {{event_name}}, {{ticket_code}}, {{localidad}}.</p></td>
                            </tr>
                            <tr>
                                <th>Categoría</th>
                                <td><select name="flow_category">
                                    <?php foreach ( $categories as $key => $label ) : ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($edit_config['category'], $key); ?>><?php echo esc_html($label . ' (' . $key . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select></td>
                            </tr>
                            <tr>
                                <th>Configuración Meta</th>
                                <td>
                                    <div class="evapp-row">
                                        <label>WABA ID<br><input type="text" class="regular-text" name="flow_waba_id" value="<?php echo esc_attr($edit_config['waba_id'] ?: $default_waba_id); ?>"></label>
                                        <label>Número emisor<br>
                                            <select name="flow_sender_phone_number_id">
                                                <option value="">Usar número por defecto</option>
                                                <?php foreach ( $phone_accounts as $account ) : ?>
                                                    <option value="<?php echo esc_attr($account['phone_number_id']); ?>" <?php selected($edit_config['sender_phone_number_id'], $account['phone_number_id']); ?>><?php echo esc_html($account['label']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    </div>
                                    <p class="description">El WABA ID se usa para crear y administrar el Flow. El número emisor se usa para pruebas y envíos directos.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>CTA y pantalla</th>
                                <td>
                                    <div class="evapp-row">
                                        <label>Texto del botón<br><input type="text" name="flow_cta" value="<?php echo esc_attr($edit_config['cta']); ?>" maxlength="30"></label>
                                        <label>Botón final<br><input type="text" name="flow_submit_label" value="<?php echo esc_attr($edit_config['submit_label']); ?>" maxlength="30"></label>
                                    </div>
                                    <p><label>ID de pantalla inicial<br><input type="text" name="flow_screen_id" value="<?php echo esc_attr($edit_config['screen_id']); ?>" class="regular-text"></label></p>
                                </td>
                            </tr>
                        </table>

                        <h3>Preguntas de la encuesta</h3>
                        <div id="evapp-wa-flow-questions">
                            <?php foreach ( $edit_config['questions'] as $index => $question ) : ?>
                                <?php eventosapp_whatsapp_flows_render_question_row($index, $question, $question_types); ?>
                            <?php endforeach; ?>
                        </div>
                        <p><button type="button" class="button" id="evapp-wa-add-question">Agregar pregunta</button></p>

                        <p class="submit"><button type="submit" class="button button-primary">Guardar Flow local</button></p>
                    </form>
                </div>

                <div class="evapp-card">
                    <h2>JSON generado</h2>
                    <p class="evapp-muted">Este JSON es el que EventosApp sube a Meta cuando presionas “Subir JSON”.</p>
                    <textarea class="code" readonly><?php echo esc_textarea($json_preview); ?></textarea>
                </div>
            </div>

            <div>
                <div class="evapp-card">
                    <h2>Flows creados</h2>
                    <?php if ( empty($flows) ) : ?>
                        <p>No hay Flows guardados todavía.</p>
                    <?php else : ?>
                        <table class="widefat striped">
                            <thead><tr><th>Flow</th><th>Estado</th><th>Meta ID</th><th>Acción</th></tr></thead>
                            <tbody>
                            <?php foreach ( $flows as $item ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($item['title']); ?></strong></td>
                                    <td><span class="evapp-pill"><?php echo esc_html($item['status'] ?: 'local'); ?></span></td>
                                    <td><?php echo esc_html($item['meta_flow_id'] ?: '—'); ?></td>
                                    <td><a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'eventosapp_whatsapp_flows', 'flow_id' => $item['id']], admin_url('admin.php'))); ?>">Abrir</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <p><a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows&flow_id=0')); ?>" class="button">Crear uno nuevo</a></p>
                </div>

                <?php if ( $flow_id ) : ?>
                    <div class="evapp-card">
                        <h2>Sincronización con Meta</h2>
                        <p><strong>Estado:</strong> <span class="evapp-pill"><?php echo esc_html($edit_config['status']); ?></span></p>
                        <p><strong>Flow ID Meta:</strong> <?php echo esc_html($edit_config['meta_flow_id'] ?: 'No creado'); ?></p>
                        <?php if ( ! empty($edit_config['preview_url']) ) : ?>
                            <p><a class="button" target="_blank" href="<?php echo esc_url($edit_config['preview_url']); ?>">Abrir vista previa</a></p>
                        <?php endif; ?>
                        <div class="evapp-actions">
                            <?php eventosapp_whatsapp_flows_render_small_post_button('eventosapp_whatsapp_flow_create_meta', 'eventosapp_whatsapp_flow_create_meta', 'Crear en Meta', $flow_id); ?>
                            <?php eventosapp_whatsapp_flows_render_small_post_button('eventosapp_whatsapp_flow_upload_json', 'eventosapp_whatsapp_flow_upload_json', 'Subir JSON', $flow_id); ?>
                            <?php eventosapp_whatsapp_flows_render_small_post_button('eventosapp_whatsapp_flow_preview', 'eventosapp_whatsapp_flow_preview', 'Pedir preview', $flow_id); ?>
                            <?php eventosapp_whatsapp_flows_render_small_post_button('eventosapp_whatsapp_flow_refresh', 'eventosapp_whatsapp_flow_refresh', 'Sincronizar estado', $flow_id); ?>
                            <?php eventosapp_whatsapp_flows_render_small_post_button('eventosapp_whatsapp_flow_publish', 'eventosapp_whatsapp_flow_publish', 'Publicar', $flow_id, 'button-primary'); ?>
                        </div>
                        <?php if ( ! empty($edit_config['validation_errors']) && is_array($edit_config['validation_errors']) ) : ?>
                            <div class="evapp-warning"><strong>Errores de validación Meta:</strong><?php eventosapp_whatsapp_flows_render_debug($edit_config['validation_errors']); ?></div>
                        <?php endif; ?>
                        <details style="margin-top:12px;"><summary>Última respuesta técnica de Meta</summary><?php eventosapp_whatsapp_flows_render_debug($edit_config['last_meta_response']); ?></details>
                    </div>

                    <div class="evapp-card">
                        <h2>Enviar prueba directa</h2>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('eventosapp_whatsapp_flow_test_send'); ?>
                            <input type="hidden" name="action" value="eventosapp_whatsapp_flow_test_send">
                            <input type="hidden" name="flow_post_id" value="<?php echo esc_attr($flow_id); ?>">
                            <p><label>Teléfono destino<br><input type="text" name="test_phone" class="regular-text" placeholder="573001112233"></label></p>
                            <p><label>Ticket ID opcional<br><input type="number" name="test_ticket_id" min="0" class="small-text"></label></p>
                            <p><label>Evento opcional<br><select name="test_event_id"><option value="0">Sin evento</option><?php foreach ( $events as $event ) : ?><option value="<?php echo esc_attr($event->ID); ?>"><?php echo esc_html(get_the_title($event)); ?></option><?php endforeach; ?></select></label></p>
                            <p><button type="submit" class="button button-primary">Enviar Flow de prueba</button></p>
                        </form>
                    </div>

                    <div class="evapp-card">
                        <h2>Envío por lote controlado</h2>
                        <p class="evapp-muted">Procesa máximo 100 tickets por lote para evitar carga excesiva. Para más asistentes, ejecuta por bloques usando el offset.</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('eventosapp_whatsapp_flow_campaign_send'); ?>
                            <input type="hidden" name="action" value="eventosapp_whatsapp_flow_campaign_send">
                            <input type="hidden" name="flow_post_id" value="<?php echo esc_attr($flow_id); ?>">
                            <p><label>Evento<br><select name="campaign_event_id" required><option value="">Seleccionar evento</option><?php foreach ( $events as $event ) : ?><option value="<?php echo esc_attr($event->ID); ?>"><?php echo esc_html(get_the_title($event)); ?></option><?php endforeach; ?></select></label></p>
                            <p><label>Límite <input type="number" name="campaign_limit" value="25" min="1" max="100" class="small-text"></label> <label>Offset <input type="number" name="campaign_offset" value="0" min="0" class="small-text"></label></p>
                            <p><button type="submit" class="button">Enviar lote</button></p>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="evapp-card">
            <h2>Respuestas recientes</h2>
            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=eventosapp_whatsapp_flow_export_responses&flow_id=' . absint($flow_id)), 'eventosapp_whatsapp_flow_export_responses')); ?>">Descargar respuestas CSV</a></p>
            <?php eventosapp_whatsapp_flows_render_responses_table($recent_responses); ?>
        </div>

        <div class="evapp-card">
            <h2>Envíos recientes</h2>
            <?php eventosapp_whatsapp_flows_render_sends_table($recent_sends); ?>
        </div>
    </div>

    <script>
    (function(){
        var wrap = document.getElementById('evapp-wa-flow-questions');
        var add = document.getElementById('evapp-wa-add-question');
        if (!wrap || !add) return;
        function optionsTextFromBlock(block){ return ''; }
        function nextIndex(){ return wrap.querySelectorAll('.evapp-question').length; }
        function questionTemplate(i){
            return '<div class="evapp-question" data-question-index="'+i+'">'+
                '<div class="evapp-question-head"><strong>Pregunta '+(i+1)+'</strong><button type="button" class="button-link-delete evapp-remove-question">Quitar</button></div>'+
                '<div class="evapp-row"><label>Etiqueta<br><input type="text" class="large-text" name="questions['+i+'][label]" value="Nueva pregunta"></label><label>Slug<br><input type="text" class="regular-text" name="questions['+i+'][slug]" value="pregunta_'+(i+1)+'"></label></div>'+
                '<p><label>Tipo<br><select name="questions['+i+'][type]"><?php foreach ( $question_types as $key => $label ) : ?><option value="<?php echo esc_js($key); ?>"><?php echo esc_js($label); ?></option><?php endforeach; ?></select></label> <label style="margin-left:12px;"><input type="checkbox" name="questions['+i+'][required]" value="1" checked> Obligatoria</label></p>'+
                '<p><label>Opciones, una por línea<br><textarea class="large-text" rows="4" name="questions['+i+'][options]">Opción 1\nOpción 2</textarea></label></p>'+
            '</div>';
        }
        add.addEventListener('click', function(){ wrap.insertAdjacentHTML('beforeend', questionTemplate(nextIndex())); });
        wrap.addEventListener('click', function(e){ if(e.target && e.target.classList.contains('evapp-remove-question')){ var q=e.target.closest('.evapp-question'); if(q) q.remove(); } });
    })();
    </script>
    <?php
}

function eventosapp_whatsapp_flows_render_notices() {
    if ( empty($_GET['flow_notice']) ) {
        return;
    }
    $type = sanitize_key((string) $_GET['flow_notice']);
    $message = isset($_GET['flow_message']) ? sanitize_text_field(wp_unslash($_GET['flow_message'])) : '';
    if ( $message === '' ) {
        return;
    }
    $class = $type === 'success' ? 'notice notice-success' : ($type === 'warning' ? 'notice notice-warning' : 'notice notice-error');
    echo '<div class="' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
}

function eventosapp_whatsapp_flows_render_debug($value) {
    if ( function_exists('eventosapp_whatsapp_render_log_details') ) {
        eventosapp_whatsapp_render_log_details($value);
        return;
    }
    echo '<pre style="white-space:pre-wrap;max-height:240px;overflow:auto;background:#f6f7f7;border:1px solid #ddd;padding:8px;">' . esc_html(eventosapp_whatsapp_flows_json_encode($value, true)) . '</pre>';
}

function eventosapp_whatsapp_flows_render_small_post_button($action, $nonce_action, $label, $flow_id, $class = '') {
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:0;">
        <?php wp_nonce_field($nonce_action); ?>
        <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
        <input type="hidden" name="flow_post_id" value="<?php echo esc_attr(absint($flow_id)); ?>">
        <button type="submit" class="button <?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button>
    </form>
    <?php
}

function eventosapp_whatsapp_flows_render_question_row($index, $question, $question_types) {
    $index = is_numeric($index) ? absint($index) : 0;
    $question = is_array($question) ? $question : [];
    $options_text = '';
    if ( ! empty($question['options']) && is_array($question['options']) ) {
        $lines = [];
        foreach ( $question['options'] as $option ) {
            $lines[] = is_array($option) ? (string)($option['title'] ?? '') : (string) $option;
        }
        $options_text = implode("\n", array_filter($lines));
    }
    ?>
    <div class="evapp-question" data-question-index="<?php echo esc_attr($index); ?>">
        <div class="evapp-question-head"><strong>Pregunta <?php echo esc_html($index + 1); ?></strong><button type="button" class="button-link-delete evapp-remove-question">Quitar</button></div>
        <div class="evapp-row">
            <label>Etiqueta<br><input type="text" class="large-text" name="questions[<?php echo esc_attr($index); ?>][label]" value="<?php echo esc_attr($question['label'] ?? ''); ?>"></label>
            <label>Slug<br><input type="text" class="regular-text" name="questions[<?php echo esc_attr($index); ?>][slug]" value="<?php echo esc_attr($question['slug'] ?? ''); ?>"></label>
        </div>
        <p><label>Tipo<br><select name="questions[<?php echo esc_attr($index); ?>][type]">
            <?php foreach ( $question_types as $key => $label ) : ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($question['type'] ?? '', $key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select></label> <label style="margin-left:12px;"><input type="checkbox" name="questions[<?php echo esc_attr($index); ?>][required]" value="1" <?php checked(($question['required'] ?? '0'), '1'); ?>> Obligatoria</label></p>
        <p><label>Opciones, una por línea<br><textarea class="large-text" rows="4" name="questions[<?php echo esc_attr($index); ?>][options]"><?php echo esc_textarea($options_text); ?></textarea></label></p>
    </div>
    <?php
}

function eventosapp_whatsapp_flows_render_responses_table($rows) {
    if ( empty($rows) ) {
        echo '<p>No hay respuestas registradas todavía.</p>';
        return;
    }
    ?>
    <table class="widefat striped">
        <thead><tr><th>Fecha</th><th>Flow</th><th>Ticket</th><th>Teléfono</th><th>Respuesta</th></tr></thead>
        <tbody>
        <?php foreach ( $rows as $row ) : ?>
            <tr>
                <td><?php echo esc_html($row['created_at']); ?></td>
                <td>#<?php echo esc_html($row['flow_post_id']); ?><br><small><?php echo esc_html($row['meta_flow_id']); ?></small></td>
                <td><?php echo $row['ticket_id'] ? '<a href="' . esc_url(get_edit_post_link(absint($row['ticket_id']))) . '">#' . esc_html($row['ticket_id']) . '</a>' : '—'; ?></td>
                <td><?php echo esc_html($row['phone']); ?></td>
                <td><pre class="evapp-response-pre"><?php echo esc_html($row['response_summary'] ?: $row['response_json']); ?></pre></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function eventosapp_whatsapp_flows_render_sends_table($rows) {
    if ( empty($rows) ) {
        echo '<p>No hay envíos registrados todavía.</p>';
        return;
    }
    ?>
    <table class="widefat striped">
        <thead><tr><th>Fecha</th><th>Ticket</th><th>Teléfono</th><th>Estado</th><th>Message ID</th><th>Respondió</th></tr></thead>
        <tbody>
        <?php foreach ( $rows as $row ) : ?>
            <tr>
                <td><?php echo esc_html($row['created_at']); ?></td>
                <td><?php echo $row['ticket_id'] ? '<a href="' . esc_url(get_edit_post_link(absint($row['ticket_id']))) . '">#' . esc_html($row['ticket_id']) . '</a>' : '—'; ?></td>
                <td><?php echo esc_html($row['phone']); ?></td>
                <td><span class="evapp-pill"><?php echo esc_html($row['status']); ?></span><br><small><?php echo esc_html($row['delivery_status']); ?></small></td>
                <td><small><?php echo esc_html($row['wa_message_id'] ?: '—'); ?></small></td>
                <td><?php echo ! empty($row['response_received']) ? 'Sí' : 'No'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
