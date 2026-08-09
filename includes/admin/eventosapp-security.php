<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Seguridad integrada de EventosApp.
 *
 * Sustituye las funciones históricas del plugin "Custom Login Basic" y agrega
 * hardening de WordPress, auditoría, diagnóstico antimalware y protecciones
 * de abuso sin introducir un rate-limit global que pueda afectar operaciones
 * masivas de check-in desde una misma red/NAT durante un evento.
 */
if ( ! class_exists( 'EventosApp_Security' ) ) {
final class EventosApp_Security {
    const OPTION      = 'eventosapp_security';
    const AUDIT       = 'eventosapp_security_login_audit';
    const SCAN        = 'eventosapp_security_malware_scan';
    const LEGACY_OPT  = 'clb_settings';
    const LEGACY_LOG  = 'clb_login_audit_log';
    const AUDIT_LIMIT = 250;

    private $settings = [];

    public function __construct() {
        $this->settings = $this->load_settings();

        add_action( 'init', [ $this, 'register_shortcodes' ], 999 );
        add_action( 'template_redirect', [ $this, 'handle_front_request' ], -50 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_recaptcha_script' ], 20 );

        add_action( 'login_init', [ $this, 'block_wp_login' ], 0 );
        add_action( 'admin_init', [ $this, 'block_wp_admin' ], 0 );
        add_action( 'init', [ $this, 'block_author_enumeration' ], 0 );
        add_action( 'init', [ $this, 'maybe_block_xmlrpc_request' ], 0 );

        add_filter( 'show_admin_bar', '__return_false', PHP_INT_MAX );
        add_action( 'init', [ $this, 'hide_admin_bar_bump' ], 1 );
        add_action( 'wp_enqueue_scripts', [ $this, 'dequeue_admin_bar_assets' ], 100 );

        add_action( 'init', [ $this, 'clean_wp_head' ], 1 );
        add_action( 'send_headers', [ $this, 'send_security_headers' ], 20 );
        add_filter( 'xmlrpc_enabled', [ $this, 'filter_xmlrpc_enabled' ] );
        add_filter( 'xmlrpc_methods', [ $this, 'filter_xmlrpc_methods' ] );
        add_filter( 'wp_headers', [ $this, 'filter_wp_headers' ] );
        add_filter( 'pings_open', [ $this, 'filter_pings_open' ], 20, 2 );
        add_filter( 'rest_endpoints', [ $this, 'filter_rest_endpoints' ] );

        add_action( 'comment_form_after_fields', [ $this, 'render_comment_honeypot' ] );
        add_action( 'comment_form_logged_in_after', [ $this, 'render_comment_honeypot' ] );
        add_filter( 'preprocess_comment', [ $this, 'check_comment_honeypot' ], 1 );

        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_notices', [ $this, 'legacy_plugin_notice' ] );

        add_action( 'admin_post_eventosapp_security_scan', [ $this, 'handle_malware_scan' ] );
        add_action( 'admin_post_eventosapp_security_clear_audit', [ $this, 'handle_clear_audit' ] );

        add_filter( 'eventosapp_installation_page_registry', [ $this, 'extend_page_registry' ] );
        add_filter( 'eventosapp_dashboard_categories', [ $this, 'extend_dashboard_categories' ] );
        add_filter( 'eventosapp_dashboard_modules', [ $this, 'extend_dashboard_modules' ], 50, 3 );
        add_action( 'wp_footer', [ $this, 'render_session_dock' ], 80 );

        $this->maybe_migrate_legacy_audit();
    }

    private function defaults() {
        return [
            'login_page_id'          => 0,
            'error_404_page_id'      => 0,
            'recaptcha_site_key'     => '',
            'recaptcha_secret_key'   => '',
            'enable_attempts'        => 1,
            'max_attempts'           => 5,
            'lock_minutes'           => 10,
            'enable_nonce'           => 1,
            'block_author_enum'      => 1,
            'block_rest_user_enum'   => 1,
            'disable_xmlrpc'         => 1,
            'disable_pingbacks'      => 1,
            'comment_honeypot'       => 1,
            'security_headers'       => 1,
            'clean_wp_head'          => 1,
            'roles'                  => [],
        ];
    }

    private function raw_settings() {
        $value = get_option( self::OPTION, [] );
        return is_array( $value ) ? $value : [];
    }

    private function load_settings() {
        $stored = $this->raw_settings();

        if ( empty( $stored ) ) {
            $legacy = get_option( self::LEGACY_OPT, [] );
            if ( is_array( $legacy ) && ! empty( $legacy ) ) {
                $stored = $this->convert_legacy_settings( $legacy );
                update_option( self::OPTION, $stored, false );
            }
        }

        return wp_parse_args( $stored, $this->defaults() );
    }

    private function convert_legacy_settings( array $legacy ) {
        $out = $this->defaults();
        $out['login_page_id']        = absint( $legacy['login_page'] ?? 0 );
        $out['recaptcha_site_key']   = sanitize_text_field( $legacy['recaptcha_site_key'] ?? '' );
        $out['recaptcha_secret_key'] = sanitize_text_field( $legacy['recaptcha_secret_key'] ?? '' );
        $out['enable_attempts']      = ! empty( $legacy['enable_attempts'] ) ? 1 : 0;
        $out['enable_nonce']         = ! empty( $legacy['enable_nonce'] ) ? 1 : 0;
        $out['block_author_enum']    = ! empty( $legacy['block_author_enum'] ) ? 1 : 0;

        if ( ! empty( $legacy['forbidden_url'] ) ) {
            $out['error_404_page_id'] = absint( url_to_postid( esc_url_raw( $legacy['forbidden_url'] ) ) );
        }

        if ( ! empty( $legacy['roles'] ) && is_array( $legacy['roles'] ) ) {
            foreach ( $legacy['roles'] as $role => $rule ) {
                if ( ! is_array( $rule ) ) continue;
                $role = sanitize_key( $role );
                if ( $role === '' ) continue;
                $out['roles'][ $role ] = [
                    'enabled'       => ! empty( $rule['enabled'] ) ? 1 : 0,
                    'backend'       => ! empty( $rule['backend'] ) ? 1 : 0,
                    'redirect_page' => absint( $rule['redirect_page'] ?? 0 ),
                ];
            }
        }

        return $out;
    }

    private function maybe_migrate_legacy_audit() {
        $current = get_option( self::AUDIT, null );
        if ( is_array( $current ) ) return;

        $legacy = get_option( self::LEGACY_LOG, [] );
        if ( ! is_array( $legacy ) || empty( $legacy ) ) {
            update_option( self::AUDIT, [], false );
            return;
        }

        $clean = [];
        foreach ( array_slice( $legacy, 0, self::AUDIT_LIMIT ) as $row ) {
            if ( ! is_array( $row ) ) continue;
            $clean[] = [
                'date'    => sanitize_text_field( $row['date'] ?? '' ),
                'ip'      => sanitize_text_field( $row['ip'] ?? '' ),
                'user'    => sanitize_text_field( $row['user'] ?? '' ),
                'success' => ! empty( $row['success'] ) ? 1 : 0,
                'user_id' => absint( $row['user_id'] ?? 0 ),
                'reason'  => 'Importado desde Custom Login Basic',
            ];
        }
        update_option( self::AUDIT, $clean, false );
    }

    public function get_all_roles() {
        if ( ! function_exists( 'wp_roles' ) ) return [];
        $roles = wp_roles()->roles;
        $out   = [];
        foreach ( (array) $roles as $key => $data ) {
            $out[ $key ] = isset( $data['name'] ) ? translate_user_role( $data['name'] ) : $key;
        }
        return $out;
    }

    private function default_role_enabled( $role ) {
        return in_array( $role, [ 'administrator', 'staff', 'logistico', 'organizador', 'coordinador', 'expositor' ], true ) ? 1 : 0;
    }

    public function get_role_rule( $role ) {
        $role  = sanitize_key( (string) $role );
        $rules = isset( $this->settings['roles'] ) && is_array( $this->settings['roles'] ) ? $this->settings['roles'] : [];
        $rule  = isset( $rules[ $role ] ) && is_array( $rules[ $role ] ) ? $rules[ $role ] : [];

        return [
            'enabled'       => array_key_exists( 'enabled', $rule ) ? (int) !! $rule['enabled'] : $this->default_role_enabled( $role ),
            'backend'       => array_key_exists( 'backend', $rule ) ? (int) !! $rule['backend'] : ( $role === 'administrator' ? 1 : 0 ),
            'redirect_page' => absint( $rule['redirect_page'] ?? 0 ),
        ];
    }

    public function user_has_enabled_role( $user ) {
        if ( ! $user instanceof WP_User || empty( $user->roles ) ) return false;
        foreach ( (array) $user->roles as $role ) {
            $rule = $this->get_role_rule( $role );
            if ( ! empty( $rule['enabled'] ) ) return true;
        }
        return false;
    }

    public function user_can_access_backend( $user ) {
        if ( ! $user instanceof WP_User ) return false;
        if ( user_can( $user, 'manage_options' ) ) return true;

        foreach ( (array) $user->roles as $role ) {
            $rule = $this->get_role_rule( $role );
            if ( ! empty( $rule['enabled'] ) && ! empty( $rule['backend'] ) ) return true;
        }
        return false;
    }

    public function get_front_redirect_for_user( $user ) {
        if ( ! $user instanceof WP_User ) return home_url( '/' );

        foreach ( (array) $user->roles as $role ) {
            $rule = $this->get_role_rule( $role );
            if ( empty( $rule['enabled'] ) ) continue;
            if ( ! empty( $rule['redirect_page'] ) ) {
                $url = get_permalink( $rule['redirect_page'] );
                if ( $url ) return $url;
            }
        }

        if ( function_exists( 'eventosapp_get_dashboard_url' ) ) {
            return eventosapp_get_dashboard_url();
        }

        return home_url( '/' );
    }

    private function get_login_page_id() {
        return absint( $this->settings['login_page_id'] ?? 0 );
    }

    private function get_404_page_id() {
        return absint( $this->settings['error_404_page_id'] ?? 0 );
    }

    public function get_login_url() {
        $page_id = $this->get_login_page_id();
        if ( $page_id ) {
            $url = get_permalink( $page_id );
            if ( $url ) return $url;
        }
        return home_url( '/' );
    }

    private function get_404_url() {
        $page_id = $this->get_404_page_id();
        if ( $page_id ) {
            $url = get_permalink( $page_id );
            if ( $url ) return $url;
        }
        return '';
    }

    public function register_shortcodes() {
        // Fuerza el handler integrado incluso si el plugin histórico sigue activo
        // temporalmente durante la migración.
        remove_shortcode( 'custom_login_basic' );
        add_shortcode( 'custom_login_basic', [ $this, 'shortcode_login_form' ] );
        add_shortcode( 'eventosapp_404', [ $this, 'shortcode_404' ] );
    }

    public function handle_front_request() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) return;

        $queried_id = get_queried_object_id();
        $login_id   = $this->get_login_page_id();
        $page_404   = $this->get_404_page_id();

        if ( $page_404 && (int) $queried_id === (int) $page_404 ) {
            status_header( 404 );
            nocache_headers();
            return;
        }

        if ( is_404() && $page_404 ) {
            $url = get_permalink( $page_404 );
            if ( $url ) {
                wp_safe_redirect( $url, 302 );
                exit;
            }
        }

        if ( $login_id && (int) $queried_id === (int) $login_id && is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $this->user_has_enabled_role( $user ) ) {
                wp_safe_redirect( $this->get_front_redirect_for_user( $user ) );
                exit;
            }
            return;
        }

        if ( empty( $_POST['custom_login_submit'] ) ) return;

        $is_login_page = $login_id && (int) $queried_id === (int) $login_id;
        if ( ! $is_login_page ) {
            global $post;
            $is_login_page = $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'custom_login_basic' );
        }
        if ( ! $is_login_page ) return;

        $this->process_login();
    }

    private function login_error_redirect( $code ) {
        $url = $this->get_login_url();
        if ( ! $url ) $url = home_url( '/' );
        $url = remove_query_arg( [ 'login_error', 'loggedout' ], $url );
        wp_safe_redirect( add_query_arg( 'login_error', sanitize_key( $code ), $url ) );
        exit;
    }

    private function client_ip() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
    }

    private function attempt_key( $user_input = '' ) {
        $material = $this->client_ip() . '|' . strtolower( trim( (string) $user_input ) );
        return 'evapp_login_attempts_' . substr( hash_hmac( 'sha256', $material, wp_salt( 'auth' ) ), 0, 40 );
    }

    public function process_login() {
        if ( is_user_logged_in() ) return;

        $username = isset( $_POST['custom_login_user'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_login_user'] ) ) : '';
        $password = isset( $_POST['custom_login_pass'] ) ? (string) wp_unslash( $_POST['custom_login_pass'] ) : '';

        if ( ! empty( $this->settings['enable_nonce'] ) ) {
            $nonce = isset( $_POST['custom_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_login_nonce'] ) ) : '';
            if ( ! $nonce || ! wp_verify_nonce( $nonce, 'eventosapp_custom_login' ) ) {
                $this->log_attempt( $username ?: 'Solicitud sin usuario', false, 0, 'CSRF / nonce inválido' );
                $this->login_error_redirect( 'csrf' );
            }
        }

        $attempt_key = $this->attempt_key( $username );
        $attempts    = ! empty( $this->settings['enable_attempts'] ) ? (int) get_transient( $attempt_key ) : 0;
        $max         = max( 3, min( 20, absint( $this->settings['max_attempts'] ?? 5 ) ) );
        $lock        = max( 1, min( 120, absint( $this->settings['lock_minutes'] ?? 10 ) ) );

        if ( ! empty( $this->settings['enable_attempts'] ) && $attempts >= $max ) {
            $this->log_attempt( $username, false, 0, 'Bloqueo temporal por intentos' );
            $this->login_error_redirect( 'locked' );
        }

        $secret   = trim( (string) ( $this->settings['recaptcha_secret_key'] ?? '' ) );
        $site_key = trim( (string) ( $this->settings['recaptcha_site_key'] ?? '' ) );
        if ( $secret !== '' && $site_key !== '' ) {
            $token = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
            if ( $token === '' ) {
                $this->bump_attempt( $attempt_key, $attempts, $lock );
                $this->log_attempt( $username, false, 0, 'reCAPTCHA no completado' );
                $this->login_error_redirect( 'recaptcha' );
            }

            $verify = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', [
                'timeout' => 8,
                'body'    => [
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => $this->client_ip(),
                ],
            ] );

            if ( is_wp_error( $verify ) ) {
                $this->bump_attempt( $attempt_key, $attempts, $lock );
                $this->log_attempt( $username, false, 0, 'No fue posible validar reCAPTCHA' );
                $this->login_error_redirect( 'recaptcha' );
            }

            $payload = json_decode( wp_remote_retrieve_body( $verify ), true );
            if ( empty( $payload['success'] ) ) {
                $this->bump_attempt( $attempt_key, $attempts, $lock );
                $this->log_attempt( $username, false, 0, 'reCAPTCHA inválido' );
                $this->login_error_redirect( 'recaptcha' );
            }
        }

        if ( $username === '' || $password === '' ) {
            $this->bump_attempt( $attempt_key, $attempts, $lock );
            $this->log_attempt( $username ?: 'Vacío', false, 0, 'Credenciales incompletas' );
            $this->login_error_redirect( 'invalid' );
        }

        $user = wp_signon( [
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => true,
        ], is_ssl() );

        if ( is_wp_error( $user ) ) {
            $this->bump_attempt( $attempt_key, $attempts, $lock );
            $this->log_attempt( $username, false, 0, 'Credenciales inválidas' );
            $this->login_error_redirect( 'invalid' );
        }

        if ( ! $this->user_has_enabled_role( $user ) ) {
            wp_logout();
            $this->bump_attempt( $attempt_key, $attempts, $lock );
            $this->log_attempt( $username, false, $user->ID, 'Rol deshabilitado en Seguridad' );
            $this->login_error_redirect( 'role_disabled' );
        }

        delete_transient( $attempt_key );
        $this->log_attempt( $username, true, $user->ID, 'Inicio de sesión correcto' );
        wp_safe_redirect( $this->get_front_redirect_for_user( $user ) );
        exit;
    }

    private function bump_attempt( $key, $attempts, $lock_minutes ) {
        if ( empty( $this->settings['enable_attempts'] ) ) return;
        set_transient( $key, (int) $attempts + 1, max( 1, absint( $lock_minutes ) ) * MINUTE_IN_SECONDS );
    }

    public function shortcode_login_form() {
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( ! $this->user_has_enabled_role( $user ) ) {
                return $this->login_shell(
                    '<div class="evapp-login-alert is-error"><strong>Acceso no habilitado.</strong><span>Tu cuenta está autenticada, pero ninguno de sus roles está activo para ingresar a EventosApp.</span></div>' .
                    '<a class="evapp-login-secondary" href="' . esc_url( wp_logout_url( $this->get_login_url() ) ) . '">Cerrar esta sesión</a>'
                );
            }

            return $this->login_shell(
                '<div class="evapp-login-alert is-success"><strong>Sesión activa.</strong><span>Ingresaste como ' . esc_html( $user->display_name ) . '.</span></div>' .
                '<a class="evapp-login-submit" href="' . esc_url( $this->get_front_redirect_for_user( $user ) ) . '">Ir al panel</a>' .
                '<a class="evapp-login-secondary" href="' . esc_url( wp_logout_url( $this->get_login_url() ) ) . '">Cerrar sesión</a>'
            );
        }

        $errors = [
            'invalid'       => 'Usuario o contraseña incorrectos.',
            'recaptcha'     => 'No fue posible validar el reCAPTCHA. Inténtalo nuevamente.',
            'locked'        => 'Se alcanzó el límite de intentos. Espera unos minutos antes de volver a intentarlo.',
            'role_disabled' => 'Tu cuenta existe, pero su rol no tiene permitido iniciar sesión en EventosApp.',
            'csrf'          => 'La sesión del formulario venció. Recarga la página e inténtalo nuevamente.',
        ];
        $code   = isset( $_GET['login_error'] ) ? sanitize_key( wp_unslash( $_GET['login_error'] ) ) : '';
        $error  = isset( $errors[ $code ] ) ? $errors[ $code ] : '';
        $site   = trim( (string) ( $this->settings['recaptcha_site_key'] ?? '' ) );
        $secret = trim( (string) ( $this->settings['recaptcha_secret_key'] ?? '' ) );
        if ( $site === '' || $secret === '' ) $site = '';

        ob_start();
        if ( $error ) {
            echo '<div class="evapp-login-alert is-error" role="alert"><strong>No pudimos iniciar sesión.</strong><span>' . esc_html( $error ) . '</span></div>';
        }
        ?>
        <form method="post" class="evapp-login-form" autocomplete="on">
            <?php if ( ! empty( $this->settings['enable_nonce'] ) ) wp_nonce_field( 'eventosapp_custom_login', 'custom_login_nonce' ); ?>
            <div class="evapp-login-field">
                <label for="evapp-login-user">Usuario o correo</label>
                <input id="evapp-login-user" type="text" name="custom_login_user" autocomplete="username" inputmode="email" required autofocus>
            </div>
            <div class="evapp-login-field">
                <label for="evapp-login-pass">Contraseña</label>
                <div class="evapp-password-wrap">
                    <input id="evapp-login-pass" type="password" name="custom_login_pass" autocomplete="current-password" required>
                    <button type="button" class="evapp-password-toggle" aria-label="Mostrar contraseña" aria-pressed="false" data-evapp-password-toggle>
                        <svg class="evapp-eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.6"/></svg>
                    </button>
                </div>
            </div>
            <?php if ( $site !== '' ) : ?>
                <div class="evapp-recaptcha"><div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $site ); ?>"></div></div>
            <?php endif; ?>
            <button type="submit" name="custom_login_submit" value="1" class="evapp-login-submit">Iniciar sesión</button>
        </form>
        <script>
        (function(){
            var root=document.currentScript&&document.currentScript.closest('.evapp-login-card');
            if(!root) return;
            var btn=root.querySelector('[data-evapp-password-toggle]');
            var input=root.querySelector('#evapp-login-pass');
            if(!btn||!input) return;
            btn.addEventListener('click',function(){
                var show=input.type==='password';
                input.type=show?'text':'password';
                btn.setAttribute('aria-pressed',show?'true':'false');
                btn.setAttribute('aria-label',show?'Ocultar contraseña':'Mostrar contraseña');
                input.focus({preventScroll:true});
            });
        })();
        </script>
        <?php
        return $this->login_shell( ob_get_clean() );
    }

    private function login_shell( $content ) {
        $logo = $this->brand_logo_url();
        ob_start();
        ?>
        <div class="evapp-login-stage">
            <style>
            .evapp-login-stage{--ev:#3279bd;--ev-dark:#255f96;--ev-text:#182230;--ev-muted:#64748b;--ev-border:#dfe7f1;--ev-bg:#f5f8fc;width:100%;padding:clamp(24px,5vw,64px) 16px;box-sizing:border-box;font-family:inherit;color:var(--ev-text)}
            .evapp-login-stage *{box-sizing:border-box}.evapp-login-card{width:min(100%,470px);margin:0 auto;background:#fff;border:1px solid var(--ev-border);border-radius:24px;padding:clamp(24px,5vw,38px);box-shadow:0 24px 70px rgba(28,56,86,.13)}
            .evapp-login-brand{text-align:center;margin-bottom:26px}.evapp-login-brand img{display:block;max-width:220px;max-height:74px;width:auto;height:auto;margin:0 auto 15px;object-fit:contain}.evapp-login-mark{width:58px;height:58px;border-radius:18px;margin:0 auto 14px;background:linear-gradient(145deg,#3279bd,#38adcc);display:grid;place-items:center;color:#fff;box-shadow:0 12px 28px rgba(50,121,189,.28)}
            .evapp-login-mark svg{width:30px;height:30px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.evapp-login-brand h2{font-size:26px;line-height:1.18;margin:0 0 7px}.evapp-login-brand p{margin:0;color:var(--ev-muted);font-size:14px;line-height:1.55}
            .evapp-login-form{display:grid;gap:17px}.evapp-login-field label{display:block;font-weight:700;font-size:13px;margin:0 0 7px}.evapp-login-field input{width:100%;height:48px;border:1px solid #cfd9e6;border-radius:12px;background:#fff;padding:0 14px;font:inherit;color:var(--ev-text);outline:none;transition:.18s border-color,.18s box-shadow}.evapp-login-field input:focus{border-color:var(--ev);box-shadow:0 0 0 4px rgba(50,121,189,.12)}
            .evapp-password-wrap{position:relative}.evapp-password-wrap input{padding-right:52px}.evapp-password-toggle{position:absolute;right:6px;top:6px;width:36px;height:36px;border:0;border-radius:9px;background:transparent;color:#64748b;display:grid;place-items:center;cursor:pointer}.evapp-password-toggle:hover,.evapp-password-toggle:focus{background:#eef5fb;color:var(--ev);outline:none}.evapp-password-toggle svg{width:21px;height:21px;fill:none;stroke:currentColor;stroke-width:1.9}
            .evapp-recaptcha{overflow:auto;padding:2px 0}.evapp-login-submit{appearance:none;border:0;border-radius:12px;background:var(--ev);color:#fff!important;min-height:48px;padding:12px 18px;font:inherit;font-weight:800;text-align:center;text-decoration:none!important;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:.18s transform,.18s background,.18s box-shadow}.evapp-login-submit:hover,.evapp-login-submit:focus{background:var(--ev-dark);transform:translateY(-1px);box-shadow:0 10px 24px rgba(50,121,189,.2)}
            .evapp-login-secondary{display:flex;justify-content:center;margin-top:12px;color:var(--ev)!important;font-weight:700;text-decoration:none!important}.evapp-login-alert{display:grid;gap:4px;padding:13px 14px;border-radius:12px;margin-bottom:17px;font-size:13px;line-height:1.45}.evapp-login-alert.is-error{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}.evapp-login-alert.is-success{background:#ecfdf3;border:1px solid #bbf7d0;color:#166534}.evapp-login-alert strong{font-size:14px}
            @media(max-width:480px){.evapp-login-stage{padding:18px 10px}.evapp-login-card{border-radius:18px;padding:22px 17px}.evapp-login-brand h2{font-size:23px}.g-recaptcha{transform:scale(.89);transform-origin:left top;margin-bottom:-8px}}
            </style>
            <div class="evapp-login-card">
                <div class="evapp-login-brand">
                    <?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="EventosApp"><?php else : ?>
                        <div class="evapp-login-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4M17 3v4M3 9h18M8 15l2.5 2.5L16 12"/></svg></div>
                    <?php endif; ?>
                    <h2>Acceso a EventosApp</h2>
                    <p>Ingresa con tu cuenta asignada para continuar al panel de gestión.</p>
                </div>
                <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML construido internamente. ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function enqueue_recaptcha_script() {
        if ( is_admin() || is_user_logged_in() ) return;
        $site   = trim( (string) ( $this->settings['recaptcha_site_key'] ?? '' ) );
        $secret = trim( (string) ( $this->settings['recaptcha_secret_key'] ?? '' ) );
        if ( $site === '' || $secret === '' ) return;

        global $post;
        if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'custom_login_basic' ) ) {
            wp_enqueue_script( 'google-recaptcha', 'https://www.google.com/recaptcha/api.js', [], null, true );
        }
    }

    public function block_wp_login() {
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        if ( in_array( $action, [ 'logout', 'loggedout', 'postpass' ], true ) ) return;
        $this->go_404();
    }

    public function block_wp_admin() {
        if ( wp_doing_ajax() || wp_doing_cron() ) return;

        global $pagenow;
        if ( in_array( (string) $pagenow, [ 'admin-ajax.php', 'admin-post.php' ], true ) ) return;

        if ( ! is_user_logged_in() ) {
            $this->go_404();
        }

        $user = wp_get_current_user();
        if ( ! $this->user_can_access_backend( $user ) ) {
            $this->go_404();
        }
    }

    private function go_404() {
        $url = $this->get_404_url();
        if ( $url ) {
            wp_safe_redirect( $url, 302 );
            exit;
        }

        status_header( 404 );
        nocache_headers();
        wp_die( esc_html__( 'Página no encontrada.', 'eventosapp' ), '404', [ 'response' => 404 ] );
    }

    public function block_author_enumeration() {
        if ( empty( $this->settings['block_author_enum'] ) || is_admin() ) return;
        if ( isset( $_GET['author'] ) || isset( $_GET['author_name'] ) ) {
            $this->go_404();
        }
    }

    public function maybe_block_xmlrpc_request() {
        if ( empty( $this->settings['disable_xmlrpc'] ) ) return;
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            status_header( 403 );
            nocache_headers();
            exit( 'XML-RPC disabled by EventosApp Security.' );
        }
    }

    public function hide_admin_bar_bump() {
        if ( is_admin() ) return;
        show_admin_bar( false );
        remove_action( 'wp_head', '_admin_bar_bump_cb' );
    }

    public function dequeue_admin_bar_assets() {
        if ( is_admin() ) return;
        wp_dequeue_style( 'admin-bar' );
        wp_deregister_style( 'admin-bar' );
        wp_dequeue_script( 'admin-bar' );
        wp_deregister_script( 'admin-bar' );
    }

    public function clean_wp_head() {
        if ( is_admin() || empty( $this->settings['clean_wp_head'] ) ) return;

        remove_action( 'wp_head', 'wp_generator' );
        remove_action( 'wp_head', 'rsd_link' );
        remove_action( 'wp_head', 'wlwmanifest_link' );
        remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
        remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
        remove_action( 'template_redirect', 'rest_output_link_header', 11 );
        remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
        remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
        remove_action( 'wp_head', 'wp_oembed_add_host_js' );
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        add_filter( 'embed_oembed_discover', '__return_false' );
        add_filter( 'the_generator', '__return_empty_string' );
    }

    public function send_security_headers() {
        if ( empty( $this->settings['security_headers'] ) || headers_sent() ) return;
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Referrer-Policy: strict-origin-when-cross-origin' );
        header( 'X-Permitted-Cross-Domain-Policies: none' );
    }

    public function filter_xmlrpc_enabled( $enabled ) {
        return ! empty( $this->settings['disable_xmlrpc'] ) ? false : $enabled;
    }

    public function filter_xmlrpc_methods( $methods ) {
        if ( ! empty( $this->settings['disable_pingbacks'] ) && is_array( $methods ) ) {
            unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
        }
        return $methods;
    }

    public function filter_wp_headers( $headers ) {
        if ( ! empty( $this->settings['disable_pingbacks'] ) && is_array( $headers ) ) {
            unset( $headers['X-Pingback'] );
        }
        return $headers;
    }

    public function filter_pings_open( $open, $post_id ) {
        return ! empty( $this->settings['disable_pingbacks'] ) ? false : $open;
    }

    public function filter_rest_endpoints( $endpoints ) {
        if ( empty( $this->settings['block_rest_user_enum'] ) || is_user_logged_in() || ! is_array( $endpoints ) ) return $endpoints;
        foreach ( array_keys( $endpoints ) as $route ) {
            if ( strpos( (string) $route, '/wp/v2/users' ) === 0 ) {
                unset( $endpoints[ $route ] );
            }
        }
        return $endpoints;
    }

    public function render_comment_honeypot() {
        if ( empty( $this->settings['comment_honeypot'] ) ) return;
        echo '<p class="evapp-comment-hp" aria-hidden="true" style="position:absolute!important;left:-9999px!important;width:1px!important;height:1px!important;overflow:hidden!important"><label>Website secundario<input type="text" name="eventosapp_contact_website" value="" tabindex="-1" autocomplete="off"></label></p>';
    }

    public function check_comment_honeypot( $commentdata ) {
        if ( ! empty( $this->settings['comment_honeypot'] ) && ! empty( $_POST['eventosapp_contact_website'] ) ) {
            wp_die( 'Comentario rechazado.', 'Spam', [ 'response' => 403 ] );
        }
        return $commentdata;
    }

    private function log_attempt( $user, $success, $user_id = 0, $reason = '' ) {
        $log = get_option( self::AUDIT, [] );
        if ( ! is_array( $log ) ) $log = [];
        array_unshift( $log, [
            'date'    => current_time( 'mysql' ),
            'ip'      => $this->client_ip(),
            'user'    => sanitize_text_field( (string) $user ),
            'success' => $success ? 1 : 0,
            'user_id' => absint( $user_id ),
            'reason'  => sanitize_text_field( (string) $reason ),
        ] );
        update_option( self::AUDIT, array_slice( $log, 0, self::AUDIT_LIMIT ), false );
    }

    public function register_admin_page() {
        add_submenu_page(
            'eventosapp_dashboard',
            'Seguridad de EventosApp',
            'Seguridad',
            'manage_options',
            'eventosapp_seguridad',
            [ $this, 'render_admin_page' ]
        );
    }

    public function register_settings() {
        $args = [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
            'default'           => $this->defaults(),
        ];
        register_setting( 'eventosapp_security_group', self::OPTION, $args );
        register_setting( 'eventosapp_pages_group', self::OPTION, $args );

        add_settings_section(
            'eventosapp_security_pages_section',
            'Acceso, Login y página 404',
            function() {
                echo '<p>Selecciona las páginas que contienen el acceso integrado y la experiencia 404 de EventosApp. Ambas pueden instalarse automáticamente desde el estado de instalación.</p>';
            },
            'eventosapp_configuracion'
        );

        add_settings_field(
            'eventosapp_security_login_page_id',
            'Página de inicio de sesión',
            [ $this, 'render_config_page_select' ],
            'eventosapp_configuracion',
            'eventosapp_security_pages_section',
            [ 'key' => 'login_page_id', 'shortcode' => '[custom_login_basic]' ]
        );

        add_settings_field(
            'eventosapp_security_404_page_id',
            'Página 404 de EventosApp',
            [ $this, 'render_config_page_select' ],
            'eventosapp_configuracion',
            'eventosapp_security_pages_section',
            [ 'key' => 'error_404_page_id', 'shortcode' => '[eventosapp_404]' ]
        );
    }

    public function sanitize_settings( $input ) {
        $input   = is_array( $input ) ? $input : [];
        $current = $this->raw_settings();
        $out     = wp_parse_args( $current, $this->defaults() );
        $full    = isset( $input['_context'] ) && $input['_context'] === 'full';

        if ( array_key_exists( 'login_page_id', $input ) ) {
            $out['login_page_id'] = absint( $input['login_page_id'] );
        }
        if ( array_key_exists( 'error_404_page_id', $input ) ) {
            $out['error_404_page_id'] = absint( $input['error_404_page_id'] );
        }

        if ( $full ) {
            $out['recaptcha_site_key']   = sanitize_text_field( $input['recaptcha_site_key'] ?? '' );
            $out['recaptcha_secret_key'] = sanitize_text_field( $input['recaptcha_secret_key'] ?? '' );
            $out['enable_attempts']      = ! empty( $input['enable_attempts'] ) ? 1 : 0;
            $out['max_attempts']         = max( 3, min( 20, absint( $input['max_attempts'] ?? 5 ) ) );
            $out['lock_minutes']         = max( 1, min( 120, absint( $input['lock_minutes'] ?? 10 ) ) );
            $out['enable_nonce']         = ! empty( $input['enable_nonce'] ) ? 1 : 0;
            $out['block_author_enum']    = ! empty( $input['block_author_enum'] ) ? 1 : 0;
            $out['block_rest_user_enum'] = ! empty( $input['block_rest_user_enum'] ) ? 1 : 0;
            $out['disable_xmlrpc']       = ! empty( $input['disable_xmlrpc'] ) ? 1 : 0;
            $out['disable_pingbacks']    = ! empty( $input['disable_pingbacks'] ) ? 1 : 0;
            $out['comment_honeypot']     = ! empty( $input['comment_honeypot'] ) ? 1 : 0;
            $out['security_headers']     = ! empty( $input['security_headers'] ) ? 1 : 0;
            $out['clean_wp_head']        = ! empty( $input['clean_wp_head'] ) ? 1 : 0;
            $out['roles']                = [];

            foreach ( $this->get_all_roles() as $role => $label ) {
                $rule = isset( $input['roles'][ $role ] ) && is_array( $input['roles'][ $role ] ) ? $input['roles'][ $role ] : [];
                $out['roles'][ $role ] = [
                    'enabled'       => ! empty( $rule['enabled'] ) ? 1 : 0,
                    'backend'       => ! empty( $rule['backend'] ) ? 1 : 0,
                    'redirect_page' => absint( $rule['redirect_page'] ?? 0 ),
                ];
            }
        }

        unset( $out['_context'] );
        $this->settings = wp_parse_args( $out, $this->defaults() );
        return $out;
    }

    public function render_config_page_select( $args ) {
        $key       = sanitize_key( $args['key'] ?? '' );
        $shortcode = sanitize_text_field( $args['shortcode'] ?? '' );
        $current   = absint( $this->settings[ $key ] ?? 0 );
        $pages     = get_pages( [
            'post_status' => [ 'publish', 'private' ],
            'number'      => 0,
            'sort_column' => 'post_title',
            'sort_order'  => 'asc',
        ] );

        echo '<select name="' . esc_attr( self::OPTION ) . '[' . esc_attr( $key ) . ']" class="regular-text">';
        echo '<option value="0">— Selecciona una página —</option>';
        foreach ( $pages as $page ) {
            printf( '<option value="%d"%s>%s</option>', $page->ID, selected( $current, $page->ID, false ), esc_html( $page->post_title ) );
        }
        echo '</select>';
        echo '<p class="description" style="margin-top:6px;">Debe contener el shortcode: <code>' . esc_html( $shortcode ) . '</code>.</p>';
    }

    public function extend_page_registry( $registry ) {
        $registry = is_array( $registry ) ? $registry : [];
        $registry['security_login'] = [
            'group'       => 'Seguridad',
            'label'       => 'Inicio de sesión de EventosApp',
            'description' => 'Acceso del staff y usuarios autorizados con el login integrado.',
            'option'      => self::OPTION,
            'option_key'  => 'login_page_id',
            'shortcode'   => 'custom_login_basic',
            'content'     => '[custom_login_basic]',
            'title'       => 'Ingreso Staff',
            'slug'        => 'ingreso-staff',
        ];
        $registry['security_404'] = [
            'group'       => 'Seguridad',
            'label'       => 'Página 404 de EventosApp',
            'description' => 'Experiencia visual para rutas inexistentes y accesos protegidos.',
            'option'      => self::OPTION,
            'option_key'  => 'error_404_page_id',
            'shortcode'   => 'eventosapp_404',
            'content'     => '[eventosapp_404]',
            'title'       => '404 | EventosApp',
            'slug'        => '404',
        ];
        return $registry;
    }

    public function extend_dashboard_categories( $categories ) {
        $categories = is_array( $categories ) ? $categories : [];
        $categories['account'] = 'Cuenta y administración';
        return $categories;
    }

    public function extend_dashboard_modules( $modules, $event_id, $user_id ) {
        $modules = is_array( $modules ) ? $modules : [];
        $user    = get_userdata( $user_id );
        if ( ! $user instanceof WP_User ) return $modules;

        $modules['session_logout'] = [
            'title'       => 'Cerrar sesión',
            'description' => 'Usuario activo: ' . $user->display_name . '. Finaliza de forma segura la sesión actual.',
            'icon'        => 'circle-user',
            'category'    => 'account',
            'url'         => wp_logout_url( $this->get_login_url() ),
            'visible'     => true,
            'keywords'    => 'usuario cuenta sesión salir logout ' . $user->user_login,
        ];

        if ( user_can( $user, 'manage_options' ) ) {
            $modules['wordpress_backend'] = [
                'title'       => 'Backend de WordPress',
                'description' => 'Acceso administrativo avanzado. Visible únicamente para administradores.',
                'icon'        => 'apps',
                'category'    => 'account',
                'url'         => admin_url(),
                'visible'     => true,
                'keywords'    => 'wordpress backend administrador wp admin configuración',
            ];
        }

        return $modules;
    }

    private function is_managed_frontend_page() {
        if ( is_admin() || ! is_user_logged_in() ) return false;
        $page_id = get_queried_object_id();
        if ( ! $page_id ) return false;

        $cfg = function_exists( 'eventosapp_get_pages_config' ) ? eventosapp_get_pages_config() : [];
        if ( ! is_array( $cfg ) ) return false;

        $exclude = [ 'self_checkin_page_id', 'live_raffle_public_page_id' ];
        foreach ( $cfg as $key => $configured_id ) {
            if ( in_array( $key, $exclude, true ) ) continue;
            if ( absint( $configured_id ) && absint( $configured_id ) === absint( $page_id ) ) return true;
        }

        return false;
    }

    public function render_session_dock() {
        static $rendered = false;
        if ( $rendered || ! $this->is_managed_frontend_page() ) return;
        $rendered = true;

        $user         = wp_get_current_user();
        $dashboard    = function_exists( 'eventosapp_get_dashboard_url' ) ? eventosapp_get_dashboard_url() : home_url( '/' );
        $dashboard_id = function_exists( 'eventosapp_get_pages_config' ) ? absint( eventosapp_get_pages_config()['dashboard_page_id'] ?? 0 ) : 0;
        $is_dashboard = $dashboard_id && absint( get_queried_object_id() ) === $dashboard_id;
        ?>
        <style id="eventosapp-session-dock-css">
        .evapp-session-dock{position:fixed;right:18px;bottom:18px;z-index:99990;display:flex;align-items:center;gap:8px;max-width:min(680px,calc(100vw - 24px));padding:8px;background:rgba(255,255,255,.96);border:1px solid #dfe7f1;border-radius:16px;box-shadow:0 18px 48px rgba(20,43,67,.18);backdrop-filter:blur(12px);font-family:inherit;color:#182230}.evapp-session-dock *{box-sizing:border-box}.evapp-session-user{display:flex;align-items:center;gap:9px;min-width:0;padding:2px 7px 2px 3px}.evapp-session-avatar{width:34px;height:34px;border-radius:11px;background:#eaf4ff;color:#3279bd;display:grid;place-items:center;flex:0 0 auto}.evapp-session-avatar svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:2}.evapp-session-copy{min-width:0;line-height:1.15}.evapp-session-copy strong,.evapp-session-copy small{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:190px}.evapp-session-copy strong{font-size:12px}.evapp-session-copy small{font-size:10px;color:#64748b;margin-top:3px}.evapp-session-action{min-height:34px;padding:7px 10px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;color:#255f96!important;background:#eef5fb;text-decoration:none!important;font-size:11px;font-weight:800;white-space:nowrap}.evapp-session-action:hover,.evapp-session-action:focus{background:#dcecf8;color:#174c78!important}.evapp-session-action.is-admin{background:#182230;color:#fff!important}.evapp-session-action.is-logout{background:#fff1f2;color:#9f1239!important}.evapp-session-action.is-logout:hover{background:#ffe4e6}
        @media(max-width:600px){.evapp-session-dock{left:12px;right:12px;bottom:12px;max-width:none;display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:6px}.evapp-session-user{min-width:0}.evapp-session-copy strong,.evapp-session-copy small{max-width:140px}.evapp-session-action{padding:7px 9px}.evapp-session-action.is-admin{display:none}}
        </style>
        <aside class="evapp-session-dock" aria-label="Sesión activa de EventosApp">
            <div class="evapp-session-user">
                <span class="evapp-session-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"/><path d="M5 20c.8-4 3.2-6 7-6s6.2 2 7 6"/></svg></span>
                <span class="evapp-session-copy"><strong><?php echo esc_html( $user->display_name ); ?></strong><small><?php echo esc_html( $user->user_login ); ?></small></span>
            </div>
            <?php if ( ! $is_dashboard ) : ?><a class="evapp-session-action" href="<?php echo esc_url( $dashboard ); ?>">Panel</a><?php endif; ?>
            <?php if ( current_user_can( 'manage_options' ) ) : ?><a class="evapp-session-action is-admin" href="<?php echo esc_url( admin_url() ); ?>">WordPress</a><?php endif; ?>
            <a class="evapp-session-action is-logout" href="<?php echo esc_url( wp_logout_url( $this->get_login_url() ) ); ?>">Cerrar sesión</a>
        </aside>
        <?php
    }

    private function brand_logo_url() {
        $custom_logo_id = absint( get_theme_mod( 'custom_logo' ) );
        if ( $custom_logo_id ) {
            $url = wp_get_attachment_image_url( $custom_logo_id, 'large' );
            if ( $url ) return $url;
        }
        $site_icon = get_site_icon_url( 256 );
        return $site_icon ? $site_icon : '';
    }

    public function shortcode_404() {
        $logo      = $this->brand_logo_url();
        $home      = home_url( '/' );
        $dashboard = is_user_logged_in() && function_exists( 'eventosapp_get_dashboard_url' ) ? eventosapp_get_dashboard_url() : '';
        $login     = $this->get_login_url();

        ob_start();
        ?>
        <section class="evapp-404" aria-labelledby="evapp-404-title">
            <style>
            .evapp-404{--ev:#3279bd;--ev2:#38adcc;--ink:#172334;--muted:#65758b;position:relative;isolation:isolate;overflow:hidden;min-height:min(760px,90vh);display:grid;place-items:center;padding:clamp(30px,7vw,80px) 18px;background:radial-gradient(circle at 15% 15%,rgba(56,173,204,.16),transparent 34%),radial-gradient(circle at 90% 75%,rgba(74,86,205,.15),transparent 32%),linear-gradient(180deg,#f8fbff,#eef5fb);font-family:inherit;color:var(--ink);border-radius:28px}.evapp-404 *{box-sizing:border-box}.evapp-404-card{position:relative;z-index:3;width:min(820px,100%);text-align:center}.evapp-404-logo{display:block;max-width:220px;max-height:76px;width:auto;height:auto;object-fit:contain;margin:0 auto 24px}.evapp-404-visual{width:min(420px,86vw);height:210px;margin:0 auto 20px;position:relative}.evapp-404-ticket{position:absolute;left:50%;top:50%;width:270px;height:150px;transform:translate(-50%,-50%) rotate(-4deg);border-radius:22px;background:#fff;border:1px solid #dce8f3;box-shadow:0 24px 60px rgba(37,95,150,.18);display:grid;grid-template-columns:76px 1fr;overflow:hidden;animation:ev404float 4.2s ease-in-out infinite}.evapp-404-ticket-side{background:linear-gradient(160deg,var(--ev),var(--ev2));display:grid;place-items:center;color:#fff}.evapp-404-ticket-side svg{width:38px;height:38px;fill:none;stroke:currentColor;stroke-width:2}.evapp-404-ticket-body{padding:23px 20px;text-align:left}.evapp-404-ticket-body b{display:block;font-size:15px;margin-bottom:11px}.evapp-404-lines{display:grid;gap:8px}.evapp-404-lines i{height:7px;border-radius:99px;background:#eaf1f7;display:block}.evapp-404-lines i:nth-child(2){width:73%}.evapp-404-lines i:nth-child(3){width:48%}.evapp-404-scan{position:absolute;left:50%;top:20px;width:3px;height:168px;border-radius:4px;background:linear-gradient(transparent,#38adcc,transparent);filter:drop-shadow(0 0 8px #38adcc);animation:ev404scan 2.8s ease-in-out infinite}.evapp-404-badge{position:absolute;right:4%;top:2%;width:86px;height:86px;border-radius:50%;background:#fff;border:1px solid #dce8f3;box-shadow:0 12px 30px rgba(37,95,150,.14);display:grid;place-items:center;font-size:24px;font-weight:900;color:var(--ev);transform:rotate(8deg)}
            .evapp-404-code{display:inline-flex;align-items:center;gap:8px;padding:6px 11px;border-radius:999px;background:#eaf4ff;color:#255f96;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;margin-bottom:12px}.evapp-404 h1{font-size:clamp(31px,6vw,58px);line-height:1.02;margin:0 auto 13px;max-width:760px;letter-spacing:-.035em}.evapp-404 p{max-width:650px;margin:0 auto;color:var(--muted);font-size:clamp(15px,2vw,18px);line-height:1.65}.evapp-404-actions{display:flex;justify-content:center;flex-wrap:wrap;gap:10px;margin-top:26px}.evapp-404-btn{min-height:46px;padding:11px 17px;border-radius:12px;text-decoration:none!important;font-weight:800;display:inline-flex;align-items:center;justify-content:center;border:1px solid #d8e4ef;background:#fff;color:#255f96!important;box-shadow:0 8px 20px rgba(30,70,108,.06)}.evapp-404-btn.is-primary{background:var(--ev);border-color:var(--ev);color:#fff!important}.evapp-404-orb{position:absolute;border-radius:50%;opacity:.65;z-index:1;animation:ev404drift 8s ease-in-out infinite}.evapp-404-orb.o1{width:18px;height:18px;background:#38adcc;left:12%;top:20%}.evapp-404-orb.o2{width:11px;height:11px;background:#4a56cd;right:15%;top:30%;animation-delay:-2s}.evapp-404-orb.o3{width:14px;height:14px;background:#3279bd;left:22%;bottom:17%;animation-delay:-4s}
            @keyframes ev404float{50%{transform:translate(-50%,-56%) rotate(2deg)}}@keyframes ev404scan{0%,100%{transform:translateX(-120px);opacity:.25}50%{transform:translateX(120px);opacity:1}}@keyframes ev404drift{50%{transform:translateY(-24px) rotate(90deg)}}@media(prefers-reduced-motion:reduce){.evapp-404 *{animation:none!important}}@media(max-width:520px){.evapp-404{border-radius:18px}.evapp-404-visual{height:185px}.evapp-404-ticket{width:235px;height:132px;grid-template-columns:62px 1fr}.evapp-404-ticket-body{padding:19px 16px}.evapp-404-scan{height:145px}.evapp-404-badge{width:70px;height:70px;font-size:20px}.evapp-404-actions{display:grid}.evapp-404-btn{width:100%}}
            </style>
            <span class="evapp-404-orb o1" aria-hidden="true"></span><span class="evapp-404-orb o2" aria-hidden="true"></span><span class="evapp-404-orb o3" aria-hidden="true"></span>
            <div class="evapp-404-card">
                <?php if ( $logo ) : ?><img class="evapp-404-logo" src="<?php echo esc_url( $logo ); ?>" alt="EventosApp"><?php endif; ?>
                <div class="evapp-404-visual" aria-hidden="true">
                    <div class="evapp-404-ticket"><div class="evapp-404-ticket-side"><svg viewBox="0 0 24 24"><rect x="4" y="4" width="6" height="6"/><rect x="14" y="4" width="6" height="6"/><rect x="4" y="14" width="6" height="6"/><path d="M14 14h3v3h-3zM18 18h2v2h-2z"/></svg></div><div class="evapp-404-ticket-body"><b>Ticket fuera de ruta</b><div class="evapp-404-lines"><i></i><i></i><i></i></div></div></div>
                    <span class="evapp-404-scan"></span><span class="evapp-404-badge">404</span>
                </div>
                <span class="evapp-404-code">Ruta no encontrada</span>
                <h1 id="evapp-404-title">Este QR parece haberte enviado a otra sala.</h1>
                <p>La página que buscabas ya no está aquí, cambió de ubicación o nunca tuvo acreditación. La buena noticia: podemos devolverte al evento correcto en un clic.</p>
                <div class="evapp-404-actions">
                    <?php if ( $dashboard ) : ?><a class="evapp-404-btn is-primary" href="<?php echo esc_url( $dashboard ); ?>">Volver al dashboard</a><?php elseif ( $login && $login !== $home ) : ?><a class="evapp-404-btn is-primary" href="<?php echo esc_url( $login ); ?>">Ir al inicio de sesión</a><?php endif; ?>
                    <a class="evapp-404-btn" href="<?php echo esc_url( $home ); ?>">Ir a la página principal</a>
                    <a class="evapp-404-btn" href="<?php echo esc_url( $home ); ?>" data-evapp-go-back>Volver a la página anterior</a>
                </div>
            </div>
            <script>(function(){var b=document.querySelector('[data-evapp-go-back]');if(!b)return;b.addEventListener('click',function(e){if(window.history.length>1){e.preventDefault();window.history.back();}});})();</script>
        </section>
        <?php
        return ob_get_clean();
    }

    public function legacy_plugin_notice() {
        if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'Custom_Login_Basic' ) ) return;
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || strpos( (string) $screen->id, 'eventosapp' ) === false ) return;
        echo '<div class="notice notice-warning"><p><strong>EventosApp Seguridad ya incluye Custom Login Basic.</strong> El plugin histórico sigue activo. Cuando valides el nuevo acceso, desactívalo para evitar hooks duplicados de login y bloqueo de backend.</p></div>';
    }

    private function scan_roots() {
        $roots = [];
        $plugin_root = realpath( dirname( __DIR__, 2 ) );
        if ( $plugin_root && is_dir( $plugin_root ) ) $roots['EventosApp'] = $plugin_root;
        $theme_root = realpath( get_stylesheet_directory() );
        if ( $theme_root && is_dir( $theme_root ) && $theme_root !== $plugin_root ) $roots['Tema activo'] = $theme_root;
        return $roots;
    }

    private function malware_patterns() {
        return [
            [ 'severity' => 'alta', 'label' => 'Ejecución ofuscada con eval/base64', 'regex' => '/eval\s*\(\s*(?:base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i' ],
            [ 'severity' => 'alta', 'label' => 'Entrada HTTP enviada a una función de sistema', 'regex' => '/\b(?:system|shell_exec|passthru|exec|popen|proc_open)\s*\(\s*(?:\$_(?:GET|POST|REQUEST|COOKIE)|filter_input)/i' ],
            [ 'severity' => 'alta', 'label' => 'assert/eval ejecutando entrada HTTP', 'regex' => '/\b(?:assert|eval)\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE)/i' ],
            [ 'severity' => 'alta', 'label' => 'Firma conocida de webshell', 'regex' => '/\b(?:FilesMan|WSO\s+Shell|b374k|r57shell|c99shell)\b/i' ],
            [ 'severity' => 'media', 'label' => 'Payload base64 extenso decodificado en PHP', 'regex' => '/base64_decode\s*\(\s*[\'\"][A-Za-z0-9+\/=]{220,}[\'\"]\s*\)/i' ],
            [ 'severity' => 'media', 'label' => 'preg_replace con modificador /e obsoleto', 'regex' => '/preg_replace\s*\(\s*[\'\"][^\'\"]*\/e[^\'\"]*[\'\"]/i' ],
        ];
    }

    public function handle_malware_scan() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No autorizado.', '', [ 'response' => 403 ] );
        check_admin_referer( 'eventosapp_security_scan', 'eventosapp_security_scan_nonce' );

        $started  = microtime( true );
        $findings = [];
        $scanned  = 0;
        $skipped  = 0;
        $errors   = [];
        $limit    = 3000;
        $max_size = 1536 * 1024;
        $patterns = $this->malware_patterns();

        foreach ( $this->scan_roots() as $label => $root ) {
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveCallbackFilterIterator(
                        new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
                        static function( $current, $key, $iterator ) {
                            if ( $current->isDir() ) {
                                return ! in_array( $current->getFilename(), [ '.git', 'node_modules', 'cache', 'tmp' ], true );
                            }
                            return true;
                        }
                    )
                );

                foreach ( $iterator as $file ) {
                    if ( $scanned >= $limit ) break 2;
                    if ( ! $file->isFile() || strtolower( $file->getExtension() ) !== 'php' ) continue;
                    if ( $file->getSize() > $max_size ) { $skipped++; continue; }

                    $scanned++;
                    $content = @file_get_contents( $file->getPathname() );
                    if ( $content === false ) { $errors[] = 'No fue posible leer ' . $file->getPathname(); continue; }

                    foreach ( $patterns as $pattern ) {
                        if ( preg_match( $pattern['regex'], $content ) ) {
                            $relative = ltrim( str_replace( wp_normalize_path( $root ), '', wp_normalize_path( $file->getPathname() ) ), '/' );
                            $findings[] = [
                                'root'     => $label,
                                'file'     => $relative,
                                'severity' => $pattern['severity'],
                                'rule'     => $pattern['label'],
                                'modified' => gmdate( 'Y-m-d H:i:s', $file->getMTime() ),
                                'sha256'   => hash_file( 'sha256', $file->getPathname() ),
                            ];
                            break;
                        }
                    }
                    if ( count( $findings ) >= 100 ) break 2;
                }
            } catch ( Throwable $e ) {
                $errors[] = $label . ': ' . $e->getMessage();
            }
        }

        $result = [
            'date'          => current_time( 'mysql' ),
            'files_scanned' => $scanned,
            'files_skipped' => $skipped,
            'findings'      => $findings,
            'errors'        => array_slice( array_map( 'sanitize_text_field', $errors ), 0, 20 ),
            'duration'      => round( microtime( true ) - $started, 3 ),
            'limit_reached' => $scanned >= $limit ? 1 : 0,
        ];
        update_option( self::SCAN, $result, false );

        wp_safe_redirect( add_query_arg( [ 'page' => 'eventosapp_seguridad', 'scan' => 'done' ], admin_url( 'admin.php' ) ) . '#malware' );
        exit;
    }

    public function handle_clear_audit() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No autorizado.', '', [ 'response' => 403 ] );
        check_admin_referer( 'eventosapp_security_clear_audit', 'eventosapp_security_clear_audit_nonce' );
        update_option( self::AUDIT, [], false );
        wp_safe_redirect( add_query_arg( [ 'page' => 'eventosapp_seguridad', 'audit' => 'cleared' ], admin_url( 'admin.php' ) ) . '#auditoria' );
        exit;
    }

    private function render_page_select_control( $key ) {
        $current = absint( $this->settings[ $key ] ?? 0 );
        $pages = get_pages( [ 'post_status' => [ 'publish', 'private' ], 'number' => 0, 'sort_column' => 'post_title', 'sort_order' => 'asc' ] );
        echo '<select name="' . esc_attr( self::OPTION ) . '[' . esc_attr( $key ) . ']">';
        echo '<option value="0">— Selecciona una página —</option>';
        foreach ( $pages as $page ) printf( '<option value="%d"%s>%s</option>', $page->ID, selected( $current, $page->ID, false ), esc_html( $page->post_title ) );
        echo '</select>';
    }

    private function render_roles_matrix() {
        $roles = $this->get_all_roles();
        $pages = get_pages( [ 'post_status' => [ 'publish', 'private' ], 'number' => 0, 'sort_column' => 'post_title', 'sort_order' => 'asc' ] );
        ?>
        <div class="evsec-table-wrap"><table class="widefat striped evsec-role-table"><thead><tr><th>Rol</th><th>Puede iniciar sesión</th><th>Acceso a /wp-admin</th><th>Página después del login</th></tr></thead><tbody>
        <?php foreach ( $roles as $role => $label ) : $rule = $this->get_role_rule( $role ); ?>
            <tr>
                <td><strong><?php echo esc_html( $label ); ?></strong><br><code><?php echo esc_html( $role ); ?></code></td>
                <td><input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[roles][<?php echo esc_attr( $role ); ?>][enabled]" value="0"><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[roles][<?php echo esc_attr( $role ); ?>][enabled]" value="1" <?php checked( 1, $rule['enabled'] ); ?>> Activo</label></td>
                <td><input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[roles][<?php echo esc_attr( $role ); ?>][backend]" value="0"><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[roles][<?php echo esc_attr( $role ); ?>][backend]" value="1" <?php checked( 1, $rule['backend'] ); ?>> Permitido</label></td>
                <td><select name="<?php echo esc_attr( self::OPTION ); ?>[roles][<?php echo esc_attr( $role ); ?>][redirect_page]"><option value="0">Dashboard de EventosApp</option><?php foreach ( $pages as $page ) printf( '<option value="%d"%s>%s</option>', $page->ID, selected( $rule['redirect_page'], $page->ID, false ), esc_html( $page->post_title ) ); ?></select></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php
    }

    private function render_scan_result() {
        $scan = get_option( self::SCAN, [] );
        if ( ! is_array( $scan ) || empty( $scan['date'] ) ) {
            echo '<div class="evsec-empty">Aún no se ha ejecutado un diagnóstico manual.</div>';
            return;
        }
        $findings = isset( $scan['findings'] ) && is_array( $scan['findings'] ) ? $scan['findings'] : [];
        ?>
        <div class="evsec-scan-summary <?php echo $findings ? 'has-findings' : 'is-clean'; ?>">
            <strong><?php echo $findings ? esc_html( count( $findings ) . ' hallazgo(s) para revisar' ) : 'Sin firmas de alto riesgo detectadas'; ?></strong>
            <span><?php echo absint( $scan['files_scanned'] ?? 0 ); ?> archivos PHP revisados · <?php echo esc_html( $scan['duration'] ?? 0 ); ?> s · <?php echo esc_html( $scan['date'] ); ?></span>
        </div>
        <?php if ( ! empty( $scan['limit_reached'] ) ) : ?><p class="notice-inline">El diagnóstico alcanzó el límite de 3.000 archivos. Revisa manualmente los directorios restantes.</p><?php endif; ?>
        <?php if ( $findings ) : ?>
            <div class="evsec-table-wrap"><table class="widefat striped"><thead><tr><th>Severidad</th><th>Ubicación</th><th>Archivo</th><th>Regla</th><th>Modificado (UTC)</th><th>SHA-256</th></tr></thead><tbody>
            <?php foreach ( $findings as $finding ) : ?>
                <tr><td><span class="evsec-badge is-<?php echo esc_attr( $finding['severity'] ?? 'media' ); ?>"><?php echo esc_html( $finding['severity'] ?? 'media' ); ?></span></td><td><?php echo esc_html( $finding['root'] ?? '' ); ?></td><td><code><?php echo esc_html( $finding['file'] ?? '' ); ?></code></td><td><?php echo esc_html( $finding['rule'] ?? '' ); ?></td><td><?php echo esc_html( $finding['modified'] ?? '' ); ?></td><td><code class="evsec-hash"><?php echo esc_html( $finding['sha256'] ?? '' ); ?></code></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
        <?php if ( ! empty( $scan['errors'] ) ) : ?><details><summary>Errores de lectura</summary><ul><?php foreach ( $scan['errors'] as $error ) echo '<li>' . esc_html( $error ) . '</li>'; ?></ul></details><?php endif; ?>
        <p class="description">Este diagnóstico usa firmas de alto riesgo y no reemplaza un antivirus del servidor, un EDR ni un análisis externo. Un resultado limpio no garantiza ausencia total de malware.</p>
        <?php
    }

    private function render_audit_log() {
        $log = get_option( self::AUDIT, [] );
        if ( ! is_array( $log ) ) $log = [];
        ?>
        <div class="evsec-table-wrap"><table class="widefat striped"><thead><tr><th>Fecha/hora</th><th>Usuario</th><th>ID</th><th>IP</th><th>Resultado</th><th>Detalle</th></tr></thead><tbody>
        <?php if ( empty( $log ) ) : ?><tr><td colspan="6">No hay registros de acceso todavía.</td></tr><?php else : foreach ( $log as $row ) : ?>
            <tr><td><?php echo esc_html( $row['date'] ?? '' ); ?></td><td><?php echo esc_html( $row['user'] ?? '' ); ?></td><td><?php echo ! empty( $row['user_id'] ) ? absint( $row['user_id'] ) : '—'; ?></td><td><code><?php echo esc_html( $row['ip'] ?? '' ); ?></code></td><td><span class="evsec-badge <?php echo ! empty( $row['success'] ) ? 'is-ok' : 'is-alta'; ?>"><?php echo ! empty( $row['success'] ) ? 'Correcto' : 'Fallido'; ?></span></td><td><?php echo esc_html( $row['reason'] ?? '' ); ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody></table></div>
        <?php
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Acceso no autorizado.' );
        $scan = get_option( self::SCAN, [] );
        $scan_findings = is_array( $scan ) && ! empty( $scan['findings'] ) && is_array( $scan['findings'] ) ? count( $scan['findings'] ) : 0;
        ?>
        <div class="wrap evsec-wrap">
            <style>
            .evsec-wrap{max-width:1450px}.evsec-hero{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin:18px 0 18px;padding:24px;border-radius:18px;background:linear-gradient(135deg,#172334,#255f96);color:#fff}.evsec-hero h1{color:#fff;margin:0 0 7px}.evsec-hero p{margin:0;color:#dbeafe;max-width:820px;line-height:1.55}.evsec-nav{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 18px}.evsec-nav a{padding:8px 12px;border-radius:999px;background:#fff;border:1px solid #dcdcde;text-decoration:none;font-weight:700}.evsec-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 18px}.evsec-stat,.evsec-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;box-shadow:0 6px 18px rgba(31,35,40,.04)}.evsec-stat{padding:16px}.evsec-stat strong{display:block;font-size:22px;margin-bottom:4px}.evsec-stat span{color:#646970;font-size:12px}.evsec-card{padding:22px;margin:0 0 18px;scroll-margin-top:42px}.evsec-card h2{margin:0 0 6px}.evsec-card>p:first-of-type{color:#646970}.evsec-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.evsec-field{padding:14px;border:1px solid #e2e4e7;border-radius:11px;background:#fafafa}.evsec-field label.evsec-label{display:block;font-weight:700;margin-bottom:7px}.evsec-field input[type=text],.evsec-field input[type=password],.evsec-field input[type=number],.evsec-field select{width:100%;max-width:100%}.evsec-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.evsec-check{padding:12px;border:1px solid #e2e4e7;border-radius:10px;background:#fafafa}.evsec-table-wrap{overflow:auto;width:100%;border:1px solid #e2e4e7;border-radius:11px;margin-top:14px}.evsec-table-wrap table{border:0!important;min-width:850px}.evsec-role-table{min-width:1050px!important}.evsec-role-table select{min-width:280px}.evsec-badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:800;text-transform:uppercase}.evsec-badge.is-ok{background:#edfaef;color:#116329}.evsec-badge.is-alta{background:#fcf0f1;color:#8a2424}.evsec-badge.is-media{background:#fff8e5;color:#8a4b00}.evsec-scan-summary{display:grid;gap:4px;padding:14px;border-radius:11px;margin-top:14px}.evsec-scan-summary.is-clean{background:#edfaef;border:1px solid #b8dfc1;color:#116329}.evsec-scan-summary.has-findings{background:#fff8e5;border:1px solid #f0d58c;color:#7a4600}.evsec-scan-summary span{font-size:12px}.evsec-empty{padding:16px;background:#f6f7f7;border-radius:10px;color:#646970}.evsec-hash{font-size:10px;word-break:break-all}.evsec-warning{padding:13px 14px;border-left:4px solid #dba617;background:#fff8e5;margin:14px 0}.evsec-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.evsec-actions form{margin:0}.notice-inline{padding:10px 12px;background:#fff8e5;border-radius:8px}.evsec-note{padding:14px;border-radius:10px;background:#f0f6fc;border:1px solid #c5d9ed;color:#174c78;line-height:1.55}
            @media(max-width:1100px){.evsec-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.evsec-form-grid,.evsec-checks{grid-template-columns:1fr}}@media(max-width:782px){.evsec-hero{display:block;padding:18px}.evsec-grid{grid-template-columns:1fr}.evsec-card{padding:16px}.evsec-nav{position:relative;overflow:auto;flex-wrap:nowrap;padding-bottom:4px}.evsec-nav a{white-space:nowrap}}
            </style>

            <div class="evsec-hero"><div><h1>Seguridad de EventosApp</h1><p>Acceso, roles, auditoría y hardening integrados. Esta capa reemplaza Custom Login Basic y añade controles pensados para la operación de eventos sin limitar de forma global el tráfico de check-in.</p></div><span class="dashicons dashicons-shield" style="font-size:48px;width:48px;height:48px"></span></div>

            <div class="evsec-nav"><a href="#acceso">Acceso y roles</a><a href="#antispam">Anti-spam y hardening</a><a href="#antiddos">Anti-DDoS / abuso</a><a href="#malware">Anti-malware</a><a href="#auditoria">Auditoría</a></div>

            <div class="evsec-grid">
                <div class="evsec-stat"><strong>Bloqueado</strong><span>Login nativo /wp-login.php</span></div>
                <div class="evsec-stat"><strong>Oculta</strong><span>Barra de WordPress en frontend para todos</span></div>
                <div class="evsec-stat"><strong><?php echo absint( $this->settings['max_attempts'] ?? 5 ); ?></strong><span>Intentos antes del bloqueo temporal</span></div>
                <div class="evsec-stat"><strong><?php echo $scan_findings ? absint( $scan_findings ) : '0'; ?></strong><span>Hallazgos del último diagnóstico</span></div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'eventosapp_security_group' ); ?>
                <input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[_context]" value="full">

                <section class="evsec-card" id="acceso"><h2>Acceso y control por roles</h2><p>Configura el login integrado, reCAPTCHA, protección CSRF y qué roles pueden autenticarse o entrar al backend.</p>
                    <div class="evsec-form-grid">
                        <div class="evsec-field"><label class="evsec-label">Página de inicio de sesión</label><?php $this->render_page_select_control( 'login_page_id' ); ?><p class="description">Debe contener <code>[custom_login_basic]</code>.</p></div>
                        <div class="evsec-field"><label class="evsec-label">Página 404</label><?php $this->render_page_select_control( 'error_404_page_id' ); ?><p class="description">Debe contener <code>[eventosapp_404]</code>.</p></div>
                        <div class="evsec-field"><label class="evsec-label">Google reCAPTCHA v2 · Site Key</label><input type="text" name="<?php echo esc_attr( self::OPTION ); ?>[recaptcha_site_key]" value="<?php echo esc_attr( $this->settings['recaptcha_site_key'] ?? '' ); ?>" autocomplete="off"><p class="description">Usa la versión “No soy un robot”.</p></div>
                        <div class="evsec-field"><label class="evsec-label">Google reCAPTCHA v2 · Secret Key</label><input type="password" name="<?php echo esc_attr( self::OPTION ); ?>[recaptcha_secret_key]" value="<?php echo esc_attr( $this->settings['recaptcha_secret_key'] ?? '' ); ?>" autocomplete="new-password"><p class="description">La clave se almacena como opción de WordPress.</p></div>
                    </div>
                    <div class="evsec-checks" style="margin-top:14px"><label class="evsec-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enable_nonce]" value="1" <?php checked( 1, ! empty( $this->settings['enable_nonce'] ) ); ?>> <strong>Protección CSRF (nonce)</strong><br><span class="description">Requerida en el formulario de acceso.</span></label><label class="evsec-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enable_attempts]" value="1" <?php checked( 1, ! empty( $this->settings['enable_attempts'] ) ); ?>> <strong>Limitar intentos de login</strong><br><span class="description">Bloqueo por combinación IP + usuario.</span></label></div>
                    <h3 style="margin-top:22px">Matriz de roles</h3><?php $this->render_roles_matrix(); ?>
                </section>

                <section class="evsec-card" id="antispam"><h2>Anti-spam y hardening</h2><p>Reduce vectores comunes de enumeración, pingbacks y automatizaciones no deseadas sin desactivar funciones propias de EventosApp.</p>
                    <div class="evsec-checks">
                        <label class="evsec-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[block_author_enum]" value="1" <?php checked( 1, ! empty( $this->settings['block_author_enum'] ) ); ?>> <strong>Bloquear enumeración por author</strong><br><span class="description">Rechaza <code>?author=</code> y <code>?author_name=</code>.</span></label>
                        <label class="evsec-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[block_rest_user_enum]" value="1" <?php checked( 1, ! empty( $this->settings['block_rest_user_enum'] ) ); ?>> <strong>Ocultar usuarios en REST para visitantes</strong><br><span class="description">Mantiene los endpoints disponibles para usuarios autenticados.</span></label>
                        <label class="evsec-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[disable_xmlrpc]" value="1" <?php checked( 1, ! empty( $this->settings['disable_xmlrpc'] ) ); ?>> <strong>Desactivar XML-RPC</strong><br><span class="description">Reduce ataques de fuerza bruta y abuso histórico de XML-RPC.</span></label>
                        <label class="evsec-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[disable_pingbacks]" value="1" <?php checked( 1, ! empty( $this->settings['disable_pingbacks'] ) ); ?>> <strong>Desactivar pingbacks/trackbacks</strong><br><span class="description">Elimina X-Pingback y métodos asociados.</span></label>
                        <label class="evsec-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[comment_honeypot]" value="1" <?php checked( 1, ! empty( $this->settings['comment_honeypot'] ) ); ?>> <strong>Honeypot para comentarios</strong><br><span class="description">Campo invisible para detectar bots básicos.</span></label>
                        <label class="evsec-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[clean_wp_head]" value="1" <?php checked( 1, ! empty( $this->settings['clean_wp_head'] ) ); ?>> <strong>Reducir huellas de WordPress</strong><br><span class="description">Quita generator, RSD, WLW, oEmbed y enlaces de descubrimiento innecesarios.</span></label>
                        <label class="evsec-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[security_headers]" value="1" <?php checked( 1, ! empty( $this->settings['security_headers'] ) ); ?>> <strong>Headers seguros compatibles</strong><br><span class="description">nosniff, Referrer-Policy y X-Permitted-Cross-Domain-Policies.</span></label>
                    </div>
                </section>

                <section class="evsec-card" id="antiddos"><h2>Anti-DDoS y control de abuso</h2><p>EventosApp aplica defensa de aplicación sobre el acceso sin imponer un límite global que pueda bloquear cientos de asistentes detrás del mismo Wi‑Fi o NAT.</p>
                    <div class="evsec-form-grid"><div class="evsec-field"><label class="evsec-label">Intentos máximos</label><input type="number" min="3" max="20" name="<?php echo esc_attr( self::OPTION ); ?>[max_attempts]" value="<?php echo absint( $this->settings['max_attempts'] ?? 5 ); ?>"></div><div class="evsec-field"><label class="evsec-label">Bloqueo temporal (minutos)</label><input type="number" min="1" max="120" name="<?php echo esc_attr( self::OPTION ); ?>[lock_minutes]" value="<?php echo absint( $this->settings['lock_minutes'] ?? 10 ); ?>"></div></div>
                    <div class="evsec-note" style="margin-top:14px"><strong>Importante:</strong> esta protección reduce fuerza bruta y abuso a nivel PHP, pero un ataque volumétrico debe detenerse antes de llegar al servidor. Para DDoS real se recomienda mantener firewall/WAF y protección de red en el proveedor o CDN. No se añadió un rate-limit global porque podría afectar el check-in masivo en eventos.</div>
                </section>

                <?php submit_button( 'Guardar configuración de seguridad' ); ?>
            </form>

            <section class="evsec-card" id="malware"><h2>Anti-malware · diagnóstico manual</h2><p>Escanea archivos PHP de EventosApp y del tema activo buscando firmas de alto riesgo. No modifica, elimina ni pone archivos en cuarentena automáticamente.</p>
                <div class="evsec-actions"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="eventosapp_security_scan"><?php wp_nonce_field( 'eventosapp_security_scan', 'eventosapp_security_scan_nonce' ); ?><button class="button button-primary" type="submit">Ejecutar diagnóstico ahora</button></form></div>
                <?php $this->render_scan_result(); ?>
            </section>

            <section class="evsec-card" id="auditoria"><h2>Auditoría de inicios de sesión</h2><p>Conserva los últimos <?php echo absint( self::AUDIT_LIMIT ); ?> intentos gestionados por el login integrado, con resultado, usuario e IP.</p>
                <?php $this->render_audit_log(); ?>
                <div class="evsec-actions" style="margin-top:14px"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('¿Eliminar todo el registro de auditoría de accesos?');"><input type="hidden" name="action" value="eventosapp_security_clear_audit"><?php wp_nonce_field( 'eventosapp_security_clear_audit', 'eventosapp_security_clear_audit_nonce' ); ?><button class="button" type="submit">Limpiar auditoría</button></form></div>
            </section>
        </div>
        <?php
    }
}
}

if ( ! isset( $GLOBALS['eventosapp_security'] ) || ! $GLOBALS['eventosapp_security'] instanceof EventosApp_Security ) {
    $GLOBALS['eventosapp_security'] = new EventosApp_Security();
}
