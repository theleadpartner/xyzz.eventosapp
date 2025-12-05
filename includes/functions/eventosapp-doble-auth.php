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
 * Valida si un código ingresado coincide con el asignado al ticket
 * 
 * @param int $ticket_id ID del ticket
 * @param string $input_code Código ingresado por el usuario
 * @return bool True si coincide, false si no
 */
function eventosapp_validate_auth_code( $ticket_id, $input_code ) {
    $stored_code = eventosapp_get_ticket_auth_code( $ticket_id );
    
    if ( ! $stored_code ) {
        return false;
    }
    
    // Normalizar: quitar espacios y convertir a string
    $input_code  = trim( (string) $input_code );
    $stored_code = trim( (string) $stored_code );
    
    return $input_code === $stored_code;
}

// ========================================
// ENVÍO DE CÓDIGOS POR EMAIL
// ========================================

/**
 * Envía el código de doble autenticación al asistente por email
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
    $code        = eventosapp_get_ticket_auth_code( $ticket_id );
    
    // Si no existe código, generar uno nuevo
    if ( ! $code ) {
        $code = eventosapp_assign_auth_code_to_ticket( $ticket_id );
    }
    
    $nombre_completo = trim( $nombre . ' ' . $apellido );
    
    // Construir el email
    $subject = sprintf( 'Código de Verificación - %s', $event_title );
    
    $message = sprintf(
        "Hola %s,\n\n" .
        "Has recibido este mensaje porque estás registrado para el evento:\n" .
        "📅 %s\n\n" .
        "Para completar tu check-in en el evento, necesitarás presentar el siguiente código de verificación:\n\n" .
        "🔐 CÓDIGO: %s\n\n" .
        "⚠️ IMPORTANTE:\n" .
        "• Este código es personal e intransferible\n" .
        "• Preséntalo junto a tu ticket para ingresar al evento\n" .
        "• NO compartas este código con nadie\n\n" .
        "Nos vemos en el evento,\n" .
        "Equipo de %s",
        $nombre_completo,
        $event_title,
        $code,
        get_bloginfo( 'name' )
    );
    
    // Enviar email
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    $sent    = wp_mail( $email, $subject, $message, $headers );
    
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
 * Envía códigos a todos los tickets de un evento
 * 
 * @param int $event_id ID del evento
 * @return array Resultados: ['success' => int, 'failed' => int, 'total' => int]
 */
function eventosapp_send_mass_auth_codes( $event_id ) {
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
        $sent = eventosapp_send_auth_code_email( $ticket->ID, 'masivo' );
        
        if ( $sent ) {
            $success++;
        } else {
            $failed++;
        }
    }
    
    $total = count( $tickets );
    
    // Registrar en el log del evento
    eventosapp_log_mass_send( $event_id, $total, $success, $failed );
    
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
 */
function eventosapp_log_mass_send( $event_id, $total, $success, $failed ) {
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
    // Cancelar cualquier evento programado anterior
    wp_clear_scheduled_hook( 'eventosapp_auto_send_auth_codes', [ $event_id ] );
    
    // Programar nuevo evento
    wp_schedule_single_event( $timestamp, 'eventosapp_auto_send_auth_codes', [ $event_id ] );
}

/**
 * Hook para envío automático programado
 */
add_action( 'eventosapp_auto_send_auth_codes', function( $event_id ) {
    eventosapp_send_mass_auth_codes( $event_id );
}, 10, 1 );

/**
 * Regenera códigos para todos los tickets de un evento y los envía
 * ATENCIÓN: Esta función BORRA todos los códigos existentes
 * 
 * @param int $event_id ID del evento
 * @return array Resultados: ['success' => int, 'failed' => int, 'total' => int]
 */
function eventosapp_regenerate_and_send_mass_auth_codes( $event_id ) {
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
        // IMPORTANTE: Generar un NUEVO código (esto sobrescribe el anterior)
        $new_code = eventosapp_assign_auth_code_to_ticket( $ticket->ID );
        
        // Enviar el nuevo código
        $sent = eventosapp_send_auth_code_email( $ticket->ID, 'masivo' );
        
        if ( $sent ) {
            $success++;
        } else {
            $failed++;
        }
    }
    
    $total = count( $tickets );
    
    // Registrar en el log del evento (con indicador de regeneración)
    eventosapp_log_mass_send( $event_id, $total, $success, $failed );
    
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
