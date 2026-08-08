<?php
/**
 * EventosApp – Check-In por Reconocimiento Facial
 *
 * Shortcode: [eventosapp_face_checkin]
 *
 * Flujo:
 *  1. Staff abre el módulo y se valida el evento activo/permisos.
 *  2. Se cargan los modelos locales de face-api.js.
 *  3. Via AJAX se cargan los CPT eventosapp_asistente del evento activo que tienen foto.
 *  4. Los descriptores se cachean en IndexedDB y se agrupan por cédula para construir
 *     un único FaceMatcher reutilizable durante toda la sesión.
 *  5. Al encontrar coincidencia se extrae la cédula del asistente reconocido.
 *  6. Otro AJAX busca el ticket (cédula + evento activo) y ejecuta el check-in
 *     usando la misma lógica operativa que el check-in por QR.
 *
 * Dependencias locales:
 *  - face-api.js: includes/assets/js/face-api.min.js
 *  - modelos: includes/assets/face-models/
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// 0. HELPERS COMPARTIDOS DEL MÓDULO
// ============================================================

/**
 * Logger de diagnóstico. El archivo ya lo invocaba en el flujo AJAX; se define
 * de forma protegida para evitar un fatal cuando WP_DEBUG está activo o cuando
 * el check-in llega a esos puntos de ejecución.
 */
if ( ! function_exists( 'eventosapp_face_checkin_debug_log' ) ) {
    function eventosapp_face_checkin_debug_log( $message, $context = [] ) {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        $suffix = '';
        if ( ! empty( $context ) ) {
            $suffix = ' | ' . ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $context ) : json_encode( $context ) );
        }

        error_log( 'EVENTOSAPP FACE CHECKIN | ' . sanitize_text_field( (string) $message ) . $suffix );
    }
}

/**
 * Devuelve el contexto de fecha del evento usando la zona horaria configurada.
 * Se usa en la UI para no cargar modelos/cámara cuando hoy no es un día válido.
 */
if ( ! function_exists( 'eventosapp_face_checkin_day_context' ) ) {
    function eventosapp_face_checkin_day_context( $event_id ) {
        $event_id = absint( $event_id );
        $event_tz = get_post_meta( $event_id, '_eventosapp_zona_horaria', true );

        if ( ! $event_tz ) {
            $event_tz = wp_timezone_string();
            if ( ! $event_tz || $event_tz === 'UTC' ) {
                $offset   = get_option( 'gmt_offset' );
                $event_tz = $offset ? ( timezone_name_from_abbr( '', $offset * 3600, 0 ) ?: 'UTC' ) : 'UTC';
            }
        }

        try {
            $dt = new DateTime( 'now', new DateTimeZone( $event_tz ) );
        } catch ( Exception $e ) {
            $dt = new DateTime( 'now', wp_timezone() );
        }

        $today = $dt->format( 'Y-m-d' );
        $days  = function_exists( 'eventosapp_get_event_days' )
            ? (array) eventosapp_get_event_days( $event_id )
            : [];

        return [
            'today' => $today,
            'label' => date_i18n( 'D, d M Y', strtotime( $today ) ),
            'valid' => ! empty( $days ) && in_array( $today, $days, true ),
        ];
    }
}

// ============================================================
// 1. SHORTCODE PRINCIPAL
// ============================================================

add_shortcode( 'eventosapp_face_checkin', function ( $atts ) {

    // Mantiene el acceso directo alineado con la feature visible en el dashboard.
    if ( function_exists( 'eventosapp_require_feature' ) ) {
        eventosapp_require_feature( 'face_checkin' );
    }

    // Fallback de sesión para instalaciones donde no exista el helper anterior.
    if ( ! is_user_logged_in() ) {
        return '<p style="color:#b42318;">Debes iniciar sesión para usar este módulo.</p>';
    }

    // Reutiliza la capacidad operativa del Check-In QR.
    if ( function_exists( 'eventosapp_current_user_can_checkin' ) && ! eventosapp_current_user_can_checkin() ) {
        return '<p style="color:#b42318;">No tienes permisos para realizar check-in.</p>';
    }

    // Debe existir evento activo.
    $active_event = function_exists( 'eventosapp_get_active_event' ) ? eventosapp_get_active_event() : 0;
    if ( ! $active_event ) {
        ob_start();
        if ( function_exists( 'eventosapp_require_active_event' ) ) {
            eventosapp_require_active_event();
        } else {
            echo '<p>Debes seleccionar un evento activo para usar el reconocimiento facial.</p>';
        }
        return ob_get_clean();
    }

    $nonce_load    = wp_create_nonce( 'evapp_face_load_asistentes' );
    $nonce_checkin = wp_create_nonce( 'evapp_face_checkin_process' );

    $models_url    = esc_url( EVENTOSAPP_PLUGIN_URL . 'includes/assets/face-models' );
    $face_api_url  = esc_url( EVENTOSAPP_PLUGIN_URL . 'includes/assets/js/face-api.min.js' );
    $dashboard_url = function_exists( 'eventosapp_get_dashboard_url' )
        ? eventosapp_get_dashboard_url()
        : home_url( '/' );

    $event_id      = (int) $active_event;
    $event_name    = get_the_title( $active_event );
    $ajax_url      = admin_url( 'admin-ajax.php' );
    $day_context   = eventosapp_face_checkin_day_context( $event_id );
    $day_is_valid  = ! empty( $day_context['valid'] );
    $instance_id   = function_exists( 'wp_unique_id' )
        ? wp_unique_id( 'evapp-face-checkin-' )
        : 'evapp-face-checkin-' . $event_id;

    ob_start(); ?>
    <?php if ( $day_is_valid ) : ?>
        <script src="<?php echo esc_url( $face_api_url ); ?>"></script>
    <?php endif; ?>

    <style>
    .evapp-face-checkin-app {
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
      line-height:1.45;
    }
    .evapp-face-checkin-app,
    .evapp-face-checkin-app *,
    .evapp-face-checkin-app *::before,
    .evapp-face-checkin-app *::after { box-sizing:border-box; }
    .evapp-face-checkin-app a { text-decoration:none; }
    .evapp-face-checkin-app [hidden] { display:none!important; }

    .evapp-face-checkin-app .evfc-shell {
      width:100%;
      padding:clamp(18px,3vw,36px);
      background:var(--evapp-app-bg);
      border:1px solid var(--evapp-border);
      border-radius:var(--evapp-radius-lg);
      box-shadow:0 18px 50px rgba(31,65,99,.08);
    }
    .evapp-face-checkin-app .evfc-header {
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:24px;
      margin-bottom:22px;
    }
    .evapp-face-checkin-app .evfc-heading { min-width:0; }
    .evapp-face-checkin-app .evfc-eyebrow {
      margin:0 0 7px;
      color:var(--evapp-primary);
      font-size:12px;
      font-weight:800;
      letter-spacing:.15em;
      text-transform:uppercase;
    }
    .evapp-face-checkin-app .evfc-title {
      margin:0;
      color:var(--evapp-text);
      font-size:clamp(27px,4vw,42px);
      font-weight:800;
      line-height:1.08;
      letter-spacing:-.035em;
    }
    .evapp-face-checkin-app .evfc-subtitle {
      max-width:760px;
      margin:10px 0 0;
      color:var(--evapp-muted);
      font-size:15px;
      line-height:1.6;
    }
    .evapp-face-checkin-app .evfc-header-actions { flex:0 0 auto; }
    .evapp-face-checkin-app .evfc-btn {
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
      cursor:pointer;
      transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease,background .16s ease,color .16s ease,opacity .16s ease;
      -webkit-tap-highlight-color:transparent;
    }
    .evapp-face-checkin-app .evfc-btn svg {
      width:18px;
      height:18px;
      flex:0 0 18px;
      fill:none;
      stroke:currentColor;
      stroke-width:2;
      stroke-linecap:round;
      stroke-linejoin:round;
    }
    .evapp-face-checkin-app .evfc-btn:hover:not(:disabled) { transform:translateY(-1px); }
    .evapp-face-checkin-app .evfc-btn:focus-visible { outline:3px solid rgba(50,121,189,.22); outline-offset:2px; }
    .evapp-face-checkin-app .evfc-btn:disabled { opacity:.55; cursor:not-allowed; transform:none; box-shadow:none; }
    .evapp-face-checkin-app .evfc-btn-secondary {
      background:var(--evapp-surface);
      border-color:var(--evapp-border);
      color:var(--evapp-text);
      box-shadow:0 5px 15px rgba(31,65,99,.05);
      white-space:nowrap;
    }
    .evapp-face-checkin-app .evfc-btn-secondary:hover:not(:disabled) {
      border-color:#c7d7e8;
      color:var(--evapp-primary-dark);
      box-shadow:0 8px 20px rgba(31,65,99,.09);
    }

    .evapp-face-checkin-app .evfc-event-context {
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
    .evapp-face-checkin-app .evfc-event-main {
      min-width:0;
      display:flex;
      align-items:center;
      gap:13px;
    }
    .evapp-face-checkin-app .evfc-event-icon {
      width:44px;
      height:44px;
      flex:0 0 44px;
      display:grid;
      place-items:center;
      color:var(--evapp-primary);
      background:var(--evapp-primary-soft);
      border-radius:13px;
    }
    .evapp-face-checkin-app .evfc-event-icon svg {
      width:22px;
      height:22px;
      fill:none;
      stroke:currentColor;
      stroke-width:1.9;
      stroke-linecap:round;
      stroke-linejoin:round;
    }
    .evapp-face-checkin-app .evfc-event-copy { min-width:0; }
    .evapp-face-checkin-app .evfc-event-kicker {
      display:block;
      margin-bottom:3px;
      color:var(--evapp-muted);
      font-size:11px;
      font-weight:800;
      letter-spacing:.09em;
      text-transform:uppercase;
    }
    .evapp-face-checkin-app .evfc-event-name {
      display:block;
      overflow:hidden;
      color:var(--evapp-text);
      font-size:15px;
      font-weight:800;
      line-height:1.3;
      text-overflow:ellipsis;
      white-space:nowrap;
    }
    .evapp-face-checkin-app .evfc-event-meta {
      display:flex;
      align-items:center;
      justify-content:flex-end;
      flex-wrap:wrap;
      gap:8px;
    }
    .evapp-face-checkin-app .evfc-chip {
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
    .evapp-face-checkin-app .evfc-chip::before {
      width:7px;
      height:7px;
      border-radius:50%;
      background:#94a3b8;
      content:"";
    }
    .evapp-face-checkin-app .evfc-chip.is-valid {
      color:var(--evapp-success);
      border-color:#cfeadf;
      background:var(--evapp-success-soft);
    }
    .evapp-face-checkin-app .evfc-chip.is-valid::before { background:var(--evapp-success); }
    .evapp-face-checkin-app .evfc-chip.is-warning {
      color:var(--evapp-warning);
      border-color:#f1dfad;
      background:var(--evapp-warning-soft);
    }
    .evapp-face-checkin-app .evfc-chip.is-warning::before { background:#d69e2e; }
    .evapp-face-checkin-app .evfc-chip.is-cache {
      display:none;
      color:var(--evapp-success);
      border-color:#cfeadf;
      background:var(--evapp-success-soft);
    }
    .evapp-face-checkin-app .evfc-chip.is-cache::before { background:var(--evapp-success); }
    .evapp-face-checkin-app .evfc-chip.is-cache.is-visible { display:inline-flex; }

    .evapp-face-checkin-app .evfc-layout {
      display:grid;
      grid-template-columns:minmax(0,1.18fr) minmax(320px,.82fr);
      gap:20px;
      align-items:start;
    }
    .evapp-face-checkin-app .evfc-panel {
      min-width:0;
      background:var(--evapp-surface);
      border:1px solid var(--evapp-border);
      border-radius:var(--evapp-radius);
      box-shadow:0 8px 26px rgba(31,65,99,.05);
    }
    .evapp-face-checkin-app .evfc-scanner-panel { padding:18px; }
    .evapp-face-checkin-app .evfc-result-panel { position:sticky; top:18px; padding:18px; }
    body.admin-bar .evapp-face-checkin-app .evfc-result-panel { top:50px; }
    .evapp-face-checkin-app .evfc-panel-head {
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:14px;
      margin-bottom:15px;
    }
    .evapp-face-checkin-app .evfc-panel-title {
      margin:0;
      color:var(--evapp-text);
      font-size:17px;
      font-weight:800;
      line-height:1.3;
    }
    .evapp-face-checkin-app .evfc-panel-desc {
      margin:5px 0 0;
      color:var(--evapp-muted);
      font-size:13px;
      line-height:1.5;
    }
    .evapp-face-checkin-app .evfc-camera-state {
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
    .evapp-face-checkin-app .evfc-camera-state::before {
      width:7px;
      height:7px;
      border-radius:50%;
      background:#94a3b8;
      content:"";
    }
    .evapp-face-checkin-app .evfc-camera-state.is-live {
      color:var(--evapp-success);
      background:var(--evapp-success-soft);
      border-color:#cfeadf;
    }
    .evapp-face-checkin-app .evfc-camera-state.is-live::before {
      background:var(--evapp-success);
      box-shadow:0 0 0 4px rgba(22,133,91,.10);
    }
    .evapp-face-checkin-app .evfc-camera-state.is-busy {
      color:var(--evapp-primary-dark);
      background:var(--evapp-primary-soft);
      border-color:#cfe3f6;
    }
    .evapp-face-checkin-app .evfc-camera-state.is-busy::before {
      background:var(--evapp-primary);
      animation:evfcPulse 1s ease-in-out infinite;
    }

    .evapp-face-checkin-app .evapp-face-btn {
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
      transition:transform .16s ease,box-shadow .16s ease,background .16s ease,opacity .16s ease;
      -webkit-tap-highlight-color:transparent;
    }
    .evapp-face-checkin-app .evapp-face-btn:hover:not(:disabled) {
      transform:translateY(-1px);
      background:var(--evapp-primary-dark);
      box-shadow:0 12px 24px rgba(50,121,189,.24);
    }
    .evapp-face-checkin-app .evapp-face-btn:focus-visible,
    .evapp-face-checkin-app .evapp-face-btn-cache:focus-visible {
      outline:3px solid rgba(50,121,189,.22);
      outline-offset:2px;
    }
    .evapp-face-checkin-app .evapp-face-btn:disabled {
      opacity:.56;
      cursor:not-allowed;
      transform:none;
      box-shadow:none;
    }
    .evapp-face-checkin-app .evapp-face-btn.is-live {
      background:var(--evapp-danger);
      box-shadow:0 9px 20px rgba(197,58,58,.17);
    }
    .evapp-face-checkin-app .evapp-face-btn.is-live:hover:not(:disabled) { background:#a92f2f; }
    .evapp-face-checkin-app .evapp-face-btn svg {
      width:19px;
      height:19px;
      flex:0 0 19px;
      fill:none;
      stroke:currentColor;
      stroke-width:2;
      stroke-linecap:round;
      stroke-linejoin:round;
    }

    .evapp-face-checkin-app .evfc-camera-wrap {
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
    .evapp-face-checkin-app .evfc-camera-placeholder {
      position:absolute;
      inset:0;
      z-index:1;
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
    .evapp-face-checkin-app .evfc-camera-placeholder svg {
      width:46px;
      height:46px;
      fill:none;
      stroke:#6f8ca8;
      stroke-width:1.6;
      stroke-linecap:round;
      stroke-linejoin:round;
    }
    .evapp-face-checkin-app .evfc-camera-placeholder strong { color:#f8fafc; font-size:14px; }
    .evapp-face-checkin-app .evfc-camera-placeholder span {
      max-width:320px;
      color:#94a3b8;
      font-size:12px;
      line-height:1.5;
    }
    .evapp-face-checkin-app .evfc-camera-wrap.is-active .evfc-camera-placeholder { display:none; }
    .evapp-face-checkin-app #evapp-face-video,
    .evapp-face-checkin-app #evapp-face-canvas {
      position:absolute;
      inset:0;
      width:100%;
      height:100%;
    }
    .evapp-face-checkin-app #evapp-face-video {
      display:none;
      object-fit:cover;
      background:#0f172a;
    }
    .evapp-face-checkin-app .evfc-camera-wrap.is-active #evapp-face-video { display:block; }
    .evapp-face-checkin-app #evapp-face-canvas { z-index:2; pointer-events:none; }
    .evapp-face-checkin-app .evapp-face-guide {
      position:absolute;
      z-index:3;
      top:50%;
      left:50%;
      display:none;
      width:min(56%,260px);
      aspect-ratio:3/4;
      border:3px dashed rgba(111,184,244,.88);
      border-radius:50% 50% 46% 46% / 42% 42% 58% 58%;
      filter:drop-shadow(0 2px 5px rgba(0,0,0,.26));
      transform:translate(-50%,-50%);
      pointer-events:none;
    }
    .evapp-face-checkin-app .evfc-camera-wrap.is-active .evapp-face-guide { display:block; }
    .evapp-face-checkin-app .evapp-face-overlay {
      position:absolute;
      z-index:4;
      left:16px;
      right:16px;
      bottom:14px;
      display:none;
      padding:8px 12px;
      border:1px solid rgba(255,255,255,.14);
      border-radius:999px;
      background:rgba(8,15,27,.72);
      color:#f8fafc;
      font-size:12px;
      font-weight:750;
      line-height:1.35;
      text-align:center;
      text-shadow:0 1px 3px #000;
      backdrop-filter:blur(8px);
      pointer-events:none;
    }
    .evapp-face-checkin-app .evfc-camera-wrap.is-active .evapp-face-overlay { display:block; }

    .evapp-face-checkin-app .evfc-progress {
      display:none;
      margin-top:12px;
      overflow:hidden;
      height:7px;
      background:#e8eef5;
      border-radius:999px;
    }
    .evapp-face-checkin-app .evfc-progress.is-visible { display:block; }
    .evapp-face-checkin-app .evfc-progress-bar {
      width:0;
      height:100%;
      background:linear-gradient(90deg,var(--evapp-primary),#5ca8e8);
      border-radius:999px;
      transition:width .25s ease;
    }
    .evapp-face-checkin-app .evfc-status-row {
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      min-height:34px;
      margin-top:10px;
    }
    .evapp-face-checkin-app .evapp-face-status {
      min-width:0;
      color:var(--evapp-muted);
      font-size:12px;
      line-height:1.45;
    }
    .evapp-face-checkin-app .evapp-face-spinner {
      display:inline-block;
      width:14px;
      height:14px;
      margin-right:6px;
      border:2px solid #c8d5e2;
      border-top-color:var(--evapp-primary);
      border-radius:50%;
      animation:evfcSpin .7s linear infinite;
      vertical-align:-2px;
    }
    .evapp-face-checkin-app .evapp-face-btn-cache {
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:7px;
      min-height:36px;
      border:1px solid var(--evapp-border);
      border-radius:10px;
      padding:8px 11px;
      background:#fff;
      color:var(--evapp-primary-dark);
      font:inherit;
      font-size:12px;
      font-weight:800;
      cursor:pointer;
      transition:background .16s ease,border-color .16s ease,color .16s ease;
      white-space:nowrap;
    }
    .evapp-face-checkin-app .evapp-face-btn-cache:hover:not(:disabled) {
      background:var(--evapp-primary-soft);
      border-color:#c4dbee;
    }
    .evapp-face-checkin-app .evapp-face-btn-cache:disabled { opacity:.55; cursor:not-allowed; }

    .evapp-face-checkin-app .evfc-guide {
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:8px;
      margin-top:12px;
    }
    .evapp-face-checkin-app .evfc-guide-item {
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
    .evapp-face-checkin-app .evfc-guide-item svg {
      width:15px;
      height:15px;
      flex:0 0 15px;
      fill:none;
      stroke:var(--evapp-primary);
      stroke-width:1.8;
      stroke-linecap:round;
      stroke-linejoin:round;
    }

    .evapp-face-checkin-app .evfc-result-empty {
      display:flex;
      align-items:flex-start;
      gap:10px;
      min-height:130px;
      padding:14px;
      border:1px solid #dbe7f2;
      border-radius:13px;
      background:#f8fbfe;
      color:var(--evapp-muted);
      font-size:13px;
      line-height:1.55;
    }
    .evapp-face-checkin-app .evfc-result-empty svg {
      width:20px;
      height:20px;
      flex:0 0 20px;
      margin-top:1px;
      fill:none;
      stroke:var(--evapp-primary);
      stroke-width:1.8;
      stroke-linecap:round;
      stroke-linejoin:round;
    }
    .evapp-face-checkin-app .evapp-face-result {
      display:none;
      padding:16px;
      border:1px solid var(--evapp-border);
      border-left-width:4px;
      border-radius:14px;
      background:#fff;
    }
    .evapp-face-checkin-app .evapp-face-result.is-visible { display:block; }
    .evapp-face-checkin-app .evapp-face-result.is-ok { border-left-color:var(--evapp-success); background:var(--evapp-success-soft); }
    .evapp-face-checkin-app .evapp-face-result.is-warn { border-left-color:#d69e2e; background:var(--evapp-warning-soft); }
    .evapp-face-checkin-app .evapp-face-result.is-err { border-left-color:var(--evapp-danger); background:var(--evapp-danger-soft); }
    .evapp-face-checkin-app .evapp-face-result-name {
      margin:0 0 7px;
      color:var(--evapp-text);
      font-size:16px;
      font-weight:850;
      line-height:1.3;
    }
    .evapp-face-checkin-app .evapp-face-result-meta {
      color:#415064;
      font-size:13px;
      line-height:1.65;
      overflow-wrap:anywhere;
    }

    .evapp-face-checkin-app .evfc-stats {
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:9px;
      margin-top:14px;
    }
    .evapp-face-checkin-app .evfc-stat {
      min-width:0;
      padding:12px 10px;
      border:1px solid var(--evapp-border);
      border-radius:12px;
      background:#fbfdff;
      text-align:center;
    }
    .evapp-face-checkin-app .evfc-stat-number {
      display:block;
      color:var(--evapp-primary-dark);
      font-size:22px;
      font-weight:850;
      line-height:1.1;
    }
    .evapp-face-checkin-app .evfc-stat-label {
      display:block;
      margin-top:4px;
      color:var(--evapp-muted);
      font-size:10px;
      font-weight:750;
      line-height:1.3;
    }

    .evapp-face-checkin-app .evfc-note {
      margin-top:14px;
      padding:12px 13px;
      border:1px solid #dbe7f2;
      border-radius:12px;
      background:#f8fbfe;
      color:var(--evapp-muted);
      font-size:11px;
      line-height:1.5;
    }

    @keyframes evfcSpin { to { transform:rotate(360deg); } }
    @keyframes evfcPulse { 0%,100% { opacity:.45; } 50% { opacity:1; } }

    @media (max-width:900px) {
      .evapp-face-checkin-app .evfc-layout { grid-template-columns:1fr; }
      .evapp-face-checkin-app .evfc-result-panel { position:static; top:auto; }
      .evapp-face-checkin-app .evfc-camera-wrap { aspect-ratio:16/11; }
    }
    @media (max-width:680px) {
      .evapp-face-checkin-app .evfc-shell { padding:16px; border-radius:20px; }
      .evapp-face-checkin-app .evfc-header { flex-direction:column; gap:15px; }
      .evapp-face-checkin-app .evfc-header-actions,
      .evapp-face-checkin-app .evfc-header-actions .evfc-btn { width:100%; }
      .evapp-face-checkin-app .evfc-event-context { align-items:flex-start; flex-direction:column; }
      .evapp-face-checkin-app .evfc-event-meta { width:100%; justify-content:flex-start; }
      .evapp-face-checkin-app .evfc-panel-head { flex-direction:column; }
      .evapp-face-checkin-app .evfc-camera-state { align-self:flex-start; }
      .evapp-face-checkin-app .evfc-scanner-panel,
      .evapp-face-checkin-app .evfc-result-panel { padding:14px; }
      .evapp-face-checkin-app .evfc-camera-wrap { min-height:360px; aspect-ratio:3/4; border-radius:14px; }
      .evapp-face-checkin-app .evfc-guide { grid-template-columns:1fr; }
      .evapp-face-checkin-app .evfc-status-row { align-items:flex-start; flex-direction:column; }
      .evapp-face-checkin-app .evapp-face-btn-cache { width:100%; }
    }
    @media (max-width:420px) {
      .evapp-face-checkin-app .evfc-shell { padding:13px; }
      .evapp-face-checkin-app .evfc-title { font-size:28px; }
      .evapp-face-checkin-app .evfc-event-main { align-items:flex-start; }
      .evapp-face-checkin-app .evfc-event-name { white-space:normal; }
      .evapp-face-checkin-app .evfc-stats { grid-template-columns:1fr; }
      .evapp-face-checkin-app .evfc-stat { display:flex; align-items:center; justify-content:space-between; text-align:left; }
      .evapp-face-checkin-app .evfc-stat-label { margin:0; font-size:11px; }
    }
    @media (prefers-reduced-motion:reduce) {
      .evapp-face-checkin-app *,
      .evapp-face-checkin-app *::before,
      .evapp-face-checkin-app *::after {
        scroll-behavior:auto!important;
        transition:none!important;
        animation-duration:.001ms!important;
        animation-iteration-count:1!important;
      }
    }
    </style>

    <div
      class="evapp-face-checkin-app"
      id="<?php echo esc_attr( $instance_id ); ?>"
      data-event="<?php echo esc_attr( $event_id ); ?>"
      data-day-valid="<?php echo $day_is_valid ? '1' : '0'; ?>"
    >
      <div class="evfc-shell">
        <header class="evfc-header">
          <div class="evfc-heading">
            <p class="evfc-eyebrow">EVENTOSAPP</p>
            <h1 class="evfc-title">Check-In Facial</h1>
            <p class="evfc-subtitle">Identifica al asistente mediante reconocimiento facial y registra su ingreso en el evento activo sin cambiar la lógica actual de validación del ticket.</p>
          </div>
          <div class="evfc-header-actions">
            <a href="<?php echo esc_url( $dashboard_url ); ?>" class="evfc-btn evfc-btn-secondary" aria-label="Volver al dashboard">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg>
              <span>Volver al dashboard</span>
            </a>
          </div>
        </header>

        <section class="evfc-event-context" aria-label="Evento activo">
          <div class="evfc-event-main">
            <div class="evfc-event-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2"></rect><path d="M8 3v4M16 3v4M4 10h16"></path></svg>
            </div>
            <div class="evfc-event-copy">
              <span class="evfc-event-kicker">Evento activo</span>
              <span class="evfc-event-name"><?php echo esc_html( $event_name ); ?></span>
            </div>
          </div>
          <div class="evfc-event-meta">
            <span class="evfc-chip <?php echo $day_is_valid ? 'is-valid' : 'is-warning'; ?>"><?php echo esc_html( $day_context['label'] ); ?></span>
            <span class="evfc-chip <?php echo $day_is_valid ? 'is-valid' : 'is-warning'; ?>"><?php echo $day_is_valid ? 'Check-in habilitado hoy' : 'Fuera de fecha del evento'; ?></span>
          </div>
        </section>

        <div class="evfc-layout">
          <section class="evfc-panel evfc-scanner-panel" aria-label="Lector facial">
            <div class="evfc-panel-head">
              <div>
                <h2 class="evfc-panel-title">Lector de reconocimiento facial</h2>
                <p class="evfc-panel-desc">Usa la cámara frontal, mantén el rostro centrado y procura iluminación uniforme.</p>
              </div>
              <div class="evfc-camera-state" id="evapp-face-camera-state" aria-live="polite">Cámara inactiva</div>
            </div>

            <button class="evapp-face-btn" id="evapp-face-toggle-btn" type="button" disabled>
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8V6a2 2 0 0 1 2-2h2M3 16v2a2 2 0 0 0 2 2h2M21 8V6a2 2 0 0 0-2-2h-2M21 16v2a2 2 0 0 1-2 2h-2"></path><circle cx="12" cy="10" r="3"></circle><path d="M8 18c.8-2.3 2.2-3.5 4-3.5s3.2 1.2 4 3.5"></path></svg>
              <span id="evapp-face-btn-label"><?php echo $day_is_valid ? 'Cargando modelos…' : 'Check-in no disponible hoy'; ?></span>
            </button>

            <div class="evfc-camera-wrap" id="evapp-face-cam-wrap">
              <div class="evfc-camera-placeholder" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M3 8V6a2 2 0 0 1 2-2h2M3 16v2a2 2 0 0 0 2 2h2M21 8V6a2 2 0 0 0-2-2h-2M21 16v2a2 2 0 0 1-2 2h-2"></path><circle cx="12" cy="10" r="3"></circle><path d="M8 18c.8-2.3 2.2-3.5 4-3.5s3.2 1.2 4 3.5"></path></svg>
                <strong><?php echo $day_is_valid ? 'La cámara está apagada' : 'Check-in fuera de fecha'; ?></strong>
                <span><?php echo $day_is_valid ? 'Cuando los perfiles estén listos podrás activar la cámara para iniciar el reconocimiento.' : 'El reconocimiento no se inicia porque hoy no corresponde a una fecha habilitada del evento.'; ?></span>
              </div>
              <video id="evapp-face-video" autoplay muted playsinline></video>
              <canvas id="evapp-face-canvas"></canvas>
              <div class="evapp-face-guide" aria-hidden="true"></div>
              <div class="evapp-face-overlay" id="evapp-face-cam-label">Posiciona el rostro dentro del óvalo</div>
            </div>

            <div class="evfc-progress" id="evapp-face-progress-wrap" aria-hidden="true">
              <div class="evfc-progress-bar" id="evapp-face-progress-bar"></div>
            </div>

            <div class="evfc-status-row">
              <div class="evapp-face-status" id="evapp-face-status" aria-live="polite">
                <?php echo $day_is_valid ? 'Iniciando sistema de reconocimiento facial…' : 'El check-in solo está permitido en las fechas del evento.'; ?>
              </div>
              <button class="evapp-face-btn-cache" id="evapp-face-clear-cache" type="button" hidden>
                <span aria-hidden="true">↻</span>
                <span>Forzar recarga de perfiles</span>
              </button>
            </div>

            <div class="evfc-guide" aria-label="Consejos para reconocimiento facial">
              <div class="evfc-guide-item"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v3M12 19v3M2 12h3M19 12h3"></path></svg>Buena iluminación</div>
              <div class="evfc-guide-item"><svg viewBox="0 0 24 24"><path d="M4 12h16M8 8l-4 4 4 4M16 8l4 4-4 4"></path></svg>Rostro centrado</div>
              <div class="evfc-guide-item"><svg viewBox="0 0 24 24"><path d="M5 5l14 14M7 17c1.1-2.2 2.8-3.5 5-3.5 1.2 0 2.3.4 3.2 1"></path><circle cx="12" cy="9" r="3"></circle></svg>Evita cubrir el rostro</div>
            </div>
          </section>

          <section class="evfc-panel evfc-result-panel" aria-label="Resultado del reconocimiento">
            <div class="evfc-panel-head">
              <div>
                <h2 class="evfc-panel-title">Resultado del check-in</h2>
                <p class="evfc-panel-desc">Aquí verás la identificación, validación y datos del asistente.</p>
              </div>
              <span class="evfc-chip is-cache" id="evapp-face-cache-badge">Caché local</span>
            </div>

            <div class="evfc-result-empty" id="evapp-face-result-empty">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v4M12 16h.01"></path></svg>
              <span>Activa la cámara cuando el sistema indique que los perfiles están listos. El resultado de cada reconocimiento aparecerá en este panel.</span>
            </div>

            <div class="evapp-face-result" id="evapp-face-result" aria-live="polite" aria-atomic="false">
              <div class="evapp-face-result-name" id="evapp-face-result-name"></div>
              <div class="evapp-face-result-meta" id="evapp-face-result-meta"></div>
            </div>

            <div class="evfc-stats" aria-label="Estado de la sesión facial">
              <div class="evfc-stat">
                <span class="evfc-stat-number" id="evapp-face-db-count">0</span>
                <span class="evfc-stat-label">Perfiles disponibles</span>
              </div>
              <div class="evfc-stat">
                <span class="evfc-stat-number" id="evapp-face-reference-count">0</span>
                <span class="evfc-stat-label">Referencias faciales</span>
              </div>
              <div class="evfc-stat">
                <span class="evfc-stat-number" id="evapp-face-checkin-count">0</span>
                <span class="evfc-stat-label">Check-ins de esta sesión</span>
              </div>
            </div>

            <div class="evfc-note">Los descriptores faciales se procesan en el navegador y se reutilizan mediante IndexedDB. La validación final del ingreso continúa realizándose en el servidor sobre el ticket del evento activo.</div>
          </section>
        </div>
      </div>
    </div>

    <script>
    (function () {
        'use strict';

        const root = document.getElementById('<?php echo esc_js( $instance_id ); ?>');
        if (!root || root.dataset.evappFaceReady === '1') return;
        root.dataset.evappFaceReady = '1';

        const AJAX_URL   = '<?php echo esc_js( $ajax_url ); ?>';
        const EVENT_ID   = <?php echo (int) $event_id; ?>;
        const NONCE_LOAD = '<?php echo esc_js( $nonce_load ); ?>';
        const NONCE_CI   = '<?php echo esc_js( $nonce_checkin ); ?>';
        const MODELS_URL = '<?php echo esc_js( $models_url ); ?>';
        const DAY_VALID  = root.dataset.dayValid === '1';

        // Menor distancia = coincidencia más estricta.
        const MATCH_THRESHOLD = 0.55;
        const DETECT_INTERVAL_MS = 1200;
        const IDB_NAME    = 'EvappFaceCache';
        const IDB_VERSION = 1;
        const IDB_STORE   = 'descriptors';

        let faceDB         = [];
        let faceMatcher    = null;
        let profileNames   = new Map();
        let isLive         = false;
        let stream         = null;
        let detectTimer    = null;
        let lastCedula     = null;
        let lastCedulaTs   = 0;
        let checkinCount   = 0;
        let isProcessing   = false;
        let activeCacheKey = null;
        let detectorOptions = null;

        const video         = root.querySelector('#evapp-face-video');
        const canvas        = root.querySelector('#evapp-face-canvas');
        const camWrap       = root.querySelector('#evapp-face-cam-wrap');
        const toggleBtn     = root.querySelector('#evapp-face-toggle-btn');
        const btnLabel      = root.querySelector('#evapp-face-btn-label');
        const statusEl      = root.querySelector('#evapp-face-status');
        const resultEl      = root.querySelector('#evapp-face-result');
        const resultEmpty   = root.querySelector('#evapp-face-result-empty');
        const resultName    = root.querySelector('#evapp-face-result-name');
        const resultMeta    = root.querySelector('#evapp-face-result-meta');
        const camLabel      = root.querySelector('#evapp-face-cam-label');
        const cameraState   = root.querySelector('#evapp-face-camera-state');
        const dbCount       = root.querySelector('#evapp-face-db-count');
        const referenceCount= root.querySelector('#evapp-face-reference-count');
        const ciCount       = root.querySelector('#evapp-face-checkin-count');
        const progWrap      = root.querySelector('#evapp-face-progress-wrap');
        const progBar       = root.querySelector('#evapp-face-progress-bar');
        const clearCacheBtn = root.querySelector('#evapp-face-clear-cache');
        const cacheBadge    = root.querySelector('#evapp-face-cache-badge');

        if (!video || !canvas || !camWrap || !toggleBtn || !btnLabel || !statusEl) return;

        function setStatus(message, spin) {
            statusEl.textContent = '';
            if (spin) {
                const spinner = document.createElement('span');
                spinner.className = 'evapp-face-spinner';
                spinner.setAttribute('aria-hidden', 'true');
                statusEl.appendChild(spinner);
            }
            statusEl.appendChild(document.createTextNode(String(message || '')));
        }

        function setCameraState(label, state) {
            if (!cameraState) return;
            cameraState.textContent = label;
            cameraState.classList.remove('is-live', 'is-busy');
            if (state === 'live') cameraState.classList.add('is-live');
            if (state === 'busy') cameraState.classList.add('is-busy');
        }

        function showResult(type, name, meta) {
            if (resultEmpty) resultEmpty.hidden = true;
            resultEl.className = 'evapp-face-result is-visible is-' + type;
            resultName.textContent = name;
            resultMeta.innerHTML = meta;
        }

        function clearResult() {
            resultEl.className = 'evapp-face-result';
            resultName.textContent = '';
            resultMeta.textContent = '';
            if (resultEmpty) resultEmpty.hidden = false;
        }

        function setProgress(pct) {
            const safePct = Math.max(0, Math.min(100, Number(pct) || 0));
            progWrap.classList.add('is-visible');
            progBar.style.width = safePct + '%';
            if (safePct >= 100) {
                window.setTimeout(function () {
                    progWrap.classList.remove('is-visible');
                }, 800);
            }
        }

        function setCacheIndicator(isCached) {
            if (cacheBadge) cacheBadge.classList.toggle('is-visible', !!isCached);
            if (clearCacheBtn) clearCacheBtn.hidden = !activeCacheKey;
        }

        function cameraErrorMessage(error) {
            const name = error && error.name ? String(error.name) : '';
            if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
                return 'El navegador no tiene permiso para usar la cámara. Habilita el permiso para este sitio y vuelve a intentar.';
            }
            if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
                return 'No se encontró una cámara disponible en este dispositivo.';
            }
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                return 'Este navegador no permite acceder a la cámara desde esta página. Verifica HTTPS y que el navegador esté actualizado.';
            }
            return 'No se pudo acceder a la cámara. Revisa permisos del navegador y vuelve a intentar.';
        }

        function openCacheDB() {
            return new Promise(function(resolve) {
                if (!window.indexedDB) { resolve(null); return; }
                const req = indexedDB.open(IDB_NAME, IDB_VERSION);
                req.onupgradeneeded = function(e) {
                    const db = e.target.result;
                    if (!db.objectStoreNames.contains(IDB_STORE)) {
                        db.createObjectStore(IDB_STORE, { keyPath: 'key' });
                    }
                };
                req.onsuccess = function(e) { resolve(e.target.result); };
                req.onerror   = function()  { resolve(null); };
            });
        }

        async function getCachedDescriptors(cacheKey) {
            try {
                const db = await openCacheDB();
                if (!db) return null;
                return new Promise(function(resolve) {
                    const tx  = db.transaction(IDB_STORE, 'readonly');
                    const req = tx.objectStore(IDB_STORE).get(cacheKey);
                    req.onsuccess = function(e) {
                        resolve(e.target.result ? e.target.result.data : null);
                    };
                    req.onerror = function() { resolve(null); };
                });
            } catch (e) {
                return null;
            }
        }

        async function saveCachedDescriptors(cacheKey, data) {
            try {
                const db = await openCacheDB();
                if (!db) return;
                return new Promise(function(resolve) {
                    const tx = db.transaction(IDB_STORE, 'readwrite');
                    tx.objectStore(IDB_STORE).put({
                        key: cacheKey,
                        data: data,
                        timestamp: Date.now()
                    });
                    tx.oncomplete = function() { resolve(); };
                    tx.onerror    = function() { resolve(); };
                });
            } catch (e) {}
        }

        async function deleteCachedDescriptors(cacheKey) {
            try {
                const db = await openCacheDB();
                if (!db) return;
                return new Promise(function(resolve) {
                    const tx = db.transaction(IDB_STORE, 'readwrite');
                    tx.objectStore(IDB_STORE).delete(cacheKey);
                    tx.oncomplete = function() { resolve(); };
                    tx.onerror    = function() { resolve(); };
                });
            } catch (e) {}
        }

        /**
         * Agrupa las múltiples fotos del mismo asistente y construye el matcher
         * una sola vez. Antes se reconstruía en cada detección de cámara.
         */
        function rebuildMatcher() {
            faceMatcher  = null;
            profileNames = new Map();

            if (!window.faceapi || !Array.isArray(faceDB) || faceDB.length === 0) {
                dbCount.textContent = '0';
                referenceCount.textContent = '0';
                return 0;
            }

            const grouped = new Map();
            faceDB.forEach(function(item) {
                const cedula = String(item.cedula || '').trim();
                if (!cedula || !(item.descriptor instanceof Float32Array)) return;

                if (!grouped.has(cedula)) {
                    grouped.set(cedula, { descriptors: [], nombre: String(item.nombre || cedula) });
                }
                grouped.get(cedula).descriptors.push(item.descriptor);
            });

            const labeled = [];
            grouped.forEach(function(value, cedula) {
                if (!value.descriptors.length) return;
                labeled.push(new faceapi.LabeledFaceDescriptors(cedula, value.descriptors));
                profileNames.set(cedula, value.nombre || cedula);
            });

            if (labeled.length) {
                faceMatcher = new faceapi.FaceMatcher(labeled, MATCH_THRESHOLD);
            }

            dbCount.textContent = String(labeled.length);
            referenceCount.textContent = String(faceDB.length);
            return labeled.length;
        }

        function setReadyState() {
            const profiles = rebuildMatcher();
            if (!profiles || !faceMatcher) {
                toggleBtn.disabled = true;
                btnLabel.textContent = 'Sin perfiles disponibles';
                setCameraState('Sin perfiles', '');
                return false;
            }

            toggleBtn.disabled = !DAY_VALID;
            btnLabel.textContent = DAY_VALID ? 'Activar cámara' : 'Check-in no disponible hoy';
            setCameraState(DAY_VALID ? 'Cámara inactiva' : 'Fuera de fecha', '');
            return DAY_VALID;
        }

        async function loadModels() {
            if (!DAY_VALID) return;
            if (!window.faceapi || !faceapi.nets) {
                setStatus('No fue posible cargar la librería local de reconocimiento facial.', false);
                btnLabel.textContent = 'Reconocimiento no disponible';
                setCameraState('Error de carga', '');
                return;
            }

            setStatus('Cargando modelos de reconocimiento facial…', true);
            setCameraState('Preparando', 'busy');
            setProgress(5);

            try {
                await faceapi.nets.ssdMobilenetv1.loadFromUri(MODELS_URL);
                setProgress(40);
                await faceapi.nets.faceLandmark68Net.loadFromUri(MODELS_URL);
                setProgress(70);
                await faceapi.nets.faceRecognitionNet.loadFromUri(MODELS_URL);
                setProgress(90);
                detectorOptions = new faceapi.SsdMobilenetv1Options({ minConfidence: 0.5 });
                setStatus('Modelos cargados. Cargando perfiles de asistentes…', true);
                await loadAsistentes(false);
                setProgress(100);
            } catch (e) {
                setStatus('Error cargando los modelos de reconocimiento facial.', false);
                btnLabel.textContent = 'Reconocimiento no disponible';
                setCameraState('Error de carga', '');
                console.error('[FaceCheckin] Error modelos:', e);
            }
        }

        async function loadAsistentes(forceReload) {
            try {
                const res = await fetch(AJAX_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'evapp_get_asistentes_face_data',
                        security: NONCE_LOAD,
                        event_id: String(EVENT_ID),
                        force_refresh: forceReload ? '1' : '0'
                    })
                });
                const data = await res.json();

                if (!data.success || !data.data || !Array.isArray(data.data.asistentes) || !data.data.asistentes.length) {
                    faceDB = [];
                    rebuildMatcher();
                    setStatus('No se encontraron asistentes con foto registrada para este evento.', false);
                    btnLabel.textContent = 'Sin perfiles disponibles';
                    toggleBtn.disabled = true;
                    setCameraState('Sin perfiles', '');
                    return;
                }

                const lista    = data.data.asistentes;
                const total    = Number(data.data.total) || lista.length;
                const cacheVer = String(data.data.cache_version || 'v1');
                const cacheKey = 'evapp_e' + EVENT_ID + '_' + cacheVer;
                activeCacheKey = cacheKey;

                if (!forceReload) {
                    const cached = await getCachedDescriptors(cacheKey);
                    if (cached && cached.length > 0) {
                        faceDB = cached.map(function(c) {
                            return {
                                cedula: c.cedula,
                                nombre: c.nombre,
                                descriptor: new Float32Array(c.descriptor)
                            };
                        });

                        const profiles = rebuildMatcher();
                        setCacheIndicator(true);
                        if (profiles > 0) {
                            setStatus(profiles + ' perfiles cargados desde caché local.', false);
                            setReadyState();
                            return;
                        }
                    }
                }

                setCacheIndicator(false);
                setStatus('Procesando ' + total + ' perfiles del evento…', true);
                faceDB = [];

                let procesados = 0;
                for (const asistente of lista) {
                    const photoUrls = (Array.isArray(asistente.foto_urls) && asistente.foto_urls.length)
                        ? asistente.foto_urls
                        : [asistente.foto_url];

                    for (const photoUrl of photoUrls) {
                        if (!photoUrl) continue;
                        try {
                            const img = await faceapi.fetchImage(photoUrl);
                            const detection = await faceapi
                                .detectSingleFace(img, detectorOptions || new faceapi.SsdMobilenetv1Options({ minConfidence: 0.5 }))
                                .withFaceLandmarks()
                                .withFaceDescriptor();

                            if (detection) {
                                faceDB.push({
                                    cedula: asistente.cedula,
                                    nombre: asistente.nombre,
                                    descriptor: detection.descriptor
                                });
                            }
                        } catch (imgErr) {
                            console.warn('[FaceCheckin] No se pudo procesar foto de:', asistente.nombre, '|', photoUrl, imgErr && imgErr.message ? imgErr.message : imgErr);
                        }
                    }

                    procesados++;
                    const pct = Math.round((procesados / Math.max(total, 1)) * 100);
                    setProgress(90 + (pct * 0.1));
                    setStatus('Procesando ' + procesados + '/' + total + ' perfiles…', true);
                }

                if (faceDB.length > 0) {
                    await saveCachedDescriptors(cacheKey, faceDB.map(function(f) {
                        return {
                            cedula: f.cedula,
                            nombre: f.nombre,
                            descriptor: Array.from(f.descriptor)
                        };
                    }));
                    setCacheIndicator(true);
                }

                const profiles = rebuildMatcher();
                if (profiles === 0) {
                    setStatus('Ninguna foto produjo un rostro utilizable. Verifica las fotos registradas.', false);
                    btnLabel.textContent = 'Sin perfiles disponibles';
                    toggleBtn.disabled = true;
                    setCameraState('Sin perfiles', '');
                } else {
                    setStatus(profiles + ' perfiles y ' + faceDB.length + ' referencias faciales listos.', false);
                    setReadyState();
                }

            } catch (e) {
                setStatus('Error cargando perfiles de asistentes.', false);
                btnLabel.textContent = 'Error cargando perfiles';
                toggleBtn.disabled = true;
                setCameraState('Error de carga', '');
                console.error('[FaceCheckin] Error asistentes:', e);
            }
        }

        if (clearCacheBtn) {
            clearCacheBtn.addEventListener('click', async function() {
                if (isLive) {
                    setStatus('Detén la cámara antes de recargar los perfiles.', false);
                    return;
                }

                clearCacheBtn.disabled = true;
                if (activeCacheKey) {
                    await deleteCachedDescriptors(activeCacheKey);
                }

                faceDB = [];
                faceMatcher = null;
                profileNames = new Map();
                dbCount.textContent = '0';
                referenceCount.textContent = '0';
                setCacheIndicator(false);
                toggleBtn.disabled = true;
                btnLabel.textContent = 'Recargando perfiles…';
                setCameraState('Actualizando', 'busy');
                setStatus('Recargando perfiles desde el servidor…', true);

                await loadAsistentes(true);
                clearCacheBtn.disabled = false;
            });
        }

        toggleBtn.addEventListener('click', async function() {
            if (!isLive) {
                await startCamera();
            } else {
                stopCamera();
            }
        });

        async function startCamera() {
            if (!DAY_VALID) {
                setStatus('El check-in no está habilitado hoy para este evento.', false);
                return;
            }
            if (!faceMatcher) {
                setStatus('Todavía no hay perfiles faciales disponibles para comparar.', false);
                return;
            }
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                setStatus(cameraErrorMessage(null), false);
                return;
            }

            toggleBtn.disabled = true;
            setCameraState('Activando', 'busy');
            setStatus('Solicitando acceso a la cámara…', true);

            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'user' },
                        width: { ideal: 960 },
                        height: { ideal: 720 }
                    },
                    audio: false
                });

                video.srcObject = stream;
                await video.play();

                canvas.width  = video.videoWidth || 640;
                canvas.height = video.videoHeight || 480;
                camWrap.classList.add('is-active');
                toggleBtn.classList.add('is-live');
                toggleBtn.disabled = false;
                btnLabel.textContent = 'Detener cámara';
                isLive = true;

                setCameraState('Cámara activa', 'live');
                setStatus('Cámara activa. Posiciona el rostro dentro del óvalo.', false);
                clearResult();
                startDetection();
            } catch (e) {
                toggleBtn.disabled = false;
                btnLabel.textContent = 'Activar cámara';
                setCameraState('Cámara inactiva', '');
                setStatus(cameraErrorMessage(e), false);
                console.error('[FaceCheckin] Cámara:', e);
            }
        }

        function stopCamera() {
            if (detectTimer) {
                clearTimeout(detectTimer);
                detectTimer = null;
            }
            if (stream) {
                stream.getTracks().forEach(function(track) { track.stop(); });
                stream = null;
            }

            try { video.pause(); } catch (e) {}
            video.srcObject = null;
            camWrap.classList.remove('is-active');
            toggleBtn.classList.remove('is-live');
            toggleBtn.disabled = !faceMatcher || !DAY_VALID;
            btnLabel.textContent = DAY_VALID ? 'Activar cámara' : 'Check-in no disponible hoy';
            isLive = false;
            isProcessing = false;
            lastCedula = null;
            setCameraState('Cámara inactiva', '');
            setStatus('Cámara detenida.', false);

            const ctx = canvas.getContext('2d');
            if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
        }

        function startDetection() {
            if (detectTimer) clearTimeout(detectTimer);
            detectTimer = window.setTimeout(detectionLoop, 150);
        }

        async function detectionLoop() {
            if (!isLive) return;
            await runDetection();
            if (isLive) {
                detectTimer = window.setTimeout(detectionLoop, DETECT_INTERVAL_MS);
            }
        }

        async function runDetection() {
            if (!isLive || isProcessing || !faceMatcher || document.hidden) return;
            if (video.readyState < 2) return;

            isProcessing = true;
            try {
                const detection = await faceapi
                    .detectSingleFace(video, detectorOptions || new faceapi.SsdMobilenetv1Options({ minConfidence: 0.5 }))
                    .withFaceLandmarks()
                    .withFaceDescriptor();

                const ctx = canvas.getContext('2d');
                if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);

                if (!detection) {
                    camLabel.textContent = 'Posiciona el rostro dentro del óvalo';
                    return;
                }

                const dims = faceapi.matchDimensions(canvas, video, true);
                const resized = faceapi.resizeResults(detection, dims);
                faceapi.draw.drawDetections(canvas, resized);

                const match = faceMatcher.findBestMatch(detection.descriptor);
                if (match.label === 'unknown') {
                    camLabel.textContent = 'Rostro no reconocido. Acércate o mejora la iluminación.';
                    return;
                }

                const cedula = String(match.label);
                const now = Date.now();
                if (cedula === lastCedula && (now - lastCedulaTs) < 8000) {
                    camLabel.textContent = 'Ya identificado. Espera unos segundos.';
                    return;
                }

                lastCedula = cedula;
                lastCedulaTs = now;
                const nombre = profileNames.get(cedula) || cedula;

                camLabel.textContent = 'Identificando: ' + nombre + '…';
                setCameraState('Validando ticket', 'busy');
                setStatus('Rostro reconocido. Buscando ticket…', true);
                await procesarCheckin(cedula, match.distance);
                if (isLive) setCameraState('Cámara activa', 'live');

            } catch (e) {
                console.warn('[FaceCheckin] Error detección:', e);
            } finally {
                isProcessing = false;
            }
        }

        async function procesarCheckin(cedula, distance) {
            try {
                const res = await fetch(AJAX_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'evapp_face_checkin_process',
                        security: NONCE_CI,
                        event_id: String(EVENT_ID),
                        cedula: cedula
                    })
                });
                const data = await res.json();

                if (data.success) {
                    const d = data.data || {};
                    const tipo = d.already ? 'warn' : 'ok';
                    const titulo = d.already ? 'Ya hizo check-in hoy' : 'Check-In Exitoso';

                    let metaHtml = '';
                    if (d.full_name)   metaHtml += '<strong>' + escapeHtml(d.full_name) + '</strong><br>';
                    if (d.cedula)      metaHtml += 'Cédula: ' + escapeHtml(d.cedula) + '<br>';
                    if (d.company)     metaHtml += 'Empresa: ' + escapeHtml(d.company) + '<br>';
                    if (d.designation) metaHtml += 'Cargo: ' + escapeHtml(d.designation) + '<br>';
                    if (d.localidad)   metaHtml += 'Localidad: ' + escapeHtml(d.localidad) + '<br>';
                    if (d.checkin_date_label) metaHtml += 'Fecha: ' + escapeHtml(d.checkin_date_label) + '<br>';
                    metaHtml += 'Coincidencia facial: ' + Math.max(0, (1 - Number(distance || 0))).toFixed(2);
                    if (d.payment_message) metaHtml += '<br>' + escapeHtml(d.payment_message);

                    showResult(tipo, titulo, metaHtml);
                    setStatus(tipo === 'ok' ? 'Check-in registrado correctamente.' : 'El asistente ya había ingresado hoy.', false);
                    camLabel.textContent = tipo === 'ok' ? 'Check-in registrado' : 'Asistente ya ingresó';

                    if (!d.already) {
                        checkinCount++;
                        ciCount.textContent = String(checkinCount);
                    }
                } else {
                    const errMsg = data && data.data && data.data.error ? data.data.error : 'Error desconocido';
                    showResult('err', 'No se pudo hacer Check-In', escapeHtml(errMsg));
                    setStatus(errMsg, false);
                    camLabel.textContent = 'No fue posible validar el ticket';
                }
            } catch (e) {
                setStatus('Error de conexión al procesar el check-in.', false);
                showResult('err', 'Error de conexión', 'No fue posible completar la validación. Vuelve a intentar.');
                console.error('[FaceCheckin] Error check-in AJAX:', e);
            }
        }

        function escapeHtml(str) {
            if (str === null || typeof str === 'undefined') return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        window.addEventListener('pagehide', function() {
            if (isLive) stopCamera();
        });

        if (DAY_VALID) {
            loadModels();
        } else {
            toggleBtn.disabled = true;
            setCameraState('Fuera de fecha', '');
        }

    })();
    </script>
    <?php
    return ob_get_clean();
} );


// ============================================================
// 2. AJAX: Cargar asistentes con foto SOLO del evento activo
//    NUEVO: filtra por cédulas de tickets del evento.
//           Retorna cache_version para invalidación en IndexedDB.
// ============================================================

add_action( 'wp_ajax_evapp_get_asistentes_face_data', function () {

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( ['error' => 'Debes iniciar sesión.'], 401 );
    }

    if ( function_exists('eventosapp_current_user_can_checkin') && ! eventosapp_current_user_can_checkin() ) {
        wp_send_json_error( ['error' => 'Permisos insuficientes.'], 403 );
    }

    // Mantiene el AJAX alineado con la visibilidad/permisos del dashboard.
    if ( function_exists('eventosapp_user_can_access_frontend_feature') && ! eventosapp_user_can_access_frontend_feature('face_checkin') ) {
        wp_send_json_error( ['error' => 'No tienes permisos para usar Check-In Facial.'], 403 );
    }

    check_ajax_referer( 'evapp_face_load_asistentes', 'security' );

    $event_id = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;

    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $event_id !== $active ) {
            wp_send_json_error( ['error' => 'Sin permisos para este evento.'], 403 );
        }
    }

    if ( ! $event_id ) {
        wp_send_json_error( ['error' => 'Evento no especificado.'] );
    }

    global $wpdb;

    $force_refresh = ! empty($_POST['force_refresh']);
    $cache_key = 'evapp_face_data_' . absint($event_id);

    if ( $force_refresh ) {
        delete_transient($cache_key);
    } else {
        $cached_payload = get_transient($cache_key);
        if ( is_array($cached_payload) ) {
            wp_send_json_success($cached_payload);
        }
    }

    // ── Paso 1: Obtener cédulas registradas en tickets del evento ──────────
    $cedulas_evento = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT pm_cc.meta_value
           FROM {$wpdb->postmeta} pm_ev
           JOIN {$wpdb->postmeta} pm_cc ON pm_cc.post_id = pm_ev.post_id
                                       AND pm_cc.meta_key = '_eventosapp_asistente_cc'
                                       AND pm_cc.meta_value != ''
           JOIN {$wpdb->posts} p       ON p.ID = pm_ev.post_id
          WHERE pm_ev.meta_key   = '_eventosapp_ticket_evento_id'
            AND pm_ev.meta_value = %s
            AND p.post_type      = 'eventosapp_ticket'
            AND p.post_status   != 'trash'",
        $event_id
    ) );

    if ( empty( $cedulas_evento ) ) {
        wp_send_json_success( [
            'asistentes'    => [],
            'total'         => 0,
            'cache_version' => 'empty',
        ] );
    }

    // ── Paso 2: Buscar asistentes CPT que tengan foto ──────────────────────
    $resultado = [];
    $lotes = array_chunk( $cedulas_evento, 100 );

    foreach ( $lotes as $lote ) {
        $placeholders = implode( ', ', array_fill( 0, count( $lote ), '%s' ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                p.ID            AS asistente_id,
                pm_ced.meta_value  AS cedula,
                pm_foto.meta_value AS foto_id,
                pm_nom.meta_value  AS nombres,
                pm_ape.meta_value  AS apellidos
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm_ced  ON pm_ced.post_id  = p.ID AND pm_ced.meta_key  = '_asistente_cedula'
             JOIN {$wpdb->postmeta} pm_foto ON pm_foto.post_id = p.ID AND pm_foto.meta_key = '_asistente_foto_id'
                                           AND pm_foto.meta_value != ''
             LEFT JOIN {$wpdb->postmeta} pm_nom ON pm_nom.post_id = p.ID AND pm_nom.meta_key = '_asistente_nombres'
             LEFT JOIN {$wpdb->postmeta} pm_ape ON pm_ape.post_id = p.ID AND pm_ape.meta_key = '_asistente_apellidos'
            WHERE p.post_type   = 'eventosapp_asistente'
              AND p.post_status = 'publish'
              AND pm_ced.meta_value IN ( $placeholders )",
            ...$lote
        ) );

        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $foto_id      = (int) $row->foto_id;
                $foto_url_pri = wp_get_attachment_url( $foto_id );
                if ( ! $foto_url_pri ) continue;

                // Construir array de todas las URLs: foto principal + fotos adicionales
                $foto_urls = [ $foto_url_pri ];

                $fotos_ids_json = get_post_meta( (int) $row->asistente_id, '_asistente_fotos_ids', true );
                if ( $fotos_ids_json ) {
                    $fotos_ids_extra = json_decode( $fotos_ids_json, true );
                    if ( is_array( $fotos_ids_extra ) ) {
                        foreach ( $fotos_ids_extra as $fid ) {
                            $fid = (int) $fid;
                            if ( $fid && $fid !== $foto_id ) {
                                $extra_url = wp_get_attachment_url( $fid );
                                if ( $extra_url ) $foto_urls[] = $extra_url;
                            }
                        }
                    }
                }

                $resultado[] = [
                    'asistente_id' => (int) $row->asistente_id,
                    'cedula'       => $row->cedula,
                    'nombre'       => trim( $row->nombres . ' ' . $row->apellidos ),
                    'foto_url'     => $foto_url_pri,              // backward compat
                    'foto_id'      => $foto_id,                   // backward compat
                    'foto_urls'    => array_values( $foto_urls ),  // nuevo: todas las fotos
                ];
            }
        }
    }

    // ── Paso 3: Generar cache_version (cambia si se agregan/quitan fotos) ──
    $total_urls = array_sum( array_map( function( $a ) { return count( $a['foto_urls'] ); }, $resultado ) );
    $version_data = array_map( function( $a ) {
        $urls = isset( $a['foto_urls'] ) && is_array( $a['foto_urls'] ) ? $a['foto_urls'] : [];
        sort( $urls );
        return implode( '|', [
            (string) $a['asistente_id'],
            (string) $a['cedula'],
            (string) $a['nombre'],
            implode( ',', $urls ),
        ] );
    }, $resultado );
    sort( $version_data );
    $cache_version = substr( md5( implode( '||', $version_data ) . '|n' . $total_urls ), 0, 12 );

    $response = [
        'asistentes'    => $resultado,
        'total'         => count( $resultado ),
        'cache_version' => $cache_version,
    ];

    // Cache corto para evitar reconstruir el dataset completo en cada recarga del lector facial.
    set_transient($cache_key, $response, 10 * MINUTE_IN_SECONDS);

    wp_send_json_success( $response );
} );


// ============================================================
// 3. AJAX: Procesar Check-In por cédula (reconocimiento facial)
// ============================================================

add_action( 'wp_ajax_evapp_face_checkin_process', function () {

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( ['error' => 'Debes iniciar sesión.'], 401 );
    }

    if ( function_exists('eventosapp_current_user_can_checkin') && ! eventosapp_current_user_can_checkin() ) {
        wp_send_json_error( ['error' => 'Permisos insuficientes para Check-In.'], 403 );
    }

    // Mantiene el endpoint protegido con la misma feature usada por el dashboard.
    if ( function_exists('eventosapp_user_can_access_frontend_feature') && ! eventosapp_user_can_access_frontend_feature('face_checkin') ) {
        wp_send_json_error( ['error' => 'No tienes permisos para usar Check-In Facial.'], 403 );
    }

    check_ajax_referer( 'evapp_face_checkin_process', 'security' );

    $cedula   = isset( $_POST['cedula'] )   ? sanitize_text_field( wp_unslash( $_POST['cedula'] ) ) : '';
    $event_id = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;

    // Validar evento activo para no-admins
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $event_id !== $active ) {
            wp_send_json_error( ['error' => 'Sin permisos para este evento.'], 403 );
        }
    }

    if ( ! $cedula || ! $event_id ) {
        wp_send_json_error( ['error' => 'Datos incompletos (cédula o evento).'] );
    }

    eventosapp_face_checkin_debug_log('Solicitud de check-in facial', [
        'event_id' => $event_id,
        'cedula'   => $cedula,
    ]);

    // Buscar ticket por cédula + evento usando la función existente
    $ticket_post_id = false;
    if ( function_exists( 'evapp_find_ticket_by_cedula_evento' ) ) {
        $ticket_post_id = evapp_find_ticket_by_cedula_evento( $cedula, $event_id );
    }

    if ( ! $ticket_post_id ) {
        // Intento directo por meta si la función no está disponible
        global $wpdb;
        $ticket_post_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT cc_pm.post_id
               FROM {$wpdb->postmeta} cc_pm
               INNER JOIN {$wpdb->postmeta} event_pm
                       ON event_pm.post_id = cc_pm.post_id
                      AND event_pm.meta_key = %s
                      AND event_pm.meta_value = %s
               INNER JOIN {$wpdb->posts} p
                       ON p.ID = cc_pm.post_id
                      AND p.post_type = 'eventosapp_ticket'
                      AND p.post_status NOT IN ('trash','auto-draft','inherit')
              WHERE cc_pm.meta_key = %s
                AND cc_pm.meta_value = %s
              LIMIT 1",
            '_eventosapp_ticket_evento_id',
            (string) absint($event_id),
            '_eventosapp_asistente_cc',
            $cedula
        ) );
        $ticket_post_id = $ticket_post_id ? absint($ticket_post_id) : false;
    }

    if ( ! $ticket_post_id ) {
        eventosapp_face_checkin_debug_log('Ticket no encontrado', [
            'event_id' => $event_id,
            'cedula'   => $cedula,
        ]);
        wp_send_json_error( ['error' => 'No se encontró ticket para esta persona en el evento activo.'] );
    }

    update_meta_cache('post', [$ticket_post_id, $event_id]);

    // ── Control de pago (misma lógica que QR checkin) ───────────────────────
    $control_pago_activo = ( get_post_meta( $event_id, '_eventosapp_ticket_control_pago', true ) === '1' );
    $mensaje_pago = '';

    if ( $control_pago_activo ) {
        $estado_pago = get_post_meta( $ticket_post_id, '_eventosapp_estado_pago', true );
        if ( empty( $estado_pago ) ) $estado_pago = 'no_pagado';

        if ( $estado_pago === 'no_pagado' ) {
            wp_send_json_error( [
                'error'            => '❌ Check-In rechazado: el ticket no ha sido pagado.',
                'payment_required' => true,
                'ticket_id'        => $ticket_post_id,
            ] );
        }
        $mensaje_pago = '💳 Ticket verificado como Pagado';
    }

    // ── Validación de fecha del evento ───────────────────────────────────────
    $event_tz = get_post_meta( $event_id, '_eventosapp_zona_horaria', true );
    if ( ! $event_tz ) {
        $event_tz = wp_timezone_string();
        if ( ! $event_tz || $event_tz === 'UTC' ) {
            $offset   = get_option( 'gmt_offset' );
            $event_tz = $offset ? timezone_name_from_abbr( '', $offset * 3600, 0 ) ?: 'UTC' : 'UTC';
        }
    }

    try {
        $dt = new DateTime( 'now', new DateTimeZone( $event_tz ) );
    } catch ( Exception $e ) {
        $dt = new DateTime( 'now', wp_timezone() );
    }
    $today = $dt->format( 'Y-m-d' );

    $days = function_exists( 'eventosapp_get_event_days' )
        ? (array) eventosapp_get_event_days( $event_id )
        : [];

    if ( empty( $days ) || ! in_array( $today, $days, true ) ) {
        wp_send_json_error( ['error' => 'El check-in solo está permitido en las fechas del evento. Hoy no corresponde.'] );
    }

    // ── Marcar check-in ──────────────────────────────────────────────────────
    $status_arr = get_post_meta( $ticket_post_id, '_eventosapp_checkin_status', true );
    if ( is_string( $status_arr ) ) $status_arr = @unserialize( $status_arr );
    if ( ! is_array( $status_arr ) ) $status_arr = [];

    $already = ( isset( $status_arr[ $today ] ) && $status_arr[ $today ] === 'checked_in' );

    if ( ! $already ) {
        $status_arr[ $today ] = 'checked_in';
        update_post_meta( $ticket_post_id, '_eventosapp_checkin_status', $status_arr );

        // Log de check-in
        $log = get_post_meta( $ticket_post_id, '_eventosapp_checkin_log', true );
        if ( is_string( $log ) ) $log = @unserialize( $log );
        if ( ! is_array( $log ) ) $log = [];

        $user   = wp_get_current_user();
        $log[]  = [
            'fecha'          => $dt->format( 'Y-m-d' ),
            'hora'           => $dt->format( 'H:i:s' ),
            'dia'            => $today,
            'status'         => 'checked_in',
            'usuario'        => $user && $user->exists()
                ? $user->display_name . ' (' . $user->user_email . ')'
                : 'Sistema',
            'qr_type'        => 'face_recognition',
            'qr_type_label'  => 'Reconocimiento Facial',
            'cedula'         => $cedula,
        ];
        update_post_meta( $ticket_post_id, '_eventosapp_checkin_log', $log );

        // Actualizar estadísticas de QR si la función existe
        if ( function_exists( 'eventosapp_update_qr_usage_stats' ) ) {
            eventosapp_update_qr_usage_stats( $event_id, 'face_recognition' );
        }

        eventosapp_face_checkin_debug_log('Check-in facial realizado', [
            'event_id'  => $event_id,
            'ticket_id' => $ticket_post_id,
            'fecha'     => $today,
        ]);
    } else {
        eventosapp_face_checkin_debug_log('Check-in facial ya registrado para hoy', [
            'event_id'  => $event_id,
            'ticket_id' => $ticket_post_id,
            'fecha'     => $today,
        ]);
    }

    // ── Respuesta ────────────────────────────────────────────────────────────
    $first = get_post_meta( $ticket_post_id, '_eventosapp_asistente_nombre',   true );
    $last  = get_post_meta( $ticket_post_id, '_eventosapp_asistente_apellido',  true );
    $comp  = get_post_meta( $ticket_post_id, '_eventosapp_asistente_empresa',   true );
    $role  = get_post_meta( $ticket_post_id, '_eventosapp_asistente_cargo',     true );
    $loc   = get_post_meta( $ticket_post_id, '_eventosapp_asistente_localidad', true );

    wp_send_json_success( [
        'already'            => $already,
        'full_name'          => trim( $first . ' ' . $last ),
        'company'            => $comp,
        'designation'        => $role,
        'localidad'          => $loc,
        'event_name'         => get_the_title( $event_id ),
        'ticket_id'          => $ticket_post_id,
        'cedula'             => $cedula,
        'checkin_date'       => $today,
        'checkin_date_label' => date_i18n( 'D, d M Y', strtotime( $today ) ),
        'qr_type_label'      => 'Reconocimiento Facial',
        'payment_message'    => $mensaje_pago,
    ] );
} );
