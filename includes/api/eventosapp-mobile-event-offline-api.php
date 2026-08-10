<?php
/**
 * EventosApp – Paquete offline móvil unificado por evento (Android 2.9.1+).
 *
 * Objetivo:
 * - una sola descarga paginada por evento;
 * - una sola consulta de tickets por página;
 * - payloads específicos únicamente para los módulos autorizados;
 * - estructura extensible para futuros módulos móviles;
 * - compatibilidad total con las rutas Staff 2.7.0 y Kiosko 2.8.0.
 *
 * Ruta:
 * GET /eventosapp-kiosk/v1/events/{event_id}/offline-package
 *
 * Debe cargarse después de:
 * - eventosapp-mobile-staff-offline-api.php
 * - eventosapp-mobile-kiosk-offline-api.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'EVENTOSAPP_MOBILE_EVENT_OFFLINE_API_VERSION' ) ) {
    define( 'EVENTOSAPP_MOBILE_EVENT_OFFLINE_API_VERSION', '1.0.0' );
}

if ( ! function_exists( 'eventosapp_mobile_event_offline_modules' ) ) {
    /**
     * Devuelve exclusivamente módulos que el usuario puede utilizar en el evento.
     * Esta lista es la fuente de verdad del paquete y puede crecer sin cambiar el
     * contrato base de Android.
     */
    function eventosapp_mobile_event_offline_modules( $event_id, $user_id = 0 ) {
        $event_id = absint( $event_id );
        $user_id  = absint( $user_id ?: get_current_user_id() );
        if ( ! $event_id || ! $user_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return [];
        }

        $modules = [];

        if (
            function_exists( 'eventosapp_mobile_app_user_can_feature_in_event' ) &&
            eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user_id, 'qr' )
        ) {
            $modules[] = 'staff_qr';
        }

        if (
            function_exists( 'eventosapp_mobile_kiosk_offline_user_can_event' ) &&
            eventosapp_mobile_kiosk_offline_user_can_event( $event_id, $user_id )
        ) {
            $modules[] = 'kiosk';
        }

        return array_values( array_unique( $modules ) );
    }
}

if ( ! function_exists( 'eventosapp_mobile_event_offline_event_payload' ) ) {
    function eventosapp_mobile_event_offline_event_payload( $event_id, $modules, $kiosk_config = null ) {
        $event_id = absint( $event_id );

        if ( function_exists( 'eventosapp_mobile_app_event_payload' ) ) {
            $payload = eventosapp_mobile_app_event_payload( $event_id, 'mobile_offline' );
            if ( is_array( $payload ) && ! empty( $payload['id'] ) ) {
                return $payload;
            }
        }

        if (
            in_array( 'kiosk', (array) $modules, true ) &&
            is_array( $kiosk_config ) &&
            ! empty( $kiosk_config['event'] ) &&
            is_array( $kiosk_config['event'] )
        ) {
            return $kiosk_config['event'];
        }

        $days = function_exists( 'eventosapp_get_event_days' )
            ? array_values( (array) eventosapp_get_event_days( $event_id ) )
            : [];

        return [
            'id'        => $event_id,
            'title'     => sanitize_text_field( get_the_title( $event_id ) ),
            'status'    => sanitize_key( get_post_status( $event_id ) ?: '' ),
            'days'      => $days,
            'first_day' => $days ? reset( $days ) : '',
            'last_day'  => $days ? end( $days ) : '',
            'logo_url'  => '',
            'modalidad' => function_exists( 'eventosapp_get_event_modalidad' )
                ? eventosapp_get_event_modalidad( $event_id )
                : 'presencial',
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_event_offline_package' ) ) {
    function eventosapp_mobile_event_offline_package( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        $user_id  = get_current_user_id();
        $page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
        $per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ?: 50 ) ) );

        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return new WP_Error( 'invalid_event', 'El evento no existe.', [ 'status' => 404 ] );
        }

        $modules = eventosapp_mobile_event_offline_modules( $event_id, $user_id );
        if ( empty( $modules ) ) {
            return new WP_Error(
                'forbidden_event',
                'No tienes módulos móviles autorizados para este evento.',
                [ 'status' => 403 ]
            );
        }

        $kiosk_config = null;
        if ( in_array( 'kiosk', $modules, true ) ) {
            if ( ! function_exists( 'eventosapp_mobile_kiosk_offline_config_payload' ) ) {
                return new WP_Error(
                    'api_dependency_missing',
                    'La API offline del Kiosko no está cargada correctamente.',
                    [ 'status' => 500 ]
                );
            }
            $kiosk_config = eventosapp_mobile_kiosk_offline_config_payload( $event_id );
            if ( is_wp_error( $kiosk_config ) ) {
                return $kiosk_config;
            }
        }

        $query = new WP_Query( [
            'post_type'              => 'eventosapp_ticket',
            'post_status'            => [ 'publish', 'future', 'draft', 'pending', 'private' ],
            'posts_per_page'         => $per_page,
            'paged'                  => $page,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'fields'                 => 'ids',
            'meta_key'               => '_eventosapp_ticket_evento_id',
            'meta_value'             => (string) $event_id,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ] );

        $ticket_ids = array_map( 'absint', (array) $query->posts );
        if ( $ticket_ids ) {
            update_meta_cache( 'post', $ticket_ids );
        }

        $staff_tickets = [];
        $kiosk_tickets = [];

        foreach ( $ticket_ids as $ticket_id ) {
            if (
                in_array( 'staff_qr', $modules, true ) &&
                function_exists( 'eventosapp_mobile_offline_ticket_payload' )
            ) {
                $staff_payload = eventosapp_mobile_offline_ticket_payload( $ticket_id, $event_id );
                if ( $staff_payload ) {
                    $staff_tickets[] = $staff_payload;
                }
            }

            if (
                in_array( 'kiosk', $modules, true ) &&
                function_exists( 'eventosapp_mobile_kiosk_offline_ticket_payload' )
            ) {
                $kiosk_payload = eventosapp_mobile_kiosk_offline_ticket_payload( $ticket_id, $event_id );
                if ( $kiosk_payload ) {
                    $kiosk_tickets[] = $kiosk_payload;
                }
            }
        }

        $timezone = get_post_meta( $event_id, '_eventosapp_zona_horaria', true );
        if ( ! $timezone ) {
            $timezone = wp_timezone_string() ?: 'UTC';
        }

        $event_payload = eventosapp_mobile_event_offline_event_payload(
            $event_id,
            $modules,
            is_array( $kiosk_config ) ? $kiosk_config : null
        );

        $module_payloads = [];
        if ( in_array( 'staff_qr', $modules, true ) ) {
            $staff_event = function_exists( 'eventosapp_mobile_app_event_payload' )
                ? eventosapp_mobile_app_event_payload( $event_id, 'staff_qr' )
                : $event_payload;

            $module_payloads['staff_qr'] = [
                'schema_version' => '1.0.0',
                'event'          => is_array( $staff_event ) ? $staff_event : $event_payload,
                'tickets'        => $staff_tickets,
            ];
        }

        if ( in_array( 'kiosk', $modules, true ) ) {
            $module_payloads['kiosk'] = [
                'schema_version' => '1.0.0',
                'event'          => is_array( $kiosk_config ) && isset( $kiosk_config['event'] )
                    ? $kiosk_config['event']
                    : $event_payload,
                'config'         => $kiosk_config,
                'tickets'        => $kiosk_tickets,
            ];
        }

        $response = [
            'api_version'   => EVENTOSAPP_MOBILE_EVENT_OFFLINE_API_VERSION,
            'generated_at'  => gmdate( 'c' ),
            'event_id'      => $event_id,
            'timezone'      => sanitize_text_field( $timezone ),
            'event'         => $event_payload,
            'modules'       => $modules,
            'module_data'   => $module_payloads,
            'page'          => $page,
            'per_page'      => $per_page,
            'total'         => absint( $query->found_posts ),
            'total_pages'   => max( 1, absint( $query->max_num_pages ) ),
        ];

        if ( function_exists( 'eventosapp_mobile_offline_no_cache_response' ) ) {
            return eventosapp_mobile_offline_no_cache_response( $response );
        }
        if ( function_exists( 'eventosapp_mobile_kiosk_offline_no_cache_response' ) ) {
            return eventosapp_mobile_kiosk_offline_no_cache_response( $response );
        }

        $rest_response = rest_ensure_response( $response );
        $rest_response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $rest_response->header( 'Pragma', 'no-cache' );
        return $rest_response;
    }
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\\d+)/offline-package', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventosapp_mobile_event_offline_package',
        'permission_callback' => 'eventosapp_mobile_app_permission',
        'args'                => [
            'event_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
            'page'     => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'per_page' => [ 'required' => false, 'sanitize_callback' => 'absint' ],
        ],
    ] );
} );
