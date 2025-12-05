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
        'register_page_id'          => 0,
        'qr_page_id'                => 0,
        'metrics_page_id'           => 0,
        'edit_page_id'              => 0,
        'qr_localidad_page_id'      => 0,
        'qr_sesion_page_id'         => 0,
        'checklist_page_id'         => 0,
        'networking_ranking_page_id' => 0,
        'qr_double_auth_page_id'    => 0, // AGREGAR ESTA LÍNEA
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
function eventosapp_get_register_url() {
    return eventosapp_get_configured_page_url('register_page_id', '#');
}
function eventosapp_get_qr_url() {
    return eventosapp_get_configured_page_url('qr_page_id', '#');
}
function eventosapp_get_metrics_url() {
    return eventosapp_get_configured_page_url('metrics_page_id', '#');
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


});

function eventosapp_sanitize_pages_option($input){
    $out = [];
    $keys = [
        'dashboard_page_id',
        'front_search_page_id',
        'register_page_id',
        'qr_page_id',
        'metrics_page_id',
        'edit_page_id',
        'qr_localidad_page_id',
        'qr_sesion_page_id',
        'checklist_page_id',
        'networking_ranking_page_id',
        'qr_double_auth_page_id', // AGREGAR ESTA LÍNEA
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

function eventosapp_render_configuracion_page(){ ?>
<div class="wrap">
        <h1>Configuración de EventosApp</h1>
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
            <li><code>[eventosapp_front_register]</code> — Registro manual de asistentes.</li>
            <li><code>[eventosapp_qr_checkin]</code> — Check-In con QR (lector de cámara).</li>
            <li><code>[eventosapp_front_edit]</code> — Edición de tickets.</li>
            <li><code>[eventosapp_qr_localidad]</code> — Validador de Localidad (solo lectura).</li>
            <li><code>[eventosapp_qr_sesion]</code> — Control de acceso por sesión.</li> <!-- NUEVO -->
			<li><code>[eventosapp_event_checklist]</code> — Checklist del evento (para coordinador).</li>
			<li><code>[eventosapp_networking_ranking]</code> — Ranking Networking (Top lectores y leídos del día).</li>
			<li><code>[qr_checkin_doble_auth]</code> — Check-In con QR y Doble Autenticación.</li>


        </ul>
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
        'search'             => 'Check-In Manual & Escarapela',
        'register'           => 'Registro Manual de Asistentes',
        'qr'                 => 'Check-In con QR',
        'edit'               => 'Edición de Tickets',
        'qr_localidad'       => 'Validador de Localidad',
        'qr_sesion'          => 'Control por Sesión',
        'checklist'          => 'Checklist de Evento',
        'networking_ranking' => 'Ranking Networking',
        'qr_double_auth'     => 'Check-In QR Doble Autenticación', // AGREGAR ESTA LÍNEA
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
        $defaults['staff']['qr']            = 1;
        $defaults['staff']['qr_localidad']  = 1;
        $defaults['staff']['qr_sesion']     = 1;
        // checklist: OFF por defecto para staff
        // networking_ranking: OFF por defecto para staff (ajústalo si lo deseas ON)
    }

    // Logístico
    if (isset($defaults['logistico'])) {
        $defaults['logistico']['dashboard']     = 1;
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
        'search'             => 'front_search_page_id',
        'register'           => 'register_page_id',
        'qr'                 => 'qr_page_id',
        'edit'               => 'edit_page_id',
        'qr_localidad'       => 'qr_localidad_page_id',
        'qr_sesion'          => 'qr_sesion_page_id',
        'checklist'          => 'checklist_page_id',
        'networking_ranking' => 'networking_ranking_page_id',
        'qr_double_auth'     => 'qr_double_auth_page_id', // AGREGAR ESTA LÍNEA
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



