<?php
/**
 * EventosApp – Hardening de rendimiento online para Android 2.11.2+.
 *
 * Objetivos:
 * - Resolver lecturas QR contra eventos grandes sin recorrer postmeta por cada QR.
 * - Precalentar el índice de lectura por evento sin bloquear la UI de Android.
 * - Evitar carreras read/modify/write cuando varias tablets registran el mismo
 *   ticket o la misma sesión casi simultáneamente.
 * - Evitar que la búsqueda textual del Kiosko Android cargue miles de tickets
 *   y sus metadatos en PHP para cada consulta.
 *
 * Este archivo se carga después de las APIs offline/avanzadas/consumibles, pero
 * antes de includes/frontend/eventosapp-qr-checkin.php. Por ello puede declarar
 * eventosapp_qr_find_ticket_by_scanned_code() conservando el mismo contrato
 * público y añadiendo un índice dedicado como primera ruta de resolución.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'EVENTOSAPP_MOBILE_ONLINE_PERFORMANCE_VERSION' ) ) {
    define( 'EVENTOSAPP_MOBILE_ONLINE_PERFORMANCE_VERSION', '1.0.0' );
}

if ( ! defined( 'EVENTOSAPP_MOBILE_ONLINE_LOOKUP_DB_VERSION' ) ) {
    define( 'EVENTOSAPP_MOBILE_ONLINE_LOOKUP_DB_VERSION', '1.0.0' );
}

if ( ! function_exists( 'eventosapp_mobile_online_lookup_table' ) ) {
    function eventosapp_mobile_online_lookup_table() {
        global $wpdb;
        return $wpdb->prefix . 'eventosapp_qr_lookup';
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_install_lookup_table' ) ) {
    function eventosapp_mobile_online_install_lookup_table() {
        global $wpdb;

        $table = eventosapp_mobile_online_lookup_table();
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            event_id bigint(20) unsigned NOT NULL,
            lookup_hash char(64) NOT NULL,
            ticket_id bigint(20) unsigned NOT NULL,
            qr_type varchar(64) NOT NULL DEFAULT 'unknown',
            qr_type_label varchar(96) NOT NULL DEFAULT 'QR',
            updated_at datetime NOT NULL,
            PRIMARY KEY  (event_id,lookup_hash),
            KEY event_ticket (event_id,ticket_id),
            KEY ticket_id (ticket_id)
        ) ENGINE=InnoDB {$charset_collate};";

        dbDelta( $sql );

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( $exists ) {
            update_option( 'eventosapp_mobile_online_lookup_db_version', EVENTOSAPP_MOBILE_ONLINE_LOOKUP_DB_VERSION, false );
            return true;
        }
        return false;
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_maybe_install_lookup_table' ) ) {
    function eventosapp_mobile_online_maybe_install_lookup_table() {
        if ( get_option( 'eventosapp_mobile_online_lookup_db_version' ) !== EVENTOSAPP_MOBILE_ONLINE_LOOKUP_DB_VERSION ) {
            eventosapp_mobile_online_install_lookup_table();
        }
    }
}
add_action( 'init', 'eventosapp_mobile_online_maybe_install_lookup_table', 6 );

if ( ! function_exists( 'eventosapp_mobile_online_lookup_table_ready' ) ) {
    function eventosapp_mobile_online_lookup_table_ready() {
        return get_option( 'eventosapp_mobile_online_lookup_db_version' ) === EVENTOSAPP_MOBILE_ONLINE_LOOKUP_DB_VERSION;
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_normalize_scan' ) ) {
    function eventosapp_mobile_online_normalize_scan( $value ) {
        if ( function_exists( 'eventosapp_mobile_offline_normalize_scan' ) ) {
            return eventosapp_mobile_offline_normalize_scan( $value );
        }
        return trim( str_replace( [ "\0", "\r", "\n" ], '', (string) $value ) );
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_delete_ticket_index' ) ) {
    function eventosapp_mobile_online_delete_ticket_index( $ticket_id ) {
        global $wpdb;
        if ( ! eventosapp_mobile_online_lookup_table_ready() ) return;
        $wpdb->delete(
            eventosapp_mobile_online_lookup_table(),
            [ 'ticket_id' => absint( $ticket_id ) ],
            [ '%d' ]
        );
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_purge_event_index' ) ) {
    function eventosapp_mobile_online_purge_event_index( $event_id ) {
        global $wpdb;
        if ( ! eventosapp_mobile_online_lookup_table_ready() ) return;
        $wpdb->delete(
            eventosapp_mobile_online_lookup_table(),
            [ 'event_id' => absint( $event_id ) ],
            [ '%d' ]
        );
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_index_ticket' ) ) {
    /**
     * Reemplaza todas las claves QR conocidas de un ticket dentro del índice.
     * Los valores QR nunca se guardan en claro: solo SHA-256 + tipo/etiqueta.
     */
    function eventosapp_mobile_online_index_ticket( $ticket_id ) {
        global $wpdb;

        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id || ! eventosapp_mobile_online_lookup_table_ready() ) return 0;

        eventosapp_mobile_online_delete_ticket_index( $ticket_id );

        if ( get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) return 0;
        if ( in_array( get_post_status( $ticket_id ), [ 'trash', 'auto-draft', 'inherit' ], true ) ) return 0;

        $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) return 0;

        $keys = function_exists( 'eventosapp_mobile_offline_ticket_lookup_keys' )
            ? eventosapp_mobile_offline_ticket_lookup_keys( $ticket_id, $event_id )
            : [];

        $inserted = 0;
        $table = eventosapp_mobile_online_lookup_table();
        $updated_at = current_time( 'mysql' );

        foreach ( (array) $keys as $key ) {
            $hash = strtolower( sanitize_text_field( (string) ( $key['hash'] ?? '' ) ) );
            if ( ! preg_match( '/^[0-9a-f]{64}$/', $hash ) ) continue;

            $ok = $wpdb->replace(
                $table,
                [
                    'event_id'      => $event_id,
                    'lookup_hash'   => $hash,
                    'ticket_id'     => $ticket_id,
                    'qr_type'       => sanitize_key( (string) ( $key['type'] ?? 'unknown' ) ),
                    'qr_type_label' => sanitize_text_field( (string) ( $key['type_label'] ?? 'QR' ) ),
                    'updated_at'    => $updated_at,
                ],
                [ '%d', '%s', '%d', '%s', '%s', '%s' ]
            );
            if ( $ok !== false ) $inserted++;
        }

        return $inserted;
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_index_queue' ) ) {
    function eventosapp_mobile_online_index_queue( $ticket_id = 0 ) {
        static $queue = [];
        $ticket_id = absint( $ticket_id );
        if ( $ticket_id ) $queue[ $ticket_id ] = true;
        return array_keys( $queue );
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_flush_index_queue' ) ) {
    function eventosapp_mobile_online_flush_index_queue() {
        foreach ( eventosapp_mobile_online_index_queue() as $ticket_id ) {
            eventosapp_mobile_online_index_ticket( $ticket_id );
        }
    }
}
add_action( 'shutdown', 'eventosapp_mobile_online_flush_index_queue', 20 );

add_action( 'save_post_eventosapp_ticket', function ( $post_id ) {
    eventosapp_mobile_online_index_queue( $post_id );
}, 100 );

if ( ! function_exists( 'eventosapp_mobile_online_qr_meta_keys' ) ) {
    function eventosapp_mobile_online_qr_meta_keys() {
        return [
            '_eventosapp_ticket_evento_id',
            'eventosapp_ticketID',
            'eventosapp_ticket_preprintedID',
            '_eventosapp_badge_security_code',
            '_eventosapp_qr_email',
            '_eventosapp_qr_google_wallet',
            '_eventosapp_qr_apple_wallet',
            '_eventosapp_qr_pdf',
            '_eventosapp_qr_whatsapp',
            '_eventosapp_qr_badge',
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_meta_changed' ) ) {
    function eventosapp_mobile_online_meta_changed( $meta_id, $object_id, $meta_key, $meta_value = null ) {
        $object_id = absint( $object_id );
        $meta_key = (string) $meta_key;
        $post_type = $object_id ? get_post_type( $object_id ) : '';

        if ( $post_type === 'eventosapp_ticket' && in_array( $meta_key, eventosapp_mobile_online_qr_meta_keys(), true ) ) {
            eventosapp_mobile_online_index_queue( $object_id );
            return;
        }

        if ( $post_type === 'eventosapp_event' && $meta_key === '_eventosapp_ticket_use_preprinted_qr' ) {
            eventosapp_mobile_online_purge_event_index( $object_id );
        }
    }
}
add_action( 'added_post_meta', 'eventosapp_mobile_online_meta_changed', 100, 4 );
add_action( 'updated_post_meta', 'eventosapp_mobile_online_meta_changed', 100, 4 );
add_action( 'deleted_post_meta', 'eventosapp_mobile_online_meta_changed', 100, 4 );

add_action( 'trashed_post', function ( $post_id ) {
    if ( get_post_type( $post_id ) === 'eventosapp_ticket' ) {
        eventosapp_mobile_online_delete_ticket_index( $post_id );
    }
}, 20 );
add_action( 'before_delete_post', function ( $post_id ) {
    if ( get_post_type( $post_id ) === 'eventosapp_ticket' ) {
        eventosapp_mobile_online_delete_ticket_index( $post_id );
    }
}, 20 );

if ( ! function_exists( 'eventosapp_mobile_online_fast_qr_lookup' ) ) {
    /** Devuelve null cuando el índice no tiene esa lectura; nunca bloquea el fallback. */
    function eventosapp_mobile_online_fast_qr_lookup( $scanned, $event_id ) {
        global $wpdb;

        if ( ! eventosapp_mobile_online_lookup_table_ready() ) return null;
        $event_id = absint( $event_id );
        $normalized = eventosapp_mobile_online_normalize_scan( $scanned );
        if ( ! $event_id || $normalized === '' ) return null;

        $candidate_values = [ $normalized ];
        if ( get_post_meta( $event_id, '_eventosapp_ticket_use_preprinted_qr', true ) === '1' ) {
            $digits = preg_replace( '/\D+/', '', $normalized );
            if ( $digits !== '' && $digits !== $normalized ) $candidate_values[] = $digits;
        }

        $table = eventosapp_mobile_online_lookup_table();
        foreach ( array_unique( $candidate_values ) as $candidate ) {
            $hash = hash( 'sha256', $candidate );
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT ticket_id,qr_type,qr_type_label FROM {$table} WHERE event_id=%d AND lookup_hash=%s LIMIT 1",
                $event_id,
                $hash
            ), ARRAY_A );
            if ( ! is_array( $row ) ) continue;

            $ticket_id = absint( $row['ticket_id'] ?? 0 );
            $valid = $ticket_id
                && get_post_type( $ticket_id ) === 'eventosapp_ticket'
                && ! in_array( get_post_status( $ticket_id ), [ 'trash', 'auto-draft', 'inherit' ], true )
                && absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) === $event_id;

            if ( ! $valid ) {
                $wpdb->delete( $table, [ 'event_id' => $event_id, 'lookup_hash' => $hash ], [ '%d', '%s' ] );
                continue;
            }

            return [
                'found'      => true,
                'ticket_id'  => $ticket_id,
                'type'       => sanitize_key( $row['qr_type'] ?? 'unknown' ),
                'type_label' => sanitize_text_field( $row['qr_type_label'] ?? 'QR' ),
                'error'      => '',
                'indexed'    => true,
            ];
        }
        return null;
    }
}

/**
 * Contrato histórico del resolvedor QR, ahora con fast-path indexado.
 * El fallback permanece para compatibilidad y además autoindexa el ticket que
 * encuentre, por lo que una primera lectura no indexada acelera las siguientes.
 */
if ( ! function_exists( 'eventosapp_qr_find_ticket_by_scanned_code' ) ) {
    function eventosapp_qr_find_ticket_by_scanned_code( $scanned, $event_id, $use_preprinted = null ) {
        global $wpdb;

        $scanned = eventosapp_mobile_online_normalize_scan( sanitize_text_field( (string) $scanned ) );
        $event_id = absint( $event_id );
        $empty = [
            'found'      => false,
            'ticket_id'  => 0,
            'type'       => 'unknown',
            'type_label' => 'QR Estándar',
            'error'      => '',
        ];

        if ( $scanned === '' || ! $event_id ) {
            $empty['error'] = 'Datos incompletos';
            return $empty;
        }

        $cache_key = 'evapp_qr_lookup_' . md5( $event_id . '|' . $scanned . '|' . ( is_null( $use_preprinted ) ? 'auto' : (string) (int) (bool) $use_preprinted ) );
        $cached = wp_cache_get( $cache_key, 'eventosapp_qr' );
        if ( is_array( $cached ) ) return $cached;

        $fast = eventosapp_mobile_online_fast_qr_lookup( $scanned, $event_id );
        if ( is_array( $fast ) && ! empty( $fast['found'] ) ) {
            wp_cache_set( $cache_key, $fast, 'eventosapp_qr', 60 );
            return $fast;
        }

        if ( class_exists( 'EventosApp_QR_Manager' ) ) {
            $validation = EventosApp_QR_Manager::validate_qr( $scanned );
            if ( isset( $validation['valid'] ) && $validation['valid'] === true && ! empty( $validation['ticket_id'] ) ) {
                $candidate_id = absint( $validation['ticket_id'] );
                $ticket_event = absint( get_post_meta( $candidate_id, '_eventosapp_ticket_evento_id', true ) );
                if ( $ticket_event === $event_id ) {
                    eventosapp_mobile_online_index_ticket( $candidate_id );
                    $result = [
                        'found'      => true,
                        'ticket_id'  => $candidate_id,
                        'type'       => sanitize_key( (string) ( $validation['type'] ?? 'qr_manager' ) ),
                        'type_label' => sanitize_text_field( (string) ( $validation['type_label'] ?? ( $validation['type'] ?? 'QR' ) ) ),
                        'error'      => '',
                    ];
                    wp_cache_set( $cache_key, $result, 'eventosapp_qr', 60 );
                    return $result;
                }
                $empty['error'] = 'El QR no corresponde a este evento';
                wp_cache_set( $cache_key, $empty, 'eventosapp_qr', 30 );
                return $empty;
            }
        }

        if ( is_null( $use_preprinted ) ) {
            $use_preprinted = get_post_meta( $event_id, '_eventosapp_ticket_use_preprinted_qr', true ) === '1';
        } else {
            $use_preprinted = (bool) $use_preprinted;
        }

        $meta_key = $use_preprinted ? 'eventosapp_ticket_preprintedID' : 'eventosapp_ticketID';
        if ( $use_preprinted ) {
            $scan_val = preg_replace( '/\D+/', '', $scanned );
            if ( $scan_val === '' ) {
                $empty['error'] = 'QR inválido: se esperaba un número.';
                wp_cache_set( $cache_key, $empty, 'eventosapp_qr', 30 );
                return $empty;
            }
        } else {
            $scan_val = $scanned;
        }

        $ticket_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT code_pm.post_id
               FROM {$wpdb->postmeta} code_pm
               INNER JOIN {$wpdb->postmeta} event_pm
                       ON event_pm.post_id = code_pm.post_id
                      AND event_pm.meta_key = %s
                      AND event_pm.meta_value = %s
               INNER JOIN {$wpdb->posts} p
                       ON p.ID = code_pm.post_id
                      AND p.post_type = 'eventosapp_ticket'
                      AND p.post_status NOT IN ('trash','auto-draft','inherit')
              WHERE code_pm.meta_key = %s
                AND code_pm.meta_value = %s
              LIMIT 1",
            '_eventosapp_ticket_evento_id',
            (string) $event_id,
            $meta_key,
            $scan_val
        ) );

        if ( $ticket_id ) {
            eventosapp_mobile_online_index_ticket( $ticket_id );
            $result = [
                'found'      => true,
                'ticket_id'  => absint( $ticket_id ),
                'type'       => 'legacy',
                'type_label' => $use_preprinted ? 'QR Preimpreso' : 'QR Legacy',
                'error'      => '',
            ];
            wp_cache_set( $cache_key, $result, 'eventosapp_qr', 60 );
            return $result;
        }

        $empty['error'] = 'Ticket no encontrado para este evento.';
        wp_cache_set( $cache_key, $empty, 'eventosapp_qr', 30 );
        return $empty;
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_warm_event_index' ) ) {
    function eventosapp_mobile_online_warm_event_index( WP_REST_Request $request ) {
        global $wpdb;

        $event_id = absint( $request['event_id'] );
        $cursor = max( 0, absint( $request->get_param( 'cursor' ) ) );
        $limit = min( 500, max( 50, absint( $request->get_param( 'limit' ) ?: 500 ) ) );
        $user_id = get_current_user_id();

        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return new WP_Error( 'invalid_event', 'El evento no existe.', [ 'status' => 404 ] );
        }

        $modules = function_exists( 'eventosapp_mobile_event_offline_modules' )
            ? eventosapp_mobile_event_offline_modules( $event_id, $user_id )
            : [];
        if ( empty( $modules ) ) {
            return new WP_Error( 'forbidden_event', 'No tienes módulos móviles autorizados para este evento.', [ 'status' => 403 ] );
        }

        if ( ! eventosapp_mobile_online_lookup_table_ready() && ! eventosapp_mobile_online_install_lookup_table() ) {
            return new WP_Error( 'online_index_unavailable', 'No se pudo preparar el índice de lectura online.', [ 'status' => 500 ] );
        }

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} event_pm
                       ON event_pm.post_id=p.ID
                      AND event_pm.meta_key='_eventosapp_ticket_evento_id'
                      AND event_pm.meta_value=%s
              WHERE p.post_type='eventosapp_ticket'
                AND p.post_status IN ('publish','future','draft','pending','private')
                AND p.ID>%d
              ORDER BY p.ID ASC
              LIMIT %d",
            (string) $event_id,
            $cursor,
            $limit
        ) );
        $ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );

        if ( $ids ) update_meta_cache( 'post', $ids );
        $keys = 0;
        foreach ( $ids as $ticket_id ) {
            $keys += eventosapp_mobile_online_index_ticket( $ticket_id );
        }

        $next_cursor = $ids ? max( $ids ) : $cursor;
        $complete = count( $ids ) < $limit;

        return rest_ensure_response( [
            'api_version' => EVENTOSAPP_MOBILE_ONLINE_PERFORMANCE_VERSION,
            'event_id'    => $event_id,
            'processed'   => count( $ids ),
            'keys'        => $keys,
            'next_cursor' => $next_cursor,
            'complete'    => $complete,
        ] );
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_lock_key' ) ) {
    function eventosapp_mobile_online_lock_key( $scope, $event_id, $ticket_id, $dimension ) {
        return 'evapp_onlock_' . md5(
            sanitize_key( $scope ) . '|' . absint( $event_id ) . '|' . absint( $ticket_id ) . '|' . (string) $dimension
        );
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_acquire_lock' ) ) {
    function eventosapp_mobile_online_acquire_lock( $scope, $event_id, $ticket_id, $dimension, $ttl = 30 ) {
        $key = eventosapp_mobile_online_lock_key( $scope, $event_id, $ticket_id, $dimension );
        $now = time();
        $ttl = min( 120, max( 5, absint( $ttl ) ) );

        if ( add_option( $key, $now, '', false ) ) return $key;
        $existing = absint( get_option( $key, 0 ) );
        if ( $existing && ( $now - $existing ) > $ttl ) {
            delete_option( $key );
            if ( add_option( $key, $now, '', false ) ) return $key;
        }
        return '';
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_release_lock' ) ) {
    function eventosapp_mobile_online_release_lock( $key ) {
        if ( $key ) delete_option( $key );
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_acquire_checkin_lock' ) ) {
    /** Reutiliza el lock offline cuando existe para coordinar online + sync. */
    function eventosapp_mobile_online_acquire_checkin_lock( $event_id, $ticket_id, $day ) {
        if ( function_exists( 'eventosapp_mobile_offline_acquire_lock' ) ) {
            return eventosapp_mobile_offline_acquire_lock( $event_id, $ticket_id, $day );
        }
        return eventosapp_mobile_online_acquire_lock( 'checkin', $event_id, $ticket_id, $day, 30 );
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_release_checkin_lock' ) ) {
    function eventosapp_mobile_online_release_checkin_lock( $key ) {
        if ( function_exists( 'eventosapp_mobile_offline_release_lock' ) ) {
            eventosapp_mobile_offline_release_lock( $key );
        } else {
            eventosapp_mobile_online_release_lock( $key );
        }
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_find_tickets_by_auth_fields' ) ) {
    /**
     * Búsqueda textual acotada para Kiosko Android. Genera candidatos por SQL
     * y carga metadatos solo de esos candidatos; nunca hace el fallback histórico
     * de cargar 6.000/10.000 tickets completos en PHP por cada búsqueda.
     */
    function eventosapp_mobile_online_find_tickets_by_auth_fields( $search, $event_id, $fields, $limit = 20 ) {
        global $wpdb;

        $event_id = absint( $event_id );
        $limit = min( 50, max( 1, absint( $limit ) ) );
        $fields = function_exists( 'eventosapp_self_checkin_normalize_auth_fields' )
            ? eventosapp_self_checkin_normalize_auth_fields( $fields )
            : array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $fields ) ) ) );
        $search = function_exists( 'eventosapp_self_checkin_normalize_search' )
            ? eventosapp_self_checkin_normalize_search( $search )
            : sanitize_text_field( (string) $search );

        if ( ! $event_id || $search === '' || empty( $fields ) ) return [];

        $cache_key = 'evapp_mobile_online_text_' . md5( $event_id . '|' . implode( ',', $fields ) . '|' . $search . '|' . $limit );
        $cached = wp_cache_get( $cache_key, 'eventosapp_self_checkin' );
        if ( is_array( $cached ) ) return $cached;

        $candidate_ids = [];
        $candidate_limit = max( 120, $limit * 8 );
        $add_rows = static function ( $rows ) use ( &$candidate_ids, $candidate_limit ) {
            foreach ( (array) $rows as $id ) {
                $id = absint( $id );
                if ( $id ) $candidate_ids[ $id ] = true;
                if ( count( $candidate_ids ) >= $candidate_limit ) break;
            }
        };

        $event_join = " INNER JOIN {$wpdb->postmeta} event_pm ON event_pm.post_id=p.ID AND event_pm.meta_key='_eventosapp_ticket_evento_id' AND event_pm.meta_value=%s ";
        $status_where = " p.post_type='eventosapp_ticket' AND p.post_status NOT IN ('trash','auto-draft','inherit') ";

        if ( in_array( 'identification', $fields, true ) ) {
            $compact = preg_replace( '/[^a-z0-9]/i', '', strtolower( remove_accents( $search ) ) );
            $digits = preg_replace( '/\D+/', '', $search );
            $like = '%' . $wpdb->esc_like( $compact !== '' ? $compact : $search ) . '%';
            $rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$event_join}
                 INNER JOIN {$wpdb->postmeta} value_pm ON value_pm.post_id=p.ID
                 WHERE {$status_where}
                   AND value_pm.meta_key IN ('_eventosapp_asistente_cc','_eventosapp_asistente_cedula','_evapp_search_cc','_evapp_search_identification')
                   AND (value_pm.meta_value=%s OR value_pm.meta_value=%s OR value_pm.meta_value LIKE %s)
                 ORDER BY p.ID DESC LIMIT %d",
                (string) $event_id,
                $search,
                $compact !== '' ? $compact : $digits,
                $like,
                $candidate_limit
            ) );
            $add_rows( $rows );
        }

        if ( in_array( 'phone', $fields, true ) && count( $candidate_ids ) < $candidate_limit ) {
            $digits = preg_replace( '/\D+/', '', $search );
            $needle = $digits !== '' ? $digits : $search;
            $like = '%' . $wpdb->esc_like( $needle ) . '%';
            $rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$event_join}
                 INNER JOIN {$wpdb->postmeta} value_pm ON value_pm.post_id=p.ID
                 WHERE {$status_where}
                   AND value_pm.meta_key IN ('_eventosapp_asistente_tel','_eventosapp_asistente_telefono','_evapp_search_phone')
                   AND (value_pm.meta_value=%s OR value_pm.meta_value LIKE %s)
                 ORDER BY p.ID DESC LIMIT %d",
                (string) $event_id,
                $needle,
                $like,
                $candidate_limit
            ) );
            $add_rows( $rows );
        }

        if ( ( in_array( 'full_name', $fields, true ) || in_array( 'last_name', $fields, true ) ) && count( $candidate_ids ) < $candidate_limit ) {
            $terms = preg_split( '/\s+/u', trim( $search ) );
            $anchor = sanitize_text_field( (string) ( $terms[0] ?? $search ) );
            $like = '%' . $wpdb->esc_like( $anchor ) . '%';
            $meta_keys = in_array( 'full_name', $fields, true )
                ? "('_eventosapp_asistente_nombre','_eventosapp_asistente_apellido')"
                : "('_eventosapp_asistente_apellido')";
            $rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$event_join}
                 INNER JOIN {$wpdb->postmeta} value_pm ON value_pm.post_id=p.ID
                 WHERE {$status_where}
                   AND value_pm.meta_key IN {$meta_keys}
                   AND value_pm.meta_value LIKE %s
                 ORDER BY p.ID DESC LIMIT %d",
                (string) $event_id,
                $like,
                $candidate_limit
            ) );
            $add_rows( $rows );
        }

        $ids = array_keys( $candidate_ids );
        if ( $ids ) update_meta_cache( 'post', $ids );

        $matched = [];
        foreach ( $ids as $ticket_id ) {
            $matches = function_exists( 'eventosapp_self_checkin_ticket_matches_auth_search' )
                ? eventosapp_self_checkin_ticket_matches_auth_search( $ticket_id, $search, $fields )
                : true;
            if ( ! $matches ) continue;
            $matched[] = absint( $ticket_id );
            if ( count( $matched ) >= $limit ) break;
        }

        wp_cache_set( $cache_key, $matched, 'eventosapp_self_checkin', 30 );
        return $matched;
    }
}

if ( ! function_exists( 'eventosapp_mobile_online_kiosk_text_search' ) ) {
    function eventosapp_mobile_online_kiosk_text_search( WP_REST_Request $request, $event_id ) {
        $event_id = absint( $event_id );
        if ( ! eventosapp_kiosk_api_event_is_enabled( $event_id ) ) {
            return new WP_Error( 'kiosk_disabled', 'El kiosko no está habilitado para este evento.', [ 'status' => 409 ] );
        }

        $auth_fields = function_exists( 'eventosapp_self_checkin_get_event_auth_fields' )
            ? eventosapp_self_checkin_get_event_auth_fields( $event_id )
            : [ 'identification' ];
        $text_auth_enabled = function_exists( 'eventosapp_self_checkin_event_text_auth_enabled' )
            ? eventosapp_self_checkin_event_text_auth_enabled( $event_id )
            : true;
        if ( ! $text_auth_enabled ) {
            return new WP_Error( 'text_auth_disabled', 'Este evento está configurado para autenticación mediante código QR.', [ 'status' => 409 ] );
        }

        $raw_query = (string) $request->get_param( 'query' );
        $query = function_exists( 'eventosapp_self_checkin_validate_auth_search' )
            ? eventosapp_self_checkin_validate_auth_search( $raw_query, $auth_fields )
            : sanitize_text_field( $raw_query );
        if ( is_wp_error( $query ) ) {
            return new WP_Error( $query->get_error_code(), $query->get_error_message(), [ 'status' => 400 ] );
        }

        $ticket_ids = eventosapp_mobile_online_find_tickets_by_auth_fields( $query, $event_id, $auth_fields, 20 );
        $results = [];
        foreach ( $ticket_ids as $ticket_id ) {
            if ( function_exists( 'eventosapp_ticket_is_virtual' ) && eventosapp_ticket_is_virtual( $ticket_id ) ) continue;
            $ticket = eventosapp_kiosk_api_ticket_payload( $ticket_id );
            if ( ! $ticket || absint( $ticket['event_id'] ?? 0 ) !== $event_id ) continue;
            $results[] = $ticket;
        }

        $enabled_auth_fields = array_values( $auth_fields );
        $qr_enabled = function_exists( 'eventosapp_self_checkin_event_qr_auth_enabled' )
            ? eventosapp_self_checkin_event_qr_auth_enabled( $event_id )
            : get_post_meta( $event_id, '_eventosapp_self_checkin_qr_enabled', true ) === '1';
        if ( $qr_enabled ) $enabled_auth_fields[] = 'qr';

        return rest_ensure_response( [
            'query'       => sanitize_text_field( $query ),
            'query_type'  => 'text',
            'results'     => $results,
            'total'       => count( $results ),
            'auth_fields' => $enabled_auth_fields,
            'optimized'   => true,
        ] );
    }
}

/**
 * Intercepta únicamente escrituras/consultas online de Android que necesitan
 * exclusión o una búsqueda textual acotada. Cada ruta vuelve a autenticar el
 * token antes de devolver una respuesta desde rest_pre_dispatch.
 */
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
    if ( $result !== null || ! $request instanceof WP_REST_Request ) return $result;
    if ( strtoupper( $request->get_method() ) !== 'POST' ) return $result;

    $route = $request->get_route();

    // Kiosko: sustituye solo la búsqueda textual. Las lecturas QR siguen la
    // callback original, que ahora usa el resolvedor indexado declarado arriba.
    if ( preg_match( '#^/eventosapp-kiosk/v1/events/(\d+)/search$#', $route, $matches ) ) {
        $raw_query = (string) $request->get_param( 'query' );
        if ( function_exists( 'eventosapp_kiosk_api_is_qr_query' ) && eventosapp_kiosk_api_is_qr_query( $raw_query ) ) {
            return $result;
        }
        $event_id = absint( $matches[1] );
        $user = function_exists( 'eventosapp_mobile_app_token_user' ) ? eventosapp_mobile_app_token_user( $request ) : null;
        if ( is_wp_error( $user ) ) return $user;
        if ( ! $user instanceof WP_User || ! eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user->ID, 'self_checkin' ) ) {
            return new WP_Error( 'forbidden_event', 'No tienes permiso de Autogestión del Asistente para este evento.', [ 'status' => 403 ] );
        }
        return eventosapp_mobile_online_kiosk_text_search( $request, $event_id );
    }

    // Staff QR: serializa por ticket/día para que dos tablets no escriban el
    // mismo array de estados/logs simultáneamente.
    if ( preg_match( '#^/eventosapp-kiosk/v1/staff/events/(\d+)/checkin$#', $route, $matches ) ) {
        $event_id = absint( $matches[1] );
        $user = function_exists( 'eventosapp_mobile_app_token_user' ) ? eventosapp_mobile_app_token_user( $request ) : null;
        if ( is_wp_error( $user ) ) return $user;
        if ( ! $user instanceof WP_User || ! eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user->ID, 'qr' ) ) {
            return new WP_Error( 'forbidden_event', 'No tienes permiso de Check-in con QR para este evento.', [ 'status' => 403 ] );
        }
        $lookup = eventosapp_mobile_app_find_ticket( $request->get_param( 'scanned' ), $event_id );
        if ( is_wp_error( $lookup ) ) return $lookup;
        $ticket_id = absint( $lookup['ticket_id'] ?? 0 );
        $day = function_exists( 'eventosapp_get_event_current_date' )
            ? eventosapp_get_event_current_date( $event_id )
            : eventosapp_mobile_app_event_datetime( $event_id )->format( 'Y-m-d' );
        $lock = eventosapp_mobile_online_acquire_checkin_lock( $event_id, $ticket_id, $day );
        if ( ! $lock ) {
            return new WP_Error( 'checkin_busy', 'El ticket está siendo procesado por otro dispositivo. Intenta nuevamente.', [ 'status' => 409 ] );
        }
        try {
            return eventosapp_mobile_app_staff_checkin( $request );
        } finally {
            eventosapp_mobile_online_release_checkin_lock( $lock );
        }
    }

    // Kiosko: protege la escritura de check-in que precede a la impresión.
    if ( preg_match( '#^/eventosapp-kiosk/v1/tickets/(\d+)/print$#', $route, $matches ) ) {
        $ticket_id = absint( $matches[1] );
        $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        $user = function_exists( 'eventosapp_mobile_app_token_user' ) ? eventosapp_mobile_app_token_user( $request ) : null;
        if ( is_wp_error( $user ) ) return $user;
        if ( ! $user instanceof WP_User || ! eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user->ID, 'self_checkin' ) ) {
            return new WP_Error( 'forbidden_ticket', 'No tienes acceso a este ticket.', [ 'status' => 403 ] );
        }
        $day = function_exists( 'eventosapp_get_event_current_date' ) ? eventosapp_get_event_current_date( $event_id ) : current_time( 'Y-m-d' );
        $lock = eventosapp_mobile_online_acquire_checkin_lock( $event_id, $ticket_id, $day );
        if ( ! $lock ) {
            return new WP_Error( 'checkin_busy', 'El ticket está siendo procesado por otro dispositivo. Intenta nuevamente.', [ 'status' => 409 ] );
        }
        try {
            return eventosapp_kiosk_api_print( $request );
        } finally {
            eventosapp_mobile_online_release_checkin_lock( $lock );
        }
    }

    // Sesiones: el candado incorpora el nombre de sesión para no impedir que
    // un mismo ticket pueda entrar simultáneamente a sesiones distintas.
    if ( preg_match( '#^/eventosapp-kiosk/v1/events/(\d+)/qr-session/checkin$#', $route, $matches ) ) {
        $event_id = absint( $matches[1] );
        $user = function_exists( 'eventosapp_mobile_app_token_user' ) ? eventosapp_mobile_app_token_user( $request ) : null;
        if ( is_wp_error( $user ) ) return $user;
        $ticket_id = absint( $request->get_param( 'ticket_id' ) );
        $session = trim( sanitize_text_field( (string) $request->get_param( 'session' ) ) );
        $lock = eventosapp_mobile_online_acquire_lock( 'session', $event_id, $ticket_id, $session, 30 );
        if ( ! $lock ) {
            return new WP_Error( 'session_busy', 'Este ingreso de sesión está siendo procesado por otro dispositivo. Intenta nuevamente.', [ 'status' => 409 ] );
        }
        try {
            return eventosapp_mobile_advanced_session_checkin( $request );
        } finally {
            eventosapp_mobile_online_release_lock( $lock );
        }
    }

    // Doble Auth termina en el check-in general serializado por ticket/día.
    if ( preg_match( '#^/eventosapp-kiosk/v1/events/(\d+)/qr-double-auth/checkin$#', $route, $matches ) ) {
        $event_id = absint( $matches[1] );
        $user = function_exists( 'eventosapp_mobile_app_token_user' ) ? eventosapp_mobile_app_token_user( $request ) : null;
        if ( is_wp_error( $user ) ) return $user;
        $ticket_id = absint( $request->get_param( 'ticket_id' ) );
        $day = function_exists( 'eventosapp_get_event_current_date' ) ? eventosapp_get_event_current_date( $event_id ) : current_time( 'Y-m-d' );
        $lock = eventosapp_mobile_online_acquire_checkin_lock( $event_id, $ticket_id, $day );
        if ( ! $lock ) {
            return new WP_Error( 'checkin_busy', 'El ticket está siendo procesado por otro dispositivo. Intenta nuevamente.', [ 'status' => 409 ] );
        }
        try {
            return eventosapp_mobile_advanced_double_auth_checkin( $request );
        } finally {
            eventosapp_mobile_online_release_checkin_lock( $lock );
        }
    }

    return $result;
}, 12, 3 );

add_action( 'rest_api_init', function () {
    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\d+)/online-index/warm', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'eventosapp_mobile_online_warm_event_index',
        'permission_callback' => 'eventosapp_mobile_app_permission',
        'args'                => [
            'event_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
            'cursor'   => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            'limit'    => [ 'required' => false, 'sanitize_callback' => 'absint' ],
        ],
    ] );
}, 45 );
