<?php
/**
 * EventosApp – Coordinador compartido del warm-up QR online.
 *
 * Varias tablets pueden seleccionar el mismo evento casi al mismo tiempo. Este
 * coordinador evita que cada una reconstruya por separado los 5.000+ tickets:
 * todas avanzan sobre un cursor compartido por evento y un lock corto por lote.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'eventosapp_mobile_online_warm_state_key' ) ) {
    function eventosapp_mobile_online_warm_state_key( $event_id ) {
        return 'evapp_qrwarm_' . md5( (string) absint( $event_id ) );
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_warm_state' ) ) {
    function eventosapp_mobile_online_warm_state( $event_id, $value = null ) {
        $key = eventosapp_mobile_online_warm_state_key( $event_id );
        if ( is_array( $value ) ) {
            $value['db_version'] = EVENTOSAPP_MOBILE_ONLINE_LOOKUP_DB_VERSION;
            $value['updated_at'] = time();
            update_option( $key, $value, false );
            return $value;
        }
        if ( $value === false ) {
            delete_option( $key );
            return [];
        }

        $state = get_option( $key, [] );
        if ( ! is_array( $state ) || ( $state['db_version'] ?? '' ) !== EVENTOSAPP_MOBILE_ONLINE_LOOKUP_DB_VERSION ) {
            return [];
        }
        return $state;
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_reset_warm_state_on_mode_change' ) ) {
    function eventosapp_mobile_online_reset_warm_state_on_mode_change( $meta_id, $object_id, $meta_key, $meta_value = null ) {
        if (
            (string) $meta_key === '_eventosapp_ticket_use_preprinted_qr' &&
            get_post_type( absint( $object_id ) ) === 'eventosapp_event'
        ) {
            eventosapp_mobile_online_warm_state( absint( $object_id ), false );
        }
    }
}
add_action( 'added_post_meta', 'eventosapp_mobile_online_reset_warm_state_on_mode_change', 110, 4 );
add_action( 'updated_post_meta', 'eventosapp_mobile_online_reset_warm_state_on_mode_change', 110, 4 );
add_action( 'deleted_post_meta', 'eventosapp_mobile_online_reset_warm_state_on_mode_change', 110, 4 );

if ( ! function_exists( 'eventosapp_mobile_online_shared_warm_batch' ) ) {
    function eventosapp_mobile_online_shared_warm_batch( WP_REST_Request $request, $event_id, $user_id ) {
        global $wpdb;

        $event_id = absint( $event_id );
        $user_id  = absint( $user_id );
        $limit    = min( 500, max( 50, absint( $request->get_param( 'limit' ) ?: 500 ) ) );

        if ( ! eventosapp_mobile_online_lookup_table_ready() && ! eventosapp_mobile_online_install_lookup_table() ) {
            return new WP_Error( 'online_index_unavailable', 'No se pudo preparar el índice de lectura online.', [ 'status' => 500 ] );
        }

        $state = eventosapp_mobile_online_warm_state( $event_id );
        if ( ! empty( $state['complete'] ) ) {
            return rest_ensure_response( [
                'api_version' => EVENTOSAPP_MOBILE_ONLINE_PERFORMANCE_VERSION,
                'event_id'    => $event_id,
                'processed'   => 0,
                'keys'        => 0,
                'next_cursor' => absint( $state['cursor'] ?? 0 ),
                'complete'    => true,
                'busy'        => false,
                'shared'      => true,
            ] );
        }

        $lock = eventosapp_mobile_online_acquire_lock( 'warm', $event_id, 0, 'qr-index', 90 );
        if ( ! $lock ) {
            $state = eventosapp_mobile_online_warm_state( $event_id );
            return rest_ensure_response( [
                'api_version' => EVENTOSAPP_MOBILE_ONLINE_PERFORMANCE_VERSION,
                'event_id'    => $event_id,
                'processed'   => 0,
                'keys'        => 0,
                'next_cursor' => absint( $state['cursor'] ?? 0 ),
                'complete'    => ! empty( $state['complete'] ),
                'busy'        => true,
                'shared'      => true,
            ] );
        }

        try {
            // Releer después de tomar el lock: otra tablet pudo avanzar justo
            // antes de que esta request adquiriera exclusión.
            $state = eventosapp_mobile_online_warm_state( $event_id );
            if ( ! empty( $state['complete'] ) ) {
                return rest_ensure_response( [
                    'api_version' => EVENTOSAPP_MOBILE_ONLINE_PERFORMANCE_VERSION,
                    'event_id'    => $event_id,
                    'processed'   => 0,
                    'keys'        => 0,
                    'next_cursor' => absint( $state['cursor'] ?? 0 ),
                    'complete'    => true,
                    'busy'        => false,
                    'shared'      => true,
                ] );
            }

            $cursor = absint( $state['cursor'] ?? 0 );
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT p.ID
                   FROM {$wpdb->posts} p
                   INNER JOIN {$wpdb->postmeta} event_pm
                           ON event_pm.post_id=p.ID
                          AND event_pm.meta_key='_eventosapp_ticket_evento_id'
                          AND event_pm.meta_value=%s
                  WHERE p.post_type='eventosapp_ticket'
                    AND p.post_status IN ('publish','future','draft','pending','private')
                    AND p.ID>%d
                  ORDER BY p.ID ASC
                  LIMIT %d",
                (string) $event_id,
                $cursor,
                $limit
            ) );
            $ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );

            if ( $ids ) {
                update_meta_cache( 'post', $ids );
            }

            $has_bulk = function_exists( 'eventosapp_mobile_online_index_ticket_batch' );
            $keys = $has_bulk
                ? eventosapp_mobile_online_index_ticket_batch( $ids )
                : 0;
            if ( $ids && ! $has_bulk ) {
                foreach ( $ids as $ticket_id ) {
                    $keys += eventosapp_mobile_online_index_ticket( $ticket_id );
                }
            }

            if ( $keys < 0 ) {
                return new WP_Error(
                    'online_index_write_failed',
                    'EventosApp no pudo escribir el lote del índice QR. El progreso no se avanzó y puede reintentarse.',
                    [ 'status' => 500 ]
                );
            }

            $next_cursor = $ids ? max( $ids ) : $cursor;
            $complete = count( $ids ) < $limit;
            eventosapp_mobile_online_warm_state( $event_id, [
                'cursor'     => $next_cursor,
                'complete'   => $complete ? 1 : 0,
                'last_user'  => $user_id,
            ] );

            return rest_ensure_response( [
                'api_version' => EVENTOSAPP_MOBILE_ONLINE_PERFORMANCE_VERSION,
                'event_id'    => $event_id,
                'processed'   => count( $ids ),
                'keys'        => $keys,
                'next_cursor' => $next_cursor,
                'complete'    => $complete,
                'busy'        => false,
                'shared'      => true,
                'bulk'        => $has_bulk,
            ] );
        } finally {
            eventosapp_mobile_online_release_lock( $lock );
        }
    }
}

/**
 * Sustituye el callback del warm-up por el coordinador compartido. La ruta ya
 * está registrada por eventosapp-mobile-online-performance.php.
 */
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
    if ( $result !== null || ! $request instanceof WP_REST_Request ) return $result;
    if ( strtoupper( $request->get_method() ) !== 'POST' ) return $result;

    $route = $request->get_route();
    if ( ! preg_match( '#^/eventosapp-kiosk/v1/events/(\d+)/online-index/warm$#', $route, $matches ) ) {
        return $result;
    }

    $event_id = absint( $matches[1] );
    if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
        return new WP_Error( 'invalid_event', 'El evento no existe.', [ 'status' => 404 ] );
    }

    $user = function_exists( 'eventosapp_mobile_app_token_user' )
        ? eventosapp_mobile_app_token_user( $request )
        : null;
    if ( is_wp_error( $user ) ) return $user;
    if ( ! $user instanceof WP_User ) {
        return new WP_Error( 'unauthorized', 'La sesión móvil no es válida.', [ 'status' => 401 ] );
    }

    $modules = function_exists( 'eventosapp_mobile_event_offline_modules' )
        ? eventosapp_mobile_event_offline_modules( $event_id, $user->ID )
        : [];
    if ( empty( $modules ) ) {
        return new WP_Error( 'forbidden_event', 'No tienes módulos móviles autorizados para este evento.', [ 'status' => 403 ] );
    }

    return eventosapp_mobile_online_shared_warm_batch( $request, $event_id, $user->ID );
}, 11, 3 );
