<?php
/**
 * EventosApp - Administración de Operador WhatsApp / Embedded Signup.
 *
 * Ruta: includes/admin/eventosapp-whatsapp-onboarding.php
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

function eventosapp_wa_operator_capability() {
    return apply_filters('eventosapp_wa_operator_capability', 'manage_options');
}

function eventosapp_wa_operator_can_manage() {
    return current_user_can(eventosapp_wa_operator_capability());
}

add_action('admin_menu', function() {
    add_submenu_page(
        'eventosapp_dashboard',
        'Operador WhatsApp',
        'Operador WhatsApp',
        eventosapp_wa_operator_capability(),
        'eventosapp_whatsapp_operator',
        'eventosapp_wa_operator_render_page',
        20
    );
}, 20);

/**
 * Metabox informativo en Clientes.
 */
add_action('add_meta_boxes_eventosapp_cliente', function() {
    add_meta_box(
        'eventosapp_cliente_whatsapp_operator',
        'WhatsApp Business Platform',
        'eventosapp_wa_operator_render_client_metabox',
        'eventosapp_cliente',
        'side',
        'high'
    );
});

function eventosapp_wa_operator_render_client_metabox($post) {
    if ( ! eventosapp_wa_operator_can_manage() ) {
        echo '<p>No tienes permisos para administrar conexiones de WhatsApp.</p>';
        return;
    }

    $accounts = eventosapp_wa_operator_get_accounts(['client_post_id'=>$post->ID]);
    $url = add_query_arg([
        'page' => 'eventosapp_whatsapp_operator',
        'tab' => 'onboarding',
        'client_id' => $post->ID,
    ], admin_url('admin.php'));

    if ( empty($accounts) ) {
        echo '<p>Este cliente todavía no tiene una cuenta de WhatsApp conectada.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url($url) . '">Conectar con Meta</a></p>';
        return;
    }

    foreach ( $accounts as $account ) {
        $phones = eventosapp_wa_operator_get_phones(['account_id'=>$account['id']]);
        echo '<div style="padding:8px 0;border-bottom:1px solid #dcdcde">';
        echo '<strong>' . esc_html($account['name'] ?: ('WABA ' . $account['waba_id'])) . '</strong><br>';
        echo '<small>WABA: ' . esc_html($account['waba_id']) . '</small><br>';
        echo '<small>Números: ' . esc_html(count($phones)) . ' · Webhook: ' . (! empty($account['webhook_subscribed']) ? 'suscrito' : 'pendiente') . '</small>';
        echo '</div>';
    }
    echo '<p><a class="button" href="' . esc_url($url) . '">Administrar conexión</a></p>';
}

/**
 * Helpers de avisos y redirección.
 */
function eventosapp_wa_operator_admin_url($tab = 'summary', $args = []) {
    $args = array_merge([
        'page' => 'eventosapp_whatsapp_operator',
        'tab'  => sanitize_key((string)$tab),
    ], is_array($args) ? $args : []);
    return add_query_arg($args, admin_url('admin.php'));
}

function eventosapp_wa_operator_redirect_notice($tab, $ok, $message, $extra = []) {
    $url = eventosapp_wa_operator_admin_url($tab, array_merge([
        'waop_ok' => $ok ? '1' : '0',
        'waop_msg'=> (string)$message,
    ], is_array($extra) ? $extra : []));
    wp_safe_redirect($url);
    exit;
}

function eventosapp_wa_operator_render_notice() {
    if ( ! isset($_GET['waop_msg']) ) {
        return;
    }
    $ok = ! empty($_GET['waop_ok']);
    $message = sanitize_text_field((string)wp_unslash($_GET['waop_msg']));
    echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . ' is-dismissible"><p><strong>EventosApp:</strong> ' . esc_html($message) . '</p></div>';
}

function eventosapp_wa_operator_clients() {
    return get_posts([
        'post_type'      => 'eventosapp_cliente',
        'post_status'    => ['publish','draft','private'],
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);
}

function eventosapp_wa_operator_require_admin_action($nonce_action) {
    if ( ! eventosapp_wa_operator_can_manage() ) {
        wp_die('No tienes permisos para administrar WhatsApp.');
    }
    check_admin_referer($nonce_action);
}

/**
 * Guardar configuración.
 */
add_action('admin_post_eventosapp_wa_operator_save_settings', function() {
    eventosapp_wa_operator_require_admin_action('eventosapp_wa_operator_save_settings');

    $current = eventosapp_wa_operator_get_settings();
    $settings = [
        'enabled'                  => isset($_POST['enabled']) ? '1' : '0',
        'app_id'                   => preg_replace('/\D+/', '', (string)wp_unslash($_POST['app_id'] ?? '')),
        'configuration_id'         => sanitize_text_field((string)wp_unslash($_POST['configuration_id'] ?? '')),
        'api_version'              => preg_replace('/[^a-zA-Z0-9._-]/', '', (string)wp_unslash($_POST['api_version'] ?? 'v23.0')),
        'provider_business_id'     => preg_replace('/\D+/', '', (string)wp_unslash($_POST['provider_business_id'] ?? '')),
        'system_user_id'           => preg_replace('/\D+/', '', (string)wp_unslash($_POST['system_user_id'] ?? '')),
        'system_user_token_encrypted' => $current['system_user_token_encrypted'] ?? '',
        'auto_assign_system_user'  => isset($_POST['auto_assign_system_user']) ? '1' : '0',
        'verify_webhook_signature' => isset($_POST['verify_webhook_signature']) ? '1' : '0',
        'auto_subscribe_webhook'   => isset($_POST['auto_subscribe_webhook']) ? '1' : '0',
        'auto_sync_after_signup'   => isset($_POST['auto_sync_after_signup']) ? '1' : '0',
        'auto_import_phone_numbers'=> isset($_POST['auto_import_phone_numbers']) ? '1' : '0',
        'token_health_enabled'     => isset($_POST['token_health_enabled']) ? '1' : '0',
        'token_warning_days'       => min(60, max(1, absint($_POST['token_warning_days'] ?? 7))),
        'app_secret_encrypted'     => $current['app_secret_encrypted'] ?? '',
    ];

    $secret = trim((string)wp_unslash($_POST['app_secret'] ?? ''));
    if ( $secret !== '' ) {
        $encrypted = eventosapp_wa_operator_encrypt_secret($secret);
        if ( is_wp_error($encrypted) ) {
            eventosapp_wa_operator_redirect_notice('settings', false, $encrypted->get_error_message());
        }
        $settings['app_secret_encrypted'] = $encrypted;
    }

    $system_user_token = trim((string)wp_unslash($_POST['system_user_token'] ?? ''));
    if ( $system_user_token !== '' ) {
        $encrypted = eventosapp_wa_operator_encrypt_secret($system_user_token);
        if ( is_wp_error($encrypted) ) {
            eventosapp_wa_operator_redirect_notice('settings', false, $encrypted->get_error_message());
        }
        $settings['system_user_token_encrypted'] = $encrypted;
    }

    if ( ! empty($settings['auto_assign_system_user']) ) {
        $effective_system_token = $system_user_token !== ''
            ? $system_user_token
            : eventosapp_wa_operator_decrypt_secret($settings['system_user_token_encrypted'] ?? '');
        if ( empty($settings['provider_business_id']) || empty($settings['system_user_id']) || $effective_system_token === '' ) {
            eventosapp_wa_operator_redirect_notice(
                'settings',
                false,
                'Para asignar automáticamente el System User debes guardar Business ID del proveedor, System User ID y su token administrativo.'
            );
        }
    }

    if ( ! empty($settings['verify_webhook_signature']) ) {
        $effective_secret = $secret !== ''
            ? $secret
            : eventosapp_wa_operator_decrypt_secret($settings['app_secret_encrypted'] ?? '');
        if ( $effective_secret === '' ) {
            eventosapp_wa_operator_redirect_notice(
                'settings',
                false,
                'No puedes activar la validación de firma sin guardar primero un App Secret válido.'
            );
        }
    }

    eventosapp_wa_operator_update_settings($settings);
    eventosapp_wa_operator_schedule_health();
    eventosapp_wa_operator_redirect_notice('settings', true, 'Configuración del operador guardada.');
});

/**
 * Conexión manual.
 */
add_action('admin_post_eventosapp_wa_operator_manual_connect', function() {
    eventosapp_wa_operator_require_admin_action('eventosapp_wa_operator_manual_connect');

    $result = eventosapp_wa_operator_manual_connect([
        'client_post_id' => absint($_POST['client_post_id'] ?? 0),
        'meta_business_id'=> preg_replace('/\D+/', '', (string)wp_unslash($_POST['meta_business_id'] ?? '')),
        'waba_id'         => preg_replace('/\D+/', '', (string)wp_unslash($_POST['waba_id'] ?? '')),
        'phone_number_id' => preg_replace('/\D+/', '', (string)wp_unslash($_POST['phone_number_id'] ?? '')),
        'account_name'    => sanitize_text_field((string)wp_unslash($_POST['account_name'] ?? '')),
        'access_token'    => trim((string)wp_unslash($_POST['access_token'] ?? '')),
        'is_default'      => isset($_POST['is_default']),
    ]);

    if ( is_wp_error($result) ) {
        eventosapp_wa_operator_redirect_notice('onboarding', false, $result->get_error_message());
    }
    eventosapp_wa_operator_redirect_notice('accounts', true, 'Cuenta conectada, token cifrado y activos sincronizados.');
});

/**
 * Acciones administrativas sobre cuentas y números.
 */
add_action('admin_post_eventosapp_wa_operator_action', function() {
    eventosapp_wa_operator_require_admin_action('eventosapp_wa_operator_action');

    $operation = sanitize_key((string)wp_unslash($_POST['operation'] ?? ''));
    $account_id = absint($_POST['account_id'] ?? 0);
    $phone_number_id = preg_replace('/\D+/', '', (string)wp_unslash($_POST['phone_number_id'] ?? ''));
    $tab = sanitize_key((string)wp_unslash($_POST['return_tab'] ?? 'accounts'));
    $result = null;
    $success_message = '';

    switch ( $operation ) {
        case 'sync_account':
            $account = eventosapp_wa_operator_get_account($account_id);
            $result = $account ? eventosapp_wa_operator_sync_waba($account['waba_id'], $account_id) : new WP_Error('account_not_found', 'La cuenta no existe.');
            $success_message = 'Cuenta y números sincronizados con Meta.';
            break;

        case 'subscribe_account':
            $account = eventosapp_wa_operator_get_account($account_id);
            $result = $account ? eventosapp_wa_operator_subscribe_waba($account['waba_id']) : new WP_Error('account_not_found', 'La cuenta no existe.');
            $success_message = 'Aplicación suscrita al webhook de la WABA.';
            break;

        case 'check_subscription':
            $account = eventosapp_wa_operator_get_account($account_id);
            $result = $account ? eventosapp_wa_operator_check_waba_subscription($account['waba_id']) : new WP_Error('account_not_found', 'La cuenta no existe.');
            $success_message = ! is_wp_error($result) && ! empty($result['subscribed'])
                ? 'La aplicación aparece suscrita a la WABA.'
                : 'Consulta completada. La aplicación no aparece suscrita.';
            break;

        case 'assign_system_user':
            $account = eventosapp_wa_operator_get_account($account_id);
            $result = $account ? eventosapp_wa_operator_assign_system_user($account['waba_id']) : new WP_Error('account_not_found', 'La cuenta no existe.');
            $success_message = 'System User asignado a la WABA con permisos MANAGE y DEVELOP.';
            break;

        case 'check_system_user':
            $account = eventosapp_wa_operator_get_account($account_id);
            $result = $account ? eventosapp_wa_operator_check_system_user_assignment($account['waba_id']) : new WP_Error('account_not_found', 'La cuenta no existe.');
            $success_message = ! is_wp_error($result) && ! empty($result['assigned'])
                ? 'El System User aparece asignado a la WABA.'
                : 'Consulta completada. El System User no aparece asignado.';
            break;

        case 'validate_token':
            $result = eventosapp_wa_operator_validate_account_token($account_id);
            $success_message = 'Token validado correctamente.';
            break;

        case 'disconnect_account':
            $result = eventosapp_wa_operator_revoke_account_credentials($account_id, 'Desconexión manual desde el panel');
            $success_message = 'Credenciales revocadas localmente. Los registros se conservaron para auditoría.';
            break;

        case 'sync_phone':
            $result = eventosapp_wa_operator_sync_phone($phone_number_id);
            $success_message = 'Número sincronizado con Meta.';
            break;

        case 'request_code':
            $method = sanitize_key((string)wp_unslash($_POST['code_method'] ?? 'sms'));
            $language = sanitize_text_field((string)wp_unslash($_POST['language'] ?? 'es_CO'));
            $result = eventosapp_wa_operator_request_verification_code($phone_number_id, $method, $language);
            $success_message = 'Meta aceptó la solicitud del código de verificación.';
            break;

        case 'verify_code':
            $code = preg_replace('/\D+/', '', (string)wp_unslash($_POST['verification_code'] ?? ''));
            $result = eventosapp_wa_operator_verify_code($phone_number_id, $code);
            $success_message = 'Código verificado correctamente.';
            break;

        case 'register_phone':
            $pin = preg_replace('/\D+/', '', (string)wp_unslash($_POST['pin'] ?? ''));
            $result = eventosapp_wa_operator_register_phone($phone_number_id, $pin);
            $success_message = 'Número registrado en WhatsApp Cloud API.';
            break;

        case 'deregister_phone':
            $confirm = sanitize_text_field((string)wp_unslash($_POST['confirm_text'] ?? ''));
            if ( $confirm !== 'DESREGISTRAR' ) {
                $result = new WP_Error('confirmation_required', 'Debes escribir DESREGISTRAR para confirmar.');
            } else {
                $result = eventosapp_wa_operator_deregister_phone($phone_number_id);
            }
            $success_message = 'Número desregistrado de Cloud API.';
            break;

        case 'request_display_name':
            $new_name = sanitize_text_field((string)wp_unslash($_POST['display_name'] ?? ''));
            if ( ! function_exists('eventosapp_whatsapp_request_display_name_update') ) {
                $result = new WP_Error('display_name_api_unavailable', 'La función de display name no está disponible.');
            } else {
                $result = eventosapp_whatsapp_request_display_name_update($phone_number_id, $new_name);
            }
            $success_message = 'Solicitud de display name enviada a Meta.';
            break;

        case 'check_display_name':
            if ( ! function_exists('eventosapp_whatsapp_check_display_name_status') ) {
                $result = new WP_Error('display_name_api_unavailable', 'La consulta de display name no está disponible.');
            } else {
                $result = eventosapp_whatsapp_check_display_name_status($phone_number_id);
                if ( ! is_wp_error($result) && ! empty($result['ok']) && is_array($result['response'] ?? null) ) {
                    $phone = eventosapp_wa_operator_get_phone_by_phone_number_id($phone_number_id);
                    $remote = $result['response'];
                    eventosapp_wa_operator_upsert_phone([
                        'phone_number_id' => $phone_number_id,
                        'account_id' => absint($phone['account_id'] ?? 0),
                        'waba_id' => $phone['waba_id'] ?? '',
                        'client_post_id' => absint($phone['client_post_id'] ?? 0),
                        'display_phone_number' => $remote['display_phone_number'] ?? ($phone['display_phone_number'] ?? ''),
                        'verified_name' => $remote['verified_name'] ?? ($phone['verified_name'] ?? ''),
                        'requested_display_name' => $remote['new_display_name'] ?? ($phone['requested_display_name'] ?? ''),
                        'name_status' => $remote['new_name_status'] ?? ($remote['name_status'] ?? ($phone['name_status'] ?? '')),
                        'last_synced_at' => eventosapp_wa_operator_now(),
                        'raw' => $remote,
                    ]);
                }
            }
            $success_message = 'Estado del display name actualizado.';
            break;

        default:
            $result = new WP_Error('unknown_operation', 'La operación solicitada no está disponible.');
            break;
    }

    if ( is_wp_error($result) ) {
        eventosapp_wa_operator_redirect_notice($tab, false, $result->get_error_message());
    }
    if ( is_array($result) && array_key_exists('ok', $result) && empty($result['ok']) ) {
        eventosapp_wa_operator_redirect_notice($tab, false, $result['message'] ?? 'Meta rechazó la solicitud.');
    }
    if ( $result === false ) {
        eventosapp_wa_operator_redirect_notice($tab, false, 'No fue posible completar la operación.');
    }

    eventosapp_wa_operator_redirect_notice($tab, true, $success_message);
});

/**
 * AJAX: crear sesión de Embedded Signup.
 */
add_action('wp_ajax_eventosapp_wa_operator_create_session', function() {
    if ( ! eventosapp_wa_operator_can_manage() ) {
        wp_send_json_error(['message'=>'No tienes permisos.'], 403);
    }
    check_ajax_referer('eventosapp_wa_operator_embedded_signup', 'nonce');

    $session = eventosapp_wa_operator_create_session(absint($_POST['client_post_id'] ?? 0));
    if ( is_wp_error($session) ) {
        wp_send_json_error(['message'=>$session->get_error_message()], 400);
    }
    wp_send_json_success($session);
});

add_action('wp_ajax_eventosapp_wa_operator_complete_signup', function() {
    if ( ! eventosapp_wa_operator_can_manage() ) {
        wp_send_json_error(['message'=>'No tienes permisos.'], 403);
    }
    check_ajax_referer('eventosapp_wa_operator_embedded_signup', 'nonce');

    $raw_session_info = isset($_POST['session_info']) ? wp_unslash($_POST['session_info']) : '';
    $session_info = json_decode((string)$raw_session_info, true);
    if ( ! is_array($session_info) ) {
        $session_info = [];
    }

    $result = eventosapp_wa_operator_complete_embedded_signup([
        'session_key' => sanitize_text_field((string)wp_unslash($_POST['session_key'] ?? '')),
        'state'       => sanitize_text_field((string)wp_unslash($_POST['state'] ?? '')),
        'code'        => trim((string)wp_unslash($_POST['code'] ?? '')),
        'session_info'=> $session_info,
    ]);

    if ( is_wp_error($result) ) {
        wp_send_json_error(['message'=>$result->get_error_message()], 400);
    }

    wp_send_json_success([
        'message' => 'Cuenta conectada y sincronizada correctamente.',
        'result'  => $result,
        'redirect'=> eventosapp_wa_operator_admin_url('accounts', ['waop_ok'=>'1','waop_msg'=>'Embedded Signup completado correctamente.']),
    ]);
});

/**
 * Render general.
 */
function eventosapp_wa_operator_render_page() {
    if ( ! eventosapp_wa_operator_can_manage() ) {
        wp_die('No tienes permisos para acceder a esta sección.');
    }

    eventosapp_wa_operator_maybe_install_tables();
    $tab = sanitize_key((string)($_GET['tab'] ?? 'summary'));
    $allowed_tabs = ['summary','settings','onboarding','accounts','phones','logs'];
    if ( ! in_array($tab, $allowed_tabs, true) ) {
        $tab = 'summary';
    }

    echo '<div class="wrap evapp-waop-wrap">';
    echo '<h1>Operador WhatsApp</h1>';
    echo '<p class="description">Onboarding, verificación, registro y administración multicliente de WhatsApp Business Platform.</p>';
    eventosapp_wa_operator_render_notice();
    eventosapp_wa_operator_render_styles();

    echo '<nav class="nav-tab-wrapper">';
    $tabs = [
        'summary'    => 'Resumen',
        'settings'   => 'Configuración Meta',
        'onboarding' => 'Conectar cliente',
        'accounts'   => 'WABAs',
        'phones'     => 'Números',
        'logs'       => 'Auditoría',
    ];
    foreach ( $tabs as $key => $label ) {
        echo '<a class="nav-tab ' . ($tab === $key ? 'nav-tab-active' : '') . '" href="' . esc_url(eventosapp_wa_operator_admin_url($key)) . '">' . esc_html($label) . '</a>';
    }
    echo '</nav>';

    switch ( $tab ) {
        case 'settings':
            eventosapp_wa_operator_render_settings();
            break;
        case 'onboarding':
            eventosapp_wa_operator_render_onboarding();
            break;
        case 'accounts':
            eventosapp_wa_operator_render_accounts();
            break;
        case 'phones':
            eventosapp_wa_operator_render_phones();
            break;
        case 'logs':
            eventosapp_wa_operator_render_logs();
            break;
        default:
            eventosapp_wa_operator_render_summary();
            break;
    }

    echo '</div>';
}

function eventosapp_wa_operator_render_styles() {
    ?>
    <style>
    .evapp-waop-wrap{max-width:1500px}.evapp-waop-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-top:18px}.evapp-waop-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}.evapp-waop-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px;box-sizing:border-box}.evapp-waop-card h2,.evapp-waop-card h3{margin-top:0}.evapp-waop-metric{font-size:32px;font-weight:700;line-height:1}.evapp-waop-field{margin-bottom:15px}.evapp-waop-field label{display:block;font-weight:600;margin-bottom:5px}.evapp-waop-field input[type=text],.evapp-waop-field input[type=password],.evapp-waop-field input[type=number],.evapp-waop-field select,.evapp-waop-field textarea{width:100%;max-width:none}.evapp-waop-help{font-size:12px;color:#646970;margin-top:4px}.evapp-waop-badge{display:inline-block;border-radius:999px;padding:3px 9px;font-size:11px;font-weight:700;background:#eef2f6;color:#344054}.evapp-waop-badge.good{background:#dcfce7;color:#166534}.evapp-waop-badge.warn{background:#fef3c7;color:#92400e}.evapp-waop-badge.bad{background:#fee2e2;color:#991b1b}.evapp-waop-table{width:100%;border-collapse:collapse;background:#fff;margin-top:18px}.evapp-waop-table th,.evapp-waop-table td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}.evapp-waop-table th{background:#f6f7f7}.evapp-waop-actions{display:flex;gap:7px;flex-wrap:wrap}.evapp-waop-actions form{display:inline-flex;gap:5px;align-items:center;margin:0}.evapp-waop-danger{border-color:#d63638!important;color:#d63638!important}.evapp-waop-status{padding:12px;border-left:4px solid #2271b1;background:#f0f6fc;margin:14px 0}.evapp-waop-sdk-log{min-height:90px;max-height:220px;overflow:auto;background:#111827;color:#d1fae5;padding:12px;border-radius:7px;font-family:monospace;font-size:12px;white-space:pre-wrap}.evapp-waop-inline{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.evapp-waop-checklist{margin:0}.evapp-waop-checklist li{margin:8px 0}.evapp-waop-secret-state{font-size:12px;margin-left:8px}.evapp-waop-detail{font-size:12px;color:#646970;line-height:1.6}.evapp-waop-phone-panel{margin-top:10px;padding-top:10px;border-top:1px solid #e5e7eb}.evapp-waop-phone-panel details{margin:8px 0}.evapp-waop-phone-panel summary{cursor:pointer;font-weight:600}.evapp-waop-phone-panel form{margin-top:8px}.evapp-waop-phone-panel input[type=text],.evapp-waop-phone-panel input[type=password],.evapp-waop-phone-panel select{max-width:240px}@media(max-width:1000px){.evapp-waop-grid,.evapp-waop-grid.two{grid-template-columns:1fr}.evapp-waop-table{display:block;overflow:auto}}
    </style>
    <?php
}

function eventosapp_wa_operator_status_badge($value) {
    $value = strtoupper(sanitize_text_field((string)$value));
    $good = ['ACTIVE','APPROVED','CONNECTED','REGISTERED','VERIFIED','GREEN','VALID'];
    $bad = ['REJECTED','DECLINED','DISCONNECTED','DEREGISTERED','INVALID','RED','ERROR','FAILED'];
    $class = in_array($value, $good, true) ? 'good' : (in_array($value, $bad, true) ? 'bad' : 'warn');
    return '<span class="evapp-waop-badge ' . esc_attr($class) . '">' . esc_html($value !== '' ? $value : 'SIN ESTADO') . '</span>';
}

function eventosapp_wa_operator_render_summary() {
    global $wpdb;

    $settings = eventosapp_wa_operator_get_settings();
    $businesses_count = absint($wpdb->get_var("SELECT COUNT(*) FROM " . eventosapp_wa_operator_table('businesses')));
    $accounts_count = absint($wpdb->get_var("SELECT COUNT(*) FROM " . eventosapp_wa_operator_table('accounts')));
    $phones_count = absint($wpdb->get_var("SELECT COUNT(*) FROM " . eventosapp_wa_operator_table('phones')));
    $registered_count = absint($wpdb->get_var("SELECT COUNT(*) FROM " . eventosapp_wa_operator_table('phones') . " WHERE registration_status IN ('REGISTERED','CONNECTED')"));
    $valid_credentials = absint($wpdb->get_var("SELECT COUNT(*) FROM " . eventosapp_wa_operator_table('credentials') . " WHERE status = 'active'"));
    $secret_ready = eventosapp_wa_operator_get_app_secret() !== '';
    $system_token_ready = eventosapp_wa_operator_get_system_user_token() !== '';
    $system_user_ready = eventosapp_wa_operator_system_user_ready();

    echo '<div class="evapp-waop-grid">';
    foreach ([
        ['Clientes', $businesses_count],
        ['WABAs', $accounts_count],
        ['Números registrados', $registered_count . ' / ' . $phones_count],
    ] as $metric) {
        echo '<div class="evapp-waop-card"><div class="evapp-waop-metric">' . esc_html($metric[1]) . '</div><p>' . esc_html($metric[0]) . '</p></div>';
    }
    echo '</div>';

    echo '<div class="evapp-waop-grid two">';
    echo '<section class="evapp-waop-card"><h2>Preparación técnica</h2><ul class="evapp-waop-checklist">';
    $checks = [
        ['Módulo activo', ! empty($settings['enabled'])],
        ['App ID configurado', ! empty($settings['app_id'])],
        ['App Secret cifrado', $secret_ready],
        ['Configuration ID de Embedded Signup', ! empty($settings['configuration_id'])],
        ['Credenciales de onboarding activas', $valid_credentials > 0],
        ['System User del proveedor listo', $system_user_ready],
        ['Token del System User cifrado', $system_token_ready],
        ['Webhook con firma', ! empty($settings['verify_webhook_signature']) && $secret_ready],
    ];
    foreach ( $checks as $check ) {
        echo '<li>' . ($check[1] ? '✅' : '⚠️') . ' ' . esc_html($check[0]) . '</li>';
    }
    echo '</ul><p><a class="button button-primary" href="' . esc_url(eventosapp_wa_operator_admin_url('settings')) . '">Completar configuración</a></p></section>';

    echo '<section class="evapp-waop-card"><h2>Qué conserva EventosApp</h2>';
    echo '<p>La configuración histórica de WhatsApp Tickets sigue siendo el fallback. Los números incorporados por Embedded Signup se agregan al selector existente y sus tokens se resuelven automáticamente por Phone Number ID o WABA.</p>';
    echo '<p>No se borraron plantillas, Flows, mensajes, inbox, campañas, confirmaciones de asistencia ni configuraciones de eventos.</p>';
    echo '<div class="evapp-waop-status"><strong>Webhook público:</strong><br>' . esc_html(function_exists('eventosapp_whatsapp_get_recommended_webhook_url') ? eventosapp_whatsapp_get_recommended_webhook_url() : home_url('/?eventosapp_whatsapp_webhook=1')) . '</div>';
    echo '</section></div>';

    $health = $settings['last_health_summary'] ?? [];
    if ( $health ) {
        echo '<section class="evapp-waop-card" style="margin-top:18px"><h2>Última revisión de tokens</h2>';
        echo '<p>Fecha: ' . esc_html($settings['last_health_run'] ?: '—')
            . ' · Revisados: ' . esc_html(absint($health['checked'] ?? 0))
            . ' · Válidos: ' . esc_html(absint($health['valid'] ?? 0))
            . ' · Con problema: ' . esc_html(absint($health['invalid'] ?? 0))
            . ' · Próximos a vencer: ' . esc_html(absint($health['expiring'] ?? 0))
            . ' · Vencidos: ' . esc_html(absint($health['expired'] ?? 0))
            . ' · System User: ' . (! empty($health['system_user_token_valid']) ? 'válido' : (! empty($health['system_user_token_configured']) ? 'con problema' : 'no configurado'))
            . '</p>';
        echo '</section>';
    }
}

function eventosapp_wa_operator_render_settings() {
    $settings = eventosapp_wa_operator_get_settings();
    $secret_ready = eventosapp_wa_operator_get_app_secret() !== '';
    $system_token_ready = eventosapp_wa_operator_get_system_user_token() !== '';
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-waop-card" style="margin-top:18px;max-width:900px">
        <input type="hidden" name="action" value="eventosapp_wa_operator_save_settings">
        <?php wp_nonce_field('eventosapp_wa_operator_save_settings'); ?>
        <h2>Aplicación Meta y Embedded Signup</h2>

        <div class="evapp-waop-field">
            <label><input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled'], '1'); ?>> Activar módulo de operador</label>
        </div>

        <div class="evapp-waop-grid two" style="margin-top:0">
            <div class="evapp-waop-field">
                <label for="waop_app_id">App ID</label>
                <input type="text" id="waop_app_id" name="app_id" value="<?php echo esc_attr($settings['app_id']); ?>" autocomplete="off">
            </div>
            <div class="evapp-waop-field">
                <label for="waop_configuration_id">Configuration ID de Facebook Login for Business</label>
                <input type="text" id="waop_configuration_id" name="configuration_id" value="<?php echo esc_attr($settings['configuration_id']); ?>" autocomplete="off">
            </div>
        </div>

        <div class="evapp-waop-grid two" style="margin-top:0">
            <div class="evapp-waop-field">
                <label for="waop_app_secret">App Secret <span class="evapp-waop-secret-state"><?php echo $secret_ready ? '✅ guardado y cifrado' : '⚠️ no configurado'; ?></span></label>
                <input type="password" id="waop_app_secret" name="app_secret" value="" autocomplete="new-password" placeholder="<?php echo $secret_ready ? 'Déjalo vacío para conservar el actual' : 'Pega el App Secret'; ?>">
                <p class="evapp-waop-help">Nunca se muestra nuevamente. Se cifra con claves derivadas de los salts de WordPress.</p>
            </div>
            <div class="evapp-waop-field">
                <label for="waop_api_version">Versión Graph API</label>
                <input type="text" id="waop_api_version" name="api_version" value="<?php echo esc_attr($settings['api_version']); ?>" placeholder="v23.0">
            </div>
        </div>

        <h3>System User del proveedor</h3>
        <p class="evapp-waop-help">Configuración opcional recomendada para operar WABAs compartidas con un token estable del proveedor. No activa ni comparte una línea de crédito.</p>
        <div class="evapp-waop-grid two" style="margin-top:0">
            <div class="evapp-waop-field">
                <label for="waop_provider_business_id">Business ID del proveedor</label>
                <input type="text" id="waop_provider_business_id" name="provider_business_id" value="<?php echo esc_attr($settings['provider_business_id']); ?>" inputmode="numeric">
            </div>
            <div class="evapp-waop-field">
                <label for="waop_system_user_id">System User ID</label>
                <input type="text" id="waop_system_user_id" name="system_user_id" value="<?php echo esc_attr($settings['system_user_id']); ?>" inputmode="numeric">
            </div>
        </div>
        <div class="evapp-waop-field">
            <label for="waop_system_user_token">Admin System User Access Token <span class="evapp-waop-secret-state"><?php echo $system_token_ready ? '✅ guardado y cifrado' : '⚠️ no configurado'; ?></span></label>
            <input type="password" id="waop_system_user_token" name="system_user_token" value="" autocomplete="new-password" placeholder="<?php echo $system_token_ready ? 'Déjalo vacío para conservar el actual' : 'Pega el token administrativo'; ?>">
            <p class="evapp-waop-help">Se usa para comprobar o asignar el System User a las WABAs. Nunca se vuelve a mostrar.</p>
        </div>
        <p><label><input type="checkbox" name="auto_assign_system_user" value="1" <?php checked($settings['auto_assign_system_user'], '1'); ?>> Asignar automáticamente el System User a cada WABA conectada</label></p>

        <h3>Automatización y seguridad</h3>
        <p><label><input type="checkbox" name="auto_subscribe_webhook" value="1" <?php checked($settings['auto_subscribe_webhook'], '1'); ?>> Suscribir automáticamente la aplicación a cada WABA conectada</label></p>
        <p><label><input type="checkbox" name="auto_sync_after_signup" value="1" <?php checked($settings['auto_sync_after_signup'], '1'); ?>> Sincronizar activos al finalizar Embedded Signup</label></p>
        <p><label><input type="checkbox" name="auto_import_phone_numbers" value="1" <?php checked($settings['auto_import_phone_numbers'], '1'); ?>> Importar números encontrados en la WABA</label></p>
        <p><label><input type="checkbox" name="verify_webhook_signature" value="1" <?php checked($settings['verify_webhook_signature'], '1'); ?>> Validar X-Hub-Signature-256 en webhooks</label></p>
        <p class="evapp-waop-help">Cuando esta opción está activa, los webhooks sin una firma válida se rechazan. El panel impide activarla sin App Secret.</p>
        <p><label><input type="checkbox" name="token_health_enabled" value="1" <?php checked($settings['token_health_enabled'], '1'); ?>> Revisar diariamente la validez de los tokens</label></p>
        <div class="evapp-waop-field" style="max-width:260px">
            <label for="waop_warning_days">Avisar con anticipación de expiración</label>
            <input type="number" id="waop_warning_days" name="token_warning_days" min="1" max="60" value="<?php echo esc_attr($settings['token_warning_days']); ?>">
        </div>

        <p><button class="button button-primary" type="submit">Guardar configuración</button></p>
    </form>
    <?php
}

function eventosapp_wa_operator_render_onboarding() {
    $settings = eventosapp_wa_operator_get_settings();
    $clients = eventosapp_wa_operator_clients();
    $selected_client = absint($_GET['client_id'] ?? 0);
    $ready = ! empty($settings['enabled']) && ! empty($settings['app_id']) && ! empty($settings['configuration_id']) && eventosapp_wa_operator_get_app_secret() !== '';

    echo '<div class="evapp-waop-grid two">';
    echo '<section class="evapp-waop-card"><h2>Embedded Signup</h2>';
    if ( ! $ready ) {
        echo '<div class="notice notice-warning inline"><p>Completa App ID, App Secret y Configuration ID antes de iniciar el onboarding.</p></div>';
    }
    echo '<div class="evapp-waop-field"><label for="waop_client_id">Cliente de EventosApp</label><select id="waop_client_id"><option value="0">Sin cliente específico</option>';
    foreach ( $clients as $client ) {
        echo '<option value="' . esc_attr($client->ID) . '" ' . selected($selected_client, $client->ID, false) . '>' . esc_html(eventosapp_wa_operator_client_name($client->ID)) . '</option>';
    }
    echo '</select></div>';
    echo '<p><button type="button" id="waop-embedded-signup" class="button button-primary button-hero" ' . disabled($ready, false, false) . '>Conectar con Meta</button></p>';
    echo '<p class="evapp-waop-help">Meta abrirá el flujo oficial para seleccionar o crear el Business Portfolio, la WABA, el número y el display name.</p>';
    echo '<div id="waop-sdk-log" class="evapp-waop-sdk-log">Listo para iniciar.</div>';
    echo '</section>';

    echo '<section class="evapp-waop-card"><h2>Conexión manual de una cuenta existente</h2>';
    echo '<p>Usa este método únicamente cuando la WABA y el token ya existen en Meta. El token se valida antes de guardarlo y se cifra en la base de datos.</p>';
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eventosapp_wa_operator_manual_connect">
        <?php wp_nonce_field('eventosapp_wa_operator_manual_connect'); ?>
        <div class="evapp-waop-field"><label>Cliente</label><select name="client_post_id"><option value="0">Sin cliente específico</option><?php foreach ( $clients as $client ) : ?><option value="<?php echo esc_attr($client->ID); ?>" <?php selected($selected_client, $client->ID); ?>><?php echo esc_html(eventosapp_wa_operator_client_name($client->ID)); ?></option><?php endforeach; ?></select></div>
        <div class="evapp-waop-field"><label>Nombre administrativo de la cuenta</label><input type="text" name="account_name" placeholder="Ej. WhatsApp Cliente ACME"></div>
        <div class="evapp-waop-grid two" style="margin-top:0">
            <div class="evapp-waop-field"><label>Meta Business ID</label><input type="text" name="meta_business_id" inputmode="numeric"></div>
            <div class="evapp-waop-field"><label>WABA ID *</label><input type="text" name="waba_id" inputmode="numeric" required></div>
        </div>
        <div class="evapp-waop-field"><label>Phone Number ID inicial</label><input type="text" name="phone_number_id" inputmode="numeric"></div>
        <div class="evapp-waop-field"><label>Access Token *</label><textarea name="access_token" rows="4" required autocomplete="off"></textarea></div>
        <p><label><input type="checkbox" name="is_default" value="1"> Marcar como WABA predeterminada del cliente</label></p>
        <p><button class="button" type="submit">Validar y conectar manualmente</button></p>
    </form>
    <?php
    echo '</section></div>';

    eventosapp_wa_operator_render_embedded_signup_script($settings);
}

function eventosapp_wa_operator_render_embedded_signup_script($settings) {
    $app_id = preg_replace('/\D+/', '', (string)$settings['app_id']);
    $configuration_id = sanitize_text_field((string)$settings['configuration_id']);
    $nonce = wp_create_nonce('eventosapp_wa_operator_embedded_signup');
    ?>
    <div id="fb-root"></div>
    <script>
    (function(){
        const appId = <?php echo wp_json_encode($app_id); ?>;
        const configurationId = <?php echo wp_json_encode($configuration_id); ?>;
        const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        const nonce = <?php echo wp_json_encode($nonce); ?>;
        const logEl = document.getElementById('waop-sdk-log');
        const button = document.getElementById('waop-embedded-signup');
        let lastSessionInfo = {};

        function log(message, data) {
            const stamp = new Date().toLocaleTimeString();
            let line = '[' + stamp + '] ' + message;
            if (data) {
                try { line += '\n' + JSON.stringify(data, null, 2); } catch(e) {}
            }
            logEl.textContent = line + '\n\n' + logEl.textContent;
        }

        function post(params) {
            const body = new URLSearchParams(params);
            return fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString()
            }).then(r => r.json());
        }

        window.addEventListener('message', function(event) {
            if (event.origin !== 'https://www.facebook.com' && event.origin !== 'https://web.facebook.com') {
                return;
            }
            let data = event.data;
            if (typeof data === 'string') {
                try { data = JSON.parse(data); } catch(e) { return; }
            }
            if (!data || data.type !== 'WA_EMBEDDED_SIGNUP') {
                return;
            }
            if (data.event === 'FINISH') {
                lastSessionInfo = data.data || {};
                log('Meta finalizó la selección de activos.', lastSessionInfo);
            } else if (data.event === 'CANCEL') {
                log('El usuario canceló Embedded Signup.', data.data || {});
            } else if (data.event === 'ERROR') {
                log('Meta informó un error durante Embedded Signup.', data.data || {});
            } else {
                log('Evento de Embedded Signup: ' + String(data.event || ''), data.data || {});
            }
        });

        window.fbAsyncInit = function() {
            FB.init({
                appId: appId,
                cookie: true,
                xfbml: false,
                version: <?php echo wp_json_encode($settings['api_version']); ?>
            });
            log('Facebook JavaScript SDK inicializado.');
        };

        (function(d, s, id){
            if (!appId) return;
            const fjs = d.getElementsByTagName(s)[0];
            if (d.getElementById(id)) return;
            const js = d.createElement(s); js.id = id;
            js.src = 'https://connect.facebook.net/es_LA/sdk.js';
            fjs.parentNode.insertBefore(js, fjs);
        }(document, 'script', 'facebook-jssdk'));

        if (!button) return;
        button.addEventListener('click', async function(){
            if (!appId || !configurationId || typeof FB === 'undefined') {
                log('Falta App ID, Configuration ID o el SDK todavía no está listo.');
                return;
            }
            button.disabled = true;
            lastSessionInfo = {};
            log('Creando sesión segura de onboarding…');

            try {
                const sessionResponse = await post({
                    action: 'eventosapp_wa_operator_create_session',
                    nonce: nonce,
                    client_post_id: document.getElementById('waop_client_id').value || '0'
                });
                if (!sessionResponse.success) {
                    throw new Error((sessionResponse.data && sessionResponse.data.message) || 'No se pudo crear la sesión.');
                }
                const secureSession = sessionResponse.data;
                log('Sesión creada. Abriendo Meta…');

                FB.login(function(response){
                    if (!response || !response.authResponse || !response.authResponse.code) {
                        button.disabled = false;
                        log('Meta no devolvió el código de autorización.', response || {});
                        return;
                    }
                    const code = response.authResponse.code;
                    log('Código recibido. Intercambiando y sincronizando activos…');

                    window.setTimeout(async function(){
                        try {
                            const completed = await post({
                                action: 'eventosapp_wa_operator_complete_signup',
                                nonce: nonce,
                                session_key: secureSession.session_key,
                                state: secureSession.state,
                                code: code,
                                session_info: JSON.stringify(lastSessionInfo || {})
                            });
                            if (!completed.success) {
                                throw new Error((completed.data && completed.data.message) || 'No se pudo completar el onboarding.');
                            }
                            log('Onboarding completado.', completed.data.result || {});
                            window.location.href = completed.data.redirect;
                        } catch (error) {
                            button.disabled = false;
                            log('Error al completar el onboarding: ' + error.message);
                        }
                    }, 700);
                }, {
                    config_id: configurationId,
                    response_type: 'code',
                    override_default_response_type: true,
                    extras: {
                        setup: {},
                        featureType: '',
                        sessionInfoVersion: '3'
                    }
                });
            } catch (error) {
                button.disabled = false;
                log('Error: ' + error.message);
            }
        });
    })();
    </script>
    <?php
}

function eventosapp_wa_operator_action_form($operation, $args = [], $label = '', $class = 'button', $fields_html = '') {
    $args = is_array($args) ? $args : [];
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eventosapp_wa_operator_action">';
    echo '<input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
    echo '<input type="hidden" name="return_tab" value="' . esc_attr($args['return_tab'] ?? 'accounts') . '">';
    if ( ! empty($args['account_id']) ) {
        echo '<input type="hidden" name="account_id" value="' . esc_attr(absint($args['account_id'])) . '">';
    }
    if ( ! empty($args['phone_number_id']) ) {
        echo '<input type="hidden" name="phone_number_id" value="' . esc_attr($args['phone_number_id']) . '">';
    }
    wp_nonce_field('eventosapp_wa_operator_action');
    echo $fields_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label ?: $operation) . '</button>';
    echo '</form>';
}

function eventosapp_wa_operator_render_accounts() {
    $accounts = eventosapp_wa_operator_get_accounts(['limit'=>1000]);
    if ( empty($accounts) ) {
        echo '<div class="evapp-waop-card" style="margin-top:18px"><p>No hay WABAs conectadas.</p><a class="button button-primary" href="' . esc_url(eventosapp_wa_operator_admin_url('onboarding')) . '">Conectar cliente</a></div>';
        return;
    }

    echo '<table class="evapp-waop-table"><thead><tr><th>Cliente / cuenta</th><th>WABA</th><th>Estados</th><th>System User / webhook</th><th>Token</th><th>Acciones</th></tr></thead><tbody>';
    foreach ( $accounts as $account ) {
        $credential = eventosapp_wa_operator_get_credential_for_account($account['id']);
        echo '<tr>';
        echo '<td><strong>' . esc_html($account['name'] ?: 'Cuenta WhatsApp') . '</strong><br><span class="evapp-waop-detail">' . esc_html(eventosapp_wa_operator_client_name($account['client_post_id'])) . '<br>Business ID: ' . esc_html($account['meta_business_id'] ?: '—') . '</span></td>';
        echo '<td><code>' . esc_html($account['waba_id']) . '</code><br><span class="evapp-waop-detail">Última sincronización: ' . esc_html($account['last_synced_at'] ?: '—') . '</span></td>';
        echo '<td>' . eventosapp_wa_operator_status_badge($account['account_status']) . ' ' . eventosapp_wa_operator_status_badge($account['review_status']) . '<br><span class="evapp-waop-detail">Verificación: ' . esc_html($account['business_verification_status'] ?: '—') . '</span></td>';
        echo '<td>'
            . (! empty($account['system_user_assigned']) ? '<span class="evapp-waop-badge good">SYSTEM USER ASIGNADO</span>' : '<span class="evapp-waop-badge warn">SYSTEM USER PENDIENTE</span>')
            . '<br>'
            . (! empty($account['webhook_subscribed']) ? '<span class="evapp-waop-badge good">WEBHOOK SUSCRITO</span>' : '<span class="evapp-waop-badge warn">WEBHOOK PENDIENTE</span>')
            . '</td>';
        echo '<td>' . ($credential ? eventosapp_wa_operator_status_badge($credential['status']) : '<span class="evapp-waop-badge bad">SIN TOKEN</span>') . '<br><span class="evapp-waop-detail">Expira: ' . esc_html($credential['expires_at'] ?? '—') . '<br>Validado: ' . esc_html($credential['last_validated_at'] ?? '—') . '</span></td>';
        echo '<td><div class="evapp-waop-actions">';
        eventosapp_wa_operator_action_form('sync_account', ['account_id'=>$account['id'],'return_tab'=>'accounts'], 'Sincronizar');
        eventosapp_wa_operator_action_form('subscribe_account', ['account_id'=>$account['id'],'return_tab'=>'accounts'], 'Suscribir webhook');
        eventosapp_wa_operator_action_form('check_subscription', ['account_id'=>$account['id'],'return_tab'=>'accounts'], 'Comprobar webhook');
        eventosapp_wa_operator_action_form('assign_system_user', ['account_id'=>$account['id'],'return_tab'=>'accounts'], 'Asignar System User');
        eventosapp_wa_operator_action_form('check_system_user', ['account_id'=>$account['id'],'return_tab'=>'accounts'], 'Comprobar System User');
        eventosapp_wa_operator_action_form('validate_token', ['account_id'=>$account['id'],'return_tab'=>'accounts'], 'Validar token de onboarding');
        eventosapp_wa_operator_action_form('disconnect_account', ['account_id'=>$account['id'],'return_tab'=>'accounts'], 'Revocar localmente', 'button evapp-waop-danger');
        echo '</div></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function eventosapp_wa_operator_render_phones() {
    $phones = eventosapp_wa_operator_get_phones(['limit'=>2000]);
    if ( empty($phones) ) {
        echo '<div class="evapp-waop-card" style="margin-top:18px"><p>No hay números importados. Sincroniza una WABA o completa Embedded Signup.</p></div>';
        return;
    }

    echo '<table class="evapp-waop-table"><thead><tr><th>Número</th><th>Cuenta / cliente</th><th>Display name</th><th>Registro y calidad</th><th>Operaciones</th></tr></thead><tbody>';
    foreach ( $phones as $phone ) {
        $account = eventosapp_wa_operator_get_account($phone['account_id']);
        echo '<tr>';
        echo '<td><strong>' . esc_html($phone['display_phone_number'] ?: 'Sin número visible') . '</strong><br><code>' . esc_html($phone['phone_number_id']) . '</code></td>';
        echo '<td><strong>' . esc_html($account['name'] ?? 'Cuenta no disponible') . '</strong><br><span class="evapp-waop-detail">' . esc_html(eventosapp_wa_operator_client_name($phone['client_post_id'])) . '<br>WABA: ' . esc_html($phone['waba_id']) . '</span></td>';
        echo '<td><strong>' . esc_html($phone['verified_name'] ?: 'Sin nombre verificado') . '</strong><br>' . eventosapp_wa_operator_status_badge($phone['name_status']) . '<br><span class="evapp-waop-detail">Solicitado: ' . esc_html($phone['requested_display_name'] ?: '—') . '</span></td>';
        echo '<td>' . eventosapp_wa_operator_status_badge($phone['registration_status']) . ' ' . eventosapp_wa_operator_status_badge($phone['code_verification_status']) . '<br><span class="evapp-waop-detail">Calidad: ' . esc_html($phone['quality_rating'] ?: '—') . '<br>Límite: ' . esc_html($phone['messaging_limit_tier'] ?: '—') . '</span></td>';
        echo '<td><div class="evapp-waop-actions">';
        eventosapp_wa_operator_action_form('sync_phone', ['phone_number_id'=>$phone['phone_number_id'],'return_tab'=>'phones'], 'Sincronizar');
        eventosapp_wa_operator_action_form('check_display_name', ['phone_number_id'=>$phone['phone_number_id'],'return_tab'=>'phones'], 'Consultar nombre');
        echo '</div><div class="evapp-waop-phone-panel">';

        echo '<details><summary>Verificar número por SMS o llamada</summary>';
        $fields = '<select name="code_method"><option value="SMS">SMS</option><option value="VOICE">Llamada</option></select><input type="text" name="language" value="es_CO" style="width:90px">';
        eventosapp_wa_operator_action_form('request_code', ['phone_number_id'=>$phone['phone_number_id'],'return_tab'=>'phones'], 'Enviar código', 'button', $fields);
        $fields = '<input type="text" name="verification_code" inputmode="numeric" maxlength="12" placeholder="Código recibido" required>';
        eventosapp_wa_operator_action_form('verify_code', ['phone_number_id'=>$phone['phone_number_id'],'return_tab'=>'phones'], 'Verificar código', 'button', $fields);
        echo '</details>';

        echo '<details><summary>Registrar en Cloud API</summary>';
        $fields = '<input type="password" name="pin" inputmode="numeric" minlength="6" maxlength="6" placeholder="PIN de 6 dígitos" required autocomplete="new-password">';
        eventosapp_wa_operator_action_form('register_phone', ['phone_number_id'=>$phone['phone_number_id'],'return_tab'=>'phones'], 'Registrar número', 'button button-primary', $fields);
        echo '</details>';

        echo '<details><summary>Solicitar display name</summary>';
        $fields = '<input type="text" name="display_name" maxlength="512" placeholder="Nuevo nombre visible" required>';
        eventosapp_wa_operator_action_form('request_display_name', ['phone_number_id'=>$phone['phone_number_id'],'return_tab'=>'phones'], 'Enviar a Meta', 'button', $fields);
        echo '</details>';

        echo '<details><summary style="color:#b32d2e">Desregistrar número</summary>';
        $fields = '<input type="text" name="confirm_text" placeholder="Escribe DESREGISTRAR" required>';
        eventosapp_wa_operator_action_form('deregister_phone', ['phone_number_id'=>$phone['phone_number_id'],'return_tab'=>'phones'], 'Desregistrar', 'button evapp-waop-danger', $fields);
        echo '</details>';

        echo '</div></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function eventosapp_wa_operator_render_logs() {
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT * FROM " . eventosapp_wa_operator_table('logs') . " ORDER BY id DESC LIMIT 300",
        ARRAY_A
    );

    echo '<table class="evapp-waop-table"><thead><tr><th>Fecha</th><th>Acción</th><th>Estado</th><th>Cuenta / número</th><th>Mensaje</th><th>Contexto</th></tr></thead><tbody>';
    foreach ( $rows as $row ) {
        echo '<tr>';
        echo '<td>' . esc_html($row['created_at']) . '</td>';
        echo '<td><code>' . esc_html($row['action']) . '</code></td>';
        echo '<td>' . eventosapp_wa_operator_status_badge($row['status']) . '</td>';
        echo '<td>Cuenta #' . esc_html($row['account_id']) . '<br>Número #' . esc_html($row['phone_row_id']) . '</td>';
        echo '<td>' . esc_html($row['message']) . '</td>';
        echo '<td><details><summary>Ver</summary><pre style="white-space:pre-wrap;max-width:600px">' . esc_html(wp_json_encode(eventosapp_wa_operator_decode_json($row['context_json']), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) . '</pre></details></td>';
        echo '</tr>';
    }
    if ( empty($rows) ) {
        echo '<tr><td colspan="6">Todavía no hay registros de auditoría.</td></tr>';
    }
    echo '</tbody></table>';
}
