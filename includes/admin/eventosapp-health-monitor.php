<?php
/**
 * EventosApp – Monitor de Salud y Rendimiento
 *
 * Archivo nuevo.
 * Ruta recomendada: includes/admin/eventosapp-health-monitor.php
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('eventosapp_health_bytes_to_human') ) {
    function eventosapp_health_bytes_to_human($bytes) {
        $bytes = max(0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ( $bytes >= 1024 && $i < count($units) - 1 ) {
            $bytes /= 1024;
            $i++;
        }
        return number_format_i18n($bytes, $i === 0 ? 0 : 2) . ' ' . $units[$i];
    }
}

if ( ! function_exists('eventosapp_health_parse_size_to_bytes') ) {
    function eventosapp_health_parse_size_to_bytes($size) {
        $size = trim((string) $size);
        if ( $size === '' || $size === '-1' ) return 0;
        $unit = strtolower(substr($size, -1));
        $num  = (float) $size;
        switch ($unit) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return (int) $num;
    }
}

if ( ! function_exists('eventosapp_health_dir_size') ) {
    function eventosapp_health_dir_size($dir, $max_files = 2500) {
        $dir = (string) $dir;
        if ( $dir === '' || ! is_dir($dir) ) {
            return ['bytes' => 0, 'files' => 0, 'truncated' => false];
        }

        $bytes = 0;
        $files = 0;
        $truncated = false;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ( $iterator as $file ) {
                if ( $file->isFile() ) {
                    $files++;
                    $bytes += (int) $file->getSize();
                    if ( $files >= $max_files ) {
                        $truncated = true;
                        break;
                    }
                }
            }
        } catch ( Exception $e ) {
            return ['bytes' => $bytes, 'files' => $files, 'truncated' => true];
        }

        return ['bytes' => $bytes, 'files' => $files, 'truncated' => $truncated];
    }
}

if ( ! function_exists('eventosapp_health_table_size') ) {
    function eventosapp_health_table_size($table_name) {
        global $wpdb;

        $table_name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table_name);
        if ( $table_name === '' ) return 0;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT (data_length + index_length) AS total_bytes FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s LIMIT 1',
            $table_name
        ), ARRAY_A);

        return $row && isset($row['total_bytes']) ? (int) $row['total_bytes'] : 0;
    }
}

if ( ! function_exists('eventosapp_health_index_exists') ) {
    function eventosapp_health_index_exists($table_name, $index_name) {
        global $wpdb;

        $table_name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table_name);
        $index_name = sanitize_key((string) $index_name);
        if ( $table_name === '' || $index_name === '' ) return false;

        $found = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `{$table_name}` WHERE Key_name = %s", $index_name));
        return ! empty($found);
    }
}

if ( ! function_exists('eventosapp_health_get_recommended_indexes') ) {
    function eventosapp_health_get_recommended_indexes() {
        global $wpdb;

        $indexes = [
            [
                'table' => $wpdb->postmeta,
                'name'  => 'evapp_meta_key_val_post',
                'sql'   => "ALTER TABLE `{$wpdb->postmeta}` ADD INDEX `evapp_meta_key_val_post` (`meta_key`(64), `meta_value`(191), `post_id`)",
                'label' => 'wp_postmeta: meta_key + meta_value + post_id',
            ],
            [
                'table' => $wpdb->postmeta,
                'name'  => 'evapp_post_key_val',
                'sql'   => "ALTER TABLE `{$wpdb->postmeta}` ADD INDEX `evapp_post_key_val` (`post_id`, `meta_key`(64), `meta_value`(64))",
                'label' => 'wp_postmeta: post_id + meta_key + meta_value',
            ],
        ];

        $inbox_table = function_exists('eventosapp_whatsapp_inbox_messages_table_name')
            ? eventosapp_whatsapp_inbox_messages_table_name()
            : $wpdb->prefix . 'eventosapp_whatsapp_inbox_messages';

        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $inbox_table));
        if ( $table_exists === $inbox_table ) {
            $indexes[] = [
                'table' => $inbox_table,
                'name'  => 'evapp_conv_created',
                'sql'   => "ALTER TABLE `{$inbox_table}` ADD INDEX `evapp_conv_created` (`conversation_id`, `created_at`)",
                'label' => 'WhatsApp inbox: conversación + fecha',
            ];
            $indexes[] = [
                'table' => $inbox_table,
                'name'  => 'evapp_event_created',
                'sql'   => "ALTER TABLE `{$inbox_table}` ADD INDEX `evapp_event_created` (`event_id`, `created_at`)",
                'label' => 'WhatsApp inbox: evento + fecha',
            ];
        }

        return $indexes;
    }
}

if ( ! function_exists('eventosapp_health_ensure_indexes') ) {
    function eventosapp_health_ensure_indexes() {
        global $wpdb;

        $results = [];
        foreach ( eventosapp_health_get_recommended_indexes() as $index ) {
            $table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $index['table']);
            $name  = sanitize_key((string) $index['name']);

            if ( eventosapp_health_index_exists($table, $name) ) {
                $results[] = ['ok' => true, 'label' => $index['label'], 'message' => 'Ya existía.'];
                continue;
            }

            $ok = $wpdb->query($index['sql']);
            if ( $ok === false ) {
                $results[] = [
                    'ok'      => false,
                    'label'   => $index['label'],
                    'message' => $wpdb->last_error ?: 'No se pudo crear el índice.',
                ];
            } else {
                $results[] = ['ok' => true, 'label' => $index['label'], 'message' => 'Creado correctamente.'];
            }
        }

        update_option('eventosapp_health_indexes_last_run', current_time('mysql'), false);
        return $results;
    }
}

if ( ! function_exists('eventosapp_health_collect') ) {
    function eventosapp_health_collect() {
        global $wpdb;

        $upload = wp_upload_dir();
        $qr_dir = trailingslashit($upload['basedir']) . 'eventosapp-qr/';
        $qr_cache_dir = trailingslashit($upload['basedir']) . 'eventosapp-qr-cache/';
        $log_dir = trailingslashit($upload['basedir']) . 'eventosapp-qr-logs/';

        $tickets_total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'eventosapp_ticket' AND post_status NOT IN ('trash','auto-draft','inherit')"
        );
        $events_total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'eventosapp_event' AND post_status NOT IN ('trash','auto-draft','inherit')"
        );

        $top_events = $wpdb->get_results(
            "SELECT ev.meta_value AS event_id, COUNT(*) AS total
               FROM {$wpdb->postmeta} ev
               INNER JOIN {$wpdb->posts} p ON p.ID = ev.post_id
              WHERE ev.meta_key = '_eventosapp_ticket_evento_id'
                AND p.post_type = 'eventosapp_ticket'
                AND p.post_status NOT IN ('trash','auto-draft','inherit')
              GROUP BY ev.meta_value
              ORDER BY total DESC
              LIMIT 10",
            ARRAY_A
        );

        $tracked_meta_keys = [
            '_eventosapp_ticket_evento_id',
            '_eventosapp_asistente_cc',
            '_evapp_search_blob',
            '_evapp_search_name',
            '_evapp_search_cc',
            '_evapp_search_phone',
            '_evapp_search_email',
            'eventosapp_ticketID',
            'eventosapp_ticket_preprintedID',
            '_eventosapp_checkin_status',
            '_eventosapp_checkin_log',
            '_eventosapp_whatsapp_last_status',
            '_eventosapp_ticket_email_sent_status',
        ];
        $placeholders = implode(',', array_fill(0, count($tracked_meta_keys), '%s'));
        $meta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, COUNT(*) AS total FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders) GROUP BY meta_key ORDER BY total DESC",
            ...$tracked_meta_keys
        ), ARRAY_A);

        $indexes = [];
        foreach ( eventosapp_health_get_recommended_indexes() as $index ) {
            $table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $index['table']);
            $name  = sanitize_key((string) $index['name']);
            $indexes[] = [
                'label'  => $index['label'],
                'table'  => $table,
                'name'   => $name,
                'exists' => eventosapp_health_index_exists($table, $name),
            ];
        }

        $inbox_table = function_exists('eventosapp_whatsapp_inbox_messages_table_name')
            ? eventosapp_whatsapp_inbox_messages_table_name()
            : $wpdb->prefix . 'eventosapp_whatsapp_inbox_messages';
        $inbox_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $inbox_table)) === $inbox_table);
        $inbox = [
            'exists'       => $inbox_exists,
            'rows'         => 0,
            'last_24h'     => 0,
            'table_bytes'  => 0,
        ];
        if ( $inbox_exists ) {
            $inbox['rows'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$inbox_table}");
            $inbox['last_24h'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$inbox_table} WHERE created_at >= %s", gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)));
            $inbox['table_bytes'] = eventosapp_health_table_size($inbox_table);
        }

        $batch_current = get_option('eventosapp_batch_current', null);
        $batch_lock = get_option('eventosapp_batch_processor_lock', null);

        $memory_limit = eventosapp_health_parse_size_to_bytes(ini_get('memory_limit'));
        $memory_usage = memory_get_usage(true);
        $memory_peak  = memory_get_peak_usage(true);

        $qr_size       = eventosapp_health_dir_size($qr_dir);
        $qr_cache_size = eventosapp_health_dir_size($qr_cache_dir);
        $log_size      = eventosapp_health_dir_size($log_dir);

        $transients_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_evapp_') . '%',
            $wpdb->esc_like('_transient_timeout_evapp_') . '%'
        ));

        $postmeta_bytes = eventosapp_health_table_size($wpdb->postmeta);
        $posts_bytes    = eventosapp_health_table_size($wpdb->posts);
        $options_bytes  = eventosapp_health_table_size($wpdb->options);

        $recommendations = [];
        $max_event_tickets = 0;
        foreach ( (array) $top_events as $row ) {
            $max_event_tickets = max($max_event_tickets, (int) $row['total']);
        }
        if ( $max_event_tickets >= 1000 ) {
            $recommendations[] = 'Hay al menos un evento con 1000 o más tickets. Mantén activas las búsquedas segmentadas y evita usar “Todos los datos” como búsqueda por defecto.';
        }
        foreach ( $indexes as $idx ) {
            if ( empty($idx['exists']) ) {
                $recommendations[] = 'Falta el índice recomendado: ' . $idx['label'] . '. Esto afecta búsquedas de tickets, QR y filtros masivos.';
                break;
            }
        }
        if ( $memory_limit > 0 && $memory_peak > ($memory_limit * 0.7) ) {
            $recommendations[] = 'El pico de memoria de esta carga supera el 70% del memory_limit. Conviene reducir lotes masivos o subir memory_limit.';
        }
        if ( $inbox['rows'] > 10000 ) {
            $recommendations[] = 'El inbox de WhatsApp ya tiene más de 10.000 mensajes. Revisa limpieza automática y filtros por fecha.';
        }
        if ( $log_size['bytes'] > 200 * 1024 * 1024 ) {
            $recommendations[] = 'Los logs/archivos de QR superan 200 MB. Revisa limpieza de archivos antiguos.';
        }
        if ( empty($recommendations) ) {
            $recommendations[] = 'No se detectaron alertas críticas en los indicadores revisados.';
        }

        return [
            'generated_at'      => current_time('mysql'),
            'tickets_total'     => $tickets_total,
            'events_total'      => $events_total,
            'top_events'        => $top_events,
            'meta_rows'         => $meta_rows,
            'indexes'           => $indexes,
            'inbox'             => $inbox,
            'batch_current'     => is_array($batch_current) ? $batch_current : null,
            'batch_lock'        => is_array($batch_lock) ? $batch_lock : null,
            'memory'            => [
                'limit' => $memory_limit,
                'usage' => $memory_usage,
                'peak'  => $memory_peak,
            ],
            'files'             => [
                'qr'       => $qr_size,
                'qr_cache' => $qr_cache_size,
                'logs'     => $log_size,
            ],
            'db'                => [
                'posts'    => $posts_bytes,
                'postmeta' => $postmeta_bytes,
                'options'  => $options_bytes,
            ],
            'transients_count'  => $transients_count,
            'recommendations'   => $recommendations,
            'indexes_last_run'  => get_option('eventosapp_health_indexes_last_run', ''),
        ];
    }
}

add_action('admin_menu', function() {
    add_submenu_page(
        'eventosapp_dashboard',
        'Salud y Rendimiento',
        'Salud y Rendimiento',
        'manage_options',
        'eventosapp-health-monitor',
        'eventosapp_health_render_page',
        95
    );
}, 30);

add_action('wp_ajax_eventosapp_health_create_indexes', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'No autorizado'], 403);
    }
    check_ajax_referer('eventosapp_health_indexes', 'security');

    $results = eventosapp_health_ensure_indexes();
    wp_send_json_success(['results' => $results]);
});

if ( ! function_exists('eventosapp_health_status_badge') ) {
    function eventosapp_health_status_badge($ok, $text_ok = 'OK', $text_bad = 'Revisar') {
        $bg = $ok ? '#dcfce7' : '#fee2e2';
        $fg = $ok ? '#166534' : '#991b1b';
        $txt = $ok ? $text_ok : $text_bad;
        return '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:' . esc_attr($bg) . ';color:' . esc_attr($fg) . ';font-weight:700;font-size:12px;">' . esc_html($txt) . '</span>';
    }
}

if ( ! function_exists('eventosapp_health_render_page') ) {
    function eventosapp_health_render_page() {
        if ( ! current_user_can('manage_options') ) {
            wp_die('No autorizado');
        }

        $data = eventosapp_health_collect();
        $nonce = wp_create_nonce('eventosapp_health_indexes');
        ?>
        <div class="wrap evapp-health-wrap">
            <h1>EventosApp — Salud y Rendimiento</h1>
            <p>Indicadores internos para anticipar problemas de rendimiento cuando un evento crece a 1000+ asistentes.</p>

            <style>
                .evapp-health-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin:18px 0}
                .evapp-health-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
                .evapp-health-card h2{font-size:14px;margin:0 0 8px;color:#50575e;text-transform:uppercase;letter-spacing:.04em}
                .evapp-health-value{font-size:28px;font-weight:800;line-height:1.1;color:#1d2327}
                .evapp-health-sub{font-size:12px;color:#646970;margin-top:6px}
                .evapp-health-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #dcdcde;border-radius:12px;overflow:hidden;margin:12px 0 22px}
                .evapp-health-table th,.evapp-health-table td{padding:10px 12px;border-bottom:1px solid #f0f0f1;text-align:left;vertical-align:top}
                .evapp-health-table th{background:#f6f7f7;font-weight:700}
                .evapp-health-table tr:last-child td{border-bottom:0}
                .evapp-health-actions{display:flex;gap:10px;align-items:center;margin:12px 0 18px;flex-wrap:wrap}
                .evapp-health-note{padding:12px 14px;border-radius:10px;background:#f0f6fc;border:1px solid #c5d9ed;color:#1d3b53;margin:14px 0}
                .evapp-health-warn{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
                .evapp-health-ok{background:#ecfdf5;border-color:#bbf7d0;color:#166534}
                #evapp-health-index-result{min-height:22px}
            </style>

            <div class="evapp-health-grid">
                <div class="evapp-health-card">
                    <h2>Tickets activos</h2>
                    <div class="evapp-health-value"><?php echo esc_html(number_format_i18n($data['tickets_total'])); ?></div>
                    <div class="evapp-health-sub">Total de tickets no enviados a papelera.</div>
                </div>
                <div class="evapp-health-card">
                    <h2>Eventos</h2>
                    <div class="evapp-health-value"><?php echo esc_html(number_format_i18n($data['events_total'])); ?></div>
                    <div class="evapp-health-sub">Eventos registrados.</div>
                </div>
                <div class="evapp-health-card">
                    <h2>Memoria PHP</h2>
                    <div class="evapp-health-value"><?php echo esc_html(eventosapp_health_bytes_to_human($data['memory']['peak'])); ?></div>
                    <div class="evapp-health-sub">Pico actual / límite <?php echo esc_html($data['memory']['limit'] ? eventosapp_health_bytes_to_human($data['memory']['limit']) : 'sin límite'); ?>.</div>
                </div>
                <div class="evapp-health-card">
                    <h2>WhatsApp Inbox</h2>
                    <div class="evapp-health-value"><?php echo esc_html(number_format_i18n($data['inbox']['rows'])); ?></div>
                    <div class="evapp-health-sub">Mensajes almacenados. Últimas 24h: <?php echo esc_html(number_format_i18n($data['inbox']['last_24h'])); ?>.</div>
                </div>
            </div>

            <h2>Acciones de optimización</h2>
            <div class="evapp-health-actions">
                <button type="button" class="button button-primary" id="evapp-health-create-indexes">Crear / verificar índices recomendados</button>
                <span id="evapp-health-index-result"></span>
            </div>
            <p class="description">Los índices mejoran búsquedas por cédula, TicketID, QR, filtros por evento y consultas del inbox. Si ya existen, no se duplican.</p>

            <h2>Índices recomendados</h2>
            <table class="evapp-health-table">
                <thead><tr><th>Índice</th><th>Tabla</th><th>Estado</th></tr></thead>
                <tbody>
                <?php foreach ( $data['indexes'] as $idx ) : ?>
                    <tr>
                        <td><?php echo esc_html($idx['label']); ?><br><code><?php echo esc_html($idx['name']); ?></code></td>
                        <td><code><?php echo esc_html($idx['table']); ?></code></td>
                        <td><?php echo eventosapp_health_status_badge(!empty($idx['exists']), 'Activo', 'Falta'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Eventos con más tickets</h2>
            <table class="evapp-health-table">
                <thead><tr><th>Evento</th><th>ID</th><th>Tickets</th><th>Riesgo</th></tr></thead>
                <tbody>
                <?php if ( empty($data['top_events']) ) : ?>
                    <tr><td colspan="4">No hay tickets asociados a eventos.</td></tr>
                <?php else : ?>
                    <?php foreach ( $data['top_events'] as $row ) :
                        $event_id = absint($row['event_id']);
                        $total = (int) $row['total'];
                        $risk = $total >= 1000 ? 'Alto' : ($total >= 500 ? 'Medio' : 'Normal');
                        ?>
                        <tr>
                            <td><?php echo esc_html(get_the_title($event_id) ?: 'Evento sin título'); ?></td>
                            <td><a href="<?php echo esc_url(get_edit_post_link($event_id)); ?>"><?php echo esc_html($event_id); ?></a></td>
                            <td><?php echo esc_html(number_format_i18n($total)); ?></td>
                            <td><?php echo eventosapp_health_status_badge($total < 500, 'Normal', $risk); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <h2>Consumo de base de datos</h2>
            <table class="evapp-health-table">
                <thead><tr><th>Área</th><th>Tamaño aproximado</th><th>Detalle</th></tr></thead>
                <tbody>
                    <tr><td>Posts</td><td><?php echo esc_html(eventosapp_health_bytes_to_human($data['db']['posts'])); ?></td><td>Eventos, tickets, asistentes y adjuntos.</td></tr>
                    <tr><td>Postmeta</td><td><?php echo esc_html(eventosapp_health_bytes_to_human($data['db']['postmeta'])); ?></td><td>Principal punto de presión en búsquedas y filtros.</td></tr>
                    <tr><td>Options</td><td><?php echo esc_html(eventosapp_health_bytes_to_human($data['db']['options'])); ?></td><td>Opciones, transients y segmentos temporales.</td></tr>
                    <tr><td>Inbox WhatsApp</td><td><?php echo esc_html(eventosapp_health_bytes_to_human($data['inbox']['table_bytes'])); ?></td><td><?php echo $data['inbox']['exists'] ? 'Tabla detectada.' : 'Tabla no detectada.'; ?></td></tr>
                </tbody>
            </table>

            <h2>Metadatos críticos</h2>
            <table class="evapp-health-table">
                <thead><tr><th>Meta key</th><th>Filas</th></tr></thead>
                <tbody>
                <?php foreach ( $data['meta_rows'] as $row ) : ?>
                    <tr><td><code><?php echo esc_html($row['meta_key']); ?></code></td><td><?php echo esc_html(number_format_i18n((int)$row['total'])); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Archivos y cache</h2>
            <table class="evapp-health-table">
                <thead><tr><th>Área</th><th>Tamaño</th><th>Archivos revisados</th></tr></thead>
                <tbody>
                    <tr><td>QR generados</td><td><?php echo esc_html(eventosapp_health_bytes_to_human($data['files']['qr']['bytes'])); ?></td><td><?php echo esc_html(number_format_i18n($data['files']['qr']['files'])); ?><?php echo $data['files']['qr']['truncated'] ? ' +' : ''; ?></td></tr>
                    <tr><td>QR cache</td><td><?php echo esc_html(eventosapp_health_bytes_to_human($data['files']['qr_cache']['bytes'])); ?></td><td><?php echo esc_html(number_format_i18n($data['files']['qr_cache']['files'])); ?><?php echo $data['files']['qr_cache']['truncated'] ? ' +' : ''; ?></td></tr>
                    <tr><td>Logs QR</td><td><?php echo esc_html(eventosapp_health_bytes_to_human($data['files']['logs']['bytes'])); ?></td><td><?php echo esc_html(number_format_i18n($data['files']['logs']['files'])); ?><?php echo $data['files']['logs']['truncated'] ? ' +' : ''; ?></td></tr>
                    <tr><td>Transients EventosApp</td><td colspan="2"><?php echo esc_html(number_format_i18n($data['transients_count'])); ?> registros temporales detectados.</td></tr>
                </tbody>
            </table>

            <h2>Procesos por lote</h2>
            <?php if ( empty($data['batch_current']) ) : ?>
                <div class="evapp-health-note evapp-health-ok">No hay actualización por lote activa.</div>
            <?php else : ?>
                <table class="evapp-health-table">
                    <tbody>
                        <tr><th>Estado</th><td><?php echo esc_html($data['batch_current']['status'] ?? 'desconocido'); ?></td></tr>
                        <tr><th>Procesados</th><td><?php echo esc_html(number_format_i18n((int)($data['batch_current']['processed'] ?? 0))); ?> / <?php echo esc_html(number_format_i18n((int)($data['batch_current']['total'] ?? 0))); ?></td></tr>
                        <tr><th>Última actividad</th><td><?php echo esc_html($data['batch_current']['last_activity'] ?? ''); ?></td></tr>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>Recomendaciones</h2>
            <?php foreach ( $data['recommendations'] as $rec ) : ?>
                <div class="evapp-health-note <?php echo strpos($rec, 'No se detectaron') === 0 ? 'evapp-health-ok' : 'evapp-health-warn'; ?>"><?php echo esc_html($rec); ?></div>
            <?php endforeach; ?>

            <p class="description">Última generación: <?php echo esc_html($data['generated_at']); ?>. Última verificación de índices: <?php echo esc_html($data['indexes_last_run'] ?: 'Nunca'); ?>.</p>
        </div>

        <script>
        jQuery(function($){
            $('#evapp-health-create-indexes').on('click', function(){
                var $btn = $(this);
                var $out = $('#evapp-health-index-result');
                $btn.prop('disabled', true).text('Procesando...');
                $out.text('Verificando índices recomendados...');

                $.post(ajaxurl, {
                    action: 'eventosapp_health_create_indexes',
                    security: <?php echo wp_json_encode($nonce); ?>
                }, function(resp){
                    if(resp && resp.success){
                        var lines = [];
                        $.each(resp.data.results || [], function(i, item){
                            lines.push((item.ok ? '✅ ' : '❌ ') + item.label + ': ' + item.message);
                        });
                        $out.html(lines.join('<br>'));
                        setTimeout(function(){ window.location.reload(); }, 1200);
                    } else {
                        $out.text((resp && resp.data && resp.data.message) ? resp.data.message : 'No se pudo completar la operación.');
                    }
                }, 'json').fail(function(xhr){
                    $out.text('Error de servidor al crear/verificar índices. Revisa wp-debug.log.');
                }).always(function(){
                    $btn.prop('disabled', false).text('Crear / verificar índices recomendados');
                });
            });
        });
        </script>
        <?php
    }
}
