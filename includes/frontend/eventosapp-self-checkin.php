<?php
/**
 * EventosApp – Autogestión del Asistente
 *
 * Shortcode: [eventosapp_self_checkin]
 *
 * Módulo táctil para que el asistente se identifique por cédula, confirme sus datos
 * y dispare la impresión de la escarapela. Al imprimir, el ticket queda marcado como
 * check-in presencial del día del evento sin revertir estados existentes.
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('eventosapp_self_checkin_digits_only') ) {
    function eventosapp_self_checkin_digits_only( $value ) {
        return preg_replace('/\D+/', '', (string) $value);
    }
}

if ( ! function_exists('eventosapp_self_checkin_current_user_label') ) {
    function eventosapp_self_checkin_current_user_label() {
        $user = wp_get_current_user();
        if ( $user && $user->exists() ) {
            return $user->display_name . ' (' . $user->user_email . ')';
        }
        return 'Autogestión';
    }
}

if ( ! function_exists('eventosapp_self_checkin_get_active_event_id') ) {
    function eventosapp_self_checkin_get_active_event_id( $requested_event_id = 0 ) {
        $requested_event_id = absint( $requested_event_id );

        if ( $requested_event_id ) {
            return $requested_event_id;
        }

        if ( function_exists('eventosapp_get_active_event') ) {
            return absint( eventosapp_get_active_event() );
        }

        return 0;
    }
}

if ( ! function_exists('eventosapp_self_checkin_user_can_event') ) {
    function eventosapp_self_checkin_user_can_event( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return false;
        }

        if ( ! is_user_logged_in() ) {
            return false;
        }

        if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('self_checkin') ) {
            return false;
        }

        if ( current_user_can('manage_options') ) {
            return true;
        }

        if ( function_exists('eventosapp_get_active_event') ) {
            $active_event = absint( eventosapp_get_active_event() );
            if ( ! $active_event || $active_event !== $event_id ) {
                return false;
            }
        }

        if ( function_exists('eventosapp_user_can_manage_event') && eventosapp_user_can_manage_event( $event_id ) ) {
            return true;
        }

        if ( function_exists('eventosapp_staff_access_user_can_select_event_in_dashboard') && eventosapp_staff_access_user_can_select_event_in_dashboard( $event_id, get_current_user_id() ) ) {
            return true;
        }

        if ( function_exists('eventosapp_support_user_can_select_event_in_dashboard') && eventosapp_support_user_can_select_event_in_dashboard( $event_id, get_current_user_id() ) ) {
            return true;
        }

        if ( function_exists('eventosapp_support_user_has_assignment_in_event') && eventosapp_support_user_has_assignment_in_event( $event_id, get_current_user_id() ) ) {
            return true;
        }

        return false;
    }
}

if ( ! function_exists('eventosapp_self_checkin_validate_cc') ) {
    function eventosapp_self_checkin_validate_cc( $cedula ) {
        $digits = eventosapp_self_checkin_digits_only( $cedula );

        if ( $digits === '' ) {
            return new WP_Error('empty_cc', 'Ingresa una cédula para buscar.');
        }

        $len = strlen( $digits );
        if ( $len < 5 || $len > 12 ) {
            return new WP_Error('invalid_cc', 'La cédula debe contener entre 5 y 12 números.');
        }

        return $digits;
    }
}

if ( ! function_exists('eventosapp_self_checkin_find_tickets_by_cc') ) {
    function eventosapp_self_checkin_find_tickets_by_cc( $cedula_digits, $event_id, $limit = 10 ) {
        global $wpdb;

        $cedula_digits = eventosapp_self_checkin_digits_only( $cedula_digits );
        $event_id      = absint( $event_id );
        $limit         = max(1, min(20, absint( $limit )));

        if ( ! $cedula_digits || ! $event_id ) {
            return [];
        }

        $cache_key = 'evapp_self_cc_' . md5( $event_id . '|' . $cedula_digits . '|' . $limit );
        $cached = wp_cache_get( $cache_key, 'eventosapp_self_checkin' );
        if ( is_array( $cached ) ) {
            return array_map('intval', $cached);
        }

        $base_join = "
            INNER JOIN {$wpdb->postmeta} event_pm
                    ON event_pm.post_id = p.ID
                   AND event_pm.meta_key = %s
                   AND event_pm.meta_value = %s
            INNER JOIN {$wpdb->posts} p2
                    ON p2.ID = event_pm.post_id
        ";

        // 1) Índice segmentado generado por EventosApp: búsqueda exacta y rápida.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID
               FROM {$wpdb->posts} p
               {$base_join}
               INNER JOIN {$wpdb->postmeta} cc_idx
                       ON cc_idx.post_id = p.ID
                      AND cc_idx.meta_key = %s
                      AND cc_idx.meta_value = %s
              WHERE p.post_type = 'eventosapp_ticket'
                AND p.post_status NOT IN ('trash','auto-draft','inherit')
              ORDER BY p.ID DESC
              LIMIT %d",
            '_eventosapp_ticket_evento_id',
            (string) $event_id,
            '_evapp_search_cc',
            $cedula_digits,
            $limit
        ) );

        // 2) Fallback para tickets antiguos sin índice o con cédula guardada con puntos/espacios/guiones.
        if ( empty( $ids ) ) {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT p.ID
                   FROM {$wpdb->posts} p
                   {$base_join}
                   INNER JOIN {$wpdb->postmeta} cc_raw
                           ON cc_raw.post_id = p.ID
                          AND cc_raw.meta_key = %s
                  WHERE p.post_type = 'eventosapp_ticket'
                    AND p.post_status NOT IN ('trash','auto-draft','inherit')
                    AND REPLACE(REPLACE(REPLACE(REPLACE(cc_raw.meta_value, '.', ''), '-', ''), ' ', ''), ',', '') = %s
                  ORDER BY p.ID DESC
                  LIMIT %d",
                '_eventosapp_ticket_evento_id',
                (string) $event_id,
                '_eventosapp_asistente_cc',
                $cedula_digits,
                $limit
            ) );
        }

        $ids = array_map('intval', (array) $ids);
        wp_cache_set( $cache_key, $ids, 'eventosapp_self_checkin', 30 );

        return $ids;
    }
}

if ( ! function_exists('eventosapp_self_checkin_get_today_status') ) {
    function eventosapp_self_checkin_get_today_status( $ticket_id, $event_id ) {
        $ticket_id = absint( $ticket_id );
        $event_id  = absint( $event_id );

        $today = function_exists('eventosapp_get_today_in_event_tz')
            ? eventosapp_get_today_in_event_tz( $event_id )
            : current_time('Y-m-d');

        $status_arr = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
        if ( is_string( $status_arr ) ) $status_arr = @unserialize( $status_arr );
        if ( ! is_array( $status_arr ) ) $status_arr = [];

        $today_status = isset( $status_arr[$today] ) && $status_arr[$today] === 'checked_in'
            ? 'checked_in'
            : 'not_checked_in';

        $today_allowed = function_exists('eventosapp_is_today_valid_for_event')
            ? eventosapp_is_today_valid_for_event( $event_id )
            : true;

        if ( ! $today_allowed ) {
            $today_status = 'not_checked_in';
        }

        return [
            'today'         => $today,
            'today_status'  => $today_status,
            'today_allowed' => (bool) $today_allowed,
        ];
    }
}

if ( ! function_exists('eventosapp_self_checkin_ticket_payload') ) {
    function eventosapp_self_checkin_ticket_payload( $ticket_id ) {
        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
            return null;
        }

        $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        if ( ! $event_id ) {
            return null;
        }

        $first_name = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
        $last_name  = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );
        $company    = get_post_meta( $ticket_id, '_eventosapp_asistente_empresa', true );
        $role       = get_post_meta( $ticket_id, '_eventosapp_asistente_cargo', true );
        $cc         = get_post_meta( $ticket_id, '_eventosapp_asistente_cc', true );
        $email      = get_post_meta( $ticket_id, '_eventosapp_asistente_email', true );
        $phone      = get_post_meta( $ticket_id, '_eventosapp_asistente_tel', true );
        $localidad  = get_post_meta( $ticket_id, '_eventosapp_asistente_localidad', true );
        $ticket_pub = get_post_meta( $ticket_id, 'eventosapp_ticketID', true );
        $modalidad  = function_exists('eventosapp_get_ticket_modalidad') ? eventosapp_get_ticket_modalidad( $ticket_id ) : ( get_post_meta( $ticket_id, '_eventosapp_ticket_modalidad', true ) ?: 'presencial' );
        $modalidad_label = function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label( $ticket_id ) : ucfirst( $modalidad );
        $status = eventosapp_self_checkin_get_today_status( $ticket_id, $event_id );

        return [
            'ticket_id'       => $ticket_id,
            'event_id'        => $event_id,
            'event_name'      => get_the_title( $event_id ),
            'first_name'      => $first_name,
            'last_name'       => $last_name,
            'full_name'       => trim( $first_name . ' ' . $last_name ),
            'company'         => $company,
            'designation'     => $role,
            'cc'              => $cc,
            'email'           => $email,
            'phone'           => $phone,
            'localidad'       => $localidad,
            'ticket_pub'      => $ticket_pub,
            'modalidad'       => $modalidad,
            'modalidad_label' => $modalidad_label,
            'today'           => $status['today'],
            'today_status'    => $status['today_status'],
            'today_allowed'   => $status['today_allowed'],
            'already_checked' => ( $status['today_status'] === 'checked_in' ),
        ];
    }
}

if ( ! function_exists('eventosapp_self_checkin_mark_ticket') ) {
    function eventosapp_self_checkin_mark_ticket( $ticket_id ) {
        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
            return new WP_Error('invalid_ticket', 'Ticket inválido.');
        }

        $event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
        if ( ! $event_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
            return new WP_Error('invalid_event', 'Evento inválido.');
        }

        if ( ! eventosapp_self_checkin_user_can_event( $event_id ) ) {
            return new WP_Error('forbidden', 'Sin permisos para este evento.');
        }

        $today = function_exists('eventosapp_get_today_in_event_tz')
            ? eventosapp_get_today_in_event_tz( $event_id )
            : current_time('Y-m-d');

        $days = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days( $event_id ) : [];
        if ( empty( $days ) || ! in_array( $today, $days, true ) ) {
            return new WP_Error('invalid_day', 'El check-in solo está permitido en las fechas del evento. Hoy no corresponde.');
        }

        $status_arr = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
        if ( is_string( $status_arr ) ) $status_arr = @unserialize( $status_arr );
        if ( ! is_array( $status_arr ) ) $status_arr = [];

        $already = isset( $status_arr[$today] ) && $status_arr[$today] === 'checked_in';

        if ( ! $already ) {
            $status_arr[$today] = 'checked_in';
            update_post_meta( $ticket_id, '_eventosapp_checkin_status', $status_arr );

            try {
                $tz = new DateTimeZone( get_post_meta( $event_id, '_eventosapp_zona_horaria', true ) ?: wp_timezone_string() );
            } catch ( Exception $e ) {
                $tz = wp_timezone();
            }
            $now = new DateTime( 'now', $tz );

            $log = get_post_meta( $ticket_id, '_eventosapp_checkin_log', true );
            if ( is_string( $log ) ) $log = @unserialize( $log );
            if ( ! is_array( $log ) ) $log = [];

            $log[] = [
                'fecha'         => $now->format('Y-m-d'),
                'hora'          => $now->format('H:i:s'),
                'dia'           => $today,
                'status'        => 'checked_in',
                'status_label'  => 'Check-in por autogestión',
                'checkin_type'  => 'presencial',
                'modalidad'     => function_exists('eventosapp_get_ticket_modalidad') ? eventosapp_get_ticket_modalidad( $ticket_id ) : get_post_meta( $ticket_id, '_eventosapp_ticket_modalidad', true ),
                'usuario'       => eventosapp_self_checkin_current_user_label(),
                'origen'        => 'self-checkin',
                'qr_type'       => 'self_checkin',
                'qr_type_label' => 'Autogestión',
            ];
            update_post_meta( $ticket_id, '_eventosapp_checkin_log', $log );

            if ( function_exists('eventosapp_update_qr_usage_stats') ) {
                eventosapp_update_qr_usage_stats( $event_id, 'self_checkin' );
            }
        }

        return [
            'event_id' => $event_id,
            'today'    => $today,
            'already'  => $already,
        ];
    }
}

add_shortcode('eventosapp_self_checkin', function( $atts ) {
    if ( function_exists('eventosapp_require_feature') ) {
        eventosapp_require_feature('self_checkin');
    }

    $atts = shortcode_atts([
        'event_id' => 0,
    ], $atts, 'eventosapp_self_checkin');

    $event_id = eventosapp_self_checkin_get_active_event_id( $atts['event_id'] );

    if ( ! $event_id ) {
        $dashboard_url = function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/');
        return '<div class="evsc-alert evsc-alert-warn">Debes escoger un <strong>evento</strong> para activar la autogestión. Ve al <a href="'.esc_url($dashboard_url).'">dashboard</a>, selecciónalo y vuelve aquí.</div>';
    }

    if ( ! eventosapp_self_checkin_user_can_event( $event_id ) ) {
        return '<div class="evsc-alert evsc-alert-error">No tienes permisos para usar la autogestión en este evento.</div>';
    }

    wp_enqueue_script('jquery');
    wp_register_script('eventosapp-self-checkin', '', ['jquery'], null, true);
    wp_localize_script('eventosapp-self-checkin', 'EvSelfCheckin', [
        'ajax_url'       => admin_url('admin-ajax.php'),
        'search_nonce'   => wp_create_nonce('eventosapp_self_checkin_search'),
        'confirm_nonce'  => wp_create_nonce('eventosapp_self_checkin_confirm'),
        'print_nonce'    => wp_create_nonce('eventosapp_self_checkin_print'),
        'badge_nonce'    => wp_create_nonce('eventosapp_self_checkin_badge'),
        'event_id'       => $event_id,
        'event_name'     => get_the_title( $event_id ),
        'messages'       => [
            'cc_required' => 'Ingresa una cédula válida de 5 a 12 números.',
            'searching'   => 'Buscando asistente…',
            'no_results'  => 'No se encontró ningún asistente con esa cédula en este evento.',
            'select_one'  => 'Selecciona un resultado para continuar.',
            'confirming'  => 'Confirmando información…',
            'printing'    => 'Marcando check-in e imprimiendo escarapela…',
            'net_error'   => 'Error de conexión. Intenta nuevamente.',
        ],
    ]);

    $css = <<<'CSS'
.evsc-wrap{max-width:1100px;margin:0 auto;padding:18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:24px;box-shadow:0 12px 34px rgba(15,23,42,.08);font-family:Arial,Helvetica,sans-serif;color:#0f172a}
.evsc-kicker{margin:0 0 6px;color:#2563eb;font-size:18px;font-weight:800;letter-spacing:.03em;text-transform:uppercase}
.evsc-title{margin:0;color:#0f172a;font-size:36px;line-height:1.05;font-weight:900}
.evsc-subtitle{margin:8px 0 20px;color:#475569;font-size:18px;line-height:1.35}
.evsc-event{display:inline-flex;align-items:center;gap:8px;margin-bottom:18px;padding:10px 14px;border-radius:999px;background:#e0f2fe;color:#075985;font-size:17px;font-weight:800}
.evsc-panel{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:20px;box-shadow:0 8px 22px rgba(15,23,42,.06)}
.evsc-search{display:grid;grid-template-columns:1fr 220px;gap:14px;align-items:end}
.evsc-field label{display:block;margin:0 0 8px;color:#334155;font-size:18px;font-weight:800}
.evsc-input{width:100%;box-sizing:border-box;border:2px solid #cbd5e1;border-radius:18px;background:#fff;color:#0f172a;font-size:34px;font-weight:900;letter-spacing:.04em;padding:18px 20px;min-height:82px;outline:none;box-shadow:inset 0 2px 4px rgba(15,23,42,.04)}
.evsc-input:focus{border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.14)}
.evsc-btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-height:70px;border:0;border-radius:18px;padding:18px 26px;font-size:22px;font-weight:900;line-height:1.1;text-decoration:none;cursor:pointer;transition:transform .12s ease,filter .15s ease,background-color .15s ease,opacity .15s ease;touch-action:manipulation}
.evsc-btn:hover{filter:brightness(.96);transform:translateY(-1px)}
.evsc-btn:active{transform:translateY(0)}
.evsc-btn[disabled]{opacity:.45;cursor:not-allowed;transform:none;filter:none}
.evsc-btn-primary{background:#2563eb;color:#fff}
.evsc-btn-success{background:#16a34a;color:#fff}
.evsc-btn-dark{background:#0f172a;color:#fff}
.evsc-btn-light{background:#e2e8f0;color:#0f172a}
.evsc-actions{display:flex;gap:14px;align-items:center;justify-content:flex-end;flex-wrap:wrap;margin-top:18px}
.evsc-status{margin-top:14px;padding:14px 16px;border-radius:16px;background:#f1f5f9;color:#334155;font-size:18px;font-weight:700;display:none}
.evsc-status.is-visible{display:block}
.evsc-status.ok{background:#dcfce7;color:#166534}
.evsc-status.err{background:#fee2e2;color:#991b1b}
.evsc-results{margin-top:18px;display:grid;gap:12px}
.evsc-result{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:center;background:#fff;border:2px solid #e2e8f0;border-radius:20px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.05);cursor:pointer}
.evsc-result:hover{border-color:#93c5fd}
.evsc-result.is-selected{border-color:#2563eb;background:#eff6ff;box-shadow:0 0 0 4px rgba(37,99,235,.12)}
.evsc-result-name{font-size:26px;line-height:1.1;font-weight:900;color:#0f172a}
.evsc-result-meta{margin-top:8px;color:#475569;font-size:17px;line-height:1.35}
.evsc-chip{display:inline-flex;align-items:center;border-radius:999px;padding:6px 10px;font-size:14px;font-weight:900;background:#e2e8f0;color:#334155;margin:3px 6px 3px 0}
.evsc-chip-ok{background:#dcfce7;color:#166534}
.evsc-chip-warn{background:#fef3c7;color:#92400e}
.evsc-confirm{display:none;margin-top:20px;background:#0f172a;color:#fff;border-radius:24px;padding:22px;box-shadow:0 14px 32px rgba(15,23,42,.22)}
.evsc-confirm.is-visible{display:block}
.evsc-confirm h3{margin:0 0 12px;color:#fff;font-size:32px;line-height:1.1;font-weight:900}
.evsc-confirm-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:16px}
.evsc-data{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.16);border-radius:18px;padding:14px}
.evsc-data-label{display:block;color:#bfdbfe;font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.evsc-data-value{display:block;color:#fff;font-size:22px;font-weight:900;word-break:break-word}
.evsc-print-frame{position:fixed;left:-10000px;top:0;width:1px;height:1px;border:0;opacity:0;pointer-events:none}
.evsc-alert{max-width:900px;margin:16px auto;padding:14px 16px;border-radius:14px;font-size:17px;font-weight:700}
.evsc-alert-warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}
.evsc-alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
@media(max-width:800px){.evsc-wrap{padding:14px;border-radius:18px}.evsc-title{font-size:30px}.evsc-search{grid-template-columns:1fr}.evsc-input{font-size:28px;min-height:74px}.evsc-btn{width:100%;min-height:68px}.evsc-result{grid-template-columns:1fr}.evsc-actions{justify-content:stretch}.evsc-confirm-grid{grid-template-columns:1fr}.evsc-result-name{font-size:23px}}
CSS;

    wp_register_style('eventosapp-self-checkin', false, [], null);
    wp_add_inline_style('eventosapp-self-checkin', $css);
    wp_enqueue_style('eventosapp-self-checkin');

    $js = <<<'JS'
jQuery(function($){
  var $root = $('#evsc-wrap');
  if(!$root.length) return;

  var $input = $('#evsc-cc');
  var $searchBtn = $('#evsc-search-btn');
  var $confirmBtn = $('#evsc-confirm-btn');
  var $clearBtn = $('#evsc-clear-btn');
  var $results = $('#evsc-results');
  var $status = $('#evsc-status');
  var $confirm = $('#evsc-confirm');
  var $confirmData = $('#evsc-confirm-data');
  var $printBtn = $('#evsc-print-btn');
  var selectedTicket = null;
  var confirmedTicket = null;
  var currentRows = [];

  function escHtml(value){
    if(value === null || typeof value === 'undefined') return '';
    return $('<div>').text(String(value)).html();
  }

  function showStatus(message, type){
    $status.removeClass('ok err is-visible');
    if(!message){ $status.hide().text(''); return; }
    $status.addClass('is-visible').addClass(type === 'ok' ? 'ok' : (type === 'err' ? 'err' : '')).text(message).show();
  }

  function normalizeCc(){
    var value = ($input.val() || '').replace(/\D+/g, '').slice(0, 12);
    $input.val(value);
    return value;
  }

  function validCc(){
    var cc = normalizeCc();
    return cc.length >= 5 && cc.length <= 12;
  }

  function resetSelection(keepResults){
    selectedTicket = null;
    confirmedTicket = null;
    $confirmBtn.prop('disabled', true);
    $printBtn.prop('disabled', true);
    $confirm.removeClass('is-visible');
    $confirmData.empty();
    if(!keepResults){
      currentRows = [];
      $results.empty();
    }
  }

  function chip(text, cls){
    return '<span class="evsc-chip '+(cls||'')+'">'+escHtml(text)+'</span>';
  }

  function renderResults(rows){
    resetSelection(true);
    currentRows = $.isArray(rows) ? rows : [];

    if(!currentRows.length){
      $results.html('<div class="evsc-status is-visible err">'+escHtml(EvSelfCheckin.messages.no_results)+'</div>');
      return;
    }

    var html = '';
    $.each(currentRows, function(i, it){
      var checked = it.already_checked === true || it.today_status === 'checked_in';
      html += '<button type="button" class="evsc-result" data-ticket-id="'+escHtml(it.ticket_id)+'">'
           +    '<span>'
           +      '<span class="evsc-result-name">'+escHtml(it.full_name || 'Asistente sin nombre')+'</span>'
           +      '<span class="evsc-result-meta">'
           +        'Cédula: <strong>'+escHtml(it.cc || '—')+'</strong><br>'
           +        'Localidad: <strong>'+escHtml(it.localidad || '—')+'</strong> · Ticket: <strong>'+escHtml(it.ticket_pub || '—')+'</strong><br>'
           +        'Empresa: <strong>'+escHtml(it.company || '—')+'</strong>'
           +      '</span>'
           +      '<span style="display:block;margin-top:8px;">'
           +        chip(it.modalidad_label || 'Presencial', '')
           +        (checked ? chip('Check-in ya registrado', 'evsc-chip-ok') : chip('Pendiente de check-in', 'evsc-chip-warn'))
           +      '</span>'
           +    '</span>'
           +    '<span class="evsc-btn evsc-btn-light">Seleccionar</span>'
           +  '</button>';
    });
    $results.html(html);
  }

  function findRow(ticketId){
    ticketId = parseInt(ticketId, 10);
    for(var i=0; i<currentRows.length; i++){
      if(parseInt(currentRows[i].ticket_id, 10) === ticketId) return currentRows[i];
    }
    return null;
  }

  function renderConfirm(it){
    confirmedTicket = it;
    $confirmData.html(
      '<div class="evsc-data"><span class="evsc-data-label">Nombre</span><span class="evsc-data-value">'+escHtml(it.full_name || '—')+'</span></div>'+
      '<div class="evsc-data"><span class="evsc-data-label">Cédula</span><span class="evsc-data-value">'+escHtml(it.cc || '—')+'</span></div>'+
      '<div class="evsc-data"><span class="evsc-data-label">Localidad</span><span class="evsc-data-value">'+escHtml(it.localidad || '—')+'</span></div>'+
      '<div class="evsc-data"><span class="evsc-data-label">Empresa</span><span class="evsc-data-value">'+escHtml(it.company || '—')+'</span></div>'+
      '<div class="evsc-data"><span class="evsc-data-label">Cargo</span><span class="evsc-data-value">'+escHtml(it.designation || '—')+'</span></div>'+
      '<div class="evsc-data"><span class="evsc-data-label">Ticket</span><span class="evsc-data-value">'+escHtml(it.ticket_pub || '—')+'</span></div>'+
      '<div class="evsc-data"><span class="evsc-data-label">Evento</span><span class="evsc-data-value">'+escHtml(it.event_name || EvSelfCheckin.event_name || '—')+'</span></div>'+
      '<div class="evsc-data"><span class="evsc-data-label">Estado</span><span class="evsc-data-value">'+(it.already_checked ? 'Check-in registrado' : 'Listo para imprimir')+'</span></div>'
    );
    $confirm.addClass('is-visible');
    $printBtn.prop('disabled', false);
    $('html, body').animate({scrollTop: $confirm.offset().top - 20}, 220);
  }

  $input.on('input', function(){
    normalizeCc();
    resetSelection(false);
    showStatus('', '');
  });

  $input.on('keydown', function(e){
    if(e.key === 'Enter'){
      e.preventDefault();
      $searchBtn.trigger('click');
    }
  });

  $searchBtn.on('click', function(){
    if(!validCc()){
      showStatus(EvSelfCheckin.messages.cc_required, 'err');
      resetSelection(false);
      return;
    }

    $searchBtn.prop('disabled', true).text('Buscando…');
    showStatus(EvSelfCheckin.messages.searching, '');
    resetSelection(false);

    $.post(EvSelfCheckin.ajax_url, {
      action: 'eventosapp_self_checkin_search',
      security: EvSelfCheckin.search_nonce,
      event_id: EvSelfCheckin.event_id,
      cedula: normalizeCc()
    }, function(resp){
      if(resp && resp.success){
        showStatus('', '');
        renderResults(resp.data && resp.data.results ? resp.data.results : []);
      } else {
        var msg = resp && resp.data && resp.data.message ? resp.data.message : EvSelfCheckin.messages.no_results;
        showStatus(msg, 'err');
      }
    }, 'json').fail(function(){
      showStatus(EvSelfCheckin.messages.net_error, 'err');
    }).always(function(){
      $searchBtn.prop('disabled', false).text('Buscar');
    });
  });

  $(document).on('click', '.evsc-result', function(){
    var ticketId = $(this).data('ticket-id');
    selectedTicket = findRow(ticketId);
    $('.evsc-result').removeClass('is-selected');
    $(this).addClass('is-selected');
    $confirmBtn.prop('disabled', !selectedTicket);
    $confirm.removeClass('is-visible');
    $printBtn.prop('disabled', true);
    showStatus(selectedTicket ? 'Resultado seleccionado. Presiona Confirmar para revisar los datos.' : EvSelfCheckin.messages.select_one, selectedTicket ? 'ok' : 'err');
  });

  $confirmBtn.on('click', function(){
    if(!selectedTicket){
      showStatus(EvSelfCheckin.messages.select_one, 'err');
      return;
    }

    $confirmBtn.prop('disabled', true).text('Confirmando…');
    showStatus(EvSelfCheckin.messages.confirming, '');

    $.post(EvSelfCheckin.ajax_url, {
      action: 'eventosapp_self_checkin_confirm',
      security: EvSelfCheckin.confirm_nonce,
      event_id: EvSelfCheckin.event_id,
      ticket_id: selectedTicket.ticket_id
    }, function(resp){
      if(resp && resp.success && resp.data && resp.data.ticket){
        showStatus('Datos confirmados. Ya puedes imprimir la escarapela.', 'ok');
        renderConfirm(resp.data.ticket);
      } else {
        var msg = resp && resp.data && resp.data.message ? resp.data.message : 'No fue posible confirmar el asistente.';
        showStatus(msg, 'err');
      }
    }, 'json').fail(function(){
      showStatus(EvSelfCheckin.messages.net_error, 'err');
    }).always(function(){
      $confirmBtn.prop('disabled', !selectedTicket).text('Confirmar');
    });
  });

  $printBtn.on('click', function(){
    if(!confirmedTicket){
      showStatus('Primero confirma la información del asistente.', 'err');
      return;
    }

    $printBtn.prop('disabled', true).text('Imprimiendo…');
    showStatus(EvSelfCheckin.messages.printing, '');

    $.post(EvSelfCheckin.ajax_url, {
      action: 'eventosapp_self_checkin_print',
      security: EvSelfCheckin.print_nonce,
      event_id: EvSelfCheckin.event_id,
      ticket_id: confirmedTicket.ticket_id
    }, function(resp){
      if(resp && resp.success && resp.data && resp.data.print_url){
        showStatus(resp.data.already ? 'El check-in ya estaba registrado. Se enviará la escarapela a impresión.' : 'Check-in registrado. Se enviará la escarapela a impresión.', 'ok');
        var $frame = $('<iframe class="evsc-print-frame" aria-hidden="true"></iframe>');
        $('body').append($frame);
        $frame.attr('src', resp.data.print_url);
        setTimeout(function(){ $frame.remove(); }, 20000);
        confirmedTicket.already_checked = true;
      } else {
        var msg = resp && resp.data && resp.data.message ? resp.data.message : 'No fue posible imprimir la escarapela.';
        showStatus(msg, 'err');
      }
    }, 'json').fail(function(xhr){
      var msg = EvSelfCheckin.messages.net_error;
      try { if(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){ msg = xhr.responseJSON.data.message; } } catch(e){}
      showStatus(msg, 'err');
    }).always(function(){
      $printBtn.prop('disabled', false).text('Imprimir escarapela');
    });
  });

  $clearBtn.on('click', function(){
    $input.val('').focus();
    resetSelection(false);
    showStatus('', '');
  });
});
JS;

    wp_add_inline_script('eventosapp-self-checkin', $js);
    wp_enqueue_script('eventosapp-self-checkin');

    ob_start();
    if ( function_exists('eventosapp_active_event_bar') ) {
        eventosapp_active_event_bar();
    }
    ?>
    <div id="evsc-wrap" class="evsc-wrap" data-event-id="<?php echo esc_attr( $event_id ); ?>">
        <div class="evsc-event">Evento activo: <?php echo esc_html( get_the_title( $event_id ) ); ?></div>
        <p class="evsc-kicker">Autogestión</p>
        <h2 class="evsc-title">Identificación del asistente</h2>
        <p class="evsc-subtitle">Ingresa la cédula, selecciona el resultado correcto, confirma la información e imprime la escarapela.</p>

        <div class="evsc-panel">
            <div class="evsc-search">
                <div class="evsc-field">
                    <label for="evsc-cc">Cédula de ciudadanía</label>
                    <input id="evsc-cc" class="evsc-input" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="12" autocomplete="off" placeholder="Ej: 1234567890" aria-label="Cédula de ciudadanía">
                </div>
                <button id="evsc-search-btn" type="button" class="evsc-btn evsc-btn-primary">Buscar</button>
            </div>

            <div id="evsc-status" class="evsc-status" role="status" aria-live="polite"></div>
            <div id="evsc-results" class="evsc-results" aria-live="polite"></div>

            <div class="evsc-actions">
                <button id="evsc-clear-btn" type="button" class="evsc-btn evsc-btn-light">Limpiar</button>
                <button id="evsc-confirm-btn" type="button" class="evsc-btn evsc-btn-success" disabled>Confirmar</button>
            </div>
        </div>

        <div id="evsc-confirm" class="evsc-confirm" aria-live="polite">
            <h3>Confirma tu información</h3>
            <div id="evsc-confirm-data" class="evsc-confirm-grid"></div>
            <div class="evsc-actions">
                <button id="evsc-print-btn" type="button" class="evsc-btn evsc-btn-success" disabled>Imprimir escarapela</button>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
});

add_action('wp_ajax_eventosapp_self_checkin_search', function() {
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('self_checkin') ) {
        wp_send_json_error(['message' => 'Permisos insuficientes.'], 403);
    }

    check_ajax_referer('eventosapp_self_checkin_search', 'security');

    $event_id = eventosapp_self_checkin_get_active_event_id( $_POST['event_id'] ?? 0 );
    if ( ! $event_id || ! eventosapp_self_checkin_user_can_event( $event_id ) ) {
        wp_send_json_error(['message' => 'No tienes permisos para este evento.'], 403);
    }

    $cedula = eventosapp_self_checkin_validate_cc( $_POST['cedula'] ?? '' );
    if ( is_wp_error( $cedula ) ) {
        wp_send_json_error(['message' => $cedula->get_error_message()], 400);
    }

    $ticket_ids = eventosapp_self_checkin_find_tickets_by_cc( $cedula, $event_id, 10 );
    if ( ! empty( $ticket_ids ) ) {
        update_meta_cache('post', $ticket_ids);
    }

    $results = [];
    foreach ( $ticket_ids as $ticket_id ) {
        $payload = eventosapp_self_checkin_ticket_payload( $ticket_id );
        if ( ! $payload || absint( $payload['event_id'] ) !== absint( $event_id ) ) {
            continue;
        }
        $results[] = $payload;
    }

    wp_send_json_success([
        'results' => $results,
    ]);
});

add_action('wp_ajax_eventosapp_self_checkin_confirm', function() {
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('self_checkin') ) {
        wp_send_json_error(['message' => 'Permisos insuficientes.'], 403);
    }

    check_ajax_referer('eventosapp_self_checkin_confirm', 'security');

    $ticket_id = absint( $_POST['ticket_id'] ?? 0 );
    $event_id  = eventosapp_self_checkin_get_active_event_id( $_POST['event_id'] ?? 0 );

    if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
        wp_send_json_error(['message' => 'Ticket inválido.'], 400);
    }

    $ticket_event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    if ( ! $event_id || $ticket_event_id !== absint( $event_id ) ) {
        wp_send_json_error(['message' => 'El ticket no corresponde al evento activo.'], 403);
    }

    if ( ! eventosapp_self_checkin_user_can_event( $event_id ) ) {
        wp_send_json_error(['message' => 'No tienes permisos para este evento.'], 403);
    }

    $payload = eventosapp_self_checkin_ticket_payload( $ticket_id );
    if ( ! $payload ) {
        wp_send_json_error(['message' => 'No fue posible leer la información del asistente.'], 400);
    }

    wp_send_json_success([
        'ticket' => $payload,
    ]);
});

add_action('wp_ajax_eventosapp_self_checkin_print', function() {
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('self_checkin') ) {
        wp_send_json_error(['message' => 'Permisos insuficientes.'], 403);
    }

    check_ajax_referer('eventosapp_self_checkin_print', 'security');

    $ticket_id = absint( $_POST['ticket_id'] ?? 0 );
    $event_id  = eventosapp_self_checkin_get_active_event_id( $_POST['event_id'] ?? 0 );

    if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
        wp_send_json_error(['message' => 'Ticket inválido.'], 400);
    }

    $ticket_event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    if ( ! $event_id || $ticket_event_id !== absint( $event_id ) ) {
        wp_send_json_error(['message' => 'El ticket no corresponde al evento activo.'], 403);
    }

    $marked = eventosapp_self_checkin_mark_ticket( $ticket_id );
    if ( is_wp_error( $marked ) ) {
        $code = $marked->get_error_code() === 'invalid_day' ? 400 : 403;
        wp_send_json_error(['message' => $marked->get_error_message()], $code);
    }

    $print_url = add_query_arg([
        'action'    => 'eventosapp_self_checkin_badge',
        'nonce'     => wp_create_nonce('eventosapp_self_checkin_badge_' . $ticket_id),
        'ticket_id' => $ticket_id,
        'event_id'  => $ticket_event_id,
    ], admin_url('admin-ajax.php'));

    wp_send_json_success([
        'message'   => 'Check-in registrado. Imprimiendo escarapela.',
        'print_url' => esc_url_raw( $print_url ),
        'already'   => ! empty( $marked['already'] ),
        'ticket'    => eventosapp_self_checkin_ticket_payload( $ticket_id ),
    ]);
});

add_action('wp_ajax_eventosapp_self_checkin_badge', function() {
    $ticket_id = absint( $_GET['ticket_id'] ?? 0 );
    $event_id  = absint( $_GET['event_id'] ?? 0 );
    $nonce     = sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) );

    if ( ! $ticket_id || ! wp_verify_nonce( $nonce, 'eventosapp_self_checkin_badge_' . $ticket_id ) ) {
        wp_die('Nonce inválido.', '', 403);
    }

    if ( ! $ticket_id || get_post_type( $ticket_id ) !== 'eventosapp_ticket' ) {
        wp_die('Ticket inválido.', '', 400);
    }

    $ticket_event_id = absint( get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true ) );
    if ( ! $event_id || $event_id !== $ticket_event_id ) {
        $event_id = $ticket_event_id;
    }

    if ( ! eventosapp_self_checkin_user_can_event( $event_id ) ) {
        wp_die('Sin permisos.', '', 403);
    }

    if ( ! function_exists('eventosapp_get_badge_html_from_event') ) {
        wp_die('La función de escarapela no está disponible.', '', 500);
    }

    echo eventosapp_get_badge_html_from_event( $event_id, $ticket_id );
    exit;
});
