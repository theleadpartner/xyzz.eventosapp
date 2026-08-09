<?php
/**
 * EventosApp - Asistencia / Equipo de apoyo
 *
 * Archivo nuevo.
 * Ubicación recomendada: includes/frontend/eventosapp-support-assistance.php
 *
 * Incluye:
 * - Metabox por evento para grupos de staff de apoyo.
 * - Shortcode [eventosapp_support_assistance] para registrar atenciones.
 * - Shortcode [eventosapp_support_team_metrics] para métricas del equipo de apoyo.
 * - AJAX para identificar asistentes por QR o cédula.
 * - Registro y descarga CSV de atenciones.
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! defined('EVENTOSAPP_SUPPORT_DB_VERSION') ) {
    define('EVENTOSAPP_SUPPORT_DB_VERSION', '1.0.0');
}

if ( ! function_exists('eventosapp_support_table_name') ) {
    function eventosapp_support_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'eventosapp_support_attentions';
    }
}

if ( ! function_exists('eventosapp_support_assistance_install_table') ) {
    function eventosapp_support_assistance_install_table() {
        global $wpdb;

        $table = eventosapp_support_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL DEFAULT 0,
            ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
            ticket_code varchar(120) NOT NULL DEFAULT '',
            attendee_first_name varchar(190) NOT NULL DEFAULT '',
            attendee_last_name varchar(190) NOT NULL DEFAULT '',
            attendee_cc varchar(80) NOT NULL DEFAULT '',
            attendee_email varchar(190) NOT NULL DEFAULT '',
            attendee_phone varchar(120) NOT NULL DEFAULT '',
            staff_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            staff_name varchar(190) NOT NULL DEFAULT '',
            staff_email varchar(190) NOT NULL DEFAULT '',
            support_group_number int(10) unsigned NOT NULL DEFAULT 0,
            is_group_coordinator tinyint(1) NOT NULL DEFAULT 0,
            reason text NULL,
            source varchar(40) NOT NULL DEFAULT 'frontend',
            created_at_gmt datetime NOT NULL,
            created_at_local datetime NOT NULL,
            created_date date NOT NULL,
            created_hour varchar(5) NOT NULL DEFAULT '00:00',
            PRIMARY KEY  (id),
            KEY event_id (event_id),
            KEY ticket_id (ticket_id),
            KEY staff_user_id (staff_user_id),
            KEY created_date (created_date),
            KEY created_hour (created_hour),
            KEY event_hour (event_id, created_hour),
            KEY event_staff (event_id, staff_user_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('eventosapp_support_db_version', EVENTOSAPP_SUPPORT_DB_VERSION, false);
    }
}

add_action('init', function(){
    $installed = get_option('eventosapp_support_db_version');
    if ( $installed !== EVENTOSAPP_SUPPORT_DB_VERSION ) {
        eventosapp_support_assistance_install_table();
    }
}, 5);

if ( ! function_exists('eventosapp_support_get_event_timezone') ) {
    function eventosapp_support_get_event_timezone( $event_id ) {
        $tzid = get_post_meta( absint($event_id), '_eventosapp_zona_horaria', true );
        if ( ! $tzid ) {
            $tzid = wp_timezone_string();
        }
        if ( ! $tzid ) {
            $tzid = 'UTC';
        }
        try {
            return new DateTimeZone($tzid);
        } catch (Exception $e) {
            return wp_timezone();
        }
    }
}

if ( ! function_exists('eventosapp_support_normalize_groups') ) {
    function eventosapp_support_normalize_groups( $groups ) {
        if ( ! is_array($groups) ) return [];

        $clean = [];
        foreach ( $groups as $group ) {
            if ( ! is_array($group) ) continue;

            $number = isset($group['group_number']) ? absint($group['group_number']) : 0;
            if ( ! $number ) continue;

            $members = isset($group['members']) ? (array) $group['members'] : [];
            $members = array_values(array_unique(array_filter(array_map('absint', $members))));

            $coordinator = isset($group['coordinator_id']) ? absint($group['coordinator_id']) : 0;
            if ( $coordinator && ! in_array($coordinator, $members, true) ) {
                $members[] = $coordinator;
            }

            if ( empty($members) ) continue;

            $clean[] = [
                'group_number'   => $number,
                'coordinator_id' => $coordinator,
                'members'        => array_values(array_unique($members)),
                'created_at'     => isset($group['created_at']) ? sanitize_text_field($group['created_at']) : current_time('mysql'),
                'created_by'     => isset($group['created_by']) ? absint($group['created_by']) : 0,
            ];
        }

        usort($clean, function($a, $b){
            return (int) $a['group_number'] <=> (int) $b['group_number'];
        });

        return $clean;
    }
}

if ( ! function_exists('eventosapp_support_get_groups') ) {
    function eventosapp_support_get_groups( $event_id ) {
        $groups = get_post_meta( absint($event_id), '_eventosapp_support_staff_groups', true );
        return eventosapp_support_normalize_groups($groups);
    }
}

if ( ! function_exists('eventosapp_support_save_groups') ) {
    function eventosapp_support_save_groups( $event_id, $groups ) {
        update_post_meta( absint($event_id), '_eventosapp_support_staff_groups', eventosapp_support_normalize_groups($groups) );
    }
}

if ( ! function_exists('eventosapp_support_normalize_organizer_team_ids') ) {
    function eventosapp_support_normalize_organizer_team_ids( $ids ) {
        if ( ! is_array($ids) ) {
            $ids = $ids ? [$ids] : [];
        }
        return array_values(array_unique(array_filter(array_map('absint', $ids))));
    }
}

if ( ! function_exists('eventosapp_support_get_organizer_team_user_ids') ) {
    function eventosapp_support_get_organizer_team_user_ids( $event_id ) {
        $ids = get_post_meta( absint($event_id), '_eventosapp_support_organizer_team_ids', true );
        return eventosapp_support_normalize_organizer_team_ids($ids);
    }
}

if ( ! function_exists('eventosapp_support_save_organizer_team_user_ids') ) {
    function eventosapp_support_save_organizer_team_user_ids( $event_id, $ids ) {
        update_post_meta( absint($event_id), '_eventosapp_support_organizer_team_ids', eventosapp_support_normalize_organizer_team_ids($ids) );
    }
}

if ( ! function_exists('eventosapp_support_user_is_super_admin') ) {
    function eventosapp_support_user_is_super_admin( $user_id = null ) {
        $user_id = $user_id === null ? get_current_user_id() : absint($user_id);
        if ( ! $user_id ) return false;

        if ( function_exists('is_super_admin') && is_super_admin($user_id) ) {
            return true;
        }

        return user_can($user_id, 'manage_options');
    }
}

if ( ! function_exists('eventosapp_support_user_is_organizer_team') ) {
    function eventosapp_support_user_is_organizer_team( $event_id, $user_id = null ) {
        $user_id = $user_id === null ? get_current_user_id() : absint($user_id);
        return $user_id && in_array($user_id, eventosapp_support_get_organizer_team_user_ids($event_id), true);
    }
}

if ( ! function_exists('eventosapp_support_user_can_download_csv') ) {
    function eventosapp_support_user_can_download_csv( $event_id, $user_id = null ) {
        $event_id = absint($event_id);
        $user_id  = $user_id === null ? get_current_user_id() : absint($user_id);

        if ( ! $event_id || ! $user_id ) {
            return false;
        }

        // La descarga completa del CSV queda reservada a administradores
        // y a usuarios marcados en el metabox como Equipo del Organizador.
        if ( eventosapp_support_user_is_super_admin($user_id) ) {
            return true;
        }

        return eventosapp_support_user_is_organizer_team($event_id, $user_id);
    }
}

if ( ! function_exists('eventosapp_support_get_download_csv_url') ) {
    /**
     * Construye una URL frontend para descargar el CSV de consultas.
     *
     * No se usa wp-admin/admin-post.php como URL principal porque algunos usuarios
     * operativos del dashboard no tienen acceso al área administrativa y pueden ser
     * redirigidos antes de que WordPress ejecute admin_post. El handler se mantiene
     * también en admin_post por compatibilidad, pero los botones del frontend usan
     * esta ruta segura.
     *
     * @param int $event_id
     * @return string
     */
    function eventosapp_support_get_download_csv_url( $event_id ) {
        $event_id = absint($event_id);
        if ( ! $event_id ) {
            return '#';
        }

        return add_query_arg([
            'eventosapp_frontend_action' => 'eventosapp_support_download_csv',
            'event_id'                   => $event_id,
            '_wpnonce'                   => wp_create_nonce('eventosapp_support_download_csv_' . $event_id),
        ], home_url('/'));
    }
}

if ( ! function_exists('eventosapp_support_is_frontend_download_csv_request') ) {
    function eventosapp_support_is_frontend_download_csv_request() {
        $action = isset($_REQUEST['eventosapp_frontend_action'])
            ? sanitize_key(wp_unslash($_REQUEST['eventosapp_frontend_action']))
            : '';

        return $action === 'eventosapp_support_download_csv';
    }
}

if ( ! function_exists('eventosapp_support_get_all_assigned_user_ids') ) {
    function eventosapp_support_get_all_assigned_user_ids( $event_id ) {
        $ids = eventosapp_support_get_event_staff_user_ids($event_id);
        foreach ( eventosapp_support_get_organizer_team_user_ids($event_id) as $uid ) {
            $ids[] = absint($uid);
        }
        return array_values(array_unique(array_filter($ids)));
    }
}

if ( ! function_exists('eventosapp_support_user_has_assignment_in_event') ) {
    function eventosapp_support_user_has_assignment_in_event( $event_id, $user_id = null ) {
        $user_id = $user_id === null ? get_current_user_id() : absint($user_id);
        if ( ! $event_id || ! $user_id ) return false;
        return eventosapp_support_user_is_assigned_to_event($event_id, $user_id) || eventosapp_support_user_is_organizer_team($event_id, $user_id);
    }
}

if ( ! function_exists('eventosapp_support_get_event_staff_user_ids') ) {
    function eventosapp_support_get_event_staff_user_ids( $event_id ) {
        $ids = [];
        foreach ( eventosapp_support_get_groups($event_id) as $group ) {
            foreach ( (array) $group['members'] as $uid ) {
                $uid = absint($uid);
                if ( $uid ) $ids[] = $uid;
            }
            $coordinator = absint($group['coordinator_id'] ?? 0);
            if ( $coordinator ) $ids[] = $coordinator;
        }
        return array_values(array_unique(array_filter($ids)));
    }
}

if ( ! function_exists('eventosapp_support_get_cogestion_staff_user_ids') ) {
    function eventosapp_support_get_cogestion_staff_user_ids( $event_id ) {
        $assigned = get_post_meta( absint($event_id), '_evapp_event_staff_assigned', true );
        if ( ! is_array($assigned) ) return [];
        return array_values(array_unique(array_filter(array_map('absint', array_keys($assigned)))));
    }
}

if ( ! function_exists('eventosapp_support_get_user_group') ) {
    function eventosapp_support_get_user_group( $event_id, $user_id ) {
        $user_id = absint($user_id);
        if ( ! $user_id ) return null;

        foreach ( eventosapp_support_get_groups($event_id) as $group ) {
            $members = array_map('absint', (array) ($group['members'] ?? []));
            if ( in_array($user_id, $members, true) || absint($group['coordinator_id'] ?? 0) === $user_id ) {
                return $group;
            }
        }
        return null;
    }
}

if ( ! function_exists('eventosapp_support_user_is_assigned_to_event') ) {
    function eventosapp_support_user_is_assigned_to_event( $event_id, $user_id = null ) {
        $user_id = $user_id === null ? get_current_user_id() : absint($user_id);
        return (bool) eventosapp_support_get_user_group( absint($event_id), $user_id );
    }
}

if ( ! function_exists('eventosapp_support_user_is_group_coordinator') ) {
    function eventosapp_support_user_is_group_coordinator( $event_id, $user_id = null ) {
        $user_id = $user_id === null ? get_current_user_id() : absint($user_id);
        $group = eventosapp_support_get_user_group( absint($event_id), $user_id );
        return $group && absint($group['coordinator_id'] ?? 0) === $user_id;
    }
}

if ( ! function_exists('eventosapp_support_user_has_any_event') ) {
    function eventosapp_support_user_has_any_event( $user_id = null ) {
        $user_id = $user_id === null ? get_current_user_id() : absint($user_id);
        if ( ! $user_id ) return false;

        $events = get_posts([
            'post_type'      => 'eventosapp_event',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => '_eventosapp_support_staff_groups',
                    'compare' => 'EXISTS',
                ],
                [
                    'key'     => '_eventosapp_support_organizer_team_ids',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        foreach ( $events as $event_id ) {
            if ( eventosapp_support_user_has_assignment_in_event($event_id, $user_id) ) {
                return true;
            }
        }
        return false;
    }
}

if ( ! function_exists('eventosapp_support_user_can_admin_event_without_support') ) {
    function eventosapp_support_user_can_admin_event_without_support( $event_id, $user_id = null ) {
        $event_id = absint($event_id);
        $user_id  = $user_id === null ? get_current_user_id() : absint($user_id);
        if ( ! $event_id || ! $user_id ) return false;

        if ( user_can($user_id, 'manage_options') ) {
            return true;
        }

        $event = get_post($event_id);
        if ( $event && absint($event->post_author) === $user_id ) {
            return true;
        }

        $now = time();
        $temp_authors = get_post_meta($event_id, '_evapp_temp_authors', true);
        if ( is_array($temp_authors) ) {
            foreach ( $temp_authors as $row ) {
                if ( ! is_array($row) || empty($row['user_id']) ) continue;
                if ( absint($row['user_id']) !== $user_id ) continue;
                $until = isset($row['until']) ? absint($row['until']) : 0;
                if ( ! $until || $until >= $now ) return true;
            }
        }

        return false;
    }
}

if ( ! function_exists('eventosapp_support_user_can_feature_for_event') ) {
    function eventosapp_support_user_can_feature_for_event( $event_id, $feature, $user_id = null ) {
        $event_id = absint($event_id);
        $user_id  = $user_id === null ? get_current_user_id() : absint($user_id);
        if ( ! $event_id || ! $user_id ) return false;

        // El bypass de estas secciones queda reservado al superadministrador.
        // Los permisos por rol y el metabox "Control de Acceso Dashboard Staff" no habilitan por sí solos estas secciones.
        if ( eventosapp_support_user_is_super_admin($user_id) ) {
            return true;
        }

        $is_group_member      = eventosapp_support_user_is_assigned_to_event($event_id, $user_id);
        $is_group_coordinator = eventosapp_support_user_is_group_coordinator($event_id, $user_id);
        $is_organizer_team    = eventosapp_support_user_is_organizer_team($event_id, $user_id);

        if ( $feature === 'dashboard' ) {
            return $is_group_member || $is_organizer_team;
        }

        if ( $feature === 'support_assistance' ) {
            // Los coordinadores y el Equipo del Organizador no registran atenciones desde esta sección.
            return $is_group_member && ! $is_group_coordinator && ! $is_organizer_team;
        }

        if ( $feature === 'support_team_metrics' ) {
            // Coordinador: métricas de su grupo. Equipo del Organizador: métricas globales del evento.
            return $is_group_coordinator || $is_organizer_team;
        }

        return false;
    }
}


// =====================================================
// Compatibilidad: selección de evento para Equipo del Organizador
// =====================================================
if ( ! function_exists('eventosapp_support_user_can_select_event_in_dashboard') ) {
    function eventosapp_support_user_can_select_event_in_dashboard( $event_id, $user_id = null ) {
        $event_id = absint($event_id);
        $user_id  = $user_id === null ? get_current_user_id() : absint($user_id);

        if ( ! $event_id || ! $user_id || get_post_type($event_id) !== 'eventosapp_event' ) {
            return false;
        }

        if ( eventosapp_support_user_is_super_admin($user_id) ) {
            return true;
        }

        if ( function_exists('eventosapp_dashboard_user_can_access_event_scope') ) {
            return eventosapp_dashboard_user_can_access_event_scope($event_id, $user_id);
        }

        // Respeta primero los accesos personalizados del metabox Control de Acceso Dashboard Staff.
        // Esto evita que el flujo de selección de evento quede limitado solo al permiso de gestión del evento.
        if ( function_exists('eventosapp_staff_access_user_can_select_event_in_dashboard') && eventosapp_staff_access_user_can_select_event_in_dashboard($event_id, $user_id) ) {
            return true;
        }

        // Permite seleccionar el evento a usuarios asignados al módulo Expositor.
        // Esta validación no concede permisos de Asistencia; solo habilita el cambio de evento activo.
        if ( function_exists('eventosapp_expositor_user_can_select_event_in_dashboard') && eventosapp_expositor_user_can_select_event_in_dashboard($event_id, $user_id) ) {
            return true;
        }

        // Incluye miembros de grupos de apoyo, coordinadores y Equipo del Organizador.
        // Este permiso solo habilita la selección del evento en el dashboard frontend;
        // no concede edición del evento ni acceso al botón de Asistencia.
        if ( eventosapp_support_user_has_assignment_in_event($event_id, $user_id) ) {
            return true;
        }

        if ( eventosapp_support_user_can_admin_event_without_support($event_id, $user_id) ) {
            return true;
        }

        if ( function_exists('eventosapp_user_can_manage_event') ) {
            try {
                $ref = new ReflectionFunction('eventosapp_user_can_manage_event');
                if ( $ref->getNumberOfParameters() >= 2 ) {
                    return (bool) eventosapp_user_can_manage_event($event_id, $user_id);
                }
                return (bool) eventosapp_user_can_manage_event($event_id);
            } catch ( Throwable $e ) {
                return false;
            }
        }

        return false;
    }
}

if ( ! function_exists('eventosapp_support_get_dashboard_redirect_url') ) {
    function eventosapp_support_get_dashboard_redirect_url() {
        $url = '';

        if ( function_exists('eventosapp_get_dashboard_url') ) {
            $url = eventosapp_get_dashboard_url();
        }

        if ( ! $url || $url === '#' ) {
            $cfg = function_exists('eventosapp_get_pages_config') ? eventosapp_get_pages_config() : [];
            $dashboard_page_id = isset($cfg['dashboard_page_id']) ? absint($cfg['dashboard_page_id']) : 0;
            if ( $dashboard_page_id ) {
                $url = get_permalink($dashboard_page_id);
            }
        }

        if ( ! $url || $url === '#' ) {
            $url = wp_get_referer();
        }

        if ( ! $url || $url === '#' ) {
            $url = home_url('/');
        }

        return remove_query_arg(['evapp_err', 'set', 'from'], $url);
    }
}

if ( ! function_exists('eventosapp_support_redirect_dashboard_error') ) {
    function eventosapp_support_redirect_dashboard_error( $message, array $extra = [] ) {
        if ( function_exists('eventosapp_redirect_with_error') ) {
            eventosapp_redirect_with_error($message, $extra);
        }

        $url = add_query_arg(
            array_merge([
                'evapp_err' => rawurlencode($message),
            ], $extra),
            eventosapp_support_get_dashboard_redirect_url()
        );

        wp_safe_redirect($url);
        exit;
    }
}

if ( ! function_exists('eventosapp_support_set_active_event_for_frontend') ) {
    function eventosapp_support_set_active_event_for_frontend( $event_id, $user_id = null ) {
        $event_id = absint($event_id);
        $user_id  = $user_id === null ? get_current_user_id() : absint($user_id);

        if ( ! $event_id || ! $user_id ) {
            return false;
        }

        if ( function_exists('eventosapp_set_active_event') ) {
            try {
                $ref = new ReflectionFunction('eventosapp_set_active_event');
                if ( $ref->getNumberOfParameters() >= 2 ) {
                    eventosapp_set_active_event($event_id, $user_id);
                } else {
                    eventosapp_set_active_event($event_id);
                }
            } catch ( Throwable $e ) {
                // Si el helper propio no está disponible o falla por firma distinta,
                // mantenemos los respaldos de user meta/cookie de abajo.
            }
        }

        // Respaldos no destructivos para instalaciones donde el helper del evento activo
        // lee una meta/cookie común. No eliminan ni modifican permisos ni asignaciones.
        update_user_meta($user_id, 'eventosapp_active_event', $event_id);
        update_user_meta($user_id, '_eventosapp_active_event', $event_id);
        update_user_meta($user_id, 'evapp_active_event', $event_id);

        $cookie_path   = ( defined('COOKIEPATH') && COOKIEPATH ) ? COOKIEPATH : '/';
        $cookie_domain = ( defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '';
        $cookie_expire = time() + WEEK_IN_SECONDS;
        $cookie_secure = is_ssl();

        if ( ! headers_sent() ) {
            setcookie('eventosapp_active_event', (string) $event_id, $cookie_expire, $cookie_path, $cookie_domain, $cookie_secure, true);
            setcookie('evapp_active_event', (string) $event_id, $cookie_expire, $cookie_path, $cookie_domain, $cookie_secure, true);
        }

        $_COOKIE['eventosapp_active_event'] = (string) $event_id;
        $_COOKIE['evapp_active_event']      = (string) $event_id;

        return true;
    }
}

if ( ! function_exists('eventosapp_support_handle_dashboard_event_selection') ) {
    function eventosapp_support_handle_dashboard_event_selection() {
        if ( is_admin() || wp_doing_ajax() ) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
        if ( $method !== 'POST' ) {
            return;
        }

        $action = isset($_POST['evapp_action']) ? sanitize_text_field(wp_unslash($_POST['evapp_action'])) : '';
        if ( $action !== 'set_event' ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            return;
        }

        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if ( ! wp_verify_nonce($nonce, 'evapp_set_event') ) {
            return;
        }

        $event_id = isset($_POST['eventosapp_event_id']) ? absint(wp_unslash($_POST['eventosapp_event_id'])) : 0;
        $user_id  = get_current_user_id();

        if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
            eventosapp_support_redirect_dashboard_error('Evento inválido.', ['from' => 'dashboard']);
        }

        // Este handler ahora resuelve la selección de evento de forma centralizada para:
        // - Equipo de apoyo / Equipo del Organizador.
        // - Acceso personalizado del metabox Control de Acceso Dashboard Staff.
        // - Usuarios asignados al módulo Expositor o gestores de expositores.
        // - Administradores, co-gestores y propietarios autorizados por el flujo base.
        // Así se evita que otro handler posterior rechace un evento válido con "No puedes gestionar este evento".
        if ( ! eventosapp_support_user_can_select_event_in_dashboard($event_id, $user_id) ) {
            eventosapp_support_redirect_dashboard_error('No puedes gestionar este evento.', ['from' => 'dashboard']);
        }

        eventosapp_support_set_active_event_for_frontend($event_id, $user_id);

        $url = add_query_arg('set', '1', eventosapp_support_get_dashboard_redirect_url());
        wp_safe_redirect($url);
        exit;
    }
}
add_action('init', 'eventosapp_support_handle_dashboard_event_selection', -1000);

/**
 * Permisos dinámicos para el metabox Equipo de apoyo / Asistencia.
 * Este filtro se ejecuta después del control por rol y por evento para que estas dos secciones
 * dependan finalmente del metabox de apoyo y no de la matriz global de permisos.
 */
add_filter('eventosapp_role_can', function($has_permission, $feature, $user){
    if ( ! $user || ! $user instanceof WP_User || ! $user->exists() ) {
        return $has_permission;
    }

    $user_id          = absint($user->ID);
    $support_features = ['support_assistance', 'support_team_metrics'];

    $active_event = 0;
    if ( function_exists('eventosapp_get_active_event') ) {
        $active_event = absint( eventosapp_get_active_event() );
    }

    if ( ! $active_event ) {
        $has_support_event = eventosapp_support_user_has_any_event($user_id);
        $has_expositor_event = function_exists('eventosapp_expositor_user_has_any_event')
            ? eventosapp_expositor_user_has_any_event($user_id)
            : false;
        $has_cogestion_event = function_exists('eventosapp_dashboard_user_has_any_cogestion_assignment')
            ? eventosapp_dashboard_user_has_any_cogestion_assignment($user_id)
            : false;
        $has_custom_dashboard_event = function_exists('eventosapp_staff_access_user_has_any_dashboard_event')
            ? eventosapp_staff_access_user_has_any_dashboard_event($user_id)
            : false;

        if ( $feature === 'dashboard' && ( $has_support_event || $has_expositor_event || $has_cogestion_event || $has_custom_dashboard_event ) ) {
            return true;
        }

        if ( in_array($feature, $support_features, true) ) {
            return false;
        }

        return $has_permission;
    }

    if ( eventosapp_support_user_is_super_admin($user_id) ) {
        if ( in_array($feature, $support_features, true) ) {
            return true;
        }
        return $has_permission;
    }

    $has_support_assignment = eventosapp_support_user_has_assignment_in_event($active_event, $user_id);

    // Asistencia y métricas quedan gobernadas exclusivamente por el metabox de apoyo.
    // La sección personalizada por usuario del Control de Acceso Dashboard Staff se
    // reaplica después con prioridad alta, por lo que puede conceder o bloquear estos módulos.
    if ( in_array($feature, $support_features, true) ) {
        return eventosapp_support_user_can_feature_for_event($active_event, $feature, $user_id);
    }

    if ( ! $has_support_assignment ) {
        return $has_permission;
    }

    if ( $feature === 'dashboard' ) {
        return true;
    }

    // Si el mismo usuario también es autor, co-gestor temporal, staff operativo
    // o tiene una configuración personalizada de dashboard para este evento,
    // el metabox de Asistencia no debe aislarlo ni apagar sus permisos de gestión.
    // En ese caso se conserva el permiso ya resuelto por la matriz global/por rol
    // o por la capa personalizada que se reaplica más adelante.
    $has_management_scope = false;

    $event = get_post($active_event);
    if ( $event && absint($event->post_author) === $user_id ) {
        $has_management_scope = true;
    }

    if ( ! $has_management_scope && function_exists('eventosapp_dashboard_user_has_cogestion_assignment_in_event') ) {
        $has_management_scope = eventosapp_dashboard_user_has_cogestion_assignment_in_event($active_event, $user_id);
    }

    if ( ! $has_management_scope && function_exists('eventosapp_dashboard_user_has_staff_custom_scope_in_event') ) {
        $has_management_scope = eventosapp_dashboard_user_has_staff_custom_scope_in_event($active_event, $user_id);
    }

    if ( $has_management_scope ) {
        return $has_permission;
    }

    // La asignación en Equipo de apoyo / Asistencia no debe apagar módulos externos que
    // tienen su propia lógica de permisos. Se preservan los permisos ya resueltos para
    // Expositor y Gestión de Expositores, evitando conflictos con usuarios que cumplen
    // doble función dentro del mismo evento.
    $external_module_features = ['expositor', 'expositor_gestion'];
    if ( in_array($feature, $external_module_features, true) ) {
        return $has_permission;
    }

    // Para los demás botones operativos se mantiene el aislamiento del equipo de apoyo.
    return false;
}, 20, 3);

if ( ! function_exists('eventosapp_support_get_ticket_payload') ) {
    function eventosapp_support_get_ticket_payload( $ticket_id ) {
        $ticket_id = absint($ticket_id);
        if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
            return null;
        }

        $event_id = absint( get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true) );

        return [
            'ticket_id'   => $ticket_id,
            'event_id'    => $event_id,
            'event_name'  => $event_id ? get_the_title($event_id) : '',
            'ticket_code' => (string) get_post_meta($ticket_id, 'eventosapp_ticketID', true),
            'first_name'  => (string) get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true),
            'last_name'   => (string) get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true),
            'cc'          => (string) get_post_meta($ticket_id, '_eventosapp_asistente_cc', true),
            'email'       => (string) get_post_meta($ticket_id, '_eventosapp_asistente_email', true),
            'phone'       => (string) get_post_meta($ticket_id, '_eventosapp_asistente_tel', true),
            'localidad'   => (string) get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true),
        ];
    }
}

if ( ! function_exists('eventosapp_support_find_ticket_by_qr') ) {
    function eventosapp_support_find_ticket_by_qr( $scanned, $event_id ) {
        global $wpdb;

        $scanned  = trim( sanitize_text_field( (string) $scanned ) );
        $event_id = absint($event_id);
        if ( $scanned === '' || ! $event_id ) return 0;

        if ( function_exists('eventosapp_qr_find_ticket_by_scanned_code') ) {
            $lookup = eventosapp_qr_find_ticket_by_scanned_code($scanned, $event_id);
            if ( ! empty($lookup['ticket_id']) ) {
                return absint($lookup['ticket_id']);
            }
        }

        $ticket_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT code_pm.post_id
               FROM {$wpdb->postmeta} code_pm
               INNER JOIN {$wpdb->postmeta} event_pm
                       ON event_pm.post_id = code_pm.post_id
                      AND event_pm.meta_key = %s
                      AND event_pm.meta_value = %s
               INNER JOIN {$wpdb->posts} p
                       ON p.ID = code_pm.post_id
                      AND p.post_type = 'eventosapp_ticket'
                      AND p.post_status NOT IN ('trash','auto-draft','inherit')
              WHERE code_pm.meta_key IN ('eventosapp_ticketID','eventosapp_ticket_preprintedID')
                AND code_pm.meta_value = %s
              ORDER BY code_pm.post_id DESC
              LIMIT 1",
            '_eventosapp_ticket_evento_id',
            (string) $event_id,
            $scanned
        ) );

        return $ticket_id ? absint($ticket_id) : 0;
    }
}

if ( ! function_exists('eventosapp_support_current_event_from_request') ) {
    function eventosapp_support_current_event_from_request( $value = 0 ) {
        $event_id = absint($value);
        if ( ! $event_id && function_exists('eventosapp_get_active_event') ) {
            $event_id = absint( eventosapp_get_active_event() );
        }
        return $event_id;
    }
}

if ( ! function_exists('eventosapp_support_require_event_feature') ) {
    function eventosapp_support_require_event_feature( $event_id, $feature ) {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error(['message' => 'Debes iniciar sesión.'], 401);
        }

        if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
            wp_send_json_error(['message' => 'Evento inválido.'], 400);
        }

        if ( ! eventosapp_support_user_can_feature_for_event($event_id, $feature) ) {
            wp_send_json_error(['message' => 'No tienes permisos sobre este evento.'], 403);
        }
    }
}

// =====================================================
// Helpers de guardado del metabox Equipo de apoyo / Asistencia
// =====================================================
if ( ! function_exists('eventosapp_support_process_assignment_update') ) {
    function eventosapp_support_process_assignment_update( $event_id, array $args = [] ) {
        $event_id = absint($event_id);

        if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
            return new WP_Error('invalid_event', 'Evento inválido.');
        }

        if ( ! current_user_can('edit_post', $event_id) ) {
            return new WP_Error('permission_denied', 'No tienes permisos para editar este evento.');
        }

        $groups = eventosapp_support_get_groups($event_id);

        $remove_numbers = isset($args['remove_numbers']) ? (array) $args['remove_numbers'] : [];
        $remove_numbers = array_values(array_unique(array_filter(array_map('absint', $remove_numbers))));
        if ( $remove_numbers ) {
            $groups = array_values(array_filter($groups, function($group) use ($remove_numbers){
                return ! in_array(absint($group['group_number'] ?? 0), $remove_numbers, true);
            }));
        }

        $co_staff_ids = eventosapp_support_get_cogestion_staff_user_ids($event_id);

        $group_support_ids = [];
        foreach ( $groups as $group ) {
            foreach ( (array) ($group['members'] ?? []) as $uid ) {
                $group_support_ids[] = absint($uid);
            }
        }
        $group_support_ids = array_values(array_unique(array_filter($group_support_ids)));

        if ( array_key_exists('organizer_team_ids', $args) ) {
            $organizer_team_ids = eventosapp_support_normalize_organizer_team_ids($args['organizer_team_ids']);
        } else {
            $organizer_team_ids = eventosapp_support_get_organizer_team_user_ids($event_id);
        }

        $organizer_team_ids = array_values(array_filter($organizer_team_ids, function($uid) use ($co_staff_ids, $group_support_ids){
            if ( ! $uid ) return false;
            if ( in_array($uid, $co_staff_ids, true) ) return false;
            if ( in_array($uid, $group_support_ids, true) ) return false;
            $user = get_userdata($uid);
            return $user && in_array('staff', (array) $user->roles, true);
        }));

        eventosapp_support_save_organizer_team_user_ids($event_id, $organizer_team_ids);

        $existing_support_ids = array_values(array_unique(array_filter(array_merge($group_support_ids, $organizer_team_ids))));

        $new_members = isset($args['new_members']) ? (array) $args['new_members'] : [];
        $new_members = array_values(array_unique(array_filter(array_map('absint', $new_members))));

        $new_coordinator = isset($args['new_coordinator']) ? absint($args['new_coordinator']) : 0;
        if ( $new_coordinator && ! in_array($new_coordinator, $new_members, true) ) {
            $new_members[] = $new_coordinator;
        }

        $new_members = array_values(array_filter($new_members, function($uid) use ($co_staff_ids, $existing_support_ids){
            if ( ! $uid ) return false;
            if ( in_array($uid, $co_staff_ids, true) ) return false;
            if ( in_array($uid, $existing_support_ids, true) ) return false;
            $user = get_userdata($uid);
            return $user && in_array('staff', (array) $user->roles, true);
        }));

        $created_group_number = 0;
        if ( $new_members ) {
            if ( $new_coordinator && ! in_array($new_coordinator, $new_members, true) ) {
                $new_coordinator = 0;
            }

            $next_group = 1;
            foreach ( $groups as $group ) {
                $next_group = max($next_group, absint($group['group_number'] ?? 0) + 1);
            }

            $groups[] = [
                'group_number'   => $next_group,
                'coordinator_id' => $new_coordinator,
                'members'        => $new_members,
                'created_at'     => current_time('mysql'),
                'created_by'     => get_current_user_id(),
            ];

            $created_group_number = $next_group;
        }

        eventosapp_support_save_groups($event_id, $groups);

        return [
            'created_group_number' => absint($created_group_number),
            'created_members'      => count($new_members),
            'removed_groups'       => count($remove_numbers),
            'organizer_team_count' => count($organizer_team_ids),
            'groups_count'         => count(eventosapp_support_get_groups($event_id)),
        ];
    }
}

// =====================================================
// Metabox de grupos de apoyo
// =====================================================
add_action('add_meta_boxes', function(){
    add_meta_box(
        'eventosapp_support_assistance_groups',
        'Equipo de apoyo / Asistencia',
        'eventosapp_support_render_groups_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
});

if ( ! function_exists('eventosapp_support_render_groups_metabox') ) {
    function eventosapp_support_render_groups_metabox( $post ) {
        wp_nonce_field('eventosapp_support_groups_save', 'eventosapp_support_groups_nonce');

        $event_id       = absint($post->ID);
        $groups         = eventosapp_support_get_groups($event_id);
        $support_ids    = eventosapp_support_get_event_staff_user_ids($event_id);
        $organizer_team_ids = eventosapp_support_get_organizer_team_user_ids($event_id);
        $all_support_ids    = eventosapp_support_get_all_assigned_user_ids($event_id);
        $cogestion_ids  = eventosapp_support_get_cogestion_staff_user_ids($event_id);
        $next_group     = 1;
        foreach ( $groups as $group ) {
            $next_group = max($next_group, absint($group['group_number']) + 1);
        }

        $staff_users = get_users([
            'role'    => 'staff',
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => ['ID', 'display_name', 'user_login', 'user_email'],
        ]);

        $latest = eventosapp_support_get_latest_attentions($event_id, 30);
        $download_url = eventosapp_support_get_download_csv_url($event_id);
        $can_download_csv = eventosapp_support_user_can_download_csv($event_id);
        ?>
        <style>
            .evapp-support-box{border:1px solid #dcdcde;background:#fff;border-radius:10px;padding:14px;margin:12px 0;}
            .evapp-support-box h4{margin:0 0 8px;}
            .evapp-support-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
            .evapp-support-table{width:100%;border-collapse:collapse;margin-top:8px;font-size:12px;}
            .evapp-support-table th,.evapp-support-table td{border:1px solid #dcdcde;padding:6px 8px;text-align:left;vertical-align:top;}
            .evapp-support-table th{background:#f6f7f7;font-weight:700;}
            .evapp-support-muted{color:#646970;font-size:12px;line-height:1.4;}
            .evapp-support-danger{color:#b32d2e;font-weight:600;}
            .evapp-support-pill{display:inline-block;background:#eef6ff;border:1px solid #b8dcff;border-radius:999px;padding:2px 8px;margin:2px 3px 2px 0;font-size:12px;}
            .evapp-support-pill.org{background:#fef3c7;border-color:#f59e0b;color:#78350f;}
            .evapp-support-select{width:100%;min-height:130px;}
            .evapp-support-full{width:100%;}
            .evapp-support-ajax-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px;}
            .evapp-support-ajax-status{font-size:12px;font-weight:600;}
            .evapp-support-ajax-status.ok{color:#008a20;}
            .evapp-support-ajax-status.error{color:#b32d2e;}
            @media(max-width:900px){.evapp-support-grid{grid-template-columns:1fr;}}
        </style>

        <div class="evapp-support-box">
            <h4>Grupos de staff para la sección Asistencia</h4>
            <p class="evapp-support-muted">
                Estos usuarios solo podrán acceder a las secciones de apoyo del evento. Un staff que esté asignado aquí no debe estar al mismo tiempo como <strong>Staff operativo</strong> en el metabox de co-gestión.
            </p>

            <?php if ( $groups ) : ?>
                <table class="evapp-support-table">
                    <thead>
                        <tr>
                            <th>Grupo</th>
                            <th>Coordinador</th>
                            <th>Integrantes</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $groups as $group ) : ?>
                        <?php
                        $coordinator_id = absint($group['coordinator_id'] ?? 0);
                        $coordinator    = $coordinator_id ? get_userdata($coordinator_id) : null;
                        $members        = array_map('absint', (array) ($group['members'] ?? []));
                        ?>
                        <tr>
                            <td><strong>Grupo <?php echo esc_html(absint($group['group_number'])); ?></strong></td>
                            <td>
                                <?php if ( $coordinator ) : ?>
                                    <?php echo esc_html($coordinator->display_name . ' (' . $coordinator->user_email . ')'); ?>
                                <?php else : ?>
                                    <span class="evapp-support-muted">Sin coordinador</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php foreach ( $members as $member_id ) : ?>
                                    <?php $member = get_userdata($member_id); ?>
                                    <?php if ( $member ) : ?>
                                        <span class="evapp-support-pill">
                                            <?php echo esc_html($member->display_name); ?>
                                            <?php if ( $member_id === $coordinator_id ) echo esc_html(' · coordinador'); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <label>
                                    <input type="checkbox" name="evapp_support_remove_groups[]" value="<?php echo esc_attr(absint($group['group_number'])); ?>">
                                    Eliminar grupo
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="evapp-support-muted">Todavía no hay grupos de apoyo para este evento.</p>
            <?php endif; ?>
        </div>

        <div class="evapp-support-box">
            <h4>Equipo del Organizador</h4>
            <p class="evapp-support-muted">
                Los usuarios seleccionados aquí solo podrán acceder a <strong>Métrica de equipo de apoyo</strong> y verán las métricas totales de todos los grupos del evento. No tendrán acceso al botón de Asistencia.
            </p>

            <?php if ( $organizer_team_ids ) : ?>
                <p>
                    <?php foreach ( $organizer_team_ids as $org_uid ) : ?>
                        <?php $org_user = get_userdata($org_uid); ?>
                        <?php if ( $org_user ) : ?>
                            <span class="evapp-support-pill org">
                                <?php echo esc_html($org_user->display_name . ' · Equipo del Organizador'); ?>
                            </span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </p>
            <?php else : ?>
                <p class="evapp-support-muted">Todavía no hay usuarios asignados como Equipo del Organizador para este evento.</p>
            <?php endif; ?>

            <label for="evapp_support_organizer_team_ids"><strong>Usuarios Equipo del Organizador</strong></label>
            <select id="evapp_support_organizer_team_ids" name="evapp_support_organizer_team_ids[]" class="evapp-support-select" multiple>
                <?php foreach ( $staff_users as $staff ) : ?>
                    <?php
                    $uid = absint($staff->ID);
                    $selected = in_array($uid, $organizer_team_ids, true);
                    $already_group = in_array($uid, $support_ids, true);
                    $already_cogestion = in_array($uid, $cogestion_ids, true);
                    $disabled = ! $selected && ( $already_group || $already_cogestion );
                    $label_suffix = '';
                    if ( $already_group ) {
                        $label_suffix = ' — bloqueado por grupo de apoyo';
                    } elseif ( $already_cogestion ) {
                        $label_suffix = ' — bloqueado por co-gestión';
                    }
                    ?>
                    <option value="<?php echo esc_attr($uid); ?>" <?php selected($selected); ?> <?php disabled($disabled); ?>>
                        <?php echo esc_html($staff->display_name . ' - ' . $staff->user_login . ' (' . $staff->user_email . ')' . $label_suffix); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="evapp-support-muted">Mantén presionada la tecla Ctrl/Cmd para seleccionar uno o varios usuarios. Para quitar un usuario del Equipo del Organizador, desmárcalo y presiona <strong>Agregar usuarios y actualizar pantalla</strong>.</p>
        </div>

        <div class="evapp-support-box">
            <h4>Agregar Grupo <?php echo esc_html($next_group); ?></h4>
            <div class="evapp-support-grid">
                <div>
                    <label for="evapp_support_new_members"><strong>Seleccionar varios usuarios staff</strong></label>
                    <select id="evapp_support_new_members" name="evapp_support_new_members[]" class="evapp-support-select" multiple>
                        <?php foreach ( $staff_users as $staff ) : ?>
                            <?php
                            $uid = absint($staff->ID);
                            $already_support   = in_array($uid, $all_support_ids, true);
                            $already_cogestion = in_array($uid, $cogestion_ids, true);
                            $disabled = $already_support || $already_cogestion;
                            $label_suffix = '';
                            if ( $already_support ) {
                                $label_suffix = ' — ya está en apoyo';
                            } elseif ( $already_cogestion ) {
                                $label_suffix = ' — bloqueado por co-gestión';
                            }
                            ?>
                            <option value="<?php echo esc_attr($uid); ?>" <?php disabled($disabled); ?>>
                                <?php echo esc_html($staff->display_name . ' - ' . $staff->user_login . ' (' . $staff->user_email . ')' . $label_suffix); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="evapp-support-muted">Mantén presionada la tecla Ctrl/Cmd para seleccionar varios usuarios.</p>
                </div>
                <div>
                    <label for="evapp_support_new_coordinator"><strong>Coordinador de grupo</strong></label>
                    <select id="evapp_support_new_coordinator" name="evapp_support_new_coordinator" class="evapp-support-full">
                        <option value="0">— Sin coordinador —</option>
                        <?php foreach ( $staff_users as $staff ) : ?>
                            <?php
                            $uid = absint($staff->ID);
                            $already_support   = in_array($uid, $all_support_ids, true);
                            $already_cogestion = in_array($uid, $cogestion_ids, true);
                            $disabled = $already_support || $already_cogestion;
                            $label_suffix = '';
                            if ( $already_support ) {
                                $label_suffix = ' — ya está en apoyo';
                            } elseif ( $already_cogestion ) {
                                $label_suffix = ' — bloqueado por co-gestión';
                            }
                            ?>
                            <option value="<?php echo esc_attr($uid); ?>" <?php disabled($disabled); ?>>
                                <?php echo esc_html($staff->display_name . ' (' . $staff->user_email . ')' . $label_suffix); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="evapp-support-muted">Si eliges un coordinador que no estaba en la lista de integrantes, se agregará automáticamente al grupo.</p>
                </div>
            </div>
            <div class="evapp-support-ajax-actions">
                <button type="button" class="button button-primary" id="evappSupportAjaxSaveBtn" data-event-id="<?php echo esc_attr($event_id); ?>">Agregar usuarios y actualizar pantalla</button>
                <span class="evapp-support-ajax-status" id="evappSupportAjaxSaveStatus"></span>
            </div>
            <p class="evapp-support-muted">Este botón guarda los usuarios seleccionados en este metabox sin depender del botón <strong>Actualizar</strong> del evento. Al finalizar, la pantalla se recarga automáticamente para mostrar el grupo creado y limpiar la selección.</p>
        </div>

        <script>
        (function(){
            const btn = document.getElementById('evappSupportAjaxSaveBtn');
            const status = document.getElementById('evappSupportAjaxSaveStatus');
            if (!btn || !status) return;

            const savedMessage = window.sessionStorage ? window.sessionStorage.getItem('evappSupportAjaxSaved') : '';
            if (savedMessage) {
                status.textContent = savedMessage;
                status.className = 'evapp-support-ajax-status ok';
                window.sessionStorage.removeItem('evappSupportAjaxSaved');
            }

            function selectedValues(selector){
                const el = document.querySelector(selector);
                if (!el) return [];
                return Array.from(el.selectedOptions || []).map(function(option){
                    return option.value;
                }).filter(Boolean);
            }

            function checkedValues(selector){
                return Array.from(document.querySelectorAll(selector)).map(function(input){
                    return input.value;
                }).filter(Boolean);
            }

            btn.addEventListener('click', function(){
                const nonceInput = document.getElementById('eventosapp_support_groups_nonce');
                const coordinator = document.getElementById('evapp_support_new_coordinator');

                if (!window.ajaxurl || !nonceInput) {
                    status.textContent = 'No se pudo iniciar el guardado AJAX. Recarga la pantalla e intenta nuevamente.';
                    status.className = 'evapp-support-ajax-status error';
                    return;
                }

                const fd = new FormData();
                fd.append('action', 'eventosapp_support_save_metabox_assignments');
                fd.append('event_id', btn.dataset.eventId || '0');
                fd.append('security', nonceInput.value || '');

                selectedValues('#evapp_support_organizer_team_ids').forEach(function(value){
                    fd.append('evapp_support_organizer_team_ids[]', value);
                });

                selectedValues('#evapp_support_new_members').forEach(function(value){
                    fd.append('evapp_support_new_members[]', value);
                });

                checkedValues('input[name="evapp_support_remove_groups[]"]:checked').forEach(function(value){
                    fd.append('evapp_support_remove_groups[]', value);
                });

                fd.append('evapp_support_new_coordinator', coordinator ? coordinator.value : '0');

                btn.disabled = true;
                status.textContent = 'Guardando asignaciones…';
                status.className = 'evapp-support-ajax-status';

                fetch(window.ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd
                }).then(function(response){
                    return response.json();
                }).then(function(resp){
                    if (!resp || !resp.success) {
                        const msg = resp && resp.data && resp.data.message ? resp.data.message : 'No se pudieron guardar las asignaciones.';
                        throw new Error(msg);
                    }

                    const msg = resp.data && resp.data.message ? resp.data.message : 'Asignaciones guardadas correctamente.';
                    if (window.sessionStorage) {
                        window.sessionStorage.setItem('evappSupportAjaxSaved', msg);
                    }
                    window.location.reload();
                }).catch(function(error){
                    status.textContent = error && error.message ? error.message : 'Error guardando asignaciones.';
                    status.className = 'evapp-support-ajax-status error';
                    btn.disabled = false;
                });
            });
        })();
        </script>

        <div class="evapp-support-box">
            <h4>Atenciones registradas en este evento</h4>
            <?php if ( $can_download_csv ) : ?>
                <p>
                    <a class="button button-secondary" href="<?php echo esc_url($download_url); ?>">Descargar base de consultas CSV</a>
                </p>
            <?php else : ?>
                <p class="evapp-support-muted">La descarga CSV solo está disponible para administradores y usuarios del Equipo del Organizador.</p>
            <?php endif; ?>
            <?php if ( $latest ) : ?>
                <table class="evapp-support-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Usuario</th>
                            <th>Asistente</th>
                            <th>Cédula</th>
                            <th>Ticket</th>
                            <th>Motivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $latest as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html($row->created_at_local); ?></td>
                                <td><?php echo esc_html($row->staff_name); ?></td>
                                <td><?php echo esc_html(trim($row->attendee_first_name . ' ' . $row->attendee_last_name)); ?></td>
                                <td><?php echo esc_html($row->attendee_cc); ?></td>
                                <td><?php echo esc_html($row->ticket_code); ?></td>
                                <td><?php echo esc_html(wp_trim_words((string) $row->reason, 18)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="evapp-support-muted">Aún no hay atenciones registradas para este evento.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}

add_action('save_post_eventosapp_event', function($post_id, $post){
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision($post_id) ) return;
    if ( empty($_POST['eventosapp_support_groups_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventosapp_support_groups_nonce'])), 'eventosapp_support_groups_save') ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;

    eventosapp_support_process_assignment_update($post_id, [
        'remove_numbers'     => isset($_POST['evapp_support_remove_groups']) ? array_map('absint', (array) $_POST['evapp_support_remove_groups']) : [],
        'organizer_team_ids' => isset($_POST['evapp_support_organizer_team_ids']) ? array_map('absint', (array) $_POST['evapp_support_organizer_team_ids']) : [],
        'new_members'        => isset($_POST['evapp_support_new_members']) ? array_map('absint', (array) $_POST['evapp_support_new_members']) : [],
        'new_coordinator'    => isset($_POST['evapp_support_new_coordinator']) ? absint($_POST['evapp_support_new_coordinator']) : 0,
    ]);
}, 40, 2);

add_action('wp_ajax_eventosapp_support_save_metabox_assignments', function(){
    check_ajax_referer('eventosapp_support_groups_save', 'security');

    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
        wp_send_json_error(['message' => 'Evento inválido.'], 400);
    }

    if ( ! current_user_can('edit_post', $event_id) ) {
        wp_send_json_error(['message' => 'No tienes permisos para editar este evento.'], 403);
    }

    $result = eventosapp_support_process_assignment_update($event_id, [
        'remove_numbers'     => isset($_POST['evapp_support_remove_groups']) ? array_map('absint', (array) $_POST['evapp_support_remove_groups']) : [],
        'organizer_team_ids' => isset($_POST['evapp_support_organizer_team_ids']) ? array_map('absint', (array) $_POST['evapp_support_organizer_team_ids']) : [],
        'new_members'        => isset($_POST['evapp_support_new_members']) ? array_map('absint', (array) $_POST['evapp_support_new_members']) : [],
        'new_coordinator'    => isset($_POST['evapp_support_new_coordinator']) ? absint($_POST['evapp_support_new_coordinator']) : 0,
    ]);

    if ( is_wp_error($result) ) {
        wp_send_json_error(['message' => $result->get_error_message()], 400);
    }

    $message = 'Asignaciones guardadas correctamente.';
    if ( ! empty($result['created_group_number']) ) {
        $message = 'Grupo ' . absint($result['created_group_number']) . ' agregado correctamente. Actualizando pantalla…';
    } elseif ( ! empty($result['removed_groups']) ) {
        $message = 'Grupos eliminados correctamente. Actualizando pantalla…';
    }

    wp_send_json_success([
        'message' => $message,
        'summary' => $result,
    ]);
});

// Enforce extra: si alguien intenta asignar en co-gestión a un staff de apoyo, se remueve de co-gestión.
add_action('save_post_eventosapp_event', function($post_id){
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;

    $support_ids = eventosapp_support_get_all_assigned_user_ids($post_id);
    if ( ! $support_ids ) return;

    $assigned = get_post_meta($post_id, '_evapp_event_staff_assigned', true);
    if ( ! is_array($assigned) ) return;

    $changed = false;
    foreach ( $support_ids as $support_id ) {
        if ( isset($assigned[$support_id]) ) {
            unset($assigned[$support_id]);
            if ( function_exists('eventosapp_remove_staff_from_event') ) {
                eventosapp_remove_staff_from_event($post_id, $support_id);
            }
            $changed = true;
        }
    }

    if ( $changed ) {
        update_post_meta($post_id, '_evapp_event_staff_assigned', $assigned);
    }
}, 999);

if ( ! function_exists('eventosapp_support_get_latest_attentions') ) {
    function eventosapp_support_get_latest_attentions( $event_id, $limit = 30 ) {
        global $wpdb;
        $table = eventosapp_support_table_name();
        $event_id = absint($event_id);
        $limit = max(1, min(200, absint($limit)));

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE event_id = %d ORDER BY id DESC LIMIT %d",
            $event_id,
            $limit
        ) );
    }
}

if ( ! function_exists('eventosapp_support_get_metrics_scope') ) {
    function eventosapp_support_get_metrics_scope( $event_id, $user_id = null ) {
        $event_id = absint($event_id);
        $user_id  = $user_id === null ? get_current_user_id() : absint($user_id);
        if ( ! $event_id || ! $user_id ) return null;

        if ( eventosapp_support_user_is_super_admin($user_id) || eventosapp_support_user_is_organizer_team($event_id, $user_id) ) {
            return [
                'type'         => 'all',
                'group_number' => 0,
                'label'        => 'Vista total del evento',
            ];
        }

        if ( eventosapp_support_user_is_group_coordinator($event_id, $user_id) ) {
            $group = eventosapp_support_get_user_group($event_id, $user_id);
            $group_number = $group ? absint($group['group_number'] ?? 0) : 0;
            if ( $group_number ) {
                return [
                    'type'         => 'group',
                    'group_number' => $group_number,
                    'label'        => 'Vista limitada al Grupo ' . $group_number,
                ];
            }
        }

        return null;
    }
}

if ( ! function_exists('eventosapp_support_prepare_query') ) {
    function eventosapp_support_prepare_query( $query, array $params ) {
        global $wpdb;
        array_unshift($params, $query);
        return call_user_func_array([$wpdb, 'prepare'], $params);
    }
}

if ( ! function_exists('eventosapp_support_metrics_where_sql') ) {
    function eventosapp_support_metrics_where_sql( $event_id, $scope, &$params ) {
        $params = [absint($event_id)];
        $where = 'event_id = %d';
        if ( is_array($scope) && ($scope['type'] ?? '') === 'group' ) {
            $where .= ' AND support_group_number = %d';
            $params[] = absint($scope['group_number'] ?? 0);
        }
        return $where;
    }
}

// =====================================================
// AJAX frontend
// =====================================================
add_action('wp_ajax_eventosapp_support_identify_qr', function(){
    check_ajax_referer('eventosapp_support_assistance', 'security');

    $event_id = eventosapp_support_current_event_from_request($_POST['event_id'] ?? 0);
    eventosapp_support_require_event_feature($event_id, 'support_assistance');

    $scanned = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
    if ( $scanned === '' ) {
        wp_send_json_error(['message' => 'No se recibió ningún código QR.'], 400);
    }

    $ticket_id = eventosapp_support_find_ticket_by_qr($scanned, $event_id);
    if ( ! $ticket_id ) {
        wp_send_json_error(['message' => 'No se encontró un asistente para este QR en el evento activo.'], 404);
    }

    $payload = eventosapp_support_get_ticket_payload($ticket_id);
    if ( ! $payload || absint($payload['event_id']) !== $event_id ) {
        wp_send_json_error(['message' => 'El ticket no pertenece al evento activo.'], 403);
    }

    wp_send_json_success($payload);
});

add_action('wp_ajax_eventosapp_support_search_attendee', function(){
    check_ajax_referer('eventosapp_support_assistance', 'security');

    $event_id = eventosapp_support_current_event_from_request($_REQUEST['event_id'] ?? 0);
    eventosapp_support_require_event_feature($event_id, 'support_assistance');

    global $wpdb;
    $q = isset($_REQUEST['q']) ? sanitize_text_field(wp_unslash($_REQUEST['q'])) : '';
    $q_digits = preg_replace('/\D+/', '', $q);
    $search = $q_digits !== '' ? $q_digits : trim($q);

    if ( strlen($search) < 2 ) {
        wp_send_json_success([]);
    }

    $like = '%' . $wpdb->esc_like($search) . '%';

    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT cc_pm.post_id
           FROM {$wpdb->postmeta} cc_pm
           INNER JOIN {$wpdb->postmeta} event_pm
                   ON event_pm.post_id = cc_pm.post_id
                  AND event_pm.meta_key = %s
                  AND event_pm.meta_value = %s
           INNER JOIN {$wpdb->posts} p
                   ON p.ID = cc_pm.post_id
                  AND p.post_type = 'eventosapp_ticket'
                  AND p.post_status NOT IN ('trash','auto-draft','inherit')
          WHERE cc_pm.meta_key = %s
            AND cc_pm.meta_value LIKE %s
          ORDER BY cc_pm.post_id DESC
          LIMIT 12",
        '_eventosapp_ticket_evento_id',
        (string) $event_id,
        '_eventosapp_asistente_cc',
        $like
    ) );

    $out = [];
    foreach ( array_map('absint', (array) $ids) as $ticket_id ) {
        $payload = eventosapp_support_get_ticket_payload($ticket_id);
        if ( $payload ) $out[] = $payload;
    }

    wp_send_json_success($out);
});

add_action('wp_ajax_eventosapp_support_register_attention', function(){
    check_ajax_referer('eventosapp_support_assistance', 'security');

    $event_id = eventosapp_support_current_event_from_request($_POST['event_id'] ?? 0);
    eventosapp_support_require_event_feature($event_id, 'support_assistance');

    $ticket_id = absint($_POST['ticket_id'] ?? 0);
    $reason    = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        wp_send_json_error(['message' => 'Ticket inválido.'], 400);
    }
    if ( $reason === '' ) {
        wp_send_json_error(['message' => 'Debes escribir la razón de la consulta.'], 400);
    }

    $payload = eventosapp_support_get_ticket_payload($ticket_id);
    if ( ! $payload || absint($payload['event_id']) !== $event_id ) {
        wp_send_json_error(['message' => 'El ticket no pertenece al evento activo.'], 403);
    }

    global $wpdb;
    $table = eventosapp_support_table_name();
    $user  = wp_get_current_user();
    $group = eventosapp_support_get_user_group($event_id, $user->ID);

    $tz = eventosapp_support_get_event_timezone($event_id);
    $local_dt = new DateTime('now', $tz);

    $inserted = $wpdb->insert(
        $table,
        [
            'event_id'              => $event_id,
            'ticket_id'             => $ticket_id,
            'ticket_code'           => (string) $payload['ticket_code'],
            'attendee_first_name'   => (string) $payload['first_name'],
            'attendee_last_name'    => (string) $payload['last_name'],
            'attendee_cc'           => (string) $payload['cc'],
            'attendee_email'        => (string) $payload['email'],
            'attendee_phone'        => (string) $payload['phone'],
            'staff_user_id'         => absint($user->ID),
            'staff_name'            => (string) $user->display_name,
            'staff_email'           => (string) $user->user_email,
            'support_group_number'  => $group ? absint($group['group_number'] ?? 0) : 0,
            'is_group_coordinator'  => eventosapp_support_user_is_group_coordinator($event_id, $user->ID) ? 1 : 0,
            'reason'                => $reason,
            'source'                => 'frontend',
            'created_at_gmt'        => gmdate('Y-m-d H:i:s'),
            'created_at_local'      => $local_dt->format('Y-m-d H:i:s'),
            'created_date'          => $local_dt->format('Y-m-d'),
            'created_hour'          => $local_dt->format('H:00'),
        ],
        [
            '%d','%d','%s','%s','%s','%s','%s','%s','%d','%s','%s','%d','%d','%s','%s','%s','%s','%s','%s'
        ]
    );

    if ( ! $inserted ) {
        wp_send_json_error(['message' => 'No se pudo guardar la atención.'], 500);
    }

    wp_send_json_success([
        'message' => 'Atención registrada correctamente.',
        'id'      => absint($wpdb->insert_id),
        'time'    => $local_dt->format('Y-m-d H:i:s'),
    ]);
});

// =====================================================
// UI frontend compartida: Asistencia / Métricas de apoyo
// =====================================================
if ( ! function_exists('eventosapp_support_frontend_icon') ) {
    function eventosapp_support_frontend_icon( $name ) {
        switch ( $name ) {
            case 'back':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg>';

            case 'calendar':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M7 3v4M17 3v4M3 9h18"></path></svg>';

            case 'qr':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4z"></path><path d="M14 14h2v2h-2zM18 14h2v2h-2zM16 18h2v2h-2zM20 18h1v2h-1z"></path></svg>';

            case 'id':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"></rect><circle cx="8" cy="11" r="2.3"></circle><path d="M5.5 16c.6-1.6 1.4-2.5 2.5-2.5s1.9.9 2.5 2.5M13 10h5M13 14h4"></path></svg>';

            case 'support':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4z"></path><path d="M8 9h8M8 13h5"></path><path d="m16 15 1.5 1.5L21 13"></path></svg>';

            case 'chart':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"></path></svg>';

            case 'users':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 20c.7-3.3 2.5-5 5.5-5s4.8 1.7 5.5 5"></path><circle cx="17" cy="7.5" r="2.2"></circle><path d="M15.5 14.8c.5-.2 1-.3 1.5-.3 2.2 0 3.6 1.4 4 4"></path></svg>';

            case 'clock':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>';

            case 'download':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"></path><path d="m7 10 5 5 5-5"></path><path d="M5 21h14"></path></svg>';

            case 'search':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>';

            case 'check':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>';

            case 'close':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17"></path></svg>';

            default:
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v4M12 16h.01"></path></svg>';
        }
    }
}

if ( ! function_exists('eventosapp_support_frontend_print_styles') ) {
    function eventosapp_support_frontend_print_styles() {
        static $printed = false;
        if ( $printed ) return;
        $printed = true;
        ?>
        <style id="eventosapp-support-frontend-ui">
            .evapp-support-app{
                --evapp-primary:#3279bd;
                --evapp-primary-dark:#255f96;
                --evapp-primary-soft:#eaf4ff;
                --evapp-app-bg:#f5f8fc;
                --evapp-surface:#ffffff;
                --evapp-border:#dfe7f1;
                --evapp-text:#182230;
                --evapp-muted:#64748b;
                --evapp-success:#15803d;
                --evapp-success-soft:#ecfdf3;
                --evapp-danger:#b42318;
                --evapp-danger-soft:#fff1f0;
                --evapp-warning:#b45309;
                --evapp-warning-soft:#fff8e7;
                --evapp-radius:18px;
                --evapp-radius-lg:26px;
                width:100%;
                max-width:1180px;
                margin:0 auto;
                color:var(--evapp-text);
                font-family:inherit;
                line-height:1.45;
                box-sizing:border-box;
                isolation:isolate;
            }
            .evapp-support-app *,
            .evapp-support-app *::before,
            .evapp-support-app *::after{box-sizing:border-box}
            .evapp-support-app a{text-decoration:none}
            .evapp-support-app svg{
                display:block;
                fill:none;
                stroke:currentColor;
                stroke-width:2;
                stroke-linecap:round;
                stroke-linejoin:round;
            }
            .evapp-support-shell{
                width:100%;
                padding:clamp(18px,3vw,36px);
                background:var(--evapp-app-bg);
                border:1px solid var(--evapp-border);
                border-radius:var(--evapp-radius-lg);
                box-shadow:0 18px 50px rgba(31,52,73,.08);
            }
            .evapp-support-header{
                display:flex;
                align-items:flex-start;
                justify-content:space-between;
                gap:24px;
                margin-bottom:22px;
            }
            .evapp-support-heading{min-width:0}
            .evapp-support-eyebrow{
                margin:0 0 6px;
                color:var(--evapp-primary);
                font-size:12px;
                font-weight:800;
                letter-spacing:.11em;
                text-transform:uppercase;
            }
            .evapp-support-main-title{
                margin:0;
                color:var(--evapp-text);
                font-size:clamp(27px,4vw,42px);
                font-weight:850;
                line-height:1.08;
                letter-spacing:-.035em;
            }
            .evapp-support-subtitle{
                max-width:780px;
                margin:10px 0 0;
                color:var(--evapp-muted);
                font-size:15px;
                line-height:1.6;
            }
            .evapp-support-header-actions{flex:0 0 auto}
            .evapp-support-btn{
                min-height:44px;
                display:inline-flex;
                align-items:center;
                justify-content:center;
                gap:9px;
                margin:0;
                padding:10px 15px;
                border:1px solid transparent;
                border-radius:12px;
                font:inherit;
                font-size:14px;
                font-weight:750;
                line-height:1.15;
                text-align:center;
                cursor:pointer;
                transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease,background .16s ease,color .16s ease,opacity .16s ease;
                -webkit-tap-highlight-color:transparent;
            }
            .evapp-support-btn svg{width:18px;height:18px;flex:0 0 18px}
            .evapp-support-btn:hover:not(:disabled){transform:translateY(-1px)}
            .evapp-support-btn:focus-visible,
            .evapp-support-method:focus-visible,
            .evapp-support-result:focus-visible,
            .evapp-support-input:focus-visible,
            .evapp-support-textarea:focus-visible{
                outline:3px solid rgba(50,121,189,.20);
                outline-offset:2px;
            }
            .evapp-support-btn:disabled{opacity:.55;cursor:not-allowed;transform:none!important;box-shadow:none!important}
            .evapp-support-btn-primary{
                color:#fff!important;
                background:var(--evapp-primary);
                border-color:var(--evapp-primary);
                box-shadow:0 9px 20px rgba(50,121,189,.18);
            }
            .evapp-support-btn-primary:hover{
                color:#fff!important;
                background:var(--evapp-primary-dark);
                border-color:var(--evapp-primary-dark);
                box-shadow:0 12px 24px rgba(50,121,189,.24);
            }
            .evapp-support-btn-secondary{
                color:var(--evapp-text)!important;
                background:var(--evapp-surface);
                border-color:var(--evapp-border);
                box-shadow:0 5px 15px rgba(31,65,99,.05);
                white-space:nowrap;
            }
            .evapp-support-btn-secondary:hover{
                color:var(--evapp-primary-dark)!important;
                border-color:#c7d7e8;
                box-shadow:0 8px 20px rgba(31,65,99,.09);
            }
            .evapp-support-btn-danger{
                color:#fff!important;
                background:var(--evapp-danger);
                border-color:var(--evapp-danger);
                box-shadow:0 9px 20px rgba(180,35,24,.16);
            }
            .evapp-support-event-context{
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:16px;
                margin-bottom:20px;
                padding:16px 18px;
                background:var(--evapp-surface);
                border:1px solid var(--evapp-border);
                border-radius:var(--evapp-radius);
                box-shadow:0 8px 24px rgba(31,52,73,.045);
            }
            .evapp-support-event-main{
                min-width:0;
                display:flex;
                align-items:center;
                gap:13px;
            }
            .evapp-support-event-icon{
                width:44px;
                height:44px;
                flex:0 0 44px;
                display:grid;
                place-items:center;
                color:var(--evapp-primary);
                background:var(--evapp-primary-soft);
                border-radius:13px;
            }
            .evapp-support-event-icon svg{width:22px;height:22px}
            .evapp-support-event-copy{min-width:0}
            .evapp-support-event-kicker{
                display:block;
                margin-bottom:3px;
                color:var(--evapp-muted);
                font-size:11px;
                font-weight:800;
                letter-spacing:.09em;
                text-transform:uppercase;
            }
            .evapp-support-event-name{
                display:block;
                overflow:hidden;
                color:var(--evapp-text);
                font-size:15px;
                font-weight:800;
                line-height:1.3;
                text-overflow:ellipsis;
                white-space:nowrap;
            }
            .evapp-support-event-meta{
                display:flex;
                align-items:center;
                justify-content:flex-end;
                flex-wrap:wrap;
                gap:8px;
            }
            .evapp-support-chip{
                min-height:30px;
                display:inline-flex;
                align-items:center;
                gap:7px;
                padding:6px 10px;
                border:1px solid var(--evapp-border);
                border-radius:999px;
                background:#fff;
                color:var(--evapp-muted);
                font-size:12px;
                font-weight:750;
                white-space:nowrap;
            }
            .evapp-support-chip::before{
                width:7px;
                height:7px;
                flex:0 0 7px;
                border-radius:50%;
                background:var(--evapp-primary);
                content:"";
            }
            .evapp-support-card{
                min-width:0;
                padding:20px;
                background:var(--evapp-surface);
                border:1px solid var(--evapp-border);
                border-radius:var(--evapp-radius);
                box-shadow:0 8px 26px rgba(31,52,73,.05);
            }
            .evapp-support-card + .evapp-support-card{margin-top:16px}
            .evapp-support-card-head{
                display:flex;
                align-items:flex-start;
                justify-content:space-between;
                gap:16px;
                margin-bottom:16px;
            }
            .evapp-support-section-heading{
                min-width:0;
                display:flex;
                align-items:flex-start;
                gap:12px;
            }
            .evapp-support-section-icon{
                width:40px;
                height:40px;
                flex:0 0 40px;
                display:grid;
                place-items:center;
                color:var(--evapp-primary);
                background:var(--evapp-primary-soft);
                border-radius:12px;
            }
            .evapp-support-section-icon svg{width:20px;height:20px}
            .evapp-support-section-title{
                margin:0;
                color:var(--evapp-text);
                font-size:17px;
                font-weight:820;
                line-height:1.3;
            }
            .evapp-support-section-desc{
                margin:5px 0 0;
                color:var(--evapp-muted);
                font-size:13px;
                line-height:1.5;
            }
            .evapp-support-methods{
                display:grid;
                grid-template-columns:repeat(2,minmax(0,1fr));
                gap:12px;
            }
            .evapp-support-method{
                width:100%;
                min-width:0;
                min-height:106px;
                display:flex;
                align-items:center;
                gap:14px;
                padding:16px;
                border:1px solid var(--evapp-border);
                border-radius:16px;
                background:#fff;
                color:var(--evapp-text);
                font:inherit;
                text-align:left;
                cursor:pointer;
                box-shadow:0 6px 18px rgba(31,52,73,.035);
                transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease,background .16s ease;
            }
            .evapp-support-method:hover{
                transform:translateY(-1px);
                border-color:#c5d8ea;
                box-shadow:0 10px 24px rgba(31,73,112,.08);
            }
            .evapp-support-method.is-active{
                border-color:#a9cbea;
                background:var(--evapp-primary-soft);
                box-shadow:0 0 0 3px rgba(50,121,189,.08);
            }
            .evapp-support-method.is-danger{
                border-color:#f2b8b5;
                background:var(--evapp-danger-soft);
            }
            .evapp-support-method-icon{
                width:48px;
                height:48px;
                flex:0 0 48px;
                display:grid;
                place-items:center;
                color:var(--evapp-primary);
                background:var(--evapp-primary-soft);
                border-radius:14px;
            }
            .evapp-support-method.is-active .evapp-support-method-icon{
                color:#fff;
                background:var(--evapp-primary);
            }
            .evapp-support-method.is-danger .evapp-support-method-icon{
                color:#fff;
                background:var(--evapp-danger);
            }
            .evapp-support-method-icon svg{width:24px;height:24px}
            .evapp-support-method-copy{min-width:0}
            .evapp-support-method-title{
                display:block;
                color:inherit;
                font-size:15px;
                font-weight:820;
                line-height:1.3;
            }
            .evapp-support-method-desc{
                display:block;
                margin-top:4px;
                color:var(--evapp-muted);
                font-size:12px;
                line-height:1.45;
            }
            .evapp-support-camera{
                display:none;
                margin-top:16px;
                overflow:hidden;
                background:#07111f;
                border:1px solid #1e293b;
                border-radius:18px;
                box-shadow:0 10px 30px rgba(15,23,42,.16);
            }
            .evapp-support-camera-head{
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:12px;
                padding:12px 14px;
                color:#dbeafe;
                background:#0f172a;
                font-size:12px;
            }
            .evapp-support-camera-head strong{color:#fff;font-size:13px}
            .evapp-support-camera-stage{
                position:relative;
                min-height:280px;
                aspect-ratio:16/9;
                overflow:hidden;
            }
            .evapp-support-camera video{
                width:100%;
                height:100%;
                display:block;
                object-fit:cover;
            }
            .evapp-support-frame{
                position:absolute;
                inset:0;
                display:grid;
                place-items:center;
                pointer-events:none;
                background:radial-gradient(ellipse 48% 52% at 50% 50%,rgba(255,255,255,0) 61%,rgba(3,7,18,.58) 63%);
            }
            .evapp-support-scan-guide{
                width:min(54%,300px);
                aspect-ratio:1;
                border:2px solid rgba(255,255,255,.92);
                border-radius:22px;
                box-shadow:0 0 0 1px rgba(50,121,189,.36),0 0 28px rgba(50,121,189,.24);
            }
            .evapp-support-search{
                display:none;
                margin-top:16px;
                padding:16px;
                border:1px solid var(--evapp-border);
                border-radius:16px;
                background:#fbfdff;
            }
            .evapp-support-field-label{
                display:block;
                margin:0 0 7px;
                color:var(--evapp-text);
                font-size:12px;
                font-weight:800;
            }
            .evapp-support-input-wrap{position:relative;min-width:0}
            .evapp-support-input,
            .evapp-support-textarea{
                width:100%;
                margin:0;
                color:var(--evapp-text);
                background:#fff;
                border:1px solid var(--evapp-border);
                border-radius:13px;
                box-shadow:none;
                font:inherit;
                font-size:15px;
                outline:none;
                transition:border-color .16s ease,box-shadow .16s ease;
            }
            .evapp-support-input{
                min-height:48px;
                padding:10px 46px;
            }
            .evapp-support-textarea{
                min-height:120px;
                padding:12px 13px;
                line-height:1.55;
                resize:vertical;
            }
            .evapp-support-input:focus,
            .evapp-support-textarea:focus{
                border-color:var(--evapp-primary);
                box-shadow:0 0 0 4px rgba(50,121,189,.12);
            }
            .evapp-support-search-icon{
                position:absolute;
                z-index:2;
                top:50%;
                left:15px;
                width:20px;
                height:20px;
                color:var(--evapp-muted);
                transform:translateY(-50%);
                pointer-events:none;
            }
            .evapp-support-search-icon svg{width:20px;height:20px}
            .evapp-support-clear{
                position:absolute;
                z-index:3;
                top:50%;
                right:8px;
                width:32px;
                height:32px;
                display:none;
                align-items:center;
                justify-content:center;
                padding:0;
                border:0;
                border-radius:9px;
                color:var(--evapp-muted);
                background:transparent;
                cursor:pointer;
                transform:translateY(-50%);
            }
            .evapp-support-clear.is-visible{display:flex}
            .evapp-support-clear:hover{color:var(--evapp-text);background:var(--evapp-primary-soft)}
            .evapp-support-clear svg{width:17px;height:17px}
            .evapp-support-search-foot{
                display:flex;
                align-items:flex-start;
                justify-content:space-between;
                gap:12px;
                margin-top:8px;
                color:var(--evapp-muted);
                font-size:11px;
                line-height:1.45;
            }
            .evapp-support-result-count{font-weight:750;white-space:nowrap}
            .evapp-support-results{
                display:grid;
                gap:9px;
                margin-top:12px;
            }
            .evapp-support-result{
                width:100%;
                display:grid;
                grid-template-columns:auto minmax(0,1fr) auto;
                align-items:center;
                gap:12px;
                padding:13px;
                border:1px solid var(--evapp-border);
                border-radius:14px;
                background:#fff;
                color:var(--evapp-text);
                font:inherit;
                text-align:left;
                cursor:pointer;
                transition:border-color .16s ease,box-shadow .16s ease,transform .16s ease;
            }
            .evapp-support-result:hover{
                transform:translateY(-1px);
                border-color:#c5d8ea;
                box-shadow:0 8px 20px rgba(31,73,112,.07);
            }
            .evapp-support-result-avatar{
                width:42px;
                height:42px;
                display:grid;
                place-items:center;
                border-radius:12px;
                color:var(--evapp-primary-dark);
                background:var(--evapp-primary-soft);
                font-size:12px;
                font-weight:850;
                letter-spacing:.03em;
            }
            .evapp-support-result-copy{min-width:0}
            .evapp-support-result-name{
                display:block;
                color:var(--evapp-text);
                font-size:14px;
                font-weight:800;
                line-height:1.3;
            }
            .evapp-support-result-meta{
                display:block;
                margin-top:3px;
                color:var(--evapp-muted);
                font-size:11px;
                line-height:1.4;
                overflow-wrap:anywhere;
            }
            .evapp-support-result-arrow{
                width:32px;
                height:32px;
                display:grid;
                place-items:center;
                border-radius:10px;
                color:var(--evapp-primary);
                background:var(--evapp-primary-soft);
            }
            .evapp-support-result-arrow svg{width:16px;height:16px}
            .evapp-support-selected{
                display:none;
                margin-top:16px;
                padding:16px;
                border:1px solid #cfe3f6;
                border-radius:16px;
                background:var(--evapp-primary-soft);
            }
            .evapp-support-selected-head{
                display:flex;
                align-items:flex-start;
                justify-content:space-between;
                gap:12px;
                margin-bottom:12px;
            }
            .evapp-support-selected-title{
                margin:0;
                color:var(--evapp-text);
                font-size:15px;
                font-weight:820;
            }
            .evapp-support-selected-subtitle{
                margin:3px 0 0;
                color:var(--evapp-muted);
                font-size:11px;
            }
            .evapp-support-selected-clear{
                min-height:36px;
                padding:7px 10px;
                font-size:11px;
            }
            .evapp-support-data-grid{
                display:grid;
                grid-template-columns:repeat(2,minmax(0,1fr));
                gap:10px;
            }
            .evapp-support-data-item{
                min-width:0;
                padding:10px 11px;
                border:1px solid rgba(196,219,238,.9);
                border-radius:12px;
                background:rgba(255,255,255,.78);
            }
            .evapp-support-data-label{
                display:block;
                margin-bottom:3px;
                color:var(--evapp-muted);
                font-size:10px;
                font-weight:800;
                letter-spacing:.05em;
                text-transform:uppercase;
            }
            .evapp-support-data-value{
                display:block;
                color:var(--evapp-text);
                font-size:12px;
                font-weight:750;
                line-height:1.4;
                overflow-wrap:anywhere;
            }
            .evapp-support-reason{
                display:none;
                margin-top:16px;
                padding:16px;
                border:1px solid var(--evapp-border);
                border-radius:16px;
                background:#fff;
            }
            .evapp-support-reason-actions{
                display:flex;
                align-items:center;
                justify-content:flex-end;
                gap:10px;
                margin-top:10px;
            }
            .evapp-support-status{
                display:flex;
                align-items:flex-start;
                gap:9px;
                margin-top:14px;
                padding:11px 13px;
                border:1px solid var(--evapp-border);
                border-radius:12px;
                background:#fff;
                color:var(--evapp-muted);
                font-size:12px;
                font-weight:700;
                line-height:1.45;
            }
            .evapp-support-status:empty{display:none}
            .evapp-support-status.is-success{
                color:#0f5132;
                border-color:#b7e4c7;
                background:var(--evapp-success-soft);
            }
            .evapp-support-status.is-error{
                color:#8b1e17;
                border-color:#f2b8b5;
                background:var(--evapp-danger-soft);
            }
            .evapp-support-status.is-muted{
                color:var(--evapp-muted);
                border-color:var(--evapp-border);
                background:#fff;
            }
            .evapp-support-empty{
                padding:14px;
                border:1px dashed var(--evapp-border);
                border-radius:13px;
                color:var(--evapp-muted);
                background:#fbfdff;
                font-size:12px;
                line-height:1.5;
            }
            .evapp-support-summary{
                display:grid;
                grid-template-columns:repeat(3,minmax(0,1fr));
                gap:12px;
                margin-bottom:16px;
            }
            .evapp-support-kpi-card{
                min-width:0;
                padding:16px;
                border:1px solid var(--evapp-border);
                border-radius:16px;
                background:#fff;
                box-shadow:0 6px 18px rgba(31,52,73,.035);
            }
            .evapp-support-kpi-icon{
                width:38px;
                height:38px;
                display:grid;
                place-items:center;
                margin-bottom:12px;
                color:var(--evapp-primary);
                background:var(--evapp-primary-soft);
                border-radius:12px;
            }
            .evapp-support-kpi-icon svg{width:19px;height:19px}
            .evapp-support-kpi-label{
                display:block;
                color:var(--evapp-muted);
                font-size:11px;
                font-weight:750;
                line-height:1.3;
            }
            .evapp-support-kpi-value{
                display:block;
                margin-top:4px;
                color:var(--evapp-text);
                font-size:clamp(20px,3vw,28px);
                font-weight:850;
                line-height:1.15;
                overflow-wrap:anywhere;
            }
            .evapp-support-kpi-detail{
                display:block;
                margin-top:4px;
                color:var(--evapp-muted);
                font-size:10px;
                line-height:1.4;
            }
            .evapp-support-chart{
                display:grid;
                gap:9px;
            }
            .evapp-support-bar-row{
                display:grid;
                grid-template-columns:64px minmax(0,1fr) 50px;
                gap:10px;
                align-items:center;
            }
            .evapp-support-bar-hour{
                color:var(--evapp-text);
                font-size:12px;
                font-weight:800;
                white-space:nowrap;
            }
            .evapp-support-bar-track{
                height:22px;
                overflow:hidden;
                border:1px solid #e2e8f0;
                border-radius:999px;
                background:#f1f5f9;
            }
            .evapp-support-bar{
                height:100%;
                min-width:4px;
                border-radius:999px;
                background:linear-gradient(90deg,var(--evapp-primary),#5f9fd8);
            }
            .evapp-support-bar-total{
                color:var(--evapp-muted);
                font-size:12px;
                font-weight:800;
                text-align:right;
            }
            .evapp-support-download-row{
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:14px;
                margin-top:16px;
                padding:14px 15px;
                border:1px solid #cfe3f6;
                border-radius:14px;
                background:var(--evapp-primary-soft);
            }
            .evapp-support-download-copy{min-width:0}
            .evapp-support-download-copy strong{
                display:block;
                color:var(--evapp-text);
                font-size:12px;
                font-weight:800;
            }
            .evapp-support-download-copy span{
                display:block;
                margin-top:2px;
                color:var(--evapp-muted);
                font-size:10px;
                line-height:1.45;
            }
            .evapp-support-table-wrap{
                width:100%;
                overflow:hidden;
                border:1px solid var(--evapp-border);
                border-radius:14px;
            }
            .evapp-support-ranking-table{
                width:100%;
                margin:0;
                border:0;
                border-collapse:collapse;
                background:#fff;
                font-size:13px;
            }
            .evapp-support-ranking-table th,
            .evapp-support-ranking-table td{
                padding:12px 13px;
                border:0;
                border-bottom:1px solid var(--evapp-border);
                text-align:left;
                vertical-align:middle;
            }
            .evapp-support-ranking-table th{
                color:#475569;
                background:#f8fafc;
                font-size:11px;
                font-weight:800;
                letter-spacing:.025em;
                text-transform:uppercase;
            }
            .evapp-support-ranking-table tbody tr:last-child td{border-bottom:0}
            .evapp-support-ranking-table tbody tr:hover{background:#fbfdff}
            .evapp-support-rank{
                width:36px;
                height:28px;
                display:inline-grid;
                place-items:center;
                border-radius:9px;
                color:var(--evapp-primary-dark);
                background:var(--evapp-primary-soft);
                font-size:11px;
                font-weight:850;
            }
            .evapp-support-user-name{
                color:var(--evapp-text);
                font-weight:800;
            }
            .evapp-support-user-email{
                color:var(--evapp-muted);
                overflow-wrap:anywhere;
            }
            .evapp-support-count-badge{
                min-width:34px;
                min-height:28px;
                display:inline-flex;
                align-items:center;
                justify-content:center;
                padding:4px 8px;
                border-radius:999px;
                color:var(--evapp-primary-dark);
                background:var(--evapp-primary-soft);
                font-size:12px;
                font-weight:850;
            }
            .evapp-support-notice-shell{
                width:100%;
                max-width:900px;
                margin:0 auto;
                padding:18px;
                background:var(--evapp-app-bg);
                border:1px solid var(--evapp-border);
                border-radius:20px;
                box-shadow:0 12px 36px rgba(31,52,73,.07);
            }
            .evapp-support-notice{
                padding:14px 16px;
                border:1px solid #f1dfad;
                border-radius:14px;
                color:#7c4a03;
                background:var(--evapp-warning-soft);
                font-size:13px;
                line-height:1.5;
            }
            .evapp-support-notice.is-error{
                color:#8b1e17;
                border-color:#f2b8b5;
                background:var(--evapp-danger-soft);
            }
            .evapp-support-notice a{color:var(--evapp-primary-dark);font-weight:800}
            @media(max-width:900px){
                .evapp-support-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
                .evapp-support-summary .evapp-support-kpi-card:first-child{grid-column:1/-1}
            }
            @media(max-width:767px){
                .evapp-support-shell{padding:16px;border-radius:20px}
                .evapp-support-header{display:block;margin-bottom:18px}
                .evapp-support-header-actions{margin-top:14px}
                .evapp-support-header-actions .evapp-support-btn{width:100%}
                .evapp-support-event-context{
                    align-items:flex-start;
                    flex-direction:column;
                    padding:14px;
                }
                .evapp-support-event-main{width:100%}
                .evapp-support-event-meta{
                    width:100%;
                    justify-content:flex-start;
                }
                .evapp-support-event-meta .evapp-support-btn{width:100%}
                .evapp-support-methods{grid-template-columns:1fr}
                .evapp-support-camera-stage{aspect-ratio:4/5;min-height:340px}
                .evapp-support-download-row{
                    align-items:stretch;
                    flex-direction:column;
                }
                .evapp-support-download-row .evapp-support-btn{width:100%}
            }
            @media(max-width:620px){
                .evapp-support-main-title{font-size:clamp(28px,9vw,36px)}
                .evapp-support-card{padding:16px}
                .evapp-support-card-head{display:block}
                .evapp-support-data-grid{grid-template-columns:1fr}
                .evapp-support-summary{grid-template-columns:1fr}
                .evapp-support-summary .evapp-support-kpi-card:first-child{grid-column:auto}
                .evapp-support-search-foot{
                    flex-direction:column;
                    gap:4px;
                }
                .evapp-support-result{
                    grid-template-columns:auto minmax(0,1fr);
                }
                .evapp-support-result-arrow{display:none}
                .evapp-support-reason-actions{display:grid}
                .evapp-support-reason-actions .evapp-support-btn{width:100%}
                .evapp-support-bar-row{
                    grid-template-columns:52px minmax(0,1fr) 38px;
                    gap:7px;
                }
                .evapp-support-table-wrap{
                    overflow:visible;
                    border:0;
                    border-radius:0;
                    background:transparent;
                }
                .evapp-support-ranking-table,
                .evapp-support-ranking-table tbody,
                .evapp-support-ranking-table tr,
                .evapp-support-ranking-table td{
                    display:block;
                    width:100%;
                }
                .evapp-support-ranking-table thead{display:none}
                .evapp-support-ranking-table tbody{
                    display:grid;
                    gap:10px;
                }
                .evapp-support-ranking-table tr{
                    padding:12px;
                    border:1px solid var(--evapp-border);
                    border-radius:14px;
                    background:#fff;
                }
                .evapp-support-ranking-table td{
                    display:flex;
                    align-items:flex-start;
                    justify-content:space-between;
                    gap:14px;
                    padding:7px 0;
                    border:0;
                    border-bottom:1px dashed #e7edf4;
                    text-align:right;
                }
                .evapp-support-ranking-table td:last-child{border-bottom:0}
                .evapp-support-ranking-table td::before{
                    flex:0 0 auto;
                    color:var(--evapp-muted);
                    font-size:10px;
                    font-weight:800;
                    letter-spacing:.04em;
                    text-transform:uppercase;
                    content:attr(data-label);
                }
            }
            @media(max-width:430px){
                .evapp-support-chip{white-space:normal}
                .evapp-support-event-meta{
                    align-items:stretch;
                    flex-direction:column;
                }
                .evapp-support-method{align-items:flex-start}
                .evapp-support-method-icon{
                    width:42px;
                    height:42px;
                    flex-basis:42px;
                }
                .evapp-support-camera-stage{min-height:300px}
            }
            @media(prefers-reduced-motion:reduce){
                .evapp-support-app *,
                .evapp-support-app *::before,
                .evapp-support-app *::after{
                    scroll-behavior:auto!important;
                    transition-duration:.01ms!important;
                    animation-duration:.01ms!important;
                    animation-iteration-count:1!important;
                }
            }
        </style>
        <?php
    }
}

if ( ! function_exists('eventosapp_support_frontend_notice_html') ) {
    function eventosapp_support_frontend_notice_html( $message, $type = 'warning', $dashboard_url = '' ) {
        ob_start();
        eventosapp_support_frontend_print_styles();
        ?>
        <div class="evapp-support-app">
            <div class="evapp-support-notice-shell">
                <div class="evapp-support-notice <?php echo $type === 'error' ? 'is-error' : ''; ?>" role="<?php echo $type === 'error' ? 'alert' : 'status'; ?>">
                    <?php echo wp_kses_post($message); ?>
                </div>
                <?php if ( $dashboard_url ) : ?>
                    <div style="margin-top:12px;">
                        <a class="evapp-support-btn evapp-support-btn-secondary" href="<?php echo esc_url($dashboard_url); ?>">
                            <?php echo eventosapp_support_frontend_icon('back'); ?>
                            <span>Volver al dashboard</span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

if ( ! function_exists('eventosapp_support_frontend_navigation') ) {
    function eventosapp_support_frontend_navigation() {
        $dashboard_url = function_exists('eventosapp_get_dashboard_url')
            ? eventosapp_get_dashboard_url()
            : home_url('/');

        if ( ! $dashboard_url || $dashboard_url === '#' ) {
            $dashboard_url = home_url('/');
        }

        $dashboard_url = remove_query_arg(['evapp', 'evapp_err', 'set', 'from'], $dashboard_url);
        $change_event_url = add_query_arg(['evapp' => 'change_event'], $dashboard_url);

        return [
            'dashboard_url'    => $dashboard_url,
            'change_event_url' => $change_event_url,
        ];
    }
}

// =====================================================
// Shortcode: Asistencia
// =====================================================
add_shortcode('eventosapp_support_assistance', function($atts){
    $atts = shortcode_atts(['event_id' => 0], $atts);
    $event_id = eventosapp_support_current_event_from_request($atts['event_id']);
    $nav = eventosapp_support_frontend_navigation();

    if ( ! $event_id ) {
        return eventosapp_support_frontend_notice_html(
            'Debes escoger un evento en el <a href="' . esc_url($nav['dashboard_url']) . '">dashboard</a> antes de registrar atenciones.',
            'warning',
            $nav['dashboard_url']
        );
    }

    if ( ! eventosapp_support_user_can_feature_for_event($event_id, 'support_assistance') ) {
        return eventosapp_support_frontend_notice_html(
            'No tienes permisos para registrar atenciones en este evento.',
            'error',
            $nav['dashboard_url']
        );
    }

    if ( ! wp_script_is('jsqr', 'registered') ) {
        wp_register_script('jsqr', 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js', [], '1.4.0', true);
    }
    wp_enqueue_script('jsqr');

    $nonce = wp_create_nonce('eventosapp_support_assistance');
    $event_name = get_the_title($event_id);
    $user_group = eventosapp_support_get_user_group($event_id, get_current_user_id());
    $group_number = $user_group ? absint($user_group['group_number'] ?? 0) : 0;
    $instance_id = 'evapp-support-assistance-' . wp_unique_id();

    ob_start();
    eventosapp_support_frontend_print_styles();
    ?>
    <div
        id="<?php echo esc_attr($instance_id); ?>"
        class="evapp-support-app evapp-support-assistance-app"
        data-event-id="<?php echo esc_attr($event_id); ?>"
        data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
        data-nonce="<?php echo esc_attr($nonce); ?>"
    >
        <div class="evapp-support-shell">
            <header class="evapp-support-header">
                <div class="evapp-support-heading">
                    <p class="evapp-support-eyebrow">EVENTOSAPP</p>
                    <h1 class="evapp-support-main-title">Asistencia</h1>
                    <p class="evapp-support-subtitle">
                        Identifica al asistente y registra el motivo de su consulta sin alterar su estado de check-in.
                    </p>
                </div>

                <div class="evapp-support-header-actions">
                    <a
                        href="<?php echo esc_url($nav['dashboard_url']); ?>"
                        class="evapp-support-btn evapp-support-btn-secondary"
                        aria-label="Volver al dashboard"
                    >
                        <?php echo eventosapp_support_frontend_icon('back'); ?>
                        <span>Volver al dashboard</span>
                    </a>
                </div>
            </header>

            <section class="evapp-support-event-context" aria-label="Evento activo">
                <div class="evapp-support-event-main">
                    <div class="evapp-support-event-icon" aria-hidden="true">
                        <?php echo eventosapp_support_frontend_icon('calendar'); ?>
                    </div>

                    <div class="evapp-support-event-copy">
                        <span class="evapp-support-event-kicker">Evento activo</span>
                        <strong class="evapp-support-event-name"><?php echo esc_html($event_name ?: ('Evento #' . $event_id)); ?></strong>
                    </div>
                </div>

                <div class="evapp-support-event-meta">
                    <?php if ( $group_number ) : ?>
                        <span class="evapp-support-chip">Grupo <?php echo esc_html($group_number); ?></span>
                    <?php endif; ?>
                    <a class="evapp-support-btn evapp-support-btn-primary" href="<?php echo esc_url($nav['change_event_url']); ?>">
                        Cambiar evento
                    </a>
                </div>
            </section>

            <section class="evapp-support-card" aria-labelledby="<?php echo esc_attr($instance_id); ?>-identify-title">
                <div class="evapp-support-card-head">
                    <div class="evapp-support-section-heading">
                        <div class="evapp-support-section-icon" aria-hidden="true">
                            <?php echo eventosapp_support_frontend_icon('support'); ?>
                        </div>
                        <div>
                            <h2 id="<?php echo esc_attr($instance_id); ?>-identify-title" class="evapp-support-section-title">Identificar asistente</h2>
                            <p class="evapp-support-section-desc">
                                Usa el QR del ticket o busca por cédula. La lectura solo identifica al asistente para esta atención.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="evapp-support-methods" role="group" aria-label="Método de identificación">
                    <button
                        type="button"
                        class="evapp-support-method"
                        id="evappSupportScanBtn"
                        aria-expanded="false"
                        aria-controls="evappSupportCamera"
                    >
                        <span class="evapp-support-method-icon" aria-hidden="true">
                            <?php echo eventosapp_support_frontend_icon('qr'); ?>
                        </span>
                        <span class="evapp-support-method-copy">
                            <span class="evapp-support-method-title">Leer QR con cámara</span>
                            <span class="evapp-support-method-desc">Ideal cuando el asistente tiene su ticket disponible.</span>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="evapp-support-method"
                        id="evappSupportCedulaBtn"
                        aria-expanded="false"
                        aria-controls="evappSupportSearch"
                    >
                        <span class="evapp-support-method-icon" aria-hidden="true">
                            <?php echo eventosapp_support_frontend_icon('id'); ?>
                        </span>
                        <span class="evapp-support-method-copy">
                            <span class="evapp-support-method-title">Identificar por cédula</span>
                            <span class="evapp-support-method-desc">Busca coincidencias dentro del evento activo.</span>
                        </span>
                    </button>
                </div>

                <div class="evapp-support-camera" id="evappSupportCamera" aria-hidden="true">
                    <div class="evapp-support-camera-head">
                        <strong>Escáner QR</strong>
                        <span>Centra el código dentro del recuadro</span>
                    </div>
                    <div class="evapp-support-camera-stage">
                        <video id="evappSupportVideo" playsinline muted></video>
                        <div class="evapp-support-frame" aria-hidden="true">
                            <div class="evapp-support-scan-guide"></div>
                        </div>
                    </div>
                </div>

                <div class="evapp-support-search" id="evappSupportSearch" aria-hidden="true">
                    <label class="evapp-support-field-label" for="evappSupportCedula">Cédula del asistente</label>

                    <div class="evapp-support-input-wrap">
                        <span class="evapp-support-search-icon" aria-hidden="true">
                            <?php echo eventosapp_support_frontend_icon('search'); ?>
                        </span>
                        <input
                            type="search"
                            id="evappSupportCedula"
                            class="evapp-support-input"
                            placeholder="Escribe la cédula del asistente"
                            autocomplete="off"
                            autocapitalize="off"
                            spellcheck="false"
                            inputmode="numeric"
                        >
                        <button type="button" class="evapp-support-clear" id="evappSupportClearSearch" aria-label="Limpiar búsqueda">
                            <?php echo eventosapp_support_frontend_icon('close'); ?>
                        </button>
                    </div>

                    <div class="evapp-support-search-foot">
                        <span>La búsqueda inicia desde 2 caracteres. Presiona Enter para buscar de inmediato.</span>
                        <span class="evapp-support-result-count" id="evappSupportResultCount" aria-live="polite"></span>
                    </div>

                    <div class="evapp-support-results" id="evappSupportResults" aria-live="polite" aria-busy="false"></div>
                </div>

                <div class="evapp-support-selected" id="evappSupportSelected"></div>

                <div class="evapp-support-reason" id="evappSupportReasonBox">
                    <label class="evapp-support-field-label" for="evappSupportReason">Razón de la consulta del asistente</label>
                    <textarea
                        id="evappSupportReason"
                        class="evapp-support-textarea"
                        placeholder="Describe brevemente la razón de la consulta"
                        maxlength="2000"
                    ></textarea>

                    <div class="evapp-support-reason-actions">
                        <button type="button" class="evapp-support-btn evapp-support-btn-primary" id="evappSupportRegisterBtn">
                            <?php echo eventosapp_support_frontend_icon('check'); ?>
                            <span class="evapp-support-register-label">Registrar atención</span>
                        </button>
                    </div>
                </div>

                <div
                    class="evapp-support-status"
                    id="evappSupportStatus"
                    role="status"
                    aria-live="polite"
                    aria-atomic="true"
                ></div>
            </section>
        </div>
    </div>

    <script>
    (function(){
        const panel = document.getElementById(<?php echo wp_json_encode($instance_id); ?>);
        if (!panel) return;

        const ajaxURL = panel.dataset.ajaxUrl || '';
        const nonce = panel.dataset.nonce || '';
        const eventID = parseInt(panel.dataset.eventId, 10) || 0;

        const scanBtn = panel.querySelector('#evappSupportScanBtn');
        const cedulaBtn = panel.querySelector('#evappSupportCedulaBtn');
        const cameraBox = panel.querySelector('#evappSupportCamera');
        const video = panel.querySelector('#evappSupportVideo');
        const searchBox = panel.querySelector('#evappSupportSearch');
        const cedulaInput = panel.querySelector('#evappSupportCedula');
        const clearSearchBtn = panel.querySelector('#evappSupportClearSearch');
        const resultCount = panel.querySelector('#evappSupportResultCount');
        const resultsBox = panel.querySelector('#evappSupportResults');
        const selectedBox = panel.querySelector('#evappSupportSelected');
        const reasonBox = panel.querySelector('#evappSupportReasonBox');
        const reasonInput = panel.querySelector('#evappSupportReason');
        const registerBtn = panel.querySelector('#evappSupportRegisterBtn');
        const registerLabel = registerBtn ? registerBtn.querySelector('.evapp-support-register-label') : null;
        const statusBox = panel.querySelector('#evappSupportStatus');

        if (!scanBtn || !cedulaBtn || !cameraBox || !video || !searchBox || !cedulaInput || !resultsBox || !selectedBox || !reasonBox || !reasonInput || !registerBtn || !statusBox) {
            return;
        }

        let stream = null;
        let scanning = false;
        let selectedTicket = null;
        let searchTimer = null;
        let searchController = null;
        let searchSequence = 0;
        let scanRaf = 0;
        let lastScanAt = 0;
        let barcodeDetector = null;

        const scanCanvas = document.createElement('canvas');
        const scanContext = scanCanvas.getContext('2d', {willReadFrequently:true});

        if ('BarcodeDetector' in window) {
            try {
                barcodeDetector = new BarcodeDetector({formats:['qr_code']});
            } catch (error) {
                barcodeDetector = null;
            }
        }

        function esc(text){
            return String(text == null ? '' : text).replace(/[&<>'"]/g, function(c){
                return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];
            });
        }

        function initials(firstName, lastName){
            const first = String(firstName || '').trim().charAt(0);
            const last = String(lastName || '').trim().charAt(0);
            return (first + last).toUpperCase() || 'AS';
        }

        function setStatus(message, type){
            statusBox.className = 'evapp-support-status ' + (
                type === 'error'
                    ? 'is-error'
                    : (type === 'ok' ? 'is-success' : 'is-muted')
            );
            statusBox.textContent = message || '';
        }

        function setMethodState(button, active, danger){
            if (!button) return;
            button.classList.toggle('is-active', !!active && !danger);
            button.classList.toggle('is-danger', !!danger);
            button.setAttribute('aria-expanded', active ? 'true' : 'false');
        }

        function setResultsBusy(isBusy){
            resultsBox.setAttribute('aria-busy', isBusy ? 'true' : 'false');
        }

        function updateClearSearch(){
            if (!clearSearchBtn) return;
            clearSearchBtn.classList.toggle('is-visible', cedulaInput.value.trim() !== '');
        }

        function stopCamera(options){
            options = options || {};
            scanning = false;

            if (scanRaf) {
                cancelAnimationFrame(scanRaf);
                scanRaf = 0;
            }

            if (stream) {
                stream.getTracks().forEach(function(track){
                    try { track.stop(); } catch (error) {}
                });
                stream = null;
            }

            if (video.srcObject) {
                video.srcObject = null;
            }

            cameraBox.style.display = 'none';
            cameraBox.setAttribute('aria-hidden', 'true');
            setMethodState(scanBtn, false, false);

            const title = scanBtn.querySelector('.evapp-support-method-title');
            const desc = scanBtn.querySelector('.evapp-support-method-desc');
            if (title) title.textContent = 'Leer QR con cámara';
            if (desc) desc.textContent = 'Ideal cuando el asistente tiene su ticket disponible.';

            if (!options.keepStatus && options.status) {
                setStatus(options.status, options.statusType || 'muted');
            }
        }

        function closeSearch(){
            searchBox.style.display = 'none';
            searchBox.setAttribute('aria-hidden', 'true');
            setMethodState(cedulaBtn, false, false);
        }

        function openSearch(){
            stopCamera({keepStatus:true});
            searchBox.style.display = 'block';
            searchBox.setAttribute('aria-hidden', 'false');
            setMethodState(cedulaBtn, true, false);
            cedulaInput.focus();
            updateClearSearch();
        }

        function clearSelection(options){
            options = options || {};
            selectedTicket = null;
            selectedBox.style.display = 'none';
            selectedBox.innerHTML = '';
            reasonBox.style.display = 'none';
            reasonInput.value = '';

            if (!options.keepStatus) {
                setStatus('', 'muted');
            }
        }

        function resetSearchResults(){
            resultsBox.innerHTML = '';
            resultCount.textContent = '';
            setResultsBusy(false);
        }

        async function startCamera(){
            if (scanning) {
                stopCamera({status:'Cámara detenida.', statusType:'muted'});
                return;
            }

            closeSearch();
            clearSelection({keepStatus:true});
            resetSearchResults();
            setStatus('Activando cámara…', 'muted');

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                setStatus('Este navegador no permite acceder a la cámara desde esta página.', 'error');
                return;
            }

            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video:{
                        facingMode:{ideal:'environment'},
                        width:{ideal:1280},
                        height:{ideal:720}
                    },
                    audio:false
                });

                video.srcObject = stream;
                await video.play();

                scanning = true;
                cameraBox.style.display = 'block';
                cameraBox.setAttribute('aria-hidden', 'false');
                setMethodState(scanBtn, true, true);

                const title = scanBtn.querySelector('.evapp-support-method-title');
                const desc = scanBtn.querySelector('.evapp-support-method-desc');
                if (title) title.textContent = 'Detener cámara';
                if (desc) desc.textContent = 'La cámara está activa y buscando un código QR.';

                setStatus('Cámara activa. Enfoca el QR del asistente.', 'muted');
                lastScanAt = 0;
                scanRaf = requestAnimationFrame(scanLoop);
            } catch (error) {
                stopCamera({keepStatus:true});
                setStatus('No se pudo acceder a la cámara. Revisa los permisos del navegador e intenta nuevamente.', 'error');
            }
        }

        async function scanLoop(timestamp){
            if (!scanning) return;

            // Limita el análisis a ~7 veces por segundo para reducir consumo de CPU
            // en dispositivos donde se usa jsQR como fallback.
            if ((timestamp - lastScanAt) < 140) {
                scanRaf = requestAnimationFrame(scanLoop);
                return;
            }
            lastScanAt = timestamp;

            try {
                if (barcodeDetector && video.readyState >= 2) {
                    const codes = await barcodeDetector.detect(video);
                    if (codes && codes.length && codes[0].rawValue) {
                        identifyByQR(codes[0].rawValue);
                        return;
                    }
                } else if (window.jsQR && scanContext && video.videoWidth && video.videoHeight) {
                    if (scanCanvas.width !== video.videoWidth || scanCanvas.height !== video.videoHeight) {
                        scanCanvas.width = video.videoWidth;
                        scanCanvas.height = video.videoHeight;
                    }

                    scanContext.drawImage(video, 0, 0, scanCanvas.width, scanCanvas.height);
                    const imageData = scanContext.getImageData(0, 0, scanCanvas.width, scanCanvas.height);
                    const code = window.jsQR(imageData.data, scanCanvas.width, scanCanvas.height);

                    if (code && code.data) {
                        identifyByQR(code.data);
                        return;
                    }
                }
            } catch (error) {
                // Un frame inválido no debe detener el lector; el siguiente intento continúa.
            }

            if (scanning) {
                scanRaf = requestAnimationFrame(scanLoop);
            }
        }

        function postForm(data){
            const fd = new FormData();
            Object.keys(data).forEach(function(key){
                fd.append(key, data[key]);
            });
            fd.append('security', nonce);
            fd.append('event_id', eventID);

            return fetch(ajaxURL, {
                method:'POST',
                body:fd,
                credentials:'same-origin'
            }).then(function(response){
                return response.json();
            });
        }

        function identifyByQR(code){
            if (!code || !scanning) return;

            stopCamera({keepStatus:true});
            setStatus('Identificando asistente…', 'muted');

            postForm({
                action:'eventosapp_support_identify_qr',
                code:code
            }).then(function(resp){
                if (!resp || !resp.success) {
                    setStatus(
                        (resp && resp.data && resp.data.message)
                            ? resp.data.message
                            : 'No se pudo identificar el asistente.',
                        'error'
                    );
                    return;
                }

                selectTicket(resp.data);
                setStatus('Asistente identificado por QR.', 'ok');
            }).catch(function(){
                setStatus('Error de red al identificar por QR.', 'error');
            });
        }

        function selectTicket(ticket){
            selectedTicket = ticket || null;
            if (!selectedTicket || !selectedTicket.ticket_id) return;

            closeSearch();
            resetSearchResults();

            const fullName = ((ticket.first_name || '') + ' ' + (ticket.last_name || '')).trim() || 'Asistente';
            selectedBox.style.display = 'block';
            selectedBox.innerHTML =
                '<div class="evapp-support-selected-head">' +
                    '<div>' +
                        '<h3 class="evapp-support-selected-title">Asistente seleccionado</h3>' +
                        '<p class="evapp-support-selected-subtitle">Verifica los datos antes de registrar la atención.</p>' +
                    '</div>' +
                    '<button type="button" class="evapp-support-btn evapp-support-btn-secondary evapp-support-selected-clear" data-evapp-clear-selection>Quitar selección</button>' +
                '</div>' +
                '<div class="evapp-support-data-grid">' +
                    '<div class="evapp-support-data-item"><span class="evapp-support-data-label">Nombre</span><span class="evapp-support-data-value">' + esc(fullName) + '</span></div>' +
                    '<div class="evapp-support-data-item"><span class="evapp-support-data-label">Cédula</span><span class="evapp-support-data-value">' + esc(ticket.cc || '—') + '</span></div>' +
                    '<div class="evapp-support-data-item"><span class="evapp-support-data-label">Ticket</span><span class="evapp-support-data-value">' + esc(ticket.ticket_code || '—') + '</span></div>' +
                    '<div class="evapp-support-data-item"><span class="evapp-support-data-label">Correo</span><span class="evapp-support-data-value">' + esc(ticket.email || '—') + '</span></div>' +
                    '<div class="evapp-support-data-item"><span class="evapp-support-data-label">Teléfono</span><span class="evapp-support-data-value">' + esc(ticket.phone || '—') + '</span></div>' +
                '</div>';

            reasonBox.style.display = 'block';
            reasonInput.value = '';
            reasonInput.focus();
        }

        function renderResults(items){
            resultsBox.innerHTML = '';
            setResultsBusy(false);

            if (!items || !items.length) {
                resultCount.textContent = '0 resultados';
                resultsBox.innerHTML = '<div class="evapp-support-empty">No encontramos asistentes con esa cédula dentro del evento activo.</div>';
                return;
            }

            resultCount.textContent = items.length + (items.length === 1 ? ' resultado' : ' resultados');

            items.forEach(function(item){
                const fullName = ((item.first_name || '') + ' ' + (item.last_name || '')).trim() || 'Asistente';
                const contact = [];
                if (item.email) contact.push(item.email);
                if (item.phone) contact.push(item.phone);

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'evapp-support-result';
                btn.innerHTML =
                    '<span class="evapp-support-result-avatar" aria-hidden="true">' + esc(initials(item.first_name, item.last_name)) + '</span>' +
                    '<span class="evapp-support-result-copy">' +
                        '<span class="evapp-support-result-name">' + esc(fullName) + '</span>' +
                        '<span class="evapp-support-result-meta">Cédula: ' + esc(item.cc || '—') + ' · Ticket: ' + esc(item.ticket_code || '—') + '</span>' +
                        (contact.length ? '<span class="evapp-support-result-meta">' + esc(contact.join(' · ')) + '</span>' : '') +
                    '</span>' +
                    '<span class="evapp-support-result-arrow" aria-hidden="true">' +
                        '<svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"></path></svg>' +
                    '</span>';

                btn.addEventListener('click', function(){
                    selectTicket(item);
                    setStatus('Asistente seleccionado por cédula.', 'ok');
                });

                resultsBox.appendChild(btn);
            });
        }

        function searchByCedula(immediate){
            clearTimeout(searchTimer);
            updateClearSearch();

            const q = cedulaInput.value.trim();
            if (searchController) {
                searchController.abort();
                searchController = null;
            }

            if (q.length < 2) {
                resultCount.textContent = '';
                resultsBox.innerHTML = q.length
                    ? '<div class="evapp-support-empty">Escribe al menos 2 caracteres para iniciar la búsqueda.</div>'
                    : '';
                setResultsBusy(false);
                setStatus('', 'muted');
                return;
            }

            const delay = immediate ? 0 : 280;
            searchTimer = setTimeout(function(){
                const sequence = ++searchSequence;
                setStatus('Buscando asistente…', 'muted');
                setResultsBusy(true);
                resultCount.textContent = '';
                resultsBox.innerHTML = '<div class="evapp-support-empty">Buscando coincidencias…</div>';

                if ('AbortController' in window) {
                    searchController = new AbortController();
                }

                const url = ajaxURL
                    + '?action=eventosapp_support_search_attendee'
                    + '&security=' + encodeURIComponent(nonce)
                    + '&event_id=' + encodeURIComponent(eventID)
                    + '&q=' + encodeURIComponent(q);

                fetch(url, {
                    credentials:'same-origin',
                    signal:searchController ? searchController.signal : undefined
                }).then(function(response){
                    return response.json();
                }).then(function(resp){
                    if (sequence !== searchSequence) return;

                    if (!resp || !resp.success) {
                        setResultsBusy(false);
                        setStatus(
                            (resp && resp.data && resp.data.message)
                                ? resp.data.message
                                : 'No se pudo completar la búsqueda.',
                            'error'
                        );
                        return;
                    }

                    renderResults(resp.data || []);
                    setStatus('', 'muted');
                }).catch(function(error){
                    if (error && error.name === 'AbortError') return;
                    if (sequence !== searchSequence) return;

                    setResultsBusy(false);
                    setStatus('Error de red al buscar por cédula.', 'error');
                }).finally(function(){
                    if (sequence === searchSequence) {
                        searchController = null;
                    }
                });
            }, delay);
        }

        scanBtn.addEventListener('click', startCamera);

        cedulaBtn.addEventListener('click', function(){
            if (searchBox.style.display === 'block') {
                closeSearch();
                return;
            }
            openSearch();
        });

        cedulaInput.addEventListener('input', function(){
            searchByCedula(false);
        });

        cedulaInput.addEventListener('keydown', function(event){
            if (event.key === 'Enter') {
                event.preventDefault();
                searchByCedula(true);
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                cedulaInput.value = '';
                resetSearchResults();
                updateClearSearch();
                setStatus('', 'muted');
            }
        });

        if (clearSearchBtn) {
            clearSearchBtn.addEventListener('click', function(){
                cedulaInput.value = '';
                resetSearchResults();
                updateClearSearch();
                setStatus('', 'muted');
                cedulaInput.focus();
            });
        }

        selectedBox.addEventListener('click', function(event){
            const clearButton = event.target.closest('[data-evapp-clear-selection]');
            if (!clearButton) return;

            clearSelection();
            setStatus('Selección eliminada. Puedes identificar otro asistente.', 'muted');
        });

        registerBtn.addEventListener('click', function(){
            if (!selectedTicket || !selectedTicket.ticket_id) {
                setStatus('Primero debes identificar un asistente.', 'error');
                return;
            }

            const reason = reasonInput.value.trim();
            if (!reason) {
                setStatus('Debes escribir la razón de la consulta.', 'error');
                reasonInput.focus();
                return;
            }

            registerBtn.disabled = true;
            if (registerLabel) registerLabel.textContent = 'Registrando…';
            setStatus('Guardando atención…', 'muted');

            postForm({
                action:'eventosapp_support_register_attention',
                ticket_id:selectedTicket.ticket_id,
                reason:reason
            }).then(function(resp){
                if (!resp || !resp.success) {
                    setStatus(
                        (resp && resp.data && resp.data.message)
                            ? resp.data.message
                            : 'No se pudo registrar la atención.',
                        'error'
                    );
                    return;
                }

                clearSelection({keepStatus:true});
                resetSearchResults();
                cedulaInput.value = '';
                updateClearSearch();
                setStatus('Atención registrada correctamente. Ya puedes atender a la siguiente persona.', 'ok');
            }).catch(function(){
                setStatus('Error de red al registrar la atención.', 'error');
            }).finally(function(){
                registerBtn.disabled = false;
                if (registerLabel) registerLabel.textContent = 'Registrar atención';
            });
        });

        window.addEventListener('pagehide', function(){
            stopCamera({keepStatus:true});
            if (searchController) {
                searchController.abort();
                searchController = null;
            }
        });

        document.addEventListener('visibilitychange', function(){
            if (document.hidden && scanning) {
                stopCamera({keepStatus:true});
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
});

// =====================================================
// Shortcode: Métricas equipo de apoyo
// =====================================================
add_shortcode('eventosapp_support_team_metrics', function($atts){
    $atts = shortcode_atts(['event_id' => 0], $atts);
    $event_id = eventosapp_support_current_event_from_request($atts['event_id']);
    $nav = eventosapp_support_frontend_navigation();

    if ( ! $event_id ) {
        return eventosapp_support_frontend_notice_html(
            'Debes escoger un evento en el <a href="' . esc_url($nav['dashboard_url']) . '">dashboard</a> antes de ver métricas.',
            'warning',
            $nav['dashboard_url']
        );
    }

    if ( ! eventosapp_support_user_can_feature_for_event($event_id, 'support_team_metrics') ) {
        return eventosapp_support_frontend_notice_html(
            'No tienes permisos para ver las métricas del equipo de apoyo.',
            'error',
            $nav['dashboard_url']
        );
    }

    $scope = eventosapp_support_get_metrics_scope($event_id, get_current_user_id());
    if ( ! $scope ) {
        return eventosapp_support_frontend_notice_html(
            'No tienes permisos para ver las métricas del equipo de apoyo.',
            'error',
            $nav['dashboard_url']
        );
    }

    global $wpdb;
    $table = eventosapp_support_table_name();
    $where_params = [];
    $where_sql = eventosapp_support_metrics_where_sql($event_id, $scope, $where_params);

    $by_hour = $wpdb->get_results( eventosapp_support_prepare_query(
        "SELECT created_hour, COUNT(*) AS total
           FROM {$table}
          WHERE {$where_sql}
          GROUP BY created_hour
          ORDER BY created_hour ASC",
        $where_params
    ) );

    $top_users = $wpdb->get_results( eventosapp_support_prepare_query(
        "SELECT staff_user_id, staff_name, staff_email, COUNT(*) AS total
           FROM {$table}
          WHERE {$where_sql}
          GROUP BY staff_user_id, staff_name, staff_email
          ORDER BY total DESC, staff_name ASC
          LIMIT 10",
        $where_params
    ) );

    $total = (int) $wpdb->get_var(
        eventosapp_support_prepare_query(
            "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
            $where_params
        )
    );

    $max_hour = 0;
    $peak_hour = '';
    $peak_hour_total = 0;

    foreach ( $by_hour as $row ) {
        $row_total = (int) $row->total;
        $max_hour = max($max_hour, $row_total);

        if ( $row_total > $peak_hour_total ) {
            $peak_hour_total = $row_total;
            $peak_hour = (string) $row->created_hour;
        }
    }

    if ( $max_hour < 1 ) $max_hour = 1;

    $leader_name = '';
    $leader_total = 0;
    if ( ! empty($top_users[0]) ) {
        $leader_name = (string) ($top_users[0]->staff_name ?: ('Usuario #' . $top_users[0]->staff_user_id));
        $leader_total = (int) $top_users[0]->total;
    }

    $can_download_csv = eventosapp_support_user_can_download_csv($event_id);
    $download_url = $can_download_csv ? eventosapp_support_get_download_csv_url($event_id) : '';
    $event_name = get_the_title($event_id);
    $instance_id = 'evapp-support-metrics-' . wp_unique_id();

    ob_start();
    eventosapp_support_frontend_print_styles();
    ?>
    <div id="<?php echo esc_attr($instance_id); ?>" class="evapp-support-app evapp-support-metrics-app">
        <div class="evapp-support-shell">
            <header class="evapp-support-header">
                <div class="evapp-support-heading">
                    <p class="evapp-support-eyebrow">EVENTOSAPP</p>
                    <h1 class="evapp-support-main-title">Métricas de equipo de apoyo</h1>
                    <p class="evapp-support-subtitle">
                        Consulta el volumen de atenciones, los horarios de mayor demanda y el desempeño del equipo dentro del alcance asignado.
                    </p>
                </div>

                <div class="evapp-support-header-actions">
                    <a
                        href="<?php echo esc_url($nav['dashboard_url']); ?>"
                        class="evapp-support-btn evapp-support-btn-secondary"
                        aria-label="Volver al dashboard"
                    >
                        <?php echo eventosapp_support_frontend_icon('back'); ?>
                        <span>Volver al dashboard</span>
                    </a>
                </div>
            </header>

            <section class="evapp-support-event-context" aria-label="Evento activo">
                <div class="evapp-support-event-main">
                    <div class="evapp-support-event-icon" aria-hidden="true">
                        <?php echo eventosapp_support_frontend_icon('calendar'); ?>
                    </div>

                    <div class="evapp-support-event-copy">
                        <span class="evapp-support-event-kicker">Evento activo</span>
                        <strong class="evapp-support-event-name"><?php echo esc_html($event_name ?: ('Evento #' . $event_id)); ?></strong>
                    </div>
                </div>

                <div class="evapp-support-event-meta">
                    <span class="evapp-support-chip"><?php echo esc_html($scope['label'] ?? ''); ?></span>
                    <a class="evapp-support-btn evapp-support-btn-primary" href="<?php echo esc_url($nav['change_event_url']); ?>">
                        Cambiar evento
                    </a>
                </div>
            </section>

            <section class="evapp-support-summary" aria-label="Resumen de métricas">
                <article class="evapp-support-kpi-card">
                    <div class="evapp-support-kpi-icon" aria-hidden="true">
                        <?php echo eventosapp_support_frontend_icon('chart'); ?>
                    </div>
                    <span class="evapp-support-kpi-label">Total de atenciones</span>
                    <strong class="evapp-support-kpi-value"><?php echo esc_html(number_format_i18n($total)); ?></strong>
                    <span class="evapp-support-kpi-detail"><?php echo esc_html($scope['label'] ?? ''); ?></span>
                </article>

                <article class="evapp-support-kpi-card">
                    <div class="evapp-support-kpi-icon" aria-hidden="true">
                        <?php echo eventosapp_support_frontend_icon('clock'); ?>
                    </div>
                    <span class="evapp-support-kpi-label">Hora de mayor demanda</span>
                    <strong class="evapp-support-kpi-value"><?php echo esc_html($peak_hour ?: '—'); ?></strong>
                    <span class="evapp-support-kpi-detail">
                        <?php echo $peak_hour ? esc_html(number_format_i18n($peak_hour_total) . ' atenciones') : 'Sin datos todavía'; ?>
                    </span>
                </article>

                <article class="evapp-support-kpi-card">
                    <div class="evapp-support-kpi-icon" aria-hidden="true">
                        <?php echo eventosapp_support_frontend_icon('users'); ?>
                    </div>
                    <span class="evapp-support-kpi-label">Mayor número de atenciones</span>
                    <strong class="evapp-support-kpi-value" style="font-size:18px;"><?php echo esc_html($leader_name ?: '—'); ?></strong>
                    <span class="evapp-support-kpi-detail">
                        <?php echo $leader_name ? esc_html(number_format_i18n($leader_total) . ' atenciones') : 'Sin datos todavía'; ?>
                    </span>
                </article>
            </section>

            <section class="evapp-support-card" aria-labelledby="<?php echo esc_attr($instance_id); ?>-hours-title">
                <div class="evapp-support-card-head">
                    <div class="evapp-support-section-heading">
                        <div class="evapp-support-section-icon" aria-hidden="true">
                            <?php echo eventosapp_support_frontend_icon('clock'); ?>
                        </div>
                        <div>
                            <h2 id="<?php echo esc_attr($instance_id); ?>-hours-title" class="evapp-support-section-title">Atenciones realizadas por hora</h2>
                            <p class="evapp-support-section-desc">Distribución de las consultas registradas durante el evento.</p>
                        </div>
                    </div>
                </div>

                <?php if ( $by_hour ) : ?>
                    <div class="evapp-support-chart">
                        <?php foreach ( $by_hour as $row ) : ?>
                            <?php
                            $row_total = (int) $row->total;
                            $pct = min(100, max(1, round(($row_total / $max_hour) * 100)));
                            ?>
                            <div
                                class="evapp-support-bar-row"
                                aria-label="<?php echo esc_attr($row->created_hour . ': ' . $row_total . ' atenciones'); ?>"
                            >
                                <strong class="evapp-support-bar-hour"><?php echo esc_html($row->created_hour); ?></strong>
                                <div class="evapp-support-bar-track" aria-hidden="true">
                                    <div class="evapp-support-bar" style="width:<?php echo esc_attr($pct); ?>%;"></div>
                                </div>
                                <span class="evapp-support-bar-total"><?php echo esc_html(number_format_i18n($row_total)); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <div class="evapp-support-empty">Aún no hay atenciones registradas para graficar.</div>
                <?php endif; ?>

                <?php if ( $can_download_csv && $download_url ) : ?>
                    <div class="evapp-support-download-row">
                        <div class="evapp-support-download-copy">
                            <strong>Base completa de consultas</strong>
                            <span>Disponible para administradores y usuarios del Equipo del Organizador.</span>
                        </div>
                        <a class="evapp-support-btn evapp-support-btn-primary" href="<?php echo esc_url($download_url); ?>">
                            <?php echo eventosapp_support_frontend_icon('download'); ?>
                            <span>Descargar CSV</span>
                        </a>
                    </div>
                <?php endif; ?>
            </section>

            <section class="evapp-support-card" aria-labelledby="<?php echo esc_attr($instance_id); ?>-ranking-title">
                <div class="evapp-support-card-head">
                    <div class="evapp-support-section-heading">
                        <div class="evapp-support-section-icon" aria-hidden="true">
                            <?php echo eventosapp_support_frontend_icon('users'); ?>
                        </div>
                        <div>
                            <h2 id="<?php echo esc_attr($instance_id); ?>-ranking-title" class="evapp-support-section-title">Usuarios con más atenciones</h2>
                            <p class="evapp-support-section-desc">Ranking de hasta 10 integrantes según el número de consultas registradas.</p>
                        </div>
                    </div>
                </div>

                <?php if ( $top_users ) : ?>
                    <div class="evapp-support-table-wrap">
                        <table class="evapp-support-ranking-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Usuario</th>
                                    <th>Correo</th>
                                    <th>Atenciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $pos = 1; foreach ( $top_users as $row ) : ?>
                                    <tr>
                                        <td data-label="Posición">
                                            <span class="evapp-support-rank"><?php echo esc_html($pos++); ?></span>
                                        </td>
                                        <td data-label="Usuario">
                                            <span class="evapp-support-user-name"><?php echo esc_html($row->staff_name ?: ('Usuario #' . $row->staff_user_id)); ?></span>
                                        </td>
                                        <td data-label="Correo">
                                            <span class="evapp-support-user-email"><?php echo esc_html($row->staff_email ?: '—'); ?></span>
                                        </td>
                                        <td data-label="Atenciones">
                                            <span class="evapp-support-count-badge"><?php echo esc_html(number_format_i18n((int) $row->total)); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <div class="evapp-support-empty">Aún no hay usuarios con atenciones registradas.</div>
                <?php endif; ?>
            </section>
        </div>
    </div>
    <?php
    return ob_get_clean();
});

// =====================================================
// Descarga CSV
// =====================================================
if ( ! function_exists('eventosapp_support_handle_download_csv') ) {
    function eventosapp_support_handle_download_csv() {
        $event_id = absint($_GET['event_id'] ?? 0);
        if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
            wp_die('Evento inválido.', '', 400);
        }

        check_admin_referer('eventosapp_support_download_csv_' . $event_id);

        if ( ! eventosapp_support_user_can_download_csv($event_id) ) {
            wp_die('No tienes permisos para descargar esta base de consultas.', '', 403);
        }

        $scope = ['type' => 'all', 'group_number' => 0, 'label' => 'Vista total del evento'];

        global $wpdb;
        $table = eventosapp_support_table_name();
        $where_params = [];
        $where_sql = eventosapp_support_metrics_where_sql($event_id, $scope, $where_params);

        $rows = $wpdb->get_results( eventosapp_support_prepare_query(
            "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id ASC",
            $where_params
        ), ARRAY_A );

        $filename = 'eventosapp-consultas-evento-' . $event_id . '-' . gmdate('Ymd-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        if ( ! $out ) {
            exit;
        }

        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($out, [
            'ID',
            'Usuario que hizo la atención',
            'Correo usuario',
            'Evento',
            'ID Evento',
            'Nombre asistente',
            'Apellido asistente',
            'Cédula asistente',
            'Ticket',
            'Correo asistente',
            'Teléfono asistente',
            'Motivo de la consulta',
            'Fecha de la consulta',
            'Hora de la consulta',
            'Grupo',
            'Es coordinador',
        ]);

        $event_name = get_the_title($event_id);
        foreach ( $rows as $row ) {
            $local = isset($row['created_at_local']) ? (string) $row['created_at_local'] : '';
            $date = $local ? substr($local, 0, 10) : '';
            $time = $local ? substr($local, 11, 8) : '';

            fputcsv($out, [
                $row['id'] ?? '',
                $row['staff_name'] ?? '',
                $row['staff_email'] ?? '',
                $event_name,
                $event_id,
                $row['attendee_first_name'] ?? '',
                $row['attendee_last_name'] ?? '',
                $row['attendee_cc'] ?? '',
                $row['ticket_code'] ?? '',
                $row['attendee_email'] ?? '',
                $row['attendee_phone'] ?? '',
                $row['reason'] ?? '',
                $date,
                $time,
                $row['support_group_number'] ?? '',
                ! empty($row['is_group_coordinator']) ? 'Sí' : 'No',
            ]);
        }

        fclose($out);
        exit;
    }
}

if ( ! function_exists('eventosapp_support_handle_frontend_download_csv') ) {
    function eventosapp_support_handle_frontend_download_csv() {
        if ( ! eventosapp_support_is_frontend_download_csv_request() ) {
            return;
        }

        eventosapp_support_handle_download_csv();
    }
}

add_action('init', 'eventosapp_support_handle_frontend_download_csv', -1000);
add_action('admin_post_eventosapp_support_download_csv', 'eventosapp_support_handle_download_csv');
