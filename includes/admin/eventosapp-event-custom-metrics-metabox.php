<?php
// includes/admin/eventosapp-event-custom-metrics-metabox.php
if ( ! defined('ABSPATH') ) exit;

/**
 * EventosApp - Métricas personalizadas por evento.
 *
 * Este archivo agrega un nuevo metabox al CPT de eventos para configurar gráficas
 * adicionales que luego se muestran en includes/frontend/eventosapp-frontend-metrics.php.
 *
 * Meta principal del evento:
 * - _eventosapp_custom_metrics_layout
 */

if ( ! defined('EVAPP_CUSTOM_METRICS_META_KEY') ) {
    define('EVAPP_CUSTOM_METRICS_META_KEY', '_eventosapp_custom_metrics_layout');
}

if ( ! function_exists('eventosapp_custom_metrics_chart_type_options') ) {
    function eventosapp_custom_metrics_chart_type_options(){
        return [
            'table'       => 'Tabla',
            'number_card' => 'Tarjeta de gráfico',
            'column'      => 'Columnas',
            'pie'         => 'Torta',
        ];
    }
}

if ( ! function_exists('eventosapp_custom_metrics_aggregation_options') ) {
    function eventosapp_custom_metrics_aggregation_options(){
        return [
            'count' => 'Contar tickets',
            'sum'   => 'Sumar campo numérico',
            'avg'   => 'Promediar campo numérico',
        ];
    }
}

if ( ! function_exists('eventosapp_custom_metrics_sort_options') ) {
    function eventosapp_custom_metrics_sort_options(){
        return [
            'value_desc' => 'Valor mayor a menor',
            'value_asc'  => 'Valor menor a mayor',
            'label_asc'  => 'Etiqueta A → Z',
            'label_desc' => 'Etiqueta Z → A',
        ];
    }
}

if ( ! function_exists('eventosapp_custom_metrics_default_slot') ) {
    function eventosapp_custom_metrics_default_slot(){
        return [
            'enabled'          => false,
            'title'            => '',
            'chart_type'       => 'column',
            'span'             => 1,
            'label_field'      => 'localidad',
            'value_field'      => '',
            'aggregation'      => 'count',
            'sort_by'          => 'value_desc',
            'limit'            => 10,
            'show_legend'      => true,
            'show_data_labels' => false,
            'table_fields'     => ['nombre', 'apellido', 'email', 'localidad', 'checked_in_any'],
        ];
    }
}

if ( ! function_exists('eventosapp_custom_metrics_default_layout') ) {
    function eventosapp_custom_metrics_default_layout(){
        return [
            'rows' => [
                [
                    'slots' => [
                        eventosapp_custom_metrics_default_slot(),
                        eventosapp_custom_metrics_default_slot(),
                    ],
                ],
            ],
        ];
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_available_fields') ) {
    function eventosapp_custom_metrics_get_available_fields($event_id){
        $event_id = (int) $event_id;

        $fields = [
            [
                'key'      => 'ticket_public_id',
                'label'    => 'Ticket Public ID',
                'type'     => 'text',
                'source'   => 'system',
                'meta_key' => 'eventosapp_ticketID',
            ],
            [
                'key'      => 'ticket_post_id',
                'label'    => 'Ticket Post ID',
                'type'     => 'number',
                'source'   => 'computed',
            ],
            [
                'key'      => 'secuencia',
                'label'    => 'Secuencia interna',
                'type'     => 'number',
                'source'   => 'system',
                'meta_key' => '_eventosapp_ticket_seq',
            ],
            [
                'key'      => 'nombre',
                'label'    => 'Nombre',
                'type'     => 'text',
                'source'   => 'system',
                'meta_key' => '_eventosapp_asistente_nombre',
            ],
            [
                'key'      => 'apellido',
                'label'    => 'Apellido',
                'type'     => 'text',
                'source'   => 'system',
                'meta_key' => '_eventosapp_asistente_apellido',
            ],
            [
                'key'      => 'cc',
                'label'    => 'CC',
                'type'     => 'text',
                'source'   => 'system',
                'meta_key' => '_eventosapp_asistente_cc',
            ],
            [
                'key'      => 'email',
                'label'    => 'Email',
                'type'     => 'text',
                'source'   => 'system',
                'meta_key' => '_eventosapp_asistente_email',
            ],
            [
                'key'      => 'telefono',
                'label'    => 'Teléfono',
                'type'     => 'text',
                'source'   => 'system',
                'meta_key' => '_eventosapp_asistente_tel',
            ],
            [
                'key'      => 'empresa',
                'label'    => 'Empresa',
                'type'     => 'text',
                'source'   => 'system',
                'meta_key' => '_eventosapp_asistente_empresa',
            ],
            [
                'key'      => 'nit',
                'label'    => 'NIT',
                'type'     => 'text',
                'source'   => 'system',
                'meta_key' => '_eventosapp_asistente_nit',
            ],
            [
                'key'      => 'cargo',
                'label'    => 'Cargo',
                'type'     => 'text',
                'source'   => 'system',
                'meta_key' => '_eventosapp_asistente_cargo',
            ],
            [
                'key'      => 'ciudad',
                'label'    => 'Ciudad',
                'type'     => 'text',
                'source'   => 'system',
                'meta_key' => '_eventosapp_asistente_ciudad',
            ],
            [
                'key'      => 'pais',
                'label'    => 'País',
                'type'     => 'text',
                'source'   => 'system',
                'meta_key' => '_eventosapp_asistente_pais',
            ],
            [
                'key'      => 'localidad',
                'label'    => 'Localidad',
                'type'     => 'text',
                'source'   => 'system',
                'meta_key' => '_eventosapp_asistente_localidad',
            ],
            [
                'key'      => 'estado_pago',
                'label'    => 'Estado de pago',
                'type'     => 'text',
                'source'   => 'system',
                'meta_key' => '_eventosapp_estado_pago',
            ],
            [
                'key'      => 'checked_in_any',
                'label'    => 'Checked-In (algún día)',
                'type'     => 'text',
                'source'   => 'computed',
            ],
            [
                'key'      => 'medio_checkin',
                'label'    => 'Medio de check-in',
                'type'     => 'text',
                'source'   => 'computed',
            ],
            [
                'key'      => 'acompanantes_sin_qr',
                'label'    => 'Acompañantes sin QR',
                'type'     => 'number',
                'source'   => 'system',
                'meta_key' => '_eventosapp_ticket_acompanantes_sin_qr',
            ],
            [
                'key'      => 'fecha_creacion',
                'label'    => 'Fecha creación',
                'type'     => 'date',
                'source'   => 'computed',
            ],
        ];

        if ( function_exists('eventosapp_get_event_extra_fields') && $event_id > 0 ) {
            $extras = eventosapp_get_event_extra_fields($event_id);
            if ( is_array($extras) ) {
                foreach ( $extras as $extra ) {
                    $extra_key = isset($extra['key']) ? sanitize_key($extra['key']) : '';
                    if ( $extra_key === '' ) continue;

                    $extra_type = isset($extra['type']) ? (string) $extra['type'] : 'text';
                    $fields[] = [
                        'key'       => 'extra_' . $extra_key,
                        'label'     => 'Extra: ' . ( isset($extra['label']) ? sanitize_text_field($extra['label']) : $extra_key ),
                        'type'      => $extra_type === 'number' ? 'number' : 'text',
                        'source'    => 'extra',
                        'extra_key' => $extra_key,
                        'meta_key'  => '_eventosapp_extra_' . $extra_key,
                    ];
                }
            }
        }

        $used = [];
        $out  = [];
        foreach ( $fields as $field ) {
            $key = sanitize_key( isset($field['key']) ? $field['key'] : '' );
            if ( $key === '' || isset($used[$key]) ) continue;
            $field['key'] = $key;
            $field['label'] = isset($field['label']) ? sanitize_text_field($field['label']) : $key;
            $field['type'] = isset($field['type']) && in_array($field['type'], ['text','number','date'], true) ? $field['type'] : 'text';
            $out[] = $field;
            $used[$key] = true;
        }

        return $out;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_field_map') ) {
    function eventosapp_custom_metrics_get_field_map($event_id){
        $map = [];
        foreach ( eventosapp_custom_metrics_get_available_fields($event_id) as $field ) {
            $map[$field['key']] = $field;
        }
        return $map;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_sanitize_slot') ) {
    function eventosapp_custom_metrics_sanitize_slot($slot){
        $default = eventosapp_custom_metrics_default_slot();
        $slot = is_array($slot) ? array_merge($default, $slot) : $default;

        $chart_types = array_keys(eventosapp_custom_metrics_chart_type_options());
        $aggr_types  = array_keys(eventosapp_custom_metrics_aggregation_options());
        $sort_types  = array_keys(eventosapp_custom_metrics_sort_options());

        $out = [];
        $out['enabled']          = ! empty($slot['enabled']);
        $out['title']            = sanitize_text_field( isset($slot['title']) ? (string) $slot['title'] : '' );
        $out['chart_type']       = in_array($slot['chart_type'], $chart_types, true) ? $slot['chart_type'] : $default['chart_type'];
        $out['span']             = (int) $slot['span'] === 2 ? 2 : 1;
        $out['label_field']      = sanitize_key( isset($slot['label_field']) ? (string) $slot['label_field'] : '' );
        $out['value_field']      = sanitize_key( isset($slot['value_field']) ? (string) $slot['value_field'] : '' );
        $out['aggregation']      = in_array($slot['aggregation'], $aggr_types, true) ? $slot['aggregation'] : $default['aggregation'];
        $out['sort_by']          = in_array($slot['sort_by'], $sort_types, true) ? $slot['sort_by'] : $default['sort_by'];
        $out['limit']            = max(1, min(500, (int) $slot['limit']));
        $out['show_legend']      = ! empty($slot['show_legend']);
        $out['show_data_labels'] = ! empty($slot['show_data_labels']);
        $out['table_fields']     = [];

        if ( isset($slot['table_fields']) && is_array($slot['table_fields']) ) {
            foreach ( $slot['table_fields'] as $field_key ) {
                $field_key = sanitize_key((string) $field_key);
                if ( $field_key !== '' && ! in_array($field_key, $out['table_fields'], true) ) {
                    $out['table_fields'][] = $field_key;
                }
            }
        }

        if ( empty($out['table_fields']) ) {
            $out['table_fields'] = $default['table_fields'];
        }

        return $out;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_sanitize_layout_config') ) {
    function eventosapp_custom_metrics_sanitize_layout_config($layout){
        if ( ! is_array($layout) || empty($layout['rows']) || ! is_array($layout['rows']) ) {
            return eventosapp_custom_metrics_default_layout();
        }

        $out = ['rows' => []];
        foreach ( $layout['rows'] as $row ) {
            $slots_raw = isset($row['slots']) && is_array($row['slots']) ? array_values($row['slots']) : [];
            $slot_1 = eventosapp_custom_metrics_sanitize_slot( isset($slots_raw[0]) ? $slots_raw[0] : [] );
            $slot_2 = eventosapp_custom_metrics_sanitize_slot( isset($slots_raw[1]) ? $slots_raw[1] : [] );
            $out['rows'][] = ['slots' => [$slot_1, $slot_2]];
        }

        if ( empty($out['rows']) ) {
            $out = eventosapp_custom_metrics_default_layout();
        }

        return $out;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_layout_config') ) {
    function eventosapp_custom_metrics_get_layout_config($event_id){
        $raw = get_post_meta((int) $event_id, EVAPP_CUSTOM_METRICS_META_KEY, true);
        if ( ! is_array($raw) ) {
            $raw = [];
        }
        return eventosapp_custom_metrics_sanitize_layout_config($raw);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_normalize_display_value') ) {
    function eventosapp_custom_metrics_normalize_display_value($value){
        if ( is_array($value) ) {
            $flat = [];
            array_walk_recursive($value, function($v) use (&$flat){
                if ( is_scalar($v) ) $flat[] = (string) $v;
            });
            return implode(', ', $flat);
        }
        if ( is_bool($value) ) return $value ? 'Sí' : 'No';
        if ( $value === null ) return '';
        return trim(wp_strip_all_tags((string) $value));
    }
}

if ( ! function_exists('eventosapp_custom_metrics_normalize_dimension_value') ) {
    function eventosapp_custom_metrics_normalize_dimension_value($value){
        $value = eventosapp_custom_metrics_normalize_display_value($value);
        return $value !== '' ? $value : 'Sin dato';
    }
}

if ( ! function_exists('eventosapp_custom_metrics_normalize_numeric_value') ) {
    function eventosapp_custom_metrics_normalize_numeric_value($value){
        if ( is_int($value) || is_float($value) ) {
            return (float) $value;
        }
        if ( is_array($value) || is_object($value) ) {
            return null;
        }

        $value = trim(wp_strip_all_tags((string) $value));
        if ( $value === '' ) return null;

        $value = preg_replace('/[^0-9,\.\-]/', '', $value);
        if ( $value === '' || $value === '-' ) return null;

        $last_comma = strrpos($value, ',');
        $last_dot   = strrpos($value, '.');

        if ( $last_comma !== false && $last_dot !== false ) {
            if ( $last_comma > $last_dot ) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ( $last_comma !== false ) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_ticket_checked_in_any') ) {
    function eventosapp_custom_metrics_ticket_checked_in_any($ticket_id){
        $status_arr = get_post_meta($ticket_id, '_eventosapp_checkin_status', true);
        if ( is_string($status_arr) ) {
            $maybe = @unserialize($status_arr);
            if ( $maybe !== false || $status_arr === 'b:0;' ) $status_arr = $maybe;
        }
        if ( ! is_array($status_arr) ) $status_arr = [];
        return ( in_array('checked_in', $status_arr, true) || in_array('checked-in', $status_arr, true) ) ? 'Sí' : 'No';
    }
}

if ( ! function_exists('eventosapp_custom_metrics_ticket_first_qr_label') ) {
    function eventosapp_custom_metrics_ticket_first_qr_label($ticket_id){
        $log = get_post_meta($ticket_id, '_eventosapp_checkin_log', true);
        if ( is_string($log) ) {
            $maybe = @unserialize($log);
            if ( $maybe !== false || $log === 'b:0;' ) $log = $maybe;
        }
        if ( ! is_array($log) ) $log = [];

        foreach ( $log as $entry ) {
            if ( ! is_array($entry) ) continue;
            $status = isset($entry['status']) ? (string) $entry['status'] : '';
            if ( $status === 'checked_in' || $status === 'checked-in' ) {
                if ( isset($entry['qr_type_label']) && $entry['qr_type_label'] !== '' ) {
                    return sanitize_text_field($entry['qr_type_label']);
                }
                if ( isset($entry['qr_type']) && $entry['qr_type'] !== '' ) {
                    return sanitize_text_field($entry['qr_type']);
                }
                return 'Sin clasificar';
            }
        }

        return '';
    }
}

if ( ! function_exists('eventosapp_custom_metrics_extract_ticket_value') ) {
    function eventosapp_custom_metrics_extract_ticket_value($ticket_id, $event_id, $field){
        $key = isset($field['key']) ? (string) $field['key'] : '';

        if ( $key === 'ticket_post_id' ) {
            return (string) $ticket_id;
        }
        if ( $key === 'checked_in_any' ) {
            return eventosapp_custom_metrics_ticket_checked_in_any($ticket_id);
        }
        if ( $key === 'medio_checkin' ) {
            return eventosapp_custom_metrics_ticket_first_qr_label($ticket_id);
        }
        if ( $key === 'fecha_creacion' ) {
            return get_post_time('Y-m-d H:i:s', false, $ticket_id);
        }

        $meta_key = isset($field['meta_key']) ? (string) $field['meta_key'] : '';
        if ( $meta_key !== '' ) {
            return get_post_meta($ticket_id, $meta_key, true);
        }

        return '';
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_ticket_records') ) {
    function eventosapp_custom_metrics_get_ticket_records($event_id){
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) return [];

        $fields = eventosapp_custom_metrics_get_available_fields($event_id);
        $query = new WP_Query([
            'post_type'      => 'eventosapp_ticket',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_eventosapp_ticket_evento_id',
                    'value'   => $event_id,
                    'compare' => '=',
                ],
            ],
        ]);

        $records = [];
        foreach ( (array) $query->posts as $ticket_id ) {
            $record = [];
            foreach ( $fields as $field ) {
                $record[$field['key']] = eventosapp_custom_metrics_extract_ticket_value($ticket_id, $event_id, $field);
            }
            $records[] = $record;
        }

        return $records;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_sort_bucket_rows') ) {
    function eventosapp_custom_metrics_sort_bucket_rows($rows, $sort_by){
        usort($rows, function($a, $b) use ($sort_by){
            $av = isset($a['sort']) ? (float) $a['sort'] : 0;
            $bv = isset($b['sort']) ? (float) $b['sort'] : 0;
            $al = isset($a['label']) ? (string) $a['label'] : '';
            $bl = isset($b['label']) ? (string) $b['label'] : '';

            if ( $sort_by === 'value_asc' ) return $av <=> $bv;
            if ( $sort_by === 'label_asc' ) return strnatcasecmp($al, $bl);
            if ( $sort_by === 'label_desc' ) return strnatcasecmp($bl, $al);
            return $bv <=> $av;
        });
        return $rows;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_build_slot_payload') ) {
    function eventosapp_custom_metrics_build_slot_payload($event_id, $slot, $records, $field_map, $slot_index){
        if ( empty($slot['enabled']) ) {
            return ['empty' => true];
        }

        $chart_type = isset($slot['chart_type']) ? (string) $slot['chart_type'] : 'column';
        $title      = isset($slot['title']) && $slot['title'] !== '' ? (string) $slot['title'] : 'Métrica personalizada';
        $limit      = isset($slot['limit']) ? max(1, min(500, (int) $slot['limit'])) : 10;
        $aggr       = isset($slot['aggregation']) ? (string) $slot['aggregation'] : 'count';
        $label_key  = isset($slot['label_field']) ? sanitize_key($slot['label_field']) : '';
        $value_key  = isset($slot['value_field']) ? sanitize_key($slot['value_field']) : '';
        $sort_by    = isset($slot['sort_by']) ? (string) $slot['sort_by'] : 'value_desc';

        $base_payload = [
            'id'               => 'evapp_custom_metric_' . (int) $event_id . '_' . (int) $slot_index,
            'title'            => $title,
            'chart_type'       => $chart_type,
            'span'             => isset($slot['span']) && (int) $slot['span'] === 2 ? 2 : 1,
            'show_legend'      => ! empty($slot['show_legend']),
            'show_data_labels' => ! empty($slot['show_data_labels']),
            'filtered_count'   => count($records),
            'empty'            => false,
        ];

        if ( empty($records) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'Este evento no tiene tickets para graficar.';
            return $base_payload;
        }

        if ( $chart_type === 'table' ) {
            $table_fields = isset($slot['table_fields']) && is_array($slot['table_fields']) ? $slot['table_fields'] : [];
            if ( empty($table_fields) ) {
                $table_fields = ['nombre', 'apellido', 'email', 'localidad', 'checked_in_any'];
            }

            $columns = [];
            $valid_fields = [];
            foreach ( $table_fields as $field_key ) {
                $field_key = sanitize_key((string) $field_key);
                if ( isset($field_map[$field_key]) ) {
                    $valid_fields[] = $field_key;
                    $columns[] = $field_map[$field_key]['label'];
                }
            }

            if ( empty($valid_fields) ) {
                $base_payload['empty'] = true;
                $base_payload['message'] = 'La tabla no tiene campos válidos seleccionados.';
                return $base_payload;
            }

            $rows = [];
            foreach ( $records as $record ) {
                $line = [];
                foreach ( $valid_fields as $field_key ) {
                    $line[] = eventosapp_custom_metrics_normalize_display_value( isset($record[$field_key]) ? $record[$field_key] : '' );
                }
                $rows[] = $line;
                if ( count($rows) >= $limit ) break;
            }

            return array_merge($base_payload, [
                'columns' => $columns,
                'rows'    => $rows,
                'empty'   => empty($rows),
            ]);
        }

        if ( $chart_type === 'number_card' ) {
            $sum = 0.0;
            $count = 0;

            foreach ( $records as $record ) {
                if ( $aggr === 'count' ) {
                    $count++;
                    continue;
                }
                $num = eventosapp_custom_metrics_normalize_numeric_value( isset($record[$value_key]) ? $record[$value_key] : null );
                if ( $num === null ) continue;
                $sum += $num;
                $count++;
            }

            $metric_value = 0;
            if ( $aggr === 'count' ) {
                $metric_value = $count;
            } elseif ( $aggr === 'sum' ) {
                $metric_value = $sum;
            } elseif ( $aggr === 'avg' ) {
                $metric_value = $count > 0 ? ( $sum / $count ) : 0;
            }

            $metric_label = 'Cantidad';
            if ( $aggr !== 'count' && isset($field_map[$value_key]) ) {
                $metric_label = $field_map[$value_key]['label'];
            }

            return array_merge($base_payload, [
                'metric_value' => $metric_value,
                'metric_label' => $metric_label,
            ]);
        }

        if ( ! isset($field_map[$label_key]) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'Debes elegir un campo de etiqueta válido.';
            return $base_payload;
        }
        if ( $aggr !== 'count' && ! isset($field_map[$value_key]) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'La agregación elegida requiere un campo numérico válido.';
            return $base_payload;
        }

        $bucket = [];
        foreach ( $records as $record ) {
            $label = eventosapp_custom_metrics_normalize_dimension_value( isset($record[$label_key]) ? $record[$label_key] : '' );
            if ( ! isset($bucket[$label]) ) {
                $bucket[$label] = ['sum' => 0.0, 'count' => 0];
            }

            if ( $aggr === 'count' ) {
                $bucket[$label]['count']++;
            } else {
                $num = eventosapp_custom_metrics_normalize_numeric_value( isset($record[$value_key]) ? $record[$value_key] : null );
                if ( $num === null ) continue;
                $bucket[$label]['sum'] += $num;
                $bucket[$label]['count']++;
            }
        }

        $rows = [];
        foreach ( $bucket as $label => $data ) {
            $value = 0;
            if ( $aggr === 'count' ) {
                $value = (int) $data['count'];
            } elseif ( $aggr === 'sum' ) {
                $value = (float) $data['sum'];
            } elseif ( $aggr === 'avg' ) {
                $value = $data['count'] > 0 ? (float) ( $data['sum'] / $data['count'] ) : 0;
            }
            $rows[] = ['label' => $label, 'value' => $value, 'sort' => (float) $value];
        }

        $rows = eventosapp_custom_metrics_sort_bucket_rows($rows, $sort_by);
        $rows = array_slice($rows, 0, $limit);

        $labels = [];
        $values = [];
        foreach ( $rows as $row ) {
            $labels[] = $row['label'];
            $values[] = $row['value'];
        }

        return array_merge($base_payload, [
            'labels'      => $labels,
            'values'      => $values,
            'label_title' => isset($field_map[$label_key]) ? $field_map[$label_key]['label'] : 'Etiqueta',
            'value_title' => $aggr === 'count' ? 'Cantidad' : ( isset($field_map[$value_key]) ? $field_map[$value_key]['label'] : 'Valor' ),
            'empty'       => empty($labels),
        ]);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_payload') ) {
    function eventosapp_custom_metrics_get_payload($event_id){
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) return ['rows' => [], 'has_metrics' => false];

        $layout = eventosapp_custom_metrics_get_layout_config($event_id);
        $field_map = eventosapp_custom_metrics_get_field_map($event_id);
        $records = null;
        $rows_out = [];
        $slot_index = 0;
        $has_metrics = false;

        foreach ( (array) $layout['rows'] as $row ) {
            $row_slots = [];
            foreach ( (array) $row['slots'] as $slot ) {
                $slot_index++;
                if ( empty($slot['enabled']) ) continue;
                if ( $records === null ) {
                    $records = eventosapp_custom_metrics_get_ticket_records($event_id);
                }
                $payload = eventosapp_custom_metrics_build_slot_payload($event_id, $slot, $records, $field_map, $slot_index);
                if ( ! empty($payload) && empty($payload['empty']) || ( ! empty($payload) && ! empty($slot['enabled']) ) ) {
                    $row_slots[] = $payload;
                    $has_metrics = true;
                }
            }
            if ( ! empty($row_slots) ) {
                $rows_out[] = ['slots' => $row_slots];
            }
        }

        return [
            'rows'        => $rows_out,
            'has_metrics' => $has_metrics,
        ];
    }
}

add_action('add_meta_boxes', function(){
    $post_types = [];
    if ( post_type_exists('eventosapp_event') ) $post_types[] = 'eventosapp_event';
    if ( post_type_exists('eventosapp_events') ) $post_types[] = 'eventosapp_events';
    if ( empty($post_types) ) $post_types[] = 'eventosapp_event';

    foreach ( array_unique($post_types) as $post_type ) {
        add_meta_box(
            'eventosapp_custom_metrics_builder',
            'Métricas personalizadas del evento',
            'eventosapp_custom_metrics_render_metabox',
            $post_type,
            'normal',
            'default'
        );
    }
});

if ( ! function_exists('eventosapp_custom_metrics_render_metabox') ) {
    function eventosapp_custom_metrics_render_metabox($post){
        wp_nonce_field('eventosapp_custom_metrics_save', 'eventosapp_custom_metrics_nonce');

        $layout = eventosapp_custom_metrics_get_layout_config($post->ID);
        $fields = eventosapp_custom_metrics_get_available_fields($post->ID);
        $chart_types = eventosapp_custom_metrics_chart_type_options();
        $aggregations = eventosapp_custom_metrics_aggregation_options();
        $sort_options = eventosapp_custom_metrics_sort_options();
        ?>
        <style>
            .evapp-cmetrics-wrap{border:1px solid #dcdcde;border-radius:12px;background:#fff;padding:14px;margin-top:6px}
            .evapp-cmetrics-help{margin:0 0 14px;color:#50575e;line-height:1.45}
            .evapp-cmetrics-row{border:1px solid #e5e7eb;border-radius:12px;background:#f8fafc;margin:0 0 14px;padding:12px}
            .evapp-cmetrics-row-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
            .evapp-cmetrics-row-title{font-weight:700;color:#1d2327}
            .evapp-cmetrics-slots{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
            .evapp-cmetrics-slot{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;box-shadow:0 1px 1px rgba(0,0,0,.03)}
            .evapp-cmetrics-slot.is-disabled{opacity:.62}
            .evapp-cmetrics-slot h4{margin:0 0 10px;font-size:14px;color:#1f2937}
            .evapp-cmetrics-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 12px}
            .evapp-cmetrics-field{display:flex;flex-direction:column;gap:4px}
            .evapp-cmetrics-field label{font-weight:600;color:#374151}
            .evapp-cmetrics-field input[type="text"],
            .evapp-cmetrics-field input[type="number"],
            .evapp-cmetrics-field select{width:100%;max-width:100%}
            .evapp-cmetrics-field select[multiple]{min-height:126px}
            .evapp-cmetrics-checks{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:8px}
            .evapp-cmetrics-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}
            .evapp-cmetrics-note{font-size:12px;color:#646970;margin-top:4px}
            @media(max-width:900px){.evapp-cmetrics-slots,.evapp-cmetrics-grid{grid-template-columns:1fr}}
        </style>

        <div class="evapp-cmetrics-wrap" id="evappCustomMetricsBuilder">
            <p class="evapp-cmetrics-help">
                Configura aquí las métricas adicionales para este evento. Cada fila puede tener uno o dos bloques. Los campos disponibles incluyen los datos base del ticket y los campos adicionales definidos para este evento.
            </p>
            <input type="hidden" id="evapp_custom_metrics_json" name="evapp_custom_metrics_json" value="<?php echo esc_attr(wp_json_encode($layout)); ?>">
            <div id="evapp-cmetrics-rows"></div>
            <div class="evapp-cmetrics-actions">
                <button type="button" class="button button-primary" id="evapp-cmetrics-add-row">Agregar fila</button>
                <span class="evapp-cmetrics-note">Guarda o actualiza el evento para conservar los cambios.</span>
            </div>
        </div>

        <script>
        (function($){
            const fields = <?php echo wp_json_encode(array_values($fields)); ?>;
            const chartTypes = <?php echo wp_json_encode($chart_types); ?>;
            const aggregations = <?php echo wp_json_encode($aggregations); ?>;
            const sortOptions = <?php echo wp_json_encode($sort_options); ?>;
            let state = <?php echo wp_json_encode($layout); ?>;

            function defaultSlot(){
                return {
                    enabled:false,
                    title:'',
                    chart_type:'column',
                    span:1,
                    label_field:'localidad',
                    value_field:'',
                    aggregation:'count',
                    sort_by:'value_desc',
                    limit:10,
                    show_legend:true,
                    show_data_labels:false,
                    table_fields:['nombre','apellido','email','localidad','checked_in_any']
                };
            }

            function normalizeState(){
                if (!state || !Array.isArray(state.rows)) state = {rows:[]};
                if (!state.rows.length) state.rows.push({slots:[defaultSlot(), defaultSlot()]});
                state.rows = state.rows.map(function(row){
                    let slots = row && Array.isArray(row.slots) ? row.slots : [];
                    if (!slots[0]) slots[0] = defaultSlot();
                    if (!slots[1]) slots[1] = defaultSlot();
                    return {slots:[Object.assign(defaultSlot(), slots[0]), Object.assign(defaultSlot(), slots[1])]};
                });
            }

            function esc(s){
                return String(s == null ? '' : s)
                    .replace(/&/g,'&amp;')
                    .replace(/</g,'&lt;')
                    .replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;')
                    .replace(/'/g,'&#039;');
            }

            function optionsFromMap(map, selected){
                let html = '';
                Object.keys(map).forEach(function(key){
                    html += '<option value="'+esc(key)+'" '+(String(selected)===String(key)?'selected':'')+'>'+esc(map[key])+'</option>';
                });
                return html;
            }

            function fieldOptions(selected, emptyLabel){
                let html = emptyLabel ? '<option value="">'+esc(emptyLabel)+'</option>' : '';
                fields.forEach(function(f){
                    html += '<option value="'+esc(f.key)+'" '+(String(selected)===String(f.key)?'selected':'')+'>'+esc(f.label)+'</option>';
                });
                return html;
            }

            function tableFieldOptions(selectedList){
                selectedList = Array.isArray(selectedList) ? selectedList : [];
                let html = '';
                fields.forEach(function(f){
                    html += '<option value="'+esc(f.key)+'" '+(selectedList.indexOf(f.key)!==-1?'selected':'')+'>'+esc(f.label)+'</option>';
                });
                return html;
            }

            function syncHidden(){
                $('#evapp_custom_metrics_json').val(JSON.stringify(state));
            }

            function render(){
                normalizeState();
                const $rows = $('#evapp-cmetrics-rows').empty();
                state.rows.forEach(function(row, rIndex){
                    let rowHtml = '<div class="evapp-cmetrics-row" data-row="'+rIndex+'">'
                        + '<div class="evapp-cmetrics-row-head">'
                        + '<div class="evapp-cmetrics-row-title">Fila '+(rIndex+1)+'</div>'
                        + '<button type="button" class="button evapp-cmetrics-remove-row">Eliminar fila</button>'
                        + '</div>'
                        + '<div class="evapp-cmetrics-slots">';

                    row.slots.forEach(function(slot, sIndex){
                        rowHtml += renderSlot(rIndex, sIndex, slot);
                    });

                    rowHtml += '</div></div>';
                    $rows.append(rowHtml);
                });
                syncHidden();
                refreshSlotVisibility();
            }

            function renderSlot(rIndex, sIndex, slot){
                const disabledClass = slot.enabled ? '' : ' is-disabled';
                return '<div class="evapp-cmetrics-slot'+disabledClass+'" data-row="'+rIndex+'" data-slot="'+sIndex+'">'
                    + '<h4>Gráfico '+(sIndex+1)+'</h4>'
                    + '<div class="evapp-cmetrics-checks">'
                    + '<label><input type="checkbox" class="evapp-cmetrics-control" data-prop="enabled" '+(slot.enabled?'checked':'')+'> Activar este bloque</label>'
                    + '<label><input type="checkbox" class="evapp-cmetrics-control" data-prop="show_legend" '+(slot.show_legend?'checked':'')+'> Mostrar leyenda</label>'
                    + '<label><input type="checkbox" class="evapp-cmetrics-control" data-prop="show_data_labels" '+(slot.show_data_labels?'checked':'')+'> Mostrar valores</label>'
                    + '</div>'
                    + '<div class="evapp-cmetrics-grid" style="margin-top:10px">'
                    + '<div class="evapp-cmetrics-field"><label>Título</label><input type="text" class="evapp-cmetrics-control" data-prop="title" value="'+esc(slot.title)+'"></div>'
                    + '<div class="evapp-cmetrics-field"><label>Tipo</label><select class="evapp-cmetrics-control evapp-cmetrics-type" data-prop="chart_type">'+optionsFromMap(chartTypes, slot.chart_type)+'</select></div>'
                    + '<div class="evapp-cmetrics-field"><label>Ancho en la fila</label><select class="evapp-cmetrics-control" data-prop="span"><option value="1" '+(parseInt(slot.span,10)!==2?'selected':'')+'>Mitad de fila</option><option value="2" '+(parseInt(slot.span,10)===2?'selected':'')+'>Fila completa</option></select></div>'
                    + '<div class="evapp-cmetrics-field"><label>Límite de resultados</label><input type="number" min="1" max="500" class="evapp-cmetrics-control" data-prop="limit" value="'+esc(slot.limit || 10)+'"></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-label"><label>Campo de etiqueta / agrupación</label><select class="evapp-cmetrics-control" data-prop="label_field">'+fieldOptions(slot.label_field, 'Selecciona campo')+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-value"><label>Campo numérico</label><select class="evapp-cmetrics-control" data-prop="value_field">'+fieldOptions(slot.value_field, 'No aplica / selecciona campo')+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-aggregation"><label>Cálculo</label><select class="evapp-cmetrics-control" data-prop="aggregation">'+optionsFromMap(aggregations, slot.aggregation)+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-sort"><label>Orden</label><select class="evapp-cmetrics-control" data-prop="sort_by">'+optionsFromMap(sortOptions, slot.sort_by)+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-table" style="grid-column:1/-1"><label>Campos de la tabla</label><select multiple class="evapp-cmetrics-control" data-prop="table_fields">'+tableFieldOptions(slot.table_fields)+'</select><span class="evapp-cmetrics-note">Mantén presionada la tecla Cmd/Ctrl para seleccionar varios campos.</span></div>'
                    + '</div>'
                    + '</div>';
            }

            function refreshSlotVisibility(){
                $('.evapp-cmetrics-slot').each(function(){
                    const $slot = $(this);
                    const type = $slot.find('[data-prop="chart_type"]').val();
                    const aggr = $slot.find('[data-prop="aggregation"]').val();
                    $slot.toggleClass('is-disabled', !$slot.find('[data-prop="enabled"]').is(':checked'));
                    $slot.find('.evapp-cmetrics-field-table').toggle(type === 'table');
                    $slot.find('.evapp-cmetrics-field-label').toggle(type !== 'table' && type !== 'number_card');
                    $slot.find('.evapp-cmetrics-field-sort').toggle(type !== 'table' && type !== 'number_card');
                    $slot.find('.evapp-cmetrics-field-value').toggle(type === 'number_card' || (type !== 'table' && aggr !== 'count'));
                    $slot.find('.evapp-cmetrics-field-aggregation').toggle(type !== 'table');
                });
            }

            $(document).on('change input', '.evapp-cmetrics-control', function(){
                const $control = $(this);
                const $slot = $control.closest('.evapp-cmetrics-slot');
                const rowIndex = parseInt($slot.attr('data-row'), 10);
                const slotIndex = parseInt($slot.attr('data-slot'), 10);
                const prop = $control.attr('data-prop');
                let value;

                if ($control.attr('type') === 'checkbox') {
                    value = $control.is(':checked');
                } else if ($control.is('select') && $control.prop('multiple')) {
                    value = $control.val() || [];
                } else {
                    value = $control.val();
                }

                if (prop === 'span' || prop === 'limit') value = parseInt(value || 0, 10);
                state.rows[rowIndex].slots[slotIndex][prop] = value;
                syncHidden();
                refreshSlotVisibility();
            });

            $('#evapp-cmetrics-add-row').on('click', function(e){
                e.preventDefault();
                normalizeState();
                state.rows.push({slots:[defaultSlot(), defaultSlot()]});
                render();
            });

            $(document).on('click', '.evapp-cmetrics-remove-row', function(e){
                e.preventDefault();
                const rowIndex = parseInt($(this).closest('.evapp-cmetrics-row').attr('data-row'), 10);
                state.rows.splice(rowIndex, 1);
                if (!state.rows.length) state.rows.push({slots:[defaultSlot(), defaultSlot()]});
                render();
            });

            render();
        })(jQuery);
        </script>
        <?php
    }
}

add_action('save_post', function($post_id, $post){
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision($post_id) ) return;
    if ( ! $post || ! in_array($post->post_type, ['eventosapp_event', 'eventosapp_events'], true) ) return;
    if ( ! isset($_POST['eventosapp_custom_metrics_nonce']) || ! wp_verify_nonce($_POST['eventosapp_custom_metrics_nonce'], 'eventosapp_custom_metrics_save') ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;

    $raw = isset($_POST['evapp_custom_metrics_json']) ? wp_unslash($_POST['evapp_custom_metrics_json']) : '';
    $decoded = json_decode((string) $raw, true);
    if ( ! is_array($decoded) ) {
        $decoded = eventosapp_custom_metrics_default_layout();
    }

    $layout = eventosapp_custom_metrics_sanitize_layout_config($decoded);
    update_post_meta($post_id, EVAPP_CUSTOM_METRICS_META_KEY, $layout);
}, 20, 2);
