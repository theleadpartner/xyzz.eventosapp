<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Correcciones de sesión / acceso del frontend de EventosApp.
 *
 * Objetivos:
 * - mostrar el login integrado directamente en el Dashboard si no hay sesión;
 * - reemplazar el control flotante por una barra integrada al flujo visual;
 * - mantener usuario activo, cierre de sesión y acceso administrativo sin
 *   exponer marcas ni rutas técnicas en la interfaz pública;
 * - conservar intacta la lógica funcional de los módulos existentes.
 */

if ( ! function_exists( 'eventosapp_session_ui_security' ) ) {
    function eventosapp_session_ui_security() {
        return ( isset( $GLOBALS['eventosapp_security'] ) && $GLOBALS['eventosapp_security'] instanceof EventosApp_Security )
            ? $GLOBALS['eventosapp_security']
            : null;
    }
}

if ( ! function_exists( 'eventosapp_session_ui_login_url' ) ) {
    function eventosapp_session_ui_login_url() {
        $security = eventosapp_session_ui_security();
        if ( $security && is_callable( [ $security, 'get_login_url' ] ) ) {
            $url = $security->get_login_url();
            if ( $url ) return $url;
        }
        return function_exists( 'eventosapp_get_dashboard_url' ) ? eventosapp_get_dashboard_url() : home_url( '/' );
    }
}

if ( ! function_exists( 'eventosapp_session_ui_dashboard_page_id' ) ) {
    function eventosapp_session_ui_dashboard_page_id() {
        if ( ! function_exists( 'eventosapp_get_pages_config' ) ) return 0;
        $cfg = eventosapp_get_pages_config();
        return is_array( $cfg ) ? absint( $cfg['dashboard_page_id'] ?? 0 ) : 0;
    }
}

if ( ! function_exists( 'eventosapp_session_ui_is_dashboard_page' ) ) {
    function eventosapp_session_ui_is_dashboard_page() {
        $dashboard_id = eventosapp_session_ui_dashboard_page_id();
        return $dashboard_id && absint( get_queried_object_id() ) === $dashboard_id;
    }
}

if ( ! function_exists( 'eventosapp_session_ui_is_managed_page' ) ) {
    function eventosapp_session_ui_is_managed_page() {
        if ( is_admin() || ! is_user_logged_in() || ! is_singular( 'page' ) ) return false;

        $page_id = absint( get_queried_object_id() );
        if ( ! $page_id || ! function_exists( 'eventosapp_get_pages_config' ) ) return false;

        $cfg = eventosapp_get_pages_config();
        if ( ! is_array( $cfg ) ) return false;

        // No exponer datos de la sesión operativa en kiosko ni pantalla pública.
        $excluded = [ 'self_checkin_page_id', 'live_raffle_public_page_id' ];

        foreach ( $cfg as $key => $configured_id ) {
            if ( in_array( $key, $excluded, true ) ) continue;
            if ( absint( $configured_id ) && absint( $configured_id ) === $page_id ) return true;
        }

        return false;
    }
}

if ( ! function_exists( 'eventosapp_session_ui_action_url' ) ) {
    function eventosapp_session_ui_action_url( $action ) {
        $action = sanitize_key( (string) $action );
        $base   = function_exists( 'eventosapp_get_dashboard_url' ) ? eventosapp_get_dashboard_url() : home_url( '/' );
        $base   = remove_query_arg( [ 'evapp_action', 'evapp_token', 'login_error', 'loggedout' ], $base );

        return add_query_arg( [
            'evapp_action' => $action,
            'evapp_token'  => wp_create_nonce( 'eventosapp_front_action_' . $action ),
        ], $base );
    }
}

if ( ! function_exists( 'eventosapp_session_ui_handle_front_action' ) ) {
    function eventosapp_session_ui_handle_front_action() {
        if ( is_admin() || empty( $_GET['evapp_action'] ) ) return;

        $action = sanitize_key( wp_unslash( $_GET['evapp_action'] ) );
        if ( ! in_array( $action, [ 'logout', 'admin' ], true ) ) return;

        $token = isset( $_GET['evapp_token'] ) ? sanitize_text_field( wp_unslash( $_GET['evapp_token'] ) ) : '';
        if ( ! $token || ! wp_verify_nonce( $token, 'eventosapp_front_action_' . $action ) ) {
            wp_safe_redirect( function_exists( 'eventosapp_get_dashboard_url' ) ? eventosapp_get_dashboard_url() : home_url( '/' ) );
            exit;
        }

        if ( $action === 'logout' ) {
            if ( is_user_logged_in() ) wp_logout();
            wp_safe_redirect( eventosapp_session_ui_login_url() );
            exit;
        }

        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            wp_safe_redirect( function_exists( 'eventosapp_get_dashboard_url' ) ? eventosapp_get_dashboard_url() : home_url( '/' ) );
            exit;
        }

        wp_safe_redirect( admin_url() );
        exit;
    }
}
add_action( 'template_redirect', 'eventosapp_session_ui_handle_front_action', -45 );

/**
 * Convierte cualquier enlace de cierre de sesión generado por la seguridad
 * integrada en una ruta limpia de EventosApp cuando se renderiza el frontend.
 */
add_filter( 'logout_url', static function( $logout_url, $redirect ) {
    if ( is_admin() ) return $logout_url;
    return eventosapp_session_ui_action_url( 'logout' );
}, 99, 2 );

if ( ! function_exists( 'eventosapp_session_ui_dashboard_login_error_location' ) ) {
    function eventosapp_session_ui_dashboard_login_error_location( $location, $status ) {
        if ( ! eventosapp_session_ui_is_dashboard_page() || empty( $_POST['custom_login_submit'] ) ) return $location;

        $error = '';
        $query = wp_parse_url( $location, PHP_URL_QUERY );
        if ( $query ) {
            parse_str( $query, $params );
            $error = isset( $params['login_error'] ) ? sanitize_key( (string) $params['login_error'] ) : '';
        }

        if ( $error === '' ) return $location;

        $dashboard = function_exists( 'eventosapp_get_dashboard_url' ) ? eventosapp_get_dashboard_url() : home_url( '/' );
        $dashboard = remove_query_arg( [ 'login_error', 'loggedout' ], $dashboard );
        return add_query_arg( 'login_error', $error, $dashboard );
    }
}

/**
 * El formulario puede renderizarse dinámicamente dentro del Dashboard aunque
 * la página no contenga físicamente [custom_login_basic]. Este handler permite
 * que el POST use exactamente el mismo motor de autenticación ya integrado.
 */
if ( ! function_exists( 'eventosapp_session_ui_process_dashboard_login' ) ) {
    function eventosapp_session_ui_process_dashboard_login() {
        if ( is_admin() || is_user_logged_in() || empty( $_POST['custom_login_submit'] ) ) return;
        if ( ! eventosapp_session_ui_is_dashboard_page() ) return;

        $security = eventosapp_session_ui_security();
        if ( $security && is_callable( [ $security, 'process_login' ] ) ) {
            add_filter( 'wp_redirect', 'eventosapp_session_ui_dashboard_login_error_location', PHP_INT_MAX, 2 );
            $security->process_login();
        }
    }
}
add_action( 'template_redirect', 'eventosapp_session_ui_process_dashboard_login', -40 );

/**
 * Carga reCAPTCHA también cuando el login se está mostrando dentro del
 * Dashboard, ya que el shortcode no existe físicamente en post_content.
 */
add_action( 'wp_enqueue_scripts', static function() {
    if ( is_admin() || is_user_logged_in() || ! eventosapp_session_ui_is_dashboard_page() ) return;

    $cfg = get_option( 'eventosapp_security', [] );
    $cfg = is_array( $cfg ) ? $cfg : [];
    $site_key   = trim( (string) ( $cfg['recaptcha_site_key'] ?? '' ) );
    $secret_key = trim( (string) ( $cfg['recaptcha_secret_key'] ?? '' ) );

    if ( $site_key !== '' && $secret_key !== '' ) {
        wp_enqueue_script( 'google-recaptcha', 'https://www.google.com/recaptcha/api.js', [], null, true );
    }
}, 25 );

if ( ! function_exists( 'eventosapp_session_ui_panel_html' ) ) {
    function eventosapp_session_ui_panel_html( $context = 'module' ) {
        if ( ! is_user_logged_in() ) return '';

        $context      = sanitize_key( (string) $context );
        $user         = wp_get_current_user();
        $dashboard    = function_exists( 'eventosapp_get_dashboard_url' ) ? eventosapp_get_dashboard_url() : home_url( '/' );
        $show_panel   = $context !== 'dashboard';
        $show_admin   = current_user_can( 'manage_options' );
        $logout_url   = eventosapp_session_ui_action_url( 'logout' );
        $admin_url    = eventosapp_session_ui_action_url( 'admin' );

        ob_start();
        ?>
        <style id="eventosapp-session-panel-css">
        .evapp-session-panel{--esp-primary:#3279bd;--esp-primary-dark:#255f96;--esp-text:#182230;--esp-muted:#64748b;--esp-border:#dfe7f1;display:flex;align-items:center;justify-content:space-between;gap:14px;width:100%;margin:0 0 22px;padding:12px 14px;background:#f8fbff;border:1px solid var(--esp-border);border-radius:16px;font-family:inherit;color:var(--esp-text);box-sizing:border-box}.evapp-session-panel *{box-sizing:border-box}.evapp-session-panel-user{display:flex;align-items:center;gap:11px;min-width:0}.evapp-session-panel-avatar{width:40px;height:40px;border-radius:13px;background:#eaf4ff;color:var(--esp-primary);display:grid;place-items:center;flex:0 0 auto}.evapp-session-panel-avatar svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:2}.evapp-session-panel-copy{display:block;min-width:0;line-height:1.15}.evapp-session-panel-copy strong,.evapp-session-panel-copy small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.evapp-session-panel-copy strong{font-size:14px;color:var(--esp-text)}.evapp-session-panel-copy small{margin-top:4px;font-size:11px;color:var(--esp-muted)}.evapp-session-panel-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}.evapp-session-panel-action{min-height:38px;padding:8px 12px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #d8e4ef;background:#fff;color:var(--esp-primary-dark)!important;text-decoration:none!important;font-size:12px;font-weight:800;white-space:nowrap;transition:background .18s ease,border-color .18s ease,transform .18s ease}.evapp-session-panel-action:hover,.evapp-session-panel-action:focus{background:#edf5fc;border-color:#bfd6ea;color:#174c78!important}.evapp-session-panel-action.is-admin{background:#182230;border-color:#182230;color:#fff!important}.evapp-session-panel-action.is-admin:hover,.evapp-session-panel-action.is-admin:focus{background:#263548;border-color:#263548;color:#fff!important}.evapp-session-panel-action.is-logout{background:#fff5f6;border-color:#ffd9df;color:#9f1239!important}.evapp-session-panel-action.is-logout:hover,.evapp-session-panel-action.is-logout:focus{background:#ffe9ed;border-color:#ffc8d2;color:#881337!important}
        @media(max-width:680px){.evapp-session-panel{align-items:stretch;flex-direction:column;padding:12px;margin-bottom:16px}.evapp-session-panel-actions{display:grid;grid-template-columns:repeat(<?php echo $show_panel && $show_admin ? '3' : ( $show_panel || $show_admin ? '2' : '1' ); ?>,minmax(0,1fr));width:100%}.evapp-session-panel-action{width:100%;padding:9px 8px}.evapp-session-panel-copy strong{font-size:13px}.evapp-session-panel-copy small{font-size:10px}}
        @media(max-width:390px){.evapp-session-panel-actions{grid-template-columns:1fr}.evapp-session-panel-action{min-height:40px}}
        </style>
        <div class="evapp-session-panel" data-evapp-session-panel aria-label="Sesión activa de EventosApp">
            <div class="evapp-session-panel-user">
                <span class="evapp-session-panel-avatar" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"/><path d="M5 20c.8-4 3.2-6 7-6s6.2 2 7 6"/></svg>
                </span>
                <span class="evapp-session-panel-copy">
                    <strong><?php echo esc_html( $user->display_name ); ?></strong>
                    <small><?php echo esc_html( $user->user_login ); ?></small>
                </span>
            </div>
            <div class="evapp-session-panel-actions">
                <?php if ( $show_panel ) : ?>
                    <a class="evapp-session-panel-action" href="<?php echo esc_url( $dashboard ); ?>">Panel</a>
                <?php endif; ?>
                <?php if ( $show_admin ) : ?>
                    <a class="evapp-session-panel-action is-admin" href="<?php echo esc_url( $admin_url ); ?>">Administración</a>
                <?php endif; ?>
                <a class="evapp-session-panel-action is-logout" href="<?php echo esc_url( $logout_url ); ?>">Cerrar sesión</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

/**
 * Dashboard:
 * - sin sesión: sustituye el mensaje/link por el formulario real;
 * - con sesión: inserta la barra de cuenta dentro de .evapp-dashboard-shell.
 */
add_filter( 'do_shortcode_tag', static function( $output, $tag, $attr, $m ) {
    if ( $tag !== 'eventosapp_dashboard' ) return $output;

    if ( ! is_user_logged_in() ) {
        if ( shortcode_exists( 'custom_login_basic' ) ) {
            return '<div class="evapp-dashboard-login" data-evapp-dashboard-login>' . do_shortcode( '[custom_login_basic]' ) . '</div>';
        }
        return $output;
    }

    $panel  = eventosapp_session_ui_panel_html( 'dashboard' );
    $needle = '<div class="evapp-dashboard-shell">';

    if ( $panel !== '' && strpos( $output, $needle ) !== false ) {
        return str_replace( $needle, $needle . $panel, $output );
    }

    return $panel . $output;
}, 95, 4 );

/**
 * Módulos configurados: agrega la misma barra en el flujo normal del contenido,
 * nunca como elemento fijo o superpuesto.
 */
add_filter( 'the_content', static function( $content ) {
    if ( ! is_user_logged_in() || eventosapp_session_ui_is_dashboard_page() || ! eventosapp_session_ui_is_managed_page() ) return $content;
    if ( ! in_the_loop() || ! is_main_query() ) return $content;
    if ( strpos( $content, 'data-evapp-session-panel' ) !== false ) return $content;

    return eventosapp_session_ui_panel_html( 'module' ) . $content;
}, 7 );

/**
 * Desactiva únicamente las dos piezas visuales de la implementación anterior:
 * el dock flotante y las tarjetas de cuenta del Dashboard. El motor de seguridad,
 * roles, auditoría y acceso administrativo permanece intacto.
 */
$eventosapp_session_ui_security = eventosapp_session_ui_security();
if ( $eventosapp_session_ui_security ) {
    remove_action( 'wp_footer', [ $eventosapp_session_ui_security, 'render_session_dock' ], 80 );
    remove_filter( 'eventosapp_dashboard_modules', [ $eventosapp_session_ui_security, 'extend_dashboard_modules' ], 50 );
}
