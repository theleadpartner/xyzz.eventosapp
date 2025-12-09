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
 * Marca un día como "enviado" en el registro del evento
 * 
 * @param int $event_id ID del evento
 * @param string $date Fecha en formato Y-m-d
 */
function eventosapp_mark_day_as_sent($event_id, $date) {
    $days_sent = get_post_meta($event_id, '_eventosapp_double_auth_days_sent', true);
    
    if (!is_array($days_sent)) {
        $days_sent = [];
    }
    
    if (!in_array($date, $days_sent)) {
        $days_sent[] = $date;
        update_post_meta($event_id, '_eventosapp_double_auth_days_sent', $days_sent);
    }
}

/**
 * Verifica si ya se envió el código de un día específico
 * 
 * @param int $event_id ID del evento
 * @param string $date Fecha en formato Y-m-d
 * @return bool True si ya se envió, false si no
 */
function eventosapp_is_day_code_sent($event_id, $date) {
    $days_sent = get_post_meta($event_id, '_eventosapp_double_auth_days_sent', true);
    
    if (!is_array($days_sent)) {
        return false;
    }
    
    return in_array($date, $days_sent);
}

/**
 * Obtiene la lista de días ya enviados
 * 
 * @param int $event_id ID del evento
 * @return array Array de fechas en formato Y-m-d
 */
function eventosapp_get_sent_days($event_id) {
    $days_sent = get_post_meta($event_id, '_eventosapp_double_auth_days_sent', true);
    
    if (!is_array($days_sent)) {
        return [];
    }
    
    return $days_sent;
}

/**
 * Limpia el registro de días enviados (útil para reprogramar todo el evento)
 * 
 * @param int $event_id ID del evento
 */
function eventosapp_clear_sent_days($event_id) {
    delete_post_meta($event_id, '_eventosapp_double_auth_days_sent');
}

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
    
    // Obtener días del evento para verificar si ya se enviaron
    $auth_mode = get_post_meta($event_id, '_eventosapp_ticket_double_auth_mode', true);
    $event_days = [];
    if (function_exists('eventosapp_get_event_days')) {
        $event_days = eventosapp_get_event_days($event_id);
    }
    
    // Verificar si ya se enviaron códigos del primer día
    // Solo reprogramar si NO se ha enviado el primer día
    if (!empty($event_days) && $auth_mode === 'all_days') {
        $first_day = $event_days[0];
        
        if (eventosapp_is_day_code_sent($event_id, $first_day)) {
            // Ya se envió el primer día, no reprogramar el envío inicial
            // Solo reprogramar días siguientes si es necesario
            eventosapp_schedule_remaining_days($event_id, $event_days);
            return;
        }
    }
    
    // Si llegamos aquí, programar el envío inicial
    wp_schedule_single_event($scheduled_datetime, $hook, $args);
}

/**
 * Programa los días restantes de un evento multi-día
 * 
 * @param int $event_id ID del evento
 * @param array $event_days Array de días del evento
 */
function eventosapp_schedule_remaining_days($event_id, $event_days) {
    if (empty($event_days)) {
        return;
    }
    
    $days_sent = eventosapp_get_sent_days($event_id);
    $event_tz = get_post_meta($event_id, '_eventosapp_zona_horaria', true);
    
    if (!$event_tz) {
        $event_tz = wp_timezone_string();
    }
    
    // Programar envíos para los días que NO se han enviado (excepto el primero que ya se chequeó)
    for ($i = 1; $i < count($event_days); $i++) {
        $day = $event_days[$i];
        
        // Si ya se envió este día, saltar
        if (in_array($day, $days_sent)) {
            continue;
        }
        
        try {
            $dt = new DateTime($day . ' 06:00:00', new DateTimeZone($event_tz));
            $timestamp = $dt->getTimestamp();
            
            // Solo programar si es futuro
            if ($timestamp > current_time('timestamp')) {
                // Verificar si ya existe este evento programado
                $existing = wp_next_scheduled('eventosapp_send_auth_codes_for_specific_day', [$event_id, $day]);
                
                if (!$existing) {
                    wp_schedule_single_event(
                        $timestamp,
                        'eventosapp_send_auth_codes_for_specific_day',
                        [$event_id, $day]
                    );
                }
            }
        } catch (Exception $e) {
            // Continuar con el siguiente día si hay error
            continue;
        }
    }
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
    
    if ($auth_mode === 'all_days') {
        // Modo multi-día: enviar código del primer día en el envío programado
        $first_day = $event_days[0];
        
        // Verificar si ya se envió el código de este día
        if (eventosapp_is_day_code_sent($event_id, $first_day)) {
            // Ya se envió, no volver a enviar
            return;
        }
        
        // Enviar códigos para el primer día (códigos por día)
        eventosapp_send_mass_auth_codes_for_day($event_id, $first_day);
        
        // Marcar el primer día como enviado
        eventosapp_mark_day_as_sent($event_id, $first_day);
        
        // Programar envíos para los días siguientes
        eventosapp_schedule_remaining_days($event_id, $event_days);
        
    } else {
        // Modo clásico / primer día: envío único usando códigos generales
        // Verificar si ya se envió
        if (empty($event_days)) {
            return;
        }
        
        $first_day = $event_days[0];
        
        if (eventosapp_is_day_code_sent($event_id, $first_day)) {
            // Ya se envió, no volver a enviar
            return;
        }
        
        eventosapp_send_mass_auth_codes($event_id);
        
        // Marcar como enviado
        eventosapp_mark_day_as_sent($event_id, $first_day);
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
    
    // Verificar si ya se envió el código de este día
    if (eventosapp_is_day_code_sent($event_id, $date)) {
        // Ya se envió, no volver a enviar
        return;
    }

    // Enviar códigos para este día específico
    eventosapp_send_mass_auth_codes_for_day($event_id, $date);
    
    // Marcar este día como enviado
    eventosapp_mark_day_as_sent($event_id, $date);
}
