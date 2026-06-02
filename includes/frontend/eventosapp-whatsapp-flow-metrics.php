<?php
/**
 * EventosApp - Métricas frontend de Encuestas
 *
 * Shortcode: [eventosapp_whatsapp_flow_metrics]
 * Página configurable desde EventosApp > Configuración.
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_log_debug') ) {
    function eventosapp_whatsapp_flow_metrics_log_debug($message, $context = []) {
        if ( ! defined('WP_DEBUG') || ! WP_DEBUG ) {
            return;
        }

        $line = 'EVENTOSAPP FLOW METRICS | ' . (is_scalar($message) ? (string) $message : 'debug');
        if ( ! empty($context) ) {
            $encoded = function_exists('wp_json_encode')
                ? wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : json_encode($context);
            if ( $encoded ) {
                $line .= ' | ' . $encoded;
            }
        }
        error_log($line);
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_can_view') ) {
    function eventosapp_whatsapp_flow_metrics_can_view() {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        if ( function_exists('eventosapp_role_can') ) {
            return eventosapp_role_can('flow_metrics');
        }

        $user = wp_get_current_user();
        $roles = (array) ($user->roles ?? []);
        return current_user_can('manage_options') || in_array('organizador', $roles, true);
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_require_dependencies') ) {
    function eventosapp_whatsapp_flow_metrics_require_dependencies() {
        $required = [
            'eventosapp_whatsapp_flows_sends_table_name',
            'eventosapp_whatsapp_flows_responses_table_name',
            'eventosapp_whatsapp_flows_get_flow_config',
        ];

        foreach ( $required as $fn ) {
            if ( ! function_exists($fn) ) {
                return false;
            }
        }

        if ( function_exists('eventosapp_whatsapp_flows_maybe_install_tables') ) {
            eventosapp_whatsapp_flows_maybe_install_tables();
        }

        return true;
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_get_active_event_id') ) {
    function eventosapp_whatsapp_flow_metrics_get_active_event_id() {
        if ( function_exists('eventosapp_get_active_event') ) {
            return absint(eventosapp_get_active_event());
        }
        return 0;
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_get_configured_flow_id') ) {
    function eventosapp_whatsapp_flow_metrics_get_configured_flow_id($event_id) {
        $event_id = absint($event_id);
        if ( ! $event_id ) {
            return 0;
        }

        $flow_id = 0;

        // Fuente principal: configuración efectiva del metabox
        // "Diseño WhatsApp y Landing" > "Encuesta de satisfacción por WhatsApp Flow".
        // Esta función respeta la sincronización entre la plantilla aprobada por Meta y el Flow local asociado.
        if ( function_exists('eventosapp_whatsapp_get_event_satisfaction_flow_config') ) {
            $config = eventosapp_whatsapp_get_event_satisfaction_flow_config($event_id);
            if ( is_array($config) ) {
                $flow_id = absint($config['flow_post_id'] ?? 0);
            }
        }

        // Compatibilidad hacia atrás si el archivo de WhatsApp Ticket no está cargado todavía.
        if ( ! $flow_id && function_exists('eventosapp_whatsapp_get_event_selected_satisfaction_flow_post_id') ) {
            $flow_id = absint(eventosapp_whatsapp_get_event_selected_satisfaction_flow_post_id($event_id));
        }

        if ( ! $flow_id ) {
            $flow_id = absint(get_post_meta($event_id, '_eventosapp_whatsapp_satisfaction_flow_post_id', true));
        }

        if ( $flow_id && function_exists('eventosapp_whatsapp_is_valid_flow_post') && ! eventosapp_whatsapp_is_valid_flow_post($flow_id) ) {
            return 0;
        }

        return $flow_id;
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_get_flow_title') ) {
    function eventosapp_whatsapp_flow_metrics_get_flow_title($flow_post_id) {
        $flow_post_id = absint($flow_post_id);
        if ( ! $flow_post_id ) {
            return 'Encuesta sin identificar';
        }

        $title = get_the_title($flow_post_id);
        if ( ! is_string($title) || trim($title) === '' ) {
            $title = 'Encuesta #' . $flow_post_id;
        }
        return $title;
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_get_event_flows') ) {
    function eventosapp_whatsapp_flow_metrics_get_event_flows($event_id) {
        global $wpdb;

        $event_id = absint($event_id);
        if ( ! $event_id || ! eventosapp_whatsapp_flow_metrics_require_dependencies() ) {
            return [];
        }

        $configured_flow_id = eventosapp_whatsapp_flow_metrics_get_configured_flow_id($event_id);
        if ( ! $configured_flow_id ) {
            return [];
        }

        $sends_table = eventosapp_whatsapp_flows_sends_table_name();
        $responses_table = eventosapp_whatsapp_flows_responses_table_name();

        $send_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT MAX(updated_at) AS last_activity, COUNT(*) AS total_sends
                 FROM {$sends_table}
                 WHERE event_id = %d AND flow_post_id = %d",
                $event_id,
                $configured_flow_id
            ),
            ARRAY_A
        );

        $response_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT MAX(r.created_at) AS last_activity, COUNT(*) AS total_answers
                 FROM {$responses_table} r
                 LEFT JOIN {$sends_table} s ON s.id = r.send_id
                 WHERE r.flow_post_id = %d AND (r.event_id = %d OR s.event_id = %d)",
                $configured_flow_id,
                $event_id,
                $event_id
            ),
            ARRAY_A
        );

        $send_last = (string)($send_row['last_activity'] ?? '');
        $response_last = (string)($response_row['last_activity'] ?? '');
        $last_activity = $send_last;
        if ( $response_last !== '' && ($last_activity === '' || strcmp($response_last, $last_activity) > 0) ) {
            $last_activity = $response_last;
        }

        return [[
            'id'            => $configured_flow_id,
            'title'         => eventosapp_whatsapp_flow_metrics_get_flow_title($configured_flow_id),
            'last_activity' => $last_activity,
            'total_sends'   => absint($send_row['total_sends'] ?? 0),
            'total_answers' => absint($response_row['total_answers'] ?? 0),
            'configured'    => true,
            'source'        => 'event_satisfaction_flow_config',
        ]];
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_normalize_question_type') ) {
    function eventosapp_whatsapp_flow_metrics_normalize_question_type($type) {
        $type = sanitize_key((string) $type);
        $aliases = [
            'radiobuttonsgroup' => 'radio',
            'radio_buttons_group' => 'radio',
            'checkboxgroup' => 'checkbox',
            'checkbox_group' => 'checkbox',
            'dropdown' => 'dropdown',
            'textinput' => 'text',
            'text_input' => 'text',
            'textarea' => 'textarea',
            'text_area' => 'textarea',
            'datepicker' => 'date',
            'date_picker' => 'date',
            'optin' => 'optin',
            'opt_in' => 'optin',
        ];
        return $aliases[$type] ?? $type;
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_sanitize_slug') ) {
    function eventosapp_whatsapp_flow_metrics_sanitize_slug($slug, $fallback = 'pregunta') {
        if ( function_exists('eventosapp_whatsapp_flows_sanitize_slug') ) {
            return eventosapp_whatsapp_flows_sanitize_slug($slug, $fallback);
        }

        $slug = sanitize_key((string)$slug);
        return $slug !== '' ? $slug : sanitize_key((string)$fallback);
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_normalize_option_key') ) {
    function eventosapp_whatsapp_flow_metrics_normalize_option_key($value) {
        if ( is_bool($value) ) {
            return $value ? 'true' : 'false';
        }
        if ( is_array($value) || is_object($value) ) {
            $value = function_exists('wp_json_encode') ? wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : json_encode($value);
        }
        $value = trim((string)$value);
        return $value === '' ? '__empty__' : (function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value));
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_normalize_options') ) {
    function eventosapp_whatsapp_flow_metrics_normalize_options($options) {
        $out = [];
        if ( ! is_array($options) ) {
            return $out;
        }

        foreach ( $options as $option ) {
            if ( is_array($option) ) {
                $id = isset($option['id']) ? trim((string)$option['id']) : '';
                $title = isset($option['title']) ? trim((string)$option['title']) : '';
            } else {
                $id = trim((string)$option);
                $title = trim((string)$option);
            }

            if ( $id === '' && $title === '' ) {
                continue;
            }
            if ( $id === '' ) {
                $id = sanitize_key($title);
            }
            if ( $title === '' ) {
                $title = $id;
            }

            $out[] = [
                'id'    => sanitize_text_field($id),
                'label' => sanitize_text_field($title),
            ];
        }
        return $out;
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_get_question_definitions') ) {
    function eventosapp_whatsapp_flow_metrics_get_question_definitions($flow_post_id) {
        $flow_post_id = absint($flow_post_id);
        if ( ! $flow_post_id || ! function_exists('eventosapp_whatsapp_flows_get_flow_config') ) {
            return [];
        }

        $config = eventosapp_whatsapp_flows_get_flow_config($flow_post_id);
        $questions = isset($config['questions']) && is_array($config['questions']) ? $config['questions'] : [];
        $input_types = function_exists('eventosapp_whatsapp_flows_input_question_types') ? eventosapp_whatsapp_flows_input_question_types() : ['radio','checkbox','dropdown','text','textarea','date','optin'];
        $definitions = [];

        foreach ( $questions as $index => $question ) {
            if ( ! is_array($question) ) {
                continue;
            }

            $type = eventosapp_whatsapp_flow_metrics_normalize_question_type($question['type'] ?? 'text');
            if ( ! in_array($type, $input_types, true) ) {
                continue;
            }

            $slug = eventosapp_whatsapp_flow_metrics_sanitize_slug($question['slug'] ?? '', 'pregunta_' . ($index + 1));
            $label = sanitize_text_field((string)($question['label'] ?? 'Pregunta ' . ($index + 1)));
            if ( $label === '' ) {
                $label = 'Pregunta ' . ($index + 1);
            }

            $choices = eventosapp_whatsapp_flow_metrics_normalize_options($question['options'] ?? []);
            if ( $type === 'optin' ) {
                $choices = [
                    ['id' => 'true',  'label' => 'Sí'],
                    ['id' => 'false', 'label' => 'No'],
                ];
            }

            $choice_map = [];
            foreach ( $choices as $choice ) {
                $choice_map[eventosapp_whatsapp_flow_metrics_normalize_option_key($choice['id'])] = $choice['label'];
                $choice_map[eventosapp_whatsapp_flow_metrics_normalize_option_key($choice['label'])] = $choice['label'];
            }

            $definitions[$slug] = [
                'slug'        => $slug,
                'label'       => $label,
                'type'        => $type,
                'choices'     => $choices,
                'choice_map'  => $choice_map,
                'order'       => absint($index),
                'chartable'   => in_array($type, ['radio','checkbox','dropdown','optin'], true),
            ];
        }

        return $definitions;
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_decode_response_json') ) {
    function eventosapp_whatsapp_flow_metrics_decode_response_json($raw) {
        if ( function_exists('eventosapp_whatsapp_flows_decode_nfm_response_json') ) {
            $decoded = eventosapp_whatsapp_flows_decode_nfm_response_json($raw);
            return is_array($decoded) ? $decoded : [];
        }

        if ( is_array($raw) ) {
            return $raw;
        }
        if ( is_object($raw) ) {
            $raw = function_exists('wp_json_encode') ? wp_json_encode($raw) : json_encode($raw);
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_value_to_labels') ) {
    function eventosapp_whatsapp_flow_metrics_value_to_labels($value, $question) {
        $type = sanitize_key((string)($question['type'] ?? ''));
        $choice_map = isset($question['choice_map']) && is_array($question['choice_map']) ? $question['choice_map'] : [];

        if ( $type === 'checkbox' ) {
            $values = is_array($value) ? $value : [$value];
        } else {
            $values = [$value];
        }

        $labels = [];
        foreach ( $values as $item ) {
            if ( is_bool($item) ) {
                $key = $item ? 'true' : 'false';
                $raw_label = $item ? 'Sí' : 'No';
            } elseif ( is_array($item) || is_object($item) ) {
                $raw_label = function_exists('wp_json_encode') ? wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : json_encode($item);
                $key = eventosapp_whatsapp_flow_metrics_normalize_option_key($raw_label);
            } else {
                $raw_label = trim((string)$item);
                $key = eventosapp_whatsapp_flow_metrics_normalize_option_key($raw_label);
            }

            if ( $raw_label === '' ) {
                continue;
            }

            $label = isset($choice_map[$key]) ? $choice_map[$key] : sanitize_text_field($raw_label);
            if ( $label !== '' ) {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_get_counts') ) {
    function eventosapp_whatsapp_flow_metrics_get_counts($event_id, $flow_post_id) {
        global $wpdb;

        $event_id = absint($event_id);
        $flow_post_id = absint($flow_post_id);
        if ( ! $event_id || ! $flow_post_id || ! eventosapp_whatsapp_flow_metrics_require_dependencies() ) {
            return [
                'sent'      => 0,
                'read'      => 0,
                'answered'  => 0,
                'read_rate' => 0,
                'answer_rate' => 0,
            ];
        }

        $sends_table = eventosapp_whatsapp_flows_sends_table_name();
        $responses_table = eventosapp_whatsapp_flows_responses_table_name();

        $sent_where = "event_id = %d AND flow_post_id = %d AND status NOT LIKE 'failed%%' AND (wa_message_id <> '' OR status IN ('sent_request','webhook_sent','webhook_delivered','webhook_read','delivered','read'))";
        $sent = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$sends_table} WHERE {$sent_where}", $event_id, $flow_post_id));

        $read = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$sends_table}
             WHERE event_id = %d AND flow_post_id = %d
             AND (delivery_status = 'read' OR status = 'webhook_read')",
            $event_id,
            $flow_post_id
        ));

        $answered = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$responses_table} r
             LEFT JOIN {$sends_table} s ON s.id = r.send_id
             WHERE r.flow_post_id = %d AND (r.event_id = %d OR s.event_id = %d)",
            $flow_post_id,
            $event_id,
            $event_id
        ));

        return [
            'sent'        => $sent,
            'read'        => $read,
            'answered'    => $answered,
            'read_rate'   => $sent > 0 ? round(($read / $sent) * 100, 2) : 0,
            'answer_rate' => $sent > 0 ? round(($answered / $sent) * 100, 2) : 0,
        ];
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_get_cache_version') ) {
    function eventosapp_whatsapp_flow_metrics_get_cache_version($event_id, $flow_post_id) {
        global $wpdb;

        $event_id = absint($event_id);
        $flow_post_id = absint($flow_post_id);
        if ( ! $event_id || ! $flow_post_id || ! eventosapp_whatsapp_flow_metrics_require_dependencies() ) {
            return '0';
        }

        $sends_table = eventosapp_whatsapp_flows_sends_table_name();
        $responses_table = eventosapp_whatsapp_flows_responses_table_name();

        $send_version = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS c, COALESCE(MAX(id),0) AS max_id, COALESCE(MAX(updated_at),'') AS max_updated
             FROM {$sends_table}
             WHERE event_id = %d AND flow_post_id = %d",
            $event_id,
            $flow_post_id
        ), ARRAY_A);

        $response_version = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS c, COALESCE(MAX(r.id),0) AS max_id, COALESCE(MAX(r.created_at),'') AS max_created
             FROM {$responses_table} r
             LEFT JOIN {$sends_table} s ON s.id = r.send_id
             WHERE r.flow_post_id = %d AND (r.event_id = %d OR s.event_id = %d)",
            $flow_post_id,
            $event_id,
            $event_id
        ), ARRAY_A);

        return md5(wp_json_encode([
            's' => $send_version ?: [],
            'r' => $response_version ?: [],
        ]));
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_build_payload') ) {
    function eventosapp_whatsapp_flow_metrics_build_payload($event_id, $flow_post_id) {
        global $wpdb;

        $event_id = absint($event_id);
        $flow_post_id = absint($flow_post_id);
        $flows = eventosapp_whatsapp_flow_metrics_get_event_flows($event_id);
        $valid_flow_ids = array_map('absint', wp_list_pluck($flows, 'id'));

        if ( ! $event_id || ! $flow_post_id || ! in_array($flow_post_id, $valid_flow_ids, true) ) {
            $configured_flow_id = eventosapp_whatsapp_flow_metrics_get_configured_flow_id($event_id);
            $message = $configured_flow_id
                ? 'Esta sección solo permite consultar la encuesta configurada en el metabox Diseño WhatsApp y Landing del evento activo.'
                : 'Este evento no tiene una encuesta de satisfacción por WhatsApp Flow configurada en el metabox Diseño WhatsApp y Landing.';

            return [
                'event_id' => $event_id,
                'flow_id'  => $flow_post_id,
                'flow_title' => $flow_post_id ? eventosapp_whatsapp_flow_metrics_get_flow_title($flow_post_id) : '',
                'flows'    => $flows,
                'counts'   => ['sent'=>0, 'read'=>0, 'answered'=>0, 'read_rate'=>0, 'answer_rate'=>0],
                'questions'=> [],
                'message'  => $message,
                'performance' => ['cached' => false, 'processed_responses' => 0, 'batch_size' => 0],
            ];
        }

        $version = eventosapp_whatsapp_flow_metrics_get_cache_version($event_id, $flow_post_id);
        $cache_key = 'evapp_flow_metrics_' . md5($event_id . '|' . $flow_post_id . '|' . $version);
        $cached = get_transient($cache_key);
        if ( is_array($cached) ) {
            $cached['performance']['cached'] = true;
            return $cached;
        }

        $counts = eventosapp_whatsapp_flow_metrics_get_counts($event_id, $flow_post_id);
        $definitions = eventosapp_whatsapp_flow_metrics_get_question_definitions($flow_post_id);
        $metrics = [];

        foreach ( $definitions as $slug => $question ) {
            if ( empty($question['chartable']) ) {
                continue;
            }

            $counts_by_label = [];
            foreach ( (array)($question['choices'] ?? []) as $choice ) {
                $label = sanitize_text_field((string)($choice['label'] ?? ''));
                if ( $label !== '' ) {
                    $counts_by_label[$label] = 0;
                }
            }

            $metrics[$slug] = [
                'slug'               => $slug,
                'label'              => $question['label'],
                'type'               => $question['type'],
                'answered_responses' => 0,
                'selection_total'     => 0,
                'counts'             => $counts_by_label,
            ];
        }

        $responses_table = eventosapp_whatsapp_flows_responses_table_name();
        $sends_table = eventosapp_whatsapp_flows_sends_table_name();
        $batch_size = (int) apply_filters('eventosapp_whatsapp_flow_metrics_batch_size', 500, $event_id, $flow_post_id);
        $batch_size = max(100, min(1000, $batch_size));
        $last_id = 0;
        $processed = 0;

        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT r.id, r.response_json
                 FROM {$responses_table} r
                 LEFT JOIN {$sends_table} s ON s.id = r.send_id
                 WHERE r.id > %d
                   AND r.flow_post_id = %d
                   AND (r.event_id = %d OR s.event_id = %d)
                 ORDER BY r.id ASC
                 LIMIT %d",
                $last_id,
                $flow_post_id,
                $event_id,
                $event_id,
                $batch_size
            ), ARRAY_A);

            if ( empty($rows) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $last_id = max($last_id, absint($row['id'] ?? 0));
                $decoded = eventosapp_whatsapp_flow_metrics_decode_response_json($row['response_json'] ?? '');
                if ( empty($decoded) ) {
                    continue;
                }

                foreach ( $metrics as $slug => &$metric ) {
                    if ( ! array_key_exists($slug, $decoded) ) {
                        continue;
                    }
                    $question = $definitions[$slug] ?? [];
                    $labels = eventosapp_whatsapp_flow_metrics_value_to_labels($decoded[$slug], $question);
                    if ( empty($labels) ) {
                        continue;
                    }

                    $metric['answered_responses']++;
                    foreach ( $labels as $label ) {
                        if ( ! isset($metric['counts'][$label]) ) {
                            $metric['counts'][$label] = 0;
                        }
                        $metric['counts'][$label]++;
                        $metric['selection_total']++;
                    }
                }
                unset($metric);

                $processed++;
            }
        } while ( count($rows) >= $batch_size );

        $questions_payload = [];
        foreach ( $metrics as $metric ) {
            $options = [];
            $selection_total = max(0, absint($metric['selection_total'] ?? 0));
            foreach ( (array)($metric['counts'] ?? []) as $label => $count ) {
                $count = absint($count);
                if ( $count <= 0 && $selection_total > 0 ) {
                    // Conserva opciones configuradas con cero solo cuando ya hay datos para comparar.
                }
                $options[] = [
                    'label'   => sanitize_text_field((string)$label),
                    'count'   => $count,
                    'percent' => $selection_total > 0 ? round(($count / $selection_total) * 100, 2) : 0,
                ];
            }

            usort($options, function($a, $b) {
                $cmp = absint($b['count'] ?? 0) <=> absint($a['count'] ?? 0);
                if ( $cmp !== 0 ) {
                    return $cmp;
                }
                return strcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
            });

            $questions_payload[] = [
                'slug'               => $metric['slug'],
                'label'              => $metric['label'],
                'type'               => $metric['type'],
                'answered_responses' => absint($metric['answered_responses'] ?? 0),
                'selection_total'     => $selection_total,
                'options'             => $options,
            ];
        }

        usort($questions_payload, function($a, $b) use ($definitions) {
            $ao = absint($definitions[$a['slug']]['order'] ?? 9999);
            $bo = absint($definitions[$b['slug']]['order'] ?? 9999);
            return $ao <=> $bo;
        });

        $payload = [
            'event_id'      => $event_id,
            'event_title'   => get_the_title($event_id),
            'flow_id'       => $flow_post_id,
            'flow_title'    => eventosapp_whatsapp_flow_metrics_get_flow_title($flow_post_id),
            'flows'         => $flows,
            'counts'        => $counts,
            'questions'     => $questions_payload,
            'message'       => '',
            'performance'   => [
                'cached'              => false,
                'processed_responses' => $processed,
                'batch_size'          => $batch_size,
            ],
        ];

        $ttl = (int) apply_filters('eventosapp_whatsapp_flow_metrics_cache_ttl', 20, $event_id, $flow_post_id);
        set_transient($cache_key, $payload, max(5, min(120, $ttl)));

        return $payload;
    }
}


if ( ! function_exists('eventosapp_whatsapp_flow_metrics_get_export_url') ) {
    function eventosapp_whatsapp_flow_metrics_get_export_url($event_id, $flow_post_id) {
        $event_id = absint($event_id);
        $flow_post_id = absint($flow_post_id);

        if ( ! $event_id || ! $flow_post_id ) {
            return '#';
        }

        // Importante: el botón frontend no debe depender de wp-admin/admin-post.php.
        // Usuarios con permiso de dashboard frontend pueden ser redirigidos fuera del admin
        // antes de que WordPress ejecute admin_post. Por eso la URL principal usa una
        // acción frontend propia atendida en init; admin_post se conserva por compatibilidad.
        // Tampoco se usa wp_nonce_url() porque devuelve la URL escapada para HTML y,
        // cuando JavaScript reemplaza el Flow dinámico, puede conservar "&amp;" como texto real.
        return add_query_arg([
            'eventosapp_frontend_action' => 'eventosapp_whatsapp_flow_metrics_export_csv',
            'event_id'                   => $event_id,
            'flow_id'                    => $flow_post_id,
            '_wpnonce'                   => wp_create_nonce('eventosapp_whatsapp_flow_metrics_export_csv'),
        ], home_url('/'));
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_is_frontend_export_request') ) {
    function eventosapp_whatsapp_flow_metrics_is_frontend_export_request() {
        $action = eventosapp_whatsapp_flow_metrics_get_request_value('eventosapp_frontend_action', '');
        $action = is_scalar($action) ? sanitize_key((string) $action) : '';

        return $action === 'eventosapp_whatsapp_flow_metrics_export_csv';
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_get_request_value') ) {
    function eventosapp_whatsapp_flow_metrics_get_request_value($key, $default = '') {
        $key = (string) $key;
        if ( $key === '' ) {
            return $default;
        }

        if ( isset($_REQUEST[$key]) ) {
            return wp_unslash($_REQUEST[$key]);
        }

        // Compatibilidad defensiva para enlaces que llegaron con &amp; dentro de la URL.
        // En ese caso PHP recibe nombres como amp;event_id, amp;flow_id o amp;_wpnonce.
        $amp_key = 'amp;' . $key;
        if ( isset($_REQUEST[$amp_key]) ) {
            return wp_unslash($_REQUEST[$amp_key]);
        }

        return $default;
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_verify_export_nonce') ) {
    function eventosapp_whatsapp_flow_metrics_verify_export_nonce() {
        $nonce = eventosapp_whatsapp_flow_metrics_get_request_value('_wpnonce', '');
        if ( ! is_scalar($nonce) || ! wp_verify_nonce((string) $nonce, 'eventosapp_whatsapp_flow_metrics_export_csv') ) {
            wp_die('El enlace de descarga no es válido o expiró. Regresa a Métricas de Encuestas y presiona nuevamente el botón Descargar resultados CSV.');
        }
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_user_can_download_csv') ) {
    /**
     * Autoriza la descarga CSV contra el evento solicitado, no contra el evento activo
     * implícito. Esto permite que usuarios con permisos personalizados de
     * "Métricas de encuestas" descarguen desde el dashboard frontend aunque no tengan
     * acceso al wp-admin o permisos globales de administrador.
     *
     * @param int $event_id
     * @param int|null $user_id
     * @return bool
     */
    function eventosapp_whatsapp_flow_metrics_user_can_download_csv($event_id, $user_id = null) {
        $event_id = absint($event_id);
        $user_id = $user_id === null ? get_current_user_id() : absint($user_id);

        if ( ! $event_id || ! $user_id || ! is_user_logged_in() ) {
            return false;
        }

        if ( user_can($user_id, 'manage_options') ) {
            return true;
        }

        if ( function_exists('eventosapp_user_can_access_dashboard_feature_in_event')
            && eventosapp_user_can_access_dashboard_feature_in_event($user_id, 'flow_metrics', $event_id) ) {
            return true;
        }

        if ( function_exists('eventosapp_staff_access_user_can_access_feature') ) {
            $custom_permission = eventosapp_staff_access_user_can_access_feature($event_id, $user_id, 'flow_metrics', null);
            if ( $custom_permission !== null ) {
                return (bool) $custom_permission;
            }
        }

        $active_event = function_exists('eventosapp_get_active_event')
            ? absint(eventosapp_get_active_event())
            : 0;

        if ( $active_event && $active_event === $event_id && eventosapp_whatsapp_flow_metrics_can_view() ) {
            return true;
        }

        return false;
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_is_internal_response_key') ) {
    function eventosapp_whatsapp_flow_metrics_is_internal_response_key($key) {
        if ( function_exists('eventosapp_whatsapp_flows_is_internal_response_key') ) {
            return eventosapp_whatsapp_flows_is_internal_response_key($key);
        }

        $key = sanitize_key((string) $key);
        if ( $key === '' ) {
            return true;
        }

        return in_array($key, [
            'flow_token',
            'eventosapp_flow_token',
            'eventosapp_flow_post_id',
            'eventosapp_event_id',
            'eventosapp_ticket_id',
            'eventosapp_ticket_code',
            'flow_post_id',
            'event_id',
            'ticket_id',
            'ticket_code',
            'flow_id_local',
            'token',
        ], true);
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_response_label_from_slug') ) {
    function eventosapp_whatsapp_flow_metrics_response_label_from_slug($slug) {
        if ( function_exists('eventosapp_whatsapp_flows_response_label_from_slug') ) {
            return eventosapp_whatsapp_flows_response_label_from_slug($slug);
        }

        $slug = sanitize_key((string) $slug);
        if ( $slug === '' ) {
            return 'Respuesta';
        }
        return ucwords(str_replace('_', ' ', $slug));
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_get_export_question_map') ) {
    function eventosapp_whatsapp_flow_metrics_get_export_question_map($flow_post_id) {
        $flow_post_id = absint($flow_post_id);
        if ( ! $flow_post_id ) {
            return [];
        }

        if ( function_exists('eventosapp_whatsapp_flows_get_answer_questions_map') ) {
            $map = eventosapp_whatsapp_flows_get_answer_questions_map($flow_post_id);
            return is_array($map) ? $map : [];
        }

        $definitions = eventosapp_whatsapp_flow_metrics_get_question_definitions($flow_post_id);
        $map = [];
        foreach ( $definitions as $slug => $definition ) {
            $slug = eventosapp_whatsapp_flow_metrics_sanitize_slug($slug, 'pregunta_' . (count($map) + 1));
            if ( $slug === '' ) {
                continue;
            }

            $options = [];
            foreach ( (array)($definition['choices'] ?? []) as $choice ) {
                $options[] = [
                    'id'    => sanitize_text_field((string)($choice['id'] ?? '')),
                    'title' => sanitize_text_field((string)($choice['label'] ?? $choice['title'] ?? '')),
                ];
            }

            $map[$slug] = [
                'slug'    => $slug,
                'label'   => sanitize_text_field((string)($definition['label'] ?? eventosapp_whatsapp_flow_metrics_response_label_from_slug($slug))),
                'type'    => sanitize_key((string)($definition['type'] ?? 'text')),
                'options' => $options,
            ];
        }

        return $map;
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_export_collect_columns') ) {
    function eventosapp_whatsapp_flow_metrics_export_collect_columns($event_id, $flow_post_id) {
        global $wpdb;

        $event_id = absint($event_id);
        $flow_post_id = absint($flow_post_id);
        if ( ! $event_id || ! $flow_post_id || ! eventosapp_whatsapp_flow_metrics_require_dependencies() ) {
            return [];
        }

        $columns = [];
        $column_keys = [];
        $question_map = eventosapp_whatsapp_flow_metrics_get_export_question_map($flow_post_id);

        foreach ( $question_map as $slug => $question ) {
            $slug = eventosapp_whatsapp_flow_metrics_sanitize_slug($slug, 'pregunta_' . (count($columns) + 1));
            if ( $slug === '' ) {
                continue;
            }

            $column_key = $flow_post_id . ':' . $slug;
            if ( isset($column_keys[$column_key]) ) {
                continue;
            }

            $label = sanitize_text_field((string)($question['label'] ?? eventosapp_whatsapp_flow_metrics_response_label_from_slug($slug)));
            if ( $label === '' ) {
                $label = eventosapp_whatsapp_flow_metrics_response_label_from_slug($slug);
            }

            $columns[] = [
                'key'          => $column_key,
                'flow_post_id' => $flow_post_id,
                'slug'         => $slug,
                'label'        => $label,
                'header'       => $label,
                'question'     => is_array($question) ? $question : [],
                'source'       => 'flow_config',
            ];
            $column_keys[$column_key] = true;
        }

        $responses_table = eventosapp_whatsapp_flows_responses_table_name();
        $sends_table = eventosapp_whatsapp_flows_sends_table_name();
        $batch_size = (int) apply_filters('eventosapp_whatsapp_flow_metrics_export_column_scan_batch_size', 700, $event_id, $flow_post_id);
        $batch_size = max(100, min(1500, $batch_size));
        $last_id = 0;

        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT r.id, r.response_json
                 FROM {$responses_table} r
                 LEFT JOIN {$sends_table} s ON s.id = r.send_id
                 WHERE r.id > %d
                   AND r.flow_post_id = %d
                   AND (r.event_id = %d OR s.event_id = %d)
                 ORDER BY r.id ASC
                 LIMIT %d",
                $last_id,
                $flow_post_id,
                $event_id,
                $event_id,
                $batch_size
            ), ARRAY_A);

            if ( empty($rows) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $last_id = max($last_id, absint($row['id'] ?? 0));
                $decoded = eventosapp_whatsapp_flow_metrics_decode_response_json($row['response_json'] ?? '');
                if ( ! is_array($decoded) || empty($decoded) ) {
                    continue;
                }

                foreach ( $decoded as $key => $value ) {
                    $slug = eventosapp_whatsapp_flow_metrics_sanitize_slug($key, 'respuesta_' . (count($columns) + 1));
                    if ( $slug === '' || eventosapp_whatsapp_flow_metrics_is_internal_response_key($slug) || isset($question_map[$slug]) ) {
                        continue;
                    }

                    $column_key = $flow_post_id . ':' . $slug;
                    if ( isset($column_keys[$column_key]) ) {
                        continue;
                    }

                    $label = eventosapp_whatsapp_flow_metrics_response_label_from_slug($slug);
                    $columns[] = [
                        'key'          => $column_key,
                        'flow_post_id' => $flow_post_id,
                        'slug'         => $slug,
                        'label'        => $label,
                        'header'       => $label,
                        'question'     => [],
                        'source'       => 'response_json',
                    ];
                    $column_keys[$column_key] = true;
                }
            }
        } while ( count($rows) >= $batch_size );

        $used_headers = [];
        foreach ( $columns as $index => $column ) {
            $header = sanitize_text_field((string)($column['header'] ?? ''));
            if ( $header === '' ) {
                $header = eventosapp_whatsapp_flow_metrics_response_label_from_slug($column['slug'] ?? 'respuesta');
            }

            $base_header = $header;
            if ( isset($used_headers[$header]) ) {
                $suffix = sanitize_key((string)($column['slug'] ?? ''));
                $header = $base_header . ' [' . ($suffix !== '' ? $suffix : ((int) $index + 1)) . ']';
            }
            while ( isset($used_headers[$header]) ) {
                $header = $base_header . ' [' . ((int) $index + 1) . ']';
            }

            $columns[$index]['header'] = $header;
            $used_headers[$header] = true;
        }

        return $columns;
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_export_cell_value') ) {
    function eventosapp_whatsapp_flow_metrics_export_cell_value($row, $column) {
        if ( ! is_array($row) || ! is_array($column) ) {
            return '';
        }

        $row_flow_id = absint($row['flow_post_id'] ?? 0);
        $column_flow_id = absint($column['flow_post_id'] ?? 0);
        if ( $row_flow_id && $column_flow_id && $row_flow_id !== $column_flow_id ) {
            return '';
        }

        $decoded = eventosapp_whatsapp_flow_metrics_decode_response_json($row['response_json'] ?? '');
        if ( ! is_array($decoded) ) {
            return '';
        }

        $slug = eventosapp_whatsapp_flow_metrics_sanitize_slug($column['slug'] ?? '', '');
        if ( $slug === '' || ! array_key_exists($slug, $decoded) ) {
            return '';
        }

        $question = is_array($column['question'] ?? null) ? $column['question'] : [];
        if ( function_exists('eventosapp_whatsapp_flows_format_response_value_for_question') ) {
            return eventosapp_whatsapp_flows_format_response_value_for_question($decoded[$slug], $question);
        }

        $labels = eventosapp_whatsapp_flow_metrics_value_to_labels($decoded[$slug], $question);
        if ( ! empty($labels) ) {
            return implode(', ', $labels);
        }

        if ( is_array($decoded[$slug]) || is_object($decoded[$slug]) ) {
            return sanitize_textarea_field(wp_json_encode($decoded[$slug], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return sanitize_text_field((string) $decoded[$slug]);
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_stream_csv_export') ) {
    function eventosapp_whatsapp_flow_metrics_stream_csv_export($event_id, $flow_post_id) {
        global $wpdb;

        $event_id = absint($event_id);
        $flow_post_id = absint($flow_post_id);
        if ( ! $event_id || ! $flow_post_id ) {
            wp_die('Falta el evento o la encuesta para descargar el CSV.');
        }

        $responses_table = eventosapp_whatsapp_flows_responses_table_name();
        $sends_table = eventosapp_whatsapp_flows_sends_table_name();
        $question_columns = eventosapp_whatsapp_flow_metrics_export_collect_columns($event_id, $flow_post_id);

        $event_slug = sanitize_title(get_the_title($event_id));
        if ( $event_slug === '' ) {
            $event_slug = 'evento-' . $event_id;
        }

        $flow_slug = sanitize_title(eventosapp_whatsapp_flow_metrics_get_flow_title($flow_post_id));
        if ( $flow_slug === '' ) {
            $flow_slug = 'encuesta-' . $flow_post_id;
        }

        $filename = sanitize_file_name('eventosapp-metricas-encuestas-' . $event_slug . '-' . $flow_slug . '-' . date('Ymd-His') . '.csv');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        if ( ! $out ) {
            exit;
        }

        $headers = ['response_id', 'flow_post_id', 'meta_flow_id', 'event_id', 'ticket_id', 'phone', 'flow_token', 'wa_message_id', 'reply_to_message_id', 'created_at', 'summary', 'response_json'];
        foreach ( $question_columns as $column ) {
            $headers[] = sanitize_text_field((string)($column['header'] ?? $column['label'] ?? $column['slug'] ?? 'respuesta'));
        }
        fputcsv($out, $headers);

        $batch_size = (int) apply_filters('eventosapp_whatsapp_flow_metrics_export_rows_batch_size', 700, $event_id, $flow_post_id);
        $batch_size = max(100, min(1500, $batch_size));
        $last_id = 0;

        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT r.*
                 FROM {$responses_table} r
                 LEFT JOIN {$sends_table} s ON s.id = r.send_id
                 WHERE r.id > %d
                   AND r.flow_post_id = %d
                   AND (r.event_id = %d OR s.event_id = %d)
                 ORDER BY r.id ASC
                 LIMIT %d",
                $last_id,
                $flow_post_id,
                $event_id,
                $event_id,
                $batch_size
            ), ARRAY_A);

            if ( empty($rows) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $last_id = max($last_id, absint($row['id'] ?? 0));
                $line = [
                    $row['id'] ?? '',
                    $row['flow_post_id'] ?? '',
                    $row['meta_flow_id'] ?? '',
                    $row['event_id'] ?? '',
                    $row['ticket_id'] ?? '',
                    $row['phone'] ?? '',
                    $row['flow_token'] ?? '',
                    $row['wa_message_id'] ?? '',
                    $row['reply_to_message_id'] ?? '',
                    $row['created_at'] ?? '',
                    $row['response_summary'] ?? '',
                    $row['response_json'] ?? '',
                ];

                foreach ( $question_columns as $column ) {
                    $line[] = eventosapp_whatsapp_flow_metrics_export_cell_value($row, $column);
                }

                fputcsv($out, $line);
            }

            if ( function_exists('flush') ) {
                flush();
            }
        } while ( count($rows) >= $batch_size );

        fclose($out);
        exit;
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_handle_csv_export') ) {
    function eventosapp_whatsapp_flow_metrics_handle_csv_export() {
        try {
            eventosapp_whatsapp_flow_metrics_verify_export_nonce();

            $event_id = absint(eventosapp_whatsapp_flow_metrics_get_request_value('event_id', 0));
            if ( ! $event_id ) {
                $event_id = eventosapp_whatsapp_flow_metrics_get_active_event_id();
            }

            if ( ! $event_id ) {
                wp_die('No hay evento activo para descargar resultados.');
            }

            if ( get_post_type($event_id) !== 'eventosapp_event' ) {
                wp_die('Evento inválido para descargar resultados.');
            }

            if ( ! eventosapp_whatsapp_flow_metrics_user_can_download_csv($event_id) ) {
                wp_die('No tienes permisos suficientes para descargar este CSV.');
            }

            if ( ! eventosapp_whatsapp_flow_metrics_require_dependencies() ) {
                wp_die('El módulo de métricas de encuestas no está disponible.');
            }

            $flows = eventosapp_whatsapp_flow_metrics_get_event_flows($event_id);
            $valid_flow_ids = array_map('absint', wp_list_pluck($flows, 'id'));
            $requested_flow_id = absint(eventosapp_whatsapp_flow_metrics_get_request_value('flow_id', 0));
            $default_flow_id = ! empty($flows[0]['id']) ? absint($flows[0]['id']) : 0;
            $flow_post_id = $requested_flow_id ?: $default_flow_id;

            if ( ! $flow_post_id || ! in_array($flow_post_id, $valid_flow_ids, true) ) {
                wp_die('La encuesta solicitada no pertenece al evento activo o no está disponible para descargar.');
            }

            eventosapp_whatsapp_flow_metrics_stream_csv_export($event_id, $flow_post_id);
        } catch ( Throwable $e ) {
            eventosapp_whatsapp_flow_metrics_log_debug('csv_export_error', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            wp_die('No se pudo generar el CSV de resultados de la encuesta.');
        }
    }
}

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_handle_frontend_csv_export') ) {
    function eventosapp_whatsapp_flow_metrics_handle_frontend_csv_export() {
        if ( ! eventosapp_whatsapp_flow_metrics_is_frontend_export_request() ) {
            return;
        }

        eventosapp_whatsapp_flow_metrics_handle_csv_export();
    }
}

add_action('init', 'eventosapp_whatsapp_flow_metrics_handle_frontend_csv_export', -1000);
add_action('admin_post_eventosapp_whatsapp_flow_metrics_export_csv', 'eventosapp_whatsapp_flow_metrics_handle_csv_export');

if ( ! function_exists('eventosapp_whatsapp_flow_metrics_send_json_exception') ) {
    function eventosapp_whatsapp_flow_metrics_send_json_exception($e, $public_message = 'No se pudieron cargar las métricas de encuestas.') {
        $payload = ['error' => $public_message];
        if ( defined('WP_DEBUG') && WP_DEBUG && $e instanceof Throwable ) {
            $payload['debug_message'] = $e->getMessage();
            $payload['debug_file'] = basename($e->getFile());
            $payload['debug_line'] = $e->getLine();
        }
        wp_send_json_error($payload, 500);
    }
}

add_action('wp_ajax_eventosapp_whatsapp_flow_metrics_data', function() {
    try {
        if ( ! check_ajax_referer('eventosapp_whatsapp_flow_metrics_data', 'security', false) ) {
            wp_send_json_error(['error' => 'Sesión expirada o token inválido. Recarga la página e intenta nuevamente.'], 403);
        }

        if ( ! eventosapp_whatsapp_flow_metrics_can_view() ) {
            wp_send_json_error(['error' => 'Permisos insuficientes.'], 403);
        }

        $event_id = eventosapp_whatsapp_flow_metrics_get_active_event_id();
        if ( ! $event_id ) {
            wp_send_json_error(['error' => 'No hay evento activo.'], 400);
        }

        if ( ! eventosapp_whatsapp_flow_metrics_require_dependencies() ) {
            wp_send_json_error(['error' => 'El módulo de métricas de encuestas no está disponible.'], 500);
        }

        $flows = eventosapp_whatsapp_flow_metrics_get_event_flows($event_id);
        $requested_flow_id = absint($_POST['flow_id'] ?? 0);
        $default_flow_id = ! empty($flows[0]['id']) ? absint($flows[0]['id']) : 0;
        $flow_id = $requested_flow_id ?: $default_flow_id;

        wp_send_json_success(eventosapp_whatsapp_flow_metrics_build_payload($event_id, $flow_id));
    } catch ( Throwable $e ) {
        eventosapp_whatsapp_flow_metrics_log_debug('ajax_error', [
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ]);
        eventosapp_whatsapp_flow_metrics_send_json_exception($e);
    }
});

add_shortcode('eventosapp_whatsapp_flow_metrics', function() {
    if ( function_exists('eventosapp_require_feature') ) {
        eventosapp_require_feature('flow_metrics');
    } elseif ( ! eventosapp_whatsapp_flow_metrics_can_view() ) {
        return '<p>No tienes permisos para ver esta sección.</p>';
    }

    $active_event = eventosapp_whatsapp_flow_metrics_get_active_event_id();
    if ( ! $active_event ) {
        ob_start();
        if ( function_exists('eventosapp_require_active_event') ) {
            eventosapp_require_active_event();
        } else {
            echo '<p>Debes seleccionar un evento activo.</p>';
        }
        return ob_get_clean();
    }

    if ( ! eventosapp_whatsapp_flow_metrics_require_dependencies() ) {
        return '<p>El módulo de métricas de encuestas no está disponible o no se cargó correctamente.</p>';
    }

    $flows = eventosapp_whatsapp_flow_metrics_get_event_flows($active_event);
    $default_flow_id = ! empty($flows[0]['id']) ? absint($flows[0]['id']) : 0;
    $nonce = wp_create_nonce('eventosapp_whatsapp_flow_metrics_data');
    $export_url = eventosapp_whatsapp_flow_metrics_get_export_url($active_event, $default_flow_id);
    // Plantilla RAW para JavaScript. No se usa wp_nonce_url() porque transforma & en &amp;
    // y eso rompe la verificación del nonce cuando JS actualiza el href del botón.
    $export_url_template = add_query_arg([
        'eventosapp_frontend_action' => 'eventosapp_whatsapp_flow_metrics_export_csv',
        'event_id'                   => absint($active_event),
        'flow_id'                    => '__EVAPP_FLOW_ID__',
        '_wpnonce'                   => wp_create_nonce('eventosapp_whatsapp_flow_metrics_export_csv'),
    ], home_url('/'));

    wp_register_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
    wp_enqueue_script('chartjs');

    ob_start();
    ?>
    <style>
        .evapp-flow-metrics-wrap{max-width:1120px;margin:0 auto;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#0f172a;}
        .evapp-flow-metrics-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:14px;}
        .evapp-flow-metrics-title{font-size:1.35rem;font-weight:900;letter-spacing:.2px;margin:0;color:#0b1020;}
        .evapp-flow-metrics-subtitle{margin:5px 0 0;color:#64748b;font-size:.95rem;}
        .evapp-flow-metrics-toolbar{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin:0 0 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:14px;}
        .evapp-flow-metrics-toolbar label{display:flex;flex-direction:column;gap:5px;font-weight:800;color:#334155;font-size:.88rem;}
        .evapp-flow-metrics-toolbar select{min-height:38px;min-width:300px;border:1px solid #cbd5e1;border-radius:10px;padding:6px 10px;background:#fff;}
        .evapp-flow-metrics-flow-static{font-weight:900;color:#1e293b;background:#fff;border:1px solid #e2e8f0;border-radius:999px;padding:9px 13px;}
        .evapp-flow-metrics-download{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 14px;border-radius:10px;background:#3454f4;color:#fff!important;text-decoration:none;font-weight:900;font-size:.9rem;box-shadow:0 7px 18px rgba(52,84,244,.22);}
        .evapp-flow-metrics-download:hover{background:#203bc4;color:#fff!important;text-decoration:none;}
        .evapp-flow-metrics-download.is-disabled{opacity:.55;pointer-events:none;box-shadow:none;}
        .evapp-flow-metrics-status{font-size:.88rem;color:#64748b;margin-left:auto;}
        .evapp-flow-metrics-status.is-loading{color:#b45309;}
        .evapp-flow-metrics-status.is-error{color:#b91c1c;font-weight:800;}
        .evapp-flow-metrics-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:14px;}
        .evapp-flow-metrics-kpi{background:#0b1020;color:#eaf1ff;border-radius:16px;padding:16px;box-shadow:0 8px 22px rgba(15,23,42,.12);}
        .evapp-flow-metrics-kpi span{display:block;color:#a9b6d3;font-size:.9rem;font-weight:800;margin-bottom:6px;}
        .evapp-flow-metrics-kpi strong{display:block;font-size:2.35rem;line-height:1;font-weight:950;}
        .evapp-flow-metrics-kpi small{display:block;margin-top:7px;color:#cfe0ff;}
        .evapp-flow-metrics-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:12px;}
        .evapp-flow-metrics-card{grid-column:span 12;background:#0b1020;color:#eaf1ff;border-radius:16px;padding:16px;box-shadow:0 8px 22px rgba(15,23,42,.12);}
        @media(min-width:850px){.evapp-flow-metrics-card{grid-column:span 6;}}
        .evapp-flow-metrics-card h3{margin:0 0 4px;color:#cfe0ff;font-size:1rem;line-height:1.35;}
        .evapp-flow-metrics-card .evapp-flow-metrics-question-meta{color:#a9b6d3;font-size:.86rem;margin-bottom:10px;}
        .evapp-flow-metrics-chart-box{position:relative;min-height:280px;margin:8px 0 12px;}
        .evapp-flow-metrics-table{width:100%;border-collapse:separate;border-spacing:0;margin-top:8px;}
        .evapp-flow-metrics-table th,.evapp-flow-metrics-table td{padding:8px 9px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;font-size:.9rem;}
        .evapp-flow-metrics-table th{color:#cfe0ff;background:#111d3d;}
        .evapp-flow-metrics-table td:nth-child(2),.evapp-flow-metrics-table td:nth-child(3){font-weight:800;white-space:nowrap;}
        .evapp-flow-metrics-empty{background:#fff7ed;border:1px solid #fed7aa;color:#7c2d12;border-radius:16px;padding:15px;margin-top:12px;}
        .evapp-flow-metrics-note{color:#64748b;font-size:.86rem;margin-top:10px;}
        @media(max-width:720px){.evapp-flow-metrics-head{display:block}.evapp-flow-metrics-kpis{grid-template-columns:1fr}.evapp-flow-metrics-toolbar select{min-width:100%;}.evapp-flow-metrics-status{width:100%;margin-left:0}.evapp-flow-metrics-chart-box{min-height:240px;}}
    </style>

    <div class="evapp-flow-metrics-wrap" data-evapp-flow-metrics-root data-event-id="<?php echo esc_attr($active_event); ?>" data-default-flow-id="<?php echo esc_attr($default_flow_id); ?>">
        <div class="evapp-flow-metrics-head">
            <div>
                <h2 class="evapp-flow-metrics-title">Métricas de Encuestas</h2>
                <p class="evapp-flow-metrics-subtitle">Evento activo: <strong><?php echo esc_html(get_the_title($active_event)); ?></strong></p>
            </div>
        </div>

        <?php if ( empty($flows) ) : ?>
            <div class="evapp-flow-metrics-empty">Todavía no hay encuestas enviadas, respondidas o configuradas para este evento.</div>
        <?php else : ?>
            <div class="evapp-flow-metrics-toolbar">
                <?php if ( count($flows) > 1 ) : ?>
                    <label for="evappFlowMetricsFlow">Encuesta a revisar
                        <select id="evappFlowMetricsFlow">
                            <?php foreach ( $flows as $flow ) : ?>
                                <option value="<?php echo esc_attr(absint($flow['id'])); ?>" <?php selected(absint($flow['id']), $default_flow_id); ?>>
                                    <?php echo esc_html($flow['title']); ?><?php echo ! empty($flow['last_activity']) ? ' — último movimiento ' . esc_html($flow['last_activity']) : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php else : ?>
                    <div>
                        <div style="font-size:.88rem;font-weight:800;color:#334155;margin-bottom:5px;">Encuesta a revisar</div>
                        <div class="evapp-flow-metrics-flow-static"><?php echo esc_html($flows[0]['title']); ?></div>
                        <input type="hidden" id="evappFlowMetricsFlow" value="<?php echo esc_attr($default_flow_id); ?>">
                    </div>
                <?php endif; ?>
                <a class="evapp-flow-metrics-download<?php echo $default_flow_id ? '' : ' is-disabled'; ?>" id="evappFlowMetricsCsvDownload" href="<?php echo esc_url($export_url); ?>">Descargar resultados CSV</a>
                <div class="evapp-flow-metrics-status" id="evappFlowMetricsStatus">Preparando métricas…</div>
            </div>

            <div class="evapp-flow-metrics-kpis" aria-label="Indicadores de encuestas">
                <div class="evapp-flow-metrics-kpi"><span>Mensajes / encuestas enviadas</span><strong id="evappFlowMetricSent">0</strong><small>Solicitudes aceptadas por Meta</small></div>
                <div class="evapp-flow-metrics-kpi"><span>Encuestas leídas</span><strong id="evappFlowMetricRead">0</strong><small><span id="evappFlowMetricReadRate">0%</span> sobre enviadas</small></div>
                <div class="evapp-flow-metrics-kpi"><span>Encuestas respondidas</span><strong id="evappFlowMetricAnswered">0</strong><small><span id="evappFlowMetricAnswerRate">0%</span> sobre enviadas</small></div>
            </div>

            <div class="evapp-flow-metrics-grid" id="evappFlowMetricsCharts"></div>
            <div class="evapp-flow-metrics-note">Los gráficos se calculan por lotes desde la tabla de respuestas para no cargar todos los tickets ni metadatos del evento en memoria.</div>
        <?php endif; ?>
    </div>

    <?php if ( ! empty($flows) ) : ?>
    <script>
    (function(){
        const root = document.querySelector('[data-evapp-flow-metrics-root]');
        if (!root) return;

        const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        const nonce = <?php echo wp_json_encode($nonce); ?>;
        const rawExportUrlTemplate = <?php echo wp_json_encode($export_url_template); ?>;
        const exportUrlTemplate = decodeHtmlEntities(rawExportUrlTemplate);
        const flowInput = document.getElementById('evappFlowMetricsFlow');
        const csvDownload = document.getElementById('evappFlowMetricsCsvDownload');
        const statusEl = document.getElementById('evappFlowMetricsStatus');
        const chartsWrap = document.getElementById('evappFlowMetricsCharts');
        const sentEl = document.getElementById('evappFlowMetricSent');
        const readEl = document.getElementById('evappFlowMetricRead');
        const answeredEl = document.getElementById('evappFlowMetricAnswered');
        const readRateEl = document.getElementById('evappFlowMetricReadRate');
        const answerRateEl = document.getElementById('evappFlowMetricAnswerRate');
        let chartInstances = [];

        function numberFormat(value){
            const n = Number(value || 0);
            return n.toLocaleString('es-CO');
        }

        function percentFormat(value){
            const n = Number(value || 0);
            return n.toLocaleString('es-CO', { maximumFractionDigits: 2 }) + '%';
        }

        function escapeHtml(value){
            return String(value == null ? '' : value).replace(/[&<>'"]/g, function(c){
                return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];
            });
        }

        function decodeHtmlEntities(value){
            const textarea = document.createElement('textarea');
            textarea.innerHTML = String(value == null ? '' : value);
            return textarea.value;
        }

        function destroyCharts(){
            chartInstances.forEach(function(chart){
                try { chart.destroy(); } catch(e) {}
            });
            chartInstances = [];
        }

        function setStatus(message, state){
            if (!statusEl) return;
            statusEl.textContent = message || '';
            statusEl.classList.toggle('is-loading', state === 'loading');
            statusEl.classList.toggle('is-error', state === 'error');
        }

        function updateDownloadLink(flowId){
            if (!csvDownload) return;
            flowId = flowId || (flowInput ? flowInput.value : root.getAttribute('data-default-flow-id'));
            if (!flowId || !exportUrlTemplate) {
                csvDownload.setAttribute('href', '#');
                csvDownload.classList.add('is-disabled');
                return;
            }
            csvDownload.setAttribute('href', exportUrlTemplate.replace('__EVAPP_FLOW_ID__', encodeURIComponent(flowId)));
            csvDownload.classList.remove('is-disabled');
        }

        function renderKpis(counts){
            counts = counts || {};
            sentEl.textContent = numberFormat(counts.sent);
            readEl.textContent = numberFormat(counts.read);
            answeredEl.textContent = numberFormat(counts.answered);
            readRateEl.textContent = percentFormat(counts.read_rate);
            answerRateEl.textContent = percentFormat(counts.answer_rate);
        }

        function renderEmpty(message){
            destroyCharts();
            chartsWrap.innerHTML = '<div class="evapp-flow-metrics-card" style="grid-column:span 12;"><h3>Sin respuestas para graficar</h3><p style="color:#a9b6d3;margin:0;">'+escapeHtml(message || 'Todavía no hay respuestas con preguntas de selección para esta encuesta.')+'</p></div>';
        }

        function renderCharts(questions){
            destroyCharts();
            chartsWrap.innerHTML = '';

            if (!Array.isArray(questions) || !questions.length) {
                renderEmpty('Esta encuesta todavía no tiene respuestas de selección para graficar.');
                return;
            }

            questions.forEach(function(question, index){
                const options = Array.isArray(question.options) ? question.options : [];
                const labels = options.map(function(item){ return item.label || 'Sin etiqueta'; });
                const values = options.map(function(item){ return Number(item.count || 0); });
                const total = values.reduce(function(a,b){ return a + b; }, 0);
                const canvasId = 'evappFlowMetricChart_' + index + '_' + String(question.slug || 'q').replace(/[^a-zA-Z0-9_-]/g, '');

                let rows = '';
                options.forEach(function(item){
                    rows += '<tr><td>'+escapeHtml(item.label || 'Sin etiqueta')+'</td><td>'+numberFormat(item.count)+'</td><td>'+percentFormat(item.percent)+'</td></tr>';
                });
                if (!rows) {
                    rows = '<tr><td colspan="3">Sin opciones registradas.</td></tr>';
                }

                const card = document.createElement('div');
                card.className = 'evapp-flow-metrics-card';
                card.innerHTML = '<h3>'+escapeHtml(question.label || 'Pregunta')+'</h3>'+
                    '<div class="evapp-flow-metrics-question-meta">Respuestas válidas: '+numberFormat(question.answered_responses)+' · Selecciones graficadas: '+numberFormat(question.selection_total)+'</div>'+
                    '<div class="evapp-flow-metrics-chart-box"><canvas id="'+escapeHtml(canvasId)+'"></canvas></div>'+
                    '<table class="evapp-flow-metrics-table"><thead><tr><th>Opción</th><th>Número</th><th>Porcentaje</th></tr></thead><tbody>'+rows+'</tbody></table>';
                chartsWrap.appendChild(card);

                if (window.Chart && total > 0) {
                    const ctx = document.getElementById(canvasId);
                    const chart = new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: labels,
                            datasets: [{ data: values }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'bottom', labels: { color: '#eaf1ff' } },
                                tooltip: {
                                    callbacks: {
                                        label: function(context){
                                            const raw = Number(context.parsed || 0);
                                            const pct = total > 0 ? (raw / total) * 100 : 0;
                                            return ' ' + context.label + ': ' + numberFormat(raw) + ' (' + pct.toLocaleString('es-CO', { maximumFractionDigits: 2 }) + '%)';
                                        }
                                    }
                                }
                            }
                        }
                    });
                    chartInstances.push(chart);
                } else if (total <= 0) {
                    const box = card.querySelector('.evapp-flow-metrics-chart-box');
                    if (box) box.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:220px;color:#a9b6d3;text-align:center;">Sin respuestas para esta pregunta.</div>';
                }
            });
        }

        function fetchData(){
            const flowId = flowInput ? flowInput.value : root.getAttribute('data-default-flow-id');
            updateDownloadLink(flowId);
            if (!flowId) {
                renderKpis({});
                renderEmpty('No hay encuesta seleccionada.');
                return;
            }

            setStatus('Cargando métricas…', 'loading');
            const body = new FormData();
            body.append('action', 'eventosapp_whatsapp_flow_metrics_data');
            body.append('security', nonce);
            body.append('flow_id', flowId);

            fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: body })
                .then(function(resp){
                    return resp.json().then(function(json){
                        if (!resp.ok || !json || !json.success) {
                            const message = json && json.data && json.data.error ? json.data.error : 'No se pudieron cargar las métricas.';
                            throw new Error(message);
                        }
                        return json.data;
                    });
                })
                .then(function(data){
                    renderKpis(data.counts || {});
                    renderCharts(data.questions || []);
                    const perf = data.performance || {};
                    const suffix = perf.cached ? ' · cache' : ' · actualizado';
                    setStatus('Métricas cargadas' + suffix, 'ok');
                })
                .catch(function(error){
                    renderKpis({});
                    renderEmpty(error.message || 'Error al cargar métricas.');
                    setStatus(error.message || 'Error al cargar métricas.', 'error');
                });
        }

        if (flowInput && flowInput.addEventListener) {
            flowInput.addEventListener('change', function(){
                updateDownloadLink(flowInput.value);
                fetchData();
            });
        }

        updateDownloadLink(flowInput ? flowInput.value : root.getAttribute('data-default-flow-id'));
        fetchData();
    })();
    </script>
    <?php endif; ?>
    <?php
    return ob_get_clean();
});
