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
 * Log interno del módulo de envío masivo.
 * Mantiene trazabilidad en WP_DEBUG/error_log sin exponer reglas completas ni payloads sensibles en el navegador.
 */
if (!function_exists('eventosapp_email_masivo_debug_log')) {
    function eventosapp_email_masivo_debug_log($message, $context = []) {
        $line = 'EVENTOSAPP EMAIL MASIVO | ' . trim((string) $message);
        if (is_array($context) && !empty($context)) {
            $encoded = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded) {
                $line .= ' | ' . $encoded;
            }
        }
        error_log($line);
    }
}

/**
 * Prepara la variante efectiva del ticket antes de mostrarlo o enviarlo por correo masivo.
 *
 * Compatibilidad buscada:
 * - Si existe el helper masivo de variantes, se usa para sincronizar clases Google Wallet por evento/contexto.
 * - Si el helper masivo no existe, se hace fallback a eventosapp_ticket_variants_apply_to_ticket().
 * - Si el módulo de variantes no está cargado, no bloquea el envío y deja trazabilidad segura.
 *
 * @param int    $ticket_id ID del ticket.
 * @param string $context   Contexto corto para logs/metas.
 * @return array Resumen seguro para logs/UI.
 */
if (!function_exists('eventosapp_email_masivo_prepare_ticket_variant')) {
    function eventosapp_email_masivo_prepare_ticket_variant($ticket_id, $context = 'email_bulk') {
        $ticket_id = absint($ticket_id);
        $event_id  = $ticket_id ? absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true)) : 0;
        $context   = sanitize_key((string) $context);
        if ($context === '') {
            $context = 'email_bulk';
        }

        $summary = [
            'ok'                    => false,
            'ticket_id'             => $ticket_id,
            'event_id'              => $event_id,
            'context'               => $context,
            'applied'               => false,
            'reason'                => '',
            'variant_key'           => '',
            'variant_name'          => '',
            'matched_index'         => null,
            'changed'               => false,
            'email_template'        => '',
            'email_template_path'   => '',
            'email_header_image'    => '',
            'google_wallet_class'   => '',
            'google_class_source'   => '',
            'google_classes_synced' => false,
        ];

        if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
            $summary['reason'] = 'ticket_invalid';
            eventosapp_email_masivo_debug_log('Variante no preparada: ticket inválido', $summary);
            return $summary;
        }

        if (!$event_id) {
            $summary['reason'] = 'event_missing';
            eventosapp_email_masivo_debug_log('Variante no preparada: ticket sin evento', $summary);
            return $summary;
        }

        if (function_exists('eventosapp_ticket_variants_prepare_ticket_for_batch_context')) {
            try {
                $prepared = eventosapp_ticket_variants_prepare_ticket_for_batch_context($ticket_id, $event_id, $context, [
                    'sync_google_classes' => true,
                    'mark_assets_stale'   => false,
                    'clear_assets_stale'  => false,
                    'log'                 => true,
                ]);

                if (is_array($prepared)) {
                    $summary = array_merge($summary, [
                        'ok'                    => !empty($prepared['ok']),
                        'applied'               => !empty($prepared['applied']),
                        'reason'                => (string) ($prepared['reason'] ?? ''),
                        'variant_key'           => (string) ($prepared['variant_key'] ?? ''),
                        'variant_name'          => (string) ($prepared['variant_name'] ?? ''),
                        'matched_index'         => array_key_exists('matched_index', $prepared) ? $prepared['matched_index'] : null,
                        'changed'               => !empty($prepared['changed']),
                        'google_classes_synced' => !empty($prepared['google_classes_synced']),
                    ]);
                }
            } catch (Throwable $e) {
                $summary['ok'] = false;
                $summary['reason'] = 'variant_prepare_exception';
                eventosapp_email_masivo_debug_log('Error preparando variante para correo masivo', [
                    'ticket_id' => $ticket_id,
                    'event_id'  => $event_id,
                    'context'   => $context,
                    'error'     => $e->getMessage(),
                ]);
            }
        } elseif (function_exists('eventosapp_ticket_variants_apply_to_ticket')) {
            try {
                $applied = eventosapp_ticket_variants_apply_to_ticket($ticket_id, $event_id, true);
                if (!is_array($applied)) {
                    $applied = [];
                }

                $summary['ok']            = true;
                $summary['applied']       = !empty($applied['applied']);
                $summary['reason']        = (string) ($applied['reason'] ?? ($summary['applied'] ? 'applied' : 'not_applied'));
                $summary['variant_key']   = (string) ($applied['variant_key'] ?? get_post_meta($ticket_id, '_eventosapp_ticket_variant_key', true));
                $summary['variant_name']  = (string) ($applied['variant_name'] ?? get_post_meta($ticket_id, '_eventosapp_ticket_variant_name', true));
                $summary['matched_index'] = array_key_exists('matched_index', $applied) ? $applied['matched_index'] : null;
            } catch (Throwable $e) {
                $summary['ok'] = false;
                $summary['reason'] = 'variant_apply_exception';
                eventosapp_email_masivo_debug_log('Error aplicando variante para correo masivo', [
                    'ticket_id' => $ticket_id,
                    'event_id'  => $event_id,
                    'context'   => $context,
                    'error'     => $e->getMessage(),
                ]);
            }
        } else {
            $summary['ok']     = true;
            $summary['reason'] = 'variants_module_not_loaded';
        }

        $summary['variant_key']         = (string) get_post_meta($ticket_id, '_eventosapp_ticket_variant_key', true);
        $summary['variant_name']        = (string) get_post_meta($ticket_id, '_eventosapp_ticket_variant_name', true);
        $summary['email_template']      = (string) get_post_meta($ticket_id, '_eventosapp_ticket_email_template_override', true);
        $summary['email_template_path'] = (string) get_post_meta($ticket_id, '_eventosapp_ticket_email_template_path', true);
        $summary['email_header_image']  = (string) get_post_meta($ticket_id, '_eventosapp_ticket_email_header_image_url', true);
        $summary['google_wallet_class'] = (string) get_post_meta($ticket_id, '_eventosapp_wallet_variant_class_id', true);
        $summary['google_class_source'] = (string) get_post_meta($ticket_id, '_eventosapp_wallet_variant_class_source', true);

        update_post_meta($ticket_id, '_eventosapp_email_masivo_variant_last_context', $context);
        update_post_meta($ticket_id, '_eventosapp_email_masivo_variant_last_at', current_time('mysql'));
        update_post_meta($ticket_id, '_eventosapp_email_masivo_variant_last_result', [
            'context'               => $summary['context'],
            'applied'               => $summary['applied'],
            'reason'                => $summary['reason'],
            'variant_key'           => $summary['variant_key'],
            'variant_name'          => $summary['variant_name'],
            'matched_index'         => $summary['matched_index'],
            'changed'               => $summary['changed'],
            'email_template'        => $summary['email_template'],
            'google_wallet_class'   => $summary['google_wallet_class'],
            'google_class_source'   => $summary['google_class_source'],
            'google_classes_synced' => $summary['google_classes_synced'],
        ]);

        eventosapp_email_masivo_debug_log('Variante preparada para correo masivo', [
            'ticket_id'             => $ticket_id,
            'event_id'              => $event_id,
            'context'               => $context,
            'applied'               => $summary['applied'] ? 'yes' : 'no',
            'reason'                => $summary['reason'],
            'variant_key'           => $summary['variant_key'],
            'variant_name'          => $summary['variant_name'],
            'email_template'        => $summary['email_template'],
            'google_wallet_class'   => $summary['google_wallet_class'],
            'google_class_source'   => $summary['google_class_source'],
            'google_classes_synced' => $summary['google_classes_synced'] ? 'yes' : 'no',
        ]);

        return $summary;
    }
}

/**
 * Texto corto y seguro para la UI/log del envío masivo.
 */
if (!function_exists('eventosapp_email_masivo_format_variant_label')) {
    function eventosapp_email_masivo_format_variant_label($variant_summary) {
        if (!is_array($variant_summary)) {
            return 'Plantilla normal del evento';
        }

        if (!empty($variant_summary['applied'])) {
            $name = trim((string) ($variant_summary['variant_name'] ?: $variant_summary['variant_key']));
            $label = $name !== '' ? ('Variante: ' . $name) : 'Variante aplicada';
            if (!empty($variant_summary['email_template'])) {
                $label .= ' · plantilla: ' . sanitize_file_name($variant_summary['email_template']);
            }
            return $label;
        }

        $reason = (string) ($variant_summary['reason'] ?? '');
        if ($reason === 'variants_module_not_loaded') {
            return 'Plantilla normal del evento · módulo de variantes no cargado';
        }
        if ($reason === 'disabled') {
            return 'Plantilla normal del evento · variantes desactivadas';
        }
        if ($reason === 'no_match') {
            return 'Plantilla normal del evento · sin variante coincidente';
        }
        if ($reason === 'event_missing') {
            return 'Sin evento asignado';
        }

        return 'Plantilla normal del evento';
    }
}

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

                <div class="evapp-filter-field">
                    <label for="modalidad">Modalidad del Ticket</label>
                    <select name="filters[modalidad]" id="modalidad">
                        <option value="">-- Todas las modalidades --</option>
                        <option value="presencial">Presencial</option>
                        <option value="virtual">Virtual</option>
                    </select>
                    <small>Filtra tickets presenciales o virtuales</small>
                </div>
            </div>

            <div class="evapp-filter-row">
                <div class="evapp-filter-field">
                    <label for="confirmation_status">Confirmación de asistencia</label>
                    <select name="filters[confirmation_status]" id="confirmation_status">
                        <option value="">-- Todos los estados --</option>
                        <option value="si">Sí</option>
                        <option value="no">No</option>
                        <option value="no_responde">No responde</option>
                        <option value="sin_consulta">Sin consulta</option>
                    </select>
                    <small>Usa el estado registrado por el módulo de confirmación de asistencia.</small>
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
        .evapp-variant-badge { display: inline-block; background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe; padding: 3px 8px; border-radius: 999px; font-size: 11px; line-height: 1.4; }
        .evapp-variant-badge-normal { background: #f3f4f6; color: #374151; border-color: #d1d5db; }
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
            'modalidad' => 'Modalidad del Ticket',
            'confirmation_status' => 'Confirmación de asistencia',
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
            if ($key === 'modalidad') {
                $modalidad_opts = function_exists('eventosapp_ticket_modalidad_options') ? eventosapp_ticket_modalidad_options() : ['presencial' => 'Presencial', 'virtual' => 'Virtual'];
                $display_value = $modalidad_opts[$value] ?? $value;
            }
            if ($key === 'confirmation_status') {
                $display_value = function_exists('eventosapp_attendance_confirmation_status_label')
                    ? eventosapp_attendance_confirmation_status_label($value)
                    : (['si'=>'Sí','no'=>'No','no_responde'=>'No responde','sin_consulta'=>'Sin consulta'][$value] ?? $value);
            }
            
            echo '<span class="evapp-filter-tag"><strong>'.esc_html($label).':</strong> '.esc_html($display_value).'</span>';
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

    <div class="notice notice-info" style="margin: 15px 0;">
        <p><strong>Compatibilidad con variantes:</strong> antes de mostrar la vista previa y antes de cada envío, el sistema evalúa la variante efectiva del ticket. Si el ticket coincide con una variante, se usará su plantilla de correo, cabezote y branding configurado; si no coincide, se mantiene la plantilla normal del evento.</p>
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
                        <th>Modalidad</th>
                        <th>Confirmación</th>
                        <th>Variante / Plantilla</th>
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
                        $modalidad_label = function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label($tid) : ucfirst((string) get_post_meta($tid, '_eventosapp_ticket_modalidad', true));
                        $email_status = get_post_meta($tid, '_eventosapp_ticket_email_sent_status', true);
                        $confirmation_label = function_exists('eventosapp_attendance_confirmation_get_ticket_field_value') ? eventosapp_attendance_confirmation_get_ticket_field_value($tid, 'attendance_confirmation_status', true) : 'Sin consulta';
                        $ticket_id = get_post_meta($tid, 'eventosapp_ticketID', true);
                        $variant_summary = eventosapp_email_masivo_prepare_ticket_variant($tid, 'email_bulk_preview');
                        $variant_label = eventosapp_email_masivo_format_variant_label($variant_summary);
                        $variant_badge_class = !empty($variant_summary['applied']) ? 'evapp-variant-badge' : 'evapp-variant-badge evapp-variant-badge-normal';
                        
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
                            <td><?php echo esc_html($modalidad_label ?: 'Presencial'); ?></td>
                            <td><?php echo esc_html($confirmation_label); ?></td>
                            <td><span class="<?php echo esc_attr($variant_badge_class); ?>"><?php echo esc_html($variant_label); ?></span></td>
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

    $bulk_limits = function_exists('eventosapp_attendance_confirmation_bulk_limits')
        ? eventosapp_attendance_confirmation_bulk_limits('email_bulk', ['email'])
        : ['batch_size'=>5,'ajax_delay_ms'=>5000,'max_execution'=>20,'memory_stop_ratio'=>0.72,'lock_ttl'=>180];

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
            <p class="description">Protección activa: máximo <?php echo esc_html($bulk_limits['batch_size']); ?> correos por solicitud, pausa de <?php echo esc_html(number_format($bulk_limits['ajax_delay_ms']/1000,1,',','.')); ?> segundos, bloqueo de concurrencia y corte preventivo por memoria/tiempo.</p>
            
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
        const batchSize = <?php echo (int)$bulk_limits['batch_size']; ?>;
        const batchDelay = <?php echo (int)$bulk_limits['ajax_delay_ms']; ?>;

        function addLog(message, type = 'info') {
            const $log = $('#logContainer');
            const timestamp = new Date().toLocaleTimeString();
            const className = 'evapp-log-' + type;
            const safeMessage = $('<div>').text(message || '').html();
            $log.append('<div class="evapp-log-entry ' + className + '">[' + timestamp + '] ' + safeMessage + '</div>');
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
                            setTimeout(processBatch, parseInt(data.retry_after_ms || batchDelay, 10));
                        }
                    } else {
                        const responseData = response.data || {};
                        const message = typeof responseData === 'object' && responseData.message
                            ? responseData.message
                            : (responseData || 'Error desconocido');
                        const retryDelay = typeof responseData === 'object' && responseData.retry_after_ms
                            ? parseInt(responseData.retry_after_ms, 10)
                            : batchDelay * 2;
                        addLog('Lote no procesado: ' + message + '. Se reintentará sin avanzar el cursor.', 'error');
                        if (offset < total) {
                            setTimeout(processBatch, Math.max(batchDelay, retryDelay));
                        }
                    }
                },
                error: function(xhr, status, error) {
                    addLog('Error de conexión. No se avanza el cursor para evitar perder tickets. Se reintentará.', 'error');
                    if (offset < total) {
                        setTimeout(processBatch, batchDelay * 2);
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
    if (!current_user_can('manage_options')) wp_send_json_error('No autorizado');

    $segment_id = isset($_POST['segment_id']) ? sanitize_key((string)wp_unslash($_POST['segment_id'])) : '';
    $offset = isset($_POST['offset']) ? max(0, absint($_POST['offset'])) : 0;
    $segment = get_option('evapp_email_segment_' . $segment_id);
    if (!$segment || !is_array($segment)) wp_send_json_error('Segmento no encontrado');

    $limits = function_exists('eventosapp_attendance_confirmation_bulk_limits')
        ? eventosapp_attendance_confirmation_bulk_limits('email_bulk', ['email'])
        : ['batch_size'=>5,'ajax_delay_ms'=>5000,'max_execution'=>20,'memory_stop_ratio'=>0.72,'lock_ttl'=>180];
    $scope = 'email_bulk_segment:' . $segment_id;
    $lock = function_exists('eventosapp_attendance_confirmation_acquire_lock')
        ? eventosapp_attendance_confirmation_acquire_lock($scope, $limits['lock_ttl'])
        : wp_generate_password(12, false, false);

    if (is_wp_error($lock)) {
        wp_send_json_success([
            'busy'=>true,'processed'=>0,'sent'=>0,'errors'=>0,'next_offset'=>$offset,
            'retry_after_ms'=>$limits['ajax_delay_ms']*2,'logs'=>[['message'=>$lock->get_error_message(),'type'=>'info']],
        ]);
    }

    $response=[];
    try {
        $ticket_ids = $segment['ticket_ids'] ?? [];
        $batch = array_values(array_filter(array_map('absint', array_slice($ticket_ids, $offset, $limits['batch_size']))));
        if (!empty($batch)) update_meta_cache('post', $batch);
        $sent=0; $errors=0; $logs=[]; $processed=0; $started=microtime(true); $resource_guard=false;

        foreach($batch as $ticket_id) {
            if (function_exists('eventosapp_attendance_confirmation_should_yield') && eventosapp_attendance_confirmation_should_yield($started,$processed,$limits)) {
                $resource_guard=true;
                break;
            }
            $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
            $email = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
            $processed++;

            if (!$email || !is_email($email)) {
                $errors++;
                $logs[]=['message'=>"Ticket {$ticket_code}: Email inválido",'type'=>'error'];
                continue;
            }

            $variant_summary = eventosapp_email_masivo_prepare_ticket_variant($ticket_id, 'email_bulk_send');
            $variant_label = eventosapp_email_masivo_format_variant_label($variant_summary);
            $logs[]=['message'=>"Ticket {$ticket_code}: {$variant_label}",'type'=>'info'];

            if (function_exists('eventosapp_send_ticket_email_now')) {
                list($ok,$msg)=eventosapp_send_ticket_email_now($ticket_id,[
                    'source'=>'bulk','force'=>false,
                    'variant_context'=>[
                        'applied'=>!empty($variant_summary['applied']),
                        'reason'=>(string)($variant_summary['reason']??''),
                        'variant_key'=>(string)($variant_summary['variant_key']??''),
                        'variant_name'=>(string)($variant_summary['variant_name']??''),
                        'email_template'=>(string)($variant_summary['email_template']??''),
                    ],
                ]);
                if($ok){$sent++;$logs[]=['message'=>"Ticket {$ticket_code}: Enviado correctamente a {$email} ({$variant_label})",'type'=>'success'];}
                else{$errors++;$logs[]=['message'=>"Ticket {$ticket_code}: Error - ".($msg?:'Desconocido')." ({$variant_label})",'type'=>'error'];}
            } else {
                $errors++;
                $logs[]=['message'=>"Ticket {$ticket_code}: Función de envío no disponible ({$variant_label})",'type'=>'error'];
            }

            usleep(120000);
        }

        $response=[
            'busy'=>false,'processed'=>$processed,'sent'=>$sent,'errors'=>$errors,
            'next_offset'=>$offset+$processed,'logs'=>$logs,'retry_after_ms'=>$limits['ajax_delay_ms'],
            'resource_guard_triggered'=>$resource_guard,
        ];
    } finally {
        if (function_exists('eventosapp_attendance_confirmation_release_lock')) eventosapp_attendance_confirmation_release_lock($scope,$lock);
    }

    wp_send_json_success($response);
});

/**
 * Obtener tickets filtrados según criterios
 */
function eventosapp_email_masivo_get_filtered_tickets($filters) {
    $args = [
        'post_type' => 'eventosapp_ticket',
        'post_status' => 'any',
        'fields' => 'ids',
        'posts_per_page' => 300,
        'orderby' => 'ID',
        'order' => 'ASC',
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

    // Filtro por confirmación de asistencia. "Sin consulta" incluye tickets antiguos sin meta.
    if (!empty($filters['confirmation_status'])) {
        if (function_exists('eventosapp_attendance_confirmation_status_meta_query')) {
            $clause = eventosapp_attendance_confirmation_status_meta_query($filters['confirmation_status']);
            if (is_array($clause)) $meta_query[] = $clause;
        } else {
            $status = sanitize_key((string)$filters['confirmation_status']);
            if ($status === 'sin_consulta') {
                $meta_query[] = ['relation'=>'OR',
                    ['key'=>'_eventosapp_attendance_confirmation_status','value'=>'sin_consulta','compare'=>'='],
                    ['key'=>'_eventosapp_attendance_confirmation_status','compare'=>'NOT EXISTS'],
                ];
            } elseif (in_array($status,['si','no','no_responde'],true)) {
                $meta_query[] = ['key'=>'_eventosapp_attendance_confirmation_status','value'=>$status,'compare'=>'='];
            }
        }
    }

    $modalidad_filter = '';
    if (!empty($filters['modalidad'])) {
        $modalidad_filter = function_exists('eventosapp_normalize_ticket_modalidad')
            ? eventosapp_normalize_ticket_modalidad($filters['modalidad'])
            : sanitize_key((string) $filters['modalidad']);

        if (!in_array($modalidad_filter, ['presencial', 'virtual'], true)) {
            $modalidad_filter = '';
        }

        // No se agrega meta_query aquí porque la modalidad efectiva puede depender del evento:
        // eventos Virtuales fuerzan todos sus tickets a Virtual aunque el meta del ticket esté vacío.
        // El filtro exacto se aplica más abajo con eventosapp_get_ticket_modalidad().
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

    // Consulta paginada: evita que WordPress intente resolver todos los tickets
    // en una sola operación SQL/PHP cuando el evento tiene una base grande.
    $ticket_ids = [];
    $page = 1;
    $max_pages_guard = 10000;
    do {
        $args['paged'] = $page;
        $query = new WP_Query($args);
        $page_ids = array_values(array_filter(array_map('absint', (array)$query->posts)));
        if ( empty($page_ids) ) {
            unset($query);
            break;
        }
        update_meta_cache('post', $page_ids);
        $ticket_ids = array_merge($ticket_ids, $page_ids);
        $page_count = count($page_ids);
        unset($query, $page_ids);
        $page++;
    } while ( $page_count >= (int)$args['posts_per_page'] && $page <= $max_pages_guard );

    $ticket_ids = array_values(array_unique($ticket_ids));

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

    if (!empty($modalidad_filter) && function_exists('eventosapp_get_ticket_modalidad')) {
        $ticket_ids = array_values(array_filter($ticket_ids, function($tid) use ($modalidad_filter) {
            return eventosapp_get_ticket_modalidad($tid) === $modalidad_filter;
        }));
    }

    return $ticket_ids;
}
