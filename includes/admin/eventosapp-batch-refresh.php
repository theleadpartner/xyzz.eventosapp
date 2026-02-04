<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * EventosApp Batch Refresh - Funciones de Procesamiento
 * 
 * VERSION 3.0 - Refactorizado para trabajar con el nuevo sistema de procesamiento por lote
 * Este archivo contiene solo las funciones de procesamiento de tickets.
 * El metabox y la UI han sido movidos a eventosapp-batch-processor.php
 *
 * Modos de actualización soportados:
 * - Modo Completo: Regenera TODO (Wallets, PDF, ICS, search blob, QR nuevos y legacy)
 * - Modo QR Faltantes: Solo CREA los QR nuevos que falten, sin tocar los existentes ni el legacy
 */

/**
 * Cantidad de tickets asociados a un evento (rápido)
 */
function eventosapp_count_tickets_by_event($evento_id) {
    global $wpdb;
    $evento_id = (int)$evento_id;
    if ($evento_id <= 0) return 0;
    $meta_key = '_eventosapp_ticket_evento_id';

    $sql = $wpdb->prepare("
        SELECT COUNT(p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = %d
        WHERE p.post_type = 'eventosapp_ticket' AND p.post_status <> 'auto-draft'
    ", $meta_key, $evento_id);

    $cnt = (int) $wpdb->get_var($sql);
    return $cnt;
}

/**
 * Procesar un ticket completo según el modo especificado
 * 
 * @param int $ticket_id ID del ticket
 * @param string $mode Modo de actualización: 'complete' o 'qr_missing'
 * @return mixed true en modo complete, array con estadísticas en modo qr_missing
 */
function eventosapp_refresh_ticket_full($ticket_id, $mode = 'complete') {
    $ticket_id = (int)$ticket_id;
    if ($ticket_id <= 0) return false;

    $evento_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    if (!$evento_id) return false;

    // ========================================================================
    // MODO QR_MISSING: Solo genera QR faltantes sin tocar existentes
    // ========================================================================
    if ($mode === 'qr_missing') {
        if ( class_exists('EventosApp_QR_Manager') ) {
            try {
                $qr_manager = EventosApp_QR_Manager::get_instance();
                
                if ($qr_manager && method_exists($qr_manager, 'generate_missing_qr_codes')) {
                    $result = $qr_manager->generate_missing_qr_codes($ticket_id);
                    
                    $stats = [
                        'generated' => 0,
                        'skipped' => 0,
                        'failed' => 0
                    ];
                    
                    foreach ($result as $type => $data) {
                        if (isset($data['status'])) {
                            if ($data['status'] === 'generated') {
                                $stats['generated']++;
                            } elseif ($data['status'] === 'exists') {
                                $stats['skipped']++;
                            } elseif ($data['status'] === 'failed') {
                                $stats['failed']++;
                            }
                        }
                    }
                    
                    // Regenerar wallets solo si se generaron QR nuevos
                    if ($stats['generated'] > 0) {
                        // Android Wallet
                        $wallet_android_on = get_post_meta($evento_id, '_eventosapp_ticket_wallet_android', true);
                        if ($wallet_android_on === '1' || $wallet_android_on === 1 || $wallet_android_on === true) {
                            if ( function_exists('eventosapp_generar_enlace_wallet_android') ) {
                                try {
                                    delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_android');
                                    delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url');
                                    eventosapp_generar_enlace_wallet_android($ticket_id, false);
                                    error_log("EventosApp Batch QR: Android Wallet regenerado para ticket {$ticket_id}");
                                } catch (\Throwable $e) {
                                    error_log("EventosApp Batch QR: Error regenerando Android Wallet para ticket {$ticket_id}: " . $e->getMessage());
                                }
                            }
                        }
                        
                        // Apple Wallet
                        $wallet_ios_on = get_post_meta($evento_id, '_eventosapp_ticket_wallet_apple', true);
                        if ($wallet_ios_on === '1' || $wallet_ios_on === 1 || $wallet_ios_on === true) {
                            if ( function_exists('eventosapp_apple_generate_pass') ) {
                                try {
                                    delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple');
                                    delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url');
                                    delete_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url');
                                    eventosapp_apple_generate_pass($ticket_id);
                                    error_log("EventosApp Batch QR: Apple Wallet regenerado para ticket {$ticket_id}");
                                } catch (\Throwable $e) {
                                    error_log("EventosApp Batch QR: Error regenerando Apple Wallet para ticket {$ticket_id}: " . $e->getMessage());
                                }
                            } elseif ( function_exists('eventosapp_generar_enlace_wallet_apple') ) {
                                try { 
                                    delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple');
                                    delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url');
                                    eventosapp_generar_enlace_wallet_apple($ticket_id);
                                    error_log("EventosApp Batch QR: Apple Wallet (fallback) regenerado para ticket {$ticket_id}");
                                } catch (\Throwable $e) {
                                    error_log("EventosApp Batch QR: Error regenerando Apple Wallet (fallback) para ticket {$ticket_id}: " . $e->getMessage());
                                }
                            }
                        }
                    }
                    
                    update_post_meta($ticket_id, '_eventosapp_last_batch_refresh', current_time('mysql'));
                    update_post_meta($ticket_id, '_eventosapp_last_batch_mode', 'qr_missing');
                    update_post_meta($ticket_id, '_eventosapp_last_batch_qr_generated', $stats['generated']);
                    update_post_meta($ticket_id, '_eventosapp_last_batch_qr_skipped', $stats['skipped']);
                    
                    return $stats;
                } else {
                    error_log("EventosApp Batch QR: Método generate_missing_qr_codes no disponible");
                }
            } catch (\Throwable $e) {
                error_log("EventosApp Batch QR: Error en modo qr_missing para ticket {$ticket_id}: " . $e->getMessage());
                $stats['failed'] = 5;
            }
        } else {
            error_log("EventosApp Batch QR: Clase EventosApp_QR_Manager no disponible");
            $stats['failed'] = 5;
        }

        return $stats;
    }

    // ========================================================================
    // MODO COMPLETE: Regenera TODO (comportamiento original mejorado)
    // ========================================================================

    // 0) Regenerar TODOS los QR (incluyendo legacy) usando el QR Manager
    if ( class_exists('EventosApp_QR_Manager') ) {
        try {
            $qr_manager = EventosApp_QR_Manager::get_instance();
            
            if ($qr_manager && method_exists($qr_manager, 'regenerate_all_qr_codes_complete')) {
                $qr_count = $qr_manager->regenerate_all_qr_codes_complete($ticket_id);
                error_log("EventosApp Batch Complete: Regenerados {$qr_count} QR para ticket {$ticket_id}");
            }
        } catch (\Throwable $e) {
            error_log("EventosApp Batch Complete: Error regenerando QR para ticket {$ticket_id}: " . $e->getMessage());
        }
    }

    // 1) Wallet Android ON/OFF por evento
    $wallet_android_on = get_post_meta($evento_id, '_eventosapp_ticket_wallet_android', true);
    if ($wallet_android_on === '1' || $wallet_android_on === 1 || $wallet_android_on === true) {
        if ( function_exists('eventosapp_generar_enlace_wallet_android') ) {
            // Eliminar antes de regenerar
            delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_android');
            delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url');
            try { eventosapp_generar_enlace_wallet_android($ticket_id, false); } catch (\Throwable $e) {}
        }
    } else {
        if ( function_exists('eventosapp_eliminar_enlace_wallet_android') ) {
            try { eventosapp_eliminar_enlace_wallet_android($ticket_id); } catch (\Throwable $e) {}
        }
    }

    // 2) Wallet Apple ON/OFF por evento
    $wallet_ios_on = get_post_meta($evento_id, '_eventosapp_ticket_wallet_apple', true);
    if ($wallet_ios_on === '1' || $wallet_ios_on === 1 || $wallet_ios_on === true) {
        // Eliminar antes de regenerar
        delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple');
        delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url');
        delete_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url');
        
        if ( function_exists('eventosapp_apple_generate_pass') ) {
            try { eventosapp_apple_generate_pass($ticket_id); } catch (\Throwable $e) {}
        } elseif ( function_exists('eventosapp_generar_enlace_wallet_apple') ) {
            try { eventosapp_generar_enlace_wallet_apple($ticket_id); } catch (\Throwable $e) {}
        }
    } else {
        // Limpieza si Apple está off
        delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple');
        delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url');
        delete_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url');
    }

    // 3) PDF
    if ( function_exists('eventosapp_ticket_generar_pdf') ) {
        delete_post_meta($ticket_id, '_eventosapp_ticket_pdf_url');
        delete_post_meta($ticket_id, '_eventosapp_ticket_pdf_path');
        try { eventosapp_ticket_generar_pdf($ticket_id); } catch (\Throwable $e) {}
    }

    // 4) ICS
    if ( function_exists('eventosapp_ticket_generar_ics') ) {
        delete_post_meta($ticket_id, '_eventosapp_ticket_ics_url');
        delete_post_meta($ticket_id, '_eventosapp_ticket_ics_path');
        try { eventosapp_ticket_generar_ics($ticket_id); } catch (\Throwable $e) {}
    }

    // 5) Rebuild índice de búsqueda
    if ( function_exists('eventosapp_ticket_build_search_blob') ) {
        try { eventosapp_ticket_build_search_blob($ticket_id); } catch (\Throwable $e) {}
    }

    // 6) Marca de "última actualización batch" (auditoría opcional)
    update_post_meta($ticket_id, '_eventosapp_last_batch_refresh', current_time('mysql'));
    update_post_meta($ticket_id, '_eventosapp_last_batch_mode', 'complete');

    return true;
}
