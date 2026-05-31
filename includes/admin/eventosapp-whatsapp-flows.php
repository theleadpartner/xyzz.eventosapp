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
    define('EVENTOSAPP_WHATSAPP_FLOWS_TABLE_VERSION', '2026.05.30.1');
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

    add_submenu_page(
        'eventosapp_dashboard',
        'Gestionar Flows',
        'Gestionar Flows',
        'manage_options',
        'eventosapp_whatsapp_flows_manage',
        'eventosapp_whatsapp_flows_render_manage_page',
        25
    );

    add_submenu_page(
        'eventosapp_dashboard',
        'Envío Masivo de Flows',
        'Envío Masivo de Flows',
        'manage_options',
        'eventosapp_whatsapp_flows_campaign',
        'eventosapp_whatsapp_flows_render_campaign_page',
        26
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

/**
 * Tipos de campo disponibles para construir Flows usando únicamente
 * nombres de componentes soportados por WhatsApp Flows.
 *
 * La UI muestra el nombre real del componente para evitar confundir
 * componentes oficiales con formatos de encuesta o presets internos.
 */
function eventosapp_whatsapp_flows_question_types() {
    return [
        'heading'    => 'TextHeading',
        'subheading' => 'TextSubheading',
        'body'       => 'TextBody',
        'caption'    => 'TextCaption',
        'radio'      => 'RadioButtonsGroup',
        'checkbox'   => 'CheckboxGroup',
        'dropdown'   => 'Dropdown',
        'text'       => 'TextInput',
        'textarea'   => 'TextArea',
        'date'       => 'DatePicker',
        'optin'      => 'OptIn',
    ];
}

function eventosapp_whatsapp_flows_input_question_types() {
    return [
        'radio', 'checkbox', 'dropdown', 'text', 'textarea', 'date', 'optin'
    ];
}

function eventosapp_whatsapp_flows_display_question_types() {
    return ['heading', 'subheading', 'body', 'caption'];
}

function eventosapp_whatsapp_flows_text_input_types() {
    return [
        'text'   => 'Texto general',
        'email'  => 'Correo electrónico',
        'number' => 'Número',
        'phone'  => 'Teléfono',
    ];
}

function eventosapp_whatsapp_flows_type_help() {
    return [
        'heading'    => 'TextHeading: muestra un encabezado. No guarda respuesta.',
        'subheading' => 'TextSubheading: muestra un subtítulo. No guarda respuesta.',
        'body'       => 'TextBody: muestra instrucciones, contexto o texto legal. No guarda respuesta.',
        'caption'    => 'TextCaption: muestra una nota corta. No guarda respuesta.',
        'radio'      => 'RadioButtonsGroup: permite elegir una sola opción. Para escalas, escribe manualmente cada opción en el campo de opciones.',
        'checkbox'   => 'CheckboxGroup: permite elegir varias opciones.',
        'dropdown'   => 'Dropdown: permite elegir una opción desde una lista desplegable.',
        'text'       => 'TextInput: campo de texto de una sola línea. Su input-type puede ser text, email, number o phone.',
        'textarea'   => 'TextArea: campo de texto largo.',
        'date'       => 'DatePicker: selector de fecha.',
        'optin'      => 'OptIn: casilla de aceptación.',
    ];
}

function eventosapp_whatsapp_flows_default_options_for_type($type) {
    $type = sanitize_key((string) $type);
    if ( $type === 'nps' ) {
        $options = [];
        for ( $i = 0; $i <= 10; $i++ ) {
            $options[] = ['id' => (string) $i, 'title' => (string) $i];
        }
        return $options;
    }
    if ( $type === 'rating5' ) {
        return [
            ['id' => '1', 'title' => '1 - Muy insatisfecho'],
            ['id' => '2', 'title' => '2'],
            ['id' => '3', 'title' => '3 - Regular'],
            ['id' => '4', 'title' => '4'],
            ['id' => '5', 'title' => '5 - Muy satisfecho'],
        ];
    }
    if ( $type === 'yesno' ) {
        return [
            ['id' => 'si', 'title' => 'Sí'],
            ['id' => 'no', 'title' => 'No'],
        ];
    }
    return [];
}

function eventosapp_whatsapp_flows_default_questions() {
    return [
        [
            'slug'     => 'seccion_valoracion',
            'label'    => 'Valoración del evento',
            'help'     => '',
            'type'     => 'heading',
            'required' => '0',
            'options'  => [],
        ],
        [
            'slug'     => 'probabilidad_recomendar',
            'label'    => '¿Qué tan probable es que recomiendes este evento a un amigo o familiar?',
            'help'     => '0 es nada probable y 10 es muy probable.',
            'type'     => 'radio',
            'required' => '1',
            'options'  => eventosapp_whatsapp_flows_default_options_for_type('nps'),
        ],
        [
            'slug'     => 'medio_conocimiento',
            'label'    => '¿Cómo te enteraste de nuestro evento?',
            'help'     => '',
            'type'     => 'radio',
            'required' => '0',
            'options'  => [
                ['id' => 'correo', 'title' => 'Recibí un correo electrónico'],
                ['id' => 'llamada', 'title' => 'Llamada de un agente comercial'],
                ['id' => 'empresa', 'title' => 'Por medio de la empresa'],
                ['id' => 'web', 'title' => 'Página web'],
                ['id' => 'recomendacion', 'title' => 'Recomendación'],
                ['id' => 'redes', 'title' => 'Redes sociales'],
                ['id' => 'whatsapp', 'title' => 'WhatsApp'],
            ],
        ],
        [
            'slug'     => 'satisfaccion_contenido',
            'label'    => '¿Los temas desarrollados cumplieron tus expectativas?',
            'help'     => '1 corresponde al mínimo grado de satisfacción y 5 al máximo.',
            'type'     => 'radio',
            'required' => '1',
            'options'  => eventosapp_whatsapp_flows_default_options_for_type('rating5'),
        ],
        [
            'slug'     => 'comentarios',
            'label'    => '¿Qué fue lo que más te gustó y qué podríamos mejorar?',
            'help'     => '',
            'type'     => 'textarea',
            'required' => '0',
            'options'  => [],
        ],
        [
            'slug'     => 'acepta_tratamiento_datos',
            'label'    => 'Acepto el tratamiento de mis datos personales para fines relacionados con el evento.',
            'help'     => 'Usa este campo cuando necesites autorización expresa.',
            'type'     => 'optin',
            'required' => '1',
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

/**
 * Normaliza IDs de pantalla para WhatsApp Flow JSON.
 * Meta valida screens[n].id con letras y guiones bajos únicamente.
 * Por eso no se reutiliza sanitize_key(), porque permite números y guiones.
 */
function eventosapp_whatsapp_flows_sanitize_flow_screen_id($value, $fallback = 'SURVEY') {
    $normalize = static function($candidate) {
        $screen = strtoupper(trim(remove_accents((string) $candidate)));
        $screen = preg_replace('/[^A-Z_]+/', '_', $screen);
        $screen = preg_replace('/_+/', '_', (string) $screen);
        $screen = trim((string) $screen, '_');

        if ( $screen === '' ) {
            return '';
        }

        if ( ! preg_match('/^[A-Z]/', $screen) ) {
            $screen = 'SCREEN_' . $screen;
        }

        return $screen;
    };

    $screen = $normalize($value);
    if ( $screen === '' && $fallback !== '' ) {
        $screen = $normalize($fallback);
    }

    return $screen;
}

function eventosapp_whatsapp_flows_screen_suffix_from_index($screen_index) {
    $screen_index = absint($screen_index);
    if ( $screen_index <= 0 ) {
        return '';
    }

    $letters = '';
    $number = $screen_index;
    while ( $number >= 0 ) {
        $letters = chr(65 + ($number % 26)) . $letters;
        $number = (int) floor($number / 26) - 1;
    }

    return $letters;
}

function eventosapp_whatsapp_flows_make_screen_id($base_screen_id, $screen_index = 0) {
    $base_screen_id = eventosapp_whatsapp_flows_sanitize_flow_screen_id($base_screen_id, 'SURVEY');
    if ( $base_screen_id === '' ) {
        $base_screen_id = 'SURVEY';
    }

    $suffix = eventosapp_whatsapp_flows_screen_suffix_from_index($screen_index);
    return $suffix !== '' ? $base_screen_id . '_' . $suffix : $base_screen_id;
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
            $parts = array_map('trim', explode('|', $line, 2));
            if ( count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '' ) {
                $id = sanitize_key(remove_accents($parts[0]));
                $title = sanitize_text_field($parts[1]);
            } else {
                $title = sanitize_text_field($line);
                $id = sanitize_key(remove_accents($line));
            }
            if ( $id === '' ) {
                $id = 'opcion_' . (count($options) + 1);
            }
            $options[] = [
                'id'    => eventosapp_whatsapp_flows_text_limit($id, 80),
                'title' => eventosapp_whatsapp_flows_text_limit($title, 80),
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
                'id'    => eventosapp_whatsapp_flows_text_limit($id, 80),
                'title' => eventosapp_whatsapp_flows_text_limit($title, 80),
            ];
        }
    }

    return array_slice($options, 0, 200);
}

function eventosapp_whatsapp_flows_normalize_questions($raw_questions) {
    $questions = [];
    $types = eventosapp_whatsapp_flows_question_types();
    $display_types = eventosapp_whatsapp_flows_display_question_types();
    $text_input_types = eventosapp_whatsapp_flows_text_input_types();

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
        $legacy_type = $type;
        $input_type = sanitize_key((string)($question['input_type'] ?? 'text'));
        if ( ! isset($text_input_types[$input_type]) ) {
            $input_type = 'text';
        }

        // Migración segura desde versiones anteriores del constructor.
        // Los alias internos antiguos se convierten al componente oficial RadioButtonsGroup.
        if ( in_array($legacy_type, ['nps', 'rating5', 'yesno'], true) ) {
            $type = 'radio';
        }

        // Los alias internos antiguos de entrada corta se convierten al componente oficial TextInput.
        if ( in_array($legacy_type, ['email', 'number', 'phone'], true) ) {
            $type = 'text';
            $input_type = $legacy_type;
        }

        if ( ! isset($types[$type]) ) {
            $type = 'radio';
        }

        $slug = eventosapp_whatsapp_flows_sanitize_slug($question['slug'] ?? '', 'pregunta_' . ((int) $index + 1));
        $help = sanitize_textarea_field((string)($question['help'] ?? ''));
        $placeholder = sanitize_text_field((string)($question['placeholder'] ?? ''));
        $options = eventosapp_whatsapp_flows_normalize_options($question['options'] ?? []);

        if ( in_array($legacy_type, ['nps', 'rating5', 'yesno'], true) && empty($options) ) {
            $options = eventosapp_whatsapp_flows_default_options_for_type($legacy_type);
        }

        if ( in_array($type, ['radio', 'checkbox', 'dropdown'], true) && empty($options) ) {
            $options = [
                ['id' => 'opcion_1', 'title' => 'Opción 1'],
                ['id' => 'opcion_2', 'title' => 'Opción 2'],
            ];
        }

        if ( in_array($type, $display_types, true) ) {
            $required = '0';
            $options = [];
        } else {
            $required = ! empty($question['required']) && $question['required'] !== '0' ? '1' : '0';
        }

        $min_chars = isset($question['min_chars']) ? absint($question['min_chars']) : 0;
        $max_chars = isset($question['max_chars']) ? absint($question['max_chars']) : 0;
        if ( $max_chars && $min_chars && $min_chars > $max_chars ) {
            $min_chars = 0;
        }

        $questions[] = [
            'slug'        => $slug,
            'label'       => $label,
            'help'        => $help,
            'placeholder' => $placeholder,
            'type'        => $type,
            'input_type'  => $input_type,
            'required'    => $required,
            'options'     => $options,
            'min_chars'   => $min_chars,
            'max_chars'   => $max_chars,
        ];
    }

    return ! empty($questions) ? array_slice($questions, 0, 60) : eventosapp_whatsapp_flows_default_questions();
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
        'questions_per_screen'   => absint(get_post_meta($flow_post_id, '_eventosapp_wa_flow_questions_per_screen', true)) ?: 8,
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
    $config['screen_id'] = eventosapp_whatsapp_flows_sanitize_flow_screen_id($config['screen_id'], 'SURVEY');
    $config['questions_per_screen'] = min(15, max(3, absint($config['questions_per_screen'] ?? 8)));

    return $config;
}

function eventosapp_whatsapp_flows_question_answer_schema($question) {
    $type = sanitize_key((string)($question['type'] ?? 'text'));
    if ( $type === 'checkbox' ) {
        return [
            'type'        => 'array',
            'items'       => ['type' => 'string'],
            '__example__' => ['opcion_1'],
        ];
    }
    if ( $type === 'optin' ) {
        return [
            'type'        => 'boolean',
            '__example__' => true,
        ];
    }
    return [
        'type'        => 'string',
        '__example__' => 'respuesta',
    ];
}

function eventosapp_whatsapp_flows_question_to_component($question) {
    $type = sanitize_key((string)($question['type'] ?? 'radio'));
    $label = sanitize_text_field((string)($question['label'] ?? 'Pregunta'));
    $slug = eventosapp_whatsapp_flows_sanitize_slug($question['slug'] ?? '', 'pregunta');
    $required = ! empty($question['required']) && $question['required'] !== '0';
    $options = eventosapp_whatsapp_flows_normalize_options($question['options'] ?? []);

    if ( $type === 'heading' ) {
        return ['type' => 'TextHeading', 'text' => eventosapp_whatsapp_flows_text_limit($label, 80)];
    }
    if ( $type === 'subheading' ) {
        return ['type' => 'TextSubheading', 'text' => eventosapp_whatsapp_flows_text_limit($label, 80)];
    }
    if ( $type === 'body' ) {
        return ['type' => 'TextBody', 'text' => eventosapp_whatsapp_flows_text_limit($label, 4096)];
    }
    if ( $type === 'caption' ) {
        return ['type' => 'TextCaption', 'text' => eventosapp_whatsapp_flows_text_limit($label, 300)];
    }

    $component = [
        'name'     => $slug,
        'label'    => eventosapp_whatsapp_flows_text_limit($label, 120),
        'required' => $required,
    ];

    if ( $type === 'textarea' ) {
        $component = array_merge(['type' => 'TextArea'], $component);
    } elseif ( $type === 'text' ) {
        $text_input_types = eventosapp_whatsapp_flows_text_input_types();
        $input_type = sanitize_key((string)($question['input_type'] ?? 'text'));
        if ( ! isset($text_input_types[$input_type]) ) {
            $input_type = 'text';
        }
        $component = array_merge(['type' => 'TextInput', 'input-type' => $input_type], $component);
    } elseif ( $type === 'date' ) {
        $component = array_merge(['type' => 'DatePicker'], $component);
    } elseif ( $type === 'optin' ) {
        $component = array_merge(['type' => 'OptIn'], $component);
    } elseif ( $type === 'checkbox' ) {
        $component = array_merge(['type' => 'CheckboxGroup'], $component);
        $component['data-source'] = $options;
    } elseif ( $type === 'dropdown' ) {
        $component = array_merge(['type' => 'Dropdown'], $component);
        $component['data-source'] = $options;
    } else {
        $component = array_merge(['type' => 'RadioButtonsGroup'], $component);
        $component['data-source'] = $options;
    }

    // Compatibilidad Meta Flow JSON: TextInput y TextArea no aceptan la propiedad placeholder.
    // Si existen placeholders guardados por versiones anteriores, se conservan localmente pero no se envían a Meta.

    $min_chars = absint($question['min_chars'] ?? 0);
    $max_chars = absint($question['max_chars'] ?? 0);
    if ( $min_chars > 0 && in_array($component['type'], ['TextInput', 'TextArea'], true) ) {
        $component['min-chars'] = $min_chars;
    }
    if ( $max_chars > 0 && in_array($component['type'], ['TextInput', 'TextArea'], true) ) {
        $component['max-chars'] = $max_chars;
    }

    return $component;
}


function eventosapp_whatsapp_flows_build_flow_json($flow_post_id, $override_config = []) {
    $config = $flow_post_id ? eventosapp_whatsapp_flows_get_flow_config($flow_post_id) : [];
    $config = wp_parse_args($override_config, $config);

    $title        = sanitize_text_field((string)($config['title'] ?? 'Encuesta del evento'));
    $description  = sanitize_textarea_field((string)($config['description'] ?? 'Completa esta breve encuesta.'));
    $submit_label = sanitize_text_field((string)($config['submit_label'] ?? 'Enviar respuestas'));
    $screen_id    = eventosapp_whatsapp_flows_sanitize_flow_screen_id($config['screen_id'] ?? 'SURVEY', 'SURVEY');
    $questions    = eventosapp_whatsapp_flows_normalize_questions($config['questions'] ?? []);
    $per_screen   = min(15, max(3, absint($config['questions_per_screen'] ?? 8)));

    $display_types = eventosapp_whatsapp_flows_display_question_types();
    $input_types   = eventosapp_whatsapp_flows_input_question_types();
    $screens_questions = [];
    $current = [];
    $current_inputs = 0;

    foreach ( $questions as $question ) {
        $qtype = sanitize_key((string)($question['type'] ?? 'radio'));
        $is_input = in_array($qtype, $input_types, true);
        if ( $is_input && $current_inputs >= $per_screen && ! empty($current) ) {
            $screens_questions[] = $current;
            $current = [];
            $current_inputs = 0;
        }
        $current[] = $question;
        if ( $is_input ) {
            $current_inputs++;
        }
    }
    if ( ! empty($current) ) {
        $screens_questions[] = $current;
    }
    if ( empty($screens_questions) ) {
        $screens_questions = [eventosapp_whatsapp_flows_default_questions()];
    }

    $answer_questions = [];
    foreach ( $questions as $question ) {
        if ( in_array(sanitize_key((string)($question['type'] ?? '')), $input_types, true) ) {
            $slug = eventosapp_whatsapp_flows_sanitize_slug($question['slug'] ?? '', 'pregunta_' . (count($answer_questions) + 1));
            $answer_questions[$slug] = $question;
        }
    }

    $screen_ids = [];
    foreach ( $screens_questions as $idx => $_screen_questions ) {
        $screen_ids[$idx] = eventosapp_whatsapp_flows_make_screen_id($screen_id, $idx);
    }

    $screens = [];
    $previous_slugs = [];

    foreach ( $screens_questions as $screen_index => $screen_questions ) {
        $is_last_screen = $screen_index === (count($screens_questions) - 1);
        $children = [];

        if ( $screen_index === 0 ) {
            if ( $title !== '' ) {
                $children[] = ['type' => 'TextHeading', 'text' => eventosapp_whatsapp_flows_text_limit($title, 80)];
            }
            if ( $description !== '' ) {
                $children[] = ['type' => 'TextBody', 'text' => eventosapp_whatsapp_flows_text_limit($description, 4096)];
            }
        } else {
            $children[] = ['type' => 'TextSubheading', 'text' => 'Continuemos con la encuesta'];
        }

        $form_children = [];
        $current_screen_slugs = [];

        foreach ( $screen_questions as $index => $question ) {
            $qtype = sanitize_key((string)($question['type'] ?? 'radio'));
            $help = sanitize_textarea_field((string)($question['help'] ?? ''));
            $component = eventosapp_whatsapp_flows_question_to_component($question);

            if ( $help !== '' ) {
                $form_children[] = [
                    'type' => 'TextCaption',
                    'text' => eventosapp_whatsapp_flows_text_limit($help, 300),
                ];
            }

            $form_children[] = $component;

            if ( in_array($qtype, $input_types, true) ) {
                $current_screen_slugs[] = eventosapp_whatsapp_flows_sanitize_slug($question['slug'] ?? '', 'pregunta_' . ($index + 1));
            }
        }

        $payload = [
            'eventosapp_flow_post_id' => (string) absint($flow_post_id),
        ];

        foreach ( $previous_slugs as $slug ) {
            $payload[$slug] = '${data.' . $slug . '}';
        }
        foreach ( $current_screen_slugs as $slug ) {
            $payload[$slug] = '${form.' . $slug . '}';
        }

        $footer_action = [
            'name'    => $is_last_screen ? 'complete' : 'navigate',
            'payload' => $payload,
        ];
        if ( ! $is_last_screen ) {
            $footer_action['next'] = [
                'type' => 'screen',
                'name' => $screen_ids[$screen_index + 1],
            ];
        }

        $form_children[] = [
            'type'            => 'Footer',
            'label'           => $is_last_screen ? ($submit_label !== '' ? $submit_label : 'Enviar respuestas') : 'Continuar',
            'on-click-action' => $footer_action,
        ];

        $children[] = [
            'type'     => 'Form',
            'name'     => 'eventosapp_survey_form_' . ($screen_index + 1),
            'children' => $form_children,
        ];

        $data_schema = new stdClass();
        if ( ! empty($previous_slugs) ) {
            $data_schema = [];
            foreach ( $previous_slugs as $slug ) {
                if ( isset($answer_questions[$slug]) ) {
                    $data_schema[$slug] = eventosapp_whatsapp_flows_question_answer_schema($answer_questions[$slug]);
                }
            }
        }

        $screen = [
            'id'       => $screen_ids[$screen_index],
            'title'    => $title !== '' ? eventosapp_whatsapp_flows_text_limit($title, 35) : 'Encuesta',
            'terminal' => $is_last_screen,
            'data'     => $data_schema,
            'layout'   => [
                'type'     => 'SingleColumnLayout',
                'children' => $children,
            ],
        ];
        if ( $is_last_screen ) {
            $screen['success'] = true;
        }

        $screens[] = $screen;
        $previous_slugs = array_values(array_unique(array_merge($previous_slugs, $current_screen_slugs)));
    }

    return [
        'version' => '7.3',
        'screens' => $screens,
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
        if ( $method === 'POST' ) {
            curl_setopt($ch, CURLOPT_POST, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Accept: application/json',
        ]);
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
    $screen_id = eventosapp_whatsapp_flows_sanitize_flow_screen_id($config['screen_id'] ?? 'SURVEY', 'SURVEY');

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
    $context_key = sanitize_key((string)($args['context'] ?? ($args['send_mode'] ?? 'whatsapp_flow_direct')));
    if ( $context_key === '' ) {
        $context_key = 'whatsapp_flow_direct';
    }
    $source_key = sanitize_text_field((string)($args['source_key'] ?? ''));
    $http_code = isset($result['http_code']) ? absint($result['http_code']) : 0;
    $sender_label = sanitize_text_field((string)($settings['sender_phone_label'] ?? ($settings['phone_number_label'] ?? '')));
    $transport_label = 'flow';
    $flow_log_name = 'Flow: ' . sanitize_text_field((string)($config['title'] ?? get_the_title($flow_post_id)));

    eventosapp_whatsapp_flows_update_send_row($send_id, [
        'wa_message_id' => $message_id,
        'status'        => ! empty($result['ok']) ? 'sent_request' : 'failed_request',
        'response_json' => eventosapp_whatsapp_flows_json_encode($result['response'] ?? $result, true),
        'error_message' => empty($result['ok']) ? (string)($result['message'] ?? 'Error al enviar Flow.') : '',
    ]);

    if ( $message_id !== '' && function_exists('eventosapp_whatsapp_register_message_map') ) {
        eventosapp_whatsapp_register_message_map($message_id, $ticket_id, $context_key, $to);
    }

    if ( $ticket_id && function_exists('eventosapp_whatsapp_add_ticket_log') ) {
        $log_args = [
            'context'    => $context_key,
            'source_key' => $source_key,
            'flow_post_id' => $flow_post_id,
            'flow_meta_id' => $meta_flow_id,
            'send_id'    => $send_id,
        ];

        $log_result = is_array($result) ? $result : [];
        $log_result['transport'] = $transport_label;
        $log_result['template_name'] = $flow_log_name;
        $log_result['message_id'] = $message_id;
        $log_result['http_code'] = $http_code;
        $log_result['delivery_status'] = ! empty($result['ok']) ? 'pendiente_webhook' : '';
        $log_result['debug'] = array_merge(
            isset($result['debug']) && is_array($result['debug']) ? $result['debug'] : [],
            [
                'transport' => $transport_label,
                'flow_post_id' => $flow_post_id,
                'flow_title' => $config['title'] ?? '',
                'meta_flow_id' => $meta_flow_id,
                'send_mode' => sanitize_key((string)($args['send_mode'] ?? 'direct_flow')),
                'context' => $context_key,
                'source_key' => $source_key,
                'sender_phone_number_id' => $sender_phone_number_id,
                'sender_phone_label' => $sender_label,
                'payload_summary' => $result['payload_summary'] ?? [
                    'type' => 'interactive',
                    'interactive_type' => 'flow',
                    'flow_id' => $meta_flow_id,
                    'flow_cta' => $cta,
                ],
            ]
        );

        if ( ! empty($result['ok']) ) {
            if ( function_exists('eventosapp_whatsapp_register_successful_send_tracking') ) {
                eventosapp_whatsapp_register_successful_send_tracking($ticket_id, $to, ['context' => $context_key], current_time('mysql'));
            }
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_status', 'aceptado_meta');
            update_post_meta($ticket_id, '_eventosapp_whatsapp_delivery_status', 'pendiente_webhook');
            delete_post_meta($ticket_id, '_eventosapp_whatsapp_delivery_at');
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_error', '');
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_response', $result['response'] ?? []);
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_message_id', $message_id);
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_transport', $transport_label);
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_template_name', $flow_log_name);
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_sender_phone_number_id', $sender_phone_number_id);
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_sender_label', $sender_label);
            if ( $source_key !== '' ) {
                update_post_meta($ticket_id, '_eventosapp_whatsapp_last_source_key', $source_key);
            }
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_http_code', $http_code);
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_debug', $log_result['debug']);
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_payload_summary', $log_result['debug']['payload_summary'] ?? []);
            eventosapp_whatsapp_add_ticket_log($ticket_id, 'aceptado_meta', $result['message'] ?? 'Solicitud de WhatsApp Flow aceptada por Meta.', $log_args, $to, $log_result);
        } else {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_status', 'error');
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_error', $result['message'] ?? 'Error al enviar Flow.');
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_response', $result['response'] ?? []);
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_transport', $transport_label);
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_template_name', $flow_log_name);
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_sender_phone_number_id', $sender_phone_number_id);
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_sender_label', $sender_label);
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_http_code', $http_code);
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_debug', $log_result['debug']);
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_payload_summary', $log_result['debug']['payload_summary'] ?? []);
            eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', $result['message'] ?? 'Error al enviar WhatsApp Flow.', $log_args, $to, $log_result);
        }
    }

    eventosapp_whatsapp_flows_add_activity(! empty($result['ok']) ? 'flow_envio_directo_solicitado' : 'flow_envio_directo_error', [
        'send_id'      => $send_id,
        'flow_post_id' => $flow_post_id,
        'meta_flow_id' => $meta_flow_id,
        'event_id'     => $event_id,
        'ticket_id'    => $ticket_id,
        'to'           => $to,
        'message_id'   => $message_id,
        'context'      => $context_key,
        'source_key'   => $source_key,
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

function eventosapp_whatsapp_flows_decode_nfm_response_json($raw_response) {
    if ( is_array($raw_response) ) {
        return $raw_response;
    }

    if ( is_object($raw_response) ) {
        return json_decode(wp_json_encode($raw_response), true) ?: [];
    }

    $raw_response = trim((string) $raw_response);
    if ( $raw_response === '' ) {
        return [];
    }

    $decoded = json_decode($raw_response, true);
    if ( is_array($decoded) ) {
        return $decoded;
    }

    $unescaped = wp_unslash($raw_response);
    if ( $unescaped !== $raw_response ) {
        $decoded = json_decode($unescaped, true);
        if ( is_array($decoded) ) {
            return $decoded;
        }
    }

    $html_decoded = html_entity_decode($raw_response, ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
    if ( $html_decoded !== $raw_response ) {
        $decoded = json_decode($html_decoded, true);
        if ( is_array($decoded) ) {
            return $decoded;
        }
    }

    return [];
}

function eventosapp_whatsapp_flows_extract_nfm_response($message) {
    if ( ! is_array($message) ) {
        return null;
    }

    if ( isset($message['entry']) || isset($message['object']) || isset($message['changes']) || isset($message['messages']) ) {
        return null;
    }

    if ( sanitize_key((string)($message['type'] ?? '')) !== 'interactive' ) {
        return null;
    }

    $interactive = isset($message['interactive']) && is_array($message['interactive']) ? $message['interactive'] : [];
    if ( sanitize_key((string)($interactive['type'] ?? '')) !== 'nfm_reply' ) {
        return null;
    }

    $reply = isset($interactive['nfm_reply']) && is_array($interactive['nfm_reply']) ? $interactive['nfm_reply'] : [];
    $raw_response = $reply['response_json'] ?? '';
    $decoded = eventosapp_whatsapp_flows_decode_nfm_response_json($raw_response);

    return [
        'name'          => sanitize_text_field((string)($reply['name'] ?? '')),
        'body'          => sanitize_textarea_field((string)($reply['body'] ?? '')),
        'response_raw'  => is_scalar($raw_response) ? (string) $raw_response : eventosapp_whatsapp_flows_json_encode($raw_response, false),
        'response_json' => $decoded,
    ];
}

function eventosapp_whatsapp_flows_is_whatsapp_payload($payload) {
    if ( ! is_array($payload) ) {
        return false;
    }

    if ( isset($payload['object']) && (string) $payload['object'] === 'whatsapp_business_account' ) {
        return true;
    }

    if ( isset($payload['entry']) && is_array($payload['entry']) ) {
        return true;
    }

    if ( isset($payload['messages']) && is_array($payload['messages']) ) {
        return true;
    }

    if ( isset($payload['changes']) && is_array($payload['changes']) ) {
        return true;
    }

    return false;
}

function eventosapp_whatsapp_flows_payload_has_nfm_reply($payload) {
    if ( ! is_array($payload) ) {
        return false;
    }

    foreach ( $payload as $key => $value ) {
        if ( $key === 'nfm_reply' ) {
            return true;
        }
        if ( is_array($value) && eventosapp_whatsapp_flows_payload_has_nfm_reply($value) ) {
            return true;
        }
    }

    return false;
}

function eventosapp_whatsapp_flows_payload_has_statuses($payload) {
    if ( ! is_array($payload) ) {
        return false;
    }

    if ( isset($payload['statuses']) && is_array($payload['statuses']) ) {
        return true;
    }

    foreach ( $payload as $value ) {
        if ( is_array($value) && eventosapp_whatsapp_flows_payload_has_statuses($value) ) {
            return true;
        }
    }

    return false;
}

function eventosapp_whatsapp_flows_extract_messages_from_payload($payload) {
    $messages = [];

    if ( ! is_array($payload) ) {
        return $messages;
    }

    if ( isset($payload['type'], $payload['interactive']) && is_array($payload['interactive']) ) {
        $messages[] = [
            'message' => $payload,
            'value'   => [],
            'entry'   => [],
            'change'  => [],
        ];
        return $messages;
    }

    if ( isset($payload['messages']) && is_array($payload['messages']) ) {
        foreach ( $payload['messages'] as $message ) {
            if ( is_array($message) ) {
                $messages[] = [
                    'message' => $message,
                    'value'   => $payload,
                    'entry'   => [],
                    'change'  => [],
                ];
            }
        }
    }

    if ( isset($payload['entry']) && is_array($payload['entry']) ) {
        foreach ( $payload['entry'] as $entry ) {
            if ( ! is_array($entry) ) {
                continue;
            }
            $changes = isset($entry['changes']) && is_array($entry['changes']) ? $entry['changes'] : [];
            foreach ( $changes as $change ) {
                if ( ! is_array($change) ) {
                    continue;
                }
                $value = isset($change['value']) && is_array($change['value']) ? $change['value'] : [];
                if ( empty($value['messages']) || ! is_array($value['messages']) ) {
                    continue;
                }
                foreach ( $value['messages'] as $message ) {
                    if ( is_array($message) ) {
                        $messages[] = [
                            'message' => $message,
                            'value'   => $value,
                            'entry'   => $entry,
                            'change'  => $change,
                        ];
                    }
                }
            }
        }
    }

    if ( isset($payload['changes']) && is_array($payload['changes']) ) {
        foreach ( $payload['changes'] as $change ) {
            if ( ! is_array($change) ) {
                continue;
            }
            $value = isset($change['value']) && is_array($change['value']) ? $change['value'] : [];
            if ( empty($value['messages']) || ! is_array($value['messages']) ) {
                continue;
            }
            foreach ( $value['messages'] as $message ) {
                if ( is_array($message) ) {
                    $messages[] = [
                        'message' => $message,
                        'value'   => $value,
                        'entry'   => [],
                        'change'  => $change,
                    ];
                }
            }
        }
    }

    return $messages;
}

function eventosapp_whatsapp_flows_extract_statuses_from_payload($payload) {
    $statuses = [];

    if ( ! is_array($payload) ) {
        return $statuses;
    }

    if ( isset($payload['statuses']) && is_array($payload['statuses']) ) {
        foreach ( $payload['statuses'] as $status ) {
            if ( is_array($status) ) {
                $statuses[] = [
                    'status' => $status,
                    'value'  => $payload,
                    'entry'  => [],
                    'change' => [],
                ];
            }
        }
    }

    if ( isset($payload['entry']) && is_array($payload['entry']) ) {
        foreach ( $payload['entry'] as $entry ) {
            if ( ! is_array($entry) ) {
                continue;
            }
            $changes = isset($entry['changes']) && is_array($entry['changes']) ? $entry['changes'] : [];
            foreach ( $changes as $change ) {
                if ( ! is_array($change) ) {
                    continue;
                }
                $value = isset($change['value']) && is_array($change['value']) ? $change['value'] : [];
                if ( empty($value['statuses']) || ! is_array($value['statuses']) ) {
                    continue;
                }
                foreach ( $value['statuses'] as $status ) {
                    if ( is_array($status) ) {
                        $statuses[] = [
                            'status' => $status,
                            'value'  => $value,
                            'entry'  => $entry,
                            'change' => $change,
                        ];
                    }
                }
            }
        }
    }

    if ( isset($payload['changes']) && is_array($payload['changes']) ) {
        foreach ( $payload['changes'] as $change ) {
            if ( ! is_array($change) ) {
                continue;
            }
            $value = isset($change['value']) && is_array($change['value']) ? $change['value'] : [];
            if ( empty($value['statuses']) || ! is_array($value['statuses']) ) {
                continue;
            }
            foreach ( $value['statuses'] as $status ) {
                if ( is_array($status) ) {
                    $statuses[] = [
                        'status' => $status,
                        'value'  => $value,
                        'entry'  => [],
                        'change' => $change,
                    ];
                }
            }
        }
    }

    return $statuses;
}

function eventosapp_whatsapp_flows_process_webhook_payload($payload, $source = 'payload_bridge') {
    if ( is_string($payload) ) {
        $payload = json_decode($payload, true);
    }

    $has_nfm_reply = eventosapp_whatsapp_flows_payload_has_nfm_reply($payload);
    $has_statuses = eventosapp_whatsapp_flows_payload_has_statuses($payload);

    if ( ! eventosapp_whatsapp_flows_is_whatsapp_payload($payload) || ( ! $has_nfm_reply && ! $has_statuses ) ) {
        return [
            'ok'        => false,
            'processed' => 0,
            'message'   => 'El payload no contiene respuestas nfm_reply ni estados de WhatsApp Flow.',
        ];
    }

    $items = $has_nfm_reply ? eventosapp_whatsapp_flows_extract_messages_from_payload($payload) : [];
    $statuses = $has_statuses ? eventosapp_whatsapp_flows_extract_statuses_from_payload($payload) : [];
    $processed = 0;
    $received = 0;
    $status_processed = 0;

    foreach ( $items as $item ) {
        $message = $item['message'];
        if ( ! eventosapp_whatsapp_flows_extract_nfm_response($message) ) {
            continue;
        }
        $received++;
        eventosapp_whatsapp_flows_handle_inbound_response(
            $message,
            $item['value'] ?? [],
            $item['entry'] ?? [],
            $item['change'] ?? [],
            $payload
        );
        $processed++;
    }

    foreach ( $statuses as $item ) {
        if ( empty($item['status']) || ! is_array($item['status']) ) {
            continue;
        }
        eventosapp_whatsapp_flows_handle_status_update($item['status'], [
            'value'  => $item['value'] ?? [],
            'entry'  => $item['entry'] ?? [],
            'change' => $item['change'] ?? [],
            'source' => sanitize_key((string) $source),
        ]);
        $status_processed++;
    }

    if ( $processed > 0 || $status_processed > 0 ) {
        eventosapp_whatsapp_flows_add_activity('flow_webhook_payload_procesado', [
            'source'           => sanitize_key((string) $source),
            'responses'        => $processed,
            'responses_found'  => $received,
            'statuses'         => $status_processed,
        ]);
    }

    return [
        'ok'        => ($processed + $status_processed) > 0,
        'processed' => $processed + $status_processed,
        'responses' => $processed,
        'statuses'  => $status_processed,
        'received'  => $received,
        'message'   => ($processed + $status_processed) > 0 ? 'Webhook de Flow procesado.' : 'No se encontró ningún mensaje nfm_reply ni estado procesable.',
    ];
}

function eventosapp_whatsapp_flows_capture_payload_once($payload, $source = 'bridge') {
    static $hashes = [];

    if ( is_string($payload) ) {
        $raw = $payload;
    } else {
        $raw = eventosapp_whatsapp_flows_json_encode($payload, false);
    }

    if ( trim((string) $raw) === '' ) {
        return [
            'ok'        => false,
            'processed' => 0,
            'message'   => 'Payload vacío.',
        ];
    }

    $hash = md5((string) $raw);
    if ( isset($hashes[$hash]) ) {
        return [
            'ok'        => false,
            'processed' => 0,
            'message'   => 'Payload ya procesado en esta petición.',
        ];
    }
    $hashes[$hash] = true;

    return eventosapp_whatsapp_flows_process_webhook_payload($payload, $source);
}

function eventosapp_whatsapp_flows_handle_payload_action($payload = [], $value = [], $entry = [], $change = [], $extra = []) {
    if ( eventosapp_whatsapp_flows_extract_nfm_response($payload) ) {
        eventosapp_whatsapp_flows_handle_inbound_response($payload, is_array($value) ? $value : [], is_array($entry) ? $entry : [], is_array($change) ? $change : [], is_array($extra) ? $extra : []);
        return;
    }

    eventosapp_whatsapp_flows_capture_payload_once($payload, 'action_bridge');
}

function eventosapp_whatsapp_flows_capture_rest_webhook_payload($result, $server, $request) {
    if ( ! $request instanceof WP_REST_Request ) {
        return $result;
    }

    if ( strtoupper((string) $request->get_method()) !== 'POST' ) {
        return $result;
    }

    $route = strtolower((string) $request->get_route());
    $body = (string) $request->get_body();
    if ( $body === '' || (strpos($route, 'whatsapp') === false && strpos($route, 'webhook') === false && strpos($body, 'nfm_reply') === false && strpos($body, 'statuses') === false) ) {
        return $result;
    }

    $payload = json_decode($body, true);
    if ( is_array($payload) ) {
        eventosapp_whatsapp_flows_capture_payload_once($payload, 'rest_pre_dispatch');
    }

    return $result;
}
add_filter('rest_pre_dispatch', 'eventosapp_whatsapp_flows_capture_rest_webhook_payload', 9, 3);

function eventosapp_whatsapp_flows_capture_shutdown_webhook_payload() {
    if ( empty($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST' ) {
        return;
    }

    $uri = strtolower((string)($_SERVER['REQUEST_URI'] ?? ''));
    $content_type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if ( strpos($content_type, 'json') === false && strpos($uri, 'whatsapp') === false && strpos($uri, 'webhook') === false && strpos($uri, 'wp-json') === false ) {
        return;
    }

    $raw = file_get_contents('php://input');
    if ( ! is_string($raw) || $raw === '' || (strpos($raw, 'nfm_reply') === false && strpos($raw, 'statuses') === false) ) {
        return;
    }

    $payload = json_decode($raw, true);
    if ( is_array($payload) ) {
        eventosapp_whatsapp_flows_capture_payload_once($payload, 'shutdown_bridge');
    }
}
add_action('shutdown', 'eventosapp_whatsapp_flows_capture_shutdown_webhook_payload', 1);

add_action('eventosapp_whatsapp_webhook_inbound_message_received', 'eventosapp_whatsapp_flows_handle_inbound_response', 8, 5);
add_action('eventosapp_whatsapp_webhook_payload_received', 'eventosapp_whatsapp_flows_handle_payload_action', 8, 5);
add_action('eventosapp_whatsapp_webhook_received', 'eventosapp_whatsapp_flows_handle_payload_action', 8, 5);
add_action('eventosapp_whatsapp_webhook_raw_payload_received', 'eventosapp_whatsapp_flows_handle_payload_action', 8, 5);
add_action('eventosapp_whatsapp_webhook_inbound_payload_received', 'eventosapp_whatsapp_flows_handle_payload_action', 8, 5);

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
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_screen_id', eventosapp_whatsapp_flows_sanitize_flow_screen_id($_POST['flow_screen_id'] ?? 'SURVEY', 'SURVEY'));
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_questions_per_screen', min(15, max(3, absint($_POST['flow_questions_per_screen'] ?? 8))));
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
    $flow_json = eventosapp_whatsapp_flows_json_encode(eventosapp_whatsapp_flows_build_flow_json($flow_post_id), false);
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_json', eventosapp_whatsapp_flows_json_encode(eventosapp_whatsapp_flows_build_flow_json($flow_post_id), true));

    $body = [
        'name'       => sanitize_text_field($config['title']),
        'categories' => [$config['category']],
        // Se envía el JSON desde la creación para evitar que Meta cree el Flow con el ejemplo
        // predeterminado “Hello World”. Aun así se conserva el botón “Subir JSON” para
        // reenviar cambios mientras el Flow esté en borrador.
        'flow_json'  => $flow_json,
        'publish'    => false,
    ];

    $result = eventosapp_whatsapp_flows_graph_request('POST', $waba_id . '/flows', $body, $settings);
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_meta_response', $result);
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_sync_at', current_time('mysql'));

    if ( ! empty($result['ok']) ) {
        $meta_flow_id = eventosapp_whatsapp_flows_extract_meta_flow_id($result['response'] ?? []);
        if ( $meta_flow_id !== '' ) {
            update_post_meta($flow_post_id, '_eventosapp_wa_flow_meta_id', $meta_flow_id);
            update_post_meta($flow_post_id, '_eventosapp_wa_flow_waba_id', $waba_id);
            update_post_meta($flow_post_id, '_eventosapp_wa_flow_status', 'draft_meta_json_ready');
            update_post_meta($flow_post_id, '_eventosapp_wa_flow_created_at_meta', current_time('mysql'));
        }
        eventosapp_whatsapp_flows_add_activity('flow_creado_en_meta', ['flow_post_id' => $flow_post_id, 'meta_flow_id' => $meta_flow_id, 'result' => $result]);
        eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'success', 'flow_message' => rawurlencode('Flow creado en Meta correctamente con el JSON local generado por EventosApp. Revisa la vista previa antes de publicar.')]);
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

    $current_status = sanitize_key((string)($config['status'] ?? ''));
    if ( in_array($current_status, ['published', 'publicado'], true) ) {
        eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'error', 'flow_message' => rawurlencode('Meta no permite modificar el JSON de un Flow ya publicado. Presiona “Crear/Recrear en Meta con JSON local” para generar un nuevo Flow ID con la configuración actual de EventosApp.')]);
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

    $meta_success = true;
    if ( is_array($result['response'] ?? null) && array_key_exists('success', $result['response']) ) {
        $meta_success = ! empty($result['response']['success']);
    }

    if ( ! empty($result['ok']) && $meta_success ) {
        update_post_meta($flow_post_id, '_eventosapp_wa_flow_status', empty($validation_errors) ? 'json_uploaded' : 'json_with_validation_errors');
        eventosapp_whatsapp_flows_add_activity('flow_json_subido', ['flow_post_id' => $flow_post_id, 'meta_flow_id' => $meta_flow_id, 'validation_errors' => $validation_errors, 'result' => $result]);
        $msg = empty($validation_errors) ? 'JSON subido y validado por Meta. Ahora pide la vista previa para confirmar que no siga apareciendo el ejemplo “Hello World”.' : 'JSON subido, pero Meta reportó errores de validación. Revisa el detalle antes de publicar.';
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

    if ( ! empty($config['validation_errors']) && is_array($config['validation_errors']) ) {
        eventosapp_whatsapp_flows_notice_redirect(['flow_id' => $flow_post_id, 'flow_notice' => 'error', 'flow_message' => rawurlencode('No se debe publicar todavía: Meta reporta errores de validación en el JSON del Flow.')]);
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
    $result = eventosapp_whatsapp_flows_graph_request('GET', $meta_flow_id . '?fields=id,name,status,categories,validation_errors,json_version,data_api_version,preview', null, $settings);
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
    $result = eventosapp_whatsapp_flows_graph_request('GET', $meta_flow_id . '?fields=preview.invalidate(false)', null, $settings);
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_meta_response', $result);
    update_post_meta($flow_post_id, '_eventosapp_wa_flow_last_sync_at', current_time('mysql'));

    if ( ! empty($result['ok']) && is_array($result['response'] ?? null) ) {
        $preview_url = esc_url_raw((string)($result['response']['preview']['preview_url'] ?? ($result['response']['preview_url'] ?? ($result['response']['url'] ?? ''))));
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

/**
 * Envío masivo de WhatsApp Flows: helpers de segmentación, vista previa y progreso.
 */
function eventosapp_whatsapp_flows_bulk_segment_option_key($segment_id) {
    return 'evapp_whatsapp_flow_segment_' . sanitize_key((string) $segment_id);
}

function eventosapp_whatsapp_flows_bulk_status_options() {
    if ( function_exists('eventosapp_whatsapp_masivo_status_options') ) {
        return eventosapp_whatsapp_masivo_status_options();
    }
    return [
        'no_enviado'    => 'No enviado (sin solicitud aceptada por Meta)',
        'enviado'       => 'Enviado (solicitud aceptada por Meta)',
        'aceptado_meta' => 'Último estado: aceptado por Meta',
        'error'         => 'Último estado: error local/API',
        'skipped'       => 'Último estado: omitido',
        'preparado'     => 'Último estado: preparado',
    ];
}

function eventosapp_whatsapp_flows_bulk_delivery_options() {
    if ( function_exists('eventosapp_whatsapp_masivo_delivery_options') ) {
        return eventosapp_whatsapp_masivo_delivery_options();
    }
    return [
        'pendiente_webhook' => 'Pendiente de webhook',
        'sent'              => 'Enviado por WhatsApp',
        'delivered'         => 'Entregado al dispositivo',
        'read'              => 'Leído por el usuario',
        'failed'            => 'Fallido en Meta',
    ];
}

function eventosapp_whatsapp_flows_bulk_modalidad_labels() {
    if ( function_exists('eventosapp_whatsapp_masivo_ticket_modalidad_labels') ) {
        return eventosapp_whatsapp_masivo_ticket_modalidad_labels();
    }
    if ( function_exists('eventosapp_ticket_modalidad_options') ) {
        return eventosapp_ticket_modalidad_options();
    }
    return [
        'presencial' => 'Presencial',
        'virtual'    => 'Virtual',
    ];
}

function eventosapp_whatsapp_flows_bulk_get_ticket_modalidad_key($ticket_id, $event_id = 0) {
    $ticket_id = absint($ticket_id);
    $event_id = absint($event_id);
    $mode = '';

    if ( function_exists('eventosapp_get_ticket_modalidad') ) {
        $mode = eventosapp_get_ticket_modalidad($ticket_id);
    }
    if ( $mode === '' ) {
        $mode = get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
    }
    if ( $mode === '' && $event_id ) {
        if ( function_exists('eventosapp_ticket_allowed_modalidades_for_event') ) {
            $allowed = eventosapp_ticket_allowed_modalidades_for_event($event_id);
            $mode = is_array($allowed) && ! empty($allowed) ? reset($allowed) : '';
        } elseif ( function_exists('eventosapp_get_event_modalidad') ) {
            $event_mode = eventosapp_get_event_modalidad($event_id);
            $mode = $event_mode === 'virtual' ? 'virtual' : 'presencial';
        }
    }

    $mode = function_exists('eventosapp_normalize_ticket_modalidad') ? eventosapp_normalize_ticket_modalidad($mode) : sanitize_key((string) $mode);
    return in_array($mode, ['presencial', 'virtual'], true) ? $mode : 'presencial';
}

function eventosapp_whatsapp_flows_bulk_get_event_modalidad_label($event_id) {
    $event_id = absint($event_id);
    if ( $event_id && function_exists('eventosapp_get_event_modalidad_label') ) {
        return eventosapp_get_event_modalidad_label($event_id);
    }
    $mode = $event_id && function_exists('eventosapp_get_event_modalidad') ? eventosapp_get_event_modalidad($event_id) : '';
    $labels = function_exists('eventosapp_event_modalidad_options') ? eventosapp_event_modalidad_options() : [
        'presencial' => 'Presencial',
        'virtual'    => 'Virtual',
        'hibrido'    => 'Presencial y Virtual',
    ];
    return $labels[$mode] ?? ($mode !== '' ? ucfirst((string) $mode) : 'No definida');
}

function eventosapp_whatsapp_flows_bulk_flow_label($flow_post_id) {
    $flow_post_id = absint($flow_post_id);
    if ( ! $flow_post_id ) {
        return 'Flow no seleccionado';
    }
    $config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);
    $title = sanitize_text_field((string)($config['title'] ?? get_the_title($flow_post_id)));
    $meta_flow_id = preg_replace('/\D+/', '', (string)($config['meta_flow_id'] ?? ''));
    $status = sanitize_text_field((string)($config['status'] ?? ''));
    $parts = [$title !== '' ? $title : ('Flow #' . $flow_post_id)];
    if ( $meta_flow_id !== '' ) {
        $parts[] = 'Meta ID ' . $meta_flow_id;
    }
    if ( $status !== '' ) {
        $parts[] = 'Estado ' . $status;
    }
    return implode(' — ', $parts);
}

function eventosapp_whatsapp_flows_bulk_get_event_extra_fields_schema($event_id) {
    $event_id = absint($event_id);
    if ( ! $event_id ) {
        return [];
    }
    if ( function_exists('eventosapp_whatsapp_masivo_get_event_extra_fields_schema') ) {
        return eventosapp_whatsapp_masivo_get_event_extra_fields_schema($event_id);
    }

    $fields = [];
    $raw_fields = [];
    if ( function_exists('eventosapp_get_event_extra_fields') ) {
        $raw_fields = eventosapp_get_event_extra_fields($event_id);
    }
    if ( empty($raw_fields) || ! is_array($raw_fields) ) {
        $raw_fields = get_post_meta($event_id, '_eventosapp_extra_fields', true);
    }
    if ( ! is_array($raw_fields) ) {
        return [];
    }

    foreach ( $raw_fields as $field ) {
        if ( ! is_array($field) ) {
            continue;
        }
        $key = sanitize_key((string)($field['key'] ?? ($field['name'] ?? ($field['id'] ?? ''))));
        if ( $key === '' ) {
            continue;
        }
        $label = sanitize_text_field((string)($field['label'] ?? ($field['name'] ?? $key)));
        $options = [];
        if ( ! empty($field['options']) ) {
            if ( is_array($field['options']) ) {
                $options = array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $field['options']))));
            } else {
                $options = array_values(array_filter(array_map('sanitize_text_field', array_map('trim', explode("\n", (string) $field['options'])))));
            }
        }
        $fields[] = [
            'key'     => $key,
            'label'   => $label,
            'type'    => sanitize_key((string)($field['type'] ?? 'text')),
            'options' => array_values(array_unique($options)),
        ];
    }

    return $fields;
}

function eventosapp_whatsapp_flows_bulk_sanitize_filters($filters) {
    $filters = is_array($filters) ? $filters : [];
    $clean = [];

    foreach ( $filters as $key => $value ) {
        $key = sanitize_key((string) $key);
        if ( $key === '' ) {
            continue;
        }

        if ( $key === 'extra_fields' && is_array($value) ) {
            $clean['extra_fields'] = [];
            foreach ( $value as $field_key => $field_value ) {
                $field_key = sanitize_key((string) $field_key);
                if ( $field_key === '' ) {
                    continue;
                }
                $field_value = sanitize_text_field((string) $field_value);
                if ( $field_value !== '' ) {
                    $clean['extra_fields'][$field_key] = $field_value;
                }
            }
            if ( empty($clean['extra_fields']) ) {
                unset($clean['extra_fields']);
            }
            continue;
        }

        $value = is_scalar($value) ? sanitize_text_field((string) $value) : '';
        if ( $value !== '' ) {
            $clean[$key] = $value;
        }
    }

    return $clean;
}

function eventosapp_whatsapp_flows_bulk_existing_ticket_ids($flow_post_id, $event_id, $ticket_ids = []) {
    global $wpdb;
    eventosapp_whatsapp_flows_maybe_install_tables();

    $flow_post_id = absint($flow_post_id);
    $event_id = absint($event_id);
    $ticket_ids = array_values(array_unique(array_filter(array_map('absint', is_array($ticket_ids) ? $ticket_ids : []))));
    if ( ! $flow_post_id || ! $event_id ) {
        return [];
    }

    $table = eventosapp_whatsapp_flows_sends_table_name();
    $found = [];
    $failed_like = 'failed%';

    if ( empty($ticket_ids) ) {
        $sql = $wpdb->prepare(
            "SELECT DISTINCT ticket_id FROM {$table} WHERE flow_post_id = %d AND event_id = %d AND ticket_id > 0 AND status NOT LIKE %s",
            $flow_post_id,
            $event_id,
            $failed_like
        );
        return array_map('absint', (array) $wpdb->get_col($sql));
    }

    foreach ( array_chunk($ticket_ids, 800) as $chunk ) {
        $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
        $params = array_merge([$flow_post_id, $event_id, $failed_like], $chunk);
        $sql = $wpdb->prepare(
            "SELECT DISTINCT ticket_id FROM {$table} WHERE flow_post_id = %d AND event_id = %d AND ticket_id > 0 AND status NOT LIKE %s AND ticket_id IN ({$placeholders})",
            $params
        );
        $found = array_merge($found, array_map('absint', (array) $wpdb->get_col($sql)));
    }

    return array_values(array_unique($found));
}

function eventosapp_whatsapp_flows_bulk_get_filtered_tickets($filters) {
    $filters = is_array($filters) ? $filters : [];

    $args = [
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'posts_per_page' => -1,
        'no_found_rows'  => true,
    ];

    $meta_query = ['relation' => 'AND'];
    $date_query = [];

    if ( ! empty($filters['evento_id']) ) {
        $meta_query[] = [
            'key'     => '_eventosapp_ticket_evento_id',
            'value'   => absint($filters['evento_id']),
            'compare' => '=',
        ];
    }

    if ( ! empty($filters['localidad']) ) {
        $meta_query[] = [
            'key'     => '_eventosapp_asistente_localidad',
            'value'   => sanitize_text_field((string) $filters['localidad']),
            'compare' => '=',
        ];
    }

    $modalidad_filter = '';
    if ( ! empty($filters['modalidad']) ) {
        $modalidad_filter = function_exists('eventosapp_normalize_ticket_modalidad')
            ? eventosapp_normalize_ticket_modalidad($filters['modalidad'])
            : sanitize_key((string) $filters['modalidad']);
        if ( ! in_array($modalidad_filter, ['presencial', 'virtual'], true) ) {
            $modalidad_filter = '';
        }
    }

    $whatsapp_tracking_filter = '';
    if ( ! empty($filters['whatsapp_status']) ) {
        $whatsapp_status = sanitize_key((string) $filters['whatsapp_status']);
        if ( in_array($whatsapp_status, ['no_enviado', 'enviado'], true) ) {
            $whatsapp_tracking_filter = $whatsapp_status;
        } else {
            $meta_query[] = [
                'key'     => '_eventosapp_whatsapp_last_status',
                'value'   => $whatsapp_status,
                'compare' => '=',
            ];
        }
    }

    if ( ! empty($filters['delivery_status']) ) {
        $meta_query[] = [
            'key'     => '_eventosapp_whatsapp_delivery_status',
            'value'   => sanitize_key((string) $filters['delivery_status']),
            'compare' => '=',
        ];
    }

    if ( ! empty($filters['last_sent_from']) || ! empty($filters['last_sent_to']) ) {
        $date_meta = [
            'key'  => '_eventosapp_whatsapp_last_sent_at',
            'type' => 'DATETIME',
        ];
        if ( ! empty($filters['last_sent_from']) && ! empty($filters['last_sent_to']) ) {
            $date_meta['value'] = [sanitize_text_field((string) $filters['last_sent_from']) . ' 00:00:00', sanitize_text_field((string) $filters['last_sent_to']) . ' 23:59:59'];
            $date_meta['compare'] = 'BETWEEN';
        } elseif ( ! empty($filters['last_sent_from']) ) {
            $date_meta['value'] = sanitize_text_field((string) $filters['last_sent_from']) . ' 00:00:00';
            $date_meta['compare'] = '>=';
        } else {
            $date_meta['value'] = sanitize_text_field((string) $filters['last_sent_to']) . ' 23:59:59';
            $date_meta['compare'] = '<=';
        }
        $meta_query[] = $date_meta;
    }

    if ( ! empty($filters['created_from']) || ! empty($filters['created_to']) ) {
        if ( ! empty($filters['created_from']) && ! empty($filters['created_to']) ) {
            $date_query = [
                'after'     => sanitize_text_field((string) $filters['created_from']) . ' 00:00:00',
                'before'    => sanitize_text_field((string) $filters['created_to']) . ' 23:59:59',
                'inclusive' => true,
            ];
        } elseif ( ! empty($filters['created_from']) ) {
            $date_query = [
                'after'     => sanitize_text_field((string) $filters['created_from']) . ' 00:00:00',
                'inclusive' => true,
            ];
        } else {
            $date_query = [
                'before'    => sanitize_text_field((string) $filters['created_to']) . ' 23:59:59',
                'inclusive' => true,
            ];
        }
    }

    if ( ! empty($filters['extra_fields']) && is_array($filters['extra_fields']) ) {
        foreach ( $filters['extra_fields'] as $field_key => $field_value ) {
            if ( $field_value === '' || $field_value === null ) {
                continue;
            }
            $meta_query[] = [
                'key'     => '_eventosapp_extra_' . sanitize_key((string) $field_key),
                'value'   => sanitize_text_field((string) $field_value),
                'compare' => 'LIKE',
            ];
        }
    }

    if ( count($meta_query) > 1 ) {
        $args['meta_query'] = $meta_query;
    }
    if ( ! empty($date_query) ) {
        $args['date_query'] = $date_query;
    }

    $query = new WP_Query($args);
    $ticket_ids = array_map('absint', (array) $query->posts);
    if ( ! empty($ticket_ids) ) {
        update_meta_cache('post', $ticket_ids);
    }

    if ( ! empty($filters['event_date']) ) {
        $event_date = sanitize_text_field((string) $filters['event_date']);
        $filtered_ids = [];
        foreach ( $ticket_ids as $tid ) {
            $evento_id = absint(get_post_meta($tid, '_eventosapp_ticket_evento_id', true));
            if ( $evento_id && function_exists('eventosapp_get_event_days') ) {
                $event_days = eventosapp_get_event_days($evento_id);
                if ( is_array($event_days) && in_array($event_date, $event_days, true) ) {
                    $filtered_ids[] = $tid;
                }
            }
        }
        $ticket_ids = $filtered_ids;
    }

    if ( $modalidad_filter !== '' ) {
        $ticket_ids = array_values(array_filter($ticket_ids, function($tid) use ($modalidad_filter) {
            $event_id = absint(get_post_meta($tid, '_eventosapp_ticket_evento_id', true));
            return eventosapp_whatsapp_flows_bulk_get_ticket_modalidad_key($tid, $event_id) === $modalidad_filter;
        }));
    }

    if ( $whatsapp_tracking_filter !== '' ) {
        $ticket_ids = array_values(array_filter($ticket_ids, function($tid) use ($whatsapp_tracking_filter) {
            if ( function_exists('eventosapp_whatsapp_get_send_tracking') ) {
                $tracking = eventosapp_whatsapp_get_send_tracking($tid, true);
                $sent_status = is_array($tracking) && ! empty($tracking['sent_status']) ? sanitize_key((string) $tracking['sent_status']) : 'no_enviado';
            } else {
                $sent_status = sanitize_key((string) get_post_meta($tid, '_eventosapp_whatsapp_sent_status', true));
                if ( $sent_status === '' ) {
                    $first_sent = get_post_meta($tid, '_eventosapp_whatsapp_first_sent_at', true);
                    $last_sent = get_post_meta($tid, '_eventosapp_whatsapp_last_sent_at', true);
                    $sent_status = ($first_sent !== '' || $last_sent !== '') ? 'enviado' : 'no_enviado';
                }
            }
            return $whatsapp_tracking_filter === 'enviado' ? $sent_status === 'enviado' : $sent_status !== 'enviado';
        }));
    }

    $flow_send_status = sanitize_key((string)($filters['flow_send_status'] ?? ''));
    $flow_post_id = absint($filters['flow_id'] ?? 0);
    $event_id = absint($filters['evento_id'] ?? 0);
    if ( $flow_post_id && $event_id && in_array($flow_send_status, ['no_recibido', 'recibido'], true) ) {
        $existing = eventosapp_whatsapp_flows_bulk_existing_ticket_ids($flow_post_id, $event_id, $ticket_ids);
        if ( ! empty($existing) ) {
            $existing_map = array_fill_keys(array_map('strval', $existing), true);
            $ticket_ids = array_values(array_filter($ticket_ids, function($tid) use ($flow_send_status, $existing_map) {
                $has_existing = isset($existing_map[(string) absint($tid)]);
                return $flow_send_status === 'recibido' ? $has_existing : ! $has_existing;
            }));
        } elseif ( $flow_send_status === 'recibido' ) {
            $ticket_ids = [];
        }
    }

    return array_values(array_unique(array_map('absint', $ticket_ids)));
}

function eventosapp_whatsapp_flows_bulk_preview_ticket_row($ticket_id, $flow_post_id, $event_id) {
    $ticket_id = absint($ticket_id);
    $event_id = absint($event_id ?: get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $first = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
    $last = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
    $name = trim((string) $first . ' ' . (string) $last);
    $phone_raw = get_post_meta($ticket_id, '_eventosapp_asistente_tel', true);
    $settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : ['default_country_code' => '57'];
    $phone = function_exists('eventosapp_whatsapp_normalize_phone') ? eventosapp_whatsapp_normalize_phone($phone_raw, $settings['default_country_code'] ?? '57') : preg_replace('/\D+/', '', (string) $phone_raw);
    $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
    if ( $ticket_code === '' ) {
        $ticket_code = (string) $ticket_id;
    }
    $last_status = get_post_meta($ticket_id, '_eventosapp_whatsapp_last_status', true);
    $delivery_status = get_post_meta($ticket_id, '_eventosapp_whatsapp_delivery_status', true);
    $status_label = function_exists('eventosapp_whatsapp_status_label') ? eventosapp_whatsapp_status_label($last_status) : ($last_status ?: 'Sin estado');
    $delivery_label = $delivery_status && function_exists('eventosapp_whatsapp_status_label') ? eventosapp_whatsapp_status_label($delivery_status) : $delivery_status;
    $existing = eventosapp_whatsapp_flows_bulk_existing_ticket_ids($flow_post_id, $event_id, [$ticket_id]);
    $labels = eventosapp_whatsapp_flows_bulk_modalidad_labels();
    $mode = eventosapp_whatsapp_flows_bulk_get_ticket_modalidad_key($ticket_id, $event_id);

    return [
        'ticket_code' => sanitize_text_field((string) $ticket_code),
        'name'        => $name !== '' ? $name : 'Sin nombre',
        'phone'       => $phone ?: sanitize_text_field((string) $phone_raw),
        'email'       => sanitize_email((string) get_post_meta($ticket_id, '_eventosapp_asistente_email', true)),
        'localidad'   => sanitize_text_field((string) get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true)),
        'modalidad'   => $labels[$mode] ?? ucfirst($mode),
        'status'      => trim($status_label . ($delivery_label ? ' / ' . $delivery_label : '')),
        'flow_status' => ! empty($existing) ? 'Ya recibió este Flow' : 'No ha recibido este Flow',
    ];
}

add_action('wp_ajax_eventosapp_whatsapp_flows_bulk_get_event_extra_fields', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error('No autorizado');
    }
    check_ajax_referer('eventosapp_whatsapp_flows_bulk_extra_fields');

    $event_id = absint($_POST['event_id'] ?? 0);
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
        wp_send_json_success(['fields' => []]);
    }

    $fields = eventosapp_whatsapp_flows_bulk_get_event_extra_fields_schema($event_id);
    eventosapp_whatsapp_flows_add_activity('flow_masivo_campos_adicionales_consultados', [
        'event_id' => $event_id,
        'fields_count' => count($fields),
    ]);
    wp_send_json_success(['fields' => $fields]);
});

add_action('admin_post_eventosapp_whatsapp_flow_create_segment', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No autorizado');
    }
    check_admin_referer('eventosapp_whatsapp_flow_segment', 'evapp_whatsapp_flow_nonce');

    $filters = isset($_POST['filters']) && is_array($_POST['filters']) ? wp_unslash($_POST['filters']) : [];
    $filters = eventosapp_whatsapp_flows_bulk_sanitize_filters($filters);
    $event_id = absint($filters['evento_id'] ?? 0);
    $flow_post_id = absint($_POST['flow_post_id'] ?? ($filters['flow_id'] ?? 0));

    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
        wp_die('Debes seleccionar un evento válido antes de crear el segmento de Flows.');
    }
    if ( ! $flow_post_id || get_post_type($flow_post_id) !== EVENTOSAPP_WHATSAPP_FLOWS_POST_TYPE ) {
        wp_die('Debes seleccionar un Flow válido antes de crear el segmento.');
    }

    $flow_config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);
    $meta_flow_id = preg_replace('/\D+/', '', (string)($flow_config['meta_flow_id'] ?? ''));
    if ( $meta_flow_id === '' ) {
        wp_die('El Flow seleccionado no tiene Flow ID de Meta. Primero crea o sincroniza el Flow con Meta.');
    }

    $filters['evento_id'] = $event_id;
    $filters['flow_id'] = $flow_post_id;
    if ( empty($filters['flow_send_status']) ) {
        $filters['flow_send_status'] = 'no_recibido';
    }

    $ticket_ids = eventosapp_whatsapp_flows_bulk_get_filtered_tickets($filters);
    $segment_id = 'waflow_' . time() . '_' . wp_generate_password(8, false, false);
    $sender_settings = function_exists('eventosapp_whatsapp_resolve_sender_settings') && function_exists('eventosapp_whatsapp_get_settings')
        ? eventosapp_whatsapp_resolve_sender_settings($event_id, eventosapp_whatsapp_get_settings())
        : [];

    $segment = [
        'id' => $segment_id,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
        'created_by' => get_current_user_id(),
        'event_id' => $event_id,
        'flow_post_id' => $flow_post_id,
        'meta_flow_id' => $meta_flow_id,
        'flow_label' => eventosapp_whatsapp_flows_bulk_flow_label($flow_post_id),
        'sender_phone_number_id' => sanitize_text_field((string)($sender_settings['sender_phone_number_id'] ?? ($sender_settings['phone_number_id'] ?? ''))),
        'sender_phone_label' => sanitize_text_field((string)($sender_settings['sender_phone_label'] ?? ($sender_settings['phone_number_label'] ?? 'Número por defecto'))),
        'respect_rules' => isset($_POST['respect_rules']) ? 1 : 0,
        'filters' => $filters,
        'ticket_ids' => $ticket_ids,
        'total' => count($ticket_ids),
    ];

    update_option(eventosapp_whatsapp_flows_bulk_segment_option_key($segment_id), $segment, false);
    eventosapp_whatsapp_flows_add_activity('flow_masivo_segmento_creado', [
        'segment_id' => $segment_id,
        'event_id' => $event_id,
        'event_title' => get_the_title($event_id),
        'flow_post_id' => $flow_post_id,
        'meta_flow_id' => $meta_flow_id,
        'flow_label' => $segment['flow_label'],
        'sender_phone_number_id' => $segment['sender_phone_number_id'],
        'sender_phone_label' => $segment['sender_phone_label'],
        'respect_rules' => $segment['respect_rules'] ? 'yes' : 'no',
        'total' => count($ticket_ids),
        'filters' => $filters,
    ]);

    wp_safe_redirect(admin_url('admin.php?page=eventosapp_whatsapp_flows_campaign&step=2&segment_id=' . urlencode($segment_id)));
    exit;
});

add_action('wp_ajax_eventosapp_whatsapp_flow_process_batch', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error('No autorizado');
    }
    check_ajax_referer('eventosapp_whatsapp_flow_process');

    $segment_id = isset($_POST['segment_id']) ? sanitize_key((string) wp_unslash($_POST['segment_id'])) : '';
    $offset = isset($_POST['offset']) ? max(0, absint($_POST['offset'])) : 0;
    $batch_size = isset($_POST['batch_size']) ? min(10, max(1, absint($_POST['batch_size']))) : 5;

    if ( $segment_id === '' ) {
        wp_send_json_error('Segmento inválido');
    }

    $segment = get_option(eventosapp_whatsapp_flows_bulk_segment_option_key($segment_id));
    if ( ! $segment || ! is_array($segment) ) {
        wp_send_json_error('Segmento no encontrado');
    }

    $ticket_ids = isset($segment['ticket_ids']) && is_array($segment['ticket_ids']) ? array_values(array_filter(array_map('absint', $segment['ticket_ids']))) : [];
    $flow_post_id = absint($segment['flow_post_id'] ?? 0);
    $event_id = absint($segment['event_id'] ?? ($segment['filters']['evento_id'] ?? 0));
    $flow_config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);

    if ( ! $flow_post_id || empty($flow_config) ) {
        wp_send_json_error('El Flow del segmento ya no existe o no es válido.');
    }

    $batch = array_values(array_filter(array_map('absint', array_slice($ticket_ids, $offset, $batch_size))));
    if ( ! empty($batch) ) {
        update_meta_cache('post', $batch);
    }

    $sent = 0;
    $errors = 0;
    $skipped = 0;
    $logs = [];
    $flow_label = eventosapp_whatsapp_flows_bulk_flow_label($flow_post_id);

    foreach ( $batch as $ticket_id ) {
        $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
        if ( $ticket_code === '' ) {
            $ticket_code = (string) $ticket_id;
        }
        $ticket_event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if ( ! $ticket_event_id ) {
            $ticket_event_id = $event_id;
        }
        $source_key = 'whatsapp_flow_bulk_' . $segment_id . '_' . $flow_post_id;

        if ( ! empty($segment['respect_rules']) && function_exists('eventosapp_whatsapp_ticket_passes_rules') ) {
            $rules_result = eventosapp_whatsapp_ticket_passes_rules($ticket_id, $ticket_event_id);
            if ( empty($rules_result['allowed']) ) {
                $skipped++;
                $reason = sanitize_text_field((string)($rules_result['reason'] ?? 'Omitido por reglas del evento.'));
                if ( function_exists('eventosapp_whatsapp_add_ticket_log') ) {
                    eventosapp_whatsapp_add_ticket_log($ticket_id, 'skipped', $reason, [
                        'context' => 'whatsapp_flow_bulk_send',
                        'source_key' => $source_key,
                        'flow_post_id' => $flow_post_id,
                    ], '', [
                        'transport' => 'flow',
                        'template_name' => 'Flow: ' . sanitize_text_field((string)($flow_config['title'] ?? '')),
                        'debug' => [
                            'stage' => 'rules_validation',
                            'segment_id' => $segment_id,
                            'flow_post_id' => $flow_post_id,
                            'event_id' => $ticket_event_id,
                        ],
                    ]);
                }
                $logs[] = ['message' => 'Ticket ' . $ticket_code . ': omitido por reglas — ' . $reason, 'type' => 'warning'];
                continue;
            }
        }

        $last_source = get_post_meta($ticket_id, '_eventosapp_whatsapp_last_source_key', true);
        if ( $last_source === $source_key ) {
            $skipped++;
            $logs[] = ['message' => 'Ticket ' . $ticket_code . ': omitido por duplicado del mismo segmento.', 'type' => 'warning'];
            eventosapp_whatsapp_flows_add_activity('flow_masivo_omitido_duplicado_source_key', [
                'segment_id' => $segment_id,
                'ticket_id' => $ticket_id,
                'event_id' => $ticket_event_id,
                'flow_post_id' => $flow_post_id,
                'source_key' => $source_key,
            ]);
            continue;
        }

        $phone = get_post_meta($ticket_id, '_eventosapp_asistente_tel', true);
        $result = eventosapp_whatsapp_flows_send_direct_flow($flow_post_id, $phone, [
            'ticket_id' => $ticket_id,
            'event_id' => $ticket_event_id,
            'send_mode' => 'direct_campaign',
            'context' => 'whatsapp_flow_bulk_send',
            'source_key' => $source_key,
            'sender_phone_number_id' => sanitize_text_field((string)($segment['sender_phone_number_id'] ?? '')),
        ]);

        if ( ! empty($result['ok']) ) {
            $sent++;
            $message_id = sanitize_text_field((string)($result['message_id'] ?? ''));
            $logs[] = ['message' => 'Ticket ' . $ticket_code . ': solicitud aceptada por Meta usando ' . $flow_label . ($message_id !== '' ? ' — ID ' . $message_id : '') . '.', 'type' => 'success'];
        } else {
            $errors++;
            $error_message = sanitize_text_field((string)($result['message'] ?? 'Error desconocido.'));
            if ( empty($result['send_id']) && function_exists('eventosapp_whatsapp_add_ticket_log') ) {
                eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', $error_message, [
                    'context' => 'whatsapp_flow_bulk_send',
                    'source_key' => $source_key,
                    'flow_post_id' => $flow_post_id,
                ], sanitize_text_field((string) $phone), [
                    'transport' => 'flow',
                    'template_name' => 'Flow: ' . sanitize_text_field((string)($flow_config['title'] ?? '')),
                    'response' => $result['response'] ?? [],
                    'http_code' => isset($result['http_code']) ? absint($result['http_code']) : 0,
                    'debug' => [
                        'stage' => 'pre_api_validation_error',
                        'segment_id' => $segment_id,
                        'flow_post_id' => $flow_post_id,
                        'event_id' => $ticket_event_id,
                        'message' => $error_message,
                    ],
                ]);
            }
            $logs[] = ['message' => 'Ticket ' . $ticket_code . ': error — ' . $error_message, 'type' => 'error'];
        }

        usleep(150000);
    }

    $last_result = get_option('eventosapp_whatsapp_flow_last_campaign_result', []);
    $last_result = is_array($last_result) ? $last_result : [];
    if ( ($last_result['segment_id'] ?? '') !== $segment_id ) {
        $last_result = [
            'segment_id' => $segment_id,
            'flow_post_id' => $flow_post_id,
            'event_id' => $event_id,
            'total' => count($ticket_ids),
            'ok' => 0,
            'errors' => 0,
            'skipped' => 0,
            'processed' => 0,
            'created_at' => current_time('mysql'),
        ];
    }
    $last_result['ok'] = absint($last_result['ok'] ?? 0) + $sent;
    $last_result['errors'] = absint($last_result['errors'] ?? 0) + $errors;
    $last_result['skipped'] = absint($last_result['skipped'] ?? 0) + $skipped;
    $last_result['processed'] = min(count($ticket_ids), absint($last_result['processed'] ?? 0) + count($batch));
    $last_result['updated_at'] = current_time('mysql');
    update_option('eventosapp_whatsapp_flow_last_campaign_result', $last_result, false);

    wp_send_json_success([
        'processed' => count($batch),
        'sent' => $sent,
        'skipped' => $skipped,
        'errors' => $errors,
        'next_offset' => $offset + $batch_size,
        'logs' => $logs,
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
    $skip_existing = ! empty($_POST['campaign_skip_existing']);
    $return_page = sanitize_key((string)($_POST['return_page'] ?? 'editor'));

    $redirect_args = $return_page === 'campaign'
        ? ['page' => 'eventosapp_whatsapp_flows_campaign', 'flow_id' => $flow_post_id]
        : ['page' => 'eventosapp_whatsapp_flows', 'flow_id' => $flow_post_id];

    if ( ! $flow_post_id || ! $event_id ) {
        eventosapp_whatsapp_flows_notice_redirect(array_merge($redirect_args, ['flow_notice' => 'error', 'flow_message' => rawurlencode('Debes seleccionar Flow y evento para el envío por lote.')]));
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
    $skipped_count = 0;

    foreach ( $tickets as $ticket_id ) {
        if ( $skip_existing ) {
            global $wpdb;
            eventosapp_whatsapp_flows_maybe_install_tables();
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . eventosapp_whatsapp_flows_sends_table_name() . ' WHERE flow_post_id = %d AND event_id = %d AND ticket_id = %d AND status NOT LIKE %s',
                $flow_post_id,
                $event_id,
                absint($ticket_id),
                'failed%'
            ));
            if ( $existing > 0 ) {
                $skipped_count++;
                continue;
            }
        }

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
        'skipped'      => $skipped_count,
        'processed'    => count($tickets),
        'created_at'   => current_time('mysql'),
    ], false);

    eventosapp_whatsapp_flows_notice_redirect(array_merge($redirect_args, [
        'flow_notice'  => $error_count ? 'warning' : 'success',
        'flow_message' => rawurlencode('Lote procesado. Enviados: ' . $ok_count . '. Omitidos: ' . $skipped_count . '. Errores: ' . $error_count . '.'),
    ]));
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
function eventosapp_whatsapp_flows_admin_styles() {
    ?>
    <style>
        .eventosapp-wa-flows{--evapp-blue:#3454f4;--evapp-blue2:#eef2ff;--evapp-ink:#152234;--evapp-muted:#667085;--evapp-border:#d9e1ef;--evapp-bg:#f5f7fb;--evapp-card:#fff;--evapp-green:#0a9b67;--evapp-orange:#d97706}.eventosapp-wa-flows.wrap{background:var(--evapp-bg);padding:20px;margin:0 0 0 -20px;min-height:calc(100vh - 32px)}.eventosapp-wa-flows h1{font-size:28px;font-weight:800;color:var(--evapp-ink);margin:0 0 18px}.eventosapp-wa-flows .evapp-page-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}.eventosapp-wa-flows .evapp-page-head p{margin:.35rem 0 0;color:var(--evapp-muted);font-size:14px}.eventosapp-wa-flows .evapp-top-actions{display:flex;gap:8px;flex-wrap:wrap}.eventosapp-wa-flows .evapp-card{background:var(--evapp-card);border:1px solid var(--evapp-border);border-radius:16px;padding:18px;box-shadow:0 8px 22px rgba(15,23,42,.05);margin-bottom:18px}.eventosapp-wa-flows .evapp-card h2{font-size:17px;margin:0 0 12px;color:var(--evapp-ink)}.eventosapp-wa-flows .evapp-card h3{font-size:15px;margin:18px 0 10px;color:var(--evapp-ink)}.eventosapp-wa-flows .evapp-grid{display:grid;grid-template-columns:minmax(520px,1.15fr) minmax(330px,.85fr);gap:18px;align-items:start}.eventosapp-wa-flows .evapp-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.eventosapp-wa-flows .evapp-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}.eventosapp-wa-flows .evapp-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}.eventosapp-wa-flows .evapp-field{display:block;margin-bottom:12px}.eventosapp-wa-flows .evapp-field span,.eventosapp-wa-flows .evapp-label{display:block;font-weight:700;color:#26364a;margin-bottom:6px}.eventosapp-wa-flows input[type=text],.eventosapp-wa-flows input[type=number],.eventosapp-wa-flows select,.eventosapp-wa-flows textarea{border:1px solid #cfd8e6;border-radius:10px;min-height:38px;box-shadow:none}.eventosapp-wa-flows .evapp-field input[type=text],.eventosapp-wa-flows .evapp-field input[type=number],.eventosapp-wa-flows .evapp-field select,.eventosapp-wa-flows .evapp-field textarea{width:100%;max-width:100%;box-sizing:border-box}.eventosapp-wa-flows textarea{padding:8px 10px}.eventosapp-wa-flows #flow_description{display:block;width:100%;min-height:96px;resize:vertical}.eventosapp-wa-flows .regular-text,.eventosapp-wa-flows .large-text{max-width:100%;width:100%}.eventosapp-wa-flows .evapp-muted,.eventosapp-wa-flows .description{color:var(--evapp-muted)}.eventosapp-wa-flows .evapp-pill{display:inline-flex;align-items:center;border-radius:999px;background:var(--evapp-blue2);color:#203bc4;padding:4px 9px;font-size:12px;font-weight:800}.eventosapp-wa-flows .evapp-pill.green{background:#e9f9f1;color:#07724d}.eventosapp-wa-flows .evapp-pill.gray{background:#eef1f5;color:#4b5563}.eventosapp-wa-flows .evapp-stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}.eventosapp-wa-flows .evapp-stat{background:linear-gradient(180deg,#fff,#f7f9ff);border:1px solid #e3e9f6;border-radius:14px;padding:13px}.eventosapp-wa-flows .evapp-stat span{display:block;font-weight:700;color:var(--evapp-muted);font-size:12px}.eventosapp-wa-flows .evapp-stat strong{display:block;font-size:24px;color:var(--evapp-ink);line-height:1.1;margin-top:4px}.eventosapp-wa-flows .evapp-builder-toolbar{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:12px 0 14px}.eventosapp-wa-flows .evapp-builder-toolbar button{min-height:38px;border-radius:10px}.eventosapp-wa-flows .evapp-question{border:1px solid #d9e1ef;border-radius:16px;margin:12px 0;background:#fff;overflow:hidden}.eventosapp-wa-flows .evapp-question-head{display:flex;justify-content:space-between;gap:12px;align-items:center;background:#f8faff;padding:12px 14px;border-bottom:1px solid #e6edf8}.eventosapp-wa-flows .evapp-question-title{display:flex;align-items:center;gap:9px}.eventosapp-wa-flows .evapp-question-number{display:inline-flex;justify-content:center;align-items:center;width:28px;height:28px;border-radius:9px;background:var(--evapp-blue);color:#fff;font-weight:800}.eventosapp-wa-flows .evapp-question-body{padding:14px}.eventosapp-wa-flows .evapp-type-help{padding:9px 10px;border-radius:10px;background:#f8fafc;border:1px solid #e5edf7;color:#536071;margin:8px 0 0;font-size:12px}.eventosapp-wa-flows .evapp-options-wrap textarea{font-family:Menlo,Consolas,monospace;min-height:96px}.eventosapp-wa-flows .evapp-question.is-display .evapp-options-wrap,.eventosapp-wa-flows .evapp-question.is-display .evapp-required-wrap{display:none}.eventosapp-wa-flows .evapp-question.is-optin .evapp-options-wrap,.eventosapp-wa-flows .evapp-question:not(.is-text-input) .evapp-text-input-type-wrap{display:none}.eventosapp-wa-flows textarea.code{width:100%;min-height:310px;font-family:Menlo,Consolas,monospace;background:#0f172a;color:#d9e9ff;border-radius:14px;padding:14px}.eventosapp-wa-flows .widefat{border:1px solid #dce4f1;border-radius:12px;overflow:hidden}.eventosapp-wa-flows .widefat th{font-weight:800;color:#26364a}.eventosapp-wa-flows .widefat td{vertical-align:top}.eventosapp-wa-flows .evapp-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.eventosapp-wa-flows .evapp-response-pre{white-space:pre-wrap;max-height:130px;overflow:auto;background:#f8fafc;border-radius:10px;padding:8px}.eventosapp-wa-flows .evapp-warning{border-left:4px solid var(--evapp-orange);background:#fff7ed;padding:12px;border-radius:12px;margin:12px 0;color:#7c2d12}.eventosapp-wa-flows .evapp-info{border-left:4px solid var(--evapp-blue);background:#eef2ff;padding:12px;border-radius:12px;margin:12px 0;color:#26364a}.eventosapp-wa-flows .evapp-success{border-left:4px solid var(--evapp-green);background:#ecfdf3;padding:12px;border-radius:12px;margin:12px 0}.eventosapp-wa-flows .button{border-radius:9px}.eventosapp-wa-flows .button-primary{background:var(--evapp-blue);border-color:var(--evapp-blue)}.eventosapp-wa-flows .evapp-empty{padding:22px;border:1px dashed #cfd8e6;border-radius:14px;background:#fafcff;color:var(--evapp-muted);text-align:center}.eventosapp-wa-flows .evapp-template-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.eventosapp-wa-flows .evapp-template-card{border:1px solid #dce4f1;border-radius:13px;padding:12px;background:#fbfdff}.eventosapp-wa-flows .evapp-template-card strong{display:block;color:var(--evapp-ink);margin-bottom:4px}.eventosapp-wa-flows .evapp-template-card p{margin:0;color:var(--evapp-muted);font-size:12px}.eventosapp-wa-flows .evapp-small{font-size:12px}.eventosapp-wa-flows .evapp-checkline{display:flex;gap:7px;align-items:center;margin:8px 0}.eventosapp-wa-flows .evapp-form-table{width:100%;border-collapse:separate;border-spacing:0 12px}.eventosapp-wa-flows .evapp-form-table th{width:170px;text-align:left;vertical-align:top;padding-top:8px;color:#26364a}.eventosapp-wa-flows .evapp-form-table td{vertical-align:top}@media(max-width:1200px){.eventosapp-wa-flows .evapp-grid{grid-template-columns:1fr}.eventosapp-wa-flows .evapp-builder-toolbar{grid-template-columns:repeat(2,1fr)}}@media(max-width:782px){.eventosapp-wa-flows.wrap{margin-left:-10px;padding:14px}.eventosapp-wa-flows .evapp-stat-grid,.eventosapp-wa-flows .evapp-grid-3,.eventosapp-wa-flows .evapp-row,.eventosapp-wa-flows .evapp-row-3,.eventosapp-wa-flows .evapp-template-grid{grid-template-columns:1fr}.eventosapp-wa-flows .evapp-page-head{display:block}.eventosapp-wa-flows .evapp-form-table th,.eventosapp-wa-flows .evapp-form-table td{display:block;width:100%}}
    </style>
    <?php
}
function eventosapp_whatsapp_flows_get_events_for_select() {
    return get_posts([
        'post_type'      => 'eventosapp_event',
        'post_status'    => ['publish', 'draft', 'private'],
        'posts_per_page' => 300,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);
}

function eventosapp_whatsapp_flows_get_default_meta_context() {
    $settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];
    $default_waba_id = function_exists('eventosapp_whatsapp_get_effective_webhook_waba_id') ? eventosapp_whatsapp_get_effective_webhook_waba_id($settings) : ($settings['webhook_waba_id'] ?? '');
    $phone_accounts = function_exists('eventosapp_whatsapp_get_phone_accounts') ? eventosapp_whatsapp_get_phone_accounts($settings) : [];
    return [$settings, $default_waba_id, $phone_accounts];
}

function eventosapp_whatsapp_flows_render_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }

    eventosapp_whatsapp_flows_maybe_install_tables();
    $flow_id = absint($_GET['flow_id'] ?? 0);
    $flows = eventosapp_whatsapp_flows_get_all_for_select();
    $selected = $flow_id ? eventosapp_whatsapp_flows_get_flow_config($flow_id) : [];

    list($settings, $default_waba_id, $phone_accounts) = eventosapp_whatsapp_flows_get_default_meta_context();
    $categories = eventosapp_whatsapp_flows_categories();
    $question_types = eventosapp_whatsapp_flows_question_types();
    $type_help = eventosapp_whatsapp_flows_type_help();
    $events = eventosapp_whatsapp_flows_get_events_for_select();

    $new_config = [
        'id'                     => 0,
        'title'                  => '',
        'description'            => 'Hola {{name}}, queremos conocer tu opinión sobre {{event_name}}. Completa esta encuesta corta.',
        'category'               => 'SURVEY',
        'cta'                    => 'Responder encuesta',
        'submit_label'           => 'Enviar respuestas',
        'screen_id'              => 'SURVEY',
        'questions_per_screen'   => 8,
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
    $flow_id = absint($edit_config['id']);
    $stats = eventosapp_whatsapp_flows_get_stats($flow_id);
    $recent_sends = eventosapp_whatsapp_flows_get_recent_sends($flow_id, 25);
    $recent_responses = eventosapp_whatsapp_flows_get_recent_responses($flow_id, 25);
    $json_preview = eventosapp_whatsapp_flows_json_encode(eventosapp_whatsapp_flows_build_flow_json($flow_id, $edit_config), true);
    ?>
    <div class="wrap eventosapp-wa-flows">
        <?php eventosapp_whatsapp_flows_admin_styles(); ?>
        <div class="evapp-page-head">
            <div>
                <h1><?php echo $flow_id ? 'Editar WhatsApp Flow' : 'Crear WhatsApp Flow'; ?></h1>
                <p>Constructor independiente para encuestas y formularios nativos dentro de WhatsApp.</p>
            </div>
            <div class="evapp-top-actions">
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_manage')); ?>">Gestionar Flows</a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_campaign&flow_id=' . absint($flow_id))); ?>">Envío masivo</a>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows&flow_id=0')); ?>">Crear nuevo</a>
            </div>
        </div>
        <?php eventosapp_whatsapp_flows_render_notices(); ?>

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
                    <h2>1. Datos generales del Flow</h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="evapp-wa-flow-form">
                        <?php wp_nonce_field('eventosapp_whatsapp_flow_save'); ?>
                        <input type="hidden" name="action" value="eventosapp_whatsapp_flow_save">
                        <input type="hidden" name="flow_post_id" value="<?php echo esc_attr($edit_config['id']); ?>">

                        <div class="evapp-row">
                            <label class="evapp-field"><span>Nombre del Flow</span><input type="text" id="flow_title" name="flow_title" value="<?php echo esc_attr($edit_config['title']); ?>" placeholder="Encuesta post evento" required></label>
                            <label class="evapp-field"><span>Categoría</span><select name="flow_category">
                                <?php foreach ( $categories as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($edit_config['category'], $key); ?>><?php echo esc_html($label . ' (' . $key . ')'); ?></option>
                                <?php endforeach; ?>
                            </select></label>
                        </div>

                        <label class="evapp-field"><span>Mensaje / descripción inicial</span><textarea rows="3" id="flow_description" name="flow_description"><?php echo esc_textarea($edit_config['description']); ?></textarea><span class="description">Puedes usar variables internas en envíos directos: {{name}}, {{event_name}}, {{ticket_code}}, {{localidad}}.</span></label>

                        <div class="evapp-row-3">
                            <label class="evapp-field"><span>Texto del botón</span><input type="text" name="flow_cta" value="<?php echo esc_attr($edit_config['cta']); ?>" maxlength="30"></label>
                            <label class="evapp-field"><span>Botón final</span><input type="text" name="flow_submit_label" value="<?php echo esc_attr($edit_config['submit_label']); ?>" maxlength="30"></label>
                            <label class="evapp-field"><span>ID pantalla inicial</span><input type="text" name="flow_screen_id" value="<?php echo esc_attr($edit_config['screen_id']); ?>"><span class="description">Usa solo letras y guiones bajos. Si hay varias pantallas, el sistema genera sufijos como _B, _C, sin números.</span></label>
                        </div>

                        <div class="evapp-row-3">
                            <label class="evapp-field"><span>Preguntas por pantalla</span><input type="number" name="flow_questions_per_screen" value="<?php echo esc_attr($edit_config['questions_per_screen']); ?>" min="3" max="15"><span class="description">Divide automáticamente encuestas largas en varias pantallas.</span></label>
                            <label class="evapp-field"><span>WABA ID</span><input type="text" name="flow_waba_id" value="<?php echo esc_attr($edit_config['waba_id'] ?: $default_waba_id); ?>"></label>
                            <label class="evapp-field"><span>Número emisor</span><select name="flow_sender_phone_number_id">
                                <option value="">Usar número por defecto</option>
                                <?php foreach ( $phone_accounts as $account ) : ?>
                                    <option value="<?php echo esc_attr($account['phone_number_id']); ?>" <?php selected($edit_config['sender_phone_number_id'], $account['phone_number_id']); ?>><?php echo esc_html($account['label']); ?></option>
                                <?php endforeach; ?>
                            </select></label>
                        </div>

                        <div class="evapp-info">
                            <strong>Componentes oficiales usados por el constructor:</strong> TextHeading, TextSubheading, TextBody, TextCaption, TextInput, TextArea, RadioButtonsGroup, CheckboxGroup, Dropdown, DatePicker y OptIn. El componente Footer se genera automáticamente al final de cada pantalla. Para mantener compatibilidad con la validación de Meta, el JSON no incluye propiedades <code>placeholder</code> en TextInput/TextArea y las pantallas adicionales se nombran solo con letras y guiones bajos.
                        </div>

                        <h2>2. Componentes del Flow</h2>
                        <div class="evapp-builder-toolbar" aria-label="Agregar componentes soportados por WhatsApp Flows">
                            <button type="button" class="button evapp-add-component" data-component="heading">+ TextHeading</button>
                            <button type="button" class="button evapp-add-component" data-component="subheading">+ TextSubheading</button>
                            <button type="button" class="button evapp-add-component" data-component="body">+ TextBody</button>
                            <button type="button" class="button evapp-add-component" data-component="caption">+ TextCaption</button>
                            <button type="button" class="button evapp-add-component" data-component="radio">+ RadioButtonsGroup</button>
                            <button type="button" class="button evapp-add-component" data-component="checkbox">+ CheckboxGroup</button>
                            <button type="button" class="button evapp-add-component" data-component="dropdown">+ Dropdown</button>
                            <button type="button" class="button evapp-add-component" data-component="text">+ TextInput</button>
                            <button type="button" class="button evapp-add-component" data-component="textarea">+ TextArea</button>
                            <button type="button" class="button evapp-add-component" data-component="date">+ DatePicker</button>
                            <button type="button" class="button evapp-add-component" data-component="optin">+ OptIn</button>
                        </div>

                        <div id="evapp-wa-flow-questions">
                            <?php foreach ( $edit_config['questions'] as $index => $question ) : ?>
                                <?php eventosapp_whatsapp_flows_render_question_row($index, $question, $question_types); ?>
                            <?php endforeach; ?>
                        </div>

                        <p class="submit"><button type="submit" class="button button-primary button-hero">Guardar Flow local</button></p>
                    </form>
                </div>

                <div class="evapp-card">
                    <h2>JSON generado</h2>
                    <p class="evapp-muted">Este JSON es el que EventosApp envía a Meta. Si la encuesta es larga, se divide en varias pantallas y conserva las respuestas hasta el envío final.</p>
                    <textarea class="code" readonly><?php echo esc_textarea($json_preview); ?></textarea>
                </div>
            </div>

            <div>
                <div class="evapp-card">
                    <h2>Flows recientes</h2>
                    <?php if ( empty($flows) ) : ?>
                        <div class="evapp-empty">Todavía no hay Flows guardados.</div>
                    <?php else : ?>
                        <table class="widefat striped">
                            <thead><tr><th>Flow</th><th>Estado</th><th>Meta ID</th><th></th></tr></thead>
                            <tbody>
                            <?php $shown = 0; foreach ( $flows as $item ) : if ( $shown >= 6 ) { break; } $shown++; ?>
                                <tr>
                                    <td><strong><?php echo esc_html($item['title']); ?></strong></td>
                                    <td><span class="evapp-pill <?php echo esc_attr(($item['status'] ?? '') === 'published' ? 'green' : ''); ?>"><?php echo esc_html($item['status'] ?: 'local'); ?></span></td>
                                    <td><small><?php echo esc_html($item['meta_flow_id'] ?: '—'); ?></small></td>
                                    <td><a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'eventosapp_whatsapp_flows', 'flow_id' => $item['id']], admin_url('admin.php'))); ?>">Abrir</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <p class="evapp-actions"><a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows&flow_id=0')); ?>" class="button">Crear uno nuevo</a><a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_manage')); ?>" class="button">Gestionar todos</a></p>
                </div>

                <div class="evapp-card">
                    <h2>Guía de componentes</h2>
                    <div class="evapp-template-grid">
                        <div class="evapp-template-card"><strong>TextHeading</strong><p>Encabezado visible. No guarda respuesta.</p></div>
                        <div class="evapp-template-card"><strong>TextSubheading</strong><p>Subtítulo visible. No guarda respuesta.</p></div>
                        <div class="evapp-template-card"><strong>TextBody</strong><p>Texto de instrucciones o contexto. No guarda respuesta.</p></div>
                        <div class="evapp-template-card"><strong>TextCaption</strong><p>Nota corta. No guarda respuesta.</p></div>
                        <div class="evapp-template-card"><strong>RadioButtonsGroup</strong><p>Una sola opción seleccionable.</p></div>
                        <div class="evapp-template-card"><strong>CheckboxGroup</strong><p>Varias opciones seleccionables.</p></div>
                        <div class="evapp-template-card"><strong>Dropdown</strong><p>Una opción dentro de una lista desplegable.</p></div>
                        <div class="evapp-template-card"><strong>TextInput</strong><p>Respuesta corta de una línea.</p></div>
                        <div class="evapp-template-card"><strong>TextArea</strong><p>Respuesta larga.</p></div>
                        <div class="evapp-template-card"><strong>DatePicker</strong><p>Selector de fecha.</p></div>
                        <div class="evapp-template-card"><strong>OptIn</strong><p>Casilla de aceptación.</p></div>
                    </div>
                </div>

                <?php if ( $flow_id ) : ?>
                    <div class="evapp-card">
                        <h2>Sincronización con Meta</h2>
                        <p><strong>Estado:</strong> <span class="evapp-pill <?php echo esc_attr($edit_config['status'] === 'published' ? 'green' : ''); ?>"><?php echo esc_html($edit_config['status']); ?></span></p>
                        <p><strong>Flow ID Meta:</strong> <?php echo esc_html($edit_config['meta_flow_id'] ?: 'No creado'); ?></p>
                        <?php if ( ! empty($edit_config['preview_url']) ) : ?>
                            <p><a class="button" target="_blank" href="<?php echo esc_url($edit_config['preview_url']); ?>">Abrir vista previa</a></p>
                        <?php endif; ?>
                        <div class="evapp-actions">
                            <?php eventosapp_whatsapp_flows_render_small_post_button('eventosapp_whatsapp_flow_create_meta', 'eventosapp_whatsapp_flow_create_meta', 'Crear/Recrear en Meta con JSON local', $flow_id); ?>
                            <?php eventosapp_whatsapp_flows_render_small_post_button('eventosapp_whatsapp_flow_upload_json', 'eventosapp_whatsapp_flow_upload_json', 'Subir JSON', $flow_id); ?>
                            <?php eventosapp_whatsapp_flows_render_small_post_button('eventosapp_whatsapp_flow_preview', 'eventosapp_whatsapp_flow_preview', 'Pedir preview', $flow_id); ?>
                            <?php eventosapp_whatsapp_flows_render_small_post_button('eventosapp_whatsapp_flow_refresh', 'eventosapp_whatsapp_flow_refresh', 'Sincronizar estado', $flow_id); ?>
                            <?php eventosapp_whatsapp_flows_render_small_post_button('eventosapp_whatsapp_flow_publish', 'eventosapp_whatsapp_flow_publish', 'Publicar', $flow_id, 'button-primary'); ?>
                        </div>
                        <div class="evapp-warning"><strong>Importante:</strong> si un Flow ya fue publicado, Meta no permite modificarlo como si fuera borrador. Para cambios grandes, recrea un nuevo Meta ID y revisa el preview antes de publicar.</div>
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
                            <label class="evapp-field"><span>Teléfono destino</span><input type="text" name="test_phone" placeholder="573001112233"></label>
                            <div class="evapp-row">
                                <label class="evapp-field"><span>Ticket ID opcional</span><input type="number" name="test_ticket_id" min="0"></label>
                                <label class="evapp-field"><span>Evento opcional</span><select name="test_event_id"><option value="0">Sin evento</option><?php foreach ( $events as $event ) : ?><option value="<?php echo esc_attr($event->ID); ?>"><?php echo esc_html(get_the_title($event)); ?></option><?php endforeach; ?></select></label>
                            </div>
                            <button type="submit" class="button button-primary">Enviar Flow de prueba</button>
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

    <?php eventosapp_whatsapp_flows_render_builder_script($question_types, $type_help); ?>
    <?php
}

function eventosapp_whatsapp_flows_render_manage_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    eventosapp_whatsapp_flows_maybe_install_tables();
    $flows = eventosapp_whatsapp_flows_get_all_for_select();
    $search = sanitize_text_field((string)($_GET['s'] ?? ''));
    $status_filter = sanitize_key((string)($_GET['flow_status'] ?? ''));
    ?>
    <div class="wrap eventosapp-wa-flows">
        <?php eventosapp_whatsapp_flows_admin_styles(); ?>
        <div class="evapp-page-head">
            <div><h1>Gestionar Flows</h1><p>Consulta, edita y reutiliza todos los Flows locales creados en EventosApp.</p></div>
            <div class="evapp-top-actions"><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows&flow_id=0')); ?>">Crear Flow</a><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_campaign')); ?>">Envío masivo</a></div>
        </div>
        <?php eventosapp_whatsapp_flows_render_notices(); ?>
        <div class="evapp-card">
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="evapp-actions">
                <input type="hidden" name="page" value="eventosapp_whatsapp_flows_manage">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Buscar por nombre">
                <select name="flow_status"><option value="">Todos los estados</option><option value="published" <?php selected($status_filter, 'published'); ?>>published</option><option value="json_uploaded" <?php selected($status_filter, 'json_uploaded'); ?>>json_uploaded</option><option value="local_draft" <?php selected($status_filter, 'local_draft'); ?>>local_draft</option><option value="draft_meta_json_ready" <?php selected($status_filter, 'draft_meta_json_ready'); ?>>draft_meta_json_ready</option></select>
                <button class="button">Filtrar</button>
            </form>
        </div>
        <div class="evapp-card">
            <?php if ( empty($flows) ) : ?>
                <div class="evapp-empty">No hay Flows todavía.</div>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th>Flow</th><th>Estado</th><th>Meta ID</th><th>Pantalla</th><th>Métricas</th><th>Acciones</th></tr></thead><tbody>
                    <?php foreach ( $flows as $item ) :
                        if ( $search !== '' && stripos($item['title'], $search) === false ) { continue; }
                        if ( $status_filter !== '' && sanitize_key($item['status']) !== $status_filter ) { continue; }
                        $config = eventosapp_whatsapp_flows_get_flow_config($item['id']);
                        $stats = eventosapp_whatsapp_flows_get_stats($item['id']);
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($item['title']); ?></strong><br><small>ID local #<?php echo esc_html($item['id']); ?></small></td>
                            <td><span class="evapp-pill <?php echo esc_attr(($item['status'] ?? '') === 'published' ? 'green' : ''); ?>"><?php echo esc_html($item['status'] ?: 'local'); ?></span></td>
                            <td><small><?php echo esc_html($item['meta_flow_id'] ?: '—'); ?></small></td>
                            <td><?php echo esc_html($config['screen_id'] ?? 'SURVEY'); ?></td>
                            <td><?php echo esc_html($stats['answered']); ?> respuestas / <?php echo esc_html($stats['sent']); ?> envíos<br><small>Tasa <?php echo esc_html($stats['rate']); ?>%</small></td>
                            <td class="evapp-actions"><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows&flow_id=' . absint($item['id']))); ?>">Editar</a><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_campaign&flow_id=' . absint($item['id']))); ?>">Enviar</a><a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=eventosapp_whatsapp_flow_export_responses&flow_id=' . absint($item['id'])), 'eventosapp_whatsapp_flow_export_responses')); ?>">CSV</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function eventosapp_whatsapp_flows_render_campaign_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes.');
    }
    eventosapp_whatsapp_flows_maybe_install_tables();
    $step = isset($_GET['step']) ? max(1, min(3, absint($_GET['step']))) : 1;
    $segment_id = isset($_GET['segment_id']) ? sanitize_key((string) wp_unslash($_GET['segment_id'])) : '';
    ?>
    <div class="wrap eventosapp-wa-flows">
        <?php eventosapp_whatsapp_flows_admin_styles(); ?>
        <style>
            .eventosapp-wa-flows .evapp-flow-bulk-form{max-width:1020px;}
            .eventosapp-wa-flows .evapp-flow-step-nav{display:flex;gap:8px;margin:0 0 18px;flex-wrap:wrap;}
            .eventosapp-wa-flows .evapp-flow-step-nav a,.eventosapp-wa-flows .evapp-flow-step-nav span{display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border:1px solid #d9e1ef;border-radius:999px;background:#fff;text-decoration:none;color:#334155;font-weight:700;}
            .eventosapp-wa-flows .evapp-flow-step-nav .is-active{background:#3454f4;color:#fff;border-color:#3454f4;}
            .eventosapp-wa-flows .evapp-flow-filter-section{background:#fff;border:1px solid #d9e1ef;border-radius:16px;padding:18px;margin-bottom:18px;box-shadow:0 8px 22px rgba(15,23,42,.04);}
            .eventosapp-wa-flows .evapp-flow-filter-section h3{margin:0 0 14px;color:#152234;border-bottom:2px solid #eef2ff;padding-bottom:10px;}
            .eventosapp-wa-flows .evapp-flow-filter-row{display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:14px;}
            .eventosapp-wa-flows .evapp-flow-filter-field{display:flex;flex-direction:column;gap:6px;}
            .eventosapp-wa-flows .evapp-flow-filter-field label{font-weight:700;color:#26364a;}
            .eventosapp-wa-flows .evapp-flow-filter-field small{color:#667085;}
            .eventosapp-wa-flows .evapp-flow-extra-fields-container{margin-top:12px;padding:14px;background:#f8fafc;border:1px solid #e5edf7;border-radius:12px;}
            .eventosapp-wa-flows .evapp-flow-extra-field-row{display:grid;grid-template-columns:220px 1fr;gap:10px;align-items:center;margin-bottom:10px;}
            .eventosapp-wa-flows .evapp-flow-tagline{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 16px;}
            .eventosapp-wa-flows .evapp-flow-filter-tag{display:inline-flex;gap:5px;align-items:center;background:#eef2ff;border:1px solid #dbe4ff;color:#26364a;border-radius:999px;padding:6px 10px;font-size:12px;}
            .eventosapp-wa-flows .evapp-flow-progress-box{background:#fff;border:1px solid #d9e1ef;border-radius:16px;padding:20px;max-width:920px;}
            .eventosapp-wa-flows .evapp-wa-progress-bar-container{width:100%;height:40px;background:#f0f0f1;border-radius:20px;overflow:hidden;margin:20px 0;position:relative;}
            .eventosapp-wa-flows .evapp-wa-progress-bar{height:100%;background:linear-gradient(90deg,#3454f4 0%,#667dff 100%);transition:width .3s ease;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;}
            .eventosapp-wa-flows .evapp-wa-stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px 0;}
            .eventosapp-wa-flows .evapp-wa-stat-card{background:#f8fafc;border:1px solid #e5edf7;border-radius:13px;padding:14px;text-align:center;}
            .eventosapp-wa-flows .evapp-wa-stat-card .number{font-size:28px;font-weight:800;color:#152234;line-height:1;}
            .eventosapp-wa-flows .evapp-wa-stat-card .label{font-size:12px;color:#667085;margin-top:6px;font-weight:700;}
            .eventosapp-wa-flows .evapp-wa-log-container{max-height:360px;overflow-y:auto;background:#111827;color:#e5e7eb;padding:15px;border-radius:12px;font-family:Menlo,Consolas,monospace;font-size:12px;margin:20px 0;}
            .eventosapp-wa-flows .evapp-wa-log-entry{margin:5px 0;line-height:1.45;}
            .eventosapp-wa-flows .evapp-wa-log-success{color:#4ade80;}
            .eventosapp-wa-flows .evapp-wa-log-error{color:#f87171;}
            .eventosapp-wa-flows .evapp-wa-log-info{color:#60a5fa;}
            .eventosapp-wa-flows .evapp-wa-log-warning{color:#fbbf24;}
            @media(max-width:782px){.eventosapp-wa-flows .evapp-flow-filter-row,.eventosapp-wa-flows .evapp-flow-extra-field-row,.eventosapp-wa-flows .evapp-wa-stats-grid{grid-template-columns:1fr;}}
        </style>
        <div class="evapp-page-head">
            <div>
                <h1>Envío Masivo de Flows</h1>
                <p>Configura el evento, selecciona el Flow, segmenta asistentes, revisa la vista previa y envía por lotes con barra de progreso y log detallado.</p>
            </div>
            <div class="evapp-top-actions">
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_manage')); ?>">Gestionar Flows</a>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows&flow_id=0')); ?>">Crear Flow</a>
            </div>
        </div>
        <?php eventosapp_whatsapp_flows_render_notices(); ?>
        <?php eventosapp_whatsapp_flows_render_campaign_steps_nav($step, $segment_id); ?>
        <?php
        if ( $step === 1 ) {
            eventosapp_whatsapp_flows_render_campaign_step1();
        } elseif ( $step === 2 ) {
            eventosapp_whatsapp_flows_render_campaign_step2($segment_id);
        } else {
            eventosapp_whatsapp_flows_render_campaign_step3($segment_id);
        }
        ?>
    </div>
    <?php
}

function eventosapp_whatsapp_flows_render_campaign_steps_nav($current_step, $segment_id = '') {
    $steps = [
        1 => '1. Configurar filtros',
        2 => '2. Revisar segmento',
        3 => '3. Enviar con progreso',
    ];
    echo '<div class="evapp-flow-step-nav">';
    foreach ( $steps as $num => $label ) {
        $url = add_query_arg(['page' => 'eventosapp_whatsapp_flows_campaign', 'step' => $num], admin_url('admin.php'));
        if ( $segment_id !== '' && $num > 1 ) {
            $url = add_query_arg('segment_id', $segment_id, $url);
        }
        $class = $current_step === $num ? 'is-active' : '';
        if ( $num > 1 && $segment_id === '' ) {
            echo '<span class="' . esc_attr($class) . '">' . esc_html($label) . '</span>';
        } else {
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
    }
    echo '</div>';
}

function eventosapp_whatsapp_flows_render_campaign_step1() {
    $eventos = eventosapp_whatsapp_flows_get_events_for_select();
    $flows = eventosapp_whatsapp_flows_get_all_for_select();
    $status_options = eventosapp_whatsapp_flows_bulk_status_options();
    $delivery_options = eventosapp_whatsapp_flows_bulk_delivery_options();
    $modalidad_labels = eventosapp_whatsapp_flows_bulk_modalidad_labels();
    $selected_flow_id = absint($_GET['flow_id'] ?? 0);

    global $wpdb;
    $localidades = $wpdb->get_col("\n        SELECT DISTINCT meta_value\n        FROM {$wpdb->postmeta}\n        WHERE meta_key = '_eventosapp_asistente_localidad'\n        AND meta_value != ''\n        ORDER BY meta_value ASC\n    ");

    $event_summaries = [];
    $settings_for_events = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];
    foreach ( $eventos as $ev ) {
        $sender_settings = function_exists('eventosapp_whatsapp_resolve_sender_settings')
            ? eventosapp_whatsapp_resolve_sender_settings($ev->ID, $settings_for_events)
            : $settings_for_events;
        $event_summaries[(string) $ev->ID] = [
            'event_id' => (int) $ev->ID,
            'event_title' => get_the_title($ev->ID),
            'event_label' => eventosapp_whatsapp_flows_bulk_get_event_modalidad_label($ev->ID),
            'sender_phone_number_id' => sanitize_text_field((string)($sender_settings['sender_phone_number_id'] ?? ($sender_settings['phone_number_id'] ?? ''))),
            'sender_phone_label' => sanitize_text_field((string)($sender_settings['sender_phone_label'] ?? ($sender_settings['phone_number_label'] ?? 'Número por defecto'))),
        ];
    }
    ?>
    <?php if ( empty($flows) ) : ?>
        <div class="notice notice-error"><p><strong>No hay Flows disponibles.</strong> Primero crea o sincroniza un Flow antes de usar el envío masivo.</p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-flow-bulk-form" id="evappFlowBulkForm">
        <input type="hidden" name="action" value="eventosapp_whatsapp_flow_create_segment">
        <?php wp_nonce_field('eventosapp_whatsapp_flow_segment', 'evapp_whatsapp_flow_nonce'); ?>

        <div class="evapp-warning">
            <strong>Uso recomendado:</strong> el envío directo de Flow depende de que WhatsApp permita iniciar esa conversación con un mensaje interactivo. Para campañas fuera de ventana de conversación, usa una plantilla Flow aprobada por Meta. Esta pantalla deja trazabilidad completa en el log de WhatsApp y en el historial del ticket.
        </div>

        <div class="evapp-flow-filter-section">
            <h3>1️⃣ Seleccionar evento y Flow</h3>
            <div class="evapp-flow-filter-row">
                <div class="evapp-flow-filter-field">
                    <label for="evento_id">Evento *</label>
                    <select name="filters[evento_id]" id="evento_id" required>
                        <option value="">-- Selecciona el evento --</option>
                        <?php foreach ( $eventos as $ev ) : ?>
                            <option value="<?php echo esc_attr($ev->ID); ?>"><?php echo esc_html($ev->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>El evento define los tickets, los campos adicionales y el número emisor efectivo.</small>
                </div>
                <div class="evapp-flow-filter-field">
                    <label>Información del evento</label>
                    <div class="evapp-info" id="eventFlowSummary" style="margin:0;">Selecciona un evento para ver modalidad y número emisor.</div>
                </div>
            </div>
            <div class="evapp-flow-filter-row">
                <div class="evapp-flow-filter-field">
                    <label for="flow_post_id">Flow a enviar *</label>
                    <select name="flow_post_id" id="flow_post_id" required <?php disabled(empty($flows)); ?>>
                        <option value="">-- Selecciona el Flow --</option>
                        <?php foreach ( $flows as $item ) : ?>
                            <?php $meta_id = preg_replace('/\D+/', '', (string)($item['meta_flow_id'] ?? '')); ?>
                            <option value="<?php echo esc_attr($item['id']); ?>" <?php selected($selected_flow_id, $item['id']); ?> data-meta-flow-id="<?php echo esc_attr($meta_id); ?>" data-status="<?php echo esc_attr($item['status'] ?? ''); ?>">
                                <?php echo esc_html('#' . $item['id'] . ' · ' . $item['title'] . ($meta_id ? ' · Meta ID ' . $meta_id : ' · sin Meta ID') . (! empty($item['status']) ? ' · ' . $item['status'] : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Solo se podrá continuar si el Flow tiene Meta Flow ID.</small>
                </div>
                <div class="evapp-flow-filter-field">
                    <label for="flow_send_status">Estado frente a este Flow</label>
                    <select name="filters[flow_send_status]" id="flow_send_status">
                        <option value="no_recibido" selected>No han recibido este Flow para este evento</option>
                        <option value="todos">Todos los tickets filtrados</option>
                        <option value="recibido">Solo tickets que ya recibieron este Flow</option>
                    </select>
                    <small>Reemplaza el antiguo checkbox de omitir duplicados y permite auditar o reenviar segmentos específicos.</small>
                </div>
            </div>
            <div class="evapp-flow-filter-row">
                <div class="evapp-flow-filter-field">
                    <label for="respect_rules">Reglas del evento</label>
                    <label style="font-weight:400;margin-top:3px;"><input type="checkbox" name="respect_rules" id="respect_rules" value="1" checked> Respetar reglas de envío WhatsApp configuradas en el evento</label>
                    <small>Los tickets bloqueados por reglas quedan como omitidos y se registran en el log.</small>
                </div>
                <div class="evapp-flow-filter-field">
                    <label>Validación del Flow</label>
                    <div class="evapp-info" id="flowValidationSummary" style="margin:0;">Selecciona un Flow para ver su estado.</div>
                </div>
            </div>
        </div>

        <div class="evapp-flow-filter-section">
            <h3>📲 Estado WhatsApp y fechas de envío</h3>
            <div class="evapp-flow-filter-row">
                <div class="evapp-flow-filter-field">
                    <label for="whatsapp_status">Estado de solicitud WhatsApp</label>
                    <select name="filters[whatsapp_status]" id="whatsapp_status">
                        <option value="">-- Todos --</option>
                        <?php foreach ( $status_options as $value => $label ) : ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Filtra por el último estado local/API guardado en el ticket.</small>
                </div>
                <div class="evapp-flow-filter-field">
                    <label for="delivery_status">Estado recibido por webhook</label>
                    <select name="filters[delivery_status]" id="delivery_status">
                        <option value="">-- Todos --</option>
                        <?php foreach ( $delivery_options as $value => $label ) : ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Filtra por entrega, lectura o fallo reportado por WhatsApp.</small>
                </div>
            </div>
            <div class="evapp-flow-filter-row">
                <div class="evapp-flow-filter-field">
                    <label for="last_sent_from">Último envío WhatsApp - Desde</label>
                    <input type="date" name="filters[last_sent_from]" id="last_sent_from">
                </div>
                <div class="evapp-flow-filter-field">
                    <label for="last_sent_to">Último envío WhatsApp - Hasta</label>
                    <input type="date" name="filters[last_sent_to]" id="last_sent_to">
                </div>
            </div>
        </div>

        <div class="evapp-flow-filter-section">
            <h3>🎫 Segmentación del evento</h3>
            <div class="evapp-flow-filter-row">
                <div class="evapp-flow-filter-field">
                    <label for="localidad">Localidad</label>
                    <select name="filters[localidad]" id="localidad">
                        <option value="">-- Todas las localidades --</option>
                        <?php foreach ( $localidades as $loc ) : ?>
                            <option value="<?php echo esc_attr($loc); ?>"><?php echo esc_html($loc); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="evapp-flow-filter-field">
                    <label for="modalidad">Modalidad del ticket</label>
                    <select name="filters[modalidad]" id="modalidad">
                        <option value="">-- Todas --</option>
                        <?php foreach ( $modalidad_labels as $value => $label ) : ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="evapp-flow-filter-row">
                <div class="evapp-flow-filter-field">
                    <label for="event_date">Fecha específica del evento</label>
                    <input type="date" name="filters[event_date]" id="event_date">
                    <small>Tickets válidos para esta fecha del evento. Déjalo vacío para incluir todas las fechas.</small>
                </div>
                <div class="evapp-flow-filter-field">
                    <label>&nbsp;</label>
                    <div class="evapp-empty" style="padding:12px;text-align:left;">Los campos adicionales del evento se cargan dinámicamente al seleccionar el evento.</div>
                </div>
            </div>
        </div>

        <div class="evapp-flow-filter-section">
            <h3>📅 Fecha de creación del ticket</h3>
            <div class="evapp-flow-filter-row">
                <div class="evapp-flow-filter-field">
                    <label for="created_from">Creado desde</label>
                    <input type="date" name="filters[created_from]" id="created_from">
                </div>
                <div class="evapp-flow-filter-field">
                    <label for="created_to">Creado hasta</label>
                    <input type="date" name="filters[created_to]" id="created_to">
                </div>
            </div>
        </div>

        <div class="evapp-flow-filter-section" id="extraFieldsSection" style="display:none;">
            <h3>🔧 Campos adicionales del evento</h3>
            <div class="evapp-flow-extra-fields-container" id="extraFieldsContainer">
                <p><em>Selecciona un evento primero para ver sus campos adicionales.</em></p>
            </div>
        </div>

        <p><button type="submit" class="button button-primary button-large" id="evappFlowBulkSubmit" <?php disabled(empty($flows)); ?>>Crear Segmento y Continuar →</button></p>
    </form>

    <script>
    jQuery(document).ready(function($){
        var eventSummaries = <?php echo wp_json_encode($event_summaries); ?> || {};
        function escapeHtml(value){ return $('<div>').text(value || '').html(); }
        function updateEventSummary(){
            var eventId = $('#evento_id').val();
            var data = eventId ? eventSummaries[eventId] : null;
            if (!data) {
                $('#eventFlowSummary').html('Selecciona un evento para ver modalidad y número emisor.');
                return;
            }
            $('#eventFlowSummary').html(
                '<strong>Evento:</strong> ' + escapeHtml(data.event_title) + '<br>' +
                '<strong>Modalidad:</strong> ' + escapeHtml(data.event_label) + '<br>' +
                '<strong>Número emisor:</strong> ' + escapeHtml(data.sender_phone_label || data.sender_phone_number_id || 'Número por defecto')
            );
        }
        function updateFlowSummary(){
            var $selected = $('#flow_post_id option:selected');
            var value = $('#flow_post_id').val();
            if (!value) {
                $('#flowValidationSummary').html('Selecciona un Flow para ver su estado.');
                return;
            }
            var metaId = String($selected.data('meta-flow-id') || '');
            var status = String($selected.data('status') || '');
            var html = '<strong>Flow:</strong> ' + escapeHtml($.trim($selected.text())) + '<br>';
            html += '<strong>Meta Flow ID:</strong> ' + escapeHtml(metaId || 'No configurado') + '<br>';
            html += '<strong>Estado:</strong> ' + escapeHtml(status || 'Sin estado local');
            if (!metaId) {
                html += '<br><strong style="color:#b91c1c;">No podrás continuar hasta que el Flow tenga Meta Flow ID.</strong>';
            }
            $('#flowValidationSummary').html(html);
        }
        function loadExtraFields(){
            var eventoId = $('#evento_id').val();
            var $section = $('#extraFieldsSection');
            var $container = $('#extraFieldsContainer');
            if (!eventoId) {
                $section.hide();
                $container.html('<p><em>Selecciona un evento primero para ver sus campos adicionales.</em></p>');
                return;
            }
            $section.show();
            $container.html('<p>Cargando campos...</p>');
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'eventosapp_whatsapp_flows_bulk_get_event_extra_fields',
                    event_id: eventoId,
                    _wpnonce: '<?php echo esc_js(wp_create_nonce('eventosapp_whatsapp_flows_bulk_extra_fields')); ?>'
                },
                success: function(response){
                    if (response.success && response.data.fields && response.data.fields.length > 0) {
                        var html = '';
                        response.data.fields.forEach(function(field){
                            html += '<div class="evapp-flow-extra-field-row">';
                            html += '<label>' + escapeHtml(field.label) + '</label>';
                            if (field.options && field.options.length > 0) {
                                html += '<select name="filters[extra_fields][' + escapeHtml(field.key) + ']">';
                                html += '<option value="">-- Cualquiera --</option>';
                                field.options.forEach(function(opt){ html += '<option value="' + escapeHtml(opt) + '">' + escapeHtml(opt) + '</option>'; });
                                html += '</select>';
                            } else {
                                html += '<input type="text" name="filters[extra_fields][' + escapeHtml(field.key) + ']" placeholder="Valor a buscar">';
                            }
                            html += '</div>';
                        });
                        $container.html(html);
                    } else {
                        $container.html('<p><em>Este evento no tiene campos adicionales configurados.</em></p>');
                    }
                },
                error: function(){ $container.html('<p style="color:red;">Error al cargar campos adicionales.</p>'); }
            });
        }
        $('#evento_id').on('change', function(){ updateEventSummary(); loadExtraFields(); });
        $('#flow_post_id').on('change', updateFlowSummary);
        $('#evappFlowBulkForm').on('submit', function(e){
            if (!$('#evento_id').val()) { e.preventDefault(); alert('Primero debes seleccionar el evento.'); return false; }
            var $selected = $('#flow_post_id option:selected');
            if (!$('#flow_post_id').val()) { e.preventDefault(); alert('Primero debes seleccionar el Flow.'); return false; }
            if (!String($selected.data('meta-flow-id') || '')) { e.preventDefault(); alert('El Flow seleccionado no tiene Meta Flow ID. Primero créalo o sincronízalo con Meta.'); return false; }
        });
        updateEventSummary();
        updateFlowSummary();
    });
    </script>
    <?php
}

function eventosapp_whatsapp_flows_render_campaign_filter_tags($segment) {
    $filters = is_array($segment['filters'] ?? null) ? $segment['filters'] : [];
    $status_options = eventosapp_whatsapp_flows_bulk_status_options();
    $delivery_options = eventosapp_whatsapp_flows_bulk_delivery_options();
    $modalidad_labels = eventosapp_whatsapp_flows_bulk_modalidad_labels();
    $flow_status_labels = [
        'no_recibido' => 'No han recibido este Flow',
        'todos'       => 'Todos',
        'recibido'    => 'Ya recibieron este Flow',
    ];

    echo '<div class="evapp-flow-tagline">';
    echo '<span class="evapp-flow-filter-tag"><strong>Evento:</strong> ' . esc_html(get_the_title(absint($segment['event_id'] ?? 0))) . '</span>';
    echo '<span class="evapp-flow-filter-tag"><strong>Flow:</strong> ' . esc_html($segment['flow_label'] ?? eventosapp_whatsapp_flows_bulk_flow_label(absint($segment['flow_post_id'] ?? 0))) . '</span>';
    echo '<span class="evapp-flow-filter-tag"><strong>Número emisor:</strong> ' . esc_html($segment['sender_phone_label'] ?? 'Número por defecto') . '</span>';
    echo '<span class="evapp-flow-filter-tag"><strong>Reglas:</strong> ' . (! empty($segment['respect_rules']) ? 'Se respetan' : 'Se ignoran') . '</span>';

    $filter_labels = [
        'flow_send_status' => 'Estado frente al Flow',
        'whatsapp_status'  => 'Estado WhatsApp',
        'delivery_status'  => 'Webhook',
        'localidad'        => 'Localidad',
        'modalidad'        => 'Modalidad',
        'event_date'       => 'Fecha evento',
        'last_sent_from'   => 'Último envío desde',
        'last_sent_to'     => 'Último envío hasta',
        'created_from'     => 'Creado desde',
        'created_to'       => 'Creado hasta',
    ];
    foreach ( $filter_labels as $key => $label ) {
        if ( empty($filters[$key]) ) {
            continue;
        }
        $value = $filters[$key];
        if ( $key === 'whatsapp_status' ) {
            $value = $status_options[$value] ?? $value;
        } elseif ( $key === 'delivery_status' ) {
            $value = $delivery_options[$value] ?? $value;
        } elseif ( $key === 'modalidad' ) {
            $value = $modalidad_labels[$value] ?? $value;
        } elseif ( $key === 'flow_send_status' ) {
            $value = $flow_status_labels[$value] ?? $value;
        }
        echo '<span class="evapp-flow-filter-tag"><strong>' . esc_html($label) . ':</strong> ' . esc_html((string) $value) . '</span>';
    }
    if ( ! empty($filters['extra_fields']) && is_array($filters['extra_fields']) ) {
        foreach ( $filters['extra_fields'] as $field_key => $field_value ) {
            echo '<span class="evapp-flow-filter-tag"><strong>Campo ' . esc_html($field_key) . ':</strong> ' . esc_html((string) $field_value) . '</span>';
        }
    }
    echo '</div>';
}

function eventosapp_whatsapp_flows_render_campaign_step2($segment_id) {
    if ( $segment_id === '' ) {
        echo '<div class="notice notice-error"><p>No hay segmento seleccionado.</p></div><p><a href="' . esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_campaign')) . '" class="button">← Volver a filtros</a></p>';
        return;
    }
    $segment = get_option(eventosapp_whatsapp_flows_bulk_segment_option_key($segment_id));
    if ( ! $segment || ! is_array($segment) ) {
        echo '<div class="notice notice-error"><p>El segmento no existe o expiró.</p></div><p><a href="' . esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_campaign')) . '" class="button">← Crear nuevo segmento</a></p>';
        return;
    }

    $filters = is_array($segment['filters'] ?? null) ? $segment['filters'] : [];
    $ticket_ids = eventosapp_whatsapp_flows_bulk_get_filtered_tickets($filters);
    $segment['ticket_ids'] = $ticket_ids;
    $segment['total'] = count($ticket_ids);
    $segment['updated_at'] = current_time('mysql');
    update_option(eventosapp_whatsapp_flows_bulk_segment_option_key($segment_id), $segment, false);

    $total = count($ticket_ids);
    $flow_post_id = absint($segment['flow_post_id'] ?? 0);
    $event_id = absint($segment['event_id'] ?? 0);
    ?>
    <div class="evapp-card">
        <h2>Vista previa del segmento</h2>
        <?php eventosapp_whatsapp_flows_render_campaign_filter_tags($segment); ?>
        <div class="evapp-stat-grid">
            <div class="evapp-stat"><span>Total del segmento</span><strong><?php echo esc_html($total); ?></strong></div>
            <div class="evapp-stat"><span>Evento</span><strong style="font-size:16px;"><?php echo esc_html(get_the_title($event_id)); ?></strong></div>
            <div class="evapp-stat"><span>Modalidad evento</span><strong style="font-size:16px;"><?php echo esc_html(eventosapp_whatsapp_flows_bulk_get_event_modalidad_label($event_id)); ?></strong></div>
            <div class="evapp-stat"><span>Flow</span><strong style="font-size:16px;"><?php echo esc_html(eventosapp_whatsapp_flows_bulk_flow_label($flow_post_id)); ?></strong></div>
            <div class="evapp-stat"><span>Número emisor</span><strong style="font-size:16px;"><?php echo esc_html($segment['sender_phone_label'] ?? 'Número por defecto'); ?></strong></div>
        </div>

        <?php if ( $total > 0 ) : ?>
            <p>Se muestran los primeros <?php echo esc_html(min(150, $total)); ?> registros del segmento. El envío real procesará los <?php echo esc_html($total); ?> tickets filtrados.</p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Asistente</th>
                        <th>Teléfono</th>
                        <th>Localidad / modalidad</th>
                        <th>Último estado WhatsApp</th>
                        <th>Estado Flow</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( array_slice($ticket_ids, 0, 150) as $tid ) : ?>
                        <?php $row = eventosapp_whatsapp_flows_bulk_preview_ticket_row($tid, $flow_post_id, $event_id); ?>
                        <tr>
                            <td><strong><?php echo esc_html($row['ticket_code']); ?></strong><br><small>ID <?php echo esc_html($tid); ?></small></td>
                            <td><?php echo esc_html($row['name']); ?><br><small><?php echo esc_html($row['email']); ?></small></td>
                            <td><?php echo esc_html($row['phone']); ?></td>
                            <td><?php echo esc_html($row['localidad'] ?: 'Sin localidad'); ?><br><small><?php echo esc_html($row['modalidad']); ?></small></td>
                            <td><?php echo esc_html($row['status'] ?: 'Sin estado'); ?></td>
                            <td><span class="evapp-pill <?php echo $row['flow_status'] === 'Ya recibió este Flow' ? 'green' : 'gray'; ?>"><?php echo esc_html($row['flow_status']); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:18px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_campaign')); ?>" class="button">← Volver a filtros</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_campaign&step=3&segment_id=' . urlencode($segment_id))); ?>" class="button button-primary button-large" style="margin-left:10px;">Continuar al envío →</a>
            </p>
        <?php else : ?>
            <div class="evapp-empty">No hay tickets que cumplan los filtros seleccionados.</div>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_campaign')); ?>" class="button button-primary">← Ajustar filtros</a></p>
        <?php endif; ?>
    </div>
    <?php
}

function eventosapp_whatsapp_flows_render_campaign_step3($segment_id) {
    if ( $segment_id === '' ) {
        echo '<div class="notice notice-error"><p>No hay segmento seleccionado.</p></div><p><a href="' . esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_campaign')) . '" class="button">← Volver a filtros</a></p>';
        return;
    }
    $segment = get_option(eventosapp_whatsapp_flows_bulk_segment_option_key($segment_id));
    if ( ! $segment || ! is_array($segment) ) {
        echo '<div class="notice notice-error"><p>El segmento no existe o expiró.</p></div><p><a href="' . esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_campaign')) . '" class="button">← Crear nuevo segmento</a></p>';
        return;
    }
    $ticket_ids = isset($segment['ticket_ids']) && is_array($segment['ticket_ids']) ? array_values(array_filter(array_map('absint', $segment['ticket_ids']))) : [];
    $total = count($ticket_ids);
    ?>
    <div class="evapp-flow-progress-box">
        <h2>Enviar Flow al segmento</h2>
        <?php eventosapp_whatsapp_flows_render_campaign_filter_tags($segment); ?>
        <p><strong>Total a procesar:</strong> <?php echo esc_html($total); ?> tickets</p>
        <p><strong>Flow:</strong> <?php echo esc_html($segment['flow_label'] ?? eventosapp_whatsapp_flows_bulk_flow_label(absint($segment['flow_post_id'] ?? 0))); ?></p>
        <p><strong>Importante:</strong> cada intento quedará registrado en el historial del ticket y en el log central de WhatsApp con transporte <code>flow</code>.</p>

        <div class="evapp-wa-progress-bar-container">
            <div class="evapp-wa-progress-bar" id="progressBar" style="width:0%;"><span id="progressText">0%</span></div>
        </div>
        <div class="evapp-wa-stats-grid">
            <div class="evapp-wa-stat-card"><div class="number" id="processedCount">0</div><div class="label">Procesados</div></div>
            <div class="evapp-wa-stat-card"><div class="number" id="sentCount">0</div><div class="label">Enviados</div></div>
            <div class="evapp-wa-stat-card"><div class="number" id="skippedCount">0</div><div class="label">Omitidos</div></div>
            <div class="evapp-wa-stat-card"><div class="number" id="errorCount">0</div><div class="label">Errores</div></div>
        </div>
        <button type="button" class="button button-primary button-hero" id="startProcess" <?php disabled($total <= 0); ?>>Iniciar envío masivo de Flows</button>
        <button type="button" class="button button-secondary" id="pauseProcess" style="display:none;">Pausar</button>
        <div id="processStatus" style="margin-top:15px;"></div>
        <details open style="margin-top:16px;">
            <summary style="cursor:pointer;font-weight:700;color:#3454f4;">Ver log detallado</summary>
            <div class="evapp-wa-log-container" id="logContainer">
                <div class="evapp-wa-log-entry evapp-wa-log-info">[INFO] Sistema iniciado. Esperando inicio del proceso...</div>
            </div>
        </details>
        <div id="finalActions" style="display:none;margin-top:20px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_campaign')); ?>" class="button button-primary button-large">← Crear otro envío</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_manage')); ?>" class="button button-secondary">Gestionar Flows</a>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($){
        const segmentId = <?php echo wp_json_encode($segment_id); ?>;
        const total = <?php echo (int) $total; ?>;
        let offset = 0;
        let processed = 0;
        let sent = 0;
        let skipped = 0;
        let errors = 0;
        let running = false;
        const batchSize = 5;
        function addLog(message, type = 'info') {
            const $log = $('#logContainer');
            const timestamp = new Date().toLocaleTimeString();
            const safeMessage = $('<div>').text(message || '').html();
            const className = 'evapp-wa-log-' + type;
            $log.append('<div class="evapp-wa-log-entry ' + className + '">[' + timestamp + '] ' + safeMessage + '</div>');
            $log.scrollTop($log[0].scrollHeight);
        }
        function updateUI() {
            const percent = total > 0 ? Math.round((processed / total) * 100) : 0;
            $('#progressBar').css('width', percent + '%');
            $('#progressText').text(percent + '%');
            $('#processedCount').text(processed);
            $('#sentCount').text(sent);
            $('#skippedCount').text(skipped);
            $('#errorCount').text(errors);
            if (processed >= total) {
                running = false;
                $('#startProcess').hide();
                $('#pauseProcess').hide();
                $('#finalActions').show();
                $('#processStatus').html('<div class="evapp-success"><strong>✅ Envío completado</strong><p>Se procesaron todos los tickets del segmento.</p></div>');
                addLog('Proceso completado. Enviados: ' + sent + ', omitidos: ' + skipped + ', errores: ' + errors + '.', errors > 0 ? 'warning' : 'success');
            }
        }
        function processBatch() {
            if (!running || processed >= total) {
                updateUI();
                return;
            }
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'eventosapp_whatsapp_flow_process_batch',
                    segment_id: segmentId,
                    offset: offset,
                    batch_size: batchSize,
                    _wpnonce: '<?php echo esc_js(wp_create_nonce('eventosapp_whatsapp_flow_process')); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data || {};
                        processed += parseInt(data.processed || 0, 10);
                        sent += parseInt(data.sent || 0, 10);
                        skipped += parseInt(data.skipped || 0, 10);
                        errors += parseInt(data.errors || 0, 10);
                        offset = parseInt(data.next_offset || (offset + batchSize), 10);
                        if (data.logs && data.logs.length > 0) {
                            data.logs.forEach(function(log){ addLog(log.message, log.type); });
                        } else {
                            addLog('Lote procesado sin registros individuales.', 'info');
                        }
                        updateUI();
                        if (running && processed < total) {
                            setTimeout(processBatch, 500);
                        }
                    } else {
                        running = false;
                        $('#pauseProcess').hide();
                        $('#startProcess').show().text('Reintentar desde offset ' + offset);
                        const msg = response.data || 'Error desconocido en el lote.';
                        $('#processStatus').html('<div class="notice notice-error"><p>' + $('<div>').text(msg).html() + '</p></div>');
                        addLog('Error AJAX: ' + msg, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    running = false;
                    $('#pauseProcess').hide();
                    $('#startProcess').show().text('Reintentar desde offset ' + offset);
                    const msg = error || status || 'Error de conexión';
                    $('#processStatus').html('<div class="notice notice-error"><p>Error de conexión: ' + $('<div>').text(msg).html() + '</p></div>');
                    addLog('Error de conexión: ' + msg, 'error');
                }
            });
        }
        $('#startProcess').on('click', function(){
            if (total <= 0) {
                addLog('No hay tickets para procesar.', 'warning');
                return;
            }
            running = true;
            $('#startProcess').hide();
            $('#pauseProcess').show();
            $('#processStatus').html('<div class="evapp-info"><strong>Procesando...</strong> No cierres esta ventana hasta que finalice.</div>');
            addLog('Iniciando envío masivo de Flows para ' + total + ' tickets.', 'info');
            processBatch();
        });
        $('#pauseProcess').on('click', function(){
            running = false;
            $('#pauseProcess').hide();
            $('#startProcess').show().text('Continuar desde offset ' + offset);
            $('#processStatus').html('<div class="evapp-warning"><strong>Proceso pausado.</strong> Puedes continuar desde el último lote procesado.</div>');
            addLog('Proceso pausado por el usuario en offset ' + offset + '.', 'warning');
        });
    });
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

function eventosapp_whatsapp_flows_options_to_text($options) {
    if ( empty($options) || ! is_array($options) ) {
        return '';
    }
    $lines = [];
    foreach ( $options as $option ) {
        if ( is_array($option) ) {
            $id = sanitize_key((string)($option['id'] ?? ''));
            $title = sanitize_text_field((string)($option['title'] ?? ''));
            if ( $title === '' ) {
                continue;
            }
            $lines[] = $id !== '' && $id !== sanitize_key(remove_accents($title)) ? $id . '|' . $title : $title;
        } else {
            $lines[] = sanitize_text_field((string) $option);
        }
    }
    return implode("\n", array_filter($lines));
}

function eventosapp_whatsapp_flows_render_question_row($index, $question, $question_types) {
    $index = is_numeric($index) ? absint($index) : 0;
    $question = is_array($question) ? $question : [];
    $type = sanitize_key((string)($question['type'] ?? 'radio'));
    if ( ! isset($question_types[$type]) ) {
        $type = 'radio';
    }
    $type_help = eventosapp_whatsapp_flows_type_help();
    $display_types = eventosapp_whatsapp_flows_display_question_types();
    $choice_types = ['radio', 'checkbox', 'dropdown'];
    $text_input_types = eventosapp_whatsapp_flows_text_input_types();
    $input_type = sanitize_key((string)($question['input_type'] ?? 'text'));
    if ( ! isset($text_input_types[$input_type]) ) {
        $input_type = 'text';
    }
    $classes = ['evapp-question'];
    if ( in_array($type, $display_types, true) ) { $classes[] = 'is-display'; }
    if ( in_array($type, $choice_types, true) ) { $classes[] = 'is-choice'; }
    if ( $type === 'text' ) { $classes[] = 'is-text-input'; }
    if ( $type === 'date' ) { $classes[] = 'is-date'; }
    if ( $type === 'optin' ) { $classes[] = 'is-optin'; }
    $options_text = eventosapp_whatsapp_flows_options_to_text($question['options'] ?? []);
    ?>
    <div class="<?php echo esc_attr(implode(' ', $classes)); ?>" data-question-index="<?php echo esc_attr($index); ?>">
        <div class="evapp-question-head">
            <div class="evapp-question-title"><span class="evapp-question-number"><?php echo esc_html($index + 1); ?></span><strong><?php echo esc_html($question_types[$type] ?? 'Campo'); ?></strong></div>
            <button type="button" class="button-link-delete evapp-remove-question">Quitar</button>
        </div>
        <div class="evapp-question-body">
            <div class="evapp-row">
                <label class="evapp-field"><span>Texto visible</span><input type="text" class="large-text" name="questions[<?php echo esc_attr($index); ?>][label]" value="<?php echo esc_attr($question['label'] ?? ''); ?>" placeholder="Pregunta o texto de sección"></label>
                <label class="evapp-field"><span>Slug / nombre interno</span><input type="text" class="regular-text" name="questions[<?php echo esc_attr($index); ?>][slug]" value="<?php echo esc_attr($question['slug'] ?? ''); ?>" placeholder="campo_respuesta"></label>
            </div>
            <div class="evapp-row">
                <label class="evapp-field"><span>Componente WhatsApp Flow</span><select class="evapp-question-type" name="questions[<?php echo esc_attr($index); ?>][type]">
                    <?php foreach ( $question_types as $key => $label ) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($type, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label class="evapp-field evapp-required-wrap"><span>Validación</span><label class="evapp-checkline"><input type="checkbox" name="questions[<?php echo esc_attr($index); ?>][required]" value="1" <?php checked(($question['required'] ?? '0'), '1'); ?>> Obligatoria</label></label>
            </div>
            <div class="evapp-row evapp-text-input-type-wrap">
                <label class="evapp-field"><span>Formato de TextInput</span><select name="questions[<?php echo esc_attr($index); ?>][input_type]">
                    <?php foreach ( $text_input_types as $key => $label ) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($input_type, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select><span class="description">No crea otro componente: solo cambia el formato interno de TextInput.</span></label>
            </div>
            <label class="evapp-field"><span>Ayuda / instrucción opcional</span><textarea rows="2" name="questions[<?php echo esc_attr($index); ?>][help]" placeholder="Ej: 1 es el mínimo y 5 el máximo"><?php echo esc_textarea($question['help'] ?? ''); ?></textarea></label>
            <div class="evapp-row evapp-text-limits-wrap">
                <label class="evapp-field"><span>Mínimo de caracteres</span><input type="number" name="questions[<?php echo esc_attr($index); ?>][min_chars]" value="<?php echo esc_attr(absint($question['min_chars'] ?? 0)); ?>" min="0"></label>
                <label class="evapp-field"><span>Máximo de caracteres</span><input type="number" name="questions[<?php echo esc_attr($index); ?>][max_chars]" value="<?php echo esc_attr(absint($question['max_chars'] ?? 0)); ?>" min="0"></label>
            </div>
            <label class="evapp-field evapp-options-wrap"><span>Opciones, una por línea</span><textarea rows="5" name="questions[<?php echo esc_attr($index); ?>][options]" placeholder="opcion_1|Opción 1&#10;opcion_2|Opción 2"><?php echo esc_textarea($options_text); ?></textarea><span class="description">También puedes usar id|Texto visible si necesitas controlar el valor interno.</span></label>
            <div class="evapp-type-help" data-help-for="<?php echo esc_attr($type); ?>"><?php echo esc_html($type_help[$type] ?? ''); ?></div>
        </div>
    </div>
    <?php
}


function eventosapp_whatsapp_flows_render_builder_script($question_types, $type_help) {
    $types_json = wp_json_encode($question_types, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $help_json = wp_json_encode($type_help, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $input_types_json = wp_json_encode(eventosapp_whatsapp_flows_text_input_types(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>
    <script>
    (function(){
        var wrap = document.getElementById('evapp-wa-flow-questions');
        if (!wrap) return;
        var questionTypes = <?php echo $types_json ? $types_json : '{}'; ?>;
        var typeHelp = <?php echo $help_json ? $help_json : '{}'; ?>;
        var textInputTypes = <?php echo $input_types_json ? $input_types_json : '{}'; ?>;
        var displayTypes = ['heading','subheading','body','caption'];
        var choiceTypes = ['radio','checkbox','dropdown'];
        var textLimitTypes = ['text','textarea'];
        var componentDefaults = {
            heading: {type:'heading', label:'TextHeading', slug:'text_heading', help:'', options:'', required:false},
            subheading: {type:'subheading', label:'TextSubheading', slug:'text_subheading', help:'', options:'', required:false},
            body: {type:'body', label:'TextBody', slug:'text_body', help:'', options:'', required:false},
            caption: {type:'caption', label:'TextCaption', slug:'text_caption', help:'', options:'', required:false},
            radio: {type:'radio', label:'RadioButtonsGroup', slug:'radio_buttons_group', help:'', options:'opcion_1|Opción 1\nopcion_2|Opción 2', required:true},
            checkbox: {type:'checkbox', label:'CheckboxGroup', slug:'checkbox_group', help:'', options:'opcion_1|Opción 1\nopcion_2|Opción 2', required:false},
            dropdown: {type:'dropdown', label:'Dropdown', slug:'dropdown', help:'', options:'opcion_1|Opción 1\nopcion_2|Opción 2', required:false},
            text: {type:'text', input_type:'text', label:'TextInput', slug:'text_input', help:'', options:'', required:false},
            textarea: {type:'textarea', label:'TextArea', slug:'text_area', help:'', options:'', required:false},
            date: {type:'date', label:'DatePicker', slug:'date_picker', help:'', options:'', required:false},
            optin: {type:'optin', label:'OptIn', slug:'opt_in', help:'', options:'', required:true}
        };
        function esc(v){ return String(v || '').replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]; }); }
        function nextIndex(){ return wrap.querySelectorAll('.evapp-question').length; }
        function typeOptions(selected){
            var html='';
            Object.keys(questionTypes).forEach(function(key){ html += '<option value="'+esc(key)+'"'+(key===selected?' selected':'')+'>'+esc(questionTypes[key])+'</option>'; });
            return html;
        }
        function inputTypeOptions(selected){
            var html='';
            selected = selected || 'text';
            Object.keys(textInputTypes).forEach(function(key){ html += '<option value="'+esc(key)+'"'+(key===selected?' selected':'')+'>'+esc(textInputTypes[key])+'</option>'; });
            return html;
        }
        function questionTemplate(i, data){
            data = data || {};
            var type = data.type || 'radio';
            var required = data.required !== false;
            var inputType = data.input_type || 'text';
            return '<div class="evapp-question" data-question-index="'+i+'">'+
                '<div class="evapp-question-head"><div class="evapp-question-title"><span class="evapp-question-number">'+(i+1)+'</span><strong>'+esc(questionTypes[type] || 'Campo')+'</strong></div><button type="button" class="button-link-delete evapp-remove-question">Quitar</button></div>'+ 
                '<div class="evapp-question-body">'+
                '<div class="evapp-row"><label class="evapp-field"><span>Texto visible</span><input type="text" class="large-text" name="questions['+i+'][label]" value="'+esc(data.label || 'Nueva pregunta')+'" placeholder="Pregunta o texto de sección"></label><label class="evapp-field"><span>Slug / nombre interno</span><input type="text" class="regular-text" name="questions['+i+'][slug]" value="'+esc(data.slug || ('pregunta_'+(i+1)))+'" placeholder="campo_respuesta"></label></div>'+ 
                '<div class="evapp-row"><label class="evapp-field"><span>Componente WhatsApp Flow</span><select class="evapp-question-type" name="questions['+i+'][type]">'+typeOptions(type)+'</select></label><label class="evapp-field evapp-required-wrap"><span>Validación</span><label class="evapp-checkline"><input type="checkbox" name="questions['+i+'][required]" value="1" '+(required?'checked':'')+'> Obligatoria</label></label></div>'+ 
                '<div class="evapp-row evapp-text-input-type-wrap"><label class="evapp-field"><span>Formato de TextInput</span><select name="questions['+i+'][input_type]">'+inputTypeOptions(inputType)+'</select><span class="description">No crea otro componente: solo cambia el formato interno de TextInput.</span></label></div>'+ 
                '<label class="evapp-field"><span>Ayuda / instrucción opcional</span><textarea rows="2" name="questions['+i+'][help]" placeholder="Ej: 1 es el mínimo y 5 el máximo">'+esc(data.help || '')+'</textarea></label>'+ 
                '<div class="evapp-row evapp-text-limits-wrap"><label class="evapp-field"><span>Mínimo de caracteres</span><input type="number" name="questions['+i+'][min_chars]" value="0" min="0"></label><label class="evapp-field"><span>Máximo de caracteres</span><input type="number" name="questions['+i+'][max_chars]" value="0" min="0"></label></div>'+ 
                '<label class="evapp-field evapp-options-wrap"><span>Opciones, una por línea</span><textarea rows="5" name="questions['+i+'][options]" placeholder="opcion_1|Opción 1&#10;opcion_2|Opción 2">'+esc(data.options || 'opcion_1|Opción 1\nopcion_2|Opción 2')+'</textarea><span class="description">También puedes usar id|Texto visible si necesitas controlar el valor interno.</span></label>'+ 
                '<div class="evapp-type-help">'+esc(typeHelp[type] || '')+'</div>'+ 
                '</div></div>';
        }
        function refreshBlock(block){
            if(!block) return;
            var select = block.querySelector('.evapp-question-type');
            var type = select ? select.value : 'radio';
            block.classList.toggle('is-display', displayTypes.indexOf(type) !== -1);
            block.classList.toggle('is-choice', choiceTypes.indexOf(type) !== -1);
            block.classList.toggle('is-text-input', type === 'text');
            block.classList.toggle('is-date', type === 'date');
            block.classList.toggle('is-optin', type === 'optin');
            var limits = block.querySelector('.evapp-text-limits-wrap');
            if(limits) limits.style.display = textLimitTypes.indexOf(type) !== -1 ? '' : 'none';
            var title = block.querySelector('.evapp-question-title strong');
            if(title) title.textContent = questionTypes[type] || 'Campo';
            var help = block.querySelector('.evapp-type-help');
            if(help) help.textContent = typeHelp[type] || '';
        }
        function refreshAll(){ Array.prototype.forEach.call(wrap.querySelectorAll('.evapp-question'), refreshBlock); }
        document.addEventListener('click', function(e){
            if(e.target && e.target.classList.contains('evapp-remove-question')){
                var q=e.target.closest('.evapp-question');
                if(q) q.remove();
            }
            if(e.target && e.target.classList.contains('evapp-add-component')){
                var component=e.target.getAttribute('data-component');
                var item=componentDefaults[component];
                if(item){
                    var i=nextIndex();
                    var data=Object.assign({}, item);
                    data.slug = (data.slug || component) + '_' + (i + 1);
                    wrap.insertAdjacentHTML('beforeend', questionTemplate(i, data));
                    refreshAll();
                }
            }
        });
        wrap.addEventListener('change', function(e){ if(e.target && e.target.classList.contains('evapp-question-type')) refreshBlock(e.target.closest('.evapp-question')); });
        refreshAll();
    })();
    </script>
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
