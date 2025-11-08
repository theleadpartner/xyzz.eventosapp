<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
  register_rest_route('eventosapp/v1', '/persons/(?P<cc>[A-Za-z0-9_\-]+)', [
    'methods'  => 'GET',
    'callback' => 'evapp_api_get_person_by_cc',
    'permission_callback' => '__return_true',
  ]);

  register_rest_route('eventosapp/v1', '/companies/(?P<nit>[A-Za-z0-9_\-]+)', [
    'methods'  => 'GET',
    'callback' => 'evapp_api_get_company_by_nit',
    'permission_callback' => '__return_true',
  ]);
});

/** Helper: arma respuesta estándar */
function evapp_api_ok($data){ return new WP_REST_Response(['ok'=>true,'data'=>$data], 200); }
function evapp_api_not_found($msg='Not found'){ return new WP_REST_Response(['ok'=>false,'message'=>$msg], 404); }

/** GET /persons/{cc}?event_id=123 */
function evapp_api_get_person_by_cc( WP_REST_Request $req ){
  $cc       = sanitize_text_field($req['cc']);
  $event_id = intval($req->get_param('event_id'));

  if (!$cc) return evapp_api_not_found('CC vacío');

  // Busca el ticket más reciente que coincida (y opcionalmente por evento)
  $meta_query = [
    ['key'=>'_eventosapp_asistente_cc', 'value'=>$cc],
  ];
  if ($event_id) {
    $meta_query[] = ['key'=>'_eventosapp_ticket_evento_id', 'value'=>$event_id];
  }

  $posts = get_posts([
    'post_type'      => 'eventosapp_ticket',
    'posts_per_page' => 1,
    'orderby'        => 'ID',
    'order'          => 'DESC',
    'meta_query'     => $meta_query,
    'fields'         => 'ids',
  ]);

  if (!$posts) return evapp_api_not_found();

  $pid = $posts[0];
  $data = [
    'cc'         => get_post_meta($pid, '_eventosapp_asistente_cc', true),
    'first_name' => get_post_meta($pid, '_eventosapp_asistente_nombre', true),
    'last_name'  => get_post_meta($pid, '_eventosapp_asistente_apellido', true),
    'email'      => get_post_meta($pid, '_eventosapp_asistente_email', true),
    'phone'      => get_post_meta($pid, '_eventosapp_asistente_tel', true),
    'company'    => get_post_meta($pid, '_eventosapp_asistente_empresa', true),
    'city'       => get_post_meta($pid, '_eventosapp_asistente_ciudad', true),
    'country'    => get_post_meta($pid, '_eventosapp_asistente_pais', true),
    'localidad'  => get_post_meta($pid, '_eventosapp_asistente_localidad', true),
  ];

  return evapp_api_ok($data);
}

/** GET /companies/{nit}?event_id=123 */
function evapp_api_get_company_by_nit( WP_REST_Request $req ){
  $nit      = sanitize_text_field($req['nit']);
  $event_id = intval($req->get_param('event_id'));

  if (!$nit) return evapp_api_not_found('NIT vacío');

  $meta_query = [
    ['key'=>'_eventosapp_asistente_nit', 'value'=>$nit],
  ];
  if ($event_id) {
    $meta_query[] = ['key'=>'_eventosapp_ticket_evento_id', 'value'=>$event_id];
  }

  $posts = get_posts([
    'post_type'      => 'eventosapp_ticket',
    'posts_per_page' => 1,
    'orderby'        => 'ID',
    'order'          => 'DESC',
    'meta_query'     => $meta_query,
    'fields'         => 'ids',
  ]);

  if (!$posts) return evapp_api_not_found();

  $pid = $posts[0];
  $data = [
    'nit'     => get_post_meta($pid, '_eventosapp_asistente_nit', true),
    'company' => get_post_meta($pid, '_eventosapp_asistente_empresa', true),
    'city'    => get_post_meta($pid, '_eventosapp_asistente_ciudad', true),
    'contact' => [
      'email' => get_post_meta($pid, '_eventosapp_asistente_email', true),
      'phone' => get_post_meta($pid, '_eventosapp_asistente_tel', true),
    ],
  ];

  return evapp_api_ok($data);
}
