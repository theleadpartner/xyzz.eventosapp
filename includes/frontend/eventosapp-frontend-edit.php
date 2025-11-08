<?php
/**
 * Frontend: Edición de tickets (buscar + cargar + editar)
 * Shortcode: [eventosapp_front_edit]
 * Requiere: evento activo elegido desde el dashboard (o event_id en el shortcode)
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * ===== Helpers de fecha (reusados igual que en eventosapp-frontend-search.php) =====
 */
if ( ! function_exists('eventosapp_get_today_in_event_tz') ) {
    function eventosapp_get_today_in_event_tz( $event_id ) {
        $event_tz = get_post_meta($event_id, '_eventosapp_zona_horaria', true);
        if ( ! $event_tz ) {
            $event_tz = wp_timezone_string();
            if ( ! $event_tz || $event_tz === 'UTC' ) {
                $offset = get_option('gmt_offset');
                $event_tz = $offset ? timezone_name_from_abbr('', $offset * 3600, 0) ?: 'UTC' : 'UTC';
            }
        }
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

// ———————————————— Shortcode contenedor ————————————————
add_shortcode('eventosapp_front_edit', function($atts){
    if ( function_exists('eventosapp_require_feature') ) eventosapp_require_feature('edit');

    $a = shortcode_atts(['event_id'=>0], $atts);
    $eid = absint($a['event_id']);

    // Si no vino event_id, usar evento activo del dashboard
    if ( ! $eid && function_exists('eventosapp_get_active_event') ) {
        $eid = (int) eventosapp_get_active_event();
    }

    // Debe haber evento
    if ( ! $eid ) {
        if ( function_exists('eventosapp_require_active_event') ) {
            eventosapp_require_active_event();
            return '';
        }
        $dash = function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/');
        return '<div style="padding:.8rem;border:1px solid #eee;background:#fffdf2;border-radius:8px;color:#8a6d3b;">
            Debes escoger un <strong>evento activo</strong> en el <a href="'.esc_url($dash).'">dashboard</a>.
        </div>';
    }

    // Validar permisos sobre el evento
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_user_can_manage_event') && ! eventosapp_user_can_manage_event($eid) ) {
        return '<div style="padding:.8rem;border:1px solid #eee;background:#fff8f8;border-radius:8px;color:#a33;">
            No tienes permisos sobre este evento.
        </div>';
    }

    // Detectar si el evento usa QR preimpreso
    $use_preprinted_qr = false;
    $flag_meta = get_post_meta($eid, '_eventosapp_ticket_use_preprinted_qr', true);
    if ($flag_meta !== '' && $flag_meta !== null) {
        $use_preprinted_qr = (bool) intval($flag_meta);
    } else {
        $flag_opt = get_option('_eventosapp_ticket_use_preprinted_qr', 0);
        $use_preprinted_qr = (bool) intval($flag_opt);
    }
    $use_preprinted_qr = (bool) apply_filters('eventosapp_use_preprinted_qr', $use_preprinted_qr, $eid);

    // ——— Procesamiento POST: actualizar ticket ———
    $msg = '';

    if ( isset($_POST['evedit_action']) && $_POST['evedit_action']==='update_ticket' ) {

        // Seguridad adicional: el usuario debe conservar permiso "edit"
        if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('edit') ) {
            $msg = '<div style="padding:.8rem;border:1px solid #fca5a5;background:#fee2e2;border-radius:10px;color:#991b1b;">Permisos insuficientes.</div>';
        } else {
            check_admin_referer('eventosapp_front_edit');

            $ticket_id = absint($_POST['ed_ticket_id'] ?? 0);
            if ( ! $ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket' ) {
                $msg = '<div style="padding:.8rem;border:1px solid #fca5a5;background:#fee2e2;border-radius:10px;color:#991b1b;">Ticket inválido.</div>';
            } else {
                // Asegurar que el ticket pertenece al evento activo (o que el usuario sea admin)
                $ticket_event = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
                if ( ! current_user_can('manage_options') && $ticket_event !== $eid ) {
                    $msg = '<div style="padding:.8rem;border:1px solid #fca5a5;background:#fee2e2;border-radius:10px;color:#991b1b;">No puedes editar este ticket.</div>';
                } else {
                    // Mapear campos del formulario a los esperados por el hook save_post_eventosapp_ticket
                    $_POST['eventosapp_ticket_nonce']       = wp_create_nonce('eventosapp_ticket_guardar');
                    $_POST['eventosapp_ticket_evento_id']   = $ticket_event ?: $eid; // no permitimos cambiar el evento aquí
                    $_POST['eventosapp_ticket_user_id']     = get_current_user_id();

                    $_POST['eventosapp_asistente_nombre']   = sanitize_text_field($_POST['ed_nombre']   ?? '');
                    $_POST['eventosapp_asistente_apellido'] = sanitize_text_field($_POST['ed_apellido'] ?? '');
                    $_POST['eventosapp_asistente_cc']       = sanitize_text_field($_POST['ed_cc']       ?? '');
                    $_POST['eventosapp_asistente_email']    = sanitize_email($_POST['ed_email']         ?? '');
                    $_POST['eventosapp_asistente_tel']      = sanitize_text_field($_POST['ed_tel']      ?? '');
                    $_POST['eventosapp_asistente_empresa']  = sanitize_text_field($_POST['ed_empresa']  ?? '');
                    $_POST['eventosapp_asistente_nit']      = sanitize_text_field($_POST['ed_nit']      ?? '');
                    $_POST['eventosapp_asistente_cargo']    = sanitize_text_field($_POST['ed_cargo']    ?? '');

                    // NUEVO
                    $_POST['eventosapp_asistente_ciudad']   = sanitize_text_field($_POST['ed_ciudad']   ?? '');
                    $_POST['eventosapp_asistente_pais']     = sanitize_text_field($_POST['ed_pais']     ?? 'Colombia');
                    $_POST['eventosapp_asistente_localidad']= sanitize_text_field($_POST['ed_localidad'] ?? '');

                    // Preimpreso (siempre aceptamos numérico; el save_post ya valida)
                    $preprinted_raw = wp_unslash($_POST['ed_preprinted_qr_id'] ?? '');
                    if ($preprinted_raw !== '') {
                        $_POST['eventosapp_ticket_preprintedID'] = preg_replace('/\D+/', '', (string)$preprinted_raw);
                    }

                    // Sesiones (checkbox)
                    $_POST['eventosapp_ticket_sesiones_nonce'] = wp_create_nonce('eventosapp_ticket_sesiones_guardar');
                    $ses = [];
                    if ( ! empty($_POST['ed_sesiones']) && is_array($_POST['ed_sesiones']) ) {
                        foreach ($_POST['ed_sesiones'] as $s) { $ses[] = sanitize_text_field($s); }
                    }
                    $_POST['eventosapp_ticket_sesiones_acceso'] = $ses;

                    // Extras (antes del guardado)
                    if (!empty($_POST['ed_extra']) && is_array($_POST['ed_extra'])) {
                        $_POST['eventosapp_extra'] = $_POST['ed_extra']; // el hook los sanea y guarda
                    }

                    // Disparar guardado reutilizando la misma lógica del admin
                    do_action('save_post_eventosapp_ticket', $ticket_id, get_post($ticket_id), true);

                    // Mensaje
                    $pub = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
                    $msg = '<div style="padding:12px;border:1px solid #d1fae5;background:#ecfdf5;border-radius:10px;color:#065f46;">
                        Cambios guardados para el Ticket <b>'.esc_html($pub ?: '#'.$ticket_id).'</b>.
                    </div>';
                }
            }
        }
    }

    // Localidades del evento
    $localidades = get_post_meta($eid, '_eventosapp_localidades', true);
    if (!is_array($localidades) || empty($localidades)) $localidades = ['General','VIP','Platino'];

    // Cargar barra de evento activo
    ob_start();
    if ( function_exists('eventosapp_active_event_bar') ) eventosapp_active_event_bar();

    if ($msg) echo $msg;
    ?>

    <div id="evfe-wrap" class="evfe-wrap" style="max-width:980px;margin:0 auto">
        <!-- Buscador -->
        <div class="evfe-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;box-shadow:0 1px 5px rgba(120,140,160,.06)">
            <h2 style="margin:0 0 10px;font-size:22px;">Editar tickets</h2>
            <p style="margin:0 0 10px;color:#555">Busca al asistente por nombre, apellido, email, CC o TicketID. Haz clic en <b>Editar</b>.</p>
            <input id="evfe-input" type="text" class="evfe-input" placeholder="Buscar…" style="width:100%;padding:.65rem .7rem;border:1px solid #dfe3e7;border-radius:10px">
            <div id="evfe-results" class="evfe-results" style="margin-top:10px"></div>
        </div>

        <!-- Formulario de edición (se rellena vía AJAX) -->
        <form id="evfe-form" method="post" style="display:none;margin-top:14px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;box-shadow:0 1px 5px rgba(120,140,160,.06)">
            <?php wp_nonce_field('eventosapp_front_edit'); ?>
            <input type="hidden" name="evedit_action" value="update_ticket">
            <input type="hidden" name="ed_ticket_id" id="ed_ticket_id">
            <input type="hidden" name="ed_event_id" value="<?php echo esc_attr($eid); ?>">

            <h3 style="margin:0 0 12px">Editar datos del asistente</h3>

            <!-- Check-in (igual lógica que el buscador: solo hoy y si está permitido) -->
            <div id="evfe-checkin-wrap" style="display:none;margin:8px 0 12px;padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#f9fafb;align-items:center;gap:10px;flex-wrap:wrap">
                <span id="evfe-checkin-badge" class="evfe-badge evfe-badge-no">Not Checked In</span>
                <button type="button" id="evfe-toggle-checkin" class="evfe-btn evfe-toggle">Toggle Check-in</button>
                <small id="evfe-checkin-note" style="color:#555"></small>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
                <div><label>Nombre *</label><input type="text" name="ed_nombre" id="ed_nombre" required class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div><label>Apellido *</label><input type="text" name="ed_apellido" id="ed_apellido" required class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div><label>CC</label><input type="text" name="ed_cc" id="ed_cc" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div><label>Email *</label><input type="email" name="ed_email" id="ed_email" required class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div><label>Teléfono</label><input type="tel" name="ed_tel" id="ed_tel" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div><label>Empresa</label><input type="text" name="ed_empresa" id="ed_empresa" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div><label>NIT</label><input type="text" name="ed_nit" id="ed_nit" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div><label>Cargo</label><input type="text" name="ed_cargo" id="ed_cargo" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>

                <!-- NUEVO -->
                <div><label>Ciudad</label><input type="text" name="ed_ciudad" id="ed_ciudad" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7"></div>
                <div>
                    <label>País</label>
                    <select name="ed_pais" id="ed_pais" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7">
                        <?php
                        $countries = function_exists('eventosapp_get_countries') ? eventosapp_get_countries() : array('Colombia');
                        foreach ($countries as $c) {
                            echo '<option value="'.esc_attr($c).'">'.esc_html($c).'</option>';
                        }
                        ?>
                    </select>
                </div>

                <div>
                    <label>Localidad</label>
                    <select name="ed_localidad" id="ed_localidad" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7">
                        <option value="">Seleccione…</option>
                        <?php foreach($localidades as $loc): ?>
                            <option value="<?php echo esc_attr($loc); ?>"><?php echo esc_html($loc); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="evfe-extras" style="margin-top:12px;padding-top:10px;border-top:1px dashed #e5e7eb"></div>

                <div>
                    <label>ID de QR preimpreso (numérico)</label>
                    <input type="text" name="ed_preprinted_qr_id" id="ed_preprinted_qr_id" class="widefat" placeholder="Ej: 00012345" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7">
                    <small style="color:#666;display:block;margin-top:4px;">
                        <?php echo $use_preprinted_qr ? 'Este evento usa QR preimpreso.' : 'Úsalo solo si el ticket tiene QR preimpreso.'; ?>
                    </small>
                </div>
            </div>

            <div id="evfe-sesiones" style="margin-top:12px;padding-top:10px;border-top:1px dashed #e5e7eb">
                <b>Acceso a sesiones internas:</b>
                <div id="evfe-sesiones-list" style="margin-top:8px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;"></div>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:16px">
                <button type="submit" class="button button-primary" style="padding:.7rem 1.1rem;border-radius:10px;font-weight:700">Guardar cambios</button>

                <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <input type="email" id="evfe_email_alt" placeholder="Enviar a otro correo (opcional)" style="padding:.5rem .6rem;border:1px solid #dfe3e7;border-radius:10px;min-width:260px">
                    <button type="button" id="evfe_send_mail" class="button" style="border-color:#2563eb;background:#2563eb;color:#fff;border-radius:10px;">Reenviar ticket por correo</button>
                    <span id="evfe_mail_note" style="font-size:.95rem;color:#555"></span>
                </div>
            </div>
        </form>
    </div>

    <?php
    // ——— Scripts/estilos ———
    wp_enqueue_script('jquery');
    wp_register_script('eventosapp-front-edit', false, ['jquery'], null, true);
    wp_localize_script('eventosapp-front-edit', 'EvFrontEdit', [
        'ajax_url'     => admin_url('admin-ajax.php'),
        'search_nonce' => wp_create_nonce('eventosapp_front_search'),
        'get_nonce'    => wp_create_nonce('eventosapp_front_get_ticket'),
        'mail_nonce'   => wp_create_nonce('eventosapp_front_send_ticket_email'),
        // Usamos el MISMO nonce que el buscador:
        'toggle_nonce' => wp_create_nonce('eventosapp_toggle_checkin'),
        'event_id'     => $eid,
        'msgs'         => [
            'not_allowed' => __('El check-in solo está permitido en las fechas del evento. Hoy no corresponde.', 'eventosapp'),
            'net_error'   => __('Error de red. Intenta de nuevo.', 'eventosapp')
        ]
    ]);

    // CSS (como estilo de WP, no por JS)
    $css = <<<CSS
.evfe-row{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;padding:.8rem;border:1px solid #eee;border-radius:12px;background:#fff;margin-bottom:8px;box-shadow:0 1px 5px rgba(120,140,160,.07)}
.evfe-data{flex:1 1 auto;min-width:0;word-break:break-word}
.evfe-actions{flex:0 0 auto;display:flex;gap:8px}
.evfe-btn{display:inline-block;border-radius:8px;border:0;font-size:1rem;font-weight:600;cursor:pointer;padding:.55rem .9rem;box-shadow:0 1px 4px rgba(30,60,100,.07)}
.evfe-edit{background:#2563eb;color:#fff}
.evfe-edit:hover{background:#1d4ed8}
.evfe-note{display:inline-block;margin-left:8px;font-size:.92rem;color:#0f5132;background:#d1e7dd;border:1px solid #badbcc;padding:.25rem .45rem;border-radius:6px}
@media(max-width:650px){ .evfe-row{flex-direction:column} .evfe-actions{justify-content:stretch} .evfe-btn{width:100%} }

/* Accesibilidad visual del formulario */
#evfe-form label{font-weight:700;color:#111}
#evfe-form input:not([type]),
#evfe-form input[type="text"],
#evfe-form input[type="email"],
#evfe-form input[type="tel"],
#evfe-form input[type="number"],
#evfe-form select,
#evfe-form textarea{
  background:#f6f7fb;
  border-color:#dfe3e7;
}
#evfe-form input[type="checkbox"]{background:transparent}
#evfe-form input:focus,
#evfe-form select:focus,
#evfe-form textarea:focus{
  outline:none;
  box-shadow:0 0 0 3px rgba(37,99,235,.15);
  background:#f3f5fb;
}
#evfe-form.evfe-form-highlight{
  box-shadow:0 0 0 3px rgba(37,99,235,.15), 0 1px 6px rgba(120,140,160,.12) !important;
}

/* Badge Check-in */
.evfe-badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:.92rem;font-weight:700}
.evfe-badge-ok{background:#16a34a;color:#fff}
.evfe-badge-no{background:#b91c1c;color:#fff}

/* Botón toggle */
.evfe-toggle{background:#111827;color:#fff}
.evfe-toggle:hover{background:#0b1220}
CSS;

    wp_register_style('eventosapp-front-edit', false, [], null);
    wp_add_inline_style('eventosapp-front-edit', $css);
    wp_enqueue_style('eventosapp-front-edit');

    // JS en NOWDOC para evitar interpolación de PHP y permitir nombres $var en JS
    $js = <<<'JS'
jQuery(function($){
  var $in  = $('#evfe-input'),
      $out = $('#evfe-results'),
      $form= $('#evfe-form'),
      eventId = EvFrontEdit.event_id,
      timer;

  function render(rows){
    if(!rows.length){ $out.html('<div style="padding:.5rem;color:#666;">No hay resultados.</div>'); return; }
    var html='';
    $.each(rows,function(i,it){
      var full = (it.first_name||'')+' '+(it.last_name||'');
      html += '<div class="evfe-row">'
           +   '<div class="evfe-data">'
           +     '<strong>'+ full +'</strong> <span style="color:#888">('+(it.cc||'—')+')</span><br>'
           +     'Email: '+(it.email||'—')+'<br>'
           +     'TicketID: '+(it.ticket_pub||'—')+' · Evento: '+(it.event_name||'—') 
           +   '</div>'
           +   '<div class="evfe-actions">'
           +     '<button class="evfe-btn evfe-edit" data-ticket-id="'+it.ticket_id+'">Editar</button>'
           +   '</div>'
           + '</div>';
    });
    $out.html(html);
  }

  $in.on('input', function(){
    clearTimeout(timer);
    var q = $in.val().trim();
    if(!q || q.length < 2){ $out.empty(); return; }
    timer = setTimeout(function(){
      $.getJSON(EvFrontEdit.ajax_url, {
        action: 'eventosapp_front_search',
        security: EvFrontEdit.search_nonce,
        q: q,
        event_id: eventId
      }).done(function(resp){
        if(resp && resp.success){ render(resp.data||[]); } else { render([]); }
      }).fail(function(){ render([]); });
    }, 250);
  });

  function setBadge(status){
    var $b = $('#evfe-checkin-badge');
    if(status==='checked_in'){
      $b.removeClass('evfe-badge-no').addClass('evfe-badge-ok').text('Checked In');
    } else {
      $b.removeClass('evfe-badge-ok').addClass('evfe-badge-no').text('Not Checked In');
    }
  }

  // Cargar datos del ticket en el formulario
  $(document).on('click', '.evfe-edit', function(){
    var tid = $(this).data('ticket-id');
    $.getJSON(EvFrontEdit.ajax_url, {
      action: 'eventosapp_front_get_ticket',
      security: EvFrontEdit.get_nonce,
      ticket_id: tid
    }).done(function(resp){
      if(!resp || !resp.success || !resp.data){ alert('No se pudo cargar el ticket.'); return; }
      var d = resp.data;
      // Campos base
      $('#ed_ticket_id').val(d.ticket_id);
      $('#ed_nombre').val(d.nombre||'');
      $('#ed_apellido').val(d.apellido||'');
      $('#ed_cc').val(d.cc||'');
      $('#ed_email').val(d.email||'');
      $('#ed_tel').val(d.tel||'');
      $('#ed_empresa').val(d.empresa||'');
      $('#ed_nit').val(d.nit||'');
      $('#ed_cargo').val(d.cargo||'');

      // NUEVO
      $('#ed_ciudad').val(d.ciudad||'');
      $('#ed_pais').val(d.pais || 'Colombia');

      // Localidades
      var $sel = $('#ed_localidad');

      // Campos adicionales dinámicos
      var $extras = $('#evfe-extras').empty();
      var schema = Array.isArray(d.extras_schema) ? d.extras_schema : [];
      var vals   = d.extras_values || {};
      if (schema.length) {
        var html = '<b>Campos adicionales:</b><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-top:8px;">';
        schema.forEach(function(f){
          var key = f.key, label = f.label || key, req = !!f.required;
          var val = (vals && typeof vals[key] !== 'undefined') ? vals[key] : '';
          html += '<div><label>'+label+(req?' *':'')+'</label>';
          var name = 'ed_extra['+key+']';
          if (f.type === 'number') {
            html += '<input type="number" name="'+name+'" value="'+(val||'')+'" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7">';
          } else if (f.type === 'select') {
            html += '<select name="'+name+'" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7">';
            html += '<option value="">Seleccione…</option>';
            (f.options||[]).forEach(function(op){
              var sel = (op===val)?' selected':'';
              html += '<option value="'+String(op).replace(/"/g,'&quot;')+'"'+sel+'>'+op+'</option>';
            });
            html += '</select>';
          } else {
            html += '<input type="text" name="'+name+'" value="'+(val||'')+'" class="widefat" style="padding:.55rem;border-radius:10px;border:1px solid #dfe3e7">';
          }
          html += '</div>';
        });
        html += '</div>';
        $extras.html(html).show();
      } else {
        $extras.hide();
      }

      if (Array.isArray(d.localidades) && d.localidades.length){
        var curr = d.localidad||'';
        $sel.empty().append('<option value="">Seleccione…</option>');
        d.localidades.forEach(function(l){
          var sel = (l===curr)?' selected':'';
          $sel.append('<option value="'+l.replace(/"/g,'&quot;')+'"'+sel+'>'+l+'</option>');
        });
      } else {
        $('#ed_localidad').val(d.localidad||'');
      }

      // Preimpreso
      $('#ed_preprinted_qr_id').val(d.preprinted||'');

      // Sesiones checkbox
      var $list = $('#evfe-sesiones-list').empty();
      if (Array.isArray(d.sesiones) && d.sesiones.length){
        d.sesiones.forEach(function(s){
          var checked = (Array.isArray(d.sesiones_acceso) && d.sesiones_acceso.indexOf(s)>=0) ? 'checked' : '';
          $list.append('<label style="display:flex;align-items:center;gap:8px;border:1px solid #eee;border-radius:8px;padding:8px;background:#fafbfc;">'
                       +'<input type="checkbox" name="ed_sesiones[]" value="'+s.replace(/"/g,'&quot;')+'" '+checked+'> '+s+'</label>');
        });
        $('#evfe-sesiones').show();
      } else {
        $('#evfe-sesiones').hide();
      }

      // Check-in hoy (igual que el buscador)
      if (typeof d.today_allowed !== 'undefined') {
        setBadge(d.today_status || 'not_checked_in');
        $('#evfe-checkin-wrap').css('display','flex');
        var $btn = $('#evfe-toggle-checkin');
        $btn.prop('disabled', !d.today_allowed);
        $('#evfe-checkin-note').text(d.today_allowed ? '' : EvFrontEdit.msgs.not_allowed);
      } else {
        $('#evfe-checkin-wrap').hide();
      }

      // Mostrar formulario + scroll y focus
      $form.slideDown(140, function(){
        var top = $form.offset().top - 8;
        window.scrollTo({top: top, behavior: 'smooth'});
        $('#ed_nombre').trigger('focus');
        $form.addClass('evfe-form-highlight');
        setTimeout(function(){ $form.removeClass('evfe-form-highlight'); }, 1200);
      });

      // Nota del mail limpia
      $('#evfe_mail_note').text('');
    }).fail(function(){
      alert('Error de red.');
    });
  });

  // Toggle Check-in (usando el MISMO endpoint del buscador)
  $('#evfe-toggle-checkin').on('click', function(){
    var tid = $('#ed_ticket_id').val();
    if(!tid){ alert('Primero carga un ticket.'); return; }
    $('#evfe-checkin-note').text('Actualizando...');
    $('#evfe-toggle-checkin').prop('disabled', true);

    $.post(EvFrontEdit.ajax_url, {
      action: 'eventosapp_front_toggle_checkin',
      security: EvFrontEdit.toggle_nonce,
      ticket_id: tid
    }, function(resp){
      if(resp && resp.success && resp.data){
        setBadge(resp.data.today_status);
        $('#evfe-checkin-note').html('<span class="evfe-note">Estado actualizado.</span>');
        $('#evfe-toggle-checkin').prop('disabled', false);
      } else {
        var m = (resp && resp.data && resp.data.message) ? resp.data.message : EvFrontEdit.msgs.not_allowed;
        $('#evfe-checkin-note').text(m);
        $('#evfe-toggle-checkin').prop('disabled', false);
      }
    }, 'json').fail(function(xhr){
      var msg = EvFrontEdit.msgs.net_error;
      try {
        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          msg = xhr.responseJSON.data.message;
        } else if (xhr.responseText) {
          var j = JSON.parse(xhr.responseText);
          if (j && j.data && j.data.message) msg = j.data.message;
        }
      } catch(e){}
      $('#evfe-checkin-note').text(msg);
      $('#evfe-toggle-checkin').prop('disabled', false);
    });
  });

  // Reenviar correo
  $('#evfe_send_mail').on('click', function(){
    var tid = $('#ed_ticket_id').val();
    if(!tid){ alert('Primero carga un ticket.'); return; }
    var alt = $('#evfe_email_alt').val().trim();
    $('#evfe_mail_note').text('Enviando…');
    $.post(EvFrontEdit.ajax_url, {
      action: 'eventosapp_front_send_ticket_email',
      security: EvFrontEdit.mail_nonce,
      ticket_id: tid,
      alt_email: alt
    }, function(resp){
      if(resp && resp.success){
        $('#evfe_mail_note').html('<span class="evfe-note">Correo enviado.</span>');
      } else {
        var m = (resp && resp.data && resp.data.message) ? resp.data.message : 'No se pudo enviar el correo.';
        $('#evfe_mail_note').text(m);
      }
    }, 'json').fail(function(){
      $('#evfe_mail_note').text(EvFrontEdit.msgs.net_error);
    });
  });
});
JS;

    wp_add_inline_script('eventosapp-front-edit', $js);
    wp_enqueue_script('eventosapp-front-edit');

    return ob_get_clean();
});

// ———————————————— AJAX: obtener datos del ticket ————————————————
add_action('wp_ajax_eventosapp_front_get_ticket', function(){
    // 1) CSRF
    check_ajax_referer('eventosapp_front_get_ticket','security');

    // 2) Permiso de feature "edit"
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('edit') ) {
        wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
    }

    // 3) Validaciones
    $tid = absint($_GET['ticket_id'] ?? 0);
    if ( ! $tid || get_post_type($tid) !== 'eventosapp_ticket' ) {
        wp_send_json_error(['message' => 'Ticket inválido'], 400);
    }

    $evento_id = (int) get_post_meta($tid, '_eventosapp_ticket_evento_id', true);
    if ( ! $evento_id ) {
        wp_send_json_error(['message' => 'Ticket sin evento'], 400);
    }

    // 4) Seguridad: admin o gestor del evento
    if ( ! current_user_can('manage_options')
         && function_exists('eventosapp_user_can_manage_event')
         && ! eventosapp_user_can_manage_event($evento_id) ) {
        wp_send_json_error(['message' => 'Sin permisos'], 403);
    }

    // 5) Datos
    $nombre   = get_post_meta($tid, '_eventosapp_asistente_nombre', true);
    $apellido = get_post_meta($tid, '_eventosapp_asistente_apellido', true);
    $cc       = get_post_meta($tid, '_eventosapp_asistente_cc', true);
    $email    = get_post_meta($tid, '_eventosapp_asistente_email', true);
    $tel      = get_post_meta($tid, '_eventosapp_asistente_tel', true);
    $emp      = get_post_meta($tid, '_eventosapp_asistente_empresa', true);
    $nit      = get_post_meta($tid, '_eventosapp_asistente_nit', true);
    $cargo    = get_post_meta($tid, '_eventosapp_asistente_cargo', true);
    $loc      = get_post_meta($tid, '_eventosapp_asistente_localidad', true);
    $pre      = get_post_meta($tid, 'eventosapp_ticket_preprintedID', true);

    // NUEVO
    $ciudad   = get_post_meta($tid, '_eventosapp_asistente_ciudad', true);
    $pais     = get_post_meta($tid, '_eventosapp_asistente_pais', true);

    $localidades = get_post_meta($evento_id, '_eventosapp_localidades', true);
    if (!is_array($localidades) || empty($localidades)) $localidades = ['General','VIP','Platino'];

    $sesiones = get_post_meta($evento_id, '_eventosapp_sesiones_internas', true);
    if (!is_array($sesiones)) $sesiones = [];
    $ses_nombres = [];
    foreach ($sesiones as $s) {
        if (is_array($s) && isset($s['nombre']) && $s['nombre']!=='') $ses_nombres[] = $s['nombre'];
        elseif (is_string($s) && $s!=='') $ses_nombres[] = $s;
    }
    $ses_acceso = get_post_meta($tid, '_eventosapp_ticket_sesiones_acceso', true);
    if (!is_array($ses_acceso)) $ses_acceso = [];

    $extras_schema = function_exists('eventosapp_get_event_extra_fields') ? eventosapp_get_event_extra_fields($evento_id) : [];
    $extras_values = [];
    foreach ($extras_schema as $fld){
        $extras_values[$fld['key']] = get_post_meta($tid, '_eventosapp_extra_'.$fld['key'], true);
    }

    // Info de check-in de HOY (misma lógica del buscador)
    $today_allowed = eventosapp_is_today_valid_for_event($evento_id);
    $today         = eventosapp_get_today_in_event_tz($evento_id);
    $status_arr    = get_post_meta($tid, '_eventosapp_checkin_status', true);
    if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
    if (!is_array($status_arr)) $status_arr = [];
    $today_status  = $today_allowed ? ($status_arr[$today] ?? 'not_checked_in') : 'not_checked_in';

    wp_send_json_success([
        'ticket_id'         => $tid,
        'event_id'          => $evento_id,
        'nombre'            => $nombre,
        'apellido'          => $apellido,
        'cc'                => $cc,
        'email'             => $email,
        'tel'               => $tel,
        'empresa'           => $emp,
        'nit'               => $nit,
        'cargo'             => $cargo,
        // NUEVO
        'ciudad'            => $ciudad,
        'pais'              => $pais ?: 'Colombia',
        //
        'localidad'         => $loc,
        'localidades'       => array_values(array_unique(array_filter($localidades))),
        'preprinted'        => $pre,
        'sesiones'          => array_values(array_unique(array_filter($ses_nombres))),
        'sesiones_acceso'   => $ses_acceso,
        'extras_schema'     => $extras_schema,
        'extras_values'     => $extras_values,

        // Check-in (hoy)
        'today_allowed'     => $today_allowed,
        'today_status'      => $today_status,
    ]);

});

// ———————————————— (SIN handler local de toggle check-in)
// Usamos el endpoint existente `eventosapp_front_toggle_checkin` del archivo “search”
// con el mismo nonce 'eventosapp_toggle_checkin'.

// ———————————————— AJAX: reenviar ticket por correo ————————————————
add_action('wp_ajax_eventosapp_front_send_ticket_email', function(){
    // 1) CSRF
    check_ajax_referer('eventosapp_front_send_ticket_email','security');

    // 2) Permiso de feature "edit"
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('edit') ) {
        wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
    }

    // 3) Validaciones
    $tid = absint($_POST['ticket_id'] ?? 0);
    if ( ! $tid || get_post_type($tid) !== 'eventosapp_ticket' ) {
        wp_send_json_error(['message' => 'Ticket inválido'], 400);
    }

    $evento_id = (int) get_post_meta($tid, '_eventosapp_ticket_evento_id', true);
    if ( ! $evento_id ) {
        wp_send_json_error(['message' => 'Ticket sin evento'], 400);
    }

    // 4) Seguridad: admin o dueño del evento
    if ( ! current_user_can('manage_options')
         && function_exists('eventosapp_user_can_manage_event')
         && ! eventosapp_user_can_manage_event($evento_id) ) {
        wp_send_json_error(['message' => 'Sin permisos'], 403);
    }

    // 5) Destino
    $stored = get_post_meta($tid, '_eventosapp_asistente_email', true);
    $alt    = sanitize_email($_POST['alt_email'] ?? '');
    $to     = $alt ?: $stored;
    if ( ! $to || ! is_email($to) ) {
        wp_send_json_error(['message' => 'Correo destino inválido'], 400);
    }

    // 6) Flags del evento
    $pdf_on = get_post_meta($evento_id, '_eventosapp_ticket_pdf', true) === '1';
    $ics_on = get_post_meta($evento_id, '_eventosapp_ticket_ics', true) === '1';

    if ($pdf_on && function_exists('eventosapp_ticket_generar_pdf')) eventosapp_ticket_generar_pdf($tid);
    if ($ics_on && function_exists('eventosapp_ticket_generar_ics')) eventosapp_ticket_generar_ics($tid);

    // 7) Adjuntos
    $attachments = [];
    if ($pdf_on) {
        $pdf_url = get_post_meta($tid, '_eventosapp_ticket_pdf_url', true);
        if ($pdf_url && function_exists('eventosapp_url_to_path')) {
            $pdf_path = eventosapp_url_to_path($pdf_url);
            if ($pdf_path && file_exists($pdf_path)) $attachments[] = $pdf_path;
        }
    }
    if ($ics_on) {
        $ics_url = get_post_meta($tid, '_eventosapp_ticket_ics_url', true);
        if ($ics_url && function_exists('eventosapp_url_to_path')) {
            $ics_path = eventosapp_url_to_path($ics_url);
            if ($ics_path && file_exists($ics_path)) $attachments[] = $ics_path;
        }
    }

    // 8) Email
    if ( ! function_exists('eventosapp_build_ticket_email_html') ) {
        wp_send_json_error(['message'=>'Plantilla de email no disponible.'], 500);
    }
    $subject = 'Tu ticket';
    $ev_name = $evento_id ? get_the_title($evento_id) : '';
    if ($ev_name) $subject = 'Tu ticket para ' . $ev_name;

    $html = eventosapp_build_ticket_email_html($tid);
    $headers = ['Content-Type: text/html; charset=UTF-8'];

    $ok = wp_mail($to, $subject, $html, $headers, $attachments);
    if ($ok) wp_send_json_success(true);
    wp_send_json_error(['message'=>'No se pudo enviar el correo. Revisa configuración SMTP/hosting.'], 500);
});
