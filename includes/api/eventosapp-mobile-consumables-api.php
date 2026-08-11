<?php
/**
 * EventosApp – API móvil de Consumo de Consumibles (Android 2.11.0+).
 *
 * La administración permanece en EventosApp. Android recibe únicamente la
 * operación autorizada de staff, historial propio y datos necesarios para
 * continuidad offline. Las escrituras online reutilizan el motor oficial y
 * las escrituras offline diferidas preservan la fecha real de operación.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'EVENTOSAPP_MOBILE_CONSUMABLES_API_VERSION' ) ) {
    define( 'EVENTOSAPP_MOBILE_CONSUMABLES_API_VERSION', '1.0.0' );
}

if ( ! function_exists( 'eventosapp_mobile_consumables_user_can_event' ) ) {
    function eventosapp_mobile_consumables_user_can_event( $event_id, $user_id = 0 ) {
        $event_id = absint( $event_id );
        $user_id = absint( $user_id ?: get_current_user_id() );
        if ( ! $event_id || ! $user_id || get_post_type( $event_id ) !== 'eventosapp_event' ) return false;
        if ( ! function_exists( 'eventosapp_consumables_is_enabled' ) || ! eventosapp_consumables_is_enabled( $event_id ) ) return false;
        if ( function_exists( 'eventosapp_consumables_user_can_feature' ) ) {
            return (bool) eventosapp_consumables_user_can_feature( $event_id, $user_id, 'consumables_staff' );
        }
        return function_exists( 'eventosapp_mobile_app_user_can_feature_in_event' )
            ? (bool) eventosapp_mobile_app_user_can_feature_in_event( $event_id, $user_id, 'consumables_staff' )
            : false;
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_event_ids' ) ) {
    function eventosapp_mobile_consumables_event_ids( $user_id = 0 ) {
        $user_id = absint( $user_id ?: get_current_user_id() );
        if ( ! $user_id || ! function_exists( 'eventosapp_mobile_app_all_event_ids' ) ) return [];
        $ids = [];
        foreach ( eventosapp_mobile_app_all_event_ids() as $event_id ) {
            if ( eventosapp_mobile_consumables_user_can_event( $event_id, $user_id ) ) $ids[] = absint( $event_id );
        }
        return array_values( array_unique( array_filter( $ids ) ) );
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_items_payload' ) ) {
    function eventosapp_mobile_consumables_items_payload( $event_id ) {
        $out = [];
        if ( ! function_exists( 'eventosapp_consumables_get_event_items' ) ) return $out;
        foreach ( (array) eventosapp_consumables_get_event_items( absint( $event_id ) ) as $item ) {
            $id = sanitize_key( $item['id'] ?? '' );
            $name = sanitize_text_field( $item['name'] ?? '' );
            if ( $id && $name ) $out[] = [ 'id' => $id, 'name' => $name ];
        }
        return $out;
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_attendee_payload' ) ) {
    function eventosapp_mobile_consumables_attendee_payload( $ticket_id, $event_id ) {
        if ( function_exists( 'eventosapp_mobile_advanced_attendee_payload' ) ) {
            $payload = eventosapp_mobile_advanced_attendee_payload( $ticket_id, $event_id );
            if ( is_array( $payload ) && ! empty( $payload['attendee'] ) ) return (array) $payload['attendee'];
        }
        if ( function_exists( 'eventosapp_mobile_offline_ticket_payload' ) ) {
            $payload = eventosapp_mobile_offline_ticket_payload( $ticket_id, $event_id );
            if ( is_array( $payload ) && ! empty( $payload['attendee'] ) ) return (array) $payload['attendee'];
        }

        $first = sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true ) );
        $last = sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true ) );
        $identification = get_post_meta( $ticket_id, '_eventosapp_asistente_cc', true );
        if ( $identification === '' ) $identification = get_post_meta( $ticket_id, '_eventosapp_asistente_cedula', true );
        $phone = get_post_meta( $ticket_id, '_eventosapp_asistente_tel', true );
        if ( $phone === '' ) $phone = get_post_meta( $ticket_id, '_eventosapp_asistente_telefono', true );

        return [
            'ticket_id' => absint( $ticket_id ),
            'event_id' => absint( $event_id ),
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => trim( $first . ' ' . $last ),
            'company' => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_empresa', true ) ),
            'designation' => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_cargo', true ) ),
            'identification' => sanitize_text_field( $identification ),
            'email' => sanitize_email( get_post_meta( $ticket_id, '_eventosapp_asistente_email', true ) ),
            'phone' => sanitize_text_field( $phone ),
            'localidad' => sanitize_text_field( get_post_meta( $ticket_id, '_eventosapp_asistente_localidad', true ) ),
            'modalidad' => 'presencial',
            'modalidad_label' => 'Presencial',
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_inventory_period_snapshot' ) ) {
    function eventosapp_mobile_consumables_inventory_period_snapshot( $ticket_id, $event_id, $rule, $period_key, $period_date = '' ) {
        $ticket_id = absint( $ticket_id );
        $event_id = absint( $event_id );
        $period_key = sanitize_text_field( $period_key );
        $period_date = sanitize_text_field( $period_date );
        $behavior = sanitize_key( $rule['behavior'] ?? 'shared' );
        $snapshot = [
            'enabled' => true,
            'assigned' => true,
            'event_id' => $event_id,
            'ticket_id' => $ticket_id,
            'rule_id' => sanitize_key( $rule['id'] ?? '' ),
            'rule_name' => sanitize_text_field( $rule['name'] ?? 'Inventario' ),
            'behavior' => $behavior,
            'period_key' => $period_key,
            'period_date' => $period_date,
            'period_label' => $behavior === 'per_day' && $period_date
                ? 'Saldo del ' . date_i18n( 'd/m/Y', strtotime( $period_date ) )
                : 'Saldo general para todo el evento',
            'items' => [],
        ];

        foreach ( (array) ( $rule['items'] ?? [] ) as $item ) {
            $balance = function_exists( 'eventosapp_consumables_sync_balance_row' )
                ? eventosapp_consumables_sync_balance_row( $event_id, $ticket_id, $rule, $item, $period_key )
                : [];
            $allocated = absint( $item['quantity'] ?? 0 );
            $consumed = is_array( $balance ) ? absint( $balance['consumed'] ?? 0 ) : 0;
            $remaining = max( 0, $allocated - $consumed );
            $snapshot['items'][] = [
                'id' => sanitize_key( $item['id'] ?? '' ),
                'name' => sanitize_text_field( $item['name'] ?? '' ),
                'allocated' => $allocated,
                'consumed' => $consumed,
                'remaining' => $remaining,
                'exhausted' => $remaining <= 0,
            ];
        }
        return $snapshot;
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_inventory_payload' ) ) {
    function eventosapp_mobile_consumables_inventory_payload( $ticket_id, $event_id ) {
        $snapshot = function_exists( 'eventosapp_consumables_get_ticket_inventory_snapshot' )
            ? eventosapp_consumables_get_ticket_inventory_snapshot( $ticket_id, $event_id )
            : [];
        if ( ! is_array( $snapshot ) ) $snapshot = [];
        $snapshot['offline_periods'] = [];

        if ( empty( $snapshot['enabled'] ) || ! function_exists( 'eventosapp_consumables_get_ticket_rule' ) ) {
            return $snapshot;
        }
        $rule = eventosapp_consumables_get_ticket_rule( $ticket_id, $event_id );
        if ( empty( $rule ) || sanitize_key( $rule['behavior'] ?? 'shared' ) !== 'per_day' ) {
            return $snapshot;
        }
        $days = function_exists( 'eventosapp_consumables_get_event_days' )
            ? eventosapp_consumables_get_event_days( $event_id )
            : [];
        foreach ( (array) $days as $day ) {
            $day = sanitize_text_field( $day );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) continue;
            $snapshot['offline_periods'][ $day ] = eventosapp_mobile_consumables_inventory_period_snapshot(
                $ticket_id,
                $event_id,
                $rule,
                $day,
                $day
            );
        }
        return $snapshot;
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_ticket_payload' ) ) {
    function eventosapp_mobile_consumables_ticket_payload( $ticket_id, $event_id ) {
        $ticket_id = absint( $ticket_id );
        $event_id = absint( $event_id );
        if ( ! $ticket_id || absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) !== $event_id ) return [];
        $keys = function_exists( 'eventosapp_mobile_offline_ticket_lookup_keys' )
            ? eventosapp_mobile_offline_ticket_lookup_keys( $ticket_id, $event_id )
            : [];
        return [
            'ticket_id' => $ticket_id,
            'ticket_public_id' => sanitize_text_field( get_post_meta( $ticket_id, 'eventosapp_ticketID', true ) ),
            'attendee' => eventosapp_mobile_consumables_attendee_payload( $ticket_id, $event_id ),
            'lookup_keys' => $keys,
            'inventory' => eventosapp_mobile_consumables_inventory_payload( $ticket_id, $event_id ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_transaction_payload' ) ) {
    function eventosapp_mobile_consumables_transaction_payload( $tx, $user_id = 0 ) {
        $user_id = absint( $user_id ?: get_current_user_id() );
        $owner_id = absint( $tx['staff_user_id'] ?? 0 );
        $ticket = is_array( $tx['ticket'] ?? null ) ? $tx['ticket'] : [];
        $items = [];
        foreach ( (array) ( $tx['items'] ?? [] ) as $item ) {
            $items[] = [
                'item_id' => sanitize_key( $item['item_id'] ?? '' ),
                'item_name' => sanitize_text_field( $item['item_name'] ?? '' ),
                'quantity' => absint( $item['quantity'] ?? 0 ),
            ];
        }
        $status = sanitize_key( $tx['status'] ?? 'active' );
        return [
            'batch_id' => sanitize_text_field( $tx['batch_id'] ?? '' ),
            'event_id' => absint( $tx['event_id'] ?? 0 ),
            'ticket_id' => absint( $tx['ticket_id'] ?? 0 ),
            'ticket_public_id' => sanitize_text_field( $ticket['public_id'] ?? '' ),
            'attendee_name' => sanitize_text_field( $ticket['nombre_completo'] ?? 'Asistente' ),
            'identification' => sanitize_text_field( $ticket['cedula'] ?? '' ),
            'localidad' => sanitize_text_field( $ticket['localidad'] ?? '' ),
            'items' => $items,
            'total_quantity' => absint( $tx['total_quantity'] ?? 0 ),
            'status' => $status,
            'status_label' => sanitize_text_field( $tx['status_label'] ?? '' ),
            'created_at' => sanitize_text_field( $tx['created_at'] ?? '' ),
            'request_at' => sanitize_text_field( $tx['request_at'] ?? '' ),
            'can_request_cancellation' => $owner_id === $user_id && $status === 'active',
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_transactions_for_user' ) ) {
    function eventosapp_mobile_consumables_transactions_for_user( $event_id, $user_id = 0, $limit = 300 ) {
        $user_id = absint( $user_id ?: get_current_user_id() );
        $out = [];
        if ( ! function_exists( 'eventosapp_consumables_tx_load_event_transactions' ) ) return $out;
        foreach ( (array) eventosapp_consumables_tx_load_event_transactions( absint( $event_id ) ) as $tx ) {
            if ( absint( $tx['staff_user_id'] ?? 0 ) !== $user_id ) continue;
            $out[] = eventosapp_mobile_consumables_transaction_payload( $tx, $user_id );
            if ( count( $out ) >= max( 1, absint( $limit ) ) ) break;
        }
        return $out;
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_require_event' ) ) {
    function eventosapp_mobile_consumables_require_event( $event_id, $user_id = 0 ) {
        $event_id = absint( $event_id );
        $user_id = absint( $user_id ?: get_current_user_id() );
        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return new WP_Error( 'invalid_event', 'El evento no existe.', [ 'status' => 404 ] );
        }
        if ( ! eventosapp_mobile_consumables_user_can_event( $event_id, $user_id ) ) {
            return new WP_Error( 'forbidden_event', 'No tienes permiso para consumir consumibles en este evento.', [ 'status' => 403 ] );
        }
        return true;
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_selection' ) ) {
    function eventosapp_mobile_consumables_selection( $raw ) {
        if ( function_exists( 'eventosapp_consumables_sanitize_selection' ) ) {
            return eventosapp_consumables_sanitize_selection( is_array( $raw ) ? $raw : [] );
        }
        $out = [];
        foreach ( (array) $raw as $id => $qty ) {
            $id = sanitize_key( $id );
            $qty = absint( $qty );
            if ( $id && $qty ) $out[ $id ] = $qty;
        }
        return $out;
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_request_id' ) ) {
    function eventosapp_mobile_consumables_request_id( $value = '' ) {
        $value = preg_replace( '/[^A-Za-z0-9._-]/', '', sanitize_text_field( (string) $value ) );
        return $value ? substr( $value, 0, 64 ) : wp_generate_uuid4();
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_operation_time' ) ) {
    function eventosapp_mobile_consumables_operation_time( $event_id, $created_at ) {
        $event_id = absint( $event_id );
        $created_at = sanitize_text_field( (string) $created_at );
        if ( ! $created_at ) {
            return new WP_Error( 'offline_time_missing', 'La operación offline no contiene su fecha y hora original.' );
        }
        try {
            $instant = new DateTimeImmutable( $created_at );
        } catch ( Throwable $e ) {
            return new WP_Error( 'offline_time_invalid', 'La fecha y hora de la operación offline no es válida.' );
        }

        $tz_name = sanitize_text_field( get_post_meta( $event_id, '_eventosapp_zona_horaria', true ) );
        try {
            $event_tz = new DateTimeZone( $tz_name ?: ( wp_timezone_string() ?: 'UTC' ) );
        } catch ( Throwable $e ) {
            $event_tz = wp_timezone();
        }
        $event_date = $instant->setTimezone( $event_tz )->format( 'Y-m-d' );
        $days = function_exists( 'eventosapp_consumables_get_event_days' )
            ? eventosapp_consumables_get_event_days( $event_id )
            : [];
        if ( empty( $days ) || ! in_array( $event_date, $days, true ) ) {
            return new WP_Error( 'offline_outside_event_day', 'La operación offline no corresponde a una fecha configurada del evento.' );
        }
        $today = function_exists( 'eventosapp_consumables_current_event_date' )
            ? eventosapp_consumables_current_event_date( $event_id )
            : wp_date( 'Y-m-d' );
        if ( $event_date > $today ) {
            return new WP_Error( 'offline_future_operation', 'No se puede sincronizar un consumo fechado en un día futuro del evento.' );
        }

        return [
            'event_date' => $event_date,
            'mysql' => $instant->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' ),
            'iso' => $instant->format( DATE_ATOM ),
        ];
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_consume_items_historical' ) ) {
    function eventosapp_mobile_consumables_consume_items_historical( $event_id, $ticket_id, $selection, $staff_user_id, $request_uuid, $qr_type, $operation_time ) {
        global $wpdb;

        $event_id = absint( $event_id );
        $ticket_id = absint( $ticket_id );
        $staff_user_id = absint( $staff_user_id );
        $selection = eventosapp_mobile_consumables_selection( $selection );
        $request_uuid = eventosapp_mobile_consumables_request_id( $request_uuid );
        $qr_type = sanitize_key( $qr_type );

        if ( ! is_array( $operation_time ) || empty( $operation_time['event_date'] ) || empty( $operation_time['mysql'] ) ) {
            return new WP_Error( 'offline_time_invalid', 'No se pudo determinar el día real del consumo offline.' );
        }
        if ( ! $event_id || ! $ticket_id || empty( $selection ) ) {
            return new WP_Error( 'invalid_data', 'La operación offline no contiene un ticket o selección válida.' );
        }
        if ( ! function_exists( 'eventosapp_consumables_is_enabled' ) || ! eventosapp_consumables_is_enabled( $event_id ) ) {
            return new WP_Error( 'disabled', 'El Control de Consumibles no está activo para este evento.' );
        }
        if ( absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) !== $event_id ) {
            return new WP_Error( 'wrong_event', 'El ticket no corresponde al evento activo.' );
        }
        if ( ! function_exists( 'eventosapp_consumables_get_ticket_rule' ) || ! function_exists( 'eventosapp_consumables_table_names' ) || ! function_exists( 'eventosapp_consumables_batch_line_uuid' ) ) {
            return new WP_Error( 'api_dependency_missing', 'El motor de consumibles no está disponible.' );
        }

        $rule = eventosapp_consumables_get_ticket_rule( $ticket_id, $event_id );
        if ( empty( $rule ) ) {
            return new WP_Error( 'not_assigned', 'El asistente no tiene un inventario de consumibles asignado según la segmentación configurada.' );
        }
        $behavior = sanitize_key( $rule['behavior'] ?? 'shared' );
        $period_key = $behavior === 'per_day' ? sanitize_text_field( $operation_time['event_date'] ) : 'event';
        $period = [
            'key' => $period_key,
            'date' => $behavior === 'per_day' ? sanitize_text_field( $operation_time['event_date'] ) : '',
            'label' => $behavior === 'per_day'
                ? 'Saldo del ' . date_i18n( 'd/m/Y', strtotime( $operation_time['event_date'] ) )
                : 'Saldo general para todo el evento',
        ];

        $rule_items = [];
        foreach ( (array) ( $rule['items'] ?? [] ) as $item ) {
            $item_id = sanitize_key( $item['id'] ?? '' );
            if ( $item_id !== '' ) $rule_items[ $item_id ] = $item;
        }
        $selected_items = [];
        foreach ( $selection as $item_id => $quantity ) {
            if ( ! isset( $rule_items[ $item_id ] ) ) {
                return new WP_Error( 'item_not_assigned', 'Uno de los consumibles seleccionados no está asignado a este asistente.' );
            }
            $selected_items[ $item_id ] = [
                'item' => $rule_items[ $item_id ],
                'quantity' => absint( $quantity ),
                'line_uuid' => eventosapp_consumables_batch_line_uuid( $request_uuid, $item_id ),
            ];
        }

        if ( function_exists( 'eventosapp_consumables_maybe_install_tables' ) && ! eventosapp_consumables_maybe_install_tables() ) {
            return new WP_Error( 'storage_unavailable', 'No se pudo preparar el almacenamiento de consumibles.' );
        }
        $tables = eventosapp_consumables_table_names();

        $existing_lines = [];
        foreach ( $selected_items as $item_id => $line ) {
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT item_id,item_name,quantity,remaining_after FROM {$tables['ledger']} WHERE request_uuid=%s LIMIT 1",
                $line['line_uuid']
            ), ARRAY_A );
            if ( is_array( $existing ) ) $existing_lines[ $item_id ] = $existing;
        }
        if ( count( $existing_lines ) === count( $selected_items ) ) {
            $results = [];
            foreach ( $selected_items as $item_id => $line ) {
                $existing = $existing_lines[ $item_id ];
                $results[] = [
                    'item_id' => $item_id,
                    'item_name' => sanitize_text_field( $existing['item_name'] ?? $line['item']['name'] ?? '' ),
                    'quantity' => absint( $existing['quantity'] ?? $line['quantity'] ),
                    'remaining' => max( 0, intval( $existing['remaining_after'] ?? 0 ) ),
                ];
            }
            return [ 'duplicate' => true, 'items' => $results, 'rule' => $rule, 'period' => $period ];
        }
        if ( ! empty( $existing_lines ) ) {
            return new WP_Error( 'request_conflict', 'Esta operación offline ya fue usada con una selección diferente.' );
        }

        $wpdb->query( 'START TRANSACTION' );
        try {
            $operation_mysql = sanitize_text_field( $operation_time['mysql'] );
            $locked_balances = [];

            foreach ( $selected_items as $item_id => $line ) {
                $allocated = absint( $line['item']['quantity'] ?? 0 );
                $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$tables['balances']}
                        (event_id,ticket_id,config_id,item_id,period_key,allocated,consumed,updated_at)
                     VALUES (%d,%d,%s,%s,%s,%d,0,%s)
                     ON DUPLICATE KEY UPDATE
                        config_id=VALUES(config_id), allocated=VALUES(allocated), updated_at=VALUES(updated_at)",
                    $event_id,
                    $ticket_id,
                    sanitize_key( $rule['id'] ?? '' ),
                    $item_id,
                    $period_key,
                    $allocated,
                    $operation_mysql
                ) );
                $balance = $wpdb->get_row( $wpdb->prepare(
                    "SELECT allocated,consumed FROM {$tables['balances']}
                     WHERE event_id=%d AND ticket_id=%d AND item_id=%s AND period_key=%s
                     LIMIT 1 FOR UPDATE",
                    $event_id,
                    $ticket_id,
                    $item_id,
                    $period_key
                ), ARRAY_A );
                if ( ! is_array( $balance ) ) throw new RuntimeException( 'balance_not_found' );
                $remaining = max( 0, absint( $balance['allocated'] ?? 0 ) - absint( $balance['consumed'] ?? 0 ) );
                if ( $line['quantity'] > $remaining ) {
                    $wpdb->query( 'ROLLBACK' );
                    $name = sanitize_text_field( $line['item']['name'] ?? 'este consumible' );
                    return new WP_Error(
                        'insufficient_balance',
                        'No se sincronizó ningún descuento. Para ' . $name . ' se solicitaron ' . absint( $line['quantity'] ) . ' y el saldo de ese día solo tiene ' . $remaining . ' disponible(s).'
                    );
                }
                $locked_balances[ $item_id ] = $balance;
            }

            $results = [];
            foreach ( $selected_items as $item_id => $line ) {
                $quantity = absint( $line['quantity'] );
                $updated = $wpdb->query( $wpdb->prepare(
                    "UPDATE {$tables['balances']}
                     SET consumed=consumed+%d, updated_at=%s
                     WHERE event_id=%d AND ticket_id=%d AND item_id=%s AND period_key=%s
                       AND consumed + %d <= allocated",
                    $quantity,
                    $operation_mysql,
                    $event_id,
                    $ticket_id,
                    $item_id,
                    $period_key,
                    $quantity
                ) );
                if ( intval( $updated ) !== 1 ) throw new RuntimeException( 'balance_update_failed' );

                $allocated = absint( $locked_balances[ $item_id ]['allocated'] ?? 0 );
                $consumed_after = absint( $locked_balances[ $item_id ]['consumed'] ?? 0 ) + $quantity;
                $remaining = max( 0, $allocated - $consumed_after );
                $item_name = sanitize_text_field( $line['item']['name'] ?? 'Consumible' );
                $inserted = $wpdb->insert( $tables['ledger'], [
                    'request_uuid' => $line['line_uuid'],
                    'event_id' => $event_id,
                    'ticket_id' => $ticket_id,
                    'config_id' => sanitize_key( $rule['id'] ?? '' ),
                    'item_id' => $item_id,
                    'item_name' => $item_name,
                    'period_key' => $period_key,
                    'quantity' => $quantity,
                    'action' => 'consume',
                    'staff_user_id' => $staff_user_id,
                    'qr_type' => $qr_type,
                    'source' => 'mobile_offline',
                    'remaining_after' => $remaining,
                    'note' => 'batch_request=' . $request_uuid . ';offline_date=' . sanitize_text_field( $operation_time['event_date'] ),
                    'created_at' => $operation_mysql,
                ], [ '%s','%d','%d','%s','%s','%s','%s','%d','%s','%d','%s','%s','%d','%s','%s' ] );
                if ( ! $inserted ) throw new RuntimeException( 'ledger_insert_failed' );
                $results[] = [
                    'item_id' => $item_id,
                    'item_name' => $item_name,
                    'quantity' => $quantity,
                    'remaining' => $remaining,
                ];
            }

            $wpdb->query( 'COMMIT' );
            return [ 'duplicate' => false, 'items' => $results, 'rule' => $rule, 'period' => $period ];
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'consume_error', 'No se pudo sincronizar el consumo offline. Ningún saldo fue modificado.' );
        }
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_events' ) ) {
    function eventosapp_mobile_consumables_events( WP_REST_Request $request ) {
        $events = [];
        foreach ( eventosapp_mobile_consumables_event_ids() as $event_id ) {
            $event = function_exists( 'eventosapp_mobile_app_event_payload' )
                ? eventosapp_mobile_app_event_payload( $event_id, 'consumables_staff' )
                : [ 'id' => $event_id, 'title' => get_the_title( $event_id ) ];
            $event['items'] = eventosapp_mobile_consumables_items_payload( $event_id );
            $events[] = $event;
        }
        return rest_ensure_response( [
            'api_version' => EVENTOSAPP_MOBILE_CONSUMABLES_API_VERSION,
            'events' => $events,
        ] );
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_consume' ) ) {
    function eventosapp_mobile_consumables_consume( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        $user_id = get_current_user_id();
        $allowed = eventosapp_mobile_consumables_require_event( $event_id, $user_id );
        if ( is_wp_error( $allowed ) ) return $allowed;

        $selection = eventosapp_mobile_consumables_selection( $request->get_param( 'items' ) );
        if ( ! $selection ) return new WP_Error( 'missing_items', 'Selecciona al menos un consumible y define su cantidad.', [ 'status' => 400 ] );
        $scanned = trim( sanitize_text_field( (string) $request->get_param( 'scanned' ) ) );
        if ( ! $scanned ) return new WP_Error( 'missing_qr', 'No se recibió un código QR válido.', [ 'status' => 400 ] );
        if ( ! function_exists( 'eventosapp_consumables_find_ticket_from_qr' ) || ! function_exists( 'eventosapp_consumables_consume_items' ) ) {
            return new WP_Error( 'api_dependency_missing', 'El motor de consumibles no está disponible.', [ 'status' => 500 ] );
        }

        $lookup = eventosapp_consumables_find_ticket_from_qr( $scanned, $event_id );
        if ( is_wp_error( $lookup ) ) return new WP_Error( $lookup->get_error_code(), $lookup->get_error_message(), [ 'status' => 400 ] );
        $ticket_id = absint( $lookup['ticket_id'] ?? 0 );
        $batch_id = eventosapp_mobile_consumables_request_id( $request->get_param( 'client_id' ) );
        $result = eventosapp_consumables_consume_items(
            $event_id,
            $ticket_id,
            $selection,
            $user_id,
            $batch_id,
            sanitize_key( $lookup['type'] ?? 'qr' )
        );
        if ( is_wp_error( $result ) ) return new WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 409 ] );

        return rest_ensure_response( [
            'event_id' => $event_id,
            'event_name' => get_the_title( $event_id ),
            'ticket_id' => $ticket_id,
            'attendee' => eventosapp_mobile_consumables_attendee_payload( $ticket_id, $event_id ),
            'batch_id' => $batch_id,
            'consumptions' => array_values( (array) ( $result['items'] ?? [] ) ),
            'inventory' => eventosapp_mobile_consumables_inventory_payload( $ticket_id, $event_id ),
            'qr_type' => sanitize_key( $lookup['type'] ?? 'qr' ),
            'qr_type_label' => sanitize_text_field( $lookup['type_label'] ?? 'QR' ),
            'duplicate' => ! empty( $result['duplicate'] ),
            'message' => ! empty( $result['duplicate'] ) ? 'La transacción ya estaba registrada.' : 'Consumo registrado correctamente.',
        ] );
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_transactions' ) ) {
    function eventosapp_mobile_consumables_transactions( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        $allowed = eventosapp_mobile_consumables_require_event( $event_id );
        if ( is_wp_error( $allowed ) ) return $allowed;
        return rest_ensure_response( [
            'event_id' => $event_id,
            'transactions' => eventosapp_mobile_consumables_transactions_for_user( $event_id ),
        ] );
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_cancel_request' ) ) {
    function eventosapp_mobile_consumables_cancel_request( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        $allowed = eventosapp_mobile_consumables_require_event( $event_id );
        if ( is_wp_error( $allowed ) ) return $allowed;
        $batch_id = sanitize_text_field( (string) $request->get_param( 'batch_id' ) );
        if ( ! function_exists( 'eventosapp_consumables_tx_request_cancel' ) ) {
            return new WP_Error( 'api_dependency_missing', 'La auditoría de consumibles no está disponible.', [ 'status' => 500 ] );
        }
        $result = eventosapp_consumables_tx_request_cancel( $event_id, $batch_id, get_current_user_id() );
        if ( is_wp_error( $result ) ) return new WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 409 ] );
        return rest_ensure_response( [
            'accepted' => true,
            'already' => ! empty( $result['duplicate'] ),
            'batch_id' => $batch_id,
            'message' => ! empty( $result['duplicate'] )
                ? 'La cancelación ya había sido solicitada.'
                : 'Solicitud de cancelación enviada al administrador.',
        ] );
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_offline_sync' ) ) {
    function eventosapp_mobile_consumables_offline_sync( WP_REST_Request $request ) {
        $event_id = absint( $request['event_id'] );
        $user_id = get_current_user_id();
        $allowed = eventosapp_mobile_consumables_require_event( $event_id, $user_id );
        if ( is_wp_error( $allowed ) ) return $allowed;

        $raw_items = $request->get_param( 'items' );
        if ( ! is_array( $raw_items ) ) $raw_items = [];
        $consumes = [];
        $cancellations = [];
        foreach ( $raw_items as $raw ) {
            if ( ! is_array( $raw ) ) continue;
            if ( sanitize_key( $raw['operation'] ?? 'consume' ) === 'cancel_request' ) $cancellations[] = $raw;
            else $consumes[] = $raw;
        }
        $ordered = array_merge( $consumes, $cancellations );
        $results = [];

        foreach ( $ordered as $raw ) {
            $operation = sanitize_key( $raw['operation'] ?? 'consume' );
            $client_id = eventosapp_mobile_consumables_request_id( $raw['client_id'] ?? '' );

            if ( $operation === 'cancel_request' ) {
                $batch_id = sanitize_text_field( $raw['target_batch'] ?? '' );
                $result = function_exists( 'eventosapp_consumables_tx_request_cancel' )
                    ? eventosapp_consumables_tx_request_cancel( $event_id, $batch_id, $user_id )
                    : new WP_Error( 'missing_dependency', 'La auditoría no está disponible.' );
                $results[] = is_wp_error( $result )
                    ? [
                        'client_id' => $client_id,
                        'operation' => 'cancel_request',
                        'target_batch' => $batch_id,
                        'accepted' => false,
                        'already' => false,
                        'message' => $result->get_error_message(),
                    ]
                    : [
                        'client_id' => $client_id,
                        'operation' => 'cancel_request',
                        'target_batch' => $batch_id,
                        'accepted' => true,
                        'already' => ! empty( $result['duplicate'] ),
                        'message' => ! empty( $result['duplicate'] )
                            ? 'La cancelación ya había sido solicitada.'
                            : 'Solicitud de cancelación offline sincronizada.',
                    ];
                continue;
            }

            $ticket_id = absint( $raw['ticket_id'] ?? 0 );
            $selection = eventosapp_mobile_consumables_selection( $raw['items'] ?? [] );
            if ( ! $ticket_id || ! $selection || absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) ) !== $event_id ) {
                $results[] = [
                    'client_id' => $client_id,
                    'operation' => 'consume',
                    'accepted' => false,
                    'already' => false,
                    'message' => 'Operación offline inválida.',
                ];
                continue;
            }
            $operation_time = eventosapp_mobile_consumables_operation_time( $event_id, $raw['created_at'] ?? '' );
            if ( is_wp_error( $operation_time ) ) {
                $results[] = [
                    'client_id' => $client_id,
                    'operation' => 'consume',
                    'accepted' => false,
                    'already' => false,
                    'message' => $operation_time->get_error_message(),
                ];
                continue;
            }

            $result = eventosapp_mobile_consumables_consume_items_historical(
                $event_id,
                $ticket_id,
                $selection,
                $user_id,
                $client_id,
                sanitize_key( $raw['qr_type'] ?? 'offline' ),
                $operation_time
            );
            $results[] = is_wp_error( $result )
                ? [
                    'client_id' => $client_id,
                    'operation' => 'consume',
                    'accepted' => false,
                    'already' => false,
                    'message' => $result->get_error_message(),
                ]
                : [
                    'client_id' => $client_id,
                    'operation' => 'consume',
                    'batch_id' => $client_id,
                    'accepted' => true,
                    'already' => ! empty( $result['duplicate'] ),
                    'message' => ! empty( $result['duplicate'] )
                        ? 'El consumo offline ya existía en EventosApp.'
                        : 'Consumo offline sincronizado en su fecha original.',
                ];
        }

        return rest_ensure_response( [ 'results' => array_values( $results ) ] );
    }
}

if ( ! function_exists( 'eventosapp_mobile_consumables_offline_config_payload' ) ) {
    function eventosapp_mobile_consumables_offline_config_payload( $event_id, $user_id = 0 ) {
        return [
            'api_version' => EVENTOSAPP_MOBILE_CONSUMABLES_API_VERSION,
            'items' => eventosapp_mobile_consumables_items_payload( $event_id ),
            'transactions' => eventosapp_mobile_consumables_transactions_for_user( $event_id, $user_id ),
        ];
    }
}

add_action( 'rest_api_init', function () {
    $permission = function_exists( 'eventosapp_mobile_app_permission' ) ? 'eventosapp_mobile_app_permission' : '__return_false';

    register_rest_route( 'eventosapp-kiosk/v1', '/mobile/consumables/events', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'eventosapp_mobile_consumables_events',
        'permission_callback' => $permission,
    ] );

    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\d+)/consumables/consume', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'eventosapp_mobile_consumables_consume',
        'permission_callback' => $permission,
        'args' => [ 'event_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
    ] );

    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\d+)/consumables/transactions', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'eventosapp_mobile_consumables_transactions',
        'permission_callback' => $permission,
        'args' => [ 'event_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
    ] );

    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\d+)/consumables/cancel-request', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'eventosapp_mobile_consumables_cancel_request',
        'permission_callback' => $permission,
        'args' => [ 'event_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
    ] );

    register_rest_route( 'eventosapp-kiosk/v1', '/events/(?P<event_id>\d+)/consumables/offline-sync', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'eventosapp_mobile_consumables_offline_sync',
        'permission_callback' => $permission,
        'args' => [ 'event_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
    ] );
} );
