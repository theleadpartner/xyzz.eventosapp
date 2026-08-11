<?php
/** EventosApp – API móvil de Consumo de Consumibles (Android 2.11.0+). */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'EVENTOSAPP_MOBILE_CONSUMABLES_API_VERSION' ) ) {
    define( 'EVENTOSAPP_MOBILE_CONSUMABLES_API_VERSION', '1.0.0' );
}

function eventosapp_mobile_consumables_user_can_event( $event_id, $user_id = 0 ) {
    $event_id = absint( $event_id );
    $user_id = absint( $user_id ?: get_current_user_id() );
    if ( ! $event_id || ! $user_id || get_post_type( $event_id ) !== 'eventosapp_event' ) return false;
    if ( ! function_exists( 'eventosapp_consumables_is_enabled' ) || ! eventosapp_consumables_is_enabled( $event_id ) ) return false;
    if ( function_exists( 'eventosapp_consumables_user_can_feature' ) ) {
        return (bool) eventosapp_consumables_user_can_feature( $event_id, $user_id, 'consumables_staff' );
    }
    return function_exists( 'eventosapp_mobile_app_user_can_feature_in_event' )
        ? (bool) eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user_id, 'consumables_staff' ) : false;
}

function eventosapp_mobile_consumables_event_ids( $user_id = 0 ) {
    $user_id = absint( $user_id ?: get_current_user_id() );
    if ( ! $user_id || ! function_exists( 'eventosapp_mobile_app_all_event_ids' ) ) return [];
    $ids = [];
    foreach ( eventosapp_mobile_app_all_event_ids() as $event_id ) {
        if ( eventosapp_mobile_consumables_user_can_event( $event_id, $user_id ) ) $ids[] = absint( $event_id );
    }
    return array_values( array_unique( $ids ) );
}

function eventosapp_mobile_consumables_items_payload( $event_id ) {
    $out = [];
    if ( ! function_exists( 'eventosapp_consumables_get_event_items' ) ) return $out;
    foreach ( (array) eventosapp_consumables_get_event_items( absint( $event_id ) ) as $item ) {
        $id = sanitize_key( $item['id'] ?? '' );
        $name = sanitize_text_field( $item['name'] ?? '' );
        if ( $id && $name ) $out[] = [ 'id' => $id, 'name' => $name ];
    }
    return $out;
}

function eventosapp_mobile_consumables_attendee_payload( $ticket_id, $event_id ) {
    if ( function_exists( 'eventosapp_mobile_advanced_attendee_payload' ) ) {
        $payload = eventosapp_mobile_advanced_attendee_payload( $ticket_id, $event_id );
        if ( is_array( $payload ) && ! empty( $payload['attendee'] ) ) return (array) $payload['attendee'];
    }
    if ( function_exists( 'eventosapp_mobile_offline_ticket_payload' ) ) {
        $payload = eventosapp_mobile_offline_ticket_payload( $ticket_id, $event_id );
        if ( is_array( $payload ) && ! empty( $payload['attendee'] ) ) return (array) $payload['attendee'];
    }
    $first = sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true ) );
    $last = sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true ) );
    return [
        'ticket_id' => absint( $ticket_id ), 'event_id' => absint( $event_id ),
        'first_name' => $first, 'last_name' => $last, 'full_name' => trim( "$first $last" ),
        'company' => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_empresa', true ) ),
        'designation' => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_cargo', true ) ),
        'identification' => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_cedula', true ) ),
        'email' => sanitize_email( get_post_meta( $ticket_id, '_eventosapp_asistente_email', true ) ),
        'phone' => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_telefono', true ) ),
        'localidad' => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_localidad', true ) ),
        'modalidad' => 'presencial', 'modalidad_label' => 'Presencial',
    ];
}

function eventosapp_mobile_consumables_ticket_payload( $ticket_id, $event_id ) {
    $ticket_id = absint( $ticket_id ); $event_id = absint( $event_id );
    if ( ! $ticket_id || absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) !== $event_id ) return [];
    $keys = function_exists( 'eventosapp_mobile_offline_ticket_lookup_keys' )
        ? eventosapp_mobile_offline_ticket_lookup_keys( $ticket_id, $event_id ) : [];
    $inventory = function_exists( 'eventosapp_consumables_get_ticket_inventory_snapshot' )
        ? eventosapp_consumables_get_ticket_inventory_snapshot( $ticket_id, $event_id ) : [];
    return [
        'ticket_id' => $ticket_id,
        'ticket_public_id' => sanitize_text_field( get_post_meta( $ticket_id, 'eventosapp_ticketID', true ) ),
        'attendee' => eventosapp_mobile_consumables_attendee_payload( $ticket_id, $event_id ),
        'lookup_keys' => $keys,
        'inventory' => is_array( $inventory ) ? $inventory : [],
    ];
}

function eventosapp_mobile_consumables_transaction_payload( $tx, $user_id = 0 ) {
    $user_id = absint( $user_id ?: get_current_user_id() );
    $owner_id = absint( $tx['staff_user_id'] ?? 0 );
    $ticket = is_array( $tx['ticket'] ?? null ) ? $tx['ticket'] : [];
    $items = [];
    foreach ( (array) ( $tx['items'] ?? [] ) as $item ) {
        $items[] = [ 'item_id' => sanitize_key( $item['item_id'] ?? '' ), 'item_name' => sanitize_text_field( $item['item_name'] ?? '' ), 'quantity' => absint( $item['quantity'] ?? 0 ) ];
    }
    $status = sanitize_key( $tx['status'] ?? 'active' );
    return [
        'batch_id' => sanitize_text_field( $tx['batch_id'] ?? '' ), 'event_id' => absint( $tx['event_id'] ?? 0 ),
        'ticket_id' => absint( $tx['ticket_id'] ?? 0 ), 'ticket_public_id' => sanitize_text_field( $ticket['public_id'] ?? '' ),
        'attendee_name' => sanitize_text_field( $ticket['nombre_completo'] ?? 'Asistente' ),
        'identification' => sanitize_text_field( $ticket['cedula'] ?? '' ), 'localidad' => sanitize_text_field( $ticket['localidad'] ?? '' ),
        'items' => $items, 'total_quantity' => absint( $tx['total_quantity'] ?? 0 ), 'status' => $status,
        'status_label' => sanitize_text_field( $tx['status_label'] ?? '' ), 'created_at' => sanitize_text_field( $tx['created_at'] ?? '' ),
        'request_at' => sanitize_text_field( $tx['request_at'] ?? '' ),
        'can_request_cancellation' => $owner_id === $user_id && $status === 'active',
    ];
}

function eventosapp_mobile_consumables_transactions_for_user( $event_id, $user_id = 0, $limit = 300 ) {
    $user_id = absint( $user_id ?: get_current_user_id() ); $out = [];
    if ( ! function_exists( 'eventosapp_consumables_tx_load_event_transactions' ) ) return $out;
    foreach ( (array) eventosapp_consumables_tx_load_event_transactions( absint( $event_id ) ) as $tx ) {
        if ( absint( $tx['staff_user_id'] ?? 0 ) !== $user_id ) continue;
        $out[] = eventosapp_mobile_consumables_transaction_payload( $tx, $user_id );
        if ( count( $out ) >= max( 1, absint( $limit ) ) ) break;
    }
    return $out;
}

function eventosapp_mobile_consumables_require_event( $event_id, $user_id = 0 ) {
    $event_id = absint( $event_id ); $user_id = absint( $user_id ?: get_current_user_id() );
    if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) return new WP_Error( 'invalid_event', 'El evento no existe.', [ 'status' => 404 ] );
    if ( ! eventosapp_mobile_consumables_user_can_event( $event_id, $user_id ) ) return new WP_Error( 'forbidden_event', 'No tienes permiso para consumir consumibles en este evento.', [ 'status' => 403 ] );
    return true;
}

function eventosapp_mobile_consumables_selection( $raw ) {
    if ( function_exists( 'eventosapp_consumables_sanitize_selection' ) ) return eventosapp_consumables_sanitize_selection( is_array( $raw ) ? $raw : [] );
    $out = []; foreach ( (array) $raw as $id => $qty ) { $id = sanitize_key( $id ); $qty = absint( $qty ); if ( $id && $qty ) $out[$id] = $qty; } return $out;
}

function eventosapp_mobile_consumables_request_id( $value = '' ) {
    $value = preg_replace( '/[^A-Za-z0-9._-]/', '', sanitize_text_field( (string) $value ) );
    return $value ? substr( $value, 0, 64 ) : wp_generate_uuid4();
}

function eventosapp_mobile_consumables_events( WP_REST_Request $request ) {
    $events = [];
    foreach ( eventosapp_mobile_consumables_event_ids() as $event_id ) {
        $event = function_exists( 'eventosapp_mobile_app_event_payload' ) ? eventosapp_mobile_app_event_payload( $event_id, 'consumables_staff' ) : [ 'id' => $event_id, 'title' => get_the_title( $event_id ) ];
        $event['items'] = eventosapp_mobile_consumables_items_payload( $event_id ); $events[] = $event;
    }
    return rest_ensure_response( [ 'api_version' => EVENTOSAPP_MOBILE_CONSUMABLES_API_VERSION, 'events' => $events ] );
}

function eventosapp_mobile_consumables_consume( WP_REST_Request $request ) {
    $event_id = absint( $request['event_id'] ); $user_id = get_current_user_id();
    $allowed = eventosapp_mobile_consumables_require_event( $event_id, $user_id ); if ( is_wp_error( $allowed ) ) return $allowed;
    $selection = eventosapp_mobile_consumables_selection( $request->get_param( 'items' ) );
    if ( ! $selection ) return new WP_Error( 'missing_items', 'Selecciona al menos un consumible y define su cantidad.', [ 'status' => 400 ] );
    $scanned = trim( sanitize_text_field( (string) $request->get_param( 'scanned' ) ) );
    if ( ! $scanned ) return new WP_Error( 'missing_qr', 'No se recibió un código QR válido.', [ 'status' => 400 ] );
    if ( ! function_exists( 'eventosapp_consumables_find_ticket_from_qr' ) || ! function_exists( 'eventosapp_consumables_consume_items' ) ) return new WP_Error( 'api_dependency_missing', 'El motor de consumibles no está disponible.', [ 'status' => 500 ] );
    $lookup = eventosapp_consumables_find_ticket_from_qr( $scanned, $event_id );
    if ( is_wp_error( $lookup ) ) return new WP_Error( $lookup->get_error_code(), $lookup->get_error_message(), [ 'status' => 400 ] );
    $ticket_id = absint( $lookup['ticket_id'] ?? 0 ); $batch_id = eventosapp_mobile_consumables_request_id( $request->get_param( 'client_id' ) );
    $result = eventosapp_consumables_consume_items( $event_id, $ticket_id, $selection, $user_id, $batch_id, sanitize_key( $lookup['type'] ?? 'qr' ) );
    if ( is_wp_error( $result ) ) return new WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 409 ] );
    $inventory = eventosapp_consumables_get_ticket_inventory_snapshot( $ticket_id, $event_id );
    return rest_ensure_response( [
        'event_id' => $event_id, 'event_name' => get_the_title( $event_id ), 'ticket_id' => $ticket_id,
        'attendee' => eventosapp_mobile_consumables_attendee_payload( $ticket_id, $event_id ), 'batch_id' => $batch_id,
        'consumptions' => array_values( (array) ( $result['items'] ?? [] ) ), 'inventory' => $inventory,
        'qr_type' => sanitize_key( $lookup['type'] ?? 'qr' ), 'qr_type_label' => sanitize_text_field( $lookup['type_label'] ?? 'QR' ),
        'duplicate' => ! empty( $result['duplicate'] ), 'message' => ! empty( $result['duplicate'] ) ? 'La transacción ya estaba registrada.' : 'Consumo registrado correctamente.',
    ] );
}

function eventosapp_mobile_consumables_transactions( WP_REST_Request $request ) {
    $event_id = absint( $request['event_id'] ); $allowed = eventosapp_mobile_consumables_require_event( $event_id ); if ( is_wp_error( $allowed ) ) return $allowed;
    return rest_ensure_response( [ 'event_id' => $event_id, 'transactions' => eventosapp_mobile_consumables_transactions_for_user( $event_id ) ] );
}

function eventosapp_mobile_consumables_cancel_request( WP_REST_Request $request ) {
    $event_id = absint( $request['event_id'] ); $allowed = eventosapp_mobile_consumables_require_event( $event_id ); if ( is_wp_error( $allowed ) ) return $allowed;
    $batch_id = sanitize_text_field( (string) $request->get_param( 'batch_id' ) );
    if ( ! function_exists( 'eventosapp_consumables_tx_request_cancel' ) ) return new WP_Error( 'api_dependency_missing', 'La auditoría de consumibles no está disponible.', [ 'status' => 500 ] );
    $result = eventosapp_consumables_tx_request_cancel( $event_id, $batch_id, get_current_user_id() );
    if ( is_wp_error( $result ) ) return new WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 409 ] );
    return rest_ensure_response( [ 'accepted' => true, 'already' => ! empty( $result['duplicate'] ), 'batch_id' => $batch_id, 'message' => ! empty( $result['duplicate'] ) ? 'La cancelación ya había sido solicitada.' : 'Solicitud de cancelación enviada al administrador.' ] );
}

function eventosapp_mobile_consumables_offline_sync( WP_REST_Request $request ) {
    $event_id = absint( $request['event_id'] ); $allowed = eventosapp_mobile_consumables_require_event( $event_id ); if ( is_wp_error( $allowed ) ) return $allowed;
    $raw_items = $request->get_param( 'items' ); if ( ! is_array( $raw_items ) ) $raw_items = [];
    $ordered = array_merge( array_filter( $raw_items, fn($x) => is_array($x) && sanitize_key($x['operation'] ?? 'consume') !== 'cancel_request' ), array_filter( $raw_items, fn($x) => is_array($x) && sanitize_key($x['operation'] ?? '') === 'cancel_request' ) );
    $results = [];
    foreach ( $ordered as $raw ) {
        $operation = sanitize_key( $raw['operation'] ?? 'consume' ); $client_id = eventosapp_mobile_consumables_request_id( $raw['client_id'] ?? '' );
        if ( $operation === 'cancel_request' ) {
            $batch_id = sanitize_text_field( $raw['target_batch'] ?? '' );
            $r = function_exists( 'eventosapp_consumables_tx_request_cancel' ) ? eventosapp_consumables_tx_request_cancel( $event_id, $batch_id, get_current_user_id() ) : new WP_Error( 'missing_dependency', 'La auditoría no está disponible.' );
            $results[] = is_wp_error($r) ? [ 'client_id'=>$client_id,'operation'=>$operation,'accepted'=>false,'already'=>false,'message'=>$r->get_error_message() ] : [ 'client_id'=>$client_id,'operation'=>$operation,'target_batch'=>$batch_id,'accepted'=>true,'already'=>!empty($r['duplicate']),'message'=>'Solicitud de cancelación sincronizada.' ];
            continue;
        }
        $ticket_id = absint( $raw['ticket_id'] ?? 0 ); $selection = eventosapp_mobile_consumables_selection( $raw['items'] ?? [] );
        if ( ! $ticket_id || ! $selection || absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) !== $event_id ) { $results[] = [ 'client_id'=>$client_id,'operation'=>'consume','accepted'=>false,'already'=>false,'message'=>'Operación offline inválida.' ]; continue; }
        $r = eventosapp_consumables_consume_items( $event_id, $ticket_id, $selection, get_current_user_id(), $client_id, sanitize_key( $raw['qr_type'] ?? 'offline' ) );
        $results[] = is_wp_error($r) ? [ 'client_id'=>$client_id,'operation'=>'consume','accepted'=>false,'already'=>false,'message'=>$r->get_error_message() ] : [ 'client_id'=>$client_id,'operation'=>'consume','batch_id'=>$client_id,'accepted'=>true,'already'=>!empty($r['duplicate']),'message'=>'Consumo offline sincronizado.' ];
    }
    return rest_ensure_response( [ 'results' => array_values( $results ) ] );
}

function eventosapp_mobile_consumables_offline_config_payload( $event_id, $user_id = 0 ) {
    return [ 'api_version' => EVENTOSAPP_MOBILE_CONSUMABLES_API_VERSION, 'items' => eventosapp_mobile_consumables_items_payload( $event_id ), 'transactions' => eventosapp_mobile_consumables_transactions_for_user( $event_id, $user_id ) ];
}

add_action( 'rest_api_init', function () {
    $permission = function_exists( 'eventosapp_mobile_app_permission' ) ? 'eventosapp_mobile_app_permission' : '__return_false';
    register_rest_route( 'eventosapp-kiosk/v1', '/mobile/consumables/events', [ 'methods'=>WP_REST_Server::READABLE, 'callback'=>'eventosapp_mobile_consumables_events', 'permission_callback'=>$permission ] );
    foreach ( [ 'consume'=>'eventosapp_mobile_consumables_consume', 'cancel-request'=>'eventosapp_mobile_consumables_cancel_request', 'offline-sync'=>'eventosapp_mobile_consumables_offline_sync' ] as $route => $callback ) {
        register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\d+)/consumables/' . $route, [ 'methods'=>WP_REST_Server::CREATABLE, 'callback'=>$callback, 'permission_callback'=>$permission, 'args'=>[ 'event_id'=>[ 'required'=>true, 'sanitize_callback'=>'absint' ] ] ] );
    }
    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\d+)/consumables/transactions', [ 'methods'=>WP_REST_Server::READABLE, 'callback'=>'eventosapp_mobile_consumables_transactions', 'permission_callback'=>$permission, 'args'=>[ 'event_id'=>[ 'required'=>true, 'sanitize_callback'=>'absint' ] ] ] );
} );
