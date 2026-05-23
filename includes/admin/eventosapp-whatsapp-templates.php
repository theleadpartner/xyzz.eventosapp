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
 * URL pública frontal para acciones de ticket usadas desde WhatsApp.
 * No usa /wp-admin/admin-post.php para evitar que el enlace dependa de una sesión iniciada.
 */
function eventosapp_whatsapp_templates_public_action_url($action, $ticket_public = '{{1}}') {
    if ( function_exists('eventosapp_whatsapp_public_action_url') ) {
        return eventosapp_whatsapp_public_action_url($action, $ticket_public);
    }

    $action = sanitize_key((string) $action);
    if ( ! in_array($action, ['ticket_landing', 'ticket_ics', 'virtual_access'], true) ) {
        $action = 'ticket_landing';
    }

    $ticket_public = (string) $ticket_public;
    $slug = sanitize_title((string) apply_filters('eventosapp_whatsapp_public_ticket_page_slug', 'ticket'));
    $slug = $slug !== '' ? $slug : 'ticket';
    $base_url = trailingslashit(home_url('/' . $slug));

    $args = [
        'ticket' => $ticket_public,
    ];

    if ( $action !== 'ticket_landing' ) {
        $args = [
            'eventosapp_whatsapp_public_action' => $action,
            'ticket' => $ticket_public,
        ];
    }

    $url = add_query_arg($args, $base_url);

    return str_replace(['%7B%7B1%7D%7D', '%7b%7b1%7d%7d', rawurlencode('{{1}}')], '{{1}}', $url);
}

/**
 * URL pública específica para la landing de ticket.
 */
function eventosapp_whatsapp_templates_public_ticket_landing_url($ticket_public = '{{1}}') {
    return eventosapp_whatsapp_templates_public_action_url('ticket_landing', $ticket_public);
}

/**
 * URL base segura para botones de plantilla.
 */
function eventosapp_whatsapp_templates_button_url($action) {
    return eventosapp_whatsapp_templates_public_action_url($action, '{{1}}');
}

/**
 * Convierte URLs antiguas basadas en /wp-admin/admin-post.php a la ruta pública frontal.
 */
function eventosapp_whatsapp_templates_normalize_public_button_url($url) {
    $url = (string) $url;
    if ( $url === '' || strpos($url, 'admin-post.php') === false || strpos($url, 'eventosapp_whatsapp_') === false ) {
        return $url;
    }

    $legacy_map = [
        'eventosapp_whatsapp_ticket_landing' => 'ticket_landing',
        'eventosapp_whatsapp_ticket_ics'     => 'ticket_ics',
        'eventosapp_whatsapp_virtual_access' => 'virtual_access',
    ];

    foreach ( $legacy_map as $legacy_action => $public_action ) {
        if ( strpos($url, 'action=' . $legacy_action) !== false || strpos($url, 'action=' . rawurlencode($legacy_action)) !== false ) {
            $ticket_placeholder = strpos($url, 'ticket_demo_123') !== false ? 'ticket_demo_123' : '{{1}}';
            return eventosapp_whatsapp_templates_public_action_url($public_action, $ticket_placeholder);
        }
    }

    return $url;
}

/**
 * Ejemplo completo de URL para aprobación de Meta.
 */
function eventosapp_whatsapp_templates_button_example_url($action) {
    return str_replace('{{1}}', 'ticket_demo_123', eventosapp_whatsapp_templates_button_url($action));
}


/**
 * Normaliza el marcador permitido por Meta para botones URL.
 * En botones URL solo se admite {{1}} como variable técnica del botón. Las
 * variables del cuerpo, como {{9}}, no son válidas en el campo URL que se envía
 * a Meta; para abrir la plataforma virtual se usa el redirect público
 * virtual_access con el identificador del ticket.
 */
function eventosapp_whatsapp_templates_normalize_button_url_placeholder($url) {
    $url = trim((string) $url);
    $url = preg_replace('/[\r\n\t]+/', '', $url);
    $url = preg_replace('/\{\{\s*1\s*\}\}/', '{{1}}', $url);
    return (string) $url;
}

/**
 * Devuelve variables no permitidas encontradas en la URL de un botón.
 */
function eventosapp_whatsapp_templates_button_url_unsupported_variables($url) {
    $url = (string) $url;
    $unsupported = [];

    if ( preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $url, $matches) ) {
        foreach ( (array) ($matches[1] ?? []) as $variable_number ) {
            $variable_number = absint($variable_number);
            if ( $variable_number > 0 && $variable_number !== 1 && ! in_array($variable_number, $unsupported, true) ) {
                $unsupported[] = $variable_number;
            }
        }
    }

    return $unsupported;
}

/**
 * Detecta el caso común que causa el error de Meta: usar {{9}} como URL del botón.
 * {{9}} se mantiene disponible para el BODY, pero el botón debe apuntar al
 * endpoint público de EventosApp con {{1}} para que Meta reciba una URI válida.
 */
function eventosapp_whatsapp_templates_button_url_requests_virtual_redirect($url) {
    $url = trim((string) $url);
    if ( $url === '' ) {
        return false;
    }

    if ( preg_match('/^\{\{\s*9\s*\}\}$/', $url) || preg_match('/\{\{\s*9\s*\}\}/', $url) ) {
        return true;
    }

    $key = sanitize_key($url);
    return in_array($key, [
        'virtual_access',
        'acceso_virtual',
        'platform_url',
        'platform_direct_url',
        'plataforma_directa',
        'enlace_plataforma',
    ], true);
}

/**
 * Permite escribir acciones cortas en vez de pegar toda la URL pública.
 */
function eventosapp_whatsapp_templates_button_action_from_keyword($url) {
    $key = sanitize_key((string) $url);
    $map = [
        'ticket_landing'   => 'ticket_landing',
        'landing_ticket'   => 'ticket_landing',
        'ver_ticket'       => 'ticket_landing',
        'ticket_ics'       => 'ticket_ics',
        'ics'              => 'ticket_ics',
        'agenda'           => 'ticket_ics',
        'agregar_agenda'   => 'ticket_ics',
        'virtual_access'   => 'virtual_access',
        'acceso_virtual'   => 'virtual_access',
        'platform_url'     => 'virtual_access',
        'platform_direct_url' => 'virtual_access',
        'plataforma_directa'  => 'virtual_access',
        'enlace_plataforma'   => 'virtual_access',
    ];

    return $map[$key] ?? '';
}

/**
 * Normaliza la URL del botón antes de guardarla o enviarla a Meta.
 */
function eventosapp_whatsapp_templates_normalize_button_url_for_storage($url, $modality = '', $button_number = 1) {
    $url = eventosapp_whatsapp_templates_normalize_button_url_placeholder($url);
    if ( $url === '' ) {
        return '';
    }

    $action = eventosapp_whatsapp_templates_button_action_from_keyword($url);
    if ( $action !== '' ) {
        return eventosapp_whatsapp_templates_button_url($action);
    }

    if ( eventosapp_whatsapp_templates_button_url_requests_virtual_redirect($url) ) {
        return eventosapp_whatsapp_templates_button_url('virtual_access');
    }

    return eventosapp_whatsapp_templates_sanitize_url_template($url);
}

/**
 * Construye una URL de ejemplo completa para el botón dinámico.
 */
function eventosapp_whatsapp_templates_button_example_from_url($url) {
    $url = eventosapp_whatsapp_templates_normalize_button_url_placeholder($url);
    if ( $url === '' ) {
        return '';
    }

    if ( strpos($url, '{{1}}') !== false ) {
        $url = str_replace('{{1}}', 'ticket_demo_123', $url);
    }

    return esc_url_raw($url);
}

/**
 * Normaliza la URL de ejemplo del botón. Si falta o quedó inválida, la genera
 * desde la URL dinámica usando ticket_demo_123.
 */
function eventosapp_whatsapp_templates_normalize_button_example_for_storage($example, $url) {
    $url = eventosapp_whatsapp_templates_normalize_button_url_placeholder($url);
    $example = trim((string) $example);
    $example = preg_replace('/[\r\n\t]+/', '', $example);
    $example = preg_replace('/\{\{\s*1\s*\}\}/', 'ticket_demo_123', $example);

    if ( $example !== '' ) {
        $example = esc_url_raw($example);
    }

    if ( strpos($url, '{{1}}') !== false ) {
        $generated = eventosapp_whatsapp_templates_button_example_from_url($url);
        if ( $example === '' || ! eventosapp_whatsapp_templates_is_absolute_http_url($example) ) {
            return $generated;
        }
    }

    return $example;
}

/**
 * Valida una URL http/https absoluta con criterio estricto para evitar errores
 * de Meta del tipo "buttons[0][url] is not a valid URI".
 */
function eventosapp_whatsapp_templates_is_absolute_http_url($url) {
    $url = trim((string) $url);
    if ( $url === '' || ! preg_match('/^https?:\/\//i', $url) ) {
        return false;
    }

    return (bool) filter_var($url, FILTER_VALIDATE_URL);
}

/**
 * Verifica si la URL de botón quedará como URI válida para Meta después de
 * sustituir {{1}} por un identificador de muestra.
 */
function eventosapp_whatsapp_templates_button_url_is_valid_for_meta($url) {
    $example = eventosapp_whatsapp_templates_button_example_from_url($url);
    return eventosapp_whatsapp_templates_is_absolute_http_url($example);
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
            'header_sample_file_name' => '',
            'header_sample_file_type' => '',
            'header_sample_file_size' => '',
            'header_sample_uploaded_at' => '',
            'body_text'             => "🎟️ Hola {{1}}, tu inscripción a *{{2}}* está confirmada.\n\n✅ Presenta este QR en el ingreso al evento.\n\n📌 *Detalles de tu inscripción:*\n\n🎫 *Evento:* {{2}}\n📅 *Fecha:* {{3}}\n🕒 *Hora:* {{4}}\n📍 *Lugar:* {{5}}\n👥 *Modalidad:* {{8}}\n🏢 *Organizador:* {{7}}\n\n🔗 Ingresa a tu 'Ticket' para ver:\n\n🍎 Ticket para *Apple Wallet*\n💳 Ticket para *Google Wallet*\n📄 Ticket descargable en *PDF*\n📆 Recordatorio para agregar a tu agenda\n\n✨ Te esperamos.",
            'body_examples'         => "María Pérez\nEvento Demo\n20 de mayo de 2026\n8:00 a. m.\nCentro de Convenciones\nhttps://demo.eventosapp.com/ticket_demo_123\nEventosApp\nPresencial",
            'footer_text'           => 'EventosApp',
            'button_1_enabled'      => '1',
            'button_1_text'         => 'Ver mi ticket',
            'button_1_url'          => eventosapp_whatsapp_templates_button_url('ticket_landing'),
            'button_1_example'      => eventosapp_whatsapp_templates_button_example_url('ticket_landing'),
            'button_2_enabled'      => '1',
            'button_2_text'         => 'Agregar a agenda',
            'button_2_url'          => eventosapp_whatsapp_templates_button_url('ticket_ics'),
            'button_2_example'      => eventosapp_whatsapp_templates_button_example_url('ticket_ics'),
            'sender_phone_number_id' => '',
            'sender_phone_label'    => 'Número por defecto',
            'waba_id'               => '',
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
            'header_format'         => 'IMAGE',
            'header_text'           => '',
            'header_sample_handle'  => '',
            'header_sample_file_name' => '',
            'header_sample_file_type' => '',
            'header_sample_file_size' => '',
            'header_sample_uploaded_at' => '',
            'body_text'             => "🎟️ Hola {{1}}, tu inscripción a *{{2}}* está confirmada.\n\n✅ Conserva este mensaje para consultar tu acceso al evento.\n\n📌 *Detalles de tu inscripción:*\n\n🎫 *Evento:* {{2}}\n📅 *Fecha:* {{3}}\n🕒 *Hora:* {{4}}\n💻 *Plataforma:* {{5}}\n👥 *Modalidad:* {{8}}\n🏢 *Organizador:* {{7}}\n\n🔗 Ingresa a tu 'Ticket' para ver:\n\n🍎 Ticket para *Apple Wallet*\n💳 Ticket para *Google Wallet*\n📄 Ticket descargable en *PDF*\n📆 Recordatorio para agregar a tu agenda\n\n✨ Te esperamos.",
            'body_examples'         => "María Pérez\nEvento Demo Virtual\n20 de mayo de 2026\n8:00 a. m.\nZoom\nhttps://demo.eventosapp.com/acceso_virtual\nEventosApp\nVirtual",
            'footer_text'           => 'EventosApp',
            'button_1_enabled'      => '1',
            'button_1_text'         => 'Ingresar al evento',
            'button_1_url'          => eventosapp_whatsapp_templates_button_url('virtual_access'),
            'button_1_example'      => eventosapp_whatsapp_templates_button_example_url('virtual_access'),
            'button_2_enabled'      => '1',
            'button_2_text'         => 'Agregar a agenda',
            'button_2_url'          => eventosapp_whatsapp_templates_button_url('ticket_ics'),
            'button_2_example'      => eventosapp_whatsapp_templates_button_example_url('ticket_ics'),
            'sender_phone_number_id' => '',
            'sender_phone_label'    => 'Número por defecto',
            'waba_id'               => '',
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
        'app_id'       => '',
        'default_qr_header_image' => '',
        'default_virtual_message_image' => '',
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

            // Migración segura: solo actualiza la estructura visual/textual de las plantillas base
            // cuando siguen en estado local. No toca plantillas ya aprobadas/en revisión para no
            // desincronizar lo que Meta tiene aprobado con los parámetros que se envían en runtime.
            $current_status = strtoupper((string)($settings['templates'][$default_id]['meta_status'] ?? 'LOCAL'));
            if ( ! empty($settings['templates'][$default_id]['is_default']) && $settings['templates'][$default_id]['is_default'] === '1' && in_array($current_status, ['', 'LOCAL'], true) ) {
                foreach ( ['body_text', 'body_examples', 'header_format', 'footer_text', 'button_1_enabled', 'button_1_text', 'button_1_url', 'button_1_example', 'button_2_enabled', 'button_2_text', 'button_2_url', 'button_2_example'] as $migrated_field ) {
                    $settings['templates'][$default_id][$migrated_field] = $default_template[$migrated_field];
                }
                $changed = true;
            }
        }
    }

    foreach ( $settings['templates'] as $template_id => $template ) {
        if ( ! is_array($template) ) {
            continue;
        }

        foreach ( [1, 2] as $button_number ) {
            $url_key = 'button_' . $button_number . '_url';
            $example_key = 'button_' . $button_number . '_example';

            $current_url = (string)($template[$url_key] ?? '');
            $normalized_url = eventosapp_whatsapp_templates_normalize_public_button_url($current_url);
            $normalized_url = eventosapp_whatsapp_templates_normalize_button_url_for_storage($normalized_url, $template['modality'] ?? '', $button_number);
            if ( $normalized_url !== $current_url ) {
                $settings['templates'][$template_id][$url_key] = $normalized_url;
                $changed = true;
            }

            $current_example = (string)($template[$example_key] ?? '');
            $normalized_example = eventosapp_whatsapp_templates_normalize_public_button_url($current_example);
            $normalized_example = eventosapp_whatsapp_templates_normalize_button_example_for_storage($normalized_example, $normalized_url);
            if ( $normalized_example !== $current_example ) {
                $settings['templates'][$template_id][$example_key] = $normalized_example;
                $changed = true;
            }
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
 * Sanitiza IDs numéricos de WhatsApp Business Account.
 */
function eventosapp_whatsapp_templates_sanitize_waba_id($value) {
    if ( function_exists('eventosapp_whatsapp_sanitize_waba_id') ) {
        return eventosapp_whatsapp_sanitize_waba_id($value);
    }
    return preg_replace('/\D+/', '', (string) $value);
}

/**
 * Sanitiza Phone Number ID usando el helper principal cuando está disponible.
 */
function eventosapp_whatsapp_templates_sanitize_phone_number_id($value) {
    if ( function_exists('eventosapp_whatsapp_sanitize_phone_number_id') ) {
        return eventosapp_whatsapp_sanitize_phone_number_id($value);
    }
    return preg_replace('/\D+/', '', (string) $value);
}

/**
 * Obtiene las cuentas/números configurados en WhatsApp Tickets.
 */
function eventosapp_whatsapp_templates_get_phone_accounts() {
    if ( function_exists('eventosapp_whatsapp_get_settings') && function_exists('eventosapp_whatsapp_get_phone_accounts') ) {
        return eventosapp_whatsapp_get_phone_accounts(eventosapp_whatsapp_get_settings());
    }
    return [];
}

/**
 * Devuelve el Phone Number ID por defecto de WhatsApp Tickets.
 */
function eventosapp_whatsapp_templates_get_default_phone_number_id() {
    if ( function_exists('eventosapp_whatsapp_get_settings') ) {
        $wa_settings = eventosapp_whatsapp_get_settings();
        return eventosapp_whatsapp_templates_sanitize_phone_number_id($wa_settings['phone_number_id'] ?? '');
    }
    return '';
}

/**
 * Devuelve datos completos del número/cuenta seleccionada para una plantilla.
 * Si la plantilla no tiene número explícito, se interpreta como número por defecto
 * para mantener compatibilidad con las plantillas aprobadas antes de agregar multi-número.
 */
function eventosapp_whatsapp_templates_resolve_sender_account($phone_number_id = '', $template_settings = null) {
    $template_settings = is_array($template_settings) ? $template_settings : eventosapp_whatsapp_templates_get_settings();
    $accounts = eventosapp_whatsapp_templates_get_phone_accounts();
    $phone_number_id = eventosapp_whatsapp_templates_sanitize_phone_number_id($phone_number_id);
    $default_phone_number_id = eventosapp_whatsapp_templates_get_default_phone_number_id();

    if ( $phone_number_id === '' ) {
        $phone_number_id = $default_phone_number_id;
    }

    $is_default_sender = ($phone_number_id === '' || $phone_number_id === $default_phone_number_id);

    if ( $phone_number_id !== '' && isset($accounts[$phone_number_id]) && is_array($accounts[$phone_number_id]) ) {
        $account = $accounts[$phone_number_id];
        $is_default_sender = $is_default_sender || ! empty($account['is_default']);

        return [
            'phone_number_id' => $phone_number_id,
            'alias'           => sanitize_text_field((string)($account['alias'] ?? 'Número WhatsApp')),
            'label'           => sanitize_text_field((string)($account['label'] ?? (($account['alias'] ?? 'Número WhatsApp') . ' — ' . $phone_number_id))),
            'waba_id'         => $is_default_sender ? eventosapp_whatsapp_templates_sanitize_waba_id($template_settings['waba_id'] ?? '') : '',
            'is_default'      => $is_default_sender,
        ];
    }

    return [
        'phone_number_id' => $phone_number_id,
        'alias'           => $phone_number_id !== '' ? 'Número no disponible' : 'Número por defecto',
        'label'           => $phone_number_id !== '' ? 'Número no disponible — ' . $phone_number_id : 'Número por defecto',
        'waba_id'         => $is_default_sender ? eventosapp_whatsapp_templates_sanitize_waba_id($template_settings['waba_id'] ?? '') : '',
        'is_default'      => $is_default_sender,
    ];
}

/**
 * WABA efectivo de una plantilla según el número marcado.
 */
function eventosapp_whatsapp_templates_get_template_waba_id($template, $template_settings = null) {
    $template = is_array($template) ? $template : [];
    $template_settings = is_array($template_settings) ? $template_settings : eventosapp_whatsapp_templates_get_settings();
    $sender_phone = eventosapp_whatsapp_templates_sanitize_phone_number_id($template['sender_phone_number_id'] ?? '');
    $account = eventosapp_whatsapp_templates_resolve_sender_account($sender_phone, $template_settings);

    if ( ! empty($account['is_default']) ) {
        $default_waba_id = eventosapp_whatsapp_templates_sanitize_waba_id($template_settings['waba_id'] ?? '');
        return $default_waba_id !== '' ? $default_waba_id : eventosapp_whatsapp_templates_sanitize_waba_id($template['waba_id'] ?? '');
    }

    // Para números distintos al principal, el WABA pertenece a la plantilla.
    // Esto evita que la aprobación se envíe por error al WABA global del número por defecto.
    return eventosapp_whatsapp_templates_sanitize_waba_id($template['waba_id'] ?? '');
}

/**
 * Etiqueta administrativa del número al que pertenece una plantilla.
 */
function eventosapp_whatsapp_templates_get_template_sender_label($template, $template_settings = null) {
    $template = is_array($template) ? $template : [];
    $template_settings = is_array($template_settings) ? $template_settings : eventosapp_whatsapp_templates_get_settings();
    $sender_phone = eventosapp_whatsapp_templates_sanitize_phone_number_id($template['sender_phone_number_id'] ?? '');
    $account = eventosapp_whatsapp_templates_resolve_sender_account($sender_phone, $template_settings);
    return sanitize_text_field((string)($account['label'] ?? 'Número por defecto'));
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
    $url = eventosapp_whatsapp_templates_normalize_button_url_placeholder($url);
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
 * Sanitiza el Header Sample Handle generado por Meta.
 *
 * Este valor no es una URL pública. Meta lo devuelve después de subir
 * una imagen de muestra con Resumable Upload API y suele venir como una
 * cadena larga con prefijos internos, por ejemplo "4::...".
 */
function eventosapp_whatsapp_templates_sanitize_header_handle($handle) {
    $handle = trim((string) $handle);
    if ( $handle === '' ) {
        return '';
    }

    $handle = wp_strip_all_tags($handle);
    $handle = preg_replace('/[\r\n\t]+/', '', $handle);
    $handle = trim($handle);

    return $handle;
}

/**
 * Extrae las variables numéricas del cuerpo en el orden real en que aparecen.
 * Si una variable se repite, conserva una sola entrada para que el valor se
 * reutilice correctamente en Meta y en el envío runtime.
 */
function eventosapp_whatsapp_templates_extract_body_variable_numbers($body_text) {
    $numbers = [];

    if ( preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $body_text, $matches) ) {
        foreach ( (array) $matches[1] as $number ) {
            $number = absint($number);
            if ( $number < 1 ) {
                continue;
            }
            if ( ! in_array($number, $numbers, true) ) {
                $numbers[] = $number;
            }
        }
    }

    return $numbers;
}

/**
 * Convierte el textarea de ejemplos en un arreglo indexado por número de variable.
 * La línea 1 corresponde a {{1}}, la línea 2 a {{2}}, etc. Esto permite que el
 * usuario elimine {{6}} del cuerpo y siga usando {{7}} / {{8}} sin romper el
 * ejemplo que Meta exige para el componente BODY.
 */
function eventosapp_whatsapp_templates_parse_body_examples_by_number($examples_text) {
    $lines = preg_split('/\r\n|\r|\n/', (string) $examples_text);
    $examples = [];
    $index = 1;

    foreach ( (array) $lines as $line ) {
        $line = sanitize_text_field($line);
        if ( $line !== '' ) {
            $examples[$index] = $line;
        }
        $index++;
    }

    return $examples;
}

/**
 * Valor de muestra seguro para cada variable estándar de EventosApp.
 */
function eventosapp_whatsapp_templates_body_example_fallback($variable_number) {
    $fallback = [
        1 => 'María Pérez',
        2 => 'Evento Demo',
        3 => '20 de mayo de 2026',
        4 => '8:00 a. m.',
        5 => 'Centro de Convenciones',
        6 => eventosapp_whatsapp_templates_button_example_url('ticket_landing'),
        7 => 'EventosApp',
        8 => 'Presencial',
        9 => 'https://demo.eventosapp.com/plataforma-virtual',
    ];

    $variable_number = absint($variable_number);
    return $fallback[$variable_number] ?? ('Ejemplo ' . $variable_number);
}

/**
 * Determina si un botón debe incluirse en la plantilla enviada a Meta.
 * Las plantillas antiguas sin este campo se consideran activas para mantener
 * compatibilidad con estructuras aprobadas previamente.
 */
function eventosapp_whatsapp_templates_button_enabled($template, $button_number) {
    $template = is_array($template) ? $template : [];
    $button_number = absint($button_number);
    $key = 'button_' . $button_number . '_enabled';

    if ( ! array_key_exists($key, $template) ) {
        return true;
    }

    $raw = strtolower(trim((string) $template[$key]));
    return ! in_array($raw, ['0', 'no', 'false', 'off'], true);
}

/**
 * Normaliza el valor de activación del botón desde POST o desde opciones antiguas.
 */
function eventosapp_whatsapp_templates_normalize_button_enabled($raw, $existing, $button_number) {
    $raw = is_array($raw) ? $raw : [];
    $existing = is_array($existing) ? $existing : [];
    $button_number = absint($button_number);
    $key = 'button_' . $button_number . '_enabled';

    if ( array_key_exists($key, $raw) ) {
        $value = strtolower(trim((string) $raw[$key]));
        return in_array($value, ['1', 'yes', 'true', 'on'], true) ? '1' : '0';
    }

    if ( array_key_exists($key, $existing) ) {
        return eventosapp_whatsapp_templates_button_enabled($existing, $button_number) ? '1' : '0';
    }

    $text = trim((string)($existing['button_' . $button_number . '_text'] ?? ''));
    $url = trim((string)($existing['button_' . $button_number . '_url'] ?? ''));
    return ($text !== '' || $url !== '') ? '1' : '0';
}

/**
 * Prepara el cuerpo para Meta.
 *
 * Meta es muy sensible al ejemplo del BODY cuando existen variables. Para evitar
 * rechazos cuando el usuario edita el texto, elimina {{6}} o reordena variables,
 * EventosApp envía a Meta una versión normalizada con variables consecutivas
 * {{1}}, {{2}}, {{3}}..., pero conserva un mapa entre esas posiciones y las
 * variables reales de EventosApp.
 */
function eventosapp_whatsapp_templates_prepare_body_for_meta($body_text, $examples_text = '') {
    $body_text = (string) $body_text;
    $variable_numbers = eventosapp_whatsapp_templates_extract_body_variable_numbers($body_text);
    $examples_by_number = eventosapp_whatsapp_templates_parse_body_examples_by_number($examples_text);

    if ( empty($variable_numbers) ) {
        return [
            'text' => $body_text,
            'variable_numbers' => [],
            'example_values' => [],
            'signature' => md5($body_text),
        ];
    }

    $local_to_meta = [];
    foreach ( $variable_numbers as $index => $local_number ) {
        $local_to_meta[$local_number] = $index + 1;
    }

    $normalized_text = preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', function($match) use ($local_to_meta) {
        $local_number = absint($match[1] ?? 0);
        if ( ! isset($local_to_meta[$local_number]) ) {
            return $match[0];
        }
        return '{{' . $local_to_meta[$local_number] . '}}';
    }, $body_text);

    $example_values = [];
    foreach ( $variable_numbers as $local_number ) {
        $example = $examples_by_number[$local_number] ?? '';
        if ( $example === '' ) {
            $example = eventosapp_whatsapp_templates_body_example_fallback($local_number);
        }
        $example_values[] = sanitize_text_field($example);
    }

    return [
        'text' => (string) $normalized_text,
        'variable_numbers' => array_values(array_map('absint', $variable_numbers)),
        'example_values' => $example_values,
        'signature' => md5($body_text),
    ];
}

/**
 * Sanitiza y normaliza un mapa de variables guardado en la plantilla.
 */
function eventosapp_whatsapp_templates_sanitize_body_variable_map($map) {
    $normalized = [];

    if ( is_string($map) ) {
        $decoded = json_decode($map, true);
        if ( is_array($decoded) ) {
            $map = $decoded;
        }
    }

    if ( is_array($map) ) {
        foreach ( $map as $number ) {
            $number = absint($number);
            if ( $number > 0 && ! in_array($number, $normalized, true) ) {
                $normalized[] = $number;
            }
        }
    }

    return $normalized;
}

/**
 * Normaliza ejemplos del body a una fila compatible con Meta.
 */
function eventosapp_whatsapp_templates_body_examples_to_array($body_text, $examples_text) {
    $prepared = eventosapp_whatsapp_templates_prepare_body_for_meta($body_text, $examples_text);
    return $prepared['example_values'] ?? [];
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

    $template_settings = eventosapp_whatsapp_templates_get_settings();
    $requested_sender_phone = array_key_exists('sender_phone_number_id', $raw)
        ? eventosapp_whatsapp_templates_sanitize_phone_number_id($raw['sender_phone_number_id'])
        : eventosapp_whatsapp_templates_sanitize_phone_number_id($existing['sender_phone_number_id'] ?? '');
    $sender_account = eventosapp_whatsapp_templates_resolve_sender_account($requested_sender_phone, $template_settings);
    $effective_sender_phone = eventosapp_whatsapp_templates_sanitize_phone_number_id($sender_account['phone_number_id'] ?? $requested_sender_phone);
    $existing_effective_sender_phone = eventosapp_whatsapp_templates_sanitize_phone_number_id($existing['sender_phone_number_id'] ?? '');
    if ( $existing_effective_sender_phone === '' ) {
        $existing_effective_sender_phone = eventosapp_whatsapp_templates_get_default_phone_number_id();
    }

    $sender_changed = ! empty($existing) && $effective_sender_phone !== '' && $existing_effective_sender_phone !== '' && $effective_sender_phone !== $existing_effective_sender_phone;
    $posted_waba_id = array_key_exists('waba_id', $raw)
        ? eventosapp_whatsapp_templates_sanitize_waba_id($raw['waba_id'])
        : eventosapp_whatsapp_templates_sanitize_waba_id($existing['waba_id'] ?? '');

    if ( ! empty($sender_account['is_default']) ) {
        $effective_waba_id = eventosapp_whatsapp_templates_sanitize_waba_id($template_settings['waba_id'] ?? '');
    } else {
        $effective_waba_id = $posted_waba_id;
    }

    $existing_waba_id = eventosapp_whatsapp_templates_sanitize_waba_id($existing['waba_id'] ?? '');
    $remote_waba_changed = ! empty($existing)
        && $effective_waba_id !== ''
        && $existing_waba_id !== ''
        && $effective_waba_id !== $existing_waba_id
        && ( ! empty($existing['meta_template_id']) || strtoupper((string)($existing['meta_status'] ?? 'LOCAL')) !== 'LOCAL' );
    $remote_context_changed = $sender_changed || $remote_waba_changed;

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
        'header_sample_handle' => eventosapp_whatsapp_templates_sanitize_header_handle($raw['header_sample_handle'] ?? ($existing['header_sample_handle'] ?? '')),
        'header_sample_file_name' => sanitize_file_name($existing['header_sample_file_name'] ?? ''),
        'header_sample_file_type' => sanitize_mime_type($existing['header_sample_file_type'] ?? ''),
        'header_sample_file_size' => absint($existing['header_sample_file_size'] ?? 0),
        'header_sample_uploaded_at' => sanitize_text_field($existing['header_sample_uploaded_at'] ?? ''),
        'body_text'            => sanitize_textarea_field($raw['body_text'] ?? ($existing['body_text'] ?? '')),
        'body_examples'        => sanitize_textarea_field($raw['body_examples'] ?? ($existing['body_examples'] ?? '')),
        'body_text_meta'       => sanitize_textarea_field($existing['body_text_meta'] ?? ''),
        'body_variable_map'    => eventosapp_whatsapp_templates_sanitize_body_variable_map($existing['body_variable_map'] ?? []),
        'body_variable_signature' => sanitize_text_field($existing['body_variable_signature'] ?? ''),
        'footer_text'          => sanitize_text_field($raw['footer_text'] ?? ($existing['footer_text'] ?? '')),
        'button_1_enabled'     => eventosapp_whatsapp_templates_normalize_button_enabled($raw, $existing, 1),
        'button_1_text'        => sanitize_text_field($raw['button_1_text'] ?? ($existing['button_1_text'] ?? '')),
        'button_1_url'         => eventosapp_whatsapp_templates_normalize_button_url_for_storage($raw['button_1_url'] ?? ($existing['button_1_url'] ?? ''), $modality, 1),
        'button_1_example'     => eventosapp_whatsapp_templates_normalize_button_example_for_storage($raw['button_1_example'] ?? ($existing['button_1_example'] ?? ''), $raw['button_1_url'] ?? ($existing['button_1_url'] ?? '')),
        'button_2_enabled'     => eventosapp_whatsapp_templates_normalize_button_enabled($raw, $existing, 2),
        'button_2_text'        => sanitize_text_field($raw['button_2_text'] ?? ($existing['button_2_text'] ?? '')),
        'button_2_url'         => eventosapp_whatsapp_templates_normalize_button_url_for_storage($raw['button_2_url'] ?? ($existing['button_2_url'] ?? ''), $modality, 2),
        'button_2_example'     => eventosapp_whatsapp_templates_normalize_button_example_for_storage($raw['button_2_example'] ?? ($existing['button_2_example'] ?? ''), $raw['button_2_url'] ?? ($existing['button_2_url'] ?? '')),
        'sender_phone_number_id' => $effective_sender_phone,
        'sender_phone_label'   => sanitize_text_field((string)($sender_account['alias'] ?? ($sender_account['label'] ?? 'Número WhatsApp'))),
        'waba_id'              => $effective_waba_id,
        'meta_template_id'     => $remote_context_changed ? '' : sanitize_text_field($existing['meta_template_id'] ?? ''),
        'meta_status'          => $remote_context_changed ? 'LOCAL' : sanitize_text_field($existing['meta_status'] ?? 'LOCAL'),
        'meta_category'        => sanitize_text_field($existing['meta_category'] ?? ''),
        'meta_rejected_reason' => $remote_context_changed ? '' : sanitize_text_field($existing['meta_rejected_reason'] ?? ''),
        'last_api_message'     => $remote_context_changed ? 'Número emisor o WABA cambiado. Debes enviar esta plantilla nuevamente a Meta para el WABA correspondiente.' : sanitize_text_field($existing['last_api_message'] ?? ''),
        'last_api_response'    => $remote_context_changed ? [] : (isset($existing['last_api_response']) && is_array($existing['last_api_response']) ? $existing['last_api_response'] : []),
        'last_submitted_at'    => $remote_context_changed ? '' : sanitize_text_field($existing['last_submitted_at'] ?? ''),
        'last_checked_at'      => $remote_context_changed ? '' : sanitize_text_field($existing['last_checked_at'] ?? ''),
        'created_at'           => sanitize_text_field($existing['created_at'] ?? current_time('mysql')),
        'updated_at'           => current_time('mysql'),
    ], []);

    $remote_template_exists = ! empty($existing['meta_template_id']) || ! in_array(strtoupper((string)($existing['meta_status'] ?? 'LOCAL')), ['', 'LOCAL'], true);
    if ( $remote_template_exists && ! $remote_context_changed ) {
        $structure_fields = [
            'name',
            'language',
            'category',
            'modality',
            'header_format',
            'header_text',
            'header_sample_handle',
            'body_text',
            'body_examples',
            'footer_text',
            'button_1_enabled',
            'button_1_text',
            'button_1_url',
            'button_1_example',
            'button_2_enabled',
            'button_2_text',
            'button_2_url',
            'button_2_example',
        ];

        $structure_changed = false;
        foreach ( $structure_fields as $field ) {
            if ( $field === 'button_1_enabled' ) {
                $old_value = eventosapp_whatsapp_templates_normalize_button_enabled([], $existing, 1);
            } elseif ( $field === 'button_2_enabled' ) {
                $old_value = eventosapp_whatsapp_templates_normalize_button_enabled([], $existing, 2);
            } else {
                $old_value = (string)($existing[$field] ?? '');
            }

            $new_value = (string)($template[$field] ?? '');
            if ( $old_value !== $new_value ) {
                $structure_changed = true;
                break;
            }
        }

        if ( $structure_changed ) {
            $template['meta_status'] = 'LOCAL';
            $template['meta_rejected_reason'] = '';
            $template['last_api_message'] = 'La estructura local de la plantilla cambió. Envíala nuevamente a Meta antes de usarla en envíos reales.';
            $template['last_api_response'] = [];
            $template['last_submitted_at'] = '';
            $template['last_checked_at'] = '';
        }
    }

    if ( $template['name'] === '' ) {
        $template['name'] = 'eventosapp_template_' . substr(md5($id), 0, 8);
    }

    if ( $template['title'] === '' ) {
        $template['title'] = $template['name'];
    }

    $template['button_1_example'] = eventosapp_whatsapp_templates_normalize_button_example_for_storage($template['button_1_example'] ?? '', $template['button_1_url'] ?? '');
    $template['button_2_example'] = eventosapp_whatsapp_templates_normalize_button_example_for_storage($template['button_2_example'] ?? '', $template['button_2_url'] ?? '');

    $prepared_body = eventosapp_whatsapp_templates_prepare_body_for_meta($template['body_text'], $template['body_examples']);
    $template['body_text_meta'] = sanitize_textarea_field($prepared_body['text'] ?? $template['body_text']);
    $template['body_variable_map'] = eventosapp_whatsapp_templates_sanitize_body_variable_map($prepared_body['variable_numbers'] ?? []);
    $template['body_variable_signature'] = sanitize_text_field($prepared_body['signature'] ?? md5((string)$template['body_text']));

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
    } else {
        $body_variables = eventosapp_whatsapp_templates_extract_body_variable_numbers($template['body_text']);
        if ( count($body_variables) > 20 ) {
            $errors[] = 'El cuerpo de la plantilla tiene demasiadas variables. Usa máximo 20 variables para mantener compatibilidad con Meta.';
        }
    }

    if ( ! empty($template['header_format']) && $template['header_format'] === 'IMAGE' ) {
        if ( empty($template['header_sample_handle']) ) {
            $errors[] = 'La plantilla usa encabezado de imagen. Para enviarla a Meta debes subir una imagen de muestra para generar el Header Sample Handle, o pegar un handle válido generado por Meta.';
        } elseif ( preg_match('/^https?:\/\//i', (string) $template['header_sample_handle']) ) {
            $errors[] = 'El Header Sample Handle no puede ser una URL pública. Debe ser el handle que Meta devuelve después de subir la imagen de muestra con Resumable Upload API.';
        }
    }

    $buttons = 0;
    foreach ( [1, 2] as $i ) {
        if ( ! eventosapp_whatsapp_templates_button_enabled($template, $i) ) {
            continue;
        }

        $text = trim((string)($template['button_' . $i . '_text'] ?? ''));
        $url  = trim((string)($template['button_' . $i . '_url'] ?? ''));
        if ( $text !== '' || $url !== '' ) {
            if ( $text === '' || $url === '' ) {
                $errors[] = 'Cada botón debe tener texto y URL.';
            }

            $unsupported_variables = eventosapp_whatsapp_templates_button_url_unsupported_variables($url);
            if ( ! empty($unsupported_variables) ) {
                $errors[] = 'La URL del botón ' . $i . ' solo puede usar la variable técnica {{1}} del ticket. No uses {{' . implode('}}, {{', array_map('absint', $unsupported_variables)) . '}} en botones; esa variable es para el cuerpo del mensaje. Para abrir la plataforma virtual, usa la URL pública de acceso virtual de EventosApp.';
            }

            if ( substr_count($url, '{{1}}') > 1 ) {
                $errors[] = 'Cada botón URL solo puede usar una variable dinámica {{1}}.';
            }

            if ( $url !== '' && ! eventosapp_whatsapp_templates_button_url_is_valid_for_meta($url) ) {
                $errors[] = 'La URL del botón ' . $i . ' debe ser una URI absoluta válida con http:// o https://. Para enlaces virtuales directos no uses {{9}} como URL del botón; usa el endpoint público virtual_access de EventosApp.';
            }

            if ( strpos($url, '{{1}}') !== false ) {
                $example = eventosapp_whatsapp_templates_normalize_button_example_for_storage($template['button_' . $i . '_example'] ?? '', $url);
                if ( $example === '' || ! eventosapp_whatsapp_templates_is_absolute_http_url($example) ) {
                    $errors[] = 'Cada botón con URL dinámica debe tener una URL de ejemplo completa y válida.';
                }
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

    $prepared_body = eventosapp_whatsapp_templates_prepare_body_for_meta($template['body_text'] ?? '', $template['body_examples'] ?? '');
    $body_component = [
        'type' => 'BODY',
        'text' => $prepared_body['text'] ?? ($template['body_text'] ?? ''),
    ];

    $body_examples = $prepared_body['example_values'] ?? [];
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
        if ( ! eventosapp_whatsapp_templates_button_enabled($template, $i) ) {
            continue;
        }

        $text = trim((string)($template['button_' . $i . '_text'] ?? ''));
        $url  = eventosapp_whatsapp_templates_normalize_button_url_for_storage($template['button_' . $i . '_url'] ?? '', $template['modality'] ?? '', $i);
        if ( $text === '' || $url === '' ) {
            continue;
        }

        $button = [
            'type' => 'URL',
            'text' => $text,
            'url'  => $url,
        ];

        if ( strpos($url, '{{1}}') !== false ) {
            $example = eventosapp_whatsapp_templates_normalize_button_example_for_storage($template['button_' . $i . '_example'] ?? '', $url);
            if ( $example !== '' ) {
                $button['example'] = [ $example ];
            }
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
            'request_body' => is_array($body) ? $body : null,
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
 * Indica si el formulario recibió un archivo de muestra para encabezado.
 */
function eventosapp_whatsapp_templates_has_header_sample_upload() {
    return ! empty($_FILES['header_sample_file'])
        && is_array($_FILES['header_sample_file'])
        && isset($_FILES['header_sample_file']['error'])
        && (int) $_FILES['header_sample_file']['error'] !== UPLOAD_ERR_NO_FILE;
}

/**
 * Valida el archivo local antes de subirlo a Meta como muestra de encabezado.
 */
function eventosapp_whatsapp_templates_validate_header_sample_file($file) {
    if ( empty($file) || ! is_array($file) ) {
        return new WP_Error('evapp_wa_header_file_missing', 'No se recibió el archivo de muestra.');
    }

    $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ( $error === UPLOAD_ERR_NO_FILE ) {
        return new WP_Error('evapp_wa_header_file_missing', 'No se seleccionó ningún archivo de muestra.');
    }

    if ( $error !== UPLOAD_ERR_OK ) {
        return new WP_Error('evapp_wa_header_file_upload_error', 'WordPress no pudo recibir el archivo de muestra. Código de carga: ' . $error . '.');
    }

    $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
    if ( $tmp_name === '' || ! is_uploaded_file($tmp_name) || ! file_exists($tmp_name) || ! is_readable($tmp_name) ) {
        return new WP_Error('evapp_wa_header_file_invalid_tmp', 'El archivo temporal de la muestra no está disponible o no se puede leer.');
    }

    $size = isset($file['size']) ? absint($file['size']) : filesize($tmp_name);
    if ( $size <= 0 ) {
        return new WP_Error('evapp_wa_header_file_empty', 'La imagen de muestra está vacía.');
    }

    $max_size = 5 * 1024 * 1024;
    if ( $size > $max_size ) {
        return new WP_Error('evapp_wa_header_file_too_large', 'La imagen de muestra supera el máximo recomendado de 5 MB para encabezados de imagen de WhatsApp.');
    }

    $original_name = isset($file['name']) ? sanitize_file_name((string) $file['name']) : 'eventosapp-header-sample.png';
    if ( $original_name === '' ) {
        $original_name = 'eventosapp-header-sample.png';
    }

    $allowed_mimes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];

    $check = wp_check_filetype_and_ext($tmp_name, $original_name, $allowed_mimes);
    $mime = ! empty($check['type']) ? $check['type'] : '';

    if ( $mime === '' && function_exists('finfo_open') ) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ( $finfo ) {
            $mime = (string) finfo_file($finfo, $tmp_name);
            finfo_close($finfo);
        }
    }

    if ( ! in_array($mime, ['image/jpeg', 'image/png'], true) ) {
        return new WP_Error('evapp_wa_header_file_type', 'La muestra para encabezado de imagen debe ser JPG/JPEG o PNG. Meta no acepta una URL pública ni otro tipo de archivo para este campo.');
    }

    if ( ! preg_match('/\.(jpe?g|png)$/i', $original_name) ) {
        $original_name .= $mime === 'image/png' ? '.png' : '.jpg';
    }

    return [
        'tmp_name' => $tmp_name,
        'name'     => $original_name,
        'type'     => $mime,
        'size'     => $size,
    ];
}

/**
 * Sube una imagen de muestra a Meta y devuelve el Header Sample Handle.
 */
function eventosapp_whatsapp_templates_upload_header_sample_to_meta($file) {
    $settings = eventosapp_whatsapp_templates_get_settings();
    $wa_settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];

    $app_id = preg_replace('/\D+/', '', (string)($settings['app_id'] ?? ''));
    $access_token = trim((string)($wa_settings['access_token'] ?? ''));
    $api_version  = preg_replace('/[^a-zA-Z0-9\.\-_]/', '', (string)($wa_settings['api_version'] ?? 'v23.0'));
    $timeout      = min(60, max(5, absint($wa_settings['request_timeout'] ?? 20)));

    if ( $api_version === '' ) {
        $api_version = 'v23.0';
    }

    if ( $app_id === '' ) {
        return [
            'ok' => false,
            'message' => 'Falta configurar el Meta App ID en la conexión de plantillas. Este ID se necesita para crear la sesión de Resumable Upload y generar el Header Sample Handle.',
        ];
    }

    if ( $access_token === '' ) {
        return [
            'ok' => false,
            'message' => 'Falta Access Token en WhatsApp Tickets. El mismo token se usa para subir la muestra y crear la plantilla.',
        ];
    }

    $validated = eventosapp_whatsapp_templates_validate_header_sample_file($file);
    if ( is_wp_error($validated) ) {
        return [
            'ok' => false,
            'message' => $validated->get_error_message(),
        ];
    }

    if ( ! empty($wa_settings['dry_run']) && $wa_settings['dry_run'] === '1' ) {
        return [
            'ok' => true,
            'message' => 'Modo prueba interno: muestra simulada. No se subió archivo a Meta.',
            'handle' => 'dry_run_header_handle_' . substr(md5($validated['name'] . $validated['size']), 0, 16),
            'file' => $validated,
            'response' => [
                'dry_run' => true,
            ],
        ];
    }

    $session_endpoint = sprintf('https://graph.facebook.com/%s/%s/uploads', rawurlencode($api_version), rawurlencode($app_id));
    $session_endpoint = add_query_arg([
        'file_name'   => $validated['name'],
        'file_length' => $validated['size'],
        'file_type'   => $validated['type'],
        'access_token' => $access_token,
    ], $session_endpoint);

    $session_response = wp_remote_post($session_endpoint, [
        'timeout' => $timeout,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
    ]);

    if ( is_wp_error($session_response) ) {
        return [
            'ok' => false,
            'message' => 'No se pudo crear la sesión de carga en Meta: ' . $session_response->get_error_message(),
        ];
    }

    $session_code = (int) wp_remote_retrieve_response_code($session_response);
    $session_body = (string) wp_remote_retrieve_body($session_response);
    $session_decoded = json_decode($session_body, true);
    $session_ok = $session_code >= 200 && $session_code < 300 && is_array($session_decoded) && ! empty($session_decoded['id']);

    if ( function_exists('eventosapp_whatsapp_log') ) {
        eventosapp_whatsapp_log($session_ok ? 'Header Sample: sesión de carga creada' : 'Header Sample: error creando sesión', [
            'http_code' => $session_code,
            'file_name' => $validated['name'],
            'file_type' => $validated['type'],
            'file_size' => $validated['size'],
            'response' => $session_decoded ?: $session_body,
        ]);
    }

    if ( ! $session_ok ) {
        return [
            'ok' => false,
            'message' => eventosapp_whatsapp_templates_extract_api_error($session_decoded, $session_body, $session_code),
            'response' => $session_decoded ?: $session_body,
        ];
    }

    $upload_session_id = (string) $session_decoded['id'];
    $binary = file_get_contents($validated['tmp_name']);
    if ( $binary === false || $binary === '' ) {
        return [
            'ok' => false,
            'message' => 'No se pudo leer el archivo de muestra para enviarlo a Meta.',
        ];
    }

    $upload_endpoint = sprintf('https://graph.facebook.com/%s/%s', rawurlencode($api_version), $upload_session_id);
    $upload_response = wp_remote_request($upload_endpoint, [
        'method' => 'POST',
        'timeout' => $timeout,
        'headers' => [
            'Authorization' => 'OAuth ' . $access_token,
            'file_offset'   => '0',
            'Content-Type'  => $validated['type'],
            'Content-Length' => (string) strlen($binary),
        ],
        'body' => $binary,
    ]);

    if ( is_wp_error($upload_response) ) {
        return [
            'ok' => false,
            'message' => 'No se pudo subir la muestra a Meta: ' . $upload_response->get_error_message(),
        ];
    }

    $upload_code = (int) wp_remote_retrieve_response_code($upload_response);
    $upload_body = (string) wp_remote_retrieve_body($upload_response);
    $upload_decoded = json_decode($upload_body, true);
    $handle = '';

    if ( is_array($upload_decoded) ) {
        if ( ! empty($upload_decoded['h']) ) {
            $handle = eventosapp_whatsapp_templates_sanitize_header_handle($upload_decoded['h']);
        } elseif ( ! empty($upload_decoded['handle']) ) {
            $handle = eventosapp_whatsapp_templates_sanitize_header_handle($upload_decoded['handle']);
        }
    }

    $upload_ok = $upload_code >= 200 && $upload_code < 300 && $handle !== '';

    if ( function_exists('eventosapp_whatsapp_log') ) {
        eventosapp_whatsapp_log($upload_ok ? 'Header Sample: archivo subido a Meta' : 'Header Sample: error subiendo archivo', [
            'http_code' => $upload_code,
            'file_name' => $validated['name'],
            'file_type' => $validated['type'],
            'file_size' => $validated['size'],
            'has_handle' => $handle !== '',
            'response' => $upload_decoded ?: $upload_body,
        ]);
    }

    if ( ! $upload_ok ) {
        return [
            'ok' => false,
            'message' => $handle === '' ? 'Meta respondió la carga, pero no devolvió un Header Sample Handle utilizable.' : eventosapp_whatsapp_templates_extract_api_error($upload_decoded, $upload_body, $upload_code),
            'response' => $upload_decoded ?: $upload_body,
        ];
    }

    return [
        'ok' => true,
        'message' => 'Imagen de muestra subida a Meta. Header Sample Handle generado correctamente.',
        'handle' => $handle,
        'file' => $validated,
        'response' => $upload_decoded,
    ];
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

    $template = $settings['templates'][$template_id];
    $waba_id = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
    if ( $waba_id === '' ) {
        return ['ok' => false, 'message' => 'Configura el WhatsApp Business Account ID del número emisor al que pertenece esta plantilla.'];
    }

    $sender_account = eventosapp_whatsapp_templates_resolve_sender_account($template['sender_phone_number_id'] ?? '', $settings);
    $prepared_body = eventosapp_whatsapp_templates_prepare_body_for_meta($template['body_text'] ?? '', $template['body_examples'] ?? '');
    $template['body_text_meta'] = sanitize_textarea_field($prepared_body['text'] ?? ($template['body_text'] ?? ''));
    $template['body_variable_map'] = eventosapp_whatsapp_templates_sanitize_body_variable_map($prepared_body['variable_numbers'] ?? []);
    $template['body_variable_signature'] = sanitize_text_field($prepared_body['signature'] ?? md5((string)($template['body_text'] ?? '')));
    $template['sender_phone_number_id'] = eventosapp_whatsapp_templates_sanitize_phone_number_id($sender_account['phone_number_id'] ?? ($template['sender_phone_number_id'] ?? ''));
    $template['sender_phone_label'] = sanitize_text_field((string)($sender_account['alias'] ?? ($sender_account['label'] ?? 'Número WhatsApp')));
    $template['waba_id'] = $waba_id;
    $settings['templates'][$template_id] = $template;
    eventosapp_whatsapp_templates_update_settings($settings);

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

    if ( function_exists('eventosapp_whatsapp_log') ) {
        eventosapp_whatsapp_log('Plantilla WhatsApp enviada a Meta con número emisor', [
            'template_id' => $template_id,
            'template_name' => $template['name'] ?? '',
            'sender_phone_number_id' => $template['sender_phone_number_id'] ?? '',
            'sender_phone_label' => $template['sender_phone_label'] ?? '',
            'waba_id' => $waba_id,
        ]);
    }

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
        $template['waba_id'] = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
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

    $template = $settings['templates'][$template_id];
    $waba_id = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
    if ( $waba_id === '' ) {
        return ['ok' => false, 'message' => 'Configura el WhatsApp Business Account ID del número emisor de esta plantilla.'];
    }

    $sender_account = eventosapp_whatsapp_templates_resolve_sender_account($template['sender_phone_number_id'] ?? '', $settings);
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
    $template['sender_phone_number_id'] = eventosapp_whatsapp_templates_sanitize_phone_number_id($sender_account['phone_number_id'] ?? ($template['sender_phone_number_id'] ?? ''));
    $template['sender_phone_label'] = sanitize_text_field((string)($sender_account['alias'] ?? ($sender_account['label'] ?? 'Número WhatsApp')));
    $template['waba_id'] = $waba_id;
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
    $templates = isset($settings['templates']) && is_array($settings['templates']) ? $settings['templates'] : [];

    $waba_ids = [];
    $default_waba_id = eventosapp_whatsapp_templates_sanitize_waba_id($settings['waba_id'] ?? '');
    if ( $default_waba_id !== '' ) {
        $waba_ids[$default_waba_id] = $default_waba_id;
    }

    foreach ( $templates as $template ) {
        if ( ! is_array($template) ) {
            continue;
        }
        $template_waba_id = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
        if ( $template_waba_id !== '' ) {
            $waba_ids[$template_waba_id] = $template_waba_id;
        }
    }

    if ( empty($waba_ids) ) {
        return ['ok' => false, 'message' => 'Configura al menos un WhatsApp Business Account ID para sincronizar plantillas.'];
    }

    $fields = 'id,name,status,category,language,rejected_reason,quality_score';
    $remote_by_waba = [];
    $last_response = [];

    foreach ( $waba_ids as $waba_id ) {
        $path = rawurlencode($waba_id) . '/message_templates?limit=100&fields=' . rawurlencode($fields);
        $api_result = eventosapp_whatsapp_templates_api_request('GET', $path);
        $last_response[$waba_id] = $api_result['response'] ?? null;

        if ( empty($api_result['ok']) || empty($api_result['response']['data']) || ! is_array($api_result['response']['data']) ) {
            continue;
        }

        $remote_by_waba[$waba_id] = $api_result['response']['data'];
    }

    if ( empty($remote_by_waba) ) {
        return [
            'ok' => false,
            'message' => 'No se pudieron consultar plantillas en los WABA configurados.',
            'response' => $last_response,
        ];
    }

    $updated = 0;
    foreach ( $settings['templates'] as $local_id => $template ) {
        if ( ! is_array($template) ) {
            continue;
        }

        $template_waba_id = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
        if ( $template_waba_id === '' || empty($remote_by_waba[$template_waba_id]) ) {
            continue;
        }

        foreach ( $remote_by_waba[$template_waba_id] as $remote ) {
            if ( ! is_array($remote) ) {
                continue;
            }
            if ( ($remote['name'] ?? '') !== ($template['name'] ?? '') || ($remote['language'] ?? '') !== ($template['language'] ?? '') ) {
                continue;
            }

            $sender_account = eventosapp_whatsapp_templates_resolve_sender_account($template['sender_phone_number_id'] ?? '', $settings);
            $template['meta_template_id'] = ! empty($remote['id']) ? sanitize_text_field((string)$remote['id']) : ($template['meta_template_id'] ?? '');
            $template['meta_status'] = ! empty($remote['status']) ? sanitize_text_field((string)$remote['status']) : ($template['meta_status'] ?? 'UNKNOWN');
            $template['meta_category'] = ! empty($remote['category']) ? sanitize_text_field((string)$remote['category']) : ($template['meta_category'] ?? '');
            $template['meta_rejected_reason'] = ! empty($remote['rejected_reason']) ? sanitize_text_field((string)$remote['rejected_reason']) : '';
            $template['sender_phone_number_id'] = eventosapp_whatsapp_templates_sanitize_phone_number_id($sender_account['phone_number_id'] ?? ($template['sender_phone_number_id'] ?? ''));
            $template['sender_phone_label'] = sanitize_text_field((string)($sender_account['alias'] ?? ($sender_account['label'] ?? 'Número WhatsApp')));
            $template['waba_id'] = $template_waba_id;
            $template['last_checked_at'] = current_time('mysql');
            $template['last_api_response'] = $remote;
            $settings['templates'][$local_id] = $template;
            $updated++;
            break;
        }
    }

    $settings['last_sync_at'] = current_time('mysql');
    $settings['last_message'] = 'Sincronización ejecutada por WABA. Plantillas locales actualizadas: ' . $updated;
    eventosapp_whatsapp_templates_update_settings($settings);

    return ['ok' => true, 'message' => $settings['last_message'], 'response' => $last_response];
}
/**
 * Render principal del módulo.
 */
function eventosapp_whatsapp_templates_render_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes para acceder a esta página.');
    }

    if ( function_exists('wp_enqueue_media') ) {
        wp_enqueue_media();
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
            .evapp-wa-tpl-info{background:#f0f6fc;border-left:4px solid #72aee6;padding:10px 12px;margin:10px 0;line-height:1.5;}
            .evapp-wa-tpl-file-meta{background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:8px;margin-top:8px;}
            .evapp-wa-tpl-image-preview{display:flex;align-items:center;gap:12px;margin-top:8px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:9px;max-width:720px;}
            .evapp-wa-tpl-image-preview img{max-width:190px;max-height:88px;width:auto;height:auto;object-fit:cover;background:#fff;border:1px solid #dcdcde;border-radius:6px;}
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

                    <label for="evapp_wa_tpl_app_id">Meta App ID</label>
                    <div>
                        <input type="text" id="evapp_wa_tpl_app_id" name="app_id" value="<?php echo esc_attr($settings['app_id'] ?? ''); ?>" placeholder="Ej: 123456789012345">
                        <p class="evapp-wa-tpl-help">Es el ID numérico de la app de Meta Developers. Se necesita para subir la imagen de muestra con Resumable Upload API y generar el <span class="evapp-wa-tpl-code">Header Sample Handle</span>.</p>
                    </div>

                    <label for="evapp_wa_tpl_default_qr_header_image">Imagen por defecto para cabezote QR WhatsApp</label>
                    <div>
                        <input type="text" id="evapp_wa_tpl_default_qr_header_image" class="evapp-wa-tpl-media-url" name="default_qr_header_image" value="<?php echo esc_attr($settings['default_qr_header_image'] ?? ''); ?>" placeholder="https://.../cabezote-whatsapp.jpg">
                        <p class="evapp-wa-tpl-help">Se usará encima del QR enviado por WhatsApp cuando el evento no tenga un cabezote personalizado. Medida exacta recomendada: 1000 x 160 px, en JPG o PNG. Esta imagen no reemplaza el QR.</p>
                        <p><button type="button" class="button evapp-wa-tpl-media-button" data-target="#evapp_wa_tpl_default_qr_header_image">Seleccionar imagen</button> <button type="button" class="button evapp-wa-tpl-media-clear" data-target="#evapp_wa_tpl_default_qr_header_image">Quitar</button></p>
                        <?php if ( ! empty($settings['default_qr_header_image']) ) : ?>
                            <div class="evapp-wa-tpl-image-preview"><img src="<?php echo esc_url($settings['default_qr_header_image']); ?>" alt="Cabezote QR"><span>Imagen activa para cabezote de QR WhatsApp.</span></div>
                        <?php endif; ?>
                    </div>

                    <label for="evapp_wa_tpl_default_virtual_message_image">Imagen por defecto para mensajes virtuales</label>
                    <div>
                        <input type="text" id="evapp_wa_tpl_default_virtual_message_image" class="evapp-wa-tpl-media-url" name="default_virtual_message_image" value="<?php echo esc_attr($settings['default_virtual_message_image'] ?? ''); ?>" placeholder="https://.../ticket-virtual-whatsapp.jpg">
                        <p class="evapp-wa-tpl-help">Se enviará como imagen del mensaje para tickets de modalidad virtual cuando el ticket no tenga una imagen personalizada.</p>
                        <p><button type="button" class="button evapp-wa-tpl-media-button" data-target="#evapp_wa_tpl_default_virtual_message_image">Seleccionar imagen</button> <button type="button" class="button evapp-wa-tpl-media-clear" data-target="#evapp_wa_tpl_default_virtual_message_image">Quitar</button></p>
                        <?php if ( ! empty($settings['default_virtual_message_image']) ) : ?>
                            <div class="evapp-wa-tpl-image-preview"><img src="<?php echo esc_url($settings['default_virtual_message_image']); ?>" alt="Imagen virtual"><span>Imagen activa para mensajes de modalidad virtual.</span></div>
                        <?php endif; ?>
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
            <strong>Importante:</strong> la plantilla presencial usa encabezado de imagen para poder enviar el QR como imagen dinámica cuando se use en el envío final. El <span class="evapp-wa-tpl-code">Header Sample Handle</span> no es una URL pública: se genera subiendo una imagen JPG/PNG de muestra a Meta desde el formulario de edición. Sin ese handle, Meta rechazará la creación por API.
        </div>

        <?php
        if ( $view === 'edit' ) {
            eventosapp_whatsapp_templates_render_edit_form($template_id);
        } else {
            eventosapp_whatsapp_templates_render_list($settings);
        }
        ?>
        <script>
        jQuery(function($){
            var evappWaTplFrame = null;
            $('.evapp-wa-tpl-media-button').on('click', function(e){
                e.preventDefault();
                var targetSelector = $(this).data('target');
                if (!targetSelector) return;
                evappWaTplFrame = wp.media({
                    title: 'Seleccionar imagen',
                    button: { text: 'Usar esta imagen' },
                    library: { type: 'image' },
                    multiple: false
                });
                evappWaTplFrame.on('select', function(){
                    var attachment = evappWaTplFrame.state().get('selection').first().toJSON();
                    if (attachment && attachment.url) {
                        $(targetSelector).val(attachment.url).trigger('change');
                    }
                });
                evappWaTplFrame.open();
            });
            $('.evapp-wa-tpl-media-clear').on('click', function(e){
                e.preventDefault();
                var targetSelector = $(this).data('target');
                if (targetSelector) $(targetSelector).val('').trigger('change');
            });
        });
        </script>
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
            Las dos plantillas por defecto ya quedan creadas localmente como base: una para modalidad presencial y otra para modalidad virtual. Puedes editarlas, duplicarlas o enviar su estructura a Meta para aprobación marcándolas para el número emisor correspondiente.
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
                    <th>Número / WABA</th>
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
                            <?php
                            $sender_label = eventosapp_whatsapp_templates_get_template_sender_label($template, $settings);
                            $sender_phone = eventosapp_whatsapp_templates_sanitize_phone_number_id($template['sender_phone_number_id'] ?? '') ?: eventosapp_whatsapp_templates_get_default_phone_number_id();
                            $template_waba_id = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
                            ?>
                            <strong><?php echo esc_html($sender_label); ?></strong>
                            <?php if ( $sender_phone !== '' ) : ?><br><small>Phone ID: <?php echo esc_html($sender_phone); ?></small><?php endif; ?>
                            <?php if ( $template_waba_id !== '' ) : ?><br><small>WABA: <?php echo esc_html($template_waba_id); ?></small><?php else : ?><br><small style="color:#b91c1c;">Sin WABA ID</small><?php endif; ?>
                        </td>
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
                            <?php if ( ! empty($template['button_1_text']) ) : ?>1. <?php echo esc_html($template['button_1_text']); ?><?php if ( ! eventosapp_whatsapp_templates_button_enabled($template, 1) ) : ?> <em>(desactivado)</em><?php endif; ?><br><?php endif; ?>
                            <?php if ( ! empty($template['button_2_text']) ) : ?>2. <?php echo esc_html($template['button_2_text']); ?><?php if ( ! eventosapp_whatsapp_templates_button_enabled($template, 2) ) : ?> <em>(desactivado)</em><?php endif; ?><?php endif; ?>
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

    $phone_accounts = eventosapp_whatsapp_templates_get_phone_accounts();
    $default_sender_phone = eventosapp_whatsapp_templates_get_default_phone_number_id();
    $template_sender_phone = eventosapp_whatsapp_templates_sanitize_phone_number_id($template['sender_phone_number_id'] ?? '') ?: $default_sender_phone;
    $template_waba_id = eventosapp_whatsapp_templates_get_template_waba_id($template, $settings);
    $template_custom_waba_id = eventosapp_whatsapp_templates_sanitize_waba_id($template['waba_id'] ?? '');
    $template_uses_default_sender = ($template_sender_phone === '' || $template_sender_phone === $default_sender_phone);

    $preview_payload = [
        'waba_id'    => $template_waba_id,
        'sender_phone_number_id' => $template_sender_phone,
        'name'       => $template['name'] ?? '',
        'language'   => $template['language'] ?? 'es',
        'category'   => 'UTILITY',
        'components' => eventosapp_whatsapp_templates_build_meta_components($template),
    ];
    ?>
    <div class="evapp-wa-tpl-card">
        <h2><?php echo $is_new ? 'Crear plantilla WhatsApp' : 'Editar plantilla WhatsApp'; ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
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
                    <p class="evapp-wa-tpl-help">Usa minúsculas, números y guion bajo. Para aprobar una versión por otro número, duplica la plantilla y cambia el nombre técnico para que sea único dentro del WABA seleccionado.</p>
                </div>

                <label for="evapp_tpl_sender_phone">Número emisor de esta plantilla</label>
                <div>
                    <?php if ( empty($phone_accounts) ) : ?>
                        <p class="evapp-wa-tpl-help" style="margin-top:0;color:#b91c1c;">Primero configura al menos el Phone Number ID por defecto en WhatsApp Tickets.</p>
                        <input type="hidden" name="template[sender_phone_number_id]" value="<?php echo esc_attr($template_sender_phone); ?>">
                    <?php else : ?>
                        <select id="evapp_tpl_sender_phone" name="template[sender_phone_number_id]" required data-default-phone="<?php echo esc_attr($default_sender_phone); ?>">
                            <?php foreach ( $phone_accounts as $account_id => $account ) :
                                $account_is_default = ! empty($account['is_default']) || $account_id === $default_sender_phone;
                                $account_label = (string)($account['label'] ?? (($account['alias'] ?? 'Número WhatsApp') . ' — ' . $account_id));
                                if ( $account_is_default && empty($settings['waba_id']) ) {
                                    $account_label .= ' · sin WABA ID por defecto';
                                } elseif ( ! $account_is_default ) {
                                    $account_label .= ' · WABA se define en esta plantilla';
                                }
                            ?>
                                <option value="<?php echo esc_attr($account_id); ?>" <?php selected($template_sender_phone, $account_id); ?>><?php echo esc_html($account_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="evapp-wa-tpl-help">Esta marca controla en qué WhatsApp Business Account ID se crea/sincroniza la plantilla y evita que luego se use con un número emisor diferente en tickets o envío masivo.</p>
                        <?php if ( $template_waba_id !== '' ) : ?>
                            <p class="evapp-wa-tpl-help">WABA efectivo para esta plantilla: <span class="evapp-wa-tpl-code"><?php echo esc_html($template_waba_id); ?></span>.</p>
                        <?php else : ?>
                            <p class="evapp-wa-tpl-help" style="color:#b91c1c;">Este número no tiene WABA ID efectivo. Si es el número por defecto, guarda el WABA en la conexión superior de Plantillas WhatsApp. Si es un número adicional, diligencia el WABA en el campo que aparece abajo.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <label class="evapp-wa-tpl-non-default-waba" for="evapp_tpl_sender_waba_id">WABA ID de este número</label>
                <div class="evapp-wa-tpl-non-default-waba">
                    <input type="text" id="evapp_tpl_sender_waba_id" name="template[waba_id]" value="<?php echo esc_attr($template_uses_default_sender ? '' : $template_custom_waba_id); ?>" placeholder="Ej: 348166311709878" autocomplete="off">
                    <p class="evapp-wa-tpl-help">Este campo solo aplica cuando la plantilla se enviará para aprobación con un número distinto al número por defecto. Debe ser el WhatsApp Business Account ID donde Meta aprobará esta plantilla.</p>
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
                    <input type="text" id="evapp_tpl_header_handle" name="template[header_sample_handle]" value="<?php echo esc_attr($template['header_sample_handle'] ?? ''); ?>" placeholder="Se genera al subir una imagen de muestra a Meta">
                    <p class="evapp-wa-tpl-help">Obligatorio si el encabezado es Imagen dinámica. No pegues una URL pública aquí; debe ser el handle que devuelve Meta después de subir la muestra.</p>
                    <?php if ( ! empty($template['header_sample_handle']) ) : ?>
                        <div class="evapp-wa-tpl-file-meta">
                            <strong>Handle guardado:</strong> <span class="evapp-wa-tpl-code"><?php echo esc_html($template['header_sample_handle']); ?></span>
                            <?php if ( ! empty($template['header_sample_uploaded_at']) ) : ?><br><small>Última muestra subida: <?php echo esc_html($template['header_sample_uploaded_at']); ?></small><?php endif; ?>
                            <?php if ( ! empty($template['header_sample_file_name']) ) : ?><br><small>Archivo: <?php echo esc_html($template['header_sample_file_name']); ?> · <?php echo esc_html($template['header_sample_file_type'] ?? ''); ?> · <?php echo esc_html(size_format(absint($template['header_sample_file_size'] ?? 0))); ?></small><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <label for="evapp_tpl_header_sample_file">Imagen de muestra para Meta</label>
                <div>
                    <input type="file" id="evapp_tpl_header_sample_file" name="header_sample_file" accept="image/png,image/jpeg">
                    <div class="evapp-wa-tpl-info">
                        <strong>Qué debes subir:</strong> una imagen JPG/JPEG o PNG de ejemplo, máximo 5 MB. Para esta plantilla presencial puedes usar una imagen de muestra del QR o una imagen neutra del ticket. EventosApp la sube a Meta con Resumable Upload API y guarda automáticamente el <span class="evapp-wa-tpl-code">Header Sample Handle</span> que Meta exige para aprobar plantillas con encabezado de imagen.
                    </div>
                    <p class="evapp-wa-tpl-help">Requisitos previos: tener guardados el WhatsApp Business Account ID, el Meta App ID y el Access Token en WhatsApp Tickets. El archivo solo se usa como muestra de aprobación; al enviar tickets reales, el QR individual se enviará dinámicamente.</p>
                </div>

                <label for="evapp_tpl_body">Cuerpo</label>
                <div>
                    <textarea id="evapp_tpl_body" name="template[body_text]" required><?php echo esc_textarea($template['body_text'] ?? ''); ?></textarea>
                    <p class="evapp-wa-tpl-help">Variables disponibles: <span class="evapp-wa-tpl-code">{{1}}</span> nombre del asistente, <span class="evapp-wa-tpl-code">{{2}}</span> evento, <span class="evapp-wa-tpl-code">{{3}}</span> fecha, <span class="evapp-wa-tpl-code">{{4}}</span> hora, <span class="evapp-wa-tpl-code">{{5}}</span> lugar o plataforma, <span class="evapp-wa-tpl-code">{{6}}</span> enlace público del ticket/landing, <span class="evapp-wa-tpl-code">{{7}}</span> organizador, <span class="evapp-wa-tpl-code">{{8}}</span> modalidad, <span class="evapp-wa-tpl-code">{{9}}</span> enlace directo de la plataforma virtual.</p>
                    <p class="evapp-wa-tpl-help">Puedes quitar o reordenar variables. Antes de enviarla a Meta, EventosApp normaliza internamente el cuerpo para que el componente BODY siempre incluya el campo <span class="evapp-wa-tpl-code">example</span> requerido.</p>
                </div>

                <label for="evapp_tpl_body_examples">Ejemplos del cuerpo</label>
                <div>
                    <textarea id="evapp_tpl_body_examples" name="template[body_examples]" required><?php echo esc_textarea($template['body_examples'] ?? ''); ?></textarea>
                    <p class="evapp-wa-tpl-help">Un ejemplo por línea usando el número de la variable: línea 1 para <span class="evapp-wa-tpl-code">{{1}}</span>, línea 2 para <span class="evapp-wa-tpl-code">{{2}}</span>, línea 7 para <span class="evapp-wa-tpl-code">{{7}}</span>, línea 8 para <span class="evapp-wa-tpl-code">{{8}}</span> y línea 9 para <span class="evapp-wa-tpl-code">{{9}}</span>. Si falta una línea, EventosApp agrega un ejemplo seguro antes de enviar a Meta.</p>
                </div>

                <label for="evapp_tpl_footer">Footer</label>
                <div>
                    <input type="text" id="evapp_tpl_footer" name="template[footer_text]" value="<?php echo esc_attr($template['footer_text'] ?? ''); ?>">
                </div>

                <label>Botón 1</label>
                <div>
                    <input type="hidden" name="template[button_1_enabled]" value="0">
                    <label style="display:inline-flex;align-items:center;gap:6px;margin:0 0 8px;font-weight:600;">
                        <input type="checkbox" name="template[button_1_enabled]" value="1" <?php checked(eventosapp_whatsapp_templates_button_enabled($template, 1)); ?>>
                        Activar este botón en la plantilla
                    </label>
                    <input type="text" name="template[button_1_text]" value="<?php echo esc_attr($template['button_1_text'] ?? ''); ?>" placeholder="Texto del botón" style="margin-bottom:6px;">
                    <input type="text" name="template[button_1_url]" value="<?php echo esc_attr($template['button_1_url'] ?? ''); ?>" placeholder="URL pública con {{1}}" style="margin-bottom:6px;">
                    <input type="text" name="template[button_1_example]" value="<?php echo esc_attr($template['button_1_example'] ?? ''); ?>" placeholder="URL de ejemplo completa">
                    <p class="evapp-wa-tpl-help">El botón URL solo puede usar la variable técnica <span class="evapp-wa-tpl-code">{{1}}</span> para el identificador público del ticket. No uses <span class="evapp-wa-tpl-code">{{9}}</span> en este campo: <span class="evapp-wa-tpl-code">{{9}}</span> es para el cuerpo del mensaje. Para abrir la plataforma directa, usa el botón de acceso virtual de EventosApp; ese endpoint decide si redirige a landing o a la plataforma según la configuración del evento.</p>
                </div>

                <label>Botón 2</label>
                <div>
                    <input type="hidden" name="template[button_2_enabled]" value="0">
                    <label style="display:inline-flex;align-items:center;gap:6px;margin:0 0 8px;font-weight:600;">
                        <input type="checkbox" name="template[button_2_enabled]" value="1" <?php checked(eventosapp_whatsapp_templates_button_enabled($template, 2)); ?>>
                        Activar este botón en la plantilla
                    </label>
                    <input type="text" name="template[button_2_text]" value="<?php echo esc_attr($template['button_2_text'] ?? ''); ?>" placeholder="Texto del botón" style="margin-bottom:6px;">
                    <input type="text" name="template[button_2_url]" value="<?php echo esc_attr($template['button_2_url'] ?? ''); ?>" placeholder="URL pública con {{1}}" style="margin-bottom:6px;">
                    <input type="text" name="template[button_2_example]" value="<?php echo esc_attr($template['button_2_example'] ?? ''); ?>" placeholder="URL de ejemplo completa">
                    <p class="evapp-wa-tpl-help">La estructura queda limitada a dos botones URL para mantener compatibilidad con Meta. Puedes desactivar este botón para crear o reenviar una plantilla con un solo botón. Igual que en el botón 1, la URL del botón solo debe usar <span class="evapp-wa-tpl-code">{{1}}</span>.</p>
                </div>
            </div>

            <p>
                <?php submit_button('Guardar plantilla local', 'primary', 'submit', false); ?>
                <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_templates')); ?>">Volver al listado</a>
            </p>
            <script>
            jQuery(function($){
                var $sender = $('#evapp_tpl_sender_phone');
                var defaultPhone = $sender.data('default-phone') ? String($sender.data('default-phone')) : '';
                function evappToggleTemplateWabaField(){
                    var selected = $sender.length ? String($sender.val() || '') : defaultPhone;
                    var usesDefault = !selected || (defaultPhone && selected === defaultPhone);
                    $('.evapp-wa-tpl-non-default-waba').toggle(!usesDefault);
                }
                $sender.on('change', evappToggleTemplateWabaField);
                evappToggleTemplateWabaField();
            });
            </script>
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
    $settings['app_id'] = isset($_POST['app_id']) ? preg_replace('/\D+/', '', (string) wp_unslash($_POST['app_id'])) : '';
    $settings['default_qr_header_image'] = isset($_POST['default_qr_header_image']) ? esc_url_raw(trim((string) wp_unslash($_POST['default_qr_header_image']))) : '';
    $settings['default_virtual_message_image'] = isset($_POST['default_virtual_message_image']) ? esc_url_raw(trim((string) wp_unslash($_POST['default_virtual_message_image']))) : '';
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

    $upload_message = '';
    $upload_ok = true;

    if ( eventosapp_whatsapp_templates_has_header_sample_upload() ) {
        if ( ($template['header_format'] ?? '') !== 'IMAGE' ) {
            $upload_ok = false;
            $upload_message = 'Seleccionaste un archivo de muestra, pero el encabezado de la plantilla no está configurado como Imagen dinámica.';
        } else {
            $upload_result = eventosapp_whatsapp_templates_upload_header_sample_to_meta($_FILES['header_sample_file']);
            if ( ! empty($upload_result['ok']) ) {
                $file_meta = is_array($upload_result['file'] ?? null) ? $upload_result['file'] : [];
                $template['header_sample_handle'] = eventosapp_whatsapp_templates_sanitize_header_handle($upload_result['handle'] ?? '');
                $template['header_sample_file_name'] = sanitize_file_name($file_meta['name'] ?? '');
                $template['header_sample_file_type'] = sanitize_mime_type($file_meta['type'] ?? '');
                $template['header_sample_file_size'] = absint($file_meta['size'] ?? 0);
                $template['header_sample_uploaded_at'] = current_time('mysql');
                $template['last_api_message'] = sanitize_text_field((string)($upload_result['message'] ?? 'Imagen de muestra subida a Meta.'));
                $template['last_api_response'] = is_array($upload_result['response'] ?? null) ? $upload_result['response'] : [];
                $upload_message = $upload_result['message'] ?? 'Imagen de muestra subida a Meta y handle guardado.';
            } else {
                $upload_ok = false;
                $template['last_api_message'] = sanitize_text_field((string)($upload_result['message'] ?? 'No se pudo subir la imagen de muestra a Meta.'));
                $template['last_api_response'] = is_array($upload_result['response'] ?? null) ? $upload_result['response'] : [];
                $upload_message = $template['last_api_message'];
            }
        }
    }

    $settings['templates'][$template['id']] = $template;
    eventosapp_whatsapp_templates_update_settings($settings);

    if ( $upload_message !== '' ) {
        eventosapp_whatsapp_templates_redirect($upload_ok, ($upload_ok ? 'Plantilla local guardada. ' : 'Plantilla local guardada, pero ') . $upload_message, [
            'view' => 'edit',
            'template_id' => $template['id'],
        ]);
    }

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

    $landing_header = function_exists('eventosapp_whatsapp_get_landing_header_image') ? eventosapp_whatsapp_get_landing_header_image($ticket_id, $event_id) : '';

    // En la landing pública solo se debe mostrar el QR limpio. La composición
    // cabezote + QR se reserva exclusivamente para el encabezado multimedia del
    // mensaje de WhatsApp presencial.
    $message_image  = $qr_url;

    return [
        'qr' => esc_url_raw($qr_url),
        'message_image' => esc_url_raw($message_image),
        'landing_header' => esc_url_raw($landing_header),
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
add_action('template_redirect', 'eventosapp_whatsapp_templates_public_action_router', 0);

/**
 * Router público frontal para los enlaces de WhatsApp.
 * Permite abrir la landing, el ICS y el acceso virtual sin pasar por /wp-admin/.
 */
function eventosapp_whatsapp_templates_public_action_router() {
    if ( is_admin() ) {
        return;
    }

    $action = isset($_GET['eventosapp_whatsapp_public_action']) ? sanitize_key(wp_unslash($_GET['eventosapp_whatsapp_public_action'])) : '';
    if ( $action === '' ) {
        return;
    }

    if ( $action === 'ticket_landing' ) {
        eventosapp_whatsapp_templates_render_public_ticket_landing();
    }

    if ( $action === 'ticket_ics' ) {
        eventosapp_whatsapp_templates_redirect_ticket_ics();
    }

    if ( $action === 'virtual_access' ) {
        eventosapp_whatsapp_templates_redirect_virtual_access();
    }

    status_header(404);
    wp_die('Acción pública de WhatsApp no encontrada.');
}

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
    $is_virtual = function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id);

    // Para tickets virtuales, cualquier botón genérico "Ver mi ticket" debe llevar
    // a la landing virtual pública ya configurada para el evento, no a la ficha
    // de ticket presencial/mixta ni al enlace técnico del admin.
    if ( $is_virtual && ! empty($assets['virtual_landing']) ) {
        wp_safe_redirect($assets['virtual_landing']);
        exit;
    }

    $fecha = function_exists('eventosapp_whatsapp_get_event_date_label') ? eventosapp_whatsapp_get_event_date_label($event_id) : '';
    $hora_inicio = $event_id ? get_post_meta($event_id, '_eventosapp_hora_inicio', true) : '';
    $hora_cierre = $event_id ? get_post_meta($event_id, '_eventosapp_hora_cierre', true) : '';
    $direccion = $event_id ? get_post_meta($event_id, '_eventosapp_direccion', true) : '';
    $organizador = $event_id ? (function_exists('eventosapp_get_nombre_organizador') ? eventosapp_get_nombre_organizador($event_id) : get_post_meta($event_id, '_eventosapp_organizador', true)) : '';
    $platform = $event_id ? get_post_meta($event_id, '_eventosapp_virtual_platform', true) : '';
    $event_title = $event_id ? get_the_title($event_id) : 'Ticket';

    $wallet_google_img = function_exists('eventosapp_asset_url_with_version') ? eventosapp_asset_url_with_version('assets/graphics/wallet_icons/google_wallet_btn.png') : '';
    $wallet_apple_img  = function_exists('eventosapp_asset_url_with_version') ? eventosapp_asset_url_with_version('assets/graphics/wallet_icons/apple_wallet_btn.png') : '';

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
        <title><?php echo esc_html($event_title); ?> - Ticket</title>
        <style>
            body{margin:0;background:#eef2f7;color:#111827;font-family:Arial,Helvetica,sans-serif;}
            .evapp-ticket-wrap{max-width:760px;margin:0 auto;padding:28px 16px;box-sizing:border-box;}
            .evapp-ticket-card{background:#fff;border-radius:20px;box-shadow:0 14px 42px rgba(15,23,42,.11);overflow:hidden;border:1px solid #e5e7eb;}
            .evapp-ticket-header{background:#0f172a;}
            .evapp-ticket-header img{display:block;width:100%;height:auto;max-height:190px;object-fit:cover;}
            .evapp-ticket-body{padding:26px 28px 8px;}
            .evapp-ticket-title{margin:0 0 8px;font-size:28px;line-height:1.18;color:#111827;}
            .evapp-ticket-subtitle{margin:0 0 18px;color:#64748b;font-size:15px;line-height:1.45;}
            .evapp-ticket-media{text-align:center;margin:18px 0 22px;}
            .evapp-ticket-media img{max-width:330px;width:100%;height:auto;border:1px solid #e5e7eb;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.08);}
            .evapp-ticket-kvs{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px;margin:16px 0;}
            .evapp-ticket-kv{display:flex;gap:10px;padding:7px 0;border-bottom:1px solid rgba(226,232,240,.9);line-height:1.45;}
            .evapp-ticket-kv:last-child{border-bottom:0;}
            .evapp-ticket-kv b{min-width:120px;color:#0f172a;}
            .evapp-ticket-actions{padding:20px 28px 28px;background:#f8fafc;border-top:1px solid #e5e7eb;text-align:center;}
            .evapp-ticket-wallets{display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;margin:0 0 14px;}
            .evapp-ticket-wallets img{display:block;width:200px;max-width:100%;height:auto;}
            .evapp-ticket-buttons{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;}
            .evapp-ticket-button{display:inline-block;text-align:center;text-decoration:none;background:#111827;color:#fff!important;padding:13px 18px;border-radius:12px;font-weight:700;min-width:170px;box-sizing:border-box;}
            .evapp-ticket-button.secondary{background:#2563eb;}
            .evapp-ticket-button.neutral{background:#475569;}
            .evapp-ticket-button.success{background:#16a34a;}
            .evapp-ticket-small{font-size:12px;color:#64748b;margin:16px 0 0;text-align:center;line-height:1.4;}
            @media(max-width:520px){.evapp-ticket-body{padding:22px 18px 8px}.evapp-ticket-actions{padding:18px}.evapp-ticket-title{font-size:24px}.evapp-ticket-kv{display:block}.evapp-ticket-kv b{display:block;margin-bottom:2px}.evapp-ticket-button{width:100%;}.evapp-ticket-wallets a{width:100%;display:flex;justify-content:center;}}
        </style>
    </head>
    <body>
        <main class="evapp-ticket-wrap">
            <section class="evapp-ticket-card">
                <?php if ( ! empty($assets['landing_header']) ) : ?>
                    <div class="evapp-ticket-header"><img src="<?php echo esc_url($assets['landing_header']); ?>" alt="<?php echo esc_attr($event_title); ?>"></div>
                <?php endif; ?>

                <div class="evapp-ticket-body">
                    <h1 class="evapp-ticket-title"><?php echo esc_html($event_title); ?></h1>
                    <p class="evapp-ticket-subtitle">Tu inscripción está confirmada. Conserva esta página para consultar los enlaces principales del ticket.</p>

                    <?php if ( ! empty($assets['qr']) && ! $is_virtual ) : ?>
                        <div class="evapp-ticket-media">
                            <img src="<?php echo esc_url($assets['qr']); ?>" alt="QR de ingreso">
                        </div>
                    <?php endif; ?>

                    <div class="evapp-ticket-kvs">
                        <?php if ( $nombre ) : ?><div class="evapp-ticket-kv"><b>Asistente:</b><span><?php echo esc_html($nombre); ?></span></div><?php endif; ?>
                        <?php if ( $ticket_code ) : ?><div class="evapp-ticket-kv"><b>Ticket:</b><span><?php echo esc_html($ticket_code); ?></span></div><?php endif; ?>
                        <?php if ( $organizador ) : ?><div class="evapp-ticket-kv"><b>Organizador:</b><span><?php echo esc_html($organizador); ?></span></div><?php endif; ?>
                        <?php if ( $modalidad ) : ?><div class="evapp-ticket-kv"><b>Modalidad:</b><span><?php echo esc_html($modalidad); ?></span></div><?php endif; ?>
                        <?php if ( $fecha ) : ?><div class="evapp-ticket-kv"><b>Fecha:</b><span><?php echo esc_html($fecha); ?></span></div><?php endif; ?>
                        <?php if ( $hora_inicio ) : ?><div class="evapp-ticket-kv"><b>Hora:</b><span><?php echo esc_html($hora_inicio . ($hora_cierre ? ' - ' . $hora_cierre : '')); ?></span></div><?php endif; ?>
                        <?php if ( $is_virtual && $platform ) : ?><div class="evapp-ticket-kv"><b>Plataforma:</b><span><?php echo esc_html($platform); ?></span></div><?php endif; ?>
                        <?php if ( ! $is_virtual && $direccion ) : ?><div class="evapp-ticket-kv"><b>Lugar:</b><span><?php echo esc_html($direccion); ?></span></div><?php endif; ?>
                    </div>
                </div>

                <div class="evapp-ticket-actions">
                    <?php if ( ! $is_virtual && ( ! empty($assets['google_wallet']) || ! empty($assets['apple_wallet']) ) ) : ?>
                        <div class="evapp-ticket-wallets">
                            <?php if ( ! empty($assets['google_wallet']) ) : ?>
                                <a href="<?php echo esc_url($assets['google_wallet']); ?>" target="_blank" rel="noopener noreferrer" aria-label="Agregar a Google Wallet">
                                    <?php if ( $wallet_google_img ) : ?><img src="<?php echo esc_url($wallet_google_img); ?>" alt="Agregar a Google Wallet"><?php else : ?>Agregar a Google Wallet<?php endif; ?>
                                </a>
                            <?php endif; ?>
                            <?php if ( ! empty($assets['apple_wallet']) ) : ?>
                                <a href="<?php echo esc_url($assets['apple_wallet']); ?>" target="_blank" rel="noopener noreferrer" aria-label="Agregar a Apple Wallet">
                                    <?php if ( $wallet_apple_img ) : ?><img src="<?php echo esc_url($wallet_apple_img); ?>" alt="Agregar a Apple Wallet"><?php else : ?>Agregar a Apple Wallet<?php endif; ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="evapp-ticket-buttons">
                        <?php if ( ! empty($assets['virtual_landing']) && $is_virtual ) : ?><a class="evapp-ticket-button success" href="<?php echo esc_url($assets['virtual_landing']); ?>" target="_blank" rel="noopener noreferrer">Ingresar al evento virtual</a><?php endif; ?>
                        <?php if ( ! empty($assets['ics']) ) : ?><a class="evapp-ticket-button secondary" href="<?php echo esc_url($assets['ics']); ?>" target="_blank" rel="noopener noreferrer">Agregar a agenda</a><?php endif; ?>
                        <?php if ( ! empty($assets['pdf']) ) : ?><a class="evapp-ticket-button neutral" href="<?php echo esc_url($assets['pdf']); ?>" target="_blank" rel="noopener noreferrer">Descargar PDF</a><?php endif; ?>
                    </div>

                    <p class="evapp-ticket-small">Este enlace pertenece a EventosApp. No compartas tu ticket con terceros.</p>
                </div>
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
