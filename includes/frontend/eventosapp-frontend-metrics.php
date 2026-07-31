<?php
// includes/frontend/eventosapp-frontend-metrics.php
if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('eventosapp_metrics_log_debug') ) {
    function eventosapp_metrics_log_debug($message, $context = []) {
        if ( ! defined('WP_DEBUG') || ! WP_DEBUG ) {
            return;
        }

        $message = is_scalar($message) ? (string) $message : 'debug';
        $line = 'EVENTOSAPP METRICS | ' . $message;

        if ( ! empty($context) ) {
            if ( function_exists('wp_json_encode') ) {
                $encoded = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $encoded = json_encode($context);
            }
            if ( $encoded ) {
                $line .= ' | ' . $encoded;
            }
        }

        error_log($line);
    }
}

// Cargar helper de métricas personalizadas si está disponible.
// Ruta esperada del nuevo archivo: includes/admin/eventosapp-event-custom-metrics-metabox.php
if ( ! function_exists('eventosapp_custom_metrics_get_payload') ) {
    $evapp_custom_metrics_file = dirname(__DIR__) . '/admin/eventosapp-event-custom-metrics-metabox.php';
    if ( file_exists($evapp_custom_metrics_file) ) {
        try {
            require_once $evapp_custom_metrics_file;
        } catch (\Throwable $e) {
            eventosapp_metrics_log_debug('custom_metrics_include_error', [
                'file'    => $evapp_custom_metrics_file,
                'message' => $e->getMessage(),
                'file_at' => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        }
    }
}

/**
 * Shortcode: [eventosapp_front_metrics]
 * - Requiere login y rol organizador/administrator
 * - Requiere evento activo (usa eventosapp_require_active_event())
 * - Secciones:
 *   1) KPI total de tickets del evento activo
 *   2) Pie: Checked In vs Not Checked In (número y %)
 *   3) Barras por hora (00–23): check-in principal (azul) + sesiones (colores determinísticos)
 *      -> Con filtro: Acumulado (rango) o Por día
 *   4) Tabla por Localidad (Check-ins, Not check-ins, % Asistencia, Check-ins sesiones adicionales*, % asistentes a sesiones*)
 *   5) Gráfico de torta de medios de check-in
 *   6) Tabla de estadísticas por medio de check-in
 *   7) Botón descargar base de datos (XLSX compatible con Excel) con modalidad y medios de check-in
 *
 * (*) Por "sesiones adicionales", se contabiliza el número de asistentes ÚNICOS con al menos un check-in de sesión.
 */

//
// === Permiso de visualización ===
//
if ( ! function_exists('eventosapp_user_can_view_metrics') ) {
    function eventosapp_user_can_view_metrics() {
        if ( ! is_user_logged_in() ) return false;
        $u = wp_get_current_user();
        $roles = (array) $u->roles;
        return in_array('administrator', $roles, true) || in_array('organizador', $roles, true);
    }
}

if ( ! function_exists('eventosapp_metrics_qr_label_from_log_entry') ) {
    function eventosapp_metrics_qr_label_from_log_entry($entry, $fallback = 'Sin clasificar') {
        $fallback = is_scalar($fallback) && (string) $fallback !== '' ? (string) $fallback : 'Sin clasificar';

        if (is_array($entry) && isset($entry['qr_type_label']) && is_scalar($entry['qr_type_label'])) {
            $stored_label = trim((string) $entry['qr_type_label']);
            if ($stored_label !== '') {
                return function_exists('sanitize_text_field') ? sanitize_text_field($stored_label) : $stored_label;
            }
        }

        $type = is_array($entry) && isset($entry['qr_type']) && is_scalar($entry['qr_type'])
            ? sanitize_key((string) $entry['qr_type'])
            : '';

        if ($type !== '') {
            $labels = [
                'email'          => 'Email',
                'google_wallet'  => 'Google Wallet',
                'apple_wallet'   => 'Apple Wallet',
                'pdf'            => 'PDF Impreso',
                'whatsapp'       => 'WhatsApp',
                'badge'          => 'Escarapela Impresa',
                'legacy'         => 'QR Legacy',
                'preprinted'     => 'QR Preimpreso',
                'counter'        => 'Counter',
                'manual'         => 'Manual',
                'front'          => 'Frontend',
                'frontend'       => 'Frontend',
                'virtual'        => 'Acceso virtual',
                'virtual_access' => 'Acceso virtual',
                'face'           => 'Reconocimiento facial',
                'face_checkin'   => 'Reconocimiento facial',
                'self_checkin'   => 'Búsqueda e impresión',
            ];

            if (isset($labels[$type])) {
                return $labels[$type];
            }

            /*
             * Evita un 500 crítico cuando EventosApp_QR_Manager::get_qr_type_label()
             * existe como método de instancia. En PHP 8 llamar estáticamente un método
             * no estático dispara fatal error y rompe la respuesta JSON de admin-ajax.
             */
            if (class_exists('EventosApp_QR_Manager') && method_exists('EventosApp_QR_Manager', 'get_qr_type_label')) {
                try {
                    $reflection = new ReflectionMethod('EventosApp_QR_Manager', 'get_qr_type_label');
                    if ($reflection->isStatic()) {
                        $label = EventosApp_QR_Manager::get_qr_type_label($type);
                        if (is_scalar($label) && trim((string) $label) !== '') {
                            $label = trim((string) $label);
                            return function_exists('sanitize_text_field') ? sanitize_text_field($label) : $label;
                        }
                    }
                } catch (\Throwable $e) {
                    eventosapp_metrics_log_debug('qr_label_resolver_error', [
                        'qr_type' => $type,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            return ucwords(str_replace('_', ' ', $type));
        }

        return $fallback;
    }
}

if ( ! function_exists('eventosapp_metrics_safe_custom_metrics_available') ) {
    function eventosapp_metrics_safe_custom_metrics_available($event_id) {
        if ( ! function_exists('eventosapp_custom_metrics_has_enabled_slots') ) {
            return false;
        }

        try {
            return (bool) eventosapp_custom_metrics_has_enabled_slots(absint($event_id));
        } catch (\Throwable $e) {
            eventosapp_metrics_log_debug('custom_metrics_available_error', [
                'event_id' => absint($event_id),
                'message'  => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ]);
            return false;
        }
    }
}

if ( ! function_exists('eventosapp_metrics_safe_custom_metrics_payload') ) {
    function eventosapp_metrics_safe_custom_metrics_payload($event_id) {
        $fallback = [
            'settings'    => [
                'show_header'  => true,
                'header_text'  => 'Métricas personalizadas',
                'header_color' => '#eaf1ff',
            ],
            'rows'        => [],
            'has_metrics' => false,
        ];

        if ( ! function_exists('eventosapp_custom_metrics_get_payload') ) {
            return $fallback;
        }

        try {
            $payload = eventosapp_custom_metrics_get_payload(absint($event_id));
            return is_array($payload) ? $payload : $fallback;
        } catch (\Throwable $e) {
            eventosapp_metrics_log_debug('custom_metrics_payload_error', [
                'event_id' => absint($event_id),
                'message'  => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ]);
            return $fallback;
        }
    }
}

if ( ! function_exists('eventosapp_metrics_send_json_exception') ) {
    function eventosapp_metrics_send_json_exception($e, $public_message = 'No se pudieron cargar las métricas. Revisa el log de EventosApp para ver el detalle técnico.') {
        $payload = [
            'error' => $public_message,
        ];

        if ( defined('WP_DEBUG') && WP_DEBUG && $e instanceof \Throwable ) {
            $payload['debug_message'] = $e->getMessage();
            $payload['debug_file']    = basename($e->getFile());
            $payload['debug_line']    = $e->getLine();
        }

        wp_send_json_error($payload, 500);
    }
}


if ( ! function_exists('eventosapp_metrics_is_metrics_ajax_request') ) {
    function eventosapp_metrics_is_metrics_ajax_request() {
        if ( ! function_exists('wp_doing_ajax') || ! wp_doing_ajax() ) {
            return false;
        }

        $action = isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : '';
        return in_array($action, [
            'eventosapp_metrics_data',
            'eventosapp_custom_metrics_data',
            'eventosapp_export_tickets',
        ], true);
    }
}

if ( ! function_exists('eventosapp_metrics_register_ajax_fatal_guard') ) {
    function eventosapp_metrics_register_ajax_fatal_guard() {
        if ( ! eventosapp_metrics_is_metrics_ajax_request() ) {
            return;
        }

        if ( ! isset($GLOBALS['eventosapp_metrics_shutdown_reserve']) ) {
            $GLOBALS['eventosapp_metrics_shutdown_reserve'] = str_repeat('E', 262144);
        }

        add_filter('wp_fatal_error_handler_enabled', function($enabled){
            return eventosapp_metrics_is_metrics_ajax_request() ? false : $enabled;
        }, 0);

        register_shutdown_function(function(){
            if ( ! eventosapp_metrics_is_metrics_ajax_request() ) {
                return;
            }

            $GLOBALS['eventosapp_metrics_shutdown_reserve'] = null;
            $error = error_get_last();
            if ( ! is_array($error) || empty($error['type']) ) {
                return;
            }

            $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
            if ( ! in_array((int) $error['type'], $fatal_types, true) ) {
                return;
            }

            eventosapp_metrics_log_debug('fatal_shutdown_ajax_error', [
                'type'    => (int) $error['type'],
                'message' => isset($error['message']) ? (string) $error['message'] : '',
                'file'    => isset($error['file']) ? (string) $error['file'] : '',
                'line'    => isset($error['line']) ? (int) $error['line'] : 0,
                'action'  => isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : '',
            ]);

            while ( ob_get_level() > 0 ) {
                @ob_end_clean();
            }

            if ( ! headers_sent() ) {
                status_header(500);
                nocache_headers();
                header('Content-Type: application/json; charset=' . get_option('blog_charset'));
            }

            $payload = [
                'success' => false,
                'data'    => [
                    'error' => 'No se pudieron cargar las métricas por un error crítico del servidor. Revisa el log de PHP/WordPress con el prefijo EVENTOSAPP METRICS para ver el archivo y la línea exacta.',
                ],
            ];

            if ( defined('WP_DEBUG') && WP_DEBUG ) {
                $payload['data']['debug_message'] = isset($error['message']) ? (string) $error['message'] : '';
                $payload['data']['debug_file']    = isset($error['file']) ? basename((string) $error['file']) : '';
                $payload['data']['debug_line']    = isset($error['line']) ? (int) $error['line'] : 0;
            }

            echo wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        });
    }
}

eventosapp_metrics_register_ajax_fatal_guard();

if ( ! function_exists('eventosapp_metrics_normalize_scalar_label') ) {
    function eventosapp_metrics_normalize_scalar_label($value, $fallback = '(Sin dato)') {
        if ( is_scalar($value) ) {
            $value = trim(wp_strip_all_tags((string) $value));
            return $value !== '' ? sanitize_text_field($value) : $fallback;
        }

        if ( is_array($value) ) {
            foreach (['label', 'nombre', 'name', 'title', 'value', 'localidad'] as $candidate_key) {
                if ( isset($value[$candidate_key]) && is_scalar($value[$candidate_key]) ) {
                    $candidate = trim(wp_strip_all_tags((string) $value[$candidate_key]));
                    if ( $candidate !== '' ) {
                        return sanitize_text_field($candidate);
                    }
                }
            }
        }

        return $fallback;
    }
}

if ( ! function_exists('eventosapp_metrics_normalize_localidades_list') ) {
    function eventosapp_metrics_normalize_localidades_list($raw) {
        $fallback = ['General', 'VIP', 'Platino'];
        if ( ! is_array($raw) || empty($raw) ) {
            return $fallback;
        }

        $out = [];
        foreach ( $raw as $item ) {
            $label = eventosapp_metrics_normalize_scalar_label($item, '');
            if ( $label !== '' && ! in_array($label, $out, true) ) {
                $out[] = $label;
            }
        }

        return ! empty($out) ? $out : $fallback;
    }
}

if ( ! function_exists('eventosapp_metrics_get_ticket_ids_for_event') ) {
    function eventosapp_metrics_get_ticket_ids_for_event($event_id) {
        global $wpdb;

        $event_id = absint($event_id);
        if ( ! $event_id || ! $wpdb ) {
            return [];
        }

        $cache_key = 'evapp_metrics_ticket_ids_' . $event_id;
        $cached = wp_cache_get($cache_key, 'eventosapp_metrics');
        if ( is_array($cached) ) {
            return array_map('intval', $cached);
        }

        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID
               AND pm.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND pm.meta_value = %s
             ORDER BY p.ID ASC",
            '_eventosapp_ticket_evento_id',
            'eventosapp_ticket',
            (string) $event_id
        );

        $ids = $wpdb->get_col($sql);
        if ( ! empty($wpdb->last_error) ) {
            eventosapp_metrics_log_debug('ticket_ids_query_db_error', [
                'event_id' => $event_id,
                'error'    => $wpdb->last_error,
            ]);
            throw new RuntimeException('Error consultando tickets del evento para métricas.');
        }

        $ids = array_map('intval', (array) $ids);
        wp_cache_set($cache_key, $ids, 'eventosapp_metrics', 30);
        return $ids;
    }
}

if ( ! function_exists('eventosapp_metrics_get_ticket_meta_map') ) {
    function eventosapp_metrics_get_ticket_meta_map(array $ticket_ids, array $meta_keys) {
        global $wpdb;

        $ticket_ids = array_values(array_unique(array_filter(array_map('intval', $ticket_ids))));
        $meta_keys = array_values(array_unique(array_filter(array_map('strval', $meta_keys))));

        $map = [];
        foreach ( $ticket_ids as $ticket_id ) {
            $map[$ticket_id] = [];
        }

        if ( empty($ticket_ids) || empty($meta_keys) || ! $wpdb ) {
            return $map;
        }

        foreach ( array_chunk($ticket_ids, 500) as $chunk ) {
            $id_placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $key_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));

            $query = $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE post_id IN ($id_placeholders)
                   AND meta_key IN ($key_placeholders)
                 ORDER BY post_id ASC, meta_id ASC",
                array_merge($chunk, $meta_keys)
            );

            $rows = $wpdb->get_results($query, ARRAY_A);
            if ( ! empty($wpdb->last_error) ) {
                eventosapp_metrics_log_debug('ticket_meta_query_db_error', [
                    'error' => $wpdb->last_error,
                ]);
                throw new RuntimeException('Error consultando metadatos de tickets para métricas.');
            }

            foreach ( (array) $rows as $row ) {
                $ticket_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;
                $meta_key = isset($row['meta_key']) ? (string) $row['meta_key'] : '';
                if ( ! $ticket_id || $meta_key === '' || ! isset($map[$ticket_id]) ) {
                    continue;
                }

                if ( ! array_key_exists($meta_key, $map[$ticket_id]) ) {
                    $map[$ticket_id][$meta_key] = maybe_unserialize($row['meta_value']);
                }
            }
        }

        return $map;
    }
}

if ( ! function_exists('eventosapp_metrics_ticket_meta_value') ) {
    function eventosapp_metrics_ticket_meta_value(array $ticket_meta, $ticket_id, $meta_key, $default = '') {
        $ticket_id = (int) $ticket_id;
        $meta_key = (string) $meta_key;
        if ( isset($ticket_meta[$ticket_id]) && array_key_exists($meta_key, $ticket_meta[$ticket_id]) ) {
            return $ticket_meta[$ticket_id][$meta_key];
        }
        return $default;
    }
}


if ( ! function_exists('eventosapp_metrics_meta_to_array') ) {
    function eventosapp_metrics_meta_to_array($value) {
        if ( is_string($value) ) {
            if ( function_exists('maybe_unserialize') ) {
                $value = maybe_unserialize($value);
            } else {
                $maybe = @unserialize($value);
                if ( $maybe !== false || $value === 'b:0;' ) {
                    $value = $maybe;
                }
            }
        }

        return is_array($value) ? $value : [];
    }
}

if ( ! function_exists('eventosapp_metrics_get_tickets_count_for_event') ) {
    function eventosapp_metrics_get_tickets_count_for_event($event_id) {
        global $wpdb;

        $event_id = absint($event_id);
        if ( ! $event_id || ! $wpdb ) {
            return 0;
        }

        $cache_key = 'evapp_metrics_ticket_count_' . $event_id;
        $cached = wp_cache_get($cache_key, 'eventosapp_metrics');
        if ( $cached !== false ) {
            return max(0, (int) $cached);
        }

        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID
               AND pm.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND pm.meta_value = %s",
            '_eventosapp_ticket_evento_id',
            'eventosapp_ticket',
            (string) $event_id
        );

        $count = (int) $wpdb->get_var($sql);
        if ( ! empty($wpdb->last_error) ) {
            eventosapp_metrics_log_debug('ticket_count_query_db_error', [
                'event_id' => $event_id,
                'error'    => $wpdb->last_error,
            ]);
            throw new RuntimeException('Error contando tickets del evento para métricas.');
        }

        wp_cache_set($cache_key, $count, 'eventosapp_metrics', 30);
        return max(0, $count);
    }
}

if ( ! function_exists('eventosapp_metrics_get_ticket_ids_batch_for_event') ) {
    function eventosapp_metrics_get_ticket_ids_batch_for_event($event_id, $after_id = 0, $limit = 400) {
        global $wpdb;

        $event_id = absint($event_id);
        $after_id = absint($after_id);
        $limit    = max(50, min(1000, (int) $limit));

        if ( ! $event_id || ! $wpdb ) {
            return [];
        }

        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID
               AND pm.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND pm.meta_value = %s
               AND p.ID > %d
             ORDER BY p.ID ASC
             LIMIT %d",
            '_eventosapp_ticket_evento_id',
            'eventosapp_ticket',
            (string) $event_id,
            $after_id,
            $limit
        );

        $ids = $wpdb->get_col($sql);
        if ( ! empty($wpdb->last_error) ) {
            eventosapp_metrics_log_debug('ticket_ids_batch_query_db_error', [
                'event_id' => $event_id,
                'after_id' => $after_id,
                'limit'    => $limit,
                'error'    => $wpdb->last_error,
            ]);
            throw new RuntimeException('Error consultando tickets del evento por lotes para métricas.');
        }

        return array_map('intval', (array) $ids);
    }
}

if ( ! function_exists('eventosapp_metrics_default_payload_cache_key') ) {
    function eventosapp_metrics_default_payload_cache_key($event_id, array $request) {
        $parts = [
            'version'      => 'qr_participation_v2',
            'event_id'     => absint($event_id),
            'mode'         => isset($request['mode']) ? sanitize_key((string) $request['mode']) : '',
            'day'          => isset($request['day']) ? sanitize_text_field((string) $request['day']) : '',
            'from'         => isset($request['from']) ? sanitize_text_field((string) $request['from']) : '',
            'to'           => isset($request['to']) ? sanitize_text_field((string) $request['to']) : '',
            'checkin_type' => isset($request['checkin_type']) ? sanitize_key((string) $request['checkin_type']) : '',
        ];

        return 'evapp_metrics_payload_' . md5(wp_json_encode($parts));
    }
}

if ( ! function_exists('eventosapp_metrics_build_default_payload') ) {
    function eventosapp_metrics_build_default_payload($event_id, array $request = []) {
        $event_id = absint($event_id);
        if ( ! $event_id ) {
            throw new RuntimeException('No hay evento activo para construir métricas.');
        }

        $event_modalidad = function_exists('eventosapp_get_event_modalidad')
            ? eventosapp_get_event_modalidad($event_id)
            : (get_post_meta($event_id, '_eventosapp_event_modalidad', true) ?: 'presencial');
        if ( function_exists('eventosapp_normalize_event_modalidad') ) {
            $event_modalidad = eventosapp_normalize_event_modalidad($event_modalidad);
        } else {
            $event_modalidad = in_array($event_modalidad, ['presencial','virtual','presencial_virtual'], true) ? $event_modalidad : 'presencial';
        }

        $event_has_presencial = in_array($event_modalidad, ['presencial','presencial_virtual'], true);
        $event_has_virtual    = in_array($event_modalidad, ['virtual','presencial_virtual'], true);

        $checkin_type = isset($request['checkin_type']) ? sanitize_text_field((string) $request['checkin_type']) : 'all';
        if ( ! in_array($checkin_type, ['all','presencial','virtual'], true) ) $checkin_type = 'all';
        if ( ! $event_has_virtual && $checkin_type === 'virtual' ) $checkin_type = 'presencial';
        if ( ! $event_has_presencial && $checkin_type === 'presencial' ) $checkin_type = 'virtual';
        if ( $event_modalidad === 'virtual' && $checkin_type === 'all' ) $checkin_type = 'virtual';
        if ( $event_modalidad === 'presencial' && $checkin_type === 'all' ) $checkin_type = 'presencial';

        $checkin_filter_label = [
            'all'        => 'Todos',
            'presencial' => 'Presencial',
            'virtual'    => 'Virtual',
        ][$checkin_type] ?? 'Todos';

        $event_tz = get_post_meta($event_id, '_eventosapp_zona_horaria', true);
        if ( ! $event_tz ) {
            $event_tz = wp_timezone_string();
            if ( ! $event_tz || $event_tz === 'UTC' ) {
                $offset = get_option('gmt_offset');
                $event_tz = $offset ? timezone_name_from_abbr('', $offset * 3600, 0) ?: 'UTC' : 'UTC';
            }
        }
        try {
            $now = new DateTime('now', new DateTimeZone($event_tz));
        } catch (Exception $e) {
            $now = new DateTime('now', wp_timezone());
        }
        $today = $now->format('Y-m-d');

        $mode = isset($request['mode']) ? sanitize_text_field((string) $request['mode']) : 'day';
        if ( $mode !== 'sum' && $mode !== 'day' ) $mode = 'day';

        $is_date = function($s){
            return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
        };

        $req_day  = isset($request['day'])  ? sanitize_text_field((string) $request['day'])  : '';
        $req_from = isset($request['from']) ? sanitize_text_field((string) $request['from']) : '';
        $req_to   = isset($request['to'])   ? sanitize_text_field((string) $request['to'])   : '';

        $day  = $today;
        $from = $today;
        $to   = $today;

        if ( $mode === 'day' ) {
            if ( $is_date($req_day) ) $day = $req_day;
        } else {
            if ( $is_date($req_from) ) $from = $req_from;
            if ( $is_date($req_to) )   $to   = $req_to;
            if ( $to < $from ) { $tmp = $from; $from = $to; $to = $tmp; }
        }

        $event_days = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days($event_id) : [];
        $event_days = array_values(array_filter($event_days, function($d) use ($is_date){ return $is_date($d); }));
        $event_days_lookup = array_fill_keys($event_days, true);

        $date_is_current_event_day = function($fecha) use ($event_days_lookup) {
            if ( empty($event_days_lookup) ) return true;
            return is_string($fecha) && isset($event_days_lookup[$fecha]);
        };

        $ticket_has_valid_status = function($status_arr) use ($event_days_lookup) {
            if ( ! is_array($status_arr) ) return false;
            if ( empty($event_days_lookup) ) {
                return in_array('checked_in', $status_arr, true) || in_array('checked-in', $status_arr, true);
            }
            foreach ( $status_arr as $status_day => $status_value ) {
                if ( ($status_value === 'checked_in' || $status_value === 'checked-in') && isset($event_days_lookup[$status_day]) ) {
                    return true;
                }
            }
            return false;
        };

        $in_filter = function($fecha) use ($mode, $day, $from, $to){
            if ( ! $fecha ) return false;
            if ( $mode === 'day' ) return $fecha === $day;
            return ($fecha >= $from && $fecha <= $to);
        };

        $type_is_enabled = function($type) use ($checkin_type) {
            return $checkin_type === 'all' || $checkin_type === $type;
        };

        $total                    = eventosapp_metrics_get_tickets_count_for_event($event_id);
        $checked_total            = 0;
        $checked_presencial_total = 0;
        $checked_virtual_total    = 0;
        $checked_unique_total     = 0;
        $hourly_main              = array_fill(0, 24, 0);
        $hourly_virtual           = array_fill(0, 24, 0);
        $hourly_ses               = [];
        $loc_totals               = [];
        $loc_checked              = [];
        $loc_checked_presencial   = [];
        $loc_checked_virtual      = [];
        $loc_ses_uniques          = [];

        $qr_types_count = [
            'Email'              => 0,
            'Google Wallet'      => 0,
            'Apple Wallet'       => 0,
            'PDF Impreso'        => 0,
            'WhatsApp'           => 0,
            'Escarapela Impresa' => 0,
            'Acceso virtual'     => 0,
            'QR Legacy'          => 0,
            'QR Preimpreso'      => 0,
            'Búsqueda e impresión' => 0,
        ];

        $all_localidades = eventosapp_metrics_normalize_localidades_list(get_post_meta($event_id, '_eventosapp_localidades', true));
        foreach ( $all_localidades as $L ) {
            $loc_totals[$L]             = 0;
            $loc_checked[$L]            = 0;
            $loc_checked_presencial[$L] = 0;
            $loc_checked_virtual[$L]    = 0;
            $loc_ses_uniques[$L]        = [];
        }

        $batch_size = (int) apply_filters('eventosapp_metrics_ticket_batch_size', 350, $event_id);
        $batch_size = max(100, min(700, $batch_size));
        $last_id    = 0;
        $processed  = 0;
        $meta_keys  = [
            '_eventosapp_asistente_localidad',
            '_eventosapp_checkin_status',
            '_eventosapp_virtual_checkin_status',
            '_eventosapp_checkin_log',
        ];

        do {
            $ids = eventosapp_metrics_get_ticket_ids_batch_for_event($event_id, $last_id, $batch_size);
            if ( empty($ids) ) {
                break;
            }

            $last_id = max($ids);
            $ticket_meta = eventosapp_metrics_get_ticket_meta_map($ids, $meta_keys);

            foreach ( $ids as $tid ) {
                $processed++;

                $loc = eventosapp_metrics_normalize_scalar_label(
                    eventosapp_metrics_ticket_meta_value($ticket_meta, $tid, '_eventosapp_asistente_localidad', ''),
                    '(Sin localidad)'
                );
                if ( ! array_key_exists($loc, $loc_totals) ) {
                    $loc_totals[$loc]             = 0;
                    $loc_checked[$loc]            = 0;
                    $loc_checked_presencial[$loc] = 0;
                    $loc_checked_virtual[$loc]    = 0;
                    $loc_ses_uniques[$loc]        = [];
                }
                $loc_totals[$loc]++;

                $status_arr         = eventosapp_metrics_meta_to_array(eventosapp_metrics_ticket_meta_value($ticket_meta, $tid, '_eventosapp_checkin_status', []));
                $virtual_status_arr = eventosapp_metrics_meta_to_array(eventosapp_metrics_ticket_meta_value($ticket_meta, $tid, '_eventosapp_virtual_checkin_status', []));
                $pres_checked       = $ticket_has_valid_status($status_arr);
                $virt_checked       = $ticket_has_valid_status($virtual_status_arr);
                $unique_checked     = ($pres_checked || $virt_checked);

                if ( $pres_checked ) {
                    $checked_presencial_total++;
                    $loc_checked_presencial[$loc]++;
                }
                if ( $virt_checked ) {
                    $checked_virtual_total++;
                    $loc_checked_virtual[$loc]++;
                }
                if ( $unique_checked ) {
                    $checked_unique_total++;
                }

                if ( $checkin_type === 'virtual' ) {
                    $selected_checked = $virt_checked;
                } elseif ( $checkin_type === 'presencial' ) {
                    $selected_checked = $pres_checked;
                } else {
                    $selected_checked = $unique_checked;
                }

                if ( $selected_checked ) {
                    $checked_total++;
                    $loc_checked[$loc]++;
                }

                $log = eventosapp_metrics_meta_to_array(eventosapp_metrics_ticket_meta_value($ticket_meta, $tid, '_eventosapp_checkin_log', []));
                $qr_presencial_contado = false;
                $qr_virtual_contado    = false;

                foreach ( $log as $row ) {
                    if ( ! is_array($row) ) continue;

                    $fecha = isset($row['fecha']) ? (string) $row['fecha'] : '';
                    $hora  = isset($row['hora'])  ? (string) $row['hora']  : '';
                    $status= isset($row['status'])? (string) $row['status']: '';
                    $ses   = isset($row['sesion'])? (string) $row['sesion']: '';
                    $checkin_log_type = isset($row['checkin_type']) ? (string)$row['checkin_type'] : '';
                    $entry_event_date = ! empty($row['dia']) ? (string)$row['dia'] : $fecha;
                    $entry_is_valid_event_day = $date_is_current_event_day($entry_event_date);
                    $H = ($hora && preg_match('/^\d{2}/', $hora)) ? intval(substr($hora,0,2)) : null;
                    if ( $H === null || $H < 0 || $H > 23 ) $H = null;

                    $is_presencial_log = ($status === 'checked_in' || $status === 'checked-in') && $checkin_log_type !== 'virtual';
                    $is_virtual_log    = ($status === 'virtual_checked_in') || $checkin_log_type === 'virtual';

                    if ( $entry_is_valid_event_day && $in_filter($fecha) && $H !== null ) {
                        if ( $is_presencial_log ) {
                            $hourly_main[$H] = (isset($hourly_main[$H]) ? $hourly_main[$H] : 0) + 1;
                        } elseif ( $is_virtual_log ) {
                            $hourly_virtual[$H] = (isset($hourly_virtual[$H]) ? $hourly_virtual[$H] : 0) + 1;
                        } elseif ( $status === 'session_checked_in' && $ses ) {
                            if ( ! isset($hourly_ses[$ses]) ) $hourly_ses[$ses] = array_fill(0,24,0);
                            $hourly_ses[$ses][$H] = (isset($hourly_ses[$ses][$H]) ? $hourly_ses[$ses][$H] : 0) + 1;
                        }
                    }

                    if ( $status === 'session_checked_in' && $entry_is_valid_event_day ) {
                        $loc_ses_uniques[$loc][$tid] = true;
                    }

                    if ( $entry_is_valid_event_day && $is_presencial_log && ! $qr_presencial_contado && $type_is_enabled('presencial') ) {
                        $qr_presencial_contado = true;
                        $qr_label = eventosapp_metrics_qr_label_from_log_entry($row, 'Sin clasificar');
                        $qr_types_count[$qr_label] = isset($qr_types_count[$qr_label]) ? $qr_types_count[$qr_label] + 1 : 1;
                    }

                    if ( $entry_is_valid_event_day && $is_virtual_log && ! $qr_virtual_contado && $type_is_enabled('virtual') ) {
                        $qr_virtual_contado = true;
                        $qr_label = eventosapp_metrics_qr_label_from_log_entry($row, 'Acceso virtual');
                        $qr_types_count[$qr_label] = isset($qr_types_count[$qr_label]) ? $qr_types_count[$qr_label] + 1 : 1;
                    }
                }

                if ( $pres_checked && ! $qr_presencial_contado && $type_is_enabled('presencial') ) {
                    $qr_types_count['Sin clasificar'] = isset($qr_types_count['Sin clasificar']) ? $qr_types_count['Sin clasificar'] + 1 : 1;
                }
                if ( $virt_checked && ! $qr_virtual_contado && $type_is_enabled('virtual') ) {
                    $qr_types_count['Acceso virtual'] = isset($qr_types_count['Acceso virtual']) ? $qr_types_count['Acceso virtual'] + 1 : 1;
                }
            }

            unset($ticket_meta, $ids);
        } while ( true );

        if ( $processed !== $total ) {
            eventosapp_metrics_log_debug('ticket_count_processed_mismatch', [
                'event_id'  => $event_id,
                'total'     => $total,
                'processed' => $processed,
            ]);
            if ( $processed > 0 ) {
                $total = $processed;
            }
        }

        $rows = [];
        foreach ( $loc_totals as $L => $tot ) {
            $chk  = $loc_checked[$L] ?? 0;
            $not  = max($tot - $chk, 0);
            $pctA = $tot ? ($chk * 100 / $tot) : 0;
            $sesUniq = isset($loc_ses_uniques[$L]) ? count($loc_ses_uniques[$L]) : 0;
            $pctSes  = $tot ? ($sesUniq * 100 / $tot) : 0;

            $rows[] = [
                'localidad'                => $L,
                'total'                    => (int)$tot,
                'checkins'                 => (int)$chk,
                'checkins_presencial'      => (int)($loc_checked_presencial[$L] ?? 0),
                'checkins_virtual'         => (int)($loc_checked_virtual[$L] ?? 0),
                'not_checkins'             => (int)$not,
                'pct_asistencia'           => round($pctA, 2),
                'checkins_sesiones_unicos' => (int)$sesUniq,
                'pct_sesiones'             => round($pctSes, 2),
            ];
        }
        usort($rows, function($a,$b){ return $b['checkins'] <=> $a['checkins']; });

        $bar_meta = [
            'labels'       => array_map(function($h){ return str_pad((string)$h, 2, '0', STR_PAD_LEFT); }, range(0,23)),
            'main'         => array_values($hourly_main),
            'virtual'      => array_values($hourly_virtual),
            'sessions'     => $hourly_ses,
            'mode'         => $mode,
            'checkin_type' => $checkin_type,
        ];
        if ( $mode === 'day' ) {
            $bar_meta['day']  = $day;
            $bar_meta['from'] = $day;
            $bar_meta['to']   = $day;
        } else {
            $bar_meta['from'] = $from;
            $bar_meta['to']   = $to;
            $bar_meta['day']  = $today;
        }

        $qr_stats_filtered = [];
        $qr_total = 0;
        foreach ( $qr_types_count as $type => $count ) {
            $count = (int) $count;
            if ( $count > 0 ) {
                $qr_stats_filtered[$type] = $count;
                $qr_total += $count;
            }
        }

        /*
         * Base real de participación para el gráfico y la tabla de medios.
         * Antes el porcentaje se calculaba contra la suma de medios ($qr_total), lo que solo mostraba
         * distribución interna entre medios. Para participación real del evento, cada medio debe dividirse
         * contra el total de tickets del evento, igual que el KPI general de asistencia.
         */
        $qr_participation_base     = max((int) $total, 0);
        $qr_checked_base           = max((int) $checked_total, 0);
        $qr_without_media_total    = max($qr_checked_base - (int) $qr_total, 0);
        $qr_not_checked_base_total = max($qr_participation_base - $qr_checked_base, 0);

        return [
            'total_tickets'               => $total,
            'checked_in_total'            => $checked_total,
            'checked_in_presencial_total' => $checked_presencial_total,
            'checked_in_virtual_total'    => $checked_virtual_total,
            'checked_in_unique_total'     => $checked_unique_total,
            'not_checked_in_total'        => max($total - $checked_total, 0),
            'event_modalidad'             => $event_modalidad,
            'show_presencial_metrics'     => $event_has_presencial,
            'show_virtual_metrics'        => $event_has_virtual,
            'checkin_type'                => $checkin_type,
            'checkin_filter_label'        => $checkin_filter_label,
            'bar'                         => $bar_meta,
            'table'                       => [ 'rows' => $rows ],
            'qr_stats'                    => [
                'types'                      => $qr_stats_filtered,
                'total'                      => $qr_total,
                'distribution_total'         => $qr_total,
                'participation_base'         => $qr_participation_base,
                'participation_base_label'   => 'Total de tickets del evento',
                'checked_total'              => $qr_checked_base,
                'without_media_total'        => $qr_without_media_total,
                'not_checked_total'          => $qr_not_checked_base_total,
                'percentage_mode'            => 'participation',
            ],
            'custom_metrics_available' => eventosapp_metrics_safe_custom_metrics_available($event_id),
            'custom_metrics' => null,
            'performance' => [
                'processed_tickets' => $processed,
                'batch_size'        => $batch_size,
                'cached'            => false,
            ],
        ];
    }
}

// === Render compartido por shortcode y widget de Elementor ===
if ( ! function_exists('eventosapp_metrics_get_dashboard_return_url') ) {
    function eventosapp_metrics_get_dashboard_return_url($custom_url = '') {
        $custom_url = is_scalar($custom_url) ? trim((string) $custom_url) : '';
        if ($custom_url !== '') {
            return esc_url_raw($custom_url);
        }

        if ( function_exists('eventosapp_get_dashboard_url') ) {
            $dashboard_url = eventosapp_get_dashboard_url();
            if (is_string($dashboard_url) && trim($dashboard_url) !== '') {
                return esc_url_raw($dashboard_url);
            }
        }

        return home_url('/');
    }
}

if ( ! function_exists('eventosapp_metrics_print_frontend_styles') ) {
    function eventosapp_metrics_print_frontend_styles() {
        static $printed = false;
        if ($printed) return;
        $printed = true;
        ?>
        <style id="eventosapp-metrics-styles">
.evapp-metrics{
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
  --evapp-warning:#b45309;
  --evapp-warning-soft:#fff7ed;
  --evapp-danger:#b42318;
  --evapp-danger-soft:#fff1f0;
  --evapp-analytics-bg:#0b1020;
  --evapp-analytics-bg-soft:#0f1835;
  --evapp-analytics-bg-alt:#0c1733;
  --evapp-analytics-bg-hover:#111d3d;
  --evapp-analytics-text:#eaf1ff;
  --evapp-analytics-title:#cfe0ff;
  --evapp-analytics-muted:#a9b6d3;
  --evapp-analytics-border:#263554;
  --evapp-analytics-grid:rgba(148,163,184,.18);
  --evapp-analytics-danger:#ffb4b4;
  --evapp-analytics-success:#7cff8d;
  --evapp-card-radius:18px;
  --evapp-shell-radius:26px;
  --evapp-grid-gap:16px;
  --evapp-chart-height:330px;
  width:100%;
  color:var(--evapp-text);
  font-family:inherit;
  line-height:1.45;
}
.evapp-metrics,.evapp-metrics *{box-sizing:border-box}
.evapp-metrics a{text-decoration:none}
.evapp-metrics button,.evapp-metrics input,.evapp-metrics select{font:inherit}
.evapp-metrics [hidden],.evapp-metrics .hidden{display:none!important}
.evapp-metrics .screen-reader-text{
  position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;
  overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important;
}
.evapp-metrics-shell{
  width:100%;
  padding:clamp(18px,3vw,36px);
  background:var(--evapp-app-bg);
  border:1px solid var(--evapp-border);
  border-radius:var(--evapp-shell-radius);
  box-shadow:0 18px 50px rgba(31,52,73,.08);
}
.evapp-metrics-header{
  display:flex;align-items:flex-end;justify-content:space-between;gap:24px;margin-bottom:22px;
}
.evapp-metrics-heading{min-width:0;max-width:800px}
.evapp-metrics-eyebrow{
  margin:0 0 6px;color:var(--evapp-primary);font-size:12px;font-weight:800;letter-spacing:.11em;text-transform:uppercase;
}
.evapp-metrics-title{
  margin:0;color:var(--evapp-text);font-size:clamp(27px,4vw,42px);font-weight:850;line-height:1.08;letter-spacing:-.035em;
}
.evapp-metrics-subtitle{max-width:760px;margin:10px 0 0;color:var(--evapp-muted);font-size:15px}
.evapp-metrics-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex:0 0 auto;flex-wrap:wrap}
.evapp-metrics-button{
  display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:10px 16px;
  border:1px solid var(--evapp-border);border-radius:12px;background:var(--evapp-surface);color:var(--evapp-text)!important;
  font-size:14px;font-weight:750;line-height:1;cursor:pointer;white-space:nowrap;
  transition:transform .18s ease,background-color .18s ease,border-color .18s ease,box-shadow .18s ease,color .18s ease;
}
.evapp-metrics-button svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.evapp-metrics-button:hover{border-color:var(--evapp-primary);color:var(--evapp-primary-dark)!important;transform:translateY(-1px);box-shadow:0 8px 18px rgba(31,73,112,.12)}
.evapp-metrics-button.is-primary{border-color:var(--evapp-primary);background:var(--evapp-primary);color:#fff!important}
.evapp-metrics-button.is-primary:hover{border-color:var(--evapp-primary-dark);background:var(--evapp-primary-dark);color:#fff!important}
.evapp-metrics-button:disabled{opacity:.55;cursor:not-allowed;transform:none;box-shadow:none}
.evapp-metrics-event{
  display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:14px;margin-bottom:20px;padding:14px;
  background:var(--evapp-surface);border:1px solid var(--evapp-border);border-radius:18px;box-shadow:0 8px 24px rgba(31,52,73,.05);
}
.evapp-metrics-event-icon{
  display:flex;align-items:center;justify-content:center;width:46px;height:46px;color:var(--evapp-primary);background:var(--evapp-primary-soft);border-radius:14px;
}
.evapp-metrics-event-icon svg{width:24px;height:24px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.evapp-metrics-event-copy{min-width:0}
.evapp-metrics-event-label{display:block;margin-bottom:2px;color:var(--evapp-muted);font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.evapp-metrics-event-name{display:block;overflow:hidden;color:var(--evapp-text);font-size:16px;font-weight:800;text-overflow:ellipsis;white-space:nowrap}
.evapp-live-badge{
  display:inline-flex;align-items:center;gap:7px;min-height:34px;padding:7px 11px;color:var(--evapp-success);background:var(--evapp-success-soft);
  border:1px solid rgba(21,128,61,.16);border-radius:999px;font-size:12px;font-weight:800;white-space:nowrap;
}
.evapp-live-badge-dot{width:8px;height:8px;border-radius:50%;background:var(--evapp-success);box-shadow:0 0 0 4px rgba(21,128,61,.12)}
.evapp-filter-panel{
  margin-bottom:18px;padding:18px;background:var(--evapp-surface);border:1px solid var(--evapp-border);border-radius:18px;box-shadow:0 8px 24px rgba(31,52,73,.045);
}
.evapp-filter-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:15px}
.evapp-filter-title{margin:0;color:var(--evapp-text);font-size:17px;font-weight:820;letter-spacing:-.01em}
.evapp-filter-help{margin:4px 0 0;color:var(--evapp-muted);font-size:13px}
.evapp-filters{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:12px;align-items:end;margin:0}
.evapp-filters .grp{display:flex;flex-direction:column;gap:6px;grid-column:span 3;min-width:0}
.evapp-filters .grp.is-action{grid-column:span 3}
.evapp-filters label{color:var(--evapp-text);font-size:12px;font-weight:750}
.evapp-filters input[type="date"],.evapp-filters select{
  width:100%;min-height:44px;margin:0;padding:9px 38px 9px 12px;color:var(--evapp-text);background:#fff;
  border:1px solid var(--evapp-border);border-radius:12px;outline:none;box-shadow:none;
  transition:border-color .18s ease,box-shadow .18s ease;
}
.evapp-filters input[type="date"]{padding-right:12px}
.evapp-filters input[type="date"]:focus,.evapp-filters select:focus{border-color:var(--evapp-primary);box-shadow:0 0 0 4px rgba(50,121,189,.13)}
.evapp-filters .evapp-metrics-button{width:100%;min-height:44px}
.evapp-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:var(--evapp-grid-gap);margin-bottom:var(--evapp-grid-gap)}
.evapp-kpi-card{
  position:relative;min-width:0;padding:18px;background:var(--evapp-surface);border:1px solid var(--evapp-border);border-radius:var(--evapp-card-radius);
  box-shadow:0 8px 24px rgba(31,52,73,.05);overflow:hidden;
}
.evapp-kpi-card::after{content:"";position:absolute;inset:auto 0 0;height:3px;background:var(--evapp-primary);opacity:.9}
.evapp-kpi-card.is-success::after{background:var(--evapp-success)}
.evapp-kpi-card.is-warning::after{background:var(--evapp-warning)}
.evapp-kpi-card.is-rate::after{background:var(--evapp-primary-dark)}
.evapp-kpi-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
.evapp-kpi-label{color:var(--evapp-muted);font-size:12px;font-weight:750;letter-spacing:.02em;text-transform:uppercase}
.evapp-kpi-icon{display:flex;align-items:center;justify-content:center;width:38px;height:38px;color:var(--evapp-primary);background:var(--evapp-primary-soft);border-radius:12px}
.evapp-kpi-icon svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.evapp-kpi-card.is-success .evapp-kpi-icon{color:var(--evapp-success);background:var(--evapp-success-soft)}
.evapp-kpi-card.is-warning .evapp-kpi-icon{color:var(--evapp-warning);background:var(--evapp-warning-soft)}
.evapp-kpi-value{display:block;color:var(--evapp-text);font-size:clamp(28px,3vw,38px);font-weight:900;line-height:1;letter-spacing:-.035em}
.evapp-kpi-caption{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:10px;color:var(--evapp-muted);font-size:12px}
.evapp-kpi-scope{display:inline-flex;max-width:100%;padding:3px 8px;color:var(--evapp-primary-dark);background:var(--evapp-primary-soft);border-radius:999px;font-weight:750;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.evapp-m-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:var(--evapp-grid-gap);width:100%}
.evapp-metric-card{
  min-width:0;padding:18px;background:var(--evapp-analytics-bg);color:var(--evapp-analytics-text);border:1px solid var(--evapp-analytics-border);
  border-radius:var(--evapp-card-radius);box-shadow:0 10px 28px rgba(5,12,24,.18);
}
.evapp-metric-card h3{margin:0;color:var(--evapp-analytics-title);font-size:17px;font-weight:820;line-height:1.25;letter-spacing:-.01em}
.evapp-card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:14px}
.evapp-card-kicker{display:block;margin-top:4px;color:var(--evapp-analytics-muted);font-size:12px}
.evapp-pie{grid-column:span 5}
.evapp-bars{grid-column:span 7}
.evapp-table{grid-column:span 12}
.evapp-qr-pie{grid-column:span 5}
.evapp-qr-table{grid-column:span 7}
.evapp-chart-frame{position:relative;width:100%;height:var(--evapp-chart-height);min-height:260px}
.evapp-chart-frame.is-doughnut{height:min(330px,var(--evapp-chart-height))}
.evapp-chart-frame canvas{display:block;width:100%!important;height:100%!important}
.evapp-hint{margin:12px 0 0;color:var(--evapp-analytics-muted);font-size:12px;line-height:1.45}
.evapp-table-scroll,.evapp-custom-table-wrap{
  width:100%;overflow:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--evapp-analytics-border);border-radius:14px;
  background:var(--evapp-analytics-bg);scrollbar-color:var(--evapp-analytics-border) var(--evapp-analytics-bg);
}
.evapp-table table,.evapp-qr-table table,.evapp-custom-table{width:100%;min-width:760px;border-collapse:separate;border-spacing:0;background:var(--evapp-analytics-bg)}
.evapp-table th,.evapp-table td,.evapp-qr-table th,.evapp-qr-table td,.evapp-custom-table th,.evapp-custom-table td{
  padding:11px 13px;text-align:left;vertical-align:middle;border-bottom:1px solid var(--evapp-analytics-border);color:var(--evapp-analytics-text);font-size:13px;white-space:nowrap;
}
.evapp-table thead th,.evapp-qr-table thead th,.evapp-custom-table thead th{
  position:sticky;top:0;z-index:1;background:var(--evapp-analytics-bg-soft);color:var(--evapp-analytics-title);font-size:12px;font-weight:800;
}
.evapp-table thead tr:first-child th,.evapp-custom-table thead tr:first-child th{z-index:3}
.evapp-table thead tr:nth-child(2) th,.evapp-custom-table thead tr:nth-child(2) th{top:41px;z-index:2}
.evapp-table thead th.evapp-table-group-heading,.evapp-custom-table thead th.evapp-table-group-heading{text-align:center;background:var(--evapp-analytics-bg-hover);color:var(--evapp-analytics-title)}
.evapp-table tbody tr,.evapp-qr-table tbody tr,.evapp-custom-table tbody tr{background:var(--evapp-analytics-bg)}
.evapp-table tbody tr:nth-child(even),.evapp-qr-table tbody tr:nth-child(even),.evapp-custom-table tbody tr:nth-child(even){background:var(--evapp-analytics-bg-alt)}
.evapp-table tbody tr:hover,.evapp-qr-table tbody tr:hover,.evapp-custom-table tbody tr:hover{background:var(--evapp-analytics-bg-hover)}
.evapp-table .evapp-total td,.evapp-qr-table .evapp-total td,.evapp-custom-table tbody tr.evapp-custom-total-row td{
  font-weight:850;color:var(--evapp-analytics-text);background:var(--evapp-analytics-bg-soft);border-top:1px solid rgba(79,124,255,.46);
}
.evapp-footnote{margin:10px 0 0;color:var(--evapp-analytics-muted);font-size:12px;line-height:1.45}
.evapp-metrics .evapp-muted{color:var(--evapp-muted)!important}
.evapp-metrics .evapp-bad{color:var(--evapp-danger)!important}
.evapp-metrics .evapp-ok{color:var(--evapp-success)!important}
.evapp-metric-card .evapp-muted,.evapp-custom-table-wrap .evapp-muted{color:var(--evapp-analytics-muted)!important}
.evapp-metric-card .evapp-bad,.evapp-custom-table-wrap .evapp-bad{color:var(--evapp-analytics-danger)!important}
.evapp-metric-card .evapp-ok,.evapp-custom-table-wrap .evapp-ok{color:var(--evapp-analytics-success)!important}
.evapp-custom-metrics-panel{grid-column:span 12;display:none}
.evapp-custom-metrics-panel.is-visible{display:block}
.evapp-custom-toolbar{
  display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:12px;padding:16px 18px;
  background:var(--evapp-surface);border:1px solid var(--evapp-border);border-radius:var(--evapp-card-radius);box-shadow:0 8px 24px rgba(31,52,73,.05);
}
.evapp-custom-toolbar-title{margin:0 0 3px;color:var(--evapp-text);font-size:16px;font-weight:850}
.evapp-custom-toolbar-status{color:var(--evapp-muted);font-size:12px;line-height:1.4}
.evapp-custom-toolbar-status.is-loading{color:var(--evapp-warning)}
.evapp-custom-toolbar-status.is-error{color:var(--evapp-danger)}
.evapp-custom-metrics-shell{display:none}
.evapp-custom-metrics-shell.is-visible{display:block}
.evapp-custom-title{
  margin:4px 0 12px;padding:11px 14px;color:var(--evapp-text);background:var(--evapp-surface);border:1px solid var(--evapp-border);
  border-left:4px solid var(--evapp-custom-accent,var(--evapp-primary));border-radius:12px;font-size:17px;font-weight:850;
}
.evapp-custom-row{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:var(--evapp-grid-gap);margin-bottom:var(--evapp-grid-gap)}
.evapp-custom-slot{grid-column:span 12;min-height:160px}
.evapp-custom-slot.span-1{grid-column:span 6}
.evapp-custom-slot.span-2{grid-column:span 12}
.evapp-custom-card-value{margin-top:14px;color:var(--evapp-analytics-text);font-size:clamp(30px,4vw,44px);font-weight:900;line-height:1;letter-spacing:-.035em}
.evapp-custom-card-label{margin-top:7px;color:var(--evapp-analytics-muted);font-size:13px}
.evapp-custom-empty{padding:22px 0;color:var(--evapp-analytics-muted);font-size:13px}
.evapp-custom-chart-frame{position:relative;width:100%;min-height:300px;margin-top:12px}
.evapp-custom-chart-frame canvas{width:100%!important;height:100%!important}
.evapp-custom-chart-values{display:grid;gap:6px;margin-top:12px;color:var(--evapp-analytics-muted);font-size:12px}
.evapp-custom-chart-values div{display:flex;justify-content:space-between;gap:12px;padding-bottom:5px;border-bottom:1px solid var(--evapp-analytics-border)}
.evapp-metrics.is-loading .evapp-live-badge-dot{animation:evappMetricsPulse 1.1s ease-in-out infinite}
@keyframes evappMetricsPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.45;transform:scale(.72)}}
@media(max-width:1099px){
  .evapp-metrics-header{align-items:flex-start}
  .evapp-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .evapp-filters .grp{grid-column:span 4}
  .evapp-pie,.evapp-bars,.evapp-qr-pie,.evapp-qr-table{grid-column:span 12}
}
@media(max-width:767px){
  .evapp-metrics-shell{padding:16px;border-radius:20px}
  .evapp-metrics-header{display:block;margin-bottom:18px}
  .evapp-metrics-actions{justify-content:flex-start;margin-top:15px}
  .evapp-metrics-event{grid-template-columns:auto minmax(0,1fr)}
  .evapp-live-badge{grid-column:1/-1;justify-content:center}
  .evapp-filter-header{display:block}
  .evapp-filters .grp,.evapp-filters .grp.is-action{grid-column:span 6}
  .evapp-chart-frame{height:300px;min-height:240px}
  .evapp-custom-slot.span-1,.evapp-custom-slot.span-2{grid-column:span 12}
}
@media(max-width:520px){
  .evapp-metrics-actions{display:grid;grid-template-columns:1fr;width:100%}
  .evapp-metrics-button{width:100%}
  .evapp-kpi-grid{grid-template-columns:1fr}
  .evapp-filters .grp,.evapp-filters .grp.is-action{grid-column:span 12}
  .evapp-metric-card{padding:15px}
  .evapp-chart-frame{height:270px}
  .evapp-custom-toolbar{display:block}
  .evapp-custom-toolbar .evapp-metrics-button{margin-top:12px}
}
@media(prefers-reduced-motion:reduce){.evapp-metrics *{scroll-behavior:auto!important;transition:none!important;animation:none!important}}
        </style>
        <?php
    }
}

if ( ! function_exists('eventosapp_render_metrics') ) {
    function eventosapp_render_metrics($args = []) {
        $defaults = [
            'show_header'          => 'yes',
            'eyebrow'              => 'EventosApp',
            'title'                => 'Métricas en tiempo real',
            'subtitle'             => 'Consulta la asistencia, los horarios de ingreso, las localidades y los medios de check-in del evento activo.',
            'show_event_context'   => 'yes',
            'show_back_button'     => 'yes',
            'back_button_label'    => 'Volver al dashboard',
            'dashboard_url'        => '',
            'show_export_button'   => 'yes',
            'export_button_label'  => 'Descargar base (Excel)',
            'show_filters'         => 'yes',
            'filter_title'         => 'Filtros de análisis',
            'filter_description'   => 'Define el periodo y el tipo de check-in que deseas revisar.',
            'apply_button_label'   => 'Aplicar filtros',
            'refresh_interval'     => 15000,
            'instance_id'          => '',
        ];
        $args = wp_parse_args(is_array($args) ? $args : [], $defaults);

        if ( function_exists('eventosapp_require_feature') ) {
            eventosapp_require_feature('metrics');
        }

        ob_start();
        eventosapp_metrics_print_frontend_styles();
        $metrics_css = ob_get_clean();

        $active_event = function_exists('eventosapp_get_active_event') ? absint(eventosapp_get_active_event()) : 0;
        if ( ! $active_event ) {
            ob_start();
            echo $metrics_css;
            if (function_exists('eventosapp_require_active_event')) {
                eventosapp_require_active_event();
            } else {
                echo '<div class="evapp-metrics"><div class="evapp-metrics-shell"><p class="evapp-muted">Debes seleccionar un evento activo.</p></div></div>';
            }
            return ob_get_clean();
        }

        $instance_id = sanitize_html_class((string) $args['instance_id']);
        if ($instance_id === '') {
            $instance_id = function_exists('wp_unique_id') ? wp_unique_id('evapp-metrics-') : uniqid('evapp-metrics-', false);
        }

        $nonce_data   = wp_create_nonce('eventosapp_metrics_data');
        $nonce_export = wp_create_nonce('eventosapp_export_tickets');
        $dashboard_url = eventosapp_metrics_get_dashboard_return_url($args['dashboard_url']);
        $export_url = add_query_arg([
            'action'   => 'eventosapp_export_tickets',
            'security' => $nonce_export,
        ], admin_url('admin-ajax.php'));
        $refresh_interval = max(0, absint($args['refresh_interval']));
        $id = static function($key) use ($instance_id) {
            return $instance_id . '-' . sanitize_html_class($key);
        };

        ob_start();
        echo $metrics_css;
        ?>
        <div
            class="evapp-metrics"
            id="<?php echo esc_attr($instance_id); ?>"
            data-evapp-metrics
            data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
            data-ajax-nonce="<?php echo esc_attr($nonce_data); ?>"
            data-refresh-interval="<?php echo esc_attr($refresh_interval); ?>"
            data-event="<?php echo esc_attr($active_event); ?>"
        >
          <div class="evapp-metrics-shell">
            <?php if ($args['show_header'] === 'yes') : ?>
              <header class="evapp-metrics-header">
                <div class="evapp-metrics-heading">
                  <?php if (trim((string) $args['eyebrow']) !== '') : ?><p class="evapp-metrics-eyebrow"><?php echo esc_html($args['eyebrow']); ?></p><?php endif; ?>
                  <?php if (trim((string) $args['title']) !== '') : ?><h2 class="evapp-metrics-title"><?php echo esc_html($args['title']); ?></h2><?php endif; ?>
                  <?php if (trim((string) $args['subtitle']) !== '') : ?><p class="evapp-metrics-subtitle"><?php echo esc_html($args['subtitle']); ?></p><?php endif; ?>
                </div>
                <?php if ($args['show_back_button'] === 'yes' || $args['show_export_button'] === 'yes') : ?>
                  <div class="evapp-metrics-actions">
                    <?php if ($args['show_back_button'] === 'yes') : ?>
                      <a class="evapp-metrics-button" href="<?php echo esc_url($dashboard_url); ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/><path d="M9 12h10"/></svg>
                        <?php echo esc_html($args['back_button_label']); ?>
                      </a>
                    <?php endif; ?>
                    <?php if ($args['show_export_button'] === 'yes') : ?>
                      <a class="evapp-metrics-button is-primary" data-evapp-id="evappExportBtn" href="<?php echo esc_url($export_url); ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
                        <?php echo esc_html($args['export_button_label']); ?>
                      </a>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </header>
            <?php endif; ?>

            <?php if ($args['show_event_context'] === 'yes') : ?>
              <div class="evapp-metrics-event">
                <div class="evapp-metrics-event-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 9h18"/></svg>
                </div>
                <div class="evapp-metrics-event-copy">
                  <span class="evapp-metrics-event-label">Evento activo</span>
                  <span class="evapp-metrics-event-name"><?php echo esc_html(get_the_title($active_event)); ?></span>
                </div>
                <div class="evapp-live-badge"><span class="evapp-live-badge-dot"></span><span>Actualización automática</span></div>
              </div>
            <?php endif; ?>

            <section class="evapp-filter-panel"<?php echo $args['show_filters'] === 'yes' ? '' : ' hidden'; ?>>
              <div class="evapp-filter-header">
                <div>
                  <h3 class="evapp-filter-title"><?php echo esc_html($args['filter_title']); ?></h3>
                  <?php if (trim((string) $args['filter_description']) !== '') : ?><p class="evapp-filter-help"><?php echo esc_html($args['filter_description']); ?></p><?php endif; ?>
                </div>
              </div>
              <div class="evapp-filters" data-evapp-id="evappFilters">
                <div class="grp">
                  <label for="<?php echo esc_attr($id('mode')); ?>">Modo</label>
                  <select id="<?php echo esc_attr($id('mode')); ?>" data-evapp-id="evappMode">
                    <option value="sum">Acumulado por horas (rango)</option>
                    <option value="day">Por día</option>
                  </select>
                </div>
                <div class="grp" data-evapp-id="grpFrom">
                  <label for="<?php echo esc_attr($id('from')); ?>">Desde</label>
                  <input type="date" id="<?php echo esc_attr($id('from')); ?>" data-evapp-id="evappFrom" />
                </div>
                <div class="grp" data-evapp-id="grpTo">
                  <label for="<?php echo esc_attr($id('to')); ?>">Hasta</label>
                  <input type="date" id="<?php echo esc_attr($id('to')); ?>" data-evapp-id="evappTo" />
                </div>
                <div class="grp hidden" data-evapp-id="grpDay">
                  <label for="<?php echo esc_attr($id('day')); ?>">Fecha</label>
                  <input type="date" id="<?php echo esc_attr($id('day')); ?>" data-evapp-id="evappDay" />
                </div>
                <div class="grp">
                  <label for="<?php echo esc_attr($id('checkin-type')); ?>">Tipo de check-in</label>
                  <select id="<?php echo esc_attr($id('checkin-type')); ?>" data-evapp-id="evappCheckinType">
                    <option value="all">Todos</option>
                    <option value="presencial">Presencial</option>
                    <option value="virtual">Virtual</option>
                  </select>
                </div>
                <div class="grp is-action">
                  <label class="screen-reader-text" for="<?php echo esc_attr($id('apply')); ?>">Aplicar filtros</label>
                  <button
                    type="button"
                    class="evapp-metrics-button is-primary"
                    id="<?php echo esc_attr($id('apply')); ?>"
                    data-evapp-id="evappApply"
                    data-default-label="<?php echo esc_attr($args['apply_button_label']); ?>"
                  ><?php echo esc_html($args['apply_button_label']); ?></button>
                </div>
              </div>
            </section>

            <div class="evapp-kpi-grid" aria-label="Resumen de asistencia">
              <article class="evapp-kpi-card">
                <div class="evapp-kpi-top"><span class="evapp-kpi-label">Tickets totales</span><span class="evapp-kpi-icon"><svg viewBox="0 0 24 24"><path d="M5 7h14v3a2 2 0 0 0 0 4v3H5v-3a2 2 0 0 0 0-4V7Z"/><path d="M9 7v10"/></svg></span></div>
                <strong class="evapp-kpi-value" data-evapp-id="evappKpiTotal">0</strong>
                <div class="evapp-kpi-caption"><span>Base del evento activo</span></div>
              </article>
              <article class="evapp-kpi-card is-success">
                <div class="evapp-kpi-top"><span class="evapp-kpi-label">Con check-in</span><span class="evapp-kpi-icon"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg></span></div>
                <strong class="evapp-kpi-value" data-evapp-id="evappKpiChecked">0</strong>
                <div class="evapp-kpi-caption"><span>Asistentes registrados</span><span class="evapp-kpi-scope" data-evapp-id="evappKpiScope">Todos</span></div>
              </article>
              <article class="evapp-kpi-card is-warning">
                <div class="evapp-kpi-top"><span class="evapp-kpi-label">Pendientes</span><span class="evapp-kpi-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span></div>
                <strong class="evapp-kpi-value" data-evapp-id="evappKpiPending">0</strong>
                <div class="evapp-kpi-caption"><span>Sin check-in registrado</span></div>
              </article>
              <article class="evapp-kpi-card is-rate">
                <div class="evapp-kpi-top"><span class="evapp-kpi-label">Asistencia</span><span class="evapp-kpi-icon"><svg viewBox="0 0 24 24"><path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/></svg></span></div>
                <strong class="evapp-kpi-value" data-evapp-id="evappKpiRate">0.00%</strong>
                <div class="evapp-kpi-caption"><span>Participación sobre tickets</span></div>
              </article>
            </div>

            <div class="evapp-m-grid">
              <section class="evapp-metric-card evapp-pie">
                <div class="evapp-card-header"><div><h3>Asistencia principal</h3><span class="evapp-card-kicker">Distribución de asistentes con y sin check-in</span></div></div>
                <div class="evapp-chart-frame is-doughnut"><canvas data-evapp-id="evappPie"></canvas></div>
                <div class="evapp-hint" data-evapp-id="evappPieHint"></div>
              </section>

              <section class="evapp-metric-card evapp-bars">
                <div class="evapp-card-header"><div><h3 data-evapp-id="evappBarsTitle">Check-ins por hora</h3><span class="evapp-card-kicker">Comportamiento de ingreso durante el periodo seleccionado</span></div></div>
                <div class="evapp-chart-frame"><canvas data-evapp-id="evappBars"></canvas></div>
                <div class="evapp-hint">Azul = presencial. Violeta = virtual. Las sesiones adicionales conservan colores diferenciados.</div>
              </section>

              <section class="evapp-metric-card evapp-table">
                <div class="evapp-card-header"><div><h3>Resumen por localidad</h3><span class="evapp-card-kicker">Comparativo de asistencia y participación por tipo de entrada</span></div></div>
                <div class="evapp-table-scroll">
                  <table>
                    <thead data-evapp-id="evappTableHead"><tr><th>Localidad</th><th>Check-ins</th><th>Not Check-ins</th><th>% Asistencia</th><th>Sesiones adicionales</th><th>% sesiones</th></tr></thead>
                    <tbody data-evapp-id="evappTableBody"><tr><td colspan="6" class="evapp-muted">Cargando…</td></tr></tbody>
                  </table>
                </div>
                <div class="evapp-footnote">“Sesiones adicionales” cuenta asistentes únicos que confirmaron al menos una sesión.</div>
              </section>

              <section class="evapp-metric-card evapp-qr-pie">
                <div class="evapp-card-header"><div><h3>Check-ins por medio</h3><span class="evapp-card-kicker">Participación real de cada canal sobre el total de tickets</span></div></div>
                <div class="evapp-chart-frame is-doughnut"><canvas data-evapp-id="evappQrPie"></canvas></div>
                <div class="evapp-hint" data-evapp-id="evappQrPieHint"></div>
              </section>

              <section class="evapp-metric-card evapp-qr-table">
                <div class="evapp-card-header"><div><h3>Estadísticas por medio</h3><span class="evapp-card-kicker">Detalle de check-ins y porcentaje de participación</span></div></div>
                <div class="evapp-table-scroll">
                  <table>
                    <thead><tr><th>Medio</th><th>Check-ins</th><th>% Participación</th></tr></thead>
                    <tbody data-evapp-id="evappQrTableBody"><tr><td colspan="3" class="evapp-muted">Cargando…</td></tr></tbody>
                  </table>
                </div>
              </section>

              <section data-evapp-id="evappCustomMetricsPanel" class="evapp-custom-metrics-panel" aria-live="polite">
                <div class="evapp-custom-toolbar">
                  <div>
                    <div class="evapp-custom-toolbar-title">Métricas personalizadas</div>
                    <div data-evapp-id="evappCustomMetricsStatus" class="evapp-custom-toolbar-status">Esperando a que terminen de cargar las métricas por defecto…</div>
                  </div>
                  <button type="button" class="evapp-metrics-button" data-evapp-id="evappCustomReloadBtn" disabled>Recargar personalizadas</button>
                </div>
                <div data-evapp-id="evappCustomMetrics" class="evapp-custom-metrics-shell"></div>
              </section>
            </div>
          </div>
        </div>
        <?php

        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );

        if ( ! wp_script_is('eventosapp-front-metrics', 'registered') ) {
            wp_register_script('eventosapp-front-metrics', '', ['chartjs'], null, true);
        }

        $js = <<<'JS'
    (function(){
        const root = document.getElementById(__EVAPP_METRICS_ROOT__);
        if (!root || root.dataset.evappMetricsReady === '1') return;
        root.dataset.evappMetricsReady = '1';

        const find = function(name){
            return root.querySelector('[data-evapp-id="' + name + '"]');
        };
        const ajaxURL   = root.getAttribute('data-ajax-url') || '';
        const ajaxNonce = root.getAttribute('data-ajax-nonce') || '';
        const refreshInterval = Math.max(0, Number(root.getAttribute('data-refresh-interval') || 15000));

        const kpiTotal   = find('evappKpiTotal');
        const kpiChecked = find('evappKpiChecked');
        const kpiPending = find('evappKpiPending');
        const kpiRate    = find('evappKpiRate');
        const kpiScope   = find('evappKpiScope');
        const tableBody  = find('evappTableBody');
        const tableHead  = find('evappTableHead');
        const pieHint    = find('evappPieHint');
        const barsTitle  = find('evappBarsTitle');
        
        // Referencias para gráfico y tabla de medios de check-in
        const qrPieHint = find('evappQrPieHint');
        const qrTableBody = find('evappQrTableBody');
        const customMetricsPanel = find('evappCustomMetricsPanel');
        const customMetricsWrap = find('evappCustomMetrics');
        const customMetricsStatus = find('evappCustomMetricsStatus');
        const customReloadBtn = find('evappCustomReloadBtn');

        // Filtros
        const modeSel = find('evappMode');
        const gFrom   = find('grpFrom');
        const gTo     = find('grpTo');
        const gDay    = find('grpDay');
        const inFrom  = find('evappFrom');
        const inTo    = find('evappTo');
        const inDay   = find('evappDay');
        const btnApply= find('evappApply');
        const checkinTypeSel = find('evappCheckinType');

        function toggleInputs(){
            const mode = modeSel ? modeSel.value : 'day';
            if (mode === 'sum'){
                gFrom.classList.remove('hidden');
                gTo.classList.remove('hidden');
                gDay.classList.add('hidden');
            } else {
                gFrom.classList.add('hidden');
                gTo.classList.add('hidden');
                gDay.classList.remove('hidden');
            }
        }
        if (modeSel) modeSel.addEventListener('change', toggleInputs);
        toggleInputs();

        let pieChart = null;
        let barChart = null;
        let qrPieChart = null; // Chart para medios de check-in
        let customCharts = {}; // Gráficos personalizados por evento

        let defaultFetchInProgress = false;
        let defaultFetchPromise = null;
        let pendingDefaultFetch = false;
        let customFetchInProgress = false;
        let customReloadQueued = false;
        let customHasLoaded = false;
        let customMetricsAvailable = false;

        // Color estable por nombre de sesión
        function colorFor(text){
            let h = 0;
            for(let i=0;i<text.length;i++){ h = (h * 31 + text.charCodeAt(i)) >>> 0; }
            const hue = h % 360, sat = 70, lig = 52;
            function hslToRgb(h, s, l){
                s/=100; l/=100;
                const k = n => (n + h/30) % 12;
                const a = s * Math.min(l, 1-l);
                const f = n => l - a * Math.max(-1, Math.min(k(n)-3, Math.min(9-k(n), 1)));
                return [Math.round(255*f(0)), Math.round(255*f(8)), Math.round(255*f(4))];
            }
            const [r,g,b] = hslToRgb(hue, sat, lig);
            return 'rgb(' + r + ', ' + g + ', ' + b + ')';
        }

        function fmt(n){ return (n||0).toLocaleString(); }
        function pct(n){ return (Math.round((n||0)*100)/100).toFixed(2) + '%'; }
        function toNumber(n){
            n = Number(n || 0);
            return Number.isFinite(n) ? n : 0;
        }
        function calcPercent(value, base){
            value = toNumber(value);
            base = toNumber(base);
            return base > 0 ? (value * 100 / base) : 0;
        }

        function cssVar(name, fallback){
            const value = window.getComputedStyle(root).getPropertyValue(name).trim();
            return value || fallback;
        }

        function analyticsTheme(){
            return {
                text: cssVar('--evapp-analytics-text', '#eaf1ff'),
                muted: cssVar('--evapp-analytics-muted', '#a9b6d3'),
                grid: cssVar('--evapp-analytics-grid', 'rgba(148,163,184,.18)')
            };
        }

        function setCustomPanelVisible(visible){
            if (!customMetricsPanel) return;
            if (visible) customMetricsPanel.classList.add('is-visible');
            else customMetricsPanel.classList.remove('is-visible');
        }

        function setCustomStatus(message, state){
            if (!customMetricsStatus) return;
            customMetricsStatus.textContent = message || '';
            customMetricsStatus.classList.remove('is-loading', 'is-error');
            if (state === 'loading') customMetricsStatus.classList.add('is-loading');
            if (state === 'error') customMetricsStatus.classList.add('is-error');
        }

        function setCustomButton(disabled, label){
            if (!customReloadBtn) return;
            customReloadBtn.disabled = !!disabled;
            if (label) customReloadBtn.textContent = label;
        }

        function setDefaultLoading(loading){
            root.classList.toggle('is-loading', !!loading);
            if (!btnApply) return;
            btnApply.disabled = !!loading;
            const defaultLabel = btnApply.getAttribute('data-default-label') || 'Aplicar filtros';
            btnApply.textContent = loading ? 'Actualizando…' : defaultLabel;
        }

        function getCurrentFilters(){
            const mode = modeSel ? modeSel.value : 'sum';
            const filters = {
                mode: mode,
                checkin_type: checkinTypeSel ? checkinTypeSel.value : 'all'
            };
            if (mode === 'sum') {
                filters.from = inFrom && inFrom.value ? inFrom.value : '';
                filters.to = inTo && inTo.value ? inTo.value : '';
            } else {
                filters.day = inDay && inDay.value ? inDay.value : '';
            }
            return filters;
        }

        function renderPie(data){
            const canvas = find('evappPie');
            if (!canvas || typeof Chart === 'undefined') return;
            const ctx = canvas.getContext('2d');
            const theme = analyticsTheme();
            const checked = data.checked_in_total || 0;
            const notc    = data.not_checked_in_total || 0;
            const total   = Math.max(checked + notc, 0);

            const checkedPct = total ? (checked*100/total) : 0;
            const notPct     = total ? (notc   *100/total) : 0;
            if (pieHint) pieHint.textContent = `Checked In: ${fmt(checked)} (${pct(checkedPct)}) · Not Checked In: ${fmt(notc)} (${pct(notPct)})`;

            const cfg = {
                type: 'doughnut',
                data: {
                    labels: ['Checked In', 'Not Checked In'],
                    datasets: [{ data:[checked, notc], backgroundColor: ['#4f7cff', '#94a3b8'], borderWidth: 0 }]
                },
                options: { responsive:true, maintainAspectRatio:false, plugins: { legend:{ position:'bottom', labels:{ color:theme.text, boxWidth:12, boxHeight:12, padding:16 } } } }
            };
            if (pieChart){ pieChart.data = cfg.data; pieChart.update(); }
            else { pieChart = new Chart(ctx, cfg); }
        }

        function renderBars(data){
            const canvas = find('evappBars');
            if (!canvas || typeof Chart === 'undefined') return;
            const ctx = canvas.getContext('2d');
            const theme = analyticsTheme();
            const labels = (data.bar && data.bar.labels) ? data.bar.labels : [];
            const datasets = [];
            const selectedType = (data.bar && data.bar.checkin_type) ? data.bar.checkin_type : 'all';
            const showVirtual = !!data.show_virtual_metrics;
            const showPresencial = data.show_presencial_metrics !== false;

            if (showPresencial && (selectedType === 'all' || selectedType === 'presencial')) {
                datasets.push({
                    label: 'Presencial',
                    data: (data.bar && data.bar.main) ? data.bar.main : [],
                    backgroundColor: '#4f7cff',
                    borderWidth: 0,
                    stack: 'checkins'
                });
            }

            if (showVirtual && (selectedType === 'all' || selectedType === 'virtual')) {
                datasets.push({
                    label: 'Virtual',
                    data: (data.bar && data.bar.virtual) ? data.bar.virtual : [],
                    backgroundColor: '#8b5cf6',
                    borderWidth: 0,
                    stack: 'checkins'
                });
            }

            // Sesiones adicionales se conservan porque son un tercer medio operativo del dashboard.
            const ses = (data.bar && data.bar.sessions) ? data.bar.sessions : {};
            Object.keys(ses).sort().forEach(name=>{
                datasets.push({ label: name, data: ses[name], backgroundColor: colorFor(name), borderWidth: 0, stack: 'sessions' });
            });

            // Título dinámico
            const suffix = data.checkin_filter_label ? (' · ' + data.checkin_filter_label) : '';
            if (data.bar && data.bar.mode === 'day'){
                barsTitle.textContent = 'Check-ins por hora — ' + (data.bar.day || '') + suffix;
            } else if (data.bar) {
                barsTitle.textContent = 'Check-ins por hora (acumulado) — ' + (data.bar.from || '') + ' a ' + (data.bar.to || '') + suffix;
            }

            const cfg = {
                type: 'bar',
                data: { labels, datasets },
                options: {
                    responsive:true,
                    maintainAspectRatio:false,
                    scales: {
                        x: { stacked:true, ticks:{ color:theme.muted }, grid:{ color:theme.grid} },
                        y: { stacked:true, beginAtZero:true, ticks:{ color:theme.muted }, grid:{ color:theme.grid} }
                    },
                    plugins: { legend:{ position:'bottom', labels:{ color:theme.text, boxWidth:12, boxHeight:12, padding:16 } } }
                }
            };
            if (barChart){ barChart.data = cfg.data; barChart.update(); }
            else { barChart = new Chart(ctx, cfg); }
        }

        function escapeHTML(s){
            return String(s)
                .replace(/&/g,'&amp;')
                .replace(/</g,'&lt;')
                .replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;')
                .replace(/'/g,'&#039;');
        }

        function plainTextFromHTML(raw){
            raw = String(raw || '');
            if (!raw) return '';

            const tmp = document.createElement('div');
            tmp.innerHTML = raw;
            return (tmp.textContent || tmp.innerText || raw)
                .replace(/\s+/g, ' ')
                .trim();
        }

        async function readAjaxJSON(resp){
            const raw = await resp.text();
            let payload = null;

            if (raw) {
                try {
                    payload = JSON.parse(raw);
                } catch (parseError) {
                    const clean = plainTextFromHTML(raw);
                    const msg = clean
                        ? clean.substring(0, 220)
                        : 'El servidor devolvió una respuesta vacía o no válida.';
                    throw new Error('Respuesta no JSON de admin-ajax (' + resp.status + '): ' + msg);
                }
            }

            if (!resp.ok) {
                const serverMsg = payload && payload.data && payload.data.error ? payload.data.error : '';
                throw new Error(serverMsg || ('Error HTTP ' + resp.status + ' al cargar métricas.'));
            }

            if (!payload) {
                throw new Error('admin-ajax no devolvió datos.');
            }

            return payload;
        }

        function renderTable(rows, data){
            data = data || {};
            const selectedType = data.checkin_type || 'all';
            const showVirtual = !!data.show_virtual_metrics;
            const showPresencial = data.show_presencial_metrics !== false;
            const useDetailed = showVirtual && showPresencial;

            function setTableHeader(rowTitle, groupTitle, indicatorCols, totalTitle){
                if (!tableHead) return;
                indicatorCols = Array.isArray(indicatorCols) ? indicatorCols : [];
                totalTitle = totalTitle || '';

                let html = '<tr class="evapp-table-superhead">';
                html += '<th class="evapp-table-row-heading" rowspan="2">' + escapeHTML(rowTitle || 'Indicador') + '</th>';
                if (indicatorCols.length) {
                    html += '<th class="evapp-table-group-heading" colspan="' + indicatorCols.length + '">' + escapeHTML(groupTitle || 'Indicadores') + '</th>';
                }
                if (totalTitle) {
                    html += '<th class="evapp-table-total-heading" rowspan="2">' + escapeHTML(totalTitle) + '</th>';
                }
                html += '</tr><tr class="evapp-table-subhead">';
                indicatorCols.forEach(function(col){
                    html += '<th>' + escapeHTML(col) + '</th>';
                });
                html += '</tr>';
                tableHead.innerHTML = html;
            }

            const rowTitle = 'Localidad';
            let indicatorCols = [];
            if (useDetailed && selectedType === 'all') {
                indicatorCols = ['Check-ins presenciales', 'Check-ins virtuales', 'Check-ins únicos', 'Not Check-ins', '% Asistencia', 'Check-ins sesiones adicionales (únicos)', '% asistentes a sesiones'];
            } else if (selectedType === 'virtual') {
                indicatorCols = ['Check-ins virtuales', 'Not Check-ins', '% Asistencia', 'Check-ins sesiones adicionales (únicos)', '% asistentes a sesiones'];
            } else if (selectedType === 'presencial') {
                indicatorCols = ['Check-ins presenciales', 'Not Check-ins', '% Asistencia', 'Check-ins sesiones adicionales (únicos)', '% asistentes a sesiones'];
            } else {
                indicatorCols = ['Check-ins', 'Not Check-ins', '% Asistencia', 'Check-ins sesiones adicionales (únicos)', '% asistentes a sesiones'];
            }
            const cols = [rowTitle].concat(indicatorCols);
            setTableHeader(rowTitle, 'Indicadores de asistencia', indicatorCols, '');

            if (!rows || !rows.length){
                tableBody.innerHTML = '<tr><td colspan="' + cols.length + '" class="evapp-muted">Sin datos.</td></tr>';
                return;
            }

            let sumChk = 0, sumNot = 0, sumSesUniq = 0, sumPres = 0, sumVirt = 0;
            rows.forEach(r=>{
                sumChk     += (r.checkins || 0);
                sumNot     += (r.not_checkins || 0);
                sumSesUniq += (r.checkins_sesiones_unicos || 0);
                sumPres    += (r.checkins_presencial || 0);
                sumVirt    += (r.checkins_virtual || 0);
            });
            const totalTickets = sumChk + sumNot;
            const pctAsisTotal = totalTickets ? (sumChk * 100 / totalTickets) : 0;
            const pctSesTotal  = totalTickets ? (sumSesUniq * 100 / totalTickets) : 0;

            const bodyHTML = rows.map(function(r){
                let cells = '<td>' + escapeHTML(r.localidad || '—') + '</td>';
                if (useDetailed && selectedType === 'all') {
                    cells += '<td>' + fmt(r.checkins_presencial) + '</td>';
                    cells += '<td>' + fmt(r.checkins_virtual) + '</td>';
                    cells += '<td>' + fmt(r.checkins) + '</td>';
                } else if (selectedType === 'virtual') {
                    cells += '<td>' + fmt(r.checkins_virtual != null ? r.checkins_virtual : r.checkins) + '</td>';
                } else if (selectedType === 'presencial') {
                    cells += '<td>' + fmt(r.checkins_presencial != null ? r.checkins_presencial : r.checkins) + '</td>';
                } else {
                    cells += '<td>' + fmt(r.checkins) + '</td>';
                }
                cells += '<td>' + fmt(r.not_checkins) + '</td>';
                cells += '<td>' + (r.pct_asistencia != null ? (Math.round(r.pct_asistencia*100)/100).toFixed(2) : '0.00') + '%</td>';
                cells += '<td>' + fmt(r.checkins_sesiones_unicos) + '</td>';
                cells += '<td>' + (r.pct_sesiones != null ? (Math.round(r.pct_sesiones*100)/100).toFixed(2) : '0.00') + '%</td>';
                return '<tr>' + cells + '</tr>';
            }).join('');

            let totalCells = '<td>Total</td>';
            if (useDetailed && selectedType === 'all') {
                totalCells += '<td>' + fmt(sumPres) + '</td>';
                totalCells += '<td>' + fmt(sumVirt) + '</td>';
                totalCells += '<td>' + fmt(sumChk) + '</td>';
            } else if (selectedType === 'virtual') {
                totalCells += '<td>' + fmt(sumVirt) + '</td>';
            } else if (selectedType === 'presencial') {
                totalCells += '<td>' + fmt(sumPres) + '</td>';
            } else {
                totalCells += '<td>' + fmt(sumChk) + '</td>';
            }
            totalCells += '<td>' + fmt(sumNot) + '</td>';
            totalCells += '<td>' + (pctAsisTotal.toFixed(2)) + '%</td>';
            totalCells += '<td>' + fmt(sumSesUniq) + '</td>';
            totalCells += '<td>' + (pctSesTotal.toFixed(2)) + '%</td>';

            tableBody.innerHTML = bodyHTML + '<tr class="evapp-total">' + totalCells + '</tr>';
        }
        
        // Renderizar gráfico de torta de medios de check-in con porcentaje real de participación.
        function renderQrPie(qrStats){
            if (!qrStats || !qrStats.types || Object.keys(qrStats.types).length === 0) {
                if (qrPieHint) qrPieHint.textContent = 'No hay datos de medios de check-in disponibles';
                if (qrPieChart) {
                    qrPieChart.destroy();
                    qrPieChart = null;
                }
                return;
            }
            
            const canvas = find('evappQrPie');
            if (!canvas || typeof Chart === 'undefined') return;
            const ctx = canvas.getContext('2d');
            const theme = analyticsTheme();
            const types = qrStats.types || {};
            const mediaTotal = toNumber(qrStats.distribution_total || qrStats.total || 0);
            const participationBase = toNumber(qrStats.participation_base || qrStats.checked_total || qrStats.total || 0);
            const checkedTotal = toNumber(qrStats.checked_total || mediaTotal);
            const withoutMediaTotal = toNumber(qrStats.without_media_total || 0);
            const notCheckedTotal = toNumber(qrStats.not_checked_total || Math.max(participationBase - checkedTotal, 0));
            const labels = [];
            const dataValues = [];
            const colors = {
                'Email': '#4f7cff',
                'Google Wallet': '#34a853',
                'Apple Wallet': '#E5E5EA',
                'PDF Impreso': '#f59e0b',
                'WhatsApp': '#25D366',
                'Escarapela Impresa': '#8b5cf6',
                'QR Legacy': '#94a3b8',
                'QR Preimpreso': '#64748b',
                'Acceso virtual': '#8b5cf6',
                'Check-in virtual': '#8b5cf6',
                'Check-ins sin medio': '#cbd5e1',
                'Sin check-in': '#334155'
            };
            
            const backgroundColors = [];
            
            Object.keys(types).sort().forEach(type => {
                labels.push(type);
                dataValues.push(toNumber(types[type]));
                backgroundColors.push(colors[type] || colorFor(type));
            });

            if (withoutMediaTotal > 0) {
                labels.push('Check-ins sin medio');
                dataValues.push(withoutMediaTotal);
                backgroundColors.push(colors['Check-ins sin medio']);
            }

            if (notCheckedTotal > 0) {
                labels.push('Sin check-in');
                dataValues.push(notCheckedTotal);
                backgroundColors.push(colors['Sin check-in']);
            }
            
            const checkedPct = calcPercent(checkedTotal, participationBase);
            if (qrPieHint) qrPieHint.textContent = `Base real: ${fmt(participationBase)} tickets · Participación registrada: ${fmt(checkedTotal)} (${pct(checkedPct)}) · Check-ins por medio: ${fmt(mediaTotal)}`;
            
            const cfg = {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: dataValues,
                        backgroundColor: backgroundColors,
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: theme.text, boxWidth:12, boxHeight:12, padding:16 }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = toNumber(context.parsed || 0);
                                    const percentage = calcPercent(value, participationBase);
                                    return `${label}: ${fmt(value)} (${pct(percentage)})`;
                                }
                            }
                        }
                    }
                }
            };
            
            if (qrPieChart) {
                qrPieChart.data = cfg.data;
                qrPieChart.options = cfg.options;
                qrPieChart.update();
            } else {
                qrPieChart = new Chart(ctx, cfg);
            }
        }
        
        // Renderizar tabla de medios de check-in con porcentaje real de participación.
        function renderQrTable(qrStats){
            if (!qrStats || !qrStats.types || Object.keys(qrStats.types).length === 0) {
                qrTableBody.innerHTML = '<tr><td colspan="3" class="evapp-muted">Sin datos de medios de check-in.</td></tr>';
                return;
            }
            
            const types = qrStats.types || {};
            const mediaTotal = toNumber(qrStats.distribution_total || qrStats.total || 0);
            const participationBase = toNumber(qrStats.participation_base || qrStats.checked_total || qrStats.total || 0);
            const checkedTotal = toNumber(qrStats.checked_total || mediaTotal);
            const withoutMediaTotal = toNumber(qrStats.without_media_total || 0);
            const notCheckedTotal = toNumber(qrStats.not_checked_total || Math.max(participationBase - checkedTotal, 0));
            
            // Crear array de tipos ordenado por cantidad (descendente).
            // El porcentaje ya no se calcula contra mediaTotal, sino contra participationBase.
            const typeArray = Object.keys(types).map(type => ({
                type: type,
                count: toNumber(types[type]),
                percentage: calcPercent(types[type], participationBase)
            })).sort((a, b) => b.count - a.count);
            
            let bodyHTML = typeArray.map(item => {
                return (
                    '<tr>'
                    + '<td>' + escapeHTML(item.type) + '</td>'
                    + '<td>' + fmt(item.count) + '</td>'
                    + '<td>' + item.percentage.toFixed(2) + '%</td>'
                    + '</tr>'
                );
            }).join('');

            if (withoutMediaTotal > 0) {
                bodyHTML +=
                    '<tr>'
                    + '<td>Check-ins sin medio</td>'
                    + '<td>' + fmt(withoutMediaTotal) + '</td>'
                    + '<td>' + calcPercent(withoutMediaTotal, participationBase).toFixed(2) + '%</td>'
                    + '</tr>';
            }

            if (notCheckedTotal > 0) {
                bodyHTML +=
                    '<tr>'
                    + '<td>Sin check-in</td>'
                    + '<td>' + fmt(notCheckedTotal) + '</td>'
                    + '<td>' + calcPercent(notCheckedTotal, participationBase).toFixed(2) + '%</td>'
                    + '</tr>';
            }
            
            // Total real de participación: el 100% ahora corresponde al total de tickets del evento.
            bodyHTML += 
                '<tr class="evapp-total">'
                + '<td>Total tickets</td>'
                + '<td>' + fmt(participationBase) + '</td>'
                + '<td>100.00%</td>'
                + '</tr>';
            
            qrTableBody.innerHTML = bodyHTML;
        }

        function destroyCustomCharts(){
            Object.keys(customCharts).forEach(function(id){
                try { customCharts[id].destroy(); } catch(e) {}
            });
            customCharts = {};
        }

        function safeCssColor(color, fallback){
            color = String(color || '').trim();
            return /^#[0-9a-f]{6}$/i.test(color) ? color : (fallback || '#3279bd');
        }

        function renderCustomTable(metric){
            metric = metric || {};
            const columns = Array.isArray(metric.columns) ? metric.columns : [];
            const rows = Array.isArray(metric.rows) ? metric.rows : [];
            if (!columns.length || !rows.length) {
                return '<div class="evapp-custom-empty">Sin datos para mostrar.</div>';
            }

            const rowTitle = metric.row_title || columns[0] || 'Indicador';
            const columnTitle = metric.column_title || 'Columnas';
            const totalTitle = metric.total_title || 'Total';
            const hasTotalColumn = (typeof metric.has_total_column === 'boolean')
                ? metric.has_total_column
                : (columns.length > 1 && String(columns[columns.length - 1]).toLowerCase() === String(totalTitle).toLowerCase());
            const dynamicColumnEnd = hasTotalColumn ? Math.max(columns.length - 1, 1) : columns.length;
            const dynamicColumns = Array.isArray(metric.dynamic_columns) && metric.dynamic_columns.length
                ? metric.dynamic_columns
                : columns.slice(1, dynamicColumnEnd);

            let html = '<div class="evapp-custom-table-wrap"><table class="evapp-custom-table"><thead>';
            html += '<tr class="evapp-table-superhead">';
            html += '<th class="evapp-table-row-heading" rowspan="2">' + escapeHTML(rowTitle) + '</th>';
            if (dynamicColumns.length) {
                html += '<th class="evapp-table-group-heading" colspan="' + dynamicColumns.length + '">' + escapeHTML(columnTitle) + '</th>';
            }
            if (hasTotalColumn) {
                html += '<th class="evapp-table-total-heading" rowspan="2">' + escapeHTML(totalTitle) + '</th>';
            }
            html += '</tr><tr class="evapp-table-subhead">';
            dynamicColumns.forEach(function(col){ html += '<th>' + escapeHTML(col) + '</th>'; });
            html += '</tr></thead><tbody>';
            rows.forEach(function(row){
                const isTotalRow = hasTotalColumn && row && String(row[0] || '').toLowerCase() === String(totalTitle).toLowerCase();
                html += '<tr' + (isTotalRow ? ' class="evapp-custom-total-row"' : '') + '>';
                columns.forEach(function(_, index){
                    html += '<td>' + escapeHTML((row && row[index] != null) ? row[index] : '') + '</td>';
                });
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            return html;
        }

        function renderCustomValues(metric){
            if (!metric || !metric.show_data_labels) return '';
            const labels = Array.isArray(metric.labels) ? metric.labels : [];
            const values = Array.isArray(metric.formatted_values) ? metric.formatted_values : [];
            if (!labels.length || !values.length) return '';

            let html = '<div class="evapp-custom-chart-values">';
            labels.forEach(function(label, i){
                html += '<div><span>' + escapeHTML(label) + '</span><strong>' + escapeHTML(values[i] != null ? values[i] : '') + '</strong></div>';
            });
            html += '</div>';
            return html;
        }

        function drawCustomCharts(jobs){
            if (!Array.isArray(jobs)) return;
            jobs.forEach(function(metric){
                const canvas = root.querySelector('[data-evapp-custom-chart="' + String(metric.canvas_key || '') + '"]');
                if (!canvas || typeof Chart === 'undefined') return;

                const chartType = metric.chart_type === 'pie' ? 'doughnut' : 'bar';
                const theme = analyticsTheme();
                const labels = Array.isArray(metric.labels) ? metric.labels : [];
                let datasets = [];

                if (Array.isArray(metric.datasets) && metric.datasets.length) {
                    datasets = metric.datasets.map(function(ds){
                        return {
                            label: ds.label || 'Serie',
                            data: Array.isArray(ds.data) ? ds.data : [],
                            backgroundColor: colorFor(ds.label || 'Serie'),
                            borderWidth: 0
                        };
                    });
                } else {
                    const values = Array.isArray(metric.values) ? metric.values : [];
                    datasets = [{
                        label: metric.value_title || 'Valor',
                        data: values,
                        backgroundColor: labels.map(function(label){ return colorFor(label); }),
                        borderWidth: 0
                    }];
                }

                const cfg = {
                    type: chartType,
                    data: { labels: labels, datasets: datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: chartType === 'doughnut',
                        plugins: {
                            legend: {
                                display: !!metric.show_legend,
                                position: chartType === 'doughnut' ? 'bottom' : 'top',
                                labels: { color:theme.text, boxWidth:12, boxHeight:12, padding:16 }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context){
                                        const label = context.dataset && context.dataset.label ? context.dataset.label + ': ' : '';
                                        const raw = chartType === 'doughnut' ? context.parsed : context.parsed.y;
                                        return label + fmt(raw || 0);
                                    }
                                }
                            }
                        },
                        scales: chartType === 'doughnut' ? {} : {
                            x: { ticks:{ color:theme.muted }, grid:{ color:theme.grid} },
                            y: { beginAtZero:true, ticks:{ color:theme.muted }, grid:{ color:theme.grid} }
                        }
                    }
                };

                customCharts[metric.canvas_key] = new Chart(canvas.getContext('2d'), cfg);
            });
        }

        function renderCustomMetrics(payload){
            if (!customMetricsWrap) return;
            destroyCustomCharts();

            if (!payload || !payload.has_metrics || !Array.isArray(payload.rows) || !payload.rows.length) {
                customMetricsWrap.classList.remove('is-visible');
                customMetricsWrap.innerHTML = '';
                return;
            }

            const settings = payload.settings || {};
            const showHeader = settings.show_header !== false;
            const headerText = settings.header_text || 'Métricas personalizadas';
            const headerColor = safeCssColor(settings.header_color, '#3279bd');
            let html = '';

            if (showHeader) {
                html += '<h3 class="evapp-custom-title" style="--evapp-custom-accent:' + headerColor + '">' + escapeHTML(headerText) + '</h3>';
            }

            const chartJobs = [];
            payload.rows.forEach(function(row){
                const slots = row && Array.isArray(row.slots) ? row.slots : [];
                if (!slots.length) return;
                html += '<div class="evapp-custom-row">';

                slots.forEach(function(metric){
                    if (!metric) return;
                    const span = parseInt(metric.span, 10) === 2 ? 2 : 1;
                    html += '<div class="evapp-metric-card evapp-custom-slot span-' + span + '">';
                    html += '<h3>' + escapeHTML(metric.title || 'Métrica personalizada') + '</h3>';

                    if (metric.empty) {
                        html += '<div class="evapp-custom-empty">' + escapeHTML(metric.message || 'Sin datos para mostrar.') + '</div>';
                    } else if (metric.chart_type === 'table') {
                        html += renderCustomTable(metric);
                    } else if (metric.chart_type === 'number_card') {
                        html += '<div class="evapp-custom-card-value">' + escapeHTML(metric.metric_value_display != null ? metric.metric_value_display : fmt(metric.metric_value || 0)) + '</div>';
                        html += '<div class="evapp-custom-card-label">' + escapeHTML(metric.metric_label || 'Valor') + '</div>';
                    } else {
                        const canvasKey = 'custom-' + chartJobs.length;
                        html += '<div class="evapp-custom-chart-frame"><canvas data-evapp-custom-chart="' + canvasKey + '"></canvas></div>';
                        html += renderCustomValues(metric);
                        chartJobs.push(Object.assign({}, metric, {canvas_key: canvasKey}));
                    }

                    html += '</div>';
                });

                html += '</div>';
            });

            customMetricsWrap.innerHTML = html;
            customMetricsWrap.classList.add('is-visible');
            drawCustomCharts(chartJobs);
        }

        function setKpis(total, checked, label){
            total = toNumber(total);
            checked = toNumber(checked);
            const pending = Math.max(total - checked, 0);
            const rate = calcPercent(checked, total);
            if (kpiTotal) kpiTotal.textContent = fmt(total);
            if (kpiChecked) kpiChecked.textContent = fmt(checked);
            if (kpiPending) kpiPending.textContent = fmt(pending);
            if (kpiRate) kpiRate.textContent = pct(rate);
            if (kpiScope) kpiScope.textContent = label || 'Todos';
        }

        function afterDefaultMetricsLoaded(data){
            customMetricsAvailable = !!(data && data.custom_metrics_available);

            if (!customMetricsAvailable) {
                customReloadQueued = false;
                customHasLoaded = false;
                destroyCustomCharts();
                if (customMetricsWrap) {
                    customMetricsWrap.classList.remove('is-visible');
                    customMetricsWrap.innerHTML = '';
                }
                setCustomPanelVisible(false);
                setCustomButton(true, 'Recargar personalizadas');
                return;
            }

            setCustomPanelVisible(true);

            if (!customHasLoaded && !customFetchInProgress) {
                customReloadQueued = true;
                setCustomStatus('Las métricas por defecto ya cargaron. Las personalizadas se cargarán enseguida.', 'loading');
                setCustomButton(true, 'Cargando…');
            } else if (!customFetchInProgress) {
                setCustomStatus('La carga base terminó. Las personalizadas no se recargan automáticamente; usa el botón para refrescarlas bajo demanda.', '');
                setCustomButton(false, 'Recargar personalizadas');
            }
        }

        function requestDefaultMetricsLoad(options){
            options = options || {};
            if (customFetchInProgress) {
                if (options.force) {
                    pendingDefaultFetch = true;
                    setCustomStatus('Recarga de métricas por defecto pendiente. Las personalizadas esperarán para no cruzar cargas.', 'loading');
                }
                return defaultFetchPromise || Promise.resolve(null);
            }
            return fetchData(options);
        }

        async function fetchData(options){
            options = options || {};

            if (defaultFetchInProgress) {
                if (options.force) {
                    pendingDefaultFetch = true;
                    if (customMetricsAvailable) {
                        setCustomPanelVisible(true);
                        setCustomStatus('Hay una recarga de métricas por defecto en curso. Las personalizadas esperarán.', 'loading');
                        setCustomButton(true, 'Esperando…');
                    }
                }
                return defaultFetchPromise;
            }

            defaultFetchInProgress = true;
            setDefaultLoading(true);

            defaultFetchPromise = (async function(){
                try {
                    const fd = new FormData();
                    fd.append('action',   'eventosapp_metrics_data');
                    fd.append('security', ajaxNonce);

                    const filters = getCurrentFilters();
                    fd.append('mode', filters.mode || 'sum');
                    fd.append('checkin_type', filters.checkin_type || 'all');
                    if ((filters.mode || 'sum') === 'sum'){
                        if (filters.from) fd.append('from', filters.from);
                        if (filters.to)   fd.append('to',   filters.to);
                    } else {
                        if (filters.day)  fd.append('day',  filters.day);
                    }

                    const resp = await fetch(ajaxURL, { method:'POST', body:fd, credentials:'same-origin' });
                    const j = await readAjaxJSON(resp);
                    if (!j || !j.success) {
                        const serverMsg = j && j.data && j.data.error ? j.data.error : 'Error al cargar métricas.';
                        throw new Error(serverMsg);
                    }

                    const d = j.data;

                    if (!inDay.value && d.bar && d.bar.day)   inDay.value  = d.bar.day;
                    if (!inFrom.value && d.bar && d.bar.from) inFrom.value = d.bar.from;
                    if (!inTo.value && d.bar && d.bar.to)     inTo.value   = d.bar.to;

                    if (checkinTypeSel && d.checkin_type && checkinTypeSel.value !== d.checkin_type) {
                        checkinTypeSel.value = d.checkin_type;
                    }

                    setKpis(d.total_tickets, d.checked_in_total, d.checkin_filter_label || '');
                    renderPie(d);
                    renderBars(d);
                    renderTable((d.table && d.table.rows) ? d.table.rows : [], d);
                    renderQrPie(d.qr_stats);
                    renderQrTable(d.qr_stats);
                    afterDefaultMetricsLoaded(d);

                    return d;
                } catch(e){
                    console.error(e);
                    const message = e && e.message ? e.message : 'No se pudieron cargar las métricas.';
                    tableBody.innerHTML = '<tr><td colspan="6" class="evapp-bad">' + escapeHTML(message) + '</td></tr>';
                    qrTableBody.innerHTML = '<tr><td colspan="3" class="evapp-bad">Error al cargar datos de medios de check-in.</td></tr>';
                    if (customMetricsAvailable) {
                        setCustomPanelVisible(true);
                        setCustomStatus('No se recargaron las personalizadas porque falló la carga de métricas por defecto.', 'error');
                        setCustomButton(false, 'Recargar personalizadas');
                    }
                    return null;
                } finally {
                    defaultFetchInProgress = false;
                    defaultFetchPromise = null;
                    setDefaultLoading(false);

                    if (pendingDefaultFetch) {
                        pendingDefaultFetch = false;
                        fetchData({force:true, reason:'queued'});
                        return;
                    }

                    if (customReloadQueued) {
                        customReloadQueued = false;
                        fetchCustomMetrics();
                    }
                }
            })();

            return defaultFetchPromise;
        }

        function requestCustomMetricsLoad(){
            if (!customMetricsAvailable) return;

            setCustomPanelVisible(true);

            if (defaultFetchInProgress || pendingDefaultFetch) {
                customReloadQueued = true;
                setCustomStatus('Esperando a que terminen las métricas por defecto para cargar las personalizadas.', 'loading');
                setCustomButton(true, 'Esperando…');
                return;
            }

            if (customFetchInProgress) {
                customReloadQueued = true;
                setCustomStatus('Ya hay una carga personalizada en curso. Se hará una recarga adicional al terminar.', 'loading');
                setCustomButton(true, 'Cargando…');
                return;
            }

            fetchCustomMetrics();
        }

        async function fetchCustomMetrics(){
            if (!customMetricsAvailable || customFetchInProgress || defaultFetchInProgress || pendingDefaultFetch) {
                if (customMetricsAvailable) customReloadQueued = true;
                return;
            }

            customFetchInProgress = true;
            setCustomPanelVisible(true);
            setCustomStatus('Cargando métricas personalizadas después de la carga base…', 'loading');
            setCustomButton(true, 'Cargando…');

            try {
                const fd = new FormData();
                const filters = getCurrentFilters();
                fd.append('action', 'eventosapp_custom_metrics_data');
                fd.append('security', ajaxNonce);
                fd.append('mode', filters.mode || 'sum');
                fd.append('checkin_type', filters.checkin_type || 'all');
                if ((filters.mode || 'sum') === 'sum') {
                    if (filters.from) fd.append('from', filters.from);
                    if (filters.to)   fd.append('to', filters.to);
                } else if (filters.day) {
                    fd.append('day', filters.day);
                }

                const resp = await fetch(ajaxURL, { method:'POST', body:fd, credentials:'same-origin' });
                const j = await readAjaxJSON(resp);
                if (!j || !j.success) {
                    const serverMsg = j && j.data && j.data.error ? j.data.error : 'Error al cargar métricas personalizadas.';
                    throw new Error(serverMsg);
                }

                renderCustomMetrics(j.data ? j.data.custom_metrics : null);
                customHasLoaded = true;
                setCustomStatus('Métricas personalizadas cargadas. No se actualizarán solas; usa el botón cuando necesites refrescarlas.', '');
                setCustomButton(false, 'Recargar personalizadas');
            } catch(e) {
                console.error(e);
                setCustomStatus('No se pudieron cargar las métricas personalizadas.', 'error');
                setCustomButton(false, 'Recargar personalizadas');
            } finally {
                customFetchInProgress = false;

                if (pendingDefaultFetch) {
                    pendingDefaultFetch = false;
                    fetchData({force:true, reason:'queued-after-custom'});
                    return;
                }

                if (customReloadQueued) {
                    customReloadQueued = false;
                    fetchCustomMetrics();
                }
            }
        }

        if (btnApply) {
            btnApply.addEventListener('click', function(e){
                e.preventDefault();
                customHasLoaded = false;
                customReloadQueued = false;
                requestDefaultMetricsLoad({force:true, reason:'apply'});
            });
        }

        if (customReloadBtn) {
            customReloadBtn.addEventListener('click', function(e){
                e.preventDefault();
                requestCustomMetricsLoad();
            });
        }

        // Espera al DOM
        if (document.readyState !== 'loading') init();
        else document.addEventListener('DOMContentLoaded', init);

        function init(){
            requestDefaultMetricsLoad({force:true, reason:'initial'});
            if (refreshInterval < 5000) return;
            const timer = window.setInterval(function(){
                if (!document.body.contains(root)) {
                    window.clearInterval(timer);
                    return;
                }
                if (!document.hidden && !defaultFetchInProgress && !customFetchInProgress) {
                    requestDefaultMetricsLoad({force:false, reason:'auto'});
                }
            }, refreshInterval);
        }
    })();
JS;
        $js = str_replace('__EVAPP_METRICS_ROOT__', wp_json_encode($instance_id), $js);
        wp_add_inline_script('eventosapp-front-metrics', $js, 'after');
        wp_enqueue_script('eventosapp-front-metrics');

        return ob_get_clean();
    }
}

if ( ! function_exists('eventosapp_front_metrics_shortcode') ) {
    function eventosapp_front_metrics_shortcode($atts = []) {
        $atts = shortcode_atts([
            'show_header'        => 'yes',
            'show_event_context' => 'yes',
            'show_back_button'   => 'yes',
            'show_export_button' => 'yes',
            'show_filters'       => 'yes',
        ], $atts, 'eventosapp_front_metrics');

        return eventosapp_render_metrics($atts);
    }
}
add_shortcode('eventosapp_front_metrics', 'eventosapp_front_metrics_shortcode');


//
// === AJAX: datos de métricas en tiempo real ===
//

add_action('wp_ajax_eventosapp_metrics_data', function(){
    try {
        // CSRF. Se valida sin wp_die para que admin-ajax responda siempre JSON.
        if ( ! check_ajax_referer('eventosapp_metrics_data', 'security', false) ) {
            wp_send_json_error(['error'=>'Sesión expirada o token inválido. Recarga la página e intenta nuevamente.'], 403);
        }

        if ( ! is_user_logged_in() ) wp_send_json_error(['error'=>'No autorizado']);
        if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('metrics') ) {
            wp_send_json_error(['error'=>'Permisos insuficientes']);
        }

        $event_id = function_exists('eventosapp_get_active_event') ? (int) eventosapp_get_active_event() : 0;
        if ( ! $event_id ) wp_send_json_error(['error'=>'No hay evento activo.']);

        $cache_key = eventosapp_metrics_default_payload_cache_key($event_id, $_POST);
        $cached = get_transient($cache_key);
        if ( is_array($cached) ) {
            if ( isset($cached['performance']) && is_array($cached['performance']) ) {
                $cached['performance']['cached'] = true;
            }
            wp_send_json_success($cached);
        }

        $out = eventosapp_metrics_build_default_payload($event_id, $_POST);

        // Caché corta para evitar que el refresco automático o doble clic lance varias lecturas pesadas simultáneas.
        // No sacrifica la información: se recalcula con frecuencia y conserva las mismas gráficas/datos.
        $ttl = (int) apply_filters('eventosapp_metrics_default_payload_cache_ttl', 12, $event_id);
        set_transient($cache_key, $out, max(5, min(60, $ttl)));

        wp_send_json_success($out);
    } catch (\Throwable $e) {
        eventosapp_metrics_log_debug('metrics_data_ajax_error', [
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ]);
        eventosapp_metrics_send_json_exception($e);
    }
});

add_action('wp_ajax_eventosapp_custom_metrics_data', function(){
    try {
        if ( ! check_ajax_referer('eventosapp_metrics_data', 'security', false) ) {
            wp_send_json_error(['error'=>'Sesión expirada o token inválido. Recarga la página e intenta nuevamente.'], 403);
        }

    if ( ! is_user_logged_in() ) wp_send_json_error(['error'=>'No autorizado']);
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('metrics') ) {
        wp_send_json_error(['error'=>'Permisos insuficientes']);
    }

    $event_id = function_exists('eventosapp_get_active_event') ? (int) eventosapp_get_active_event() : 0;
    if ( ! $event_id ) wp_send_json_error(['error'=>'No hay evento activo.']);

    $payload = eventosapp_metrics_safe_custom_metrics_payload($event_id);

    wp_send_json_success([
        'custom_metrics' => $payload,
    ]);
    } catch (\Throwable $e) {
        eventosapp_metrics_log_debug('custom_metrics_ajax_error', [
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ]);
        eventosapp_metrics_send_json_exception($e, 'No se pudieron cargar las métricas personalizadas. Revisa el log de EventosApp para ver el detalle técnico.');
    }
});


//
// === Helper: generar y transmitir XLSX en vivo (sin librerías externas) ===
//
if ( ! function_exists('eventosapp_stream_xlsx') ) {
    function eventosapp_stream_xlsx($filename, $sheetName, $headers, $rows){
        if ( ! class_exists('ZipArchive') ) {
            wp_die('El servidor no tiene ZipArchive habilitado (requerido para .xlsx).', '', 500);
        }

        // util: col 0 -> A, 1 -> B, ...
        $colLetter = function($i){
            $s = '';
            while ($i >= 0) {
                $s = chr($i % 26 + 65) . $s;
                $i = intdiv($i, 26) - 1;
            }
            return $s;
        };
        $xmlEsc = function($s){
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $cols = count($headers);
        $rowsCount = count($rows) + 1; // + encabezado
        $lastCol = $colLetter($cols - 1);
        $dimension = "A1:{$lastCol}{$rowsCount}";

        // ===== xl/worksheets/sheet1.xml (usamos inlineStr para conservar formato texto) =====
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
               . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
               . '<dimension ref="'.$dimension.'"/>'
               . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
               . '<sheetFormatPr defaultRowHeight="15"/>'
               . '<sheetData>';

        // Fila encabezados
        $sheet .= '<row r="1">';
        foreach ($headers as $ci => $h) {
            $ref = $colLetter($ci) . '1';
            $sheet .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$xmlEsc($h).'</t></is></c>';
        }
        $sheet .= '</row>';

        // Filas de datos
        for ($ri = 0; $ri < count($rows); $ri++) {
            $sheet .= '<row r="'.($ri+2).'">';
            $row = $rows[$ri];
            for ($ci = 0; $ci < $cols; $ci++) {
                $val = isset($row[$ci]) ? $row[$ci] : '';
                $ref = $colLetter($ci) . ($ri+2);
                // Todo como texto para no dañar CC / teléfonos / IDs / horas
                $sheet .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$xmlEsc($val).'</t></is></c>';
            }
            $sheet .= '</row>';
        }

        $sheet .= '</sheetData></worksheet>';

        // ===== Resto de partes mínimas del paquete XLSX =====
        $contentTypes =
'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>';

        $rels =
'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>';

        $workbook =
'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
 xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="'.htmlspecialchars($sheetName, ENT_QUOTES, 'UTF-8').'" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';

        $workbookRels =
'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

        // Estilos mínimos
        $styles =
'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="1"><font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font></fonts>
  <fills count="1"><fill><patternFill patternType="none"/></fill></fills>
  <borders count="1"><border/></borders>
  <cellStyleXfs count="1"><xf/></cellStyleXfs>
  <cellXfs count="1"><xf xfId="0"/></cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>';

        $nowIso = gmdate('Y-m-d\TH:i:s\Z');
        $core =
'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"
 xmlns:dc="http://purl.org/dc/elements/1.1/"
 xmlns:dcterms="http://purl.org/dc/terms/"
 xmlns:dcmitype="http://purl.org/dc/dcmitype/"
 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:creator>EventosApp</dc:creator>
  <cp:lastModifiedBy>EventosApp</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">'.$nowIso.'</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">'.$nowIso.'</dcterms:modified>
</cp:coreProperties>';

        $app =
'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"
 xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>EventosApp</Application>
  <DocSecurity>0</DocSecurity>
  <ScaleCrop>false</ScaleCrop>
  <Company>EventosApp</Company>
  <LinksUpToDate>false</LinksUpToDate>
  <SharedDoc>false</SharedDoc>
  <HyperlinksChanged>false</HyperlinksChanged>
  <AppVersion>16.0000</AppVersion>
</Properties>';

        // Crear zip en archivo temporal
        if ( function_exists('wp_tempnam') ) {
            $tmp = wp_tempnam('eventosapp_xlsx');
        } else {
            $tmp = tempnam(sys_get_temp_dir(), 'evxlsx_');
        }
        if ( ! $tmp ) wp_die('No se pudo crear archivo temporal para .xlsx', '', 500);

        $zip = new ZipArchive();
        if (true !== $zip->open($tmp, ZipArchive::OVERWRITE)) {
            @unlink($tmp);
            wp_die('No se pudo inicializar el contenedor .xlsx', '', 500);
        }

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('docProps/core.xml', $core);
        $zip->addFromString('docProps/app.xml', $app);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/styles.xml', $styles);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        // Enviar al navegador
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}

//
// === AJAX: Exportar base (XLSX para Excel) ===
add_action('wp_ajax_eventosapp_export_tickets', function(){
    // CSRF (sí, también en GET)
    check_ajax_referer('eventosapp_export_tickets', 'security');

    if ( ! is_user_logged_in() ) wp_die('No autorizado', '', 403);
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('metrics') ) {
        wp_die('Permisos insuficientes', '', 403);
    }

    $event_id = function_exists('eventosapp_get_active_event') ? (int) eventosapp_get_active_event() : 0;
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
        wp_die('No hay evento activo válido (eventosapp_event).', '', 400);
    }

    // --- Utilidades ---
    $is_date = function($s){
        return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
    };

    // === 1) DÍAS DEL EVENTO ===
    $event_days = [];
    $tipo_fecha   = get_post_meta($event_id, '_eventosapp_tipo_fecha', true) ?: 'unica';
    $fecha_unica  = get_post_meta($event_id, '_eventosapp_fecha_unica', true) ?: '';
    $fecha_inicio = get_post_meta($event_id, '_eventosapp_fecha_inicio', true) ?: '';
    $fecha_fin    = get_post_meta($event_id, '_eventosapp_fecha_fin', true) ?: '';
    $fechas_noco  = get_post_meta($event_id, '_eventosapp_fechas_noco', true);

    if (!is_array($fechas_noco)) {
        $fechas_noco = is_string($fechas_noco) && $fechas_noco ? array_map('trim', explode(',', $fechas_noco)) : [];
    }

    try {
        if ($tipo_fecha === 'unica' && $is_date($fecha_unica)) {
            $event_days = [$fecha_unica];
        } elseif ($tipo_fecha === 'consecutiva' && $is_date($fecha_inicio) && $is_date($fecha_fin) && $fecha_fin >= $fecha_inicio) {
            $d1 = new DateTime($fecha_inicio);
            $d2 = new DateTime($fecha_fin);
            while ($d1 <= $d2) {
                $event_days[] = $d1->format('Y-m-d');
                $d1->modify('+1 day');
            }
        } elseif ($tipo_fecha === 'noconsecutiva' && !empty($fechas_noco)) {
            foreach ($fechas_noco as $f) if ($is_date($f)) $event_days[] = $f;
            sort($event_days);
        }
    } catch (\Throwable $e) {
        $event_days = [];
    }

    if (!$event_days) {
        $q_tmp = new WP_Query([
            'post_type'      => 'eventosapp_ticket',
            'post_status'    => 'any',
            'posts_per_page' => 250,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key'=>'_eventosapp_ticket_evento_id', 'value'=>$event_id, 'compare'=>'='],
            ],
        ]);
        $days_set = [];
        foreach ($q_tmp->posts as $tid) {
            $status_arr = get_post_meta($tid, '_eventosapp_checkin_status', true);
            if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
            if (is_array($status_arr)) foreach ($status_arr as $d => $st) if ($is_date($d)) $days_set[$d] = true;

            $virtual_status_arr = get_post_meta($tid, '_eventosapp_virtual_checkin_status', true);
            if (is_string($virtual_status_arr)) $virtual_status_arr = @unserialize($virtual_status_arr);
            if (is_array($virtual_status_arr)) foreach ($virtual_status_arr as $d => $st) if ($is_date($d)) $days_set[$d] = true;

            $log = get_post_meta($tid, '_eventosapp_checkin_log', true);
            if (is_string($log)) $log = @unserialize($log);
            if (is_array($log)) foreach ($log as $row) {
                $f = isset($row['fecha']) ? $row['fecha'] : '';
                if ($is_date($f)) $days_set[$f] = true;
            }
        }
        $event_days = array_keys($days_set);
        sort($event_days);
    }

    $event_days_lookup = array_fill_keys($event_days, true);
    $ticket_has_valid_main_checkin = function($status_arr) use ($event_days_lookup) {
        if (!is_array($status_arr)) return false;
        if (empty($event_days_lookup)) {
            return in_array('checked_in', $status_arr, true) || in_array('checked-in', $status_arr, true);
        }
        foreach ($status_arr as $status_day => $status_value) {
            if (($status_value === 'checked_in' || $status_value === 'checked-in') && isset($event_days_lookup[$status_day])) {
                return true;
            }
        }
        return false;
    };

    // === 2) SESIONES (detección robusta) ===
    $extract_names = function($raw){
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (is_string($v) && $v !== '') { $out[$v] = true; continue; }
                if (is_array($v)) {
                    if (!empty($v['nombre']) && is_string($v['nombre'])) $out[$v['nombre']] = true;
                    elseif (!empty($v['name']) && is_string($v['name'])) $out[$v['name']] = true;
                    elseif (!empty($v['title']) && is_string($v['title'])) $out[$v['title']] = true;
                    elseif (!empty($v['slug']) && is_string($v['slug'])) $out[$v['slug']] = true;
                }
            }
        }
        return array_keys($out);
    };

    $all_sessions = [];
    foreach (['_eventosapp_sesiones','_eventosapp_sesiones_def','_eventosapp_lista_sesiones','_eventosapp_sessions','_eventosapp_sesiones_internas'] as $meta_key) {
        $raw = get_post_meta($event_id, $meta_key, true);
        if (!empty($raw)) { $names = $extract_names($raw); if (!empty($names)) { $all_sessions = $names; break; } }
    }
    if (empty($all_sessions)) {
        $q_tmp2 = new WP_Query([
            'post_type'      => 'eventosapp_ticket',
            'post_status'    => 'any',
            'posts_per_page' => 250,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key'=>'_eventosapp_ticket_evento_id', 'value'=>$event_id, 'compare'=>'='],
            ],
        ]);
        $set = [];
        foreach ($q_tmp2->posts as $tid) {
            $acc = get_post_meta($tid, '_eventosapp_ticket_sesiones_acceso', true);
            if (!is_array($acc)) $acc = [];
            foreach ($acc as $sname) if (is_string($sname) && $sname!=='') $set[$sname] = true;

            $ses = get_post_meta($tid, '_eventosapp_ticket_checkin_sesiones', true);
            if (is_string($ses)) $ses = @unserialize($ses);
            if (!is_array($ses)) $ses = [];
            foreach ($ses as $sname => $st) if (is_string($sname) && $sname!=='') $set[$sname] = true;
        }
        $all_sessions = array_keys($set);
    }
    sort($all_sessions, SORT_NATURAL | SORT_FLAG_CASE);

    // === 3) Tickets ===
    // Usa el resolvedor optimizado de IDs del módulo de métricas para evitar
    // construir objetos WP_Post o una consulta WP_Query sin límite.
    if ( function_exists('eventosapp_metrics_get_ticket_ids_for_event') ) {
        $ids = eventosapp_metrics_get_ticket_ids_for_event($event_id);
    } else {
        $q = new WP_Query([
            'post_type'      => 'eventosapp_ticket',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                ['key'=>'_eventosapp_ticket_evento_id', 'value'=>$event_id, 'compare'=>'='],
            ],
        ]);
        $ids = array_map('absint', (array)$q->posts);
    }

    $extra_prefix = defined('EVAPP_PUBLIC_EXTRA_PREFIX') ? EVAPP_PUBLIC_EXTRA_PREFIX : 'eventosapp_extra';
    $extra_fields = function_exists('eventosapp_get_event_extra_fields')
        ? eventosapp_get_event_extra_fields($event_id)
        : [];

    // === 4) Encabezados ===
    $headers = [
        'Ticket Public ID','Ticket Post ID','Evento ID','Evento',
        'Secuencia Interna',
        'Nombre','Apellido','CC','Email','Teléfono','Empresa','NIT','Cargo','Localidad','Modalidad del Ticket',
        'Estado de confirmación de asistencia','Medio enviado para confirmación','Medio en que respondió',
        'Fecha del primer envío de confirmación','Fecha del último envío de confirmación',
        'Último medio de respuesta','Fecha de última respuesta','Conflicto de respuestas',
        'Checked-In (algún día)', 'Checked-In presencial (algún día)', 'Checked-In virtual (algún día)'
    ];
    if (!empty($extra_fields)) {
        foreach ($extra_fields as $f) { $headers[] = 'Extra: ' . ($f['label'] ?? ''); }
    }

    // Check-in por día (SI/NO) + Hora por día
    // Se conserva la columna legacy "Check-in" como check-in presencial para no romper descargas existentes.
    foreach ($event_days as $d) {
        $headers[] = 'Check-in — '.$d;
        $headers[] = 'Hora check-in — '.$d;
        $headers[] = 'Check-in virtual — '.$d;
        $headers[] = 'Hora check-in virtual — '.$d;
    }

    // Sesiones (acceso y check-in por sesión)
    foreach ($all_sessions as $sname) { $headers[] = 'Sesión: '.$sname.' (Acceso)'; }
    foreach ($all_sessions as $sname) { $headers[] = 'Sesión: '.$sname.' (Check-in)'; }

    // Hora por sesión y día
    foreach ($all_sessions as $sname) {
        foreach ($event_days as $d) {
            $headers[] = 'Hora sesión: '.$sname.' — '.$d;
        }
    }

$headers[] = 'Fecha creación';
    
    // Encabezados de estado de correo
    $headers[] = 'Estado Correo Ticket';
    $headers[] = 'Fecha del Primer Envío';
    $headers[] = 'Fecha del Último Envío';
    $headers[] = 'Canal de Envío';

    // Encabezados de estado de WhatsApp
    $headers[] = 'Estado WhatsApp Ticket';
    $headers[] = 'Fecha del Primer Envío WhatsApp';
    $headers[] = 'Fecha del Último Envío WhatsApp';
    $headers[] = 'Canal de Envío WhatsApp';
    $headers[] = 'Estado Entrega WhatsApp';
    
// Encabezado para medio de check-in
    $headers[] = 'Medio de Check-in';

    // NUEVO: Acompañantes sin QR
    $headers[] = 'Acompañantes sin QR';

    // === 5) Filas
    $dataRows = [];

    // util para comparar horas HH:MM o HH:MM:SS
    $min_time = function($a, $b){
        if ($a === '') return $b;
        if ($b === '') return $a;
        // normalizar a HH:MM:SS
        $norm = function($t){
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) return $t;
            if (preg_match('/^\d{2}:\d{2}$/', $t)) return $t.':00';
            return $t;
        };
        $na = $norm($a); $nb = $norm($b);
        return ($na <= $nb) ? $a : $b;
    };

    // Normaliza canales de confirmación incluso si el motor especializado no
    // terminó de cargar o el ticket conserva un formato histórico.
    $format_attendance_channels = function($value){
        if (is_string($value)) {
            $value = maybe_unserialize($value);
        }
        if (is_string($value)) {
            $value = preg_split('/[\s,|]+/', $value);
        }
        if (!is_array($value)) {
            $value = [];
        }

        $labels = [
            'email'    => 'Correo electrónico',
            'whatsapp' => 'WhatsApp',
        ];
        $out = [];
        foreach ($value as $channel) {
            if (is_array($channel) || is_object($channel)) continue;
            $channel = sanitize_key((string)$channel);
            if ($channel === '') continue;
            $label = $labels[$channel] ?? ucwords(str_replace('_', ' ', $channel));
            if (!in_array($label, $out, true)) {
                $out[] = $label;
            }
        }
        return implode(', ', $out);
    };

    // Mantiene las fechas MySQL guardadas por WordPress sin desplazarlas de
    // zona horaria. Otros formatos válidos se convierten a la zona del sitio.
    $format_attendance_datetime = function($value){
        if (is_array($value) || is_object($value)) return '';
        $value = trim(sanitize_text_field((string)$value));
        if ($value === '') return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }
        $timestamp = strtotime($value);
        return $timestamp ? wp_date('Y-m-d H:i:s', $timestamp, wp_timezone()) : $value;
    };

    // Resuelve primer y último envío desde las metas canónicas. Para tickets
    // antiguos, recupera fechas desde el historial y las metas por canal.
    $resolve_attendance_sent_dates = function($ticket_id) use ($format_attendance_datetime){
        $first = get_post_meta($ticket_id, '_eventosapp_attendance_confirmation_first_sent_at', true);
        $last  = get_post_meta($ticket_id, '_eventosapp_attendance_confirmation_last_sent_at', true);

        $candidates = [];
        foreach ([
            $first,
            $last,
            get_post_meta($ticket_id, '_eventosapp_attendance_confirmation_email_sent_at', true),
            get_post_meta($ticket_id, '_eventosapp_attendance_confirmation_whatsapp_sent_at', true),
        ] as $candidate) {
            if (is_scalar($candidate) && trim((string)$candidate) !== '') {
                $candidate = trim((string)$candidate);
                $timestamp = strtotime($candidate);
                if ($timestamp) $candidates[$timestamp . '|' . $candidate] = $candidate;
            }
        }

        $history = get_post_meta($ticket_id, '_eventosapp_attendance_confirmation_history', true);
        if (is_string($history)) $history = maybe_unserialize($history);
        if (is_array($history)) {
            foreach ($history as $entry) {
                if (!is_array($entry) || sanitize_key((string)($entry['type'] ?? '')) !== 'send') continue;
                $candidate = trim((string)($entry['at'] ?? ''));
                $timestamp = $candidate !== '' ? strtotime($candidate) : false;
                if ($timestamp) $candidates[$timestamp . '|' . $candidate] = $candidate;
            }
        }

        if ((!is_scalar($first) || trim((string)$first) === '') && !empty($candidates)) {
            $ordered = [];
            foreach ($candidates as $candidate) {
                $timestamp = strtotime($candidate);
                if ($timestamp) $ordered[$timestamp] = $candidate;
            }
            if (!empty($ordered)) {
                ksort($ordered, SORT_NUMERIC);
                $first = reset($ordered);
            }
        }

        if ((!is_scalar($last) || trim((string)$last) === '') && !empty($candidates)) {
            $ordered = [];
            foreach ($candidates as $candidate) {
                $timestamp = strtotime($candidate);
                if ($timestamp) $ordered[$timestamp] = $candidate;
            }
            if (!empty($ordered)) {
                ksort($ordered, SORT_NUMERIC);
                $last = end($ordered);
            }
        }

        return [
            'first' => $format_attendance_datetime($first),
            'last'  => $format_attendance_datetime($last),
        ];
    };

    foreach ($ids as $tid) {
        $row = [];
        $pub_id   = get_post_meta($tid, 'eventosapp_ticketID', true);
        $seq      = get_post_meta($tid, '_eventosapp_ticket_seq', true);
        $first    = get_post_meta($tid, '_eventosapp_asistente_nombre', true);
        $last     = get_post_meta($tid, '_eventosapp_asistente_apellido', true);
        $cc       = get_post_meta($tid, '_eventosapp_asistente_cc', true);
        $email    = get_post_meta($tid, '_eventosapp_asistente_email', true);
        $tel      = get_post_meta($tid, '_eventosapp_asistente_tel', true);
        $comp     = get_post_meta($tid, '_eventosapp_asistente_empresa', true);
        $nit      = get_post_meta($tid, '_eventosapp_asistente_nit', true);
        $role     = get_post_meta($tid, '_eventosapp_asistente_cargo', true);
        $loc      = get_post_meta($tid, '_eventosapp_asistente_localidad', true);
        $ev_title = get_the_title($event_id);

        $ticket_modalidad = function_exists('eventosapp_get_ticket_modalidad')
            ? eventosapp_get_ticket_modalidad($tid)
            : (get_post_meta($tid, '_eventosapp_ticket_modalidad', true) ?: 'presencial');
        if (function_exists('eventosapp_normalize_ticket_modalidad')) {
            $ticket_modalidad = eventosapp_normalize_ticket_modalidad($ticket_modalidad);
        } else {
            $ticket_modalidad = in_array($ticket_modalidad, ['presencial','virtual'], true) ? $ticket_modalidad : 'presencial';
        }
        $ticket_modalidad_label = function_exists('eventosapp_ticket_modalidad_options')
            ? ((eventosapp_ticket_modalidad_options())[$ticket_modalidad] ?? ucfirst($ticket_modalidad))
            : ($ticket_modalidad === 'virtual' ? 'Virtual' : 'Presencial');

        $attendance_status_key = function_exists('eventosapp_attendance_confirmation_get_status')
            ? eventosapp_attendance_confirmation_get_status($tid)
            : sanitize_key((string)get_post_meta($tid, '_eventosapp_attendance_confirmation_status', true));
        if ($attendance_status_key === '') $attendance_status_key = 'sin_consulta';
        $attendance_status_label = function_exists('eventosapp_attendance_confirmation_status_label')
            ? eventosapp_attendance_confirmation_status_label($attendance_status_key)
            : (['si'=>'Sí','no'=>'No','no_responde'=>'No responde','sin_consulta'=>'Sin consulta'][$attendance_status_key] ?? 'Sin consulta');
        $attendance_sent_channels = function_exists('eventosapp_attendance_confirmation_get_ticket_field_value')
            ? eventosapp_attendance_confirmation_get_ticket_field_value($tid, 'attendance_confirmation_sent_channels', true)
            : '';
        if (!is_scalar($attendance_sent_channels) || trim((string)$attendance_sent_channels) === '') {
            $attendance_sent_channels = $format_attendance_channels(
                get_post_meta($tid, '_eventosapp_attendance_confirmation_sent_channels', true)
            );
        }

        $attendance_response_channels = function_exists('eventosapp_attendance_confirmation_get_ticket_field_value')
            ? eventosapp_attendance_confirmation_get_ticket_field_value($tid, 'attendance_confirmation_response_channels', true)
            : '';
        if (!is_scalar($attendance_response_channels) || trim((string)$attendance_response_channels) === '') {
            $attendance_response_channels = $format_attendance_channels(
                get_post_meta($tid, '_eventosapp_attendance_confirmation_response_channels', true)
            );
        }

        $attendance_sent_dates = $resolve_attendance_sent_dates($tid);
        $attendance_first_sent_at = $attendance_sent_dates['first'];
        $attendance_last_sent_at  = $attendance_sent_dates['last'];

        $attendance_last_response_channel = function_exists('eventosapp_attendance_confirmation_get_ticket_field_value')
            ? eventosapp_attendance_confirmation_get_ticket_field_value($tid, 'attendance_confirmation_last_response_channel', true)
            : '';
        if (!is_scalar($attendance_last_response_channel) || trim((string)$attendance_last_response_channel) === '') {
            $attendance_last_response_channel = $format_attendance_channels([
                get_post_meta($tid, '_eventosapp_attendance_confirmation_last_response_channel', true),
            ]);
        }
        $attendance_last_response_at = $format_attendance_datetime(
            get_post_meta($tid, '_eventosapp_attendance_confirmation_last_response_at', true)
        );
        $attendance_conflict = get_post_meta($tid, '_eventosapp_attendance_confirmation_conflict', true) === '1' ? 'SI' : 'NO';

        $status_arr = get_post_meta($tid, '_eventosapp_checkin_status', true);
        if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
        if (!is_array($status_arr)) $status_arr = [];

        $virtual_status_arr = get_post_meta($tid, '_eventosapp_virtual_checkin_status', true);
        if (is_string($virtual_status_arr)) $virtual_status_arr = @unserialize($virtual_status_arr);
        if (!is_array($virtual_status_arr)) $virtual_status_arr = [];

        $any_presencial_checked = $ticket_has_valid_main_checkin($status_arr);
        $any_virtual_checked    = $ticket_has_valid_main_checkin($virtual_status_arr);
        $any_checked            = ($any_presencial_checked || $any_virtual_checked) ? 'SI' : 'NO';
        $any_presencial_checked_label = $any_presencial_checked ? 'SI' : 'NO';
        $any_virtual_checked_label    = $any_virtual_checked ? 'SI' : 'NO';

        $acc = get_post_meta($tid, '_eventosapp_ticket_sesiones_acceso', true);
        if (!is_array($acc)) $acc = [];
        $acc_set = [];
        foreach ($acc as $sname) { if (is_string($sname) && $sname!=='') $acc_set[$sname] = true; }

        $ses = get_post_meta($tid, '_eventosapp_ticket_checkin_sesiones', true);
        if (is_string($ses)) $ses = @unserialize($ses);
        if (!is_array($ses)) $ses = [];

        $created = get_post_time('Y-m-d H:i:s', true, $tid);

        // Mapear horas por día (principal presencial, virtual) y por sesión x día
        $main_time_by_day = [];
        $virtual_time_by_day = [];
        foreach ($event_days as $d) {
            $main_time_by_day[$d] = '';
            $virtual_time_by_day[$d] = '';
        }

        $session_time_by_day = []; // [sname][day] => 'HH:MM[:SS]'
        foreach ($all_sessions as $sname) {
            $session_time_by_day[$sname] = [];
            foreach ($event_days as $d) $session_time_by_day[$sname][$d] = '';
        }
        
        // Medios de check-in detectados para este ticket.
        $checkin_media_labels = [];
        $qr_type_checkin = '';

        $log = get_post_meta($tid, '_eventosapp_checkin_log', true);
        if (is_string($log)) $log = @unserialize($log);
        if (!is_array($log)) $log = [];

        foreach ($log as $entry) {
            $f = isset($entry['fecha']) ? $entry['fecha'] : '';
            $h = isset($entry['hora'])  ? $entry['hora']  : '';
            $st= isset($entry['status'])? $entry['status']: '';
            $sn= isset($entry['sesion'])? $entry['sesion']: '';

            if (!$f || !$h) continue;

            $checkin_log_type = isset($entry['checkin_type']) ? (string)$entry['checkin_type'] : '';
            $entry_day = isset($entry['dia']) && $entry['dia'] ? (string)$entry['dia'] : $f;

            // principal presencial por día: tomar la PRIMERA hora del día
            if (in_array($entry_day, $event_days, true) && ($st === 'checked_in' || $st === 'checked-in') && $checkin_log_type !== 'virtual') {
                $main_time_by_day[$entry_day] = $min_time($main_time_by_day[$entry_day], $h);

                $label = eventosapp_metrics_qr_label_from_log_entry($entry, 'Sin clasificar');
                $checkin_media_labels[$label] = true;
                if ($qr_type_checkin === '') {
                    $qr_type_checkin = $label;
                }
            }

            // virtual por día: tomar la PRIMERA hora del día
            if (in_array($entry_day, $event_days, true) && ($st === 'virtual_checked_in' || $checkin_log_type === 'virtual')) {
                $virtual_time_by_day[$entry_day] = $min_time($virtual_time_by_day[$entry_day], $h);
                $label = eventosapp_metrics_qr_label_from_log_entry($entry, 'Acceso virtual');
                $checkin_media_labels[$label] = true;
            }

            // sesión por día
            if ($sn && in_array($entry_day, $event_days, true) && ($st === 'session_checked_in')) {
                if (isset($session_time_by_day[$sn])) {
                    $session_time_by_day[$sn][$entry_day] = $min_time($session_time_by_day[$sn][$entry_day], $h);
                }
            }
        }

        // base
        $row[] = (string)$pub_id;
        $row[] = (string)$tid;
        $row[] = (string)$event_id;
        $row[] = (string)$ev_title;
        $row[] = (string)$seq;
        $row[] = (string)$first;
        $row[] = (string)$last;
        $row[] = (string)$cc;
        $row[] = (string)$email;
        $row[] = (string)$tel;
        $row[] = (string)$comp;
        $row[] = (string)$nit;
        $row[] = (string)$role;
        $row[] = (string)$loc;
        $row[] = (string)$ticket_modalidad_label;
        $row[] = (string)$attendance_status_label;
        $row[] = (string)$attendance_sent_channels;
        $row[] = (string)$attendance_response_channels;
        $row[] = (string)$attendance_first_sent_at;
        $row[] = (string)$attendance_last_sent_at;
        $row[] = (string)$attendance_last_response_channel;
        $row[] = (string)$attendance_last_response_at;
        $row[] = (string)$attendance_conflict;
        $row[] = (string)$any_checked;
        $row[] = (string)$any_presencial_checked_label;
        $row[] = (string)$any_virtual_checked_label;

        // extras
        if (!empty($extra_fields)) {
            $extras_map = get_post_meta($tid, '_'.$extra_prefix, true);
            if (!is_array($extras_map) || empty($extras_map)) {
                $extras_map = get_post_meta($tid, $extra_prefix, true);
                if (!is_array($extras_map)) $extras_map = [];
            }
            foreach ($extra_fields as $f) {
                $key = $f['key'] ?? '';
                $val = '';
                if ($key !== '') {
                    if (isset($extras_map[$key]) && $extras_map[$key] !== '') {
                        $val = $extras_map[$key];
                    } else {
                        foreach ([$extra_prefix . '_' . $key, '_' . $extra_prefix . '_' . $key] as $mkey) {
                            $tmp = get_post_meta($tid, $mkey, true);
                            if ($tmp !== '' && $tmp !== null) { $val = $tmp; break; }
                        }
                    }
                    if (function_exists('eventosapp_normalize_extra_value')) {
                        $val = eventosapp_normalize_extra_value($f, $val);
                    } else {
                        $val = is_scalar($val) ? (string)$val : '';
                    }
                }
                $row[] = (string)$val;
            }
        }

        // por día: SI/NO + HORA. La columna legacy "Check-in" se mantiene como presencial.
        foreach ($event_days as $d) {
            $st  = isset($status_arr[$d]) ? $status_arr[$d] : '';
            $vst = isset($virtual_status_arr[$d]) ? $virtual_status_arr[$d] : '';
            $row[] = ($st === 'checked_in' || $st === 'checked-in') ? 'SI' : 'NO';
            $row[] = (string)$main_time_by_day[$d];
            $row[] = ($vst === 'checked_in' || $vst === 'checked-in') ? 'SI' : 'NO';
            $row[] = (string)$virtual_time_by_day[$d];
        }

        // sesiones acceso
        foreach ($all_sessions as $sname) { $row[] = isset($acc_set[$sname]) ? 'SI' : 'NO'; }
        // sesiones check-in (SI/NO)
        foreach ($all_sessions as $sname) {
            $st  = isset($ses[$sname]) ? $ses[$sname] : '';
            $row[] = ($st === 'checked_in' || $st === 'checked-in') ? 'SI' : 'NO';
        }
        // sesiones x día — HORA
        foreach ($all_sessions as $sname) {
            foreach ($event_days as $d) {
                $row[] = (string)$session_time_by_day[$sname][$d];
            }
        }

        $row[] = (string)$created;
        
// Datos de estado de correo
        $email_status = get_post_meta($tid, '_eventosapp_ticket_email_sent_status', true);
        $row[] = ($email_status === 'enviado') ? 'Enviado' : 'No Enviado';
        
        $first_sent = get_post_meta($tid, '_eventosapp_ticket_email_first_sent', true);
        $row[] = $first_sent ? date_i18n('Y-m-d H:i:s', strtotime($first_sent)) : '';
        
        $last_sent = get_post_meta($tid, '_eventosapp_ticket_last_email_at', true);
        $row[] = $last_sent ? date_i18n('Y-m-d H:i:s', strtotime($last_sent)) : '';

        // Canal de envío: fuente del envío más reciente registrado en el historial
        $email_history = get_post_meta($tid, '_eventosapp_ticket_email_history', true);
        if (!is_array($email_history)) $email_history = [];
        $email_source_raw = isset($email_history[0]['source']) ? (string) $email_history[0]['source'] : '';
        $email_source_labels = [
            'admin'          => 'Envío manual (admin)',
            'manual'         => 'Envío manual (admin)',
            'auto'           => 'Envío automático',
            'webhook'        => 'Webhook',
            'reminder'       => 'Recordatorio',
            'frontend_edit'  => 'Módulo edición frontend',
        ];
        $row[] = isset($email_source_labels[$email_source_raw])
            ? $email_source_labels[$email_source_raw]
            : ($email_source_raw ?: '');

        // Datos de estado de WhatsApp
        if (function_exists('eventosapp_whatsapp_get_send_tracking')) {
            $whatsapp_tracking = eventosapp_whatsapp_get_send_tracking($tid, true);
        } else {
            $whatsapp_tracking = [
                'sent_status'   => get_post_meta($tid, '_eventosapp_whatsapp_sent_status', true),
                'first_sent_at' => get_post_meta($tid, '_eventosapp_whatsapp_first_sent_at', true),
                'last_sent_at'  => get_post_meta($tid, '_eventosapp_whatsapp_last_sent_at', true),
                'last_source'   => get_post_meta($tid, '_eventosapp_whatsapp_last_source', true),
            ];
        }

        $whatsapp_sent_status = isset($whatsapp_tracking['sent_status']) ? sanitize_key((string)$whatsapp_tracking['sent_status']) : '';
        $row[] = ($whatsapp_sent_status === 'enviado') ? 'Enviado' : 'No Enviado';

        $whatsapp_first_sent = isset($whatsapp_tracking['first_sent_at']) ? (string)$whatsapp_tracking['first_sent_at'] : '';
        if ($whatsapp_first_sent && function_exists('eventosapp_whatsapp_format_datetime')) {
            $row[] = eventosapp_whatsapp_format_datetime($whatsapp_first_sent, 'Y-m-d H:i:s');
        } else {
            $whatsapp_first_ts = $whatsapp_first_sent ? strtotime($whatsapp_first_sent) : false;
            $row[] = $whatsapp_first_ts ? date_i18n('Y-m-d H:i:s', $whatsapp_first_ts) : '';
        }

        $whatsapp_last_sent = isset($whatsapp_tracking['last_sent_at']) ? (string)$whatsapp_tracking['last_sent_at'] : '';
        if ($whatsapp_last_sent && function_exists('eventosapp_whatsapp_format_datetime')) {
            $row[] = eventosapp_whatsapp_format_datetime($whatsapp_last_sent, 'Y-m-d H:i:s');
        } else {
            $whatsapp_last_ts = $whatsapp_last_sent ? strtotime($whatsapp_last_sent) : false;
            $row[] = $whatsapp_last_ts ? date_i18n('Y-m-d H:i:s', $whatsapp_last_ts) : '';
        }

        $whatsapp_source_raw = isset($whatsapp_tracking['last_source']) ? (string)$whatsapp_tracking['last_source'] : '';
        $row[] = function_exists('eventosapp_whatsapp_source_label')
            ? eventosapp_whatsapp_source_label($whatsapp_source_raw)
            : ($whatsapp_source_raw ?: '');

        $whatsapp_delivery_status = get_post_meta($tid, '_eventosapp_whatsapp_delivery_status', true);
        if (!$whatsapp_delivery_status) {
            $whatsapp_delivery_status = get_post_meta($tid, '_eventosapp_whatsapp_last_status', true);
        }
        $row[] = function_exists('eventosapp_whatsapp_status_label')
            ? eventosapp_whatsapp_status_label($whatsapp_delivery_status)
            : (string)$whatsapp_delivery_status;
        
// Medio de check-in
        if (empty($checkin_media_labels) && $any_presencial_checked && $qr_type_checkin !== '') {
            $checkin_media_labels[$qr_type_checkin] = true;
        }
        if (empty($checkin_media_labels) && $any_virtual_checked) {
            $checkin_media_labels['Acceso virtual'] = true;
        }
        $row[] = implode(', ', array_keys($checkin_media_labels));

        // NUEVO: Acompañantes sin QR (total acumulado del ticket)
        $acompanantes_total = (int) get_post_meta($tid, '_eventosapp_ticket_acompanantes_sin_qr', true);
        $row[] = $acompanantes_total > 0 ? (string)$acompanantes_total : '0';

        $dataRows[] = $row;
    }

    // === 6) Enviar XLSX ===
    $filename = 'tickets_evento_'.$event_id.'_'.date('Ymd_His').'.xlsx';
    eventosapp_stream_xlsx($filename, 'Tickets', $headers, $dataRows);
});
