<?php
// includes/admin/eventosapp-event-custom-metrics-metabox.php
if ( ! defined('ABSPATH') ) exit;

/**
 * EventosApp - Métricas personalizadas por evento.
 *
 * Este archivo crea un metabox independiente para el CPT de eventos y expone
 * funciones helper que son consumidas por includes/frontend/eventosapp-frontend-metrics.php.
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
            'table'       => 'Tabla dinámica',
            'number_card' => 'Tarjeta de gráfico',
            'column'      => 'Columnas',
            'pie'         => 'Torta',
        ];
    }
}

if ( ! function_exists('eventosapp_custom_metrics_aggregation_options') ) {
    function eventosapp_custom_metrics_aggregation_options(){
        return [
            'count' => 'Contar registros',
            'sum'   => 'Sumar valores',
            'avg'   => 'Promediar valores',
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

if ( ! function_exists('eventosapp_custom_metrics_percentage_options') ) {
    function eventosapp_custom_metrics_percentage_options(){
        return [
            'none'   => 'No mostrar porcentajes',
            'total'  => '% sobre total general',
            'row'    => '% sobre total de la fila',
            'column' => '% sobre total de la columna',
        ];
    }
}

if ( ! function_exists('eventosapp_custom_metrics_value_format_options') ) {
    function eventosapp_custom_metrics_value_format_options(){
        return [
            'integer' => 'Número entero',
            'decimal' => 'Número decimal',
            'money'   => 'Moneda / valor',
        ];
    }
}

if ( ! function_exists('eventosapp_custom_metrics_default_slot') ) {
    function eventosapp_custom_metrics_default_slot(){
        return [
            'enabled'              => false,
            'title'                => '',
            'chart_type'           => 'column',
            'span'                 => 1,

            // Campos estructurales según tipo.
            'row_field'            => 'localidad',
            'column_field'         => '',
            'label_field'          => 'localidad', // Compatibilidad con la versión anterior.
            'series_field'         => '',
            'value_field'          => '',

            // Cálculo y visualización.
            'aggregation'          => 'count',
            'sort_by'              => 'value_desc',
            'limit'                => 10,
            'value_format'         => 'integer',
            'percentage_mode'      => 'none',
            'show_percentages'     => false,
            'show_legend'          => true,
            'show_data_labels'     => false,
            'table_include_totals' => true,

            // Compatibilidad con tabla simple anterior.
            'table_fields'         => ['nombre', 'apellido', 'email', 'localidad', 'checked_in_any'],
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
        $pct_types   = array_keys(eventosapp_custom_metrics_percentage_options());
        $fmt_types   = array_keys(eventosapp_custom_metrics_value_format_options());

        $out = [];
        $out['enabled']              = ! empty($slot['enabled']);
        $out['title']                = sanitize_text_field( isset($slot['title']) ? (string) $slot['title'] : '' );
        $out['chart_type']           = in_array($slot['chart_type'], $chart_types, true) ? $slot['chart_type'] : $default['chart_type'];
        $out['span']                 = (int) $slot['span'] === 2 ? 2 : 1;
        $out['row_field']            = sanitize_key( isset($slot['row_field']) ? (string) $slot['row_field'] : (string) $slot['label_field'] );
        $out['column_field']         = sanitize_key( isset($slot['column_field']) ? (string) $slot['column_field'] : '' );
        $out['label_field']          = sanitize_key( isset($slot['label_field']) ? (string) $slot['label_field'] : (string) $out['row_field'] );
        $out['series_field']         = sanitize_key( isset($slot['series_field']) ? (string) $slot['series_field'] : '' );
        $out['value_field']          = sanitize_key( isset($slot['value_field']) ? (string) $slot['value_field'] : '' );
        $out['aggregation']          = in_array($slot['aggregation'], $aggr_types, true) ? $slot['aggregation'] : $default['aggregation'];
        $out['sort_by']              = in_array($slot['sort_by'], $sort_types, true) ? $slot['sort_by'] : $default['sort_by'];
        $out['limit']                = max(1, min(500, (int) $slot['limit']));
        $out['value_format']         = in_array($slot['value_format'], $fmt_types, true) ? $slot['value_format'] : $default['value_format'];
        $out['percentage_mode']      = in_array($slot['percentage_mode'], $pct_types, true) ? $slot['percentage_mode'] : $default['percentage_mode'];
        $out['show_percentages']     = ! empty($slot['show_percentages']);
        $out['show_legend']          = ! empty($slot['show_legend']);
        $out['show_data_labels']     = ! empty($slot['show_data_labels']);
        $out['table_include_totals'] = ! empty($slot['table_include_totals']);
        $out['table_fields']         = [];

        if ( isset($slot['table_fields']) && is_array($slot['table_fields']) ) {
            foreach ( $slot['table_fields'] as $field_key ) {
                $field_key = sanitize_key((string) $field_key);
                if ( $field_key !== '' && ! in_array($field_key, $out['table_fields'], true) ) {
                    $out['table_fields'][] = $field_key;
                }
            }
        }

        if ( empty($out['row_field']) && ! empty($out['label_field']) ) {
            $out['row_field'] = $out['label_field'];
        }
        if ( empty($out['label_field']) && ! empty($out['row_field']) ) {
            $out['label_field'] = $out['row_field'];
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

if ( ! function_exists('eventosapp_custom_metrics_format_number') ) {
    function eventosapp_custom_metrics_format_number($value, $format = 'integer'){
        $value = is_numeric($value) ? (float) $value : 0;
        if ( $format === 'decimal' ) {
            return number_format_i18n($value, 2);
        }
        if ( $format === 'money' ) {
            return '$' . number_format_i18n($value, 2);
        }
        return number_format_i18n($value, 0);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_format_percent') ) {
    function eventosapp_custom_metrics_format_percent($value){
        $value = is_numeric($value) ? (float) $value : 0;
        return number_format_i18n($value, 2) . '%';
    }
}

if ( ! function_exists('eventosapp_custom_metrics_bucket_value') ) {
    function eventosapp_custom_metrics_bucket_value($bucket, $aggr){
        $sum = isset($bucket['sum']) ? (float) $bucket['sum'] : 0.0;
        $count = isset($bucket['count']) ? (int) $bucket['count'] : 0;
        if ( $aggr === 'sum' ) return $sum;
        if ( $aggr === 'avg' ) return $count > 0 ? ( $sum / $count ) : 0;
        return $count;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_add_to_bucket') ) {
    function eventosapp_custom_metrics_add_to_bucket(&$bucket, $record, $value_key, $aggr){
        if ( ! is_array($bucket) ) {
            $bucket = ['sum' => 0.0, 'count' => 0];
        }
        if ( $aggr === 'count' ) {
            $bucket['count']++;
            return;
        }

        $num = eventosapp_custom_metrics_normalize_numeric_value( isset($record[$value_key]) ? $record[$value_key] : null );
        if ( $num === null ) return;
        $bucket['sum'] += $num;
        $bucket['count']++;
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

if ( ! function_exists('eventosapp_custom_metrics_build_table_payload') ) {
    function eventosapp_custom_metrics_build_table_payload($base_payload, $slot, $records, $field_map){
        $row_key = sanitize_key( ! empty($slot['row_field']) ? $slot['row_field'] : $slot['label_field'] );
        $column_key = sanitize_key( isset($slot['column_field']) ? $slot['column_field'] : '' );
        $value_key = sanitize_key( isset($slot['value_field']) ? $slot['value_field'] : '' );
        $aggr = isset($slot['aggregation']) ? (string) $slot['aggregation'] : 'count';
        $limit = isset($slot['limit']) ? max(1, min(500, (int) $slot['limit'])) : 10;
        $format = isset($slot['value_format']) ? (string) $slot['value_format'] : 'integer';
        $percentage_mode = isset($slot['percentage_mode']) ? (string) $slot['percentage_mode'] : 'none';
        $include_totals = ! empty($slot['table_include_totals']);

        if ( ! isset($field_map[$row_key]) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'Debes elegir un campo de fila válido para la tabla.';
            return $base_payload;
        }
        if ( $column_key !== '' && ! isset($field_map[$column_key]) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'Debes elegir un campo de columna válido para la tabla.';
            return $base_payload;
        }
        if ( $aggr !== 'count' && ! isset($field_map[$value_key]) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'La tabla requiere un campo de valor para sumar o promediar.';
            return $base_payload;
        }

        $column_label_without_field = 'Total';
        $bucket = [];
        $row_totals = [];
        $column_totals = [];
        $grand_total = ['sum' => 0.0, 'count' => 0];
        $column_labels = [];

        foreach ( $records as $record ) {
            $row_label = eventosapp_custom_metrics_normalize_dimension_value( isset($record[$row_key]) ? $record[$row_key] : '' );
            $col_label = $column_key !== ''
                ? eventosapp_custom_metrics_normalize_dimension_value( isset($record[$column_key]) ? $record[$column_key] : '' )
                : $column_label_without_field;

            if ( ! isset($bucket[$row_label]) ) $bucket[$row_label] = [];
            if ( ! isset($bucket[$row_label][$col_label]) ) $bucket[$row_label][$col_label] = ['sum' => 0.0, 'count' => 0];
            if ( ! isset($row_totals[$row_label]) ) $row_totals[$row_label] = ['sum' => 0.0, 'count' => 0];
            if ( ! isset($column_totals[$col_label]) ) $column_totals[$col_label] = ['sum' => 0.0, 'count' => 0];

            eventosapp_custom_metrics_add_to_bucket($bucket[$row_label][$col_label], $record, $value_key, $aggr);
            eventosapp_custom_metrics_add_to_bucket($row_totals[$row_label], $record, $value_key, $aggr);
            eventosapp_custom_metrics_add_to_bucket($column_totals[$col_label], $record, $value_key, $aggr);
            eventosapp_custom_metrics_add_to_bucket($grand_total, $record, $value_key, $aggr);
            $column_labels[$col_label] = true;
        }

        $grand_value = eventosapp_custom_metrics_bucket_value($grand_total, $aggr);
        $row_index = [];
        foreach ( $row_totals as $row_label => $total_bucket ) {
            $row_index[] = [
                'label' => $row_label,
                'sort'  => eventosapp_custom_metrics_bucket_value($total_bucket, $aggr),
            ];
        }
        $row_index = eventosapp_custom_metrics_sort_bucket_rows($row_index, isset($slot['sort_by']) ? $slot['sort_by'] : 'value_desc');
        $row_index = array_slice($row_index, 0, $limit);

        $column_names = array_keys($column_labels);
        natcasesort($column_names);
        $column_names = array_values($column_names);

        $calc_pct = function($value, $row_label, $col_label) use ($percentage_mode, $aggr, $row_totals, $column_totals, $grand_value){
            if ( $percentage_mode === 'none' || $aggr === 'avg' ) return null;
            $den = 0;
            if ( $percentage_mode === 'row' && isset($row_totals[$row_label]) ) {
                $den = eventosapp_custom_metrics_bucket_value($row_totals[$row_label], $aggr);
            } elseif ( $percentage_mode === 'column' && isset($column_totals[$col_label]) ) {
                $den = eventosapp_custom_metrics_bucket_value($column_totals[$col_label], $aggr);
            } elseif ( $percentage_mode === 'total' ) {
                $den = $grand_value;
            }
            return $den > 0 ? ( (float) $value * 100 / (float) $den ) : 0;
        };

        $format_cell = function($value, $pct) use ($format){
            $display = eventosapp_custom_metrics_format_number($value, $format);
            if ( $pct !== null ) {
                $display .= ' (' . eventosapp_custom_metrics_format_percent($pct) . ')';
            }
            return $display;
        };

        $columns = [ isset($field_map[$row_key]['label']) ? $field_map[$row_key]['label'] : 'Fila' ];
        foreach ( $column_names as $col_label ) {
            $columns[] = $col_label;
        }
        if ( $include_totals && $column_key !== '' ) {
            $columns[] = 'Total';
        }

        $rows = [];
        foreach ( $row_index as $row_item ) {
            $row_label = $row_item['label'];
            $line = [$row_label];
            foreach ( $column_names as $col_label ) {
                $cell_bucket = isset($bucket[$row_label][$col_label]) ? $bucket[$row_label][$col_label] : ['sum' => 0.0, 'count' => 0];
                $value = eventosapp_custom_metrics_bucket_value($cell_bucket, $aggr);
                $pct = $calc_pct($value, $row_label, $col_label);
                $line[] = $format_cell($value, $pct);
            }
            if ( $include_totals && $column_key !== '' ) {
                $total_value = isset($row_totals[$row_label]) ? eventosapp_custom_metrics_bucket_value($row_totals[$row_label], $aggr) : 0;
                $line[] = eventosapp_custom_metrics_format_number($total_value, $format);
            }
            $rows[] = $line;
        }

        $footer = [];
        if ( $include_totals && ! empty($rows) ) {
            $footer[] = 'Total';
            foreach ( $column_names as $col_label ) {
                $total_value = isset($column_totals[$col_label]) ? eventosapp_custom_metrics_bucket_value($column_totals[$col_label], $aggr) : 0;
                $footer[] = eventosapp_custom_metrics_format_number($total_value, $format);
            }
            if ( $column_key !== '' ) {
                $footer[] = eventosapp_custom_metrics_format_number($grand_value, $format);
            }
        }

        return array_merge($base_payload, [
            'columns'         => $columns,
            'rows'            => $rows,
            'footer'          => $footer,
            'row_title'       => isset($field_map[$row_key]['label']) ? $field_map[$row_key]['label'] : 'Fila',
            'column_title'    => $column_key !== '' && isset($field_map[$column_key]['label']) ? $field_map[$column_key]['label'] : '',
            'value_title'     => $aggr === 'count' ? 'Registros' : ( isset($field_map[$value_key]['label']) ? $field_map[$value_key]['label'] : 'Valor' ),
            'percentage_mode' => $percentage_mode,
            'empty'           => empty($rows),
        ]);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_build_number_card_payload') ) {
    function eventosapp_custom_metrics_build_number_card_payload($base_payload, $slot, $records, $field_map){
        $aggr = isset($slot['aggregation']) ? (string) $slot['aggregation'] : 'count';
        $value_key = sanitize_key( isset($slot['value_field']) ? (string) $slot['value_field'] : '' );
        $format = isset($slot['value_format']) ? (string) $slot['value_format'] : 'integer';

        if ( $aggr !== 'count' && ! isset($field_map[$value_key]) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'La tarjeta requiere un campo de valor para sumar o promediar.';
            return $base_payload;
        }

        $bucket = ['sum' => 0.0, 'count' => 0];
        foreach ( $records as $record ) {
            eventosapp_custom_metrics_add_to_bucket($bucket, $record, $value_key, $aggr);
        }

        $metric_value = eventosapp_custom_metrics_bucket_value($bucket, $aggr);
        $metric_label = $aggr === 'count'
            ? 'Registros'
            : ( isset($field_map[$value_key]['label']) ? $field_map[$value_key]['label'] : 'Valor' );

        return array_merge($base_payload, [
            'metric_value'         => $metric_value,
            'metric_value_display' => eventosapp_custom_metrics_format_number($metric_value, $format),
            'metric_label'         => $metric_label,
            'empty'                => false,
        ]);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_build_chart_payload_common') ) {
    function eventosapp_custom_metrics_build_chart_payload_common($base_payload, $slot, $records, $field_map){
        $chart_type = isset($slot['chart_type']) ? (string) $slot['chart_type'] : 'column';
        $label_key  = sanitize_key( ! empty($slot['label_field']) ? $slot['label_field'] : $slot['row_field'] );
        $series_key = sanitize_key( isset($slot['series_field']) ? (string) $slot['series_field'] : '' );
        $value_key  = sanitize_key( isset($slot['value_field']) ? (string) $slot['value_field'] : '' );
        $sort_by    = isset($slot['sort_by']) ? (string) $slot['sort_by'] : 'value_desc';
        $aggr       = isset($slot['aggregation']) ? (string) $slot['aggregation'] : 'count';
        $limit      = isset($slot['limit']) ? max(1, min(500, (int) $slot['limit'])) : 10;
        $format     = isset($slot['value_format']) ? (string) $slot['value_format'] : 'integer';

        if ( ! isset($field_map[$label_key]) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'Debes elegir un campo de etiqueta válido.';
            return $base_payload;
        }
        if ( $aggr !== 'count' && ! isset($field_map[$value_key]) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'La agregación elegida requiere un campo de valor válido.';
            return $base_payload;
        }
        if ( $chart_type === 'pie' ) {
            $series_key = '';
        }
        if ( $series_key !== '' && ! isset($field_map[$series_key]) ) {
            $series_key = '';
        }

        if ( $series_key !== '' ) {
            $bucket = [];
            $label_totals = [];
            $series_used = [];

            foreach ( $records as $record ) {
                $label = eventosapp_custom_metrics_normalize_dimension_value( isset($record[$label_key]) ? $record[$label_key] : '' );
                $serie = eventosapp_custom_metrics_normalize_dimension_value( isset($record[$series_key]) ? $record[$series_key] : '' );

                if ( ! isset($bucket[$label]) ) $bucket[$label] = [];
                if ( ! isset($bucket[$label][$serie]) ) $bucket[$label][$serie] = ['sum' => 0.0, 'count' => 0];
                if ( ! isset($label_totals[$label]) ) $label_totals[$label] = ['sum' => 0.0, 'count' => 0];

                eventosapp_custom_metrics_add_to_bucket($bucket[$label][$serie], $record, $value_key, $aggr);
                eventosapp_custom_metrics_add_to_bucket($label_totals[$label], $record, $value_key, $aggr);
                $series_used[$serie] = true;
            }

            $label_rows = [];
            foreach ( $label_totals as $label => $total_bucket ) {
                $label_rows[] = [
                    'label' => $label,
                    'sort'  => eventosapp_custom_metrics_bucket_value($total_bucket, $aggr),
                ];
            }
            $label_rows = eventosapp_custom_metrics_sort_bucket_rows($label_rows, $sort_by);
            $label_rows = array_slice($label_rows, 0, $limit);

            $labels = array_map(function($row){ return $row['label']; }, $label_rows);
            $series_names = array_keys($series_used);
            natcasesort($series_names);
            $series_names = array_values($series_names);

            $datasets = [];
            foreach ( $series_names as $serie ) {
                $data = [];
                $display = [];
                foreach ( $labels as $label ) {
                    $cell = isset($bucket[$label][$serie]) ? $bucket[$label][$serie] : ['sum' => 0.0, 'count' => 0];
                    $value = eventosapp_custom_metrics_bucket_value($cell, $aggr);
                    $data[] = $value;
                    $display[] = eventosapp_custom_metrics_format_number($value, $format);
                }
                $datasets[] = [
                    'label'   => $serie,
                    'data'    => $data,
                    'display' => $display,
                ];
            }

            return array_merge($base_payload, [
                'labels'      => $labels,
                'datasets'    => $datasets,
                'label_title' => isset($field_map[$label_key]) ? $field_map[$label_key]['label'] : 'Etiqueta',
                'series_title'=> isset($field_map[$series_key]) ? $field_map[$series_key]['label'] : 'Serie',
                'value_title' => $aggr === 'count' ? 'Registros' : ( isset($field_map[$value_key]) ? $field_map[$value_key]['label'] : 'Valor' ),
                'empty'       => empty($labels) || empty($datasets),
            ]);
        }

        $bucket = [];
        foreach ( $records as $record ) {
            $label = eventosapp_custom_metrics_normalize_dimension_value( isset($record[$label_key]) ? $record[$label_key] : '' );
            if ( ! isset($bucket[$label]) ) $bucket[$label] = ['sum' => 0.0, 'count' => 0];
            eventosapp_custom_metrics_add_to_bucket($bucket[$label], $record, $value_key, $aggr);
        }

        $rows = [];
        foreach ( $bucket as $label => $data ) {
            $value = eventosapp_custom_metrics_bucket_value($data, $aggr);
            $rows[] = [
                'label'   => $label,
                'value'   => $value,
                'display' => eventosapp_custom_metrics_format_number($value, $format),
                'sort'    => (float) $value,
            ];
        }

        $rows = eventosapp_custom_metrics_sort_bucket_rows($rows, $sort_by);
        $rows = array_slice($rows, 0, $limit);

        $labels = [];
        $values = [];
        $display_values = [];
        foreach ( $rows as $row ) {
            $labels[] = $row['label'];
            $values[] = $row['value'];
            $display_values[] = $row['display'];
        }

        return array_merge($base_payload, [
            'labels'         => $labels,
            'values'         => $values,
            'display_values' => $display_values,
            'label_title'    => isset($field_map[$label_key]) ? $field_map[$label_key]['label'] : 'Etiqueta',
            'value_title'    => $aggr === 'count' ? 'Registros' : ( isset($field_map[$value_key]) ? $field_map[$value_key]['label'] : 'Valor' ),
            'empty'          => empty($labels),
        ]);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_build_slot_payload') ) {
    function eventosapp_custom_metrics_build_slot_payload($event_id, $slot, $records, $field_map, $slot_index){
        if ( empty($slot['enabled']) ) {
            return ['empty' => true];
        }

        $chart_type = isset($slot['chart_type']) ? (string) $slot['chart_type'] : 'column';
        $title      = isset($slot['title']) && $slot['title'] !== '' ? (string) $slot['title'] : 'Métrica personalizada';

        $base_payload = [
            'id'                   => 'evapp_custom_metric_' . (int) $event_id . '_' . (int) $slot_index,
            'title'                => $title,
            'chart_type'           => $chart_type,
            'span'                 => isset($slot['span']) && (int) $slot['span'] === 2 ? 2 : 1,
            'aggregation'          => isset($slot['aggregation']) ? (string) $slot['aggregation'] : 'count',
            'percentage_mode'      => isset($slot['percentage_mode']) ? (string) $slot['percentage_mode'] : 'none',
            'show_percentages'     => ! empty($slot['show_percentages']),
            'show_legend'          => ! empty($slot['show_legend']),
            'show_data_labels'     => ! empty($slot['show_data_labels']),
            'filtered_count'       => count($records),
            'empty'                => false,
        ];

        if ( empty($records) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'Este evento no tiene tickets para graficar.';
            return $base_payload;
        }

        if ( $chart_type === 'table' ) {
            return eventosapp_custom_metrics_build_table_payload($base_payload, $slot, $records, $field_map);
        }

        if ( $chart_type === 'number_card' ) {
            return eventosapp_custom_metrics_build_number_card_payload($base_payload, $slot, $records, $field_map);
        }

        return eventosapp_custom_metrics_build_chart_payload_common($base_payload, $slot, $records, $field_map);
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
                if ( ! empty($payload) ) {
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
        $percentage_options = eventosapp_custom_metrics_percentage_options();
        $value_format_options = eventosapp_custom_metrics_value_format_options();
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
            .evapp-cmetrics-checks{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:8px}
            .evapp-cmetrics-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}
            .evapp-cmetrics-note{font-size:12px;color:#646970;margin-top:4px;line-height:1.45}
            .evapp-cmetrics-section-title{grid-column:1/-1;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:7px 10px;font-weight:700;color:#334155;margin-top:4px}
            .evapp-cmetrics-inline-check{display:inline-flex!important;align-items:center;gap:7px;flex-direction:row!important;font-weight:600;color:#374151;margin-top:22px}
            @media(max-width:900px){.evapp-cmetrics-slots,.evapp-cmetrics-grid{grid-template-columns:1fr}}
        </style>

        <div class="evapp-cmetrics-wrap" id="evappCustomMetricsBuilder">
            <p class="evapp-cmetrics-help">
                Configura aquí métricas adicionales para este evento. Cada tipo de gráfico muestra únicamente los campos que necesita: tablas dinámicas con fila, columna y valores; tarjetas con cálculo principal; columnas con eje, series y valor; tortas con categoría y valor.
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
            const percentageOptions = <?php echo wp_json_encode($percentage_options); ?>;
            const valueFormatOptions = <?php echo wp_json_encode($value_format_options); ?>;
            let state = <?php echo wp_json_encode($layout); ?>;

            function defaultSlot(){
                return {
                    enabled:false,
                    title:'',
                    chart_type:'column',
                    span:1,
                    row_field:'localidad',
                    column_field:'',
                    label_field:'localidad',
                    series_field:'',
                    value_field:'',
                    aggregation:'count',
                    sort_by:'value_desc',
                    limit:10,
                    value_format:'integer',
                    percentage_mode:'none',
                    show_percentages:false,
                    show_legend:true,
                    show_data_labels:false,
                    table_include_totals:true,
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
                    return {slots:[normalizeSlot(slots[0]), normalizeSlot(slots[1])]};
                });
            }

            function normalizeSlot(slot){
                const s = Object.assign(defaultSlot(), slot || {});
                if (!s.row_field && s.label_field) s.row_field = s.label_field;
                if (!s.label_field && s.row_field) s.label_field = s.row_field;
                if (!s.value_format) s.value_format = 'integer';
                if (!s.percentage_mode) s.percentage_mode = 'none';
                return s;
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

            function fieldOptions(selected, emptyLabel, onlyNumeric){
                let html = emptyLabel ? '<option value="">'+esc(emptyLabel)+'</option>' : '';
                fields.forEach(function(f){
                    if (onlyNumeric && f.type !== 'number') return;
                    const suffix = f.type ? ' · '+f.type : '';
                    html += '<option value="'+esc(f.key)+'" '+(String(selected)===String(f.key)?'selected':'')+'>'+esc(f.label + suffix)+'</option>';
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
                    + '<label class="evapp-cmetrics-check-legend"><input type="checkbox" class="evapp-cmetrics-control" data-prop="show_legend" '+(slot.show_legend?'checked':'')+'> Mostrar leyenda</label>'
                    + '<label class="evapp-cmetrics-check-values"><input type="checkbox" class="evapp-cmetrics-control" data-prop="show_data_labels" '+(slot.show_data_labels?'checked':'')+'> Mostrar valores</label>'
                    + '<label class="evapp-cmetrics-check-percent"><input type="checkbox" class="evapp-cmetrics-control" data-prop="show_percentages" '+(slot.show_percentages?'checked':'')+'> Mostrar porcentajes</label>'
                    + '</div>'
                    + '<div class="evapp-cmetrics-grid" style="margin-top:10px">'
                    + '<div class="evapp-cmetrics-field"><label>Título</label><input type="text" class="evapp-cmetrics-control" data-prop="title" value="'+esc(slot.title)+'"></div>'
                    + '<div class="evapp-cmetrics-field"><label>Tipo</label><select class="evapp-cmetrics-control evapp-cmetrics-type" data-prop="chart_type">'+optionsFromMap(chartTypes, slot.chart_type)+'</select></div>'
                    + '<div class="evapp-cmetrics-field"><label>Ancho en la fila</label><select class="evapp-cmetrics-control" data-prop="span"><option value="1" '+(parseInt(slot.span,10)!==2?'selected':'')+'>Mitad de fila</option><option value="2" '+(parseInt(slot.span,10)===2?'selected':'')+'>Fila completa</option></select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-limit"><label>Límite de filas / categorías</label><input type="number" min="1" max="500" class="evapp-cmetrics-control" data-prop="limit" value="'+esc(slot.limit || 10)+'"></div>'
                    + '<div class="evapp-cmetrics-section-title evapp-cmetrics-table-only">Estructura de tabla dinámica</div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-row"><label>Campo de fila</label><select class="evapp-cmetrics-control" data-prop="row_field">'+fieldOptions(slot.row_field, 'Selecciona fila', false)+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-column"><label>Campo de columna</label><select class="evapp-cmetrics-control" data-prop="column_field">'+fieldOptions(slot.column_field, 'Sin columnas / solo total', false)+'</select></div>'
                    + '<div class="evapp-cmetrics-section-title evapp-cmetrics-chart-only">Estructura del gráfico</div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-label"><label>Eje / categoría</label><select class="evapp-cmetrics-control" data-prop="label_field">'+fieldOptions(slot.label_field, 'Selecciona campo', false)+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-series"><label>Series / columnas</label><select class="evapp-cmetrics-control" data-prop="series_field">'+fieldOptions(slot.series_field, 'Sin series', false)+'</select></div>'
                    + '<div class="evapp-cmetrics-section-title evapp-cmetrics-calc-title">Valor y cálculo</div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-aggregation"><label>Cálculo</label><select class="evapp-cmetrics-control" data-prop="aggregation">'+optionsFromMap(aggregations, slot.aggregation)+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-value"><label>Campo de valor</label><select class="evapp-cmetrics-control" data-prop="value_field">'+fieldOptions(slot.value_field, 'No aplica para contar registros', false)+'</select><span class="evapp-cmetrics-note">Se usa cuando el cálculo es suma o promedio. Puede ser un campo base o personalizado.</span></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-format"><label>Formato del valor</label><select class="evapp-cmetrics-control" data-prop="value_format">'+optionsFromMap(valueFormatOptions, slot.value_format)+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-percent-mode"><label>Porcentajes en tabla</label><select class="evapp-cmetrics-control" data-prop="percentage_mode">'+optionsFromMap(percentageOptions, slot.percentage_mode)+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-sort"><label>Orden</label><select class="evapp-cmetrics-control" data-prop="sort_by">'+optionsFromMap(sortOptions, slot.sort_by)+'</select></div>'
                    + '<label class="evapp-cmetrics-field evapp-cmetrics-inline-check evapp-cmetrics-field-totals"><input type="checkbox" class="evapp-cmetrics-control" data-prop="table_include_totals" '+(slot.table_include_totals?'checked':'')+'> Mostrar totales en tabla</label>'
                    + '</div>'
                    + '</div>';
            }

            function refreshSlotVisibility(){
                $('.evapp-cmetrics-slot').each(function(){
                    const $slot = $(this);
                    const type = $slot.find('[data-prop="chart_type"]').val();
                    const aggr = $slot.find('[data-prop="aggregation"]').val();
                    $slot.toggleClass('is-disabled', !$slot.find('[data-prop="enabled"]').is(':checked'));

                    const isTable = type === 'table';
                    const isCard = type === 'number_card';
                    const isColumn = type === 'column';
                    const isPie = type === 'pie';

                    $slot.find('.evapp-cmetrics-table-only').toggle(isTable);
                    $slot.find('.evapp-cmetrics-chart-only').toggle(isColumn || isPie);
                    $slot.find('.evapp-cmetrics-field-row').toggle(isTable);
                    $slot.find('.evapp-cmetrics-field-column').toggle(isTable);
                    $slot.find('.evapp-cmetrics-field-label').toggle(isColumn || isPie);
                    $slot.find('.evapp-cmetrics-field-series').toggle(isColumn);
                    $slot.find('.evapp-cmetrics-field-percent-mode').toggle(isTable);
                    $slot.find('.evapp-cmetrics-field-totals').toggle(isTable);
                    $slot.find('.evapp-cmetrics-field-sort').toggle(isTable || isColumn || isPie);
                    $slot.find('.evapp-cmetrics-field-limit').toggle(isTable || isColumn || isPie);
                    $slot.find('.evapp-cmetrics-check-legend').toggle(isColumn || isPie);
                    $slot.find('.evapp-cmetrics-check-values').toggle(isColumn || isPie);
                    $slot.find('.evapp-cmetrics-check-percent').toggle(isColumn || isPie);
                    $slot.find('.evapp-cmetrics-field-value').toggle(aggr !== 'count');
                    $slot.find('.evapp-cmetrics-calc-title').toggle(true);
                    $slot.find('.evapp-cmetrics-field-format').toggle(true);
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
                if (!state.rows[rowIndex] || !state.rows[rowIndex].slots[slotIndex]) return;
                state.rows[rowIndex].slots[slotIndex][prop] = value;

                if (prop === 'row_field' && !state.rows[rowIndex].slots[slotIndex].label_field) {
                    state.rows[rowIndex].slots[slotIndex].label_field = value;
                }
                if (prop === 'label_field' && !state.rows[rowIndex].slots[slotIndex].row_field) {
                    state.rows[rowIndex].slots[slotIndex].row_field = value;
                }

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
