<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Iconos inline para el dashboard (relleno/trazo = currentColor).
 */
if ( ! function_exists('eventosapp_dashboard_icon') ) {
	function eventosapp_dashboard_icon( $name ) {
		switch ($name) {
			case 'metrics': // barras (métricas)
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="10" width="4" height="10" rx="1"/><rect x="10" y="4" width="4" height="16" rx="1"/><rect x="17" y="7" width="4" height="13" rx="1"/></svg>';

			case 'circle-user': // registro manual
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/>
					<circle cx="12" cy="9" r="3" fill="none" stroke="currentColor" stroke-width="2"/>
					<path d="M6.5 17c1.2-2.4 3.6-3.5 5.5-3.5S16.8 14.6 18 17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				</svg>';

			case 'qrcode': // check-in con QR
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 14h2v2h-2zM16 18h2v2h-2zM20 18h2v2h-2z"/>
				</svg>';

			case 'calendar-check': // control de acceso a sesión
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<rect x="3" y="5" width="18" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>
					<path d="M7 3v4M17 3v4M3 9h18M9 15l2 2 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>';

			case 'id-badge': // check-in manual & escarapela
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<rect x="6" y="5" width="12" height="16" rx="2"/>
					<rect x="10" y="2" width="4" height="3" rx="1"/>
					<circle cx="12" cy="11" r="2.5"/>
					<path d="M9 16h6a.8.8 0 0 1 .8.8V18H8.2v-1.2A.8.8 0 0 1 9 16Z"/>
				</svg>';

			case 'check-double': // validador de localidad
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M3 13l3 3 5-6M12 13l3 3 6-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>';

			case 'checklist': // checklist
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<rect x="4" y="4" width="16" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>
					<path d="M8 8h8M8 12h8M8 16h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					<path d="M5 5l2 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				</svg>';

			case 'ticket': // edición de tickets (ticket)
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M5 9V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
					<rect x="9" y="9" width="6" height="6" rx="1" fill="none" stroke="currentColor" stroke-width="2"/>
				</svg>';

			case 'trophy': // Ranking Networking
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M4 5h16v2a5 5 0 0 1-5 5h-6a5 5 0 0 1-5-5V5Z" fill="none" stroke="currentColor" stroke-width="2"/>
					<path d="M9 12v2a3 3 0 0 0 6 0v-2M8 21h8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				</svg>';

			case 'shield-check': // NUEVO: Doble Autenticación
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true">
					<path d="M12 2L4 6v6c0 5.5 3.8 10.7 8 12 4.2-1.3 8-6.5 8-12V6l-8-4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
					<path d="M9 12l2 2 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
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
 * Shortcode principal del dashboard.
 * Uso: [eventosapp_dashboard]
 */
add_shortcode('eventosapp_dashboard', function(){
	if ( ! is_user_logged_in() ) {
		$login = wp_login_url( get_permalink() );
		return '<p>Debes iniciar sesión. <a href="'.esc_url($login).'">Iniciar sesión</a></p>';
	}

	if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('dashboard') ) {
		return '<p>No tienes permisos para ver este panel.</p>';
	}

	// Cambio de evento por GET
	if ( isset($_GET['evapp']) && $_GET['evapp'] === 'change_event' ) {
		if (function_exists('eventosapp_clear_active_event')) {
			eventosapp_clear_active_event();
		}
	}

	// Mensajes
	$msg = '';
	if ( isset($_GET['evapp_err']) && $_GET['evapp_err'] !== '' ) {
		$msg = '<div class="notice notice-error" style="padding:8px;margin:10px 0;"><strong>Error:</strong> '
			. esc_html( rawurldecode( wp_unslash($_GET['evapp_err']) ) ) . '</div>';
	} elseif ( isset($_GET['set']) && $_GET['set'] === '1' ) {
		$msg = '<div class="notice notice-success" style="padding:8px;margin:10px 0;">Evento activado.</div>';
	}

	ob_start();
	echo '<div class="evapp-dashboard">';

	$active_event = function_exists('eventosapp_get_active_event') ? eventosapp_get_active_event() : 0;

	if ( $active_event ) {
		// Estilos una sola vez
		eventosapp_print_dashboard_css();
		if ( $msg ) echo $msg;

		// Barra "Evento activo"
		if (function_exists('eventosapp_active_event_bar')) {
			eventosapp_active_event_bar();
		}

		// URLs desde Configuración
		$url_metrics       = function_exists('eventosapp_get_metrics_url')               ? eventosapp_get_metrics_url()               : '#';
		$url_search        = function_exists('eventosapp_get_search_url')                ? eventosapp_get_search_url()                : '#';
		$url_register      = function_exists('eventosapp_get_register_url')              ? eventosapp_get_register_url()              : '#';
		$url_qr            = function_exists('eventosapp_get_qr_url')                    ? eventosapp_get_qr_url()                    : '#';
		$url_edit          = function_exists('eventosapp_get_edit_url')                  ? eventosapp_get_edit_url()                  : '#';
		$url_qr_localidad  = function_exists('eventosapp_get_qr_localidad_url')          ? eventosapp_get_qr_localidad_url()          : '#';
		$url_qr_sesion     = function_exists('eventosapp_get_qr_sesion_url')             ? eventosapp_get_qr_sesion_url()             : '#';
		$url_checklist     = function_exists('eventosapp_get_checklist_url')             ? eventosapp_get_checklist_url()             : '#';
		$url_net_ranking   = function_exists('eventosapp_get_networking_ranking_url')    ? eventosapp_get_networking_ranking_url()    : '#';
		$url_qr_double_auth = function_exists('eventosapp_get_qr_double_auth_url')       ? eventosapp_get_qr_double_auth_url()        : '#'; // NUEVO

		?>
		<div class="evapp-grid" role="navigation" aria-label="Panel de acciones del evento">
			<?php if (eventosapp_role_can('metrics')): ?>
				<a class="evapp-card" href="<?php echo esc_url($url_metrics); ?>" aria-label="Métricas">
					<?php echo eventosapp_dashboard_icon('metrics'); ?>
					<span class="evapp-title">Métricas</span>
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

		</div>
		<?php

	} else {
		// ==== No hay evento elegido: selector (igual que antes) ====
		if ( $msg ) echo $msg;

		$query_args = [
			'post_type'      => 'eventosapp_event',
			'posts_per_page' => 300,
			'post_status'    => ['publish'],
			'orderby'        => 'title',
			'order'          => 'ASC',
		];
		$all_events = get_posts($query_args);

		$events = array_values(array_filter($all_events, function($ev){
			if ( function_exists('eventosapp_user_can_manage_event') ) {
				return eventosapp_user_can_manage_event($ev->ID);
			}
			return current_user_can('manage_options') || (int)$ev->post_author === get_current_user_id();
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
