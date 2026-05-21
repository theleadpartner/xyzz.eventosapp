<?php
// includes/admin/eventosapp-event-custom-metrics-metabox.php
if ( ! defined('ABSPATH') ) exit;

/**
 * EventosApp - Métricas personalizadas por evento.
 *
 * Archivo independiente para agregar un metabox al CPT de eventos y permitir
 * configurar gráficas adicionales que se renderizan en:
 * includes/frontend/eventosapp-frontend-metrics.php
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

if ( ! function_exists('eventosapp_custom_metrics_value_format_options') ) {
    function eventosapp_custom_metrics_value_format_options(){
        return [
            'integer'  => 'Entero',
            'decimal'  => 'Decimal',
            'currency' => 'Moneda / valor',
        ];
    }
}

if ( ! function_exists('eventosapp_custom_metrics_percentage_options') ) {
    function eventosapp_custom_metrics_percentage_options(){
        return [
            'none'    => 'No mostrar porcentajes',
            'general' => '% sobre total general',
            'row'     => '% sobre total de la fila',
            'column'  => '% sobre total de la columna',
        ];
    }
}

if ( ! function_exists('eventosapp_custom_metrics_default_settings') ) {
    function eventosapp_custom_metrics_default_settings(){
        return [
            'show_header'  => true,
            'header_text'  => 'Métricas personalizadas',
            'header_color' => '#eaf1ff',
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
            'row_field'            => 'localidad',
            'column_field'         => 'checked_in_any',
            'label_field'          => 'localidad',
            'series_field'         => '',
            'value_field'          => '',
            'aggregation'          => 'count',
            'sort_by'              => 'value_desc',
            'limit'                => 10,
            'value_format'         => 'integer',
            'percentage_mode'      => 'none',
            'table_include_totals' => true,
            'show_legend'          => true,
            'show_data_labels'     => false,
            'table_fields'         => ['nombre', 'apellido', 'email', 'localidad', 'checked_in_any'],
        ];
    }
}

if ( ! function_exists('eventosapp_custom_metrics_default_layout') ) {
    function eventosapp_custom_metrics_default_layout(){
        return [
            'settings' => eventosapp_custom_metrics_default_settings(),
            'rows'     => [
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

if ( ! function_exists('eventosapp_custom_metrics_sanitize_color') ) {
    function eventosapp_custom_metrics_sanitize_color($color, $fallback = '#eaf1ff'){
        $color = is_string($color) ? trim($color) : '';
        if ( function_exists('sanitize_hex_color') ) {
            $clean = sanitize_hex_color($color);
            return $clean ? $clean : $fallback;
        }
        return preg_match('/^#[a-f0-9]{6}$/i', $color) ? $color : $fallback;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_sanitize_settings') ) {
    function eventosapp_custom_metrics_sanitize_settings($settings){
        $default = eventosapp_custom_metrics_default_settings();
        $settings = is_array($settings) ? array_merge($default, $settings) : $default;

        $header_text = isset($settings['header_text']) ? sanitize_text_field((string) $settings['header_text']) : $default['header_text'];
        if ( $header_text === '' ) $header_text = $default['header_text'];

        return [
            'show_header'  => ! empty($settings['show_header']),
            'header_text'  => $header_text,
            'header_color' => eventosapp_custom_metrics_sanitize_color(isset($settings['header_color']) ? $settings['header_color'] : $default['header_color'], $default['header_color']),
        ];
    }
}


if ( ! function_exists('eventosapp_custom_metrics_normalize_field_key') ) {
    function eventosapp_custom_metrics_normalize_field_key($field_key){
        $field_key = sanitize_key((string) $field_key);
        if ( $field_key === '' ) return '';

        $aliases = [
            '_eventosapp_ticket_modalidad'  => 'modalidad',
            'eventosapp_ticket_modalidad'   => 'modalidad',
            'ticket_modalidad'              => 'modalidad',
            'modalidad_ticket'              => 'modalidad',
            'modalidad_del_ticket'          => 'modalidad',
            'tipo_modalidad'                => 'modalidad',
        ];

        return isset($aliases[$field_key]) ? $aliases[$field_key] : $field_key;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_ticket_modalidad_display') ) {
    function eventosapp_custom_metrics_get_ticket_modalidad_display($ticket_id, $event_id = 0){
        $ticket_id = (int) $ticket_id;
        $event_id  = (int) $event_id;

        if ( $ticket_id <= 0 ) return '';

        if ( function_exists('eventosapp_get_ticket_modalidad_label') ) {
            $label = eventosapp_get_ticket_modalidad_label($ticket_id);
            if ( is_string($label) && trim($label) !== '' ) {
                return sanitize_text_field($label);
            }
        }

        $raw = get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true);
        if ( function_exists('eventosapp_resolve_ticket_modalidad') ) {
            $raw = eventosapp_resolve_ticket_modalidad($event_id, $raw, $raw);
        } elseif ( function_exists('eventosapp_normalize_ticket_modalidad') ) {
            $raw = eventosapp_normalize_ticket_modalidad($raw);
        } else {
            $raw = sanitize_key((string) $raw);
            $raw = in_array($raw, ['presencial', 'virtual'], true) ? $raw : '';
        }

        $options = function_exists('eventosapp_ticket_modalidad_options')
            ? eventosapp_ticket_modalidad_options()
            : [ 'presencial' => 'Presencial', 'virtual' => 'Virtual' ];

        if ( isset($options[$raw]) ) return sanitize_text_field($options[$raw]);
        return $raw !== '' ? ucwords(str_replace(['_', '-'], ' ', sanitize_text_field($raw))) : '';
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_available_fields') ) {
    function eventosapp_custom_metrics_get_available_fields($event_id){
        $event_id = (int) $event_id;
        static $cache = [];
        if ( isset($cache[$event_id]) ) return $cache[$event_id];

        $fields = [
            [ 'key'=>'ticket_public_id', 'label'=>'Ticket Public ID', 'type'=>'text', 'source'=>'system', 'meta_key'=>'eventosapp_ticketID' ],
            [ 'key'=>'ticket_post_id', 'label'=>'Ticket Post ID', 'type'=>'number', 'source'=>'computed' ],
            [ 'key'=>'secuencia', 'label'=>'Secuencia interna', 'type'=>'number', 'source'=>'system', 'meta_key'=>'_eventosapp_ticket_seq' ],
            [ 'key'=>'nombre', 'label'=>'Nombre', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_nombre' ],
            [ 'key'=>'apellido', 'label'=>'Apellido', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_apellido' ],
            [ 'key'=>'cc', 'label'=>'CC', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_cc' ],
            [ 'key'=>'email', 'label'=>'Email', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_email' ],
            [ 'key'=>'telefono', 'label'=>'Teléfono', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_tel' ],
            [ 'key'=>'empresa', 'label'=>'Empresa', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_empresa' ],
            [ 'key'=>'nit', 'label'=>'NIT', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_nit' ],
            [ 'key'=>'cargo', 'label'=>'Cargo', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_cargo' ],
            [ 'key'=>'ciudad', 'label'=>'Ciudad', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_ciudad' ],
            [ 'key'=>'pais', 'label'=>'País', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_pais' ],
            [ 'key'=>'localidad', 'label'=>'Localidad', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_localidad' ],
            [ 'key'=>'modalidad', 'label'=>'Modalidad', 'type'=>'text', 'source'=>'computed' ],
            [ 'key'=>'estado_pago', 'label'=>'Estado de pago', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_estado_pago' ],
            [ 'key'=>'checked_in_any', 'label'=>'Checked-In (algún día)', 'type'=>'text', 'source'=>'computed' ],
            [ 'key'=>'medio_checkin', 'label'=>'Medio de check-in', 'type'=>'text', 'source'=>'computed' ],
            [ 'key'=>'acompanantes_sin_qr', 'label'=>'Acompañantes sin QR', 'type'=>'number', 'source'=>'system', 'meta_key'=>'_eventosapp_ticket_acompanantes_sin_qr' ],
            [ 'key'=>'fecha_creacion', 'label'=>'Fecha creación', 'type'=>'date', 'source'=>'computed' ],
        ];

        if ( $event_id > 0 ) {
            $extras = [];
            if ( function_exists('eventosapp_get_event_extra_fields') ) {
                $extras = eventosapp_get_event_extra_fields($event_id);
            } else {
                $extras = get_post_meta($event_id, '_eventosapp_extra_fields', true);
            }

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
            $key = eventosapp_custom_metrics_normalize_field_key(isset($field['key']) ? $field['key'] : '');
            if ( $key === '' || isset($used[$key]) ) continue;

            $field['key']   = $key;
            $field['label'] = isset($field['label']) ? sanitize_text_field($field['label']) : $key;
            $field['type']  = isset($field['type']) && in_array($field['type'], ['text','number','date'], true) ? $field['type'] : 'text';
            $out[] = $field;
            $used[$key] = true;
        }

        $cache[$event_id] = $out;
        return $out;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_field_map') ) {
    function eventosapp_custom_metrics_get_field_map($event_id){
        $event_id = (int) $event_id;
        static $cache = [];
        if ( isset($cache[$event_id]) ) return $cache[$event_id];

        $map = [];
        foreach ( eventosapp_custom_metrics_get_available_fields($event_id) as $field ) {
            $map[$field['key']] = $field;
        }
        $cache[$event_id] = $map;
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
        $formats     = array_keys(eventosapp_custom_metrics_value_format_options());
        $percentages = array_keys(eventosapp_custom_metrics_percentage_options());

        $legacy_label = isset($slot['label_field']) ? eventosapp_custom_metrics_normalize_field_key((string) $slot['label_field']) : $default['label_field'];

        $out = [];
        $out['enabled']              = ! empty($slot['enabled']);
        $out['title']                = sanitize_text_field(isset($slot['title']) ? (string) $slot['title'] : '');
        $out['chart_type']           = in_array($slot['chart_type'], $chart_types, true) ? $slot['chart_type'] : $default['chart_type'];
        $out['span']                 = (int) $slot['span'] === 2 ? 2 : 1;
        $out['row_field']            = eventosapp_custom_metrics_normalize_field_key(isset($slot['row_field']) && $slot['row_field'] !== '' ? (string) $slot['row_field'] : $legacy_label);
        $out['column_field']         = eventosapp_custom_metrics_normalize_field_key(isset($slot['column_field']) ? (string) $slot['column_field'] : $default['column_field']);
        $out['label_field']          = eventosapp_custom_metrics_normalize_field_key($legacy_label !== '' ? $legacy_label : $out['row_field']);
        $out['series_field']         = eventosapp_custom_metrics_normalize_field_key(isset($slot['series_field']) ? (string) $slot['series_field'] : '');
        $out['value_field']          = eventosapp_custom_metrics_normalize_field_key(isset($slot['value_field']) ? (string) $slot['value_field'] : '');
        $out['aggregation']          = in_array($slot['aggregation'], $aggr_types, true) ? $slot['aggregation'] : $default['aggregation'];
        $out['sort_by']              = in_array($slot['sort_by'], $sort_types, true) ? $slot['sort_by'] : $default['sort_by'];
        $out['limit']                = max(1, min(500, (int) $slot['limit']));
        $out['value_format']         = in_array(isset($slot['value_format']) ? $slot['value_format'] : '', $formats, true) ? $slot['value_format'] : $default['value_format'];
        $out['percentage_mode']      = in_array(isset($slot['percentage_mode']) ? $slot['percentage_mode'] : '', $percentages, true) ? $slot['percentage_mode'] : $default['percentage_mode'];
        $out['table_include_totals'] = ! empty($slot['table_include_totals']);
        $out['show_legend']          = ! empty($slot['show_legend']);
        $out['show_data_labels']     = ! empty($slot['show_data_labels']);
        $out['table_fields']         = [];

        if ( isset($slot['table_fields']) && is_array($slot['table_fields']) ) {
            foreach ( $slot['table_fields'] as $field_key ) {
                $field_key = eventosapp_custom_metrics_normalize_field_key((string) $field_key);
                if ( $field_key !== '' && ! in_array($field_key, $out['table_fields'], true) ) {
                    $out['table_fields'][] = $field_key;
                }
            }
        }
        if ( empty($out['table_fields']) ) $out['table_fields'] = $default['table_fields'];

        return $out;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_sanitize_layout_config') ) {
    function eventosapp_custom_metrics_sanitize_layout_config($layout){
        if ( ! is_array($layout) ) {
            return eventosapp_custom_metrics_default_layout();
        }

        $out = [
            'settings' => eventosapp_custom_metrics_sanitize_settings(isset($layout['settings']) ? $layout['settings'] : []),
            'rows'     => [],
        ];

        if ( empty($layout['rows']) || ! is_array($layout['rows']) ) {
            $layout['rows'] = eventosapp_custom_metrics_default_layout()['rows'];
        }

        foreach ( $layout['rows'] as $row ) {
            $slots_raw = isset($row['slots']) && is_array($row['slots']) ? array_values($row['slots']) : [];
            $slot_1 = eventosapp_custom_metrics_sanitize_slot(isset($slots_raw[0]) ? $slots_raw[0] : []);
            $slot_2 = eventosapp_custom_metrics_sanitize_slot(isset($slots_raw[1]) ? $slots_raw[1] : []);
            $out['rows'][] = ['slots' => [$slot_1, $slot_2]];
        }

        if ( empty($out['rows']) ) $out['rows'] = eventosapp_custom_metrics_default_layout()['rows'];
        return $out;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_layout_config') ) {
    function eventosapp_custom_metrics_get_layout_config($event_id){
        $raw = get_post_meta((int) $event_id, EVAPP_CUSTOM_METRICS_META_KEY, true);
        if ( ! is_array($raw) ) $raw = [];
        return eventosapp_custom_metrics_sanitize_layout_config($raw);
    }
}


if ( ! function_exists('eventosapp_custom_metrics_get_enabled_slots') ) {
    function eventosapp_custom_metrics_get_enabled_slots($layout){
        $slots = [];
        if ( ! is_array($layout) || empty($layout['rows']) || ! is_array($layout['rows']) ) return $slots;

        foreach ( $layout['rows'] as $row ) {
            if ( empty($row['slots']) || ! is_array($row['slots']) ) continue;
            foreach ( $row['slots'] as $slot ) {
                if ( ! empty($slot['enabled']) ) $slots[] = $slot;
            }
        }

        return $slots;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_has_enabled_slots') ) {
    function eventosapp_custom_metrics_has_enabled_slots($event_id){
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) return false;
        $layout = eventosapp_custom_metrics_get_layout_config($event_id);
        return ! empty(eventosapp_custom_metrics_get_enabled_slots($layout));
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_required_field_keys_from_layout') ) {
    function eventosapp_custom_metrics_get_required_field_keys_from_layout($layout){
        $required = [];
        foreach ( eventosapp_custom_metrics_get_enabled_slots($layout) as $slot ) {
            $chart_type = isset($slot['chart_type']) ? (string) $slot['chart_type'] : 'column';
            $aggr       = isset($slot['aggregation']) ? (string) $slot['aggregation'] : 'count';

            $add_key = function($key) use (&$required){
                $key = eventosapp_custom_metrics_normalize_field_key((string) $key);
                if ( $key !== '' ) $required[$key] = true;
            };

            if ( $chart_type === 'table' ) {
                $add_key(isset($slot['row_field']) ? $slot['row_field'] : '');
                $add_key(isset($slot['column_field']) ? $slot['column_field'] : '');
                if ( $aggr !== 'count' ) $add_key(isset($slot['value_field']) ? $slot['value_field'] : '');
                continue;
            }

            if ( $chart_type === 'number_card' ) {
                if ( $aggr !== 'count' ) $add_key(isset($slot['value_field']) ? $slot['value_field'] : '');
                continue;
            }

            $add_key(isset($slot['label_field']) ? $slot['label_field'] : '');
            $add_key(isset($slot['series_field']) ? $slot['series_field'] : '');
            if ( $aggr !== 'count' ) $add_key(isset($slot['value_field']) ? $slot['value_field'] : '');
        }

        return array_keys($required);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_normalize_display_value') ) {
    function eventosapp_custom_metrics_normalize_display_value($value){
        if ( is_array($value) ) {
            $flat = [];
            array_walk_recursive($value, function($v) use (&$flat){ if ( is_scalar($v) ) $flat[] = (string) $v; });
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
        if ( is_int($value) || is_float($value) ) return (float) $value;
        if ( is_array($value) || is_object($value) ) return null;

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
        $value = is_numeric($value) ? (float) $value : 0.0;
        if ( $format === 'currency' ) return '$' . number_format($value, 0, ',', '.');
        if ( $format === 'decimal' ) return number_format($value, 2, ',', '.');
        return number_format($value, 0, ',', '.');
    }
}

if ( ! function_exists('eventosapp_custom_metrics_format_metric_cell') ) {
    function eventosapp_custom_metrics_format_metric_cell($value, $format, $percentage_mode, $percent_base){
        $text = eventosapp_custom_metrics_format_number($value, $format);
        if ( $percentage_mode !== 'none' ) {
            $pct = $percent_base > 0 ? ( (float) $value * 100 / (float) $percent_base ) : 0;
            $text .= ' (' . number_format($pct, 2, ',', '.') . '%)';
        }
        return $text;
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
                if ( isset($entry['qr_type_label']) && $entry['qr_type_label'] !== '' ) return sanitize_text_field($entry['qr_type_label']);
                if ( isset($entry['qr_type']) && $entry['qr_type'] !== '' ) return sanitize_text_field($entry['qr_type']);
                return 'Sin clasificar';
            }
        }
        return '';
    }
}

if ( ! function_exists('eventosapp_custom_metrics_extract_ticket_value') ) {
    function eventosapp_custom_metrics_extract_ticket_value($ticket_id, $event_id, $field){
        $key = eventosapp_custom_metrics_normalize_field_key(isset($field['key']) ? (string) $field['key'] : '');

        if ( $key === 'ticket_post_id' ) return (string) $ticket_id;
        if ( $key === 'modalidad' ) return eventosapp_custom_metrics_get_ticket_modalidad_display($ticket_id, $event_id);
        if ( $key === 'checked_in_any' ) return eventosapp_custom_metrics_ticket_checked_in_any($ticket_id);
        if ( $key === 'medio_checkin' ) return eventosapp_custom_metrics_ticket_first_qr_label($ticket_id);
        if ( $key === 'fecha_creacion' ) return get_post_time('Y-m-d H:i:s', false, $ticket_id);

        $meta_key = isset($field['meta_key']) ? (string) $field['meta_key'] : '';
        if ( $meta_key !== '' ) return get_post_meta($ticket_id, $meta_key, true);
        return '';
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_ticket_records') ) {
    function eventosapp_custom_metrics_get_ticket_records($event_id, $field_keys = null){
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) return [];

        $all_fields = eventosapp_custom_metrics_get_available_fields($event_id);
        $fields = $all_fields;

        if ( is_array($field_keys) ) {
            $wanted = [];
            foreach ( $field_keys as $field_key ) {
                $field_key = eventosapp_custom_metrics_normalize_field_key((string) $field_key);
                if ( $field_key !== '' ) $wanted[$field_key] = true;
            }
            $fields = [];
            foreach ( $all_fields as $field ) {
                $key = isset($field['key']) ? (string) $field['key'] : '';
                if ( isset($wanted[$key]) ) $fields[] = $field;
            }
        }

        $query = new WP_Query([
            'post_type'              => 'eventosapp_ticket',
            'post_status'            => 'any',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'meta_query'             => [
                [
                    'key'     => '_eventosapp_ticket_evento_id',
                    'value'   => $event_id,
                    'compare' => '=',
                ],
            ],
        ]);

        $ticket_ids = array_map('intval', (array) $query->posts);
        if ( ! empty($ticket_ids) ) {
            update_meta_cache('post', $ticket_ids);
        }

        $records = [];
        foreach ( $ticket_ids as $ticket_id ) {
            $record = [];
            foreach ( $fields as $field ) {
                $record[$field['key']] = eventosapp_custom_metrics_extract_ticket_value($ticket_id, $event_id, $field);
            }
            $records[] = $record;
        }
        return $records;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_calculate_record_value') ) {
    function eventosapp_custom_metrics_calculate_record_value($record, $value_key, $aggregation){
        if ( $aggregation === 'count' ) return 1;
        return eventosapp_custom_metrics_normalize_numeric_value(isset($record[$value_key]) ? $record[$value_key] : null);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_bucket_value') ) {
    function eventosapp_custom_metrics_bucket_value($data, $aggregation){
        $sum   = isset($data['sum']) ? (float) $data['sum'] : 0.0;
        $count = isset($data['count']) ? (int) $data['count'] : 0;
        if ( $aggregation === 'avg' ) return $count > 0 ? ( $sum / $count ) : 0;
        if ( $aggregation === 'sum' ) return $sum;
        return $count;
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
        $row_key    = sanitize_key(isset($slot['row_field']) ? $slot['row_field'] : '');
        $column_key = sanitize_key(isset($slot['column_field']) ? $slot['column_field'] : '');
        $value_key  = sanitize_key(isset($slot['value_field']) ? $slot['value_field'] : '');
        $aggr       = isset($slot['aggregation']) ? (string) $slot['aggregation'] : 'count';
        $limit      = isset($slot['limit']) ? max(1, min(500, (int) $slot['limit'])) : 10;
        $sort_by    = isset($slot['sort_by']) ? (string) $slot['sort_by'] : 'value_desc';
        $format     = isset($slot['value_format']) ? (string) $slot['value_format'] : 'integer';
        $pct_mode   = isset($slot['percentage_mode']) ? (string) $slot['percentage_mode'] : 'none';
        $with_total = ! empty($slot['table_include_totals']);

        if ( ! isset($field_map[$row_key]) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'Debes elegir un campo de fila válido para la tabla.';
            return $base_payload;
        }
        if ( ! isset($field_map[$column_key]) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'Debes elegir un campo de columna válido para la tabla.';
            return $base_payload;
        }
        if ( $aggr !== 'count' && ! isset($field_map[$value_key]) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'El cálculo elegido requiere un campo de valores válido.';
            return $base_payload;
        }

        $matrix = [];
        $row_totals = [];
        $column_totals = [];
        $row_counts = [];
        $column_counts = [];
        $grand_sum = 0.0;
        $grand_count = 0;

        foreach ( $records as $record ) {
            $row_label = eventosapp_custom_metrics_normalize_dimension_value(isset($record[$row_key]) ? $record[$row_key] : '');
            $col_label = eventosapp_custom_metrics_normalize_dimension_value(isset($record[$column_key]) ? $record[$column_key] : '');
            $value = eventosapp_custom_metrics_calculate_record_value($record, $value_key, $aggr);
            if ( $value === null ) continue;

            if ( ! isset($matrix[$row_label]) ) $matrix[$row_label] = [];
            if ( ! isset($matrix[$row_label][$col_label]) ) $matrix[$row_label][$col_label] = ['sum'=>0.0, 'count'=>0];
            if ( ! isset($row_totals[$row_label]) ) $row_totals[$row_label] = 0.0;
            if ( ! isset($row_counts[$row_label]) ) $row_counts[$row_label] = 0;
            if ( ! isset($column_totals[$col_label]) ) $column_totals[$col_label] = 0.0;
            if ( ! isset($column_counts[$col_label]) ) $column_counts[$col_label] = 0;

            if ( $aggr === 'count' ) {
                $matrix[$row_label][$col_label]['count']++;
                $row_totals[$row_label] += 1;
                $column_totals[$col_label] += 1;
                $grand_sum += 1;
            } else {
                $matrix[$row_label][$col_label]['sum'] += (float) $value;
                $matrix[$row_label][$col_label]['count']++;
                $row_totals[$row_label] += (float) $value;
                $column_totals[$col_label] += (float) $value;
                $grand_sum += (float) $value;
            }

            $row_counts[$row_label]++;
            $column_counts[$col_label]++;
            $grand_count++;
        }

        if ( empty($matrix) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'No hay datos suficientes para construir la tabla.';
            return $base_payload;
        }

        $columns = array_keys($column_totals);
        natcasesort($columns);
        $columns = array_values($columns);

        $row_objects = [];
        foreach ( $matrix as $row_label => $cells ) {
            $row_value = $aggr === 'avg' && ! empty($row_counts[$row_label]) ? ( $row_totals[$row_label] / $row_counts[$row_label] ) : $row_totals[$row_label];
            $row_objects[] = [
                'label' => $row_label,
                'sort'  => $row_value,
                'cells' => $cells,
            ];
        }
        $row_objects = eventosapp_custom_metrics_sort_bucket_rows($row_objects, $sort_by);
        $row_objects = array_slice($row_objects, 0, $limit);

        $headers = [$field_map[$row_key]['label']];
        foreach ( $columns as $col_label ) $headers[] = $col_label;
        if ( $with_total ) $headers[] = 'Total';

        $rows = [];
        foreach ( $row_objects as $row_obj ) {
            $row_label = $row_obj['label'];
            $line = [$row_label];
            foreach ( $columns as $col_label ) {
                $value = 0;
                if ( isset($row_obj['cells'][$col_label]) ) {
                    $value = eventosapp_custom_metrics_bucket_value($row_obj['cells'][$col_label], $aggr);
                }
                $base = $grand_sum;
                if ( $pct_mode === 'row' ) $base = isset($row_totals[$row_label]) ? (float) $row_totals[$row_label] : 0;
                if ( $pct_mode === 'column' ) $base = isset($column_totals[$col_label]) ? (float) $column_totals[$col_label] : 0;
                $line[] = eventosapp_custom_metrics_format_metric_cell($value, $format, $pct_mode, $base);
            }
            if ( $with_total ) {
                $total_value = $aggr === 'avg' && ! empty($row_counts[$row_label]) ? ( $row_totals[$row_label] / $row_counts[$row_label] ) : $row_totals[$row_label];
                $line[] = eventosapp_custom_metrics_format_number($total_value, $format);
            }
            $rows[] = $line;
        }

        if ( $with_total ) {
            $total_line = ['Total'];
            foreach ( $columns as $col_label ) {
                $total_value = $aggr === 'avg' && ! empty($column_counts[$col_label]) ? ( $column_totals[$col_label] / $column_counts[$col_label] ) : $column_totals[$col_label];
                $total_line[] = eventosapp_custom_metrics_format_number($total_value, $format);
            }
            $grand_display = $aggr === 'avg' && $grand_count > 0 ? ( $grand_sum / $grand_count ) : $grand_sum;
            $total_line[] = eventosapp_custom_metrics_format_number($grand_display, $format);
            $rows[] = $total_line;
        }

        return array_merge($base_payload, [
            'columns'          => $headers,
            'rows'             => $rows,
            'row_title'        => $field_map[$row_key]['label'],
            'column_title'     => $field_map[$column_key]['label'],
            'value_title'      => $aggr === 'count' ? 'Registros' : ( isset($field_map[$value_key]) ? $field_map[$value_key]['label'] : 'Valor' ),
            'dynamic_columns'  => $columns,
            'has_total_column' => $with_total,
            'total_title'      => 'Total',
            'empty'            => empty($rows),
        ]);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_build_card_payload') ) {
    function eventosapp_custom_metrics_build_card_payload($base_payload, $slot, $records, $field_map){
        $aggr      = isset($slot['aggregation']) ? (string) $slot['aggregation'] : 'count';
        $value_key = sanitize_key(isset($slot['value_field']) ? $slot['value_field'] : '');
        $format    = isset($slot['value_format']) ? (string) $slot['value_format'] : 'integer';

        if ( $aggr !== 'count' && ! isset($field_map[$value_key]) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'La tarjeta requiere un campo de valor válido cuando usas suma o promedio.';
            return $base_payload;
        }

        $sum = 0.0;
        $count = 0;
        foreach ( $records as $record ) {
            if ( $aggr === 'count' ) {
                $count++;
                continue;
            }
            $num = eventosapp_custom_metrics_normalize_numeric_value(isset($record[$value_key]) ? $record[$value_key] : null);
            if ( $num === null ) continue;
            $sum += $num;
            $count++;
        }

        $metric_value = $aggr === 'avg' ? ( $count > 0 ? $sum / $count : 0 ) : ( $aggr === 'sum' ? $sum : $count );
        $metric_label = $aggr === 'count' ? 'Registros' : ( isset($field_map[$value_key]) ? $field_map[$value_key]['label'] : 'Valor' );

        return array_merge($base_payload, [
            'metric_value'         => $metric_value,
            'metric_value_display' => eventosapp_custom_metrics_format_number($metric_value, $format),
            'metric_label'         => $metric_label,
        ]);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_build_group_chart_payload') ) {
    function eventosapp_custom_metrics_build_group_chart_payload($base_payload, $slot, $records, $field_map){
        $chart_type = isset($slot['chart_type']) ? (string) $slot['chart_type'] : 'column';
        $label_key  = sanitize_key(isset($slot['label_field']) ? $slot['label_field'] : '');
        $series_key = sanitize_key(isset($slot['series_field']) ? $slot['series_field'] : '');
        $value_key  = sanitize_key(isset($slot['value_field']) ? $slot['value_field'] : '');
        $aggr       = isset($slot['aggregation']) ? (string) $slot['aggregation'] : 'count';
        $sort_by    = isset($slot['sort_by']) ? (string) $slot['sort_by'] : 'value_desc';
        $limit      = isset($slot['limit']) ? max(1, min(500, (int) $slot['limit'])) : 10;
        $format     = isset($slot['value_format']) ? (string) $slot['value_format'] : 'integer';
        $pct_mode   = isset($slot['percentage_mode']) ? (string) $slot['percentage_mode'] : 'none';

        if ( ! isset($field_map[$label_key]) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'Debes elegir un campo de etiqueta válido.';
            return $base_payload;
        }
        if ( $aggr !== 'count' && ! isset($field_map[$value_key]) ) {
            $base_payload['empty'] = true;
            $base_payload['message'] = 'El cálculo elegido requiere un campo de valores válido.';
            return $base_payload;
        }

        $use_series = $chart_type === 'column' && $series_key !== '' && isset($field_map[$series_key]);
        $bucket = [];
        $label_totals = [];
        $series_names = [];
        $grand_total = 0.0;

        foreach ( $records as $record ) {
            $label = eventosapp_custom_metrics_normalize_dimension_value(isset($record[$label_key]) ? $record[$label_key] : '');
            $series = $use_series ? eventosapp_custom_metrics_normalize_dimension_value(isset($record[$series_key]) ? $record[$series_key] : '') : '__single__';
            $value = eventosapp_custom_metrics_calculate_record_value($record, $value_key, $aggr);
            if ( $value === null ) continue;

            if ( ! isset($bucket[$label]) ) $bucket[$label] = [];
            if ( ! isset($bucket[$label][$series]) ) $bucket[$label][$series] = ['sum'=>0.0, 'count'=>0];
            if ( ! isset($label_totals[$label]) ) $label_totals[$label] = ['sum'=>0.0, 'count'=>0];
            if ( $use_series && ! in_array($series, $series_names, true) ) $series_names[] = $series;

            if ( $aggr === 'count' ) {
                $bucket[$label][$series]['count']++;
                $label_totals[$label]['count']++;
                $grand_total++;
            } else {
                $bucket[$label][$series]['sum'] += (float) $value;
                $bucket[$label][$series]['count']++;
                $label_totals[$label]['sum'] += (float) $value;
                $label_totals[$label]['count']++;
                $grand_total += (float) $value;
            }
        }

        $rows = [];
        foreach ( $bucket as $label => $data ) {
            $total_value = eventosapp_custom_metrics_bucket_value($label_totals[$label], $aggr);
            $rows[] = ['label'=>$label, 'value'=>$total_value, 'sort'=>$total_value, 'series'=>$data];
        }
        $rows = eventosapp_custom_metrics_sort_bucket_rows($rows, $sort_by);
        $rows = array_slice($rows, 0, $limit);

        $labels = [];
        $values = [];
        $formatted = [];
        foreach ( $rows as $row ) {
            $labels[] = $row['label'];
            $values[] = $row['value'];
            $formatted[] = eventosapp_custom_metrics_format_metric_cell($row['value'], $format, $pct_mode, $grand_total);
        }

        $payload = array_merge($base_payload, [
            'labels'            => $labels,
            'values'            => $values,
            'formatted_values'  => $formatted,
            'label_title'       => isset($field_map[$label_key]) ? $field_map[$label_key]['label'] : 'Etiqueta',
            'value_title'       => $aggr === 'count' ? 'Registros' : ( isset($field_map[$value_key]) ? $field_map[$value_key]['label'] : 'Valor' ),
            'percentage_mode'   => $pct_mode,
            'value_format'      => $format,
            'empty'             => empty($labels),
        ]);

        if ( $use_series ) {
            natcasesort($series_names);
            $series_names = array_values($series_names);
            $datasets = [];
            foreach ( $series_names as $series ) {
                $data = [];
                foreach ( $rows as $row ) {
                    $data[] = isset($row['series'][$series]) ? eventosapp_custom_metrics_bucket_value($row['series'][$series], $aggr) : 0;
                }
                $datasets[] = ['label'=>$series, 'data'=>$data];
            }
            $payload['datasets'] = $datasets;
            $payload['series_title'] = isset($field_map[$series_key]) ? $field_map[$series_key]['label'] : 'Serie';
        }

        return $payload;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_build_slot_payload') ) {
    function eventosapp_custom_metrics_build_slot_payload($event_id, $slot, $records, $field_map, $slot_index){
        if ( empty($slot['enabled']) ) return ['empty' => true];

        $chart_type = isset($slot['chart_type']) ? (string) $slot['chart_type'] : 'column';
        $title      = isset($slot['title']) && $slot['title'] !== '' ? (string) $slot['title'] : 'Métrica personalizada';

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

        if ( $chart_type === 'table' ) return eventosapp_custom_metrics_build_table_payload($base_payload, $slot, $records, $field_map);
        if ( $chart_type === 'number_card' ) return eventosapp_custom_metrics_build_card_payload($base_payload, $slot, $records, $field_map);
        return eventosapp_custom_metrics_build_group_chart_payload($base_payload, $slot, $records, $field_map);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_payload') ) {
    function eventosapp_custom_metrics_get_payload($event_id){
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) {
            return [
                'settings'    => eventosapp_custom_metrics_default_settings(),
                'rows'        => [],
                'has_metrics' => false,
            ];
        }

        $layout = eventosapp_custom_metrics_get_layout_config($event_id);
        $enabled_slots = eventosapp_custom_metrics_get_enabled_slots($layout);
        if ( empty($enabled_slots) ) {
            return [
                'settings'    => isset($layout['settings']) ? $layout['settings'] : eventosapp_custom_metrics_default_settings(),
                'rows'        => [],
                'has_metrics' => false,
            ];
        }

        $field_map = eventosapp_custom_metrics_get_field_map($event_id);
        $required_field_keys = eventosapp_custom_metrics_get_required_field_keys_from_layout($layout);
        $records = null;
        $rows_out = [];
        $slot_index = 0;
        $has_metrics = false;

        foreach ( (array) $layout['rows'] as $row ) {
            $row_slots = [];
            foreach ( (array) $row['slots'] as $slot ) {
                $slot_index++;
                if ( empty($slot['enabled']) ) continue;
                if ( $records === null ) $records = eventosapp_custom_metrics_get_ticket_records($event_id, $required_field_keys);
                $payload = eventosapp_custom_metrics_build_slot_payload($event_id, $slot, $records, $field_map, $slot_index);
                if ( ! empty($payload) ) {
                    $row_slots[] = $payload;
                    $has_metrics = true;
                }
            }
            if ( ! empty($row_slots) ) $rows_out[] = ['slots' => $row_slots];
        }

        return [
            'settings'    => isset($layout['settings']) ? $layout['settings'] : eventosapp_custom_metrics_default_settings(),
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
        $formats = eventosapp_custom_metrics_value_format_options();
        $percentage_options = eventosapp_custom_metrics_percentage_options();
        ?>
        <style>
            .evapp-cmetrics-wrap{border:1px solid #dcdcde;border-radius:12px;background:#fff;padding:14px;margin-top:6px}
            .evapp-cmetrics-help{margin:0 0 14px;color:#50575e;line-height:1.45}
            .evapp-cmetrics-section{border:1px solid #e5e7eb;border-radius:12px;background:#f8fafc;margin:0 0 14px;padding:12px}
            .evapp-cmetrics-section-title{font-weight:800;color:#1d2327;margin:0 0 10px}
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
            .evapp-cmetrics-field input[type="color"],
            .evapp-cmetrics-field select{width:100%;max-width:100%}
            .evapp-cmetrics-checks{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:8px}
            .evapp-cmetrics-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}
            .evapp-cmetrics-note{font-size:12px;color:#646970;margin-top:4px;line-height:1.35}
            .evapp-cmetrics-type-note{grid-column:1/-1;padding:8px 10px;background:#eef6ff;border:1px solid #bfdbfe;border-radius:8px;color:#1e3a8a;font-size:12px;line-height:1.45}
            @media(max-width:900px){.evapp-cmetrics-slots,.evapp-cmetrics-grid{grid-template-columns:1fr}}
        </style>

        <div class="evapp-cmetrics-wrap" id="evappCustomMetricsBuilder">
            <p class="evapp-cmetrics-help">
                Configura aquí las métricas adicionales para este evento. Cada fila puede tener uno o dos bloques. Los campos disponibles incluyen los datos base del ticket y los campos adicionales definidos para este evento.
            </p>
            <input type="hidden" id="evapp_custom_metrics_json" name="evapp_custom_metrics_json" value="<?php echo esc_attr(wp_json_encode($layout)); ?>">

            <div class="evapp-cmetrics-section">
                <div class="evapp-cmetrics-section-title">Encabezado de métricas personalizadas</div>
                <div class="evapp-cmetrics-grid">
                    <div class="evapp-cmetrics-field">
                        <label>Mostrar encabezado</label>
                        <label style="font-weight:400"><input type="checkbox" id="evapp-cmetrics-show-header"> Mostrar el título antes de los gráficos personalizados</label>
                    </div>
                    <div class="evapp-cmetrics-field">
                        <label>Color del encabezado</label>
                        <input type="color" id="evapp-cmetrics-header-color">
                    </div>
                    <div class="evapp-cmetrics-field" style="grid-column:1/-1">
                        <label>Texto del encabezado</label>
                        <input type="text" id="evapp-cmetrics-header-text" placeholder="Métricas personalizadas">
                    </div>
                </div>
            </div>

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
            const valueFormats = <?php echo wp_json_encode($formats); ?>;
            const percentageOptions = <?php echo wp_json_encode($percentage_options); ?>;
            let state = <?php echo wp_json_encode($layout); ?>;

            function defaultSettings(){
                return { show_header:true, header_text:'Métricas personalizadas', header_color:'#eaf1ff' };
            }

            function defaultSlot(){
                return {
                    enabled:false,
                    title:'',
                    chart_type:'column',
                    span:1,
                    row_field:'localidad',
                    column_field:'checked_in_any',
                    label_field:'localidad',
                    series_field:'',
                    value_field:'',
                    aggregation:'count',
                    sort_by:'value_desc',
                    limit:10,
                    value_format:'integer',
                    percentage_mode:'none',
                    table_include_totals:true,
                    show_legend:true,
                    show_data_labels:false,
                    table_fields:['nombre','apellido','email','localidad','checked_in_any']
                };
            }

            function normalizeState(){
                if (!state || typeof state !== 'object') state = {};
                state.settings = Object.assign(defaultSettings(), state.settings || {});
                if (!Array.isArray(state.rows)) state.rows = [];
                if (!state.rows.length) state.rows.push({slots:[defaultSlot(), defaultSlot()]});
                state.rows = state.rows.map(function(row){
                    let slots = row && Array.isArray(row.slots) ? row.slots : [];
                    if (!slots[0]) slots[0] = defaultSlot();
                    if (!slots[1]) slots[1] = defaultSlot();
                    slots[0].row_field = normalizeFieldKey(slots[0].row_field || slots[0].label_field || 'localidad');
                    slots[1].row_field = normalizeFieldKey(slots[1].row_field || slots[1].label_field || 'localidad');
                    slots[0].column_field = normalizeFieldKey(slots[0].column_field || 'checked_in_any');
                    slots[1].column_field = normalizeFieldKey(slots[1].column_field || 'checked_in_any');
                    slots[0].label_field = normalizeFieldKey(slots[0].label_field || slots[0].row_field || 'localidad');
                    slots[1].label_field = normalizeFieldKey(slots[1].label_field || slots[1].row_field || 'localidad');
                    slots[0].series_field = normalizeFieldKey(slots[0].series_field || '');
                    slots[1].series_field = normalizeFieldKey(slots[1].series_field || '');
                    slots[0].value_field = normalizeFieldKey(slots[0].value_field || '');
                    slots[1].value_field = normalizeFieldKey(slots[1].value_field || '');
                    if (Array.isArray(slots[0].table_fields)) slots[0].table_fields = slots[0].table_fields.map(normalizeFieldKey).filter(Boolean);
                    if (Array.isArray(slots[1].table_fields)) slots[1].table_fields = slots[1].table_fields.map(normalizeFieldKey).filter(Boolean);
                    return {slots:[Object.assign(defaultSlot(), slots[0]), Object.assign(defaultSlot(), slots[1])]};
                });
            }

            function esc(s){
                return String(s == null ? '' : s)
                    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
            }

            function optionsFromMap(map, selected){
                let html = '';
                Object.keys(map).forEach(function(key){
                    html += '<option value="'+esc(key)+'" '+(String(selected)===String(key)?'selected':'')+'>'+esc(map[key])+'</option>';
                });
                return html;
            }

            function normalizeFieldKey(key){
                key = String(key == null ? '' : key).toLowerCase().replace(/[^a-z0-9_\-]/g, '');
                const aliases = {
                    '_eventosapp_ticket_modalidad':'modalidad',
                    'eventosapp_ticket_modalidad':'modalidad',
                    'ticket_modalidad':'modalidad',
                    'modalidad_ticket':'modalidad',
                    'modalidad_del_ticket':'modalidad',
                    'tipo_modalidad':'modalidad'
                };
                return aliases[key] || key;
            }

            function hasFieldKey(key){
                key = normalizeFieldKey(key);
                if (!key) return false;
                return fields.some(function(f){ return String(f.key) === String(key); });
            }

            function fieldOptions(selected, emptyLabel){
                selected = normalizeFieldKey(selected);
                let html = emptyLabel ? '<option value="">'+esc(emptyLabel)+'</option>' : '';
                if (selected && !hasFieldKey(selected)) {
                    html += '<option value="'+esc(selected)+'" selected>Campo no disponible: '+esc(selected)+'</option>';
                }
                fields.forEach(function(f){
                    html += '<option value="'+esc(f.key)+'" '+(String(selected)===String(f.key)?'selected':'')+'>'+esc(f.label)+'</option>';
                });
                return html;
            }

            function syncHeaderControls(){
                $('#evapp-cmetrics-show-header').prop('checked', !!state.settings.show_header);
                $('#evapp-cmetrics-header-text').val(state.settings.header_text || 'Métricas personalizadas');
                $('#evapp-cmetrics-header-color').val(state.settings.header_color || '#eaf1ff');
            }

            function syncHidden(){
                $('#evapp_custom_metrics_json').val(JSON.stringify(state));
            }

            function render(){
                normalizeState();
                syncHeaderControls();
                const $rows = $('#evapp-cmetrics-rows').empty();
                state.rows.forEach(function(row, rIndex){
                    let rowHtml = '<div class="evapp-cmetrics-row" data-row="'+rIndex+'">'
                        + '<div class="evapp-cmetrics-row-head">'
                        + '<div class="evapp-cmetrics-row-title">Fila '+(rIndex+1)+'</div>'
                        + '<button type="button" class="button evapp-cmetrics-remove-row">Eliminar fila</button>'
                        + '</div>'
                        + '<div class="evapp-cmetrics-slots">';
                    row.slots.forEach(function(slot, sIndex){ rowHtml += renderSlot(rIndex, sIndex, slot); });
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
                    + '<label class="evapp-cmetrics-legend-check"><input type="checkbox" class="evapp-cmetrics-control" data-prop="show_legend" '+(slot.show_legend?'checked':'')+'> Mostrar leyenda</label>'
                    + '<label class="evapp-cmetrics-values-check"><input type="checkbox" class="evapp-cmetrics-control" data-prop="show_data_labels" '+(slot.show_data_labels?'checked':'')+'> Mostrar valores</label>'
                    + '<label class="evapp-cmetrics-total-check"><input type="checkbox" class="evapp-cmetrics-control" data-prop="table_include_totals" '+(slot.table_include_totals?'checked':'')+'> Mostrar totales</label>'
                    + '</div>'
                    + '<div class="evapp-cmetrics-grid" style="margin-top:10px">'
                    + '<div class="evapp-cmetrics-field"><label>Título</label><input type="text" class="evapp-cmetrics-control" data-prop="title" value="'+esc(slot.title)+'"></div>'
                    + '<div class="evapp-cmetrics-field"><label>Tipo</label><select class="evapp-cmetrics-control evapp-cmetrics-type" data-prop="chart_type">'+optionsFromMap(chartTypes, slot.chart_type)+'</select></div>'
                    + '<div class="evapp-cmetrics-type-note evapp-cmetrics-note-table">Tabla dinámica: selecciona fila, columna, campo de valores, tipo de cálculo y porcentajes.</div>'
                    + '<div class="evapp-cmetrics-type-note evapp-cmetrics-note-card">Tarjeta: muestra un único número calculado con conteo, suma o promedio.</div>'
                    + '<div class="evapp-cmetrics-type-note evapp-cmetrics-note-column">Columnas: selecciona eje/categoría y opcionalmente una serie para comparar grupos.</div>'
                    + '<div class="evapp-cmetrics-type-note evapp-cmetrics-note-pie">Torta: selecciona una categoría para distribuir el total por segmentos.</div>'
                    + '<div class="evapp-cmetrics-field"><label>Ancho en la fila</label><select class="evapp-cmetrics-control" data-prop="span"><option value="1" '+(parseInt(slot.span,10)!==2?'selected':'')+'>Mitad de fila</option><option value="2" '+(parseInt(slot.span,10)===2?'selected':'')+'>Fila completa</option></select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-limit"><label>Límite de resultados</label><input type="number" min="1" max="500" class="evapp-cmetrics-control" data-prop="limit" value="'+esc(slot.limit || 10)+'"></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-row"><label>Campo de fila</label><select class="evapp-cmetrics-control" data-prop="row_field">'+fieldOptions(slot.row_field, 'Selecciona campo')+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-column"><label>Campo de columna</label><select class="evapp-cmetrics-control" data-prop="column_field">'+fieldOptions(slot.column_field, 'Selecciona campo')+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-label"><label>Eje / categoría</label><select class="evapp-cmetrics-control" data-prop="label_field">'+fieldOptions(slot.label_field, 'Selecciona campo')+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-series"><label>Series / columnas opcional</label><select class="evapp-cmetrics-control" data-prop="series_field">'+fieldOptions(slot.series_field, 'Sin series')+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-value"><label>Campo de valores</label><select class="evapp-cmetrics-control" data-prop="value_field">'+fieldOptions(slot.value_field, 'No aplica / selecciona campo')+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-aggregation"><label>Cálculo</label><select class="evapp-cmetrics-control" data-prop="aggregation">'+optionsFromMap(aggregations, slot.aggregation)+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-format"><label>Formato del valor</label><select class="evapp-cmetrics-control" data-prop="value_format">'+optionsFromMap(valueFormats, slot.value_format)+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-percentage"><label>Porcentajes</label><select class="evapp-cmetrics-control" data-prop="percentage_mode">'+optionsFromMap(percentageOptions, slot.percentage_mode)+'</select></div>'
                    + '<div class="evapp-cmetrics-field evapp-cmetrics-field-sort"><label>Orden</label><select class="evapp-cmetrics-control" data-prop="sort_by">'+optionsFromMap(sortOptions, slot.sort_by)+'</select></div>'
                    + '</div>'
                    + '</div>';
            }

            function refreshSlotVisibility(){
                $('.evapp-cmetrics-slot').each(function(){
                    const $slot = $(this);
                    const type = $slot.find('[data-prop="chart_type"]').val();
                    const aggr = $slot.find('[data-prop="aggregation"]').val();
                    $slot.toggleClass('is-disabled', !$slot.find('[data-prop="enabled"]').is(':checked'));

                    $slot.find('.evapp-cmetrics-note-table').toggle(type === 'table');
                    $slot.find('.evapp-cmetrics-note-card').toggle(type === 'number_card');
                    $slot.find('.evapp-cmetrics-note-column').toggle(type === 'column');
                    $slot.find('.evapp-cmetrics-note-pie').toggle(type === 'pie');

                    $slot.find('.evapp-cmetrics-field-row').toggle(type === 'table');
                    $slot.find('.evapp-cmetrics-field-column').toggle(type === 'table');
                    $slot.find('.evapp-cmetrics-field-label').toggle(type === 'column' || type === 'pie');
                    $slot.find('.evapp-cmetrics-field-series').toggle(type === 'column');
                    $slot.find('.evapp-cmetrics-field-value').toggle(type === 'table' || type === 'number_card' || aggr !== 'count');
                    $slot.find('.evapp-cmetrics-field-aggregation').toggle(true);
                    $slot.find('.evapp-cmetrics-field-format').toggle(type === 'table' || type === 'number_card' || aggr !== 'count');
                    $slot.find('.evapp-cmetrics-field-percentage').toggle(type === 'table' || type === 'column' || type === 'pie');
                    $slot.find('.evapp-cmetrics-field-sort').toggle(type !== 'number_card');
                    $slot.find('.evapp-cmetrics-field-limit').toggle(type !== 'number_card');
                    $slot.find('.evapp-cmetrics-total-check').toggle(type === 'table');
                    $slot.find('.evapp-cmetrics-legend-check').toggle(type === 'column' || type === 'pie');
                });
            }

            $(document).on('change input', '.evapp-cmetrics-control', function(){
                const $control = $(this);
                const $slot = $control.closest('.evapp-cmetrics-slot');
                const rowIndex = parseInt($slot.attr('data-row'), 10);
                const slotIndex = parseInt($slot.attr('data-slot'), 10);
                const prop = $control.attr('data-prop');
                let value;

                if ($control.attr('type') === 'checkbox') value = $control.is(':checked');
                else value = $control.val();

                if (prop === 'span' || prop === 'limit') value = parseInt(value || 0, 10);
                if (['row_field','column_field','label_field','series_field','value_field'].indexOf(prop) !== -1) value = normalizeFieldKey(value);
                if (prop === 'row_field') state.rows[rowIndex].slots[slotIndex].label_field = value;
                state.rows[rowIndex].slots[slotIndex][prop] = value;
                syncHidden();
                refreshSlotVisibility();
            });

            $('#evapp-cmetrics-show-header').on('change', function(){
                state.settings.show_header = $(this).is(':checked');
                syncHidden();
            });
            $('#evapp-cmetrics-header-text').on('input', function(){
                state.settings.header_text = $(this).val();
                syncHidden();
            });
            $('#evapp-cmetrics-header-color').on('input change', function(){
                state.settings.header_color = $(this).val() || '#eaf1ff';
                syncHidden();
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
    if ( ! is_array($decoded) ) $decoded = eventosapp_custom_metrics_default_layout();

    $layout = eventosapp_custom_metrics_sanitize_layout_config($decoded);
    update_post_meta($post_id, EVAPP_CUSTOM_METRICS_META_KEY, $layout);
}, 20, 2);
