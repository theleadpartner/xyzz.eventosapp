<?php
/**
 * EventosApp - Mensajería WhatsApp para tickets
 *
 * Integra WhatsApp Cloud API como canal adicional de entrega del ticket,
 * sin modificar la función existente de envío por correo. El envío se dispara
 * cuando el correo queda registrado como enviado o manualmente desde el ticket.
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! defined('EVENTOSAPP_WHATSAPP_OPTION') ) {
    define('EVENTOSAPP_WHATSAPP_OPTION', 'eventosapp_whatsapp_settings');
}

if ( ! defined('EVENTOSAPP_WHATSAPP_ACTIVITY_LOG_OPTION') ) {
    define('EVENTOSAPP_WHATSAPP_ACTIVITY_LOG_OPTION', 'eventosapp_whatsapp_activity_log');
}

/**
 * Valores por defecto de la integración WhatsApp.
 */
function eventosapp_whatsapp_default_settings() {
    return [
        'enabled'              => '0',
        'api_version'          => 'v23.0',
        'phone_number_id'      => '',
        'phone_number_label'   => 'EventosApp',
        'phone_accounts'       => [],
        'access_token'         => '',
        'default_country_code' => '57',
        'request_timeout'      => 20,
        'debug_log'            => '0',
        'dry_run'                => '0',
        'test_phone'             => '',
        'test_phone_number_id'   => '',
        'test_message_mode'      => 'template',
        'test_template_name'     => 'hello_world',
        'test_template_language' => 'en_US',
        'webhook_verify_token'   => '',
        'last_test_result'       => [],
        'message_intro'          => 'Hola {{nombre}}, tu inscripción para {{evento_nombre}} está confirmada.',
    ];
}

/**
 * Sanitiza un Phone Number ID de WhatsApp Cloud.
 */
function eventosapp_whatsapp_sanitize_phone_number_id($value) {
    return preg_replace('/\D+/', '', (string) $value);
}

/**
 * Sanitiza un WhatsApp Business Account ID asociado a un número emisor.
 */
function eventosapp_whatsapp_sanitize_waba_id($value) {
    return preg_replace('/\D+/', '', (string) $value);
}

/**
 * Sanitiza el alias administrativo de un número emisor.
 */
function eventosapp_whatsapp_sanitize_phone_account_label($value, $fallback = '') {
    $label = sanitize_text_field((string) $value);
    $label = trim($label);
    if ( $label === '' ) {
        $label = sanitize_text_field((string) $fallback);
    }
    return $label !== '' ? $label : 'Número WhatsApp';
}

/**
 * Normaliza el listado de números emisores adicionales.
 *
 * El número principal se conserva en phone_number_id para mantener compatibilidad
 * con instalaciones previas. Esta lista solo guarda números adicionales.
 * El WABA de aprobación de plantillas se administra por plantilla desde
 * eventosapp-whatsapp-templates.php, no desde esta pantalla de envío.
 */
function eventosapp_whatsapp_normalize_phone_accounts($accounts, $default_phone_number_id = '') {
    $default_phone_number_id = eventosapp_whatsapp_sanitize_phone_number_id($default_phone_number_id);
    $normalized = [];
    $seen = [];

    if ( ! is_array($accounts) ) {
        return [];
    }

    foreach ( $accounts as $account ) {
        if ( ! is_array($account) ) {
            continue;
        }

        $phone_number_id = eventosapp_whatsapp_sanitize_phone_number_id(
            $account['phone_number_id'] ?? ($account['id'] ?? '')
        );

        if ( $phone_number_id === '' || $phone_number_id === $default_phone_number_id || isset($seen[$phone_number_id]) ) {
            continue;
        }

        $alias = eventosapp_whatsapp_sanitize_phone_account_label(
            $account['alias'] ?? ($account['label'] ?? ''),
            'WhatsApp ' . substr($phone_number_id, -4)
        );
        $normalized[] = [
            'alias'           => $alias,
            'phone_number_id' => $phone_number_id,
        ];
        $seen[$phone_number_id] = true;
    }

    return $normalized;
}

/**
 * Construye el listado efectivo de números emisores disponibles.
 */
function eventosapp_whatsapp_get_phone_accounts($settings = null) {
    $settings = is_array($settings) ? wp_parse_args($settings, eventosapp_whatsapp_default_settings()) : eventosapp_whatsapp_get_settings();

    $default_phone_number_id = eventosapp_whatsapp_sanitize_phone_number_id($settings['phone_number_id'] ?? '');
    $default_label = eventosapp_whatsapp_sanitize_phone_account_label($settings['phone_number_label'] ?? '', 'EventosApp');
    $accounts = [];

    if ( $default_phone_number_id !== '' ) {
        $accounts[$default_phone_number_id] = [
            'alias'           => $default_label,
            'phone_number_id' => $default_phone_number_id,
            'label'           => $default_label . ' — ' . $default_phone_number_id . ' (por defecto)',
            'is_default'      => true,
        ];
    }

    foreach ( eventosapp_whatsapp_normalize_phone_accounts($settings['phone_accounts'] ?? [], $default_phone_number_id) as $account ) {
        $phone_number_id = $account['phone_number_id'];
        $alias = $account['alias'];
        $accounts[$phone_number_id] = [
            'alias'           => $alias,
            'phone_number_id' => $phone_number_id,
            'label'           => $alias . ' — ' . $phone_number_id,
            'is_default'      => false,
        ];
    }

    return apply_filters('eventosapp_whatsapp_phone_accounts', $accounts, $settings);
}

/**
 * Devuelve un número emisor disponible por Phone Number ID.
 */
function eventosapp_whatsapp_get_phone_account($phone_number_id = '', $settings = null) {
    $settings = is_array($settings) ? wp_parse_args($settings, eventosapp_whatsapp_default_settings()) : eventosapp_whatsapp_get_settings();
    $accounts = eventosapp_whatsapp_get_phone_accounts($settings);
    $phone_number_id = eventosapp_whatsapp_sanitize_phone_number_id($phone_number_id);

    if ( $phone_number_id !== '' && isset($accounts[$phone_number_id]) ) {
        return $accounts[$phone_number_id];
    }

    $default_phone_number_id = eventosapp_whatsapp_sanitize_phone_number_id($settings['phone_number_id'] ?? '');
    if ( $default_phone_number_id !== '' && isset($accounts[$default_phone_number_id]) ) {
        return $accounts[$default_phone_number_id];
    }

    return null;
}

/**
 * Resuelve el Phone Number ID elegido para un evento. Si el evento no tiene
 * selección válida, usa el número principal configurado en WhatsApp Tickets.
 */
function eventosapp_whatsapp_get_event_sender_phone_number_id($event_id, $settings = null) {
    $event_id = absint($event_id);
    $settings = is_array($settings) ? wp_parse_args($settings, eventosapp_whatsapp_default_settings()) : eventosapp_whatsapp_get_settings();
    $accounts = eventosapp_whatsapp_get_phone_accounts($settings);
    $default_phone_number_id = eventosapp_whatsapp_sanitize_phone_number_id($settings['phone_number_id'] ?? '');

    $selected = $event_id ? eventosapp_whatsapp_sanitize_phone_number_id(get_post_meta($event_id, '_eventosapp_whatsapp_sender_phone_number_id', true)) : '';
    if ( $selected !== '' && isset($accounts[$selected]) ) {
        return $selected;
    }

    if ( $default_phone_number_id !== '' && isset($accounts[$default_phone_number_id]) ) {
        return $default_phone_number_id;
    }

    return '';
}

/**
 * Aplica a los settings el número emisor seleccionado para un evento.
 */
function eventosapp_whatsapp_resolve_sender_settings($event_id = 0, $settings = null) {
    $settings = is_array($settings) ? wp_parse_args($settings, eventosapp_whatsapp_default_settings()) : eventosapp_whatsapp_get_settings();
    $sender_phone_number_id = eventosapp_whatsapp_get_event_sender_phone_number_id($event_id, $settings);
    $account = eventosapp_whatsapp_get_phone_account($sender_phone_number_id, $settings);

    if ( $sender_phone_number_id !== '' ) {
        $settings['phone_number_id'] = $sender_phone_number_id;
    }

    $settings['sender_phone_number_id'] = eventosapp_whatsapp_sanitize_phone_number_id($settings['phone_number_id'] ?? '');
    $settings['sender_phone_label'] = is_array($account)
        ? eventosapp_whatsapp_sanitize_phone_account_label($account['alias'] ?? '', 'Número WhatsApp')
        : eventosapp_whatsapp_sanitize_phone_account_label($settings['phone_number_label'] ?? '', 'Número WhatsApp');
    $settings['_resolved_sender_account'] = is_array($account) ? $account : [];

    return $settings;
}

/**
 * Aplica a los settings un Phone Number ID específico, usado por la prueba rápida.
 */
function eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id($phone_number_id = '', $settings = null) {
    $settings = is_array($settings) ? wp_parse_args($settings, eventosapp_whatsapp_default_settings()) : eventosapp_whatsapp_get_settings();
    $accounts = eventosapp_whatsapp_get_phone_accounts($settings);
    $phone_number_id = eventosapp_whatsapp_sanitize_phone_number_id($phone_number_id);

    if ( $phone_number_id === '' || ! isset($accounts[$phone_number_id]) ) {
        $phone_number_id = eventosapp_whatsapp_sanitize_phone_number_id($settings['phone_number_id'] ?? '');
    }

    if ( $phone_number_id !== '' && isset($accounts[$phone_number_id]) ) {
        $settings['phone_number_id'] = $phone_number_id;
        $settings['sender_phone_number_id'] = $phone_number_id;
        $settings['sender_phone_label'] = eventosapp_whatsapp_sanitize_phone_account_label($accounts[$phone_number_id]['alias'] ?? '', 'Número WhatsApp');
        $settings['_resolved_sender_account'] = $accounts[$phone_number_id];
    }

    return $settings;
}

/**
 * Sanitiza los números adicionales enviados desde la pantalla de settings.
 */
function eventosapp_whatsapp_sanitize_phone_accounts_from_request($default_phone_number_id = '') {
    $aliases = isset($_POST['phone_accounts_alias']) && is_array($_POST['phone_accounts_alias']) ? wp_unslash($_POST['phone_accounts_alias']) : [];
    $phone_ids = isset($_POST['phone_accounts_phone_number_id']) && is_array($_POST['phone_accounts_phone_number_id']) ? wp_unslash($_POST['phone_accounts_phone_number_id']) : [];
    $raw_accounts = [];
    $count = max(count($aliases), count($phone_ids));

    for ( $i = 0; $i < $count; $i++ ) {
        $raw_accounts[] = [
            'alias'           => $aliases[$i] ?? '',
            'phone_number_id' => $phone_ids[$i] ?? '',
        ];
    }

    return eventosapp_whatsapp_normalize_phone_accounts($raw_accounts, $default_phone_number_id);
}

/**
 * Obtiene settings con fallback seguro.
 */
function eventosapp_whatsapp_get_settings() {
    $saved = get_option(EVENTOSAPP_WHATSAPP_OPTION, []);
    if ( ! is_array($saved) ) {
        $saved = [];
    }

    $settings = wp_parse_args($saved, eventosapp_whatsapp_default_settings());
    $settings['phone_number_id'] = eventosapp_whatsapp_sanitize_phone_number_id($settings['phone_number_id'] ?? '');
    $settings['phone_number_label'] = eventosapp_whatsapp_sanitize_phone_account_label($settings['phone_number_label'] ?? '', 'EventosApp');
    $settings['phone_accounts'] = eventosapp_whatsapp_normalize_phone_accounts($settings['phone_accounts'] ?? [], $settings['phone_number_id']);

    $available_accounts = eventosapp_whatsapp_get_phone_accounts($settings);
    $test_phone_number_id = eventosapp_whatsapp_sanitize_phone_number_id($settings['test_phone_number_id'] ?? '');
    if ( $test_phone_number_id !== '' && ! isset($available_accounts[$test_phone_number_id]) ) {
        $test_phone_number_id = '';
    }
    $settings['test_phone_number_id'] = $test_phone_number_id;

    if ( empty($settings['webhook_verify_token']) ) {
        $settings['webhook_verify_token'] = wp_generate_password(32, false, false);
    }

    return $settings;
}

/**
 * Guarda logs solo cuando está activo el modo debug.
 */
function eventosapp_whatsapp_log($message, $context = []) {
    $settings = eventosapp_whatsapp_get_settings();
    if ( isset($settings['debug_log']) && $settings['debug_log'] === '1' ) {
        $line = 'EVENTOSAPP WHATSAPP | ' . $message;
        if ( ! empty($context) ) {
            $line .= ' | ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        error_log($line);
    }
}

/**
 * Reduce y sanea estructuras antes de guardarlas en logs administrativos.
 * No guarda tokens, headers Authorization ni textos extensos completos.
 */
function eventosapp_whatsapp_sanitize_log_context($value, $depth = 0) {
    if ( $depth > 5 ) {
        return '[profundidad_limitada]';
    }

    if ( is_array($value) ) {
        $clean = [];
        foreach ( $value as $key => $item ) {
            $clean_key = is_scalar($key) ? (string) $key : 'key';
            $key_lc = strtolower($clean_key);
            if ( strpos($key_lc, 'token') !== false || strpos($key_lc, 'authorization') !== false || strpos($key_lc, 'bearer') !== false || strpos($key_lc, 'secret') !== false ) {
                $clean[$clean_key] = '[redactado]';
                continue;
            }
            $clean[$clean_key] = eventosapp_whatsapp_sanitize_log_context($item, $depth + 1);
        }
        return $clean;
    }

    if ( is_object($value) ) {
        return eventosapp_whatsapp_sanitize_log_context((array) $value, $depth + 1);
    }

    if ( is_bool($value) || is_int($value) || is_float($value) || $value === null ) {
        return $value;
    }

    $text = sanitize_text_field((string) $value);
    if ( function_exists('mb_strlen') && mb_strlen($text) > 1200 ) {
        return mb_substr($text, 0, 1200) . '... [recortado]';
    }
    if ( strlen($text) > 1200 ) {
        return substr($text, 0, 1200) . '... [recortado]';
    }
    return $text;
}

/**
 * Registra actividad de WhatsApp en base de datos y, si está activo, en wp-debug.log.
 */
function eventosapp_whatsapp_add_activity_log($event, $context = []) {
    $log = get_option(EVENTOSAPP_WHATSAPP_ACTIVITY_LOG_OPTION, []);
    if ( ! is_array($log) ) {
        $log = [];
    }

    $entry = [
        'date'    => current_time('mysql'),
        'event'   => sanitize_text_field((string) $event),
        'context' => eventosapp_whatsapp_sanitize_log_context($context),
    ];

    $log[] = $entry;
    if ( count($log) > 300 ) {
        $log = array_slice($log, -300);
    }

    update_option(EVENTOSAPP_WHATSAPP_ACTIVITY_LOG_OPTION, $log, false);
    eventosapp_whatsapp_log($event, $entry['context']);
}

/**
 * Obtiene las últimas actividades globales de WhatsApp.
 */
function eventosapp_whatsapp_get_activity_log($limit = 50) {
    $log = get_option(EVENTOSAPP_WHATSAPP_ACTIVITY_LOG_OPTION, []);
    if ( ! is_array($log) ) {
        return [];
    }
    $log = array_reverse($log);
    return array_slice($log, 0, max(1, absint($limit)));
}

/**
 * Imagen base del sistema para encabezados de tickets cuando no existe una personalizada.
 */
function eventosapp_whatsapp_system_default_header_image() {
    return esc_url_raw(apply_filters(
        'eventosapp_whatsapp_system_default_header_image',
        'https://eventosapp.com/wp-content/uploads/2025/08/header_ticket_gen.jpg'
    ));
}

/**
 * Medidas oficiales para la imagen compuesta que se envía por WhatsApp en tickets presenciales.
 * El cabezote del QR debe prepararse exactamente en 1000 x 160 px para que no se recorte.
 */
if ( ! defined('EVENTOSAPP_WHATSAPP_QR_CANVAS_WIDTH') ) {
    define('EVENTOSAPP_WHATSAPP_QR_CANVAS_WIDTH', 1000);
}
if ( ! defined('EVENTOSAPP_WHATSAPP_QR_CANVAS_HEIGHT') ) {
    define('EVENTOSAPP_WHATSAPP_QR_CANVAS_HEIGHT', 1000);
}
if ( ! defined('EVENTOSAPP_WHATSAPP_QR_HEADER_WIDTH') ) {
    define('EVENTOSAPP_WHATSAPP_QR_HEADER_WIDTH', 1000);
}
if ( ! defined('EVENTOSAPP_WHATSAPP_QR_HEADER_HEIGHT') ) {
    define('EVENTOSAPP_WHATSAPP_QR_HEADER_HEIGHT', 160);
}
if ( ! defined('EVENTOSAPP_WHATSAPP_QR_IMAGE_SIZE') ) {
    define('EVENTOSAPP_WHATSAPP_QR_IMAGE_SIZE', 760);
}
if ( ! defined('EVENTOSAPP_WHATSAPP_QR_LAYOUT_VERSION') ) {
    define('EVENTOSAPP_WHATSAPP_QR_LAYOUT_VERSION', 'v3-1000x1000-header-1000x160-contain');
}

/**
 * Lee una imagen por defecto guardada en el módulo Plantillas WhatsApp, si el archivo está cargado.
 */
function eventosapp_whatsapp_get_template_default_image($key) {
    $key = sanitize_key((string) $key);
    if ( $key === '' || ! function_exists('eventosapp_whatsapp_templates_get_settings') ) {
        return '';
    }

    $settings = eventosapp_whatsapp_templates_get_settings();
    if ( empty($settings[$key]) ) {
        return '';
    }

    return esc_url_raw((string) $settings[$key]);
}

/**
 * Obtiene el branding efectivo de variante aplicado al ticket.
 *
 * Las variantes de ticket guardan principalmente overrides usados por el correo
 * (_eventosapp_ticket_email_header_image_url y colores). WhatsApp debe leer esos
 * mismos metadatos para que la landing pública y la imagen enviada no vuelvan al
 * branding base del evento cuando el ticket cumple una variante.
 */
function eventosapp_whatsapp_get_ticket_variant_branding($ticket_id) {
    $ticket_id = absint($ticket_id);
    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        return [
            'variant_key'       => '',
            'variant_name'      => '',
            'header_image_url'  => '',
            'heading_color'     => '',
            'subheading_color'  => '',
            'text_color'        => '',
            'has_variant'       => false,
        ];
    }

    $variant_key  = sanitize_key((string) get_post_meta($ticket_id, '_eventosapp_ticket_variant_key', true));
    $variant_name = sanitize_text_field((string) get_post_meta($ticket_id, '_eventosapp_ticket_variant_name', true));

    $header_image = esc_url_raw((string) get_post_meta($ticket_id, '_eventosapp_ticket_email_header_image_url', true));
    $heading      = sanitize_hex_color((string) get_post_meta($ticket_id, '_eventosapp_ticket_email_heading_color', true));
    $subheading   = sanitize_hex_color((string) get_post_meta($ticket_id, '_eventosapp_ticket_email_subheading_color', true));
    $text         = sanitize_hex_color((string) get_post_meta($ticket_id, '_eventosapp_ticket_email_text_color', true));

    return [
        'variant_key'       => $variant_key,
        'variant_name'      => $variant_name,
        'header_image_url'  => $header_image,
        'heading_color'     => $heading ?: '',
        'subheading_color'  => $subheading ?: '',
        'text_color'        => $text ?: '',
        'has_variant'       => ($variant_key !== '' || $variant_name !== ''),
    ];
}

/**
 * Resuelve las imágenes efectivas para la landing, el cabezote del QR y los mensajes virtuales.
 * Orden landing: variante > evento > respaldo legacy del ticket > cabezote de email del evento > valor por defecto > sistema.
 * Orden QR WhatsApp: variante > evento > respaldo legacy del ticket > valor por defecto > cabezote de email > sistema.
 * Orden virtual: variante > evento > respaldo legacy del ticket > valor por defecto > landing resuelta > sistema.
 */
function eventosapp_whatsapp_resolve_ticket_visual_images($ticket_id, $event_id = 0) {
    $ticket_id = absint($ticket_id);
    $event_id  = absint($event_id ?: ( $ticket_id ? get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true) : 0 ));

    $system_default = eventosapp_whatsapp_system_default_header_image();
    $email_header   = $event_id ? esc_url_raw((string) get_post_meta($event_id, '_eventosapp_email_header_img', true)) : '';

    // Branding de variante: se toma del mismo metadato que usa el correo del ticket.
    // Esto garantiza que la landing pública de WhatsApp y la imagen del QR reflejen
    // la variante efectiva del ticket cuando aplique.
    $variant_branding = eventosapp_whatsapp_get_ticket_variant_branding($ticket_id);
    $variant_header   = ! empty($variant_branding['header_image_url']) ? esc_url_raw((string) $variant_branding['header_image_url']) : '';

    // Configuración correcta: estas imágenes pertenecen al evento, no al ticket.
    $event_landing_header = $event_id ? esc_url_raw((string) get_post_meta($event_id, '_eventosapp_whatsapp_landing_header_img', true)) : '';
    $event_qr_header      = $event_id ? esc_url_raw((string) get_post_meta($event_id, '_eventosapp_whatsapp_qr_header_img', true)) : '';
    $event_virtual_image  = $event_id ? esc_url_raw((string) get_post_meta($event_id, '_eventosapp_whatsapp_virtual_message_img', true)) : '';

    // Compatibilidad hacia atrás: si ya existían valores guardados en tickets antiguos,
    // se usan únicamente como respaldo cuando el evento todavía no tiene configuración.
    $legacy_ticket_landing_header = $ticket_id ? esc_url_raw((string) get_post_meta($ticket_id, '_eventosapp_whatsapp_landing_header_img', true)) : '';
    $legacy_ticket_qr_header      = $ticket_id ? esc_url_raw((string) get_post_meta($ticket_id, '_eventosapp_whatsapp_qr_header_img', true)) : '';
    $legacy_ticket_virtual_image  = $ticket_id ? esc_url_raw((string) get_post_meta($ticket_id, '_eventosapp_whatsapp_virtual_message_img', true)) : '';

    $default_qr_header     = eventosapp_whatsapp_get_template_default_image('default_qr_header_image');
    $default_virtual_image = eventosapp_whatsapp_get_template_default_image('default_virtual_message_image');

    $landing_header = $variant_header ?: $event_landing_header ?: $legacy_ticket_landing_header ?: $email_header ?: $default_qr_header ?: $system_default;
    $qr_header      = $variant_header ?: $event_qr_header ?: $legacy_ticket_qr_header ?: $default_qr_header ?: $email_header ?: $system_default;
    $virtual_image  = $variant_header ?: $event_virtual_image ?: $legacy_ticket_virtual_image ?: $default_virtual_image ?: $landing_header ?: $system_default;

    return [
        'landing_header'             => esc_url_raw($landing_header),
        'qr_header'                  => esc_url_raw($qr_header),
        'virtual_message_image'      => esc_url_raw($virtual_image),
        'variant_branding'           => $variant_branding,
        'variant_header'             => esc_url_raw($variant_header),
        'event_landing_override'     => $event_landing_header,
        'event_qr_override'          => $event_qr_header,
        'event_virtual_override'     => $event_virtual_image,
        'ticket_landing_override'    => $legacy_ticket_landing_header,
        'ticket_qr_override'         => $legacy_ticket_qr_header,
        'ticket_virtual_override'    => $legacy_ticket_virtual_image,
        'event_email_header'         => $email_header,
        'default_qr_header'          => $default_qr_header,
        'default_virtual_image'      => $default_virtual_image,
        'system_default'             => $system_default,
    ];
}

function eventosapp_whatsapp_get_landing_header_image($ticket_id, $event_id = 0) {
    $visuals = eventosapp_whatsapp_resolve_ticket_visual_images($ticket_id, $event_id);
    return $visuals['landing_header'];
}

function eventosapp_whatsapp_get_qr_header_image($ticket_id, $event_id = 0) {
    $visuals = eventosapp_whatsapp_resolve_ticket_visual_images($ticket_id, $event_id);
    return $visuals['qr_header'];
}

function eventosapp_whatsapp_get_virtual_message_image($ticket_id, $event_id = 0) {
    $visuals = eventosapp_whatsapp_resolve_ticket_visual_images($ticket_id, $event_id);
    return $visuals['virtual_message_image'];
}


/**
 * Slug público recomendado para la página donde se mostrará el ticket de WhatsApp.
 * Por defecto se usa /ticket/ para evitar enlaces con /wp-admin/admin-post.php.
 */
function eventosapp_whatsapp_public_ticket_page_slug() {
    $slug = apply_filters('eventosapp_whatsapp_public_ticket_page_slug', 'ticket');
    $slug = sanitize_title((string) $slug);
    return $slug !== '' ? $slug : 'ticket';
}

/**
 * Construye URLs públicas frontales para botones de WhatsApp.
 *
 * ticket_landing: /ticket/?ticket={{1}}
 * ticket_ics: /ticket/?eventosapp_whatsapp_public_action=ticket_ics&ticket={{1}}
 * virtual_access: /ticket/?eventosapp_whatsapp_public_action=virtual_access&ticket={{1}}
 */
function eventosapp_whatsapp_public_action_url($action, $ticket_public = '{{1}}') {
    $action = sanitize_key((string) $action);
    if ( ! in_array($action, ['ticket_landing', 'ticket_ics', 'virtual_access'], true) ) {
        $action = 'ticket_landing';
    }

    $ticket_public = (string) $ticket_public;
    $base_url = trailingslashit(home_url('/' . eventosapp_whatsapp_public_ticket_page_slug()));

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

    return str_replace(
        ['%7B%7B1%7D%7D', '%7b%7b1%7d%7d', rawurlencode('{{1}}')],
        '{{1}}',
        $url
    );
}

/**
 * URL pública para la landing del ticket.
 */
function eventosapp_whatsapp_public_ticket_landing_url($ticket_public = '{{1}}') {
    return eventosapp_whatsapp_public_action_url('ticket_landing', $ticket_public);
}

/**
 * Busca un ticket por código público sin exigir sesión iniciada.
 */
function eventosapp_whatsapp_find_ticket_by_public_code($public_code) {
    $public_code = sanitize_text_field((string) $public_code);
    if ( $public_code === '' ) {
        return 0;
    }

    if ( function_exists('eventosapp_find_ticket_by_public_id') ) {
        $ticket_id = absint(eventosapp_find_ticket_by_public_id($public_code));
        if ( $ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket' ) {
            return $ticket_id;
        }
    }

    $query = new WP_Query([
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [
            'relation' => 'OR',
            [
                'key'     => 'eventosapp_ticketID',
                'value'   => $public_code,
                'compare' => '=',
            ],
            [
                'key'     => '_eventosapp_ticket_public_id',
                'value'   => $public_code,
                'compare' => '=',
            ],
        ],
    ]);

    if ( ! empty($query->posts[0]) ) {
        return absint($query->posts[0]);
    }

    // Respaldo para tickets antiguos que no tengan código público guardado.
    if ( ctype_digit($public_code) && get_post_type(absint($public_code)) === 'eventosapp_ticket' ) {
        return absint($public_code);
    }

    return 0;
}

/**
 * Detecta si la solicitud actual corresponde a la ruta pública /ticket/.
 */
function eventosapp_whatsapp_get_public_ticket_route_context() {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    $request_path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
    $request_path = trim($request_path, '/');

    $home_path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
    $home_path = trim($home_path, '/');
    if ( $home_path !== '' && $request_path !== $home_path ) {
        if ( strpos($request_path, $home_path . '/') === 0 ) {
            $request_path = substr($request_path, strlen($home_path) + 1);
        }
    }

    $slug = eventosapp_whatsapp_public_ticket_page_slug();
    $is_ticket_route = ($request_path === $slug || strpos($request_path, $slug . '/') === 0);
    $ticket_from_path = '';

    if ( $is_ticket_route ) {
        $parts = array_values(array_filter(explode('/', $request_path), static function($part) {
            return $part !== '';
        }));
        if ( isset($parts[1]) ) {
            $ticket_from_path = sanitize_text_field(rawurldecode((string) $parts[1]));
        }
    }

    return [
        'is_ticket_route' => $is_ticket_route,
        'ticket_from_path' => $ticket_from_path,
        'slug' => $slug,
    ];
}

/**
 * Resuelve el ticket solicitado desde query string, ruta /ticket/{codigo} o request legacy.
 */
function eventosapp_whatsapp_resolve_public_ticket_from_request($source = null) {
    $source = is_array($source) ? $source : $_REQUEST;
    $public = '';

    foreach ( ['ticket', 'ticket_pub', 'public_id', 'ticketID', 'ticket_id_public'] as $key ) {
        if ( isset($source[$key]) && $source[$key] !== '' ) {
            $public = sanitize_text_field(wp_unslash($source[$key]));
            break;
        }
    }

    if ( $public === '' ) {
        $route_context = eventosapp_whatsapp_get_public_ticket_route_context();
        if ( ! empty($route_context['ticket_from_path']) ) {
            $public = $route_context['ticket_from_path'];
        }
    }

    if ( $public !== '' ) {
        return eventosapp_whatsapp_find_ticket_by_public_code($public);
    }

    $ticket_id = isset($source['ticket_id']) ? absint($source['ticket_id']) : 0;
    if ( $ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket' && current_user_can('edit_post', $ticket_id) ) {
        return $ticket_id;
    }

    return 0;
}

/**
 * Obtiene URLs útiles del ticket para la landing pública de WhatsApp.
 */
function eventosapp_whatsapp_get_public_ticket_assets($ticket_id) {
    $ticket_id = absint($ticket_id);
    $event_id = $ticket_id ? absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true)) : 0;
    $is_virtual = function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id);

    $qr_url = function_exists('eventosapp_whatsapp_ensure_qr_url') ? eventosapp_whatsapp_ensure_qr_url($ticket_id) : '';

    $ics_url = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
    if ( ! $ics_url && function_exists('eventosapp_ticket_generar_ics') ) {
        eventosapp_ticket_generar_ics($ticket_id);
        $ics_url = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
    }

    $pdf_url = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
    if ( ! $pdf_url && function_exists('eventosapp_ticket_generar_pdf') && ! $is_virtual ) {
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
    $virtual_use_landing = $event_id && function_exists('eventosapp_virtual_landing_whatsapp_use_landing')
        ? eventosapp_virtual_landing_whatsapp_use_landing($event_id)
        : true;
    $virtual_access_url = '';
    if ( function_exists('eventosapp_get_virtual_whatsapp_access_url') ) {
        $virtual_access_url = eventosapp_get_virtual_whatsapp_access_url($ticket_id);
    } else {
        $virtual_access_url = $virtual_use_landing && $virtual_landing ? $virtual_landing : $platform_url;
        if ( ! $virtual_access_url ) {
            $virtual_access_url = $virtual_landing;
        }
    }

    $landing_header = eventosapp_whatsapp_get_landing_header_image($ticket_id, $event_id);

    return [
        'qr'                  => esc_url_raw($qr_url),
        'landing_header'      => esc_url_raw($landing_header),
        'ics'                 => esc_url_raw($ics_url),
        'pdf'                 => esc_url_raw($pdf_url),
        'google_wallet'       => esc_url_raw($google_wallet),
        'apple_wallet'        => esc_url_raw($apple_wallet),
        'virtual_landing'     => esc_url_raw($virtual_landing),
        'platform_url'        => esc_url_raw($platform_url),
        'virtual_access_url'  => esc_url_raw($virtual_access_url),
        'virtual_use_landing' => $virtual_use_landing ? 1 : 0,
    ];
}

/**
 * CSS del widget/landing pública de ticket WhatsApp.
 */
function eventosapp_whatsapp_public_ticket_styles() {
    return '<style>
        .evapp-wa-ticket-public{box-sizing:border-box;width:100%;}
        .evapp-wa-ticket-public *{box-sizing:border-box;}
        .evapp-wa-ticket-public .evapp-ticket-wrap{max-width:760px;margin:0 auto;padding:28px 16px;}
        .evapp-wa-ticket-public .evapp-ticket-card{background:#fff;border-radius:20px;box-shadow:0 14px 42px rgba(15,23,42,.11);overflow:hidden;border:1px solid #e5e7eb;}
        .evapp-wa-ticket-public .evapp-ticket-header{background:#0f172a;}
        .evapp-wa-ticket-public .evapp-ticket-header img{display:block;width:100%;height:auto;max-height:190px;object-fit:cover;}
        .evapp-wa-ticket-public .evapp-ticket-body{padding:26px 28px 8px;}
        .evapp-wa-ticket-public .evapp-ticket-title{margin:0 0 8px;font-size:28px;line-height:1.18;color:#111827;font-weight:800;}
        .evapp-wa-ticket-public .evapp-ticket-subtitle{margin:0 0 18px;color:#64748b;font-size:15px;line-height:1.45;}
        .evapp-wa-ticket-public .evapp-ticket-variant-badge{display:inline-flex;align-items:center;gap:6px;margin:0 0 14px;padding:6px 10px;border-radius:999px;background:#ecfdf5;color:#047857;border:1px solid #bbf7d0;font-size:12px;font-weight:700;}
        .evapp-wa-ticket-public .evapp-ticket-media{text-align:center;margin:18px 0 22px;}
        .evapp-wa-ticket-public .evapp-ticket-media img{max-width:330px;width:100%;height:auto;border:1px solid #e5e7eb;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.08);}
        .evapp-wa-ticket-public .evapp-ticket-kvs{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px;margin:16px 0;}
        .evapp-wa-ticket-public .evapp-ticket-kv{display:flex;gap:10px;padding:7px 0;border-bottom:1px solid rgba(226,232,240,.9);line-height:1.45;color:#111827;}
        .evapp-wa-ticket-public .evapp-ticket-kv:last-child{border-bottom:0;}
        .evapp-wa-ticket-public .evapp-ticket-kv b{min-width:120px;color:#0f172a;}
        .evapp-wa-ticket-public .evapp-ticket-actions{padding:20px 28px 28px;background:#f8fafc;border-top:1px solid #e5e7eb;text-align:center;}
        .evapp-wa-ticket-public .evapp-ticket-wallets{display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;margin:0 0 14px;}
        .evapp-wa-ticket-public .evapp-ticket-wallets img{display:block;width:200px;max-width:100%;height:auto;}
        .evapp-wa-ticket-public .evapp-ticket-buttons{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;}
        .evapp-wa-ticket-public .evapp-ticket-button{display:inline-block;text-align:center;text-decoration:none;background:#111827;color:#fff!important;padding:13px 18px;border-radius:12px;font-weight:700;min-width:170px;box-sizing:border-box;line-height:1.2;}
        .evapp-wa-ticket-public .evapp-ticket-button.secondary{background:#2563eb;}
        .evapp-wa-ticket-public .evapp-ticket-button.neutral{background:#475569;}
        .evapp-wa-ticket-public .evapp-ticket-button.success{background:#16a34a;}
        .evapp-wa-ticket-public .evapp-ticket-small{font-size:12px;color:#64748b;margin:16px 0 0;text-align:center;line-height:1.4;}
        .evapp-wa-ticket-public .evapp-ticket-empty{max-width:760px;margin:0 auto;padding:24px 18px;background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;color:#9a3412;line-height:1.5;}
        @media(max-width:520px){.evapp-wa-ticket-public .evapp-ticket-wrap{padding:18px 12px}.evapp-wa-ticket-public .evapp-ticket-body{padding:22px 18px 8px}.evapp-wa-ticket-public .evapp-ticket-actions{padding:18px}.evapp-wa-ticket-public .evapp-ticket-title{font-size:24px}.evapp-wa-ticket-public .evapp-ticket-kv{display:block}.evapp-wa-ticket-public .evapp-ticket-kv b{display:block;margin-bottom:2px}.evapp-wa-ticket-public .evapp-ticket-button{width:100%;}.evapp-wa-ticket-public .evapp-ticket-wallets a{width:100%;display:flex;justify-content:center;}}
    </style>';
}

/**
 * Renderiza el contenido del ticket. Se usa tanto en shortcode/Elementor como en fallback standalone.
 */
function eventosapp_whatsapp_render_public_ticket_landing_content($ticket_id = 0, $args = []) {
    $ticket_id = absint($ticket_id ?: eventosapp_whatsapp_resolve_public_ticket_from_request());
    $args = is_array($args) ? $args : [];
    $show_styles = ! isset($args['show_styles']) || $args['show_styles'];

    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        return ($show_styles ? eventosapp_whatsapp_public_ticket_styles() : '') . '<div class="evapp-wa-ticket-public"><div class="evapp-ticket-empty"><strong>Ticket no encontrado.</strong><br>Verifica que el enlace recibido por WhatsApp esté completo.</div></div>';
    }

    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $assets = eventosapp_whatsapp_get_public_ticket_assets($ticket_id);
    $variant_branding = eventosapp_whatsapp_get_ticket_variant_branding($ticket_id);
    $title_style = ! empty($variant_branding['heading_color']) ? ' style="color:' . esc_attr($variant_branding['heading_color']) . ';"' : '';
    $subtitle_style = ! empty($variant_branding['subheading_color']) ? ' style="color:' . esc_attr($variant_branding['subheading_color']) . ';"' : '';
    $text_style = ! empty($variant_branding['text_color']) ? ' style="color:' . esc_attr($variant_branding['text_color']) . ';"' : '';
    $nombre = trim(get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' . get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true));
    $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
    if ( ! $ticket_code ) {
        $ticket_code = eventosapp_whatsapp_get_ticket_public_code($ticket_id);
    }

    $modalidad = function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label($ticket_id) : get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
    $is_virtual = function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id);
    $fecha = function_exists('eventosapp_whatsapp_get_event_date_label') ? eventosapp_whatsapp_get_event_date_label($event_id) : '';
    $hora_inicio = $event_id ? get_post_meta($event_id, '_eventosapp_hora_inicio', true) : '';
    $hora_cierre = $event_id ? get_post_meta($event_id, '_eventosapp_hora_cierre', true) : '';
    $direccion = $event_id ? get_post_meta($event_id, '_eventosapp_direccion', true) : '';
    $organizador = $event_id ? (function_exists('eventosapp_get_nombre_organizador') ? eventosapp_get_nombre_organizador($event_id) : get_post_meta($event_id, '_eventosapp_organizador', true)) : '';
    $platform = $event_id ? get_post_meta($event_id, '_eventosapp_virtual_platform', true) : '';
    $event_title = $event_id ? get_the_title($event_id) : 'Ticket';

    $wallet_google_img = function_exists('eventosapp_asset_url_with_version') ? eventosapp_asset_url_with_version('assets/graphics/wallet_icons/google_wallet_btn.png') : '';
    $wallet_apple_img  = function_exists('eventosapp_asset_url_with_version') ? eventosapp_asset_url_with_version('assets/graphics/wallet_icons/apple_wallet_btn.png') : '';

    ob_start();
    echo $show_styles ? eventosapp_whatsapp_public_ticket_styles() : '';
    ?>
    <div class="evapp-wa-ticket-public">
        <main class="evapp-ticket-wrap">
            <section class="evapp-ticket-card">
                <?php if ( ! empty($assets['landing_header']) ) : ?>
                    <div class="evapp-ticket-header"><img src="<?php echo esc_url($assets['landing_header']); ?>" alt="<?php echo esc_attr($event_title); ?>"></div>
                <?php endif; ?>

                <div class="evapp-ticket-body">
                    <?php if ( ! empty($variant_branding['variant_name']) ) : ?>
                        <div class="evapp-ticket-variant-badge">✨ <?php echo esc_html($variant_branding['variant_name']); ?></div>
                    <?php endif; ?>
                    <h1 class="evapp-ticket-title"<?php echo $title_style; ?>><?php echo esc_html($event_title); ?></h1>
                    <p class="evapp-ticket-subtitle"<?php echo $subtitle_style; ?>>Tu inscripción está confirmada. Conserva esta página para consultar los enlaces principales del ticket.</p>

                    <?php if ( ! empty($assets['qr']) && ! $is_virtual ) : ?>
                        <div class="evapp-ticket-media">
                            <img src="<?php echo esc_url($assets['qr']); ?>" alt="QR de ingreso">
                        </div>
                    <?php endif; ?>

                    <div class="evapp-ticket-kvs"<?php echo $text_style; ?>>
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
                        <?php if ( $is_virtual && ! empty($assets['virtual_access_url']) ) : ?><a class="evapp-ticket-button success" href="<?php echo esc_url($assets['virtual_access_url']); ?>" target="_blank" rel="noopener noreferrer">Ingresar al evento virtual</a><?php endif; ?>
                        <?php if ( ! empty($assets['ics']) ) : ?><a class="evapp-ticket-button secondary" href="<?php echo esc_url($assets['ics']); ?>" target="_blank" rel="noopener noreferrer">Agregar a agenda</a><?php endif; ?>
                        <?php if ( ! empty($assets['pdf']) ) : ?><a class="evapp-ticket-button neutral" href="<?php echo esc_url($assets['pdf']); ?>" target="_blank" rel="noopener noreferrer">Descargar PDF</a><?php endif; ?>
                    </div>

                    <p class="evapp-ticket-small">Este enlace pertenece a EventosApp. No compartas tu ticket con terceros.</p>
                </div>
            </section>
        </main>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Shortcode para usar en una página pública, recomendado en /ticket/.
 * Uso: [eventosapp_whatsapp_ticket]
 */
function eventosapp_whatsapp_public_ticket_shortcode($atts = []) {
    $atts = shortcode_atts([
        'ticket' => '',
    ], is_array($atts) ? $atts : [], 'eventosapp_whatsapp_ticket');

    $ticket_id = 0;
    if ( ! empty($atts['ticket']) ) {
        $ticket_id = eventosapp_whatsapp_find_ticket_by_public_code($atts['ticket']);
    }

    if ( ! $ticket_id ) {
        $ticket_id = eventosapp_whatsapp_resolve_public_ticket_from_request();
    }

    return eventosapp_whatsapp_render_public_ticket_landing_content($ticket_id);
}
add_shortcode('eventosapp_whatsapp_ticket', 'eventosapp_whatsapp_public_ticket_shortcode');
add_shortcode('eventosapp_ticket_whatsapp', 'eventosapp_whatsapp_public_ticket_shortcode');

/**
 * Página standalone de respaldo para enlaces públicos del ticket.
 */
function eventosapp_whatsapp_render_public_ticket_landing_page($ticket_id = 0) {
    $ticket_id = absint($ticket_id ?: eventosapp_whatsapp_resolve_public_ticket_from_request());
    $event_id = $ticket_id ? absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true)) : 0;
    $event_title = $event_id ? get_the_title($event_id) : 'Ticket';

    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow', true);
    header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
    if ( ! $ticket_id ) {
        status_header(404);
    }
    ?>
    <!doctype html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,nofollow">
        <title><?php echo esc_html($event_title); ?> - Ticket</title>
        <style>body{margin:0;background:#eef2f7;color:#111827;font-family:Arial,Helvetica,sans-serif;}</style>
    </head>
    <body>
        <?php echo eventosapp_whatsapp_render_public_ticket_landing_content($ticket_id); ?>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Redirecciona el admin-post legacy de Meta a la URL pública /ticket/.
 */
function eventosapp_whatsapp_redirect_legacy_ticket_landing_to_public_page() {
    $ticket_id = eventosapp_whatsapp_resolve_public_ticket_from_request();
    if ( ! $ticket_id ) {
        eventosapp_whatsapp_render_public_ticket_landing_page(0);
    }

    $ticket_public = eventosapp_whatsapp_get_ticket_public_code($ticket_id);
    wp_safe_redirect(eventosapp_whatsapp_public_ticket_landing_url($ticket_public), 302);
    exit;
}
add_action('admin_post_nopriv_eventosapp_whatsapp_ticket_landing', 'eventosapp_whatsapp_redirect_legacy_ticket_landing_to_public_page', 0);
add_action('admin_post_eventosapp_whatsapp_ticket_landing', 'eventosapp_whatsapp_redirect_legacy_ticket_landing_to_public_page', 0);

/**
 * Redirige al archivo ICS público del ticket.
 */
function eventosapp_whatsapp_redirect_public_ticket_ics() {
    $ticket_id = eventosapp_whatsapp_resolve_public_ticket_from_request();
    if ( ! $ticket_id ) {
        status_header(404);
        wp_die('Ticket no encontrado.');
    }

    $assets = eventosapp_whatsapp_get_public_ticket_assets($ticket_id);
    if ( empty($assets['ics']) ) {
        status_header(404);
        wp_die('No se encontró archivo ICS para este ticket.');
    }

    wp_safe_redirect($assets['ics']);
    exit;
}
add_action('admin_post_nopriv_eventosapp_whatsapp_ticket_ics', 'eventosapp_whatsapp_redirect_public_ticket_ics', 0);
add_action('admin_post_eventosapp_whatsapp_ticket_ics', 'eventosapp_whatsapp_redirect_public_ticket_ics', 0);

/**
 * Redirige al acceso virtual público del ticket.
 */
function eventosapp_whatsapp_redirect_public_virtual_access() {
    $ticket_id = eventosapp_whatsapp_resolve_public_ticket_from_request();
    if ( ! $ticket_id ) {
        status_header(404);
        wp_die('Ticket no encontrado.');
    }

    $assets = eventosapp_whatsapp_get_public_ticket_assets($ticket_id);
    $target = ! empty($assets['virtual_access_url']) ? $assets['virtual_access_url'] : (! empty($assets['virtual_landing']) ? $assets['virtual_landing'] : $assets['platform_url']);

    if ( empty($target) ) {
        status_header(404);
        wp_die('No se encontró enlace virtual para este ticket.');
    }

    if ( ! empty($assets['platform_url']) && $target === $assets['platform_url'] && function_exists('eventosapp_register_virtual_checkin') ) {
        eventosapp_register_virtual_checkin($ticket_id, [
            'origen'     => 'whatsapp-virtual-access-direct',
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);
    }

    wp_redirect(esc_url_raw($target), 302);
    exit;
}
add_action('admin_post_nopriv_eventosapp_whatsapp_virtual_access', 'eventosapp_whatsapp_redirect_public_virtual_access', 0);
add_action('admin_post_eventosapp_whatsapp_virtual_access', 'eventosapp_whatsapp_redirect_public_virtual_access', 0);

/**
 * Router frontal público. Permite que /ticket/?ticket=... funcione incluso si la página no existe,
 * y procesa acciones públicas como ICS o acceso virtual sin usar /wp-admin/.
 */
function eventosapp_whatsapp_public_ticket_template_redirect() {
    if ( is_admin() ) {
        return;
    }

    $action = isset($_GET['eventosapp_whatsapp_public_action']) ? sanitize_key(wp_unslash($_GET['eventosapp_whatsapp_public_action'])) : '';

    if ( $action === 'ticket_ics' ) {
        eventosapp_whatsapp_redirect_public_ticket_ics();
    }

    if ( $action === 'virtual_access' ) {
        eventosapp_whatsapp_redirect_public_virtual_access();
    }

    if ( $action === 'ticket_landing' ) {
        eventosapp_whatsapp_render_public_ticket_landing_page();
    }

    $route_context = eventosapp_whatsapp_get_public_ticket_route_context();
    if ( empty($route_context['is_ticket_route']) ) {
        return;
    }

    // Si existe la página /ticket/ publicada, se deja cargar para que Elementor o el shortcode rendericen el widget.
    // Si no existe o la ruta es /ticket/{codigo}, se usa el fallback standalone.
    if ( function_exists('is_page') && is_page($route_context['slug']) && ! is_404() && empty($route_context['ticket_from_path']) ) {
        return;
    }

    eventosapp_whatsapp_render_public_ticket_landing_page();
}
add_action('template_redirect', 'eventosapp_whatsapp_public_ticket_template_redirect', 0);

/**
 * Widget Elementor opcional para ubicar la landing en una página pública como /ticket/.
 */
add_action('elementor/widgets/register', function($widgets_manager) {
    if ( ! class_exists('\\Elementor\\Widget_Base') ) {
        return;
    }

    if ( ! class_exists('EventosApp_WhatsApp_Ticket_Public_Widget') ) {
        class EventosApp_WhatsApp_Ticket_Public_Widget extends \Elementor\Widget_Base {
            public function get_name() {
                return 'eventosapp_whatsapp_ticket_public';
            }

            public function get_title() {
                return 'EventosApp Ticket WhatsApp';
            }

            public function get_icon() {
                return 'eicon-ticket';
            }

            public function get_categories() {
                return ['general'];
            }

            protected function render() {
                echo eventosapp_whatsapp_render_public_ticket_landing_content();
            }
        }
    }

    if ( method_exists($widgets_manager, 'register') ) {
        $widgets_manager->register(new EventosApp_WhatsApp_Ticket_Public_Widget());
    } elseif ( method_exists($widgets_manager, 'register_widget_type') ) {
        $widgets_manager->register_widget_type(new EventosApp_WhatsApp_Ticket_Public_Widget());
    }
});

/**
 * Convierte una URL local de uploads a path cuando es posible.
 */
function eventosapp_whatsapp_url_to_local_path($url) {
    $url = esc_url_raw((string) $url);
    if ( $url === '' ) {
        return '';
    }

    // Importante: varias imágenes de EventosApp llevan ?v=timestamp.
    // Ese query string es válido en navegador, pero rompe file_exists() si se
    // intenta convertir la URL directamente a path. Por eso primero trabajamos
    // con la URL limpia y con el path decodificado.
    $clean_url = strtok($url, '?');
    if ( ! is_string($clean_url) || $clean_url === '' ) {
        $clean_url = $url;
    }

    if ( function_exists('eventosapp_url_to_path') ) {
        $path = eventosapp_url_to_path($clean_url);
        if ( $path && file_exists($path) && is_readable($path) ) {
            return $path;
        }
    }

    $upload = wp_upload_dir();
    if ( ! is_array($upload) || ! empty($upload['error']) || empty($upload['basedir']) || empty($upload['baseurl']) ) {
        return '';
    }

    $candidates = [];

    // Caso directo: misma base URL de uploads.
    $upload_baseurl = untrailingslashit((string) $upload['baseurl']);
    if ( strpos($clean_url, $upload_baseurl . '/') === 0 ) {
        $relative = ltrim(substr($clean_url, strlen($upload_baseurl)), '/');
        $candidates[] = trailingslashit($upload['basedir']) . rawurldecode($relative);
    }

    // Caso robusto: compara solo el path para soportar http/https, CDN simple
    // o dominios equivalentes apuntando al mismo wp-content/uploads.
    $url_path    = wp_parse_url($clean_url, PHP_URL_PATH);
    $upload_path = wp_parse_url($upload_baseurl, PHP_URL_PATH);
    if ( is_string($url_path) && $url_path !== '' ) {
        $url_path = '/' . ltrim($url_path, '/');
        $upload_path = is_string($upload_path) && $upload_path !== '' ? '/' . trim($upload_path, '/') : '';

        if ( $upload_path !== '' && strpos($url_path, $upload_path . '/') === 0 ) {
            $relative = ltrim(substr($url_path, strlen($upload_path)), '/');
            $candidates[] = trailingslashit($upload['basedir']) . rawurldecode($relative);
        }

        // Respaldo para instalaciones donde baseurl no coincide exactamente,
        // pero la URL contiene /wp-content/uploads/.
        $marker = '/wp-content/uploads/';
        $pos = strpos($url_path, $marker);
        if ( $pos !== false ) {
            $relative = substr($url_path, $pos + strlen($marker));
            $candidates[] = trailingslashit($upload['basedir']) . rawurldecode(ltrim($relative, '/'));
        }
    }

    foreach ( array_unique(array_filter($candidates)) as $path ) {
        if ( file_exists($path) && is_readable($path) ) {
            return $path;
        }
    }

    return '';
}

/**
 * Crea un recurso GD desde una URL local/remota. Retorna false si GD no está disponible.
 */
function eventosapp_whatsapp_image_resource_from_url($url) {
    $url = esc_url_raw((string) $url);
    if ( $url === '' || ! function_exists('imagecreatefromstring') ) {
        return false;
    }

    $bytes = '';
    $local_path = eventosapp_whatsapp_url_to_local_path($url);
    if ( $local_path ) {
        $bytes = file_get_contents($local_path);
    } else {
        $response = wp_remote_get($url, [
            'timeout'     => 12,
            'redirection' => 3,
            'sslverify'   => false,
        ]);
        if ( is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400 ) {
            return false;
        }
        $bytes = wp_remote_retrieve_body($response);
    }

    if ( ! is_string($bytes) || $bytes === '' ) {
        return false;
    }

    $image = @imagecreatefromstring($bytes);
    return $image ?: false;
}

/**
 * Copia una imagen cubriendo el rectángulo de destino sin deformarla.
 */
function eventosapp_whatsapp_image_cover_copy($dst, $src, $dst_x, $dst_y, $dst_w, $dst_h) {
    $src_w = imagesx($src);
    $src_h = imagesy($src);
    if ( ! $src_w || ! $src_h ) {
        return false;
    }

    $src_ratio = $src_w / $src_h;
    $dst_ratio = $dst_w / $dst_h;

    if ( $src_ratio > $dst_ratio ) {
        $crop_h = $src_h;
        $crop_w = (int) round($src_h * $dst_ratio);
        $src_x  = (int) floor(($src_w - $crop_w) / 2);
        $src_y  = 0;
    } else {
        $crop_w = $src_w;
        $crop_h = (int) round($src_w / $dst_ratio);
        $src_x  = 0;
        $src_y  = (int) floor(($src_h - $crop_h) / 2);
    }

    return imagecopyresampled($dst, $src, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $crop_w, $crop_h);
}

/**
 * Copia una imagen completa dentro del rectángulo de destino sin recortarla.
 * Se usa para el cabezote del QR de WhatsApp, porque el logo o textos del extremo
 * derecho no deben cortarse si la imagen subida no coincide exactamente con la proporción esperada.
 */
function eventosapp_whatsapp_image_contain_copy($dst, $src, $dst_x, $dst_y, $dst_w, $dst_h) {
    $src_w = imagesx($src);
    $src_h = imagesy($src);
    if ( ! $src_w || ! $src_h || ! $dst_w || ! $dst_h ) {
        return false;
    }

    $scale = min($dst_w / $src_w, $dst_h / $src_h);
    $copy_w = max(1, (int) floor($src_w * $scale));
    $copy_h = max(1, (int) floor($src_h * $scale));
    $copy_x = $dst_x + (int) floor(($dst_w - $copy_w) / 2);
    $copy_y = $dst_y + (int) floor(($dst_h - $copy_h) / 2);

    return imagecopyresampled($dst, $src, $copy_x, $copy_y, 0, 0, $copy_w, $copy_h, $src_w, $src_h);
}

/**
 * Genera una imagen pública compuesta con cabezote + QR para WhatsApp.
 * Si no se puede generar, devuelve el QR original para no bloquear el envío.
 */
function eventosapp_whatsapp_build_qr_message_image($ticket_id, $qr_url) {
    $ticket_id = absint($ticket_id);
    $qr_url    = esc_url_raw((string) $qr_url);

    if ( ! $ticket_id || $qr_url === '' ) {
        return $qr_url;
    }

    if ( ! function_exists('imagecreatetruecolor') || ! function_exists('imagejpeg') || ! function_exists('imagecopyresampled') ) {
        eventosapp_whatsapp_add_activity_log('qr_compuesto_no_generado_gd_no_disponible', [
            'ticket_id' => $ticket_id,
            'qr_url'    => $qr_url,
            'reason'    => 'La extensión GD de PHP no está disponible. Se enviará el QR original.',
        ]);
        return $qr_url;
    }

    $event_id    = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $header_url  = eventosapp_whatsapp_get_qr_header_image($ticket_id, $event_id);
    $cache_key   = md5($ticket_id . '|' . $qr_url . '|' . $header_url . '|' . EVENTOSAPP_WHATSAPP_QR_LAYOUT_VERSION);
    $upload      = wp_upload_dir();

    if ( ! is_array($upload) || ! empty($upload['error']) || empty($upload['basedir']) || empty($upload['baseurl']) ) {
        eventosapp_whatsapp_add_activity_log('qr_compuesto_no_generado_uploads_no_disponible', [
            'ticket_id' => $ticket_id,
            'qr_url'    => $qr_url,
            'error'     => is_array($upload) ? ($upload['error'] ?? '') : 'wp_upload_dir no retornó arreglo.',
        ]);
        return $qr_url;
    }

    $dir         = trailingslashit($upload['basedir']) . 'eventosapp-whatsapp/';
    $base_url    = trailingslashit($upload['baseurl']) . 'eventosapp-whatsapp/';
    $file        = 'whatsapp-ticket-' . $ticket_id . '-' . $cache_key . '.jpg';
    $path        = $dir . $file;
    $public_url  = $base_url . $file;

    if ( file_exists($path) && filesize($path) > 0 ) {
        return esc_url_raw($public_url);
    }

    if ( ! wp_mkdir_p($dir) ) {
        eventosapp_whatsapp_add_activity_log('qr_compuesto_no_generado_directorio', [
            'ticket_id' => $ticket_id,
            'dir'       => $dir,
            'reason'    => 'No se pudo crear el directorio eventosapp-whatsapp en uploads.',
        ]);
        return $qr_url;
    }

    $qr_image = eventosapp_whatsapp_image_resource_from_url($qr_url);
    if ( ! $qr_image ) {
        eventosapp_whatsapp_add_activity_log('qr_compuesto_no_generado_qr_invalido', [
            'ticket_id'       => $ticket_id,
            'qr_url'          => $qr_url,
            'qr_local_path'   => eventosapp_whatsapp_url_to_local_path($qr_url),
            'reason'          => 'No se pudo leer la imagen real del QR. Se enviará el QR original.',
        ]);
        return $qr_url;
    }

    $header_image = $header_url ? eventosapp_whatsapp_image_resource_from_url($header_url) : false;

    $canvas_w = (int) EVENTOSAPP_WHATSAPP_QR_CANVAS_WIDTH;
    $canvas_h = (int) EVENTOSAPP_WHATSAPP_QR_CANVAS_HEIGHT;
    $header_h = $header_image ? (int) EVENTOSAPP_WHATSAPP_QR_HEADER_HEIGHT : 0;
    $qr_size = (int) EVENTOSAPP_WHATSAPP_QR_IMAGE_SIZE;
    $available_after_header = max(0, $canvas_h - $header_h - $qr_size);
    $top_padding = $header_image ? (int) floor($available_after_header / 2) : (int) floor(($canvas_h - $qr_size) / 2);
    $bottom_padding = $canvas_h - $header_h - $top_padding - $qr_size;

    $canvas = imagecreatetruecolor($canvas_w, $canvas_h);
    if ( ! $canvas ) {
        imagedestroy($qr_image);
        if ( $header_image ) {
            imagedestroy($header_image);
        }
        eventosapp_whatsapp_add_activity_log('qr_compuesto_no_generado_canvas', [
            'ticket_id' => $ticket_id,
            'reason'    => 'No se pudo crear el lienzo GD para combinar cabezote y QR.',
        ]);
        return $qr_url;
    }

    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $canvas_w, $canvas_h, $white);

    if ( $header_image ) {
        eventosapp_whatsapp_image_contain_copy($canvas, $header_image, 0, 0, $canvas_w, $header_h);
    }

    // Fondo blanco dedicado para el QR. Esto evita que la lectura se afecte si
    // el PNG original trae transparencia o bordes muy justos.
    $qr_x = (int) floor(($canvas_w - $qr_size) / 2);
    $qr_y = $header_h + $top_padding;
    $quiet_zone = 28;
    imagefilledrectangle($canvas, $qr_x - $quiet_zone, $qr_y - $quiet_zone, $qr_x + $qr_size + $quiet_zone, $qr_y + $qr_size + $quiet_zone, $white);
    imagecopyresampled($canvas, $qr_image, $qr_x, $qr_y, 0, 0, $qr_size, $qr_size, imagesx($qr_image), imagesy($qr_image));

    $ok = imagejpeg($canvas, $path, 92);

    imagedestroy($canvas);
    imagedestroy($qr_image);
    if ( $header_image ) {
        imagedestroy($header_image);
    }

    if ( ! $ok || ! file_exists($path) || filesize($path) <= 0 ) {
        eventosapp_whatsapp_add_activity_log('qr_compuesto_no_generado_escritura', [
            'ticket_id' => $ticket_id,
            'path'      => $path,
            'reason'    => 'No se pudo escribir la imagen compuesta en uploads.',
        ]);
        return $qr_url;
    }

    eventosapp_whatsapp_add_activity_log('qr_compuesto_generado', [
        'ticket_id'       => $ticket_id,
        'event_id'        => $event_id,
        'qr_url'          => $qr_url,
        'header_url'      => $header_url,
        'composite_url'   => esc_url_raw($public_url),
        'canvas_width'    => $canvas_w,
        'canvas_height'   => $canvas_h,
        'header_width'    => (int) EVENTOSAPP_WHATSAPP_QR_HEADER_WIDTH,
        'header_height'   => $header_h,
        'qr_size'         => $qr_size,
        'qr_local_path'   => eventosapp_whatsapp_url_to_local_path($qr_url),
        'header_local_path' => $header_url ? eventosapp_whatsapp_url_to_local_path($header_url) : '',
    ]);

    return esc_url_raw($public_url);
}

/**
 * Imagen que se enviará como media por WhatsApp: QR compuesto para presencial, imagen configurada para virtual.
 */
function eventosapp_whatsapp_prepare_message_image_url($ticket_id, $qr_url = '') {
    $ticket_id = absint($ticket_id);
    $event_id  = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $is_virtual = function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id);

    if ( $is_virtual ) {
        $virtual_image = eventosapp_whatsapp_get_virtual_message_image($ticket_id, $event_id);
        eventosapp_whatsapp_add_activity_log('imagen_whatsapp_virtual_resuelta', [
            'ticket_id' => $ticket_id,
            'event_id'  => $event_id,
            'image_url' => $virtual_image,
        ]);
        return $virtual_image;
    }

    $qr_url = esc_url_raw((string) $qr_url);
    if ( $qr_url === '' ) {
        eventosapp_whatsapp_add_activity_log('imagen_whatsapp_presencial_sin_qr', [
            'ticket_id' => $ticket_id,
            'event_id'  => $event_id,
            'reason'    => 'No se encontró/generó URL del QR real del ticket para hacer la composición.',
        ]);
        return '';
    }

    $composite_url = eventosapp_whatsapp_build_qr_message_image($ticket_id, $qr_url);
    eventosapp_whatsapp_add_activity_log('imagen_whatsapp_presencial_resuelta', [
        'ticket_id'     => $ticket_id,
        'event_id'      => $event_id,
        'qr_url'        => $qr_url,
        'composite_url' => $composite_url,
        'is_composite'  => $composite_url !== '' && $composite_url !== $qr_url ? 1 : 0,
    ]);

    return $composite_url;
}

/**
 * Etiqueta legible para estados locales y estados recibidos por webhook.
 */
function eventosapp_whatsapp_status_label($status) {
    $status = sanitize_text_field((string) $status);
    $labels = [
        ''                    => 'Sin estado',
        'enviado'             => 'Aceptado por Meta',
        'aceptado_meta'       => 'Aceptado por Meta',
        'pendiente_webhook'   => 'Pendiente de webhook',
        'sent'                => 'Enviado por WhatsApp',
        'webhook_sent'        => 'Enviado por WhatsApp',
        'enviado_webhook'     => 'Enviado por WhatsApp',
        'delivered'           => 'Entregado al dispositivo',
        'webhook_delivered'   => 'Entregado al dispositivo',
        'entregado'           => 'Entregado al dispositivo',
        'read'                => 'Leído por el usuario',
        'webhook_read'        => 'Leído por el usuario',
        'leido'               => 'Leído por el usuario',
        'failed'              => 'Fallido en Meta',
        'webhook_failed'      => 'Fallido en Meta',
        'fallido_webhook'     => 'Fallido en Meta',
        'error'               => 'Error local/API',
        'skipped'             => 'Omitido',
        'preparado'           => 'Preparado para envío',
        'template_runtime'    => 'Plantilla aprobada',
        'freeform_fallback'   => 'Mensaje libre fallback',
    ];
    return $labels[$status] ?? $status;
}


/**
 * Estados del historial que cuentan como envío real de ticket por WhatsApp.
 * Se usa "aceptado_meta" porque, al igual que wp_mail() en correo,
 * confirma que la solicitud de envío salió correctamente desde EventosApp.
 */
function eventosapp_whatsapp_successful_send_statuses() {
    return apply_filters('eventosapp_whatsapp_successful_send_statuses', [
        'aceptado_meta',
        'enviado',
    ]);
}

/**
 * Normaliza una fecha/hora guardada en postmeta para poder compararla.
 */
function eventosapp_whatsapp_datetime_timestamp($value) {
    if (is_numeric($value)) {
        return (int) $value;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }

    $timestamp = strtotime($value);
    return $timestamp ? (int) $timestamp : 0;
}

/**
 * Formatea fechas de tracking WhatsApp para metaboxes y exportaciones.
 */
function eventosapp_whatsapp_format_datetime($value, $format = 'd/m/Y H:i') {
    $timestamp = eventosapp_whatsapp_datetime_timestamp($value);
    if (!$timestamp) {
        return '';
    }

    return date_i18n($format, $timestamp);
}

/**
 * Etiqueta legible para el origen/contexto del envío de WhatsApp.
 */
function eventosapp_whatsapp_source_label($source) {
    $source = sanitize_key((string) $source);
    $labels = [
        'manual_admin'             => 'Envío manual (admin)',
        'whatsapp_bulk_send'       => 'Envío masivo WhatsApp',
        'whatsapp_mass_send'       => 'Envío masivo WhatsApp',
        'masivo'                   => 'Envío masivo WhatsApp',
        'email_history'            => 'Disparado por correo',
        'email_status'             => 'Disparado por correo',
        'email_hook'               => 'Disparado por correo',
        'email_adminpost_shutdown' => 'Disparado por correo',
        'webhook_create'           => 'Creación por webhook',
        'webhook_update'           => 'Actualización por webhook',
        'frontend'                 => 'Creación frontend',
        'frontend_create'          => 'Creación frontend',
        'frontend_edit'            => 'Módulo edición frontend',
        'ticket_create'            => 'Creación de ticket',
        'ticket_update'            => 'Actualización de ticket',
        'reminder'                 => 'Recordatorio',
        'unknown'                  => 'No especificado',
        ''                         => '',
    ];

    return $labels[$source] ?? ($source ?: '');
}

/**
 * Guarda el tracking principal de envíos WhatsApp en postmeta del ticket.
 *
 * Metadatos principales:
 * - _eventosapp_whatsapp_sent_status: enviado/no_enviado
 * - _eventosapp_whatsapp_first_sent_at: primera solicitud aceptada por Meta
 * - _eventosapp_whatsapp_last_sent_at: última solicitud aceptada por Meta
 * - _eventosapp_whatsapp_first_to / _eventosapp_whatsapp_last_to
 * - _eventosapp_whatsapp_first_source / _eventosapp_whatsapp_last_source
 * - _eventosapp_whatsapp_send_count
 */
function eventosapp_whatsapp_register_successful_send_tracking($ticket_id, $phone = '', $args = [], $sent_at = '') {
    $ticket_id = absint($ticket_id);
    if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
        return '';
    }

    $sent_at = trim((string) $sent_at);
    if ($sent_at === '') {
        $sent_at = current_time('mysql');
    }

    $phone = sanitize_text_field((string) $phone);
    $source = sanitize_key((string)($args['context'] ?? 'unknown'));

    $first_sent = get_post_meta($ticket_id, '_eventosapp_whatsapp_first_sent_at', true);
    if (empty($first_sent)) {
        update_post_meta($ticket_id, '_eventosapp_whatsapp_first_sent_at', $sent_at);
        update_post_meta($ticket_id, '_eventosapp_whatsapp_first_to', $phone);
        update_post_meta($ticket_id, '_eventosapp_whatsapp_first_source', $source);
    }

    update_post_meta($ticket_id, '_eventosapp_whatsapp_sent_status', 'enviado');
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_sent_at', $sent_at);
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_to', $phone);
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_source', $source);

    $send_count = (int) get_post_meta($ticket_id, '_eventosapp_whatsapp_send_count', true);
    update_post_meta($ticket_id, '_eventosapp_whatsapp_send_count', max(0, $send_count) + 1);

    return $sent_at;
}

/**
 * Obtiene el tracking de primer/último envío de WhatsApp.
 * Si el ticket es anterior a este tracking, intenta reconstruirlo desde el historial.
 */
function eventosapp_whatsapp_get_send_tracking($ticket_id, $persist_recovered = false) {
    $ticket_id = absint($ticket_id);
    $empty = [
        'sent_status'   => 'no_enviado',
        'first_sent_at' => '',
        'last_sent_at'  => '',
        'first_to'      => '',
        'last_to'       => '',
        'first_source'  => '',
        'last_source'   => '',
        'send_count'    => 0,
    ];

    if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
        return $empty;
    }

    $tracking = [
        'sent_status'   => sanitize_key((string) get_post_meta($ticket_id, '_eventosapp_whatsapp_sent_status', true)),
        'first_sent_at' => (string) get_post_meta($ticket_id, '_eventosapp_whatsapp_first_sent_at', true),
        'last_sent_at'  => (string) get_post_meta($ticket_id, '_eventosapp_whatsapp_last_sent_at', true),
        'first_to'      => (string) get_post_meta($ticket_id, '_eventosapp_whatsapp_first_to', true),
        'last_to'       => (string) get_post_meta($ticket_id, '_eventosapp_whatsapp_last_to', true),
        'first_source'  => sanitize_key((string) get_post_meta($ticket_id, '_eventosapp_whatsapp_first_source', true)),
        'last_source'   => sanitize_key((string) get_post_meta($ticket_id, '_eventosapp_whatsapp_last_source', true)),
        'send_count'    => (int) get_post_meta($ticket_id, '_eventosapp_whatsapp_send_count', true),
    ];

    $successful_statuses = eventosapp_whatsapp_successful_send_statuses();
    $history = get_post_meta($ticket_id, '_eventosapp_whatsapp_history', true);
    if (!is_array($history)) {
        $history = [];
    }

    $recovered = [];
    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $entry_status = sanitize_key((string)($entry['status'] ?? ''));
        if (!in_array($entry_status, $successful_statuses, true)) {
            continue;
        }
        $entry_date = (string)($entry['date'] ?? '');
        $entry_ts = eventosapp_whatsapp_datetime_timestamp($entry_date);
        if (!$entry_ts) {
            continue;
        }
        $recovered[] = [
            'date'    => $entry_date,
            'ts'      => $entry_ts,
            'to'      => sanitize_text_field((string)($entry['to'] ?? '')),
            'source'  => sanitize_key((string)($entry['context'] ?? 'unknown')),
            'status'  => $entry_status,
        ];
    }

    if (!empty($recovered)) {
        usort($recovered, static function($a, $b) {
            return ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0);
        });
        $first_recovered = reset($recovered);
        $last_recovered  = end($recovered);

        if (empty($tracking['first_sent_at'])) {
            $tracking['first_sent_at'] = (string)($first_recovered['date'] ?? '');
            $tracking['first_to'] = (string)($first_recovered['to'] ?? '');
            $tracking['first_source'] = sanitize_key((string)($first_recovered['source'] ?? 'unknown'));
        }
        if (empty($tracking['last_sent_at'])) {
            $tracking['last_sent_at'] = (string)($last_recovered['date'] ?? '');
            $tracking['last_to'] = (string)($last_recovered['to'] ?? '');
            $tracking['last_source'] = sanitize_key((string)($last_recovered['source'] ?? 'unknown'));
        }
        if ($tracking['send_count'] <= 0) {
            $tracking['send_count'] = count($recovered);
        }
    }

    if (empty($tracking['sent_status'])) {
        $tracking['sent_status'] = (!empty($tracking['first_sent_at']) || !empty($tracking['last_sent_at'])) ? 'enviado' : 'no_enviado';
    }

    if (!empty($tracking['last_sent_at']) && empty($tracking['first_sent_at'])) {
        $tracking['first_sent_at'] = $tracking['last_sent_at'];
        if (empty($tracking['first_to'])) {
            $tracking['first_to'] = $tracking['last_to'];
        }
        if (empty($tracking['first_source'])) {
            $tracking['first_source'] = $tracking['last_source'];
        }
    }

    if ($persist_recovered && $tracking['sent_status'] === 'enviado') {
        update_post_meta($ticket_id, '_eventosapp_whatsapp_sent_status', 'enviado');
        if (!empty($tracking['first_sent_at'])) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_first_sent_at', $tracking['first_sent_at']);
        }
        if (!empty($tracking['last_sent_at'])) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_sent_at', $tracking['last_sent_at']);
        }
        if (!empty($tracking['first_to'])) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_first_to', $tracking['first_to']);
        }
        if (!empty($tracking['last_to'])) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_to', $tracking['last_to']);
        }
        if (!empty($tracking['first_source'])) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_first_source', $tracking['first_source']);
        }
        if (!empty($tracking['last_source'])) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_source', $tracking['last_source']);
        }
        if ((int)$tracking['send_count'] > 0) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_send_count', (int)$tracking['send_count']);
        }
    }

    return wp_parse_args($tracking, $empty);
}

/**
 * Resume el payload sin exponer textos completos ni credenciales.
 */
function eventosapp_whatsapp_summarize_payload(array $payload) {
    $summary = [
        'messaging_product' => $payload['messaging_product'] ?? '',
        'recipient_type'    => $payload['recipient_type'] ?? '',
        'to'                => $payload['to'] ?? '',
        'type'              => $payload['type'] ?? '',
    ];

    if ( ($payload['type'] ?? '') === 'template' && ! empty($payload['template']) && is_array($payload['template']) ) {
        $summary['template'] = [
            'name'     => $payload['template']['name'] ?? '',
            'language' => $payload['template']['language']['code'] ?? '',
        ];
        $components_summary = [];
        foreach ( (array) ($payload['template']['components'] ?? []) as $component ) {
            if ( ! is_array($component) ) {
                continue;
            }
            $component_summary = [
                'type'       => $component['type'] ?? '',
                'sub_type'   => $component['sub_type'] ?? '',
                'index'      => $component['index'] ?? '',
                'parameters' => count((array) ($component['parameters'] ?? [])),
            ];
            if ( strtolower((string)($component['type'] ?? '')) === 'header' && ! empty($component['parameters'][0]['image']['link']) ) {
                $component_summary['image_link'] = esc_url_raw($component['parameters'][0]['image']['link']);
            }
            $components_summary[] = $component_summary;
        }
        $summary['components'] = $components_summary;
    }

    if ( ($payload['type'] ?? '') === 'image' && ! empty($payload['image']) && is_array($payload['image']) ) {
        $summary['image_link'] = ! empty($payload['image']['link']) ? esc_url_raw($payload['image']['link']) : '';
        $caption = (string) ($payload['image']['caption'] ?? '');
        $summary['caption_chars'] = function_exists('mb_strlen') ? mb_strlen($caption) : strlen($caption);
        $summary['caption_preview'] = function_exists('mb_substr') ? mb_substr($caption, 0, 240) : substr($caption, 0, 240);
    }

    if ( ($payload['type'] ?? '') === 'text' && ! empty($payload['text']) && is_array($payload['text']) ) {
        $body = (string) ($payload['text']['body'] ?? '');
        $summary['text_chars'] = function_exists('mb_strlen') ? mb_strlen($body) : strlen($body);
        $summary['text_preview'] = function_exists('mb_substr') ? mb_substr($body, 0, 240) : substr($body, 0, 240);
        $summary['preview_url'] = ! empty($payload['text']['preview_url']) ? 1 : 0;
    }

    return eventosapp_whatsapp_sanitize_log_context($summary);
}

/**
 * Resume la respuesta de Meta de forma útil para diagnóstico.
 */
function eventosapp_whatsapp_summarize_response($decoded, $raw_body = '') {
    if ( is_array($decoded) ) {
        $summary = [];
        foreach ( ['messaging_product', 'contacts', 'messages', 'error'] as $key ) {
            if ( isset($decoded[$key]) ) {
                $summary[$key] = $decoded[$key];
            }
        }
        return eventosapp_whatsapp_sanitize_log_context($summary ?: $decoded);
    }

    return eventosapp_whatsapp_sanitize_log_context($raw_body);
}

/**
 * Muestra una estructura de log en formato legible dentro del admin.
 */
function eventosapp_whatsapp_render_log_details($value) {
    $value = eventosapp_whatsapp_sanitize_log_context($value);
    echo '<pre style="white-space:pre-wrap;word-break:break-word;background:#f6f7f7;border:1px solid #dcdcde;padding:8px;margin:6px 0 0;max-height:260px;overflow:auto;font-size:11px;line-height:1.35;">';
    echo esc_html(wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo '</pre>';
}

/**
 * Agrega sección de configuración global en el menú EventosApp.
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'eventosapp_dashboard',
        'WhatsApp Tickets',
        'WhatsApp Tickets',
        'manage_options',
        'eventosapp_whatsapp_tickets',
        'eventosapp_whatsapp_render_settings_page'
    );
}, 20);

/**
 * Render de página de configuración WhatsApp.
 */
function eventosapp_whatsapp_render_settings_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes para acceder a esta página.');
    }

    $settings = eventosapp_whatsapp_get_settings();
    $token_saved = ! empty($settings['access_token']);
    $webhook_url = admin_url('admin-post.php?action=eventosapp_whatsapp_webhook');
    $last_test = isset($settings['last_test_result']) && is_array($settings['last_test_result']) ? $settings['last_test_result'] : [];
    ?>
    <div class="wrap eventosapp-whatsapp-settings">
        <h1>WhatsApp Tickets</h1>
        <p>
            Configura el envío de tickets por WhatsApp usando WhatsApp Cloud API de Meta.
            El sistema enviará una imagen del QR de WhatsApp con el resumen del ticket y sus enlaces principales.
        </p>

        <?php if ( isset($_GET['evapp_whatsapp_saved']) ) : ?>
            <div class="notice notice-success is-dismissible"><p><strong>EventosApp:</strong> Configuración de WhatsApp guardada.</p></div>
        <?php endif; ?>

        <?php if ( isset($_GET['evapp_whatsapp_log_cleared']) ) : ?>
            <div class="notice notice-success is-dismissible"><p><strong>EventosApp:</strong> Registro global de WhatsApp limpiado.</p></div>
        <?php endif; ?>

        <?php if ( isset($_GET['evapp_whatsapp_test']) ) :
            $ok = sanitize_text_field(wp_unslash($_GET['evapp_whatsapp_test'])) === '1';
            $msg = isset($_GET['evapp_whatsapp_msg']) ? sanitize_text_field(wp_unslash($_GET['evapp_whatsapp_msg'])) : ($ok ? 'Mensaje de prueba enviado.' : 'No se pudo enviar el mensaje de prueba.');
            ?>
            <div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><strong>EventosApp:</strong> <?php echo esc_html($msg); ?></p></div>
        <?php endif; ?>

        <style>
            .evapp-wa-card{background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:18px;margin:18px 0;max-width:980px;}
            .evapp-wa-card h2{margin-top:0;}
            .evapp-wa-grid{display:grid;grid-template-columns:220px minmax(280px,520px);gap:12px 18px;align-items:center;}
            .evapp-wa-grid label{font-weight:600;}
            .evapp-wa-grid input[type="text"],.evapp-wa-grid input[type="password"],.evapp-wa-grid input[type="number"],.evapp-wa-grid textarea,.evapp-wa-grid select{width:100%;}
            .evapp-wa-grid textarea{min-height:80px;}
            .evapp-wa-status-table{border-collapse:collapse;width:100%;max-width:980px;background:#fff;margin-top:8px;}
            .evapp-wa-status-table th,.evapp-wa-status-table td{border:1px solid #dcdcde;padding:8px;text-align:left;vertical-align:top;}
            .evapp-wa-status-table th{background:#f6f7f7;width:220px;}
            .evapp-wa-help{color:#646970;font-size:12px;margin:4px 0 0;}
            .evapp-wa-code{font-family:monospace;background:#f6f7f7;padding:2px 5px;border-radius:4px;}
            .evapp-wa-phone-accounts{display:flex;flex-direction:column;gap:10px;}
            .evapp-wa-phone-account-row{display:grid;grid-template-columns:minmax(180px,1fr) minmax(240px,1fr) auto;gap:8px;align-items:center;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px;}
            .evapp-wa-phone-account-row input{width:100%;}
            @media (max-width: 782px){.evapp-wa-grid{grid-template-columns:1fr;}.evapp-wa-phone-account-row{grid-template-columns:1fr;}}
        </style>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('eventosapp_whatsapp_save_settings', 'eventosapp_whatsapp_settings_nonce'); ?>
            <input type="hidden" name="action" value="eventosapp_whatsapp_save_settings">

            <div class="evapp-wa-card">
                <h2>API de WhatsApp Cloud</h2>
                <div class="evapp-wa-grid">
                    <label for="evapp_wa_enabled">Activar integración global</label>
                    <div>
                        <label>
                            <input type="checkbox" id="evapp_wa_enabled" name="enabled" value="1" <?php checked($settings['enabled'], '1'); ?>>
                            Permitir envíos por WhatsApp desde EventosApp
                        </label>
                        <p class="evapp-wa-help">Además de esta activación global, cada evento debe tener WhatsApp activo en “Funciones Extra del Ticket”.</p>
                    </div>

                    <label for="evapp_wa_api_version">Versión Graph API</label>
                    <div>
                        <input type="text" id="evapp_wa_api_version" name="api_version" value="<?php echo esc_attr($settings['api_version']); ?>" placeholder="v23.0">
                        <p class="evapp-wa-help">Ejemplo: <span class="evapp-wa-code">v23.0</span>. Puedes cambiarla cuando actualices tu app de Meta.</p>
                    </div>

                    <label for="evapp_wa_phone_number_label">Alias del número por defecto</label>
                    <div>
                        <input type="text" id="evapp_wa_phone_number_label" name="phone_number_label" value="<?php echo esc_attr($settings['phone_number_label']); ?>" autocomplete="off" placeholder="EventosApp">
                        <p class="evapp-wa-help">Nombre interno para reconocer el número principal en los metaboxes y logs. No se envía a Meta.</p>
                    </div>

                    <label for="evapp_wa_phone_number_id">Phone Number ID por defecto</label>
                    <div>
                        <input type="text" id="evapp_wa_phone_number_id" name="phone_number_id" value="<?php echo esc_attr($settings['phone_number_id']); ?>" autocomplete="off">
                        <p class="evapp-wa-help">ID del número emisor principal de WhatsApp Business Platform. Este seguirá siendo el respaldo automático para eventos sin número propio.</p>
                    </div>

                    <label>Números adicionales</label>
                    <div>
                        <div class="evapp-wa-phone-accounts" id="evapp-wa-phone-accounts">
                            <?php foreach ( eventosapp_whatsapp_normalize_phone_accounts($settings['phone_accounts'] ?? [], $settings['phone_number_id'] ?? '') as $account ) : ?>
                                <div class="evapp-wa-phone-account-row">
                                    <input type="text" name="phone_accounts_alias[]" value="<?php echo esc_attr($account['alias']); ?>" placeholder="Sobrenombre del organizador" autocomplete="off">
                                    <input type="text" name="phone_accounts_phone_number_id[]" value="<?php echo esc_attr($account['phone_number_id']); ?>" placeholder="Phone Number ID" autocomplete="off">
                                    <button type="button" class="button evapp-wa-remove-phone-account">Quitar</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p style="margin:10px 0 0;"><button type="button" class="button button-secondary" id="evapp-wa-add-phone-account">+ Agregar número emisor</button></p>
                        <p class="evapp-wa-help">Agrega aquí los Phone Number ID de los organizadores. Todos usarán el mismo Access Token de la app, siempre que el usuario de sistema tenga permisos sobre esos números. El WABA ID para aprobar plantillas se define desde Plantillas WhatsApp en cada plantilla que use un número distinto al principal.</p>
                    </div>

                    <label for="evapp_wa_access_token">Access Token</label>
                    <div>
                        <input type="password" id="evapp_wa_access_token" name="access_token" value="" autocomplete="new-password" placeholder="<?php echo $token_saved ? esc_attr('Token guardado. Déjalo vacío para conservarlo.') : esc_attr('Pega aquí el token de Meta'); ?>">
                        <p class="evapp-wa-help">Por seguridad no se muestra el token guardado. Si escribes uno nuevo, reemplazará al anterior.</p>
                    </div>

                    <label for="evapp_wa_country">Indicativo por defecto</label>
                    <div>
                        <input type="text" id="evapp_wa_country" name="default_country_code" value="<?php echo esc_attr($settings['default_country_code']); ?>" placeholder="57">
                        <p class="evapp-wa-help">Se usa cuando el teléfono del asistente no trae indicativo internacional.</p>
                    </div>

                    <label for="evapp_wa_timeout">Timeout</label>
                    <div>
                        <input type="number" id="evapp_wa_timeout" name="request_timeout" min="5" max="60" value="<?php echo esc_attr((int)$settings['request_timeout']); ?>">
                        <p class="evapp-wa-help">Tiempo máximo de espera por solicitud a Meta, en segundos.</p>
                    </div>

                    <label for="evapp_wa_intro">Mensaje inicial</label>
                    <div>
                        <textarea id="evapp_wa_intro" name="message_intro"><?php echo esc_textarea($settings['message_intro']); ?></textarea>
                        <p class="evapp-wa-help">Variables disponibles: <span class="evapp-wa-code">{{nombre}}</span>, <span class="evapp-wa-code">{{apellido}}</span>, <span class="evapp-wa-code">{{evento_nombre}}</span>, <span class="evapp-wa-code">{{ticket_id}}</span>, <span class="evapp-wa-code">{{enlace_plataforma_virtual}}</span>, <span class="evapp-wa-code">{{enlace_virtual_whatsapp}}</span>.</p>
                    </div>

                    <label for="evapp_wa_debug">Depuración</label>
                    <div>
                        <label>
                            <input type="checkbox" id="evapp_wa_debug" name="debug_log" value="1" <?php checked($settings['debug_log'], '1'); ?>>
                            Escribir logs en wp-debug.log
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" name="dry_run" value="1" <?php checked($settings['dry_run'], '1'); ?>>
                            Modo prueba interno: no llama a Meta, solo registra el intento como simulado
                        </label>
                    </div>

                    <label for="evapp_wa_webhook_url">Webhook de estados</label>
                    <div>
                        <input type="text" id="evapp_wa_webhook_url" value="<?php echo esc_attr($webhook_url); ?>" readonly onclick="this.select();">
                        <p class="evapp-wa-help">Usa esta URL en Meta para recibir estados de entrega: enviado, entregado, leído o fallido.</p>
                    </div>

                    <label for="evapp_wa_webhook_verify_token">Token de verificación webhook</label>
                    <div>
                        <input type="text" id="evapp_wa_webhook_verify_token" name="webhook_verify_token" value="<?php echo esc_attr($settings['webhook_verify_token']); ?>" autocomplete="off">
                        <p class="evapp-wa-help">Copia este mismo token en Meta al configurar el webhook. Si lo dejas vacío, EventosApp generará uno al guardar.</p>
                    </div>
                </div>
            </div>

            <div class="evapp-wa-card">
                <h2>Prueba rápida</h2>
                <div class="evapp-wa-grid">
                    <label for="evapp_wa_test_phone">Teléfono de prueba</label>
                    <div>
                        <input type="text" id="evapp_wa_test_phone" name="test_phone" value="<?php echo esc_attr($settings['test_phone']); ?>" placeholder="573001112233">
                        <p class="evapp-wa-help">Guarda este teléfono para usar el botón de prueba.</p>
                    </div>

                    <label for="evapp_wa_test_phone_number_id">Número emisor de prueba</label>
                    <div>
                        <?php $test_accounts = eventosapp_whatsapp_get_phone_accounts($settings); ?>
                        <?php if ( empty($test_accounts) ) : ?>
                            <p class="evapp-wa-help" style="margin-top:0;">Configura primero el Phone Number ID por defecto para poder enviar pruebas.</p>
                        <?php else : ?>
                            <select id="evapp_wa_test_phone_number_id" name="test_phone_number_id">
                                <option value="">Automático: número por defecto</option>
                                <?php foreach ( $test_accounts as $account_id => $account ) : ?>
                                    <option value="<?php echo esc_attr($account_id); ?>" <?php selected($settings['test_phone_number_id'], $account_id); ?>><?php echo esc_html($account['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="evapp-wa-help">Sirve para probar un número nuevo antes de asignarlo a un evento.</p>
                        <?php endif; ?>
                    </div>

                    <label for="evapp_wa_test_message_mode">Tipo de mensaje de prueba</label>
                    <div>
                        <select id="evapp_wa_test_message_mode" name="test_message_mode">
                            <option value="template" <?php selected($settings['test_message_mode'], 'template'); ?>>Plantilla aprobada por Meta</option>
                            <option value="text" <?php selected($settings['test_message_mode'], 'text'); ?>>Texto libre</option>
                        </select>
                        <p class="evapp-wa-help">Para iniciar una conversación desde la empresa, usa plantilla. El texto libre solo es confiable si el usuario ya escribió al WhatsApp del negocio dentro de la ventana de atención.</p>
                    </div>

                    <label for="evapp_wa_test_template_name">Nombre de plantilla de prueba</label>
                    <div>
                        <input type="text" id="evapp_wa_test_template_name" name="test_template_name" value="<?php echo esc_attr($settings['test_template_name']); ?>" placeholder="hello_world">
                        <p class="evapp-wa-help">Para la prueba inicial de Meta normalmente puedes usar <span class="evapp-wa-code">hello_world</span>.</p>
                    </div>

                    <label for="evapp_wa_test_template_language">Idioma de plantilla</label>
                    <div>
                        <input type="text" id="evapp_wa_test_template_language" name="test_template_language" value="<?php echo esc_attr($settings['test_template_language']); ?>" placeholder="en_US">
                        <p class="evapp-wa-help">Debe coincidir con el idioma configurado en la plantilla. Para <span class="evapp-wa-code">hello_world</span> suele ser <span class="evapp-wa-code">en_US</span>.</p>
                    </div>
                </div>

                <?php if ( ! empty($last_test) ) : ?>
                    <h3>Última prueba registrada</h3>
                    <table class="evapp-wa-status-table">
                        <tbody>
                            <tr><th>Fecha</th><td><?php echo esc_html($last_test['date'] ?? ''); ?></td></tr>
                            <tr><th>Teléfono</th><td><?php echo esc_html($last_test['to'] ?? ''); ?></td></tr>
                            <tr><th>Tipo</th><td><?php echo esc_html($last_test['type'] ?? ''); ?></td></tr>
                            <tr><th>Resultado API</th><td><?php echo ! empty($last_test['ok']) ? 'Aceptado por Meta' : 'Error'; ?></td></tr>
                            <tr><th>HTTP</th><td><?php echo esc_html((string)($last_test['http_code'] ?? '')); ?></td></tr>
                            <tr><th>Message ID</th><td><?php echo esc_html($last_test['message_id'] ?? ''); ?></td></tr>
                            <tr><th>Estado webhook</th><td><?php echo esc_html($last_test['delivery_status'] ?? 'Sin estado recibido'); ?></td></tr>
                            <tr><th>Mensaje</th><td><?php echo esc_html($last_test['message'] ?? ''); ?></td></tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <script>
            jQuery(function($){
                var $accounts = $('#evapp-wa-phone-accounts');
                $('#evapp-wa-add-phone-account').off('click.evappWaPhoneAccounts').on('click.evappWaPhoneAccounts', function(e){
                    e.preventDefault();
                    $accounts.append(
                        '<div class="evapp-wa-phone-account-row">' +
                            '<input type="text" name="phone_accounts_alias[]" value="" placeholder="Sobrenombre del organizador" autocomplete="off">' +
                            '<input type="text" name="phone_accounts_phone_number_id[]" value="" placeholder="Phone Number ID" autocomplete="off">' +
                            '<button type="button" class="button evapp-wa-remove-phone-account">Quitar</button>' +
                        '</div>'
                    );
                });
                $(document).off('click.evappWaRemovePhoneAccount').on('click.evappWaRemovePhoneAccount', '.evapp-wa-remove-phone-account', function(e){
                    e.preventDefault();
                    $(this).closest('.evapp-wa-phone-account-row').remove();
                });
            });
            </script>

            <?php submit_button('Guardar configuración de WhatsApp'); ?>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
            <?php wp_nonce_field('eventosapp_whatsapp_send_test', 'eventosapp_whatsapp_test_nonce'); ?>
            <input type="hidden" name="action" value="eventosapp_whatsapp_send_test">
            <?php submit_button('Enviar mensaje de prueba', 'secondary', 'submit', false); ?>
        </form>

        <?php $activity_log = eventosapp_whatsapp_get_activity_log(25); ?>
        <div class="evapp-wa-card">
            <h2>Registro global reciente de WhatsApp</h2>
            <p class="evapp-wa-help">
                Este registro queda guardado en WordPress aunque el modo wp-debug.log esté apagado. Permite validar si Meta solo aceptó la solicitud, si llegó un webhook de entrega/lectura o si Meta devolvió un fallo posterior.
            </p>
            <?php if ( empty($activity_log) ) : ?>
                <p>No hay actividad registrada todavía.</p>
            <?php else : ?>
                <table class="evapp-wa-status-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Evento</th>
                            <th>Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $activity_log as $entry ) : ?>
                            <tr>
                                <td><?php echo esc_html($entry['date'] ?? ''); ?></td>
                                <td><?php echo esc_html($entry['event'] ?? ''); ?></td>
                                <td><?php eventosapp_whatsapp_render_log_details($entry['context'] ?? []); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <?php wp_nonce_field('eventosapp_whatsapp_clear_activity_log', 'eventosapp_whatsapp_clear_log_nonce'); ?>
                <input type="hidden" name="action" value="eventosapp_whatsapp_clear_activity_log">
                <?php submit_button('Limpiar registro global', 'secondary', 'submit', false); ?>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Guarda settings globales.
 */
add_action('admin_post_eventosapp_whatsapp_save_settings', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }

    if ( ! isset($_POST['eventosapp_whatsapp_settings_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_settings_nonce'], 'eventosapp_whatsapp_save_settings') ) {
        wp_die('Nonce inválido.');
    }

    $current = eventosapp_whatsapp_get_settings();

    $access_token = isset($_POST['access_token']) ? trim((string) wp_unslash($_POST['access_token'])) : '';
    if ( $access_token === '' ) {
        $access_token = $current['access_token'];
    }

    $api_version = isset($_POST['api_version']) ? sanitize_text_field(wp_unslash($_POST['api_version'])) : 'v23.0';
    $api_version = preg_replace('/[^a-zA-Z0-9\.\-_]/', '', $api_version);
    if ( $api_version === '' ) {
        $api_version = 'v23.0';
    }

    $test_message_mode = isset($_POST['test_message_mode']) ? sanitize_key(wp_unslash($_POST['test_message_mode'])) : 'template';
    if ( ! in_array($test_message_mode, ['template', 'text'], true) ) {
        $test_message_mode = 'template';
    }

    $phone_number_id = isset($_POST['phone_number_id']) ? eventosapp_whatsapp_sanitize_phone_number_id(wp_unslash($_POST['phone_number_id'])) : '';
    $phone_accounts = eventosapp_whatsapp_sanitize_phone_accounts_from_request($phone_number_id);
    $phone_number_label = isset($_POST['phone_number_label']) ? eventosapp_whatsapp_sanitize_phone_account_label(wp_unslash($_POST['phone_number_label']), 'EventosApp') : 'EventosApp';

    $available_for_test = eventosapp_whatsapp_get_phone_accounts([
        'phone_number_id'      => $phone_number_id,
        'phone_number_label'   => $phone_number_label,
        'phone_accounts'       => $phone_accounts,
    ]);
    $test_phone_number_id = isset($_POST['test_phone_number_id']) ? eventosapp_whatsapp_sanitize_phone_number_id(wp_unslash($_POST['test_phone_number_id'])) : '';
    if ( $test_phone_number_id !== '' && ! isset($available_for_test[$test_phone_number_id]) ) {
        $test_phone_number_id = '';
    }

    $webhook_verify_token = isset($_POST['webhook_verify_token']) ? sanitize_text_field(wp_unslash($_POST['webhook_verify_token'])) : '';
    if ( $webhook_verify_token === '' ) {
        $webhook_verify_token = ! empty($current['webhook_verify_token']) ? $current['webhook_verify_token'] : wp_generate_password(32, false, false);
    }

    $settings = [
        'enabled'                => isset($_POST['enabled']) ? '1' : '0',
        'api_version'            => $api_version,
        'phone_number_id'        => $phone_number_id,
        'phone_number_label'     => $phone_number_label,
        'phone_accounts'         => $phone_accounts,
        'access_token'           => $access_token,
        'default_country_code'   => isset($_POST['default_country_code']) ? preg_replace('/\D+/', '', (string) wp_unslash($_POST['default_country_code'])) : '57',
        'request_timeout'        => isset($_POST['request_timeout']) ? min(60, max(5, absint($_POST['request_timeout']))) : 20,
        'debug_log'              => isset($_POST['debug_log']) ? '1' : '0',
        'dry_run'                => isset($_POST['dry_run']) ? '1' : '0',
        'test_phone'             => isset($_POST['test_phone']) ? sanitize_text_field(wp_unslash($_POST['test_phone'])) : '',
        'test_phone_number_id'   => $test_phone_number_id,
        'test_message_mode'      => $test_message_mode,
        'test_template_name'     => isset($_POST['test_template_name']) ? sanitize_key(wp_unslash($_POST['test_template_name'])) : 'hello_world',
        'test_template_language' => isset($_POST['test_template_language']) ? sanitize_text_field(wp_unslash($_POST['test_template_language'])) : 'en_US',
        'webhook_verify_token'   => $webhook_verify_token,
        'last_test_result'       => isset($current['last_test_result']) && is_array($current['last_test_result']) ? $current['last_test_result'] : [],
        'message_intro'          => isset($_POST['message_intro']) ? sanitize_textarea_field(wp_unslash($_POST['message_intro'])) : eventosapp_whatsapp_default_settings()['message_intro'],
    ];

    if ( $settings['default_country_code'] === '' ) {
        $settings['default_country_code'] = '57';
    }
    if ( $settings['test_template_name'] === '' ) {
        $settings['test_template_name'] = 'hello_world';
    }
    if ( $settings['test_template_language'] === '' ) {
        $settings['test_template_language'] = 'en_US';
    }

    update_option(EVENTOSAPP_WHATSAPP_OPTION, $settings, false);

    wp_safe_redirect(add_query_arg('evapp_whatsapp_saved', '1', admin_url('admin.php?page=eventosapp_whatsapp_tickets')));
    exit;
});

/**
 * Mensaje de prueba desde la página global.
 */
add_action('admin_post_eventosapp_whatsapp_send_test', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }

    if ( ! isset($_POST['eventosapp_whatsapp_test_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_test_nonce'], 'eventosapp_whatsapp_send_test') ) {
        wp_die('Nonce inválido.');
    }

    $settings = eventosapp_whatsapp_get_settings();
    $settings = eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id($settings['test_phone_number_id'] ?? '', $settings);
    $phone = eventosapp_whatsapp_normalize_phone($settings['test_phone'], $settings['default_country_code']);

    if ( ! $phone ) {
        wp_safe_redirect(add_query_arg([
            'evapp_whatsapp_test' => '0',
            'evapp_whatsapp_msg'  => rawurlencode('Configura un teléfono de prueba válido.'),
        ], admin_url('admin.php?page=eventosapp_whatsapp_tickets')));
        exit;
    }

    $mode = isset($settings['test_message_mode']) && $settings['test_message_mode'] === 'text' ? 'text' : 'template';

    if ( $mode === 'template' ) {
        $result = eventosapp_whatsapp_api_send_template(
            $phone,
            $settings['test_template_name'] ?? 'hello_world',
            $settings['test_template_language'] ?? 'en_US',
            [],
            $settings
        );
    } else {
        $result = eventosapp_whatsapp_api_send_message($phone, [
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => 'Prueba de WhatsApp Tickets desde EventosApp.',
            ],
        ], $settings);
    }

    eventosapp_whatsapp_store_last_test_result($phone, $mode, $result);

    wp_safe_redirect(add_query_arg([
        'evapp_whatsapp_test' => ! empty($result['ok']) ? '1' : '0',
        'evapp_whatsapp_msg'  => rawurlencode(! empty($result['message']) ? $result['message'] : (! empty($result['ok']) ? 'Mensaje aceptado por Meta.' : 'Error enviando prueba.')),
    ], admin_url('admin.php?page=eventosapp_whatsapp_tickets')));
    exit;
});

/**
 * Limpia el registro global de actividad WhatsApp.
 */
add_action('admin_post_eventosapp_whatsapp_clear_activity_log', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }

    if ( ! isset($_POST['eventosapp_whatsapp_clear_log_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_clear_log_nonce'], 'eventosapp_whatsapp_clear_activity_log') ) {
        wp_die('Nonce inválido.');
    }

    delete_option(EVENTOSAPP_WHATSAPP_ACTIVITY_LOG_OPTION);
    wp_safe_redirect(add_query_arg('evapp_whatsapp_log_cleared', '1', admin_url('admin.php?page=eventosapp_whatsapp_tickets')));
    exit;
});

/**
 * Agrega metabox de reglas de envío por evento.
 */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_whatsapp_rules',
        'WhatsApp Tickets - Reglas de Envío',
        'eventosapp_whatsapp_render_event_rules_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
});

/**
 * Campos disponibles para reglas.
 */
function eventosapp_whatsapp_get_rule_fields($event_id = 0) {
    $fields = [
        'nombre'      => 'Nombre',
        'apellido'    => 'Apellido',
        'cedula'      => 'Cédula',
        'email'       => 'Correo electrónico',
        'telefono'    => 'Celular',
        'empresa'     => 'Empresa',
        'nit'         => 'NIT',
        'cargo'       => 'Cargo',
        'ciudad'      => 'Ciudad',
        'pais'        => 'País',
        'localidad'   => 'Localidad',
        'modalidad'        => 'Modalidad del ticket',
        'creation_channel' => 'Canal de creación del ticket',
        'estado_pago'      => 'Estado de pago',
    ];

    if ( $event_id && function_exists('eventosapp_get_event_extra_fields') ) {
        $extra_fields = eventosapp_get_event_extra_fields($event_id);
        if ( is_array($extra_fields) ) {
            foreach ( $extra_fields as $extra ) {
                if ( empty($extra['key']) ) {
                    continue;
                }
                $key = sanitize_key($extra['key']);
                if ( $key === '' ) {
                    continue;
                }
                $label = ! empty($extra['label']) ? sanitize_text_field($extra['label']) : $key;
                $fields['extra:' . $key] = 'Campo adicional: ' . $label;
            }
        }
    }

    return $fields;
}

/**
 * Operadores disponibles para reglas.
 */
function eventosapp_whatsapp_get_rule_operators() {
    return [
        'equals'       => 'Es igual a',
        'not_equals'   => 'No es igual a',
        'contains'     => 'Contiene',
        'not_contains' => 'No contiene',
        'starts_with'  => 'Empieza por',
        'ends_with'    => 'Termina en',
        'empty'        => 'Está vacío',
        'not_empty'    => 'No está vacío',
    ];
}

/**
 * Normaliza las reglas guardadas.
 */
function eventosapp_whatsapp_normalize_rules($rules) {
    if ( ! is_array($rules) ) {
        return [];
    }

    $clean = [];
    foreach ( $rules as $rule ) {
        if ( ! is_array($rule) ) {
            continue;
        }

        $conditions = [];
        if ( ! empty($rule['conditions']) && is_array($rule['conditions']) ) {
            foreach ( $rule['conditions'] as $condition ) {
                if ( ! is_array($condition) ) {
                    continue;
                }

                $field = isset($condition['field']) ? sanitize_text_field(wp_unslash($condition['field'])) : '';
                $operator = isset($condition['operator']) ? sanitize_key(wp_unslash($condition['operator'])) : 'equals';
                $value = isset($condition['value']) ? sanitize_text_field(wp_unslash($condition['value'])) : '';

                if ( $field === '' ) {
                    continue;
                }

                if ( ! array_key_exists($operator, eventosapp_whatsapp_get_rule_operators()) ) {
                    $operator = 'equals';
                }

                $conditions[] = [
                    'field'    => $field,
                    'operator' => $operator,
                    'value'    => $value,
                ];
            }
        }

        $action = isset($rule['action']) ? sanitize_key(wp_unslash($rule['action'])) : 'allow';
        if ( ! in_array($action, ['allow', 'deny'], true) ) {
            $action = 'allow';
        }

        $match = isset($rule['match']) ? sanitize_key(wp_unslash($rule['match'])) : 'all';
        if ( ! in_array($match, ['all', 'any'], true) ) {
            $match = 'all';
        }

        $clean[] = [
            'enabled'    => isset($rule['enabled']) ? '1' : '0',
            'name'       => isset($rule['name']) ? sanitize_text_field(wp_unslash($rule['name'])) : '',
            'action'     => $action,
            'match'      => $match,
            'conditions' => $conditions,
        ];
    }

    return $clean;
}

/**
 * Renderiza el metabox de reglas por evento.
 */
function eventosapp_whatsapp_render_event_rules_metabox($post) {
    $enabled = get_post_meta($post->ID, '_eventosapp_ticket_whatsapp_enabled', true);
    $rules = get_post_meta($post->ID, '_eventosapp_whatsapp_rules', true);
    $rules = eventosapp_whatsapp_normalize_rules($rules);
    $fields = eventosapp_whatsapp_get_rule_fields($post->ID);
    $operators = eventosapp_whatsapp_get_rule_operators();

    wp_nonce_field('eventosapp_whatsapp_rules_save', 'eventosapp_whatsapp_rules_nonce');
    ?>
    <style>
        .evapp-wa-rules-box{border:1px solid #dcdcde;background:#fff;border-radius:8px;padding:14px;margin:10px 0;}
        .evapp-wa-rule{border:1px solid #ccd0d4;border-left:4px solid #25D366;border-radius:8px;background:#fafafa;margin:14px 0;padding:14px;}
        .evapp-wa-rule-head{display:grid;grid-template-columns:90px 1fr 150px 150px auto;gap:10px;align-items:center;margin-bottom:12px;}
        .evapp-wa-rule-head input[type="text"],.evapp-wa-rule-head select{width:100%;}
        .evapp-wa-conditions table{width:100%;border-collapse:collapse;background:#fff;}
        .evapp-wa-conditions th,.evapp-wa-conditions td{padding:8px;border-bottom:1px solid #eee;text-align:left;vertical-align:middle;}
        .evapp-wa-conditions select,.evapp-wa-conditions input{width:100%;}
        .evapp-wa-muted{color:#646970;font-size:12px;}
        .evapp-wa-empty{padding:12px;background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:6px;}
    </style>

    <div class="evapp-wa-rules-box">
        <?php if ( $enabled !== '1' ) : ?>
            <p class="evapp-wa-empty">WhatsApp todavía no está activo para este evento. Actívalo en el metabox lateral <strong>Funciones Extra del Ticket</strong>.</p>
        <?php endif; ?>

        <p>
            Configura a quién se le envía o no el ticket por WhatsApp. Las reglas de <strong>No enviar</strong> tienen prioridad sobre las reglas de <strong>Enviar</strong>.
            Si no creas reglas, el sistema enviará a todos los tickets del evento que tengan celular válido.
        </p>
        <p class="evapp-wa-muted">
            Para el criterio <strong>Canal de creación del ticket</strong> usa valores como <code>manual</code>, <code>frontend</code>, <code>public</code>, <code>webhook</code> o <code>import</code>.
        </p>

        <div id="evapp-wa-rules-list">
            <?php if ( empty($rules) ) : ?>
                <p class="evapp-wa-empty" id="evapp-wa-no-rules">No hay reglas configuradas. Se enviará a todos los asistentes con celular válido.</p>
            <?php else : ?>
                <?php foreach ( $rules as $rule_index => $rule ) : ?>
                    <?php eventosapp_whatsapp_render_rule_row($rule_index, $rule, $fields, $operators); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <p>
            <button type="button" class="button button-secondary" id="evapp-wa-add-rule">+ Agregar regla de WhatsApp</button>
        </p>
    </div>

    <script type="text/html" id="tmpl-evapp-wa-rule">
        <?php eventosapp_whatsapp_render_rule_row('__RULE_INDEX__', [
            'enabled' => '1',
            'name' => '',
            'action' => 'allow',
            'match' => 'all',
            'conditions' => [],
        ], $fields, $operators); ?>
    </script>

    <script type="text/html" id="tmpl-evapp-wa-condition">
        <?php eventosapp_whatsapp_render_condition_row('__RULE_INDEX__', '__COND_INDEX__', [], $fields, $operators); ?>
    </script>

    <script>
    jQuery(function($){
        var ruleIndex = $('#evapp-wa-rules-list .evapp-wa-rule').length;

        function replaceAllIndexes(html, ruleIdx, condIdx) {
            html = html.replace(/__RULE_INDEX__/g, ruleIdx);
            if (typeof condIdx !== 'undefined') {
                html = html.replace(/__COND_INDEX__/g, condIdx);
            }
            return html;
        }

        $('#evapp-wa-add-rule').on('click', function(){
            $('#evapp-wa-no-rules').remove();
            var html = $('#tmpl-evapp-wa-rule').html();
            $('#evapp-wa-rules-list').append(replaceAllIndexes(html, ruleIndex));
            ruleIndex++;
        });

        $(document).on('click', '.evapp-wa-remove-rule', function(){
            $(this).closest('.evapp-wa-rule').remove();
            if ($('#evapp-wa-rules-list .evapp-wa-rule').length === 0) {
                $('#evapp-wa-rules-list').append('<p class="evapp-wa-empty" id="evapp-wa-no-rules">No hay reglas configuradas. Se enviará a todos los asistentes con celular válido.</p>');
            }
        });

        $(document).on('click', '.evapp-wa-add-condition', function(){
            var $rule = $(this).closest('.evapp-wa-rule');
            var rIdx = $rule.data('rule-index');
            var cIdx = $rule.find('tbody tr').length;
            var html = $('#tmpl-evapp-wa-condition').html();
            $rule.find('tbody').append(replaceAllIndexes(html, rIdx, cIdx));
        });

        $(document).on('click', '.evapp-wa-remove-condition', function(){
            $(this).closest('tr').remove();
        });
    });
    </script>
    <?php
}

/**
 * Render individual de regla.
 */
function eventosapp_whatsapp_render_rule_row($rule_index, $rule, $fields, $operators) {
    $rule = wp_parse_args($rule, [
        'enabled' => '1',
        'name' => '',
        'action' => 'allow',
        'match' => 'all',
        'conditions' => [],
    ]);
    ?>
    <div class="evapp-wa-rule" data-rule-index="<?php echo esc_attr($rule_index); ?>">
        <div class="evapp-wa-rule-head">
            <label>
                <input type="checkbox" name="eventosapp_whatsapp_rules[<?php echo esc_attr($rule_index); ?>][enabled]" value="1" <?php checked($rule['enabled'], '1'); ?>> Activa
            </label>
            <input type="text" name="eventosapp_whatsapp_rules[<?php echo esc_attr($rule_index); ?>][name]" value="<?php echo esc_attr($rule['name']); ?>" placeholder="Nombre de la regla">
            <select name="eventosapp_whatsapp_rules[<?php echo esc_attr($rule_index); ?>][action]">
                <option value="allow" <?php selected($rule['action'], 'allow'); ?>>Enviar si cumple</option>
                <option value="deny" <?php selected($rule['action'], 'deny'); ?>>No enviar si cumple</option>
            </select>
            <select name="eventosapp_whatsapp_rules[<?php echo esc_attr($rule_index); ?>][match]">
                <option value="all" <?php selected($rule['match'], 'all'); ?>>Todas las condiciones</option>
                <option value="any" <?php selected($rule['match'], 'any'); ?>>Cualquier condición</option>
            </select>
            <button type="button" class="button-link-delete evapp-wa-remove-rule">Eliminar regla</button>
        </div>

        <div class="evapp-wa-conditions">
            <table>
                <thead>
                    <tr>
                        <th style="width:32%;">Campo</th>
                        <th style="width:24%;">Operador</th>
                        <th>Valor</th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( (array) $rule['conditions'] as $condition_index => $condition ) : ?>
                        <?php eventosapp_whatsapp_render_condition_row($rule_index, $condition_index, $condition, $fields, $operators); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="button button-small evapp-wa-add-condition">+ Agregar condición</button></p>
            <p class="evapp-wa-muted">Una regla sin condiciones aplica para todos los tickets.</p>
        </div>
    </div>
    <?php
}

/**
 * Render individual de condición.
 */
function eventosapp_whatsapp_render_condition_row($rule_index, $condition_index, $condition, $fields, $operators) {
    $condition = wp_parse_args((array)$condition, [
        'field' => 'cedula',
        'operator' => 'equals',
        'value' => '',
    ]);
    ?>
    <tr>
        <td>
            <select name="eventosapp_whatsapp_rules[<?php echo esc_attr($rule_index); ?>][conditions][<?php echo esc_attr($condition_index); ?>][field]">
                <?php foreach ( $fields as $key => $label ) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($condition['field'], $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <select name="eventosapp_whatsapp_rules[<?php echo esc_attr($rule_index); ?>][conditions][<?php echo esc_attr($condition_index); ?>][operator]">
                <?php foreach ( $operators as $key => $label ) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($condition['operator'], $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="text" name="eventosapp_whatsapp_rules[<?php echo esc_attr($rule_index); ?>][conditions][<?php echo esc_attr($condition_index); ?>][value]" value="<?php echo esc_attr($condition['value']); ?>" placeholder="Valor a comparar">
        </td>
        <td><button type="button" class="button-link-delete evapp-wa-remove-condition">Quitar</button></td>
    </tr>
    <?php
}

/**
 * Guarda reglas por evento.
 */
add_action('save_post_eventosapp_event', function($post_id) {
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision($post_id) ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;

    if ( ! isset($_POST['eventosapp_whatsapp_rules_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_rules_nonce'], 'eventosapp_whatsapp_rules_save') ) {
        return;
    }

    $raw_rules = isset($_POST['eventosapp_whatsapp_rules']) && is_array($_POST['eventosapp_whatsapp_rules']) ? $_POST['eventosapp_whatsapp_rules'] : [];
    $rules = eventosapp_whatsapp_normalize_rules($raw_rules);
    update_post_meta($post_id, '_eventosapp_whatsapp_rules', $rules);
}, 30);

/**
 * Metabox manual en el ticket para ver historial y reenviar por WhatsApp.
 */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_ticket_whatsapp',
        'WhatsApp del Ticket',
        'eventosapp_whatsapp_render_ticket_metabox',
        'eventosapp_ticket',
        'side',
        'default'
    );

    add_meta_box(
        'eventosapp_ticket_whatsapp_diagnostics',
        'Diagnóstico WhatsApp del Ticket',
        'eventosapp_whatsapp_render_ticket_diagnostics_metabox',
        'eventosapp_ticket',
        'normal',
        'default'
    );
});

function eventosapp_whatsapp_render_ticket_metabox($post) {
    $event_id = absint(get_post_meta($post->ID, '_eventosapp_ticket_evento_id', true));
    $settings = eventosapp_whatsapp_get_settings();
    $sender_settings = $event_id ? eventosapp_whatsapp_resolve_sender_settings($event_id, $settings) : $settings;
    $event_enabled = $event_id ? get_post_meta($event_id, '_eventosapp_ticket_whatsapp_enabled', true) : '0';
    $phone = get_post_meta($post->ID, '_eventosapp_asistente_tel', true);
    $normalized_phone = eventosapp_whatsapp_normalize_phone($phone, $settings['default_country_code'] ?? '57');
    $status = get_post_meta($post->ID, '_eventosapp_whatsapp_last_status', true);
    $tracking = eventosapp_whatsapp_get_send_tracking($post->ID, true);
    $last_at = $tracking['last_sent_at'] ?: get_post_meta($post->ID, '_eventosapp_whatsapp_last_sent_at', true);
    $first_at = $tracking['first_sent_at'] ?? '';
    $last_error = get_post_meta($post->ID, '_eventosapp_whatsapp_last_error', true);
    $last_message_id = get_post_meta($post->ID, '_eventosapp_whatsapp_last_message_id', true);
    $delivery_status = get_post_meta($post->ID, '_eventosapp_whatsapp_delivery_status', true);
    $delivery_at = get_post_meta($post->ID, '_eventosapp_whatsapp_delivery_at', true);
    $last_transport = get_post_meta($post->ID, '_eventosapp_whatsapp_last_transport', true);
    $last_template = get_post_meta($post->ID, '_eventosapp_whatsapp_last_template_name', true);
    $last_http = get_post_meta($post->ID, '_eventosapp_whatsapp_last_http_code', true);
    $history = get_post_meta($post->ID, '_eventosapp_whatsapp_history', true);
    if ( ! is_array($history) ) {
        $history = [];
    }

    $send_url = wp_nonce_url(add_query_arg([
        'action' => 'eventosapp_send_ticket_whatsapp',
        'ticket_id' => $post->ID,
    ], admin_url('admin-post.php')), 'eventosapp_send_ticket_whatsapp_' . $post->ID);
    ?>
    <style>
        .evapp-wa-side-status{padding:8px 10px;border-radius:6px;background:#f6f7f7;border-left:4px solid #72aee6;margin:8px 0;}
        .evapp-wa-side-warning{border-left-color:#dba617;background:#fff8e5;}
        .evapp-wa-side-error{border-left-color:#b32d2e;background:#fcf0f1;}
        .evapp-wa-side-ok{border-left-color:#00a32a;background:#edfaef;}
        .evapp-wa-send-summary{padding:10px;border-radius:4px;margin:12px 0;border:1px solid #cbd5e1;background:#f8fafc;}
        .evapp-wa-send-summary strong{display:block;margin-bottom:5px;}
        .evapp-wa-send-summary span{display:block;font-size:12px;line-height:1.45;}
        .evapp-wa-send-summary-ok{background:#d1fae5;border-color:#10b981;color:#065f46;}
        .evapp-wa-send-summary-ok strong{color:#047857;}
        .evapp-wa-send-summary-empty{background:#fee2e2;border-color:#ef4444;color:#7f1d1d;}
        .evapp-wa-send-summary-empty strong{color:#dc2626;}
        .evapp-wa-side-small{font-size:12px;color:#646970;line-height:1.45;}
        .evapp-wa-history-mini{margin-left:16px;list-style:disc;}
        .evapp-wa-history-mini li{margin-bottom:7px;}
        .evapp-wa-break{word-break:break-word;overflow-wrap:anywhere;}
    </style>

    <p><strong>Celular registrado:</strong><br><?php echo $phone ? esc_html($phone) : '<span style="color:#b32d2e;">Sin celular</span>'; ?></p>
    <p><strong>Celular normalizado:</strong><br><?php echo $normalized_phone ? esc_html($normalized_phone) : '<span style="color:#b32d2e;">No válido</span>'; ?></p>
    <p><strong>WhatsApp global:</strong> <?php echo ! empty($settings['enabled']) && $settings['enabled'] === '1' ? 'Activo' : 'Inactivo'; ?></p>
    <p><strong>WhatsApp en evento:</strong> <?php echo $event_enabled === '1' ? 'Activo' : 'Inactivo'; ?></p>
    <p><strong>Número emisor:</strong><br>
        <?php echo esc_html($sender_settings['sender_phone_label'] ?? ($settings['phone_number_label'] ?? 'Número WhatsApp')); ?>
        <?php if ( ! empty($sender_settings['sender_phone_number_id']) ) : ?><br><small class="evapp-wa-break">ID: <?php echo esc_html($sender_settings['sender_phone_number_id']); ?></small><?php endif; ?>
    </p>

    <?php if ( ($tracking['sent_status'] ?? '') === 'enviado' && ( $first_at || $last_at ) ) : ?>
        <div class="evapp-wa-send-summary evapp-wa-send-summary-ok">
            <strong>✓ Estado: WhatsApp enviado</strong>
            <?php if ( $first_at ) : ?>
                <span><strong style="display:inline;margin:0;color:inherit;">Primer envío:</strong> <?php echo esc_html(eventosapp_whatsapp_format_datetime($first_at)); ?></span>
            <?php endif; ?>
            <?php if ( $last_at ) : ?>
                <span><strong style="display:inline;margin:0;color:inherit;">Último envío:</strong> <?php echo esc_html(eventosapp_whatsapp_format_datetime($last_at)); ?></span>
            <?php endif; ?>
            <?php if ( ! empty($tracking['last_to']) ) : ?>
                <span><strong style="display:inline;margin:0;color:inherit;">Último destino:</strong> <?php echo esc_html($tracking['last_to']); ?></span>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="evapp-wa-send-summary evapp-wa-send-summary-empty">
            <strong>✗ Estado: WhatsApp no enviado</strong>
            <span>Aún no hay una solicitud aceptada por Meta para este ticket.</span>
        </div>
    <?php endif; ?>

    <?php
    $box_class = 'evapp-wa-side-status';
    if ( in_array($status, ['error', 'failed', 'fallido_webhook'], true) || $last_error ) {
        $box_class .= ' evapp-wa-side-error';
    } elseif ( in_array($delivery_status, ['delivered', 'read'], true) ) {
        $box_class .= ' evapp-wa-side-ok';
    } elseif ( in_array($status, ['aceptado_meta', 'enviado'], true) && ( $delivery_status === '' || $delivery_status === 'pendiente_webhook' ) ) {
        $box_class .= ' evapp-wa-side-warning';
    }
    ?>

    <div class="<?php echo esc_attr($box_class); ?>">
        <strong>Último estado local:</strong><br>
        <?php echo esc_html(eventosapp_whatsapp_status_label($status)); ?>
        <?php if ( $last_at ) : ?><br><small><?php echo esc_html($last_at); ?></small><?php endif; ?>
        <?php if ( $last_http ) : ?><br><small>HTTP Meta: <?php echo esc_html((string) $last_http); ?></small><?php endif; ?>
    </div>

    <p><strong>Estado de entrega webhook:</strong><br>
        <?php echo esc_html($delivery_status ? eventosapp_whatsapp_status_label($delivery_status) : 'Sin webhook recibido'); ?>
        <?php echo $delivery_at ? '<br><small>' . esc_html($delivery_at) . '</small>' : ''; ?>
    </p>

    <?php if ( $last_transport ) : ?>
        <p><strong>Método usado:</strong><br><?php echo esc_html($last_transport === 'template' ? 'Plantilla aprobada por Meta' : 'Mensaje libre / fallback'); ?></p>
    <?php endif; ?>
    <?php if ( $last_template ) : ?>
        <p><strong>Plantilla:</strong><br><span class="evapp-wa-break"><small><?php echo esc_html($last_template); ?></small></span></p>
    <?php endif; ?>
    <?php if ( $last_message_id ) : ?><p><strong>Message ID Meta:</strong><br><span class="evapp-wa-break"><small><?php echo esc_html($last_message_id); ?></small></span></p><?php endif; ?>
    <?php if ( $last_error ) : ?><p style="color:#b32d2e;"><strong>Error:</strong><br><?php echo esc_html($last_error); ?></p><?php endif; ?>

    <?php if ( in_array($status, ['aceptado_meta', 'enviado'], true) && ( $delivery_status === '' || $delivery_status === 'pendiente_webhook' ) ) : ?>
        <p class="evapp-wa-side-small" style="background:#fff8e5;border-left:4px solid #dba617;padding:8px;">
            Meta aceptó la solicitud, pero esto no confirma entrega. Para saber si llegó, debe entrar un webhook de estado: enviado, entregado, leído o fallido.
        </p>
    <?php endif; ?>

    <p><a class="button button-secondary" href="<?php echo esc_url($send_url); ?>">Enviar / reenviar WhatsApp</a></p>
    <p class="evapp-wa-side-small">El envío manual respeta la configuración global y el celular del asistente, pero omite las reglas de filtro para permitir reenvíos administrativos.</p>

    <?php if ( ! empty($history) ) : ?>
        <hr>
        <strong>Historial reciente</strong>
        <ul class="evapp-wa-history-mini">
            <?php foreach ( array_slice(array_reverse($history), 0, 6) as $entry ) : ?>
                <li>
                    <span class="evapp-wa-break"><?php echo esc_html(($entry['date'] ?? '') . ' - ' . eventosapp_whatsapp_status_label($entry['status'] ?? '')); ?></span>
                    <?php if ( ! empty($entry['http_code']) ) : ?><br><small>HTTP: <?php echo esc_html((string)$entry['http_code']); ?></small><?php endif; ?>
                    <?php if ( ! empty($entry['transport']) ) : ?><br><small><?php echo esc_html($entry['transport'] === 'template' ? 'Plantilla' : 'Libre/fallback'); ?></small><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php
}


/**
 * Metabox adicional para personalizar el diseño de WhatsApp y la landing del evento.
 *
 * Importante: esta configuración pertenece al CPT del evento. Los valores antiguos
 * que pudieron quedar guardados en tickets se conservan únicamente como respaldo
 * para no romper tickets ya creados.
 */
if ( ! function_exists('eventosapp_whatsapp_event_post_types') ) {
    function eventosapp_whatsapp_event_post_types() {
        $post_types = [ 'eventosapp_event', 'eventosapp_events' ];
        $post_types = array_values(array_unique(array_filter($post_types, static function($post_type) {
            return is_string($post_type) && $post_type !== '';
        })));

        return apply_filters('eventosapp_whatsapp_event_post_types', $post_types);
    }
}

if ( ! function_exists('eventosapp_whatsapp_active_event_post_types') ) {
    function eventosapp_whatsapp_active_event_post_types() {
        $active = [];
        foreach ( eventosapp_whatsapp_event_post_types() as $post_type ) {
            if ( post_type_exists($post_type) ) {
                $active[] = $post_type;
            }
        }
        return $active ?: [ 'eventosapp_event' ];
    }
}

if ( ! function_exists('eventosapp_whatsapp_is_event_post_type') ) {
    function eventosapp_whatsapp_is_event_post_type($post_type) {
        return in_array((string) $post_type, eventosapp_whatsapp_event_post_types(), true);
    }
}

if ( ! function_exists('eventosapp_whatsapp_get_event_modalidad_for_admin') ) {
    function eventosapp_whatsapp_get_event_modalidad_for_admin($event_id) {
        $event_id = absint($event_id);
        $modalidad = '';

        if ( $event_id && function_exists('eventosapp_get_event_modalidad') ) {
            $modalidad = eventosapp_get_event_modalidad($event_id);
        }
        if ( $modalidad === '' && $event_id ) {
            $modalidad = get_post_meta($event_id, '_eventosapp_event_modalidad', true);
        }
        if ( $modalidad === '' && $event_id ) {
            $modalidad = get_post_meta($event_id, '_eventosapp_modalidad_evento', true);
        }

        if ( function_exists('eventosapp_normalize_event_modalidad') ) {
            return eventosapp_normalize_event_modalidad($modalidad ?: 'presencial');
        }

        $modalidad = sanitize_key((string) $modalidad);
        return in_array($modalidad, [ 'presencial', 'virtual', 'presencial_virtual' ], true) ? $modalidad : 'presencial';
    }
}

if ( ! function_exists('eventosapp_whatsapp_event_template_modalities') ) {
    function eventosapp_whatsapp_event_template_modalities($event_id) {
        $mode = eventosapp_whatsapp_get_event_modalidad_for_admin($event_id);
        if ( $mode === 'virtual' ) {
            return [ 'virtual' ];
        }
        if ( $mode === 'presencial_virtual' ) {
            return [ 'presencial', 'virtual' ];
        }
        return [ 'presencial' ];
    }
}

if ( ! function_exists('eventosapp_whatsapp_template_matches_modality') ) {
    function eventosapp_whatsapp_template_matches_modality($template, $modality, $include_custom = true) {
        if ( ! is_array($template) ) {
            return false;
        }

        $modality = sanitize_key((string) $modality);
        $template_modality = sanitize_key((string)($template['modality'] ?? 'custom'));
        $base_key = sanitize_key((string)($template['base_key'] ?? ''));

        if ( $template_modality === $modality || $base_key === $modality ) {
            return true;
        }

        return $include_custom && $template_modality === 'custom';
    }
}

if ( ! function_exists('eventosapp_whatsapp_get_event_selected_template_id') ) {
    function eventosapp_whatsapp_get_event_selected_template_id($event_id, $modality) {
        $event_id = absint($event_id);
        $modality = sanitize_key((string) $modality);
        if ( ! $event_id || ! in_array($modality, [ 'presencial', 'virtual' ], true) ) {
            return '';
        }

        $template_id = get_post_meta($event_id, '_eventosapp_whatsapp_template_' . $modality . '_id', true);
        if ( $template_id === '' ) {
            $template_id = get_post_meta($event_id, '_eventosapp_whatsapp_' . $modality . '_template_id', true);
        }

        return sanitize_key((string) $template_id);
    }
}

if ( ! function_exists('eventosapp_whatsapp_get_event_selected_sender_phone_number_id') ) {
    function eventosapp_whatsapp_get_event_selected_sender_phone_number_id($event_id) {
        $event_id = absint($event_id);
        if ( ! $event_id ) {
            return '';
        }
        return eventosapp_whatsapp_sanitize_phone_number_id(get_post_meta($event_id, '_eventosapp_whatsapp_sender_phone_number_id', true));
    }
}

if ( ! function_exists('eventosapp_whatsapp_render_event_sender_phone_select') ) {
    function eventosapp_whatsapp_render_event_sender_phone_select($event_id) {
        $event_id = absint($event_id);
        $settings = eventosapp_whatsapp_get_settings();
        $accounts = eventosapp_whatsapp_get_phone_accounts($settings);
        $current = eventosapp_whatsapp_get_event_selected_sender_phone_number_id($event_id);
        $default_phone_number_id = eventosapp_whatsapp_sanitize_phone_number_id($settings['phone_number_id'] ?? '');
        ?>
        <label for="evapp_eventosapp_whatsapp_sender_phone_number_id">Número emisor WhatsApp del evento</label>
        <div class="evapp-wa-visual-field">
            <?php if ( empty($accounts) ) : ?>
                <p class="evapp-wa-visual-help" style="margin-top:0;">
                    Aún no hay números emisores configurados. Configura primero el Phone Number ID por defecto en <strong>WhatsApp Tickets → API de WhatsApp Cloud</strong>.
                </p>
            <?php else : ?>
                <select id="evapp_eventosapp_whatsapp_sender_phone_number_id" name="eventosapp_whatsapp_sender_phone_number_id" data-default-phone-number-id="<?php echo esc_attr($default_phone_number_id); ?>">
                    <option value=""><?php echo esc_html('Automático: número por defecto' . ($default_phone_number_id && isset($accounts[$default_phone_number_id]) ? ' — ' . $accounts[$default_phone_number_id]['label'] : '')); ?></option>
                    <?php foreach ( $accounts as $account_id => $account ) : ?>
                        <option value="<?php echo esc_attr($account_id); ?>" <?php selected($current, $account_id); ?>><?php echo esc_html($account['label']); ?></option>
                    <?php endforeach; ?>
                    <?php if ( $current !== '' && ! isset($accounts[$current]) ) : ?>
                        <option value="<?php echo esc_attr($current); ?>" selected><?php echo esc_html('Número guardado no disponible — ' . $current); ?></option>
                    <?php endif; ?>
                </select>
                <p class="evapp-wa-visual-help">
                    Este número se usará en envíos manuales del ticket, envíos masivos, envíos automáticos al crear tickets y recordatorios del evento. Si lo dejas en automático, se usará el número por defecto global.
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}

if ( ! function_exists('eventosapp_whatsapp_get_templates_for_select') ) {
    function eventosapp_whatsapp_get_templates_for_select($modality) {
        $modality = sanitize_key((string) $modality);
        if ( ! function_exists('eventosapp_whatsapp_templates_get_settings') ) {
            return [];
        }

        $settings = eventosapp_whatsapp_templates_get_settings();
        $templates = isset($settings['templates']) && is_array($settings['templates']) ? $settings['templates'] : [];
        $options = [];

        foreach ( $templates as $template_id => $template ) {
            if ( ! is_array($template) ) {
                continue;
            }
            $template['id'] = sanitize_key((string)($template['id'] ?? $template_id));
            if ( $template['id'] === '' || ! eventosapp_whatsapp_template_matches_modality($template, $modality, true) ) {
                continue;
            }
            $options[$template['id']] = $template;
        }

        uasort($options, static function($a, $b) use ($modality) {
            $a_exact = eventosapp_whatsapp_template_matches_modality($a, $modality, false) ? 0 : 1;
            $b_exact = eventosapp_whatsapp_template_matches_modality($b, $modality, false) ? 0 : 1;
            if ( $a_exact !== $b_exact ) {
                return $a_exact <=> $b_exact;
            }

            $a_approved = function_exists('eventosapp_whatsapp_is_template_approved') && eventosapp_whatsapp_is_template_approved($a) ? 0 : 1;
            $b_approved = function_exists('eventosapp_whatsapp_is_template_approved') && eventosapp_whatsapp_is_template_approved($b) ? 0 : 1;
            if ( $a_approved !== $b_approved ) {
                return $a_approved <=> $b_approved;
            }

            return strcasecmp((string)($a['title'] ?? $a['name'] ?? ''), (string)($b['title'] ?? $b['name'] ?? ''));
        });

        return $options;
    }
}

if ( ! function_exists('eventosapp_whatsapp_template_status_label') ) {
    function eventosapp_whatsapp_template_status_label($template) {
        $status = strtoupper((string)($template['meta_status'] ?? 'LOCAL'));
        $labels = [
            'APPROVED'      => 'Aprobada',
            'ACTIVE'        => 'Aprobada',
            'PENDING'       => 'Pendiente',
            'IN_APPEAL'     => 'En apelación',
            'REJECTED'      => 'Rechazada',
            'PAUSED'        => 'Pausada',
            'DISABLED'      => 'Deshabilitada',
            'LOCAL'         => 'Local sin aprobar',
            ''              => 'Local sin aprobar',
        ];
        return $labels[$status] ?? $status;
    }
}

if ( ! function_exists('eventosapp_whatsapp_get_template_sender_phone_number_id') ) {
    function eventosapp_whatsapp_get_template_sender_phone_number_id($template, $settings = null) {
        $settings = is_array($settings) ? wp_parse_args($settings, eventosapp_whatsapp_default_settings()) : eventosapp_whatsapp_get_settings();
        $sender = is_array($template) ? eventosapp_whatsapp_sanitize_phone_number_id($template['sender_phone_number_id'] ?? '') : '';
        if ( $sender !== '' ) {
            return $sender;
        }
        return eventosapp_whatsapp_sanitize_phone_number_id($settings['phone_number_id'] ?? '');
    }
}

if ( ! function_exists('eventosapp_whatsapp_get_template_sender_label') ) {
    function eventosapp_whatsapp_get_template_sender_label($template, $settings = null) {
        $settings = is_array($settings) ? wp_parse_args($settings, eventosapp_whatsapp_default_settings()) : eventosapp_whatsapp_get_settings();
        $sender = eventosapp_whatsapp_get_template_sender_phone_number_id($template, $settings);
        $account = eventosapp_whatsapp_get_phone_account($sender, $settings);
        if ( is_array($account) ) {
            return sanitize_text_field((string)($account['label'] ?? ($account['alias'] ?? $sender)));
        }
        return $sender !== '' ? $sender : 'Número por defecto';
    }
}

if ( ! function_exists('eventosapp_whatsapp_template_matches_sender') ) {
    function eventosapp_whatsapp_template_matches_sender($template, $sender_phone_number_id = '', $allow_empty_sender_as_default = true) {
        if ( ! is_array($template) ) {
            return false;
        }
        $settings = eventosapp_whatsapp_get_settings();
        $expected = eventosapp_whatsapp_sanitize_phone_number_id($sender_phone_number_id);
        if ( $expected === '' ) {
            $expected = eventosapp_whatsapp_sanitize_phone_number_id($settings['phone_number_id'] ?? '');
        }

        $template_sender_raw = eventosapp_whatsapp_sanitize_phone_number_id($template['sender_phone_number_id'] ?? '');
        if ( $template_sender_raw === '' && $allow_empty_sender_as_default ) {
            $template_sender_raw = eventosapp_whatsapp_sanitize_phone_number_id($settings['phone_number_id'] ?? '');
        }

        return $expected !== '' && $template_sender_raw !== '' && $expected === $template_sender_raw;
    }
}

if ( ! function_exists('eventosapp_whatsapp_render_event_template_select') ) {
    function eventosapp_whatsapp_render_event_template_select($event_id, $modality) {
        $event_id = absint($event_id);
        $modality = sanitize_key((string) $modality);
        $label = $modality === 'virtual' ? 'Plantilla WhatsApp para modalidad virtual' : 'Plantilla WhatsApp para modalidad presencial';
        $field_name = 'eventosapp_whatsapp_template_' . $modality . '_id';
        $field_id = 'evapp_' . $field_name;
        $current = eventosapp_whatsapp_get_event_selected_template_id($event_id, $modality);
        $templates = eventosapp_whatsapp_get_templates_for_select($modality);
        $templates_page = admin_url('admin.php?page=eventosapp_whatsapp_templates');
        $settings = eventosapp_whatsapp_get_settings();
        $event_sender_phone_number_id = eventosapp_whatsapp_get_event_sender_phone_number_id($event_id, $settings);
        ?>
        <label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($label); ?></label>
        <div class="evapp-wa-visual-field">
            <?php if ( empty($templates) ) : ?>
                <p class="evapp-wa-visual-help" style="margin-top:0;">
                    No hay plantillas locales para esta modalidad. Crea o sincroniza plantillas desde
                    <a href="<?php echo esc_url($templates_page); ?>">Plantillas WhatsApp</a>.
                </p>
            <?php else : ?>
                <select id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($field_name); ?>" class="evapp-wa-template-select">
                    <option value=""><?php echo esc_html($modality === 'virtual' ? 'Automática: primera plantilla virtual aprobada' : 'Automática: primera plantilla presencial aprobada'); ?></option>
                    <?php foreach ( $templates as $template_id => $template ) :
                        $status_label = eventosapp_whatsapp_template_status_label($template);
                        $title = trim((string)($template['title'] ?? ''));
                        $name = trim((string)($template['name'] ?? ''));
                        $template_sender = eventosapp_whatsapp_get_template_sender_phone_number_id($template, $settings);
                        $template_sender_label = eventosapp_whatsapp_get_template_sender_label($template, $settings);
                        $option_label = ($title !== '' ? $title : $name) . ($name !== '' ? ' — ' . $name : '') . ' [' . $status_label . '] · ' . $template_sender_label;
                    ?>
                        <option value="<?php echo esc_attr($template_id); ?>" data-sender-phone-number-id="<?php echo esc_attr($template_sender); ?>" <?php selected($current, $template_id); ?>><?php echo esc_html($option_label); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="evapp-wa-visual-help">
                    Si eliges una plantilla no aprobada, EventosApp la mostrará en la configuración, pero al enviar usará una plantilla aprobada compatible como respaldo. Para envíos reales Meta exige estado aprobado. Las opciones se filtran en pantalla por el número emisor seleccionado para evitar usar una plantilla marcada para otro número.
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}

add_action('add_meta_boxes', function() {
    foreach ( eventosapp_whatsapp_active_event_post_types() as $screen ) {
        add_meta_box(
            'eventosapp_event_whatsapp_visuals',
            'Diseño WhatsApp y Landing',
            'eventosapp_whatsapp_render_event_visuals_metabox',
            $screen,
            'normal',
            'default'
        );
    }
}, 30);

function eventosapp_whatsapp_render_event_visuals_metabox($post) {
    $event_id = absint($post->ID);
    $visuals  = eventosapp_whatsapp_resolve_ticket_visual_images(0, $event_id);
    $modalidad_evento = eventosapp_whatsapp_get_event_modalidad_for_admin($event_id);
    $template_modalities = eventosapp_whatsapp_event_template_modalities($event_id);

    $modalidad_labels = [
        'presencial'         => 'Presencial',
        'virtual'            => 'Virtual',
        'presencial_virtual' => 'Presencial y Virtual',
    ];

    $fields = [
        'eventosapp_whatsapp_landing_header_img' => [
            'meta'        => '_eventosapp_whatsapp_landing_header_img',
            'label'       => 'Cabezote personalizado para la landing del ticket',
            'description' => 'Imagen superior que se usará en la landing pública del ticket cuando aplique. Si se deja vacía, se usará el cabezote del email del evento o el valor por defecto.',
            'effective'   => $visuals['landing_header'],
        ],
        'eventosapp_whatsapp_qr_header_img' => [
            'meta'        => '_eventosapp_whatsapp_qr_header_img',
            'label'       => 'Imagen por defecto para cabezote QR WhatsApp',
            'description' => 'Esta imagen NO reemplaza el QR. EventosApp toma el QR real del ticket presencial y genera una composición con este cabezote encima del QR. Medida exacta recomendada: 1000 x 160 px, en JPG o PNG. Si subes otra proporción, el sistema la centrará completa para evitar cortes, pero puede dejar franjas blancas.',
            'effective'   => $visuals['qr_header'],
        ],
        'eventosapp_whatsapp_virtual_message_img' => [
            'meta'        => '_eventosapp_whatsapp_virtual_message_img',
            'label'       => 'Imagen para mensajes WhatsApp de modalidad virtual',
            'description' => 'Se usará como imagen del mensaje para tickets virtuales. El botón o enlace del ticket virtual dirigirá a la landing virtual existente del evento.',
            'effective'   => $visuals['virtual_message_image'],
        ],
    ];

    wp_enqueue_media();
    wp_nonce_field('eventosapp_whatsapp_event_visuals_save', 'eventosapp_whatsapp_event_visuals_nonce');
    ?>
    <style>
        .evapp-wa-visual-grid{display:grid;grid-template-columns:260px minmax(280px,1fr);gap:14px 18px;align-items:start;max-width:1040px;}
        .evapp-wa-visual-grid label{font-weight:700;padding-top:7px;}
        .evapp-wa-visual-field input[type="text"],.evapp-wa-visual-field select{width:100%;max-width:720px;}
        .evapp-wa-visual-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:7px;}
        .evapp-wa-visual-help{font-size:12px;color:#646970;margin:5px 0 0;line-height:1.45;}
        .evapp-wa-visual-preview{display:flex;align-items:center;gap:12px;margin-top:9px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:10px;max-width:720px;}
        .evapp-wa-visual-preview img{max-width:180px;max-height:86px;width:auto;height:auto;background:#fff;border:1px solid #dcdcde;border-radius:6px;object-fit:cover;}
        .evapp-wa-visual-preview code{word-break:break-all;white-space:normal;}
        .evapp-wa-visual-note{background:#f0f6fc;border-left:4px solid #72aee6;padding:10px 12px;margin:0 0 14px;line-height:1.45;max-width:1040px;}
        .evapp-wa-visual-section-title{grid-column:1 / -1;margin:8px 0 0;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;font-weight:700;}
        @media (max-width: 900px){.evapp-wa-visual-grid{grid-template-columns:1fr;}.evapp-wa-visual-grid label{padding-top:0;}}
    </style>

    <div class="evapp-wa-visual-note">
        <strong>Ubicación correcta de esta configuración:</strong> ahora este metabox pertenece al evento.
        La modalidad actual del evento es <strong><?php echo esc_html($modalidad_labels[$modalidad_evento] ?? 'Presencial'); ?></strong>.
        Por eso se mostrarán únicamente los campos de plantilla que corresponden: presencial, virtual o ambos.
    </div>

    <div class="evapp-wa-visual-grid">
        <div class="evapp-wa-visual-section-title">Imágenes del evento para WhatsApp y landing</div>
        <?php foreach ( $fields as $field_name => $field ) :
            $current = esc_url_raw((string) get_post_meta($event_id, $field['meta'], true));
            $effective = esc_url_raw((string) $field['effective']);
            $input_id = 'evapp_' . sanitize_key($field_name);
        ?>
            <label for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($field['label']); ?></label>
            <div class="evapp-wa-visual-field">
                <input type="text" id="<?php echo esc_attr($input_id); ?>" class="evapp-wa-visual-url" name="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_attr($current); ?>" placeholder="https://.../imagen.jpg">
                <div class="evapp-wa-visual-actions">
                    <button type="button" class="button evapp-wa-visual-select" data-target="#<?php echo esc_attr($input_id); ?>">Seleccionar imagen</button>
                    <button type="button" class="button evapp-wa-visual-clear" data-target="#<?php echo esc_attr($input_id); ?>">Quitar personalizada</button>
                </div>
                <p class="evapp-wa-visual-help"><?php echo esc_html($field['description']); ?></p>
                <?php if ( $effective ) : ?>
                    <div class="evapp-wa-visual-preview">
                        <img src="<?php echo esc_url($effective); ?>" alt="Imagen efectiva">
                        <div>
                            <strong>Imagen efectiva actual</strong><br>
                            <code><?php echo esc_html($effective); ?></code>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="evapp-wa-visual-section-title">Número emisor WhatsApp del evento</div>
        <?php eventosapp_whatsapp_render_event_sender_phone_select($event_id); ?>

        <div class="evapp-wa-visual-section-title">Plantillas WhatsApp por modalidad</div>
        <?php foreach ( $template_modalities as $template_modality ) : ?>
            <?php eventosapp_whatsapp_render_event_template_select($event_id, $template_modality); ?>
        <?php endforeach; ?>
    </div>

    <script>
    jQuery(function($){
        var evappWaVisualFrame = null;
        $('.evapp-wa-visual-select').off('click.evappWaVisual').on('click.evappWaVisual', function(e){
            e.preventDefault();
            var targetSelector = $(this).data('target');
            if (!targetSelector) return;
            evappWaVisualFrame = wp.media({
                title: 'Seleccionar imagen',
                button: { text: 'Usar esta imagen' },
                library: { type: 'image' },
                multiple: false
            });
            evappWaVisualFrame.on('select', function(){
                var attachment = evappWaVisualFrame.state().get('selection').first().toJSON();
                if (attachment && attachment.url) {
                    $(targetSelector).val(attachment.url).trigger('change');
                }
            });
            evappWaVisualFrame.open();
        });
        $('.evapp-wa-visual-clear').off('click.evappWaVisual').on('click.evappWaVisual', function(e){
            e.preventDefault();
            var targetSelector = $(this).data('target');
            if (targetSelector) $(targetSelector).val('').trigger('change');
        });

        function evappWaFilterTemplatesBySender(){
            var $sender = $('#evapp_eventosapp_whatsapp_sender_phone_number_id');
            var defaultSender = String($sender.data('default-phone-number-id') || '');
            var selectedSender = String($sender.val() || defaultSender);
            $('.evapp-wa-template-select').each(function(){
                var $select = $(this);
                var current = String($select.val() || '');
                var currentVisible = true;
                $select.find('option').each(function(){
                    var $option = $(this);
                    if (!$option.val()) {
                        $option.prop('disabled', false).show();
                        return;
                    }
                    var optionSender = String($option.data('sender-phone-number-id') || defaultSender);
                    var matches = !selectedSender || !optionSender || optionSender === selectedSender;
                    $option.prop('disabled', !matches).toggle(matches);
                    if (current && $option.val() === current && !matches) {
                        currentVisible = false;
                    }
                });
                if (!currentVisible) {
                    $select.val('');
                }
            });
        }
        $('#evapp_eventosapp_whatsapp_sender_phone_number_id').off('change.evappWaSenderFilter').on('change.evappWaSenderFilter', evappWaFilterTemplatesBySender);
        evappWaFilterTemplatesBySender();
    });
    </script>
    <?php
}

function eventosapp_whatsapp_save_event_visuals_metabox($post_id, $post = null, $update = false) {
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision($post_id) ) {
        return;
    }
    if ( ! isset($_POST['eventosapp_whatsapp_event_visuals_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_event_visuals_nonce'], 'eventosapp_whatsapp_event_visuals_save') ) {
        return;
    }
    if ( ! current_user_can('edit_post', $post_id) ) {
        return;
    }

    $post_type = get_post_type($post_id);
    if ( ! eventosapp_whatsapp_is_event_post_type($post_type) ) {
        return;
    }

    $image_fields = [
        'eventosapp_whatsapp_landing_header_img' => '_eventosapp_whatsapp_landing_header_img',
        'eventosapp_whatsapp_qr_header_img' => '_eventosapp_whatsapp_qr_header_img',
        'eventosapp_whatsapp_virtual_message_img' => '_eventosapp_whatsapp_virtual_message_img',
    ];

    foreach ( $image_fields as $request_key => $meta_key ) {
        $value = isset($_POST[$request_key]) ? esc_url_raw(trim((string) wp_unslash($_POST[$request_key]))) : '';
        if ( $value !== '' ) {
            update_post_meta($post_id, $meta_key, $value);
        } else {
            delete_post_meta($post_id, $meta_key);
        }
    }

    if ( array_key_exists('eventosapp_whatsapp_sender_phone_number_id', $_POST) ) {
        $selected_sender = eventosapp_whatsapp_sanitize_phone_number_id(wp_unslash($_POST['eventosapp_whatsapp_sender_phone_number_id']));
        $settings = eventosapp_whatsapp_get_settings();
        $accounts = eventosapp_whatsapp_get_phone_accounts($settings);
        $default_sender = eventosapp_whatsapp_sanitize_phone_number_id($settings['phone_number_id'] ?? '');

        if ( $selected_sender !== '' && isset($accounts[$selected_sender]) && $selected_sender !== $default_sender ) {
            update_post_meta($post_id, '_eventosapp_whatsapp_sender_phone_number_id', $selected_sender);
        } else {
            delete_post_meta($post_id, '_eventosapp_whatsapp_sender_phone_number_id');
        }
    }

    foreach ( [ 'presencial', 'virtual' ] as $modality ) {
        $request_key = 'eventosapp_whatsapp_template_' . $modality . '_id';
        if ( ! array_key_exists($request_key, $_POST) ) {
            continue;
        }
        $template_id = sanitize_key((string) wp_unslash($_POST[$request_key]));
        if ( $template_id !== '' ) {
            $template_matches_sender = true;
            if ( function_exists('eventosapp_whatsapp_templates_get_settings') && function_exists('eventosapp_whatsapp_find_template_by_identifier') ) {
                $template_settings = eventosapp_whatsapp_templates_get_settings();
                $template_options = isset($template_settings['templates']) && is_array($template_settings['templates']) ? $template_settings['templates'] : [];
                $selected_template = eventosapp_whatsapp_find_template_by_identifier($template_options, $template_id);
                $event_sender_for_validation = eventosapp_whatsapp_get_event_sender_phone_number_id($post_id, eventosapp_whatsapp_get_settings());
                $template_matches_sender = ! $selected_template || eventosapp_whatsapp_template_matches_sender($selected_template, $event_sender_for_validation, true);
            }

            if ( $template_matches_sender ) {
                update_post_meta($post_id, '_eventosapp_whatsapp_template_' . $modality . '_id', $template_id);
            } else {
                delete_post_meta($post_id, '_eventosapp_whatsapp_template_' . $modality . '_id');
                eventosapp_whatsapp_add_activity_log('plantilla_evento_descartada_por_numero_incompatible', [
                    'event_id'   => $post_id,
                    'modality'   => $modality,
                    'template_id'=> $template_id,
                ]);
            }
        } else {
            delete_post_meta($post_id, '_eventosapp_whatsapp_template_' . $modality . '_id');
        }
    }
}
add_action('save_post_eventosapp_event', 'eventosapp_whatsapp_save_event_visuals_metabox', 30, 3);
add_action('save_post_eventosapp_events', 'eventosapp_whatsapp_save_event_visuals_metabox', 30, 3);

function eventosapp_whatsapp_render_ticket_diagnostics_metabox($post) {
    $history = get_post_meta($post->ID, '_eventosapp_whatsapp_history', true);
    if ( ! is_array($history) ) {
        $history = [];
    }

    $last_debug = get_post_meta($post->ID, '_eventosapp_whatsapp_last_debug', true);
    $last_payload = get_post_meta($post->ID, '_eventosapp_whatsapp_last_payload_summary', true);
    $last_response = get_post_meta($post->ID, '_eventosapp_whatsapp_last_response', true);
    $last_webhook = get_post_meta($post->ID, '_eventosapp_whatsapp_last_webhook_status_raw', true);
    ?>
    <style>
        .evapp-wa-diag-table{width:100%;border-collapse:collapse;background:#fff;}
        .evapp-wa-diag-table th,.evapp-wa-diag-table td{border:1px solid #dcdcde;padding:8px;text-align:left;vertical-align:top;}
        .evapp-wa-diag-table th{background:#f6f7f7;width:150px;}
        .evapp-wa-diag-muted{color:#646970;font-size:12px;}
        .evapp-wa-diag-badge{display:inline-block;padding:2px 7px;border-radius:999px;background:#f0f0f1;font-size:12px;}
    </style>
    <p class="evapp-wa-diag-muted">
        Este diagnóstico diferencia entre <strong>aceptado por Meta</strong> y <strong>entregado por WhatsApp</strong>. Un HTTP 200 con Message ID solo indica que Meta recibió la solicitud; la confirmación real llega por webhook de estado.
    </p>

    <h4>Última solicitud</h4>
    <table class="evapp-wa-diag-table">
        <tbody>
            <tr><th>Resumen técnico</th><td><?php eventosapp_whatsapp_render_log_details($last_debug ?: []); ?></td></tr>
            <tr><th>Payload enviado</th><td><?php eventosapp_whatsapp_render_log_details($last_payload ?: []); ?></td></tr>
            <tr><th>Respuesta Meta</th><td><?php eventosapp_whatsapp_render_log_details(eventosapp_whatsapp_summarize_response(is_array($last_response) ? $last_response : [], is_string($last_response) ? $last_response : '')); ?></td></tr>
            <tr><th>Último webhook</th><td><?php eventosapp_whatsapp_render_log_details($last_webhook ?: []); ?></td></tr>
        </tbody>
    </table>

    <h4>Historial detallado del ticket</h4>
    <?php if ( empty($history) ) : ?>
        <p>No hay actividad de WhatsApp registrada para este ticket.</p>
    <?php else : ?>
        <table class="evapp-wa-diag-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Estado</th>
                    <th>Contexto</th>
                    <th>Teléfono</th>
                    <th>HTTP</th>
                    <th>Detalle</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( array_reverse($history) as $entry ) : ?>
                    <tr>
                        <td><?php echo esc_html($entry['date'] ?? ''); ?></td>
                        <td><span class="evapp-wa-diag-badge"><?php echo esc_html(eventosapp_whatsapp_status_label($entry['status'] ?? '')); ?></span></td>
                        <td><?php echo esc_html($entry['context'] ?? ''); ?></td>
                        <td><?php echo esc_html($entry['to'] ?? ''); ?></td>
                        <td><?php echo esc_html((string)($entry['http_code'] ?? '')); ?></td>
                        <td>
                            <strong>Mensaje:</strong> <?php echo esc_html($entry['message'] ?? ''); ?><br>
                            <?php if ( ! empty($entry['message_id']) ) : ?><strong>Message ID:</strong> <span style="word-break:break-all;"><?php echo esc_html($entry['message_id']); ?></span><br><?php endif; ?>
                            <?php if ( ! empty($entry['transport']) ) : ?><strong>Método:</strong> <?php echo esc_html($entry['transport'] === 'template' ? 'Plantilla aprobada' : 'Mensaje libre/fallback'); ?><br><?php endif; ?>
                            <?php if ( ! empty($entry['template_name']) ) : ?><strong>Plantilla:</strong> <?php echo esc_html($entry['template_name']); ?><br><?php endif; ?>
                            <?php if ( ! empty($entry['delivery_status']) ) : ?><strong>Webhook:</strong> <?php echo esc_html(eventosapp_whatsapp_status_label($entry['delivery_status'])); ?><br><?php endif; ?>
                            <?php if ( ! empty($entry['debug']) ) : ?>
                                <details style="margin-top:6px;"><summary>Ver detalle técnico</summary><?php eventosapp_whatsapp_render_log_details($entry['debug']); ?></details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}

/**
 * Acción manual de envío por WhatsApp desde el ticket.
 */
add_action('admin_post_eventosapp_send_ticket_whatsapp', function() {
    $ticket_id = isset($_GET['ticket_id']) ? absint($_GET['ticket_id']) : 0;

    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        wp_die('Ticket inválido.');
    }

    if ( ! current_user_can('edit_post', $ticket_id) ) {
        wp_die('Permisos insuficientes.');
    }

    if ( ! wp_verify_nonce($_GET['_wpnonce'] ?? '', 'eventosapp_send_ticket_whatsapp_' . $ticket_id) ) {
        wp_die('Nonce inválido.');
    }

    $result = eventosapp_whatsapp_send_ticket($ticket_id, [
        'context' => 'manual_admin',
        'force' => true,
        'skip_rules' => true,
    ]);

    wp_safe_redirect(add_query_arg([
        'post' => $ticket_id,
        'action' => 'edit',
        'evapp_whatsapp' => ! empty($result['ok']) ? '1' : '0',
        'evapp_whatsapp_msg' => rawurlencode(! empty($result['message']) ? $result['message'] : (! empty($result['ok']) ? 'WhatsApp enviado.' : 'No se pudo enviar WhatsApp.')),
    ], admin_url('post.php')));
    exit;
});

add_action('admin_notices', function() {
    if ( ! is_admin() || ! isset($_GET['post'], $_GET['evapp_whatsapp']) ) return;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'eventosapp_ticket' ) return;

    $ok = sanitize_text_field(wp_unslash($_GET['evapp_whatsapp'])) === '1';
    $msg = isset($_GET['evapp_whatsapp_msg']) ? sanitize_text_field(wp_unslash($_GET['evapp_whatsapp_msg'])) : ($ok ? 'WhatsApp enviado.' : 'No se pudo enviar WhatsApp.');
    echo '<div class="' . ($ok ? 'notice notice-success' : 'notice notice-error') . ' is-dismissible"><p><strong>EventosApp WhatsApp:</strong> ' . esc_html($msg) . '</p></div>';
});

/**
 * Normaliza teléfono a formato internacional sin +.
 */
function eventosapp_whatsapp_normalize_phone($raw_phone, $default_country_code = '57') {
    $phone = preg_replace('/\D+/', '', (string) $raw_phone);
    $default_country_code = preg_replace('/\D+/', '', (string) $default_country_code);

    if ( $phone === '' ) {
        return '';
    }

    if ( strpos($phone, '00') === 0 ) {
        $phone = substr($phone, 2);
    }

    if ( $default_country_code && strpos($phone, $default_country_code) !== 0 ) {
        if ( strlen($phone) <= 10 ) {
            $phone = $default_country_code . ltrim($phone, '0');
        }
    }

    return strlen($phone) >= 8 ? $phone : '';
}

/**
 * Envía payload a WhatsApp Cloud API.
 */
function eventosapp_whatsapp_api_send_message($to, array $message_payload, $settings = null) {
    $settings = is_array($settings) ? wp_parse_args($settings, eventosapp_whatsapp_default_settings()) : eventosapp_whatsapp_get_settings();

    if ( empty($settings['enabled']) || $settings['enabled'] !== '1' ) {
        eventosapp_whatsapp_add_activity_log('api_cancelada_integracion_inactiva', [
            'to' => $to,
            'payload_type' => $message_payload['type'] ?? '',
        ]);
        return [
            'ok' => false,
            'message' => 'La integración global de WhatsApp no está activa.',
            'debug' => [
                'stage' => 'settings_validation',
                'reason' => 'global_disabled',
            ],
        ];
    }

    if ( ! empty($settings['dry_run']) && $settings['dry_run'] === '1' ) {
        $dry_payload = array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
        ], $message_payload);

        $debug = [
            'stage' => 'dry_run',
            'to' => $to,
            'payload_summary' => eventosapp_whatsapp_summarize_payload($dry_payload),
        ];
        eventosapp_whatsapp_add_activity_log('dry_run_mensaje_simulado', $debug);
        return [
            'ok' => true,
            'message' => 'Modo prueba: envío simulado correctamente.',
            'dry_run' => true,
            'response' => ['dry_run' => true],
            'payload_summary' => $debug['payload_summary'],
            'debug' => $debug,
        ];
    }

    $phone_number_id = preg_replace('/\D+/', '', (string)($settings['phone_number_id'] ?? ''));
    $access_token = trim((string)($settings['access_token'] ?? ''));
    $api_version = preg_replace('/[^a-zA-Z0-9\.\-_]/', '', (string)($settings['api_version'] ?? 'v23.0'));

    if ( $phone_number_id === '' || $access_token === '' ) {
        eventosapp_whatsapp_add_activity_log('api_cancelada_credenciales_incompletas', [
            'to' => $to,
            'phone_number_id_present' => $phone_number_id !== '',
            'access_token_present' => $access_token !== '',
            'payload_type' => $message_payload['type'] ?? '',
        ]);
        return [
            'ok' => false,
            'message' => 'Faltan Phone Number ID o Access Token en la configuración de WhatsApp.',
            'debug' => [
                'stage' => 'settings_validation',
                'reason' => 'missing_phone_number_id_or_token',
                'phone_number_id_present' => $phone_number_id !== '',
                'access_token_present' => $access_token !== '',
            ],
        ];
    }

    if ( $api_version === '' ) {
        $api_version = 'v23.0';
    }

    $endpoint = sprintf(
        'https://graph.facebook.com/%s/%s/messages',
        rawurlencode($api_version),
        rawurlencode($phone_number_id)
    );

    $payload = array_merge([
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $to,
    ], $message_payload);

    $payload_json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $payload_summary = eventosapp_whatsapp_summarize_payload($payload);
    $request_debug = [
        'stage' => 'request_ready',
        'api_version' => $api_version,
        'phone_number_id' => $phone_number_id,
        'sender_phone_number_id' => eventosapp_whatsapp_sanitize_phone_number_id($settings['sender_phone_number_id'] ?? $phone_number_id),
        'sender_phone_label' => sanitize_text_field((string)($settings['sender_phone_label'] ?? ($settings['phone_number_label'] ?? ''))),
        'endpoint' => $endpoint,
        'timeout' => min(60, max(5, absint($settings['request_timeout'] ?? 20))),
        'payload_bytes' => strlen((string) $payload_json),
        'payload_summary' => $payload_summary,
    ];

    eventosapp_whatsapp_add_activity_log('api_solicitud_enviada_a_meta', $request_debug);

    $response = wp_remote_post($endpoint, [
        'timeout' => $request_debug['timeout'],
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json',
        ],
        'body' => $payload_json,
    ]);

    if ( is_wp_error($response) ) {
        $debug = array_merge($request_debug, [
            'stage' => 'wp_http_error',
            'error' => $response->get_error_message(),
        ]);
        eventosapp_whatsapp_add_activity_log('api_error_wp_http', $debug);
        return [
            'ok' => false,
            'message' => $response->get_error_message(),
            'response' => null,
            'payload_summary' => $payload_summary,
            'debug' => $debug,
        ];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    $response_summary = eventosapp_whatsapp_summarize_response($decoded, $body);

    $ok = $code >= 200 && $code < 300;
    $message_id = eventosapp_whatsapp_extract_message_id($decoded);
    $debug = array_merge($request_debug, [
        'stage' => $ok ? 'meta_accepted' : 'meta_rejected',
        'http_code' => $code,
        'message_id' => $message_id,
        'response_summary' => $response_summary,
    ]);

    if ( ! $ok && is_array($decoded) && ! empty($decoded['error']) ) {
        $debug['meta_error'] = eventosapp_whatsapp_sanitize_log_context($decoded['error']);
    }

    eventosapp_whatsapp_add_activity_log($ok ? 'api_respuesta_meta_aceptada' : 'api_respuesta_meta_error', $debug);

    return [
        'ok' => $ok,
        'message' => $ok ? ($message_id ? 'Solicitud aceptada por Meta. ID: ' . $message_id . '. Esperando webhook de entrega.' : 'Solicitud aceptada por Meta. Esperando webhook de entrega.') : eventosapp_whatsapp_extract_api_error($decoded, $body, $code),
        'http_code' => $code,
        'message_id' => $message_id,
        'response' => $decoded ?: $body,
        'payload_summary' => $payload_summary,
        'response_summary' => $response_summary,
        'debug' => $debug,
    ];
}

function eventosapp_whatsapp_extract_api_error($decoded, $body, $code) {
    if ( is_array($decoded) && ! empty($decoded['error']['message']) ) {
        return 'Meta API: ' . sanitize_text_field($decoded['error']['message']);
    }
    if ( $body !== '' ) {
        return 'Meta API HTTP ' . (int)$code . ': ' . sanitize_text_field(wp_trim_words($body, 30, '...'));
    }
    return 'Meta API HTTP ' . (int)$code;
}

function eventosapp_whatsapp_extract_message_id($decoded) {
    if ( is_array($decoded) && ! empty($decoded['messages'][0]['id']) ) {
        return sanitize_text_field((string) $decoded['messages'][0]['id']);
    }
    return '';
}

function eventosapp_whatsapp_build_template_payload($template_name, $language_code = 'en_US', $components = []) {
    $template_name = sanitize_key((string) $template_name);
    $language_code = sanitize_text_field((string) $language_code);

    if ( $template_name === '' ) {
        $template_name = 'hello_world';
    }
    if ( $language_code === '' ) {
        $language_code = 'en_US';
    }

    $template = [
        'name' => $template_name,
        'language' => [
            'code' => $language_code,
        ],
    ];

    if ( is_array($components) && ! empty($components) ) {
        $template['components'] = $components;
    }

    return [
        'type' => 'template',
        'template' => $template,
    ];
}

/**
 * Detecta si el estado remoto de una plantilla permite usarla para envíos reales.
 */
function eventosapp_whatsapp_is_template_approved($template) {
    if ( ! is_array($template) ) {
        return false;
    }
    $status = strtoupper((string)($template['meta_status'] ?? ''));
    return in_array($status, ['APPROVED', 'ACTIVE'], true) && ! empty($template['name']) && ! empty($template['language']);
}

/**
 * Normaliza identificadores locales/remotos de plantilla para comparación.
 */
function eventosapp_whatsapp_template_lookup_key($value) {
    $value = trim((string) $value);
    if ( $value === '' ) {
        return '';
    }
    return sanitize_key($value);
}

/**
 * Prepara una plantilla local para uso runtime sin depender de si el option está
 * indexado por ID local, nombre de Meta o algún ID heredado.
 */
function eventosapp_whatsapp_prepare_runtime_template($template, $fallback_id = '') {
    if ( ! is_array($template) ) {
        return [];
    }

    $fallback_id = eventosapp_whatsapp_template_lookup_key($fallback_id);
    $template['id'] = eventosapp_whatsapp_template_lookup_key($template['id'] ?? $fallback_id);
    if ( $template['id'] === '' ) {
        $template['id'] = $fallback_id;
    }

    $template['name'] = sanitize_key((string)($template['name'] ?? ''));
    $template['language'] = sanitize_text_field((string)($template['language'] ?? 'es'));
    $template['modality'] = sanitize_key((string)($template['modality'] ?? 'custom'));
    $template['base_key'] = sanitize_key((string)($template['base_key'] ?? $template['modality']));
    $template['meta_status'] = strtoupper(sanitize_text_field((string)($template['meta_status'] ?? 'LOCAL')));
    $template['meta_template_id'] = sanitize_text_field((string)($template['meta_template_id'] ?? ''));
    $template['sender_phone_number_id'] = eventosapp_whatsapp_sanitize_phone_number_id($template['sender_phone_number_id'] ?? '');
    $template['sender_phone_label'] = sanitize_text_field((string)($template['sender_phone_label'] ?? ''));
    $template['waba_id'] = eventosapp_whatsapp_sanitize_waba_id($template['waba_id'] ?? '');

    return $template;
}

/**
 * Busca una plantilla en el arreglo local por cualquiera de sus identificadores.
 * Soluciona el caso en que el select guarda template['id'], pero el option quedó
 * indexado por otra clave local después de editar/sincronizar/duplicar.
 */
function eventosapp_whatsapp_find_template_by_identifier($templates, $identifier) {
    if ( ! is_array($templates) ) {
        return null;
    }

    $needle = eventosapp_whatsapp_template_lookup_key($identifier);
    if ( $needle === '' ) {
        return null;
    }

    foreach ( $templates as $template_key => $template ) {
        if ( ! is_array($template) ) {
            continue;
        }

        $runtime_template = eventosapp_whatsapp_prepare_runtime_template($template, $template_key);
        $lookup_values = [
            $template_key,
            $runtime_template['id'] ?? '',
            $runtime_template['name'] ?? '',
            $runtime_template['meta_template_id'] ?? '',
        ];

        foreach ( $lookup_values as $lookup_value ) {
            if ( eventosapp_whatsapp_template_lookup_key($lookup_value) === $needle ) {
                $runtime_template['_storage_key'] = sanitize_key((string)$template_key);
                return $runtime_template;
            }
        }
    }

    return null;
}

/**
 * Determina si para este ticket se debe preferir plantilla virtual o presencial.
 */
function eventosapp_whatsapp_get_ticket_template_modality($ticket_id, $event_id = 0) {
    $ticket_id = absint($ticket_id);
    $event_id = absint($event_id ?: get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));

    $raw = '';
    if ( function_exists('eventosapp_get_ticket_modalidad') ) {
        $raw = eventosapp_get_ticket_modalidad($ticket_id);
    }
    if ( $raw === '' ) {
        $raw = get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
    }
    if ( $raw === '' && $event_id ) {
        $raw = get_post_meta($event_id, '_eventosapp_modalidad_evento', true);
    }
    if ( $raw === '' && $event_id ) {
        $raw = get_post_meta($event_id, '_eventosapp_event_modality', true);
    }

    $norm = function_exists('remove_accents') ? remove_accents((string)$raw) : (string)$raw;
    $norm = strtolower($norm);

    if ( strpos($norm, 'virtual') !== false && strpos($norm, 'presencial') === false ) {
        return 'virtual';
    }

    return 'presencial';
}

/**
 * Obtiene el código público del ticket para URLs dinámicas de botones.
 */
function eventosapp_whatsapp_get_ticket_public_code($ticket_id) {
    $ticket_id = absint($ticket_id);
    $public = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
    if ( ! $public ) {
        $public = get_post_meta($ticket_id, '_eventosapp_ticket_public_id', true);
    }
    if ( ! $public ) {
        $public = (string) $ticket_id;
    }
    return sanitize_text_field((string) $public);
}

/**
 * Busca la plantilla aprobada más adecuada para el ticket.
 */
function eventosapp_whatsapp_find_approved_template_for_ticket($ticket_id, $event_id = 0) {
    if ( ! function_exists('eventosapp_whatsapp_templates_get_settings') ) {
        return null;
    }

    $ticket_id = absint($ticket_id);
    $event_id = absint($event_id ?: get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $settings = eventosapp_whatsapp_templates_get_settings();
    $templates = isset($settings['templates']) && is_array($settings['templates']) ? $settings['templates'] : [];
    if ( empty($templates) ) {
        return null;
    }

    $preferred_modality = eventosapp_whatsapp_get_ticket_template_modality($ticket_id, $event_id);
    $sender_phone_number_id = $event_id ? eventosapp_whatsapp_get_event_sender_phone_number_id($event_id, eventosapp_whatsapp_get_settings()) : eventosapp_whatsapp_sanitize_phone_number_id(eventosapp_whatsapp_get_settings()['phone_number_id'] ?? '');

    // 1) Prioridad absoluta: plantilla escogida en el metabox del evento para
    // la modalidad real del ticket. Se busca por ID local, storage key, nombre
    // de Meta o meta_template_id para evitar que caiga a la plantilla antigua.
    if ( $event_id && function_exists('eventosapp_whatsapp_get_event_selected_template_id') ) {
        $selected_template_id = eventosapp_whatsapp_get_event_selected_template_id($event_id, $preferred_modality);
        if ( $selected_template_id !== '' ) {
            $selected_template = eventosapp_whatsapp_find_template_by_identifier($templates, $selected_template_id);

            if ( $selected_template && eventosapp_whatsapp_is_template_approved($selected_template) && eventosapp_whatsapp_template_matches_sender($selected_template, $sender_phone_number_id, true) ) {
                $selected_template['_selection_source'] = 'event_metabox_' . $preferred_modality;
                eventosapp_whatsapp_add_activity_log('plantilla_evento_seleccionada_runtime', [
                    'ticket_id'            => $ticket_id,
                    'event_id'             => $event_id,
                    'preferred_modality'   => $preferred_modality,
                    'selected_template_id' => $selected_template_id,
                    'runtime_template_id'  => $selected_template['id'] ?? '',
                    'storage_key'          => $selected_template['_storage_key'] ?? '',
                    'template_name'        => $selected_template['name'] ?? '',
                    'language'             => $selected_template['language'] ?? '',
                    'meta_status'          => $selected_template['meta_status'] ?? '',
                    'meta_template_id'     => $selected_template['meta_template_id'] ?? '',
                    'sender_phone_number_id' => $sender_phone_number_id,
                    'template_sender_phone_number_id' => eventosapp_whatsapp_get_template_sender_phone_number_id($selected_template),
                ]);
                return $selected_template;
            }

            eventosapp_whatsapp_add_activity_log($selected_template ? 'plantilla_evento_no_aprobada' : 'plantilla_evento_no_encontrada', [
                'ticket_id'            => $ticket_id,
                'event_id'             => $event_id,
                'preferred_modality'   => $preferred_modality,
                'selected_template_id' => $selected_template_id,
                'template_found'       => $selected_template ? 1 : 0,
                'selected_template_name' => $selected_template['name'] ?? '',
                'selected_template_status' => $selected_template['meta_status'] ?? '',
                'sender_phone_number_id' => $sender_phone_number_id,
                'template_sender_phone_number_id' => $selected_template ? eventosapp_whatsapp_get_template_sender_phone_number_id($selected_template) : '',
                'sender_matches'        => $selected_template ? (eventosapp_whatsapp_template_matches_sender($selected_template, $sender_phone_number_id, true) ? 1 : 0) : 0,
                'reason'               => $selected_template
                    ? 'La plantilla seleccionada en el evento existe, pero no está aprobada/activa en Meta o está marcada para otro número. Se buscará un respaldo aprobado del mismo número emisor.'
                    : 'La plantilla seleccionada en el evento no se encontró en el option local de plantillas. Se buscará un respaldo aprobado.',
            ]);
        }
    }

    // 2) Respaldo: primera plantilla aprobada que coincida exactamente con la
    // modalidad. Se mantiene para no bloquear envíos si el evento no tiene
    // selección o si la selección todavía no está aprobada.
    $fallback = null;

    foreach ( $templates as $template_id => $template ) {
        if ( ! is_array($template) ) {
            continue;
        }

        $template = eventosapp_whatsapp_prepare_runtime_template($template, $template_id);
        if ( empty($template) || ! eventosapp_whatsapp_is_template_approved($template) || ! eventosapp_whatsapp_template_matches_sender($template, $sender_phone_number_id, true) ) {
            continue;
        }

        $matches_modality = function_exists('eventosapp_whatsapp_template_matches_modality')
            ? eventosapp_whatsapp_template_matches_modality($template, $preferred_modality, false)
            : false;

        if ( $matches_modality ) {
            $template['_selection_source'] = 'approved_modality_fallback';
            eventosapp_whatsapp_add_activity_log('plantilla_respaldo_modalidad_runtime', [
                'ticket_id'          => $ticket_id,
                'event_id'           => $event_id,
                'preferred_modality' => $preferred_modality,
                'template_id'        => $template['id'] ?? '',
                'template_name'      => $template['name'] ?? '',
                'meta_status'        => $template['meta_status'] ?? '',
                'sender_phone_number_id' => $sender_phone_number_id,
                'template_sender_phone_number_id' => eventosapp_whatsapp_get_template_sender_phone_number_id($template),
            ]);
            return $template;
        }

        $template_modality = sanitize_key((string)($template['modality'] ?? 'custom'));
        if ( $fallback === null && in_array($template_modality, ['custom', 'presencial', 'virtual'], true) ) {
            $fallback = $template;
        }
    }

    if ( $fallback ) {
        $fallback['_selection_source'] = 'approved_general_fallback';
        eventosapp_whatsapp_add_activity_log('plantilla_respaldo_general_runtime', [
            'ticket_id'          => $ticket_id,
            'event_id'           => $event_id,
            'preferred_modality' => $preferred_modality,
            'template_id'        => $fallback['id'] ?? '',
            'template_name'      => $fallback['name'] ?? '',
            'meta_status'        => $fallback['meta_status'] ?? '',
            'sender_phone_number_id' => $sender_phone_number_id,
            'template_sender_phone_number_id' => eventosapp_whatsapp_get_template_sender_phone_number_id($fallback),
        ]);
    }

    return $fallback;
}

/**
 * Cuenta variables numéricas tipo {{1}}, {{2}} usadas por el cuerpo de una plantilla.
 */
function eventosapp_whatsapp_get_template_body_variable_count($body_text) {
    preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string)$body_text, $matches);
    if ( empty($matches[1]) ) {
        return 0;
    }
    $numbers = array_map('absint', $matches[1]);
    return max($numbers);
}

/**
 * Extrae variables únicas del cuerpo en orden de aparición.
 */
function eventosapp_whatsapp_extract_template_body_variable_numbers($body_text) {
    $numbers = [];

    if ( preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string)$body_text, $matches) ) {
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
 * Sanitiza el mapa guardado al enviar la plantilla a Meta.
 */
function eventosapp_whatsapp_sanitize_template_body_variable_map($map) {
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
 * Devuelve el orden real de parámetros BODY que debe enviarse a Meta.
 *
 * Compatibilidad:
 * - Plantillas nuevas enviadas con el módulo de plantillas guardan
 *   body_variable_map y usan exactamente ese orden.
 * - Plantillas antiguas sin mapa conservan el comportamiento anterior: envían
 *   parámetros desde {{1}} hasta el número máximo detectado.
 */
function eventosapp_whatsapp_get_runtime_body_variable_numbers($template) {
    $template = is_array($template) ? $template : [];
    $body_text = (string)($template['body_text'] ?? '');
    $saved_map = eventosapp_whatsapp_sanitize_template_body_variable_map($template['body_variable_map'] ?? []);
    $saved_signature = sanitize_text_field((string)($template['body_variable_signature'] ?? ''));
    $current_signature = md5($body_text);

    if ( ! empty($saved_map) && $saved_signature !== '' && hash_equals($saved_signature, $current_signature) ) {
        return $saved_map;
    }

    $max_count = eventosapp_whatsapp_get_template_body_variable_count($body_text);
    if ( $max_count < 1 ) {
        return [];
    }

    return range(1, $max_count);
}

/**
 * Valores dinámicos estándar para plantillas WhatsApp de ticket.
 */
function eventosapp_whatsapp_get_template_values_for_ticket($ticket_id, $event_id = 0) {
    $ticket_id = absint($ticket_id);
    $event_id = absint($event_id ?: get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $nombre = trim(get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' . get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true));
    if ( $nombre === '' ) {
        $nombre = 'Asistente';
    }

    $fecha = $event_id ? eventosapp_whatsapp_get_event_date_label($event_id) : '';
    $hora_inicio = $event_id ? get_post_meta($event_id, '_eventosapp_hora_inicio', true) : '';
    $hora_cierre = $event_id ? get_post_meta($event_id, '_eventosapp_hora_cierre', true) : '';
    $hora = trim($hora_inicio . ($hora_cierre ? ' - ' . $hora_cierre : ''));

    $modality = eventosapp_whatsapp_get_ticket_template_modality($ticket_id, $event_id);
    $place_or_platform = '';
    if ( $modality === 'virtual' ) {
        $place_or_platform = $event_id ? get_post_meta($event_id, '_eventosapp_virtual_platform', true) : '';
        if ( $place_or_platform === '' ) {
            $place_or_platform = 'Plataforma virtual';
        }
    } else {
        $place_or_platform = $event_id ? get_post_meta($event_id, '_eventosapp_direccion', true) : '';
        if ( $place_or_platform === '' ) {
            $place_or_platform = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);
        }
        if ( $place_or_platform === '' ) {
            $place_or_platform = 'Lugar del evento';
        }
    }

    $ticket_public = eventosapp_whatsapp_get_ticket_public_code($ticket_id);
    $landing_url = eventosapp_whatsapp_public_ticket_landing_url($ticket_public);
    if ( $modality === 'virtual' && function_exists('eventosapp_get_virtual_landing_url') ) {
        $virtual_landing = eventosapp_get_virtual_landing_url($ticket_id);
        if ( $virtual_landing ) {
            $landing_url = $virtual_landing;
        }
    }

    $platform_direct_url = function_exists('eventosapp_get_ticket_virtual_platform_url') ? eventosapp_get_ticket_virtual_platform_url($ticket_id) : ($event_id ? get_post_meta($event_id, '_eventosapp_virtual_url', true) : '');
    if ( $platform_direct_url === '' ) {
        $platform_direct_url = 'Enlace de plataforma virtual';
    }

    $organizador = $event_id ? (function_exists('eventosapp_get_nombre_organizador') ? eventosapp_get_nombre_organizador($event_id) : get_post_meta($event_id, '_eventosapp_organizador', true)) : '';
    if ( $organizador === '' ) {
        $organizador = 'Organizador del evento';
    }

    return [
        1 => $nombre,
        2 => $event_id ? get_the_title($event_id) : 'Evento',
        3 => $fecha ?: 'Fecha del evento',
        4 => $hora ?: 'Hora del evento',
        5 => $place_or_platform,
        6 => $landing_url,
        7 => $organizador,
        8 => $modality === 'virtual' ? 'Virtual' : 'Presencial',
        9 => $platform_direct_url,
    ];
}

if ( ! function_exists('eventosapp_whatsapp_runtime_template_button_enabled') ) {
    /**
     * Compatibilidad: las plantillas anteriores no guardaban estado del botón.
     * Si no existe el meta de activación, se considera activo para no romper
     * plantillas ya aprobadas.
     */
    function eventosapp_whatsapp_runtime_template_button_enabled($template, $button_number) {
        $template = is_array($template) ? $template : [];
        $button_number = absint($button_number);
        $key = 'button_' . $button_number . '_enabled';

        if ( ! array_key_exists($key, $template) ) {
            return true;
        }

        $raw = strtolower(trim((string) $template[$key]));
        return ! in_array($raw, ['0', 'no', 'false', 'off'], true);
    }
}

/**
 * Construye componentes runtime de plantilla para enviar el ticket.
 */
function eventosapp_whatsapp_build_ticket_template_components($template, $ticket_id, $event_id, $qr_url = '') {
    $components = [];
    $debug = [
        'template_name' => $template['name'] ?? '',
        'template_language' => $template['language'] ?? '',
        'header_format' => $template['header_format'] ?? '',
        'body_variable_count' => 0,
        'button_variable_components' => 0,
    ];

    if ( ! empty($template['header_format']) && strtoupper((string)$template['header_format']) === 'IMAGE' ) {
        if ( $qr_url === '' ) {
            return [
                'ok' => false,
                'message' => 'La plantilla aprobada requiere encabezado de imagen, pero no se pudo obtener una imagen pública para el ticket.',
                'components' => [],
                'debug' => $debug,
            ];
        }
        $components[] = [
            'type' => 'header',
            'parameters' => [
                [
                    'type' => 'image',
                    'image' => [
                        'link' => $qr_url,
                    ],
                ],
            ],
        ];
    }

    $values = eventosapp_whatsapp_get_template_values_for_ticket($ticket_id, $event_id);
    $body_variable_numbers = eventosapp_whatsapp_get_runtime_body_variable_numbers($template);
    $body_count = count($body_variable_numbers);
    $debug['body_variable_count'] = $body_count;
    $debug['body_variable_numbers'] = $body_variable_numbers;
    $debug['body_variable_map_source'] = ! empty($template['body_variable_map']) ? 'stored_meta_map' : 'legacy_max_range';

    if ( $body_count > 0 ) {
        $params = [];
        foreach ( $body_variable_numbers as $variable_number ) {
            $variable_number = absint($variable_number);
            $params[] = [
                'type' => 'text',
                'text' => sanitize_text_field((string)($values[$variable_number] ?? '-')),
            ];
        }
        $components[] = [
            'type' => 'body',
            'parameters' => $params,
        ];
    }

    $button_index = 0;
    foreach ( [1, 2] as $i ) {
        if ( ! eventosapp_whatsapp_runtime_template_button_enabled($template, $i) ) {
            continue;
        }

        $url = (string)($template['button_' . $i . '_url'] ?? '');
        if ( function_exists('eventosapp_whatsapp_templates_normalize_button_url_for_storage') ) {
            $url = eventosapp_whatsapp_templates_normalize_button_url_for_storage($url, $template['modality'] ?? '', $i);
        }
        $text = (string)($template['button_' . $i . '_text'] ?? '');
        if ( $text === '' || $url === '' ) {
            continue;
        }

        if ( strpos($url, '{{1}}') !== false ) {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => (string)$button_index,
                'parameters' => [
                    [
                        'type' => 'text',
                        'text' => eventosapp_whatsapp_get_ticket_public_code($ticket_id),
                    ],
                ],
            ];
            $debug['button_variable_components']++;
        }

        $button_index++;
    }

    $debug['components_count'] = count($components);

    return [
        'ok' => true,
        'message' => 'Componentes de plantilla construidos.',
        'components' => $components,
        'debug' => $debug,
    ];
}

/**
 * Construye el payload final del ticket. Prioriza plantillas aprobadas y usa mensaje libre solo como respaldo.
 */
function eventosapp_whatsapp_build_ticket_payload($ticket_id, $message, $qr_url = '') {
    $ticket_id = absint($ticket_id);
    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $template = eventosapp_whatsapp_find_approved_template_for_ticket($ticket_id, $event_id);

    if ( $template ) {
        $components_result = eventosapp_whatsapp_build_ticket_template_components($template, $ticket_id, $event_id, $qr_url);
        if ( ! empty($components_result['ok']) ) {
            return [
                'transport' => 'template',
                'template_name' => sanitize_text_field((string)$template['name']),
                'template_language' => sanitize_text_field((string)$template['language']),
                'payload' => eventosapp_whatsapp_build_template_payload(
                    $template['name'],
                    $template['language'],
                    $components_result['components']
                ),
                'debug' => [
                    'selected_transport' => 'template',
                    'template' => [
                        'id' => $template['id'] ?? '',
                        'name' => $template['name'] ?? '',
                        'language' => $template['language'] ?? '',
                        'modality' => $template['modality'] ?? '',
                        'meta_status' => $template['meta_status'] ?? '',
                        'meta_template_id' => $template['meta_template_id'] ?? '',
                        'sender_phone_number_id' => eventosapp_whatsapp_get_template_sender_phone_number_id($template),
                        'sender_phone_label' => eventosapp_whatsapp_get_template_sender_label($template),
                    ],
                    'components' => $components_result['debug'],
                ],
            ];
        }

        eventosapp_whatsapp_add_activity_log('template_aprobada_no_utilizable', [
            'ticket_id' => $ticket_id,
            'template_name' => $template['name'] ?? '',
            'reason' => $components_result['message'] ?? '',
            'debug' => $components_result['debug'] ?? [],
        ]);
    }

    if ( $qr_url ) {
        $payload = [
            'type' => 'image',
            'image' => [
                'link' => $qr_url,
                'caption' => $message,
            ],
        ];
    } else {
        $payload = [
            'type' => 'text',
            'text' => [
                'preview_url' => true,
                'body' => $message,
            ],
        ];
    }

    return [
        'transport' => 'freeform',
        'template_name' => '',
        'template_language' => '',
        'payload' => $payload,
        'debug' => [
            'selected_transport' => 'freeform',
            'reason' => $template ? 'approved_template_unusable_fallback' : 'no_approved_template_found',
            'warning' => 'Los mensajes libres pueden no iniciar conversaciones fuera de la ventana de atención de WhatsApp. Para entregas transaccionales se recomienda plantilla aprobada.',
            'media_image_url_present' => $qr_url !== '',
        ],
    ];
}

function eventosapp_whatsapp_api_send_template($to, $template_name, $language_code = 'en_US', $components = [], $settings = null) {
    return eventosapp_whatsapp_api_send_message(
        $to,
        eventosapp_whatsapp_build_template_payload($template_name, $language_code, $components),
        $settings
    );
}

function eventosapp_whatsapp_store_last_test_result($phone, $type, $result) {
    $settings = eventosapp_whatsapp_get_settings();
    $message_id = eventosapp_whatsapp_extract_message_id($result['response'] ?? []);

    $settings['last_test_result'] = [
        'date' => current_time('mysql'),
        'to' => sanitize_text_field($phone),
        'type' => sanitize_text_field($type),
        'ok' => ! empty($result['ok']) ? 1 : 0,
        'http_code' => isset($result['http_code']) ? (int) $result['http_code'] : 0,
        'message_id' => $message_id,
        'delivery_status' => '',
        'message' => sanitize_text_field((string)($result['message'] ?? '')),
    ];

    update_option(EVENTOSAPP_WHATSAPP_OPTION, $settings, false);

    if ( $message_id !== '' ) {
        eventosapp_whatsapp_register_message_map($message_id, 0, 'quick_test', $phone);
    }
}

function eventosapp_whatsapp_get_message_map() {
    $map = get_option('eventosapp_whatsapp_message_map', []);
    return is_array($map) ? $map : [];
}

function eventosapp_whatsapp_register_message_map($message_id, $ticket_id = 0, $context = '', $phone = '') {
    $message_id = sanitize_text_field((string)$message_id);
    if ( $message_id === '' ) {
        return;
    }

    $map = eventosapp_whatsapp_get_message_map();
    $map[$message_id] = [
        'ticket_id' => absint($ticket_id),
        'context' => sanitize_text_field((string)$context),
        'phone' => sanitize_text_field((string)$phone),
        'created_at' => current_time('mysql'),
    ];

    if ( count($map) > 500 ) {
        $map = array_slice($map, -500, null, true);
    }

    update_option('eventosapp_whatsapp_message_map', $map, false);
}

/**
 * Obtiene o genera el QR específico de WhatsApp.
 */
function eventosapp_whatsapp_ensure_qr_url($ticket_id) {
    $ticket_id = absint($ticket_id);
    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        return '';
    }

    $candidates = [];

    $add_candidate = static function($url, $source = '') use (&$candidates) {
        $url = esc_url_raw((string) $url);
        if ( $url === '' ) {
            return;
        }
        $candidates[] = [
            'url'    => $url,
            'source' => sanitize_text_field((string) $source),
        ];
    };

    $all_qrs = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
    if ( is_array($all_qrs) ) {
        // Primero el QR específico de WhatsApp, luego los QR ya existentes del
        // ticket. Todos representan el código real del ticket y sirven para la
        // composición visual con cabezote.
        foreach ( [ 'whatsapp', 'pdf', 'email', 'ticket', 'checkin', 'counter', 'wallet', 'default' ] as $key ) {
            if ( ! empty($all_qrs[$key]['url']) ) {
                $add_candidate($all_qrs[$key]['url'], '_eventosapp_qr_codes.' . $key);
            }
        }
        foreach ( $all_qrs as $key => $qr_data ) {
            if ( is_array($qr_data) && ! empty($qr_data['url']) ) {
                $add_candidate($qr_data['url'], '_eventosapp_qr_codes.' . sanitize_key((string)$key));
            }
        }
    }

    foreach ( [ '_eventosapp_ticket_qr_url', '_eventosapp_wallet_android_qr_url', '_eventosapp_qr_url' ] as $meta_key ) {
        $add_candidate(get_post_meta($ticket_id, $meta_key, true), $meta_key);
    }

    // Intenta crear el QR específico para WhatsApp con el QR Manager si está disponible.
    $manager = null;
    if ( isset($GLOBALS['eventosapp_qr_manager_instance']) && $GLOBALS['eventosapp_qr_manager_instance'] instanceof EventosApp_QR_Manager ) {
        $manager = $GLOBALS['eventosapp_qr_manager_instance'];
    } elseif ( function_exists('eventosapp_qr_manager_init') ) {
        $manager = eventosapp_qr_manager_init();
    }

    if ( $manager && method_exists($manager, 'generate_qr_code') ) {
        $qr = $manager->generate_qr_code($ticket_id, 'whatsapp');
        if ( is_array($qr) && ! empty($qr['url']) ) {
            $add_candidate($qr['url'], 'qr_manager.whatsapp');
        }
    }

    // Respaldo del sistema histórico de EventosApp: genera el PNG físico del
    // ticket en /uploads/eventosapp-qr/{ticketID}.png cuando todavía no existe.
    $ticket_code = eventosapp_whatsapp_get_ticket_public_code($ticket_id);
    if ( $ticket_code !== '' && function_exists('eventosapp_get_ticket_qr_url') ) {
        $add_candidate(eventosapp_get_ticket_qr_url($ticket_code), 'eventosapp_get_ticket_qr_url');
    }

    $candidates = apply_filters('eventosapp_whatsapp_qr_url_candidates', $candidates, $ticket_id);
    if ( ! is_array($candidates) ) {
        $candidates = [];
    }

    $seen = [];
    foreach ( $candidates as $candidate ) {
        $url = is_array($candidate) ? esc_url_raw((string)($candidate['url'] ?? '')) : esc_url_raw((string)$candidate);
        if ( $url === '' || isset($seen[$url]) ) {
            continue;
        }
        $seen[$url] = true;

        // Si es local, validamos que exista. Si no se puede resolver a path,
        // igual se permite porque puede ser CDN o una URL remota pública.
        $local_path = eventosapp_whatsapp_url_to_local_path($url);
        if ( $local_path !== '' && ( ! file_exists($local_path) || ! is_readable($local_path) ) ) {
            continue;
        }

        eventosapp_whatsapp_add_activity_log('qr_whatsapp_resuelto', [
            'ticket_id' => $ticket_id,
            'source'    => is_array($candidate) ? ($candidate['source'] ?? '') : 'candidate',
            'qr_url'    => $url,
            'local_path'=> $local_path,
        ]);

        return $url;
    }

    eventosapp_whatsapp_add_activity_log('qr_whatsapp_no_resuelto', [
        'ticket_id' => $ticket_id,
        'sources_checked' => array_values(array_filter(array_map(static function($candidate) {
            return is_array($candidate) ? ($candidate['source'] ?? '') : 'candidate';
        }, $candidates))),
    ]);

    return '';
}

/**
 * Prepara los recursos públicos que usa WhatsApp antes de mostrar o enviar el ticket.
 *
 * Este punto común evita que frontend, webhook o importación masiva generen links/QR
 * con la configuración base cuando el ticket realmente tiene una variante aplicada.
 */
function eventosapp_whatsapp_prepare_ticket_assets($ticket_id, $args = []) {
    $ticket_id = absint($ticket_id);
    $defaults = [
        'event_id'               => 0,
        'context'                => 'whatsapp_prepare',
        'apply_variant'          => true,
        'refresh_enabled_assets' => false,
        'ensure_qr'              => true,
        'ensure_landing'         => true,
        'ensure_message_image'   => false,
        'rebuild_search_index'   => true,
        'log'                    => true,
    ];
    $args = is_array($args) ? wp_parse_args($args, $defaults) : $defaults;

    $event_id = absint($args['event_id'] ?: get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $context  = sanitize_key((string) $args['context']);
    if ( $context === '' ) {
        $context = 'whatsapp_prepare';
    }

    $summary = [
        'ok'                  => false,
        'ticket_id'           => $ticket_id,
        'event_id'            => $event_id,
        'context'             => $context,
        'variant'             => null,
        'asset_refresh'       => null,
        'ticket_modalidad'    => '',
        'virtual_ticket'      => false,
        'public_code'         => '',
        'landing_url'         => '',
        'qr_url'              => '',
        'message_image_url'   => '',
        'errors'              => [],
    ];

    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' || ! $event_id ) {
        $summary['errors'][] = 'ticket_or_event_invalid';
        return $summary;
    }

    if ( function_exists('eventosapp_ticket_sync_modalidad') ) {
        eventosapp_ticket_sync_modalidad($ticket_id);
    }

    if ( ! empty($args['apply_variant']) ) {
        try {
            if ( function_exists('eventosapp_ticket_variants_prepare_ticket_for_frontend_context') ) {
                $summary['variant'] = eventosapp_ticket_variants_prepare_ticket_for_frontend_context($ticket_id, $event_id, $context, [
                    'sync_google_classes' => true,
                    'mark_assets_stale'   => false,
                    'clear_assets_stale'  => false,
                    'refresh_wallets'     => false,
                    'refresh_pdf_ics'     => false,
                    'rebuild_search_index'=> ! empty($args['rebuild_search_index']),
                    'log'                 => ! empty($args['log']),
                ]);
            } elseif ( function_exists('eventosapp_ticket_variants_apply_to_ticket') ) {
                $summary['variant'] = eventosapp_ticket_variants_apply_to_ticket($ticket_id, $event_id, true);
            }
        } catch (Throwable $e) {
            $summary['errors'][] = 'variant: ' . $e->getMessage();
        }
    }

    $is_virtual = function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id);
    $summary['ticket_modalidad'] = sanitize_key((string) get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true));
    $summary['virtual_ticket'] = (bool) $is_virtual;

    if ( ! empty($args['refresh_enabled_assets']) ) {
        try {
            if ( function_exists('eventosapp_ticket_variants_frontend_refresh_enabled_assets') ) {
                $summary['asset_refresh'] = eventosapp_ticket_variants_frontend_refresh_enabled_assets($ticket_id, $event_id, $context, [
                    'refresh_wallets'      => ! $is_virtual,
                    'refresh_pdf_ics'      => true,
                    'clear_assets_stale'   => true,
                    'rebuild_search_index' => false,
                    'log'                  => ! empty($args['log']),
                ]);
            } else {
                if ( function_exists('eventosapp_ticket_generar_ics') ) {
                    eventosapp_ticket_generar_ics($ticket_id);
                }
                if ( ! $is_virtual && function_exists('eventosapp_ticket_generar_pdf') ) {
                    eventosapp_ticket_generar_pdf($ticket_id);
                }
                if ( ! $is_virtual && get_post_meta($event_id, '_eventosapp_ticket_wallet_android', true) === '1' && function_exists('eventosapp_generar_enlace_wallet_android') ) {
                    eventosapp_generar_enlace_wallet_android($ticket_id, false);
                }
                if ( ! $is_virtual && get_post_meta($event_id, '_eventosapp_ticket_wallet_apple', true) === '1' ) {
                    if ( function_exists('eventosapp_apple_generate_pass') ) {
                        eventosapp_apple_generate_pass($ticket_id);
                    } elseif ( function_exists('eventosapp_generar_enlace_wallet_apple') ) {
                        eventosapp_generar_enlace_wallet_apple($ticket_id);
                    }
                }
                $summary['asset_refresh'] = ['fallback' => 'generators_executed_when_available'];
            }
        } catch (Throwable $e) {
            $summary['errors'][] = 'asset_refresh: ' . $e->getMessage();
        }
    }

    $public_code = eventosapp_whatsapp_get_ticket_public_code($ticket_id);
    $landing_url = eventosapp_whatsapp_public_ticket_landing_url($public_code);
    $summary['public_code'] = $public_code;
    $summary['landing_url'] = esc_url_raw($landing_url);

    if ( ! empty($args['ensure_landing']) ) {
        update_post_meta($ticket_id, '_eventosapp_whatsapp_ticket_public_code', $public_code);
        update_post_meta($ticket_id, '_eventosapp_whatsapp_ticket_landing_url', esc_url_raw($landing_url));
    }

    if ( ! empty($args['ensure_qr']) && ! $is_virtual ) {
        try {
            $manager = null;
            if ( isset($GLOBALS['eventosapp_qr_manager_instance']) && $GLOBALS['eventosapp_qr_manager_instance'] instanceof EventosApp_QR_Manager ) {
                $manager = $GLOBALS['eventosapp_qr_manager_instance'];
            } elseif ( class_exists('EventosApp_QR_Manager') && method_exists('EventosApp_QR_Manager', 'get_instance') ) {
                $manager = EventosApp_QR_Manager::get_instance();
            } elseif ( function_exists('eventosapp_qr_manager_init') ) {
                $manager = eventosapp_qr_manager_init();
            }

            if ( $manager && method_exists($manager, 'generate_qr_code') ) {
                $manager->generate_qr_code($ticket_id, 'whatsapp');
            }

            $summary['qr_url'] = eventosapp_whatsapp_ensure_qr_url($ticket_id);
        } catch (Throwable $e) {
            $summary['errors'][] = 'qr: ' . $e->getMessage();
        }
    }

    if ( ! empty($args['ensure_message_image']) ) {
        try {
            $summary['message_image_url'] = eventosapp_whatsapp_prepare_message_image_url($ticket_id, $summary['qr_url']);
            if ( $summary['message_image_url'] !== '' ) {
                update_post_meta($ticket_id, '_eventosapp_whatsapp_message_image_url', esc_url_raw($summary['message_image_url']));
            }
        } catch (Throwable $e) {
            $summary['errors'][] = 'message_image: ' . $e->getMessage();
        }
    }

    $summary['ok'] = empty($summary['errors']);
    update_post_meta($ticket_id, '_eventosapp_whatsapp_assets_last_context', $context);
    update_post_meta($ticket_id, '_eventosapp_whatsapp_assets_last_at', current_time('mysql'));
    update_post_meta($ticket_id, '_eventosapp_whatsapp_assets_last_result', eventosapp_whatsapp_sanitize_log_context($summary));

    if ( ! empty($args['log']) ) {
        eventosapp_whatsapp_add_activity_log('assets_whatsapp_preparados', $summary);
    }

    do_action('eventosapp_whatsapp_ticket_assets_prepared', $ticket_id, $event_id, $context, $summary);

    return $summary;
}

/**
 * Prepara anexos y dispara WhatsApp desde flujos de creación/actualización distintos al correo.
 */
function eventosapp_whatsapp_maybe_send_after_ticket_creation($ticket_id, $context = 'ticket_create', $args = []) {
    $ticket_id = absint($ticket_id);
    $context = sanitize_key((string) $context);
    if ( $context === '' ) {
        $context = 'ticket_create';
    }

    $args = is_array($args) ? $args : [];
    $event_id = absint($args['event_id'] ?? get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));

    $prepare = eventosapp_whatsapp_prepare_ticket_assets($ticket_id, [
        'event_id'               => $event_id,
        'context'                => $context,
        'apply_variant'          => true,
        'refresh_enabled_assets' => ! array_key_exists('refresh_enabled_assets', $args) || ! empty($args['refresh_enabled_assets']),
        'ensure_qr'              => true,
        'ensure_landing'         => true,
        'ensure_message_image'   => true,
        'rebuild_search_index'   => true,
        'log'                    => true,
    ]);

    $source_key = isset($args['source_key']) ? sanitize_text_field((string) $args['source_key']) : '';
    if ( $source_key === '' ) {
        $source_key = $context . ':' . $ticket_id . ':' . md5((string) get_post_modified_time('U', true, $ticket_id));
    }

    $send_result = eventosapp_whatsapp_send_ticket($ticket_id, [
        'context'    => $context,
        'source_key' => $source_key,
        'force'      => ! empty($args['force']),
        'skip_rules' => ! empty($args['skip_rules']),
    ]);

    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_creation_hook_context', $context);
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_creation_hook_result', eventosapp_whatsapp_sanitize_log_context([
        'prepare' => $prepare,
        'send'    => $send_result,
    ]));

    return [
        'prepare' => $prepare,
        'send'    => $send_result,
    ];
}

/**
 * Reemplaza variables del mensaje inicial.
 */
function eventosapp_whatsapp_replace_message_vars($template, $ticket_id, $event_id) {
    $evento_nombre = $event_id ? get_the_title($event_id) : '';
    $platform_url = function_exists('eventosapp_get_ticket_virtual_platform_url') ? eventosapp_get_ticket_virtual_platform_url($ticket_id) : ($event_id ? get_post_meta($event_id, '_eventosapp_virtual_url', true) : '');
    $virtual_access_url = function_exists('eventosapp_get_virtual_whatsapp_access_url') ? eventosapp_get_virtual_whatsapp_access_url($ticket_id) : '';
    $vars = [
        '{{nombre}}' => get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true),
        '{{apellido}}' => get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true),
        '{{evento_nombre}}' => $evento_nombre ? '*' . $evento_nombre . '*' : '',
        '{{ticket_id}}' => get_post_meta($ticket_id, 'eventosapp_ticketID', true),
        '{{enlace_plataforma_virtual}}' => $platform_url,
        '{{enlace_virtual_whatsapp}}' => $virtual_access_url,
    ];

    return strtr((string)$template, array_map('sanitize_text_field', $vars));
}

/**
 * Construye la fecha legible del evento.
 */
function eventosapp_whatsapp_get_event_date_label($event_id) {
    $tipo_fecha = get_post_meta($event_id, '_eventosapp_tipo_fecha', true) ?: 'unica';

    if ( $tipo_fecha === 'unica' ) {
        $fecha = get_post_meta($event_id, '_eventosapp_fecha_unica', true);
        return $fecha ? date_i18n('F d, Y', strtotime($fecha)) : '';
    }

    if ( $tipo_fecha === 'consecutiva' ) {
        $inicio = get_post_meta($event_id, '_eventosapp_fecha_inicio', true);
        $fin = get_post_meta($event_id, '_eventosapp_fecha_fin', true);
        if ( $inicio && $fin ) {
            return date_i18n('F d, Y', strtotime($inicio)) . ' - ' . date_i18n('F d, Y', strtotime($fin));
        }
        return $inicio ? date_i18n('F d, Y', strtotime($inicio)) : '';
    }

    $fechas = get_post_meta($event_id, '_eventosapp_fechas_noco', true);
    if ( is_array($fechas) && ! empty($fechas) ) {
        return implode(', ', array_map(function($fecha) {
            return date_i18n('F d, Y', strtotime($fecha));
        }, $fechas));
    }

    return '';
}

/**
 * Construye mensaje del ticket para WhatsApp.
 */
function eventosapp_whatsapp_build_ticket_message($ticket_id) {
    $ticket_id = absint($ticket_id);
    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    if ( ! $ticket_id || ! $event_id ) {
        return '';
    }

    $settings = eventosapp_whatsapp_get_settings();
    $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
    $nombre = trim(get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' . get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true));
    $localidad = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);
    $modalidad = function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label($ticket_id) : get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
    $fecha = eventosapp_whatsapp_get_event_date_label($event_id);
    $hora_inicio = get_post_meta($event_id, '_eventosapp_hora_inicio', true);
    $hora_cierre = get_post_meta($event_id, '_eventosapp_hora_cierre', true);
    $direccion = get_post_meta($event_id, '_eventosapp_direccion', true);
    $organizador = function_exists('eventosapp_get_nombre_organizador') ? eventosapp_get_nombre_organizador($event_id) : get_post_meta($event_id, '_eventosapp_organizador', true);
    $virtual_platform = get_post_meta($event_id, '_eventosapp_virtual_platform', true);
    $evento_nombre = get_the_title($event_id);
    $is_virtual = function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id);

    $google_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url', true);
    if ( ! $google_wallet ) $google_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android', true);

    $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url', true);
    if ( ! $apple_wallet ) $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple', true);
    if ( ! $apple_wallet ) $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url', true);

    $ics_url = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
    if ( ! $ics_url && function_exists('eventosapp_ticket_generar_ics') ) {
        eventosapp_ticket_generar_ics($ticket_id);
        $ics_url = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
    }

    $pdf_url = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
    if ( ! $pdf_url && function_exists('eventosapp_ticket_generar_pdf') && ! $is_virtual ) {
        eventosapp_ticket_generar_pdf($ticket_id);
        $pdf_url = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
    }

    $virtual_landing = '';
    if ( $is_virtual && function_exists('eventosapp_get_virtual_landing_url') ) {
        $virtual_landing = eventosapp_get_virtual_landing_url($ticket_id);
    }
    $virtual_access_url = '';
    if ( $is_virtual ) {
        if ( function_exists('eventosapp_get_virtual_whatsapp_access_url') ) {
            $virtual_access_url = eventosapp_get_virtual_whatsapp_access_url($ticket_id);
        } else {
            $virtual_access_url = $virtual_landing;
        }
    }

    $public_landing = eventosapp_whatsapp_public_ticket_landing_url(eventosapp_whatsapp_get_ticket_public_code($ticket_id));

    $intro = eventosapp_whatsapp_replace_message_vars($settings['message_intro'], $ticket_id, $event_id);

    $lines = [];
    $lines[] = $intro ?: ('Hola' . ($nombre ? ' ' . $nombre : '') . ', tu inscripción para *' . $evento_nombre . '* está confirmada.');
    $lines[] = '';
    $lines[] = '🎟️ *Detalles del evento*';
    if ( $evento_nombre ) $lines[] = '🎫 *Evento:* ' . $evento_nombre;
    if ( $organizador ) $lines[] = '👤 *Organizador:* ' . $organizador;
    if ( $nombre ) $lines[] = '🙋 *Asistente:* ' . $nombre;
    if ( $ticket_code ) $lines[] = '🔖 *Ticket:* ' . $ticket_code;
    if ( $localidad && ! $is_virtual ) $lines[] = '🏷️ *Localidad:* ' . $localidad;
    if ( $modalidad ) $lines[] = '🧭 *Modalidad:* ' . $modalidad;
    if ( $fecha ) $lines[] = '📅 *Fecha:* ' . $fecha;
    if ( $hora_inicio ) $lines[] = '⏰ *Hora:* ' . $hora_inicio . ($hora_cierre ? ' - ' . $hora_cierre : '');

    if ( $is_virtual ) {
        if ( $virtual_platform ) $lines[] = '💻 *Plataforma:* ' . $virtual_platform;
        if ( $virtual_access_url ) $lines[] = '🔗 *Acceso virtual:* ' . $virtual_access_url;
    } else {
        if ( $direccion ) $lines[] = '📍 *Lugar:* ' . $direccion;
    }

    $links = [];
    if ( $public_landing ) $links[] = '🎟️ Ver ticket: ' . $public_landing;
    if ( $google_wallet ) $links[] = '📱 Google Wallet: ' . $google_wallet;
    if ( $apple_wallet ) $links[] = '🍎 Apple Wallet: ' . $apple_wallet;
    if ( $ics_url ) $links[] = '📅 Agregar al calendario: ' . $ics_url;
    if ( $pdf_url ) $links[] = '📄 Descargar PDF: ' . $pdf_url;

    if ( ! empty($links) ) {
        $lines[] = '';
        $lines[] = '🔗 *Enlaces útiles*';
        foreach ( $links as $link ) {
            $lines[] = '• ' . $link;
        }
    }

    if ( $is_virtual ) {
        $lines[] = '';
        $lines[] = '💡 Conserva este mensaje para ingresar a la sesión virtual cuando el acceso esté habilitado.';
    } else {
        $lines[] = '';
        $lines[] = '✅ El QR de ingreso se muestra en la imagen de este mensaje.';
    }

    $message = implode("
", array_filter($lines, function($line) {
        return $line !== null;
    }));

    if ( function_exists('mb_strlen') && mb_strlen($message) > 1000 ) {
        $message = mb_substr($message, 0, 997) . '...';
    } elseif ( strlen($message) > 1000 ) {
        $message = substr($message, 0, 997) . '...';
    }

    return $message;
}

/**
 * Obtiene valor de campo para reglas.
 */
function eventosapp_whatsapp_get_rule_field_value($ticket_id, $field) {
    $map = [
        'nombre'      => '_eventosapp_asistente_nombre',
        'apellido'    => '_eventosapp_asistente_apellido',
        'cedula'      => '_eventosapp_asistente_cc',
        'email'       => '_eventosapp_asistente_email',
        'telefono'    => '_eventosapp_asistente_tel',
        'empresa'     => '_eventosapp_asistente_empresa',
        'nit'         => '_eventosapp_asistente_nit',
        'cargo'       => '_eventosapp_asistente_cargo',
        'ciudad'      => '_eventosapp_asistente_ciudad',
        'pais'        => '_eventosapp_asistente_pais',
        'localidad'   => '_eventosapp_asistente_localidad',
        'estado_pago' => '_eventosapp_estado_pago',
    ];

    if ( $field === 'modalidad' ) {
        return function_exists('eventosapp_get_ticket_modalidad') ? eventosapp_get_ticket_modalidad($ticket_id) : get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
    }

    if ( $field === 'creation_channel' ) {
        $channel = get_post_meta($ticket_id, '_eventosapp_creation_channel', true);
        if ( $channel === '' ) {
            $channel = get_post_meta($ticket_id, '_eventosapp_ticket_origin', true);
        }
        return sanitize_key($channel ?: 'manual');
    }

    if ( strpos($field, 'extra:') === 0 ) {
        $extra_key = sanitize_key(substr($field, 6));
        return get_post_meta($ticket_id, '_eventosapp_extra_' . $extra_key, true);
    }

    if ( isset($map[$field]) ) {
        return get_post_meta($ticket_id, $map[$field], true);
    }

    return '';
}

function eventosapp_whatsapp_compare_values($actual, $operator, $expected) {
    $actual = is_scalar($actual) ? (string) $actual : '';
    $expected = is_scalar($expected) ? (string) $expected : '';

    $actual_norm = function_exists('remove_accents') ? remove_accents($actual) : $actual;
    $expected_norm = function_exists('remove_accents') ? remove_accents($expected) : $expected;

    $actual_norm = function_exists('mb_strtolower') ? mb_strtolower($actual_norm) : strtolower($actual_norm);
    $expected_norm = function_exists('mb_strtolower') ? mb_strtolower($expected_norm) : strtolower($expected_norm);

    switch ( $operator ) {
        case 'not_equals':
            return $actual_norm !== $expected_norm;
        case 'contains':
            return $expected_norm === '' ? true : strpos($actual_norm, $expected_norm) !== false;
        case 'not_contains':
            return $expected_norm === '' ? false : strpos($actual_norm, $expected_norm) === false;
        case 'starts_with':
            return $expected_norm === '' ? true : strpos($actual_norm, $expected_norm) === 0;
        case 'ends_with':
            if ( $expected_norm === '' ) return true;
            return substr($actual_norm, -strlen($expected_norm)) === $expected_norm;
        case 'empty':
            return trim($actual) === '';
        case 'not_empty':
            return trim($actual) !== '';
        case 'equals':
        default:
            return $actual_norm === $expected_norm;
    }
}

function eventosapp_whatsapp_rule_matches_ticket($ticket_id, $rule) {
    $conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];

    if ( empty($conditions) ) {
        return true;
    }

    $match = isset($rule['match']) && $rule['match'] === 'any' ? 'any' : 'all';
    $results = [];

    foreach ( $conditions as $condition ) {
        $field = isset($condition['field']) ? (string) $condition['field'] : '';
        $operator = isset($condition['operator']) ? (string) $condition['operator'] : 'equals';
        $expected = isset($condition['value']) ? (string) $condition['value'] : '';
        $actual = eventosapp_whatsapp_get_rule_field_value($ticket_id, $field);
        $results[] = eventosapp_whatsapp_compare_values($actual, $operator, $expected);
    }

    return $match === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
}

/**
 * Decide si un ticket pasa filtros del evento.
 */
function eventosapp_whatsapp_ticket_passes_rules($ticket_id, $event_id) {
    $rules = eventosapp_whatsapp_normalize_rules(get_post_meta($event_id, '_eventosapp_whatsapp_rules', true));

    if ( empty($rules) ) {
        return [
            'allowed' => true,
            'reason' => 'Sin reglas: envío permitido.',
        ];
    }

    $has_allow_rules = false;
    $matched_allow = false;

    foreach ( $rules as $rule_index => $rule ) {
        if ( empty($rule['enabled']) || $rule['enabled'] !== '1' ) {
            continue;
        }

        if ( $rule['action'] === 'allow' ) {
            $has_allow_rules = true;
        }

        $matches = eventosapp_whatsapp_rule_matches_ticket($ticket_id, $rule);
        if ( ! $matches ) {
            continue;
        }

        if ( $rule['action'] === 'deny' ) {
            return [
                'allowed' => false,
                'reason' => 'Bloqueado por regla: ' . ($rule['name'] ?: ('Regla #' . ((int)$rule_index + 1))),
            ];
        }

        if ( $rule['action'] === 'allow' ) {
            $matched_allow = true;
        }
    }

    if ( $has_allow_rules && ! $matched_allow ) {
        return [
            'allowed' => false,
            'reason' => 'No cumple ninguna regla de envío permitida.',
        ];
    }

    return [
        'allowed' => true,
        'reason' => $matched_allow ? 'Cumple regla de envío.' : 'Sin regla restrictiva aplicable.',
    ];
}

/**
 * Envía el ticket por WhatsApp.
 */
function eventosapp_whatsapp_send_ticket($ticket_id, $args = []) {
    $ticket_id = absint($ticket_id);
    $args = wp_parse_args($args, [
        'context' => 'unknown',
        'force' => false,
        'skip_rules' => false,
        'source_key' => '',
    ]);

    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        return ['ok' => false, 'message' => 'Ticket inválido.'];
    }

    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
        eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', 'El ticket no tiene evento asociado.', $args);
        eventosapp_whatsapp_add_activity_log('ticket_envio_cancelado_sin_evento', [
            'ticket_id' => $ticket_id,
            'context' => $args['context'] ?? 'unknown',
        ]);
        return ['ok' => false, 'message' => 'El ticket no tiene evento asociado.'];
    }

    if ( get_post_meta($event_id, '_eventosapp_ticket_whatsapp_enabled', true) !== '1' ) {
        eventosapp_whatsapp_add_activity_log('ticket_envio_cancelado_evento_inactivo', [
            'ticket_id' => $ticket_id,
            'event_id' => $event_id,
            'context' => $args['context'] ?? 'unknown',
        ]);
        return ['ok' => false, 'message' => 'WhatsApp no está activo para este evento.'];
    }

    $source_key = sanitize_text_field((string)$args['source_key']);
    if ( ! $args['force'] && $source_key !== '' ) {
        $last_source = get_post_meta($ticket_id, '_eventosapp_whatsapp_last_source_key', true);
        if ( $last_source === $source_key ) {
            eventosapp_whatsapp_add_activity_log('ticket_envio_omitido_duplicado_source_key', [
                'ticket_id' => $ticket_id,
                'event_id' => $event_id,
                'source_key' => $source_key,
            ]);
            return ['ok' => true, 'message' => 'WhatsApp ya había sido enviado para este evento de correo.', 'skipped_duplicate' => true];
        }
    }

    $lock_key = 'eventosapp_whatsapp_send_lock_' . $ticket_id;
    if ( get_transient($lock_key) && ! $args['force'] ) {
        eventosapp_whatsapp_add_activity_log('ticket_envio_omitido_lock_temporal', [
            'ticket_id' => $ticket_id,
            'event_id' => $event_id,
            'context' => $args['context'] ?? 'unknown',
        ]);
        return ['ok' => true, 'message' => 'Envío WhatsApp omitido por bloqueo temporal anti-duplicado.', 'skipped_duplicate' => true];
    }
    set_transient($lock_key, 1, 60);

    if ( empty($args['skip_rules']) ) {
        $rules_result = eventosapp_whatsapp_ticket_passes_rules($ticket_id, $event_id);
        if ( empty($rules_result['allowed']) ) {
            delete_transient($lock_key);
            eventosapp_whatsapp_add_ticket_log($ticket_id, 'skipped', $rules_result['reason'], $args);
            eventosapp_whatsapp_add_activity_log('ticket_envio_omitido_reglas', [
                'ticket_id' => $ticket_id,
                'event_id' => $event_id,
                'reason' => $rules_result['reason'],
                'context' => $args['context'] ?? 'unknown',
            ]);
            return ['ok' => true, 'message' => $rules_result['reason'], 'skipped_rules' => true];
        }
    }

    $settings = eventosapp_whatsapp_get_settings();
    $settings = eventosapp_whatsapp_resolve_sender_settings($event_id, $settings);
    $phone_raw = get_post_meta($ticket_id, '_eventosapp_asistente_tel', true);
    $phone = eventosapp_whatsapp_normalize_phone($phone_raw, $settings['default_country_code']);

    if ( ! $phone ) {
        delete_transient($lock_key);
        eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', 'El asistente no tiene celular válido para WhatsApp.', $args, (string)$phone_raw);
        eventosapp_whatsapp_add_activity_log('ticket_envio_cancelado_celular_invalido', [
            'ticket_id' => $ticket_id,
            'event_id' => $event_id,
            'phone_raw' => $phone_raw,
            'default_country_code' => $settings['default_country_code'] ?? '',
        ]);
        return ['ok' => false, 'message' => 'El asistente no tiene celular válido para WhatsApp.'];
    }

    $assets_prepare_result = eventosapp_whatsapp_prepare_ticket_assets($ticket_id, [
        'event_id'               => $event_id,
        'context'                => sanitize_key((string)($args['context'] ?? 'send_ticket')) . '_before_send',
        'apply_variant'          => true,
        'refresh_enabled_assets' => true,
        'ensure_qr'              => true,
        'ensure_landing'         => true,
        'ensure_message_image'   => false,
        'rebuild_search_index'   => true,
        'log'                    => true,
    ]);

    $message = eventosapp_whatsapp_build_ticket_message($ticket_id);
    if ( $message === '' ) {
        delete_transient($lock_key);
        eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', 'No se pudo construir el mensaje del ticket.', $args, $phone);
        eventosapp_whatsapp_add_activity_log('ticket_envio_cancelado_mensaje_vacio', [
            'ticket_id' => $ticket_id,
            'event_id' => $event_id,
            'to' => $phone,
        ]);
        return ['ok' => false, 'message' => 'No se pudo construir el mensaje del ticket.'];
    }

    $qr_url = eventosapp_whatsapp_ensure_qr_url($ticket_id);
    $message_image_url = eventosapp_whatsapp_prepare_message_image_url($ticket_id, $qr_url);
    $payload_result = eventosapp_whatsapp_build_ticket_payload($ticket_id, $message, $message_image_url);
    $payload = $payload_result['payload'];
    $transport = sanitize_text_field((string)($payload_result['transport'] ?? 'freeform'));
    $template_name = sanitize_text_field((string)($payload_result['template_name'] ?? ''));
    $template_language = sanitize_text_field((string)($payload_result['template_language'] ?? ''));
    $payload_debug = isset($payload_result['debug']) && is_array($payload_result['debug']) ? $payload_result['debug'] : [];
    $message_length = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);

    $pre_debug = [
        'ticket_id' => $ticket_id,
        'event_id' => $event_id,
        'context' => $args['context'] ?? 'unknown',
        'force' => ! empty($args['force']),
        'skip_rules' => ! empty($args['skip_rules']),
        'to' => $phone,
        'phone_raw' => $phone_raw,
        'sender_phone_number_id' => sanitize_text_field((string)($settings['sender_phone_number_id'] ?? ($settings['phone_number_id'] ?? ''))),
        'sender_phone_label' => sanitize_text_field((string)($settings['sender_phone_label'] ?? ($settings['phone_number_label'] ?? ''))),
        'qr_url_present' => $qr_url !== '',
        'message_image_url_present' => $message_image_url !== '',
        'message_image_mode' => (function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id)) ? 'virtual_image' : 'qr_with_header',
        'message_chars' => $message_length,
        'transport' => $transport,
        'template_name' => $template_name,
        'template_language' => $template_language,
        'payload_builder' => $payload_debug,
        'assets_prepare' => isset($assets_prepare_result) ? $assets_prepare_result : null,
    ];

    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_debug', eventosapp_whatsapp_sanitize_log_context($pre_debug));
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_transport', $transport);
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_template_name', $template_name);
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_template_language', $template_language);
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_sender_phone_number_id', sanitize_text_field((string)($settings['sender_phone_number_id'] ?? ($settings['phone_number_id'] ?? ''))));
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_sender_label', sanitize_text_field((string)($settings['sender_phone_label'] ?? ($settings['phone_number_label'] ?? ''))));
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_payload_summary', eventosapp_whatsapp_summarize_payload(array_merge([
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $phone,
    ], $payload)));

    eventosapp_whatsapp_add_activity_log('ticket_envio_preparado', $pre_debug);
    eventosapp_whatsapp_add_ticket_log($ticket_id, 'preparado', 'Solicitud preparada para Meta.', $args, $phone, [
        'http_code' => 0,
        'debug' => $pre_debug,
        'transport' => $transport,
        'template_name' => $template_name,
    ]);

    $result = eventosapp_whatsapp_api_send_message($phone, $payload, $settings);

    // No se elimina el transient aquí: lo dejamos expirar para evitar duplicados
    // cuando el flujo de correo actualiza varios metadatos en la misma ejecución.
    $result_debug = isset($result['debug']) && is_array($result['debug']) ? $result['debug'] : [];
    $final_debug = array_merge($pre_debug, [
        'api_result' => $result_debug,
        'http_code' => isset($result['http_code']) ? (int)$result['http_code'] : 0,
        'message_id' => isset($result['message_id']) ? sanitize_text_field((string)$result['message_id']) : eventosapp_whatsapp_extract_message_id($result['response'] ?? []),
        'response_summary' => $result['response_summary'] ?? eventosapp_whatsapp_summarize_response($result['response'] ?? []),
    ]);

    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_debug', eventosapp_whatsapp_sanitize_log_context($final_debug));
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_http_code', isset($result['http_code']) ? (int)$result['http_code'] : 0);

    if ( ! empty($result['ok']) ) {
        $whatsapp_sent_at = current_time('mysql');
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_status', 'aceptado_meta');
        update_post_meta($ticket_id, '_eventosapp_whatsapp_delivery_status', 'pendiente_webhook');
        delete_post_meta($ticket_id, '_eventosapp_whatsapp_delivery_at');
        eventosapp_whatsapp_register_successful_send_tracking($ticket_id, $phone, $args, $whatsapp_sent_at);
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_error', '');
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_response', $result['response'] ?? []);
        $message_id = isset($result['message_id']) ? sanitize_text_field((string)$result['message_id']) : eventosapp_whatsapp_extract_message_id($result['response'] ?? []);
        if ( $message_id !== '' ) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_message_id', $message_id);
            eventosapp_whatsapp_register_message_map($message_id, $ticket_id, $args['context'] ?? 'unknown', $phone);
        }
        if ( $source_key !== '' ) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_source_key', $source_key);
        }
        eventosapp_whatsapp_add_activity_log('ticket_envio_aceptado_por_meta', $final_debug);
        eventosapp_whatsapp_add_ticket_log($ticket_id, 'aceptado_meta', $result['message'] ?? 'Solicitud aceptada por Meta. Esperando webhook de entrega.', $args, $phone, array_merge($result, [
            'debug' => $final_debug,
            'transport' => $transport,
            'template_name' => $template_name,
            'delivery_status' => 'pendiente_webhook',
        ]));
    } else {
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_status', 'error');
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_error', $result['message'] ?? 'Error desconocido.');
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_response', $result['response'] ?? []);
        eventosapp_whatsapp_add_activity_log('ticket_envio_error_meta_o_local', $final_debug);
        eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', $result['message'] ?? 'Error desconocido.', $args, $phone, array_merge($result, [
            'debug' => $final_debug,
            'transport' => $transport,
            'template_name' => $template_name,
        ]));
    }

    return $result;
}

function eventosapp_whatsapp_add_ticket_log($ticket_id, $status, $message, $args = [], $phone = '', $result = []) {
    $history = get_post_meta($ticket_id, '_eventosapp_whatsapp_history', true);
    if ( ! is_array($history) ) {
        $history = [];
    }

    $response = $result['response'] ?? [];
    $debug = isset($result['debug']) && is_array($result['debug']) ? $result['debug'] : [];

    $entry = [
        'date' => current_time('mysql'),
        'status' => sanitize_text_field($status),
        'message' => sanitize_text_field((string)$message),
        'context' => sanitize_text_field((string)($args['context'] ?? 'unknown')),
        'source_key' => sanitize_text_field((string)($args['source_key'] ?? '')),
        'to' => sanitize_text_field($phone),
        'sender_phone_number_id' => sanitize_text_field((string)($debug['sender_phone_number_id'] ?? ($debug['api_result']['sender_phone_number_id'] ?? ''))),
        'sender_phone_label' => sanitize_text_field((string)($debug['sender_phone_label'] ?? ($debug['api_result']['sender_phone_label'] ?? ''))),
        'http_code' => isset($result['http_code']) ? (int)$result['http_code'] : 0,
        'message_id' => isset($result['message_id']) ? sanitize_text_field((string)$result['message_id']) : eventosapp_whatsapp_extract_message_id($response),
        'transport' => isset($result['transport']) ? sanitize_text_field((string)$result['transport']) : '',
        'template_name' => isset($result['template_name']) ? sanitize_text_field((string)$result['template_name']) : '',
        'delivery_status' => isset($result['delivery_status']) ? sanitize_text_field((string)$result['delivery_status']) : '',
        'response_summary' => isset($result['response_summary']) ? eventosapp_whatsapp_sanitize_log_context($result['response_summary']) : eventosapp_whatsapp_summarize_response($response),
        'debug' => eventosapp_whatsapp_sanitize_log_context($debug),
    ];

    if ( is_array($response) && ! empty($response['error']) ) {
        $entry['meta_error'] = eventosapp_whatsapp_sanitize_log_context($response['error']);
    }

    $history[] = $entry;

    if ( count($history) > 80 ) {
        $history = array_slice($history, -80);
    }

    update_post_meta($ticket_id, '_eventosapp_whatsapp_history', $history);
}

/**
 * Webhook público para verificación y estados de entrega de WhatsApp.
 * URL en Meta: /wp-admin/admin-post.php?action=eventosapp_whatsapp_webhook
 */
add_action('admin_post_nopriv_eventosapp_whatsapp_webhook', 'eventosapp_whatsapp_handle_webhook_request');
add_action('admin_post_eventosapp_whatsapp_webhook', 'eventosapp_whatsapp_handle_webhook_request');

function eventosapp_whatsapp_handle_webhook_request() {
    $settings = eventosapp_whatsapp_get_settings();

    if ( isset($_GET['hub_mode']) || isset($_GET['hub.mode']) ) {
        $mode = isset($_GET['hub_mode']) ? sanitize_text_field(wp_unslash($_GET['hub_mode'])) : sanitize_text_field(wp_unslash($_GET['hub.mode']));
        $token = isset($_GET['hub_verify_token']) ? sanitize_text_field(wp_unslash($_GET['hub_verify_token'])) : (isset($_GET['hub.verify_token']) ? sanitize_text_field(wp_unslash($_GET['hub.verify_token'])) : '');
        $challenge = isset($_GET['hub_challenge']) ? sanitize_text_field(wp_unslash($_GET['hub_challenge'])) : (isset($_GET['hub.challenge']) ? sanitize_text_field(wp_unslash($_GET['hub.challenge'])) : '');

        if ( $mode === 'subscribe' && $challenge !== '' && ! empty($settings['webhook_verify_token']) && hash_equals((string)$settings['webhook_verify_token'], (string)$token) ) {
            status_header(200);
            header('Content-Type: text/plain; charset=utf-8');
            echo $challenge;
            exit;
        }

        eventosapp_whatsapp_log('Webhook verificación rechazada', [
            'mode' => $mode,
            'token_present' => $token !== '',
            'challenge_present' => $challenge !== '',
        ]);
        status_header(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);

    if ( ! is_array($payload) ) {
        eventosapp_whatsapp_log('Webhook recibido con JSON inválido', [
            'raw' => substr((string)$raw, 0, 500),
        ]);
        status_header(400);
        wp_send_json(['ok' => false, 'message' => 'JSON inválido']);
    }

    eventosapp_whatsapp_process_webhook_payload($payload);

    status_header(200);
    wp_send_json(['ok' => true]);
}

function eventosapp_whatsapp_process_webhook_payload($payload) {
    $entries = isset($payload['entry']) && is_array($payload['entry']) ? $payload['entry'] : [];

    eventosapp_whatsapp_add_activity_log('webhook_payload_recibido', [
        'entries' => count($entries),
        'object' => $payload['object'] ?? '',
    ]);

    foreach ( $entries as $entry ) {
        $changes = isset($entry['changes']) && is_array($entry['changes']) ? $entry['changes'] : [];

        foreach ( $changes as $change ) {
            $value = isset($change['value']) && is_array($change['value']) ? $change['value'] : [];

            if ( ! empty($value['statuses']) && is_array($value['statuses']) ) {
                foreach ( $value['statuses'] as $status ) {
                    if ( is_array($status) ) {
                        eventosapp_whatsapp_process_webhook_status($status);
                    }
                }
            }

            if ( ! empty($value['messages']) && is_array($value['messages']) ) {
                foreach ( $value['messages'] as $message ) {
                    if ( is_array($message) ) {
                        eventosapp_whatsapp_process_webhook_inbound_message($message);
                    }
                }
            }
        }
    }
}

function eventosapp_whatsapp_process_webhook_status($status) {
    $message_id = ! empty($status['id']) ? sanitize_text_field((string)$status['id']) : '';
    $delivery_status = ! empty($status['status']) ? sanitize_text_field((string)$status['status']) : '';
    $recipient_id = ! empty($status['recipient_id']) ? sanitize_text_field((string)$status['recipient_id']) : '';
    $timestamp = ! empty($status['timestamp']) ? absint($status['timestamp']) : 0;
    $delivery_at = $timestamp ? date_i18n('Y-m-d H:i:s', $timestamp) : current_time('mysql');

    if ( $message_id === '' || $delivery_status === '' ) {
        eventosapp_whatsapp_add_activity_log('webhook_estado_incompleto', [
            'status_payload' => $status,
        ]);
        return;
    }

    $error_message = '';
    $error_detail = [];
    if ( ! empty($status['errors'][0]) && is_array($status['errors'][0]) ) {
        $error = $status['errors'][0];
        $error_detail = eventosapp_whatsapp_sanitize_log_context($error);
        $error_message = sanitize_text_field(trim(($error['code'] ?? '') . ' ' . ($error['title'] ?? '') . ' ' . ($error['message'] ?? '')));
    }

    $map = eventosapp_whatsapp_get_message_map();
    $mapped = isset($map[$message_id]) && is_array($map[$message_id]) ? $map[$message_id] : [];
    $ticket_id = ! empty($mapped['ticket_id']) ? absint($mapped['ticket_id']) : 0;

    $webhook_debug = [
        'message_id' => $message_id,
        'status' => $delivery_status,
        'recipient_id' => $recipient_id,
        'ticket_id' => $ticket_id,
        'mapped_context' => $mapped['context'] ?? '',
        'mapped_phone' => $mapped['phone'] ?? '',
        'delivery_at' => $delivery_at,
        'conversation' => $status['conversation'] ?? [],
        'pricing' => $status['pricing'] ?? [],
        'errors' => $error_detail,
        'raw_status' => $status,
    ];

    eventosapp_whatsapp_add_activity_log('webhook_estado_whatsapp', $webhook_debug);

    if ( $ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket' ) {
        update_post_meta($ticket_id, '_eventosapp_whatsapp_delivery_status', $delivery_status);
        update_post_meta($ticket_id, '_eventosapp_whatsapp_delivery_at', $delivery_at);
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_webhook_status_raw', eventosapp_whatsapp_sanitize_log_context($webhook_debug));

        $local_status_map = [
            'sent' => 'enviado_webhook',
            'delivered' => 'entregado',
            'read' => 'leido',
            'failed' => 'fallido_webhook',
        ];
        if ( isset($local_status_map[$delivery_status]) ) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_status', $local_status_map[$delivery_status]);
        }

        if ( $delivery_status === 'failed' && $error_message !== '' ) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_error', $error_message);
        } elseif ( in_array($delivery_status, ['sent', 'delivered', 'read'], true) ) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_error', '');
        }

        eventosapp_whatsapp_add_ticket_log($ticket_id, 'webhook_' . $delivery_status, $error_message ?: 'Estado recibido por webhook: ' . $delivery_status, [
            'context' => 'webhook_status',
            'source_key' => $message_id,
        ], $recipient_id, [
            'http_code' => 0,
            'message_id' => $message_id,
            'delivery_status' => $delivery_status,
            'debug' => $webhook_debug,
        ]);
    } elseif ( ! empty($mapped['context']) && $mapped['context'] === 'quick_test' ) {
        eventosapp_whatsapp_update_last_test_delivery_status($message_id, $delivery_status, $error_message);
    }
}

function eventosapp_whatsapp_update_last_test_delivery_status($message_id, $delivery_status, $error_message = '') {
    $settings = eventosapp_whatsapp_get_settings();
    if ( empty($settings['last_test_result']) || ! is_array($settings['last_test_result']) ) {
        return;
    }
    if ( empty($settings['last_test_result']['message_id']) || $settings['last_test_result']['message_id'] !== $message_id ) {
        return;
    }

    $settings['last_test_result']['delivery_status'] = $delivery_status;
    if ( $error_message !== '' ) {
        $settings['last_test_result']['message'] = $error_message;
    }
    update_option(EVENTOSAPP_WHATSAPP_OPTION, $settings, false);
}

function eventosapp_whatsapp_process_webhook_inbound_message($message) {
    $from = ! empty($message['from']) ? sanitize_text_field((string)$message['from']) : '';
    if ( $from === '' ) {
        return;
    }

    $inbound = get_option('eventosapp_whatsapp_last_inbound_by_phone', []);
    if ( ! is_array($inbound) ) {
        $inbound = [];
    }

    $inbound[$from] = [
        'last_at' => current_time('mysql'),
        'message_id' => ! empty($message['id']) ? sanitize_text_field((string)$message['id']) : '',
        'type' => ! empty($message['type']) ? sanitize_text_field((string)$message['type']) : '',
    ];

    if ( count($inbound) > 500 ) {
        $inbound = array_slice($inbound, -500, null, true);
    }

    update_option('eventosapp_whatsapp_last_inbound_by_phone', $inbound, false);

    eventosapp_whatsapp_add_activity_log('webhook_mensaje_entrante_whatsapp', [
        'from' => $from,
        'message_id' => $inbound[$from]['message_id'],
        'type' => $inbound[$from]['type'],
    ]);
}

/**
 * Controla si un envío de correo puede disparar WhatsApp automáticamente.
 *
 * IMPORTANTE:
 * - El botón manual de correo NO debe disparar WhatsApp.
 * - El botón manual de WhatsApp conserva su flujo propio en admin_post_eventosapp_send_ticket_whatsapp.
 * - Solo los orígenes automáticos conocidos pueden activar el puente correo -> WhatsApp.
 */
function eventosapp_whatsapp_is_manual_email_admin_request($ticket_id = 0) {
    if ( empty($_REQUEST['action']) ) {
        return false;
    }

    $action = sanitize_key(wp_unslash($_REQUEST['action']));
    if ( $action !== 'eventosapp_send_ticket_email' ) {
        return false;
    }

    $ticket_id = absint($ticket_id);
    if ( ! $ticket_id ) {
        return true;
    }

    foreach ( ['post_id', 'ticket_id', 'post'] as $request_key ) {
        if ( isset($_REQUEST[$request_key]) && absint($_REQUEST[$request_key]) === $ticket_id ) {
            return true;
        }
    }

    return false;
}

function eventosapp_whatsapp_normalize_email_source($source) {
    $source = is_scalar($source) ? (string) $source : '';
    $source = strtolower(trim($source));
    return sanitize_key($source);
}

function eventosapp_whatsapp_email_source_allows_auto_send($source, $ticket_id = 0, $entry = []) {
    $source = eventosapp_whatsapp_normalize_email_source($source);

    if ( eventosapp_whatsapp_is_manual_email_admin_request($ticket_id) ) {
        return false;
    }

    $manual_sources = [
        '',
        'manual',
        'admin',
        'metabox',
        'adminpost',
        'manual_admin',
        'manual_adminpost',
        'email_manual',
        'ticket_email_manual',
    ];

    if ( in_array($source, $manual_sources, true) ) {
        return false;
    }

    $automatic_sources = [
        'auto',
        'automatico',
        'automatic',
        'webhook',
        'frontend',
        'public',
        'import',
        'importacion',
        'bulk',
        'masivo',
        'reminder',
        'recordatorio',
        'cron',
        'scheduled',
        'api',
        'automation',
    ];

    $allowed = in_array($source, $automatic_sources, true);

    /**
     * Permite extender desde otro archivo qué orígenes de correo pueden disparar WhatsApp.
     * Por defecto se bloquean manual/admin y solo se aceptan orígenes automáticos conocidos.
     */
    return (bool) apply_filters('eventosapp_whatsapp_email_source_allows_auto_send', $allowed, $source, $ticket_id, $entry);
}

function eventosapp_whatsapp_get_latest_email_history_entry($history) {
    if ( ! is_array($history) || empty($history) ) {
        return [];
    }

    // El módulo de correo inserta el nuevo envío al inicio con array_unshift().
    if ( isset($history[0]) && is_array($history[0]) ) {
        return $history[0];
    }

    $last = end($history);
    return is_array($last) ? $last : [];
}

/**
 * Disparo cuando el correo queda registrado como enviado.
 *
 * Se usa únicamente como puente para envíos automáticos. El envío manual por correo queda aislado
 * para que no active WhatsApp por metadatos, hooks ni shutdown.
 */
function eventosapp_whatsapp_trigger_from_email_meta($meta_id, $object_id, $meta_key, $_meta_value) {
    $ticket_id = absint($object_id);
    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        return;
    }

    // Este meta se actualiza antes del historial y no trae el origen del envío.
    // Si se usa para disparar WhatsApp, el botón manual de correo queda mezclado con WhatsApp.
    if ( $meta_key === '_eventosapp_ticket_email_sent_status' ) {
        eventosapp_whatsapp_add_activity_log('ticket_envio_whatsapp_no_disparado_por_status_email', [
            'ticket_id' => $ticket_id,
            'reason'    => 'El estado de correo no identifica si el origen fue manual o automático.',
        ]);
        return;
    }

    if ( $meta_key !== '_eventosapp_ticket_email_history' ) {
        return;
    }

    $history = get_post_meta($ticket_id, '_eventosapp_ticket_email_history', true);
    $count = is_array($history) ? count($history) : 0;
    $last = eventosapp_whatsapp_get_latest_email_history_entry($history);
    $source = eventosapp_whatsapp_normalize_email_source($last['source'] ?? '');

    if ( ! eventosapp_whatsapp_email_source_allows_auto_send($source, $ticket_id, $last) ) {
        eventosapp_whatsapp_add_activity_log('ticket_envio_whatsapp_omitido_por_correo_manual', [
            'ticket_id' => $ticket_id,
            'email_source' => $source,
            'meta_key' => $meta_key,
            'reason' => 'El correo fue manual/admin o no tiene un origen automático permitido.',
        ]);
        return;
    }

    $source_key = 'email_history:' . $source . ':' . $count . ':' . md5(wp_json_encode($last));
    eventosapp_whatsapp_send_ticket($ticket_id, [
        'context' => 'email_' . $source,
        'source_key' => $source_key,
    ]);
}
add_action('added_post_meta', 'eventosapp_whatsapp_trigger_from_email_meta', 20, 4);
add_action('updated_post_meta', 'eventosapp_whatsapp_trigger_from_email_meta', 20, 4);

/**
 * Compatibilidad con posibles hooks explícitos del módulo de correo.
 * También respeta la separación de botones manuales: correo manual no dispara WhatsApp.
 */
add_action('eventosapp_after_ticket_email_sent', function($ticket_id, $recipient = '', $args = []) {
    $ticket_id = absint($ticket_id);
    $args = is_array($args) ? $args : [];
    $source = eventosapp_whatsapp_normalize_email_source($args['source'] ?? '');

    if ( ! eventosapp_whatsapp_email_source_allows_auto_send($source, $ticket_id, $args) ) {
        eventosapp_whatsapp_add_activity_log('ticket_envio_whatsapp_omitido_por_hook_correo_manual', [
            'ticket_id' => $ticket_id,
            'email_source' => $source,
            'reason' => 'Hook de correo omitido porque no corresponde a un origen automático permitido.',
        ]);
        return;
    }

    eventosapp_whatsapp_send_ticket($ticket_id, [
        'context' => 'email_hook_' . $source,
        'source_key' => 'email_hook:' . $source . ':' . $ticket_id . ':' . time(),
    ]);
}, 20, 3);

/**
 * Disparos explícitos desde flujos que crean o actualizan tickets sin depender únicamente del correo.
 * El anti-duplicado interno evita doble envío cuando el correo también dispara WhatsApp en la misma solicitud.
 */
add_action('eventosapp_ticket_created_via_webhook', function($ticket_id, $payload = []) {
    eventosapp_whatsapp_maybe_send_after_ticket_creation(absint($ticket_id), 'webhook_create', [
        'refresh_enabled_assets' => false,
        'source_key' => 'webhook_create:' . absint($ticket_id) . ':' . md5(wp_json_encode($payload)),
    ]);
}, 30, 2);

add_action('eventosapp_ticket_updated_via_webhook', function($ticket_id, $payload = []) {
    eventosapp_whatsapp_maybe_send_after_ticket_creation(absint($ticket_id), 'webhook_update', [
        'refresh_enabled_assets' => false,
        'source_key' => 'webhook_update:' . absint($ticket_id) . ':' . md5(wp_json_encode($payload) . ':' . (string) get_post_modified_time('U', true, absint($ticket_id))),
    ]);
}, 30, 2);

add_action('eventosapp_frontend_ticket_created', function($ticket_id, $event_id = 0, $context = 'frontend') {
    eventosapp_whatsapp_maybe_send_after_ticket_creation(absint($ticket_id), sanitize_key((string)$context) ?: 'frontend', [
        'event_id'   => absint($event_id),
        'source_key' => sanitize_key((string)$context) . ':' . absint($ticket_id) . ':' . md5((string) get_post_modified_time('U', true, absint($ticket_id))),
    ]);
}, 30, 3);

/**
 * El botón admin-post de correo queda aislado de WhatsApp.
 *
 * Antes existía un puente por shutdown para action=eventosapp_send_ticket_email que validaba
 * el estado final del correo y disparaba WhatsApp. Ese puente causaba que el botón manual
 * de correo ejecutara también el envío de WhatsApp, por eso se eliminó intencionalmente.
 */
