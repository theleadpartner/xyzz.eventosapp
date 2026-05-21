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
    // Normalizar input
    $input_code = trim( (string) $input_code );
    if ( $input_code === '' ) {
        return false;
    }

    // Código general por defecto
    $stored_code = eventosapp_get_ticket_auth_code( $ticket_id );

    // Intentar detectar contexto de evento / multi-día
    $event_id = get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true );
    if ( $event_id ) {
        $auth_mode  = get_post_meta( $event_id, '_eventosapp_ticket_double_auth_mode', true );
        $tipo_fecha = get_post_meta( $event_id, '_eventosapp_tipo_fecha', true );
        $tipo_fecha = $tipo_fecha ? $tipo_fecha : 'unica';

        // Solo aplicar lógica por día cuando:
        // - El evento es multi-día (no 'unica')
        // - El modo de autenticación es "all_days"
        if ( $auth_mode === 'all_days' && $tipo_fecha !== 'unica' && function_exists( 'eventosapp_get_event_days' ) ) {
            $days = (array) eventosapp_get_event_days( $event_id );
            if ( ! empty( $days ) ) {
                // Obtener "hoy" en la zona horaria del evento
                $event_tz = get_post_meta( $event_id, '_eventosapp_zona_horaria', true );
                if ( ! $event_tz ) {
                    $event_tz = wp_timezone_string();
                    if ( ! $event_tz || $event_tz === 'UTC' ) {
                        $offset   = get_option( 'gmt_offset' );
                        $event_tz = $offset ? timezone_name_from_abbr( '', $offset * 3600, 0 ) ?: 'UTC' : 'UTC';
                    }
                }

                try {
                    $dt    = new DateTime( 'now', new DateTimeZone( $event_tz ) );
                    $today = $dt->format( 'Y-m-d' );
                } catch ( Exception $e ) {
                    $dt    = new DateTime( 'now', wp_timezone() );
                    $today = $dt->format( 'Y-m-d' );
                }

                // Si hoy es un día del evento, intentamos validar contra el código específico de ese día
                if ( in_array( $today, $days, true ) ) {
                    $stored_day_code = eventosapp_get_ticket_auth_code_for_day( $ticket_id, $today );
                    if ( $stored_day_code ) {
                        $stored_code = $stored_day_code;
                    }
                }
            }
        }
    }

    if ( ! $stored_code ) {
        return false;
    }

    $stored_code = trim( (string) $stored_code );

    return $input_code === $stored_code;
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
    // Verificar que el ticket existe
    if ( get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
        return false;
    }
    
    // Obtener datos del ticket
    $email    = get_post_meta( $ticket_id, '_eventosapp_asistente_email', true );
    $nombre   = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
    $apellido = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );
    $event_id = get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true );
    
    if ( ! $email || ! $event_id ) {
        return false;
    }
    
    $event_title = get_the_title( $event_id );
    $ticket_code = get_post_meta( $ticket_id, 'eventosapp_ticketID', true );
    $code        = eventosapp_get_ticket_auth_code( $ticket_id );
    
    // Si no existe código, generar uno nuevo
    if ( ! $code ) {
        $code = eventosapp_assign_auth_code_to_ticket( $ticket_id );
    }
    
    $nombre_completo = trim( $nombre . ' ' . $apellido );
    
    // ========================================
    // OBTENER CONFIGURACIÓN DEL METABOX
    // ========================================
    
    // Header image del evento (igual que el ticket normal)
    $header_img = get_post_meta( $event_id, '_eventosapp_email_header_img', true );
    if ( ! $header_img ) {
        $header_img = 'https://eventosapp.com/wp-content/uploads/2025/08/header_ticket_gen.jpg';
    }
    
    // From Name configurado en el metabox
    $from_name = get_post_meta( $event_id, '_eventosapp_email_fromname', true );
    if ( ! $from_name ) {
        $from_name = get_bloginfo( 'name' );
    }
    
    // ========================================
    // CARGAR PLANTILLA HTML
    // ========================================
    
    $template_path = dirname( __FILE__, 2 ) . '/templates/email_tickets/email-ticket-auth.html';
    
    if ( ! file_exists( $template_path ) ) {
        // Fallback a texto plano si no existe la plantilla
        return eventosapp_send_auth_code_email_plain( $ticket_id, $method, $code, $from_name );
    }
    
    $html = file_get_contents( $template_path );
    
    // ========================================
    // REEMPLAZAR TOKENS EN LA PLANTILLA
    // ========================================
    
    // Datos del evento
    $organizador  = get_post_meta( $event_id, '_eventosapp_organizador', true ) ?: '';
    $lugar_evento = get_post_meta( $event_id, '_eventosapp_direccion', true ) ?: '';
    
    // Verificar si este código es para un día específico (eventos multi-día con all_days)
    $specific_date = get_post_meta( $ticket_id, '_eventosapp_double_auth_current_day', true );
    $auth_mode = get_post_meta( $event_id, '_eventosapp_ticket_double_auth_mode', true );
    
    // Fecha legible del evento (usando el mismo método que el ticket normal)
    $tipo_fecha = get_post_meta( $event_id, '_eventosapp_tipo_fecha', true );
    $fecha_evento = '';
    $hora_evento  = '';
    $fecha_especifica_text = ''; // Para mostrar "Este código es válido para el día X"
    
    // Si hay una fecha específica (modo all_days en eventos multi-día), usarla
    if ( $specific_date && $auth_mode === 'all_days' && $tipo_fecha !== 'unica' ) {
        $fecha_evento = date_i18n( 'F d, Y', strtotime( $specific_date ) );
        $fecha_especifica_text = sprintf( 
            '<p style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px;margin:16px 0;color:#856404;font-size:14px;"><strong>📅 Este código es válido solo para:</strong> %s</p>',
            $fecha_evento
        );
    } else {
        // Fecha normal para eventos de día único o primer día
        if ( $tipo_fecha === 'unica' ) {
            $fecha_raw = get_post_meta( $event_id, '_eventosapp_fecha_unica', true );
            if ( $fecha_raw ) {
                $fecha_evento = date_i18n( 'F d, Y', strtotime( $fecha_raw ) );
            }
        } elseif ( $tipo_fecha === 'consecutiva' ) {
            $inicio = get_post_meta( $event_id, '_eventosapp_fecha_inicio', true );
            if ( $inicio ) {
                $fecha_evento = date_i18n( 'F d, Y', strtotime( $inicio ) );
            }
        } elseif ( $tipo_fecha === 'noconsecutiva' ) {
            $fechas = get_post_meta( $event_id, '_eventosapp_fechas_noco', true );
            if ( is_string( $fechas ) ) {
                $fechas = @unserialize( $fechas );
            }
            if ( ! is_array( $fechas ) ) {
                $fechas = [];
            }
            if ( $fechas ) {
                $fecha_evento = implode( ', ', array_map( function( $f ) {
                    return date_i18n( 'F d, Y', strtotime( $f ) );
                }, $fechas ) );
            }
        }
    }
    
    // Hora del evento
    $hora_inicio = get_post_meta( $event_id, '_eventosapp_hora_inicio', true ) ?: '';
    $hora_cierre = get_post_meta( $event_id, '_eventosapp_hora_cierre', true ) ?: '';
    $tz_label    = get_post_meta( $event_id, '_eventosapp_zona_horaria', true ) ?: '';
    
    if ( ! $tz_label ) {
        $tz_label = wp_timezone_string();
    }
    
    if ( $hora_inicio && $hora_cierre ) {
        $hora_evento = $hora_inicio . ' – ' . $hora_cierre;
    } elseif ( $hora_inicio ) {
        $hora_evento = $hora_inicio;
    } elseif ( $hora_cierre ) {
        $hora_evento = 'Hasta ' . $hora_cierre;
    }
    
    if ( $hora_evento && $tz_label ) {
        $hora_evento .= ' (' . $tz_label . ')';
    }
    
    // Tokens a reemplazar
    $tokens = [
        '{{header_img}}'           => esc_url( $header_img ),
        '{{evento_nombre}}'        => esc_html( $event_title ),
        '{{organizador}}'          => esc_html( $organizador ),
        '{{fecha_evento}}'         => esc_html( $fecha_evento ),
        '{{hora_evento}}'          => esc_html( $hora_evento ),
        '{{lugar_evento}}'         => esc_html( $lugar_evento ),
        '{{asistente_nombre}}'     => esc_html( $nombre_completo ),
        '{{asistente_email}}'      => esc_html( $email ),
        '{{ticket_id}}'            => esc_html( $ticket_code ),
        '{{codigo_auth}}'          => esc_html( $code ),
        '{{fecha_especifica}}'     => $fecha_especifica_text, // Ya viene con HTML escapado
    ];
    
    $html = strtr( $html, $tokens );
    
    // ========================================
    // CONFIGURAR Y ENVIAR EMAIL
    // ========================================
    
    // Asunto
    $subject = sprintf( '🔐 Tu Código de Verificación - %s', $event_title );
    
    // Cabeceras: From con nombre personalizado (dominio propio para evitar DMARC)
    $site_host = parse_url( home_url(), PHP_URL_HOST );
    if ( ! $site_host ) {
        $site_host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    }
    $site_host  = preg_replace( '/^www\./i', '', $site_host );
    $from_email = sanitize_email( 'no-reply@' . $site_host );
    
    if ( ! $from_email ) {
        $admin_fallback = get_option( 'admin_email' );
        $from_email = is_email( $admin_fallback ) ? $admin_fallback : 'no-reply@localhost';
    }
    
    // Sanitizar From Name (sin saltos de línea ni caracteres especiales)
    $from_name = preg_replace( "/[\r\n]+/u", ' ', trim( $from_name ) );
    $from_name = str_replace( ['"', '<', '>'], '', $from_name );
    if ( strlen( $from_name ) > 120 ) {
        $from_name = mb_substr( $from_name, 0, 120 );
    }
    
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        sprintf( 'From: %s <%s>', $from_name, $from_email ),
    ];
    
    // Enviar
    $content_type_cb = function() { return 'text/html'; };
    add_filter( 'wp_mail_content_type', $content_type_cb );
    
    $sent = wp_mail( $email, $subject, $html, $headers );
    
    remove_filter( 'wp_mail_content_type', $content_type_cb );
    
    // Registrar en el log del ticket
    if ( $sent ) {
        eventosapp_log_auth_code_send( $ticket_id, $method );
    }
    
    return $sent;
}

/**
 * Registra un envío de código en el log del ticket
 * Mantiene solo los últimos 3 registros
 * 
 * @param int $ticket_id ID del ticket
 * @param string $method Método: 'manual', 'masivo', 'automatico'
 */
function eventosapp_log_auth_code_send( $ticket_id, $method = 'manual' ) {
    $log = get_post_meta( $ticket_id, '_eventosapp_double_auth_send_log', true );
    
    if ( ! is_array( $log ) ) {
        $log = [];
    }
    
    $user_id = get_current_user_id();
    $user    = $user_id ? get_userdata( $user_id ) : null;
    
    // Agregar nuevo registro
    $log[] = [
        'timestamp' => current_time( 'timestamp' ),
        'method'    => $method,
        'user_id'   => $user_id,
        'user_name' => $user ? $user->display_name : 'Sistema',
    ];
    
    // Mantener solo los últimos 3
    if ( count( $log ) > 3 ) {
        $log = array_slice( $log, -3 );
    }
    
    update_post_meta( $ticket_id, '_eventosapp_double_auth_send_log', $log );
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
    // Detectar configuración del evento
    $auth_mode  = get_post_meta( $event_id, '_eventosapp_ticket_double_auth_mode', true );
    $tipo_fecha = get_post_meta( $event_id, '_eventosapp_tipo_fecha', true );
    $tipo_fecha = $tipo_fecha ? $tipo_fecha : 'unica';

    // Determinar el "día objetivo" para el envío actual
    $target_day = null;

    // Sólo aplicar lógica multi-día si auth_mode es "all_days" y NO es evento de día único
    if ( $auth_mode === 'all_days' && $tipo_fecha !== 'unica' && function_exists( 'eventosapp_get_event_days' ) ) {
        $event_days = eventosapp_get_event_days( $event_id );
        if ( ! empty( $event_days ) ) {
            // Obtener "hoy" en la zona horaria del evento
            $event_tz = get_post_meta( $event_id, '_eventosapp_zona_horaria', true );
            if ( ! $event_tz ) {
                $event_tz = wp_timezone_string();
                if ( ! $event_tz || $event_tz === 'UTC' ) {
                    $offset   = get_option( 'gmt_offset' );
                    $event_tz = $offset ? timezone_name_from_abbr( '', $offset * 3600, 0 ) ?: 'UTC' : 'UTC';
                }
            }

            try {
                $dt    = new DateTime( 'now', new DateTimeZone( $event_tz ) );
                $today = $dt->format( 'Y-m-d' );
            } catch ( Exception $e ) {
                $dt    = new DateTime( 'now', wp_timezone() );
                $today = $dt->format( 'Y-m-d' );
            }

            // Si hoy es un día del evento, usarlo como día objetivo
            if ( in_array( $today, $event_days, true ) ) {
                $target_day = $today;
            } else {
                // Si hoy NO es día del evento, usar el primer día del evento
                $target_day = $event_days[0];
            }
        }
    }

    // Obtener todos los tickets del evento
    $tickets = get_posts([
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_key'       => '_eventosapp_ticket_evento_id',
        'meta_value'     => $event_id,
    ]);

    $success = 0;
    $failed  = 0;

    foreach ( $tickets as $ticket ) {
        $sent = false;

        if ( $target_day && ! empty( $event_days ) ) {
            // ===== Evento multi-día en modo all_days =====
            // 1) Regenerar códigos para TODOS los días del evento
            $target_code = null;

            foreach ( $event_days as $day ) {
                $code_for_day = eventosapp_assign_auth_code_to_ticket_for_day( $ticket->ID, $day );
                if ( $day === $target_day ) {
                    $target_code = $code_for_day;
                }
            }

            // Seguridad: si por cualquier razón no se obtuvo código para el día objetivo
            if ( ! $target_code ) {
                $target_code = eventosapp_assign_auth_code_to_ticket_for_day( $ticket->ID, $target_day );
            }

            // 2) Sincronizar "día actual" y código general con el día objetivo
            update_post_meta( $ticket->ID, '_eventosapp_double_auth_current_day', $target_day );
            update_post_meta( $ticket->ID, '_eventosapp_double_auth_code', $target_code );
            update_post_meta( $ticket->ID, '_eventosapp_double_auth_code_date', current_time( 'timestamp' ) );

            // 3) Enviar correo usando la función específica por día (registra log por día)
            $sent = eventosapp_send_auth_code_email_for_day( $ticket->ID, $target_day, 'masivo' );
        } else {
            // ===== Evento de día único o modo first_day =====
            // Generar SIEMPRE un nuevo código general
            $new_code = eventosapp_assign_auth_code_to_ticket( $ticket->ID );

            // Enviar el nuevo código (registra log genérico en el ticket)
            $sent = eventosapp_send_auth_code_email( $ticket->ID, 'masivo' );
        }

        if ( $sent ) {
            $success++;
        } else {
            $failed++;
        }
    }

    $total = count( $tickets );

    // Registrar en el log del evento
    if ( $target_day ) {
        // Multi-día: guardar con información del día
        eventosapp_log_mass_send_for_day( $event_id, $target_day, $total, $success, $failed );
        
        // NUEVO: Marcar este día como enviado
        if (function_exists('eventosapp_mark_day_as_sent')) {
            eventosapp_mark_day_as_sent($event_id, $target_day);
        }
    } else {
        // Caso clásico
        eventosapp_log_mass_send( $event_id, $total, $success, $failed );
        
        // NUEVO: Para eventos de día único, marcar el primer día como enviado
        if (function_exists('eventosapp_get_event_days') && function_exists('eventosapp_mark_day_as_sent')) {
            $days = eventosapp_get_event_days($event_id);
            if (!empty($days)) {
                eventosapp_mark_day_as_sent($event_id, $days[0]);
            }
        }
    }

    return [
        'success' => $success,
        'failed'  => $failed,
        'total'   => $total,
    ];
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
    $event_id  = absint( $event_id );
    $timestamp = absint( $timestamp );

    if ( ! $event_id || ! $timestamp ) {
        return false;
    }

    // Cancelar cualquier evento programado anterior
    wp_clear_scheduled_hook( 'eventosapp_auto_send_auth_codes', [ $event_id ] );

    // Programar nuevo evento.
    // WP-Cron espera un Unix timestamp UTC real. No se debe pasar current_time('timestamp').
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
    // Detectar configuración del evento
    $auth_mode  = get_post_meta( $event_id, '_eventosapp_ticket_double_auth_mode', true );
    $tipo_fecha = get_post_meta( $event_id, '_eventosapp_tipo_fecha', true );
    $tipo_fecha = $tipo_fecha ? $tipo_fecha : 'unica';

    $event_days = [];
    if ( function_exists( 'eventosapp_get_event_days' ) ) {
        $event_days = (array) eventosapp_get_event_days( $event_id );
    }

    // Calcular día objetivo para multi-día (solo modo all_days)
    $target_day = null;
    if ( $auth_mode === 'all_days' && $tipo_fecha !== 'unica' && ! empty( $event_days ) ) {
        // Determinar "hoy" en la zona horaria del evento
        $event_tz = get_post_meta( $event_id, '_eventosapp_zona_horaria', true );
        if ( ! $event_tz ) {
            $event_tz = wp_timezone_string();
            if ( ! $event_tz || $event_tz === 'UTC' ) {
                $offset   = get_option( 'gmt_offset' );
                $event_tz = $offset ? timezone_name_from_abbr( '', $offset * 3600, 0 ) ?: 'UTC' : 'UTC';
            }
        }

        try {
            $dt    = new DateTime( 'now', new DateTimeZone( $event_tz ) );
            $today = $dt->format( 'Y-m-d' );
        } catch ( Exception $e ) {
            $dt    = new DateTime( 'now', wp_timezone() );
            $today = $dt->format( 'Y-m-d' );
        }

        if ( in_array( $today, $event_days, true ) ) {
            $target_day = $today;
        } else {
            // Si hoy no es un día del evento, usar el primer día del mismo
            $target_day = reset( $event_days );
        }
    }

    // Obtener todos los tickets del evento
    $tickets = get_posts( [
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_key'       => '_eventosapp_ticket_evento_id',
        'meta_value'     => $event_id,
    ] );

    $success = 0;
    $failed  = 0;

    foreach ( $tickets as $ticket ) {
        $sent = false;

        if ( $target_day && ! empty( $event_days ) ) {
            // ===== Evento multi-día en modo all_days =====
            // 1) Regenerar códigos para TODOS los días del evento
            $target_code = null;

            foreach ( $event_days as $day ) {
                $code_for_day = eventosapp_assign_auth_code_to_ticket_for_day( $ticket->ID, $day );
                if ( $day === $target_day ) {
                    $target_code = $code_for_day;
                }
            }

            // Seguridad: si por cualquier razón no se obtuvo código para el día objetivo
            if ( ! $target_code ) {
                $target_code = eventosapp_assign_auth_code_to_ticket_for_day( $ticket->ID, $target_day );
            }

            // 2) Sincronizar "día actual" y código general con el día objetivo
            update_post_meta( $ticket->ID, '_eventosapp_double_auth_current_day', $target_day );
            update_post_meta( $ticket->ID, '_eventosapp_double_auth_code', $target_code );
            update_post_meta( $ticket->ID, '_eventosapp_double_auth_code_date', current_time( 'timestamp' ) );

            // 3) Enviar correo usando la función específica por día (registra log por día)
            $sent = eventosapp_send_auth_code_email_for_day( $ticket->ID, $target_day, 'masivo' );
        } else {
            // ===== Evento de día único o modo first_day =====
            // Generar SIEMPRE un nuevo código general
            $new_code = eventosapp_assign_auth_code_to_ticket( $ticket->ID );

            // Enviar el nuevo código (registra log genérico en el ticket)
            $sent = eventosapp_send_auth_code_email( $ticket->ID, 'masivo' );
        }

        if ( $sent ) {
            $success++;
        } else {
            $failed++;
        }
    }

    $total = count( $tickets );

    // Registrar en el log del evento
    if ( $target_day ) {
        // Multi-día: guardar con información del día
        eventosapp_log_mass_send_for_day( $event_id, $target_day, $total, $success, $failed );
    } else {
        // Caso clásico
        eventosapp_log_mass_send( $event_id, $total, $success, $failed );
    }

    return [
        'success' => $success,
        'failed'  => $failed,
        'total'   => $total,
    ];
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
    
    $result = eventosapp_regenerate_and_send_mass_auth_codes( $event_id );
    
    wp_send_json_success( [
        'message' => sprintf( 
            'Códigos regenerados y enviados: %d exitosos, %d fallidos de %d total', 
            $result['success'], 
            $result['failed'], 
            $result['total'] 
        ),
        'result' => $result,
    ]);
});

// ========================================
// AJAX: ENVÍO MANUAL DESDE METABOX DE EVENTO
// ========================================

add_action( 'wp_ajax_eventosapp_test_send_auth_code', function() {
    check_ajax_referer( 'eventosapp_double_auth_test', 'nonce' );
    
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( 'Permisos insuficientes' );
    }
    
    $ticket_id_public = isset( $_POST['ticket_id'] ) ? sanitize_text_field( $_POST['ticket_id'] ) : '';
    
    if ( ! $ticket_id_public ) {
        wp_send_json_error( 'ID de ticket no proporcionado' );
    }
    
    // Buscar ticket por eventosapp_ticketID
    $tickets = get_posts([
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'meta_key'       => 'eventosapp_ticketID',
        'meta_value'     => $ticket_id_public,
    ]);
    
    if ( empty( $tickets ) ) {
        wp_send_json_error( 'Ticket no encontrado' );
    }
    
    $ticket = $tickets[0];
    
    // Generar y enviar código
    $code = eventosapp_assign_auth_code_to_ticket( $ticket->ID );
    $sent = eventosapp_send_auth_code_email( $ticket->ID, 'manual' );
    
    if ( $sent ) {
        wp_send_json_success( [
            'message' => sprintf( 'Código enviado exitosamente al ticket %s', $ticket_id_public ),
            'code'    => $code,
        ]);
    } else {
        wp_send_json_error( 'Error al enviar el email' );
    }
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
    
    $result = eventosapp_send_mass_auth_codes( $event_id );
    
    wp_send_json_success( [
        'message' => sprintf( 
            'Envío masivo completado: %d exitosos, %d fallidos de %d total', 
            $result['success'], 
            $result['failed'], 
            $result['total'] 
        ),
        'result' => $result,
    ]);
});

// ========================================
// AJAX: ENVÍO MANUAL DESDE METABOX DE TICKET
// ========================================

add_action( 'wp_ajax_eventosapp_send_single_auth_code', function() {
    check_ajax_referer( 'eventosapp_double_auth_single', 'nonce' );
    
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( 'Permisos insuficientes' );
    }
    
    $ticket_id = isset( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
    
    if ( ! $ticket_id ) {
        wp_send_json_error( 'ID de ticket no proporcionado' );
    }
    
    // Generar y enviar código
    $code = eventosapp_assign_auth_code_to_ticket( $ticket_id );
    $sent = eventosapp_send_auth_code_email( $ticket_id, 'manual' );
    
    if ( $sent ) {
        $email = get_post_meta( $ticket_id, '_eventosapp_asistente_email', true );
        wp_send_json_success( [
            'message' => sprintf( 'Código enviado exitosamente a %s', $email ),
            'code'    => $code,
        ]);
    } else {
        wp_send_json_error( 'Error al enviar el email' );
    }
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
    // Si no se especifica fecha, usar el primer día del evento
    if ( ! $date ) {
        $days = function_exists('eventosapp_get_event_days') 
            ? eventosapp_get_event_days($event_id) 
            : [];
        
        if ( empty($days) ) {
            return [
                'success' => 0,
                'failed' => 0,
                'total' => 0,
                'date' => null,
                'error' => 'No se encontraron días para el evento'
            ];
        }
        
        $date = $days[0];
    }
    
    // Obtener todos los tickets del evento
    $tickets = get_posts([
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_key'       => '_eventosapp_ticket_evento_id',
        'meta_value'     => $event_id,
    ]);
    
    $success = 0;
    $failed  = 0;
    
    foreach ( $tickets as $ticket ) {
        // Generar código para este día
        $code = eventosapp_assign_auth_code_to_ticket_for_day( $ticket->ID, $date );
        
        // Enviar email
        $sent = eventosapp_send_auth_code_email_for_day( $ticket->ID, $date, 'automatico' );
        
        if ( $sent ) {
            $success++;
        } else {
            $failed++;
        }
    }
    
    $total = count( $tickets );
    
    // Registrar en el log del evento
    eventosapp_log_mass_send_for_day( $event_id, $date, $total, $success, $failed );
    
    // NUEVO: Marcar este día como enviado
    if (function_exists('eventosapp_mark_day_as_sent')) {
        eventosapp_mark_day_as_sent($event_id, $date);
    }
    
    return [
        'success' => $success,
        'failed'  => $failed,
        'total'   => $total,
        'date'    => $date,
    ];
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
    // Obtener o generar el código para este día
    $code = eventosapp_get_ticket_auth_code_for_day( $ticket_id, $date );
    
    if ( ! $code ) {
        $code = eventosapp_assign_auth_code_to_ticket_for_day( $ticket_id, $date );
    }
    
    // Usar la función de envío estándar (que usa el código general)
    // Temporalmente guardamos el código del día como código general
    $original_code = get_post_meta( $ticket_id, '_eventosapp_double_auth_code', true );
    update_post_meta( $ticket_id, '_eventosapp_double_auth_code', $code );
    
    // Enviar email
    $sent = eventosapp_send_auth_code_email( $ticket_id, $method );
    
    // Restaurar código original
    if ( $original_code ) {
        update_post_meta( $ticket_id, '_eventosapp_double_auth_code', $original_code );
    }
    
    // Registrar en el log del ticket con información del día
    if ( $sent ) {
        eventosapp_log_ticket_send_for_day( $ticket_id, $date, $method );
    }
    
    return $sent;
}

/**
 * Registra un envío de código para un día específico en el log del ticket
 * 
 * @param int $ticket_id ID del ticket
 * @param string $date Fecha del día
 * @param string $method Método de envío
 */
function eventosapp_log_ticket_send_for_day( $ticket_id, $date, $method ) {
    $log = get_post_meta( $ticket_id, '_eventosapp_double_auth_send_log', true );
    
    if ( ! is_array( $log ) ) {
        $log = [];
    }
    
    $user_id = get_current_user_id();
    $user    = $user_id ? get_userdata( $user_id ) : null;
    
    // Agregar nuevo registro con información del día
    $log[] = [
        'timestamp' => current_time( 'timestamp' ),
        'method'    => $method,
        'user_id'   => $user_id,
        'user_name' => $user ? $user->display_name : 'Sistema',
        'day'       => $date, // NUEVO: registrar el día específico
    ];
    
    // Mantener solo los últimos 5 (aumentamos a 5 para eventos multi-día)
    if ( count( $log ) > 5 ) {
        $log = array_slice( $log, -5 );
    }
    
    update_post_meta( $ticket_id, '_eventosapp_double_auth_send_log', $log );
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
    
    // Generar y enviar código para este día
    $code = eventosapp_assign_auth_code_to_ticket_for_day( $ticket_id, $date );
    $sent = eventosapp_send_auth_code_email_for_day( $ticket_id, $date, 'manual' );
    
    if ( $sent ) {
        $email = get_post_meta( $ticket_id, '_eventosapp_asistente_email', true );
        $date_formatted = date_i18n( 'd/m/Y', strtotime($date) );
        wp_send_json_success( [
            'message' => sprintf( 'Código del %s enviado exitosamente a %s', $date_formatted, $email ),
            'code'    => $code,
        ]);
    } else {
        wp_send_json_error( 'Error al enviar el email' );
    }
});
