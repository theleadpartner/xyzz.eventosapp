<?php
/**
 * Email Ticket Masivo - Sistema de envío masivo con segmentación avanzada
 * 
 * Permite enviar tickets por correo de forma masiva usando múltiples criterios de filtrado:
 * - Estado de envío del correo
 * - Fechas de envío (primera y última)
 * - Localidad
 * - Evento
 * - Fechas específicas del evento
 * - Fecha de creación del ticket
 * - Campos adicionales personalizados
 * 
 * El sistema procesa los envíos en lotes controlados para evitar sobrecarga del servidor.
 *
 * @package EventosApp
 */

if (!defined('ABSPATH')) exit;

/**
 * Registrar el menú de administración
 */
add_action('admin_menu', function(){
    add_submenu_page(
        'eventosapp_dashboard',
        'Email Ticket Masivo',
        'Email Ticket Masivo',
        'manage_options',
        'eventosapp_email_masivo',
        'eventosapp_email_masivo_render_page',
        30
    );
}, 20);

/**
 * Renderizar la página principal de Email Ticket Masivo
 */
function eventosapp_email_masivo_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para acceder a esta página.');
    }

    $step = isset($_GET['step']) ? intval($_GET['step']) : 1;
    $segment_id = isset($_GET['segment_id']) ? sanitize_text_field($_GET['segment_id']) : '';

    echo '<div class="wrap">';
    echo '<h1>Email Ticket Masivo</h1>';
    echo '<p class="description">Envía correos de tickets de forma masiva usando criterios de segmentación avanzados.</p>';

    // Pestañas de navegación
    echo '<h2 class="nav-tab-wrapper" style="margin:20px 0;">';
    $tabs = [
        1 => 'Filtros y Segmentación',
        2 => 'Vista Previa',
        3 => 'Envío Masivo'
    ];
    foreach($tabs as $num => $label) {
        $active = ($step === $num) ? ' nav-tab-active' : '';
        $url = add_query_arg(['page' => 'eventosapp_email_masivo', 'step' => $num], admin_url('admin.php'));
        if ($segment_id && $num > 1) {
            $url = add_query_arg('segment_id', $segment_id, $url);
        }
        echo '<a class="nav-tab'.$active.'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
    }
    echo '</h2>';

    // Renderizar contenido según el paso
    switch($step) {
        case 1:
            eventosapp_email_masivo_render_step1();
            break;
        case 2:
            eventosapp_email_masivo_render_step2($segment_id);
            break;
        case 3:
            eventosapp_email_masivo_render_step3($segment_id);
            break;
        default:
            eventosapp_email_masivo_render_step1();
    }

    echo '</div>'; // .wrap
}

/**
 * STEP 1: Configurar filtros y crear segmentación
 */
function eventosapp_email_masivo_render_step1() {
    // Obtener todos los eventos
    $eventos = get_posts([
        'post_type' => 'eventosapp_event',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC'
    ]);

    // Obtener localidades únicas
    global $wpdb;
    $localidades = $wpdb->get_col("
        SELECT DISTINCT meta_value 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = '_eventosapp_asistente_localidad' 
        AND meta_value != '' 
        ORDER BY meta_value ASC
    ");

    ?>
    <style>
        .evapp-email-masivo-form { max-width: 900px; }
        .evapp-filter-section { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .evapp-filter-section h3 { margin-top: 0; color: #2271b1; border-bottom: 2px solid #2271b1; padding-bottom: 10px; }
        .evapp-filter-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
        .evapp-filter-field { display: flex; flex-direction: column; }
        .evapp-filter-field label { font-weight: 600; margin-bottom: 5px; color: #1d2327; }
        .evapp-filter-field select, .evapp-filter-field input { padding: 8px; border: 1px solid #8c8f94; border-radius: 4px; }
        .evapp-filter-field small { color: #646970; margin-top: 4px; }
        .evapp-extra-fields-container { margin-top: 15px; padding: 15px; background: #f0f0f1; border-radius: 4px; }
        .evapp-extra-field-row { display: grid; grid-template-columns: 200px 1fr; gap: 10px; margin-bottom: 10px; align-items: center; }
        .evapp-button-group { margin-top: 20px; }
        .evapp-info-box { background: #e7f3ff; border-left: 4px solid #2271b1; padding: 12px; margin: 15px 0; }
    </style>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-email-masivo-form" id="evappEmailMasivoForm">
        <input type="hidden" name="action" value="eventosapp_email_masivo_create_segment">
        <?php wp_nonce_field('eventosapp_email_masivo_segment', 'evapp_email_masivo_nonce'); ?>

        <div class="evapp-info-box">
            <strong>💡 Instrucciones:</strong> Configura los filtros para segmentar los tickets que deseas enviar. 
            Los campos vacíos se ignoran en la búsqueda. Puedes combinar múltiples criterios.
        </div>

        <!-- Filtros de Estado y Fechas de Envío -->
        <div class="evapp-filter-section">
            <h3>📧 Estado de Correo y Fechas de Envío</h3>
            
            <div class="evapp-filter-row">
                <div class="evapp-filter-field">
                    <label for="email_status">Estado del Correo</label>
                    <select name="filters[email_status]" id="email_status">
                        <option value="">-- Todos --</option>
                        <option value="enviado">Enviado</option>
                        <option value="no_enviado">No Enviado</option>
                        <option value="error">Error</option>
                    </select>
                    <small>Filtra por el estado de envío del ticket</small>
                </div>
            </div>

            <div class="evapp-filter-row">
                <div class="evapp-filter-field">
                    <label for="first_sent_from">Primer Envío - Desde</label>
                    <input type="date" name="filters[first_sent_from]" id="first_sent_from">
                    <small>Fecha mínima del primer envío</small>
                </div>
                <div class="evapp-filter-field">
                    <label for="first_sent_to">Primer Envío - Hasta</label>
                    <input type="date" name="filters[first_sent_to]" id="first_sent_to">
                    <small>Fecha máxima del primer envío</small>
                </div>
            </div>

            <div class="evapp-filter-row">
                <div class="evapp-filter-field">
                    <label for="last_sent_from">Último Envío - Desde</label>
                    <input type="date" name="filters[last_sent_from]" id="last_sent_from">
                    <small>Fecha mínima del último envío</small>
                </div>
                <div class="evapp-filter-field">
                    <label for="last_sent_to">Último Envío - Hasta</label>
                    <input type="date" name="filters[last_sent_to]" id="last_sent_to">
                    <small>Fecha máxima del último envío</small>
                </div>
            </div>
        </div>

        <!-- Filtros de Evento y Localidad -->
        <div class="evapp-filter-section">
            <h3>🎫 Evento y Localidad</h3>
            
            <div class="evapp-filter-row">
                <div class="evapp-filter-field">
                    <label for="evento_id">Evento</label>
                    <select name="filters[evento_id]" id="evento_id">
                        <option value="">-- Todos los eventos --</option>
                        <?php foreach($eventos as $ev): ?>
                            <option value="<?php echo esc_attr($ev->ID); ?>">
                                <?php echo esc_html($ev->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Selecciona un evento específico</small>
                </div>

                <div class="evapp-filter-field">
                    <label for="localidad">Localidad</label>
                    <select name="filters[localidad]" id="localidad">
                        <option value="">-- Todas las localidades --</option>
                        <?php foreach($localidades as $loc): ?>
                            <option value="<?php echo esc_attr($loc); ?>">
                                <?php echo esc_html($loc); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Filtra por localidad del asistente</small>
                </div>
            </div>

            <div class="evapp-filter-row">
                <div class="evapp-filter-field">
                    <label for="event_date">Fecha Específica del Evento</label>
                    <input type="date" name="filters[event_date]" id="event_date">
                    <small>Tickets válidos para esta fecha del evento</small>
                </div>
            </div>
        </div>

        <!-- Filtros de Fecha de Creación -->
        <div class="evapp-filter-section">
            <h3>📅 Fecha de Creación del Ticket</h3>
            
            <div class="evapp-filter-row">
                <div class="evapp-filter-field">
                    <label for="created_from">Creado Desde</label>
                    <input type="date" name="filters[created_from]" id="created_from">
                    <small>Fecha mínima de creación</small>
                </div>
                <div class="evapp-filter-field">
                    <label for="created_to">Creado Hasta</label>
                    <input type="date" name="filters[created_to]" id="created_to">
                    <small>Fecha máxima de creación</small>
                </div>
            </div>
        </div>

        <!-- Campos Adicionales Dinámicos -->
        <div class="evapp-filter-section" id="extraFieldsSection" style="display:none;">
            <h3>🔧 Campos Adicionales del Evento</h3>
            <div class="evapp-extra-fields-container" id="extraFieldsContainer">
                <p><em>Selecciona un evento primero para ver sus campos adicionales.</em></p>
            </div>
        </div>

        <!-- Botones de acción -->
        <div class="evapp-button-group">
            <button type="submit" class="button button-primary button-large">
                Crear Segmento y Continuar →
            </button>
        </div>
    </form>

    <script>
    jQuery(document).ready(function($){
        // Cargar campos extras cuando se selecciona un evento
        $('#evento_id').on('change', function(){
            var eventoId = $(this).val();
            var $section = $('#extraFieldsSection');
            var $container = $('#extraFieldsContainer');
            
            if (!eventoId) {
                $section.hide();
                $container.html('<p><em>Selecciona un evento primero para ver sus campos adicionales.</em></p>');
                return;
            }

            $container.html('<p>Cargando campos adicionales...</p>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'eventosapp_get_event_extra_fields',
                    event_id: eventoId,
                    _wpnonce: '<?php echo wp_create_nonce('eventosapp_get_extra_fields'); ?>'
                },
                success: function(response) {
                    if (response.success && response.data.fields && response.data.fields.length > 0) {
                        var html = '';
                        response.data.fields.forEach(function(field){
                            html += '<div class="evapp-extra-field-row">';
                            html += '<label><strong>' + field.label + ':</strong></label>';
                            
                            if (field.type === 'select' && field.options) {
                                html += '<select name="filters[extra_fields][' + field.key + ']">';
                                html += '<option value="">-- Todos --</option>';
                                field.options.forEach(function(opt){
                                    html += '<option value="' + opt + '">' + opt + '</option>';
                                });
                                html += '</select>';
                            } else {
                                html += '<input type="text" name="filters[extra_fields][' + field.key + ']" placeholder="Valor a buscar">';
                            }
                            html += '</div>';
                        });
                        $container.html(html);
                        $section.show();
                    } else {
                        $container.html('<p><em>Este evento no tiene campos adicionales configurados.</em></p>');
                        $section.show();
                    }
                },
                error: function() {
                    $container.html('<p style="color:red;"><em>Error al cargar los campos adicionales.</em></p>');
                    $section.show();
                }
            });
        });
    });
    </script>
    <?php
}

/**
 * STEP 2: Vista previa de los tickets que se enviarán
 */
function eventosapp_email_masivo_render_step2($segment_id) {
    if (empty($segment_id)) {
        echo '<div class="notice notice-error"><p>No hay segmento seleccionado. <a href="'.admin_url('admin.php?page=eventosapp_email_masivo').'">Volver al paso 1</a></p></div>';
        return;
    }

    $segment = get_option('evapp_email_segment_' . $segment_id);
    if (!$segment || !is_array($segment)) {
        echo '<div class="notice notice-error"><p>Segmento no encontrado. <a href="'.admin_url('admin.php?page=eventosapp_email_masivo').'">Volver al paso 1</a></p></div>';
        return;
    }

    $filters = $segment['filters'] ?? [];
    $ticket_ids = eventosapp_email_masivo_get_filtered_tickets($filters);
    $total = count($ticket_ids);

    // Guardar los IDs en el segmento
    $segment['ticket_ids'] = $ticket_ids;
    $segment['total'] = $total;
    update_option('evapp_email_segment_' . $segment_id, $segment, false);

    ?>
    <style>
        .evapp-preview-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .evapp-stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .evapp-stat-card h3 { margin: 0 0 10px 0; font-size: 14px; opacity: 0.9; }
        .evapp-stat-card .number { font-size: 36px; font-weight: bold; }
        .evapp-preview-table { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .evapp-preview-table table { width: 100%; border-collapse: collapse; }
        .evapp-preview-table th { background: #f0f0f1; padding: 12px; text-align: left; border-bottom: 2px solid #ddd; }
        .evapp-preview-table td { padding: 10px; border-bottom: 1px solid #eee; }
        .evapp-preview-table tr:hover { background: #f9f9f9; }
        .evapp-filters-applied { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 8px; margin: 20px 0; }
        .evapp-filters-applied h4 { margin-top: 0; color: #856404; }
        .evapp-filter-tag { display: inline-block; background: white; border: 1px solid #ffc107; padding: 5px 10px; margin: 5px; border-radius: 4px; font-size: 12px; }
    </style>

    <div class="evapp-filters-applied">
        <h4>📊 Filtros Aplicados</h4>
        <?php 
        $filter_labels = [
            'email_status' => 'Estado de Correo',
            'first_sent_from' => 'Primer Envío Desde',
            'first_sent_to' => 'Primer Envío Hasta',
            'last_sent_from' => 'Último Envío Desde',
            'last_sent_to' => 'Último Envío Hasta',
            'evento_id' => 'Evento',
            'localidad' => 'Localidad',
            'event_date' => 'Fecha del Evento',
            'created_from' => 'Creado Desde',
            'created_to' => 'Creado Hasta'
        ];

        $has_filters = false;
        foreach($filters as $key => $value) {
            if (empty($value)) continue;
            if ($key === 'extra_fields') continue; // Lo manejamos aparte
            
            $has_filters = true;
            $label = $filter_labels[$key] ?? $key;
            $display_value = $value;
            
            if ($key === 'evento_id') {
                $display_value = get_the_title($value);
            }
            
            echo '<span class="evapp-filter-tag"><strong>'.$label.':</strong> '.$display_value.'</span>';
        }

        // Campos extras
        if (!empty($filters['extra_fields']) && is_array($filters['extra_fields'])) {
            foreach($filters['extra_fields'] as $field_key => $field_value) {
                if (empty($field_value)) continue;
                $has_filters = true;
                echo '<span class="evapp-filter-tag"><strong>Campo Extra - '.$field_key.':</strong> '.$field_value.'</span>';
            }
        }

        if (!$has_filters) {
            echo '<p><em>Sin filtros aplicados - se mostrarán todos los tickets</em></p>';
        }
        ?>
    </div>

    <div class="evapp-preview-stats">
        <div class="evapp-stat-card">
            <h3>Total de Tickets</h3>
            <div class="number"><?php echo number_format($total); ?></div>
        </div>
        <div class="evapp-stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
            <h3>Correos a Enviar</h3>
            <div class="number"><?php echo number_format($total); ?></div>
        </div>
    </div>

    <?php if ($total > 0): ?>
        <div class="evapp-preview-table">
            <h3>Vista Previa de Tickets (primeros 20)</h3>
            <table>
                <thead>
                    <tr>
                        <th>ID Ticket</th>
                        <th>Asistente</th>
                        <th>Email</th>
                        <th>Evento</th>
                        <th>Localidad</th>
                        <th>Estado Email</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $preview_ids = array_slice($ticket_ids, 0, 20);
                    foreach($preview_ids as $tid):
                        $nombre = get_post_meta($tid, '_eventosapp_asistente_nombre', true);
                        $apellido = get_post_meta($tid, '_eventosapp_asistente_apellido', true);
                        $email = get_post_meta($tid, '_eventosapp_asistente_email', true);
                        $evento_id = get_post_meta($tid, '_eventosapp_ticket_evento_id', true);
                        $localidad = get_post_meta($tid, '_eventosapp_asistente_localidad', true);
                        $email_status = get_post_meta($tid, '_eventosapp_ticket_email_sent_status', true);
                        $ticket_id = get_post_meta($tid, 'eventosapp_ticketID', true);
                        
                        $status_colors = [
                            'enviado' => '#10b981',
                            'no_enviado' => '#6b7280',
                            'error' => '#ef4444'
                        ];
                        $status_color = $status_colors[$email_status] ?? '#6b7280';
                    ?>
                        <tr>
                            <td><code><?php echo esc_html($ticket_id); ?></code></td>
                            <td><?php echo esc_html($nombre . ' ' . $apellido); ?></td>
                            <td><?php echo esc_html($email); ?></td>
                            <td><?php echo esc_html(get_the_title($evento_id)); ?></td>
                            <td><?php echo esc_html($localidad); ?></td>
                            <td>
                                <span style="background: <?php echo $status_color; ?>; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px;">
                                    <?php echo esc_html($email_status ?: 'no_enviado'); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($total > 20): ?>
                <p style="margin-top: 15px; color: #646970;">
                    <em>Se muestran solo los primeros 20 tickets. Total a procesar: <strong><?php echo $total; ?></strong></em>
                </p>
            <?php endif; ?>
        </div>

        <div style="margin: 30px 0;">
            <a href="<?php echo admin_url('admin.php?page=eventosapp_email_masivo'); ?>" class="button">
                ← Volver a Filtros
            </a>
            <a href="<?php echo admin_url('admin.php?page=eventosapp_email_masivo&step=3&segment_id='.urlencode($segment_id)); ?>" class="button button-primary button-large" style="margin-left: 10px;">
                Continuar al Envío →
            </a>
        </div>

    <?php else: ?>
        <div class="notice notice-warning">
            <p><strong>No se encontraron tickets con los filtros aplicados.</strong></p>
            <p><a href="<?php echo admin_url('admin.php?page=eventosapp_email_masivo'); ?>">← Volver a ajustar filtros</a></p>
        </div>
    <?php endif; ?>
    <?php
}

/**
 * STEP 3: Ejecutar el envío masivo con barra de progreso
 */
function eventosapp_email_masivo_render_step3($segment_id) {
    if (empty($segment_id)) {
        echo '<div class="notice notice-error"><p>No hay segmento seleccionado.</p></div>';
        return;
    }

    $segment = get_option('evapp_email_segment_' . $segment_id);
    if (!$segment || !is_array($segment)) {
        echo '<div class="notice notice-error"><p>Segmento no encontrado.</p></div>';
        return;
    }

    $total = $segment['total'] ?? 0;
    $ticket_ids = $segment['ticket_ids'] ?? [];

    if ($total === 0 || empty($ticket_ids)) {
        echo '<div class="notice notice-warning"><p>No hay tickets para enviar.</p></div>';
        return;
    }

    ?>
    <style>
        .evapp-sending-container { max-width: 800px; margin: 20px auto; }
        .evapp-sending-card { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .evapp-progress-bar-container { width: 100%; height: 40px; background: #f0f0f1; border-radius: 20px; overflow: hidden; margin: 20px 0; position: relative; }
        .evapp-progress-bar { height: 100%; background: linear-gradient(90deg, #667eea 0%, #764ba2 100%); transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; }
        .evapp-stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 20px 0; }
        .evapp-stat-box { background: #f9f9f9; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #e0e0e0; }
        .evapp-stat-box .label { font-size: 12px; color: #646970; text-transform: uppercase; }
        .evapp-stat-box .value { font-size: 28px; font-weight: bold; color: #1d2327; margin-top: 5px; }
        .evapp-status-message { padding: 15px; margin: 15px 0; border-radius: 8px; }
        .evapp-status-processing { background: #e7f3ff; border-left: 4px solid #2271b1; }
        .evapp-status-complete { background: #d1fae5; border-left: 4px solid #10b981; }
        .evapp-status-error { background: #fee2e2; border-left: 4px solid #ef4444; }
        .evapp-log-container { max-height: 300px; overflow-y: auto; background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 12px; margin: 20px 0; }
        .evapp-log-entry { margin: 5px 0; }
        .evapp-log-success { color: #4ade80; }
        .evapp-log-error { color: #f87171; }
        .evapp-log-info { color: #60a5fa; }
    </style>

    <div class="evapp-sending-container">
        <div class="evapp-sending-card">
            <h2 style="margin-top: 0;">📤 Envío Masivo de Tickets</h2>
            
            <div class="evapp-status-message evapp-status-processing" id="statusMessage">
                <strong>🔄 Preparando envío...</strong>
                <p id="statusText">Inicializando sistema de envío masivo...</p>
            </div>

            <div class="evapp-progress-bar-container">
                <div class="evapp-progress-bar" id="progressBar" style="width: 0%;">
                    <span id="progressText">0%</span>
                </div>
            </div>

            <div class="evapp-stats-grid">
                <div class="evapp-stat-box">
                    <div class="label">Total</div>
                    <div class="value" id="statTotal"><?php echo $total; ?></div>
                </div>
                <div class="evapp-stat-box">
                    <div class="label">Enviados</div>
                    <div class="value" id="statSent" style="color: #10b981;">0</div>
                </div>
                <div class="evapp-stat-box">
                    <div class="label">Errores</div>
                    <div class="value" id="statErrors" style="color: #ef4444;">0</div>
                </div>
            </div>

            <details style="margin-top: 20px;">
                <summary style="cursor: pointer; font-weight: 600; color: #2271b1;">Ver log detallado</summary>
                <div class="evapp-log-container" id="logContainer">
                    <div class="evapp-log-entry evapp-log-info">[INFO] Sistema iniciado. Esperando inicio del proceso...</div>
                </div>
            </details>

            <div style="margin-top: 20px; text-align: center; display: none;" id="actionButtons">
                <a href="<?php echo admin_url('admin.php?page=eventosapp_email_masivo'); ?>" class="button button-primary button-large">
                    ← Volver al Inicio
                </a>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($){
        const segmentId = <?php echo json_encode($segment_id); ?>;
        const total = <?php echo $total; ?>;
        let processed = 0;
        let sent = 0;
        let errors = 0;
        let offset = 0;
        const batchSize = 10; // Enviar de 10 en 10

        function addLog(message, type = 'info') {
            const $log = $('#logContainer');
            const timestamp = new Date().toLocaleTimeString();
            const className = 'evapp-log-' + type;
            $log.append('<div class="evapp-log-entry ' + className + '">[' + timestamp + '] ' + message + '</div>');
            $log.scrollTop($log[0].scrollHeight);
        }

        function updateUI() {
            const percent = Math.round((processed / total) * 100);
            $('#progressBar').css('width', percent + '%');
            $('#progressText').text(percent + '%');
            $('#statSent').text(sent);
            $('#statErrors').text(errors);
            
            if (processed >= total) {
                $('#statusMessage')
                    .removeClass('evapp-status-processing')
                    .addClass('evapp-status-complete')
                    .html('<strong>✅ Envío Completado</strong><p>Se han procesado todos los tickets.</p>');
                $('#actionButtons').show();
                addLog('Proceso completado: ' + sent + ' enviados, ' + errors + ' errores', 'success');
            } else {
                $('#statusText').text('Procesando lote... (' + processed + ' de ' + total + ')');
            }
        }

        function processBatch() {
            if (offset >= total) {
                updateUI();
                return;
            }

            addLog('Procesando lote desde offset ' + offset, 'info');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'eventosapp_email_masivo_process_batch',
                    segment_id: segmentId,
                    offset: offset,
                    batch_size: batchSize,
                    _wpnonce: '<?php echo wp_create_nonce('eventosapp_email_masivo_process'); ?>'
                },
                timeout: 60000, // 60 segundos de timeout
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        processed += data.processed;
                        sent += data.sent;
                        errors += data.errors;
                        offset = data.next_offset;
                        
                        // Agregar logs individuales
                        if (data.logs && data.logs.length > 0) {
                            data.logs.forEach(function(log){
                                addLog(log.message, log.type);
                            });
                        }
                        
                        updateUI();
                        
                        // Continuar con el siguiente lote después de una pequeña pausa
                        if (offset < total) {
                            setTimeout(processBatch, 1000); // 1 segundo entre lotes
                        }
                    } else {
                        addLog('Error en el lote: ' + (response.data || 'Error desconocido'), 'error');
                        errors += batchSize;
                        processed += batchSize;
                        offset += batchSize;
                        updateUI();
                        
                        // Continuar a pesar del error
                        if (offset < total) {
                            setTimeout(processBatch, 2000);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    addLog('Error de conexión: ' + error, 'error');
                    errors += batchSize;
                    processed += batchSize;
                    offset += batchSize;
                    updateUI();
                    
                    // Continuar a pesar del error
                    if (offset < total) {
                        setTimeout(processBatch, 2000);
                    }
                }
            });
        }

        // Iniciar el proceso
        addLog('Iniciando envío masivo de ' + total + ' tickets', 'success');
        setTimeout(processBatch, 500);
    });
    </script>
    <?php
}

/**
 * Handler AJAX: Crear segmento con filtros
 */
add_action('admin_post_eventosapp_email_masivo_create_segment', function(){
    if (!current_user_can('manage_options')) {
        wp_die('No autorizado');
    }

    check_admin_referer('eventosapp_email_masivo_segment', 'evapp_email_masivo_nonce');

    $filters = isset($_POST['filters']) ? $_POST['filters'] : [];
    
    // Limpiar filtros vacíos
    $filters = array_filter($filters, function($value) {
        if (is_array($value)) {
            return !empty(array_filter($value));
        }
        return !empty($value);
    });

    // Crear ID único para el segmento
    $segment_id = 'seg_' . time() . '_' . wp_generate_password(8, false);
    
    // Guardar segmento
    $segment = [
        'id' => $segment_id,
        'filters' => $filters,
        'created_at' => current_time('mysql'),
        'created_by' => get_current_user_id()
    ];
    
    update_option('evapp_email_segment_' . $segment_id, $segment, false);

    // Redirigir al paso 2
    wp_redirect(admin_url('admin.php?page=eventosapp_email_masivo&step=2&segment_id=' . urlencode($segment_id)));
    exit;
});

/**
 * Handler AJAX: Obtener campos extras de un evento
 */
add_action('wp_ajax_eventosapp_get_event_extra_fields', function(){
    check_ajax_referer('eventosapp_get_extra_fields');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No autorizado');
    }

    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    
    if (!$event_id) {
        wp_send_json_error('ID de evento inválido');
    }

    $fields = [];
    
    // Obtener campos adicionales del evento
    if (function_exists('eventosapp_get_event_extra_fields')) {
        $extra_fields = eventosapp_get_event_extra_fields($event_id);
        if (is_array($extra_fields)) {
            foreach($extra_fields as $field) {
                $fields[] = [
                    'key' => $field['key'] ?? '',
                    'label' => $field['label'] ?? '',
                    'type' => $field['type'] ?? 'text',
                    'options' => $field['options'] ?? []
                ];
            }
        }
    }

    wp_send_json_success(['fields' => $fields]);
});

/**
 * Handler AJAX: Procesar lote de envíos
 */
add_action('wp_ajax_eventosapp_email_masivo_process_batch', function(){
    check_ajax_referer('eventosapp_email_masivo_process');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No autorizado');
    }

    $segment_id = isset($_POST['segment_id']) ? sanitize_text_field($_POST['segment_id']) : '';
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;

    $segment = get_option('evapp_email_segment_' . $segment_id);
    if (!$segment || !is_array($segment)) {
        wp_send_json_error('Segmento no encontrado');
    }

    $ticket_ids = $segment['ticket_ids'] ?? [];
    $batch = array_slice($ticket_ids, $offset, $batch_size);
    
    $sent = 0;
    $errors = 0;
    $logs = [];

    foreach($batch as $ticket_id) {
        $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
        $email = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
        
        if (!$email || !is_email($email)) {
            $errors++;
            $logs[] = [
                'message' => "Ticket {$ticket_code}: Email inválido",
                'type' => 'error'
            ];
            continue;
        }

        // Enviar usando la función existente
        if (function_exists('eventosapp_send_ticket_email_now')) {
            list($ok, $msg) = eventosapp_send_ticket_email_now($ticket_id, [
                'source' => 'bulk',
                'force' => false // No forzar reenvío si ya se envió
            ]);

            if ($ok) {
                $sent++;
                $logs[] = [
                    'message' => "Ticket {$ticket_code}: Enviado correctamente a {$email}",
                    'type' => 'success'
                ];
            } else {
                $errors++;
                $logs[] = [
                    'message' => "Ticket {$ticket_code}: Error - " . ($msg ?: 'Desconocido'),
                    'type' => 'error'
                ];
            }
        } else {
            $errors++;
            $logs[] = [
                'message' => "Ticket {$ticket_code}: Función de envío no disponible",
                'type' => 'error'
            ];
        }

        // Pequeña pausa entre envíos
        usleep(100000); // 0.1 segundos
    }

    wp_send_json_success([
        'processed' => count($batch),
        'sent' => $sent,
        'errors' => $errors,
        'next_offset' => $offset + $batch_size,
        'logs' => $logs
    ]);
});

/**
 * Obtener tickets filtrados según criterios
 */
function eventosapp_email_masivo_get_filtered_tickets($filters) {
    $args = [
        'post_type' => 'eventosapp_ticket',
        'post_status' => 'any',
        'fields' => 'ids',
        'posts_per_page' => -1,
        'no_found_rows' => true,
    ];

    $meta_query = ['relation' => 'AND'];
    $date_query = [];

    // Filtro por evento
    if (!empty($filters['evento_id'])) {
        $meta_query[] = [
            'key' => '_eventosapp_ticket_evento_id',
            'value' => intval($filters['evento_id']),
            'compare' => '='
        ];
    }

    // Filtro por localidad
    if (!empty($filters['localidad'])) {
        $meta_query[] = [
            'key' => '_eventosapp_asistente_localidad',
            'value' => sanitize_text_field($filters['localidad']),
            'compare' => '='
        ];
    }

    // Filtro por estado de email - CORREGIDO para manejar no_enviado correctamente
    if (!empty($filters['email_status'])) {
        $email_status = sanitize_text_field($filters['email_status']);
        
        if ($email_status === 'no_enviado') {
            // Para no_enviado: buscar tickets que TENGAN el valor 'no_enviado' O que NO TENGAN el meta field
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key' => '_eventosapp_ticket_email_sent_status',
                    'value' => 'no_enviado',
                    'compare' => '='
                ],
                [
                    'key' => '_eventosapp_ticket_email_sent_status',
                    'compare' => 'NOT EXISTS'
                ]
            ];
        } else {
            // Para enviado o error: buscar normalmente
            $meta_query[] = [
                'key' => '_eventosapp_ticket_email_sent_status',
                'value' => $email_status,
                'compare' => '='
            ];
        }
    }

    // Filtro por fecha de primer envío
    if (!empty($filters['first_sent_from']) || !empty($filters['first_sent_to'])) {
        $date_meta = [
            'key' => '_eventosapp_ticket_email_first_sent',
            'type' => 'DATETIME'
        ];
        
        if (!empty($filters['first_sent_from']) && !empty($filters['first_sent_to'])) {
            $date_meta['value'] = [
                sanitize_text_field($filters['first_sent_from']) . ' 00:00:00',
                sanitize_text_field($filters['first_sent_to']) . ' 23:59:59'
            ];
            $date_meta['compare'] = 'BETWEEN';
        } elseif (!empty($filters['first_sent_from'])) {
            $date_meta['value'] = sanitize_text_field($filters['first_sent_from']) . ' 00:00:00';
            $date_meta['compare'] = '>=';
        } elseif (!empty($filters['first_sent_to'])) {
            $date_meta['value'] = sanitize_text_field($filters['first_sent_to']) . ' 23:59:59';
            $date_meta['compare'] = '<=';
        }
        
        $meta_query[] = $date_meta;
    }

    // Filtro por fecha de último envío
    if (!empty($filters['last_sent_from']) || !empty($filters['last_sent_to'])) {
        $date_meta = [
            'key' => '_eventosapp_ticket_last_email_at',
            'type' => 'DATETIME'
        ];
        
        if (!empty($filters['last_sent_from']) && !empty($filters['last_sent_to'])) {
            $date_meta['value'] = [
                sanitize_text_field($filters['last_sent_from']) . ' 00:00:00',
                sanitize_text_field($filters['last_sent_to']) . ' 23:59:59'
            ];
            $date_meta['compare'] = 'BETWEEN';
        } elseif (!empty($filters['last_sent_from'])) {
            $date_meta['value'] = sanitize_text_field($filters['last_sent_from']) . ' 00:00:00';
            $date_meta['compare'] = '>=';
        } elseif (!empty($filters['last_sent_to'])) {
            $date_meta['value'] = sanitize_text_field($filters['last_sent_to']) . ' 23:59:59';
            $date_meta['compare'] = '<=';
        }
        
        $meta_query[] = $date_meta;
    }

    // Filtro por fecha de creación del ticket
    if (!empty($filters['created_from']) || !empty($filters['created_to'])) {
        if (!empty($filters['created_from']) && !empty($filters['created_to'])) {
            $date_query = [
                'after' => sanitize_text_field($filters['created_from']) . ' 00:00:00',
                'before' => sanitize_text_field($filters['created_to']) . ' 23:59:59',
                'inclusive' => true
            ];
        } elseif (!empty($filters['created_from'])) {
            $date_query = [
                'after' => sanitize_text_field($filters['created_from']) . ' 00:00:00',
                'inclusive' => true
            ];
        } elseif (!empty($filters['created_to'])) {
            $date_query = [
                'before' => sanitize_text_field($filters['created_to']) . ' 23:59:59',
                'inclusive' => true
            ];
        }
    }

    // Campos adicionales
    if (!empty($filters['extra_fields']) && is_array($filters['extra_fields'])) {
        foreach($filters['extra_fields'] as $field_key => $field_value) {
            if (empty($field_value)) continue;
            
            $meta_query[] = [
                'key' => '_eventosapp_extra_' . sanitize_key($field_key),
                'value' => sanitize_text_field($field_value),
                'compare' => 'LIKE'
            ];
        }
    }

    if (count($meta_query) > 1) {
        $args['meta_query'] = $meta_query;
    }

    if (!empty($date_query)) {
        $args['date_query'] = $date_query;
    }

    $query = new WP_Query($args);
    $ticket_ids = $query->posts;

    // Filtro adicional por fecha específica del evento
    if (!empty($filters['event_date'])) {
        $event_date = sanitize_text_field($filters['event_date']);
        $filtered_ids = [];
        
        foreach($ticket_ids as $tid) {
            $evento_id = get_post_meta($tid, '_eventosapp_ticket_evento_id', true);
            if ($evento_id && function_exists('eventosapp_get_event_days')) {
                $event_days = eventosapp_get_event_days($evento_id);
                if (in_array($event_date, $event_days)) {
                    $filtered_ids[] = $tid;
                }
            }
        }
        
        $ticket_ids = $filtered_ids;
    }

    return $ticket_ids;
}
