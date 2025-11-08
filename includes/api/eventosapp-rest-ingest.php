<?php
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

  return new WP_REST_Response(['ok'=>true,'ticket_id'=>intval($pid)], $existing ? 200 : 201);
}
