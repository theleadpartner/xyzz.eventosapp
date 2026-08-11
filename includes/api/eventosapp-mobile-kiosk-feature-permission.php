<?php
/**
 * EventosApp – Contexto de permisos por evento para las rutas Kiosko Android.
 *
 * Instalar junto con eventosapp-mobile-staff-checkin-api.php y cargar después.
 *
 * La API base del Kiosko conserva un permission_callback histórico que consulta
 * eventosapp_role_can('self_checkin') sin recibir el ID del evento. Este archivo
 * establece el contexto del evento solicitado antes del callback y obliga a que
 * la respuesta respete el permiso efectivo por usuario y evento.
 *
 * Bootstrap móvil actual:
 * Kiosko base -> Staff QR -> contexto Kiosko -> offline Staff -> offline Kiosko
 * -> QR avanzados -> paquete offline unificado.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'eventosapp_mobile_kiosk_feature_context' ) ) {
    function eventosapp_mobile_kiosk_feature_context( $value = null ) {
        static $context = [];
        if ( is_array( $value ) ) {
            $context = $value;
        } elseif ( $value === false ) {
            $context = [];
        }
        return $context;
    }
}

add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
    if ( $result !== null || ! $request instanceof WP_REST_Request ) {
        return $result;
    }

    $route = $request->get_route();
    $event_id = 0;

    if ( preg_match( '#^/eventosapp-kiosk/v1/events/(\d+)(?:/search)?$#', $route, $matches ) ) {
        $event_id = absint( $matches[1] );
    } elseif ( preg_match( '#^/eventosapp-kiosk/v1/tickets/(\d+)(?:/print)?$#', $route, $matches ) ) {
        $ticket_id = absint( $matches[1] );
        $event_id  = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    }

    if ( ! $event_id ) {
        return $result;
    }

    if ( ! function_exists( 'eventosapp_mobile_app_token_user' ) ||
         ! function_exists( 'eventosapp_mobile_app_user_can_feature_in_event' ) ) {
        return new WP_Error(
            'api_dependency_missing',
            'La extensión móvil de EventosApp no está cargada correctamente.',
            [ 'status' => 500 ]
        );
    }

    $user = eventosapp_mobile_app_token_user( $request );
    if ( is_wp_error( $user ) ) {
        return $user;
    }

    $allowed = eventosapp_mobile_app_user_can_feature_in_event(
        $event_id,
        $user->ID,
        'self_checkin'
    );

    eventosapp_mobile_kiosk_feature_context( [
        'event_id' => $event_id,
        'user_id'  => absint( $user->ID ),
        'allowed'  => (bool) $allowed,
    ] );

    if ( ! $allowed ) {
        return new WP_Error(
            'forbidden_event',
            'No tienes permiso de Autogestión del Asistente para este evento.',
            [ 'status' => 403 ]
        );
    }

    return $result;
}, 7, 3 );

add_filter( 'eventosapp_role_can', function ( $has_permission, $feature, $user ) {
    if ( sanitize_key( $feature ) !== 'self_checkin' ) {
        return $has_permission;
    }

    $context = eventosapp_mobile_kiosk_feature_context();
    if ( empty( $context['event_id'] ) || empty( $context['user_id'] ) ) {
        return $has_permission;
    }

    if ( ! $user instanceof WP_User || absint( $user->ID ) !== absint( $context['user_id'] ) ) {
        return false;
    }

    return ! empty( $context['allowed'] );
}, 30000, 3 );

add_filter( 'rest_request_after_callbacks', function ( $response, $handler, $request ) {
    eventosapp_mobile_kiosk_feature_context( false );
    return $response;
}, 30000, 3 );

// Android 2.7.0+: snapshot y sincronización offline Staff.
$eventosapp_mobile_offline_api = __DIR__ . '/eventosapp-mobile-staff-offline-api.php';
if ( is_readable( $eventosapp_mobile_offline_api ) ) {
    require_once $eventosapp_mobile_offline_api;
}
unset( $eventosapp_mobile_offline_api );

// Android 2.8.0+: snapshot autocontenido y sincronización offline del Kiosko.
$eventosapp_mobile_kiosk_offline_api = __DIR__ . '/eventosapp-mobile-kiosk-offline-api.php';
if ( is_readable( $eventosapp_mobile_kiosk_offline_api ) ) {
    require_once $eventosapp_mobile_kiosk_offline_api;
}
unset( $eventosapp_mobile_kiosk_offline_api );

// Android 2.10.0+: Localidad, Sesiones y Doble Autenticación, incluidos sus
// endpoints online y la sincronización offline compartida.
$eventosapp_mobile_advanced_qr_api = __DIR__ . '/eventosapp-mobile-advanced-qr-api.php';
if ( is_readable( $eventosapp_mobile_advanced_qr_api ) ) {
    require_once $eventosapp_mobile_advanced_qr_api;
}
unset( $eventosapp_mobile_advanced_qr_api );

// Android 2.9.1+: una sola descarga paginada por evento. Desde 2.10.0 incluye
// también el bloque compartido advanced_qr para los tres lectores adicionales.
$eventosapp_mobile_event_offline_api = __DIR__ . '/eventosapp-mobile-event-offline-api.php';
if ( is_readable( $eventosapp_mobile_event_offline_api ) ) {
    require_once $eventosapp_mobile_event_offline_api;
}
unset( $eventosapp_mobile_event_offline_api );
