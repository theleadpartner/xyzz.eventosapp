<?php
/**
 * Control de Acceso al Dashboard por Evento
 * 
 * Permite configurar qué secciones del dashboard pueden ver roles específicos
 * (Staff, Organizador, Logístico) para cada evento individual.
 * 
 * IMPORTANTE: Este sistema funciona sobre los permisos globales configurados en
 * eventosapp-configuracion.php. Si un rol no tiene permiso global a una sección,
 * no se podrá habilitar a nivel de evento.
 * 
 * @package EventosApp
 */

if (!defined('ABSPATH')) exit;

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
    
    // Obtener todas las secciones disponibles
    $features = function_exists('eventosapp_dashboard_features') 
        ? eventosapp_dashboard_features() 
        : [];
    
    // Roles que queremos controlar por evento
    $controlled_roles = [
        'eventosapp_staff'       => 'Staff',
        'eventosapp_organizador' => 'Organizador',
        'eventosapp_logistico'   => 'Logístico'
    ];
    
    ?>
    <div class="eventosapp-staff-access-control">
        <p class="description" style="margin-bottom: 15px;">
            <strong>Importante:</strong> Este control funciona <em>sobre</em> los permisos globales configurados en 
            <a href="<?php echo admin_url('admin.php?page=eventosapp_configuracion'); ?>" target="_blank">Configuración</a>. 
            Solo puedes <strong>restringir</strong> lo que ya está habilitado globalmente, no puedes habilitar lo que está bloqueado.
        </p>
        
        <style>
            .eventosapp-staff-access-control .access-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }
            .eventosapp-staff-access-control .access-table th,
            .eventosapp-staff-access-control .access-table td {
                border: 1px solid #ddd;
                padding: 8px 10px;
                text-align: center;
            }
            .eventosapp-staff-access-control .access-table th {
                background: #f6f7f7;
                font-weight: 600;
            }
            .eventosapp-staff-access-control .access-table td.role-name {
                text-align: left;
                background: #fafafa;
                font-weight: 600;
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
                font-size: 11px;
                display: block;
                margin-top: 2px;
            }
        </style>
        
        <table class="access-table">
            <thead>
                <tr>
                    <th style="width: 150px;">Rol</th>
                    <?php foreach ($features as $feature_key => $feature_label): ?>
                        <th><?php echo esc_html($feature_label); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($controlled_roles as $role_slug => $role_label): ?>
                    <tr>
                        <td class="role-name">
                            <?php echo esc_html($role_label); ?>
                            <br><code style="font-size: 10px;"><?php echo esc_html($role_slug); ?></code>
                        </td>
                        <?php foreach ($features as $feature_key => $feature_label): ?>
                            <?php
                            // Verificar si el rol tiene permiso global para esta feature
                            $has_global_permission = !empty($global_visibility[$role_slug][$feature_key]);
                            
                            // Verificar si está habilitado para este evento específico
                            // Por defecto, si tiene permiso global, está habilitado (a menos que se haya desmarcado)
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
                                    <span class="global-blocked">Bloqueado</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <p class="description" style="margin-top: 15px;">
            <strong>Cómo funciona:</strong>
        </p>
        <ul style="margin-left: 20px; list-style: disc;">
            <li>Las casillas <strong>marcadas</strong> indican que el rol puede ver esa sección en este evento.</li>
            <li>Las casillas <strong>desmarcadas</strong> bloquean el acceso a esa sección para este evento específico.</li>
            <li>Las casillas <strong>deshabilitadas</strong> (grises) están bloqueadas en la configuración global y no se pueden habilitar aquí.</li>
            <li>Por defecto, todos los permisos globales están habilitados hasta que los desmarques manualmente.</li>
        </ul>
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
    
    if (!wp_verify_nonce($_POST['eventosapp_staff_access_control_nonce'], 'eventosapp_staff_access_control_nonce')) {
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
    
    // Obtener todas las secciones disponibles
    $features = function_exists('eventosapp_dashboard_features') 
        ? eventosapp_dashboard_features() 
        : [];
    
    // Roles que controlamos
    $controlled_roles = [
        'eventosapp_staff',
        'eventosapp_organizador',
        'eventosapp_logistico'
    ];
    
    // Procesar y sanitizar los datos
    $event_access = [];
    $input = isset($_POST['_eventosapp_staff_event_access']) ? $_POST['_eventosapp_staff_event_access'] : [];
    
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
    
    // Guardar la configuración
    update_post_meta($post_id, '_eventosapp_staff_event_access', $event_access);
}

// ========================================
// VALIDACIÓN DE PERMISOS POR EVENTO
// ========================================

/**
 * Filtrar la función eventosapp_role_can para incluir validación por evento
 * 
 * Este filtro se ejecuta después de verificar los permisos globales.
 * Si el usuario no tiene permiso global, no llega a esta validación.
 */
add_filter('eventosapp_role_can', 'eventosapp_validate_event_specific_access', 10, 3);

function eventosapp_validate_event_specific_access($has_permission, $feature, $user) {
    // Si ya no tiene permiso global, no hacer nada más
    if (!$has_permission) {
        return false;
    }
    
    // Obtener el evento activo
    if (!function_exists('eventosapp_get_active_event')) {
        return $has_permission; // Si no hay función, usar permiso global
    }
    
    $active_event = eventosapp_get_active_event();
    
    // Si no hay evento activo, usar solo permisos globales
    if (!$active_event) {
        return $has_permission;
    }
    
    // Verificar si el usuario tiene alguno de los roles controlados por evento
    $controlled_roles = [
        'eventosapp_staff',
        'eventosapp_organizador',
        'eventosapp_logistico'
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
 * Helper: Verificar si un rol específico puede acceder a una feature en un evento específico
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
        return false; // Si no tiene permiso global, no puede acceder
    }
    
    // Verificar si es un rol controlado
    $controlled_roles = [
        'eventosapp_staff',
        'eventosapp_organizador',
        'eventosapp_logistico'
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
