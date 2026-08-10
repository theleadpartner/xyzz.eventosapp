<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Identidad corporativa y shell visual de EventosApp.
 *
 * Esta capa centraliza:
 * - activos y colores corporativos;
 * - detección de páginas gestionadas por EventosApp;
 * - template "app" sin header/footer del tema;
 * - encabezado común con marca, sesión y acciones;
 * - preferencia Light/Dark persistente por usuario;
 * - estilos corporativos compartidos por todos los módulos.
 *
 * No modifica la lógica operativa de los módulos, permisos, shortcodes,
 * autenticación, selección de evento ni acciones existentes.
 */

if ( ! function_exists( 'eventosapp_brand_colors' ) ) {
    function eventosapp_brand_colors() {
        return [
            'dark'      => '#171e37',
            'blue'      => '#3683c5',
            'blue_dark' => '#286291',
        ];
    }
}

if ( ! function_exists( 'eventosapp_brand_asset_url' ) ) {
    function eventosapp_brand_asset_url( $variant = 'logo_color' ) {
        $assets = [
            'logo_color' => 'eventosapp_color.svg',
            'logo_white' => 'eventosapp_blanco.svg',
            'icon_color' => 'eventosapp_icon.svg',
            'icon_white' => 'eventosapp_icon_blanco.svg',
        ];

        $variant = sanitize_key( (string) $variant );
        if ( ! isset( $assets[ $variant ] ) ) {
            $variant = 'logo_color';
        }

        return EVENTOSAPP_PLUGIN_URL . 'assets/branding/' . $assets[ $variant ];
    }
}

/**
 * Reúne únicamente IDs de páginas que EventosApp administra de forma explícita.
 *
 * Se consideran:
 * - el mapa principal `eventosapp_pages`;
 * - el mapa histórico de Networking `eventosapp_networking_pages`;
 * - Login y 404 de la configuración de Seguridad.
 */
if ( ! function_exists( 'eventosapp_branding_managed_page_ids' ) ) {
    function eventosapp_branding_managed_page_ids() {
        static $ids = null;
        if ( is_array( $ids ) ) return $ids;

        $ids = [];

        $pages = get_option( 'eventosapp_pages', [] );
        if ( is_array( $pages ) ) {
            foreach ( $pages as $page_id ) {
                $page_id = absint( $page_id );
                if ( $page_id ) $ids[] = $page_id;
            }
        }

        $networking = get_option( 'eventosapp_networking_pages', [] );
        if ( is_array( $networking ) ) {
            $walker = static function( $value ) use ( &$walker, &$ids ) {
                if ( is_array( $value ) ) {
                    foreach ( $value as $nested ) $walker( $nested );
                    return;
                }
                if ( is_numeric( $value ) ) {
                    $page_id = absint( $value );
                    if ( $page_id ) $ids[] = $page_id;
                }
            };
            $walker( $networking );
        }

        $security = get_option( 'eventosapp_security', [] );
        if ( is_array( $security ) ) {
            foreach ( [ 'login_page_id', 'error_404_page_id' ] as $key ) {
                $page_id = absint( $security[ $key ] ?? 0 );
                if ( $page_id ) $ids[] = $page_id;
            }
        }

        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
        return $ids;
    }
}

if ( ! function_exists( 'eventosapp_branding_is_managed_page' ) ) {
    function eventosapp_branding_is_managed_page() {
        if ( is_admin() || ! is_singular( 'page' ) ) return false;

        $page_id = absint( get_queried_object_id() );
        if ( ! $page_id ) return false;

        return in_array( $page_id, eventosapp_branding_managed_page_ids(), true );
    }
}

if ( ! function_exists( 'eventosapp_branding_dashboard_page_id' ) ) {
    function eventosapp_branding_dashboard_page_id() {
        $pages = get_option( 'eventosapp_pages', [] );
        return is_array( $pages ) ? absint( $pages['dashboard_page_id'] ?? 0 ) : 0;
    }
}

if ( ! function_exists( 'eventosapp_branding_is_dashboard_page' ) ) {
    function eventosapp_branding_is_dashboard_page() {
        $dashboard_id = eventosapp_branding_dashboard_page_id();
        return $dashboard_id && absint( get_queried_object_id() ) === $dashboard_id;
    }
}

/**
 * Determina las vistas donde no deben exponerse datos de la sesión operativa.
 * Conserva las exclusiones históricas de Kiosko y Sorteo Público e incluye 404.
 */
if ( ! function_exists( 'eventosapp_branding_show_account_controls' ) ) {
    function eventosapp_branding_show_account_controls() {
        if ( ! is_user_logged_in() ) return false;

        $page_id = absint( get_queried_object_id() );
        if ( ! $page_id ) return false;

        $pages = get_option( 'eventosapp_pages', [] );
        $pages = is_array( $pages ) ? $pages : [];
        $security = get_option( 'eventosapp_security', [] );
        $security = is_array( $security ) ? $security : [];

        $excluded = [
            absint( $pages['self_checkin_page_id'] ?? 0 ),
            absint( $pages['live_raffle_public_page_id'] ?? 0 ),
            absint( $security['error_404_page_id'] ?? 0 ),
        ];

        return ! in_array( $page_id, array_filter( $excluded ), true );
    }
}

if ( ! function_exists( 'eventosapp_branding_css_contents' ) ) {
    function eventosapp_branding_css_contents() {
        static $css = null;

        if ( $css !== null ) return $css;

        $file = EVENTOSAPP_PLUGIN_PATH . 'assets/css/eventosapp-branding.css';
        if ( ! is_readable( $file ) ) {
            $css = '';
            return $css;
        }

        $contents = file_get_contents( $file );
        if ( ! is_string( $contents ) ) {
            $css = '';
            return $css;
        }

        // La hoja se imprime inline al final del documento. Convertimos las
        // rutas relativas de los SVG a URLs absolutas del plugin para que la
        // resolución no dependa de la URL de la página actual.
        $branding_url = EVENTOSAPP_PLUGIN_URL . 'assets/branding/';
        $css = str_replace( '../branding/', $branding_url, $contents );
        return $css;
    }
}

if ( ! function_exists( 'eventosapp_branding_is_admin_screen' ) ) {
    function eventosapp_branding_is_admin_screen() {
        if ( ! is_admin() ) return false;

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( $page !== '' && strpos( $page, 'eventosapp' ) !== false ) return true;

        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        if ( $post_type !== '' && strpos( $post_type, 'eventosapp' ) === 0 ) return true;

        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen ) {
                foreach ( [ $screen->id ?? '', $screen->base ?? '', $screen->post_type ?? '' ] as $screen_key ) {
                    if ( is_string( $screen_key ) && strpos( $screen_key, 'eventosapp' ) !== false ) return true;
                }
            }
        }

        return false;
    }
}

if ( ! function_exists( 'eventosapp_branding_front_body_class' ) ) {
    function eventosapp_branding_front_body_class( $classes ) {
        $classes = is_array( $classes ) ? $classes : [];
        if ( eventosapp_branding_is_managed_page() ) {
            $classes[] = 'eventosapp-branding';
            $classes[] = 'eventosapp-app-page';
        }
        return array_values( array_unique( $classes ) );
    }
}
add_filter( 'body_class', 'eventosapp_branding_front_body_class', 20 );

if ( ! function_exists( 'eventosapp_branding_admin_body_class' ) ) {
    function eventosapp_branding_admin_body_class( $classes ) {
        $classes = trim( (string) $classes );
        if ( eventosapp_branding_is_admin_screen() && strpos( ' ' . $classes . ' ', ' eventosapp-branding ' ) === false ) {
            $classes .= ( $classes === '' ? '' : ' ' ) . 'eventosapp-branding';
        }
        return $classes;
    }
}
add_filter( 'admin_body_class', 'eventosapp_branding_admin_body_class', 20 );

/**
 * Preferencia visual persistente por usuario.
 */
if ( ! function_exists( 'eventosapp_branding_user_theme' ) ) {
    function eventosapp_branding_user_theme( $user_id = 0 ) {
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        if ( ! $user_id ) return '';

        $theme = sanitize_key( (string) get_user_meta( $user_id, '_eventosapp_ui_theme', true ) );
        return in_array( $theme, [ 'light', 'dark' ], true ) ? $theme : '';
    }
}

if ( ! function_exists( 'eventosapp_branding_save_theme_preference' ) ) {
    function eventosapp_branding_save_theme_preference() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Sesión requerida.' ], 401 );
        }

        check_ajax_referer( 'eventosapp_theme_preference', 'nonce' );

        $theme = isset( $_POST['theme'] ) ? sanitize_key( wp_unslash( $_POST['theme'] ) ) : '';
        if ( ! in_array( $theme, [ 'light', 'dark' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Tema inválido.' ], 400 );
        }

        update_user_meta( get_current_user_id(), '_eventosapp_ui_theme', $theme );
        wp_send_json_success( [ 'theme' => $theme ] );
    }
}
add_action( 'wp_ajax_eventosapp_save_theme_preference', 'eventosapp_branding_save_theme_preference' );

/**
 * Aplica la preferencia antes de pintar la UI para reducir el flash entre temas.
 * El valor de usuario tiene prioridad; si aún no existe, se usa localStorage.
 */
if ( ! function_exists( 'eventosapp_branding_print_theme_bootstrap' ) ) {
    function eventosapp_branding_print_theme_bootstrap() {
        if ( ! eventosapp_branding_is_managed_page() ) return;

        $user_id = get_current_user_id();
        $stored  = eventosapp_branding_user_theme( $user_id );
        $key     = $user_id ? 'eventosapp_ui_theme_u_' . $user_id : 'eventosapp_ui_theme_guest';
        ?>
        <script id="eventosapp-theme-bootstrap">
        (function(){
            try {
                var serverTheme = <?php echo wp_json_encode( $stored ); ?>;
                var storageKey = <?php echo wp_json_encode( $key ); ?>;
                var localTheme = window.localStorage ? window.localStorage.getItem(storageKey) : '';
                var theme = serverTheme || ((localTheme === 'dark' || localTheme === 'light') ? localTheme : 'light');
                document.documentElement.setAttribute('data-evapp-theme', theme);
                document.documentElement.style.colorScheme = theme;
            } catch (e) {
                document.documentElement.setAttribute('data-evapp-theme', 'light');
            }
        })();
        </script>
        <?php
    }
}
add_action( 'wp_head', 'eventosapp_branding_print_theme_bootstrap', 1 );

/**
 * Header único de la aplicación. Sustituye visualmente el header del tema en
 * páginas mapeadas y concentra marca, modo visual y acciones de sesión.
 */
if ( ! function_exists( 'eventosapp_branding_render_app_header' ) ) {
    function eventosapp_branding_render_app_header() {
        if ( ! eventosapp_branding_is_managed_page() ) return;

        $dashboard_url = function_exists( 'eventosapp_get_dashboard_url' ) ? eventosapp_get_dashboard_url() : home_url( '/' );
        $show_account  = eventosapp_branding_show_account_controls();
        $is_dashboard  = eventosapp_branding_is_dashboard_page();
        $stored_theme  = eventosapp_branding_user_theme();
        $stored_theme  = $stored_theme ?: 'light';
        $user          = $show_account ? wp_get_current_user() : null;

        $logout_url = function_exists( 'eventosapp_session_ui_action_url' )
            ? eventosapp_session_ui_action_url( 'logout' )
            : wp_logout_url( $dashboard_url );
        $admin_url = function_exists( 'eventosapp_session_ui_action_url' )
            ? eventosapp_session_ui_action_url( 'admin' )
            : admin_url();
        ?>
        <header class="evapp-app-chrome" data-evapp-app-chrome>
            <div class="evapp-app-chrome-inner">
                <a class="evapp-app-brand" href="<?php echo esc_url( $dashboard_url ); ?>" aria-label="EventosApp">
                    <img src="<?php echo esc_url( eventosapp_brand_asset_url( 'logo_white' ) ); ?>" alt="EventosApp" width="214" height="54" decoding="async">
                </a>

                <div class="evapp-app-chrome-actions">
                    <?php if ( $show_account && $user instanceof WP_User ) : ?>
                        <div class="evapp-app-user" aria-label="Usuario activo">
                            <span class="evapp-app-user-icon" aria-hidden="true"></span>
                            <span class="evapp-app-user-copy">
                                <span class="evapp-app-user-label">Usuario activo</span>
                                <strong><?php echo esc_html( $user->display_name ); ?></strong>
                                <small>@<?php echo esc_html( $user->user_login ); ?></small>
                            </span>
                        </div>
                    <?php endif; ?>

                    <button
                        type="button"
                        class="evapp-app-action evapp-theme-toggle"
                        data-evapp-theme-toggle
                        aria-pressed="<?php echo $stored_theme === 'dark' ? 'true' : 'false'; ?>"
                        aria-label="Cambiar modo visual"
                    >
                        <svg class="evapp-theme-icon is-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.2 15.5A8.5 8.5 0 0 1 8.5 3.8 8.7 8.7 0 1 0 20.2 15.5Z"/></svg>
                        <svg class="evapp-theme-icon is-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                        <span data-evapp-theme-label><?php echo $stored_theme === 'dark' ? 'Modo claro' : 'Modo oscuro'; ?></span>
                    </button>

                    <?php if ( $show_account ) : ?>
                        <?php if ( ! $is_dashboard ) : ?>
                            <a class="evapp-app-action" href="<?php echo esc_url( $dashboard_url ); ?>">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/></svg>
                                <span>Dashboard</span>
                            </a>
                        <?php endif; ?>

                        <?php if ( current_user_can( 'manage_options' ) ) : ?>
                            <a class="evapp-app-action is-admin" href="<?php echo esc_url( $admin_url ); ?>">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 4.8 3.2 8.3 8 9 4.8-.7 8-4.2 8-9V7l-8-4Z"/><path d="M9 12h6M12 9v6"/></svg>
                                <span>Administración</span>
                            </a>
                        <?php endif; ?>

                        <a class="evapp-app-action is-logout" href="<?php echo esc_url( $logout_url ); ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5M13 8l4 4-4 4M8 12h9"/></svg>
                            <span>Cerrar sesión</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </header>
        <?php
    }
}

/**
 * JS del selector Light/Dark. Guarda inmediatamente en localStorage y, para
 * usuarios autenticados, sincroniza además con user_meta mediante AJAX.
 */
if ( ! function_exists( 'eventosapp_branding_print_theme_script' ) ) {
    function eventosapp_branding_print_theme_script() {
        if ( ! eventosapp_branding_is_managed_page() ) return;

        $user_id = get_current_user_id();
        $key     = $user_id ? 'eventosapp_ui_theme_u_' . $user_id : 'eventosapp_ui_theme_guest';
        $ajax    = admin_url( 'admin-ajax.php' );
        $nonce   = $user_id ? wp_create_nonce( 'eventosapp_theme_preference' ) : '';
        ?>
        <script id="eventosapp-theme-controller">
        (function(){
            var button = document.querySelector('[data-evapp-theme-toggle]');
            if (!button) return;

            var label = button.querySelector('[data-evapp-theme-label]');
            var storageKey = <?php echo wp_json_encode( $key ); ?>;
            var ajaxUrl = <?php echo wp_json_encode( $ajax ); ?>;
            var nonce = <?php echo wp_json_encode( $nonce ); ?>;
            var isAuthenticated = <?php echo $user_id ? 'true' : 'false'; ?>;

            function currentTheme(){
                return document.documentElement.getAttribute('data-evapp-theme') === 'dark' ? 'dark' : 'light';
            }

            function syncButton(theme){
                var dark = theme === 'dark';
                button.setAttribute('aria-pressed', dark ? 'true' : 'false');
                if (label) label.textContent = dark ? 'Modo claro' : 'Modo oscuro';
            }

            function applyTheme(theme, persist){
                if (theme !== 'dark' && theme !== 'light') theme = 'light';
                document.documentElement.setAttribute('data-evapp-theme', theme);
                document.documentElement.style.colorScheme = theme;
                syncButton(theme);

                try {
                    if (window.localStorage) window.localStorage.setItem(storageKey, theme);
                } catch (e) {}

                if (persist && isAuthenticated && nonce && window.fetch) {
                    var body = new URLSearchParams();
                    body.set('action', 'eventosapp_save_theme_preference');
                    body.set('nonce', nonce);
                    body.set('theme', theme);
                    window.fetch(ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: body.toString()
                    }).catch(function(){});
                }
            }

            syncButton(currentTheme());
            button.addEventListener('click', function(){
                applyTheme(currentTheme() === 'dark' ? 'light' : 'dark', true);
            });
        })();
        </script>
        <?php
    }
}
add_action( 'wp_footer', 'eventosapp_branding_print_theme_script', 998 );

/**
 * Template de aplicación: mantiene wp_head/wp_footer para que Elementor y los
 * módulos carguen sus assets, pero elimina por construcción header/footer del tema.
 */
if ( ! function_exists( 'eventosapp_branding_app_template' ) ) {
    function eventosapp_branding_app_template( $template ) {
        if ( ! eventosapp_branding_is_managed_page() ) return $template;
        if ( is_feed() || wp_doing_ajax() ) return $template;

        $app_template = EVENTOSAPP_PLUGIN_PATH . 'templates/eventosapp-app-page.php';
        return is_readable( $app_template ) ? $app_template : $template;
    }
}
add_filter( 'template_include', 'eventosapp_branding_app_template', 9999 );

if ( ! function_exists( 'eventosapp_print_branding_styles' ) ) {
    function eventosapp_print_branding_styles() {
        static $printed = false;
        if ( $printed ) return;

        if ( is_admin() ) {
            if ( ! eventosapp_branding_is_admin_screen() ) return;
        } elseif ( ! eventosapp_branding_is_managed_page() ) {
            return;
        }

        $css = eventosapp_branding_css_contents();
        if ( $css === '' ) return;

        $printed = true;
        echo "\n<style id=\"eventosapp-corporate-branding\">\n";
        echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS estático interno del plugin.
        echo "\n</style>\n";
    }
}

// Se imprime al final para sobreescribir únicamente defaults visuales históricos.
// Los selectores explícitos de Elementor mantienen mayor especificidad.
add_action( 'wp_footer', 'eventosapp_print_branding_styles', 999 );
add_action( 'admin_footer', 'eventosapp_print_branding_styles', 999 );
