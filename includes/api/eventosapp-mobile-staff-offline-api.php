<?php
/**
 * EventosApp – API offline para Check-in QR Android 2.7.0+
 *
 * Extiende la API móvil Staff existente con descarga de snapshot y
 * sincronización idempotente de ingresos registrados sin conexión.
 *
 * Debe cargarse después de:
 *   includes/api/eventosapp-mobile-staff-checkin-api.php
 *
 * Rutas:
 * - GET  /eventosapp-kiosk/v1/staff/events/{event_id}/offline-snapshot
 * - POST /eventosapp-kiosk/v1/staff/events/{event_id}/offline-sync
 *
 * No sustituye el check-in online ni modifica Kiosko, escarapelas o impresión.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'EVENTOSAPP_MOBILE_OFFLINE_API_VERSION' ) ) {
    define( 'EVENTOSAPP_MOBILE_OFFLINE_API_VERSION', '1.0.0' );
}

if ( ! function_exists( 'eventosapp_mobile_offline_normalize_scan' ) ) {
    function eventosapp_mobile_offline_normalize_scan( $value ) {
        $value = str_replace( [ "\0", "\r", "\n" ], '', (string) $value );
        return trim( $value );
    }
}

if ( ! function_exists( 'eventosapp_mobile_offline_lookup_key' ) ) {
    function eventosapp_mobile_offline_lookup_key( $value, $type = 'unknown', $type_label = 'QR' ) {
        $value = eventosapp_mobile_offline_normalize_scan( $value );
        if ( $value === '' ) {
            return null;
        }

        return [
            'hash'       => hash( 'sha256', $value ),
            'type'       => sanitize_key( $type ?: 'unknown' ),
            'type_label' => sanitize_text_field( $type_label ?: 'QR' ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_offline_ticket_lookup_keys' ) ) {
    /**
     * Construye únicamente formas de QR que el resolvedor online actual acepta.
     * Se entregan hashes SHA-256, nunca el valor QR en texto claro.
     */
    function eventosapp_mobile_offline_ticket_lookup_keys( $ticket_id, $event_id ) {
        $ticket_id = absint( $ticket_id );
        $event_id  = absint( $event_id );
        if ( ! $ticket_id || ! $event_id ) {
            return [];
        }

        $keys = [];
        $add  = static function ( $value, $type, $label ) use ( &$keys ) {
            $key = eventosapp_mobile_offline_lookup_key( $value, $type, $label );
            if ( $key ) {
                $keys[ $key['hash'] ] = $key;
            }
        };

        $use_preprinted = get_post_meta( $event_id, '_eventosapp_ticket_use_preprinted_qr', true ) === '1';
        if ( $use_preprinted ) {
            $preprinted = preg_replace( '/\D+/', '', (string) get_post_meta( $ticket_id, 'eventosapp_ticket_preprintedID', true ) );
            if ( $preprinted !== '' ) {
                $add( $preprinted, 'preprinted', 'QR Preimpreso' );
            }
        } else {
            $public_code = trim( (string) get_post_meta( $ticket_id, 'eventosapp_ticketID', true ) );
            if ( $public_code !== '' ) {
                $add( $public_code, 'legacy', 'QR Legacy' );
            }
        }

        $public_code = trim( (string) get_post_meta( $ticket_id, 'eventosapp_ticketID', true ) );
        if ( $public_code !== '' ) {
            $simple_types = [
                'email'         => [ 'email', 'Email' ],
                'google_wallet' => [ 'gwallet', 'Google Wallet' ],
                'apple_wallet'  => [ 'awallet', 'Apple Wallet' ],
                'pdf'           => [ 'pdf', 'PDF Impreso' ],
                'whatsapp'      => [ 'whatsapp', 'WhatsApp' ],
            ];

            foreach ( $simple_types as $type => $data ) {
                if ( get_post_meta( $ticket_id, '_eventosapp_qr_' . $type, true ) ) {
                    $add( $public_code . '-' . $data[0], $type, $data[1] );
                }
            }
        }

        $security_code = trim( (string) get_post_meta( $ticket_id, '_eventosapp_badge_security_code', true ) );
        if ( $public_code !== '' && $security_code !== '' ) {
            $event_value = $event_id . '-ticketid=' . $public_code . '-' . $security_code;

            // Forma exacta generada actualmente por generate_badge_qr().
            $badge_current = add_query_arg(
                [ 'event' => $event_value ],
                trailingslashit( get_site_url() ) . 'networking/global'
            );
            $add( $badge_current, 'badge', 'Escarapela Impresa' );

            // Compatibilidad con badges históricos generados con barra final.
            $badge_legacy = home_url() . '/networking/global/?event=' . rawurlencode( $event_value );
            $add( $badge_legacy, 'badge', 'Escarapela Impresa' );
        }

        return array_values( $keys );
    }
}

if ( ! function_exists( 'eventosapp_mobile_offline_checked_days' ) ) {
    function eventosapp_mobile_offline_checked_days( $ticket_id ) {
        $status = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
        if ( is_string( $status ) ) {
            $status = maybe_unserialize( $status );
        }
        if ( ! is_array( $status ) ) {
            return [];
        }

        $days = [];
        foreach ( $status as $day => $value ) {
            if ( $value === 'checked_in' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day ) ) {
                $days[] = sanitize_text_field( $day );
            }
        }
        sort( $days );
        return array_values( array_unique( $days ) );
    }
}

if ( ! function_exists( 'eventosapp_mobile_offline_ticket_payload' ) ) {
    function eventosapp_mobile_offline_ticket_payload( $ticket_id, $event_id ) {
        $ticket_id = absint( $ticket_id );
        $event_id  = absint( $event_id );

        if ( ! $ticket_id || ! $event_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
            return null;
        }
        if ( absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) !== $event_id ) {
            return null;
        }

        $post_status = get_post_status( $ticket_id );
        if ( in_array( $post_status, [ 'trash', 'auto-draft', 'inherit' ], true ) ) {
            return null;
        }

        $access_allowed  = true;
        $access_message  = '';
        $payment_message = '';

        if ( function_exists( 'eventosapp_ticket_is_virtual' ) && eventosapp_ticket_is_virtual( $ticket_id ) ) {
            $access_allowed = false;
            $access_message = 'El ticket es virtual y no admite check-in presencial.';
        }

        if ( $access_allowed && get_post_meta( $event_id, '_eventosapp_ticket_control_pago', true ) === '1' ) {
            $payment_status = get_post_meta( $ticket_id, '_eventosapp_estado_pago', true ) ?: 'no_pagado';
            if ( $payment_status === 'no_pagado' ) {
                $access_allowed = false;
                $access_message = 'El Check-in no se puede realizar porque el ticket no ha sido pagado.';
            } else {
                $payment_message = 'El ticket está marcado como pagado.';
            }
        }

        $first = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
        $last  = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );

        return [
            'ticket_id'       => $ticket_id,
            'access_allowed'  => $access_allowed,
            'access_message'  => $access_message,
            'payment_message' => $payment_message,
            'checked_in_days' => eventosapp_mobile_offline_checked_days( $ticket_id ),
            'lookup_keys'     => eventosapp_mobile_offline_ticket_lookup_keys( $ticket_id, $event_id ),
            'attendee'        => [
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
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_offline_no_cache_response' ) ) {
    function eventosapp_mobile_offline_no_cache_response( $data ) {
        $response = rest_ensure_response( $data );
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'Pragma', 'no-cache' );
        return $response;
    }
}

if ( ! function_exists( 'eventosapp_mobile_offline_snapshot' ) ) {
    function eventosapp_mobile_offline_snapshot( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        $user_id  = get_current_user_id();
        $page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
        $per_page = min( 500, max( 1, absint( $request->get_param( 'per_page' ) ?: 500 ) ) );

        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return new WP_Error( 'invalid_event', 'El evento no existe.', [ 'status' => 404 ] );
        }
        if ( ! function_exists( 'eventosapp_mobile_app_user_can_feature_in_event' ) ||
             ! eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user_id, 'qr' ) ) {
            return new WP_Error( 'forbidden_event', 'No tienes permiso de Check-in con QR para este evento.', [ 'status' => 403 ] );
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

        $tickets = [];
        foreach ( $ticket_ids as $ticket_id ) {
            $payload = eventosapp_mobile_offline_ticket_payload( $ticket_id, $event_id );
            if ( $payload ) {
                $tickets[] = $payload;
            }
        }

        $timezone = get_post_meta( $event_id, '_eventosapp_zona_horaria', true );
        if ( ! $timezone ) {
            $timezone = wp_timezone_string() ?: 'UTC';
        }

        $event_payload = function_exists( 'eventosapp_mobile_app_event_payload' )
            ? eventosapp_mobile_app_event_payload( $event_id, 'staff_qr' )
            : [
                'id'        => $event_id,
                'title'     => sanitize_text_field( get_the_title( $event_id ) ),
                'status'    => sanitize_key( get_post_status( $event_id ) ?: '' ),
                'days'      => function_exists( 'eventosapp_get_event_days' ) ? array_values( (array) eventosapp_get_event_days( $event_id ) ) : [],
                'first_day' => '',
                'last_day'  => '',
                'logo_url'  => '',
                'modalidad' => 'presencial',
            ];

        return eventosapp_mobile_offline_no_cache_response( [
            'api_version'  => EVENTOSAPP_MOBILE_OFFLINE_API_VERSION,
            'generated_at' => gmdate( 'c' ),
            'timezone'     => sanitize_text_field( $timezone ),
            'event'        => $event_payload,
            'page'         => $page,
            'per_page'     => $per_page,
            'total'        => absint( $query->found_posts ),
            'total_pages'  => max( 1, absint( $query->max_num_pages ) ),
            'tickets'      => $tickets,
        ] );
    }
}

if ( ! function_exists( 'eventosapp_mobile_offline_find_client_log' ) ) {
    function eventosapp_mobile_offline_find_client_log( $ticket_id, $client_id ) {
        $log = get_post_meta( $ticket_id, '_eventosapp_checkin_log', true );
        if ( is_string( $log ) ) {
            $log = maybe_unserialize( $log );
        }
        if ( ! is_array( $log ) ) {
            return false;
        }

        foreach ( $log as $entry ) {
            if ( is_array( $entry ) && isset( $entry['client_operation_id'] ) && hash_equals( (string) $entry['client_operation_id'], (string) $client_id ) ) {
                return true;
            }
        }
        return false;
    }
}

if ( ! function_exists( 'eventosapp_mobile_offline_lock_key' ) ) {
    function eventosapp_mobile_offline_lock_key( $event_id, $ticket_id, $checkin_day ) {
        return 'evapp_offsync_' . md5( absint( $event_id ) . '|' . absint( $ticket_id ) . '|' . (string) $checkin_day );
    }
}

if ( ! function_exists( 'eventosapp_mobile_offline_acquire_lock' ) ) {
    function eventosapp_mobile_offline_acquire_lock( $event_id, $ticket_id, $checkin_day ) {
        $key = eventosapp_mobile_offline_lock_key( $event_id, $ticket_id, $checkin_day );
        $now = time();

        if ( add_option( $key, $now, '', false ) ) {
            return $key;
        }

        $existing = absint( get_option( $key, 0 ) );
        if ( $existing && ( $now - $existing ) > 30 ) {
            delete_option( $key );
            if ( add_option( $key, $now, '', false ) ) {
                return $key;
            }
        }

        return '';
    }
}

if ( ! function_exists( 'eventosapp_mobile_offline_release_lock' ) ) {
    function eventosapp_mobile_offline_release_lock( $key ) {
        if ( $key ) {
            delete_option( $key );
        }
    }
}

if ( ! function_exists( 'eventosapp_mobile_offline_qr_type_label' ) ) {
    function eventosapp_mobile_offline_qr_type_label( $type ) {
        $type = sanitize_key( $type );
        if ( class_exists( 'EventosApp_QR_Manager' ) && is_callable( [ 'EventosApp_QR_Manager', 'get_qr_type_label' ] ) ) {
            return sanitize_text_field( EventosApp_QR_Manager::get_qr_type_label( $type ) );
        }

        $labels = [
            'email'         => 'Email',
            'google_wallet' => 'Google Wallet',
            'apple_wallet'  => 'Apple Wallet',
            'pdf'           => 'PDF Impreso',
            'whatsapp'      => 'WhatsApp',
            'badge'         => 'Escarapela Impresa',
            'legacy'        => 'QR Legacy',
            'preprinted'    => 'QR Preimpreso',
        ];
        return $labels[ $type ] ?? 'QR';
    }
}

if ( ! function_exists( 'eventosapp_mobile_offline_sync_item' ) ) {
    function eventosapp_mobile_offline_sync_item( $event_id, $item ) {
        $event_id    = absint( $event_id );
        $client_id   = sanitize_text_field( (string) ( $item['client_id'] ?? '' ) );
        $ticket_id   = absint( $item['ticket_id'] ?? 0 );
        $checkin_day = sanitize_text_field( (string) ( $item['checkin_date'] ?? '' ) );
        $qr_type     = sanitize_key( (string) ( $item['qr_type'] ?? 'unknown' ) );
        $created_at  = substr( sanitize_text_field( (string) ( $item['created_at'] ?? '' ) ), 0, 80 );

        $result = [
            'client_id' => $client_id,
            'ticket_id' => $ticket_id,
            'accepted'  => false,
            'already'   => false,
            'message'   => '',
        ];

        if (
            ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $client_id ) ||
            ! $ticket_id ||
            ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $checkin_day )
        ) {
            $result['message'] = 'El registro offline está incompleto o es inválido.';
            return $result;
        }

        if ( get_post_type( $ticket_id ) !== 'eventosapp_ticket' || absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) !== $event_id ) {
            $result['message'] = 'El ticket ya no pertenece al evento seleccionado.';
            return $result;
        }

        if ( in_array( get_post_status( $ticket_id ), [ 'trash', 'auto-draft', 'inherit' ], true ) ) {
            $result['message'] = 'El ticket ya no está activo en EventosApp.';
            return $result;
        }

        if ( function_exists( 'eventosapp_ticket_is_virtual' ) && eventosapp_ticket_is_virtual( $ticket_id ) ) {
            $result['message'] = 'El ticket es virtual y no admite check-in presencial.';
            return $result;
        }

        if ( get_post_meta( $event_id, '_eventosapp_ticket_control_pago', true ) === '1' ) {
            $payment_status = get_post_meta( $ticket_id, '_eventosapp_estado_pago', true ) ?: 'no_pagado';
            if ( $payment_status === 'no_pagado' ) {
                $result['message'] = 'El ticket figura como no pagado en EventosApp.';
                return $result;
            }
        }

        $valid_lookup_types = array_values( array_unique( array_map(
            static function ( $key ) {
                return sanitize_key( $key['type'] ?? '' );
            },
            eventosapp_mobile_offline_ticket_lookup_keys( $ticket_id, $event_id )
        ) ) );

        if ( ! $qr_type || ! in_array( $qr_type, $valid_lookup_types, true ) ) {
            $result['message'] = 'El tipo de QR ya no es válido para este ticket.';
            return $result;
        }
        $qr_label = eventosapp_mobile_offline_qr_type_label( $qr_type );

        $days = function_exists( 'eventosapp_get_event_days' ) ? (array) eventosapp_get_event_days( $event_id ) : [];
        if ( ! in_array( $checkin_day, $days, true ) ) {
            $result['message'] = 'La fecha registrada offline no corresponde a una fecha válida del evento.';
            return $result;
        }

        $dt    = function_exists( 'eventosapp_mobile_app_event_datetime' ) ? eventosapp_mobile_app_event_datetime( $event_id ) : new DateTime( 'now', wp_timezone() );
        $today = $dt->format( 'Y-m-d' );
        if ( $checkin_day > $today ) {
            $result['message'] = 'La fecha offline está en el futuro respecto a EventosApp.';
            return $result;
        }

        if ( eventosapp_mobile_offline_find_client_log( $ticket_id, $client_id ) ) {
            $result['accepted'] = true;
            $result['already']  = true;
            $result['message']  = 'La operación offline ya había sido sincronizada.';
            return $result;
        }

        $lock_key = eventosapp_mobile_offline_acquire_lock( $event_id, $ticket_id, $checkin_day );
        if ( ! $lock_key ) {
            $result['message'] = 'El ticket está siendo sincronizado por otro proceso. La app volverá a intentarlo.';
            return $result;
        }

        try {
            if ( eventosapp_mobile_offline_find_client_log( $ticket_id, $client_id ) ) {
                $result['accepted'] = true;
                $result['already']  = true;
                $result['message']  = 'La operación offline ya había sido sincronizada.';
                return $result;
            }

            $status = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
            if ( is_string( $status ) ) {
                $status = maybe_unserialize( $status );
            }
            if ( ! is_array( $status ) ) {
                $status = [];
            }

            $already = isset( $status[ $checkin_day ] ) && $status[ $checkin_day ] === 'checked_in';
            if ( ! $already ) {
                $status[ $checkin_day ] = 'checked_in';
                update_post_meta( $ticket_id, '_eventosapp_checkin_status', $status );
            }

            $log = get_post_meta( $ticket_id, '_eventosapp_checkin_log', true );
            if ( is_string( $log ) ) {
                $log = maybe_unserialize( $log );
            }
            if ( ! is_array( $log ) ) {
                $log = [];
            }

            $user  = wp_get_current_user();
            $log[] = [
                'fecha'               => $checkin_day,
                'hora'                => $dt->format( 'H:i:s' ),
                'dia'                 => $checkin_day,
                'status'              => 'checked_in',
                'usuario'             => $user->display_name . ' (' . $user->user_email . ')',
                'usuario_id'          => absint( $user->ID ),
                'qr_type'             => $qr_type,
                'qr_type_label'       => $qr_label,
                'origen'              => 'android_staff_qr_offline_sync',
                'client_operation_id' => $client_id,
                'offline_created_at'  => $created_at,
                'synced_at'           => $dt->format( 'c' ),
            ];
            update_post_meta( $ticket_id, '_eventosapp_checkin_log', $log );

            if ( ! $already && function_exists( 'eventosapp_update_qr_usage_stats' ) ) {
                eventosapp_update_qr_usage_stats( $event_id, $qr_type );
            }

            $result['accepted'] = true;
            $result['already']  = $already;
            $result['message']  = $already
                ? 'El ticket ya estaba marcado en EventosApp; se resolvió sin duplicar el check-in.'
                : 'Ingreso offline sincronizado correctamente.';
            return $result;
        } finally {
            eventosapp_mobile_offline_release_lock( $lock_key );
        }
    }
}

if ( ! function_exists( 'eventosapp_mobile_offline_sync' ) ) {
    function eventosapp_mobile_offline_sync( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        $user_id  = get_current_user_id();

        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return new WP_Error( 'invalid_event', 'El evento no existe.', [ 'status' => 404 ] );
        }
        if ( ! function_exists( 'eventosapp_mobile_app_user_can_feature_in_event' ) ||
             ! eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user_id, 'qr' ) ) {
            return new WP_Error( 'forbidden_event', 'No tienes permiso de Check-in con QR para este evento.', [ 'status' => 403 ] );
        }

        $params = $request->get_json_params();
        $items  = isset( $params['items'] ) && is_array( $params['items'] ) ? array_values( $params['items'] ) : [];
        if ( count( $items ) > 500 ) {
            return new WP_Error( 'too_many_items', 'Envía máximo 500 registros por sincronización.', [ 'status' => 400 ] );
        }

        $results = [];
        foreach ( $items as $item ) {
            if ( is_array( $item ) ) {
                $results[] = eventosapp_mobile_offline_sync_item( $event_id, $item );
            }
        }

        return eventosapp_mobile_offline_no_cache_response( [
            'api_version' => EVENTOSAPP_MOBILE_OFFLINE_API_VERSION,
            'event_id'    => $event_id,
            'synced_at'   => gmdate( 'c' ),
            'results'     => $results,
        ] );
    }
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'eventosapp-kiosk/v1', '/staff/events/(?P<event_id>\\d+)/offline-snapshot', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventosapp_mobile_offline_snapshot',
        'permission_callback' => 'eventosapp_mobile_app_permission',
        'args'                => [
            'event_id' => [
                'required'          => true,
                'sanitize_callback' => 'absint',
            ],
            'page' => [
                'required'          => false,
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'required'          => false,
                'sanitize_callback' => 'absint',
            ],
        ],
    ] );

    register_rest_route( 'eventosapp-kiosk/v1', '/staff/events/(?P<event_id>\\d+)/offline-sync', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'eventosapp_mobile_offline_sync',
        'permission_callback' => 'eventosapp_mobile_app_permission',
        'args'                => [
            'event_id' => [
                'required'          => true,
                'sanitize_callback' => 'absint',
            ],
        ],
    ] );
}, 35 );
