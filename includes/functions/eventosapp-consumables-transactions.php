<?php
/**
 * EventosApp — Consumibles: transacciones, auditoría y solicitudes de cancelación.
 *
 * Extiende el módulo existente sin reemplazar su lógica de inventario ni de lectura QR.
 * - Agrega pestaña de transacciones para organizadores/administradores.
 * - Agrega pestaña "Mis transacciones" para staff/logístico.
 * - Permite solicitar cancelaciones y anular transacciones restaurando saldos.
 * - Conserva el ledger completo: nunca borra movimientos.
 * - Agrega exportación CSV y resumen general por consumible.
 * - Agrega historial de movimientos en la landing pública del ticket presencial.
 */

if ( ! defined('ABSPATH') ) exit;

/* -------------------------------------------------------------------------
 * Helpers de auditoría
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_tx_parse_note') ) {
    function eventosapp_consumables_tx_parse_note($note) {
        $out = [];
        foreach ( explode(';', (string) $note) as $part ) {
            if ( strpos($part, '=') === false ) continue;
            list($key, $value) = array_map('trim', explode('=', $part, 2));
            $key = sanitize_key($key);
            if ( $key === '' ) continue;
            $out[$key] = sanitize_text_field($value);
        }
        return $out;
    }
}

if ( ! function_exists('eventosapp_consumables_tx_sanitize_batch_id') ) {
    function eventosapp_consumables_tx_sanitize_batch_id($batch_id) {
        $batch_id = sanitize_text_field(wp_unslash((string) $batch_id));
        return preg_replace('/[^A-Za-z0-9._-]/', '', $batch_id);
    }
}

if ( ! function_exists('eventosapp_consumables_tx_batch_from_row') ) {
    function eventosapp_consumables_tx_batch_from_row($row) {
        if ( ! is_array($row) ) return '';
        $action = sanitize_key($row['action'] ?? '');
        $meta = eventosapp_consumables_tx_parse_note($row['note'] ?? '');

        if ( $action === 'consume' && ! empty($meta['batch_request']) ) {
            return eventosapp_consumables_tx_sanitize_batch_id($meta['batch_request']);
        }
        if ( in_array($action, [ 'cancel_request', 'reverse' ], true) && ! empty($meta['target_batch']) ) {
            return eventosapp_consumables_tx_sanitize_batch_id($meta['target_batch']);
        }

        return $action === 'consume' && ! empty($row['id']) ? 'legacy_' . absint($row['id']) : '';
    }
}

if ( ! function_exists('eventosapp_consumables_tx_first_meta') ) {
    function eventosapp_consumables_tx_first_meta($ticket_id, $keys, $contains = []) {
        $ticket_id = absint($ticket_id);
        if ( ! $ticket_id ) return '';

        foreach ( (array) $keys as $key ) {
            $value = get_post_meta($ticket_id, $key, true);
            if ( is_scalar($value) && trim((string) $value) !== '' ) {
                return sanitize_text_field((string) $value);
            }
        }

        if ( $contains ) {
            $all = get_post_meta($ticket_id);
            // Respeta la prioridad de los términos: por ejemplo, intenta primero
            // cédula/identificación y deja "documento" como último recurso.
            foreach ( (array) $contains as $needle ) {
                $needle_l = strtolower(remove_accents((string) $needle));
                foreach ( (array) $all as $meta_key => $values ) {
                    $meta_key_l = strtolower(remove_accents((string) $meta_key));
                    if ( strpos($meta_key_l, $needle_l) === false ) continue;
                    $value = is_array($values) ? reset($values) : $values;
                    $decoded = maybe_unserialize($value);
                    if ( is_scalar($decoded) && trim((string) $decoded) !== '' ) {
                        return sanitize_text_field((string) $decoded);
                    }
                }
            }
        }

        return '';
    }
}

if ( ! function_exists('eventosapp_consumables_tx_ticket_details') ) {
    function eventosapp_consumables_tx_ticket_details($ticket_id) {
        static $cache = [];
        $ticket_id = absint($ticket_id);
        if ( ! $ticket_id ) return [];
        if ( isset($cache[$ticket_id]) ) return $cache[$ticket_id];

        $nombre = eventosapp_consumables_tx_first_meta($ticket_id, [
            '_eventosapp_asistente_nombre',
            '_eventosapp_nombre',
            'eventosapp_nombre',
        ]);
        $apellido = eventosapp_consumables_tx_first_meta($ticket_id, [
            '_eventosapp_asistente_apellido',
            '_eventosapp_apellido',
            'eventosapp_apellido',
        ]);
        $cedula = eventosapp_consumables_tx_first_meta($ticket_id, [
            '_eventosapp_asistente_cedula',
            '_eventosapp_cedula',
            'eventosapp_cedula',
            '_eventosapp_asistente_identificacion',
            '_eventosapp_identificacion',
            '_eventosapp_asistente_documento',
            '_eventosapp_documento',
        ], [ 'cedula', 'cédula', 'identificacion', 'identificación', 'documento' ]);
        $localidad = eventosapp_consumables_tx_first_meta($ticket_id, [
            '_eventosapp_asistente_localidad',
            '_eventosapp_localidad',
            'eventosapp_localidad',
        ], [ 'localidad' ]);

        $cache[$ticket_id] = [
            'ticket_id'       => $ticket_id,
            'public_id'       => sanitize_text_field((string) get_post_meta($ticket_id, 'eventosapp_ticketID', true)),
            'nombre'          => $nombre,
            'apellido'        => $apellido,
            'nombre_completo' => trim($nombre . ' ' . $apellido) ?: 'Asistente',
            'cedula'          => $cedula,
            'localidad'       => $localidad,
        ];
        return $cache[$ticket_id];
    }
}

if ( ! function_exists('eventosapp_consumables_tx_config_map') ) {
    function eventosapp_consumables_tx_config_map($event_id) {
        static $cache = [];
        $event_id = absint($event_id);
        if ( isset($cache[$event_id]) ) return $cache[$event_id];

        $map = [];
        $config = eventosapp_consumables_get_config($event_id);
        foreach ( (array) ($config['rules'] ?? []) as $rule ) {
            $id = sanitize_key($rule['id'] ?? '');
            if ( $id === '' ) continue;
            $map[$id] = sanitize_text_field($rule['name'] ?? 'Configuración');
        }
        $cache[$event_id] = $map;
        return $map;
    }
}

if ( ! function_exists('eventosapp_consumables_tx_matching_config_names') ) {
    function eventosapp_consumables_tx_matching_config_names($event_id, $ticket_id) {
        static $cache = [];
        $event_id = absint($event_id);
        $ticket_id = absint($ticket_id);
        $cache_key = $event_id . ':' . $ticket_id;
        if ( isset($cache[$cache_key]) ) return $cache[$cache_key];

        $names = [];
        $config = eventosapp_consumables_get_config($event_id);
        foreach ( (array) ($config['rules'] ?? []) as $rule ) {
            if ( eventosapp_consumables_rule_matches_ticket($rule, $ticket_id) ) {
                $name = sanitize_text_field($rule['name'] ?? 'Configuración');
                if ( $name !== '' ) $names[] = $name;
            }
        }
        $cache[$cache_key] = array_values(array_unique($names));
        return $cache[$cache_key];
    }
}

if ( ! function_exists('eventosapp_consumables_tx_user_label') ) {
    function eventosapp_consumables_tx_user_label($user_id) {
        static $cache = [];
        $user_id = absint($user_id);
        if ( ! $user_id ) return 'Sistema';
        if ( isset($cache[$user_id]) ) return $cache[$user_id];

        $user = get_userdata($user_id);
        if ( ! $user ) return $cache[$user_id] = 'Usuario #' . $user_id;
        $label = trim((string) $user->display_name);
        if ( $label === '' ) $label = (string) $user->user_login;
        return $cache[$user_id] = sanitize_text_field($label);
    }
}

if ( ! function_exists('eventosapp_consumables_tx_status_label') ) {
    function eventosapp_consumables_tx_status_label($status) {
        $labels = [
            'active'            => 'Activa',
            'cancel_requested'  => 'Cancelación solicitada',
            'reversed'          => 'Anulada / saldo restablecido',
            'partial_reversal'  => 'Anulación parcial',
        ];
        return $labels[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
    }
}

/* -------------------------------------------------------------------------
 * Lectura y agrupación del ledger
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_tx_load_event_transactions') ) {
    function eventosapp_consumables_tx_load_event_transactions($event_id) {
        global $wpdb;
        $event_id = absint($event_id);
        if ( ! $event_id || ! eventosapp_consumables_maybe_install_tables() ) return [];

        $tables = eventosapp_consumables_table_names();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tables['ledger']}
             WHERE event_id=%d AND action IN ('consume','cancel_request','reverse')
             ORDER BY id ASC",
            $event_id
        ), ARRAY_A);

        $transactions = [];
        $requests = [];
        $reversals = [];

        foreach ( (array) $rows as $row ) {
            $action = sanitize_key($row['action'] ?? '');
            $batch_id = eventosapp_consumables_tx_batch_from_row($row);
            if ( $batch_id === '' ) continue;

            if ( $action === 'consume' ) {
                if ( ! isset($transactions[$batch_id]) ) {
                    $transactions[$batch_id] = [
                        'batch_id'      => $batch_id,
                        'event_id'      => $event_id,
                        'ticket_id'     => absint($row['ticket_id'] ?? 0),
                        'staff_user_id' => absint($row['staff_user_id'] ?? 0),
                        'created_at'    => sanitize_text_field($row['created_at'] ?? ''),
                        'max_id'        => absint($row['id'] ?? 0),
                        'lines'         => [],
                    ];
                }
                $transactions[$batch_id]['lines'][] = $row;
                $transactions[$batch_id]['max_id'] = max($transactions[$batch_id]['max_id'], absint($row['id'] ?? 0));
            } elseif ( $action === 'cancel_request' ) {
                $requests[$batch_id][] = $row;
            } elseif ( $action === 'reverse' ) {
                $reversals[$batch_id][] = $row;
            }
        }

        // Precarga metadatos de tickets y usuarios para que la pantalla siga siendo
        // eficiente cuando el evento acumula muchas transacciones.
        $ticket_ids = [];
        $user_ids = [];
        foreach ( $transactions as $tx ) {
            if ( ! empty($tx['ticket_id']) ) $ticket_ids[] = absint($tx['ticket_id']);
            if ( ! empty($tx['staff_user_id']) ) $user_ids[] = absint($tx['staff_user_id']);
        }
        foreach ( $requests as $request_rows ) {
            foreach ( (array) $request_rows as $request_row ) {
                if ( ! empty($request_row['staff_user_id']) ) $user_ids[] = absint($request_row['staff_user_id']);
            }
        }
        $ticket_ids = array_values(array_unique(array_filter($ticket_ids)));
        $user_ids = array_values(array_unique(array_filter($user_ids)));
        if ( $ticket_ids ) update_meta_cache('post', $ticket_ids);
        if ( $user_ids && function_exists('cache_users') ) cache_users($user_ids);

        $config_map = eventosapp_consumables_tx_config_map($event_id);
        foreach ( $transactions as $batch_id => &$tx ) {
            $tx['requests'] = $requests[$batch_id] ?? [];
            $tx['reversals'] = $reversals[$batch_id] ?? [];
            $tx['ticket'] = eventosapp_consumables_tx_ticket_details($tx['ticket_id']);
            $tx['matching_configs'] = eventosapp_consumables_tx_matching_config_names($event_id, $tx['ticket_id']);
            $tx['staff_label'] = eventosapp_consumables_tx_user_label($tx['staff_user_id']);

            $items = [];
            $applied_configs = [];
            $total = 0;
            foreach ( $tx['lines'] as $line ) {
                $item_id = sanitize_key($line['item_id'] ?? '');
                $qty = absint($line['quantity'] ?? 0);
                if ( ! isset($items[$item_id]) ) {
                    $items[$item_id] = [
                        'item_id'   => $item_id,
                        'item_name' => sanitize_text_field($line['item_name'] ?? 'Consumible'),
                        'quantity'  => 0,
                    ];
                }
                $items[$item_id]['quantity'] += $qty;
                $total += $qty;

                $config_id = sanitize_key($line['config_id'] ?? '');
                if ( $config_id !== '' ) {
                    $applied_configs[$config_id] = $config_map[$config_id] ?? $config_id;
                }
            }

            $reversed_by_item = [];
            $reversed_total = 0;
            foreach ( $tx['reversals'] as $line ) {
                $item_id = sanitize_key($line['item_id'] ?? '');
                $qty = absint($line['quantity'] ?? 0);
                $reversed_by_item[$item_id] = ($reversed_by_item[$item_id] ?? 0) + $qty;
                $reversed_total += $qty;
            }

            $tx['items'] = array_values($items);
            $tx['total_quantity'] = $total;
            $tx['reversed_quantity'] = $reversed_total;
            $tx['applied_configs'] = array_values(array_unique(array_values($applied_configs)));

            if ( $reversed_total >= $total && $total > 0 ) {
                $tx['status'] = 'reversed';
            } elseif ( $reversed_total > 0 ) {
                $tx['status'] = 'partial_reversal';
            } elseif ( ! empty($tx['requests']) ) {
                $tx['status'] = 'cancel_requested';
            } else {
                $tx['status'] = 'active';
            }

            $tx['status_label'] = eventosapp_consumables_tx_status_label($tx['status']);
            $last_request = ! empty($tx['requests']) ? $tx['requests'][count($tx['requests']) - 1] : [];
            $tx['request_user_id'] = absint($last_request['staff_user_id'] ?? 0);
            $tx['request_user_label'] = $tx['request_user_id'] ? eventosapp_consumables_tx_user_label($tx['request_user_id']) : '';
            $tx['request_at'] = sanitize_text_field($last_request['created_at'] ?? '');
            $tx['reversed_by_item'] = $reversed_by_item;
        }
        unset($tx);

        $transactions = array_values($transactions);
        usort($transactions, static function($a, $b) {
            return intval($b['max_id'] ?? 0) <=> intval($a['max_id'] ?? 0);
        });

        return $transactions;
    }
}

if ( ! function_exists('eventosapp_consumables_tx_get_consume_rows_for_batch') ) {
    function eventosapp_consumables_tx_get_consume_rows_for_batch($event_id, $batch_id) {
        global $wpdb;
        $event_id = absint($event_id);
        $batch_id = eventosapp_consumables_tx_sanitize_batch_id($batch_id);
        if ( ! $event_id || $batch_id === '' ) return [];

        $tables = eventosapp_consumables_table_names();
        if ( strpos($batch_id, 'legacy_') === 0 ) {
            $ledger_id = absint(substr($batch_id, 7));
            if ( ! $ledger_id ) return [];
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$tables['ledger']} WHERE event_id=%d AND id=%d AND action='consume'",
                $event_id,
                $ledger_id
            ), ARRAY_A);
        }

        $exact = 'batch_request=' . $batch_id;
        $prefix = $wpdb->esc_like($exact . ';') . '%';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tables['ledger']}
             WHERE event_id=%d AND action='consume' AND (note=%s OR note LIKE %s)
             ORDER BY id ASC",
            $event_id,
            $exact,
            $prefix
        ), ARRAY_A);
    }
}

if ( ! function_exists('eventosapp_consumables_tx_has_movement_for_batch') ) {
    function eventosapp_consumables_tx_has_movement_for_batch($event_id, $batch_id, $action) {
        global $wpdb;
        $event_id = absint($event_id);
        $batch_id = eventosapp_consumables_tx_sanitize_batch_id($batch_id);
        $action = sanitize_key($action);
        if ( ! $event_id || $batch_id === '' || $action === '' ) return false;

        $tables = eventosapp_consumables_table_names();
        $exact = 'target_batch=' . $batch_id;
        $prefix = $wpdb->esc_like($exact . ';') . '%';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$tables['ledger']} WHERE event_id=%d AND action=%s AND (note=%s OR note LIKE %s) LIMIT 1",
            $event_id,
            $action,
            $exact,
            $prefix
        ));
    }
}

/* -------------------------------------------------------------------------
 * Cancelación / reverso auditable
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_tx_request_cancel') ) {
    function eventosapp_consumables_tx_request_cancel($event_id, $batch_id, $user_id) {
        global $wpdb;
        $event_id = absint($event_id);
        $user_id = absint($user_id);
        $batch_id = eventosapp_consumables_tx_sanitize_batch_id($batch_id);
        if ( ! $event_id || ! $user_id || $batch_id === '' ) {
            return new WP_Error('invalid_request', 'La transacción seleccionada no es válida.');
        }

        $rows = eventosapp_consumables_tx_get_consume_rows_for_batch($event_id, $batch_id);
        if ( empty($rows) ) return new WP_Error('not_found', 'No se encontró la transacción.');

        $owner_id = absint($rows[0]['staff_user_id'] ?? 0);
        if ( $owner_id !== $user_id ) {
            return new WP_Error('not_owner', 'Solo puedes solicitar la cancelación de transacciones registradas por tu usuario.');
        }
        if ( eventosapp_consumables_tx_has_movement_for_batch($event_id, $batch_id, 'reverse') ) {
            return new WP_Error('already_reversed', 'Esta transacción ya fue anulada y sus recursos fueron restablecidos.');
        }
        if ( eventosapp_consumables_tx_has_movement_for_batch($event_id, $batch_id, 'cancel_request') ) {
            return [ 'duplicate' => true ];
        }

        $tables = eventosapp_consumables_table_names();
        $first = $rows[0];
        $request_uuid = 'cancel_' . substr(hash('sha256', $event_id . '|' . $batch_id . '|' . $user_id), 0, 57);
        $inserted = $wpdb->insert($tables['ledger'], [
            'request_uuid'    => $request_uuid,
            'event_id'        => $event_id,
            'ticket_id'       => absint($first['ticket_id'] ?? 0),
            'config_id'       => sanitize_key($first['config_id'] ?? ''),
            'item_id'         => '',
            'item_name'       => 'Solicitud de cancelación',
            'period_key'      => sanitize_text_field($first['period_key'] ?? ''),
            'quantity'        => 0,
            'action'          => 'cancel_request',
            'staff_user_id'   => $user_id,
            'qr_type'         => '',
            'source'          => 'staff_request',
            'remaining_after' => 0,
            'note'            => 'target_batch=' . $batch_id,
            'created_at'      => current_time('mysql'),
        ], [ '%s','%d','%d','%s','%s','%s','%s','%d','%s','%d','%s','%s','%d','%s','%s' ]);

        if ( ! $inserted ) {
            if ( eventosapp_consumables_tx_has_movement_for_batch($event_id, $batch_id, 'cancel_request') ) {
                return [ 'duplicate' => true ];
            }
            return new WP_Error('request_failed', 'No se pudo registrar la solicitud de cancelación.');
        }
        return [ 'duplicate' => false ];
    }
}

if ( ! function_exists('eventosapp_consumables_tx_reverse') ) {
    function eventosapp_consumables_tx_reverse($event_id, $batch_id, $actor_user_id) {
        global $wpdb;
        $event_id = absint($event_id);
        $actor_user_id = absint($actor_user_id);
        $batch_id = eventosapp_consumables_tx_sanitize_batch_id($batch_id);
        if ( ! $event_id || ! $actor_user_id || $batch_id === '' ) {
            return new WP_Error('invalid_request', 'La transacción seleccionada no es válida.');
        }

        $rows = eventosapp_consumables_tx_get_consume_rows_for_batch($event_id, $batch_id);
        if ( empty($rows) ) return new WP_Error('not_found', 'No se encontró la transacción.');
        if ( eventosapp_consumables_tx_has_movement_for_batch($event_id, $batch_id, 'reverse') ) {
            return new WP_Error('already_reversed', 'La transacción ya fue anulada anteriormente.');
        }

        $tables = eventosapp_consumables_table_names();
        $wpdb->query('START TRANSACTION');

        try {
            // Serializa cualquier intento de reverso sobre la misma transacción.
            // Se bloquean primero las filas originales del ledger; un segundo administrador
            // deberá esperar al COMMIT del primero y después verá el reverso ya registrado.
            foreach ( $rows as $row ) {
                $original_id = absint($row['id'] ?? 0);
                if ( ! $original_id ) throw new RuntimeException('invalid_original_row');
                $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$tables['ledger']} WHERE id=%d AND event_id=%d AND action='consume' FOR UPDATE",
                    $original_id,
                    $event_id
                ));
            }

            $reverse_exact = 'target_batch=' . $batch_id;
            $reverse_prefix = $wpdb->esc_like($reverse_exact . ';') . '%';
            $existing_reverse = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$tables['ledger']}
                 WHERE event_id=%d AND action='reverse' AND (note=%s OR note LIKE %s)
                 LIMIT 1 FOR UPDATE",
                $event_id,
                $reverse_exact,
                $reverse_prefix
            ));
            if ( $existing_reverse ) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('already_reversed', 'La transacción ya fue anulada anteriormente.');
            }

            $locked = [];
            foreach ( $rows as $row ) {
                $ticket_id = absint($row['ticket_id'] ?? 0);
                $item_id = sanitize_key($row['item_id'] ?? '');
                $period_key = sanitize_text_field($row['period_key'] ?? '');
                $quantity = absint($row['quantity'] ?? 0);

                $balance = $wpdb->get_row($wpdb->prepare(
                    "SELECT allocated,consumed FROM {$tables['balances']}
                     WHERE event_id=%d AND ticket_id=%d AND item_id=%s AND period_key=%s
                     LIMIT 1 FOR UPDATE",
                    $event_id,
                    $ticket_id,
                    $item_id,
                    $period_key
                ), ARRAY_A);

                if ( ! is_array($balance) || absint($balance['consumed'] ?? 0) < $quantity ) {
                    throw new RuntimeException('balance_inconsistent');
                }
                $locked[absint($row['id'])] = $balance;
            }

            $now = current_time('mysql');
            foreach ( $rows as $row ) {
                $row_id = absint($row['id'] ?? 0);
                $ticket_id = absint($row['ticket_id'] ?? 0);
                $item_id = sanitize_key($row['item_id'] ?? '');
                $period_key = sanitize_text_field($row['period_key'] ?? '');
                $quantity = absint($row['quantity'] ?? 0);
                $before = $locked[$row_id];
                $consumed_after = max(0, absint($before['consumed'] ?? 0) - $quantity);
                $allocated = absint($before['allocated'] ?? 0);
                $remaining_after = max(0, $allocated - $consumed_after);

                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$tables['balances']}
                     SET consumed=consumed-%d, updated_at=%s
                     WHERE event_id=%d AND ticket_id=%d AND item_id=%s AND period_key=%s AND consumed >= %d",
                    $quantity,
                    $now,
                    $event_id,
                    $ticket_id,
                    $item_id,
                    $period_key,
                    $quantity
                ));
                if ( intval($updated) !== 1 ) throw new RuntimeException('balance_update_failed');

                $request_uuid = 'reverse_' . substr(hash('sha256', $batch_id . '|' . $row_id), 0, 56);
                $inserted = $wpdb->insert($tables['ledger'], [
                    'request_uuid'    => $request_uuid,
                    'event_id'        => $event_id,
                    'ticket_id'       => $ticket_id,
                    'config_id'       => sanitize_key($row['config_id'] ?? ''),
                    'item_id'         => $item_id,
                    'item_name'       => sanitize_text_field($row['item_name'] ?? 'Consumible'),
                    'period_key'      => $period_key,
                    'quantity'        => $quantity,
                    'action'          => 'reverse',
                    'staff_user_id'   => $actor_user_id,
                    'qr_type'         => sanitize_key($row['qr_type'] ?? ''),
                    'source'          => 'manager_reversal',
                    'remaining_after' => $remaining_after,
                    'note'            => 'target_batch=' . $batch_id . ';original_ledger_id=' . $row_id,
                    'created_at'      => $now,
                ], [ '%s','%d','%d','%s','%s','%s','%s','%d','%s','%d','%s','%s','%d','%s','%s' ]);
                if ( ! $inserted ) throw new RuntimeException('ledger_insert_failed');
            }

            $wpdb->query('COMMIT');
            return [ 'reversed' => true ];
        } catch ( Throwable $e ) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'reverse_failed',
                'No se pudo anular la transacción de forma segura. Ningún saldo fue modificado.'
            );
        }
    }
}

if ( ! function_exists('eventosapp_consumables_tx_ajax_request_cancel') ) {
    function eventosapp_consumables_tx_ajax_request_cancel() {
        if ( ! is_user_logged_in() ) wp_send_json_error([ 'message' => 'Debes iniciar sesión.' ], 401);
        check_ajax_referer('eventosapp_consumables_transactions', 'nonce');

        $event_id = absint($_POST['event_id'] ?? 0);
        $batch_id = eventosapp_consumables_tx_sanitize_batch_id($_POST['batch_id'] ?? '');
        $user_id = get_current_user_id();
        $active_event = function_exists('eventosapp_get_active_event') ? absint(eventosapp_get_active_event($user_id)) : 0;

        if ( ! $event_id || $event_id !== $active_event ) {
            wp_send_json_error([ 'message' => 'El evento activo cambió. Regresa al dashboard y vuelve a seleccionarlo.' ], 400);
        }
        if ( ! eventosapp_consumables_user_can_feature($event_id, $user_id, 'consumables_staff') ) {
            wp_send_json_error([ 'message' => 'No tienes permisos para consultar estas transacciones.' ], 403);
        }

        $result = eventosapp_consumables_tx_request_cancel($event_id, $batch_id, $user_id);
        if ( is_wp_error($result) ) {
            wp_send_json_error([ 'message' => $result->get_error_message() ], 409);
        }
        wp_send_json_success([
            'message' => ! empty($result['duplicate'])
                ? 'La solicitud ya estaba registrada.'
                : 'Solicitud de cancelación enviada al administrador.',
        ]);
    }
}
add_action('wp_ajax_eventosapp_consumables_request_cancel', 'eventosapp_consumables_tx_ajax_request_cancel');

if ( ! function_exists('eventosapp_consumables_tx_ajax_reverse') ) {
    function eventosapp_consumables_tx_ajax_reverse() {
        if ( ! is_user_logged_in() ) wp_send_json_error([ 'message' => 'Debes iniciar sesión.' ], 401);
        check_ajax_referer('eventosapp_consumables_transactions', 'nonce');

        $event_id = absint($_POST['event_id'] ?? 0);
        $batch_id = eventosapp_consumables_tx_sanitize_batch_id($_POST['batch_id'] ?? '');
        $user_id = get_current_user_id();
        $active_event = function_exists('eventosapp_get_active_event') ? absint(eventosapp_get_active_event($user_id)) : 0;

        if ( ! $event_id || $event_id !== $active_event ) {
            wp_send_json_error([ 'message' => 'El evento activo cambió. Regresa al dashboard y vuelve a seleccionarlo.' ], 400);
        }
        if ( ! eventosapp_consumables_user_can_feature($event_id, $user_id, 'consumables_manage') ) {
            wp_send_json_error([ 'message' => 'No tienes permisos para anular transacciones de este evento.' ], 403);
        }

        $result = eventosapp_consumables_tx_reverse($event_id, $batch_id, $user_id);
        if ( is_wp_error($result) ) {
            wp_send_json_error([ 'message' => $result->get_error_message() ], 409);
        }
        wp_send_json_success([ 'message' => 'Transacción anulada. Los recursos fueron restablecidos y el movimiento quedó registrado en auditoría.' ]);
    }
}
add_action('wp_ajax_eventosapp_consumables_reverse_transaction', 'eventosapp_consumables_tx_ajax_reverse');

/* -------------------------------------------------------------------------
 * Filtros, estadísticas y exportación
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_tx_request_filters') ) {
    function eventosapp_consumables_tx_request_filters() {
        return [
            'q'          => sanitize_text_field(wp_unslash($_GET['evctx_q'] ?? '')),
            'item'       => sanitize_key(wp_unslash($_GET['evctx_item'] ?? '')),
            'staff'      => absint($_GET['evctx_staff'] ?? 0),
            'cedula'     => sanitize_text_field(wp_unslash($_GET['evctx_cedula'] ?? '')),
            'localidad'  => sanitize_text_field(wp_unslash($_GET['evctx_localidad'] ?? '')),
            'date'       => sanitize_text_field(wp_unslash($_GET['evctx_date'] ?? '')),
            'time_from'  => sanitize_text_field(wp_unslash($_GET['evctx_time_from'] ?? '')),
            'time_to'    => sanitize_text_field(wp_unslash($_GET['evctx_time_to'] ?? '')),
            'status'     => sanitize_key(wp_unslash($_GET['evctx_status'] ?? '')),
        ];
    }
}

if ( ! function_exists('eventosapp_consumables_tx_normalize_search') ) {
    function eventosapp_consumables_tx_normalize_search($value) {
        $value = strtolower(remove_accents((string) $value));
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim($value);
    }
}

if ( ! function_exists('eventosapp_consumables_tx_filter') ) {
    function eventosapp_consumables_tx_filter($transactions, $filters) {
        $out = [];
        foreach ( (array) $transactions as $tx ) {
            $ticket = $tx['ticket'] ?? [];
            $items = (array) ($tx['items'] ?? []);

            if ( ! empty($filters['item']) ) {
                $match = false;
                foreach ( $items as $item ) {
                    if ( sanitize_key($item['item_id'] ?? '') === $filters['item'] ) { $match = true; break; }
                }
                if ( ! $match ) continue;
            }
            if ( ! empty($filters['staff']) && absint($tx['staff_user_id'] ?? 0) !== absint($filters['staff']) ) continue;
            if ( $filters['cedula'] !== '' && strpos(eventosapp_consumables_tx_normalize_search($ticket['cedula'] ?? ''), eventosapp_consumables_tx_normalize_search($filters['cedula'])) === false ) continue;
            if ( $filters['localidad'] !== '' && eventosapp_consumables_tx_normalize_search($ticket['localidad'] ?? '') !== eventosapp_consumables_tx_normalize_search($filters['localidad']) ) continue;
            if ( $filters['date'] !== '' && substr((string) ($tx['created_at'] ?? ''), 0, 10) !== $filters['date'] ) continue;

            $time = substr((string) ($tx['created_at'] ?? ''), 11, 5);
            if ( $filters['time_from'] !== '' && $time < $filters['time_from'] ) continue;
            if ( $filters['time_to'] !== '' && $time > $filters['time_to'] ) continue;
            if ( $filters['status'] !== '' && sanitize_key($tx['status'] ?? '') !== $filters['status'] ) continue;

            if ( $filters['q'] !== '' ) {
                $parts = [
                    $ticket['nombre_completo'] ?? '',
                    $ticket['cedula'] ?? '',
                    $ticket['localidad'] ?? '',
                    $tx['staff_label'] ?? '',
                    implode(' ', (array) ($tx['matching_configs'] ?? [])),
                    implode(' ', array_map(static function($item){ return ($item['item_name'] ?? '') . ' ' . ($item['quantity'] ?? ''); }, $items)),
                ];
                if ( strpos(eventosapp_consumables_tx_normalize_search(implode(' ', $parts)), eventosapp_consumables_tx_normalize_search($filters['q'])) === false ) continue;
            }

            $out[] = $tx;
        }
        return $out;
    }
}

if ( ! function_exists('eventosapp_consumables_tx_item_stats') ) {
    function eventosapp_consumables_tx_item_stats($transactions) {
        $stats = [];
        foreach ( (array) $transactions as $tx ) {
            $reversed = (array) ($tx['reversed_by_item'] ?? []);
            foreach ( (array) ($tx['items'] ?? []) as $item ) {
                $item_id = sanitize_key($item['item_id'] ?? '');
                if ( $item_id === '' ) continue;
                if ( ! isset($stats[$item_id]) ) {
                    $stats[$item_id] = [
                        'item_id'   => $item_id,
                        'item_name' => sanitize_text_field($item['item_name'] ?? 'Consumible'),
                        'gross'     => 0,
                        'reversed'  => 0,
                        'net'       => 0,
                        'pending'   => 0,
                    ];
                }
                $qty = absint($item['quantity'] ?? 0);
                $rev = min($qty, absint($reversed[$item_id] ?? 0));
                $stats[$item_id]['gross'] += $qty;
                $stats[$item_id]['reversed'] += $rev;
                $stats[$item_id]['net'] += max(0, $qty - $rev);
                if ( ($tx['status'] ?? '') === 'cancel_requested' ) $stats[$item_id]['pending'] += $qty;
            }
        }
        uasort($stats, static function($a, $b){ return intval($b['net']) <=> intval($a['net']); });
        return array_values($stats);
    }
}

if ( ! function_exists('eventosapp_consumables_tx_csv_value') ) {
    /**
     * Evita que Excel/LibreOffice interpreten datos textuales del asistente como fórmulas.
     */
    function eventosapp_consumables_tx_csv_value($value) {
        if ( ! is_string($value) ) return $value;
        if ( $value !== '' && preg_match('/^[=+@\t\r-]/u', $value) ) {
            return "'" . $value;
        }
        return $value;
    }
}

if ( ! function_exists('eventosapp_consumables_tx_export') ) {
    function eventosapp_consumables_tx_export() {
        if ( ! is_user_logged_in() ) auth_redirect();
        $event_id = absint($_GET['event_id'] ?? 0);
        check_admin_referer('eventosapp_consumables_export_' . $event_id);

        $user_id = get_current_user_id();
        if ( ! $event_id || ! eventosapp_consumables_user_can_feature($event_id, $user_id, 'consumables_manage') ) {
            wp_die('No tienes permisos para descargar las transacciones de este evento.', 'Acceso denegado', [ 'response' => 403 ]);
        }

        global $wpdb;
        $tables = eventosapp_consumables_table_names();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tables['ledger']} WHERE event_id=%d ORDER BY id ASC",
            $event_id
        ), ARRAY_A);
        $transactions = eventosapp_consumables_tx_load_event_transactions($event_id);
        $tx_map = [];
        foreach ( $transactions as $tx ) $tx_map[$tx['batch_id']] = $tx;
        $config_map = eventosapp_consumables_tx_config_map($event_id);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="consumibles-evento-' . $event_id . '-' . wp_date('Y-m-d-His') . '.csv"');
        $out = fopen('php://output', 'w');
        echo "\xEF\xBB\xBF";
        fputcsv($out, [
            'ID movimiento','ID transacción','Tipo movimiento','Estado transacción','Evento','ID ticket','Ticket público',
            'Nombre','Apellido','Cédula','Localidad','Configuraciones asignadas','Configuración aplicada','Item','Cantidad movimiento',
            'Cantidad absoluta','Staff del movimiento','Staff que cobró','Fecha','Hora','Periodo','Saldo después','Tipo QR','Fuente','Nota auditoría'
        ], ';', '"', '');

        foreach ( (array) $rows as $row ) {
            $batch_id = eventosapp_consumables_tx_batch_from_row($row);
            $tx = $batch_id && isset($tx_map[$batch_id]) ? $tx_map[$batch_id] : [];
            $ticket = eventosapp_consumables_tx_ticket_details(absint($row['ticket_id'] ?? 0));
            $action = sanitize_key($row['action'] ?? '');
            $movement_label = [
                'consume'        => 'Consumo',
                'cancel_request' => 'Solicitud de cancelación',
                'reverse'        => 'Reverso / restauración',
            ][$action] ?? $action;
            $qty = absint($row['quantity'] ?? 0);
            $signed_qty = $action === 'reverse' ? -$qty : ($action === 'cancel_request' ? 0 : $qty);
            $created = sanitize_text_field($row['created_at'] ?? '');
            $config_id = sanitize_key($row['config_id'] ?? '');

            $csv_row = [
                absint($row['id'] ?? 0),
                $batch_id,
                $movement_label,
                $tx ? eventosapp_consumables_tx_status_label($tx['status'] ?? '') : '',
                get_the_title($event_id),
                absint($row['ticket_id'] ?? 0),
                $ticket['public_id'] ?? '',
                $ticket['nombre'] ?? '',
                $ticket['apellido'] ?? '',
                $ticket['cedula'] ?? '',
                $ticket['localidad'] ?? '',
                implode(' | ', eventosapp_consumables_tx_matching_config_names($event_id, absint($row['ticket_id'] ?? 0))),
                $config_map[$config_id] ?? $config_id,
                sanitize_text_field($row['item_name'] ?? ''),
                $signed_qty,
                $qty,
                eventosapp_consumables_tx_user_label(absint($row['staff_user_id'] ?? 0)),
                $tx ? ($tx['staff_label'] ?? '') : '',
                substr($created, 0, 10),
                substr($created, 11, 8),
                sanitize_text_field($row['period_key'] ?? ''),
                intval($row['remaining_after'] ?? 0),
                sanitize_text_field($row['qr_type'] ?? ''),
                sanitize_text_field($row['source'] ?? ''),
                sanitize_text_field($row['note'] ?? ''),
            ];
            $csv_row = array_map('eventosapp_consumables_tx_csv_value', $csv_row);
            fputcsv($out, $csv_row, ';', '"', '');
        }
        fclose($out);
        exit;
    }
}
add_action('admin_post_eventosapp_consumables_export_transactions', 'eventosapp_consumables_tx_export');

/* -------------------------------------------------------------------------
 * UI compartida de pestañas
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_tx_tabs_html') ) {
    function eventosapp_consumables_tx_tabs_html($type, $active) {
        $is_manager = $type === 'manager';
        $base = $is_manager
            ? (function_exists('eventosapp_get_consumables_manager_url') ? eventosapp_get_consumables_manager_url() : '#')
            : (function_exists('eventosapp_get_consumables_staff_url') ? eventosapp_get_consumables_staff_url() : '#');
        $first = $is_manager ? 'config' : 'scan';
        $second = $is_manager ? 'transactions' : 'my-transactions';
        $first_label = $is_manager ? 'Configuración' : 'Escanear';
        $second_label = $is_manager ? 'Transacciones' : 'Mis transacciones';

        $first_url = add_query_arg('evc_tab', $first, $base);
        $second_url = add_query_arg('evc_tab', $second, $base);

        return '<div class="evapp-cons-tabs-wrap"><style>
            .evapp-cons-tabs-wrap{max-width:1180px;margin:0 auto 14px}.evapp-cons-tabs{display:flex;gap:8px;padding:6px;background:#eef4fa;border:1px solid #dfe7f1;border-radius:14px}.evapp-cons-tab{flex:1;text-align:center;padding:10px 14px;border-radius:10px;text-decoration:none!important;font-weight:800;color:#475569}.evapp-cons-tab.is-active{background:#fff;color:#255f96;box-shadow:0 4px 12px rgba(31,52,73,.08)}
        </style><nav class="evapp-cons-tabs" aria-label="Secciones de consumibles">'
        . '<a class="evapp-cons-tab ' . ($active === $first ? 'is-active' : '') . '" href="' . esc_url($first_url) . '">' . esc_html($first_label) . '</a>'
        . '<a class="evapp-cons-tab ' . ($active === $second ? 'is-active' : '') . '" href="' . esc_url($second_url) . '">' . esc_html($second_label) . '</a>'
        . '</nav></div>';
    }
}

if ( ! function_exists('eventosapp_consumables_tx_item_summary_html') ) {
    function eventosapp_consumables_tx_item_summary_html($stats, $staff_mode = false) {
        if ( empty($stats) ) return '<div class="evapp-tx-empty">Todavía no hay consumos registrados.</div>';
        ob_start(); ?>
        <div class="evapp-tx-summary-grid">
            <?php foreach ( $stats as $stat ): ?>
                <div class="evapp-tx-summary-card">
                    <span><?php echo esc_html($stat['item_name']); ?></span>
                    <strong><?php echo (int) $stat['net']; ?></strong>
                    <small><?php echo $staff_mode ? 'unidades netas registradas' : 'neto entregado'; ?></small>
                    <?php if ( ! $staff_mode ): ?>
                        <em>Bruto: <?php echo (int) $stat['gross']; ?> · Anulado: <?php echo (int) $stat['reversed']; ?><?php echo $stat['pending'] ? ' · Pendiente: ' . (int) $stat['pending'] : ''; ?></em>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php return ob_get_clean();
    }
}

/* -------------------------------------------------------------------------
 * Control de Consumibles — pestaña Transacciones
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_tx_render_manager_transactions') ) {
    function eventosapp_consumables_tx_render_manager_transactions($event_id) {
        $event_id = absint($event_id);
        $all = eventosapp_consumables_tx_load_event_transactions($event_id);
        $filters = eventosapp_consumables_tx_request_filters();
        $filtered = eventosapp_consumables_tx_filter($all, $filters);
        $stats = eventosapp_consumables_tx_item_stats($all);
        $page = max(1, absint($_GET['evctx_page'] ?? 1));
        $per_page = 25;
        $total_pages = max(1, (int) ceil(count($filtered) / $per_page));
        if ( $page > $total_pages ) $page = $total_pages;
        $rows = array_slice($filtered, ($page - 1) * $per_page, $per_page);
        $nonce = wp_create_nonce('eventosapp_consumables_transactions');
        $base = function_exists('eventosapp_get_consumables_manager_url') ? eventosapp_get_consumables_manager_url() : '#';
        $export_url = wp_nonce_url(
            add_query_arg([ 'action' => 'eventosapp_consumables_export_transactions', 'event_id' => $event_id ], admin_url('admin-post.php')),
            'eventosapp_consumables_export_' . $event_id
        );

        $staff_options = [];
        $localities = [];
        foreach ( $all as $tx ) {
            $sid = absint($tx['staff_user_id'] ?? 0);
            if ( $sid ) $staff_options[$sid] = $tx['staff_label'] ?? eventosapp_consumables_tx_user_label($sid);
            $loc = trim((string) ($tx['ticket']['localidad'] ?? ''));
            if ( $loc !== '' ) $localities[eventosapp_consumables_tx_normalize_search($loc)] = $loc;
        }
        asort($staff_options, SORT_NATURAL | SORT_FLAG_CASE);
        asort($localities, SORT_NATURAL | SORT_FLAG_CASE);
        $event_items = eventosapp_consumables_get_event_items($event_id);
        foreach ( $all as $tx ) {
            foreach ( (array) ($tx['items'] ?? []) as $history_item ) {
                $history_id = sanitize_key($history_item['item_id'] ?? '');
                if ( $history_id === '' || isset($event_items[$history_id]) ) continue;
                $event_items[$history_id] = [
                    'id' => $history_id,
                    'name' => sanitize_text_field($history_item['item_name'] ?? 'Consumible histórico'),
                ];
            }
        }

        ob_start(); ?>
        <div class="evapp-tx-shell" id="evapp-consumables-transactions">
            <style>
                .evapp-tx-shell{--p:#3279bd;--pd:#255f96;--bg:#f5f8fc;--b:#dfe7f1;--t:#182230;--m:#64748b;--danger:#b42318;--warn:#9a6700;max-width:1180px;margin:0 auto;padding:clamp(16px,3vw,30px);background:var(--bg);border:1px solid var(--b);border-radius:24px;color:var(--t);font-family:inherit}.evapp-tx-shell *{box-sizing:border-box}.evapp-tx-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}.evapp-tx-head h2{margin:0;font-size:clamp(26px,4vw,38px)}.evapp-tx-head p{margin:6px 0 0;color:var(--m)}.evapp-tx-actions{display:flex;gap:8px;flex-wrap:wrap}.evapp-tx-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:9px 14px;border-radius:11px;border:1px solid var(--p);background:var(--p);color:#fff!important;text-decoration:none!important;font-weight:800;cursor:pointer}.evapp-tx-btn.is-secondary{background:#fff;color:var(--p)!important}.evapp-tx-btn.is-danger{background:#fff;color:var(--danger)!important;border-color:#f1b3ad}.evapp-tx-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:18px}.evapp-tx-summary-card{background:#fff;border:1px solid var(--b);border-radius:14px;padding:14px}.evapp-tx-summary-card span,.evapp-tx-summary-card small,.evapp-tx-summary-card em{display:block}.evapp-tx-summary-card span{font-weight:800}.evapp-tx-summary-card strong{display:block;font-size:27px;color:var(--pd);margin:5px 0 2px}.evapp-tx-summary-card small,.evapp-tx-summary-card em{color:var(--m);font-size:11px}.evapp-tx-summary-card em{font-style:normal;margin-top:6px}.evapp-tx-filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:14px;margin-bottom:16px;background:#fff;border:1px solid var(--b);border-radius:16px}.evapp-tx-field label{display:block;font-size:11px;font-weight:800;color:var(--m);margin-bottom:4px}.evapp-tx-field input,.evapp-tx-field select{width:100%;min-height:40px;border:1px solid #cbd5e1;border-radius:9px;padding:7px 9px;background:#fff}.evapp-tx-field.is-wide{grid-column:span 2}.evapp-tx-filter-actions{display:flex;align-items:end;gap:8px}.evapp-tx-table-wrap{overflow:auto;background:#fff;border:1px solid var(--b);border-radius:16px}.evapp-tx-table{width:100%;min-width:1050px;border-collapse:collapse}.evapp-tx-table th,.evapp-tx-table td{padding:11px 10px;border-bottom:1px solid #edf2f7;vertical-align:top;text-align:left;font-size:12px}.evapp-tx-table th{background:#f8fafc;color:#475569;font-size:10px;text-transform:uppercase;letter-spacing:.04em}.evapp-tx-table tr.is-pending td{background:#fff9e8}.evapp-tx-items-line{display:block;font-weight:750;margin-bottom:3px}.evapp-tx-muted{color:var(--m);font-size:11px}.evapp-tx-badge{display:inline-flex;padding:5px 8px;border-radius:999px;background:#eaf4ff;color:#255f96;font-size:10px;font-weight:850}.evapp-tx-badge.is-pending{background:#fff1c2;color:#7a5200}.evapp-tx-badge.is-reversed{background:#eef2f7;color:#52606d}.evapp-tx-message{display:none;margin:0 0 14px;padding:12px 14px;border-radius:11px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-weight:700}.evapp-tx-message.is-error{background:#fef2f2;border-color:#fecaca;color:#991b1b}.evapp-tx-empty{padding:22px;text-align:center;color:var(--m)}.evapp-tx-pages{display:flex;justify-content:center;gap:6px;margin-top:14px}.evapp-tx-pages a,.evapp-tx-pages span{min-width:34px;padding:7px 9px;text-align:center;border-radius:8px;border:1px solid var(--b);background:#fff;text-decoration:none}.evapp-tx-pages .current{background:var(--p);color:#fff;border-color:var(--p)}
                @media(max-width:850px){.evapp-tx-head{flex-direction:column}.evapp-tx-filters{grid-template-columns:1fr 1fr}.evapp-tx-field.is-wide{grid-column:span 2}}@media(max-width:520px){.evapp-tx-filters{grid-template-columns:1fr}.evapp-tx-field.is-wide{grid-column:auto}.evapp-tx-filter-actions{align-items:stretch;flex-direction:column}.evapp-tx-filter-actions .evapp-tx-btn{width:100%}}
            </style>

            <div class="evapp-tx-head">
                <div><h2>Transacciones de consumibles</h2><p>Consulta, filtra, exporta y anula movimientos sin perder la trazabilidad histórica.</p></div>
                <div class="evapp-tx-actions"><a class="evapp-tx-btn is-secondary" href="<?php echo esc_url($export_url); ?>">Descargar CSV completo</a></div>
            </div>

            <h3 style="margin:0 0 10px">Control general por ítem</h3>
            <?php echo eventosapp_consumables_tx_item_summary_html($stats, false); ?>

            <form class="evapp-tx-filters" method="get" action="<?php echo esc_url($base); ?>">
                <input type="hidden" name="evc_tab" value="transactions">
                <div class="evapp-tx-field is-wide"><label>Búsqueda general</label><input type="search" name="evctx_q" value="<?php echo esc_attr($filters['q']); ?>" placeholder="Nombre, cédula, ítem, configuración o staff"></div>
                <div class="evapp-tx-field"><label>Ítem</label><select name="evctx_item"><option value="">Todos</option><?php foreach ( $event_items as $item ): ?><option value="<?php echo esc_attr($item['id']); ?>" <?php selected($filters['item'], $item['id']); ?>><?php echo esc_html($item['name']); ?></option><?php endforeach; ?></select></div>
                <div class="evapp-tx-field"><label>Staff</label><select name="evctx_staff"><option value="0">Todos</option><?php foreach ( $staff_options as $sid => $label ): ?><option value="<?php echo (int)$sid; ?>" <?php selected($filters['staff'], $sid); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div>
                <div class="evapp-tx-field"><label>Cédula</label><input type="search" name="evctx_cedula" value="<?php echo esc_attr($filters['cedula']); ?>"></div>
                <div class="evapp-tx-field"><label>Localidad</label><select name="evctx_localidad"><option value="">Todas</option><?php foreach ( $localities as $loc ): ?><option value="<?php echo esc_attr($loc); ?>" <?php selected(eventosapp_consumables_tx_normalize_search($filters['localidad']), eventosapp_consumables_tx_normalize_search($loc)); ?>><?php echo esc_html($loc); ?></option><?php endforeach; ?></select></div>
                <div class="evapp-tx-field"><label>Fecha</label><input type="date" name="evctx_date" value="<?php echo esc_attr($filters['date']); ?>"></div>
                <div class="evapp-tx-field"><label>Hora desde</label><input type="time" name="evctx_time_from" value="<?php echo esc_attr($filters['time_from']); ?>"></div>
                <div class="evapp-tx-field"><label>Hora hasta</label><input type="time" name="evctx_time_to" value="<?php echo esc_attr($filters['time_to']); ?>"></div>
                <div class="evapp-tx-field"><label>Estado</label><select name="evctx_status"><option value="">Todos</option><option value="active" <?php selected($filters['status'],'active'); ?>>Activa</option><option value="cancel_requested" <?php selected($filters['status'],'cancel_requested'); ?>>Cancelación solicitada</option><option value="reversed" <?php selected($filters['status'],'reversed'); ?>>Anulada</option></select></div>
                <div class="evapp-tx-filter-actions"><button class="evapp-tx-btn" type="submit">Filtrar</button><a class="evapp-tx-btn is-secondary" href="<?php echo esc_url(add_query_arg('evc_tab','transactions',$base)); ?>">Limpiar</a></div>
            </form>

            <div class="evapp-tx-message" data-tx-message></div>
            <div class="evapp-tx-table-wrap">
                <?php if ( $rows ): ?>
                <table class="evapp-tx-table"><thead><tr><th>Estado</th><th>Fecha / hora</th><th>Asistente</th><th>Cédula / localidad</th><th>Ítems cobrados</th><th>Configuración</th><th>Staff</th><th>Acción</th></tr></thead><tbody>
                    <?php foreach ( $rows as $tx ):
                        $ticket = $tx['ticket'];
                        $status = $tx['status'];
                        ?>
                        <tr class="<?php echo $status === 'cancel_requested' ? 'is-pending' : ''; ?>">
                            <td><span class="evapp-tx-badge <?php echo $status === 'cancel_requested' ? 'is-pending' : ($status === 'reversed' ? 'is-reversed' : ''); ?>"><?php echo esc_html($tx['status_label']); ?></span><?php if ( $status === 'cancel_requested' ): ?><div class="evapp-tx-muted" style="margin-top:5px">Solicitada por <?php echo esc_html($tx['request_user_label']); ?></div><?php endif; ?></td>
                            <td><?php echo esc_html(date_i18n('d/m/Y', strtotime($tx['created_at']))); ?><div class="evapp-tx-muted"><?php echo esc_html(date_i18n('H:i:s', strtotime($tx['created_at']))); ?></div></td>
                            <td><strong><?php echo esc_html($ticket['nombre_completo']); ?></strong><div class="evapp-tx-muted">Ticket #<?php echo esc_html($ticket['public_id'] ?: $ticket['ticket_id']); ?></div></td>
                            <td><?php echo esc_html($ticket['cedula'] ?: '—'); ?><div class="evapp-tx-muted"><?php echo esc_html($ticket['localidad'] ?: 'Sin localidad'); ?></div></td>
                            <td><?php foreach ( $tx['items'] as $item ): ?><span class="evapp-tx-items-line"><?php echo (int)$item['quantity']; ?> × <?php echo esc_html($item['item_name']); ?></span><?php endforeach; ?></td>
                            <td><?php echo esc_html(implode(' · ', $tx['applied_configs']) ?: '—'); ?><div class="evapp-tx-muted">Coincide con: <?php echo esc_html(implode(' · ', $tx['matching_configs']) ?: '—'); ?></div></td>
                            <td><?php echo esc_html($tx['staff_label']); ?></td>
                            <td><?php if ( in_array($status, ['active','cancel_requested'], true) ): ?><button type="button" class="evapp-tx-btn is-danger" data-reverse-batch="<?php echo esc_attr($tx['batch_id']); ?>">Anular y restablecer</button><?php else: ?><span class="evapp-tx-muted">Sin acciones disponibles</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>
                <?php else: ?><div class="evapp-tx-empty">No se encontraron transacciones con los filtros actuales.</div><?php endif; ?>
            </div>

            <?php if ( $total_pages > 1 ): ?><nav class="evapp-tx-pages" aria-label="Paginación">
                <?php for ( $i=1; $i <= $total_pages; $i++ ):
                    $args = array_filter([
                        'evc_tab'=>'transactions','evctx_q'=>$filters['q'],'evctx_item'=>$filters['item'],'evctx_staff'=>$filters['staff'],
                        'evctx_cedula'=>$filters['cedula'],'evctx_localidad'=>$filters['localidad'],'evctx_date'=>$filters['date'],
                        'evctx_time_from'=>$filters['time_from'],'evctx_time_to'=>$filters['time_to'],'evctx_status'=>$filters['status'],'evctx_page'=>$i,
                    ], static function($v){ return $v !== '' && $v !== 0; });
                    if ( $i === $page ): ?><span class="current"><?php echo (int)$i; ?></span><?php else: ?><a href="<?php echo esc_url(add_query_arg($args, $base)); ?>"><?php echo (int)$i; ?></a><?php endif; ?>
                <?php endfor; ?></nav><?php endif; ?>

            <script>
            (function(){
                var root=document.getElementById('evapp-consumables-transactions');if(!root)return;
                var ajaxUrl=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,nonce=<?php echo wp_json_encode($nonce); ?>,eventId=<?php echo (int)$event_id; ?>;
                var msg=root.querySelector('[data-tx-message]');
                function show(ok,text){msg.className='evapp-tx-message '+(ok?'':'is-error');msg.textContent=text;msg.style.display='block';}
                root.addEventListener('click',async function(e){
                    var btn=e.target.closest('[data-reverse-batch]');if(!btn)return;
                    e.preventDefault();
                    if(!confirm('¿Anular esta transacción? Los consumibles serán restablecidos al asistente y el movimiento original se conservará en el historial.'))return;
                    btn.disabled=true;btn.textContent='Anulando...';
                    var body=new URLSearchParams();body.set('action','eventosapp_consumables_reverse_transaction');body.set('nonce',nonce);body.set('event_id',eventId);body.set('batch_id',btn.getAttribute('data-reverse-batch'));
                    try{var r=await fetch(ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});var j=await r.json();if(j&&j.success){show(true,j.data.message);setTimeout(function(){window.location.reload();},350);}else{show(false,(j&&j.data&&j.data.message)||'No se pudo anular la transacción.');btn.disabled=false;btn.textContent='Anular y restablecer';}}catch(err){show(false,'Error de conexión. Intenta nuevamente.');btn.disabled=false;btn.textContent='Anular y restablecer';}
                });
            })();
            </script>
        </div>
        <?php return ob_get_clean();
    }
}

/* -------------------------------------------------------------------------
 * Consumo de Consumibles — pestaña Mis transacciones
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_tx_render_staff_transactions') ) {
    function eventosapp_consumables_tx_render_staff_transactions($event_id, $user_id) {
        $event_id = absint($event_id);
        $user_id = absint($user_id);
        $all = array_values(array_filter(eventosapp_consumables_tx_load_event_transactions($event_id), static function($tx) use ($user_id) {
            return absint($tx['staff_user_id'] ?? 0) === $user_id;
        }));
        $stats = eventosapp_consumables_tx_item_stats($all);
        $page = max(1, absint($_GET['evc_staff_page'] ?? 1));
        $per_page = 25;
        $total_pages = max(1, (int) ceil(count($all) / $per_page));
        if ( $page > $total_pages ) $page = $total_pages;
        $rows = array_slice($all, ($page - 1) * $per_page, $per_page);
        $nonce = wp_create_nonce('eventosapp_consumables_transactions');
        $base = function_exists('eventosapp_get_consumables_staff_url') ? eventosapp_get_consumables_staff_url() : '#';

        ob_start(); ?>
        <div class="evapp-tx-shell evapp-tx-staff" id="evapp-consumables-my-transactions">
            <style>
                .evapp-tx-staff{--p:#3279bd;--pd:#255f96;--bg:#f5f8fc;--b:#dfe7f1;--t:#182230;--m:#64748b;--danger:#b42318;max-width:1040px;margin:0 auto;padding:clamp(16px,3vw,30px);background:var(--bg);border:1px solid var(--b);border-radius:24px;color:var(--t);font-family:inherit}.evapp-tx-staff *{box-sizing:border-box}.evapp-tx-staff h2{margin:0;font-size:clamp(26px,4vw,38px)}.evapp-tx-staff .lead{margin:6px 0 18px;color:var(--m)}.evapp-tx-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:18px}.evapp-tx-summary-card{background:#fff;border:1px solid var(--b);border-radius:14px;padding:14px}.evapp-tx-summary-card span,.evapp-tx-summary-card small{display:block}.evapp-tx-summary-card span{font-weight:800}.evapp-tx-summary-card strong{display:block;font-size:27px;color:var(--pd);margin:5px 0 2px}.evapp-tx-summary-card small{color:var(--m);font-size:11px}.evapp-tx-list{display:grid;gap:10px}.evapp-tx-card{padding:14px;background:#fff;border:1px solid var(--b);border-radius:15px}.evapp-tx-card.is-pending{border-color:#f1cf72;background:#fffaf0}.evapp-tx-card-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.evapp-tx-card h4{margin:0 0 4px}.evapp-tx-muted{color:var(--m);font-size:11px}.evapp-tx-card-items{display:flex;flex-wrap:wrap;gap:6px;margin:11px 0}.evapp-tx-card-item{padding:6px 9px;border-radius:999px;background:#eaf4ff;color:#255f96;font-size:11px;font-weight:800}.evapp-tx-badge{display:inline-flex;padding:5px 8px;border-radius:999px;background:#eaf4ff;color:#255f96;font-size:10px;font-weight:850}.evapp-tx-badge.is-pending{background:#fff1c2;color:#7a5200}.evapp-tx-badge.is-reversed{background:#eef2f7;color:#52606d}.evapp-tx-btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:8px 12px;border-radius:10px;border:1px solid var(--p);background:#fff;color:var(--p)!important;font-weight:800;cursor:pointer}.evapp-tx-message{display:none;margin:0 0 14px;padding:12px 14px;border-radius:11px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-weight:700}.evapp-tx-message.is-error{background:#fef2f2;border-color:#fecaca;color:#991b1b}.evapp-tx-empty{padding:22px;text-align:center;color:var(--m);background:#fff;border:1px dashed var(--b);border-radius:14px}.evapp-tx-pages{display:flex;justify-content:center;gap:6px;margin-top:14px}.evapp-tx-pages a,.evapp-tx-pages span{min-width:34px;padding:7px 9px;text-align:center;border-radius:8px;border:1px solid var(--b);background:#fff;text-decoration:none}.evapp-tx-pages .current{background:var(--p);color:#fff;border-color:var(--p)}
                @media(max-width:600px){.evapp-tx-card-head{flex-direction:column}.evapp-tx-btn{width:100%}}
            </style>
            <h2>Mis transacciones</h2>
            <p class="lead">Consulta tus consumos registrados en este evento y solicita la cancelación de una transacción si detectas un error.</p>
            <?php echo eventosapp_consumables_tx_item_summary_html($stats, true); ?>
            <div class="evapp-tx-message" data-tx-message></div>
            <?php if ( $rows ): ?><div class="evapp-tx-list">
                <?php foreach ( $rows as $tx ): $ticket=$tx['ticket']; $status=$tx['status']; ?>
                    <article class="evapp-tx-card <?php echo $status === 'cancel_requested' ? 'is-pending' : ''; ?>">
                        <div class="evapp-tx-card-head"><div><h4><?php echo esc_html($ticket['nombre_completo']); ?></h4><div class="evapp-tx-muted"><?php echo esc_html(date_i18n('d/m/Y H:i:s', strtotime($tx['created_at']))); ?><?php echo $ticket['cedula'] ? ' · Cédula ' . esc_html($ticket['cedula']) : ''; ?></div></div><span class="evapp-tx-badge <?php echo $status === 'cancel_requested' ? 'is-pending' : ($status === 'reversed' ? 'is-reversed' : ''); ?>"><?php echo esc_html($tx['status_label']); ?></span></div>
                        <div class="evapp-tx-card-items"><?php foreach ( $tx['items'] as $item ): ?><span class="evapp-tx-card-item"><?php echo (int)$item['quantity']; ?> × <?php echo esc_html($item['item_name']); ?></span><?php endforeach; ?></div>
                        <?php if ( $status === 'active' ): ?><button type="button" class="evapp-tx-btn" data-request-cancel="<?php echo esc_attr($tx['batch_id']); ?>">Solicitar cancelación</button>
                        <?php elseif ( $status === 'cancel_requested' ): ?><div class="evapp-tx-muted">La solicitud ya está marcada para revisión del administrador.</div>
                        <?php elseif ( $status === 'reversed' ): ?><div class="evapp-tx-muted">El administrador anuló esta transacción y restableció los recursos.</div><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div><?php else: ?><div class="evapp-tx-empty">Todavía no has registrado transacciones en este evento.</div><?php endif; ?>

            <?php if ( $total_pages > 1 ): ?><nav class="evapp-tx-pages" aria-label="Paginación"><?php for($i=1;$i<=$total_pages;$i++): $url=add_query_arg(['evc_tab'=>'my-transactions','evc_staff_page'=>$i],$base); if($i===$page): ?><span class="current"><?php echo (int)$i; ?></span><?php else: ?><a href="<?php echo esc_url($url); ?>"><?php echo (int)$i; ?></a><?php endif; endfor; ?></nav><?php endif; ?>

            <script>
            (function(){
                var root=document.getElementById('evapp-consumables-my-transactions');if(!root)return;
                var ajaxUrl=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,nonce=<?php echo wp_json_encode($nonce); ?>,eventId=<?php echo (int)$event_id; ?>;
                var msg=root.querySelector('[data-tx-message]');
                function show(ok,text){msg.className='evapp-tx-message '+(ok?'':'is-error');msg.textContent=text;msg.style.display='block';}
                root.addEventListener('click',async function(e){var btn=e.target.closest('[data-request-cancel]');if(!btn)return;e.preventDefault();if(!confirm('¿Enviar esta transacción al administrador para solicitar su cancelación?'))return;btn.disabled=true;btn.textContent='Enviando...';var body=new URLSearchParams();body.set('action','eventosapp_consumables_request_cancel');body.set('nonce',nonce);body.set('event_id',eventId);body.set('batch_id',btn.getAttribute('data-request-cancel'));try{var r=await fetch(ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});var j=await r.json();if(j&&j.success){show(true,j.data.message);setTimeout(function(){window.location.reload();},350);}else{show(false,(j&&j.data&&j.data.message)||'No se pudo enviar la solicitud.');btn.disabled=false;btn.textContent='Solicitar cancelación';}}catch(err){show(false,'Error de conexión. Intenta nuevamente.');btn.disabled=false;btn.textContent='Solicitar cancelación';}});
            })();
            </script>
        </div>
        <?php return ob_get_clean();
    }
}

/* -------------------------------------------------------------------------
 * Reemplazo no destructivo de los shortcodes para incorporar pestañas
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_tx_manager_shortcode') ) {
    function eventosapp_consumables_tx_manager_shortcode() {
        if ( ! is_user_logged_in() ) return eventosapp_consumables_render_manager_shortcode();
        $event_id = function_exists('eventosapp_get_active_event') ? absint(eventosapp_get_active_event()) : 0;
        if ( ! $event_id || ! eventosapp_consumables_is_enabled($event_id) || ! eventosapp_consumables_user_can_feature($event_id, get_current_user_id(), 'consumables_manage') ) {
            return eventosapp_consumables_render_manager_shortcode();
        }

        $tab = sanitize_key(wp_unslash($_GET['evc_tab'] ?? 'config'));
        if ( ! in_array($tab, [ 'config', 'transactions' ], true) ) $tab = 'config';
        $content = $tab === 'transactions'
            ? eventosapp_consumables_tx_render_manager_transactions($event_id)
            : eventosapp_consumables_render_manager_shortcode();
        return eventosapp_consumables_tx_tabs_html('manager', $tab) . $content;
    }
}

if ( ! function_exists('eventosapp_consumables_tx_staff_shortcode') ) {
    function eventosapp_consumables_tx_staff_shortcode() {
        if ( ! is_user_logged_in() ) return eventosapp_consumables_render_staff_shortcode();
        $event_id = function_exists('eventosapp_get_active_event') ? absint(eventosapp_get_active_event()) : 0;
        if ( ! $event_id || ! eventosapp_consumables_is_enabled($event_id) || ! eventosapp_consumables_user_can_feature($event_id, get_current_user_id(), 'consumables_staff') ) {
            return eventosapp_consumables_render_staff_shortcode();
        }

        $tab = sanitize_key(wp_unslash($_GET['evc_tab'] ?? 'scan'));
        if ( ! in_array($tab, [ 'scan', 'my-transactions' ], true) ) $tab = 'scan';
        $content = $tab === 'my-transactions'
            ? eventosapp_consumables_tx_render_staff_transactions($event_id, get_current_user_id())
            : eventosapp_consumables_render_staff_shortcode();
        return eventosapp_consumables_tx_tabs_html('staff', $tab) . $content;
    }
}

remove_shortcode('eventosapp_consumables_manager');
add_shortcode('eventosapp_consumables_manager', 'eventosapp_consumables_tx_manager_shortcode');
remove_shortcode('eventosapp_consumables_staff');
add_shortcode('eventosapp_consumables_staff', 'eventosapp_consumables_tx_staff_shortcode');

/* -------------------------------------------------------------------------
 * Historial público del asistente
 * ---------------------------------------------------------------------- */

if ( ! function_exists('eventosapp_consumables_tx_ticket_transactions') ) {
    function eventosapp_consumables_tx_ticket_transactions($event_id, $ticket_id) {
        global $wpdb;
        $event_id = absint($event_id);
        $ticket_id = absint($ticket_id);
        if ( ! $event_id || ! $ticket_id || ! eventosapp_consumables_maybe_install_tables() ) return [];

        $tables = eventosapp_consumables_table_names();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tables['ledger']}
             WHERE event_id=%d AND ticket_id=%d AND action IN ('consume','reverse')
             ORDER BY id ASC",
            $event_id,
            $ticket_id
        ), ARRAY_A);

        $txs = [];
        $reversals = [];
        foreach ( (array) $rows as $row ) {
            $batch = eventosapp_consumables_tx_batch_from_row($row);
            if ( $batch === '' ) continue;
            if ( ($row['action'] ?? '') === 'consume' ) {
                if ( ! isset($txs[$batch]) ) {
                    $txs[$batch] = [ 'batch_id'=>$batch, 'created_at'=>$row['created_at'], 'lines'=>[], 'max_id'=>absint($row['id']) ];
                }
                $txs[$batch]['lines'][] = $row;
                $txs[$batch]['max_id'] = max($txs[$batch]['max_id'], absint($row['id']));
            } else {
                $reversals[$batch][] = $row;
            }
        }

        foreach ( $txs as $batch => &$tx ) {
            $total = array_sum(array_map(static function($r){ return absint($r['quantity'] ?? 0); }, $tx['lines']));
            $rev = array_sum(array_map(static function($r){ return absint($r['quantity'] ?? 0); }, $reversals[$batch] ?? []));
            $tx['reversed'] = $total > 0 && $rev >= $total;
        }
        unset($tx);
        $txs = array_values($txs);
        usort($txs, static function($a,$b){ return strcmp($a['created_at'],$b['created_at']); });
        return $txs;
    }
}

if ( ! function_exists('eventosapp_consumables_tx_public_history_html') ) {
    function eventosapp_consumables_tx_public_history_html($ticket_id, $event_id) {
        $ticket_id = absint($ticket_id);
        $event_id = absint($event_id);
        if ( ! $ticket_id || ! $event_id ) return '';
        if ( function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id) ) return '';
        if ( ! eventosapp_consumables_is_enabled($event_id) ) return '';

        $transactions = eventosapp_consumables_tx_ticket_transactions($event_id, $ticket_id);
        $event_days = eventosapp_consumables_get_event_days($event_id);
        $by_day = [];
        foreach ( $transactions as $tx ) {
            $day = substr((string) $tx['created_at'], 0, 10);
            if ( $day === '' ) continue;
            $by_day[$day][] = $tx;
            if ( ! in_array($day, $event_days, true) ) $event_days[] = $day;
        }
        sort($event_days, SORT_STRING);
        if ( empty($event_days) ) $event_days = [ wp_date('Y-m-d') ];

        ob_start(); ?>
        <section class="evapp-ticket-consumables-history" aria-labelledby="evapp-ticket-consumables-history-title">
            <style>
                .evapp-ticket-consumables-history{margin:0 28px 26px;padding:20px;border:1px solid #dbe7f3;border-radius:16px;background:#fff}.evapp-ticket-consumables-history h2{margin:0 0 5px;font-size:20px;color:#111827}.evapp-ticket-consumables-history>p{margin:0 0 14px;color:#64748b;font-size:13px}.evapp-ticket-cons-day{padding:13px 0;border-top:1px solid #edf2f7}.evapp-ticket-cons-day:first-of-type{border-top:0;padding-top:0}.evapp-ticket-cons-day h3{margin:0 0 9px;font-size:14px;color:#334155}.evapp-ticket-cons-move{padding:11px 12px;margin-top:8px;border:1px solid #dbe7f3;border-radius:12px;background:#f8fbff}.evapp-ticket-cons-move.is-reversed{background:#f8fafc;opacity:.72}.evapp-ticket-cons-move-head{display:flex;justify-content:space-between;gap:10px;font-size:11px;color:#64748b}.evapp-ticket-cons-move-status{font-weight:800;color:#2563eb}.evapp-ticket-cons-move.is-reversed .evapp-ticket-cons-move-status{color:#64748b}.evapp-ticket-cons-move-items{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.evapp-ticket-cons-move-item{padding:5px 8px;border-radius:999px;background:#fff;border:1px solid #dbe7f3;font-size:11px;font-weight:750;color:#334155}.evapp-ticket-cons-no-moves{font-size:12px;color:#94a3b8;padding:7px 0}@media(max-width:520px){.evapp-ticket-consumables-history{margin:0 18px 22px}.evapp-ticket-cons-move-head{display:block}.evapp-ticket-cons-move-status{display:block;margin-top:3px}}
            </style>
            <h2 id="evapp-ticket-consumables-history-title">Movimientos de mi inventario</h2>
            <p>Historial de consumos registrados para este ticket, organizado por día del evento.</p>
            <?php foreach ( $event_days as $day ): ?>
                <div class="evapp-ticket-cons-day">
                    <h3><?php echo esc_html(date_i18n('l, d \d\e F \d\e Y', strtotime($day))); ?></h3>
                    <?php if ( ! empty($by_day[$day]) ): ?>
                        <?php foreach ( $by_day[$day] as $tx ): ?>
                            <div class="evapp-ticket-cons-move <?php echo !empty($tx['reversed']) ? 'is-reversed' : ''; ?>">
                                <div class="evapp-ticket-cons-move-head"><span><?php echo esc_html(date_i18n('H:i', strtotime($tx['created_at']))); ?></span><span class="evapp-ticket-cons-move-status"><?php echo !empty($tx['reversed']) ? 'Anulada · recursos restablecidos' : 'Consumo registrado'; ?></span></div>
                                <div class="evapp-ticket-cons-move-items"><?php foreach ( $tx['lines'] as $line ): ?><span class="evapp-ticket-cons-move-item"><?php echo (int)absint($line['quantity'] ?? 0); ?> × <?php echo esc_html($line['item_name'] ?? 'Consumible'); ?></span><?php endforeach; ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?><div class="evapp-ticket-cons-no-moves">Sin movimientos registrados este día.</div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
        <?php return ob_get_clean();
    }
}

if ( ! function_exists('eventosapp_consumables_tx_append_public_history') ) {
    function eventosapp_consumables_tx_append_public_history() {
        if ( empty($GLOBALS['eventosapp_consumables_public_inventory_html']) ) return;
        $current = (string) $GLOBALS['eventosapp_consumables_public_inventory_html'];
        if ( strpos($current, 'evapp-ticket-consumables-history') !== false ) return;

        $ticket_id = function_exists('eventosapp_consumables_resolve_public_ticket_request')
            ? absint(eventosapp_consumables_resolve_public_ticket_request())
            : 0;
        if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) return;
        if ( function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id) ) return;
        $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if ( ! $event_id ) return;

        $history = eventosapp_consumables_tx_public_history_html($ticket_id, $event_id);
        if ( $history !== '' ) {
            $GLOBALS['eventosapp_consumables_public_inventory_html'] = $current . $history;
        }
    }
}
add_action('template_redirect', 'eventosapp_consumables_tx_append_public_history', -99);
add_action('admin_post_nopriv_eventosapp_whatsapp_ticket_landing', 'eventosapp_consumables_tx_append_public_history', 1);
add_action('admin_post_eventosapp_whatsapp_ticket_landing', 'eventosapp_consumables_tx_append_public_history', 1);
