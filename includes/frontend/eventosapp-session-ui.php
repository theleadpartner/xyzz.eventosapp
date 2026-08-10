<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sesión / acceso integrado en la UI frontend de EventosApp.
 *
 * Esta capa complementa EventosApp_Security y resuelve dos contextos de render:
 * 1) shortcodes tradicionales;
 * 2) widgets de Elementor que llaman directamente a los renderizadores PHP.
 *
 * No modifica permisos, autenticación, auditoría ni hardening.
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

        // No exponer la sesión operativa en kiosko ni en pantallas públicas.
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
 * Mantiene los enlaces frontend dentro de la identidad de EventosApp.
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
 * Procesa el formulario cuando [custom_login_basic] está renderizado dentro del
 * Dashboard aunque el shortcode no exista físicamente en el contenido de la página.
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
 * Carga reCAPTCHA también cuando el login aparece de forma dinámica en Dashboard.
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

if ( ! function_exists( 'eventosapp_session_ui_panel_styles' ) ) {
    function eventosapp_session_ui_panel_styles() {
        return '<style id="eventosapp-session-panel-css">
.evapp-session-panel{--esp-primary:#3279bd;--esp-primary-dark:#255f96;--esp-text:#182230;--esp-muted:#64748b;--esp-border:#dfe7f1;display:flex;align-items:center;justify-content:space-between;gap:14px;width:100%;margin:0 0 18px;padding:11px 12px;background:#fff;border:1px solid var(--esp-border);border-radius:14px;box-shadow:0 7px 22px rgba(29,62,94,.055);font-family:inherit;color:var(--esp-text);box-sizing:border-box}.evapp-session-panel *{box-sizing:border-box}.evapp-session-panel-user{display:flex;align-items:center;gap:10px;min-width:0}.evapp-session-panel-avatar{width:38px;height:38px;border-radius:12px;background:#eaf4ff;color:var(--esp-primary);display:grid;place-items:center;flex:0 0 auto}.evapp-session-panel-avatar svg{width:21px;height:21px;fill:none;stroke:currentColor;stroke-width:2}.evapp-session-panel-copy{display:block;min-width:0;line-height:1.12}.evapp-session-panel-label{display:block;margin-bottom:3px;color:var(--esp-muted);font-size:9px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.evapp-session-panel-copy strong,.evapp-session-panel-copy small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.evapp-session-panel-copy strong{font-size:13px;color:var(--esp-text)}.evapp-session-panel-copy small{margin-top:3px;font-size:10px;color:var(--esp-muted)}.evapp-session-panel-actions{display:flex;align-items:center;justify-content:flex-end;gap:7px;flex-wrap:wrap}.evapp-session-panel-action{min-height:36px;padding:7px 11px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid #d8e4ef;background:#f8fbff;color:var(--esp-primary-dark)!important;text-decoration:none!important;font-size:11px;font-weight:800;white-space:nowrap;transition:background .18s ease,border-color .18s ease,transform .18s ease}.evapp-session-panel-action svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.evapp-session-panel-action:hover,.evapp-session-panel-action:focus{background:#edf5fc;border-color:#bfd6ea;color:#174c78!important}.evapp-session-panel-action.is-admin{background:#182230;border-color:#182230;color:#fff!important}.evapp-session-panel-action.is-admin:hover,.evapp-session-panel-action.is-admin:focus{background:#263548;border-color:#263548;color:#fff!important}.evapp-session-panel-action.is-logout{background:#fff5f6;border-color:#ffd9df;color:#9f1239!important}.evapp-session-panel-action.is-logout:hover,.evapp-session-panel-action.is-logout:focus{background:#ffe9ed;border-color:#ffc8d2;color:#881337!important}
@media(max-width:680px){.evapp-session-panel{align-items:stretch;flex-direction:column;margin-bottom:14px}.evapp-session-panel-actions{display:grid;width:100%;grid-template-columns:repeat(var(--evapp-session-actions,2),minmax(0,1fr))}.evapp-session-panel-action{width:100%;padding:8px 7px}.evapp-session-panel-copy strong{font-size:13px}}
@media(max-width:390px){.evapp-session-panel-actions{grid-template-columns:1fr}.evapp-session-panel-action{min-height:39px}}
</style>';
    }
}

if ( ! function_exists( 'eventosapp_session_ui_panel_html' ) ) {
    function eventosapp_session_ui_panel_html( $context = 'module', $include_styles = true ) {
        if ( ! is_user_logged_in() ) return '';

        $context      = sanitize_key( (string) $context );
        $user         = wp_get_current_user();
        $dashboard    = function_exists( 'eventosapp_get_dashboard_url' ) ? eventosapp_get_dashboard_url() : home_url( '/' );
        $show_dashboard = $context !== 'dashboard';
        $show_admin   = current_user_can( 'manage_options' );
        $logout_url   = eventosapp_session_ui_action_url( 'logout' );
        $admin_url    = eventosapp_session_ui_action_url( 'admin' );
        $actions      = 1 + ( $show_dashboard ? 1 : 0 ) + ( $show_admin ? 1 : 0 );

        ob_start();
        if ( $include_styles ) echo eventosapp_session_ui_panel_styles();
        ?>
        <section class="evapp-session-panel" data-evapp-session-panel aria-label="Cuenta y sesión de EventosApp" style="--evapp-session-actions:<?php echo esc_attr( $actions ); ?>">
            <div class="evapp-session-panel-user">
                <span class="evapp-session-panel-avatar" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"/><path d="M5 20c.8-4 3.2-6 7-6s6.2 2 7 6"/></svg>
                </span>
                <span class="evapp-session-panel-copy">
                    <span class="evapp-session-panel-label">Usuario activo</span>
                    <strong><?php echo esc_html( $user->display_name ); ?></strong>
                    <small>@<?php echo esc_html( $user->user_login ); ?></small>
                </span>
            </div>
            <div class="evapp-session-panel-actions">
                <?php if ( $show_dashboard ) : ?>
                    <a class="evapp-session-panel-action" href="<?php echo esc_url( $dashboard ); ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/></svg>
                        Dashboard
                    </a>
                <?php endif; ?>
                <?php if ( $show_admin ) : ?>
                    <a class="evapp-session-panel-action is-admin" href="<?php echo esc_url( $admin_url ); ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 4.8 3.2 8.3 8 9 4.8-.7 8-4.2 8-9V7l-8-4Z"/><path d="M9 12h6M12 9v6"/></svg>
                        Administración
                    </a>
                <?php endif; ?>
                <a class="evapp-session-panel-action is-logout" href="<?php echo esc_url( $logout_url ); ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5M13 8l4 4-4 4M8 12h9"/></svg>
                    Cerrar sesión
                </a>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }
}

if ( ! function_exists( 'eventosapp_session_ui_login_html' ) ) {
    function eventosapp_session_ui_login_html() {
        if ( is_user_logged_in() ) return '';
        if ( shortcode_exists( 'custom_login_basic' ) ) {
            return '<div class="evapp-dashboard-login" data-evapp-dashboard-login>' . do_shortcode( '[custom_login_basic]' ) . '</div>';
        }
        return '<div class="evapp-dashboard-login"><p>No fue posible cargar el formulario de acceso.</p></div>';
    }
}

/**
 * Inserta el panel dentro del shell visual del módulo, preferentemente después
 * de su encabezado. Esto evita que quede por fuera del card principal.
 */
if ( ! function_exists( 'eventosapp_session_ui_inject_panel_into_markup' ) ) {
    function eventosapp_session_ui_inject_panel_into_markup( $markup, $context = 'module' ) {
        $markup = (string) $markup;
        if ( $markup === '' || ! is_user_logged_in() || strpos( $markup, 'data-evapp-session-panel' ) !== false ) return $markup;

        $panel = eventosapp_session_ui_panel_html( $context, true );
        if ( $panel === '' ) return $markup;

        if ( preg_match( '/<div\b[^>]*class=["\'][^"\']*\bevapp-[a-z0-9_-]*shell\b[^"\']*["\'][^>]*>/i', $markup, $shell_match, PREG_OFFSET_CAPTURE ) ) {
            $shell_tag    = $shell_match[0][0];
            $shell_offset = (int) $shell_match[0][1];
            $after_shell  = $shell_offset + strlen( $shell_tag );

            // Si el shell tiene un header cercano, la cuenta queda justo debajo.
            $header_close = stripos( $markup, '</header>', $after_shell );
            if ( $header_close !== false && ( $header_close - $after_shell ) < 9000 ) {
                $insert_at = $header_close + strlen( '</header>' );
                return substr( $markup, 0, $insert_at ) . $panel . substr( $markup, $insert_at );
            }

            return substr( $markup, 0, $after_shell ) . $panel . substr( $markup, $after_shell );
        }

        return $panel . $markup;
    }
}

if ( ! function_exists( 'eventosapp_session_ui_filter_dashboard_output' ) ) {
    function eventosapp_session_ui_filter_dashboard_output( $output ) {
        if ( ! is_user_logged_in() ) return eventosapp_session_ui_login_html();
        return eventosapp_session_ui_inject_panel_into_markup( $output, 'dashboard' );
    }
}

/**
 * Shortcode Dashboard: cubre páginas creadas con [eventosapp_dashboard].
 */
add_filter( 'do_shortcode_tag', static function( $output, $tag, $attr, $m ) {
    if ( $tag !== 'eventosapp_dashboard' ) return $output;
    return eventosapp_session_ui_filter_dashboard_output( $output );
}, 95, 4 );

/**
 * Elementor: sus widgets llaman directamente a eventosapp_render_*(), por lo
 * que do_shortcode_tag/the_content no siempre reciben el HTML final. Este filtro
 * actúa sobre el contenido final del widget y es la vía principal para las UIs
 * mostradas desde Elementor.
 */
add_filter( 'elementor/widget/render_content', static function( $content, $widget ) {
    if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) ) return $content;

    $name = sanitize_key( (string) $widget->get_name() );
    if ( $name === 'eventosapp_dashboard' ) {
        return eventosapp_session_ui_filter_dashboard_output( $content );
    }

    if ( ! is_user_logged_in() || ! eventosapp_session_ui_is_managed_page() ) return $content;
    if ( strpos( $name, 'eventosapp_' ) !== 0 ) return $content;

    return eventosapp_session_ui_inject_panel_into_markup( $content, 'module' );
}, 99, 2 );

/**
 * Fallback para páginas tradicionales/shortcodes fuera de Elementor.
 */
add_filter( 'the_content', static function( $content ) {
    if ( is_admin() || ! in_the_loop() || ! is_main_query() ) return $content;

    if ( eventosapp_session_ui_is_dashboard_page() && ! is_user_logged_in() ) {
        return eventosapp_session_ui_login_html();
    }

    if ( ! is_user_logged_in() || eventosapp_session_ui_is_dashboard_page() || ! eventosapp_session_ui_is_managed_page() ) return $content;
    return eventosapp_session_ui_inject_panel_into_markup( $content, 'module' );
}, 99 );

/**
 * Última red de seguridad para módulos Elementor/custom render que no pasen por
 * los filtros anteriores. El panel se monta DENTRO del shell visual más grande,
 * nunca como elemento fijo/flotante.
 */
add_action( 'wp_footer', static function() {
    if ( is_admin() || ! is_user_logged_in() || eventosapp_session_ui_is_dashboard_page() || ! eventosapp_session_ui_is_managed_page() ) return;

    $panel = eventosapp_session_ui_panel_html( 'module', false );
    if ( $panel === '' ) return;

    echo eventosapp_session_ui_panel_styles();
    ?>
    <template id="evapp-session-panel-template"><?php echo $panel; ?></template>
    <script id="eventosapp-session-panel-mount">
    (function(){
        function visible(el){
            if(!el) return false;
            var r=el.getBoundingClientRect();
            var s=window.getComputedStyle(el);
            return r.width>220 && r.height>80 && s.display!=='none' && s.visibility!=='hidden';
        }
        function mount(){
            if(document.querySelector('[data-evapp-session-panel]')) return true;
            var tpl=document.getElementById('evapp-session-panel-template');
            if(!tpl) return false;
            var nodes=Array.prototype.slice.call(document.querySelectorAll('[class*="evapp-"][class*="-shell"]')).filter(function(el){
                return visible(el) && !el.closest('[data-evapp-session-panel]');
            });
            if(!nodes.length) return false;
            nodes.sort(function(a,b){
                var ar=a.getBoundingClientRect(),br=b.getBoundingClientRect();
                return (br.width*br.height)-(ar.width*ar.height);
            });
            var shell=nodes[0];
            var fragment=tpl.content.cloneNode(true);
            var panel=fragment.querySelector('[data-evapp-session-panel]');
            if(!panel) return false;
            var children=Array.prototype.slice.call(shell.children);
            var header=children.find(function(el){
                return el.tagName==='HEADER' || /(^|\s)evapp-[^\s]*header(\s|$)/.test(el.className||'');
            });
            if(header && header.parentNode===shell){
                header.insertAdjacentElement('afterend',panel);
            }else{
                shell.insertBefore(panel,shell.firstChild);
            }
            tpl.remove();
            return true;
        }
        if(!mount()){
            document.addEventListener('DOMContentLoaded',mount,{once:true});
            window.setTimeout(mount,350);
        }
    })();
    </script>
    <?php
}, 92 );

/**
 * Desactiva únicamente las piezas visuales antiguas. El motor de Seguridad,
 * roles, auditoría y acceso administrativo permanece intacto.
 */
$eventosapp_session_ui_security = eventosapp_session_ui_security();
if ( $eventosapp_session_ui_security ) {
    remove_action( 'wp_footer', [ $eventosapp_session_ui_security, 'render_session_dock' ], 80 );
    remove_filter( 'eventosapp_dashboard_modules', [ $eventosapp_session_ui_security, 'extend_dashboard_modules' ], 50 );
}
