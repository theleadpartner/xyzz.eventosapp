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


if ( ! function_exists('eventosapp_custom_metrics_filter_relation_options') ) {
    function eventosapp_custom_metrics_filter_relation_options(){
        return [
            'all' => 'Cumplir todos los filtros (Y)',
            'any' => 'Cumplir al menos un filtro (O)',
        ];
    }
}

if ( ! function_exists('eventosapp_custom_metrics_filter_operator_groups') ) {
    function eventosapp_custom_metrics_filter_operator_groups(){
        return [
            'text' => [
                'equals'       => 'Es igual a',
                'not_equals'   => 'No es igual a',
                'contains'     => 'Contiene',
                'not_contains' => 'No contiene',
                'starts_with'  => 'Comienza por',
                'ends_with'    => 'Termina en',
                'is_empty'     => 'Está vacío',
                'is_not_empty' => 'No está vacío',
            ],
            'options' => [
                'equals'       => 'Es igual a',
                'not_equals'   => 'No es igual a',
                'contains'     => 'Contiene la opción',
                'not_contains' => 'No contiene la opción',
                'is_empty'     => 'Está vacío',
                'is_not_empty' => 'No está vacío',
            ],
            'number' => [
                'equals'           => 'Es igual a',
                'not_equals'       => 'No es igual a',
                'greater_than'     => 'Es mayor que',
                'greater_or_equal' => 'Es mayor o igual que',
                'less_than'        => 'Es menor que',
                'less_or_equal'    => 'Es menor o igual que',
                'is_empty'         => 'Está vacío',
                'is_not_empty'     => 'No está vacío',
            ],
            'date' => [
                'equals'           => 'Es la fecha',
                'not_equals'       => 'No es la fecha',
                'before'           => 'Es anterior a',
                'on_or_before'     => 'Es igual o anterior a',
                'after'            => 'Es posterior a',
                'on_or_after'      => 'Es igual o posterior a',
                'is_empty'         => 'Está vacío',
                'is_not_empty'     => 'No está vacío',
            ],
        ];
    }
}

if ( ! function_exists('eventosapp_custom_metrics_filter_operators_flat') ) {
    function eventosapp_custom_metrics_filter_operators_flat(){
        $operators = [];
        foreach ( eventosapp_custom_metrics_filter_operator_groups() as $group ) {
            foreach ( array_keys($group) as $operator ) $operators[$operator] = true;
        }
        return array_keys($operators);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_prepare_filter_options') ) {
    function eventosapp_custom_metrics_prepare_filter_options($options, $labels_as_values = false){
        if ( ! is_array($options) ) return [];

        $out = [];
        $used = [];
        foreach ( $options as $key => $item ) {
            if ( is_array($item) ) {
                $value = isset($item['value']) && is_scalar($item['value']) ? (string) $item['value'] : '';
                $label = isset($item['label']) && is_scalar($item['label']) ? (string) $item['label'] : $value;
            } elseif ( is_scalar($item) ) {
                $label = trim(wp_strip_all_tags((string) $item));
                $value = is_int($key) || $labels_as_values ? $label : (string) $key;
            } else {
                continue;
            }

            $value = sanitize_text_field($value);
            $label = sanitize_text_field($label);
            if ( $label === '' ) $label = $value;
            if ( $value === '' || isset($used[$value]) ) continue;

            $out[] = ['value' => $value, 'label' => $label];
            $used[$value] = true;
        }

        return $out;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_event_locality_filter_options') ) {
    function eventosapp_custom_metrics_get_event_locality_filter_options($event_id){
        $raw = get_post_meta((int) $event_id, '_eventosapp_localidades', true);
        if ( ! is_array($raw) || empty($raw) ) $raw = ['General', 'VIP', 'Platino'];

        $labels = [];
        foreach ( $raw as $item ) {
            $label = '';
            if ( is_scalar($item) ) {
                $label = (string) $item;
            } elseif ( is_array($item) ) {
                foreach ( ['label', 'nombre', 'name', 'title', 'value', 'localidad'] as $candidate ) {
                    if ( isset($item[$candidate]) && is_scalar($item[$candidate]) ) {
                        $label = (string) $item[$candidate];
                        break;
                    }
                }
            }
            $label = sanitize_text_field(trim($label));
            if ( $label !== '' ) $labels[] = $label;
        }

        return eventosapp_custom_metrics_prepare_filter_options(array_values(array_unique($labels)));
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
            'filter_relation'      => 'all',
            'filters'              => [],
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
            '_eventosapp_attendance_confirmation_status' => 'attendance_confirmation_status',
            'confirmation_status'            => 'attendance_confirmation_status',
            'confirmacion_asistencia'         => 'attendance_confirmation_status',
            'estado_confirmacion_asistencia'  => 'attendance_confirmation_status',
            '_eventosapp_attendance_confirmation_sent_channels' => 'attendance_confirmation_sent_channels',
            '_eventosapp_attendance_confirmation_response_channels' => 'attendance_confirmation_response_channels',
            '_eventosapp_attendance_confirmation_last_response_channel' => 'attendance_confirmation_last_response_channel',
            '_eventosapp_attendance_confirmation_last_response_at' => 'attendance_confirmation_last_response_at',
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

        $countries = function_exists('eventosapp_get_countries') ? eventosapp_get_countries() : [];
        $country_options = eventosapp_custom_metrics_prepare_filter_options(is_array($countries) ? $countries : []);
        $locality_options = $event_id > 0 ? eventosapp_custom_metrics_get_event_locality_filter_options($event_id) : [];

        $ticket_modalities = function_exists('eventosapp_ticket_modalidad_options')
            ? eventosapp_ticket_modalidad_options()
            : ['presencial' => 'Presencial', 'virtual' => 'Virtual'];
        if ( $event_id > 0 && function_exists('eventosapp_ticket_allowed_modalidades_for_event') ) {
            $allowed_modalities = eventosapp_ticket_allowed_modalidades_for_event($event_id);
            $ticket_modalities = array_intersect_key($ticket_modalities, array_fill_keys((array) $allowed_modalities, true));
        }
        $modality_options = eventosapp_custom_metrics_prepare_filter_options($ticket_modalities, true);

        $payment_options = eventosapp_custom_metrics_prepare_filter_options([
            'no_pagado' => 'No Pagado',
            'pagado'    => 'Pagado',
        ]);

        $attendance_statuses = function_exists('eventosapp_attendance_confirmation_status_options')
            ? eventosapp_attendance_confirmation_status_options()
            : ['si'=>'Sí','no'=>'No','no_responde'=>'No responde','sin_consulta'=>'Sin consulta'];
        $attendance_status_options = eventosapp_custom_metrics_prepare_filter_options($attendance_statuses, true);

        $attendance_channels = function_exists('eventosapp_attendance_confirmation_channel_options')
            ? eventosapp_attendance_confirmation_channel_options()
            : ['email'=>'Correo electrónico','whatsapp'=>'WhatsApp'];
        $attendance_channel_options = eventosapp_custom_metrics_prepare_filter_options($attendance_channels, true);

        $yes_no_options = eventosapp_custom_metrics_prepare_filter_options(['Sí', 'No']);

        $fields = [
            [ 'key'=>'ticket_public_id', 'label'=>'Ticket Public ID', 'type'=>'text', 'source'=>'system', 'meta_key'=>'eventosapp_ticketID' ],
            [ 'key'=>'ticket_post_id', 'label'=>'Ticket Post ID', 'type'=>'number', 'source'=>'computed' ],
            [ 'key'=>'ticket_user_id', 'label'=>'Usuario WordPress ID', 'type'=>'number', 'source'=>'system', 'meta_key'=>'_eventosapp_ticket_user_id' ],
            [ 'key'=>'ticket_preprinted_id', 'label'=>'QR preimpreso ID', 'type'=>'number', 'source'=>'system', 'meta_key'=>'eventosapp_ticket_preprintedID' ],
            [ 'key'=>'secuencia', 'label'=>'Secuencia interna', 'type'=>'number', 'source'=>'system', 'meta_key'=>'_eventosapp_ticket_seq' ],
            [ 'key'=>'nombre', 'label'=>'Nombre', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_nombre' ],
            [ 'key'=>'apellido', 'label'=>'Apellido', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_apellido' ],
            [ 'key'=>'cc', 'label'=>'Identificación / Pasaporte', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_cc' ],
            [ 'key'=>'email', 'label'=>'Email', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_email' ],
            [ 'key'=>'telefono', 'label'=>'Teléfono', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_tel' ],
            [ 'key'=>'empresa', 'label'=>'Empresa', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_empresa' ],
            [ 'key'=>'nit', 'label'=>'NIT', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_nit' ],
            [ 'key'=>'cargo', 'label'=>'Cargo', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_cargo' ],
            [ 'key'=>'ciudad', 'label'=>'Ciudad', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_ciudad' ],
            [ 'key'=>'pais', 'label'=>'País', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_pais', 'filter_options'=>$country_options ],
            [ 'key'=>'localidad', 'label'=>'Localidad', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_asistente_localidad', 'filter_options'=>$locality_options ],
            [ 'key'=>'modalidad', 'label'=>'Modalidad', 'type'=>'text', 'source'=>'computed', 'filter_options'=>$modality_options ],
            [ 'key'=>'estado_pago', 'label'=>'Estado de pago', 'type'=>'text', 'source'=>'system', 'meta_key'=>'_eventosapp_estado_pago', 'filter_options'=>$payment_options ],
            [ 'key'=>'attendance_confirmation_status', 'label'=>'Confirmación de asistencia', 'type'=>'text', 'source'=>'computed', 'meta_key'=>'_eventosapp_attendance_confirmation_status', 'filter_options'=>$attendance_status_options ],
            [ 'key'=>'attendance_confirmation_sent_channels', 'label'=>'Canales consultados para confirmación', 'type'=>'text', 'source'=>'computed', 'meta_key'=>'_eventosapp_attendance_confirmation_sent_channels', 'filter_options'=>$attendance_channel_options, 'filter_multiple'=>true ],
            [ 'key'=>'attendance_confirmation_response_channels', 'label'=>'Canales de respuesta de confirmación', 'type'=>'text', 'source'=>'computed', 'meta_key'=>'_eventosapp_attendance_confirmation_response_channels', 'filter_options'=>$attendance_channel_options, 'filter_multiple'=>true ],
            [ 'key'=>'attendance_confirmation_last_response_channel', 'label'=>'Último canal de respuesta', 'type'=>'text', 'source'=>'computed', 'meta_key'=>'_eventosapp_attendance_confirmation_last_response_channel', 'filter_options'=>$attendance_channel_options ],
            [ 'key'=>'attendance_confirmation_last_response_at', 'label'=>'Fecha de última respuesta de confirmación', 'type'=>'date', 'source'=>'system', 'meta_key'=>'_eventosapp_attendance_confirmation_last_response_at' ],
            [ 'key'=>'checked_in_any', 'label'=>'Checked-In (algún día)', 'type'=>'text', 'source'=>'computed', 'filter_options'=>$yes_no_options ],
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
                    $extra_options = $extra_type === 'select'
                        ? eventosapp_custom_metrics_prepare_filter_options(isset($extra['options']) ? $extra['options'] : [])
                        : [];

                    $fields[] = [
                        'key'            => 'extra_' . $extra_key,
                        'label'          => 'Extra: ' . ( isset($extra['label']) ? sanitize_text_field($extra['label']) : $extra_key ),
                        'type'           => $extra_type === 'number' ? 'number' : 'text',
                        'source'         => 'extra',
                        'extra_key'      => $extra_key,
                        'meta_key'       => '_eventosapp_extra_' . $extra_key,
                        'filter_options' => $extra_options,
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
            $field['filter_options'] = isset($field['filter_options'])
                ? eventosapp_custom_metrics_prepare_filter_options($field['filter_options'])
                : [];
            $field['filter_multiple'] = ! empty($field['filter_multiple']);
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

if ( ! function_exists('eventosapp_custom_metrics_filter_operator_requires_value') ) {
    function eventosapp_custom_metrics_filter_operator_requires_value($operator){
        return ! in_array(sanitize_key((string) $operator), ['is_empty', 'is_not_empty'], true);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_sanitize_filter') ) {
    function eventosapp_custom_metrics_sanitize_filter($filter){
        if ( ! is_array($filter) ) return null;

        $field = eventosapp_custom_metrics_normalize_field_key(isset($filter['field']) ? (string) $filter['field'] : '');
        if ( $field === '' ) return null;

        $operator = sanitize_key(isset($filter['operator']) ? (string) $filter['operator'] : 'contains');
        if ( ! in_array($operator, eventosapp_custom_metrics_filter_operators_flat(), true) ) {
            $operator = 'contains';
        }

        $value = '';
        if ( isset($filter['value']) && is_scalar($filter['value']) ) {
            $value = sanitize_text_field((string) $filter['value']);
        }

        if ( eventosapp_custom_metrics_filter_operator_requires_value($operator) && $value === '' ) {
            return null;
        }

        return [
            'field'    => $field,
            'operator' => $operator,
            'value'    => $value,
        ];
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
        $filter_relations            = array_keys(eventosapp_custom_metrics_filter_relation_options());
        $out['filter_relation']      = in_array(isset($slot['filter_relation']) ? $slot['filter_relation'] : '', $filter_relations, true) ? $slot['filter_relation'] : 'all';
        $out['filters']              = [];
        if ( isset($slot['filters']) && is_array($slot['filters']) ) {
            foreach ( array_slice(array_values($slot['filters']), 0, 30) as $filter ) {
                $filter = eventosapp_custom_metrics_sanitize_filter($filter);
                if ( $filter ) $out['filters'][] = $filter;
            }
        }
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

            if ( ! empty($slot['filters']) && is_array($slot['filters']) ) {
                foreach ( $slot['filters'] as $filter ) {
                    if ( is_array($filter) ) $add_key(isset($filter['field']) ? $filter['field'] : '');
                }
            }

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
    function eventosapp_custom_metrics_ticket_checked_in_any($ticket_id, $event_id = 0){
        $ticket_id = (int) $ticket_id;
        $event_id  = (int) $event_id;
        if ( $ticket_id <= 0 ) return 'No';

        if ( $event_id <= 0 ) {
            $event_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
        }

        $status_arr         = get_post_meta($ticket_id, '_eventosapp_checkin_status', true);
        $virtual_status_arr = get_post_meta($ticket_id, '_eventosapp_virtual_checkin_status', true);
        $event_days         = eventosapp_custom_metrics_get_event_days($event_id);

        return eventosapp_custom_metrics_ticket_checked_in_any_from_meta($status_arr, $virtual_status_arr, $event_days);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_ticket_first_qr_label') ) {
    function eventosapp_custom_metrics_ticket_first_qr_label($ticket_id){
        $log = get_post_meta($ticket_id, '_eventosapp_checkin_log', true);
        return eventosapp_custom_metrics_ticket_first_qr_label_from_meta($log);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_extract_ticket_value') ) {
    function eventosapp_custom_metrics_extract_ticket_value($ticket_id, $event_id, $field){
        $key = eventosapp_custom_metrics_normalize_field_key(isset($field['key']) ? (string) $field['key'] : '');

        if ( $key === 'ticket_post_id' ) return (string) $ticket_id;
        if ( $key === 'modalidad' ) return eventosapp_custom_metrics_get_ticket_modalidad_display($ticket_id, $event_id);
        if ( strpos($key, 'attendance_confirmation_') === 0 ) {
            if ( function_exists('eventosapp_attendance_confirmation_get_ticket_field_value') ) {
                return eventosapp_attendance_confirmation_get_ticket_field_value($ticket_id, $key, true);
            }
            if ( $key === 'attendance_confirmation_status' ) {
                $raw = sanitize_key((string)get_post_meta($ticket_id, '_eventosapp_attendance_confirmation_status', true));
                $labels = ['si'=>'Sí','no'=>'No','no_responde'=>'No responde','sin_consulta'=>'Sin consulta'];
                return $labels[$raw] ?? 'Sin consulta';
            }
        }
        if ( $key === 'checked_in_any' ) return eventosapp_custom_metrics_ticket_checked_in_any($ticket_id, $event_id);
        if ( $key === 'medio_checkin' ) return eventosapp_custom_metrics_ticket_first_qr_label($ticket_id);
        if ( $key === 'fecha_creacion' ) return get_post_time('Y-m-d H:i:s', false, $ticket_id);

        $meta_key = isset($field['meta_key']) ? (string) $field['meta_key'] : '';
        if ( $meta_key !== '' ) return get_post_meta($ticket_id, $meta_key, true);
        return '';
    }
}


if ( ! function_exists('eventosapp_custom_metrics_maybe_unserialize') ) {
    function eventosapp_custom_metrics_maybe_unserialize($value){
        if ( is_string($value) ) {
            if ( function_exists('maybe_unserialize') ) {
                return maybe_unserialize($value);
            }
            $maybe = @unserialize($value);
            if ( $maybe !== false || $value === 'b:0;' ) return $maybe;
        }
        return $value;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_array_value') ) {
    function eventosapp_custom_metrics_array_value($value){
        $value = eventosapp_custom_metrics_maybe_unserialize($value);
        return is_array($value) ? $value : [];
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_meta_value_from_map') ) {
    function eventosapp_custom_metrics_get_meta_value_from_map($meta_map, $ticket_id, $meta_key, $default = ''){
        $ticket_id = (int) $ticket_id;
        $meta_key = (string) $meta_key;
        if ( isset($meta_map[$ticket_id]) && array_key_exists($meta_key, $meta_map[$ticket_id]) ) {
            return $meta_map[$ticket_id][$meta_key];
        }
        return $default;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_field_requires_meta_keys') ) {
    function eventosapp_custom_metrics_field_requires_meta_keys($fields){
        $meta_keys = ['_eventosapp_ticket_evento_id'];
        foreach ( (array) $fields as $field ) {
            $key = eventosapp_custom_metrics_normalize_field_key(isset($field['key']) ? (string) $field['key'] : '');
            if ( $key === 'modalidad' ) {
                $meta_keys[] = '_eventosapp_ticket_modalidad';
                continue;
            }
            if ( $key === 'checked_in_any' ) {
                $meta_keys[] = '_eventosapp_checkin_status';
                $meta_keys[] = '_eventosapp_virtual_checkin_status';
                continue;
            }
            if ( $key === 'medio_checkin' ) {
                $meta_keys[] = '_eventosapp_checkin_log';
                continue;
            }
            $meta_key = isset($field['meta_key']) ? (string) $field['meta_key'] : '';
            if ( $meta_key !== '' ) $meta_keys[] = $meta_key;
        }
        return array_values(array_unique(array_filter($meta_keys)));
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_ticket_ids_batch') ) {
    function eventosapp_custom_metrics_get_ticket_ids_batch($event_id, $after_id = 0, $limit = 400){
        global $wpdb;

        $event_id = absint($event_id);
        $after_id = absint($after_id);
        $limit    = max(50, min(1000, (int) $limit));
        if ( ! $event_id || ! $wpdb ) return [];

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
            if ( function_exists('eventosapp_metrics_log_debug') ) {
                eventosapp_metrics_log_debug('custom_metrics_ticket_batch_db_error', [
                    'event_id' => $event_id,
                    'after_id' => $after_id,
                    'error'    => $wpdb->last_error,
                ]);
            }
            throw new RuntimeException('Error consultando tickets por lotes para métricas personalizadas.');
        }

        return array_map('intval', (array) $ids);
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_ticket_post_dates') ) {
    function eventosapp_custom_metrics_get_ticket_post_dates(array $ticket_ids){
        global $wpdb;

        $ticket_ids = array_values(array_unique(array_filter(array_map('intval', $ticket_ids))));
        if ( empty($ticket_ids) || ! $wpdb ) return [];

        $placeholders = implode(',', array_fill(0, count($ticket_ids), '%d'));
        $query = $wpdb->prepare(
            "SELECT ID, post_date_gmt, post_date
             FROM {$wpdb->posts}
             WHERE ID IN ($placeholders)",
            $ticket_ids
        );

        $rows = $wpdb->get_results($query, ARRAY_A);
        if ( ! empty($wpdb->last_error) ) {
            if ( function_exists('eventosapp_metrics_log_debug') ) {
                eventosapp_metrics_log_debug('custom_metrics_post_dates_db_error', [
                    'error' => $wpdb->last_error,
                ]);
            }
            throw new RuntimeException('Error consultando fechas de creación para métricas personalizadas.');
        }

        $map = [];
        foreach ( (array) $rows as $row ) {
            $id = isset($row['ID']) ? (int) $row['ID'] : 0;
            if ( ! $id ) continue;
            $date = ! empty($row['post_date_gmt']) && $row['post_date_gmt'] !== '0000-00-00 00:00:00'
                ? (string) $row['post_date_gmt']
                : (isset($row['post_date']) ? (string) $row['post_date'] : '');
            $map[$id] = $date;
        }

        return $map;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_ticket_meta_map') ) {
    function eventosapp_custom_metrics_get_ticket_meta_map(array $ticket_ids, array $meta_keys){
        global $wpdb;

        $ticket_ids = array_values(array_unique(array_filter(array_map('intval', $ticket_ids))));
        $meta_keys  = array_values(array_unique(array_filter(array_map('strval', $meta_keys))));
        $map = [];
        foreach ( $ticket_ids as $ticket_id ) $map[$ticket_id] = [];

        if ( empty($ticket_ids) || empty($meta_keys) || ! $wpdb ) return $map;

        $id_placeholders  = implode(',', array_fill(0, count($ticket_ids), '%d'));
        $key_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));

        $query = $wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
             FROM {$wpdb->postmeta}
             WHERE post_id IN ($id_placeholders)
               AND meta_key IN ($key_placeholders)
             ORDER BY post_id ASC, meta_id ASC",
            array_merge($ticket_ids, $meta_keys)
        );

        $rows = $wpdb->get_results($query, ARRAY_A);
        if ( ! empty($wpdb->last_error) ) {
            if ( function_exists('eventosapp_metrics_log_debug') ) {
                eventosapp_metrics_log_debug('custom_metrics_meta_batch_db_error', [
                    'error' => $wpdb->last_error,
                ]);
            }
            throw new RuntimeException('Error consultando metadatos por lotes para métricas personalizadas.');
        }

        foreach ( (array) $rows as $row ) {
            $ticket_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;
            $meta_key = isset($row['meta_key']) ? (string) $row['meta_key'] : '';
            if ( ! $ticket_id || $meta_key === '' || ! isset($map[$ticket_id]) ) continue;
            if ( ! array_key_exists($meta_key, $map[$ticket_id]) ) {
                $map[$ticket_id][$meta_key] = eventosapp_custom_metrics_maybe_unserialize($row['meta_value']);
            }
        }

        return $map;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_event_days') ) {
    function eventosapp_custom_metrics_get_event_days($event_id){
        $event_id = (int) $event_id;
        if ( $event_id <= 0 || ! function_exists('eventosapp_get_event_days') ) return [];

        $days = eventosapp_get_event_days($event_id);
        if ( ! is_array($days) ) return [];

        $valid_days = [];
        foreach ( $days as $day ) {
            if ( is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ) {
                $valid_days[] = $day;
            }
        }

        return array_values(array_unique($valid_days));
    }
}

if ( ! function_exists('eventosapp_custom_metrics_status_array_has_checked_in') ) {
    function eventosapp_custom_metrics_status_array_has_checked_in($status_arr, $event_days = []){
        $status_arr = eventosapp_custom_metrics_array_value($status_arr);
        if ( empty($status_arr) ) return false;

        $event_days_lookup = [];
        if ( is_array($event_days) ) {
            foreach ( $event_days as $day ) {
                if ( is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ) {
                    $event_days_lookup[$day] = true;
                }
            }
        }

        foreach ( $status_arr as $status_day => $status_value ) {
            if ( ! in_array((string) $status_value, ['checked_in', 'checked-in'], true) ) {
                continue;
            }

            // Cuando conocemos las fechas reales del evento, solo cuenta el check-in
            // guardado para uno de esos días. Esto evita que estados antiguos o logs
            // de accesos digitales inflen el campo "Checked-In (algún día)".
            if ( ! empty($event_days_lookup) ) {
                $status_day = is_scalar($status_day) ? (string) $status_day : '';
                if ( isset($event_days_lookup[$status_day]) ) {
                    return true;
                }
                continue;
            }

            return true;
        }

        return false;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_checkin_log_has_successful_any_checkin') ) {
    function eventosapp_custom_metrics_checkin_log_has_successful_any_checkin($log, $event_days = []){
        $log = eventosapp_custom_metrics_array_value($log);
        if ( empty($log) ) return false;

        $event_days_lookup = [];
        if ( is_array($event_days) ) {
            foreach ( $event_days as $day ) {
                if ( is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ) {
                    $event_days_lookup[$day] = true;
                }
            }
        }

        foreach ( $log as $entry ) {
            if ( ! is_array($entry) ) continue;

            $status = isset($entry['status']) ? (string) $entry['status'] : '';
            if ( ! in_array($status, ['checked_in', 'checked-in', 'virtual_checked_in'], true) ) {
                continue;
            }

            if ( ! empty($event_days_lookup) ) {
                $entry_day = '';
                if ( isset($entry['dia']) && is_scalar($entry['dia']) && (string) $entry['dia'] !== '' ) {
                    $entry_day = (string) $entry['dia'];
                } elseif ( isset($entry['fecha']) && is_scalar($entry['fecha']) ) {
                    $entry_day = (string) $entry['fecha'];
                }

                if ( ! isset($event_days_lookup[$entry_day]) ) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_ticket_checked_in_any_from_meta') ) {
    function eventosapp_custom_metrics_ticket_checked_in_any_from_meta($status_arr, $virtual_status_arr = [], $event_days = []){
        $has_presencial = eventosapp_custom_metrics_status_array_has_checked_in($status_arr, $event_days);
        $has_virtual    = eventosapp_custom_metrics_status_array_has_checked_in($virtual_status_arr, $event_days);

        return ( $has_presencial || $has_virtual ) ? 'Sí' : 'No';
    }
}

if ( ! function_exists('eventosapp_custom_metrics_ticket_first_qr_label_from_meta') ) {
    function eventosapp_custom_metrics_ticket_first_qr_label_from_meta($log){
        $log = eventosapp_custom_metrics_array_value($log);
        foreach ( $log as $entry ) {
            if ( ! is_array($entry) ) continue;
            $status = isset($entry['status']) ? (string) $entry['status'] : '';
            if ( $status === 'checked_in' || $status === 'checked-in' || $status === 'virtual_checked_in' ) {
                if ( isset($entry['qr_type_label']) && is_scalar($entry['qr_type_label']) && trim((string) $entry['qr_type_label']) !== '' ) {
                    return sanitize_text_field((string) $entry['qr_type_label']);
                }
                if ( isset($entry['qr_type']) && is_scalar($entry['qr_type']) && trim((string) $entry['qr_type']) !== '' ) {
                    return sanitize_text_field((string) $entry['qr_type']);
                }
                return ($status === 'virtual_checked_in') ? 'Acceso virtual' : 'Sin clasificar';
            }
        }
        return '';
    }
}

if ( ! function_exists('eventosapp_custom_metrics_ticket_modalidad_display_from_meta') ) {
    function eventosapp_custom_metrics_ticket_modalidad_display_from_meta($raw, $event_id = 0){
        $event_id = (int) $event_id;
        $raw = is_scalar($raw) ? (string) $raw : '';

        if ( function_exists('eventosapp_resolve_ticket_modalidad') ) {
            $raw = eventosapp_resolve_ticket_modalidad($event_id, $raw, $raw);
        } elseif ( function_exists('eventosapp_normalize_ticket_modalidad') ) {
            $raw = eventosapp_normalize_ticket_modalidad($raw);
        } else {
            $raw = sanitize_key($raw);
            $raw = in_array($raw, ['presencial', 'virtual'], true) ? $raw : '';
        }

        $options = function_exists('eventosapp_ticket_modalidad_options')
            ? eventosapp_ticket_modalidad_options()
            : [ 'presencial' => 'Presencial', 'virtual' => 'Virtual' ];

        if ( isset($options[$raw]) ) return sanitize_text_field($options[$raw]);
        return $raw !== '' ? ucwords(str_replace(['_', '-'], ' ', sanitize_text_field($raw))) : '';
    }
}

if ( ! function_exists('eventosapp_custom_metrics_attendance_value_from_meta') ) {
    function eventosapp_custom_metrics_attendance_value_from_meta($key, $raw){
        $key = eventosapp_custom_metrics_normalize_field_key($key);
        if ( $key === 'attendance_confirmation_status' ) {
            $raw = sanitize_key(is_scalar($raw) ? (string)$raw : '');
            $labels = function_exists('eventosapp_attendance_confirmation_status_options')
                ? eventosapp_attendance_confirmation_status_options()
                : ['si'=>'Sí','no'=>'No','no_responde'=>'No responde','sin_consulta'=>'Sin consulta'];
            return $labels[$raw] ?? ($labels['sin_consulta'] ?? 'Sin consulta');
        }
        if ( in_array($key, ['attendance_confirmation_sent_channels','attendance_confirmation_response_channels'], true) ) {
            $channels = function_exists('eventosapp_attendance_confirmation_sanitize_channels')
                ? eventosapp_attendance_confirmation_sanitize_channels($raw)
                : (is_array($raw) ? $raw : []);
            if ( function_exists('eventosapp_attendance_confirmation_format_channels') ) {
                return eventosapp_attendance_confirmation_format_channels($channels);
            }
            $labels = ['email'=>'Correo electrónico','whatsapp'=>'WhatsApp'];
            return implode(', ', array_map(function($channel) use ($labels){ return $labels[$channel] ?? $channel; }, $channels));
        }
        if ( $key === 'attendance_confirmation_last_response_channel' ) {
            $channel = sanitize_key(is_scalar($raw) ? (string)$raw : '');
            if ( function_exists('eventosapp_attendance_confirmation_channel_label') && $channel !== '' ) {
                return eventosapp_attendance_confirmation_channel_label($channel);
            }
            return $channel === 'email' ? 'Correo electrónico' : ($channel === 'whatsapp' ? 'WhatsApp' : '');
        }
        return is_scalar($raw) ? (string)$raw : '';
    }
}

if ( ! function_exists('eventosapp_custom_metrics_extract_ticket_value_from_batch') ) {
    function eventosapp_custom_metrics_extract_ticket_value_from_batch($ticket_id, $event_id, $field, $meta_map, $post_dates){
        $key = eventosapp_custom_metrics_normalize_field_key(isset($field['key']) ? (string) $field['key'] : '');

        if ( $key === 'ticket_post_id' ) return (string) $ticket_id;
        if ( $key === 'modalidad' ) {
            return eventosapp_custom_metrics_ticket_modalidad_display_from_meta(
                eventosapp_custom_metrics_get_meta_value_from_map($meta_map, $ticket_id, '_eventosapp_ticket_modalidad', ''),
                $event_id
            );
        }
        if ( strpos($key, 'attendance_confirmation_') === 0 ) {
            $meta_key = isset($field['meta_key']) ? (string)$field['meta_key'] : '';
            $raw = $meta_key !== '' ? eventosapp_custom_metrics_get_meta_value_from_map($meta_map, $ticket_id, $meta_key, '') : '';
            return eventosapp_custom_metrics_attendance_value_from_meta($key, $raw);
        }
        if ( $key === 'checked_in_any' ) {
            return eventosapp_custom_metrics_ticket_checked_in_any_from_meta(
                eventosapp_custom_metrics_get_meta_value_from_map($meta_map, $ticket_id, '_eventosapp_checkin_status', []),
                eventosapp_custom_metrics_get_meta_value_from_map($meta_map, $ticket_id, '_eventosapp_virtual_checkin_status', []),
                eventosapp_custom_metrics_get_event_days($event_id)
            );
        }
        if ( $key === 'medio_checkin' ) {
            return eventosapp_custom_metrics_ticket_first_qr_label_from_meta(
                eventosapp_custom_metrics_get_meta_value_from_map($meta_map, $ticket_id, '_eventosapp_checkin_log', [])
            );
        }
        if ( $key === 'fecha_creacion' ) {
            return isset($post_dates[$ticket_id]) ? (string) $post_dates[$ticket_id] : '';
        }

        $meta_key = isset($field['meta_key']) ? (string) $field['meta_key'] : '';
        if ( $meta_key !== '' ) return eventosapp_custom_metrics_get_meta_value_from_map($meta_map, $ticket_id, $meta_key, '');
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
                $key = isset($field['key']) ? eventosapp_custom_metrics_normalize_field_key((string) $field['key']) : '';
                if ( isset($wanted[$key]) ) $fields[] = $field;
            }
        }

        if ( empty($fields) ) return [];

        $records = [];
        $meta_keys = eventosapp_custom_metrics_field_requires_meta_keys($fields);
        $batch_size = (int) apply_filters('eventosapp_custom_metrics_ticket_batch_size', 350, $event_id);
        $batch_size = max(100, min(700, $batch_size));
        $last_id = 0;
        $processed = 0;

        do {
            $ticket_ids = eventosapp_custom_metrics_get_ticket_ids_batch($event_id, $last_id, $batch_size);
            if ( empty($ticket_ids) ) break;

            $last_id = max($ticket_ids);
            $meta_map = eventosapp_custom_metrics_get_ticket_meta_map($ticket_ids, $meta_keys);
            $post_dates = eventosapp_custom_metrics_get_ticket_post_dates($ticket_ids);

            foreach ( $ticket_ids as $ticket_id ) {
                $record = [];
                foreach ( $fields as $field ) {
                    $field_key = isset($field['key']) ? eventosapp_custom_metrics_normalize_field_key((string) $field['key']) : '';
                    if ( $field_key === '' ) continue;
                    $record[$field_key] = eventosapp_custom_metrics_extract_ticket_value_from_batch($ticket_id, $event_id, $field, $meta_map, $post_dates);
                }
                $records[] = $record;
                $processed++;
            }

            unset($meta_map, $post_dates, $ticket_ids);
        } while ( true );

        if ( function_exists('eventosapp_metrics_log_debug') ) {
            eventosapp_metrics_log_debug('custom_metrics_records_built', [
                'event_id'  => $event_id,
                'records'   => $processed,
                'fields'    => count($fields),
                'batchSize' => $batch_size,
            ]);
        }

        return $records;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_normalize_filter_text') ) {
    function eventosapp_custom_metrics_normalize_filter_text($value, $field_key = ''){
        $value = eventosapp_custom_metrics_normalize_display_value($value);
        if ( function_exists('remove_accents') ) $value = remove_accents($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/\s+/u', ' ', trim($value));

        $field_key = eventosapp_custom_metrics_normalize_field_key($field_key);
        if ( in_array($field_key, ['ticket_public_id', 'ticket_preprinted_id', 'cc', 'telefono', 'nit'], true) ) {
            $value = preg_replace('/[^a-z0-9]/i', '', $value);
        }

        return (string) $value;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_filter_value_is_empty') ) {
    function eventosapp_custom_metrics_filter_value_is_empty($value){
        return eventosapp_custom_metrics_normalize_display_value($value) === '';
    }
}

if ( ! function_exists('eventosapp_custom_metrics_normalize_filter_date') ) {
    function eventosapp_custom_metrics_normalize_filter_date($value){
        $value = eventosapp_custom_metrics_normalize_display_value($value);
        if ( $value === '' ) return '';
        if ( preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match) ) return $match[1];
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : '';
    }
}

if ( ! function_exists('eventosapp_custom_metrics_record_matches_filter') ) {
    function eventosapp_custom_metrics_record_matches_filter($record, $filter, $field_map){
        if ( ! is_array($record) || ! is_array($filter) ) return false;

        $field_key = eventosapp_custom_metrics_normalize_field_key(isset($filter['field']) ? $filter['field'] : '');
        $operator  = sanitize_key(isset($filter['operator']) ? $filter['operator'] : 'contains');
        $expected  = isset($filter['value']) && is_scalar($filter['value']) ? (string) $filter['value'] : '';

        if ( $field_key === '' || ! isset($field_map[$field_key]) ) return false;
        $actual = array_key_exists($field_key, $record) ? $record[$field_key] : '';
        $is_empty = eventosapp_custom_metrics_filter_value_is_empty($actual);

        if ( $operator === 'is_empty' ) return $is_empty;
        if ( $operator === 'is_not_empty' ) return ! $is_empty;
        if ( $is_empty ) return $operator === 'not_equals' || $operator === 'not_contains';

        $field_type = isset($field_map[$field_key]['type']) ? (string) $field_map[$field_key]['type'] : 'text';

        if ( $field_type === 'number' ) {
            $actual_number   = eventosapp_custom_metrics_normalize_numeric_value($actual);
            $expected_number = eventosapp_custom_metrics_normalize_numeric_value($expected);
            if ( $actual_number === null || $expected_number === null ) return false;

            if ( $operator === 'equals' ) return abs($actual_number - $expected_number) < 0.0000001;
            if ( $operator === 'not_equals' ) return abs($actual_number - $expected_number) >= 0.0000001;
            if ( $operator === 'greater_than' ) return $actual_number > $expected_number;
            if ( $operator === 'greater_or_equal' ) return $actual_number >= $expected_number;
            if ( $operator === 'less_than' ) return $actual_number < $expected_number;
            if ( $operator === 'less_or_equal' ) return $actual_number <= $expected_number;
            return false;
        }

        if ( $field_type === 'date' ) {
            $actual_date   = eventosapp_custom_metrics_normalize_filter_date($actual);
            $expected_date = eventosapp_custom_metrics_normalize_filter_date($expected);
            if ( $actual_date === '' || $expected_date === '' ) return false;

            if ( $operator === 'equals' ) return $actual_date === $expected_date;
            if ( $operator === 'not_equals' ) return $actual_date !== $expected_date;
            if ( $operator === 'before' ) return $actual_date < $expected_date;
            if ( $operator === 'on_or_before' ) return $actual_date <= $expected_date;
            if ( $operator === 'after' ) return $actual_date > $expected_date;
            if ( $operator === 'on_or_after' ) return $actual_date >= $expected_date;
            return false;
        }

        $actual_text   = eventosapp_custom_metrics_normalize_filter_text($actual, $field_key);
        $expected_text = eventosapp_custom_metrics_normalize_filter_text($expected, $field_key);

        if ( $operator === 'equals' ) return $actual_text === $expected_text;
        if ( $operator === 'not_equals' ) return $actual_text !== $expected_text;
        if ( $operator === 'contains' ) return $expected_text !== '' && strpos($actual_text, $expected_text) !== false;
        if ( $operator === 'not_contains' ) return $expected_text === '' || strpos($actual_text, $expected_text) === false;
        if ( $operator === 'starts_with' ) return $expected_text !== '' && strpos($actual_text, $expected_text) === 0;
        if ( $operator === 'ends_with' ) {
            if ( $expected_text === '' ) return false;
            return substr($actual_text, -strlen($expected_text)) === $expected_text;
        }

        return false;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_get_slot_filters') ) {
    function eventosapp_custom_metrics_get_slot_filters($slot){
        $filters = [];
        if ( ! is_array($slot) || empty($slot['filters']) || ! is_array($slot['filters']) ) return $filters;
        foreach ( $slot['filters'] as $filter ) {
            $filter = eventosapp_custom_metrics_sanitize_filter($filter);
            if ( $filter ) $filters[] = $filter;
        }
        return $filters;
    }
}

if ( ! function_exists('eventosapp_custom_metrics_apply_slot_filters') ) {
    function eventosapp_custom_metrics_apply_slot_filters($records, $slot, $field_map){
        $records = is_array($records) ? $records : [];
        $filters = eventosapp_custom_metrics_get_slot_filters($slot);
        if ( empty($filters) ) return $records;

        $relation = isset($slot['filter_relation']) && $slot['filter_relation'] === 'any' ? 'any' : 'all';
        $filtered = [];

        foreach ( $records as $record ) {
            $matches = 0;
            foreach ( $filters as $filter ) {
                if ( eventosapp_custom_metrics_record_matches_filter($record, $filter, $field_map) ) $matches++;
            }

            $include = $relation === 'any' ? $matches > 0 : $matches === count($filters);
            if ( $include ) $filtered[] = $record;
        }

        return $filtered;
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

        $payload_cache_key = 'evapp_cmetrics_payload_' . md5($event_id . '|' . wp_json_encode($layout));
        $cached_payload = get_transient($payload_cache_key);
        if ( is_array($cached_payload) ) {
            return $cached_payload;
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

                $slot_filters = eventosapp_custom_metrics_get_slot_filters($slot);
                $slot_records = eventosapp_custom_metrics_apply_slot_filters($records, $slot, $field_map);
                $payload = eventosapp_custom_metrics_build_slot_payload($event_id, $slot, $slot_records, $field_map, $slot_index);
                if ( ! empty($payload) ) {
                    $payload['total_count'] = count($records);
                    $payload['filtered_count'] = count($slot_records);
                    $payload['active_filters'] = count($slot_filters);
                    if ( empty($slot_records) && ! empty($slot_filters) ) {
                        $payload['message'] = 'No hay tickets que cumplan los filtros configurados para esta métrica.';
                    }
                    $row_slots[] = $payload;
                    $has_metrics = true;
                }
            }
            if ( ! empty($row_slots) ) $rows_out[] = ['slots' => $row_slots];
        }

        $payload = [
            'settings'    => isset($layout['settings']) ? $layout['settings'] : eventosapp_custom_metrics_default_settings(),
            'rows'        => $rows_out,
            'has_metrics' => $has_metrics,
        ];

        $ttl = (int) apply_filters('eventosapp_custom_metrics_payload_cache_ttl', 20, $event_id);
        set_transient($payload_cache_key, $payload, max(5, min(120, $ttl)));

        return $payload;
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
        $filter_relations = eventosapp_custom_metrics_filter_relation_options();
        $filter_operator_groups = eventosapp_custom_metrics_filter_operator_groups();
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
            .evapp-cmetrics-field input[type="date"],
            .evapp-cmetrics-field input[type="color"],
            .evapp-cmetrics-field select{width:100%;max-width:100%}
            .evapp-cmetrics-checks{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:8px}
            .evapp-cmetrics-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}
            .evapp-cmetrics-note{font-size:12px;color:#646970;margin-top:4px;line-height:1.35}
            .evapp-cmetrics-type-note{grid-column:1/-1;padding:8px 10px;background:#eef6ff;border:1px solid #bfdbfe;border-radius:8px;color:#1e3a8a;font-size:12px;line-height:1.45}
            .evapp-cmetrics-filters-box{margin-top:14px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc;padding:10px}
            .evapp-cmetrics-filters-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}
            .evapp-cmetrics-filters-title{font-weight:800;color:#1f2937}
            .evapp-cmetrics-filter-relation{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:8px 0}
            .evapp-cmetrics-filter-relation label{font-weight:600;color:#374151}
            .evapp-cmetrics-filter-relation select{min-width:230px}
            .evapp-cmetrics-filter-list{display:grid;gap:8px}
            .evapp-cmetrics-filter-row{display:grid;grid-template-columns:minmax(150px,1.3fr) minmax(135px,1fr) minmax(150px,1.2fr) auto;gap:8px;align-items:end;padding:9px;border:1px solid #e2e8f0;border-radius:9px;background:#fff}
            .evapp-cmetrics-filter-row .evapp-cmetrics-field{min-width:0}
            .evapp-cmetrics-filter-remove{align-self:end;white-space:nowrap}
            .evapp-cmetrics-filter-empty{padding:9px 10px;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;background:#fff;font-size:12px}
            .evapp-cmetrics-filter-value.is-hidden{display:none}
            @media(max-width:1100px){.evapp-cmetrics-filter-row{grid-template-columns:1fr 1fr}.evapp-cmetrics-filter-remove{justify-self:start}}
            @media(max-width:900px){.evapp-cmetrics-slots,.evapp-cmetrics-grid,.evapp-cmetrics-filter-row{grid-template-columns:1fr}}
        </style>

        <div class="evapp-cmetrics-wrap" id="evappCustomMetricsBuilder">
            <p class="evapp-cmetrics-help">
                Configura aquí las métricas adicionales para este evento. Cada bloque puede aplicar varios filtros antes de calcular la tabla, tarjeta o gráfico. Los campos parametrizados cargan sus opciones reales; los demás permiten escribir el valor que deseas buscar.
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
            const filterRelations = <?php echo wp_json_encode($filter_relations); ?>;
            const filterOperatorGroups = <?php echo wp_json_encode($filter_operator_groups); ?>;
            let state = <?php echo wp_json_encode($layout); ?>;

            function defaultSettings(){
                return { show_header:true, header_text:'Métricas personalizadas', header_color:'#eaf1ff' };
            }

            function defaultFilter(){
                return { field:'', operator:'contains', value:'' };
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
                    filter_relation:'all',
                    filters:[],
                    table_fields:['nombre','apellido','email','localidad','checked_in_any']
                };
            }

            function esc(s){
                return String(s == null ? '' : s)
                    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
            }

            function normalizeFieldKey(key){
                key = String(key == null ? '' : key).toLowerCase().replace(/[^a-z0-9_\-]/g, '');
                const aliases = {
                    '_eventosapp_ticket_modalidad':'modalidad',
                    'eventosapp_ticket_modalidad':'modalidad',
                    'ticket_modalidad':'modalidad',
                    'modalidad_ticket':'modalidad',
                    'modalidad_del_ticket':'modalidad',
                    'tipo_modalidad':'modalidad',
                    '_eventosapp_attendance_confirmation_status':'attendance_confirmation_status',
                    'confirmation_status':'attendance_confirmation_status',
                    'confirmacion_asistencia':'attendance_confirmation_status',
                    'estado_confirmacion_asistencia':'attendance_confirmation_status',
                    '_eventosapp_attendance_confirmation_sent_channels':'attendance_confirmation_sent_channels',
                    '_eventosapp_attendance_confirmation_response_channels':'attendance_confirmation_response_channels',
                    '_eventosapp_attendance_confirmation_last_response_channel':'attendance_confirmation_last_response_channel',
                    '_eventosapp_attendance_confirmation_last_response_at':'attendance_confirmation_last_response_at'
                };
                return aliases[key] || key;
            }

            function fieldByKey(key){
                key = normalizeFieldKey(key);
                return fields.find(function(field){ return String(field.key) === String(key); }) || null;
            }

            function hasFieldKey(key){
                return !!fieldByKey(key);
            }

            function optionsFromMap(map, selected){
                let html = '';
                Object.keys(map || {}).forEach(function(key){
                    html += '<option value="'+esc(key)+'" '+(String(selected)===String(key)?'selected':'')+'>'+esc(map[key])+'</option>';
                });
                return html;
            }

            function fieldOptions(selected, emptyLabel){
                selected = normalizeFieldKey(selected);
                let html = emptyLabel ? '<option value="">'+esc(emptyLabel)+'</option>' : '';
                if (selected && !hasFieldKey(selected)) {
                    html += '<option value="'+esc(selected)+'" selected>Campo no disponible: '+esc(selected)+'</option>';
                }
                fields.forEach(function(field){
                    html += '<option value="'+esc(field.key)+'" '+(String(selected)===String(field.key)?'selected':'')+'>'+esc(field.label)+'</option>';
                });
                return html;
            }

            function fieldFilterOptions(field){
                return field && Array.isArray(field.filter_options) ? field.filter_options : [];
            }

            function filterOperatorGroup(field){
                if (!field) return 'text';
                if (field.type === 'number') return 'number';
                if (field.type === 'date') return 'date';
                if (fieldFilterOptions(field).length) return 'options';
                return 'text';
            }

            function defaultOperatorForField(field){
                if (!field) return 'contains';
                if (field.type === 'number' || field.type === 'date') return 'equals';
                if (fieldFilterOptions(field).length) return field.filter_multiple ? 'contains' : 'equals';
                return 'contains';
            }

            function operatorNeedsValue(operator){
                return ['is_empty','is_not_empty'].indexOf(String(operator || '')) === -1;
            }

            function operatorOptionsForField(field, selected){
                const group = filterOperatorGroup(field);
                const operators = filterOperatorGroups[group] || filterOperatorGroups.text || {};
                if (!Object.prototype.hasOwnProperty.call(operators, selected)) {
                    selected = defaultOperatorForField(field);
                }
                return optionsFromMap(operators, selected);
            }

            function normalizeFilter(filter){
                filter = Object.assign(defaultFilter(), filter && typeof filter === 'object' ? filter : {});
                filter.field = normalizeFieldKey(filter.field || '');
                const field = fieldByKey(filter.field);
                const operators = filterOperatorGroups[filterOperatorGroup(field)] || filterOperatorGroups.text || {};
                filter.operator = String(filter.operator || defaultOperatorForField(field));
                if (!Object.prototype.hasOwnProperty.call(operators, filter.operator)) {
                    filter.operator = defaultOperatorForField(field);
                }
                filter.value = filter.value == null ? '' : String(filter.value);
                if (!operatorNeedsValue(filter.operator)) filter.value = '';
                return filter;
            }

            function normalizeSlot(slot){
                slot = Object.assign(defaultSlot(), slot && typeof slot === 'object' ? slot : {});
                slot.row_field = normalizeFieldKey(slot.row_field || slot.label_field || 'localidad');
                slot.column_field = normalizeFieldKey(slot.column_field || 'checked_in_any');
                slot.label_field = normalizeFieldKey(slot.label_field || slot.row_field || 'localidad');
                slot.series_field = normalizeFieldKey(slot.series_field || '');
                slot.value_field = normalizeFieldKey(slot.value_field || '');
                slot.filter_relation = slot.filter_relation === 'any' ? 'any' : 'all';
                slot.filters = Array.isArray(slot.filters) ? slot.filters.map(normalizeFilter).slice(0, 30) : [];
                if (Array.isArray(slot.table_fields)) slot.table_fields = slot.table_fields.map(normalizeFieldKey).filter(Boolean);
                return slot;
            }

            function normalizeState(){
                if (!state || typeof state !== 'object') state = {};
                state.settings = Object.assign(defaultSettings(), state.settings || {});
                if (!Array.isArray(state.rows)) state.rows = [];
                if (!state.rows.length) state.rows.push({slots:[defaultSlot(), defaultSlot()]});
                state.rows = state.rows.map(function(row){
                    let slots = row && Array.isArray(row.slots) ? row.slots : [];
                    return {slots:[normalizeSlot(slots[0]), normalizeSlot(slots[1])]};
                });
            }

            function syncHeaderControls(){
                $('#evapp-cmetrics-show-header').prop('checked', !!state.settings.show_header);
                $('#evapp-cmetrics-header-text').val(state.settings.header_text || 'Métricas personalizadas');
                $('#evapp-cmetrics-header-color').val(state.settings.header_color || '#eaf1ff');
            }

            function syncHidden(){
                $('#evapp_custom_metrics_json').val(JSON.stringify(state));
            }

            function renderFilterValueControl(filter, field, filterIndex){
                if (!operatorNeedsValue(filter.operator)) {
                    return '<div class="evapp-cmetrics-field evapp-cmetrics-filter-value is-hidden"></div>';
                }

                const options = fieldFilterOptions(field);
                let control = '';
                if (options.length) {
                    let values = options.map(function(option){ return String(option.value); });
                    let html = '<option value="">Selecciona una opción</option>';
                    if (filter.value && values.indexOf(String(filter.value)) === -1) {
                        html += '<option value="'+esc(filter.value)+'" selected>Valor no disponible: '+esc(filter.value)+'</option>';
                    }
                    options.forEach(function(option){
                        html += '<option value="'+esc(option.value)+'" '+(String(filter.value)===String(option.value)?'selected':'')+'>'+esc(option.label)+'</option>';
                    });
                    control = '<select class="evapp-cmetrics-filter-control" data-filter-prop="value" data-filter-index="'+filterIndex+'">'+html+'</select>';
                } else {
                    let type = 'text';
                    let extra = ' placeholder="Escribe el valor"';
                    if (field && field.type === 'number') {
                        type = 'number';
                        extra = ' step="any" placeholder="Escribe el número"';
                    } else if (field && field.type === 'date') {
                        type = 'date';
                        extra = '';
                    }
                    control = '<input type="'+type+'"'+extra+' class="evapp-cmetrics-filter-control" data-filter-prop="value" data-filter-index="'+filterIndex+'" value="'+esc(filter.value)+'">';
                }

                return '<div class="evapp-cmetrics-field evapp-cmetrics-filter-value"><label>Valor</label>'+control+'</div>';
            }

            function renderFilterRow(filter, filterIndex){
                const field = fieldByKey(filter.field);
                return '<div class="evapp-cmetrics-filter-row" data-filter="'+filterIndex+'">'
                    + '<div class="evapp-cmetrics-field"><label>Campo</label><select class="evapp-cmetrics-filter-control" data-filter-prop="field" data-filter-index="'+filterIndex+'">'+fieldOptions(filter.field, 'Selecciona campo')+'</select></div>'
                    + '<div class="evapp-cmetrics-field"><label>Condición</label><select class="evapp-cmetrics-filter-control" data-filter-prop="operator" data-filter-index="'+filterIndex+'">'+operatorOptionsForField(field, filter.operator)+'</select></div>'
                    + renderFilterValueControl(filter, field, filterIndex)
                    + '<button type="button" class="button evapp-cmetrics-filter-remove" data-filter-index="'+filterIndex+'">Eliminar filtro</button>'
                    + '</div>';
            }

            function renderFiltersBox(slot){
                let filtersHtml = '';
                if (!slot.filters.length) {
                    filtersHtml = '<div class="evapp-cmetrics-filter-empty">Sin filtros: esta métrica usará todos los tickets del evento.</div>';
                } else {
                    slot.filters.forEach(function(filter, filterIndex){
                        filtersHtml += renderFilterRow(filter, filterIndex);
                    });
                }

                return '<div class="evapp-cmetrics-filters-box">'
                    + '<div class="evapp-cmetrics-filters-head">'
                    + '<div><div class="evapp-cmetrics-filters-title">Filtros de datos</div><div class="evapp-cmetrics-note">Los filtros se aplican antes del conteo, suma, promedio o agrupación.</div></div>'
                    + '<button type="button" class="button evapp-cmetrics-filter-add">Agregar filtro</button>'
                    + '</div>'
                    + '<div class="evapp-cmetrics-filter-relation"><label>Combinar filtros</label><select class="evapp-cmetrics-filter-relation-control">'+optionsFromMap(filterRelations, slot.filter_relation)+'</select></div>'
                    + '<div class="evapp-cmetrics-filter-list">'+filtersHtml+'</div>'
                    + '</div>';
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
                    + renderFiltersBox(slot)
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

            function render(){
                normalizeState();
                syncHeaderControls();
                const $rows = $('#evapp-cmetrics-rows').empty();
                state.rows.forEach(function(row, rowIndex){
                    let rowHtml = '<div class="evapp-cmetrics-row" data-row="'+rowIndex+'">'
                        + '<div class="evapp-cmetrics-row-head">'
                        + '<div class="evapp-cmetrics-row-title">Fila '+(rowIndex+1)+'</div>'
                        + '<button type="button" class="button evapp-cmetrics-remove-row">Eliminar fila</button>'
                        + '</div>'
                        + '<div class="evapp-cmetrics-slots">';
                    row.slots.forEach(function(slot, slotIndex){ rowHtml += renderSlot(rowIndex, slotIndex, slot); });
                    rowHtml += '</div></div>';
                    $rows.append(rowHtml);
                });
                syncHidden();
                refreshSlotVisibility();
            }

            function slotFromControl($control){
                const $slot = $control.closest('.evapp-cmetrics-slot');
                const rowIndex = parseInt($slot.attr('data-row'), 10);
                const slotIndex = parseInt($slot.attr('data-slot'), 10);
                return {
                    rowIndex:rowIndex,
                    slotIndex:slotIndex,
                    slot:state.rows[rowIndex].slots[slotIndex]
                };
            }

            $(document).on('change input', '.evapp-cmetrics-control', function(){
                const $control = $(this);
                const context = slotFromControl($control);
                const prop = $control.attr('data-prop');
                let value;

                if ($control.attr('type') === 'checkbox') value = $control.is(':checked');
                else value = $control.val();

                if (prop === 'span' || prop === 'limit') value = parseInt(value || 0, 10);
                if (['row_field','column_field','label_field','series_field','value_field'].indexOf(prop) !== -1) value = normalizeFieldKey(value);
                if (prop === 'row_field') context.slot.label_field = value;
                context.slot[prop] = value;
                syncHidden();
                refreshSlotVisibility();
            });

            $(document).on('click', '.evapp-cmetrics-filter-add', function(e){
                e.preventDefault();
                const context = slotFromControl($(this));
                context.slot.filters.push(defaultFilter());
                render();
            });

            $(document).on('click', '.evapp-cmetrics-filter-remove', function(e){
                e.preventDefault();
                const context = slotFromControl($(this));
                const filterIndex = parseInt($(this).attr('data-filter-index'), 10);
                context.slot.filters.splice(filterIndex, 1);
                render();
            });

            $(document).on('change', '.evapp-cmetrics-filter-relation-control', function(){
                const context = slotFromControl($(this));
                context.slot.filter_relation = $(this).val() === 'any' ? 'any' : 'all';
                syncHidden();
            });

            $(document).on('change', '.evapp-cmetrics-filter-control[data-filter-prop="field"]', function(){
                const context = slotFromControl($(this));
                const filterIndex = parseInt($(this).attr('data-filter-index'), 10);
                const fieldKey = normalizeFieldKey($(this).val());
                const field = fieldByKey(fieldKey);
                context.slot.filters[filterIndex] = {
                    field:fieldKey,
                    operator:defaultOperatorForField(field),
                    value:''
                };
                render();
            });

            $(document).on('change', '.evapp-cmetrics-filter-control[data-filter-prop="operator"]', function(){
                const context = slotFromControl($(this));
                const filterIndex = parseInt($(this).attr('data-filter-index'), 10);
                context.slot.filters[filterIndex].operator = $(this).val();
                if (!operatorNeedsValue(context.slot.filters[filterIndex].operator)) {
                    context.slot.filters[filterIndex].value = '';
                }
                render();
            });

            $(document).on('input change', '.evapp-cmetrics-filter-control[data-filter-prop="value"]', function(){
                const context = slotFromControl($(this));
                const filterIndex = parseInt($(this).attr('data-filter-index'), 10);
                context.slot.filters[filterIndex].value = $(this).val();
                syncHidden();
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
