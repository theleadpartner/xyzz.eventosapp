<?php
/**
 * EventosApp – Galería IA Buscador de Fotos
 *
 * Handlers AJAX para el flujo frontend de búsqueda de fotos por IA:
 *   1. evapp_galeria_buscar_ticket      → Valida cédula + apellidos contra tickets del evento.
 *   2. evapp_galeria_registrar_foto     → Sube foto y crea/actualiza CPT asistente.
 *
 * Archivo: includes/frontend/eventosapp-galeria-ia-buscador.php
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// 1. AJAX: Buscar y validar ticket del asistente
//    Action: evapp_galeria_buscar_ticket
//    Público (nopriv) – acceso desde frontend de galería.
// ============================================================

add_action( 'wp_ajax_evapp_galeria_buscar_ticket',        'evapp_galeria_buscar_ticket_handler' );
add_action( 'wp_ajax_nopriv_evapp_galeria_buscar_ticket', 'evapp_galeria_buscar_ticket_handler' );

function evapp_galeria_buscar_ticket_handler() {

    check_ajax_referer( 'evapp_gi_buscar_ticket', 'security' );

    $galeria_id = absint( $_POST['galeria_id'] ?? 0 );
    $cedula     = sanitize_text_field( wp_unslash( $_POST['cedula']    ?? '' ) );
    $apellidos  = sanitize_text_field( wp_unslash( $_POST['apellidos'] ?? '' ) );

    if ( ! $galeria_id || ! $cedula || ! $apellidos ) {
        wp_send_json_error( [ 'error' => 'Datos incompletos. Ingresa tu número de identificación y apellidos.' ] );
    }

    // Obtener el evento asociado a la galería
    $post_galeria = get_post( $galeria_id );
    if ( ! $post_galeria || $post_galeria->post_type !== 'eventosapp_galeria' ) {
        wp_send_json_error( [ 'error' => 'Galería no válida.' ] );
    }

    $evento_id = (int) get_post_meta( $galeria_id, '_galeria_evento_id', true );
    if ( ! $evento_id ) {
        wp_send_json_error( [ 'error' => 'Esta galería no tiene un evento asociado.' ] );
    }

    // Buscar ticket por cédula + evento
    $ticket_id = false;
    if ( function_exists( 'evapp_find_ticket_by_cedula_evento' ) ) {
        $ticket_id = evapp_find_ticket_by_cedula_evento( $cedula, $evento_id );
    }

    // Fallback directo por meta si la función no está disponible
    if ( ! $ticket_id ) {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT pm.post_id
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key   = '_eventosapp_asistente_cc'
                AND pm.meta_value = %s
                AND p.post_type   = 'eventosapp_ticket'
                AND p.post_status != 'trash'",
            $cedula
        ) );
        if ( $ids ) {
            foreach ( $ids as $cand ) {
                if ( (int) get_post_meta( $cand, '_eventosapp_ticket_evento_id', true ) === $evento_id ) {
                    $ticket_id = (int) $cand;
                    break;
                }
            }
        }
    }

    if ( ! $ticket_id ) {
        wp_send_json_error( [ 'error' => 'No encontramos un asistente registrado con esos datos. Verifica tu número de identificación y apellidos tal como los ingresaste al inscribirte.' ] );
    }

    // Validar apellido (case-insensitive, coincidencia parcial en ambas direcciones)
    $apellido_ticket = strtolower( trim(
        get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true )
    ) );
    $apellido_buscar = strtolower( trim( $apellidos ) );

    $coincide = (
        strpos( $apellido_ticket, $apellido_buscar ) !== false ||
        strpos( $apellido_buscar, $apellido_ticket ) !== false ||
        similar_text( $apellido_ticket, $apellido_buscar ) >= ( max( strlen($apellido_ticket), strlen($apellido_buscar) ) * 0.7 )
    );

    if ( ! $coincide ) {
        wp_send_json_error( [ 'error' => 'Los datos ingresados no coinciden con nuestros registros. Verifica que los apellidos estén escritos correctamente.' ] );
    }

    // Obtener datos del ticket para mostrar al usuario
    $nombre   = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre',   true );
    $apellido = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );
    $email    = get_post_meta( $ticket_id, '_eventosapp_asistente_email',    true );
    $empresa  = get_post_meta( $ticket_id, '_eventosapp_asistente_empresa',  true );
    $cargo    = get_post_meta( $ticket_id, '_eventosapp_asistente_cargo',    true );

    wp_send_json_success( [
        'ticket_id'       => $ticket_id,
        'nombre_completo' => trim( $nombre . ' ' . $apellido ),
        'email'           => $email,
        'empresa'         => $empresa,
        'cargo'           => $cargo,
    ] );
}

// ============================================================
// 2. AJAX: Registrar foto y crear/actualizar CPT asistente
//    Action: evapp_galeria_registrar_foto
//    Público (nopriv) – acceso desde frontend de galería.
// ============================================================

add_action( 'wp_ajax_evapp_galeria_registrar_foto',        'evapp_galeria_registrar_foto_handler' );
add_action( 'wp_ajax_nopriv_evapp_galeria_registrar_foto', 'evapp_galeria_registrar_foto_handler' );

function evapp_galeria_registrar_foto_handler() {

    check_ajax_referer( 'evapp_gi_registrar_foto', 'security' );

    $galeria_id = absint( $_POST['galeria_id'] ?? 0 );
    $ticket_id  = absint( $_POST['ticket_id']  ?? 0 );
    $cedula     = sanitize_text_field( wp_unslash( $_POST['cedula']    ?? '' ) );
    $foto_data  = wp_unslash( $_POST['foto_data'] ?? '' );

    if ( ! $galeria_id || ! $ticket_id || ! $cedula || ! $foto_data ) {
        wp_send_json_error( [ 'error' => 'Datos incompletos para registrar la foto.' ] );
    }

    // Revalidar que el ticket pertenece al evento de esta galería
    $evento_id = (int) get_post_meta( $galeria_id, '_galeria_evento_id', true );
    if ( ! $evento_id ) {
        wp_send_json_error( [ 'error' => 'Galería sin evento asociado.' ] );
    }

    $ticket_evento = (int) get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true );
    if ( $ticket_evento !== $evento_id ) {
        wp_send_json_error( [ 'error' => 'El ticket no pertenece a este evento.' ] );
    }

    // Procesar imagen base64
    $foto_data_limpia = preg_replace( '#^data:image/\w+;base64,#i', '', $foto_data );
    $foto_binario     = base64_decode( $foto_data_limpia );

    if ( empty( $foto_binario ) || strlen( $foto_binario ) < 1000 ) {
        wp_send_json_error( [ 'error' => 'La imagen recibida no es válida. Por favor intenta de nuevo.' ] );
    }

    // Validar tamaño máximo (5 MB)
    if ( strlen( $foto_binario ) > 5 * 1024 * 1024 ) {
        wp_send_json_error( [ 'error' => 'La imagen es demasiado grande. Por favor usa una foto más pequeña o toma la foto directamente desde la cámara.' ] );
    }

    // Cargar funciones de medios de WordPress (necesarias en frontend)
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    // Subir imagen a wp-uploads
    $nombre_archivo = sanitize_file_name(
        'asistente-' . $cedula . '-' . time() . '.jpg'
    );
    $upload = wp_upload_bits( $nombre_archivo, null, $foto_binario );

    if ( ! empty( $upload['error'] ) ) {
        error_log( '[EventosApp GaleriaIA] Error upload foto: ' . $upload['error'] . ' | Cédula: ' . $cedula );
        wp_send_json_error( [ 'error' => 'Error al guardar la imagen en el servidor. Por favor intenta de nuevo.' ] );
    }

    // Crear attachment en WordPress Media Library
    $attachment_id = wp_insert_attachment(
        [
            'post_mime_type' => 'image/jpeg',
            'post_title'     => 'Foto Asistente – ' . $cedula,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ],
        $upload['file']
    );

    if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
        error_log( '[EventosApp GaleriaIA] Error al crear attachment para cédula: ' . $cedula );
        wp_send_json_error( [ 'error' => 'Error al procesar la imagen. Por favor intenta de nuevo.' ] );
    }

    // Generar metadatos del attachment (miniaturas, dimensiones, etc.)
    $metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
    wp_update_attachment_metadata( $attachment_id, $metadata );

    // ── Buscar CPT asistente existente por cédula ─────────────────────────
    $asistente_id = false;

    if ( function_exists( 'eventosapp_find_asistente_by_cedula' ) ) {
        $asistente_id = eventosapp_find_asistente_by_cedula( $cedula );
    }

    if ( ! $asistente_id && function_exists( 'evapp_find_asistente_by_cedula_local' ) ) {
        $asistente_id = evapp_find_asistente_by_cedula_local( $cedula );
    }

    if ( $asistente_id ) {
        // ── Asistente EXISTE: actualizar foto ────────────────────────────────
        update_post_meta( $asistente_id, '_asistente_foto_id', $attachment_id );
        error_log( "[EventosApp GaleriaIA] Foto actualizada en asistente ID:{$asistente_id} | Cédula: {$cedula} | Attachment: {$attachment_id}" );

    } else {
        // ── Asistente NO EXISTE: crear CPT desde datos del ticket ─────────────
        if ( function_exists( 'evapp_crear_asistente_desde_ticket' ) ) {
            $asistente_id = evapp_crear_asistente_desde_ticket( $ticket_id, $cedula );
        }

        if ( ! $asistente_id || is_wp_error( $asistente_id ) ) {
            // Creación manual como fallback
            $nombre   = sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_nombre',   true ) );
            $apellido = sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true ) );
            $titulo   = trim( $nombre . ' ' . $apellido ) ?: 'Asistente ' . $cedula;

            $asistente_id = wp_insert_post( [
                'post_type'   => 'eventosapp_asistente',
                'post_status' => 'publish',
                'post_title'  => $titulo,
            ] );

            if ( is_wp_error( $asistente_id ) || ! $asistente_id ) {
                // La foto ya fue subida; no bloquear al usuario por esto
                error_log( "[EventosApp GaleriaIA] No se pudo crear CPT asistente para cédula: {$cedula}" );
                wp_send_json_error( [ 'error' => 'Error al crear el perfil del asistente. Contacta al organizador del evento.' ] );
            }

            // Poblar campos mínimos
            update_post_meta( $asistente_id, '_asistente_cedula',    $cedula );
            update_post_meta( $asistente_id, '_asistente_nombres',   $nombre );
            update_post_meta( $asistente_id, '_asistente_apellidos', $apellido );
            update_post_meta( $asistente_id, '_asistente_email',     sanitize_email( get_post_meta( $ticket_id, '_eventosapp_asistente_email',   true ) ) );
            update_post_meta( $asistente_id, '_asistente_empresa',   sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_empresa', true ) ) );
            update_post_meta( $asistente_id, '_asistente_cargo',     sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_cargo',   true ) ) );
            update_post_meta( $asistente_id, '_asistente_telefono',  sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_tel',     true ) ) );
            update_post_meta( $asistente_id, '_asistente_ciudad',    sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_ciudad',  true ) ) );
            update_post_meta( $asistente_id, '_asistente_pais',      sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_pais',    true ) ) );
        }

        // Asignar la foto al asistente recién creado
        if ( $asistente_id && ! is_wp_error( $asistente_id ) ) {
            update_post_meta( $asistente_id, '_asistente_foto_id', $attachment_id );
            // Vincular ticket → asistente (bi-direccional)
            update_post_meta( $ticket_id,    '_eventosapp_ticket_asistente_cpt_id', $asistente_id );
            $asociados = get_post_meta( $asistente_id, '_asistente_tickets_asociados', true );
            if ( ! is_array( $asociados ) ) $asociados = [];
            if ( ! in_array( $ticket_id, $asociados, true ) ) {
                $asociados[] = $ticket_id;
                update_post_meta( $asistente_id, '_asistente_tickets_asociados', $asociados );
            }
            error_log( "[EventosApp GaleriaIA] Asistente creado ID:{$asistente_id} con foto ID:{$attachment_id} | Cédula: {$cedula}" );
        }
    }

    wp_send_json_success( [
        'asistente_id' => $asistente_id,
        'foto_id'      => $attachment_id,
        'mensaje'      => 'Foto registrada correctamente.',
    ] );
}
