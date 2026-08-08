<?php
/**
 * Sistema de Doble Autenticación para Check-In
 * Genera, asigna, envía y valida códigos de 5 dígitos para tickets
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ========================================
// GENERACIÓN DE CÓDIGOS
// ========================================

/**
 * Genera un código aleatorio de 5 dígitos
 * 
 * @return string Código de 5 dígitos (ej: "73492")
 */
function eventosapp_generate_auth_code() {
    return str_pad( random_int(10000, 99999), 5, '0', STR_PAD_LEFT );
}

/**
 * Asigna un nuevo código de doble autenticación a un ticket
 * Invalida automáticamente el código anterior
 * 
 * @param int $ticket_id ID del ticket
 * @return string Código generado
 */
function eventosapp_assign_auth_code_to_ticket( $ticket_id ) {
    $code = eventosapp_generate_auth_code();
    $now  = current_time('timestamp');
    
    // Guardar código actual
    update_post_meta( $ticket_id, '_eventosapp_double_auth_code', $code );
    update_post_meta( $ticket_id, '_eventosapp_double_auth_code_date', $now );
    
    return $code;
}

/**
 * Obtiene el código actual de un ticket
 * 
 * @param int $ticket_id ID del ticket
 * @return string|false Código o false si no existe
 */
function eventosapp_get_ticket_auth_code( $ticket_id ) {
    $code = get_post_meta( $ticket_id, '_eventosapp_double_auth_code', true );
    return $code ? $code : false;
}

/**
 * Valida si un código ingresado coincide con el asignado al ticket.
 *
 * - Para eventos de día único o modo "first_day": valida contra el código general.
 * - Para eventos multi-día en modo "all_days": intenta validar contra el código del día actual
 *   (según la zona horaria del evento). Si no existe, cae al código general.
 * 
 * @param int    $ticket_id  ID del ticket
 * @param string $input_code Código ingresado por el usuario
 * @return bool True si coincide, false si no
 */
function eventosapp_validate_auth_code( $ticket_id, $input_code ) {
    $ticket_id  = absint( $ticket_id );
    $input_code = trim( (string) $input_code );

    if ( ! $ticket_id || ! preg_match( '/^\d{5}$/', $input_code ) ) {
        return false;
    }

    $stored_code = eventosapp_get_ticket_auth_code( $ticket_id );
    $event_id    = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );

    if ( $event_id ) {
        $auth_mode  = get_post_meta( $event_id, '_eventosapp_ticket_double_auth_mode', true );
        $tipo_fecha = get_post_meta( $event_id, '_eventosapp_tipo_fecha', true ) ?: 'unica';

        if ( $auth_mode === 'all_days' && $tipo_fecha !== 'unica' ) {
            $today = function_exists( 'eventosapp_double_auth_current_event_day' )
                ? eventosapp_double_auth_current_event_day( $event_id )
                : '';

            if ( $today && function_exists( 'eventosapp_get_ticket_auth_code_for_day' ) ) {
                $day_code = eventosapp_get_ticket_auth_code_for_day( $ticket_id, $today );
                if ( $day_code ) $stored_code = $day_code;
            }
        }
    }

    $stored_code = trim( (string) $stored_code );
    return preg_match( '/^\d{5}$/', $stored_code )
        ? hash_equals( $stored_code, $input_code )
        : false;
}




// ========================================
// HELPERS DE CONTEXTO, CANALES Y RENDIMIENTO
// ========================================

if ( ! function_exists( 'eventosapp_double_auth_event_timezone' ) ) {
    function eventosapp_double_auth_event_timezone( $event_id ) {
        $event_id = absint( $event_id );
        $name = $event_id ? sanitize_text_field( (string) get_post_meta( $event_id, '_eventosapp_zona_horaria', true ) ) : '';
        if ( $name === '' ) $name = wp_timezone_string();

        try {
            return new DateTimeZone( $name ?: 'UTC' );
        } catch ( Throwable $e ) {
            return wp_timezone();
        }
    }
}

if ( ! function_exists( 'eventosapp_double_auth_current_event_day' ) ) {
    function eventosapp_double_auth_current_event_day( $event_id ) {
        try {
            return ( new DateTimeImmutable( 'now', eventosapp_double_auth_event_timezone( $event_id ) ) )->format( 'Y-m-d' );
        } catch ( Throwable $e ) {
            return current_time( 'Y-m-d' );
        }
    }
}

if ( ! function_exists( 'eventosapp_double_auth_event_days' ) ) {
    function eventosapp_double_auth_event_days( $event_id ) {
        $days = function_exists( 'eventosapp_get_event_days' ) ? (array) eventosapp_get_event_days( absint( $event_id ) ) : [];
        $days = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $days ), static function( $day ) {
            return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day );
        } ) ) );
        sort( $days );
        return $days;
    }
}

if ( ! function_exists( 'eventosapp_double_auth_requires_code_for_day' ) ) {
    function eventosapp_double_auth_requires_code_for_day( $event_id, $day = '' ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) return true;

        $mode = get_post_meta( $event_id, '_eventosapp_ticket_double_auth_mode', true ) ?: 'first_day';
        if ( $mode === 'all_days' ) return true;

        $days = eventosapp_double_auth_event_days( $event_id );
        if ( count( $days ) <= 1 ) return true;

        $day = $day && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day )
            ? (string) $day
            : eventosapp_double_auth_current_event_day( $event_id );

        // El texto histórico del metabox define first_day así: el código solo se
        // exige el primer día; los días siguientes continúan sin segundo factor.
        return $day === (string) reset( $days );
    }
}

if ( ! function_exists( 'eventosapp_double_auth_target_day' ) ) {
    function eventosapp_double_auth_target_day( $event_id ) {
        $event_id = absint( $event_id );
        $mode = get_post_meta( $event_id, '_eventosapp_ticket_double_auth_mode', true ) ?: 'first_day';
        if ( $mode !== 'all_days' ) return '';

        $days = eventosapp_double_auth_event_days( $event_id );
        if ( empty( $days ) ) return '';

        $today = eventosapp_double_auth_current_event_day( $event_id );
        return in_array( $today, $days, true ) ? $today : (string) reset( $days );
    }
}

if ( ! function_exists( 'eventosapp_double_auth_ticket_ids' ) ) {
    function eventosapp_double_auth_ticket_ids( $event_id ) {
        global $wpdb;

        $event_id = absint( $event_id );
        if ( ! $event_id ) return [];

        // Consulta liviana: la cola precarga el meta únicamente del lote que va
        // a ejecutar, evitando llenar memoria con todos los tickets del evento.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} event_pm
                       ON event_pm.post_id = p.ID
                      AND event_pm.meta_key = %s
                      AND event_pm.meta_value = %s
              WHERE p.post_type = 'eventosapp_ticket'
                AND p.post_status = 'publish'
              ORDER BY p.ID ASC",
            '_eventosapp_ticket_evento_id',
            (string) $event_id
        ) );

        return array_values( array_filter( array_unique( array_map( 'absint', (array) $ids ) ) ) );
    }
}

if ( ! function_exists( 'eventosapp_double_auth_event_channels' ) ) {
    function eventosapp_double_auth_event_channels( $event_id ) {
        $event_id = absint( $event_id );
        $email_meta = get_post_meta( $event_id, '_eventosapp_double_auth_send_email', true );
        $email = $email_meta === '' ? true : $email_meta === '1';
        $whatsapp = get_post_meta( $event_id, '_eventosapp_double_auth_send_whatsapp', true ) === '1';

        // Mantener correo como fallback histórico si ambos flags llegan apagados.
        if ( ! $email && ! $whatsapp ) $email = true;

        return [ 'email' => $email, 'whatsapp' => $whatsapp ];
    }
}

if ( ! function_exists( 'eventosapp_double_auth_queue_channel' ) ) {
    function eventosapp_double_auth_queue_channel( $event_id ) {
        $channels = eventosapp_double_auth_event_channels( $event_id );
        if ( $channels['email'] && $channels['whatsapp'] ) return 'email+whatsapp';
        if ( $channels['whatsapp'] ) return 'whatsapp';
        return 'email';
    }
}

if ( ! function_exists( 'eventosapp_double_auth_whatsapp_template_id' ) ) {
    function eventosapp_double_auth_whatsapp_template_id( $event_id ) {
        return sanitize_key( (string) get_post_meta( absint( $event_id ), '_eventosapp_double_auth_whatsapp_template_id', true ) );
    }
}

if ( ! function_exists( 'eventosapp_double_auth_validity_label' ) ) {
    function eventosapp_double_auth_validity_label( $event_id, $specific_date = '' ) {
        $specific_date = sanitize_text_field( (string) $specific_date );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $specific_date ) ) {
            return date_i18n( 'd/m/Y', strtotime( $specific_date ) );
        }

        $days = eventosapp_double_auth_event_days( $event_id );
        return $days ? date_i18n( 'd/m/Y', strtotime( (string) reset( $days ) ) ) : 'Fecha del evento';
    }
}

if ( ! function_exists( 'eventosapp_double_auth_prepare_ticket_code_for_dispatch' ) ) {
    function eventosapp_double_auth_prepare_ticket_code_for_dispatch( $ticket_id, $specific_date = '', $regenerate = true ) {
        $ticket_id = absint( $ticket_id );
        $specific_date = sanitize_text_field( (string) $specific_date );
        if ( ! $ticket_id ) return '';

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $specific_date ) ) {
            $code = $regenerate
                ? eventosapp_assign_auth_code_to_ticket_for_day( $ticket_id, $specific_date )
                : eventosapp_get_ticket_auth_code_for_day( $ticket_id, $specific_date );
            if ( ! $code ) $code = eventosapp_assign_auth_code_to_ticket_for_day( $ticket_id, $specific_date );

            // Conserva compatibilidad con pantallas históricas que consultan el
            // código general, sin usar este metadato como estado temporal de envío.
            update_post_meta( $ticket_id, '_eventosapp_double_auth_current_day', $specific_date );
            update_post_meta( $ticket_id, '_eventosapp_double_auth_code', $code );
            update_post_meta( $ticket_id, '_eventosapp_double_auth_code_date', current_time( 'timestamp' ) );
            return (string) $code;
        }

        $code = $regenerate ? eventosapp_assign_auth_code_to_ticket( $ticket_id ) : eventosapp_get_ticket_auth_code( $ticket_id );
        if ( ! $code ) $code = eventosapp_assign_auth_code_to_ticket( $ticket_id );
        return (string) $code;
    }
}


// ========================================
// ENVÍO DE CÓDIGOS POR EMAIL
// ========================================

/**
 * Envía el código de doble autenticación al asistente por email
 * Usa la plantilla email-ticket-auth.html y la configuración del metabox del evento
 * 
 * @param int $ticket_id ID del ticket
 * @param string $method Método de envío: 'manual', 'masivo', 'automatico'
 * @return bool True si se envió correctamente, false si falló
 */
function eventosapp_send_auth_code_email( $ticket_id, $method = 'manual' ) {
    $ticket_id = absint( $ticket_id );
    $event_id  = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    $specific_date = '';

    if ( $event_id && get_post_meta( $event_id, '_eventosapp_ticket_double_auth_mode', true ) === 'all_days' ) {
        $candidate = sanitize_text_field( (string) get_post_meta( $ticket_id, '_eventosapp_double_auth_current_day', true ) );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $candidate ) ) $specific_date = $candidate;
    }

    $code = $specific_date
        ? eventosapp_get_ticket_auth_code_for_day( $ticket_id, $specific_date )
        : eventosapp_get_ticket_auth_code( $ticket_id );

    if ( ! $code ) {
        $code = eventosapp_double_auth_prepare_ticket_code_for_dispatch( $ticket_id, $specific_date, true );
    }

    return eventosapp_send_auth_code_email_with_context( $ticket_id, $method, $code, $specific_date );
}


/**
 * Envía correo usando un código y fecha explícitos. Evita intercambiar
 * metadatos temporales durante el trabajo concurrente de la cola.
 */
function eventosapp_send_auth_code_email_with_context( $ticket_id, $method = 'manual', $code = '', $specific_date = '' ) {
    $ticket_id = absint( $ticket_id );
    if ( get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) return false;

    $email    = sanitize_email( get_post_meta( $ticket_id, '_eventosapp_asistente_email', true ) );
    $nombre   = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
    $apellido = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );
    $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    $code     = trim( (string) $code );

    if ( ! $email || ! $event_id || ! preg_match( '/^\d{5}$/', $code ) ) return false;

    $event_title = get_the_title( $event_id );
    $ticket_code = get_post_meta( $ticket_id, 'eventosapp_ticketID', true );
    $nombre_completo = trim( $nombre . ' ' . $apellido );

    $header_img = get_post_meta( $event_id, '_eventosapp_email_header_img', true );
    if ( ! $header_img ) $header_img = 'https://eventosapp.com/wp-content/uploads/2025/08/header_ticket_gen.jpg';

    $from_name = get_post_meta( $event_id, '_eventosapp_email_fromname', true ) ?: get_bloginfo( 'name' );
    $template_path = dirname( __FILE__, 2 ) . '/templates/email_tickets/email-ticket-auth.html';

    if ( ! file_exists( $template_path ) ) {
        return eventosapp_send_auth_code_email_plain( $ticket_id, $method, $code, $from_name, $specific_date );
    }

    $html = file_get_contents( $template_path );
    if ( $html === false ) {
        return eventosapp_send_auth_code_email_plain( $ticket_id, $method, $code, $from_name, $specific_date );
    }

    $organizador = get_post_meta( $event_id, '_eventosapp_organizador', true ) ?: '';
    if ( function_exists( 'eventosapp_get_nombre_organizador' ) ) {
        $organizador = eventosapp_get_nombre_organizador( $event_id ) ?: $organizador;
    }

    $lugar_evento = get_post_meta( $event_id, '_eventosapp_direccion', true ) ?: '';
    $tipo_fecha   = get_post_meta( $event_id, '_eventosapp_tipo_fecha', true ) ?: 'unica';
    $fecha_evento = '';
    $fecha_especifica_text = '';

    if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $specific_date ) ) {
        $fecha_evento = date_i18n( 'F d, Y', strtotime( $specific_date ) );
        $fecha_especifica_text = sprintf(
            '<p style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px;margin:16px 0;color:#856404;font-size:14px;"><strong>📅 Este código es válido solo para:</strong> %s</p>',
            esc_html( $fecha_evento )
        );
    } elseif ( $tipo_fecha === 'unica' ) {
        $raw = get_post_meta( $event_id, '_eventosapp_fecha_unica', true );
        if ( $raw ) $fecha_evento = date_i18n( 'F d, Y', strtotime( $raw ) );
    } elseif ( in_array( $tipo_fecha, [ 'consecutiva', 'rango' ], true ) ) {
        $raw = get_post_meta( $event_id, '_eventosapp_fecha_inicio', true );
        if ( $raw ) $fecha_evento = date_i18n( 'F d, Y', strtotime( $raw ) );
    } elseif ( $tipo_fecha === 'noconsecutiva' ) {
        $days = eventosapp_double_auth_event_days( $event_id );
        if ( $days ) {
            $fecha_evento = implode( ', ', array_map( static function( $day ) {
                return date_i18n( 'F d, Y', strtotime( $day ) );
            }, $days ) );
        }
    }

    $hora_inicio = get_post_meta( $event_id, '_eventosapp_hora_inicio', true ) ?: '';
    $hora_cierre = get_post_meta( $event_id, '_eventosapp_hora_cierre', true ) ?: '';
    $hora_evento = $hora_inicio && $hora_cierre ? $hora_inicio . ' – ' . $hora_cierre : ( $hora_inicio ?: ( $hora_cierre ? 'Hasta ' . $hora_cierre : '' ) );
    $tz_label = eventosapp_double_auth_event_timezone( $event_id )->getName();
    if ( $hora_evento && $tz_label ) $hora_evento .= ' (' . $tz_label . ')';

    $html = strtr( $html, [
        '{{header_img}}'       => esc_url( $header_img ),
        '{{evento_nombre}}'    => esc_html( $event_title ),
        '{{organizador}}'      => esc_html( $organizador ),
        '{{fecha_evento}}'     => esc_html( $fecha_evento ),
        '{{hora_evento}}'      => esc_html( $hora_evento ),
        '{{lugar_evento}}'     => esc_html( $lugar_evento ),
        '{{asistente_nombre}}' => esc_html( $nombre_completo ),
        '{{asistente_email}}'  => esc_html( $email ),
        '{{ticket_id}}'        => esc_html( $ticket_code ),
        '{{codigo_auth}}'      => esc_html( $code ),
        '{{fecha_especifica}}' => $fecha_especifica_text,
    ] );

    $site_host = preg_replace( '/^www\./i', '', (string) parse_url( home_url(), PHP_URL_HOST ) );
    $from_email = sanitize_email( 'no-reply@' . ( $site_host ?: 'localhost' ) );
    if ( ! $from_email ) $from_email = sanitize_email( get_option( 'admin_email' ) ) ?: 'no-reply@localhost';

    $from_name = preg_replace( "/[\r\n]+/u", ' ', trim( (string) $from_name ) );
    $from_name = str_replace( [ '"', '<', '>' ], '', $from_name );
    if ( function_exists( 'mb_substr' ) && strlen( $from_name ) > 120 ) $from_name = mb_substr( $from_name, 0, 120 );

    $sent = wp_mail(
        $email,
        sprintf( '🔐 Tu Código de Verificación - %s', $event_title ),
        $html,
        [
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $from_name, $from_email ),
        ]
    );

    if ( $sent ) eventosapp_log_auth_code_send( $ticket_id, $method, 'email', [ 'day' => $specific_date ] );
    return (bool) $sent;
}

function eventosapp_send_auth_code_email_plain( $ticket_id, $method, $code, $from_name = '', $specific_date = '' ) {
    $ticket_id = absint( $ticket_id );
    $email     = sanitize_email( get_post_meta( $ticket_id, '_eventosapp_asistente_email', true ) );
    $event_id  = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    if ( ! $email || ! $event_id || ! preg_match( '/^\d{5}$/', (string) $code ) ) return false;

    $name = trim( get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true ) . ' ' . get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true ) );
    $message  = 'Hola ' . ( $name ?: 'Asistente' ) . ",\n\n";
    $message .= 'Tu código de verificación es: ' . $code . "\n";
    $message .= 'Validez: ' . eventosapp_double_auth_validity_label( $event_id, $specific_date ) . "\n\n";
    $message .= 'Presenta este código junto con tu QR al ingresar al evento.';

    $site_host = preg_replace( '/^www\./i', '', (string) parse_url( home_url(), PHP_URL_HOST ) );
    $from_email = sanitize_email( 'no-reply@' . ( $site_host ?: 'localhost' ) );
    $headers = $from_email ? [ sprintf( 'From: %s <%s>', sanitize_text_field( $from_name ?: get_bloginfo( 'name' ) ), $from_email ) ] : [];

    $sent = wp_mail( $email, sprintf( 'Tu Código de Verificación - %s', get_the_title( $event_id ) ), $message, $headers );
    if ( $sent ) eventosapp_log_auth_code_send( $ticket_id, $method, 'email', [ 'day' => $specific_date, 'fallback' => 'plain' ] );
    return (bool) $sent;
}

/**
 * Envía por una plantilla aprobada de Meta.
 * BODY: {{1}} Código (obligatoria) · {{2}} Nombre · {{3}} Evento
 * {{4}} Fecha de validez · {{5}} Ticket · {{6}} Organizador.
 */
function eventosapp_send_auth_code_whatsapp( $ticket_id, $method = 'manual', $specific_date = '', $code = '' ) {
    $ticket_id = absint( $ticket_id );
    if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) return [ 'ok' => false, 'message' => 'Ticket inválido.' ];

    $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    if ( ! $event_id || get_post_meta( $event_id, '_eventosapp_ticket_whatsapp_enabled', true ) !== '1' ) {
        return [ 'ok' => false, 'message' => 'WhatsApp no está activo para este evento.' ];
    }
    if ( ! function_exists( 'eventosapp_whatsapp_get_settings' ) || ! function_exists( 'eventosapp_whatsapp_build_template_payload' ) || ! function_exists( 'eventosapp_whatsapp_api_send_message' ) || ! function_exists( 'eventosapp_whatsapp_normalize_phone' ) ) {
        return [ 'ok' => false, 'message' => 'El motor de WhatsApp no está disponible.' ];
    }

    $template_id = eventosapp_double_auth_whatsapp_template_id( $event_id );
    if ( ! $template_id || ! function_exists( 'eventosapp_whatsapp_masivo_get_template' ) ) {
        return [ 'ok' => false, 'message' => 'Falta seleccionar una plantilla de WhatsApp para Doble Autenticación.' ];
    }

    $template = eventosapp_whatsapp_masivo_get_template( $template_id );
    $approved = is_array( $template ) && ( function_exists( 'eventosapp_whatsapp_is_template_approved' )
        ? eventosapp_whatsapp_is_template_approved( $template )
        : in_array( strtoupper( (string) ( $template['meta_status'] ?? '' ) ), [ 'APPROVED', 'ACTIVE' ], true ) );
    if ( ! $approved ) return [ 'ok' => false, 'message' => 'La plantilla de Doble Autenticación no está aprobada por Meta.' ];

    $header_format = strtoupper( sanitize_key( (string) ( $template['header_format'] ?? 'NONE' ) ) );
    if ( in_array( $header_format, [ 'IMAGE', 'VIDEO', 'DOCUMENT' ], true ) ) {
        return [ 'ok' => false, 'message' => 'La plantilla de Doble Autenticación debe usar encabezado sin multimedia.' ];
    }

    $header_text = (string) ( $template['header_text_meta'] ?? $template['header_text'] ?? '' );
    if ( preg_match( '/\{\{\s*\d+\s*\}\}/', $header_text ) ) {
        return [ 'ok' => false, 'message' => 'La plantilla de Doble Autenticación no debe usar variables dinámicas en el encabezado.' ];
    }

    $settings = eventosapp_whatsapp_get_settings();
    if ( function_exists( 'eventosapp_whatsapp_resolve_sender_settings' ) ) $settings = eventosapp_whatsapp_resolve_sender_settings( $event_id, $settings );

    $sender_id = sanitize_text_field( (string) ( $settings['sender_phone_number_id'] ?? $settings['phone_number_id'] ?? '' ) );
    if ( $sender_id && function_exists( 'eventosapp_whatsapp_template_matches_sender' ) && ! eventosapp_whatsapp_template_matches_sender( $template, $sender_id, true ) ) {
        return [ 'ok' => false, 'message' => 'La plantilla de Doble Autenticación corresponde a otro número emisor.' ];
    }

    $phone = eventosapp_whatsapp_normalize_phone(
        get_post_meta( $ticket_id, '_eventosapp_asistente_tel', true ),
        $settings['default_country_code'] ?? '57'
    );
    if ( ! $phone ) return [ 'ok' => false, 'message' => 'El ticket no tiene un número de WhatsApp válido.' ];

    $code = trim( (string) $code );
    if ( ! preg_match( '/^\d{5}$/', $code ) ) {
        $code = $specific_date ? eventosapp_get_ticket_auth_code_for_day( $ticket_id, $specific_date ) : eventosapp_get_ticket_auth_code( $ticket_id );
    }
    if ( ! preg_match( '/^\d{5}$/', (string) $code ) ) return [ 'ok' => false, 'message' => 'No existe un código válido para enviar.' ];

    if ( function_exists( 'eventosapp_whatsapp_get_runtime_body_variable_numbers' ) ) {
        $variables = eventosapp_whatsapp_get_runtime_body_variable_numbers( $template );
    } else {
        preg_match_all( '/\{\{\s*(\d+)\s*\}\}/', (string) ( $template['body_text'] ?? '' ), $matches );
        $variables = ! empty( $matches[1] ) ? array_values( array_unique( array_map( 'absint', $matches[1] ) ) ) : [];
    }
    $variables = array_values( array_unique( array_map( 'absint', (array) $variables ) ) );

    if ( ! in_array( 1, $variables, true ) ) return [ 'ok' => false, 'message' => 'La plantilla debe incluir {{1}} para el código.' ];
    if ( array_diff( $variables, [ 1, 2, 3, 4, 5, 6 ] ) ) return [ 'ok' => false, 'message' => 'La plantilla usa variables BODY incompatibles; utiliza únicamente {{1}} a {{6}}.' ];
    sort( $variables, SORT_NUMERIC );

    foreach ( range( 1, 10 ) as $button_number ) {
        if ( strpos( (string) ( $template[ 'button_' . $button_number . '_url' ] ?? '' ), '{{' ) !== false ) {
            return [ 'ok' => false, 'message' => 'La plantilla no debe usar botones URL con variables dinámicas.' ];
        }
    }

    $name = trim( get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true ) . ' ' . get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true ) );
    $organizer = function_exists( 'eventosapp_get_nombre_organizador' ) ? eventosapp_get_nombre_organizador( $event_id ) : get_post_meta( $event_id, '_eventosapp_organizador', true );

    $values = [
        1 => $code,
        2 => $name ?: 'Asistente',
        3 => get_the_title( $event_id ) ?: 'Evento',
        4 => eventosapp_double_auth_validity_label( $event_id, $specific_date ),
        5 => get_post_meta( $ticket_id, 'eventosapp_ticketID', true ) ?: (string) $ticket_id,
        6 => $organizer ?: 'Organizador del evento',
    ];

    $params = [];
    foreach ( $variables as $number ) {
        $params[] = [ 'type' => 'text', 'text' => sanitize_text_field( (string) ( $values[ $number ] ?? '-' ) ) ];
    }

    $send_lock_key = 'eventosapp_double_auth_wa_' . md5( $ticket_id . '|' . $specific_date . '|' . $code . '|' . $template_id );
    if ( get_transient( $send_lock_key ) ) {
        return [
            'ok' => true,
            'message' => 'El mismo código ya está en proceso de envío por WhatsApp.',
            'message_id' => '',
            'template' => $template['name'] ?? '',
            'skipped_duplicate' => true,
        ];
    }
    set_transient( $send_lock_key, 1, 45 );

    $components = $params ? [ [ 'type' => 'body', 'parameters' => $params ] ] : [];
    $payload = eventosapp_whatsapp_build_template_payload(
        sanitize_key( (string) ( $template['name'] ?? '' ) ),
        sanitize_text_field( (string) ( $template['language'] ?? 'es' ) ),
        $components
    );
    $result = eventosapp_whatsapp_api_send_message( $phone, $payload, $settings );

    $ok = is_array( $result ) && ! empty( $result['ok'] );
    $message_id = '';
    if ( $ok && ! empty( $result['message_id'] ) ) $message_id = sanitize_text_field( (string) $result['message_id'] );
    elseif ( $ok && ! empty( $result['response']['messages'][0]['id'] ) ) $message_id = sanitize_text_field( (string) $result['response']['messages'][0]['id'] );

    $wa_log_args = [
        'context'    => 'double_auth_code',
        'source_key' => 'double_auth:' . $ticket_id . ':' . ( $specific_date ?: 'general' ) . ':' . md5( (string) $code ),
    ];

    if ( $ok ) {
        if ( function_exists( 'eventosapp_whatsapp_register_successful_send_tracking' ) ) {
            eventosapp_whatsapp_register_successful_send_tracking( $ticket_id, $phone, $wa_log_args );
        }
        if ( $message_id ) {
            update_post_meta( $ticket_id, '_eventosapp_whatsapp_last_message_id', $message_id );
            if ( function_exists( 'eventosapp_whatsapp_register_message_map' ) ) {
                eventosapp_whatsapp_register_message_map( $message_id, $ticket_id, 'double_auth_code', $phone );
            }
        }
        update_post_meta( $ticket_id, '_eventosapp_whatsapp_last_status', 'aceptado_meta' );
        update_post_meta( $ticket_id, '_eventosapp_whatsapp_last_template_id', $template_id );
        update_post_meta( $ticket_id, '_eventosapp_whatsapp_last_template_name', sanitize_key( (string) ( $template['name'] ?? '' ) ) );

        if ( function_exists( 'eventosapp_whatsapp_add_ticket_log' ) ) {
            eventosapp_whatsapp_add_ticket_log(
                $ticket_id,
                'aceptado_meta',
                is_array( $result ) && ! empty( $result['message'] ) ? $result['message'] : 'Código de Doble Autenticación aceptado por Meta.',
                $wa_log_args,
                $phone,
                array_merge( is_array( $result ) ? $result : [], [
                    'transport'       => 'template',
                    'template_name'   => sanitize_key( (string) ( $template['name'] ?? '' ) ),
                    'delivery_status' => 'pendiente_webhook',
                ] )
            );
        }

        eventosapp_log_auth_code_send( $ticket_id, $method, 'whatsapp', [
            'day' => $specific_date,
            'message_id' => $message_id,
            'template_id' => $template_id,
            'template' => $template['name'] ?? '',
        ] );
        if ( function_exists( 'eventosapp_whatsapp_add_activity_log' ) ) {
            eventosapp_whatsapp_add_activity_log( 'double_auth_code_sent', [
                'ticket_id' => $ticket_id,
                'event_id' => $event_id,
                'phone' => $phone,
                'template' => $template['name'] ?? '',
                'message_id' => $message_id,
                'day' => $specific_date,
            ] );
        }
    } else {
        delete_transient( $send_lock_key );
        update_post_meta( $ticket_id, '_eventosapp_whatsapp_last_status', 'error' );
        update_post_meta( $ticket_id, '_eventosapp_whatsapp_last_error', is_array( $result ) ? (string) ( $result['message'] ?? 'Error de WhatsApp.' ) : 'Error de WhatsApp.' );
        if ( function_exists( 'eventosapp_whatsapp_add_ticket_log' ) ) {
            eventosapp_whatsapp_add_ticket_log(
                $ticket_id,
                'error',
                is_array( $result ) && ! empty( $result['message'] ) ? $result['message'] : 'No se pudo enviar el código de Doble Autenticación por WhatsApp.',
                $wa_log_args,
                $phone,
                array_merge( is_array( $result ) ? $result : [], [
                    'transport'     => 'template',
                    'template_name' => sanitize_key( (string) ( $template['name'] ?? '' ) ),
                ] )
            );
        }
    }

    return [
        'ok' => $ok,
        'message' => is_array( $result ) && ! empty( $result['message'] )
            ? sanitize_text_field( $result['message'] )
            : ( $ok ? 'Código enviado por WhatsApp.' : 'No se pudo enviar el código por WhatsApp.' ),
        'message_id' => $message_id,
        'template' => $template['name'] ?? '',
    ];
}

function eventosapp_send_auth_code_channels( $ticket_id, $method = 'manual', $specific_date = '', $code = '' ) {
    $ticket_id = absint( $ticket_id );
    $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    if ( ! $ticket_id || ! $event_id ) return [ 'ok' => false, 'all_ok' => false, 'partial' => false, 'message' => 'Ticket o evento inválido.', 'channels' => [] ];

    $code = trim( (string) $code );
    if ( ! preg_match( '/^\d{5}$/', $code ) ) {
        $code = $specific_date ? eventosapp_get_ticket_auth_code_for_day( $ticket_id, $specific_date ) : eventosapp_get_ticket_auth_code( $ticket_id );
    }
    if ( ! preg_match( '/^\d{5}$/', (string) $code ) ) return [ 'ok' => false, 'all_ok' => false, 'partial' => false, 'message' => 'No existe un código válido para enviar.', 'channels' => [] ];

    $config = eventosapp_double_auth_event_channels( $event_id );
    $results = [];

    if ( ! empty( $config['email'] ) ) {
        $sent = eventosapp_send_auth_code_email_with_context( $ticket_id, $method, $code, $specific_date );
        $results['email'] = [ 'ok' => (bool) $sent, 'message' => $sent ? 'Correo enviado.' : 'No se pudo enviar el correo.' ];
    }
    if ( ! empty( $config['whatsapp'] ) ) {
        $results['whatsapp'] = eventosapp_send_auth_code_whatsapp( $ticket_id, $method, $specific_date, $code );
    }

    $attempted = count( $results );
    $ok_count = 0;
    foreach ( $results as $row ) if ( ! empty( $row['ok'] ) ) $ok_count++;

    $ok = $attempted > 0 && $ok_count > 0;
    $all_ok = $attempted > 0 && $ok_count === $attempted;
    $partial = $ok && ! $all_ok;

    $parts = [];
    foreach ( $results as $channel => $row ) {
        $parts[] = ucfirst( $channel ) . ': ' . ( ! empty( $row['ok'] ) ? 'enviado' : sanitize_text_field( (string) ( $row['message'] ?? 'falló' ) ) );
    }

    return [
        'ok' => $ok,
        'all_ok' => $all_ok,
        'partial' => $partial,
        'message' => $parts ? implode( ' · ', $parts ) : 'No hay canales configurados.',
        'channels' => $results,
        'code' => $code,
    ];
}


/**
 * Registra un envío de código en el log del ticket
 * Mantiene solo los últimos 3 registros
 * 
 * @param int $ticket_id ID del ticket
 * @param string $method Método: 'manual', 'masivo', 'automatico'
 */
function eventosapp_log_auth_code_send( $ticket_id, $method = 'manual', $channel = 'email', $context = [] ) {
    $log = get_post_meta( absint( $ticket_id ), '_eventosapp_double_auth_send_log', true );
    $log = is_array( $log ) ? $log : [];

    $user_id = get_current_user_id();
    $user = $user_id ? get_userdata( $user_id ) : null;
    $context = is_array( $context ) ? $context : [];

    $entry = [
        'timestamp' => current_time( 'timestamp' ),
        'method'    => sanitize_key( (string) $method ) ?: 'manual',
        'channel'   => sanitize_key( (string) $channel ) ?: 'email',
        'user_id'   => $user_id,
        'user_name' => $user ? $user->display_name : 'Sistema',
    ];

    if ( ! empty( $context['day'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $context['day'] ) ) $entry['day'] = sanitize_text_field( (string) $context['day'] );
    if ( ! empty( $context['message_id'] ) ) $entry['message_id'] = sanitize_text_field( (string) $context['message_id'] );
    if ( ! empty( $context['template_id'] ) ) $entry['template_id'] = sanitize_key( (string) $context['template_id'] );
    if ( ! empty( $context['template'] ) ) $entry['template'] = sanitize_text_field( (string) $context['template'] );
    if ( ! empty( $context['fallback'] ) ) $entry['fallback'] = sanitize_key( (string) $context['fallback'] );

    $log[] = $entry;
    if ( count( $log ) > 10 ) $log = array_slice( $log, -10 );
    update_post_meta( absint( $ticket_id ), '_eventosapp_double_auth_send_log', $log );
}

/**
 * Obtiene el log de envíos de un ticket
 * 
 * @param int $ticket_id ID del ticket
 * @return array Log de envíos (últimos 3)
 */
function eventosapp_get_ticket_auth_log( $ticket_id ) {
    $log = get_post_meta( $ticket_id, '_eventosapp_double_auth_send_log', true );
    return is_array( $log ) ? $log : [];
}

// ========================================
// ENVÍO MASIVO Y PROGRAMADO
// ========================================

/**
 * Envía códigos de autenticación masivamente a todos los tickets de un evento
 * Función que se ejecuta desde el panel de administración o por cron
 * 
 * @param int $event_id ID del evento
 * @return array Resultados: ['success' => int, 'failed' => int, 'total' => int]
 */
function eventosapp_send_mass_auth_codes( $event_id ) {
    $event_id = absint( $event_id );
    if ( ! $event_id ) return [ 'success' => 0, 'failed' => 0, 'partial' => 0, 'total' => 0 ];

    $target_day = eventosapp_double_auth_target_day( $event_id );
    $ticket_ids = eventosapp_double_auth_ticket_ids( $event_id );
    $success = $failed = $partial = 0;

    foreach ( $ticket_ids as $ticket_id ) {
        $code = eventosapp_double_auth_prepare_ticket_code_for_dispatch( $ticket_id, $target_day, true );
        $delivery = eventosapp_send_auth_code_channels( $ticket_id, 'masivo', $target_day, $code );

        if ( ! empty( $delivery['ok'] ) ) {
            $success++;
            if ( ! empty( $delivery['partial'] ) ) $partial++;
        } else {
            $failed++;
        }
    }

    $total = count( $ticket_ids );
    if ( $target_day ) {
        eventosapp_log_mass_send_for_day( $event_id, $target_day, $total, $success, $failed );
        if ( function_exists( 'eventosapp_mark_day_as_sent' ) ) eventosapp_mark_day_as_sent( $event_id, $target_day );
    } else {
        eventosapp_log_mass_send( $event_id, $total, $success, $failed );
        $days = eventosapp_double_auth_event_days( $event_id );
        if ( $days && function_exists( 'eventosapp_mark_day_as_sent' ) ) eventosapp_mark_day_as_sent( $event_id, (string) reset( $days ) );
    }

    return [ 'success' => $success, 'failed' => $failed, 'partial' => $partial, 'total' => $total ];
}

/**
 * Registra un envío masivo en el log del evento
 * Mantiene solo los últimos 3 registros
 * 
 * @param int $event_id ID del evento
 * @param int $total Total de tickets
 * @param int $success Enviados correctamente
 * @param int $failed Fallos
 * @param string|null $date Fecha específica del envío (para eventos multi-día)
 */
function eventosapp_log_mass_send( $event_id, $total, $success, $failed, $date = null ) {
    $log = get_post_meta( $event_id, '_eventosapp_double_auth_mass_log', true );
    
    if ( ! is_array( $log ) ) {
        $log = [];
    }
    
    $user_id = get_current_user_id();
    $user    = $user_id ? get_userdata( $user_id ) : null;
    
    // Agregar nuevo registro
    $log[] = [
        'timestamp' => current_time( 'timestamp' ),
        'total'     => $total,
        'success'   => $success,
        'failed'    => $failed,
        'user_id'   => $user_id,
        'user_name' => $user ? $user->display_name : 'Sistema',
        'date'      => $date, // Fecha específica para eventos multi-día
    ];
    
    // Mantener solo los últimos 3
    if ( count( $log ) > 3 ) {
        $log = array_slice( $log, -3 );
    }
    
    update_post_meta( $event_id, '_eventosapp_double_auth_mass_log', $log );
}

/**
 * Obtiene el log de envíos masivos de un evento
 * 
 * @param int $event_id ID del evento
 * @return array Log de envíos masivos (últimos 3)
 */
function eventosapp_get_event_mass_log( $event_id ) {
    $log = get_post_meta( $event_id, '_eventosapp_double_auth_mass_log', true );
    return is_array( $log ) ? $log : [];
}

/**
 * Programa el envío automático de códigos
 * 
 * @param int $event_id ID del evento
 * @param int $timestamp Timestamp UNIX de cuándo enviar
 */
function eventosapp_schedule_auth_codes( $event_id, $timestamp ) {
    $event_id = absint( $event_id );
    $timestamp = absint( $timestamp );
    if ( ! $event_id || ! $timestamp ) return false;

    update_post_meta( $event_id, '_eventosapp_double_auth_scheduled_datetime', $timestamp );
    if ( function_exists( 'eventosapp_schedule_auth_codes_send' ) ) {
        return (bool) eventosapp_schedule_auth_codes_send( $event_id );
    }

    wp_clear_scheduled_hook( 'eventosapp_auto_send_auth_codes', [ $event_id ] );
    return wp_schedule_single_event( $timestamp, 'eventosapp_auto_send_auth_codes', [ $event_id ] );
}

/**
 * Hook para envío automático programado
 */
add_action( 'eventosapp_auto_send_auth_codes', function( $event_id ) {
    eventosapp_send_mass_auth_codes( $event_id );
}, 10, 1 );

/**
 * Regenera códigos para todos los tickets de un evento y los envía.
 *
 * - Eventos de día único o modo "first_day":
 *      * Regenera SIEMPRE un nuevo código general por ticket.
 *      * Envía el código por email (método "masivo").
 *
 * - Eventos multi-día en modo "all_days":
 *      * Regenera los códigos de TODOS los días del evento para cada ticket.
 *      * Sincroniza el código general y el "día actual" con el día objetivo.
 *      * Envía por email SOLO el código del día objetivo (hoy si es día del evento,
 *        o el primer día del evento en su defecto).
 *
 * En todos los casos actualiza el log masivo del evento y el log del ticket,
 * de forma que el metabox "🔐 Información de Doble Autenticación" vea los códigos
 * correctamente.
 *
 * @param int $event_id ID del evento
 * @return array Resultados: ['success' => int, 'failed' => int, 'total' => int]
 */
function eventosapp_regenerate_and_send_mass_auth_codes( $event_id ) {
    $event_id = absint( $event_id );
    if ( ! $event_id ) return [ 'success' => 0, 'failed' => 0, 'partial' => 0, 'total' => 0 ];

    $mode = get_post_meta( $event_id, '_eventosapp_ticket_double_auth_mode', true ) ?: 'first_day';
    $days = eventosapp_double_auth_event_days( $event_id );
    $today = eventosapp_double_auth_current_event_day( $event_id );
    $target_day = $mode === 'all_days' && $days
        ? ( in_array( $today, $days, true ) ? $today : (string) reset( $days ) )
        : '';

    $ticket_ids = eventosapp_double_auth_ticket_ids( $event_id );
    $success = $failed = $partial = 0;

    foreach ( $ticket_ids as $ticket_id ) {
        $code = '';

        if ( $mode === 'all_days' && $days ) {
            foreach ( $days as $day ) {
                $day_code = eventosapp_assign_auth_code_to_ticket_for_day( $ticket_id, $day );
                if ( $day === $target_day ) $code = $day_code;
            }
            if ( ! $code && $target_day ) $code = eventosapp_assign_auth_code_to_ticket_for_day( $ticket_id, $target_day );

            if ( $target_day && $code ) {
                update_post_meta( $ticket_id, '_eventosapp_double_auth_current_day', $target_day );
                update_post_meta( $ticket_id, '_eventosapp_double_auth_code', $code );
                update_post_meta( $ticket_id, '_eventosapp_double_auth_code_date', current_time( 'timestamp' ) );
            }
        } else {
            $code = eventosapp_assign_auth_code_to_ticket( $ticket_id );
        }

        $delivery = eventosapp_send_auth_code_channels( $ticket_id, 'masivo', $target_day, $code );
        if ( ! empty( $delivery['ok'] ) ) {
            $success++;
            if ( ! empty( $delivery['partial'] ) ) $partial++;
        } else {
            $failed++;
        }
    }

    $total = count( $ticket_ids );
    if ( $target_day ) eventosapp_log_mass_send_for_day( $event_id, $target_day, $total, $success, $failed );
    else eventosapp_log_mass_send( $event_id, $total, $success, $failed );

    return [ 'success' => $success, 'failed' => $failed, 'partial' => $partial, 'total' => $total ];
}


// ========================================
// AJAX: REGENERAR Y ENVIAR CÓDIGOS MASIVAMENTE
// ========================================

add_action( 'wp_ajax_eventosapp_regenerate_and_send_auth_codes', function() {
    check_ajax_referer( 'eventosapp_double_auth_regenerate', 'nonce' );
    
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( 'Permisos insuficientes' );
    }
    
    $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
    
    if ( ! $event_id ) {
        wp_send_json_error( 'ID de evento no proporcionado' );
    }
    
    // En instalaciones con Cola y Tareas, el proceso masivo se ejecuta por lotes
    // para evitar timeouts/picos de memoria y queda administrable desde la cola.
    if ( function_exists( 'eventosapp_task_queue_create_double_auth_manual' ) && function_exists( 'eventosapp_task_queue_get_adapter' ) && eventosapp_task_queue_get_adapter( 'double_auth_regenerate' ) ) {
        $task_id = eventosapp_task_queue_create_double_auth_manual( $event_id, true );
        if ( is_wp_error( $task_id ) ) {
            wp_send_json_error( $task_id->get_error_message() );
        }
        $task_url = function_exists( 'eventosapp_task_queue_task_url' ) ? eventosapp_task_queue_task_url( $task_id ) : '';
        wp_send_json_success( [
            'message'  => 'La regeneración y envío se agregó a Cola y Tareas. Se procesará por lotes y podrás revisar su progreso desde allí.',
            'task_id'  => absint( $task_id ),
            'task_url' => esc_url_raw( $task_url ),
            'queued'   => true,
        ] );
    }

    // Fallback histórico si la cola no está disponible.
    $result = eventosapp_regenerate_and_send_mass_auth_codes( $event_id );
    wp_send_json_success( [
        'message' => sprintf(
            'Códigos regenerados y enviados: %d exitosos, %d fallidos de %d total',
            $result['success'],
            $result['failed'],
            $result['total']
        ),
        'result' => $result,
    ] );
});

// ========================================
// AJAX: ENVÍO MANUAL DESDE METABOX DE EVENTO
// ========================================

add_action( 'wp_ajax_eventosapp_test_send_auth_code', function() {
    check_ajax_referer( 'eventosapp_double_auth_test', 'nonce' );
    if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permisos insuficientes' );

    $public_id = isset( $_POST['ticket_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_id'] ) ) : '';
    if ( ! $public_id ) wp_send_json_error( 'ID de ticket no proporcionado' );

    $ticket_id = function_exists( 'eventosapp_find_ticket_by_public_id' ) ? absint( eventosapp_find_ticket_by_public_id( $public_id ) ) : 0;
    if ( ! $ticket_id ) {
        $ids = get_posts( [
            'post_type' => 'eventosapp_ticket',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => 'eventosapp_ticketID',
            'meta_value' => $public_id,
        ] );
        $ticket_id = $ids ? absint( $ids[0] ) : 0;
    }
    if ( ! $ticket_id ) wp_send_json_error( 'Ticket no encontrado' );

    $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    $day = eventosapp_double_auth_target_day( $event_id );
    $code = eventosapp_double_auth_prepare_ticket_code_for_dispatch( $ticket_id, $day, true );
    $delivery = eventosapp_send_auth_code_channels( $ticket_id, 'manual', $day, $code );

    if ( ! empty( $delivery['ok'] ) ) {
        wp_send_json_success( [ 'message' => 'Código generado. ' . $delivery['message'], 'code' => $code, 'delivery' => $delivery ] );
    }
    wp_send_json_error( $delivery['message'] ?: 'No fue posible enviar el código.' );
});

// ========================================
// AJAX: ENVÍO MASIVO DESDE METABOX DE EVENTO
// ========================================

add_action( 'wp_ajax_eventosapp_mass_send_auth_codes', function() {
    check_ajax_referer( 'eventosapp_double_auth_mass', 'nonce' );
    
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( 'Permisos insuficientes' );
    }
    
    $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
    
    if ( ! $event_id ) {
        wp_send_json_error( 'ID de evento no proporcionado' );
    }
    
    // Preferir siempre la cola central: el envío queda visible, pausable y
    // protegido por los límites de recursos del worker.
    if ( function_exists( 'eventosapp_task_queue_create_double_auth_manual' ) && function_exists( 'eventosapp_task_queue_get_adapter' ) && eventosapp_task_queue_get_adapter( 'double_auth_massive' ) ) {
        $task_id = eventosapp_task_queue_create_double_auth_manual( $event_id, false );
        if ( is_wp_error( $task_id ) ) {
            wp_send_json_error( $task_id->get_error_message() );
        }
        $task_url = function_exists( 'eventosapp_task_queue_task_url' ) ? eventosapp_task_queue_task_url( $task_id ) : '';
        wp_send_json_success( [
            'message'  => 'El envío masivo se agregó a Cola y Tareas. Se procesará por lotes y podrás revisar su progreso desde allí.',
            'task_id'  => absint( $task_id ),
            'task_url' => esc_url_raw( $task_url ),
            'queued'   => true,
        ] );
    }

    // Fallback histórico si la cola central no está disponible.
    $result = eventosapp_send_mass_auth_codes( $event_id );
    wp_send_json_success( [
        'message' => sprintf(
            'Envío masivo completado: %d exitosos, %d fallidos de %d total',
            $result['success'],
            $result['failed'],
            $result['total']
        ),
        'result' => $result,
    ] );
});

// ========================================
// AJAX: ENVÍO MANUAL DESDE METABOX DE TICKET
// ========================================

add_action( 'wp_ajax_eventosapp_send_single_auth_code', function() {
    check_ajax_referer( 'eventosapp_double_auth_single', 'nonce' );
    if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permisos insuficientes' );

    $ticket_id = isset( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
    if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) wp_send_json_error( 'ID de ticket inválido' );

    $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    $day = eventosapp_double_auth_target_day( $event_id );
    $code = eventosapp_double_auth_prepare_ticket_code_for_dispatch( $ticket_id, $day, true );
    $delivery = eventosapp_send_auth_code_channels( $ticket_id, 'manual', $day, $code );

    if ( ! empty( $delivery['ok'] ) ) {
        wp_send_json_success( [ 'message' => 'Código generado. ' . $delivery['message'], 'code' => $code, 'delivery' => $delivery ] );
    }
    wp_send_json_error( $delivery['message'] ?: 'No fue posible enviar el código.' );
});

// ========================================
// AJAX: REVELAR CÓDIGO EN METABOX DE TICKET
// ========================================

add_action( 'wp_ajax_eventosapp_reveal_auth_code', function() {
    check_ajax_referer( 'eventosapp_double_auth_reveal', 'nonce' );
    
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( 'Permisos insuficientes' );
    }
    
    $ticket_id = isset( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
    
    if ( ! $ticket_id ) {
        wp_send_json_error( 'ID de ticket no proporcionado' );
    }
    
    $code = eventosapp_get_ticket_auth_code( $ticket_id );
    
    if ( $code ) {
        wp_send_json_success( [ 'code' => $code ] );
    } else {
        wp_send_json_error( 'No hay código asignado' );
    }
});

// ========================================
// SOPORTE PARA CÓDIGOS POR DÍA (MULTI-DÍA)
// ========================================

/**
 * Asigna un código de autenticación para un día específico de un ticket
 * 
 * @param int $ticket_id ID del ticket
 * @param string $date Fecha en formato Y-m-d
 * @return string Código generado
 */
function eventosapp_assign_auth_code_to_ticket_for_day( $ticket_id, $date ) {
    $code = eventosapp_generate_auth_code();
    $now  = current_time('timestamp');
    
    // Guardar código para este día específico
    $meta_key_code = '_eventosapp_double_auth_code_day_' . $date;
    $meta_key_date = '_eventosapp_double_auth_code_date_day_' . $date;
    
    update_post_meta( $ticket_id, $meta_key_code, $code );
    update_post_meta( $ticket_id, $meta_key_date, $now );
    
    // También actualizar el "día actual" del ticket
    update_post_meta( $ticket_id, '_eventosapp_double_auth_current_day', $date );
    
    return $code;
}

/**
 * Obtiene el código de autenticación de un ticket para un día específico
 * 
 * @param int $ticket_id ID del ticket
 * @param string $date Fecha en formato Y-m-d (null para código general)
 * @return string|false Código o false si no existe
 */
function eventosapp_get_ticket_auth_code_for_day( $ticket_id, $date = null ) {
    if ( ! $date ) {
        // Código general (backward compatibility)
        return eventosapp_get_ticket_auth_code( $ticket_id );
    }
    
    $meta_key = '_eventosapp_double_auth_code_day_' . $date;
    $code = get_post_meta( $ticket_id, $meta_key, true );
    
    return $code ? $code : false;
}

/**
 * Obtiene la fecha de generación de un código para un día específico
 * 
 * @param int $ticket_id ID del ticket
 * @param string $date Fecha en formato Y-m-d
 * @return int|false Timestamp o false si no existe
 */
function eventosapp_get_ticket_auth_code_date_for_day( $ticket_id, $date ) {
    $meta_key = '_eventosapp_double_auth_code_date_day_' . $date;
    $timestamp = get_post_meta( $ticket_id, $meta_key, true );
    
    return $timestamp ? (int) $timestamp : false;
}

/**
 * Obtiene todos los códigos de un ticket para todos sus días
 * 
 * @param int $ticket_id ID del ticket
 * @param int $event_id ID del evento
 * @return array Array con estructura ['date' => ['code' => string, 'timestamp' => int]]
 */
function eventosapp_get_all_ticket_day_codes( $ticket_id, $event_id ) {
    $days = function_exists('eventosapp_get_event_days') 
        ? eventosapp_get_event_days($event_id) 
        : [];
    
    if ( empty($days) ) {
        return [];
    }
    
    $result = [];
    
    foreach ( $days as $day ) {
        $code = eventosapp_get_ticket_auth_code_for_day( $ticket_id, $day );
        $timestamp = eventosapp_get_ticket_auth_code_date_for_day( $ticket_id, $day );
        
        $result[$day] = [
            'code' => $code ? $code : null,
            'timestamp' => $timestamp ? $timestamp : null,
        ];
    }
    
    return $result;
}

/**
 * Envía códigos de autenticación para un día específico a todos los tickets de un evento
 * Función llamada por el sistema de cron para eventos multi-día
 * 
 * @param int $event_id ID del evento
 * @param string|null $date Fecha específica (Y-m-d) o null para primer día
 * @return array Resultados: ['success' => int, 'failed' => int, 'total' => int, 'date' => string]
 */
function eventosapp_send_mass_auth_codes_for_day( $event_id, $date = null ) {
    $event_id = absint( $event_id );
    $date = sanitize_text_field( (string) $date );
    if ( ! $event_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        return [ 'success' => 0, 'failed' => 0, 'partial' => 0, 'total' => 0 ];
    }

    $ticket_ids = eventosapp_double_auth_ticket_ids( $event_id );
    $success = $failed = $partial = 0;

    foreach ( $ticket_ids as $ticket_id ) {
        $code = eventosapp_double_auth_prepare_ticket_code_for_dispatch( $ticket_id, $date, true );
        $delivery = eventosapp_send_auth_code_channels( $ticket_id, 'automatico', $date, $code );

        if ( ! empty( $delivery['ok'] ) ) {
            $success++;
            if ( ! empty( $delivery['partial'] ) ) $partial++;
        } else {
            $failed++;
        }
    }

    $total = count( $ticket_ids );
    eventosapp_log_mass_send_for_day( $event_id, $date, $total, $success, $failed );
    if ( function_exists( 'eventosapp_mark_day_as_sent' ) ) eventosapp_mark_day_as_sent( $event_id, $date );

    return [ 'success' => $success, 'failed' => $failed, 'partial' => $partial, 'total' => $total ];
}

/**
 * Envía el código de autenticación de un día específico por email
 * 
 * @param int $ticket_id ID del ticket
 * @param string $date Fecha en formato Y-m-d
 * @param string $method Método de envío: 'manual', 'masivo', 'automatico'
 * @return bool True si se envió correctamente, false si falló
 */
function eventosapp_send_auth_code_email_for_day( $ticket_id, $date, $method = 'manual' ) {
    $ticket_id = absint( $ticket_id );
    $date = sanitize_text_field( (string) $date );
    if ( ! $ticket_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return false;

    $code = eventosapp_get_ticket_auth_code_for_day( $ticket_id, $date );
    if ( ! $code ) $code = eventosapp_assign_auth_code_to_ticket_for_day( $ticket_id, $date );

    return eventosapp_send_auth_code_email_with_context( $ticket_id, $method, $code, $date );
}

/**
 * Registra un envío de código para un día específico en el log del ticket
 * 
 * @param int $ticket_id ID del ticket
 * @param string $date Fecha del día
 * @param string $method Método de envío
 */
function eventosapp_log_ticket_send_for_day( $ticket_id, $date, $method ) {
    eventosapp_log_auth_code_send( $ticket_id, $method, 'email', [ 'day' => $date ] );
}

/**
 * Registra un envío masivo para un día específico en el log del evento
 * 
 * @param int $event_id ID del evento
 * @param string $date Fecha del día
 * @param int $total Total de tickets
 * @param int $success Enviados correctamente
 * @param int $failed Fallos
 */
function eventosapp_log_mass_send_for_day( $event_id, $date, $total, $success, $failed ) {
    $log = get_post_meta( $event_id, '_eventosapp_double_auth_mass_log', true );
    
    if ( ! is_array( $log ) ) {
        $log = [];
    }
    
    $user_id = get_current_user_id();
    $user    = $user_id ? get_userdata( $user_id ) : null;
    
    // Agregar nuevo registro
    $log[] = [
        'timestamp' => current_time( 'timestamp' ),
        'total'     => $total,
        'success'   => $success,
        'failed'    => $failed,
        'user_id'   => $user_id,
        'user_name' => $user ? $user->display_name : 'Sistema',
        'day'       => $date, // NUEVO: registrar el día específico
    ];
    
    // Mantener solo los últimos 5
    if ( count( $log ) > 5 ) {
        $log = array_slice( $log, -5 );
    }
    
    update_post_meta( $event_id, '_eventosapp_double_auth_mass_log', $log );
}

// ========================================
// AJAX: REVELAR CÓDIGO DE UN DÍA ESPECÍFICO
// ========================================

add_action( 'wp_ajax_eventosapp_reveal_auth_code_for_day', function() {
    check_ajax_referer( 'eventosapp_double_auth_reveal', 'nonce' );
    
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( 'Permisos insuficientes' );
    }
    
    $ticket_id = isset( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
    $date = isset( $_POST['date'] ) ? sanitize_text_field( $_POST['date'] ) : '';
    
    if ( ! $ticket_id || ! $date ) {
        wp_send_json_error( 'Datos incompletos' );
    }
    
    // Validar formato de fecha
    if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ) {
        wp_send_json_error( 'Formato de fecha inválido' );
    }
    
    $code = eventosapp_get_ticket_auth_code_for_day( $ticket_id, $date );
    
    if ( $code ) {
        wp_send_json_success( [ 'code' => $code ] );
    } else {
        wp_send_json_error( 'No hay código asignado para este día' );
    }
});

// ========================================
// AJAX: ENVIAR CÓDIGO DE UN DÍA ESPECÍFICO
// ========================================

add_action( 'wp_ajax_eventosapp_send_auth_code_for_day', function() {
    check_ajax_referer( 'eventosapp_double_auth_single', 'nonce' );
    if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permisos insuficientes' );

    $ticket_id = isset( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
    $date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
    if ( ! $ticket_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) wp_send_json_error( 'Datos o fecha inválidos' );

    $code = eventosapp_double_auth_prepare_ticket_code_for_dispatch( $ticket_id, $date, true );
    $delivery = eventosapp_send_auth_code_channels( $ticket_id, 'manual', $date, $code );

    if ( ! empty( $delivery['ok'] ) ) {
        wp_send_json_success( [
            'message' => sprintf( 'Código del %s generado. %s', date_i18n( 'd/m/Y', strtotime( $date ) ), $delivery['message'] ),
            'code' => $code,
            'delivery' => $delivery,
        ] );
    }
    wp_send_json_error( $delivery['message'] ?: 'No fue posible enviar el código.' );
});

