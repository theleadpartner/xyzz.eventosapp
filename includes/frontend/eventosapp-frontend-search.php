<?php
/**
 * Frontend: buscador y gestión rápida de tickets
 * - Shortcode [eventosapp_front_search event_id="123"]
 * - Se integra con el evento activo elegido en el dashboard.
 * - UI alineada con el Dashboard / Registro Manual / Check-In QR de EventosApp.
 * - AJAX search + toggle check-in + acompañantes + impresión de escarapela.
 * - Conserva compatibilidad con EventosApp Android Printer Bridge.
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Helpers de fecha (TZ del evento)
 */
if ( ! function_exists('eventosapp_get_today_in_event_tz') ) {
    function eventosapp_get_today_in_event_tz( $event_id ) {
        $event_tz = get_post_meta($event_id, '_eventosapp_zona_horaria', true);
        if ( ! $event_tz ) {
            $event_tz = wp_timezone_string();
            if ( ! $event_tz || $event_tz === 'UTC' ) {
                $offset = get_option('gmt_offset');
                $event_tz = $offset ? timezone_name_from_abbr('', $offset * 3600, 0) ?: 'UTC' : 'UTC';
            }
        }

        try {
            $dt = new DateTime('now', new DateTimeZone($event_tz));
        } catch (Exception $e) {
            $dt = new DateTime('now', wp_timezone());
        }

        return $dt->format('Y-m-d');
    }
}

if ( ! function_exists('eventosapp_is_today_valid_for_event') ) {
    function eventosapp_is_today_valid_for_event( $event_id ) {
        $today = eventosapp_get_today_in_event_tz($event_id);
        $days  = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days($event_id) : [];
        return (!empty($days) && in_array($today, $days, true));
    }
}

/**
 * Shortcode contenedor
 * Uso: [eventosapp_front_search event_id="123"]
 */
add_shortcode('eventosapp_front_search', function($atts){
    if ( function_exists('eventosapp_require_feature') ) {
        eventosapp_require_feature('search');
    }

    $a   = shortcode_atts(['event_id' => 0], $atts);
    $eid = absint($a['event_id']);

    if ( ! $eid && function_exists('eventosapp_get_active_event') ) {
        $eid = (int) eventosapp_get_active_event();
    }

    if ( ! $eid ) {
        $dashboard_url = function_exists('eventosapp_get_dashboard_url')
            ? eventosapp_get_dashboard_url()
            : home_url('/dashboard/');

        if ( function_exists('eventosapp_require_active_event') ) {
            eventosapp_require_active_event();
            return '';
        }

        return '<div style="max-width:900px;margin:12px auto;padding:14px 16px;background:#fff8e6;border:1px solid #f1dfad;border-left:4px solid #a16207;border-radius:14px;color:#7c4a03;font-family:inherit;line-height:1.5;">
            Debes escoger un <strong>evento</strong> para gestionar. Ve al <a href="'.esc_url($dashboard_url).'" style="color:#255f96;font-weight:700;">dashboard</a>, selecciónalo y vuelve aquí.
        </div>';
    }

    if ( $eid && ! eventosapp_user_can_manage_event($eid) && ! current_user_can('manage_options') ) {
        return '<div style="max-width:900px;margin:12px auto;padding:14px 16px;background:#fff1f1;border:1px solid #f4caca;border-left:4px solid #c53a3a;border-radius:14px;color:#9b2c2c;font-family:inherit;line-height:1.5;">No tienes permisos sobre este evento.</div>';
    }

    $dashboard_url = function_exists('eventosapp_get_dashboard_url')
        ? eventosapp_get_dashboard_url()
        : home_url('/');
    $dashboard_url = remove_query_arg(['evapp', 'evapp_err', 'set'], $dashboard_url);
    $change_event_url = add_query_arg(['evapp' => 'change_event'], $dashboard_url);

    $event_name = get_the_title($eid) ?: ('Evento #' . $eid);
    $event_modalidad_label = function_exists('eventosapp_get_event_modalidad_label')
        ? eventosapp_get_event_modalidad_label($eid)
        : '';
    $today_iso = eventosapp_get_today_in_event_tz($eid);
    $today_allowed = eventosapp_is_today_valid_for_event($eid);
    $today_label = $today_iso ? date_i18n('D, d M Y', strtotime($today_iso)) : '';

    wp_enqueue_script('jquery');
    wp_register_script('eventosapp-front-search', '', ['jquery'], null, true);

    wp_localize_script('eventosapp-front-search', 'EvFrontSearch', [
        'ajax_url'           => admin_url('admin-ajax.php'),
        'search_nonce'       => wp_create_nonce('eventosapp_front_search'),
        'toggle_nonce'       => wp_create_nonce('eventosapp_toggle_checkin'),
        'print_nonce'        => wp_create_nonce('eventosapp_render_badge'),
        'acompanantes_nonce' => wp_create_nonce('eventosapp_registrar_acompanantes'),
        'event_id'           => $eid,
        'msgs'               => [
            'not_allowed'  => __('El check-in solo está permitido en las fechas del evento. Hoy no corresponde.', 'eventosapp'),
            'net_error'    => __('Error de red. Intenta de nuevo.', 'eventosapp'),
            'search_error' => __('No fue posible completar la búsqueda. Intenta nuevamente.', 'eventosapp'),
        ]
    ]);

    $css = <<<CSS
.evfs-app{
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
  --evapp-purple:#6d4bc3;
  --evapp-purple-soft:#f3efff;
  --evapp-radius:18px;
  --evapp-radius-lg:26px;
  width:100%;
  max-width:1180px;
  margin:0 auto;
  color:var(--evapp-text);
  font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
  line-height:1.45;
  box-sizing:border-box;
}
.evfs-app *,
.evfs-app *::before,
.evfs-app *::after{box-sizing:border-box}
.evfs-app a{text-decoration:none}
.evfs-app .screen-reader-text{
  position:absolute!important;
  width:1px!important;
  height:1px!important;
  padding:0!important;
  margin:-1px!important;
  overflow:hidden!important;
  clip:rect(0,0,0,0)!important;
  white-space:nowrap!important;
  border:0!important;
}
.evfs-shell{
  width:100%;
  padding:clamp(18px,3vw,36px);
  background:var(--evapp-app-bg);
  border:1px solid var(--evapp-border);
  border-radius:var(--evapp-radius-lg);
  box-shadow:0 18px 50px rgba(31,65,99,.08);
}
.evfs-header{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:24px;
  margin-bottom:22px;
}
.evfs-heading{min-width:0}
.evfs-eyebrow{
  margin:0 0 7px;
  color:var(--evapp-primary);
  font-size:12px;
  font-weight:800;
  letter-spacing:.15em;
  text-transform:uppercase;
}
.evfs-main-title{
  margin:0;
  color:var(--evapp-text);
  font-size:clamp(27px,4vw,42px);
  font-weight:800;
  line-height:1.08;
  letter-spacing:-.035em;
}
.evfs-subtitle{
  max-width:760px;
  margin:10px 0 0;
  color:var(--evapp-muted);
  font-size:15px;
  line-height:1.6;
}
.evfs-btn{
  min-height:44px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:9px;
  margin:0;
  padding:10px 15px;
  border:1px solid transparent;
  border-radius:12px;
  font:inherit;
  font-size:14px;
  font-weight:750;
  line-height:1.15;
  text-align:center;
  cursor:pointer;
  transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease,background .16s ease,color .16s ease,opacity .16s ease;
  -webkit-tap-highlight-color:transparent;
}
.evfs-btn svg{
  width:18px;
  height:18px;
  flex:0 0 18px;
  fill:none;
  stroke:currentColor;
  stroke-width:2;
  stroke-linecap:round;
  stroke-linejoin:round;
}
.evfs-btn:hover:not(:disabled){transform:translateY(-1px)}
.evfs-btn:focus-visible{outline:3px solid rgba(50,121,189,.22);outline-offset:2px}
.evfs-btn:disabled{opacity:.55;cursor:not-allowed;transform:none!important;box-shadow:none!important}
.evfs-btn-secondary{
  background:var(--evapp-surface);
  border-color:var(--evapp-border);
  color:var(--evapp-text)!important;
  box-shadow:0 5px 15px rgba(31,65,99,.05);
  white-space:nowrap;
}
.evfs-btn-secondary:hover{border-color:#c7d7e8;color:var(--evapp-primary-dark)!important;box-shadow:0 8px 20px rgba(31,65,99,.09)}
.evfs-btn-primary{
  background:var(--evapp-primary);
  border-color:var(--evapp-primary);
  color:#fff!important;
  box-shadow:0 9px 20px rgba(50,121,189,.18);
}
.evfs-btn-primary:hover{background:var(--evapp-primary-dark);border-color:var(--evapp-primary-dark);box-shadow:0 12px 24px rgba(50,121,189,.24)}
.evfs-event-context{
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
.evfs-event-main{min-width:0;display:flex;align-items:center;gap:13px}
.evfs-event-icon{
  width:44px;
  height:44px;
  flex:0 0 44px;
  display:grid;
  place-items:center;
  color:var(--evapp-primary);
  background:var(--evapp-primary-soft);
  border-radius:13px;
}
.evfs-event-icon svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.evfs-event-copy{min-width:0}
.evfs-event-kicker{
  display:block;
  margin-bottom:3px;
  color:var(--evapp-muted);
  font-size:11px;
  font-weight:800;
  letter-spacing:.09em;
  text-transform:uppercase;
}
.evfs-event-name{
  display:block;
  overflow:hidden;
  color:var(--evapp-text);
  font-size:15px;
  font-weight:800;
  line-height:1.3;
  text-overflow:ellipsis;
  white-space:nowrap;
}
.evfs-event-meta{display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:8px}
.evfs-chip{
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
.evfs-chip::before{width:7px;height:7px;border-radius:50%;background:#94a3b8;content:""}
.evfs-chip.is-valid{color:var(--evapp-success);border-color:#cfeadf;background:var(--evapp-success-soft)}
.evfs-chip.is-valid::before{background:var(--evapp-success)}
.evfs-chip.is-warning{color:var(--evapp-warning);border-color:#f1dfad;background:var(--evapp-warning-soft)}
.evfs-chip.is-warning::before{background:#d69e2e}
.evfs-search-card{
  padding:18px;
  background:var(--evapp-surface);
  border:1px solid var(--evapp-border);
  border-radius:var(--evapp-radius);
  box-shadow:0 8px 26px rgba(31,65,99,.05);
}
.evfs-search-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px}
.evfs-search-title{margin:0;color:var(--evapp-text);font-size:17px;font-weight:800;line-height:1.3}
.evfs-search-description{margin:5px 0 0;color:var(--evapp-muted);font-size:13px;line-height:1.5}
.evfs-searchbar{display:grid;grid-template-columns:minmax(190px,245px) minmax(0,1fr);gap:10px;align-items:center}
.evfs-select,
.evfs-input{
  width:100%;
  min-height:48px;
  margin:0;
  color:var(--evapp-text);
  background:#fff;
  border:1px solid var(--evapp-border);
  border-radius:13px;
  font:inherit;
  font-size:15px;
  outline:none;
  transition:border-color .16s ease,box-shadow .16s ease;
}
.evfs-select{padding:10px 38px 10px 13px}

/*
 * El input de búsqueda necesita una regla más específica porque algunos themes
 * (incluido Elementor/Hello y estilos globales de formularios) declaran
 * input[type="search"] con mayor especificidad y pueden sobrescribir el padding.
 * El padding izquierdo reservado evita que el texto se monte sobre la lupa.
 */
.evfs-input{padding:10px 46px 10px 46px}

.evfs-app input.evfs-input[type="search"]{
  padding:10px 46px 10px 46px!important;
  -webkit-appearance:none;
  appearance:none;
}

.evfs-app input.evfs-input[type="search"]::-webkit-search-decoration,
.evfs-app input.evfs-input[type="search"]::-webkit-search-cancel-button,
.evfs-app input.evfs-input[type="search"]::-webkit-search-results-button,
.evfs-app input.evfs-input[type="search"]::-webkit-search-results-decoration{
  -webkit-appearance:none;
  appearance:none;
}

.evfs-input-wrap{position:relative;min-width:0}

.evfs-search-icon{
  position:absolute;
  z-index:2;
  top:50%;
  left:15px;
  width:20px;
  height:20px;
  color:var(--evapp-muted);
  transform:translateY(-50%);
  pointer-events:none;
}

.evfs-clear{
  position:absolute;
  z-index:3;
  top:50%;
  right:8px;
  width:34px;
  height:34px;
}
.evfs-search-icon svg{width:20px;height:20px;display:block;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round}
.evfs-clear{
  position:absolute;
  top:50%;
  right:8px;
  width:34px;
  height:34px;
  display:none;
  align-items:center;
  justify-content:center;
  padding:0;
  border:0;
  border-radius:9px;
  color:var(--evapp-muted);
  background:transparent;
  font-size:22px;
  line-height:1;
  cursor:pointer;
  transform:translateY(-50%);
}
.evfs-clear.is-visible{display:flex}
.evfs-clear:hover{color:var(--evapp-text);background:var(--evapp-primary-soft)}
.evfs-search-foot{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
  margin-top:10px;
  color:var(--evapp-muted);
  font-size:12px;
  line-height:1.45;
}
.evfs-result-count{font-weight:750;white-space:nowrap}
.evfs-results{margin-top:16px}
.evfs-state{
  min-height:180px;
  display:flex;
  align-items:center;
  justify-content:center;
  flex-direction:column;
  gap:9px;
  padding:28px 20px;
  color:var(--evapp-muted);
  background:rgba(255,255,255,.7);
  border:1px dashed #cbd8e6;
  border-radius:var(--evapp-radius);
  text-align:center;
}
.evfs-state-icon{
  width:46px;
  height:46px;
  display:grid;
  place-items:center;
  color:var(--evapp-primary);
  background:var(--evapp-primary-soft);
  border-radius:14px;
}
.evfs-state-icon svg{width:23px;height:23px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.evfs-state strong{color:var(--evapp-text);font-size:15px}
.evfs-state span{max-width:520px;font-size:13px;line-height:1.55}
.evfs-spinner{
  width:28px;
  height:28px;
  border:3px solid #dce8f3;
  border-top-color:var(--evapp-primary);
  border-radius:50%;
  animation:evfsSpin .7s linear infinite;
}
@keyframes evfsSpin{to{transform:rotate(360deg)}}
.evfs-row{
  display:grid;
  grid-template-columns:minmax(0,1fr) minmax(220px,255px);
  gap:18px;
  align-items:start;
  margin-bottom:12px;
  padding:18px;
  background:var(--evapp-surface);
  border:1px solid var(--evapp-border);
  border-radius:var(--evapp-radius);
  box-shadow:0 8px 24px rgba(31,65,99,.05);
  transition:border-color .16s ease,box-shadow .16s ease,transform .16s ease;
}
.evfs-row:hover{border-color:#c9d9e8;box-shadow:0 12px 30px rgba(31,65,99,.085);transform:translateY(-1px)}
.evfs-data{min-width:0;display:grid;grid-template-columns:auto minmax(0,1fr);gap:14px}
.evfs-avatar{
  width:48px;
  height:48px;
  display:grid;
  place-items:center;
  flex:0 0 48px;
  color:var(--evapp-primary-dark);
  background:var(--evapp-primary-soft);
  border:1px solid #d6e8f8;
  border-radius:15px;
  font-size:14px;
  font-weight:850;
  letter-spacing:.02em;
  text-transform:uppercase;
}
.evfs-data-main{min-width:0}
.evfs-person-head{display:flex;align-items:center;flex-wrap:wrap;gap:8px 10px;margin-bottom:8px}
.evfs-person-name{margin:0;color:var(--evapp-text);font-size:17px;font-weight:820;line-height:1.3;word-break:break-word}
.evfs-mode-badge{
  display:inline-flex;
  align-items:center;
  min-height:25px;
  padding:4px 8px;
  border-radius:999px;
  color:var(--evapp-primary-dark);
  background:var(--evapp-primary-soft);
  font-size:11px;
  font-weight:800;
  white-space:nowrap;
}
.evfs-mode-badge.is-virtual{color:#5b3aa7;background:var(--evapp-purple-soft)}
.evfs-info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 16px}
.evfs-info-item{min-width:0;color:var(--evapp-muted);font-size:12px;line-height:1.4}
.evfs-info-item strong{display:block;margin-bottom:2px;color:#475569;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.evfs-info-value{display:block;overflow-wrap:anywhere;color:var(--evapp-text);font-size:13px;font-weight:600}
.evfs-info-item.is-wide{grid-column:1/-1}
.evfs-actions{position:relative;display:grid;gap:9px;align-content:start}
.evfs-actions .evfs-btn{width:100%}
.evfs-check{background:var(--evapp-primary);border-color:var(--evapp-primary);color:#fff;box-shadow:0 9px 20px rgba(50,121,189,.18)}
.evfs-check:hover:not(:disabled){background:var(--evapp-primary-dark);border-color:var(--evapp-primary-dark);box-shadow:0 12px 24px rgba(50,121,189,.23)}
.evfs-check[aria-checked="true"],
.evfs-check.is-checked{background:var(--evapp-success);border-color:var(--evapp-success);box-shadow:0 9px 20px rgba(22,133,91,.17)}
.evfs-check[aria-checked="true"]:hover:not(:disabled),
.evfs-check.is-checked:hover:not(:disabled){background:#11704d;border-color:#11704d}
.evfs-print{background:#fff;border-color:#c7d8e8;color:var(--evapp-primary-dark);box-shadow:none}
.evfs-print:hover:not(:disabled){background:var(--evapp-primary-soft);border-color:#b9d3e9;box-shadow:none}
.evfs-virtual-badge{
  min-height:54px;
  display:flex;
  flex-direction:column;
  align-items:flex-start;
  justify-content:center;
  gap:2px;
  padding:10px 12px;
  color:#5b3aa7;
  background:var(--evapp-purple-soft);
  border:1px solid #ddd2fb;
  border-radius:12px;
  font-size:12px;
  font-weight:800;
  line-height:1.25;
}
.evfs-virtual-badge small{color:#735db2;font-size:11px;font-weight:650;line-height:1.3}
.evfs-virtual-badge.is-checked{color:var(--evapp-success);background:var(--evapp-success-soft);border-color:#cfeadf}
.evfs-virtual-badge.is-checked small{color:#3f7d67}
.evfs-checkin-status{
  display:inline-flex;
  align-items:center;
  gap:6px;
  margin-top:10px;
  color:var(--evapp-muted);
  font-size:11px;
  font-weight:750;
}
.evfs-checkin-status::before{width:7px;height:7px;border-radius:50%;background:#94a3b8;content:""}
.evfs-checkin-status.is-checked{color:var(--evapp-success)}
.evfs-checkin-status.is-checked::before{background:var(--evapp-success)}
.evfs-checkin-status.is-disabled{color:var(--evapp-warning)}
.evfs-checkin-status.is-disabled::before{background:#d69e2e}
.evfs-note{
  position:absolute;
  right:0;
  bottom:-8px;
  z-index:4;
  width:min(320px,100%);
  padding:9px 11px;
  color:#9b2c2c;
  background:var(--evapp-danger-soft);
  border:1px solid #f4caca;
  border-radius:10px;
  box-shadow:0 10px 24px rgba(80,30,30,.10);
  font-size:12px;
  line-height:1.4;
  transform:translateY(100%);
}
.evfs-note.ok{color:var(--evapp-success);background:var(--evapp-success-soft);border-color:#cfeadf}
.evfs-acomp-wrapper{margin:-3px 0 12px}
.evfs-acomp-panel{
  width:100%;
  padding:14px;
  background:var(--evapp-primary-soft);
  border:1px solid #cce0f3;
  border-radius:15px;
}
.evfs-acomp-label{margin-bottom:9px;color:var(--evapp-primary-dark);font-size:13px;font-weight:800}
.evfs-acomp-row{display:flex;gap:8px;align-items:center}
.evfs-acomp-input{
  flex:0 0 92px;
  min-height:44px;
  width:92px;
  margin:0;
  padding:8px 10px;
  color:var(--evapp-text);
  background:#fff;
  border:1px solid #bdd4e9;
  border-radius:11px;
  font:inherit;
  font-size:15px;
  font-weight:800;
  text-align:center;
  outline:none;
  -moz-appearance:textfield;
}
.evfs-acomp-input:focus{border-color:var(--evapp-primary);box-shadow:0 0 0 3px rgba(50,121,189,.12)}
.evfs-acomp-input::-webkit-inner-spin-button,.evfs-acomp-input::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}
.evfs-acomp-btn{
  min-height:44px;
  flex:1;
  margin:0;
  padding:9px 14px;
  color:#fff;
  background:var(--evapp-primary);
  border:1px solid var(--evapp-primary);
  border-radius:11px;
  font:inherit;
  font-size:13px;
  font-weight:800;
  cursor:pointer;
  transition:background .16s ease,opacity .16s ease,transform .16s ease;
}
.evfs-acomp-btn:hover:not(:disabled){background:var(--evapp-primary-dark);transform:translateY(-1px)}
.evfs-acomp-btn:disabled{opacity:.55;cursor:not-allowed}
.evfs-acomp-status{min-height:18px;margin-top:7px;color:var(--evapp-muted);font-size:12px;line-height:1.4}
.evfs-acomp-status.is-success{color:var(--evapp-success)}
.evfs-acomp-status.is-error{color:var(--evapp-danger)}
.evfs-inline-notice{
  max-width:900px;
  margin:12px auto;
  padding:14px 16px;
  border:1px solid #f1dfad;
  border-left:4px solid #d69e2e;
  border-radius:13px;
  background:var(--evapp-warning-soft,#fff8e6);
  color:#805900;
}
.evfs-inline-notice--error{border-color:#f4caca;border-left-color:#c53a3a;background:#fff1f1;color:#9b2c2c}
@media(max-width:900px){
  .evfs-row{grid-template-columns:minmax(0,1fr) 210px}
  .evfs-info-grid{grid-template-columns:1fr}
  .evfs-info-item.is-wide{grid-column:auto}
}
@media(max-width:767px){
  .evfs-shell{padding:16px;border-radius:20px}
  .evfs-header{display:block;margin-bottom:18px}
  .evfs-header-actions{margin-top:14px}
  .evfs-header-actions .evfs-btn{width:100%}
  .evfs-event-context{align-items:flex-start;flex-direction:column;padding:14px}
  .evfs-event-main{width:100%}
  .evfs-event-meta{width:100%;justify-content:flex-start}
  .evfs-event-context>.evfs-btn{width:100%}
  .evfs-search-heading{display:block}
  .evfs-searchbar{grid-template-columns:1fr}
  .evfs-search-foot{align-items:flex-start;flex-direction:column;gap:4px}
  .evfs-row{grid-template-columns:1fr;gap:14px;padding:15px}
  .evfs-actions{grid-template-columns:repeat(2,minmax(0,1fr))}
  .evfs-virtual-badge{grid-column:1/-1}
  .evfs-note{left:0;right:auto;width:100%}
}
@media(max-width:520px){
  .evfs-main-title{font-size:30px}
  .evfs-search-card{padding:14px}
  .evfs-data{grid-template-columns:1fr;gap:10px}
  .evfs-avatar{width:42px;height:42px;display:none}
  .evfs-info-grid{grid-template-columns:1fr}
  .evfs-actions{grid-template-columns:1fr}
  .evfs-virtual-badge{grid-column:auto}
  .evfs-acomp-row{align-items:stretch;flex-direction:column}
  .evfs-acomp-input{width:100%;flex-basis:auto}
  .evfs-acomp-btn{width:100%}
  .evfs-chip{white-space:normal}
}
@media(prefers-reduced-motion:reduce){
  .evfs-app *{scroll-behavior:auto!important;transition:none!important;animation-duration:.001ms!important;animation-iteration-count:1!important}
}
CSS;

    wp_register_style('eventosapp-front-search', false, [], null);
    wp_add_inline_style('eventosapp-front-search', $css);
    wp_enqueue_style('eventosapp-front-search');

    $js = <<<'JS'
jQuery(function($){
  var $w = $('#evfs-wrap'),
      $type = $('#evfs-search-type'),
      $in = $('#evfs-input'),
      $out = $('#evfs-results'),
      $clear = $('#evfs-clear'),
      $count = $('#evfs-result-count'),
      eventId = EvFrontSearch.event_id,
      timer,
      pendingSearch = null,
      requestSeq = 0,
      lastSearchKey = '';

  if(!$w.length) return;

  function escHtml(value){
    if(value === null || typeof value === 'undefined') return '';
    return $('<div>').text(String(value)).html();
  }

  function escAttr(value){
    return escHtml(value).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function icon(name){
    if(name === 'check') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>';
    if(name === 'printer') return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V4h10v5"></path><path d="M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"></path><path d="M7 14h10v6H7z"></path></svg>';
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>';
  }

  function searchPlaceholder(type){
    var labels = {
      name: 'Buscar por nombres y apellidos…',
      cc: 'Buscar por cédula…',
      phone: 'Buscar por celular…',
      email: 'Buscar por correo electrónico…',
      all: 'Buscar en todos los datos…'
    };
    return labels[type] || labels.cc;
  }

  function getSearchType(){
    return ($type.val() || 'cc').toString();
  }

  function getMinLen(type){
    return (type === 'all' || type === 'name' || type === 'email') ? 3 : 2;
  }

  function updateSearchPlaceholder(){
    $in.attr('placeholder', searchPlaceholder(getSearchType()));
  }

  function updateClear(){
    $clear.toggleClass('is-visible', $.trim($in.val()).length > 0);
  }

  function updateCount(value){
    if(!value){
      $count.text('');
      return;
    }
    $count.text(value === 1 ? '1 resultado' : value + ' resultados');
  }

  function stateHtml(kind, title, text){
    var visual = kind === 'loading'
      ? '<div class="evfs-spinner" aria-hidden="true"></div>'
      : '<div class="evfs-state-icon" aria-hidden="true">' + icon('search') + '</div>';
    return '<div class="evfs-state evfs-state-' + escAttr(kind) + '">' +
      visual +
      '<strong>' + escHtml(title) + '</strong>' +
      '<span>' + escHtml(text) + '</span>' +
    '</div>';
  }

  function setBusy(isBusy){
    $out.attr('aria-busy', isBusy ? 'true' : 'false');
  }

  function showInitial(){
    setBusy(false);
    updateCount(0);
    $out.html(stateHtml('initial', 'Busca un asistente', 'Selecciona el tipo de búsqueda y escribe los datos del asistente. Los resultados aparecerán automáticamente.'));
  }

  function showMinChars(minLen){
    setBusy(false);
    updateCount(0);
    $out.html(stateHtml('hint', 'Continúa escribiendo', 'Ingresa al menos ' + minLen + ' caracteres para iniciar la búsqueda.'));
  }

  function showLoading(){
    setBusy(true);
    updateCount(0);
    $out.html(stateHtml('loading', 'Buscando asistentes…', 'Estamos consultando los tickets del evento activo.'));
  }

  function showError(){
    setBusy(false);
    updateCount(0);
    $out.html(stateHtml('error', 'No pudimos completar la búsqueda', EvFrontSearch.msgs.search_error || EvFrontSearch.msgs.net_error));
  }

  function btnCheck(status, ticketId, allowed){
    var isChecked = (status === 'checked_in');
    var showAsChecked = (allowed !== false) && isChecked;
    var txt = showAsChecked ? 'Check-in realizado' : 'Hacer check-in';
    var disabledAttr = (allowed === false) ? ' disabled title="Hoy no es un día de check-in"' : '';
    var ariaAttr = ' aria-checked="' + (showAsChecked ? 'true' : 'false') + '"';
    var checkedClass = showAsChecked ? ' is-checked' : '';

    return '<button type="button" class="evfs-btn evfs-check' + checkedClass + '"' + ariaAttr +
           ' data-ticket-id="' + escAttr(ticketId) + '"' + disabledAttr + '>' +
           icon('check') + '<span class="evfs-btn-label">' + txt + '</span></button>';
  }

  function btnVirtualBadge(it){
    var checked = it.virtual_today_status === 'checked_in' || it.virtual_checked === true;
    var sub = checked ? 'Check-in virtual registrado' : 'Sin check-in virtual hoy';
    return '<div class="evfs-virtual-badge' + (checked ? ' is-checked' : '') + '" role="status">' +
      '<span>Asistente virtual</span><small>' + escHtml(sub) + '</small></div>';
  }

  function initials(firstName, lastName){
    var first = $.trim(firstName || '');
    var last = $.trim(lastName || '');
    var out = '';
    if(first) out += first.charAt(0);
    if(last) out += last.charAt(0);
    return out || 'AS';
  }

  function infoItem(label, value, wide){
    return '<div class="evfs-info-item' + (wide ? ' is-wide' : '') + '">' +
      '<strong>' + escHtml(label) + '</strong>' +
      '<span class="evfs-info-value">' + escHtml(value || '—') + '</span>' +
    '</div>';
  }

  function render(rows){
    setBusy(false);
    rows = $.isArray(rows) ? rows : [];
    updateCount(rows.length);

    if(!rows.length){
      $out.html(stateHtml('empty', 'No encontramos coincidencias', 'Revisa el dato ingresado o prueba otro tipo de búsqueda.'));
      return;
    }

    var html = '';
    $.each(rows, function(i, it){
      it = it || {};
      var isVirtual = it.is_virtual === true || it.modalidad === 'virtual';
      var fullName = $.trim((it.first_name || '') + ' ' + (it.last_name || '')) || 'Asistente sin nombre';
      var profile = '';
      if(it.company || it.cargo){
        profile = [it.company || '', it.cargo || ''].filter(function(v){ return $.trim(v).length; }).join(' · ');
      }
      var checkStatusClass = it.today_allowed === false ? ' is-disabled' : (it.today_status === 'checked_in' ? ' is-checked' : '');
      var checkStatusText = it.today_allowed === false
        ? 'Check-in fuera de fecha'
        : (it.today_status === 'checked_in' ? 'Check-in presencial registrado' : 'Pendiente de check-in presencial');
      var actions = (isVirtual ? btnVirtualBadge(it) : '') +
        btnCheck(it.today_status, it.ticket_id, it.today_allowed) +
        '<button type="button" class="evfs-btn evfs-print" data-eventosapp-native-event="1" data-ticket-id="' + escAttr(it.ticket_id) + '" data-event-id="' + escAttr(it.event_id) + '">' +
          icon('printer') + '<span>Imprimir escarapela</span></button>';

      html += '<article class="evfs-row" data-ticket-id="' + escAttr(it.ticket_id) + '">' +
        '<div class="evfs-data">' +
          '<div class="evfs-avatar" aria-hidden="true">' + escHtml(initials(it.first_name, it.last_name)) + '</div>' +
          '<div class="evfs-data-main">' +
            '<div class="evfs-person-head">' +
              '<h3 class="evfs-person-name">' + escHtml(fullName) + '</h3>' +
              '<span class="evfs-mode-badge' + (isVirtual ? ' is-virtual' : '') + '">' + escHtml(it.modalidad_label || 'Presencial') + '</span>' +
            '</div>' +
            '<div class="evfs-info-grid">' +
              infoItem('Cédula / ID', it.cc || '—', false) +
              infoItem('Ticket ID', it.ticket_pub || '—', false) +
              infoItem('Email', it.email || '—', false) +
              infoItem('Celular', it.phone || '—', false) +
              infoItem('Localidad', it.localidad || '—', false) +
              (profile ? infoItem('Empresa / cargo', profile, false) : '') +
            '</div>' +
            '<div class="evfs-checkin-status' + checkStatusClass + '">' + escHtml(checkStatusText) + '</div>' +
          '</div>' +
        '</div>' +
        '<div class="evfs-actions">' + actions + '</div>' +
      '</article>';
    });

    $out.html(html);
  }

  function showNote($btn, msg, ok){
    var $wrap = $btn.closest('.evfs-actions');
    var $n = $wrap.find('.evfs-note');
    if(!$n.length){ $n = $('<div class="evfs-note" role="status" />').appendTo($wrap); }
    $n.text(msg).toggleClass('ok', !!ok).stop(true,true).fadeIn(120);
    setTimeout(function(){ $n.fadeOut(180, function(){ $(this).remove(); }); }, 3000);
  }

  function runSearch(immediate){
    clearTimeout(timer);
    updateClear();

    var q = $.trim($in.val());
    var searchType = getSearchType();
    var minLen = getMinLen(searchType);

    if(pendingSearch && pendingSearch.readyState !== 4){
      pendingSearch.abort();
    }

    if(!q){
      lastSearchKey = '';
      requestSeq++;
      showInitial();
      return;
    }

    if(q.length < minLen){
      lastSearchKey = '';
      requestSeq++;
      showMinChars(minLen);
      return;
    }

    var delay = immediate ? 0 : 350;
    timer = setTimeout(function(){
      var key = searchType + '|' + q;
      if(key === lastSearchKey) return;

      var seq = ++requestSeq;
      showLoading();

      pendingSearch = $.getJSON(EvFrontSearch.ajax_url, {
        action: 'eventosapp_front_search',
        security: EvFrontSearch.search_nonce,
        q: q,
        search_type: searchType,
        event_id: eventId
      }).done(function(resp){
        if(seq !== requestSeq) return;
        if(resp && resp.success){
          lastSearchKey = key;
          render(resp.data || []);
        } else {
          lastSearchKey = '';
          showError();
        }
      }).fail(function(xhr, status){
        if(seq !== requestSeq || status === 'abort') return;
        lastSearchKey = '';
        showError();
      }).always(function(){
        if(seq === requestSeq) pendingSearch = null;
      });
    }, delay);
  }

  $in.on('input', function(){ runSearch(false); });

  $in.on('keydown', function(e){
    if(e.key === 'Enter'){
      e.preventDefault();
      lastSearchKey = '';
      runSearch(true);
    }
    if(e.key === 'Escape' && $in.val()){
      e.preventDefault();
      $in.val('');
      runSearch(true);
      $in.trigger('focus');
    }
  });

  $type.on('change', function(){
    updateSearchPlaceholder();
    lastSearchKey = '';
    runSearch(true);
  });

  $clear.on('click', function(){
    $in.val('').trigger('focus');
    lastSearchKey = '';
    runSearch(true);
  });

  updateSearchPlaceholder();
  updateClear();
  showInitial();

  $(document).on('click', '.evfs-check', function(){
    var $b = $(this), id = $b.data('ticket-id');
    if($b.prop('disabled') || $b.hasClass('is-loading')) return;

    var wasChecked = $b.attr('aria-checked') === 'true';
    $b.addClass('is-loading').prop('disabled', true);
    $b.find('.evfs-btn-label').text('Actualizando…');

    $.post(EvFrontSearch.ajax_url, {
      action: 'eventosapp_front_toggle_checkin',
      security: EvFrontSearch.toggle_nonce,
      ticket_id: id
    }, function(resp){
      if(resp && resp.success){
        var newStatus = resp.data.today_status;
        var isChecked = newStatus === 'checked_in';
        var $row = $b.closest('.evfs-row');

        $b.attr('aria-checked', isChecked ? 'true' : 'false')
          .toggleClass('is-checked', isChecked)
          .find('.evfs-btn-label').text(isChecked ? 'Check-in realizado' : 'Hacer check-in');

        $row.find('.evfs-checkin-status')
          .removeClass('is-checked is-disabled')
          .toggleClass('is-checked', isChecked)
          .text(isChecked ? 'Check-in presencial registrado' : 'Pendiente de check-in presencial');

        showNote($b, isChecked ? 'Check-in registrado correctamente.' : 'Check-in presencial removido.', true);

        if (isChecked && resp.data.acompanantes_enabled && resp.data.ticket_id) {
          $row.next('.evfs-acomp-wrapper').remove();
          var tid = resp.data.ticket_id;
          var $panel = $(
            '<div class="evfs-acomp-wrapper">' +
              '<div class="evfs-acomp-panel">' +
                '<div class="evfs-acomp-label">Acompañantes sin QR</div>' +
                '<div class="evfs-acomp-row">' +
                  '<input type="number" class="evfs-acomp-input" min="0" max="500" step="1" value="0" aria-label="Cantidad de acompañantes">' +
                  '<button type="button" class="evfs-acomp-btn" data-ticket-id="' + escAttr(tid) + '">Registrar acompañantes</button>' +
                '</div>' +
                '<div class="evfs-acomp-status" role="status" aria-live="polite"></div>' +
              '</div>' +
            '</div>'
          );
          $row.after($panel);
        } else if (!isChecked) {
          $row.next('.evfs-acomp-wrapper').remove();
        }
      } else {
        $b.attr('aria-checked', wasChecked ? 'true' : 'false')
          .toggleClass('is-checked', wasChecked)
          .find('.evfs-btn-label').text(wasChecked ? 'Check-in realizado' : 'Hacer check-in');
        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : EvFrontSearch.msgs.not_allowed;
        showNote($b, msg, false);
      }
    }, 'json').fail(function(xhr){
      $b.attr('aria-checked', wasChecked ? 'true' : 'false')
        .toggleClass('is-checked', wasChecked)
        .find('.evfs-btn-label').text(wasChecked ? 'Check-in realizado' : 'Hacer check-in');

      var msg = EvFrontSearch.msgs.net_error;
      try {
        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          msg = xhr.responseJSON.data.message;
        } else if (xhr.responseText) {
          var j = JSON.parse(xhr.responseText);
          if (j && j.data && j.data.message) msg = j.data.message;
        }
      } catch(e){}
      showNote($b, msg, false);
    }).always(function(){
      $b.removeClass('is-loading').prop('disabled', false);
    });
  });

  $(document).on('click', '.evfs-acomp-btn', function(){
    var $btn = $(this);
    var tid = $btn.data('ticket-id');
    var $panel = $btn.closest('.evfs-acomp-panel');
    var $input = $panel.find('.evfs-acomp-input');
    var $status = $panel.find('.evfs-acomp-status');
    var cantidad = parseInt($input.val(), 10);

    $status.removeClass('is-success is-error');

    if (isNaN(cantidad) || cantidad < 0 || cantidad > 500) {
      $status.addClass('is-error').text('Ingresa un número válido entre 0 y 500.');
      return;
    }

    $btn.prop('disabled', true).text('Guardando…');
    $status.text('Registrando…');

    $.post(EvFrontSearch.ajax_url, {
      action: 'eventosapp_registrar_acompanantes',
      companion_nonce: EvFrontSearch.acompanantes_nonce,
      ticket_id: tid,
      cantidad: cantidad
    }, function(resp){
      if (resp && resp.success) {
        $status.addClass('is-success').text(cantidad + ' acompañante(s) registrado(s). Total: ' + (resp.data.total || cantidad));
        $input.val(0);
        $btn.text('Guardado');
        setTimeout(function(){ $btn.prop('disabled', false).text('Registrar acompañantes'); }, 2500);
      } else {
        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Error al guardar.';
        $status.addClass('is-error').text(msg);
        $btn.prop('disabled', false).text('Reintentar');
      }
    }, 'json').fail(function(){
      $status.addClass('is-error').text('Error de conexión.');
      $btn.prop('disabled', false).text('Reintentar');
    });
  });

  $(document).on('click', '.evfs-print', function(){
    var $button = $(this),
        tid = $button.data('ticket-id'),
        eid = $button.data('event-id');
    var url = EvFrontSearch.ajax_url
            + '?action=eventosapp_render_badge'
            + '&nonce=' + encodeURIComponent(EvFrontSearch.print_nonce)
            + '&ticket_id=' + encodeURIComponent(tid)
            + '&event_id=' + encodeURIComponent(eid);

    var detail = {
      source: 'frontend-search',
      ticket_id: tid,
      event_id: eid,
      print_url: url,
      copies: $button.data('copies') || 1
    };
    var bridgeEvent = null;

    try {
      bridgeEvent = new CustomEvent('eventosapp:print-request', {
        detail: detail,
        bubbles: false,
        cancelable: true
      });
    } catch(error) {}

    if(bridgeEvent && document.dispatchEvent(bridgeEvent) === false){
      return;
    }

    window.open(url, '_blank');
  });
});
JS;

    wp_add_inline_script('eventosapp-front-search', $js);
    wp_enqueue_script('eventosapp-front-search');

    ob_start();
    ?>
    <div id="evfs-wrap" class="evfs-app evfs-wrap" data-event-id="<?php echo esc_attr($eid); ?>">
        <div class="evfs-shell">
            <header class="evfs-header">
                <div class="evfs-heading">
                    <p class="evfs-eyebrow">EVENTOSAPP</p>
                    <h1 class="evfs-main-title">Búsqueda y Check-In Manual</h1>
                    <p class="evfs-subtitle">
                        Encuentra asistentes rápidamente, valida sus datos, gestiona el check-in presencial y vuelve a imprimir su escarapela desde un solo lugar.
                    </p>
                </div>

                <div class="evfs-header-actions">
                    <a href="<?php echo esc_url($dashboard_url); ?>" class="evfs-btn evfs-btn-secondary" aria-label="Volver al dashboard">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg>
                        <span>Volver al dashboard</span>
                    </a>
                </div>
            </header>

            <section class="evfs-event-context" aria-label="Evento activo">
                <div class="evfs-event-main">
                    <div class="evfs-event-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M7 3v4M17 3v4M3 9h18"></path></svg>
                    </div>
                    <div class="evfs-event-copy">
                        <span class="evfs-event-kicker">Evento activo</span>
                        <strong class="evfs-event-name"><?php echo esc_html($event_name); ?></strong>
                    </div>
                </div>

                <div class="evfs-event-meta">
                    <?php if ( $event_modalidad_label ): ?>
                        <span class="evfs-chip"><?php echo esc_html($event_modalidad_label); ?></span>
                    <?php endif; ?>
                    <?php if ( $today_label ): ?>
                        <span class="evfs-chip <?php echo $today_allowed ? 'is-valid' : 'is-warning'; ?>">
                            <?php echo esc_html($today_allowed ? 'Check-in habilitado · ' . $today_label : 'Fuera de fecha · ' . $today_label); ?>
                        </span>
                    <?php endif; ?>
                    <a class="evfs-btn evfs-btn-primary" href="<?php echo esc_url($change_event_url); ?>">Cambiar evento</a>
                </div>
            </section>

            <section class="evfs-search-card" aria-labelledby="evfs-search-title">
                <div class="evfs-search-heading">
                    <div>
                        <h2 id="evfs-search-title" class="evfs-search-title">Buscar asistente</h2>
                        <p class="evfs-search-description">Usa el dato más preciso disponible. La cédula y el celular requieren solo 2 caracteres; nombres, email y búsqueda general requieren 3.</p>
                    </div>
                </div>

                <div class="evfs-searchbar">
                    <div>
                        <label class="screen-reader-text" for="evfs-search-type">Tipo de búsqueda</label>
                        <select id="evfs-search-type" class="evfs-select" aria-label="Tipo de búsqueda">
                            <option value="name">Nombres y apellidos</option>
                            <option value="cc" selected>Cédula</option>
                            <option value="phone">Celular</option>
                            <option value="email">Correo electrónico</option>
                            <option value="all">Todos los datos</option>
                        </select>
                    </div>

                    <div class="evfs-input-wrap">
                        <span class="evfs-search-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
                        </span>
                        <label class="screen-reader-text" for="evfs-input">Dato del asistente</label>
                        <input id="evfs-input" class="evfs-input" type="search" placeholder="Buscar por cédula…" autocomplete="off" autocapitalize="off" spellcheck="false">
                        <button id="evfs-clear" class="evfs-clear" type="button" aria-label="Limpiar búsqueda">×</button>
                    </div>
                </div>

                <div class="evfs-search-foot">
                    <span>Tip: presiona Enter para buscar de inmediato o Esc para limpiar.</span>
                    <span id="evfs-result-count" class="evfs-result-count" aria-live="polite"></span>
                </div>
            </section>

            <div id="evfs-results" class="evfs-results" aria-live="polite" aria-busy="false"></div>
        </div>
    </div>
    <?php
    return ob_get_clean();
});

/**
 * AJAX: búsqueda segmentada y optimizada.
 *
 * Reglas de rendimiento y segmentación:
 * - Cédula consulta únicamente el índice/meta de cédula.
 * - Celular consulta únicamente el índice/meta de teléfono.
 * - Correo consulta únicamente el índice/meta de correo.
 * - Nombres y apellidos consulta únicamente los campos de nombre/apellido.
 * - Solo "Todos los datos" utiliza el índice amplio _evapp_search_blob.
 * - Los fallbacks de compatibilidad nunca saltan de un segmento a otro.
 */
add_action('wp_ajax_eventosapp_front_search', function(){
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('search') ) {
        wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
    }
    check_ajax_referer('eventosapp_front_search','security');

    global $wpdb;

    $q        = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
    $event_id = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;

    $allowed_search_types = [ 'name', 'cc', 'phone', 'email', 'all' ];
    $search_type = isset($_GET['search_type']) ? sanitize_key(wp_unslash($_GET['search_type'])) : 'cc';
    if ( ! in_array($search_type, $allowed_search_types, true) ) {
        $search_type = 'cc';
    }

    if ( ! $event_id && function_exists('eventosapp_get_active_event') ) {
        $event_id = (int) eventosapp_get_active_event();
    }

    if ($q === '') wp_send_json_success([]);

    if ( ! function_exists('eventosapp_normalize_text') ) {
        function eventosapp_normalize_text($s) {
            $s = wp_strip_all_tags( (string) $s );
            $s = remove_accents($s);
            if (function_exists('mb_strtolower')) { $s = mb_strtolower($s, 'UTF-8'); } else { $s = strtolower($s); }
            $s = preg_replace('/\s+/u', ' ', $s);
            return trim($s);
        }
    }

    if ( ! function_exists('eventosapp_search_digits_only') ) {
        function eventosapp_search_digits_only($value) {
            return preg_replace('/\D+/', '', (string) $value);
        }
    }

    $q_norm   = eventosapp_normalize_text($q);
    $q_digits = eventosapp_search_digits_only($q);

    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || ($event_id && $event_id !== $active) ) {
            wp_send_json_success([]);
        }
        $event_id = $active;
    }

    $allowed_event_ids = [];
    if ( $event_id ) {
        $allowed_event_ids = [ (int) $event_id ];
    } elseif ( ! current_user_can('manage_options') ) {
        $u = wp_get_current_user();
        $mine = get_posts([
            'post_type'   => 'eventosapp_event',
            'post_status' => 'publish',
            'numberposts' => -1,
            'author'      => $u->ID,
            'fields'      => 'ids'
        ]);
        $allowed_event_ids = array_map('intval', $mine);
        if ( empty($allowed_event_ids) ) {
            wp_send_json_success([]);
        }
    }

    $search_meta_map = [
        'name'  => '_evapp_search_name',
        'cc'    => '_evapp_search_cc',
        'phone' => '_evapp_search_phone',
        'email' => '_evapp_search_email',
        'all'   => '_evapp_search_blob',
    ];

    // Fallbacks estrictamente limitados al segmento solicitado.
    // "all" NO se incluye aquí porque su consulta principal ya usa el blob amplio.
    $raw_meta_fallback_map = [
        'cc'    => [ '_eventosapp_asistente_cc' ],
        'phone' => [ '_eventosapp_asistente_tel' ],
        'email' => [ '_eventosapp_asistente_email' ],
        'name'  => [ '_eventosapp_asistente_nombre', '_eventosapp_asistente_apellido' ],
    ];

    if ( $search_type === 'cc' || $search_type === 'phone' ) {
        $search_value = $q_digits !== '' ? $q_digits : $q_norm;
    } else {
        $search_value = $q_norm;
    }

    $min_len = in_array($search_type, ['all', 'name', 'email'], true) ? 3 : 2;
    $search_length = function_exists('mb_strlen')
        ? mb_strlen($search_value, 'UTF-8')
        : strlen($search_value);
    if ( $search_value === '' || $search_length < $min_len ) {
        wp_send_json_success([]);
    }

    $like = '%' . $wpdb->esc_like($search_value) . '%';

    $find_ticket_ids = function( $meta_keys, $like_value, $limit = 30 ) use ( $wpdb, $allowed_event_ids ) {
        $meta_keys = array_values(array_filter(array_map('sanitize_key', (array) $meta_keys)));
        if ( empty($meta_keys) ) return [];

        $join_event = '';
        $params = [];

        if ( ! empty($allowed_event_ids) ) {
            $placeholders = implode(',', array_fill(0, count($allowed_event_ids), '%s'));
            $join_event = " INNER JOIN {$wpdb->postmeta} evm ON evm.post_id = p.ID AND evm.meta_key = %s AND evm.meta_value IN ($placeholders)";
            $params[] = '_eventosapp_ticket_evento_id';
            foreach ( $allowed_event_ids as $aid ) {
                $params[] = (string) absint($aid);
            }
        }

        $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        $sql = "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            {$join_event}
            INNER JOIN {$wpdb->postmeta} sm ON sm.post_id = p.ID
            WHERE p.post_type = %s
              AND p.post_status NOT IN ('trash','auto-draft','inherit')
              AND sm.meta_key IN ($meta_placeholders)
              AND sm.meta_value LIKE %s
            ORDER BY p.ID DESC
            LIMIT %d
        ";

        $params[] = 'eventosapp_ticket';
        foreach ( $meta_keys as $mk ) {
            $params[] = $mk;
        }
        $params[] = $like_value;
        $params[] = (int) $limit;

        $prepared = $wpdb->prepare($sql, $params);
        $ids = $wpdb->get_col($prepared);
        return array_map('intval', (array) $ids);
    };

    /**
     * Fallback específico para búsquedas por nombre completo.
     *
     * Los tickets antiguos pueden no tener _evapp_search_name. Cuando el usuario
     * escribe "Nombre Apellido", los valores históricos viven en dos metakeys
     * distintas. Esta consulta concatena SOLO esos dos campos, sin tocar el blob
     * general, manteniendo la segmentación y la compatibilidad.
     */
    $find_full_name_ticket_ids = function( $name_value, $limit = 30 ) use ( $wpdb, $allowed_event_ids ) {
        $name_value = trim((string) $name_value);
        if ( $name_value === '' ) return [];

        $join_event = '';
        $params = [];

        if ( ! empty($allowed_event_ids) ) {
            $placeholders = implode(',', array_fill(0, count($allowed_event_ids), '%s'));
            $join_event = " INNER JOIN {$wpdb->postmeta} evm ON evm.post_id = p.ID AND evm.meta_key = %s AND evm.meta_value IN ($placeholders)";
            $params[] = '_eventosapp_ticket_evento_id';
            foreach ( $allowed_event_ids as $aid ) {
                $params[] = (string) absint($aid);
            }
        }

        $sql = "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            {$join_event}
            LEFT JOIN {$wpdb->postmeta} fn
              ON fn.post_id = p.ID AND fn.meta_key = '_eventosapp_asistente_nombre'
            LEFT JOIN {$wpdb->postmeta} ln
              ON ln.post_id = p.ID AND ln.meta_key = '_eventosapp_asistente_apellido'
            WHERE p.post_type = %s
              AND p.post_status NOT IN ('trash','auto-draft','inherit')
              AND CONCAT_WS(' ', COALESCE(fn.meta_value,''), COALESCE(ln.meta_value,'')) LIKE %s
            ORDER BY p.ID DESC
            LIMIT %d
        ";

        $params[] = 'eventosapp_ticket';
        $params[] = '%' . $wpdb->esc_like($name_value) . '%';
        $params[] = (int) $limit;

        $prepared = $wpdb->prepare($sql, $params);
        $ids = $wpdb->get_col($prepared);
        return array_map('intval', (array) $ids);
    };

    // Prefijo versionado para no reutilizar durante el despliegue resultados
    // cacheados por la lógica anterior que podía caer al blob general.
    $cache_key = 'evfs_ids_segmented_v2_' . md5(wp_json_encode([
        'q'      => $search_value,
        'type'   => $search_type,
        'events' => $allowed_event_ids,
    ]));
    $tickets = wp_cache_get($cache_key, 'eventosapp_search');

    if ( ! is_array($tickets) ) {
        // 1) Consulta primaria: únicamente el índice del segmento seleccionado.
        $tickets = $find_ticket_ids([ $search_meta_map[$search_type] ], $like, 30);

        // 2) Compatibilidad con tickets antiguos, sin salir del segmento.
        if ( empty($tickets) && $search_type === 'name' ) {
            // Una sola palabra se resuelve de forma más barata sobre nombre/apellido.
            // Una frase (ej. "James Wood") requiere combinar ambos campos históricos.
            if ( preg_match('/\s/u', $q_norm) ) {
                $tickets = $find_full_name_ticket_ids($q_norm, 30);
            } else {
                $tickets = $find_ticket_ids($raw_meta_fallback_map['name'], '%' . $wpdb->esc_like($q_norm) . '%', 30);
            }
        } elseif ( empty($tickets) && isset($raw_meta_fallback_map[$search_type]) ) {
            $fallback_value = ($search_type === 'cc' || $search_type === 'phone')
                ? ($q_digits !== '' ? $q_digits : $q)
                : $q_norm;
            $fallback_like = '%' . $wpdb->esc_like($fallback_value) . '%';
            $tickets = $find_ticket_ids($raw_meta_fallback_map[$search_type], $fallback_like, 30);
        }

        // IMPORTANTE: no existe fallback al _evapp_search_blob para búsquedas
        // específicas. El blob solo se consulta cuando search_type === 'all'.
        wp_cache_set($cache_key, array_map('intval', (array) $tickets), 'eventosapp_search', 30);
    }

    $tickets = array_map('intval', (array) $tickets);
    if ( ! empty($tickets) ) {
        update_meta_cache('post', $tickets);
    }

    $out = [];

    foreach ( $tickets as $tid ) {
        $ev_id = (int) get_post_meta($tid, '_eventosapp_ticket_evento_id', true);

        if ( $ev_id && ! current_user_can('manage_options') ) {
            if ( ! eventosapp_user_can_manage_event($ev_id) ) continue;
        }

        $fn         = get_post_meta($tid, '_eventosapp_asistente_nombre', true);
        $ln         = get_post_meta($tid, '_eventosapp_asistente_apellido', true);
        $email      = get_post_meta($tid, '_eventosapp_asistente_email', true);
        $phone      = get_post_meta($tid, '_eventosapp_asistente_tel', true);
        $company    = get_post_meta($tid, '_eventosapp_asistente_empresa', true);
        $cargo      = get_post_meta($tid, '_eventosapp_asistente_cargo', true);
        $cc         = get_post_meta($tid, '_eventosapp_asistente_cc', true);
        $localidad  = get_post_meta($tid, '_eventosapp_asistente_localidad', true);
        $ticketP    = get_post_meta($tid, 'eventosapp_ticketID', true);
        $evname     = $ev_id ? get_the_title($ev_id) : '';
        $modalidad  = function_exists('eventosapp_get_ticket_modalidad') ? eventosapp_get_ticket_modalidad($tid) : (get_post_meta($tid, '_eventosapp_ticket_modalidad', true) ?: 'presencial');
        $modalidad  = in_array($modalidad, ['presencial','virtual'], true) ? $modalidad : 'presencial';
        $is_virtual = ($modalidad === 'virtual');
        $modalidad_label = function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label($tid) : ucfirst($modalidad);
        $virtual_url = ($is_virtual && function_exists('eventosapp_get_virtual_landing_url')) ? eventosapp_get_virtual_landing_url($tid) : '';

        $today = eventosapp_get_today_in_event_tz($ev_id);
        $status_arr = get_post_meta($tid, '_eventosapp_checkin_status', true);
        if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
        if (!is_array($status_arr)) $status_arr = [];
        $today_status = $status_arr[$today] ?? 'not_checked_in';

        $virtual_status_arr = get_post_meta($tid, '_eventosapp_virtual_checkin_status', true);
        if (is_string($virtual_status_arr)) $virtual_status_arr = @unserialize($virtual_status_arr);
        if (!is_array($virtual_status_arr)) $virtual_status_arr = [];
        $virtual_today_status = isset($virtual_status_arr[$today]) && in_array($virtual_status_arr[$today], ['checked_in','checked-in'], true)
            ? 'checked_in'
            : 'not_checked_in';

        $today_allowed = eventosapp_is_today_valid_for_event($ev_id);
        if (!$today_allowed) {
            $today_status = 'not_checked_in';
            $virtual_today_status = 'not_checked_in';
        }

        $out[] = [
            'ticket_id'            => $tid,
            'event_id'             => $ev_id,
            'event_name'           => $evname,
            'first_name'           => $fn,
            'last_name'            => $ln,
            'email'                => $email,
            'phone'                => $phone,
            'company'              => $company,
            'cargo'                => $cargo,
            'cc'                   => $cc,
            'localidad'            => $localidad,
            'ticket_pub'           => $ticketP,
            'modalidad'            => $modalidad,
            'modalidad_label'      => $modalidad_label,
            'is_virtual'           => $is_virtual,
            'virtual_url'          => $virtual_url,
            'virtual_today_status' => $virtual_today_status,
            'virtual_checked'      => ($virtual_today_status === 'checked_in'),
            'today_status'         => $today_status,
            'today_allowed'        => $today_allowed,
        ];
    }

    wp_send_json_success( $out );
});

/**
 * AJAX: Toggle check-in del día actual (con log)
 * - Respeta fechas del evento (TZ del evento)
 * - No admins: solo pueden togglear del evento ACTIVO
 */
add_action('wp_ajax_eventosapp_front_toggle_checkin', function(){
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('search') ) {
        wp_send_json_error(['message'=>'Permisos insuficientes'], 403);
    }

    check_ajax_referer('eventosapp_toggle_checkin','security');

    $ticket_id = absint($_POST['ticket_id'] ?? 0);
    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        wp_send_json_error(['message'=>'Ticket inválido'], 400);
    }
    $evento_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    if ( ! $evento_id ) wp_send_json_error(['message'=>'Ticket sin evento'], 400);

    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $evento_id !== $active ) {
            wp_send_json_error(['message'=>'Sin permisos'], 403);
        }
    } elseif ( ! current_user_can('manage_options') && ! eventosapp_user_can_manage_event($evento_id) ) {
        wp_send_json_error(['message'=>'Sin permisos'], 403);
    }

    $today = eventosapp_get_today_in_event_tz($evento_id);
    $days  = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days($evento_id) : [];

    if ( empty($days) || !in_array($today, $days, true) ) {
        wp_send_json_error(['message' => 'El check-in solo está permitido en las fechas del evento. Hoy no corresponde.']);
    }

    $status_arr = get_post_meta($ticket_id, '_eventosapp_checkin_status', true);
    if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
    if (!is_array($status_arr)) $status_arr = [];

    $curr = $status_arr[$today] ?? 'not_checked_in';
    $new  = ($curr === 'checked_in') ? 'not_checked_in' : 'checked_in';
    $status_arr[$today] = $new;
    update_post_meta($ticket_id, '_eventosapp_checkin_status', $status_arr);

    $log = get_post_meta($ticket_id, '_eventosapp_checkin_log', true);
    if (is_string($log)) $log = @unserialize($log);
    if (!is_array($log)) $log = [];
    $user = wp_get_current_user();

    try {
        $tz = new DateTimeZone( get_post_meta($evento_id, '_eventosapp_zona_horaria', true) ?: wp_timezone_string() );
    } catch(Exception $e) {
        $tz = wp_timezone();
    }
    $now = new DateTime('now', $tz);

    $log_entry = [
        'fecha'        => $now->format('Y-m-d'),
        'hora'         => $now->format('H:i:s'),
        'dia'          => $today,
        'status'       => $new,
        'status_label' => ($new === 'checked_in') ? 'Check-in presencial' : 'Check-in presencial removido',
        'checkin_type' => 'presencial',
        'modalidad'    => function_exists('eventosapp_get_ticket_modalidad') ? eventosapp_get_ticket_modalidad($ticket_id) : get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true),
        'usuario'      => $user->display_name . ' (' . $user->user_email . ')',
        'origen'       => 'frontend-search',
    ];

    if ( $new === 'checked_in' ) {
        $log_entry['qr_type']       = 'counter';
        $log_entry['qr_type_label'] = 'Counter';

        if ( function_exists('eventosapp_update_qr_usage_stats') ) {
            eventosapp_update_qr_usage_stats($evento_id, 'counter');
        }
    }

    $log[] = $log_entry;
    update_post_meta($ticket_id, '_eventosapp_checkin_log', $log);

    wp_send_json_success([
        'today_status'         => $new,
        'today_allowed'        => true,
        'message'              => 'Estado actualizado.',
        'ticket_id'            => $ticket_id,
        'acompanantes_enabled' => (get_post_meta($evento_id, '_eventosapp_ticket_acompanantes_checkin', true) === '1'),
    ]);
});

/**
 * AJAX: Render de escarapela leyendo configuración del EVENTO
 * - No admins: solo pueden imprimir del evento ACTIVO
 */
add_action('wp_ajax_eventosapp_render_badge', 'eventosapp_ajax_render_badge');
function eventosapp_ajax_render_badge() {
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('search') ) {
        wp_die('Permisos insuficientes', '', 403);
    }

    check_ajax_referer('eventosapp_render_badge','nonce');

    $ticket_id = absint($_GET['ticket_id'] ?? 0);
    $event_id  = absint($_GET['event_id'] ?? 0);

    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) wp_die('Ticket inválido', '', 400);

    $event_from_ticket = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    if ( ! $event_id || $event_id !== $event_from_ticket ) {
        $event_id = $event_from_ticket;
    }
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) wp_die('Evento inválido', '', 400);

    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $event_id !== $active ) {
            wp_die('Sin permisos', '', 403);
        }
    } elseif ( ! current_user_can('manage_options') && ! eventosapp_user_can_manage_event($event_id) ) {
        wp_die('Sin permisos', '', 403);
    }

    echo eventosapp_get_badge_html_from_event($event_id, $ticket_id);
    exit;
}

/**
 * Construcción de la escarapela tomando metas del EVENTO.
 *
 * Fallback heredado: la versión central preferida vive en eventosapp-badges.php.
 * Esta declaración queda protegida para no generar fatal si el helper central
 * ya fue cargado, manteniendo compatibilidad con instalaciones donde el archivo
 * de escarapelas no esté disponible por alguna razón.
 */
if ( ! function_exists('eventosapp_get_badge_html_from_event') ) {
function eventosapp_get_badge_html_from_event( $event_id, $ticket_id = 0, $auto_print = true ) {
    if ( ! function_exists('eventosapp_get_badge_settings') ) {
        $cfg = [
            'design' => 'manillas',
            'order'  => [1=>'full_name',2=>'company',3=>'qr',4=>'none',5=>'none'],
            'width'  => 374, 'height'=>208,
            'size_large'=>24, 'size_medium'=>18, 'size_small'=>14,
            'weight_large'=>600,'weight_medium'=>500,'weight_small'=>400,
            'sep_vertical'=>4, 'sep_horizontal'=>4,
            'qr_size'=>72, 'border_width'=>0,
        ];
    } else {
        $cfg = eventosapp_get_badge_settings( $event_id );
    }

    $active = [];
    for ($i=1; $i<=5; $i++) {
        $f = $cfg['order'][$i] ?? 'none';
        if ($f !== 'none' && $f !== '') $active[] = $f;
    }
    if (!$active) $active = ['full_name', 'company', 'qr'];

    $labels = [];

    if ($ticket_id && get_post_type($ticket_id)==='eventosapp_ticket') {
        $nombre   = get_post_meta($ticket_id, '_eventosapp_asistente_nombre',  true);
        $apell    = get_post_meta($ticket_id, '_eventosapp_asistente_apellido',true);
        $empresa  = get_post_meta($ticket_id, '_eventosapp_asistente_empresa', true);
        $cargo    = get_post_meta($ticket_id, '_eventosapp_asistente_cargo',   true);
        $cc       = get_post_meta($ticket_id, '_eventosapp_asistente_cc',      true);
        $email    = get_post_meta($ticket_id, '_eventosapp_asistente_email',   true);
        $tel      = get_post_meta($ticket_id, '_eventosapp_asistente_tel',     true);
        $nit      = get_post_meta($ticket_id, '_eventosapp_asistente_nit',     true);
        $ciudad   = get_post_meta($ticket_id, '_eventosapp_asistente_ciudad',  true);
        $pais     = get_post_meta($ticket_id, '_eventosapp_asistente_pais',    true);
        $localidad= get_post_meta($ticket_id, '_eventosapp_asistente_localidad',true);
        $code     = get_post_meta($ticket_id, 'eventosapp_ticketID',           true);

        $labels['full_name']   = trim($nombre . ' ' . $apell) ?: 'Nombres + Apellidos';
        $labels['nombre']      = $nombre ?: 'Nombre';
        $labels['apellido']    = $apell ?: 'Apellido';
        $labels['company']     = $empresa ?: 'Nombre de la Empresa';
        $labels['designation'] = $cargo   ?: 'Cargo';
        $labels['cc_id']       = $cc      ?: 'CC_ID';
        $labels['email']       = $email   ?: 'Email';
        $labels['telefono']    = $tel     ?: 'Teléfono';
        $labels['nit']         = $nit     ?: 'NIT';
        $labels['ciudad']      = $ciudad  ?: 'Ciudad';
        $labels['pais']        = $pais    ?: 'País';
        $labels['localidad']   = $localidad ?: 'Localidad';

        if ($code && function_exists('eventosapp_get_ticket_qr_url')) {
            $labels['qr'] = eventosapp_get_ticket_qr_url($code);
        } else {
            $labels['qr'] = '';
        }

        if (function_exists('eventosapp_get_event_extra_fields')) {
            $extra_fields = eventosapp_get_event_extra_fields($event_id);
            if (!empty($extra_fields)) {
                foreach ($extra_fields as $field) {
                    $key = 'extra_' . $field['key'];
                    $value = get_post_meta($ticket_id, '_eventosapp_extra_' . $field['key'], true);
                    $labels[$key] = $value ?: $field['label'];
                }
            }
        }
    } else {
        $labels = [
            'full_name'   => 'Nombres + Apellidos',
            'nombre'      => 'Nombre',
            'apellido'    => 'Apellido',
            'company'     => 'Nombre de la Empresa',
            'designation' => 'Cargo',
            'cc_id'       => 'CC_ID',
            'email'       => 'Email',
            'telefono'    => 'Teléfono',
            'nit'         => 'NIT',
            'ciudad'      => 'Ciudad',
            'pais'        => 'País',
            'localidad'   => 'Localidad',
            'qr'          => '',
        ];

        if (function_exists('eventosapp_get_event_extra_fields')) {
            $extra_fields = eventosapp_get_event_extra_fields($event_id);
            if (!empty($extra_fields)) {
                foreach ($extra_fields as $field) {
                    $key = 'extra_' . $field['key'];
                    $labels[$key] = $field['label'];
                }
            }
        }
    }

    $flex_dir = ($cfg['design'] === 'escarapelas') ? 'column' : 'row';

    ob_start(); ?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Escarapela</title>
<style>
  html,body{margin:0;padding:0;height:100%}
  body{display:flex;align-items:center;justify-content:center;font-family:Arial,Helvetica,sans-serif}
  .badge{
    <?php echo ($cfg['border_width']>0 ? "border:{$cfg['border_width']}px solid #000;" : "border:none;"); ?>
    display:flex; flex-direction:<?php echo esc_attr($flex_dir); ?>;
    align-items:stretch; justify-content:center;
    width:<?php echo (int)$cfg['width']; ?>px; height:<?php echo (int)$cfg['height']; ?>px;
    padding:4px; box-sizing:border-box;
  }
  .left,.right{display:flex; flex-direction:column; justify-content:center; height:100%;}
  .right{align-items:center;}
  .slot{line-height:1.15; text-align:center; word-break:break-word;}
  @media print { @page { margin: 8mm; } }
</style>
</head>
<body>
<div class="badge">
<?php
    if ($cfg['design'] === 'escarapelas_split') {
        $left  = array_slice($active, 0, 3);
        $right = $active[3] ?? null;

        echo "<div class='left'>";
        foreach ($left as $idx=>$field) {
            $fs = ($idx===0) ? $cfg['size_large'] : (($idx===1) ? $cfg['size_medium'] : $cfg['size_small']);
            $fw = ($idx===0) ? $cfg['weight_large'] : (($idx===1) ? $cfg['weight_medium'] : $cfg['weight_small']);
            $m  = $cfg['sep_vertical'];
            if ($field === 'qr' && !empty($labels['qr'])) {
                echo "<div class='slot' style='margin:{$m}px'><img src='".esc_url($labels['qr'])."' width='{$cfg['qr_size']}' height='{$cfg['qr_size']}' alt='QR'></div>";
            } else {
                $label_text = isset($labels[$field]) ? $labels[$field] : '';
                echo "<div class='slot' style='margin:{$m}px; font-size:{$fs}px; font-weight:{$fw};'>".esc_html($label_text)."</div>";
            }
        }
        echo "</div>";

        $mh = $cfg['sep_horizontal'];
        echo "<div class='right' style='margin-left:{$mh}px'>";
        if ($right) {
            if ($right === 'qr' && !empty($labels['qr'])) {
                echo "<div class='slot' style='margin:{$cfg['sep_vertical']}px'><img src='".esc_url($labels['qr'])."' width='{$cfg['qr_size']}' height='{$cfg['qr_size']}' alt='QR'></div>";
            } else {
                $label_text = isset($labels[$right]) ? $labels[$right] : '';
                echo "<div class='slot' style='margin:{$cfg['sep_vertical']}px; font-size:{$cfg['size_medium']}px; font-weight:{$cfg['weight_medium']};'>".esc_html($label_text)."</div>";
            }
        }
        echo "</div>";

    } elseif ($cfg['design'] === 'escarapelas_split_4') {
        $left  = array_slice($active, 0, 4);
        $right = $active[4] ?? null;

        echo "<div class='left'>";
        foreach ($left as $idx=>$field) {
            if ($idx === 0) {
                $fs = $cfg['size_large'];
                $fw = $cfg['weight_large'];
            } elseif ($idx === 1 || $idx === 2) {
                $fs = $cfg['size_medium'];
                $fw = $cfg['weight_medium'];
            } else {
                $fs = $cfg['size_small'];
                $fw = $cfg['weight_small'];
            }
            $m  = $cfg['sep_vertical'];

            if ($field === 'qr' && !empty($labels['qr'])) {
                echo "<div class='slot' style='margin:{$m}px'><img src='".esc_url($labels['qr'])."' width='{$cfg['qr_size']}' height='{$cfg['qr_size']}' alt='QR'></div>";
            } else {
                $label_text = isset($labels[$field]) ? $labels[$field] : '';
                echo "<div class='slot' style='margin:{$m}px; font-size:{$fs}px; font-weight:{$fw};'>".esc_html($label_text)."</div>";
            }
        }
        echo "</div>";

        $mh = $cfg['sep_horizontal'];
        echo "<div class='right' style='margin-left:{$mh}px'>";
        if ($right) {
            if ($right === 'qr' && !empty($labels['qr'])) {
                echo "<div class='slot' style='margin:{$cfg['sep_vertical']}px'><img src='".esc_url($labels['qr'])."' width='{$cfg['qr_size']}' height='{$cfg['qr_size']}' alt='QR'></div>";
            } else {
                $label_text = isset($labels[$right]) ? $labels[$right] : '';
                echo "<div class='slot' style='margin:{$cfg['sep_vertical']}px; font-size:{$cfg['size_medium']}px; font-weight:{$cfg['weight_medium']};'>".esc_html($label_text)."</div>";
            }
        }
        echo "</div>";

    } else {
        foreach (array_values($active) as $idx=>$field) {
            $margin = ($cfg['design']==='escarapelas') ? $cfg['sep_vertical'] : $cfg['sep_horizontal'];
            if ($field==='qr' && !empty($labels['qr'])) {
                echo "<div class='slot' style='margin:{$margin}px'><img src='".esc_url($labels['qr'])."' width='{$cfg['qr_size']}' height='{$cfg['qr_size']}' alt='QR'></div>";
            } else {
                if ($cfg['design']==='escarapelas') {
                    $fs = ($idx===0) ? $cfg['size_large'] : (($idx<=2) ? $cfg['size_medium'] : $cfg['size_small']);
                    $fw = ($idx===0) ? $cfg['weight_large'] : (($idx<=2) ? $cfg['weight_medium'] : $cfg['weight_small']);
                } else {
                    $fs = $cfg['size_medium'];
                    $fw = $cfg['weight_medium'];
                }
                $label_text = isset($labels[$field]) ? $labels[$field] : '';
                echo "<div class='slot' style='margin:{$margin}px; font-size:{$fs}px; font-weight:{$fw};'>".esc_html($label_text)."</div>";
            }
        }
    }
?>
</div>
<?php if ( ! empty( $auto_print ) ) : ?>
<script>window.print();</script>
<?php endif; ?>
</body></html>
<?php
    return ob_get_clean();
}
}
