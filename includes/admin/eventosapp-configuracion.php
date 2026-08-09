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
 *   'qr_sesion_page_id'         => (int), // Control por sesión
 *   'consumables_manager_page_id'=> (int), // Configuración de consumibles
 *   'consumables_staff_page_id'  => (int), // Consumo por QR
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
        'consumables_manager_page_id'  => 0, // Configuración de consumibles
        'consumables_staff_page_id'    => 0, // Consumo de consumibles por QR
        'live_raffle_page_id'          => 0, // Gestión del sorteo en vivo
        'live_raffle_public_page_id'   => 0, // Pantalla pública del sorteo
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

function eventosapp_get_consumables_manager_url() {
    return eventosapp_get_configured_page_url('consumables_manager_page_id', '#');
}

function eventosapp_get_consumables_staff_url() {
    return eventosapp_get_configured_page_url('consumables_staff_page_id', '#');
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
    ['key'=>'networking_ranking_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_ranking_networking]</code>']
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

    add_settings_field(
        'consumables_manager_page_id',
        'Página de Control de Consumibles',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'consumables_manager_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_consumables_manager]</code>']
    );

    add_settings_field(
        'consumables_staff_page_id',
        'Página de Consumo de Consumibles',
        'eventosapp_render_pages_field',
        'eventosapp_configuracion',
        'eventosapp_pages_section',
        ['key'=>'consumables_staff_page_id', 'desc'=>'Debe contener el shortcode: <code>[eventosapp_consumables_staff]</code>']
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
        'consumables_manager_page_id',
        'consumables_staff_page_id',
        'live_raffle_page_id',
        'live_raffle_public_page_id',
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
 * ============================================================
 * ESTADO E INSTALACIÓN AUTOMÁTICA DE PÁGINAS
 * ============================================================
 *
 * Esta capa no reemplaza el mapeo manual existente. Lo complementa con:
 * - inventario central de páginas y shortcodes;
 * - diagnóstico de shortcode registrado, página existente y mapeo;
 * - instalación/reparación individual;
 * - instalación completa en segundo plano mediante la Cola y Tareas central;
 * - soporte para las páginas de Networking que usan su opción histórica
 *   `eventosapp_networking_pages`.
 *
 * Importante: el instalador jamás "crea" el callback PHP de un shortcode.
 * Si el módulo que registra el shortcode no está cargado, la página no se crea
 * para evitar dejar rutas rotas.
 */

if ( ! function_exists('eventosapp_installation_page_registry') ) {
    function eventosapp_installation_page_registry() {
        $registry = [
            'dashboard' => [
                'group'       => 'Operación',
                'label'       => 'Dashboard',
                'description' => 'Panel principal de gestión del frontend.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'dashboard_page_id',
                'shortcode'   => 'eventosapp_dashboard',
                'content'     => '[eventosapp_dashboard]',
                'title'       => 'Dashboard',
                'slug'        => 'dashboard',
            ],
            'front_search' => [
                'group'       => 'Operación',
                'label'       => 'Check-In Manual & Escarapela',
                'description' => 'Búsqueda manual, check-in e impresión de escarapela.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'front_search_page_id',
                'shortcode'   => 'eventosapp_front_search',
                'content'     => '[eventosapp_front_search]',
                'title'       => 'Check-In',
                'slug'        => 'check-in',
            ],
            'self_checkin' => [
                'group'       => 'Operación',
                'label'       => 'Autogestión del Asistente',
                'description' => 'Kiosko web de identificación e impresión de escarapela.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'self_checkin_page_id',
                'shortcode'   => 'eventosapp_self_checkin',
                'content'     => '[eventosapp_self_checkin]',
                'title'       => 'Kiosko de Autogestión',
                'slug'        => 'kiosko-de-autogestion',
            ],
            'register' => [
                'group'       => 'Operación',
                'label'       => 'Registro Manual de Asistentes',
                'description' => 'Formulario interno para crear asistentes y tickets.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'register_page_id',
                'shortcode'   => 'eventosapp_front_register',
                'content'     => '[eventosapp_front_register]',
                'title'       => 'Registro Manual',
                'slug'        => 'registro-manual',
            ],
            'qr_checkin' => [
                'group'       => 'Operación',
                'label'       => 'Check-In con QR',
                'description' => 'Lector QR de cámara para el acceso general.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'qr_page_id',
                'shortcode'   => 'eventosapp_qr_checkin',
                'content'     => '[eventosapp_qr_checkin]',
                'title'       => 'QR Check-In',
                'slug'        => 'qr-checkin',
            ],
            'metrics' => [
                'group'       => 'Métricas',
                'label'       => 'Métricas',
                'description' => 'Panel de métricas generales del evento.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'metrics_page_id',
                'shortcode'   => 'eventosapp_front_metrics',
                'content'     => '[eventosapp_front_metrics]',
                'title'       => 'Métricas',
                'slug'        => 'metricas',
            ],
            'flow_metrics' => [
                'group'       => 'Métricas',
                'label'       => 'Métricas de Encuestas',
                'description' => 'Resultados de WhatsApp Flows y encuestas.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'flow_metrics_page_id',
                'shortcode'   => 'eventosapp_whatsapp_flow_metrics',
                'content'     => '[eventosapp_whatsapp_flow_metrics]',
                'title'       => 'Métricas de Encuestas',
                'slug'        => 'metricas-encuestas',
            ],
            'edit' => [
                'group'       => 'Operación',
                'label'       => 'Edición de Tickets',
                'description' => 'Edición y actualización de asistentes/tickets desde frontend.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'edit_page_id',
                'shortcode'   => 'eventosapp_front_edit',
                'content'     => '[eventosapp_front_edit]',
                'title'       => 'Edición de Tickets',
                'slug'        => 'edicion-tickets',
            ],
            'qr_localidad' => [
                'group'       => 'Accesos',
                'label'       => 'Validador de Localidad',
                'description' => 'Validación de localidad mediante QR.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'qr_localidad_page_id',
                'shortcode'   => 'eventosapp_qr_localidad',
                'content'     => '[eventosapp_qr_localidad]',
                'title'       => 'QR Acceso',
                'slug'        => 'qr-acceso',
            ],
            'qr_sesion' => [
                'group'       => 'Accesos',
                'label'       => 'Control por Sesión',
                'description' => 'Control de acceso a sesiones mediante QR.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'qr_sesion_page_id',
                'shortcode'   => 'eventosapp_qr_sesion',
                'content'     => '[eventosapp_qr_sesion]',
                'title'       => 'Control por Sesión',
                'slug'        => 'control-sesion',
            ],
            'checklist' => [
                'group'       => 'Operación',
                'label'       => 'Checklist de Evento',
                'description' => 'Checklist operativo del evento.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'checklist_page_id',
                'shortcode'   => 'eventosapp_event_checklist',
                'content'     => '[eventosapp_event_checklist]',
                'title'       => 'Checklist de Evento',
                'slug'        => 'checklist-evento',
            ],
            'networking_ranking' => [
                'group'       => 'Networking',
                'label'       => 'Ranking Networking',
                'description' => 'Ranking de actividad de networking.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'networking_ranking_page_id',
                'shortcode'         => 'eventosapp_ranking_networking',
                'content'           => '[eventosapp_ranking_networking]',
                'legacy_shortcodes' => ['eventosapp_networking_ranking'],
                'title'             => 'Ranking Networking',
                'slug'        => 'ranking-networking',
            ],
            'qr_double_auth' => [
                'group'       => 'Accesos',
                'label'       => 'Check-In QR con Doble Autenticación',
                'description' => 'Acceso QR reforzado con segundo factor.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'qr_double_auth_page_id',
                'shortcode'   => 'qr_checkin_doble_auth',
                'content'     => '[qr_checkin_doble_auth]',
                'title'       => 'QR Check-In Doble Autenticación',
                'slug'        => 'qr-checkin-doble-auth',
            ],
            'face_checkin' => [
                'group'       => 'Accesos',
                'label'       => 'Check-In Facial',
                'description' => 'Check-in por reconocimiento facial.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'face_checkin_page_id',
                'shortcode'   => 'eventosapp_face_checkin',
                'content'     => '[eventosapp_face_checkin]',
                'title'       => 'Check-In Facial',
                'slug'        => 'checkin-facial',
            ],
            'support_assistance' => [
                'group'       => 'Equipo de apoyo',
                'label'       => 'Asistencia',
                'description' => 'Registro operativo del equipo de apoyo.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'support_assistance_page_id',
                'shortcode'   => 'eventosapp_support_assistance',
                'content'     => '[eventosapp_support_assistance]',
                'title'       => 'Asistencia',
                'slug'        => 'asistencia',
            ],
            'support_team_metrics' => [
                'group'       => 'Equipo de apoyo',
                'label'       => 'Métricas del Equipo de Apoyo',
                'description' => 'Métricas y seguimiento del equipo de apoyo.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'support_team_metrics_page_id',
                'shortcode'   => 'eventosapp_support_team_metrics',
                'content'     => '[eventosapp_support_team_metrics]',
                'title'       => 'Métricas Equipo de Apoyo',
                'slug'        => 'metricas-equipo-apoyo',
            ],
            'expositor' => [
                'group'       => 'Expositores',
                'label'       => 'Módulo Expositor',
                'description' => 'Módulo de entregas e inventario del expositor.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'expositor_page_id',
                'shortcode'   => 'eventosapp_expositor',
                'content'     => '[eventosapp_expositor]',
                'title'       => 'Expositor',
                'slug'        => 'expositor',
            ],
            'expositor_gestion' => [
                'group'       => 'Expositores',
                'label'       => 'Gestión de Expositores',
                'description' => 'Administración y autorización de expositores.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'expositor_gestion_page_id',
                'shortcode'   => 'eventosapp_expositor_gestion',
                'content'     => '[eventosapp_expositor_gestion]',
                'title'       => 'Gestión de Expositores',
                'slug'        => 'gestion-expositores',
            ],
            'company_checkin' => [
                'group'       => 'Monitores',
                'label'       => 'Monitor de Empresas',
                'description' => 'Monitor dinámico de empresas y asistentes con check-in.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'company_checkin_page_id',
                'shortcode'   => 'eventosapp_company_checkin_monitor',
                'content'     => '[eventosapp_company_checkin_monitor]',
                'title'       => 'Control de Asistentes de Empresas',
                'slug'        => 'control-asistentes-empresas',
            ],
            'consumables_manager' => [
                'group'       => 'Consumibles',
                'label'       => 'Control de Consumibles',
                'description' => 'Configuración de inventarios y segmentación de consumibles.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'consumables_manager_page_id',
                'shortcode'   => 'eventosapp_consumables_manager',
                'content'     => '[eventosapp_consumables_manager]',
                'title'       => 'Control de Consumibles',
                'slug'        => 'control-consumibles',
            ],
            'consumables_staff' => [
                'group'       => 'Consumibles',
                'label'       => 'Consumo de Consumibles',
                'description' => 'Lectura QR y descuento de consumibles por staff.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'consumables_staff_page_id',
                'shortcode'   => 'eventosapp_consumables_staff',
                'content'     => '[eventosapp_consumables_staff]',
                'title'       => 'Consumo de Consumibles',
                'slug'        => 'consumo-consumibles',
            ],
            'live_raffle' => [
                'group'       => 'Sorteo en Vivo',
                'label'       => 'Gestión del Sorteo en Vivo',
                'description' => 'Panel de gestión del sorteo para organizadores.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'live_raffle_page_id',
                'shortcode'   => 'eventosapp_live_raffle',
                'content'     => '[eventosapp_live_raffle]',
                'title'       => 'Sorteo en Vivo',
                'slug'        => 'sorteo-en-vivo',
            ],
            'live_raffle_public' => [
                'group'       => 'Sorteo en Vivo',
                'label'       => 'Pantalla Pública del Sorteo',
                'description' => 'Pantalla pública/proyección. El módulo agrega el evento a la URL.',
                'option'      => 'eventosapp_pages',
                'option_key'  => 'live_raffle_public_page_id',
                'shortcode'   => 'eventosapp_live_raffle_public',
                'content'     => '[eventosapp_live_raffle_public]',
                'title'       => 'Sorteo',
                'slug'        => 'sorteo',
            ],
            'networking_parent' => [
                'group'       => 'Networking',
                'label'       => 'Página Padre de Networking',
                'description' => 'Contenedor estructural para /networking/ y sus landings hijas.',
                'option'      => 'eventosapp_networking_pages',
                'option_key'  => 'parent_page_id',
                'shortcode'   => '',
                'content'     => '',
                'title'       => 'Networking',
                'slug'        => 'networking',
                'structural'  => true,
            ],
            'networking_search' => [
                'group'       => 'Networking',
                'label'       => 'Acceso a Networking',
                'description' => 'Página pública para localizar los eventos de networking del asistente.',
                'option'      => 'eventosapp_networking_pages',
                'option_key'  => 'search_page_id',
                'shortcode'   => 'eventosapp_networking_search',
                'content'     => '[eventosapp_networking_search]',
                'title'       => 'Acceso a Networking',
                'slug'        => 'acceso',
                'parent'      => 'networking_parent',
            ],
            'networking_global' => [
                'group'       => 'Networking',
                'label'       => 'Networking Global',
                'description' => 'Escaneo y consulta global de contactos dentro del evento.',
                'option'      => 'eventosapp_networking_pages',
                'option_key'  => 'global_page_id',
                'shortcode'   => 'eventosapp_networking_global',
                'content'     => '[eventosapp_networking_global]',
                'title'       => 'Networking Global',
                'slug'        => 'global',
                'parent'      => 'networking_parent',
            ],
        ];

        return (array) apply_filters('eventosapp_installation_page_registry', $registry);
    }
}

if ( ! function_exists('eventosapp_installation_get_option_array') ) {
    function eventosapp_installation_get_option_array($option_name) {
        $value = get_option(sanitize_key((string) $option_name), []);
        return is_array($value) ? $value : [];
    }
}

if ( ! function_exists('eventosapp_installation_get_mapped_page_id') ) {
    function eventosapp_installation_get_mapped_page_id(array $definition) {
        $option_name = sanitize_key($definition['option'] ?? 'eventosapp_pages');
        $option_key  = sanitize_key($definition['option_key'] ?? '');
        if ($option_name === '' || $option_key === '') {
            return 0;
        }

        $cfg = eventosapp_installation_get_option_array($option_name);
        return absint($cfg[$option_key] ?? 0);
    }
}

if ( ! function_exists('eventosapp_installation_set_mapped_page_id') ) {
    function eventosapp_installation_set_mapped_page_id(array $definition, $page_id) {
        $option_name = sanitize_key($definition['option'] ?? 'eventosapp_pages');
        $option_key  = sanitize_key($definition['option_key'] ?? '');
        $page_id     = absint($page_id);

        if ($option_name === '' || $option_key === '' || !$page_id) {
            return false;
        }

        $cfg = eventosapp_installation_get_option_array($option_name);
        $cfg[$option_key] = $page_id;

        /*
         * Compatibilidad con el módulo de Sorteo en Vivo:
         * ese módulo conserva sus dos claves mediante un filtro
         * pre_update_option_eventosapp_pages que prioriza $_POST cuando existe.
         * Durante una instalación automática no venimos de options.php, por eso
         * exponemos temporalmente solo la clave que estamos actualizando y luego
         * restauramos el request original.
         */
        $restore_post_pages = null;
        $had_post_pages = array_key_exists('eventosapp_pages', $_POST);

        if ($option_name === 'eventosapp_pages' && in_array($option_key, ['live_raffle_page_id', 'live_raffle_public_page_id'], true)) {
            $restore_post_pages = $had_post_pages ? $_POST['eventosapp_pages'] : null;
            if (!isset($_POST['eventosapp_pages']) || !is_array($_POST['eventosapp_pages'])) {
                $_POST['eventosapp_pages'] = [];
            }
            $_POST['eventosapp_pages'][$option_key] = $page_id;
        }

        $updated = update_option($option_name, $cfg, false);

        if ($option_name === 'eventosapp_pages' && in_array($option_key, ['live_raffle_page_id', 'live_raffle_public_page_id'], true)) {
            if ($had_post_pages) {
                $_POST['eventosapp_pages'] = $restore_post_pages;
            } else {
                unset($_POST['eventosapp_pages']);
            }
        }

        return $updated;
    }
}

if ( ! function_exists('eventosapp_installation_get_valid_page') ) {
    function eventosapp_installation_get_valid_page($page_id) {
        $page_id = absint($page_id);
        if (!$page_id) {
            return null;
        }

        $page = get_post($page_id);
        if (
            !$page instanceof WP_Post ||
            $page->post_type !== 'page' ||
            !in_array($page->post_status, ['publish', 'private'], true)
        ) {
            return null;
        }

        return $page;
    }
}

if ( ! function_exists('eventosapp_installation_page_has_shortcode') ) {
    function eventosapp_installation_page_has_shortcode($page, $shortcode) {
        if (!$page instanceof WP_Post) {
            return false;
        }

        $shortcode = sanitize_key((string) $shortcode);
        if ($shortcode === '') {
            return true;
        }

        return has_shortcode((string) $page->post_content, $shortcode);
    }
}

if ( ! function_exists('eventosapp_installation_candidate_pages') ) {
    function eventosapp_installation_candidate_pages($refresh = false) {
        static $pages = null;

        if ($refresh || $pages === null) {
            $pages = get_posts([
                'post_type'              => 'page',
                'post_status'            => ['publish', 'private'],
                'posts_per_page'         => -1,
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
                'update_post_meta_cache' => false,
            ]);
        }

        return is_array($pages) ? $pages : [];
    }
}

if ( ! function_exists('eventosapp_installation_find_existing_page') ) {
    function eventosapp_installation_find_existing_page(array $definition) {
        $shortcode  = sanitize_key($definition['shortcode'] ?? '');
        $structural = !empty($definition['structural']);

        if ($structural) {
            $slug = sanitize_title($definition['slug'] ?? '');
            if ($slug !== '') {
                $page = get_page_by_path($slug, OBJECT, 'page');
                if ($page instanceof WP_Post && in_array($page->post_status, ['publish', 'private'], true)) {
                    return $page;
                }
            }
            return null;
        }

        if ($shortcode === '') {
            return null;
        }

        foreach (eventosapp_installation_candidate_pages() as $page) {
            if (eventosapp_installation_page_has_shortcode($page, $shortcode)) {
                return $page;
            }
        }

        return null;
    }
}

if ( ! function_exists('eventosapp_installation_definition_status') ) {
    function eventosapp_installation_definition_status($definition_id, array $definition) {
        $definition_id       = sanitize_key((string) $definition_id);
        $shortcode           = sanitize_key($definition['shortcode'] ?? '');
        $structural          = !empty($definition['structural']);
        $shortcode_registered = $structural || ($shortcode !== '' && shortcode_exists($shortcode));
        $mapped_page_id      = eventosapp_installation_get_mapped_page_id($definition);
        $mapped_page         = eventosapp_installation_get_valid_page($mapped_page_id);
        $mapped_has_content  = $mapped_page && ($structural || eventosapp_installation_page_has_shortcode($mapped_page, $shortcode));
        $candidate_page      = (!$mapped_has_content && $shortcode_registered)
            ? eventosapp_installation_find_existing_page($definition)
            : null;

        $state = 'missing';
        if (!$shortcode_registered) {
            $state = 'shortcode_unavailable';
        } elseif ($mapped_has_content) {
            $state = 'ok';
        } elseif ($mapped_page) {
            $state = 'mapped_without_shortcode';
        } elseif ($candidate_page) {
            $state = 'needs_mapping';
        }

        return [
            'id'                   => $definition_id,
            'state'                => $state,
            'healthy'              => $state === 'ok',
            'structural'           => $structural,
            'shortcode'            => $shortcode,
            'shortcode_registered' => (bool) $shortcode_registered,
            'mapped_page_id'       => $mapped_page ? absint($mapped_page->ID) : 0,
            'mapped_page'          => $mapped_page,
            'mapped_has_content'   => (bool) $mapped_has_content,
            'candidate_page_id'    => $candidate_page instanceof WP_Post ? absint($candidate_page->ID) : 0,
            'candidate_page'       => $candidate_page,
        ];
    }
}

if ( ! function_exists('eventosapp_installation_health_summary') ) {
    function eventosapp_installation_health_summary() {
        $registry = eventosapp_installation_page_registry();
        $summary = [
            'total'       => count($registry),
            'healthy'     => 0,
            'attention'   => 0,
            'unavailable' => 0,
        ];

        foreach ($registry as $definition_id => $definition) {
            $status = eventosapp_installation_definition_status($definition_id, $definition);
            if ($status['healthy']) {
                $summary['healthy']++;
            } elseif ($status['state'] === 'shortcode_unavailable') {
                $summary['unavailable']++;
            } else {
                $summary['attention']++;
            }
        }

        return $summary;
    }
}

if ( ! function_exists('eventosapp_installation_resolve_parent_page_id') ) {
    function eventosapp_installation_resolve_parent_page_id(array $definition, array $stack = []) {
        $parent_definition_id = sanitize_key($definition['parent'] ?? '');
        if ($parent_definition_id === '') {
            return 0;
        }

        if (in_array($parent_definition_id, $stack, true)) {
            return new WP_Error('evapp_install_parent_cycle', 'Se detectó una dependencia circular entre páginas.');
        }

        $registry = eventosapp_installation_page_registry();
        if (empty($registry[$parent_definition_id]) || !is_array($registry[$parent_definition_id])) {
            return new WP_Error('evapp_install_parent_missing', 'No existe la definición de la página padre requerida.');
        }

        $parent_definition = $registry[$parent_definition_id];
        $parent_id         = eventosapp_installation_get_mapped_page_id($parent_definition);
        $parent_page       = eventosapp_installation_get_valid_page($parent_id);

        if ($parent_page) {
            return absint($parent_page->ID);
        }

        $stack[] = $parent_definition_id;
        $result = eventosapp_installation_install_definition($parent_definition_id, $parent_definition, $stack);

        if (is_wp_error($result)) {
            return $result;
        }

        return absint($result['page_id'] ?? 0);
    }
}

if ( ! function_exists('eventosapp_installation_append_shortcode_safely') ) {
    function eventosapp_installation_append_shortcode_safely(WP_Post $page, array $definition) {
        $shortcode = sanitize_key($definition['shortcode'] ?? '');
        $content   = trim((string) ($definition['content'] ?? ''));

        if ($shortcode === '' || $content === '') {
            return absint($page->ID);
        }

        if (eventosapp_installation_page_has_shortcode($page, $shortcode)) {
            return absint($page->ID);
        }

        $existing = rtrim((string) $page->post_content);

        // Corrige aliases históricos conocidos sin borrar el resto del contenido.
        $legacy_shortcodes = isset($definition['legacy_shortcodes']) && is_array($definition['legacy_shortcodes'])
            ? $definition['legacy_shortcodes']
            : [];

        foreach ($legacy_shortcodes as $legacy_shortcode) {
            $legacy_shortcode = sanitize_key((string) $legacy_shortcode);
            if ($legacy_shortcode === '') {
                continue;
            }

            $legacy_literal = '[' . $legacy_shortcode . ']';
            if (strpos($existing, $legacy_literal) !== false) {
                $existing = str_replace($legacy_literal, $content, $existing);
            }
        }

        $updated = has_shortcode($existing, $shortcode)
            ? $existing
            : ($existing === '' ? $content : ($existing . "\n\n" . $content));

        $result = wp_update_post([
            'ID'           => $page->ID,
            'post_content' => $updated,
        ], true);

        return is_wp_error($result) ? $result : absint($result);
    }
}

if ( ! function_exists('eventosapp_installation_install_definition') ) {
    function eventosapp_installation_install_definition($definition_id, array $definition = [], array $stack = []) {
        $definition_id = sanitize_key((string) $definition_id);
        if ($definition_id === '') {
            return new WP_Error('evapp_install_invalid_definition', 'La definición de página no es válida.');
        }

        if (empty($definition)) {
            $registry = eventosapp_installation_page_registry();
            if (empty($registry[$definition_id]) || !is_array($registry[$definition_id])) {
                return new WP_Error('evapp_install_unknown_definition', 'La página solicitada no existe en el inventario de instalación.');
            }
            $definition = $registry[$definition_id];
        }

        $shortcode  = sanitize_key($definition['shortcode'] ?? '');
        $structural = !empty($definition['structural']);

        if (!$structural && ($shortcode === '' || !shortcode_exists($shortcode))) {
            return new WP_Error(
                'evapp_shortcode_unavailable',
                'El shortcode [' . ($shortcode ?: 'desconocido') . '] no está registrado por el código cargado de EventosApp. No se creó una página rota.'
            );
        }

        $parent_id = eventosapp_installation_resolve_parent_page_id($definition, $stack);
        if (is_wp_error($parent_id)) {
            return $parent_id;
        }

        $mapped_page_id = eventosapp_installation_get_mapped_page_id($definition);
        $mapped_page    = eventosapp_installation_get_valid_page($mapped_page_id);
        $action         = 'verified';
        $changed        = false;

        if ($mapped_page) {
            if (!$structural && !eventosapp_installation_page_has_shortcode($mapped_page, $shortcode)) {
                $updated = eventosapp_installation_append_shortcode_safely($mapped_page, $definition);
                if (is_wp_error($updated)) {
                    return $updated;
                }
                $changed = true;
                $action  = 'repaired';
                $mapped_page = get_post($mapped_page->ID);
            }

            if (!eventosapp_installation_set_mapped_page_id($definition, $mapped_page->ID)) {
                // update_option() retorna false si el valor no cambió; no es un error.
                $current_id = eventosapp_installation_get_mapped_page_id($definition);
                if ((int) $current_id !== (int) $mapped_page->ID) {
                    return new WP_Error('evapp_install_map_failed', 'No fue posible guardar el mapeo de la página existente.');
                }
            }

            return [
                'ok'      => true,
                'page_id' => absint($mapped_page->ID),
                'action'  => $action,
                'changed' => $changed,
                'message' => $action === 'repaired'
                    ? 'Se conservó la página mapeada y se agregó el shortcode faltante.'
                    : 'La página ya estaba instalada y correctamente mapeada.',
            ];
        }

        $candidate = eventosapp_installation_find_existing_page($definition);
        if ($candidate instanceof WP_Post) {
            if (!eventosapp_installation_set_mapped_page_id($definition, $candidate->ID)) {
                $current_id = eventosapp_installation_get_mapped_page_id($definition);
                if ((int) $current_id !== (int) $candidate->ID) {
                    return new WP_Error('evapp_install_map_existing_failed', 'Se encontró una página válida, pero no fue posible mapearla.');
                }
            }

            /*
             * Si la página ya existía, preservamos completamente su jerarquía y URL.
             * La dependencia de parent solo se aplica a páginas nuevas.
             */
            update_option('eventosapp_needs_flush', 1);

            return [
                'ok'      => true,
                'page_id' => absint($candidate->ID),
                'action'  => 'mapped_existing',
                'changed' => true,
                'message' => 'Se encontró una página que ya contenía el shortcode y se mapeó sin duplicarla.',
            ];
        }

        $post_data = [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'post_title'     => sanitize_text_field($definition['title'] ?? $definition['label'] ?? 'EventosApp'),
            'post_name'      => sanitize_title($definition['slug'] ?? ''),
            'post_content'   => $structural ? '' : (string) ($definition['content'] ?? ''),
            'post_parent'    => absint($parent_id),
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ];

        $new_page_id = wp_insert_post($post_data, true);
        if (is_wp_error($new_page_id) || !$new_page_id) {
            return is_wp_error($new_page_id)
                ? $new_page_id
                : new WP_Error('evapp_install_create_failed', 'WordPress no pudo crear la página.');
        }

        if (!eventosapp_installation_set_mapped_page_id($definition, $new_page_id)) {
            $current_id = eventosapp_installation_get_mapped_page_id($definition);
            if ((int) $current_id !== (int) $new_page_id) {
                wp_delete_post($new_page_id, true);
                return new WP_Error('evapp_install_map_new_failed', 'La página se creó, pero no pudo mapearse. Se revirtió la creación para evitar una página huérfana.');
            }
        }

        eventosapp_installation_candidate_pages(true);
        update_option('eventosapp_needs_flush', 1);

        return [
            'ok'      => true,
            'page_id' => absint($new_page_id),
            'action'  => 'created',
            'changed' => true,
            'message' => 'Página creada, publicada, shortcode insertado y mapeo guardado automáticamente.',
        ];
    }
}

if ( ! function_exists('eventosapp_installation_notice_transient_key') ) {
    function eventosapp_installation_notice_transient_key($user_id, $token) {
        return 'evapp_install_notice_' . absint($user_id) . '_' . sanitize_key((string) $token);
    }
}

if ( ! function_exists('eventosapp_installation_store_notice') ) {
    function eventosapp_installation_store_notice($type, $message) {
        $token = strtolower(wp_generate_password(14, false, false));
        $key   = eventosapp_installation_notice_transient_key(get_current_user_id(), $token);
        set_transient($key, [
            'type'    => sanitize_key((string) $type),
            'message' => sanitize_text_field((string) $message),
        ], 10 * MINUTE_IN_SECONDS);
        return $token;
    }
}

if ( ! function_exists('eventosapp_installation_pull_notice') ) {
    function eventosapp_installation_pull_notice() {
        if (empty($_GET['evapp_install_notice'])) {
            return [];
        }

        $token = sanitize_key(wp_unslash($_GET['evapp_install_notice']));
        if ($token === '') {
            return [];
        }

        $key    = eventosapp_installation_notice_transient_key(get_current_user_id(), $token);
        $notice = get_transient($key);
        delete_transient($key);

        return is_array($notice) ? $notice : [];
    }
}

if ( ! function_exists('eventosapp_installation_admin_url') ) {
    function eventosapp_installation_admin_url(array $args = []) {
        return add_query_arg(
            array_merge(['page' => 'eventosapp_configuracion'], $args),
            admin_url('admin.php')
        );
    }
}

if ( ! function_exists('eventosapp_installation_redirect_with_notice') ) {
    function eventosapp_installation_redirect_with_notice($type, $message) {
        $token = eventosapp_installation_store_notice($type, $message);
        $url   = eventosapp_installation_admin_url(['evapp_install_notice' => $token]);
        wp_safe_redirect($url . '#eventosapp-installation');
        exit;
    }
}

add_action('admin_post_eventosapp_install_single_page', 'eventosapp_handle_install_single_page');
if ( ! function_exists('eventosapp_handle_install_single_page') ) {
    function eventosapp_handle_install_single_page() {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para instalar páginas de EventosApp.', '', ['response' => 403]);
        }

        $definition_id = isset($_POST['definition_id'])
            ? sanitize_key(wp_unslash($_POST['definition_id']))
            : '';

        check_admin_referer('eventosapp_install_single_page_' . $definition_id, 'eventosapp_install_single_page_nonce');

        $registry = eventosapp_installation_page_registry();
        if ($definition_id === '' || empty($registry[$definition_id]) || !is_array($registry[$definition_id])) {
            eventosapp_installation_redirect_with_notice('error', 'La página solicitada no existe en el inventario de EventosApp.');
        }

        $result = eventosapp_installation_install_definition($definition_id, $registry[$definition_id]);

        if (is_wp_error($result)) {
            eventosapp_installation_redirect_with_notice('error', implode(' ', $result->get_error_messages()));
        }

        $page_id = absint($result['page_id'] ?? 0);
        $label   = sanitize_text_field($registry[$definition_id]['label'] ?? $definition_id);
        $message = $page_id
            ? $label . ': ' . sanitize_text_field($result['message'] ?? 'Instalación completada.') . ' Página #' . $page_id . '.'
            : $label . ': instalación completada.';

        eventosapp_installation_redirect_with_notice('success', $message);
    }
}

if ( ! function_exists('eventosapp_installation_register_task_queue_adapter') ) {
    /**
     * Registra el instalador de páginas como adaptador de la cola central de
     * EventosApp. La cola ya dispone de loopback REST + respaldo WP-Cron, por
     * lo que la instalación continúa aunque el administrador cierre la página.
     */
    function eventosapp_installation_register_task_queue_adapter() {
        if ( ! function_exists('eventosapp_task_queue_register_adapter') ) {
            return false;
        }

        return eventosapp_task_queue_register_adapter('eventosapp_install_pages', [
            'label'          => 'Instalación de páginas de EventosApp',
            'channel'        => 'system',
            'group'          => 'massive',
            'batch_size'     => 4,
            'min_batch_size' => 1,
            'max_batch_size' => 8,
            'process_batch'  => 'eventosapp_installation_task_queue_process_batch',
        ]);
    }
}

if ( ! function_exists('eventosapp_installation_task_queue_process_batch') ) {
    /**
     * Procesa un lote del inventario de instalación.
     *
     * El cursor de la cola representa la posición dentro de definition_ids.
     * Los errores de una página se registran y el proceso continúa con las
     * demás para que una dependencia opcional no bloquee una instalación nueva.
     */
    function eventosapp_installation_task_queue_process_batch($task, $context = []) {
        $task      = is_array($task) ? $task : [];
        $context   = is_array($context) ? $context : [];
        $registry  = eventosapp_installation_page_registry();
        $payload   = isset($task['payload']) && is_array($task['payload']) ? $task['payload'] : [];
        $ids       = isset($payload['definition_ids']) && is_array($payload['definition_ids'])
            ? array_values(array_filter(array_map('sanitize_key', $payload['definition_ids'])))
            : array_keys($registry);

        // El inventario puede evolucionar entre la creación y la ejecución de la tarea.
        $ids = array_values(array_filter($ids, static function($definition_id) use ($registry) {
            return isset($registry[$definition_id]) && is_array($registry[$definition_id]);
        }));

        $total      = count($ids);
        $cursor     = min($total, max(0, absint($task['cursor_value'] ?? 0)));
        $batch_size = max(1, absint($context['batch_size'] ?? 4));
        $processed  = 0;
        $success    = 0;
        $errors     = 0;
        $logs       = [];

        while ($cursor < $total && $processed < $batch_size) {
            $definition_id = $ids[$cursor];
            $definition    = $registry[$definition_id];
            $label         = sanitize_text_field($definition['label'] ?? $definition_id);
            $result        = eventosapp_installation_install_definition($definition_id, $definition);

            if (is_wp_error($result)) {
                $errors++;
                $logs[] = [
                    'level'   => 'error',
                    'message' => $label . ': ' . implode(' ', $result->get_error_messages()),
                    'context' => ['definition_id' => $definition_id],
                ];
            } else {
                $success++;
                $logs[] = [
                    'level'   => 'success',
                    'message' => $label . ': ' . sanitize_text_field($result['message'] ?? 'Instalación verificada.'),
                    'context' => [
                        'definition_id' => $definition_id,
                        'page_id'       => absint($result['page_id'] ?? 0),
                        'action'        => sanitize_key($result['action'] ?? ''),
                    ],
                ];
            }

            $processed++;
            $cursor++;

            if (isset($context['should_yield']) && is_callable($context['should_yield'])) {
                $started_at = isset($context['started_at']) ? (float) $context['started_at'] : microtime(true);
                if (call_user_func($context['should_yield'], $started_at, $processed)) {
                    break;
                }
            }
        }

        $done = $cursor >= $total;
        if ($done) {
            // La página pública del sorteo añade una regla dinámica y el módulo
            // principal consume esta bandera en el siguiente init real.
            update_option('eventosapp_needs_flush', 1);
        }

        return [
            'processed'     => $processed,
            'success'       => $success,
            'errors'        => $errors,
            'skipped'       => 0,
            'next_cursor'   => $cursor,
            'total_items'   => $total,
            'done'          => $done,
            'fatal'         => false,
            'error_message' => '',
            'logs'          => $logs,
        ];
    }
}

// La cola central se carga antes de este archivo desde eventosapp.php. Registrar
// aquí asegura que el adaptador exista tanto en peticiones admin como en workers
// REST/cron independientes.
eventosapp_installation_register_task_queue_adapter();

if ( ! function_exists('eventosapp_installation_get_background_job') ) {
    /**
     * Normaliza la tarea central a la estructura pequeña que consume esta UI.
     */
    function eventosapp_installation_get_background_job() {
        if ( ! function_exists('eventosapp_task_queue_get') ) {
            return [];
        }

        $task_id = absint(get_option('eventosapp_installation_task_id', 0));
        if (!$task_id) {
            return [];
        }

        $task = eventosapp_task_queue_get($task_id);
        if (!is_array($task)) {
            return [];
        }

        $payload = isset($task['payload']) && is_array($task['payload']) ? $task['payload'] : [];
        $ids     = isset($payload['definition_ids']) && is_array($payload['definition_ids'])
            ? array_values(array_filter(array_map('sanitize_key', $payload['definition_ids'])))
            : array_keys(eventosapp_installation_page_registry());
        $cursor  = max(0, absint($task['cursor_value'] ?? 0));
        $status  = sanitize_key($task['status'] ?? '');

        // La cola considera "completed" una tarea que recorrió todo el lote aun
        // cuando una página concreta produjo error. Para esta pantalla lo
        // expresamos como "completed_with_errors" sin alterar el estado central.
        $ui_status = ($status === 'completed' && absint($task['error_items'] ?? 0) > 0)
            ? 'completed_with_errors'
            : $status;

        $to_timestamp = static function($value) {
            if (!is_string($value) || trim($value) === '') {
                return 0;
            }
            $timestamp = strtotime($value . ' UTC');
            return $timestamp ? absint($timestamp) : 0;
        };

        return [
            'id'                => absint($task['id'] ?? 0),
            'uuid'              => sanitize_text_field($task['uuid'] ?? ''),
            'status'            => $ui_status,
            'queue_status'      => $status,
            'created_at'        => $to_timestamp($task['created_at'] ?? ''),
            'started_at'        => $to_timestamp($task['started_at'] ?? ''),
            'finished_at'       => $to_timestamp($task['completed_at'] ?? ''),
            'updated_at'        => $to_timestamp($task['updated_at'] ?? ''),
            'requested_by'      => absint($task['created_by'] ?? 0),
            'total'             => absint($task['total_items'] ?? count($ids)),
            'processed'         => absint($task['processed_items'] ?? 0),
            'success'           => absint($task['success_items'] ?? 0),
            'errors'            => absint($task['error_items'] ?? 0),
            'skipped'           => absint($task['skipped_items'] ?? 0),
            'current'           => isset($ids[$cursor]) ? sanitize_key($ids[$cursor]) : '',
            'last_error'        => sanitize_text_field($task['last_error'] ?? ''),
            'queue_task_url'    => add_query_arg(
                ['page' => 'eventosapp_task_queue', 'task_id' => absint($task['id'] ?? 0)],
                admin_url('admin.php')
            ),
        ];
    }
}

if ( ! function_exists('eventosapp_installation_job_is_active') ) {
    function eventosapp_installation_job_is_active(array $job) {
        $status = sanitize_key($job['queue_status'] ?? $job['status'] ?? '');
        return in_array($status, ['queued', 'scheduled', 'running', 'paused'], true);
    }
}

if ( ! function_exists('eventosapp_installation_schedule_background_job') ) {
    function eventosapp_installation_schedule_background_job() {
        $current = eventosapp_installation_get_background_job();
        if (eventosapp_installation_job_is_active($current)) {
            return new WP_Error('evapp_install_job_active', 'Ya existe una instalación automática en curso o pausada.');
        }

        if (
            ! function_exists('eventosapp_task_queue_register_adapter') ||
            ! function_exists('eventosapp_task_queue_create') ||
            ! function_exists('eventosapp_task_queue_get')
        ) {
            return new WP_Error(
                'evapp_install_queue_missing',
                'La cola central de EventosApp no está disponible. Revisa includes/functions/eventosapp-task-queue-core.php antes de iniciar una instalación masiva.'
            );
        }

        eventosapp_installation_register_task_queue_adapter();
        if (function_exists('eventosapp_task_queue_maybe_install')) {
            eventosapp_task_queue_maybe_install();
        }

        $registry = eventosapp_installation_page_registry();
        $task_id  = eventosapp_task_queue_create([
            'task_type'   => 'eventosapp_install_pages',
            'task_group'  => 'massive',
            'channel'     => 'system',
            'title'       => 'Instalación de páginas de EventosApp',
            'status'      => 'queued',
            'priority'    => 40,
            'total_items' => count($registry),
            'batch_size'  => 4,
            'payload'     => [
                'definition_ids' => array_keys($registry),
                'source'         => 'eventosapp_configuracion',
                'requested_at'   => current_time('mysql', true),
            ],
            'created_by'  => get_current_user_id(),
        ]);

        if (is_wp_error($task_id)) {
            return $task_id;
        }

        update_option('eventosapp_installation_task_id', absint($task_id), false);
        return eventosapp_installation_get_background_job();
    }
}

add_action('admin_post_eventosapp_install_all_pages', 'eventosapp_handle_install_all_pages');
if ( ! function_exists('eventosapp_handle_install_all_pages') ) {
    function eventosapp_handle_install_all_pages() {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para instalar páginas de EventosApp.', '', ['response' => 403]);
        }

        check_admin_referer('eventosapp_install_all_pages', 'eventosapp_install_all_pages_nonce');

        $job = eventosapp_installation_schedule_background_job();
        if (is_wp_error($job)) {
            eventosapp_installation_redirect_with_notice('error', implode(' ', $job->get_error_messages()));
        }

        eventosapp_installation_redirect_with_notice(
            'success',
            'La instalación automática fue enviada a la Cola y Tareas de EventosApp. Puedes cerrar esta pantalla; el worker continuará el proceso en segundo plano.'
        );
    }
}

add_action('wp_ajax_eventosapp_installation_job_status', 'eventosapp_ajax_installation_job_status');
if ( ! function_exists('eventosapp_ajax_installation_job_status') ) {
    function eventosapp_ajax_installation_job_status() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No autorizado.'], 403);
        }

        check_ajax_referer('eventosapp_installation_job_status', 'nonce');

        $job     = eventosapp_installation_get_background_job();
        $summary = eventosapp_installation_health_summary();

        wp_send_json_success([
            'job' => [
                'id'          => absint($job['id'] ?? 0),
                'status'      => sanitize_key($job['status'] ?? ''),
                'queue_status'=> sanitize_key($job['queue_status'] ?? ''),
                'total'       => absint($job['total'] ?? 0),
                'processed'   => absint($job['processed'] ?? 0),
                'success'     => absint($job['success'] ?? 0),
                'errors'      => absint($job['errors'] ?? 0),
                'skipped'     => absint($job['skipped'] ?? 0),
                'current'     => sanitize_key($job['current'] ?? ''),
                'updated_at'  => absint($job['updated_at'] ?? 0),
                'finished_at' => absint($job['finished_at'] ?? 0),
                'task_url'    => esc_url_raw($job['queue_task_url'] ?? ''),
            ],
            'summary' => array_map('absint', $summary),
        ]);
    }
}

if ( ! function_exists('eventosapp_render_installation_notice') ) {
    function eventosapp_render_installation_notice(array $notice) {
        if (empty($notice['message'])) {
            return;
        }

        $type = sanitize_key($notice['type'] ?? 'info');
        $class = $type === 'success'
            ? 'notice-success'
            : ($type === 'error' ? 'notice-error' : 'notice-info');

        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible" style="margin:16px 0 20px;"><p>'
           . esc_html($notice['message'])
           . '</p></div>';
    }
}

if ( ! function_exists('eventosapp_render_installation_section') ) {
    function eventosapp_render_installation_section() {
        $registry = eventosapp_installation_page_registry();
        $summary  = eventosapp_installation_health_summary();
        $job      = eventosapp_installation_get_background_job();
        $active   = eventosapp_installation_job_is_active($job);
        $job_status = sanitize_key($job['status'] ?? '');
        $job_total = max(1, absint($job['total'] ?? count($registry)));
        $job_processed = absint($job['processed'] ?? 0);
        $job_percent = min(100, max(0, (int) round(($job_processed / $job_total) * 100)));
        $status_nonce = wp_create_nonce('eventosapp_installation_job_status');

        $state_labels = [
            'ok'                       => ['Correcto', 'ok'],
            'needs_mapping'            => ['Página detectada · falta mapear', 'warning'],
            'mapped_without_shortcode' => ['Mapeada · falta shortcode', 'warning'],
            'missing'                  => ['Falta instalar', 'missing'],
            'shortcode_unavailable'    => ['Shortcode no disponible', 'error'],
        ];
        ?>
        <style>
            .evapp-install-shell{max-width:1380px;margin:18px 0 30px;padding:24px;background:#fff;border:1px solid #dcdcde;border-radius:16px;box-shadow:0 8px 24px rgba(31,35,40,.06)}
            .evapp-install-head{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:18px}
            .evapp-install-head h2{margin:0 0 6px;font-size:22px;line-height:1.25}
            .evapp-install-head p{max-width:850px;margin:0;color:#646970;line-height:1.55}
            .evapp-install-actions{display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:10px}
            .evapp-install-actions form{margin:0}
            .evapp-install-actions .button{min-height:36px;display:inline-flex;align-items:center;justify-content:center}
            .evapp-install-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 18px}
            .evapp-install-summary-card{min-width:0;padding:15px 16px;background:#f6f7f7;border:1px solid #e2e4e7;border-radius:12px}
            .evapp-install-summary-card strong{display:block;margin-bottom:3px;font-size:24px;line-height:1.1;color:#1d2327}
            .evapp-install-summary-card span{color:#646970;font-size:12px;font-weight:600}
            .evapp-install-summary-card.is-ok{background:#edfaef;border-color:#b8dfc1}
            .evapp-install-summary-card.is-warning{background:#fff8e5;border-color:#f0d58c}
            .evapp-install-summary-card.is-error{background:#fcf0f1;border-color:#efb7ba}
            .evapp-install-job{margin:0 0 18px;padding:15px 16px;background:#f0f6fc;border:1px solid #c5d9ed;border-radius:12px}
            .evapp-install-job-row{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
            .evapp-install-job-title{font-weight:700;color:#1d2327}
            .evapp-install-job-meta{color:#50575e;font-size:12px}
            .evapp-install-progress{height:10px;margin-top:10px;overflow:hidden;background:#dce6f0;border-radius:999px}
            .evapp-install-progress-bar{height:100%;width:0;background:#2271b1;border-radius:999px;transition:width .25s ease}
            .evapp-install-table-wrap{width:100%;overflow:auto;border:1px solid #e2e4e7;border-radius:12px}
            .evapp-install-table{width:100%;min-width:1060px;border-collapse:collapse;background:#fff}
            .evapp-install-table th,.evapp-install-table td{padding:12px 13px;border-bottom:1px solid #eceef0;vertical-align:middle;text-align:left}
            .evapp-install-table th{position:sticky;top:0;z-index:1;background:#f6f7f7;color:#1d2327;font-size:12px;font-weight:700}
            .evapp-install-table tr:last-child td{border-bottom:0}
            .evapp-install-table td.evapp-install-page{min-width:250px}
            .evapp-install-page strong{display:block;margin-bottom:3px}
            .evapp-install-page small{display:block;color:#646970;line-height:1.4}
            .evapp-install-group{display:inline-block;padding:3px 7px;background:#f0f0f1;border-radius:999px;color:#50575e;font-size:11px;font-weight:700;white-space:nowrap}
            .evapp-install-code{white-space:nowrap}
            .evapp-install-badge{display:inline-flex;align-items:center;gap:5px;padding:5px 8px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
            .evapp-install-badge.ok{background:#edfaef;color:#116329}
            .evapp-install-badge.warning{background:#fff8e5;color:#8a4b00}
            .evapp-install-badge.missing{background:#f0f6fc;color:#135e96}
            .evapp-install-badge.error{background:#fcf0f1;color:#8a2424}
            .evapp-install-links{display:flex;align-items:center;flex-wrap:wrap;gap:6px;margin-top:4px;font-size:12px}
            .evapp-install-links a{text-decoration:none}
            .evapp-install-action form{margin:0}
            .evapp-install-tech{margin-top:16px}
            .evapp-install-tech summary{cursor:pointer;font-weight:700}
            .evapp-install-tech ul{columns:2;column-gap:28px;margin:12px 0 0;padding-left:20px}
            .evapp-install-tech li{break-inside:avoid;margin-bottom:5px}
            @media(max-width:1100px){
                .evapp-install-head{display:block}
                .evapp-install-actions{justify-content:flex-start;margin-top:14px}
                .evapp-install-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
            }
            @media(max-width:782px){
                .evapp-install-shell{padding:16px;margin-right:10px;border-radius:12px}
                .evapp-install-summary{grid-template-columns:1fr 1fr}
                .evapp-install-actions,.evapp-install-actions form,.evapp-install-actions .button{width:100%}
                .evapp-install-tech ul{columns:1}
            }
            @media(max-width:480px){
                .evapp-install-summary{grid-template-columns:1fr}
            }
        </style>

        <section id="eventosapp-installation" class="evapp-install-shell">
            <div class="evapp-install-head">
                <div>
                    <h2>Estado de instalación de EventosApp</h2>
                    <p>
                        Valida que el código del shortcode esté cargado, que exista una página que lo contenga y que esa página esté mapeada.
                        El instalador reutiliza páginas válidas cuando las encuentra, repara el shortcode faltante sin borrar contenido y crea únicamente lo que no existe.
                    </p>
                </div>
                <div class="evapp-install-actions">
                    <a class="button" href="<?php echo esc_url(eventosapp_installation_admin_url()); ?>#eventosapp-installation">Revalidar estado</a>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="eventosapp_install_all_pages">
                        <?php wp_nonce_field('eventosapp_install_all_pages', 'eventosapp_install_all_pages_nonce'); ?>
                        <button type="submit" class="button button-primary" <?php disabled($active); ?>>
                            <?php echo $active ? 'Instalación en curso…' : 'Instalar / reparar todas en segundo plano'; ?>
                        </button>
                    </form>
                </div>
            </div>

            <div class="evapp-install-summary">
                <div class="evapp-install-summary-card">
                    <strong data-evapp-summary="total"><?php echo absint($summary['total']); ?></strong>
                    <span>Páginas / estructuras controladas</span>
                </div>
                <div class="evapp-install-summary-card is-ok">
                    <strong data-evapp-summary="healthy"><?php echo absint($summary['healthy']); ?></strong>
                    <span>Correctamente instaladas</span>
                </div>
                <div class="evapp-install-summary-card is-warning">
                    <strong data-evapp-summary="attention"><?php echo absint($summary['attention']); ?></strong>
                    <span>Requieren instalación o reparación</span>
                </div>
                <div class="evapp-install-summary-card is-error">
                    <strong data-evapp-summary="unavailable"><?php echo absint($summary['unavailable']); ?></strong>
                    <span>Shortcodes cuyo módulo no está cargado</span>
                </div>
            </div>

            <?php if ($active || in_array($job_status, ['completed', 'completed_with_errors', 'failed', 'cancelled', 'expired', 'archived'], true)): ?>
                <div class="evapp-install-job" id="evapp-install-job"
                     data-active="<?php echo $active ? '1' : '0'; ?>"
                     data-nonce="<?php echo esc_attr($status_nonce); ?>">
                    <div class="evapp-install-job-row">
                        <div>
                            <span class="evapp-install-job-title" id="evapp-install-job-title">
                                <?php
                                if ($active) {
                                    echo 'Instalación automática en segundo plano';
                                } elseif ($job_status === 'completed') {
                                    echo 'Última instalación automática completada';
                                } elseif ($job_status === 'completed_with_errors') {
                                    echo 'Última instalación completada con observaciones';
                                } elseif ($job_status === 'cancelled') {
                                    echo 'La última instalación automática fue cancelada';
                                } elseif ($job_status === 'archived') {
                                    echo 'La última instalación automática fue archivada';
                                } elseif ($job_status === 'expired') {
                                    echo 'La última instalación automática quedó vencida';
                                } else {
                                    echo 'La última instalación automática no pudo completarse';
                                }
                                ?>
                            </span>
                            <div class="evapp-install-job-meta" id="evapp-install-job-meta">
                                Procesadas <?php echo absint($job_processed); ?> de <?php echo absint($job['total'] ?? count($registry)); ?>
                                · Correctas <?php echo absint($job['success'] ?? 0); ?>
                                · Errores <?php echo absint($job['errors'] ?? 0); ?>
                                <?php if (!empty($job['queue_task_url'])): ?>
                                    · <a href="<?php echo esc_url($job['queue_task_url']); ?>">Abrir en Cola y Tareas</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php
                        $job_badge_warning = in_array($job_status, ['failed', 'completed_with_errors', 'cancelled', 'expired'], true);
                        $job_badge_label = $active
                            ? ucfirst($job_status ?: 'queued')
                            : ($job_status === 'completed'
                                ? 'Completado'
                                : ($job_status === 'completed_with_errors'
                                    ? 'Con observaciones'
                                    : ($job_status === 'cancelled'
                                        ? 'Cancelado'
                                        : ($job_status === 'archived'
                                            ? 'Archivado'
                                            : ($job_status === 'expired' ? 'Vencido' : 'Fallido')))));
                        ?>
                        <span class="evapp-install-badge <?php echo $job_badge_warning ? 'warning' : 'ok'; ?>" id="evapp-install-job-badge">
                            <?php echo esc_html($job_badge_label); ?>
                        </span>
                    </div>
                    <div class="evapp-install-progress" aria-hidden="true">
                        <div class="evapp-install-progress-bar" id="evapp-install-progress-bar" style="width:<?php echo absint($job_percent); ?>%"></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="evapp-install-table-wrap">
                <table class="evapp-install-table">
                    <thead>
                        <tr>
                            <th>Página / módulo</th>
                            <th>Grupo</th>
                            <th>Shortcode</th>
                            <th>Código</th>
                            <th>Página</th>
                            <th>Estado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registry as $definition_id => $definition): ?>
                            <?php
                            $status = eventosapp_installation_definition_status($definition_id, $definition);
                            $state_data = $state_labels[$status['state']] ?? ['Revisar', 'warning'];
                            $display_page = $status['mapped_page'] instanceof WP_Post
                                ? $status['mapped_page']
                                : ($status['candidate_page'] instanceof WP_Post ? $status['candidate_page'] : null);
                            $shortcode_label = $status['structural']
                                ? 'Contenedor estructural'
                                : '[' . $status['shortcode'] . ']';
                            ?>
                            <tr>
                                <td class="evapp-install-page">
                                    <strong><?php echo esc_html($definition['label'] ?? $definition_id); ?></strong>
                                    <small><?php echo esc_html($definition['description'] ?? ''); ?></small>
                                </td>
                                <td><span class="evapp-install-group"><?php echo esc_html($definition['group'] ?? 'General'); ?></span></td>
                                <td class="evapp-install-code"><code><?php echo esc_html($shortcode_label); ?></code></td>
                                <td>
                                    <?php if ($status['shortcode_registered']): ?>
                                        <span class="evapp-install-badge ok"><?php echo $status['structural'] ? 'Estructura' : 'Registrado'; ?></span>
                                    <?php else: ?>
                                        <span class="evapp-install-badge error">No registrado</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($display_page instanceof WP_Post): ?>
                                        <strong><?php echo esc_html($display_page->post_title ?: '(Sin título)'); ?></strong>
                                        <div class="evapp-install-links">
                                            <span>#<?php echo absint($display_page->ID); ?></span>
                                            <?php if (get_permalink($display_page->ID)): ?>
                                                <a href="<?php echo esc_url(get_permalink($display_page->ID)); ?>" target="_blank" rel="noopener">Ver</a>
                                            <?php endif; ?>
                                            <?php if (get_edit_post_link($display_page->ID)): ?>
                                                <a href="<?php echo esc_url(get_edit_post_link($display_page->ID)); ?>">Editar</a>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span>—</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="evapp-install-badge <?php echo esc_attr($state_data[1]); ?>"><?php echo esc_html($state_data[0]); ?></span></td>
                                <td class="evapp-install-action">
                                    <?php if (!$status['healthy'] && $status['shortcode_registered']): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <input type="hidden" name="action" value="eventosapp_install_single_page">
                                            <input type="hidden" name="definition_id" value="<?php echo esc_attr($definition_id); ?>">
                                            <?php wp_nonce_field('eventosapp_install_single_page_' . $definition_id, 'eventosapp_install_single_page_nonce'); ?>
                                            <button type="submit" class="button button-secondary">
                                                <?php echo $status['state'] === 'needs_mapping' ? 'Mapear automáticamente' : 'Instalar / reparar'; ?>
                                            </button>
                                        </form>
                                    <?php elseif ($status['healthy']): ?>
                                        <span class="evapp-install-badge ok">Sin acción</span>
                                    <?php else: ?>
                                        <span class="description">Revisa el archivo del módulo.</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <details class="evapp-install-tech">
                <summary>Ver inventario técnico de shortcodes</summary>
                <ul>
                    <?php foreach ($registry as $definition): ?>
                        <?php if (!empty($definition['shortcode'])): ?>
                            <li><code>[<?php echo esc_html($definition['shortcode']); ?>]</code> — <?php echo esc_html($definition['label'] ?? 'EventosApp'); ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </details>
        </section>

        <?php if ($active): ?>
            <script>
            (function(){
                var box = document.getElementById('evapp-install-job');
                if (!box || box.getAttribute('data-active') !== '1') return;

                var nonce = box.getAttribute('data-nonce') || '';
                var progress = document.getElementById('evapp-install-progress-bar');
                var meta = document.getElementById('evapp-install-job-meta');
                var badge = document.getElementById('evapp-install-job-badge');
                var stopped = false;

                function setSummary(summary) {
                    if (!summary) return;
                    ['total','healthy','attention','unavailable'].forEach(function(key){
                        var el = document.querySelector('[data-evapp-summary="' + key + '"]');
                        if (el && typeof summary[key] !== 'undefined') {
                            el.textContent = summary[key];
                        }
                    });
                }

                function poll(){
                    if (stopped) return;

                    var body = new URLSearchParams();
                    body.append('action', 'eventosapp_installation_job_status');
                    body.append('nonce', nonce);

                    fetch(ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: body.toString()
                    })
                    .then(function(response){ return response.json(); })
                    .then(function(payload){
                        if (!payload || !payload.success || !payload.data) return;

                        var job = payload.data.job || {};
                        var total = Math.max(1, parseInt(job.total || 0, 10));
                        var processed = parseInt(job.processed || 0, 10);
                        var success = parseInt(job.success || 0, 10);
                        var errors = parseInt(job.errors || 0, 10);
                        var percent = Math.max(0, Math.min(100, Math.round((processed / total) * 100)));

                        if (progress) progress.style.width = percent + '%';
                        if (meta) meta.textContent = 'Procesadas ' + processed + ' de ' + total + ' · Correctas ' + success + ' · Errores ' + errors;
                        if (badge) badge.textContent = job.status || 'running';
                        setSummary(payload.data.summary);

                        if (['completed','completed_with_errors','failed','cancelled','expired','archived'].indexOf(job.status) !== -1) {
                            stopped = true;
                            window.setTimeout(function(){ window.location.reload(); }, 900);
                        }
                    })
                    .catch(function(){});
                }

                poll();
                window.setInterval(poll, 2500);
            })();
            </script>
        <?php endif;
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
    $bulk_users_result  = eventosapp_bulk_users_pull_result();
    $installation_notice = eventosapp_installation_pull_notice();
    ?>
<div class="wrap">
        <h1>Configuración de EventosApp</h1>
        <?php eventosapp_render_installation_notice($installation_notice); ?>
        <?php eventosapp_render_bulk_users_result($bulk_users_result); ?>
        <?php eventosapp_render_installation_section(); ?>

        <form method="post" action="options.php">
            <?php
            settings_fields('eventosapp_pages_group');
            do_settings_sections('eventosapp_configuracion');
            submit_button();
            ?>
        </form>

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
        'consumables_manage' => 'Control de Consumibles',
        'consumables_staff'  => 'Consumo de Consumibles',
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

/**
 * Registro completo y compatible de funcionalidades del dashboard.
 *
 * Algunas instalaciones pueden tener una definición previa de
 * eventosapp_dashboard_features() cargada por una versión antigua o por un snippet.
 * Este helper no intenta redeclararla: toma lo que exista, incorpora siempre los
 * módulos de Consumibles y conserva un orden operativo coherente.
 *
 * @return array<string,string>
 */
if ( ! function_exists('eventosapp_dashboard_features_complete') ) {
    function eventosapp_dashboard_features_complete() {
        $base = function_exists('eventosapp_dashboard_features')
            ? eventosapp_dashboard_features()
            : [];

        if ( ! is_array($base) ) {
            $base = [];
        }

        unset($base['consumables_manage'], $base['consumables_staff']);

        $complete = [];
        $inserted = false;
        foreach ($base as $key => $label) {
            $complete[$key] = $label;

            if ($key === 'qr_sesion') {
                $complete['consumables_manage'] = 'Control de Consumibles';
                $complete['consumables_staff']  = 'Consumo de Consumibles';
                $inserted = true;
            }
        }

        if (!$inserted) {
            $complete['consumables_manage'] = 'Control de Consumibles';
            $complete['consumables_staff']  = 'Consumo de Consumibles';
        }

        return (array) apply_filters('eventosapp_dashboard_features_complete', $complete);
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

/**
 * Política base obligatoria para los módulos de consumibles.
 *
 * Esta política mantiene los módulos disponibles de forma coherente aunque la
 * opción de visibilidad se haya guardado antes de que existieran estas features:
 * - Administrador y Organizador: configuración + consumo.
 * - Staff y Logístico: solamente consumo.
 *
 * La excepción individual se resuelve posteriormente desde el metabox
 * "Control de Acceso Dashboard Staff", que trabaja por usuario y evento.
 *
 * @param string $role    Slug del rol.
 * @param string $feature Feature del dashboard.
 * @return int|null 1/0 cuando existe una regla fija; null en los demás casos.
 */
if ( ! function_exists('eventosapp_consumables_role_policy') ) {
    function eventosapp_consumables_role_policy($role, $feature) {
        $role    = sanitize_key((string) $role);
        $feature = sanitize_key((string) $feature);

        if ( ! in_array($feature, ['consumables_manage', 'consumables_staff'], true) ) {
            return null;
        }

        if ( in_array($role, ['administrator', 'organizador'], true) ) {
            return 1;
        }

        if ( in_array($role, ['staff', 'logistico'], true) ) {
            return $feature === 'consumables_staff' ? 1 : 0;
        }

        return null;
    }
}


// 3) Defaults sensatos (admin/organizador todo ON; staff/logistico limitado; resto OFF)
if ( ! function_exists('eventosapp_default_dashboard_visibility') ) {
function eventosapp_default_dashboard_visibility() {
    $roles    = eventosapp_get_all_roles();
    $features = array_keys( eventosapp_dashboard_features_complete() );
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
        $defaults['staff']['qr_sesion']          = 1;
        $defaults['staff']['consumables_staff']   = 1;
        // checklist: OFF por defecto para staff
        // networking_ranking: OFF por defecto para staff (ajústalo si lo deseas ON)
    }

    // Logístico
    if (isset($defaults['logistico'])) {
        $defaults['logistico']['dashboard']     = 1;
        $defaults['logistico']['self_checkin']  = 1;
        $defaults['logistico']['qr']            = 1;
        $defaults['logistico']['qr_localidad']  = 1;
        $defaults['logistico']['qr_sesion']          = 1;
        $defaults['logistico']['consumables_staff']   = 1;
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

        // Asegura que existan todos los roles/features y aplica la política
        // obligatoria de consumibles, incluso en opciones guardadas antiguas.
        foreach ($defaults as $role => $map) {
            if (!isset($saved[$role]) || !is_array($saved[$role])) $saved[$role] = [];
            foreach ($map as $feat => $on) {
                if (!isset($saved[$role][$feat])) $saved[$role][$feat] = $on;
                $saved[$role][$feat] = (int) !!$saved[$role][$feat];

                $fixed_policy = eventosapp_consumables_role_policy($role, $feat);
                if ($fixed_policy !== null) {
                    $saved[$role][$feat] = (int) $fixed_policy;
                }
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
        $features = eventosapp_dashboard_features_complete();
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
            $features = array_keys( eventosapp_dashboard_features_complete() );
            $out = [];

            if (!is_array($input)) $input = [];

            foreach ($roles as $r => $rlabel) {
                $out[$r] = [];
                foreach ($features as $f) {
                    $val = isset($input[$r][$f]) ? (int) !!$input[$r][$f] : 0;
                    $fixed_policy = eventosapp_consumables_role_policy($r, $f);
                    $out[$r][$f] = $fixed_policy !== null ? (int) $fixed_policy : $val;
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
            $features = eventosapp_dashboard_features_complete();
            $cfg      = eventosapp_get_dashboard_visibility();

            echo '<style>
                .evapp-perm-table-wrap { overflow-x:auto; width:100%; border:1px solid #dcdcde; }
                .evapp-perm-table { border-collapse: collapse; margin:0; min-width:1900px; }
                .evapp-perm-table th, .evapp-perm-table td { border:1px solid #ddd; padding:6px 8px; text-align:center; }
                .evapp-perm-table th { background:#f6f7f7; }
                .evapp-perm-table td.role { text-align:left; font-weight:600; background:#fafafa; }
            </style>';

            echo '<div class="evapp-perm-table-wrap"><table class="evapp-perm-table">';
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
                    $fixed_policy = eventosapp_consumables_role_policy($slug, $featKey);

                    if ($fixed_policy !== null) {
                        echo '<td title="Regla base de Consumibles. La excepción se configura por usuario y evento en Control de Acceso Dashboard Staff.">';
                        echo '<input type="hidden" name="'.$name.'" value="'.(int)$fixed_policy.'">';
                        echo '<input type="checkbox" value="1" '.checked(1, (int)$fixed_policy, false).' disabled>';
                        echo '</td>';
                    } else {
                        echo '<td><input type="checkbox" name="'.$name.'" value="1" '.$checked.'></td>';
                    }
                }
                echo '</tr>';
            }
            echo '</tbody></table></div>';

            echo '<p class="description" style="margin-top:6px;">'
               . 'Consejo: <b>Ver Dashboard</b> controla el acceso general al panel; '
               . 'el resto controla la <em>visualización</em> de cada botón. '
               . 'Los permisos de Consumibles bloqueados en esta tabla siguen la regla operativa por rol; '
               . 'las excepciones se crean por usuario y evento en <b>Control de Acceso Dashboard Staff</b>.</p>';
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
        'consumables_manage'    => 'consumables_manager_page_id',
        'consumables_staff'     => 'consumables_staff_page_id',
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


/**
 * Resuelve el permiso efectivo de una funcionalidad frontend.
 *
 * Para Consumibles usa el evento activo y la misma capa de permisos empleada
 * por las tarjetas del dashboard. Esto evita que el botón sea visible y que la
 * protección de la URL lo rechace después por consultar una ruta diferente.
 *
 * @param string   $feature  Feature solicitada.
 * @param int|null $user_id  Usuario; null usa el usuario actual.
 * @param int      $event_id Evento; 0 usa el evento activo.
 * @return bool
 */
if ( ! function_exists('eventosapp_user_can_access_frontend_feature') ) {
    function eventosapp_user_can_access_frontend_feature($feature, $user_id = null, $event_id = 0) {
        $feature = sanitize_key((string) $feature);
        if ($feature === '') return false;

        $user_id = $user_id === null ? get_current_user_id() : absint($user_id);
        if (!$user_id) return false;

        if (user_can($user_id, 'manage_options')) return true;

        $event_id = absint($event_id);
        if (!$event_id && function_exists('eventosapp_get_active_event')) {
            $event_id = absint(eventosapp_get_active_event());
        }

        if (in_array($feature, ['consumables_manage', 'consumables_staff'], true)) {
            if ($event_id && function_exists('eventosapp_user_can_access_dashboard_feature_in_event')) {
                return (bool) eventosapp_user_can_access_dashboard_feature_in_event($user_id, $feature, $event_id);
            }

            $user = get_userdata($user_id);
            if (!$user) return false;

            foreach ((array) $user->roles as $role) {
                if (function_exists('eventosapp_consumables_role_policy')) {
                    $policy = eventosapp_consumables_role_policy($role, $feature);
                    if ($policy !== null && (int) $policy === 1) return true;
                }
            }
            return false;
        }

        return function_exists('eventosapp_role_can')
            ? (bool) eventosapp_role_can($feature, $user_id)
            : false;
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
        if ( ! eventosapp_user_can_access_frontend_feature($feature) ) {
            $labels = eventosapp_dashboard_features_complete();
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
    if ( ! eventosapp_user_can_access_frontend_feature($current_feature) ) {
        $labels = eventosapp_dashboard_features_complete();
        $nice   = isset($labels[$current_feature]) ? $labels[$current_feature] : $current_feature;
        eventosapp_redirect_with_error('No tienes permisos para acceder a: '.$nice.'.', ['from'=>$current_feature]);
    }
}, 9);



