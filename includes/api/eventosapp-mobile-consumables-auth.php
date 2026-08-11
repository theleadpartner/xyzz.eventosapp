<?php
/** Permite login Android a usuarios cuyo único módulo móvil sea Consumibles. */
if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
    if ( $result !== null || ! $request instanceof WP_REST_Request ) return $result;
    if ( strtoupper( $request->get_method() ) !== 'POST' || $request->get_route() !== '/eventosapp-kiosk/v1/auth/login' ) return $result;
    if ( ! function_exists( 'eventosapp_kiosk_api_create_token' ) || ! function_exists( 'eventosapp_mobile_app_all_event_ids' ) ) return $result;

    $login = sanitize_text_field( (string) $request->get_param( 'login' ) );
    $password = (string) $request->get_param( 'password' );
    $device = sanitize_text_field( (string) $request->get_param( 'device_name' ) );
    if ( $login === '' || $password === '' ) return new WP_Error( 'missing_credentials', 'Escribe el usuario y la contraseña.', [ 'status' => 400 ] );

    $rate_key = function_exists( 'eventosapp_kiosk_api_login_rate_key' )
        ? eventosapp_kiosk_api_login_rate_key( $login )
        : 'evapp_mobile_login_' . md5( strtolower( $login ) . '|' . ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
    $attempts = absint( get_transient( $rate_key ) );
    if ( $attempts >= 8 ) return new WP_Error( 'too_many_attempts', 'Demasiados intentos. Espera 15 minutos y vuelve a intentar.', [ 'status' => 429 ] );

    $user = wp_authenticate( $login, $password );
    if ( is_wp_error( $user ) ) {
        set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
        return new WP_Error( 'invalid_credentials', 'Usuario o contraseña incorrectos.', [ 'status' => 401 ] );
    }
    delete_transient( $rate_key );
    wp_set_current_user( $user->ID );

    $counts = [ 'kiosk'=>0, 'staff_qr'=>0, 'qr_localidad'=>0, 'qr_session'=>0, 'qr_double_auth'=>0, 'consumables_staff'=>0 ];
    foreach ( eventosapp_mobile_app_all_event_ids() as $event_id ) {
        if ( function_exists( 'eventosapp_mobile_kiosk_offline_user_can_event' ) && eventosapp_mobile_kiosk_offline_user_can_event( $event_id, $user->ID ) ) $counts['kiosk']++;
        if ( function_exists( 'eventosapp_mobile_app_user_can_feature_in_event' ) && eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user->ID, 'qr' ) ) $counts['staff_qr']++;
        if ( function_exists( 'eventosapp_mobile_advanced_modules_for_event' ) ) {
            foreach ( eventosapp_mobile_advanced_modules_for_event( $event_id, $user->ID ) as $module ) if ( isset( $counts[$module] ) ) $counts[$module]++;
        }
        if ( function_exists( 'eventosapp_mobile_consumables_user_can_event' ) && eventosapp_mobile_consumables_user_can_event( $event_id, $user->ID ) ) $counts['consumables_staff']++;
    }
    if ( array_sum( $counts ) === 0 ) return new WP_Error( 'forbidden', 'Tu usuario no tiene módulos móviles autorizados.', [ 'status' => 403 ] );

    $token = eventosapp_kiosk_api_create_token( $user->ID, $device );
    if ( is_wp_error( $token ) ) return $token;
    $user_payload = function_exists( 'eventosapp_kiosk_api_user_payload' ) ? eventosapp_kiosk_api_user_payload( $user ) : [ 'id'=>absint($user->ID), 'display_name'=>sanitize_text_field($user->display_name), 'email'=>sanitize_email($user->user_email) ];

    return rest_ensure_response( [
        'token' => $token['token'], 'expires_at' => gmdate( 'c', $token['expires_at'] ), 'user' => $user_payload,
        'capabilities' => [
            'kiosk'=>$counts['kiosk']>0, 'kiosk_event_count'=>$counts['kiosk'],
            'staff_qr'=>$counts['staff_qr']>0, 'staff_event_count'=>$counts['staff_qr'],
            'advanced_qr'=>($counts['qr_localidad']+$counts['qr_session']+$counts['qr_double_auth'])>0,
            'consumables_staff'=>$counts['consumables_staff']>0, 'consumables_event_count'=>$counts['consumables_staff'],
            'mobile_api'=>defined('EVENTOSAPP_MOBILE_APP_API_VERSION') ? EVENTOSAPP_MOBILE_APP_API_VERSION : '1.1.0',
            'consumables_api'=>defined('EVENTOSAPP_MOBILE_CONSUMABLES_API_VERSION') ? EVENTOSAPP_MOBILE_CONSUMABLES_API_VERSION : '1.0.0',
        ],
    ] );
}, 3, 3 );
