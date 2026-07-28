<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Configuración de páginas (shortcodes) para el frontend
 * Opción almacenada: option_name = 'eventosapp_pages'
 * Estructura:
 * [
 *   'dashboard_page_id'    => (int),
 *   'front_search_page_id' => (int),
 *   'register_page_id'     => (int),
 *   'qr_page_id'           => (int),
 *   'metrics_page_id'      => (int),
 *   'flow_metrics_page_id' => (int), // Métricas de Encuestas
 *   'edit_page_id'         => (int),
 *   'qr_localidad_page_id' => (int), // Validador de Localidad (solo lectura)
 *   'qr_sesion_page_id'    => (int), // NUEVO: Control por sesión
 * ]
 */

// ===== Helpers de lectura (pueden usarse globalmente) =====
function eventosapp_get_pages_config() {
    $cfg = get_option('eventosapp_pages', []);
    if (!is_array($cfg)) $cfg = [];
    return wp_parse_args($cfg, [
        'dashboard_page_id'         => 0,
        'front_search_page_id'      => 0,
        'self_checkin_page_id'      => 0,
        'register_page_id'          => 0,
        'qr_page_id'                => 0,
        'metrics_page_id'           => 0,
        'flow_metrics_page_id'      => 0,
        'edit_page_id'              => 0,
        'qr_localidad_page_id'      => 0,
        'qr_sesion_page_id'         => 0,
        'checklist_page_id'         => 0,
        'networking_ranking_page_id' => 0,
        'qr_double_auth_page_id'    => 0,
        'face_checkin_page_id'      => 0, // NUEVO
        'support_assistance_page_id'   => 0, // Asistencia / Equipo de apoyo
        'support_team_metrics_page_id' => 0, // Métricas equipo de apoyo
        'expositor_page_id'            => 0, // Módulo Expositor
        'expositor_gestion_page_id'    => 0, // Gestión de Expositores
        'company_checkin_page_id'       => 0, // Monitor de empresas con check-in
    ]);
}


function eventosapp_get_checklist_url() {
    return eventosapp_get_configured_page_url('checklist_page_id', '#');
}

function eventosapp_get_configured_page_url($key, $fallback = '') {
    $cfg = eventosapp_get_pages_config();
    $pid = isset($cfg[$key]) ? absint($cfg[$key]) : 0;
    if ($pid) {
        $url = get_permalink($pid);
        if ($url) return $url;
    }
    return $fallback ?: home_url('/');
}

// Getters específicos
function eventosapp_get_dashboard_url() {
    return eventosapp_get_configured_page_url('dashboard_page_id', home_url('/'));
}
function eventosapp_get_search_url() {
    return eventosapp_get_configured_page_url('front_search_page_id', '#');
}
function eventosapp_get_self_checkin_url() {
    return eventosapp_get_configured_page_url('self_checkin_page_id', '#');
}
function eventosapp_get_register_url() {
    return eventosapp_get_configured_page_url('register_page_id', '#');
}
function eventosapp_get_qr_url() {
    return eventosapp_get_configured_page_url('qr_page_id', '#');
}
function eventosapp_get_metrics_url() {
    return eventosapp_get_configured_page_url('metrics_page_id', '#');
}
function eventosapp_get_flow_metrics_url() {
    return eventosapp_get_configured_page_url('flow_metrics_page_id', '#');
}
function eventosapp_get_edit_url() {
    return eventosapp_get_configured_page_url('edit_page_id', '#');
}
function eventosapp_get_qr_localidad_url() {
    return eventosapp_get_configured_page_url('qr_localidad_page_id', '#');
}
// NUEVO: Getter para la página del Control por Sesión
function eventosapp_get_qr_sesion_url() {
    return eventosapp_get_configured_page_url('qr_sesion_page_id', '#');
}

function eventosapp_get_networking_ranking_url() {
    return eventosapp_get_configured_page_url('networking_ranking_page_id', '#');
}

// NUEVO: Getter para QR con Doble Autenticación
function eventosapp_get_qr_double_auth_url() {
    return eventosapp_get_configured_page_url('qr_double_auth_page_id', '#');
}

// NUEVO: Getter para Check-In por Reconocimiento Facial
function eventosapp_get_face_checkin_url() {
    return eventosapp_get_configured_page_url('face_checkin_page_id', '#');
}

function eventosapp_get_support_assistance_url() {
    return eventosapp_get_configured_page_url('support_assistance_page_id', '#');
}

function eventosapp_get_support_team_metrics_url() {
    return eventosapp_get_configured_page_url('support_team_metrics_page_id', '#');
}

function eventosapp_get_expositor_url() {
    return eventosapp_get_configured_page_url('expositor_page_id', '#');
}

function eventosapp_get_expositor_gestion_url() {
    return eventosapp_get_configured_page_url('expositor_gestion_page_id', '#');
}

function eventosapp_get_company_checkin_url() {
    return eventosapp_get_configured_page_url('company_checkin_page_id', '#');
}


// ===== Admin UI =====
add_action('admin_menu', function(){
    add_submenu_page(
        'eventosapp_dashboard',
        'Configuración',
        'Configuración',
        'manage_options',
        'eventosapp_configuracion',
        'eventosapp_render_configuracion_page'
    );
});

add_action('admin_init', function(){
    register_setting('eventosapp_pages_group', 'eventosapp_pages', [
        'type'              => 'array',
        'sanitize_callback' => 'eventosapp_sanitize_pages_option',
        'default'           => []
    ]);

    add_settings_section(
        'eventosapp_pages_section',
        'Páginas del Frontend (Shortcodes)',
        function(){
            echo '<p>Selecciona qué páginas de WordPress contienen cada shortcode. '
               . 'Así puedes cambiar URLs sin modificar código.</p>';
        },
        'eventosapp_configuracion'
    );

    add_settings_field(
        'dashboard_page_id',
        'Página del Dashboard',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'dashboard_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_dashboard]</code>']
    );

    add_settings_field(
        'front_search_page_id',
        'Página de Check-In Manual & Escarapela',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'front_search_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_front_search]</code>']
    );

    add_settings_field(
        'self_checkin_page_id',
        'Página de Autogestión del Asistente',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'self_checkin_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_self_checkin]</code>']
    );

    add_settings_field(
        'register_page_id',
        'Página de Registro Manual de Asistentes',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'register_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_front_register]</code>']
    );

    add_settings_field(
        'qr_page_id',
        'Página de Check-In con QR',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'qr_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_qr_checkin]</code>']
    );

	add_settings_field(
		'metrics_page_id',
		'Página de Métricas',
		'eventosapp_render_pages_field',
		'eventosapp_configuracion',
		'eventosapp_pages_section',
		['key'=>'metrics_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_front_metrics]</code>']
	);

	add_settings_field(
		'flow_metrics_page_id',
		'Página de Métricas de Encuestas',
		'eventosapp_render_pages_field',
		'eventosapp_configuracion',
		'eventosapp_pages_section',
		['key'=>'flow_metrics_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_whatsapp_flow_metrics]</code>']
	);

    // Página de Edición de Tickets
    add_settings_field(
        'edit_page_id',
        'Página de Edición de Tickets',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'edit_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_front_edit]</code>']
    );

    // Validador de Localidad
    add_settings_field(
        'qr_localidad_page_id',
        'Página de Validador de Localidad',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'qr_localidad_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_qr_localidad]</code>']
    );

    // NUEVO: Control por sesión
    add_settings_field(
        'qr_sesion_page_id',
        'Página de Control por Sesión',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'qr_sesion_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_qr_sesion]</code>']
    );
// NUEVO: Control de checklist
	add_settings_field(
    'checklist_page_id',
    'Página de Checklist de Evento',
    'eventosapp_render_pages_field',
    'eventosapp_configuracion',
    'eventosapp_pages_section',
    ['key'=>'checklist_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_event_checklist]</code>']
);

	// NUEVO: Página de Ranking Networking
add_settings_field(
    'networking_ranking_page_id',
    'Página de Ranking Networking',
    'eventosapp_render_pages_field',
    'eventosapp_configuracion',
    'eventosapp_pages_section',
    ['key'=>'networking_ranking_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_networking_ranking]</code>']
);


// NUEVO: Página de Check-In QR con Doble Autenticación
    add_settings_field(
        'qr_double_auth_page_id',
        'Página de Check-In QR con Doble Autenticación',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'qr_double_auth_page_id', 'desc'=>'Debe contener el shortcode: <code>[qr_checkin_doble_auth]</code>']
    );

	// NUEVO: Página de Check-In por Reconocimiento Facial
add_settings_field(
    'face_checkin_page_id',
    'Página de Check-In Facial',
    'eventosapp_render_pages_field',
    'eventosapp_configuracion',
    'eventosapp_pages_section',
    ['key'=>'face_checkin_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_face_checkin]</code>']
);

    add_settings_field(
        'support_assistance_page_id',
        'Página de Asistencia',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'support_assistance_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_support_assistance]</code>']
    );

    add_settings_field(
        'support_team_metrics_page_id',
        'Página de Métrica de equipo de apoyo',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'support_team_metrics_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_support_team_metrics]</code>']
    );

    add_settings_field(
        'expositor_page_id',
        'Página del Módulo Expositor',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'expositor_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_expositor]</code>']
    );

    add_settings_field(
        'expositor_gestion_page_id',
        'Página de Gestión de Expositores',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'expositor_gestion_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_expositor_gestion]</code>']
    );

    add_settings_field(
        'company_checkin_page_id',
        'Página del Monitor de Empresas',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'company_checkin_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_company_checkin_monitor]</code>']
    );

});

function eventosapp_sanitize_pages_option($input){
    $out = [];
    $keys = [
        'dashboard_page_id',
        'front_search_page_id',
        'self_checkin_page_id',
        'register_page_id',
        'qr_page_id',
        'metrics_page_id',
        'flow_metrics_page_id',
        'edit_page_id',
        'qr_localidad_page_id',
        'qr_sesion_page_id',
        'checklist_page_id',
        'networking_ranking_page_id',
        'qr_double_auth_page_id',
        'face_checkin_page_id', // NUEVO
        'support_assistance_page_id',
        'support_team_metrics_page_id',
        'expositor_page_id',
        'expositor_gestion_page_id',
        'company_checkin_page_id',
    ];
    foreach ($keys as $k) {
        $out[$k] = isset($input[$k]) ? absint($input[$k]) : 0;
    }
    return $out;
}


function eventosapp_render_pages_field($args){
    $key  = $args['key'];
    $desc = isset($args['desc']) ? $args['desc'] : '';
    $cfg  = eventosapp_get_pages_config();
    $current = isset($cfg[$key]) ? absint($cfg[$key]) : 0;

    // Listado de páginas: publicadas y privadas, orden por título
    $pages = get_pages([
        'post_status' => ['publish','private'],
        'number'      => 0,
        'sort_column' => 'post_title',
        'sort_order'  => 'asc',
    ]);

    echo '<select name="eventosapp_pages['.esc_attr($key).']" class="regular-text">';
    echo '<option value="0">— Selecciona una página —</option>';
    foreach ($pages as $p) {
        printf('<option value="%d"%s>%s</option>', $p->ID, selected($current, $p->ID, false), esc_html($p->post_title));
    }
    echo '</select>';
    if ($desc) {
        echo '<p class="description" style="margin-top:6px;">'.$desc.'</p>';
    }
}

/**
 * Gestión masiva de usuarios desde la pantalla de Configuración.
 *
 * La creación usa wp_insert_user() directamente, por lo que EventosApp no
 * invoca wp_new_user_notification() ni envía credenciales por correo.
 * La eliminación es permanente y se procesa únicamente para administradores.
 */
if ( ! function_exists('eventosapp_bulk_users_result_transient_key') ) {
    function eventosapp_bulk_users_result_transient_key($user_id, $token) {
        return 'eventosapp_bulk_users_' . absint($user_id) . '_' . sanitize_key((string) $token);
    }
}

if ( ! function_exists('eventosapp_bulk_users_store_result') ) {
    function eventosapp_bulk_users_store_result(array $result) {
        $user_id = get_current_user_id();
        $token   = strtolower(wp_generate_password(16, false, false));
        $key     = eventosapp_bulk_users_result_transient_key($user_id, $token);

        set_transient($key, $result, 10 * MINUTE_IN_SECONDS);

        return $token;
    }
}

if ( ! function_exists('eventosapp_bulk_users_pull_result') ) {
    function eventosapp_bulk_users_pull_result() {
        if (empty($_GET['eventosapp_bulk_users_result'])) {
            return [];
        }

        $token = sanitize_key(wp_unslash($_GET['eventosapp_bulk_users_result']));
        if ($token === '') {
            return [];
        }

        $key    = eventosapp_bulk_users_result_transient_key(get_current_user_id(), $token);
        $result = get_transient($key);
        delete_transient($key);

        return is_array($result) ? $result : [];
    }
}

if ( ! function_exists('eventosapp_bulk_users_redirect_with_result') ) {
    function eventosapp_bulk_users_redirect_with_result(array $result) {
        $token = eventosapp_bulk_users_store_result($result);
        $url   = add_query_arg(
            [
                'page'                         => 'eventosapp_configuracion',
                'eventosapp_bulk_users_result' => $token,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($url . '#eventosapp-bulk-users');
        exit;
    }
}

if ( ! function_exists('eventosapp_bulk_users_parse_lines') ) {
    function eventosapp_bulk_users_parse_lines($raw_text) {
        if (is_array($raw_text) || is_object($raw_text)) {
            return [];
        }

        $raw_text = wp_unslash((string) $raw_text);
        $raw_text = str_replace(["\r\n", "\r"], "\n", $raw_text);
        $rows     = [];

        foreach (explode("\n", $raw_text) as $index => $line) {
            if ($index === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
            }

            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $rows[] = [
                'line'  => $index + 1,
                'value' => $line,
            ];
        }

        return $rows;
    }
}

if ( ! function_exists('eventosapp_bulk_users_available_roles') ) {
    function eventosapp_bulk_users_available_roles() {
        if (!function_exists('get_editable_roles')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $editable_roles = get_editable_roles();
        return is_array($editable_roles) ? $editable_roles : [];
    }
}

if ( ! function_exists('eventosapp_bulk_users_base_result') ) {
    function eventosapp_bulk_users_base_result($action, $processed) {
        return [
            'action'  => sanitize_key((string) $action),
            'summary' => [
                'processed' => absint($processed),
                'created'   => 0,
                'deleted'   => 0,
                'warnings'  => 0,
                'errors'    => 0,
            ],
            'items'   => [],
        ];
    }
}

if ( ! function_exists('eventosapp_bulk_users_add_result_item') ) {
    function eventosapp_bulk_users_add_result_item(array &$result, $status, $line, $username, $message, array $extra = []) {
        $status = sanitize_key((string) $status);
        $item   = array_merge(
            [
                'status'   => $status,
                'line'     => absint($line),
                'username' => sanitize_user((string) $username, true),
                'message'  => sanitize_text_field((string) $message),
            ],
            $extra
        );

        $result['items'][] = $item;

        if ($status === 'created') {
            $result['summary']['created']++;
        } elseif ($status === 'deleted') {
            $result['summary']['deleted']++;
        } elseif ($status === 'warning') {
            $result['summary']['warnings']++;
        } elseif ($status === 'error') {
            $result['summary']['errors']++;
        }
    }
}

add_action('admin_post_eventosapp_bulk_create_users', 'eventosapp_handle_bulk_create_users');
if ( ! function_exists('eventosapp_handle_bulk_create_users') ) {
    function eventosapp_handle_bulk_create_users() {
        if (!current_user_can('manage_options') || !current_user_can('create_users')) {
            wp_die('No tienes permisos para crear usuarios.', '', ['response' => 403]);
        }

        check_admin_referer('eventosapp_bulk_create_users', 'eventosapp_bulk_create_users_nonce');

        $rows   = eventosapp_bulk_users_parse_lines($_POST['eventosapp_bulk_create_users_data'] ?? '');
        $result = eventosapp_bulk_users_base_result('create', count($rows));
        $roles  = eventosapp_bulk_users_available_roles();

        if (empty($rows)) {
            eventosapp_bulk_users_add_result_item($result, 'error', 0, '', 'No se encontraron líneas para procesar.');
            eventosapp_bulk_users_redirect_with_result($result);
        }

        $seen_usernames = [];
        $seen_emails    = [];

        foreach ($rows as $row) {
            $line_number = absint($row['line']);
            $fields      = str_getcsv($row['value'], ',', '"', '\\');
            $fields      = array_map(static function($value) {
                return trim((string) $value);
            }, $fields);

            if (count($fields) !== 6) {
                eventosapp_bulk_users_add_result_item(
                    $result,
                    'error',
                    $line_number,
                    $fields[0] ?? '',
                    'La línea debe contener exactamente 6 campos separados por coma.'
                );
                continue;
            }

            [$username_raw, $first_name, $last_name, $password, $email_raw, $role_raw] = $fields;

            $username = sanitize_user($username_raw, true);
            $email    = sanitize_email($email_raw);
            $role     = sanitize_key($role_raw);

            if ($username_raw === '' || $username === '' || $username !== $username_raw || !validate_username($username)) {
                eventosapp_bulk_users_add_result_item(
                    $result,
                    'error',
                    $line_number,
                    $username_raw,
                    'El nombre de usuario es inválido o contiene caracteres no permitidos.'
                );
                continue;
            }

            if ($password === '') {
                eventosapp_bulk_users_add_result_item($result, 'error', $line_number, $username, 'La contraseña no puede estar vacía.');
                continue;
            }

            if ($email_raw === '' || $email === '' || !is_email($email)) {
                eventosapp_bulk_users_add_result_item($result, 'error', $line_number, $username, 'El correo electrónico no es válido.');
                continue;
            }

            if ($role === '' || !isset($roles[$role])) {
                eventosapp_bulk_users_add_result_item(
                    $result,
                    'error',
                    $line_number,
                    $username,
                    'El perfil indicado no existe o no está disponible para asignación.',
                    ['email' => $email, 'role' => $role]
                );
                continue;
            }

            $username_key = strtolower($username);
            $email_key    = strtolower($email);

            if (isset($seen_usernames[$username_key])) {
                eventosapp_bulk_users_add_result_item(
                    $result,
                    'warning',
                    $line_number,
                    $username,
                    'Usuario duplicado dentro del bloque. Ya apareció en la línea ' . $seen_usernames[$username_key] . '.',
                    ['email' => $email, 'role' => $role]
                );
                continue;
            }
            $seen_usernames[$username_key] = $line_number;

            if (isset($seen_emails[$email_key])) {
                eventosapp_bulk_users_add_result_item(
                    $result,
                    'warning',
                    $line_number,
                    $username,
                    'Correo duplicado dentro del bloque. Ya apareció en la línea ' . $seen_emails[$email_key] . '.',
                    ['email' => $email, 'role' => $role]
                );
                continue;
            }
            $seen_emails[$email_key] = $line_number;

            $existing_username_id = username_exists($username);
            if ($existing_username_id) {
                eventosapp_bulk_users_add_result_item(
                    $result,
                    'warning',
                    $line_number,
                    $username,
                    'El nombre de usuario ya existe en WordPress.',
                    ['email' => $email, 'role' => $role, 'user_id' => absint($existing_username_id)]
                );
                continue;
            }

            $existing_email_id = email_exists($email);
            if ($existing_email_id) {
                eventosapp_bulk_users_add_result_item(
                    $result,
                    'warning',
                    $line_number,
                    $username,
                    'El correo electrónico ya pertenece a otro usuario.',
                    ['email' => $email, 'role' => $role, 'user_id' => absint($existing_email_id)]
                );
                continue;
            }

            $display_name = trim($first_name . ' ' . $last_name);
            $user_id      = wp_insert_user([
                'user_login'   => $username,
                'user_pass'    => $password,
                'user_email'   => $email,
                'first_name'   => sanitize_text_field($first_name),
                'last_name'    => sanitize_text_field($last_name),
                'display_name' => $display_name !== '' ? sanitize_text_field($display_name) : $username,
                'role'         => $role,
            ]);

            if (is_wp_error($user_id)) {
                eventosapp_bulk_users_add_result_item(
                    $result,
                    'error',
                    $line_number,
                    $username,
                    implode(' ', $user_id->get_error_messages()),
                    ['email' => $email, 'role' => $role]
                );
                continue;
            }

            eventosapp_bulk_users_add_result_item(
                $result,
                'created',
                $line_number,
                $username,
                'Usuario creado correctamente. No se envió notificación.',
                [
                    'email'   => $email,
                    'role'    => $role,
                    'user_id' => absint($user_id),
                ]
            );
        }

        eventosapp_bulk_users_redirect_with_result($result);
    }
}

add_action('admin_post_eventosapp_bulk_delete_users', 'eventosapp_handle_bulk_delete_users');
if ( ! function_exists('eventosapp_handle_bulk_delete_users') ) {
    function eventosapp_handle_bulk_delete_users() {
        if (!current_user_can('manage_options') || !current_user_can('delete_users')) {
            wp_die('No tienes permisos para eliminar usuarios.', '', ['response' => 403]);
        }

        check_admin_referer('eventosapp_bulk_delete_users', 'eventosapp_bulk_delete_users_nonce');

        $rows   = eventosapp_bulk_users_parse_lines($_POST['eventosapp_bulk_delete_users_data'] ?? '');
        $result = eventosapp_bulk_users_base_result('delete', count($rows));

        if (empty($_POST['eventosapp_bulk_delete_confirm'])) {
            eventosapp_bulk_users_add_result_item($result, 'error', 0, '', 'Debes confirmar que entiendes que la eliminación es permanente.');
            eventosapp_bulk_users_redirect_with_result($result);
        }

        if (empty($rows)) {
            eventosapp_bulk_users_add_result_item($result, 'error', 0, '', 'No se encontraron usuarios para eliminar.');
            eventosapp_bulk_users_redirect_with_result($result);
        }

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        if (is_multisite()) {
            eventosapp_bulk_users_add_result_item(
                $result,
                'error',
                0,
                '',
                'La eliminación permanente está bloqueada en instalaciones Multisite para evitar borrar contenido de otros sitios de la red.'
            );
            eventosapp_bulk_users_redirect_with_result($result);
        }

        $seen_usernames = [];
        $current_user_id = get_current_user_id();

        foreach ($rows as $row) {
            $line_number = absint($row['line']);
            $username_raw = trim((string) $row['value']);
            $username     = sanitize_user($username_raw, true);

            if ($username_raw === '' || $username === '' || $username !== $username_raw || !validate_username($username)) {
                eventosapp_bulk_users_add_result_item(
                    $result,
                    'error',
                    $line_number,
                    $username_raw,
                    'El nombre de usuario es inválido o contiene caracteres no permitidos.'
                );
                continue;
            }

            $username_key = strtolower($username);
            if (isset($seen_usernames[$username_key])) {
                eventosapp_bulk_users_add_result_item(
                    $result,
                    'warning',
                    $line_number,
                    $username,
                    'Usuario duplicado dentro del bloque. Ya apareció en la línea ' . $seen_usernames[$username_key] . '.'
                );
                continue;
            }
            $seen_usernames[$username_key] = $line_number;

            $user = get_user_by('login', $username);
            if (!$user instanceof WP_User) {
                eventosapp_bulk_users_add_result_item($result, 'warning', $line_number, $username, 'El usuario no existe.');
                continue;
            }

            if ((int) $user->ID === (int) $current_user_id) {
                eventosapp_bulk_users_add_result_item($result, 'warning', $line_number, $username, 'No puedes eliminar tu propio usuario desde este proceso.');
                continue;
            }

            $deleted = wp_delete_user($user->ID, $current_user_id);

            if (!$deleted) {
                eventosapp_bulk_users_add_result_item(
                    $result,
                    'error',
                    $line_number,
                    $username,
                    'WordPress no pudo eliminar permanentemente el usuario.',
                    ['user_id' => absint($user->ID)]
                );
                continue;
            }

            eventosapp_bulk_users_add_result_item(
                $result,
                'deleted',
                $line_number,
                $username,
                'Usuario eliminado permanentemente.',
                ['user_id' => absint($user->ID), 'email' => sanitize_email($user->user_email)]
            );
        }

        eventosapp_bulk_users_redirect_with_result($result);
    }
}

if ( ! function_exists('eventosapp_render_bulk_users_result') ) {
    function eventosapp_render_bulk_users_result(array $result) {
        if (empty($result['summary']) || !is_array($result['summary'])) {
            return;
        }

        $summary = wp_parse_args($result['summary'], [
            'processed' => 0,
            'created'   => 0,
            'deleted'   => 0,
            'warnings'  => 0,
            'errors'    => 0,
        ]);
        $action_label = ($result['action'] ?? '') === 'delete' ? 'Eliminación masiva' : 'Creación masiva';
        $notice_class = !empty($summary['errors']) ? 'notice-warning' : 'notice-success';
        ?>
        <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible" style="margin:16px 0 20px;">
            <p>
                <strong><?php echo esc_html($action_label); ?> finalizada.</strong>
                Procesados: <?php echo absint($summary['processed']); ?> ·
                Creados: <?php echo absint($summary['created']); ?> ·
                Eliminados: <?php echo absint($summary['deleted']); ?> ·
                Advertencias: <?php echo absint($summary['warnings']); ?> ·
                Errores: <?php echo absint($summary['errors']); ?>.
            </p>
        </div>

        <?php if (!empty($result['items']) && is_array($result['items'])): ?>
            <div class="evapp-bulk-users-results">
                <h3>Detalle del proceso</h3>
                <div class="evapp-bulk-users-table-wrap">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Línea</th>
                                <th>Estado</th>
                                <th>Usuario</th>
                                <th>Correo</th>
                                <th>Perfil</th>
                                <th>Resultado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['items'] as $item): ?>
                                <?php
                                $status = sanitize_key($item['status'] ?? 'error');
                                $labels = [
                                    'created' => 'Creado',
                                    'deleted' => 'Eliminado',
                                    'warning' => 'Advertencia',
                                    'error'   => 'Error',
                                ];
                                ?>
                                <tr>
                                    <td><?php echo !empty($item['line']) ? absint($item['line']) : '—'; ?></td>
                                    <td><span class="evapp-bulk-status evapp-bulk-status-<?php echo esc_attr($status); ?>"><?php echo esc_html($labels[$status] ?? ucfirst($status)); ?></span></td>
                                    <td><code><?php echo esc_html($item['username'] ?? ''); ?></code></td>
                                    <td><?php echo esc_html($item['email'] ?? ''); ?></td>
                                    <td><code><?php echo esc_html($item['role'] ?? ''); ?></code></td>
                                    <td><?php echo esc_html($item['message'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif;
    }
}

if ( ! function_exists('eventosapp_render_bulk_users_section') ) {
    function eventosapp_render_bulk_users_section() {
        $roles = eventosapp_bulk_users_available_roles();
        ?>
        <hr id="eventosapp-bulk-users" style="margin:32px 0 24px;">
        <h2>Gestión masiva de usuarios</h2>
        <p>Crea o elimina usuarios de WordPress desde bloques de texto. Cada línea se procesa de forma independiente y al terminar se muestra el detalle completo.</p>

        <style>
            .evapp-bulk-users-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:20px;max-width:1280px;margin-top:18px}
            .evapp-bulk-users-card{background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:20px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
            .evapp-bulk-users-card h3{margin:0 0 10px;font-size:18px}
            .evapp-bulk-users-card textarea{width:100%;min-height:230px;font-family:Consolas,Monaco,monospace;font-size:13px;line-height:1.55;resize:vertical}
            .evapp-bulk-users-format{padding:10px 12px;background:#f6f7f7;border-left:4px solid #3782c4;margin:12px 0}
            .evapp-bulk-users-format code{word-break:break-all}
            .evapp-bulk-users-warning{padding:10px 12px;background:#fcf0f1;border-left:4px solid #d63638;color:#691c1c;margin:12px 0}
            .evapp-bulk-users-role-list{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 14px}
            .evapp-bulk-users-role-list code{background:#f0f0f1;border-radius:4px;padding:3px 6px}
            .evapp-bulk-users-table-wrap{overflow:auto;max-width:1280px;margin-bottom:24px}
            .evapp-bulk-users-results{max-width:1280px;margin:16px 0 24px}
            .evapp-bulk-users-results table{min-width:850px}
            .evapp-bulk-status{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap}
            .evapp-bulk-status-created,.evapp-bulk-status-deleted{background:#edfaef;color:#116329}
            .evapp-bulk-status-warning{background:#fff8e5;color:#8a4b00}
            .evapp-bulk-status-error{background:#fcf0f1;color:#8a2424}
            @media(max-width:782px){.evapp-bulk-users-grid{grid-template-columns:1fr}.evapp-bulk-users-card{padding:16px}}
        </style>

        <div class="evapp-bulk-users-grid">
            <div class="evapp-bulk-users-card">
                <h3>Crear usuarios masivamente</h3>
                <p>Agrega un usuario por línea con seis valores separados por coma.</p>
                <div class="evapp-bulk-users-format">
                    <strong>Formato:</strong><br>
                    <code>usuario,primernombre,apellidos,contraseña,correo,perfil</code><br><br>
                    <strong>Ejemplo:</strong><br>
                    <code>mariacamilaestit,maria,estit,-123456-,tickets@eventosapp.com,staff</code>
                </div>
                <p><strong>Perfiles disponibles:</strong></p>
                <div class="evapp-bulk-users-role-list">
                    <?php foreach ($roles as $role_slug => $role_data): ?>
                        <code><?php echo esc_html($role_slug); ?></code>
                    <?php endforeach; ?>
                </div>
                <p class="description">No se evalúa la fortaleza de la contraseña y EventosApp no envía notificaciones de creación.</p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="eventosapp_bulk_create_users">
                    <?php wp_nonce_field('eventosapp_bulk_create_users', 'eventosapp_bulk_create_users_nonce'); ?>
                    <label for="eventosapp_bulk_create_users_data" class="screen-reader-text">Usuarios para crear</label>
                    <textarea id="eventosapp_bulk_create_users_data" name="eventosapp_bulk_create_users_data" spellcheck="false" placeholder="mariacamilaestit,maria,estit,-123456-,tickets@eventosapp.com,staff" required></textarea>
                    <?php submit_button('Crear usuarios', 'primary', 'submit', false); ?>
                </form>
            </div>

            <div class="evapp-bulk-users-card">
                <h3>Eliminar usuarios masivamente</h3>
                <p>Escribe únicamente el nombre de usuario, uno por cada línea.</p>
                <div class="evapp-bulk-users-format">
                    <strong>Ejemplo:</strong><br>
                    <code>mariacamilaestit</code><br>
                    <code>juanoperaciones</code>
                </div>
                <div class="evapp-bulk-users-warning">
                    <strong>Eliminación permanente:</strong> se borrará el usuario y sus metadatos. El contenido que tenga asignado se reasignará al administrador que ejecuta el proceso. Esta acción no usa papelera y no se puede deshacer.
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm('¿Confirmas la eliminación permanente de todos los usuarios indicados? Esta acción no se puede deshacer.');">
                    <input type="hidden" name="action" value="eventosapp_bulk_delete_users">
                    <?php wp_nonce_field('eventosapp_bulk_delete_users', 'eventosapp_bulk_delete_users_nonce'); ?>
                    <label for="eventosapp_bulk_delete_users_data" class="screen-reader-text">Usuarios para eliminar</label>
                    <textarea id="eventosapp_bulk_delete_users_data" name="eventosapp_bulk_delete_users_data" spellcheck="false" placeholder="mariacamilaestit&#10;juanoperaciones" required></textarea>
                    <p>
                        <label>
                            <input type="checkbox" name="eventosapp_bulk_delete_confirm" value="1" required>
                            Entiendo que los usuarios se eliminarán permanentemente.
                        </label>
                    </p>
                    <?php submit_button('Eliminar usuarios permanentemente', 'delete', 'submit', false); ?>
                </form>
            </div>
        </div>
        <?php
    }
}


function eventosapp_render_configuracion_page(){
    $bulk_users_result = eventosapp_bulk_users_pull_result();
    ?>
<div class="wrap">
        <h1>Configuración de EventosApp</h1>
        <?php eventosapp_render_bulk_users_result($bulk_users_result); ?>
        <form method="post" action="options.php">
            <?php
            settings_fields('eventosapp_pages_group');
            do_settings_sections('eventosapp_configuracion');
            submit_button();
            ?>
        </form>
        <hr>
        <h2>Shortcodes necesarios</h2>
        <ul style="list-style:disc;padding-left:18px">
            <li><code>[eventosapp_dashboard]</code> — Dashboard de gestión.</li>
            <li><code>[eventosapp_front_search]</code> — Check-In manual & Escarapela.</li>
            <li><code>[eventosapp_self_checkin]</code> — Autogestión del asistente e impresión de escarapela.</li>
            <li><code>[eventosapp_front_register]</code> — Registro manual de asistentes.</li>
            <li><code>[eventosapp_qr_checkin]</code> — Check-In con QR (lector de cámara).</li>
            <li><code>[eventosapp_front_metrics]</code> — Métricas del evento.</li>
            <li><code>[eventosapp_whatsapp_flow_metrics]</code> — Métricas de Encuestas del evento.</li>
            <li><code>[eventosapp_front_edit]</code> — Edición de tickets.</li>
            <li><code>[eventosapp_qr_localidad]</code> — Validador de Localidad (solo lectura).</li>
            <li><code>[eventosapp_qr_sesion]</code> — Control de acceso por sesión.</li>
            <li><code>[eventosapp_event_checklist]</code> — Checklist del evento (para coordinador).</li>
            <li><code>[eventosapp_networking_ranking]</code> — Ranking Networking (Top lectores y leídos del día).</li>
            <li><code>[qr_checkin_doble_auth]</code> — Check-In con QR y Doble Autenticación.</li>
            <li><code>[eventosapp_face_checkin]</code> — Check-In por Reconocimiento Facial.</li>
            <li><code>[eventosapp_support_assistance]</code> — Asistencia / Equipo de apoyo.</li>
            <li><code>[eventosapp_support_team_metrics]</code> — Métricas del equipo de apoyo.</li>
            <li><code>[eventosapp_expositor]</code> — Módulo de entregas del expositor.</li>
            <li><code>[eventosapp_expositor_gestion]</code> — Gestión/autorización de expositores por organizador.</li>
            <li><code>[eventosapp_company_checkin_monitor]</code> — Monitor dinámico de empresas y asistentes con check-in.</li>
        </ul>
        <?php eventosapp_render_bulk_users_section(); ?>
    </div>
<?php }
// ==============================
// VISIBILIDAD DEL DASHBOARD POR ROL
// ==============================

// 1) Definición centralizada de “features” (botones/opciones del dashboard)
if ( ! function_exists('eventosapp_dashboard_features') ) {
function eventosapp_dashboard_features() {
    return [
        'dashboard'          => 'Ver Dashboard',
        'metrics'            => 'Métricas',
        'flow_metrics'       => 'Métricas de Encuestas',
        'search'             => 'Check-In Manual & Escarapela',
        'self_checkin'       => 'Autogestión del Asistente',
        'register'           => 'Registro Manual de Asistentes',
        'qr'                 => 'Check-In con QR',
        'edit'               => 'Edición de Tickets',
        'qr_localidad'       => 'Validador de Localidad',
        'qr_sesion'          => 'Control por Sesión',
        'checklist'          => 'Checklist de Evento',
        'networking_ranking' => 'Ranking Networking',
        'qr_double_auth'     => 'Check-In QR Doble Autenticación',
        'face_checkin'       => 'Check-In Facial', // NUEVO
        'support_assistance'   => 'Asistencia',
        'support_team_metrics' => 'Métrica de equipo de apoyo',
        'expositor'            => 'Expositor',
        'expositor_gestion'    => 'Gestión de Expositores',
        'company_checkin'       => 'Empresas con Check-In',
    ];
}
}


// 2) Roles disponibles (editable roles)
if ( ! function_exists('eventosapp_get_all_roles') ) {
    function eventosapp_get_all_roles() {
        if ( ! function_exists('get_editable_roles') ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        $wp_roles = get_editable_roles();
        // Devuelve ['slug' => 'Nombre bonito']
        $out = [];
        foreach ($wp_roles as $slug => $info) {
            $out[$slug] = isset($info['name']) ? translate_user_role($info['name']) : ucfirst($slug);
        }
        return $out;
    }
}

// 3) Defaults sensatos (admin/organizador todo ON; staff/logistico limitado; resto OFF)
if ( ! function_exists('eventosapp_default_dashboard_visibility') ) {
function eventosapp_default_dashboard_visibility() {
    $roles    = eventosapp_get_all_roles();
    $features = array_keys( eventosapp_dashboard_features() );
    $defaults = [];

    // Base: todo OFF
    foreach ($roles as $r => $rlabel) {
        $defaults[$r] = array_fill_keys($features, 0);
    }

    // Admin / Organizador: todo ON
    foreach (['administrator','organizador'] as $r) {
        if (isset($defaults[$r])) {
            foreach ($features as $f) $defaults[$r][$f] = 1;
        }
    }

    // Staff (ejemplo conservador)
    if (isset($defaults['staff'])) {
        $defaults['staff']['dashboard']     = 1;
        $defaults['staff']['search']        = 1;
        $defaults['staff']['self_checkin']  = 1;
        $defaults['staff']['qr']            = 1;
        $defaults['staff']['qr_localidad']  = 1;
        $defaults['staff']['qr_sesion']     = 1;
        // checklist: OFF por defecto para staff
        // networking_ranking: OFF por defecto para staff (ajústalo si lo deseas ON)
    }

    // Logístico
    if (isset($defaults['logistico'])) {
        $defaults['logistico']['dashboard']     = 1;
        $defaults['logistico']['self_checkin']  = 1;
        $defaults['logistico']['qr']            = 1;
        $defaults['logistico']['qr_localidad']  = 1;
        $defaults['logistico']['qr_sesion']     = 1;
        // checklist: OFF
        // networking_ranking: OFF (ajústalo si lo deseas)
    }

    // Coordinador: dashboard + checklist ON (y activamos ranking por utilidad operativa)
    if (isset($defaults['coordinador'])) {
        $defaults['coordinador']['dashboard']          = 1;
        $defaults['coordinador']['checklist']          = 1;
        $defaults['coordinador']['networking_ranking'] = 1;
    }

    // Expositor: sólo dashboard + módulo Expositor.
    if (isset($defaults['expositor'])) {
        $defaults['expositor']['dashboard'] = 1;
        $defaults['expositor']['expositor'] = 1;
    }

    return $defaults;
}
}


// 4) Obtener/merge opción guardada con defaults
if ( ! function_exists('eventosapp_get_dashboard_visibility') ) {
    function eventosapp_get_dashboard_visibility() {
        $saved    = get_option('eventosapp_dashboard_visibility', []);
        $defaults = eventosapp_default_dashboard_visibility();

        // Asegura que existan todos los roles/features
        foreach ($defaults as $role => $map) {
            if (!isset($saved[$role]) || !is_array($saved[$role])) $saved[$role] = [];
            foreach ($map as $feat => $on) {
                if (!isset($saved[$role][$feat])) $saved[$role][$feat] = $on;
                $saved[$role][$feat] = (int) !!$saved[$role][$feat];
            }
        }

        // Limpia roles que ya no existan
        $roles = array_keys( eventosapp_get_all_roles() );
        foreach (array_keys($saved) as $r) {
            if (!in_array($r, $roles, true)) unset($saved[$r]);
        }
        return $saved;
    }
}

// 5) Helper: ¿el usuario puede X?
if ( ! function_exists('eventosapp_role_can') ) {
    function eventosapp_role_can($feature, $user = null) {
        $features = eventosapp_dashboard_features();
        if (!isset($features[$feature])) return false;

        $u = $user ? get_userdata($user) : wp_get_current_user();
        if ( !$u || !$u->exists() ) return false;

        $visibility = eventosapp_get_dashboard_visibility();
        $roles_user = (array) $u->roles;

        // Si el usuario tiene múltiples roles, basta con que uno permita la feature
        foreach ($roles_user as $r) {
            if (isset($visibility[$r], $visibility[$r][$feature]) && (int)$visibility[$r][$feature] === 1) {
                /**
                 * Filtro por si algún plugin quiere sobre-escribir dinámicamente
                 * (true/false final)
                 */
                return (bool) apply_filters('eventosapp_role_can', true, $feature, $u);
            }
        }
        return (bool) apply_filters('eventosapp_role_can', false, $feature, $u);
    }
}

// 6) Registrar opción y UI en la misma página de configuración existente
add_action('admin_init', function() {

    // Opción nueva
    register_setting('eventosapp_pages_group', 'eventosapp_dashboard_visibility', [
        'type'              => 'array',
        'sanitize_callback' => function($input) {
            $roles    = eventosapp_get_all_roles();
            $features = array_keys( eventosapp_dashboard_features() );
            $out = [];

            if (!is_array($input)) $input = [];

            foreach ($roles as $r => $rlabel) {
                $out[$r] = [];
                foreach ($features as $f) {
                    $val = isset($input[$r][$f]) ? (int) !!$input[$r][$f] : 0;
                    $out[$r][$f] = $val;
                }
            }
            return $out;
        },
        'default'           => eventosapp_default_dashboard_visibility(),
    ]);

    // Sección nueva
    add_settings_section(
        'eventosapp_roles_section',
        'Visibilidad del Dashboard por Rol',
        function(){
            echo '<p>Marca qué opciones del dashboard ve cada rol. '
               . 'Esto afecta <em>solo</em> lo que se muestra en el menú del dashboard del frontend.</p>';
        },
        'eventosapp_configuracion'
    );

    // Campo con grilla de checks
    add_settings_field(
        'eventosapp_dashboard_visibility',
        'Permisos de menús por rol',
        function() {
            $roles    = eventosapp_get_all_roles();
            $features = eventosapp_dashboard_features();
            $cfg      = eventosapp_get_dashboard_visibility();

            echo '<style>
                .evapp-perm-table { border-collapse: collapse; margin-top:4px; }
                .evapp-perm-table th, .evapp-perm-table td { border:1px solid #ddd; padding:6px 8px; text-align:center; }
                .evapp-perm-table th { background:#f6f7f7; }
                .evapp-perm-table td.role { text-align:left; font-weight:600; background:#fafafa; }
            </style>';

            echo '<table class="evapp-perm-table">';
            echo '<thead><tr><th>Rol</th>';
            foreach ($features as $key => $label) {
                echo '<th>'.esc_html($label).'</th>';
            }
            echo '</tr></thead><tbody>';

            foreach ($roles as $slug => $label) {
                echo '<tr>';
                echo '<td class="role">'.esc_html($label).' <code>'.esc_html($slug).'</code></td>';
                foreach ($features as $featKey => $featLabel) {
                    $checked = !empty($cfg[$slug][$featKey]) ? 'checked' : '';
                    $name = 'eventosapp_dashboard_visibility['.esc_attr($slug).']['.esc_attr($featKey).']';
                    echo '<td><input type="checkbox" name="'.$name.'" value="1" '.$checked.'></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';

            echo '<p class="description" style="margin-top:6px;">'
               . 'Consejo: <b>Ver Dashboard</b> controla el acceso general al panel; '
               . 'el resto controla la <em>visualización</em> de cada botón.</p>';
        },
        'eventosapp_configuracion',
        'eventosapp_roles_section'
    );

});

// =====================================================
// Mapa: feature -> key de página configurada
// =====================================================
if ( ! function_exists('eventosapp_feature_page_map') ) {
function eventosapp_feature_page_map() {
    return [
        'dashboard'          => 'dashboard_page_id',
        'metrics'            => 'metrics_page_id',
        'flow_metrics'       => 'flow_metrics_page_id',
        'search'             => 'front_search_page_id',
        'self_checkin'       => 'self_checkin_page_id',
        'register'           => 'register_page_id',
        'qr'                 => 'qr_page_id',
        'edit'               => 'edit_page_id',
        'qr_localidad'       => 'qr_localidad_page_id',
        'qr_sesion'          => 'qr_sesion_page_id',
        'checklist'          => 'checklist_page_id',
        'networking_ranking' => 'networking_ranking_page_id',
        'qr_double_auth'     => 'qr_double_auth_page_id',
        'face_checkin'       => 'face_checkin_page_id', // NUEVO
        'support_assistance'   => 'support_assistance_page_id',
        'support_team_metrics' => 'support_team_metrics_page_id',
        'expositor'            => 'expositor_page_id',
        'expositor_gestion'    => 'expositor_gestion_page_id',
        'company_checkin'       => 'company_checkin_page_id',
    ];
}
}


// =====================================================
// Redirección con mensaje al dashboard
// =====================================================
if ( ! function_exists('eventosapp_redirect_with_error') ) {
    function eventosapp_redirect_with_error($message, $extra = []) {
        $cfg     = eventosapp_get_pages_config();
        $dash_id = (int) ($cfg['dashboard_page_id'] ?? 0);
        $dash    = $dash_id ? get_permalink($dash_id) : home_url('/');

        // Parámetros a pasar al dashboard
        $args = array_merge([
            'evapp_err' => rawurlencode($message),
        ], is_array($extra) ? $extra : []);

        // Limpia flags previos para no acumularlos en la URL
        $dash = remove_query_arg(['evapp_err','set'], $dash);

        wp_safe_redirect( add_query_arg($args, $dash) );
        exit;
    }
}


// =====================================================
// Guardado común (para usar dentro de shortcodes si se desea)
// =====================================================
if ( ! function_exists('eventosapp_require_feature') ) {
    function eventosapp_require_feature($feature) {
        if ( ! is_user_logged_in() ) {
            eventosapp_redirect_with_error('Debes iniciar sesión para acceder a esta sección.', ['from'=>$feature]);
        }
        if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can($feature) ) {
            $labels = eventosapp_dashboard_features();
            $nice   = isset($labels[$feature]) ? $labels[$feature] : $feature;
            eventosapp_redirect_with_error('No tienes permisos para acceder a: '.$nice.'.', ['from'=>$feature]);
        }
    }
}

// =====================================================
// BLOQUEO por URL: protege todas las páginas configuradas
// =====================================================
add_action('template_redirect', function () {
    if ( is_admin() ) return;

    $cfg   = eventosapp_get_pages_config();
    $map   = eventosapp_feature_page_map();
    $pid   = get_queried_object_id();
    if ( ! $pid ) return;

    // ¿Qué feature corresponde a esta página?
    $current_feature = null;
    foreach ($map as $feat => $key) {
        if ( ! empty($cfg[$key]) && (int)$cfg[$key] === (int)$pid ) {
            $current_feature = $feat;
            break;
        }
    }
    if ( ! $current_feature ) return;

    // Evita bucles: la página de dashboard no se redirige aquí (el shortcode ya muestra el error)
    if ($current_feature === 'dashboard') return;

    // Reglas:
    if ( ! is_user_logged_in() ) {
        eventosapp_redirect_with_error('Debes iniciar sesión para acceder a esta sección.', ['from'=>$current_feature]);
    }
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can($current_feature) ) {
        $labels = eventosapp_dashboard_features();
        $nice   = isset($labels[$current_feature]) ? $labels[$current_feature] : $current_feature;
        eventosapp_redirect_with_error('No tienes permisos para acceder a: '.$nice.'.', ['from'=>$current_feature]);
    }
}, 9);



