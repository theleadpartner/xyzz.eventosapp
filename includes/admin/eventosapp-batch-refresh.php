<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Metabox en CPT Evento para refrescar (en batches) los tickets asociados,
 * regenerando assets/derivados (Wallets, PDF, ICS, search blob, etc.)
 *
 * VERSION 2.1 - Dos modos de actualización:
 * - Modo Completo: Regenera TODO (Wallets, PDF, ICS, search blob, QR nuevos y legacy)
 * - Modo QR Faltantes: Solo CREA los QR nuevos que falten, sin tocar los existentes ni el legacy
 *
 * Flujo:
 *  - Metabox con selector de tamaño de lote, modo de actualización y botón
 *  - Disparo vía GET a admin-post.php?action=eventosapp_refresh_tickets_batch
 *  - Handler procesa "page" de tickets (per_page configurable) y redirige a la siguiente
 *  - Al finalizar, elimina el lock y vuelve al editor del evento con un aviso
 */

define('EVAPP_REFRESH_DEFAULT_PER_PAGE', 50);
define('EVAPP_REFRESH_LOCK_TTL',        15 * MINUTE_IN_SECONDS); // lock 15 min

/**
 * Metabox en eventosapp_event
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'eventosapp_refresh_tickets_batch',
        'Actualizar Tickets (Batch)',
        'eventosapp_render_refresh_tickets_batch_metabox',
        'eventosapp_event',
        'side',
        'high'
    );
});

/**
 * Render del metabox (CPT Evento)
 */
function eventosapp_render_refresh_tickets_batch_metabox($post) {
    $evento_id = (int)$post->ID;

    $per_page = (int) get_option('eventosapp_refresh_per_page', EVAPP_REFRESH_DEFAULT_PER_PAGE);
    if (!in_array($per_page, [10,25,50,100], true)) $per_page = EVAPP_REFRESH_DEFAULT_PER_PAGE;

    $nonce = wp_create_nonce('eventosapp_refresh_tickets_batch_' . $evento_id);
    $admin_post = admin_url('admin-post.php');

    // Mostrar lock si existe
    $lock_key = eventosapp_refresh_lock_key($evento_id);
    $locked   = (bool) get_transient($lock_key);

    ?>
    <div style="font-size:12px; line-height:1.45">
        <p>Ejecuta una actualización <strong>ordenada por lotes</strong> de todos los tickets de este evento.</p>

        <?php if ($locked): ?>
            <div style="background:#fff8e5;border:1px solid #eac54f;padding:8px;border-radius:4px;margin-bottom:8px">
                <strong>Proceso en curso:</strong> ya hay una ejecución activa para este evento. Espera a que finalice o vuelve a intentarlo más tarde.
            </div>
        <?php endif; ?>

        <!-- Selector de Modo de Actualización -->
        <div style="margin-bottom:15px;padding:10px;background:#f0f0f1;border-radius:4px;">
            <label style="display:block;margin-bottom:8px;"><strong>🎯 Modo de Actualización</strong></label>
            
<label style="display:block;margin-bottom:6px;cursor:pointer;">
                <input type="radio" name="evapp_refresh_mode" value="complete" checked style="margin-right:5px;">
                <strong>Regenerar Completo</strong> - Regenera TODO (Wallets, PDF, ICS, QR)
            </label>
            
            <label style="display:block;cursor:pointer;">
                <input type="radio" name="evapp_refresh_mode" value="qr_missing" style="margin-right:5px;">
                <strong>Solo QR Faltantes</strong> - Crea QR nuevos solo si no existen
            </label>
            
            <p class="description" style="margin:8px 0 0 0;font-size:11px;color:#666;">
                📌 <strong>QR Faltantes:</strong> Genera únicamente los QR del QR Manager que no existan. No toca QR existentes ni el legacy. Ideal para tickets antiguos sin QR nuevos.
            </p>
        </div>

        <label for="evapp_refresh_batch_size"><strong>Tickets por lote</strong></label><br>
        <select id="evapp_refresh_batch_size" style="width:100%;margin:4px 0 10px;">
            <option value="10"  <?php selected($per_page,10);  ?>>10 (muy seguro)</option>
            <option value="25"  <?php selected($per_page,25);  ?>>25 (seguro)</option>
            <option value="50"  <?php selected($per_page,50);  ?>>50 (recomendado)</option>
            <option value="100" <?php selected($per_page,100); ?>>100 (rápido, más carga)</option>
        </select>

        <button type="button" id="evapp-btn-refresh-batch" class="button button-primary" style="width:100%;" <?php disabled($locked, true); ?>>
            🔄 Actualizar tickets de este evento
        </button>

        <p class="description" style="margin-top:8px;">El proceso usa lock por evento y redirecciones entre lotes para proteger el servidor.</p>

        <script>
        (function(){
            var btn   = document.getElementById('evapp-btn-refresh-batch');
            var sel   = document.getElementById('evapp_refresh_batch_size');
            var base  = <?php echo wp_json_encode($admin_post); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var eid   = <?php echo (int) $evento_id; ?>;

            if (btn) {
                btn.addEventListener('click', function(e){
                    e.preventDefault();
                    
                    // Obtener modo seleccionado
                    var modeRadio = document.querySelector('input[name="evapp_refresh_mode"]:checked');
                    var mode = modeRadio ? modeRadio.value : 'complete';
                    
                    var size = sel ? sel.value : '<?php echo (int)$per_page; ?>';
                    var url  = base + '?action=eventosapp_refresh_tickets_batch'
                                     + '&evento_id=' + encodeURIComponent(eid)
                                     + '&per_page='  + encodeURIComponent(size)
                                     + '&mode='      + encodeURIComponent(mode)
                                     + '&page=1'
                                     + '&_wpnonce='  + encodeURIComponent(nonce);
                    window.location.href = url;
                });
            }
        })();
        </script>
    </div>
    <?php
}

/**
 * Admin-post handler: procesa una "página" (batch) y se auto-redirige hasta terminar.
 */
add_action('admin_post_eventosapp_refresh_tickets_batch', 'eventosapp_refresh_tickets_batch_handler');
function eventosapp_refresh_tickets_batch_handler() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes', 403);
    }

    $evento_id = isset($_REQUEST['evento_id']) ? (int) $_REQUEST['evento_id'] : 0;
    $per_page  = isset($_REQUEST['per_page'])  ? (int) $_REQUEST['per_page']  : EVAPP_REFRESH_DEFAULT_PER_PAGE;
    $page      = isset($_REQUEST['page'])      ? (int) $_REQUEST['page']      : 1;
    $mode      = isset($_REQUEST['mode'])      ? sanitize_text_field($_REQUEST['mode']) : 'complete';

    if (!$evento_id) wp_die('Solicitud inválida', 400);

    $nonce_ok = isset($_REQUEST['_wpnonce']) && wp_verify_nonce($_REQUEST['_wpnonce'], 'eventosapp_refresh_tickets_batch_'.$evento_id);
    if ( ! $nonce_ok ) wp_die('Nonce inválido', 403);

    if (!in_array($per_page, [10,25,50,100], true)) $per_page = EVAPP_REFRESH_DEFAULT_PER_PAGE;
    if ($page < 1) $page = 1;
    
// Validar modo
    if (!in_array($mode, ['complete', 'qr_missing'], true)) $mode = 'complete';

    // Lock anti-concurrencia
    $lock_key = eventosapp_refresh_lock_key($evento_id);
    if ( get_transient($lock_key) && empty($_REQUEST['continue']) ) {
        // Ya hay un proceso activo
        $back = add_query_arg([
            'post'        => $evento_id,
            'action'      => 'edit',
            'evapp_rf'    => 'locked',
        ], admin_url('post.php'));
        wp_safe_redirect($back);
        exit;
    }
    set_transient($lock_key, 1, EVAPP_REFRESH_LOCK_TTL);

    // Contar total solo en la primera página para mostrar progreso (cacheable en opción temporal)
    $total = (int) get_option('eventosapp_refresh_total_'.$evento_id, 0);
    if ($page === 1 || $total <= 0) {
        $total = eventosapp_count_tickets_by_event($evento_id);
        update_option('eventosapp_refresh_total_'.$evento_id, $total, false);
    }

    // Obtener lote (page actual)
    $processed = eventosapp_refresh_tickets_batch_do($evento_id, $page, $per_page, $mode);

// Si no hubo tickets en este batch, hemos terminado
    if ($processed === 0) {
        delete_transient($lock_key);
        delete_option('eventosapp_refresh_total_'.$evento_id);

        // Recuperar estadísticas globales
        $global_stats = get_option('eventosapp_refresh_stats_'.$evento_id, [
            'total_processed' => 0,
            'qr_generated' => 0,
            'qr_skipped' => 0,
            'qr_failed' => 0
        ]);
        delete_option('eventosapp_refresh_stats_'.$evento_id);

        $mode_label = ($mode === 'qr_missing') ? 'QR Faltantes' : 'Completo';
        $msg_parts = ["Actualización {$mode_label} completada"];
        
        if ($mode === 'qr_missing') {
            $msg_parts[] = "Generados: {$global_stats['qr_generated']}";
            $msg_parts[] = "Ya existían: {$global_stats['qr_skipped']}";
            if ($global_stats['qr_failed'] > 0) {
                $msg_parts[] = "Fallidos: {$global_stats['qr_failed']}";
            }
        }
        
        $back = add_query_arg([
            'post'        => $evento_id,
            'action'      => 'edit',
            'evapp_rf'    => 'done',
            'evapp_msg'   => rawurlencode(implode(' | ', $msg_parts)),
        ], admin_url('post.php'));
        wp_safe_redirect($back);
        exit;
    }

    // Acumular estadísticas si es modo qr_missing
    if ($mode === 'qr_missing' && isset($result['stats'])) {
        $global_stats = get_option('eventosapp_refresh_stats_'.$evento_id, [
            'total_processed' => 0,
            'qr_generated' => 0,
            'qr_skipped' => 0,
            'qr_failed' => 0
        ]);
        
        $global_stats['total_processed'] += $processed;
        $global_stats['qr_generated'] += $result['stats']['generated'];
        $global_stats['qr_skipped'] += $result['stats']['skipped'];
        $global_stats['qr_failed'] += $result['stats']['failed'];
        
        update_option('eventosapp_refresh_stats_'.$evento_id, $global_stats, false);
    }

    // Siguiente "página"
    $next_page = $page + 1;

    // Redirigir a la siguiente iteración (mantiene el lock vivo)
    $next = add_query_arg([
        'action'    => 'eventosapp_refresh_tickets_batch',
        'evento_id' => $evento_id,
        'per_page'  => $per_page,
        'mode'      => $mode,
        'page'      => $next_page,
        '_wpnonce'  => $_REQUEST['_wpnonce'],
        'continue'  => 1, // indica que es la misma ejecución
    ], admin_url('admin-post.php'));

    // Pequeño respiro para no clavar el servidor si PHP-FPM es muy sensible
    // (en producción normalmente no hace falta)
    // usleep(200000); // 200ms

    wp_safe_redirect($next);
    exit;
}

/**
 * Procesa un batch de tickets: retorna cuántos tickets se procesaron en esta página.
 */
function eventosapp_refresh_tickets_batch_do($evento_id, $page, $per_page, $mode = 'complete') {
    $offset = ($page - 1) * $per_page;

    // Query light: solo IDs, no_found_rows para no calcular total en SQL
    $q = new WP_Query([
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'posts_per_page' => $per_page,
        'offset'         => $offset,
        'no_found_rows'  => true,
        'meta_query'     => [[
            'key'   => '_eventosapp_ticket_evento_id',
            'value' => (int)$evento_id,
            'compare' => '=',
            'type'  => 'NUMERIC',
        ]],
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);

    if ( ! $q->have_posts() ) return ['processed' => 0];

    // Optimiza contadores/flush durante el loop
    if ( function_exists('wp_defer_term_counting') ) wp_defer_term_counting(true);
    if ( function_exists('wp_defer_comment_counting') ) wp_defer_comment_counting(true);

    $batch_stats = ['generated' => 0, 'skipped' => 0, 'failed' => 0];

    foreach ($q->posts as $ticket_id) {
        $ticket_stats = eventosapp_refresh_one_ticket($ticket_id, $evento_id, $mode);
        
        if ($mode === 'qr_missing' && is_array($ticket_stats)) {
            $batch_stats['generated'] += $ticket_stats['generated'];
            $batch_stats['skipped'] += $ticket_stats['skipped'];
            $batch_stats['failed'] += $ticket_stats['failed'];
        }
    }

    if ( function_exists('wp_defer_term_counting') ) wp_defer_term_counting(false);
    if ( function_exists('wp_defer_comment_counting') ) wp_defer_comment_counting(false);

    // Limpia caches intermedias
    if ( function_exists('clean_post_cache') ) {
        foreach ($q->posts as $ticket_id) clean_post_cache($ticket_id);
    }

    return [
        'processed' => count($q->posts),
        'stats' => $batch_stats
    ];
}

/**
 * Refresca un ticket: regenera derivados como si "actualizaras" el ticket,
 * pero sin depender del nonce del editor.
 * 
 * @param int    $ticket_id  ID del ticket
 * @param int    $evento_id  ID del evento (opcional, se obtiene si no se pasa)
 * @param string $mode       Modo de actualización: 'complete' o 'qr_missing'
 * @return mixed bool|array  True/false para complete, array de stats para qr_missing
 */
function eventosapp_refresh_one_ticket($ticket_id, $evento_id = 0, $mode = 'complete') {
    $ticket_id = (int)$ticket_id;
    if ($ticket_id <= 0) return false;

    if (!$evento_id) {
        $evento_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
        if (!$evento_id) return false;
    }

    // ========================================================================
    // MODO QR_MISSING: Solo CREA los QR nuevos que falten (no toca existentes)
    // ========================================================================
    if ($mode === 'qr_missing') {
        $stats = ['generated' => 0, 'skipped' => 0, 'failed' => 0];
        
        // 1) Verificar si ya tiene todos los QR nuevos
        if ( class_exists('EventosApp_QR_Manager') ) {
            try {
                $qr_manager = EventosApp_QR_Manager::get_instance();
                
                if ($qr_manager && method_exists($qr_manager, 'has_all_new_qr_codes')) {
                    // Si ya tiene todos los QR, no hacer nada
                    if ($qr_manager->has_all_new_qr_codes($ticket_id)) {
                        error_log("EventosApp Batch QR: Ticket {$ticket_id} ya tiene todos los QR nuevos, saltando...");
                        $stats['skipped'] = 5; // Los 5 QR ya existen
                        
                        update_post_meta($ticket_id, '_eventosapp_last_batch_refresh', current_time('mysql'));
                        update_post_meta($ticket_id, '_eventosapp_last_batch_mode', 'qr_missing');
                        update_post_meta($ticket_id, '_eventosapp_last_batch_action', 'skipped_all_exist');
                        
                        return $stats;
                    }
                }
                
                // 2) Generar solo los QR faltantes (sin tocar los existentes)
                if ($qr_manager && method_exists($qr_manager, 'generate_missing_qr_codes')) {
                    $result = $qr_manager->generate_missing_qr_codes($ticket_id);
                    $stats = $result;
                    
                    error_log("EventosApp Batch QR: Ticket {$ticket_id} - Generados: {$result['generated']}, Ya existían: {$result['skipped']}, Fallidos: {$result['failed']}");
                    
                    // 3) Solo actualizar PDF/Wallets si se generó al menos un QR nuevo
                    if ($result['generated'] > 0) {
                        // Regenerar PDF
                        if ( function_exists('eventosapp_ticket_generar_pdf') ) {
                            try { 
                                delete_post_meta($ticket_id, '_eventosapp_ticket_pdf_url');
                                delete_post_meta($ticket_id, '_eventosapp_ticket_pdf_path');
                                eventosapp_ticket_generar_pdf($ticket_id);
                                error_log("EventosApp Batch QR: PDF regenerado para ticket {$ticket_id}");
                            } catch (\Throwable $e) {
                                error_log("EventosApp Batch QR: Error regenerando PDF para ticket {$ticket_id}: " . $e->getMessage());
                            }
                        }

                        // Regenerar Google Wallet
                        $wallet_android_on = get_post_meta($evento_id, '_eventosapp_ticket_wallet_android', true);
                        if ($wallet_android_on === '1' || $wallet_android_on === 1 || $wallet_android_on === true) {
                            if ( function_exists('eventosapp_generar_enlace_wallet_android') ) {
                                try { 
                                    delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_android');
                                    delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url');
                                    eventosapp_generar_enlace_wallet_android($ticket_id, false);
                                    error_log("EventosApp Batch QR: Google Wallet regenerado para ticket {$ticket_id}");
                                } catch (\Throwable $e) {
                                    error_log("EventosApp Batch QR: Error regenerando Google Wallet para ticket {$ticket_id}: " . $e->getMessage());
                                }
                            }
                        }

                        // Regenerar Apple Wallet
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
                    update_post_meta($ticket_id, '_eventosapp_last_batch_qr_generated', $result['generated']);
                    update_post_meta($ticket_id, '_eventosapp_last_batch_qr_skipped', $result['skipped']);
                    
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
 * Clave de transient para lock por evento
 */
function eventosapp_refresh_lock_key($evento_id) {
    return 'evapp_refresh_lock_' . (int)$evento_id;
}

/**
 * Avisos en el editor del evento
 */
add_action('admin_notices', function () {
    if ( ! is_admin() || ! isset($_GET['post'], $_GET['action']) || $_GET['action'] !== 'edit' ) return;
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'eventosapp_event' ) return;

    if ( isset($_GET['evapp_rf']) ) {
        $code = sanitize_text_field(wp_unslash($_GET['evapp_rf']));
        $msg  = isset($_GET['evapp_msg']) ? sanitize_text_field(wp_unslash($_GET['evapp_msg'])) : '';

        if ($code === 'locked') {
            echo '<div class="notice notice-warning is-dismissible"><p><b>EventosApp:</b> Ya existe un proceso de actualización por lotes en curso para este evento.</p></div>';
        } elseif ($code === 'done') {
            echo '<div class="notice notice-success is-dismissible"><p><b>EventosApp:</b> '.($msg ? esc_html($msg) : 'Actualización completada.').'</p></div>';
        }
    }
});
