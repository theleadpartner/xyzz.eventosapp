<?php
/**
 * Archivo: includes/functions/eventosapp-webhook-conditionals.php
 *
 * Soporta reglas condicionales de envío de correo para tickets creados/actualizados por webhook.
 *
 * Compatibilidad:
 * - Mantiene evaluación por orden: primera regla que coincide gana.
 * - Mantiene compatibilidad con reglas antiguas de 1 solo criterio.
 * - Agrega múltiples criterios por regla con relación AND / OR.
 * - Evita colisiones fatales si la función pública ya fue declarada en otro archivo.
 */

if (!defined('ABSPATH')) exit;

if (!defined('EVENTOSAPP_WEBHOOK_CONDITIONALS_META_ENABLED')) {
    define('EVENTOSAPP_WEBHOOK_CONDITIONALS_META_ENABLED', '_eventosapp_webhook_conditionals_enabled');
}
if (!defined('EVENTOSAPP_WEBHOOK_CONDITIONALS_META_RULES')) {
    define('EVENTOSAPP_WEBHOOK_CONDITIONALS_META_RULES', '_eventosapp_webhook_conditionals_rules');
}
if (!defined('EVENTOSAPP_WEBHOOK_CONDITIONALS_NONCE')) {
    define('EVENTOSAPP_WEBHOOK_CONDITIONALS_NONCE', 'eventosapp_webhook_conditionals_nonce');
}

/**
 * Metas legacy soportadas en lectura y escritura para no perder compatibilidad.
 */
if (!function_exists('eventosapp_webhook_conditionals_meta_enabled_candidates')) {
    function eventosapp_webhook_conditionals_meta_enabled_candidates() {
        return [
            '_eventosapp_webhook_conditionals_enabled',
            '_eventosapp_webhook_email_conditionals_enabled',
            '_eventosapp_webhook_conditional_enabled',
            '_eventosapp_webhook_rules_enabled',
        ];
    }
}

if (!function_exists('eventosapp_webhook_conditionals_meta_rules_candidates')) {
    function eventosapp_webhook_conditionals_meta_rules_candidates() {
        return [
            '_eventosapp_webhook_conditionals_rules',
            '_eventosapp_webhook_email_conditionals_rules',
            '_eventosapp_webhook_conditional_rules',
            '_eventosapp_webhook_rules',
        ];
    }
}

/**
 * Registro del metabox.
 */
if (!function_exists('eventosapp_register_webhook_conditionals_metabox')) {
    function eventosapp_register_webhook_conditionals_metabox() {
        add_meta_box(
            'eventosapp_webhook_conditionals',
            'Webhook → Condicionales de envío de correo',
            'eventosapp_render_webhook_conditionals_metabox',
            'eventosapp_event',
            'normal',
            'default'
        );
    }
}
if (!has_action('add_meta_boxes', 'eventosapp_register_webhook_conditionals_metabox')) {
    add_action('add_meta_boxes', 'eventosapp_register_webhook_conditionals_metabox', 20);
}

/**
 * Guardado del metabox.
 */
if (!function_exists('eventosapp_save_webhook_conditionals_metabox')) {
    function eventosapp_save_webhook_conditionals_metabox($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (
            !isset($_POST[EVENTOSAPP_WEBHOOK_CONDITIONALS_NONCE]) ||
            !wp_verify_nonce($_POST[EVENTOSAPP_WEBHOOK_CONDITIONALS_NONCE], 'eventosapp_save_webhook_conditionals')
        ) {
            return;
        }

        $enabled = !empty($_POST['eventosapp_webhook_conditionals_enabled']) ? '1' : '0';
        eventosapp_webhook_conditionals_update_enabled_meta($post_id, $enabled);

        $raw_rules = isset($_POST['eventosapp_webhook_conditionals_rules']) && is_array($_POST['eventosapp_webhook_conditionals_rules'])
            ? wp_unslash($_POST['eventosapp_webhook_conditionals_rules'])
            : [];

        $normalized = eventosapp_webhook_conditionals_sanitize_rules($raw_rules, $post_id);
        eventosapp_webhook_conditionals_update_rules_meta($post_id, $normalized);
    }
}
if (!has_action('save_post_eventosapp_event', 'eventosapp_save_webhook_conditionals_metabox')) {
    add_action('save_post_eventosapp_event', 'eventosapp_save_webhook_conditionals_metabox', 20, 1);
}

/**
 * Render del metabox.
 */
if (!function_exists('eventosapp_render_webhook_conditionals_metabox')) {
    function eventosapp_render_webhook_conditionals_metabox($post) {
        wp_nonce_field('eventosapp_save_webhook_conditionals', EVENTOSAPP_WEBHOOK_CONDITIONALS_NONCE);

        $enabled       = eventosapp_webhook_conditionals_get_enabled($post->ID);
        $rules         = eventosapp_webhook_conditionals_get_rules($post->ID);
        $field_options = eventosapp_webhook_conditionals_get_field_options($post->ID);
        $templates     = eventosapp_webhook_conditionals_get_email_templates($post->ID);
        $operators     = eventosapp_webhook_conditionals_get_operators();
        $actions       = eventosapp_webhook_conditionals_get_actions();
        $relations     = [
            'all' => 'Cumplir TODOS los criterios (AND)',
            'any' => 'Cumplir CUALQUIERA de los criterios (OR)',
        ];

        if (empty($rules)) {
            $rules = [eventosapp_webhook_conditionals_get_default_rule()];
        }
        ?>
        <div class="eventosapp-webhook-conditions-wrap">
            <style>
                .eventosapp-webhook-conditions-wrap .evapp-wh-info-box{
                    background:#f6f7f7;
                    border-left:4px solid #2271b1;
                    padding:14px 16px;
                    margin-bottom:16px;
                }
                .eventosapp-webhook-conditions-wrap .evapp-wh-toggle-box,
                .eventosapp-webhook-conditions-wrap .evapp-wh-rule-box{
                    border:1px solid #dcdcde;
                    border-radius:8px;
                    background:#fff;
                    padding:16px;
                    margin-bottom:16px;
                }
                .eventosapp-webhook-conditions-wrap .evapp-wh-rule-head{
                    display:flex;
                    justify-content:space-between;
                    align-items:center;
                    gap:16px;
                    margin-bottom:14px;
                    padding-bottom:10px;
                    border-bottom:1px solid #e5e7eb;
                }
                .eventosapp-webhook-conditions-wrap .evapp-wh-grid-3{
                    display:grid;
                    grid-template-columns:1fr 1fr 1fr;
                    gap:14px;
                    margin-bottom:12px;
                }
                .eventosapp-webhook-conditions-wrap .evapp-wh-grid-2{
                    display:grid;
                    grid-template-columns:1fr 1fr;
                    gap:14px;
                }
                .eventosapp-webhook-conditions-wrap label{
                    display:block;
                    font-weight:600;
                    margin-bottom:6px;
                }
                .eventosapp-webhook-conditions-wrap select,
                .eventosapp-webhook-conditions-wrap input[type="text"]{
                    width:100%;
                    max-width:none;
                }
                .eventosapp-webhook-conditions-wrap .evapp-wh-help{
                    color:#666;
                    font-style:italic;
                    margin-top:6px;
                }
                .eventosapp-webhook-conditions-wrap .evapp-wh-criteria-wrap{
                    margin:14px 0 12px;
                    border:1px solid #e5e7eb;
                    border-radius:8px;
                    background:#fafafa;
                    padding:12px;
                }
                .eventosapp-webhook-conditions-wrap .evapp-wh-criteria-title{
                    display:flex;
                    justify-content:space-between;
                    align-items:center;
                    gap:12px;
                    margin-bottom:10px;
                }
                .eventosapp-webhook-conditions-wrap .evapp-wh-criterion-box{
                    border:1px solid #dcdcde;
                    background:#fff;
                    border-radius:6px;
                    padding:12px;
                    margin-bottom:10px;
                }
                .eventosapp-webhook-conditions-wrap .evapp-wh-criterion-actions,
                .eventosapp-webhook-conditions-wrap .evapp-wh-rule-actions{
                    display:flex;
                    justify-content:flex-end;
                    align-items:center;
                }
                .eventosapp-webhook-conditions-wrap .evapp-wh-muted{
                    color:#6b7280;
                    font-size:12px;
                }
                @media (max-width: 960px){
                    .eventosapp-webhook-conditions-wrap .evapp-wh-grid-3,
                    .eventosapp-webhook-conditions-wrap .evapp-wh-grid-2{
                        grid-template-columns:1fr;
                    }
                }
            </style>

            <div class="evapp-wh-info-box">
                <p style="margin:0 0 8px;"><strong>¿Qué hace esto?</strong><br>
                    Configura reglas para controlar el envío de correos cuando llegan inscripciones vía webhook.<br>
                    Puedes decidir si se envía correo o no, y qué plantilla usar, según el contenido de los campos del ticket.
                </p>
                <p style="margin:0;"><strong>Orden de evaluación:</strong> Las reglas se evalúan de arriba hacia abajo. La primera regla que coincida se ejecuta (las demás se ignoran).</p>
            </div>

            <div class="evapp-wh-toggle-box">
                <label style="display:flex;align-items:center;gap:10px;margin:0;">
                    <input type="checkbox" name="eventosapp_webhook_conditionals_enabled" value="1" <?php checked($enabled, '1'); ?>>
                    <span style="font-size:16px;font-weight:600;">Activar condicionales para este evento</span>
                </label>
                <p class="evapp-wh-help">Si está desactivado, todos los tickets del webhook recibirán correo con la plantilla por defecto del evento.</p>
            </div>

            <div id="evapp-wh-rules-root">
                <?php foreach ($rules as $rule_index => $rule): ?>
                    <?php eventosapp_render_webhook_conditionals_rule_block($post->ID, $rule_index, $rule, $field_options, $operators, $actions, $templates, $relations); ?>
                <?php endforeach; ?>
            </div>

            <p>
                <button type="button" class="button" id="evapp-wh-add-rule">➕ Agregar regla</button>
            </p>
        </div>

        <script type="text/html" id="tmpl-evapp-wh-rule">
            <?php
            $tpl_rule = eventosapp_webhook_conditionals_get_default_rule();
            eventosapp_render_webhook_conditionals_rule_block($post->ID, '__RULE_INDEX__', $tpl_rule, $field_options, $operators, $actions, $templates, $relations, true);
            ?>
        </script>

        <script type="text/html" id="tmpl-evapp-wh-criterion">
            <?php
            $tpl_criterion = eventosapp_webhook_conditionals_get_default_criterion();
            eventosapp_render_webhook_conditionals_criterion_block($post->ID, '__RULE_INDEX__', '__CRITERION_INDEX__', $tpl_criterion, $field_options, $operators, true);
            ?>
        </script>

        <script>
        (function($){
            function refreshRuleTitles(){
                $('#evapp-wh-rules-root .evapp-wh-rule-box').each(function(i){
                    $(this).find('.evapp-wh-rule-title').text('Regla #' + (i + 1));
                });
            }

            function bindRule($rule){
                $rule.on('click', '.evapp-wh-remove-rule', function(){
                    $(this).closest('.evapp-wh-rule-box').remove();
                    refreshRuleTitles();
                });

                $rule.on('click', '.evapp-wh-add-criterion', function(){
                    var $ruleBox = $(this).closest('.evapp-wh-rule-box');
                    var ruleIndex = $ruleBox.data('rule-index');
                    var $criteriaRoot = $ruleBox.find('.evapp-wh-criteria-root');
                    var criterionIndex = $criteriaRoot.children('.evapp-wh-criterion-box').length;
                    var html = $('#tmpl-evapp-wh-criterion').html()
                        .replace(/__RULE_INDEX__/g, String(ruleIndex))
                        .replace(/__CRITERION_INDEX__/g, String(criterionIndex));
                    $criteriaRoot.append(html);
                });

                $rule.on('click', '.evapp-wh-remove-criterion', function(){
                    var $criteriaRoot = $(this).closest('.evapp-wh-criteria-root');
                    if ($criteriaRoot.children('.evapp-wh-criterion-box').length <= 1) {
                        alert('Cada regla debe tener al menos un criterio.');
                        return;
                    }
                    $(this).closest('.evapp-wh-criterion-box').remove();
                });
            }

            $(document).ready(function(){
                $('#evapp-wh-rules-root .evapp-wh-rule-box').each(function(){
                    bindRule($(this));
                });
                refreshRuleTitles();

                $('#evapp-wh-add-rule').on('click', function(){
                    var ruleIndex = $('#evapp-wh-rules-root .evapp-wh-rule-box').length;
                    var html = $('#tmpl-evapp-wh-rule').html().replace(/__RULE_INDEX__/g, String(ruleIndex));
                    var $rule = $(html);
                    $('#evapp-wh-rules-root').append($rule);
                    bindRule($rule);
                    refreshRuleTitles();
                });
            });
        })(jQuery);
        </script>
        <?php
    }
}

/**
 * Render de una regla.
 */
if (!function_exists('eventosapp_render_webhook_conditionals_rule_block')) {
    function eventosapp_render_webhook_conditionals_rule_block($evento_id, $rule_index, $rule, $field_options, $operators, $actions, $templates, $relations, $for_template = false) {
        $relation = isset($rule['criteria_relation']) ? $rule['criteria_relation'] : 'all';
        $action   = isset($rule['action']) ? $rule['action'] : 'send_specific';
        $template = isset($rule['template']) ? $rule['template'] : '';
        $criteria = isset($rule['criteria']) && is_array($rule['criteria']) ? $rule['criteria'] : [eventosapp_webhook_conditionals_get_default_criterion()];

        $rule_attr = $for_template ? 'data-rule-index="__RULE_INDEX__"' : 'data-rule-index="' . esc_attr($rule_index) . '"';
        ?>
        <div class="evapp-wh-rule-box" <?php echo $rule_attr; ?>>
            <div class="evapp-wh-rule-head">
                <strong class="evapp-wh-rule-title">Regla #<?php echo is_numeric($rule_index) ? intval($rule_index) + 1 : 1; ?></strong>
                <button type="button" class="button-link-delete evapp-wh-remove-rule">✖ Eliminar</button>
            </div>

            <div class="evapp-wh-criteria-wrap">
                <div class="evapp-wh-criteria-title">
                    <div>
                        <label>Cómo deben cumplirse los criterios</label>
                        <select name="eventosapp_webhook_conditionals_rules[<?php echo esc_attr($rule_index); ?>][criteria_relation]">
                            <?php foreach ($relations as $relation_key => $relation_label): ?>
                                <option value="<?php echo esc_attr($relation_key); ?>" <?php selected($relation, $relation_key); ?>><?php echo esc_html($relation_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="evapp-wh-rule-actions">
                        <button type="button" class="button evapp-wh-add-criterion">➕ Agregar criterio</button>
                    </div>
                </div>

                <div class="evapp-wh-criteria-root">
                    <?php foreach ($criteria as $criterion_index => $criterion): ?>
                        <?php eventosapp_render_webhook_conditionals_criterion_block($evento_id, $rule_index, $criterion_index, $criterion, $field_options, $operators, $for_template); ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="evapp-wh-grid-2">
                <div>
                    <label>Acción si coincide</label>
                    <select name="eventosapp_webhook_conditionals_rules[<?php echo esc_attr($rule_index); ?>][action]">
                        <?php foreach ($actions as $action_key => $action_label): ?>
                            <option value="<?php echo esc_attr($action_key); ?>" <?php selected($action, $action_key); ?>><?php echo esc_html($action_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Plantilla de correo</label>
                    <select name="eventosapp_webhook_conditionals_rules[<?php echo esc_attr($rule_index); ?>][template]">
                        <option value="">Email Ticket (por defecto)</option>
                        <?php foreach ($templates as $template_id => $template_label): ?>
                            <option value="<?php echo esc_attr($template_id); ?>" <?php selected($template, $template_id); ?>><?php echo esc_html($template_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="evapp-wh-help">Si está vacío, se usa la plantilla configurada en “Personalización del correo”.</div>
                </div>
            </div>
        </div>
        <?php
    }
}

/**
 * Render de un criterio.
 */
if (!function_exists('eventosapp_render_webhook_conditionals_criterion_block')) {
    function eventosapp_render_webhook_conditionals_criterion_block($evento_id, $rule_index, $criterion_index, $criterion, $field_options, $operators, $for_template = false) {
        $field    = isset($criterion['field']) ? $criterion['field'] : '';
        $operator = isset($criterion['operator']) ? $criterion['operator'] : 'equals';
        $value    = isset($criterion['value']) ? $criterion['value'] : '';
        ?>
        <div class="evapp-wh-criterion-box">
            <div class="evapp-wh-grid-3">
                <div>
                    <label>Campo a evaluar</label>
                    <select name="eventosapp_webhook_conditionals_rules[<?php echo esc_attr($rule_index); ?>][criteria][<?php echo esc_attr($criterion_index); ?>][field]">
                        <?php foreach ($field_options as $group_label => $group_fields): ?>
                            <optgroup label="<?php echo esc_attr($group_label); ?>">
                                <?php foreach ($group_fields as $field_key => $field_label): ?>
                                    <option value="<?php echo esc_attr($field_key); ?>" <?php selected($field, $field_key); ?>><?php echo esc_html($field_label); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Operador</label>
                    <select name="eventosapp_webhook_conditionals_rules[<?php echo esc_attr($rule_index); ?>][criteria][<?php echo esc_attr($criterion_index); ?>][operator]">
                        <?php foreach ($operators as $operator_key => $operator_label): ?>
                            <option value="<?php echo esc_attr($operator_key); ?>" <?php selected($operator, $operator_key); ?>><?php echo esc_html($operator_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Valor a comparar</label>
                    <input type="text" name="eventosapp_webhook_conditionals_rules[<?php echo esc_attr($rule_index); ?>][criteria][<?php echo esc_attr($criterion_index); ?>][value]" value="<?php echo esc_attr($value); ?>">
                    <div class="evapp-wh-help">No distingue mayúsculas/minúsculas. Deja vacío si el operador es “está vacío” o “no está vacío”.</div>
                </div>
            </div>
            <div class="evapp-wh-criterion-actions">
                <button type="button" class="button-link-delete evapp-wh-remove-criterion">✖ Eliminar criterio</button>
            </div>
        </div>
        <?php
    }
}

/**
 * Evalúa reglas para un ticket.
 * Devuelve la primera coincidencia.
 */
if (!function_exists('eventosapp_evaluate_webhook_conditionals')) {
    function eventosapp_evaluate_webhook_conditionals($ticket_id, $evento_id) {
        $enabled = eventosapp_webhook_conditionals_get_enabled($evento_id);
        if ($enabled !== '1') {
            return [
                'send_email'   => true,
                'template'     => null,
                'matched_rule' => null,
            ];
        }

        $rules = eventosapp_webhook_conditionals_get_rules($evento_id);
        if (empty($rules)) {
            return [
                'send_email'   => true,
                'template'     => null,
                'matched_rule' => null,
            ];
        }

        foreach ($rules as $index => $rule) {
            $normalized_rule = eventosapp_webhook_conditionals_normalize_rule($rule);
            if (empty($normalized_rule['criteria'])) {
                continue;
            }

            $match = eventosapp_webhook_conditionals_match_rule($ticket_id, $evento_id, $normalized_rule);
            if (!$match) {
                continue;
            }

            $send_email = ($normalized_rule['action'] !== 'skip_email');
            $template   = $send_email ? ($normalized_rule['template'] ?: null) : null;

            return [
                'send_email' => $send_email,
                'template'   => $template,
                'matched_rule' => [
                    'rule_index'        => $index,
                    'criteria_relation' => $normalized_rule['criteria_relation'],
                    'criteria'          => $normalized_rule['criteria'],
                    'action'            => $normalized_rule['action'],
                    'template'          => $normalized_rule['template'],
                ],
            ];
        }

        return [
            'send_email'   => true,
            'template'     => null,
            'matched_rule' => null,
        ];
    }
}

if (!function_exists('eventosapp_webhook_conditionals_match_rule')) {
    function eventosapp_webhook_conditionals_match_rule($ticket_id, $evento_id, array $rule) {
        $results = [];
        foreach ($rule['criteria'] as $criterion) {
            $results[] = eventosapp_webhook_conditionals_match_criterion($ticket_id, $evento_id, $criterion);
        }

        if ($rule['criteria_relation'] === 'any') {
            return in_array(true, $results, true);
        }

        return !in_array(false, $results, true);
    }
}

if (!function_exists('eventosapp_webhook_conditionals_match_criterion')) {
    function eventosapp_webhook_conditionals_match_criterion($ticket_id, $evento_id, array $criterion) {
        $field    = isset($criterion['field']) ? (string) $criterion['field'] : '';
        $operator = isset($criterion['operator']) ? (string) $criterion['operator'] : 'equals';
        $expected = isset($criterion['value']) ? (string) $criterion['value'] : '';
        $actual   = eventosapp_webhook_conditionals_get_ticket_field_value($ticket_id, $evento_id, $field);

        $actual_norm   = eventosapp_webhook_conditionals_normalize_value($actual);
        $expected_norm = eventosapp_webhook_conditionals_normalize_value($expected);

        switch ($operator) {
            case 'equals':
                return $actual_norm === $expected_norm;
            case 'not_equals':
                return $actual_norm !== $expected_norm;
            case 'contains':
                return $expected_norm !== '' && strpos($actual_norm, $expected_norm) !== false;
            case 'not_contains':
                return $expected_norm !== '' && strpos($actual_norm, $expected_norm) === false;
            case 'starts_with':
                return $expected_norm !== '' && strpos($actual_norm, $expected_norm) === 0;
            case 'ends_with':
                if ($expected_norm === '') return false;
                $len = strlen($expected_norm);
                return substr($actual_norm, -$len) === $expected_norm;
            case 'is_empty':
                return $actual_norm === '';
            case 'is_not_empty':
                return $actual_norm !== '';
            case 'gt':
            case 'gte':
            case 'lt':
            case 'lte':
                $actual_num   = is_numeric($actual) ? (float) $actual : null;
                $expected_num = is_numeric($expected) ? (float) $expected : null;
                if ($actual_num === null || $expected_num === null) return false;
                if ($operator === 'gt')  return $actual_num >  $expected_num;
                if ($operator === 'gte') return $actual_num >= $expected_num;
                if ($operator === 'lt')  return $actual_num <  $expected_num;
                return $actual_num <= $expected_num;
        }

        return false;
    }
}

/**
 * Obtención del valor del campo del ticket.
 */
if (!function_exists('eventosapp_webhook_conditionals_get_ticket_field_value')) {
    function eventosapp_webhook_conditionals_get_ticket_field_value($ticket_id, $evento_id, $field) {
        $field = (string) $field;

        $map = [
            'ticket_id'         => 'eventosapp_ticketID',
            'nombre'            => '_eventosapp_asistente_nombre',
            'apellido'          => '_eventosapp_asistente_apellido',
            'email'             => '_eventosapp_asistente_email',
            'telefono'          => '_eventosapp_asistente_tel',
            'empresa'           => '_eventosapp_asistente_empresa',
            'cc'                => '_eventosapp_asistente_cc',
            'nit'               => '_eventosapp_asistente_nit',
            'cargo'             => '_eventosapp_asistente_cargo',
            'ciudad'            => '_eventosapp_asistente_ciudad',
            'pais'              => '_eventosapp_asistente_pais',
            'localidad'         => '_eventosapp_asistente_localidad',
            'estado_pago'       => '_eventosapp_estado_pago',
            'creation_channel'  => '_eventosapp_creation_channel',
            'external_id'       => '_eventosapp_external_id',
        ];

        if (isset($map[$field])) {
            return get_post_meta($ticket_id, $map[$field], true);
        }

        if (strpos($field, 'extra:') === 0) {
            $extra_key = substr($field, 6);
            return get_post_meta($ticket_id, '_eventosapp_extra_' . $extra_key, true);
        }

        return get_post_meta($ticket_id, $field, true);
    }
}

/**
 * Opciones de campos disponibles.
 */
if (!function_exists('eventosapp_webhook_conditionals_get_field_options')) {
    function eventosapp_webhook_conditionals_get_field_options($evento_id) {
        $base = [
            'Campos base del ticket' => [
                'ticket_id'        => 'ID del ticket',
                'nombre'           => 'Nombre',
                'apellido'         => 'Apellido',
                'email'            => 'Email',
                'telefono'         => 'Teléfono',
                'empresa'          => 'Empresa',
                'cc'               => 'Cédula',
                'nit'              => 'NIT',
                'cargo'            => 'Cargo',
                'ciudad'           => 'Ciudad',
                'pais'             => 'País',
                'localidad'        => 'Localidad',
                'estado_pago'      => 'Estado de pago',
                'creation_channel' => 'Canal de creación',
                'external_id'      => 'External ID',
            ],
        ];

        $extras = [];
        if (function_exists('eventosapp_get_event_extra_fields')) {
            $event_extras = (array) eventosapp_get_event_extra_fields($evento_id);
            foreach ($event_extras as $extra) {
                if (empty($extra['key'])) continue;
                $extras['Campos adicionales del evento']['extra:' . $extra['key']] = !empty($extra['label']) ? $extra['label'] . ' (extra)' : $extra['key'] . ' (extra)';
            }
        }

        return array_merge($base, $extras);
    }
}

if (!function_exists('eventosapp_webhook_conditionals_get_operators')) {
    function eventosapp_webhook_conditionals_get_operators() {
        return [
            'equals'       => 'Es igual a (exacto)',
            'not_equals'   => 'No es igual a',
            'contains'     => 'Contiene',
            'not_contains' => 'No contiene',
            'starts_with'  => 'Empieza por',
            'ends_with'    => 'Termina en',
            'is_empty'     => 'Está vacío',
            'is_not_empty' => 'No está vacío',
            'gt'           => 'Mayor que',
            'gte'          => 'Mayor o igual que',
            'lt'           => 'Menor que',
            'lte'          => 'Menor o igual que',
        ];
    }
}

if (!function_exists('eventosapp_webhook_conditionals_get_actions')) {
    function eventosapp_webhook_conditionals_get_actions() {
        return [
            'send_specific' => '✉️ Enviar correo con plantilla específica',
            'skip_email'    => '🚫 No enviar correo',
        ];
    }
}

/**
 * Plantillas disponibles.
 */
if (!function_exists('eventosapp_webhook_conditionals_get_email_templates')) {
    function eventosapp_webhook_conditionals_get_email_templates($evento_id = 0) {
        $templates = [];

        $possible_post_types = [
            'eventosapp_email_tpl',
            'eventosapp_email_template',
            'eventosapp_correo_tpl',
        ];

        foreach ($possible_post_types as $post_type) {
            $posts = get_posts([
                'post_type'         => $post_type,
                'post_status'       => ['publish', 'draft', 'private'],
                'numberposts'       => -1,
                'orderby'           => 'title',
                'order'             => 'ASC',
                'suppress_filters'  => false,
            ]);

            if (!empty($posts)) {
                foreach ($posts as $tpl) {
                    $templates[$tpl->ID] = $tpl->post_title;
                }
                break;
            }
        }

        return $templates;
    }
}

/**
 * Helpers de reglas.
 */
if (!function_exists('eventosapp_webhook_conditionals_get_default_rule')) {
    function eventosapp_webhook_conditionals_get_default_rule() {
        return [
            'criteria_relation' => 'all',
            'criteria'          => [eventosapp_webhook_conditionals_get_default_criterion()],
            'action'            => 'send_specific',
            'template'          => '',
        ];
    }
}

if (!function_exists('eventosapp_webhook_conditionals_get_default_criterion')) {
    function eventosapp_webhook_conditionals_get_default_criterion() {
        return [
            'field'    => 'email',
            'operator' => 'is_not_empty',
            'value'    => '',
        ];
    }
}

if (!function_exists('eventosapp_webhook_conditionals_sanitize_rules')) {
    function eventosapp_webhook_conditionals_sanitize_rules(array $raw_rules, $evento_id) {
        $sanitized = [];

        foreach ($raw_rules as $rule) {
            if (!is_array($rule)) continue;

            $rule = eventosapp_webhook_conditionals_normalize_rule($rule);
            $relation = ($rule['criteria_relation'] === 'any') ? 'any' : 'all';
            $action   = ($rule['action'] === 'skip_email') ? 'skip_email' : 'send_specific';
            $template = is_scalar($rule['template']) ? sanitize_text_field((string) $rule['template']) : '';

            $criteria_out = [];
            foreach ((array) $rule['criteria'] as $criterion) {
                if (!is_array($criterion)) continue;

                $field    = sanitize_text_field((string) ($criterion['field'] ?? ''));
                $operator = sanitize_text_field((string) ($criterion['operator'] ?? 'equals'));
                $value    = sanitize_text_field((string) ($criterion['value'] ?? ''));

                if ($field === '') continue;
                if (!array_key_exists($operator, eventosapp_webhook_conditionals_get_operators())) {
                    $operator = 'equals';
                }

                $criteria_out[] = [
                    'field'    => $field,
                    'operator' => $operator,
                    'value'    => $value,
                ];
            }

            if (empty($criteria_out)) {
                continue;
            }

            $sanitized[] = [
                'criteria_relation' => $relation,
                'criteria'          => array_values($criteria_out),
                'action'            => $action,
                'template'          => $template,
            ];
        }

        return array_values($sanitized);
    }
}

if (!function_exists('eventosapp_webhook_conditionals_normalize_rule')) {
    function eventosapp_webhook_conditionals_normalize_rule($rule) {
        $rule = is_array($rule) ? $rule : [];

        $criteria_relation = isset($rule['criteria_relation']) ? (string) $rule['criteria_relation'] : 'all';
        $action            = isset($rule['action']) ? (string) $rule['action'] : 'send_specific';
        $template          = isset($rule['template']) ? (string) $rule['template'] : '';
        $criteria          = [];

        if (!empty($rule['criteria']) && is_array($rule['criteria'])) {
            foreach ($rule['criteria'] as $criterion) {
                if (!is_array($criterion)) continue;
                $criteria[] = [
                    'field'    => isset($criterion['field']) ? (string) $criterion['field'] : '',
                    'operator' => isset($criterion['operator']) ? (string) $criterion['operator'] : 'equals',
                    'value'    => isset($criterion['value']) ? (string) $criterion['value'] : '',
                ];
            }
        }

        /**
         * Compatibilidad legacy: regla antigua con field / operator / value al nivel raíz.
         */
        if (empty($criteria) && (!empty($rule['field']) || !empty($rule['operator']) || array_key_exists('value', $rule))) {
            $criteria[] = [
                'field'    => isset($rule['field']) ? (string) $rule['field'] : '',
                'operator' => isset($rule['operator']) ? (string) $rule['operator'] : 'equals',
                'value'    => isset($rule['value']) ? (string) $rule['value'] : '',
            ];
        }

        if (empty($criteria)) {
            $criteria[] = eventosapp_webhook_conditionals_get_default_criterion();
        }

        return [
            'criteria_relation' => ($criteria_relation === 'any') ? 'any' : 'all',
            'criteria'          => array_values($criteria),
            'action'            => ($action === 'skip_email') ? 'skip_email' : 'send_specific',
            'template'          => $template,
        ];
    }
}

if (!function_exists('eventosapp_webhook_conditionals_normalize_value')) {
    function eventosapp_webhook_conditionals_normalize_value($value) {
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }
        $value = wp_strip_all_tags((string) $value);
        $value = remove_accents($value);
        if (function_exists('mb_strtolower')) {
            $value = mb_strtolower($value, 'UTF-8');
        } else {
            $value = strtolower($value);
        }
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim($value);
    }
}

if (!function_exists('eventosapp_webhook_conditionals_get_enabled')) {
    function eventosapp_webhook_conditionals_get_enabled($evento_id) {
        foreach (eventosapp_webhook_conditionals_meta_enabled_candidates() as $meta_key) {
            $value = get_post_meta($evento_id, $meta_key, true);
            if ($value !== '') {
                return (string) $value === '1' ? '1' : '0';
            }
        }
        return '0';
    }
}

if (!function_exists('eventosapp_webhook_conditionals_update_enabled_meta')) {
    function eventosapp_webhook_conditionals_update_enabled_meta($evento_id, $enabled) {
        $enabled = ((string) $enabled === '1') ? '1' : '0';
        foreach (eventosapp_webhook_conditionals_meta_enabled_candidates() as $meta_key) {
            update_post_meta($evento_id, $meta_key, $enabled);
        }
    }
}

if (!function_exists('eventosapp_webhook_conditionals_get_rules')) {
    function eventosapp_webhook_conditionals_get_rules($evento_id) {
        foreach (eventosapp_webhook_conditionals_meta_rules_candidates() as $meta_key) {
            $rules = get_post_meta($evento_id, $meta_key, true);
            if (!empty($rules) && is_array($rules)) {
                $out = [];
                foreach ($rules as $rule) {
                    $out[] = eventosapp_webhook_conditionals_normalize_rule($rule);
                }
                return $out;
            }
        }
        return [];
    }
}

if (!function_exists('eventosapp_webhook_conditionals_update_rules_meta')) {
    function eventosapp_webhook_conditionals_update_rules_meta($evento_id, array $rules) {
        foreach (eventosapp_webhook_conditionals_meta_rules_candidates() as $meta_key) {
            update_post_meta($evento_id, $meta_key, $rules);
        }
    }
}
