<?php
/*
Plugin Name: EventosApp
Description: Gestión de eventos y tickets para asistentes.
Version: 1.1
Author: The Lead Partner
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// === Constantes del plugin ===
if ( ! defined( 'EVENTOSAPP_PLUGIN_URL' ) ) {
    define( 'EVENTOSAPP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );   // Con trailing slash
    define( 'EVENTOSAPP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) ); // Con trailing slash
}

/**
 * Helpers globales de modalidad de evento/ticket.
 * Se definen antes de cargar el resto de archivos para que todos los flujos
 * usen la misma normalización sin depender del orden de includes.
 */
if ( ! function_exists('eventosapp_event_modalidad_options') ) {
    function eventosapp_event_modalidad_options() {
        return [
            'presencial'         => 'Presencial',
            'virtual'            => 'Virtual',
            'presencial_virtual' => 'Presencial y Virtual',
        ];
    }
}

if ( ! function_exists('eventosapp_ticket_modalidad_options') ) {
    function eventosapp_ticket_modalidad_options() {
        return [
            'presencial' => 'Presencial',
            'virtual'    => 'Virtual',
        ];
    }
}

if ( ! function_exists('eventosapp_normalize_event_modalidad') ) {
    function eventosapp_normalize_event_modalidad( $modalidad ) {
        $modalidad = sanitize_key( (string) $modalidad );
        $aliases = [
            'fisico'             => 'presencial',
            'físico'             => 'presencial',
            'presencial_y_virtual'=> 'presencial_virtual',
            'presencial-virtual' => 'presencial_virtual',
            'hibrido'            => 'presencial_virtual',
            'híbrido'            => 'presencial_virtual',
            'mixto'              => 'presencial_virtual',
        ];
        if ( isset( $aliases[$modalidad] ) ) {
            $modalidad = $aliases[$modalidad];
        }
        return in_array( $modalidad, [ 'presencial', 'virtual', 'presencial_virtual' ], true ) ? $modalidad : 'presencial';
    }
}

if ( ! function_exists('eventosapp_normalize_ticket_modalidad') ) {
    function eventosapp_normalize_ticket_modalidad( $modalidad ) {
        $modalidad = sanitize_key( (string) $modalidad );
        if ( in_array( $modalidad, [ 'presencial', 'virtual' ], true ) ) {
            return $modalidad;
        }
        return '';
    }
}

if ( ! function_exists('eventosapp_get_event_modalidad') ) {
    function eventosapp_get_event_modalidad( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) {
            return 'presencial';
        }
        return eventosapp_normalize_event_modalidad( get_post_meta( $event_id, '_eventosapp_event_modalidad', true ) ?: 'presencial' );
    }
}

if ( ! function_exists('eventosapp_get_event_modalidad_label') ) {
    function eventosapp_get_event_modalidad_label( $event_id ) {
        $modalidad = eventosapp_get_event_modalidad( $event_id );
        $options   = eventosapp_event_modalidad_options();
        return $options[$modalidad] ?? 'Presencial';
    }
}

if ( ! function_exists('eventosapp_event_has_physical_access') ) {
    function eventosapp_event_has_physical_access( $event_id ) {
        return in_array( eventosapp_get_event_modalidad( $event_id ), [ 'presencial', 'presencial_virtual' ], true );
    }
}

if ( ! function_exists('eventosapp_event_has_virtual_access') ) {
    function eventosapp_event_has_virtual_access( $event_id ) {
        return in_array( eventosapp_get_event_modalidad( $event_id ), [ 'virtual', 'presencial_virtual' ], true );
    }
}

if ( ! function_exists('eventosapp_ticket_allowed_modalidades_for_event') ) {
    function eventosapp_ticket_allowed_modalidades_for_event( $event_id ) {
        $event_mode = eventosapp_get_event_modalidad( $event_id );
        if ( $event_mode === 'virtual' ) {
            return [ 'virtual' ];
        }
        if ( $event_mode === 'presencial_virtual' ) {
            return [ 'presencial', 'virtual' ];
        }
        return [ 'presencial' ];
    }
}

if ( ! function_exists('eventosapp_resolve_ticket_modalidad') ) {
    function eventosapp_resolve_ticket_modalidad( $event_id, $requested = '', $current = '' ) {
        $allowed   = eventosapp_ticket_allowed_modalidades_for_event( $event_id );
        $requested = eventosapp_normalize_ticket_modalidad( $requested );
        $current   = eventosapp_normalize_ticket_modalidad( $current );

        if ( $requested && in_array( $requested, $allowed, true ) ) {
            return $requested;
        }
        if ( $current && in_array( $current, $allowed, true ) ) {
            return $current;
        }
        return reset( $allowed ) ?: 'presencial';
    }
}

if ( ! function_exists('eventosapp_get_ticket_modalidad') ) {
    function eventosapp_get_ticket_modalidad( $ticket_id ) {
        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id ) {
            return 'presencial';
        }

        $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        $stored   = get_post_meta( $ticket_id, '_eventosapp_ticket_modalidad', true );
        $resolved = eventosapp_resolve_ticket_modalidad( $event_id, $stored, $stored );

        if ( $resolved && $stored !== $resolved ) {
            update_post_meta( $ticket_id, '_eventosapp_ticket_modalidad', $resolved );
        }

        return $resolved ?: 'presencial';
    }
}

if ( ! function_exists('eventosapp_get_ticket_modalidad_label') ) {
    function eventosapp_get_ticket_modalidad_label( $ticket_id ) {
        $modalidad = eventosapp_get_ticket_modalidad( $ticket_id );
        $options   = eventosapp_ticket_modalidad_options();
        return $options[$modalidad] ?? 'Presencial';
    }
}

if ( ! function_exists('eventosapp_ticket_is_virtual') ) {
    function eventosapp_ticket_is_virtual( $ticket_id ) {
        return eventosapp_get_ticket_modalidad( $ticket_id ) === 'virtual';
    }
}

/**
 * Helpers globales para la autenticación del Networking Global.
 *
 * Cada evento puede definir qué datos del ticket debe escribir el asistente
 * antes de iniciar su sesión de networking. Si el evento no tiene configuración,
 * se conserva el comportamiento histórico: cédula + apellido.
 */
if ( ! function_exists('eventosapp_networking_global_auth_field_options') ) {
    function eventosapp_networking_global_auth_field_options() {
        return [
            'cc' => [
                'label'       => 'Cédula',
                'meta_key'    => '_eventosapp_asistente_cc',
                'type'        => 'text',
                'placeholder' => 'Ej: 1020304050',
                'help'        => 'Escribe tu número de identificación tal como aparece en la inscripción.',
            ],
            'apellido' => [
                'label'       => 'Apellido',
                'meta_key'    => '_eventosapp_asistente_apellido',
                'type'        => 'text',
                'placeholder' => 'Ej: Pérez o García',
                'help'        => 'Escribe tu apellido tal como aparece en la inscripción.',
            ],
            'nombre' => [
                'label'       => 'Primer Nombre',
                'meta_key'    => '_eventosapp_asistente_nombre',
                'type'        => 'text',
                'placeholder' => 'Ej: Juan',
                'help'        => 'Escribe tu primer nombre registrado.',
            ],
            'email' => [
                'label'       => 'Email',
                'meta_key'    => '_eventosapp_asistente_email',
                'type'        => 'email',
                'placeholder' => 'Ej: nombre@correo.com',
                'help'        => 'Escribe el correo usado en tu inscripción.',
            ],
            'telefono' => [
                'label'       => 'Número de Contacto',
                'meta_key'    => '_eventosapp_asistente_tel',
                'type'        => 'text',
                'placeholder' => 'Ej: 3001234567',
                'help'        => 'Escribe tu teléfono registrado.',
            ],
            'empresa' => [
                'label'       => 'Nombre de Empresa',
                'meta_key'    => '_eventosapp_asistente_empresa',
                'type'        => 'text',
                'placeholder' => 'Ej: Empresa S.A.S.',
                'help'        => 'Escribe la empresa registrada en tu ticket.',
            ],
            'nit' => [
                'label'       => 'NIT',
                'meta_key'    => '_eventosapp_asistente_nit',
                'type'        => 'text',
                'placeholder' => 'Ej: 900123456',
                'help'        => 'Escribe el NIT registrado.',
            ],
            'cargo' => [
                'label'       => 'Cargo',
                'meta_key'    => '_eventosapp_asistente_cargo',
                'type'        => 'text',
                'placeholder' => 'Ej: Gerente Comercial',
                'help'        => 'Escribe el cargo registrado.',
            ],
            'ciudad' => [
                'label'       => 'Ciudad',
                'meta_key'    => '_eventosapp_asistente_ciudad',
                'type'        => 'text',
                'placeholder' => 'Ej: Barranquilla',
                'help'        => 'Escribe la ciudad registrada.',
            ],
            'pais' => [
                'label'       => 'País',
                'meta_key'    => '_eventosapp_asistente_pais',
                'type'        => 'text',
                'placeholder' => 'Ej: Colombia',
                'help'        => 'Escribe el país registrado.',
            ],
            'localidad' => [
                'label'       => 'Localidad',
                'meta_key'    => '_eventosapp_asistente_localidad',
                'type'        => 'text',
                'placeholder' => 'Ej: VIP',
                'help'        => 'Escribe la localidad registrada en tu ticket.',
            ],
        ];
    }
}

if ( ! function_exists('eventosapp_networking_global_auth_default_fields') ) {
    function eventosapp_networking_global_auth_default_fields() {
        return [ 'cc', 'apellido' ];
    }
}

if ( ! function_exists('eventosapp_normalize_networking_global_auth_fields') ) {
    function eventosapp_normalize_networking_global_auth_fields( $fields ) {
        $options = eventosapp_networking_global_auth_field_options();

        if ( is_string( $fields ) ) {
            $decoded = json_decode( $fields, true );
            if ( is_array( $decoded ) ) {
                $fields = $decoded;
            } else {
                $fields = array_map( 'trim', explode( ',', $fields ) );
            }
        }

        if ( ! is_array( $fields ) ) {
            $fields = [];
        }

        $clean = [];
        foreach ( $fields as $field ) {
            $field = sanitize_key( (string) $field );
            if ( isset( $options[ $field ] ) && ! in_array( $field, $clean, true ) ) {
                $clean[] = $field;
            }
        }

        if ( empty( $clean ) ) {
            $clean = eventosapp_networking_global_auth_default_fields();
        }

        return $clean;
    }
}

if ( ! function_exists('eventosapp_get_event_networking_global_auth_fields') ) {
    function eventosapp_get_event_networking_global_auth_fields( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) {
            return eventosapp_networking_global_auth_default_fields();
        }

        $stored = get_post_meta( $event_id, '_eventosapp_networking_global_auth_fields', true );
        if ( empty( $stored ) ) {
            return eventosapp_networking_global_auth_default_fields();
        }

        return eventosapp_normalize_networking_global_auth_fields( $stored );
    }
}

if ( ! function_exists('eventosapp_networking_global_auth_get_field_labels') ) {
    function eventosapp_networking_global_auth_get_field_labels( $fields ) {
        $options = eventosapp_networking_global_auth_field_options();
        $labels  = [];

        foreach ( eventosapp_normalize_networking_global_auth_fields( $fields ) as $field ) {
            if ( isset( $options[ $field ]['label'] ) ) {
                $labels[] = $options[ $field ]['label'];
            }
        }

        return $labels;
    }
}

if ( ! function_exists('eventosapp_networking_global_auth_meta_key') ) {
    function eventosapp_networking_global_auth_meta_key( $field ) {
        $options = eventosapp_networking_global_auth_field_options();
        $field   = sanitize_key( (string) $field );
        return $options[ $field ]['meta_key'] ?? '';
    }
}

if ( ! function_exists('eventosapp_normalize_networking_global_auth_value') ) {
    function eventosapp_normalize_networking_global_auth_value( $value, $field = '' ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        $field = sanitize_key( (string) $field );
        $value = trim( sanitize_text_field( wp_unslash( (string) $value ) ) );

        if ( $field === 'email' ) {
            return strtolower( sanitize_email( $value ) );
        }

        $value = remove_accents( $value );
        $value = strtolower( $value );
        $value = preg_replace( '/\s+/u', ' ', $value );
        $value = trim( $value );

        if ( in_array( $field, [ 'cc', 'nit', 'telefono' ], true ) ) {
            $value = preg_replace( '/[^a-z0-9]/', '', $value );
        }

        return $value;
    }
}

if ( ! function_exists('eventosapp_networking_global_auth_values_match') ) {
    function eventosapp_networking_global_auth_values_match( $stored, $submitted, $field ) {
        return eventosapp_normalize_networking_global_auth_value( $stored, $field ) === eventosapp_normalize_networking_global_auth_value( $submitted, $field );
    }
}

if ( ! function_exists('eventosapp_find_ticket_by_networking_global_auth') ) {
    function eventosapp_find_ticket_by_networking_global_auth( $event_id, $submitted_fields, $configured_fields = [] ) {
        $event_id          = absint( $event_id );
        $configured_fields = eventosapp_normalize_networking_global_auth_fields( $configured_fields );
        $options           = eventosapp_networking_global_auth_field_options();

        if ( ! $event_id || ! is_array( $submitted_fields ) || empty( $configured_fields ) ) {
            return 0;
        }

        $submitted = [];
        foreach ( $configured_fields as $field ) {
            $submitted[ $field ] = isset( $submitted_fields[ $field ] ) ? (string) $submitted_fields[ $field ] : '';
            if ( eventosapp_normalize_networking_global_auth_value( $submitted[ $field ], $field ) === '' ) {
                return 0;
            }
        }

        $anchor_field = '';
        foreach ( [ 'cc', 'email', 'telefono', 'nit' ] as $candidate ) {
            if ( in_array( $candidate, $configured_fields, true ) && ! empty( $options[ $candidate ]['meta_key'] ) ) {
                $anchor_field = $candidate;
                break;
            }
        }

        $base_meta_query = [
            'relation' => 'AND',
            [
                'key'   => '_eventosapp_ticket_evento_id',
                'value' => $event_id,
                'type'  => 'NUMERIC',
            ],
        ];

        $queries_to_try = [];
        if ( $anchor_field ) {
            $anchor_value = $submitted[ $anchor_field ];
            if ( $anchor_field === 'email' ) {
                $anchor_value = sanitize_email( $anchor_value );
            }
            $queries_to_try[] = array_merge( $base_meta_query, [
                [
                    'key'     => $options[ $anchor_field ]['meta_key'],
                    'value'   => $anchor_value,
                    'compare' => '=',
                ],
            ] );
        }
        $queries_to_try[] = $base_meta_query;

        foreach ( $queries_to_try as $index => $meta_query ) {
            $ticket_ids = get_posts( [
                'post_type'      => 'eventosapp_ticket',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => $meta_query,
            ] );

            if ( empty( $ticket_ids ) && $index === 0 ) {
                continue;
            }

            foreach ( $ticket_ids as $ticket_id ) {
                $all_match = true;

                foreach ( $configured_fields as $field ) {
                    $meta_key = $options[ $field ]['meta_key'] ?? '';
                    if ( ! $meta_key ) {
                        $all_match = false;
                        break;
                    }

                    $stored = get_post_meta( $ticket_id, $meta_key, true );
                    if ( ! eventosapp_networking_global_auth_values_match( $stored, $submitted[ $field ], $field ) ) {
                        $all_match = false;
                        break;
                    }
                }

                if ( $all_match ) {
                    return absint( $ticket_id );
                }
            }
        }

        return 0;
    }
}

/**
 * Helpers globales de check-in presencial/virtual.
 *
 * Mantiene intacto el check-in presencial existente (_eventosapp_checkin_status)
 * y agrega un estado independiente para check-in virtual (_eventosapp_virtual_checkin_status).
 */
if ( ! function_exists('eventosapp_get_event_timezone_object') ) {
    function eventosapp_get_event_timezone_object( $event_id ) {
        $event_tz = get_post_meta( absint($event_id), '_eventosapp_zona_horaria', true );
        if ( ! $event_tz ) {
            $event_tz = wp_timezone_string();
            if ( ! $event_tz || $event_tz === 'UTC' ) {
                $offset = get_option('gmt_offset');
                $event_tz = $offset ? ( timezone_name_from_abbr('', $offset * 3600, 0) ?: 'UTC' ) : 'UTC';
            }
        }

        try {
            return new DateTimeZone( $event_tz );
        } catch ( Exception $e ) {
            return wp_timezone();
        }
    }
}

if ( ! function_exists('eventosapp_get_event_current_date') ) {
    function eventosapp_get_event_current_date( $event_id ) {
        try {
            $now = new DateTime( 'now', eventosapp_get_event_timezone_object( $event_id ) );
        } catch ( Exception $e ) {
            $now = new DateTime( 'now', wp_timezone() );
        }
        return $now->format('Y-m-d');
    }
}


if ( ! function_exists('eventosapp_normalize_datetime_local_value') ) {
    /**
     * Normaliza valores de <input type="datetime-local"> sin convertirlos a UTC.
     *
     * El navegador envía la fecha/hora como hora local del evento, por ejemplo:
     * 2026-06-02T07:00. Este helper conserva esa hora exacta para evitar desfases
     * al renderizar la landing virtual, correos o mensajes asociados al evento.
     */
    function eventosapp_normalize_datetime_local_value( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        $value = trim( sanitize_text_field( wp_unslash( (string) $value ) ) );
        if ( $value === '' ) {
            return '';
        }

        if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[T\s](\d{2}:\d{2})(?::\d{2})?(?:\.\d+)?(?:Z|[+\-]\d{2}:?\d{2})?$/i', $value, $matches ) ) {
            return $matches[1] . 'T' . $matches[2];
        }

        return $value;
    }
}

if ( ! function_exists('eventosapp_datetime_value_has_timezone') ) {
    function eventosapp_datetime_value_has_timezone( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            return false;
        }

        $value = trim( (string) $value );
        if ( $value === '' ) {
            return false;
        }

        return (bool) preg_match( '/(?:Z|[+\-]\d{2}:?\d{2})$/i', $value );
    }
}

if ( ! function_exists('eventosapp_get_virtual_access_datetime_object') ) {
    /**
     * Devuelve la fecha/hora de habilitación virtual como DateTimeImmutable
     * en la zona horaria del evento.
     *
     * Regla importante:
     * - Si el valor guardado no trae zona horaria explícita, se interpreta como hora local
     *   de la zona configurada en el evento, NO como UTC.
     * - Si algún flujo externo guarda una fecha con Z u offset, se respeta ese instante y
     *   se convierte a la zona del evento solo para mostrarlo.
     */
    function eventosapp_get_virtual_access_datetime_object( $event_id, $raw_value = null ) {
        $event_id = absint( $event_id );
        $raw_value = ( $raw_value === null ) ? get_post_meta( $event_id, '_eventosapp_virtual_access_datetime', true ) : $raw_value;

        if ( is_array( $raw_value ) || is_object( $raw_value ) ) {
            return null;
        }

        $raw_value = trim( (string) $raw_value );
        if ( $raw_value === '' ) {
            return null;
        }

        $tz = function_exists('eventosapp_get_event_timezone_object') ? eventosapp_get_event_timezone_object( $event_id ) : wp_timezone();

        try {
            if ( eventosapp_datetime_value_has_timezone( $raw_value ) ) {
                return ( new DateTimeImmutable( $raw_value ) )->setTimezone( $tz );
            }

            $clean_value = str_replace( 'T', ' ', $raw_value );
            $clean_value = preg_replace( '/\.\d+$/', '', $clean_value );
            $formats = [
                '!Y-m-d H:i:s',
                '!Y-m-d H:i',
                '!Y-m-d',
            ];

            foreach ( $formats as $format ) {
                $dt = DateTimeImmutable::createFromFormat( $format, $clean_value, $tz );
                if ( $dt instanceof DateTimeImmutable ) {
                    $errors = DateTimeImmutable::getLastErrors();
                    if ( $errors === false || ( empty( $errors['warning_count'] ) && empty( $errors['error_count'] ) ) ) {
                        return $dt;
                    }
                }
            }

            return ( new DateTimeImmutable( $raw_value, $tz ) )->setTimezone( $tz );
        } catch ( Exception $e ) {
            return null;
        }
    }
}

if ( ! function_exists('eventosapp_format_event_datetime') ) {
    /**
     * Formatea una fecha/hora usando siempre la zona horaria configurada en el evento.
     */
    function eventosapp_format_event_datetime( $event_id, $datetime, $format = 'd/m/Y H:i', $include_timezone = false ) {
        $event_id = absint( $event_id );
        $tz = function_exists('eventosapp_get_event_timezone_object') ? eventosapp_get_event_timezone_object( $event_id ) : wp_timezone();

        if ( $datetime instanceof DateTimeInterface ) {
            $local_datetime = ( new DateTimeImmutable( '@' . $datetime->getTimestamp() ) )->setTimezone( $tz );
        } else {
            $local_datetime = eventosapp_get_virtual_access_datetime_object( $event_id, $datetime );
        }

        if ( ! $local_datetime instanceof DateTimeInterface ) {
            return '';
        }

        $label = $local_datetime->format( $format );
        if ( $include_timezone ) {
            $label .= ' (' . $tz->getName() . ')';
        }

        return $label;
    }
}

if ( ! function_exists('eventosapp_array_meta') ) {
    function eventosapp_array_meta( $post_id, $meta_key ) {
        $value = get_post_meta( absint($post_id), $meta_key, true );
        if ( is_string( $value ) ) {
            $maybe = @unserialize( $value );
            if ( $maybe !== false || $value === 'b:0;' ) {
                $value = $maybe;
            }
        }
        return is_array( $value ) ? $value : [];
    }
}

if ( ! function_exists('eventosapp_get_checkin_config') ) {
    function eventosapp_get_checkin_config( $type = 'presencial' ) {
        $type = sanitize_key( (string) $type );
        if ( $type === 'virtual' ) {
            return [
                'type'             => 'virtual',
                'status_meta'      => '_eventosapp_virtual_checkin_status',
                'status_checked'   => 'checked_in',
                'log_status'       => 'virtual_checked_in',
                'log_status_off'   => 'virtual_not_checked_in',
                'label'            => 'Check-in virtual',
                'qr_type'          => 'virtual_access',
                'qr_type_label'    => 'Acceso virtual',
                'last_meta'        => '_eventosapp_virtual_checkin_last_at',
                'count_meta'       => '_eventosapp_virtual_checkin_clicks',
            ];
        }

        return [
            'type'             => 'presencial',
            'status_meta'      => '_eventosapp_checkin_status',
            'status_checked'   => 'checked_in',
            'log_status'       => 'checked_in',
            'log_status_off'   => 'not_checked_in',
            'label'            => 'Check-in presencial',
            'qr_type'          => 'counter',
            'qr_type_label'    => 'Counter',
            'last_meta'        => '_eventosapp_presencial_checkin_last_at',
            'count_meta'       => '_eventosapp_presencial_checkin_clicks',
        ];
    }
}

if ( ! function_exists('eventosapp_ticket_checkin_status_for_day') ) {
    function eventosapp_ticket_checkin_status_for_day( $ticket_id, $type = 'presencial', $day = '' ) {
        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id ) return 'not_checked_in';

        $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        $day = $day ?: eventosapp_get_event_current_date( $event_id );
        $cfg = eventosapp_get_checkin_config( $type );
        $arr = eventosapp_array_meta( $ticket_id, $cfg['status_meta'] );
        $status = isset( $arr[$day] ) ? (string) $arr[$day] : 'not_checked_in';
        return in_array( $status, [ 'checked_in', 'checked-in' ], true ) ? 'checked_in' : 'not_checked_in';
    }
}

if ( ! function_exists('eventosapp_ticket_has_checkin_type') ) {
    function eventosapp_ticket_has_checkin_type( $ticket_id, $type = 'presencial', $valid_days = [] ) {
        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id ) return false;

        $cfg = eventosapp_get_checkin_config( $type );
        $arr = eventosapp_array_meta( $ticket_id, $cfg['status_meta'] );
        $valid_lookup = [];
        if ( is_array( $valid_days ) && ! empty( $valid_days ) ) {
            foreach ( $valid_days as $d ) {
                if ( is_string( $d ) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ) {
                    $valid_lookup[$d] = true;
                }
            }
        }

        foreach ( $arr as $day => $status ) {
            if ( ! in_array( $status, [ 'checked_in', 'checked-in' ], true ) ) continue;
            if ( $valid_lookup && ! isset( $valid_lookup[$day] ) ) continue;
            return true;
        }
        return false;
    }
}

if ( ! function_exists('eventosapp_register_ticket_checkin') ) {
    function eventosapp_register_ticket_checkin( $ticket_id, $type = 'presencial', $args = [] ) {
        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
            return [ 'ok' => false, 'message' => 'Ticket inválido.' ];
        }

        $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return [ 'ok' => false, 'message' => 'Ticket sin evento válido.' ];
        }

        $args = is_array( $args ) ? $args : [];
        $cfg  = eventosapp_get_checkin_config( $type );
        $type = $cfg['type'];

        $day = ! empty( $args['day'] ) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['day'])
            ? (string) $args['day']
            : eventosapp_get_event_current_date( $event_id );

        $days = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days( $event_id ) : [];
        $days = array_values( array_filter( $days, function( $d ) {
            return is_string( $d ) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        } ) );

        $allow_outside_days = ! empty( $args['allow_outside_event_days'] );
        if ( ! $allow_outside_days && ! empty( $days ) && ! in_array( $day, $days, true ) ) {
            return [
                'ok'      => false,
                'message' => 'El check-in solo está permitido en las fechas del evento. Hoy no corresponde.',
                'day'     => $day,
                'type'    => $type,
            ];
        }

        try {
            $now = new DateTime( 'now', eventosapp_get_event_timezone_object( $event_id ) );
        } catch ( Exception $e ) {
            $now = new DateTime( 'now', wp_timezone() );
        }

        $status_arr = eventosapp_array_meta( $ticket_id, $cfg['status_meta'] );
        $prev = isset( $status_arr[$day] ) ? (string) $status_arr[$day] : 'not_checked_in';
        $already_checked = in_array( $prev, [ 'checked_in', 'checked-in' ], true );
        $status_arr[$day] = 'checked_in';
        update_post_meta( $ticket_id, $cfg['status_meta'], $status_arr );
        update_post_meta( $ticket_id, $cfg['last_meta'], $now->format('Y-m-d H:i:s') );
        update_post_meta( $ticket_id, $cfg['count_meta'], (int) get_post_meta( $ticket_id, $cfg['count_meta'], true ) + 1 );

        $force_log = ! empty( $args['force_log'] );
        if ( ! $already_checked || $force_log ) {
            $log = eventosapp_array_meta( $ticket_id, '_eventosapp_checkin_log' );
            $user = wp_get_current_user();
            $usuario = 'Sistema';
            if ( $user && $user->exists() ) {
                $usuario = $user->display_name . ' (' . $user->user_email . ')';
            } elseif ( $type === 'virtual' ) {
                $email = get_post_meta( $ticket_id, '_eventosapp_asistente_email', true );
                $usuario = $email ? ( 'Asistente virtual (' . $email . ')' ) : 'Asistente virtual';
            }

            $log_entry = [
                'fecha'          => $now->format('Y-m-d'),
                'hora'           => $now->format('H:i:s'),
                'dia'            => $day,
                'status'         => $cfg['log_status'],
                'status_label'   => $cfg['label'],
                'checkin_type'   => $type,
                'modalidad'      => function_exists('eventosapp_get_ticket_modalidad') ? eventosapp_get_ticket_modalidad( $ticket_id ) : get_post_meta( $ticket_id, '_eventosapp_ticket_modalidad', true ),
                'usuario'        => isset( $args['usuario'] ) && $args['usuario'] !== '' ? sanitize_text_field( (string) $args['usuario'] ) : $usuario,
                'origen'         => isset( $args['origen'] ) && $args['origen'] !== '' ? sanitize_text_field( (string) $args['origen'] ) : ( $type === 'virtual' ? 'virtual-landing' : 'manual' ),
                'previo'         => $prev,
                'qr_type'        => isset( $args['qr_type'] ) && $args['qr_type'] !== '' ? sanitize_key( (string) $args['qr_type'] ) : $cfg['qr_type'],
                'qr_type_label'  => isset( $args['qr_type_label'] ) && $args['qr_type_label'] !== '' ? sanitize_text_field( (string) $args['qr_type_label'] ) : $cfg['qr_type_label'],
            ];

            if ( ! empty( $args['ip'] ) ) {
                $log_entry['ip'] = sanitize_text_field( (string) $args['ip'] );
            }
            if ( ! empty( $args['user_agent'] ) ) {
                $log_entry['user_agent'] = substr( sanitize_text_field( (string) $args['user_agent'] ), 0, 250 );
            }

            $log[] = $log_entry;
            update_post_meta( $ticket_id, '_eventosapp_checkin_log', $log );
        }

        if ( $type === 'presencial' && function_exists('eventosapp_update_qr_usage_stats') && ! $already_checked ) {
            eventosapp_update_qr_usage_stats( $event_id, ! empty( $args['qr_type'] ) ? sanitize_key( $args['qr_type'] ) : $cfg['qr_type'] );
        }

        return [
            'ok'              => true,
            'ticket_id'       => $ticket_id,
            'event_id'        => $event_id,
            'type'            => $type,
            'day'             => $day,
            'status'          => 'checked_in',
            'already_checked' => $already_checked,
            'message'         => $already_checked ? 'El check-in ya estaba registrado.' : 'Check-in registrado.',
        ];
    }
}

if ( ! function_exists('eventosapp_register_virtual_checkin') ) {
    function eventosapp_register_virtual_checkin( $ticket_id, $args = [] ) {
        $args = is_array( $args ) ? $args : [];
        $args['origen'] = $args['origen'] ?? 'virtual-landing';
        $args['qr_type'] = $args['qr_type'] ?? 'virtual_access';
        $args['qr_type_label'] = $args['qr_type_label'] ?? 'Acceso virtual';
        return eventosapp_register_ticket_checkin( $ticket_id, 'virtual', $args );
    }
}

if ( ! function_exists('eventosapp_find_ticket_by_public_id') ) {
    function eventosapp_find_ticket_by_public_id( $public_id ) {
        $public_id = sanitize_text_field( (string) $public_id );
        if ( $public_id === '' ) return 0;

        $q = new WP_Query( [
            'post_type'      => 'eventosapp_ticket',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => 'eventosapp_ticketID',
                    'value'   => $public_id,
                    'compare' => '=',
                ],
            ],
        ] );

        return ! empty( $q->posts ) ? (int) $q->posts[0] : 0;
    }
}

if ( ! function_exists('eventosapp_resolve_ticket_from_request') ) {
    function eventosapp_resolve_ticket_from_request( $source = null ) {
        $source = is_array( $source ) ? $source : $_REQUEST;
        $ticket_id = isset( $source['ticket_id'] ) ? absint( $source['ticket_id'] ) : 0;
        if ( $ticket_id && get_post_type( $ticket_id ) === 'eventosapp_ticket' ) {
            return $ticket_id;
        }

        foreach ( [ 'ticket_pub', 'ticket', 'public_id', 'ticketID', 'ticket_id_public' ] as $key ) {
            if ( ! empty( $source[$key] ) ) {
                $found = eventosapp_find_ticket_by_public_id( wp_unslash( $source[$key] ) );
                if ( $found ) return $found;
            }
        }

        return 0;
    }
}

if ( ! function_exists('eventosapp_sanitize_virtual_landing_path') ) {
    /**
     * Normaliza cualquier valor escrito en el metabox para que siempre sea una ruta pública segura.
     * La landing virtual oficial se mantiene bajo /virtual/{slug-del-evento} para que el widget pueda
     * resolver el evento desde la URL sin exponer admin-ajax.php al asistente.
     */
    function eventosapp_sanitize_virtual_landing_path( $raw_path, $event_id = 0 ) {
        $event_id = absint( $event_id );
        $fallback_slug = $event_id ? sanitize_title( get_the_title( $event_id ) ) : '';
        if ( $fallback_slug === '' ) {
            $fallback_slug = 'evento-virtual';
        }

        $raw_path = is_string( $raw_path ) ? wp_unslash( $raw_path ) : '';
        $raw_path = trim( wp_strip_all_tags( $raw_path ) );

        if ( $raw_path === '' ) {
            return '/virtual/' . $fallback_slug;
        }

        $home_url = home_url();
        if ( $home_url && strpos( $raw_path, $home_url ) === 0 ) {
            $raw_path = substr( $raw_path, strlen( $home_url ) );
        }

        $parsed_path = wp_parse_url( $raw_path, PHP_URL_PATH );
        if ( is_string( $parsed_path ) && $parsed_path !== '' ) {
            $raw_path = $parsed_path;
        }

        $raw_path = trim( $raw_path, " \t\n\r\0\x0B/" );
        $raw_path = preg_replace( '#^virtual/#i', '', $raw_path );
        $raw_path = trim( (string) $raw_path, '/' );

        if ( $raw_path === '' ) {
            return '/virtual/' . $fallback_slug;
        }

        $segments = array_values( array_filter( explode( '/', $raw_path ), static function( $segment ) {
            return trim( (string) $segment ) !== '';
        } ) );

        $last_segment = $segments ? end( $segments ) : $fallback_slug;
        $slug = sanitize_title( $last_segment );
        if ( $slug === '' ) {
            $slug = $fallback_slug;
        }

        return '/virtual/' . $slug;
    }
}

if ( ! function_exists('eventosapp_get_event_virtual_landing_path') ) {
    function eventosapp_get_event_virtual_landing_path( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) {
            return '/virtual/evento-virtual';
        }

        $stored_path = get_post_meta( $event_id, '_eventosapp_virtual_landing_path', true );
        return eventosapp_sanitize_virtual_landing_path( $stored_path, $event_id );
    }
}

if ( ! function_exists('eventosapp_get_event_virtual_landing_url') ) {
    function eventosapp_get_event_virtual_landing_url( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) {
            return '';
        }

        return home_url( eventosapp_get_event_virtual_landing_path( $event_id ) );
    }
}

if ( ! function_exists('eventosapp_get_virtual_access_redirect_url') ) {
    /**
     * URL técnica de respaldo. No debe usarse como URL principal del correo.
     * Se conserva para compatibilidad con enlaces antiguos que ya hayan sido enviados.
     */
    function eventosapp_get_virtual_access_redirect_url( $ticket_id ) {
        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id ) return '';
        $public_id = get_post_meta( $ticket_id, 'eventosapp_ticketID', true );
        return add_query_arg( [
            'action'     => 'eventosapp_virtual_access',
            'ticket_id'  => $ticket_id,
            'ticket_pub' => $public_id,
        ], admin_url('admin-ajax.php') );
    }
}

if ( ! function_exists('eventosapp_get_virtual_landing_url') ) {
    /**
     * URL pública que debe usar el botón del correo/ticket virtual.
     * Ejemplo final: /virtual/nombre-del-evento?ticket_pub=tkXXXX
     */
    function eventosapp_get_virtual_landing_url( $ticket_id ) {
        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
            return '';
        }

        $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return eventosapp_get_virtual_access_redirect_url( $ticket_id );
        }

        $public_id = get_post_meta( $ticket_id, 'eventosapp_ticketID', true );
        $landing_url = eventosapp_get_event_virtual_landing_url( $event_id );

        if ( ! $landing_url ) {
            return eventosapp_get_virtual_access_redirect_url( $ticket_id );
        }

        $args = [];
        if ( $public_id !== '' ) {
            $args['ticket_pub'] = $public_id;
        } else {
            $args['ticket_id'] = $ticket_id;
        }

        return add_query_arg( $args, $landing_url );
    }
}

if ( ! function_exists('eventosapp_get_ticket_virtual_platform_url') ) {
    function eventosapp_get_ticket_virtual_platform_url( $ticket_id ) {
        $ticket_id = absint( $ticket_id );
        $event_id  = $ticket_id ? absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) : 0;
        return $event_id ? esc_url_raw( get_post_meta( $event_id, '_eventosapp_virtual_url', true ) ) : '';
    }
}

if ( ! function_exists('eventosapp_event_virtual_access_uses_landing') ) {
    /**
     * Define si los enlaces públicos de acceso virtual deben pasar por la landing de EventosApp.
     * Por compatibilidad, cualquier evento sin valor guardado se considera activo.
     */
    function eventosapp_event_virtual_access_uses_landing( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) {
            return true;
        }

        $stored = get_post_meta( $event_id, '_eventosapp_virtual_access_use_landing', true );
        return (string) $stored !== '0';
    }
}

if ( ! function_exists('eventosapp_get_ticket_virtual_access_url') ) {
    /**
     * URL final que deben usar botones, correos, WhatsApp y paneles para abrir el acceso virtual.
     * Si el evento tiene activa la landing, devuelve la landing pública de EventosApp.
     * Si se desactiva, devuelve directamente el enlace de la plataforma configurada.
     */
    function eventosapp_get_ticket_virtual_access_url( $ticket_id ) {
        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
            return '';
        }

        $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        if ( $event_id && eventosapp_event_virtual_access_uses_landing( $event_id ) ) {
            $landing_url = function_exists('eventosapp_get_virtual_landing_url') ? eventosapp_get_virtual_landing_url( $ticket_id ) : '';
            if ( $landing_url ) {
                return esc_url_raw( $landing_url );
            }
        }

        $platform_url = eventosapp_get_ticket_virtual_platform_url( $ticket_id );
        return $platform_url ? esc_url_raw( $platform_url ) : '';
    }
}

if ( ! function_exists('eventosapp_ajax_register_virtual_checkin') ) {
    function eventosapp_ajax_register_virtual_checkin() {
        $ticket_id = eventosapp_resolve_ticket_from_request( $_REQUEST );
        if ( ! $ticket_id ) {
            wp_send_json_error( [ 'message' => 'Ticket inválido.' ], 400 );
        }

        $result = eventosapp_register_virtual_checkin( $ticket_id, [
            'origen'     => 'virtual-landing',
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ] );

        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( [ 'message' => $result['message'] ?? 'No se pudo registrar el check-in virtual.' ], 400 );
        }

        wp_send_json_success( $result );
    }
}
add_action('wp_ajax_eventosapp_register_virtual_checkin', 'eventosapp_ajax_register_virtual_checkin');
add_action('wp_ajax_nopriv_eventosapp_register_virtual_checkin', 'eventosapp_ajax_register_virtual_checkin');

if ( ! function_exists('eventosapp_ajax_virtual_access_redirect') ) {
    function eventosapp_ajax_virtual_access_redirect() {
        $ticket_id = eventosapp_resolve_ticket_from_request( $_REQUEST );
        if ( ! $ticket_id ) {
            wp_die( 'Ticket inválido.', '', 400 );
        }

        eventosapp_register_virtual_checkin( $ticket_id, [
            'origen'     => 'virtual-access-redirect',
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ] );

        $target = eventosapp_get_ticket_virtual_platform_url( $ticket_id );
        if ( ! $target ) {
            wp_die( 'El enlace virtual todavía no está configurado.', '', 404 );
        }

        wp_redirect( esc_url_raw( $target ) );
        exit;
    }
}
add_action('wp_ajax_eventosapp_virtual_access', 'eventosapp_ajax_virtual_access_redirect');
add_action('wp_ajax_nopriv_eventosapp_virtual_access', 'eventosapp_ajax_virtual_access_redirect');

if ( ! function_exists('eventosapp_ticket_clear_presential_assets') ) {
    function eventosapp_ticket_clear_presential_assets( $ticket_id ) {
        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id ) {
            return;
        }

        $meta_keys = [
            '_eventosapp_ticket_pdf_url',
            '_eventosapp_ticket_wallet_android',
            '_eventosapp_ticket_wallet_android_url',
            '_eventosapp_wallet_google_object_id',
            '_eventosapp_wallet_google_class_id_effective',
            '_eventosapp_ticket_wallet_apple',
            '_eventosapp_ticket_wallet_apple_url',
            '_eventosapp_ticket_pkpass_url',
            '_eventosapp_qr_codes',
        ];

        foreach ( $meta_keys as $meta_key ) {
            delete_post_meta( $ticket_id, $meta_key );
        }
    }
}

if ( ! function_exists('eventosapp_require_first_existing_file') ) {
    function eventosapp_require_first_existing_file( array $relative_paths ) {
        foreach ( $relative_paths as $relative_path ) {
            $full_path = plugin_dir_path( __FILE__ ) . ltrim( $relative_path, '/' );
            if ( file_exists( $full_path ) ) {
                require_once $full_path;
                return true;
            }
        }
        return false;
    }
}

require_once plugin_dir_path(__FILE__) . 'eventosapp-tickets.php';
eventosapp_require_first_existing_file([
    'includes/admin/eventosapp-virtual-landing-metabox.php',
    'eventosapp-virtual-landing-metabox.php',
]);
eventosapp_require_first_existing_file([
    'includes/frontend/eventosapp-virtual-landing-widget.php',
    'eventosapp-virtual-landing-widget.php',
]);
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-integraciones.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions/google-wallet-android.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-badges.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-wallet-hooks.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-extras-ticket.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-email-header.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-sesiones.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-configuracion.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-co-gestion.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-access-staff-control-event.php'; // Control de acceso dashboard por evento
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-admin-metabox-sticky-menu.php'; // Menú sticky de metaboxes del editor de eventos
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-frontend-helpers.php';
eventosapp_require_first_existing_file([
    'includes/frontend/eventosapp-support-assistance.php',
    'eventosapp-support-assistance.php',
]);
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-frontend-dashboard.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-frontend-search.php';
eventosapp_require_first_existing_file([
    'includes/frontend/eventosapp-self-checkin.php',
    'eventosapp-self-checkin.php',
]);
eventosapp_require_first_existing_file([
    'includes/frontend/eventosapp-self-checkin-elementor.php',
    'eventosapp-self-checkin-elementor.php',
]);
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-frontend-register.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-public-register.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-registration-status-embed.php'; // NUEVO: consulta pública/externa de estado de inscripción
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-qr-checkin.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-face-checkin.php';
require_once plugin_dir_path(__FILE__) . 'eventosapp-qr-manager.php';
eventosapp_require_first_existing_file([
    'includes/admin/eventosapp-whatsapp-ticket.php',
    'eventosapp-whatsapp-ticket.php',
]);
eventosapp_require_first_existing_file([
    'includes/admin/eventosapp-whatsapp-templates.php',
    'eventosapp-whatsapp-templates.php',
]);
eventosapp_require_first_existing_file([
    'includes/admin/eventosapp-whatsapp-flows.php',
    'eventosapp-whatsapp-flows.php',
]);
eventosapp_require_first_existing_file([
    'includes/admin/eventosapp-whatsapp-flow-templates.php',
    'eventosapp-whatsapp-flow-templates.php',
]);
eventosapp_require_first_existing_file([
    'includes/admin/eventosapp-ticket-reminders.php',
    'eventosapp-ticket-reminders.php',
]);
eventosapp_require_first_existing_file([
    'includes/functions/eventosapp-whatsapp-masivo.php',
    'eventosapp-whatsapp-masivo.php',
]);
eventosapp_require_first_existing_file([
    'includes/admin/eventosapp-whatsapp-inbox.php',
    'eventosapp-whatsapp-inbox.php',
]);
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-generador-masivo-qr.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-frontend-edit.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-frontend-metrics.php';
eventosapp_require_first_existing_file([
    'includes/frontend/eventosapp-whatsapp-flow-metrics.php',
    'eventosapp-whatsapp-flow-metrics.php',
]);
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-campos-adicionales.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-privacidad.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-api-autocomplete.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions/eventosapp-ticket-email.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions/eventosapp-email-masivo.php';
require_once plugin_dir_path(__FILE__) . 'includes/api/eventosapp-intake-ac.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/metabox-curl.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-herramientas.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-embed-form.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-edicion-masiva.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-clientes-cpt.php'; // CPT Clientes / Organizadores
eventosapp_require_first_existing_file([
    'includes/admin/eventosapp-expositores.php',
    'eventosapp-expositores.php',
]); // CPT Expositores / Módulo de entregas comerciales
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-asistentes-cpt.php'; // CPT Asistentes / Personas
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-asistente-ticket-vincular.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-galeria-cpt.php'; // CPT Galerías de Fotos
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-networking-auth.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-networking-global.php';
require_once plugin_dir_path(__FILE__) . 'includes/public/eventosapp-pkpass-endpoint.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-notificaciones-evento.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-wallet-push-scheduler.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-frontend-auto-register.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-auto-networking.php';  // <-- NUEVO: Auto-creación páginas networking
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-event-checklist.php'; // <-- NUEVO
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-ranking-networking.php'; // <-- NUEVO: Ranking de Networking
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-batch-refresh.php';   // <-- NUEVO: batch refresh tickets por evento
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-batch-processor.php'; // <-- NUEVO: Sistema de actualización por lote v3.0
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-health-monitor.php'; // Monitor de salud y rendimiento de EventosApp
require_once plugin_dir_path(__FILE__) . 'includes/functions/eventosapp-webhook-conditionals.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions/eventosapp-ticket-variants.php'; // NUEVO: variantes de ticket por reglas
require_once plugin_dir_path(__FILE__) . 'includes/functions/eventosapp-networking-search.php';

// NUEVO: Sistema de Doble Autenticación
require_once plugin_dir_path(__FILE__) . 'includes/functions/eventosapp-doble-auth.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions/eventosapp-doble-auth-cron.php'; // NUEVO
require_once plugin_dir_path(__FILE__) . 'includes/admin/eventosapp-ticket-double-auth-metabox.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-qr-double-auth.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend/eventosapp-galeria-ia-buscador.php';

// === Helper: Resolver nombre del Organizador dinámicamente ===
// Siempre retorna el nombre correcto sin importar si se usó texto libre o CPT Cliente.
// Si el evento vincula un cliente, lee directamente del CPT cliente (siempre fresco).
// Si es texto libre, lee el campo de texto del metabox.
if ( ! function_exists('eventosapp_get_nombre_organizador') ) {
    function eventosapp_get_nombre_organizador( $evento_id ) {
        if ( ! $evento_id ) return '';

        $usar_cliente = get_post_meta( $evento_id, '_eventosapp_usar_cliente', true );

        if ( $usar_cliente === '1' ) {
            $cliente_id = absint( get_post_meta( $evento_id, '_eventosapp_cliente_id', true ) );
            if ( $cliente_id ) {
                $nombre = get_post_meta( $cliente_id, '_cliente_nombre_empresa', true );
                if ( ! $nombre ) {
                    $nombre = get_the_title( $cliente_id );
                }
                return sanitize_text_field( $nombre );
            }
            // Checkbox marcado pero sin cliente seleccionado → fallback al campo de texto
            return sanitize_text_field( get_post_meta( $evento_id, '_eventosapp_organizador', true ) ?: '' );
        }

        // Modo texto libre
        return sanitize_text_field( get_post_meta( $evento_id, '_eventosapp_organizador', true ) ?: '' );
    }
}

// === Debug de correo (solo si EVENTOSAPP_DEBUG_MAIL está habilitado) ===
if (defined('EVENTOSAPP_DEBUG_MAIL') && EVENTOSAPP_DEBUG_MAIL) {

    // 1) Log detallado cuando falla wp_mail (mensajes + DATA completa)
    add_action('wp_mail_failed', function($wp_error){
        if (is_wp_error($wp_error)) {
            error_log('[EventosApp] wp_mail_failed: ' . implode(' | ', $wp_error->get_error_messages()));
            $data = $wp_error->get_error_data();
            if ($data) {
                // data incluye: to, subject, message, headers, attachments, phpmailer_exception_code
                error_log('[EventosApp] wp_mail_failed DATA: ' . print_r($data, true));
            }
        } else {
            error_log('[EventosApp] wp_mail_failed desconocido');
        }
    }, 10, 1);

    // 2) Log antes de enviar (para ver exactamente qué se intenta mandar)
    add_filter('pre_wp_mail', function($null, $atts){
        // $atts = ['to'=>..., 'subject'=>..., 'message'=>..., 'headers'=>..., 'attachments'=>...]
        error_log('[EventosApp] pre_wp_mail ATTS: ' . print_r($atts, true));
        return $null; // no cortamos el envío
    }, 10, 2);

    // 3) Debug de PHPMailer a error_log (mientras depuras)
    add_action('phpmailer_init', function($phpmailer){
        // Si usas SMTP (WP Mail SMTP, Post SMTP, etc.) verás el diálogo SMTP aquí
        $phpmailer->SMTPDebug  = 2; // 0 = off, 1/2 = info detallada
        $phpmailer->Debugoutput = function($str, $level) {
            error_log("[PHPMailer:$level] $str");
        };
    });

}


// === Activación: preparar .htaccess PKPASS y flushear en el próximo init (sin do_action('init')) ===
register_activation_hook(__FILE__, function () {
    // Escribe/asegura el bloque de .htaccess para .pkpass
    if ( ! function_exists('insert_with_markers') && file_exists( ABSPATH . 'wp-admin/includes/misc.php' ) ) {
        require_once ABSPATH . 'wp-admin/includes/misc.php';
    }
    if ( function_exists('eventosapp_pkpass_activation') ) {
        eventosapp_pkpass_activation();
    }

    // Marca para flushear cuando corra el init real de WP
    update_option('eventosapp_needs_flush', 1);

    // Prepara la tabla central del Log de WhatsApp y su limpieza automática.
    if ( function_exists('eventosapp_whatsapp_install_log_table') ) {
        eventosapp_whatsapp_install_log_table();
    }
    if ( function_exists('eventosapp_whatsapp_schedule_log_cleanup') ) {
        eventosapp_whatsapp_schedule_log_cleanup();
    }

    // Prepara la tabla independiente del Inbox de WhatsApp.
    if ( function_exists('eventosapp_whatsapp_inbox_install_tables') ) {
        eventosapp_whatsapp_inbox_install_tables();
    }

    // Prepara la tabla del módulo Asistencia / Equipo de apoyo.
    if ( function_exists('eventosapp_support_assistance_install_table') ) {
        eventosapp_support_assistance_install_table();
    }

    // Prepara la tabla del módulo Expositores / entregas comerciales.
    if ( function_exists('eventosapp_expositores_install_tables') ) {
        eventosapp_expositores_install_tables();
    }
});

// En el primer init real, registra reglas (tu plugin ya las agrega con add_action('init', ...)) y flushea
add_action('init', function () {
    if ( get_option('eventosapp_needs_flush') ) {
        flush_rewrite_rules();
        delete_option('eventosapp_needs_flush');
    }
}, 99);

// (Opcional) Desactivación limpia: remueve el bloque y flushea
register_deactivation_hook(__FILE__, function () {
    if ( defined('EVENTOSAPP_WHATSAPP_LOG_CLEANUP_HOOK') ) {
        $cleanup_timestamp = wp_next_scheduled(EVENTOSAPP_WHATSAPP_LOG_CLEANUP_HOOK);
        if ( $cleanup_timestamp ) {
            wp_unschedule_event($cleanup_timestamp, EVENTOSAPP_WHATSAPP_LOG_CLEANUP_HOOK);
        }
    }

    if ( ! function_exists('insert_with_markers') && file_exists( ABSPATH . 'wp-admin/includes/misc.php' ) ) {
        require_once ABSPATH . 'wp-admin/includes/misc.php';
    }
    if ( function_exists('insert_with_markers') ) {
        $root_ht = ABSPATH . '.htaccess';
        if ( file_exists($root_ht) && is_writable($root_ht) ) {
            insert_with_markers($root_ht, 'EventosApp PKPASS', []); // elimina el bloque
        }
    }
    flush_rewrite_rules();
});


register_deactivation_hook(__FILE__, function () {
    // (Opcional) Limpia el bloque en raíz si quieres dejar todo como estaba
    if (!function_exists('insert_with_markers') && file_exists(ABSPATH.'wp-admin/includes/misc.php')) {
        require_once ABSPATH.'wp-admin/includes/misc.php';
    }
    if (function_exists('insert_with_markers')) {
        $root_ht = ABSPATH . '.htaccess';
        if (file_exists($root_ht) && is_writable($root_ht)) {
            insert_with_markers($root_ht, 'EventosApp PKPASS', []); // elimina el bloque
        }
    }

    flush_rewrite_rules();
});



// === Color Picker para el CPT de eventos ===
add_action('admin_enqueue_scripts', function(){
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'eventosapp_event' ) {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_media(); // Necesario para seleccionar fondos y logos del kiosko desde la Biblioteca de Medios.
    }
});


/**
 * Menú principal y submenús (estructura customizada)
 */
add_action('admin_menu', function() {
    add_menu_page(
        'EventosApp',                // Page title
        'EventosApp',                // Menu title
        'manage_options',            // Capability
        'eventosapp_dashboard',      // Menu slug (no página real)
        '__return_null',             // Callback
        'dashicons-calendar-alt',    // Icon
        25                           // Position
    );

    // Submenú "Eventos"
    add_submenu_page(
        'eventosapp_dashboard',
        'Eventos',
        'Eventos',
        'manage_options',
        'edit.php?post_type=eventosapp_event'
    );

    // Submenú "Tickets"
    add_submenu_page(
        'eventosapp_dashboard',
        'Tickets',
        'Tickets',
        'manage_options',
        'edit.php?post_type=eventosapp_ticket'
    );

    // Submenú "Clientes"
    add_submenu_page(
        'eventosapp_dashboard',
        'Clientes',
        'Clientes',
        'manage_options',
        'edit.php?post_type=eventosapp_cliente'
    );

    // Submenú "Expositores"
    add_submenu_page(
        'eventosapp_dashboard',
        'Expositores',
        'Expositores',
        'manage_options',
        'edit.php?post_type=eventosapp_expositor'
    );

// Submenú "Asistentes"
    add_submenu_page(
        'eventosapp_dashboard',
        'Asistentes',
        'Asistentes',
        'manage_options',
        'edit.php?post_type=eventosapp_asistente'
    );

    // Submenú "Galerías"
    add_submenu_page(
        'eventosapp_dashboard',
        'Galerías',
        '📸 Galerías',
        'manage_options',
        'edit.php?post_type=eventosapp_galeria'
    );
}, 9);


/**
 * Cambiar el "menu_parent" de los CPT a nuestro menú principal
 */
add_filter('parent_file', function($parent_file) {
    global $current_screen;
    if (isset($current_screen->post_type) && in_array($current_screen->post_type, [
        'eventosapp_event',
        'eventosapp_ticket',
        'eventosapp_cliente',
        'eventosapp_expositor',
        'eventosapp_asistente',
        'eventosapp_galeria',
    ])) {
        return 'eventosapp_dashboard';
    }
    return $parent_file;
});


/**
 * 1. Registrar el Custom Post Type "eventosapp_event"
 */
add_action('init', function() {
    register_post_type('eventosapp_event', [
        'labels' => [
            'name'               => 'Eventos',
            'singular_name'      => 'Evento',
            'add_new'            => 'Agregar Nuevo',
            'add_new_item'       => 'Agregar Nuevo Evento',
            'edit_item'          => 'Editar Evento',
            'new_item'           => 'Nuevo Evento',
            'view_item'          => 'Ver Evento',
            'search_items'       => 'Buscar Eventos',
            'not_found'          => 'No se encontraron eventos',
            'not_found_in_trash' => 'No se encontraron eventos en la papelera',
        ],
        'public'              => true,
        'menu_icon'           => 'dashicons-calendar-alt',
        'supports'            => ['title', 'thumbnail', 'custom-fields'],
        'has_archive'         => true,
        'rewrite'             => ['slug' => 'eventos'],
        'show_in_rest'        => true,
        // NO poner menu_position, ni menu_parent aquí
        'show_ui'             => true,
        'show_in_menu'        => false, // <-- solo en menú custom
    ]);
});


/**
 * 2. Metabox para detalles del evento + otras metabox
 */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_detalles_evento',
        'Detalles del Evento',
        'eventosapp_render_metabox_evento',
        'eventosapp_event',
        'normal',
        'default'
    );

    add_meta_box(
        'eventosapp_localidades_evento',
        'Localidades del Evento',
        'eventosapp_render_metabox_localidades',
        'eventosapp_event',
        'normal',
        'default'
    );

    add_meta_box(
        'eventosapp_networking_global_auth_evento',
        'Autenticación Networking Global',
        'eventosapp_render_metabox_networking_global_auth',
        'eventosapp_event',
        'normal',
        'default'
    );

    add_meta_box(
        'eventosapp_self_checkin_design_evento',
        'Autogestión Kiosko - Personalización',
        'eventosapp_render_metabox_self_checkin_design',
        'eventosapp_event',
        'normal',
        'default'
    );
});


/**
 * Metabox: Detalles del Evento (INCLUYE Wallet por evento)
 */
/**
 * Metabox: Detalles del Evento (INCLUYE Wallet por evento)
 */
function eventosapp_render_metabox_evento($post) {
    // Recuperar valores guardados
    $tipo_fecha   = get_post_meta($post->ID, '_eventosapp_tipo_fecha', true) ?: 'unica';
    $fecha_unica  = get_post_meta($post->ID, '_eventosapp_fecha_unica', true) ?: '';
    $fecha_inicio = get_post_meta($post->ID, '_eventosapp_fecha_inicio', true) ?: '';
    $fecha_fin    = get_post_meta($post->ID, '_eventosapp_fecha_fin', true) ?: '';
    $fechas_noco  = get_post_meta($post->ID, '_eventosapp_fechas_noco', true) ?: [];
    $hora_inicio  = get_post_meta($post->ID, '_eventosapp_hora_inicio', true) ?: '';
    $hora_cierre  = get_post_meta($post->ID, '_eventosapp_hora_cierre', true) ?: '';
    $zona_horaria = get_post_meta($post->ID, '_eventosapp_zona_horaria', true) ?: '';

    // Campos del evento
    $direccion         = get_post_meta($post->ID, '_eventosapp_direccion', true) ?: '';
    $coordenadas       = get_post_meta($post->ID, '_eventosapp_coordenadas', true) ?: '';
    $organizador       = get_post_meta($post->ID, '_eventosapp_organizador', true) ?: '';
    $organizador_email = get_post_meta($post->ID, '_eventosapp_organizador_email', true) ?: '';
    $organizador_tel   = get_post_meta($post->ID, '_eventosapp_organizador_tel', true) ?: '';

    // Modalidad del evento
    $modalidad_evento = function_exists('eventosapp_get_event_modalidad')
        ? eventosapp_get_event_modalidad($post->ID)
        : (get_post_meta($post->ID, '_eventosapp_event_modalidad', true) ?: 'presencial');
    $modalidad_evento = function_exists('eventosapp_normalize_event_modalidad')
        ? eventosapp_normalize_event_modalidad($modalidad_evento)
        : (in_array($modalidad_evento, ['presencial','virtual','presencial_virtual'], true) ? $modalidad_evento : 'presencial');
    $modalidad_options = function_exists('eventosapp_event_modalidad_options') ? eventosapp_event_modalidad_options() : [
        'presencial'         => 'Presencial',
        'virtual'            => 'Virtual',
        'presencial_virtual' => 'Presencial y Virtual',
    ];
    $virtual_url                = get_post_meta($post->ID, '_eventosapp_virtual_url', true) ?: '';
    $virtual_platform           = get_post_meta($post->ID, '_eventosapp_virtual_platform', true) ?: '';
    $virtual_access_datetime_raw = get_post_meta($post->ID, '_eventosapp_virtual_access_datetime', true) ?: '';
    $virtual_access_datetime     = function_exists('eventosapp_normalize_datetime_local_value') ? eventosapp_normalize_datetime_local_value($virtual_access_datetime_raw) : $virtual_access_datetime_raw;
    $virtual_access_use_landing = get_post_meta($post->ID, '_eventosapp_virtual_access_use_landing', true);
    $virtual_access_use_landing = ((string) $virtual_access_use_landing === '0') ? '0' : '1';

    // === VINCULACIÓN CON CLIENTE ===
    $usar_cliente = get_post_meta($post->ID, '_eventosapp_usar_cliente', true) === '1' ? '1' : '0';
    $cliente_id   = (int) get_post_meta($post->ID, '_eventosapp_cliente_id', true);

    if (!is_array($fechas_noco)) {
        $fechas_noco = is_string($fechas_noco) && $fechas_noco ? explode(',', $fechas_noco) : [];
    }

    // === PERSONALIZACIÓN WALLET (por evento) ===
    $wallet_custom_enable = get_post_meta($post->ID, '_eventosapp_wallet_custom_enable', true) === '1' ? '1' : '0';
    $wallet_class_id      = get_post_meta($post->ID, '_eventosapp_wallet_class_id', true) ?: '';
    $wallet_logo_url      = get_post_meta($post->ID, '_eventosapp_wallet_logo_url', true) ?: '';
    $wallet_hero_url      = get_post_meta($post->ID, '_eventosapp_wallet_hero_img_url', true) ?: '';
    $wallet_hex_color     = get_post_meta($post->ID, '_eventosapp_wallet_hex_color', true) ?: '#3782C4';

    // Apple overrides (opcionales por evento)
    $apple_icon_url  = get_post_meta($post->ID, '_eventosapp_apple_icon_url',  true) ?: '';
    $apple_logo_url  = get_post_meta($post->ID, '_eventosapp_apple_logo_url',  true) ?: '';
    $apple_strip_url = get_post_meta($post->ID, '_eventosapp_apple_strip_url', true) ?: '';
    $apple_hex_bg    = get_post_meta($post->ID, '_eventosapp_apple_hex_bg',    true) ?: '';
    $apple_hex_fg    = get_post_meta($post->ID, '_eventosapp_apple_hex_fg',    true) ?: '';
    $apple_hex_label = get_post_meta($post->ID, '_eventosapp_apple_hex_label', true) ?: '';

    $readonly = ($wallet_custom_enable === '1' && $wallet_class_id) ? 'readonly' : '';
    $note = ($readonly
        ? '<span class="muted">Este ID fue generado automáticamente y no es editable.</span>'
        : '<span class="muted">Si no incluye ".", se antepone el Issuer ID.</span>'
    );

    // Obtener lista de clientes para el dropdown
    $clientes = get_posts([
        'post_type'      => 'eventosapp_cliente',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ]);

    wp_nonce_field('eventosapp_detalles_evento', 'eventosapp_nonce');
    ?>
    <style>
    .eventosapp_fecha_row { margin-bottom: 10px; }
    .eventosapp-input-wide { width: 99%; }
    .eventosapp-wallet-toggle .desc { font-size:12px; color:#666; display:block; margin-top:2px; }
    .eventosapp-wallet-grid { border:1px solid #e5e5e5; padding:10px; margin-top:6px; background:#fafafa; }
    .muted { color:#666; font-size:12px; }
    .evapp-org-toggle-wrap { margin-bottom:6px; }
    .evapp-org-toggle-wrap label { font-weight:400; cursor:pointer; }
    .evapp-modalidad-box { border:1px solid #dbeafe; background:#eff6ff; padding:12px; border-radius:10px; margin:12px 0 16px; }
    .evapp-modalidad-box label { font-weight:600; }
    .evapp-modalidad-help { margin:6px 0 0; color:#1f4f82; font-size:12px; line-height:1.4; }
    .evapp-physical-fields, .evapp-virtual-fields { border:1px solid #e5e7eb; background:#fafafa; padding:12px; border-radius:10px; margin:10px 0 16px; }
    .evapp-virtual-fields h4, .evapp-physical-fields h4 { margin:0 0 10px; }
    .evapp-virtual-redirect-toggle { display:block; border:1px solid #c7d2fe; background:#eef2ff; border-radius:10px; padding:10px 12px; margin:12px 0; }
    .evapp-virtual-redirect-toggle label { display:flex; gap:8px; align-items:flex-start; font-weight:600; cursor:pointer; color:#1e3a8a; }
    .evapp-virtual-redirect-toggle input { margin-top:2px; }
    .evapp-virtual-redirect-toggle .muted { display:block; margin:6px 0 0 24px; line-height:1.4; }
    </style>

    <!-- Tipo de fecha -->
    <div class="eventosapp_fecha_row">
        <label><strong>Tipo de fecha del evento:</strong></label><br>
        <select id="eventosapp_tipo_fecha" name="eventosapp_tipo_fecha">
            <option value="unica"        <?php selected($tipo_fecha, 'unica'); ?>>Una sola fecha</option>
            <option value="consecutiva"  <?php selected($tipo_fecha, 'consecutiva'); ?>>Varios días consecutivos</option>
            <option value="noconsecutiva"<?php selected($tipo_fecha, 'noconsecutiva'); ?>>Varios días NO consecutivos</option>
        </select>
    </div>

    <div id="eventosapp_fechas_wrap">
        <div id="fecha_unica_wrap" style="display:<?php echo ($tipo_fecha === 'unica' ? 'block' : 'none'); ?>;">
            <label>Fecha del evento:</label><br>
            <input type="date" name="eventosapp_fecha_unica" value="<?php echo esc_attr($fecha_unica); ?>">
        </div>

        <div id="fecha_consecutiva_wrap" style="display:<?php echo ($tipo_fecha === 'consecutiva' ? 'block' : 'none'); ?>;">
            <label>Fecha de inicio:</label><br>
            <input type="date" name="eventosapp_fecha_inicio" value="<?php echo esc_attr($fecha_inicio); ?>"><br>
            <label>Fecha de cierre:</label><br>
            <input type="date" name="eventosapp_fecha_fin" value="<?php echo esc_attr($fecha_fin); ?>">
        </div>

        <div id="fecha_noco_wrap" style="display:<?php echo ($tipo_fecha === 'noconsecutiva' ? 'block' : 'none'); ?>;">
            <label>Fechas específicas (puede agregar varias):</label><br>
            <div id="eventosapp_fechas_noco_list">
                <?php
                if ($fechas_noco && is_array($fechas_noco)) {
                    foreach ($fechas_noco as $fecha) {
                        echo '<div><input type="date" name="eventosapp_fechas_noco[]" value="'.esc_attr($fecha).'" style="margin-bottom:2px;"> <button type="button" class="remove_fecha_noco button">-</button></div>';
                    }
                }
                ?>
            </div>
            <button type="button" id="add_fecha_noco" class="button">Agregar Fecha</button>
        </div>
    </div>

    <br>
    <label><strong>Hora de Inicio:</strong></label><br>
    <input type="time" name="eventosapp_hora_inicio" value="<?php echo esc_attr($hora_inicio); ?>"><br><br>

    <label><strong>Hora de Cierre:</strong></label><br>
    <input type="time" name="eventosapp_hora_cierre" value="<?php echo esc_attr($hora_cierre); ?>"><br><br>

    <label><strong>Zona Horaria:</strong></label><br>
    <select name="eventosapp_zona_horaria">
        <?php
        $zonas = timezone_identifiers_list();
        foreach ($zonas as $zona) {
            echo '<option value="' . esc_attr($zona) . '" '.selected($zona_horaria, $zona, false). '>' . esc_html($zona) . '</option>';
        }
        ?>
    </select>
    <br><br>

    <!-- MODALIDAD DEL EVENTO -->
    <div class="evapp-modalidad-box">
        <label for="eventosapp_event_modalidad"><strong>Modalidad del Evento:</strong></label><br>
        <select name="eventosapp_event_modalidad" id="eventosapp_event_modalidad">
            <?php foreach ($modalidad_options as $mode_key => $mode_label): ?>
                <option value="<?php echo esc_attr($mode_key); ?>" <?php selected($modalidad_evento, $mode_key); ?>><?php echo esc_html($mode_label); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="evapp-modalidad-help">
            Presencial usa ubicación física y tickets con QR/PDF/Wallet según la configuración actual. Virtual usa enlace de acceso y solo adjunta ICS. Presencial y Virtual permite elegir la modalidad por asistente.
        </p>
    </div>

    <!-- CAMPOS DEL EVENTO -->
    <div class="evapp-physical-fields" id="evapp_physical_fields">
        <h4>Ubicación física del evento</h4>
        <label><strong>Dirección del Evento:</strong></label><br>
        <input type="text" class="eventosapp-input-wide" name="eventosapp_direccion" value="<?php echo esc_attr($direccion); ?>" placeholder="Ej: Calle 123 #45-67, Ciudad"><br><br>

        <label><strong>Coordenadas Google Maps (lat,lng):</strong></label><br>
        <input type="text" class="eventosapp-input-wide" name="eventosapp_coordenadas" value="<?php echo esc_attr($coordenadas); ?>" placeholder="Ej: 11.0041,-74.8067"><br>
        <span class="muted">Puedes obtenerlas desde <a href="https://www.google.com/maps" target="_blank" rel="noopener">Google Maps</a></span>
    </div>

    <div class="evapp-virtual-fields" id="evapp_virtual_fields">
        <h4>Acceso virtual del evento</h4>
        <label><strong>Plataforma del evento virtual:</strong></label><br>
        <input type="text" class="eventosapp-input-wide" name="eventosapp_virtual_platform" value="<?php echo esc_attr($virtual_platform); ?>" placeholder="Ej: Zoom, Google Meet, Microsoft Teams"><br><br>

        <label><strong>Enlace del evento virtual:</strong></label><br>
        <input type="url" class="eventosapp-input-wide" name="eventosapp_virtual_url" value="<?php echo esc_url($virtual_url); ?>" placeholder="https://..."><br>

        <div class="evapp-virtual-redirect-toggle">
            <label for="eventosapp_virtual_access_use_landing">
                <input type="checkbox" id="eventosapp_virtual_access_use_landing" name="eventosapp_virtual_access_use_landing" value="1" <?php checked($virtual_access_use_landing, '1'); ?>>
                Redirigir el acceso virtual a la landing de EventosApp
            </label>
            <span class="muted">Activo por defecto. Si se desmarca, los enlaces de acceso virtual enviados por correo, WhatsApp y mostrados en el ticket abrirán directamente el enlace de la plataforma configurada arriba.</span>
        </div>

        <label><strong>Fecha y hora para habilitar el enlace:</strong></label><br>
        <input type="datetime-local" name="eventosapp_virtual_access_datetime" value="<?php echo esc_attr($virtual_access_datetime); ?>">
        <br><span class="muted">Antes de esta fecha/hora, la landing del ticket virtual mostrará que el botón todavía no está habilitado. Esta fecha/hora se interpreta en la zona horaria seleccionada arriba y no se convierte a UTC.</span>
    </div>

    <!-- === NOMBRE DEL ORGANIZADOR (con opción de vincular cliente) === -->
    <label><strong>Nombre del Organizador:</strong></label><br>

    <div class="evapp-org-toggle-wrap">
        <label>
            <input type="checkbox" id="evapp_usar_cliente_cb" name="eventosapp_usar_cliente" value="1" <?php checked($usar_cliente, '1'); ?>>
            Organizador está creado (seleccionar desde Clientes)
        </label>
    </div>

    <!-- Campo texto libre (por defecto) -->
    <div id="evapp_org_texto_wrap" style="display:<?php echo ($usar_cliente === '1' ? 'none' : 'block'); ?>;">
        <input type="text" class="eventosapp-input-wide" name="eventosapp_organizador" id="evapp_organizador_texto" value="<?php echo esc_attr($organizador); ?>">
    </div>

    <!-- Dropdown de clientes (cuando checkbox activo) -->
    <div id="evapp_org_cliente_wrap" style="display:<?php echo ($usar_cliente === '1' ? 'block' : 'none'); ?>;">
        <?php if (empty($clientes)): ?>
            <p class="muted">⚠️ No hay clientes creados aún. <a href="<?php echo admin_url('post-new.php?post_type=eventosapp_cliente'); ?>" target="_blank">Crear cliente</a></p>
        <?php else: ?>
            <select name="eventosapp_cliente_id" id="evapp_cliente_select" class="eventosapp-input-wide">
                <option value="">— Seleccionar cliente —</option>
                <?php foreach ($clientes as $cid):
                    $cnombre = get_post_meta($cid, '_cliente_nombre_empresa', true) ?: get_the_title($cid);
                ?>
                    <option value="<?php echo esc_attr($cid); ?>" <?php selected($cliente_id, $cid); ?>>
                        <?php echo esc_html($cnombre); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($cliente_id):
                $edit_url = get_edit_post_link($cliente_id);
            ?>
                <span class="muted" id="evapp_cliente_link_wrap"> → <a href="<?php echo esc_url($edit_url); ?>" target="_blank">Ver ficha del cliente</a></span>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <br>

    <label><strong>Correo Electrónico del Organizador:</strong></label><br>
    <input type="email" class="eventosapp-input-wide" name="eventosapp_organizador_email" value="<?php echo esc_attr($organizador_email); ?>"><br><br>

    <label><strong>Número de Contacto del Organizador:</strong></label><br>
    <input type="text" class="eventosapp-input-wide" name="eventosapp_organizador_tel" value="<?php echo esc_attr($organizador_tel); ?>"><br><br>

    <!-- === PERSONALIZACIÓN GOOGLE/APPLE POR EVENTO === -->
    <div class="eventosapp-wallet-toggle">
        <label>
            <input type="checkbox" id="eventosapp_wallet_custom_enable" name="eventosapp_wallet_custom_enable" value="1" <?php checked($wallet_custom_enable, '1'); ?>>
            <strong>Personalizar Wallet para este evento</strong>
        </label>
        <span class="desc">Si está desmarcado, se usarán los valores por defecto de <em>Integraciones</em>.</span>
    </div>

    <div id="eventosapp_wallet_custom_fields" class="eventosapp-wallet-grid" style="display:<?php echo ($wallet_custom_enable === '1' ? 'block' : 'none'); ?>;">
        <h3 style="margin:6px 0 10px">Google Wallet</h3>
        <table class="form-table" style="margin:0;">
            <tr>
                <th style="width:180px;"><label for="eventosapp_wallet_class_id">Class ID:</label></th>
                <td>
                    <input type="text" class="regular-text" id="eventosapp_wallet_class_id" name="eventosapp_wallet_class_id"
                           value="<?php echo esc_attr($wallet_class_id); ?>" <?php echo $readonly; ?>
                           placeholder="ej: issuerId.event_123">
                    <?php echo $note; ?>
                </td>
            </tr>
            <tr>
                <th><label for="eventosapp_wallet_logo_url">URL Logo:</label></th>
                <td><input type="url" class="regular-text" id="eventosapp_wallet_logo_url" name="eventosapp_wallet_logo_url" value="<?php echo esc_attr($wallet_logo_url); ?>" placeholder="https://.../logo.png"></td>
            </tr>
            <tr>
                <th><label for="eventosapp_wallet_hex_color">Color HEX (clase):</label></th>
                <td>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <input type="text" class="regular-text eventosapp-color-field" id="eventosapp_wallet_hex_color" name="eventosapp_wallet_hex_color" value="<?php echo esc_attr($wallet_hex_color ?: '#3782C4'); ?>" data-default-color="#3782C4" />
                        <span id="eventosapp_wallet_hex_color_preview" style="width:34px;height:22px;border-radius:4px;border:1px solid #ccd0d4;display:inline-block;background:<?php echo esc_attr($wallet_hex_color ?: '#3782C4'); ?>"></span>
                    </div>
                    <span class="muted">Color de la clase (ej. #3782C4). Por defecto #3782C4.</span>
                </td>
            </tr>
            <tr>
                <th><label for="eventosapp_wallet_hero_img_url">URL Imagen Hero:</label></th>
                <td><input type="url" class="regular-text" id="eventosapp_wallet_hero_img_url" name="eventosapp_wallet_hero_img_url" value="<?php echo esc_attr($wallet_hero_url); ?>" placeholder="https://.../hero.jpg"></td>
            </tr>
        </table>

        <hr>
        <h3 style="margin:10px 0 10px">Apple Wallet (opcional / overrides por evento)</h3>
        <table class="form-table" style="margin:0;">
            <tr>
                <th><label for="eventosapp_apple_icon_url">Apple Icon URL (png)</label></th>
                <td><input type="url" class="regular-text" id="eventosapp_apple_icon_url" name="eventosapp_apple_icon_url" value="<?php echo esc_attr($apple_icon_url); ?>" placeholder="https://.../icon.png"></td>
            </tr>
            <tr>
                <th><label for="eventosapp_apple_logo_url">Apple Logo URL (png)</label></th>
                <td><input type="url" class="regular-text" id="eventosapp_apple_logo_url" name="eventosapp_apple_logo_url" value="<?php echo esc_attr($apple_logo_url); ?>" placeholder="https://.../logo.png"></td>
            </tr>
            <tr>
                <th><label for="eventosapp_apple_strip_url">Apple Strip URL (png)</label></th>
                <td><input type="url" class="regular-text" id="eventosapp_apple_strip_url" name="eventosapp_apple_strip_url" value="<?php echo esc_attr($apple_strip_url); ?>" placeholder="https://.../strip.png"></td>
            </tr>
            <tr>
                <th><label for="eventosapp_apple_hex_bg">Apple BG HEX</label></th>
                <td><input type="text" class="regular-text" id="eventosapp_apple_hex_bg" name="eventosapp_apple_hex_bg" value="<?php echo esc_attr($apple_hex_bg); ?>" placeholder="#3782C4"></td>
            </tr>
            <tr>
                <th><label for="eventosapp_apple_hex_fg">Apple FG HEX</label></th>
                <td><input type="text" class="regular-text" id="eventosapp_apple_hex_fg" name="eventosapp_apple_hex_fg" value="<?php echo esc_attr($apple_hex_fg); ?>" placeholder="#FFFFFF"></td>
            </tr>
            <tr>
                <th><label for="eventosapp_apple_hex_label">Apple Label HEX</label></th>
                <td><input type="text" class="regular-text" id="eventosapp_apple_hex_label" name="eventosapp_apple_hex_label" value="<?php echo esc_attr($apple_hex_label); ?>" placeholder="#FFFFFF"></td>
            </tr>
        </table>
    </div>

    <script>
    (function($){
      $('#eventosapp_tipo_fecha').on('change', function() {
          var tipo = $(this).val();
          $('#fecha_unica_wrap, #fecha_consecutiva_wrap, #fecha_noco_wrap').hide();
          if(tipo == 'unica')        $('#fecha_unica_wrap').show();
          if(tipo == 'consecutiva')  $('#fecha_consecutiva_wrap').show();
          if(tipo == 'noconsecutiva')$('#fecha_noco_wrap').show();
      });
      $('#add_fecha_noco').on('click', function(){
          $('#eventosapp_fechas_noco_list').append('<div><input type="date" name="eventosapp_fechas_noco[]" style="margin-bottom:2px;"> <button type="button" class="remove_fecha_noco button">-</button></div>');
      });
      $(document).on('click', '.remove_fecha_noco', function(){
          $(this).parent().remove();
      });
      $('#eventosapp_wallet_custom_enable').on('change', function(){
          if ($(this).is(':checked')) {
              $('#eventosapp_wallet_custom_fields').slideDown(120);
          } else {
              $('#eventosapp_wallet_custom_fields').slideUp(120);
          }
      });

      function evappToggleModalidadFields(){
          var mode = $('#eventosapp_event_modalidad').val() || 'presencial';
          if (mode === 'virtual') {
              $('#evapp_physical_fields').hide();
              $('#evapp_virtual_fields').show();
          } else if (mode === 'presencial_virtual') {
              $('#evapp_physical_fields').show();
              $('#evapp_virtual_fields').show();
          } else {
              $('#evapp_physical_fields').show();
              $('#evapp_virtual_fields').hide();
          }
      }
      $('#eventosapp_event_modalidad').on('change', evappToggleModalidadFields);
      evappToggleModalidadFields();

      // === Toggle Organizador: texto libre vs cliente ===
      $('#evapp_usar_cliente_cb').on('change', function(){
          if ($(this).is(':checked')) {
              $('#evapp_org_texto_wrap').hide();
              $('#evapp_org_cliente_wrap').show();
          } else {
              $('#evapp_org_cliente_wrap').hide();
              $('#evapp_org_texto_wrap').show();
          }
      });

      // Actualizar enlace "Ver ficha del cliente" al cambiar el select
      $('#evapp_cliente_select').on('change', function(){
          var cid = $(this).val();
          var $linkWrap = $('#evapp_cliente_link_wrap');
          if (!cid) {
              $linkWrap.hide();
              return;
          }
          // Construir URL de edición dinámicamente
          var editBase = '<?php echo esc_js(admin_url('post.php?action=edit&post=')); ?>';
          if ($linkWrap.length) {
              $linkWrap.find('a').attr('href', editBase + cid);
              $linkWrap.show();
          }
      });
    })(jQuery);

    // Color Picker + preview
    jQuery(function($){
      var $inputColor = $('#eventosapp_wallet_hex_color');
      var $preview    = $('#eventosapp_wallet_hex_color_preview');

      if ($inputColor.length && $inputColor.wpColorPicker) {
        $inputColor.wpColorPicker({
          change: function(event, ui){
            if (ui && ui.color) $preview.css('background-color', ui.color.toString());
          },
          clear: function(){
            var def = $inputColor.data('default-color') || '#3782C4';
            $preview.css('background-color', def);
          }
        });
        $inputColor.on('input', function(){
          var val = $(this).val();
          if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(val)) $preview.css('background-color', val);
        });
      }
    });
    </script>
    <?php
}


/**
 * Metabox: Localidades
 */
function eventosapp_render_metabox_localidades($post) {
    $localidades = get_post_meta($post->ID, '_eventosapp_localidades', true);
    if (!is_array($localidades) || empty($localidades)) {
        $localidades = ['General', 'VIP', 'Platino'];
    }
    wp_nonce_field('eventosapp_localidades_guardar', 'eventosapp_localidades_nonce');
    ?>
    <p>
        <strong>Define las localidades disponibles para este evento:</strong><br>
        <span style="font-size:12px;color:#666;">Puedes agregar, quitar o editar localidades (ej: General, VIP, Platino, etc).</span>
    </p>
    <div id="eventosapp_localidades_list">
        <?php foreach ($localidades as $i => $loc): ?>
            <div style="margin-bottom:4px;">
                <input type="text" name="eventosapp_localidades[]" value="<?php echo esc_attr($loc); ?>" placeholder="Nombre localidad" style="width:220px;">
                <button type="button" class="remove_localidad button">Eliminar</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" id="add_localidad" class="button">Agregar Localidad</button>
    <script>
    (function($){
        $('#add_localidad').on('click', function(){
            $('#eventosapp_localidades_list').append(
                '<div style="margin-bottom:4px;">' +
                '<input type="text" name="eventosapp_localidades[]" value="" placeholder="Nombre localidad" style="width:220px;">' +
                '<button type="button" class="remove_localidad button">Eliminar</button>' +
                '</div>'
            );
        });
        $(document).on('click', '.remove_localidad', function(){
            $(this).parent().remove();
        });
    })(jQuery);
    </script>
    <?php
}


/**
 * Metabox: Autenticación del Networking Global.
 * Permite definir por evento qué campos del ticket se usarán para iniciar sesión
 * en la página /networking/global/.
 */
function eventosapp_render_metabox_networking_global_auth($post) {
    $options = function_exists('eventosapp_networking_global_auth_field_options') ? eventosapp_networking_global_auth_field_options() : [];
    $selected = function_exists('eventosapp_get_event_networking_global_auth_fields')
        ? eventosapp_get_event_networking_global_auth_fields($post->ID)
        : [ 'cc', 'apellido' ];

    if (empty($options)) {
        echo '<p style="color:#b32d2e;">No se pudieron cargar los campos disponibles para autenticación.</p>';
        return;
    }

    wp_nonce_field('eventosapp_networking_global_auth_guardar', 'eventosapp_networking_global_auth_nonce');
    ?>
    <style>
        .evapp-netauth-box { border:1px solid #dbeafe; background:#eff6ff; border-radius:10px; padding:14px; margin:10px 0; }
        .evapp-netauth-title { margin:0 0 8px; font-weight:700; color:#1e3a8a; }
        .evapp-netauth-help { margin:0 0 12px; color:#1f4f82; font-size:12px; line-height:1.45; }
        .evapp-netauth-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:8px 14px; }
        .evapp-netauth-item { display:flex; align-items:flex-start; gap:8px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:10px; }
        .evapp-netauth-item input { margin-top:2px; }
        .evapp-netauth-item strong { display:block; color:#111827; }
        .evapp-netauth-item small { display:block; color:#6b7280; line-height:1.35; margin-top:2px; }
        .evapp-netauth-warning { margin:12px 0 0; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; padding:10px; border-radius:8px; font-size:12px; line-height:1.45; }
    </style>

    <div class="evapp-netauth-box">
        <p class="evapp-netauth-title">Campos requeridos para autenticar al asistente</p>
        <p class="evapp-netauth-help">
            Esta configuración aplica únicamente para este evento. El formulario público de Networking Global solicitará solo los campos marcados aquí. Si no seleccionas ninguno, se conservará el comportamiento por defecto: <strong>Cédula + Apellido</strong>.
        </p>

        <div class="evapp-netauth-grid">
            <?php foreach ($options as $key => $field): ?>
                <label class="evapp-netauth-item" for="eventosapp_networking_global_auth_field_<?php echo esc_attr($key); ?>">
                    <input
                        type="checkbox"
                        id="eventosapp_networking_global_auth_field_<?php echo esc_attr($key); ?>"
                        name="eventosapp_networking_global_auth_fields[]"
                        value="<?php echo esc_attr($key); ?>"
                        <?php checked(in_array($key, $selected, true)); ?>
                    >
                    <span>
                        <strong><?php echo esc_html($field['label'] ?? $key); ?></strong>
                        <small><?php echo esc_html($field['help'] ?? 'Campo disponible para validar identidad.'); ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="evapp-netauth-warning">
            Recomendación aplicada: mantén al menos un campo único como <strong>Cédula</strong>, <strong>Email</strong>, <strong>Teléfono</strong> o <strong>NIT</strong> para evitar coincidencias entre asistentes con nombres o apellidos iguales.
        </div>
    </div>
    <?php
}

/**
 * Metabox: Personalización del módulo de Autogestión Kiosko.
 * Permite definir tema, fondo, logo principal y logos adicionales por evento.
 */
function eventosapp_render_metabox_self_checkin_design($post) {
    $config = function_exists('eventosapp_self_checkin_get_design_config')
        ? eventosapp_self_checkin_get_design_config($post->ID)
        : [];

    $theme                  = $config['theme'] ?? 'light';
    $background_type        = $config['background_type'] ?? 'default';
    $background_color       = $config['background_color'] ?? '';
    $background_image_url   = $config['background_image_url'] ?? '';
    $background_image_id    = absint(get_post_meta($post->ID, '_eventosapp_self_checkin_background_image_id', true));
    $background_size        = $config['background_size'] ?? 'cover';
    $background_position    = $config['background_position'] ?? 'center center';
    $background_repeat      = $config['background_repeat'] ?? 'no-repeat';
    $background_attachment  = $config['background_attachment'] ?? 'scroll';
    $background_class       = $config['background_class'] ?? 'eventosapp-self-checkin-bg';
    $main_logo_url          = $config['main_logo_url'] ?? '';
    $main_logo_id           = absint($config['main_logo_id'] ?? get_post_meta($post->ID, '_eventosapp_self_checkin_main_logo_id', true));
    $main_logo_width        = absint(get_post_meta($post->ID, '_eventosapp_self_checkin_main_logo_width', true)) ?: 220;
    $main_logo_max_height   = absint(get_post_meta($post->ID, '_eventosapp_self_checkin_main_logo_max_height', true)) ?: 120;
    $extra_logos            = $config['extra_logos'] ?? [];
    $extra_box_width        = absint(get_post_meta($post->ID, '_eventosapp_self_checkin_extra_logos_box_width', true)) ?: 760;
    $extra_box_height       = absint(get_post_meta($post->ID, '_eventosapp_self_checkin_extra_logos_box_height', true)) ?: 120;
    $extra_gap              = absint(get_post_meta($post->ID, '_eventosapp_self_checkin_extra_logos_gap', true)) ?: 18;
    $extra_logo_max_height  = absint(get_post_meta($post->ID, '_eventosapp_self_checkin_extra_logo_max_height', true)) ?: 72;

    $positions = [
        'left top'      => 'Arriba izquierda',
        'center top'    => 'Arriba centro',
        'right top'     => 'Arriba derecha',
        'left center'   => 'Centro izquierda',
        'center center' => 'Centro centro',
        'right center'  => 'Centro derecha',
        'left bottom'   => 'Abajo izquierda',
        'center bottom' => 'Abajo centro',
        'right bottom'  => 'Abajo derecha',
    ];

    wp_nonce_field('eventosapp_self_checkin_design_guardar', 'eventosapp_self_checkin_design_nonce');
    ?>
    <style>
        .evapp-kiosk-design-box{border:1px solid #dbeafe;background:#eff6ff;border-radius:10px;padding:14px;margin:10px 0;color:#111827}
        .evapp-kiosk-design-title{margin:0 0 8px;font-weight:800;color:#1e3a8a;font-size:14px;text-transform:uppercase;letter-spacing:.03em}
        .evapp-kiosk-design-help{margin:0 0 12px;color:#1f4f82;font-size:12px;line-height:1.45}
        .evapp-kiosk-design-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-top:10px}
        .evapp-kiosk-field{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px}
        .evapp-kiosk-field label{display:block;font-weight:700;margin:0 0 6px;color:#111827}
        .evapp-kiosk-field small{display:block;color:#6b7280;line-height:1.35;margin-top:5px}
        .evapp-kiosk-field input[type="text"],.evapp-kiosk-field input[type="number"],.evapp-kiosk-field select{width:100%;max-width:100%}
        .evapp-kiosk-upload-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .evapp-kiosk-preview{display:flex;align-items:center;justify-content:center;width:150px;min-height:74px;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc;margin-top:8px;overflow:hidden;padding:8px;color:#64748b;font-size:12px;text-align:center}
        .evapp-kiosk-preview img{display:block;max-width:100%;max-height:90px;width:auto;height:auto;object-fit:contain}
        .evapp-kiosk-logos-list{display:grid;gap:10px;margin-top:10px}
        .evapp-kiosk-logo-item{display:grid;grid-template-columns:150px minmax(0,1fr) auto;gap:10px;align-items:center;border:1px solid #e5e7eb;background:#fff;border-radius:10px;padding:10px}
        .evapp-kiosk-logo-fields{display:grid;gap:8px}
        .evapp-kiosk-class-pill{display:inline-block;background:#0f172a;color:#fff;border-radius:999px;padding:4px 8px;font-family:monospace;font-size:12px}
        @media(max-width:782px){.evapp-kiosk-logo-item{grid-template-columns:1fr}.evapp-kiosk-preview{width:100%}}
    </style>

    <div class="evapp-kiosk-design-box">
        <p class="evapp-kiosk-design-title">Tema visual del panel</p>
        <p class="evapp-kiosk-design-help">Esta configuración aplica solo para este evento. El widget de búsqueda e impresión toma estos colores automáticamente, salvo que un control de estilo de Elementor lo sobrescriba de forma específica.</p>
        <div class="evapp-kiosk-design-grid">
            <div class="evapp-kiosk-field">
                <label for="eventosapp_self_checkin_theme">Modo de colores</label>
                <select id="eventosapp_self_checkin_theme" name="eventosapp_self_checkin_theme">
                    <option value="light" <?php selected($theme, 'light'); ?>>Modo claro - Fondo blanco</option>
                    <option value="dark" <?php selected($theme, 'dark'); ?>>Modo oscuro - Fondo oscuro</option>
                </select>
                <small>El modo claro usa paneles blancos y textos oscuros. El modo oscuro usa paneles oscuros, textos claros y botones optimizados para fondos oscuros.</small>
            </div>
        </div>
    </div>

    <div class="evapp-kiosk-design-box">
        <p class="evapp-kiosk-design-title">Control del fondo</p>
        <p class="evapp-kiosk-design-help">Agrega la clase <span class="evapp-kiosk-class-pill"><?php echo esc_html($background_class); ?></span> al contenedor o sección de Elementor que debe tomar este fondo.</p>
        <div class="evapp-kiosk-design-grid">
            <div class="evapp-kiosk-field">
                <label for="eventosapp_self_checkin_background_class">Clase del contenedor en Elementor</label>
                <input type="text" id="eventosapp_self_checkin_background_class" name="eventosapp_self_checkin_background_class" value="<?php echo esc_attr($background_class); ?>" placeholder="eventosapp-self-checkin-bg">
                <small>Escribe la clase sin punto. En Elementor debes poner la misma clase en Avanzado &gt; Clases CSS.</small>
            </div>

            <div class="evapp-kiosk-field">
                <label for="eventosapp_self_checkin_background_type">Tipo de fondo</label>
                <select id="eventosapp_self_checkin_background_type" name="eventosapp_self_checkin_background_type">
                    <option value="default" <?php selected($background_type, 'default'); ?>>Automático según modo</option>
                    <option value="color" <?php selected($background_type, 'color'); ?>>Color</option>
                    <option value="image" <?php selected($background_type, 'image'); ?>>Imagen</option>
                </select>
                <small>Si escoges color y lo dejas vacío, se usará el color por defecto del modo seleccionado.</small>
            </div>

            <div class="evapp-kiosk-field">
                <label for="eventosapp_self_checkin_background_color">Color de fondo</label>
                <input type="text" class="evapp-kiosk-color" id="eventosapp_self_checkin_background_color" name="eventosapp_self_checkin_background_color" value="<?php echo esc_attr($background_color); ?>" placeholder="#ffffff">
                <small>Opcional. Déjalo vacío para usar el color automático del modo claro u oscuro.</small>
            </div>

            <div class="evapp-kiosk-field">
                <label>Imagen de fondo</label>
                <input type="hidden" id="eventosapp_self_checkin_background_image_id" name="eventosapp_self_checkin_background_image_id" value="<?php echo esc_attr($background_image_id); ?>">
                <input type="text" id="eventosapp_self_checkin_background_image_url" name="eventosapp_self_checkin_background_image_url" value="<?php echo esc_attr($background_image_url); ?>" placeholder="URL de imagen">
                <div class="evapp-kiosk-upload-row" style="margin-top:8px;">
                    <button type="button" class="button evapp-kiosk-upload" data-target-url="#eventosapp_self_checkin_background_image_url" data-target-id="#eventosapp_self_checkin_background_image_id" data-preview="#eventosapp_self_checkin_background_image_preview">Subir / elegir imagen</button>
                    <button type="button" class="button evapp-kiosk-clear-media" data-target-url="#eventosapp_self_checkin_background_image_url" data-target-id="#eventosapp_self_checkin_background_image_id" data-preview="#eventosapp_self_checkin_background_image_preview">Quitar</button>
                </div>
                <div class="evapp-kiosk-preview" id="eventosapp_self_checkin_background_image_preview">
                    <?php echo $background_image_url ? '<img src="'.esc_url($background_image_url).'" alt="Fondo kiosko">' : 'Sin imagen'; ?>
                </div>
            </div>

            <div class="evapp-kiosk-field">
                <label for="eventosapp_self_checkin_background_size">Tamaño de imagen</label>
                <select id="eventosapp_self_checkin_background_size" name="eventosapp_self_checkin_background_size">
                    <option value="cover" <?php selected($background_size, 'cover'); ?>>Cover - cubrir todo</option>
                    <option value="contain" <?php selected($background_size, 'contain'); ?>>Contain - mostrar completa</option>
                    <option value="auto" <?php selected($background_size, 'auto'); ?>>Auto - tamaño original</option>
                </select>
            </div>

            <div class="evapp-kiosk-field">
                <label for="eventosapp_self_checkin_background_position">Posición de imagen</label>
                <select id="eventosapp_self_checkin_background_position" name="eventosapp_self_checkin_background_position">
                    <?php foreach ($positions as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($background_position, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="evapp-kiosk-field">
                <label for="eventosapp_self_checkin_background_repeat">Repetición</label>
                <select id="eventosapp_self_checkin_background_repeat" name="eventosapp_self_checkin_background_repeat">
                    <option value="no-repeat" <?php selected($background_repeat, 'no-repeat'); ?>>No repetir</option>
                    <option value="repeat" <?php selected($background_repeat, 'repeat'); ?>>Repetir</option>
                    <option value="repeat-x" <?php selected($background_repeat, 'repeat-x'); ?>>Repetir horizontal</option>
                    <option value="repeat-y" <?php selected($background_repeat, 'repeat-y'); ?>>Repetir vertical</option>
                </select>
            </div>

            <div class="evapp-kiosk-field">
                <label for="eventosapp_self_checkin_background_attachment">Comportamiento</label>
                <select id="eventosapp_self_checkin_background_attachment" name="eventosapp_self_checkin_background_attachment">
                    <option value="scroll" <?php selected($background_attachment, 'scroll'); ?>>Normal / scroll</option>
                    <option value="fixed" <?php selected($background_attachment, 'fixed'); ?>>Fijo</option>
                </select>
            </div>
        </div>
    </div>

    <div class="evapp-kiosk-design-box">
        <p class="evapp-kiosk-design-title">Logo principal</p>
        <p class="evapp-kiosk-design-help">Este logo se usará automáticamente en el widget de búsqueda e impresión cuando el widget de Elementor no tenga un logo propio configurado.</p>
        <div class="evapp-kiosk-design-grid">
            <div class="evapp-kiosk-field">
                <label>Imagen del logo principal</label>
                <input type="hidden" id="eventosapp_self_checkin_main_logo_id" name="eventosapp_self_checkin_main_logo_id" value="<?php echo esc_attr($main_logo_id); ?>">
                <input type="text" id="eventosapp_self_checkin_main_logo_url" name="eventosapp_self_checkin_main_logo_url" value="<?php echo esc_attr($main_logo_url); ?>" placeholder="URL del logo principal">
                <div class="evapp-kiosk-upload-row" style="margin-top:8px;">
                    <button type="button" class="button evapp-kiosk-upload" data-target-url="#eventosapp_self_checkin_main_logo_url" data-target-id="#eventosapp_self_checkin_main_logo_id" data-preview="#eventosapp_self_checkin_main_logo_preview">Subir / elegir logo</button>
                    <button type="button" class="button evapp-kiosk-clear-media" data-target-url="#eventosapp_self_checkin_main_logo_url" data-target-id="#eventosapp_self_checkin_main_logo_id" data-preview="#eventosapp_self_checkin_main_logo_preview">Quitar</button>
                </div>
                <div class="evapp-kiosk-preview" id="eventosapp_self_checkin_main_logo_preview">
                    <?php echo $main_logo_url ? '<img src="'.esc_url($main_logo_url).'" alt="Logo principal">' : 'Sin logo'; ?>
                </div>
            </div>

            <div class="evapp-kiosk-field">
                <label for="eventosapp_self_checkin_main_logo_width">Ancho máximo del logo principal</label>
                <input type="number" min="40" max="1000" step="1" id="eventosapp_self_checkin_main_logo_width" name="eventosapp_self_checkin_main_logo_width" value="<?php echo esc_attr($main_logo_width); ?>">
                <small>En píxeles. La altura queda automática para conservar proporciones.</small>
            </div>

            <div class="evapp-kiosk-field">
                <label for="eventosapp_self_checkin_main_logo_max_height">Alto máximo del logo principal</label>
                <input type="number" min="24" max="600" step="1" id="eventosapp_self_checkin_main_logo_max_height" name="eventosapp_self_checkin_main_logo_max_height" value="<?php echo esc_attr($main_logo_max_height); ?>">
                <small>Evita que un logo muy vertical rompa el layout.</small>
            </div>
        </div>
    </div>

    <div class="evapp-kiosk-design-box">
        <p class="evapp-kiosk-design-title">Logos adicionales</p>
        <p class="evapp-kiosk-design-help">Agrega logos de patrocinadores, aliados o marcas. El nuevo widget de Elementor <strong>EventosApp Autogestión - Logos adicionales</strong> tomará estos datos automáticamente.</p>
        <div class="evapp-kiosk-design-grid">
            <div class="evapp-kiosk-field">
                <label for="eventosapp_self_checkin_extra_logos_box_width">Ancho del contenedor de logos</label>
                <input type="number" min="120" max="2400" step="1" id="eventosapp_self_checkin_extra_logos_box_width" name="eventosapp_self_checkin_extra_logos_box_width" value="<?php echo esc_attr($extra_box_width); ?>">
                <small>En píxeles. El contenedor siempre se ajusta al 100% disponible si la pantalla es menor.</small>
            </div>
            <div class="evapp-kiosk-field">
                <label for="eventosapp_self_checkin_extra_logos_box_height">Alto mínimo del contenedor</label>
                <input type="number" min="40" max="800" step="1" id="eventosapp_self_checkin_extra_logos_box_height" name="eventosapp_self_checkin_extra_logos_box_height" value="<?php echo esc_attr($extra_box_height); ?>">
            </div>
            <div class="evapp-kiosk-field">
                <label for="eventosapp_self_checkin_extra_logos_gap">Separación entre logos</label>
                <input type="number" min="0" max="200" step="1" id="eventosapp_self_checkin_extra_logos_gap" name="eventosapp_self_checkin_extra_logos_gap" value="<?php echo esc_attr($extra_gap); ?>">
            </div>
            <div class="evapp-kiosk-field">
                <label for="eventosapp_self_checkin_extra_logo_max_height">Alto máximo de cada logo</label>
                <input type="number" min="20" max="500" step="1" id="eventosapp_self_checkin_extra_logo_max_height" name="eventosapp_self_checkin_extra_logo_max_height" value="<?php echo esc_attr($extra_logo_max_height); ?>">
                <small>Cada imagen conserva sus proporciones y se acomoda automáticamente.</small>
            </div>
        </div>

        <div id="evapp-kiosk-extra-logos-list" class="evapp-kiosk-logos-list">
            <?php foreach ($extra_logos as $index => $logo):
                $logo_id  = absint($logo['id'] ?? 0);
                $logo_url = esc_url($logo['url'] ?? '');
                $logo_alt = sanitize_text_field($logo['alt'] ?? '');
                if (!$logo_url) { continue; }
                ?>
                <div class="evapp-kiosk-logo-item" data-index="<?php echo esc_attr($index); ?>">
                    <div class="evapp-kiosk-preview evapp-kiosk-extra-preview"><img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($logo_alt); ?>"></div>
                    <div class="evapp-kiosk-logo-fields">
                        <input type="hidden" class="evapp-kiosk-extra-id" name="eventosapp_self_checkin_extra_logos[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($logo_id); ?>">
                        <input type="text" class="evapp-kiosk-extra-url" name="eventosapp_self_checkin_extra_logos[<?php echo esc_attr($index); ?>][url]" value="<?php echo esc_attr($logo_url); ?>" placeholder="URL del logo">
                        <input type="text" class="evapp-kiosk-extra-alt" name="eventosapp_self_checkin_extra_logos[<?php echo esc_attr($index); ?>][alt]" value="<?php echo esc_attr($logo_alt); ?>" placeholder="Texto alternativo opcional">
                        <div class="evapp-kiosk-upload-row">
                            <button type="button" class="button evapp-kiosk-upload-extra">Subir / elegir logo</button>
                            <button type="button" class="button evapp-kiosk-remove-extra">Eliminar</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <p><button type="button" class="button button-primary" id="evapp-kiosk-add-extra-logo">Agregar logo adicional</button></p>
    </div>

    <script type="text/html" id="evapp-kiosk-extra-logo-template">
        <div class="evapp-kiosk-logo-item" data-index="__INDEX__">
            <div class="evapp-kiosk-preview evapp-kiosk-extra-preview">Sin logo</div>
            <div class="evapp-kiosk-logo-fields">
                <input type="hidden" class="evapp-kiosk-extra-id" name="eventosapp_self_checkin_extra_logos[__INDEX__][id]" value="">
                <input type="text" class="evapp-kiosk-extra-url" name="eventosapp_self_checkin_extra_logos[__INDEX__][url]" value="" placeholder="URL del logo">
                <input type="text" class="evapp-kiosk-extra-alt" name="eventosapp_self_checkin_extra_logos[__INDEX__][alt]" value="" placeholder="Texto alternativo opcional">
                <div class="evapp-kiosk-upload-row">
                    <button type="button" class="button evapp-kiosk-upload-extra">Subir / elegir logo</button>
                    <button type="button" class="button evapp-kiosk-remove-extra">Eliminar</button>
                </div>
            </div>
        </div>
    </script>

    <script>
    (function($){
        function evappOpenMediaFrame(args){
            var frame = wp.media({
                title: 'Seleccionar imagen',
                button: { text: 'Usar esta imagen' },
                multiple: false,
                library: { type: 'image' }
            });
            frame.on('select', function(){
                var attachment = frame.state().get('selection').first().toJSON();
                if (!attachment || !attachment.url) return;
                if (args.$url && args.$url.length) args.$url.val(attachment.url).trigger('change');
                if (args.$id && args.$id.length) args.$id.val(attachment.id || '');
                if (args.$preview && args.$preview.length) args.$preview.html('<img src="'+ attachment.url +'" alt="">');
            });
            frame.open();
        }

        $('.evapp-kiosk-color').each(function(){
            if ($(this).wpColorPicker) {
                $(this).wpColorPicker();
            }
        });

        $(document).on('click', '.evapp-kiosk-upload', function(e){
            e.preventDefault();
            var $btn = $(this);
            evappOpenMediaFrame({
                $url: $($btn.data('target-url')),
                $id: $($btn.data('target-id')),
                $preview: $($btn.data('preview'))
            });
        });

        $(document).on('click', '.evapp-kiosk-clear-media', function(e){
            e.preventDefault();
            var $btn = $(this);
            $($btn.data('target-url')).val('');
            $($btn.data('target-id')).val('');
            $($btn.data('preview')).html('Sin imagen');
        });

        var extraIndex = <?php echo (int) (count($extra_logos) + 1); ?>;
        $('#evapp-kiosk-add-extra-logo').on('click', function(e){
            e.preventDefault();
            var tpl = $('#evapp-kiosk-extra-logo-template').html().replace(/__INDEX__/g, extraIndex++);
            $('#evapp-kiosk-extra-logos-list').append(tpl);
        });

        $(document).on('click', '.evapp-kiosk-upload-extra', function(e){
            e.preventDefault();
            var $item = $(this).closest('.evapp-kiosk-logo-item');
            evappOpenMediaFrame({
                $url: $item.find('.evapp-kiosk-extra-url'),
                $id: $item.find('.evapp-kiosk-extra-id'),
                $preview: $item.find('.evapp-kiosk-extra-preview')
            });
        });

        $(document).on('click', '.evapp-kiosk-remove-extra', function(e){
            e.preventDefault();
            $(this).closest('.evapp-kiosk-logo-item').remove();
        });

        $('#eventosapp_self_checkin_background_class').on('input', function(){
            $('.evapp-kiosk-class-pill').text($(this).val() || 'eventosapp-self-checkin-bg');
        });
    })(jQuery);
    </script>
    <?php
}



/**
 * 3. Guardar los valores de la metabox (incluye Wallet por evento)
 */
add_action('save_post_eventosapp_event', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['eventosapp_nonce']) || !wp_verify_nonce($_POST['eventosapp_nonce'], 'eventosapp_detalles_evento')) return;

    $tipo = isset($_POST['eventosapp_tipo_fecha']) ? sanitize_text_field($_POST['eventosapp_tipo_fecha']) : 'unica';
    update_post_meta($post_id, '_eventosapp_tipo_fecha', $tipo);

    $modalidad = isset($_POST['eventosapp_event_modalidad']) ? sanitize_text_field(wp_unslash($_POST['eventosapp_event_modalidad'])) : 'presencial';
    $modalidad = function_exists('eventosapp_normalize_event_modalidad') ? eventosapp_normalize_event_modalidad($modalidad) : (in_array($modalidad, ['presencial','virtual','presencial_virtual'], true) ? $modalidad : 'presencial');
    update_post_meta($post_id, '_eventosapp_event_modalidad', $modalidad);

    if ($tipo === 'unica') {
        update_post_meta($post_id, '_eventosapp_fecha_unica', sanitize_text_field($_POST['eventosapp_fecha_unica'] ?? ''));
        update_post_meta($post_id, '_eventosapp_fecha_inicio', '');
        update_post_meta($post_id, '_eventosapp_fecha_fin', '');
        update_post_meta($post_id, '_eventosapp_fechas_noco', []);
    } elseif ($tipo === 'consecutiva') {
        update_post_meta($post_id, '_eventosapp_fecha_inicio', sanitize_text_field($_POST['eventosapp_fecha_inicio'] ?? ''));
        update_post_meta($post_id, '_eventosapp_fecha_fin', sanitize_text_field($_POST['eventosapp_fecha_fin'] ?? ''));
        update_post_meta($post_id, '_eventosapp_fecha_unica', '');
        update_post_meta($post_id, '_eventosapp_fechas_noco', []);
    } elseif ($tipo === 'noconsecutiva') {
        $fechas_noco = [];
        if (!empty($_POST['eventosapp_fechas_noco']) && is_array($_POST['eventosapp_fechas_noco'])) {
            foreach ($_POST['eventosapp_fechas_noco'] as $f) {
                $f = trim($f);
                if ($f !== '') $fechas_noco[] = sanitize_text_field($f);
            }
        }
        update_post_meta($post_id, '_eventosapp_fechas_noco', $fechas_noco);
        update_post_meta($post_id, '_eventosapp_fecha_unica', '');
        update_post_meta($post_id, '_eventosapp_fecha_inicio', '');
        update_post_meta($post_id, '_eventosapp_fecha_fin', '');
    }

    update_post_meta($post_id, '_eventosapp_hora_inicio', sanitize_text_field($_POST['eventosapp_hora_inicio'] ?? ''));
    update_post_meta($post_id, '_eventosapp_hora_cierre', sanitize_text_field($_POST['eventosapp_hora_cierre'] ?? ''));
    update_post_meta($post_id, '_eventosapp_zona_horaria', sanitize_text_field($_POST['eventosapp_zona_horaria'] ?? ''));

    update_post_meta($post_id, '_eventosapp_direccion', sanitize_text_field($_POST['eventosapp_direccion'] ?? ''));
    update_post_meta($post_id, '_eventosapp_coordenadas', sanitize_text_field($_POST['eventosapp_coordenadas'] ?? ''));
    update_post_meta($post_id, '_eventosapp_virtual_platform', sanitize_text_field($_POST['eventosapp_virtual_platform'] ?? ''));
    update_post_meta($post_id, '_eventosapp_virtual_url', esc_url_raw($_POST['eventosapp_virtual_url'] ?? ''));
    $virtual_access_datetime = function_exists('eventosapp_normalize_datetime_local_value')
        ? eventosapp_normalize_datetime_local_value($_POST['eventosapp_virtual_access_datetime'] ?? '')
        : sanitize_text_field($_POST['eventosapp_virtual_access_datetime'] ?? '');
    update_post_meta($post_id, '_eventosapp_virtual_access_datetime', $virtual_access_datetime);
    update_post_meta($post_id, '_eventosapp_virtual_access_use_landing', isset($_POST['eventosapp_virtual_access_use_landing']) ? '1' : '0');
    update_post_meta($post_id, '_eventosapp_organizador_email', sanitize_email($_POST['eventosapp_organizador_email'] ?? ''));
    update_post_meta($post_id, '_eventosapp_organizador_tel', sanitize_text_field($_POST['eventosapp_organizador_tel'] ?? ''));

    // === VINCULACIÓN CON CLIENTE ===
    $usar_cliente = isset($_POST['eventosapp_usar_cliente']) ? '1' : '0';
    update_post_meta($post_id, '_eventosapp_usar_cliente', $usar_cliente);

    if ($usar_cliente === '1') {
        $cliente_id = absint($_POST['eventosapp_cliente_id'] ?? 0);
        update_post_meta($post_id, '_eventosapp_cliente_id', $cliente_id);

        // Derivar nombre del organizador desde el cliente seleccionado
        if ($cliente_id) {
            $nombre_cliente = get_post_meta($cliente_id, '_cliente_nombre_empresa', true);
            if (!$nombre_cliente) {
                $nombre_cliente = get_the_title($cliente_id);
            }
            update_post_meta($post_id, '_eventosapp_organizador', sanitize_text_field($nombre_cliente));
        } else {
            // Si no eligió cliente, dejamos el organizador vacío o como estaba
            update_post_meta($post_id, '_eventosapp_organizador', '');
        }
    } else {
        // Modo texto libre
        update_post_meta($post_id, '_eventosapp_cliente_id', 0);
        update_post_meta($post_id, '_eventosapp_organizador', sanitize_text_field($_POST['eventosapp_organizador'] ?? ''));
    }

    if (isset($_POST['eventosapp_localidades_nonce']) && wp_verify_nonce($_POST['eventosapp_localidades_nonce'], 'eventosapp_localidades_guardar')) {
        $localidades = [];
        if (!empty($_POST['eventosapp_localidades']) && is_array($_POST['eventosapp_localidades'])) {
            foreach ($_POST['eventosapp_localidades'] as $l) {
                $l = trim($l);
                if ($l !== '') $localidades[] = sanitize_text_field($l);
            }
        }
        update_post_meta($post_id, '_eventosapp_localidades', $localidades);
    }

    // === WALLET por evento ===
    $wc_enable = isset($_POST['eventosapp_wallet_custom_enable']) ? '1' : '0';
    update_post_meta($post_id, '_eventosapp_wallet_custom_enable', $wc_enable);

    if ($wc_enable === '1') {
        $existing_class = get_post_meta($post_id, '_eventosapp_wallet_class_id', true);
        if (!$existing_class) {
            $class_id = sanitize_text_field($_POST['eventosapp_wallet_class_id'] ?? '');
            update_post_meta($post_id, '_eventosapp_wallet_class_id', $class_id);
        }
        update_post_meta($post_id, '_eventosapp_wallet_logo_url', esc_url_raw($_POST['eventosapp_wallet_logo_url'] ?? ''));
        update_post_meta($post_id, '_eventosapp_wallet_hero_img_url', esc_url_raw($_POST['eventosapp_wallet_hero_img_url'] ?? ''));
        $hex = isset($_POST['eventosapp_wallet_hex_color']) ? wp_unslash($_POST['eventosapp_wallet_hex_color']) : '';
        $hex = sanitize_hex_color( $hex );
        if ( ! $hex ) { $hex = '#3782C4'; }
        update_post_meta($post_id, '_eventosapp_wallet_hex_color', $hex);

        // Apple overrides (opcionales)
        update_post_meta($post_id, '_eventosapp_apple_icon_url',  esc_url_raw($_POST['eventosapp_apple_icon_url']  ?? ''));
        update_post_meta($post_id, '_eventosapp_apple_logo_url',  esc_url_raw($_POST['eventosapp_apple_logo_url']  ?? ''));
        update_post_meta($post_id, '_eventosapp_apple_strip_url', esc_url_raw($_POST['eventosapp_apple_strip_url'] ?? ''));
        foreach (['bg'=>'_eventosapp_apple_hex_bg', 'fg'=>'_eventosapp_apple_hex_fg', 'label'=>'_eventosapp_apple_hex_label'] as $k=>$meta){
            $v = $_POST['eventosapp_apple_hex_'.$k] ?? '';
            if ($v && preg_match('/^#?[0-9A-Fa-f]{6}$/', $v)) { if ($v[0] !== '#') $v = '#'.$v; }
            else $v = '';
            update_post_meta($post_id, $meta, $v);
        }

    } else {
        // Limpiar cuando se desactiva
        delete_post_meta($post_id, '_eventosapp_wallet_class_id');
        delete_post_meta($post_id, '_eventosapp_wallet_logo_url');
        delete_post_meta($post_id, '_eventosapp_wallet_hero_img_url');
        delete_post_meta($post_id, '_eventosapp_wallet_hex_color');

        delete_post_meta($post_id, '_eventosapp_apple_icon_url');
        delete_post_meta($post_id, '_eventosapp_apple_logo_url');
        delete_post_meta($post_id, '_eventosapp_apple_strip_url');
        delete_post_meta($post_id, '_eventosapp_apple_hex_bg');
        delete_post_meta($post_id, '_eventosapp_apple_hex_fg');
        delete_post_meta($post_id, '_eventosapp_apple_hex_label');
    }
}, 20);

/**
 * Guardar configuración por evento de autenticación para Networking Global.
 * Se mantiene separado del guardado principal para no depender de otros metaboxes.
 */
add_action('save_post_eventosapp_event', 'eventosapp_save_networking_global_auth_metabox', 25);
function eventosapp_save_networking_global_auth_metabox($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (!isset($_POST['eventosapp_networking_global_auth_nonce']) || !wp_verify_nonce($_POST['eventosapp_networking_global_auth_nonce'], 'eventosapp_networking_global_auth_guardar')) {
        return;
    }

    $fields = [];
    if (!empty($_POST['eventosapp_networking_global_auth_fields']) && is_array($_POST['eventosapp_networking_global_auth_fields'])) {
        foreach ($_POST['eventosapp_networking_global_auth_fields'] as $field) {
            $fields[] = sanitize_key(wp_unslash($field));
        }
    }

    $fields = function_exists('eventosapp_normalize_networking_global_auth_fields')
        ? eventosapp_normalize_networking_global_auth_fields($fields)
        : array_values(array_unique(array_filter($fields)));

    if (empty($fields)) {
        $fields = [ 'cc', 'apellido' ];
    }

    update_post_meta($post_id, '_eventosapp_networking_global_auth_fields', $fields);
}

/**
 * Guardar configuración visual del módulo de Autogestión Kiosko por evento.
 */
add_action('save_post_eventosapp_event', 'eventosapp_save_self_checkin_design_metabox', 30);
function eventosapp_save_self_checkin_design_metabox($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (!isset($_POST['eventosapp_self_checkin_design_nonce']) || !wp_verify_nonce($_POST['eventosapp_self_checkin_design_nonce'], 'eventosapp_self_checkin_design_guardar')) {
        return;
    }

    $theme = isset($_POST['eventosapp_self_checkin_theme']) ? sanitize_key(wp_unslash($_POST['eventosapp_self_checkin_theme'])) : 'light';
    if (!in_array($theme, ['light', 'dark'], true)) {
        $theme = 'light';
    }
    update_post_meta($post_id, '_eventosapp_self_checkin_theme', $theme);

    $background_type = isset($_POST['eventosapp_self_checkin_background_type']) ? sanitize_key(wp_unslash($_POST['eventosapp_self_checkin_background_type'])) : 'default';
    if (!in_array($background_type, ['default', 'color', 'image'], true)) {
        $background_type = 'default';
    }
    update_post_meta($post_id, '_eventosapp_self_checkin_background_type', $background_type);

    $background_color = isset($_POST['eventosapp_self_checkin_background_color']) ? sanitize_hex_color(wp_unslash($_POST['eventosapp_self_checkin_background_color'])) : '';
    update_post_meta($post_id, '_eventosapp_self_checkin_background_color', $background_color ?: '');

    update_post_meta($post_id, '_eventosapp_self_checkin_background_image_id', absint($_POST['eventosapp_self_checkin_background_image_id'] ?? 0));
    update_post_meta($post_id, '_eventosapp_self_checkin_background_image_url', esc_url_raw(wp_unslash($_POST['eventosapp_self_checkin_background_image_url'] ?? '')));

    $background_size = isset($_POST['eventosapp_self_checkin_background_size']) ? sanitize_key(wp_unslash($_POST['eventosapp_self_checkin_background_size'])) : 'cover';
    if (!in_array($background_size, ['cover', 'contain', 'auto'], true)) {
        $background_size = 'cover';
    }
    update_post_meta($post_id, '_eventosapp_self_checkin_background_size', $background_size);

    $allowed_positions = [
        'left top', 'center top', 'right top',
        'left center', 'center center', 'right center',
        'left bottom', 'center bottom', 'right bottom',
    ];
    $background_position = isset($_POST['eventosapp_self_checkin_background_position']) ? sanitize_text_field(wp_unslash($_POST['eventosapp_self_checkin_background_position'])) : 'center center';
    if (!in_array($background_position, $allowed_positions, true)) {
        $background_position = 'center center';
    }
    update_post_meta($post_id, '_eventosapp_self_checkin_background_position', $background_position);

    $background_repeat = isset($_POST['eventosapp_self_checkin_background_repeat']) ? sanitize_key(wp_unslash($_POST['eventosapp_self_checkin_background_repeat'])) : 'no-repeat';
    if (!in_array($background_repeat, ['no-repeat', 'repeat', 'repeat-x', 'repeat-y'], true)) {
        $background_repeat = 'no-repeat';
    }
    update_post_meta($post_id, '_eventosapp_self_checkin_background_repeat', $background_repeat);

    $background_attachment = isset($_POST['eventosapp_self_checkin_background_attachment']) ? sanitize_key(wp_unslash($_POST['eventosapp_self_checkin_background_attachment'])) : 'scroll';
    if (!in_array($background_attachment, ['scroll', 'fixed'], true)) {
        $background_attachment = 'scroll';
    }
    update_post_meta($post_id, '_eventosapp_self_checkin_background_attachment', $background_attachment);

    $background_class = isset($_POST['eventosapp_self_checkin_background_class']) ? wp_unslash($_POST['eventosapp_self_checkin_background_class']) : 'eventosapp-self-checkin-bg';
    $background_class = function_exists('eventosapp_self_checkin_clean_background_class') ? eventosapp_self_checkin_clean_background_class($background_class) : sanitize_html_class(ltrim((string) $background_class, '.'));
    update_post_meta($post_id, '_eventosapp_self_checkin_background_class', $background_class ?: 'eventosapp-self-checkin-bg');

    update_post_meta($post_id, '_eventosapp_self_checkin_main_logo_id', absint($_POST['eventosapp_self_checkin_main_logo_id'] ?? 0));
    update_post_meta($post_id, '_eventosapp_self_checkin_main_logo_url', esc_url_raw(wp_unslash($_POST['eventosapp_self_checkin_main_logo_url'] ?? '')));
    update_post_meta($post_id, '_eventosapp_self_checkin_main_logo_width', min(1000, max(40, absint($_POST['eventosapp_self_checkin_main_logo_width'] ?? 220))));
    update_post_meta($post_id, '_eventosapp_self_checkin_main_logo_max_height', min(600, max(24, absint($_POST['eventosapp_self_checkin_main_logo_max_height'] ?? 120))));

    update_post_meta($post_id, '_eventosapp_self_checkin_extra_logos_box_width', min(2400, max(120, absint($_POST['eventosapp_self_checkin_extra_logos_box_width'] ?? 760))));
    update_post_meta($post_id, '_eventosapp_self_checkin_extra_logos_box_height', min(800, max(40, absint($_POST['eventosapp_self_checkin_extra_logos_box_height'] ?? 120))));
    update_post_meta($post_id, '_eventosapp_self_checkin_extra_logos_gap', min(200, max(0, absint($_POST['eventosapp_self_checkin_extra_logos_gap'] ?? 18))));
    update_post_meta($post_id, '_eventosapp_self_checkin_extra_logo_max_height', min(500, max(20, absint($_POST['eventosapp_self_checkin_extra_logo_max_height'] ?? 72))));

    $extra_logos = [];
    if (!empty($_POST['eventosapp_self_checkin_extra_logos']) && is_array($_POST['eventosapp_self_checkin_extra_logos'])) {
        foreach ($_POST['eventosapp_self_checkin_extra_logos'] as $logo) {
            if (!is_array($logo)) {
                continue;
            }
            $url = esc_url_raw(wp_unslash($logo['url'] ?? ''));
            if (!$url) {
                continue;
            }
            $extra_logos[] = [
                'id'  => absint($logo['id'] ?? 0),
                'url' => $url,
                'alt' => sanitize_text_field(wp_unslash($logo['alt'] ?? '')),
            ];
        }
    }
    update_post_meta($post_id, '_eventosapp_self_checkin_extra_logos', $extra_logos);
}

// ===== Asegurar roles base de EventosApp =====
add_action('init', function(){
    eventosapp_ensure_core_roles();
}, 5);

function eventosapp_ensure_core_roles(){
    $roles = [
        'staff'       => ['name' => 'Staff',       'caps' => ['read'=>true]],
        'logistico'   => ['name' => 'Logístico',   'caps' => ['read'=>true]],
        'organizador' => ['name' => 'Organizador', 'caps' => ['read'=>true]],
        'coordinador' => ['name' => 'Coordinador', 'caps' => ['read'=>true]],
        'expositor'   => ['name' => 'Expositor',   'caps' => ['read'=>true]],
    ];
    foreach ($roles as $slug => $data){
        if ( ! get_role($slug) ){
            add_role($slug, $data['name'], $data['caps']);
        }
    }
}
