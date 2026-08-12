<?php
/**
 * EventosApp – Indexación QR masiva para warm-up online.
 *
 * La indexación incremental de un ticket continúa usando la función individual.
 * Este helper se usa únicamente cuando el warm-up prepara cientos de tickets:
 * elimina sus filas en una sola sentencia y hace INSERT/UPDATE por bloques.
 *
 * Retorno:
 * - >= 0: cantidad de claves escritas correctamente.
 * - -1: error SQL; el coordinador no debe avanzar el cursor del warm-up.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'eventosapp_mobile_online_index_ticket_batch' ) ) {
    function eventosapp_mobile_online_index_ticket_batch( $ticket_ids ) {
        global $wpdb;

        if ( ! function_exists( 'eventosapp_mobile_online_lookup_table_ready' ) ||
             ! eventosapp_mobile_online_lookup_table_ready() ) {
            return -1;
        }

        $ticket_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ticket_ids ) ) ) );
        if ( empty( $ticket_ids ) ) {
            return 0;
        }

        $table = eventosapp_mobile_online_lookup_table();
        $id_placeholders = implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) );
        $delete_sql = $wpdb->prepare(
            "DELETE FROM {$table} WHERE ticket_id IN ({$id_placeholders})",
            $ticket_ids
        );
        if ( ! is_string( $delete_sql ) || $delete_sql === '' || $wpdb->query( $delete_sql ) === false ) {
            return -1;
        }

        $updated_at = current_time( 'mysql' );
        $rows = [];

        foreach ( $ticket_ids as $ticket_id ) {
            if ( get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) continue;
            if ( in_array( get_post_status( $ticket_id ), [ 'trash', 'auto-draft', 'inherit' ], true ) ) continue;

            $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
            if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) continue;

            $keys = function_exists( 'eventosapp_mobile_offline_ticket_lookup_keys' )
                ? eventosapp_mobile_offline_ticket_lookup_keys( $ticket_id, $event_id )
                : [];

            foreach ( (array) $keys as $key ) {
                $hash = strtolower( sanitize_text_field( (string) ( $key['hash'] ?? '' ) ) );
                if ( ! preg_match( '/^[0-9a-f]{64}$/', $hash ) ) continue;

                $rows[] = [
                    'event_id'      => $event_id,
                    'lookup_hash'   => $hash,
                    'ticket_id'     => $ticket_id,
                    'qr_type'       => sanitize_key( (string) ( $key['type'] ?? 'unknown' ) ),
                    'qr_type_label' => sanitize_text_field( (string) ( $key['type_label'] ?? 'QR' ) ),
                    'updated_at'    => $updated_at,
                ];
            }
        }

        if ( empty( $rows ) ) {
            return 0;
        }

        $written = 0;
        foreach ( array_chunk( $rows, 300 ) as $chunk ) {
            $placeholders = [];
            $args = [];

            foreach ( $chunk as $row ) {
                $placeholders[] = '(%d,%s,%d,%s,%s,%s)';
                $args[] = absint( $row['event_id'] );
                $args[] = (string) $row['lookup_hash'];
                $args[] = absint( $row['ticket_id'] );
                $args[] = (string) $row['qr_type'];
                $args[] = (string) $row['qr_type_label'];
                $args[] = (string) $row['updated_at'];
            }

            $sql = "INSERT INTO {$table}
                        (event_id,lookup_hash,ticket_id,qr_type,qr_type_label,updated_at)
                    VALUES " . implode( ',', $placeholders ) . "
                    ON DUPLICATE KEY UPDATE
                        ticket_id=VALUES(ticket_id),
                        qr_type=VALUES(qr_type),
                        qr_type_label=VALUES(qr_type_label),
                        updated_at=VALUES(updated_at)";
            $prepared = $wpdb->prepare( $sql, $args );
            if ( ! is_string( $prepared ) || $prepared === '' ) {
                return -1;
            }

            $result = $wpdb->query( $prepared );
            if ( $result === false ) {
                return -1;
            }
            $written += count( $chunk );
        }

        return $written;
    }
}
