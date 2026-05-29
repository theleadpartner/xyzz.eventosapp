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
 * Tipos de campo disponibles para construir encuestas usando únicamente
 * componentes reales soportados por WhatsApp Flows.
 *
 * Importante: NPS, escala 1 a 5 y Sí/No no son componentes propios de Meta.
 * En EventosApp se agregan como presets, pero se generan como RadioButtonsGroup.
 */
function eventosapp_whatsapp_flows_question_types() {
    return [
        'heading'    => 'TextHeading — Título de sección',
        'subheading' => 'TextSubheading — Subtítulo',
        'body'       => 'TextBody — Texto informativo',
        'caption'    => 'TextCaption — Nota pequeña',
        'radio'      => 'RadioButtonsGroup — Selección única',
        'checkbox'   => 'CheckboxGroup — Selección múltiple',
        'dropdown'   => 'Dropdown — Lista desplegable',
        'text'       => 'TextInput — Campo de texto',
        'textarea'   => 'TextArea — Comentario largo',
        'date'       => 'DatePicker — Fecha',
        'optin'      => 'OptIn — Aceptación / consentimiento',
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
        'heading'    => 'Componente real: TextHeading. Úsalo para separar bloques como “Valoración del evento”, “Conferencista” o “Queremos conocerte más”. No guarda respuesta.',
        'subheading' => 'Componente real: TextSubheading. Úsalo para subtítulos cortos dentro de una sección. No guarda respuesta.',
        'body'       => 'Componente real: TextBody. Úsalo para instrucciones, contexto o textos legales cortos. No guarda respuesta.',
        'caption'    => 'Componente real: TextCaption. Úsalo para notas pequeñas, aclaraciones o ayudas visuales. No guarda respuesta.',
        'radio'      => 'Componente real: RadioButtonsGroup. Úsalo para una sola respuesta. NPS 0-10, satisfacción 1-5 y Sí/No se hacen con este componente y opciones predefinidas.',
        'checkbox'   => 'Componente real: CheckboxGroup. Úsalo cuando el asistente pueda seleccionar varias respuestas al mismo tiempo.',
        'dropdown'   => 'Componente real: Dropdown. Úsalo para listas largas; ocupa menos espacio que RadioButtonsGroup.',
        'text'       => 'Componente real: TextInput. Úsalo para datos cortos. El formato interno puede ser texto, email, número o teléfono.',
        'textarea'   => 'Componente real: TextArea. Úsalo para comentarios, sugerencias y respuestas abiertas largas.',
        'date'       => 'Componente real: DatePicker. Úsalo para fechas, reservas o disponibilidad.',
        'optin'      => 'Componente real: OptIn. Úsalo para autorizaciones, tratamiento de datos y aceptación de términos.',
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

        // Migración segura desde la versión anterior del constructor:
        // nps, rating5 y yesno NO son componentes reales de Meta. Se convierten en RadioButtonsGroup.
        if ( in_array($legacy_type, ['nps', 'rating5', 'yesno'], true) ) {
            $type = 'radio';
        }

        // email, number y phone NO son componentes separados; son variantes de TextInput.
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
    $config['screen_id'] = eventosapp_whatsapp_flows_sanitize_slug($config['screen_id'], 'SURVEY');
    $config['screen_id'] = strtoupper($config['screen_id']);
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

    $placeholder = sanitize_text_field((string)($question['placeholder'] ?? ''));
    if ( $placeholder !== '' && in_array($component['type'], ['TextInput', 'TextArea'], true) ) {
        $component['placeholder'] = eventosapp_whatsapp_flows_text_limit($placeholder, 80);
    }

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
    $screen_id    = eventosapp_whatsapp_flows_sanitize_slug($config['screen_id'] ?? 'SURVEY', 'SURVEY');
    $screen_id    = strtoupper($screen_id);
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
        $screen_ids[$idx] = $idx === 0 ? $screen_id : $screen_id . '_' . ($idx + 1);
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
        .eventosapp-wa-flows{--evapp-blue:#3454f4;--evapp-blue2:#eef2ff;--evapp-ink:#152234;--evapp-muted:#667085;--evapp-border:#d9e1ef;--evapp-bg:#f5f7fb;--evapp-card:#fff;--evapp-green:#0a9b67;--evapp-orange:#d97706}.eventosapp-wa-flows.wrap{background:var(--evapp-bg);padding:20px;margin:0 0 0 -20px;min-height:calc(100vh - 32px)}.eventosapp-wa-flows h1{font-size:28px;font-weight:800;color:var(--evapp-ink);margin:0 0 18px}.eventosapp-wa-flows .evapp-page-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}.eventosapp-wa-flows .evapp-page-head p{margin:.35rem 0 0;color:var(--evapp-muted);font-size:14px}.eventosapp-wa-flows .evapp-top-actions{display:flex;gap:8px;flex-wrap:wrap}.eventosapp-wa-flows .evapp-card{background:var(--evapp-card);border:1px solid var(--evapp-border);border-radius:16px;padding:18px;box-shadow:0 8px 22px rgba(15,23,42,.05);margin-bottom:18px}.eventosapp-wa-flows .evapp-card h2{font-size:17px;margin:0 0 12px;color:var(--evapp-ink)}.eventosapp-wa-flows .evapp-card h3{font-size:15px;margin:18px 0 10px;color:var(--evapp-ink)}.eventosapp-wa-flows .evapp-grid{display:grid;grid-template-columns:minmax(520px,1.15fr) minmax(330px,.85fr);gap:18px;align-items:start}.eventosapp-wa-flows .evapp-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.eventosapp-wa-flows .evapp-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}.eventosapp-wa-flows .evapp-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}.eventosapp-wa-flows .evapp-field{display:block;margin-bottom:12px}.eventosapp-wa-flows .evapp-field span,.eventosapp-wa-flows .evapp-label{display:block;font-weight:700;color:#26364a;margin-bottom:6px}.eventosapp-wa-flows input[type=text],.eventosapp-wa-flows input[type=number],.eventosapp-wa-flows select,.eventosapp-wa-flows textarea{border:1px solid #cfd8e6;border-radius:10px;min-height:38px;box-shadow:none}.eventosapp-wa-flows textarea{padding:8px 10px}.eventosapp-wa-flows .regular-text,.eventosapp-wa-flows .large-text{max-width:100%;width:100%}.eventosapp-wa-flows .evapp-muted,.eventosapp-wa-flows .description{color:var(--evapp-muted)}.eventosapp-wa-flows .evapp-pill{display:inline-flex;align-items:center;border-radius:999px;background:var(--evapp-blue2);color:#203bc4;padding:4px 9px;font-size:12px;font-weight:800}.eventosapp-wa-flows .evapp-pill.green{background:#e9f9f1;color:#07724d}.eventosapp-wa-flows .evapp-pill.gray{background:#eef1f5;color:#4b5563}.eventosapp-wa-flows .evapp-stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}.eventosapp-wa-flows .evapp-stat{background:linear-gradient(180deg,#fff,#f7f9ff);border:1px solid #e3e9f6;border-radius:14px;padding:13px}.eventosapp-wa-flows .evapp-stat span{display:block;font-weight:700;color:var(--evapp-muted);font-size:12px}.eventosapp-wa-flows .evapp-stat strong{display:block;font-size:24px;color:var(--evapp-ink);line-height:1.1;margin-top:4px}.eventosapp-wa-flows .evapp-builder-toolbar{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:12px 0 14px}.eventosapp-wa-flows .evapp-builder-toolbar button{min-height:38px;border-radius:10px}.eventosapp-wa-flows .evapp-question{border:1px solid #d9e1ef;border-radius:16px;margin:12px 0;background:#fff;overflow:hidden}.eventosapp-wa-flows .evapp-question-head{display:flex;justify-content:space-between;gap:12px;align-items:center;background:#f8faff;padding:12px 14px;border-bottom:1px solid #e6edf8}.eventosapp-wa-flows .evapp-question-title{display:flex;align-items:center;gap:9px}.eventosapp-wa-flows .evapp-question-number{display:inline-flex;justify-content:center;align-items:center;width:28px;height:28px;border-radius:9px;background:var(--evapp-blue);color:#fff;font-weight:800}.eventosapp-wa-flows .evapp-question-body{padding:14px}.eventosapp-wa-flows .evapp-type-help{padding:9px 10px;border-radius:10px;background:#f8fafc;border:1px solid #e5edf7;color:#536071;margin:8px 0 0;font-size:12px}.eventosapp-wa-flows .evapp-options-wrap textarea{font-family:Menlo,Consolas,monospace;min-height:96px}.eventosapp-wa-flows .evapp-question.is-display .evapp-options-wrap,.eventosapp-wa-flows .evapp-question.is-display .evapp-required-wrap,.eventosapp-wa-flows .evapp-question.is-display .evapp-placeholder-wrap{display:none}.eventosapp-wa-flows .evapp-question.is-choice .evapp-placeholder-wrap,.eventosapp-wa-flows .evapp-question.is-date .evapp-placeholder-wrap,.eventosapp-wa-flows .evapp-question.is-optin .evapp-placeholder-wrap,.eventosapp-wa-flows .evapp-question.is-optin .evapp-options-wrap,.eventosapp-wa-flows .evapp-question:not(.is-text-input) .evapp-text-input-type-wrap{display:none}.eventosapp-wa-flows textarea.code{width:100%;min-height:310px;font-family:Menlo,Consolas,monospace;background:#0f172a;color:#d9e9ff;border-radius:14px;padding:14px}.eventosapp-wa-flows .widefat{border:1px solid #dce4f1;border-radius:12px;overflow:hidden}.eventosapp-wa-flows .widefat th{font-weight:800;color:#26364a}.eventosapp-wa-flows .widefat td{vertical-align:top}.eventosapp-wa-flows .evapp-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.eventosapp-wa-flows .evapp-response-pre{white-space:pre-wrap;max-height:130px;overflow:auto;background:#f8fafc;border-radius:10px;padding:8px}.eventosapp-wa-flows .evapp-warning{border-left:4px solid var(--evapp-orange);background:#fff7ed;padding:12px;border-radius:12px;margin:12px 0;color:#7c2d12}.eventosapp-wa-flows .evapp-info{border-left:4px solid var(--evapp-blue);background:#eef2ff;padding:12px;border-radius:12px;margin:12px 0;color:#26364a}.eventosapp-wa-flows .evapp-success{border-left:4px solid var(--evapp-green);background:#ecfdf3;padding:12px;border-radius:12px;margin:12px 0}.eventosapp-wa-flows .button{border-radius:9px}.eventosapp-wa-flows .button-primary{background:var(--evapp-blue);border-color:var(--evapp-blue)}.eventosapp-wa-flows .evapp-empty{padding:22px;border:1px dashed #cfd8e6;border-radius:14px;background:#fafcff;color:var(--evapp-muted);text-align:center}.eventosapp-wa-flows .evapp-template-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.eventosapp-wa-flows .evapp-template-card{border:1px solid #dce4f1;border-radius:13px;padding:12px;background:#fbfdff}.eventosapp-wa-flows .evapp-template-card strong{display:block;color:var(--evapp-ink);margin-bottom:4px}.eventosapp-wa-flows .evapp-template-card p{margin:0;color:var(--evapp-muted);font-size:12px}.eventosapp-wa-flows .evapp-small{font-size:12px}.eventosapp-wa-flows .evapp-checkline{display:flex;gap:7px;align-items:center;margin:8px 0}.eventosapp-wa-flows .evapp-form-table{width:100%;border-collapse:separate;border-spacing:0 12px}.eventosapp-wa-flows .evapp-form-table th{width:170px;text-align:left;vertical-align:top;padding-top:8px;color:#26364a}.eventosapp-wa-flows .evapp-form-table td{vertical-align:top}@media(max-width:1200px){.eventosapp-wa-flows .evapp-grid{grid-template-columns:1fr}.eventosapp-wa-flows .evapp-builder-toolbar{grid-template-columns:repeat(2,1fr)}}@media(max-width:782px){.eventosapp-wa-flows.wrap{margin-left:-10px;padding:14px}.eventosapp-wa-flows .evapp-stat-grid,.eventosapp-wa-flows .evapp-grid-3,.eventosapp-wa-flows .evapp-row,.eventosapp-wa-flows .evapp-row-3,.eventosapp-wa-flows .evapp-template-grid{grid-template-columns:1fr}.eventosapp-wa-flows .evapp-page-head{display:block}.eventosapp-wa-flows .evapp-form-table th,.eventosapp-wa-flows .evapp-form-table td{display:block;width:100%}}
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
                            <label class="evapp-field"><span>ID pantalla inicial</span><input type="text" name="flow_screen_id" value="<?php echo esc_attr($edit_config['screen_id']); ?>"></label>
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
                            <strong>Componentes reales de WhatsApp Flows:</strong> este constructor solo genera componentes soportados: <strong>TextHeading, TextSubheading, TextBody, TextCaption, TextInput, TextArea, RadioButtonsGroup, CheckboxGroup, Dropdown, DatePicker, OptIn y Footer</strong>. NPS, satisfacción 1 a 5 y Sí/No son presets que se crean como <strong>RadioButtonsGroup</strong>, no como componentes inventados.
                        </div>

                        <h2>2. Preguntas y bloques de la encuesta</h2>
                        <div class="evapp-builder-toolbar">
                            <button type="button" class="button evapp-add-preset" data-preset="heading">+ Sección</button>
                            <button type="button" class="button evapp-add-preset" data-preset="nps">+ NPS como RadioButtons</button>
                            <button type="button" class="button evapp-add-preset" data-preset="rating5">+ Escala 1-5 como RadioButtons</button>
                            <button type="button" class="button evapp-add-preset" data-preset="source">+ Medio / fuente</button>
                            <button type="button" class="button evapp-add-preset" data-preset="comment">+ Comentario</button>
                            <button type="button" class="button evapp-add-preset" data-preset="personal">+ Datos personales</button>
                            <button type="button" class="button evapp-add-preset" data-preset="consent">+ Consentimiento</button>
                            <button type="button" class="button evapp-add-preset" data-preset="complete">+ Encuesta completa</button>
                            <button type="button" class="button" id="evapp-wa-add-question">+ Campo en blanco</button>
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
                    <h2>Guía de campos</h2>
                    <div class="evapp-template-grid">
                        <div class="evapp-template-card"><strong>RadioButtonsGroup</strong><p>Selección única. Sirve para NPS 0-10, satisfacción 1-5, Sí/No y respuestas cerradas.</p></div>
                        <div class="evapp-template-card"><strong>CheckboxGroup</strong><p>Selección múltiple. Sirve para intereses, temas o canales.</p></div>
                        <div class="evapp-template-card"><strong>Dropdown</strong><p>Selección única en lista. Úsalo cuando hay muchas opciones.</p></div>
                        <div class="evapp-template-card"><strong>TextInput / TextArea</strong><p>Datos cortos o comentarios largos. TextInput permite formato texto, email, número o teléfono.</p></div>
                        <div class="evapp-template-card"><strong>DatePicker</strong><p>Fechas con selector nativo dentro del Flow.</p></div>
                        <div class="evapp-template-card"><strong>OptIn</strong><p>Aceptación de términos, consentimiento o tratamiento de datos.</p></div>
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
    $flows = eventosapp_whatsapp_flows_get_all_for_select();
    $events = eventosapp_whatsapp_flows_get_events_for_select();
    $selected_flow_id = absint($_GET['flow_id'] ?? 0);
    $last = get_option('eventosapp_whatsapp_flow_last_campaign_result', []);
    ?>
    <div class="wrap eventosapp-wa-flows">
        <?php eventosapp_whatsapp_flows_admin_styles(); ?>
        <div class="evapp-page-head">
            <div><h1>Envío Masivo de Flows</h1><p>Envía una encuesta Flow a los asistentes de un evento por lotes controlados.</p></div>
            <div class="evapp-top-actions"><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows_manage')); ?>">Gestionar Flows</a><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_flows&flow_id=0')); ?>">Crear Flow</a></div>
        </div>
        <?php eventosapp_whatsapp_flows_render_notices(); ?>
        <div class="evapp-grid">
            <div class="evapp-card">
                <h2>Configurar envío</h2>
                <div class="evapp-warning"><strong>Uso recomendado:</strong> el envío directo de Flow sirve para pruebas o conversaciones activas. Para campañas fuera de la ventana de conversación, usa una plantilla Flow aprobada por Meta.</div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('eventosapp_whatsapp_flow_campaign_send'); ?>
                    <input type="hidden" name="action" value="eventosapp_whatsapp_flow_campaign_send">
                    <input type="hidden" name="return_page" value="campaign">
                    <label class="evapp-field"><span>Evento</span><select name="campaign_event_id" required><option value="">Seleccionar evento</option><?php foreach ( $events as $event ) : ?><option value="<?php echo esc_attr($event->ID); ?>"><?php echo esc_html(get_the_title($event)); ?></option><?php endforeach; ?></select></label>
                    <label class="evapp-field"><span>Flow a enviar</span><select name="flow_post_id" required><option value="">Seleccionar Flow</option><?php foreach ( $flows as $item ) : ?><option value="<?php echo esc_attr($item['id']); ?>" <?php selected($selected_flow_id, $item['id']); ?>><?php echo esc_html($item['title'] . ($item['meta_flow_id'] ? ' — Meta ID ' . $item['meta_flow_id'] : ' — sin Meta ID')); ?></option><?php endforeach; ?></select></label>
                    <div class="evapp-row">
                        <label class="evapp-field"><span>Límite por lote</span><input type="number" name="campaign_limit" value="25" min="1" max="100"></label>
                        <label class="evapp-field"><span>Offset</span><input type="number" name="campaign_offset" value="0" min="0"></label>
                    </div>
                    <label class="evapp-checkline"><input type="checkbox" name="campaign_skip_existing" value="1" checked> Omitir tickets que ya recibieron este mismo Flow para este evento</label>
                    <button type="submit" class="button button-primary button-hero">Enviar lote de Flows</button>
                </form>
            </div>
            <div>
                <div class="evapp-card">
                    <h2>Último resultado</h2>
                    <?php if ( is_array($last) && ! empty($last['created_at']) ) : ?>
                        <p><strong>Fecha:</strong> <?php echo esc_html($last['created_at']); ?></p>
                        <p><strong>Procesados:</strong> <?php echo esc_html($last['processed'] ?? 0); ?></p>
                        <p><strong>Enviados:</strong> <?php echo esc_html($last['ok'] ?? 0); ?></p>
                        <p><strong>Errores:</strong> <?php echo esc_html($last['errors'] ?? 0); ?></p>
                        <p><strong>Omitidos:</strong> <?php echo esc_html($last['skipped'] ?? 0); ?></p>
                    <?php else : ?>
                        <div class="evapp-empty">No hay resultados de envío masivo todavía.</div>
                    <?php endif; ?>
                </div>
                <div class="evapp-card">
                    <h2>Proceso sugerido</h2>
                    <ol>
                        <li>Crea y prueba el Flow.</li>
                        <li>Confirma que el preview en Meta muestra tus preguntas.</li>
                        <li>Publica el Flow.</li>
                        <li>Selecciona evento y Flow.</li>
                        <li>Envía en lotes de 25 a 100 asistentes.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
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
            <div class="evapp-row-3">
                <label class="evapp-field"><span>Componente WhatsApp Flow</span><select class="evapp-question-type" name="questions[<?php echo esc_attr($index); ?>][type]">
                    <?php foreach ( $question_types as $key => $label ) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($type, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label class="evapp-field evapp-placeholder-wrap"><span>Placeholder opcional</span><input type="text" name="questions[<?php echo esc_attr($index); ?>][placeholder]" value="<?php echo esc_attr($question['placeholder'] ?? ''); ?>" placeholder="Ej: Escribe tu respuesta"></label>
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
            <label class="evapp-field evapp-options-wrap"><span>Opciones, una por línea</span><textarea rows="5" name="questions[<?php echo esc_attr($index); ?>][options]" placeholder="Excelente&#10;Buena&#10;Regular&#10;Mala"><?php echo esc_textarea($options_text); ?></textarea><span class="description">También puedes usar id|Texto visible si necesitas controlar el valor interno. Para NPS usa opciones 0 a 10; para satisfacción usa 1 a 5.</span></label>
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
        var rating5Options = '1|1 - Muy insatisfecho\n2|2\n3|3 - Regular\n4|4\n5|5 - Muy satisfecho';
        var npsOptions = '0|0\n1|1\n2|2\n3|3\n4|4\n5|5\n6|6\n7|7\n8|8\n9|9\n10|10';
        var yesNoOptions = 'si|Sí\nno|No';
        var presetMap = {
            heading: [{type:'heading', label:'Nueva sección', slug:'seccion', help:'', options:'', required:false}],
            nps: [{type:'radio', label:'¿Qué tan probable es que recomiendes este evento a un amigo o familiar?', slug:'probabilidad_recomendar', help:'0 es nada probable y 10 es muy probable. Este preset usa RadioButtonsGroup.', options:npsOptions, required:true}],
            rating5: [{type:'radio', label:'Califica tu grado de satisfacción', slug:'satisfaccion', help:'1 corresponde al mínimo grado de satisfacción y 5 al máximo. Este preset usa RadioButtonsGroup.', options:rating5Options, required:true}],
            yesno: [{type:'radio', label:'¿Deseas continuar?', slug:'confirmacion', help:'Este preset usa RadioButtonsGroup.', options:yesNoOptions, required:true}],
            source: [{type:'radio', label:'¿Cómo te enteraste de nuestro evento?', slug:'medio_conocimiento', help:'', options:'correo|Recibí un correo electrónico\nllamada|Llamada de un agente comercial\nempresa|Por medio de la empresa\nweb|Página web\nrecomendacion|Recomendación\nredes|Redes sociales\nwhatsapp|WhatsApp', required:false}],
            comment: [{type:'textarea', label:'¿Qué fue lo que más te gustó y qué podríamos mejorar?', slug:'comentarios', help:'', options:'', required:false}],
            personal: [
                {type:'heading', label:'Queremos conocerte más', slug:'seccion_datos_personales', help:'', options:'', required:false},
                {type:'text', input_type:'text', label:'Nombres', slug:'nombres', help:'', options:'', required:true},
                {type:'text', input_type:'text', label:'Apellidos', slug:'apellidos', help:'', options:'', required:true},
                {type:'text', input_type:'text', label:'Nombre de la empresa', slug:'empresa', help:'', options:'', required:false},
                {type:'text', input_type:'email', label:'Correo electrónico', slug:'correo', help:'', options:'', required:false},
                {type:'text', input_type:'phone', label:'Celular', slug:'celular', help:'', options:'', required:false}
            ],
            consent: [{type:'optin', label:'Acepto el tratamiento de mis datos personales para fines relacionados con el evento.', slug:'acepta_tratamiento_datos', help:'Usa este campo para autorización expresa de datos personales.', options:'', required:true}],
            complete: [
                {type:'heading', label:'Valoración del evento', slug:'seccion_valoracion_evento', help:'', options:'', required:false},
                {type:'radio', label:'¿Qué tan probable es que recomiendes este espacio a un amigo o familiar?', slug:'probabilidad_recomendar', help:'0 es nada probable y 10 es muy probable. Usa RadioButtonsGroup.', options:npsOptions, required:true},
                {type:'radio', label:'¿Cómo te enteraste de nuestro evento?', slug:'medio_conocimiento', help:'', options:'correo|Recibí un correo electrónico\nllamada|Llamada de un agente comercial\nempresa|Por medio de la empresa\nweb|Página web\nrecomendacion|Recomendación\nredes|Redes sociales\nwhatsapp|WhatsApp', required:false},
                {type:'heading', label:'Temáticas del evento', slug:'seccion_tematicas', help:'', options:'', required:false},
                {type:'radio', label:'¿Los temas desarrollados cumplieron tus expectativas?', slug:'temas_cumplieron_expectativas', help:'1 corresponde al mínimo grado de satisfacción y 5 al máximo. Usa RadioButtonsGroup.', options:rating5Options, required:true},
                {type:'radio', label:'¿Contribuyeron en tu ocupación actual?', slug:'contribucion_ocupacion', help:'Usa RadioButtonsGroup.', options:rating5Options, required:true},
                {type:'heading', label:'Conferencista', slug:'seccion_conferencista', help:'', options:'', required:false},
                {type:'radio', label:'Despertaron y mantuvieron el interés', slug:'conferencista_interes', help:'Usa RadioButtonsGroup.', options:rating5Options, required:true},
                {type:'radio', label:'Expusieron información clara y concreta', slug:'conferencista_claridad', help:'Usa RadioButtonsGroup.', options:rating5Options, required:true},
                {type:'textarea', label:'¿Te gustaría complementar tus respuestas?', slug:'comentarios_conferencista', help:'', options:'', required:false},
                {type:'heading', label:'Facilidad para participar en el evento', slug:'seccion_facilidad', help:'', options:'', required:false},
                {type:'radio', label:'Inscripción al evento', slug:'facilidad_inscripcion', help:'1 es muy difícil y 5 es muy fácil. Usa RadioButtonsGroup.', options:rating5Options, required:true},
                {type:'radio', label:'Acceso al lugar del evento', slug:'facilidad_acceso', help:'Usa RadioButtonsGroup.', options:rating5Options, required:true},
                {type:'textarea', label:'¿Qué fue lo que más te gustó del evento?', slug:'lo_que_mas_gusto', help:'', options:'', required:false},
                {type:'textarea', label:'¿Qué aspectos podríamos mejorar?', slug:'aspectos_mejorar', help:'', options:'', required:false},
                {type:'radio', label:'¿Estarías dispuesto a pagar por formaciones como esta?', slug:'dispuesto_pagar', help:'Usa RadioButtonsGroup.', options:yesNoOptions, required:false},
                {type:'heading', label:'Queremos conocerte más', slug:'seccion_datos', help:'', options:'', required:false},
                {type:'text', input_type:'text', label:'Nombres', slug:'nombres', help:'', options:'', required:true},
                {type:'text', input_type:'text', label:'Apellidos', slug:'apellidos', help:'', options:'', required:true},
                {type:'text', input_type:'text', label:'Nombre de la empresa', slug:'empresa', help:'', options:'', required:false},
                {type:'text', input_type:'number', label:'NIT', slug:'nit', help:'Sin puntos ni dígito de verificación.', options:'', required:false},
                {type:'optin', label:'Acepto expresamente el tratamiento de mis datos personales.', slug:'acepta_datos', help:'Incluye aquí la autorización legal resumida o enlaza la política en el mensaje previo.', options:'', required:true}
            ]
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
                '<div class="evapp-row-3"><label class="evapp-field"><span>Componente WhatsApp Flow</span><select class="evapp-question-type" name="questions['+i+'][type]">'+typeOptions(type)+'</select></label><label class="evapp-field evapp-placeholder-wrap"><span>Placeholder opcional</span><input type="text" name="questions['+i+'][placeholder]" value="'+esc(data.placeholder || '')+'" placeholder="Ej: Escribe tu respuesta"></label><label class="evapp-field evapp-required-wrap"><span>Validación</span><label class="evapp-checkline"><input type="checkbox" name="questions['+i+'][required]" value="1" '+(required?'checked':'')+'> Obligatoria</label></label></div>'+ 
                '<div class="evapp-row evapp-text-input-type-wrap"><label class="evapp-field"><span>Formato de TextInput</span><select name="questions['+i+'][input_type]">'+inputTypeOptions(inputType)+'</select><span class="description">No crea otro componente: solo cambia el formato interno de TextInput.</span></label></div>'+ 
                '<label class="evapp-field"><span>Ayuda / instrucción opcional</span><textarea rows="2" name="questions['+i+'][help]" placeholder="Ej: 1 es el mínimo y 5 el máximo">'+esc(data.help || '')+'</textarea></label>'+ 
                '<div class="evapp-row evapp-text-limits-wrap"><label class="evapp-field"><span>Mínimo de caracteres</span><input type="number" name="questions['+i+'][min_chars]" value="0" min="0"></label><label class="evapp-field"><span>Máximo de caracteres</span><input type="number" name="questions['+i+'][max_chars]" value="0" min="0"></label></div>'+ 
                '<label class="evapp-field evapp-options-wrap"><span>Opciones, una por línea</span><textarea rows="5" name="questions['+i+'][options]" placeholder="Excelente&#10;Buena&#10;Regular&#10;Mala">'+esc(data.options || 'Opción 1\nOpción 2')+'</textarea><span class="description">También puedes usar id|Texto visible si necesitas controlar el valor interno. Para NPS usa opciones 0 a 10; para satisfacción usa 1 a 5.</span></label>'+ 
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
        var add = document.getElementById('evapp-wa-add-question');
        if(add){ add.addEventListener('click', function(){ var i=nextIndex(); wrap.insertAdjacentHTML('beforeend', questionTemplate(i, {type:'radio', label:'Nueva pregunta', slug:'pregunta_'+(i+1), options:'Opción 1\nOpción 2', required:true})); refreshAll(); }); }
        document.addEventListener('click', function(e){
            if(e.target && e.target.classList.contains('evapp-remove-question')){ var q=e.target.closest('.evapp-question'); if(q) q.remove(); }
            if(e.target && e.target.classList.contains('evapp-add-preset')){ var preset=e.target.getAttribute('data-preset'); var items=presetMap[preset] || []; items.forEach(function(item){ var i=nextIndex(); wrap.insertAdjacentHTML('beforeend', questionTemplate(i, item)); }); refreshAll(); }
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
