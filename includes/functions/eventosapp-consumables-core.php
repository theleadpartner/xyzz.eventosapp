<?php
/**
 * EventosApp — Control de Consumibles
 *
 * Funciones incluidas:
 * - Metabox por evento para activar y configurar inventarios segmentados.
 * - Módulo frontend de configuración para organizadores.
 * - Módulo frontend de consumo por QR para staff.
 * - Inventario calculado por ticket, con saldo global o reinicio por día.
 * - Registro transaccional y auditoría de cada consumo.
 * - Integración no invasiva con el dashboard mediante filtros.
 *
 * Shortcodes:
 * - [eventosapp_consumables_manager]
 * - [eventosapp_consumables_staff]
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! defined('EVENTOSAPP_CONSUMABLES_DB_VERSION') ) {
    define('EVENTOSAPP_CONSUMABLES_DB_VERSION', '1.0.0');
}

/* -------------------------------------------------------------------------
 * Tablas y persistencia transaccional
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_table_names') ) {
    function eventosapp_consumables_table_names() {
        global $wpdb;
        return [
            'balances' => $wpdb->prefix . 'eventosapp_consumable_balances',
            'ledger'   => $wpdb->prefix . 'eventosapp_consumable_ledger',
        ];
    }
}

if ( ! function_exists('eventosapp_consumables_install_tables') ) {
    function eventosapp_consumables_install_tables() {
        global $wpdb;

        $tables = eventosapp_consumables_table_names();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_balances = "CREATE TABLE {$tables['balances']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            ticket_id bigint(20) unsigned NOT NULL,
            config_id varchar(64) NOT NULL,
            item_id varchar(64) NOT NULL,
            period_key varchar(32) NOT NULL,
            allocated int(10) unsigned NOT NULL DEFAULT 0,
            consumed int(10) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ticket_item_period (event_id,ticket_id,item_id,period_key),
            KEY event_ticket (event_id,ticket_id),
            KEY event_item (event_id,item_id)
        ) ENGINE=InnoDB {$charset_collate};";

        $sql_ledger = "CREATE TABLE {$tables['ledger']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_uuid varchar(64) NOT NULL,
            event_id bigint(20) unsigned NOT NULL,
            ticket_id bigint(20) unsigned NOT NULL,
            config_id varchar(64) NOT NULL,
            item_id varchar(64) NOT NULL,
            item_name varchar(191) NOT NULL,
            period_key varchar(32) NOT NULL,
            quantity int(10) unsigned NOT NULL DEFAULT 1,
            action varchar(24) NOT NULL DEFAULT 'consume',
            staff_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            qr_type varchar(64) NOT NULL DEFAULT '',
            source varchar(64) NOT NULL DEFAULT 'staff_qr',
            remaining_after int(11) NOT NULL DEFAULT 0,
            note text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY request_uuid (request_uuid),
            KEY event_ticket (event_id,ticket_id),
            KEY event_item (event_id,item_id),
            KEY created_at (created_at)
        ) ENGINE=InnoDB {$charset_collate};";

        dbDelta($sql_balances);
        dbDelta($sql_ledger);

        $balances_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tables['balances'])) === $tables['balances'];
        $ledger_exists   = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tables['ledger'])) === $tables['ledger'];

        if ( $balances_exists && $ledger_exists ) {
            update_option('eventosapp_consumables_db_version', EVENTOSAPP_CONSUMABLES_DB_VERSION, false);
            return true;
        }

        return false;
    }
}

if ( ! function_exists('eventosapp_consumables_maybe_install_tables') ) {
    function eventosapp_consumables_maybe_install_tables() {
        if ( get_option('eventosapp_consumables_db_version') !== EVENTOSAPP_CONSUMABLES_DB_VERSION ) {
            return eventosapp_consumables_install_tables();
        }
        return true;
    }
}
add_action('init', 'eventosapp_consumables_maybe_install_tables', 5);

/* -------------------------------------------------------------------------
 * Configuración del evento
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_event_post_types') ) {
    function eventosapp_consumables_event_post_types() {
        $types = [ 'eventosapp_event', 'eventosapp_events' ];
        return array_values(array_unique(array_filter($types, static function($type) {
            return is_string($type) && $type !== '';
        })));
    }
}

if ( ! function_exists('eventosapp_consumables_is_event_post_type') ) {
    function eventosapp_consumables_is_event_post_type($post_type) {
        return in_array((string) $post_type, eventosapp_consumables_event_post_types(), true);
    }
}

if ( ! function_exists('eventosapp_consumables_active_event_post_types') ) {
    function eventosapp_consumables_active_event_post_types() {
        $active = [];
        foreach ( eventosapp_consumables_event_post_types() as $type ) {
            if ( post_type_exists($type) ) $active[] = $type;
        }
        return $active ?: [ 'eventosapp_event' ];
    }
}

if ( ! function_exists('eventosapp_consumables_make_id') ) {
    function eventosapp_consumables_make_id($prefix = 'id') {
        $prefix = sanitize_key($prefix) ?: 'id';
        $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('', true);
        return $prefix . '_' . substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) $uuid), 0, 20);
    }
}

if ( ! function_exists('eventosapp_consumables_split_values') ) {
    function eventosapp_consumables_split_values($raw) {
        if ( is_array($raw) ) {
            $parts = $raw;
        } else {
            $raw = wp_unslash((string) $raw);
            $parts = preg_split('/[\r\n,;]+/u', $raw);
        }

        $out = [];
        $seen = [];
        foreach ( (array) $parts as $value ) {
            if ( is_array($value) || is_object($value) ) continue;
            $value = trim(sanitize_text_field((string) $value));
            if ( $value === '' ) continue;
            $key = strtolower(remove_accents($value));
            if ( isset($seen[$key]) ) continue;
            $seen[$key] = true;
            $out[] = $value;
        }
        return $out;
    }
}

if ( ! function_exists('eventosapp_consumables_sanitize_config') ) {
    function eventosapp_consumables_sanitize_config($raw) {
        if ( ! is_array($raw) ) $raw = [];
        $raw_rules = isset($raw['rules']) && is_array($raw['rules']) ? $raw['rules'] : [];
        $rules = [];
        $used_rule_ids = [];
        $used_item_ids = [];

        foreach ( $raw_rules as $index => $rule ) {
            if ( ! is_array($rule) ) continue;

            $rule_id = sanitize_key($rule['id'] ?? '');
            if ( $rule_id === '' || isset($used_rule_ids[$rule_id]) ) {
                $rule_id = eventosapp_consumables_make_id('cfg');
            }
            $used_rule_ids[$rule_id] = true;

            $name = sanitize_text_field(wp_unslash($rule['name'] ?? ''));
            if ( $name === '' ) $name = 'Configuración ' . (count($rules) + 1);

            $segment_type = sanitize_key($rule['segment_type'] ?? 'all');
            if ( ! in_array($segment_type, [ 'all', 'localidad', 'custom' ], true) ) {
                $segment_type = 'all';
            }

            $segment_field = $segment_type === 'custom'
                ? sanitize_key($rule['segment_field'] ?? '')
                : '';
            $segment_values = $segment_type === 'all'
                ? []
                : eventosapp_consumables_split_values($rule['segment_values'] ?? []);

            $behavior = sanitize_key($rule['behavior'] ?? 'shared');
            if ( ! in_array($behavior, [ 'shared', 'per_day' ], true) ) {
                $behavior = 'shared';
            }

            $items = [];
            $raw_items = isset($rule['items']) && is_array($rule['items']) ? $rule['items'] : [];
            foreach ( $raw_items as $item ) {
                if ( ! is_array($item) ) continue;
                $item_name = sanitize_text_field(wp_unslash($item['name'] ?? ''));
                if ( $item_name === '' ) continue;

                $quantity = absint($item['quantity'] ?? 0);
                if ( $quantity < 1 ) continue;
                $quantity = min(999999, $quantity);

                $item_id = sanitize_key($item['id'] ?? '');
                if ( $item_id === '' || isset($used_item_ids[$item_id]) ) {
                    $item_id = eventosapp_consumables_make_id('item');
                }
                $used_item_ids[$item_id] = true;

                $items[] = [
                    'id'       => $item_id,
                    'name'     => $item_name,
                    'quantity' => $quantity,
                ];
            }

            if ( empty($items) ) continue;
            if ( $segment_type === 'custom' && $segment_field === '' ) continue;
            if ( in_array($segment_type, [ 'localidad', 'custom' ], true) && empty($segment_values) ) continue;

            $rules[] = [
                'id'             => $rule_id,
                'name'           => $name,
                'segment_type'   => $segment_type,
                'segment_field'  => $segment_field,
                'segment_values' => $segment_values,
                'behavior'       => $behavior,
                'items'          => $items,
            ];
        }

        return [
            'version'    => 1,
            'updated_at' => current_time('mysql'),
            'rules'      => $rules,
        ];
    }
}

if ( ! function_exists('eventosapp_consumables_get_config') ) {
    function eventosapp_consumables_get_config($event_id) {
        $event_id = absint($event_id);
        if ( ! $event_id ) return [ 'version' => 1, 'rules' => [] ];

        $stored = get_post_meta($event_id, '_eventosapp_consumables_config', true);
        if ( ! is_array($stored) ) $stored = [];

        $normalized = eventosapp_consumables_sanitize_config($stored);
        if ( empty($stored) ) $normalized['updated_at'] = '';
        return $normalized;
    }
}

if ( ! function_exists('eventosapp_consumables_is_enabled') ) {
    function eventosapp_consumables_is_enabled($event_id) {
        return get_post_meta(absint($event_id), '_eventosapp_consumables_enabled', true) === '1';
    }
}

if ( ! function_exists('eventosapp_consumables_get_event_localities') ) {
    function eventosapp_consumables_get_event_localities($event_id) {
        $values = get_post_meta(absint($event_id), '_eventosapp_localidades', true);
        return eventosapp_consumables_split_values(is_array($values) ? $values : []);
    }
}

if ( ! function_exists('eventosapp_consumables_get_extra_fields') ) {
    function eventosapp_consumables_get_extra_fields($event_id) {
        $fields = function_exists('eventosapp_get_event_extra_fields')
            ? eventosapp_get_event_extra_fields(absint($event_id))
            : [];
        return is_array($fields) ? $fields : [];
    }
}

/* -------------------------------------------------------------------------
 * Segmentación, períodos e inventario
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_normalize_match_value') ) {
    function eventosapp_consumables_normalize_match_value($value) {
        if ( is_array($value) || is_object($value) ) return '';
        $value = trim(sanitize_text_field((string) $value));
        $value = strtolower(remove_accents($value));
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim((string) $value);
    }
}

if ( ! function_exists('eventosapp_consumables_rule_matches_ticket') ) {
    function eventosapp_consumables_rule_matches_ticket($rule, $ticket_id) {
        if ( ! is_array($rule) ) return false;
        $ticket_id = absint($ticket_id);
        if ( ! $ticket_id ) return false;

        $type = sanitize_key($rule['segment_type'] ?? 'all');
        if ( $type === 'all' ) return true;

        $values = isset($rule['segment_values']) && is_array($rule['segment_values'])
            ? $rule['segment_values']
            : [];
        $normalized_values = array_filter(array_map('eventosapp_consumables_normalize_match_value', $values), 'strlen');
        if ( empty($normalized_values) ) return false;

        if ( $type === 'localidad' ) {
            $ticket_value = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);
        } elseif ( $type === 'custom' ) {
            $field = sanitize_key($rule['segment_field'] ?? '');
            if ( $field === '' ) return false;
            $ticket_value = get_post_meta($ticket_id, '_eventosapp_extra_' . $field, true);
        } else {
            return false;
        }

        return in_array(eventosapp_consumables_normalize_match_value($ticket_value), $normalized_values, true);
    }
}

if ( ! function_exists('eventosapp_consumables_get_ticket_rule') ) {
    function eventosapp_consumables_get_ticket_rule($ticket_id, $event_id = 0) {
        $ticket_id = absint($ticket_id);
        if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) return [];

        $ticket_event = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        $event_id = $event_id ? absint($event_id) : $ticket_event;
        if ( ! $event_id || $ticket_event !== $event_id ) return [];

        $config = eventosapp_consumables_get_config($event_id);
        foreach ( (array) ($config['rules'] ?? []) as $rule ) {
            if ( eventosapp_consumables_rule_matches_ticket($rule, $ticket_id) ) {
                return $rule;
            }
        }
        return [];
    }
}

if ( ! function_exists('eventosapp_consumables_get_event_days') ) {
    function eventosapp_consumables_get_event_days($event_id) {
        $days = function_exists('eventosapp_get_event_days')
            ? (array) eventosapp_get_event_days(absint($event_id))
            : [];
        $days = array_values(array_unique(array_filter(array_map('sanitize_text_field', $days))));
        sort($days, SORT_STRING);
        return $days;
    }
}

if ( ! function_exists('eventosapp_consumables_current_event_date') ) {
    function eventosapp_consumables_current_event_date($event_id) {
        if ( function_exists('eventosapp_get_event_current_date') ) {
            return eventosapp_get_event_current_date(absint($event_id));
        }
        return wp_date('Y-m-d');
    }
}

if ( ! function_exists('eventosapp_consumables_validate_operating_day') ) {
    function eventosapp_consumables_validate_operating_day($event_id) {
        $today = eventosapp_consumables_current_event_date($event_id);
        $days  = eventosapp_consumables_get_event_days($event_id);

        if ( empty($days) ) {
            return new WP_Error('event_without_dates', 'El evento no tiene fechas válidas configuradas para realizar consumos.');
        }
        if ( ! in_array($today, $days, true) ) {
            return new WP_Error('outside_event_day', 'El consumo solo está permitido durante una fecha configurada del evento. Hoy no corresponde a una fecha activa.');
        }
        return $today;
    }
}

if ( ! function_exists('eventosapp_consumables_resolve_period') ) {
    function eventosapp_consumables_resolve_period($event_id, $behavior, $for_consumption = false) {
        $behavior = sanitize_key($behavior);
        if ( $behavior !== 'per_day' ) {
            return [
                'key'   => 'event',
                'date'  => '',
                'label' => 'Saldo general para todo el evento',
            ];
        }

        $today = eventosapp_consumables_current_event_date($event_id);
        $days  = eventosapp_consumables_get_event_days($event_id);
        if ( empty($days) ) {
            return new WP_Error('event_without_dates', 'El evento no tiene fechas válidas configuradas.');
        }

        if ( in_array($today, $days, true) ) {
            $date = $today;
        } elseif ( $for_consumption ) {
            return new WP_Error('outside_event_day', 'Este inventario se reinicia por día y hoy no corresponde a una fecha activa del evento.');
        } else {
            $date = end($days);
            foreach ( $days as $candidate ) {
                if ( $candidate >= $today ) {
                    $date = $candidate;
                    break;
                }
            }
        }

        return [
            'key'   => $date,
            'date'  => $date,
            'label' => 'Saldo del ' . date_i18n('d/m/Y', strtotime($date)),
        ];
    }
}

if ( ! function_exists('eventosapp_consumables_sync_balance_row') ) {
    function eventosapp_consumables_sync_balance_row($event_id, $ticket_id, $rule, $item, $period_key) {
        global $wpdb;
        if ( ! eventosapp_consumables_maybe_install_tables() ) {
            return [
                'allocated' => absint($item['quantity'] ?? 0),
                'consumed'  => 0,
            ];
        }
        $tables = eventosapp_consumables_table_names();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$tables['balances']}
                (event_id,ticket_id,config_id,item_id,period_key,allocated,consumed,updated_at)
             VALUES (%d,%d,%s,%s,%s,%d,0,%s)
             ON DUPLICATE KEY UPDATE
                config_id=VALUES(config_id),
                allocated=VALUES(allocated),
                updated_at=VALUES(updated_at)",
            absint($event_id),
            absint($ticket_id),
            sanitize_key($rule['id'] ?? ''),
            sanitize_key($item['id'] ?? ''),
            sanitize_text_field($period_key),
            absint($item['quantity'] ?? 0),
            current_time('mysql')
        ));

        return $wpdb->get_row($wpdb->prepare(
            "SELECT allocated,consumed FROM {$tables['balances']}
             WHERE event_id=%d AND ticket_id=%d AND item_id=%s AND period_key=%s LIMIT 1",
            absint($event_id),
            absint($ticket_id),
            sanitize_key($item['id'] ?? ''),
            sanitize_text_field($period_key)
        ), ARRAY_A);
    }
}

if ( ! function_exists('eventosapp_consumables_get_ticket_inventory_snapshot') ) {
    function eventosapp_consumables_get_ticket_inventory_snapshot($ticket_id, $event_id = 0) {
        $ticket_id = absint($ticket_id);
        $event_id = $event_id ? absint($event_id) : absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));

        $snapshot = [
            'enabled'      => false,
            'assigned'     => false,
            'event_id'     => $event_id,
            'ticket_id'    => $ticket_id,
            'rule_id'      => '',
            'rule_name'    => '',
            'behavior'     => 'shared',
            'period_key'   => '',
            'period_date'  => '',
            'period_label' => '',
            'items'        => [],
        ];

        if ( ! $ticket_id || ! $event_id || ! eventosapp_consumables_is_enabled($event_id) ) return $snapshot;
        $snapshot['enabled'] = true;

        $rule = eventosapp_consumables_get_ticket_rule($ticket_id, $event_id);
        if ( empty($rule) ) return $snapshot;

        $period = eventosapp_consumables_resolve_period($event_id, $rule['behavior'] ?? 'shared', false);
        if ( is_wp_error($period) ) return $snapshot;

        $snapshot['assigned']     = true;
        $snapshot['rule_id']      = sanitize_key($rule['id'] ?? '');
        $snapshot['rule_name']    = sanitize_text_field($rule['name'] ?? 'Inventario');
        $snapshot['behavior']     = sanitize_key($rule['behavior'] ?? 'shared');
        $snapshot['period_key']   = $period['key'];
        $snapshot['period_date']  = $period['date'];
        $snapshot['period_label'] = $period['label'];

        foreach ( (array) ($rule['items'] ?? []) as $item ) {
            $balance = eventosapp_consumables_sync_balance_row($event_id, $ticket_id, $rule, $item, $period['key']);
            $allocated = absint($item['quantity'] ?? 0);
            $consumed  = is_array($balance) ? absint($balance['consumed'] ?? 0) : 0;
            $remaining = max(0, $allocated - $consumed);

            $snapshot['items'][] = [
                'id'        => sanitize_key($item['id'] ?? ''),
                'name'      => sanitize_text_field($item['name'] ?? ''),
                'allocated' => $allocated,
                'consumed'  => $consumed,
                'remaining' => $remaining,
                'exhausted' => $remaining <= 0,
            ];
        }

        return $snapshot;
    }
}

if ( ! function_exists('eventosapp_consumables_get_event_items') ) {
    function eventosapp_consumables_get_event_items($event_id) {
        $items = [];
        $config = eventosapp_consumables_get_config($event_id);
        foreach ( (array) ($config['rules'] ?? []) as $rule ) {
            foreach ( (array) ($rule['items'] ?? []) as $item ) {
                $item_id = sanitize_key($item['id'] ?? '');
                if ( $item_id === '' ) continue;
                $items[$item_id] = [
                    'id'          => $item_id,
                    'name'        => sanitize_text_field($item['name'] ?? ''),
                    'quantity'    => absint($item['quantity'] ?? 0),
                    'config_id'   => sanitize_key($rule['id'] ?? ''),
                    'config_name' => sanitize_text_field($rule['name'] ?? 'Configuración'),
                ];
            }
        }
        return $items;
    }
}

/* -------------------------------------------------------------------------
 * Permisos y dashboard
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_append_dashboard_features') ) {
    /**
     * Registra los dos módulos en el catálogo completo del dashboard.
     * La función es idempotente y conserva cualquier otro módulo agregado por filtros.
     */
    function eventosapp_consumables_append_dashboard_features($features) {
        if ( ! is_array($features) ) $features = [];
        $features['consumables_manage'] = 'Control de Consumibles';
        $features['consumables_staff']  = 'Consumo de Consumibles';
        return $features;
    }
}
add_filter('eventosapp_dashboard_features_complete', 'eventosapp_consumables_append_dashboard_features', 20);

if ( ! function_exists('eventosapp_consumables_user_can_feature') ) {
    function eventosapp_consumables_user_can_feature($event_id, $user_id, $feature) {
        $event_id = absint($event_id);
        $user_id  = absint($user_id ?: get_current_user_id());
        $feature  = sanitize_key($feature);
        if ( ! $event_id || ! $user_id || ! eventosapp_consumables_is_enabled($event_id) ) return false;

        // Usa la misma ruta efectiva que protege la URL configurada. De esta forma
        // la tarjeta y la página nunca pueden producir decisiones contradictorias.
        if ( function_exists('eventosapp_user_can_access_frontend_feature') ) {
            return eventosapp_user_can_access_frontend_feature($feature, $user_id, $event_id);
        }

        if ( function_exists('eventosapp_user_can_access_dashboard_feature_in_event') ) {
            return eventosapp_user_can_access_dashboard_feature_in_event($user_id, $feature, $event_id);
        }

        if ( user_can($user_id, 'manage_options') ) return true;

        $can_manage_event = function_exists('eventosapp_user_can_manage_event')
            ? eventosapp_user_can_manage_event($event_id, $user_id)
            : false;
        if ( ! $can_manage_event ) return false;

        $allowed = function_exists('eventosapp_role_can')
            ? eventosapp_role_can($feature, $user_id)
            : user_can($user_id, 'edit_posts');

        if ( function_exists('eventosapp_staff_access_user_can_access_feature') ) {
            $allowed = eventosapp_staff_access_user_can_access_feature($event_id, $user_id, $feature, $allowed);
        }

        return (bool) $allowed;
    }
}

if ( ! function_exists('eventosapp_consumables_dashboard_modules') ) {
    function eventosapp_consumables_dashboard_modules($modules, $event_id, $user_id) {
        if ( ! is_array($modules) ) $modules = [];
        if ( ! eventosapp_consumables_is_enabled($event_id) ) return $modules;

        $modules['consumables_manage'] = [
            'title'       => 'Control de Consumibles',
            'description' => 'Configura inventarios, cantidades, segmentación y reinicio por día.',
            'icon'        => 'checklist',
            'category'    => 'operations',
            'url'         => function_exists('eventosapp_get_consumables_manager_url') ? eventosapp_get_consumables_manager_url() : '#',
            'visible'     => eventosapp_consumables_user_can_feature($event_id, $user_id, 'consumables_manage'),
            'keywords'    => 'consumibles inventario configuración bebidas alimentos cantidades',
        ];

        $modules['consumables_staff'] = [
            'title'       => 'Consumo de Consumibles',
            'description' => 'Selecciona uno o varios consumibles, define cantidades y descuéntalos con una sola lectura QR.',
            'icon'        => 'qrcode',
            'category'    => 'access',
            'url'         => function_exists('eventosapp_get_consumables_staff_url') ? eventosapp_get_consumables_staff_url() : '#',
            'visible'     => eventosapp_consumables_user_can_feature($event_id, $user_id, 'consumables_staff'),
            'keywords'    => 'consumir descontar qr cerveza almuerzo bebida inventario',
        ];

        return $modules;
    }
}
add_filter('eventosapp_dashboard_modules', 'eventosapp_consumables_dashboard_modules', 20, 3);

/* -------------------------------------------------------------------------
 * Editor compartido: metabox + módulo del organizador
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_render_item_row') ) {
    function eventosapp_consumables_render_item_row($item = []) {
        $item = wp_parse_args(is_array($item) ? $item : [], [
            'id' => eventosapp_consumables_make_id('item'),
            'name' => '',
            'quantity' => 1,
        ]);
        ?>
        <div class="evapp-cons-item" data-evapp-cons-item>
            <input type="hidden" data-item-field="id" value="<?php echo esc_attr($item['id']); ?>">
            <div class="evapp-cons-field evapp-cons-field--grow">
                <label>Consumible</label>
                <input type="text" data-item-field="name" value="<?php echo esc_attr($item['name']); ?>" placeholder="Ej: Cerveza" required>
            </div>
            <div class="evapp-cons-field evapp-cons-field--quantity">
                <label>Cantidad</label>
                <input type="number" min="1" max="999999" step="1" data-item-field="quantity" value="<?php echo esc_attr(max(1, absint($item['quantity']))); ?>" required>
            </div>
            <button type="button" class="evapp-cons-icon-btn is-danger" data-remove-item aria-label="Eliminar consumible">×</button>
        </div>
        <?php
    }
}

if ( ! function_exists('eventosapp_consumables_render_rule_row') ) {
    function eventosapp_consumables_render_rule_row($rule, $extra_fields, $localities) {
        $rule = wp_parse_args(is_array($rule) ? $rule : [], [
            'id' => eventosapp_consumables_make_id('cfg'),
            'name' => '',
            'segment_type' => 'all',
            'segment_field' => '',
            'segment_values' => [],
            'behavior' => 'shared',
            'items' => [],
        ]);
        if ( empty($rule['items']) ) {
            $rule['items'] = [ [ 'id' => eventosapp_consumables_make_id('item'), 'name' => '', 'quantity' => 1 ] ];
        }
        ?>
        <article class="evapp-cons-rule" data-evapp-cons-rule>
            <input type="hidden" data-rule-field="id" value="<?php echo esc_attr($rule['id']); ?>">
            <header class="evapp-cons-rule-head">
                <div>
                    <span class="evapp-cons-priority" data-priority-label>Prioridad</span>
                    <h4>Configuración de inventario</h4>
                </div>
                <div class="evapp-cons-rule-actions">
                    <button type="button" class="evapp-cons-icon-btn" data-move-up aria-label="Subir configuración">↑</button>
                    <button type="button" class="evapp-cons-icon-btn" data-move-down aria-label="Bajar configuración">↓</button>
                    <button type="button" class="evapp-cons-icon-btn is-danger" data-remove-rule aria-label="Eliminar configuración">×</button>
                </div>
            </header>

            <div class="evapp-cons-grid">
                <div class="evapp-cons-field evapp-cons-field--wide">
                    <label>Nombre de la configuración</label>
                    <input type="text" data-rule-field="name" value="<?php echo esc_attr($rule['name']); ?>" placeholder="Ej: Inventario Localidad General" required>
                    <small>El orden define la prioridad. Se aplica la primera configuración que coincida con el asistente.</small>
                </div>

                <div class="evapp-cons-field">
                    <label>Segmentación</label>
                    <select data-rule-field="segment_type" data-segment-type>
                        <option value="all" <?php selected($rule['segment_type'], 'all'); ?>>Aplicar a todos</option>
                        <option value="localidad" <?php selected($rule['segment_type'], 'localidad'); ?>>Por localidad</option>
                        <option value="custom" <?php selected($rule['segment_type'], 'custom'); ?>>Por campo personalizado</option>
                    </select>
                </div>

                <div class="evapp-cons-field" data-custom-field-wrap>
                    <label>Campo personalizado</label>
                    <select data-rule-field="segment_field" data-segment-field>
                        <option value="">— Selecciona un campo —</option>
                        <?php foreach ( (array) $extra_fields as $field ):
                            $key = sanitize_key($field['key'] ?? '');
                            if ( $key === '' ) continue;
                            $options = isset($field['options']) && is_array($field['options']) ? array_values($field['options']) : [];
                            ?>
                            <option value="<?php echo esc_attr($key); ?>" data-options="<?php echo esc_attr(wp_json_encode($options)); ?>" <?php selected($rule['segment_field'], $key); ?>><?php echo esc_html($field['label'] ?? $key); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="evapp-cons-field evapp-cons-field--wide" data-segment-values-wrap>
                    <label data-values-label>Opciones que reciben este inventario</label>
                    <textarea rows="3" data-rule-field="segment_values" data-segment-values placeholder="Una opción por línea"><?php echo esc_textarea(implode("\n", (array) $rule['segment_values'])); ?></textarea>
                    <div class="evapp-cons-suggestions" data-segment-suggestions data-localities="<?php echo esc_attr(wp_json_encode(array_values($localities))); ?>"></div>
                    <small>Escribe una opción por línea. La comparación no distingue mayúsculas ni tildes.</small>
                </div>

                <div class="evapp-cons-field evapp-cons-field--wide">
                    <label>Comportamiento del inventario</label>
                    <select data-rule-field="behavior">
                        <option value="shared" <?php selected($rule['behavior'], 'shared'); ?>>Un solo inventario para todos los días del evento</option>
                        <option value="per_day" <?php selected($rule['behavior'], 'per_day'); ?>>Reiniciar el inventario en cada día del evento</option>
                    </select>
                    <small>En eventos de un solo día ambas opciones producen el mismo saldo.</small>
                </div>
            </div>

            <div class="evapp-cons-items-block">
                <div class="evapp-cons-items-head">
                    <div>
                        <h5>Consumibles y cantidades</h5>
                        <p>Cada lectura descuenta exactamente una unidad del consumible seleccionado por el staff.</p>
                    </div>
                    <button type="button" class="evapp-cons-secondary-btn" data-add-item>+ Agregar consumible</button>
                </div>
                <div class="evapp-cons-items" data-items-list>
                    <?php foreach ( (array) $rule['items'] as $item ) eventosapp_consumables_render_item_row($item); ?>
                </div>
            </div>
        </article>
        <?php
    }
}

if ( ! function_exists('eventosapp_consumables_render_editor') ) {
    function eventosapp_consumables_render_editor($event_id, $args = []) {
        $event_id = absint($event_id);
        $args = wp_parse_args(is_array($args) ? $args : [], [
            'show_enabled' => false,
            'input_name'   => 'eventosapp_consumables_config',
            'context'      => 'admin',
        ]);

        $config = eventosapp_consumables_get_config($event_id);
        $rules = (array) ($config['rules'] ?? []);
        $extra_fields = eventosapp_consumables_get_extra_fields($event_id);
        $localities = eventosapp_consumables_get_event_localities($event_id);
        $enabled = eventosapp_consumables_is_enabled($event_id);
        $root_id = 'evapp-cons-editor-' . $event_id . '-' . sanitize_key($args['context']) . '-' . wp_rand(1000, 9999);
        ?>
        <style>
            .evapp-cons-editor{--evc-primary:#3279bd;--evc-primary-dark:#255f96;--evc-soft:#eaf4ff;--evc-bg:#f5f8fc;--evc-surface:#fff;--evc-border:#dfe7f1;--evc-text:#182230;--evc-muted:#64748b;--evc-danger:#b42318;color:var(--evc-text);font-family:inherit}
            .evapp-cons-editor *{box-sizing:border-box}.evapp-cons-intro{padding:16px 18px;background:var(--evc-bg);border:1px solid var(--evc-border);border-radius:16px;margin-bottom:16px}.evapp-cons-intro h3{margin:0 0 5px;font-size:19px}.evapp-cons-intro p{margin:0;color:var(--evc-muted);line-height:1.5}.evapp-cons-enable{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:14px;margin-bottom:16px}.evapp-cons-enable strong{display:block}.evapp-cons-enable small{display:block;color:#475569;margin-top:3px}.evapp-cons-switch{position:relative;display:inline-flex;width:52px;height:30px;flex:0 0 auto}.evapp-cons-switch input{opacity:0;width:0;height:0}.evapp-cons-switch span{position:absolute;inset:0;background:#94a3b8;border-radius:999px;cursor:pointer;transition:.2s}.evapp-cons-switch span:before{content:"";position:absolute;width:22px;height:22px;left:4px;top:4px;background:#fff;border-radius:50%;box-shadow:0 2px 6px rgba(0,0,0,.2);transition:.2s}.evapp-cons-switch input:checked+span{background:var(--evc-primary)}.evapp-cons-switch input:checked+span:before{transform:translateX(22px)}
            .evapp-cons-rules{display:grid;gap:16px}.evapp-cons-rule{background:var(--evc-surface);border:1px solid var(--evc-border);border-radius:18px;padding:18px;box-shadow:0 10px 28px rgba(31,52,73,.06)}.evapp-cons-rule-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px}.evapp-cons-rule-head h4{margin:2px 0 0;font-size:17px}.evapp-cons-priority{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--evc-primary)}.evapp-cons-rule-actions{display:flex;gap:6px}.evapp-cons-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;padding:0;border:1px solid var(--evc-border);border-radius:10px;background:#fff;color:var(--evc-text);font-size:18px;line-height:1;cursor:pointer}.evapp-cons-icon-btn:hover{background:var(--evc-soft);border-color:#b9d6f2}.evapp-cons-icon-btn.is-danger{color:var(--evc-danger)}
            .evapp-cons-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.evapp-cons-field{min-width:0}.evapp-cons-field--wide{grid-column:1/-1}.evapp-cons-field label{display:block;font-weight:750;margin-bottom:6px}.evapp-cons-field input,.evapp-cons-field select,.evapp-cons-field textarea{width:100%;max-width:none;min-height:42px;padding:9px 11px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:var(--evc-text);font:inherit}.evapp-cons-field textarea{min-height:78px;resize:vertical}.evapp-cons-field small{display:block;margin-top:5px;color:var(--evc-muted);font-size:12px;line-height:1.4}.evapp-cons-suggestions{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.evapp-cons-chip{border:1px solid #b9d6f2;border-radius:999px;background:var(--evc-soft);color:var(--evc-primary-dark);font-size:12px;font-weight:700;padding:5px 9px;cursor:pointer}
            .evapp-cons-items-block{margin-top:18px;padding-top:16px;border-top:1px solid var(--evc-border)}.evapp-cons-items-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.evapp-cons-items-head h5{margin:0;font-size:15px}.evapp-cons-items-head p{margin:3px 0 0;color:var(--evc-muted);font-size:12px}.evapp-cons-items{display:grid;gap:9px}.evapp-cons-item{display:flex;align-items:flex-end;gap:10px;padding:11px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px}.evapp-cons-field--grow{flex:1 1 auto}.evapp-cons-field--quantity{flex:0 0 130px}.evapp-cons-secondary-btn,.evapp-cons-primary-btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 14px;border-radius:11px;font-weight:750;cursor:pointer;text-decoration:none}.evapp-cons-secondary-btn{background:#fff;border:1px solid var(--evc-primary);color:var(--evc-primary)}.evapp-cons-primary-btn{background:var(--evc-primary);border:1px solid var(--evc-primary);color:#fff!important}.evapp-cons-add-rule{display:flex;justify-content:center;margin-top:16px;padding:16px;border:1px dashed #b9d6f2;border-radius:16px;background:#f8fbff}.evapp-cons-empty{padding:20px;text-align:center;border:1px dashed var(--evc-border);border-radius:16px;background:#fff;color:var(--evc-muted)}
            @media(max-width:760px){.evapp-cons-grid{grid-template-columns:1fr}.evapp-cons-field--wide{grid-column:auto}.evapp-cons-item{align-items:stretch;flex-wrap:wrap}.evapp-cons-field--quantity{flex:1 1 120px}.evapp-cons-item .evapp-cons-icon-btn{align-self:flex-end}.evapp-cons-items-head{align-items:flex-start;flex-direction:column}.evapp-cons-enable{align-items:flex-start}}
        </style>

        <div id="<?php echo esc_attr($root_id); ?>" class="evapp-cons-editor" data-input-name="<?php echo esc_attr($args['input_name']); ?>">
            <div class="evapp-cons-intro">
                <h3>Control de Consumibles</h3>
                <p>Crea configuraciones en orden de prioridad. Cada asistente recibe la primera configuración que coincida con su localidad o campo personalizado. Los cambios realizados aquí y en el módulo del dashboard usan exactamente la misma configuración del evento.</p>
            </div>

            <?php if ( $args['show_enabled'] ): ?>
                <div class="evapp-cons-enable">
                    <div>
                        <strong>Activar control de consumibles para este evento</strong>
                        <small>Al activarlo aparecerán los módulos del dashboard y el inventario del asistente en la landing.</small>
                    </div>
                    <label class="evapp-cons-switch">
                        <input type="checkbox" name="eventosapp_consumables_enabled" value="1" <?php checked($enabled); ?>>
                        <span aria-hidden="true"></span>
                    </label>
                </div>
            <?php endif; ?>

            <div class="evapp-cons-rules" data-rules-list>
                <?php foreach ( $rules as $rule ) eventosapp_consumables_render_rule_row($rule, $extra_fields, $localities); ?>
            </div>
            <div class="evapp-cons-empty" data-empty-state <?php echo $rules ? 'style="display:none"' : ''; ?>>
                Todavía no hay configuraciones. Agrega la primera para definir el inventario base de los asistentes.
            </div>
            <div class="evapp-cons-add-rule">
                <button type="button" class="evapp-cons-primary-btn" data-add-rule>+ Agregar configuración</button>
            </div>

            <template data-rule-template>
                <?php eventosapp_consumables_render_rule_row([
                    'id' => '__RULE_ID__',
                    'name' => '',
                    'segment_type' => 'all',
                    'segment_field' => '',
                    'segment_values' => [],
                    'behavior' => 'shared',
                    'items' => [ [ 'id' => '__ITEM_ID__', 'name' => '', 'quantity' => 1 ] ],
                ], $extra_fields, $localities); ?>
            </template>
            <template data-item-template><?php eventosapp_consumables_render_item_row([ 'id' => '__ITEM_ID__', 'name' => '', 'quantity' => 1 ]); ?></template>
        </div>

        <script>
        (function(){
            var root = document.getElementById(<?php echo wp_json_encode($root_id); ?>);
            if (!root || root.dataset.ready === '1') return;
            root.dataset.ready = '1';

            var rulesList = root.querySelector('[data-rules-list]');
            var emptyState = root.querySelector('[data-empty-state]');
            var inputName = root.getAttribute('data-input-name') || 'eventosapp_consumables_config';

            function makeId(prefix){
                return prefix + '_' + Date.now().toString(36) + Math.random().toString(36).slice(2,10);
            }
            function parseOptions(raw){
                try { var value = JSON.parse(raw || '[]'); return Array.isArray(value) ? value : []; }
                catch(e){ return []; }
            }
            function appendValue(textarea, value){
                if (!textarea || !value) return;
                var rows = textarea.value.split(/\r?\n/).map(function(v){return v.trim();}).filter(Boolean);
                var exists = rows.some(function(v){return v.toLowerCase() === String(value).toLowerCase();});
                if (!exists) rows.push(value);
                textarea.value = rows.join('\n');
                textarea.dispatchEvent(new Event('change', {bubbles:true}));
            }
            function updateSuggestions(rule){
                var type = rule.querySelector('[data-segment-type]').value;
                var fieldWrap = rule.querySelector('[data-custom-field-wrap]');
                var valuesWrap = rule.querySelector('[data-segment-values-wrap]');
                var label = rule.querySelector('[data-values-label]');
                var suggestions = rule.querySelector('[data-segment-suggestions]');
                var fieldSelect = rule.querySelector('[data-segment-field]');
                var values = [];

                fieldWrap.style.display = type === 'custom' ? '' : 'none';
                valuesWrap.style.display = type === 'all' ? 'none' : '';
                if (type === 'localidad') {
                    label.textContent = 'Localidades que reciben este inventario';
                    values = parseOptions(suggestions.getAttribute('data-localities'));
                } else if (type === 'custom') {
                    label.textContent = 'Opciones del campo que reciben este inventario';
                    var selected = fieldSelect.options[fieldSelect.selectedIndex];
                    values = selected ? parseOptions(selected.getAttribute('data-options')) : [];
                }

                suggestions.innerHTML = '';
                values.forEach(function(value){
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'evapp-cons-chip';
                    button.textContent = value;
                    button.addEventListener('click', function(){
                        appendValue(rule.querySelector('[data-segment-values]'), value);
                    });
                    suggestions.appendChild(button);
                });
            }
            function renumber(){
                var rules = Array.prototype.slice.call(rulesList.querySelectorAll(':scope > [data-evapp-cons-rule]'));
                rules.forEach(function(rule, ri){
                    var priority = rule.querySelector('[data-priority-label]');
                    if (priority) priority.textContent = 'Prioridad ' + (ri + 1);
                    rule.querySelectorAll('[data-rule-field]').forEach(function(field){
                        field.name = inputName + '[rules][' + ri + '][' + field.getAttribute('data-rule-field') + ']';
                    });
                    var items = Array.prototype.slice.call(rule.querySelectorAll('[data-items-list] > [data-evapp-cons-item]'));
                    items.forEach(function(item, ii){
                        item.querySelectorAll('[data-item-field]').forEach(function(field){
                            field.name = inputName + '[rules][' + ri + '][items][' + ii + '][' + field.getAttribute('data-item-field') + ']';
                        });
                    });
                    updateSuggestions(rule);
                });
                emptyState.style.display = rules.length ? 'none' : '';
            }
            function addItem(rule){
                var template = root.querySelector('[data-item-template]');
                var html = template.innerHTML.replace(/__ITEM_ID__/g, makeId('item'));
                rule.querySelector('[data-items-list]').insertAdjacentHTML('beforeend', html);
                renumber();
            }
            function addRule(){
                var template = root.querySelector('[data-rule-template]');
                var html = template.innerHTML
                    .replace(/__RULE_ID__/g, makeId('cfg'))
                    .replace(/__ITEM_ID__/g, makeId('item'));
                rulesList.insertAdjacentHTML('beforeend', html);
                renumber();
                var last = rulesList.lastElementChild;
                if (last) {
                    var name = last.querySelector('[data-rule-field="name"]');
                    if (name) name.focus();
                }
            }

            root.addEventListener('click', function(event){
                var addRuleBtn = event.target.closest('[data-add-rule]');
                if (addRuleBtn) { event.preventDefault(); addRule(); return; }
                var addItemBtn = event.target.closest('[data-add-item]');
                if (addItemBtn) { event.preventDefault(); addItem(addItemBtn.closest('[data-evapp-cons-rule]')); return; }
                var removeItemBtn = event.target.closest('[data-remove-item]');
                if (removeItemBtn) {
                    event.preventDefault();
                    var rule = removeItemBtn.closest('[data-evapp-cons-rule]');
                    var items = rule.querySelectorAll('[data-evapp-cons-item]');
                    if (items.length <= 1) { alert('Cada configuración debe conservar al menos un consumible.'); return; }
                    removeItemBtn.closest('[data-evapp-cons-item]').remove(); renumber(); return;
                }
                var removeRuleBtn = event.target.closest('[data-remove-rule]');
                if (removeRuleBtn) {
                    event.preventDefault();
                    if (confirm('¿Eliminar esta configuración de inventario?')) {
                        removeRuleBtn.closest('[data-evapp-cons-rule]').remove(); renumber();
                    }
                    return;
                }
                var up = event.target.closest('[data-move-up]');
                if (up) {
                    event.preventDefault();
                    var current = up.closest('[data-evapp-cons-rule]');
                    if (current.previousElementSibling) rulesList.insertBefore(current, current.previousElementSibling);
                    renumber(); return;
                }
                var down = event.target.closest('[data-move-down]');
                if (down) {
                    event.preventDefault();
                    var currentDown = down.closest('[data-evapp-cons-rule]');
                    if (currentDown.nextElementSibling) rulesList.insertBefore(currentDown.nextElementSibling, currentDown);
                    renumber(); return;
                }
            });

            root.addEventListener('change', function(event){
                if (event.target.matches('[data-segment-type], [data-segment-field]')) {
                    updateSuggestions(event.target.closest('[data-evapp-cons-rule]'));
                }
            });

            renumber();
        })();
        </script>
        <?php
    }
}

if ( ! function_exists('eventosapp_consumables_register_metabox') ) {
    function eventosapp_consumables_register_metabox() {
        foreach ( eventosapp_consumables_active_event_post_types() as $screen ) {
            add_meta_box(
                'eventosapp_consumables_control',
                'Control de Consumibles',
                'eventosapp_consumables_render_metabox',
                $screen,
                'normal',
                'default'
            );
        }
    }
}
add_action('add_meta_boxes', 'eventosapp_consumables_register_metabox', 25);

if ( ! function_exists('eventosapp_consumables_render_metabox') ) {
    function eventosapp_consumables_render_metabox($post) {
        wp_nonce_field('eventosapp_consumables_save', 'eventosapp_consumables_nonce');
        eventosapp_consumables_render_editor($post->ID, [
            'show_enabled' => true,
            'context'      => 'metabox',
        ]);
    }
}

if ( ! function_exists('eventosapp_consumables_save_metabox') ) {
    function eventosapp_consumables_save_metabox($post_id) {
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) ) return;
        if ( ! eventosapp_consumables_is_event_post_type(get_post_type($post_id)) ) return;
        if ( ! current_user_can('edit_post', $post_id) ) return;
        if ( ! isset($_POST['eventosapp_consumables_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventosapp_consumables_nonce'])), 'eventosapp_consumables_save') ) return;

        update_post_meta($post_id, '_eventosapp_consumables_enabled', isset($_POST['eventosapp_consumables_enabled']) ? '1' : '0');
        $raw = isset($_POST['eventosapp_consumables_config']) && is_array($_POST['eventosapp_consumables_config'])
            ? wp_unslash($_POST['eventosapp_consumables_config'])
            : [];
        update_post_meta($post_id, '_eventosapp_consumables_config', eventosapp_consumables_sanitize_config($raw));
        update_post_meta($post_id, '_eventosapp_consumables_updated_by', get_current_user_id());
        update_post_meta($post_id, '_eventosapp_consumables_updated_at', current_time('mysql'));
    }
}
add_action('save_post_eventosapp_event', 'eventosapp_consumables_save_metabox', 40);
add_action('save_post_eventosapp_events', 'eventosapp_consumables_save_metabox', 40);

if ( ! function_exists('eventosapp_consumables_handle_front_save') ) {
    function eventosapp_consumables_handle_front_save() {
        if ( is_admin() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ) return;
        if ( empty($_POST['eventosapp_consumables_action']) || $_POST['eventosapp_consumables_action'] !== 'save_front_config' ) return;

        if ( ! is_user_logged_in() ) {
            wp_safe_redirect(wp_login_url());
            exit;
        }

        $event_id = absint($_POST['eventosapp_consumables_event_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['eventosapp_consumables_front_nonce'] ?? ''));
        $back = function_exists('eventosapp_get_consumables_manager_url')
            ? eventosapp_get_consumables_manager_url()
            : (wp_get_referer() ?: (function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/')));
        $back = remove_query_arg([ 'evapp_consumables_saved', 'evapp_consumables_error' ], $back);

        if ( ! wp_verify_nonce($nonce, 'eventosapp_consumables_front_save_' . $event_id) ) {
            wp_safe_redirect(add_query_arg('evapp_consumables_error', rawurlencode('La solicitud de guardado no es válida.'), $back));
            exit;
        }
        if ( ! eventosapp_consumables_user_can_feature($event_id, get_current_user_id(), 'consumables_manage') ) {
            wp_safe_redirect(add_query_arg('evapp_consumables_error', rawurlencode('No tienes permisos para configurar consumibles en este evento.'), $back));
            exit;
        }

        $raw = isset($_POST['eventosapp_consumables_config']) && is_array($_POST['eventosapp_consumables_config'])
            ? wp_unslash($_POST['eventosapp_consumables_config'])
            : [];
        update_post_meta($event_id, '_eventosapp_consumables_config', eventosapp_consumables_sanitize_config($raw));
        update_post_meta($event_id, '_eventosapp_consumables_updated_by', get_current_user_id());
        update_post_meta($event_id, '_eventosapp_consumables_updated_at', current_time('mysql'));

        wp_safe_redirect(add_query_arg('evapp_consumables_saved', '1', $back));
        exit;
    }
}
add_action('template_redirect', 'eventosapp_consumables_handle_front_save', 2);

if ( ! function_exists('eventosapp_consumables_render_manager_shortcode') ) {
    function eventosapp_consumables_render_manager_shortcode() {
        if ( ! is_user_logged_in() ) return '<div class="evapp-cons-notice is-error">Debes iniciar sesión para acceder a este módulo.</div>';

        $event_id = function_exists('eventosapp_get_active_event') ? eventosapp_get_active_event() : 0;
        if ( ! $event_id ) return '<div class="evapp-cons-notice is-error">Selecciona un evento activo desde el dashboard.</div>';
        if ( ! eventosapp_consumables_is_enabled($event_id) ) return '<div class="evapp-cons-notice is-error">El Control de Consumibles está desactivado para este evento. Solo puede activarse desde el metabox del evento.</div>';
        if ( ! eventosapp_consumables_user_can_feature($event_id, get_current_user_id(), 'consumables_manage') ) return '<div class="evapp-cons-notice is-error">No tienes permisos para configurar consumibles en este evento.</div>';

        ob_start();
        ?>
        <div class="evapp-cons-front-shell">
            <style>
                .evapp-cons-front-shell{max-width:1180px;margin:0 auto;padding:clamp(16px,3vw,32px);background:#f5f8fc;border:1px solid #dfe7f1;border-radius:26px;box-shadow:0 18px 50px rgba(31,52,73,.08);font-family:inherit}.evapp-cons-front-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}.evapp-cons-front-head h2{margin:0;color:#182230;font-size:clamp(26px,4vw,40px);letter-spacing:-.03em}.evapp-cons-front-head p{margin:7px 0 0;color:#64748b}.evapp-cons-back{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 15px;border:1px solid #dfe7f1;border-radius:12px;background:#fff;color:#182230;text-decoration:none;font-weight:750}.evapp-cons-front-event{margin-bottom:18px;padding:14px 16px;border:1px solid #dfe7f1;border-radius:15px;background:#fff}.evapp-cons-front-event small{display:block;color:#64748b;text-transform:uppercase;font-weight:800;letter-spacing:.05em}.evapp-cons-front-event strong{font-size:16px}.evapp-cons-notice{padding:13px 15px;margin:0 0 16px;border:1px solid #bbf7d0;border-left:4px solid #15803d;border-radius:12px;background:#f0fdf4;color:#166534;font-weight:700}.evapp-cons-notice.is-error{border-color:#fecaca;border-left-color:#b42318;background:#fef2f2;color:#991b1b}.evapp-cons-front-actions{display:flex;justify-content:flex-end;margin-top:18px}.evapp-cons-save{min-height:46px;padding:11px 18px;border:1px solid #3279bd;border-radius:12px;background:#3279bd;color:#fff;font:inherit;font-weight:800;cursor:pointer}@media(max-width:700px){.evapp-cons-front-head{align-items:flex-start;flex-direction:column}.evapp-cons-back{width:100%}}
            </style>
            <div class="evapp-cons-front-head">
                <div><h2>Control de Consumibles</h2><p>Configura el inventario base y sus reglas de asignación.</p></div>
                <a class="evapp-cons-back" href="<?php echo esc_url(function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/')); ?>">← Volver al dashboard</a>
            </div>
            <div class="evapp-cons-front-event"><small>Evento activo</small><strong><?php echo esc_html(get_the_title($event_id)); ?></strong></div>
            <?php if ( isset($_GET['evapp_consumables_saved']) ): ?><div class="evapp-cons-notice">La configuración de consumibles fue guardada correctamente.</div><?php endif; ?>
            <?php if ( ! empty($_GET['evapp_consumables_error']) ): ?><div class="evapp-cons-notice is-error"><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['evapp_consumables_error']))); ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="eventosapp_consumables_action" value="save_front_config">
                <input type="hidden" name="eventosapp_consumables_event_id" value="<?php echo esc_attr($event_id); ?>">
                <?php wp_nonce_field('eventosapp_consumables_front_save_' . $event_id, 'eventosapp_consumables_front_nonce'); ?>
                <?php eventosapp_consumables_render_editor($event_id, [ 'show_enabled' => false, 'context' => 'frontend' ]); ?>
                <div class="evapp-cons-front-actions"><button type="submit" class="evapp-cons-save">Guardar configuración</button></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
add_shortcode('eventosapp_consumables_manager', 'eventosapp_consumables_render_manager_shortcode');

/* -------------------------------------------------------------------------
 * Inventario del asistente en la landing presencial de WhatsApp
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_resolve_public_ticket_request') ) {
    function eventosapp_consumables_resolve_public_ticket_request() {
        if ( function_exists('eventosapp_whatsapp_templates_resolve_ticket_from_request') ) {
            return absint(eventosapp_whatsapp_templates_resolve_ticket_from_request());
        }

        foreach ( ['ticket', 'ticket_pub', 'public_id', 'ticketID'] as $key ) {
            if ( empty($_GET[$key]) ) continue;
            $public_id = sanitize_text_field(wp_unslash($_GET[$key]));
            if ( function_exists('eventosapp_find_ticket_by_public_id') ) {
                $ticket_id = absint(eventosapp_find_ticket_by_public_id($public_id));
                if ( $ticket_id ) return $ticket_id;
            }
        }

        return 0;
    }
}

if ( ! function_exists('eventosapp_consumables_public_inventory_html') ) {
    function eventosapp_consumables_public_inventory_html($ticket_id, $event_id) {
        $ticket_id = absint($ticket_id);
        $event_id = absint($event_id);
        if ( ! $ticket_id || ! $event_id ) return '';
        if ( function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id) ) return '';
        if ( ! eventosapp_consumables_is_enabled($event_id) ) return '';

        $snapshot = eventosapp_consumables_get_ticket_inventory_snapshot($ticket_id, $event_id);
        if ( empty($snapshot['enabled']) ) return '';

        ob_start();
        ?>
        <section class="evapp-ticket-consumables" aria-labelledby="evapp-ticket-consumables-title">
            <style>
                .evapp-ticket-consumables{margin:20px 28px 26px;padding:20px;border:1px solid #dbe7f3;border-radius:16px;background:#f8fbff}
                .evapp-ticket-consumables-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}
                .evapp-ticket-consumables h2{margin:0 0 5px;font-size:21px;line-height:1.2;color:#111827}
                .evapp-ticket-consumables p{margin:0;color:#64748b;font-size:14px;line-height:1.45}
                .evapp-ticket-consumables-period{display:inline-flex;align-items:center;border-radius:999px;padding:6px 10px;background:#e8f2fc;color:#135e96;font-size:11px;font-weight:800;white-space:nowrap}
                .evapp-ticket-consumables-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px}
                .evapp-ticket-consumable{padding:13px;border:1px solid #dbe7f3;border-radius:13px;background:#fff}
                .evapp-ticket-consumable.is-empty{background:#f8fafc;opacity:.72}
                .evapp-ticket-consumable-name{display:block;margin-bottom:9px;font-size:14px;font-weight:800;color:#1f2937}
                .evapp-ticket-consumable-balance{display:flex;align-items:baseline;gap:5px;color:#2563eb}
                .evapp-ticket-consumable-balance strong{font-size:25px;line-height:1}
                .evapp-ticket-consumable-balance span{font-size:12px;color:#64748b}
                .evapp-ticket-consumable-progress{height:6px;margin-top:10px;border-radius:999px;background:#e5e7eb;overflow:hidden}
                .evapp-ticket-consumable-progress span{display:block;height:100%;border-radius:999px;background:#2563eb}
                .evapp-ticket-consumables-empty{padding:13px;border:1px dashed #cbd5e1;border-radius:12px;background:#fff;color:#64748b;font-size:14px}
                @media(max-width:520px){.evapp-ticket-consumables{margin:16px 18px 22px}.evapp-ticket-consumables-head{display:block}.evapp-ticket-consumables-period{margin-top:10px}.evapp-ticket-consumables-grid{grid-template-columns:1fr 1fr}}
            </style>
            <div class="evapp-ticket-consumables-head">
                <div>
                    <h2 id="evapp-ticket-consumables-title">Mi inventario de consumibles</h2>
                    <p>Consulta aquí las unidades asignadas, consumidas y disponibles para tu ticket presencial.</p>
                </div>
                <?php if ( ! empty($snapshot['period_label']) ): ?>
                    <span class="evapp-ticket-consumables-period"><?php echo esc_html($snapshot['period_label']); ?></span>
                <?php endif; ?>
            </div>

            <?php if ( ! empty($snapshot['assigned']) && ! empty($snapshot['items']) ): ?>
                <div class="evapp-ticket-consumables-grid">
                    <?php foreach ( $snapshot['items'] as $item ):
                        $allocated = max(0, absint($item['allocated'] ?? 0));
                        $remaining = max(0, absint($item['remaining'] ?? 0));
                        $consumed = max(0, absint($item['consumed'] ?? 0));
                        $percent = $allocated > 0 ? min(100, max(0, round(($remaining / $allocated) * 100))) : 0;
                        ?>
                        <div class="evapp-ticket-consumable <?php echo $remaining < 1 ? 'is-empty' : ''; ?>">
                            <span class="evapp-ticket-consumable-name"><?php echo esc_html($item['name'] ?? 'Consumible'); ?></span>
                            <div class="evapp-ticket-consumable-balance"><strong><?php echo (int)$remaining; ?></strong><span>disponibles de <?php echo (int)$allocated; ?></span></div>
                            <div class="evapp-ticket-consumable-progress" aria-label="<?php echo esc_attr($remaining . ' de ' . $allocated . ' disponibles'); ?>"><span style="width:<?php echo (int)$percent; ?>%"></span></div>
                            <p style="margin:8px 0 0;font-size:11px"><?php echo (int)$consumed; ?> consumido(s)</p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="evapp-ticket-consumables-empty">Este ticket presencial no tiene un inventario de consumibles asignado por las reglas de segmentación del evento.</div>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }
}

if ( ! function_exists('eventosapp_consumables_inject_public_inventory') ) {
    function eventosapp_consumables_inject_public_inventory($html) {
        if ( ! is_string($html) || $html === '' || strpos($html, 'evapp-ticket-consumables') !== false ) return $html;

        $ticket_id = eventosapp_consumables_resolve_public_ticket_request();
        if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) return $html;
        if ( function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id) ) return $html;

        $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        $inventory_html = eventosapp_consumables_public_inventory_html($ticket_id, $event_id);
        if ( $inventory_html === '' ) return $html;

        $marker = '<div class="evapp-ticket-actions">';
        $position = strpos($html, $marker);
        if ( $position !== false ) {
            return substr($html, 0, $position) . $inventory_html . substr($html, $position);
        }

        $fallback = '</section>';
        $position = strrpos($html, $fallback);
        if ( $position !== false ) {
            return substr($html, 0, $position) . $inventory_html . substr($html, $position);
        }

        return $html;
    }
}

if ( ! function_exists('eventosapp_consumables_start_public_landing_buffer') ) {
    function eventosapp_consumables_start_public_landing_buffer() {
        static $started = false;
        if ( $started ) return;

        $has_ticket = false;
        foreach ( ['ticket', 'ticket_pub', 'public_id', 'ticketID'] as $key ) {
            if ( isset($_GET[$key]) && $_GET[$key] !== '' ) {
                $has_ticket = true;
                break;
            }
        }
        if ( ! $has_ticket ) return;

        $action = isset($_GET['eventosapp_whatsapp_public_action'])
            ? sanitize_key(wp_unslash($_GET['eventosapp_whatsapp_public_action']))
            : '';
        if ( $action !== '' && $action !== 'ticket_landing' ) return;
        if ( isset($_GET['evapp_vticket']) ) return;

        $started = true;
        ob_start('eventosapp_consumables_inject_public_inventory');
    }
}
add_action('template_redirect', 'eventosapp_consumables_start_public_landing_buffer', -100);
add_action('admin_post_nopriv_eventosapp_whatsapp_ticket_landing', 'eventosapp_consumables_start_public_landing_buffer', 0);
add_action('admin_post_eventosapp_whatsapp_ticket_landing', 'eventosapp_consumables_start_public_landing_buffer', 0);

/* -------------------------------------------------------------------------
 * Descuento por QR
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_find_ticket_from_qr') ) {
    function eventosapp_consumables_find_ticket_from_qr($raw_code, $event_id) {
        $raw_code = trim(sanitize_text_field(wp_unslash((string) $raw_code)));
        $event_id = absint($event_id);
        if ( $raw_code === '' || ! $event_id ) return new WP_Error('missing_qr', 'No se recibió un código QR válido.');

        if ( function_exists('eventosapp_qr_find_ticket_by_scanned_code') ) {
            $lookup = eventosapp_qr_find_ticket_by_scanned_code($raw_code, $event_id, null);
            if ( ! empty($lookup['found']) && ! empty($lookup['ticket_id']) ) {
                return [
                    'ticket_id'  => absint($lookup['ticket_id']),
                    'type'       => sanitize_key($lookup['type'] ?? 'qr'),
                    'type_label' => sanitize_text_field($lookup['type_label'] ?? 'QR'),
                ];
            }
            return new WP_Error('qr_not_found', sanitize_text_field($lookup['error'] ?? 'No se encontró un ticket válido para este QR.'));
        }

        if ( class_exists('EventosApp_QR_Manager') ) {
            $validation = EventosApp_QR_Manager::validate_qr($raw_code);
            if ( ! empty($validation['valid']) && ! empty($validation['ticket_id']) ) {
                $ticket_id = absint($validation['ticket_id']);
                $ticket_event = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
                if ( $ticket_event !== $event_id ) return new WP_Error('wrong_event', 'El QR pertenece a otro evento.');
                return [
                    'ticket_id'  => $ticket_id,
                    'type'       => sanitize_key($validation['type'] ?? 'qr'),
                    'type_label' => sanitize_text_field($validation['type_label'] ?? 'QR'),
                ];
            }
        }

        return new WP_Error('qr_not_found', 'Código QR inválido o no reconocido.');
    }
}

if ( ! function_exists('eventosapp_consumables_sanitize_selection') ) {
    /**
     * Normaliza la selección enviada por el lector QR.
     * Formato preferido: [item_id => cantidad]. También acepta filas con
     * item_id/quantity para conservar compatibilidad con integraciones futuras.
     */
    function eventosapp_consumables_sanitize_selection($raw) {
        if ( ! is_array($raw) ) return [];

        $selection = [];
        foreach ( $raw as $key => $value ) {
            if ( is_array($value) ) {
                $item_id = sanitize_key($value['item_id'] ?? $value['id'] ?? $key);
                $quantity = absint($value['quantity'] ?? $value['qty'] ?? 0);
            } else {
                $item_id = sanitize_key($key);
                $quantity = absint($value);
            }

            if ( $item_id === '' || $quantity < 1 ) continue;
            $selection[$item_id] = min(999999, $quantity);
        }

        return $selection;
    }
}

if ( ! function_exists('eventosapp_consumables_batch_line_uuid') ) {
    /**
     * El ledger conserva una fila por consumible. Este UUID determinístico permite
     * que una misma lectura con varios artículos siga siendo idempotente.
     */
    function eventosapp_consumables_batch_line_uuid($request_uuid, $item_id) {
        $request_uuid = sanitize_text_field((string)$request_uuid);
        $item_id = sanitize_key((string)$item_id);
        return 'batch_' . substr(hash('sha256', $request_uuid . '|' . $item_id), 0, 56);
    }
}

if ( ! function_exists('eventosapp_consumables_consume_items') ) {
    /**
     * Descuenta varios consumibles y cantidades en una sola transacción.
     * La operación es todo-o-nada: si una línea no tiene saldo, ninguna se descuenta.
     */
    function eventosapp_consumables_consume_items($event_id, $ticket_id, $selection, $staff_user_id, $request_uuid, $qr_type = '') {
        global $wpdb;

        $event_id = absint($event_id);
        $ticket_id = absint($ticket_id);
        $staff_user_id = absint($staff_user_id);
        $selection = eventosapp_consumables_sanitize_selection($selection);
        $request_uuid = sanitize_text_field($request_uuid ?: eventosapp_consumables_make_id('req'));
        $qr_type = sanitize_key($qr_type);

        if ( ! $event_id || ! $ticket_id || empty($selection) ) {
            return new WP_Error('invalid_data', 'Selecciona al menos un consumible y una cantidad válida antes de escanear.');
        }
        if ( ! eventosapp_consumables_is_enabled($event_id) ) {
            return new WP_Error('disabled', 'El Control de Consumibles no está activo para este evento.');
        }

        $day = eventosapp_consumables_validate_operating_day($event_id);
        if ( is_wp_error($day) ) return $day;

        $ticket_event = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if ( $ticket_event !== $event_id ) {
            return new WP_Error('wrong_event', 'El ticket no corresponde al evento activo.');
        }

        $rule = eventosapp_consumables_get_ticket_rule($ticket_id, $event_id);
        if ( empty($rule) ) {
            return new WP_Error('not_assigned', 'El asistente no tiene un inventario de consumibles asignado según la segmentación configurada.');
        }

        $rule_items = [];
        foreach ( (array)($rule['items'] ?? []) as $item ) {
            $item_id = sanitize_key($item['id'] ?? '');
            if ( $item_id !== '' ) $rule_items[$item_id] = $item;
        }

        $selected_items = [];
        foreach ( $selection as $item_id => $quantity ) {
            if ( ! isset($rule_items[$item_id]) ) {
                return new WP_Error(
                    'item_not_assigned',
                    'Uno de los consumibles seleccionados no está asignado a este asistente. Revisa la segmentación o ajusta la selección antes de volver a escanear.'
                );
            }
            $selected_items[$item_id] = [
                'item'     => $rule_items[$item_id],
                'quantity' => absint($quantity),
                'line_uuid'=> eventosapp_consumables_batch_line_uuid($request_uuid, $item_id),
            ];
        }

        $period = eventosapp_consumables_resolve_period($event_id, $rule['behavior'] ?? 'shared', true);
        if ( is_wp_error($period) ) return $period;

        if ( ! eventosapp_consumables_maybe_install_tables() ) {
            return new WP_Error('storage_unavailable', 'No se pudo preparar el almacenamiento de consumibles. Contacta al administrador del sitio.');
        }
        $tables = eventosapp_consumables_table_names();

        // Comprobar idempotencia antes de abrir la transacción.
        $existing_lines = [];
        foreach ( $selected_items as $item_id => $line ) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT item_id,item_name,quantity,remaining_after FROM {$tables['ledger']} WHERE request_uuid=%s LIMIT 1",
                $line['line_uuid']
            ), ARRAY_A);
            if ( is_array($existing) ) $existing_lines[$item_id] = $existing;
        }

        if ( count($existing_lines) === count($selected_items) ) {
            $results = [];
            foreach ( $selected_items as $item_id => $line ) {
                $existing = $existing_lines[$item_id];
                $results[] = [
                    'item_id'   => $item_id,
                    'item_name' => sanitize_text_field($existing['item_name'] ?? $line['item']['name'] ?? ''),
                    'quantity'  => absint($existing['quantity'] ?? $line['quantity']),
                    'remaining' => max(0, intval($existing['remaining_after'] ?? 0)),
                ];
            }
            return [
                'duplicate' => true,
                'items'     => $results,
                'rule'      => $rule,
                'period'    => $period,
            ];
        }

        if ( ! empty($existing_lines) ) {
            return new WP_Error('request_conflict', 'Esta lectura ya fue usada con una selección diferente. Inicia una nueva lectura para evitar descuentos duplicados.');
        }

        $wpdb->query('START TRANSACTION');
        try {
            $now = current_time('mysql');
            $locked_balances = [];

            // Crear/sincronizar y bloquear todas las filas antes de descontar.
            foreach ( $selected_items as $item_id => $line ) {
                $allocated = absint($line['item']['quantity'] ?? 0);
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$tables['balances']}
                        (event_id,ticket_id,config_id,item_id,period_key,allocated,consumed,updated_at)
                     VALUES (%d,%d,%s,%s,%s,%d,0,%s)
                     ON DUPLICATE KEY UPDATE
                        config_id=VALUES(config_id),
                        allocated=VALUES(allocated),
                        updated_at=VALUES(updated_at)",
                    $event_id,
                    $ticket_id,
                    sanitize_key($rule['id'] ?? ''),
                    $item_id,
                    sanitize_text_field($period['key']),
                    $allocated,
                    $now
                ));

                $balance = $wpdb->get_row($wpdb->prepare(
                    "SELECT allocated,consumed FROM {$tables['balances']}
                     WHERE event_id=%d AND ticket_id=%d AND item_id=%s AND period_key=%s
                     LIMIT 1 FOR UPDATE",
                    $event_id,
                    $ticket_id,
                    $item_id,
                    sanitize_text_field($period['key'])
                ), ARRAY_A);

                if ( ! is_array($balance) ) {
                    throw new RuntimeException('balance_not_found');
                }

                $remaining = max(0, absint($balance['allocated'] ?? 0) - absint($balance['consumed'] ?? 0));
                if ( $line['quantity'] > $remaining ) {
                    $wpdb->query('ROLLBACK');
                    $name = sanitize_text_field($line['item']['name'] ?? 'este consumible');
                    return new WP_Error(
                        'insufficient_balance',
                        'No se realizó ningún descuento. Para ' . $name . ' solicitaste ' . absint($line['quantity']) . ' y el asistente solo tiene ' . $remaining . ' disponible(s).'
                    );
                }

                $locked_balances[$item_id] = $balance;
            }

            $results = [];
            foreach ( $selected_items as $item_id => $line ) {
                $quantity = absint($line['quantity']);
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$tables['balances']}
                     SET consumed=consumed+%d, updated_at=%s
                     WHERE event_id=%d AND ticket_id=%d AND item_id=%s AND period_key=%s
                       AND consumed + %d <= allocated",
                    $quantity,
                    $now,
                    $event_id,
                    $ticket_id,
                    $item_id,
                    sanitize_text_field($period['key']),
                    $quantity
                ));

                if ( intval($updated) !== 1 ) {
                    throw new RuntimeException('balance_update_failed');
                }

                $allocated = absint($locked_balances[$item_id]['allocated'] ?? 0);
                $consumed_after = absint($locked_balances[$item_id]['consumed'] ?? 0) + $quantity;
                $remaining = max(0, $allocated - $consumed_after);
                $item_name = sanitize_text_field($line['item']['name'] ?? 'Consumible');

                $inserted = $wpdb->insert($tables['ledger'], [
                    'request_uuid'    => $line['line_uuid'],
                    'event_id'        => $event_id,
                    'ticket_id'       => $ticket_id,
                    'config_id'       => sanitize_key($rule['id'] ?? ''),
                    'item_id'         => $item_id,
                    'item_name'       => $item_name,
                    'period_key'      => sanitize_text_field($period['key']),
                    'quantity'        => $quantity,
                    'action'          => 'consume',
                    'staff_user_id'   => $staff_user_id,
                    'qr_type'         => $qr_type,
                    'source'          => 'staff_qr',
                    'remaining_after' => $remaining,
                    'note'            => 'batch_request=' . $request_uuid,
                    'created_at'      => $now,
                ], [ '%s','%d','%d','%s','%s','%s','%s','%d','%s','%d','%s','%s','%d','%s','%s' ]);

                if ( ! $inserted ) {
                    throw new RuntimeException('ledger_insert_failed');
                }

                $results[] = [
                    'item_id'   => $item_id,
                    'item_name' => $item_name,
                    'quantity'  => $quantity,
                    'remaining' => $remaining,
                ];
            }

            $wpdb->query('COMMIT');
            return [
                'duplicate' => false,
                'items'     => $results,
                'rule'      => $rule,
                'period'    => $period,
            ];
        } catch ( Throwable $e ) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('consume_error', 'No se pudo registrar el consumo. Ningún saldo fue modificado; intenta nuevamente.');
        }
    }
}

if ( ! function_exists('eventosapp_consumables_consume_item') ) {
    /**
     * Wrapper de compatibilidad para integraciones que todavía descuentan una unidad.
     */
    function eventosapp_consumables_consume_item($event_id, $ticket_id, $item_id, $staff_user_id, $request_uuid, $qr_type = '') {
        $result = eventosapp_consumables_consume_items(
            $event_id,
            $ticket_id,
            [sanitize_key($item_id) => 1],
            $staff_user_id,
            $request_uuid,
            $qr_type
        );
        if ( is_wp_error($result) ) return $result;

        $first = !empty($result['items'][0]) ? $result['items'][0] : [];
        $selected_item = [];
        foreach ( (array)($result['rule']['items'] ?? []) as $item ) {
            if ( sanitize_key($item['id'] ?? '') === sanitize_key($item_id) ) {
                $selected_item = $item;
                break;
            }
        }

        return [
            'duplicate' => !empty($result['duplicate']),
            'remaining' => absint($first['remaining'] ?? 0),
            'item'      => $selected_item,
            'quantity'  => 1,
            'rule'      => $result['rule'] ?? [],
            'period'    => $result['period'] ?? [],
        ];
    }
}

if ( ! function_exists('eventosapp_consumables_ajax_consume') ) {
    function eventosapp_consumables_ajax_consume() {
        if ( ! is_user_logged_in() ) wp_send_json_error([ 'message' => 'Debes iniciar sesión.' ], 401);
        check_ajax_referer('eventosapp_consumables_consume', 'nonce');

        $event_id = absint($_POST['event_id'] ?? 0);
        $raw_selection = isset($_POST['items']) && is_array($_POST['items']) ? wp_unslash($_POST['items']) : [];
        $selection = eventosapp_consumables_sanitize_selection($raw_selection);

        // Compatibilidad con la interfaz anterior de un solo consumible.
        if ( empty($selection) ) {
            $legacy_item_id = sanitize_key($_POST['item_id'] ?? '');
            if ( $legacy_item_id !== '' ) $selection[$legacy_item_id] = max(1, absint($_POST['quantity'] ?? 1));
        }

        $raw_code = isset($_POST['raw_code']) ? wp_unslash($_POST['raw_code']) : '';
        $request_uuid = sanitize_text_field(wp_unslash($_POST['request_uuid'] ?? ''));
        $user_id = get_current_user_id();
        $active_event = function_exists('eventosapp_get_active_event') ? eventosapp_get_active_event($user_id) : 0;

        if ( ! $event_id || $event_id !== absint($active_event) ) {
            wp_send_json_error([ 'message' => 'El evento activo cambió. Regresa al dashboard y vuelve a seleccionar el evento.' ], 400);
        }
        if ( empty($selection) ) {
            wp_send_json_error([ 'message' => 'Selecciona al menos un consumible y define su cantidad.' ], 400);
        }
        if ( ! eventosapp_consumables_user_can_feature($event_id, $user_id, 'consumables_staff') ) {
            wp_send_json_error([ 'message' => 'No tienes permisos para descontar consumibles en este evento.' ], 403);
        }

        $lookup = eventosapp_consumables_find_ticket_from_qr($raw_code, $event_id);
        if ( is_wp_error($lookup) ) {
            wp_send_json_error([ 'message' => $lookup->get_error_message(), 'code' => $lookup->get_error_code() ], 400);
        }

        $ticket_id = absint($lookup['ticket_id']);
        $result = eventosapp_consumables_consume_items($event_id, $ticket_id, $selection, $user_id, $request_uuid, $lookup['type'] ?? '');
        if ( is_wp_error($result) ) {
            $snapshot = eventosapp_consumables_get_ticket_inventory_snapshot($ticket_id, $event_id);
            wp_send_json_error([
                'message'   => $result->get_error_message(),
                'code'      => $result->get_error_code(),
                'attendee'  => trim(get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' . get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true)),
                'inventory' => $snapshot,
            ], 409);
        }

        $snapshot = eventosapp_consumables_get_ticket_inventory_snapshot($ticket_id, $event_id);
        $name = trim(get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true) . ' ' . get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true));
        $consumptions = array_values((array)($result['items'] ?? []));
        $total_deducted = 0;
        $summary_parts = [];
        foreach ( $consumptions as $line ) {
            $quantity = absint($line['quantity'] ?? 0);
            $total_deducted += $quantity;
            $summary_parts[] = $quantity . ' × ' . sanitize_text_field($line['item_name'] ?? 'Consumible');
        }
        $summary_label = implode(', ', $summary_parts);
        $duplicate = !empty($result['duplicate']);
        $message = $duplicate
            ? 'Esta lectura ya había sido procesada. No se realizaron descuentos adicionales.'
            : 'Consumo registrado correctamente: ' . $summary_label . '.';

        $first = $consumptions[0] ?? [];
        wp_send_json_success([
            'message'          => $message,
            'attendee'         => $name ?: 'Asistente',
            'ticket_id'        => $ticket_id,
            'ticket_public_id' => get_post_meta($ticket_id, 'eventosapp_ticketID', true),
            'consumptions'     => $consumptions,
            'summary_label'    => $summary_label,
            'item_name'        => sanitize_text_field($first['item_name'] ?? ''),
            'deducted'         => $total_deducted,
            'remaining'        => absint($first['remaining'] ?? 0),
            'period_label'     => $result['period']['label'] ?? '',
            'qr_type'          => sanitize_text_field($lookup['type_label'] ?? 'QR'),
            'inventory'        => $snapshot,
            'duplicate'        => $duplicate,
        ]);
    }
}
add_action('wp_ajax_eventosapp_consumables_consume', 'eventosapp_consumables_ajax_consume');

if ( ! function_exists('eventosapp_consumables_get_recent_activity') ) {
    function eventosapp_consumables_get_recent_activity($event_id, $limit = 8) {
        global $wpdb;
        if ( ! eventosapp_consumables_maybe_install_tables() ) return [];
        $tables = eventosapp_consumables_table_names();
        $limit = max(1, min(30, absint($limit)));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT ticket_id,item_name,quantity,remaining_after,staff_user_id,created_at
             FROM {$tables['ledger']} WHERE event_id=%d AND action='consume'
             ORDER BY id DESC LIMIT %d",
            absint($event_id),
            $limit
        ), ARRAY_A);
    }
}

if ( ! function_exists('eventosapp_consumables_render_staff_shortcode') ) {
    function eventosapp_consumables_render_staff_shortcode() {
        if ( ! is_user_logged_in() ) return '<div class="evapp-cscan-notice is-error">Debes iniciar sesión para acceder a este módulo.</div>';
        $event_id = function_exists('eventosapp_get_active_event') ? eventosapp_get_active_event() : 0;
        if ( ! $event_id ) return '<div class="evapp-cscan-notice is-error">Selecciona un evento activo desde el dashboard.</div>';
        if ( ! eventosapp_consumables_is_enabled($event_id) ) return '<div class="evapp-cscan-notice is-error">El Control de Consumibles no está activo para este evento.</div>';
        if ( ! eventosapp_consumables_user_can_feature($event_id, get_current_user_id(), 'consumables_staff') ) return '<div class="evapp-cscan-notice is-error">No tienes permisos para descontar consumibles en este evento.</div>';

        $items = eventosapp_consumables_get_event_items($event_id);
        $recent = eventosapp_consumables_get_recent_activity($event_id, 8);
        $nonce = wp_create_nonce('eventosapp_consumables_consume');
        $instance = 'evapp-cscan-' . $event_id . '-' . wp_rand(1000, 9999);

        ob_start();
        ?>
        <div id="<?php echo esc_attr($instance); ?>" class="evapp-cscan" data-event-id="<?php echo esc_attr($event_id); ?>">
            <style>
                .evapp-cscan{--evc-primary:#3279bd;--evc-dark:#255f96;--evc-soft:#eaf4ff;--evc-bg:#f5f8fc;--evc-surface:#fff;--evc-border:#dfe7f1;--evc-text:#182230;--evc-muted:#64748b;--evc-success:#15803d;--evc-danger:#b42318;max-width:1040px;margin:0 auto;padding:clamp(16px,3vw,32px);background:var(--evc-bg);border:1px solid var(--evc-border);border-radius:26px;box-shadow:0 18px 50px rgba(31,52,73,.08);color:var(--evc-text);font-family:inherit}.evapp-cscan *{box-sizing:border-box}.evapp-cscan-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.evapp-cscan-head h2{margin:0;font-size:clamp(27px,4vw,40px);letter-spacing:-.03em}.evapp-cscan-head p{margin:7px 0 0;color:var(--evc-muted)}.evapp-cscan-back{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 15px;border:1px solid var(--evc-border);border-radius:12px;background:#fff;color:var(--evc-text);text-decoration:none;font-weight:750}.evapp-cscan-event{display:flex;align-items:center;gap:12px;padding:14px 16px;margin-bottom:18px;background:#fff;border:1px solid var(--evc-border);border-radius:15px}.evapp-cscan-event-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:12px;background:var(--evc-soft);color:var(--evc-primary);font-weight:900}.evapp-cscan-event small{display:block;color:var(--evc-muted);font-weight:800;text-transform:uppercase;letter-spacing:.05em}.evapp-cscan-grid{display:grid;grid-template-columns:minmax(0,1.18fr) minmax(290px,.82fr);gap:18px}.evapp-cscan-card{background:#fff;border:1px solid var(--evc-border);border-radius:18px;padding:18px;box-shadow:0 10px 28px rgba(31,52,73,.05)}.evapp-cscan-card h3{margin:0 0 8px;font-size:18px}.evapp-cscan-help{margin:0 0 14px;color:var(--evc-muted);font-size:12px;line-height:1.45}.evapp-cscan-items{display:grid;gap:9px;max-height:410px;overflow:auto;padding-right:3px}.evapp-cscan-item{display:grid;grid-template-columns:auto minmax(0,1fr) 90px;gap:10px;align-items:center;padding:11px;border:1px solid var(--evc-border);border-radius:13px;background:#fff;transition:border-color .15s,background .15s}.evapp-cscan-item:has([data-item-check]:checked){border-color:var(--evc-primary);background:var(--evc-soft)}.evapp-cscan-item input[type="checkbox"]{width:20px;height:20px;margin:0}.evapp-cscan-item-name{display:block;font-weight:800;line-height:1.25}.evapp-cscan-item-meta{display:block;margin-top:3px;color:var(--evc-muted);font-size:11px}.evapp-cscan-qty-wrap label{display:block;margin-bottom:3px;color:var(--evc-muted);font-size:10px;font-weight:800;text-transform:uppercase}.evapp-cscan-qty{width:100%;min-height:39px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;font:inherit;text-align:center;font-weight:800}.evapp-cscan-selection-summary{margin:12px 0;padding:11px 13px;border-radius:12px;background:#f8fafc;border:1px solid var(--evc-border);color:var(--evc-muted);font-size:13px}.evapp-cscan-selection-summary strong{color:var(--evc-text)}.evapp-cscan-btn{display:flex;align-items:center;justify-content:center;width:100%;min-height:50px;border:0;border-radius:14px;padding:11px 16px;background:var(--evc-primary);color:#fff;font:inherit;font-size:16px;font-weight:850;cursor:pointer}.evapp-cscan-btn:hover{background:var(--evc-dark)}.evapp-cscan-btn:disabled{opacity:.48;cursor:not-allowed}.evapp-cscan-btn.is-stop{background:var(--evc-danger)}.evapp-cscan-video-wrap{display:none;margin-top:14px;overflow:hidden;border-radius:16px;background:#0f172a;position:relative}.evapp-cscan-video{display:block;width:100%;max-height:440px;object-fit:cover}.evapp-cscan-frame{position:absolute;inset:50% auto auto 50%;width:min(68%,310px);aspect-ratio:1;border:3px solid #fff;border-radius:18px;transform:translate(-50%,-50%);box-shadow:0 0 0 999px rgba(15,23,42,.35)}.evapp-cscan-result{display:none;margin-top:14px;padding:15px;border-radius:15px;border:1px solid var(--evc-border);background:#fff}.evapp-cscan-result.is-success{border-color:#86efac;background:#f0fdf4}.evapp-cscan-result.is-error{border-color:#fecaca;background:#fef2f2}.evapp-cscan-result-title{font-size:18px;font-weight:900;margin-bottom:9px}.evapp-cscan-result-grid{display:grid;grid-template-columns:minmax(120px,.4fr) minmax(0,1fr);gap:7px 12px;font-size:13px}.evapp-cscan-result-grid strong{color:var(--evc-muted)}.evapp-cscan-lines{display:grid;gap:7px;margin:11px 0}.evapp-cscan-line{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;padding:9px 10px;border-radius:10px;background:rgba(255,255,255,.75);border:1px solid rgba(203,213,225,.75)}.evapp-cscan-line span{font-weight:800}.evapp-cscan-line small{color:var(--evc-muted);text-align:right}.evapp-cscan-message{margin-top:10px;font-size:13px;line-height:1.45}.evapp-cscan-inventory{display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:8px;margin-top:12px}.evapp-cscan-inventory-item{padding:10px;border:1px solid #bbf7d0;border-radius:11px;background:#fff}.evapp-cscan-inventory-item.is-empty{opacity:.6;border-color:#e5e7eb}.evapp-cscan-inventory-item span,.evapp-cscan-inventory-item strong{display:block}.evapp-cscan-inventory-item span{font-size:12px;color:var(--evc-muted)}.evapp-cscan-inventory-item strong{margin-top:3px;font-size:17px}.evapp-cscan-recent{display:grid;gap:8px}.evapp-cscan-recent-row{padding:10px 11px;border:1px solid var(--evc-border);border-radius:11px;background:#f8fafc}.evapp-cscan-recent-row strong,.evapp-cscan-recent-row span{display:block}.evapp-cscan-recent-row strong{font-size:13px}.evapp-cscan-recent-row span{margin-top:2px;color:var(--evc-muted);font-size:11px}.evapp-cscan-empty{color:var(--evc-muted);font-size:13px}.evapp-cscan-notice{padding:13px 15px;border:1px solid var(--evc-border);border-radius:12px;background:#fff}.evapp-cscan-notice.is-error{border-color:#fecaca;background:#fef2f2;color:#991b1b}@media(max-width:780px){.evapp-cscan-grid{grid-template-columns:1fr}.evapp-cscan-head{flex-direction:column}.evapp-cscan-back{width:100%}}@media(max-width:520px){.evapp-cscan-item{grid-template-columns:auto minmax(0,1fr);}.evapp-cscan-qty-wrap{grid-column:2}.evapp-cscan-result-grid{grid-template-columns:1fr}.evapp-cscan-inventory{grid-template-columns:1fr 1fr}}
            </style>

            <div class="evapp-cscan-head">
                <div><h2>Consumo de Consumibles</h2><p>Define todo lo que vas a entregar y descuéntalo con una sola lectura QR.</p></div>
                <a class="evapp-cscan-back" href="<?php echo esc_url(function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/')); ?>">← Volver al dashboard</a>
            </div>

            <div class="evapp-cscan-event"><div class="evapp-cscan-event-icon">QR</div><div><small>Evento activo</small><strong><?php echo esc_html(get_the_title($event_id)); ?></strong></div></div>

            <div class="evapp-cscan-grid">
                <section class="evapp-cscan-card">
                    <h3>1. Selecciona consumibles y cantidades</h3>
                    <p class="evapp-cscan-help">Puedes marcar varios elementos. El sistema validará todos los saldos antes de descontar; si uno no está disponible, no descontará ninguno.</p>

                    <?php if ( $items ): ?>
                        <div class="evapp-cscan-items" data-items>
                            <?php foreach ( $items as $item ): ?>
                                <div class="evapp-cscan-item" data-item-row data-item-id="<?php echo esc_attr($item['id']); ?>">
                                    <input type="checkbox" value="<?php echo esc_attr($item['id']); ?>" data-item-check aria-label="Seleccionar <?php echo esc_attr($item['name']); ?>">
                                    <div>
                                        <span class="evapp-cscan-item-name"><?php echo esc_html($item['name']); ?></span>
                                        <span class="evapp-cscan-item-meta"><?php echo esc_html($item['config_name']); ?> · máximo configurado: <?php echo (int)$item['quantity']; ?></span>
                                    </div>
                                    <div class="evapp-cscan-qty-wrap">
                                        <label>Cantidad</label>
                                        <input class="evapp-cscan-qty" type="number" min="1" max="<?php echo max(1, (int)$item['quantity']); ?>" step="1" value="1" data-item-qty disabled>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="evapp-cscan-selection-summary" data-selection-summary>No hay consumibles seleccionados.</div>
                        <button type="button" class="evapp-cscan-btn" data-scan-button disabled>Activar cámara y escanear</button>
                    <?php else: ?>
                        <div class="evapp-cscan-notice is-error">No hay consumibles configurados para este evento.</div>
                    <?php endif; ?>

                    <div class="evapp-cscan-video-wrap" data-video-wrap>
                        <video class="evapp-cscan-video" data-video playsinline muted></video>
                        <canvas data-canvas hidden></canvas>
                        <div class="evapp-cscan-frame" aria-hidden="true"></div>
                    </div>
                    <div class="evapp-cscan-result" data-result></div>
                </section>

                <aside class="evapp-cscan-card">
                    <h3>Actividad reciente</h3>
                    <p class="evapp-cscan-help">Últimos descuentos registrados en este evento.</p>
                    <div class="evapp-cscan-recent" data-recent>
                        <?php if ( $recent ): ?>
                            <?php foreach ( $recent as $entry ):
                                $ticket_name = trim(get_post_meta(absint($entry['ticket_id']), '_eventosapp_asistente_nombre', true) . ' ' . get_post_meta(absint($entry['ticket_id']), '_eventosapp_asistente_apellido', true));
                                ?>
                                <div class="evapp-cscan-recent-row">
                                    <strong><?php echo esc_html(($ticket_name ?: 'Asistente') . ' · ' . absint($entry['quantity'] ?? 1) . ' × ' . $entry['item_name']); ?></strong>
                                    <span>Saldo: <?php echo (int)$entry['remaining_after']; ?> · <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($entry['created_at']))); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="evapp-cscan-empty">Todavía no hay consumos registrados.</div>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>

            <script>
            (function(){
                var root = document.getElementById(<?php echo wp_json_encode($instance); ?>);
                if (!root || root.dataset.ready === '1') return;
                root.dataset.ready = '1';

                var itemRows = Array.prototype.slice.call(root.querySelectorAll('[data-item-row]'));
                var button = root.querySelector('[data-scan-button]');
                var selectionSummary = root.querySelector('[data-selection-summary]');
                var videoWrap = root.querySelector('[data-video-wrap]');
                var video = root.querySelector('[data-video]');
                var canvas = root.querySelector('[data-canvas]');
                var resultBox = root.querySelector('[data-result]');
                var recentBox = root.querySelector('[data-recent]');
                if (!button) return;

                var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var nonce = <?php echo wp_json_encode($nonce); ?>;
                var eventId = <?php echo (int)$event_id; ?>;
                var stream = null, running = false, detector = null, raf = 0, busy = false;
                try { if ('BarcodeDetector' in window) detector = new BarcodeDetector({formats:['qr_code']}); } catch(e){}

                function escapeHtml(value){ return String(value == null ? '' : value).replace(/[&<>'"]/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[ch];}); }
                function uuid(){ if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID(); return 'req_' + Date.now().toString(36) + Math.random().toString(36).slice(2,12); }
                function beep(ok){ try{var Ctx=window.AudioContext||window.webkitAudioContext;if(Ctx){var ctx=new Ctx(),osc=ctx.createOscillator(),gain=ctx.createGain();osc.frequency.value=ok?880:220;gain.gain.value=.05;osc.connect(gain);gain.connect(ctx.destination);osc.start();osc.stop(ctx.currentTime+.12);}}catch(e){} if(navigator.vibrate)navigator.vibrate(ok?80:[80,50,80]); }

                function getSelection(){
                    var selected = {};
                    itemRows.forEach(function(row){
                        var check = row.querySelector('[data-item-check]');
                        var qty = row.querySelector('[data-item-qty]');
                        if (!check || !qty || !check.checked) return;
                        var value = parseInt(qty.value,10) || 0;
                        var max = parseInt(qty.max,10) || 999999;
                        value = Math.max(1,Math.min(max,value));
                        qty.value = value;
                        selected[check.value] = value;
                    });
                    return selected;
                }

                function updateSelection(){
                    var selected = getSelection();
                    var ids = Object.keys(selected);
                    var total = ids.reduce(function(sum,id){return sum+selected[id];},0);
                    button.disabled = busy || ids.length === 0;
                    if (selectionSummary) selectionSummary.innerHTML = ids.length
                        ? '<strong>'+ids.length+'</strong> tipo(s) seleccionado(s), <strong>'+total+'</strong> unidad(es) por cada QR leído.'
                        : 'No hay consumibles seleccionados.';
                    return selected;
                }

                itemRows.forEach(function(row){
                    var check = row.querySelector('[data-item-check]');
                    var qty = row.querySelector('[data-item-qty]');
                    if (check) check.addEventListener('change',function(){ if(qty)qty.disabled=!check.checked; if(resultBox)resultBox.style.display='none'; updateSelection(); });
                    if (qty) qty.addEventListener('input',updateSelection);
                });

                function stop(){
                    running=false;if(raf)cancelAnimationFrame(raf);raf=0;
                    if(stream)stream.getTracks().forEach(function(track){track.stop();});stream=null;
                    if(video)video.srcObject=null;if(videoWrap)videoWrap.style.display='none';
                    button.classList.remove('is-stop');
                    if(!busy)button.textContent='Activar cámara y escanear';
                    updateSelection();
                }

                function inventoryHtml(snapshot){
                    if(!snapshot||!snapshot.enabled)return '';
                    if(!snapshot.assigned)return '<div class="evapp-cscan-message">El asistente no tiene inventario asignado por segmentación.</div>';
                    var rows=(snapshot.items||[]).map(function(item){return '<div class="evapp-cscan-inventory-item '+(item.remaining<=0?'is-empty':'')+'"><span>'+escapeHtml(item.name)+'</span><strong>'+escapeHtml(item.remaining)+' / '+escapeHtml(item.allocated)+'</strong></div>';}).join('');
                    return '<div class="evapp-cscan-inventory">'+rows+'</div>';
                }

                function consumptionLines(data){
                    var lines=data.consumptions||[];
                    if(!lines.length)return '';
                    return '<div class="evapp-cscan-lines">'+lines.map(function(line){return '<div class="evapp-cscan-line"><span>'+escapeHtml(line.quantity)+' × '+escapeHtml(line.item_name)+'</span><small>Saldo: '+escapeHtml(line.remaining)+'</small></div>';}).join('')+'</div>';
                }

                function showResult(ok,data){
                    data=data||{};
                    var cls=ok?'is-success':'is-error';
                    var title=ok?(data.duplicate?'Lectura ya procesada':'Consumo registrado'):'No se realizó el descuento';
                    var html='<div class="evapp-cscan-result-title">'+title+'</div>';
                    if(data.attendee){html+='<div class="evapp-cscan-result-grid"><strong>Asistente</strong><span>'+escapeHtml(data.attendee)+'</span>';if(ok){html+='<strong>Total descontado</strong><span>'+escapeHtml(data.deducted)+' unidad(es)</span><strong>Medio leído</strong><span>'+escapeHtml(data.qr_type)+'</span>';}html+='</div>';}
                    if(ok)html+=consumptionLines(data);
                    html+='<div class="evapp-cscan-message">'+escapeHtml(data.message||'No fue posible procesar el QR.')+'</div>'+inventoryHtml(data.inventory);
                    html+='<button type="button" class="evapp-cscan-btn" style="margin-top:12px" data-scan-again>Escanear otro QR</button>';
                    resultBox.className='evapp-cscan-result '+cls;resultBox.innerHTML=html;resultBox.style.display='block';
                    var again=resultBox.querySelector('[data-scan-again]');if(again)again.addEventListener('click',function(){resultBox.style.display='none';start();});
                }

                function addRecent(data){
                    if(!recentBox||!data.attendee||!data.summary_label)return;
                    var empty=recentBox.querySelector('.evapp-cscan-empty');if(empty)empty.remove();
                    var row=document.createElement('div');row.className='evapp-cscan-recent-row';
                    row.innerHTML='<strong>'+escapeHtml(data.attendee)+' · '+escapeHtml(data.summary_label)+'</strong><span>Registrado ahora</span>';
                    recentBox.insertBefore(row,recentBox.firstChild);while(recentBox.children.length>8)recentBox.removeChild(recentBox.lastChild);
                }

                async function sendCode(raw){
                    if(busy)return;
                    var selected=getSelection();
                    if(!Object.keys(selected).length){showResult(false,{message:'Selecciona al menos un consumible y su cantidad.'});return;}
                    busy=true;stop();button.disabled=true;button.textContent='Procesando consumo...';
                    var body=new URLSearchParams();
                    body.set('action','eventosapp_consumables_consume');body.set('nonce',nonce);body.set('event_id',eventId);body.set('raw_code',raw);body.set('request_uuid',uuid());
                    Object.keys(selected).forEach(function(itemId){body.set('items['+itemId+']',selected[itemId]);});
                    try{
                        var response=await fetch(ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});
                        var json=await response.json();
                        if(json&&json.success){beep(true);showResult(true,json.data||{});if(!(json.data||{}).duplicate)addRecent(json.data||{});}else{beep(false);showResult(false,(json&&json.data)||{message:'No se pudo procesar el consumo.'});}
                    }catch(e){beep(false);showResult(false,{message:'Error de conexión. Verifica la red e intenta nuevamente.'});}
                    busy=false;button.textContent='Activar cámara y escanear';updateSelection();
                }

                async function ensureJsQR(){if(detector||window.jsQR)return;await new Promise(function(resolve,reject){var script=document.createElement('script');script.src='https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';script.onload=resolve;script.onerror=reject;document.head.appendChild(script);});}
                async function tick(){
                    if(!running||busy)return;
                    try{
                        if(video.readyState>=2){
                            if(detector){var codes=await detector.detect(video);if(codes&&codes[0]&&codes[0].rawValue){sendCode(codes[0].rawValue);return;}}
                            else if(window.jsQR){var width=video.videoWidth,height=video.videoHeight;if(width&&height){canvas.width=width;canvas.height=height;var ctx=canvas.getContext('2d',{willReadFrequently:true});ctx.drawImage(video,0,0,width,height);var image=ctx.getImageData(0,0,width,height);var code=window.jsQR(image.data,width,height,{inversionAttempts:'dontInvert'});if(code&&code.data){sendCode(code.data);return;}}}
                        }
                    }catch(e){}
                    raf=requestAnimationFrame(tick);
                }
                async function start(){
                    if(!Object.keys(getSelection()).length){showResult(false,{message:'Selecciona al menos un consumible y define su cantidad antes de escanear.'});return;}
                    resultBox.style.display='none';
                    try{await ensureJsQR();stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false});video.srcObject=stream;await video.play();running=true;videoWrap.style.display='block';button.classList.add('is-stop');button.textContent='Detener cámara';tick();}
                    catch(e){stop();showResult(false,{message:'No se pudo acceder a la cámara. Revisa los permisos del navegador y usa una conexión HTTPS.'});}
                }

                button.addEventListener('click',function(){if(running)stop();else start();});
                window.addEventListener('pagehide',stop);
                updateSelection();
            })();
            </script>
        </div>
        <?php
        return ob_get_clean();
    }
}
add_shortcode('eventosapp_consumables_staff', 'eventosapp_consumables_render_staff_shortcode');
