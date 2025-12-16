<?php
/**
 * EventosApp - QR Ecosystem
 * Sistema de QR diferenciados por medio de distribución
 * 
 * Genera 5 tipos de QR para cada ticket:
 * - email: QR para correos electrónicos
 * - pdf: QR para archivos PDF
 * - google_wallet: QR para Google Wallet
 * - apple_wallet: QR para Apple Wallet  
 * - badge: QR para escarapela (con funcionalidad especial de networking)
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Estructura de un QR codificado:
 * [TICKET_ID]|[MEDIO]
 * 
 * Ejemplo: ABC123XYZ|email
 * Ejemplo badge: ABC123XYZ|badge
 */

/**
 * Codifica un ticket ID con el identificador de medio
 * 
 * @param string $ticket_id ID del ticket
 * @param string $medio Tipo de medio: email, pdf, google_wallet, apple_wallet, badge
 * @return string QR codificado
 */
function eventosapp_qr_encode($ticket_id, $medio = 'email') {
    $medios_validos = ['email', 'pdf', 'google_wallet', 'apple_wallet', 'badge'];
    
    if (!in_array($medio, $medios_validos, true)) {
        $medio = 'email';
    }
    
    return $ticket_id . '|' . $medio;
}

/**
 * Decodifica un QR y extrae el ticket ID y el medio
 * Es compatible con QR antiguos (sin medio) y nuevos (con medio)
 * 
 * @param string $qr_data Datos del QR escaneado
 * @return array ['ticket_id' => string, 'medio' => string, 'legacy' => bool]
 */
function eventosapp_qr_decode($qr_data) {
    $qr_data = trim($qr_data);
    
    // Verificar si tiene el separador de medio
    if (strpos($qr_data, '|') !== false) {
        $parts = explode('|', $qr_data, 2);
        return [
            'ticket_id' => $parts[0],
            'medio' => $parts[1] ?? 'unknown',
            'legacy' => false
        ];
    }
    
    // QR legacy (sin medio especificado)
    return [
        'ticket_id' => $qr_data,
        'medio' => 'legacy',
        'legacy' => true
    ];
}

/**
 * Genera la URL del QR para un ticket y medio específico
 * 
 * @param string $ticket_id ID del ticket
 * @param string $medio Tipo de medio
 * @return string URL del archivo QR
 */
function eventosapp_get_qr_url_for_medio($ticket_id, $medio = 'email') {
    if (!$ticket_id) return '';
    
    $upload_dir = wp_upload_dir();
    $qr_dir = $upload_dir['basedir'] . '/eventosapp-qr/';
    $qr_url = $upload_dir['baseurl'] . '/eventosapp-qr/';
    
    if (!file_exists($qr_dir)) {
        wp_mkdir_p($qr_dir);
    }
    
    // Nombre del archivo incluye el medio
    $filename = $ticket_id . '_' . $medio . '.png';
    $qr_file = $qr_dir . $filename;
    $qr_src = $qr_url . $filename;
    
    // Generar QR si no existe
    if (!file_exists($qr_file)) {
        $qr_data = eventosapp_qr_encode($ticket_id, $medio);
        
        // Para badge, genera URL en lugar de código plano
        if ($medio === 'badge') {
            $qr_data = eventosapp_get_badge_networking_url($ticket_id);
        }
        
        if (function_exists('QRcode')) {
            QRcode::png($qr_data, $qr_file, 'L', 6, 2);
        }
    }
    
    if (file_exists($qr_file)) {
        return $qr_src . '?v=' . filemtime($qr_file);
    }
    
    return '';
}

/**
 * Genera todos los QR para un ticket (todos los medios)
 * 
 * @param string $ticket_id ID del ticket
 * @return array Arreglo asociativo con URLs de cada medio
 */
function eventosapp_generate_all_qr_codes($ticket_id) {
    $medios = ['email', 'pdf', 'google_wallet', 'apple_wallet', 'badge'];
    $qr_urls = [];
    
    foreach ($medios as $medio) {
        $qr_urls[$medio] = eventosapp_get_qr_url_for_medio($ticket_id, $medio);
    }
    
    return $qr_urls;
}

/**
 * Obtiene la URL de la página de networking con validador
 * Para el QR del badge que redirige a autenticación de networking
 * 
 * @param string $ticket_id ID del ticket
 * @return string URL completa
 */
function eventosapp_get_badge_networking_url($ticket_id) {
    $base_url = home_url('/networking/');
    return add_query_arg('validator', $ticket_id, $base_url);
}

/**
 * Registra el uso de un medio específico cuando se hace checkin
 * 
 * @param int $ticket_post_id ID del post del ticket
 * @param string $medio Medio utilizado
 * @param string $day Día del checkin (formato Y-m-d)
 */
function eventosapp_track_qr_medio_usage($ticket_post_id, $medio, $day) {
    // Obtener historial de medios usados
    $medio_history = get_post_meta($ticket_post_id, '_eventosapp_qr_medio_history', true);
    if (!is_array($medio_history)) {
        $medio_history = [];
    }
    
    // Registrar uso
    $medio_history[] = [
        'medio' => $medio,
        'dia' => $day,
        'fecha_hora' => current_time('mysql'),
        'timestamp' => time()
    ];
    
    update_post_meta($ticket_post_id, '_eventosapp_qr_medio_history', $medio_history);
    
    // Contador por medio
    $medio_counter = get_post_meta($ticket_post_id, '_eventosapp_qr_medio_count', true);
    if (!is_array($medio_counter)) {
        $medio_counter = [];
    }
    
    if (!isset($medio_counter[$medio])) {
        $medio_counter[$medio] = 0;
    }
    $medio_counter[$medio]++;
    
    update_post_meta($ticket_post_id, '_eventosapp_qr_medio_count', $medio_counter);
    
    // Guardar el último medio usado
    update_post_meta($ticket_post_id, '_eventosapp_qr_last_medio_used', $medio);
}

/**
 * Obtiene estadísticas de uso de medios para un ticket
 * 
 * @param int $ticket_post_id ID del post del ticket
 * @return array Estadísticas de uso
 */
function eventosapp_get_qr_medio_stats($ticket_post_id) {
    $medio_history = get_post_meta($ticket_post_id, '_eventosapp_qr_medio_history', true);
    $medio_counter = get_post_meta($ticket_post_id, '_eventosapp_qr_medio_count', true);
    $last_medio = get_post_meta($ticket_post_id, '_eventosapp_qr_last_medio_used', true);
    
    return [
        'history' => is_array($medio_history) ? $medio_history : [],
        'counter' => is_array($medio_counter) ? $medio_counter : [],
        'last_used' => $last_medio ?: 'ninguno',
        'total_scans' => is_array($medio_history) ? count($medio_history) : 0
    ];
}

/**
 * Obtiene estadísticas globales de uso de medios para un evento
 * 
 * @param int $event_id ID del evento
 * @return array Estadísticas globales por medio
 */
function eventosapp_get_event_qr_medio_stats($event_id) {
    global $wpdb;
    
    $tickets = get_posts([
        'post_type' => 'eventosapp_ticket',
        'post_status' => 'any',
        'numberposts' => -1,
        'meta_query' => [
            [
                'key' => '_eventosapp_ticket_evento_id',
                'value' => $event_id,
                'compare' => '='
            ]
        ],
        'fields' => 'ids'
    ]);
    
    $stats = [
        'email' => 0,
        'pdf' => 0,
        'google_wallet' => 0,
        'apple_wallet' => 0,
        'badge' => 0,
        'legacy' => 0,
        'unknown' => 0,
        'total' => 0
    ];
    
    foreach ($tickets as $ticket_id) {
        $counter = get_post_meta($ticket_id, '_eventosapp_qr_medio_count', true);
        if (is_array($counter)) {
            foreach ($counter as $medio => $count) {
                if (isset($stats[$medio])) {
                    $stats[$medio] += $count;
                    $stats['total'] += $count;
                } else {
                    $stats['unknown'] += $count;
                    $stats['total'] += $count;
                }
            }
        }
    }
    
    return $stats;
}

/**
 * Regenera todos los QR de un ticket (útil después de actualizar el sistema)
 * 
 * @param int $ticket_post_id ID del post del ticket
 * @return bool Success
 */
function eventosapp_regenerate_ticket_qr_codes($ticket_post_id) {
    $ticket_id = get_post_meta($ticket_post_id, 'eventosapp_ticketID', true);
    if (!$ticket_id) return false;
    
    $upload_dir = wp_upload_dir();
    $qr_dir = $upload_dir['basedir'] . '/eventosapp-qr/';
    
    // Eliminar QR existentes
    $medios = ['email', 'pdf', 'google_wallet', 'apple_wallet', 'badge'];
    foreach ($medios as $medio) {
        $filename = $ticket_id . '_' . $medio . '.png';
        $qr_file = $qr_dir . $filename;
        if (file_exists($qr_file)) {
            unlink($qr_file);
        }
    }
    
    // Regenerar
    eventosapp_generate_all_qr_codes($ticket_id);
    
    return true;
}

/**
 * Hook: al crear o actualizar un ticket, genera todos sus QR
 */
add_action('save_post_eventosapp_ticket', function($post_id, $post, $update) {
    // Evitar auto-saves y revisiones
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    
    $ticket_id = get_post_meta($post_id, 'eventosapp_ticketID', true);
    if ($ticket_id) {
        eventosapp_generate_all_qr_codes($ticket_id);
    }
}, 20, 3);

/**
 * Shortcode para página de networking con validador (badge QR)
 * Uso: [eventosapp_badge_networking]
 */
add_shortcode('eventosapp_badge_networking', function() {
    // Verificar si hay un validator en la URL
    $validator = isset($_GET['validator']) ? sanitize_text_field($_GET['validator']) : '';
    
    if (!$validator) {
        return '<div style="padding:20px;text-align:center;color:#dc2626;">
            <h2>Acceso inválido</h2>
            <p>Esta página requiere un código de validación. Por favor, escanea un QR de badge válido.</p>
        </div>';
    }
    
    // Decodificar el QR (puede venir como ticket_id directo desde la URL)
    $decoded = eventosapp_qr_decode($validator);
    $ticket_id_to_find = $decoded['ticket_id'];
    
    // Buscar el ticket
    global $wpdb;
    $ticket_post_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'eventosapp_ticketID' AND meta_value = %s LIMIT 1",
        $ticket_id_to_find
    ));
    
    if (!$ticket_post_id) {
        return '<div style="padding:20px;text-align:center;color:#dc2626;">
            <h2>Ticket no encontrado</h2>
            <p>El código escaneado no corresponde a ningún ticket válido.</p>
        </div>';
    }
    
    // Obtener el evento del ticket
    $event_id = get_post_meta($ticket_post_id, '_eventosapp_ticket_evento_id', true);
    
    if (!$event_id) {
        return '<div style="padding:20px;text-align:center;color:#dc2626;">
            <h2>Error</h2>
            <p>No se pudo identificar el evento asociado a este ticket.</p>
        </div>';
    }
    
    // Mostrar el shortcode de networking auth con el evento
    return do_shortcode('[eventosapp_qr_networking_auth event="' . $event_id . '"]');
});

/**
 * Labels amigables para los medios
 */
function eventosapp_qr_medio_label($medio) {
    $labels = [
        'email' => 'Correo Electrónico',
        'pdf' => 'PDF',
        'google_wallet' => 'Google Wallet',
        'apple_wallet' => 'Apple Wallet',
        'badge' => 'Escarapela',
        'legacy' => 'QR Antiguo',
        'unknown' => 'Desconocido'
    ];
    
    return $labels[$medio] ?? $medio;
}
