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
            'meta_query'     => [[
                'key'     => '_eventosapp_support_staff_groups',
                'compare' => 'EXISTS',
            ]],
        ]);

        foreach ( $events as $event_id ) {
            if ( eventosapp_support_user_is_assigned_to_event($event_id, $user_id) ) {
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

        if ( user_can($user_id, 'manage_options') || user_can($user_id, 'edit_others_posts') ) {
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

        if ( eventosapp_support_user_can_admin_event_without_support($event_id, $user_id) ) {
            return true;
        }

        $is_support = eventosapp_support_user_is_assigned_to_event($event_id, $user_id);
        if ( ! $is_support ) return false;

        if ( $feature === 'support_assistance' || $feature === 'dashboard' ) {
            return true;
        }

        if ( $feature === 'support_team_metrics' ) {
            return eventosapp_support_user_is_group_coordinator($event_id, $user_id);
        }

        return false;
    }
}

/**
 * Permisos dinámicos para staff de apoyo:
 * - Staff asignado como apoyo: solo Dashboard + Asistencia.
 * - Coordinador de grupo: Dashboard + Asistencia + Métricas de equipo de apoyo.
 */
add_filter('eventosapp_role_can', function($has_permission, $feature, $user){
    if ( ! $user || ! $user instanceof WP_User || ! $user->exists() ) {
        return $has_permission;
    }

    $support_features = ['support_assistance', 'support_team_metrics'];

    $active_event = 0;
    if ( function_exists('eventosapp_get_active_event') ) {
        $active_event = absint( eventosapp_get_active_event() );
    }

    if ( ! $active_event ) {
        if ( $feature === 'dashboard' && eventosapp_support_user_has_any_event($user->ID) ) {
            return true;
        }
        return $has_permission;
    }

    $is_support = eventosapp_support_user_is_assigned_to_event($active_event, $user->ID);

    // Las nuevas secciones solo se muestran si el usuario administra el evento
    // o si fue asignado en este metabox de apoyo.
    if ( in_array($feature, $support_features, true) ) {
        return eventosapp_support_user_can_feature_for_event($active_event, $feature, $user->ID);
    }

    if ( ! $is_support ) {
        return $has_permission;
    }

    if ( eventosapp_support_user_can_admin_event_without_support($active_event, $user->ID) ) {
        return $has_permission;
    }

    if ( $feature === 'dashboard' || $feature === 'support_assistance' ) {
        return true;
    }

    if ( $feature === 'support_team_metrics' ) {
        return eventosapp_support_user_is_group_coordinator($active_event, $user->ID);
    }

    // Un staff de apoyo no debe ver el resto de secciones de este evento.
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
        $download_url = wp_nonce_url(
            add_query_arg([
                'action'   => 'eventosapp_support_download_csv',
                'event_id' => $event_id,
            ], admin_url('admin-post.php')),
            'eventosapp_support_download_csv_' . $event_id
        );
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
            .evapp-support-select{width:100%;min-height:130px;}
            .evapp-support-full{width:100%;}
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
            <h4>Agregar Grupo <?php echo esc_html($next_group); ?></h4>
            <div class="evapp-support-grid">
                <div>
                    <label for="evapp_support_new_members"><strong>Seleccionar varios usuarios staff</strong></label>
                    <select id="evapp_support_new_members" name="evapp_support_new_members[]" class="evapp-support-select" multiple>
                        <?php foreach ( $staff_users as $staff ) : ?>
                            <?php
                            $uid = absint($staff->ID);
                            $already_support   = in_array($uid, $support_ids, true);
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
                            $already_support   = in_array($uid, $support_ids, true);
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
        </div>

        <div class="evapp-support-box">
            <h4>Atenciones registradas en este evento</h4>
            <p>
                <a class="button button-secondary" href="<?php echo esc_url($download_url); ?>">Descargar base de consultas CSV</a>
            </p>
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

    $groups = eventosapp_support_get_groups($post_id);

    $remove_numbers = isset($_POST['evapp_support_remove_groups']) ? array_map('absint', (array) $_POST['evapp_support_remove_groups']) : [];
    if ( $remove_numbers ) {
        $groups = array_values(array_filter($groups, function($group) use ($remove_numbers){
            return ! in_array(absint($group['group_number'] ?? 0), $remove_numbers, true);
        }));
    }

    $co_staff_ids = eventosapp_support_get_cogestion_staff_user_ids($post_id);
    $existing_support_ids = [];
    foreach ( $groups as $group ) {
        foreach ( (array) ($group['members'] ?? []) as $uid ) {
            $existing_support_ids[] = absint($uid);
        }
    }
    $existing_support_ids = array_values(array_unique(array_filter($existing_support_ids)));

    $new_members = isset($_POST['evapp_support_new_members']) ? array_map('absint', (array) $_POST['evapp_support_new_members']) : [];
    $new_members = array_values(array_unique(array_filter($new_members)));

    $new_coordinator = isset($_POST['evapp_support_new_coordinator']) ? absint($_POST['evapp_support_new_coordinator']) : 0;
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
    }

    eventosapp_support_save_groups($post_id, $groups);
}, 40, 2);

// Enforce extra: si alguien intenta asignar en co-gestión a un staff de apoyo, se remueve de co-gestión.
add_action('save_post_eventosapp_event', function($post_id){
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;

    $support_ids = eventosapp_support_get_event_staff_user_ids($post_id);
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
// Shortcode: Asistencia
// =====================================================
add_shortcode('eventosapp_support_assistance', function($atts){
    if ( function_exists('eventosapp_require_feature') ) {
        eventosapp_require_feature('support_assistance');
    }

    $atts = shortcode_atts(['event_id' => 0], $atts);
    $event_id = eventosapp_support_current_event_from_request($atts['event_id']);

    if ( ! $event_id ) {
        $dashboard = function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/');
        return '<div class="evapp-support-notice">Debes escoger un evento en el <a href="'.esc_url($dashboard).'">dashboard</a> antes de registrar atenciones.</div>';
    }

    if ( ! eventosapp_support_user_can_feature_for_event($event_id, 'support_assistance') ) {
        return '<div class="evapp-support-notice evapp-support-notice-error">No tienes permisos para registrar atenciones en este evento.</div>';
    }

    if ( ! wp_script_is('jsqr', 'registered') ) {
        wp_register_script('jsqr', 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js', [], null, true);
    }
    wp_enqueue_script('jsqr');

    $nonce = wp_create_nonce('eventosapp_support_assistance');

    ob_start();
    if ( function_exists('eventosapp_active_event_bar') ) {
        eventosapp_active_event_bar();
    }
    ?>
    <style>
        .evapp-support-panel{max-width:860px;margin:0 auto;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#111827;}
        .evapp-support-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:18px;box-shadow:0 8px 26px rgba(15,23,42,.08);margin-bottom:16px;}
        .evapp-support-title{margin:0 0 8px;font-size:24px;font-weight:800;color:#10233f;}
        .evapp-support-help{margin:0 0 14px;color:#64748b;font-size:14px;line-height:1.45;}
        .evapp-support-actions{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;}
        .evapp-support-btn{border:0;border-radius:12px;padding:14px 16px;font-weight:800;font-size:16px;cursor:pointer;background:#2F73B5;color:#fff;box-shadow:0 4px 12px rgba(47,115,181,.22);}
        .evapp-support-btn:hover{filter:brightness(.96);}
        .evapp-support-btn.secondary{background:#0f766e;}
        .evapp-support-btn.danger{background:#dc2626;}
        .evapp-support-btn:disabled{opacity:.58;cursor:not-allowed;}
        .evapp-support-camera{display:none;margin-top:14px;background:#0b1020;border-radius:16px;overflow:hidden;position:relative;aspect-ratio:3/4;max-height:560px;}
        .evapp-support-camera video{width:100%;height:100%;object-fit:cover;display:block;}
        .evapp-support-frame{position:absolute;inset:0;pointer-events:none;background:radial-gradient(ellipse 62% 42% at 50% 50%,rgba(255,255,255,0) 62%,rgba(3,7,18,.58) 64%);}
        .evapp-support-search{display:none;margin-top:14px;}
        .evapp-support-input,.evapp-support-textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:12px;padding:12px 14px;font-size:16px;background:#fff;color:#111827;}
        .evapp-support-textarea{min-height:110px;resize:vertical;}
        .evapp-support-results{margin-top:10px;display:grid;gap:8px;}
        .evapp-support-result{border:1px solid #e5e7eb;background:#f8fafc;border-radius:12px;padding:12px;text-align:left;cursor:pointer;}
        .evapp-support-result:hover{background:#eef6ff;border-color:#bfdbfe;}
        .evapp-support-selected{display:none;border:1px solid #bae6fd;background:#f0f9ff;border-radius:14px;padding:14px;margin-top:14px;}
        .evapp-support-selected h3{margin:0 0 8px;color:#0f172a;}
        .evapp-support-grid{display:grid;grid-template-columns:150px 1fr;gap:5px 12px;font-size:14px;}
        .evapp-support-grid b{color:#334155;}
        .evapp-support-reason{display:none;margin-top:14px;}
        .evapp-support-status{margin-top:12px;font-weight:700;}
        .evapp-support-ok{color:#15803d;}
        .evapp-support-error{color:#b91c1c;}
        .evapp-support-muted{color:#64748b;}
        .evapp-support-notice{max-width:860px;margin:0 auto;padding:12px 14px;border-radius:12px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
        .evapp-support-notice-error{background:#fef2f2;border-color:#fecaca;color:#991b1b;}
        @media(max-width:700px){.evapp-support-actions{grid-template-columns:1fr}.evapp-support-grid{grid-template-columns:1fr}.evapp-support-btn{width:100%;}}
    </style>

    <div class="evapp-support-panel" data-event-id="<?php echo esc_attr($event_id); ?>" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
        <div class="evapp-support-card">
            <h2 class="evapp-support-title">Asistencia</h2>
            <p class="evapp-support-help">Identifica al asistente por QR o por cédula. Esta lectura no realiza check-in; solo selecciona al asistente para registrar la atención.</p>

            <div class="evapp-support-actions">
                <button type="button" class="evapp-support-btn" id="evappSupportScanBtn">Leer QR con cámara</button>
                <button type="button" class="evapp-support-btn secondary" id="evappSupportCedulaBtn">Identificar por Cédula</button>
            </div>

            <div class="evapp-support-camera" id="evappSupportCamera">
                <video id="evappSupportVideo" playsinline muted></video>
                <div class="evapp-support-frame"></div>
            </div>

            <div class="evapp-support-search" id="evappSupportSearch">
                <input type="text" id="evappSupportCedula" class="evapp-support-input" placeholder="Escribe la cédula del asistente" autocomplete="off">
                <div class="evapp-support-results" id="evappSupportResults"></div>
            </div>

            <div class="evapp-support-selected" id="evappSupportSelected"></div>

            <div class="evapp-support-reason" id="evappSupportReasonBox">
                <label for="evappSupportReason"><strong>Razón de la consulta del asistente</strong></label>
                <textarea id="evappSupportReason" class="evapp-support-textarea" placeholder="Describe la razón de la consulta"></textarea>
                <button type="button" class="evapp-support-btn" id="evappSupportRegisterBtn" style="margin-top:10px;">Registrar atención</button>
            </div>

            <div class="evapp-support-status" id="evappSupportStatus"></div>
        </div>
    </div>

    <script>
    (function(){
        const panel = document.querySelector('.evapp-support-panel[data-event-id="<?php echo esc_js($event_id); ?>"]');
        if (!panel) return;

        const ajaxURL = panel.dataset.ajaxUrl;
        const nonce = panel.dataset.nonce;
        const eventID = parseInt(panel.dataset.eventId, 10) || 0;

        const scanBtn = document.getElementById('evappSupportScanBtn');
        const cedulaBtn = document.getElementById('evappSupportCedulaBtn');
        const cameraBox = document.getElementById('evappSupportCamera');
        const video = document.getElementById('evappSupportVideo');
        const searchBox = document.getElementById('evappSupportSearch');
        const cedulaInput = document.getElementById('evappSupportCedula');
        const resultsBox = document.getElementById('evappSupportResults');
        const selectedBox = document.getElementById('evappSupportSelected');
        const reasonBox = document.getElementById('evappSupportReasonBox');
        const reasonInput = document.getElementById('evappSupportReason');
        const registerBtn = document.getElementById('evappSupportRegisterBtn');
        const statusBox = document.getElementById('evappSupportStatus');

        let stream = null;
        let scanning = false;
        let selectedTicket = null;
        let barcodeDetector = ('BarcodeDetector' in window) ? new BarcodeDetector({formats:['qr_code']}) : null;
        let searchTimer = null;

        function setStatus(message, type){
            statusBox.className = 'evapp-support-status ' + (type === 'error' ? 'evapp-support-error' : (type === 'ok' ? 'evapp-support-ok' : 'evapp-support-muted'));
            statusBox.textContent = message || '';
        }

        function esc(text){
            return String(text || '').replace(/[&<>'"]/g, function(c){
                return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];
            });
        }

        function stopCamera(){
            scanning = false;
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            cameraBox.style.display = 'none';
            scanBtn.textContent = 'Leer QR con cámara';
            scanBtn.classList.remove('danger');
        }

        async function startCamera(){
            if (scanning) {
                stopCamera();
                return;
            }
            stopCamera();
            searchBox.style.display = 'none';
            cameraBox.style.display = 'block';
            setStatus('Activando cámara…', 'muted');
            try {
                stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}}, audio:false});
                video.srcObject = stream;
                await video.play();
                scanning = true;
                scanBtn.textContent = 'Detener cámara';
                scanBtn.classList.add('danger');
                setStatus('Cámara activa. Enfoca el QR del asistente.', 'muted');
                requestAnimationFrame(scanLoop);
            } catch (e) {
                cameraBox.style.display = 'none';
                setStatus('No se pudo acceder a la cámara.', 'error');
            }
        }

        async function scanLoop(){
            if (!scanning) return;

            try {
                if (barcodeDetector) {
                    const codes = await barcodeDetector.detect(video);
                    if (codes && codes.length && codes[0].rawValue) {
                        identifyByQR(codes[0].rawValue);
                        return;
                    }
                } else if (window.jsQR && video.videoWidth && video.videoHeight) {
                    const canvas = document.createElement('canvas');
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    const ctx = canvas.getContext('2d', {willReadFrequently:true});
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    const code = window.jsQR(imageData.data, canvas.width, canvas.height);
                    if (code && code.data) {
                        identifyByQR(code.data);
                        return;
                    }
                }
            } catch (e) {}

            requestAnimationFrame(scanLoop);
        }

        function postForm(data){
            const fd = new FormData();
            Object.keys(data).forEach(key => fd.append(key, data[key]));
            fd.append('security', nonce);
            fd.append('event_id', eventID);
            return fetch(ajaxURL, {method:'POST', body:fd, credentials:'same-origin'}).then(r => r.json());
        }

        function identifyByQR(code){
            stopCamera();
            setStatus('Identificando asistente…', 'muted');
            postForm({action:'eventosapp_support_identify_qr', code:code}).then(resp => {
                if (!resp || !resp.success) {
                    setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'No se pudo identificar el asistente.', 'error');
                    return;
                }
                selectTicket(resp.data);
                setStatus('Asistente identificado por QR.', 'ok');
            }).catch(() => setStatus('Error de red al identificar por QR.', 'error'));
        }

        function selectTicket(ticket){
            selectedTicket = ticket;
            selectedBox.style.display = 'block';
            selectedBox.innerHTML = '<h3>Asistente seleccionado</h3>' +
                '<div class="evapp-support-grid">' +
                '<b>Nombre</b><span>' + esc((ticket.first_name || '') + ' ' + (ticket.last_name || '')) + '</span>' +
                '<b>Cédula</b><span>' + esc(ticket.cc) + '</span>' +
                '<b>Ticket</b><span>' + esc(ticket.ticket_code) + '</span>' +
                '<b>Correo</b><span>' + esc(ticket.email) + '</span>' +
                '<b>Teléfono</b><span>' + esc(ticket.phone) + '</span>' +
                '</div>';
            reasonBox.style.display = 'block';
            reasonInput.value = '';
            reasonInput.focus();
        }

        function renderResults(items){
            resultsBox.innerHTML = '';
            if (!items || !items.length) {
                resultsBox.innerHTML = '<div class="evapp-support-muted">Sin resultados.</div>';
                return;
            }
            items.forEach(item => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'evapp-support-result';
                btn.innerHTML = '<strong>' + esc((item.first_name || '') + ' ' + (item.last_name || '')) + '</strong><br>' +
                    '<span>Cédula: ' + esc(item.cc) + ' · Ticket: ' + esc(item.ticket_code) + '</span><br>' +
                    '<small>' + esc(item.email || '') + ' ' + esc(item.phone || '') + '</small>';
                btn.addEventListener('click', () => {
                    selectTicket(item);
                    setStatus('Asistente seleccionado por cédula.', 'ok');
                });
                resultsBox.appendChild(btn);
            });
        }

        function searchByCedula(){
            const q = cedulaInput.value.trim();
            if (q.length < 2) {
                resultsBox.innerHTML = '<div class="evapp-support-muted">Escribe al menos 2 caracteres.</div>';
                return;
            }
            setStatus('Buscando asistente…', 'muted');
            const url = ajaxURL + '?action=eventosapp_support_search_attendee&security=' + encodeURIComponent(nonce) + '&event_id=' + encodeURIComponent(eventID) + '&q=' + encodeURIComponent(q);
            fetch(url, {credentials:'same-origin'}).then(r => r.json()).then(resp => {
                if (!resp || !resp.success) {
                    setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'No se pudo buscar.', 'error');
                    return;
                }
                renderResults(resp.data);
                setStatus('', 'muted');
            }).catch(() => setStatus('Error de red al buscar por cédula.', 'error'));
        }

        scanBtn.addEventListener('click', startCamera);
        cedulaBtn.addEventListener('click', function(){
            stopCamera();
            searchBox.style.display = searchBox.style.display === 'block' ? 'none' : 'block';
            if (searchBox.style.display === 'block') cedulaInput.focus();
        });

        cedulaInput.addEventListener('input', function(){
            clearTimeout(searchTimer);
            searchTimer = setTimeout(searchByCedula, 320);
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
            registerBtn.textContent = 'Registrando…';
            setStatus('Guardando atención…', 'muted');

            postForm({action:'eventosapp_support_register_attention', ticket_id:selectedTicket.ticket_id, reason:reason}).then(resp => {
                registerBtn.disabled = false;
                registerBtn.textContent = 'Registrar atención';
                if (!resp || !resp.success) {
                    setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'No se pudo registrar la atención.', 'error');
                    return;
                }
                setStatus('Atención registrada correctamente.', 'ok');
                selectedTicket = null;
                selectedBox.style.display = 'none';
                selectedBox.innerHTML = '';
                reasonBox.style.display = 'none';
                reasonInput.value = '';
                resultsBox.innerHTML = '';
                cedulaInput.value = '';
            }).catch(() => {
                registerBtn.disabled = false;
                registerBtn.textContent = 'Registrar atención';
                setStatus('Error de red al registrar la atención.', 'error');
            });
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
    if ( function_exists('eventosapp_require_feature') ) {
        eventosapp_require_feature('support_team_metrics');
    }

    $atts = shortcode_atts(['event_id' => 0], $atts);
    $event_id = eventosapp_support_current_event_from_request($atts['event_id']);

    if ( ! $event_id ) {
        $dashboard = function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/');
        return '<div class="evapp-support-notice">Debes escoger un evento en el <a href="'.esc_url($dashboard).'">dashboard</a> antes de ver métricas.</div>';
    }

    if ( ! eventosapp_support_user_can_feature_for_event($event_id, 'support_team_metrics') ) {
        return '<div class="evapp-support-notice evapp-support-notice-error">No tienes permisos para ver las métricas del equipo de apoyo.</div>';
    }

    global $wpdb;
    $table = eventosapp_support_table_name();

    $by_hour = $wpdb->get_results( $wpdb->prepare(
        "SELECT created_hour, COUNT(*) AS total
           FROM {$table}
          WHERE event_id = %d
          GROUP BY created_hour
          ORDER BY created_hour ASC",
        $event_id
    ) );

    $top_users = $wpdb->get_results( $wpdb->prepare(
        "SELECT staff_user_id, staff_name, staff_email, COUNT(*) AS total
           FROM {$table}
          WHERE event_id = %d
          GROUP BY staff_user_id, staff_name, staff_email
          ORDER BY total DESC, staff_name ASC
          LIMIT 10",
        $event_id
    ) );

    $total = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_id = %d", $event_id) );
    $max_hour = 0;
    foreach ( $by_hour as $row ) {
        $max_hour = max($max_hour, (int) $row->total);
    }
    if ( $max_hour < 1 ) $max_hour = 1;

    ob_start();
    if ( function_exists('eventosapp_active_event_bar') ) {
        eventosapp_active_event_bar();
    }
    ?>
    <style>
        .evapp-support-metrics{max-width:980px;margin:0 auto;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#111827;}
        .evapp-support-metrics-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:18px;box-shadow:0 8px 26px rgba(15,23,42,.08);margin-bottom:16px;}
        .evapp-support-metrics h2{margin:0 0 6px;font-size:26px;color:#10233f;}
        .evapp-support-kpi{display:inline-flex;gap:8px;align-items:center;background:#eef6ff;border:1px solid #bfdbfe;border-radius:999px;padding:8px 12px;font-weight:800;color:#1e3a8a;margin:8px 0 16px;}
        .evapp-support-bar-row{display:grid;grid-template-columns:70px 1fr 60px;gap:10px;align-items:center;margin:8px 0;}
        .evapp-support-bar-track{height:24px;background:#f1f5f9;border-radius:999px;overflow:hidden;border:1px solid #e2e8f0;}
        .evapp-support-bar{height:100%;background:#2F73B5;border-radius:999px;min-width:4px;}
        .evapp-support-table{width:100%;border-collapse:collapse;margin-top:8px;font-size:14px;}
        .evapp-support-table th,.evapp-support-table td{border:1px solid #e5e7eb;padding:9px;text-align:left;}
        .evapp-support-table th{background:#f8fafc;font-weight:800;}
        .evapp-support-muted{color:#64748b;}
        .evapp-support-empty{padding:14px;border:1px dashed #cbd5e1;border-radius:12px;color:#64748b;background:#f8fafc;}
    </style>
    <div class="evapp-support-metrics">
        <div class="evapp-support-metrics-card">
            <h2>Métrica de equipo de apoyo</h2>
            <div class="evapp-support-kpi">Total de atenciones: <?php echo esc_html($total); ?></div>

            <h3>Atenciones realizadas por hora</h3>
            <?php if ( $by_hour ) : ?>
                <?php foreach ( $by_hour as $row ) : ?>
                    <?php $pct = min(100, round(((int) $row->total / $max_hour) * 100)); ?>
                    <div class="evapp-support-bar-row">
                        <strong><?php echo esc_html($row->created_hour); ?></strong>
                        <div class="evapp-support-bar-track"><div class="evapp-support-bar" style="width:<?php echo esc_attr($pct); ?>%;"></div></div>
                        <span><?php echo esc_html((int) $row->total); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <div class="evapp-support-empty">Aún no hay atenciones registradas para graficar.</div>
            <?php endif; ?>
        </div>

        <div class="evapp-support-metrics-card">
            <h3>Top usuarios con más atenciones</h3>
            <?php if ( $top_users ) : ?>
                <table class="evapp-support-table">
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
                                <td><?php echo esc_html($pos++); ?></td>
                                <td><?php echo esc_html($row->staff_name ?: ('Usuario #' . $row->staff_user_id)); ?></td>
                                <td><?php echo esc_html($row->staff_email); ?></td>
                                <td><strong><?php echo esc_html((int) $row->total); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <div class="evapp-support-empty">Aún no hay usuarios con atenciones registradas.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
});

// =====================================================
// Descarga CSV
// =====================================================
add_action('admin_post_eventosapp_support_download_csv', function(){
    $event_id = absint($_GET['event_id'] ?? 0);
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
        wp_die('Evento inválido.', '', 400);
    }

    check_admin_referer('eventosapp_support_download_csv_' . $event_id);

    if ( ! eventosapp_support_user_can_feature_for_event($event_id, 'support_team_metrics') && ! current_user_can('edit_post', $event_id) ) {
        wp_die('No tienes permisos para descargar esta base de consultas.', '', 403);
    }

    global $wpdb;
    $table = eventosapp_support_table_name();

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE event_id = %d ORDER BY id ASC",
        $event_id
    ), ARRAY_A );

    $filename = 'eventosapp-consultas-evento-' . $event_id . '-' . gmdate('Ymd-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $out = fopen('php://output', 'w');
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
});
