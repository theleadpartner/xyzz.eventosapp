<?php
/**
 * EventosApp - Compatibilidad ampliada de Preconfiguraciones de Eventos.
 *
 * Extiende el snapshot controlado de includes/admin/eventosapp-event-presets.php
 * usando sus filtros públicos. Incorpora metaboxes agregados después del motor
 * original y evita que una preconfiguración copie estado operativo o tareas.
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'EVENTOSAPP_EVENT_PRESETS_COMPAT_VERSION' ) ) {
    define( 'EVENTOSAPP_EVENT_PRESETS_COMPAT_VERSION', '2026.08.20.1' );
}

/**
 * Indica si el request actual está aplicando una preconfiguración al evento.
 */
function eventosapp_event_presets_compat_is_applying() {
    return is_admin()
        && ! empty( $_POST['eventosapp_apply_preset'] )
        && ! empty( $_POST['eventosapp_event_presets_nonce'] );
}

/**
 * Metas incorporadas por metaboxes posteriores que sí representan configuración
 * reutilizable del evento.
 */
function eventosapp_event_presets_compat_copyable_keys() {
    return [
        // Sesiones.
        '_eventosapp_sesiones_internas',
        '_eventosapp_sesiones_policy_no_localidad',

        // Métricas personalizadas.
        '_eventosapp_custom_metrics_layout',

        // Landing virtual: se excluye deliberadamente el path/slug único.
        '_eventosapp_virtual_landing_header_url',
        '_eventosapp_virtual_landing_organizer_logo_url',
        '_eventosapp_virtual_landing_intro_title',
        '_eventosapp_virtual_landing_intro_text',
        '_eventosapp_virtual_landing_button_label',
        '_eventosapp_virtual_landing_colors',

        // Funciones Extra del Ticket añadidas después del snapshot original.
        '_eventosapp_ticket_auto_whatsapp_manual',
        '_eventosapp_ticket_company_checkin_monitor',
        '_eventosapp_ticket_live_raffle_enabled',

        // Confirmación de Asistencia.
        '_eventosapp_attendance_confirmation_schedule',
        '_eventosapp_attendance_confirmation_whatsapp_image',

        // Tickets / Recordatorios WhatsApp.
        '_eventosapp_ticket_reminders_enabled',
        '_eventosapp_ticket_reminders',
        '_eventosapp_ticket_reminder_rules',

        // Control de Consumibles.
        '_eventosapp_consumables_enabled',
        '_eventosapp_consumables_config',
    ];
}

/**
 * Estado operativo que jamás debe viajar dentro de un preset.
 */
function eventosapp_event_presets_compat_excluded_keys() {
    return [
        '_eventosapp_virtual_landing_path',
        '_eventosapp_attendance_confirmation_schedule_log',
        '_eventosapp_attendance_confirmation_schedule_last_error',
        '_eventosapp_ticket_reminders_scheduled_keys',
        '_eventosapp_ticket_reminders_executed',
        '_eventosapp_ticket_reminders_last_run',
        '_eventosapp_ticket_reminders_log',
    ];
}

/**
 * Amplía la allowlist del snapshot y captura/limpia tareas del evento destino
 * justo antes de que un preset sustituya una programación existente.
 */
function eventosapp_event_presets_compat_copy_meta_key( $decision, $meta_key, $event_id ) {
    $meta_key = (string) $meta_key;
    $event_id = absint( $event_id );

    if ( in_array( $meta_key, eventosapp_event_presets_compat_excluded_keys(), true ) ) {
        return false;
    }

    if ( eventosapp_event_presets_compat_is_applying() ) {
        if ( $meta_key === '_eventosapp_whatsapp_flow_schedule' ) {
            $current = get_post_meta( $event_id, $meta_key, true );
            $task_id = is_array( $current ) ? absint( $current['queue_task_id'] ?? 0 ) : 0;
            if ( $task_id ) {
                $GLOBALS['eventosapp_preset_previous_flow_task_' . $event_id] = $task_id;
            }
        } elseif ( $meta_key === '_eventosapp_attendance_confirmation_schedule' ) {
            if ( function_exists( 'eventosapp_attendance_confirmation_unschedule_event' ) ) {
                eventosapp_attendance_confirmation_unschedule_event( $event_id );
            }
        }
    }

    if ( in_array( $meta_key, eventosapp_event_presets_compat_copyable_keys(), true ) ) {
        return true;
    }

    return $decision;
}
add_filter( 'eventosapp_event_preset_copy_meta_key', 'eventosapp_event_presets_compat_copy_meta_key', 40, 3 );

/**
 * Normaliza el valor del Flow programado. Los filtros son reutilizables; la
 * fecha, hora, task ID, firmas y timestamps pertenecen al evento de origen.
 */
function eventosapp_event_presets_compat_prepare_flow_schedule( $value ) {
    if ( ! is_array( $value ) ) {
        return [];
    }

    $filters = isset( $value['filters'] ) && is_array( $value['filters'] ) ? $value['filters'] : [];
    foreach ( [ 'evento_id', 'event_id', 'flow_id', 'event_date', 'last_sent_from', 'last_sent_to', 'created_from', 'created_to' ] as $key ) {
        unset( $filters[ $key ] );
    }

    return [
        'enabled'       => 0,
        'date'          => '',
        'time'          => '',
        'filters'       => $filters,
        'respect_rules' => ! empty( $value['respect_rules'] ) ? '1' : '0',
    ];
}

/**
 * Conserva canales, filtros y plantillas de Confirmación de Asistencia, pero
 * obliga al evento destino a definir una nueva fecha/hora de ejecución.
 */
function eventosapp_event_presets_compat_prepare_attendance_schedule( $value ) {
    if ( ! is_array( $value ) ) {
        return [];
    }

    $filters = isset( $value['filters'] ) && is_array( $value['filters'] ) ? $value['filters'] : [];
    unset( $filters['evento_id'], $filters['event_id'] );

    $channels = isset( $value['channels'] ) && is_array( $value['channels'] ) ? $value['channels'] : [];
    $channels = array_values( array_unique( array_filter( array_map( 'sanitize_key', $channels ) ) ) );

    return [
        'enabled'              => 0,
        'date'                 => '',
        'time'                 => '',
        'channels'             => $channels,
        'filters'              => $filters,
        'email_template'       => sanitize_file_name( (string) ( $value['email_template'] ?? '' ) ),
        'email_subject'        => sanitize_text_field( (string) ( $value['email_subject'] ?? '' ) ),
        'email_message'        => sanitize_textarea_field( (string) ( $value['email_message'] ?? '' ) ),
        'whatsapp_template_id' => sanitize_key( (string) ( $value['whatsapp_template_id'] ?? '' ) ),
    ];
}

/**
 * Los recordatorios relativos son reutilizables. Los de fecha exacta conservan
 * el diseño pero quedan desactivados y sin la fecha absoluta del evento origen.
 */
function eventosapp_event_presets_compat_prepare_ticket_reminders( $value ) {
    if ( ! is_array( $value ) ) {
        return [];
    }

    foreach ( $value as $index => $reminder ) {
        if ( ! is_array( $reminder ) ) {
            continue;
        }

        if ( sanitize_key( (string) ( $reminder['schedule_type'] ?? '' ) ) === 'exact' ) {
            $reminder['enabled'] = '0';
            $reminder['exact_datetime'] = '';
        }

        foreach ( [ 'scheduled_key', 'task_id', 'queue_task_id', 'last_run', 'last_error', 'executed' ] as $runtime_key ) {
            unset( $reminder[ $runtime_key ] );
        }

        $value[ $index ] = $reminder;
    }

    return $value;
}

/**
 * La definición de Consumibles sí es reutilizable, pero su fecha de actualización
 * es trazabilidad del evento origen y no forma parte del diseño del preset.
 */
function eventosapp_event_presets_compat_prepare_consumables( $value ) {
    if ( ! is_array( $value ) ) {
        return [];
    }
    unset( $value['updated_at'] );
    return $value;
}

/**
 * Sanitiza valores al capturar presets nuevos.
 */
function eventosapp_event_presets_compat_prepare_meta_value( $value, $meta_key, $event_id ) {
    switch ( (string) $meta_key ) {
        case '_eventosapp_whatsapp_flow_schedule':
            return eventosapp_event_presets_compat_prepare_flow_schedule( $value );

        case '_eventosapp_attendance_confirmation_schedule':
            return eventosapp_event_presets_compat_prepare_attendance_schedule( $value );

        case '_eventosapp_ticket_reminders':
            return eventosapp_event_presets_compat_prepare_ticket_reminders( $value );

        case '_eventosapp_consumables_config':
            return eventosapp_event_presets_compat_prepare_consumables( $value );
    }

    return $value;
}
add_filter( 'eventosapp_event_preset_prepare_meta_value', 'eventosapp_event_presets_compat_prepare_meta_value', 40, 3 );

/**
 * Sanea también presets históricos v2 al aplicarlos. El motor original no vuelve
 * a ejecutar prepare_meta_value() durante apply, por eso esta fase posterior es
 * necesaria para que un snapshot antiguo no reintroduzca queue_task_id o fechas.
 */
function eventosapp_event_presets_compat_after_apply( $event_id, $preset_id, $meta ) {
    $event_id = absint( $event_id );
    $meta = is_array( $meta ) ? $meta : [];

    if ( array_key_exists( '_eventosapp_whatsapp_flow_schedule', $meta ) ) {
        $previous_task_id = absint( $GLOBALS['eventosapp_preset_previous_flow_task_' . $event_id] ?? 0 );
        if ( $previous_task_id && function_exists( 'eventosapp_whatsapp_event_flow_schedule_cancel_task' ) ) {
            eventosapp_whatsapp_event_flow_schedule_cancel_task(
                $previous_task_id,
                'Programación reemplazada al aplicar una preconfiguración de evento.'
            );
        }
        unset( $GLOBALS['eventosapp_preset_previous_flow_task_' . $event_id] );

        $schedule = get_post_meta( $event_id, '_eventosapp_whatsapp_flow_schedule', true );
        update_post_meta(
            $event_id,
            '_eventosapp_whatsapp_flow_schedule',
            eventosapp_event_presets_compat_prepare_flow_schedule( $schedule )
        );
    }

    if ( array_key_exists( '_eventosapp_attendance_confirmation_schedule', $meta ) ) {
        $schedule = get_post_meta( $event_id, '_eventosapp_attendance_confirmation_schedule', true );
        update_post_meta(
            $event_id,
            '_eventosapp_attendance_confirmation_schedule',
            eventosapp_event_presets_compat_prepare_attendance_schedule( $schedule )
        );
    }

    if ( array_key_exists( '_eventosapp_ticket_reminders', $meta ) ) {
        $items = get_post_meta( $event_id, '_eventosapp_ticket_reminders', true );
        update_post_meta(
            $event_id,
            '_eventosapp_ticket_reminders',
            eventosapp_event_presets_compat_prepare_ticket_reminders( $items )
        );

        if ( function_exists( 'eventosapp_ticket_reminders_sync_event' ) ) {
            eventosapp_ticket_reminders_sync_event( $event_id, 'preset_apply' );
        }
    }

    if ( array_key_exists( '_eventosapp_consumables_config', $meta ) ) {
        $config = get_post_meta( $event_id, '_eventosapp_consumables_config', true );
        update_post_meta(
            $event_id,
            '_eventosapp_consumables_config',
            eventosapp_event_presets_compat_prepare_consumables( $config )
        );
    }
}
add_action( 'eventosapp_event_preset_applied', 'eventosapp_event_presets_compat_after_apply', 40, 3 );
