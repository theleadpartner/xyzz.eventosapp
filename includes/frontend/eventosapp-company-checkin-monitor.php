<?php
/**
 * EventosApp - Monitor de empresas con check-in
 *
 * Shortcode: [eventosapp_company_checkin_monitor]
 *
 * Agrupa los asistentes con check-in presencial por empresa/NIT, normaliza las
 * distintas formas de escritura del NIT y muestra los nombres de empresa que
 * fueron registrados para un mismo número.
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'eventosapp_company_checkin_is_enabled' ) ) {
    /**
     * Indica si el monitor está habilitado para un evento.
     *
     * @param int $event_id
     * @return bool
     */
    function eventosapp_company_checkin_is_enabled( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) {
            return false;
        }

        return (string) get_post_meta( $event_id, '_eventosapp_ticket_company_checkin_monitor', true ) === '1';
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_cache_key' ) ) {
    function eventosapp_company_checkin_cache_key( $event_id ) {
        return 'evapp_company_checkin_v1_' . absint( $event_id );
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_clear_cache' ) ) {
    function eventosapp_company_checkin_clear_cache( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) {
            return;
        }

        $cache_key = eventosapp_company_checkin_cache_key( $event_id );
        wp_cache_delete( $cache_key, 'eventosapp_company_checkin' );
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_invalidate_ticket_meta' ) ) {
    /**
     * Invalida el resumen cuando cambia un dato que participa en el monitor.
     *
     * @param mixed  $meta_id
     * @param int    $ticket_id
     * @param string $meta_key
     * @return void
     */
    function eventosapp_company_checkin_invalidate_ticket_meta( $meta_id, $ticket_id, $meta_key ) {
        $ticket_id = absint( $ticket_id );
        $meta_key  = (string) $meta_key;

        if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
            return;
        }

        $watched_keys = [
            '_eventosapp_checkin_status',
            '_eventosapp_checkin_log',
            '_eventosapp_asistente_empresa',
            '_eventosapp_asistente_nit',
            '_eventosapp_presencial_checkin_last_at',
            '_eventosapp_ticket_evento_id',
        ];

        if ( ! in_array( $meta_key, $watched_keys, true ) ) {
            return;
        }

        $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        if ( $event_id ) {
            eventosapp_company_checkin_clear_cache( $event_id );
        }
    }
}
add_action( 'added_post_meta', 'eventosapp_company_checkin_invalidate_ticket_meta', 10, 3 );
add_action( 'updated_post_meta', 'eventosapp_company_checkin_invalidate_ticket_meta', 10, 3 );
add_action( 'deleted_post_meta', 'eventosapp_company_checkin_invalidate_ticket_meta', 10, 3 );

if ( ! function_exists( 'eventosapp_company_checkin_invalidate_event_meta' ) ) {
    function eventosapp_company_checkin_invalidate_event_meta( $meta_id, $event_id, $meta_key ) {
        $event_id = absint( $event_id );
        $meta_key = (string) $meta_key;

        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return;
        }

        $watched_keys = [
            '_eventosapp_tipo_fecha',
            '_eventosapp_fecha_unica',
            '_eventosapp_fecha_inicio',
            '_eventosapp_fecha_fin',
            '_eventosapp_fechas_noco',
            '_eventosapp_zona_horaria',
        ];

        if ( in_array( $meta_key, $watched_keys, true ) ) {
            eventosapp_company_checkin_clear_cache( $event_id );
        }
    }
}
add_action( 'added_post_meta', 'eventosapp_company_checkin_invalidate_event_meta', 10, 3 );
add_action( 'updated_post_meta', 'eventosapp_company_checkin_invalidate_event_meta', 10, 3 );
add_action( 'deleted_post_meta', 'eventosapp_company_checkin_invalidate_event_meta', 10, 3 );

if ( ! function_exists( 'eventosapp_company_checkin_invalidate_ticket_status' ) ) {
    function eventosapp_company_checkin_invalidate_ticket_status( $new_status, $old_status, $post ) {
        if ( ! $post instanceof WP_Post || $post->post_type !== 'eventosapp_ticket' || $new_status === $old_status ) {
            return;
        }

        $event_id = absint( get_post_meta( $post->ID, '_eventosapp_ticket_evento_id', true ) );
        if ( $event_id ) {
            eventosapp_company_checkin_clear_cache( $event_id );
        }
    }
}
add_action( 'transition_post_status', 'eventosapp_company_checkin_invalidate_ticket_status', 10, 3 );

if ( ! function_exists( 'eventosapp_company_checkin_calculate_colombian_dv' ) ) {
    /**
     * Calcula el dígito de verificación colombiano para una base de NIT.
     *
     * Se utiliza únicamente para reconocer entradas sin guion en las que el
     * último dígito sí corresponde al DV. No se usa para rechazar valores.
     *
     * @param string $base Solo dígitos, sin DV.
     * @return string
     */
    function eventosapp_company_checkin_calculate_colombian_dv( $base ) {
        $base = preg_replace( '/\D+/', '', (string) $base );
        if ( $base === '' ) {
            return '';
        }

        $weights = [ 71, 67, 59, 53, 47, 43, 41, 37, 29, 23, 19, 17, 13, 7, 3 ];
        $digits  = str_split( $base );
        $offset  = count( $weights ) - count( $digits );

        if ( $offset < 0 ) {
            return '';
        }

        $sum = 0;
        foreach ( $digits as $index => $digit ) {
            $sum += (int) $digit * $weights[ $offset + $index ];
        }

        $remainder = $sum % 11;
        $dv        = $remainder > 1 ? 11 - $remainder : $remainder;

        return (string) $dv;
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_format_nit_base' ) ) {
    /**
     * Formatea la base del NIT con puntos cada tres dígitos.
     *
     * @param string $base
     * @return string
     */
    function eventosapp_company_checkin_format_nit_base( $base ) {
        $base = preg_replace( '/\D+/', '', (string) $base );
        if ( $base === '' ) {
            return '';
        }

        $groups = [];
        while ( strlen( $base ) > 3 ) {
            array_unshift( $groups, substr( $base, -3 ) );
            $base = substr( $base, 0, -3 );
        }
        array_unshift( $groups, $base );

        return implode( '.', $groups );
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_normalize_nit' ) ) {
    /**
     * Normaliza un NIT conservando por separado su base y su DV.
     *
     * Ejemplos que producen la misma base:
     * - 901.582.705-9
     * - 901.582.705
     * - 901582705
     * - 901582705-9
     * - 901582705 - 9
     *
     * También reconoce el formato continuo base+DV cuando tiene diez dígitos y
     * el último coincide con el algoritmo colombiano.
     *
     * @param mixed $raw
     * @return array{raw:string,base:string,dv:string,key:string,display:string}
     */
    function eventosapp_company_checkin_normalize_nit( $raw ) {
        if ( is_array( $raw ) || is_object( $raw ) ) {
            $raw = '';
        }

        $raw = trim( wp_strip_all_tags( (string) $raw ) );
        $base = '';
        $dv   = '';

        if ( $raw !== '' && preg_match( '/^\s*([0-9\.\s]+?)\s*[-–—]\s*([0-9])\s*$/u', $raw, $matches ) ) {
            $base = preg_replace( '/\D+/', '', $matches[1] );
            $dv   = preg_replace( '/\D+/', '', $matches[2] );
        } else {
            $digits = preg_replace( '/\D+/', '', $raw );
            $base   = $digits;

            // El formato colombiano más habitual sin separadores es base de
            // nueve dígitos + un DV. Solo se separa cuando el DV es válido.
            if ( strlen( $digits ) === 10 ) {
                $candidate_base = substr( $digits, 0, -1 );
                $candidate_dv   = substr( $digits, -1 );
                if ( eventosapp_company_checkin_calculate_colombian_dv( $candidate_base ) === $candidate_dv ) {
                    $base = $candidate_base;
                    $dv   = $candidate_dv;
                }
            }
        }

        $base = ltrim( (string) $base, '0' );
        if ( $base === '' && preg_match( '/0/', (string) $raw ) ) {
            $base = '0';
        }

        $display = eventosapp_company_checkin_format_nit_base( $base );
        if ( $display !== '' && $dv !== '' ) {
            $display .= '-' . $dv;
        }

        return [
            'raw'     => $raw,
            'base'    => $base,
            'dv'      => $dv,
            'key'     => $base !== '' ? 'nit:' . $base : '',
            'display' => $display,
        ];
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_normalize_company_name' ) ) {
    /**
     * Normaliza un nombre únicamente para comparar y agrupar variantes exactas.
     * No aplica coincidencia difusa para evitar unir empresas diferentes.
     *
     * @param mixed $name
     * @return string
     */
    function eventosapp_company_checkin_normalize_company_name( $name ) {
        if ( is_array( $name ) || is_object( $name ) ) {
            return '';
        }

        $name = trim( wp_strip_all_tags( (string) $name ) );
        if ( $name === '' ) {
            return '';
        }

        $name = remove_accents( $name );
        $name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
        $name = preg_replace( '/[^a-z0-9]+/u', ' ', $name );
        $name = preg_replace( '/\s+/u', ' ', $name );

        return trim( $name );
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_meta_to_array' ) ) {
    function eventosapp_company_checkin_meta_to_array( $value ) {
        if ( is_string( $value ) ) {
            $value = maybe_unserialize( $value );
        }
        return is_array( $value ) ? $value : [];
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_ticket_is_checked' ) ) {
    /**
     * Comprueba si un ticket tiene al menos un check-in presencial válido.
     *
     * @param mixed $status_value
     * @param array $valid_days_lookup
     * @return bool
     */
    function eventosapp_company_checkin_ticket_is_checked( $status_value, array $valid_days_lookup = [] ) {
        $statuses = eventosapp_company_checkin_meta_to_array( $status_value );

        foreach ( $statuses as $day => $status ) {
            if ( ! in_array( (string) $status, [ 'checked_in', 'checked-in' ], true ) ) {
                continue;
            }

            if ( ! empty( $valid_days_lookup ) && is_string( $day ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) && ! isset( $valid_days_lookup[ $day ] ) ) {
                continue;
            }

            return true;
        }

        return false;
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_parse_datetime' ) ) {
    /**
     * Convierte una fecha/hora del log a timestamp usando la zona del evento.
     *
     * @param string $date
     * @param string $time
     * @param DateTimeZone $timezone
     * @return int
     */
    function eventosapp_company_checkin_parse_datetime( $date, $time, $timezone ) {
        $date = trim( (string) $date );
        $time = trim( (string) $time );

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return 0;
        }
        if ( ! preg_match( '/^\d{2}:\d{2}(?::\d{2})?$/', $time ) ) {
            $time = '00:00:00';
        } elseif ( strlen( $time ) === 5 ) {
            $time .= ':00';
        }

        try {
            $datetime = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date . ' ' . $time, $timezone );
            return $datetime instanceof DateTimeImmutable ? $datetime->getTimestamp() : 0;
        } catch ( Exception $e ) {
            return 0;
        }
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_ticket_arrival_range' ) ) {
    /**
     * Obtiene primera y última llegada a partir del log del ticket.
     *
     * @param mixed $log_value
     * @param array $valid_days_lookup
     * @param DateTimeZone $timezone
     * @param string $fallback_datetime
     * @return array{first:int,last:int}
     */
    function eventosapp_company_checkin_ticket_arrival_range( $log_value, array $valid_days_lookup, $timezone, $fallback_datetime = '' ) {
        $log        = eventosapp_company_checkin_meta_to_array( $log_value );
        $timestamps = [];

        foreach ( $log as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $status = isset( $entry['status'] ) ? (string) $entry['status'] : '';
            if ( ! in_array( $status, [ 'checked_in', 'checked-in' ], true ) ) {
                continue;
            }

            if ( isset( $entry['checkin_type'] ) && (string) $entry['checkin_type'] === 'virtual' ) {
                continue;
            }

            $event_day = '';
            foreach ( [ 'dia', 'fecha' ] as $day_key ) {
                if ( ! empty( $entry[ $day_key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $entry[ $day_key ] ) ) {
                    $event_day = (string) $entry[ $day_key ];
                    break;
                }
            }

            if ( ! empty( $valid_days_lookup ) && $event_day !== '' && ! isset( $valid_days_lookup[ $event_day ] ) ) {
                continue;
            }

            $date = ! empty( $entry['fecha'] ) ? (string) $entry['fecha'] : $event_day;
            $time = ! empty( $entry['hora'] ) ? (string) $entry['hora'] : '00:00:00';
            $ts   = eventosapp_company_checkin_parse_datetime( $date, $time, $timezone );
            if ( $ts > 0 ) {
                $timestamps[] = $ts;
            }
        }

        if ( empty( $timestamps ) && $fallback_datetime !== '' ) {
            try {
                $fallback = new DateTimeImmutable( $fallback_datetime, $timezone );
                $timestamps[] = $fallback->getTimestamp();
            } catch ( Exception $e ) {
                // Se conserva 0 si tampoco existe un fallback válido.
            }
        }

        if ( empty( $timestamps ) ) {
            return [ 'first' => 0, 'last' => 0 ];
        }

        sort( $timestamps, SORT_NUMERIC );
        return [
            'first' => (int) reset( $timestamps ),
            'last'  => (int) end( $timestamps ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_get_ticket_rows' ) ) {
    /**
     * Consulta tickets y metadatos del evento en bloques para evitar N+1 queries.
     *
     * @param int $event_id
     * @return array
     */
    function eventosapp_company_checkin_get_ticket_rows( $event_id ) {
        global $wpdb;

        $event_id = absint( $event_id );
        if ( ! $event_id || ! $wpdb ) {
            return [];
        }

        $checked_in_like        = '%' . $wpdb->esc_like( 's:10:"checked_in"' ) . '%';
        $checked_in_legacy_like = '%' . $wpdb->esc_like( 's:10:"checked-in"' ) . '%';

        $ticket_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT p.ID, p.post_modified_gmt
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} event_pm
                    ON event_pm.post_id = p.ID
                   AND event_pm.meta_key = %s
                 INNER JOIN {$wpdb->postmeta} checkin_pm
                    ON checkin_pm.post_id = p.ID
                   AND checkin_pm.meta_key = %s
                 WHERE p.post_type = %s
                   AND p.post_status NOT IN ('trash', 'auto-draft')
                   AND event_pm.meta_value = %s
                   AND (
                        checkin_pm.meta_value LIKE %s
                        OR checkin_pm.meta_value LIKE %s
                        OR checkin_pm.meta_value = %s
                        OR checkin_pm.meta_value = %s
                   )
                 ORDER BY p.ID ASC",
                '_eventosapp_ticket_evento_id',
                '_eventosapp_checkin_status',
                'eventosapp_ticket',
                (string) $event_id,
                $checked_in_like,
                $checked_in_legacy_like,
                'checked_in',
                'checked-in'
            ),
            ARRAY_A
        );

        if ( ! empty( $wpdb->last_error ) ) {
            throw new RuntimeException( 'No fue posible consultar los tickets del evento.' );
        }

        if ( empty( $ticket_rows ) ) {
            return [];
        }

        $ticket_ids = array_map( 'intval', wp_list_pluck( $ticket_rows, 'ID' ) );
        $modified   = [];
        foreach ( $ticket_rows as $row ) {
            $modified[ (int) $row['ID'] ] = isset( $row['post_modified_gmt'] ) ? (string) $row['post_modified_gmt'] : '';
        }

        $meta_keys = [
            '_eventosapp_checkin_status',
            '_eventosapp_checkin_log',
            '_eventosapp_asistente_empresa',
            '_eventosapp_asistente_nit',
            '_eventosapp_presencial_checkin_last_at',
        ];

        $meta_map = [];
        foreach ( $ticket_ids as $ticket_id ) {
            $meta_map[ $ticket_id ] = [];
        }

        foreach ( array_chunk( $ticket_ids, 500 ) as $chunk ) {
            $id_placeholders  = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            $key_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
            $query            = $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE post_id IN ({$id_placeholders})
                   AND meta_key IN ({$key_placeholders})
                 ORDER BY meta_id ASC",
                array_merge( $chunk, $meta_keys )
            );

            $meta_rows = $wpdb->get_results( $query, ARRAY_A );
            if ( ! empty( $wpdb->last_error ) ) {
                throw new RuntimeException( 'No fue posible consultar los datos de Empresa y NIT.' );
            }

            foreach ( (array) $meta_rows as $meta_row ) {
                $ticket_id = isset( $meta_row['post_id'] ) ? (int) $meta_row['post_id'] : 0;
                $meta_key  = isset( $meta_row['meta_key'] ) ? (string) $meta_row['meta_key'] : '';
                if ( ! $ticket_id || $meta_key === '' || ! isset( $meta_map[ $ticket_id ] ) ) {
                    continue;
                }

                if ( ! array_key_exists( $meta_key, $meta_map[ $ticket_id ] ) ) {
                    $meta_map[ $ticket_id ][ $meta_key ] = maybe_unserialize( $meta_row['meta_value'] );
                }
            }
        }

        $rows = [];
        foreach ( $ticket_ids as $ticket_id ) {
            $rows[] = [
                'ticket_id'          => $ticket_id,
                'post_modified_gmt'  => $modified[ $ticket_id ] ?? '',
                'checkin_status'     => $meta_map[ $ticket_id ]['_eventosapp_checkin_status'] ?? [],
                'checkin_log'        => $meta_map[ $ticket_id ]['_eventosapp_checkin_log'] ?? [],
                'company'            => $meta_map[ $ticket_id ]['_eventosapp_asistente_empresa'] ?? '',
                'nit'                => $meta_map[ $ticket_id ]['_eventosapp_asistente_nit'] ?? '',
                'last_checkin_at'    => $meta_map[ $ticket_id ]['_eventosapp_presencial_checkin_last_at'] ?? '',
            ];
        }

        return $rows;
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_choose_primary_name' ) ) {
    /**
     * Selecciona como nombre principal la variante más frecuente; en empate,
     * la que apareció primero.
     *
     * @param array $aliases
     * @return string
     */
    function eventosapp_company_checkin_choose_primary_name( array $aliases ) {
        $winner       = '';
        $winner_count = -1;
        $winner_first = PHP_INT_MAX;

        foreach ( $aliases as $alias ) {
            $count = isset( $alias['count'] ) ? (int) $alias['count'] : 0;
            $first = ! empty( $alias['first_arrival'] ) ? (int) $alias['first_arrival'] : PHP_INT_MAX;
            $name  = isset( $alias['name'] ) ? (string) $alias['name'] : '';

            if ( $name !== '' && ( $count > $winner_count || ( $count === $winner_count && $first < $winner_first ) ) ) {
                $winner       = $name;
                $winner_count = $count;
                $winner_first = $first;
            }
        }

        return $winner;
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_format_timestamp' ) ) {
    function eventosapp_company_checkin_format_timestamp( $timestamp, $timezone ) {
        $timestamp = (int) $timestamp;
        if ( $timestamp <= 0 ) {
            return 'Sin hora registrada';
        }

        try {
            return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->format( 'd/m/Y H:i:s' );
        } catch ( Exception $e ) {
            return 'Sin hora registrada';
        }
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_build_payload' ) ) {
    /**
     * Construye el resumen agrupado del evento.
     *
     * @param int $event_id
     * @return array
     */
    function eventosapp_company_checkin_build_payload( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id ) {
            throw new RuntimeException( 'No hay un evento activo válido.' );
        }

        $cache_key = eventosapp_company_checkin_cache_key( $event_id );
        $cached = wp_cache_get( $cache_key, 'eventosapp_company_checkin' );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $timezone = function_exists( 'eventosapp_get_event_timezone_object' )
            ? eventosapp_get_event_timezone_object( $event_id )
            : wp_timezone();

        $valid_days = function_exists( 'eventosapp_get_event_days' ) ? (array) eventosapp_get_event_days( $event_id ) : [];
        $valid_days = array_values( array_filter( $valid_days, static function( $day ) {
            return is_string( $day ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day );
        } ) );
        $valid_days_lookup = array_fill_keys( $valid_days, true );

        $groups              = [];
        $total_checked_in    = 0;
        $without_company_nit = 0;

        foreach ( eventosapp_company_checkin_get_ticket_rows( $event_id ) as $ticket ) {
            if ( ! eventosapp_company_checkin_ticket_is_checked( $ticket['checkin_status'], $valid_days_lookup ) ) {
                continue;
            }

            $total_checked_in++;

            $company     = is_scalar( $ticket['company'] ) ? trim( sanitize_text_field( (string) $ticket['company'] ) ) : '';
            $company_key = eventosapp_company_checkin_normalize_company_name( $company );
            $nit         = eventosapp_company_checkin_normalize_nit( $ticket['nit'] );

            if ( $nit['base'] !== '' ) {
                $group_key = $nit['key'];
            } elseif ( $company_key !== '' ) {
                $group_key = 'company:' . $company_key;
            } else {
                $without_company_nit++;
                continue;
            }

            $fallback_datetime = '';
            if ( is_scalar( $ticket['last_checkin_at'] ) && trim( (string) $ticket['last_checkin_at'] ) !== '' ) {
                $fallback_datetime = trim( (string) $ticket['last_checkin_at'] );
            } elseif ( ! empty( $ticket['post_modified_gmt'] ) ) {
                try {
                    $fallback_datetime = ( new DateTimeImmutable( $ticket['post_modified_gmt'], new DateTimeZone( 'UTC' ) ) )
                        ->setTimezone( $timezone )
                        ->format( 'Y-m-d H:i:s' );
                } catch ( Exception $e ) {
                    $fallback_datetime = '';
                }
            }

            $arrival = eventosapp_company_checkin_ticket_arrival_range(
                $ticket['checkin_log'],
                $valid_days_lookup,
                $timezone,
                $fallback_datetime
            );

            if ( ! isset( $groups[ $group_key ] ) ) {
                $groups[ $group_key ] = [
                    'key'           => $group_key,
                    'nit_base'      => $nit['base'],
                    'nit_dvs'       => [],
                    'raw_nits'      => [],
                    'aliases'       => [],
                    'attendees'     => 0,
                    'first_arrival' => 0,
                    'last_arrival'  => 0,
                ];
            }

            $groups[ $group_key ]['attendees']++;

            if ( $nit['dv'] !== '' ) {
                $groups[ $group_key ]['nit_dvs'][ $nit['dv'] ] = true;
            }
            if ( $nit['raw'] !== '' ) {
                $groups[ $group_key ]['raw_nits'][ $nit['raw'] ] = true;
            }

            if ( $company !== '' ) {
                $alias_key = $company_key !== '' ? $company_key : $company;
                if ( ! isset( $groups[ $group_key ]['aliases'][ $alias_key ] ) ) {
                    $groups[ $group_key ]['aliases'][ $alias_key ] = [
                        'name'          => $company,
                        'count'         => 0,
                        'first_arrival' => $arrival['first'],
                    ];
                }
                $groups[ $group_key ]['aliases'][ $alias_key ]['count']++;
                if ( $arrival['first'] > 0 && ( empty( $groups[ $group_key ]['aliases'][ $alias_key ]['first_arrival'] ) || $arrival['first'] < $groups[ $group_key ]['aliases'][ $alias_key ]['first_arrival'] ) ) {
                    $groups[ $group_key ]['aliases'][ $alias_key ]['first_arrival'] = $arrival['first'];
                }
            }

            if ( $arrival['first'] > 0 && ( empty( $groups[ $group_key ]['first_arrival'] ) || $arrival['first'] < $groups[ $group_key ]['first_arrival'] ) ) {
                $groups[ $group_key ]['first_arrival'] = $arrival['first'];
            }
            if ( $arrival['last'] > $groups[ $group_key ]['last_arrival'] ) {
                $groups[ $group_key ]['last_arrival'] = $arrival['last'];
            }
        }

        $rows = [];
        foreach ( $groups as $group ) {
            $aliases = array_values( $group['aliases'] );
            usort( $aliases, static function( $a, $b ) {
                $count_cmp = (int) ( $b['count'] ?? 0 ) <=> (int) ( $a['count'] ?? 0 );
                if ( $count_cmp !== 0 ) {
                    return $count_cmp;
                }
                return (int) ( $a['first_arrival'] ?? PHP_INT_MAX ) <=> (int) ( $b['first_arrival'] ?? PHP_INT_MAX );
            } );

            $alias_names = array_values( array_filter( array_map( static function( $alias ) {
                return isset( $alias['name'] ) ? (string) $alias['name'] : '';
            }, $aliases ) ) );

            $primary_name = eventosapp_company_checkin_choose_primary_name( $group['aliases'] );
            if ( $primary_name === '' ) {
                $primary_name = 'Empresa sin nombre registrado';
            }

            $dvs         = array_keys( $group['nit_dvs'] );
            $nit_display = $group['nit_base'] !== '' ? eventosapp_company_checkin_format_nit_base( $group['nit_base'] ) : 'Sin NIT';
            if ( count( $dvs ) === 1 ) {
                $nit_display .= '-' . reset( $dvs );
            } elseif ( count( $dvs ) > 1 ) {
                sort( $dvs, SORT_NATURAL );
                $nit_display .= ' (DV: ' . implode( ', ', $dvs ) . ')';
            }

            $search_parts = array_merge( [ $primary_name, $nit_display, $group['nit_base'] ], $alias_names, array_keys( $group['raw_nits'] ) );

            $rows[] = [
                'key'                => $group['key'],
                'company'            => $primary_name,
                'aliases'            => $alias_names,
                'nit'                => $nit_display,
                'nit_base'           => $group['nit_base'],
                'nit_has_conflict'   => count( $dvs ) > 1,
                'attendees'          => (int) $group['attendees'],
                'first_arrival_ts'   => (int) $group['first_arrival'],
                'last_arrival_ts'    => (int) $group['last_arrival'],
                'first_arrival'      => eventosapp_company_checkin_format_timestamp( $group['first_arrival'], $timezone ),
                'last_arrival'       => eventosapp_company_checkin_format_timestamp( $group['last_arrival'], $timezone ),
                'search_text'        => implode( ' ', $search_parts ),
            ];
        }

        usort( $rows, static function( $a, $b ) {
            $a_first = ! empty( $a['first_arrival_ts'] ) ? (int) $a['first_arrival_ts'] : PHP_INT_MAX;
            $b_first = ! empty( $b['first_arrival_ts'] ) ? (int) $b['first_arrival_ts'] : PHP_INT_MAX;
            if ( $a_first === $b_first ) {
                return strcasecmp( (string) $a['company'], (string) $b['company'] );
            }
            return $a_first <=> $b_first;
        } );

        foreach ( $rows as $index => &$row ) {
            $row['arrival_position'] = $index + 1;
        }
        unset( $row );

        $identified_attendees = array_sum( array_map( static function( $row ) {
            return (int) $row['attendees'];
        }, $rows ) );

        try {
            $generated_at = ( new DateTimeImmutable( 'now', $timezone ) )->format( 'd/m/Y H:i:s' );
        } catch ( Exception $e ) {
            $generated_at = current_time( 'd/m/Y H:i:s' );
        }

        $payload = [
            'event_id'               => $event_id,
            'event_title'            => get_the_title( $event_id ),
            'generated_at'           => $generated_at,
            'companies'              => count( $rows ),
            'total_checked_in'       => $total_checked_in,
            'identified_attendees'   => $identified_attendees,
            'without_company_nit'    => $without_company_nit,
            'rows'                    => $rows,
        ];

        wp_cache_set( $cache_key, $payload, 'eventosapp_company_checkin', 15 );

        return $payload;
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_user_is_temp_cogestor' ) ) {
    /**
     * Verifica exclusivamente la asignación de Co-gestión temporal.
     * No incluye staff operativo, asistencia ni expositores.
     *
     * @param int $event_id
     * @param int $user_id
     * @return bool
     */
    function eventosapp_company_checkin_user_is_temp_cogestor( $event_id, $user_id ) {
        $event_id = absint( $event_id );
        $user_id  = absint( $user_id );
        if ( ! $event_id || ! $user_id ) {
            return false;
        }

        $temp_authors = get_post_meta( $event_id, '_evapp_temp_authors', true );
        if ( ! is_array( $temp_authors ) ) {
            return false;
        }

        $now = time();
        foreach ( $temp_authors as $key => $row ) {
            $row_user_id = 0;
            $until       = 0;

            if ( is_array( $row ) ) {
                $row_user_id = ! empty( $row['user_id'] ) ? absint( $row['user_id'] ) : absint( $key );
                $until       = isset( $row['until'] ) ? absint( $row['until'] ) : 0;
            } else {
                $row_user_id = absint( $row );
            }

            if ( $row_user_id === $user_id && ( ! $until || $until >= $now ) ) {
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_user_can_view' ) ) {
    /**
     * Comprueba alcance por evento y permiso del módulo.
     *
     * Accesos admitidos:
     * - Administrador.
     * - Autor/organizador propietario del evento.
     * - Usuario vigente de Co-gestión temporal.
     * - Usuario agregado manualmente en Control de Acceso Dashboard Staff con
     *   Ver Dashboard y Empresas con Check-In activos.
     *
     * No concede acceso por pertenecer únicamente a Asistencia, Expositor o
     * Staff operativo general.
     *
     * @param int $event_id
     * @param int $user_id
     * @return bool
     */
    function eventosapp_company_checkin_user_can_view( $event_id, $user_id = 0 ) {
        $event_id = absint( $event_id );
        $user_id  = $user_id ? absint( $user_id ) : get_current_user_id();

        if ( ! $event_id || ! $user_id || ! eventosapp_company_checkin_is_enabled( $event_id ) ) {
            return false;
        }

        $event = get_post( $event_id );
        if ( ! $event || $event->post_type !== 'eventosapp_event' ) {
            return false;
        }

        $role_allowed = function_exists( 'eventosapp_role_can' )
            && eventosapp_role_can( 'company_checkin', $user_id );

        if ( user_can( $user_id, 'manage_options' ) ) {
            return $role_allowed;
        }

        if ( absint( $event->post_author ) === $user_id ) {
            return $role_allowed;
        }

        if ( eventosapp_company_checkin_user_is_temp_cogestor( $event_id, $user_id ) ) {
            return $role_allowed;
        }

        if ( function_exists( 'eventosapp_staff_access_user_can_access_feature' ) ) {
            return eventosapp_staff_access_user_can_access_feature(
                $event_id,
                $user_id,
                'company_checkin',
                false
            ) === true;
        }

        return false;
    }
}

if ( ! function_exists( 'eventosapp_company_checkin_ajax_data' ) ) {
    function eventosapp_company_checkin_ajax_data() {
        check_ajax_referer( 'eventosapp_company_checkin_monitor', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Debes iniciar sesión.' ], 401 );
        }

        $user_id = get_current_user_id();
        $event_id = function_exists( 'eventosapp_get_active_event' )
            ? absint( eventosapp_get_active_event( $user_id ) )
            : 0;

        if ( ! $event_id ) {
            wp_send_json_error( [ 'message' => 'No hay un evento activo.' ], 400 );
        }

        if ( ! eventosapp_company_checkin_is_enabled( $event_id ) ) {
            wp_send_json_error( [ 'message' => 'El monitor de empresas está desactivado para este evento.' ], 403 );
        }

        if ( ! eventosapp_company_checkin_user_can_view( $event_id, $user_id ) ) {
            wp_send_json_error( [ 'message' => 'No tienes permiso para consultar este monitor.' ], 403 );
        }

        try {
            nocache_headers();
            wp_send_json_success( eventosapp_company_checkin_build_payload( $event_id ) );
        } catch ( Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'EVENTOSAPP COMPANY CHECKIN | ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine() );
            }
            wp_send_json_error( [ 'message' => 'No fue posible cargar el monitor de empresas.' ], 500 );
        }
    }
}
add_action( 'wp_ajax_eventosapp_company_checkin_data', 'eventosapp_company_checkin_ajax_data' );

if ( ! function_exists( 'eventosapp_company_checkin_monitor_shortcode' ) ) {
    function eventosapp_company_checkin_monitor_shortcode() {
        if ( ! is_user_logged_in() ) {
            $login = wp_login_url( get_permalink() );
            return '<p>Debes iniciar sesión. <a href="' . esc_url( $login ) . '">Iniciar sesión</a></p>';
        }

        $user_id = get_current_user_id();
        $event_id = function_exists( 'eventosapp_get_active_event' )
            ? absint( eventosapp_get_active_event( $user_id ) )
            : 0;

        if ( ! $event_id ) {
            return '<div class="evapp-company-notice">Selecciona primero un evento desde el dashboard.</div>';
        }

        if ( ! eventosapp_company_checkin_is_enabled( $event_id ) ) {
            return '<div class="evapp-company-notice">El monitor de empresas no está activado para este evento.</div>';
        }

        if ( ! eventosapp_company_checkin_user_can_view( $event_id, $user_id ) ) {
            return '<div class="evapp-company-notice">No tienes permisos para ver el monitor de empresas.</div>';
        }

        $instance_id = 'evapp-company-monitor-' . wp_generate_uuid4();
        $nonce       = wp_create_nonce( 'eventosapp_company_checkin_monitor' );
        $ajax_url    = admin_url( 'admin-ajax.php' );

        ob_start();
        ?>
        <div id="<?php echo esc_attr( $instance_id ); ?>" class="evapp-company-monitor" data-ajax-url="<?php echo esc_url( $ajax_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
            <style>
                .evapp-company-monitor{--evapp-company-blue:#2F73B5;--evapp-company-border:#dfe5ec;--evapp-company-muted:#667085;font-family:inherit;color:#1d2939}
                .evapp-company-monitor *{box-sizing:border-box}
                .evapp-company-monitor .screen-reader-text{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
                .evapp-company-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}
                .evapp-company-header h2{margin:0 0 5px;font-size:28px;line-height:1.15}
                .evapp-company-header p{margin:0;color:var(--evapp-company-muted)}
                .evapp-company-refresh{border:0;border-radius:10px;background:var(--evapp-company-blue);color:#fff;padding:11px 16px;font-weight:700;cursor:pointer;white-space:nowrap}
                .evapp-company-refresh:disabled{opacity:.6;cursor:wait}
                .evapp-company-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
                .evapp-company-kpi{padding:15px;border:1px solid var(--evapp-company-border);border-radius:14px;background:#fff;box-shadow:0 3px 12px rgba(16,24,40,.05)}
                .evapp-company-kpi strong{display:block;font-size:27px;line-height:1;color:var(--evapp-company-blue);margin-bottom:7px}
                .evapp-company-kpi span{font-size:13px;color:var(--evapp-company-muted)}
                .evapp-company-controls{display:grid;grid-template-columns:minmax(240px,1fr) minmax(210px,280px);gap:12px;margin:14px 0}
                .evapp-company-controls input,.evapp-company-controls select{width:100%;min-height:44px;border:1px solid #cfd6df;border-radius:10px;padding:9px 12px;background:#fff;color:#1d2939}
                .evapp-company-meta{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:8px 0 12px;color:var(--evapp-company-muted);font-size:13px}
                .evapp-company-table-wrap{overflow:auto;border:1px solid var(--evapp-company-border);border-radius:14px;background:#fff}
                .evapp-company-table{width:100%;min-width:980px;border-collapse:collapse}
                .evapp-company-table th,.evapp-company-table td{padding:13px 14px;border-bottom:1px solid #edf0f4;text-align:left;vertical-align:top}
                .evapp-company-table th{position:sticky;top:0;z-index:1;background:#f7f9fc;color:#344054;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
                .evapp-company-table tbody tr:last-child td{border-bottom:0}
                .evapp-company-table tbody tr:hover{background:#fbfcfe}
                .evapp-company-rank{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:999px;background:#eef4fb;color:var(--evapp-company-blue);font-weight:800}
                .evapp-company-name{font-weight:800;display:block;margin-bottom:4px}
                .evapp-company-aliases{display:flex;gap:5px;flex-wrap:wrap}
                .evapp-company-alias{display:inline-flex;padding:3px 7px;border-radius:999px;background:#f2f4f7;color:#475467;font-size:11px}
                .evapp-company-count{display:inline-flex;min-width:42px;justify-content:center;padding:6px 10px;border-radius:999px;background:#e8f3ff;color:#175b93;font-weight:800}
                .evapp-company-nit-warning{display:block;color:#b54708;font-size:11px;margin-top:4px}
                .evapp-company-empty,.evapp-company-error,.evapp-company-loading{padding:28px;text-align:center;color:var(--evapp-company-muted)}
                .evapp-company-error{color:#b42318;background:#fff6f5;border:1px solid #fecdca;border-radius:12px;margin:12px 0}
                .evapp-company-loading{border:1px dashed var(--evapp-company-border);border-radius:12px}
                .evapp-company-hidden{display:none!important}
                @media(max-width:900px){.evapp-company-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.evapp-company-header{flex-direction:column}.evapp-company-refresh{width:100%}.evapp-company-controls{grid-template-columns:1fr}.evapp-company-meta{align-items:flex-start;flex-direction:column}}
                @media(max-width:520px){.evapp-company-summary{grid-template-columns:1fr}.evapp-company-header h2{font-size:23px}}
            </style>

            <?php if ( function_exists( 'eventosapp_active_event_bar' ) ) : ?>
                <?php eventosapp_active_event_bar(); ?>
            <?php endif; ?>

            <div class="evapp-company-header">
                <div>
                    <h2>Empresas con check-in</h2>
                    <p>Monitoreo dinámico de asistentes agrupados por NIT y empresa.</p>
                </div>
                <button type="button" class="evapp-company-refresh">Actualizar ahora</button>
            </div>

            <div class="evapp-company-summary" aria-live="polite">
                <div class="evapp-company-kpi"><strong data-kpi="companies">0</strong><span>Empresas identificadas</span></div>
                <div class="evapp-company-kpi"><strong data-kpi="identified">0</strong><span>Asistentes asociados</span></div>
                <div class="evapp-company-kpi"><strong data-kpi="checked">0</strong><span>Total con check-in</span></div>
                <div class="evapp-company-kpi"><strong data-kpi="unidentified">0</strong><span>Sin Empresa ni NIT</span></div>
            </div>

            <div class="evapp-company-controls">
                <label>
                    <span class="screen-reader-text">Buscar por empresa o NIT</span>
                    <input type="search" class="evapp-company-search" placeholder="Buscar por nombre de empresa o NIT" autocomplete="off">
                </label>
                <label>
                    <span class="screen-reader-text">Ordenar empresas</span>
                    <select class="evapp-company-sort">
                        <option value="arrival">Orden de llegada</option>
                        <option value="quantity">Mayor cantidad de asistentes</option>
                        <option value="name">Nombre de empresa</option>
                    </select>
                </label>
            </div>

            <div class="evapp-company-meta">
                <span class="evapp-company-results">0 empresas visibles</span>
                <span class="evapp-company-updated">Sin actualizar</span>
            </div>

            <div class="evapp-company-loading">Cargando empresas con check-in…</div>
            <div class="evapp-company-error evapp-company-hidden" role="alert"></div>

            <div class="evapp-company-table-wrap evapp-company-hidden">
                <table class="evapp-company-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Empresa principal</th>
                            <th>NIT normalizado</th>
                            <th>Nombres asociados</th>
                            <th>Asistentes</th>
                            <th>Primera llegada</th>
                            <th>Última llegada</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="evapp-company-empty evapp-company-hidden">Todavía no hay empresas con asistentes registrados en check-in.</div>

            <script>
            (function(){
                const root = document.getElementById(<?php echo wp_json_encode( $instance_id ); ?>);
                if (!root) return;

                const ajaxUrl = root.dataset.ajaxUrl;
                const nonce = root.dataset.nonce;
                const searchInput = root.querySelector('.evapp-company-search');
                const sortSelect = root.querySelector('.evapp-company-sort');
                const refreshButton = root.querySelector('.evapp-company-refresh');
                const loading = root.querySelector('.evapp-company-loading');
                const errorBox = root.querySelector('.evapp-company-error');
                const tableWrap = root.querySelector('.evapp-company-table-wrap');
                const tbody = root.querySelector('tbody');
                const emptyBox = root.querySelector('.evapp-company-empty');
                const resultsLabel = root.querySelector('.evapp-company-results');
                const updatedLabel = root.querySelector('.evapp-company-updated');
                let rows = [];
                let isLoading = false;

                const normalize = (value) => {
                    const stringValue = String(value || '').toLowerCase();
                    return typeof stringValue.normalize === 'function'
                        ? stringValue.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                        : stringValue;
                };

                const textCell = (text, className) => {
                    const element = document.createElement('span');
                    if (className) element.className = className;
                    element.textContent = text;
                    return element;
                };

                const setKpi = (key, value) => {
                    const element = root.querySelector('[data-kpi="' + key + '"]');
                    if (element) element.textContent = Number(value || 0).toLocaleString('es-CO');
                };

                const getVisibleRows = () => {
                    const term = normalize(searchInput.value).trim();
                    const visible = rows.filter((row) => !term || normalize(row.search_text).includes(term));

                    visible.sort((a, b) => {
                        if (sortSelect.value === 'quantity') {
                            return Number(b.attendees) - Number(a.attendees)
                                || Number(a.first_arrival_ts || Number.MAX_SAFE_INTEGER) - Number(b.first_arrival_ts || Number.MAX_SAFE_INTEGER)
                                || String(a.company).localeCompare(String(b.company), 'es');
                        }
                        if (sortSelect.value === 'name') {
                            return String(a.company).localeCompare(String(b.company), 'es', {sensitivity: 'base'});
                        }
                        return Number(a.first_arrival_ts || Number.MAX_SAFE_INTEGER) - Number(b.first_arrival_ts || Number.MAX_SAFE_INTEGER)
                            || String(a.company).localeCompare(String(b.company), 'es');
                    });

                    return visible;
                };

                const render = () => {
                    const visible = getVisibleRows();
                    tbody.replaceChildren();
                    resultsLabel.textContent = visible.length.toLocaleString('es-CO') + (visible.length === 1 ? ' empresa visible' : ' empresas visibles');

                    visible.forEach((row, index) => {
                        const tr = document.createElement('tr');

                        const rankTd = document.createElement('td');
                        rankTd.appendChild(textCell(String(index + 1), 'evapp-company-rank'));
                        tr.appendChild(rankTd);

                        const companyTd = document.createElement('td');
                        companyTd.appendChild(textCell(row.company || 'Empresa sin nombre registrado', 'evapp-company-name'));
                        tr.appendChild(companyTd);

                        const nitTd = document.createElement('td');
                        nitTd.appendChild(textCell(row.nit || 'Sin NIT'));
                        if (row.nit_has_conflict) {
                            nitTd.appendChild(textCell('Se detectaron varios dígitos de verificación para la misma base.', 'evapp-company-nit-warning'));
                        }
                        tr.appendChild(nitTd);

                        const aliasesTd = document.createElement('td');
                        const aliasesWrap = document.createElement('div');
                        aliasesWrap.className = 'evapp-company-aliases';
                        const aliases = Array.isArray(row.aliases) && row.aliases.length ? row.aliases : ['Sin nombre adicional'];
                        aliases.forEach((alias) => aliasesWrap.appendChild(textCell(alias, 'evapp-company-alias')));
                        aliasesTd.appendChild(aliasesWrap);
                        tr.appendChild(aliasesTd);

                        const countTd = document.createElement('td');
                        countTd.appendChild(textCell(Number(row.attendees || 0).toLocaleString('es-CO'), 'evapp-company-count'));
                        tr.appendChild(countTd);

                        const firstTd = document.createElement('td');
                        firstTd.textContent = row.first_arrival || 'Sin hora registrada';
                        tr.appendChild(firstTd);

                        const lastTd = document.createElement('td');
                        lastTd.textContent = row.last_arrival || 'Sin hora registrada';
                        tr.appendChild(lastTd);

                        tbody.appendChild(tr);
                    });

                    tableWrap.classList.toggle('evapp-company-hidden', visible.length === 0);
                    emptyBox.classList.toggle('evapp-company-hidden', visible.length !== 0);
                };

                const showError = (message) => {
                    errorBox.textContent = message || 'No fue posible cargar la información.';
                    errorBox.classList.remove('evapp-company-hidden');
                };

                const load = async () => {
                    if (isLoading) return;
                    isLoading = true;
                    refreshButton.disabled = true;
                    refreshButton.textContent = 'Actualizando…';
                    errorBox.classList.add('evapp-company-hidden');
                    if (!rows.length) loading.classList.remove('evapp-company-hidden');

                    try {
                        const body = new URLSearchParams();
                        body.set('action', 'eventosapp_company_checkin_data');
                        body.set('nonce', nonce);

                        const response = await fetch(ajaxUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                            body: body.toString()
                        });
                        const payload = await response.json();

                        if (!response.ok || !payload.success) {
                            throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'No fue posible actualizar el monitor.');
                        }

                        const data = payload.data || {};
                        rows = Array.isArray(data.rows) ? data.rows : [];
                        setKpi('companies', data.companies);
                        setKpi('identified', data.identified_attendees);
                        setKpi('checked', data.total_checked_in);
                        setKpi('unidentified', data.without_company_nit);
                        updatedLabel.textContent = 'Actualizado: ' + (data.generated_at || 'ahora');
                        render();
                    } catch (error) {
                        showError(error && error.message ? error.message : 'No fue posible cargar la información.');
                    } finally {
                        loading.classList.add('evapp-company-hidden');
                        refreshButton.disabled = false;
                        refreshButton.textContent = 'Actualizar ahora';
                        isLoading = false;
                    }
                };

                searchInput.addEventListener('input', render);
                sortSelect.addEventListener('change', render);
                refreshButton.addEventListener('click', load);
                document.addEventListener('visibilitychange', function(){
                    if (!document.hidden) load();
                });

                load();
                window.setInterval(function(){
                    if (!document.hidden) load();
                }, 12000);
            })();
            </script>
        </div>
        <?php
        return ob_get_clean();
    }
}
add_shortcode( 'eventosapp_company_checkin_monitor', 'eventosapp_company_checkin_monitor_shortcode' );
