<?php
// includes/admin/eventosapp-wallet-hooks.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Mueve fuera de eventosapp.php la lógica de:
 * - Auto sync de la EventTicketClass al guardar evento (Google Wallet)
 * - PATCH por lotes de EventTicketObjects (Google Wallet)
 * - Generación segura del PKPass (Apple Wallet) evitando “Path cannot be empty”
 *
 * Requiere:
 * - includes/functions/google-wallet-android.php
 * - (Opcional) Librería PKPass (class PKPass o \PKPass\PKPass)
 *
 * INSTRUMENTACIÓN DE DEPURACIÓN:
 * - Se añaden logs detallados a error_log y a la consola del navegador.
 * - Los logs por ticket se guardan en meta _evapp_pk_console y se imprimen
 *   automáticamente en el editor del ticket (admin) vía admin_footer.
 *
 * ⚠ IMPORTANTE: Muestra credenciales en consola (p12 password). No dejar en producción.
 */

// Por si este archivo se carga de forma independiente en otro contexto:
if ( ! function_exists('eventosapp_sync_wallet_class') ) {
    $gw_path = plugin_dir_path(dirname(__FILE__, 2)) . 'includes/functions/google-wallet-android.php';
    if ( file_exists($gw_path) ) require_once $gw_path;
}

/**
 * Hook: Cuando se genera/actualiza un ticket de Google Wallet,
 * asegurar que también se genere el QR correspondiente en el QR Manager
 */
add_action('eventosapp_before_generate_wallet_android', 'eventosapp_ensure_wallet_qr_generated', 10, 1);
function eventosapp_ensure_wallet_qr_generated($ticket_id) {
    if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
        return;
    }
    
    // Verificar si existe la clase QR Manager
    if (!class_exists('EventosApp_QR_Manager')) {
        error_log("EventosApp Wallet Hooks: QR Manager no disponible para ticket $ticket_id");
        return;
    }
    
    // Verificar si ya existe el QR de google_wallet
    $all_qr_codes = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
    
    if (is_array($all_qr_codes) && isset($all_qr_codes['google_wallet']) && !empty($all_qr_codes['google_wallet']['content'])) {
        error_log("EventosApp Wallet Hooks: QR de Google Wallet ya existe para ticket $ticket_id");
        return; // Ya existe, no regenerar
    }
    
    // Generar el QR de Google Wallet
    error_log("EventosApp Wallet Hooks: Generando QR de Google Wallet para ticket $ticket_id");
    
    $qr_manager = new EventosApp_QR_Manager();
    
    // Asegurar código de seguridad
    $security_code = get_post_meta($ticket_id, '_eventosapp_badge_security_code', true);
    if (empty($security_code)) {
        $security_code = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        update_post_meta($ticket_id, '_eventosapp_badge_security_code', $security_code);
    }
    
    // Generar QR
    $result = $qr_manager->generate_qr_code($ticket_id, 'google_wallet');
    
    if ($result && isset($result['content'])) {
        error_log("EventosApp Wallet Hooks: QR de Google Wallet generado exitosamente para ticket $ticket_id");
    } else {
        error_log("EventosApp Wallet Hooks: ERROR al generar QR de Google Wallet para ticket $ticket_id");
    }
}

/* ============================================================
 * ================== HELPERS DEBUG/CONSOLE ===================
 * ============================================================ */

/**
 * Agrega un bloque de logs (agrupado) al meta del ticket para volcar en consola.
 * $title: etiqueta del grupo
 * $data: array (key=>value) o lista simple
 */
if (!function_exists('evapp_pk_debug_append')) {
    function evapp_pk_debug_append($ticket_id, $title, $data){
        if (!$ticket_id) return;
        $entry = [
            'ts'    => time(),
            'title' => (string)$title,
            'data'  => $data,
        ];
        $all = get_post_meta($ticket_id, '_evapp_pk_console', true);
        if (!is_array($all)) $all = [];
        $all[] = $entry;
        // Limitar a 50 grupos para no inflar la base
        if (count($all) > 50) $all = array_slice($all, -50);
        update_post_meta($ticket_id, '_evapp_pk_console', $all);
    }
}

/** Limpia los logs previos del ticket (opcional según flujo). */
if (!function_exists('evapp_pk_debug_reset')) {
    function evapp_pk_debug_reset($ticket_id){
        if ($ticket_id) delete_post_meta($ticket_id, '_evapp_pk_console');
    }
}

/** Azúcar para error_log con prefijo consistente */
if (!function_exists('evapp_err')) {
    function evapp_err($msg){ error_log("EVENTOSAPP DEBUG | ".$msg); }
}

/** INTROSPECCIÓN: reporta en consola dónde están definidas las funciones clave */
if (!function_exists('evapp_pk_log_fn_origin')) {
    function evapp_pk_log_fn_origin($ticket_id){
        $stamp = '2025-08-29.r2'; // Cambia este sello si vuelves a editar
        $out = ['stamp' => $stamp];

        foreach (['eventosapp_generar_enlace_wallet_apple', 'eventosapp_apple_generate_pass'] as $fn) {
            if (function_exists($fn)) {
                try {
                    $ref = new \ReflectionFunction($fn);
                    $out[$fn] = [
                        'file'      => $ref->getFileName(),
                        'startLine' => $ref->getStartLine(),
                        'endLine'   => $ref->getEndLine(),
                    ];
                } catch (\Throwable $e) {
                    $out[$fn] = 'Reflection error: '.$e->getMessage();
                }
            } else {
                $out[$fn] = 'NO existe';
            }
        }

        evapp_pk_debug_append($ticket_id, 'FUNCIONES ACTIVAS (origen)', $out);
    }
}


/** (PROD) — Consola deshabilitada: no imprimir nada en admin */
add_action('admin_footer', function(){
    if (!is_admin()) return;
    if (empty($_GET['post'])) return;
    $post_id = (int) $_GET['post'];
    if (!$post_id) return;
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'eventosapp_ticket') return;
    // No-op: Se eliminó la salida de <script> con console.* para evitar exposición de datos sensibles.
});



/* ============================================================
 * =================== HELPERS COMUNES (GW) ===================
 * ============================================================ */

/**
 * Devuelve un hash (firma) de todos los campos del evento que afectan al Wallet Object.
 * Se usa para ejecutar PATCH sólo cuando hay cambios reales (Google).
 */
function eventosapp_wallet_build_signature($evento_id){
    $issuer_id  = (string) get_option('eventosapp_wallet_issuer_id', '');
    $wc_enable  = get_post_meta($evento_id, '_eventosapp_wallet_custom_enable', true) === '1';
    $class_evt  = (string) get_post_meta($evento_id, '_eventosapp_wallet_class_id', true);
    $class_def  = (string) get_option('eventosapp_wallet_class_id', '');

    $logo_evt   = (string) get_post_meta($evento_id, '_eventosapp_wallet_logo_url', true);
    $logo_def   = (string) get_option('eventosapp_wallet_logo_url', '');
    $hero_evt   = (string) get_post_meta($evento_id, '_eventosapp_wallet_hero_img_url', true);
    $hero_def   = (string) get_option('eventosapp_wallet_hero_img_url', '');
    $hex_evt    = (string) get_post_meta($evento_id, '_eventosapp_wallet_hex_color', true);
    $hex_def    = (string) get_option('eventosapp_wallet_hex_color', '#3782C4');

    // === Apple (overrides por evento + defaults globales) ===
    $apple_icon_ev   = (string) get_post_meta($evento_id, '_eventosapp_apple_icon_url',  true);
    $apple_logo_ev   = (string) get_post_meta($evento_id, '_eventosapp_apple_logo_url',  true);
    $apple_strip_ev  = (string) get_post_meta($evento_id, '_eventosapp_apple_strip_url', true);
    $apple_bg_ev     = (string) get_post_meta($evento_id, '_eventosapp_apple_hex_bg',    true);
    $apple_fg_ev     = (string) get_post_meta($evento_id, '_eventosapp_apple_hex_fg',    true);
    $apple_label_ev  = (string) get_post_meta($evento_id, '_eventosapp_apple_hex_label', true);

    $apple_icon_def  = (string) get_option('eventosapp_apple_icon_default_url', '');
    $apple_logo_def  = (string) get_option('eventosapp_apple_logo_default_url', '');
    $apple_strip_def = (string) get_option('eventosapp_apple_strip_default_url', '');
    $apple_bg_def    = (string) get_option('eventosapp_apple_bg_hex',   '#3782C4');
    $apple_fg_def    = (string) get_option('eventosapp_apple_fg_hex',   '#FFFFFF');
    $apple_lbl_def   = (string) get_option('eventosapp_apple_label_hex','#FFFFFF');

    $apple_icon   = $apple_icon_ev  ?: $apple_icon_def;
    $apple_logo   = $apple_logo_ev  ?: $apple_logo_def;
    $apple_strip  = $apple_strip_ev ?: $apple_strip_def;
    $apple_bg     = $apple_bg_ev    ?: $apple_bg_def;
    $apple_fg     = $apple_fg_ev    ?: $apple_fg_def;
    $apple_label  = $apple_label_ev ?: $apple_lbl_def;

    $tipo       = (string) get_post_meta($evento_id, '_eventosapp_tipo_fecha', true) ?: 'unica';
    $fi         = (string) get_post_meta($evento_id, ($tipo==='consecutiva' ? '_eventosapp_fecha_inicio' : '_eventosapp_fecha_unica'), true);
    $ff         = (string) get_post_meta($evento_id, ($tipo==='consecutiva' ? '_eventosapp_fecha_fin' : '_eventosapp_fecha_unica'), true);
    $fechasNoco = get_post_meta($evento_id, '_eventosapp_fechas_noco', true);
    if (!is_array($fechasNoco)) {
        $fechasNoco = is_string($fechasNoco) && $fechasNoco ? array_map('trim', explode(',', $fechasNoco)) : [];
    }
    sort($fechasNoco);

    $hi         = (string) get_post_meta($evento_id, '_eventosapp_hora_inicio', true);
    $hc         = (string) get_post_meta($evento_id, '_eventosapp_hora_cierre', true);
    $tz         = (string) get_post_meta($evento_id, '_eventosapp_zona_horaria', true);
    $direccion  = (string) get_post_meta($evento_id, '_eventosapp_direccion', true);
    $coords     = (string) get_post_meta($evento_id, '_eventosapp_coordenadas', true);
    $brand_text = (string) get_option('eventosapp_wallet_branding_text', 'Evento');

    $class_id   = $wc_enable ? ($class_evt ?: ($issuer_id ? $issuer_id.'.event_'.$evento_id : '')) : $class_def;
    if ($class_id && $issuer_id && strpos($class_id, '.') === false) {
        $class_id = $issuer_id . '.' . ltrim($class_id, '.');
    }

    $logo_url   = $wc_enable ? ($logo_evt ?: $logo_def) : $logo_def;
    $hero_url   = $wc_enable ? ($hero_evt ?: $hero_def) : $hero_def;
    $hex_color  = $wc_enable ? ($hex_evt ?: '#3782C4') : ($hex_def ?: '#3782C4');

    $payload = [
        // Google / comunes
        'issuer_id' => $issuer_id,
        'class_id'  => $class_id,
        'logo'      => $logo_url,
        'hero'      => $hero_url,
        'hex'       => $hex_color,
        'tipo'      => $tipo,
        'fi'        => $fi,
        'ff'        => $ff,
        'fechas_noco' => $fechasNoco,
        'hi'        => $hi,
        'hc'        => $hc,
        'tz'        => $tz,
        'direccion' => $direccion,
        'coords'    => $coords,
        'brand'     => $brand_text,

        // Apple (novedad en la firma)
        'apple_icon'  => $apple_icon,
        'apple_logo'  => $apple_logo,
        'apple_strip' => $apple_strip,
        'apple_bg'    => $apple_bg,
        'apple_fg'    => $apple_fg,
        'apple_label' => $apple_label,
    ];

    return md5( wp_json_encode($payload) );
}


/** Tamaño de lote (tickets por corrida). Filtrable. */
function eventosapp_wallet_get_batch_size(){
    $default = 50;
    $size = (int) apply_filters('eventosapp_wallet_patch_batch_size', $default);
    if ($size < 1) $size = $default;
    return $size;
}

/** Programa la siguiente ejecución del worker (Action Scheduler o WP-Cron). */
function eventosapp_wallet_schedule_next($evento_id, $delay = 10){
    $args = ['evento_id' => (int) $evento_id];
    if ( function_exists('as_schedule_single_action') ) {
        as_schedule_single_action( time() + (int)$delay, 'eventosapp_wallet_bulk_patch', $args, 'eventosapp' );
    } else {
        wp_schedule_single_event( time() + (int)$delay, 'eventosapp_wallet_bulk_patch', $args );
    }
}

/** Crea/actualiza el job de batch en meta del evento. */
function eventosapp_wallet_seed_batch_job($evento_id){
    // Contar tickets del evento
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
        'job_id'    => uniqid('evw_', true),
        'total'     => $total,
        'per_page'  => eventosapp_wallet_get_batch_size(),
        'page'      => 1,
        'ts'        => time(),
    ];
    update_post_meta($evento_id, '_eventosapp_wallet_patch_job', $job);

    // Lanza primera corrida con un pequeño delay
    eventosapp_wallet_schedule_next($evento_id, 5);
    return $job;
}

/** Ensambla datos de branding/fechas/lugar usados en cada PATCH (nivel evento). */
function eventosapp_wallet_build_event_context($evento_id){
    $issuer_id = get_option('eventosapp_wallet_issuer_id');
    $wallet_custom_enable = get_post_meta($evento_id, '_eventosapp_wallet_custom_enable', true) === '1';
    $class_id_event       = get_post_meta($evento_id, '_eventosapp_wallet_class_id', true);
    $class_id_default     = get_option('eventosapp_wallet_class_id');

    $logo_url = $wallet_custom_enable
        ? (get_post_meta($evento_id, '_eventosapp_wallet_logo_url', true) ?: get_option('eventosapp_wallet_logo_url'))
        : (get_option('eventosapp_wallet_logo_url') ?: '');

    $hero_url = $wallet_custom_enable
        ? (get_post_meta($evento_id, '_eventosapp_wallet_hero_img_url', true) ?: get_option('eventosapp_wallet_hero_img_url'))
        : (get_option('eventosapp_wallet_hero_img_url') ?: '');

    $brand_text = get_option('eventosapp_wallet_branding_text') ?: 'Evento';

    $class_id = $wallet_custom_enable ? ($class_id_event ?: ($issuer_id . '.event_' . $evento_id)) : $class_id_default;
    if ($class_id && strpos($class_id, '.') === false) $class_id = $issuer_id . '.' . ltrim($class_id, '.');

    // Datos de evento (fechas/lugar)
    $tipo      = get_post_meta($evento_id, '_eventosapp_tipo_fecha', true) ?: 'unica';
    $fi        = get_post_meta($evento_id, ($tipo==='consecutiva' ? '_eventosapp_fecha_inicio' : '_eventosapp_fecha_unica'), true) ?: '';
    $ff        = get_post_meta($evento_id, ($tipo==='consecutiva' ? '_eventosapp_fecha_fin' : '_eventosapp_fecha_unica'), true) ?: '';
    $hi        = get_post_meta($evento_id, '_eventosapp_hora_inicio', true) ?: '00:00';
    $hc        = get_post_meta($evento_id, '_eventosapp_hora_cierre', true) ?: '23:59';
    $tz        = get_post_meta($evento_id, '_eventosapp_zona_horaria', true) ?: 'UTC';
    $direccion = get_post_meta($evento_id, '_eventosapp_direccion', true) ?: '';
    $coords    = get_post_meta($evento_id, '_eventosapp_coordenadas', true) ?: '';

    $toIsoZ = function($date, $time, $tz) {
        try { $dt = new DateTime(trim($date.' '.$time), new DateTimeZone($tz ?: 'UTC')); $dt->setTimezone(new DateTimeZone('UTC')); return $dt->format('Y-m-d\TH:i:s\Z'); }
        catch (\Throwable $e) { return null; }
    };
    $startISO = ($fi ? $toIsoZ($fi, $hi, $tz) : null);
    $endISO   = ($ff ? $toIsoZ($ff, $hc, $tz) : null);
    $doorsISO = $startISO;

    $lat = $lng = null;
    if ($coords && strpos($coords, ',') !== false) {
        // Tolerante a “lat,lng,zoom”
        $parts = explode(',', $coords);
        $latRaw = trim($parts[0] ?? '');
        $lngRaw = trim($parts[1] ?? '');
        if ($latRaw !== '' && $lngRaw !== '') {
            $lat = (float) $latRaw;
            $lng = (float) $lngRaw;
        }
    }

    return [
        'issuer_id'  => $issuer_id,
        'class_id'   => $class_id,
        'logo_url'   => $logo_url,
        'hero_url'   => $hero_url,
        'brand_text' => $brand_text,
        'startISO'   => $startISO,
        'endISO'     => $endISO,
        'doorsISO'   => $doorsISO,
        'direccion'  => $direccion,
        'lat'        => $lat,
        'lng'        => $lng,
        'nombre_evento' => (get_the_title($evento_id) ?: 'Evento'),
        // Extras Apple
        'evento_id'  => $evento_id,
        'tz'         => $tz,
    ];
}

/** Cálculo PREVIEW de relevantDate (para consola admin) */
if (!function_exists('eventosapp__preview_relevant_date')) {
    function eventosapp__preview_relevant_date($evento_id){
        $res = [
            'tipo'         => null,
            'fi_meta'      => null,
            'fi_rango'     => null,
            'ff_rango'     => null,
            'fechas_noco'  => [],
            'hora_inicio'  => null,
            'tz_ev'        => null,
            'ctx_startISO' => null,
            'ctx_endISO'   => null,
            'dateLocal'    => null,
            'isoCalc'      => null,
            'relISOFinal'  => null,
            'relSource'    => null,
        ];

        $ctx = eventosapp_wallet_build_event_context($evento_id);
        $res['ctx_startISO'] = $ctx['startISO'] ?? null;
        $res['ctx_endISO']   = $ctx['endISO']   ?? null;

        $tz_site = get_option('timezone_string') ?: 'UTC';
        $tz_ev   = get_post_meta($evento_id, '_eventosapp_zona_horaria', true) ?: $tz_site;
        $res['tz_ev'] = $tz_ev;

        $tipo    = get_post_meta($evento_id, '_eventosapp_tipo_fecha', true) ?: 'unica';
        $fi_meta = get_post_meta($evento_id, '_eventosapp_fecha_unica',   true);
        $fi_rg   = get_post_meta($evento_id, '_eventosapp_fecha_inicio',  true);
        $ff_rg   = get_post_meta($evento_id, '_eventosapp_fecha_fin',     true);
        $hi      = get_post_meta($evento_id, '_eventosapp_hora_inicio',   true) ?: '00:00';
        $fechas  = get_post_meta($evento_id, '_eventosapp_fechas_noco',   true);

        if (!is_array($fechas)) {
            $fechas = (is_string($fechas) && $fechas) ? array_map('trim', explode(',', $fechas)) : [];
        }
        $fechas = array_values(array_filter($fechas, function($f){ return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f); }));
        sort($fechas);

        $res['tipo']        = $tipo;
        $res['fi_meta']     = $fi_meta ?: '';
        $res['fi_rango']    = $fi_rg ?: '';
        $res['ff_rango']    = $ff_rg ?: '';
        $res['fechas_noco'] = $fechas;
        $res['hora_inicio'] = $hi;

        $toIsoZ = function($date, $time, $tz) {
            try {
                $dt = new DateTime(trim($date.' '.$time), new DateTimeZone($tz ?: 'UTC'));
                $dt->setTimezone(new DateTimeZone('UTC'));
                return $dt->format('Y-m-d\TH:i:s\Z');
            } catch (\Throwable $e) {
                return null;
            }
        };

        $fi = $ff = null;
        if ($tipo === 'unica') {
            $fi = $fi_meta ?: null;
			} elseif ($tipo === 'consecutiva') {
				$fi = $fi_rg ?: null;
				if (!$fi) $ff = $ff_rg ?: null; // fallback al fin si faltara inicio
			} else { // noconsecutiva
            if ($fechas) {
                $todayLocal = (new DateTime('today', new DateTimeZone($tz_ev)))->format('Y-m-d');
				// noconsecutiva (PREVIEW)
				$future = array_values(array_filter($fechas, function($f) use ($todayLocal){ return $f >= $todayLocal; }));
				// antes: $ff = $future ? reset($future) : end($fechas_noco);
				$ff = $future ? reset($future) : end($fechas); // usar $fechas (la var local)
            }
        }

		$dateLocal  = $fi ?: $ff; // <-- prioriza inicio
        $isoCalc    = ($dateLocal ? $toIsoZ($dateLocal, $hi, $tz_ev) : null);
        $relISOFinal= $isoCalc ?: ($ctx['endISO'] ?: ($ctx['startISO'] ?: null));
        $relSource  = $isoCalc ? 'calc(local+tz)' : (($ctx['endISO'] ?? null) ? 'ctx.endISO' : (($ctx['startISO'] ?? null) ? 'ctx.startISO' : 'none'));

        $res['dateLocal']   = $dateLocal ?: '';
        $res['isoCalc']     = $isoCalc ?: '';
        $res['relISOFinal'] = $relISOFinal ?: '';
        $res['relSource']   = $relSource;

        return $res;
    }
}


/* ============================================================
 * =============== GOOGLE WALLET: PATCH POR TICKET ============
 * ============================================================ */

function eventosapp_wallet_patch_single_ticket($evento_id, $ticket_id, $access_token, $ctx){
    $issuer_id       = $ctx['issuer_id'];
    $class_id_wanted = $ctx['class_id'];
    $hero_url        = $ctx['hero_url'];
    $logo_url        = $ctx['logo_url'];
    $brand_text      = $ctx['brand_text'];
    $startISO        = $ctx['startISO'];
    $endISO          = $ctx['endISO'];
    $doorsISO        = $ctx['doorsISO'];
    $direccion       = $ctx['direccion'];
    $lat             = $ctx['lat'];
    $lng             = $ctx['lng'];
    $nombre_evento   = $ctx['nombre_evento'];

    // Datos del asistente
    $asistente_nombre   = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
    $asistente_apellido = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
    $asistente_email    = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
    $localidad          = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);
    $codigo_qr          = get_post_meta($ticket_id, 'eventosapp_ticketID', true) ?: $ticket_id;

    // Fecha legible
    $tipo = get_post_meta($evento_id, '_eventosapp_tipo_fecha', true) ?: 'unica';
    $fecha_label = '';
    if ($tipo === 'unica') {
        $f = get_post_meta($evento_id, '_eventosapp_fecha_unica', true);
        $fecha_label = $f ? date_i18n('D, d M Y', strtotime($f)) : '';
    } elseif ($tipo === 'consecutiva') {
        $fi = get_post_meta($evento_id, '_eventosapp_fecha_inicio', true);
        $ff = get_post_meta($evento_id, '_eventosapp_fecha_fin', true);
        if ($fi && $ff) $fecha_label = date_i18n('D, d M Y', strtotime($fi)) . ' → ' . date_i18n('D, d M Y', strtotime($ff));
    } else {
        $fechas = get_post_meta($evento_id, '_eventosapp_fechas_noco', true);
        if (is_string($fechas)) $fechas = @unserialize($fechas);
        if (!is_array($fechas)) $fechas = [];
        $fmt = [];
        foreach ($fechas as $f){ if ($f) $fmt[] = date_i18n('D, d M Y', strtotime($f)); }
        $fecha_label = implode(', ', $fmt);
    }

    // ID canónico del objeto
    $object_id = $issuer_id . '.ticket_' . $ticket_id;

    // Construye payload común (usado en INSERT/PATCH)
    $base_payload = [
        'state'         => 'active',
        'heroImage'     => $hero_url ? [
            'sourceUri' => ['uri' => $hero_url],
            'contentDescription' => ['defaultValue' => ['language' => 'es', 'value' => $brand_text]]
        ] : null,
        'logo'          => $logo_url ? ['sourceUri' => ['uri' => $logo_url]] : null,
        'eventName'     => ['defaultValue' => ['language' => 'es', 'value' => $nombre_evento]],
        'startDateTime' => $startISO,
        'endDateTime'   => $endISO,
        'doorsOpen'     => $doorsISO,
        'venue'         => $direccion ? [
            'name'    => ['defaultValue' => ['language' => 'es', 'value' => $direccion]],
            'address' => ['defaultValue' => ['language' => 'es', 'value' => $direccion]],
        ] : null,
        'locations'     => ($lat !== null && $lng !== null) ? [['latitude' => $lat, 'longitude' => $lng]] : null,
        'textModulesData' => [
            ['header' => 'Asistente', 'body' => trim(($asistente_nombre ?: '').' '.($asistente_apellido ?: ''))],
            ['header' => 'Email',     'body' => (string)$asistente_email],
            ['header' => 'Localidad', 'body' => (string)$localidad],
            ['header' => 'Fecha',     'body' => (string)$fecha_label ],
        ],
        'barcode' => [
            'type' => 'qrCode',
            'value' => $codigo_qr,
            'alternateText' => 'Código de ingreso'
        ]
    ];
    foreach ($base_payload as $k => $v) { if ($v === null) unset($base_payload[$k]); }

    // Helpers HTTP
    $headers_json = [
        'Authorization' => "Bearer $access_token",
        'Content-Type'  => 'application/json'
    ];
    $obj_base_url = "https://walletobjects.googleapis.com/walletobjects/v1/eventTicketObject/";
    $url_get   = $obj_base_url . rawurlencode($object_id);
    $url_patch = $url_get;
    $url_del   = $url_get;
    $url_ins   = $obj_base_url;

    // 1) GET - ¿existe el objeto?
    $res_get = wp_remote_get($url_get, ['timeout'=>20, 'headers'=>['Authorization'=>"Bearer $access_token"]]);
    $code_get = is_wp_error($res_get) ? 0 : wp_remote_retrieve_response_code($res_get);
    $body_get = is_wp_error($res_get) ? ('WP_Error: '.$res_get->get_error_message()) : wp_remote_retrieve_body($res_get);

    // Intenta leer classId actual (si existe)
    $current_class = null;
    if ($code_get === 200) {
        $json = json_decode($body_get, true);
        if (is_array($json)) {
            // La API devuelve 'classId' y también 'classReference'
            $current_class = isset($json['classId']) ? (string)$json['classId'] : null;
            if (!$current_class && isset($json['classReference']['id'])) {
                $current_class = (string)$json['classReference']['id'];
            }
        }
    }

    // Función auxiliar para INSERT
    $do_insert = function() use ($url_ins, $headers_json, $object_id, $class_id_wanted, $base_payload, $evento_id, $ticket_id){
        $insert_payload = array_merge([
            'id'      => $object_id,
            'classId' => $class_id_wanted,
        ], $base_payload);

        $res_ins = wp_remote_post($url_ins, [
            'timeout' => 20,
            'headers' => $headers_json,
            'body'    => wp_json_encode($insert_payload),
        ]);
        $code_ins = is_wp_error($res_ins) ? 0 : wp_remote_retrieve_response_code($res_ins);
        $body_ins = is_wp_error($res_ins) ? ('WP_Error: '.$res_ins->get_error_message()) : wp_remote_retrieve_body($res_ins);
        error_log("EVENTOSAPP WALLET INSERT OBJECT [evento:$evento_id ticket:$ticket_id] HTTP $code_ins | ".substr($body_ins,0,400));
        return $code_ins === 200;
    };

    // 2) Flujo según GET
    if ($code_get === 404) {
        // No existe → INSERT con class correcta
        return $do_insert();
    } elseif ($code_get === 200) {
        // Existe → ¿clase coincide?
        $wanted = (string)$class_id_wanted;
        $have   = (string)$current_class;
        if ($wanted && $have && $wanted !== $have) {
            // *** CASO PROBLEMA: classId distinto ***
            // Debemos borrar y recrear con la clase buena.
            $res_del = wp_remote_request($url_del, [
                'timeout' => 20,
                'method'  => 'DELETE',
                'headers' => ['Authorization'=>"Bearer $access_token"],
            ]);
            $code_del = is_wp_error($res_del) ? 0 : wp_remote_retrieve_response_code($res_del);
            $body_del = is_wp_error($res_del) ? ('WP_Error: '.$res_del->get_error_message()) : wp_remote_retrieve_body($res_del);
            error_log("EVENTOSAPP WALLET DELETE OBJECT (class mismatch) [evento:$evento_id ticket:$ticket_id] HTTP $code_del | ".substr($body_del,0,300));

            // Intentar insertar con la clase correcta
            return $do_insert();
        }

        // Clase coincide → PATCH (sin tocar classId)
        $payload_patch = $base_payload; // no incluimos classId para evitar errores
        $res_patch = wp_remote_request($url_patch, [
            'timeout' => 20,
            'method'  => 'PATCH',
            'headers' => $headers_json,
            'body'    => wp_json_encode($payload_patch),
        ]);
        $code_patch = is_wp_error($res_patch) ? 0 : wp_remote_retrieve_response_code($res_patch);
        $body_patch = is_wp_error($res_patch) ? ('WP_Error: '.$res_patch->get_error_message()) : wp_remote_retrieve_body($res_patch);
        error_log("EVENTOSAPP WALLET PATCH OBJECT [evento:$evento_id ticket:$ticket_id] HTTP $code_patch | ".substr($body_patch,0,400));
        return $code_patch === 200;
    } else {
        // Error inesperado al consultar → intenta INSERT de todas formas (idempotencia)
        error_log("EVENTOSAPP WALLET GET OBJECT [evento:$evento_id ticket:$ticket_id] HTTP $code_get | ".substr($body_get,0,300)." | fallback=INSERT");
        return $do_insert();
    }
}


/* ============================================================
 * ================= APPLE WALLET: PKPASS (SAFE) ==============
 * ============================================================ */

function eventosapp__is_url($s){ return is_string($s) && preg_match('~^https?://~i', $s); }
function eventosapp__ensure_admin_file_includes(){
    if ( ! function_exists('download_url') ) {
        if ( defined('ABSPATH') && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
    }
}
function eventosapp__download_to_tmp($url){
    eventosapp__ensure_admin_file_includes();
    if (!eventosapp__is_url($url)) return null;
    $tmp = download_url($url);
    if (is_wp_error($tmp)) return null;
    return $tmp;
}
function eventosapp__url_or_path_to_local($maybe){
    if (!$maybe) return null;
    if (eventosapp__is_url($maybe)) {
        $uploads = wp_upload_dir();
        if (strpos($maybe, $uploads['baseurl']) === 0) {
            $rel = substr($maybe, strlen($uploads['baseurl']));
            $path = $uploads['basedir'] . $rel;
            return file_exists($path) ? $path : null;
        }
        return eventosapp__download_to_tmp($maybe);
    }
    return file_exists($maybe) ? $maybe : null;
}
function eventosapp__hex_to_rgb_css($hex){
    $hex = trim($hex ?: '');
    if ($hex === '') return null;
    if ($hex[0] === '#') $hex = substr($hex,1);
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (!preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) return null;
    $r = hexdec(substr($hex,0,2));
    $g = hexdec(substr($hex,2,2));
    $b = hexdec(substr($hex,4,2));
    return "rgb($r, $g, $b)";
}

/**
 * Resuelve assets Apple por evento (override) con fallback a Integraciones.
 * Devuelve paths LOCALES listos para addFile(). Nunca vacíos.
 * (Incluye 'trace' con detalle de resolución para consola).
 */
if (!function_exists('eventosapp__apple_resolve_assets_paths')) {
    function eventosapp__apple_resolve_assets_paths($evento_id){
        $trace = ['inputs'=>[], 'steps'=>[]];

        // Overrides por evento
        $icon_ev  = get_post_meta($evento_id, '_eventosapp_apple_icon_url',  true);
        $logo_ev  = get_post_meta($evento_id, '_eventosapp_apple_logo_url',  true);
        $strip_ev = get_post_meta($evento_id, '_eventosapp_apple_strip_url', true);

        // Defaults globales (Integraciones)
        $icon_def  = get_option('eventosapp_apple_icon_default_url', '');
        $logo_def  = get_option('eventosapp_apple_logo_default_url', '');
        $strip_def = get_option('eventosapp_apple_strip_default_url', '');

        $trace['inputs'] = [
            'icon_ev'=>$icon_ev, 'logo_ev'=>$logo_ev, 'strip_ev'=>$strip_ev,
            'icon_def'=>$icon_def, 'logo_def'=>$logo_def, 'strip_def'=>$strip_def
        ];

        // Fuente efectiva
        $iconSrc  = $icon_ev  ?: $icon_def;
        $logoSrc  = $logo_ev  ?: $logo_def;
        $stripSrc = $strip_ev ?: $strip_def;

        // Convertir URL o ruta → archivo local utilizable
        $icon  = eventosapp__url_or_path_to_local($iconSrc);
        $logo  = eventosapp__url_or_path_to_local($logoSrc);
        $strip = eventosapp__url_or_path_to_local($stripSrc);

        $trace['steps'][] = ['asset'=>'icon','from'=>$iconSrc,'to'=>$icon,'note'=>'url_or_path_to_local'];
        $trace['steps'][] = ['asset'=>'logo','from'=>$logoSrc,'to'=>$logo,'note'=>'url_or_path_to_local'];
        $trace['steps'][] = ['asset'=>'strip','from'=>$stripSrc,'to'=>$strip,'note'=>'url_or_path_to_local'];

        // Fallback final: assets del plugin (includes/assets/apple/)
        $plugin_base = trailingslashit( dirname(__FILE__, 2) ) . 'assets/apple/';
        if (!$icon) {
            $try = $plugin_base . 'icon.png';
            if (file_exists($try)) { $icon = $try; $trace['steps'][] = ['asset'=>'icon','from'=>null,'to'=>$icon,'note'=>'fallback plugin icon.png']; }
        }
        if (!$logo) {
            $try = $plugin_base . 'logo.png';
            if (file_exists($try)) { $logo = $try; $trace['steps'][] = ['asset'=>'logo','from'=>null,'to'=>$logo,'note'=>'fallback plugin logo.png']; }
        }
        if (!$strip) {
            $tryPng = $plugin_base . 'strip.png';
            $tryJpg = $plugin_base . 'strip.jpg';
            if (file_exists($tryPng)) { $strip = $tryPng; $trace['steps'][] = ['asset'=>'strip','from'=>null,'to'=>$strip,'note'=>'fallback plugin strip.png']; }
            elseif (file_exists($tryJpg)) { $strip = $tryJpg; $trace['steps'][] = ['asset'=>'strip','from'=>null,'to'=>$strip,'note'=>'fallback plugin strip.jpg']; }
        }

        evapp_err("APPLE assets resolved: icon=".(string)$icon." | logo=".(string)$logo." | strip=".(string)$strip);
        return ['icon'=>$icon,'logo'=>$logo,'strip'=>$strip,'trace'=>$trace];
    }
}


// ===============================
// FUNCIÓN: Generar/actualizar PKPass con cache-buster en la URL
// ===============================
if ( ! function_exists('eventosapp_generar_enlace_wallet_apple') ) {
function eventosapp_generar_enlace_wallet_apple($ticket_id){
    $ticket_id = (int) $ticket_id;

    // Reset logs del ticket antes de generar
    evapp_pk_debug_reset($ticket_id);
    // Origen de funciones (introspección)
    if (function_exists('evapp_pk_log_fn_origin')) evapp_pk_log_fn_origin($ticket_id);
    if (!$ticket_id) return false;

    $evento_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    if (!$evento_id) { evapp_err("APPLE [ticket:$ticket_id] Evento vacío"); return false; }

    // Config Apple global (requerida)
    $team_id   = get_option('eventosapp_apple_team_id', '');
    $pass_type = get_option('eventosapp_apple_pass_type_id', '');
    $org_name  = get_option('eventosapp_apple_org_name', 'EventosApp');
    $p12_path  = get_option('eventosapp_apple_p12_path', '');
    $p12_pass  = get_option('eventosapp_apple_p12_pass', '');
    $wwdr_pem  = get_option('eventosapp_apple_wwdr_pem', '');
    $env       = get_option('eventosapp_apple_env','sandbox');
    $auth_base = get_option('eventosapp_apple_auth_base','');

    evapp_pk_debug_append($ticket_id, 'CONFIG (Apple Wallet)', [
        'Team ID'        => $team_id,
        'Pass Type ID'   => $pass_type,
        'Organization'   => $org_name,
        'Certificado .p12 (path)' => $p12_path,
        'Password .p12 (VISIBLE)' => $p12_pass,
        'WWDR .pem (path)' => $wwdr_pem,
        'Entorno APNs'   => $env,
        'Auth base'      => $auth_base,
    ]);

    if (!$team_id || !$pass_type || !$p12_path || !$p12_pass || !$wwdr_pem
        || !file_exists($p12_path) || !file_exists($wwdr_pem)) {
        evapp_err("APPLE [ticket:$ticket_id] Config/certificados incompletos. Aborto.");
        evapp_pk_debug_append($ticket_id, 'ABORTO', [
            'motivo' => 'Config/certificados incompletos',
            'p12_exists' => file_exists($p12_path),
            'wwdr_exists'=> file_exists($wwdr_pem),
        ]);
        return false;
    }

    // Verificación básica del .p12 contra Pass Type ID y vigencia
    try {
        if (!function_exists('openssl_pkcs12_read') || !function_exists('openssl_x509_parse')) {
            evapp_pk_debug_append($ticket_id, 'AVISO', [
                'openssl' => 'Faltan funciones openssl_pkcs12_read/openssl_x509_parse; no se puede verificar .p12'
            ]);
        } else {
            $pkcs12 = @file_get_contents($p12_path);
            $creds  = [];
            if ($pkcs12 && @openssl_pkcs12_read($pkcs12, $creds, $p12_pass) && !empty($creds['cert'])) {
                $parsed   = @openssl_x509_parse($creds['cert']);
                $subject  = (array)($parsed['subject'] ?? []);
                $uid      = $subject['UID'] ?? null;
                $cn       = $subject['CN']  ?? null;
                $validToT = (int)($parsed['validTo_time_t'] ?? 0);
                $validTo  = $validToT ? date('c', $validToT) : null;
                $isExpired = ($validToT && $validToT < time());

                evapp_pk_debug_append($ticket_id, 'CERT (.p12) INFO', [
                    'subject.UID' => $uid,
                    'subject.CN'  => $cn,
                    'validTo'     => $validTo,
                    'expired'     => $isExpired ? 'YES' : 'NO',
                ]);

                if ($isExpired) {
                    evapp_pk_debug_append($ticket_id, 'ABORTO', [
                        'motivo' => 'Certificado .p12 vencido',
                        'validTo' => $validTo
                    ]);
                    return false;
                }

                if ($uid && $uid !== $pass_type) {
                    evapp_pk_debug_append($ticket_id, 'ABORTO', [
                        'motivo' => 'El .p12 NO corresponde al Pass Type ID configurado',
                        'p12_UID' => $uid,
                        'json_passTypeIdentifier' => $pass_type,
                    ]);
                    return false;
                }
            } else {
                evapp_pk_debug_append($ticket_id, 'ABORTO', [
                    'motivo' => 'No se pudo leer el .p12 o falta la clave privada',
                    'p12_path' => $p12_path
                ]);
                return false;
            }
        }
    } catch (\Throwable $e) {
        evapp_pk_debug_append($ticket_id, 'ERROR', [
            'etapa' => 'parse .p12',
            'ex'    => $e->getMessage()
        ]);
        return false;
    }

    // Datos de ticket
    $codigo_qr   = get_post_meta($ticket_id, 'eventosapp_ticketID', true) ?: $ticket_id;
    $nombre      = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
    $apellido    = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
    $asistente   = trim(($nombre?:'').' '.($apellido?:''));
    $localidad   = (string) get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);
    $email       = (string) get_post_meta($ticket_id, '_eventosapp_asistente_email', true);

    // Contexto evento
    $ctx = eventosapp_wallet_build_event_context($evento_id);
    $nombre_evento = $ctx['nombre_evento'];
    $direccion     = $ctx['direccion'];

    // Cálculo de relevantDate
    $tz_site = get_option('timezone_string') ?: 'UTC';
    $tz_ev   = get_post_meta($evento_id, '_eventosapp_zona_horaria', true) ?: $tz_site;
    $tipo    = get_post_meta($evento_id, '_eventosapp_tipo_fecha', true) ?: 'unica';

    $fi_meta  = get_post_meta($evento_id, '_eventosapp_fecha_unica',   true);
    $fi_rango = get_post_meta($evento_id, '_eventosapp_fecha_inicio',  true);
    $ff_rango = get_post_meta($evento_id, '_eventosapp_fecha_fin',     true);
    $fechas_noco_meta = get_post_meta($evento_id, '_eventosapp_fechas_noco', true);
    $hi = get_post_meta($evento_id, '_eventosapp_hora_inicio', true) ?: '00:00';

    $fechas_noco = $fechas_noco_meta;
    if (!is_array($fechas_noco)) {
        $fechas_noco = (is_string($fechas_noco) && $fechas_noco) ? array_map('trim', explode(',', $fechas_noco)) : [];
    }
    $fechas_noco = array_values(array_filter($fechas_noco, function($f){ return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f); }));
    sort($fechas_noco);

    $toIsoZ = function($date, $time, $tz) {
        try {
            $dt = new DateTime(trim($date.' '.$time), new DateTimeZone($tz ?: 'UTC'));
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable $e) {
            return null;
        }
    };

    evapp_pk_debug_append($ticket_id, 'Apple relevantDate → INPUTS', [
        'tipo'         => $tipo,
        'fi_meta'      => (string)$fi_meta,
        'fi_rango'     => (string)$fi_rango,
        'ff_rango'     => (string)$ff_rango,
        'fechas_noco'  => $fechas_noco,
        'hora_inicio'  => (string)$hi,
        'tz_ev'        => (string)$tz_ev,
        'ctx.startISO' => (string)($ctx['startISO'] ?? ''),
        'ctx.endISO'   => (string)($ctx['endISO']   ?? ''),
    ]);

    $fi = $ff = null;
    if ($tipo === 'unica') {
        $fi = $fi_meta ?: null;
    } elseif ($tipo === 'consecutiva') {
        $fi = $fi_rango ?: null;
        if (!$fi) $ff = $ff_rango ?: null;
    } else { // noconsecutiva
        if ($fechas_noco) {
            $todayLocal = (new DateTime('today', new DateTimeZone($tz_ev)))->format('Y-m-d');
            $future = array_values(array_filter($fechas_noco, function($f) use ($todayLocal){ return $f >= $todayLocal; }));
            $ff = $future ? reset($future) : end($fechas_noco);
        }
    }

    $dateLocal  = $fi ?: $ff;
    $isoCalc    = ($dateLocal ? $toIsoZ($dateLocal, $hi, $tz_ev) : null);

    $relISOFinal = $isoCalc ?: ($ctx['endISO'] ?: ($ctx['startISO'] ?: null));
    $relSource   = $isoCalc ? 'calc(local+tz)' : (($ctx['endISO'] ?? null) ? 'ctx.endISO' : (($ctx['startISO'] ?? null) ? 'ctx.startISO' : 'none'));

    evapp_pk_debug_append($ticket_id, 'Apple relevantDate → COMPUTED', [
        'dateLocal_elegida' => (string)$dateLocal,
        'isoCalc'           => (string)$isoCalc,
        'fallback_usado'    => $isoCalc ? 'NO' : 'SÍ',
        'relISOFinal'       => (string)$relISOFinal,
        'relSource'         => (string)$relSource,
    ]);

    // Colores
    $bg_hex = get_post_meta($evento_id, '_eventosapp_apple_hex_bg', true) ?: get_option('eventosapp_apple_bg_hex', '#3782C4');
    $fg_hex = get_post_meta($evento_id, '_eventosapp_apple_hex_fg', true) ?: get_option('eventosapp_apple_fg_hex', '#FFFFFF');
    $label_hex = get_post_meta($evento_id, '_eventosapp_apple_hex_label', true) ?: get_option('eventosapp_apple_label_hex', '#FFFFFF');

    $bg_css    = eventosapp__hex_to_rgb_css($bg_hex);
    $fg_css    = eventosapp__hex_to_rgb_css($fg_hex);
    $label_css = eventosapp__hex_to_rgb_css($label_hex);

    // PassKit Web Service URL + token por ticket
    $ws_url = '';
    if (function_exists('evapp_apple_cfg')) {
        $cfg = evapp_apple_cfg();
        $ws_url = rtrim($cfg['ws_url'] ?? '', '/');
    }
    if (!$ws_url) $ws_url = rtrim(home_url('/eventosapp-passkit/v1'), '/');

    $auth_token = get_post_meta($ticket_id, '_evapp_pk_auth', true);
    if (!$auth_token) {
        $auth_token = wp_generate_password(32, false, false);
        update_post_meta($ticket_id, '_evapp_pk_auth', $auth_token);
    }

    evapp_pk_debug_append($ticket_id, 'WS/Colores', [
        'Web Service URL' => $ws_url,
        'Auth token (ticket)' => $auth_token,
        'BG HEX' => $bg_hex, 'FG HEX' => $fg_hex, 'Label HEX' => $label_hex,
        'BG CSS' => $bg_css, 'FG CSS' => $fg_css, 'Label CSS' => $label_css,
    ]);

    // Carpeta temporal
    $uploads = wp_upload_dir();
    $tmpDir  = trailingslashit($uploads['basedir']) . 'eventosapp-tmp-' . wp_generate_password(8,false,false) . '/';
    if ( ! wp_mkdir_p($tmpDir) ) {
        evapp_err("APPLE [ticket:$ticket_id] No se pudo crear directorio temporal.");
        evapp_pk_debug_append($ticket_id, 'ERROR', ['tmpDir'=>'NO creado', 'ruta_propuesta'=>$tmpDir]);
        return false;
    }
    evapp_pk_debug_append($ticket_id, 'tmpDir', ['tmpDir'=>$tmpDir]);

    // Assets Apple (icon/logo/strip)
    $assets = eventosapp__apple_resolve_assets_paths($evento_id);
    evapp_pk_debug_append($ticket_id, 'Assets (entrada y resolución)', $assets['trace'] ?? []);

    if (empty($assets['icon']) || !file_exists($assets['icon'])) {
        evapp_err("APPLE [ticket:$ticket_id] Falta icon.png. Aborto.");
        evapp_pk_debug_append($ticket_id, 'ABORTO', ['motivo'=>'Falta icon.png','icon_source'=>($assets['icon']??null)]);
        foreach (glob($tmpDir.'/*') as $f) @unlink($f); @rmdir($tmpDir);
        return false;
    }

    // Librería PKPass
    $PKPassClass = class_exists('PKPass') ? 'PKPass' : (class_exists('\PKPass\PKPass') ? '\PKPass\PKPass' : null);
    if (!$PKPassClass) {
        evapp_err("APPLE [ticket:$ticket_id] Librería PKPass no encontrada. Aborto.");
        evapp_pk_debug_append($ticket_id, 'ABORTO', ['motivo'=>'PKPass no encontrada']);
        foreach (glob($tmpDir.'/*') as $f) @unlink($f); @rmdir($tmpDir);
        return false;
    }

    // Instanciar y setear certs
    try {
        $did = [];
        $pass = null;

        $constDone = false;
        try {
            $constTypeP12 = null;
            foreach ([$PKPassClass.'::CERT_TYPE_P12', 'PKPass::CERT_TYPE_P12', '\PKPass\PKPass::CERT_TYPE_P12'] as $constName) {
                if (defined($constName)) { $constTypeP12 = constant($constName); break; }
            }
            if (!$constTypeP12) { $constTypeP12 = 'p12'; }

            $pass = new $PKPassClass($p12_path, $p12_pass, $wwdr_pem, $constTypeP12);
            $did[] = '__construct(p12_path, p12_pass, wwdr_pem, p12)';
            $constDone = true;
        } catch (\Throwable $e) {
            // fallback a setters
        }

        if (!$constDone || !$pass) {
            $pass = new $PKPassClass();
            $did[] = '__construct()';

            foreach (['setCertificate','setCertificatePath','setCertificateFile'] as $m) {
                if (method_exists($pass, $m)) { $pass->{$m}($p12_path); $did[] = $m.'(p12_path)'; break; }
            }
            if (method_exists($pass,'setCertificatePassword')) {
                $pass->setCertificatePassword($p12_pass); $did[]='setCertificatePassword(p12_pass)';
            }
            foreach (['setWWDRcertPath','setWWDRCertPath','setWwdrCertPath','setAppleWWDRCA'] as $m) {
                if (method_exists($pass, $m)) { $pass->{$m}($wwdr_pem); $did[] = $m.'(wwdr)'; break; }
            }
        }

        evapp_pk_debug_append($ticket_id, 'PKPass init', ['class'=>$PKPassClass, 'calls'=>$did]);
    } catch (\Throwable $e) {
        evapp_err("APPLE [ticket:$ticket_id] Error inicializando PKPass: ".$e->getMessage());
        evapp_pk_debug_append($ticket_id, 'ERROR', ['etapa'=>'init PKPass', 'ex'=>$e->getMessage()]);
        foreach (glob($tmpDir.'/*') as $f) @unlink($f); @rmdir($tmpDir);
        return false;
    }

// Payload pass.json
    $payload = [
        'formatVersion'      => 1,
        'passTypeIdentifier' => $pass_type,
        'teamIdentifier'     => $team_id,
        'organizationName'   => $org_name,
        'serialNumber'       => (string)$codigo_qr,
        'description'        => $nombre_evento ?: 'Entrada',
        'relevantDate'       => $relISOFinal,
        'webServiceURL'      => $ws_url,
        'authenticationToken'=> $auth_token,
        'eventTicket'        => [
            'primaryFields'   => [
                ['key'=>'event', 'label'=>'Evento', 'value'=> (string)$nombre_evento],
            ],
            'secondaryFields' => array_values(array_filter([
                $asistente ? ['key'=>'asistente','label'=>'Asistente','value'=>$asistente] : null,
                $localidad ? ['key'=>'localidad','label'=>'Localidad','value'=>$localidad] : null,
            ])),
            'auxiliaryFields' => array_values(array_filter([
                $direccion ? ['key'=>'lugar','label'=>'Lugar','value'=>$direccion] : null,
                $email ? ['key'=>'email','label'=>'Email','value'=>$email] : null,
            ])),
        ],
        'barcode' => [
            'format'          => 'PKBarcodeFormatQR',
            'message'         => $codigo_qr . '-awallet',
            'messageEncoding' => 'utf-8',
            'altText'         => 'Código de ingreso'
        ],
    ];
    if ($bg_css)    $payload['backgroundColor'] = $bg_css;
    if ($fg_css)    $payload['foregroundColor'] = $fg_css;
    if ($label_css) $payload['labelColor']      = $label_css;

    // Fecha visible en header (si aplica)
    if (!empty($relISOFinal)) {
        $payload['eventTicket']['headerFields'] = [[
            'key'             => 'fecha',
            'label'           => 'Fecha',
            'value'           => $relISOFinal,
            'dateStyle'       => 'PKDateStyleLong',
            'timeStyle'       => 'PKDateStyleShort',
            'isRelative'      => false,
            'ignoresTimeZone' => false
        ]];
    }

    // Limpia nulls/arrays vacías
    $removeNulls = function (&$arr) use (&$removeNulls) {
        foreach ($arr as $k => &$v) {
            if (is_array($v)) {
                $removeNulls($v);
                if ($v === []) unset($arr[$k]);
            } elseif ($v === null) {
                unset($arr[$k]);
            }
        }
    };
    $removeNulls($payload);

    // Cargar pass.json (string)
    $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $jsonMethod = 'setJSON';
    try {
        if (method_exists($pass,'setJSON')) {
            $pass->setJSON($json);
            $jsonMethod = 'setJSON(string)';
        } else {
            throw new \Exception('setJSON no disponible');
        }
    } catch (\Throwable $e) {
        if (method_exists($pass,'addFileFromString')) {
            $pass->addFileFromString('pass.json', $json);
            $jsonMethod = 'addFileFromString(pass.json, json)';
        } elseif (method_exists($pass,'addData')) {
            $pass->addData('pass.json', $json);
            $jsonMethod = 'addData(pass.json, json)';
        } else {
            $jsonPath = $tmpDir.'pass.json';
            file_put_contents($jsonPath, $json);
            if (method_exists($pass,'addFile')) {
                try { $pass->addFile($jsonPath); $jsonMethod = 'addFile('.$jsonPath.')'; } catch (\Throwable $ee) { $jsonMethod = 'addFile('.$jsonPath.') [EXCEPTION: '.$ee->getMessage().']'; }
            } else {
                $jsonMethod = 'NO_METHODS_AVAILABLE';
            }
        }
    }
    evapp_pk_debug_append($ticket_id, 'pass.json', [
        'método'       => $jsonMethod,
        'bytes'        => strlen($json),
        'relevantDate' => isset($payload['relevantDate']) ? (string)$payload['relevantDate'] : '(no_set)',
        'relSource'    => (string)$relSource,
    ]);

    // Helpers PNG + normalización
    $ensure_png = function($src, $destName, $w=null, $h=null) use ($tmpDir, $ticket_id){
        $log = ['src'=>$src, 'destName'=>$destName, 'resize'=>($w&&$h?($w.'x'.$h):'none'), 'out'=>null, 'ok'=>false];
        if (!$src || !file_exists($src)) { evapp_pk_debug_append($ticket_id, 'ensure_png', $log); return null; }
        $bin = @file_get_contents($src);
        if ($bin === false || $bin === '') { evapp_pk_debug_append($ticket_id, 'ensure_png', $log); return null; }
        $dest = $tmpDir.$destName;
        $img = @imagecreatefromstring($bin);
        if ($img) {
            if ($w && $h) {
                $dst = imagecreatetruecolor($w, $h);
                imagesavealpha($dst, true);
                $trans = imagecolorallocatealpha($dst, 0,0,0,127);
                imagefill($dst,0,0,$trans);
                $iw = imagesx($img); $ih = imagesy($img);
                imagecopyresampled($dst, $img, 0,0, 0,0, $w,$h, $iw,$ih);
                imagepng($dst, $dest);
                imagedestroy($dst);
            } else {
                imagepng($img, $dest);
            }
            imagedestroy($img);
        } else {
            file_put_contents($dest, $bin);
        }
        $ok = file_exists($dest);
        $log['out'] = $dest;
        $log['ok']  = $ok;
        evapp_pk_debug_append($ticket_id, 'ensure_png', $log);
        return $ok ? $dest : null;
    };

    $iconPath   = $ensure_png($assets['icon'],  'icon.png',    29, 29);
    $icon2xPath = $ensure_png($assets['icon'],  'icon@2x.png', 58, 58);
    $logoPath   = $ensure_png($assets['logo'],  'logo.png');
    $stripPath  = $ensure_png($assets['strip'], 'strip.png');

    if (!$iconPath) {
        evapp_err("APPLE [ticket:$ticket_id] Falta icon.png tras normalización. Aborto.");
        evapp_pk_debug_append($ticket_id, 'ABORTO', ['motivo'=>'icon.png no generado', 'icon_source'=>$assets['icon']]);
        foreach (glob($tmpDir.'/*') as $f) @unlink($f); @rmdir($tmpDir);
        return false;
    }

    $add_file = function($absPath, $destName = null) use ($pass, $ticket_id){
        $destName = $destName ?: basename($absPath);
        $log = ['destName'=>$destName, 'path'=>$absPath, 'tries'=>[], 'result'=>false];
        if (!$absPath || !file_exists($absPath)) {
            $log['tries'][] = 'SKIP (no existe)';
            evapp_pk_debug_append($ticket_id, 'addFile', $log);
            return false;
        }
        $ok = false;
        if (method_exists($pass, 'addFile')) {
            try { $pass->addFile($absPath); $ok = true; $log['tries'][] = 'addFile(path) OK'; }
            catch (\Throwable $e) { $log['tries'][] = 'addFile(path) EX: '.$e->getMessage(); }
            if (!$ok) {
                try { $pass->addFile($absPath, $destName); $ok = true; $log['tries'][] = 'addFile(path,name) OK'; }
                catch (\Throwable $e) { $log['tries'][] = 'addFile(path,name) EX: '.$e->getMessage(); }
            }
        }
        if (!$ok) {
            $bytes = @file_get_contents($absPath);
            if ($bytes !== false && $bytes !== '') {
                if (method_exists($pass,'addFileFromString')) {
                    try { $pass->addFileFromString($destName, $bytes); $ok = true; $log['tries'][]='addFileFromString OK'; }
                    catch (\Throwable $e) { $log['tries'][]='addFileFromString EX: '.$e->getMessage(); }
                }
                if (!$ok && method_exists($pass,'addData')) {
                    try { $pass->addData($destName, $bytes); $ok = true; $log['tries'][]='addData OK'; }
                    catch (\Throwable $e) { $log['tries'][]='addData EX: '.$e->getMessage(); }
                }
            } else {
                $log['tries'][]='readfile(absPath) vacío';
            }
        }
        if (!$ok) evapp_err("APPLE addFile {$destName} error: no se pudo agregar con ninguna firma.");
        $log['result'] = $ok;
        evapp_pk_debug_append($ticket_id, 'addFile', $log);
        return $ok;
    };

    $add_file($iconPath, 'icon.png');
    if ($icon2xPath) $add_file($icon2xPath, 'icon@2x.png');
    if ($logoPath)   $add_file($logoPath, 'logo.png');
    if ($stripPath)  $add_file($stripPath, 'strip.png');

    // Crear pase (modo seguro, sin forzar descarga)
    $outFile = $tmpDir . 'out.pkpass';
    $blob = '';
    $createNotes = [];

    $prevHeaders = function_exists('headers_list') ? headers_list() : [];
    ob_start();
    try {
        $res = null;
        try { $res = $pass->create(false); $createNotes[] = 'create(false) intentado'; }
        catch (\Throwable $e) { $createNotes[] = 'create(false) EX: '.$e->getMessage(); }

        if ($res === null) {
            try { $res = $pass->create(); $createNotes[] = 'create() intentado'; }
            catch (\Throwable $e) { $createNotes[] = 'create() EX: '.$e->getMessage(); }
        }
        if (!$res && file_exists($outFile)) {
            $blob = file_get_contents($outFile);
            $createNotes[] = 'outFile leído OK';
        }
        if (is_string($res) && $res !== '') {
            $blob = $res;
            $createNotes[] = 'res string OK';
        }
        $stdout = ob_get_clean();
        if ($blob === '' && $stdout !== '') {
            $blob = $stdout;
            $createNotes[] = 'stdout capturado OK';
        }
        if ($blob === '' && method_exists($pass, 'getAsString')) {
            $blob = (string) $pass->getAsString();
            $createNotes[] = 'getAsString() OK';
        }
    } finally {
        if (function_exists('headers_list') && function_exists('header_remove')) {
            $after = headers_list();
            foreach ($after as $h) {
                $hLow = strtolower($h);
                if (strpos($hLow, 'content-type: application/vnd.apple.pkpass') === 0) {
                    header_remove('Content-Type');
                    $createNotes[] = 'header_remove(Content-Type)';
                }
                if (strpos($hLow, 'content-disposition:') === 0) {
                    header_remove('Content-Disposition');
                    $createNotes[] = 'header_remove(Content-Disposition)';
                }
            }
        }
        if (ob_get_level() > 0) { @ob_end_clean(); }
    }

    evapp_pk_debug_append($ticket_id, 'create() (safe)', [
        'notas'      => $createNotes,
        'outFile'    => $outFile,
        'blob_bytes' => strlen($blob),
    ]);

    foreach (glob($tmpDir.'/*') as $f) @unlink($f);
    @rmdir($tmpDir);

    if ($blob === '') {
        $err = method_exists($pass,'getError') ? $pass->getError() : (method_exists($pass,'getErrorMessage') ? $pass->getErrorMessage() : 'desconocido');
        evapp_err("APPLE [ticket:$ticket_id] Error PKPass (blob vacío): ".(is_string($err)?$err:json_encode($err)));
        evapp_pk_debug_append($ticket_id, 'ERROR', ['etapa'=>'create()', 'detalle'=>$err]);
        return false;
    }

    // Guardar archivo en uploads/eventosapp-pkpass/
    $dir = trailingslashit($uploads['basedir']) . 'eventosapp-pkpass/';
    if (!file_exists($dir)) wp_mkdir_p($dir);
    $fname = $dir . $codigo_qr . '.pkpass';
    file_put_contents($fname, $blob);

    // URL normal + versionada (cache-buster)
    $base_url = trailingslashit($uploads['baseurl']) . 'eventosapp-pkpass/' . rawurlencode($codigo_qr) . '.pkpass';
    $mtime    = @filemtime($fname) ?: time();
    $md5      = @md5_file($fname) ?: '';
    $url_v    = $base_url . '?v=' . $mtime;

    // Persistir metas (usar siempre la versionada en el front/back)
    update_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url_raw', $base_url);
    update_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple',   $url_v);
    update_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url',$url_v);
    update_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url',      $url_v);
    update_post_meta($ticket_id, '_eventosapp_ticket_pkpass_mtime',    $mtime);
    update_post_meta($ticket_id, '_eventosapp_ticket_pkpass_md5',      $md5);

    evapp_pk_debug_append($ticket_id, 'RESULTADO', [
        'pkpass_path' => $fname,
        'pkpass_url'  => $url_v,
        'pkpass_url_raw' => $base_url,
        'bytes'       => strlen($blob),
        'mtime'       => $mtime,
        'md5'         => $md5,
    ]);

    error_log("EVENTOSAPP APPLE [ticket:$ticket_id] PKPass generado OK -> $url_v");
    return $url_v;
}}

/** Wrapper canonizado para evitar duplicidad */
if (!function_exists('eventosapp_apple_generate_pass')) {
    function eventosapp_apple_generate_pass($ticket_id, $debug=false){
        if (function_exists('evapp_pk_log_fn_origin')) evapp_pk_log_fn_origin((int)$ticket_id);
        return eventosapp_generar_enlace_wallet_apple($ticket_id);
    }
}


/* ============================================================
 * =========== AUTO .HTACCESS (PKPASS MIME/HEADERS) ===========
 * ============================================================ */

function eventosapp_pkpass_rules_lines(){
    return [
        '<IfModule mod_mime.c>',
        '  AddType application/vnd.apple.pkpass .pkpass',
        '</IfModule>',
        '<IfModule mod_deflate.c>',
        '  <FilesMatch "\.pkpass$">',
        '    SetEnv no-gzip 1',
        '  </FilesMatch>',
        '</IfModule>',
        '<IfModule mod_headers.c>',
        '  <FilesMatch "\.pkpass$">',
        '    Header set Content-Disposition "inline"',
        '    Header set Content-Type "application/vnd.apple.pkpass"',
        '    Header set Cache-Control "no-cache, no-store, must-revalidate"',
        '    Header set Pragma "no-cache"',
        '    Header set Expires "0"',
        '  </FilesMatch>',
        '</IfModule>',
    ];
}



/* ============================================================
 * ====== APPLE PASSKIT WEB SERVICE (actualizaciones OTA) =====
 * ============================================================ */

/** Serial canónico de un ticket (= QR configurado) */
if (!function_exists('evapp_pk_get_serial')) {
    function evapp_pk_get_serial($ticket_id){
        $s = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
        return (string)($s ?: $ticket_id);
    }
}

/** Marca Last-Modified al regenerar el pase (RFC-1123 para headers) */
if (!function_exists('evapp_pk_touch_lastmod')) {
    function evapp_pk_touch_lastmod($ticket_id){
        $ts = time();
        $http = gmdate('D, d M Y H:i:s', $ts).' GMT';
        update_post_meta($ticket_id, '_evapp_pk_lastmod_ts', $ts);
        update_post_meta($ticket_id, '_evapp_pk_lastmod_http', $http);
        return $http;
    }
}
if (!function_exists('evapp_pk_get_lastmod')) {
    function evapp_pk_get_lastmod($ticket_id, $as_http=true){
        $ts = (int) get_post_meta($ticket_id, '_evapp_pk_lastmod_ts', true);
        $http = (string) get_post_meta($ticket_id, '_evapp_pk_lastmod_http', true);
        if (!$ts || !$http) { return evapp_pk_touch_lastmod($ticket_id); }
        return $as_http ? $http : $ts;
    }
}

/** Índice global device->serials (para listar actualizados) */
if (!function_exists('evapp_pk_device_index_get')) {
    function evapp_pk_device_index_get(){
        $idx = get_option('eventosapp_pk_device_index');
        return is_array($idx) ? $idx : [];
    }
}
if (!function_exists('evapp_pk_device_index_set')) {
    function evapp_pk_device_index_set($idx){
        update_option('eventosapp_pk_device_index', $idx, false);
    }
}

/** Busca ticket por serial. Serial numérico = ID directo como atajo. */
if (!function_exists('evapp_ticket_id_by_serial')) {
    function evapp_ticket_id_by_serial($serial){
        $serial = (string) $serial;
        if (ctype_digit($serial)) {
            $pid = (int)$serial;
            $p = get_post($pid);
            if ($p && $p->post_type === 'eventosapp_ticket') return $pid;
        }
        $q = get_posts([
            'post_type'  => 'eventosapp_ticket',
            'post_status'=> 'any',
            'meta_key'   => 'eventosapp_ticketID',
            'meta_value' => $serial,
            'fields'     => 'ids',
            'numberposts'=> 1,
        ]);
        return $q ? (int)$q[0] : 0;
    }
}

/** Registra un device para un serial */
if (!function_exists('evapp_pk_register_device')) {
    function evapp_pk_register_device($ticket_id, $deviceId, $pushToken){
        $serial = evapp_pk_get_serial($ticket_id);
        $map = get_post_meta($ticket_id, '_evapp_pk_devices', true);
        if (!is_array($map)) $map = [];
        $already = isset($map[$deviceId]) && $map[$deviceId]['token'] === $pushToken;
        $map[$deviceId] = ['token'=>$pushToken, 'ts'=>time()];
        update_post_meta($ticket_id, '_evapp_pk_devices', $map);

        $idx = evapp_pk_device_index_get();
        if (!isset($idx[$deviceId])) $idx[$deviceId] = [];
        if (!in_array($serial, $idx[$deviceId], true)) $idx[$deviceId][] = $serial;
        evapp_pk_device_index_set($idx);

        return $already ? 200 : 201;
    }
}

/** Elimina un device de un serial */
if (!function_exists('evapp_pk_unregister_device')) {
    function evapp_pk_unregister_device($ticket_id, $deviceId){
        $serial = evapp_pk_get_serial($ticket_id);
        $map = get_post_meta($ticket_id, '_evapp_pk_devices', true);
        if (!is_array($map)) $map = [];
        unset($map[$deviceId]);
        update_post_meta($ticket_id, '_evapp_pk_devices', $map);

        $idx = evapp_pk_device_index_get();
        if (isset($idx[$deviceId])) {
            $idx[$deviceId] = array_values(array_filter($idx[$deviceId], fn($s)=>$s!==$serial));
            if (!$idx[$deviceId]) unset($idx[$deviceId]);
            evapp_pk_device_index_set($idx);
        }
        return true;
    }
}

/** Envía push APNs a todos los devices registrados para el ticket */
if (!function_exists('evapp_pk_push_devices_for_ticket')) {
    function evapp_pk_push_devices_for_ticket($ticket_id){
        $env       = get_option('eventosapp_apple_env','sandbox'); // sandbox|production
        $pass_type = get_option('eventosapp_apple_pass_type_id','');
        $p12_path  = get_option('eventosapp_apple_p12_path','');
        $p12_pass  = get_option('eventosapp_apple_p12_pass','');

        $devices = get_post_meta($ticket_id, '_evapp_pk_devices', true);
        if (!is_array($devices) || !$devices) return 0;

        $sent = 0;
        foreach ($devices as $devId => $row) {
            $token = (string) ($row['token'] ?? '');
            if (!$token) continue;
            $ok = evapp_pk_apns_push($token, $pass_type, $env, $p12_path, $p12_pass);
            $sent += $ok ? 1 : 0;
        }
        evapp_err("PKPASS PUSH ticket:$ticket_id enviados:$sent");
        return $sent;
    }
}

/** Push HTTP/2 con cURL usando el MISMO .p12 del pase */
if (!function_exists('evapp_pk_apns_push')) {
    function evapp_pk_apns_push($deviceToken, $apnsTopic, $env, $p12_path, $p12_pass){
        if (!$deviceToken || !$apnsTopic || !$p12_path || !file_exists($p12_path)) return false;
        $host = ($env === 'production') ? 'https://api.push.apple.com' : 'https://api.sandbox.push.apple.com';
        $url  = $host.'/3/device/'.trim($deviceToken);
        $ch = curl_init($url);
        $headers = [
            'apns-topic: '.$apnsTopic,
            'apns-push-type: background',
        ];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '{}',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSLCERT        => $p12_path,
            CURLOPT_SSLCERTTYPE    => 'P12',
            CURLOPT_SSLKEYPASSWD   => $p12_pass,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($code >= 200 && $code < 300) return true;
        evapp_err("APNs PUSH fallo code:$code token:".substr($deviceToken,0,6).'... resp:'.substr((string)$res,0,180).' err:'.$err);
        return false;
    }
}

/** Auth header "ApplePass <token>" → devuelve token o '' */
if (!function_exists('evapp_pk_auth_token_from_header')) {
    function evapp_pk_auth_token_from_header(){
        $h = '';
        if (function_exists('getallheaders')) {
            $hs = getallheaders();
            $h  = $hs['Authorization'] ?? $hs['authorization'] ?? '';
        } else {
            $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        }
        if (!$h) return '';
        if (stripos($h,'ApplePass ') === 0) return trim(substr($h,10));
        return '';
    }
}

/** Registro de rutas REST del Web Service PassKit */
add_action('rest_api_init', function(){
    $ns = 'eventosapp-passkit/v1';

    // 1) Registrar device para un serial
    register_rest_route($ns, '/devices/(?P<device>[A-Za-z0-9\-]+)/registrations/(?P<passType>[^\/]+)/(?P<serial>[^\/]+)', [
        'methods'  => 'POST',
        'callback' => function($req){
            $device = $req['device']; $serial = $req['serial']; $passType = $req['passType'];
            $ticket_id = evapp_ticket_id_by_serial($serial);
            if (!$ticket_id) return new WP_REST_Response(null, 404);

            // Autorización: debe coincidir con el token del pase
            $token_req = evapp_pk_auth_token_from_header();
            $token_meta= (string) get_post_meta($ticket_id, '_evapp_pk_auth', true);
            if (!$token_req || !hash_equals($token_meta, $token_req)) {
                return new WP_REST_Response(null, 401);
            }

            $body = json_decode($req->get_body(), true);
            $pushToken = (string) ($body['pushToken'] ?? '');
            if (!$pushToken) return new WP_REST_Response(null, 400);

            $code = evapp_pk_register_device($ticket_id, $device, $pushToken);
            return new WP_REST_Response(null, $code);
        },
        'permission_callback' => '__return_true'
    ]);

    // 2) Eliminar registro de device para un serial
    register_rest_route($ns, '/devices/(?P<device>[A-Za-z0-9\-]+)/registrations/(?P<passType>[^\/]+)/(?P<serial>[^\/]+)', [
        'methods'  => 'DELETE',
        'callback' => function($req){
            $device = $req['device']; $serial = $req['serial'];
            $ticket_id = evapp_ticket_id_by_serial($serial);
            if (!$ticket_id) return new WP_REST_Response(null, 404);

            // Autorización (igual que POST)
            $token_req = evapp_pk_auth_token_from_header();
            $token_meta= (string) get_post_meta($ticket_id, '_evapp_pk_auth', true);
            if (!$token_req || !hash_equals($token_meta, $token_req)) {
                return new WP_REST_Response(null, 401);
            }

            evapp_pk_unregister_device($ticket_id, $device);
            return new WP_REST_Response(null, 200);
        },
        'permission_callback' => '__return_true'
    ]);

    // 3) Listar seriales de un device con cambios desde "passesUpdatedSince"
    register_rest_route($ns, '/devices/(?P<device>[A-Za-z0-9\-]+)/registrations/(?P<passType>[^\/]+)', [
        'methods'  => 'GET',
        'callback' => function($req){
            $device = $req['device'];
            $since  = (string) ($req->get_param('passesUpdatedSince') ?? '');
            $sinceTs= $since ? strtotime($since) : 0;

            $idx = evapp_pk_device_index_get();
            $serials = $idx[$device] ?? [];

            $updated = [];
            $maxTs = 0;

            foreach ($serials as $serial){
                $ticket_id = evapp_ticket_id_by_serial($serial);
                if (!$ticket_id) continue;
                $ts = (int) evapp_pk_get_lastmod($ticket_id, false);
                if ($ts > $sinceTs) $updated[] = $serial;
                if ($ts > $maxTs) $maxTs = $ts;
            }

            if (!$updated) return new WP_REST_Response(null, 204);
            $resp = [
                'lastUpdated'   => gmdate('Y-m-d H:i:s', $maxTs),
                'serialNumbers' => array_values(array_unique($updated)),
            ];
            return new WP_REST_Response($resp, 200);
        },
        'permission_callback' => '__return_true'
    ]);

    // 4) Entregar el pase actual (respeta If-Modified-Since)
    register_rest_route($ns, '/passes/(?P<passType>[^\/]+)/(?P<serial>[^\/]+)', [
        'methods'  => 'GET',
        'callback' => function($req){
            $serial = $req['serial'];
            $ticket_id = evapp_ticket_id_by_serial($serial);
            if (!$ticket_id) return new WP_REST_Response(null, 404);

            // Auth por token del pase
            $token_req = evapp_pk_auth_token_from_header();
            $token_meta= (string) get_post_meta($ticket_id, '_evapp_pk_auth', true);
            if (!$token_req || !hash_equals($token_meta, $token_req)) {
                return new WP_REST_Response(null, 401);
            }

            // Localiza archivo .pkpass
            $uploads = wp_upload_dir();
            $path = trailingslashit($uploads['basedir']).'eventosapp-pkpass/'.$serial.'.pkpass';
            if (!file_exists($path)) return new WP_REST_Response(null, 404);

            $lm_http = evapp_pk_get_lastmod($ticket_id, true);
            $ifMod   = $req->get_header('if-modified-since');
            if ($ifMod && strtotime($ifMod) >= strtotime($lm_http)) {
                $resp = new WP_REST_Response(null, 304);
                $resp->header('Last-Modified', $lm_http);
                return $resp;
            }

            $bytes = file_get_contents($path);
            $resp = new WP_REST_Response($bytes, 200);
            $resp->header('Content-Type', 'application/vnd.apple.pkpass');
            $resp->header('Content-Disposition', 'inline; filename="'.$serial.'.pkpass"');
            $resp->header('Cache-Control', 'no-cache');
            $resp->header('Last-Modified', $lm_http);
            return $resp;
        },
        'permission_callback' => '__return_true'
    ]);

    // 5) /log (Apple envía logs opcionales del dispositivo)
    register_rest_route($ns, '/log', [
        'methods'  => 'POST',
        'callback' => function($req){
            $body = $req->get_body();
            evapp_err('PKPASS /log -> '.$body);
            return new WP_REST_Response(null, 200);
        },
        'permission_callback' => '__return_true'
    ]);
});




if (!function_exists('eventosapp_write_root_htaccess_rules')) {
    function eventosapp_write_root_htaccess_rules(){
        // Requiere misc.php para insert_with_markers
        if ( ! function_exists('insert_with_markers') ) {
            if ( defined('ABSPATH') && file_exists( ABSPATH.'wp-admin/includes/misc.php' ) ) {
                require_once ABSPATH.'wp-admin/includes/misc.php';
            }
        }
        if ( ! function_exists('insert_with_markers') ) return false;

        $htaccess = ABSPATH.'.htaccess';
        // Si no existe, intentamos crearlo vacío
        if ( ! file_exists($htaccess) ) {
            // Crear archivo vacío si la carpeta es escribible
            if ( ! is_writable(ABSPATH) ) return false;
            @file_put_contents($htaccess, "# Created by WordPress / EventosApp\n");
        }
        if ( ! is_writable($htaccess) ) return false;

        $ok = insert_with_markers($htaccess, 'EventosApp PKPASS', eventosapp_pkpass_rules_lines());
        if ($ok) {
            update_option('eventosapp_pkpass_htaccess_root_ok', time());
            evapp_err('PKPASS: reglas htaccess escritas en raíz (OK)');
        }
        return (bool) $ok;
    }
}

if (!function_exists('eventosapp_write_uploads_pkpass_htaccess')) {
    function eventosapp_write_uploads_pkpass_htaccess(){
        // Crea/asegura el directorio y un .htaccess local
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']).'eventosapp-pkpass/';
        if ( ! file_exists($dir) && ! wp_mkdir_p($dir) ) return false;

        $path = $dir.'.htaccess';
        $content = implode("\n", array_merge(
            ['# BEGIN EventosApp PKPASS (uploads)'],
            eventosapp_pkpass_rules_lines(),
            ['# END EventosApp PKPASS (uploads)']
        ))."\n";
        $w = @file_put_contents($path, $content);
        if ($w !== false) {
            update_option('eventosapp_pkpass_htaccess_uploads_ok', time());
            evapp_err('PKPASS: reglas htaccess escritas en uploads/eventosapp-pkpass (OK)');
            return true;
        }
        return false;
    }
}

if (!function_exists('eventosapp_ensure_pkpass_htaccess')) {
    function eventosapp_ensure_pkpass_htaccess(){
        // Evita correr en cada request: máx. 1 vez por día.
        $last = (int) get_option('eventosapp_pkpass_htaccess_checked', 0);
        if ($last && (time() - $last) < DAY_IN_SECONDS) return;
        update_option('eventosapp_pkpass_htaccess_checked', time());

        // 1) Probar raíz
        $root_ok = eventosapp_write_root_htaccess_rules();
        // 2) Fallback: subcarpeta de uploads (donde servimos .pkpass)
        if (!$root_ok) {
            $up_ok = eventosapp_write_uploads_pkpass_htaccess();
            if (!$up_ok) {
                evapp_err('PKPASS: no se pudo escribir .htaccess (ni raíz ni uploads).');
            }
        }
    }
}

// Corre al activar el plugin y también “auto-sana” en admin una vez al día
if (!function_exists('eventosapp_pkpass_activation')) {
    function eventosapp_pkpass_activation(){
        eventosapp_ensure_pkpass_htaccess();
    }
}
// ⚠️ Registra este activation hook desde el archivo principal del plugin, por ejemplo:
// register_activation_hook( __FILE__, 'eventosapp_pkpass_activation' );

// Como fallback en instalaciones existentes:
add_action('admin_init', 'eventosapp_ensure_pkpass_htaccess');

/** Permite subir .pkpass en la librería de medios (por si se usa) */
add_filter('upload_mimes', function($m){
    $m['pkpass'] = 'application/vnd.apple.pkpass';
    return $m;
});

// =======================================
// HOOK INIT: Fallback para servir .pkpass
// (anti-caché + ETag/Last-Modified)
// URL: https://tu-dominio/?evapp_pkpass=SERIAL
// =======================================
add_action('init', function(){
    if (!empty($_GET['evapp_pkpass'])) {
        $serial = sanitize_file_name($_GET['evapp_pkpass']);
        $uploads = wp_upload_dir();
        $path = trailingslashit($uploads['basedir'])."eventosapp-pkpass/{$serial}.pkpass";
        if (file_exists($path)) {
            // Metadatos para cache/condicional
            $mtime = @filemtime($path) ?: time();
            $etag  = '"' . (@md5_file($path) ?: md5($serial.$mtime)) . '"';
            $lm    = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

            // No-caché fuerte (rompe caches intermedias)
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');

            // Tipo/descarga (inline)
            header('Content-Type: application/vnd.apple.pkpass');
            header('Content-Disposition: inline; filename="'.basename($path).'"');
            header('Last-Modified: '.$lm);
            header('ETag: '.$etag);

            // Desactivar compresión que rompe Content-Length
            if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
            @ini_set('zlib.output_compression', 'Off');

            // Responder 304 si el cliente revalida y coincide
            $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
            $ifModSince  = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? trim($_SERVER['HTTP_IF_MODIFIED_SINCE']) : '';
            if ($ifNoneMatch === $etag || $ifModSince === $lm) {
                status_header(304);
                exit;
            }

            // Tamaño y cuerpo
            header('Content-Length: '.filesize($path));
            readfile($path);
            exit;
        }
        wp_die('Pass no encontrado', '404', ['response'=>404]);
    }
});


/* ============================================================
 * ================ HOOKS DE EVENTO (GOOGLE) ==================
 * ============================================================ 
 * 
 * FLUJO DE ACTUALIZACIÓN AUTOMÁTICA DE TICKETS:
 * 
 * 1. Al guardar un evento (save_post_eventosapp_event):
 *    a) eventosapp_autosync_wallet_class_on_save (prioridad 30)
 *       - Sincroniza/crea la clase en Google Wallet si está personalizada
 *    
 *    b) eventosapp_wallet_patch_objects_for_event (prioridad 40)
 *       - Detecta cambios usando eventosapp_wallet_build_signature()
 *       - Solo ejecuta si Wallet Android o Apple están activados
 *       - Si detecta cambios, crea un job de actualización por lotes
 * 
 * 2. Procesamiento por lotes (eventosapp_wallet_bulk_patch):
 *    - Procesa 50 tickets por ejecución (configurable)
 *    - Usa transient lock para evitar ejecuciones concurrentes
 *    - Programa siguiente ejecución con 10 segundos de delay
 *    - Actualiza Google Wallet y/o Apple Wallet según configuración
 * 
 * 3. Para cada ticket:
 *    - Google Wallet: verifica clase actual, actualiza o recrea si cambió
 *    - Apple Wallet: regenera el .pkpass con nueva información
 * 
 * PREVENCIÓN DE SOBRECARGA:
 * - Lotes de 50 tickets con 10 segundos entre lotes
 * - Lock de 60 segundos para evitar ejecuciones duplicadas
 * - Solo procesa si detecta cambios reales (firma MD5)
 * - Reintentos con 60 segundos en caso de fallo de autenticación
 */

add_action('save_post_eventosapp_event', 'eventosapp_autosync_wallet_class_on_save', 30);
function eventosapp_autosync_wallet_class_on_save($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (!isset($_POST['eventosapp_nonce']) || !wp_verify_nonce($_POST['eventosapp_nonce'], 'eventosapp_detalles_evento')) return;

    $wc_enable = get_post_meta($post_id, '_eventosapp_wallet_custom_enable', true) === '1';
    if (!$wc_enable) return;

    if (function_exists('eventosapp_sync_wallet_class')) {
        $sync = eventosapp_sync_wallet_class($post_id);
        if (is_array($sync)) {
            if (!empty($sync['class_id'])) {
                update_post_meta($post_id, '_eventosapp_wallet_class_id', $sync['class_id']);
                update_post_meta($post_id, '_eventosapp_wallet_class_id_resuelta', $sync['class_id']);
            }
            if (!empty($sync['logs'])) {
                update_post_meta($post_id, '_eventosapp_wallet_class_ultimo_log', implode("\n", (array)$sync['logs']));
            }
        }
    }
}

add_action('save_post_eventosapp_event', 'eventosapp_wallet_patch_objects_for_event', 40);
function eventosapp_wallet_patch_objects_for_event($evento_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($evento_id)) return;
    if (!isset($_POST['eventosapp_nonce']) || !wp_verify_nonce($_POST['eventosapp_nonce'], 'eventosapp_detalles_evento')) return;

    // Verificar si Wallet Android o Apple están activados para este evento
    $wallet_android_on = get_post_meta($evento_id, '_eventosapp_ticket_wallet_android', true) === '1';
    $wallet_apple_on   = get_post_meta($evento_id, '_eventosapp_ticket_wallet_apple', true) === '1';
    
    if (!$wallet_android_on && !$wallet_apple_on) {
        error_log("EVENTOSAPP WALLET BATCH skipped evento:$evento_id - Wallet no activado");
        return;
    }

    // Para Google Wallet, verificar issuer_id y obtener class_id actual
    $force_update = false;
    $reason = '';
    
    if ($wallet_android_on) {
        $issuer_id = get_option('eventosapp_wallet_issuer_id');
        if (!$issuer_id) {
            error_log("EVENTOSAPP WALLET BATCH skipped evento:$evento_id - Falta issuer_id");
            return;
        }

        // Obtener el class_id actual del evento (después de la sincronización en prioridad 30)
        $ctx = eventosapp_wallet_build_event_context($evento_id);
        $current_class_id = $ctx['class_id'] ?? '';
        
        if ($current_class_id) {
            // Obtener el class_id que se usó en la última sincronización de tickets
            $last_synced_class = get_post_meta($evento_id, '_eventosapp_wallet_last_synced_class', true);
            
            // Si el class_id cambió desde la última sincronización, forzar actualización
            if ($last_synced_class && $last_synced_class !== $current_class_id) {
                $force_update = true;
                $reason = "class_id cambió de '$last_synced_class' a '$current_class_id'";
                error_log("EVENTOSAPP WALLET BATCH evento:$evento_id - $reason - Forzando actualización de todos los tickets");
            }
            
            // Si no hay class_id previo guardado, guardarlo ahora (primera vez)
            if (!$last_synced_class) {
                update_post_meta($evento_id, '_eventosapp_wallet_last_synced_class', $current_class_id);
                error_log("EVENTOSAPP WALLET BATCH evento:$evento_id - Guardando class_id inicial: $current_class_id");
            }
        }
    }

    // Si no se forzó por cambio de clase, verificar cambios en otros campos
    if (!$force_update) {
        // Calcular firma actual basada en todos los campos que afectan a Wallet
        $current_sig = eventosapp_wallet_build_signature($evento_id);
        $prev_sig    = (string) get_post_meta($evento_id, '_eventosapp_wallet_signature', true);

        // Si la firma no ha cambiado, no hacer nada
        if ($prev_sig && hash_equals($prev_sig, $current_sig)) {
            error_log("EVENTOSAPP WALLET BATCH skipped evento:$evento_id - Sin cambios detectados");
            return;
        }
        
        $reason = "cambios detectados en campos de wallet";
    }

    // Guardar la nueva firma
    $current_sig = eventosapp_wallet_build_signature($evento_id);
    update_post_meta($evento_id, '_eventosapp_wallet_signature', $current_sig);
    
    // Si hay un class_id actual, actualizarlo como el último sincronizado
    if ($wallet_android_on && !empty($current_class_id)) {
        update_post_meta($evento_id, '_eventosapp_wallet_last_synced_class', $current_class_id);
    }
    
    // Crear el job de actualización por lotes
    $job = eventosapp_wallet_seed_batch_job($evento_id);
    
    error_log(sprintf(
        'EVENTOSAPP WALLET BATCH seed evento:%d total:%d per_page:%d page:%d (Android:%s Apple:%s) - Razón: %s',
        $evento_id, 
        $job['total'], 
        $job['per_page'], 
        $job['page'],
        $wallet_android_on ? 'ON' : 'OFF',
        $wallet_apple_on ? 'ON' : 'OFF',
        $reason
    ));
}

add_action('eventosapp_wallet_bulk_patch', function($args){
    $evento_id = is_array($args) ? (int)($args['evento_id'] ?? 0) : (int)$args;
    if (!$evento_id) return;

    $lock_key = 'evapp_wallet_job_lock_' . $evento_id;
    if ( get_transient($lock_key) ) return;
    set_transient($lock_key, 1, 60);

    $job = get_post_meta($evento_id, '_eventosapp_wallet_patch_job', true);
    if (!is_array($job) || empty($job['per_page']) || empty($job['page'])) {
        delete_transient($lock_key);
        return;
    }

    $per_page = (int) $job['per_page'];
    $page     = (int) $job['page'];

    // Verificar qué wallets están activados para este evento
    $wallet_android_on = get_post_meta($evento_id, '_eventosapp_ticket_wallet_android', true) === '1';
    $wallet_apple_on   = get_post_meta($evento_id, '_eventosapp_ticket_wallet_apple', true) === '1';

    // === GOOGLE WALLET ===
    $access_token = null;
    $ctx = null;
    
    if ($wallet_android_on) {
        if ( ! function_exists('eventosapp_google_wallet_get_access_token') ) {
            error_log("EVENTOSAPP WALLET BATCH evento:$evento_id page:$page - función eventosapp_google_wallet_get_access_token no existe");
            $wallet_android_on = false; // Desactivar para este batch
        } else {
            $auth = eventosapp_google_wallet_get_access_token();
            $access_token = $auth['token'] ?? null;
            if (!$access_token) {
                error_log("EVENTOSAPP WALLET BATCH evento:$evento_id page:$page - No se pudo obtener access_token, reintentando en 60s");
                eventosapp_wallet_schedule_next($evento_id, 60);
                delete_transient($lock_key);
                return;
            }

            $ctx = eventosapp_wallet_build_event_context($evento_id);
            if ( empty($ctx['issuer_id']) || empty($ctx['class_id']) ) {
                error_log("EVENTOSAPP WALLET BATCH evento:$evento_id page:$page - Falta issuer_id o class_id");
                delete_transient($lock_key);
                return;
            }
        }
    }

    // Obtener tickets del evento
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
        'no_found_rows'  => false,
    ]);

    if ( !$q->have_posts() ) {
        delete_post_meta($evento_id, '_eventosapp_wallet_patch_job');
        delete_transient($lock_key);
        error_log("EVENTOSAPP WALLET BATCH done evento:$evento_id - No hay tickets");
        return;
    }

    $success_android = 0;
    $success_apple = 0;
    $errors_android = 0;
    $errors_apple = 0;

    foreach ($q->posts as $ticket_id){
        // 1) Google Wallet (solo si está activado)
        if ($wallet_android_on && $access_token && $ctx) {
            try {
                $result = eventosapp_wallet_patch_single_ticket($evento_id, $ticket_id, $access_token, $ctx);
                if ($result) {
                    $success_android++;
                } else {
                    $errors_android++;
                }
            } catch (\Throwable $e) {
                $errors_android++;
                error_log("EVENTOSAPP GW PATCH EX ticket:$ticket_id -> ".$e->getMessage());
            }
        }

        // 2) Apple Wallet (solo si está activado)
        if ($wallet_apple_on) {
            try {
                $ok = eventosapp_apple_generate_pass($ticket_id);
                if ($ok) {
                    $success_apple++;
                } else {
                    $errors_apple++;
                    error_log("EVENTOSAPP APPLE regen FALLÓ ticket:$ticket_id");
                }
            } catch (\Throwable $e) {
                $errors_apple++;
                error_log("EVENTOSAPP APPLE regen EX ticket:$ticket_id -> ".$e->getMessage());
            }
        }
    }

    // Log de progreso del batch
    error_log(sprintf(
        'EVENTOSAPP WALLET BATCH evento:%d page:%d/%d processed:%d [Android: %d✓ %d✗] [Apple: %d✓ %d✗]',
        $evento_id,
        $page,
        (int)$q->max_num_pages,
        count($q->posts),
        $success_android,
        $errors_android,
        $success_apple,
        $errors_apple
    ));

    $max_pages = (int) $q->max_num_pages;
    $next_page = (int) $job['page'] + 1;

    if ($max_pages > 0 && $next_page <= $max_pages) {
        $job['page'] = $next_page;
        update_post_meta($evento_id, '_eventosapp_wallet_patch_job', $job);
        eventosapp_wallet_schedule_next($evento_id, 10);
    } else {
        delete_post_meta($evento_id, '_eventosapp_wallet_patch_job');
        error_log("EVENTOSAPP WALLET BATCH done evento:$evento_id - Completado exitosamente");
    }

    delete_transient($lock_key);
}, 10, 1);


add_action('save_post_eventosapp_ticket', 'eventosapp_apple_regen_on_ticket_save', 40);
function eventosapp_apple_regen_on_ticket_save($post_id){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'eventosapp_ticket') return;

    try {
        $ok = eventosapp_apple_generate_pass($post_id);
        if (!$ok) {
            error_log("EVENTOSAPP APPLE on-save regen FALLÓ ticket:$post_id");
        } else {
            error_log("EVENTOSAPP APPLE on-save regen OK ticket:$post_id");
        }
    } catch (\Throwable $e) {
        error_log("EVENTOSAPP APPLE on-save EX ticket:$post_id -> ".$e->getMessage());
    }
}

/* ============================================================
 * ============== ADMIN: BOTÓN Y CONTROL DE BATCH =============
 * ============================================================ */

/**
 * Filtro global para permitir cambiar el tamaño de lote sin tocar código.
 * Lee la opción 'eventosapp_wallet_batch_size' (10, 25, 50, 100).
 * No requiere cambiar la firma de eventosapp_wallet_get_batch_size().
 */
add_filter('eventosapp_wallet_patch_batch_size', function($default){
    $n = (int) get_option('eventosapp_wallet_batch_size', 50);
    if ($n !== 10 && $n !== 25 && $n !== 50 && $n !== 100) {
        $n = 50;
    }
    return $n;
});

/**
 * Metabox en la pantalla de edición del CPT eventosapp_event:
 * - Permite disparar el batch forzado (repara tickets viejos con clase incorrecta)
 * - Permite elegir "tickets por lote" para no sobrecargar el servidor
 * - Muestra el estado del job si está en curso
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'eventosapp_wallet_batch_tools',
        'Acciones de Wallet (batch)',
        'eventosapp_wallet_render_batch_tools_metabox',
        'eventosapp_event',
        'side',
        'high'
    );
});

function eventosapp_wallet_render_batch_tools_metabox($post){
    if ($post->post_type !== 'eventosapp_event') return;

    // Estado del job
    $job = get_post_meta($post->ID, '_eventosapp_wallet_patch_job', true);
    $batch_size = (int) get_option('eventosapp_wallet_batch_size', 50);
    if (!in_array($batch_size, [10,25,50,100], true)) $batch_size = 50;

    // Wallets activos
    $android_on = get_post_meta($post->ID, '_eventosapp_ticket_wallet_android', true) === '1';
    $apple_on   = get_post_meta($post->ID, '_eventosapp_ticket_wallet_apple', true) === '1';

    // Nonce para disparo vía GET (evita anidar formularios en la pantalla de edición)
    $nonce = wp_create_nonce('eventosapp_wallet_force_batch_'.$post->ID);
    $admin_post = admin_url('admin-post.php');
    ?>
    <div style="font-size:12px;line-height:1.45">
        <p><strong>Wallet activado:</strong><br>
            Android: <span style="color:<?php echo $android_on?'#008000':'#a00'; ?>;"><?php echo $android_on?'Sí':'No'; ?></span> ·
            Apple: <span style="color:<?php echo $apple_on?'#008000':'#a00'; ?>;"><?php echo $apple_on?'Sí':'No'; ?></span>
        </p>

        <?php if (is_array($job) && !empty($job['job_id'])): ?>
            <div style="background:#f6f7f7;border:1px solid #ccd0d4;padding:8px;border-radius:4px;margin-bottom:8px">
                <strong>Job en curso</strong><br>
                ID: <code><?php echo esc_html($job['job_id']); ?></code><br>
                Página: <?php echo (int)($job['page'] ?? 1); ?> ·
                Lote: <?php echo (int)($job['per_page'] ?? $batch_size); ?> ·
                Total estimado: <?php echo (int)($job['total'] ?? 0); ?>
            </div>
        <?php else: ?>
            <p style="margin:8px 0;">No hay job activo.</p>
        <?php endif; ?>

        <label for="evapp_batch_size"><strong>Tickets por lote</strong></label><br>
        <select id="evapp_batch_size" style="width:100%;margin:4px 0 8px;">
            <option value="10"  <?php selected($batch_size,10); ?>>10 (muy seguro)</option>
            <option value="25"  <?php selected($batch_size,25); ?>>25 (seguro)</option>
            <option value="50"  <?php selected($batch_size,50); ?>>50 (recomendado)</option>
            <option value="100" <?php selected($batch_size,100); ?>>100 (rápido, más carga)</option>
        </select>

        <p class="description" style="margin:4px 0 10px;">
            El proceso corre por lotes con espera entre páginas y lock anti-concurrencia.
        </p>

        <!-- Botón que lanza admin-post.php por GET con nonce: evita formularios anidados -->
        <button type="button" id="evapp-start-batch" class="button button-primary" style="width:100%;">
            Iniciar actualización por lotes ahora
        </button>

        <script>
        (function(){
            var btn   = document.getElementById('evapp-start-batch');
            var sel   = document.getElementById('evapp_batch_size');
            var base  = <?php echo wp_json_encode($admin_post); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var evento= <?php echo (int) $post->ID; ?>;

            btn.addEventListener('click', function(e){
                e.preventDefault();
                var size = sel ? sel.value : '50';
                var url  = base + '?action=eventosapp_wallet_force_batch'
                                  + '&evento_id=' + encodeURIComponent(evento)
                                  + '&batch_size=' + encodeURIComponent(size)
                                  + '&_wpnonce=' + encodeURIComponent(nonce);
                window.location.href = url;
            });
        })();
        </script>
    </div>
    <?php
}


/**
 * Handler admin-post: guarda el batch size y dispara el job forzado.
 * Ahora acepta GET o POST (usa $_REQUEST) para evitar formularios anidados en el metabox.
 */
add_action('admin_post_eventosapp_wallet_force_batch', 'eventosapp_wallet_force_batch_handler');
function eventosapp_wallet_force_batch_handler(){
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes', 403);
    }

    // Acepta GET o POST
    $evento_id = isset($_REQUEST['evento_id']) ? (int) $_REQUEST['evento_id'] : 0;

    // Nonce por evento (acepta _wpnonce de GET o POST)
    $nonce_ok  = isset($_REQUEST['_wpnonce'])
        && wp_verify_nonce($_REQUEST['_wpnonce'], 'eventosapp_wallet_force_batch_'.$evento_id);

    if (!$evento_id || !$nonce_ok) {
        wp_die('Solicitud no válida', 400);
    }

    // batch_size (acepta GET o POST)
    $batch_size = isset($_REQUEST['batch_size']) ? (int) $_REQUEST['batch_size'] : 50;
    if (!in_array($batch_size, [10,25,50,100], true)) $batch_size = 50;
    update_option('eventosapp_wallet_batch_size', $batch_size, false);

    // Lanza el seed (forzado) — esto elimina firma/clase previa para asegurar re-procesamiento
    $res = eventosapp_wallet_force_update_tickets($evento_id, true);

    // Redirige de vuelta al editor del evento con query var de estado
    $url = add_query_arg([
        'post'        => $evento_id,
        'action'      => 'edit',
        'wallet_batch'=> $res['ok'] ? 'started' : 'error',
        'wallet_msg'  => $res['ok'] ? rawurlencode($res['message'] ?? 'Batch iniciado') : rawurlencode($res['error'] ?? 'Error al iniciar'),
    ], admin_url('post.php'));

    wp_safe_redirect($url);
    exit;
}


/**
 * Admin notice tras iniciar el batch desde el metabox.
 */
add_action('admin_notices', function(){
    if (!is_admin() || !isset($_GET['wallet_batch'])) return;
    $status = sanitize_text_field($_GET['wallet_batch']);
    $msg    = isset($_GET['wallet_msg']) ? esc_html($_GET['wallet_msg']) : '';

    if ($status === 'started') {
        echo '<div class="notice notice-success is-dismissible"><p><strong>EventosApp:</strong> '.$msg.'</p></div>';
    } elseif ($status === 'error') {
        echo '<div class="notice notice-error is-dismissible"><p><strong>EventosApp:</strong> '.$msg.'</p></div>';
    }
});



/* ============================================================
 * =============== FUNCIÓN AUXILIAR PARA ADMIN ================
 * ============================================================ */

/**
 * Fuerza la actualización de todos los tickets de un evento.
 * Útil para regenerar tickets manualmente desde admin o después de cambios.
 * 
 * @param int $evento_id ID del evento
 * @param bool $force Si true, fuerza la actualización incluso sin cambios detectados
 * @return array Información del job creado
 */
function eventosapp_wallet_force_update_tickets($evento_id, $force = false) {
    if (!$evento_id) {
        return ['ok' => false, 'error' => 'ID de evento no válido'];
    }

    // Verificar que el evento existe
    $post = get_post($evento_id);
    if (!$post || $post->post_type !== 'eventosapp_event') {
        return ['ok' => false, 'error' => 'El evento no existe'];
    }

    // Verificar si hay un job en curso
    $current_job = get_post_meta($evento_id, '_eventosapp_wallet_patch_job', true);
    if (is_array($current_job) && !empty($current_job['job_id'])) {
        return [
            'ok' => false, 
            'error' => 'Ya hay una actualización en curso para este evento',
            'job' => $current_job
        ];
    }

    // Si force=true, actualizar la firma para forzar detección de cambios
    if ($force) {
        delete_post_meta($evento_id, '_eventosapp_wallet_signature');
        delete_post_meta($evento_id, '_eventosapp_wallet_last_synced_class');
        error_log("EVENTOSAPP WALLET FORCE UPDATE evento:$evento_id - Firma y class_id eliminados para forzar actualización");
    }

    // Calcular firma actual
    $current_sig = eventosapp_wallet_build_signature($evento_id);
    $prev_sig = (string) get_post_meta($evento_id, '_eventosapp_wallet_signature', true);

    // Si no hay cambios y no es forzado, no hacer nada
    if (!$force && $prev_sig && hash_equals($prev_sig, $current_sig)) {
        return [
            'ok' => false,
            'error' => 'No hay cambios detectados. Use $force=true para forzar actualización'
        ];
    }

    // Guardar nueva firma
    update_post_meta($evento_id, '_eventosapp_wallet_signature', $current_sig);
    
    // Actualizar el class_id sincronizado si Google Wallet está activado
    $wallet_android_on = get_post_meta($evento_id, '_eventosapp_ticket_wallet_android', true) === '1';
    if ($wallet_android_on) {
        $ctx = eventosapp_wallet_build_event_context($evento_id);
        if (!empty($ctx['class_id'])) {
            update_post_meta($evento_id, '_eventosapp_wallet_last_synced_class', $ctx['class_id']);
        }
    }

    // Crear job
    $job = eventosapp_wallet_seed_batch_job($evento_id);

    error_log(sprintf(
        'EVENTOSAPP WALLET FORCE UPDATE evento:%d total:%d per_page:%d (forced:%s)',
        $evento_id,
        $job['total'],
        $job['per_page'],
        $force ? 'YES' : 'NO'
    ));

    return [
        'ok' => true,
        'job' => $job,
        'message' => "Actualización iniciada para {$job['total']} tickets"
    ];
}
