<?php
/**
 * EventosApp – QR Check-In (frontend)
 * Shortcode: [eventosapp_qr_checkin]
 * - Requiere sesión iniciada y evento activo (usa eventosapp_require_active_event()).
 * - Limita el check-in al evento activo del usuario.
 * - Usa BarcodeDetector (rápido) con fallback a jsQR.
 * - Muestra marco/guía, botón de cámara y ficha del asistente.
 */

if ( ! defined('ABSPATH') ) exit;

//
// === Permisos: quién puede usar el lector ===
//
if ( ! function_exists('eventosapp_current_user_can_checkin') ) {
    function eventosapp_current_user_can_checkin() {
        if ( ! is_user_logged_in() ) return false;
        $u = wp_get_current_user();
        $roles = (array) $u->roles;
        $allowed = ['administrator','organizador','staff','logistico'];
        return (bool) array_intersect($allowed, $roles);
    }
}


/**
 * Helpers de rendimiento para búsquedas QR.
 * Evitan traer todos los candidatos por meta y luego filtrarlos en PHP.
 */
if ( ! function_exists('eventosapp_qr_checkin_debug_log') ) {
    function eventosapp_qr_checkin_debug_log($message, $context = []) {
        if ( ! defined('WP_DEBUG') || ! WP_DEBUG ) {
            return;
        }
        $suffix = '';
        if ( ! empty($context) ) {
            $suffix = ' | ' . (function_exists('wp_json_encode') ? wp_json_encode($context) : json_encode($context));
        }
        error_log('EVENTOSAPP QR CHECKIN | ' . $message . $suffix);
    }
}

if ( ! function_exists('eventosapp_qr_find_ticket_by_scanned_code') ) {
    function eventosapp_qr_find_ticket_by_scanned_code($scanned, $event_id, $use_preprinted = null) {
        global $wpdb;

        $scanned  = sanitize_text_field((string) $scanned);
        $event_id = absint($event_id);

        $empty = [
            'found'      => false,
            'ticket_id'  => 0,
            'type'       => 'unknown',
            'type_label' => 'QR Estándar',
            'error'      => '',
        ];

        if ( $scanned === '' || ! $event_id ) {
            $empty['error'] = 'Datos incompletos';
            return $empty;
        }

        $cache_key = 'evapp_qr_lookup_' . md5($event_id . '|' . $scanned . '|' . (is_null($use_preprinted) ? 'auto' : (string)(int)(bool)$use_preprinted));
        $cached = wp_cache_get($cache_key, 'eventosapp_qr');
        if ( is_array($cached) ) {
            return $cached;
        }

        if ( class_exists('EventosApp_QR_Manager') ) {
            $validation = EventosApp_QR_Manager::validate_qr($scanned);
            if ( isset($validation['valid']) && $validation['valid'] === true && ! empty($validation['ticket_id']) ) {
                $candidate_id = absint($validation['ticket_id']);
                $ticket_event = absint(get_post_meta($candidate_id, '_eventosapp_ticket_evento_id', true));

                if ( $ticket_event === $event_id ) {
                    $result = [
                        'found'      => true,
                        'ticket_id'  => $candidate_id,
                        'type'       => sanitize_key((string)($validation['type'] ?? 'qr_manager')),
                        'type_label' => sanitize_text_field((string)($validation['type_label'] ?? ($validation['type'] ?? 'QR'))),
                        'error'      => '',
                    ];
                    wp_cache_set($cache_key, $result, 'eventosapp_qr', 60);
                    return $result;
                }

                $empty['error'] = 'El QR no corresponde a este evento';
                wp_cache_set($cache_key, $empty, 'eventosapp_qr', 30);
                return $empty;
            }
        }

        if ( is_null($use_preprinted) ) {
            $use_preprinted = (get_post_meta($event_id, '_eventosapp_ticket_use_preprinted_qr', true) === '1');
        } else {
            $use_preprinted = (bool) $use_preprinted;
        }

        $meta_key = $use_preprinted ? 'eventosapp_ticket_preprintedID' : 'eventosapp_ticketID';
        if ( $use_preprinted ) {
            $scan_val = preg_replace('/\D+/', '', $scanned);
            if ( $scan_val === '' ) {
                $empty['error'] = 'QR inválido: se esperaba un número.';
                wp_cache_set($cache_key, $empty, 'eventosapp_qr', 30);
                return $empty;
            }
        } else {
            $scan_val = $scanned;
        }

        $ticket_id = $wpdb->get_var($wpdb->prepare(
            "SELECT code_pm.post_id
               FROM {$wpdb->postmeta} code_pm
               INNER JOIN {$wpdb->postmeta} event_pm
                       ON event_pm.post_id = code_pm.post_id
                      AND event_pm.meta_key = %s
                      AND event_pm.meta_value = %s
               INNER JOIN {$wpdb->posts} p
                       ON p.ID = code_pm.post_id
                      AND p.post_type = 'eventosapp_ticket'
                      AND p.post_status NOT IN ('trash','auto-draft','inherit')
              WHERE code_pm.meta_key = %s
                AND code_pm.meta_value = %s
              LIMIT 1",
            '_eventosapp_ticket_evento_id',
            (string) $event_id,
            $meta_key,
            $scan_val
        ));

        if ( $ticket_id ) {
            $result = [
                'found'      => true,
                'ticket_id'  => absint($ticket_id),
                'type'       => 'legacy',
                'type_label' => $use_preprinted ? 'QR Preimpreso' : 'QR Legacy',
                'error'      => '',
            ];
            wp_cache_set($cache_key, $result, 'eventosapp_qr', 60);
            return $result;
        }

        $empty['error'] = 'Ticket no encontrado para este evento.';
        wp_cache_set($cache_key, $empty, 'eventosapp_qr', 30);
        return $empty;
    }
}

if ( ! function_exists('eventosapp_qr_checkin_event_datetime') ) {
    function eventosapp_qr_checkin_event_datetime($event_id) {
        $event_tz = get_post_meta((int)$event_id, '_eventosapp_zona_horaria', true);
        if (!$event_tz) {
            $event_tz = wp_timezone_string();
            if (!$event_tz || $event_tz === 'UTC') {
                $offset = get_option('gmt_offset');
                $event_tz = $offset ? timezone_name_from_abbr('', $offset * 3600, 0) ?: 'UTC' : 'UTC';
            }
        }
        try {
            return new DateTime('now', new DateTimeZone($event_tz));
        } catch (Exception $e) {
            return new DateTime('now', wp_timezone());
        }
    }
}

if ( ! function_exists('eventosapp_qr_checkin_validate_event_day') ) {
    function eventosapp_qr_checkin_validate_event_day($event_id) {
        $dt = eventosapp_qr_checkin_event_datetime((int)$event_id);
        $today = $dt->format('Y-m-d');
        $days = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days((int)$event_id) : [];

        if (empty($days) || !in_array($today, $days, true)) {
            return [
                'valid' => false,
                'today' => $today,
                'error' => 'El check-in solo está permitido en las fechas del evento. Hoy no corresponde.',
            ];
        }

        return [
            'valid' => true,
            'today' => $today,
            'datetime' => $dt,
        ];
    }
}

// ============================================================================
// SHORTCODE PRINCIPAL: CHECK-IN QR
// ============================================================================
add_shortcode('eventosapp_qr_checkin', function($atts){
    if ( function_exists('eventosapp_require_feature') ) {
        eventosapp_require_feature('qr');
    }

    $active_event = function_exists('eventosapp_get_active_event') ? eventosapp_get_active_event() : 0;
    if ( ! $active_event ) {
        ob_start();
        if (function_exists('eventosapp_require_active_event')) {
            eventosapp_require_active_event();
        } else {
            echo '<p>Debes seleccionar un evento activo.</p>';
        }
        return ob_get_clean();
    }

    add_action('wp_enqueue_scripts', function(){
        if (!wp_script_is('jsqr', 'registered')) {
            wp_register_script('jsqr', 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js', [], null, true);
        }
    });

    $nonce           = wp_create_nonce('eventosapp_qr_checkin');
    $companion_nonce = wp_create_nonce('eventosapp_registrar_acompanantes');
    $dashboard_url   = function_exists('eventosapp_get_dashboard_url')
        ? eventosapp_get_dashboard_url()
        : home_url('/');
    $event_name      = get_the_title($active_event);
    $day_validation  = eventosapp_qr_checkin_validate_event_day($active_event);
    $day_is_valid    = !empty($day_validation['valid']);
    $today_label     = !empty($day_validation['today'])
        ? date_i18n('D, d M Y', strtotime($day_validation['today']))
        : '';

    ob_start(); ?>
    <style>
    .evapp-qr-checkin-app {
      --evapp-primary:#3279bd;
      --evapp-primary-dark:#255f96;
      --evapp-primary-soft:#eaf4ff;
      --evapp-app-bg:#f5f8fc;
      --evapp-surface:#ffffff;
      --evapp-border:#dfe7f1;
      --evapp-text:#182230;
      --evapp-muted:#64748b;
      --evapp-success:#16855b;
      --evapp-success-soft:#ecfdf5;
      --evapp-warning:#a16207;
      --evapp-warning-soft:#fff8e6;
      --evapp-danger:#c53a3a;
      --evapp-danger-soft:#fff1f1;
      --evapp-radius:18px;
      --evapp-radius-lg:26px;
      width:100%; max-width:1180px; margin:0 auto; color:var(--evapp-text);
      font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
      box-sizing:border-box;
    }
    .evapp-qr-checkin-app *, .evapp-qr-checkin-app *::before, .evapp-qr-checkin-app *::after { box-sizing:border-box; }
    .evapp-qr-checkin-app .evqr-shell { width:100%; padding:clamp(18px,3vw,36px); background:var(--evapp-app-bg); border:1px solid var(--evapp-border); border-radius:var(--evapp-radius-lg); box-shadow:0 18px 50px rgba(31,65,99,.08); }
    .evapp-qr-checkin-app .evqr-header { display:flex; align-items:flex-start; justify-content:space-between; gap:24px; margin-bottom:22px; }
    .evapp-qr-checkin-app .evqr-heading { min-width:0; }
    .evapp-qr-checkin-app .evqr-eyebrow { margin:0 0 7px; color:var(--evapp-primary); font-size:12px; font-weight:800; letter-spacing:.15em; text-transform:uppercase; }
    .evapp-qr-checkin-app .evqr-title { margin:0; color:var(--evapp-text); font-size:clamp(27px,4vw,42px); line-height:1.08; letter-spacing:-.035em; font-weight:800; }
    .evapp-qr-checkin-app .evqr-subtitle { max-width:720px; margin:10px 0 0; color:var(--evapp-muted); font-size:15px; line-height:1.6; }
    .evapp-qr-checkin-app .evqr-btn { min-height:44px; display:inline-flex; align-items:center; justify-content:center; gap:9px; border:1px solid transparent; border-radius:12px; padding:10px 15px; font:inherit; font-size:14px; font-weight:750; line-height:1.1; text-decoration:none!important; cursor:pointer; transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease,background .16s ease,color .16s ease; -webkit-tap-highlight-color:transparent; }
    .evapp-qr-checkin-app .evqr-btn svg { width:18px; height:18px; flex:0 0 18px; fill:none; stroke:currentColor; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
    .evapp-qr-checkin-app .evqr-btn:hover { transform:translateY(-1px); text-decoration:none!important; }
    .evapp-qr-checkin-app .evqr-btn:focus-visible { outline:3px solid rgba(50,121,189,.22); outline-offset:2px; }
    .evapp-qr-checkin-app .evqr-btn-secondary { background:var(--evapp-surface); border-color:var(--evapp-border); color:var(--evapp-text); box-shadow:0 5px 15px rgba(31,65,99,.05); white-space:nowrap; }
    .evapp-qr-checkin-app .evqr-btn-secondary:hover { border-color:#c7d7e8; color:var(--evapp-primary-dark); box-shadow:0 8px 20px rgba(31,65,99,.09); }
    .evapp-qr-checkin-app .evqr-event-context { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:22px; padding:16px 18px; background:var(--evapp-surface); border:1px solid var(--evapp-border); border-radius:var(--evapp-radius); box-shadow:0 8px 24px rgba(31,65,99,.045); }
    .evapp-qr-checkin-app .evqr-event-main { min-width:0; display:flex; align-items:center; gap:13px; }
    .evapp-qr-checkin-app .evqr-event-icon { width:44px; height:44px; flex:0 0 44px; display:grid; place-items:center; color:var(--evapp-primary); background:var(--evapp-primary-soft); border-radius:13px; }
    .evapp-qr-checkin-app .evqr-event-icon svg { width:22px; height:22px; fill:none; stroke:currentColor; stroke-width:1.9; stroke-linecap:round; stroke-linejoin:round; }
    .evapp-qr-checkin-app .evqr-event-copy { min-width:0; }
    .evapp-qr-checkin-app .evqr-event-kicker { display:block; margin-bottom:3px; color:var(--evapp-muted); font-size:11px; font-weight:800; letter-spacing:.09em; text-transform:uppercase; }
    .evapp-qr-checkin-app .evqr-event-name { display:block; overflow:hidden; color:var(--evapp-text); font-size:15px; font-weight:800; line-height:1.3; text-overflow:ellipsis; white-space:nowrap; }
    .evapp-qr-checkin-app .evqr-event-meta { display:flex; align-items:center; justify-content:flex-end; flex-wrap:wrap; gap:8px; }
    .evapp-qr-checkin-app .evqr-chip { min-height:30px; display:inline-flex; align-items:center; gap:7px; padding:6px 10px; border:1px solid var(--evapp-border); border-radius:999px; background:#fff; color:var(--evapp-muted); font-size:12px; font-weight:750; white-space:nowrap; }
    .evapp-qr-checkin-app .evqr-chip::before { width:7px; height:7px; border-radius:50%; background:#94a3b8; content:""; }
    .evapp-qr-checkin-app .evqr-chip.is-valid { color:var(--evapp-success); border-color:#cfeadf; background:var(--evapp-success-soft); }
    .evapp-qr-checkin-app .evqr-chip.is-valid::before { background:var(--evapp-success); }
    .evapp-qr-checkin-app .evqr-chip.is-warning { color:var(--evapp-warning); border-color:#f1dfad; background:var(--evapp-warning-soft); }
    .evapp-qr-checkin-app .evqr-chip.is-warning::before { background:#d69e2e; }
    .evapp-qr-checkin-app .evqr-layout { display:grid; grid-template-columns:minmax(0,1.18fr) minmax(320px,.82fr); gap:20px; align-items:start; }
    .evapp-qr-checkin-app .evqr-panel { min-width:0; background:var(--evapp-surface); border:1px solid var(--evapp-border); border-radius:var(--evapp-radius); box-shadow:0 8px 26px rgba(31,65,99,.05); }
    .evapp-qr-checkin-app .evqr-scanner-panel { padding:18px; }
    .evapp-qr-checkin-app .evqr-result-panel { position:sticky; top:18px; padding:18px; }
    body.admin-bar .evapp-qr-checkin-app .evqr-result-panel { top:50px; }
    .evapp-qr-checkin-app .evqr-panel-head { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; margin-bottom:15px; }
    .evapp-qr-checkin-app .evqr-panel-title { margin:0; color:var(--evapp-text); font-size:17px; font-weight:800; line-height:1.3; }
    .evapp-qr-checkin-app .evqr-panel-desc { margin:5px 0 0; color:var(--evapp-muted); font-size:13px; line-height:1.5; }
    .evapp-qr-checkin-app .evqr-camera-state { min-height:30px; display:inline-flex; align-items:center; gap:7px; flex:0 0 auto; padding:6px 10px; border:1px solid var(--evapp-border); border-radius:999px; background:#f8fafc; color:var(--evapp-muted); font-size:12px; font-weight:800; white-space:nowrap; }
    .evapp-qr-checkin-app .evqr-camera-state::before { width:7px; height:7px; border-radius:50%; background:#94a3b8; content:""; }
    .evapp-qr-checkin-app .evqr-camera-state.is-live { color:var(--evapp-success); background:var(--evapp-success-soft); border-color:#cfeadf; }
    .evapp-qr-checkin-app .evqr-camera-state.is-live::before { background:var(--evapp-success); box-shadow:0 0 0 4px rgba(22,133,91,.10); }
    .evapp-qr-checkin-app .evqr-camera-state.is-busy { color:var(--evapp-primary-dark); background:var(--evapp-primary-soft); border-color:#cfe3f6; }
    .evapp-qr-checkin-app .evqr-camera-state.is-busy::before { background:var(--evapp-primary); animation:evqrPulse 1s ease-in-out infinite; }
    .evapp-qr-checkin-app .evapp-qr-btn, .evapp-qr-checkin-app .evapp-qr-btn-secondary, .evapp-qr-checkin-app .evapp-payment-reminder-btn, .evapp-qr-checkin-app .evapp-acomp-btn { width:100%; min-height:48px; display:flex; align-items:center; justify-content:center; gap:9px; margin:0; border:1px solid transparent; border-radius:12px; padding:12px 16px; background:var(--evapp-primary); color:#fff; font:inherit; font-size:14px; font-weight:800; line-height:1.2; cursor:pointer; box-shadow:0 9px 20px rgba(50,121,189,.18); transition:transform .16s ease,box-shadow .16s ease,background .16s ease,opacity .16s ease; -webkit-tap-highlight-color:transparent; }
    .evapp-qr-checkin-app .evapp-qr-btn:hover:not(:disabled), .evapp-qr-checkin-app .evapp-qr-btn-secondary:hover:not(:disabled), .evapp-qr-checkin-app .evapp-payment-reminder-btn:hover:not(:disabled), .evapp-qr-checkin-app .evapp-acomp-btn:hover:not(:disabled) { transform:translateY(-1px); background:var(--evapp-primary-dark); box-shadow:0 12px 24px rgba(50,121,189,.24); }
    .evapp-qr-checkin-app .evapp-qr-btn:focus-visible, .evapp-qr-checkin-app .evapp-qr-btn-secondary:focus-visible, .evapp-qr-checkin-app .evapp-payment-reminder-btn:focus-visible, .evapp-qr-checkin-app .evapp-acomp-btn:focus-visible { outline:3px solid rgba(50,121,189,.22); outline-offset:2px; }
    .evapp-qr-checkin-app .evapp-qr-btn:disabled, .evapp-qr-checkin-app .evapp-qr-btn-secondary:disabled, .evapp-qr-checkin-app .evapp-payment-reminder-btn:disabled, .evapp-qr-checkin-app .evapp-acomp-btn:disabled { opacity:.56; cursor:not-allowed; transform:none; box-shadow:none; }
    .evapp-qr-checkin-app .evapp-qr-btn.is-live { background:var(--evapp-danger); box-shadow:0 9px 20px rgba(197,58,58,.17); }
    .evapp-qr-checkin-app .evapp-qr-btn.is-live:hover { background:#a92f2f; }
    .evapp-qr-checkin-app .evapp-qr-btn svg, .evapp-qr-checkin-app .evapp-payment-reminder-btn svg { width:19px; height:19px; flex:0 0 19px; }
    .evapp-qr-checkin-app .evapp-qr-btn-secondary { margin-top:14px; background:#fff; border-color:var(--evapp-border); color:var(--evapp-primary-dark); box-shadow:none; }
    .evapp-qr-checkin-app .evapp-qr-btn-secondary:hover:not(:disabled) { background:var(--evapp-primary-soft); border-color:#c4dbee; box-shadow:none; }
    .evapp-qr-checkin-app .evapp-qr-video-wrap { position:relative; width:100%; margin-top:14px; overflow:hidden; aspect-ratio:4/3; min-height:300px; background:radial-gradient(circle at 50% 50%,rgba(50,121,189,.12),transparent 55%),#101827; border:1px solid #d6e1ec; border-radius:16px; box-shadow:inset 0 0 0 1px rgba(255,255,255,.03); }
    .evapp-qr-checkin-app .evapp-qr-video { width:100%; height:100%; display:none; object-fit:cover; background:#0f172a; }
    .evapp-qr-checkin-app .evqr-camera-placeholder { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; flex-direction:column; gap:10px; padding:24px; color:#c8d3df; text-align:center; pointer-events:none; }
    .evapp-qr-checkin-app .evqr-camera-placeholder svg { width:42px; height:42px; fill:none; stroke:#6f8ca8; stroke-width:1.6; stroke-linecap:round; stroke-linejoin:round; }
    .evapp-qr-checkin-app .evqr-camera-placeholder strong { color:#f8fafc; font-size:14px; }
    .evapp-qr-checkin-app .evqr-camera-placeholder span { max-width:300px; color:#94a3b8; font-size:12px; line-height:1.5; }
    .evapp-qr-checkin-app .evapp-qr-video-wrap.has-camera .evqr-camera-placeholder { display:none; }
    .evapp-qr-checkin-app .evapp-qr-frame { position:absolute; inset:0; display:none; pointer-events:none; }
    .evapp-qr-checkin-app .evapp-qr-frame .mask { position:absolute; inset:0; background:radial-gradient(ellipse 54% 54% at 50% 50%,rgba(0,0,0,0) 58%,rgba(7,14,24,.48) 61%); }
    .evapp-qr-checkin-app .evapp-qr-corner { position:absolute; width:56px; height:56px; border:4px solid #6fb8f4; border-radius:11px; filter:drop-shadow(0 2px 5px rgba(0,0,0,.26)); }
    .evapp-qr-checkin-app .evapp-qr-corner.tl { top:20px;left:20px;border-right:0;border-bottom:0; }
    .evapp-qr-checkin-app .evapp-qr-corner.tr { top:20px;right:20px;border-left:0;border-bottom:0; }
    .evapp-qr-checkin-app .evapp-qr-corner.bl { bottom:20px;left:20px;border-right:0;border-top:0; }
    .evapp-qr-checkin-app .evapp-qr-corner.br { bottom:20px;right:20px;border-left:0;border-top:0; }
    .evapp-qr-checkin-app .evapp-qr-video-wrap.is-immersive { height:min(calc(100vh - var(--evapp-offset,72px)),760px); height:min(calc(100dvh - var(--evapp-offset,72px)),760px); min-height:420px; aspect-ratio:auto; }
    .evapp-qr-checkin-app .evqr-scan-guide { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; margin-top:12px; }
    .evapp-qr-checkin-app .evqr-guide-item { display:flex; align-items:center; gap:7px; min-width:0; padding:9px 10px; border:1px solid var(--evapp-border); border-radius:11px; background:#fbfdff; color:var(--evapp-muted); font-size:11px; font-weight:700; line-height:1.35; }
    .evapp-qr-checkin-app .evqr-guide-item svg { width:15px; height:15px; flex:0 0 15px; fill:none; stroke:var(--evapp-primary); stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
    .evapp-qr-checkin-app .evapp-qr-result { min-height:190px; margin:0; padding:0; background:transparent; border:0; border-radius:0; color:var(--evapp-text); }
    .evapp-qr-checkin-app .evapp-qr-help { display:flex; align-items:flex-start; gap:10px; margin:0; padding:14px; border:1px solid #dbe7f2; border-radius:13px; background:#f8fbfe; color:var(--evapp-muted); font-size:13px; line-height:1.55; }
    .evapp-qr-checkin-app .evapp-qr-help::before { width:20px; height:20px; flex:0 0 20px; display:grid; place-items:center; border-radius:50%; background:var(--evapp-primary-soft); color:var(--evapp-primary); content:"i"; font-size:12px; font-weight:900; }
    .evapp-qr-checkin-app .evqr-state { display:flex; align-items:flex-start; gap:12px; padding:15px; border:1px solid var(--evapp-border); border-radius:14px; background:#fff; }
    .evapp-qr-checkin-app .evqr-state-icon { width:38px; height:38px; flex:0 0 38px; display:grid; place-items:center; border-radius:12px; font-size:18px; font-weight:900; }
    .evapp-qr-checkin-app .evqr-state-copy { min-width:0; }
    .evapp-qr-checkin-app .evqr-state-title { margin:0; color:var(--evapp-text); font-size:15px; font-weight:850; line-height:1.35; }
    .evapp-qr-checkin-app .evqr-state-text { margin:4px 0 0; color:var(--evapp-muted); font-size:13px; line-height:1.5; overflow-wrap:anywhere; }
    .evapp-qr-checkin-app .evqr-state.is-success { border-color:#cde8dc; background:var(--evapp-success-soft); }
    .evapp-qr-checkin-app .evqr-state.is-success .evqr-state-icon { background:#d7f5e7; color:var(--evapp-success); }
    .evapp-qr-checkin-app .evqr-state.is-warning { border-color:#efdda7; background:var(--evapp-warning-soft); }
    .evapp-qr-checkin-app .evqr-state.is-warning .evqr-state-icon { background:#ffedbd; color:var(--evapp-warning); }
    .evapp-qr-checkin-app .evqr-state.is-danger { border-color:#f0caca; background:var(--evapp-danger-soft); }
    .evapp-qr-checkin-app .evqr-state.is-danger .evqr-state-icon { background:#ffdede; color:var(--evapp-danger); }
    .evapp-qr-checkin-app .evqr-state.is-info { border-color:#cfe3f6; background:var(--evapp-primary-soft); }
    .evapp-qr-checkin-app .evqr-state.is-info .evqr-state-icon { background:#d7eafc; color:var(--evapp-primary); }
    .evapp-qr-checkin-app .evapp-qr-ok, .evapp-qr-checkin-app .evapp-qr-warn, .evapp-qr-checkin-app .evapp-qr-bad { display:block; font-weight:850; }
    .evapp-qr-checkin-app .evapp-qr-ok { color:var(--evapp-success); }
    .evapp-qr-checkin-app .evapp-qr-warn { color:var(--evapp-warning); }
    .evapp-qr-checkin-app .evapp-qr-bad { color:var(--evapp-danger); }
    .evapp-qr-checkin-app .evqr-payment-note { margin-top:12px; padding:11px 12px; border:1px solid #cde8dc; border-radius:12px; background:var(--evapp-success-soft); color:var(--evapp-success); font-size:13px; font-weight:750; line-height:1.45; }
    .evapp-qr-checkin-app .evapp-qr-grid { display:grid; grid-template-columns:minmax(105px,.42fr) minmax(0,1fr); gap:0; margin-top:14px; overflow:hidden; border:1px solid var(--evapp-border); border-radius:14px; background:#fff; }
    .evapp-qr-checkin-app .evapp-qr-grid > div { min-width:0; padding:10px 12px; border-bottom:1px solid #edf2f7; color:var(--evapp-text); font-size:13px; line-height:1.4; overflow-wrap:anywhere; }
    .evapp-qr-checkin-app .evapp-qr-grid > div:nth-last-child(-n+2) { border-bottom:0; }
    .evapp-qr-checkin-app .evapp-qr-grid > div:nth-child(odd) { background:#f8fafc; color:var(--evapp-muted); }
    .evapp-qr-checkin-app .evapp-qr-grid b { color:inherit; font-weight:800; }
    .evapp-qr-checkin-app .evqr-qr-type { display:inline-flex; align-items:center; gap:6px; padding:4px 8px; border-radius:999px; background:var(--evapp-primary-soft); color:var(--evapp-primary-dark); font-size:11px; font-weight:850; line-height:1.25; }
    .evapp-qr-checkin-app .evapp-payment-reminder-btn { margin-top:14px; }
    .evapp-qr-checkin-app .evapp-payment-reminder-btn.is-success { background:var(--evapp-success)!important; box-shadow:0 9px 20px rgba(22,133,91,.17); }
    .evapp-qr-checkin-app .evqr-inline-status { margin-top:10px; padding:10px 11px; border-radius:10px; font-size:12px; font-weight:700; line-height:1.45; overflow-wrap:anywhere; }
    .evapp-qr-checkin-app .evqr-inline-status.is-info { color:var(--evapp-primary-dark); background:var(--evapp-primary-soft); }
    .evapp-qr-checkin-app .evqr-inline-status.is-success { color:var(--evapp-success); background:var(--evapp-success-soft); }
    .evapp-qr-checkin-app .evqr-inline-status.is-danger { color:var(--evapp-danger); background:var(--evapp-danger-soft); }
    .evapp-qr-checkin-app .evapp-acomp-panel { margin-top:14px; padding:14px; border:1px solid #cfe3f6; border-radius:14px; background:#f7fbff; }
    .evapp-qr-checkin-app .evapp-acomp-label { margin:0 0 10px; color:var(--evapp-text); font-size:13px; font-weight:850; }
    .evapp-qr-checkin-app .evapp-acomp-row { display:grid; grid-template-columns:100px minmax(0,1fr); gap:9px; align-items:stretch; }
    .evapp-qr-checkin-app .evapp-acomp-input { width:100%; min-height:48px; border:1px solid #cfdbe7; border-radius:12px; background:#fff; color:var(--evapp-text); font:inherit; font-size:17px; font-weight:800; text-align:center; outline:0; -moz-appearance:textfield; }
    .evapp-qr-checkin-app .evapp-acomp-input:focus { border-color:var(--evapp-primary); box-shadow:0 0 0 3px rgba(50,121,189,.12); }
    .evapp-qr-checkin-app .evapp-acomp-input::-webkit-inner-spin-button, .evapp-qr-checkin-app .evapp-acomp-input::-webkit-outer-spin-button { margin:0; -webkit-appearance:none; }
    .evapp-qr-checkin-app .evapp-acomp-status { margin-top:9px; color:var(--evapp-muted); font-size:12px; line-height:1.5; }
    @keyframes evqrSpin { to { transform:rotate(360deg); } }
    @keyframes evqrPulse { 0%,100%{opacity:.45} 50%{opacity:1} }
    @media (max-width:900px) {
      .evapp-qr-checkin-app .evqr-layout { grid-template-columns:1fr; }
      .evapp-qr-checkin-app .evqr-result-panel { position:static; top:auto; }
      .evapp-qr-checkin-app .evapp-qr-video-wrap { aspect-ratio:16/11; }
    }
    @media (max-width:680px) {
      .evapp-qr-checkin-app .evqr-shell { padding:16px; border-radius:20px; }
      .evapp-qr-checkin-app .evqr-header { flex-direction:column; gap:15px; }
      .evapp-qr-checkin-app .evqr-header-actions, .evapp-qr-checkin-app .evqr-header-actions .evqr-btn { width:100%; }
      .evapp-qr-checkin-app .evqr-event-context { align-items:flex-start; flex-direction:column; }
      .evapp-qr-checkin-app .evqr-event-meta { width:100%; justify-content:flex-start; }
      .evapp-qr-checkin-app .evqr-panel-head { flex-direction:column; }
      .evapp-qr-checkin-app .evqr-camera-state { align-self:flex-start; }
      .evapp-qr-checkin-app .evqr-scanner-panel, .evapp-qr-checkin-app .evqr-result-panel { padding:14px; }
      .evapp-qr-checkin-app .evapp-qr-video-wrap { min-height:360px; aspect-ratio:3/4; border-radius:14px; }
      .evapp-qr-checkin-app .evapp-qr-video-wrap.is-immersive { height:calc(100vh - var(--evapp-offset,62px)); height:calc(100dvh - var(--evapp-offset,62px)); min-height:420px; max-height:none; }
      .evapp-qr-checkin-app .evqr-scan-guide { grid-template-columns:1fr; }
      .evapp-qr-checkin-app .evapp-qr-grid { grid-template-columns:1fr; }
      .evapp-qr-checkin-app .evapp-qr-grid > div { border-bottom:0; }
      .evapp-qr-checkin-app .evapp-qr-grid > div:nth-child(odd) { padding-bottom:4px; font-size:11px; text-transform:uppercase; letter-spacing:.055em; }
      .evapp-qr-checkin-app .evapp-qr-grid > div:nth-child(even) { padding-top:4px; padding-bottom:11px; border-bottom:1px solid #edf2f7; }
      .evapp-qr-checkin-app .evapp-qr-grid > div:last-child { border-bottom:0; }
    }
    @media (max-width:420px) {
      .evapp-qr-checkin-app .evqr-shell { padding:13px; }
      .evapp-qr-checkin-app .evqr-title { font-size:28px; }
      .evapp-qr-checkin-app .evqr-event-main { align-items:flex-start; }
      .evapp-qr-checkin-app .evqr-event-name { white-space:normal; }
      .evapp-qr-checkin-app .evapp-acomp-row { grid-template-columns:1fr; }
      .evapp-qr-checkin-app .evapp-acomp-input { min-height:46px; }
    }
    @media (prefers-reduced-motion:reduce) {
      .evapp-qr-checkin-app *, .evapp-qr-checkin-app *::before, .evapp-qr-checkin-app *::after { scroll-behavior:auto!important; transition:none!important; animation-duration:.001ms!important; animation-iteration-count:1!important; }
    }
    </style>

    <div class="evapp-qr-shell evapp-qr-checkin-app" data-event="<?php echo esc_attr($active_event); ?>" data-event-name="<?php echo esc_attr($event_name); ?>">
      <div class="evqr-shell">
        <header class="evqr-header">
          <div class="evqr-heading">
            <p class="evqr-eyebrow">EVENTOSAPP</p>
            <h1 class="evqr-title">Check-In con QR</h1>
            <p class="evqr-subtitle">Escanea el QR del ticket, escarapela, Wallet o canal habilitado y registra el ingreso del asistente en el evento activo.</p>
          </div>
          <div class="evqr-header-actions">
            <a href="<?php echo esc_url($dashboard_url); ?>" class="evqr-btn evqr-btn-secondary" aria-label="Volver al dashboard">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg>
              <span>Volver al dashboard</span>
            </a>
          </div>
        </header>

        <section class="evqr-event-context" aria-label="Evento activo">
          <div class="evqr-event-main">
            <div class="evqr-event-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2"></rect><path d="M8 3v4M16 3v4M4 10h16"></path></svg></div>
            <div class="evqr-event-copy"><span class="evqr-event-kicker">Evento activo</span><span class="evqr-event-name"><?php echo esc_html($event_name); ?></span></div>
          </div>
          <div class="evqr-event-meta">
            <?php if ($today_label): ?><span class="evqr-chip <?php echo $day_is_valid ? 'is-valid' : 'is-warning'; ?>"><?php echo esc_html($today_label); ?></span><?php endif; ?>
            <span class="evqr-chip <?php echo $day_is_valid ? 'is-valid' : 'is-warning'; ?>"><?php echo $day_is_valid ? 'Check-in habilitado hoy' : 'Fuera de fecha del evento'; ?></span>
          </div>
        </section>

        <div class="evqr-layout">
          <section class="evqr-panel evqr-scanner-panel" aria-label="Lector de código QR">
            <div class="evqr-panel-head">
              <div><h2 class="evqr-panel-title">Lector QR</h2><p class="evqr-panel-desc">Usa preferiblemente la cámara trasera y mantén el código centrado.</p></div>
              <div class="evqr-camera-state" id="evqrCameraState" aria-live="polite">Cámara inactiva</div>
            </div>
            <button class="evapp-qr-btn" id="evappStartScan" type="button">
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><rect x="7" y="7" width="10" height="10" rx="2" stroke="currentColor" stroke-width="2"/></svg>
              <span>Activar cámara y escanear</span>
            </button>
            <div class="evapp-qr-video-wrap" id="evqrVideoWrap">
              <div class="evqr-camera-placeholder" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 7h4l2-2h6l2 2h4v12H3z"></path><circle cx="12" cy="13" r="4"></circle></svg><strong>La cámara está apagada</strong><span>Al activarla, el navegador puede solicitar permiso para utilizar la cámara.</span></div>
              <video id="evappVideo" class="evapp-qr-video" playsinline muted></video>
              <div class="evapp-qr-frame" id="evappFrame" aria-hidden="true"><div class="mask"></div><div class="evapp-qr-corner tl"></div><div class="evapp-qr-corner tr"></div><div class="evapp-qr-corner bl"></div><div class="evapp-qr-corner br"></div></div>
              <canvas id="evappCanvas" style="display:none;"></canvas>
            </div>
            <div class="evqr-scan-guide" aria-label="Consejos de lectura">
              <div class="evqr-guide-item"><svg viewBox="0 0 24 24"><path d="M12 3v18M3 12h18"></path></svg>Centra el QR</div>
              <div class="evqr-guide-item"><svg viewBox="0 0 24 24"><path d="M12 2v4M12 18v4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M2 12h4M18 12h4M4.9 19.1l2.8-2.8M16.3 7.7l2.8-2.8"></path></svg>Evita reflejos</div>
              <div class="evqr-guide-item"><svg viewBox="0 0 24 24"><path d="M5 12h14M9 8l-4 4 4 4"></path></svg>Acerca si no lee</div>
            </div>
          </section>
          <section class="evqr-panel evqr-result-panel" aria-label="Resultado del check-in">
            <div class="evqr-panel-head"><div><h2 class="evqr-panel-title">Resultado del escaneo</h2><p class="evqr-panel-desc">Aquí verás la validación y los datos del asistente.</p></div></div>
            <div class="evapp-qr-result" id="evappResult" aria-live="polite" aria-atomic="false"><div class="evapp-qr-help">Activa la cámara y coloca el QR dentro del marco. La lectura vibra o emite una señal al capturar.</div></div>
          </section>
        </div>
      </div>
    </div>

<script>
(function(){
  const wrap = document.querySelector('.evapp-qr-checkin-app');
  if (!wrap || wrap.dataset.evqrReady === '1') return;
  wrap.dataset.evqrReady = '1';
  const ajaxURL = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
  const ajaxNonce = "<?php echo esc_js( $nonce ); ?>";
  const companionNonce = "<?php echo esc_js( $companion_nonce ); ?>";
  const eventID = parseInt(wrap.dataset.event || '0', 10) || 0;
  const btn=wrap.querySelector('#evappStartScan'),video=wrap.querySelector('#evappVideo'),frame=wrap.querySelector('#evappFrame'),cvs=wrap.querySelector('#evappCanvas'),out=wrap.querySelector('#evappResult'),vwrap=wrap.querySelector('#evqrVideoWrap'),cameraState=wrap.querySelector('#evqrCameraState');
  const ctx=cvs?cvs.getContext('2d',{willReadFrequently:true}):null;
  if(!btn||!video||!frame||!cvs||!ctx||!out||!vwrap)return;
  let stream=null,running=false,rafId=0,lastScan="",lastAt=0,lastDetectionAt=0,jsQrPromise=null;
  const DETECTION_INTERVAL=110,MAX_SCAN_WIDTH=960;
  let barcodeDetector=null;try{if('BarcodeDetector'in window)barcodeDetector=new window.BarcodeDetector({formats:['qr_code']});}catch(e){barcodeDetector=null;}
  function escapeHtml(value){return String(value??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
  function safeValue(value,fallback='-'){const text=String(value??'').trim();return text?escapeHtml(text):fallback;}
  function getOffsetCompensation(){const adminBar=document.getElementById('wpadminbar');return(adminBar?adminBar.offsetHeight:0)+12;}
  function smoothScrollTo(el){if(!el)return;const offset=getOffsetCompensation();try{el.style.setProperty('--evapp-offset',offset+'px');}catch(e){}const y=el.getBoundingClientRect().top+window.pageYOffset-offset;window.scrollTo({top:y,behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});}
  function setCameraState(label,state){if(!cameraState)return;cameraState.textContent=label;cameraState.classList.remove('is-live','is-busy');if(state==='live')cameraState.classList.add('is-live');if(state==='busy')cameraState.classList.add('is-busy');}
  function setLiveUI(on){if(on){btn.classList.add('is-live');btn.innerHTML='<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6h12v12H6z" stroke="currentColor" stroke-width="2"/></svg><span>Detener cámara</span>';setCameraState('Cámara activa','live');}else{btn.classList.remove('is-live');btn.innerHTML='<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><rect x="7" y="7" width="10" height="10" rx="2" stroke="currentColor" stroke-width="2"/></svg><span>Activar cámara y escanear</span>';setCameraState('Cámara inactiva','');}}
  function beep(){try{const a=new Audio();a.src='data:audio/mp3;base64,//uQxAAAAAAAAAAAAAAAAAAAAAAAWGlinZwAAAA8AAAACAAACcQAA';a.play().catch(()=>{});}catch(e){}if(navigator.vibrate)navigator.vibrate(60);}
  function setOutput(html){out.innerHTML=html;}
  function renderState(type,title,text){const icon=type==='success'?'✓':(type==='warning'?'!':(type==='danger'?'×':'i'));return `<div class="evqr-state is-${type}"><div class="evqr-state-icon" aria-hidden="true">${icon}</div><div class="evqr-state-copy"><p class="evqr-state-title">${escapeHtml(title)}</p>${text?`<p class="evqr-state-text">${escapeHtml(text)}</p>`:''}</div></div>`;}
  function row(label,value,rawValue=false){const rendered=rawValue?value:safeValue(value);return `<div><b>${escapeHtml(label)}:</b></div><div>${rendered||'-'}</div>`;}
  function normalizeRaw(raw){let s=String(raw||'').trim();if(s.startsWith('http://')||s.startsWith('https://'))return s;if(s.includes('/'))s=s.split('/').pop();return s.replace(/\.(png|jpg|jpeg|pdf)$/i,'').replace(/-tn$/i,'').replace(/^#/,'');}
  function configureCanvas(){const sourceW=video.videoWidth||1280,sourceH=video.videoHeight||720,scale=Math.min(1,MAX_SCAN_WIDTH/sourceW);cvs.width=Math.max(320,Math.round(sourceW*scale));cvs.height=Math.max(240,Math.round(sourceH*scale));}
  function stop(){running=false;if(rafId){cancelAnimationFrame(rafId);rafId=0;}if(stream)stream.getTracks().forEach(track=>track.stop());stream=null;try{video.pause();}catch(e){}video.srcObject=null;video.style.display='none';frame.style.display='none';vwrap.classList.remove('is-immersive','has-camera');setLiveUI(false);}
  async function ensureJsQR(){if(barcodeDetector)return false;if(window.jsQR)return true;if(jsQrPromise)return jsQrPromise;jsQrPromise=new Promise((resolve,reject)=>{const existing=document.querySelector('script[data-evapp-jsqr="1"]');if(existing){if(window.jsQR){resolve(true);return;}existing.addEventListener('load',()=>resolve(true),{once:true});existing.addEventListener('error',()=>reject(new Error('No fue posible cargar el lector QR alterno.')),{once:true});return;}const s=document.createElement('script');s.src=(window.jsqr_src||'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js');s.async=true;s.dataset.evappJsqr='1';s.onload=()=>resolve(true);s.onerror=()=>reject(new Error('No fue posible cargar el lector QR alterno.'));document.head.appendChild(s);}).catch(err=>{jsQrPromise=null;throw err;});return jsQrPromise;}
  function cameraErrorMessage(err){const name=err&&err.name?String(err.name):'';if(name==='NotAllowedError'||name==='PermissionDeniedError')return 'El navegador no tiene permiso para usar la cámara. Habilita el permiso de cámara para este sitio y vuelve a intentar.';if(name==='NotFoundError'||name==='DevicesNotFoundError')return 'No se encontró una cámara disponible en este dispositivo.';if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia)return 'Este navegador no permite acceder a la cámara desde esta página. Verifica que el sitio use HTTPS y que el navegador esté actualizado.';return 'No se pudo acceder a la cámara. Revisa los permisos del navegador y vuelve a intentar.';}
  async function start(){if(!eventID){setOutput(renderState('danger','No hay evento activo','Regresa al dashboard y selecciona el evento que vas a gestionar.'));smoothScrollTo(out);return false;}if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){setOutput(renderState('danger','Cámara no disponible','El navegador no ofrece acceso a la cámara en este contexto. Verifica HTTPS y permisos.'));smoothScrollTo(out);return false;}try{stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'},width:{ideal:1280},height:{ideal:720}},audio:false});}catch(e){setOutput(renderState('danger','No se pudo activar la cámara',cameraErrorMessage(e)));smoothScrollTo(out);return false;}try{video.srcObject=stream;await video.play();}catch(e){stop();setOutput(renderState('danger','No se pudo iniciar el visor','El navegador bloqueó la reproducción de la cámara. Vuelve a intentar.'));smoothScrollTo(out);return false;}configureCanvas();video.style.display='block';frame.style.display='block';vwrap.classList.add('has-camera','is-immersive');smoothScrollTo(vwrap);running=true;lastDetectionAt=0;setLiveUI(true);rafId=requestAnimationFrame(tick);return true;}
  async function tick(timestamp){if(!running)return;if((timestamp-lastDetectionAt)<DETECTION_INTERVAL){rafId=requestAnimationFrame(tick);return;}lastDetectionAt=timestamp;try{ctx.drawImage(video,0,0,cvs.width,cvs.height);if(barcodeDetector){let bitmap=null;try{bitmap=await createImageBitmap(cvs);const codes=await barcodeDetector.detect(bitmap);if(codes&&codes.length&&running){const data=normalizeRaw(codes[0].rawValue||'');if(data){onScan(data);return;}}}catch(e){}finally{if(bitmap&&typeof bitmap.close==='function')bitmap.close();}}else if(window.jsQR){const img=ctx.getImageData(0,0,cvs.width,cvs.height),code=window.jsQR(img.data,img.width,img.height);if(code&&code.data&&running){const data=normalizeRaw(code.data);if(data){onScan(data);return;}}}}catch(e){}if(running)rafId=requestAnimationFrame(tick);}
  function injectScanAgainButton(){const old=out.querySelector('#evappScanAnother');if(old)old.remove();const againBtn=document.createElement('button');againBtn.id='evappScanAnother';againBtn.type='button';againBtn.className='evapp-qr-btn-secondary';againBtn.innerHTML='<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 11a8 8 0 10-2.34 5.66M20 5v6h-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Escanear otro QR</span>';out.appendChild(againBtn);againBtn.addEventListener('click',async()=>{againBtn.disabled=true;try{await ensureJsQR();const started=await start();if(started)setOutput('<div class="evapp-qr-help">Cámara activa. Centra el QR dentro del marco y evita reflejos.</div>');}catch(e){setOutput(renderState('danger','No se pudo preparar el lector',e&&e.message?e.message:'Vuelve a intentar.'));injectScanAgainButton();}});}
  function injectAcompanantesPanel(ticketId){if(!ticketId||out.querySelector('.evapp-acomp-panel'))return;const panel=document.createElement('div');panel.className='evapp-acomp-panel';panel.innerHTML='<div class="evapp-acomp-label">Acompañantes sin QR</div><div class="evapp-acomp-row"><input type="number" inputmode="numeric" class="evapp-acomp-input" id="evappAcompInput" min="0" max="500" step="1" value="0" aria-label="Cantidad de acompañantes"><button type="button" class="evapp-acomp-btn" id="evappAcompBtn">Registrar acompañantes</button></div><div class="evapp-acomp-status" id="evappAcompStatus" aria-live="polite"></div>';out.appendChild(panel);const inputEl=panel.querySelector('#evappAcompInput'),statusEl=panel.querySelector('#evappAcompStatus'),actionBtn=panel.querySelector('#evappAcompBtn');actionBtn.addEventListener('click',function(){const cantidad=parseInt(inputEl.value,10);if(isNaN(cantidad)||cantidad<0||cantidad>500){statusEl.textContent='Ingresa un número válido entre 0 y 500.';statusEl.style.color='var(--evapp-danger)';return;}actionBtn.disabled=true;actionBtn.textContent='Guardando…';statusEl.textContent='Registrando acompañantes…';statusEl.style.color='';const fd=new FormData();fd.append('action','eventosapp_registrar_acompanantes');fd.append('companion_nonce',companionNonce);fd.append('ticket_id',String(ticketId));fd.append('cantidad',String(cantidad));fetch(ajaxURL,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(resp=>{if(resp&&resp.success){const total=(resp.data&&typeof resp.data.total!=='undefined')?resp.data.total:cantidad;statusEl.textContent=cantidad+' acompañante(s) registrado(s). Total acumulado: '+total;statusEl.style.color='var(--evapp-success)';actionBtn.textContent='✓ Guardado';inputEl.value=0;setTimeout(()=>{actionBtn.disabled=false;actionBtn.textContent='Registrar acompañantes';},2500);}else{const msg=(resp&&resp.data&&resp.data.message)?resp.data.message:'Error al guardar.';statusEl.textContent=msg;statusEl.style.color='var(--evapp-danger)';actionBtn.disabled=false;actionBtn.textContent='Reintentar';}}).catch(()=>{statusEl.textContent='Error de conexión al registrar acompañantes.';statusEl.style.color='var(--evapp-danger)';actionBtn.disabled=false;actionBtn.textContent='Reintentar';});});}
  function attachPaymentReminder(ticketId){const reminderBtn=out.querySelector('#evappSendPaymentReminder');if(reminderBtn)reminderBtn.addEventListener('click',()=>sendPaymentReminder(ticketId));}
  function onScan(data){const now=Date.now();if(data===lastScan&&(now-lastAt)<2500){if(running)rafId=requestAnimationFrame(tick);return;}lastScan=data;lastAt=now;beep();stop();setCameraState('Procesando','busy');setOutput(renderState('info','Validando QR','Estamos verificando el ticket y registrando el ingreso.'));smoothScrollTo(out);const fd=new FormData();fd.append('action','eventosapp_qr_checkin_toggle');fd.append('security',ajaxNonce);fd.append('event_id',String(eventID));fd.append('scanned',data);fetch(ajaxURL,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(resp=>{setCameraState('Cámara inactiva','');if(!resp||!resp.success){const msg=(resp&&resp.data&&resp.data.error)?resp.data.error:'Error desconocido';if(resp&&resp.data&&resp.data.payment_required===true){const ticketId=parseInt(resp.data.ticket_id||'0',10)||0;let html=renderState('danger','Check-in bloqueado',msg);if(ticketId>0)html+='<button class="evapp-payment-reminder-btn" id="evappSendPaymentReminder" data-ticket-id="'+ticketId+'" type="button"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Enviar enlace de pago por correo</span></button><div id="evappPaymentReminderStatus" aria-live="polite"></div>';setOutput(html);if(ticketId>0)attachPaymentReminder(ticketId);}else setOutput(renderState('danger','No se pudo completar el check-in',msg));injectScanAgainButton();smoothScrollTo(out);return;}const d=resp.data||{};let html=d.already?renderState('warning','Ticket ya registrado','El asistente ya tenía check-in confirmado para esta fecha.'):renderState('success','Check-in confirmado','El ingreso del asistente quedó registrado correctamente.');if(d.payment_message)html+='<div class="evqr-payment-note">'+safeValue(d.payment_message,'')+'</div>';const qrType='<span class="evqr-qr-type">'+safeValue(d.qr_type_label||'QR Estándar')+'</span>';html+='<div class="evapp-qr-grid">'+row('Nombre',d.full_name)+row('Evento',d.event_name)+row('Fecha del check-in',d.checkin_date_label||d.checkin_date)+row('Tipo de QR',qrType,true)+row('Empresa',d.company)+row('Cargo',d.designation)+row('Localidad',d.localidad)+'</div>';setOutput(html);if(d.acompanantes_enabled&&!d.already)injectAcompanantesPanel(parseInt(d.ticket_id||'0',10)||0);injectScanAgainButton();smoothScrollTo(out);}).catch(()=>{setCameraState('Cámara inactiva','');setOutput(renderState('danger','Error de conexión','No se pudo verificar el ticket. Comprueba la conexión e intenta nuevamente.'));injectScanAgainButton();smoothScrollTo(out);});}
  btn.addEventListener('click',async()=>{if(stream&&stream.active){stop();setOutput('<div class="evapp-qr-help">Cámara detenida. Puedes volver a activarla cuando estés listo para el siguiente escaneo.</div>');return;}btn.disabled=true;setCameraState('Preparando cámara','busy');try{await ensureJsQR();const started=await start();if(started)setOutput('<div class="evapp-qr-help">Cámara activa. Centra el QR dentro del marco; la lectura se detendrá automáticamente al detectar un código.</div>');}catch(e){setCameraState('Cámara inactiva','');setOutput(renderState('danger','No se pudo preparar el lector',e&&e.message?e.message:'Vuelve a intentar.'));smoothScrollTo(out);}finally{btn.disabled=false;}});
  function sendPaymentReminder(ticketId){const reminderBtn=out.querySelector('#evappSendPaymentReminder'),statusDiv=out.querySelector('#evappPaymentReminderStatus');if(!ticketId||ticketId<=0){if(statusDiv){statusDiv.className='evqr-inline-status is-danger';statusDiv.textContent='Error: ID de ticket inválido.';}return;}if(reminderBtn){reminderBtn.disabled=true;reminderBtn.innerHTML='<svg viewBox="0 0 24 24" fill="none" aria-hidden="true" style="animation:evqrSpin .9s linear infinite;"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".25"></circle><path d="M12 3a9 9 0 019 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path></svg><span>Enviando correo…</span>';}if(statusDiv){statusDiv.className='evqr-inline-status is-info';statusDiv.textContent='Enviando recordatorio de pago…';}const fd=new FormData();fd.append('action','eventosapp_send_payment_reminder');fd.append('security',ajaxNonce);fd.append('ticket_id',String(ticketId));fetch(ajaxURL,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(resp=>{if(resp&&resp.success){if(statusDiv){statusDiv.className='evqr-inline-status is-success';statusDiv.innerHTML='Correo enviado correctamente a <strong>'+safeValue(resp.data&&resp.data.email?resp.data.email:'','-')+'</strong>.';}if(reminderBtn){reminderBtn.classList.add('is-success');reminderBtn.innerHTML='<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Correo enviado</span>';}return;}const errorMsg=(resp&&resp.data&&resp.data.message)?resp.data.message:'Error al enviar el correo.';if(statusDiv){statusDiv.className='evqr-inline-status is-danger';statusDiv.textContent=errorMsg;}if(reminderBtn){reminderBtn.disabled=false;reminderBtn.innerHTML='<span>Reintentar envío</span>';}}).catch(()=>{if(statusDiv){statusDiv.className='evqr-inline-status is-danger';statusDiv.textContent='Error de conexión al enviar el correo.';}if(reminderBtn){reminderBtn.disabled=false;reminderBtn.innerHTML='<span>Reintentar envío</span>';}});}
  window.addEventListener('pagehide',stop,{passive:true});
})();
</script>
    <?php
    return ob_get_clean();
});

add_action('wp_ajax_eventosapp_qr_checkin_toggle', function(){
// 🔒 Seguridad por rol (feature: qr)
if ( ! is_user_logged_in() ) {
    wp_send_json_error(['error' => 'Debes iniciar sesión.'], 401);
}
if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('qr') ) {
    wp_send_json_error(['error' => 'Permisos insuficientes para Check-In con QR.'], 403);
}
    check_ajax_referer('eventosapp_qr_checkin','security');

    $scanned  = isset($_POST['scanned'])  ? sanitize_text_field( wp_unslash($_POST['scanned']) ) : '';
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;

    // 🔒 Forzar evento ACTIVO para no-admins
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $event_id !== $active ) {
            wp_send_json_error(['error' => 'Sin permisos para este evento.'], 403);
        }
    }

    if ( ! $scanned || ! $event_id ) wp_send_json_error(['error'=>'Datos incompletos']);

    $lookup = eventosapp_qr_find_ticket_by_scanned_code($scanned, $event_id);
    if ( empty($lookup['found']) || empty($lookup['ticket_id']) ) {
        eventosapp_qr_checkin_debug_log('Ticket no encontrado para QR', [
            'event_id' => $event_id,
            'reason'   => $lookup['error'] ?? '',
        ]);
        wp_send_json_error(['error' => !empty($lookup['error']) ? $lookup['error'] : 'Ticket no encontrado']);
    }

    $ticket_post_id = absint($lookup['ticket_id']);
    $qr_type        = sanitize_key((string)($lookup['type'] ?? 'unknown'));
    $qr_type_label  = sanitize_text_field((string)($lookup['type_label'] ?? 'QR Estándar'));
    eventosapp_qr_checkin_debug_log('QR resuelto', [
        'event_id'  => $event_id,
        'ticket_id' => $ticket_post_id,
        'qr_type'   => $qr_type,
    ]);

    // Seguridad extra
    $ticket_event = (int) get_post_meta($ticket_post_id, '_eventosapp_ticket_evento_id', true);
    if ( $ticket_event !== (int) $event_id ) {
        wp_send_json_error(['error'=>'El ticket no pertenece al evento activo']);
    }

    // ============================================================================
    // NUEVO: VALIDACIÓN DE CONTROL DE PAGO
    // ============================================================================
    // Verificar si el evento tiene activado el control de pago
    $control_pago_activo = (get_post_meta($event_id, '_eventosapp_ticket_control_pago', true) === '1');
    
    // Variable para el mensaje de estado de pago
    $mensaje_pago = '';
    
    if ($control_pago_activo) {
        // Obtener el estado de pago del ticket
        $estado_pago = get_post_meta($ticket_post_id, '_eventosapp_estado_pago', true);
        
        // Si no tiene estado de pago definido, considerar como 'no_pagado'
        if (empty($estado_pago)) {
            $estado_pago = 'no_pagado';
        }
        
        // Si el ticket NO está pagado, rechazar el check-in
        if ($estado_pago === 'no_pagado') {
            error_log("EventosApp Check-in: Check-in rechazado - Ticket ID $ticket_post_id no está pagado");
            wp_send_json_error([
                'error' => '❌ El Check-In no se puede realizar debido a que el ticket no ha sido pagado. Por favor, realice el pago correspondiente para proceder.',
                'payment_required' => true,
                'ticket_id' => $ticket_post_id
            ]);
        }
        
        // Si está pagado, preparar mensaje para incluir en la respuesta
        $mensaje_pago = '💳 El ticket está en modo Pagado';
        error_log("EventosApp Check-in: Ticket ID $ticket_post_id verificado como PAGADO");
    }
    // ============================================================================
    // FIN VALIDACIÓN DE CONTROL DE PAGO
    // ============================================================================

    // ===== Reglas de fecha =====
    // 1) Obtener zona horaria del evento (o la del sitio)
    $event_tz = get_post_meta($event_id, '_eventosapp_zona_horaria', true);
    if (!$event_tz) {
        $event_tz = wp_timezone_string();
        if (!$event_tz || $event_tz === 'UTC') {
            $offset = get_option('gmt_offset');
            $event_tz = $offset ? timezone_name_from_abbr('', $offset * 3600, 0) ?: 'UTC' : 'UTC';
        }
    }

    // 2) "Hoy" en la TZ del evento
    try {
        $dt = new DateTime('now', new DateTimeZone($event_tz));
    } catch (Exception $e) {
        $dt = new DateTime('now', wp_timezone());
    }
    $today = $dt->format('Y-m-d');

    // 3) Días válidos del evento
    $days = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days($event_id) : [];

    // 4) Si hoy NO es un día del evento => bloquear
    if (empty($days) || !in_array($today, $days, true)) {
        wp_send_json_error(['error' => 'El check-in solo está permitido en las fechas del evento. Hoy no corresponde.']);
    }

    // 5) Estado multidía
    $status_arr = get_post_meta($ticket_post_id, '_eventosapp_checkin_status', true);
    if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
    if (!is_array($status_arr)) $status_arr = [];

    $already = (isset($status_arr[$today]) && $status_arr[$today] === 'checked_in');

    if (!$already) {
        $status_arr[$today] = 'checked_in';
        update_post_meta($ticket_post_id, '_eventosapp_checkin_status', $status_arr);

        // Log - MODIFICADO: Agregar información del tipo de QR
        $log = get_post_meta($ticket_post_id, '_eventosapp_checkin_log', true);
        if (is_string($log)) $log = @unserialize($log);
        if (!is_array($log)) $log = [];

        $user = wp_get_current_user();
        $log[] = [
            'fecha'   => $dt->format('Y-m-d'),
            'hora'    => $dt->format('H:i:s'),
            'dia'     => $today,
            'status'  => 'checked_in',
            'usuario' => $user && $user->exists() ? ($user->display_name.' ('.$user->user_email.')') : 'Sistema',
            'qr_type' => $qr_type,              // NUEVO: Tipo de QR usado
            'qr_type_label' => $qr_type_label   // NUEVO: Label legible del tipo
        ];
        update_post_meta($ticket_post_id, '_eventosapp_checkin_log', $log);
        
        // NUEVO: Actualizar estadísticas de uso de QR por tipo
        eventosapp_update_qr_usage_stats($event_id, $qr_type);
    }

    update_meta_cache('post', [$ticket_post_id]);

    // Datos para mostrar
    $first = get_post_meta($ticket_post_id, '_eventosapp_asistente_nombre', true);
    $last  = get_post_meta($ticket_post_id, '_eventosapp_asistente_apellido', true);
    $comp  = get_post_meta($ticket_post_id, '_eventosapp_asistente_empresa', true);
    $role  = get_post_meta($ticket_post_id, '_eventosapp_asistente_cargo', true);
$loc   = get_post_meta($ticket_post_id, '_eventosapp_asistente_localidad', true);

    wp_send_json_success([
        'already'              => $already,
        'full_name'            => trim($first.' '.$last),
        'company'              => $comp,
        'designation'          => $role,
        'localidad'            => $loc,
        'event_name'           => get_the_title($event_id),
        'ticket_id'            => $ticket_post_id,
        'checkin_date'         => $today,
        'checkin_date_label'   => date_i18n('D, d M Y', strtotime($today)),
        'qr_type_label'        => $qr_type_label,
        'payment_message'      => $mensaje_pago,
        'acompanantes_enabled' => (get_post_meta($event_id, '_eventosapp_ticket_acompanantes_checkin', true) === '1'),
    ]);
});

/**
 * ========================================================================
 * FUNCIÓN NUEVA: Actualizar estadísticas de uso de QR
 * ========================================================================
 * 
 * UBICACIÓN: Agregar AL FINAL del archivo eventosapp-qr-checkin.php
 * JUSTO ANTES del cierre: ?>
 * 
 * INSTRUCCIONES:
 * 1. Ve al final del archivo eventosapp-qr-checkin.php
 * 2. Busca la línea final que tiene: ?>
 * 3. ANTES de esa línea, agrega esta función COMPLETA
 */

if (!function_exists('eventosapp_update_qr_usage_stats')) {
    function eventosapp_update_qr_usage_stats($event_id, $qr_type) {
        if (!$event_id || !$qr_type) return;
        
        // Obtener estadísticas actuales
        $stats = get_post_meta($event_id, '_eventosapp_qr_usage_stats', true);
        if (!is_array($stats)) {
            $stats = array(
                'email' => 0,
                'google_wallet' => 0,
                'apple_wallet' => 0,
                'pdf' => 0,
                'whatsapp' => 0,
                'badge' => 0,
                'legacy' => 0,
                'total' => 0
            );
        }
        
        // Incrementar contador del tipo
        if (!isset($stats[$qr_type])) {
            $stats[$qr_type] = 0;
        }
        $stats[$qr_type]++;
        
        // Actualizar total
        $stats['total'] = array_sum(array_filter($stats, 'is_numeric'));
        
        // Agregar timestamp de última actualización
        $stats['last_updated'] = current_time('mysql');
        
        // Guardar
        update_post_meta($event_id, '_eventosapp_qr_usage_stats', $stats);
    }
}
// === Shortcode: Validador de Localidad (solo lectura) ===
// Uso: [eventosapp_qr_localidad]
add_shortcode('eventosapp_qr_localidad', function($atts){
    // El dashboard y la protección de URL manejan este módulo como feature independiente.
    if ( function_exists('eventosapp_require_feature') ) {
        eventosapp_require_feature('qr_localidad');
    }

    // Debe existir un evento activo.
    $active_event = function_exists('eventosapp_get_active_event') ? eventosapp_get_active_event() : 0;
    if ( ! $active_event ) {
        ob_start();
        if ( function_exists('eventosapp_require_active_event') ) {
            eventosapp_require_active_event();
        } else {
            echo '<p>Debes seleccionar un evento activo.</p>';
        }
        return ob_get_clean();
    }

    // Registrar jsQR como fallback. El script se carga bajo demanda desde JS únicamente
    // cuando BarcodeDetector no está disponible en el navegador.
    if ( ! wp_script_is('jsqr', 'registered') ) {
        wp_register_script('jsqr', 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js', [], null, true);
    }

    $nonce         = wp_create_nonce('eventosapp_qr_localidad');
    $dashboard_url = function_exists('eventosapp_get_dashboard_url')
        ? eventosapp_get_dashboard_url()
        : home_url('/');
    $event_name    = get_the_title($active_event);

    ob_start(); ?>
    <style>
    .evapp-qr-localidad-app {
      --evapp-primary:#3279bd;
      --evapp-primary-dark:#255f96;
      --evapp-primary-soft:#eaf4ff;
      --evapp-app-bg:#f5f8fc;
      --evapp-surface:#ffffff;
      --evapp-border:#dfe7f1;
      --evapp-text:#182230;
      --evapp-muted:#64748b;
      --evapp-success:#16855b;
      --evapp-success-soft:#ecfdf5;
      --evapp-warning:#a16207;
      --evapp-warning-soft:#fff8e6;
      --evapp-danger:#c53a3a;
      --evapp-danger-soft:#fff1f1;
      --evapp-radius:18px;
      --evapp-radius-lg:26px;
      width:100%;
      max-width:1180px;
      margin:0 auto;
      color:var(--evapp-text);
      font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
      box-sizing:border-box;
    }
    .evapp-qr-localidad-app *,
    .evapp-qr-localidad-app *::before,
    .evapp-qr-localidad-app *::after { box-sizing:border-box; }

    .evapp-qr-localidad-app .evqrl-shell {
      width:100%;
      padding:clamp(18px,3vw,36px);
      background:var(--evapp-app-bg);
      border:1px solid var(--evapp-border);
      border-radius:var(--evapp-radius-lg);
      box-shadow:0 18px 50px rgba(31,65,99,.08);
    }
    .evapp-qr-localidad-app .evqrl-header {
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:24px;
      margin-bottom:22px;
    }
    .evapp-qr-localidad-app .evqrl-heading { min-width:0; }
    .evapp-qr-localidad-app .evqrl-eyebrow {
      margin:0 0 7px;
      color:var(--evapp-primary);
      font-size:12px;
      font-weight:800;
      letter-spacing:.15em;
      text-transform:uppercase;
    }
    .evapp-qr-localidad-app .evqrl-title {
      margin:0;
      color:var(--evapp-text);
      font-size:clamp(27px,4vw,42px);
      line-height:1.08;
      letter-spacing:-.035em;
      font-weight:800;
    }
    .evapp-qr-localidad-app .evqrl-subtitle {
      max-width:760px;
      margin:10px 0 0;
      color:var(--evapp-muted);
      font-size:15px;
      line-height:1.6;
    }

    .evapp-qr-localidad-app .evqrl-btn {
      min-height:44px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:9px;
      border:1px solid transparent;
      border-radius:12px;
      padding:10px 15px;
      font:inherit;
      font-size:14px;
      font-weight:750;
      line-height:1.1;
      text-decoration:none!important;
      cursor:pointer;
      transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease,background .16s ease,color .16s ease,opacity .16s ease;
      -webkit-tap-highlight-color:transparent;
    }
    .evapp-qr-localidad-app .evqrl-btn svg {
      width:18px;
      height:18px;
      flex:0 0 18px;
      fill:none;
      stroke:currentColor;
      stroke-width:2;
      stroke-linecap:round;
      stroke-linejoin:round;
    }
    .evapp-qr-localidad-app .evqrl-btn:hover:not(:disabled) {
      transform:translateY(-1px);
      text-decoration:none!important;
    }
    .evapp-qr-localidad-app .evqrl-btn:focus-visible,
    .evapp-qr-localidad-app .evqrl-scan-btn:focus-visible,
    .evapp-qr-localidad-app .evqrl-scan-again:focus-visible {
      outline:3px solid rgba(50,121,189,.22);
      outline-offset:2px;
    }
    .evapp-qr-localidad-app .evqrl-btn-secondary {
      background:var(--evapp-surface);
      border-color:var(--evapp-border);
      color:var(--evapp-text);
      box-shadow:0 5px 15px rgba(31,65,99,.05);
      white-space:nowrap;
    }
    .evapp-qr-localidad-app .evqrl-btn-secondary:hover {
      border-color:#c7d7e8;
      color:var(--evapp-primary-dark);
      box-shadow:0 8px 20px rgba(31,65,99,.09);
    }

    .evapp-qr-localidad-app .evqrl-event-context {
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:16px;
      margin-bottom:22px;
      padding:16px 18px;
      background:var(--evapp-surface);
      border:1px solid var(--evapp-border);
      border-radius:var(--evapp-radius);
      box-shadow:0 8px 24px rgba(31,65,99,.045);
    }
    .evapp-qr-localidad-app .evqrl-event-main {
      min-width:0;
      display:flex;
      align-items:center;
      gap:13px;
    }
    .evapp-qr-localidad-app .evqrl-event-icon {
      width:44px;
      height:44px;
      flex:0 0 44px;
      display:grid;
      place-items:center;
      color:var(--evapp-primary);
      background:var(--evapp-primary-soft);
      border-radius:13px;
    }
    .evapp-qr-localidad-app .evqrl-event-icon svg {
      width:22px;
      height:22px;
      fill:none;
      stroke:currentColor;
      stroke-width:1.9;
      stroke-linecap:round;
      stroke-linejoin:round;
    }
    .evapp-qr-localidad-app .evqrl-event-copy { min-width:0; }
    .evapp-qr-localidad-app .evqrl-event-kicker {
      display:block;
      margin-bottom:3px;
      color:var(--evapp-muted);
      font-size:11px;
      font-weight:800;
      letter-spacing:.09em;
      text-transform:uppercase;
    }
    .evapp-qr-localidad-app .evqrl-event-name {
      display:block;
      overflow:hidden;
      color:var(--evapp-text);
      font-size:15px;
      font-weight:800;
      line-height:1.3;
      text-overflow:ellipsis;
      white-space:nowrap;
    }
    .evapp-qr-localidad-app .evqrl-event-meta {
      display:flex;
      align-items:center;
      justify-content:flex-end;
      flex-wrap:wrap;
      gap:8px;
    }
    .evapp-qr-localidad-app .evqrl-chip {
      min-height:30px;
      display:inline-flex;
      align-items:center;
      gap:7px;
      padding:6px 10px;
      border:1px solid var(--evapp-border);
      border-radius:999px;
      background:#fff;
      color:var(--evapp-muted);
      font-size:12px;
      font-weight:750;
      white-space:nowrap;
    }
    .evapp-qr-localidad-app .evqrl-chip::before {
      width:7px;
      height:7px;
      border-radius:50%;
      background:var(--evapp-primary);
      content:"";
    }
    .evapp-qr-localidad-app .evqrl-chip.is-readonly {
      color:var(--evapp-primary-dark);
      border-color:#cfe3f6;
      background:var(--evapp-primary-soft);
    }
    .evapp-qr-localidad-app .evqrl-chip.is-safe {
      color:var(--evapp-success);
      border-color:#cfeadf;
      background:var(--evapp-success-soft);
    }
    .evapp-qr-localidad-app .evqrl-chip.is-safe::before { background:var(--evapp-success); }

    .evapp-qr-localidad-app .evqrl-layout {
      display:grid;
      grid-template-columns:minmax(0,1.18fr) minmax(320px,.82fr);
      gap:20px;
      align-items:start;
    }
    .evapp-qr-localidad-app .evqrl-panel {
      min-width:0;
      background:var(--evapp-surface);
      border:1px solid var(--evapp-border);
      border-radius:var(--evapp-radius);
      box-shadow:0 8px 26px rgba(31,65,99,.05);
    }
    .evapp-qr-localidad-app .evqrl-scanner-panel { padding:18px; }
    .evapp-qr-localidad-app .evqrl-result-panel {
      position:sticky;
      top:18px;
      padding:18px;
    }
    body.admin-bar .evapp-qr-localidad-app .evqrl-result-panel { top:50px; }
    .evapp-qr-localidad-app .evqrl-panel-head {
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:14px;
      margin-bottom:15px;
    }
    .evapp-qr-localidad-app .evqrl-panel-title {
      margin:0;
      color:var(--evapp-text);
      font-size:17px;
      font-weight:800;
      line-height:1.3;
    }
    .evapp-qr-localidad-app .evqrl-panel-desc {
      margin:5px 0 0;
      color:var(--evapp-muted);
      font-size:13px;
      line-height:1.5;
    }
    .evapp-qr-localidad-app .evqrl-camera-state {
      min-height:30px;
      display:inline-flex;
      align-items:center;
      gap:7px;
      flex:0 0 auto;
      padding:6px 10px;
      border:1px solid var(--evapp-border);
      border-radius:999px;
      background:#f8fafc;
      color:var(--evapp-muted);
      font-size:12px;
      font-weight:800;
      white-space:nowrap;
    }
    .evapp-qr-localidad-app .evqrl-camera-state::before {
      width:7px;
      height:7px;
      border-radius:50%;
      background:#94a3b8;
      content:"";
    }
    .evapp-qr-localidad-app .evqrl-camera-state.is-live {
      color:var(--evapp-success);
      background:var(--evapp-success-soft);
      border-color:#cfeadf;
    }
    .evapp-qr-localidad-app .evqrl-camera-state.is-live::before {
      background:var(--evapp-success);
      box-shadow:0 0 0 4px rgba(22,133,91,.10);
    }
    .evapp-qr-localidad-app .evqrl-camera-state.is-busy {
      color:var(--evapp-primary-dark);
      background:var(--evapp-primary-soft);
      border-color:#cfe3f6;
    }
    .evapp-qr-localidad-app .evqrl-camera-state.is-busy::before {
      background:var(--evapp-primary);
      animation:evqrlPulse 1s ease-in-out infinite;
    }

    .evapp-qr-localidad-app .evqrl-scan-btn,
    .evapp-qr-localidad-app .evqrl-scan-again {
      width:100%;
      min-height:48px;
      display:flex;
      align-items:center;
      justify-content:center;
      gap:9px;
      margin:0;
      border:1px solid transparent;
      border-radius:12px;
      padding:12px 16px;
      background:var(--evapp-primary);
      color:#fff;
      font:inherit;
      font-size:14px;
      font-weight:800;
      line-height:1.2;
      cursor:pointer;
      box-shadow:0 9px 20px rgba(50,121,189,.18);
      transition:transform .16s ease,box-shadow .16s ease,background .16s ease,opacity .16s ease,border-color .16s ease,color .16s ease;
      -webkit-tap-highlight-color:transparent;
    }
    .evapp-qr-localidad-app .evqrl-scan-btn:hover:not(:disabled) {
      transform:translateY(-1px);
      background:var(--evapp-primary-dark);
      box-shadow:0 12px 24px rgba(50,121,189,.24);
    }
    .evapp-qr-localidad-app .evqrl-scan-btn:disabled,
    .evapp-qr-localidad-app .evqrl-scan-again:disabled {
      opacity:.56;
      cursor:not-allowed;
      transform:none;
      box-shadow:none;
    }
    .evapp-qr-localidad-app .evqrl-scan-btn.is-live {
      background:var(--evapp-danger);
      box-shadow:0 9px 20px rgba(197,58,58,.17);
    }
    .evapp-qr-localidad-app .evqrl-scan-btn.is-live:hover:not(:disabled) { background:#a92f2f; }
    .evapp-qr-localidad-app .evqrl-scan-btn svg,
    .evapp-qr-localidad-app .evqrl-scan-again svg {
      width:19px;
      height:19px;
      flex:0 0 19px;
      fill:none;
      stroke:currentColor;
      stroke-width:2;
      stroke-linecap:round;
      stroke-linejoin:round;
    }
    .evapp-qr-localidad-app .evqrl-scan-again {
      margin-top:14px;
      background:#fff;
      border-color:var(--evapp-border);
      color:var(--evapp-primary-dark);
      box-shadow:none;
    }
    .evapp-qr-localidad-app .evqrl-scan-again:hover:not(:disabled) {
      transform:translateY(-1px);
      background:var(--evapp-primary-soft);
      border-color:#c4dbee;
      box-shadow:none;
    }

    .evapp-qr-localidad-app .evqrl-video-wrap {
      position:relative;
      width:100%;
      margin-top:14px;
      overflow:hidden;
      aspect-ratio:4/3;
      min-height:300px;
      background:radial-gradient(circle at 50% 50%,rgba(50,121,189,.12),transparent 55%),#101827;
      border:1px solid #d6e1ec;
      border-radius:16px;
      box-shadow:inset 0 0 0 1px rgba(255,255,255,.03);
    }
    .evapp-qr-localidad-app .evqrl-video {
      width:100%;
      height:100%;
      display:none;
      object-fit:cover;
      background:#0f172a;
    }
    .evapp-qr-localidad-app .evqrl-camera-placeholder {
      position:absolute;
      inset:0;
      display:flex;
      align-items:center;
      justify-content:center;
      flex-direction:column;
      gap:10px;
      padding:24px;
      color:#c8d3df;
      text-align:center;
      pointer-events:none;
    }
    .evapp-qr-localidad-app .evqrl-camera-placeholder svg {
      width:42px;
      height:42px;
      fill:none;
      stroke:#6f8ca8;
      stroke-width:1.6;
      stroke-linecap:round;
      stroke-linejoin:round;
    }
    .evapp-qr-localidad-app .evqrl-camera-placeholder strong { color:#f8fafc; font-size:14px; }
    .evapp-qr-localidad-app .evqrl-camera-placeholder span {
      max-width:310px;
      color:#94a3b8;
      font-size:12px;
      line-height:1.5;
    }
    .evapp-qr-localidad-app .evqrl-video-wrap.has-camera .evqrl-camera-placeholder { display:none; }
    .evapp-qr-localidad-app .evqrl-frame {
      position:absolute;
      inset:0;
      display:none;
      pointer-events:none;
    }
    .evapp-qr-localidad-app .evqrl-frame .mask {
      position:absolute;
      inset:0;
      background:radial-gradient(ellipse 54% 54% at 50% 50%,rgba(0,0,0,0) 58%,rgba(7,14,24,.48) 61%);
    }
    .evapp-qr-localidad-app .evqrl-corner {
      position:absolute;
      width:56px;
      height:56px;
      border:4px solid #6fb8f4;
      border-radius:11px;
      filter:drop-shadow(0 2px 5px rgba(0,0,0,.26));
    }
    .evapp-qr-localidad-app .evqrl-corner.tl { top:20px;left:20px;border-right:0;border-bottom:0; }
    .evapp-qr-localidad-app .evqrl-corner.tr { top:20px;right:20px;border-left:0;border-bottom:0; }
    .evapp-qr-localidad-app .evqrl-corner.bl { bottom:20px;left:20px;border-right:0;border-top:0; }
    .evapp-qr-localidad-app .evqrl-corner.br { bottom:20px;right:20px;border-left:0;border-top:0; }
    .evapp-qr-localidad-app .evqrl-video-wrap.is-immersive {
      height:min(calc(100vh - var(--evapp-offset,72px)),760px);
      height:min(calc(100dvh - var(--evapp-offset,72px)),760px);
      min-height:420px;
      aspect-ratio:auto;
    }

    .evapp-qr-localidad-app .evqrl-scan-guide {
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:8px;
      margin-top:12px;
    }
    .evapp-qr-localidad-app .evqrl-guide-item {
      display:flex;
      align-items:center;
      gap:7px;
      min-width:0;
      padding:9px 10px;
      border:1px solid var(--evapp-border);
      border-radius:11px;
      background:#fbfdff;
      color:var(--evapp-muted);
      font-size:11px;
      font-weight:700;
      line-height:1.35;
    }
    .evapp-qr-localidad-app .evqrl-guide-item svg {
      width:15px;
      height:15px;
      flex:0 0 15px;
      fill:none;
      stroke:var(--evapp-primary);
      stroke-width:1.8;
      stroke-linecap:round;
      stroke-linejoin:round;
    }

    .evapp-qr-localidad-app .evqrl-result {
      min-height:190px;
      margin:0;
      padding:0;
      background:transparent;
      border:0;
      color:var(--evapp-text);
    }
    .evapp-qr-localidad-app .evqrl-help {
      display:flex;
      align-items:flex-start;
      gap:10px;
      margin:0;
      padding:14px;
      border:1px solid #dbe7f2;
      border-radius:13px;
      background:#f8fbfe;
      color:var(--evapp-muted);
      font-size:13px;
      line-height:1.55;
    }
    .evapp-qr-localidad-app .evqrl-help::before {
      width:20px;
      height:20px;
      flex:0 0 20px;
      display:grid;
      place-items:center;
      border-radius:50%;
      background:var(--evapp-primary-soft);
      color:var(--evapp-primary);
      content:"i";
      font-size:12px;
      font-weight:900;
    }
    .evapp-qr-localidad-app .evqrl-state {
      display:flex;
      align-items:flex-start;
      gap:12px;
      padding:15px;
      border:1px solid var(--evapp-border);
      border-radius:14px;
      background:#fff;
    }
    .evapp-qr-localidad-app .evqrl-state-icon {
      width:38px;
      height:38px;
      flex:0 0 38px;
      display:grid;
      place-items:center;
      border-radius:12px;
      font-size:18px;
      font-weight:900;
    }
    .evapp-qr-localidad-app .evqrl-state-copy { min-width:0; }
    .evapp-qr-localidad-app .evqrl-state-title {
      margin:0;
      color:var(--evapp-text);
      font-size:15px;
      font-weight:850;
      line-height:1.35;
    }
    .evapp-qr-localidad-app .evqrl-state-text {
      margin:4px 0 0;
      color:var(--evapp-muted);
      font-size:13px;
      line-height:1.5;
      overflow-wrap:anywhere;
    }
    .evapp-qr-localidad-app .evqrl-state.is-success {
      border-color:#cde8dc;
      background:var(--evapp-success-soft);
    }
    .evapp-qr-localidad-app .evqrl-state.is-success .evqrl-state-icon {
      background:#d7f5e7;
      color:var(--evapp-success);
    }
    .evapp-qr-localidad-app .evqrl-state.is-warning {
      border-color:#efdda7;
      background:var(--evapp-warning-soft);
    }
    .evapp-qr-localidad-app .evqrl-state.is-warning .evqrl-state-icon {
      background:#ffedbd;
      color:var(--evapp-warning);
    }
    .evapp-qr-localidad-app .evqrl-state.is-danger {
      border-color:#f0caca;
      background:var(--evapp-danger-soft);
    }
    .evapp-qr-localidad-app .evqrl-state.is-danger .evqrl-state-icon {
      background:#ffdede;
      color:var(--evapp-danger);
    }
    .evapp-qr-localidad-app .evqrl-state.is-info {
      border-color:#cfe3f6;
      background:var(--evapp-primary-soft);
    }
    .evapp-qr-localidad-app .evqrl-state.is-info .evqrl-state-icon {
      background:#d7eafc;
      color:var(--evapp-primary);
    }

    .evapp-qr-localidad-app .evqrl-locality-card {
      margin-top:14px;
      padding:18px;
      border:1px solid #bedaf1;
      border-radius:16px;
      background:linear-gradient(135deg,#f4f9ff 0%,var(--evapp-primary-soft) 100%);
      text-align:center;
    }
    .evapp-qr-localidad-app .evqrl-locality-label {
      display:block;
      margin-bottom:7px;
      color:var(--evapp-muted);
      font-size:11px;
      font-weight:850;
      letter-spacing:.12em;
      text-transform:uppercase;
    }
    .evapp-qr-localidad-app .evqrl-locality-value {
      display:block;
      color:var(--evapp-primary-dark);
      font-size:clamp(24px,4vw,38px);
      font-weight:900;
      line-height:1.08;
      letter-spacing:-.025em;
      overflow-wrap:anywhere;
      text-transform:uppercase;
    }
    .evapp-qr-localidad-app .evqrl-locality-card.is-missing {
      border-color:#efdda7;
      background:var(--evapp-warning-soft);
    }
    .evapp-qr-localidad-app .evqrl-locality-card.is-missing .evqrl-locality-value { color:var(--evapp-warning); }

    .evapp-qr-localidad-app .evqrl-grid {
      display:grid;
      grid-template-columns:minmax(105px,.42fr) minmax(0,1fr);
      gap:0;
      margin-top:14px;
      overflow:hidden;
      border:1px solid var(--evapp-border);
      border-radius:14px;
      background:#fff;
    }
    .evapp-qr-localidad-app .evqrl-grid > div {
      min-width:0;
      padding:10px 12px;
      border-bottom:1px solid #edf2f7;
      color:var(--evapp-text);
      font-size:13px;
      line-height:1.4;
      overflow-wrap:anywhere;
    }
    .evapp-qr-localidad-app .evqrl-grid > div:nth-last-child(-n+2) { border-bottom:0; }
    .evapp-qr-localidad-app .evqrl-grid > div:nth-child(odd) {
      background:#f8fafc;
      color:var(--evapp-muted);
    }
    .evapp-qr-localidad-app .evqrl-grid b { color:inherit; font-weight:800; }

    @keyframes evqrlPulse { 0%,100%{opacity:.45} 50%{opacity:1} }

    @media (max-width:900px) {
      .evapp-qr-localidad-app .evqrl-layout { grid-template-columns:1fr; }
      .evapp-qr-localidad-app .evqrl-result-panel { position:static; top:auto; }
      .evapp-qr-localidad-app .evqrl-video-wrap { aspect-ratio:16/11; }
    }
    @media (max-width:680px) {
      .evapp-qr-localidad-app .evqrl-shell { padding:16px; border-radius:20px; }
      .evapp-qr-localidad-app .evqrl-header { flex-direction:column; gap:15px; }
      .evapp-qr-localidad-app .evqrl-header-actions,
      .evapp-qr-localidad-app .evqrl-header-actions .evqrl-btn { width:100%; }
      .evapp-qr-localidad-app .evqrl-event-context {
        align-items:flex-start;
        flex-direction:column;
      }
      .evapp-qr-localidad-app .evqrl-event-meta { width:100%; justify-content:flex-start; }
      .evapp-qr-localidad-app .evqrl-panel-head { flex-direction:column; }
      .evapp-qr-localidad-app .evqrl-camera-state { align-self:flex-start; }
      .evapp-qr-localidad-app .evqrl-scanner-panel,
      .evapp-qr-localidad-app .evqrl-result-panel { padding:14px; }
      .evapp-qr-localidad-app .evqrl-video-wrap {
        min-height:360px;
        aspect-ratio:3/4;
        border-radius:14px;
      }
      .evapp-qr-localidad-app .evqrl-video-wrap.is-immersive {
        height:calc(100vh - var(--evapp-offset,62px));
        height:calc(100dvh - var(--evapp-offset,62px));
        min-height:420px;
        max-height:none;
      }
      .evapp-qr-localidad-app .evqrl-scan-guide { grid-template-columns:1fr; }
      .evapp-qr-localidad-app .evqrl-grid { grid-template-columns:1fr; }
      .evapp-qr-localidad-app .evqrl-grid > div { border-bottom:0; }
      .evapp-qr-localidad-app .evqrl-grid > div:nth-child(odd) {
        padding-bottom:4px;
        font-size:11px;
        text-transform:uppercase;
        letter-spacing:.055em;
      }
      .evapp-qr-localidad-app .evqrl-grid > div:nth-child(even) {
        padding-top:4px;
        padding-bottom:11px;
        border-bottom:1px solid #edf2f7;
      }
      .evapp-qr-localidad-app .evqrl-grid > div:last-child { border-bottom:0; }
    }
    @media (max-width:420px) {
      .evapp-qr-localidad-app .evqrl-shell { padding:13px; }
      .evapp-qr-localidad-app .evqrl-title { font-size:28px; }
      .evapp-qr-localidad-app .evqrl-event-main { align-items:flex-start; }
      .evapp-qr-localidad-app .evqrl-event-name { white-space:normal; }
      .evapp-qr-localidad-app .evqrl-locality-card { padding:15px 12px; }
    }
    @media (prefers-reduced-motion:reduce) {
      .evapp-qr-localidad-app *,
      .evapp-qr-localidad-app *::before,
      .evapp-qr-localidad-app *::after {
        scroll-behavior:auto!important;
        transition:none!important;
        animation-duration:.001ms!important;
        animation-iteration-count:1!important;
      }
    }
    </style>

    <div class="evapp-qr-localidad evapp-qr-localidad-app" data-event="<?php echo esc_attr($active_event); ?>" data-event-name="<?php echo esc_attr($event_name); ?>">
      <div class="evqrl-shell">
        <header class="evqrl-header">
          <div class="evqrl-heading">
            <p class="evqrl-eyebrow">EVENTOSAPP</p>
            <h1 class="evqrl-title">Validador de Localidad</h1>
            <p class="evqrl-subtitle">Escanea el QR y confirma visualmente la localidad o tipo de acceso del asistente. Este módulo es solo lectura y no modifica el estado de check-in.</p>
          </div>
          <div class="evqrl-header-actions">
            <a href="<?php echo esc_url($dashboard_url); ?>" class="evqrl-btn evqrl-btn-secondary" aria-label="Volver al dashboard">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg>
              <span>Volver al dashboard</span>
            </a>
          </div>
        </header>

        <section class="evqrl-event-context" aria-label="Evento activo">
          <div class="evqrl-event-main">
            <div class="evqrl-event-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2"></rect><path d="M8 3v4M16 3v4M4 10h16"></path></svg>
            </div>
            <div class="evqrl-event-copy">
              <span class="evqrl-event-kicker">Evento activo</span>
              <span class="evqrl-event-name"><?php echo esc_html($event_name); ?></span>
            </div>
          </div>
          <div class="evqrl-event-meta">
            <span class="evqrl-chip is-readonly">Solo lectura</span>
            <span class="evqrl-chip is-safe">No modifica check-in</span>
          </div>
        </section>

        <div class="evqrl-layout">
          <section class="evqrl-panel evqrl-scanner-panel" aria-label="Lector de código QR">
            <div class="evqrl-panel-head">
              <div>
                <h2 class="evqrl-panel-title">Lector QR</h2>
                <p class="evqrl-panel-desc">Usa preferiblemente la cámara trasera y mantén el código centrado.</p>
              </div>
              <div class="evqrl-camera-state" id="evqrlCameraState" aria-live="polite">Cámara inactiva</div>
            </div>

            <button class="evqrl-scan-btn" id="evappStartScanLoc" type="button">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4"></path><rect x="7" y="7" width="10" height="10" rx="2"></rect></svg>
              <span>Activar cámara y escanear</span>
            </button>

            <div class="evqrl-video-wrap" id="evqrlVideoWrap">
              <div class="evqrl-camera-placeholder" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M3 7h4l2-2h6l2 2h4v12H3z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                <strong>La cámara está apagada</strong>
                <span>Al activarla, el navegador puede solicitar permiso para utilizar la cámara.</span>
              </div>
              <video id="evappVideoLoc" class="evqrl-video" playsinline muted></video>
              <div class="evqrl-frame" id="evappFrameLoc" aria-hidden="true">
                <div class="mask"></div>
                <div class="evqrl-corner tl"></div>
                <div class="evqrl-corner tr"></div>
                <div class="evqrl-corner bl"></div>
                <div class="evqrl-corner br"></div>
              </div>
              <canvas id="evappCanvasLoc" style="display:none;"></canvas>
            </div>

            <div class="evqrl-scan-guide" aria-label="Consejos de lectura">
              <div class="evqrl-guide-item"><svg viewBox="0 0 24 24"><path d="M12 3v18M3 12h18"></path></svg>Centra el QR</div>
              <div class="evqrl-guide-item"><svg viewBox="0 0 24 24"><path d="M12 2v4M12 18v4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M2 12h4M18 12h4M4.9 19.1l2.8-2.8M16.3 7.7l2.8-2.8"></path></svg>Evita reflejos</div>
              <div class="evqrl-guide-item"><svg viewBox="0 0 24 24"><path d="M5 12h14M9 8l-4 4 4 4"></path></svg>Acerca si no lee</div>
            </div>
          </section>

          <section class="evqrl-panel evqrl-result-panel" aria-label="Resultado de la validación">
            <div class="evqrl-panel-head">
              <div>
                <h2 class="evqrl-panel-title">Localidad del asistente</h2>
                <p class="evqrl-panel-desc">La localidad aparecerá destacada junto con los datos básicos del ticket.</p>
              </div>
            </div>
            <div class="evqrl-result" id="evappResultLoc" aria-live="polite" aria-atomic="false">
              <div class="evqrl-help">Activa la cámara y coloca el QR dentro del marco. La consulta es únicamente informativa.</div>
            </div>
          </section>
        </div>
      </div>
    </div>

<script>
(function(){
  const wrap = document.querySelector('.evapp-qr-localidad-app');
  if (!wrap || wrap.dataset.evqrlReady === '1') return;
  wrap.dataset.evqrlReady = '1';

  const ajaxURL   = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
  const ajaxNonce = "<?php echo esc_js( $nonce ); ?>";
  const eventID   = parseInt(wrap.dataset.event || '0', 10) || 0;

  const btn         = wrap.querySelector('#evappStartScanLoc');
  const video       = wrap.querySelector('#evappVideoLoc');
  const frame       = wrap.querySelector('#evappFrameLoc');
  const cvs         = wrap.querySelector('#evappCanvasLoc');
  const out         = wrap.querySelector('#evappResultLoc');
  const vwrap       = wrap.querySelector('#evqrlVideoWrap');
  const cameraState = wrap.querySelector('#evqrlCameraState');
  const ctx         = cvs ? cvs.getContext('2d', {willReadFrequently:true}) : null;

  if (!btn || !video || !frame || !cvs || !ctx || !out || !vwrap) return;

  let stream = null;
  let running = false;
  let rafId = 0;
  let lastScan = '';
  let lastAt = 0;
  let lastDetectionAt = 0;
  let jsQrPromise = null;

  const DETECTION_INTERVAL = 110;
  const MAX_SCAN_WIDTH = 960;

  let barcodeDetector = null;
  try {
    if ('BarcodeDetector' in window) {
      barcodeDetector = new window.BarcodeDetector({formats:['qr_code']});
    }
  } catch (e) {
    barcodeDetector = null;
  }

  function escapeHtml(value){
    return String(value ?? '')
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  function safeValue(value, fallback='-'){
    const text = String(value ?? '').trim();
    return text ? escapeHtml(text) : fallback;
  }

  function getOffsetCompensation(){
    const adminBar = document.getElementById('wpadminbar');
    return (adminBar ? adminBar.offsetHeight : 0) + 12;
  }

  function smoothScrollTo(el){
    if (!el) return;
    const offset = getOffsetCompensation();
    try { el.style.setProperty('--evapp-offset', offset + 'px'); } catch(e){}
    const y = el.getBoundingClientRect().top + window.pageYOffset - offset;
    const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
    window.scrollTo({top:y, behavior});
  }

  function setCameraState(label, state){
    if (!cameraState) return;
    cameraState.textContent = label;
    cameraState.classList.remove('is-live','is-busy');
    if (state === 'live') cameraState.classList.add('is-live');
    if (state === 'busy') cameraState.classList.add('is-busy');
  }

  function setLiveUI(on){
    if (on) {
      btn.classList.add('is-live');
      btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6h12v12H6z"></path></svg><span>Detener cámara</span>';
      setCameraState('Cámara activa','live');
    } else {
      btn.classList.remove('is-live');
      btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4"></path><rect x="7" y="7" width="10" height="10" rx="2"></rect></svg><span>Activar cámara y escanear</span>';
      setCameraState('Cámara inactiva','');
    }
  }

  function beep(){
    try {
      const a = new Audio();
      a.src = 'data:audio/mp3;base64,//uQxAAAAAAAAAAAAAAAAAAAAAAAWGlinZwAAAA8AAAACAAACcQAA';
      a.play().catch(()=>{});
    } catch(e){}
    if (navigator.vibrate) navigator.vibrate(60);
  }

  function setOutput(html){ out.innerHTML = html; }

  function renderState(type, title, text){
    const icon = type === 'success' ? '✓' : (type === 'warning' ? '!' : (type === 'danger' ? '×' : 'i'));
    return '<div class="evqrl-state is-' + type + '">' +
      '<div class="evqrl-state-icon" aria-hidden="true">' + icon + '</div>' +
      '<div class="evqrl-state-copy"><p class="evqrl-state-title">' + escapeHtml(title) + '</p>' +
      (text ? '<p class="evqrl-state-text">' + escapeHtml(text) + '</p>' : '') +
      '</div></div>';
  }

  function row(label, value){
    return '<div><b>' + escapeHtml(label) + ':</b></div><div>' + safeValue(value) + '</div>';
  }

  function normalizeRaw(raw){
    let s = String(raw || '').trim();
    if (s.startsWith('http://') || s.startsWith('https://')) return s;
    if (s.includes('/')) s = s.split('/').pop();
    return s.replace(/\.(png|jpg|jpeg|pdf)$/i,'').replace(/-tn$/i,'').replace(/^#/,'');
  }

  function configureCanvas(){
    const sourceW = video.videoWidth || 1280;
    const sourceH = video.videoHeight || 720;
    const scale = Math.min(1, MAX_SCAN_WIDTH / sourceW);
    cvs.width = Math.max(320, Math.round(sourceW * scale));
    cvs.height = Math.max(240, Math.round(sourceH * scale));
  }

  function stop(){
    running = false;
    if (rafId) {
      cancelAnimationFrame(rafId);
      rafId = 0;
    }
    if (stream) stream.getTracks().forEach(track=>track.stop());
    stream = null;
    try { video.pause(); } catch(e){}
    video.srcObject = null;
    video.style.display = 'none';
    frame.style.display = 'none';
    vwrap.classList.remove('is-immersive','has-camera');
    setLiveUI(false);
  }

  async function ensureJsQR(){
    if (barcodeDetector) return false;
    if (window.jsQR) return true;
    if (jsQrPromise) return jsQrPromise;

    jsQrPromise = new Promise((resolve, reject)=>{
      const existing = document.querySelector('script[data-evapp-jsqr="1"],script[src*="/jsqr@1.4.0/dist/jsQR.js"]');
      if (existing) {
        if (window.jsQR) {
          resolve(true);
          return;
        }
        existing.addEventListener('load', ()=>resolve(true), {once:true});
        existing.addEventListener('error', ()=>reject(new Error('No fue posible cargar el lector QR alterno.')), {once:true});
        return;
      }

      const s = document.createElement('script');
      s.src = (window.jsqr_src || 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js');
      s.async = true;
      s.dataset.evappJsqr = '1';
      s.onload = ()=>resolve(true);
      s.onerror = ()=>reject(new Error('No fue posible cargar el lector QR alterno.'));
      document.head.appendChild(s);
    }).catch(err=>{
      jsQrPromise = null;
      throw err;
    });

    return jsQrPromise;
  }

  function cameraErrorMessage(err){
    const name = err && err.name ? String(err.name) : '';
    if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
      return 'El navegador no tiene permiso para usar la cámara. Habilita el permiso de cámara para este sitio y vuelve a intentar.';
    }
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
      return 'No se encontró una cámara disponible en este dispositivo.';
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return 'Este navegador no permite acceder a la cámara desde esta página. Verifica que el sitio use HTTPS y que el navegador esté actualizado.';
    }
    return 'No se pudo acceder a la cámara. Revisa los permisos del navegador y vuelve a intentar.';
  }

  async function start(){
    if (!eventID) {
      setOutput(renderState('danger','No hay evento activo','Regresa al dashboard y selecciona el evento que vas a gestionar.'));
      smoothScrollTo(out);
      return false;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setOutput(renderState('danger','Cámara no disponible','El navegador no ofrece acceso a la cámara en este contexto. Verifica HTTPS y permisos.'));
      smoothScrollTo(out);
      return false;
    }

    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video:{facingMode:{ideal:'environment'},width:{ideal:1280},height:{ideal:720}},
        audio:false
      });
    } catch(e) {
      setOutput(renderState('danger','No se pudo activar la cámara',cameraErrorMessage(e)));
      smoothScrollTo(out);
      return false;
    }

    try {
      video.srcObject = stream;
      await video.play();
    } catch(e) {
      stop();
      setOutput(renderState('danger','No se pudo iniciar el visor','El navegador bloqueó la reproducción de la cámara. Vuelve a intentar.'));
      smoothScrollTo(out);
      return false;
    }

    configureCanvas();
    video.style.display = 'block';
    frame.style.display = 'block';
    vwrap.classList.add('has-camera','is-immersive');
    smoothScrollTo(vwrap);

    const oldAgain = out.querySelector('#evappScanAgainLoc');
    if (oldAgain) oldAgain.remove();

    running = true;
    lastDetectionAt = 0;
    setLiveUI(true);
    rafId = requestAnimationFrame(tick);
    return true;
  }

  async function tick(timestamp){
    if (!running) return;

    if ((timestamp - lastDetectionAt) < DETECTION_INTERVAL) {
      rafId = requestAnimationFrame(tick);
      return;
    }
    lastDetectionAt = timestamp;

    try {
      ctx.drawImage(video, 0, 0, cvs.width, cvs.height);

      if (barcodeDetector) {
        let bitmap = null;
        try {
          bitmap = await createImageBitmap(cvs);
          const codes = await barcodeDetector.detect(bitmap);
          if (codes && codes.length && running) {
            const data = normalizeRaw(codes[0].rawValue || '');
            if (data) {
              onScan(data);
              return;
            }
          }
        } catch(e) {
        } finally {
          if (bitmap && typeof bitmap.close === 'function') bitmap.close();
        }
      } else if (window.jsQR) {
        const img = ctx.getImageData(0,0,cvs.width,cvs.height);
        const code = window.jsQR(img.data, img.width, img.height);
        if (code && code.data && running) {
          const data = normalizeRaw(code.data);
          if (data) {
            onScan(data);
            return;
          }
        }
      }
    } catch(e) {}

    if (running) rafId = requestAnimationFrame(tick);
  }

  function injectScanAgainButton(){
    const old = out.querySelector('#evappScanAgainLoc');
    if (old) old.remove();

    const againBtn = document.createElement('button');
    againBtn.id = 'evappScanAgainLoc';
    againBtn.type = 'button';
    againBtn.className = 'evqrl-scan-again';
    againBtn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 10-2.34 5.66M20 5v6h-6"></path></svg><span>Escanear otro QR</span>';
    out.appendChild(againBtn);

    againBtn.addEventListener('click', async ()=>{
      againBtn.disabled = true;
      setCameraState('Preparando cámara','busy');
      try {
        await ensureJsQR();
        const started = await start();
        if (started) {
          setOutput('<div class="evqrl-help">Cámara activa. Centra el QR dentro del marco y evita reflejos.</div>');
        } else {
          injectScanAgainButton();
        }
      } catch(e) {
        setCameraState('Cámara inactiva','');
        setOutput(renderState('danger','No se pudo preparar el lector',e && e.message ? e.message : 'Vuelve a intentar.'));
        injectScanAgainButton();
        smoothScrollTo(out);
      }
    });
  }

  function onScan(data){
    const now = Date.now();
    if (data === lastScan && (now - lastAt) < 2500) {
      if (running) rafId = requestAnimationFrame(tick);
      return;
    }

    lastScan = data;
    lastAt = now;
    beep();
    stop();
    setCameraState('Procesando','busy');
    setOutput(renderState('info','Validando QR','Estamos consultando la localidad del asistente.'));
    smoothScrollTo(out);

    const fd = new FormData();
    fd.append('action','eventosapp_qr_localidad_lookup');
    fd.append('security',ajaxNonce);
    fd.append('event_id',String(eventID));
    fd.append('scanned',data);

    fetch(ajaxURL,{method:'POST',body:fd,credentials:'same-origin'})
      .then(r=>r.json())
      .then(resp=>{
        setCameraState('Cámara inactiva','');

        if (!resp || !resp.success) {
          const msg = (resp && resp.data && resp.data.error) ? resp.data.error : 'Error desconocido';
          setOutput(renderState('danger','No se pudo validar el QR',msg));
          injectScanAgainButton();
          smoothScrollTo(out);
          return;
        }

        const d = resp.data || {};
        const locality = String(d.localidad ?? '').trim();
        const hasLocality = locality !== '';

        let html = renderState(
          hasLocality ? 'success' : 'warning',
          hasLocality ? 'QR validado' : 'QR válido sin localidad',
          hasLocality ? 'La localidad del asistente se muestra a continuación.' : 'El ticket pertenece al evento activo, pero no tiene una localidad registrada.'
        );

        html += '<div class="evqrl-locality-card' + (hasLocality ? '' : ' is-missing') + '">' +
          '<span class="evqrl-locality-label">Localidad</span>' +
          '<span class="evqrl-locality-value">' + safeValue(d.localidad,'Sin localidad') + '</span>' +
          '</div>';

        html += '<div class="evqrl-grid">' +
          row('Localidad',d.localidad) +
          row('Nombre',d.full_name) +
          row('Evento',d.event_name) +
          row('Empresa',d.company) +
          row('Cargo',d.designation) +
          '</div>';

        setOutput(html);
        injectScanAgainButton();
        smoothScrollTo(out);
      })
      .catch(()=>{
        setCameraState('Cámara inactiva','');
        setOutput(renderState('danger','Error de conexión','No se pudo obtener la información del asistente. Comprueba la conexión e intenta nuevamente.'));
        injectScanAgainButton();
        smoothScrollTo(out);
      });
  }

  btn.addEventListener('click', async ()=>{
    if (stream && stream.active) {
      stop();
      setOutput('<div class="evqrl-help">Cámara detenida. Puedes volver a activarla cuando estés listo para el siguiente escaneo.</div>');
      return;
    }

    btn.disabled = true;
    setCameraState('Preparando cámara','busy');
    try {
      await ensureJsQR();
      const started = await start();
      if (started) {
        setOutput('<div class="evqrl-help">Cámara activa. Centra el QR dentro del marco; la lectura se detendrá automáticamente al detectar un código.</div>');
      }
    } catch(e) {
      setCameraState('Cámara inactiva','');
      setOutput(renderState('danger','No se pudo preparar el lector',e && e.message ? e.message : 'Vuelve a intentar.'));
      smoothScrollTo(out);
    } finally {
      btn.disabled = false;
    }
  });

  window.addEventListener('pagehide',stop,{passive:true});
})();
</script>
    <?php
    return ob_get_clean();
});


// === AJAX: Obtener datos del ticket (solo lectura) para mostrar localidad en grande ===
add_action('wp_ajax_eventosapp_qr_localidad_lookup', function(){
    if ( ! is_user_logged_in() ) {
        wp_send_json_error(['error' => 'Debes iniciar sesión.'], 401);
    }

    // El permiso debe corresponder a la tarjeta/página independiente de Validador de Localidad.
    $can_localidad = current_user_can('manage_options');
    if ( ! $can_localidad ) {
        if ( function_exists('eventosapp_user_can_access_frontend_feature') ) {
            $can_localidad = eventosapp_user_can_access_frontend_feature('qr_localidad');
        } elseif ( function_exists('eventosapp_role_can') ) {
            $can_localidad = eventosapp_role_can('qr_localidad');
        }
    }
    if ( ! $can_localidad ) {
        wp_send_json_error(['error' => 'Permisos insuficientes para Validador de Localidad.'], 403);
    }

    check_ajax_referer('eventosapp_qr_localidad','security');

    $scanned  = isset($_POST['scanned'])  ? sanitize_text_field( wp_unslash($_POST['scanned']) ) : '';
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;

    // Forzar el evento activo para no administradores.
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $event_id !== $active ) {
            wp_send_json_error(['error' => 'Sin permisos para este evento.'], 403);
        }
    }

    if ( ! $scanned || ! $event_id ) {
        wp_send_json_error(['error' => 'Datos incompletos']);
    }

    $lookup = eventosapp_qr_find_ticket_by_scanned_code($scanned, $event_id);
    if ( empty($lookup['found']) || empty($lookup['ticket_id']) ) {
        wp_send_json_error([
            'error' => ! empty($lookup['error']) ? $lookup['error'] : 'Ticket no encontrado para este evento.'
        ]);
    }

    $ticket_post_id = absint($lookup['ticket_id']);

    // Seguridad extra: el ticket debe pertenecer al evento solicitado.
    $ticket_event = (int) get_post_meta($ticket_post_id, '_eventosapp_ticket_evento_id', true);
    if ( $ticket_event !== (int) $event_id ) {
        wp_send_json_error(['error' => 'El ticket no pertenece al evento activo']);
    }

    update_meta_cache('post', [$ticket_post_id]);

    // SOLO LECTURA: no se modifica estado de check-in y no se valida la fecha del evento.
    $first = get_post_meta($ticket_post_id, '_eventosapp_asistente_nombre', true);
    $last  = get_post_meta($ticket_post_id, '_eventosapp_asistente_apellido', true);
    $comp  = get_post_meta($ticket_post_id, '_eventosapp_asistente_empresa', true);
    $role  = get_post_meta($ticket_post_id, '_eventosapp_asistente_cargo', true);
    $loc   = get_post_meta($ticket_post_id, '_eventosapp_asistente_localidad', true);

    wp_send_json_success([
        'full_name'   => trim($first.' '.$last),
        'company'     => $comp,
        'designation' => $role,
        'localidad'   => $loc,
        'event_name'  => get_the_title($event_id),
        'ticket_id'   => $ticket_post_id,
    ]);
});

// === Shortcode: Check-In por Sesión Interna ===
// Uso: [eventosapp_qr_sesion]
add_shortcode('eventosapp_qr_sesion', function($atts){
// 🔒 Requiere permiso para "qr" (redirige con mensaje si no puede)
if ( function_exists('eventosapp_require_feature') ) {
    eventosapp_require_feature('qr');
}


    // Evento activo
    $active_event = function_exists('eventosapp_get_active_event') ? eventosapp_get_active_event() : 0;
    if ( ! $active_event ) {
        ob_start();
        if (function_exists('eventosapp_require_active_event')) {
            eventosapp_require_active_event();
        } else {
            echo '<p>Debes seleccionar un evento activo.</p>';
        }
        return ob_get_clean();
    }

    // Sesiones del evento activo (solo nombres)
    $raw_ses = get_post_meta($active_event, '_eventosapp_sesiones_internas', true);
    if (!is_array($raw_ses)) $raw_ses = [];
    $sesiones = [];
    foreach ($raw_ses as $s) {
        if (is_array($s) && !empty($s['nombre'])) $sesiones[] = $s['nombre'];
        elseif (is_string($s) && $s !== '')       $sesiones[] = $s;
    }

    // Nonce
    $nonce = wp_create_nonce('eventosapp_qr_sesion');

    ob_start(); ?>
    <style>
      .evapp-qr-shell { max-width: 560px; margin: 0 auto; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
      .evapp-qr-card  { background:#0b1020; color:#eaf1ff; border-radius:16px; padding:18px; box-shadow:0 8px 24px rgba(0,0,0,.15); }
      .evapp-qr-title { display:flex; align-items:center; gap:.6rem; margin:0 0 10px; font-weight:700; font-size:1.05rem; letter-spacing:.2px; }
      .evapp-qr-title svg { opacity:.9 }
      .evapp-qr-field { margin-bottom:10px; }
      .evapp-qr-field label { display:block; font-size:.95rem; margin-bottom:6px; color:#c9d6ff; font-weight:600; }
      .evapp-qr-select { width:100%; padding:.6rem .7rem; border-radius:10px; border:1px solid rgba(255,255,255,.12); background:#0a0f1d; color:#eaf1ff; }

      .evapp-qr-btn { display:flex; align-items:center; justify-content:center; gap:.5rem; border:0; border-radius:12px; padding:.9rem 1.1rem; font-weight:700; cursor:pointer; width:100%; background:#4f7cff; color:#fff; transition: filter .15s ease, background .15s ease, opacity .15s; }
      .evapp-qr-btn:hover { filter:brightness(.96); }
      .evapp-qr-btn.is-live { background:#e04f5f; color:#fff; }
      .evapp-qr-btn:disabled { opacity:.6; cursor:not-allowed; }

      /* === Naranja para el botón principal del Control por Sesión === */
      .evapp-qr-sesion .evapp-qr-btn { background:#f59e0b; color:#fff; }
      .evapp-qr-sesion .evapp-qr-btn:hover { background:#d97706; }
      .evapp-qr-sesion .evapp-qr-btn:active { background:#b45309; }
      .evapp-qr-sesion .evapp-qr-btn.is-live { background:#e04f5f; color:#fff; }

      /* Botón secundario (Escanear otro QR) – mismo color que el principal */
      .evapp-qr-sesion .evapp-qr-btn-secondary{
        margin-top:12px; width:100%;
        background:#f59e0b!important;
        color:#fff; border:0; border-radius:10px; padding:.7rem 1rem; font-weight:800; cursor:pointer;
        transition: filter .15s ease;
      }
      .evapp-qr-sesion .evapp-qr-btn-secondary:hover{ filter:brightness(1.05); }

      .evapp-qr-video-wrap { position:relative; margin-top:12px; border-radius:14px; overflow:hidden; background:#0a0f1d; aspect-ratio:3/4; }
      .evapp-qr-video { width:100%; height:100%; object-fit:cover; display:none; }
      .evapp-qr-frame { position:absolute; inset:0; pointer-events:none; display:none; }
      .evapp-qr-frame .mask { position:absolute; inset:0; background: radial-gradient(ellipse 60% 40% at 50% 50%, rgba(255,255,255,0) 62%, rgba(10,15,29,.55) 64%); }
      .evapp-qr-corner { position:absolute; width:44px; height:44px; border:4px solid #4f7cff; border-radius:10px; }
      .evapp-qr-corner.tl{top:16px;left:16px;border-right:0;border-bottom:0}
      .evapp-qr-corner.tr{top:16px;right:16px;border-left:0;border-bottom:0}
      .evapp-qr-corner.bl{bottom:16px;left:16px;border-right:0;border-top:0}
      .evapp-qr-corner.br{bottom:16px;right:16px;border-left:0;border-top:0}
      .evapp-qr-result { margin-top:14px; background:#0a0f1d; border:1px solid rgba(255,255,255,.06); border-radius:12px; padding:14px; }
      .evapp-qr-grid { display:grid; grid-template-columns: 1fr; gap:.2rem .8rem; margin-top:.4rem; }
      .evapp-qr-grid b { color:#a7b8ff; font-weight:600; }
      @media(min-width:480px){ .evapp-qr-grid{ grid-template-columns:auto 1fr} .evapp-qr-grid div{display:contents} .evapp-qr-grid b{text-align:right} }
      .evapp-qr-help { color:#a9b6d3; font-size:.9rem; margin-top:.6rem; opacity:.8 }
      .evapp-qr-ok   { color:#7CFF8D; font-weight:800; }
      .evapp-qr-bad  { color:#ff6b6b; font-weight:700; }
      .evapp-qr-warn { color:#ffd166; font-weight:700; }

      /* Caja de sesión seleccionada */
      .evapp-sesion-box { margin-top:12px; background:#0a1329; border:1px solid rgba(255,255,255,.08); border-radius:12px; padding:12px; }
      .evapp-sesion-title { display:flex; align-items:center; justify-content:space-between; font-weight:800; letter-spacing:.3px; }
      .evapp-sesion-badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:.78rem; color:#0b1020; background:#ffd166; font-weight:900; }
      .evapp-sesion-note  { font-size:.9rem; color:#a9b6d3; margin-top:6px; }
      .evapp-sesion-actions { margin-top:10px; display:flex; gap:8px; }
      .evapp-sesion-check { background:#22c55e; color:#fff; border:0; border-radius:10px; padding:.65rem .9rem; font-weight:800; cursor:pointer; }
      .evapp-sesion-check:hover { background:#16a34a; }
      .evapp-sesion-check:active { background:#15803d; }
      .evapp-sesion-check:disabled { opacity:.6; cursor:not-allowed; }
      .evapp-hidden { display:none; }

      /* === MODO INMERSIVO DEL VISOR (auto-scroll) === */
      .evapp-qr-video-wrap.is-immersive{
        aspect-ratio:auto;
        height: calc(100vh - var(--evapp-offset, 56px));
        width: 100%;
      }
    </style>

    <div class="evapp-qr-shell evapp-qr-sesion" data-event="<?php echo esc_attr($active_event); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
      <div class="evapp-qr-card">
        <div class="evapp-qr-title">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6zm14 0h2v6h-6v-2h4v-4z" stroke="#a7b8ff"/></svg>
          Lector de QR – Control de acceso por sesión
        </div>

        <div class="evapp-qr-field">
          <label for="evappSesionSelect">Selecciona la sesión interna a controlar</label>
          <select id="evappSesionSelect" class="evapp-qr-select">
            <option value="">— Selecciona una sesión —</option>
            <?php foreach ($sesiones as $s): ?>
              <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($s); ?></option>
            <?php endforeach; ?>
          </select>
          <div class="evapp-qr-help">Esta lista se carga del evento activo.</div>
        </div>

        <div id="evappScanCard" class="<?php echo $sesiones ? '' : 'evapp-hidden'; ?>">
          <button class="evapp-qr-btn" id="evappStartScanSesion" disabled>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/><rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/></svg>
            Activar cámara y escanear
          </button>

          <div class="evapp-qr-video-wrap">
            <video id="evappVideoSesion" class="evapp-qr-video" playsinline></video>
            <div class="evapp-qr-frame" id="evappFrameSesion">
              <div class="mask"></div>
              <div class="evapp-qr-corner tl"></div>
              <div class="evapp-qr-corner tr"></div>
              <div class="evapp-qr-corner bl"></div>
              <div class="evapp-qr-corner br"></div>
            </div>
            <canvas id="evappCanvasSesion" style="display:none;"></canvas>
          </div>

          <div class="evapp-qr-result" id="evappResultSesion">
            <div class="evapp-qr-help">Selecciona una sesión y activa la cámara para escanear.</div>
          </div>
        </div>

        <?php if (!$sesiones): ?>
          <div class="evapp-qr-help">No hay sesiones internas configuradas para este evento.</div>
        <?php endif; ?>
      </div>
    </div>

<script>
(function(){
  const wrap      = document.querySelector('.evapp-qr-sesion');
  const ajaxURL   = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
  const ajaxNonce = wrap?.dataset.nonce || "";
  const eventID   = parseInt(wrap?.dataset.event || '0', 10) || 0;

  const sel   = document.getElementById('evappSesionSelect');
  const btn   = document.getElementById('evappStartScanSesion');
  const video = document.getElementById('evappVideoSesion');
  const frame = document.getElementById('evappFrameSesion');
  const cvs   = document.getElementById('evappCanvasSesion');
  const ctx   = cvs.getContext('2d');
  const out   = document.getElementById('evappResultSesion');
  const vwrap = video.closest('.evapp-qr-video-wrap') || video.parentElement;

  let selectedSession = "";
  let stream = null;
  let running = false;
  let lastScan = "";
  let lastAt   = 0;
  let lastTicketId = 0; // para el toggle posterior
  let barcodeDetector = ('BarcodeDetector' in window) ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null;

  // === Scroll helpers ===
  function getOffsetCompensation(){
    const adminBar = document.getElementById('wpadminbar');
    const adminH = adminBar ? adminBar.offsetHeight : 0;
    return adminH + 10;
  }
  function smoothScrollTo(el){
    if (!el) return;
    const offset = getOffsetCompensation();
    try { el.style.setProperty('--evapp-offset', offset + 'px'); } catch(e){}
    const y = el.getBoundingClientRect().top + window.pageYOffset - offset;
    window.scrollTo({ top: y, behavior: 'smooth' });
  }

  // Habilitar botón al elegir sesión
  sel?.addEventListener('change', ()=>{
    selectedSession = sel.value || "";
    btn.disabled = !selectedSession;
    if (!selectedSession) {
      setOutput('<div class="evapp-qr-help">Selecciona una sesión para comenzar.</div>');
      smoothScrollTo(out);
    }
  });

  function setLiveUI(on){
    if (on) {
      btn.classList.add('is-live');
      btn.innerHTML = `
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
          <path d="M6 6h12v12H6z" stroke="white"/>
        </svg>
        Detener cámara
      `;
    } else {
      btn.classList.remove('is-live');
      btn.innerHTML = `
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
          <path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/>
          <rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/>
        </svg>
        Activar cámara y escanear
      `;
    }
  }
  function beep(){
    try{ const a=new Audio(); a.src='data:audio/mp3;base64,//uQxAAAAAAAAAAAAAAAAAAAAAAAWGlinZwAAAA8AAAACAAACcQAA'; a.play().catch(()=>{});}catch(e){}
    if (navigator.vibrate) navigator.vibrate(60);
  }
  function setOutput(html){ out.innerHTML = html; }
  function row(label, value){ return `<div><b>${label}:</b></div><div>${value || '-'}</div>`; }
  function normalizeRaw(raw){
    let s = String(raw||'').trim();
    // Si es una URL completa (badge/escarapela), devolverla sin modificar
    if (s.startsWith('http://') || s.startsWith('https://')) {
      return s;
    }
    // Para otros casos, aplicar normalización
    if (s.includes('/')) s = s.split('/').pop();
    s = s.replace(/\.(png|jpg|jpeg|pdf)$/i,'').replace(/-tn$/i,'').replace(/^#/, '');
    return s;
  }
  function stop(){
    running = false;
    if (stream) stream.getTracks().forEach(t=>t.stop());
    stream = null;
    video.style.display = 'none';
    frame.style.display = 'none';
    if (vwrap){ vwrap.classList.remove('is-immersive'); }
    setLiveUI(false);
  }
  async function ensureJsQR(){
    if ('BarcodeDetector' in window) return false;
    if (!window.jsQR) {
      await new Promise((resolve)=>{
        const s = document.createElement('script');
        s.src = (window.jsqr_src || 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js');
        s.onload = resolve;
        document.head.appendChild(s);
      });
    }
    return true;
  }
  async function start(){
    if (!eventID){ setOutput('<div class="evapp-qr-bad">No hay evento activo.</div>'); smoothScrollTo(out); return; }
    if (!selectedSession){ setOutput('<div class="evapp-qr-bad">Selecciona una sesión primero.</div>'); smoothScrollTo(out); return; }
    try{
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal:'environment' } }, audio:false });
    }catch(e){
      setOutput('<div class="evapp-qr-bad">No se pudo acceder a la cámara.</div>'); smoothScrollTo(out); return;
    }
    video.srcObject = stream;
    await video.play();
    video.style.display = 'block';
    frame.style.display = 'block';

    // Modo inmersivo + scroll al visor
    if (vwrap){ vwrap.classList.add('is-immersive'); smoothScrollTo(vwrap); }

    cvs.width  = video.videoWidth  || 640;
    cvs.height = video.videoHeight || 480;
    running = true; setLiveUI(true);

    // eliminar botón secundario si existía
    const again = document.getElementById('evappScanAgainSesion');
    if (again) again.remove();

    tick();
  }
  async function tick(){
    if (!running) return;
    ctx.drawImage(video, 0, 0, cvs.width, cvs.height);
    if (barcodeDetector) {
      try {
        const bitmap = await createImageBitmap(cvs);
        const codes  = await barcodeDetector.detect(bitmap);
        if (codes && codes.length){
          const data = normalizeRaw(codes[0].rawValue || '');
          onScan(data); return;
        }
      } catch(e){}
    } else if (window.jsQR) {
      const img  = ctx.getImageData(0,0,cvs.width,cvs.height);
      const code = window.jsQR(img.data, img.width, img.height);
      if (code && code.data) { onScan(normalizeRaw(code.data)); return; }
    }
    requestAnimationFrame(tick);
  }

  function injectScanAgainButton(){
    if (document.getElementById('evappScanAgainSesion')) return;
    const againBtn = document.createElement('button');
    againBtn.id = 'evappScanAgainSesion';
    againBtn.type = 'button';
    againBtn.className = 'evapp-qr-btn-secondary';
    againBtn.textContent = 'Escanear otro QR';
    out.appendChild(againBtn);

    againBtn.addEventListener('click', async ()=>{
      // Reactivar cámara; start() valida la sesión y hace el scroll
      await ensureJsQR();
      await start();
      setOutput('<div class="evapp-qr-help">Tip: coloca el QR dentro del marco. La lectura vibra/emite sonido al capturar.</div>');
    });
  }

  function renderSesionUI(d){
    const acc = d.has_access;
    const checked = (d.session_status === 'checked_in');
    const badge = checked ? '<span class="evapp-sesion-badge">YA CHECK-IN</span>' : (acc ? '<span class="evapp-sesion-badge" style="background:#7CFF8D;color:#0a1329;">PUEDE ENTRAR</span>' : '<span class="evapp-sesion-badge" style="background:#ffb4b4;color:#0a1329;">SIN ACCESO</span>');
    let html = '';
    html += '<div class="evapp-qr-grid">';
    html += row('Nombre', d.full_name);
    html += row('Evento', d.event_name);
    html += row('Empresa', d.company);
    html += row('Cargo', d.designation);
    html += row('Localidad', d.localidad);
    html += '</div>';

    html += `
      <div class="evapp-sesion-box">
        <div class="evapp-sesion-title">
          <div>${d.session}</div>
          <div>${badge}</div>
        </div>
        <div class="evapp-sesion-note">
          ${acc ? 'Control de acceso para esta sesión.' : 'Este ticket no tiene acceso a esta sesión.'}
        </div>
        <div class="evapp-sesion-actions">
          <button id="evappSesionCheckBtn" class="evapp-sesion-check" ${(!acc || checked) ? 'disabled' : ''}>
            Hacer Check-In
          </button>
        </div>
      </div>
    `;

    setOutput(html);
    smoothScrollTo(out);
    injectScanAgainButton(); // mostrar botón debajo del resultado

    const checkBtn = document.getElementById('evappSesionCheckBtn');
    if (checkBtn) {
      checkBtn.addEventListener('click', ()=>{
        checkBtn.disabled = true;
        const fd = new FormData();
        fd.append('action','eventosapp_qr_sesion_toggle');
        fd.append('security', ajaxNonce);
        fd.append('event_id', String(eventID));
        fd.append('ticket_id', String(lastTicketId || 0));
        fd.append('session', selectedSession);

        fetch(ajaxURL, { method:'POST', body:fd, credentials:'same-origin' })
          .then(r=>r.json())
          .then(resp=>{
            if (!resp || !resp.success){
              const msg = (resp && resp.data && resp.data.error) ? resp.data.error : 'Error desconocido';
              setOutput('<div class="evapp-qr-bad">'+ msg +'</div>');
              injectScanAgainButton();
              smoothScrollTo(out);
              return;
            }
            const info = resp.data || {};
            if (info.already) {
              setOutput('<div class="evapp-qr-warn">Ese asistente ya confirmó asistencia para esta sesión.</div>');
            } else {
              setOutput('<div class="evapp-qr-ok">✔ Check-In de la sesión confirmado.</div>');
            }
            injectScanAgainButton();
            smoothScrollTo(out);
          })
          .catch(()=>{
            setOutput('<div class="evapp-qr-bad">No se pudo actualizar el estado de la sesión.</div>');
            injectScanAgainButton();
            smoothScrollTo(out);
          });
      });
    }
  }

  function onScan(data){
    const now = Date.now();
    if (data === lastScan && (now - lastAt) < 2500){ requestAnimationFrame(tick); return; }
    lastScan = data; lastAt = now; beep();
    stop(); // detenemos para operar con tranquilidad
    setOutput('<div class="evapp-qr-help">Procesando: '+ data +'…</div>');
    smoothScrollTo(out);

    const fd = new FormData();
    fd.append('action','eventosapp_qr_sesion_lookup');
    fd.append('security', ajaxNonce);
    fd.append('event_id', String(eventID));
    fd.append('scanned',  data);
    fd.append('session',  selectedSession);

    fetch(ajaxURL, { method:'POST', body:fd, credentials:'same-origin' })
      .then(r=>r.json())
      .then(resp=>{
        if (!resp || !resp.success){
          const msg = (resp && resp.data && resp.data.error) ? resp.data.error : 'Error desconocido';
          setOutput('<div class="evapp-qr-bad">'+ msg +'</div>');
          injectScanAgainButton();
          smoothScrollTo(out);
          return;
        }
        const d = resp.data || {};
        lastTicketId = d.ticket_id || 0;
        renderSesionUI(d);
      })
      .catch(()=>{
        setOutput('<div class="evapp-qr-bad">No se pudo obtener la información del asistente.</div>');
        injectScanAgainButton();
        smoothScrollTo(out);
      });
  }

  // Toggle cámara
  btn.addEventListener('click', async ()=>{
    if (!selectedSession){ setOutput('<div class="evapp-qr-bad">Selecciona una sesión primero.</div>'); smoothScrollTo(out); return; }
    if (stream && stream.active) {
      stop();
      setOutput('<div class="evapp-qr-help">Cámara detenida. Haz clic para activar y escanear.</div>');
      smoothScrollTo(out);
      return;
    }
    await ensureJsQR();
    await start();
    setOutput('<div class="evapp-qr-help">Tip: coloca el QR dentro del marco. La lectura vibra/emite sonido al capturar.</div>');
    smoothScrollTo(out);
  });
})();
</script>
    <?php
    return ob_get_clean();
});




// === AJAX: Lookup por sesión (lee QR, valida evento y acceso a sesión) ===
add_action('wp_ajax_eventosapp_qr_sesion_lookup', function(){
    if ( ! is_user_logged_in() ) wp_send_json_error(['error'=>'No autorizado']);
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('qr') ) {
        wp_send_json_error(['error'=>'Permisos insuficientes']);
    }
    check_ajax_referer('eventosapp_qr_sesion','security');

    $scanned  = isset($_POST['scanned'])  ? sanitize_text_field( wp_unslash($_POST['scanned']) ) : '';
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    $session  = isset($_POST['session'])  ? sanitize_text_field( wp_unslash($_POST['session']) ) : '';

    // 🔒 Forzar evento ACTIVO para no-admins
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $event_id !== $active ) {
            wp_send_json_error(['error'=>'Sin permisos para este evento.'], 403);
        }
    }


    if (!$scanned || !$event_id || !$session) wp_send_json_error(['error'=>'Datos incompletos']);

    $day_validation = eventosapp_qr_checkin_validate_event_day($event_id);
    if (empty($day_validation['valid'])) {
        wp_send_json_error(['error' => $day_validation['error'] ?? 'El check-in solo está permitido en las fechas del evento.']);
    }

    $lookup = eventosapp_qr_find_ticket_by_scanned_code($scanned, $event_id);
    if ( empty($lookup['found']) || empty($lookup['ticket_id']) ) {
        wp_send_json_error(['error' => !empty($lookup['error']) ? $lookup['error'] : 'Ticket no encontrado para este evento.']);
    }

    $ticket_post_id = absint($lookup['ticket_id']);

    // Seguridad extra
    if ((int) get_post_meta($ticket_post_id, '_eventosapp_ticket_evento_id', true) !== (int) $event_id) {
        wp_send_json_error(['error'=>'El ticket no pertenece al evento activo']);
    }

    update_meta_cache('post', [$ticket_post_id]);

    // Datos
    $first = get_post_meta($ticket_post_id, '_eventosapp_asistente_nombre', true);
    $last  = get_post_meta($ticket_post_id, '_eventosapp_asistente_apellido', true);
    $comp  = get_post_meta($ticket_post_id, '_eventosapp_asistente_empresa', true);
    $role  = get_post_meta($ticket_post_id, '_eventosapp_asistente_cargo', true);
    $loc   = get_post_meta($ticket_post_id, '_eventosapp_asistente_localidad', true);

    // Acceso a sesión y estado
    $has_access = function_exists('eventosapp_ticket_tiene_acceso')
        ? eventosapp_ticket_tiene_acceso($ticket_post_id, $session)
        : false;

$checkin_ses = get_post_meta($ticket_post_id, '_eventosapp_ticket_checkin_sesiones', true);
    if (!is_array($checkin_ses)) $checkin_ses = [];
    $status = isset($checkin_ses[$session]) ? $checkin_ses[$session] : 'not_checked_in';

    wp_send_json_success([
        'ticket_id'       => $ticket_post_id,
        'full_name'       => trim($first.' '.$last),
        'company'         => $comp,
        'designation'     => $role,
        'localidad'       => $loc,
        'event_name'      => get_the_title($event_id),
        'session'         => $session,
        'has_access'      => (bool) $has_access,
        'session_status'  => $status,
        'session_status_label' => ($status==='checked_in' ? 'SI' : 'NO'),
    ]);
});


// === AJAX: Toggle (solo marca "checked_in" para la sesión; si ya estaba, avisa) ===
add_action('wp_ajax_eventosapp_qr_sesion_toggle', function(){
// 🔒 Seguridad por rol (feature: qr)
if ( ! is_user_logged_in() ) {
    wp_send_json_error(['error' => 'Debes iniciar sesión.'], 401);
}
if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('qr') ) {
    wp_send_json_error(['error' => 'Permisos insuficientes para Check-In con QR.'], 403);
}
    check_ajax_referer('eventosapp_qr_sesion','security');

    $ticket_id = isset($_POST['ticket_id']) ? absint($_POST['ticket_id']) : 0;
    $event_id  = isset($_POST['event_id'])  ? absint($_POST['event_id'])  : 0;
    $session   = isset($_POST['session'])   ? sanitize_text_field( wp_unslash($_POST['session']) ) : '';

    // 🔒 Forzar evento ACTIVO para no-admins
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $event_id !== $active ) {
            wp_send_json_error(['error' => 'Sin permisos para este evento.'], 403);
        }
    }


    if (!$ticket_id || !$event_id || !$session) wp_send_json_error(['error'=>'Datos incompletos']);

    $day_validation = eventosapp_qr_checkin_validate_event_day($event_id);
    if (empty($day_validation['valid'])) {
        wp_send_json_error(['error' => $day_validation['error'] ?? 'El check-in solo está permitido en las fechas del evento.']);
    }

    // Verificar pertenencia
    $ticket_event = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    if ($ticket_event !== (int) $event_id) wp_send_json_error(['error'=>'El ticket no pertenece al evento activo']);

    // ¿Tiene acceso a la sesión?
    $has_access = function_exists('eventosapp_ticket_tiene_acceso')
        ? eventosapp_ticket_tiene_acceso($ticket_id, $session)
        : false;
    if (!$has_access) wp_send_json_error(['error'=>'El ticket no tiene acceso a esta sesión.']);

    // Estado actual
    $checkin_ses = get_post_meta($ticket_id, '_eventosapp_ticket_checkin_sesiones', true);
    if (!is_array($checkin_ses)) $checkin_ses = [];
    $already = (isset($checkin_ses[$session]) && $checkin_ses[$session] === 'checked_in');

if ($already) {
        wp_send_json_success(['already'=>true]);
    }

    // Marcar como checked_in (idempotente)
    $checkin_ses[$session] = 'checked_in';
    update_post_meta($ticket_id, '_eventosapp_ticket_checkin_sesiones', $checkin_ses);

    // Log (reutilizamos _eventosapp_checkin_log con tipo 'session_checked_in')
    $log = get_post_meta($ticket_id, '_eventosapp_checkin_log', true);
    if (is_string($log)) $log = @unserialize($log);
    if (!is_array($log)) $log = [];

    $dt = (!empty($day_validation['datetime']) && $day_validation['datetime'] instanceof DateTime)
        ? $day_validation['datetime']
        : eventosapp_qr_checkin_event_datetime($event_id);
    $u = wp_get_current_user();
    $usuario = ($u && $u->exists()) ? ($u->display_name.' ('.$u->user_email.')') : 'Sistema';

    $log[] = [
        'fecha'   => $dt->format('Y-m-d'),
        'hora'    => $dt->format('H:i:s'),
        'dia'     => $dt->format('Y-m-d'),
        'status'  => 'session_checked_in',
        'sesion'  => $session,
        'usuario' => $usuario,
        'origen'  => 'qr_sesion',
    ];
update_post_meta($ticket_id, '_eventosapp_checkin_log', $log);

    wp_send_json_success(['already'=>false, 'now'=>'checked_in']);
});


/* ===========================================================
 * Shortcode público: [eventosapp_qr_contacto]
 * - Página pública (no requiere login ni evento activo)
 * - Lee QR (BarcodeDetector con fallback a jsQR)
 * - Muestra: Nombre y Apellido, Empresa, Cargo
 * - Botón "Agregar contacto" -> descarga .vcf (vCard 3.0)
 * =========================================================== */

add_shortcode('eventosapp_qr_contacto', function($atts){

    // Registrar jsQR (fallback) sólo si hace falta
    add_action('wp_enqueue_scripts', function(){
        if (!wp_script_is('jsqr', 'registered')) {
            wp_register_script('jsqr', 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js', [], null, true);
        }
    });

    $nonce = wp_create_nonce('eventosapp_qr_contacto');

    ob_start(); ?>
    <style>
      .evapp-qr-shell { max-width:560px; margin:0 auto; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
      .evapp-qr-card { background:#0b1020; color:#eaf1ff; border-radius:16px; padding:18px; box-shadow:0 8px 24px rgba(0,0,0,.15); }
      .evapp-qr-title { display:flex; align-items:center; gap:.6rem; margin:0 0 10px; font-weight:800; font-size:1.05rem; letter-spacing:.2px; }
      .evapp-qr-title svg { opacity:.9 }
      .evapp-qr-btn { display:flex; align-items:center; justify-content:center; gap:.5rem; border:0; border-radius:12px; padding:.9rem 1.1rem; font-weight:800; cursor:pointer; width:100%; background:#2563eb; color:#fff; transition:filter .15s,background .15s; }
      .evapp-qr-btn.is-live { background:#e04f5f; }
      .evapp-qr-btn:hover { filter:brightness(.98); }
      .evapp-qr-video-wrap { position:relative; margin-top:12px; border-radius:14px; overflow:hidden; background:#0a0f1d; aspect-ratio:3/4; }
      .evapp-qr-video { width:100%; height:100%; object-fit:cover; display:none; }
      .evapp-qr-frame { position:absolute; inset:0; pointer-events:none; display:none; }
      .evapp-qr-frame .mask { position:absolute; inset:0; background: radial-gradient(ellipse 60% 40% at 50% 50%, rgba(255,255,255,0) 62%, rgba(10,15,29,.55) 64%); }
      .evapp-qr-corner { position:absolute; width:44px; height:44px; border:4px solid #4f7cff; border-radius:10px; }
      .evapp-qr-corner.tl{top:16px;left:16px;border-right:0;border-bottom:0}
      .evapp-qr-corner.tr{top:16px;right:16px;border-left:0;border-bottom:0}
      .evapp-qr-corner.bl{bottom:16px;left:16px;border-right:0;border-top:0}
      .evapp-qr-corner.br{bottom:16px;right:16px;border-left:0;border-top:0}
      .evapp-qr-result { margin-top:14px; background:#0a0f1d; border:1px solid rgba(255,255,255,.06); border-radius:12px; padding:14px; }
      .evapp-qr-grid { display:grid; grid-template-columns: 1fr; gap:.2rem .8rem; margin-top:.4rem; }
      .evapp-qr-grid div b { color:#a7b8ff; font-weight:600; }
      @media(min-width:480px){ .evapp-qr-grid{ grid-template-columns:auto 1fr } .evapp-qr-grid div{ display:contents } .evapp-qr-grid b{ text-align:right } }
      .evapp-qr-help{ color:#a9b6d3; font-size:.9rem; opacity:.85 }
      .evapp-contact-btn{ margin-top:12px; width:100%; display:flex; gap:.5rem; align-items:center; justify-content:center; border:0; border-radius:10px; padding:.8rem 1rem; font-weight:800; background:#16a34a; color:#fff; cursor:pointer; }
      .evapp-contact-btn:hover{ filter:brightness(1.05); }
      .evapp-qr-bad{ color:#ff6b6b; font-weight:700; }
      .evapp-qr-video-wrap.is-immersive{ aspect-ratio:auto; height: calc(100vh - var(--evapp-offset, 56px)); width:100%; }
    </style>

    <div class="evapp-qr-shell" data-nonce="<?php echo esc_attr($nonce); ?>">
      <div class="evapp-qr-card">
        <div class="evapp-qr-title">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6zm14 0h2v6h-6v-2h4v-4z" stroke="#a7b8ff"/></svg>
          Lector de QR – Tarjeta de contacto
        </div>

        <button class="evapp-qr-btn" id="evappStartScanContact">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/><rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/></svg>
          Activar cámara y escanear
        </button>

        <div class="evapp-qr-video-wrap">
          <video id="evappVideoContact" class="evapp-qr-video" playsinline></video>
          <div class="evapp-qr-frame" id="evappFrameContact">
            <div class="mask"></div>
            <div class="evapp-qr-corner tl"></div>
            <div class="evapp-qr-corner tr"></div>
            <div class="evapp-qr-corner bl"></div>
            <div class="evapp-qr-corner br"></div>
          </div>
          <canvas id="evappCanvasContact" style="display:none;"></canvas>
        </div>

        <div class="evapp-qr-result" id="evappResultContact">
          <div class="evapp-qr-help">Escanea el QR de una escarapela para ver los datos del asistente y agregarlo como contacto.</div>
        </div>
      </div>
    </div>

    <script>
    (function(){
      const ajaxURL = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
      const nonce   = document.querySelector('.evapp-qr-shell')?.dataset.nonce || "";
      const btn   = document.getElementById('evappStartScanContact');
      const video = document.getElementById('evappVideoContact');
      const frame = document.getElementById('evappFrameContact');
      const cvs   = document.getElementById('evappCanvasContact');
      const ctx   = cvs.getContext('2d');
      const out   = document.getElementById('evappResultContact');
      const vwrap = video.closest('.evapp-qr-video-wrap') || video.parentElement;

      let stream=null, running=false, lastScan="", lastAt=0;
      let barcodeDetector = ('BarcodeDetector' in window) ? new window.BarcodeDetector({formats:['qr_code']}) : null;
      let lastData = null; // para construir VCF

      // Helpers de UI/scroll
      function getOffset(){ const ab=document.getElementById('wpadminbar'); return (ab?ab.offsetHeight:0) + 10; }
      function smoothScrollTo(el){ if(!el) return; const off=getOffset(); try{el.style.setProperty('--evapp-offset',off+'px')}catch(e){} const y=el.getBoundingClientRect().top + window.pageYOffset - off; window.scrollTo({top:y,behavior:'smooth'}); }
      function setLiveUI(on){
        if(on){ btn.classList.add('is-live'); btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6h12v12H6z" stroke="white"/></svg> Detener cámara'; }
        else { btn.classList.remove('is-live'); btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/><rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/></svg> Activar cámara y escanear'; }
      }
      function beep(){ try{const a=new Audio(); a.src='data:audio/mp3;base64,//uQxAAAAAAAAAAAAAAAAAAAAAAAWGlinZwAAAA8AAAACAAACcQAA'; a.play().catch(()=>{});}catch(e){} if(navigator.vibrate) navigator.vibrate(60); }
      function setOutput(html){ out.innerHTML = html; }
      function row(label,value){ return `<div><b>${label}:</b></div><div>${value || '-'}</div>`; }
      function normalizeRaw(raw){ 
        let s=String(raw||'').trim(); 
        // Si es una URL completa (badge/escarapela), devolverla sin modificar
        if(s.startsWith('http://') || s.startsWith('https://')) return s;
        // Para otros casos, aplicar normalización
        if(s.includes('/')) s = s.split('/').pop(); 
        s = s.replace(/\.(png|jpg|jpeg|pdf)$/i,'').replace(/-tn$/i,'').replace(/^#/,''); 
        return s; 
      }

      function stop(){
        running=false;
        if(stream) stream.getTracks().forEach(t=>t.stop());
        stream=null;
        video.style.display='none';
        frame.style.display='none';
        vwrap?.classList.remove('is-immersive');
        setLiveUI(false);
      }
      async function ensureJsQR(){
        if('BarcodeDetector' in window) return false;
        if(!window.jsQR){
          await new Promise((resolve)=>{ const s=document.createElement('script'); s.src='https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js'; s.onload=resolve; document.head.appendChild(s); });
        }
        return true;
      }
      async function start(){
        try{
          stream = await navigator.mediaDevices.getUserMedia({ video:{ facingMode:{ideal:'environment'} }, audio:false });
        }catch(e){
          setOutput('<div class="evapp-qr-bad">No se pudo acceder a la cámara.</div>');
          smoothScrollTo(out);
          return;
        }
        video.srcObject = stream;
        await video.play();
        video.style.display='block';
        frame.style.display='block';
        vwrap?.classList.add('is-immersive'); smoothScrollTo(vwrap);
        cvs.width = video.videoWidth || 640;
        cvs.height= video.videoHeight|| 480;
        running=true; setLiveUI(true); tick();
      }
      async function tick(){
        if(!running) return;
        ctx.drawImage(video,0,0,cvs.width,cvs.height);
        if(barcodeDetector){
          try{
            const bmp = await createImageBitmap(cvs);
            const codes = await barcodeDetector.detect(bmp);
            if(codes && codes.length){ onScan(normalizeRaw(codes[0].rawValue||'')); return; }
          }catch(e){}
        }else if(window.jsQR){
          const img = ctx.getImageData(0,0,cvs.width,cvs.height);
          const code= window.jsQR(img.data,img.width,img.height);
          if(code && code.data){ onScan(normalizeRaw(code.data)); return; }
        }
        requestAnimationFrame(tick);
      }
      function onScan(data){
        const now = Date.now();
        if(data===lastScan && (now-lastAt)<2500){ requestAnimationFrame(tick); return; }
        lastScan=data; lastAt=now; beep(); stop();
        setOutput('<div class="evapp-qr-help">Procesando…</div>'); smoothScrollTo(out);

        const fd = new FormData();
        fd.append('action','eventosapp_qr_contact_lookup');
        fd.append('security', nonce);
        fd.append('scanned', data);

        fetch(ajaxURL, { method:'POST', body:fd, credentials:'same-origin' })
          .then(r=>r.json())
          .then(resp=>{
            if(!resp || !resp.success){
              const msg = (resp && resp.data && resp.data.error) ? resp.data.error : 'No se encontró el asistente.';
              setOutput('<div class="evapp-qr-bad">'+msg+'</div>'); return;
            }
            const d = resp.data || {};
            lastData = d;

            let html = '<div class="evapp-qr-grid">';
            html += row('Nombre', d.full_name);
            html += row('Empresa', d.company);
            html += row('Cargo', d.designation);
            html += '</div>';

            html += `<button class="evapp-contact-btn" id="evappDownloadVCF" type="button" aria-label="Agregar contacto">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                <path d="M12 5v8m0 0l3-3m-3 3L9 10M4 19h16" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              Agregar contacto
            </button>`;

            setOutput(html); smoothScrollTo(out);

            const btnVcf = document.getElementById('evappDownloadVCF');
            if(btnVcf){
              btnVcf.addEventListener('click', ()=>{
                const vcf = buildVCF(d);
                const blob = new Blob([vcf], { type: 'text/vcard;charset=utf-8' });
                const a = document.createElement('a');
                const fn = (d.full_name || 'contacto').replace(/[^\w\s\-\.]+/g,'').trim() || 'contacto';
                a.href = URL.createObjectURL(blob);
                a.download = fn + '.vcf';
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(()=>URL.revokeObjectURL(a.href), 1500);
              });
            }
          })
          .catch(()=>{
            setOutput('<div class="evapp-qr-bad">Error de red al consultar el contacto.</div>');
          });
      }

      // Construye VCF 3.0 con saltos CRLF y escapado correcto
      function buildVCF(d){
        function esc(v){ return String(v||'').replace(/\\/g,'\\\\').replace(/\n/g,'\\n').replace(/,/g,'\\,').replace(/;/g,'\\;'); }
        const first = (d.first_name || '').trim();
        const last  = (d.last_name  || '').trim();
        const fn    = (d.full_name  || (first + (last?' '+last:''))).trim();
        const org   = esc(d.company || '');
        const title = esc(d.designation || '');
        const email = (d.email || '').trim();
        const phone = (d.phone || '').trim();

        const CRLF = '\r\n';
        let v = '';
        v += 'BEGIN:VCARD'+CRLF;
        v += 'VERSION:3.0'+CRLF;
        v += 'N:'+esc(last)+';'+esc(first)+';;;'+CRLF;
        v += 'FN:'+esc(fn)+CRLF;
        v += 'X-ABShowAs:Person'+CRLF;
        if(org)   v += 'ORG:'+org+CRLF;
        if(title) v += 'TITLE:'+title+CRLF;
        if(email) v += 'EMAIL;TYPE=INTERNET:'+email+CRLF;
        if(phone) v += 'TEL;TYPE=CELL:'+phone+CRLF;
        v += 'END:VCARD'+CRLF;
        return v;
      }

      // Botón principal: toggle cámara
      btn.addEventListener('click', async ()=>{
        if(stream && stream.active){
          stop();
          setOutput('<div class="evapp-qr-help">Cámara detenida. Haz clic para volver a escanear.</div>');
          smoothScrollTo(out);
          return;
        }
        await ensureJsQR();
        await start();
        setOutput('<div class="evapp-qr-help">Apunta al QR de la escarapela.</div>');
      });
    })();
    </script>
    <?php
    return ob_get_clean();
});


/* ===========================================================
 * AJAX público: eventosapp_qr_contact_lookup
 * - Busca ticket por meta 'eventosapp_ticketID' o 'eventosapp_ticket_preprintedID'
 * - Devuelve datos de contacto (sin requerir sesión)
 * =========================================================== */
add_action('wp_ajax_nopriv_eventosapp_qr_contact_lookup', 'eventosapp_qr_contact_lookup_cb');
add_action('wp_ajax_eventosapp_qr_contact_lookup',       'eventosapp_qr_contact_lookup_cb');

function eventosapp_qr_contact_lookup_cb(){
    check_ajax_referer('eventosapp_qr_contacto','security');

    $raw = isset($_POST['scanned']) ? wp_unslash($_POST['scanned']) : '';
    $raw = sanitize_text_field($raw);
    if (!$raw) wp_send_json_error(['error'=>'QR vacío o inválido.']);

    // Normalización suave por si llega con path o extensión
    $scanned = trim($raw);
    if (strpos($scanned, '/') !== false) {
        $parts = explode('/', $scanned);
        $scanned = end($parts);
    }
    $scanned = preg_replace('/\.(png|jpg|jpeg|pdf)$/i','', $scanned);
    $scanned = preg_replace('/-tn$/i','', $scanned);
    $scanned = ltrim($scanned, '#');

    global $wpdb;

    $ticket_post_id = 0;

    // 1) Intento SIEMPRE permitido: buscar por ID público del sistema
    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s LIMIT 1",
        'eventosapp_ticketID', $scanned
    ) );
    if ($ids && !empty($ids[0])) {
        $ticket_post_id = (int) $ids[0];
    }

    // 2) Si no hay match y el código es numérico, intentar PREIMPRESO
    //    PERO solo aceptar si el evento del ticket tiene habilitado:
    //    _eventosapp_ticket_use_preprinted_qr_networking = '1'
    if (!$ticket_post_id) {
        $scan_num = preg_replace('/\D+/', '', $scanned);
        if ($scan_num !== '') {
            $ids2 = $wpdb->get_col( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s LIMIT 50",
                'eventosapp_ticket_preprintedID', $scan_num
            ) );

            if ($ids2) {
                foreach ($ids2 as $cand) {
                    $event_id = (int) get_post_meta($cand, '_eventosapp_ticket_evento_id', true);
                    $net_on   = (get_post_meta($event_id, '_eventosapp_ticket_use_preprinted_qr_networking', true) === '1');
                    if ($net_on) { $ticket_post_id = (int) $cand; break; }
                }
            }
        }
    }

    if (!$ticket_post_id) {
        wp_send_json_error(['error'=>'No se encontró el asistente para ese QR.']);
    }

    // Metas de contacto (igual que antes)
    $first = get_post_meta($ticket_post_id, '_eventosapp_asistente_nombre', true);
    $last  = get_post_meta($ticket_post_id, '_eventosapp_asistente_apellido', true);
    $comp  = get_post_meta($ticket_post_id, '_eventosapp_asistente_empresa', true);
    $role  = get_post_meta($ticket_post_id, '_eventosapp_asistente_cargo', true);

    $email = get_post_meta($ticket_post_id, '_eventosapp_asistente_email', true);
    if (!$email) $email = get_post_meta($ticket_post_id, '_eventosapp_asistente_correo', true);

    $phone = get_post_meta($ticket_post_id, '_eventosapp_asistente_tel', true);
    if (!$phone) $phone = get_post_meta($ticket_post_id, '_eventosapp_asistente_telefono', true);
    if (!$phone) $phone = get_post_meta($ticket_post_id, '_eventosapp_asistente_cel', true);
if (!$phone) $phone = get_post_meta($ticket_post_id, '_eventosapp_asistente_celular', true);

    wp_send_json_success([
        'ticket_id'   => $ticket_post_id,
        'first_name'  => $first,
        'last_name'   => $last,
        'full_name'   => trim($first.' '.$last),
        'company'     => $comp,
        'designation' => $role,
        'email'       => $email,
        'phone'       => $phone,
    ]);
}

/**
 * ========================================================================
 * FUNCIÓN AJAX: Enviar recordatorio de pago por correo
 * ========================================================================
 * 
 * UBICACIÓN: Al final del archivo eventosapp-qr-checkin.php
 * JUSTO ANTES del cierre: ?>
 */
add_action('wp_ajax_eventosapp_send_payment_reminder', 'eventosapp_send_payment_reminder_callback');
function eventosapp_send_payment_reminder_callback() {
    // Verificar nonce
    check_ajax_referer('eventosapp_qr_checkin', 'security');
    
    // Verificar que el usuario tenga permisos
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Debes iniciar sesión.'], 401);
    }
    
    if (!function_exists('eventosapp_role_can') || !eventosapp_role_can('qr')) {
        wp_send_json_error(['message' => 'Permisos insuficientes.'], 403);
    }
    
    // Obtener ticket ID
    $ticket_id = isset($_POST['ticket_id']) ? absint($_POST['ticket_id']) : 0;
    
    if (!$ticket_id) {
        wp_send_json_error(['message' => 'ID de ticket no válido.']);
    }
    
    // Verificar que el ticket existe
    $ticket = get_post($ticket_id);
    if (!$ticket || $ticket->post_type !== 'eventosapp_ticket') {
        wp_send_json_error(['message' => 'Ticket no encontrado.']);
    }
    
    // Obtener datos del ticket
    $asistente_email = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
    $asistente_nombre = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
    $asistente_apellido = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
    $nombre_completo = trim($asistente_nombre . ' ' . $asistente_apellido);
    
    if (!$asistente_email || !is_email($asistente_email)) {
        wp_send_json_error(['message' => 'El ticket no tiene un correo electrónico válido.']);
    }
    
    // Obtener datos del evento
    $evento_id = get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    $evento_nombre = $evento_id ? get_the_title($evento_id) : 'tu evento';
    
    // Preparar el correo
    $to = $asistente_email;
    $subject = '💳 Recordatorio de Pago - ' . $evento_nombre;
    
    // Construir el cuerpo del correo en HTML
    $message = '
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Recordatorio de Pago</title>
    </head>
    <body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background-color:#f5f5f5;">
        <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f5;padding:20px 0;">
            <tr>
                <td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.1);overflow:hidden;">
                        <!-- Header -->
                        <tr>
                            <td style="background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);padding:30px 40px;text-align:center;">
                                <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:700;">💳 Recordatorio de Pago</h1>
                            </td>
                        </tr>
                        
                        <!-- Body -->
                        <tr>
                            <td style="padding:40px;">
                                <p style="margin:0 0 20px;color:#333333;font-size:16px;line-height:1.6;">
                                    Hola <strong>' . esc_html($nombre_completo) . '</strong>,
                                </p>
                                
                                <p style="margin:0 0 20px;color:#555555;font-size:15px;line-height:1.6;">
                                    Hemos detectado que tu ticket para <strong>' . esc_html($evento_nombre) . '</strong> aún no ha sido pagado.
                                </p>
                                
                                <p style="margin:0 0 30px;color:#555555;font-size:15px;line-height:1.6;">
                                    Para completar tu registro y poder realizar el check-in en el evento, es necesario que realices el pago de tu ticket.
                                </p>
                                
                                <!-- Botón de Pago -->
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td align="center" style="padding:10px 0 30px;">
                                            <a href="https://www.pse.com.co/persona" 
                                               style="display:inline-block;background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);color:#ffffff;text-decoration:none;padding:16px 40px;border-radius:8px;font-weight:700;font-size:16px;box-shadow:0 4px 12px rgba(102,126,234,0.3);">
                                                💳 Realizar Pago con PSE
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                                
                                <div style="background-color:#f8f9fa;border-left:4px solid #667eea;padding:16px 20px;margin:20px 0;border-radius:4px;">
                                    <p style="margin:0;color:#555555;font-size:14px;line-height:1.5;">
                                        <strong>Nota importante:</strong> Una vez completado el pago, tu ticket será actualizado automáticamente y podrás realizar el check-in sin inconvenientes.
                                    </p>
                                </div>
                                
                                <p style="margin:20px 0 0;color:#555555;font-size:14px;line-height:1.6;">
                                    Si tienes alguna pregunta o necesitas asistencia, no dudes en contactarnos.
                                </p>
                            </td>
                        </tr>
                        
                        <!-- Footer -->
                        <tr>
                            <td style="background-color:#f8f9fa;padding:24px 40px;text-align:center;border-top:1px solid #e9ecef;">
                                <p style="margin:0 0 8px;color:#6c757d;font-size:13px;">
                                    Este es un correo automático, por favor no respondas a este mensaje.
                                </p>
                                <p style="margin:0;color:#6c757d;font-size:12px;">
                                    © ' . date('Y') . ' EventosApp. Todos los derechos reservados.
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    ';
    
    // Headers para HTML
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: EventosApp <noreply@eventosapp.com>'
    );
    
    // Intentar enviar el correo
    $sent = wp_mail($to, $subject, $message, $headers);
    
    if ($sent) {
        // Registrar el envío en el log del ticket
        $log = get_post_meta($ticket_id, '_eventosapp_payment_reminder_log', true);
        if (!is_array($log)) {
            $log = array();
        }
        
        $user = wp_get_current_user();
        $log[] = array(
            'fecha' => current_time('mysql'),
            'email' => $asistente_email,
            'enviado_por' => $user && $user->exists() ? $user->display_name : 'Sistema',
            'usuario_id' => $user && $user->exists() ? $user->ID : 0
        );
        
        update_post_meta($ticket_id, '_eventosapp_payment_reminder_log', $log);
        
error_log("EventosApp: Recordatorio de pago enviado al ticket ID $ticket_id - Email: $asistente_email");

        wp_send_json_success([
            'message' => 'Correo enviado exitosamente',
            'email' => $asistente_email,
            'ticket_id' => $ticket_id
        ]);
    } else {
        error_log("EventosApp: Error al enviar recordatorio de pago al ticket ID $ticket_id - Email: $asistente_email");
        
        wp_send_json_error([
            'message' => 'No se pudo enviar el correo. Por favor, intenta nuevamente.'
        ]);
    }
}

/**
 * ========================================================================
 * AJAX: Registrar acompañantes sin QR vinculados a un ticket de check-in
 * ========================================================================
 * Meta guardada: _eventosapp_ticket_acompanantes_sin_qr (total acumulado)
 * Log guardado:  _eventosapp_ticket_acompanantes_log    (historial)
 */
add_action('wp_ajax_eventosapp_registrar_acompanantes', 'eventosapp_registrar_acompanantes_callback');
function eventosapp_registrar_acompanantes_callback() {
    // Verificar nonce dedicado
    check_ajax_referer('eventosapp_registrar_acompanantes', 'companion_nonce');

    // Verificar login y permisos mínimos
    if ( ! is_user_logged_in() ) {
        wp_send_json_error(['message' => 'Debes iniciar sesión.'], 401);
    }
    $can_qr     = function_exists('eventosapp_role_can') && eventosapp_role_can('qr');
    $can_search = function_exists('eventosapp_role_can') && eventosapp_role_can('search');
    if ( ! $can_qr && ! $can_search ) {
        wp_send_json_error(['message' => 'Permisos insuficientes.'], 403);
    }

    $ticket_id = isset($_POST['ticket_id']) ? absint($_POST['ticket_id']) : 0;
    $cantidad  = isset($_POST['cantidad'])  ? absint($_POST['cantidad'])  : 0;

    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        wp_send_json_error(['message' => 'Ticket inválido.']);
    }
    if ( $cantidad < 0 || $cantidad > 500 ) {
        wp_send_json_error(['message' => 'Cantidad inválida (0–500).']);
    }

    // Acumular total en el meta del ticket
    $current_total = (int) get_post_meta($ticket_id, '_eventosapp_ticket_acompanantes_sin_qr', true);
    $new_total     = $current_total + $cantidad;
    update_post_meta($ticket_id, '_eventosapp_ticket_acompanantes_sin_qr', $new_total);

    // Log del registro
    $log = get_post_meta($ticket_id, '_eventosapp_ticket_acompanantes_log', true);
    if ( ! is_array($log) ) $log = [];
    $user = wp_get_current_user();
    $log[] = [
        'fecha'    => current_time('Y-m-d'),
        'hora'     => current_time('H:i:s'),
        'cantidad' => $cantidad,
        'total'    => $new_total,
        'usuario'  => ( $user && $user->exists() ) ? $user->display_name . ' (' . $user->user_email . ')' : 'Sistema',
    ];
    update_post_meta($ticket_id, '_eventosapp_ticket_acompanantes_log', $log);

    error_log("EventosApp: Acompañantes sin QR registrados — Ticket ID $ticket_id, Cantidad: $cantidad, Total acumulado: $new_total");

    wp_send_json_success([
        'message'   => 'Acompañantes registrados correctamente.',
        'cantidad'  => $cantidad,
        'total'     => $new_total,
        'ticket_id' => $ticket_id,
    ]);
}
