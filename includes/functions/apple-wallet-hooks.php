<?php
// includes/functions/apple-wallet-hooks.php
if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'apple-wallet-ios.php';
require_once plugin_dir_path(__FILE__) . 'apple-wallet-webservice.php';

/* ============================================================
 * =============== HELPERS DEBUG/CONSOLE (SEGUROS) ============
 * ============================================================ */

/**
 * Estos helpers guardan logs estructurados en meta y los imprimen en consola
 * cuando abres el editor (post.php) del ticket o del evento.
 * Se protegen con function_exists/defines para evitar redefinir si ya existen.
 */

if (!function_exists('evapp_pk_debug_append')) {
    function evapp_pk_debug_append($ticket_id, $title, $data){
        if (!$ticket_id) return;
        $entry = ['ts'=>time(), 'title'=>(string)$title, 'data'=>$data];
        $all = get_post_meta($ticket_id, '_evapp_pk_console', true);
        if (!is_array($all)) $all = [];
        $all[] = $entry;
        if (count($all) > 50) $all = array_slice($all, -50);
        update_post_meta($ticket_id, '_evapp_pk_console', $all);
    }
}
if (!function_exists('evapp_pk_debug_reset')) {
    function evapp_pk_debug_reset($ticket_id){ if ($ticket_id) delete_post_meta($ticket_id, '_evapp_pk_console'); }
}
if (!function_exists('evapp_err')) {
    function evapp_err($msg){ error_log("EVENTOSAPP DEBUG | ".$msg); }
}

/** Logs por EVENTO (para ver en la consola del editor del evento) */
if (!function_exists('evapp_event_debug_append')) {
    function evapp_event_debug_append($evento_id, $title, $data){
        if (!$evento_id) return;
        $entry = ['ts'=>time(), 'title'=>(string)$title, 'data'=>$data];
        $all = get_post_meta($evento_id, '_evapp_event_console', true);
        if (!is_array($all)) $all = [];
        $all[] = $entry;
        if (count($all) > 80) $all = array_slice($all, -80);
        update_post_meta($evento_id, '_evapp_event_console', $all);
    }
}

/** Inyección de consola para TICKETS (deshabilitada en producción) */
if (!defined('EVAPP_PK_DEBUG_CONSOLE_HOOK')) {
    add_action('admin_footer', function(){
        if (!is_admin() || empty($_GET['post'])) return;
        $post_id = (int) $_GET['post'];
        if (!$post_id) return;
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'eventosapp_ticket') return;
        // No-op: se elimina salida de <script> y cualquier console.* en admin.
    });
    define('EVAPP_PK_DEBUG_CONSOLE_HOOK', true);
}

/** Inyección de consola para EVENTOS (deshabilitada en producción) */
if (!defined('EVAPP_EVENT_DEBUG_CONSOLE_HOOK')) {
    add_action('admin_footer', function(){
        if (!is_admin() || empty($_GET['post'])) return;
        $post_id = (int) $_GET['post'];
        if (!$post_id) return;
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'eventosapp_event') return;
        // No-op: se elimina salida de <script> y cualquier console.* en admin.
    });
    define('EVAPP_EVENT_DEBUG_CONSOLE_HOOK', true);
}

/* ============================================================
 * ================== HELPERS LOTE (APPLE) ====================
 * ============================================================ */

/** Tamaño de lote (filtrable) */
function eventosapp_apple_get_batch_size(){
    $default = 50;
    $size = (int) apply_filters('eventosapp_apple_batch_size', $default);
    if ($size < 1) $size = $default;
    return $size;
}

/** Programa la siguiente ejecución del worker (Action Scheduler o WP-Cron). */
function eventosapp_apple_schedule_next($evento_id, $delay = 10){
    $args = ['evento_id' => (int) $evento_id];
    if ( function_exists('as_schedule_single_action') ) {
        as_schedule_single_action( time() + (int)$delay, 'eventosapp_apple_bulk_regen', $args, 'eventosapp' );
    } else {
        wp_schedule_single_event( time() + (int)$delay, 'eventosapp_apple_bulk_regen', $args );
    }
}

/** Crea/actualiza el job de batch Apple en meta del evento. $mode: regen|delete */
function eventosapp_apple_seed_batch_job($evento_id, $mode='regen'){
    // Contar tickets del evento (sin límite para tener found_posts)
    $count_q = new WP_Query([
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'posts_per_page' => 1,
        'no_found_rows'  => false,
        'meta_key'       => '_eventosapp_ticket_evento_id',
        'meta_value'     => $evento_id,
    ]);
    $total = (int) $count_q->found_posts;

    $job = [
        'job_id'    => uniqid('evap_', true),
        'total'     => $total,
        'per_page'  => eventosapp_apple_get_batch_size(),
        'page'      => 1,
        'mode'      => ($mode === 'delete' ? 'delete' : 'regen'),
        'ts'        => time(),
    ];
    update_post_meta($evento_id, '_eventosapp_apple_batch_job', $job);

    // Lanza primera corrida con un pequeño delay
    eventosapp_apple_schedule_next($evento_id, 5);

    evapp_event_debug_append($evento_id, 'Seed batch Apple', [
        'job'   => $job,
        'nota'  => 'Sembrado job paginado Apple'
    ]);

    return $job;
}

/* ============================================================
 * ============ HOOK: GUARDAR TICKET (Apple ON/OFF) ===========
 * ============================================================ */

/**
 * Al guardar un TICKET:
 * - Si el evento tiene Apple ON → generar (llamada canonizada) + push.
 * - Si Apple OFF → limpiar metadatos Apple.
 * Nota: si el guardado viene del metabox principal (nonce de ticket presente),
 *       evitamos doble generación porque ya la hace eventosapp_save_ticket().
 */
add_action('save_post_eventosapp_ticket', function($post_id){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (get_post_type($post_id) !== 'eventosapp_ticket') return;

    $evento_id = (int) get_post_meta($post_id, '_eventosapp_ticket_evento_id', true);
    if (!$evento_id) return;

    $apple_on = (get_post_meta($evento_id, '_eventosapp_ticket_wallet_apple', true) === '1');

    // Estado previo de URLs (para mostrar en consola qué valor había)
    $pre = [
        '_eventosapp_ticket_pkpass_url'       => get_post_meta($post_id, '_eventosapp_ticket_pkpass_url', true),
        '_eventosapp_ticket_wallet_apple'     => get_post_meta($post_id, '_eventosapp_ticket_wallet_apple', true),
        '_eventosapp_ticket_wallet_apple_url' => get_post_meta($post_id, '_eventosapp_ticket_wallet_apple_url', true),
        'nonce_en_post'                       => isset($_POST['eventosapp_ticket_nonce']) ? 'sí' : 'no',
        'apple_on'                            => $apple_on ? '1' : '0',
    ];
    evapp_pk_debug_append($post_id, 'SAVE ticket (inicio)', $pre);

    if ($apple_on) {
        $url = '';
        // Si estamos dentro del guardado del metabox principal, probablemente ya se generó.
        if (isset($_POST['eventosapp_ticket_nonce'])) {
            // Intentamos leer URL existente; si no hay, generamos.
            $url = get_post_meta($post_id, '_eventosapp_ticket_pkpass_url', true);
            if (!$url) $url = get_post_meta($post_id, '_eventosapp_ticket_wallet_apple', true);
            if (!$url) $url = get_post_meta($post_id, '_eventosapp_ticket_wallet_apple_url', true);
            if (!$url && function_exists('eventosapp_generar_enlace_wallet_apple')) {
                evapp_pk_debug_append($post_id, 'Generación (porque no había URL)', ['razón'=>'no había ninguna URL previa']);
                $url = eventosapp_generar_enlace_wallet_apple($post_id);
            } else {
                evapp_pk_debug_append($post_id, 'Generación omitida', ['razón'=>'nonce presente; se asume ya generó el metabox o ya hay URL']);
            }
        } else {
            if (function_exists('eventosapp_generar_enlace_wallet_apple')) {
                evapp_pk_debug_append($post_id, 'Generación (llamada canonizada)', ['func'=>'eventosapp_generar_enlace_wallet_apple']);
                $url = eventosapp_generar_enlace_wallet_apple($post_id); // <- llamada canonizada
            } else {
                evapp_pk_debug_append($post_id, 'ERROR', ['detalle'=>'Generador canonizado no disponible']);
            }
        }

        if (!empty($url)) {
            evapp_pk_debug_append($post_id, 'URL final del pase', ['pkpass_url'=>$url]);
            // si el pase ya estaba instalado en dispositivos → push
            if (function_exists('evapp_pkws_push_ticket_update')) {
                evapp_pkws_push_ticket_update($post_id);
                evapp_pk_debug_append($post_id, 'Push APNs', ['resultado'=>'invocado']);
            } else {
                evapp_pk_debug_append($post_id, 'Push APNs', ['resultado'=>'función no disponible']);
            }
        } else {
            evapp_pk_debug_append($post_id, 'Generación', ['resultado'=>'sin URL (falló o abortó)']);
        }
    } else {
        // Limpieza completa de metadatos Apple en el ticket
        delete_post_meta($post_id, '_eventosapp_ticket_wallet_apple');
        delete_post_meta($post_id, '_eventosapp_ticket_wallet_apple_url');
        delete_post_meta($post_id, '_eventosapp_ticket_pkpass_url');
        evapp_pk_debug_append($post_id, 'Apple OFF', ['acción'=>'limpieza de metadatos Apple en el ticket']);
    }
}, 50);

/* ============================================================
 * ============ HOOK: GUARDAR EVENTO (lotes Apple) ============
 * ============================================================ */

/**
 * Al guardar un EVENTO:
 * - Si Apple ON → disparar job paginado que regenera por páginas y hace push por ticket.
 * - Si Apple OFF → disparar job paginado de borrado de URLs Apple en todos los tickets.
 * Además: loguea configuración global Apple + branding (default y overrides del evento).
 */
add_action('save_post_eventosapp_event', function($evento_id){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($evento_id)) return;
    if (!isset($_POST['eventosapp_nonce']) || !wp_verify_nonce($_POST['eventosapp_nonce'], 'eventosapp_detalles_evento')) return;

    $apple_on = (get_post_meta($evento_id, '_eventosapp_ticket_wallet_apple', true) === '1');

    // Config global Apple para consola (con secretos a la vista para validar lectura)
    $cfg = evapp_apple_cfg();
    $cfg_for_log = [
        'Team ID'         => $cfg['team_id'],
        'Pass Type ID'    => $cfg['pass_type_id'],
        'Organization'    => $cfg['org_name'],
        'Certificado .p12 (path)' => $cfg['p12_path'],
        'Password .p12 (VISIBLE)' => $cfg['p12_pass'],
        'WWDR .pem (path)' => $cfg['wwdr_pem'],
        'Entorno APNs'    => $cfg['env'],
        'Web Service URL' => $cfg['ws_url'],
        'Auth base'       => get_option('eventosapp_apple_auth_base',''),
    ];

    // Branding por defecto (Integraciones)
    $def_brand = [
        'Icon URL (default)'  => get_option('eventosapp_apple_icon_default_url',''),
        'Logo URL (default)'  => get_option('eventosapp_apple_logo_default_url',''),
        'Strip URL (default)' => get_option('eventosapp_apple_strip_default_url',''),
        'BG HEX (default)'    => get_option('eventosapp_apple_bg_hex','#3782C4'),
        'FG HEX (default)'    => get_option('eventosapp_apple_fg_hex','#FFFFFF'),
        'Label HEX (default)' => get_option('eventosapp_apple_label_hex','#FFFFFF'),
    ];

    // Overrides por evento
    $ovr_brand = [
        'Icon URL (evento)'   => get_post_meta($evento_id, '_eventosapp_apple_icon_url', true),
        'Logo URL (evento)'   => get_post_meta($evento_id, '_eventosapp_apple_logo_url', true),
        'Strip URL (evento)'  => get_post_meta($evento_id, '_eventosapp_apple_strip_url', true),
        'BG HEX (evento)'     => get_post_meta($evento_id, '_eventosapp_apple_hex_bg', true),
        'FG HEX (evento)'     => get_post_meta($evento_id, '_eventosapp_apple_hex_fg', true),
        'Label HEX (evento)'  => get_post_meta($evento_id, '_eventosapp_apple_hex_label', true),
    ];

    evapp_event_debug_append($evento_id, 'SAVE evento (config Apple global)', $cfg_for_log);
    evapp_event_debug_append($evento_id, 'SAVE evento (branding default)', $def_brand);
    evapp_event_debug_append($evento_id, 'SAVE evento (branding overrides)', array_merge($ovr_brand, ['Apple ON'=>$apple_on?'1':'0']));

    if ($apple_on) {
        $job = eventosapp_apple_seed_batch_job($evento_id, 'regen');
        error_log(sprintf('EVENTOSAPP APPLE BATCH seed evento:%d total:%d per_page:%d page:%d (regen)',
            $evento_id, $job['total'], $job['per_page'], $job['page']
        ));
        evapp_event_debug_append($evento_id, 'Batch Apple (regen)', $job);
    } else {
        $job = eventosapp_apple_seed_batch_job($evento_id, 'delete');
        error_log(sprintf('EVENTOSAPP APPLE BATCH seed evento:%d total:%d per_page:%d page:%d (delete)',
            $evento_id, $job['total'], $job['per_page'], $job['page']
        ));
        evapp_event_debug_append($evento_id, 'Batch Apple (delete)', $job);
    }
}, 60);

/* ============================================================
 * ============== WORKER: PROCESO PAGINADO (Apple) ============
 * ============================================================ */

add_action('eventosapp_apple_bulk_regen', function($args){
    $evento_id = is_array($args) ? (int)($args['evento_id'] ?? 0) : (int)$args;
    if (!$evento_id) return;

    $lock_key = 'evapp_apple_job_lock_' . $evento_id;
    if ( get_transient($lock_key) ) return;            // evita solapamiento
    set_transient($lock_key, 1, 60);

    $job = get_post_meta($evento_id, '_eventosapp_apple_batch_job', true);
    if (!is_array($job) || empty($job['per_page']) || empty($job['page'])) {
        delete_transient($lock_key);
        return;
    }

    $per_page = (int) $job['per_page'];
    $page     = (int) $job['page'];
    $mode     = (string) ($job['mode'] ?? 'regen');

    // Página de tickets para este evento
    $q = new WP_Query([
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'meta_key'       => '_eventosapp_ticket_evento_id',
        'meta_value'     => $evento_id,
        'no_found_rows'  => false, // necesitamos max_num_pages
    ]);

    if ( !$q->have_posts() ) {
        delete_post_meta($evento_id, '_eventosapp_apple_batch_job');
        delete_transient($lock_key);
        error_log("EVENTOSAPP APPLE BATCH done evento:$evento_id");
        evapp_event_debug_append($evento_id, 'BATCH done', ['evento_id'=>$evento_id, 'mode'=>$mode, 'page'=>$page, 'per_page'=>$per_page, 'tickets'=>0]);
        return;
    }

    evapp_event_debug_append($evento_id, 'BATCH page', [
        'evento_id'=>$evento_id,
        'mode'=>$mode,
        'page'=>$page,
        'per_page'=>$per_page,
        'tickets_en_esta_pagina'=>count($q->posts),
        'ticket_ids'=>$q->posts,
    ]);

    foreach ($q->posts as $ticket_id){
        if ($mode === 'regen') {
            if (function_exists('eventosapp_generar_enlace_wallet_apple')) {
                evapp_pk_debug_append($ticket_id, 'WORKER: regen (inicio)', [
                    'evento_id'=>$evento_id, 'page'=>$page, 'per_page'=>$per_page
                ]);
                $url = eventosapp_generar_enlace_wallet_apple($ticket_id);
                if (!empty($url) && function_exists('evapp_pkws_push_ticket_update')) {
                    evapp_pkws_push_ticket_update($ticket_id);
                    evapp_pk_debug_append($ticket_id, 'WORKER: regen (fin)', ['pkpass_url'=>$url, 'push'=>'invocado']);
                } else {
                    evapp_pk_debug_append($ticket_id, 'WORKER: regen (fin)', ['pkpass_url'=>$url, 'push'=>'no']);
                }
            } else {
                evapp_pk_debug_append($ticket_id, 'WORKER ERROR', ['detalle'=>'Generador canonizado no disponible']);
            }
        } else { // delete
            delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple');
            delete_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url');
            delete_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url');
            evapp_pk_debug_append($ticket_id, 'WORKER: delete', ['acción'=>'limpieza de metas Apple por lote']);
        }
    }

    $max_pages = (int) $q->max_num_pages;
    $next_page = $page + 1;

    if ($max_pages > 0 && $next_page <= $max_pages) {
        $job['page'] = $next_page;
        update_post_meta($evento_id, '_eventosapp_apple_batch_job', $job);
        eventosapp_apple_schedule_next($evento_id, 10);
        evapp_event_debug_append($evento_id, 'BATCH next', ['next_page'=>$next_page, 'max_pages'=>$max_pages]);
    } else {
        delete_post_meta($evento_id, '_eventosapp_apple_batch_job');
        error_log("EVENTOSAPP APPLE BATCH done evento:$evento_id");
        evapp_event_debug_append($evento_id, 'BATCH done', ['evento_id'=>$evento_id, 'mode'=>$mode, 'page'=>$page, 'per_page'=>$per_page, 'tickets'=>count($q->posts)]);
    }

    delete_transient($lock_key);
}, 10, 1);
