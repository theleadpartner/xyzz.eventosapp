<?php
/**
 * Sistema de Cron para envíos programados de códigos de doble autenticación
 * 
 * @package EventosApp
 */

if (!defined('ABSPATH')) exit;

// ========================================
// REGISTRAR HOOKS DE CRON
// ========================================

add_action('eventosapp_send_auth_codes_scheduled', 'eventosapp_cron_send_auth_codes', 10, 1);

/**
 * Programar envío de códigos de autenticación
 * 
 * @param int $event_id ID del evento
 */
function eventosapp_schedule_auth_codes_send($event_id) {
    // Cancelar cualquier cron previo para este evento
    $hook = 'eventosapp_send_auth_codes_scheduled';
    $args = [$event_id];
    $timestamp = wp_next_scheduled($hook, $args);
    
    if ($timestamp) {
        wp_unschedule_event($timestamp, $hook, $args);
    }

    // Obtener configuración
    $enabled = get_post_meta($event_id, '_eventosapp_ticket_double_auth_enabled', true);
    $scheduled_datetime = get_post_meta($event_id, '_eventosapp_double_auth_scheduled_datetime', true);

    if ($enabled !== '1' || !$scheduled_datetime) {
        return; // No programar si no está configurado
    }

    // Programar nuevo evento
    wp_schedule_single_event($scheduled_datetime, $hook, $args);
}

/**
 * Ejecutar envío programado de códigos
 * 
 * @param int $event_id ID del evento
 */
function eventosapp_cron_send_auth_codes($event_id) {
    // Verificar que el evento existe y tiene doble auth activa
    $enabled = get_post_meta($event_id, '_eventosapp_ticket_double_auth_enabled', true);
    if ($enabled !== '1') {
        return;
    }

    $auth_mode = get_post_meta($event_id, '_eventosapp_ticket_double_auth_mode', true);

    // Obtener días del evento
    $event_days = [];
    if (function_exists('eventosapp_get_event_days')) {
        $event_days = eventosapp_get_event_days($event_id);
    }

    if (empty($event_days)) {
        return;
    }

    // Determinar qué día enviar (no se usa directamente pero se mantiene por compatibilidad futura)
    $today = current_time('Y-m-d');
    
    if ($auth_mode === 'all_days') {
        // Modo multi-día: enviar código del primer día en el envío programado.
        // Los días siguientes se programarán automáticamente.
        $first_day = $event_days[0];
        
        // Enviar códigos para el primer día (códigos por día)
        eventosapp_send_mass_auth_codes_for_day($event_id, $first_day);
        
        // Programar envíos para los días siguientes (por ejemplo 06:00 AM de cada día)
        for ($i = 1; $i < count($event_days); $i++) {
            $day = $event_days[$i];
            
            try {
                $event_tz = get_post_meta($event_id, '_eventosapp_zona_horaria', true);
                if (!$event_tz) {
                    $event_tz = wp_timezone_string();
                }
                
                $dt = new DateTime($day . ' 06:00:00', new DateTimeZone($event_tz));
                $timestamp = $dt->getTimestamp();
                
                // Solo programar si es futuro
                if ($timestamp > current_time('timestamp')) {
                    wp_schedule_single_event(
                        $timestamp,
                        'eventosapp_send_auth_codes_for_specific_day',
                        [$event_id, $day]
                    );
                }
            } catch (Exception $e) {
                // Continuar con el siguiente día si hay error
                continue;
            }
        }
        
    } else {
        // Modo clásico / primer día: envío único usando códigos generales
        // (compatible con eventos de día único y multi-día en modo "first_day")
        eventosapp_send_mass_auth_codes($event_id);
    }
}


/**
 * Hook para envío de código de un día específico
 */
add_action('eventosapp_send_auth_codes_for_specific_day', 'eventosapp_cron_send_auth_codes_specific_day', 10, 2);

function eventosapp_cron_send_auth_codes_specific_day($event_id, $date) {
    $enabled = get_post_meta($event_id, '_eventosapp_ticket_double_auth_enabled', true);
    if ($enabled !== '1') {
        return;
    }

    // Enviar códigos para este día específico
    eventosapp_send_mass_auth_codes_for_day($event_id, $date);
}
