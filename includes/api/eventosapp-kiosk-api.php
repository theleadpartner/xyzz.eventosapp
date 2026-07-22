<?php
/**
 * EventosApp – API nativa para el Kiosko Android.
 *
 * Expone autenticación por token, eventos autorizados, configuración del kiosko,
 * búsqueda de asistentes, check-in y una URL firmada de corta duración para
 * rasterizar la escarapela dentro de la aplicación Android.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'EVENTOSAPP_KIOSK_API_NAMESPACE' ) ) {
    define( 'EVENTOSAPP_KIOSK_API_NAMESPACE', 'eventosapp-kiosk/v1' );
}

if ( ! defined( 'EVENTOSAPP_KIOSK_API_VERSION' ) ) {
    define( 'EVENTOSAPP_KIOSK_API_VERSION', '1.1.0' );
}

if ( ! defined( 'EVENTOSAPP_KIOSK_API_LOADED' ) ) {
    define( 'EVENTOSAPP_KIOSK_API_LOADED', true );
}

if ( ! defined( 'EVENTOSAPP_KIOSK_API_FILE' ) ) {
    define( 'EVENTOSAPP_KIOSK_API_FILE', __FILE__ );
}

if ( ! function_exists( 'eventosapp_kiosk_api_token_meta_key' ) ) {
    function eventosapp_kiosk_api_token_meta_key() {
        return '_eventosapp_kiosk_api_tokens';
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_token_hash' ) ) {
    function eventosapp_kiosk_api_token_hash( $token ) {
        return hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_read_user_tokens' ) ) {
    function eventosapp_kiosk_api_read_user_tokens( $user_id ) {
        $tokens = get_user_meta( absint( $user_id ), eventosapp_kiosk_api_token_meta_key(), true );
        if ( ! is_array( $tokens ) ) {
            $tokens = [];
        }

        $now   = time();
        $clean = [];
        foreach ( $tokens as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['hash'] ) || empty( $entry['expires_at'] ) ) {
                continue;
            }
            if ( absint( $entry['expires_at'] ) <= $now ) {
                continue;
            }

            $clean[] = [
                'id'           => sanitize_text_field( $entry['id'] ?? substr( $entry['hash'], 0, 12 ) ),
                'hash'         => sanitize_text_field( $entry['hash'] ),
                'device'       => sanitize_text_field( $entry['device'] ?? '' ),
                'created_at'   => absint( $entry['created_at'] ?? $now ),
                'expires_at'   => absint( $entry['expires_at'] ),
                'last_seen_at' => absint( $entry['last_seen_at'] ?? 0 ),
            ];
        }

        return $clean;
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_write_user_tokens' ) ) {
    function eventosapp_kiosk_api_write_user_tokens( $user_id, $tokens ) {
        $tokens = is_array( $tokens ) ? array_values( $tokens ) : [];
        if ( count( $tokens ) > 8 ) {
            usort( $tokens, static function ( $a, $b ) {
                return (int) ( $b['created_at'] ?? 0 ) <=> (int) ( $a['created_at'] ?? 0 );
            } );
            $tokens = array_slice( $tokens, 0, 8 );
        }

        update_user_meta( absint( $user_id ), eventosapp_kiosk_api_token_meta_key(), $tokens );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_create_token' ) ) {
    function eventosapp_kiosk_api_create_token( $user_id, $device = '' ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return new WP_Error( 'invalid_user', 'Usuario inválido.' );
        }

        try {
            $secret = bin2hex( random_bytes( 32 ) );
        } catch ( Exception $e ) {
            $secret = wp_generate_password( 64, false, false );
        }

        /*
         * El prefijo con el ID del usuario permite resolver la sesión sin recorrer
         * todos los usuarios de WordPress. El secreto completo sigue protegido:
         * solo se guarda su HMAC y nunca el token en texto plano.
         */
        $token      = $user_id . '.' . $secret;
        $now        = time();
        $expires_at = $now + ( 30 * DAY_IN_SECONDS );
        $hash       = eventosapp_kiosk_api_token_hash( $token );
        $tokens     = eventosapp_kiosk_api_read_user_tokens( $user_id );

        $tokens[] = [
            'id'           => substr( $hash, 0, 12 ),
            'hash'         => $hash,
            'device'       => sanitize_text_field( $device ),
            'created_at'   => $now,
            'expires_at'   => $expires_at,
            'last_seen_at' => $now,
        ];

        eventosapp_kiosk_api_write_user_tokens( $user_id, $tokens );

        return [
            'token'      => $token,
            'expires_at' => $expires_at,
        ];
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_bearer_token' ) ) {
    function eventosapp_kiosk_api_bearer_token( $request = null ) {
        $header = '';
        if ( $request instanceof WP_REST_Request ) {
            $header = (string) $request->get_header( 'authorization' );
            if ( $header === '' ) {
                $header = (string) $request->get_header( 'x-eventosapp-token' );
                if ( $header !== '' ) {
                    return trim( $header );
                }
            }
        }

        if ( $header === '' && ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            $header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
        }
        if ( $header === '' && ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            $header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
        }

        if ( preg_match( '/^Bearer\s+(.+)$/i', trim( $header ), $matches ) ) {
            return trim( $matches[1] );
        }

        return '';
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_find_user_by_token' ) ) {
    function eventosapp_kiosk_api_find_user_by_token( $token ) {
        $token = trim( (string) $token );
        if ( ! preg_match( '/^(\d+)\.([a-zA-Z0-9]{32,})$/', $token, $matches ) ) {
            return new WP_Error( 'invalid_token', 'La sesión no es válida.', [ 'status' => 401 ] );
        }

        $user_id = absint( $matches[1] );
        $user    = $user_id ? get_user_by( 'id', $user_id ) : false;
        if ( ! $user instanceof WP_User ) {
            return new WP_Error( 'invalid_token', 'La sesión expiró o fue cerrada.', [ 'status' => 401 ] );
        }

        $hash    = eventosapp_kiosk_api_token_hash( $token );
        $tokens  = eventosapp_kiosk_api_read_user_tokens( $user_id );
        $changed = false;

        foreach ( $tokens as &$entry ) {
            if ( empty( $entry['hash'] ) || ! hash_equals( (string) $entry['hash'], $hash ) ) {
                continue;
            }

            $now = time();
            if ( $now - absint( $entry['last_seen_at'] ?? 0 ) > 300 ) {
                $entry['last_seen_at'] = $now;
                $changed               = true;
            }
            if ( $changed ) {
                eventosapp_kiosk_api_write_user_tokens( $user_id, $tokens );
            }

            unset( $entry );
            return $user;
        }
        unset( $entry );

        return new WP_Error( 'invalid_token', 'La sesión expiró o fue cerrada.', [ 'status' => 401 ] );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_permission' ) ) {
    function eventosapp_kiosk_api_permission( $request ) {
        $user = eventosapp_kiosk_api_find_user_by_token( eventosapp_kiosk_api_bearer_token( $request ) );
        if ( is_wp_error( $user ) ) {
            return $user;
        }

        wp_set_current_user( $user->ID );

        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        if ( function_exists( 'eventosapp_role_can' ) && eventosapp_role_can( 'self_checkin' ) ) {
            return true;
        }

        return new WP_Error( 'forbidden', 'Tu usuario no tiene permiso para operar el kiosko.', [ 'status' => 403 ] );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_user_payload' ) ) {
    function eventosapp_kiosk_api_user_payload( $user ) {
        if ( ! $user instanceof WP_User ) {
            $user = wp_get_current_user();
        }

        return [
            'id'           => absint( $user->ID ),
            'display_name' => sanitize_text_field( $user->display_name ),
            'email'        => sanitize_email( $user->user_email ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_login_rate_key' ) ) {
    function eventosapp_kiosk_api_login_rate_key( $login ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        return 'evapp_kiosk_login_' . md5( strtolower( trim( (string) $login ) ) . '|' . $ip );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_login' ) ) {
    function eventosapp_kiosk_api_login( WP_REST_Request $request ) {
        $login    = sanitize_text_field( (string) $request->get_param( 'login' ) );
        $password = (string) $request->get_param( 'password' );
        $device   = sanitize_text_field( (string) $request->get_param( 'device_name' ) );

        if ( $login === '' || $password === '' ) {
            return new WP_Error( 'missing_credentials', 'Escribe el usuario y la contraseña.', [ 'status' => 400 ] );
        }

        $rate_key = eventosapp_kiosk_api_login_rate_key( $login );
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

        $can_operate_kiosk = current_user_can( 'manage_options' )
            || ( function_exists( 'eventosapp_role_can' ) && eventosapp_role_can( 'self_checkin' ) );
        if ( ! $can_operate_kiosk ) {
            return new WP_Error( 'forbidden', 'Tu usuario no tiene permiso para operar el kiosko.', [ 'status' => 403 ] );
        }

        $token = eventosapp_kiosk_api_create_token( $user->ID, $device );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        return rest_ensure_response( [
            'token'      => $token['token'],
            'expires_at' => gmdate( 'c', $token['expires_at'] ),
            'user'       => eventosapp_kiosk_api_user_payload( $user ),
            'api'        => [
                'namespace' => EVENTOSAPP_KIOSK_API_NAMESPACE,
                'version'   => 1,
            ],
        ] );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_logout' ) ) {
    function eventosapp_kiosk_api_logout( WP_REST_Request $request ) {
        $user  = wp_get_current_user();
        $token = eventosapp_kiosk_api_bearer_token( $request );
        $hash  = eventosapp_kiosk_api_token_hash( $token );

        $tokens = eventosapp_kiosk_api_read_user_tokens( $user->ID );
        $tokens = array_values( array_filter( $tokens, static function ( $entry ) use ( $hash ) {
            return empty( $entry['hash'] ) || ! hash_equals( (string) $entry['hash'], $hash );
        } ) );
        eventosapp_kiosk_api_write_user_tokens( $user->ID, $tokens );

        return rest_ensure_response( [ 'logged_out' => true ] );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_event_is_enabled' ) ) {
    /**
     * Respeta el interruptor explícito. Para eventos configurados antes de esta API,
     * detecta metadatos históricos de Autogestión y los considera habilitados.
     */
    function eventosapp_kiosk_api_event_is_enabled( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) {
            return false;
        }

        if ( metadata_exists( 'post', $event_id, '_eventosapp_self_checkin_enabled' ) ) {
            return get_post_meta( $event_id, '_eventosapp_self_checkin_enabled', true ) === '1';
        }

        $legacy_keys = [
            '_eventosapp_self_checkin_auth_fields',
            '_eventosapp_self_checkin_theme',
            '_eventosapp_self_checkin_background_type',
            '_eventosapp_self_checkin_background_color',
            '_eventosapp_self_checkin_background_image_url',
            '_eventosapp_self_checkin_main_logo_url',
            '_eventosapp_self_checkin_extra_logos',
        ];

        foreach ( $legacy_keys as $meta_key ) {
            if ( metadata_exists( 'post', $event_id, $meta_key ) ) {
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_user_can_event' ) ) {
    function eventosapp_kiosk_api_user_can_event( $event_id, $user_id = 0 ) {
        $event_id = absint( $event_id );
        $user_id  = absint( $user_id ?: get_current_user_id() );
        if ( ! $event_id || ! $user_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return false;
        }

        if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'edit_post', $event_id ) ) {
            return true;
        }
        if ( absint( get_post_field( 'post_author', $event_id ) ) === $user_id ) {
            return true;
        }
        if ( function_exists( 'eventosapp_user_can_manage_event' ) && eventosapp_user_can_manage_event( $event_id ) ) {
            return true;
        }
        if ( function_exists( 'eventosapp_staff_access_user_can_select_event_in_dashboard' ) && eventosapp_staff_access_user_can_select_event_in_dashboard( $event_id, $user_id ) ) {
            return true;
        }
        if ( function_exists( 'eventosapp_support_user_can_select_event_in_dashboard' ) && eventosapp_support_user_can_select_event_in_dashboard( $event_id, $user_id ) ) {
            return true;
        }
        if ( function_exists( 'eventosapp_support_user_has_assignment_in_event' ) && eventosapp_support_user_has_assignment_in_event( $event_id, $user_id ) ) {
            return true;
        }

        return false;
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_event_days' ) ) {
    function eventosapp_kiosk_api_event_days( $event_id ) {
        $days = function_exists( 'eventosapp_get_event_days' ) ? (array) eventosapp_get_event_days( $event_id ) : [];
        return array_values( array_filter( array_map( 'sanitize_text_field', $days ), static function ( $day ) {
            return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day );
        } ) );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_event_summary' ) ) {
    function eventosapp_kiosk_api_event_summary( $event_id ) {
        $event_id = absint( $event_id );
        $design   = function_exists( 'eventosapp_self_checkin_get_design_config' )
            ? eventosapp_self_checkin_get_design_config( $event_id )
            : [];
        $days = eventosapp_kiosk_api_event_days( $event_id );

        return [
            'id'            => $event_id,
            'title'         => wp_strip_all_tags( get_the_title( $event_id ) ),
            'status'        => get_post_status( $event_id ),
            'days'          => $days,
            'first_day'     => $days ? reset( $days ) : '',
            'last_day'      => $days ? end( $days ) : '',
            'kiosk_enabled' => eventosapp_kiosk_api_event_is_enabled( $event_id ),
            'theme'         => sanitize_key( $design['theme'] ?? 'light' ),
            'logo_url'      => esc_url_raw( $design['main_logo_url'] ?? '' ),
            'modalidad'     => function_exists( 'eventosapp_get_event_modalidad' ) ? eventosapp_get_event_modalidad( $event_id ) : 'presencial',
        ];
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_events' ) ) {
    function eventosapp_kiosk_api_events() {
        $event_ids = get_posts( [
            'post_type'              => 'eventosapp_event',
            'post_status'            => [ 'publish', 'future', 'draft', 'pending', 'private' ],
            'posts_per_page'         => 500,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ] );

        $events = [];
        foreach ( $event_ids as $event_id ) {
            if ( ! eventosapp_kiosk_api_user_can_event( $event_id ) ) {
                continue;
            }
            if ( function_exists( 'eventosapp_event_has_physical_access' ) && ! eventosapp_event_has_physical_access( $event_id ) ) {
                continue;
            }
            if ( ! eventosapp_kiosk_api_event_is_enabled( $event_id ) ) {
                continue;
            }
            $events[] = eventosapp_kiosk_api_event_summary( $event_id );
        }

        return rest_ensure_response( [
            'events' => $events,
            'user'   => eventosapp_kiosk_api_user_payload( wp_get_current_user() ),
        ] );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_paper_config' ) ) {
    function eventosapp_kiosk_api_paper_config( $event_id ) {
        $event_id = absint( $event_id );
        $paper    = [
            'key'         => '',
            'name'        => 'Escarapela',
            'width_mm'    => 100,
            'height_mm'   => 140,
            'margin_mm'   => 3,
            'orientation' => 'portrait',
        ];

        if ( function_exists( 'eventosapp_get_badge_settings' ) ) {
            $settings = eventosapp_get_badge_settings( $event_id );
            if ( is_array( $settings ) ) {
                $paper['key']         = sanitize_key( $settings['paper_template_key'] ?? get_post_meta( $event_id, 'eventosapp_badge_paper_template', true ) );
                $paper['name']        = sanitize_text_field( $settings['paper_template_name'] ?? $settings['paper_name'] ?? 'Escarapela' );
                $paper['width_mm']    = (float) ( $settings['paper_width_mm'] ?? 100 );
                $paper['height_mm']   = (float) ( $settings['paper_height_mm'] ?? 140 );
                $paper['margin_mm']   = (float) ( $settings['paper_margin_mm'] ?? 3 );
                $paper['orientation'] = sanitize_key( $settings['paper_orientation'] ?? 'portrait' );
            }
        } elseif ( function_exists( 'eventosapp_badge_get_selected_paper_template' ) ) {
            $template = eventosapp_badge_get_selected_paper_template( $event_id );
            if ( is_array( $template ) ) {
                $paper = array_merge( $paper, [
                    'key'         => sanitize_key( $template['key'] ?? '' ),
                    'name'        => sanitize_text_field( $template['name'] ?? 'Escarapela' ),
                    'width_mm'    => (float) ( $template['width_mm'] ?? 100 ),
                    'height_mm'   => (float) ( $template['height_mm'] ?? 140 ),
                    'margin_mm'   => (float) ( $template['margin_mm'] ?? 3 ),
                    'orientation' => sanitize_key( $template['orientation'] ?? 'portrait' ),
                ] );
            }
        }

        $paper['width_mm']  = max( 10, min( 500, $paper['width_mm'] ) );
        $paper['height_mm'] = max( 10, min( 1000, $paper['height_mm'] ) );
        $paper['margin_mm'] = max( 0, min( 100, $paper['margin_mm'] ) );
        if ( ! in_array( $paper['orientation'], [ 'portrait', 'landscape' ], true ) ) {
            $paper['orientation'] = $paper['height_mm'] >= $paper['width_mm'] ? 'portrait' : 'landscape';
        }

        return $paper;
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_event_config' ) ) {
    function eventosapp_kiosk_api_event_config( WP_REST_Request $request ) {
        $event_id = absint( $request['id'] );
        if ( ! eventosapp_kiosk_api_user_can_event( $event_id ) ) {
            return new WP_Error( 'forbidden_event', 'No tienes acceso a este evento.', [ 'status' => 403 ] );
        }
        if ( ! eventosapp_kiosk_api_event_is_enabled( $event_id ) ) {
            return new WP_Error( 'kiosk_disabled', 'Este evento no tiene habilitado el kiosko de autogestión.', [ 'status' => 409 ] );
        }
        if ( function_exists( 'eventosapp_event_has_physical_access' ) && ! eventosapp_event_has_physical_access( $event_id ) ) {
            return new WP_Error( 'virtual_event', 'El evento no tiene acceso presencial.', [ 'status' => 409 ] );
        }

        $design = function_exists( 'eventosapp_self_checkin_get_design_config' )
            ? eventosapp_self_checkin_get_design_config( $event_id )
            : [];
        $auth_fields = function_exists( 'eventosapp_self_checkin_get_event_auth_fields' )
            ? eventosapp_self_checkin_get_event_auth_fields( $event_id )
            : [ 'identification' ];
        $auth_options = function_exists( 'eventosapp_self_checkin_auth_field_options' )
            ? eventosapp_self_checkin_auth_field_options()
            : [];

        $auth_payload = [];
        foreach ( $auth_fields as $field_key ) {
            if ( empty( $auth_options[ $field_key ] ) ) {
                continue;
            }
            $field = $auth_options[ $field_key ];
            $auth_payload[] = [
                'key'         => sanitize_key( $field_key ),
                'label'       => sanitize_text_field( $field['label'] ?? $field_key ),
                'short_label' => sanitize_text_field( $field['short_label'] ?? $field['label'] ?? $field_key ),
                'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
                'help'        => sanitize_text_field( $field['help'] ?? '' ),
                'keyboard'    => ( $field['keyboard'] ?? 'numbers' ) === 'letters' ? 'letters' : 'numbers',
            ];
        }

        $colors = [];
        foreach ( (array) ( $design['colors'] ?? [] ) as $key => $value ) {
            $colors[ sanitize_key( $key ) ] = sanitize_text_field( $value );
        }

        $extra_logos = [];
        foreach ( (array) ( $design['extra_logos'] ?? [] ) as $logo ) {
            if ( empty( $logo['url'] ) ) {
                continue;
            }
            $extra_logos[] = [
                'url' => esc_url_raw( $logo['url'] ),
                'alt' => sanitize_text_field( $logo['alt'] ?? '' ),
            ];
        }

        $today = function_exists( 'eventosapp_get_today_in_event_tz' )
            ? eventosapp_get_today_in_event_tz( $event_id )
            : current_time( 'Y-m-d' );
        $days = eventosapp_kiosk_api_event_days( $event_id );

        $payload = [
            'event' => eventosapp_kiosk_api_event_summary( $event_id ),
            'design' => [
                'theme'                 => sanitize_key( $design['theme'] ?? 'light' ),
                'colors'                => $colors,
                'background_type'       => sanitize_key( $design['background_type'] ?? 'default' ),
                'background_color'      => sanitize_text_field( $design['background_color'] ?? '' ),
                'background_image_url'  => esc_url_raw( $design['background_image_url'] ?? '' ),
                'background_size'       => sanitize_key( $design['background_size'] ?? 'cover' ),
                'background_position'   => sanitize_text_field( $design['background_position'] ?? 'center center' ),
                'background_repeat'     => sanitize_key( $design['background_repeat'] ?? 'no-repeat' ),
                'main_logo_url'         => esc_url_raw( $design['main_logo_url'] ?? '' ),
                'main_logo_width'       => sanitize_text_field( $design['main_logo_width'] ?? '220px' ),
                'main_logo_max_height'  => sanitize_text_field( $design['main_logo_max_height'] ?? '120px' ),
                'extra_logos'           => $extra_logos,
                'extra_logos_gap'       => sanitize_text_field( $design['extra_logos_gap'] ?? '18px' ),
                'extra_logo_max_height' => sanitize_text_field( $design['extra_logo_max_height'] ?? '72px' ),
            ],
            'authentication' => [
                'fields'          => $auth_payload,
                'label'           => function_exists( 'eventosapp_self_checkin_auth_label' ) ? eventosapp_self_checkin_auth_label( $auth_fields ) : 'Identificación',
                'placeholder'     => function_exists( 'eventosapp_self_checkin_auth_placeholder' ) ? eventosapp_self_checkin_auth_placeholder( $auth_fields ) : 'Escribe tu identificación',
                'help'            => function_exists( 'eventosapp_self_checkin_auth_help_text' ) ? eventosapp_self_checkin_auth_help_text( $auth_fields ) : '',
                'keyboard_default'=> function_exists( 'eventosapp_self_checkin_auth_keyboard_mode' ) ? eventosapp_self_checkin_auth_keyboard_mode( $auth_fields ) : 'numbers',
            ],
            'paper' => eventosapp_kiosk_api_paper_config( $event_id ),
            'operation' => [
                'today'           => $today,
                'event_days'      => $days,
                'checkin_allowed' => empty( $days ) || in_array( $today, $days, true ),
            ],
            'texts' => [
                'kicker'         => 'Autogestión',
                'title'          => 'Identificación del asistente',
                'subtitle'       => 'Ingresa el dato solicitado, selecciona tu registro, confirma la información e imprime la escarapela.',
                'search_button'  => 'Buscar',
                'clear_button'   => 'Limpiar',
                'confirm_title'  => 'Confirma tu información',
                'print_button'   => 'Confirmar e imprimir escarapela',
                'new_checkin'    => 'Registrar otro asistente',
            ],
            'messages' => function_exists( 'eventosapp_self_checkin_messages' ) ? eventosapp_self_checkin_messages() : [],
        ];

        return rest_ensure_response( apply_filters( 'eventosapp_kiosk_api_event_config', $payload, $event_id, get_current_user_id() ) );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_ticket_payload' ) ) {
    function eventosapp_kiosk_api_ticket_payload( $ticket_id ) {
        $payload = function_exists( 'eventosapp_self_checkin_ticket_payload' )
            ? eventosapp_self_checkin_ticket_payload( $ticket_id )
            : null;
        if ( ! is_array( $payload ) ) {
            return null;
        }

        return [
            'ticket_id'       => absint( $payload['ticket_id'] ?? $ticket_id ),
            'event_id'        => absint( $payload['event_id'] ?? 0 ),
            'ticket_public_id'=> sanitize_text_field( $payload['ticket_pub'] ?? '' ),
            'first_name'      => sanitize_text_field( $payload['first_name'] ?? '' ),
            'last_name'       => sanitize_text_field( $payload['last_name'] ?? '' ),
            'full_name'       => sanitize_text_field( $payload['full_name'] ?? '' ),
            'company'         => sanitize_text_field( $payload['company'] ?? '' ),
            'designation'     => sanitize_text_field( $payload['designation'] ?? '' ),
            'identification'  => sanitize_text_field( $payload['identification'] ?? $payload['cc'] ?? '' ),
            'email'           => sanitize_email( $payload['email'] ?? '' ),
            'phone'           => sanitize_text_field( $payload['phone'] ?? '' ),
            'localidad'       => sanitize_text_field( $payload['localidad'] ?? '' ),
            'modalidad'       => sanitize_key( $payload['modalidad'] ?? 'presencial' ),
            'modalidad_label' => sanitize_text_field( $payload['modalidad_label'] ?? 'Presencial' ),
            'today'           => sanitize_text_field( $payload['today'] ?? '' ),
            'today_status'    => sanitize_key( $payload['today_status'] ?? 'not_checked_in' ),
            'today_allowed'   => ! empty( $payload['today_allowed'] ),
            'already_checked' => ! empty( $payload['already_checked'] ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_search' ) ) {
    function eventosapp_kiosk_api_search( WP_REST_Request $request ) {
        $event_id = absint( $request['id'] );
        if ( ! eventosapp_kiosk_api_user_can_event( $event_id ) ) {
            return new WP_Error( 'forbidden_event', 'No tienes acceso a este evento.', [ 'status' => 403 ] );
        }
        if ( ! eventosapp_kiosk_api_event_is_enabled( $event_id ) ) {
            return new WP_Error( 'kiosk_disabled', 'El kiosko no está habilitado para este evento.', [ 'status' => 409 ] );
        }

        $auth_fields = function_exists( 'eventosapp_self_checkin_get_event_auth_fields' )
            ? eventosapp_self_checkin_get_event_auth_fields( $event_id )
            : [ 'identification' ];
        $raw_query = $request->get_param( 'query' );
        $query = function_exists( 'eventosapp_self_checkin_validate_auth_search' )
            ? eventosapp_self_checkin_validate_auth_search( $raw_query, $auth_fields )
            : sanitize_text_field( (string) $raw_query );

        if ( is_wp_error( $query ) ) {
            return new WP_Error( $query->get_error_code(), $query->get_error_message(), [ 'status' => 400 ] );
        }

        $ticket_ids = function_exists( 'eventosapp_self_checkin_find_tickets_by_auth_fields' )
            ? eventosapp_self_checkin_find_tickets_by_auth_fields( $query, $event_id, $auth_fields, 20 )
            : [];
        if ( $ticket_ids ) {
            update_meta_cache( 'post', $ticket_ids );
        }

        $results = [];
        foreach ( $ticket_ids as $ticket_id ) {
            if ( function_exists( 'eventosapp_ticket_is_virtual' ) && eventosapp_ticket_is_virtual( $ticket_id ) ) {
                continue;
            }
            $ticket = eventosapp_kiosk_api_ticket_payload( $ticket_id );
            if ( ! $ticket || absint( $ticket['event_id'] ) !== $event_id ) {
                continue;
            }
            $results[] = $ticket;
        }

        return rest_ensure_response( [
            'query'       => sanitize_text_field( $query ),
            'results'     => $results,
            'total'       => count( $results ),
            'auth_fields' => array_values( $auth_fields ),
        ] );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_get_ticket' ) ) {
    function eventosapp_kiosk_api_get_ticket( WP_REST_Request $request ) {
        $ticket_id = absint( $request['id'] );
        if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
            return new WP_Error( 'invalid_ticket', 'Ticket inválido.', [ 'status' => 404 ] );
        }

        $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        if ( ! eventosapp_kiosk_api_user_can_event( $event_id ) ) {
            return new WP_Error( 'forbidden_ticket', 'No tienes acceso a este ticket.', [ 'status' => 403 ] );
        }
        if ( function_exists( 'eventosapp_ticket_is_virtual' ) && eventosapp_ticket_is_virtual( $ticket_id ) ) {
            return new WP_Error( 'virtual_ticket', 'El ticket es virtual y no genera escarapela.', [ 'status' => 409 ] );
        }

        $ticket = eventosapp_kiosk_api_ticket_payload( $ticket_id );
        if ( ! $ticket ) {
            return new WP_Error( 'ticket_unavailable', 'No fue posible leer el ticket.', [ 'status' => 500 ] );
        }

        return rest_ensure_response( [ 'ticket' => $ticket ] );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_badge_signature' ) ) {
    function eventosapp_kiosk_api_badge_signature( $ticket_id, $event_id, $expires, $request_id ) {
        $payload = absint( $ticket_id ) . '|' . absint( $event_id ) . '|' . absint( $expires ) . '|' . sanitize_text_field( $request_id );
        return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_badge_url' ) ) {
    function eventosapp_kiosk_api_badge_url( $ticket_id, $event_id, $request_id ) {
        $expires   = time() + ( 2 * HOUR_IN_SECONDS );
        $signature = eventosapp_kiosk_api_badge_signature( $ticket_id, $event_id, $expires, $request_id );

        return add_query_arg( [
            'event_id'   => absint( $event_id ),
            'expires'    => $expires,
            'request_id' => rawurlencode( $request_id ),
            'signature'  => $signature,
        ], rest_url( EVENTOSAPP_KIOSK_API_NAMESPACE . '/badge/' . absint( $ticket_id ) ) );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_print' ) ) {
    function eventosapp_kiosk_api_print( WP_REST_Request $request ) {
        $ticket_id = absint( $request['id'] );
        if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
            return new WP_Error( 'invalid_ticket', 'Ticket inválido.', [ 'status' => 404 ] );
        }

        $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        if ( ! eventosapp_kiosk_api_user_can_event( $event_id ) ) {
            return new WP_Error( 'forbidden_ticket', 'No tienes acceso a este ticket.', [ 'status' => 403 ] );
        }
        if ( ! eventosapp_kiosk_api_event_is_enabled( $event_id ) ) {
            return new WP_Error( 'kiosk_disabled', 'El kiosko no está habilitado para este evento.', [ 'status' => 409 ] );
        }
        if ( function_exists( 'eventosapp_ticket_is_virtual' ) && eventosapp_ticket_is_virtual( $ticket_id ) ) {
            return new WP_Error( 'virtual_ticket', 'El ticket es virtual y no genera escarapela.', [ 'status' => 409 ] );
        }

        $current_user = wp_get_current_user();
        $operator     = $current_user->display_name . ' (' . $current_user->user_email . ')';
        $checkin      = null;

        if ( function_exists( 'eventosapp_register_ticket_checkin' ) ) {
            $checkin = eventosapp_register_ticket_checkin( $ticket_id, 'presencial', [
                'origen'        => 'android-kiosk',
                'qr_type'       => 'self_checkin_android',
                'qr_type_label' => 'Autogestión Android',
                'usuario'       => $operator,
                'ip'            => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent'    => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ] );
            if ( empty( $checkin['ok'] ) ) {
                return new WP_Error( 'checkin_failed', sanitize_text_field( $checkin['message'] ?? 'No fue posible registrar el check-in.' ), [ 'status' => 400 ] );
            }
        } elseif ( function_exists( 'eventosapp_self_checkin_mark_ticket' ) ) {
            $checkin = eventosapp_self_checkin_mark_ticket( $ticket_id );
            if ( is_wp_error( $checkin ) ) {
                return new WP_Error( $checkin->get_error_code(), $checkin->get_error_message(), [ 'status' => 400 ] );
            }
        } else {
            return new WP_Error( 'checkin_unavailable', 'La función de check-in no está disponible.', [ 'status' => 500 ] );
        }

        $request_id = sanitize_text_field( (string) $request->get_param( 'request_id' ) );
        if ( ! preg_match( '/^[a-zA-Z0-9._:-]{12,160}$/', $request_id ) ) {
            $request_id = 'android-kiosk-' . $ticket_id . '-' . wp_generate_uuid4();
        }
        $paper = eventosapp_kiosk_api_paper_config( $event_id );
        $ticket     = eventosapp_kiosk_api_ticket_payload( $ticket_id );

        return rest_ensure_response( [
            'message'     => ! empty( $checkin['already_checked'] ) || ! empty( $checkin['already'] )
                ? 'El check-in ya estaba registrado. La escarapela se enviará nuevamente a impresión.'
                : 'Check-in registrado. La escarapela fue enviada a la cola de impresión.',
            'already'     => ! empty( $checkin['already_checked'] ) || ! empty( $checkin['already'] ),
            'request_id'  => $request_id,
            'badge_url'   => eventosapp_kiosk_api_badge_url( $ticket_id, $event_id, $request_id ),
            'paper'       => $paper,
            'ticket'      => $ticket,
            'event_id'    => $event_id,
            'ticket_id'   => $ticket_id,
            'copies'      => 1,
        ] );
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_badge' ) ) {
    function eventosapp_kiosk_api_badge( WP_REST_Request $request ) {
        $ticket_id = absint( $request['id'] );
        $event_id  = absint( $request->get_param( 'event_id' ) );
        $expires   = absint( $request->get_param( 'expires' ) );
        $request_id= sanitize_text_field( (string) $request->get_param( 'request_id' ) );
        $signature = sanitize_text_field( (string) $request->get_param( 'signature' ) );

        if ( ! $ticket_id || ! $event_id || ! $expires || $request_id === '' || $signature === '' ) {
            status_header( 400 );
            exit( 'Solicitud incompleta.' );
        }
        if ( $expires < time() || $expires > time() + ( 3 * HOUR_IN_SECONDS ) ) {
            status_header( 403 );
            exit( 'El enlace de impresión expiró.' );
        }

        $expected = eventosapp_kiosk_api_badge_signature( $ticket_id, $event_id, $expires, $request_id );
        if ( ! hash_equals( $expected, $signature ) ) {
            status_header( 403 );
            exit( 'Firma inválida.' );
        }
        if ( get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
            status_header( 404 );
            exit( 'Ticket inválido.' );
        }

        $ticket_event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        if ( ! $ticket_event_id || $ticket_event_id !== $event_id ) {
            status_header( 403 );
            exit( 'El ticket no corresponde al evento.' );
        }
        if ( ! function_exists( 'eventosapp_get_badge_html_from_event' ) ) {
            status_header( 500 );
            exit( 'La función de escarapela no está disponible.' );
        }

        $html = eventosapp_get_badge_html_from_event( $event_id, $ticket_id, false );
        if ( ! is_string( $html ) || $html === '' ) {
            status_header( 500 );
            exit( 'No fue posible generar la escarapela.' );
        }

        nocache_headers();
        header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
        header( 'X-Content-Type-Options: nosniff' );
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML completo generado por el motor interno de escarapelas.
        exit;
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_route_urls' ) ) {
    /**
     * Devuelve las dos formas válidas de consumir una ruta REST de WordPress.
     * La variante rest_route funciona incluso cuando los enlaces permanentes
     * o las reglas /wp-json/ no están disponibles en el servidor.
     */
    function eventosapp_kiosk_api_route_urls( $route = '/health' ) {
        $route = '/' . ltrim( (string) $route, '/' );
        $rest_path = EVENTOSAPP_KIOSK_API_NAMESPACE . $route;

        return [
            'pretty' => rest_url( $rest_path ),
            'query'  => add_query_arg(
                'rest_route',
                '/' . $rest_path,
                trailingslashit( home_url( '/' ) )
            ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_registered_route_status' ) ) {
    function eventosapp_kiosk_api_registered_route_status() {
        $required = [
            '/auth/login'                 => 'POST',
            '/auth/logout'                => 'POST',
            '/events'                     => 'GET',
            '/events/(?P<id>\d+)'       => 'GET',
            '/events/(?P<id>\d+)/search'=> 'POST',
            '/tickets/(?P<id>\d+)'      => 'GET',
            '/tickets/(?P<id>\d+)/print'=> 'POST',
            '/badge/(?P<id>\d+)'        => 'GET',
            '/health'                     => 'GET',
        ];

        $routes = rest_get_server()->get_routes();
        $status = [];
        foreach ( $required as $suffix => $method ) {
            $full = '/' . EVENTOSAPP_KIOSK_API_NAMESPACE . $suffix;
            $status[] = [
                'route'      => $full,
                'method'     => $method,
                'registered' => isset( $routes[ $full ] ),
            ];
        }

        return $status;
    }
}

if ( ! function_exists( 'eventosapp_kiosk_api_health' ) ) {
    /**
     * Endpoint público sin credenciales para confirmar que WordPress cargó el
     * archivo y registró las rutas. No expone tokens, usuarios ni datos de eventos.
     */
    function eventosapp_kiosk_api_health( WP_REST_Request $request = null ) {
        $dependencies = [
            'role_permissions' => function_exists( 'eventosapp_role_can' ),
            'ticket_search'    => function_exists( 'eventosapp_self_checkin_find_tickets_by_auth_field' )
                || function_exists( 'eventosapp_self_checkin_find_tickets_by_identifier' ),
            'ticket_payload'   => function_exists( 'eventosapp_self_checkin_ticket_payload' ),
            'checkin'          => function_exists( 'eventosapp_register_ticket_checkin' )
                || function_exists( 'eventosapp_self_checkin_mark_ticket' ),
            'badge'            => function_exists( 'eventosapp_get_badge_html_from_event' ),
        ];
        $healthy = ! in_array( false, $dependencies, true );

        return rest_ensure_response( [
            'ok'            => $healthy,
            'service'       => 'EventosApp Kiosko Android API',
            'version'       => EVENTOSAPP_KIOSK_API_VERSION,
            'namespace'     => EVENTOSAPP_KIOSK_API_NAMESPACE,
            'loaded'        => true,
            'site_url'      => home_url( '/' ),
            'server_time'   => current_time( 'mysql' ),
            'server_time_gmt'=> current_time( 'mysql', true ),
            'urls'          => eventosapp_kiosk_api_route_urls( '/health' ),
            'dependencies'  => $dependencies,
        ] );
    }
}

add_action( 'rest_api_init', static function () {
    register_rest_route( EVENTOSAPP_KIOSK_API_NAMESPACE, '/health', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventosapp_kiosk_api_health',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( EVENTOSAPP_KIOSK_API_NAMESPACE, '/auth/login', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'eventosapp_kiosk_api_login',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( EVENTOSAPP_KIOSK_API_NAMESPACE, '/auth/logout', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'eventosapp_kiosk_api_logout',
        'permission_callback' => 'eventosapp_kiosk_api_permission',
    ] );

    register_rest_route( EVENTOSAPP_KIOSK_API_NAMESPACE, '/events', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventosapp_kiosk_api_events',
        'permission_callback' => 'eventosapp_kiosk_api_permission',
    ] );

    register_rest_route( EVENTOSAPP_KIOSK_API_NAMESPACE, '/events/(?P<id>\d+)', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventosapp_kiosk_api_event_config',
        'permission_callback' => 'eventosapp_kiosk_api_permission',
        'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
    ] );

    register_rest_route( EVENTOSAPP_KIOSK_API_NAMESPACE, '/events/(?P<id>\d+)/search', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'eventosapp_kiosk_api_search',
        'permission_callback' => 'eventosapp_kiosk_api_permission',
        'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
    ] );

    register_rest_route( EVENTOSAPP_KIOSK_API_NAMESPACE, '/tickets/(?P<id>\d+)', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventosapp_kiosk_api_get_ticket',
        'permission_callback' => 'eventosapp_kiosk_api_permission',
        'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
    ] );

    register_rest_route( EVENTOSAPP_KIOSK_API_NAMESPACE, '/tickets/(?P<id>\d+)/print', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'eventosapp_kiosk_api_print',
        'permission_callback' => 'eventosapp_kiosk_api_permission',
        'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
    ] );

    register_rest_route( EVENTOSAPP_KIOSK_API_NAMESPACE, '/badge/(?P<id>\d+)', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventosapp_kiosk_api_badge',
        'permission_callback' => '__return_true',
        'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
    ] );
} );
