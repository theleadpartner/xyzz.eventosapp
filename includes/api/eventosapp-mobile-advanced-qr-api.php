<?php
/**
 * EventosApp – API móvil para módulos QR avanzados (Android 2.10.0+).
 *
 * Módulos:
 * - qr_localidad: consulta informativa de localidad, sin modificar check-in.
 * - qr_session: control de acceso y check-in de sesiones internas.
 * - qr_double_auth: check-in general con segundo factor de 5 dígitos.
 *
 * Offline:
 * - reutiliza las huellas QR del snapshot Staff;
 * - nunca entrega códigos de doble autenticación en texto claro;
 * - genera verificadores PBKDF2 por ticket/día y una prueba HMAC firmada por el servidor;
 * - sincroniza operaciones idempotentes mediante client_id.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'EVENTOSAPP_MOBILE_ADVANCED_QR_API_VERSION' ) ) {
    define( 'EVENTOSAPP_MOBILE_ADVANCED_QR_API_VERSION', '1.0.0' );
}

if ( ! function_exists( 'eventosapp_mobile_advanced_session_names' ) ) {
    function eventosapp_mobile_advanced_session_names( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) {
            return [];
        }

        if ( function_exists( 'eventosapp_qr_get_event_session_names' ) ) {
            return array_values( array_filter( array_map( 'sanitize_text_field', (array) eventosapp_qr_get_event_session_names( $event_id ) ) ) );
        }

        $raw = get_post_meta( $event_id, '_eventosapp_sesiones_internas', true );
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $names = [];
        foreach ( $raw as $session ) {
            $name = '';
            if ( is_array( $session ) && isset( $session['nombre'] ) ) {
                $name = (string) $session['nombre'];
            } elseif ( is_string( $session ) ) {
                $name = $session;
            }
            $name = trim( sanitize_text_field( $name ) );
            if ( $name !== '' ) {
                $names[] = $name;
            }
        }
        return array_values( array_unique( $names ) );
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_modules_for_event' ) ) {
    function eventosapp_mobile_advanced_modules_for_event( $event_id, $user_id = 0 ) {
        $event_id = absint( $event_id );
        $user_id  = absint( $user_id ?: get_current_user_id() );
        if ( ! $event_id || ! $user_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return [];
        }
        if ( ! function_exists( 'eventosapp_mobile_app_user_can_feature_in_event' ) ) {
            return [];
        }

        $modules = [];

        if ( eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user_id, 'qr_localidad' ) ) {
            $modules[] = 'qr_localidad';
        }

        // El shortcode de sesiones conserva históricamente la feature `qr`.
        if (
            eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user_id, 'qr' ) &&
            eventosapp_mobile_advanced_session_names( $event_id )
        ) {
            $modules[] = 'qr_session';
        }

        if (
            get_post_meta( $event_id, '_eventosapp_ticket_double_auth_enabled', true ) === '1' &&
            eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user_id, 'qr_double_auth' )
        ) {
            $modules[] = 'qr_double_auth';
        }

        return array_values( array_unique( $modules ) );
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_all_modules_for_event' ) ) {
    function eventosapp_mobile_advanced_all_modules_for_event( $event_id, $user_id = 0 ) {
        $event_id = absint( $event_id );
        $user_id  = absint( $user_id ?: get_current_user_id() );
        if ( ! $event_id || ! $user_id ) {
            return [];
        }

        $modules = eventosapp_mobile_advanced_modules_for_event( $event_id, $user_id );

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

if ( ! function_exists( 'eventosapp_mobile_advanced_event_days' ) ) {
    function eventosapp_mobile_advanced_event_days( $event_id ) {
        $days = function_exists( 'eventosapp_get_event_days' )
            ? (array) eventosapp_get_event_days( absint( $event_id ) )
            : [];
        $days = array_values( array_filter( array_map( 'sanitize_text_field', $days ), static function ( $day ) {
            return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day );
        } ) );
        sort( $days );
        return array_values( array_unique( $days ) );
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_timezone' ) ) {
    function eventosapp_mobile_advanced_timezone( $event_id ) {
        $name = sanitize_text_field( (string) get_post_meta( absint( $event_id ), '_eventosapp_zona_horaria', true ) );
        if ( $name === '' ) {
            $name = wp_timezone_string() ?: 'UTC';
        }
        try {
            return new DateTimeZone( $name );
        } catch ( Throwable $e ) {
            return wp_timezone();
        }
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_today' ) ) {
    function eventosapp_mobile_advanced_today( $event_id ) {
        try {
            $now = new DateTime( 'now', eventosapp_mobile_advanced_timezone( $event_id ) );
            return $now->format( 'Y-m-d' );
        } catch ( Throwable $e ) {
            return current_time( 'Y-m-d' );
        }
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_is_valid_day' ) ) {
    function eventosapp_mobile_advanced_is_valid_day( $event_id, $day ) {
        $day = sanitize_text_field( (string) $day );
        return $day !== '' && in_array( $day, eventosapp_mobile_advanced_event_days( $event_id ), true );
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_find_ticket' ) ) {
    function eventosapp_mobile_advanced_find_ticket( $scanned, $event_id ) {
        $event_id = absint( $event_id );
        $scanned  = trim( sanitize_text_field( (string) $scanned ) );
        if ( ! $event_id || $scanned === '' ) {
            return new WP_Error( 'invalid_scan', 'QR vacío o inválido.', [ 'status' => 400 ] );
        }

        if ( function_exists( 'eventosapp_qr_find_ticket_by_scanned_code' ) ) {
            $lookup = eventosapp_qr_find_ticket_by_scanned_code( $scanned, $event_id );
            if ( ! empty( $lookup['found'] ) && ! empty( $lookup['ticket_id'] ) ) {
                return [
                    'ticket_id'    => absint( $lookup['ticket_id'] ),
                    'qr_type'      => sanitize_key( (string) ( $lookup['type'] ?? 'unknown' ) ),
                    'qr_type_label'=> sanitize_text_field( (string) ( $lookup['type_label'] ?? 'QR' ) ),
                ];
            }
            return new WP_Error(
                'ticket_not_found',
                ! empty( $lookup['error'] ) ? sanitize_text_field( $lookup['error'] ) : 'Ticket no encontrado para este evento.',
                [ 'status' => 404 ]
            );
        }

        // Fallback mínimo para instalaciones donde el helper frontend todavía no esté cargado.
        $candidate = $scanned;
        $parts = wp_parse_url( $candidate );
        if ( is_array( $parts ) && ! empty( $parts['path'] ) ) {
            $path = trim( (string) $parts['path'], '/' );
            if ( $path !== '' ) {
                $candidate = basename( $path );
            }
        }
        $candidate = preg_replace( '/\.(png|jpg|jpeg|pdf)$/i', '', $candidate );
        $candidate = preg_replace( '/-tn$/i', '', $candidate );
        $candidate = ltrim( (string) $candidate, '#' );

        global $wpdb;
        $ticket_id = absint( $wpdb->get_var( $wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} ev ON ev.post_id = pm.post_id
             WHERE pm.meta_key = %s AND pm.meta_value = %s
               AND ev.meta_key = %s AND ev.meta_value = %s
             LIMIT 1",
            'eventosapp_ticketID',
            $candidate,
            '_eventosapp_ticket_evento_id',
            (string) $event_id
        ) ) );

        if ( ! $ticket_id ) {
            return new WP_Error( 'ticket_not_found', 'Ticket no encontrado para este evento.', [ 'status' => 404 ] );
        }

        return [ 'ticket_id' => $ticket_id, 'qr_type' => 'legacy', 'qr_type_label' => 'QR Legacy' ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_attendee_payload' ) ) {
    function eventosapp_mobile_advanced_attendee_payload( $ticket_id, $event_id ) {
        $ticket_id = absint( $ticket_id );
        $event_id  = absint( $event_id );

        if ( function_exists( 'eventosapp_mobile_offline_ticket_payload' ) ) {
            $payload = eventosapp_mobile_offline_ticket_payload( $ticket_id, $event_id );
            if ( is_array( $payload ) && ! empty( $payload['attendee'] ) ) {
                return $payload;
            }
        }

        $first = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
        $last  = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );
        return [
            'ticket_id'       => $ticket_id,
            'lookup_keys'     => function_exists( 'eventosapp_mobile_offline_ticket_lookup_keys' )
                ? eventosapp_mobile_offline_ticket_lookup_keys( $ticket_id, $event_id )
                : [],
            'checked_in_days' => function_exists( 'eventosapp_mobile_offline_checked_days' )
                ? eventosapp_mobile_offline_checked_days( $ticket_id )
                : [],
            'attendee'        => [
                'ticket_id'   => $ticket_id,
                'event_id'    => $event_id,
                'first_name'  => sanitize_text_field( $first ),
                'last_name'   => sanitize_text_field( $last ),
                'full_name'   => sanitize_text_field( trim( $first . ' ' . $last ) ),
                'company'     => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_empresa', true ) ),
                'designation' => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_cargo', true ) ),
                'email'       => sanitize_email( get_post_meta( $ticket_id, '_eventosapp_asistente_email', true ) ),
                'localidad'   => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_localidad', true ) ),
            ],
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_auth_required_for_day' ) ) {
    function eventosapp_mobile_advanced_auth_required_for_day( $event_id, $day ) {
        return function_exists( 'eventosapp_double_auth_requires_code_for_day' )
            ? (bool) eventosapp_double_auth_requires_code_for_day( absint( $event_id ), sanitize_text_field( (string) $day ) )
            : true;
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_auth_code_map' ) ) {
    function eventosapp_mobile_advanced_auth_code_map( $ticket_id, $event_id ) {
        $ticket_id = absint( $ticket_id );
        $event_id  = absint( $event_id );
        $days      = eventosapp_mobile_advanced_event_days( $event_id );
        $map       = [];

        $mode = sanitize_key( (string) get_post_meta( $event_id, '_eventosapp_ticket_double_auth_mode', true ) );
        if ( $mode === 'all_days' && function_exists( 'eventosapp_get_all_ticket_day_codes' ) ) {
            $all = eventosapp_get_all_ticket_day_codes( $ticket_id, $event_id );
            if ( is_array( $all ) ) {
                foreach ( $days as $day ) {
                    $row = isset( $all[ $day ] ) && is_array( $all[ $day ] ) ? $all[ $day ] : [];
                    $code = isset( $row['code'] ) ? trim( (string) $row['code'] ) : '';
                    if ( preg_match( '/^\d{5}$/', $code ) ) {
                        $map[ $day ] = $code;
                    }
                }
            }
            return $map;
        }

        $code = function_exists( 'eventosapp_get_ticket_auth_code' )
            ? trim( (string) eventosapp_get_ticket_auth_code( $ticket_id ) )
            : '';
        if ( preg_match( '/^\d{5}$/', $code ) ) {
            foreach ( $days as $day ) {
                $map[ $day ] = $code;
            }
        }
        return $map;
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_auth_proof_secret' ) ) {
    function eventosapp_mobile_advanced_auth_proof_secret() {
        return wp_salt( 'auth' ) . '|eventosapp-mobile-advanced-qr-v1';
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_auth_verifier' ) ) {
    function eventosapp_mobile_advanced_auth_verifier( $event_id, $ticket_id, $day, $code ) {
        $event_id  = absint( $event_id );
        $ticket_id = absint( $ticket_id );
        $day       = sanitize_text_field( (string) $day );
        $code      = trim( (string) $code );
        if ( ! $event_id || ! $ticket_id || ! preg_match( '/^\d{5}$/', $code ) ) {
            return null;
        }

        try {
            $salt = bin2hex( random_bytes( 16 ) );
        } catch ( Throwable $e ) {
            $salt = wp_generate_password( 32, false, false );
        }
        $iterations = 120000;
        $verifier   = hash_pbkdf2( 'sha256', $code, $salt, $iterations, 64, false );
        $payload    = implode( '|', [ $event_id, $ticket_id, $day, $salt, $verifier, $iterations ] );
        $proof      = hash_hmac( 'sha256', $payload, eventosapp_mobile_advanced_auth_proof_secret() );

        return [
            'salt'       => $salt,
            'hash'       => $verifier,
            'iterations' => $iterations,
            'proof'      => $proof,
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_verify_snapshot_proof' ) ) {
    function eventosapp_mobile_advanced_verify_snapshot_proof( $event_id, $ticket_id, $day, $salt, $verifier, $iterations, $proof ) {
        $event_id   = absint( $event_id );
        $ticket_id  = absint( $ticket_id );
        $day        = sanitize_text_field( (string) $day );
        $salt       = sanitize_text_field( (string) $salt );
        $verifier   = strtolower( sanitize_text_field( (string) $verifier ) );
        $iterations = absint( $iterations );
        $proof      = strtolower( sanitize_text_field( (string) $proof ) );

        if (
            ! $event_id || ! $ticket_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ||
            ! preg_match( '/^[a-zA-Z0-9]{16,128}$/', $salt ) ||
            ! preg_match( '/^[0-9a-f]{64}$/', $verifier ) ||
            $iterations < 50000 || $iterations > 500000 ||
            ! preg_match( '/^[0-9a-f]{64}$/', $proof )
        ) {
            return false;
        }

        $payload  = implode( '|', [ $event_id, $ticket_id, $day, $salt, $verifier, $iterations ] );
        $expected = hash_hmac( 'sha256', $payload, eventosapp_mobile_advanced_auth_proof_secret() );
        return hash_equals( $expected, $proof );
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_ticket_payload' ) ) {
    function eventosapp_mobile_advanced_ticket_payload( $ticket_id, $event_id, $modules ) {
        $ticket_id = absint( $ticket_id );
        $event_id  = absint( $event_id );
        $modules   = array_values( array_intersect( (array) $modules, [ 'qr_localidad', 'qr_session', 'qr_double_auth' ] ) );
        if ( ! $ticket_id || ! $event_id || ! $modules ) {
            return null;
        }

        $base = eventosapp_mobile_advanced_attendee_payload( $ticket_id, $event_id );
        if ( ! is_array( $base ) || empty( $base['ticket_id'] ) ) {
            return null;
        }

        $payload = [
            'ticket_id'       => $ticket_id,
            'attendee'        => $base['attendee'] ?? [],
            'lookup_keys'     => $base['lookup_keys'] ?? [],
            'checked_in_days' => $base['checked_in_days'] ?? [],
            'access_allowed'  => isset( $base['access_allowed'] ) ? (bool) $base['access_allowed'] : true,
            'access_message'  => sanitize_text_field( (string) ( $base['access_message'] ?? '' ) ),
            'payment_message' => sanitize_text_field( (string) ( $base['payment_message'] ?? '' ) ),
        ];

        if ( in_array( 'qr_session', $modules, true ) ) {
            $session_access = [];
            $session_status = get_post_meta( $ticket_id, '_eventosapp_ticket_checkin_sesiones', true );
            if ( ! is_array( $session_status ) ) {
                $session_status = [];
            }
            foreach ( eventosapp_mobile_advanced_session_names( $event_id ) as $session ) {
                $session_access[ $session ] = function_exists( 'eventosapp_ticket_tiene_acceso' )
                    ? (bool) eventosapp_ticket_tiene_acceso( $ticket_id, $session )
                    : false;
                $raw = isset( $session_status[ $session ] ) ? (string) $session_status[ $session ] : '';
                $session_status[ $session ] = in_array( $raw, [ 'checked_in', 'checked-in' ], true )
                    ? 'checked_in'
                    : 'not_checked_in';
            }
            $payload['session_access'] = $session_access;
            $payload['session_status'] = $session_status;
        }

        if ( in_array( 'qr_double_auth', $modules, true ) ) {
            $codes = eventosapp_mobile_advanced_auth_code_map( $ticket_id, $event_id );
            $required = [];
            $verifiers = [];
            foreach ( eventosapp_mobile_advanced_event_days( $event_id ) as $day ) {
                $is_required = eventosapp_mobile_advanced_auth_required_for_day( $event_id, $day );
                $required[ $day ] = $is_required;
                if ( $is_required && ! empty( $codes[ $day ] ) ) {
                    $verifier = eventosapp_mobile_advanced_auth_verifier( $event_id, $ticket_id, $day, $codes[ $day ] );
                    if ( $verifier ) {
                        $verifiers[ $day ] = $verifier;
                    }
                }
            }

            $payment_control = get_post_meta( $event_id, '_eventosapp_ticket_control_pago', true ) === '1';
            $payment_status  = get_post_meta( $ticket_id, '_eventosapp_estado_pago', true ) ?: 'no_pagado';
            $payload['double_auth'] = [
                'required_by_day' => $required,
                'verifiers'       => $verifiers,
                'payment_required'=> $payment_control && $payment_status === 'no_pagado',
                'payment_message' => $payment_control
                    ? ( $payment_status === 'no_pagado'
                        ? 'El Check-In no se puede realizar porque el ticket no ha sido pagado.'
                        : 'El ticket está en modo Pagado.' )
                    : '',
            ];
        }

        return $payload;
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_config_payload' ) ) {
    function eventosapp_mobile_advanced_config_payload( $event_id, $modules ) {
        $event_id = absint( $event_id );
        return [
            'sessions' => in_array( 'qr_session', (array) $modules, true )
                ? eventosapp_mobile_advanced_session_names( $event_id )
                : [],
            'double_auth' => [
                'enabled' => in_array( 'qr_double_auth', (array) $modules, true ),
                'mode'    => sanitize_key( (string) get_post_meta( $event_id, '_eventosapp_ticket_double_auth_mode', true ) ),
                'payment_control' => get_post_meta( $event_id, '_eventosapp_ticket_control_pago', true ) === '1',
            ],
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_event_list' ) ) {
    function eventosapp_mobile_advanced_event_list( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id || ! function_exists( 'eventosapp_mobile_app_all_event_ids' ) ) {
            return new WP_Error( 'forbidden', 'No se pudo resolver el usuario o sus eventos.', [ 'status' => 403 ] );
        }

        $events = [];
        foreach ( eventosapp_mobile_app_all_event_ids() as $event_id ) {
            $modules = eventosapp_mobile_advanced_all_modules_for_event( $event_id, $user_id );
            if ( ! $modules ) {
                continue;
            }
            $payload = function_exists( 'eventosapp_mobile_app_event_payload' )
                ? eventosapp_mobile_app_event_payload( $event_id, 'mobile' )
                : [ 'id' => $event_id, 'title' => get_the_title( $event_id ) ];
            $payload['modules'] = $modules;
            $payload['module_config'] = eventosapp_mobile_advanced_config_payload( $event_id, $modules );
            $events[] = $payload;
        }

        return function_exists( 'eventosapp_mobile_offline_no_cache_response' )
            ? eventosapp_mobile_offline_no_cache_response( [
                'api_version' => EVENTOSAPP_MOBILE_ADVANCED_QR_API_VERSION,
                'events' => $events,
            ] )
            : rest_ensure_response( [ 'api_version' => EVENTOSAPP_MOBILE_ADVANCED_QR_API_VERSION, 'events' => $events ] );
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_module_guard' ) ) {
    function eventosapp_mobile_advanced_module_guard( $event_id, $module ) {
        $event_id = absint( $event_id );
        $module   = sanitize_key( $module );
        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return new WP_Error( 'invalid_event', 'El evento no existe.', [ 'status' => 404 ] );
        }
        if ( ! in_array( $module, eventosapp_mobile_advanced_modules_for_event( $event_id, get_current_user_id() ), true ) ) {
            return new WP_Error( 'forbidden_event', 'No tienes permiso para este módulo en el evento.', [ 'status' => 403 ] );
        }
        return true;
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_ticket_response' ) ) {
    function eventosapp_mobile_advanced_ticket_response( $ticket_id, $event_id, $lookup = [] ) {
        $base = eventosapp_mobile_advanced_attendee_payload( $ticket_id, $event_id );
        return [
            'ticket_id'     => absint( $ticket_id ),
            'event_id'      => absint( $event_id ),
            'event_name'    => sanitize_text_field( get_the_title( $event_id ) ),
            'attendee'      => $base['attendee'] ?? [],
            'qr_type'       => sanitize_key( (string) ( $lookup['qr_type'] ?? 'unknown' ) ),
            'qr_type_label' => sanitize_text_field( (string) ( $lookup['qr_type_label'] ?? 'QR' ) ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_locality_lookup' ) ) {
    function eventosapp_mobile_advanced_locality_lookup( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        $guard = eventosapp_mobile_advanced_module_guard( $event_id, 'qr_localidad' );
        if ( is_wp_error( $guard ) ) return $guard;

        $lookup = eventosapp_mobile_advanced_find_ticket( $request->get_param( 'scanned' ), $event_id );
        if ( is_wp_error( $lookup ) ) return $lookup;
        $ticket_id = absint( $lookup['ticket_id'] );
        if ( absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) !== $event_id ) {
            return new WP_Error( 'wrong_event', 'El ticket no pertenece al evento activo.', [ 'status' => 403 ] );
        }
        return rest_ensure_response( eventosapp_mobile_advanced_ticket_response( $ticket_id, $event_id, $lookup ) );
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_session_lookup' ) ) {
    function eventosapp_mobile_advanced_session_lookup( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        $guard = eventosapp_mobile_advanced_module_guard( $event_id, 'qr_session' );
        if ( is_wp_error( $guard ) ) return $guard;
        $session = trim( sanitize_text_field( (string) $request->get_param( 'session' ) ) );
        if ( $session === '' || ! in_array( $session, eventosapp_mobile_advanced_session_names( $event_id ), true ) ) {
            return new WP_Error( 'invalid_session', 'La sesión seleccionada no está disponible en este evento.', [ 'status' => 400 ] );
        }
        $today = eventosapp_mobile_advanced_today( $event_id );
        if ( ! eventosapp_mobile_advanced_is_valid_day( $event_id, $today ) ) {
            return new WP_Error( 'invalid_event_day', 'El ingreso a sesiones solo está permitido en las fechas del evento.', [ 'status' => 409 ] );
        }

        $lookup = eventosapp_mobile_advanced_find_ticket( $request->get_param( 'scanned' ), $event_id );
        if ( is_wp_error( $lookup ) ) return $lookup;
        $ticket_id = absint( $lookup['ticket_id'] );
        $has_access = function_exists( 'eventosapp_ticket_tiene_acceso' )
            ? (bool) eventosapp_ticket_tiene_acceso( $ticket_id, $session )
            : false;
        $status = get_post_meta( $ticket_id, '_eventosapp_ticket_checkin_sesiones', true );
        $status = is_array( $status ) ? $status : [];
        $already = isset( $status[ $session ] ) && in_array( (string) $status[ $session ], [ 'checked_in', 'checked-in' ], true );

        $response = eventosapp_mobile_advanced_ticket_response( $ticket_id, $event_id, $lookup );
        $response['session'] = $session;
        $response['has_access'] = $has_access;
        $response['already'] = $already;
        return rest_ensure_response( $response );
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_append_session_log' ) ) {
    function eventosapp_mobile_advanced_append_session_log( $ticket_id, $event_id, $session, $day, $client_id = '', $qr_type = 'unknown', $qr_type_label = 'QR' ) {
        $log = get_post_meta( $ticket_id, '_eventosapp_checkin_log', true );
        $log = is_string( $log ) ? maybe_unserialize( $log ) : $log;
        $log = is_array( $log ) ? $log : [];
        $user = wp_get_current_user();
        try {
            $dt = new DateTime( 'now', eventosapp_mobile_advanced_timezone( $event_id ) );
        } catch ( Throwable $e ) {
            $dt = new DateTime( 'now', wp_timezone() );
        }
        $entry = [
            'fecha'         => $dt->format( 'Y-m-d' ),
            'hora'          => $dt->format( 'H:i:s' ),
            'dia'           => sanitize_text_field( $day ),
            'status'        => 'session_checked_in',
            'session'       => sanitize_text_field( $session ),
            'origen'        => $client_id ? 'QR Sesión Offline' : 'QR Sesión Android',
            'qr_type'       => sanitize_key( $qr_type ),
            'qr_type_label' => sanitize_text_field( $qr_type_label ),
            'usuario'       => $user && $user->exists() ? ( $user->display_name . ' (' . $user->user_email . ')' ) : 'Sistema',
        ];
        if ( $client_id ) {
            $entry['client_operation_id'] = sanitize_text_field( $client_id );
        }
        $log[] = $entry;
        update_post_meta( $ticket_id, '_eventosapp_checkin_log', $log );
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_session_checkin' ) ) {
    function eventosapp_mobile_advanced_session_checkin( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        $guard = eventosapp_mobile_advanced_module_guard( $event_id, 'qr_session' );
        if ( is_wp_error( $guard ) ) return $guard;
        $ticket_id = absint( $request->get_param( 'ticket_id' ) );
        $session = trim( sanitize_text_field( (string) $request->get_param( 'session' ) ) );
        if ( ! $ticket_id || absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) !== $event_id ) {
            return new WP_Error( 'invalid_ticket', 'El ticket no pertenece a este evento.', [ 'status' => 404 ] );
        }
        if ( $session === '' || ! in_array( $session, eventosapp_mobile_advanced_session_names( $event_id ), true ) ) {
            return new WP_Error( 'invalid_session', 'La sesión seleccionada no está disponible.', [ 'status' => 400 ] );
        }
        $today = eventosapp_mobile_advanced_today( $event_id );
        if ( ! eventosapp_mobile_advanced_is_valid_day( $event_id, $today ) ) {
            return new WP_Error( 'invalid_event_day', 'El ingreso a sesiones solo está permitido en las fechas del evento.', [ 'status' => 409 ] );
        }
        if ( ! function_exists( 'eventosapp_ticket_tiene_acceso' ) || ! eventosapp_ticket_tiene_acceso( $ticket_id, $session ) ) {
            return new WP_Error( 'session_forbidden', 'El ticket no tiene acceso a esta sesión.', [ 'status' => 403 ] );
        }

        $status = get_post_meta( $ticket_id, '_eventosapp_ticket_checkin_sesiones', true );
        $status = is_array( $status ) ? $status : [];
        $already = isset( $status[ $session ] ) && in_array( (string) $status[ $session ], [ 'checked_in', 'checked-in' ], true );
        if ( ! $already ) {
            $status[ $session ] = 'checked_in';
            update_post_meta( $ticket_id, '_eventosapp_ticket_checkin_sesiones', $status );
            eventosapp_mobile_advanced_append_session_log( $ticket_id, $event_id, $session, $today );
        }
        return rest_ensure_response( [
            'accepted' => true,
            'already'  => $already,
            'message'  => $already ? 'El ingreso a esta sesión ya estaba registrado.' : 'Ingreso a la sesión registrado correctamente.',
            'ticket_id'=> $ticket_id,
            'session'  => $session,
            'day'      => $today,
        ] );
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_double_auth_lookup' ) ) {
    function eventosapp_mobile_advanced_double_auth_lookup( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        $guard = eventosapp_mobile_advanced_module_guard( $event_id, 'qr_double_auth' );
        if ( is_wp_error( $guard ) ) return $guard;
        $today = eventosapp_mobile_advanced_today( $event_id );
        if ( ! eventosapp_mobile_advanced_is_valid_day( $event_id, $today ) ) {
            return new WP_Error( 'invalid_event_day', 'El check-in solo está permitido en las fechas del evento.', [ 'status' => 409 ] );
        }
        $lookup = eventosapp_mobile_advanced_find_ticket( $request->get_param( 'scanned' ), $event_id );
        if ( is_wp_error( $lookup ) ) return $lookup;
        $ticket_id = absint( $lookup['ticket_id'] );
        $status = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
        $status = is_string( $status ) ? maybe_unserialize( $status ) : $status;
        $status = is_array( $status ) ? $status : [];
        $already = isset( $status[ $today ] ) && in_array( (string) $status[ $today ], [ 'checked_in', 'checked-in' ], true );
        $payment_control = get_post_meta( $event_id, '_eventosapp_ticket_control_pago', true ) === '1';
        $payment_status = get_post_meta( $ticket_id, '_eventosapp_estado_pago', true ) ?: 'no_pagado';

        $response = eventosapp_mobile_advanced_ticket_response( $ticket_id, $event_id, $lookup );
        $response['day'] = $today;
        $response['already'] = $already;
        $response['auth_required'] = eventosapp_mobile_advanced_auth_required_for_day( $event_id, $today );
        $response['payment_required'] = $payment_control && $payment_status === 'no_pagado';
        $response['payment_message'] = $payment_control
            ? ( $payment_status === 'no_pagado' ? 'El ticket debe estar pagado antes de permitir el ingreso.' : 'El ticket está en modo Pagado.' )
            : '';
        return rest_ensure_response( $response );
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_register_general_checkin' ) ) {
    function eventosapp_mobile_advanced_register_general_checkin( $ticket_id, $event_id, $day, $origin, $qr_type, $qr_type_label, $client_id = '' ) {
        $ticket_id = absint( $ticket_id );
        $event_id  = absint( $event_id );
        $day       = sanitize_text_field( (string) $day );
        $qr_type   = sanitize_key( $qr_type ?: 'unknown' );
        $qr_type_label = sanitize_text_field( $qr_type_label ?: 'QR' );

        if ( function_exists( 'eventosapp_register_ticket_checkin' ) ) {
            $args = [
                'day'           => $day,
                'origen'        => sanitize_text_field( $origin ),
                'qr_type'       => $qr_type,
                'qr_type_label' => $qr_type_label,
            ];
            if ( $client_id ) {
                $args['client_operation_id'] = sanitize_text_field( $client_id );
            }
            $checkin = eventosapp_register_ticket_checkin( $ticket_id, 'presencial', $args );
            if ( ! is_array( $checkin ) || empty( $checkin['ok'] ) ) {
                return new WP_Error(
                    'checkin_failed',
                    is_array( $checkin ) && ! empty( $checkin['message'] ) ? sanitize_text_field( $checkin['message'] ) : 'No fue posible registrar el check-in.',
                    [ 'status' => 500 ]
                );
            }
            return [
                'already' => ! empty( $checkin['already_checked'] ),
                'day' => ! empty( $checkin['day'] ) ? sanitize_text_field( $checkin['day'] ) : $day,
            ];
        }

        $status = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
        $status = is_string( $status ) ? maybe_unserialize( $status ) : $status;
        $status = is_array( $status ) ? $status : [];
        $already = isset( $status[ $day ] ) && in_array( (string) $status[ $day ], [ 'checked_in', 'checked-in' ], true );
        if ( ! $already ) {
            $status[ $day ] = 'checked_in';
            update_post_meta( $ticket_id, '_eventosapp_checkin_status', $status );
            $log = get_post_meta( $ticket_id, '_eventosapp_checkin_log', true );
            $log = is_string( $log ) ? maybe_unserialize( $log ) : $log;
            $log = is_array( $log ) ? $log : [];
            $entry = [
                'fecha' => current_time( 'Y-m-d' ),
                'hora' => current_time( 'H:i:s' ),
                'dia' => $day,
                'status' => 'checked_in',
                'origen' => sanitize_text_field( $origin ),
                'qr_type' => $qr_type,
                'qr_type_label' => $qr_type_label,
            ];
            if ( $client_id ) $entry['client_operation_id'] = sanitize_text_field( $client_id );
            $log[] = $entry;
            update_post_meta( $ticket_id, '_eventosapp_checkin_log', $log );
        }
        return [ 'already' => $already, 'day' => $day ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_double_auth_checkin' ) ) {
    function eventosapp_mobile_advanced_double_auth_checkin( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        $guard = eventosapp_mobile_advanced_module_guard( $event_id, 'qr_double_auth' );
        if ( is_wp_error( $guard ) ) return $guard;
        $ticket_id = absint( $request->get_param( 'ticket_id' ) );
        $auth_code = trim( sanitize_text_field( (string) $request->get_param( 'auth_code' ) ) );
        $qr_type = sanitize_key( (string) $request->get_param( 'qr_type' ) );
        $qr_type_label = sanitize_text_field( (string) $request->get_param( 'qr_type_label' ) );
        if ( ! $ticket_id || absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) !== $event_id ) {
            return new WP_Error( 'invalid_ticket', 'El ticket no pertenece a este evento.', [ 'status' => 404 ] );
        }
        $today = eventosapp_mobile_advanced_today( $event_id );
        if ( ! eventosapp_mobile_advanced_is_valid_day( $event_id, $today ) ) {
            return new WP_Error( 'invalid_event_day', 'El check-in solo está permitido en las fechas del evento.', [ 'status' => 409 ] );
        }
        $payment_control = get_post_meta( $event_id, '_eventosapp_ticket_control_pago', true ) === '1';
        $payment_status = get_post_meta( $ticket_id, '_eventosapp_estado_pago', true ) ?: 'no_pagado';
        if ( $payment_control && $payment_status === 'no_pagado' ) {
            return new WP_Error( 'payment_required', 'El Check-In no se puede realizar porque el ticket no ha sido pagado.', [ 'status' => 403 ] );
        }
        $required = eventosapp_mobile_advanced_auth_required_for_day( $event_id, $today );
        if ( $required ) {
            if ( function_exists( 'eventosapp_qr_double_auth_attempt_state' ) ) {
                $attempt_state = eventosapp_qr_double_auth_attempt_state( $ticket_id );
                if ( ! empty( $attempt_state['locked_until'] ) && $attempt_state['locked_until'] > time() ) {
                    $remaining = max( 1, absint( $attempt_state['locked_until'] ) - time() );
                    return new WP_Error(
                        'auth_rate_limited',
                        'Se alcanzó el límite temporal de intentos para este ticket. Intenta nuevamente en ' . ceil( $remaining / 60 ) . ' minuto(s).',
                        [ 'status' => 429, 'retry_after' => $remaining ]
                    );
                }
            }

            if ( ! preg_match( '/^\d{5}$/', $auth_code ) ) {
                return new WP_Error( 'auth_invalid', 'Ingresa exactamente los 5 dígitos del código de verificación.', [ 'status' => 400 ] );
            }
            if ( ! function_exists( 'eventosapp_validate_auth_code' ) ) {
                return new WP_Error( 'auth_unavailable', 'Sistema de autenticación no disponible.', [ 'status' => 500 ] );
            }
            if ( ! eventosapp_validate_auth_code( $ticket_id, $auth_code ) ) {
                $remaining_attempts = null;
                if ( function_exists( 'eventosapp_qr_double_auth_record_failed_attempt' ) ) {
                    $attempt_state = eventosapp_qr_double_auth_record_failed_attempt( $ticket_id );
                    $remaining_attempts = max( 0, 8 - absint( $attempt_state['count'] ?? 0 ) );
                }
                $message = 'Código de verificación incorrecto. Verifica los 5 dígitos e intenta nuevamente.';
                if ( $remaining_attempts !== null && $remaining_attempts > 0 && $remaining_attempts <= 3 ) {
                    $message .= ' Intentos disponibles antes del bloqueo temporal: ' . $remaining_attempts . '.';
                }
                return new WP_Error( 'auth_invalid', $message, [ 'status' => 403, 'attempts_remaining' => $remaining_attempts ] );
            }
            if ( function_exists( 'eventosapp_qr_double_auth_clear_attempts' ) ) {
                eventosapp_qr_double_auth_clear_attempts( $ticket_id );
            }
        }

        $result = eventosapp_mobile_advanced_register_general_checkin(
            $ticket_id,
            $event_id,
            $today,
            'QR Doble Autenticación Android',
            $qr_type ?: 'unknown',
            $qr_type_label ?: 'QR'
        );
        if ( is_wp_error( $result ) ) return $result;

        $response = eventosapp_mobile_advanced_ticket_response( $ticket_id, $event_id, [
            'qr_type' => $qr_type ?: 'unknown',
            'qr_type_label' => $qr_type_label ?: 'QR',
        ] );
        $response['accepted'] = true;
        $response['already'] = ! empty( $result['already'] );
        $response['day'] = $result['day'];
        $response['auth_required'] = $required;
        $response['payment_message'] = $payment_control ? 'El ticket está en modo Pagado.' : '';
        return rest_ensure_response( $response );
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_find_client_log' ) ) {
    function eventosapp_mobile_advanced_find_client_log( $ticket_id, $client_id ) {
        if ( function_exists( 'eventosapp_mobile_offline_find_client_log' ) && eventosapp_mobile_offline_find_client_log( $ticket_id, $client_id ) ) {
            return true;
        }
        $log = get_post_meta( absint( $ticket_id ), '_eventosapp_checkin_log', true );
        $log = is_string( $log ) ? maybe_unserialize( $log ) : $log;
        if ( ! is_array( $log ) ) return false;
        foreach ( $log as $entry ) {
            if ( is_array( $entry ) && ! empty( $entry['client_operation_id'] ) && hash_equals( (string) $entry['client_operation_id'], (string) $client_id ) ) {
                return true;
            }
        }
        return false;
    }
}

if ( ! function_exists( 'eventosapp_mobile_advanced_sync' ) ) {
    function eventosapp_mobile_advanced_sync( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return new WP_Error( 'invalid_event', 'El evento no existe.', [ 'status' => 404 ] );
        }
        $items = $request->get_param( 'items' );
        if ( ! is_array( $items ) ) {
            return new WP_Error( 'invalid_items', 'La sincronización requiere una lista de operaciones.', [ 'status' => 400 ] );
        }
        if ( count( $items ) > 500 ) {
            return new WP_Error( 'too_many_items', 'Envía máximo 500 operaciones por lote.', [ 'status' => 413 ] );
        }

        $results = [];
        foreach ( $items as $raw ) {
            $item = is_array( $raw ) ? $raw : [];
            $client_id = sanitize_text_field( (string) ( $item['client_id'] ?? '' ) );
            $module = sanitize_key( (string) ( $item['module'] ?? '' ) );
            $ticket_id = absint( $item['ticket_id'] ?? 0 );
            $day = sanitize_text_field( (string) ( $item['checkin_date'] ?? '' ) );
            $qr_type = sanitize_key( (string) ( $item['qr_type'] ?? 'unknown' ) );
            $qr_type_label = sanitize_text_field( (string) ( $item['qr_type_label'] ?? 'QR' ) );

            $result = [ 'client_id' => $client_id, 'module' => $module, 'accepted' => false, 'already' => false, 'message' => '' ];
            if ( $client_id === '' || strlen( $client_id ) > 100 || ! $ticket_id || ! in_array( $module, [ 'qr_session', 'qr_double_auth' ], true ) ) {
                $result['message'] = 'Operación offline inválida.';
                $results[] = $result;
                continue;
            }
            if ( absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) !== $event_id ) {
                $result['message'] = 'El ticket no pertenece a este evento.';
                $results[] = $result;
                continue;
            }
            $guard = eventosapp_mobile_advanced_module_guard( $event_id, $module );
            if ( is_wp_error( $guard ) ) {
                $result['message'] = $guard->get_error_message();
                $results[] = $result;
                continue;
            }
            if ( ! eventosapp_mobile_advanced_is_valid_day( $event_id, $day ) ) {
                $result['message'] = 'La fecha de la operación no pertenece al evento.';
                $results[] = $result;
                continue;
            }
            if ( eventosapp_mobile_advanced_find_client_log( $ticket_id, $client_id ) ) {
                $result['accepted'] = true;
                $result['already'] = true;
                $result['message'] = 'La operación ya había sido sincronizada.';
                $results[] = $result;
                continue;
            }

            if ( $module === 'qr_session' ) {
                $session = trim( sanitize_text_field( (string) ( $item['session'] ?? '' ) ) );
                if ( $session === '' || ! in_array( $session, eventosapp_mobile_advanced_session_names( $event_id ), true ) ) {
                    $result['message'] = 'La sesión ya no está disponible en el evento.';
                    $results[] = $result;
                    continue;
                }
                if ( ! function_exists( 'eventosapp_ticket_tiene_acceso' ) || ! eventosapp_ticket_tiene_acceso( $ticket_id, $session ) ) {
                    $result['message'] = 'El ticket no tiene acceso a esta sesión.';
                    $results[] = $result;
                    continue;
                }
                $status = get_post_meta( $ticket_id, '_eventosapp_ticket_checkin_sesiones', true );
                $status = is_array( $status ) ? $status : [];
                $already = isset( $status[ $session ] ) && in_array( (string) $status[ $session ], [ 'checked_in', 'checked-in' ], true );
                if ( ! $already ) {
                    $status[ $session ] = 'checked_in';
                    update_post_meta( $ticket_id, '_eventosapp_ticket_checkin_sesiones', $status );
                    eventosapp_mobile_advanced_append_session_log( $ticket_id, $event_id, $session, $day, $client_id, $qr_type, $qr_type_label );
                }
                $result['accepted'] = true;
                $result['already'] = $already;
                $result['message'] = $already ? 'El ingreso a la sesión ya estaba registrado.' : 'Ingreso de sesión sincronizado.';
                $results[] = $result;
                continue;
            }

            $payment_control = get_post_meta( $event_id, '_eventosapp_ticket_control_pago', true ) === '1';
            $payment_status = get_post_meta( $ticket_id, '_eventosapp_estado_pago', true ) ?: 'no_pagado';
            if ( $payment_control && $payment_status === 'no_pagado' ) {
                $result['message'] = 'El ticket continúa pendiente de pago y no puede sincronizarse como ingreso.';
                $results[] = $result;
                continue;
            }

            if ( eventosapp_mobile_advanced_auth_required_for_day( $event_id, $day ) ) {
                $salt = (string) ( $item['auth_salt'] ?? '' );
                $verifier = (string) ( $item['auth_verifier'] ?? '' );
                $iterations = absint( $item['auth_iterations'] ?? 0 );
                $proof = (string) ( $item['auth_proof'] ?? '' );
                if ( ! eventosapp_mobile_advanced_verify_snapshot_proof( $event_id, $ticket_id, $day, $salt, $verifier, $iterations, $proof ) ) {
                    $result['message'] = 'La prueba offline del segundo factor no es válida.';
                    $results[] = $result;
                    continue;
                }
            }

            $checkin = eventosapp_mobile_advanced_register_general_checkin(
                $ticket_id,
                $event_id,
                $day,
                'QR Doble Autenticación Offline',
                $qr_type,
                $qr_type_label,
                $client_id
            );
            if ( is_wp_error( $checkin ) ) {
                $result['message'] = $checkin->get_error_message();
                $results[] = $result;
                continue;
            }
            $result['accepted'] = true;
            $result['already'] = ! empty( $checkin['already'] );
            $result['message'] = $result['already'] ? 'El ingreso ya estaba registrado.' : 'Check-in con doble autenticación sincronizado.';
            $results[] = $result;
        }

        return function_exists( 'eventosapp_mobile_offline_no_cache_response' )
            ? eventosapp_mobile_offline_no_cache_response( [ 'results' => $results ] )
            : rest_ensure_response( [ 'results' => $results ] );
    }
}

/**
 * Intercepta el login antes de la extensión Staff 1.1.0 para admitir usuarios
 * que tengan exclusivamente Localidad o Doble Autenticación.
 */
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
    if ( $result !== null || ! $request instanceof WP_REST_Request ) return $result;
    if ( strtoupper( $request->get_method() ) !== 'POST' || $request->get_route() !== '/eventosapp-kiosk/v1/auth/login' ) return $result;
    if ( ! function_exists( 'eventosapp_kiosk_api_create_token' ) || ! function_exists( 'eventosapp_mobile_app_all_event_ids' ) ) return $result;

    $login = sanitize_text_field( (string) $request->get_param( 'login' ) );
    $password = (string) $request->get_param( 'password' );
    $device = sanitize_text_field( (string) $request->get_param( 'device_name' ) );
    if ( $login === '' || $password === '' ) {
        return new WP_Error( 'missing_credentials', 'Escribe el usuario y la contraseña.', [ 'status' => 400 ] );
    }

    $rate_key = function_exists( 'eventosapp_kiosk_api_login_rate_key' )
        ? eventosapp_kiosk_api_login_rate_key( $login )
        : 'evapp_mobile_login_' . md5( strtolower( $login ) . '|' . ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
    $attempts = absint( get_transient( $rate_key ) );
    if ( $attempts >= 8 ) {
        return new WP_Error( 'too_many_attempts', 'Demasiados intentos. Espera 15 minutos y vuelve a intentar.', [ 'status' => 429 ] );
    }

    $user = wp_authenticate( $login, $password );
    if ( is_wp_error( $user ) ) {
        set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
        return new WP_Error( 'invalid_credentials', 'Usuario o contraseña incorrectos.', [ 'status' => 401 ] );
    }
    delete_transient( $rate_key );
    wp_set_current_user( $user->ID );

    $counts = [ 'kiosk' => 0, 'staff_qr' => 0, 'qr_localidad' => 0, 'qr_session' => 0, 'qr_double_auth' => 0 ];
    foreach ( eventosapp_mobile_app_all_event_ids() as $event_id ) {
        foreach ( eventosapp_mobile_advanced_all_modules_for_event( $event_id, $user->ID ) as $module ) {
            if ( isset( $counts[ $module ] ) ) $counts[ $module ]++;
        }
    }
    if ( array_sum( $counts ) === 0 ) {
        return new WP_Error( 'forbidden', 'Tu usuario no tiene módulos móviles autorizados.', [ 'status' => 403 ] );
    }

    $token = eventosapp_kiosk_api_create_token( $user->ID, $device );
    if ( is_wp_error( $token ) ) return $token;
    $user_payload = function_exists( 'eventosapp_kiosk_api_user_payload' )
        ? eventosapp_kiosk_api_user_payload( $user )
        : [ 'id' => absint( $user->ID ), 'display_name' => sanitize_text_field( $user->display_name ), 'email' => sanitize_email( $user->user_email ) ];

    return rest_ensure_response( [
        'token' => $token['token'],
        'expires_at' => gmdate( 'c', $token['expires_at'] ),
        'user' => $user_payload,
        'capabilities' => [
            'kiosk' => $counts['kiosk'] > 0,
            'kiosk_event_count' => $counts['kiosk'],
            'staff_qr' => $counts['staff_qr'] > 0,
            'staff_event_count' => $counts['staff_qr'],
            'qr_localidad' => $counts['qr_localidad'] > 0,
            'qr_localidad_event_count' => $counts['qr_localidad'],
            'qr_session' => $counts['qr_session'] > 0,
            'qr_session_event_count' => $counts['qr_session'],
            'qr_double_auth' => $counts['qr_double_auth'] > 0,
            'qr_double_auth_event_count' => $counts['qr_double_auth'],
            'mobile_api' => defined( 'EVENTOSAPP_MOBILE_APP_API_VERSION' ) ? EVENTOSAPP_MOBILE_APP_API_VERSION : '1.1.0',
            'advanced_qr_api' => EVENTOSAPP_MOBILE_ADVANCED_QR_API_VERSION,
        ],
    ] );
}, 4, 3 );

add_action( 'rest_api_init', function () {
    $permission = function_exists( 'eventosapp_mobile_app_permission' ) ? 'eventosapp_mobile_app_permission' : '__return_false';

    register_rest_route( 'eventosapp-kiosk/v1', '/mobile/events', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'eventosapp_mobile_advanced_event_list',
        'permission_callback' => $permission,
    ] );

    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\d+)/qr-localidad/lookup', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'eventosapp_mobile_advanced_locality_lookup',
        'permission_callback' => $permission,
        'args' => [ 'event_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
    ] );

    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\d+)/qr-session/lookup', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'eventosapp_mobile_advanced_session_lookup',
        'permission_callback' => $permission,
        'args' => [ 'event_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
    ] );

    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\d+)/qr-session/checkin', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'eventosapp_mobile_advanced_session_checkin',
        'permission_callback' => $permission,
        'args' => [ 'event_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
    ] );

    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\d+)/qr-double-auth/lookup', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'eventosapp_mobile_advanced_double_auth_lookup',
        'permission_callback' => $permission,
        'args' => [ 'event_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
    ] );

    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\d+)/qr-double-auth/checkin', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'eventosapp_mobile_advanced_double_auth_checkin',
        'permission_callback' => $permission,
        'args' => [ 'event_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
    ] );

    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\d+)/advanced-qr/offline-sync', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'eventosapp_mobile_advanced_sync',
        'permission_callback' => $permission,
        'args' => [ 'event_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
    ] );
} );
