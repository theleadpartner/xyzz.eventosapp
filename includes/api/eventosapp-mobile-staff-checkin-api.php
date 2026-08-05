<?php
/**
 * EventosApp – API móvil de operaciones para Android 2.5.1+
 *
 * Ruta de instalación:
 *   includes/api/eventosapp-mobile-staff-checkin-api.php
 *
 * Cargar después de includes/api/eventosapp-kiosk-api.php.
 *
 * Este archivo extiende la API estable del Kiosko sin reemplazar su búsqueda,
 * impresión, escarapelas ni cola. Sus responsabilidades son:
 * - autenticar usuarios que tengan al menos una operación móvil autorizada;
 * - filtrar Kiosko por la feature `self_checkin` del evento;
 * - filtrar Check-in QR por la feature `qr` del evento;
 * - exponer eventos Staff y registrar check-in QR sin impresión;
 * - impedir que permisos globales o asignaciones generales reabran funciones
 *   desmarcadas en la matriz por rol o en el acceso personalizado por usuario.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'EVENTOSAPP_MOBILE_APP_API_VERSION' ) ) {
    define( 'EVENTOSAPP_MOBILE_APP_API_VERSION', '1.1.0' );
}

if ( ! function_exists( 'eventosapp_mobile_app_restore_user' ) ) {
    function eventosapp_mobile_app_restore_user( $previous_user_id ) {
        $previous_user_id = absint( $previous_user_id );
        wp_set_current_user( $previous_user_id );
    }
}

if ( ! function_exists( 'eventosapp_mobile_app_user_can_feature_in_event' ) ) {
    /**
     * Fuente única de permisos de la aplicación Android.
     *
     * La función oficial del Control de Acceso Dashboard Staff resuelve, en orden:
     * alcance real del evento, acceso personalizado por usuario, matriz por rol del
     * evento y permiso global. Esta API no vuelve a inferir permisos por el nombre
     * del rol.
     */
    function eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user_id, $feature ) {
        $event_id = absint( $event_id );
        $user_id  = absint( $user_id );
        $feature  = sanitize_key( $feature );

        if ( ! $event_id || ! $user_id || ! $feature || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return false;
        }

        if ( user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        if ( function_exists( 'eventosapp_event_has_physical_access' ) && ! eventosapp_event_has_physical_access( $event_id ) ) {
            return false;
        }

        if ( function_exists( 'eventosapp_user_can_access_dashboard_feature_in_event' ) ) {
            return (bool) eventosapp_user_can_access_dashboard_feature_in_event(
                $user_id,
                $feature,
                $event_id
            );
        }

        $has_scope = false;
        if ( function_exists( 'eventosapp_dashboard_user_can_access_event_scope' ) ) {
            $has_scope = (bool) eventosapp_dashboard_user_can_access_event_scope( $event_id, $user_id );
        } elseif ( function_exists( 'eventosapp_user_can_manage_event' ) ) {
            $has_scope = (bool) eventosapp_user_can_manage_event( $event_id, $user_id );
        } else {
            $has_scope = absint( get_post_field( 'post_author', $event_id ) ) === $user_id
                || user_can( $user_id, 'edit_post', $event_id );
        }

        if ( ! $has_scope ) {
            return false;
        }

        if ( function_exists( 'eventosapp_staff_access_user_can_access_feature' ) ) {
            $custom = eventosapp_staff_access_user_can_access_feature(
                $event_id,
                $user_id,
                $feature,
                null
            );
            if ( $custom !== null ) {
                return (bool) $custom;
            }
        }

        $user = get_userdata( $user_id );
        if ( ! $user instanceof WP_User ) {
            return false;
        }

        if ( function_exists( 'eventosapp_role_can_in_event' ) ) {
            foreach ( (array) $user->roles as $role ) {
                if ( eventosapp_role_can_in_event( $role, $feature, $event_id ) ) {
                    return true;
                }
            }
            return false;
        }

        $previous_user_id = get_current_user_id();
        wp_set_current_user( $user_id );
        $allowed = function_exists( 'eventosapp_role_can' )
            ? (bool) eventosapp_role_can( $feature )
            : false;
        eventosapp_mobile_app_restore_user( $previous_user_id );

        return $allowed;
    }
}

if ( ! function_exists( 'eventosapp_mobile_app_all_event_ids' ) ) {
    function eventosapp_mobile_app_all_event_ids() {
        static $event_ids = null;
        if ( is_array( $event_ids ) ) {
            return $event_ids;
        }

        $event_ids = array_map( 'absint', get_posts( [
            'post_type'              => 'eventosapp_event',
            'post_status'            => [ 'publish', 'future', 'draft', 'pending', 'private' ],
            'posts_per_page'         => 500,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ] ) );

        return $event_ids;
    }
}

if ( ! function_exists( 'eventosapp_mobile_app_event_ids_for_feature' ) ) {
    function eventosapp_mobile_app_event_ids_for_feature( $user_id, $feature ) {
        $user_id = absint( $user_id );
        $feature = sanitize_key( $feature );
        if ( ! $user_id || ! $feature ) {
            return [];
        }

        $event_ids = [];
        foreach ( eventosapp_mobile_app_all_event_ids() as $event_id ) {
            if ( $feature === 'self_checkin' ) {
                if ( function_exists( 'eventosapp_kiosk_api_event_is_enabled' ) && ! eventosapp_kiosk_api_event_is_enabled( $event_id ) ) {
                    continue;
                }
            }

            if ( eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user_id, $feature ) ) {
                $event_ids[] = $event_id;
            }
        }

        return array_values( array_unique( $event_ids ) );
    }
}

if ( ! function_exists( 'eventosapp_mobile_app_kiosk_event_ids' ) ) {
    function eventosapp_mobile_app_kiosk_event_ids( $user_id ) {
        return eventosapp_mobile_app_event_ids_for_feature( $user_id, 'self_checkin' );
    }
}

if ( ! function_exists( 'eventosapp_mobile_app_staff_event_ids' ) ) {
    function eventosapp_mobile_app_staff_event_ids( $user_id ) {
        return eventosapp_mobile_app_event_ids_for_feature( $user_id, 'qr' );
    }
}

if ( ! function_exists( 'eventosapp_mobile_app_event_payload' ) ) {
    function eventosapp_mobile_app_event_payload( $event_id, $operation = 'staff_qr' ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) {
            return [];
        }

        if ( $operation === 'kiosk' && function_exists( 'eventosapp_kiosk_api_event_summary' ) ) {
            return eventosapp_kiosk_api_event_summary( $event_id );
        }

        $days = function_exists( 'eventosapp_get_event_days' )
            ? array_values( (array) eventosapp_get_event_days( $event_id ) )
            : [];
        $days = array_values( array_filter( array_map( 'sanitize_text_field', $days ) ) );
        sort( $days );

        $logo = get_post_meta( $event_id, '_eventosapp_self_checkin_main_logo_url', true );
        if ( ! $logo ) {
            $logo = get_the_post_thumbnail_url( $event_id, 'medium' ) ?: '';
        }

        return [
            'id'         => $event_id,
            'title'      => wp_strip_all_tags( get_the_title( $event_id ) ),
            'status'     => sanitize_key( get_post_status( $event_id ) ?: '' ),
            'days'       => $days,
            'first_day'  => $days ? reset( $days ) : '',
            'last_day'   => $days ? end( $days ) : '',
            'logo_url'   => esc_url_raw( $logo ),
            'modalidad'  => function_exists( 'eventosapp_get_event_modalidad' )
                ? eventosapp_get_event_modalidad( $event_id )
                : 'presencial',
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_app_token_user' ) ) {
    function eventosapp_mobile_app_token_user( $request ) {
        if ( ! $request instanceof WP_REST_Request ) {
            return new WP_Error( 'invalid_request', 'Solicitud inválida.', [ 'status' => 400 ] );
        }
        if ( ! function_exists( 'eventosapp_kiosk_api_bearer_token' ) ||
             ! function_exists( 'eventosapp_kiosk_api_find_user_by_token' ) ) {
            return new WP_Error(
                'api_dependency_missing',
                'La API segura del Kiosko no está cargada.',
                [ 'status' => 500 ]
            );
        }

        $token = eventosapp_kiosk_api_bearer_token( $request );
        $user  = eventosapp_kiosk_api_find_user_by_token( $token );
        if ( is_wp_error( $user ) ) {
            return $user;
        }

        wp_set_current_user( $user->ID );
        return $user;
    }
}

if ( ! function_exists( 'eventosapp_mobile_app_permission' ) ) {
    function eventosapp_mobile_app_permission( $request ) {
        $user = eventosapp_mobile_app_token_user( $request );
        return is_wp_error( $user ) ? $user : true;
    }
}

if ( ! function_exists( 'eventosapp_mobile_app_login' ) ) {
    function eventosapp_mobile_app_login( WP_REST_Request $request ) {
        if ( ! function_exists( 'eventosapp_kiosk_api_create_token' ) ) {
            return new WP_Error(
                'api_dependency_missing',
                'La API segura del Kiosko no está cargada.',
                [ 'status' => 500 ]
            );
        }

        $login    = sanitize_text_field( (string) $request->get_param( 'login' ) );
        $password = (string) $request->get_param( 'password' );
        $device   = sanitize_text_field( (string) $request->get_param( 'device_name' ) );

        if ( $login === '' || $password === '' ) {
            return new WP_Error( 'missing_credentials', 'Escribe el usuario y la contraseña.', [ 'status' => 400 ] );
        }

        $rate_key = function_exists( 'eventosapp_kiosk_api_login_rate_key' )
            ? eventosapp_kiosk_api_login_rate_key( $login )
            : 'evapp_mobile_login_' . md5( strtolower( $login ) . '|' . ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        $attempts = absint( get_transient( $rate_key ) );
        if ( $attempts >= 8 ) {
            return new WP_Error(
                'too_many_attempts',
                'Demasiados intentos. Espera 15 minutos y vuelve a intentar.',
                [ 'status' => 429 ]
            );
        }

        $user = wp_authenticate( $login, $password );
        if ( is_wp_error( $user ) ) {
            set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
            return new WP_Error( 'invalid_credentials', 'Usuario o contraseña incorrectos.', [ 'status' => 401 ] );
        }

        delete_transient( $rate_key );
        wp_set_current_user( $user->ID );

        $kiosk_event_ids = eventosapp_mobile_app_kiosk_event_ids( $user->ID );
        $staff_event_ids = eventosapp_mobile_app_staff_event_ids( $user->ID );
        if ( empty( $kiosk_event_ids ) && empty( $staff_event_ids ) ) {
            return new WP_Error(
                'forbidden',
                'Tu usuario no tiene eventos autorizados para Kiosko ni para Check-in QR.',
                [ 'status' => 403 ]
            );
        }

        $token = eventosapp_kiosk_api_create_token( $user->ID, $device );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $user_payload = function_exists( 'eventosapp_kiosk_api_user_payload' )
            ? eventosapp_kiosk_api_user_payload( $user )
            : [
                'id'           => absint( $user->ID ),
                'display_name' => sanitize_text_field( $user->display_name ),
                'email'        => sanitize_email( $user->user_email ),
            ];

        return rest_ensure_response( [
            'token'      => $token['token'],
            'expires_at' => gmdate( 'c', $token['expires_at'] ),
            'user'       => $user_payload,
            'capabilities' => [
                'kiosk'             => ! empty( $kiosk_event_ids ),
                'kiosk_event_count' => count( $kiosk_event_ids ),
                'staff_qr'          => ! empty( $staff_event_ids ),
                'staff_event_count' => count( $staff_event_ids ),
                'mobile_api'        => EVENTOSAPP_MOBILE_APP_API_VERSION,
            ],
        ] );
    }
}

/**
 * Sustituye únicamente el login de la API existente para permitir una sesión
 * cuando el usuario tenga Kiosko, Check-in QR o ambas operaciones.
 */
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
    if ( $result !== null || ! $request instanceof WP_REST_Request ) {
        return $result;
    }
    if ( strtoupper( $request->get_method() ) !== 'POST' ) {
        return $result;
    }
    if ( $request->get_route() !== '/eventosapp-kiosk/v1/auth/login' ) {
        return $result;
    }

    return eventosapp_mobile_app_login( $request );
}, 5, 3 );

/**
 * Candado previo para las rutas Kiosko. El listado se responde directamente
 * con eventos `self_checkin` autorizados; las rutas individuales se bloquean
 * cuando el usuario no tiene esa feature en el evento/ticket solicitado.
 */
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
    if ( $result !== null || ! $request instanceof WP_REST_Request ) {
        return $result;
    }

    $route  = $request->get_route();
    $method = strtoupper( $request->get_method() );

    if ( $route === '/eventosapp-kiosk/v1/auth/logout' && $method === 'POST' ) {
        $user = eventosapp_mobile_app_token_user( $request );
        if ( is_wp_error( $user ) ) {
            return $user;
        }
        return function_exists( 'eventosapp_kiosk_api_logout' )
            ? eventosapp_kiosk_api_logout( $request )
            : new WP_Error( 'api_dependency_missing', 'No se pudo cerrar la sesión remota.', [ 'status' => 500 ] );
    }

    if ( $route === '/eventosapp-kiosk/v1/events' && $method === 'GET' ) {
        $user = eventosapp_mobile_app_token_user( $request );
        if ( is_wp_error( $user ) ) {
            return $user;
        }

        $events = array_map(
            static function ( $event_id ) {
                return eventosapp_mobile_app_event_payload( $event_id, 'kiosk' );
            },
            eventosapp_mobile_app_kiosk_event_ids( $user->ID )
        );

        return rest_ensure_response( [
            'events' => array_values( array_filter( $events ) ),
            'user'   => function_exists( 'eventosapp_kiosk_api_user_payload' )
                ? eventosapp_kiosk_api_user_payload( $user )
                : [],
        ] );
    }

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

    $user = eventosapp_mobile_app_token_user( $request );
    if ( is_wp_error( $user ) ) {
        return $user;
    }

    if ( ! eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user->ID, 'self_checkin' ) ) {
        return new WP_Error(
            'forbidden_event',
            'No tienes permiso de Autogestión del Asistente para este evento.',
            [ 'status' => 403 ]
        );
    }

    return $result;
}, 8, 3 );

if ( ! function_exists( 'eventosapp_mobile_app_operations' ) ) {
    function eventosapp_mobile_app_operations( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $kiosk   = eventosapp_mobile_app_kiosk_event_ids( $user_id );
        $staff   = eventosapp_mobile_app_staff_event_ids( $user_id );

        return rest_ensure_response( [
            'api_version' => EVENTOSAPP_MOBILE_APP_API_VERSION,
            'operations'  => [
                'kiosk' => [
                    'allowed'     => ! empty( $kiosk ),
                    'event_count' => count( $kiosk ),
                ],
                'staff_qr' => [
                    'allowed'     => ! empty( $staff ),
                    'event_count' => count( $staff ),
                ],
            ],
        ] );
    }
}

if ( ! function_exists( 'eventosapp_mobile_app_staff_events' ) ) {
    function eventosapp_mobile_app_staff_events( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $events  = array_map(
            static function ( $event_id ) {
                return eventosapp_mobile_app_event_payload( $event_id, 'staff_qr' );
            },
            eventosapp_mobile_app_staff_event_ids( $user_id )
        );

        usort( $events, static function ( $a, $b ) {
            return strcmp( $b['last_day'] ?? '', $a['last_day'] ?? '' );
        } );

        return rest_ensure_response( [
            'events'      => array_values( array_filter( $events ) ),
            'count'       => count( $events ),
            'api_version' => EVENTOSAPP_MOBILE_APP_API_VERSION,
        ] );
    }
}

if ( ! function_exists( 'eventosapp_mobile_app_find_ticket' ) ) {
    function eventosapp_mobile_app_find_ticket( $scanned, $event_id ) {
        $scanned  = sanitize_text_field( (string) $scanned );
        $event_id = absint( $event_id );

        if ( $scanned === '' || ! $event_id ) {
            return new WP_Error( 'invalid_qr', 'El QR está vacío o es inválido.', [ 'status' => 400 ] );
        }

        if ( function_exists( 'eventosapp_qr_find_ticket_by_scanned_code' ) ) {
            $lookup = eventosapp_qr_find_ticket_by_scanned_code( $scanned, $event_id );
            if ( ! empty( $lookup['found'] ) && ! empty( $lookup['ticket_id'] ) ) {
                return $lookup;
            }
            return new WP_Error(
                'ticket_not_found',
                sanitize_text_field( $lookup['error'] ?? 'Ticket no encontrado para este evento.' ),
                [ 'status' => 404 ]
            );
        }

        if ( is_callable( [ 'EventosApp_QR_Manager', 'validate_qr' ] ) ) {
            $validation = EventosApp_QR_Manager::validate_qr( $scanned );
            if ( ! empty( $validation['valid'] ) && ! empty( $validation['ticket_id'] ) ) {
                $ticket_id = absint( $validation['ticket_id'] );
                if ( absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) === $event_id ) {
                    return [
                        'found'      => true,
                        'ticket_id'  => $ticket_id,
                        'type'       => sanitize_key( $validation['type'] ?? 'qr_manager' ),
                        'type_label' => sanitize_text_field( $validation['type_label'] ?? 'QR' ),
                    ];
                }
            }
        }

        return new WP_Error(
            'qr_lookup_unavailable',
            'EventosApp no tiene disponible el resolvedor de tickets QR.',
            [ 'status' => 500 ]
        );
    }
}

if ( ! function_exists( 'eventosapp_mobile_app_event_datetime' ) ) {
    function eventosapp_mobile_app_event_datetime( $event_id ) {
        $tzid = get_post_meta( $event_id, '_eventosapp_zona_horaria', true );
        if ( ! $tzid ) {
            $tzid = wp_timezone_string() ?: 'UTC';
        }

        try {
            return new DateTime( 'now', new DateTimeZone( $tzid ) );
        } catch ( Exception $e ) {
            return new DateTime( 'now', wp_timezone() );
        }
    }
}

if ( ! function_exists( 'eventosapp_mobile_app_staff_checkin' ) ) {
    function eventosapp_mobile_app_staff_checkin( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        $scanned  = sanitize_text_field( (string) $request->get_param( 'scanned' ) );
        $user_id  = get_current_user_id();

        if ( ! $event_id || $scanned === '' ) {
            return new WP_Error(
                'missing_data',
                'No se recibió el evento o el contenido del QR.',
                [ 'status' => 400 ]
            );
        }

        if ( ! eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user_id, 'qr' ) ) {
            return new WP_Error(
                'forbidden_event',
                'No tienes permiso de Check-in con QR para este evento.',
                [ 'status' => 403 ]
            );
        }

        $lookup = eventosapp_mobile_app_find_ticket( $scanned, $event_id );
        if ( is_wp_error( $lookup ) ) {
            return $lookup;
        }

        $ticket_id = absint( $lookup['ticket_id'] ?? 0 );
        if ( ! $ticket_id || absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) !== $event_id ) {
            return new WP_Error(
                'invalid_ticket_event',
                'El ticket no pertenece al evento seleccionado.',
                [ 'status' => 403 ]
            );
        }

        if ( function_exists( 'eventosapp_ticket_is_virtual' ) && eventosapp_ticket_is_virtual( $ticket_id ) ) {
            return new WP_Error(
                'virtual_ticket',
                'El ticket es virtual y no admite check-in presencial.',
                [ 'status' => 409 ]
            );
        }

        $payment_message = '';
        if ( get_post_meta( $event_id, '_eventosapp_ticket_control_pago', true ) === '1' ) {
            $payment_status = get_post_meta( $ticket_id, '_eventosapp_estado_pago', true ) ?: 'no_pagado';
            if ( $payment_status === 'no_pagado' ) {
                return new WP_Error(
                    'payment_required',
                    'El Check-in no se puede realizar porque el ticket no ha sido pagado.',
                    [ 'status' => 409, 'ticket_id' => $ticket_id ]
                );
            }
            $payment_message = 'El ticket está marcado como pagado.';
        }

        $dt    = eventosapp_mobile_app_event_datetime( $event_id );
        $today = $dt->format( 'Y-m-d' );
        $days  = function_exists( 'eventosapp_get_event_days' )
            ? (array) eventosapp_get_event_days( $event_id )
            : [];

        if ( empty( $days ) || ! in_array( $today, $days, true ) ) {
            return new WP_Error(
                'invalid_event_day',
                'El check-in solo está permitido en las fechas del evento. Hoy no corresponde.',
                [ 'status' => 409 ]
            );
        }

        $status = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
        if ( is_string( $status ) ) {
            $status = maybe_unserialize( $status );
        }
        if ( ! is_array( $status ) ) {
            $status = [];
        }

        $already = isset( $status[ $today ] ) && $status[ $today ] === 'checked_in';
        if ( ! $already ) {
            $status[ $today ] = 'checked_in';
            update_post_meta( $ticket_id, '_eventosapp_checkin_status', $status );

            $log = get_post_meta( $ticket_id, '_eventosapp_checkin_log', true );
            if ( is_string( $log ) ) {
                $log = maybe_unserialize( $log );
            }
            if ( ! is_array( $log ) ) {
                $log = [];
            }

            $user  = wp_get_current_user();
            $log[] = [
                'fecha'         => $dt->format( 'Y-m-d' ),
                'hora'          => $dt->format( 'H:i:s' ),
                'dia'           => $today,
                'status'        => 'checked_in',
                'usuario'       => $user->display_name . ' (' . $user->user_email . ')',
                'usuario_id'    => absint( $user->ID ),
                'qr_type'       => sanitize_key( $lookup['type'] ?? 'unknown' ),
                'qr_type_label' => sanitize_text_field( $lookup['type_label'] ?? 'QR' ),
                'origen'        => 'android_staff_qr',
            ];
            update_post_meta( $ticket_id, '_eventosapp_checkin_log', $log );

            if ( function_exists( 'eventosapp_update_qr_usage_stats' ) ) {
                eventosapp_update_qr_usage_stats(
                    $event_id,
                    sanitize_key( $lookup['type'] ?? 'unknown' )
                );
            }
        }

        update_meta_cache( 'post', [ $ticket_id ] );
        $first = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
        $last  = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );

        return rest_ensure_response( [
            'already'            => $already,
            'message'            => $already
                ? 'El invitado ya tenía check-in para esta fecha; no se duplicó el registro.'
                : 'Check-in registrado correctamente.',
            'event_name'         => sanitize_text_field( get_the_title( $event_id ) ),
            'checkin_date'       => $today,
            'checkin_date_label' => wp_date( 'D, d M Y', $dt->getTimestamp(), $dt->getTimezone() ),
            'payment_message'    => $payment_message,
            'qr_type_label'      => sanitize_text_field( $lookup['type_label'] ?? 'QR' ),
            'attendee'           => [
                'ticket_id'       => $ticket_id,
                'event_id'        => $event_id,
                'first_name'      => sanitize_text_field( $first ),
                'last_name'       => sanitize_text_field( $last ),
                'full_name'       => sanitize_text_field( trim( $first . ' ' . $last ) ),
                'company'         => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_empresa', true ) ),
                'designation'     => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_cargo', true ) ),
                'identification'  => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_cc', true ) ),
                'email'           => sanitize_email( get_post_meta( $ticket_id, '_eventosapp_asistente_email', true ) ),
                'phone'           => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_tel', true ) ),
                'localidad'       => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_localidad', true ) ),
                'modalidad'       => function_exists( 'eventosapp_get_ticket_modalidad' )
                    ? eventosapp_get_ticket_modalidad( $ticket_id )
                    : 'presencial',
                'modalidad_label' => function_exists( 'eventosapp_get_ticket_modalidad_label' )
                    ? eventosapp_get_ticket_modalidad_label( $ticket_id )
                    : 'Presencial',
            ],
        ] );
    }
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'eventosapp-kiosk/v1', '/operations', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventosapp_mobile_app_operations',
        'permission_callback' => 'eventosapp_mobile_app_permission',
    ] );

    register_rest_route( 'eventosapp-kiosk/v1', '/staff/events', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventosapp_mobile_app_staff_events',
        'permission_callback' => 'eventosapp_mobile_app_permission',
    ] );

    register_rest_route( 'eventosapp-kiosk/v1', '/staff/events/(?P<event_id>\d+)/checkin', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'eventosapp_mobile_app_staff_checkin',
        'permission_callback' => 'eventosapp_mobile_app_permission',
        'args'                => [
            'event_id' => [
                'required'          => true,
                'sanitize_callback' => 'absint',
            ],
            'scanned' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ] );
}, 30 );
