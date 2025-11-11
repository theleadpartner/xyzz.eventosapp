<?php
/**
 * Sistema de condicionales para Webhook → EventosApp
 * Permite configurar reglas que determinan si se envía correo y con qué plantilla
 * 
 * @package EventosApp
 */

if (!defined('ABSPATH')) exit;

/**
 * Obtiene las plantillas de correo disponibles
 */
function eventosapp_get_available_email_templates() {
    $templates = [];
    $dir = plugin_dir_path(dirname(dirname(__FILE__))) . 'templates/email_tickets/';
    
    if (is_dir($dir)) {
        $files = glob($dir . '*.html');
        foreach ($files as $file) {
            $basename = basename($file);
            $label = str_replace(['-', '_', '.html'], [' ', ' ', ''], $basename);
            $label = ucwords($label);
            $templates[$basename] = $label;
        }
    }
    
    // Asegurar que siempre haya al menos la plantilla por defecto
    if (empty($templates)) {
        $templates['email-ticket.html'] = 'Email Ticket (por defecto)';
    }
    
    return $templates;
}

/**
 * Obtiene los campos disponibles para las condicionales (base + extras del evento)
 */
function eventosapp_get_conditional_fields($event_id) {
    $fields = [
        // Campos base del ticket
        'email'     => 'Email',
        'nombre'    => 'Nombre',
        'apellido'  => 'Apellido',
        'cc'        => 'Cédula/CC',
        'nit'       => 'NIT',
        'empresa'   => 'Empresa',
        'cargo'     => 'Cargo',
        'tel'       => 'Teléfono',
        'ciudad'    => 'Ciudad',
        'pais'      => 'País',
        'localidad' => 'Localidad',
    ];
    
    // Agregar campos extras del evento si existen
    if (function_exists('eventosapp_get_event_extra_fields') && $event_id) {
        $extras = eventosapp_get_event_extra_fields($event_id);
        if (is_array($extras)) {
            foreach ($extras as $extra) {
                if (isset($extra['key']) && isset($extra['label'])) {
                    $fields['extra_' . $extra['key']] = $extra['label'] . ' (extra)';
                }
            }
        }
    }
    
    return $fields;
}

/**
 * Obtiene el valor de un campo del ticket
 */
function eventosapp_get_ticket_field_value($ticket_id, $field_key) {
    // Mapeo de campos base a sus meta_keys
    $meta_map = [
        'email'     => '_eventosapp_asistente_email',
        'nombre'    => '_eventosapp_asistente_nombre',
        'apellido'  => '_eventosapp_asistente_apellido',
        'cc'        => '_eventosapp_asistente_cc',
        'nit'       => '_eventosapp_asistente_nit',
        'empresa'   => '_eventosapp_asistente_empresa',
        'cargo'     => '_eventosapp_asistente_cargo',
        'tel'       => '_eventosapp_asistente_tel',
        'ciudad'    => '_eventosapp_asistente_ciudad',
        'pais'      => '_eventosapp_asistente_pais',
        'localidad' => '_eventosapp_asistente_localidad',
    ];
    
    // Si es un campo base
    if (isset($meta_map[$field_key])) {
        return (string) get_post_meta($ticket_id, $meta_map[$field_key], true);
    }
    
    // Si es un campo extra (formato: extra_nombredelcampo)
    if (strpos($field_key, 'extra_') === 0) {
        $extra_key = substr($field_key, 6); // Quitar 'extra_'
        $extras = get_post_meta($ticket_id, '_eventosapp_ticket_extras', true);
        if (is_array($extras) && isset($extras[$extra_key])) {
            return (string) $extras[$extra_key];
        }
    }
    
    return '';
}

/**
 * Evalúa un operador de comparación
 */
function eventosapp_evaluate_condition_operator($field_value, $operator, $compare_value) {
    // Normalizar valores para comparación
    $field_value = trim($field_value);
    $compare_value = trim($compare_value);
    
    switch ($operator) {
        case 'equals':
            return strcasecmp($field_value, $compare_value) === 0;
            
        case 'not_equals':
            return strcasecmp($field_value, $compare_value) !== 0;
            
        case 'contains':
            return stripos($field_value, $compare_value) !== false;
            
        case 'not_contains':
            return stripos($field_value, $compare_value) === false;
            
        case 'starts_with':
            return stripos($field_value, $compare_value) === 0;
            
        case 'ends_with':
            $len = strlen($compare_value);
            if ($len === 0) return true;
            return strcasecmp(substr($field_value, -$len), $compare_value) === 0;
            
        case 'is_empty':
            return empty($field_value);
            
        case 'is_not_empty':
            return !empty($field_value);
            
        default:
            return false;
    }
}

/**
 * Evalúa las condicionales del webhook para un ticket
 * Retorna: ['send_email' => bool, 'template' => string|null, 'matched_rule' => array|null]
 */
function eventosapp_evaluate_webhook_conditionals($ticket_id, $event_id) {
    $default_result = [
        'send_email' => true,
        'template' => null, // null = usar plantilla por defecto del evento
        'matched_rule' => null,
    ];
    
    // Obtener configuración de condicionales del evento
    $config = get_post_meta($event_id, '_eventosapp_webhook_conditionals', true);
    
    // Si no hay configuración o no está habilitada, comportamiento por defecto
    if (!is_array($config) || empty($config['enabled'])) {
        return $default_result;
    }
    
    $rules = isset($config['rules']) && is_array($config['rules']) ? $config['rules'] : [];
    
    // Si no hay reglas, comportamiento por defecto
    if (empty($rules)) {
        return $default_result;
    }
    
    // Evaluar reglas en orden (primera coincidencia gana)
    foreach ($rules as $index => $rule) {
        // Validar estructura de la regla
        if (!isset($rule['field']) || !isset($rule['operator']) || !isset($rule['action'])) {
            continue;
        }
        
        // Obtener valor del campo del ticket
        $field_value = eventosapp_get_ticket_field_value($ticket_id, $rule['field']);
        
        // Para operadores que no requieren valor de comparación
        $compare_value = isset($rule['value']) ? $rule['value'] : '';
        
        // Evaluar la condición
        $condition_met = eventosapp_evaluate_condition_operator(
            $field_value,
            $rule['operator'],
            $compare_value
        );
        
        // Si la condición se cumple, ejecutar la acción
        if ($condition_met) {
            $result = ['matched_rule' => $rule];
            
            if ($rule['action'] === 'no_email') {
                $result['send_email'] = false;
                $result['template'] = null;
            } elseif ($rule['action'] === 'send_with_template') {
                $result['send_email'] = true;
                $result['template'] = isset($rule['template']) ? $rule['template'] : null;
            } else {
                // Acción desconocida, comportamiento por defecto
                return $default_result;
            }
            
            return $result;
        }
    }
    
    // Ninguna regla coincidió, comportamiento por defecto
    return $default_result;
}

/**
 * Registra el metabox de condicionales
 */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_webhook_conditionals',
        'Webhook → Condicionales de envío de correo',
        'eventosapp_render_webhook_conditionals_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
}, 45);

/**
 * Renderiza el metabox de condicionales
 */
function eventosapp_render_webhook_conditionals_metabox($post) {
    wp_nonce_field('eventosapp_webhook_conditionals_save', 'eventosapp_webhook_conditionals_nonce');
    
    $config = get_post_meta($post->ID, '_eventosapp_webhook_conditionals', true);
    if (!is_array($config)) {
        $config = ['enabled' => false, 'rules' => []];
    }
    
    $enabled = !empty($config['enabled']);
    $rules = isset($config['rules']) && is_array($config['rules']) ? $config['rules'] : [];
    
    $available_fields = eventosapp_get_conditional_fields($post->ID);
    $available_templates = eventosapp_get_available_email_templates();
    
    $operators = [
        'equals' => 'Es igual a (exacto)',
        'not_equals' => 'No es igual a',
        'contains' => 'Contiene',
        'not_contains' => 'No contiene',
        'starts_with' => 'Comienza con',
        'ends_with' => 'Termina con',
        'is_empty' => 'Está vacío',
        'is_not_empty' => 'No está vacío',
    ];
    
    ?>
    <style>
        .evapp-cond-wrap { font-size: 13px; line-height: 1.5; }
        .evapp-cond-wrap .intro { color: #666; margin-bottom: 16px; padding: 12px; background: #f8f9fa; border-left: 3px solid #2271b1; }
        .evapp-cond-wrap .enable-toggle { margin-bottom: 20px; padding: 12px; background: #fff; border: 1px solid #ddd; border-radius: 4px; }
        .evapp-cond-wrap .rules-container { margin-top: 20px; }
        .evapp-cond-wrap .rule-item { border: 1px solid #ddd; border-radius: 8px; padding: 16px; margin-bottom: 12px; background: #fff; position: relative; }
        .evapp-cond-wrap .rule-item:hover { border-color: #2271b1; }
        .evapp-cond-wrap .rule-header { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #eee; }
        .evapp-cond-wrap .rule-header .rule-number { font-weight: 600; color: #2271b1; }
        .evapp-cond-wrap .rule-header .rule-delete { margin-left: auto; color: #dc3545; cursor: pointer; }
        .evapp-cond-wrap .rule-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 12px; }
        .evapp-cond-wrap .rule-row.action-row { grid-template-columns: 1fr 1fr; }
        .evapp-cond-wrap .field-group { display: flex; flex-direction: column; }
        .evapp-cond-wrap .field-group label { font-weight: 600; margin-bottom: 4px; color: #333; }
        .evapp-cond-wrap .field-group select,
        .evapp-cond-wrap .field-group input[type="text"] { width: 100%; padding: 6px 8px; border: 1px solid #ddd; border-radius: 4px; }
        .evapp-cond-wrap .add-rule-btn { margin-top: 12px; }
        .evapp-cond-wrap .template-field { display: none; }
        .evapp-cond-wrap .template-field.visible { display: flex; }
        .evapp-cond-wrap .help-text { font-size: 12px; color: #666; margin-top: 4px; font-style: italic; }
        .evapp-cond-wrap .disabled-overlay { pointer-events: none; opacity: 0.5; }
    </style>
    
    <div class="evapp-cond-wrap">
        <div class="intro">
            <strong>¿Qué hace esto?</strong><br>
            Configura reglas para controlar el envío de correos cuando llegan inscripciones vía webhook.<br>
            Puedes decidir si se envía correo o no, y qué plantilla usar, según el contenido de los campos del ticket.<br><br>
            <strong>Orden de evaluación:</strong> Las reglas se evalúan de arriba hacia abajo. La primera regla que coincida se ejecuta (las demás se ignoran).
        </div>
        
        <div class="enable-toggle">
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                <input type="checkbox" name="evapp_cond_enabled" value="1" <?php checked($enabled); ?> id="evapp_cond_enabled">
                <strong>Activar condicionales para este evento</strong>
            </label>
            <p class="help-text">Si está desactivado, todos los tickets del webhook recibirán correo con la plantilla por defecto del evento.</p>
        </div>
        
        <div id="evapp_rules_wrapper" class="<?php echo $enabled ? '' : 'disabled-overlay'; ?>">
            <div class="rules-container" id="evapp_rules_list">
                <?php if (empty($rules)): ?>
                    <p style="color: #666; font-style: italic;">No hay reglas configuradas. Agrega una regla para empezar.</p>
                <?php else: ?>
                    <?php foreach ($rules as $index => $rule): ?>
                        <?php 
                        $show_template = isset($rule['action']) && $rule['action'] === 'send_with_template';
                        ?>
                        <div class="rule-item" data-index="<?php echo esc_attr($index); ?>">
                            <div class="rule-header">
                                <span class="rule-number">Regla #<span class="rule-num"><?php echo $index + 1; ?></span></span>
                                <span class="rule-delete" title="Eliminar regla">❌ Eliminar</span>
                            </div>
                            
                            <div class="rule-row">
                                <div class="field-group">
                                    <label>Campo a evaluar</label>
                                    <select name="evapp_cond_rules[<?php echo $index; ?>][field]" class="rule-field">
                                        <option value="">-- Seleccionar --</option>
                                        <?php foreach ($available_fields as $key => $label): ?>
                                            <option value="<?php echo esc_attr($key); ?>" <?php selected(isset($rule['field']) ? $rule['field'] : '', $key); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="field-group">
                                    <label>Operador</label>
                                    <select name="evapp_cond_rules[<?php echo $index; ?>][operator]" class="rule-operator">
                                        <option value="">-- Seleccionar --</option>
                                        <?php foreach ($operators as $op => $label): ?>
                                            <option value="<?php echo esc_attr($op); ?>" <?php selected(isset($rule['operator']) ? $rule['operator'] : '', $op); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="field-group value-field">
                                    <label>Valor a comparar</label>
                                    <input type="text" 
                                           name="evapp_cond_rules[<?php echo $index; ?>][value]" 
                                           value="<?php echo esc_attr(isset($rule['value']) ? $rule['value'] : ''); ?>"
                                           placeholder="Ej: Si@, premium, test, etc.">
                                    <span class="help-text">No distingue mayúsculas/minúsculas. Deja vacío si el operador es "está vacío" o "no está vacío".</span>
                                </div>
                            </div>
                            
                            <div class="rule-row action-row">
                                <div class="field-group">
                                    <label>Acción si coincide</label>
                                    <select name="evapp_cond_rules[<?php echo $index; ?>][action]" class="rule-action">
                                        <option value="">-- Seleccionar --</option>
                                        <option value="no_email" <?php selected(isset($rule['action']) ? $rule['action'] : '', 'no_email'); ?>>
                                            🚫 NO enviar correo
                                        </option>
                                        <option value="send_with_template" <?php selected(isset($rule['action']) ? $rule['action'] : '', 'send_with_template'); ?>>
                                            ✉️ Enviar correo con plantilla específica
                                        </option>
                                    </select>
                                </div>
                                
                                <div class="field-group template-field <?php echo $show_template ? 'visible' : ''; ?>">
                                    <label>Plantilla de correo</label>
                                    <select name="evapp_cond_rules[<?php echo $index; ?>][template]" class="rule-template">
                                        <option value="">-- Usar plantilla por defecto del evento --</option>
                                        <?php foreach ($available_templates as $tpl_file => $tpl_label): ?>
                                            <option value="<?php echo esc_attr($tpl_file); ?>" <?php selected(isset($rule['template']) ? $rule['template'] : '', $tpl_file); ?>>
                                                <?php echo esc_html($tpl_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="help-text">Si está vacío, se usa la plantilla configurada en "Personalización del correo".</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <button type="button" class="button add-rule-btn" id="evapp_add_rule">➕ Agregar regla</button>
        </div>
    </div>
    
    <script>
    (function($) {
        var ruleIndex = <?php echo count($rules); ?>;
        
        // Plantilla de regla nueva
        var availableFields = <?php echo json_encode($available_fields); ?>;
        var operators = <?php echo json_encode($operators); ?>;
        var templates = <?php echo json_encode($available_templates); ?>;
        
        function createRuleHTML(index) {
            var fieldsOptions = '<option value="">-- Seleccionar --</option>';
            $.each(availableFields, function(key, label) {
                fieldsOptions += '<option value="' + key + '">' + label + '</option>';
            });
            
            var operatorsOptions = '<option value="">-- Seleccionar --</option>';
            $.each(operators, function(op, label) {
                operatorsOptions += '<option value="' + op + '">' + label + '</option>';
            });
            
            var templatesOptions = '<option value="">-- Usar plantilla por defecto del evento --</option>';
            $.each(templates, function(file, label) {
                templatesOptions += '<option value="' + file + '">' + label + '</option>';
            });
            
            return '<div class="rule-item" data-index="' + index + '">' +
                '<div class="rule-header">' +
                    '<span class="rule-number">Regla #<span class="rule-num">' + (index + 1) + '</span></span>' +
                    '<span class="rule-delete" title="Eliminar regla">❌ Eliminar</span>' +
                '</div>' +
                '<div class="rule-row">' +
                    '<div class="field-group">' +
                        '<label>Campo a evaluar</label>' +
                        '<select name="evapp_cond_rules[' + index + '][field]" class="rule-field">' + fieldsOptions + '</select>' +
                    '</div>' +
                    '<div class="field-group">' +
                        '<label>Operador</label>' +
                        '<select name="evapp_cond_rules[' + index + '][operator]" class="rule-operator">' + operatorsOptions + '</select>' +
                    '</div>' +
                    '<div class="field-group value-field">' +
                        '<label>Valor a comparar</label>' +
                        '<input type="text" name="evapp_cond_rules[' + index + '][value]" placeholder="Ej: Si@, premium, test, etc.">' +
                        '<span class="help-text">No distingue mayúsculas/minúsculas. Deja vacío si el operador es "está vacío" o "no está vacío".</span>' +
                    '</div>' +
                '</div>' +
                '<div class="rule-row action-row">' +
                    '<div class="field-group">' +
                        '<label>Acción si coincide</label>' +
                        '<select name="evapp_cond_rules[' + index + '][action]" class="rule-action">' +
                            '<option value="">-- Seleccionar --</option>' +
                            '<option value="no_email">🚫 NO enviar correo</option>' +
                            '<option value="send_with_template">✉️ Enviar correo con plantilla específica</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="field-group template-field">' +
                        '<label>Plantilla de correo</label>' +
                        '<select name="evapp_cond_rules[' + index + '][template]" class="rule-template">' + templatesOptions + '</select>' +
                        '<span class="help-text">Si está vacío, se usa la plantilla configurada en "Personalización del correo".</span>' +
                    '</div>' +
                '</div>' +
            '</div>';
        }
        
        // Agregar regla
        $('#evapp_add_rule').on('click', function() {
            var html = createRuleHTML(ruleIndex);
            $('#evapp_rules_list').append(html);
            ruleIndex++;
            updateRuleNumbers();
        });
        
        // Eliminar regla
        $(document).on('click', '.rule-delete', function() {
            if (confirm('¿Estás seguro de eliminar esta regla?')) {
                $(this).closest('.rule-item').remove();
                updateRuleNumbers();
            }
        });
        
        // Mostrar/ocultar campo de plantilla según la acción
        $(document).on('change', '.rule-action', function() {
            var $row = $(this).closest('.rule-item');
            var $templateField = $row.find('.template-field');
            
            if ($(this).val() === 'send_with_template') {
                $templateField.addClass('visible');
            } else {
                $templateField.removeClass('visible');
            }
        });
        
        // Toggle enabled/disabled
        $('#evapp_cond_enabled').on('change', function() {
            if ($(this).is(':checked')) {
                $('#evapp_rules_wrapper').removeClass('disabled-overlay');
            } else {
                $('#evapp_rules_wrapper').addClass('disabled-overlay');
            }
        });
        
        // Actualizar números de reglas
        function updateRuleNumbers() {
            $('#evapp_rules_list .rule-item').each(function(i) {
                $(this).find('.rule-num').text(i + 1);
            });
        }
        
    })(jQuery);
    </script>
    <?php
}

/**
 * Guarda las condicionales del webhook
 */
add_action('save_post_eventosapp_event', function($post_id) {
    // Verificaciones de seguridad
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['eventosapp_webhook_conditionals_nonce'])) return;
    if (!wp_verify_nonce($_POST['eventosapp_webhook_conditionals_nonce'], 'eventosapp_webhook_conditionals_save')) return;
    if (!current_user_can('edit_post', $post_id)) return;
    
    // Recoger datos
    $enabled = isset($_POST['evapp_cond_enabled']) ? true : false;
    $rules_raw = isset($_POST['evapp_cond_rules']) && is_array($_POST['evapp_cond_rules']) ? $_POST['evapp_cond_rules'] : [];
    
    // Procesar y validar reglas
    $rules = [];
    foreach ($rules_raw as $rule) {
        // Validar que tenga los campos mínimos
        if (empty($rule['field']) || empty($rule['operator']) || empty($rule['action'])) {
            continue; // Saltar reglas incompletas
        }
        
        $clean_rule = [
            'field' => sanitize_text_field($rule['field']),
            'operator' => sanitize_text_field($rule['operator']),
            'value' => isset($rule['value']) ? sanitize_text_field($rule['value']) : '',
            'action' => sanitize_text_field($rule['action']),
        ];
        
        // Si la acción es enviar con plantilla, guardar la plantilla
        if ($clean_rule['action'] === 'send_with_template' && !empty($rule['template'])) {
            $clean_rule['template'] = sanitize_file_name($rule['template']);
        }
        
        $rules[] = $clean_rule;
    }
    
    // Guardar configuración
    $config = [
        'enabled' => $enabled,
        'rules' => $rules,
    ];
    
    update_post_meta($post_id, '_eventosapp_webhook_conditionals', $config);
}, 25);
