<?php
/**
 * EventosApp - Inbox de WhatsApp
 *
 * Módulo independiente para recibir mensajes entrantes desde el webhook de
 * WhatsApp Cloud API y organizarlos como conversaciones/tickets internos.
 *
 * Este archivo NO modifica el flujo de envío masivo ni el envío de tickets por
 * WhatsApp. Solo escucha el hook emitido por eventosapp-whatsapp-ticket.php
 * cuando Meta entrega mensajes entrantes en el webhook.
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! defined('EVENTOSAPP_WHATSAPP_INBOX_TABLE_VERSION') ) {
    define('EVENTOSAPP_WHATSAPP_INBOX_TABLE_VERSION', '2026.05.26.2');
}

if ( ! defined('EVENTOSAPP_WHATSAPP_INBOX_POST_TYPE') ) {
    define('EVENTOSAPP_WHATSAPP_INBOX_POST_TYPE', 'eventosapp_wa_inbox');
}

/**
 * Tabla de mensajes del inbox.
 */
function eventosapp_whatsapp_inbox_messages_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'eventosapp_whatsapp_inbox_messages';
}

/**
 * Crea/actualiza la tabla donde se guardan los mensajes del inbox.
 */
function eventosapp_whatsapp_inbox_install_tables() {
    global $wpdb;

    $table_name = eventosapp_whatsapp_inbox_messages_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        conversation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        ticket_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        wa_message_id VARCHAR(220) NOT NULL DEFAULT '',
        reply_to_message_id VARCHAR(220) NOT NULL DEFAULT '',
        direction VARCHAR(20) NOT NULL DEFAULT 'inbound',
        status VARCHAR(80) NOT NULL DEFAULT '',
        from_phone VARCHAR(80) NOT NULL DEFAULT '',
        to_phone VARCHAR(80) NOT NULL DEFAULT '',
        sender_phone_number_id VARCHAR(80) NOT NULL DEFAULT '',
        display_phone_number VARCHAR(80) NOT NULL DEFAULT '',
        contact_name VARCHAR(190) NOT NULL DEFAULT '',
        message_type VARCHAR(60) NOT NULL DEFAULT '',
        body LONGTEXT NULL,
        media_id VARCHAR(220) NOT NULL DEFAULT '',
        media_mime_type VARCHAR(160) NOT NULL DEFAULT '',
        media_sha256 VARCHAR(220) NOT NULL DEFAULT '',
        media_caption TEXT NULL,
        interactive_json LONGTEXT NULL,
        location_json LONGTEXT NULL,
        raw_json LONGTEXT NULL,
        origin_method VARCHAR(80) NOT NULL DEFAULT '',
        origin_confidence VARCHAR(40) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY wa_message_id (wa_message_id(190)),
        KEY conversation_id (conversation_id),
        KEY event_id (event_id),
        KEY ticket_id (ticket_id),
        KEY direction (direction),
        KEY status (status),
        KEY from_phone (from_phone),
        KEY sender_phone_number_id (sender_phone_number_id),
        KEY created_at (created_at)
    ) {$charset_collate};";

    if ( ! function_exists('dbDelta') ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    dbDelta($sql);
    update_option('eventosapp_whatsapp_inbox_table_version', EVENTOSAPP_WHATSAPP_INBOX_TABLE_VERSION, false);
}

function eventosapp_whatsapp_inbox_maybe_install_tables() {
    if ( get_option('eventosapp_whatsapp_inbox_table_version') !== EVENTOSAPP_WHATSAPP_INBOX_TABLE_VERSION ) {
        eventosapp_whatsapp_inbox_install_tables();
    }
}
add_action('init', 'eventosapp_whatsapp_inbox_maybe_install_tables', 6);

/**
 * CPT interno que representa cada conversación/ticket de inbox.
 */
add_action('init', function() {
    register_post_type(EVENTOSAPP_WHATSAPP_INBOX_POST_TYPE, [
        'labels' => [
            'name'          => 'Inbox WhatsApp',
            'singular_name' => 'Conversación WhatsApp',
        ],
        'public'       => false,
        'show_ui'      => false,
        'show_in_menu' => false,
        'supports'     => ['title'],
        'has_archive'  => false,
        'rewrite'      => false,
        'show_in_rest' => false,
    ]);
}, 8);

/**
 * Estados internos de una conversación.
 */
function eventosapp_whatsapp_inbox_statuses() {
    return [
        'open'     => 'Abierto',
        'pending'  => 'Pendiente',
        'resolved' => 'Resuelto',
        'closed'   => 'Cerrado',
    ];
}

function eventosapp_whatsapp_inbox_status_label($status) {
    $status = sanitize_key((string) $status);
    $statuses = eventosapp_whatsapp_inbox_statuses();
    return $statuses[$status] ?? 'Abierto';
}

function eventosapp_whatsapp_inbox_clean_phone($phone) {
    return preg_replace('/\D+/', '', (string) $phone);
}

function eventosapp_whatsapp_inbox_datetime_from_timestamp($timestamp) {
    $timestamp = absint($timestamp);
    if ( ! $timestamp ) {
        return current_time('mysql');
    }
    return get_date_from_gmt(gmdate('Y-m-d H:i:s', $timestamp), 'Y-m-d H:i:s');
}

function eventosapp_whatsapp_inbox_safe_json($value) {
    if ( function_exists('eventosapp_whatsapp_sanitize_log_context') ) {
        $value = eventosapp_whatsapp_sanitize_log_context($value);
    }
    $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '';
}

function eventosapp_whatsapp_inbox_safe_debug_array($value) {
    if ( function_exists('eventosapp_whatsapp_sanitize_log_context') ) {
        $value = eventosapp_whatsapp_sanitize_log_context($value);
    }
    return is_array($value) ? $value : [];
}

function eventosapp_whatsapp_inbox_truncate($text, $length = 140) {
    $text = trim(wp_strip_all_tags((string) $text));
    $length = max(20, absint($length));
    if ( function_exists('mb_strlen') && function_exists('mb_substr') ) {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
    }
    return strlen($text) > $length ? substr($text, 0, $length) . '…' : $text;
}

/**
 * Extrae el nombre del contacto recibido en el webhook.
 */
function eventosapp_whatsapp_inbox_extract_contact_name($from_phone, $value) {
    $from_phone = eventosapp_whatsapp_inbox_clean_phone($from_phone);
    $contacts = isset($value['contacts']) && is_array($value['contacts']) ? $value['contacts'] : [];

    foreach ( $contacts as $contact ) {
        if ( ! is_array($contact) ) {
            continue;
        }
        $wa_id = eventosapp_whatsapp_inbox_clean_phone($contact['wa_id'] ?? '');
        if ( $wa_id !== '' && $from_phone !== '' && $wa_id !== $from_phone ) {
            continue;
        }
        $name = $contact['profile']['name'] ?? '';
        $name = sanitize_text_field((string) $name);
        if ( $name !== '' ) {
            return $name;
        }
    }

    return '';
}

/**
 * Convierte un payload de mensaje de Meta en campos legibles para el inbox.
 */
function eventosapp_whatsapp_inbox_extract_message_parts($message) {
    $type = sanitize_key((string)($message['type'] ?? 'unknown'));
    $body = '';
    $media_id = '';
    $media_mime_type = '';
    $media_sha256 = '';
    $media_caption = '';
    $interactive_json = '';
    $location_json = '';

    if ( $type === 'text' && isset($message['text']['body']) ) {
        $body = sanitize_textarea_field((string) $message['text']['body']);
    } elseif ( $type === 'button' ) {
        $button_text = sanitize_text_field((string)($message['button']['text'] ?? ''));
        $button_payload = sanitize_text_field((string)($message['button']['payload'] ?? ''));
        $body = trim($button_text . ($button_payload !== '' ? ' — ' . $button_payload : ''));
    } elseif ( $type === 'interactive' && ! empty($message['interactive']) && is_array($message['interactive']) ) {
        $interactive = $message['interactive'];
        $interactive_type = sanitize_key((string)($interactive['type'] ?? ''));
        if ( $interactive_type === 'button_reply' ) {
            $reply = $interactive['button_reply'] ?? [];
            $body = sanitize_text_field((string)($reply['title'] ?? ''));
            $reply_id = sanitize_text_field((string)($reply['id'] ?? ''));
            if ( $reply_id !== '' ) {
                $body .= $body !== '' ? ' — ID: ' . $reply_id : 'ID: ' . $reply_id;
            }
        } elseif ( $interactive_type === 'list_reply' ) {
            $reply = $interactive['list_reply'] ?? [];
            $body = sanitize_text_field((string)($reply['title'] ?? ''));
            $description = sanitize_text_field((string)($reply['description'] ?? ''));
            $reply_id = sanitize_text_field((string)($reply['id'] ?? ''));
            if ( $description !== '' ) {
                $body .= $body !== '' ? ' — ' . $description : $description;
            }
            if ( $reply_id !== '' ) {
                $body .= $body !== '' ? ' — ID: ' . $reply_id : 'ID: ' . $reply_id;
            }
        }
        $interactive_json = eventosapp_whatsapp_inbox_safe_json($interactive);
    } elseif ( in_array($type, ['image', 'video', 'audio', 'document', 'sticker'], true) ) {
        $media = isset($message[$type]) && is_array($message[$type]) ? $message[$type] : [];
        $media_id = sanitize_text_field((string)($media['id'] ?? ''));
        $media_mime_type = sanitize_text_field((string)($media['mime_type'] ?? ''));
        $media_sha256 = sanitize_text_field((string)($media['sha256'] ?? ''));
        $media_caption = sanitize_textarea_field((string)($media['caption'] ?? ''));
        $filename = sanitize_text_field((string)($media['filename'] ?? ''));
        $body = $media_caption !== '' ? $media_caption : 'Mensaje recibido con archivo de tipo ' . $type;
        if ( $filename !== '' ) {
            $body .= ' — Archivo: ' . $filename;
        }
    } elseif ( $type === 'location' && ! empty($message['location']) && is_array($message['location']) ) {
        $location = $message['location'];
        $name = sanitize_text_field((string)($location['name'] ?? ''));
        $address = sanitize_text_field((string)($location['address'] ?? ''));
        $lat = sanitize_text_field((string)($location['latitude'] ?? ''));
        $lng = sanitize_text_field((string)($location['longitude'] ?? ''));
        $body = trim('Ubicación recibida' . ($name !== '' ? ': ' . $name : '') . ($address !== '' ? ' — ' . $address : '') . ($lat !== '' && $lng !== '' ? ' (' . $lat . ', ' . $lng . ')' : ''));
        $location_json = eventosapp_whatsapp_inbox_safe_json($location);
    } elseif ( $type === 'contacts' ) {
        $body = 'Contacto compartido por WhatsApp.';
    } elseif ( $type === 'reaction' && ! empty($message['reaction']) && is_array($message['reaction']) ) {
        $emoji = sanitize_text_field((string)($message['reaction']['emoji'] ?? ''));
        $body = $emoji !== '' ? 'Reacción recibida: ' . $emoji : 'Reacción recibida.';
    } elseif ( $type === 'unsupported' || ! empty($message['errors']) ) {
        $body = 'Mensaje recibido, pero Meta lo marcó como no soportado.';
    }

    if ( $body === '' ) {
        $body = 'Mensaje recibido de tipo ' . ($type ?: 'desconocido') . '.';
    }

    return [
        'type'             => $type ?: 'unknown',
        'body'             => $body,
        'media_id'         => $media_id,
        'media_mime_type'  => $media_mime_type,
        'media_sha256'     => $media_sha256,
        'media_caption'    => $media_caption,
        'interactive_json' => $interactive_json,
        'location_json'    => $location_json,
    ];
}

function eventosapp_whatsapp_inbox_table_exists($table_name) {
    global $wpdb;
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
    return $found === $table_name;
}

function eventosapp_whatsapp_inbox_count_messages() {
    global $wpdb;
    $table = eventosapp_whatsapp_inbox_messages_table_name();
    if ( ! eventosapp_whatsapp_inbox_table_exists($table) ) {
        return 0;
    }
    return absint($wpdb->get_var("SELECT COUNT(*) FROM {$table}"));
}

function eventosapp_whatsapp_inbox_count_conversations() {
    return absint(wp_count_posts(EVENTOSAPP_WHATSAPP_INBOX_POST_TYPE)->publish ?? 0);
}

function eventosapp_whatsapp_inbox_get_last_message_debug() {
    global $wpdb;
    $table = eventosapp_whatsapp_inbox_messages_table_name();
    if ( ! eventosapp_whatsapp_inbox_table_exists($table) ) {
        return [];
    }
    $row = $wpdb->get_row("SELECT id, conversation_id, event_id, ticket_id, wa_message_id, direction, status, from_phone, to_phone, sender_phone_number_id, message_type, body, created_at FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A);
    return is_array($row) ? $row : [];
}

function eventosapp_whatsapp_inbox_render_local_test_form($source = 'inbox') {
    if ( ! current_user_can('manage_options') ) {
        return;
    }

    $settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];
    $test_phone = sanitize_text_field((string)($settings['test_phone'] ?? ''));
    $sender_phone_number_id = function_exists('eventosapp_whatsapp_sanitize_phone_number_id')
        ? eventosapp_whatsapp_sanitize_phone_number_id($settings['test_phone_number_id'] ?? ($settings['phone_number_id'] ?? ''))
        : eventosapp_whatsapp_inbox_clean_phone($settings['test_phone_number_id'] ?? ($settings['phone_number_id'] ?? ''));
    if ( $sender_phone_number_id === '' ) {
        $sender_phone_number_id = eventosapp_whatsapp_inbox_clean_phone($settings['phone_number_id'] ?? '');
    }
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:0;">
        <?php wp_nonce_field('eventosapp_whatsapp_inbox_local_test', 'eventosapp_whatsapp_inbox_local_test_nonce'); ?>
        <input type="hidden" name="action" value="eventosapp_whatsapp_inbox_local_test">
        <input type="hidden" name="source" value="<?php echo esc_attr($source); ?>">
        <input type="hidden" name="from_phone" value="<?php echo esc_attr($test_phone); ?>">
        <input type="hidden" name="sender_phone_number_id" value="<?php echo esc_attr($sender_phone_number_id); ?>">
        <?php submit_button('Crear inbound de prueba local', 'secondary', 'submit', false); ?>
    </form>
    <?php
}

/**
 * Resuelve el evento/ticket origen usando primero el mensaje respondido y luego
 * el último mensaje saliente enviado a ese usuario desde el número emisor.
 */
function eventosapp_whatsapp_inbox_resolve_origin($from_phone, $sender_phone_number_id = '', $reply_to_message_id = '', $created_at = '') {
    global $wpdb;

    $from_phone = eventosapp_whatsapp_inbox_clean_phone($from_phone);
    $sender_phone_number_id = eventosapp_whatsapp_inbox_clean_phone($sender_phone_number_id);
    $reply_to_message_id = sanitize_text_field((string) $reply_to_message_id);
    $origin = [
        'event_id'          => 0,
        'ticket_id'         => 0,
        'method'            => 'sin_origen',
        'confidence'        => 'none',
        'matched_message_id'=> '',
        'matched_context'   => '',
    ];

    if ( $reply_to_message_id !== '' && function_exists('eventosapp_whatsapp_get_message_map') ) {
        $map = eventosapp_whatsapp_get_message_map();
        if ( isset($map[$reply_to_message_id]) && is_array($map[$reply_to_message_id]) ) {
            $mapped = $map[$reply_to_message_id];
            $ticket_id = absint($mapped['ticket_id'] ?? 0);
            $event_id = $ticket_id ? absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true)) : 0;
            $origin = [
                'event_id'           => $event_id,
                'ticket_id'          => $ticket_id,
                'method'             => 'respuesta_a_mensaje_mapeado',
                'confidence'         => $ticket_id ? 'high' : 'medium',
                'matched_message_id' => $reply_to_message_id,
                'matched_context'    => sanitize_text_field((string)($mapped['context'] ?? '')),
            ];
            if ( $ticket_id || $event_id ) {
                return $origin;
            }
        }
    }

    if ( function_exists('eventosapp_whatsapp_log_table_name') ) {
        $log_table = eventosapp_whatsapp_log_table_name();
        if ( eventosapp_whatsapp_inbox_table_exists($log_table) ) {
            if ( $reply_to_message_id !== '' ) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT event_id, ticket_id, context, message_id FROM {$log_table} WHERE message_id = %s ORDER BY id DESC LIMIT 1",
                    $reply_to_message_id
                ), ARRAY_A);

                if ( is_array($row) ) {
                    $ticket_id = absint($row['ticket_id'] ?? 0);
                    $event_id = absint($row['event_id'] ?? 0);
                    if ( ! $event_id && $ticket_id ) {
                        $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
                    }
                    $origin = [
                        'event_id'           => $event_id,
                        'ticket_id'          => $ticket_id,
                        'method'             => 'respuesta_a_log_central',
                        'confidence'         => ($ticket_id || $event_id) ? 'high' : 'medium',
                        'matched_message_id' => sanitize_text_field((string)($row['message_id'] ?? $reply_to_message_id)),
                        'matched_context'    => sanitize_text_field((string)($row['context'] ?? '')),
                    ];
                    if ( $ticket_id || $event_id ) {
                        return $origin;
                    }
                }
            }

            $where = ['recipient = %s', 'ticket_id > 0'];
            $params = [$from_phone];
            if ( $sender_phone_number_id !== '' ) {
                $where[] = 'sender_phone_number_id = %s';
                $params[] = $sender_phone_number_id;
            }
            if ( $created_at !== '' ) {
                $where[] = 'created_at <= %s';
                $params[] = $created_at;
            }

            $sql = "SELECT event_id, ticket_id, context, message_id, created_at FROM {$log_table} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC, id DESC LIMIT 1";
            $row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);

            if ( is_array($row) ) {
                $ticket_id = absint($row['ticket_id'] ?? 0);
                $event_id = absint($row['event_id'] ?? 0);
                if ( ! $event_id && $ticket_id ) {
                    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
                }
                if ( $ticket_id || $event_id ) {
                    return [
                        'event_id'           => $event_id,
                        'ticket_id'          => $ticket_id,
                        'method'             => 'ultimo_mensaje_saliente_al_usuario',
                        'confidence'         => 'medium',
                        'matched_message_id' => sanitize_text_field((string)($row['message_id'] ?? '')),
                        'matched_context'    => sanitize_text_field((string)($row['context'] ?? '')),
                    ];
                }
            }
        }
    }

    $existing = eventosapp_whatsapp_inbox_find_latest_conversation_by_phone($from_phone, $sender_phone_number_id);
    if ( $existing ) {
        $ticket_id = absint(get_post_meta($existing, '_evapp_wa_inbox_ticket_id', true));
        $event_id = absint(get_post_meta($existing, '_evapp_wa_inbox_event_id', true));
        if ( $ticket_id || $event_id ) {
            return [
                'event_id'           => $event_id,
                'ticket_id'          => $ticket_id,
                'method'             => 'conversacion_abierta_previa',
                'confidence'         => 'medium',
                'matched_message_id' => '',
                'matched_context'    => '',
            ];
        }
    }

    return $origin;
}

function eventosapp_whatsapp_inbox_find_latest_conversation_by_phone($from_phone, $sender_phone_number_id = '') {
    $from_phone = eventosapp_whatsapp_inbox_clean_phone($from_phone);
    $sender_phone_number_id = eventosapp_whatsapp_inbox_clean_phone($sender_phone_number_id);
    if ( $from_phone === '' ) {
        return 0;
    }

    $meta_query = [
        'relation' => 'AND',
        [
            'key'   => '_evapp_wa_inbox_from_phone',
            'value' => $from_phone,
        ],
    ];

    if ( $sender_phone_number_id !== '' ) {
        $meta_query[] = [
            'key'   => '_evapp_wa_inbox_sender_phone_number_id',
            'value' => $sender_phone_number_id,
        ];
    }

    $posts = get_posts([
        'post_type'      => EVENTOSAPP_WHATSAPP_INBOX_POST_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'orderby'        => 'modified',
        'order'          => 'DESC',
        'fields'         => 'ids',
        'meta_query'     => $meta_query,
    ]);

    return ! empty($posts[0]) ? absint($posts[0]) : 0;
}

function eventosapp_whatsapp_inbox_find_open_conversation($from_phone, $sender_phone_number_id = '', $event_id = 0, $ticket_id = 0) {
    $from_phone = eventosapp_whatsapp_inbox_clean_phone($from_phone);
    $sender_phone_number_id = eventosapp_whatsapp_inbox_clean_phone($sender_phone_number_id);
    $event_id = absint($event_id);
    $ticket_id = absint($ticket_id);

    if ( $from_phone === '' ) {
        return 0;
    }

    $base_meta = [
        'relation' => 'AND',
        [
            'key'   => '_evapp_wa_inbox_from_phone',
            'value' => $from_phone,
        ],
        [
            'key'     => '_evapp_wa_inbox_status',
            'value'   => ['open', 'pending'],
            'compare' => 'IN',
        ],
    ];

    if ( $sender_phone_number_id !== '' ) {
        $base_meta[] = [
            'key'   => '_evapp_wa_inbox_sender_phone_number_id',
            'value' => $sender_phone_number_id,
        ];
    }

    $queries = [];
    if ( $ticket_id ) {
        $meta = $base_meta;
        $meta[] = [
            'key'   => '_evapp_wa_inbox_ticket_id',
            'value' => (string) $ticket_id,
        ];
        $queries[] = $meta;
    }
    if ( $event_id ) {
        $meta = $base_meta;
        $meta[] = [
            'key'   => '_evapp_wa_inbox_event_id',
            'value' => (string) $event_id,
        ];
        $queries[] = $meta;
    }
    $queries[] = $base_meta;

    foreach ( $queries as $meta_query ) {
        $posts = get_posts([
            'post_type'      => EVENTOSAPP_WHATSAPP_INBOX_POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'meta_query'     => $meta_query,
        ]);
        if ( ! empty($posts[0]) ) {
            return absint($posts[0]);
        }
    }

    return 0;
}

function eventosapp_whatsapp_inbox_build_conversation_title($conversation_id, $from_phone, $contact_name = '', $event_id = 0) {
    $label = $contact_name !== '' ? $contact_name : $from_phone;
    $event_title = $event_id ? get_the_title($event_id) : '';
    $title = 'WA Inbox #' . absint($conversation_id) . ' — ' . $label;
    if ( $event_title !== '' ) {
        $title .= ' — ' . $event_title;
    }
    return sanitize_text_field($title);
}

function eventosapp_whatsapp_inbox_get_or_create_conversation($args) {
    $from_phone = eventosapp_whatsapp_inbox_clean_phone($args['from_phone'] ?? '');
    $sender_phone_number_id = eventosapp_whatsapp_inbox_clean_phone($args['sender_phone_number_id'] ?? '');
    $display_phone_number = sanitize_text_field((string)($args['display_phone_number'] ?? ''));
    $contact_name = sanitize_text_field((string)($args['contact_name'] ?? ''));
    $event_id = absint($args['event_id'] ?? 0);
    $ticket_id = absint($args['ticket_id'] ?? 0);
    $origin_method = sanitize_text_field((string)($args['origin_method'] ?? ''));
    $origin_confidence = sanitize_text_field((string)($args['origin_confidence'] ?? ''));

    $conversation_id = eventosapp_whatsapp_inbox_find_open_conversation($from_phone, $sender_phone_number_id, $event_id, $ticket_id);

    if ( ! $conversation_id ) {
        $conversation_id = wp_insert_post([
            'post_type'   => EVENTOSAPP_WHATSAPP_INBOX_POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => 'WA Inbox — ' . ($contact_name !== '' ? $contact_name : $from_phone),
        ], true);

        if ( is_wp_error($conversation_id) || ! $conversation_id ) {
            return 0;
        }

        update_post_meta($conversation_id, '_evapp_wa_inbox_status', 'open');
        update_post_meta($conversation_id, '_evapp_wa_inbox_created_at', current_time('mysql'));
        update_post_meta($conversation_id, '_evapp_wa_inbox_unread_count', 0);
        update_post_meta($conversation_id, '_evapp_wa_inbox_message_count', 0);
    }

    update_post_meta($conversation_id, '_evapp_wa_inbox_from_phone', $from_phone);
    update_post_meta($conversation_id, '_evapp_wa_inbox_sender_phone_number_id', $sender_phone_number_id);
    update_post_meta($conversation_id, '_evapp_wa_inbox_display_phone_number', $display_phone_number);

    if ( $contact_name !== '' ) {
        update_post_meta($conversation_id, '_evapp_wa_inbox_contact_name', $contact_name);
    }
    if ( $event_id ) {
        update_post_meta($conversation_id, '_evapp_wa_inbox_event_id', $event_id);
    }
    if ( $ticket_id ) {
        update_post_meta($conversation_id, '_evapp_wa_inbox_ticket_id', $ticket_id);
    }
    if ( $origin_method !== '' ) {
        update_post_meta($conversation_id, '_evapp_wa_inbox_origin_method', $origin_method);
    }
    if ( $origin_confidence !== '' ) {
        update_post_meta($conversation_id, '_evapp_wa_inbox_origin_confidence', $origin_confidence);
    }

    wp_update_post([
        'ID'         => $conversation_id,
        'post_title' => eventosapp_whatsapp_inbox_build_conversation_title($conversation_id, $from_phone, $contact_name, $event_id),
    ]);

    return absint($conversation_id);
}

function eventosapp_whatsapp_inbox_message_exists($wa_message_id) {
    global $wpdb;
    $wa_message_id = sanitize_text_field((string) $wa_message_id);
    if ( $wa_message_id === '' ) {
        return 0;
    }
    $table = eventosapp_whatsapp_inbox_messages_table_name();
    eventosapp_whatsapp_inbox_maybe_install_tables();
    return absint($wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE wa_message_id = %s LIMIT 1", $wa_message_id)));
}

function eventosapp_whatsapp_inbox_insert_message($data) {
    global $wpdb;

    eventosapp_whatsapp_inbox_maybe_install_tables();
    $table = eventosapp_whatsapp_inbox_messages_table_name();

    $wa_message_id = sanitize_text_field((string)($data['wa_message_id'] ?? ''));
    if ( $wa_message_id !== '' ) {
        $existing_id = eventosapp_whatsapp_inbox_message_exists($wa_message_id);
        if ( $existing_id ) {
            return $existing_id;
        }
    }

    $row = [
        'conversation_id'        => absint($data['conversation_id'] ?? 0),
        'event_id'               => absint($data['event_id'] ?? 0),
        'ticket_id'              => absint($data['ticket_id'] ?? 0),
        'wa_message_id'          => $wa_message_id,
        'reply_to_message_id'    => sanitize_text_field((string)($data['reply_to_message_id'] ?? '')),
        'direction'              => sanitize_key((string)($data['direction'] ?? 'inbound')),
        'status'                 => sanitize_text_field((string)($data['status'] ?? 'received')),
        'from_phone'             => eventosapp_whatsapp_inbox_clean_phone($data['from_phone'] ?? ''),
        'to_phone'               => eventosapp_whatsapp_inbox_clean_phone($data['to_phone'] ?? ''),
        'sender_phone_number_id' => eventosapp_whatsapp_inbox_clean_phone($data['sender_phone_number_id'] ?? ''),
        'display_phone_number'   => sanitize_text_field((string)($data['display_phone_number'] ?? '')),
        'contact_name'           => sanitize_text_field((string)($data['contact_name'] ?? '')),
        'message_type'           => sanitize_key((string)($data['message_type'] ?? 'unknown')),
        'body'                   => sanitize_textarea_field((string)($data['body'] ?? '')),
        'media_id'               => sanitize_text_field((string)($data['media_id'] ?? '')),
        'media_mime_type'        => sanitize_text_field((string)($data['media_mime_type'] ?? '')),
        'media_sha256'           => sanitize_text_field((string)($data['media_sha256'] ?? '')),
        'media_caption'          => sanitize_textarea_field((string)($data['media_caption'] ?? '')),
        'interactive_json'       => (string)($data['interactive_json'] ?? ''),
        'location_json'          => (string)($data['location_json'] ?? ''),
        'raw_json'               => (string)($data['raw_json'] ?? ''),
        'origin_method'          => sanitize_text_field((string)($data['origin_method'] ?? '')),
        'origin_confidence'      => sanitize_text_field((string)($data['origin_confidence'] ?? '')),
        'created_at'             => sanitize_text_field((string)($data['created_at'] ?? current_time('mysql'))),
    ];

    $formats = ['%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'];
    $inserted = $wpdb->insert($table, $row, $formats);

    return $inserted !== false ? absint($wpdb->insert_id) : 0;
}

function eventosapp_whatsapp_inbox_update_conversation_after_message($conversation_id, $message_id, $direction, $preview, $created_at, $event_id = 0, $ticket_id = 0) {
    $conversation_id = absint($conversation_id);
    if ( ! $conversation_id ) {
        return;
    }

    $direction = sanitize_key((string) $direction);
    $preview = eventosapp_whatsapp_inbox_truncate($preview, 180);
    $created_at = sanitize_text_field((string) $created_at);

    $unread = absint(get_post_meta($conversation_id, '_evapp_wa_inbox_unread_count', true));
    if ( $direction === 'inbound' ) {
        $unread++;
    }

    $count = absint(get_post_meta($conversation_id, '_evapp_wa_inbox_message_count', true));
    $count++;

    update_post_meta($conversation_id, '_evapp_wa_inbox_unread_count', $unread);
    update_post_meta($conversation_id, '_evapp_wa_inbox_message_count', $count);
    update_post_meta($conversation_id, '_evapp_wa_inbox_last_message_id', absint($message_id));
    update_post_meta($conversation_id, '_evapp_wa_inbox_last_message_direction', $direction);
    update_post_meta($conversation_id, '_evapp_wa_inbox_last_message_preview', $preview);
    update_post_meta($conversation_id, '_evapp_wa_inbox_last_message_at', $created_at);
    update_post_meta($conversation_id, '_evapp_wa_inbox_last_activity_at', $created_at);

    if ( $event_id ) {
        update_post_meta($conversation_id, '_evapp_wa_inbox_event_id', absint($event_id));
    }
    if ( $ticket_id ) {
        update_post_meta($conversation_id, '_evapp_wa_inbox_ticket_id', absint($ticket_id));
    }

    wp_update_post([
        'ID'                => $conversation_id,
        'post_modified'     => current_time('mysql'),
        'post_modified_gmt' => current_time('mysql', true),
    ]);
}

/**
 * Procesa mensajes entrantes recibidos desde el webhook principal de WhatsApp.
 */
add_action('eventosapp_whatsapp_webhook_inbound_message_received', 'eventosapp_whatsapp_inbox_handle_inbound_message', 10, 5);
function eventosapp_whatsapp_inbox_handle_inbound_message($message, $value = [], $entry = [], $change = [], $payload = []) {
    if ( ! is_array($message) ) {
        return;
    }

    $from_phone = eventosapp_whatsapp_inbox_clean_phone($message['from'] ?? '');
    $wa_message_id = sanitize_text_field((string)($message['id'] ?? ''));
    if ( $from_phone === '' || $wa_message_id === '' ) {
        return;
    }

    if ( eventosapp_whatsapp_inbox_message_exists($wa_message_id) ) {
        return;
    }

    $value = is_array($value) ? $value : [];
    $metadata = isset($value['metadata']) && is_array($value['metadata']) ? $value['metadata'] : [];
    $sender_phone_number_id = eventosapp_whatsapp_inbox_clean_phone($metadata['phone_number_id'] ?? '');
    $display_phone_number = sanitize_text_field((string)($metadata['display_phone_number'] ?? ''));
    $contact_name = eventosapp_whatsapp_inbox_extract_contact_name($from_phone, $value);
    $reply_to_message_id = sanitize_text_field((string)($message['context']['id'] ?? ''));
    $created_at = eventosapp_whatsapp_inbox_datetime_from_timestamp($message['timestamp'] ?? 0);
    $parts = eventosapp_whatsapp_inbox_extract_message_parts($message);

    $origin = eventosapp_whatsapp_inbox_resolve_origin($from_phone, $sender_phone_number_id, $reply_to_message_id, $created_at);
    $event_id = absint($origin['event_id'] ?? 0);
    $ticket_id = absint($origin['ticket_id'] ?? 0);

    $conversation_id = eventosapp_whatsapp_inbox_get_or_create_conversation([
        'from_phone'              => $from_phone,
        'sender_phone_number_id'  => $sender_phone_number_id,
        'display_phone_number'    => $display_phone_number,
        'contact_name'            => $contact_name,
        'event_id'                => $event_id,
        'ticket_id'               => $ticket_id,
        'origin_method'           => $origin['method'] ?? '',
        'origin_confidence'       => $origin['confidence'] ?? '',
    ]);

    if ( ! $conversation_id ) {
        if ( function_exists('eventosapp_whatsapp_add_activity_log') ) {
            eventosapp_whatsapp_add_activity_log('inbox_conversacion_no_creada', [
                'from'       => $from_phone,
                'message_id' => $wa_message_id,
            ]);
        }
        return;
    }

    $message_db_id = eventosapp_whatsapp_inbox_insert_message([
        'conversation_id'        => $conversation_id,
        'event_id'               => $event_id,
        'ticket_id'              => $ticket_id,
        'wa_message_id'          => $wa_message_id,
        'reply_to_message_id'    => $reply_to_message_id,
        'direction'              => 'inbound',
        'status'                 => 'received',
        'from_phone'             => $from_phone,
        'to_phone'               => $display_phone_number,
        'sender_phone_number_id' => $sender_phone_number_id,
        'display_phone_number'   => $display_phone_number,
        'contact_name'           => $contact_name,
        'message_type'           => $parts['type'],
        'body'                   => $parts['body'],
        'media_id'               => $parts['media_id'],
        'media_mime_type'        => $parts['media_mime_type'],
        'media_sha256'           => $parts['media_sha256'],
        'media_caption'          => $parts['media_caption'],
        'interactive_json'       => $parts['interactive_json'],
        'location_json'          => $parts['location_json'],
        'raw_json'               => eventosapp_whatsapp_inbox_safe_json([
            'message' => $message,
            'metadata' => $metadata,
            'contacts' => $value['contacts'] ?? [],
        ]),
        'origin_method'          => $origin['method'] ?? '',
        'origin_confidence'      => $origin['confidence'] ?? '',
        'created_at'             => $created_at,
    ]);

    if ( $message_db_id ) {
        eventosapp_whatsapp_inbox_update_conversation_after_message($conversation_id, $message_db_id, 'inbound', $parts['body'], $created_at, $event_id, $ticket_id);

        update_option('eventosapp_whatsapp_inbox_last_processed_message', eventosapp_whatsapp_inbox_safe_debug_array([
            'processed_at' => current_time('mysql'),
            'conversation_id' => $conversation_id,
            'inbox_message_id' => $message_db_id,
            'from_phone' => $from_phone,
            'wa_message_id' => $wa_message_id,
            'message_type' => $parts['type'],
            'event_id' => $event_id,
            'ticket_id' => $ticket_id,
            'reply_to_message_id' => $reply_to_message_id,
            'origin' => $origin,
        ]), false);

        if ( function_exists('eventosapp_whatsapp_insert_central_log') ) {
            eventosapp_whatsapp_insert_central_log([
                'created_at'             => $created_at,
                'event_id'               => $event_id,
                'ticket_id'              => $ticket_id,
                'recipient'              => $from_phone,
                'channel'                => 'inbox',
                'context'                => 'inbox_inbound',
                'status'                 => 'mensaje_entrante',
                'delivery_status'        => 'received',
                'message_id'             => $wa_message_id,
                'source_key'             => 'inbox:' . $conversation_id . ':' . $message_db_id,
                'sender_phone_number_id' => $sender_phone_number_id,
                'sender_label'           => $display_phone_number,
                'transport'              => 'webhook',
                'http_code'              => 0,
                'message'                => $parts['body'],
                'meta'                   => [
                    'conversation_id' => $conversation_id,
                    'inbox_message_id' => $message_db_id,
                    'reply_to_message_id' => $reply_to_message_id,
                    'origin' => $origin,
                ],
            ]);
        }

        if ( function_exists('eventosapp_whatsapp_add_activity_log') ) {
            eventosapp_whatsapp_add_activity_log('inbox_mensaje_entrante_registrado', [
                'conversation_id' => $conversation_id,
                'inbox_message_id' => $message_db_id,
                'from' => $from_phone,
                'message_id' => $wa_message_id,
                'event_id' => $event_id,
                'ticket_id' => $ticket_id,
                'origin' => $origin,
            ]);
        }
    } else {
        update_option('eventosapp_whatsapp_inbox_last_processed_message', eventosapp_whatsapp_inbox_safe_debug_array([
            'processed_at' => current_time('mysql'),
            'error' => 'No se pudo insertar el mensaje en la tabla del inbox.',
            'conversation_id' => $conversation_id,
            'from_phone' => $from_phone,
            'wa_message_id' => $wa_message_id,
            'event_id' => $event_id,
            'ticket_id' => $ticket_id,
        ]), false);
        if ( function_exists('eventosapp_whatsapp_add_activity_log') ) {
            eventosapp_whatsapp_add_activity_log('inbox_mensaje_no_insertado', [
                'conversation_id' => $conversation_id,
                'from' => $from_phone,
                'message_id' => $wa_message_id,
            ]);
        }
    }
}

/**
 * Menú administrativo.
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'eventosapp_dashboard',
        'Inbox WhatsApp',
        'Inbox WhatsApp',
        'manage_options',
        'eventosapp_whatsapp_inbox',
        'eventosapp_whatsapp_inbox_render_page',
        23
    );
}, 21);

function eventosapp_whatsapp_inbox_get_events_for_filter() {
    return get_posts([
        'post_type'      => 'eventosapp_event',
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => 200,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'fields'         => 'ids',
    ]);
}

function eventosapp_whatsapp_inbox_get_messages($conversation_id, $limit = 200) {
    global $wpdb;
    $conversation_id = absint($conversation_id);
    if ( ! $conversation_id ) {
        return [];
    }
    eventosapp_whatsapp_inbox_maybe_install_tables();
    $table = eventosapp_whatsapp_inbox_messages_table_name();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY created_at ASC, id ASC LIMIT %d",
        $conversation_id,
        max(1, absint($limit))
    ), ARRAY_A);
}

function eventosapp_whatsapp_inbox_find_conversations_by_message_search($search) {
    global $wpdb;
    $search = trim((string) $search);
    if ( $search === '' ) {
        return [];
    }
    eventosapp_whatsapp_inbox_maybe_install_tables();
    $table = eventosapp_whatsapp_inbox_messages_table_name();
    $like = '%' . $wpdb->esc_like($search) . '%';
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT conversation_id FROM {$table} WHERE body LIKE %s OR wa_message_id LIKE %s OR from_phone LIKE %s OR contact_name LIKE %s LIMIT 300",
        $like,
        $like,
        $like,
        $like
    ));
    return array_map('absint', is_array($ids) ? $ids : []);
}

function eventosapp_whatsapp_inbox_render_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes para acceder al Inbox de WhatsApp.');
    }

    $conversation_id = isset($_GET['conversation_id']) ? absint($_GET['conversation_id']) : 0;
    if ( $conversation_id ) {
        eventosapp_whatsapp_inbox_render_conversation($conversation_id);
        return;
    }

    eventosapp_whatsapp_inbox_render_list();
}

function eventosapp_whatsapp_inbox_render_notices() {
    if ( isset($_GET['evapp_wa_inbox_saved']) ) {
        echo '<div class="notice notice-success is-dismissible"><p><strong>EventosApp:</strong> Conversación actualizada.</p></div>';
    }
    if ( isset($_GET['evapp_wa_inbox_reply']) ) {
        $ok = sanitize_text_field(wp_unslash($_GET['evapp_wa_inbox_reply'])) === '1';
        $msg = isset($_GET['evapp_wa_inbox_msg']) ? sanitize_text_field(wp_unslash($_GET['evapp_wa_inbox_msg'])) : ($ok ? 'Respuesta enviada.' : 'No se pudo enviar la respuesta.');
        echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . ' is-dismissible"><p><strong>EventosApp:</strong> ' . esc_html($msg) . '</p></div>';
    }
}

function eventosapp_whatsapp_inbox_render_styles() {
    ?>
    <style>
        .evapp-wa-inbox-wrap .evapp-card{background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;margin:16px 0;}
        .evapp-wa-inbox-filters{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:12px 0 18px;}
        .evapp-wa-inbox-filters label{font-weight:600;display:block;margin-bottom:4px;}
        .evapp-wa-inbox-filters input,.evapp-wa-inbox-filters select{min-width:180px;}
        .evapp-wa-inbox-table{width:100%;border-collapse:collapse;background:#fff;}
        .evapp-wa-inbox-table th,.evapp-wa-inbox-table td{border:1px solid #dcdcde;padding:9px;text-align:left;vertical-align:top;}
        .evapp-wa-inbox-table th{background:#f6f7f7;}
        .evapp-wa-badge{display:inline-block;border-radius:999px;padding:3px 9px;font-size:12px;line-height:1.4;background:#eef2ff;color:#1d3a8a;font-weight:600;}
        .evapp-wa-badge.open{background:#e7f7ed;color:#0a6b2b;}.evapp-wa-badge.pending{background:#fff4d6;color:#7a4b00;}.evapp-wa-badge.resolved{background:#edf7ff;color:#055a8c;}.evapp-wa-badge.closed{background:#f1f1f1;color:#555;}
        .evapp-wa-muted{color:#646970;font-size:12px;}.evapp-wa-break{word-break:break-all;}
        .evapp-wa-thread{display:flex;flex-direction:column;gap:12px;max-width:980px;}
        .evapp-wa-message{border:1px solid #dcdcde;border-radius:10px;padding:10px 12px;max-width:760px;background:#fff;}
        .evapp-wa-message.inbound{align-self:flex-start;background:#fff;}.evapp-wa-message.outbound{align-self:flex-end;background:#f0f6fc;}
        .evapp-wa-message-header{display:flex;justify-content:space-between;gap:12px;margin-bottom:6px;font-size:12px;color:#646970;}
        .evapp-wa-message-body{white-space:pre-wrap;word-break:break-word;font-size:14px;line-height:1.45;}
        .evapp-wa-grid{display:grid;grid-template-columns:210px minmax(260px,1fr);gap:12px 16px;align-items:center;max-width:900px;}
        .evapp-wa-grid label{font-weight:600;}.evapp-wa-grid textarea,.evapp-wa-grid select,.evapp-wa-grid input[type="text"]{width:100%;}
        @media (max-width: 782px){.evapp-wa-grid{grid-template-columns:1fr;}.evapp-wa-message{max-width:100%;}}
    </style>
    <?php
}

function eventosapp_whatsapp_inbox_render_diagnostics_card() {
    $webhook_url = admin_url('admin-post.php?action=eventosapp_whatsapp_webhook');
    $webhook_debug = get_option('eventosapp_whatsapp_last_webhook_debug', []);
    $last_inbound_debug = get_option('eventosapp_whatsapp_last_inbound_debug', []);
    $last_inbox_debug = get_option('eventosapp_whatsapp_inbox_last_processed_message', []);
    $last_message = eventosapp_whatsapp_inbox_get_last_message_debug();
    $last_inbound_by_phone = get_option('eventosapp_whatsapp_last_inbound_by_phone', []);
    $table = eventosapp_whatsapp_inbox_messages_table_name();
    $table_exists = eventosapp_whatsapp_inbox_table_exists($table);
    $settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];
    $effective_waba = function_exists('eventosapp_whatsapp_get_effective_webhook_waba_id') ? eventosapp_whatsapp_get_effective_webhook_waba_id($settings) : '';
    ?>
    <div class="evapp-card">
        <h2>Diagnóstico rápido del Inbox</h2>
        <p class="evapp-wa-muted">
            El inbox no consulta mensajes por cron. WhatsApp Cloud API debe enviar un POST al webhook cuando el usuario responde. Si aquí no aparece un “Último payload webhook recibido”, Meta no está llegando a WordPress.
        </p>
        <table class="evapp-wa-inbox-table">
            <tbody>
                <tr><th>Webhook configurado</th><td><span class="evapp-wa-break"><?php echo esc_html($webhook_url); ?></span></td></tr>
                <tr><th>WABA efectivo</th><td><?php echo esc_html($effective_waba ?: 'Sin WABA ID configurado'); ?></td></tr>
                <tr><th>Tabla del inbox</th><td><?php echo $table_exists ? 'Existe: ' . esc_html($table) : 'No existe todavía: ' . esc_html($table); ?></td></tr>
                <tr><th>Conversaciones / mensajes</th><td><?php echo esc_html((string) eventosapp_whatsapp_inbox_count_conversations()); ?> conversaciones / <?php echo esc_html((string) eventosapp_whatsapp_inbox_count_messages()); ?> mensajes</td></tr>
                <tr><th>Último payload webhook</th><td><?php echo esc_html($webhook_debug['received_at'] ?? 'Nunca registrado'); ?><?php if ( ! empty($webhook_debug['summary']) && function_exists('eventosapp_whatsapp_render_log_details') ) : ?><br><?php eventosapp_whatsapp_render_log_details($webhook_debug['summary']); ?><?php endif; ?></td></tr>
                <tr><th>Último mensaje inbound detectado</th><td><?php if ( function_exists('eventosapp_whatsapp_render_log_details') ) { eventosapp_whatsapp_render_log_details($last_inbound_debug ?: []); } else { echo esc_html(wp_json_encode($last_inbound_debug)); } ?></td></tr>
                <tr><th>Último mensaje guardado por inbox</th><td><?php if ( function_exists('eventosapp_whatsapp_render_log_details') ) { eventosapp_whatsapp_render_log_details($last_inbox_debug ?: []); } else { echo esc_html(wp_json_encode($last_inbox_debug)); } ?></td></tr>
                <tr><th>Último registro en tabla</th><td><?php if ( function_exists('eventosapp_whatsapp_render_log_details') ) { eventosapp_whatsapp_render_log_details($last_message ?: []); } else { echo esc_html(wp_json_encode($last_message)); } ?></td></tr>
                <tr><th>Teléfonos con inbound recibido</th><td><?php echo is_array($last_inbound_by_phone) ? esc_html((string) count($last_inbound_by_phone)) : '0'; ?></td></tr>
            </tbody>
        </table>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
            <?php eventosapp_whatsapp_inbox_render_local_test_form('inbox'); ?>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_tickets')); ?>">Abrir diagnóstico API / WABA</a>
        </div>
    </div>
    <?php
}

function eventosapp_whatsapp_inbox_render_list() {
    $status = isset($_GET['wa_status']) ? sanitize_key(wp_unslash($_GET['wa_status'])) : '';
    $event_id = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;
    $sender_phone_number_id = isset($_GET['sender_phone_number_id']) ? eventosapp_whatsapp_inbox_clean_phone(wp_unslash($_GET['sender_phone_number_id'])) : '';
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

    $meta_query = ['relation' => 'AND'];
    if ( $status !== '' ) {
        $meta_query[] = [
            'key'   => '_evapp_wa_inbox_status',
            'value' => $status,
        ];
    }
    if ( $event_id ) {
        $meta_query[] = [
            'key'   => '_evapp_wa_inbox_event_id',
            'value' => (string) $event_id,
        ];
    }
    if ( $sender_phone_number_id !== '' ) {
        $meta_query[] = [
            'key'   => '_evapp_wa_inbox_sender_phone_number_id',
            'value' => $sender_phone_number_id,
        ];
    }

    $post__in = [];
    if ( $search !== '' ) {
        $post__in = eventosapp_whatsapp_inbox_find_conversations_by_message_search($search);
        if ( empty($post__in) ) {
            $post__in = [0];
        }
    }

    $query_args = [
        'post_type'      => EVENTOSAPP_WHATSAPP_INBOX_POST_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'orderby'        => 'modified',
        'order'          => 'DESC',
        'meta_query'     => count($meta_query) > 1 ? $meta_query : [],
    ];
    if ( ! empty($post__in) ) {
        $query_args['post__in'] = $post__in;
    }

    $conversations = new WP_Query($query_args);
    $accounts = function_exists('eventosapp_whatsapp_get_phone_accounts') ? eventosapp_whatsapp_get_phone_accounts() : [];
    $events = eventosapp_whatsapp_inbox_get_events_for_filter();

    ?>
    <div class="wrap evapp-wa-inbox-wrap">
        <h1>Inbox WhatsApp</h1>
        <p>Mensajes entrantes recibidos desde los números configurados en WhatsApp Cloud API. Cada conversación funciona como un ticket interno de atención.</p>
        <?php eventosapp_whatsapp_inbox_render_notices(); ?>
        <?php eventosapp_whatsapp_inbox_render_styles(); ?>
        <?php eventosapp_whatsapp_inbox_render_diagnostics_card(); ?>

        <form method="get" class="evapp-wa-inbox-filters">
            <input type="hidden" name="page" value="eventosapp_whatsapp_inbox">
            <div>
                <label for="wa_status">Estado</label>
                <select id="wa_status" name="wa_status">
                    <option value="">Todos</option>
                    <?php foreach ( eventosapp_whatsapp_inbox_statuses() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($status, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="event_id">Evento</label>
                <select id="event_id" name="event_id">
                    <option value="0">Todos</option>
                    <?php foreach ( $events as $filter_event_id ) : ?>
                        <option value="<?php echo esc_attr($filter_event_id); ?>" <?php selected($event_id, $filter_event_id); ?>><?php echo esc_html(get_the_title($filter_event_id)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="sender_phone_number_id">Número emisor</label>
                <select id="sender_phone_number_id" name="sender_phone_number_id">
                    <option value="">Todos</option>
                    <?php foreach ( $accounts as $account ) : ?>
                        <option value="<?php echo esc_attr($account['phone_number_id']); ?>" <?php selected($sender_phone_number_id, $account['phone_number_id']); ?>><?php echo esc_html($account['label'] ?? $account['phone_number_id']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="wa_inbox_s">Buscar</label>
                <input type="search" id="wa_inbox_s" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Teléfono, nombre, mensaje o wamid">
            </div>
            <div>
                <button type="submit" class="button button-primary">Filtrar</button>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_inbox')); ?>">Limpiar</a>
            </div>
        </form>

        <table class="evapp-wa-inbox-table">
            <thead>
                <tr>
                    <th>Estado</th>
                    <th>Conversación</th>
                    <th>Evento origen</th>
                    <th>Ticket origen</th>
                    <th>Último mensaje</th>
                    <th>Número emisor</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( $conversations->have_posts() ) : ?>
                    <?php while ( $conversations->have_posts() ) : $conversations->the_post();
                        $conversation_id = get_the_ID();
                        $row_status = get_post_meta($conversation_id, '_evapp_wa_inbox_status', true) ?: 'open';
                        $from_phone = get_post_meta($conversation_id, '_evapp_wa_inbox_from_phone', true);
                        $contact_name = get_post_meta($conversation_id, '_evapp_wa_inbox_contact_name', true);
                        $row_event_id = absint(get_post_meta($conversation_id, '_evapp_wa_inbox_event_id', true));
                        $row_ticket_id = absint(get_post_meta($conversation_id, '_evapp_wa_inbox_ticket_id', true));
                        $last_preview = get_post_meta($conversation_id, '_evapp_wa_inbox_last_message_preview', true);
                        $last_at = get_post_meta($conversation_id, '_evapp_wa_inbox_last_message_at', true);
                        $unread = absint(get_post_meta($conversation_id, '_evapp_wa_inbox_unread_count', true));
                        $row_sender = get_post_meta($conversation_id, '_evapp_wa_inbox_sender_phone_number_id', true);
                        $display_phone = get_post_meta($conversation_id, '_evapp_wa_inbox_display_phone_number', true);
                        $origin_method = get_post_meta($conversation_id, '_evapp_wa_inbox_origin_method', true);
                        ?>
                        <tr>
                            <td><span class="evapp-wa-badge <?php echo esc_attr($row_status); ?>"><?php echo esc_html(eventosapp_whatsapp_inbox_status_label($row_status)); ?></span><?php if ( $unread ) : ?><br><span class="evapp-wa-badge" style="margin-top:6px;">No leídos: <?php echo esc_html($unread); ?></span><?php endif; ?></td>
                            <td>
                                <strong><?php echo esc_html($contact_name ?: 'Contacto WhatsApp'); ?></strong><br>
                                <span class="evapp-wa-break">+<?php echo esc_html($from_phone); ?></span><br>
                                <span class="evapp-wa-muted">Conversación #<?php echo esc_html($conversation_id); ?></span>
                            </td>
                            <td>
                                <?php if ( $row_event_id ) : ?>
                                    <strong><?php echo esc_html(get_the_title($row_event_id)); ?></strong><br>
                                    <span class="evapp-wa-muted">ID <?php echo esc_html($row_event_id); ?></span>
                                <?php else : ?>
                                    <span class="evapp-wa-muted">Sin evento detectado</span>
                                <?php endif; ?>
                                <?php if ( $origin_method ) : ?><br><span class="evapp-wa-muted">Origen: <?php echo esc_html($origin_method); ?></span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $row_ticket_id && get_post_type($row_ticket_id) === 'eventosapp_ticket' ) : ?>
                                    <a href="<?php echo esc_url(get_edit_post_link($row_ticket_id)); ?>">Ticket #<?php echo esc_html($row_ticket_id); ?></a><br>
                                    <span class="evapp-wa-muted"><?php echo esc_html(get_post_meta($row_ticket_id, 'eventosapp_ticketID', true)); ?></span>
                                <?php else : ?>
                                    <span class="evapp-wa-muted">Sin ticket asociado</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html($last_preview ?: 'Sin mensajes.'); ?><br>
                                <span class="evapp-wa-muted"><?php echo esc_html($last_at); ?></span>
                            </td>
                            <td>
                                <span class="evapp-wa-break"><?php echo esc_html($row_sender ?: ''); ?></span><br>
                                <span class="evapp-wa-muted"><?php echo esc_html($display_phone ?: ''); ?></span>
                            </td>
                            <td><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_inbox&conversation_id=' . $conversation_id)); ?>">Abrir</a></td>
                        </tr>
                    <?php endwhile; wp_reset_postdata(); ?>
                <?php else : ?>
                    <tr><td colspan="7">No hay conversaciones que coincidan con los filtros.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function eventosapp_whatsapp_inbox_render_conversation($conversation_id) {
    $conversation_id = absint($conversation_id);
    $post = get_post($conversation_id);
    if ( ! $post || $post->post_type !== EVENTOSAPP_WHATSAPP_INBOX_POST_TYPE ) {
        wp_die('Conversación no encontrada.');
    }

    update_post_meta($conversation_id, '_evapp_wa_inbox_unread_count', 0);

    $status = get_post_meta($conversation_id, '_evapp_wa_inbox_status', true) ?: 'open';
    $from_phone = get_post_meta($conversation_id, '_evapp_wa_inbox_from_phone', true);
    $contact_name = get_post_meta($conversation_id, '_evapp_wa_inbox_contact_name', true);
    $event_id = absint(get_post_meta($conversation_id, '_evapp_wa_inbox_event_id', true));
    $ticket_id = absint(get_post_meta($conversation_id, '_evapp_wa_inbox_ticket_id', true));
    $sender_phone_number_id = get_post_meta($conversation_id, '_evapp_wa_inbox_sender_phone_number_id', true);
    $display_phone = get_post_meta($conversation_id, '_evapp_wa_inbox_display_phone_number', true);
    $origin_method = get_post_meta($conversation_id, '_evapp_wa_inbox_origin_method', true);
    $origin_confidence = get_post_meta($conversation_id, '_evapp_wa_inbox_origin_confidence', true);
    $messages = eventosapp_whatsapp_inbox_get_messages($conversation_id, 300);
    $events = eventosapp_whatsapp_inbox_get_events_for_filter();

    ?>
    <div class="wrap evapp-wa-inbox-wrap">
        <h1>Conversación WhatsApp #<?php echo esc_html($conversation_id); ?></h1>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_inbox')); ?>">← Volver al Inbox</a></p>
        <?php eventosapp_whatsapp_inbox_render_notices(); ?>
        <?php eventosapp_whatsapp_inbox_render_styles(); ?>

        <div class="evapp-card">
            <h2><?php echo esc_html($contact_name ?: 'Contacto WhatsApp'); ?> <span class="evapp-wa-muted">+<?php echo esc_html($from_phone); ?></span></h2>
            <p>
                <span class="evapp-wa-badge <?php echo esc_attr($status); ?>"><?php echo esc_html(eventosapp_whatsapp_inbox_status_label($status)); ?></span>
                <?php if ( $event_id ) : ?> <span class="evapp-wa-badge">Evento: <?php echo esc_html(get_the_title($event_id)); ?></span><?php endif; ?>
                <?php if ( $ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket' ) : ?> <span class="evapp-wa-badge">Ticket #<?php echo esc_html($ticket_id); ?></span><?php endif; ?>
            </p>
            <p class="evapp-wa-muted">
                Número emisor: <?php echo esc_html($sender_phone_number_id ?: 'No detectado'); ?> <?php echo $display_phone ? '— ' . esc_html($display_phone) : ''; ?><br>
                Método de origen: <?php echo esc_html($origin_method ?: 'sin_origen'); ?> — confianza: <?php echo esc_html($origin_confidence ?: 'none'); ?>
            </p>
        </div>

        <div class="evapp-card">
            <h2>Gestión de la conversación</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-wa-grid">
                <?php wp_nonce_field('eventosapp_whatsapp_inbox_update_' . $conversation_id, 'eventosapp_whatsapp_inbox_nonce'); ?>
                <input type="hidden" name="action" value="eventosapp_whatsapp_inbox_update_conversation">
                <input type="hidden" name="conversation_id" value="<?php echo esc_attr($conversation_id); ?>">

                <label for="evapp_wa_inbox_status">Estado</label>
                <select id="evapp_wa_inbox_status" name="status">
                    <?php foreach ( eventosapp_whatsapp_inbox_statuses() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($status, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="evapp_wa_inbox_event_id">Evento origen</label>
                <select id="evapp_wa_inbox_event_id" name="event_id">
                    <option value="0">Sin evento asociado</option>
                    <?php foreach ( $events as $item_event_id ) : ?>
                        <option value="<?php echo esc_attr($item_event_id); ?>" <?php selected($event_id, $item_event_id); ?>><?php echo esc_html(get_the_title($item_event_id)); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Ticket origen</label>
                <div>
                    <?php if ( $ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket' ) : ?>
                        <a href="<?php echo esc_url(get_edit_post_link($ticket_id)); ?>" target="_blank" rel="noopener">Abrir ticket #<?php echo esc_html($ticket_id); ?></a>
                    <?php else : ?>
                        <span class="evapp-wa-muted">Sin ticket asociado. El ticket se asigna automáticamente cuando el mensaje responde a un WhatsApp enviado desde un ticket.</span>
                    <?php endif; ?>
                </div>

                <label></label>
                <button type="submit" class="button button-primary">Guardar gestión</button>
            </form>
        </div>

        <div class="evapp-card">
            <h2>Responder por WhatsApp</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('eventosapp_whatsapp_inbox_reply_' . $conversation_id, 'eventosapp_whatsapp_inbox_reply_nonce'); ?>
                <input type="hidden" name="action" value="eventosapp_whatsapp_inbox_send_reply">
                <input type="hidden" name="conversation_id" value="<?php echo esc_attr($conversation_id); ?>">
                <textarea name="reply_body" rows="4" style="width:100%;max-width:900px;" placeholder="Escribe una respuesta libre. Meta solo permite mensajes libres dentro de la ventana de atención vigente."></textarea>
                <p><button type="submit" class="button button-primary">Enviar respuesta</button></p>
            </form>
        </div>

        <div class="evapp-card">
            <h2>Mensajes</h2>
            <div class="evapp-wa-thread">
                <?php if ( empty($messages) ) : ?>
                    <p>No hay mensajes registrados.</p>
                <?php else : ?>
                    <?php foreach ( $messages as $message ) :
                        $direction = sanitize_key((string)($message['direction'] ?? 'inbound'));
                        $body = (string)($message['body'] ?? '');
                        ?>
                        <div class="evapp-wa-message <?php echo esc_attr($direction); ?>">
                            <div class="evapp-wa-message-header">
                                <strong><?php echo $direction === 'outbound' ? 'EventosApp' : esc_html($message['contact_name'] ?: 'Contacto'); ?></strong>
                                <span><?php echo esc_html($message['created_at']); ?></span>
                            </div>
                            <div class="evapp-wa-message-body"><?php echo esc_html($body); ?></div>
                            <div class="evapp-wa-muted" style="margin-top:8px;">
                                Tipo: <?php echo esc_html($message['message_type']); ?> — Estado: <?php echo esc_html($message['status']); ?><br>
                                <?php if ( ! empty($message['wa_message_id']) ) : ?>Message ID: <span class="evapp-wa-break"><?php echo esc_html($message['wa_message_id']); ?></span><?php endif; ?>
                                <?php if ( ! empty($message['reply_to_message_id']) ) : ?><br>Responde a: <span class="evapp-wa-break"><?php echo esc_html($message['reply_to_message_id']); ?></span><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

add_action('admin_post_eventosapp_whatsapp_inbox_local_test', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes para probar el inbox.');
    }
    if ( ! isset($_POST['eventosapp_whatsapp_inbox_local_test_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_inbox_local_test_nonce'], 'eventosapp_whatsapp_inbox_local_test') ) {
        wp_die('Nonce inválido.');
    }

    $settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : [];
    $from_phone = isset($_POST['from_phone']) ? eventosapp_whatsapp_inbox_clean_phone(wp_unslash($_POST['from_phone'])) : '';
    if ( $from_phone === '' ) {
        $from_phone = eventosapp_whatsapp_inbox_clean_phone($settings['test_phone'] ?? '');
    }
    if ( $from_phone === '' ) {
        $from_phone = '573000000000';
    }

    $sender_phone_number_id = isset($_POST['sender_phone_number_id']) ? eventosapp_whatsapp_inbox_clean_phone(wp_unslash($_POST['sender_phone_number_id'])) : '';
    if ( $sender_phone_number_id === '' ) {
        $sender_phone_number_id = eventosapp_whatsapp_inbox_clean_phone($settings['test_phone_number_id'] ?? ($settings['phone_number_id'] ?? ''));
    }

    $now = current_time('timestamp');
    $message_id = 'local_test_inbound_' . $now . '_' . wp_rand(1000, 9999);
    $message = [
        'from' => $from_phone,
        'id' => $message_id,
        'timestamp' => (string) $now,
        'type' => 'text',
        'text' => [
            'body' => 'Mensaje local de prueba para validar que el Inbox WhatsApp puede crear conversaciones sin depender de Meta.',
        ],
    ];
    $value = [
        'messaging_product' => 'whatsapp',
        'metadata' => [
            'display_phone_number' => $settings['phone_number_label'] ?? 'EventosApp',
            'phone_number_id' => $sender_phone_number_id,
        ],
        'contacts' => [
            [
                'profile' => ['name' => 'Contacto de prueba local'],
                'wa_id' => $from_phone,
            ],
        ],
    ];

    eventosapp_whatsapp_inbox_handle_inbound_message($message, $value, [], [], ['local_test' => true]);

    if ( function_exists('eventosapp_whatsapp_process_webhook_inbound_message') ) {
        eventosapp_whatsapp_process_webhook_inbound_message($message);
    }

    $source = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : 'inbox';
    if ( $source === 'settings' ) {
        wp_safe_redirect(add_query_arg([
            'page' => 'eventosapp_whatsapp_tickets',
            'evapp_whatsapp_webhook_diag' => '1',
            'evapp_whatsapp_msg' => rawurlencode('Mensaje local de prueba creado. Si aparece en el inbox, el módulo funciona y el pendiente está en la configuración de Meta/webhook.'),
        ], admin_url('admin.php')));
        exit;
    }

    wp_safe_redirect(add_query_arg([
        'page' => 'eventosapp_whatsapp_inbox',
        'evapp_wa_inbox_reply' => '1',
        'evapp_wa_inbox_msg' => rawurlencode('Mensaje local de prueba creado. Si aparece en el inbox, el módulo funciona y el pendiente está en la configuración de Meta/webhook.'),
    ], admin_url('admin.php')));
    exit;
});

add_action('admin_post_eventosapp_whatsapp_inbox_update_conversation', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes para actualizar esta conversación.');
    }

    $conversation_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
    if ( ! $conversation_id || get_post_type($conversation_id) !== EVENTOSAPP_WHATSAPP_INBOX_POST_TYPE ) {
        wp_die('Conversación inválida.');
    }

    if ( ! isset($_POST['eventosapp_whatsapp_inbox_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_inbox_nonce'], 'eventosapp_whatsapp_inbox_update_' . $conversation_id) ) {
        wp_die('Nonce inválido.');
    }

    $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'open';
    if ( ! array_key_exists($status, eventosapp_whatsapp_inbox_statuses()) ) {
        $status = 'open';
    }
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;

    update_post_meta($conversation_id, '_evapp_wa_inbox_status', $status);
    update_post_meta($conversation_id, '_evapp_wa_inbox_event_id', $event_id);

    $from_phone = get_post_meta($conversation_id, '_evapp_wa_inbox_from_phone', true);
    $contact_name = get_post_meta($conversation_id, '_evapp_wa_inbox_contact_name', true);
    wp_update_post([
        'ID' => $conversation_id,
        'post_title' => eventosapp_whatsapp_inbox_build_conversation_title($conversation_id, $from_phone, $contact_name, $event_id),
    ]);

    wp_safe_redirect(add_query_arg([
        'page' => 'eventosapp_whatsapp_inbox',
        'conversation_id' => $conversation_id,
        'evapp_wa_inbox_saved' => '1',
    ], admin_url('admin.php')));
    exit;
});

add_action('admin_post_eventosapp_whatsapp_inbox_send_reply', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos suficientes para responder esta conversación.');
    }

    $conversation_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
    if ( ! $conversation_id || get_post_type($conversation_id) !== EVENTOSAPP_WHATSAPP_INBOX_POST_TYPE ) {
        wp_die('Conversación inválida.');
    }

    if ( ! isset($_POST['eventosapp_whatsapp_inbox_reply_nonce']) || ! wp_verify_nonce($_POST['eventosapp_whatsapp_inbox_reply_nonce'], 'eventosapp_whatsapp_inbox_reply_' . $conversation_id) ) {
        wp_die('Nonce inválido.');
    }

    $body = isset($_POST['reply_body']) ? sanitize_textarea_field(wp_unslash($_POST['reply_body'])) : '';
    $body = trim($body);
    if ( $body === '' ) {
        wp_safe_redirect(add_query_arg([
            'page' => 'eventosapp_whatsapp_inbox',
            'conversation_id' => $conversation_id,
            'evapp_wa_inbox_reply' => '0',
            'evapp_wa_inbox_msg' => rawurlencode('La respuesta no puede estar vacía.'),
        ], admin_url('admin.php')));
        exit;
    }

    if ( ! function_exists('eventosapp_whatsapp_api_send_message') || ! function_exists('eventosapp_whatsapp_get_settings') ) {
        wp_safe_redirect(add_query_arg([
            'page' => 'eventosapp_whatsapp_inbox',
            'conversation_id' => $conversation_id,
            'evapp_wa_inbox_reply' => '0',
            'evapp_wa_inbox_msg' => rawurlencode('La API de WhatsApp no está cargada.'),
        ], admin_url('admin.php')));
        exit;
    }

    $to_phone = eventosapp_whatsapp_inbox_clean_phone(get_post_meta($conversation_id, '_evapp_wa_inbox_from_phone', true));
    $sender_phone_number_id = eventosapp_whatsapp_inbox_clean_phone(get_post_meta($conversation_id, '_evapp_wa_inbox_sender_phone_number_id', true));
    $event_id = absint(get_post_meta($conversation_id, '_evapp_wa_inbox_event_id', true));
    $ticket_id = absint(get_post_meta($conversation_id, '_evapp_wa_inbox_ticket_id', true));
    $contact_name = get_post_meta($conversation_id, '_evapp_wa_inbox_contact_name', true);
    $display_phone = get_post_meta($conversation_id, '_evapp_wa_inbox_display_phone_number', true);

    $settings = eventosapp_whatsapp_get_settings();
    if ( function_exists('eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id') ) {
        $settings = eventosapp_whatsapp_resolve_sender_settings_by_phone_number_id($sender_phone_number_id, $settings);
    }

    $payload = [
        'type' => 'text',
        'text' => [
            'preview_url' => false,
            'body' => $body,
        ],
    ];

    $result = eventosapp_whatsapp_api_send_message($to_phone, $payload, $settings);
    $message_id = '';
    if ( isset($result['message_id']) ) {
        $message_id = sanitize_text_field((string) $result['message_id']);
    } elseif ( function_exists('eventosapp_whatsapp_extract_message_id') ) {
        $message_id = eventosapp_whatsapp_extract_message_id($result['response'] ?? []);
    }

    $ok = ! empty($result['ok']);
    $created_at = current_time('mysql');
    $message_db_id = eventosapp_whatsapp_inbox_insert_message([
        'conversation_id'        => $conversation_id,
        'event_id'               => $event_id,
        'ticket_id'              => $ticket_id,
        'wa_message_id'          => $message_id ?: ('local_out_' . $conversation_id . '_' . time() . '_' . wp_rand(1000, 9999)),
        'direction'              => 'outbound',
        'status'                 => $ok ? 'accepted_by_meta' : 'error',
        'from_phone'             => $sender_phone_number_id,
        'to_phone'               => $to_phone,
        'sender_phone_number_id' => $sender_phone_number_id,
        'display_phone_number'   => $display_phone,
        'contact_name'           => $contact_name,
        'message_type'           => 'text',
        'body'                   => $body,
        'raw_json'               => eventosapp_whatsapp_inbox_safe_json([
            'payload' => $payload,
            'result'  => $result,
        ]),
        'origin_method'          => 'respuesta_manual_inbox',
        'origin_confidence'      => 'high',
        'created_at'             => $created_at,
    ]);

    if ( $message_db_id ) {
        eventosapp_whatsapp_inbox_update_conversation_after_message($conversation_id, $message_db_id, 'outbound', $body, $created_at, $event_id, $ticket_id);
    }

    if ( $message_id !== '' && function_exists('eventosapp_whatsapp_register_message_map') ) {
        eventosapp_whatsapp_register_message_map($message_id, $ticket_id, 'inbox_reply', $to_phone);
    }

    if ( function_exists('eventosapp_whatsapp_insert_central_log') ) {
        eventosapp_whatsapp_insert_central_log([
            'created_at'             => $created_at,
            'event_id'               => $event_id,
            'ticket_id'              => $ticket_id,
            'recipient'              => $to_phone,
            'channel'                => 'inbox',
            'context'                => 'inbox_reply',
            'status'                 => $ok ? 'respuesta_aceptada_meta' : 'respuesta_error',
            'delivery_status'        => $ok ? 'pending_webhook' : 'failed_local',
            'message_id'             => $message_id,
            'source_key'             => 'inbox_reply:' . $conversation_id . ':' . $message_db_id,
            'sender_phone_number_id' => $sender_phone_number_id,
            'sender_label'           => $settings['sender_phone_label'] ?? $display_phone,
            'transport'              => 'freeform_text',
            'http_code'              => isset($result['http_code']) ? absint($result['http_code']) : 0,
            'message'                => $ok ? 'Respuesta del inbox aceptada por Meta.' : ($result['message'] ?? 'No se pudo enviar la respuesta.'),
            'meta'                   => [
                'conversation_id' => $conversation_id,
                'inbox_message_id' => $message_db_id,
                'api_result' => $result,
            ],
        ]);
    }

    wp_safe_redirect(add_query_arg([
        'page' => 'eventosapp_whatsapp_inbox',
        'conversation_id' => $conversation_id,
        'evapp_wa_inbox_reply' => $ok ? '1' : '0',
        'evapp_wa_inbox_msg' => rawurlencode($ok ? 'Respuesta enviada a Meta. Esperando webhook de entrega.' : ($result['message'] ?? 'No se pudo enviar la respuesta.')),
    ], admin_url('admin.php')));
    exit;
});
