<?php
/**
 * EventosApp – API offline para Kiosko Android 2.8.0+
 *
 * Añade un snapshot autocontenido por evento para Autogestión Android y una
 * sincronización idempotente de los check-ins creados sin conexión.
 *
 * Debe cargarse después de:
 * - eventosapp-kiosk-api.php
 * - eventosapp-mobile-staff-checkin-api.php
 * - eventosapp-mobile-staff-offline-api.php
 *
 * Rutas:
 * - GET  /eventosapp-kiosk/v1/events/{event_id}/offline-snapshot
 * - POST /eventosapp-kiosk/v1/events/{event_id}/offline-sync
 *
 * El snapshot incluye configuración del kiosko, configuración de papel,
 * tickets, hashes de QR y HTML de escarapela con imágenes embebidas. No cambia
 * los endpoints online históricos ni la cola de impresión Android.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'EVENTOSAPP_MOBILE_KIOSK_OFFLINE_API_VERSION' ) ) {
    define( 'EVENTOSAPP_MOBILE_KIOSK_OFFLINE_API_VERSION', '1.0.0' );
}

if ( ! function_exists( 'eventosapp_mobile_kiosk_offline_no_cache_response' ) ) {
    function eventosapp_mobile_kiosk_offline_no_cache_response( $data ) {
        if ( function_exists( 'eventosapp_mobile_offline_no_cache_response' ) ) {
            return eventosapp_mobile_offline_no_cache_response( $data );
        }

        $response = rest_ensure_response( $data );
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'Pragma', 'no-cache' );
        return $response;
    }
}

if ( ! function_exists( 'eventosapp_mobile_kiosk_offline_user_can_event' ) ) {
    function eventosapp_mobile_kiosk_offline_user_can_event( $event_id, $user_id = 0 ) {
        $event_id = absint( $event_id );
        $user_id  = absint( $user_id ?: get_current_user_id() );
        if ( ! $event_id || ! $user_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return false;
        }

        if ( function_exists( 'eventosapp_mobile_app_user_can_feature_in_event' ) ) {
            if ( ! eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user_id, 'self_checkin' ) ) {
                return false;
            }
        } elseif ( function_exists( 'eventosapp_kiosk_api_user_can_event' ) ) {
            if ( ! eventosapp_kiosk_api_user_can_event( $event_id, $user_id ) ) {
                return false;
            }
        } else {
            return false;
        }

        if ( function_exists( 'eventosapp_kiosk_api_event_is_enabled' ) && ! eventosapp_kiosk_api_event_is_enabled( $event_id ) ) {
            return false;
        }
        if ( function_exists( 'eventosapp_event_has_physical_access' ) && ! eventosapp_event_has_physical_access( $event_id ) ) {
            return false;
        }

        return true;
    }
}

if ( ! function_exists( 'eventosapp_mobile_kiosk_offline_asset_data_uri' ) ) {
    /**
     * Convierte un recurso descargable a data URI para que el paquete funcione
     * sin internet. Primero resuelve archivos locales de uploads y solo como
     * fallback usa wp_safe_remote_get con límite estricto de tamaño.
     *
     * Para eventos grandes mantiene un cache acotado únicamente después de que
     * una URL se repite. Así logos/fondos compartidos se leen y codifican una
     * sola vez por request, mientras QR/recursos únicos de cada asistente no
     * consumen memoria adicional en el proceso PHP.
     */
    function eventosapp_mobile_kiosk_offline_asset_data_uri( $url ) {
        static $seen  = [];
        static $cache = [];

        $url = trim( (string) $url );
        if ( $url === '' || strpos( $url, 'data:' ) === 0 ) {
            return $url;
        }
        if ( ! preg_match( '#^https?://#i', $url ) ) {
            return $url;
        }

        $cache_key = md5( $url );
        if ( isset( $cache[ $cache_key ] ) ) {
            return $cache[ $cache_key ];
        }
        $was_seen = isset( $seen[ $cache_key ] );
        $seen[ $cache_key ] = true;

        $raw_url = preg_replace( '/[?#].*$/', '', $url );
        $uploads = wp_upload_dir();
        $bytes   = '';
        $mime    = '';

        if ( empty( $uploads['error'] ) && ! empty( $uploads['baseurl'] ) && ! empty( $uploads['basedir'] ) ) {
            $base_url = rtrim( (string) $uploads['baseurl'], '/' );
            if ( strpos( $raw_url, $base_url . '/' ) === 0 ) {
                $relative = ltrim( substr( $raw_url, strlen( $base_url ) ), '/' );
                $relative = str_replace( [ '../', '..\\' ], '', rawurldecode( $relative ) );
                $path     = trailingslashit( $uploads['basedir'] ) . $relative;
                if ( is_readable( $path ) && is_file( $path ) && filesize( $path ) <= 2 * MB_IN_BYTES ) {
                    $bytes = (string) file_get_contents( $path );
                    $type  = wp_check_filetype( $path );
                    $mime  = sanitize_mime_type( $type['type'] ?? '' );
                    if ( $mime === '' && function_exists( 'mime_content_type' ) ) {
                        $mime = sanitize_mime_type( (string) mime_content_type( $path ) );
                    }
                }
            }
        }

        if ( $bytes === '' ) {
            $response = wp_safe_remote_get( $url, [
                'timeout'             => 8,
                'redirection'         => 2,
                'limit_response_size' => 2 * MB_IN_BYTES,
                'headers'             => [ 'Accept' => 'image/*' ],
            ] );
            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $bytes = (string) wp_remote_retrieve_body( $response );
                $mime  = sanitize_mime_type( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
                if ( strpos( $mime, ';' ) !== false ) {
                    $mime = trim( strtok( $mime, ';' ) );
                }
                if ( strlen( $bytes ) > 2 * MB_IN_BYTES ) {
                    $bytes = '';
                }
            }
        }

        if ( $bytes === '' ) {
            return $url;
        }
        if ( $mime === '' || strpos( $mime, 'image/' ) !== 0 ) {
            $mime = 'image/png';
        }

        $data_uri = 'data:' . $mime . ';base64,' . base64_encode( $bytes );
        if ( $was_seen && count( $cache ) < 32 ) {
            $cache[ $cache_key ] = $data_uri;
        }
        return $data_uri;
    }
}

if ( ! function_exists( 'eventosapp_mobile_kiosk_offline_inline_badge_images' ) ) {
    function eventosapp_mobile_kiosk_offline_inline_badge_images( $html ) {
        $html = (string) $html;
        if ( $html === '' ) {
            return '';
        }

        return preg_replace_callback(
            '/(<img\\b[^>]*\\bsrc=["\'])([^"\']+)(["\'])/i',
            static function ( $matches ) {
                $src = html_entity_decode( (string) $matches[2], ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
                $uri = eventosapp_mobile_kiosk_offline_asset_data_uri( $src );
                return $matches[1] . esc_attr( $uri ) . $matches[3];
            },
            $html
        );
    }
}

if ( ! function_exists( 'eventosapp_mobile_kiosk_offline_config_payload' ) ) {
    function eventosapp_mobile_kiosk_offline_config_payload( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id || ! function_exists( 'eventosapp_kiosk_api_event_config' ) ) {
            return new WP_Error( 'kiosk_config_unavailable', 'La configuración del kiosko no está disponible.', [ 'status' => 500 ] );
        }

        $config_request = new WP_REST_Request( 'GET', '/' . EVENTOSAPP_KIOSK_API_NAMESPACE . '/events/' . $event_id );
        $config_request->set_param( 'id', $event_id );
        $response = eventosapp_kiosk_api_event_config( $config_request );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = rest_ensure_response( $response )->get_data();
        if ( ! is_array( $data ) || empty( $data['event'] ) ) {
            return new WP_Error( 'invalid_kiosk_config', 'EventosApp no pudo construir la configuración offline del kiosko.', [ 'status' => 500 ] );
        }

        if ( ! empty( $data['design'] ) && is_array( $data['design'] ) ) {
            foreach ( [ 'background_image_url', 'main_logo_url' ] as $key ) {
                if ( ! empty( $data['design'][ $key ] ) ) {
                    $data['design'][ $key ] = eventosapp_mobile_kiosk_offline_asset_data_uri( $data['design'][ $key ] );
                }
            }
            if ( ! empty( $data['design']['extra_logos'] ) && is_array( $data['design']['extra_logos'] ) ) {
                foreach ( $data['design']['extra_logos'] as &$logo ) {
                    if ( is_array( $logo ) && ! empty( $logo['url'] ) ) {
                        $logo['url'] = eventosapp_mobile_kiosk_offline_asset_data_uri( $logo['url'] );
                    }
                }
                unset( $logo );
            }
        }

        return $data;
    }
}

if ( ! function_exists( 'eventosapp_mobile_kiosk_offline_ticket_payload' ) ) {
    function eventosapp_mobile_kiosk_offline_ticket_payload( $ticket_id, $event_id ) {
        $ticket_id = absint( $ticket_id );
        $event_id  = absint( $event_id );
        if ( ! $ticket_id || ! $event_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
            return null;
        }
        if ( absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) !== $event_id ) {
            return null;
        }
        if ( in_array( get_post_status( $ticket_id ), [ 'trash', 'auto-draft', 'inherit' ], true ) ) {
            return null;
        }

        $ticket = function_exists( 'eventosapp_kiosk_api_ticket_payload' )
            ? eventosapp_kiosk_api_ticket_payload( $ticket_id )
            : null;
        if ( ! is_array( $ticket ) ) {
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

        $lookup_keys = function_exists( 'eventosapp_mobile_offline_ticket_lookup_keys' )
            ? eventosapp_mobile_offline_ticket_lookup_keys( $ticket_id, $event_id )
            : [];
        $checked_days = function_exists( 'eventosapp_mobile_offline_checked_days' )
            ? eventosapp_mobile_offline_checked_days( $ticket_id )
            : [];

        $badge_html = function_exists( 'eventosapp_get_badge_html_from_event' )
            ? eventosapp_get_badge_html_from_event( $event_id, $ticket_id, false )
            : '';
        if ( ! is_string( $badge_html ) || $badge_html === '' ) {
            return null;
        }
        $badge_html = eventosapp_mobile_kiosk_offline_inline_badge_images( $badge_html );

        return [
            'ticket'            => $ticket,
            'access_allowed'    => (bool) $access_allowed,
            'access_message'    => sanitize_text_field( $access_message ),
            'payment_message'   => sanitize_text_field( $payment_message ),
            'checked_in_days'   => array_values( $checked_days ),
            'lookup_keys'       => array_values( $lookup_keys ),
            'badge_html_base64' => base64_encode( $badge_html ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_kiosk_offline_snapshot' ) ) {
    function eventosapp_mobile_kiosk_offline_snapshot( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        $user_id  = get_current_user_id();
        $page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
        $per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ?: 50 ) ) );

        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return new WP_Error( 'invalid_event', 'El evento no existe.', [ 'status' => 404 ] );
        }
        if ( ! eventosapp_mobile_kiosk_offline_user_can_event( $event_id, $user_id ) ) {
            return new WP_Error( 'forbidden_event', 'No tienes permiso de Autogestión para este evento.', [ 'status' => 403 ] );
        }

        $config = eventosapp_mobile_kiosk_offline_config_payload( $event_id );
        if ( is_wp_error( $config ) ) {
            return $config;
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
            $payload = eventosapp_mobile_kiosk_offline_ticket_payload( $ticket_id, $event_id );
            if ( $payload ) {
                $tickets[] = $payload;
            }
        }

        $timezone = get_post_meta( $event_id, '_eventosapp_zona_horaria', true );
        if ( ! $timezone ) {
            $timezone = wp_timezone_string() ?: 'UTC';
        }

        return eventosapp_mobile_kiosk_offline_no_cache_response( [
            'api_version' => EVENTOSAPP_MOBILE_KIOSK_OFFLINE_API_VERSION,
            'generated_at'=> gmdate( 'c' ),
            'timezone'    => sanitize_text_field( $timezone ),
            'event'       => $config['event'],
            'config'      => $config,
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => absint( $query->found_posts ),
            'total_pages' => max( 1, absint( $query->max_num_pages ) ),
            'tickets'     => $tickets,
        ] );
    }
}

if ( ! function_exists( 'eventosapp_mobile_kiosk_offline_sync_item' ) ) {
    function eventosapp_mobile_kiosk_offline_sync_item( $event_id, $item ) {
        $event_id    = absint( $event_id );
        $client_id   = sanitize_text_field( (string) ( $item['client_id'] ?? '' ) );
        $ticket_id   = absint( $item['ticket_id'] ?? 0 );
        $checkin_day = sanitize_text_field( (string) ( $item['checkin_date'] ?? '' ) );
        $request_id  = substr( sanitize_text_field( (string) ( $item['request_id'] ?? '' ) ), 0, 160 );
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
            ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $checkin_day )
        ) {
            $result['message'] = 'El registro offline del kiosko está incompleto o es inválido.';
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

        $days = function_exists( 'eventosapp_get_event_days' ) ? array_values( (array) eventosapp_get_event_days( $event_id ) ) : [];
        if ( ! empty( $days ) && ! in_array( $checkin_day, $days, true ) ) {
            $result['message'] = 'La fecha registrada offline no corresponde a una fecha válida del evento.';
            return $result;
        }

        $timezone = get_post_meta( $event_id, '_eventosapp_zona_horaria', true );
        try {
            $zone = new DateTimeZone( $timezone ?: ( wp_timezone_string() ?: 'UTC' ) );
        } catch ( Exception $e ) {
            $zone = wp_timezone();
        }
        $dt    = new DateTime( 'now', $zone );
        $today = $dt->format( 'Y-m-d' );
        if ( $checkin_day > $today ) {
            $result['message'] = 'La fecha offline está en el futuro respecto a EventosApp.';
            return $result;
        }

        if ( function_exists( 'eventosapp_mobile_offline_find_client_log' ) && eventosapp_mobile_offline_find_client_log( $ticket_id, $client_id ) ) {
            $result['accepted'] = true;
            $result['already']  = true;
            $result['message']  = 'La operación offline ya había sido sincronizada.';
            return $result;
        }

        $lock_key = function_exists( 'eventosapp_mobile_offline_acquire_lock' )
            ? eventosapp_mobile_offline_acquire_lock( $event_id, $ticket_id, $checkin_day )
            : '';
        if ( function_exists( 'eventosapp_mobile_offline_acquire_lock' ) && ! $lock_key ) {
            $result['message'] = 'El ticket está siendo sincronizado por otro proceso. La app volverá a intentarlo.';
            return $result;
        }

        try {
            if ( function_exists( 'eventosapp_mobile_offline_find_client_log' ) && eventosapp_mobile_offline_find_client_log( $ticket_id, $client_id ) ) {
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
                'qr_type'             => 'self_checkin_android',
                'qr_type_label'       => 'Autogestión Android',
                'origen'              => 'android_kiosk_offline_sync',
                'client_operation_id' => $client_id,
                'request_id'          => $request_id,
                'offline_created_at'  => $created_at,
                'synced_at'           => $dt->format( 'c' ),
            ];
            update_post_meta( $ticket_id, '_eventosapp_checkin_log', $log );

            $result['accepted'] = true;
            $result['already']  = $already;
            $result['message']  = $already
                ? 'El ticket ya estaba marcado en EventosApp; se resolvió sin duplicar el check-in.'
                : 'Check-in offline del kiosko sincronizado correctamente.';
            return $result;
        } finally {
            if ( $lock_key && function_exists( 'eventosapp_mobile_offline_release_lock' ) ) {
                eventosapp_mobile_offline_release_lock( $lock_key );
            }
        }
    }
}

if ( ! function_exists( 'eventosapp_mobile_kiosk_offline_sync' ) ) {
    function eventosapp_mobile_kiosk_offline_sync( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        $user_id  = get_current_user_id();

        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return new WP_Error( 'invalid_event', 'El evento no existe.', [ 'status' => 404 ] );
        }
        if ( ! eventosapp_mobile_kiosk_offline_user_can_event( $event_id, $user_id ) ) {
            return new WP_Error( 'forbidden_event', 'No tienes permiso de Autogestión para este evento.', [ 'status' => 403 ] );
        }

        $params = $request->get_json_params();
        $items  = isset( $params['items'] ) && is_array( $params['items'] ) ? array_values( $params['items'] ) : [];
        if ( count( $items ) > 500 ) {
            return new WP_Error( 'too_many_items', 'Envía máximo 500 registros por sincronización.', [ 'status' => 400 ] );
        }

        $results = [];
        foreach ( $items as $item ) {
            if ( is_array( $item ) ) {
                $results[] = eventosapp_mobile_kiosk_offline_sync_item( $event_id, $item );
            }
        }

        return eventosapp_mobile_kiosk_offline_no_cache_response( [
            'api_version' => EVENTOSAPP_MOBILE_KIOSK_OFFLINE_API_VERSION,
            'event_id'    => $event_id,
            'synced_at'   => gmdate( 'c' ),
            'results'     => $results,
        ] );
    }
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\\d+)/offline-snapshot', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventosapp_mobile_kiosk_offline_snapshot',
        'permission_callback' => 'eventosapp_mobile_app_permission',
        'args'                => [
            'event_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
            'page'     => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'per_page' => [ 'required' => false, 'sanitize_callback' => 'absint' ],
        ],
    ] );

    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\\d+)/offline-sync', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'eventosapp_mobile_kiosk_offline_sync',
        'permission_callback' => 'eventosapp_mobile_app_permission',
        'args'                => [
            'event_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
        ],
    ] );
}, 36 );
