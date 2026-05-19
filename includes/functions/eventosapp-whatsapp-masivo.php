<?php
/**
 * WhatsApp Ticket Masivo - Sistema de envío masivo con selección de plantilla
 *
 * Crea una pantalla independiente para enviar tickets por WhatsApp en lotes,
 * usando filtros similares al envío masivo de email y permitiendo seleccionar
 * la plantilla aprobada por Meta que se usará en toda la campaña.
 *
 * Este archivo no modifica el flujo actual de envíos manuales/automáticos.
 * Usa los helpers existentes de WhatsApp, plantillas, QR, variantes y assets
 * para mantener compatibilidad con ticket landing, QR WhatsApp, Wallet, PDF,
 * ICS, modalidad y variantes.
 *
 * Ruta recomendada dentro del plugin:
 * includes/functions/eventosapp-whatsapp-masivo.php
 *
 * @package EventosApp
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Log interno del módulo de WhatsApp masivo.
 */
if ( ! function_exists('eventosapp_whatsapp_masivo_debug_log') ) {
    function eventosapp_whatsapp_masivo_debug_log($message, $context = []) {
        $line = 'EVENTOSAPP WHATSAPP MASIVO | ' . trim((string) $message);
        if ( is_array($context) && ! empty($context) ) {
            $encoded = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ( $encoded ) {
                $line .= ' | ' . $encoded;
            }
        }
        error_log($line);
    }
}

/**
 * Registra también en el activity log general de WhatsApp cuando está disponible.
 */
if ( ! function_exists('eventosapp_whatsapp_masivo_activity_log') ) {
    function eventosapp_whatsapp_masivo_activity_log($event, $context = []) {
        if ( function_exists('eventosapp_whatsapp_add_activity_log') ) {
            eventosapp_whatsapp_add_activity_log($event, is_array($context) ? $context : []);
            return;
        }
        eventosapp_whatsapp_masivo_debug_log($event, is_array($context) ? $context : []);
    }
}

/**
 * ID único del option usado para guardar temporalmente segmentos.
 */
if ( ! function_exists('eventosapp_whatsapp_masivo_segment_option_key') ) {
    function eventosapp_whatsapp_masivo_segment_option_key($segment_id) {
        return 'evapp_whatsapp_segment_' . sanitize_key((string) $segment_id);
    }
}

/**
 * Obtiene plantillas runtime desde el módulo de plantillas WhatsApp.
 */
if ( ! function_exists('eventosapp_whatsapp_masivo_get_templates') ) {
    function eventosapp_whatsapp_masivo_get_templates($only_approved = false) {
        if ( ! function_exists('eventosapp_whatsapp_templates_get_settings') ) {
            return [];
        }

        $settings = eventosapp_whatsapp_templates_get_settings();
        $templates = isset($settings['templates']) && is_array($settings['templates']) ? $settings['templates'] : [];
        $result = [];

        foreach ( $templates as $template_key => $template ) {
            if ( ! is_array($template) ) {
                continue;
            }

            if ( function_exists('eventosapp_whatsapp_prepare_runtime_template') ) {
                $runtime = eventosapp_whatsapp_prepare_runtime_template($template, $template_key);
            } else {
                $runtime = $template;
                $runtime['id'] = sanitize_key((string)($runtime['id'] ?? $template_key));
                $runtime['name'] = sanitize_key((string)($runtime['name'] ?? ''));
                $runtime['language'] = sanitize_text_field((string)($runtime['language'] ?? 'es'));
                $runtime['meta_status'] = strtoupper(sanitize_text_field((string)($runtime['meta_status'] ?? 'LOCAL')));
            }

            $runtime['_storage_key'] = sanitize_key((string) $template_key);

            if ( empty($runtime['id']) ) {
                $runtime['id'] = sanitize_key((string) $template_key);
            }

            $approved = function_exists('eventosapp_whatsapp_is_template_approved')
                ? eventosapp_whatsapp_is_template_approved($runtime)
                : in_array(strtoupper((string)($runtime['meta_status'] ?? '')), ['APPROVED', 'ACTIVE'], true);

            if ( $only_approved && ! $approved ) {
                continue;
            }

            if ( empty($runtime['name']) || empty($runtime['language']) ) {
                continue;
            }

            $runtime['_approved_for_send'] = $approved ? '1' : '0';
            $result[$runtime['id']] = $runtime;
        }

        uasort($result, static function($a, $b) {
            $at = strtolower((string)($a['title'] ?? $a['name'] ?? ''));
            $bt = strtolower((string)($b['title'] ?? $b['name'] ?? ''));
            return strcmp($at, $bt);
        });

        return $result;
    }
}

/**
 * Busca una plantilla por ID local, storage key, nombre Meta o meta_template_id.
 */
if ( ! function_exists('eventosapp_whatsapp_masivo_get_template') ) {
    function eventosapp_whatsapp_masivo_get_template($template_id) {
        $template_id = sanitize_key((string) $template_id);
        if ( $template_id === '' || ! function_exists('eventosapp_whatsapp_templates_get_settings') ) {
            return null;
        }

        $settings = eventosapp_whatsapp_templates_get_settings();
        $templates = isset($settings['templates']) && is_array($settings['templates']) ? $settings['templates'] : [];

        if ( function_exists('eventosapp_whatsapp_find_template_by_identifier') ) {
            $template = eventosapp_whatsapp_find_template_by_identifier($templates, $template_id);
            return is_array($template) ? $template : null;
        }

        foreach ( $templates as $key => $template ) {
            if ( ! is_array($template) ) {
                continue;
            }
            $lookup_values = [
                $key,
                $template['id'] ?? '',
                $template['name'] ?? '',
                $template['meta_template_id'] ?? '',
            ];
            foreach ( $lookup_values as $lookup ) {
                if ( sanitize_key((string) $lookup) === $template_id ) {
                    $template['id'] = sanitize_key((string)($template['id'] ?? $key));
                    $template['_storage_key'] = sanitize_key((string) $key);
                    $template['name'] = sanitize_key((string)($template['name'] ?? ''));
                    $template['language'] = sanitize_text_field((string)($template['language'] ?? 'es'));
                    $template['meta_status'] = strtoupper(sanitize_text_field((string)($template['meta_status'] ?? 'LOCAL')));
                    return $template;
                }
            }
        }

        return null;
    }
}

/**
 * Etiqueta legible de plantilla para UI/logs.
 */
if ( ! function_exists('eventosapp_whatsapp_masivo_template_label') ) {
    function eventosapp_whatsapp_masivo_template_label($template) {
        if ( ! is_array($template) ) {
            return 'Plantilla no encontrada';
        }

        $title = trim((string)($template['title'] ?? ''));
        $name = trim((string)($template['name'] ?? ''));
        $language = trim((string)($template['language'] ?? ''));
        $status = strtoupper((string)($template['meta_status'] ?? ''));

        $label = $title !== '' ? $title : ($name !== '' ? $name : 'Plantilla sin nombre');
        if ( $name !== '' && $title !== $name ) {
            $label .= ' — ' . $name;
        }
        if ( $language !== '' ) {
            $label .= ' [' . $language . ']';
        }
        if ( $status !== '' ) {
            $label .= ' · ' . $status;
        }

        return $label;
    }
}

/**
 * Opciones de estado WhatsApp usadas en los filtros.
 */
if ( ! function_exists('eventosapp_whatsapp_masivo_status_options') ) {
    function eventosapp_whatsapp_masivo_status_options() {
        return [
            'no_enviado'       => 'No enviado',
            'aceptado_meta'    => 'Aceptado por Meta',
            'error'            => 'Error local/API',
            'skipped'          => 'Omitido',
        ];
    }
}

if ( ! function_exists('eventosapp_whatsapp_masivo_delivery_options') ) {
    function eventosapp_whatsapp_masivo_delivery_options() {
        return [
            'pendiente_webhook' => 'Pendiente de webhook',
            'sent'              => 'Enviado por WhatsApp',
            'delivered'         => 'Entregado al dispositivo',
            'read'              => 'Leído por el usuario',
            'failed'            => 'Fallido en Meta',
        ];
    }
}

/**
 * Registra el menú de administración independiente.
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'eventosapp_dashboard',
        'WhatsApp Ticket Masivo',
        'WhatsApp Ticket Masivo',
        'manage_options',
        'eventosapp_whatsapp_masivo',
        'eventosapp_whatsapp_masivo_render_page',
        31
    );
}, 21);

/**
 * Página principal.
 */
function eventosapp_whatsapp_masivo_render_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No tienes permisos para acceder a esta página.');
    }

    $step = isset($_GET['step']) ? absint($_GET['step']) : 1;
    $segment_id = isset($_GET['segment_id']) ? sanitize_key((string) wp_unslash($_GET['segment_id'])) : '';

    echo '<div class="wrap">';
    echo '<h1>WhatsApp Ticket Masivo</h1>';
    echo '<p class="description">Envía tickets por WhatsApp en lotes controlados, usando filtros de segmentación y una plantilla aprobada por Meta.</p>';

    echo '<h2 class="nav-tab-wrapper" style="margin:20px 0;">';
    $tabs = [
        1 => 'Filtros, Segmentación y Plantilla',
        2 => 'Vista Previa',
        3 => 'Envío Masivo',
    ];

    foreach ( $tabs as $num => $label ) {
        $active = ( $step === $num ) ? ' nav-tab-active' : '';
        $url = add_query_arg(['page' => 'eventosapp_whatsapp_masivo', 'step' => $num], admin_url('admin.php'));
        if ( $segment_id && $num > 1 ) {
            $url = add_query_arg('segment_id', $segment_id, $url);
        }
        echo '<a class="nav-tab' . esc_attr($active) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
    echo '</h2>';

    switch ( $step ) {
        case 1:
            eventosapp_whatsapp_masivo_render_step1();
            break;
        case 2:
            eventosapp_whatsapp_masivo_render_step2($segment_id);
            break;
        case 3:
            eventosapp_whatsapp_masivo_render_step3($segment_id);
            break;
        default:
            eventosapp_whatsapp_masivo_render_step1();
            break;
    }

    echo '</div>';
}

/**
 * STEP 1: Filtros y plantilla.
 */
function eventosapp_whatsapp_masivo_render_step1() {
    $eventos = get_posts([
        'post_type'   => 'eventosapp_event',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
    ]);

    global $wpdb;
    $localidades = $wpdb->get_col("\n        SELECT DISTINCT meta_value\n        FROM {$wpdb->postmeta}\n        WHERE meta_key = '_eventosapp_asistente_localidad'\n        AND meta_value != ''\n        ORDER BY meta_value ASC\n    ");

    $templates = eventosapp_whatsapp_masivo_get_templates(true);
    $status_options = eventosapp_whatsapp_masivo_status_options();
    $delivery_options = eventosapp_whatsapp_masivo_delivery_options();
    ?>
    <style>
        .evapp-wa-masivo-form{max-width:960px;}
        .evapp-wa-filter-section{background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px;}
        .evapp-wa-filter-section h3{margin-top:0;color:#128c7e;border-bottom:2px solid #128c7e;padding-bottom:10px;}
        .evapp-wa-filter-row{display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;}
        .evapp-wa-filter-field{display:flex;flex-direction:column;}
        .evapp-wa-filter-field label{font-weight:600;margin-bottom:5px;color:#1d2327;}
        .evapp-wa-filter-field select,.evapp-wa-filter-field input{padding:8px;border:1px solid #8c8f94;border-radius:4px;max-width:100%;}
        .evapp-wa-filter-field small{color:#646970;margin-top:4px;}
        .evapp-wa-extra-fields-container{margin-top:15px;padding:15px;background:#f0f0f1;border-radius:4px;}
        .evapp-wa-extra-field-row{display:grid;grid-template-columns:200px 1fr;gap:10px;margin-bottom:10px;align-items:center;}
        .evapp-wa-info-box{background:#e7f7f3;border-left:4px solid #128c7e;padding:12px;margin:15px 0;}
        .evapp-wa-warning-box{background:#fff8e5;border-left:4px solid #dba617;padding:12px;margin:15px 0;}
    </style>

    <?php if ( empty($templates) ) : ?>
        <div class="notice notice-error">
            <p><strong>No hay plantillas WhatsApp aprobadas disponibles.</strong></p>
            <p>Primero debes tener al menos una plantilla con estado <strong>APPROVED</strong> o <strong>ACTIVE</strong> en el módulo de plantillas de WhatsApp. El envío masivo no usa mensaje libre porque para campañas transaccionales WhatsApp debe enviar plantillas aprobadas.</p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evapp-wa-masivo-form" id="evappWhatsappMasivoForm">
        <input type="hidden" name="action" value="eventosapp_whatsapp_masivo_create_segment">
        <?php wp_nonce_field('eventosapp_whatsapp_masivo_segment', 'evapp_whatsapp_masivo_nonce'); ?>

        <div class="evapp-wa-info-box">
            <strong>💡 Instrucciones:</strong> selecciona la plantilla aprobada por Meta y configura los filtros. Los campos vacíos se ignoran. El envío se procesará en lotes para evitar sobrecarga del servidor.
        </div>

        <div class="evapp-wa-filter-section">
            <h3>🧩 Plantilla WhatsApp a usar</h3>
            <div class="evapp-wa-filter-row">
                <div class="evapp-wa-filter-field">
                    <label for="template_id">Plantilla aprobada</label>
                    <select name="template_id" id="template_id" required <?php disabled(empty($templates)); ?>>
                        <option value="">-- Selecciona la plantilla --</option>
                        <?php foreach ( $templates as $template_id => $template ) : ?>
                            <option value="<?php echo esc_attr($template_id); ?>">
                                <?php echo esc_html(eventosapp_whatsapp_masivo_template_label($template)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Esta plantilla se usará para todos los tickets del segmento, sin cambiar la plantilla seleccionada en el metabox del evento.</small>
                </div>
                <div class="evapp-wa-filter-field">
                    <label for="respect_rules">Reglas del evento</label>
                    <label style="font-weight:400;margin-top:8px;">
                        <input type="checkbox" name="respect_rules" id="respect_rules" value="1" checked>
                        Respetar reglas de envío WhatsApp configuradas en cada evento
                    </label>
                    <small>Los tickets bloqueados por reglas quedarán como omitidos, no como error.</small>
                </div>
            </div>
        </div>

        <div class="evapp-wa-filter-section">
            <h3>📲 Estado WhatsApp y Fechas de Envío</h3>
            <div class="evapp-wa-filter-row">
                <div class="evapp-wa-filter-field">
                    <label for="whatsapp_status">Estado de solicitud WhatsApp</label>
                    <select name="filters[whatsapp_status]" id="whatsapp_status">
                        <option value="">-- Todos --</option>
                        <?php foreach ( $status_options as $value => $label ) : ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Filtra por el último estado local/API del ticket.</small>
                </div>
                <div class="evapp-wa-filter-field">
                    <label for="delivery_status">Estado recibido por webhook</label>
                    <select name="filters[delivery_status]" id="delivery_status">
                        <option value="">-- Todos --</option>
                        <?php foreach ( $delivery_options as $value => $label ) : ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Filtra por entrega, lectura o fallo reportado por WhatsApp.</small>
                </div>
            </div>
            <div class="evapp-wa-filter-row">
                <div class="evapp-wa-filter-field">
                    <label for="last_sent_from">Último envío WhatsApp - Desde</label>
                    <input type="date" name="filters[last_sent_from]" id="last_sent_from">
                    <small>Fecha mínima del último envío por WhatsApp.</small>
                </div>
                <div class="evapp-wa-filter-field">
                    <label for="last_sent_to">Último envío WhatsApp - Hasta</label>
                    <input type="date" name="filters[last_sent_to]" id="last_sent_to">
                    <small>Fecha máxima del último envío por WhatsApp.</small>
                </div>
            </div>
        </div>

        <div class="evapp-wa-filter-section">
            <h3>🎫 Evento, Localidad y Modalidad</h3>
            <div class="evapp-wa-filter-row">
                <div class="evapp-wa-filter-field">
                    <label for="evento_id">Evento</label>
                    <select name="filters[evento_id]" id="evento_id">
                        <option value="">-- Todos los eventos --</option>
                        <?php foreach ( $eventos as $ev ) : ?>
                            <option value="<?php echo esc_attr($ev->ID); ?>"><?php echo esc_html($ev->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Selecciona un evento específico.</small>
                </div>
                <div class="evapp-wa-filter-field">
                    <label for="localidad">Localidad</label>
                    <select name="filters[localidad]" id="localidad">
                        <option value="">-- Todas las localidades --</option>
                        <?php foreach ( $localidades as $loc ) : ?>
                            <option value="<?php echo esc_attr($loc); ?>"><?php echo esc_html($loc); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Filtra por localidad del asistente.</small>
                </div>
            </div>
            <div class="evapp-wa-filter-row">
                <div class="evapp-wa-filter-field">
                    <label for="event_date">Fecha específica del evento</label>
                    <input type="date" name="filters[event_date]" id="event_date">
                    <small>Tickets válidos para esta fecha del evento.</small>
                </div>
                <div class="evapp-wa-filter-field">
                    <label for="modalidad">Modalidad del ticket</label>
                    <select name="filters[modalidad]" id="modalidad">
                        <option value="">-- Todas las modalidades --</option>
                        <option value="presencial">Presencial</option>
                        <option value="virtual">Virtual</option>
                    </select>
                    <small>Filtra tickets presenciales o virtuales.</small>
                </div>
            </div>
        </div>

        <div class="evapp-wa-filter-section">
            <h3>📅 Fecha de Creación del Ticket</h3>
            <div class="evapp-wa-filter-row">
                <div class="evapp-wa-filter-field">
                    <label for="created_from">Creado desde</label>
                    <input type="date" name="filters[created_from]" id="created_from">
                    <small>Fecha mínima de creación.</small>
                </div>
                <div class="evapp-wa-filter-field">
                    <label for="created_to">Creado hasta</label>
                    <input type="date" name="filters[created_to]" id="created_to">
                    <small>Fecha máxima de creación.</small>
                </div>
            </div>
        </div>

        <div class="evapp-wa-filter-section" id="extraFieldsSection" style="display:none;">
            <h3>🔧 Campos Adicionales del Evento</h3>
            <div class="evapp-wa-extra-fields-container" id="extraFieldsContainer">
                <p><em>Selecciona un evento primero para ver sus campos adicionales.</em></p>
            </div>
        </div>

        <p>
            <button type="submit" class="button button-primary button-large" <?php disabled(empty($templates)); ?>>
                Crear Segmento y Continuar →
            </button>
        </p>
    </form>

    <script>
    jQuery(document).ready(function($){
        $('#evento_id').on('change', function(){
            var eventoId = $(this).val();
            var $section = $('#extraFieldsSection');
            var $container = $('#extraFieldsContainer');

            if (!eventoId) {
                $section.hide();
                $container.html('<p><em>Selecciona un evento primero para ver sus campos adicionales.</em></p>');
                return;
            }

            $container.html('<p>Cargando campos...</p>');
            $section.show();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'eventosapp_get_event_extra_fields',
                    evento_id: eventoId,
                    _wpnonce: '<?php echo esc_js(wp_create_nonce('eventosapp_get_extra_fields')); ?>'
                },
                success: function(response) {
                    if (response.success && response.data.fields && response.data.fields.length > 0) {
                        var html = '';
                        response.data.fields.forEach(function(field){
                            html += '<div class="evapp-wa-extra-field-row">';
                            html += '<label>' + $('<div>').text(field.label).html() + '</label>';
                            if (field.options && field.options.length > 0) {
                                html += '<select name="filters[extra_fields][' + field.key + ']">';
                                html += '<option value="">-- Cualquiera --</option>';
                                field.options.forEach(function(opt){
                                    html += '<option value="' + $('<div>').text(opt).html() + '">' + $('<div>').text(opt).html() + '</option>';
                                });
                                html += '</select>';
                            } else {
                                html += '<input type="text" name="filters[extra_fields][' + field.key + ']" placeholder="Valor a buscar">';
                            }
                            html += '</div>';
                        });
                        $container.html(html);
                    } else {
                        $container.html('<p><em>Este evento no tiene campos adicionales configurados.</em></p>');
                    }
                },
                error: function() {
                    $container.html('<p style="color:red;">Error al cargar campos adicionales.</p>');
                }
            });
        });
    });
    </script>
    <?php
}

/**
 * STEP 2: Vista previa.
 */
function eventosapp_whatsapp_masivo_render_step2($segment_id) {
    if ( empty($segment_id) ) {
        echo '<div class="notice notice-error"><p>No hay segmento seleccionado. <a href="' . esc_url(admin_url('admin.php?page=eventosapp_whatsapp_masivo')) . '">Volver al paso 1</a></p></div>';
        return;
    }

    $segment = get_option(eventosapp_whatsapp_masivo_segment_option_key($segment_id));
    if ( ! $segment || ! is_array($segment) ) {
        echo '<div class="notice notice-error"><p>Segmento no encontrado. <a href="' . esc_url(admin_url('admin.php?page=eventosapp_whatsapp_masivo')) . '">Volver al paso 1</a></p></div>';
        return;
    }

    $filters = isset($segment['filters']) && is_array($segment['filters']) ? $segment['filters'] : [];
    $ticket_ids = eventosapp_whatsapp_masivo_get_filtered_tickets($filters);
    $total = count($ticket_ids);
    $segment['ticket_ids'] = $ticket_ids;
    $segment['total'] = $total;
    $segment['updated_at'] = current_time('mysql');
    update_option(eventosapp_whatsapp_masivo_segment_option_key($segment_id), $segment, false);

    $template_id = sanitize_key((string)($segment['template_id'] ?? ''));
    $template = eventosapp_whatsapp_masivo_get_template($template_id);
    $template_ok = $template && ( function_exists('eventosapp_whatsapp_is_template_approved') ? eventosapp_whatsapp_is_template_approved($template) : in_array(strtoupper((string)($template['meta_status'] ?? '')), ['APPROVED', 'ACTIVE'], true) );

    ?>
    <style>
        .evapp-wa-preview-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin:20px 0;}
        .evapp-wa-stat-card{background:linear-gradient(135deg,#128c7e 0%,#25d366 100%);color:white;padding:24px;border-radius:8px;text-align:center;}
        .evapp-wa-stat-card h3{margin:0 0 10px;color:white;font-size:14px;text-transform:uppercase;}
        .evapp-wa-stat-card .number{font-size:36px;font-weight:bold;}
        .evapp-wa-preview-table{background:#fff;border:1px solid #ddd;border-radius:8px;overflow:hidden;margin:20px 0;}
        .evapp-wa-preview-table h3{padding:15px;margin:0;background:#f6f7f7;border-bottom:1px solid #ddd;}
        .evapp-wa-preview-table table{width:100%;border-collapse:collapse;}
        .evapp-wa-preview-table th{background:#f0f0f1;padding:12px;text-align:left;border-bottom:2px solid #ddd;}
        .evapp-wa-preview-table td{padding:10px;border-bottom:1px solid #eee;vertical-align:top;}
        .evapp-wa-preview-table tr:hover{background:#f9f9f9;}
        .evapp-wa-filters-applied{background:#fff3cd;border:1px solid #ffc107;padding:15px;border-radius:8px;margin:20px 0;}
        .evapp-wa-filters-applied h4{margin-top:0;color:#856404;}
        .evapp-wa-filter-tag{display:inline-block;background:white;border:1px solid #ffc107;padding:5px 10px;margin:5px;border-radius:4px;font-size:12px;}
        .evapp-wa-badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;line-height:1.4;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;}
        .evapp-wa-badge-error{background:#fee2e2;color:#991b1b;border-color:#fecaca;}
        .evapp-wa-badge-ok{background:#dcfce7;color:#166534;border-color:#bbf7d0;}
    </style>

    <?php if ( ! $template_ok ) : ?>
        <div class="notice notice-error">
            <p><strong>La plantilla seleccionada ya no está aprobada o no existe.</strong> Vuelve al paso 1 y selecciona una plantilla disponible.</p>
        </div>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_masivo')); ?>" class="button">← Volver a filtros</a></p>
        <?php return; ?>
    <?php endif; ?>

    <div class="evapp-wa-filters-applied">
        <h4>🧩 Plantilla seleccionada</h4>
        <span class="evapp-wa-filter-tag"><strong>Plantilla:</strong> <?php echo esc_html(eventosapp_whatsapp_masivo_template_label($template)); ?></span>
        <span class="evapp-wa-filter-tag"><strong>Reglas del evento:</strong> <?php echo ! empty($segment['respect_rules']) ? 'Se respetan' : 'Se ignoran'; ?></span>
    </div>

    <div class="evapp-wa-filters-applied">
        <h4>📊 Filtros Aplicados</h4>
        <?php
        $filter_labels = [
            'whatsapp_status' => 'Estado WhatsApp',
            'delivery_status' => 'Estado Webhook',
            'last_sent_from'  => 'Último WhatsApp Desde',
            'last_sent_to'    => 'Último WhatsApp Hasta',
            'evento_id'       => 'Evento',
            'localidad'       => 'Localidad',
            'event_date'      => 'Fecha del Evento',
            'modalidad'       => 'Modalidad del Ticket',
            'created_from'    => 'Creado Desde',
            'created_to'      => 'Creado Hasta',
        ];
        $has_filters = false;
        foreach ( $filters as $key => $value ) {
            if ( empty($value) || $key === 'extra_fields' ) {
                continue;
            }
            $has_filters = true;
            $label = $filter_labels[$key] ?? $key;
            $display_value = $value;
            if ( $key === 'evento_id' ) {
                $display_value = get_the_title((int) $value);
            } elseif ( $key === 'modalidad' ) {
                $modalidad_opts = function_exists('eventosapp_ticket_modalidad_options') ? eventosapp_ticket_modalidad_options() : ['presencial' => 'Presencial', 'virtual' => 'Virtual'];
                $display_value = $modalidad_opts[$value] ?? $value;
            } elseif ( $key === 'whatsapp_status' ) {
                $opts = eventosapp_whatsapp_masivo_status_options();
                $display_value = $opts[$value] ?? $value;
            } elseif ( $key === 'delivery_status' ) {
                $opts = eventosapp_whatsapp_masivo_delivery_options();
                $display_value = $opts[$value] ?? $value;
            }
            echo '<span class="evapp-wa-filter-tag"><strong>' . esc_html($label) . ':</strong> ' . esc_html($display_value) . '</span>';
        }
        if ( ! empty($filters['extra_fields']) && is_array($filters['extra_fields']) ) {
            foreach ( $filters['extra_fields'] as $field_key => $field_value ) {
                if ( $field_value === '' || $field_value === null ) {
                    continue;
                }
                $has_filters = true;
                echo '<span class="evapp-wa-filter-tag"><strong>Campo Extra - ' . esc_html($field_key) . ':</strong> ' . esc_html($field_value) . '</span>';
            }
        }
        if ( ! $has_filters ) {
            echo '<p><em>Sin filtros aplicados: se mostrarán todos los tickets.</em></p>';
        }
        ?>
    </div>

    <div class="notice notice-info" style="margin:15px 0;">
        <p><strong>Compatibilidad:</strong> antes de cada envío se preparan variantes, landing, QR específico de WhatsApp, imagen del mensaje, Wallet, PDF/ICS cuando aplique y se registra el resultado en el historial del ticket.</p>
    </div>

    <div class="evapp-wa-preview-stats">
        <div class="evapp-wa-stat-card">
            <h3>Total de Tickets</h3>
            <div class="number"><?php echo esc_html(number_format_i18n($total)); ?></div>
        </div>
        <div class="evapp-wa-stat-card" style="background:linear-gradient(135deg,#34d399 0%,#059669 100%);">
            <h3>WhatsApp a Procesar</h3>
            <div class="number"><?php echo esc_html(number_format_i18n($total)); ?></div>
        </div>
        <div class="evapp-wa-stat-card" style="background:linear-gradient(135deg,#60a5fa 0%,#2563eb 100%);">
            <h3>Lote sugerido</h3>
            <div class="number">5</div>
        </div>
    </div>

    <?php if ( $total > 0 ) : ?>
        <div class="evapp-wa-preview-table">
            <h3>Vista Previa de Tickets (primeros 20)</h3>
            <table>
                <thead>
                    <tr>
                        <th>ID Ticket</th>
                        <th>Asistente</th>
                        <th>Celular WhatsApp</th>
                        <th>Evento</th>
                        <th>Localidad</th>
                        <th>Modalidad</th>
                        <th>Estado WhatsApp</th>
                        <th>Plantilla a usar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $settings = function_exists('eventosapp_whatsapp_get_settings') ? eventosapp_whatsapp_get_settings() : ['default_country_code' => '57'];
                    foreach ( array_slice($ticket_ids, 0, 20) as $tid ) :
                        $nombre = trim((string)get_post_meta($tid, '_eventosapp_asistente_nombre', true) . ' ' . (string)get_post_meta($tid, '_eventosapp_asistente_apellido', true));
                        $phone_raw = get_post_meta($tid, '_eventosapp_asistente_tel', true);
                        $phone = function_exists('eventosapp_whatsapp_normalize_phone') ? eventosapp_whatsapp_normalize_phone($phone_raw, $settings['default_country_code'] ?? '57') : preg_replace('/\D+/', '', (string)$phone_raw);
                        $evento_id = absint(get_post_meta($tid, '_eventosapp_ticket_evento_id', true));
                        $localidad = get_post_meta($tid, '_eventosapp_asistente_localidad', true);
                        $modalidad_label = function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label($tid) : ucfirst((string)get_post_meta($tid, '_eventosapp_ticket_modalidad', true));
                        $ticket_code = get_post_meta($tid, 'eventosapp_ticketID', true);
                        $last_status = get_post_meta($tid, '_eventosapp_whatsapp_last_status', true);
                        $delivery_status = get_post_meta($tid, '_eventosapp_whatsapp_delivery_status', true);
                        $status_label = function_exists('eventosapp_whatsapp_status_label') ? eventosapp_whatsapp_status_label($last_status) : ($last_status ?: 'Sin estado');
                        $delivery_label = $delivery_status && function_exists('eventosapp_whatsapp_status_label') ? eventosapp_whatsapp_status_label($delivery_status) : '';
                        $phone_class = $phone ? 'evapp-wa-badge evapp-wa-badge-ok' : 'evapp-wa-badge evapp-wa-badge-error';
                    ?>
                        <tr>
                            <td><code><?php echo esc_html($ticket_code ?: $tid); ?></code></td>
                            <td><?php echo esc_html($nombre); ?></td>
                            <td><span class="<?php echo esc_attr($phone_class); ?>"><?php echo esc_html($phone ?: 'Sin celular válido'); ?></span></td>
                            <td><?php echo esc_html($evento_id ? get_the_title($evento_id) : 'Sin evento'); ?></td>
                            <td><?php echo esc_html($localidad); ?></td>
                            <td><?php echo esc_html($modalidad_label ?: 'Presencial'); ?></td>
                            <td><?php echo esc_html($status_label . ($delivery_label ? ' · ' . $delivery_label : '')); ?></td>
                            <td><span class="evapp-wa-badge"><?php echo esc_html((string)($template['name'] ?? '')); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ( $total > 20 ) : ?>
                <p style="margin:15px;color:#646970;"><em>Se muestran solo los primeros 20 tickets. Total a procesar: <strong><?php echo esc_html(number_format_i18n($total)); ?></strong></em></p>
            <?php endif; ?>
        </div>

        <div style="margin:30px 0;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_masivo')); ?>" class="button">← Volver a Filtros</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_masivo&step=3&segment_id=' . urlencode($segment_id))); ?>" class="button button-primary button-large" style="margin-left:10px;">Continuar al Envío →</a>
        </div>
    <?php else : ?>
        <div class="notice notice-warning">
            <p><strong>No se encontraron tickets con los filtros aplicados.</strong></p>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_masivo')); ?>">← Volver a ajustar filtros</a></p>
        </div>
    <?php endif;
}

/**
 * STEP 3: Envío por lotes AJAX.
 */
function eventosapp_whatsapp_masivo_render_step3($segment_id) {
    if ( empty($segment_id) ) {
        echo '<div class="notice notice-error"><p>No hay segmento seleccionado.</p></div>';
        return;
    }

    $segment = get_option(eventosapp_whatsapp_masivo_segment_option_key($segment_id));
    if ( ! $segment || ! is_array($segment) ) {
        echo '<div class="notice notice-error"><p>Segmento no encontrado.</p></div>';
        return;
    }

    $total = isset($segment['total']) ? absint($segment['total']) : 0;
    $ticket_ids = isset($segment['ticket_ids']) && is_array($segment['ticket_ids']) ? array_map('absint', $segment['ticket_ids']) : [];
    $template = eventosapp_whatsapp_masivo_get_template((string)($segment['template_id'] ?? ''));

    if ( $total === 0 || empty($ticket_ids) ) {
        echo '<div class="notice notice-warning"><p>No hay tickets para enviar.</p></div>';
        return;
    }

    if ( ! $template || ( function_exists('eventosapp_whatsapp_is_template_approved') && ! eventosapp_whatsapp_is_template_approved($template) ) ) {
        echo '<div class="notice notice-error"><p>La plantilla seleccionada no está disponible o no está aprobada.</p></div>';
        return;
    }
    ?>
    <style>
        .evapp-wa-sending-container{max-width:860px;margin:20px auto;}
        .evapp-wa-sending-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:30px;box-shadow:0 2px 8px rgba(0,0,0,.1);}
        .evapp-wa-progress-bar-container{width:100%;height:40px;background:#f0f0f1;border-radius:20px;overflow:hidden;margin:20px 0;position:relative;}
        .evapp-wa-progress-bar{height:100%;background:linear-gradient(90deg,#128c7e 0%,#25d366 100%);transition:width .3s ease;display:flex;align-items:center;justify-content:center;color:white;font-weight:bold;}
        .evapp-wa-stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin:20px 0;}
        .evapp-wa-stat-box{background:#f9f9f9;padding:15px;border-radius:8px;text-align:center;border:1px solid #e0e0e0;}
        .evapp-wa-stat-box .label{font-size:12px;color:#646970;text-transform:uppercase;}
        .evapp-wa-stat-box .value{font-size:28px;font-weight:bold;color:#1d2327;margin-top:5px;}
        .evapp-wa-status-message{padding:15px;margin:15px 0;border-radius:8px;}
        .evapp-wa-status-processing{background:#e7f7f3;border-left:4px solid #128c7e;}
        .evapp-wa-status-complete{background:#d1fae5;border-left:4px solid #10b981;}
        .evapp-wa-log-container{max-height:340px;overflow-y:auto;background:#1e1e1e;color:#d4d4d4;padding:15px;border-radius:8px;font-family:monospace;font-size:12px;margin:20px 0;}
        .evapp-wa-log-entry{margin:5px 0;}
        .evapp-wa-log-success{color:#4ade80;}
        .evapp-wa-log-error{color:#f87171;}
        .evapp-wa-log-info{color:#60a5fa;}
        .evapp-wa-log-warning{color:#fbbf24;}
    </style>

    <div class="evapp-wa-sending-container">
        <div class="evapp-wa-sending-card">
            <h2 style="margin-top:0;">📲 Envío Masivo de WhatsApp</h2>
            <p><strong>Plantilla:</strong> <?php echo esc_html(eventosapp_whatsapp_masivo_template_label($template)); ?></p>

            <div class="evapp-wa-status-message evapp-wa-status-processing" id="statusMessage">
                <strong>🔄 Preparando envío...</strong>
                <p id="statusText">Inicializando sistema de envío masivo por WhatsApp...</p>
            </div>

            <div class="evapp-wa-progress-bar-container">
                <div class="evapp-wa-progress-bar" id="progressBar" style="width:0%;"><span id="progressText">0%</span></div>
            </div>

            <div class="evapp-wa-stats-grid">
                <div class="evapp-wa-stat-box"><div class="label">Total</div><div class="value" id="statTotal"><?php echo esc_html($total); ?></div></div>
                <div class="evapp-wa-stat-box"><div class="label">Aceptados</div><div class="value" id="statSent" style="color:#10b981;">0</div></div>
                <div class="evapp-wa-stat-box"><div class="label">Omitidos</div><div class="value" id="statSkipped" style="color:#d97706;">0</div></div>
                <div class="evapp-wa-stat-box"><div class="label">Errores</div><div class="value" id="statErrors" style="color:#ef4444;">0</div></div>
            </div>

            <details style="margin-top:20px;" open>
                <summary style="cursor:pointer;font-weight:600;color:#128c7e;">Ver log detallado</summary>
                <div class="evapp-wa-log-container" id="logContainer">
                    <div class="evapp-wa-log-entry evapp-wa-log-info">[INFO] Sistema iniciado. Esperando inicio del proceso...</div>
                </div>
            </details>

            <div style="margin-top:20px;text-align:center;display:none;" id="actionButtons">
                <a href="<?php echo esc_url(admin_url('admin.php?page=eventosapp_whatsapp_masivo')); ?>" class="button button-primary button-large">← Volver al Inicio</a>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($){
        const segmentId = <?php echo wp_json_encode($segment_id); ?>;
        const total = <?php echo (int) $total; ?>;
        let processed = 0;
        let sent = 0;
        let skipped = 0;
        let errors = 0;
        let offset = 0;
        const batchSize = 5;

        function addLog(message, type = 'info') {
            const $log = $('#logContainer');
            const timestamp = new Date().toLocaleTimeString();
            const className = 'evapp-wa-log-' + type;
            const safeMessage = $('<div>').text(message || '').html();
            $log.append('<div class="evapp-wa-log-entry ' + className + '">[' + timestamp + '] ' + safeMessage + '</div>');
            $log.scrollTop($log[0].scrollHeight);
        }

        function updateUI() {
            const percent = total > 0 ? Math.round((processed / total) * 100) : 100;
            $('#progressBar').css('width', percent + '%');
            $('#progressText').text(percent + '%');
            $('#statSent').text(sent);
            $('#statSkipped').text(skipped);
            $('#statErrors').text(errors);

            if (processed >= total) {
                $('#statusMessage')
                    .removeClass('evapp-wa-status-processing')
                    .addClass('evapp-wa-status-complete')
                    .html('<strong>✅ Envío completado</strong><p>Se han procesado todos los tickets del segmento.</p>');
                $('#actionButtons').show();
                addLog('Proceso completado: ' + sent + ' aceptados por Meta, ' + skipped + ' omitidos, ' + errors + ' errores', 'success');
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
                    action: 'eventosapp_whatsapp_masivo_process_batch',
                    segment_id: segmentId,
                    offset: offset,
                    batch_size: batchSize,
                    _wpnonce: '<?php echo esc_js(wp_create_nonce('eventosapp_whatsapp_masivo_process')); ?>'
                },
                timeout: 90000,
                success: function(response) {
                    if (response.success) {
                        const data = response.data || {};
                        processed += parseInt(data.processed || 0, 10);
                        sent += parseInt(data.sent || 0, 10);
                        skipped += parseInt(data.skipped || 0, 10);
                        errors += parseInt(data.errors || 0, 10);
                        offset = parseInt(data.next_offset || (offset + batchSize), 10);

                        if (data.logs && data.logs.length > 0) {
                            data.logs.forEach(function(log){ addLog(log.message, log.type); });
                        }

                        updateUI();
                        if (offset < total) {
                            setTimeout(processBatch, 1200);
                        }
                    } else {
                        addLog('Error en el lote: ' + (response.data || 'Error desconocido'), 'error');
                        errors += Math.min(batchSize, total - offset);
                        processed += Math.min(batchSize, total - offset);
                        offset += batchSize;
                        updateUI();
                        if (offset < total) {
                            setTimeout(processBatch, 2500);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    addLog('Error de conexión: ' + error, 'error');
                    errors += Math.min(batchSize, total - offset);
                    processed += Math.min(batchSize, total - offset);
                    offset += batchSize;
                    updateUI();
                    if (offset < total) {
                        setTimeout(processBatch, 2500);
                    }
                }
            });
        }

        addLog('Iniciando envío masivo de ' + total + ' tickets por WhatsApp', 'success');
        setTimeout(processBatch, 500);
    });
    </script>
    <?php
}

/**
 * Handler: crea el segmento.
 */
add_action('admin_post_eventosapp_whatsapp_masivo_create_segment', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No autorizado');
    }

    check_admin_referer('eventosapp_whatsapp_masivo_segment', 'evapp_whatsapp_masivo_nonce');

    $template_id = isset($_POST['template_id']) ? sanitize_key((string) wp_unslash($_POST['template_id'])) : '';
    $template = eventosapp_whatsapp_masivo_get_template($template_id);
    $template_ok = $template && ( function_exists('eventosapp_whatsapp_is_template_approved') ? eventosapp_whatsapp_is_template_approved($template) : in_array(strtoupper((string)($template['meta_status'] ?? '')), ['APPROVED', 'ACTIVE'], true) );

    if ( ! $template_ok ) {
        wp_die('La plantilla seleccionada no existe o no está aprobada por Meta.');
    }

    $filters = isset($_POST['filters']) && is_array($_POST['filters']) ? wp_unslash($_POST['filters']) : [];
    $filters = eventosapp_whatsapp_masivo_sanitize_filters($filters);

    $ticket_ids = eventosapp_whatsapp_masivo_get_filtered_tickets($filters);
    $segment_id = 'wa_' . time() . '_' . wp_generate_password(8, false, false);

    $segment = [
        'id'            => $segment_id,
        'created_at'    => current_time('mysql'),
        'updated_at'    => current_time('mysql'),
        'created_by'    => get_current_user_id(),
        'template_id'   => $template_id,
        'template_name' => sanitize_key((string)($template['name'] ?? '')),
        'template_lang' => sanitize_text_field((string)($template['language'] ?? '')),
        'respect_rules' => isset($_POST['respect_rules']) ? 1 : 0,
        'filters'       => $filters,
        'ticket_ids'    => $ticket_ids,
        'total'         => count($ticket_ids),
    ];

    update_option(eventosapp_whatsapp_masivo_segment_option_key($segment_id), $segment, false);

    eventosapp_whatsapp_masivo_activity_log('whatsapp_masivo_segmento_creado', [
        'segment_id'    => $segment_id,
        'template_id'   => $template_id,
        'template_name' => $segment['template_name'],
        'template_lang' => $segment['template_lang'],
        'respect_rules' => $segment['respect_rules'] ? 'yes' : 'no',
        'total'         => count($ticket_ids),
        'filters'       => $filters,
    ]);

    wp_safe_redirect(admin_url('admin.php?page=eventosapp_whatsapp_masivo&step=2&segment_id=' . urlencode($segment_id)));
    exit;
});

/**
 * Handler AJAX: procesa lotes.
 */
add_action('wp_ajax_eventosapp_whatsapp_masivo_process_batch', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error('No autorizado');
    }

    check_ajax_referer('eventosapp_whatsapp_masivo_process');

    $segment_id = isset($_POST['segment_id']) ? sanitize_key((string) wp_unslash($_POST['segment_id'])) : '';
    $offset = isset($_POST['offset']) ? max(0, absint($_POST['offset'])) : 0;
    $batch_size = isset($_POST['batch_size']) ? min(10, max(1, absint($_POST['batch_size']))) : 5;

    if ( $segment_id === '' ) {
        wp_send_json_error('Segmento inválido');
    }

    $segment = get_option(eventosapp_whatsapp_masivo_segment_option_key($segment_id));
    if ( ! $segment || ! is_array($segment) ) {
        wp_send_json_error('Segmento no encontrado');
    }

    $ticket_ids = isset($segment['ticket_ids']) && is_array($segment['ticket_ids']) ? array_map('absint', $segment['ticket_ids']) : [];
    $template_id = sanitize_key((string)($segment['template_id'] ?? ''));
    $template = eventosapp_whatsapp_masivo_get_template($template_id);
    $template_ok = $template && ( function_exists('eventosapp_whatsapp_is_template_approved') ? eventosapp_whatsapp_is_template_approved($template) : in_array(strtoupper((string)($template['meta_status'] ?? '')), ['APPROVED', 'ACTIVE'], true) );

    if ( ! $template_ok ) {
        wp_send_json_error('La plantilla seleccionada no está aprobada o ya no existe.');
    }

    $batch = array_slice($ticket_ids, $offset, $batch_size);
    $sent = 0;
    $errors = 0;
    $skipped = 0;
    $logs = [];
    $source_key = 'whatsapp_bulk_' . $segment_id . '_' . md5($template_id . '|' . (string)($template['name'] ?? '') . '|' . (string)($template['language'] ?? ''));

    foreach ( $batch as $ticket_id ) {
        $ticket_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
        if ( ! $ticket_code ) {
            $ticket_code = (string) $ticket_id;
        }

        $result = eventosapp_whatsapp_masivo_send_ticket_with_template($ticket_id, $template_id, [
            'context'     => 'whatsapp_bulk_send',
            'source_key'  => $source_key,
            'skip_rules'  => empty($segment['respect_rules']),
            'force'       => false,
            'segment_id'  => $segment_id,
        ]);

        if ( ! empty($result['skipped_rules']) ) {
            $skipped++;
            $logs[] = [
                'message' => 'Ticket ' . $ticket_code . ': omitido por reglas — ' . sanitize_text_field((string)($result['message'] ?? '')),
                'type'    => 'warning',
            ];
        } elseif ( ! empty($result['skipped_duplicate']) ) {
            $skipped++;
            $logs[] = [
                'message' => 'Ticket ' . $ticket_code . ': omitido por duplicado del mismo segmento.',
                'type'    => 'warning',
            ];
        } elseif ( ! empty($result['ok']) ) {
            $sent++;
            $template_name = sanitize_text_field((string)($result['template_name'] ?? ($template['name'] ?? '')));
            $logs[] = [
                'message' => 'Ticket ' . $ticket_code . ': solicitud aceptada por Meta usando plantilla ' . $template_name . '.',
                'type'    => 'success',
            ];
        } else {
            $errors++;
            $logs[] = [
                'message' => 'Ticket ' . $ticket_code . ': error — ' . sanitize_text_field((string)($result['message'] ?? 'Error desconocido.')),
                'type'    => 'error',
            ];
        }

        usleep(150000);
    }

    $processed_total = (int) get_option('evapp_whatsapp_masivo_processed_' . $segment_id, 0) + count($batch);
    update_option('evapp_whatsapp_masivo_processed_' . $segment_id, $processed_total, false);

    wp_send_json_success([
        'processed'   => count($batch),
        'sent'        => $sent,
        'skipped'     => $skipped,
        'errors'      => $errors,
        'next_offset' => $offset + $batch_size,
        'logs'        => $logs,
    ]);
});

/**
 * Sanitiza filtros recibidos desde POST.
 */
function eventosapp_whatsapp_masivo_sanitize_filters($filters) {
    $filters = is_array($filters) ? $filters : [];
    $clean = [];

    foreach ( $filters as $key => $value ) {
        $key = sanitize_key((string) $key);
        if ( $key === '' ) {
            continue;
        }

        if ( $key === 'extra_fields' && is_array($value) ) {
            $clean['extra_fields'] = [];
            foreach ( $value as $field_key => $field_value ) {
                $field_key = sanitize_key((string) $field_key);
                if ( $field_key === '' ) {
                    continue;
                }
                $field_value = sanitize_text_field((string) $field_value);
                if ( $field_value !== '' ) {
                    $clean['extra_fields'][$field_key] = $field_value;
                }
            }
            if ( empty($clean['extra_fields']) ) {
                unset($clean['extra_fields']);
            }
            continue;
        }

        $value = is_scalar($value) ? sanitize_text_field((string) $value) : '';
        if ( $value !== '' ) {
            $clean[$key] = $value;
        }
    }

    return $clean;
}

/**
 * Obtiene tickets filtrados según criterios.
 */
function eventosapp_whatsapp_masivo_get_filtered_tickets($filters) {
    $filters = is_array($filters) ? $filters : [];

    $args = [
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'posts_per_page' => -1,
        'no_found_rows'  => true,
    ];

    $meta_query = ['relation' => 'AND'];
    $date_query = [];

    if ( ! empty($filters['evento_id']) ) {
        $meta_query[] = [
            'key'     => '_eventosapp_ticket_evento_id',
            'value'   => absint($filters['evento_id']),
            'compare' => '=',
        ];
    }

    if ( ! empty($filters['localidad']) ) {
        $meta_query[] = [
            'key'     => '_eventosapp_asistente_localidad',
            'value'   => sanitize_text_field((string) $filters['localidad']),
            'compare' => '=',
        ];
    }

    $modalidad_filter = '';
    if ( ! empty($filters['modalidad']) ) {
        $modalidad_filter = function_exists('eventosapp_normalize_ticket_modalidad')
            ? eventosapp_normalize_ticket_modalidad($filters['modalidad'])
            : sanitize_key((string) $filters['modalidad']);

        if ( ! in_array($modalidad_filter, ['presencial', 'virtual'], true) ) {
            $modalidad_filter = '';
        }
    }

    if ( ! empty($filters['whatsapp_status']) ) {
        $whatsapp_status = sanitize_key((string) $filters['whatsapp_status']);
        if ( $whatsapp_status === 'no_enviado' ) {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key'     => '_eventosapp_whatsapp_last_status',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_eventosapp_whatsapp_last_status',
                    'value'   => '',
                    'compare' => '=',
                ],
            ];
        } else {
            $meta_query[] = [
                'key'     => '_eventosapp_whatsapp_last_status',
                'value'   => $whatsapp_status,
                'compare' => '=',
            ];
        }
    }

    if ( ! empty($filters['delivery_status']) ) {
        $delivery_status = sanitize_key((string) $filters['delivery_status']);
        $meta_query[] = [
            'key'     => '_eventosapp_whatsapp_delivery_status',
            'value'   => $delivery_status,
            'compare' => '=',
        ];
    }

    if ( ! empty($filters['last_sent_from']) || ! empty($filters['last_sent_to']) ) {
        $date_meta = [
            'key'  => '_eventosapp_whatsapp_last_sent_at',
            'type' => 'DATETIME',
        ];

        if ( ! empty($filters['last_sent_from']) && ! empty($filters['last_sent_to']) ) {
            $date_meta['value'] = [
                sanitize_text_field((string) $filters['last_sent_from']) . ' 00:00:00',
                sanitize_text_field((string) $filters['last_sent_to']) . ' 23:59:59',
            ];
            $date_meta['compare'] = 'BETWEEN';
        } elseif ( ! empty($filters['last_sent_from']) ) {
            $date_meta['value'] = sanitize_text_field((string) $filters['last_sent_from']) . ' 00:00:00';
            $date_meta['compare'] = '>=';
        } else {
            $date_meta['value'] = sanitize_text_field((string) $filters['last_sent_to']) . ' 23:59:59';
            $date_meta['compare'] = '<=';
        }

        $meta_query[] = $date_meta;
    }

    if ( ! empty($filters['created_from']) || ! empty($filters['created_to']) ) {
        if ( ! empty($filters['created_from']) && ! empty($filters['created_to']) ) {
            $date_query = [
                'after'     => sanitize_text_field((string) $filters['created_from']) . ' 00:00:00',
                'before'    => sanitize_text_field((string) $filters['created_to']) . ' 23:59:59',
                'inclusive' => true,
            ];
        } elseif ( ! empty($filters['created_from']) ) {
            $date_query = [
                'after'     => sanitize_text_field((string) $filters['created_from']) . ' 00:00:00',
                'inclusive' => true,
            ];
        } else {
            $date_query = [
                'before'    => sanitize_text_field((string) $filters['created_to']) . ' 23:59:59',
                'inclusive' => true,
            ];
        }
    }

    if ( ! empty($filters['extra_fields']) && is_array($filters['extra_fields']) ) {
        foreach ( $filters['extra_fields'] as $field_key => $field_value ) {
            if ( $field_value === '' || $field_value === null ) {
                continue;
            }
            $meta_query[] = [
                'key'     => '_eventosapp_extra_' . sanitize_key((string) $field_key),
                'value'   => sanitize_text_field((string) $field_value),
                'compare' => 'LIKE',
            ];
        }
    }

    if ( count($meta_query) > 1 ) {
        $args['meta_query'] = $meta_query;
    }

    if ( ! empty($date_query) ) {
        $args['date_query'] = $date_query;
    }

    $query = new WP_Query($args);
    $ticket_ids = array_map('absint', $query->posts);

    if ( ! empty($filters['event_date']) ) {
        $event_date = sanitize_text_field((string) $filters['event_date']);
        $filtered_ids = [];
        foreach ( $ticket_ids as $tid ) {
            $evento_id = absint(get_post_meta($tid, '_eventosapp_ticket_evento_id', true));
            if ( $evento_id && function_exists('eventosapp_get_event_days') ) {
                $event_days = eventosapp_get_event_days($evento_id);
                if ( is_array($event_days) && in_array($event_date, $event_days, true) ) {
                    $filtered_ids[] = $tid;
                }
            }
        }
        $ticket_ids = $filtered_ids;
    }

    if ( ! empty($modalidad_filter) && function_exists('eventosapp_get_ticket_modalidad') ) {
        $ticket_ids = array_values(array_filter($ticket_ids, function($tid) use ($modalidad_filter) {
            return eventosapp_get_ticket_modalidad($tid) === $modalidad_filter;
        }));
    }

    return $ticket_ids;
}

/**
 * Envía un ticket por WhatsApp forzando una plantilla elegida desde el envío masivo.
 */
function eventosapp_whatsapp_masivo_send_ticket_with_template($ticket_id, $template_id, $args = []) {
    $ticket_id = absint($ticket_id);
    $template_id = sanitize_key((string) $template_id);
    $args = wp_parse_args(is_array($args) ? $args : [], [
        'context'    => 'whatsapp_bulk_send',
        'force'      => false,
        'skip_rules' => false,
        'source_key' => '',
        'segment_id' => '',
    ]);

    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        return ['ok' => false, 'message' => 'Ticket inválido.'];
    }

    foreach ( [
        'eventosapp_whatsapp_get_settings',
        'eventosapp_whatsapp_normalize_phone',
        'eventosapp_whatsapp_prepare_ticket_assets',
        'eventosapp_whatsapp_build_ticket_template_components',
        'eventosapp_whatsapp_build_template_payload',
        'eventosapp_whatsapp_api_send_message',
        'eventosapp_whatsapp_add_ticket_log',
    ] as $required_function ) {
        if ( ! function_exists($required_function) ) {
            return ['ok' => false, 'message' => 'Falta la función requerida: ' . $required_function . '. Verifica que eventosapp-whatsapp-ticket.php esté cargado antes del módulo masivo.'];
        }
    }

    $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
        eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', 'El ticket no tiene evento asociado.', $args);
        eventosapp_whatsapp_masivo_activity_log('whatsapp_masivo_cancelado_sin_evento', [
            'ticket_id' => $ticket_id,
            'context'   => $args['context'],
        ]);
        return ['ok' => false, 'message' => 'El ticket no tiene evento asociado.'];
    }

    if ( get_post_meta($event_id, '_eventosapp_ticket_whatsapp_enabled', true) !== '1' ) {
        eventosapp_whatsapp_masivo_activity_log('whatsapp_masivo_cancelado_evento_inactivo', [
            'ticket_id' => $ticket_id,
            'event_id'  => $event_id,
            'context'   => $args['context'],
        ]);
        return ['ok' => false, 'message' => 'WhatsApp no está activo para este evento.'];
    }

    $template = eventosapp_whatsapp_masivo_get_template($template_id);
    $template_ok = $template && ( function_exists('eventosapp_whatsapp_is_template_approved') ? eventosapp_whatsapp_is_template_approved($template) : in_array(strtoupper((string)($template['meta_status'] ?? '')), ['APPROVED', 'ACTIVE'], true) );
    if ( ! $template_ok ) {
        eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', 'La plantilla seleccionada para envío masivo no está aprobada o no existe.', $args);
        return ['ok' => false, 'message' => 'La plantilla seleccionada no está aprobada o no existe.'];
    }

    $source_key = sanitize_text_field((string)($args['source_key'] ?? ''));
    if ( empty($args['force']) && $source_key !== '' ) {
        $last_source = get_post_meta($ticket_id, '_eventosapp_whatsapp_last_source_key', true);
        if ( $last_source === $source_key ) {
            eventosapp_whatsapp_masivo_activity_log('whatsapp_masivo_omitido_duplicado_source_key', [
                'ticket_id'  => $ticket_id,
                'event_id'   => $event_id,
                'source_key' => $source_key,
                'template'   => $template['name'] ?? '',
            ]);
            return ['ok' => true, 'message' => 'WhatsApp ya había sido enviado en este segmento.', 'skipped_duplicate' => true];
        }
    }

    $lock_key = 'eventosapp_whatsapp_masivo_lock_' . $ticket_id . '_' . md5($source_key . '|' . $template_id);
    if ( get_transient($lock_key) && empty($args['force']) ) {
        eventosapp_whatsapp_masivo_activity_log('whatsapp_masivo_omitido_lock_temporal', [
            'ticket_id' => $ticket_id,
            'event_id'  => $event_id,
            'context'   => $args['context'],
        ]);
        return ['ok' => true, 'message' => 'Envío WhatsApp omitido por bloqueo temporal anti-duplicado.', 'skipped_duplicate' => true];
    }
    set_transient($lock_key, 1, 60);

    if ( empty($args['skip_rules']) && function_exists('eventosapp_whatsapp_ticket_passes_rules') ) {
        $rules_result = eventosapp_whatsapp_ticket_passes_rules($ticket_id, $event_id);
        if ( empty($rules_result['allowed']) ) {
            delete_transient($lock_key);
            eventosapp_whatsapp_add_ticket_log($ticket_id, 'skipped', $rules_result['reason'] ?? 'Omitido por reglas.', $args);
            eventosapp_whatsapp_masivo_activity_log('whatsapp_masivo_omitido_reglas', [
                'ticket_id' => $ticket_id,
                'event_id'  => $event_id,
                'reason'    => $rules_result['reason'] ?? '',
                'context'   => $args['context'],
            ]);
            return ['ok' => true, 'message' => $rules_result['reason'] ?? 'Omitido por reglas.', 'skipped_rules' => true];
        }
    }

    $settings = eventosapp_whatsapp_get_settings();
    $phone_raw = get_post_meta($ticket_id, '_eventosapp_asistente_tel', true);
    $phone = eventosapp_whatsapp_normalize_phone($phone_raw, $settings['default_country_code'] ?? '57');

    if ( ! $phone ) {
        delete_transient($lock_key);
        eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', 'El asistente no tiene celular válido para WhatsApp.', $args, (string) $phone_raw);
        eventosapp_whatsapp_masivo_activity_log('whatsapp_masivo_cancelado_celular_invalido', [
            'ticket_id'            => $ticket_id,
            'event_id'             => $event_id,
            'phone_raw'            => $phone_raw,
            'default_country_code' => $settings['default_country_code'] ?? '',
        ]);
        return ['ok' => false, 'message' => 'El asistente no tiene celular válido para WhatsApp.'];
    }

    $assets_prepare_result = eventosapp_whatsapp_prepare_ticket_assets($ticket_id, [
        'event_id'               => $event_id,
        'context'                => 'whatsapp_bulk_before_send',
        'apply_variant'          => true,
        'refresh_enabled_assets' => true,
        'ensure_qr'              => true,
        'ensure_landing'         => true,
        'ensure_message_image'   => false,
        'rebuild_search_index'   => true,
        'log'                    => true,
    ]);

    $qr_url = function_exists('eventosapp_whatsapp_ensure_qr_url') ? eventosapp_whatsapp_ensure_qr_url($ticket_id) : '';
    $message_image_url = function_exists('eventosapp_whatsapp_prepare_message_image_url') ? eventosapp_whatsapp_prepare_message_image_url($ticket_id, $qr_url) : $qr_url;

    $components_result = eventosapp_whatsapp_build_ticket_template_components($template, $ticket_id, $event_id, $message_image_url);
    if ( empty($components_result['ok']) ) {
        delete_transient($lock_key);
        $message = sanitize_text_field((string)($components_result['message'] ?? 'No se pudieron construir los componentes de la plantilla.'));
        eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', $message, $args, $phone, [
            'debug' => $components_result['debug'] ?? [],
            'transport' => 'template',
            'template_name' => $template['name'] ?? '',
        ]);
        eventosapp_whatsapp_masivo_activity_log('whatsapp_masivo_componentes_template_error', [
            'ticket_id'     => $ticket_id,
            'event_id'      => $event_id,
            'template_id'   => $template_id,
            'template_name' => $template['name'] ?? '',
            'message'       => $message,
            'debug'         => $components_result['debug'] ?? [],
        ]);
        return ['ok' => false, 'message' => $message];
    }

    $payload = eventosapp_whatsapp_build_template_payload($template['name'], $template['language'], $components_result['components']);
    $transport = 'template';
    $template_name = sanitize_key((string)($template['name'] ?? ''));
    $template_language = sanitize_text_field((string)($template['language'] ?? ''));

    $pre_debug = [
        'ticket_id'                 => $ticket_id,
        'event_id'                  => $event_id,
        'context'                   => sanitize_text_field((string)($args['context'] ?? 'whatsapp_bulk_send')),
        'segment_id'                => sanitize_key((string)($args['segment_id'] ?? '')),
        'source_key'                => $source_key,
        'to'                        => $phone,
        'phone_raw'                 => $phone_raw,
        'qr_url_present'            => $qr_url !== '',
        'message_image_url_present' => $message_image_url !== '',
        'transport'                 => $transport,
        'template_id'               => $template_id,
        'template_storage_key'      => $template['_storage_key'] ?? '',
        'template_name'             => $template_name,
        'template_language'         => $template_language,
        'template_meta_status'      => $template['meta_status'] ?? '',
        'payload_builder'           => $components_result['debug'] ?? [],
        'assets_prepare'            => $assets_prepare_result,
    ];

    if ( function_exists('eventosapp_whatsapp_sanitize_log_context') ) {
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_debug', eventosapp_whatsapp_sanitize_log_context($pre_debug));
    } else {
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_debug', $pre_debug);
    }
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_transport', $transport);
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_template_id', $template_id);
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_template_name', $template_name);
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_template_language', $template_language);
    update_post_meta($ticket_id, '_eventosapp_whatsapp_masivo_last_segment_id', sanitize_key((string)($args['segment_id'] ?? '')));
    update_post_meta($ticket_id, '_eventosapp_whatsapp_masivo_last_template_id', $template_id);
    update_post_meta($ticket_id, '_eventosapp_whatsapp_masivo_last_at', current_time('mysql'));

    if ( function_exists('eventosapp_whatsapp_summarize_payload') ) {
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_payload_summary', eventosapp_whatsapp_summarize_payload(array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $phone,
        ], $payload)));
    }

    eventosapp_whatsapp_masivo_activity_log('whatsapp_masivo_ticket_preparado', $pre_debug);
    eventosapp_whatsapp_add_ticket_log($ticket_id, 'preparado', 'Solicitud masiva preparada para Meta.', $args, $phone, [
        'http_code'     => 0,
        'debug'         => $pre_debug,
        'transport'     => $transport,
        'template_name' => $template_name,
    ]);

    $result = eventosapp_whatsapp_api_send_message($phone, $payload, $settings);

    $result_debug = isset($result['debug']) && is_array($result['debug']) ? $result['debug'] : [];
    $message_id = isset($result['message_id']) ? sanitize_text_field((string)$result['message_id']) : ( function_exists('eventosapp_whatsapp_extract_message_id') ? eventosapp_whatsapp_extract_message_id($result['response'] ?? []) : '' );
    $final_debug = array_merge($pre_debug, [
        'api_result'       => $result_debug,
        'http_code'        => isset($result['http_code']) ? (int) $result['http_code'] : 0,
        'message_id'       => $message_id,
        'response_summary' => $result['response_summary'] ?? ( function_exists('eventosapp_whatsapp_summarize_response') ? eventosapp_whatsapp_summarize_response($result['response'] ?? []) : [] ),
    ]);

    if ( function_exists('eventosapp_whatsapp_sanitize_log_context') ) {
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_debug', eventosapp_whatsapp_sanitize_log_context($final_debug));
    } else {
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_debug', $final_debug);
    }
    update_post_meta($ticket_id, '_eventosapp_whatsapp_last_http_code', isset($result['http_code']) ? (int) $result['http_code'] : 0);

    $result['transport'] = $transport;
    $result['template_id'] = $template_id;
    $result['template_name'] = $template_name;
    $result['template_language'] = $template_language;

    if ( ! empty($result['ok']) ) {
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_status', 'aceptado_meta');
        update_post_meta($ticket_id, '_eventosapp_whatsapp_delivery_status', 'pendiente_webhook');
        delete_post_meta($ticket_id, '_eventosapp_whatsapp_delivery_at');
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_sent_at', current_time('mysql'));
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_to', $phone);
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_error', '');
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_response', $result['response'] ?? []);
        if ( $message_id !== '' ) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_message_id', $message_id);
            if ( function_exists('eventosapp_whatsapp_register_message_map') ) {
                eventosapp_whatsapp_register_message_map($message_id, $ticket_id, $args['context'] ?? 'whatsapp_bulk_send', $phone);
            }
        }
        if ( $source_key !== '' ) {
            update_post_meta($ticket_id, '_eventosapp_whatsapp_last_source_key', $source_key);
        }
        eventosapp_whatsapp_masivo_activity_log('whatsapp_masivo_ticket_aceptado_por_meta', $final_debug);
        eventosapp_whatsapp_add_ticket_log($ticket_id, 'aceptado_meta', $result['message'] ?? 'Solicitud aceptada por Meta. Esperando webhook de entrega.', $args, $phone, array_merge($result, [
            'debug'           => $final_debug,
            'transport'       => $transport,
            'template_name'   => $template_name,
            'delivery_status' => 'pendiente_webhook',
        ]));
    } else {
        delete_transient($lock_key);
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_status', 'error');
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_error', $result['message'] ?? 'Error desconocido.');
        update_post_meta($ticket_id, '_eventosapp_whatsapp_last_response', $result['response'] ?? []);
        eventosapp_whatsapp_masivo_activity_log('whatsapp_masivo_ticket_error_meta_o_local', $final_debug);
        eventosapp_whatsapp_add_ticket_log($ticket_id, 'error', $result['message'] ?? 'Error desconocido.', $args, $phone, array_merge($result, [
            'debug'         => $final_debug,
            'transport'     => $transport,
            'template_name' => $template_name,
        ]));
    }

    return $result;
}
