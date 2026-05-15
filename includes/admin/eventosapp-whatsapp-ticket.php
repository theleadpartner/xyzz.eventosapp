<?php
/**
 * EventosApp - Mensajería WhatsApp para tickets
 *
 * Integra WhatsApp Cloud API como canal adicional de entrega del ticket,
 * sin modificar la función existente de envío por correo. El envío se dispara
 * cuando el correo queda registrado como enviado o manualmente desde el ticket.
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! defined('EVENTOSAPP_WHATSAPP_OPTION') ) {
    define('EVENTOSAPP_WHATSAPP_OPTION', 'eventosapp_whatsapp_settings');
}

/**
 * Valores por defecto de la integración WhatsApp.
 */
function eventosapp_whatsapp_default_settings() {
    return [
        'enabled'                           => '0',
        'api_version'                       => 'v23.0',
        'phone_number_id'                   => '',
        'waba_id'                           => '',
        'access_token'                      => '',
        'default_country_code'              => '57',
        'request_timeout'                   => 20,
        'debug_log'                         => '0',
        'dry_run'                           => '0',

        // Prueba rápida.
        'test_phone'                        => '',
        'test_message_mode'                 => 'template',
        'test_template_name'                => 'hello_world',
        'test_template_language'            => 'en_US',

        // Webhook de estados.
        'webhook_verify_token'              => '',
        'last_test_result'                  => [],

        // Envío final de tickets.
        'ticket_delivery_mode'              => 'template',
        'template_category'                 => 'UTILITY',
        'template_presencial_name'          => 'eventosapp_ticket_presencial',
        'template_presencial_language'      => 'es',
        'template_presencial_header_handle' => '',
        'template_virtual_name'             => 'eventosapp_ticket_virtual',
        'template_virtual_language'         => 'es',
        'template_footer'                   => 'EventosApp',
        'last_template_submission'          => [],

        // Modo legacy / respaldo.
        'message_intro'                     => 'Hola {{nombre}}, este es tu ticket para {{evento_nombre}}.',
    ];
}

/**
 * Obtiene settings con fallback seguro.
 */
function eventosapp_whatsapp_get_settings() {
    $saved = get_option(EVENTOSAPP_WHATSAPP_OPTION, []);
    if ( ! is_array($saved) ) {
        $saved = [];
    }

    $settings = wp_parse_args($saved, eventosapp_whatsapp_default_settings());

    if ( empty($settings['webhook_verify_token']) ) {
        $settings['webhook_verify_token'] = wp_generate_password(32, false, false);
    }

    if ( empty($settings['ticket_delivery_mode']) || ! in_array($settings['ticket_delivery_mode'], ['template', 'legacy'], true) ) {
        $settings['ticket_delivery_mode'] = 'template';
    }

    return $settings;
}

/**
 * Guarda logs solo cuando está activo el modo debug.
 */
function eventosapp_whatsapp_log($message, $context = []) {
    $settings = eventosapp_whatsapp_get_settings();
    if ( isset($settings['debug_log']) && $settings['debug_log'] === '1' ) {
        $line = 'EVENTOSAPP WHATSAPP | ' . $message;
        if ( ! empty($context) ) {
            $line .= ' | ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        error_log($line);
    }
}

/**
 * Agrega sección de configuración global en el menú EventosApp.
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'eventosapp_dashboard',
        'WhatsApp Tickets',
        'WhatsApp Tickets',
        'manage_options',
        'eventosapp_whatsapp_tickets',
        'eventosapp_whatsapp_render_settings_page'
    );
}, 20);

/**
 * Render de página de configuración WhatsApp.
 */
function eventosapp_whatsapp_render_settings_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes para acceder a esta página.');
    }

    $settings = eventosapp_whatsapp_get_settings();
    $token_saved = ! empty($settings['access_token']);
    $webhook_url = admin_url('admin-post.php?action=eventosapp_whatsapp_webhook');
    $last_test = isset($settings['last_test_result']) && is_array($settings['last_test_result']) ? $settings['last_test_result'] : [];
    $last_template_submission = isset($settings['last_template_submission']) && is_array($settings['last_template_submission']) ? $settings['last_template_submission'] : [];
    $template_preview_presencial = eventosapp_whatsapp_get_template_body_text('presencial');
    $template_preview_virtual = eventosapp_whatsapp_get_template_body_text('virtual');
    ?>
    <div class="wrap eventosapp-whatsapp-settings">
        <h1>WhatsApp Tickets</h1>
        <p>
            Configura el envío de tickets por WhatsApp usando WhatsApp Cloud API de Meta.
            Para los envíos finales se usan plantillas aprobadas por Meta según la modalidad del ticket.
        </p>

        <?php if ( isset($_GET['evapp_whatsapp_saved']) ) : ?>
            <div class="notice notice-success is-dismissible"><p><strong>EventosApp:</strong> Configuración de WhatsApp guardada.</p></div>
        <?php endif; ?>

        <?php if ( isset($_GET['evapp_whatsapp_test']) ) :
            $ok = sanitize_text_field(wp_unslash($_GET['evapp_whatsapp_test'])) === '1';
            $msg = isset($_GET['evapp_whatsapp_msg']) ? sanitize_text_field(wp_unslash($_GET['evapp_whatsapp_msg'])) : ($ok ? 'Mensaje de prueba enviado.' : 'No se pudo enviar el mensaje de prueba.');
            ?>
            <div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><strong>EventosApp:</strong> <?php echo esc_html($msg); ?></p></div>
        <?php endif; ?>

        <?php if ( isset($_GET['evapp_whatsapp_templates']) ) :
            $ok = sanitize_text_field(wp_unslash($_GET['evapp_whatsapp_templates'])) === '1';
            $msg = isset($_GET['evapp_whatsapp_templates_msg']) ? sanitize_text_field(wp_unslash($_GET['evapp_whatsapp_templates_msg'])) : ($ok ? 'Solicitud enviada a Meta.' : 'No se pudo enviar la solicitud a Meta.');
            ?>
            <div class="notice <?php echo $ok ? 'notice-success' : 'notice-warning'; ?> is-dismissible"><p><strong>EventosApp:</strong> <?php echo esc_html($msg); ?></p></div>
        <?php endif; ?>

        <style>
            .evapp-wa-card{background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:18px;margin:18px 0;max-width:1080px;}
            .evapp-wa-card h2{margin-top:0;}
            .evapp-wa-grid{display:grid;grid-template-columns:240px minmax(280px,620px);gap:12px 18px;align-items:center;}
            .evapp-wa-grid label{font-weight:600;}
            .evapp-wa-grid input[type="text"],.evapp-wa-grid input[type="password"],.evapp-wa-grid input[type="number"],.evapp-wa-grid textarea,.evapp-wa-grid select{width:100%;}
            .evapp-wa-grid textarea{min-height:80px;}
            .evapp-wa-help{color:#646970;font-size:12px;margin:4px 0 0;}
            .evapp-wa-code{font-family:monospace;background:#f6f7f7;padding:2px 5px;border-radius:4px;}
            .evapp-wa-template-preview{white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px;font-family:monospace;font-size:12px;line-height:1.45;max-width:760px;}
            .evapp-wa-status-table{border-collapse:collapse;width:100%;max-width:1080px;background:#fff;margin-top:8px;}
            .evapp-wa-status-table th,.evapp-wa-status-table td{border:1px solid #dcdcde;padding:8px;text-align:left;vertical-align:top;}
            .evapp-wa-status-table th{background:#f6f7f7;width:240px;}
            .evapp-wa-warning{border-left:4px solid #dba617;background:#fff8e5;padding:10px;margin:12px 0;}
        </style>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('eventosapp_whatsapp_save_settings', 'eventosapp_whatsapp_settings_nonce'); ?>
            <input type="hidden" name="action" value="eventosapp_whatsapp_save_settings">

            <div class="evapp-wa-card">
                <h2>API de WhatsApp Cloud</h2>
                <div class="evapp-wa-grid">
                    <label for="evapp_wa_enabled">Activar integración global</label>
                    <div>
                        <label>
                            <input type="checkbox" id="evapp_wa_enabled" name="enabled" value="1" <?php checked($settings['enabled'], '1'); ?>>
                            Permitir envíos por WhatsApp desde EventosApp
                        </label>
                        <p class="evapp-wa-help">Además de esta activación global, cada evento debe tener WhatsApp activo en “Funciones Extra del Ticket”.</p>
                    </div>

                    <label for="evapp_wa_api_version">Versión Graph API</label>
                    <div>
                        <input type="text" id="evapp_wa_api_version" name="api_version" value="<?php echo esc_attr($settings['api_version']); ?>" placeholder="v23.0">
                        <p class="evapp-wa-help">Ejemplo: <span class="evapp-wa-code">v23.0</span>. Usa la misma versión que tienes configurada en Meta.</p>
                    </div>

                    <label for="evapp_wa_phone_number_id">Phone Number ID</label>
                    <div>
                        <input type="text" id="evapp_wa_phone_number_id" name="phone_number_id" value="<?php echo esc_attr($settings['phone_number_id']); ?>" autocomplete="off">
                        <p class="evapp-wa-help">ID del número emisor de WhatsApp Business Platform.</p>
                    </div>

                    <label for="evapp_wa_waba_id">WhatsApp Business Account ID</label>
                    <div>
                        <input type="text" id="evapp_wa_waba_id" name="waba_id" value="<?php echo esc_attr($settings['waba_id']); ?>" autocomplete="off">
                        <p class="evapp-wa-help">Se usa únicamente para enviar las plantillas a revisión en Meta. Es diferente al Phone Number ID.</p>
                    </div>

                    <label for="evapp_wa_access_token">Access Token</label>
                    <div>
                        <input type="password" id="evapp_wa_access_token" name="access_token" value="" autocomplete="new-password" placeholder="<?php echo $token_saved ? esc_attr('Token guardado. Déjalo vacío para conservarlo.') : esc_attr('Pega aquí el token de Meta'); ?>">
                        <p class="evapp-wa-help">Por seguridad no se muestra el token guardado. Si escribes uno nuevo, reemplazará al anterior.</p>
                    </div>

                    <label for="evapp_wa_country">Indicativo por defecto</label>
                    <div>
                        <input type="text" id="evapp_wa_country" name="default_country_code" value="<?php echo esc_attr($settings['default_country_code']); ?>" placeholder="57">
                        <p class="evapp-wa-help">Se usa cuando el teléfono del asistente no trae indicativo internacional.</p>
                    </div>

                    <label for="evapp_wa_timeout">Timeout</label>
                    <div>
                        <input type="number" id="evapp_wa_timeout" name="request_timeout" min="5" max="60" value="<?php echo esc_attr((int)$settings['request_timeout']); ?>">
                        <p class="evapp-wa-help">Tiempo máximo de espera por solicitud a Meta, en segundos.</p>
                    </div>

                    <label for="evapp_wa_webhook_url">Webhook de estados</label>
                    <div>
                        <input type="text" id="evapp_wa_webhook_url" value="<?php echo esc_attr($webhook_url); ?>" readonly onclick="this.select();">
                        <p class="evapp-wa-help">URL pública para configurar estados de entrega en Meta.</p>
                    </div>

                    <label for="evapp_wa_webhook_verify_token">Token de verificación webhook</label>
                    <div>
                        <input type="text" id="evapp_wa_webhook_verify_token" name="webhook_verify_token" value="<?php echo esc_attr($settings['webhook_verify_token']); ?>" autocomplete="off">
                        <p class="evapp-wa-help">Copia este token en Meta cuando configures el webhook.</p>
                    </div>

                    <label for="evapp_wa_debug">Depuración</label>
                    <div>
                        <label>
                            <input type="checkbox" id="evapp_wa_debug" name="debug_log" value="1" <?php checked($settings['debug_log'], '1'); ?>>
                            Escribir logs en wp-debug.log
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" name="dry_run" value="1" <?php checked($settings['dry_run'], '1'); ?>>
                            Modo prueba interno: no llama a Meta, solo registra el intento como simulado
                        </label>
                    </div>
                </div>
            </div>

            <div class="evapp-wa-card">
                <h2>Plantillas finales de tickets</h2>
                <div class="evapp-wa-warning">
                    Los tickets finales se envían como plantillas porque el negocio puede iniciar la conversación fuera de la ventana de atención.
                    Para la modalidad presencial, la plantilla debe tener encabezado tipo imagen para poder enviar el QR real del ticket.
                </div>

                <div class="evapp-wa-grid">
                    <label for="evapp_wa_ticket_delivery_mode">Modo de envío del ticket</label>
                    <div>
                        <select id="evapp_wa_ticket_delivery_mode" name="ticket_delivery_mode">
                            <option value="template" <?php selected($settings['ticket_delivery_mode'], 'template'); ?>>Plantillas aprobadas por Meta</option>
                            <option value="legacy" <?php selected($settings['ticket_delivery_mode'], 'legacy'); ?>>Mensaje libre legacy / respaldo</option>
                        </select>
                        <p class="evapp-wa-help">Deja activo “Plantillas aprobadas por Meta” para el envío final real.</p>
                    </div>

                    <label for="evapp_wa_template_category">Categoría de plantilla</label>
                    <div>
                        <select id="evapp_wa_template_category" name="template_category">
                            <option value="UTILITY" <?php selected($settings['template_category'], 'UTILITY'); ?>>UTILITY</option>
                            <option value="MARKETING" <?php selected($settings['template_category'], 'MARKETING'); ?>>MARKETING</option>
                        </select>
                        <p class="evapp-wa-help">Para envío de tickets y recordatorios de acceso la categoría recomendada es UTILITY.</p>
                    </div>

                    <label for="evapp_wa_tpl_presencial_name">Plantilla presencial</label>
                    <div>
                        <input type="text" id="evapp_wa_tpl_presencial_name" name="template_presencial_name" value="<?php echo esc_attr($settings['template_presencial_name']); ?>" placeholder="eventosapp_ticket_presencial">
                        <p class="evapp-wa-help">Nombre exacto de la plantilla aprobada en Meta.</p>
                    </div>

                    <label for="evapp_wa_tpl_presencial_language">Idioma presencial</label>
                    <div>
                        <input type="text" id="evapp_wa_tpl_presencial_language" name="template_presencial_language" value="<?php echo esc_attr($settings['template_presencial_language']); ?>" placeholder="es">
                        <p class="evapp-wa-help">Debe coincidir con el idioma de la plantilla en Meta.</p>
                    </div>

                    <label for="evapp_wa_tpl_presencial_handle">Header handle presencial</label>
                    <div>
                        <input type="text" id="evapp_wa_tpl_presencial_handle" name="template_presencial_header_handle" value="<?php echo esc_attr($settings['template_presencial_header_handle']); ?>" placeholder="4::aW1hZ2UvanBlZw...">
                        <p class="evapp-wa-help">Solo se necesita para crear la plantilla presencial por API. Si la creas desde WhatsApp Manager, deja este campo vacío.</p>
                    </div>

                    <label for="evapp_wa_tpl_virtual_name">Plantilla virtual</label>
                    <div>
                        <input type="text" id="evapp_wa_tpl_virtual_name" name="template_virtual_name" value="<?php echo esc_attr($settings['template_virtual_name']); ?>" placeholder="eventosapp_ticket_virtual">
                        <p class="evapp-wa-help">Nombre exacto de la plantilla aprobada en Meta.</p>
                    </div>

                    <label for="evapp_wa_tpl_virtual_language">Idioma virtual</label>
                    <div>
                        <input type="text" id="evapp_wa_tpl_virtual_language" name="template_virtual_language" value="<?php echo esc_attr($settings['template_virtual_language']); ?>" placeholder="es">
                        <p class="evapp-wa-help">Debe coincidir con el idioma de la plantilla en Meta.</p>
                    </div>

                    <label for="evapp_wa_tpl_footer">Footer</label>
                    <div>
                        <input type="text" id="evapp_wa_tpl_footer" name="template_footer" value="<?php echo esc_attr($settings['template_footer']); ?>" placeholder="EventosApp">
                        <p class="evapp-wa-help">Texto corto de pie de plantilla. No debe contener variables.</p>
                    </div>

                    <label>Texto presencial</label>
                    <div><div class="evapp-wa-template-preview"><?php echo esc_html($template_preview_presencial); ?></div></div>

                    <label>Texto virtual</label>
                    <div><div class="evapp-wa-template-preview"><?php echo esc_html($template_preview_virtual); ?></div></div>
                </div>

                <?php if ( ! empty($last_template_submission) ) : ?>
                    <h3>Última solicitud de plantillas</h3>
                    <table class="evapp-wa-status-table">
                        <tbody>
                            <tr><th>Fecha</th><td><?php echo esc_html($last_template_submission['date'] ?? ''); ?></td></tr>
                            <tr><th>Resumen</th><td><?php echo esc_html($last_template_submission['summary'] ?? ''); ?></td></tr>
                            <tr><th>Detalle</th><td><pre style="white-space:pre-wrap;margin:0;"><?php echo esc_html(wp_json_encode($last_template_submission['results'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre></td></tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="evapp-wa-card">
                <h2>Mensaje legacy / respaldo</h2>
                <div class="evapp-wa-grid">
                    <label for="evapp_wa_intro">Mensaje inicial</label>
                    <div>
                        <textarea id="evapp_wa_intro" name="message_intro"><?php echo esc_textarea($settings['message_intro']); ?></textarea>
                        <p class="evapp-wa-help">Solo se usa cuando el modo de envío está en legacy. Variables disponibles: <span class="evapp-wa-code">{{nombre}}</span>, <span class="evapp-wa-code">{{apellido}}</span>, <span class="evapp-wa-code">{{evento_nombre}}</span>, <span class="evapp-wa-code">{{ticket_id}}</span>.</p>
                    </div>
                </div>
            </div>

            <div class="evapp-wa-card">
                <h2>Prueba rápida</h2>
                <div class="evapp-wa-grid">
                    <label for="evapp_wa_test_phone">Teléfono de prueba</label>
                    <div>
                        <input type="text" id="evapp_wa_test_phone" name="test_phone" value="<?php echo esc_attr($settings['test_phone']); ?>" placeholder="573001112233">
                        <p class="evapp-wa-help">Guarda este teléfono para usar el botón de prueba.</p>
                    </div>

                    <label for="evapp_wa_test_message_mode">Tipo de mensaje de prueba</label>
                    <div>
                        <select id="evapp_wa_test_message_mode" name="test_message_mode">
                            <option value="template" <?php selected($settings['test_message_mode'], 'template'); ?>>Plantilla aprobada por Meta</option>
                            <option value="text" <?php selected($settings['test_message_mode'], 'text'); ?>>Texto libre</option>
                        </select>
                        <p class="evapp-wa-help">La prueba recomendada es plantilla. El texto libre solo funciona de forma confiable si el usuario ya abrió conversación con el negocio.</p>
                    </div>

                    <label for="evapp_wa_test_template_name">Nombre de plantilla de prueba</label>
                    <div>
                        <input type="text" id="evapp_wa_test_template_name" name="test_template_name" value="<?php echo esc_attr($settings['test_template_name']); ?>" placeholder="hello_world">
                    </div>

                    <label for="evapp_wa_test_template_language">Idioma de plantilla</label>
                    <div>
                        <input type="text" id="evapp_wa_test_template_language" name="test_template_language" value="<?php echo esc_attr($settings['test_template_language']); ?>" placeholder="en_US">
                    </div>
                </div>

                <?php if ( ! empty($last_test) ) : ?>
                    <h3>Última prueba registrada</h3>
                    <table class="evapp-wa-status-table">
                        <tbody>
                            <tr><th>Fecha</th><td><?php echo esc_html($last_test['date'] ?? ''); ?></td></tr>
                            <tr><th>Teléfono</th><td><?php echo esc_html($last_test['to'] ?? ''); ?></td></tr>
                            <tr><th>Tipo</th><td><?php echo esc_html($last_test['type'] ?? ''); ?></td></tr>
                            <tr><th>Resultado API</th><td><?php echo ! empty($last_test['ok']) ? 'Aceptado por Meta' : 'Error'; ?></td></tr>
                            <tr><th>HTTP</th><td><?php echo esc_html((string)($last_test['http_code'] ?? '')); ?></td></tr>
                            <tr><th>Message ID</th><td><?php echo esc_html($last_test['message_id'] ?? ''); ?></td></tr>
                            <tr><th>Estado webhook</th><td><?php echo esc_html($last_test['delivery_status'] ?? 'Sin estado recibido'); ?></td></tr>
                            <tr><th>Mensaje</th><td><?php echo esc_html($last_test['message'] ?? ''); ?></td></tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php submit_button('Guardar configuración de WhatsApp'); ?>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
            <?php wp_nonce_field('eventosapp_whatsapp_submit_ticket_templates', 'eventosapp_whatsapp_templates_nonce'); ?>
            <input type="hidden" name="action" value="eventosapp_whatsapp_submit_ticket_templates">
            <?php submit_button('Enviar plantillas finales a revisión en Meta', 'secondary', 'submit', false); ?>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
            <?php wp_nonce_field('eventosapp_whatsapp_send_test', 'eventosapp_whatsapp_test_nonce'); ?>
            <input type="hidden" name="action" value="eventosapp_whatsapp_send_test">
            <?php submit_button('Enviar mensaje de prueba', 'secondary', 'submit', false); ?>
        </form>
    </div>
    <?php
}

/**
 * Guarda settings globales.
 */
add_action('admin_post_eventosapp_whatsapp_save_settings', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }

    if ( ! isset($_POST['eventosapp_whatsapp_settings_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_settings_nonce'], 'eventosapp_whatsapp_save_settings') ) {
        wp_die('Nonce inválido.');
    }

    $current = eventosapp_whatsapp_get_settings();

    $access_token = isset($_POST['access_token']) ? trim((string) wp_unslash($_POST['access_token'])) : '';
    if ( $access_token === '' ) {
        $access_token = $current['access_token'];
    }

    $api_version = isset($_POST['api_version']) ? sanitize_text_field(wp_unslash($_POST['api_version'])) : 'v23.0';
    $api_version = preg_replace('/[^a-zA-Z0-9\.\-_]/', '', $api_version);
    if ( $api_version === '' ) {
        $api_version = 'v23.0';
    }

    $test_message_mode = isset($_POST['test_message_mode']) ? sanitize_key(wp_unslash($_POST['test_message_mode'])) : 'template';
    if ( ! in_array($test_message_mode, ['template', 'text'], true) ) {
        $test_message_mode = 'template';
    }

    $ticket_delivery_mode = isset($_POST['ticket_delivery_mode']) ? sanitize_key(wp_unslash($_POST['ticket_delivery_mode'])) : 'template';
    if ( ! in_array($ticket_delivery_mode, ['template', 'legacy'], true) ) {
        $ticket_delivery_mode = 'template';
    }

    $template_category = isset($_POST['template_category']) ? strtoupper(sanitize_key(wp_unslash($_POST['template_category']))) : 'UTILITY';
    if ( ! in_array($template_category, ['UTILITY', 'MARKETING'], true) ) {
        $template_category = 'UTILITY';
    }

    $webhook_verify_token = isset($_POST['webhook_verify_token']) ? sanitize_text_field(wp_unslash($_POST['webhook_verify_token'])) : '';
    if ( $webhook_verify_token === '' ) {
        $webhook_verify_token = ! empty($current['webhook_verify_token']) ? $current['webhook_verify_token'] : wp_generate_password(32, false, false);
    }

    $settings = [
        'enabled'                           => isset($_POST['enabled']) ? '1' : '0',
        'api_version'                       => $api_version,
        'phone_number_id'                   => isset($_POST['phone_number_id']) ? preg_replace('/\D+/', '', (string) wp_unslash($_POST['phone_number_id'])) : '',
        'waba_id'                           => isset($_POST['waba_id']) ? preg_replace('/\D+/', '', (string) wp_unslash($_POST['waba_id'])) : '',
        'access_token'                      => $access_token,
        'default_country_code'              => isset($_POST['default_country_code']) ? preg_replace('/\D+/', '', (string) wp_unslash($_POST['default_country_code'])) : '57',
        'request_timeout'                   => isset($_POST['request_timeout']) ? min(60, max(5, absint($_POST['request_timeout']))) : 20,
        'debug_log'                         => isset($_POST['debug_log']) ? '1' : '0',
        'dry_run'                           => isset($_POST['dry_run']) ? '1' : '0',
        'test_phone'                        => isset($_POST['test_phone']) ? sanitize_text_field(wp_unslash($_POST['test_phone'])) : '',
        'test_message_mode'                 => $test_message_mode,
        'test_template_name'                => isset($_POST['test_template_name']) ? sanitize_key(wp_unslash($_POST['test_template_name'])) : 'hello_world',
        'test_template_language'            => isset($_POST['test_template_language']) ? sanitize_text_field(wp_unslash($_POST['test_template_language'])) : 'en_US',
        'webhook_verify_token'              => $webhook_verify_token,
        'last_test_result'                  => isset($current['last_test_result']) && is_array($current['last_test_result']) ? $current['last_test_result'] : [],
        'ticket_delivery_mode'              => $ticket_delivery_mode,
        'template_category'                 => $template_category,
        'template_presencial_name'          => isset($_POST['template_presencial_name']) ? sanitize_key(wp_unslash($_POST['template_presencial_name'])) : 'eventosapp_ticket_presencial',
        'template_presencial_language'      => isset($_POST['template_presencial_language']) ? sanitize_text_field(wp_unslash($_POST['template_presencial_language'])) : 'es',
        'template_presencial_header_handle' => isset($_POST['template_presencial_header_handle']) ? sanitize_text_field(wp_unslash($_POST['template_presencial_header_handle'])) : '',
        'template_virtual_name'             => isset($_POST['template_virtual_name']) ? sanitize_key(wp_unslash($_POST['template_virtual_name'])) : 'eventosapp_ticket_virtual',
        'template_virtual_language'         => isset($_POST['template_virtual_language']) ? sanitize_text_field(wp_unslash($_POST['template_virtual_language'])) : 'es',
        'template_footer'                   => isset($_POST['template_footer']) ? sanitize_text_field(wp_unslash($_POST['template_footer'])) : 'EventosApp',
        'last_template_submission'          => isset($current['last_template_submission']) && is_array($current['last_template_submission']) ? $current['last_template_submission'] : [],
        'message_intro'                     => isset($_POST['message_intro']) ? sanitize_textarea_field(wp_unslash($_POST['message_intro'])) : eventosapp_whatsapp_default_settings()['message_intro'],
    ];

    if ( $settings['default_country_code'] === '' ) {
        $settings['default_country_code'] = '57';
    }
    if ( $settings['test_template_name'] === '' ) {
        $settings['test_template_name'] = 'hello_world';
    }
    if ( $settings['test_template_language'] === '' ) {
        $settings['test_template_language'] = 'en_US';
    }
    if ( $settings['template_presencial_name'] === '' ) {
        $settings['template_presencial_name'] = 'eventosapp_ticket_presencial';
    }
    if ( $settings['template_presencial_language'] === '' ) {
        $settings['template_presencial_language'] = 'es';
    }
    if ( $settings['template_virtual_name'] === '' ) {
        $settings['template_virtual_name'] = 'eventosapp_ticket_virtual';
    }
    if ( $settings['template_virtual_language'] === '' ) {
        $settings['template_virtual_language'] = 'es';
    }

    update_option(EVENTOSAPP_WHATSAPP_OPTION, $settings, false);

    wp_safe_redirect(add_query_arg('evapp_whatsapp_saved', '1', admin_url('admin.php?page=eventosapp_whatsapp_tickets')));
    exit;
});

/**
 * Mensaje de prueba desde la página global.
 */
add_action('admin_post_eventosapp_whatsapp_send_test', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }

    if ( ! isset($_POST['eventosapp_whatsapp_test_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_test_nonce'], 'eventosapp_whatsapp_send_test') ) {
        wp_die('Nonce inválido.');
    }

    $settings = eventosapp_whatsapp_get_settings();
    $phone = eventosapp_whatsapp_normalize_phone($settings['test_phone'], $settings['default_country_code']);

    if ( ! $phone ) {
        wp_safe_redirect(add_query_arg([
            'evapp_whatsapp_test' => '0',
            'evapp_whatsapp_msg'  => rawurlencode('Configura un teléfono de prueba válido.'),
        ], admin_url('admin.php?page=eventosapp_whatsapp_tickets')));
        exit;
    }

    $mode = isset($settings['test_message_mode']) && $settings['test_message_mode'] === 'text' ? 'text' : 'template';

    if ( $mode === 'template' ) {
        $result = eventosapp_whatsapp_api_send_template(
            $phone,
            $settings['test_template_name'] ?? 'hello_world',
            $settings['test_template_language'] ?? 'en_US',
            [],
            $settings
        );
    } else {
        $result = eventosapp_whatsapp_api_send_message($phone, [
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => 'Prueba de WhatsApp Tickets desde EventosApp.',
            ],
        ], $settings);
    }

    eventosapp_whatsapp_store_last_test_result($phone, $mode, $result);

    wp_safe_redirect(add_query_arg([
        'evapp_whatsapp_test' => ! empty($result['ok']) ? '1' : '0',
        'evapp_whatsapp_msg'  => rawurlencode(! empty($result['message']) ? $result['message'] : (! empty($result['ok']) ? 'Mensaje aceptado por Meta.' : 'Error enviando prueba.')),
    ], admin_url('admin.php?page=eventosapp_whatsapp_tickets')));
    exit;
});

/**
 * Envía las plantillas finales configuradas a revisión en Meta.
 */
add_action('admin_post_eventosapp_whatsapp_submit_ticket_templates', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Permisos insuficientes.');
    }

    if ( ! isset($_POST['eventosapp_whatsapp_templates_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_templates_nonce'], 'eventosapp_whatsapp_submit_ticket_templates') ) {
        wp_die('Nonce inválido.');
    }

    $result = eventosapp_whatsapp_submit_final_templates_to_meta();

    wp_safe_redirect(add_query_arg([
        'evapp_whatsapp_templates' => ! empty($result['ok']) ? '1' : '0',
        'evapp_whatsapp_templates_msg' => rawurlencode($result['message'] ?? 'Solicitud procesada.'),
    ], admin_url('admin.php?page=eventosapp_whatsapp_tickets')));
    exit;
});

/**
 * Agrega metabox de reglas de envío por evento.
 */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_whatsapp_rules',
        'WhatsApp Tickets - Reglas de Envío',
        'eventosapp_whatsapp_render_event_rules_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
});

/**
 * Campos disponibles para reglas.
 */
function eventosapp_whatsapp_get_rule_fields($event_id = 0) {
    $fields = [
        'nombre'      => 'Nombre',
        'apellido'    => 'Apellido',
        'cedula'      => 'Cédula',
        'email'       => 'Correo electrónico',
        'telefono'    => 'Celular',
        'empresa'     => 'Empresa',
        'nit'         => 'NIT',
        'cargo'       => 'Cargo',
        'ciudad'      => 'Ciudad',
        'pais'        => 'País',
        'localidad'   => 'Localidad',
        'modalidad'   => 'Modalidad del ticket',
        'estado_pago' => 'Estado de pago',
    ];

    if ( $event_id && function_exists('eventosapp_get_event_extra_fields') ) {
        $extra_fields = eventosapp_get_event_extra_fields($event_id);
        if ( is_array($extra_fields) ) {
            foreach ( $extra_fields as $extra ) {
                if ( empty($extra['key']) ) {
                    continue;
                }
                $key = sanitize_key($extra['key']);
                if ( $key === '' ) {
                    continue;
                }
                $label = ! empty($extra['label']) ? sanitize_text_field($extra['label']) : $key;
                $fields['extra:' . $key] = 'Campo adicional: ' . $label;
            }
        }
    }

    return $fields;
}

/**
 * Operadores disponibles para reglas.
 */
function eventosapp_whatsapp_get_rule_operators() {
    return [
        'equals'       => 'Es igual a',
        'not_equals'   => 'No es igual a',
        'contains'     => 'Contiene',
        'not_contains' => 'No contiene',
        'starts_with'  => 'Empieza por',
        'ends_with'    => 'Termina en',
        'empty'        => 'Está vacío',
        'not_empty'    => 'No está vacío',
    ];
}

/**
 * Normaliza las reglas guardadas.
 */
function eventosapp_whatsapp_normalize_rules($rules) {
    if ( ! is_array($rules) ) {
        return [];
    }

    $clean = [];
    foreach ( $rules as $rule ) {
        if ( ! is_array($rule) ) {
            continue;
        }

        $conditions = [];
        if ( ! empty($rule['conditions']) && is_array($rule['conditions']) ) {
            foreach ( $rule['conditions'] as $condition ) {
                if ( ! is_array($condition) ) {
                    continue;
                }

                $field = isset($condition['field']) ? sanitize_text_field(wp_unslash($condition['field'])) : '';
                $operator = isset($condition['operator']) ? sanitize_key(wp_unslash($condition['operator'])) : 'equals';
                $value = isset($condition['value']) ? sanitize_text_field(wp_unslash($condition['value'])) : '';

                if ( $field === '' ) {
                    continue;
                }

                if ( ! array_key_exists($operator, eventosapp_whatsapp_get_rule_operators()) ) {
                    $operator = 'equals';
                }

                $conditions[] = [
                    'field'    => $field,
                    'operator' => $operator,
                    'value'    => $value,
                ];
            }
        }

        $action = isset($rule['action']) ? sanitize_key(wp_unslash($rule['action'])) : 'allow';
        if ( ! in_array($action, ['allow', 'deny'], true) ) {
            $action = 'allow';
        }

        $match = isset($rule['match']) ? sanitize_key(wp_unslash($rule['match'])) : 'all';
        if ( ! in_array($match, ['all', 'any'], true) ) {
            $match = 'all';
        }

        $clean[] = [
            'enabled'    => isset($rule['enabled']) ? '1' : '0',
            'name'       => isset($rule['name']) ? sanitize_text_field(wp_unslash($rule['name'])) : '',
            'action'     => $action,
            'match'      => $match,
            'conditions' => $conditions,
        ];
    }

    return $clean;
}

/**
 * Renderiza el metabox de reglas por evento.
 */
function eventosapp_whatsapp_render_event_rules_metabox($post) {
    $enabled = get_post_meta($post->ID, '_eventosapp_ticket_whatsapp_enabled', true);
    $rules = get_post_meta($post->ID, '_eventosapp_whatsapp_rules', true);
    $rules = eventosapp_whatsapp_normalize_rules($rules);
    $fields = eventosapp_whatsapp_get_rule_fields($post->ID);
    $operators = eventosapp_whatsapp_get_rule_operators();

    wp_nonce_field('eventosapp_whatsapp_rules_save', 'eventosapp_whatsapp_rules_nonce');
    ?>
    <style>
        .evapp-wa-rules-box{border:1px solid #dcdcde;background:#fff;border-radius:8px;padding:14px;margin:10px 0;}
        .evapp-wa-rule{border:1px solid #ccd0d4;border-left:4px solid #25D366;border-radius:8px;background:#fafafa;margin:14px 0;padding:14px;}
        .evapp-wa-rule-head{display:grid;grid-template-columns:90px 1fr 150px 150px auto;gap:10px;align-items:center;margin-bottom:12px;}
        .evapp-wa-rule-head input[type="text"],.evapp-wa-rule-head select{width:100%;}
        .evapp-wa-conditions table{width:100%;border-collapse:collapse;background:#fff;}
        .evapp-wa-conditions th,.evapp-wa-conditions td{padding:8px;border-bottom:1px solid #eee;text-align:left;vertical-align:middle;}
        .evapp-wa-conditions select,.evapp-wa-conditions input{width:100%;}
        .evapp-wa-muted{color:#646970;font-size:12px;}
        .evapp-wa-empty{padding:12px;background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:6px;}
    </style>

    <div class="evapp-wa-rules-box">
        <?php if ( $enabled !== '1' ) : ?>
            <p class="evapp-wa-empty">WhatsApp todavía no está activo para este evento. Actívalo en el metabox lateral <strong>Funciones Extra del Ticket</strong>.</p>
        <?php endif; ?>

        <p>
            Configura a quién se le envía o no el ticket por WhatsApp. Las reglas de <strong>No enviar</strong> tienen prioridad sobre las reglas de <strong>Enviar</strong>.
            Si no creas reglas, el sistema enviará a todos los tickets del evento que tengan celular válido.
        </p>

        <div id="evapp-wa-rules-list">
            <?php if ( empty($rules) ) : ?>
                <p class="evapp-wa-empty" id="evapp-wa-no-rules">No hay reglas configuradas. Se enviará a todos los asistentes con celular válido.</p>
            <?php else : ?>
                <?php foreach ( $rules as $rule_index => $rule ) : ?>
                    <?php eventosapp_whatsapp_render_rule_row($rule_index, $rule, $fields, $operators); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <p>
            <button type="button" class="button button-secondary" id="evapp-wa-add-rule">+ Agregar regla de WhatsApp</button>
        </p>
    </div>

    <script type="text/html" id="tmpl-evapp-wa-rule">
        <?php eventosapp_whatsapp_render_rule_row('__RULE_INDEX__', [
            'enabled' => '1',
            'name' => '',
            'action' => 'allow',
            'match' => 'all',
            'conditions' => [],
        ], $fields, $operators); ?>
    </script>

    <script type="text/html" id="tmpl-evapp-wa-condition">
        <?php eventosapp_whatsapp_render_condition_row('__RULE_INDEX__', '__COND_INDEX__', [], $fields, $operators); ?>
    </script>

    <script>
    jQuery(function($){
        var ruleIndex = $('#evapp-wa-rules-list .evapp-wa-rule').length;

        function replaceAllIndexes(html, ruleIdx, condIdx) {
            html = html.replace(/__RULE_INDEX__/g, ruleIdx);
            if (typeof condIdx !== 'undefined') {
                html = html.replace(/__COND_INDEX__/g, condIdx);
            }
            return html;
        }

        $('#evapp-wa-add-rule').on('click', function(){
            $('#evapp-wa-no-rules').remove();
            var html = $('#tmpl-evapp-wa-rule').html();
            $('#evapp-wa-rules-list').append(replaceAllIndexes(html, ruleIndex));
            ruleIndex++;
        });

        $(document).on('click', '.evapp-wa-remove-rule', function(){
            $(this).closest('.evapp-wa-rule').remove();
            if ($('#evapp-wa-rules-list .evapp-wa-rule').length === 0) {
                $('#evapp-wa-rules-list').append('<p class="evapp-wa-empty" id="evapp-wa-no-rules">No hay reglas configuradas. Se enviará a todos los asistentes con celular válido.</p>');
            }
        });

        $(document).on('click', '.evapp-wa-add-condition', function(){
            var $rule = $(this).closest('.evapp-wa-rule');
            var rIdx = $rule.data('rule-index');
            var cIdx = $rule.find('tbody tr').length;
            var html = $('#tmpl-evapp-wa-condition').html();
            $rule.find('tbody').append(replaceAllIndexes(html, rIdx, cIdx));
        });

        $(document).on('click', '.evapp-wa-remove-condition', function(){
            $(this).closest('tr').remove();
        });
    });
    </script>
    <?php
}

/**
 * Render individual de regla.
 */
function eventosapp_whatsapp_render_rule_row($rule_index, $rule, $fields, $operators) {
    $rule = wp_parse_args($rule, [
        'enabled' => '1',
        'name' => '',
        'action' => 'allow',
        'match' => 'all',
        'conditions' => [],
    ]);
    ?>
    <div class="evapp-wa-rule" data-rule-index="<?php echo esc_attr($rule_index); ?>">
        <div class="evapp-wa-rule-head">
            <label>
                <input type="checkbox" name="eventosapp_whatsapp_rules[<?php echo esc_attr($rule_index); ?>][enabled]" value="1" <?php checked($rule['enabled'], '1'); ?>> Activa
            </label>
            <input type="text" name="eventosapp_whatsapp_rules[<?php echo esc_attr($rule_index); ?>][name]" value="<?php echo esc_attr($rule['name']); ?>" placeholder="Nombre de la regla">
            <select name="eventosapp_whatsapp_rules[<?php echo esc_attr($rule_index); ?>][action]">
                <option value="allow" <?php selected($rule['action'], 'allow'); ?>>Enviar si cumple</option>
                <option value="deny" <?php selected($rule['action'], 'deny'); ?>>No enviar si cumple</option>
            </select>
            <select name="eventosapp_whatsapp_rules[<?php echo esc_attr($rule_index); ?>][match]">
                <option value="all" <?php selected($rule['match'], 'all'); ?>>Todas las condiciones</option>
                <option value="any" <?php selected($rule['match'], 'any'); ?>>Cualquier condición</option>
            </select>
            <button type="button" class="button-link-delete evapp-wa-remove-rule">Eliminar regla</button>
        </div>

        <div class="evapp-wa-conditions">
            <table>
                <thead>
                    <tr>
                        <th style="width:32%;">Campo</th>
                        <th style="width:24%;">Operador</th>
                        <th>Valor</th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( (array) $rule['conditions'] as $condition_index => $condition ) : ?>
                        <?php eventosapp_whatsapp_render_condition_row($rule_index, $condition_index, $condition, $fields, $operators); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="button button-small evapp-wa-add-condition">+ Agregar condición</button></p>
            <p class="evapp-wa-muted">Una regla sin condiciones aplica para todos los tickets.</p>
        </div>
    </div>
    <?php
}

/**
 * Render individual de condición.
 */
function eventosapp_whatsapp_render_condition_row($rule_index, $condition_index, $condition, $fields, $operators) {
    $condition = wp_parse_args((array)$condition, [
        'field' => 'cedula',
        'operator' => 'equals',
        'value' => '',
    ]);
    ?>
    <tr>
        <td>
            <select name="eventosapp_whatsapp_rules[<?php echo esc_attr($rule_index); ?>][conditions][<?php echo esc_attr($condition_index); ?>][field]">
                <?php foreach ( $fields as $key => $label ) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($condition['field'], $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <select name="eventosapp_whatsapp_rules[<?php echo esc_attr($rule_index); ?>][conditions][<?php echo esc_attr($condition_index); ?>][operator]">
                <?php foreach ( $operators as $key => $label ) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($condition['operator'], $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="text" name="eventosapp_whatsapp_rules[<?php echo esc_attr($rule_index); ?>][conditions][<?php echo esc_attr($condition_index); ?>][value]" value="<?php echo esc_attr($condition['value']); ?>" placeholder="Valor a comparar">
        </td>
        <td><button type="button" class="button-link-delete evapp-wa-remove-condition">Quitar</button></td>
    </tr>
    <?php
}

/**
 * Guarda reglas por evento.
 */
add_action('save_post_eventosapp_event', function($post_id) {
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision($post_id) ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;

    if ( ! isset($_POST['eventosapp_whatsapp_rules_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_rules_nonce'], 'eventosapp_whatsapp_rules_save') ) {
        return;
    }

    $raw_rules = isset($_POST['eventosapp_whatsapp_rules']) && is_array($_POST['eventosapp_whatsapp_rules']) ? $_POST['eventosapp_whatsapp_rules'] : [];
    $rules = eventosapp_whatsapp_normalize_rules($raw_rules);
    update_post_meta($post_id, '_eventosapp_whatsapp_rules', $rules);
}, 30);

/**
 * Metabox manual en el ticket para ver historial y reenviar por WhatsApp.
 */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_ticket_whatsapp',
        'WhatsApp del Ticket',
        'eventosapp_whatsapp_render_ticket_metabox',
        'eventosapp_ticket',
        'side',
        'default'
    );
});

function eventosapp_whatsapp_render_ticket_metabox($post) {
    $settings = eventosapp_whatsapp_get_settings();
    $event_id = absint(get_post_meta($post->ID, '_eventosapp_ticket_evento_id', true));
    $event_enabled = $event_id ? get_post_meta($event_id, '_eventosapp_ticket_whatsapp_enabled', true) : '0';
    $phone = get_post_meta($post->ID, '_eventosapp_asistente_tel', true);
    $status = get_post_meta($post->ID, '_eventosapp_whatsapp_last_status', true);
    $last_at = get_post_meta($post->ID, '_eventosapp_whatsapp_last_sent_at', true);
    $last_error = get_post_meta($post->ID, '_eventosapp_whatsapp_last_error', true);
    $last_message_id = get_post_meta($post->ID, '_eventosapp_whatsapp_last_message_id', true);
    $delivery_status = get_post_meta($post->ID, '_eventosapp_whatsapp_delivery_status', true);
    $delivery_at = get_post_meta($post->ID, '_eventosapp_whatsapp_delivery_at', true);
    $modalidad = function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label($post->ID) : get_post_meta($post->ID, '_eventosapp_ticket_modalidad', true);
    $template_type = eventosapp_whatsapp_get_ticket_template_type($post->ID);
    $history = get_post_meta($post->ID, '_eventosapp_whatsapp_history', true);
    if ( ! is_array($history) ) {
        $history = [];
    }

    $default_test_phone = ! empty($settings['test_phone']) ? $settings['test_phone'] : $phone;
    ?>
    <p><strong>Celular del asistente:</strong><br><?php echo $phone ? esc_html($phone) : '<span style="color:#b32d2e;">Sin celular</span>'; ?></p>
    <p><strong>Modalidad:</strong> <?php echo $modalidad ? esc_html($modalidad) : 'No definida'; ?></p>
    <p><strong>Plantilla que usará:</strong><br><code><?php echo esc_html($template_type === 'virtual' ? ($settings['template_virtual_name'] ?? '') : ($settings['template_presencial_name'] ?? '')); ?></code></p>
    <p><strong>WhatsApp en evento:</strong> <?php echo $event_enabled === '1' ? 'Activo' : 'Inactivo'; ?></p>
    <p><strong>Último estado:</strong> <?php echo $status ? esc_html($status) : 'Sin envíos'; ?></p>
    <?php if ( $last_at ) : ?><p><strong>Último envío:</strong><br><?php echo esc_html($last_at); ?></p><?php endif; ?>
    <?php if ( $last_message_id ) : ?><p><strong>Message ID Meta:</strong><br><small><?php echo esc_html($last_message_id); ?></small></p><?php endif; ?>
    <?php if ( $delivery_status ) : ?><p><strong>Estado webhook:</strong><br><?php echo esc_html($delivery_status); ?><?php echo $delivery_at ? '<br><small>' . esc_html($delivery_at) . '</small>' : ''; ?></p><?php endif; ?>
    <?php if ( $last_error ) : ?><p style="color:#b32d2e;"><strong>Error:</strong><br><?php echo esc_html($last_error); ?></p><?php endif; ?>

    <hr>
    <p><strong>Enviar bajo demanda</strong></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('eventosapp_send_ticket_whatsapp_' . $post->ID, 'eventosapp_ticket_whatsapp_nonce'); ?>
        <input type="hidden" name="action" value="eventosapp_send_ticket_whatsapp">
        <input type="hidden" name="ticket_id" value="<?php echo esc_attr($post->ID); ?>">
        <p>
            <label for="evapp_wa_phone_override_<?php echo esc_attr($post->ID); ?>"><strong>Número destino</strong></label><br>
            <input type="text" id="evapp_wa_phone_override_<?php echo esc_attr($post->ID); ?>" name="phone_override" value="<?php echo esc_attr($default_test_phone); ?>" style="width:100%;" placeholder="573001112233">
        </p>
        <p style="margin-top:8px;">
            <button type="submit" class="button button-primary">Enviar ticket real por WhatsApp</button>
        </p>
    </form>
    <small>Este envío bajo demanda omite las reglas de filtro para permitir pruebas administrativas. Usa la modalidad real del ticket y el modo final configurado en WhatsApp Tickets.</small>

    <?php if ( ! empty($history) ) : ?>
        <hr>
        <strong>Historial reciente</strong>
        <ul style="margin-left:16px;list-style:disc;">
            <?php foreach ( array_slice(array_reverse($history), 0, 8) as $entry ) : ?>
                <li>
                    <?php echo esc_html(($entry['date'] ?? '') . ' - ' . ($entry['status'] ?? '')); ?>
                    <?php if ( ! empty($entry['to']) ) : ?><br><small>Para: <?php echo esc_html($entry['to']); ?></small><?php endif; ?>
                    <?php if ( ! empty($entry['message']) ) : ?><br><small><?php echo esc_html($entry['message']); ?></small><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php
}

/**
 * Acción manual de envío por WhatsApp desde el ticket.
 */
add_action('admin_post_eventosapp_send_ticket_whatsapp', function() {
    $ticket_id = 0;
    if ( isset($_POST['ticket_id']) ) {
        $ticket_id = absint($_POST['ticket_id']);
    } elseif ( isset($_GET['ticket_id']) ) {
        $ticket_id = absint($_GET['ticket_id']);
    }

    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        wp_die('Ticket inválido.');
    }

    if ( ! current_user_can('edit_post', $ticket_id) ) {
        wp_die('Permisos insuficientes.');
    }

    $nonce_ok = false;
    if ( isset($_POST['eventosapp_ticket_whatsapp_nonce']) ) {
        $nonce_ok = wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventosapp_ticket_whatsapp_nonce'])), 'eventosapp_send_ticket_whatsapp_' . $ticket_id);
    } elseif ( isset($_GET['_wpnonce']) ) {
        $nonce_ok = wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'eventosapp_send_ticket_whatsapp_' . $ticket_id);
    }

    if ( ! $nonce_ok ) {
        wp_die('Nonce inválido.');
    }

    $phone_override = isset($_POST['phone_override']) ? sanitize_text_field(wp_unslash($_POST['phone_override'])) : '';

    $result = eventosapp_whatsapp_send_ticket($ticket_id, [
        'context' => 'manual_admin_ondemand',
        'force' => true,
        'skip_rules' => true,
        'phone_override' => $phone_override,
    ]);

    wp_safe_redirect(add_query_arg([
        'post' => $ticket_id,
        'action' => 'edit',
        'evapp_whatsapp' => ! empty($result['ok']) ? '1' : '0',
        'evapp_whatsapp_msg' => rawurlencode(! empty($result['message']) ? $result['message'] : (! empty($result['ok']) ? 'WhatsApp enviado.' : 'No se pudo enviar WhatsApp.')),
    ], admin_url('post.php')));
    exit;
});

add_action('admin_notices', function() {
    if ( ! is_admin() || ! isset($_GET['post'], $_GET['evapp_whatsapp']) ) return;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'eventosapp_ticket' ) return;

    $ok = sanitize_text_field(wp_unslash($_GET['evapp_whatsapp'])) === '1';
    $msg = isset($_GET['evapp_whatsapp_msg']) ? sanitize_text_field(wp_unslash($_GET['evapp_whatsapp_msg'])) : ($ok ? 'WhatsApp enviado.' : 'No se pudo enviar WhatsApp.');
    echo '<div class="' . ($ok ? 'notice notice-success' : 'notice notice-error') . ' is-dismissible"><p><strong>EventosApp WhatsApp:</strong> ' . esc_html($msg) . '</p></div>';
});

/**
 * Normaliza teléfono a formato internacional sin +.
 */
function eventosapp_whatsapp_normalize_phone($raw_phone, $default_country_code = '57') {
    $phone = preg_replace('/\D+/', '', (string) $raw_phone);
    $default_country_code = preg_replace('/\D+/', '', (string) $default_country_code);

    if ( $phone === '' ) {
        return '';
    }

    if ( strpos($phone, '00') === 0 ) {
        $phone = substr($phone, 2);
    }

    if ( $default_country_code && strpos($phone, $default_country_code) !== 0 ) {
        if ( strlen($phone) <= 10 ) {
            $phone = $default_country_code . ltrim($phone, '0');
        }
    }

    return strlen($phone) >= 8 ? $phone : '';
}

/**
 * Envía payload a WhatsApp Cloud API.
 */
function eventosapp_whatsapp_api_send_message($to, array $message_payload, $settings = null) {
    $settings = is_array($settings) ? wp_parse_args($settings, eventosapp_whatsapp_default_settings()) : eventosapp_whatsapp_get_settings();

    if ( empty($settings['enabled']) || $settings['enabled'] !== '1' ) {
        return [
            'ok' => false,
            'message' => 'La integración global de WhatsApp no está activa.',
        ];
    }

    if ( ! empty($settings['dry_run']) && $settings['dry_run'] === '1' ) {
        eventosapp_whatsapp_log('DRY RUN mensaje simulado', [
            'to' => $to,
            'payload' => $message_payload,
        ]);
        return [
            'ok' => true,
            'message' => 'Modo prueba: envío simulado correctamente.',
            'dry_run' => true,
            'response' => ['dry_run' => true],
        ];
    }

    $phone_number_id = preg_replace('/\D+/', '', (string)($settings['phone_number_id'] ?? ''));
    $access_token = trim((string)($settings['access_token'] ?? ''));
    $api_version = preg_replace('/[^a-zA-Z0-9\.\-_]/', '', (string)($settings['api_version'] ?? 'v23.0'));

    if ( $phone_number_id === '' || $access_token === '' ) {
        return [
            'ok' => false,
            'message' => 'Faltan Phone Number ID o Access Token en la configuración de WhatsApp.',
        ];
    }

    if ( $api_version === '' ) {
        $api_version = 'v23.0';
    }

    $endpoint = sprintf(
        'https://graph.facebook.com/%s/%s/messages',
        rawurlencode($api_version),
        rawurlencode($phone_number_id)
    );

    $payload = array_merge([
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $to,
    ], $message_payload);

    $response = wp_remote_post($endpoint, [
        'timeout' => min(60, max(5, absint($settings['request_timeout'] ?? 20))),
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    if ( is_wp_error($response) ) {
        eventosapp_whatsapp_log('Error WP HTTP enviando mensaje', [
            'to' => $to,
            'error' => $response->get_error_message(),
        ]);
        return [
            'ok' => false,
            'message' => $response->get_error_message(),
            'response' => null,
        ];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);

    $ok = $code >= 200 && $code < 300;

    eventosapp_whatsapp_log($ok ? 'Mensaje enviado' : 'Error respuesta Meta', [
        'to' => $to,
        'http_code' => $code,
        'response' => $decoded ?: $body,
    ]);

    $message_id = eventosapp_whatsapp_extract_message_id($decoded);

    return [
        'ok' => $ok,
        'message' => $ok ? ($message_id ? 'Mensaje aceptado por Meta. ID: ' . $message_id : 'Mensaje aceptado por Meta.') : eventosapp_whatsapp_extract_api_error($decoded, $body, $code),
        'http_code' => $code,
        'message_id' => $message_id,
        'response' => $decoded ?: $body,
    ];
}

function eventosapp_whatsapp_extract_api_error($decoded, $body, $code) {
    if ( is_array($decoded) && ! empty($decoded['error']['message']) ) {
        return 'Meta API: ' . sanitize_text_field($decoded['error']['message']);
    }
    if ( $body !== '' ) {
        return 'Meta API HTTP ' . (int)$code . ': ' . sanitize_text_field(wp_trim_words($body, 30, '...'));
    }
    return 'Meta API HTTP ' . (int)$code;
}

/**
 * Obtiene o genera el QR específico de WhatsApp.
 */

function eventosapp_whatsapp_extract_message_id($decoded) {
    if ( is_array($decoded) && ! empty($decoded['messages'][0]['id']) ) {
        return sanitize_text_field((string) $decoded['messages'][0]['id']);
    }
    return '';
}

function eventosapp_whatsapp_build_template_payload($template_name, $language_code = 'es', $components = []) {
    $template_name = sanitize_key((string) $template_name);
    $language_code = sanitize_text_field((string) $language_code);

    if ( $template_name === '' ) {
        $template_name = 'hello_world';
    }
    if ( $language_code === '' ) {
        $language_code = 'es';
    }

    $template = [
        'name' => $template_name,
        'language' => [
            'code' => $language_code,
        ],
    ];

    if ( is_array($components) && ! empty($components) ) {
        $template['components'] = $components;
    }

    return [
        'type' => 'template',
        'template' => $template,
    ];
}

function eventosapp_whatsapp_api_send_template($to, $template_name, $language_code = 'es', $components = [], $settings = null) {
    return eventosapp_whatsapp_api_send_message(
        $to,
        eventosapp_whatsapp_build_template_payload($template_name, $language_code, $components),
        $settings
    );
}

function eventosapp_whatsapp_store_last_test_result($phone, $type, $result) {
    $settings = eventosapp_whatsapp_get_settings();
    $message_id = isset($result['message_id']) ? sanitize_text_field((string)$result['message_id']) : eventosapp_whatsapp_extract_message_id($result['response'] ?? []);

    $settings['last_test_result'] = [
        'date' => current_time('mysql'),
        'to' => sanitize_text_field($phone),
        'type' => sanitize_text_field($type),
        'ok' => ! empty($result['ok']) ? 1 : 0,
        'http_code' => isset($result['http_code']) ? (int) $result['http_code'] : 0,
        'message_id' => $message_id,
        'delivery_status' => '',
        'message' => sanitize_text_field((string)($result['message'] ?? '')),
    ];

    update_option(EVENTOSAPP_WHATSAPP_OPTION, $settings, false);

    if ( $message_id !== '' ) {
        eventosapp_whatsapp_register_message_map($message_id, 0, 'quick_test', $phone);
    }
}

function eventosapp_whatsapp_get_message_map() {
    $map = get_option('eventosapp_whatsapp_message_map', []);
    return is_array($map) ? $map : [];
}

function eventosapp_whatsapp_register_message_map($message_id, $ticket_id = 0, $context = '', $phone = '') {
    $message_id = sanitize_text_field((string)$message_id);
    if ( $message_id === '' ) {
        return;
    }

    $map = eventosapp_whatsapp_get_message_map();
    $map[$message_id] = [
        'ticket_id' => absint($ticket_id),
        'context' => sanitize_text_field((string)$context),
        'phone' => sanitize_text_field((string)$phone),
        'created_at' => current_time('mysql'),
    ];

    if ( count($map) > 500 ) {
        $map = array_slice($map, -500, null, true);
    }

    update_option('eventosapp_whatsapp_message_map', $map, false);
}

function eventosapp_whatsapp_ensure_qr_url($ticket_id) {
    $ticket_id = absint($ticket_id);
    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        return '';
    }

    $all_qrs = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
    if ( is_array($all_qrs) && ! empty($all_qrs['whatsapp']['url']) ) {
        return esc_url_raw($all_qrs['whatsapp']['url']);
    }

    $manager = null;
    if ( isset($GLOBALS['eventosapp_qr_manager_instance']) && $GLOBALS['eventosapp_qr_manager_instance'] instanceof EventosApp_QR_Manager ) {
        $manager = $GLOBALS['eventosapp_qr_manager_instance'];
    } elseif ( function_exists('eventosapp_qr_manager_init') ) {
        $manager = eventosapp_qr_manager_init();
    }

    if ( $manager && method_exists($manager, 'generate_qr_code') ) {
        $qr = $manager->generate_qr_code($ticket_id, 'whatsapp');
        if ( is_array($qr) && ! empty($qr['url']) ) {
            return esc_url_raw($qr['url']);
        }
    }

    return '';
}

/**
 * Reemplaza variables del mensaje inicial.
 */
function eventosapp_whatsapp_replace_message_vars($template, $ticket_id, $event_id) {
    $vars = [
        '{{nombre}}' => get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true),
        '{{apellido}}' => get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true),
        '{{evento_nombre}}' => get_the_title($event_id),
        '{{ticket_id}}' => get_post_meta($ticket_id, 'eventosapp_ticketID', true),
    ];

    return strtr((string)$template, array_map('sanitize_text_field', $vars));
}

/**
 * Construye la fecha legible del evento.
 */
function eventosapp_whatsapp_get_event_date_label($event_id) {
    $tipo_fecha = get_post_meta($event_id, '_eventosapp_tipo_fecha', true) ?: 'unica';

    if ( $tipo_fecha === 'unica' ) {
        $fecha = get_post_meta($event_id, '_eventosapp_fecha_unica', true);
        return $fecha ? date_i18n('F d, Y', strtotime($fecha)) : '';
    }

    if ( $tipo_fecha === 'consecutiva' ) {
        $inicio = get_post_meta($event_id, '_eventosapp_fecha_inicio', true);
        $fin = get_post_meta($event_id, '_eventosapp_fecha_fin', true);
        if ( $inicio && $fin ) {
            return date_i18n('F d, Y', strtotime($inicio)) . ' - ' . date_i18n('F d, Y', strtotime($fin));
        }
        return $inicio ? date_i18n('F d, Y', strtotime($inicio)) : '';
    }

    $fechas = get_post_meta($event_id, '_eventosapp_fechas_noco', true);
    if ( is_array($fechas) && ! empty($fechas) ) {
        return implode(', ', array_map(function($fecha) {
            return date_i18n('F d, Y', strtotime($fecha));
        }, $fechas));
    }

    return '';
}


/**
 * Textos oficiales de las plantillas finales.
 * Estos textos deben coincidir con las plantillas aprobadas en Meta.
 */
function eventosapp_whatsapp_get_template_body_text($type) {
    $type = $type === 'virtual' ? 'virtual' : 'presencial';

    if ( $type === 'virtual' ) {
        return "Hola {{1}}, tu acceso virtual para {{2}} está listo.\n\nTicket: {{3}}\nModalidad: {{4}}\nFecha: {{5}}\nHora: {{6}}\nPlataforma: {{7}}\nIngreso a la sesión: {{8}}\nAgenda ICS: {{9}}\n\nConéctate a la hora indicada y conserva este mensaje.";
    }

    return "Hola {{1}}, este es tu ticket de ingreso para {{2}}.\n\nTicket: {{3}}\nModalidad: {{4}}\nFecha: {{5}}\nHora: {{6}}\nLugar: {{7}}\nLocalidad: {{8}}\n\nGuarda tus accesos:\nGoogle Wallet: {{9}}\nApple Wallet: {{10}}\nAgenda ICS: {{11}}\nPDF: {{12}}\n\nPresenta el QR adjunto al llegar al evento.";
}

function eventosapp_whatsapp_template_text_value($value, $fallback = 'No disponible') {
    $value = is_scalar($value) ? trim((string)$value) : '';
    if ( $value === '' ) {
        $value = $fallback;
    }
    $value = preg_replace("/[\r\n\t]+/", ' ', $value);
    $value = trim($value);

    if ( function_exists('mb_strlen') && mb_strlen($value) > 900 ) {
        $value = mb_substr($value, 0, 897) . '...';
    } elseif ( strlen($value) > 900 ) {
        $value = substr($value, 0, 897) . '...';
    }

    return $value;
}

function eventosapp_whatsapp_public_url_or_empty($url) {
    $url = is_scalar($url) ? trim((string)$url) : '';
    if ( $url === '' ) {
        return '';
    }

    if ( strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0 ) {
        return '';
    }

    return esc_url_raw($url);
}

function eventosapp_whatsapp_get_ticket_template_type($ticket_id) {
    $ticket_id = absint($ticket_id);

    if ( function_exists('eventosapp_get_ticket_modalidad') ) {
        $modalidad = eventosapp_get_ticket_modalidad($ticket_id);
    } else {
        $modalidad = get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
    }

    return $modalidad === 'virtual' ? 'virtual' : 'presencial';
}

function eventosapp_whatsapp_get_ticket_asset_urls($ticket_id, $template_type = '') {
    $ticket_id = absint($ticket_id);
    $template_type = $template_type === 'virtual' ? 'virtual' : eventosapp_whatsapp_get_ticket_template_type($ticket_id);

    $assets = [
        'qr_url' => '',
        'google_wallet' => '',
        'apple_wallet' => '',
        'ics_url' => '',
        'pdf_url' => '',
        'virtual_access' => '',
    ];

    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        return $assets;
    }

    if ( $template_type === 'presencial' ) {
        $assets['qr_url'] = eventosapp_whatsapp_public_url_or_empty(eventosapp_whatsapp_ensure_qr_url($ticket_id));

        $google_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url', true);
        if ( ! $google_wallet ) {
            $google_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android', true);
        }
        $assets['google_wallet'] = eventosapp_whatsapp_public_url_or_empty($google_wallet);

        $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url', true);
        if ( ! $apple_wallet ) {
            $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple', true);
        }
        if ( ! $apple_wallet ) {
            $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url', true);
        }
        $assets['apple_wallet'] = eventosapp_whatsapp_public_url_or_empty($apple_wallet);

        $pdf_url = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
        if ( ! $pdf_url && function_exists('eventosapp_ticket_generar_pdf') ) {
            eventosapp_ticket_generar_pdf($ticket_id);
            $pdf_url = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
        }
        $assets['pdf_url'] = eventosapp_whatsapp_public_url_or_empty($pdf_url);
    }

    $ics_url = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
    if ( ! $ics_url && function_exists('eventosapp_ticket_generar_ics') ) {
        eventosapp_ticket_generar_ics($ticket_id);
        $ics_url = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
    }
    $assets['ics_url'] = eventosapp_whatsapp_public_url_or_empty($ics_url);

    if ( $template_type === 'virtual' ) {
        if ( function_exists('eventosapp_get_virtual_landing_url') ) {
            $assets['virtual_access'] = eventosapp_whatsapp_public_url_or_empty(eventosapp_get_virtual_landing_url($ticket_id));
        }
        if ( ! $assets['virtual_access'] && function_exists('eventosapp_get_ticket_virtual_platform_url') ) {
            $assets['virtual_access'] = eventosapp_whatsapp_public_url_or_empty(eventosapp_get_ticket_virtual_platform_url($ticket_id));
        }
        if ( ! $assets['virtual_access'] ) {
            $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
            $assets['virtual_access'] = eventosapp_whatsapp_public_url_or_empty(get_post_meta($event_id, '_eventosapp_virtual_url', true));
        }
    }

    return $assets;
}

function eventosapp_whatsapp_get_ticket_body_values($ticket_id, $template_type = '') {
    $ticket_id = absint($ticket_id);
    $template_type = $template_type === 'virtual' ? 'virtual' : eventosapp_whatsapp_get_ticket_template_type($ticket_id);
    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    $assets = eventosapp_whatsapp_get_ticket_asset_urls($ticket_id, $template_type);

    $nombre_completo = trim(get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' . get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true));
    $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
    $modalidad = function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label($ticket_id) : get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
    $fecha = eventosapp_whatsapp_get_event_date_label($event_id);
    $hora_inicio = get_post_meta($event_id, '_eventosapp_hora_inicio', true);
    $hora_cierre = get_post_meta($event_id, '_eventosapp_hora_cierre', true);
    $hora = trim($hora_inicio . ($hora_cierre ? ' - ' . $hora_cierre : ''));
    $event_title = $event_id ? get_the_title($event_id) : '';
    $localidad = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);

    if ( $template_type === 'virtual' ) {
        $platform = get_post_meta($event_id, '_eventosapp_virtual_platform', true);
        return [
            eventosapp_whatsapp_template_text_value($nombre_completo, 'Asistente'),
            eventosapp_whatsapp_template_text_value($event_title, 'Evento'),
            eventosapp_whatsapp_template_text_value($ticket_code, 'Ticket'),
            eventosapp_whatsapp_template_text_value($modalidad, 'Virtual'),
            eventosapp_whatsapp_template_text_value($fecha),
            eventosapp_whatsapp_template_text_value($hora),
            eventosapp_whatsapp_template_text_value($platform),
            eventosapp_whatsapp_template_text_value($assets['virtual_access']),
            eventosapp_whatsapp_template_text_value($assets['ics_url']),
        ];
    }

    $direccion = get_post_meta($event_id, '_eventosapp_direccion', true);

    return [
        eventosapp_whatsapp_template_text_value($nombre_completo, 'Asistente'),
        eventosapp_whatsapp_template_text_value($event_title, 'Evento'),
        eventosapp_whatsapp_template_text_value($ticket_code, 'Ticket'),
        eventosapp_whatsapp_template_text_value($modalidad, 'Presencial'),
        eventosapp_whatsapp_template_text_value($fecha),
        eventosapp_whatsapp_template_text_value($hora),
        eventosapp_whatsapp_template_text_value($direccion),
        eventosapp_whatsapp_template_text_value($localidad),
        eventosapp_whatsapp_template_text_value($assets['google_wallet']),
        eventosapp_whatsapp_template_text_value($assets['apple_wallet']),
        eventosapp_whatsapp_template_text_value($assets['ics_url']),
        eventosapp_whatsapp_template_text_value($assets['pdf_url']),
    ];
}

function eventosapp_whatsapp_build_body_component_from_values(array $values) {
    $parameters = [];
    foreach ( $values as $value ) {
        $parameters[] = [
            'type' => 'text',
            'text' => eventosapp_whatsapp_template_text_value($value),
        ];
    }

    return [
        'type' => 'body',
        'parameters' => $parameters,
    ];
}

function eventosapp_whatsapp_build_ticket_template_components($ticket_id, $template_type = '') {
    $ticket_id = absint($ticket_id);
    $template_type = $template_type === 'virtual' ? 'virtual' : eventosapp_whatsapp_get_ticket_template_type($ticket_id);
    $components = [];

    if ( $template_type === 'presencial' ) {
        $assets = eventosapp_whatsapp_get_ticket_asset_urls($ticket_id, 'presencial');
        if ( empty($assets['qr_url']) ) {
            return [
                'ok' => false,
                'message' => 'No se pudo generar u obtener una URL pública del QR para la plantilla presencial.',
                'components' => [],
            ];
        }

        $components[] = [
            'type' => 'header',
            'parameters' => [
                [
                    'type' => 'image',
                    'image' => [
                        'link' => $assets['qr_url'],
                    ],
                ],
            ],
        ];
    }

    $components[] = eventosapp_whatsapp_build_body_component_from_values(eventosapp_whatsapp_get_ticket_body_values($ticket_id, $template_type));

    return [
        'ok' => true,
        'message' => 'Componentes de plantilla construidos correctamente.',
        'components' => $components,
    ];
}

function eventosapp_whatsapp_get_template_submission_definitions($settings = null) {
    $settings = is_array($settings) ? wp_parse_args($settings, eventosapp_whatsapp_default_settings()) : eventosapp_whatsapp_get_settings();
    $category = ! empty($settings['template_category']) ? strtoupper((string)$settings['template_category']) : 'UTILITY';
    if ( ! in_array($category, ['UTILITY', 'MARKETING'], true) ) {
        $category = 'UTILITY';
    }

    $footer = trim((string)($settings['template_footer'] ?? 'EventosApp'));
    $definitions = [];

    $presencial_components = [
        [
            'type' => 'HEADER',
            'format' => 'IMAGE',
        ],
        [
            'type' => 'BODY',
            'text' => eventosapp_whatsapp_get_template_body_text('presencial'),
            'example' => [
                'body_text' => [
                    [
                        'María Pérez',
                        'Congreso Empresarial 2026',
                        'TCK-001',
                        'Presencial',
                        'Mayo 25, 2026',
                        '8:00 AM - 5:00 PM',
                        'Centro de Eventos',
                        'VIP',
                        'https://eventosapp.com/google-wallet-demo',
                        'https://eventosapp.com/apple-wallet-demo.pkpass',
                        'https://eventosapp.com/calendario-demo.ics',
                        'https://eventosapp.com/ticket-demo.pdf',
                    ],
                ],
            ],
        ],
    ];

    if ( ! empty($settings['template_presencial_header_handle']) ) {
        $presencial_components[0]['example'] = [
            'header_handle' => [
                sanitize_text_field((string)$settings['template_presencial_header_handle']),
            ],
        ];
    }

    if ( $footer !== '' ) {
        $presencial_components[] = [
            'type' => 'FOOTER',
            'text' => $footer,
        ];
    }

    $definitions['presencial'] = [
        'name' => sanitize_key($settings['template_presencial_name'] ?? 'eventosapp_ticket_presencial'),
        'language' => sanitize_text_field($settings['template_presencial_language'] ?? 'es'),
        'category' => $category,
        'components' => $presencial_components,
        '_requires_header_handle' => true,
    ];

    $virtual_components = [
        [
            'type' => 'BODY',
            'text' => eventosapp_whatsapp_get_template_body_text('virtual'),
            'example' => [
                'body_text' => [
                    [
                        'María Pérez',
                        'Webinar Empresarial 2026',
                        'TCK-002',
                        'Virtual',
                        'Mayo 25, 2026',
                        '8:00 AM - 10:00 AM',
                        'Zoom',
                        'https://eventosapp.com/acceso-virtual-demo',
                        'https://eventosapp.com/calendario-virtual-demo.ics',
                    ],
                ],
            ],
        ],
    ];

    if ( $footer !== '' ) {
        $virtual_components[] = [
            'type' => 'FOOTER',
            'text' => $footer,
        ];
    }

    $definitions['virtual'] = [
        'name' => sanitize_key($settings['template_virtual_name'] ?? 'eventosapp_ticket_virtual'),
        'language' => sanitize_text_field($settings['template_virtual_language'] ?? 'es'),
        'category' => $category,
        'components' => $virtual_components,
        '_requires_header_handle' => false,
    ];

    return $definitions;
}

function eventosapp_whatsapp_api_create_template(array $definition, $settings = null) {
    $settings = is_array($settings) ? wp_parse_args($settings, eventosapp_whatsapp_default_settings()) : eventosapp_whatsapp_get_settings();

    $waba_id = preg_replace('/\D+/', '', (string)($settings['waba_id'] ?? ''));
    $access_token = trim((string)($settings['access_token'] ?? ''));
    $api_version = preg_replace('/[^a-zA-Z0-9\.\-_]/', '', (string)($settings['api_version'] ?? 'v23.0'));

    if ( $waba_id === '' || $access_token === '' ) {
        return [
            'ok' => false,
            'message' => 'Faltan WhatsApp Business Account ID o Access Token para crear plantillas.',
            'response' => null,
        ];
    }

    if ( $api_version === '' ) {
        $api_version = 'v23.0';
    }

    $payload = [
        'name' => sanitize_key($definition['name'] ?? ''),
        'language' => sanitize_text_field($definition['language'] ?? 'es'),
        'category' => sanitize_text_field($definition['category'] ?? 'UTILITY'),
        'allow_category_change' => true,
        'components' => $definition['components'] ?? [],
    ];

    if ( $payload['name'] === '' ) {
        return [
            'ok' => false,
            'message' => 'El nombre de la plantilla está vacío.',
            'response' => null,
        ];
    }

    $endpoint = sprintf(
        'https://graph.facebook.com/%s/%s/message_templates',
        rawurlencode($api_version),
        rawurlencode($waba_id)
    );

    $response = wp_remote_post($endpoint, [
        'timeout' => min(60, max(5, absint($settings['request_timeout'] ?? 20))),
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    if ( is_wp_error($response) ) {
        return [
            'ok' => false,
            'message' => $response->get_error_message(),
            'response' => null,
        ];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    $ok = $code >= 200 && $code < 300;

    eventosapp_whatsapp_log($ok ? 'Plantilla enviada a revisión' : 'Error creando plantilla', [
        'template' => $payload['name'],
        'http_code' => $code,
        'response' => $decoded ?: $body,
    ]);

    return [
        'ok' => $ok,
        'message' => $ok ? 'Plantilla enviada a revisión: ' . $payload['name'] : eventosapp_whatsapp_extract_api_error($decoded, $body, $code),
        'http_code' => $code,
        'response' => $decoded ?: $body,
    ];
}

function eventosapp_whatsapp_submit_final_templates_to_meta() {
    $settings = eventosapp_whatsapp_get_settings();
    $definitions = eventosapp_whatsapp_get_template_submission_definitions($settings);
    $results = [];
    $ok_count = 0;
    $attempt_count = 0;

    foreach ( $definitions as $type => $definition ) {
        if ( $type === 'presencial' && ! empty($definition['_requires_header_handle']) && empty($settings['template_presencial_header_handle']) ) {
            $results[$type] = [
                'ok' => false,
                'message' => 'No se envió la plantilla presencial porque falta el Header handle de imagen de ejemplo. Puedes crearla manualmente desde WhatsApp Manager o pegar aquí el handle generado por Meta.',
            ];
            continue;
        }

        unset($definition['_requires_header_handle']);
        $attempt_count++;
        $results[$type] = eventosapp_whatsapp_api_create_template($definition, $settings);

        if ( ! empty($results[$type]['ok']) ) {
            $ok_count++;
        }
    }

    $summary = $ok_count . ' de ' . $attempt_count . ' solicitudes enviadas correctamente.';
    if ( $attempt_count === 0 ) {
        $summary = 'No se envió ninguna solicitud. Revisa los datos requeridos para las plantillas.';
    }

    $settings['last_template_submission'] = [
        'date' => current_time('mysql'),
        'summary' => $summary,
        'results' => $results,
    ];
    update_option(EVENTOSAPP_WHATSAPP_OPTION, $settings, false);

    return [
        'ok' => $attempt_count > 0 && $ok_count === $attempt_count,
        'message' => $summary,
        'results' => $results,
    ];
}

/**
 * Construye mensaje del ticket para WhatsApp.
 */
function eventosapp_whatsapp_build_ticket_message($ticket_id) {
    $ticket_id = absint($ticket_id);
    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    if ( ! $ticket_id || ! $event_id ) {
        return '';
    }

    $settings = eventosapp_whatsapp_get_settings();
    $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
    $nombre = trim(get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' . get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true));
    $localidad = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);
    $modalidad = function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label($ticket_id) : get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
    $fecha = eventosapp_whatsapp_get_event_date_label($event_id);
    $hora_inicio = get_post_meta($event_id, '_eventosapp_hora_inicio', true);
    $hora_cierre = get_post_meta($event_id, '_eventosapp_hora_cierre', true);
    $direccion = get_post_meta($event_id, '_eventosapp_direccion', true);
    $organizador = function_exists('eventosapp_get_nombre_organizador') ? eventosapp_get_nombre_organizador($event_id) : get_post_meta($event_id, '_eventosapp_organizador', true);
    $virtual_platform = get_post_meta($event_id, '_eventosapp_virtual_platform', true);

    $google_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android_url', true);
    if ( ! $google_wallet ) $google_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_android', true);

    $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple_url', true);
    if ( ! $apple_wallet ) $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_wallet_apple', true);
    if ( ! $apple_wallet ) $apple_wallet = get_post_meta($ticket_id, '_eventosapp_ticket_pkpass_url', true);

    $ics_url = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
    if ( ! $ics_url && function_exists('eventosapp_ticket_generar_ics') ) {
        eventosapp_ticket_generar_ics($ticket_id);
        $ics_url = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
    }

    $pdf_url = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
    if ( ! $pdf_url && function_exists('eventosapp_ticket_generar_pdf') && ! (function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id)) ) {
        eventosapp_ticket_generar_pdf($ticket_id);
        $pdf_url = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
    }

    $virtual_landing = '';
    if ( function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id) && function_exists('eventosapp_get_virtual_landing_url') ) {
        $virtual_landing = eventosapp_get_virtual_landing_url($ticket_id);
    }

    $intro = eventosapp_whatsapp_replace_message_vars($settings['message_intro'], $ticket_id, $event_id);

    $lines = [];
    $lines[] = $intro ?: ('Hola' . ($nombre ? ' ' . $nombre : '') . ', este es tu ticket.');
    $lines[] = '';
    $lines[] = '🎟️ *' . get_the_title($event_id) . '*';
    if ( $organizador ) $lines[] = 'Organiza: ' . $organizador;
    if ( $nombre ) $lines[] = 'Asistente: ' . $nombre;
    if ( $ticket_code ) $lines[] = 'Ticket: ' . $ticket_code;
    if ( $localidad ) $lines[] = 'Localidad: ' . $localidad;
    if ( $modalidad ) $lines[] = 'Modalidad: ' . $modalidad;
    if ( $fecha ) $lines[] = 'Fecha: ' . $fecha;
    if ( $hora_inicio ) $lines[] = 'Hora: ' . $hora_inicio . ($hora_cierre ? ' - ' . $hora_cierre : '');
    if ( $direccion ) $lines[] = 'Lugar: ' . $direccion;
    if ( $virtual_platform ) $lines[] = 'Plataforma virtual: ' . $virtual_platform;
    if ( $virtual_landing ) $lines[] = 'Acceso virtual: ' . $virtual_landing;

    $links = [];
    if ( $google_wallet ) $links[] = 'Google Wallet: ' . $google_wallet;
    if ( $apple_wallet ) $links[] = 'Apple Wallet: ' . $apple_wallet;
    if ( $ics_url ) $links[] = 'Agregar al calendario: ' . $ics_url;
    if ( $pdf_url ) $links[] = 'Descargar PDF: ' . $pdf_url;

    if ( ! empty($links) ) {
        $lines[] = '';
        $lines[] = 'Enlaces:';
        foreach ( $links as $link ) {
            $lines[] = '- ' . $link;
        }
    }

    $message = implode("\n", array_filter($lines, function($line) {
        return $line !== null;
    }));

    if ( function_exists('mb_strlen') && mb_strlen($message) > 1000 ) {
        $message = mb_substr($message, 0, 997) . '...';
    } elseif ( strlen($message) > 1000 ) {
        $message = substr($message, 0, 997) . '...';
    }

    return $message;
}

/**
 * Obtiene valor de campo para reglas.
 */
function eventosapp_whatsapp_get_rule_field_value($ticket_id, $field) {
    $map = [
        'nombre'      => '_eventosapp_asistente_nombre',
        'apellido'    => '_eventosapp_asistente_apellido',
        'cedula'      => '_eventosapp_asistente_cc',
        'email'       => '_eventosapp_asistente_email',
        'telefono'    => '_eventosapp_asistente_tel',
        'empresa'     => '_eventosapp_asistente_empresa',
        'nit'         => '_eventosapp_asistente_nit',
        'cargo'       => '_eventosapp_asistente_cargo',
        'ciudad'      => '_eventosapp_asistente_ciudad',
        'pais'        => '_eventosapp_asistente_pais',
        'localidad'   => '_eventosapp_asistente_localidad',
        'estado_pago' => '_eventosapp_estado_pago',
    ];

    if ( $field === 'modalidad' ) {
        return function_exists('eventosapp_get_ticket_modalidad') ? eventosapp_get_ticket_modalidad($ticket_id) : get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
    }

    if ( strpos($field, 'extra:') === 0 ) {
        $extra_key = sanitize_key(substr($field, 6));
        return get_post_meta($ticket_id, '_eventosapp_extra_' . $extra_key, true);
    }

    if ( isset($map[$field]) ) {
        return get_post_meta($ticket_id, $map[$field], true);
    }

    return '';
}

function eventosapp_whatsapp_compare_values($actual, $operator, $expected) {
    $actual = is_scalar($actual) ? (string) $actual : '';
    $expected = is_scalar($expected) ? (string) $expected : '';

    $actual_norm = function_exists('remove_accents') ? remove_accents($actual) : $actual;
    $expected_norm = function_exists('remove_accents') ? remove_accents($expected) : $expected;

    $actual_norm = function_exists('mb_strtolower') ? mb_strtolower($actual_norm) : strtolower($actual_norm);
    $expected_norm = function_exists('mb_strtolower') ? mb_strtolower($expected_norm) : strtolower($expected_norm);

    switch ( $operator ) {
        case 'not_equals':
            return $actual_norm !== $expected_norm;
        case 'contains':
            return $expected_norm === '' ? true : strpos($actual_norm, $expected_norm) !== false;
        case 'not_contains':
            return $expected_norm === '' ? false : strpos($actual_norm, $expected_norm) === false;
        case 'starts_with':
            return $expected_norm === '' ? true : strpos($actual_norm, $expected_norm) === 0;
        case 'ends_with':
            if ( $expected_norm === '' ) return true;
            return substr($actual_norm, -strlen($expected_norm)) === $expected_norm;
        case 'empty':
            return trim($actual) === '';
        case 'not_empty':
            return trim($actual) !== '';
        case 'equals':
        default:
            return $actual_norm === $expected_norm;
    }
}

function eventosapp_whatsapp_rule_matches_ticket($ticket_id, $rule) {
    $conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];

    if ( empty($conditions) ) {
        return true;
    }

    $match = isset($rule['match']) && $rule['match'] === 'any' ? 'any' : 'all';
    $results = [];

    foreach ( $conditions as $condition ) {
        $field = isset($condition['field']) ? (string) $condition['field'] : '';
        $operator = isset($condition['operator']) ? (string) $condition['operator'] : 'equals';
        $expected = isset($condition['value']) ? (string) $condition['value'] : '';
        $actual = eventosapp_whatsapp_get_rule_field_value($ticket_id, $field);
        $results[] = eventosapp_whatsapp_compare_values($actual, $operator, $expected);
    }

    return $match === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
}

/**
 * Decide si un ticket pasa filtros del evento.
 */
function eventosapp_whatsapp_ticket_passes_rules($ticket_id, $event_id) {
    $rules = eventosapp_whatsapp_normalize_rules(get_post_meta($event_id, '_eventosapp_whatsapp_rules', true));

    if ( empty($rules) ) {
        return [
            'allowed' => true,
            'reason' => 'Sin reglas: envío permitido.',
        ];
    }

    $has_allow_rules = false;
    $matched_allow = false;

    foreach ( $rules as $rule_index => $rule ) {
        if ( empty($rule['enabled']) || $rule['enabled'] !== '1' ) {
            continue;
        }

        if ( $rule['action'] === 'allow' ) {
            $has_allow_rules = true;
        }

        $matches = eventosapp_whatsapp_rule_matches_ticket($ticket_id, $rule);
        if ( ! $matches ) {
            continue;
        }

        if ( $rule['action'] === 'deny' ) {
            return [
                'allowed' => false,
                'reason' => 'Bloqueado por regla: ' . ($rule['name'] ?: ('Regla #' . ((int)$rule_index + 1))),
            ];
        }

        if ( $rule['action'] === 'allow' ) {
            $matched_allow = true;
        }
    }

    if ( $has_allow_rules && ! $matched_allow ) {
        return [
            'allowed' => false,
            'reason' => 'No cumple ninguna regla de envío permitida.',
        ];
    }

    return [
        'allowed' => true,
        'reason' => $matched_allow ? 'Cumple regla de envío.' : 'Sin regla restrictiva aplicable.',
    ];
}

/**
 * Envía el ticket por WhatsApp.
 */
function eventosapp_whatsapp_send_ticket($ticket_id, $args = []) {
    $ticket_id = absint($ticket_id);
    $args = wp_parse_args($args, [
        'context' => 'unknown',
        'force' => false,
        'skip_rules' => false,
        'source_key' => '',
        'phone_override' => '',
    ]);

    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        return ['ok' => false, 'message' => 'Ticket inválido.'];
    }

    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
        eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', 'El ticket no tiene evento asociado.', $args);
        return ['ok' => false, 'message' => 'El ticket no tiene evento asociado.'];
    }

    if ( get_post_meta($event_id, '_eventosapp_ticket_whatsapp_enabled', true) !== '1' ) {
        return ['ok' => false, 'message' => 'WhatsApp no está activo para este evento.'];
    }

    $source_key = sanitize_text_field((string)$args['source_key']);
    if ( ! $args['force'] && $source_key !== '' ) {
        $last_source = get_post_meta($ticket_id, '_eventosapp_whatsapp_last_source_key', true);
        if ( $last_source === $source_key ) {
            return ['ok' => true, 'message' => 'WhatsApp ya había sido enviado para este evento de correo.', 'skipped_duplicate' => true];
        }
    }

    $lock_key = 'eventosapp_whatsapp_send_lock_' . $ticket_id;
    if ( get_transient($lock_key) && ! $args['force'] ) {
        return ['ok' => true, 'message' => 'Envío WhatsApp omitido por bloqueo temporal anti-duplicado.', 'skipped_duplicate' => true];
    }
    set_transient($lock_key, 1, 60);

    if ( empty($args['skip_rules']) ) {
        $rules_result = eventosapp_whatsapp_ticket_passes_rules($ticket_id, $event_id);
        if ( empty($rules_result['allowed']) ) {
            delete_transient($lock_key);
            eventosapp_whatsapp_add_ticket_log($ticket_id, 'skipped', $rules_result['reason'], $args);
            return ['ok' => true, 'message' => $rules_result['reason'], 'skipped_rules' => true];
        }
    }

    $settings = eventosapp_whatsapp_get_settings();
    $phone_raw = ! empty($args['phone_override']) ? $args['phone_override'] : get_post_meta($ticket_id, '_eventosapp_asistente_tel', true);
    $phone = eventosapp_whatsapp_normalize_phone($phone_raw, $settings['default_country_code']);

    if ( ! $phone ) {
        delete_transient($lock_key);
        eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', 'No hay un celular válido para WhatsApp.', $args);
        return ['ok' => false, 'message' => 'No hay un celular válido para WhatsApp.'];
    }

    $delivery_mode = isset($settings['ticket_delivery_mode']) && $settings['ticket_delivery_mode'] === 'legacy' ? 'legacy' : 'template';

    if ( $delivery_mode === 'template' ) {
        $template_type = eventosapp_whatsapp_get_ticket_template_type($ticket_id);
        $components_result = eventosapp_whatsapp_build_ticket_template_components($ticket_id, $template_type);

        if ( empty($components_result['ok']) ) {
            delete_transient($lock_key);
            eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', $components_result['message'] ?? 'No se pudieron construir los componentes de la plantilla.', $args, $phone);
            return [
                'ok' => false,
                'message' => $components_result['message'] ?? 'No se pudieron construir los componentes de la plantilla.',
            ];
        }

        if ( $template_type === 'virtual' ) {
            $template_name = $settings['template_virtual_name'] ?? 'eventosapp_ticket_virtual';
            $template_language = $settings['template_virtual_language'] ?? 'es';
        } else {
            $template_name = $settings['template_presencial_name'] ?? 'eventosapp_ticket_presencial';
            $template_language = $settings['template_presencial_language'] ?? 'es';
        }

        $result = eventosapp_whatsapp_api_send_template(
            $phone,
            $template_name,
            $template_language,
            $components_result['components'],
            $settings
        );

        $args['template_type'] = $template_type;
        $args['template_name'] = $template_name;
    } else {
        $message = eventosapp_whatsapp_build_ticket_message($ticket_id);
        if ( $message === '' ) {
            delete_transient($lock_key);
            eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', 'No se pudo construir el mensaje del ticket.', $args);
            return ['ok' => false, 'message' => 'No se pudo construir el mensaje del ticket.'];
        }

        $qr_url = eventosapp_whatsapp_ensure_qr_url($ticket_id);

        if ( $qr_url ) {
            $payload = [
                'type' => 'image',
                'image' => [
                    'link' => $qr_url,
                    'caption' => $message,
                ],
            ];
        } else {
            $payload = [
                'type' => 'text',
                'text' => [
                    'preview_url' => true,
                    'body' => $message,
                ],
            ];
        }

        $result = eventosapp_whatsapp_api_send_message($phone, $payload, $settings);
    }

    // No se elimina el transient aquí: lo dejamos expirar para evitar duplicados
    // cuando el flujo de correo actualiza varios metadatos en la misma ejecución.
    if ( ! empty($result['ok']) ) {
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_status', 'aceptado_meta');
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_sent_at', current_time('mysql'));
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_to', $phone);
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_error', '');
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_response', $result['response'] ?? []);
        update_post_meta($ticket_id, '_eventosapp_whatsapp_delivery_mode', $delivery_mode);

        $message_id = isset($result['message_id']) ? sanitize_text_field((string)$result['message_id']) : eventosapp_whatsapp_extract_message_id($result['response'] ?? []);
        if ( $message_id !== '' ) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_message_id', $message_id);
            eventosapp_whatsapp_register_message_map($message_id, $ticket_id, $args['context'] ?? 'unknown', $phone);
        }

        if ( $source_key !== '' ) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_source_key', $source_key);
        }

        eventosapp_whatsapp_add_ticket_log($ticket_id, 'aceptado_meta', $result['message'] ?? 'Mensaje aceptado por Meta.', $args, $phone, $result);
    } else {
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_status', 'error');
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_error', $result['message'] ?? 'Error desconocido.');
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_response', $result['response'] ?? []);
        update_post_meta($ticket_id, '_eventosapp_whatsapp_delivery_mode', $delivery_mode);
        eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', $result['message'] ?? 'Error desconocido.', $args, $phone, $result);
    }

    return $result;
}

function eventosapp_whatsapp_add_ticket_log($ticket_id, $status, $message, $args = [], $phone = '', $result = []) {
    $history = get_post_meta($ticket_id, '_eventosapp_whatsapp_history', true);
    if ( ! is_array($history) ) {
        $history = [];
    }

    $history[] = [
        'date' => current_time('mysql'),
        'status' => sanitize_text_field($status),
        'message' => sanitize_text_field((string)$message),
        'context' => sanitize_text_field((string)($args['context'] ?? 'unknown')),
        'source_key' => sanitize_text_field((string)($args['source_key'] ?? '')),
        'template_type' => sanitize_text_field((string)($args['template_type'] ?? '')),
        'template_name' => sanitize_text_field((string)($args['template_name'] ?? '')),
        'to' => sanitize_text_field($phone),
        'http_code' => isset($result['http_code']) ? (int)$result['http_code'] : 0,
        'message_id' => isset($result['message_id']) ? sanitize_text_field((string)$result['message_id']) : eventosapp_whatsapp_extract_message_id($result['response'] ?? []),
    ];

    if ( count($history) > 50 ) {
        $history = array_slice($history, -50);
    }

    update_post_meta($ticket_id, '_eventosapp_whatsapp_history', $history);
}

/**
 * Webhook público para verificación y estados de entrega de WhatsApp.
 * URL en Meta: /wp-admin/admin-post.php?action=eventosapp_whatsapp_webhook
 */
add_action('admin_post_nopriv_eventosapp_whatsapp_webhook', 'eventosapp_whatsapp_handle_webhook_request');
add_action('admin_post_eventosapp_whatsapp_webhook', 'eventosapp_whatsapp_handle_webhook_request');

function eventosapp_whatsapp_handle_webhook_request() {
    $settings = eventosapp_whatsapp_get_settings();

    if ( isset($_GET['hub_mode']) || isset($_GET['hub.mode']) ) {
        $mode = isset($_GET['hub_mode']) ? sanitize_text_field(wp_unslash($_GET['hub_mode'])) : sanitize_text_field(wp_unslash($_GET['hub.mode']));
        $token = isset($_GET['hub_verify_token']) ? sanitize_text_field(wp_unslash($_GET['hub_verify_token'])) : (isset($_GET['hub.verify_token']) ? sanitize_text_field(wp_unslash($_GET['hub.verify_token'])) : '');
        $challenge = isset($_GET['hub_challenge']) ? sanitize_text_field(wp_unslash($_GET['hub_challenge'])) : (isset($_GET['hub.challenge']) ? sanitize_text_field(wp_unslash($_GET['hub.challenge'])) : '');

        if ( $mode === 'subscribe' && $challenge !== '' && ! empty($settings['webhook_verify_token']) && hash_equals((string)$settings['webhook_verify_token'], (string)$token) ) {
            status_header(200);
            header('Content-Type: text/plain; charset=utf-8');
            echo $challenge;
            exit;
        }

        eventosapp_whatsapp_log('Webhook verificación rechazada', [
            'mode' => $mode,
            'token_present' => $token !== '',
            'challenge_present' => $challenge !== '',
        ]);
        status_header(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);

    if ( ! is_array($payload) ) {
        eventosapp_whatsapp_log('Webhook recibido con JSON inválido', [
            'raw' => substr((string)$raw, 0, 500),
        ]);
        status_header(400);
        wp_send_json(['ok' => false, 'message' => 'JSON inválido']);
    }

    eventosapp_whatsapp_process_webhook_payload($payload);

    status_header(200);
    wp_send_json(['ok' => true]);
}

function eventosapp_whatsapp_process_webhook_payload($payload) {
    $entries = isset($payload['entry']) && is_array($payload['entry']) ? $payload['entry'] : [];

    foreach ( $entries as $entry ) {
        $changes = isset($entry['changes']) && is_array($entry['changes']) ? $entry['changes'] : [];

        foreach ( $changes as $change ) {
            $value = isset($change['value']) && is_array($change['value']) ? $change['value'] : [];

            if ( ! empty($value['statuses']) && is_array($value['statuses']) ) {
                foreach ( $value['statuses'] as $status ) {
                    if ( is_array($status) ) {
                        eventosapp_whatsapp_process_webhook_status($status);
                    }
                }
            }

            if ( ! empty($value['messages']) && is_array($value['messages']) ) {
                foreach ( $value['messages'] as $message ) {
                    if ( is_array($message) ) {
                        eventosapp_whatsapp_process_webhook_inbound_message($message);
                    }
                }
            }
        }
    }
}

function eventosapp_whatsapp_process_webhook_status($status) {
    $message_id = ! empty($status['id']) ? sanitize_text_field((string)$status['id']) : '';
    $delivery_status = ! empty($status['status']) ? sanitize_text_field((string)$status['status']) : '';
    $recipient_id = ! empty($status['recipient_id']) ? sanitize_text_field((string)$status['recipient_id']) : '';
    $timestamp = ! empty($status['timestamp']) ? absint($status['timestamp']) : 0;
    $delivery_at = $timestamp ? date_i18n('Y-m-d H:i:s', $timestamp) : current_time('mysql');

    if ( $message_id === '' || $delivery_status === '' ) {
        return;
    }

    $error_message = '';
    if ( ! empty($status['errors'][0]) && is_array($status['errors'][0]) ) {
        $error = $status['errors'][0];
        $error_message = sanitize_text_field(trim(($error['code'] ?? '') . ' ' . ($error['title'] ?? '') . ' ' . ($error['message'] ?? '')));
    }

    $map = eventosapp_whatsapp_get_message_map();
    $mapped = isset($map[$message_id]) && is_array($map[$message_id]) ? $map[$message_id] : [];
    $ticket_id = ! empty($mapped['ticket_id']) ? absint($mapped['ticket_id']) : 0;

    eventosapp_whatsapp_log('Webhook estado WhatsApp', [
        'message_id' => $message_id,
        'status' => $delivery_status,
        'recipient_id' => $recipient_id,
        'ticket_id' => $ticket_id,
        'error' => $error_message,
    ]);

    if ( $ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket' ) {
        update_post_meta($ticket_id, '_eventosapp_whatsapp_delivery_status', $delivery_status);
        update_post_meta($ticket_id, '_eventosapp_whatsapp_delivery_at', $delivery_at);

        if ( $delivery_status === 'failed' && $error_message !== '' ) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_status', 'fallido_webhook');
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_error', $error_message);
        }

        eventosapp_whatsapp_add_ticket_log($ticket_id, 'webhook_' . $delivery_status, $error_message ?: 'Estado recibido por webhook: ' . $delivery_status, [
            'context' => 'webhook_status',
            'source_key' => $message_id,
        ], $recipient_id, [
            'http_code' => 0,
            'message_id' => $message_id,
        ]);
    } elseif ( ! empty($mapped['context']) && $mapped['context'] === 'quick_test' ) {
        eventosapp_whatsapp_update_last_test_delivery_status($message_id, $delivery_status, $error_message);
    }
}

function eventosapp_whatsapp_update_last_test_delivery_status($message_id, $delivery_status, $error_message = '') {
    $settings = eventosapp_whatsapp_get_settings();
    if ( empty($settings['last_test_result']) || ! is_array($settings['last_test_result']) ) {
        return;
    }
    if ( empty($settings['last_test_result']['message_id']) || $settings['last_test_result']['message_id'] !== $message_id ) {
        return;
    }

    $settings['last_test_result']['delivery_status'] = $delivery_status;
    if ( $error_message !== '' ) {
        $settings['last_test_result']['message'] = $error_message;
    }
    update_option(EVENTOSAPP_WHATSAPP_OPTION, $settings, false);
}

function eventosapp_whatsapp_process_webhook_inbound_message($message) {
    $from = ! empty($message['from']) ? sanitize_text_field((string)$message['from']) : '';
    if ( $from === '' ) {
        return;
    }

    $inbound = get_option('eventosapp_whatsapp_last_inbound_by_phone', []);
    if ( ! is_array($inbound) ) {
        $inbound = [];
    }

    $inbound[$from] = [
        'last_at' => current_time('mysql'),
        'message_id' => ! empty($message['id']) ? sanitize_text_field((string)$message['id']) : '',
        'type' => ! empty($message['type']) ? sanitize_text_field((string)$message['type']) : '',
    ];

    if ( count($inbound) > 500 ) {
        $inbound = array_slice($inbound, -500, null, true);
    }

    update_option('eventosapp_whatsapp_last_inbound_by_phone', $inbound, false);

    eventosapp_whatsapp_log('Webhook mensaje entrante WhatsApp', [
        'from' => $from,
        'message_id' => $inbound[$from]['message_id'],
        'type' => $inbound[$from]['type'],
    ]);
}

/**
 * Disparo cuando el correo queda registrado como enviado.
 */
function eventosapp_whatsapp_trigger_from_email_meta($meta_id, $object_id, $meta_key, $_meta_value) {
    $ticket_id = absint($object_id);
    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        return;
    }

    if ( $meta_key === '_eventosapp_ticket_email_history' ) {
        $history = get_post_meta($ticket_id, '_eventosapp_ticket_email_history', true);
        $count = is_array($history) ? count($history) : 0;
        $last = ($count > 0 && is_array($history[$count - 1])) ? $history[$count - 1] : [];
        $source_key = 'email_history:' . $count . ':' . md5(wp_json_encode($last));
        eventosapp_whatsapp_send_ticket($ticket_id, [
            'context' => 'email_history',
            'source_key' => $source_key,
        ]);
        return;
    }

    if ( $meta_key === '_eventosapp_ticket_email_sent_status' ) {
        $status = get_post_meta($ticket_id, '_eventosapp_ticket_email_sent_status', true);
        if ( $status === 'enviado' ) {
            $last_email_at = get_post_meta($ticket_id, '_eventosapp_ticket_last_email_at', true);
            $source_key = 'email_status:' . md5((string)$last_email_at . ':' . (string)$status);
            eventosapp_whatsapp_send_ticket($ticket_id, [
                'context' => 'email_status',
                'source_key' => $source_key,
            ]);
        }
    }
}
add_action('added_post_meta', 'eventosapp_whatsapp_trigger_from_email_meta', 20, 4);
add_action('updated_post_meta', 'eventosapp_whatsapp_trigger_from_email_meta', 20, 4);

/**
 * Compatibilidad con posibles hooks explícitos del módulo de correo.
 */
add_action('eventosapp_after_ticket_email_sent', function($ticket_id) {
    eventosapp_whatsapp_send_ticket(absint($ticket_id), [
        'context' => 'email_hook',
        'source_key' => 'email_hook:' . time(),
    ]);
}, 20, 1);

/**
 * Puente para el botón admin-post de correo: si el correo redirecciona con exit,
 * este shutdown todavía puede validar el estado final y disparar WhatsApp.
 */
add_action('admin_init', function() {
    if ( empty($_REQUEST['action']) || sanitize_key(wp_unslash($_REQUEST['action'])) !== 'eventosapp_send_ticket_email' ) {
        return;
    }

    $ticket_id = 0;
    foreach ( ['ticket_id', 'post', 'post_id'] as $key ) {
        if ( isset($_REQUEST[$key]) ) {
            $ticket_id = absint($_REQUEST[$key]);
            break;
        }
    }

    if ( ! $ticket_id ) {
        return;
    }

    add_action('shutdown', function() use ($ticket_id) {
        if ( get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
            return;
        }
        if ( get_post_meta($ticket_id, '_eventosapp_ticket_email_sent_status', true) !== 'enviado' ) {
            return;
        }
        $last_email_at = get_post_meta($ticket_id, '_eventosapp_ticket_last_email_at', true);
        eventosapp_whatsapp_send_ticket($ticket_id, [
            'context' => 'email_adminpost_shutdown',
            'source_key' => 'email_adminpost:' . md5((string)$last_email_at),
        ]);
    });
});
