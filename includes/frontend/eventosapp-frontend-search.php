<?php
/**
 * Frontend: buscador y gestión rápida de tickets
 * - Shortcode [eventosapp_front_search event_id="123"]
 * - Se integra con el "evento activo" elegido en el dashboard:
 *     - Usa eventosapp_get_active_event() si no se pasa event_id en el shortcode
 *     - Muestra barra superior eventosapp_active_event_bar() si existe
 * - AJAX search + toggle checkin + render de escarapela (basada en metas del eventosapp_event)
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Helpers de fecha (TZ del evento)
 */
if ( ! function_exists('eventosapp_get_today_in_event_tz') ) {
    function eventosapp_get_today_in_event_tz( $event_id ) {
        // 1) TZ del evento o del sitio
        $event_tz = get_post_meta($event_id, '_eventosapp_zona_horaria', true);
        if ( ! $event_tz ) {
            $event_tz = wp_timezone_string();
            if ( ! $event_tz || $event_tz === 'UTC' ) {
                $offset = get_option('gmt_offset');
                $event_tz = $offset ? timezone_name_from_abbr('', $offset * 3600, 0) ?: 'UTC' : 'UTC';
            }
        }
        // 2) "Hoy" en esa TZ
        try {
            $dt = new DateTime('now', new DateTimeZone($event_tz));
        } catch (Exception $e) {
            $dt = new DateTime('now', wp_timezone());
        }
        return $dt->format('Y-m-d');
    }
}
if ( ! function_exists('eventosapp_is_today_valid_for_event') ) {
    function eventosapp_is_today_valid_for_event( $event_id ) {
        $today = eventosapp_get_today_in_event_tz($event_id);
        $days  = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days($event_id) : [];
        return (!empty($days) && in_array($today, $days, true));
    }
}

/**
 * Shortcode contenedor
 * Uso: [eventosapp_front_search event_id="123"]
 */
add_shortcode('eventosapp_front_search', function($atts){
    if ( function_exists('eventosapp_require_feature') ) eventosapp_require_feature('search');

    $a   = shortcode_atts(['event_id'=>0], $atts);
    $eid = absint($a['event_id']);

    // 👉 Si no vino por shortcode, usa el evento activo del dashboard (si existe)
    if ( ! $eid && function_exists('eventosapp_get_active_event') ) {
        $eid = (int) eventosapp_get_active_event();
    }

    // 👉 Si sigue sin haber evento, manda al dashboard o muestra aviso
    if ( ! $eid ) {
        $dashboard_url = function_exists('eventosapp_get_dashboard_url')
            ? eventosapp_get_dashboard_url()
            : home_url('/dashboard/');
        if ( function_exists('eventosapp_require_active_event') ) {
            eventosapp_require_active_event();
            return '';
        }
        return '<div style="padding:.8rem;border:1px solid #eee;background:#fffdf2;border-radius:8px;color:#8a6d3b;">
            Debes escoger un <strong>evento</strong> para gestionar. Ve al <a href="'.esc_url($dashboard_url).'">dashboard</a>, seleccionalo y vuelve aquí.
        </div>';
    }

    // Validar permisos sobre el evento (si no es admin)
    if ( $eid && ! eventosapp_user_can_manage_event($eid) && ! current_user_can('manage_options') ) {
        return '<div style="padding:.8rem;border:1px solid #eee;background:#fff8f8;border-radius:8px;color:#a33;">
            No tienes permisos sobre este evento.
        </div>';
    }

// Enqueue JS + CSS (estilo WP)
wp_enqueue_script('jquery');
wp_register_script('eventosapp-front-search', '', ['jquery'], null, true);

// Variables para el JS
wp_localize_script('eventosapp-front-search', 'EvFrontSearch', [
    'ajax_url'           => admin_url('admin-ajax.php'),
    'search_nonce'       => wp_create_nonce('eventosapp_front_search'),
    'toggle_nonce'       => wp_create_nonce('eventosapp_toggle_checkin'),
    'print_nonce'        => wp_create_nonce('eventosapp_render_badge'),
    'acompanantes_nonce' => wp_create_nonce('eventosapp_registrar_acompanantes'),
    'event_id'           => $eid,
    'msgs'               => [
        'not_allowed' => __('El check-in solo está permitido en las fechas del evento. Hoy no corresponde.', 'eventosapp'),
        'net_error'   => __('Error de red. Intenta de nuevo.', 'eventosapp')
    ]
]);

// CSS como inline style
$css = <<<CSS
.evfs-wrap{max-width:900px;margin:0 auto}
.evfs-searchbar{display:grid;grid-template-columns:minmax(185px,240px) 1fr;gap:8px;align-items:center}
.evfs-select{width:100%;padding:.6rem .7rem;font-size:1rem;border:1px solid #dfe3e7;border-radius:10px;background:#fff;color:#111}
.evfs-input{width:100%;padding:.6rem .7rem;font-size:1.05rem;border:1px solid #dfe3e7;border-radius:10px}
.evfs-results{margin-top:.6rem}
.evfs-row{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;padding:.8rem;border:1px solid #eee;border-radius:12px;background:#fff;margin-bottom:8px;box-shadow:0 1px 5px rgba(120,140,160,.07)}
.evfs-data{flex:1 1 60%;min-width:0;word-break:break-word}
.evfs-actions{flex:0 0 230px;display:flex;flex-direction:column;align-items:flex-end;gap:8px;position:relative}
.evfs-btn{display:inline-block;border-radius:8px;border:0;font-size:1rem;font-weight:600;cursor:pointer;padding:.6rem 1rem;box-shadow:0 1px 4px rgba(30,60,100,.07)}
.evfs-check{background:#3782C4;color:#fff}
.evfs-check:hover{background:#205483}
.evfs-check[aria-checked="true"]{background:#20FF00;color:#000}
.evfs-print{border:2px solid #3782C4;background:#fff;color:#3782C4}
.evfs-print:hover{background:#205483;color:#fff}
.evfs-virtual{background:#7c3aed;color:#fff;text-align:center;text-decoration:none}
.evfs-virtual:hover{background:#5b21b6;color:#fff}
.evfs-virtual-badge{background:#7c3aed;color:#fff;text-align:center;cursor:default}
.evfs-virtual-badge.is-checked{background:#6d28d9;color:#fff}
.evfs-virtual-badge small{display:block;font-size:.75rem;font-weight:500;opacity:.92;line-height:1.15;margin-top:2px}
.evfs-muted-action{background:#e5e7eb;color:#374151;cursor:not-allowed}
.evfs-note{position:absolute;bottom:-6px;right:0;transform:translateY(100%);font-size:.9rem;padding:.35rem .55rem;border-radius:6px;background:#ffe9e9;color:#9a2424;border:1px solid #ffd3d3}
.evfs-note.ok{background:#e9ffe9;color:#1e6f2b;border-color:#c9f2c9}
.evfs-check[disabled]{opacity:.55;cursor:not-allowed;filter:grayscale(20%)}
.evfs-acomp-panel{margin-top:8px;background:#eaf3ff;border:1px solid #b3d4f5;border-radius:10px;padding:10px 12px;width:100%}
.evfs-acomp-label{font-size:.85rem;font-weight:600;color:#1e4a80;margin-bottom:6px}
.evfs-acomp-row{display:flex;gap:6px;align-items:center}
.evfs-acomp-input{flex:0 0 70px;border:1px solid #b3d4f5;border-radius:6px;padding:.35rem .5rem;font-size:1rem;font-weight:700;text-align:center;-moz-appearance:textfield}
.evfs-acomp-input::-webkit-inner-spin-button,.evfs-acomp-input::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}
.evfs-acomp-btn{flex:1;background:#3782C4;color:#fff;border:0;border-radius:6px;padding:.35rem .7rem;font-size:.85rem;font-weight:700;cursor:pointer;transition:filter .15s}
.evfs-acomp-btn:hover:not(:disabled){filter:brightness(1.1)}
.evfs-acomp-btn:disabled{opacity:.55;cursor:not-allowed}
.evfs-acomp-status{margin-top:5px;font-size:.82rem}
@media(max-width:600px){
  .evfs-searchbar{grid-template-columns:1fr}
  .evfs-row{flex-direction:column}
  .evfs-actions{flex-direction:row;align-items:stretch;width:100%;justify-content:stretch}
  .evfs-btn{width:100%}
}
CSS;

wp_register_style('eventosapp-front-search', false, [], null);
wp_add_inline_style('eventosapp-front-search', $css);
wp_enqueue_style('eventosapp-front-search');

// JS (sin inyectar CSS desde JS) — versión NOWDOC para evitar interpolación de $ en PHP
$js = <<<'JS'
jQuery(function($){
  var $w = $('#evfs-wrap'),
      $type = $('#evfs-search-type'),
      $in = $('#evfs-input'),
      $out= $('#evfs-results'),
      eventId = EvFrontSearch.event_id,
      timer,
      pendingSearch = null;

  function escHtml(value){
    if(value === null || typeof value === 'undefined') return '';
    return $('<div>').text(String(value)).html();
  }

  function escAttr(value){
    return escHtml(value).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function searchPlaceholder(type){
    var labels = {
      name: 'Buscar por nombres y apellidos…',
      cc: 'Buscar por cédula…',
      phone: 'Buscar por celular…',
      email: 'Buscar por correo electrónico…',
      all: 'Buscar en todos los datos…'
    };
    return labels[type] || labels.cc;
  }

  function getSearchType(){
    return ($type.val() || 'cc').toString();
  }

  function updateSearchPlaceholder(){
    $in.attr('placeholder', searchPlaceholder(getSearchType()));
  }


  function btnCheck(status, ticketId, allowed){
    var isChecked      = (status === 'checked_in');
    var showAsChecked  = (allowed !== false) && isChecked;
    var txt            = showAsChecked ? '✓ Checked In' : 'Check In';
    var disabledAttr   = (allowed === false) ? ' disabled title="Hoy no es un día de check-in"' : '';
    var ariaAttr       = showAsChecked ? ' aria-checked="true"' : '';

    return '<button class="evfs-btn evfs-check"' + ariaAttr +
           ' data-ticket-id="'+escAttr(ticketId)+'"' + disabledAttr + '>'+ txt +'</button>';
  }

  function btnVirtualBadge(it){
    var checked = it.virtual_today_status === 'checked_in' || it.virtual_checked === true;
    var sub = checked ? '✓ Check-in virtual registrado' : 'Sin check-in virtual';
    return '<div class="evfs-btn evfs-virtual-badge' + (checked ? ' is-checked' : '') + '" role="status" aria-live="polite">'
         + 'Asistente Virtual<small>' + escHtml(sub) + '</small>'
         + '</div>';
  }

  function render(rows){
    if(!rows.length){ $out.html('<div style="padding:.5rem;color:#666;">No hay resultados.</div>'); return; }
    var html='';
    $.each(rows,function(i,it){
      it = it || {};
      var isVirtual = it.is_virtual === true || it.modalidad === 'virtual';
      var actions = (isVirtual ? btnVirtualBadge(it) : '')
        + btnCheck(it.today_status, it.ticket_id, it.today_allowed)
        + '<button class="evfs-btn evfs-print" data-ticket-id="'+escAttr(it.ticket_id)+'" data-event-id="'+escAttr(it.event_id)+'">Imprimir escarapela</button>';
      html += '<div class="evfs-row">'
           +   '<div class="evfs-data">'
           +     '<strong>'+ escHtml((it.first_name||'') +' '+ (it.last_name||'')) +'</strong>'
           +     ' <span style="color:#888">('+escHtml(it.cc||'—')+')</span><br>'
           +     'Email: '+ escHtml(it.email||'—') +'<br>'
           +     'TicketID: '+ escHtml(it.ticket_pub||'—') +'<br>'
           +     'Evento: '+ escHtml(it.event_name||'—') +'<br>'
           +     'Localidad: '+ escHtml(it.localidad||'—') +'<br>'
           +     'Modalidad: '+ escHtml(it.modalidad_label||'Presencial')
           +   '</div>'
           +   '<div class="evfs-actions">'
           +     actions
           +   '</div>'
           + '</div>';
    });
    $out.html(html);
  }

  function showNote($btn, msg, ok){
    var $wrap = $btn.closest('.evfs-actions');
    var $n = $wrap.find('.evfs-note');
    if(!$n.length){ $n = $('<div class="evfs-note" />').appendTo($wrap); }
    $n.text(msg).toggleClass('ok', !!ok).stop(true,true).fadeIn(120);
    setTimeout(function(){ $n.fadeOut(180, function(){ $(this).remove(); }); }, 3000);
  }

  function runSearch(){
    clearTimeout(timer);
    var q = $in.val().trim();
    var searchType = getSearchType();
    var minLen = (searchType === 'all' || searchType === 'name' || searchType === 'email') ? 3 : 2;
    if(!q || q.length < minLen){
      if(pendingSearch && pendingSearch.readyState !== 4){ pendingSearch.abort(); }
      $out.empty();
      return;
    }
    timer = setTimeout(function(){
      if(pendingSearch && pendingSearch.readyState !== 4){ pendingSearch.abort(); }
      pendingSearch = $.getJSON(EvFrontSearch.ajax_url, {
        action: 'eventosapp_front_search',
        security: EvFrontSearch.search_nonce,
        q: q,
        search_type: searchType,
        event_id: eventId
      }).done(function(resp){
        if(resp && resp.success){ render(resp.data||[]); }
        else { render([]); }
      }).fail(function(xhr, status){
        if(status !== 'abort'){ render([]); }
      });
    }, 350);
  }

  $in.on('input', runSearch);

  $type.on('change', function(){
    updateSearchPlaceholder();
    $out.empty();
    runSearch();
  });

  updateSearchPlaceholder();

// Toggle check-in
  $(document).on('click','.evfs-check', function(){
    var $b = $(this), id = $b.data('ticket-id');
    $.post(EvFrontSearch.ajax_url, {
      action: 'eventosapp_front_toggle_checkin',
      security: EvFrontSearch.toggle_nonce,
      ticket_id: id
    }, function(resp){
      if(resp && resp.success){
        var newStatus = resp.data.today_status;
        $b.attr('aria-checked', newStatus === 'checked_in' ? 'true' : 'false')
          .text(newStatus === 'checked_in' ? '✓ Checked In' : 'Check In');

        // Panel de acompañantes: solo al hacer check-in (no al revertir)
        if (newStatus === 'checked_in' && resp.data.acompanantes_enabled && resp.data.ticket_id) {
          var $row = $b.closest('.evfs-row');
          // Eliminar panel previo si existe para no duplicar
          $row.next('.evfs-acomp-wrapper').remove();
          var tid = resp.data.ticket_id;
          var $panel = $(
            '<div class="evfs-acomp-wrapper" style="margin-bottom:8px;">' +
              '<div class="evfs-acomp-panel">' +
                '<div class="evfs-acomp-label">🧑‍🤝‍🧑 Acompañantes sin QR</div>' +
                '<div class="evfs-acomp-row">' +
                  '<input type="number" class="evfs-acomp-input" min="0" max="500" step="1" value="0">' +
                  '<button type="button" class="evfs-acomp-btn" data-ticket-id="' + tid + '">Registrar</button>' +
                '</div>' +
                '<div class="evfs-acomp-status"></div>' +
              '</div>' +
            '</div>'
          );
          $row.after($panel);
        } else if (newStatus !== 'checked_in') {
          // Si se revierte el check-in, ocultar el panel
          $b.closest('.evfs-row').next('.evfs-acomp-wrapper').remove();
        }
      } else {
        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : EvFrontSearch.msgs.not_allowed;
        showNote($b, msg, false);
      }
    }, 'json').fail(function(xhr){
      var msg = EvFrontSearch.msgs.net_error;
      try {
        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          msg = xhr.responseJSON.data.message;
        } else if (xhr.responseText) {
          var j = JSON.parse(xhr.responseText);
          if (j && j.data && j.data.message) msg = j.data.message;
        }
      } catch(e){}
      showNote($b, msg, false);
    });
  });

  // Registrar acompañantes sin QR (delegado al documento)
  $(document).on('click', '.evfs-acomp-btn', function(){
    var $btn      = $(this);
    var tid       = $btn.data('ticket-id');
    var $panel    = $btn.closest('.evfs-acomp-panel');
    var $input    = $panel.find('.evfs-acomp-input');
    var $status   = $panel.find('.evfs-acomp-status');
    var cantidad  = parseInt($input.val(), 10);

    if (isNaN(cantidad) || cantidad < 0 || cantidad > 500) {
      $status.html('<span style="color:#9a2424;">❌ Ingresa un número válido (0–500).</span>');
      return;
    }

    $btn.prop('disabled', true).text('Guardando…');
    $status.html('<span style="color:#555;">Registrando…</span>');

    $.post(EvFrontSearch.ajax_url, {
      action:          'eventosapp_registrar_acompanantes',
      companion_nonce: EvFrontSearch.acompanantes_nonce,
      ticket_id:       tid,
      cantidad:        cantidad
    }, function(resp){
      if (resp && resp.success) {
        $status.html('<span style="color:#1e6f2b;">✅ ' + cantidad + ' acompañante(s) registrado(s). Total: ' + (resp.data.total || cantidad) + '</span>');
        $input.val(0);
        $btn.text('✓ Guardado');
        setTimeout(function(){ $btn.prop('disabled', false).text('Registrar'); }, 3000);
      } else {
        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Error al guardar.';
        $status.html('<span style="color:#9a2424;">❌ ' + msg + '</span>');
        $btn.prop('disabled', false).text('Reintentar');
      }
    }, 'json').fail(function(){
      $status.html('<span style="color:#9a2424;">❌ Error de conexión.</span>');
      $btn.prop('disabled', false).text('Reintentar');
    });
  });

  // Imprimir escarapela
  $(document).on('click','.evfs-print', function(){
    var tid = $(this).data('ticket-id'),
        eid = $(this).data('event-id');
    var url = EvFrontSearch.ajax_url
            + '?action=eventosapp_render_badge'
            + '&nonce=' + encodeURIComponent(EvFrontSearch.print_nonce)
            + '&ticket_id=' + encodeURIComponent(tid)
            + '&event_id=' + encodeURIComponent(eid);
    window.open(url,'_blank');
  });
});
JS;


wp_add_inline_script('eventosapp-front-search', $js);
wp_enqueue_script('eventosapp-front-search');


    // HTML contenedor
    ob_start();

    // 👉 Barra superior (si el dashboard la expone)
    if ( function_exists('eventosapp_active_event_bar') ) {
        eventosapp_active_event_bar();
    }
    ?>
    <div id="evfs-wrap" class="evfs-wrap" data-event-id="<?php echo esc_attr($eid); ?>">
        <div class="evfs-searchbar">
            <label class="screen-reader-text" for="evfs-search-type">Tipo de búsqueda</label>
            <select id="evfs-search-type" class="evfs-select" aria-label="Tipo de búsqueda">
                <option value="name">Nombres y apellidos</option>
                <option value="cc" selected>Cédula</option>
                <option value="phone">Celular</option>
                <option value="email">Correo electrónico</option>
                <option value="all">Todos los datos</option>
            </select>
            <input id="evfs-input" class="evfs-input" type="text" placeholder="Buscar por cédula…" autocomplete="off">
        </div>
        <div id="evfs-results" class="evfs-results"></div>
    </div>
    <?php
    return ob_get_clean();
});

/**
 * AJAX: búsqueda segmentada y optimizada.
 *
 * Mejora de rendimiento:
 * - Por defecto busca solo por Cédula (_evapp_search_cc).
 * - Permite segmentar por nombres/apellidos, celular, correo o todos.
 * - "Todos" conserva compatibilidad con el índice amplio _evapp_search_blob,
 *   pero queda como última opción porque es la consulta más costosa.
 * - Si un ticket antiguo todavía no tiene los índices nuevos, usa fallback seguro
 *   sobre los metadatos principales sin modificar permisos ni flujos existentes.
 */
add_action('wp_ajax_eventosapp_front_search', function(){
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('search') ) {
        wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
    }
    check_ajax_referer('eventosapp_front_search','security');

    global $wpdb;

    $q        = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
    $event_id = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;

    $allowed_search_types = [ 'name', 'cc', 'phone', 'email', 'all' ];
    $search_type = isset($_GET['search_type']) ? sanitize_key(wp_unslash($_GET['search_type'])) : 'cc';
    if ( ! in_array($search_type, $allowed_search_types, true) ) {
        $search_type = 'cc';
    }

    if ( ! $event_id && function_exists('eventosapp_get_active_event') ) {
        $event_id = (int) eventosapp_get_active_event();
    }

    if ($q === '') wp_send_json_success([]);

    if ( ! function_exists('eventosapp_normalize_text') ) {
        function eventosapp_normalize_text($s) {
            $s = wp_strip_all_tags( (string) $s );
            $s = remove_accents($s);
            if (function_exists('mb_strtolower')) { $s = mb_strtolower($s, 'UTF-8'); } else { $s = strtolower($s); }
            $s = preg_replace('/\s+/u', ' ', $s);
            return trim($s);
        }
    }

    if ( ! function_exists('eventosapp_search_digits_only') ) {
        function eventosapp_search_digits_only($value) {
            return preg_replace('/\D+/', '', (string) $value);
        }
    }

    $q_norm   = eventosapp_normalize_text($q);
    $q_digits = eventosapp_search_digits_only($q);

    // No admin: forzar a su evento activo.
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || ($event_id && $event_id !== $active) ) {
            wp_send_json_success([]);
        }
        $event_id = $active;
    }

    $allowed_event_ids = [];
    if ( $event_id ) {
        $allowed_event_ids = [ (int) $event_id ];
    } elseif ( ! current_user_can('manage_options') ) {
        $u = wp_get_current_user();
        $mine = get_posts([
            'post_type'   => 'eventosapp_event',
            'post_status' => 'publish',
            'numberposts' => -1,
            'author'      => $u->ID,
            'fields'      => 'ids'
        ]);
        $allowed_event_ids = array_map('intval', $mine);
        if ( empty($allowed_event_ids) ) {
            wp_send_json_success([]);
        }
    }

    $search_meta_map = [
        'name'  => '_evapp_search_name',
        'cc'    => '_evapp_search_cc',
        'phone' => '_evapp_search_phone',
        'email' => '_evapp_search_email',
        'all'   => '_evapp_search_blob',
    ];

    $raw_meta_fallback_map = [
        'cc'    => [ '_eventosapp_asistente_cc' ],
        'phone' => [ '_eventosapp_asistente_tel' ],
        'email' => [ '_eventosapp_asistente_email' ],
        'name'  => [ '_eventosapp_asistente_nombre', '_eventosapp_asistente_apellido' ],
        'all'   => [ '_evapp_search_blob' ],
    ];

    if ( $search_type === 'cc' || $search_type === 'phone' ) {
        $search_value = $q_digits !== '' ? $q_digits : $q_norm;
    } else {
        $search_value = $q_norm;
    }

    $min_len = in_array($search_type, ['all', 'name', 'email'], true) ? 3 : 2;
    if ( $search_value === '' || mb_strlen($search_value) < $min_len ) {
        wp_send_json_success([]);
    }

    $like = '%' . $wpdb->esc_like($search_value) . '%';

    $find_ticket_ids = function( $meta_keys, $like_value, $limit = 30 ) use ( $wpdb, $allowed_event_ids ) {
        $meta_keys = array_values(array_filter(array_map('sanitize_key', (array) $meta_keys)));
        if ( empty($meta_keys) ) return [];

        $join_event = '';
        $params = [];

        if ( ! empty($allowed_event_ids) ) {
            $placeholders = implode(',', array_fill(0, count($allowed_event_ids), '%s'));
            $join_event = " INNER JOIN {$wpdb->postmeta} evm ON evm.post_id = p.ID AND evm.meta_key = %s AND evm.meta_value IN ($placeholders)";
            $params[] = '_eventosapp_ticket_evento_id';
            foreach ( $allowed_event_ids as $aid ) {
                $params[] = (string) absint($aid);
            }
        }

        $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        $sql = "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            {$join_event}
            INNER JOIN {$wpdb->postmeta} sm ON sm.post_id = p.ID
            WHERE p.post_type = %s
              AND p.post_status NOT IN ('trash','auto-draft','inherit')
              AND sm.meta_key IN ($meta_placeholders)
              AND sm.meta_value LIKE %s
            ORDER BY p.ID DESC
            LIMIT %d
        ";

        $params[] = 'eventosapp_ticket';
        foreach ( $meta_keys as $mk ) {
            $params[] = $mk;
        }
        $params[] = $like_value;
        $params[] = (int) $limit;

        $prepared = $wpdb->prepare($sql, $params);
        $ids = $wpdb->get_col($prepared);
        return array_map('intval', (array) $ids);
    };

    $cache_key = 'evfs_ids_' . md5(wp_json_encode([
        'q'      => $search_value,
        'type'   => $search_type,
        'events' => $allowed_event_ids,
    ]));
    $tickets = wp_cache_get($cache_key, 'eventosapp_search');

    if ( ! is_array($tickets) ) {
        // 1) Consulta principal sobre índice segmentado.
        $tickets = $find_ticket_ids([ $search_meta_map[$search_type] ], $like, 30);

        // 2) Fallback para tickets antiguos que aún no hayan sido reindexados con los nuevos metadatos.
    if ( empty($tickets) && isset($raw_meta_fallback_map[$search_type]) ) {
        $fallback_value = ($search_type === 'cc' || $search_type === 'phone') ? ($q_digits !== '' ? $q_digits : $q) : $q_norm;
        $fallback_like  = '%' . $wpdb->esc_like($fallback_value) . '%';
        $tickets = $find_ticket_ids($raw_meta_fallback_map[$search_type], $fallback_like, 30);
    }

    // 3) Último fallback compatible: índice amplio legado, solo si no se pidió "todos" y no hubo resultados.
        if ( empty($tickets) && $search_type !== 'all' ) {
            $tickets = $find_ticket_ids([ '_evapp_search_blob' ], '%' . $wpdb->esc_like($q_norm) . '%', 30);
        }

        wp_cache_set($cache_key, array_map('intval', (array) $tickets), 'eventosapp_search', 30);
    }

    $tickets = array_map('intval', (array) $tickets);
    if ( ! empty($tickets) ) {
        update_meta_cache('post', $tickets);
    }

    $out = [];

    foreach ( $tickets as $tid ) {
        $ev_id   = (int) get_post_meta($tid, '_eventosapp_ticket_evento_id', true);

        if ( $ev_id && ! current_user_can('manage_options') ) {
            if ( ! eventosapp_user_can_manage_event($ev_id) ) continue;
        }

        $fn         = get_post_meta($tid, '_eventosapp_asistente_nombre', true);
        $ln         = get_post_meta($tid, '_eventosapp_asistente_apellido', true);
        $email      = get_post_meta($tid, '_eventosapp_asistente_email', true);
        $cc         = get_post_meta($tid, '_eventosapp_asistente_cc', true);
        $localidad  = get_post_meta($tid, '_eventosapp_asistente_localidad', true);
        $ticketP    = get_post_meta($tid, 'eventosapp_ticketID', true);
        $evname     = $ev_id ? get_the_title($ev_id) : '';
        $modalidad  = function_exists('eventosapp_get_ticket_modalidad') ? eventosapp_get_ticket_modalidad($tid) : (get_post_meta($tid, '_eventosapp_ticket_modalidad', true) ?: 'presencial');
        $modalidad  = in_array($modalidad, ['presencial','virtual'], true) ? $modalidad : 'presencial';
        $is_virtual = ($modalidad === 'virtual');
        $modalidad_label = function_exists('eventosapp_get_ticket_modalidad_label') ? eventosapp_get_ticket_modalidad_label($tid) : ucfirst($modalidad);
        $virtual_url = ($is_virtual && function_exists('eventosapp_get_virtual_landing_url')) ? eventosapp_get_virtual_landing_url($tid) : '';

        // Estado del día actual según TZ del evento
        $today = eventosapp_get_today_in_event_tz($ev_id);
        $status_arr = get_post_meta($tid, '_eventosapp_checkin_status', true);
        if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
        if (!is_array($status_arr)) $status_arr = [];
        $today_status = $status_arr[$today] ?? 'not_checked_in';

        $virtual_status_arr = get_post_meta($tid, '_eventosapp_virtual_checkin_status', true);
        if (is_string($virtual_status_arr)) $virtual_status_arr = @unserialize($virtual_status_arr);
        if (!is_array($virtual_status_arr)) $virtual_status_arr = [];
        $virtual_today_status = isset($virtual_status_arr[$today]) && in_array($virtual_status_arr[$today], ['checked_in','checked-in'], true)
            ? 'checked_in'
            : 'not_checked_in';

        // Si hoy NO es día del evento, ignorar lo guardado para la acción del día actual.
        $today_allowed = eventosapp_is_today_valid_for_event($ev_id);
        if (!$today_allowed) {
            $today_status = 'not_checked_in';
            $virtual_today_status = 'not_checked_in';
        }

        $out[] = [
            'ticket_id'            => $tid,
            'event_id'             => $ev_id,
            'event_name'           => $evname,
            'first_name'           => $fn,
            'last_name'            => $ln,
            'email'                => $email,
            'cc'                   => $cc,
            'localidad'            => $localidad,
            'ticket_pub'           => $ticketP,
            'modalidad'            => $modalidad,
            'modalidad_label'      => $modalidad_label,
            'is_virtual'           => $is_virtual,
            'virtual_url'          => $virtual_url,
            'virtual_today_status' => $virtual_today_status,
            'virtual_checked'      => ($virtual_today_status === 'checked_in'),
            'today_status'         => $today_status,
            'today_allowed'        => $today_allowed,
        ];
    }

    wp_send_json_success( $out );
});




/**
 * AJAX: Toggle check-in del día actual (con log)
 * - Respeta fechas del evento (TZ del evento)
 * - No admins: solo pueden togglear del evento ACTIVO
 */
add_action('wp_ajax_eventosapp_front_toggle_checkin', function(){
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('search') ) {
        wp_send_json_error(['message'=>'Permisos insuficientes'], 403);
    }

    check_ajax_referer('eventosapp_toggle_checkin','security');

    $ticket_id = absint($_POST['ticket_id'] ?? 0);
    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
        wp_send_json_error(['message'=>'Ticket inválido'], 400);
    }
    $evento_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    if ( ! $evento_id ) wp_send_json_error(['message'=>'Ticket sin evento'], 400);

    // 🔒 Forzar evento activo para no-admins
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $evento_id !== $active ) {
            wp_send_json_error(['message'=>'Sin permisos'], 403);
        }
    } elseif ( ! current_user_can('manage_options') && ! eventosapp_user_can_manage_event($evento_id) ) {
        wp_send_json_error(['message'=>'Sin permisos'], 403);
    }

    // Día actual en TZ del evento y validación contra días definidos
    $today = eventosapp_get_today_in_event_tz($evento_id);
    $days  = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days($evento_id) : [];

    if ( empty($days) || !in_array($today, $days, true) ) {
        // 200 OK para que llegue a .done() y podamos mostrar el mensaje de negocio
        wp_send_json_error(['message' => 'El check-in solo está permitido en las fechas del evento. Hoy no corresponde.']);
    }

    // Estado actual y toggle
    $status_arr = get_post_meta($ticket_id, '_eventosapp_checkin_status', true);
    if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
    if (!is_array($status_arr)) $status_arr = [];

    $curr = $status_arr[$today] ?? 'not_checked_in';
    $new  = ($curr === 'checked_in') ? 'not_checked_in' : 'checked_in';
    $status_arr[$today] = $new;
    update_post_meta($ticket_id, '_eventosapp_checkin_status', $status_arr);

    // Log
    $log = get_post_meta($ticket_id, '_eventosapp_checkin_log', true);
    if (is_string($log)) $log = @unserialize($log);
    if (!is_array($log)) $log = [];
    $user = wp_get_current_user();

    // Marca hora con TZ del evento para coherencia
    try {
        $tz = new DateTimeZone( get_post_meta($evento_id, '_eventosapp_zona_horaria', true) ?: wp_timezone_string() );
    } catch(Exception $e) {
        $tz = wp_timezone();
    }
    $now = new DateTime('now', $tz);

    $log_entry = [
        'fecha'        => $now->format('Y-m-d'),
        'hora'         => $now->format('H:i:s'),
        'dia'          => $today,
        'status'       => $new,
        'status_label' => ($new === 'checked_in') ? 'Check-in presencial' : 'Check-in presencial removido',
        'checkin_type' => 'presencial',
        'modalidad'    => function_exists('eventosapp_get_ticket_modalidad') ? eventosapp_get_ticket_modalidad($ticket_id) : get_post_meta($ticket_id, '_eventosapp_ticket_modalidad', true),
        'usuario'      => $user->display_name . ' (' . $user->user_email . ')',
        'origen'       => 'frontend-search',
    ];

    // Registrar como tipo 'Counter' cuando se activa el check-in manualmente
    if ( $new === 'checked_in' ) {
        $log_entry['qr_type']       = 'counter';
        $log_entry['qr_type_label'] = 'Counter';

        // Actualizar estadísticas de uso por tipo (mismo mecanismo que QR check-in)
        if ( function_exists('eventosapp_update_qr_usage_stats') ) {
            eventosapp_update_qr_usage_stats($evento_id, 'counter');
        }
    }

    $log[] = $log_entry;
    update_post_meta($ticket_id, '_eventosapp_checkin_log', $log);

wp_send_json_success([
        'today_status'         => $new,
        'today_allowed'        => true,
        'message'              => 'Estado actualizado.',
        'ticket_id'            => $ticket_id,
        'acompanantes_enabled' => (get_post_meta($evento_id, '_eventosapp_ticket_acompanantes_checkin', true) === '1'),
    ]);
});

/**
 * AJAX: Render de escarapela leyendo configuración del EVENTO
 * - No admins: solo pueden imprimir del evento ACTIVO
 */
add_action('wp_ajax_eventosapp_render_badge', 'eventosapp_ajax_render_badge');
function eventosapp_ajax_render_badge() {
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('search') ) {
        wp_die('Permisos insuficientes', '', 403);
    }

    check_ajax_referer('eventosapp_render_badge','nonce');

    $ticket_id = absint($_GET['ticket_id'] ?? 0);
    $event_id  = absint($_GET['event_id'] ?? 0);

    if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) wp_die('Ticket inválido', '', 400);

    // Fallback: si no viene o no coincide, toma el del ticket
    $event_from_ticket = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    if ( ! $event_id || $event_id !== $event_from_ticket ) {
        $event_id = $event_from_ticket;
    }
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) wp_die('Evento inválido', '', 400);

    // 🔒 Forzar evento activo para no-admins
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $event_id !== $active ) {
            wp_die('Sin permisos', '', 403);
        }
    } elseif ( ! current_user_can('manage_options') && ! eventosapp_user_can_manage_event($event_id) ) {
        wp_die('Sin permisos', '', 403);
    }

    echo eventosapp_get_badge_html_from_event($event_id, $ticket_id);
    exit;
}


/**
 * Construcción de la escarapela tomando metas del EVENTO.
 *
 * Fallback heredado: la versión central preferida vive en eventosapp-badges.php.
 * Esta declaración queda protegida para no generar fatal si el helper central
 * ya fue cargado, manteniendo compatibilidad con instalaciones donde el archivo
 * de escarapelas no esté disponible por alguna razón.
 */
if ( ! function_exists('eventosapp_get_badge_html_from_event') ) {
function eventosapp_get_badge_html_from_event( $event_id, $ticket_id = 0, $auto_print = true ) {
    // 1) Cargar la config del evento desde el helper del admin
    if ( ! function_exists('eventosapp_get_badge_settings') ) {
        $cfg = [
            'design' => 'manillas',
            'order'  => [1=>'full_name',2=>'company',3=>'qr',4=>'none',5=>'none'],
            'width'  => 374, 'height'=>208,
            'size_large'=>24, 'size_medium'=>18, 'size_small'=>14,
            'weight_large'=>600,'weight_medium'=>500,'weight_small'=>400,
            'sep_vertical'=>4, 'sep_horizontal'=>4,
            'qr_size'=>72, 'border_width'=>0,
        ];
    } else {
        $cfg = eventosapp_get_badge_settings( $event_id );
    }

    // 2) Orden activo
    $active = [];
    for ($i=1; $i<=5; $i++) {
        $f = $cfg['order'][$i] ?? 'none';
        if ($f !== 'none' && $f !== '') $active[] = $f;
    }
    if (!$active) $active = ['full_name', 'company', 'qr'];

    // 3) Datos del ticket - TODOS LOS CAMPOS
    $labels = [];
    
    if ($ticket_id && get_post_type($ticket_id)==='eventosapp_ticket') {
        $nombre   = get_post_meta($ticket_id, '_eventosapp_asistente_nombre',  true);
        $apell    = get_post_meta($ticket_id, '_eventosapp_asistente_apellido',true);
        $empresa  = get_post_meta($ticket_id, '_eventosapp_asistente_empresa', true);
        $cargo    = get_post_meta($ticket_id, '_eventosapp_asistente_cargo',   true);
        $cc       = get_post_meta($ticket_id, '_eventosapp_asistente_cc',      true);
        $email    = get_post_meta($ticket_id, '_eventosapp_asistente_email',   true);
        $tel      = get_post_meta($ticket_id, '_eventosapp_asistente_tel',     true);
        $nit      = get_post_meta($ticket_id, '_eventosapp_asistente_nit',     true);
        $ciudad   = get_post_meta($ticket_id, '_eventosapp_asistente_ciudad',  true);
        $pais     = get_post_meta($ticket_id, '_eventosapp_asistente_pais',    true);
        $localidad= get_post_meta($ticket_id, '_eventosapp_asistente_localidad',true);
        $code     = get_post_meta($ticket_id, 'eventosapp_ticketID',           true);

        // Campos básicos
        $labels['full_name']   = trim($nombre . ' ' . $apell) ?: 'Nombres + Apellidos';
        $labels['nombre']      = $nombre ?: 'Nombre';
        $labels['apellido']    = $apell ?: 'Apellido';
        $labels['company']     = $empresa ?: 'Nombre de la Empresa';
        $labels['designation'] = $cargo   ?: 'Cargo';
        $labels['cc_id']       = $cc      ?: 'CC_ID';
        $labels['email']       = $email   ?: 'Email';
        $labels['telefono']    = $tel     ?: 'Teléfono';
        $labels['nit']         = $nit     ?: 'NIT';
        $labels['ciudad']      = $ciudad  ?: 'Ciudad';
        $labels['pais']        = $pais    ?: 'País';
        $labels['localidad']   = $localidad ?: 'Localidad';

        // QR: se mantiene disponible para la escarapela manual, incluso si el ticket es virtual.
        // Esto permite imprimir escarapela cuando un asistente virtual también llega físicamente.
        if ($code && function_exists('eventosapp_get_ticket_qr_url')) {
            $labels['qr'] = eventosapp_get_ticket_qr_url($code);
        } else {
            $labels['qr'] = '';
        }

        // Campos adicionales del evento
        if (function_exists('eventosapp_get_event_extra_fields')) {
            $extra_fields = eventosapp_get_event_extra_fields($event_id);
            if (!empty($extra_fields)) {
                foreach ($extra_fields as $field) {
                    $key = 'extra_' . $field['key'];
                    $value = get_post_meta($ticket_id, '_eventosapp_extra_' . $field['key'], true);
                    $labels[$key] = $value ?: $field['label'];
                }
            }
        }
    } else {
        // Valores por defecto
        $labels = [
            'full_name'   => 'Nombres + Apellidos',
            'nombre'      => 'Nombre',
            'apellido'    => 'Apellido',
            'company'     => 'Nombre de la Empresa',
            'designation' => 'Cargo',
            'cc_id'       => 'CC_ID',
            'email'       => 'Email',
            'telefono'    => 'Teléfono',
            'nit'         => 'NIT',
            'ciudad'      => 'Ciudad',
            'pais'        => 'País',
            'localidad'   => 'Localidad',
            'qr'          => '',
        ];
        
        // Campos adicionales con valores por defecto
        if (function_exists('eventosapp_get_event_extra_fields')) {
            $extra_fields = eventosapp_get_event_extra_fields($event_id);
            if (!empty($extra_fields)) {
                foreach ($extra_fields as $field) {
                    $key = 'extra_' . $field['key'];
                    $labels[$key] = $field['label'];
                }
            }
        }
    }

    // 4) Dirección flex según diseño
    $flex_dir = ($cfg['design'] === 'escarapelas') ? 'column' : 'row';

    ob_start(); ?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Escarapela</title>
<style>
  html,body{margin:0;padding:0;height:100%}
  body{display:flex;align-items:center;justify-content:center;font-family:Arial,Helvetica,sans-serif}
  .badge{
    <?php echo ($cfg['border_width']>0 ? "border:{$cfg['border_width']}px solid #000;" : "border:none;"); ?>
    display:flex; flex-direction:<?php echo esc_attr($flex_dir); ?>;
    align-items:stretch; justify-content:center;
    width:<?php echo (int)$cfg['width']; ?>px; height:<?php echo (int)$cfg['height']; ?>px;
    padding:4px; box-sizing:border-box;
  }
  .left,.right{display:flex; flex-direction:column; justify-content:center; height:100%;}
  .right{align-items:center;}
  .slot{line-height:1.15; text-align:center; word-break:break-word;}
  @media print { @page { margin: 8mm; } }
</style>
</head>
<body>
<div class="badge">
<?php
    if ($cfg['design'] === 'escarapelas_split') {
        // Diseño split 3 izq / 1 der (ORIGINAL)
        $left  = array_slice($active, 0, 3);
        $right = $active[3] ?? null;

        echo "<div class='left'>";
        foreach ($left as $idx=>$field) {
            $fs = ($idx===0) ? $cfg['size_large'] : (($idx===1) ? $cfg['size_medium'] : $cfg['size_small']);
            $fw = ($idx===0) ? $cfg['weight_large'] : (($idx===1) ? $cfg['weight_medium'] : $cfg['weight_small']);
            $m  = $cfg['sep_vertical'];
            if ($field === 'qr' && !empty($labels['qr'])) {
                echo "<div class='slot' style='margin:{$m}px'><img src='".esc_url($labels['qr'])."' width='{$cfg['qr_size']}' height='{$cfg['qr_size']}' alt='QR'></div>";
            } else {
                $label_text = isset($labels[$field]) ? $labels[$field] : '';
                echo "<div class='slot' style='margin:{$m}px; font-size:{$fs}px; font-weight:{$fw};'>".esc_html($label_text)."</div>";
            }
        }
        echo "</div>";

        $mh = $cfg['sep_horizontal'];
        echo "<div class='right' style='margin-left:{$mh}px'>";
        if ($right) {
            if ($right === 'qr' && !empty($labels['qr'])) {
                echo "<div class='slot' style='margin:{$cfg['sep_vertical']}px'><img src='".esc_url($labels['qr'])."' width='{$cfg['qr_size']}' height='{$cfg['qr_size']}' alt='QR'></div>";
            } else {
                $label_text = isset($labels[$right]) ? $labels[$right] : '';
                echo "<div class='slot' style='margin:{$cfg['sep_vertical']}px; font-size:{$cfg['size_medium']}px; font-weight:{$cfg['weight_medium']};'>".esc_html($label_text)."</div>";
            }
        }
        echo "</div>";

    } elseif ($cfg['design'] === 'escarapelas_split_4') {
        // NUEVO DISEÑO: split 4 izq / 1 der
        $left  = array_slice($active, 0, 4);
        $right = $active[4] ?? null;

        echo "<div class='left'>";
        foreach ($left as $idx=>$field) {
            // Tamaños: primero grande, segundo y tercero medianos, cuarto pequeño
            if ($idx === 0) {
                $fs = $cfg['size_large'];
                $fw = $cfg['weight_large'];
            } elseif ($idx === 1 || $idx === 2) {
                $fs = $cfg['size_medium'];
                $fw = $cfg['weight_medium'];
            } else {
                $fs = $cfg['size_small'];
                $fw = $cfg['weight_small'];
            }
            $m  = $cfg['sep_vertical'];
            
            if ($field === 'qr' && !empty($labels['qr'])) {
                echo "<div class='slot' style='margin:{$m}px'><img src='".esc_url($labels['qr'])."' width='{$cfg['qr_size']}' height='{$cfg['qr_size']}' alt='QR'></div>";
            } else {
                $label_text = isset($labels[$field]) ? $labels[$field] : '';
                echo "<div class='slot' style='margin:{$m}px; font-size:{$fs}px; font-weight:{$fw};'>".esc_html($label_text)."</div>";
            }
        }
        echo "</div>";

        $mh = $cfg['sep_horizontal'];
        echo "<div class='right' style='margin-left:{$mh}px'>";
        if ($right) {
            if ($right === 'qr' && !empty($labels['qr'])) {
                echo "<div class='slot' style='margin:{$cfg['sep_vertical']}px'><img src='".esc_url($labels['qr'])."' width='{$cfg['qr_size']}' height='{$cfg['qr_size']}' alt='QR'></div>";
            } else {
                $label_text = isset($labels[$right]) ? $labels[$right] : '';
                echo "<div class='slot' style='margin:{$cfg['sep_vertical']}px; font-size:{$cfg['size_medium']}px; font-weight:{$cfg['weight_medium']};'>".esc_html($label_text)."</div>";
            }
        }
        echo "</div>";

    } else {
        // Diseños normales (manillas o escarapelas vertical)
        foreach (array_values($active) as $idx=>$field) {
            $margin = ($cfg['design']==='escarapelas') ? $cfg['sep_vertical'] : $cfg['sep_horizontal'];
            if ($field==='qr' && !empty($labels['qr'])) {
                echo "<div class='slot' style='margin:{$margin}px'><img src='".esc_url($labels['qr'])."' width='{$cfg['qr_size']}' height='{$cfg['qr_size']}' alt='QR'></div>";
            } else {
                if ($cfg['design']==='escarapelas') {
                    $fs = ($idx===0) ? $cfg['size_large'] : (($idx<=2) ? $cfg['size_medium'] : $cfg['size_small']);
                    $fw = ($idx===0) ? $cfg['weight_large'] : (($idx<=2) ? $cfg['weight_medium'] : $cfg['weight_small']);
                } else {
                    $fs = $cfg['size_medium'];
                    $fw = $cfg['weight_medium'];
                }
                $label_text = isset($labels[$field]) ? $labels[$field] : '';
                echo "<div class='slot' style='margin:{$margin}px; font-size:{$fs}px; font-weight:{$fw};'>".esc_html($label_text)."</div>";
            }
        }
    }
?>
</div>
<?php if ( ! empty( $auto_print ) ) : ?>
<script>window.print();</script>
<?php endif; ?>
</body></html>
<?php
    return ob_get_clean();
}
}
