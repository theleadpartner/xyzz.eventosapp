<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Iconos inline para el dashboard (relleno/trazo = currentColor).
 */
if ( ! function_exists('eventosapp_dashboard_icon') ) {
	function eventosapp_dashboard_icon( $name ) {
		switch ($name) {
			case 'metrics':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="10" width="4" height="10" rx="1"/><rect x="10" y="4" width="4" height="16" rx="1"/><rect x="17" y="7" width="4" height="13" rx="1"/></svg>';

			case 'flow-metrics':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v9H4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M7 18h10M9 21h6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M7 11l2-2 2 2 4-5 2 3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="18" cy="18" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>';

			case 'building-checkin':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21V5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v16M2 21h20M8 7h2M13 7h1M8 11h2M13 11h1M8 15h2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="m15 16 2 2 4-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

			case 'circle-user':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/>
					<circle cx="12" cy="9" r="3" fill="none" stroke="currentColor" stroke-width="2"/>
					<path d="M6.5 17c1.2-2.4 3.6-3.5 5.5-3.5S16.8 14.6 18 17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				</svg>';

			case 'qrcode':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 14h2v2h-2zM16 18h2v2h-2zM20 18h2v2h-2z"/>
				</svg>';

			case 'calendar-check':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<rect x="3" y="5" width="18" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>
					<path d="M7 3v4M17 3v4M3 9h18M9 15l2 2 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>';

			case 'id-badge':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<rect x="6" y="5" width="12" height="16" rx="2"/>
					<rect x="10" y="2" width="4" height="3" rx="1"/>
					<circle cx="12" cy="11" r="2.5"/>
					<path d="M9 16h6a.8.8 0 0 1 .8.8V18H8.2v-1.2A.8.8 0 0 1 9 16Z"/>
				</svg>';

			case 'self-checkin':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<rect x="4" y="3" width="16" height="18" rx="3" fill="none" stroke="currentColor" stroke-width="2"/>
					<circle cx="12" cy="9" r="2.5" fill="none" stroke="currentColor" stroke-width="2"/>
					<path d="M8 15c.8-2 2.2-3 4-3s3.2 1 4 3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					<path d="M8 18h8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				</svg>';

			case 'check-double':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M3 13l3 3 5-6M12 13l3 3 6-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>';

			case 'checklist':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<rect x="4" y="4" width="16" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>
					<path d="M8 8h8M8 12h8M8 16h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					<path d="M5 5l2 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				</svg>';

			case 'ticket':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M5 9V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
					<rect x="9" y="9" width="6" height="6" rx="1" fill="none" stroke="currentColor" stroke-width="2"/>
				</svg>';

			case 'trophy':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M4 5h16v2a5 5 0 0 1-5 5h-6a5 5 0 0 1-5-5V5Z" fill="none" stroke="currentColor" stroke-width="2"/>
					<path d="M9 12v2a3 3 0 0 0 6 0v-2M8 21h8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				</svg>';

			case 'shield-check':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M12 2L4 6v6c0 5.5 3.8 10.7 8 12 4.2-1.3 8-6.5 8-12V6l-8-4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
					<path d="M9 12l2 2 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>';

			case 'face-scan': // NUEVO: Check-In Facial
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M3 8V6a2 2 0 0 1 2-2h2M3 16v2a2 2 0 0 0 2 2h2M21 8V6a2 2 0 0 0-2-2h-2M21 16v2a2 2 0 0 1-2 2h-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					<circle cx="12" cy="10" r="3" fill="none" stroke="currentColor" stroke-width="2"/>
					<path d="M8 18c.8-2.3 2.2-3.5 4-3.5s3.2 1.2 4 3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				</svg>';

			case 'support-assistance':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M4 5h16v14H4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
					<path d="M8 9h8M8 13h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					<path d="M16 15l1.5 1.5L21 13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>';

			case 'support-metrics':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<circle cx="8" cy="8" r="3" fill="none" stroke="currentColor" stroke-width="2"/>
					<circle cx="17" cy="7" r="2.5" fill="none" stroke="currentColor" stroke-width="2"/>
					<path d="M3 20c.8-3.2 2.5-5 5-5s4.2 1.8 5 5M13 19c.5-2.3 1.8-3.7 4-3.7 1.8 0 3.1 1 4 3.7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				</svg>';

			case 'expositor':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M4 9h16l-1-4H5L4 9Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
					<path d="M5 9v10h14V9M8 19v-6h4v6M14 13h3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M4 9c.4 1.4 1.4 2 2.5 2S8.6 10.4 9 9c.4 1.4 1.4 2 2.5 2S13.6 10.4 14 9c.4 1.4 1.4 2 2.5 2S18.6 10.4 19 9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				</svg>';

			case 'expositor-gestion':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M4 9h16l-1-4H5L4 9Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
					<path d="M5 9v10h14V9M8 19v-5h4v5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<circle cx="17" cy="16" r="3" fill="none" stroke="currentColor" stroke-width="2"/>
					<path d="M16 16l1 1 2-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>';

			case 'live-raffle':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M5 4h14v5a7 7 0 0 1-14 0V4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
					<path d="M8 20h8M12 16v4M5 7H2v1a4 4 0 0 0 4 4M19 7h3v1a4 4 0 0 1-4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="m12 6 .8 1.7 1.9.2-1.4 1.3.4 1.9-1.7-.9-1.7.9.4-1.9-1.4-1.3 1.9-.2L12 6Z"/>
				</svg>';

			default:
				return '';
		}
	}
}

/**
 * CSS del dashboard (se imprime una sola vez por carga).
 */
if ( ! function_exists('eventosapp_print_dashboard_css') ) {
	function eventosapp_print_dashboard_css() {
		static $printed = false;
		if ( $printed ) return;
		$printed = true;
		?>
		<style>
/* =========================
   Variables base
   ========================= */
.evapp-dashboard{
  --evapp-blue:#2F73B5;
  --evapp-blue-hover:#275F95;
  --evapp-blue-active:#1F4B77;
  --evapp-radius:18px;
  --evapp-gap:22px;
}

/* =========================
   Grid de botones (2 por fila)
   ========================= */
.evapp-grid{
  display:grid;
  grid-template-columns: repeat(2, minmax(0,1fr));
  gap: var(--evapp-gap);
  margin-top:16px;
}

/* En móvil: la BOTONERA (solo la grid) mide 1000px y hace scroll si no cabe todo */
@media (max-width: 991px){
  .evapp-grid{
    height: 1000px;
    overflow-y: auto;
    overflow-x: hidden;
  }
}

/* =========================
   Tarjetas
   ========================= */
.evapp-card{
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  text-align:center;
  gap:10px;
  padding:28px 16px;
  border-radius:var(--evapp-radius);
  background:var(--evapp-blue);
  color:#fff;
  text-decoration:none;
  box-shadow:0 4px 14px rgba(0,0,0,.10);
  transition:background-color .15s ease, transform .12s ease, box-shadow .15s ease;
  box-sizing:border-box;
}

/* Texto SIEMPRE blanco en cualquier estado */
.evapp-card,
.evapp-card:link,
.evapp-card:visited{ color:#fff; }

.evapp-card:hover{
  background:var(--evapp-blue-hover);
  transform:translateY(-2px);
  box-shadow:0 10px 20px rgba(0,0,0,.16);
}
.evapp-card:active{ background:var(--evapp-blue-active); transform:translateY(0); }
.evapp-card:focus-visible{ outline:3px solid rgba(255,255,255,.5); outline-offset:3px; }

/* =========================
   Icono + título (tamaños fijos)
   ========================= */
.evapp-ico{ width:64px; height:64px; display:block; fill:currentColor; }
.evapp-title{
  font-weight:800;
  font-size:22px;
  line-height:1.15;
  letter-spacing:.2px;
}

/* Tamaños fijos para móvil */
@media (max-width: 991px){
  .evapp-ico{ width:56px; height:56px; }
  .evapp-title{ font-size:20px; }
}

@media (min-width:1200px){
  .evapp-title{ font-size:24px; }
  .evapp-ico{ width:72px; height:72px; }
}

/* Ocultar botones WP por defecto dentro del grid */
.evapp-grid .button,
.evapp-grid .button-primary{ display:none !important; }
		</style>
		<?php
	}
}



/**
 * Helper central para validar si el usuario puede activar o conservar un evento
 * dentro del dashboard. Se mantiene aquí para que el dashboard no dependa sólo
 * de permisos globales por rol cuando existen asignaciones específicas por evento.
 */
if ( ! function_exists('eventosapp_dashboard_user_can_select_event') ) {
	function eventosapp_dashboard_user_can_select_event( $event_id, $user_id = 0 ) {
		$event_id = absint( $event_id );
		$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $event_id || ! $user_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
			return false;
		}

		if ( function_exists('eventosapp_dashboard_user_can_access_event_scope') ) {
			return eventosapp_dashboard_user_can_access_event_scope( $event_id, $user_id );
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		if ( function_exists('eventosapp_staff_access_user_can_select_event_in_dashboard') && eventosapp_staff_access_user_can_select_event_in_dashboard($event_id, $user_id) ) {
			return true;
		}

		if ( function_exists('eventosapp_support_user_has_assignment_in_event') && eventosapp_support_user_has_assignment_in_event($event_id, $user_id) ) {
			return true;
		}

		if ( function_exists('eventosapp_expositor_user_can_select_event_in_dashboard') && eventosapp_expositor_user_can_select_event_in_dashboard($event_id, $user_id) ) {
			return true;
		}

		if ( function_exists('eventosapp_user_can_manage_event') && eventosapp_user_can_manage_event($event_id, $user_id) ) {
			return true;
		}

		$post = get_post( $event_id );
		return $post && (int) $post->post_author === $user_id;
	}
}

/**
 * Determina si el usuario tiene por lo menos un evento al que pueda entrar desde el dashboard.
 *
 * Esta validación no reemplaza los permisos por módulo. Solo evita que el dashboard quede
 * bloqueado cuando el usuario tiene acceso por Co-gestión, Staff operativo, Acceso
 * personalizado, Asistencia o Expositor, pero todavía no tiene un evento activo válido.
 */
if ( ! function_exists('eventosapp_dashboard_user_has_any_selectable_event') ) {
	function eventosapp_dashboard_user_has_any_selectable_event( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		if ( function_exists('eventosapp_staff_access_user_has_any_dashboard_event') && eventosapp_staff_access_user_has_any_dashboard_event( $user_id ) ) {
			return true;
		}

		if ( function_exists('eventosapp_dashboard_user_has_any_cogestion_assignment') && eventosapp_dashboard_user_has_any_cogestion_assignment( $user_id ) ) {
			return true;
		}

		if ( function_exists('eventosapp_support_user_has_any_event') && eventosapp_support_user_has_any_event( $user_id ) ) {
			return true;
		}

		if ( function_exists('eventosapp_expositor_user_has_any_event') && eventosapp_expositor_user_has_any_event( $user_id ) ) {
			return true;
		}

		$event_ids = get_posts([
			'post_type'      => 'eventosapp_event',
			'post_status'    => ['publish', 'private', 'future', 'draft', 'pending'],
			'posts_per_page' => 300,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);

		foreach ( (array) $event_ids as $event_id ) {
			if ( function_exists('eventosapp_dashboard_user_can_select_event') && eventosapp_dashboard_user_can_select_event( $event_id, $user_id ) ) {
				return true;
			}
		}

		return false;
	}
}

/**
 * Shortcode principal del dashboard.
 * Uso: [eventosapp_dashboard]
 */
add_shortcode('eventosapp_dashboard', function(){
	if ( ! is_user_logged_in() ) {
		$login = wp_login_url( get_permalink() );
		return '<p>Debes iniciar sesión. <a href="'.esc_url($login).'">Iniciar sesión</a></p>';
	}

	$current_user_id = get_current_user_id();
	$active_event    = function_exists('eventosapp_get_active_event') ? absint( eventosapp_get_active_event( $current_user_id ) ) : 0;

	// Mensajes
	$msg = '';
	if ( isset($_GET['evapp_err']) && $_GET['evapp_err'] !== '' ) {
		$msg = '<div class="notice notice-error" style="padding:8px;margin:10px 0;"><strong>Error:</strong> '
			. esc_html( rawurldecode( wp_unslash($_GET['evapp_err']) ) ) . '</div>';
	} elseif ( isset($_GET['set']) && $_GET['set'] === '1' ) {
		$msg = '<div class="notice notice-success" style="padding:8px;margin:10px 0;">Evento activado.</div>';
	}

	// Cambio de evento por GET. Debe ejecutarse antes de validar permisos para evitar
	// que un evento activo anterior deje bloqueado el acceso al selector del dashboard.
	if ( isset($_GET['evapp']) && $_GET['evapp'] === 'change_event' ) {
		if ( function_exists('eventosapp_clear_active_event') ) {
			eventosapp_clear_active_event( $current_user_id );
		}
		$active_event = 0;
	}

	// Si el usuario conserva en usermeta un evento activo viejo o no permitido, se limpia
	// antes de consultar eventosapp_role_can('dashboard'). De lo contrario el candado por
	// evento puede negar el dashboard completo y no deja llegar al selector.
	if ( $active_event && function_exists('eventosapp_dashboard_user_can_select_event') && ! eventosapp_dashboard_user_can_select_event( $active_event, $current_user_id ) ) {
		if ( function_exists('eventosapp_clear_active_event') ) {
			eventosapp_clear_active_event( $current_user_id );
		}
		$active_event = 0;
		if ( $msg === '' ) {
			$msg = '<div class="notice notice-error" style="padding:8px;margin:10px 0;"><strong>Error:</strong> El evento activo anterior ya no está disponible para tu usuario. Selecciona un evento permitido.</div>';
		}
	}

	$evapp_can_view_dashboard = function_exists('eventosapp_role_can') && eventosapp_role_can('dashboard');
	if ( ! $evapp_can_view_dashboard && function_exists('eventosapp_dashboard_user_has_any_selectable_event') ) {
		$evapp_can_view_dashboard = eventosapp_dashboard_user_has_any_selectable_event( $current_user_id );
	}
	if ( ! $evapp_can_view_dashboard && function_exists('eventosapp_staff_access_user_has_any_dashboard_event') ) {
		$evapp_can_view_dashboard = eventosapp_staff_access_user_has_any_dashboard_event( $current_user_id );
	}
	if ( ! $evapp_can_view_dashboard && function_exists('eventosapp_dashboard_user_has_any_cogestion_assignment') ) {
		$evapp_can_view_dashboard = eventosapp_dashboard_user_has_any_cogestion_assignment( $current_user_id );
	}
	if ( ! $evapp_can_view_dashboard && function_exists('eventosapp_support_user_has_any_event') ) {
		$evapp_can_view_dashboard = eventosapp_support_user_has_any_event( $current_user_id );
	}
	if ( ! $evapp_can_view_dashboard && function_exists('eventosapp_expositor_user_has_any_event') ) {
		$evapp_can_view_dashboard = eventosapp_expositor_user_has_any_event( $current_user_id );
	}

	if ( ! $evapp_can_view_dashboard ) {
		return '<p>No tienes permisos para ver este panel.</p>';
	}

	ob_start();
	echo '<div class="evapp-dashboard">';

	if ( $active_event ) {
		// Estilos una sola vez
		eventosapp_print_dashboard_css();
		if ( $msg ) echo $msg;

		// Barra "Evento activo"
		if (function_exists('eventosapp_active_event_bar')) {
			eventosapp_active_event_bar();
		}

// URLs desde Configuración
		$url_metrics        = function_exists('eventosapp_get_metrics_url')               ? eventosapp_get_metrics_url()               : '#';
		$url_flow_metrics   = function_exists('eventosapp_get_flow_metrics_url')          ? eventosapp_get_flow_metrics_url()          : '#';
		$url_search         = function_exists('eventosapp_get_search_url')                ? eventosapp_get_search_url()                : '#';
		$url_self_checkin   = function_exists('eventosapp_get_self_checkin_url')          ? eventosapp_get_self_checkin_url()          : '#';
		$url_register       = function_exists('eventosapp_get_register_url')              ? eventosapp_get_register_url()              : '#';
		$url_qr             = function_exists('eventosapp_get_qr_url')                    ? eventosapp_get_qr_url()                    : '#';
		$url_edit           = function_exists('eventosapp_get_edit_url')                  ? eventosapp_get_edit_url()                  : '#';
		$url_qr_localidad   = function_exists('eventosapp_get_qr_localidad_url')          ? eventosapp_get_qr_localidad_url()          : '#';
		$url_qr_sesion      = function_exists('eventosapp_get_qr_sesion_url')             ? eventosapp_get_qr_sesion_url()             : '#';
		$url_checklist      = function_exists('eventosapp_get_checklist_url')             ? eventosapp_get_checklist_url()             : '#';
		$url_net_ranking    = function_exists('eventosapp_get_networking_ranking_url')    ? eventosapp_get_networking_ranking_url()    : '#';
		$url_qr_double_auth = function_exists('eventosapp_get_qr_double_auth_url')        ? eventosapp_get_qr_double_auth_url()        : '#';
		$url_face_checkin   = function_exists('eventosapp_get_face_checkin_url')          ? eventosapp_get_face_checkin_url()          : '#';
		$url_support_assist = function_exists('eventosapp_get_support_assistance_url')    ? eventosapp_get_support_assistance_url()    : '#';
		$url_support_stats  = function_exists('eventosapp_get_support_team_metrics_url')  ? eventosapp_get_support_team_metrics_url()  : '#';
		$url_expositor      = function_exists('eventosapp_get_expositor_url')             ? eventosapp_get_expositor_url()             : '#';
		$url_expo_gestion   = function_exists('eventosapp_get_expositor_gestion_url')     ? eventosapp_get_expositor_gestion_url()     : '#';
		$url_company_checkin = function_exists('eventosapp_get_company_checkin_url')       ? eventosapp_get_company_checkin_url()       : '#';
		$url_live_raffle     = function_exists('eventosapp_get_live_raffle_url')           ? eventosapp_get_live_raffle_url()           : '#';

		?>
		<div class="evapp-grid" role="navigation" aria-label="Panel de acciones del evento">
			<?php if (eventosapp_role_can('metrics')): ?>
				<a class="evapp-card" href="<?php echo esc_url($url_metrics); ?>" aria-label="Métricas">
					<?php echo eventosapp_dashboard_icon('metrics'); ?>
					<span class="evapp-title">Métricas</span>
				</a>
			<?php endif; ?>

			<?php if (eventosapp_role_can('flow_metrics')): ?>
				<a class="evapp-card" href="<?php echo esc_url($url_flow_metrics); ?>" aria-label="Métricas de Encuestas">
					<?php echo eventosapp_dashboard_icon('flow-metrics'); ?>
					<span class="evapp-title">Métricas de Encuestas</span>
				</a>
			<?php endif; ?>

			<?php
			$can_view_company_checkin = function_exists('eventosapp_company_checkin_user_can_view')
				? eventosapp_company_checkin_user_can_view($active_event, $current_user_id)
				: (function_exists('eventosapp_company_checkin_is_enabled') && eventosapp_company_checkin_is_enabled($active_event) && eventosapp_role_can('company_checkin'));
			?>
			<?php if ($can_view_company_checkin): ?>
				<a class="evapp-card" href="<?php echo esc_url($url_company_checkin); ?>" aria-label="Empresas con Check-In">
					<?php echo eventosapp_dashboard_icon('building-checkin'); ?>
					<span class="evapp-title">Empresas con Check-In</span>
				</a>
			<?php endif; ?>

			<?php
			$can_view_live_raffle = function_exists('eventosapp_live_raffle_user_can_view')
				? eventosapp_live_raffle_user_can_view($active_event, $current_user_id)
				: (function_exists('eventosapp_live_raffle_is_enabled') && eventosapp_live_raffle_is_enabled($active_event) && eventosapp_role_can('live_raffle'));
			?>
			<?php if ($can_view_live_raffle): ?>
				<a class="evapp-card" href="<?php echo esc_url($url_live_raffle); ?>" aria-label="Sorteo en Vivo">
					<?php echo eventosapp_dashboard_icon('live-raffle'); ?>
					<span class="evapp-title">Sorteo en Vivo</span>
				</a>
			<?php endif; ?>

			<?php if (eventosapp_role_can('register')): ?>
				<a class="evapp-card" href="<?php echo esc_url($url_register); ?>" aria-label="Registro manual de asistentes">
					<?php echo eventosapp_dashboard_icon('circle-user'); ?>
					<span class="evapp-title">Registro Manual de Asistentes</span>
				</a>
			<?php endif; ?>

			<?php if (eventosapp_role_can('qr')): ?>
				<a class="evapp-card" href="<?php echo esc_url($url_qr); ?>" aria-label="Check-In con QR">
					<?php echo eventosapp_dashboard_icon('qrcode'); ?>
					<span class="evapp-title">Check-In con QR</span>
				</a>
			<?php endif; ?>

			<?php if (eventosapp_role_can('search')): ?>
				<a class="evapp-card" href="<?php echo esc_url($url_search); ?>" aria-label="Check-In Manual y Escarapela">
					<?php echo eventosapp_dashboard_icon('id-badge'); ?>
					<span class="evapp-title">Check-In Manual &amp; Escarapela</span>
				</a>
			<?php endif; ?>

			<?php if (eventosapp_role_can('self_checkin')): ?>
				<a class="evapp-card" href="<?php echo esc_url($url_self_checkin); ?>" aria-label="Autogestión del Asistente">
					<?php echo eventosapp_dashboard_icon('self-checkin'); ?>
					<span class="evapp-title">Autogestión del Asistente</span>
				</a>
			<?php endif; ?>

			<?php if (eventosapp_role_can('qr_localidad')): ?>
				<a class="evapp-card" href="<?php echo esc_url($url_qr_localidad); ?>" aria-label="Validador de Localidad">
					<?php echo eventosapp_dashboard_icon('check-double'); ?>
					<span class="evapp-title">Validador de Localidad</span>
				</a>
			<?php endif; ?>

			<?php if (eventosapp_role_can('qr_sesion')): ?>
				<a class="evapp-card" href="<?php echo esc_url($url_qr_sesion); ?>" aria-label="Control de Acceso a Sesión">
					<?php echo eventosapp_dashboard_icon('calendar-check'); ?>
					<span class="evapp-title">Control de Acceso a Sesión</span>
				</a>
			<?php endif; ?>

			<?php if (eventosapp_role_can('edit')): ?>
				<a class="evapp-card" href="<?php echo esc_url($url_edit); ?>" aria-label="Ticket">
					<?php echo eventosapp_dashboard_icon('ticket'); ?>
					<span class="evapp-title">Edición de Tickets</span>
				</a>
			<?php endif; ?>
			
			<?php if (eventosapp_role_can('checklist')): ?>
				<a class="evapp-card" href="<?php echo esc_url($url_checklist); ?>" aria-label="Checklist de Evento">
					<?php echo eventosapp_dashboard_icon('checklist'); ?>
					<span class="evapp-title">Checklist de Evento</span>
				</a>
			<?php endif; ?>

			<?php if (eventosapp_role_can('networking_ranking')): ?>
				<a class="evapp-card" href="<?php echo esc_url($url_net_ranking); ?>" aria-label="Ranking Networking">
					<?php echo eventosapp_dashboard_icon('trophy'); ?>
					<span class="evapp-title">Ranking Networking</span>
				</a>
			<?php endif; ?>

			<?php if (eventosapp_role_can('qr_double_auth')): ?>
				<a class="evapp-card" href="<?php echo esc_url($url_qr_double_auth); ?>" aria-label="Check-In QR Doble Autenticación">
					<?php echo eventosapp_dashboard_icon('shield-check'); ?>
					<span class="evapp-title">Check-In QR Doble Autenticación</span>
				</a>
			<?php endif; ?>

			<?php if (eventosapp_role_can('face_checkin')): ?>
    <a class="evapp-card" href="<?php echo esc_url($url_face_checkin); ?>" aria-label="Check-In Facial">
        <?php echo eventosapp_dashboard_icon('face-scan'); ?>
        <span class="evapp-title">Check-In Facial</span>
    </a>
<?php endif; ?>

            <?php if (eventosapp_role_can('support_assistance')): ?>
                <a class="evapp-card" href="<?php echo esc_url($url_support_assist); ?>" aria-label="Asistencia">
                    <?php echo eventosapp_dashboard_icon('support-assistance'); ?>
                    <span class="evapp-title">Asistencia</span>
                </a>
            <?php endif; ?>

            <?php if (eventosapp_role_can('support_team_metrics')): ?>
                <a class="evapp-card" href="<?php echo esc_url($url_support_stats); ?>" aria-label="Métrica de equipo de apoyo">
                    <?php echo eventosapp_dashboard_icon('support-metrics'); ?>
                    <span class="evapp-title">Métrica de equipo de apoyo</span>
                </a>
            <?php endif; ?>

            <?php if (eventosapp_role_can('expositor')): ?>
                <a class="evapp-card" href="<?php echo esc_url($url_expositor); ?>" aria-label="Expositor">
                    <?php echo eventosapp_dashboard_icon('expositor'); ?>
                    <span class="evapp-title">Expositor</span>
                </a>
            <?php endif; ?>

            <?php if (eventosapp_role_can('expositor_gestion')): ?>
                <a class="evapp-card" href="<?php echo esc_url($url_expo_gestion); ?>" aria-label="Gestión de Expositores">
                    <?php echo eventosapp_dashboard_icon('expositor-gestion'); ?>
                    <span class="evapp-title">Gestión de Expositores</span>
                </a>
            <?php endif; ?>

		</div>
		<?php

	} else {
		// ==== No hay evento elegido: selector (igual que antes) ====
		if ( $msg ) echo $msg;

		$query_args = [
			'post_type'      => 'eventosapp_event',
			'posts_per_page' => 300,
			'post_status'    => ['publish', 'private', 'future', 'draft', 'pending'],
			'orderby'        => 'title',
			'order'          => 'ASC',
		];
		$all_events = get_posts($query_args);

		$events = array_values(array_filter($all_events, function($ev){
			return function_exists('eventosapp_dashboard_user_can_select_event')
				? eventosapp_dashboard_user_can_select_event($ev->ID, get_current_user_id())
				: ( current_user_can('manage_options') || (int)$ev->post_author === get_current_user_id() );
		}));

		echo '<div style="background:#f6f7f7;border:1px solid #e1e1e1;padding:14px;border-radius:8px;">';
		echo '<h3 style="margin-top:0">Elige el evento que deseas gestionar</h3>';
		echo '<form method="post" id="evapp-select-event-form">';
		wp_nonce_field('evapp_set_event');
		echo '<input type="hidden" name="evapp_action" value="set_event">';

		echo '<select name="eventosapp_event_id" id="evapp_event_selector" style="min-width:320px">';
		echo '<option value="">— Selecciona tu evento —</option>';
		foreach ($events as $ev) {
			printf('<option value="%d">%s</option>', $ev->ID, esc_html($ev->post_title));
		}
		echo '</select> ';

		echo '<button type="submit" class="button button-primary" id="evapp_manage_btn" disabled>Gestionar evento</button>';
		echo '</form>';
		echo '<p style="color:#666;margin:8px 0 0;">* Hasta que no elijas un evento, las demás opciones del panel permanecerán deshabilitadas.</p>';
		echo '</div>';

		?>
		<script>
		(function(){
			var sel = document.getElementById('evapp_event_selector');
			var btn = document.getElementById('evapp_manage_btn');
			function toggle(){ btn.disabled = ! sel.value; }
			sel.addEventListener('change', toggle);
			toggle();
		})();
		</script>
		<?php
	}

	echo '</div>';
	return ob_get_clean();
});
