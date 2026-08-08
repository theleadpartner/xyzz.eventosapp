<?php
/**
 * Sistema de programación para envíos de códigos de doble autenticación.
 *
 * La cola central de EventosApp es la vía principal cuando está disponible.
 * WP-Cron se conserva únicamente como fallback y como disparador heredado.
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'eventosapp_send_auth_codes_scheduled', 'eventosapp_cron_send_auth_codes', 10, 1 );
add_action( 'eventosapp_send_auth_codes_for_specific_day', 'eventosapp_cron_send_auth_codes_specific_day', 10, 2 );

function eventosapp_mark_day_as_sent( $event_id, $date ) {
    $event_id = absint( $event_id );
    $date = sanitize_text_field( (string) $date );
    if ( ! $event_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return;

    $days_sent = get_post_meta( $event_id, '_eventosapp_double_auth_days_sent', true );
    $days_sent = is_array( $days_sent ) ? $days_sent : [];

    if ( ! in_array( $date, $days_sent, true ) ) {
        $days_sent[] = $date;
        sort( $days_sent );
        update_post_meta( $event_id, '_eventosapp_double_auth_days_sent', $days_sent );
    }
}

function eventosapp_is_day_code_sent( $event_id, $date ) {
    $days_sent = get_post_meta( absint( $event_id ), '_eventosapp_double_auth_days_sent', true );
    return is_array( $days_sent ) && in_array( sanitize_text_field( (string) $date ), $days_sent, true );
}

function eventosapp_get_sent_days( $event_id ) {
    $days_sent = get_post_meta( absint( $event_id ), '_eventosapp_double_auth_days_sent', true );
    return is_array( $days_sent ) ? $days_sent : [];
}

function eventosapp_clear_sent_days( $event_id ) {
    delete_post_meta( absint( $event_id ), '_eventosapp_double_auth_days_sent' );
}

/**
 * Calcula el instante de envío de un día posterior respetando:
 * - cantidad (X)
 * - unidad (horas, días o semanas antes)
 * - hora local configurada
 * - zona horaria real del evento
 */
function eventosapp_double_auth_followup_timestamp( $event_id, $day ) {
    $event_id = absint( $event_id );
    $day = sanitize_text_field( (string) $day );
    if ( ! $event_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) return 0;

    $amount = max( 1, absint( get_post_meta( $event_id, '_eventosapp_double_auth_followup_amount', true ) ?: 1 ) );
    $unit = sanitize_key( (string) get_post_meta( $event_id, '_eventosapp_double_auth_followup_unit', true ) );
    $clock = sanitize_text_field( (string) get_post_meta( $event_id, '_eventosapp_double_auth_followup_time', true ) );

    if ( ! in_array( $unit, [ 'hours', 'days', 'weeks' ], true ) ) $unit = 'days';
    if ( ! preg_match( '/^\d{2}:\d{2}$/', $clock ) ) $clock = '06:00';

    $timezone = function_exists( 'eventosapp_double_auth_event_timezone' )
        ? eventosapp_double_auth_event_timezone( $event_id )
        : wp_timezone();

    try {
        $dt = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $day . ' ' . $clock, $timezone );
        $errors = DateTimeImmutable::getLastErrors();
        if ( ! $dt instanceof DateTimeImmutable || ( $errors !== false && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) ) {
            return 0;
        }

        if ( $unit === 'hours' ) {
            $dt = $dt->sub( new DateInterval( 'PT' . $amount . 'H' ) );
        } elseif ( $unit === 'weeks' ) {
            $dt = $dt->sub( new DateInterval( 'P' . $amount . 'W' ) );
        } else {
            $dt = $dt->sub( new DateInterval( 'P' . $amount . 'D' ) );
        }

        return $dt->getTimestamp();
    } catch ( Throwable $e ) {
        return 0;
    }
}

/**
 * Construye la programación efectiva que debe reflejarse en Cola y Tareas.
 */
function eventosapp_double_auth_schedule_plan( $event_id ) {
    $event_id = absint( $event_id );
    if ( ! $event_id || get_post_meta( $event_id, '_eventosapp_ticket_double_auth_enabled', true ) !== '1' ) return [];

    $initial = absint( get_post_meta( $event_id, '_eventosapp_double_auth_scheduled_datetime', true ) );
    if ( ! $initial ) return [];

    $mode = get_post_meta( $event_id, '_eventosapp_ticket_double_auth_mode', true ) ?: 'first_day';
    $days = function_exists( 'eventosapp_double_auth_event_days' )
        ? eventosapp_double_auth_event_days( $event_id )
        : ( function_exists( 'eventosapp_get_event_days' ) ? (array) eventosapp_get_event_days( $event_id ) : [] );

    $plan = [
        [
            'key'       => 'initial',
            'day'       => ( $mode === 'all_days' && ! empty( $days ) ) ? (string) $days[0] : '',
            'timestamp' => $initial,
            'kind'      => 'initial',
        ],
    ];

    if ( $mode === 'all_days' && count( $days ) > 1 ) {
        for ( $i = 1; $i < count( $days ); $i++ ) {
            $day = sanitize_text_field( (string) $days[$i] );
            $timestamp = eventosapp_double_auth_followup_timestamp( $event_id, $day );
            if ( $timestamp > 0 ) {
                $plan[] = [
                    'key'       => 'day_' . str_replace( '-', '', $day ),
                    'day'       => $day,
                    'timestamp' => $timestamp,
                    'kind'      => 'followup',
                ];
            }
        }
    }

    return $plan;
}

function eventosapp_double_auth_clear_legacy_cron( $event_id ) {
    $event_id = absint( $event_id );
    if ( ! $event_id ) return;

    wp_clear_scheduled_hook( 'eventosapp_send_auth_codes_scheduled', [ $event_id ] );
    wp_clear_scheduled_hook( 'eventosapp_auto_send_auth_codes', [ $event_id ] );

    $days = function_exists( 'eventosapp_double_auth_event_days' )
        ? eventosapp_double_auth_event_days( $event_id )
        : ( function_exists( 'eventosapp_get_event_days' ) ? (array) eventosapp_get_event_days( $event_id ) : [] );

    foreach ( $days as $day ) {
        wp_clear_scheduled_hook( 'eventosapp_send_auth_codes_for_specific_day', [ $event_id, $day ] );
    }
}

/**
 * Vía principal: sincroniza con la cola central.
 * Fallback: WP-Cron, para no romper instalaciones donde la cola no esté cargada.
 */
function eventosapp_schedule_auth_codes_send( $event_id ) {
    $event_id = absint( $event_id );
    if ( ! $event_id ) return false;

    eventosapp_double_auth_clear_legacy_cron( $event_id );

    if ( function_exists( 'eventosapp_task_queue_sync_double_auth_schedule' ) ) {
        $adapter_ok = ! function_exists( 'eventosapp_task_queue_get_adapter' )
            || eventosapp_task_queue_get_adapter( 'double_auth_scheduled' );

        if ( $adapter_ok ) {
            return (bool) eventosapp_task_queue_sync_double_auth_schedule( $event_id );
        }
    }

    $plan = eventosapp_double_auth_schedule_plan( $event_id );
    if ( empty( $plan ) ) return false;

    $scheduled_any = false;
    foreach ( $plan as $item ) {
        $timestamp = absint( $item['timestamp'] ?? 0 );
        if ( ! $timestamp || $timestamp <= time() ) continue;

        if ( ( $item['kind'] ?? '' ) === 'initial' ) {
            $scheduled_any = (bool) wp_schedule_single_event(
                $timestamp,
                'eventosapp_send_auth_codes_scheduled',
                [ $event_id ]
            ) || $scheduled_any;
        } elseif ( ! empty( $item['day'] ) ) {
            $scheduled_any = (bool) wp_schedule_single_event(
                $timestamp,
                'eventosapp_send_auth_codes_for_specific_day',
                [ $event_id, $item['day'] ]
            ) || $scheduled_any;
        }
    }

    return $scheduled_any;
}

/**
 * Compatibilidad con llamadas históricas que programaban solo días posteriores.
 */
function eventosapp_schedule_remaining_days( $event_id, $event_days ) {
    $event_id = absint( $event_id );
    $event_days = is_array( $event_days ) ? array_values( $event_days ) : [];
    if ( ! $event_id || count( $event_days ) < 2 ) return;

    if ( function_exists( 'eventosapp_task_queue_sync_double_auth_schedule' ) ) {
        eventosapp_task_queue_sync_double_auth_schedule( $event_id );
        return;
    }

    $days_sent = eventosapp_get_sent_days( $event_id );
    for ( $i = 1; $i < count( $event_days ); $i++ ) {
        $day = sanitize_text_field( (string) $event_days[$i] );
        if ( in_array( $day, $days_sent, true ) ) continue;

        $timestamp = eventosapp_double_auth_followup_timestamp( $event_id, $day );
        if ( $timestamp <= time() ) continue;

        if ( ! wp_next_scheduled( 'eventosapp_send_auth_codes_for_specific_day', [ $event_id, $day ] ) ) {
            wp_schedule_single_event(
                $timestamp,
                'eventosapp_send_auth_codes_for_specific_day',
                [ $event_id, $day ]
            );
        }
    }
}

function eventosapp_cron_send_auth_codes( $event_id ) {
    $event_id = absint( $event_id );
    if ( ! $event_id || get_post_meta( $event_id, '_eventosapp_ticket_double_auth_enabled', true ) !== '1' ) return;

    $mode = get_post_meta( $event_id, '_eventosapp_ticket_double_auth_mode', true ) ?: 'first_day';
    $days = function_exists( 'eventosapp_double_auth_event_days' ) ? eventosapp_double_auth_event_days( $event_id ) : [];
    $target_day = ( $mode === 'all_days' && $days ) ? (string) reset( $days ) : '';

    if ( function_exists( 'eventosapp_task_queue_sync_double_auth_schedule' ) ) {
        eventosapp_task_queue_sync_double_auth_schedule( $event_id );
        if ( function_exists( 'eventosapp_task_queue_activate_double_auth_due_task' ) ) {
            eventosapp_task_queue_activate_double_auth_due_task( $event_id, $target_day );
        }
        return;
    }

    if ( $target_day ) {
        if ( eventosapp_is_day_code_sent( $event_id, $target_day ) ) return;
        eventosapp_send_mass_auth_codes_for_day( $event_id, $target_day );
        eventosapp_schedule_remaining_days( $event_id, $days );
        return;
    }

    $first_day = $days ? (string) reset( $days ) : '';
    if ( $first_day && eventosapp_is_day_code_sent( $event_id, $first_day ) ) return;
    eventosapp_send_mass_auth_codes( $event_id );
}

function eventosapp_cron_send_auth_codes_specific_day( $event_id, $date ) {
    $event_id = absint( $event_id );
    $date = sanitize_text_field( (string) $date );

    if ( ! $event_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return;
    if ( get_post_meta( $event_id, '_eventosapp_ticket_double_auth_enabled', true ) !== '1' ) return;
    if ( eventosapp_is_day_code_sent( $event_id, $date ) ) return;

    if ( function_exists( 'eventosapp_task_queue_sync_double_auth_schedule' ) ) {
        eventosapp_task_queue_sync_double_auth_schedule( $event_id );
        if ( function_exists( 'eventosapp_task_queue_activate_double_auth_due_task' ) ) {
            eventosapp_task_queue_activate_double_auth_due_task( $event_id, $date );
        }
        return;
    }

    eventosapp_send_mass_auth_codes_for_day( $event_id, $date );
}
