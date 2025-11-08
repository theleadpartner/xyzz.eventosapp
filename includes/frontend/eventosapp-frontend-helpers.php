<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * URL de la página de dashboard (tomada de Configuración)
 */
if ( ! function_exists('eventosapp_get_dashboard_url') ) {
    function eventosapp_get_dashboard_url() {
        if ( function_exists('eventosapp_get_configured_page_url') ) {
            return eventosapp_get_configured_page_url('dashboard_page_id', home_url('/'));
        }
        return home_url('/');
    }
}

/** Devuelve el ID del evento activo del usuario o 0 si no hay */
function eventosapp_get_active_event($user_id = 0){
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id) return 0;
    return (int) get_user_meta($user_id, '_eventosapp_active_event', true);
}

if ( ! function_exists('eventosapp_user_can_manage_event') ) {
  /**
   * ¿El usuario (o actual) puede gestionar este evento en particular?
   * Reglas:
   *  - admin siempre sí
   *  - autor del evento sí
   *  - usuario listado en _evapp_temp_authors no vencido -> sí
   *  - staff con usermeta _evapp_event_assignment para este evento no vencido -> sí
   * (La visibilidad de menús/features la gobiernan los archivos de configuración existentes)
   */
  function eventosapp_user_can_manage_event($event_id, $user_id = 0){
    $event_id = (int)$event_id;
    $user_id  = $user_id ?: get_current_user_id();
    if ( ! $event_id || ! $user_id ) return false;

    if ( user_can($user_id, 'manage_options') ) return true;

    $p = get_post($event_id);
    if ( ! $p || $p->post_type !== 'eventosapp_event' ) return false;

    if ( (int)$p->post_author === (int)$user_id ) return true;

    // co-gestores temporales por evento
    $co = get_post_meta($event_id, '_evapp_temp_authors', true);
    if (is_array($co)) {
      $now = time();
      foreach ($co as $row) {
        if (is_array($row) && (int)($row['user_id'] ?? 0) === (int)$user_id) {
          $until = (int)($row['until'] ?? 0);
          if (!$until || $until >= $now) return true;
        }
      }
    }

    // staff asignado (desde usermeta)
    $assign = get_user_meta($user_id, '_evapp_event_assignment', true);
    if (is_array($assign) && (int)($assign['event_id'] ?? 0) === $event_id) {
      $until = (int)($assign['until'] ?? 0);
      if (!$until || $until >= time()) return true;
    }

    return false;
  }
}

/**
 * Asigna el evento activo (verifica que el evento sea del usuario actual, o admin)
 * @return WP_Error|true
 */
function eventosapp_set_active_event($event_id, $user_id = 0){
    $user_id  = $user_id ?: get_current_user_id();
    $event_id = (int) $event_id;
    if (!$user_id || !$event_id) return new WP_Error('bad_request', 'Datos incompletos.');

    $post = get_post($event_id);
    if ( ! $post || $post->post_type !== 'eventosapp_event' ) {
        return new WP_Error('invalid', 'Evento inválido.');
    }

    // Permitir si es admin, autor o co-gestor/staff asignado vigente
    if ( ! eventosapp_user_can_manage_event($event_id, $user_id) ) {
        return new WP_Error('forbidden', 'No puedes gestionar este evento.');
    }

    update_user_meta($user_id, '_eventosapp_active_event', $event_id);
    return true;
}


/** Limpia el evento activo */
function eventosapp_clear_active_event($user_id = 0){
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id) return;
    delete_user_meta($user_id, '_eventosapp_active_event');
}

/**
 * Fuerza tener evento activo en vistas críticas del frontend:
 * si no hay, redirige al dashboard configurado
 */
function eventosapp_require_active_event(){
    if ( ! is_user_logged_in() ) {
        wp_redirect( wp_login_url() );
        exit;
    }
    $ev = eventosapp_get_active_event();
    if ( ! $ev ) {
        wp_redirect( eventosapp_get_dashboard_url() );
        exit;
    }
}


/**
 * Barra superior reutilizable para mostrar/cambiar el evento activo.
 * Úsala al inicio de tus páginas frontend protegidas.
 */
function eventosapp_active_event_bar(){
    if ( ! is_user_logged_in() ) return;
    $user_id = get_current_user_id();
    $active  = eventosapp_get_active_event($user_id);
    $label   = $active ? get_the_title($active) : 'Ninguno';

    // Base limpia sin flags previos
    $base = function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/');
    $base = remove_query_arg(['evapp', 'evapp_err', 'set'], $base);
    $change_url = add_query_arg(['evapp' => 'change_event'], $base);

    ?>
    <div style="background:#f6f7f7;border:1px solid #e1e1e1;padding:8px 12px;border-radius:8px;margin:10px 0;display:flex;justify-content:space-between;align-items:center;">
        <div><strong>Evento activo:</strong> <?php echo esc_html($label); ?></div>
        <div><a class="button" href="<?php echo esc_url($change_url); ?>">Cambiar evento</a></div>
    </div>
    <?php
}


/**
 * === ARREGLA EL BUG: manejar el POST ANTES de que se renderice el contenido ===
 * Procesa el submit del selector de evento y redirige con ?set=1 o ?evapp_err=...
 */
function eventosapp_handle_set_event_post(){
    if ( is_admin() ) return;
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return;
    if ( empty($_POST['evapp_action']) || $_POST['evapp_action'] !== 'set_event' ) return;

    // Seguridad básica
    if ( ! is_user_logged_in() ) {
        wp_safe_redirect( wp_login_url() );
        exit;
    }

    $err = '';
    // Valida nonce con la API estándar
    if ( ! isset($_POST['_wpnonce']) ) {
        $err = 'Solicitud inválida.';
    } else {
        // Usamos check_admin_referer para el mismo action utilizado en el formulario
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'evapp_set_event' ) ) {
            $err = 'Solicitud inválida.';
        } else {
            $selected = isset($_POST['eventosapp_event_id']) ? (int) $_POST['eventosapp_event_id'] : 0;
            $res = eventosapp_set_active_event($selected);
            if ( is_wp_error($res) ) {
                $err = $res->get_error_message();
            }
        }
    }

    // Volver a la página desde la que enviaron el formulario
    $back = wp_get_referer();
    if ( ! $back ) $back = function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/');

    // Limpia flags previos y agrega el actual
    $back = remove_query_arg(['evapp_err','set'], $back);
    if ( $err ) {
        $back = add_query_arg('evapp_err', rawurlencode($err), $back);
    } else {
        $back = add_query_arg('set', '1', $back);
    }

    wp_safe_redirect($back);
    exit;
}
add_action('template_redirect', 'eventosapp_handle_set_event_post', 1);

