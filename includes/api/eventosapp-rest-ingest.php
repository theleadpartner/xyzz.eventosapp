<?php
/**
 * REST Ingest para EventosApp
 * Procesa webhooks de ActiveCampaign y otras plataformas
 * Incluye sistema de condicionales para envío de correo
 * 
 * @package EventosApp
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
  register_rest_route('eventosapp/v1', '/ingest/activecampaign', [
    'methods'  => 'POST',
    'callback' => 'evapp_ingest_activecampaign',
    'permission_callback' => '__return_true',
  ]);
});

function evapp_ingest_activecampaign( WP_REST_Request $req ){
  // 1) Seguridad
  $token_header = $req->get_header('x-webhook-token');
  $token_query  = $req->get_param('token');
  $token_saved  = get_option('evapp_ac_webhook_token'); // guárdalo en opciones
  if (!$token_saved || ($token_header !== $token_saved && $token_query !== $token_saved)) {
    return new WP_REST_Response(['ok'=>false,'message'=>'Forbidden'], 403);
  }

  // 2) Input (JSON o form-urlencoded)
  $in = $req->get_json_params();
  if (!is_array($in) || empty($in)) $in = $req->get_body_params();
  if (!is_array($in)) $in = [];

  // Helper para leer claves alternativas
  $get = function(array $a, array $keys){
    foreach ($keys as $k){
      if (isset($a[$k]) && $a[$k] !== '') return sanitize_text_field(wp_unslash($a[$k]));
      // Soporte notación con puntos (e.g. contact.email)
      if (strpos($k,'.') !== false){
        $cur = $a;
        foreach (explode('.', $k) as $seg){
          if (!is_array($cur) || !array_key_exists($seg, $cur)) { $cur = null; break; }
          $cur = $cur[$seg];
        }
        if ($cur !== null && $cur !== '') return sanitize_text_field(wp_unslash($cur));
      }
    }
    return '';
  };

  // 3) Campos
  $event_id = intval( $in['event_id'] ?? $req->get_param('event_id') );
  if (!$event_id) return new WP_REST_Response(['ok'=>false,'message'=>'Falta event_id'], 400);

  $email     = $get($in, ['email','contact[email]','contact.email']);
  $first     = $get($in, ['first_name','firstName','contact[first_name]','contact.first_name']);
  $last      = $get($in, ['last_name','lastName','contact[last_name]','contact.last_name']);
  $phone     = $get($in, ['phone','contact[phone]','contact.phone','phone_number']);
  $cc        = $get($in, ['cc','documento','cedula']);
  $nit       = $get($in, ['nit','company_nit']);
  $company   = $get($in, ['company','empresa','organization']);
  $city      = $get($in, ['city','contact.city']);
  $country   = $get($in, ['country','contact.country']);
  $localidad = $get($in, ['localidad']);

  if (!$email && !$cc && !$nit) {
    return new WP_REST_Response(['ok'=>false,'message'=>'Necesito email o cc/nit'], 400);
  }

  // 4) Buscar ticket existente por evento + (email | cc | nit)
  $meta_query = [
    ['key'=>'_eventosapp_ticket_evento_id', 'value'=>$event_id],
    'relation' => 'AND',
  ];
  if ($email) $meta_query[] = ['key'=>'_eventosapp_asistente_email', 'value'=>$email];
  if ($cc)    $meta_query[] = ['key'=>'_eventosapp_asistente_cc', 'value'=>$cc];
  if ($nit)   $meta_query[] = ['key'=>'_eventosapp_asistente_nit', 'value'=>$nit];

  $existing = get_posts([
    'post_type'      => 'eventosapp_ticket',
    'posts_per_page' => 1,
    'orderby'        => 'ID',
    'order'          => 'DESC',
    'meta_query'     => $meta_query,
    'fields'         => 'ids',
  ]);

  $is_new_ticket = empty($existing);

  // 5) Crear o actualizar
  if ($existing) {
    $pid = $existing[0];
  } else {
    $pid = wp_insert_post([
      'post_type'   => 'eventosapp_ticket',
      'post_status' => 'publish',
      'post_title'  => trim(($first.' '.$last)) ?: ($email ?: ($cc ?: $nit)),
    ], true);
    if (is_wp_error($pid)) {
      return new WP_REST_Response(['ok'=>false,'message'=>$pid->get_error_message()], 500);
    }
    update_post_meta($pid, '_eventosapp_ticket_evento_id', $event_id);
  }

  // 6) Metas del asistente (solo si vienen)
  $maybe = function($key,$val) use ($pid){ if($val!=='') update_post_meta($pid, $key, $val); };
  $maybe('_eventosapp_asistente_cc',        $cc);
  $maybe('_eventosapp_asistente_nit',       $nit);
  $maybe('_eventosapp_asistente_nombre',    $first);
  $maybe('_eventosapp_asistente_apellido',  $last);
  $maybe('_eventosapp_asistente_email',     $email);
  $maybe('_eventosapp_asistente_tel',       $phone);
  $maybe('_eventosapp_asistente_empresa',   $company);
  $maybe('_eventosapp_asistente_ciudad',    $city);
  $maybe('_eventosapp_asistente_pais',      $country);
  $maybe('_eventosapp_asistente_localidad', $localidad);

  // 7) Procesar campos extra (eventosapp_extra o campos directos con las keys del esquema)
  if (function_exists('eventosapp_get_event_extra_fields')) {
    $extra_schema = eventosapp_get_event_extra_fields($event_id);
    if (!empty($extra_schema) && is_array($extra_schema)) {
      $extra_values = [];
      
      // Primero revisar si viene un objeto eventosapp_extra
      $extras_obj = $in['eventosapp_extra'] ?? [];
      if (is_array($extras_obj)) {
        foreach ($extra_schema as $field) {
          $key = $field['key'];
          if (isset($extras_obj[$key]) && $extras_obj[$key] !== '') {
            $value = sanitize_text_field(wp_unslash($extras_obj[$key]));
            if (function_exists('eventosapp_normalize_extra_value')) {
              $value = eventosapp_normalize_extra_value($field, $value);
            }
            if ($value !== '') {
              $extra_values[$key] = $value;
            }
          }
        }
      }
      
      // También buscar en el nivel raíz del payload (por si vienen como campos directos)
      foreach ($extra_schema as $field) {
        $key = $field['key'];
        // Si ya lo tenemos del objeto eventosapp_extra, no sobrescribir
        if (isset($extra_values[$key])) continue;
        
        // Buscar en el nivel raíz
        if (isset($in[$key]) && $in[$key] !== '') {
          $value = sanitize_text_field(wp_unslash($in[$key]));
          if (function_exists('eventosapp_normalize_extra_value')) {
            $value = eventosapp_normalize_extra_value($field, $value);
          }
          if ($value !== '') {
            $extra_values[$key] = $value;
          }
        }
      }
      
      // Guardar campos extra si hay alguno
      if (!empty($extra_values)) {
        update_post_meta($pid, '_eventosapp_ticket_extras', $extra_values);
      }
    }
  }

  // 8) EVALUAR CONDICIONALES Y ENVIAR CORREO SI CORRESPONDE
  // Solo evaluar si es un ticket nuevo (no actualización)
  $should_send_email = false;
  $email_template = null;
  $conditional_result = null;
  
  if ($is_new_ticket && function_exists('eventosapp_evaluate_webhook_conditionals')) {
    $conditional_result = eventosapp_evaluate_webhook_conditionals($pid, $event_id);
    
    if (is_array($conditional_result)) {
      $should_send_email = !empty($conditional_result['send_email']);
      $email_template = $conditional_result['template'] ?? null;
      
      // Log de qué regla se aplicó (para debugging)
      if (isset($conditional_result['matched_rule']) && is_array($conditional_result['matched_rule'])) {
        update_post_meta($pid, '_eventosapp_webhook_conditional_matched', $conditional_result['matched_rule']);
      }
    }
  }
  
  // Enviar correo si corresponde
  if ($should_send_email && function_exists('eventosapp_send_ticket_email_now')) {
    // Si hay una plantilla específica de la condicional, temporalmente cambiar la plantilla del evento
    $original_template = null;
    if ($email_template) {
      $original_template = get_post_meta($event_id, '_eventosapp_email_tpl', true);
      update_post_meta($event_id, '_eventosapp_email_tpl', $email_template);
    }
    
    // Enviar el correo
    $send_result = eventosapp_send_ticket_email_now($pid, [
      'source' => 'webhook',
      'force' => false
    ]);
    
    // Restaurar plantilla original si fue modificada
    if ($email_template && $original_template !== null) {
      if ($original_template === '' || $original_template === false) {
        delete_post_meta($event_id, '_eventosapp_email_tpl');
      } else {
        update_post_meta($event_id, '_eventosapp_email_tpl', $original_template);
      }
    }
    
    // Log del resultado del envío
    if (is_array($send_result)) {
      update_post_meta($pid, '_eventosapp_webhook_email_result', [
        'success' => $send_result[0],
        'message' => $send_result[1],
        'template_used' => $email_template ?: 'default',
        'at' => current_time('mysql')
      ]);
    }
  } elseif ($is_new_ticket && $conditional_result) {
    // Si no se envió correo por una condicional, registrarlo
    update_post_meta($pid, '_eventosapp_webhook_email_skipped', [
      'reason' => 'conditional_rule',
      'matched_rule' => $conditional_result['matched_rule'] ?? null,
      'at' => current_time('mysql')
    ]);
  }

  return new WP_REST_Response([
    'ok' => true,
    'ticket_id' => intval($pid),
    'is_new' => $is_new_ticket,
    'email_sent' => $should_send_email
  ], $is_new_ticket ? 201 : 200);
}
