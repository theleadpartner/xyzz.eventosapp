<?php
/**
 * EventosApp – Diagnóstico de conexión con la aplicación Android de Kiosko.
 *
 * Esta pantalla no modifica configuración ni credenciales. Comprueba la carga
 * del archivo API, el registro de rutas REST y el acceso HTTP en las dos formas
 * compatibles con WordPress: /wp-json/ y ?rest_route=.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'eventosapp_kiosk_diagnostics_page_url' ) ) {
    function eventosapp_kiosk_diagnostics_page_url() {
        return admin_url( 'admin.php?page=eventosapp_kiosk_android' );
    }
}

add_action( 'admin_menu', static function () {
    add_submenu_page(
        'eventosapp_dashboard',
        'Kiosko Android',
        'Kiosko Android',
        'manage_options',
        'eventosapp_kiosk_android',
        'eventosapp_kiosk_render_diagnostics_page'
    );
}, 95 );

add_action( 'admin_notices', static function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && strpos( (string) $screen->id, 'eventosapp_kiosk_android' ) !== false ) {
        return;
    }

    $api_exists = file_exists( EVENTOSAPP_PLUGIN_PATH . 'includes/api/eventosapp-kiosk-api.php' )
        || file_exists( EVENTOSAPP_PLUGIN_PATH . 'eventosapp-kiosk-api.php' );
    $api_loaded = defined( 'EVENTOSAPP_KIOSK_API_LOADED' )
        && EVENTOSAPP_KIOSK_API_LOADED
        && function_exists( 'eventosapp_kiosk_api_login' );

    if ( $api_exists && ! $api_loaded ) {
        printf(
            '<div class="notice notice-error"><p><strong>EventosApp Kiosko Android:</strong> el archivo de la API existe, pero no fue cargado por el plugin activo. <a href="%s">Abrir diagnóstico</a>.</p></div>',
            esc_url( eventosapp_kiosk_diagnostics_page_url() )
        );
    }
} );

if ( ! function_exists( 'eventosapp_kiosk_diagnostics_rest_urls' ) ) {
    function eventosapp_kiosk_diagnostics_rest_urls( $route ) {
        $route = '/' . ltrim( (string) $route, '/' );
        $namespace = defined( 'EVENTOSAPP_KIOSK_API_NAMESPACE' )
            ? EVENTOSAPP_KIOSK_API_NAMESPACE
            : 'eventosapp-kiosk/v1';
        $path = $namespace . $route;

        if ( function_exists( 'eventosapp_kiosk_api_route_urls' ) ) {
            return eventosapp_kiosk_api_route_urls( $route );
        }

        return [
            'pretty' => rest_url( $path ),
            'query'  => add_query_arg(
                'rest_route',
                '/' . $path,
                trailingslashit( home_url( '/' ) )
            ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_kiosk_diagnostics_http_probe' ) ) {
    function eventosapp_kiosk_diagnostics_http_probe( $url, $method = 'GET', $body = null ) {
        $method = strtoupper( (string) $method );
        $args = [
            'method'      => $method,
            'timeout'     => 12,
            'redirection' => 0,
            'sslverify'   => true,
            'headers'     => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
                'User-Agent'   => 'EventosApp-Kiosk-Diagnostics/1.1',
            ],
        ];

        if ( $body !== null ) {
            $args['body'] = wp_json_encode( $body );
        }

        $started  = microtime( true );
        $response = wp_remote_request( $url, $args );
        $elapsed  = round( ( microtime( true ) - $started ) * 1000 );

        if ( is_wp_error( $response ) ) {
            return [
                'ok'          => false,
                'status'      => 0,
                'code'        => $response->get_error_code(),
                'message'     => $response->get_error_message(),
                'location'    => '',
                'elapsed_ms'  => $elapsed,
                'url'         => $url,
                'method'      => $method,
                'json'        => [],
            ];
        }

        $status   = (int) wp_remote_retrieve_response_code( $response );
        $raw_body = (string) wp_remote_retrieve_body( $response );
        $json     = json_decode( $raw_body, true );
        if ( ! is_array( $json ) ) {
            $json = [];
        }

        return [
            'ok'          => $status >= 200 && $status < 300,
            'status'      => $status,
            'code'        => sanitize_text_field( $json['code'] ?? '' ),
            'message'     => sanitize_text_field( $json['message'] ?? wp_remote_retrieve_response_message( $response ) ),
            'location'    => esc_url_raw( wp_remote_retrieve_header( $response, 'location' ) ),
            'elapsed_ms'  => $elapsed,
            'url'         => $url,
            'method'      => $method,
            'json'        => $json,
        ];
    }
}

if ( ! function_exists( 'eventosapp_kiosk_diagnostics_probe_is_healthy' ) ) {
    function eventosapp_kiosk_diagnostics_probe_is_healthy( $probe, $type ) {
        if ( ! is_array( $probe ) ) {
            return false;
        }

        if ( $type === 'health' ) {
            return (int) ( $probe['status'] ?? 0 ) === 200
                && ! empty( $probe['json']['loaded'] )
                && ( $probe['json']['namespace'] ?? '' ) === 'eventosapp-kiosk/v1';
        }

        if ( $type === 'login' ) {
            // Una petición vacía debe alcanzar el callback y responder
            // missing_credentials. No valida ni bloquea ningún usuario.
            return (int) ( $probe['status'] ?? 0 ) === 400
                && ( $probe['code'] ?? '' ) === 'missing_credentials';
        }

        return false;
    }
}

if ( ! function_exists( 'eventosapp_kiosk_diagnostics_collect' ) ) {
    function eventosapp_kiosk_diagnostics_collect() {
        $canonical_api = EVENTOSAPP_PLUGIN_PATH . 'includes/api/eventosapp-kiosk-api.php';
        $fallback_api  = EVENTOSAPP_PLUGIN_PATH . 'eventosapp-kiosk-api.php';
        $main_file     = EVENTOSAPP_PLUGIN_PATH . 'eventosapp.php';

        $api_exists = file_exists( $canonical_api ) || file_exists( $fallback_api );
        $api_loaded = defined( 'EVENTOSAPP_KIOSK_API_LOADED' )
            && EVENTOSAPP_KIOSK_API_LOADED
            && function_exists( 'eventosapp_kiosk_api_login' );

        $main_contents = is_readable( $main_file ) ? file_get_contents( $main_file ) : '';
        $include_declared = is_string( $main_contents )
            && strpos( $main_contents, 'includes/api/eventosapp-kiosk-api.php' ) !== false;
        $diagnostics_declared = is_string( $main_contents )
            && strpos( $main_contents, 'includes/admin/eventosapp-kiosk-diagnostics.php' ) !== false;

        $routes = [];
        if ( $api_loaded ) {
            $server = rest_get_server();
            $registered = $server->get_routes();
            $required = [
                '/health'                      => 'GET',
                '/auth/login'                  => 'POST',
                '/auth/logout'                 => 'POST',
                '/events'                      => 'GET',
                '/events/(?P<id>\\d+)'        => 'GET',
                '/events/(?P<id>\\d+)/search' => 'POST',
                '/tickets/(?P<id>\\d+)'       => 'GET',
                '/tickets/(?P<id>\\d+)/print' => 'POST',
                '/badge/(?P<id>\\d+)'         => 'GET',
            ];

            foreach ( $required as $suffix => $method ) {
                $full = '/eventosapp-kiosk/v1' . $suffix;
                $routes[] = [
                    'route'      => $full,
                    'method'     => $method,
                    'registered' => isset( $registered[ $full ] ),
                ];
            }
        }

        $health_urls = eventosapp_kiosk_diagnostics_rest_urls( '/health' );
        $login_urls  = eventosapp_kiosk_diagnostics_rest_urls( '/auth/login' );

        $probes = [
            'health_pretty' => eventosapp_kiosk_diagnostics_http_probe( $health_urls['pretty'], 'GET' ),
            'health_query'  => eventosapp_kiosk_diagnostics_http_probe( $health_urls['query'], 'GET' ),
            'login_pretty'  => eventosapp_kiosk_diagnostics_http_probe( $login_urls['pretty'], 'POST', [] ),
            'login_query'   => eventosapp_kiosk_diagnostics_http_probe( $login_urls['query'], 'POST', [] ),
        ];

        $health_ok = eventosapp_kiosk_diagnostics_probe_is_healthy( $probes['health_pretty'], 'health' )
            || eventosapp_kiosk_diagnostics_probe_is_healthy( $probes['health_query'], 'health' );
        $login_ok = eventosapp_kiosk_diagnostics_probe_is_healthy( $probes['login_pretty'], 'login' )
            || eventosapp_kiosk_diagnostics_probe_is_healthy( $probes['login_query'], 'login' );
        $routes_ok = ! empty( $routes ) && ! in_array( false, wp_list_pluck( $routes, 'registered' ), true );

        $dependency_names = [
            'eventosapp_role_can'                             => 'Permisos de roles',
            'eventosapp_self_checkin_ticket_payload'          => 'Datos de ticket',
            'eventosapp_get_badge_html_from_event'            => 'Generador de escarapela',
        ];
        $dependencies = [];
        foreach ( $dependency_names as $function => $label ) {
            $dependencies[] = [
                'label'     => $label,
                'function'  => $function,
                'available' => function_exists( $function ),
            ];
        }
        $dependencies[] = [
            'label'     => 'Búsqueda de asistentes',
            'function'  => 'eventosapp_self_checkin_find_tickets_by_auth_field / identifier',
            'available' => function_exists( 'eventosapp_self_checkin_find_tickets_by_auth_field' )
                || function_exists( 'eventosapp_self_checkin_find_tickets_by_identifier' ),
        ];
        $dependencies[] = [
            'label'     => 'Registro de check-in',
            'function'  => 'eventosapp_register_ticket_checkin / self_checkin_mark_ticket',
            'available' => function_exists( 'eventosapp_register_ticket_checkin' )
                || function_exists( 'eventosapp_self_checkin_mark_ticket' ),
        ];

        $enabled_events = get_posts( [
            'post_type'              => 'eventosapp_event',
            'post_status'            => [ 'publish', 'private', 'draft', 'pending', 'future' ],
            'fields'                 => 'ids',
            'posts_per_page'         => -1,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => [
                [
                    'key'     => '_eventosapp_self_checkin_enabled',
                    'value'   => '1',
                    'compare' => '=',
                ],
            ],
        ] );

        return [
            'overall_ok'           => $api_exists && $api_loaded && $include_declared && $routes_ok && $health_ok && $login_ok,
            'api_exists'           => $api_exists,
            'api_loaded'           => $api_loaded,
            'api_version'          => defined( 'EVENTOSAPP_KIOSK_API_VERSION' ) ? EVENTOSAPP_KIOSK_API_VERSION : 'No disponible',
            'api_loaded_file'      => defined( 'EVENTOSAPP_KIOSK_API_FILE' ) ? EVENTOSAPP_KIOSK_API_FILE : '',
            'canonical_api'        => $canonical_api,
            'canonical_exists'     => file_exists( $canonical_api ),
            'fallback_api'         => $fallback_api,
            'fallback_exists'      => file_exists( $fallback_api ),
            'main_file'            => $main_file,
            'include_declared'     => $include_declared,
            'diagnostics_declared' => $diagnostics_declared,
            'routes'               => $routes,
            'routes_ok'            => $routes_ok,
            'dependencies'         => $dependencies,
            'health_ok'            => $health_ok,
            'login_ok'             => $login_ok,
            'probes'               => $probes,
            'server_url'           => untrailingslashit( home_url( '/' ) ),
            'rest_index_url'       => rest_url(),
            'enabled_events'       => count( $enabled_events ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_kiosk_diagnostics_status_badge' ) ) {
    function eventosapp_kiosk_diagnostics_status_badge( $ok, $yes = 'Correcto', $no = 'Revisar' ) {
        $class = $ok ? 'evk-ok' : 'evk-error';
        $text  = $ok ? $yes : $no;
        return '<span class="evk-badge ' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
    }
}

if ( ! function_exists( 'eventosapp_kiosk_diagnostics_probe_label' ) ) {
    function eventosapp_kiosk_diagnostics_probe_label( $key ) {
        $labels = [
            'health_pretty' => 'Health por /wp-json/',
            'health_query'  => 'Health por ?rest_route=',
            'login_pretty'  => 'Login POST por /wp-json/',
            'login_query'   => 'Login POST por ?rest_route=',
        ];
        return $labels[ $key ] ?? $key;
    }
}

if ( ! function_exists( 'eventosapp_kiosk_render_diagnostics_page' ) ) {
    function eventosapp_kiosk_render_diagnostics_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para acceder a esta pantalla.', 'eventosapp' ) );
        }

        $report = eventosapp_kiosk_diagnostics_collect();
        $all_dependencies = ! in_array( false, wp_list_pluck( $report['dependencies'], 'available' ), true );
        ?>
        <div class="wrap evk-wrap">
            <h1>Kiosko Android</h1>
            <p class="evk-lead">Diagnóstico de la conexión entre esta instalación de EventosApp y la aplicación Android de autogestión.</p>

            <style>
                .evk-wrap{max-width:1180px}.evk-lead{font-size:15px;color:#50575e;margin-bottom:18px}
                .evk-summary{display:flex;gap:16px;align-items:center;padding:18px 20px;border-radius:10px;margin:16px 0 20px;border:1px solid}
                .evk-summary.ok{background:#edfaef;border-color:#72c983}.evk-summary.error{background:#fff4f4;border-color:#e38b8b}
                .evk-summary h2{margin:0 0 4px;font-size:18px}.evk-summary p{margin:0;color:#3c434a}
                .evk-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}
                .evk-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
                .evk-card h2{margin:0 0 14px;font-size:17px}.evk-card h3{font-size:14px;margin:18px 0 8px}
                .evk-row{display:grid;grid-template-columns:minmax(180px,.8fr) minmax(0,1.4fr);gap:16px;padding:9px 0;border-bottom:1px solid #f0f0f1;align-items:start}
                .evk-row:last-child{border-bottom:0}.evk-row strong{color:#1d2327}.evk-value{word-break:break-word}
                .evk-badge{display:inline-block;border-radius:999px;padding:3px 9px;font-size:12px;font-weight:600}.evk-ok{background:#dff5e3;color:#146c2e}.evk-error{background:#fde3e3;color:#a12622}.evk-warn{background:#fff1c7;color:#7a4b00}
                .evk-table{width:100%;border-collapse:collapse}.evk-table th,.evk-table td{text-align:left;padding:9px 10px;border-bottom:1px solid #e5e7eb;vertical-align:top}.evk-table th{background:#f6f7f7}
                .evk-code{font-family:monospace;background:#f6f7f7;border:1px solid #dcdcde;border-radius:5px;padding:6px 8px;display:block;word-break:break-all}
                .evk-copy{margin-top:8px}.evk-actions{display:flex;gap:10px;flex-wrap:wrap;margin:18px 0}
                .evk-help{background:#f0f6fc;border-left:4px solid #2271b1;padding:14px 16px;margin-top:16px}.evk-help ol{margin-bottom:0}
                @media(max-width:800px){.evk-grid{grid-template-columns:1fr}.evk-row{grid-template-columns:1fr;gap:4px}}
            </style>

            <div class="evk-summary <?php echo $report['overall_ok'] ? 'ok' : 'error'; ?>">
                <span class="dashicons <?php echo $report['overall_ok'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" style="font-size:30px;width:30px;height:30px"></span>
                <div>
                    <h2><?php echo $report['overall_ok'] ? 'La API del kiosko está disponible' : 'La conexión del kiosko necesita corrección'; ?></h2>
                    <p><?php echo $report['overall_ok']
                        ? 'Android puede localizar las rutas REST y alcanzar el callback de inicio de sesión.'
                        : 'El diagnóstico identifica por qué WordPress responde que no existe una ruta compatible.'; ?></p>
                </div>
            </div>

            <div class="evk-actions">
                <a class="button button-primary" href="<?php echo esc_url( eventosapp_kiosk_diagnostics_page_url() ); ?>">Ejecutar diagnóstico nuevamente</a>
                <a class="button" href="<?php echo esc_url( $report['rest_index_url'] ); ?>" target="_blank" rel="noopener">Abrir índice REST</a>
            </div>

            <div class="evk-grid">
                <section class="evk-card">
                    <h2>Configuración para la aplicación</h2>
                    <div class="evk-row"><strong>URL del servidor</strong><div class="evk-value"><code class="evk-code" id="evk-server-url"><?php echo esc_html( $report['server_url'] ); ?></code><button type="button" class="button evk-copy" data-copy="evk-server-url">Copiar URL</button></div></div>
                    <div class="evk-row"><strong>Namespace REST</strong><div><code>eventosapp-kiosk/v1</code></div></div>
                    <div class="evk-row"><strong>Versión de API</strong><div><?php echo esc_html( $report['api_version'] ); ?></div></div>
                    <div class="evk-row"><strong>Eventos habilitados</strong><div><?php echo esc_html( $report['enabled_events'] ); ?></div></div>
                    <p><strong>Importante:</strong> en Android escribe únicamente la URL del sitio. No agregues <code>/wp-json/</code>, <code>/auth/login</code> ni una URL de administración.</p>
                </section>

                <section class="evk-card">
                    <h2>Carga de archivos</h2>
                    <div class="evk-row"><strong>Archivo API encontrado</strong><div><?php echo eventosapp_kiosk_diagnostics_status_badge( $report['api_exists'] ); ?></div></div>
                    <div class="evk-row"><strong>API ejecutada por WordPress</strong><div><?php echo eventosapp_kiosk_diagnostics_status_badge( $report['api_loaded'], 'Cargada', 'No cargada' ); ?></div></div>
                    <div class="evk-row"><strong>Carga declarada en eventosapp.php</strong><div><?php echo eventosapp_kiosk_diagnostics_status_badge( $report['include_declared'] ); ?></div></div>
                    <div class="evk-row"><strong>Panel declarado en eventosapp.php</strong><div><?php echo eventosapp_kiosk_diagnostics_status_badge( $report['diagnostics_declared'] ); ?></div></div>
                    <div class="evk-row"><strong>Archivo utilizado</strong><div class="evk-value"><code><?php echo esc_html( $report['api_loaded_file'] ?: 'No identificado' ); ?></code></div></div>
                    <div class="evk-row"><strong>Ruta recomendada</strong><div class="evk-value"><code><?php echo esc_html( $report['canonical_api'] ); ?></code></div></div>
                </section>
            </div>

            <section class="evk-card" style="margin-bottom:16px">
                <h2>Rutas registradas dentro de WordPress</h2>
                <?php if ( empty( $report['routes'] ) ) : ?>
                    <p><?php echo eventosapp_kiosk_diagnostics_status_badge( false, '', 'No fue posible consultar rutas porque la API no está cargada' ); ?></p>
                <?php else : ?>
                    <table class="evk-table">
                        <thead><tr><th>Método</th><th>Ruta</th><th>Estado</th></tr></thead>
                        <tbody>
                        <?php foreach ( $report['routes'] as $route ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $route['method'] ); ?></code></td>
                                <td><code><?php echo esc_html( $route['route'] ); ?></code></td>
                                <td><?php echo eventosapp_kiosk_diagnostics_status_badge( $route['registered'], 'Registrada', 'Ausente' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="evk-card" style="margin-bottom:16px">
                <h2>Prueba HTTP desde el servidor</h2>
                <p>La prueba de login se envía sin usuario ni contraseña. El resultado esperado es HTTP 400 con código <code>missing_credentials</code>; eso confirma que la petición POST llegó al callback correcto sin afectar cuentas ni límites de acceso.</p>
                <table class="evk-table">
                    <thead><tr><th>Prueba</th><th>Respuesta</th><th>URL utilizada</th><th>Resultado</th></tr></thead>
                    <tbody>
                    <?php foreach ( $report['probes'] as $key => $probe ) :
                        $type = strpos( $key, 'health' ) === 0 ? 'health' : 'login';
                        $ok = eventosapp_kiosk_diagnostics_probe_is_healthy( $probe, $type );
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( eventosapp_kiosk_diagnostics_probe_label( $key ) ); ?></strong><br><code><?php echo esc_html( $probe['method'] ); ?></code></td>
                            <td>
                                HTTP <?php echo esc_html( $probe['status'] ); ?>
                                <?php if ( ! empty( $probe['code'] ) ) : ?><br><code><?php echo esc_html( $probe['code'] ); ?></code><?php endif; ?>
                                <?php if ( ! empty( $probe['location'] ) ) : ?><br>Redirección: <code><?php echo esc_html( $probe['location'] ); ?></code><?php endif; ?>
                                <?php if ( ! empty( $probe['message'] ) ) : ?><br><?php echo esc_html( $probe['message'] ); ?><?php endif; ?>
                                <br><small><?php echo esc_html( $probe['elapsed_ms'] ); ?> ms</small>
                            </td>
                            <td><code class="evk-code"><?php echo esc_html( $probe['url'] ); ?></code></td>
                            <td><?php echo eventosapp_kiosk_diagnostics_status_badge( $ok, 'Disponible', 'Falló' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="evk-card">
                <h2>Dependencias del flujo de autogestión</h2>
                <table class="evk-table">
                    <thead><tr><th>Componente</th><th>Función</th><th>Estado</th></tr></thead>
                    <tbody>
                    <?php foreach ( $report['dependencies'] as $dependency ) : ?>
                        <tr>
                            <td><?php echo esc_html( $dependency['label'] ); ?></td>
                            <td><code><?php echo esc_html( $dependency['function'] ); ?></code></td>
                            <td><?php echo eventosapp_kiosk_diagnostics_status_badge( $dependency['available'], 'Disponible', 'No disponible' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( ! $report['overall_ok'] ) : ?>
                    <div class="evk-help">
                        <strong>Corrección recomendada según este error</strong>
                        <ol>
                            <?php if ( ! $report['api_exists'] ) : ?><li>Instala el archivo completo <code>includes/api/eventosapp-kiosk-api.php</code>.</li><?php endif; ?>
                            <?php if ( ! $report['include_declared'] || ! $report['api_loaded'] ) : ?><li>Reemplaza también <code>eventosapp.php</code>. Instalar solo el archivo API no registra ninguna ruta si el plugin principal no lo incluye.</li><?php endif; ?>
                            <?php if ( ! $report['routes_ok'] ) : ?><li>Confirma que no exista otro archivo antiguo con las mismas funciones y recarga el plugin.</li><?php endif; ?>
                            <?php if ( ! $report['health_ok'] || ! $report['login_ok'] ) : ?><li>Revisa redirecciones de dominio, caché, firewall o reglas que bloqueen <code>/wp-json/</code>. La app 2.0.1 también probará automáticamente <code>?rest_route=</code> y conservará POST durante redirecciones.</li><?php endif; ?>
                            <?php if ( ! $all_dependencies ) : ?><li>Actualiza los archivos de Autogestión Kiosko y escarapelas indicados como no disponibles.</li><?php endif; ?>
                        </ol>
                    </div>
                <?php endif; ?>
            </section>

            <script>
            document.addEventListener('click', function(event){
                var button = event.target.closest('[data-copy]');
                if(!button) return;
                var target = document.getElementById(button.getAttribute('data-copy'));
                if(!target) return;
                var text = target.textContent || '';
                if(navigator.clipboard && window.isSecureContext){
                    navigator.clipboard.writeText(text).then(function(){ button.textContent = 'Copiada'; });
                } else {
                    var area = document.createElement('textarea');
                    area.value = text; document.body.appendChild(area); area.select();
                    document.execCommand('copy'); area.remove(); button.textContent = 'Copiada';
                }
                setTimeout(function(){ button.textContent = 'Copiar URL'; }, 1500);
            });
            </script>
        </div>
        <?php
    }
}
