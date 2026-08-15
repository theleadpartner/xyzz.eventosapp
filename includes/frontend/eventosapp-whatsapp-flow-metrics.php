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
                'sent'          => 0,
                'delivered'     => 0,
                'read'          => 0,
                'answered'      => 0,
                'delivery_rate' => 0,
                'read_rate'     => 0,
                'answer_rate'   => 0,
            ];
        }

        $sends_table = eventosapp_whatsapp_flows_sends_table_name();
        $responses_table = eventosapp_whatsapp_flows_responses_table_name();

        /*
         * Un solo recorrido de la tabla de envíos obtiene enviados, entregados y leídos.
         *
         * Importante: un WhatsApp Flow puede terminar con status = response_received
         * antes de que exista un delivery_status explícito en la fila. El webhook de
         * respuesta sí permite localizar el envío y, por tanto, confirma que el mensaje
         * fue aceptado/enviado y que el usuario llegó a abrir/interactuar con el Flow.
         *
         * En ese caso no debemos mostrar 0 enviados / 0 entregados / 0 leídos cuando
         * existen respuestas reales. Para el embudo de esta pantalla se considera:
         *   - response_received => enviado
         *   - response_received => entregado
         *   - response_received => leído/interactuado
         *
         * Esto no altera los datos almacenados ni falsifica el webhook de Meta; solamente
         * normaliza el embudo analítico a partir del estado final conocido del envío.
         */
        $send_metrics = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(
                        CASE
                            WHEN status NOT LIKE 'failed%%'
                             AND (
                                 wa_message_id <> ''
                                 OR status IN ('sent_request','webhook_sent','webhook_delivered','webhook_read','delivered','read','response_received')
                                 OR response_received = 1
                             )
                            THEN 1 ELSE 0
                        END
                    ) AS sent,
                    SUM(
                        CASE
                            WHEN status NOT LIKE 'failed%%'
                             AND (
                                 delivery_status IN ('delivered','read')
                                 OR status IN ('webhook_delivered','webhook_read','delivered','read','response_received')
                                 OR response_received = 1
                             )
                            THEN 1 ELSE 0
                        END
                    ) AS delivered,
                    SUM(
                        CASE
                            WHEN status NOT LIKE 'failed%%'
                             AND (
                                 delivery_status = 'read'
                                 OR status IN ('webhook_read','read','response_received')
                                 OR response_received = 1
                             )
                            THEN 1 ELSE 0
                        END
                    ) AS read
                 FROM {$sends_table}
                 WHERE event_id = %d AND flow_post_id = %d",
                $event_id,
                $flow_post_id
            ),
            ARRAY_A
        );

        $sent = absint($send_metrics['sent'] ?? 0);
        $delivered = absint($send_metrics['delivered'] ?? 0);
        $read = absint($send_metrics['read'] ?? 0);

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
            'sent'          => $sent,
            'delivered'     => $delivered,
            'read'          => $read,
            'answered'      => $answered,
            'delivery_rate' => $sent > 0 ? round(($delivered / $sent) * 100, 2) : 0,
            'read_rate'     => $sent > 0 ? round(($read / $sent) * 100, 2) : 0,
            'answer_rate'   => $sent > 0 ? round(($answered / $sent) * 100, 2) : 0,
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
                'counts'   => ['sent'=>0, 'delivered'=>0, 'read'=>0, 'answered'=>0, 'delivery_rate'=>0, 'read_rate'=>0, 'answer_rate'=>0],
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
    $default_flow = ! empty($flows[0]) && is_array($flows[0]) ? $flows[0] : [];
    $nonce = wp_create_nonce('eventosapp_whatsapp_flow_metrics_data');
    $export_url = eventosapp_whatsapp_flow_metrics_get_export_url($active_event, $default_flow_id);

    $dashboard_url = function_exists('eventosapp_get_dashboard_url')
        ? eventosapp_get_dashboard_url()
        : home_url('/');
    $dashboard_url = remove_query_arg(['evapp', 'evapp_err', 'set'], $dashboard_url);
    $change_event_url = add_query_arg(['evapp' => 'change_event'], $dashboard_url);

    $event_title = get_the_title($active_event);
    if ( ! is_string($event_title) || trim($event_title) === '' ) {
        $event_title = 'Evento #' . absint($active_event);
    }

    $event_modalidad_label = function_exists('eventosapp_get_event_modalidad_label')
        ? eventosapp_get_event_modalidad_label($active_event)
        : '';

    $last_activity = sanitize_text_field((string)($default_flow['last_activity'] ?? ''));
    $last_activity_label = '';
    if ( $last_activity !== '' ) {
        $activity_timestamp = strtotime($last_activity);
        if ( $activity_timestamp ) {
            $last_activity_label = date_i18n(
                get_option('date_format') . ' · ' . get_option('time_format'),
                $activity_timestamp
            );
        }
    }

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
        .evapp-flow-metrics-app{
            --evapp-primary:#3279bd;
            --evapp-primary-dark:#255f96;
            --evapp-primary-soft:#eaf4ff;
            --evapp-app-bg:#f5f8fc;
            --evapp-surface:#ffffff;
            --evapp-border:#dfe7f1;
            --evapp-text:#182230;
            --evapp-muted:#64748b;
            --evapp-success:#16855b;
            --evapp-success-soft:#ecfdf5;
            --evapp-warning:#a16207;
            --evapp-warning-soft:#fff8e6;
            --evapp-danger:#c53a3a;
            --evapp-danger-soft:#fff1f1;
            --evapp-purple:#6d4bc3;
            --evapp-purple-soft:#f3efff;
            --evapp-radius:18px;
            --evapp-radius-lg:26px;
            width:100%;
            max-width:1180px;
            margin:0 auto;
            color:var(--evapp-text);
            font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
            line-height:1.45;
            box-sizing:border-box;
        }
        .evapp-flow-metrics-app *,
        .evapp-flow-metrics-app *::before,
        .evapp-flow-metrics-app *::after{box-sizing:border-box}
        .evapp-flow-metrics-app a{text-decoration:none}
        .evapp-flow-metrics-app button,
        .evapp-flow-metrics-app select{font:inherit}
        .evapp-flow-metrics-app [hidden]{display:none!important}
        .evapp-flow-metrics-app .screen-reader-text{
            position:absolute!important;
            width:1px!important;
            height:1px!important;
            padding:0!important;
            margin:-1px!important;
            overflow:hidden!important;
            clip:rect(0,0,0,0)!important;
            white-space:nowrap!important;
            border:0!important;
        }

        .evapp-flow-metrics-shell{
            width:100%;
            padding:clamp(18px,3vw,36px);
            background:var(--evapp-app-bg);
            border:1px solid var(--evapp-border);
            border-radius:var(--evapp-radius-lg);
            box-shadow:0 18px 50px rgba(31,65,99,.08);
        }

        .evapp-flow-metrics-header{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:24px;
            margin-bottom:22px;
        }
        .evapp-flow-metrics-heading{min-width:0}
        .evapp-flow-metrics-eyebrow{
            margin:0 0 7px;
            color:var(--evapp-primary);
            font-size:12px;
            font-weight:800;
            letter-spacing:.15em;
            text-transform:uppercase;
        }
        .evapp-flow-metrics-title{
            margin:0;
            color:var(--evapp-text);
            font-size:clamp(27px,4vw,42px);
            font-weight:800;
            line-height:1.08;
            letter-spacing:-.035em;
        }
        .evapp-flow-metrics-subtitle{
            max-width:760px;
            margin:10px 0 0;
            color:var(--evapp-muted);
            font-size:15px;
            line-height:1.6;
        }
        .evapp-flow-metrics-header-actions{flex:0 0 auto}

        .evapp-flow-metrics-btn{
            min-height:44px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:9px;
            margin:0;
            padding:10px 15px;
            border:1px solid transparent;
            border-radius:12px;
            background:#fff;
            color:var(--evapp-text);
            font:inherit;
            font-size:14px;
            font-weight:750;
            line-height:1.15;
            text-align:center;
            cursor:pointer;
            transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease,background .16s ease,color .16s ease,opacity .16s ease;
            -webkit-tap-highlight-color:transparent;
        }
        .evapp-flow-metrics-btn svg{
            width:18px;
            height:18px;
            flex:0 0 18px;
            fill:none;
            stroke:currentColor;
            stroke-width:2;
            stroke-linecap:round;
            stroke-linejoin:round;
        }
        .evapp-flow-metrics-btn:hover:not(:disabled):not(.is-disabled){transform:translateY(-1px)}
        .evapp-flow-metrics-btn:focus-visible{
            outline:3px solid rgba(50,121,189,.22);
            outline-offset:2px;
        }
        .evapp-flow-metrics-btn:disabled,
        .evapp-flow-metrics-btn.is-disabled{
            opacity:.55;
            cursor:not-allowed;
            pointer-events:none;
            transform:none!important;
            box-shadow:none!important;
        }
        .evapp-flow-metrics-btn-secondary{
            background:var(--evapp-surface);
            border-color:var(--evapp-border);
            color:var(--evapp-text)!important;
            box-shadow:0 5px 15px rgba(31,65,99,.05);
            white-space:nowrap;
        }
        .evapp-flow-metrics-btn-secondary:hover:not(:disabled){
            border-color:#c7d7e8;
            color:var(--evapp-primary-dark)!important;
            box-shadow:0 8px 20px rgba(31,65,99,.09);
        }
        .evapp-flow-metrics-btn-primary{
            background:var(--evapp-primary);
            border-color:var(--evapp-primary);
            color:#fff!important;
            box-shadow:0 9px 20px rgba(50,121,189,.18);
        }
        .evapp-flow-metrics-btn-primary:hover:not(:disabled){
            background:var(--evapp-primary-dark);
            border-color:var(--evapp-primary-dark);
            color:#fff!important;
            box-shadow:0 12px 24px rgba(50,121,189,.24);
        }

        .evapp-flow-metrics-event-context{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:16px;
            margin-bottom:22px;
            padding:16px 18px;
            background:var(--evapp-surface);
            border:1px solid var(--evapp-border);
            border-radius:var(--evapp-radius);
            box-shadow:0 8px 24px rgba(31,65,99,.045);
        }
        .evapp-flow-metrics-event-main{
            min-width:0;
            display:flex;
            align-items:center;
            gap:13px;
        }
        .evapp-flow-metrics-event-icon{
            width:44px;
            height:44px;
            flex:0 0 44px;
            display:grid;
            place-items:center;
            color:var(--evapp-primary);
            background:var(--evapp-primary-soft);
            border-radius:13px;
        }
        .evapp-flow-metrics-event-icon svg{
            width:22px;
            height:22px;
            fill:none;
            stroke:currentColor;
            stroke-width:1.9;
            stroke-linecap:round;
            stroke-linejoin:round;
        }
        .evapp-flow-metrics-event-copy{min-width:0}
        .evapp-flow-metrics-event-kicker{
            display:block;
            margin-bottom:3px;
            color:var(--evapp-muted);
            font-size:11px;
            font-weight:800;
            letter-spacing:.09em;
            text-transform:uppercase;
        }
        .evapp-flow-metrics-event-name{
            display:block;
            overflow:hidden;
            color:var(--evapp-text);
            font-size:15px;
            font-weight:800;
            line-height:1.3;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .evapp-flow-metrics-event-flow{
            display:block;
            margin-top:3px;
            overflow:hidden;
            color:var(--evapp-muted);
            font-size:12px;
            line-height:1.4;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .evapp-flow-metrics-event-meta{
            display:flex;
            align-items:center;
            justify-content:flex-end;
            flex-wrap:wrap;
            gap:8px;
        }
        .evapp-flow-metrics-chip{
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
        .evapp-flow-metrics-chip::before{
            width:7px;
            height:7px;
            border-radius:50%;
            background:#94a3b8;
            content:"";
        }
        .evapp-flow-metrics-chip.is-active{
            color:var(--evapp-success);
            border-color:#cfeadf;
            background:var(--evapp-success-soft);
        }
        .evapp-flow-metrics-chip.is-active::before{background:var(--evapp-success)}

        .evapp-flow-metrics-toolbar{
            display:flex;
            align-items:flex-end;
            justify-content:space-between;
            gap:18px;
            margin-bottom:18px;
            padding:18px;
            background:var(--evapp-surface);
            border:1px solid var(--evapp-border);
            border-radius:var(--evapp-radius);
            box-shadow:0 8px 26px rgba(31,65,99,.05);
        }
        .evapp-flow-metrics-toolbar-main{min-width:0;flex:1 1 auto}
        .evapp-flow-metrics-field-label{
            display:block;
            margin:0 0 6px;
            color:var(--evapp-muted);
            font-size:11px;
            font-weight:800;
            letter-spacing:.08em;
            text-transform:uppercase;
        }
        .evapp-flow-metrics-flow-static{
            display:inline-flex;
            align-items:center;
            min-height:42px;
            max-width:100%;
            padding:9px 12px;
            border:1px solid var(--evapp-border);
            border-radius:12px;
            background:#f8fafc;
            color:var(--evapp-text);
            font-size:14px;
            font-weight:800;
            line-height:1.35;
        }
        .evapp-flow-metrics-select{
            width:min(100%,460px);
            min-height:44px;
            margin:0;
            padding:9px 38px 9px 12px;
            border:1px solid var(--evapp-border);
            border-radius:12px;
            background:#fff;
            color:var(--evapp-text);
            font-size:14px;
            font-weight:700;
            outline:none;
            transition:border-color .16s ease,box-shadow .16s ease;
        }
        .evapp-flow-metrics-select:focus{
            border-color:var(--evapp-primary);
            box-shadow:0 0 0 3px rgba(50,121,189,.14);
        }
        .evapp-flow-metrics-toolbar-actions{
            display:flex;
            align-items:center;
            justify-content:flex-end;
            flex-wrap:wrap;
            gap:9px;
            flex:0 0 auto;
        }
        .evapp-flow-metrics-status{
            min-height:30px;
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:6px 10px;
            border:1px solid var(--evapp-border);
            border-radius:999px;
            background:#f8fafc;
            color:var(--evapp-muted);
            font-size:12px;
            font-weight:750;
            line-height:1.2;
        }
        .evapp-flow-metrics-status::before{
            width:7px;
            height:7px;
            flex:0 0 7px;
            border-radius:50%;
            background:#94a3b8;
            content:"";
        }
        .evapp-flow-metrics-status.is-loading{
            color:var(--evapp-warning);
            border-color:#f1dfad;
            background:var(--evapp-warning-soft);
        }
        .evapp-flow-metrics-status.is-loading::before{
            background:#d69e2e;
            animation:evapp-flow-metrics-pulse 1s ease-in-out infinite;
        }
        .evapp-flow-metrics-status.is-ok{
            color:var(--evapp-success);
            border-color:#cfeadf;
            background:var(--evapp-success-soft);
        }
        .evapp-flow-metrics-status.is-ok::before{background:var(--evapp-success)}
        .evapp-flow-metrics-status.is-error{
            color:var(--evapp-danger);
            border-color:#f2cccc;
            background:var(--evapp-danger-soft);
        }
        .evapp-flow-metrics-status.is-error::before{background:var(--evapp-danger)}
        @keyframes evapp-flow-metrics-pulse{
            0%,100%{opacity:.35}
            50%{opacity:1}
        }

        .evapp-flow-metrics-kpis{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:14px;
            margin-bottom:24px;
        }
        .evapp-flow-metrics-kpi{
            position:relative;
            min-width:0;
            overflow:hidden;
            padding:17px;
            background:var(--evapp-surface);
            border:1px solid var(--evapp-border);
            border-radius:var(--evapp-radius);
            box-shadow:0 8px 24px rgba(31,65,99,.045);
        }
        .evapp-flow-metrics-kpi-head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:15px;
        }
        .evapp-flow-metrics-kpi-icon{
            width:38px;
            height:38px;
            flex:0 0 38px;
            display:grid;
            place-items:center;
            border-radius:11px;
            color:var(--evapp-primary);
            background:var(--evapp-primary-soft);
        }
        .evapp-flow-metrics-kpi-icon.is-success{
            color:var(--evapp-success);
            background:var(--evapp-success-soft);
        }
        .evapp-flow-metrics-kpi-icon.is-purple{
            color:var(--evapp-purple);
            background:var(--evapp-purple-soft);
        }
        .evapp-flow-metrics-kpi-icon svg{
            width:19px;
            height:19px;
            fill:none;
            stroke:currentColor;
            stroke-width:2;
            stroke-linecap:round;
            stroke-linejoin:round;
        }
        .evapp-flow-metrics-kpi-label{
            display:block;
            color:var(--evapp-muted);
            font-size:12px;
            font-weight:800;
            line-height:1.35;
        }
        .evapp-flow-metrics-kpi strong{
            display:block;
            margin:0;
            color:var(--evapp-text);
            font-size:clamp(28px,3.4vw,38px);
            line-height:1;
            font-weight:850;
            letter-spacing:-.04em;
        }
        .evapp-flow-metrics-kpi small{
            display:block;
            margin-top:8px;
            color:var(--evapp-muted);
            font-size:12px;
            line-height:1.4;
        }
        .evapp-flow-metrics-kpi small b{color:var(--evapp-text);font-weight:800}
        .evapp-flow-metrics-kpi-progress{
            height:5px;
            margin-top:12px;
            overflow:hidden;
            border-radius:999px;
            background:#edf2f7;
        }
        .evapp-flow-metrics-kpi-progress span{
            display:block;
            width:0;
            height:100%;
            border-radius:inherit;
            background:var(--evapp-primary);
            transition:width .28s ease;
        }
        .evapp-flow-metrics-kpi-progress.is-success span{background:var(--evapp-success)}
        .evapp-flow-metrics-kpi-progress.is-purple span{background:var(--evapp-purple)}

        .evapp-flow-metrics-section-head{
            display:flex;
            align-items:flex-end;
            justify-content:space-between;
            gap:18px;
            margin-bottom:14px;
        }
        .evapp-flow-metrics-section-title{
            margin:0;
            color:var(--evapp-text);
            font-size:19px;
            font-weight:850;
            line-height:1.25;
            letter-spacing:-.015em;
        }
        .evapp-flow-metrics-section-copy{
            margin:5px 0 0;
            color:var(--evapp-muted);
            font-size:13px;
            line-height:1.5;
        }

        .evapp-flow-metrics-grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:14px;
        }
        .evapp-flow-metrics-card{
            min-width:0;
            padding:18px;
            background:var(--evapp-surface);
            border:1px solid var(--evapp-border);
            border-radius:var(--evapp-radius);
            box-shadow:0 8px 26px rgba(31,65,99,.05);
        }
        .evapp-flow-metrics-card.is-full{grid-column:1/-1}
        .evapp-flow-metrics-card h3{
            margin:0;
            color:var(--evapp-text);
            font-size:16px;
            font-weight:850;
            line-height:1.4;
        }
        .evapp-flow-metrics-question-meta{
            display:flex;
            align-items:center;
            flex-wrap:wrap;
            gap:8px;
            margin-top:7px;
            color:var(--evapp-muted);
            font-size:12px;
            line-height:1.4;
        }
        .evapp-flow-metrics-question-meta span{
            display:inline-flex;
            align-items:center;
            min-height:26px;
            padding:4px 8px;
            border:1px solid var(--evapp-border);
            border-radius:999px;
            background:#f8fafc;
            font-weight:700;
        }
        .evapp-flow-metrics-chart-box{
            position:relative;
            min-height:285px;
            margin:16px 0 12px;
            padding:8px 0;
        }
        .evapp-flow-metrics-chart-empty{
            min-height:230px;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:20px;
            border:1px dashed var(--evapp-border);
            border-radius:14px;
            background:#f8fafc;
            color:var(--evapp-muted);
            font-size:13px;
            line-height:1.5;
            text-align:center;
        }
        .evapp-flow-metrics-table-wrap{
            width:100%;
            overflow-x:auto;
            margin-top:8px;
            border:1px solid var(--evapp-border);
            border-radius:13px;
            -webkit-overflow-scrolling:touch;
        }
        .evapp-flow-metrics-table{
            width:100%;
            min-width:420px;
            border-collapse:collapse;
            margin:0;
            background:#fff;
        }
        .evapp-flow-metrics-table th,
        .evapp-flow-metrics-table td{
            padding:10px 11px;
            border-bottom:1px solid var(--evapp-border);
            color:var(--evapp-text);
            font-size:13px;
            line-height:1.35;
            text-align:left;
            vertical-align:middle;
        }
        .evapp-flow-metrics-table th{
            background:#f8fafc;
            color:#475569;
            font-size:11px;
            font-weight:850;
            letter-spacing:.05em;
            text-transform:uppercase;
        }
        .evapp-flow-metrics-table tbody tr:last-child td{border-bottom:0}
        .evapp-flow-metrics-table td:nth-child(2),
        .evapp-flow-metrics-table td:nth-child(3){
            font-weight:800;
            white-space:nowrap;
        }

        .evapp-flow-metrics-empty{
            display:flex;
            align-items:flex-start;
            gap:13px;
            padding:17px;
            background:var(--evapp-warning-soft);
            border:1px solid #f1dfad;
            border-radius:var(--evapp-radius);
            color:#7c4a03;
        }
        .evapp-flow-metrics-empty-icon{
            width:38px;
            height:38px;
            flex:0 0 38px;
            display:grid;
            place-items:center;
            border-radius:11px;
            background:#fff2c9;
            color:var(--evapp-warning);
        }
        .evapp-flow-metrics-empty-icon svg{
            width:19px;
            height:19px;
            fill:none;
            stroke:currentColor;
            stroke-width:2;
            stroke-linecap:round;
            stroke-linejoin:round;
        }
        .evapp-flow-metrics-empty h3{
            margin:0 0 4px;
            color:#7c4a03;
            font-size:15px;
            font-weight:850;
        }
        .evapp-flow-metrics-empty p{
            margin:0;
            color:#8a5b12;
            font-size:13px;
            line-height:1.55;
        }

        .evapp-flow-metrics-note{
            display:flex;
            align-items:flex-start;
            gap:10px;
            margin-top:16px;
            padding:12px 14px;
            border:1px solid var(--evapp-border);
            border-radius:14px;
            background:rgba(255,255,255,.65);
            color:var(--evapp-muted);
            font-size:12px;
            line-height:1.55;
        }
        .evapp-flow-metrics-note svg{
            width:17px;
            height:17px;
            flex:0 0 17px;
            margin-top:1px;
            fill:none;
            stroke:var(--evapp-primary);
            stroke-width:2;
            stroke-linecap:round;
            stroke-linejoin:round;
        }

        @media(max-width:980px){
            .evapp-flow-metrics-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
        }
        @media(max-width:820px){
            .evapp-flow-metrics-grid{grid-template-columns:1fr}
            .evapp-flow-metrics-card.is-full{grid-column:auto}
            .evapp-flow-metrics-toolbar{align-items:stretch;flex-direction:column}
            .evapp-flow-metrics-toolbar-actions{justify-content:flex-start}
        }
        @media(max-width:767px){
            .evapp-flow-metrics-shell{padding:16px;border-radius:20px}
            .evapp-flow-metrics-header{display:block;margin-bottom:18px}
            .evapp-flow-metrics-header-actions{margin-top:14px}
            .evapp-flow-metrics-header-actions .evapp-flow-metrics-btn{width:100%}
            .evapp-flow-metrics-event-context{align-items:flex-start;flex-direction:column;padding:14px}
            .evapp-flow-metrics-event-main{width:100%}
            .evapp-flow-metrics-event-meta{width:100%;justify-content:flex-start}
            .evapp-flow-metrics-event-context>.evapp-flow-metrics-event-meta>.evapp-flow-metrics-btn{width:100%}
            .evapp-flow-metrics-toolbar{padding:14px}
            .evapp-flow-metrics-toolbar-actions{width:100%}
            .evapp-flow-metrics-toolbar-actions .evapp-flow-metrics-btn{flex:1 1 180px}
            .evapp-flow-metrics-status{width:100%;justify-content:center}
            .evapp-flow-metrics-section-head{display:block}
            .evapp-flow-metrics-chart-box{min-height:255px}
        }
        @media(max-width:540px){
            .evapp-flow-metrics-kpis{grid-template-columns:1fr}
            .evapp-flow-metrics-toolbar-actions .evapp-flow-metrics-btn{width:100%;flex-basis:100%}
            .evapp-flow-metrics-flow-static{width:100%}
            .evapp-flow-metrics-select{width:100%}
            .evapp-flow-metrics-card{padding:15px}
            .evapp-flow-metrics-chart-box{min-height:235px}
            .evapp-flow-metrics-event-flow{white-space:normal}
        }
        @media(prefers-reduced-motion:reduce){
            .evapp-flow-metrics-app *,
            .evapp-flow-metrics-app *::before,
            .evapp-flow-metrics-app *::after{
                scroll-behavior:auto!important;
                animation-duration:.01ms!important;
                animation-iteration-count:1!important;
                transition-duration:.01ms!important;
            }
        }
    </style>

    <div
        class="evapp-flow-metrics-app"
        data-evapp-flow-metrics-root
        data-event-id="<?php echo esc_attr($active_event); ?>"
        data-default-flow-id="<?php echo esc_attr($default_flow_id); ?>"
    >
        <div class="evapp-flow-metrics-shell">
            <header class="evapp-flow-metrics-header">
                <div class="evapp-flow-metrics-heading">
                    <p class="evapp-flow-metrics-eyebrow">EVENTOSAPP</p>
                    <h1 class="evapp-flow-metrics-title">Métricas de Encuestas</h1>
                    <p class="evapp-flow-metrics-subtitle">
                        Analiza el alcance y las respuestas de la encuesta de satisfacción enviada por WhatsApp Flow para el evento activo.
                    </p>
                </div>

                <div class="evapp-flow-metrics-header-actions">
                    <a href="<?php echo esc_url($dashboard_url); ?>" class="evapp-flow-metrics-btn evapp-flow-metrics-btn-secondary" aria-label="Volver al dashboard">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg>
                        <span>Volver al dashboard</span>
                    </a>
                </div>
            </header>

            <section class="evapp-flow-metrics-event-context" aria-label="Evento activo">
                <div class="evapp-flow-metrics-event-main">
                    <div class="evapp-flow-metrics-event-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"></rect><path d="M8 2v4M16 2v4M3 9h18"></path></svg>
                    </div>
                    <div class="evapp-flow-metrics-event-copy">
                        <span class="evapp-flow-metrics-event-kicker">Evento activo</span>
                        <span class="evapp-flow-metrics-event-name"><?php echo esc_html($event_title); ?></span>
                        <?php if ( ! empty($default_flow['title']) ) : ?>
                            <span class="evapp-flow-metrics-event-flow">Encuesta: <?php echo esc_html($default_flow['title']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="evapp-flow-metrics-event-meta">
                    <?php if ( $event_modalidad_label !== '' ) : ?>
                        <span class="evapp-flow-metrics-chip is-active"><?php echo esc_html($event_modalidad_label); ?></span>
                    <?php endif; ?>
                    <?php if ( $last_activity_label !== '' ) : ?>
                        <span class="evapp-flow-metrics-chip">Última actividad · <?php echo esc_html($last_activity_label); ?></span>
                    <?php endif; ?>
                    <a class="evapp-flow-metrics-btn evapp-flow-metrics-btn-primary" href="<?php echo esc_url($change_event_url); ?>">Cambiar evento</a>
                </div>
            </section>

            <?php if ( empty($flows) ) : ?>
                <div class="evapp-flow-metrics-empty" role="status">
                    <div class="evapp-flow-metrics-empty-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z"></path><path d="M8 10h8M8 14h5"></path></svg>
                    </div>
                    <div>
                        <h3>No hay una encuesta disponible para analizar</h3>
                        <p>Configura la encuesta de satisfacción por WhatsApp Flow en “Diseño WhatsApp y Landing” del evento. Cuando existan envíos o respuestas, las métricas aparecerán aquí automáticamente.</p>
                    </div>
                </div>
            <?php else : ?>
                <section class="evapp-flow-metrics-toolbar" aria-label="Controles de métricas">
                    <div class="evapp-flow-metrics-toolbar-main">
                        <span class="evapp-flow-metrics-field-label">Encuesta analizada</span>
                        <?php if ( count($flows) > 1 ) : ?>
                            <label class="screen-reader-text" for="evappFlowMetricsFlow">Encuesta a revisar</label>
                            <select class="evapp-flow-metrics-select" id="evappFlowMetricsFlow">
                                <?php foreach ( $flows as $flow ) : ?>
                                    <option value="<?php echo esc_attr(absint($flow['id'])); ?>" <?php selected(absint($flow['id']), $default_flow_id); ?>>
                                        <?php echo esc_html($flow['title']); ?><?php echo ! empty($flow['last_activity']) ? ' — último movimiento ' . esc_html($flow['last_activity']) : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else : ?>
                            <div class="evapp-flow-metrics-flow-static"><?php echo esc_html($flows[0]['title']); ?></div>
                            <input type="hidden" id="evappFlowMetricsFlow" value="<?php echo esc_attr($default_flow_id); ?>">
                        <?php endif; ?>
                    </div>

                    <div class="evapp-flow-metrics-toolbar-actions">
                        <button type="button" class="evapp-flow-metrics-btn evapp-flow-metrics-btn-secondary" id="evappFlowMetricsRefresh">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6v5h-5"></path><path d="M4 18v-5h5"></path><path d="M6.1 9a7 7 0 0 1 11.7-2.6L20 11M4 13l2.2 4.6A7 7 0 0 0 18 15"></path></svg>
                            <span>Actualizar</span>
                        </button>
                        <a
                            class="evapp-flow-metrics-btn evapp-flow-metrics-btn-primary<?php echo $default_flow_id ? '' : ' is-disabled'; ?>"
                            id="evappFlowMetricsCsvDownload"
                            href="<?php echo esc_url($export_url); ?>"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"></path><path d="m7 10 5 5 5-5"></path><path d="M5 21h14"></path></svg>
                            <span>Descargar CSV</span>
                        </a>
                        <div class="evapp-flow-metrics-status is-loading" id="evappFlowMetricsStatus" role="status" aria-live="polite">Preparando métricas…</div>
                    </div>
                </section>

                <section class="evapp-flow-metrics-kpis" aria-label="Indicadores de encuestas">
                    <article class="evapp-flow-metrics-kpi">
                        <div class="evapp-flow-metrics-kpi-head">
                            <span class="evapp-flow-metrics-kpi-label">Encuestas enviadas</span>
                            <span class="evapp-flow-metrics-kpi-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="m22 2-7 20-4-9-9-4Z"></path><path d="M22 2 11 13"></path></svg>
                            </span>
                        </div>
                        <strong id="evappFlowMetricSent">0</strong>
                        <small>Solicitudes aceptadas para envío por Meta.</small>
                    </article>

                    <article class="evapp-flow-metrics-kpi">
                        <div class="evapp-flow-metrics-kpi-head">
                            <span class="evapp-flow-metrics-kpi-label">Encuestas entregadas</span>
                            <span class="evapp-flow-metrics-kpi-icon is-success" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="m3 12 4 4 7-8"></path><path d="m10 15 3 3 8-10"></path></svg>
                            </span>
                        </div>
                        <strong id="evappFlowMetricDelivered">0</strong>
                        <small><b id="evappFlowMetricDeliveryRate">0%</b> sobre enviadas.</small>
                        <div class="evapp-flow-metrics-kpi-progress is-success" aria-hidden="true"><span id="evappFlowMetricDeliveryBar"></span></div>
                    </article>

                    <article class="evapp-flow-metrics-kpi">
                        <div class="evapp-flow-metrics-kpi-head">
                            <span class="evapp-flow-metrics-kpi-label">Encuestas leídas</span>
                            <span class="evapp-flow-metrics-kpi-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg>
                            </span>
                        </div>
                        <strong id="evappFlowMetricRead">0</strong>
                        <small><b id="evappFlowMetricReadRate">0%</b> sobre enviadas.</small>
                        <div class="evapp-flow-metrics-kpi-progress" aria-hidden="true"><span id="evappFlowMetricReadBar"></span></div>
                    </article>

                    <article class="evapp-flow-metrics-kpi">
                        <div class="evapp-flow-metrics-kpi-head">
                            <span class="evapp-flow-metrics-kpi-label">Encuestas respondidas</span>
                            <span class="evapp-flow-metrics-kpi-icon is-purple" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M4 5h16v13H8l-4 3Z"></path><path d="m8 11 2 2 5-5"></path></svg>
                            </span>
                        </div>
                        <strong id="evappFlowMetricAnswered">0</strong>
                        <small><b id="evappFlowMetricAnswerRate">0%</b> sobre enviadas.</small>
                        <div class="evapp-flow-metrics-kpi-progress is-purple" aria-hidden="true"><span id="evappFlowMetricAnswerBar"></span></div>
                    </article>
                </section>

                <section aria-labelledby="evappFlowMetricsQuestionsTitle">
                    <div class="evapp-flow-metrics-section-head">
                        <div>
                            <h2 class="evapp-flow-metrics-section-title" id="evappFlowMetricsQuestionsTitle">Resultados por pregunta</h2>
                            <p class="evapp-flow-metrics-section-copy">Las preguntas de selección se representan visualmente y conservan su tabla de valores para una lectura precisa.</p>
                        </div>
                    </div>

                    <div class="evapp-flow-metrics-grid" id="evappFlowMetricsCharts"></div>
                </section>

                <div class="evapp-flow-metrics-note">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 10v6M12 7h.01"></path></svg>
                    <span>El cálculo procesa las respuestas por lotes y usa una caché corta que se invalida cuando cambia la actividad del Flow. No se cargan todos los tickets ni sus metadatos en memoria.</span>
                </div>
            <?php endif; ?>
        </div>
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
        const refreshBtn = document.getElementById('evappFlowMetricsRefresh');
        const statusEl = document.getElementById('evappFlowMetricsStatus');
        const chartsWrap = document.getElementById('evappFlowMetricsCharts');

        const sentEl = document.getElementById('evappFlowMetricSent');
        const deliveredEl = document.getElementById('evappFlowMetricDelivered');
        const readEl = document.getElementById('evappFlowMetricRead');
        const answeredEl = document.getElementById('evappFlowMetricAnswered');

        const deliveryRateEl = document.getElementById('evappFlowMetricDeliveryRate');
        const readRateEl = document.getElementById('evappFlowMetricReadRate');
        const answerRateEl = document.getElementById('evappFlowMetricAnswerRate');

        const deliveryBarEl = document.getElementById('evappFlowMetricDeliveryBar');
        const readBarEl = document.getElementById('evappFlowMetricReadBar');
        const answerBarEl = document.getElementById('evappFlowMetricAnswerBar');

        const chartPalette = ['#3279bd','#16855b','#6d4bc3','#d97706','#0f9aa8','#d45b69','#52677c','#255f96','#4f8f6e','#8066c7','#b7791f','#3d8792'];

        let chartInstances = [];
        let activeController = null;
        let requestSerial = 0;

        function numberFormat(value){
            const n = Number(value || 0);
            return n.toLocaleString('es-CO');
        }

        function percentFormat(value){
            const n = Number(value || 0);
            return n.toLocaleString('es-CO', { maximumFractionDigits: 2 }) + '%';
        }

        function normalizedPercent(value){
            const n = Number(value || 0);
            if (!Number.isFinite(n)) return 0;
            return Math.max(0, Math.min(100, n));
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
            statusEl.classList.toggle('is-ok', state === 'ok');
            statusEl.classList.toggle('is-error', state === 'error');
        }

        function setLoading(isLoading){
            root.classList.toggle('is-loading', !!isLoading);
            if (refreshBtn) {
                refreshBtn.disabled = !!isLoading;
                const label = refreshBtn.querySelector('span');
                if (label) label.textContent = isLoading ? 'Actualizando…' : 'Actualizar';
            }
        }

        function updateDownloadLink(flowId){
            if (!csvDownload) return;
            flowId = flowId || (flowInput ? flowInput.value : root.getAttribute('data-default-flow-id'));

            if (!flowId || !exportUrlTemplate) {
                csvDownload.setAttribute('href', '#');
                csvDownload.classList.add('is-disabled');
                csvDownload.setAttribute('aria-disabled', 'true');
                return;
            }

            csvDownload.setAttribute('href', exportUrlTemplate.replace('__EVAPP_FLOW_ID__', encodeURIComponent(flowId)));
            csvDownload.classList.remove('is-disabled');
            csvDownload.removeAttribute('aria-disabled');
        }

        function setRateBar(element, value){
            if (!element) return;
            element.style.width = normalizedPercent(value) + '%';
        }

        function renderKpis(counts){
            counts = counts || {};

            if (sentEl) sentEl.textContent = numberFormat(counts.sent);
            if (deliveredEl) deliveredEl.textContent = numberFormat(counts.delivered);
            if (readEl) readEl.textContent = numberFormat(counts.read);
            if (answeredEl) answeredEl.textContent = numberFormat(counts.answered);

            if (deliveryRateEl) deliveryRateEl.textContent = percentFormat(counts.delivery_rate);
            if (readRateEl) readRateEl.textContent = percentFormat(counts.read_rate);
            if (answerRateEl) answerRateEl.textContent = percentFormat(counts.answer_rate);

            setRateBar(deliveryBarEl, counts.delivery_rate);
            setRateBar(readBarEl, counts.read_rate);
            setRateBar(answerBarEl, counts.answer_rate);
        }

        function renderEmpty(message){
            destroyCharts();
            if (!chartsWrap) return;

            chartsWrap.innerHTML =
                '<div class="evapp-flow-metrics-card is-full">'+
                    '<div class="evapp-flow-metrics-empty" role="status">'+
                        '<div class="evapp-flow-metrics-empty-icon" aria-hidden="true">'+
                            '<svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z"></path><path d="M8 10h8M8 14h5"></path></svg>'+
                        '</div>'+
                        '<div><h3>Sin respuestas para graficar</h3><p>'+
                            escapeHtml(message || 'Todavía no hay respuestas con preguntas de selección para esta encuesta.')+
                        '</p></div>'+
                    '</div>'+
                '</div>';
        }

        function chartColors(length){
            const colors = [];
            for (let i = 0; i < length; i++) {
                colors.push(chartPalette[i % chartPalette.length]);
            }
            return colors;
        }

        function renderCharts(questions, emptyMessage){
            destroyCharts();
            if (!chartsWrap) return;
            chartsWrap.innerHTML = '';

            if (!Array.isArray(questions) || !questions.length) {
                renderEmpty(emptyMessage || 'Esta encuesta todavía no tiene respuestas de selección para graficar.');
                return;
            }

            const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            questions.forEach(function(question, index){
                const options = Array.isArray(question.options) ? question.options : [];
                const labels = options.map(function(item){ return item.label || 'Sin etiqueta'; });
                const values = options.map(function(item){ return Number(item.count || 0); });
                const total = values.reduce(function(a,b){ return a + b; }, 0);
                const canvasId = 'evappFlowMetricChart_' + index + '_' + String(question.slug || 'q').replace(/[^a-zA-Z0-9_-]/g, '');

                let rows = '';
                options.forEach(function(item){
                    rows += '<tr>'+
                        '<td>'+escapeHtml(item.label || 'Sin etiqueta')+'</td>'+
                        '<td>'+numberFormat(item.count)+'</td>'+
                        '<td>'+percentFormat(item.percent)+'</td>'+
                    '</tr>';
                });

                if (!rows) {
                    rows = '<tr><td colspan="3">Sin opciones registradas.</td></tr>';
                }

                const card = document.createElement('article');
                card.className = 'evapp-flow-metrics-card';
                card.innerHTML =
                    '<h3>'+escapeHtml(question.label || 'Pregunta')+'</h3>'+
                    '<div class="evapp-flow-metrics-question-meta">'+
                        '<span>Respuestas válidas: '+numberFormat(question.answered_responses)+'</span>'+
                        '<span>Selecciones: '+numberFormat(question.selection_total)+'</span>'+
                    '</div>'+
                    '<div class="evapp-flow-metrics-chart-box"><canvas id="'+escapeHtml(canvasId)+'" aria-label="Gráfico de '+escapeHtml(question.label || 'pregunta')+'"></canvas></div>'+
                    '<div class="evapp-flow-metrics-table-wrap">'+
                        '<table class="evapp-flow-metrics-table">'+
                            '<thead><tr><th scope="col">Opción</th><th scope="col">Número</th><th scope="col">Porcentaje</th></tr></thead>'+
                            '<tbody>'+rows+'</tbody>'+
                        '</table>'+
                    '</div>';

                chartsWrap.appendChild(card);

                const chartBox = card.querySelector('.evapp-flow-metrics-chart-box');

                if (total <= 0) {
                    if (chartBox) {
                        chartBox.innerHTML = '<div class="evapp-flow-metrics-chart-empty">Sin respuestas para esta pregunta.</div>';
                    }
                    return;
                }

                if (!window.Chart) {
                    if (chartBox) {
                        chartBox.innerHTML = '<div class="evapp-flow-metrics-chart-empty">El gráfico no pudo cargarse. La tabla inferior conserva todos los resultados.</div>';
                    }
                    return;
                }

                const ctx = document.getElementById(canvasId);
                if (!ctx) return;

                const useBar = labels.length > 7;
                const colors = chartColors(labels.length);

                const config = {
                    type: useBar ? 'bar' : 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: values,
                            backgroundColor: colors,
                            borderColor: '#ffffff',
                            borderWidth: useBar ? 0 : 2,
                            borderRadius: useBar ? 6 : 0,
                            maxBarThickness: useBar ? 34 : undefined
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: useBar ? 'y' : 'x',
                        animation: {
                            duration: prefersReducedMotion ? 0 : 260
                        },
                        cutout: useBar ? undefined : '62%',
                        plugins: {
                            legend: {
                                display: !useBar,
                                position: 'bottom',
                                labels: {
                                    color: '#475569',
                                    boxWidth: 11,
                                    boxHeight: 11,
                                    padding: 14,
                                    usePointStyle: true,
                                    pointStyle: 'circle',
                                    font: { size: 11, weight: '600' }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context){
                                        const raw = Number(context.parsed && typeof context.parsed === 'object'
                                            ? (useBar ? context.parsed.x : context.parsed.y)
                                            : context.parsed || 0);
                                        const pct = total > 0 ? (raw / total) * 100 : 0;
                                        return ' ' + context.label + ': ' + numberFormat(raw) + ' (' + pct.toLocaleString('es-CO', { maximumFractionDigits: 2 }) + '%)';
                                    }
                                }
                            }
                        },
                        scales: useBar ? {
                            x: {
                                beginAtZero: true,
                                grid: { color: '#edf2f7' },
                                border: { display: false },
                                ticks: { color: '#64748b', precision: 0 }
                            },
                            y: {
                                grid: { display: false },
                                border: { display: false },
                                ticks: {
                                    color: '#475569',
                                    font: { size: 11, weight: '600' }
                                }
                            }
                        } : undefined
                    }
                };

                try {
                    const chart = new Chart(ctx, config);
                    chartInstances.push(chart);
                } catch (error) {
                    if (chartBox) {
                        chartBox.innerHTML = '<div class="evapp-flow-metrics-chart-empty">No fue posible dibujar este gráfico. La tabla inferior conserva todos los resultados.</div>';
                    }
                }
            });
        }

        function parseJsonResponse(resp){
            return resp.text().then(function(text){
                let json = null;
                try {
                    json = JSON.parse(text);
                } catch (e) {
                    throw new Error('El servidor devolvió una respuesta inválida. Recarga la página e intenta nuevamente.');
                }

                if (!resp.ok || !json || !json.success) {
                    const message = json && json.data && json.data.error
                        ? json.data.error
                        : 'No se pudieron cargar las métricas.';
                    throw new Error(message);
                }

                return json.data || {};
            });
        }

        function fetchData(options){
            options = options || {};
            const flowId = flowInput ? flowInput.value : root.getAttribute('data-default-flow-id');

            updateDownloadLink(flowId);

            if (!flowId) {
                renderKpis({});
                renderEmpty('No hay una encuesta configurada para el evento activo.');
                setStatus('Sin encuesta configurada', 'error');
                return;
            }

            if (activeController && typeof activeController.abort === 'function') {
                activeController.abort();
            }

            const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            activeController = controller;
            const serial = ++requestSerial;

            setLoading(true);
            setStatus(options.manual ? 'Actualizando métricas…' : 'Cargando métricas…', 'loading');

            const body = new FormData();
            body.append('action', 'eventosapp_whatsapp_flow_metrics_data');
            body.append('security', nonce);
            body.append('flow_id', flowId);

            const requestOptions = {
                method:'POST',
                credentials:'same-origin',
                body:body
            };
            if (controller) requestOptions.signal = controller.signal;

            fetch(ajaxUrl, requestOptions)
                .then(parseJsonResponse)
                .then(function(data){
                    if (serial !== requestSerial) return;

                    renderKpis(data.counts || {});
                    renderCharts(data.questions || [], data.message || '');

                    const perf = data.performance || {};
                    const processed = Number(perf.processed_responses || 0);
                    let statusText = perf.cached ? 'Métricas cargadas · caché vigente' : 'Métricas actualizadas';
                    if (!perf.cached && processed > 0) {
                        statusText += ' · ' + numberFormat(processed) + ' respuestas procesadas';
                    }
                    setStatus(statusText, 'ok');
                })
                .catch(function(error){
                    if (error && error.name === 'AbortError') return;
                    if (serial !== requestSerial) return;

                    renderKpis({});
                    renderEmpty(error && error.message ? error.message : 'Error al cargar métricas.');
                    setStatus(error && error.message ? error.message : 'Error al cargar métricas.', 'error');
                })
                .finally(function(){
                    if (serial !== requestSerial) return;
                    setLoading(false);
                    if (activeController === controller) {
                        activeController = null;
                    }
                });
        }

        if (flowInput && flowInput.addEventListener) {
            flowInput.addEventListener('change', function(){
                updateDownloadLink(flowInput.value);
                fetchData();
            });
        }

        if (refreshBtn && refreshBtn.addEventListener) {
            refreshBtn.addEventListener('click', function(){
                fetchData({manual:true});
            });
        }

        window.addEventListener('pagehide', function(){
            if (activeController && typeof activeController.abort === 'function') {
                activeController.abort();
            }
            destroyCharts();
        }, {once:true});

        updateDownloadLink(flowInput ? flowInput.value : root.getAttribute('data-default-flow-id'));
        fetchData();
    })();
    </script>
    <?php endif; ?>
    <?php
    return ob_get_clean();
});

