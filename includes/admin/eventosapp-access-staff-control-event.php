<?php
/**
 * Control de Acceso al Dashboard por Evento
 *
 * Permite configurar qué secciones del dashboard pueden ver roles específicos
 * (Staff, Organizador, Logístico) para cada evento individual.
 *
 * Además, permite definir accesos personalizados por usuario para el dashboard
 * frontend del evento. Esta capa por usuario se guarda en un meta independiente
 * y no modifica las asignaciones internas de Co-gestión ni de Equipo de apoyo / Asistencia.
 *
 * IMPORTANTE: La matriz por rol funciona sobre los permisos globales configurados en
 * eventosapp-configuracion.php. Si un rol no tiene permiso global a una sección,
 * no se podrá habilitar a nivel de evento desde la matriz por rol.
 *
 * @package EventosApp
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('eventosapp_staff_access_excluded_support_features')) {
    function eventosapp_staff_access_excluded_support_features() {
        return ['support_assistance', 'support_team_metrics'];
    }
}

if (!function_exists('eventosapp_staff_access_get_features')) {
    /**
     * Devuelve las secciones del dashboard disponibles para este metabox.
     *
     * @param bool $include_support Si es true incluye Asistencia y Métrica de apoyo.
     * @return array
     */
    function eventosapp_staff_access_get_features($include_support = false) {
        $features = function_exists('eventosapp_dashboard_features')
            ? eventosapp_dashboard_features()
            : [];

        if (!$include_support) {
            foreach (eventosapp_staff_access_excluded_support_features() as $excluded_feature) {
                unset($features[$excluded_feature]);
            }
        }

        return is_array($features) ? $features : [];
    }
}

if (!function_exists('eventosapp_staff_access_normalize_user_access')) {
    /**
     * Normaliza el meta de accesos personalizados por usuario.
     * Soporta la estructura nueva y una estructura simple [user_id => [feature => 1/0]].
     *
     * @param mixed $raw
     * @return array
     */
    function eventosapp_staff_access_normalize_user_access($raw) {
        if (!is_array($raw)) {
            return [];
        }

        $features = eventosapp_staff_access_get_features(true);
        $out = [];

        foreach ($raw as $user_id => $row) {
            $user_id = absint($user_id);
            if (!$user_id || !is_array($row)) {
                continue;
            }

            $feature_values = [];
            $source_features = [];

            if (isset($row['features']) && is_array($row['features'])) {
                $source_features = $row['features'];
            } else {
                $source_features = $row;
            }

            foreach ($features as $feature_key => $feature_label) {
                $feature_values[$feature_key] = !empty($source_features[$feature_key]) ? 1 : 0;
            }

            $out[$user_id] = [
                'user_id'    => $user_id,
                'features'   => $feature_values,
                'created_by' => isset($row['created_by']) ? absint($row['created_by']) : 0,
                'created_at' => isset($row['created_at']) ? absint($row['created_at']) : 0,
                'updated_by' => isset($row['updated_by']) ? absint($row['updated_by']) : 0,
                'updated_at' => isset($row['updated_at']) ? absint($row['updated_at']) : 0,
            ];
        }

        return $out;
    }
}

if (!function_exists('eventosapp_staff_access_get_user_access')) {
    /**
     * Obtiene los accesos personalizados por usuario para un evento.
     *
     * @param int $event_id
     * @return array
     */
    function eventosapp_staff_access_get_user_access($event_id) {
        $event_id = absint($event_id);
        if (!$event_id) {
            return [];
        }

        $raw = get_post_meta($event_id, '_eventosapp_staff_user_event_access', true);
        return eventosapp_staff_access_normalize_user_access($raw);
    }
}

if (!function_exists('eventosapp_staff_access_user_has_custom_access')) {
    /**
     * Indica si un usuario tiene una configuración personalizada en este evento.
     *
     * @param int $event_id
     * @param int $user_id
     * @return bool
     */
    function eventosapp_staff_access_user_has_custom_access($event_id, $user_id) {
        $event_id = absint($event_id);
        $user_id = absint($user_id);
        if (!$event_id || !$user_id) {
            return false;
        }

        $user_access = eventosapp_staff_access_get_user_access($event_id);
        return isset($user_access[$user_id]) && is_array($user_access[$user_id]);
    }
}

if (!function_exists('eventosapp_staff_access_get_user_feature_value')) {
    /**
     * Lee el valor personalizado de una sección para un usuario.
     *
     * Retorna null cuando el usuario no tiene configuración personalizada en el evento.
     * Retorna 0/1 cuando sí existe configuración personalizada.
     *
     * @param int $event_id
     * @param int $user_id
     * @param string $feature
     * @param mixed $default
     * @return int|null|mixed
     */
    function eventosapp_staff_access_get_user_feature_value($event_id, $user_id, $feature, $default = null) {
        $event_id = absint($event_id);
        $user_id = absint($user_id);
        $feature = sanitize_key($feature);

        if (!$event_id || !$user_id || $feature === '') {
            return $default;
        }

        $user_access = eventosapp_staff_access_get_user_access($event_id);
        if (!isset($user_access[$user_id])) {
            return $default;
        }

        return isset($user_access[$user_id]['features'][$feature])
            ? (int) $user_access[$user_id]['features'][$feature]
            : 0;
    }
}

if (!function_exists('eventosapp_staff_access_user_can_access_feature')) {
    /**
     * Resuelve la capa personalizada por usuario.
     *
     * - Si el usuario no tiene configuración personalizada, retorna $default.
     * - Si la tiene, esa configuración manda sobre la matriz por rol.
     * - Para secciones distintas de dashboard, también exige que el dashboard esté activo.
     *
     * Esta función NO modifica asignaciones de Equipo de apoyo / Asistencia. Si se activa
     * una sección de apoyo, solo habilita la entrada al módulo; el alcance del contenido
     * sigue dependiendo de la configuración propia de ese módulo.
     *
     * @param int $event_id
     * @param int $user_id
     * @param string $feature
     * @param mixed $default
     * @return bool|mixed
     */
    function eventosapp_staff_access_user_can_access_feature($event_id, $user_id, $feature, $default = null) {
        $feature_value = eventosapp_staff_access_get_user_feature_value($event_id, $user_id, $feature, null);
        if ($feature_value === null) {
            return $default;
        }

        $feature = sanitize_key($feature);

        if ($feature !== 'dashboard') {
            $dashboard_value = eventosapp_staff_access_get_user_feature_value($event_id, $user_id, 'dashboard', 0);
            if ((int) $dashboard_value !== 1) {
                return false;
            }
        }

        return ((int) $feature_value === 1);
    }
}

if (!function_exists('eventosapp_staff_access_user_can_select_event_in_dashboard')) {
    /**
     * Permite que el selector del dashboard muestre un evento a un usuario
     * agregado en la sección personalizada, siempre que tenga Ver Dashboard activo.
     *
     * @param int $event_id
     * @param int|null $user_id
     * @return bool
     */
    function eventosapp_staff_access_user_can_select_event_in_dashboard($event_id, $user_id = null) {
        $event_id = absint($event_id);
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        $user_id = absint($user_id);

        if (!$event_id || !$user_id) {
            return false;
        }

        return eventosapp_staff_access_user_can_access_feature($event_id, $user_id, 'dashboard', false) === true;
    }
}

if (!function_exists('eventosapp_staff_access_user_has_any_dashboard_event')) {
    /**
     * Verifica si el usuario tiene al menos un evento con acceso personalizado al dashboard.
     * Se usa para permitir que entre a la página del dashboard antes de seleccionar evento activo.
     *
     * @param int|null $user_id
     * @return bool
     */
    function eventosapp_staff_access_user_has_any_dashboard_event($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        $user_id = absint($user_id);
        if (!$user_id) {
            return false;
        }

        static $cache = [];
        if (array_key_exists($user_id, $cache)) {
            return (bool) $cache[$user_id];
        }

        $event_ids = get_posts([
            'post_type'      => 'eventosapp_event',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => '_eventosapp_staff_user_event_access',
            'no_found_rows'  => true,
        ]);

        if (!$event_ids) {
            return false;
        }

        foreach ($event_ids as $event_id) {
            if (eventosapp_staff_access_user_can_select_event_in_dashboard((int) $event_id, $user_id)) {
                $cache[$user_id] = true;
                return true;
            }
        }

        $cache[$user_id] = false;
        return false;
    }
}

// ========================================
// METABOX: Control de Acceso por Evento
// ========================================

/**
 * Registrar el metabox en eventosapp_event
 */
add_action('add_meta_boxes', 'eventosapp_add_staff_access_control_metabox');

function eventosapp_add_staff_access_control_metabox() {
    add_meta_box(
        'eventosapp_staff_access_control',
        'Control de Acceso Dashboard Staff',
        'eventosapp_render_staff_access_control_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
}

/**
 * Renderizar el metabox
 */
function eventosapp_render_staff_access_control_metabox($post) {
    wp_nonce_field('eventosapp_staff_access_control_nonce', 'eventosapp_staff_access_control_nonce');

    // Obtener la configuración guardada para este evento
    $event_access = get_post_meta($post->ID, '_eventosapp_staff_event_access', true);
    if (!is_array($event_access)) {
        $event_access = [];
    }

    // Obtener configuración global de permisos
    $global_visibility = function_exists('eventosapp_get_dashboard_visibility')
        ? eventosapp_get_dashboard_visibility()
        : [];

    // Obtener secciones para la matriz por rol. Asistencia se mantiene fuera para no competir con su metabox.
    $features = eventosapp_staff_access_get_features(false);

    // Obtener secciones completas para la nueva matriz personalizada por usuario.
    $user_features = eventosapp_staff_access_get_features(true);

    // Roles que queremos controlar por evento (SIN prefijo eventosapp_)
    $controlled_roles = [
        'staff'       => 'Staff',
        'organizador' => 'Organizador',
        'logistico'   => 'Logístico'
    ];

    $user_access = eventosapp_staff_access_get_user_access($post->ID);

    $all_users = get_users([
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => ['ID', 'user_login', 'user_email', 'display_name'],
    ]);

    ?>
    <div class="eventosapp-staff-access-control">
        <p class="description" style="margin-bottom: 15px;">
            <strong>Importante:</strong> la matriz por rol funciona <em>sobre</em> los permisos globales configurados en
            <a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_configuracion')); ?>" target="_blank">Configuración</a>.
            Solo puedes <strong>restringir</strong> lo que ya está habilitado globalmente para cada rol.
        </p>
        <p class="description" style="margin-bottom: 15px; padding: 10px; border-left: 4px solid #2271b1; background: #f0f6fc;">
            <strong>Asistencia y Métrica de equipo de apoyo:</strong> estas dos secciones no se controlan desde la matriz por rol. Su asignación interna sigue dependiendo del metabox <strong>Equipo de apoyo / Asistencia</strong>.
        </p>

        <style>
            .eventosapp-staff-access-control {
                overflow-x: auto;
            }
            .eventosapp-staff-access-control .access-table-wrapper {
                overflow-x: auto;
                margin-top: 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .eventosapp-staff-access-control .access-table {
                width: 100%;
                min-width: 900px;
                border-collapse: collapse;
                margin: 0;
            }
            .eventosapp-staff-access-control .access-table th,
            .eventosapp-staff-access-control .access-table td {
                border: 1px solid #ddd;
                padding: 6px 8px;
                text-align: center;
                font-size: 12px;
            }
            .eventosapp-staff-access-control .access-table th {
                background: #f6f7f7;
                font-weight: 600;
                position: sticky;
                top: 0;
                z-index: 2;
                white-space: nowrap;
                max-width: 115px;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .eventosapp-staff-access-control .access-table th.role-header {
                min-width: 120px;
                max-width: 120px;
            }
            .eventosapp-staff-access-control .access-table th.user-header {
                min-width: 240px;
                max-width: 240px;
            }
            .eventosapp-staff-access-control .access-table td.role-name,
            .eventosapp-staff-access-control .access-table td.user-name {
                text-align: left;
                background: #fafafa;
                font-weight: 600;
                position: sticky;
                left: 0;
                z-index: 1;
                white-space: nowrap;
                min-width: 120px;
            }
            .eventosapp-staff-access-control .access-table td.user-name {
                min-width: 240px;
                white-space: normal;
            }
            .eventosapp-staff-access-control .access-table td.disabled {
                background: #f9f9f9;
                color: #999;
            }
            .eventosapp-staff-access-control .access-table input[type="checkbox"]:disabled {
                cursor: not-allowed;
            }
            .eventosapp-staff-access-control .global-blocked {
                color: #d63638;
                font-size: 10px;
                display: block;
                margin-top: 2px;
            }
            .eventosapp-staff-access-control .role-code,
            .eventosapp-staff-access-control .user-code {
                font-size: 10px;
                color: #666;
                display: block;
                margin-top: 2px;
                font-weight: 400;
            }
            .eventosapp-staff-access-control .section-title {
                margin: 22px 0 8px;
                padding-top: 14px;
                border-top: 1px solid #dcdcde;
            }
            .eventosapp-staff-access-control .user-access-add-box {
                margin-top: 12px;
                padding: 12px;
                border: 1px solid #dcdcde;
                background: #fff;
                border-radius: 6px;
            }
            .eventosapp-staff-access-control .user-access-add-box select {
                min-width: 320px;
                max-width: 100%;
            }
            .eventosapp-staff-access-control .feature-checks-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
                gap: 8px 14px;
                margin-top: 10px;
            }
            .eventosapp-staff-access-control .feature-checks-grid label {
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 6px 8px;
                background: #f6f7f7;
                border: 1px solid #e2e4e7;
                border-radius: 4px;
            }
            .eventosapp-staff-access-control .support-feature-mark {
                display: block;
                margin-top: 3px;
                color: #2271b1;
                font-size: 10px;
                font-weight: 600;
            }
            .eventosapp-staff-access-control .delete-user-access {
                color: #b32d2e;
                font-weight: 600;
            }
        </style>

        <h3 class="section-title">1. Matriz por rol del evento</h3>
        <div class="access-table-wrapper">
            <table class="access-table">
                <thead>
                    <tr>
                        <th class="role-header">Rol</th>
                        <?php foreach ($features as $feature_key => $feature_label): ?>
                            <th title="<?php echo esc_attr($feature_label); ?>"><?php echo esc_html($feature_label); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($controlled_roles as $role_slug => $role_label): ?>
                        <tr>
                            <td class="role-name">
                                <?php echo esc_html($role_label); ?>
                                <span class="role-code"><?php echo esc_html($role_slug); ?></span>
                            </td>
                            <?php foreach ($features as $feature_key => $feature_label): ?>
                                <?php
                                // Verificar si el rol tiene permiso global para esta feature
                                $has_global_permission = !empty($global_visibility[$role_slug][$feature_key]);

                                // Verificar si está habilitado para este evento específico.
                                // Por defecto, si tiene permiso global, está habilitado a menos que se haya desmarcado.
                                $event_permission = isset($event_access[$role_slug][$feature_key])
                                    ? (int)$event_access[$role_slug][$feature_key]
                                    : ($has_global_permission ? 1 : 0);

                                $field_name = '_eventosapp_staff_event_access[' . esc_attr($role_slug) . '][' . esc_attr($feature_key) . ']';

                                // Si no tiene permiso global, el checkbox está deshabilitado
                                $disabled = !$has_global_permission;
                                $checked = $has_global_permission && $event_permission ? 'checked' : '';
                                $td_class = $disabled ? 'disabled' : '';
                                ?>
                                <td class="<?php echo esc_attr($td_class); ?>">
                                    <input
                                        type="checkbox"
                                        name="<?php echo esc_attr($field_name); ?>"
                                        value="1"
                                        <?php echo $checked; ?>
                                        <?php echo $disabled ? 'disabled' : ''; ?>
                                        <?php if ($disabled): ?>
                                            title="Bloqueado globalmente"
                                        <?php endif; ?>
                                    />
                                    <?php if ($disabled): ?>
                                        <span class="global-blocked">❌</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="description" style="margin-top: 15px;">
            <strong>Cómo funciona la matriz por rol:</strong>
        </p>
        <ul style="margin-left: 20px; list-style: disc;">
            <li>Las casillas <strong>marcadas</strong> indican que el rol puede ver esa sección en este evento.</li>
            <li>Las casillas <strong>desmarcadas</strong> bloquean el acceso a esa sección para este evento específico.</li>
            <li>Las casillas <strong>deshabilitadas</strong> están bloqueadas en la configuración global y no se pueden habilitar aquí.</li>
            <li>Por defecto, todos los permisos globales están habilitados hasta que los desmarques manualmente.</li>
        </ul>

        <h3 class="section-title">2. Acceso personalizado por usuario</h3>
        <p class="description" style="margin-bottom: 12px; padding: 10px; border-left: 4px solid #00a32a; background: #f0f8f1;">
            Esta sección permite agregar usuarios puntuales al frontend del evento y definir exactamente qué botones/secciones ven. Esta capa personalizada <strong>manda sobre la matriz por rol</strong> para ese usuario en este evento, pero <strong>no cambia</strong> sus asignaciones internas de Co-gestión ni de Equipo de apoyo / Asistencia.
        </p>
        <p class="description" style="margin-bottom: 12px; padding: 10px; border-left: 4px solid #dba617; background: #fcf9e8;">
            Si activas <strong>Asistencia</strong> o <strong>Métrica de equipo de apoyo</strong> para un usuario, solo habilitas la entrada al módulo desde el dashboard. El contenido que ese usuario podrá ver dentro del módulo seguirá dependiendo de lo configurado en <strong>Equipo de apoyo / Asistencia</strong>. Si desmarcas esas secciones aquí, se bloquea el acceso al botón/página sin borrar su asignación de apoyo.
        </p>

        <?php if (!empty($user_access)): ?>
            <div class="access-table-wrapper">
                <table class="access-table">
                    <thead>
                        <tr>
                            <th class="user-header">Usuario</th>
                            <?php foreach ($user_features as $feature_key => $feature_label): ?>
                                <th title="<?php echo esc_attr($feature_label); ?>">
                                    <?php echo esc_html($feature_label); ?>
                                    <?php if (in_array($feature_key, eventosapp_staff_access_excluded_support_features(), true)): ?>
                                        <span class="support-feature-mark">Apoyo</span>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                            <th>Eliminar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($user_access as $access_user_id => $access_row): ?>
                            <?php
                            $access_user = get_userdata($access_user_id);
                            $display_name = $access_user ? $access_user->display_name : 'Usuario eliminado';
                            $login = $access_user ? $access_user->user_login : ('ID ' . $access_user_id);
                            $email = $access_user ? $access_user->user_email : '';
                            ?>
                            <tr>
                                <td class="user-name">
                                    <?php echo esc_html($display_name); ?>
                                    <span class="user-code">
                                        <?php echo esc_html($login); ?><?php echo $email ? ' · ' . esc_html($email) : ''; ?>
                                    </span>
                                </td>
                                <?php foreach ($user_features as $feature_key => $feature_label): ?>
                                    <?php
                                    $field_name = '_eventosapp_staff_user_event_access[' . esc_attr($access_user_id) . '][' . esc_attr($feature_key) . ']';
                                    $checked = !empty($access_row['features'][$feature_key]) ? 'checked' : '';
                                    ?>
                                    <td>
                                        <input
                                            type="checkbox"
                                            name="<?php echo esc_attr($field_name); ?>"
                                            value="1"
                                            <?php echo $checked; ?>
                                        />
                                    </td>
                                <?php endforeach; ?>
                                <td>
                                    <label class="delete-user-access">
                                        <input type="checkbox" name="eventosapp_staff_user_access_remove[]" value="<?php echo esc_attr($access_user_id); ?>">
                                        Quitar
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="margin: 8px 0 12px; padding: 10px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px;">
                Todavía no hay usuarios con acceso personalizado para este evento.
            </p>
        <?php endif; ?>

        <div class="user-access-add-box">
            <h4 style="margin: 0 0 10px;">Agregar usuario con acceso personalizado</h4>
            <p style="margin: 0 0 8px;">
                <label for="eventosapp_staff_user_access_add_user"><strong>Usuario:</strong></label><br>
                <select id="eventosapp_staff_user_access_add_user" name="eventosapp_staff_user_access_add_user">
                    <option value="0">— Selecciona un usuario —</option>
                    <?php foreach ($all_users as $candidate_user): ?>
                        <?php if (isset($user_access[(int)$candidate_user->ID])) continue; ?>
                        <option value="<?php echo esc_attr($candidate_user->ID); ?>">
                            <?php echo esc_html($candidate_user->display_name . ' (' . $candidate_user->user_login . ' · ' . $candidate_user->user_email . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <div class="feature-checks-grid">
                <?php foreach ($user_features as $feature_key => $feature_label): ?>
                    <?php
                    $add_checked = ($feature_key === 'dashboard') ? 'checked' : '';
                    $field_name = 'eventosapp_staff_user_access_add_features[' . esc_attr($feature_key) . ']';
                    ?>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($field_name); ?>" value="1" <?php echo $add_checked; ?>>
                        <span>
                            <?php echo esc_html($feature_label); ?>
                            <?php if (in_array($feature_key, eventosapp_staff_access_excluded_support_features(), true)): ?>
                                <span class="support-feature-mark">Controla entrada; el contenido depende de Apoyo</span>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <p class="description" style="margin: 10px 0 0;">
                Guarda o actualiza el evento para aplicar el nuevo usuario. <strong>Ver Dashboard</strong> debe permanecer activo para que las demás secciones funcionen desde el panel.
            </p>
        </div>
    </div>
    <?php
}

/**
 * Guardar la configuración del metabox
 */
add_action('save_post_eventosapp_event', 'eventosapp_save_staff_access_control_metabox', 10, 2);

function eventosapp_save_staff_access_control_metabox($post_id, $post) {
    // Verificaciones de seguridad
    if (!isset($_POST['eventosapp_staff_access_control_nonce'])) {
        return;
    }

    if (!wp_verify_nonce(wp_unslash($_POST['eventosapp_staff_access_control_nonce']), 'eventosapp_staff_access_control_nonce')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Obtener la configuración global para validar
    $global_visibility = function_exists('eventosapp_get_dashboard_visibility')
        ? eventosapp_get_dashboard_visibility()
        : [];

    // Obtener secciones de la matriz por rol, excluyendo las de apoyo.
    $features = eventosapp_staff_access_get_features(false);

    // Roles que controlamos (SIN prefijo eventosapp_)
    $controlled_roles = [
        'staff',
        'organizador',
        'logistico'
    ];

    // Procesar y sanitizar los datos de la matriz por rol
    $event_access = [];
    $input = isset($_POST['_eventosapp_staff_event_access'])
        ? (array) wp_unslash($_POST['_eventosapp_staff_event_access'])
        : [];

    foreach ($controlled_roles as $role_slug) {
        $event_access[$role_slug] = [];

        foreach ($features as $feature_key => $feature_label) {
            // Verificar que el rol tenga permiso global
            $has_global_permission = !empty($global_visibility[$role_slug][$feature_key]);

            // Solo guardar si tiene permiso global y está marcado
            if ($has_global_permission) {
                $value = isset($input[$role_slug][$feature_key]) ? 1 : 0;
                $event_access[$role_slug][$feature_key] = $value;
            } else {
                // Si no tiene permiso global, forzar a 0
                $event_access[$role_slug][$feature_key] = 0;
            }
        }
    }

    update_post_meta($post_id, '_eventosapp_staff_event_access', $event_access);

    // Procesar y sanitizar los accesos personalizados por usuario.
    $user_features = eventosapp_staff_access_get_features(true);
    $current_user_access = eventosapp_staff_access_get_user_access($post_id);
    $new_user_access = [];
    $now = time();
    $current_admin = get_current_user_id();

    $remove_users = isset($_POST['eventosapp_staff_user_access_remove'])
        ? array_map('absint', (array) wp_unslash($_POST['eventosapp_staff_user_access_remove']))
        : [];
    $remove_users = array_filter(array_unique($remove_users));

    $input_users = isset($_POST['_eventosapp_staff_user_event_access'])
        ? (array) wp_unslash($_POST['_eventosapp_staff_user_event_access'])
        : [];

    foreach ($input_users as $user_id => $feature_input) {
        $user_id = absint($user_id);
        if (!$user_id || in_array($user_id, $remove_users, true)) {
            continue;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            continue;
        }

        $feature_input = is_array($feature_input) ? $feature_input : [];
        $feature_values = [];

        foreach ($user_features as $feature_key => $feature_label) {
            $feature_values[$feature_key] = isset($feature_input[$feature_key]) ? 1 : 0;
        }

        $previous = isset($current_user_access[$user_id]) ? $current_user_access[$user_id] : [];
        $new_user_access[$user_id] = [
            'user_id'    => $user_id,
            'features'   => $feature_values,
            'created_by' => !empty($previous['created_by']) ? absint($previous['created_by']) : $current_admin,
            'created_at' => !empty($previous['created_at']) ? absint($previous['created_at']) : $now,
            'updated_by' => $current_admin,
            'updated_at' => $now,
        ];
    }

    $add_user_id = isset($_POST['eventosapp_staff_user_access_add_user'])
        ? absint($_POST['eventosapp_staff_user_access_add_user'])
        : 0;

    if ($add_user_id && !in_array($add_user_id, $remove_users, true) && !isset($new_user_access[$add_user_id])) {
        $add_user = get_userdata($add_user_id);
        if ($add_user) {
            $add_features_input = isset($_POST['eventosapp_staff_user_access_add_features'])
                ? (array) wp_unslash($_POST['eventosapp_staff_user_access_add_features'])
                : [];
            $feature_values = [];
            $has_any_feature = false;

            foreach ($user_features as $feature_key => $feature_label) {
                $value = isset($add_features_input[$feature_key]) ? 1 : 0;
                $feature_values[$feature_key] = $value;
                if ($value === 1) {
                    $has_any_feature = true;
                }
            }

            if ($has_any_feature) {
                $new_user_access[$add_user_id] = [
                    'user_id'    => $add_user_id,
                    'features'   => $feature_values,
                    'created_by' => $current_admin,
                    'created_at' => $now,
                    'updated_by' => $current_admin,
                    'updated_at' => $now,
                ];
            }
        }
    }

    if (!empty($new_user_access)) {
        ksort($new_user_access, SORT_NUMERIC);
        update_post_meta($post_id, '_eventosapp_staff_user_event_access', $new_user_access);
    } else {
        delete_post_meta($post_id, '_eventosapp_staff_user_event_access');
    }
}

// ========================================
// VALIDACIÓN DE PERMISOS POR EVENTO
// ========================================

/**
 * Filtrar la función eventosapp_role_can para incluir validación por evento.
 *
 * Este filtro se ejecuta después de verificar los permisos globales. La capa
 * personalizada por usuario puede otorgar o quitar acceso en este evento, y se
 * vuelve a aplicar al final con prioridad alta para evitar que otro filtro la reabra.
 */
add_filter('eventosapp_role_can', 'eventosapp_validate_event_specific_access', 10, 3);

function eventosapp_validate_event_specific_access($has_permission, $feature, $user) {
    if (!$user || empty($user->ID)) {
        return $has_permission;
    }

    // Obtener el evento activo
    if (!function_exists('eventosapp_get_active_event')) {
        return $has_permission; // Si no hay función, usar permiso global
    }

    $active_event = eventosapp_get_active_event();

    // Si no hay evento activo, permitir entrada al dashboard si tiene algún evento personalizado.
    if (!$active_event) {
        if ($feature === 'dashboard' && eventosapp_staff_access_user_has_any_dashboard_event((int) $user->ID)) {
            return true;
        }
        return $has_permission;
    }

    // La capa personalizada por usuario manda sobre la matriz por rol cuando existe.
    $custom_permission = eventosapp_staff_access_user_can_access_feature($active_event, (int) $user->ID, $feature, null);
    if ($custom_permission !== null) {
        return (bool) $custom_permission;
    }

    // Si ya no tiene permiso global y no existe una capa personalizada, no hacer nada más.
    if (!$has_permission) {
        return false;
    }

    // Verificar si el usuario tiene alguno de los roles controlados por evento (SIN prefijo)
    $controlled_roles = [
        'staff',
        'organizador',
        'logistico'
    ];

    $user_roles = (array) $user->roles;
    $has_controlled_role = false;
    $current_role = null;

    foreach ($user_roles as $role) {
        if (in_array($role, $controlled_roles, true)) {
            $has_controlled_role = true;
            $current_role = $role;
            break;
        }
    }

    // Si el usuario no tiene ningún rol controlado, usar permiso global
    if (!$has_controlled_role) {
        return $has_permission;
    }

    // Obtener la configuración de acceso para este evento
    $event_access = get_post_meta($active_event, '_eventosapp_staff_event_access', true);

    // Si no hay configuración específica del evento, usar permiso global (permitir por defecto)
    if (!is_array($event_access) || !isset($event_access[$current_role])) {
        return $has_permission;
    }

    // Verificar el permiso específico para esta feature en este evento
    $event_permission = isset($event_access[$current_role][$feature])
        ? (int)$event_access[$current_role][$feature]
        : 1; // Por defecto permitir si no está configurado

    // Solo permitir si tiene AMBOS permisos: global Y del evento
    return $has_permission && ($event_permission === 1);
}

/**
 * Reaplica la capa personalizada por usuario al final de la cadena de filtros.
 * Esto evita conflictos con otros metaboxes: si aquí se desmarca una sección,
 * se bloquea su botón/página sin borrar los privilegios internos del otro módulo.
 */
add_filter('eventosapp_role_can', 'eventosapp_apply_user_specific_dashboard_access', 999, 3);

function eventosapp_apply_user_specific_dashboard_access($has_permission, $feature, $user) {
    if (!$user || empty($user->ID)) {
        return $has_permission;
    }

    if (!function_exists('eventosapp_get_active_event')) {
        return $has_permission;
    }

    $active_event = eventosapp_get_active_event();

    if (!$active_event) {
        if ($feature === 'dashboard' && eventosapp_staff_access_user_has_any_dashboard_event((int) $user->ID)) {
            return true;
        }
        return $has_permission;
    }

    $custom_permission = eventosapp_staff_access_user_can_access_feature($active_event, (int) $user->ID, $feature, null);
    if ($custom_permission !== null) {
        return (bool) $custom_permission;
    }

    return $has_permission;
}

/**
 * Helper: Verificar si un rol específico puede acceder a una feature en un evento específico.
 *
 * @param string $role Slug del rol
 * @param string $feature Slug de la feature
 * @param int $event_id ID del evento
 * @return bool
 */
function eventosapp_role_can_in_event($role, $feature, $event_id) {
    // Verificar permiso global primero
    if (!function_exists('eventosapp_get_dashboard_visibility')) {
        return false;
    }

    $global_visibility = eventosapp_get_dashboard_visibility();
    $has_global = !empty($global_visibility[$role][$feature]);

    if (!$has_global) {
        return false; // Si no tiene permiso global, no puede acceder desde la matriz por rol
    }

    // Verificar si es un rol controlado (SIN prefijo)
    $controlled_roles = [
        'staff',
        'organizador',
        'logistico'
    ];

    if (!in_array($role, $controlled_roles, true)) {
        return $has_global; // Roles no controlados usan solo permisos globales
    }

    // Obtener configuración del evento
    $event_access = get_post_meta($event_id, '_eventosapp_staff_event_access', true);

    // Si no hay configuración, permitir por defecto
    if (!is_array($event_access) || !isset($event_access[$role])) {
        return $has_global;
    }

    // Verificar permiso específico del evento
    $event_permission = isset($event_access[$role][$feature])
        ? (int)$event_access[$role][$feature]
        : 1;

    return $has_global && ($event_permission === 1);
}

if (!function_exists('eventosapp_user_can_access_dashboard_feature_in_event')) {
    /**
     * Helper por usuario: resuelve primero la capa personalizada y luego la matriz por rol.
     * Útil para otros módulos que necesiten preguntar por una sección específica de un evento.
     *
     * @param int $user_id
     * @param string $feature
     * @param int $event_id
     * @return bool
     */
    function eventosapp_user_can_access_dashboard_feature_in_event($user_id, $feature, $event_id) {
        $user_id = absint($user_id);
        $event_id = absint($event_id);
        $feature = sanitize_key($feature);

        if (!$user_id || !$event_id || $feature === '') {
            return false;
        }

        $custom_permission = eventosapp_staff_access_user_can_access_feature($event_id, $user_id, $feature, null);
        if ($custom_permission !== null) {
            return (bool) $custom_permission;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        foreach ((array) $user->roles as $role) {
            if (eventosapp_role_can_in_event($role, $feature, $event_id)) {
                return true;
            }
        }

        return false;
    }
}
