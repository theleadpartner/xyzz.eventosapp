<?php
/**
 * EventosApp – QR Check-In con Doble Autenticación
 * Shortcode: [qr_checkin_doble_auth]
 *
 * Flujo:
 * 1. Escanea el QR del ticket.
 * 2. Muestra la información del ticket y solicita el código de 5 dígitos.
 * 3. Valida el código general o el código específico del día, según la configuración.
 * 4. Si es correcto, realiza el check-in usando el estado multidía de EventosApp.
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// HELPERS DEL MÓDULO
// ============================================================

/**
 * Devuelve el contexto de fecha del evento usando su zona horaria.
 * Reutiliza el helper del Check-In QR normal cuando está disponible.
 *
 * @param int $event_id ID del evento.
 * @return array
 */
if ( ! function_exists( 'eventosapp_qr_double_auth_day_context' ) ) {
    function eventosapp_qr_double_auth_day_context( $event_id ) {
        $event_id = absint( $event_id );

        if ( function_exists( 'eventosapp_qr_checkin_validate_event_day' ) ) {
            $validation = eventosapp_qr_checkin_validate_event_day( $event_id );
            $today      = isset( $validation['today'] ) ? sanitize_text_field( $validation['today'] ) : '';

            return [
                'valid' => ! empty( $validation['valid'] ),
                'today' => $today,
                'label' => $today ? date_i18n( 'D, d M Y', strtotime( $today ) ) : '',
                'error' => isset( $validation['error'] ) ? sanitize_text_field( $validation['error'] ) : '',
            ];
        }

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

        $valid = ! empty( $days ) && in_array( $today, $days, true );

        return [
            'valid' => $valid,
            'today' => $today,
            'label' => date_i18n( 'D, d M Y', strtotime( $today ) ),
            'error' => $valid ? '' : 'El check-in solo está permitido en las fechas del evento. Hoy no corresponde.',
        ];
    }
}

/**
 * Valida sesión, feature, evento activo y configuración antes de procesar AJAX.
 * Los hooks nopriv se conservan por compatibilidad, pero el endpoint sigue siendo
 * estrictamente privado para usuarios autorizados.
 *
 * @param int $event_id ID del evento solicitado.
 * @return void
 */
if ( ! function_exists( 'eventosapp_qr_double_auth_ajax_guard' ) ) {
    function eventosapp_qr_double_auth_ajax_guard( $event_id ) {
        $event_id = absint( $event_id );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Debes iniciar sesión para usar este módulo.', 401 );
        }

        if ( function_exists( 'eventosapp_user_can_access_frontend_feature' ) ) {
            if ( ! eventosapp_user_can_access_frontend_feature( 'qr_double_auth', get_current_user_id(), $event_id ) ) {
                wp_send_json_error( 'No tienes permisos para usar el Check-In QR con Doble Autenticación.', 403 );
            }
        } elseif ( function_exists( 'eventosapp_role_can' ) ) {
            if ( ! eventosapp_role_can( 'qr_double_auth' ) ) {
                wp_send_json_error( 'No tienes permisos para usar el Check-In QR con Doble Autenticación.', 403 );
            }
        } elseif ( function_exists( 'eventosapp_current_user_can_checkin' ) && ! eventosapp_current_user_can_checkin() ) {
            wp_send_json_error( 'No tienes permisos para realizar check-in.', 403 );
        }

        if ( ! $event_id ) {
            wp_send_json_error( 'Evento inválido.', 400 );
        }

        $active_event = function_exists( 'eventosapp_get_active_event' )
            ? absint( eventosapp_get_active_event() )
            : 0;

        if ( ! $active_event || $active_event !== $event_id ) {
            wp_send_json_error( 'El evento solicitado no coincide con el evento activo.', 403 );
        }

        $enabled = get_post_meta( $event_id, '_eventosapp_ticket_double_auth_enabled', true );
        if ( $enabled !== '1' ) {
            wp_send_json_error( 'Este evento no tiene activada la Doble Autenticación.', 403 );
        }
    }
}

/**
 * Resuelve un ticket escaneado. Prioriza el helper optimizado del Check-In QR
 * normal, que ya soporta QR Manager, QR preimpreso, caché y consulta SQL directa.
 * Incluye fallback para mantener compatibilidad si ese helper no estuviera cargado.
 *
 * @param string $qr_code Código leído.
 * @param int    $event_id ID del evento.
 * @return array
 */
if ( ! function_exists( 'eventosapp_qr_double_auth_find_ticket' ) ) {
    function eventosapp_qr_double_auth_find_ticket( $qr_code, $event_id ) {
        $qr_code  = sanitize_text_field( (string) $qr_code );
        $event_id = absint( $event_id );

        if ( function_exists( 'eventosapp_qr_find_ticket_by_scanned_code' ) ) {
            $lookup = eventosapp_qr_find_ticket_by_scanned_code( $qr_code, $event_id );

            if ( ! empty( $lookup['found'] ) && ! empty( $lookup['ticket_id'] ) ) {
                return [
                    'found'      => true,
                    'ticket_id'  => absint( $lookup['ticket_id'] ),
                    'type'       => isset( $lookup['type'] ) ? sanitize_key( $lookup['type'] ) : 'legacy',
                    'type_label' => isset( $lookup['type_label'] ) ? sanitize_text_field( $lookup['type_label'] ) : 'QR',
                    'error'      => '',
                ];
            }

            return [
                'found'      => false,
                'ticket_id'  => 0,
                'type'       => 'unknown',
                'type_label' => 'QR',
                'error'      => ! empty( $lookup['error'] ) ? sanitize_text_field( $lookup['error'] ) : 'Ticket no encontrado o no pertenece a este evento.',
            ];
        }

        $ticket_id    = 0;
        $qr_type      = 'legacy';
        $qr_type_label = 'QR Legacy';

        if ( class_exists( 'EventosApp_QR_Manager' ) ) {
            $validation = EventosApp_QR_Manager::validate_qr( $qr_code );

            if ( isset( $validation['valid'] ) && $validation['valid'] === true && ! empty( $validation['ticket_id'] ) ) {
                $candidate_id = absint( $validation['ticket_id'] );
                $ticket_event = absint( get_post_meta( $candidate_id, '_eventosapp_ticket_evento_id', true ) );

                if ( $ticket_event === $event_id ) {
                    $ticket_id     = $candidate_id;
                    $qr_type       = isset( $validation['type'] ) ? sanitize_key( $validation['type'] ) : 'unknown';
                    $qr_type_label = isset( $validation['type_label'] ) ? sanitize_text_field( $validation['type_label'] ) : $qr_type;
                }
            }
        }

        if ( ! $ticket_id ) {
            $use_preprinted = get_post_meta( $event_id, '_eventosapp_ticket_use_preprinted_qr', true ) === '1';
            $meta_key       = $use_preprinted ? 'eventosapp_ticket_preprintedID' : 'eventosapp_ticketID';
            $lookup_value   = $use_preprinted ? preg_replace( '/\D+/', '', $qr_code ) : $qr_code;
            $qr_type        = $use_preprinted ? 'preprinted' : 'legacy';
            $qr_type_label  = $use_preprinted ? 'QR Preimpreso' : 'QR Legacy';

            if ( $lookup_value !== '' ) {
                $tickets = get_posts( [
                    'post_type'      => 'eventosapp_ticket',
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'meta_query'     => [
                        'relation' => 'AND',
                        [
                            'key'   => $meta_key,
                            'value' => $lookup_value,
                        ],
                        [
                            'key'   => '_eventosapp_ticket_evento_id',
                            'value' => $event_id,
                        ],
                    ],
                ] );

                if ( ! empty( $tickets ) ) {
                    $ticket_id = absint( $tickets[0] );
                }
            }
        }

        return [
            'found'      => (bool) $ticket_id,
            'ticket_id'  => $ticket_id,
            'type'       => $qr_type,
            'type_label' => $qr_type_label,
            'error'      => $ticket_id ? '' : 'Ticket no encontrado o no pertenece a este evento.',
        ];
    }
}

// ============================================================
// SHORTCODE: QR Check-In con Doble Autenticación
// ============================================================

add_shortcode( 'qr_checkin_doble_auth', function( $atts ) {
    if ( function_exists( 'eventosapp_require_feature' ) ) {
        eventosapp_require_feature( 'qr_double_auth' );
    }

    if ( ! is_user_logged_in() ) {
        return '<p style="color:#b42318;">Debes iniciar sesión para usar este módulo.</p>';
    }

    $active_event = function_exists( 'eventosapp_get_active_event' ) ? absint( eventosapp_get_active_event() ) : 0;
    if ( ! $active_event ) {
        ob_start();
        if ( function_exists( 'eventosapp_require_active_event' ) ) {
            eventosapp_require_active_event();
        } else {
            echo '<p>Debes seleccionar un evento activo.</p>';
        }
        return ob_get_clean();
    }

    $double_auth_enabled = get_post_meta( $active_event, '_eventosapp_ticket_double_auth_enabled', true ) === '1';
    $auth_mode           = get_post_meta( $active_event, '_eventosapp_ticket_double_auth_mode', true );
    $auth_mode_label     = $auth_mode === 'all_days' ? 'Código por día' : 'Código de acceso';
    $event_name          = get_the_title( $active_event );
    $dashboard_url       = function_exists( 'eventosapp_get_dashboard_url' )
        ? eventosapp_get_dashboard_url()
        : home_url( '/' );
    $day_context         = eventosapp_qr_double_auth_day_context( $active_event );
    $day_is_valid        = ! empty( $day_context['valid'] );
    $instance_id         = function_exists( 'wp_unique_id' )
        ? wp_unique_id( 'evapp-double-auth-' )
        : 'evapp-double-auth-' . $active_event;

    $nonce_search     = wp_create_nonce( 'eventosapp_qr_search' );
    $nonce_verify     = wp_create_nonce( 'eventosapp_verify_checkin' );
    $nonce_payment    = wp_create_nonce( 'eventosapp_qr_checkin' );
    $nonce_companions = wp_create_nonce( 'eventosapp_registrar_acompanantes' );

    $can_use_standard_qr_actions = function_exists( 'eventosapp_role_can' )
        ? (bool) eventosapp_role_can( 'qr' )
        : ( function_exists( 'eventosapp_current_user_can_checkin' ) ? (bool) eventosapp_current_user_can_checkin() : false );
    $can_use_search_actions = function_exists( 'eventosapp_role_can' )
        ? (bool) eventosapp_role_can( 'search' )
        : false;

    $payment_reminder_available = ( has_action( 'wp_ajax_eventosapp_send_payment_reminder' ) && $can_use_standard_qr_actions ) ? '1' : '0';
    $companions_available       = ( has_action( 'wp_ajax_eventosapp_registrar_acompanantes' ) && ( $can_use_standard_qr_actions || $can_use_search_actions ) ) ? '1' : '0';

    ob_start();
    ?>
    <style>
    .evapp-double-auth-app {
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
    .evapp-double-auth-app,
    .evapp-double-auth-app *,
    .evapp-double-auth-app *::before,
    .evapp-double-auth-app *::after { box-sizing:border-box; }
    .evapp-double-auth-app a { text-decoration:none; }
    .evapp-double-auth-app [hidden] { display:none!important; }

    .evapp-double-auth-app .evda-shell {
      width:100%;
      padding:clamp(18px,3vw,36px);
      background:var(--evapp-app-bg);
      border:1px solid var(--evapp-border);
      border-radius:var(--evapp-radius-lg);
      box-shadow:0 18px 50px rgba(31,65,99,.08);
    }
    .evapp-double-auth-app .evda-header {
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:24px;
      margin-bottom:22px;
    }
    .evapp-double-auth-app .evda-heading { min-width:0; }
    .evapp-double-auth-app .evda-eyebrow {
      margin:0 0 7px;
      color:var(--evapp-primary);
      font-size:12px;
      font-weight:800;
      letter-spacing:.15em;
      text-transform:uppercase;
    }
    .evapp-double-auth-app .evda-title {
      margin:0;
      color:var(--evapp-text);
      font-size:clamp(27px,4vw,42px);
      font-weight:800;
      line-height:1.08;
      letter-spacing:-.035em;
    }
    .evapp-double-auth-app .evda-subtitle {
      max-width:760px;
      margin:10px 0 0;
      color:var(--evapp-muted);
      font-size:15px;
      line-height:1.6;
    }
    .evapp-double-auth-app .evda-header-actions { flex:0 0 auto; }

    .evapp-double-auth-app .evda-btn {
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
    .evapp-double-auth-app .evda-btn svg {
      width:18px;
      height:18px;
      flex:0 0 18px;
      fill:none;
      stroke:currentColor;
      stroke-width:2;
      stroke-linecap:round;
      stroke-linejoin:round;
    }
    .evapp-double-auth-app .evda-btn:hover:not(:disabled) { transform:translateY(-1px); }
    .evapp-double-auth-app .evda-btn:focus-visible { outline:3px solid rgba(50,121,189,.22); outline-offset:2px; }
    .evapp-double-auth-app .evda-btn:disabled { opacity:.5; cursor:not-allowed; transform:none; box-shadow:none; }
    .evapp-double-auth-app .evda-btn-secondary {
      background:var(--evapp-surface);
      border-color:var(--evapp-border);
      color:var(--evapp-text);
      box-shadow:0 5px 15px rgba(31,65,99,.05);
      white-space:nowrap;
    }
    .evapp-double-auth-app .evda-btn-secondary:hover:not(:disabled) {
      border-color:#c7d7e8;
      color:var(--evapp-primary-dark);
      box-shadow:0 8px 20px rgba(31,65,99,.09);
    }

    .evapp-double-auth-app .evda-event-context {
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
    .evapp-double-auth-app .evda-event-main {
      min-width:0;
      display:flex;
      align-items:center;
      gap:13px;
    }
    .evapp-double-auth-app .evda-event-icon {
      width:44px;
      height:44px;
      flex:0 0 44px;
      display:grid;
      place-items:center;
      color:var(--evapp-primary);
      background:var(--evapp-primary-soft);
      border-radius:13px;
    }
    .evapp-double-auth-app .evda-event-icon svg {
      width:22px;
      height:22px;
      fill:none;
      stroke:currentColor;
      stroke-width:1.9;
      stroke-linecap:round;
      stroke-linejoin:round;
    }
    .evapp-double-auth-app .evda-event-copy { min-width:0; }
    .evapp-double-auth-app .evda-event-kicker {
      display:block;
      margin-bottom:3px;
      color:var(--evapp-muted);
      font-size:11px;
      font-weight:800;
      letter-spacing:.09em;
      text-transform:uppercase;
    }
    .evapp-double-auth-app .evda-event-name {
      display:block;
      overflow:hidden;
      color:var(--evapp-text);
      font-size:15px;
      font-weight:800;
      line-height:1.3;
      text-overflow:ellipsis;
      white-space:nowrap;
    }
    .evapp-double-auth-app .evda-event-meta {
      display:flex;
      align-items:center;
      justify-content:flex-end;
      flex-wrap:wrap;
      gap:8px;
    }
    .evapp-double-auth-app .evda-chip {
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
    .evapp-double-auth-app .evda-chip::before {
      width:7px;
      height:7px;
      border-radius:50%;
      background:#94a3b8;
      content:"";
    }
    .evapp-double-auth-app .evda-chip.is-success {
      color:var(--evapp-success);
      border-color:#cfeadf;
      background:var(--evapp-success-soft);
    }
    .evapp-double-auth-app .evda-chip.is-success::before { background:var(--evapp-success); }
    .evapp-double-auth-app .evda-chip.is-warning {
      color:var(--evapp-warning);
      border-color:#f1dfad;
      background:var(--evapp-warning-soft);
    }
    .evapp-double-auth-app .evda-chip.is-warning::before { background:#d69e2e; }
    .evapp-double-auth-app .evda-chip.is-danger {
      color:var(--evapp-danger);
      border-color:#efc7c7;
      background:var(--evapp-danger-soft);
    }
    .evapp-double-auth-app .evda-chip.is-danger::before { background:var(--evapp-danger); }

    .evapp-double-auth-app .evda-notice {
      display:flex;
      align-items:flex-start;
      gap:11px;
      margin:0 0 20px;
      padding:14px 15px;
      border:1px solid var(--evapp-border);
      border-left:4px solid var(--evapp-primary);
      border-radius:14px;
      background:var(--evapp-surface);
      color:var(--evapp-text);
      font-size:13px;
      line-height:1.55;
    }
    .evapp-double-auth-app .evda-notice strong { display:block; margin-bottom:2px; }
    .evapp-double-auth-app .evda-notice.is-danger { border-left-color:var(--evapp-danger); background:var(--evapp-danger-soft); }
    .evapp-double-auth-app .evda-notice.is-warning { border-left-color:#d69e2e; background:var(--evapp-warning-soft); }

    .evapp-double-auth-app .evda-layout {
      display:grid;
      grid-template-columns:minmax(0,1.18fr) minmax(320px,.82fr);
      gap:20px;
      align-items:start;
    }
    .evapp-double-auth-app .evda-panel {
      min-width:0;
      background:var(--evapp-surface);
      border:1px solid var(--evapp-border);
      border-radius:var(--evapp-radius);
      box-shadow:0 8px 26px rgba(31,65,99,.05);
    }
    .evapp-double-auth-app .evda-scanner-panel { padding:18px; }
    .evapp-double-auth-app .evda-result-panel { position:sticky; top:18px; padding:18px; }
    body.admin-bar .evapp-double-auth-app .evda-result-panel { top:50px; }
    .evapp-double-auth-app .evda-panel-head {
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:14px;
      margin-bottom:15px;
    }
    .evapp-double-auth-app .evda-panel-title {
      margin:0;
      color:var(--evapp-text);
      font-size:17px;
      font-weight:800;
      line-height:1.3;
    }
    .evapp-double-auth-app .evda-panel-desc {
      margin:5px 0 0;
      color:var(--evapp-muted);
      font-size:13px;
      line-height:1.5;
    }
    .evapp-double-auth-app .evda-camera-state {
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
    .evapp-double-auth-app .evda-camera-state::before {
      width:7px;
      height:7px;
      border-radius:50%;
      background:#94a3b8;
      content:"";
    }
    .evapp-double-auth-app .evda-camera-state.is-live {
      color:var(--evapp-success);
      background:var(--evapp-success-soft);
      border-color:#cfeadf;
    }
    .evapp-double-auth-app .evda-camera-state.is-live::before {
      background:var(--evapp-success);
      box-shadow:0 0 0 4px rgba(22,133,91,.10);
    }
    .evapp-double-auth-app .evda-camera-state.is-busy {
      color:var(--evapp-primary-dark);
      background:var(--evapp-primary-soft);
      border-color:#cfe3f6;
    }
    .evapp-double-auth-app .evda-camera-state.is-busy::before {
      background:var(--evapp-primary);
      animation:evdaPulse 1s ease-in-out infinite;
    }

    .evapp-double-auth-app .evapp-qr-btn,
    .evapp-double-auth-app .evapp-qr-btn-secondary,
    .evapp-double-auth-app .evda-action-btn {
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
    .evapp-double-auth-app .evapp-qr-btn:hover:not(:disabled),
    .evapp-double-auth-app .evapp-qr-btn-secondary:hover:not(:disabled),
    .evapp-double-auth-app .evda-action-btn:hover:not(:disabled) {
      transform:translateY(-1px);
      background:var(--evapp-primary-dark);
      box-shadow:0 12px 24px rgba(50,121,189,.24);
    }
    .evapp-double-auth-app .evapp-qr-btn:focus-visible,
    .evapp-double-auth-app .evapp-qr-btn-secondary:focus-visible,
    .evapp-double-auth-app .evda-action-btn:focus-visible {
      outline:3px solid rgba(50,121,189,.22);
      outline-offset:2px;
    }
    .evapp-double-auth-app .evapp-qr-btn:disabled,
    .evapp-double-auth-app .evapp-qr-btn-secondary:disabled,
    .evapp-double-auth-app .evda-action-btn:disabled {
      opacity:.5;
      cursor:not-allowed;
      transform:none;
      box-shadow:none;
    }
    .evapp-double-auth-app .evapp-qr-btn.is-live {
      background:var(--evapp-danger);
      box-shadow:0 9px 20px rgba(197,58,58,.17);
    }
    .evapp-double-auth-app .evapp-qr-btn svg,
    .evapp-double-auth-app .evapp-qr-btn-secondary svg,
    .evapp-double-auth-app .evda-action-btn svg {
      width:19px;
      height:19px;
      flex:0 0 19px;
      fill:none;
      stroke:currentColor;
      stroke-width:2;
      stroke-linecap:round;
      stroke-linejoin:round;
    }
    .evapp-double-auth-app .evapp-qr-btn-secondary {
      margin-top:14px;
      background:#fff;
      border-color:var(--evapp-border);
      color:var(--evapp-primary-dark);
      box-shadow:none;
    }
    .evapp-double-auth-app .evapp-qr-btn-secondary:hover:not(:disabled) {
      background:var(--evapp-primary-soft);
      border-color:#c4dbee;
      box-shadow:none;
    }

    .evapp-double-auth-app .evapp-qr-video-wrap {
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
    .evapp-double-auth-app .evapp-qr-video {
      width:100%;
      height:100%;
      display:none;
      object-fit:cover;
      background:#0f172a;
    }
    .evapp-double-auth-app .evda-camera-placeholder {
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
    .evapp-double-auth-app .evda-camera-placeholder svg {
      width:42px;
      height:42px;
      fill:none;
      stroke:#6f8ca8;
      stroke-width:1.6;
      stroke-linecap:round;
      stroke-linejoin:round;
    }
    .evapp-double-auth-app .evda-camera-placeholder strong { color:#f8fafc; font-size:14px; }
    .evapp-double-auth-app .evda-camera-placeholder span {
      max-width:300px;
      color:#94a3b8;
      font-size:12px;
      line-height:1.5;
    }
    .evapp-double-auth-app .evapp-qr-video-wrap.has-camera .evda-camera-placeholder { display:none; }
    .evapp-double-auth-app .evapp-qr-frame {
      position:absolute;
      inset:0;
      display:none;
      pointer-events:none;
    }
    .evapp-double-auth-app .evapp-qr-frame .mask {
      position:absolute;
      inset:0;
      background:radial-gradient(ellipse 54% 54% at 50% 50%,rgba(0,0,0,0) 58%,rgba(7,14,24,.48) 61%);
    }
    .evapp-double-auth-app .evapp-qr-corner {
      position:absolute;
      width:56px;
      height:56px;
      border:4px solid #6fb8f4;
      border-radius:11px;
      filter:drop-shadow(0 2px 5px rgba(0,0,0,.26));
    }
    .evapp-double-auth-app .evapp-qr-corner.tl { top:20px;left:20px;border-right:0;border-bottom:0; }
    .evapp-double-auth-app .evapp-qr-corner.tr { top:20px;right:20px;border-left:0;border-bottom:0; }
    .evapp-double-auth-app .evapp-qr-corner.bl { bottom:20px;left:20px;border-right:0;border-top:0; }
    .evapp-double-auth-app .evapp-qr-corner.br { bottom:20px;right:20px;border-left:0;border-top:0; }
    .evapp-double-auth-app .evapp-qr-video-wrap.is-immersive {
      height:min(calc(100vh - var(--evapp-offset,72px)),760px);
      height:min(calc(100dvh - var(--evapp-offset,72px)),760px);
      min-height:420px;
      aspect-ratio:auto;
    }

    .evapp-double-auth-app .evda-scan-guide {
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:8px;
      margin-top:12px;
    }
    .evapp-double-auth-app .evda-guide-item {
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
    .evapp-double-auth-app .evda-guide-item svg {
      width:15px;
      height:15px;
      flex:0 0 15px;
      fill:none;
      stroke:var(--evapp-primary);
      stroke-width:1.8;
      stroke-linecap:round;
      stroke-linejoin:round;
    }

    .evapp-double-auth-app .evapp-qr-result {
      min-height:190px;
      margin:0;
      padding:0;
      background:transparent;
      border:0;
      color:var(--evapp-text);
    }
    .evapp-double-auth-app .evapp-qr-help {
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
    .evapp-double-auth-app .evapp-qr-help::before {
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

    .evapp-double-auth-app .evda-state {
      display:flex;
      align-items:flex-start;
      gap:12px;
      padding:15px;
      border:1px solid var(--evapp-border);
      border-radius:14px;
      background:#fff;
    }
    .evapp-double-auth-app .evda-state + .evapp-qr-grid,
    .evapp-double-auth-app .evapp-qr-grid + .evda-auth-form,
    .evapp-double-auth-app .evda-state + .evda-action-btn,
    .evapp-double-auth-app .evapp-qr-grid + .evda-action-btn { margin-top:14px; }
    .evapp-double-auth-app .evda-state-icon {
      width:38px;
      height:38px;
      flex:0 0 38px;
      display:grid;
      place-items:center;
      border-radius:12px;
      font-size:18px;
      font-weight:900;
    }
    .evapp-double-auth-app .evda-state-copy { min-width:0; }
    .evapp-double-auth-app .evda-state-title {
      margin:0;
      color:var(--evapp-text);
      font-size:14px;
      font-weight:850;
    }
    .evapp-double-auth-app .evda-state-text {
      margin:4px 0 0;
      color:var(--evapp-muted);
      font-size:12px;
      line-height:1.5;
    }
    .evapp-double-auth-app .evda-state.is-success { border-color:#cfeadf; background:var(--evapp-success-soft); }
    .evapp-double-auth-app .evda-state.is-success .evda-state-icon { color:var(--evapp-success); background:#d9f6e8; }
    .evapp-double-auth-app .evda-state.is-warning { border-color:#f1dfad; background:var(--evapp-warning-soft); }
    .evapp-double-auth-app .evda-state.is-warning .evda-state-icon { color:var(--evapp-warning); background:#ffefbf; }
    .evapp-double-auth-app .evda-state.is-danger { border-color:#efc7c7; background:var(--evapp-danger-soft); }
    .evapp-double-auth-app .evda-state.is-danger .evda-state-icon { color:var(--evapp-danger); background:#fbdada; }
    .evapp-double-auth-app .evda-state.is-info { border-color:#cfe3f6; background:var(--evapp-primary-soft); }
    .evapp-double-auth-app .evda-state.is-info .evda-state-icon { color:var(--evapp-primary); background:#dbeeff; }

    .evapp-double-auth-app .evapp-qr-grid {
      display:grid;
      grid-template-columns:minmax(110px,.44fr) minmax(0,1fr);
      gap:0;
      overflow:hidden;
      border:1px solid var(--evapp-border);
      border-radius:14px;
      background:#fff;
    }
    .evapp-double-auth-app .evapp-qr-grid .evda-label,
    .evapp-double-auth-app .evapp-qr-grid .evda-value {
      min-width:0;
      padding:10px 12px;
      border-bottom:1px solid var(--evapp-border);
      font-size:12px;
      line-height:1.45;
      overflow-wrap:anywhere;
    }
    .evapp-double-auth-app .evapp-qr-grid .evda-label {
      background:#f8fafc;
      color:var(--evapp-muted);
      font-weight:800;
    }
    .evapp-double-auth-app .evapp-qr-grid .evda-value {
      color:var(--evapp-text);
      font-weight:650;
    }
    .evapp-double-auth-app .evapp-qr-grid > :nth-last-child(-n+2) { border-bottom:0; }
    .evapp-double-auth-app .evda-qr-type {
      display:inline-flex;
      align-items:center;
      min-height:24px;
      padding:4px 8px;
      border-radius:999px;
      background:var(--evapp-primary-soft);
      color:var(--evapp-primary-dark);
      font-size:11px;
      font-weight:800;
    }

    .evapp-double-auth-app .evda-auth-form {
      margin-top:14px;
      padding:16px;
      border:1px solid #cfe3f6;
      border-radius:14px;
      background:#f8fbfe;
    }
    .evapp-double-auth-app .evda-auth-label {
      display:block;
      margin:0 0 7px;
      color:var(--evapp-text);
      font-size:13px;
      font-weight:850;
    }
    .evapp-double-auth-app .evda-auth-help {
      margin:0 0 12px;
      color:var(--evapp-muted);
      font-size:12px;
      line-height:1.5;
    }
    .evapp-double-auth-app .evda-auth-input {
      width:100%;
      min-height:58px;
      margin:0;
      padding:10px 12px;
      border:1px solid #b9cfe3;
      border-radius:12px;
      background:#fff;
      color:var(--evapp-text);
      font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;
      font-size:26px;
      font-weight:850;
      letter-spacing:.32em;
      text-align:center;
      outline:none;
      transition:border-color .16s ease,box-shadow .16s ease;
    }
    .evapp-double-auth-app .evda-auth-input:focus {
      border-color:var(--evapp-primary);
      box-shadow:0 0 0 4px rgba(50,121,189,.14);
    }
    .evapp-double-auth-app .evda-auth-error {
      display:none;
      margin:9px 0 0;
      color:var(--evapp-danger);
      font-size:12px;
      font-weight:700;
      line-height:1.45;
    }
    .evapp-double-auth-app .evda-auth-error.is-visible { display:block; }
    .evapp-double-auth-app .evda-auth-buttons {
      display:grid;
      grid-template-columns:minmax(0,1fr) auto;
      gap:10px;
      margin-top:12px;
    }
    .evapp-double-auth-app .evda-verify-btn {
      background:var(--evapp-success);
      box-shadow:0 9px 20px rgba(22,133,91,.18);
    }
    .evapp-double-auth-app .evda-verify-btn:hover:not(:disabled) {
      background:#116c4a;
      box-shadow:0 12px 24px rgba(22,133,91,.22);
    }
    .evapp-double-auth-app .evda-cancel-btn {
      width:auto;
      background:#fff;
      border-color:var(--evapp-border);
      color:var(--evapp-text);
      box-shadow:none;
    }
    .evapp-double-auth-app .evda-cancel-btn:hover:not(:disabled) {
      background:#f8fafc;
      border-color:#c7d7e8;
      color:var(--evapp-primary-dark);
      box-shadow:none;
    }

    .evapp-double-auth-app .evda-payment-note,
    .evapp-double-auth-app .evda-inline-status {
      margin-top:12px;
      padding:11px 12px;
      border:1px solid #cfe3f6;
      border-radius:11px;
      background:var(--evapp-primary-soft);
      color:var(--evapp-primary-dark);
      font-size:12px;
      font-weight:700;
      line-height:1.5;
    }
    .evapp-double-auth-app .evda-inline-status.is-success { color:var(--evapp-success); background:var(--evapp-success-soft); border-color:#cfeadf; }
    .evapp-double-auth-app .evda-inline-status.is-danger { color:var(--evapp-danger); background:var(--evapp-danger-soft); border-color:#efc7c7; }

    .evapp-double-auth-app .evda-companion-panel {
      margin-top:14px;
      padding:14px;
      border:1px solid var(--evapp-border);
      border-radius:13px;
      background:#fbfdff;
    }
    .evapp-double-auth-app .evda-companion-label {
      margin-bottom:9px;
      color:var(--evapp-text);
      font-size:12px;
      font-weight:850;
    }
    .evapp-double-auth-app .evda-companion-row {
      display:grid;
      grid-template-columns:96px minmax(0,1fr);
      gap:9px;
    }
    .evapp-double-auth-app .evda-companion-input {
      width:100%;
      min-height:44px;
      padding:8px 10px;
      border:1px solid var(--evapp-border);
      border-radius:11px;
      background:#fff;
      color:var(--evapp-text);
      font:inherit;
      font-size:14px;
      font-weight:750;
      outline:none;
    }
    .evapp-double-auth-app .evda-companion-input:focus {
      border-color:var(--evapp-primary);
      box-shadow:0 0 0 3px rgba(50,121,189,.12);
    }
    .evapp-double-auth-app .evda-companion-status {
      min-height:18px;
      margin-top:8px;
      color:var(--evapp-muted);
      font-size:12px;
      line-height:1.45;
    }

    @keyframes evdaPulse { 0%,100%{opacity:.45} 50%{opacity:1} }

    @media (max-width:900px) {
      .evapp-double-auth-app .evda-layout { grid-template-columns:1fr; }
      .evapp-double-auth-app .evda-result-panel { position:static; top:auto; }
      .evapp-double-auth-app .evapp-qr-video-wrap { aspect-ratio:16/11; }
    }
    @media (max-width:680px) {
      .evapp-double-auth-app .evda-shell { padding:16px; border-radius:20px; }
      .evapp-double-auth-app .evda-header { flex-direction:column; gap:15px; }
      .evapp-double-auth-app .evda-header-actions,
      .evapp-double-auth-app .evda-header-actions .evda-btn { width:100%; }
      .evapp-double-auth-app .evda-event-context { align-items:flex-start; flex-direction:column; }
      .evapp-double-auth-app .evda-event-meta { width:100%; justify-content:flex-start; }
      .evapp-double-auth-app .evda-panel-head { flex-direction:column; }
      .evapp-double-auth-app .evda-camera-state { align-self:flex-start; }
      .evapp-double-auth-app .evda-scanner-panel,
      .evapp-double-auth-app .evda-result-panel { padding:14px; }
      .evapp-double-auth-app .evapp-qr-video-wrap { min-height:360px; aspect-ratio:3/4; border-radius:14px; }
      .evapp-double-auth-app .evapp-qr-video-wrap.is-immersive {
        height:calc(100vh - var(--evapp-offset,62px));
        height:calc(100dvh - var(--evapp-offset,62px));
        min-height:420px;
        max-height:none;
      }
      .evapp-double-auth-app .evda-scan-guide { grid-template-columns:1fr; }
      .evapp-double-auth-app .evapp-qr-grid { grid-template-columns:1fr; }
      .evapp-double-auth-app .evapp-qr-grid .evda-label { padding-bottom:4px; border-bottom:0; background:#f8fafc; }
      .evapp-double-auth-app .evapp-qr-grid .evda-value { padding-top:4px; }
      .evapp-double-auth-app .evapp-qr-grid > :nth-last-child(-n+2) { border-bottom:0; }
      .evapp-double-auth-app .evda-auth-buttons { grid-template-columns:1fr; }
      .evapp-double-auth-app .evda-cancel-btn { width:100%; }
      .evapp-double-auth-app .evda-companion-row { grid-template-columns:1fr; }
    }
    @media (max-width:430px) {
      .evapp-double-auth-app .evda-title { font-size:28px; }
      .evapp-double-auth-app .evda-event-main { align-items:flex-start; }
      .evapp-double-auth-app .evda-event-icon { width:40px; height:40px; flex-basis:40px; }
      .evapp-double-auth-app .evda-auth-input { font-size:23px; letter-spacing:.25em; }
    }
    @media (prefers-reduced-motion:reduce) {
      .evapp-double-auth-app * { scroll-behavior:auto!important; transition:none!important; animation:none!important; }
    }
    </style>

    <div
      class="evapp-double-auth-app"
      id="<?php echo esc_attr( $instance_id ); ?>"
      data-event="<?php echo esc_attr( $active_event ); ?>"
      data-enabled="<?php echo $double_auth_enabled ? '1' : '0'; ?>"
      data-day-valid="<?php echo $day_is_valid ? '1' : '0'; ?>"
      data-payment-reminder="<?php echo esc_attr( $payment_reminder_available ); ?>"
      data-companions="<?php echo esc_attr( $companions_available ); ?>"
    >
      <div class="evda-shell">
        <header class="evda-header">
          <div class="evda-heading">
            <p class="evda-eyebrow">EVENTOSAPP</p>
            <h1 class="evda-title">Check-In QR Doble Autenticación</h1>
            <p class="evda-subtitle">Escanea el QR del asistente, valida su código de seguridad y confirma el ingreso únicamente cuando ambas verificaciones sean correctas.</p>
          </div>
          <div class="evda-header-actions">
            <a href="<?php echo esc_url( $dashboard_url ); ?>" class="evda-btn evda-btn-secondary" aria-label="Volver al dashboard">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg>
              <span>Volver al dashboard</span>
            </a>
          </div>
        </header>

        <section class="evda-event-context" aria-label="Evento activo">
          <div class="evda-event-main">
            <div class="evda-event-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M12 2 4 6v6c0 5.5 3.8 10.7 8 12 4.2-1.3 8-6.5 8-12V6l-8-4Z"></path><path d="m9 12 2 2 4-4"></path></svg>
            </div>
            <div class="evda-event-copy">
              <span class="evda-event-kicker">Evento activo</span>
              <span class="evda-event-name"><?php echo esc_html( $event_name ?: 'Evento sin nombre' ); ?></span>
            </div>
          </div>
          <div class="evda-event-meta">
            <span class="evda-chip <?php echo $double_auth_enabled ? 'is-success' : 'is-danger'; ?>">
              <?php echo $double_auth_enabled ? 'Doble autenticación activa' : 'Doble autenticación inactiva'; ?>
            </span>
            <span class="evda-chip <?php echo $day_is_valid ? 'is-success' : 'is-warning'; ?>">
              <?php echo esc_html( $day_context['label'] ?: 'Fecha del evento' ); ?>
            </span>
            <span class="evda-chip is-success"><?php echo esc_html( $auth_mode_label ); ?></span>
          </div>
        </section>

        <?php if ( ! $double_auth_enabled ) : ?>
          <div class="evda-notice is-danger" role="alert">
            <div>
              <strong>Doble Autenticación desactivada</strong>
              Activa esta función en la configuración del evento antes de utilizar este módulo. El lector permanecerá bloqueado para evitar un check-in sin la validación requerida.
            </div>
          </div>
        <?php elseif ( ! $day_is_valid ) : ?>
          <div class="evda-notice is-warning" role="alert">
            <div>
              <strong>Hoy no es una fecha habilitada para check-in</strong>
              <?php echo esc_html( $day_context['error'] ?: 'El lector se habilitará únicamente durante una fecha válida del evento.' ); ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="evda-layout">
          <section class="evda-panel evda-scanner-panel" aria-label="Lector de código QR">
            <div class="evda-panel-head">
              <div>
                <h2 class="evda-panel-title">Lector QR</h2>
                <p class="evda-panel-desc">Usa preferiblemente la cámara trasera, mantén el código centrado y evita reflejos.</p>
              </div>
              <div class="evda-camera-state" data-role="camera-state" aria-live="polite">Cámara inactiva</div>
            </div>

            <button class="evapp-qr-btn" data-role="start-scan" type="button" <?php disabled( ! $double_auth_enabled || ! $day_is_valid ); ?>>
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4"></path><rect x="7" y="7" width="10" height="10" rx="2"></rect></svg>
              <span>Activar cámara y escanear</span>
            </button>

            <div class="evapp-qr-video-wrap" data-role="video-wrap">
              <div class="evda-camera-placeholder" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M3 7h4l2-2h6l2 2h4v12H3z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                <strong>La cámara está apagada</strong>
                <span>Al activarla, el navegador puede solicitar permiso para utilizar la cámara.</span>
              </div>
              <video class="evapp-qr-video" data-role="video" playsinline muted></video>
              <div class="evapp-qr-frame" data-role="frame" aria-hidden="true">
                <div class="mask"></div>
                <div class="evapp-qr-corner tl"></div>
                <div class="evapp-qr-corner tr"></div>
                <div class="evapp-qr-corner bl"></div>
                <div class="evapp-qr-corner br"></div>
              </div>
              <canvas data-role="canvas" style="display:none;"></canvas>
            </div>

            <div class="evda-scan-guide" aria-label="Consejos de lectura">
              <div class="evda-guide-item"><svg viewBox="0 0 24 24"><path d="M12 3v18M3 12h18"></path></svg><span>Centra el QR</span></div>
              <div class="evda-guide-item"><svg viewBox="0 0 24 24"><path d="M4 12h16M7 8h10M9 16h6"></path></svg><span>Evita movimiento</span></div>
              <div class="evda-guide-item"><svg viewBox="0 0 24 24"><path d="M12 3v2M12 19v2M3 12h2M19 12h2"></path><circle cx="12" cy="12" r="4"></circle></svg><span>Buena iluminación</span></div>
            </div>
          </section>

          <section class="evda-panel evda-result-panel" aria-label="Resultado de validación">
            <div class="evda-panel-head">
              <div>
                <h2 class="evda-panel-title">Validación de acceso</h2>
                <p class="evda-panel-desc">Aquí verás el ticket, la verificación del código y el resultado final del check-in.</p>
              </div>
            </div>
            <div class="evapp-qr-result" data-role="result" aria-live="polite">
              <div class="evapp-qr-help">Activa la cámara y escanea el QR. Después solicita al asistente su código de 5 dígitos.</div>
            </div>
          </section>
        </div>
      </div>
    </div>

    <script>
    (function(){
      const root = document.getElementById(<?php echo wp_json_encode( $instance_id ); ?>);
      if (!root) return;

      const ajaxURL         = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
      const nonceSearch     = <?php echo wp_json_encode( $nonce_search ); ?>;
      const nonceVerify     = <?php echo wp_json_encode( $nonce_verify ); ?>;
      const noncePayment    = <?php echo wp_json_encode( $nonce_payment ); ?>;
      const nonceCompanions = <?php echo wp_json_encode( $nonce_companions ); ?>;
      const eventID         = parseInt(root.dataset.event || '0', 10) || 0;
      const moduleEnabled   = root.dataset.enabled === '1';
      const dayIsValid      = root.dataset.dayValid === '1';
      const paymentReminderAvailable = root.dataset.paymentReminder === '1';
      const companionsEndpointAvailable = root.dataset.companions === '1';

      const btn         = root.querySelector('[data-role="start-scan"]');
      const video       = root.querySelector('[data-role="video"]');
      const frame       = root.querySelector('[data-role="frame"]');
      const cvs         = root.querySelector('[data-role="canvas"]');
      const out         = root.querySelector('[data-role="result"]');
      const vwrap       = root.querySelector('[data-role="video-wrap"]');
      const cameraState = root.querySelector('[data-role="camera-state"]');
      const ctx         = cvs ? cvs.getContext('2d', { willReadFrequently:true }) : null;

      if (!btn || !video || !frame || !cvs || !ctx || !out || !vwrap || !cameraState) return;

      const MAX_SCAN_WIDTH = 960;
      const DETECTION_INTERVAL = 90;
      const JSQR_SRC = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';

      let stream = null;
      let running = false;
      let rafId = 0;
      let lastDetectionAt = 0;
      let lastScan = '';
      let lastAt = 0;
      let jsQrPromise = null;
      let currentTicket = null;
      let barcodeDetector = null;

      try {
        barcodeDetector = ('BarcodeDetector' in window)
          ? new window.BarcodeDetector({ formats:['qr_code'] })
          : null;
      } catch (e) {
        barcodeDetector = null;
      }

      function escapeHtml(value){
        return String(value == null ? '' : value)
          .replace(/&/g,'&amp;')
          .replace(/</g,'&lt;')
          .replace(/>/g,'&gt;')
          .replace(/"/g,'&quot;')
          .replace(/'/g,'&#039;');
      }

      function safeValue(value, fallback='-'){
        const str = String(value == null ? '' : value).trim();
        return str ? escapeHtml(str) : escapeHtml(fallback);
      }

      function row(label, value, allowHtml=false){
        const rendered = allowHtml ? String(value || '-') : safeValue(value);
        return '<div class="evda-label">'+escapeHtml(label)+'</div><div class="evda-value">'+rendered+'</div>';
      }

      function renderState(type, title, text){
        const icons = { success:'✓', warning:'!', danger:'×', info:'i' };
        const safeType = ['success','warning','danger','info'].includes(type) ? type : 'info';
        return '<div class="evda-state is-'+safeType+'">'
          + '<div class="evda-state-icon" aria-hidden="true">'+icons[safeType]+'</div>'
          + '<div class="evda-state-copy"><p class="evda-state-title">'+safeValue(title,'')+'</p>'
          + '<p class="evda-state-text">'+safeValue(text,'')+'</p></div></div>';
      }

      function responseMessage(resp, fallback){
        if (!resp || typeof resp.data === 'undefined' || resp.data === null) return fallback;
        if (typeof resp.data === 'string') return resp.data || fallback;
        if (typeof resp.data.message === 'string') return resp.data.message || fallback;
        if (typeof resp.data.error === 'string') return resp.data.error || fallback;
        return fallback;
      }

      function setOutput(html){ out.innerHTML = html; }

      function setCameraState(label, state=''){
        cameraState.textContent = label;
        cameraState.classList.remove('is-live','is-busy');
        if (state === 'live') cameraState.classList.add('is-live');
        if (state === 'busy') cameraState.classList.add('is-busy');
      }

      function getOffsetCompensation(){
        const adminBar = document.getElementById('wpadminbar');
        const adminH = adminBar ? adminBar.offsetHeight : 0;
        return adminH + 10;
      }

      function smoothScrollTo(el){
        if (!el) return;
        const offset = getOffsetCompensation();
        try { el.style.setProperty('--evapp-offset', offset + 'px'); } catch(e) {}
        const y = el.getBoundingClientRect().top + window.pageYOffset - offset;
        window.scrollTo({ top:y, behavior:'smooth' });
      }

      function setLiveUI(on){
        if (on) {
          btn.classList.add('is-live');
          btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6h12v12H6z"></path></svg><span>Detener cámara</span>';
          setCameraState('Cámara activa','live');
        } else {
          btn.classList.remove('is-live');
          btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4"></path><rect x="7" y="7" width="10" height="10" rx="2"></rect></svg><span>Activar cámara y escanear</span>';
          setCameraState('Cámara inactiva');
        }
      }

      function beep(){
        try {
          const a = new Audio();
          a.src = 'data:audio/mp3;base64,//uQxAAAAAAAAAAAAAAAAAAAAAAAWGlinZwAAAA8AAAACAAACcQAA';
          a.play().catch(()=>{});
        } catch(e) {}
        if (navigator.vibrate) navigator.vibrate(60);
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
        try { video.pause(); } catch(e) {}
        video.srcObject = null;
        video.style.display = 'none';
        frame.style.display = 'none';
        vwrap.classList.remove('is-immersive','has-camera');
        setLiveUI(false);
      }

      async function ensureJsQR(){
        if (barcodeDetector || window.jsQR) return true;
        if (jsQrPromise) return jsQrPromise;

        jsQrPromise = new Promise((resolve,reject)=>{
          const existing = document.querySelector('script[data-evapp-jsqr="1"]');
          if (existing) {
            if (window.jsQR) {
              resolve(true);
              return;
            }
            existing.addEventListener('load',()=>resolve(true),{once:true});
            existing.addEventListener('error',()=>reject(new Error('No fue posible cargar el lector QR alterno.')),{once:true});
            return;
          }

          const s = document.createElement('script');
          s.src = JSQR_SRC;
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
          return 'El navegador no tiene permiso para usar la cámara. Habilita el permiso para este sitio y vuelve a intentar.';
        }
        if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
          return 'No se encontró una cámara disponible en este dispositivo.';
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
          return 'Este navegador no permite acceder a la cámara desde esta página. Verifica HTTPS y que el navegador esté actualizado.';
        }
        return 'No se pudo acceder a la cámara. Revisa los permisos del navegador y vuelve a intentar.';
      }

      async function start(){
        if (!moduleEnabled) {
          setOutput(renderState('danger','Doble Autenticación desactivada','Activa la función en la configuración del evento antes de usar el lector.'));
          return false;
        }
        if (!dayIsValid) {
          setOutput(renderState('warning','Fecha no habilitada','El check-in solo puede realizarse durante una fecha válida del evento.'));
          return false;
        }
        if (!eventID) {
          setOutput(renderState('danger','No hay evento activo','Regresa al dashboard y selecciona el evento que vas a gestionar.'));
          return false;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
          setOutput(renderState('danger','Cámara no disponible','El navegador no ofrece acceso a la cámara en este contexto. Verifica HTTPS y permisos.'));
          return false;
        }

        try {
          stream = await navigator.mediaDevices.getUserMedia({
            video:{ facingMode:{ideal:'environment'}, width:{ideal:1280}, height:{ideal:720} },
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
          ctx.drawImage(video,0,0,cvs.width,cvs.height);

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
            const code = window.jsQR(img.data,img.width,img.height);
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
        const old = out.querySelector('[data-role="scan-again"]');
        if (old) old.remove();

        const againBtn = document.createElement('button');
        againBtn.type = 'button';
        againBtn.className = 'evapp-qr-btn-secondary';
        againBtn.dataset.role = 'scan-again';
        againBtn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.34 5.66M20 5v6h-6"></path></svg><span>Escanear otro QR</span>';
        out.appendChild(againBtn);

        againBtn.addEventListener('click',async()=>{
          againBtn.disabled = true;
          currentTicket = null;
          try {
            await ensureJsQR();
            const started = await start();
            if (started) {
              setOutput('<div class="evapp-qr-help">Cámara activa. Centra el QR dentro del marco; la lectura se detendrá automáticamente al detectar un código.</div>');
            }
          } catch(e) {
            setOutput(renderState('danger','No se pudo preparar el lector',e && e.message ? e.message : 'Vuelve a intentar.'));
            injectScanAgainButton();
          }
        });
      }

      function appendPaymentReminder(ticketId){
        if (!paymentReminderAvailable || !ticketId || ticketId <= 0) return;

        const reminder = document.createElement('button');
        reminder.type = 'button';
        reminder.className = 'evda-action-btn';
        reminder.dataset.role = 'payment-reminder';
        reminder.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8"></path><rect x="3" y="5" width="18" height="14" rx="2"></rect></svg><span>Enviar enlace de pago por correo</span>';
        out.appendChild(reminder);

        const status = document.createElement('div');
        status.className = 'evda-inline-status';
        status.hidden = true;
        out.appendChild(status);

        reminder.addEventListener('click',()=>sendPaymentReminder(ticketId, reminder, status));
      }

      function sendPaymentReminder(ticketId, reminderBtn, statusDiv){
        if (!ticketId || ticketId <= 0) return;
        reminderBtn.disabled = true;
        reminderBtn.querySelector('span').textContent = 'Enviando correo…';
        statusDiv.hidden = false;
        statusDiv.className = 'evda-inline-status';
        statusDiv.textContent = 'Enviando recordatorio de pago…';

        const fd = new FormData();
        fd.append('action','eventosapp_send_payment_reminder');
        fd.append('security',noncePayment);
        fd.append('ticket_id',String(ticketId));

        fetch(ajaxURL,{method:'POST',body:fd,credentials:'same-origin'})
          .then(r=>r.json())
          .then(resp=>{
            if (resp && resp.success) {
              statusDiv.className = 'evda-inline-status is-success';
              const email = resp.data && resp.data.email ? resp.data.email : '';
              statusDiv.textContent = email ? 'Correo enviado correctamente a '+email+'.' : 'Correo enviado correctamente.';
              reminderBtn.querySelector('span').textContent = 'Correo enviado';
              return;
            }
            statusDiv.className = 'evda-inline-status is-danger';
            statusDiv.textContent = responseMessage(resp,'Error al enviar el correo.');
            reminderBtn.disabled = false;
            reminderBtn.querySelector('span').textContent = 'Reintentar envío';
          })
          .catch(()=>{
            statusDiv.className = 'evda-inline-status is-danger';
            statusDiv.textContent = 'Error de conexión al enviar el correo.';
            reminderBtn.disabled = false;
            reminderBtn.querySelector('span').textContent = 'Reintentar envío';
          });
      }

      function appendCompanionsPanel(ticketId){
        if (!companionsEndpointAvailable || !ticketId || ticketId <= 0) return;
        if (out.querySelector('[data-role="companions-panel"]')) return;

        const panel = document.createElement('div');
        panel.className = 'evda-companion-panel';
        panel.dataset.role = 'companions-panel';
        panel.innerHTML = '<div class="evda-companion-label">Acompañantes sin QR</div>'
          + '<div class="evda-companion-row">'
          + '<input type="number" inputmode="numeric" class="evda-companion-input" min="0" max="500" step="1" value="0" aria-label="Cantidad de acompañantes">'
          + '<button type="button" class="evda-action-btn"><span>Registrar acompañantes</span></button>'
          + '</div><div class="evda-companion-status" aria-live="polite"></div>';
        out.appendChild(panel);

        const input = panel.querySelector('input');
        const action = panel.querySelector('button');
        const status = panel.querySelector('.evda-companion-status');

        action.addEventListener('click',()=>{
          const cantidad = parseInt(input.value,10);
          if (isNaN(cantidad) || cantidad < 0 || cantidad > 500) {
            status.textContent = 'Ingresa un número válido entre 0 y 500.';
            status.style.color = 'var(--evapp-danger)';
            return;
          }

          action.disabled = true;
          action.querySelector('span').textContent = 'Guardando…';
          status.textContent = 'Registrando acompañantes…';
          status.style.color = '';

          const fd = new FormData();
          fd.append('action','eventosapp_registrar_acompanantes');
          fd.append('companion_nonce',nonceCompanions);
          fd.append('ticket_id',String(ticketId));
          fd.append('cantidad',String(cantidad));

          fetch(ajaxURL,{method:'POST',body:fd,credentials:'same-origin'})
            .then(r=>r.json())
            .then(resp=>{
              if (resp && resp.success) {
                const total = resp.data && typeof resp.data.total !== 'undefined' ? resp.data.total : cantidad;
                status.textContent = cantidad+' acompañante(s) registrado(s). Total acumulado: '+total+'.';
                status.style.color = 'var(--evapp-success)';
                action.querySelector('span').textContent = 'Guardado';
                input.value = 0;
                setTimeout(()=>{
                  action.disabled = false;
                  action.querySelector('span').textContent = 'Registrar acompañantes';
                },2200);
                return;
              }
              status.textContent = responseMessage(resp,'Error al guardar.');
              status.style.color = 'var(--evapp-danger)';
              action.disabled = false;
              action.querySelector('span').textContent = 'Reintentar';
            })
            .catch(()=>{
              status.textContent = 'Error de conexión al registrar acompañantes.';
              status.style.color = 'var(--evapp-danger)';
              action.disabled = false;
              action.querySelector('span').textContent = 'Reintentar';
            });
        });
      }

      function renderTicketGrid(ticket){
        const qrBadge = '<span class="evda-qr-type">'+safeValue(ticket.qr_type_label || 'QR')+'</span>';
        let html = '<div class="evapp-qr-grid">';
        html += row('Nombre',ticket.nombre);
        html += row('Email',ticket.email);
        html += row('Ticket ID',ticket.ticket_id);
        html += row('Medio QR',qrBadge,true);
        if (ticket.empresa) html += row('Empresa',ticket.empresa);
        if (ticket.cargo) html += row('Cargo',ticket.cargo);
        html += row('Localidad',ticket.localidad);
        html += '</div>';
        return html;
      }

      function showPaymentBlocked(ticket){
        let html = renderState('danger','Check-in bloqueado',ticket.payment_message || 'El ticket debe estar pagado antes de permitir el ingreso.');
        html += renderTicketGrid(ticket);
        setOutput(html);
        appendPaymentReminder(parseInt(ticket.id || '0',10) || 0);
        injectScanAgainButton();
        smoothScrollTo(out);
      }

      function showAuthForm(ticket, errorMessage=''){
        currentTicket = ticket;

        let html = renderState('success','Ticket encontrado','El QR corresponde al evento activo. Verifica ahora el código de seguridad del asistente.');
        html += renderTicketGrid(ticket);
        if (ticket.payment_message) {
          html += '<div class="evda-payment-note">'+safeValue(ticket.payment_message,'')+'</div>';
        }
        html += '<div class="evda-auth-form">'
          + '<label class="evda-auth-label" for="'+root.id+'-auth-code">Código de verificación</label>'
          + '<p class="evda-auth-help" id="'+root.id+'-auth-help">Solicita al asistente el código de 5 dígitos recibido para este evento o para la fecha de hoy.</p>'
          + '<input type="text" id="'+root.id+'-auth-code" class="evda-auth-input" placeholder="00000" maxlength="5" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" enterkeyhint="done" aria-describedby="'+root.id+'-auth-help '+root.id+'-auth-error">'
          + '<div class="evda-auth-error'+(errorMessage ? ' is-visible' : '')+'" id="'+root.id+'-auth-error" role="alert">'+safeValue(errorMessage,'')+'</div>'
          + '<div class="evda-auth-buttons">'
          + '<button type="button" class="evda-action-btn evda-verify-btn" data-role="verify-code"><span>Verificar y aprobar Check-In</span></button>'
          + '<button type="button" class="evda-action-btn evda-cancel-btn" data-role="cancel-auth"><span>Cancelar</span></button>'
          + '</div></div>';

        setOutput(html);
        smoothScrollTo(out);

        const input = out.querySelector('.evda-auth-input');
        const verifyBtn = out.querySelector('[data-role="verify-code"]');
        const cancelBtn = out.querySelector('[data-role="cancel-auth"]');
        const errorEl = out.querySelector('.evda-auth-error');

        if (!input || !verifyBtn || !cancelBtn || !errorEl) return;

        setTimeout(()=>{
          try { input.focus({preventScroll:true}); } catch(e) { input.focus(); }
        },100);

        input.addEventListener('input',(e)=>{
          e.target.value = e.target.value.replace(/[^0-9]/g,'').slice(0,5);
          errorEl.classList.remove('is-visible');
          errorEl.textContent = '';
        });

        input.addEventListener('keydown',(e)=>{
          if (e.key === 'Enter') {
            e.preventDefault();
            verifyBtn.click();
          }
        });

        verifyBtn.addEventListener('click',()=>{
          const code = input.value.trim();
          if (!/^\d{5}$/.test(code)) {
            errorEl.textContent = 'Ingresa exactamente los 5 dígitos del código de verificación.';
            errorEl.classList.add('is-visible');
            input.focus();
            return;
          }
          verifyBtn.disabled = true;
          cancelBtn.disabled = true;
          verifyBtn.querySelector('span').textContent = 'Verificando…';
          verifyAndCheckin(ticket,code);
        });

        cancelBtn.addEventListener('click',()=>{
          currentTicket = null;
          setOutput(renderState('info','Operación cancelada','El ticket no fue modificado. Puedes escanear otro QR cuando estés listo.'));
          injectScanAgainButton();
          smoothScrollTo(out);
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
        currentTicket = null;
        setCameraState('Procesando','busy');
        setOutput(renderState('info','Validando QR','Estamos verificando el ticket y su relación con el evento activo.'));
        smoothScrollTo(out);

        const fd = new FormData();
        fd.append('action','eventosapp_search_ticket_by_qr');
        fd.append('nonce',nonceSearch);
        fd.append('event_id',String(eventID));
        fd.append('qr_code',data);

        fetch(ajaxURL,{method:'POST',body:fd,credentials:'same-origin'})
          .then(r=>r.json())
          .then(resp=>{
            setCameraState('Cámara inactiva');
            if (!resp || !resp.success || !resp.data || !resp.data.ticket) {
              setOutput(renderState('danger','No se pudo validar el QR',responseMessage(resp,'Ticket no encontrado.')));
              injectScanAgainButton();
              smoothScrollTo(out);
              return;
            }

            const ticket = resp.data.ticket;
            currentTicket = ticket;

            if (ticket.checked_in) {
              let html = renderState('warning','Ticket ya registrado','Este ticket ya realizó check-in en la fecha de hoy. No se ejecutó ningún cambio adicional.');
              html += renderTicketGrid(ticket);
              html += '<div class="evda-payment-note">Check-in registrado: '+safeValue(ticket.checkin_date,'-')+'</div>';
              setOutput(html);
              injectScanAgainButton();
              smoothScrollTo(out);
              return;
            }

            if (ticket.payment_required) {
              showPaymentBlocked(ticket);
              return;
            }

            showAuthForm(ticket);
          })
          .catch(()=>{
            setCameraState('Cámara inactiva');
            setOutput(renderState('danger','Error de conexión','No se pudo consultar el ticket. Comprueba la conexión e intenta nuevamente.'));
            injectScanAgainButton();
            smoothScrollTo(out);
          });
      }

      function verifyAndCheckin(ticket, code){
        setOutput(renderState('info','Verificando código','Estamos validando el código y las reglas de acceso antes de confirmar el check-in.'));
        smoothScrollTo(out);

        const fd = new FormData();
        fd.append('action','eventosapp_verify_and_checkin');
        fd.append('nonce',nonceVerify);
        fd.append('event_id',String(eventID));
        fd.append('ticket_id',String(ticket.id));
        fd.append('auth_code',code);
        fd.append('qr_type',ticket.qr_type || '');
        fd.append('qr_type_label',ticket.qr_type_label || '');

        fetch(ajaxURL,{method:'POST',body:fd,credentials:'same-origin'})
          .then(r=>r.json())
          .then(resp=>{
            if (!resp || !resp.success) {
              const payload = resp && resp.data && typeof resp.data === 'object' ? resp.data : {};
              const msg = responseMessage(resp,'No se pudo completar la validación.');

              if (payload.payment_required) {
                ticket.payment_required = true;
                ticket.payment_message = msg;
                showPaymentBlocked(ticket);
                return;
              }

              if (payload.auth_invalid) {
                showAuthForm(ticket,msg);
                return;
              }

              setOutput(renderState('danger','No se pudo completar el check-in',msg));
              injectScanAgainButton();
              smoothScrollTo(out);
              return;
            }

            const d = resp.data || {};
            let html = d.already_checked
              ? renderState('warning','Check-in ya confirmado','El asistente ya tenía ingreso registrado para esta fecha.')
              : renderState('success','Check-in confirmado','El ingreso del asistente quedó registrado correctamente después de validar el código de seguridad.');

            const qrBadge = '<span class="evda-qr-type">'+safeValue(d.qr_type_label || ticket.qr_type_label || 'QR')+'</span>';
            html += '<div class="evapp-qr-grid">';
            html += row('Nombre',d.full_name || ticket.nombre);
            html += row('Evento',d.event_name || <?php echo wp_json_encode( $event_name ); ?>);
            html += row('Fecha del check-in',d.checkin_date_label || d.checkin_date);
            html += row('Medio QR',qrBadge,true);
            if (d.empresa) html += row('Empresa',d.empresa);
            if (d.cargo) html += row('Cargo',d.cargo);
            html += row('Localidad',d.localidad || ticket.localidad);
            html += '</div>';

            if (d.payment_message) {
              html += '<div class="evda-payment-note">'+safeValue(d.payment_message,'')+'</div>';
            }

            setOutput(html);

            if (d.acompanantes_enabled && !d.already_checked) {
              appendCompanionsPanel(parseInt(d.ticket_id || ticket.id || '0',10) || 0);
            }

            injectScanAgainButton();
            currentTicket = null;
            smoothScrollTo(out);
          })
          .catch(()=>{
            setOutput(renderState('danger','Error de conexión','No se pudo verificar el código. Comprueba la conexión e intenta nuevamente.'));
            injectScanAgainButton();
            smoothScrollTo(out);
          });
      }

      btn.addEventListener('click',async()=>{
        if (stream && stream.active) {
          stop();
          setOutput('<div class="evapp-qr-help">Cámara detenida. Puedes volver a activarla cuando estés listo para el siguiente escaneo.</div>');
          return;
        }

        btn.disabled = true;
        setCameraState('Preparando cámara','busy');

        try {
          await ensureJsQR();
          const started = await start();
          if (started) {
            setOutput('<div class="evapp-qr-help">Cámara activa. Centra el QR dentro del marco; la lectura se detendrá automáticamente al detectar un código.</div>');
          }
        } catch(e) {
          setCameraState('Cámara inactiva');
          setOutput(renderState('danger','No se pudo preparar el lector',e && e.message ? e.message : 'Vuelve a intentar.'));
          smoothScrollTo(out);
        } finally {
          btn.disabled = !moduleEnabled || !dayIsValid;
        }
      });

      window.addEventListener('pagehide',stop,{passive:true});
      document.addEventListener('visibilitychange',()=>{
        if (document.hidden && running) stop();
      });
    })();
    </script>
    <?php
    return ob_get_clean();
} );

// ============================================================
// AJAX: Buscar ticket por QR
// ============================================================

add_action( 'wp_ajax_eventosapp_search_ticket_by_qr', 'eventosapp_ajax_search_ticket_by_qr' );
add_action( 'wp_ajax_nopriv_eventosapp_search_ticket_by_qr', 'eventosapp_ajax_search_ticket_by_qr' );

function eventosapp_ajax_search_ticket_by_qr() {
    check_ajax_referer( 'eventosapp_qr_search', 'nonce' );

    $qr_code  = isset( $_POST['qr_code'] ) ? sanitize_text_field( wp_unslash( $_POST['qr_code'] ) ) : '';
    $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;

    eventosapp_qr_double_auth_ajax_guard( $event_id );

    if ( $qr_code === '' || ! $event_id ) {
        wp_send_json_error( 'Datos incompletos', 400 );
    }

    $day_context = eventosapp_qr_double_auth_day_context( $event_id );
    if ( empty( $day_context['valid'] ) ) {
        wp_send_json_error(
            ! empty( $day_context['error'] )
                ? $day_context['error']
                : 'El check-in solo está permitido en las fechas del evento.',
            400
        );
    }

    $lookup = eventosapp_qr_double_auth_find_ticket( $qr_code, $event_id );
    if ( empty( $lookup['found'] ) || empty( $lookup['ticket_id'] ) ) {
        wp_send_json_error(
            ! empty( $lookup['error'] )
                ? $lookup['error']
                : 'Ticket no encontrado o no pertenece a este evento.',
            404
        );
    }

    $ticket_id = absint( $lookup['ticket_id'] );

    if ( get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
        wp_send_json_error( 'El QR no corresponde a un ticket válido.', 404 );
    }

    $ticket_event = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    if ( $ticket_event !== $event_id ) {
        wp_send_json_error( 'El ticket no pertenece a este evento.', 403 );
    }

    $today = $day_context['today'];

    $nombre           = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
    $apellido         = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );
    $email            = get_post_meta( $ticket_id, '_eventosapp_asistente_email', true );
    $localidad        = get_post_meta( $ticket_id, '_eventosapp_asistente_localidad', true );
    $empresa          = get_post_meta( $ticket_id, '_eventosapp_asistente_empresa', true );
    $cargo            = get_post_meta( $ticket_id, '_eventosapp_asistente_cargo', true );
    $ticket_public_id = get_post_meta( $ticket_id, 'eventosapp_ticketID', true );

    $status_arr = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
    $status_arr = maybe_unserialize( $status_arr );
    if ( ! is_array( $status_arr ) ) {
        $status_arr = [];
    }

    $checked_in   = isset( $status_arr[ $today ] ) && $status_arr[ $today ] === 'checked_in';
    $checkin_date = $checked_in ? date_i18n( 'd/m/Y', strtotime( $today ) ) : '';

    // Mantener paridad con el Check-In QR normal: si el evento controla pagos,
    // no se debe pedir el código de seguridad a un ticket que aún no puede ingresar.
    $control_pago    = get_post_meta( $event_id, '_eventosapp_ticket_control_pago', true ) === '1';
    $payment_required = false;
    $payment_message  = '';

    if ( $control_pago ) {
        $estado_pago = get_post_meta( $ticket_id, '_eventosapp_estado_pago', true );
        if ( empty( $estado_pago ) ) {
            $estado_pago = 'no_pagado';
        }

        if ( $estado_pago === 'no_pagado' ) {
            $payment_required = true;
            $payment_message  = 'El Check-In no se puede realizar porque el ticket no ha sido pagado. Realiza el pago correspondiente para continuar.';
        } else {
            $payment_message = 'El ticket está en modo Pagado.';
        }
    }

    wp_send_json_success( [
        'ticket' => [
            'id'               => $ticket_id,
            'nombre'           => trim( $nombre . ' ' . $apellido ),
            'email'            => $email,
            'ticket_id'        => $ticket_public_id,
            'localidad'        => $localidad,
            'empresa'          => $empresa,
            'cargo'            => $cargo,
            'qr_type'          => isset( $lookup['type'] ) ? $lookup['type'] : 'legacy',
            'qr_type_label'    => isset( $lookup['type_label'] ) ? $lookup['type_label'] : 'QR Legacy',
            'checked_in'       => $checked_in,
            'checkin_date'     => $checkin_date,
            'payment_required' => $payment_required,
            'payment_message'  => $payment_message,
        ],
    ] );
}

// ============================================================
// AJAX: Verificar código y hacer check-in
// ============================================================

add_action( 'wp_ajax_eventosapp_verify_and_checkin', 'eventosapp_ajax_verify_and_checkin' );
add_action( 'wp_ajax_nopriv_eventosapp_verify_and_checkin', 'eventosapp_ajax_verify_and_checkin' );

function eventosapp_ajax_verify_and_checkin() {
    check_ajax_referer( 'eventosapp_verify_checkin', 'nonce' );

    $ticket_id     = isset( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
    $auth_code     = isset( $_POST['auth_code'] ) ? sanitize_text_field( wp_unslash( $_POST['auth_code'] ) ) : '';
    $event_id      = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
    $qr_type       = isset( $_POST['qr_type'] ) ? sanitize_key( wp_unslash( $_POST['qr_type'] ) ) : 'legacy';
    $qr_type_label = isset( $_POST['qr_type_label'] ) ? sanitize_text_field( wp_unslash( $_POST['qr_type_label'] ) ) : '';

    eventosapp_qr_double_auth_ajax_guard( $event_id );

    if ( ! $ticket_id || ! $event_id || ! preg_match( '/^\d{5}$/', $auth_code ) ) {
        wp_send_json_error( [
            'message'      => 'Datos incompletos o código de verificación inválido.',
            'auth_invalid' => true,
        ], 400 );
    }

    if ( get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
        wp_send_json_error( 'Ticket inválido.', 404 );
    }

    $ticket_event = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    if ( $ticket_event !== $event_id ) {
        wp_send_json_error( 'El ticket no pertenece a este evento.', 403 );
    }

    $day_context = eventosapp_qr_double_auth_day_context( $event_id );
    if ( empty( $day_context['valid'] ) ) {
        wp_send_json_error(
            ! empty( $day_context['error'] )
                ? $day_context['error']
                : 'El check-in solo está permitido en las fechas del evento.',
            400
        );
    }

    // Mantener paridad con el Check-In QR principal para evitar que este módulo
    // se convierta en una vía alternativa que omita el control de pago.
    $control_pago   = get_post_meta( $event_id, '_eventosapp_ticket_control_pago', true ) === '1';
    $payment_message = '';

    if ( $control_pago ) {
        $estado_pago = get_post_meta( $ticket_id, '_eventosapp_estado_pago', true );
        if ( empty( $estado_pago ) ) {
            $estado_pago = 'no_pagado';
        }

        if ( $estado_pago === 'no_pagado' ) {
            wp_send_json_error( [
                'message'          => 'El Check-In no se puede realizar porque el ticket no ha sido pagado. Realiza el pago correspondiente para continuar.',
                'payment_required' => true,
                'ticket_id'        => $ticket_id,
            ], 403 );
        }

        $payment_message = 'El ticket está en modo Pagado.';
    }

    if ( ! function_exists( 'eventosapp_validate_auth_code' ) ) {
        wp_send_json_error( 'Sistema de autenticación no disponible.', 500 );
    }

    if ( ! eventosapp_validate_auth_code( $ticket_id, $auth_code ) ) {
        wp_send_json_error( [
            'message'      => 'Código de verificación incorrecto. Verifica los 5 dígitos e intenta nuevamente.',
            'auth_invalid' => true,
        ], 403 );
    }

    if ( $qr_type_label === '' ) {
        if ( class_exists( 'EventosApp_QR_Manager' ) && method_exists( 'EventosApp_QR_Manager', 'get_qr_type_label' ) ) {
            $qr_type_label = EventosApp_QR_Manager::get_qr_type_label( $qr_type );
        } elseif ( $qr_type === 'whatsapp' ) {
            $qr_type_label = 'WhatsApp';
        } elseif ( $qr_type === 'preprinted' ) {
            $qr_type_label = 'QR Preimpreso';
        } else {
            $qr_type_label = 'QR Legacy';
        }
    }

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

    $today = $day_context['today'] ?: $dt->format( 'Y-m-d' );

    $status_arr = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
    $status_arr = maybe_unserialize( $status_arr );
    if ( ! is_array( $status_arr ) ) {
        $status_arr = [];
    }

    $already_checked = isset( $status_arr[ $today ] ) && $status_arr[ $today ] === 'checked_in';

    if ( ! $already_checked ) {
        $status_arr[ $today ] = 'checked_in';
        update_post_meta( $ticket_id, '_eventosapp_checkin_status', $status_arr );

        $log = get_post_meta( $ticket_id, '_eventosapp_checkin_log', true );
        $log = maybe_unserialize( $log );
        if ( ! is_array( $log ) ) {
            $log = [];
        }

        $user = wp_get_current_user();
        $log[] = [
            'fecha'         => $dt->format( 'Y-m-d' ),
            'hora'          => $dt->format( 'H:i:s' ),
            'dia'           => $today,
            'status'        => 'checked_in',
            'origen'        => 'QR Doble Autenticación',
            'qr_type'       => $qr_type,
            'qr_type_label' => $qr_type_label,
            'usuario'       => $user && $user->exists()
                ? ( $user->display_name . ' (' . $user->user_email . ')' )
                : 'Sistema',
        ];
        update_post_meta( $ticket_id, '_eventosapp_checkin_log', $log );

        if ( function_exists( 'eventosapp_update_qr_usage_stats' ) ) {
            eventosapp_update_qr_usage_stats( $event_id, $qr_type );
        }
    }

    $nombre    = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
    $apellido  = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );
    $empresa   = get_post_meta( $ticket_id, '_eventosapp_asistente_empresa', true );
    $cargo     = get_post_meta( $ticket_id, '_eventosapp_asistente_cargo', true );
    $localidad = get_post_meta( $ticket_id, '_eventosapp_asistente_localidad', true );
    $full_name = trim( $nombre . ' ' . $apellido );

    $message = $already_checked
        ? sprintf( 'Check-in confirmado para %s (ya había ingresado hoy anteriormente)', $full_name )
        : sprintf( 'Check-in exitoso para %s', $full_name );

    wp_send_json_success( [
        'message'              => $message,
        'already_checked'      => $already_checked,
        'ticket_id'            => $ticket_id,
        'full_name'            => $full_name,
        'event_name'           => get_the_title( $event_id ),
        'empresa'              => $empresa,
        'cargo'                => $cargo,
        'localidad'            => $localidad,
        'checkin_date'         => $today,
        'checkin_date_label'   => date_i18n( 'D, d M Y', strtotime( $today ) ),
        'qr_type'              => $qr_type,
        'qr_type_label'        => $qr_type_label,
        'payment_message'      => $payment_message,
        'acompanantes_enabled' => get_post_meta( $event_id, '_eventosapp_ticket_acompanantes_checkin', true ) === '1',
    ] );
}
