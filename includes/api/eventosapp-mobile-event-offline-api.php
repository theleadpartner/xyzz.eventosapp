<?php
/**
 * EventosApp – Paquete offline móvil unificado por evento (Android 2.9.1+).
 *
 * Android 2.11.0 incorporó Consumo de Consumibles al mismo paquete por evento.
 * Android 2.11.1 puede solicitar un snapshot estable de IDs para eventos grandes:
 * el backend resuelve los tickets una sola vez, pagina sobre ese conjunto fijo y
 * evita reconstruir configuración estática en todas las páginas.
 *
 * Ruta:
 * GET /eventosapp-kiosk/v1/events/{event_id}/offline-package
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'EVENTOSAPP_MOBILE_EVENT_OFFLINE_API_VERSION' ) ) {
    define( 'EVENTOSAPP_MOBILE_EVENT_OFFLINE_API_VERSION', '1.3.0' );
}

if ( ! defined( 'EVENTOSAPP_MOBILE_EVENT_OFFLINE_SNAPSHOT_TTL' ) ) {
    define( 'EVENTOSAPP_MOBILE_EVENT_OFFLINE_SNAPSHOT_TTL', 2 * HOUR_IN_SECONDS );
}

if ( ! function_exists( 'eventosapp_mobile_event_offline_modules' ) ) {
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

        if ( function_exists( 'eventosapp_mobile_advanced_modules_for_event' ) ) {
            $modules = array_merge( $modules, eventosapp_mobile_advanced_modules_for_event( $event_id, $user_id ) );
        }

        if (
            function_exists( 'eventosapp_mobile_consumables_user_can_event' ) &&
            eventosapp_mobile_consumables_user_can_event( $event_id, $user_id )
        ) {
            $modules[] = 'consumables_staff';
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

if ( ! function_exists( 'eventosapp_mobile_event_offline_snapshot_key' ) ) {
    function eventosapp_mobile_event_offline_snapshot_key( $snapshot_id ) {
        return 'evapp_offpkg_' . md5( (string) $snapshot_id );
    }
}

if ( ! function_exists( 'eventosapp_mobile_event_offline_create_snapshot' ) ) {
    /**
     * Congela únicamente los IDs de tickets del evento. El payload completo no
     * se guarda en transients: cada página sigue construyéndose con datos
     * actuales, pero no repite la consulta paginada ni puede saltar/duplicar IDs
     * si cambia el conjunto de tickets mientras Android descarga el evento.
     */
    function eventosapp_mobile_event_offline_create_snapshot( $event_id, $user_id ) {
        $event_id = absint( $event_id );
        $user_id  = absint( $user_id );

        $query = new WP_Query( [
            'post_type'              => 'eventosapp_ticket',
            'post_status'            => [ 'publish', 'future', 'draft', 'pending', 'private' ],
            'posts_per_page'         => -1,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'fields'                 => 'ids',
            'meta_key'               => '_eventosapp_ticket_evento_id',
            'meta_value'             => (string) $event_id,
            'no_found_rows'          => true,
            'cache_results'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ] );

        $ticket_ids  = array_values( array_filter( array_map( 'absint', (array) $query->posts ) ) );
        $snapshot_id = wp_generate_uuid4();
        $snapshot    = [
            'event_id'   => $event_id,
            'user_id'    => $user_id,
            'ticket_ids' => $ticket_ids,
            'created_at' => time(),
        ];

        if ( ! set_transient(
            eventosapp_mobile_event_offline_snapshot_key( $snapshot_id ),
            $snapshot,
            EVENTOSAPP_MOBILE_EVENT_OFFLINE_SNAPSHOT_TTL
        ) ) {
            return new WP_Error(
                'offline_snapshot_storage_failed',
                'EventosApp no pudo preparar el índice temporal del evento offline.',
                [ 'status' => 500 ]
            );
        }

        return [
            'id'         => $snapshot_id,
            'ticket_ids' => $ticket_ids,
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_event_offline_load_snapshot' ) ) {
    function eventosapp_mobile_event_offline_load_snapshot( $snapshot_id, $event_id, $user_id ) {
        $snapshot_id = sanitize_text_field( (string) $snapshot_id );
        if ( ! preg_match( '/^[a-f0-9-]{32,64}$/i', $snapshot_id ) ) {
            return new WP_Error(
                'offline_snapshot_expired',
                'El snapshot offline no es válido. La APP debe reiniciar la descarga del evento.',
                [ 'status' => 409 ]
            );
        }

        $snapshot = get_transient( eventosapp_mobile_event_offline_snapshot_key( $snapshot_id ) );
        if ( ! is_array( $snapshot ) ) {
            return new WP_Error(
                'offline_snapshot_expired',
                'El snapshot offline venció. La APP debe reiniciar la descarga del evento.',
                [ 'status' => 409 ]
            );
        }

        if (
            absint( $snapshot['event_id'] ?? 0 ) !== absint( $event_id ) ||
            absint( $snapshot['user_id'] ?? 0 ) !== absint( $user_id )
        ) {
            return new WP_Error(
                'offline_snapshot_expired',
                'El snapshot offline no corresponde al evento o usuario actual.',
                [ 'status' => 409 ]
            );
        }

        return [
            'id' => $snapshot_id,
            'ticket_ids' => array_values( array_filter( array_map(
                'absint',
                (array) ( $snapshot['ticket_ids'] ?? [] )
            ) ) ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_event_offline_legacy_page' ) ) {
    /** Mantiene sin cambios el contrato paginado de Android anteriores. */
    function eventosapp_mobile_event_offline_legacy_page( $event_id, $page, $per_page ) {
        $query = new WP_Query( [
            'post_type'              => 'eventosapp_ticket',
            'post_status'            => [ 'publish', 'future', 'draft', 'pending', 'private' ],
            'posts_per_page'         => absint( $per_page ),
            'paged'                  => absint( $page ),
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'fields'                 => 'ids',
            'meta_key'               => '_eventosapp_ticket_evento_id',
            'meta_value'             => (string) absint( $event_id ),
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ] );

        return [
            'ticket_ids'  => array_map( 'absint', (array) $query->posts ),
            'total'       => absint( $query->found_posts ),
            'total_pages' => max( 1, absint( $query->max_num_pages ) ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_event_offline_package' ) ) {
    function eventosapp_mobile_event_offline_package( WP_REST_Request $request ) {
        $event_id    = absint( $request['event_id'] );
        $user_id     = get_current_user_id();
        $page        = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
        $per_page    = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ?: 50 ) ) );
        $snapshot_id = sanitize_text_field( (string) $request->get_param( 'snapshot_id' ) );
        $optimized   = $snapshot_id !== '' || rest_sanitize_boolean( $request->get_param( 'snapshot' ) );

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

        $advanced_modules = array_values( array_intersect(
            $modules,
            [ 'qr_localidad', 'qr_session', 'qr_double_auth' ]
        ) );
        $has_consumables = in_array( 'consumables_staff', $modules, true );
        $include_static  = ! $optimized || $page === 1;

        $kiosk_config = null;
        if ( in_array( 'kiosk', $modules, true ) ) {
            if ( ! function_exists( 'eventosapp_mobile_kiosk_offline_config_payload' ) ) {
                return new WP_Error(
                    'api_dependency_missing',
                    'La API offline del Kiosko no está cargada correctamente.',
                    [ 'status' => 500 ]
                );
            }
            if ( $include_static ) {
                $kiosk_config = eventosapp_mobile_kiosk_offline_config_payload( $event_id );
                if ( is_wp_error( $kiosk_config ) ) {
                    return $kiosk_config;
                }
            }
        }

        if ( $advanced_modules && ! function_exists( 'eventosapp_mobile_advanced_ticket_payload' ) ) {
            return new WP_Error(
                'api_dependency_missing',
                'La API móvil de módulos QR avanzados no está cargada correctamente.',
                [ 'status' => 500 ]
            );
        }

        if ( $has_consumables && ! function_exists( 'eventosapp_mobile_consumables_ticket_payload' ) ) {
            return new WP_Error(
                'api_dependency_missing',
                'La API móvil de consumibles no está cargada correctamente.',
                [ 'status' => 500 ]
            );
        }

        $ticket_ids  = [];
        $total       = 0;
        $total_pages = 1;

        if ( $optimized ) {
            if ( $snapshot_id === '' ) {
                if ( $page !== 1 ) {
                    return new WP_Error(
                        'offline_snapshot_expired',
                        'La descarga optimizada debe comenzar por la primera página.',
                        [ 'status' => 409 ]
                    );
                }
                $snapshot = eventosapp_mobile_event_offline_create_snapshot( $event_id, $user_id );
            } else {
                $snapshot = eventosapp_mobile_event_offline_load_snapshot( $snapshot_id, $event_id, $user_id );
            }
            if ( is_wp_error( $snapshot ) ) {
                return $snapshot;
            }

            $snapshot_id = sanitize_text_field( $snapshot['id'] );
            $all_ids     = array_values( (array) $snapshot['ticket_ids'] );
            $total       = count( $all_ids );
            $total_pages = max( 1, (int) ceil( $total / $per_page ) );
            $ticket_ids  = array_slice( $all_ids, ( $page - 1 ) * $per_page, $per_page );
        } else {
            $legacy      = eventosapp_mobile_event_offline_legacy_page( $event_id, $page, $per_page );
            $ticket_ids  = $legacy['ticket_ids'];
            $total       = $legacy['total'];
            $total_pages = $legacy['total_pages'];
        }

        $ticket_ids = array_values( array_filter( array_map( 'absint', (array) $ticket_ids ) ) );
        if ( $ticket_ids ) {
            update_meta_cache( 'post', $ticket_ids );
        }

        $staff_tickets       = [];
        $kiosk_tickets       = [];
        $advanced_tickets    = [];
        $consumables_tickets = [];

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

            if ( $advanced_modules ) {
                $advanced_payload = eventosapp_mobile_advanced_ticket_payload( $ticket_id, $event_id, $advanced_modules );
                if ( $advanced_payload ) {
                    $advanced_tickets[] = $advanced_payload;
                }
            }

            if ( $has_consumables ) {
                $consumables_payload = eventosapp_mobile_consumables_ticket_payload( $ticket_id, $event_id );
                if ( $consumables_payload ) {
                    $consumables_tickets[] = $consumables_payload;
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
            $staff_block = [
                'schema_version' => '1.0.0',
                'tickets'        => $staff_tickets,
            ];
            if ( $include_static ) {
                $staff_event = function_exists( 'eventosapp_mobile_app_event_payload' )
                    ? eventosapp_mobile_app_event_payload( $event_id, 'staff_qr' )
                    : $event_payload;
                $staff_block['event'] = is_array( $staff_event ) ? $staff_event : $event_payload;
            }
            $module_payloads['staff_qr'] = $staff_block;
        }

        if ( in_array( 'kiosk', $modules, true ) ) {
            $kiosk_block = [
                'schema_version' => '1.0.0',
                'tickets'        => $kiosk_tickets,
            ];
            if ( $include_static ) {
                $kiosk_block['event'] = is_array( $kiosk_config ) && isset( $kiosk_config['event'] )
                    ? $kiosk_config['event']
                    : $event_payload;
                $kiosk_block['config'] = $kiosk_config;
            }
            $module_payloads['kiosk'] = $kiosk_block;
        }

        if ( $advanced_modules ) {
            $advanced_block = [
                'schema_version' => '1.0.0',
                'tickets'        => $advanced_tickets,
            ];
            if ( $include_static ) {
                $advanced_block['event'] = $event_payload;
                $advanced_block['enabled_modules'] = $advanced_modules;
                $advanced_block['config'] = function_exists( 'eventosapp_mobile_advanced_config_payload' )
                    ? eventosapp_mobile_advanced_config_payload( $event_id, $advanced_modules )
                    : [];
            }
            $module_payloads['advanced_qr'] = $advanced_block;
        }

        if ( $has_consumables ) {
            $consumables_block = [
                'schema_version' => '1.0.0',
                'tickets'        => $consumables_tickets,
            ];
            if ( $include_static ) {
                $consumables_block['event'] = $event_payload;
                $consumables_block['config'] = function_exists( 'eventosapp_mobile_consumables_offline_config_payload' )
                    ? eventosapp_mobile_consumables_offline_config_payload( $event_id, $user_id )
                    : [];
            }
            $module_payloads['consumables'] = $consumables_block;
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
            'total'         => absint( $total ),
            'total_pages'   => max( 1, absint( $total_pages ) ),
            'static_payload'=> (bool) $include_static,
        ];

        if ( $optimized ) {
            $response['snapshot_id'] = $snapshot_id;
            $response['snapshot_expires_in'] = absint( EVENTOSAPP_MOBILE_EVENT_OFFLINE_SNAPSHOT_TTL );
        }

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

/**
 * Consumibles ya llega en lotes de 200 desde Android. Este límite defensivo
 * evita que clientes defectuosos envíen miles de operaciones en una sola
 * ejecución PHP; Staff, Kiosko y QR avanzado ya aplican el mismo techo de 500.
 */
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
    if ( $result !== null || ! $request instanceof WP_REST_Request ) {
        return $result;
    }
    if (
        strtoupper( $request->get_method() ) !== 'POST' ||
        ! preg_match( '#^/eventosapp-kiosk/v1/events/\d+/consumables/offline-sync$#', $request->get_route() )
    ) {
        return $result;
    }

    $items = $request->get_param( 'items' );
    if ( is_array( $items ) && count( $items ) > 500 ) {
        return new WP_Error(
            'too_many_items',
            'Envía máximo 500 operaciones de consumibles por lote.',
            [ 'status' => 413 ]
        );
    }
    return $result;
}, 8, 3 );

add_action( 'rest_api_init', function () {
    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\d+)/offline-package', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventosapp_mobile_event_offline_package',
        'permission_callback' => 'eventosapp_mobile_app_permission',
        'args'                => [
            'event_id'    => [ 'required' => true, 'sanitize_callback' => 'absint' ],
            'page'        => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'per_page'    => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'snapshot'    => [ 'required' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ],
            'snapshot_id' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );
} );
