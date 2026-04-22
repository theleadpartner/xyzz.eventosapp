<?php
// includes/admin/eventosapp-email-header.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Encola el Media Uploader y el Color Picker únicamente en la pantalla del CPT eventosapp_event
 */
add_action('admin_enqueue_scripts', function(){
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'eventosapp_event' ) {
        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }
});

/**
 * Helpers: path de plantillas y listado
 */
if (!function_exists('eventosapp_email_templates_dir')) {
    function eventosapp_email_templates_dir(){
        // Este archivo vive en includes/admin/, subimos a includes/ y luego templates/email_tickets/
        return trailingslashit( dirname(__FILE__, 2) ) . 'templates/email_tickets/';
    }
}

if (!function_exists('eventosapp_email_list_templates')) {
    function eventosapp_email_list_templates(){
        $dir = eventosapp_email_templates_dir();
        $items = [];
        if (is_dir($dir)) {
            $files = glob($dir . '*.html');
            if ($files) {
                foreach ($files as $file) {
                    $base = basename($file);
                    // Etiqueta legible desde el nombre de archivo: email-ticket.html -> Email Ticket
                    $label = ucwords( trim( str_replace(['-', '_'], ' ', preg_replace('/\.html$/i','', $base)) ) );
                    $items[$base] = $label;
                }
            }
        }
        return $items;
    }
}

/**
 * Metabox: Email del Ticket (Plantilla, Header e Infos)
 */
add_action('add_meta_boxes', function(){
    add_meta_box(
        'eventosapp_email_header',
        'Email del Ticket — Plantilla y Header',
        'eventosapp_render_metabox_email_header',
        'eventosapp_event',
        'side',
        'high'
    );
});

/**
 * Campos disponibles para filtros de segmentación del recordatorio.
 */
if ( ! function_exists('eventosapp_reminder_filter_fields') ) {
    function eventosapp_reminder_filter_fields() {
        return [
            '_eventosapp_ticket_email_sent_status' => 'Estado de envío del correo',
            '_eventosapp_asistente_nombre'          => 'Nombre',
            '_eventosapp_asistente_apellido'        => 'Apellido',
            '_eventosapp_asistente_email'           => 'Email',
            '_eventosapp_asistente_cc'              => 'Cédula / ID',
            '_eventosapp_asistente_empresa'         => 'Empresa',
            '_eventosapp_asistente_cargo'           => 'Cargo',
            '_eventosapp_asistente_localidad'       => 'Localidad',
            '_eventosapp_asistente_tipo_entrada'    => 'Tipo de entrada',
            '_eventosapp_creation_channel'          => 'Canal de creación',
        ];
    }
}

/**
 * Operadores disponibles para filtros de segmentación del recordatorio.
 */
if ( ! function_exists('eventosapp_reminder_filter_operators') ) {
    function eventosapp_reminder_filter_operators() {
        return [
            'equals'       => 'Es igual a',
            'not_equals'   => 'No es igual a',
            'contains'     => 'Contiene',
            'not_contains' => 'No contiene',
            'is_empty'     => 'Está vacío',
            'is_not_empty' => 'No está vacío',
        ];
    }
}


function eventosapp_render_metabox_email_header($post){
    $url          = get_post_meta($post->ID, '_eventosapp_email_header_img', true) ?: '';
    $tpl_selected = get_post_meta($post->ID, '_eventosapp_email_tpl', true) ?: 'email-ticket.html';
    $subject      = get_post_meta($post->ID, '_eventosapp_email_subject', true) ?: '';
    $from_name    = get_post_meta($post->ID, '_eventosapp_email_fromname', true) ?: '';
    $extra_msg    = get_post_meta($post->ID, '_eventosapp_email_msg', true) ?: '';
    $h_color      = get_post_meta($post->ID, '_eventosapp_email_heading_color', true) ?: '';
    
    // === NUEVO: Imagen del mensaje adicional ===
    $extra_img    = get_post_meta($post->ID, '_eventosapp_email_msg_img', true) ?: '';

    // === NUEVO: Campos de recordatorio ===
    $rem_enable = get_post_meta($post->ID, '_eventosapp_reminder_enabled', true) === '1' ? '1' : '0';
    $rem_amount = get_post_meta($post->ID, '_eventosapp_reminder_amount', true);
    $rem_amount = ($rem_amount === '' || $rem_amount === null) ? 24 : intval($rem_amount); // por defecto 24
    $rem_unit   = get_post_meta($post->ID, '_eventosapp_reminder_unit', true) ?: 'hours'; // minutes|hours|days
    $rem_rate   = get_post_meta($post->ID, '_eventosapp_reminder_rate_per_minute', true);
    $rem_rate   = ($rem_rate === '' || $rem_rate === null) ? 20 : max(1, intval($rem_rate));
    // Filtros de segmentación del recordatorio
    $rem_filters = get_post_meta( $post->ID, '_eventosapp_reminder_filters', true );
    if ( ! is_array( $rem_filters ) ) $rem_filters = [];

    $fallback = 'https://eventosapp.com/wp-content/uploads/2025/08/header_ticket_gen.jpg';
    $templates = eventosapp_email_list_templates();

    wp_nonce_field('eventosapp_email_header_guardar', 'eventosapp_email_header_nonce');
    ?>
    <style>
      .evapp-hdr small{color:#666}
      .evapp-hdr .preview{margin:8px 0;border:1px solid #e5e5e5;border-radius:6px;overflow:hidden;background:#fafafa}
      .evapp-hdr .preview img{display:block;width:100%;height:auto}
      .evapp-hdr .row{display:flex;gap:6px;margin-top:6px}
      .evapp-hdr input[type="url"], .evapp-hdr input[type="text"], .evapp-hdr select, .evapp-hdr textarea {width:100%}
      .evapp-hdr .field{margin-top:10px}
      .evapp-hdr textarea{min-height:80px;resize:vertical}
      .evapp-help-tip{display:inline-block;border-radius:999px;background:#eee;color:#333;padding:0 6px;margin-left:4px;font-size:11px;cursor:help}
      .evapp-hdr .color-wrap{display:flex;align-items:center;gap:8px}
      /* NUEVO: estilos mínimos para el bloque de recordatorio */
      .evapp-reminder{background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:10px;margin-top:12px}
      .evapp-reminder h4{margin:0 0 6px;font-size:13px}
      .evapp-reminder .inline{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
      .evapp-reminder .inline input[type="number"]{max-width:100px}
      .evapp-reminder .muted{color:#666;font-size:11px;margin-top:6px;display:block}
      .evapp-reminder .preview-line{font-size:12px;background:#fff;border:1px dashed #e5e7eb;border-radius:4px;padding:6px;margin-top:6px}
      /* Estilos para la imagen del mensaje adicional */
      .evapp-msg-img-wrap{background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:10px;margin-top:8px}
      .evapp-msg-img-wrap .preview{margin:8px 0;max-width:300px}
    /* Filtros de segmentación del recordatorio */
      .evapp-reminder-filters { margin-top:10px; border-top:1px dashed #d1d5db; padding-top:10px; }
      .evapp-rem-filter-row { display:flex; gap:4px; align-items:center; margin-bottom:5px; flex-wrap:wrap; }
      .evapp-rem-filter-row .evapp-rem-field    { flex:2; min-width:110px; }
      .evapp-rem-filter-row .evapp-rem-operator { flex:1.8; min-width:110px; }
      .evapp-rem-filter-row .evapp-rem-value    { flex:2; min-width:70px; }
      .evapp-rem-filter-row .evapp-rem-del      { flex:0 0 auto; color:#c00; min-width:28px; text-align:center; }
    </style>

    <div class="evapp-hdr">
      <p><small>
        <b>Header recomendado:</b> 1280×420 px (o ~3:1) · JPG/PNG · &lt; 400 KB.
      </small></p>

      <div class="preview" id="evapp_hdr_preview" style="<?php echo $url ? '' : 'display:none'; ?>">
        <img src="<?php echo esc_url($url ?: $fallback); ?>" alt="Preview header">
      </div>

      <input type="hidden" id="evapp_hdr_field" name="eventosapp_email_header_img" value="<?php echo esc_url($url); ?>">

      <div class="row">
        <button type="button" class="button" id="evapp_hdr_upload">Subir/Seleccionar</button>
        <button type="button" class="button" id="evapp_hdr_remove" <?php disabled(!$url); ?>>Quitar</button>
      </div>

      <p class="field">
        <label for="evapp_hdr_url"><b>o pegar URL:</b></label><br>
        <input type="url" id="evapp_hdr_url" placeholder="https://..." value="<?php echo esc_attr($url); ?>">
      </p>

      <hr>

      <p class="field">
        <label for="evapp_tpl_select"><b>Plantilla de correo</b></label>
        <select id="evapp_tpl_select" name="eventosapp_email_tpl">
          <?php
          if (empty($templates)) {
              echo '<option value="email-ticket.html" '.selected($tpl_selected, 'email-ticket.html', false).'>Email Ticket</option>';
          } else {
              foreach ($templates as $file => $label) {
                  echo '<option value="'.esc_attr($file).'" '.selected($tpl_selected, $file, false).'>'.esc_html($label).'</option>';
              }
          }
          ?>
        </select>
        <small>Los .html dentro de <code>/includes/templates/email_tickets/</code> aparecerán aquí.</small>
      </p>

      <p class="field">
        <label for="evapp_mail_subject"><b>Asunto del correo</b>
          <span class="evapp-help-tip" title="Opcional. Tokens: {{evento_nombre}}, {{asistente_nombre}}, {{ticket_id}}">?</span>
        </label>
        <input type="text" id="evapp_mail_subject" name="eventosapp_email_subject" placeholder="p.ej.: Tu acceso a {{evento_nombre}}" value="<?php echo esc_attr($subject); ?>">
      </p>

      <p class="field">
        <label for="evapp_from_name"><b>From name</b>
          <span class="evapp-help-tip" title="Nombre del remitente que verá el usuario. No cambia la dirección (usamos no-reply@tu-dominio).">?</span>
        </label>
        <input type="text" id="evapp_from_name" name="eventosapp_email_fromname" placeholder="p.ej.: EventosApp · Organización" value="<?php echo esc_attr($from_name); ?>">
      </p>

      <p class="field">
        <label for="evapp_extra_msg"><b>Mensaje adicional (opcional)</b></label>
        <textarea id="evapp_extra_msg" name="eventosapp_email_msg" placeholder="Escribe un aviso para los asistentes (se mostrará en un bloque especial en el correo)"><?php echo esc_textarea($extra_msg); ?></textarea>
      </p>

      <!-- ===================== -->
      <!-- NUEVO: Imagen del mensaje adicional -->
      <!-- ===================== -->
      <div class="evapp-msg-img-wrap">
        <label><b>Imagen del mensaje del organizador (opcional)</b>
          <span class="evapp-help-tip" title="Esta imagen aparecerá antes del texto del mensaje del organizador. Solo se mostrará si hay mensaje adicional.">?</span>
        </label>
        <p><small>Se mostrará solo si hay un mensaje adicional. Recomendado: JPG/PNG/GIF · &lt; 500 KB</small></p>
        
        <div class="preview" id="evapp_msg_img_preview" style="<?php echo $extra_img ? '' : 'display:none'; ?>">
          <img src="<?php echo esc_url($extra_img); ?>" alt="Preview mensaje">
        </div>

        <input type="hidden" id="evapp_msg_img_field" name="eventosapp_email_msg_img" value="<?php echo esc_url($extra_img); ?>">

        <div class="row">
          <button type="button" class="button" id="evapp_msg_img_upload">Subir/Seleccionar</button>
          <button type="button" class="button" id="evapp_msg_img_remove" <?php disabled(!$extra_img); ?>>Quitar</button>
        </div>

        <p style="margin-top:8px;margin-bottom:0;">
          <label for="evapp_msg_img_url"><b>o pegar URL:</b></label><br>
          <input type="url" id="evapp_msg_img_url" placeholder="https://..." value="<?php echo esc_attr($extra_img); ?>">
        </p>
      </div>
      <!-- /NUEVO: Imagen del mensaje adicional -->

      <p class="field">
        <label for="evapp_heading_color"><b>Color de los encabezados</b>
          <span class="evapp-help-tip" title="Aplica a: Nombre del evento, Detalles del evento, Tu ticket y Mensaje del organizador.">?</span>
        </label>
        <span class="color-wrap">
          <input type="text" id="evapp_heading_color" name="eventosapp_email_heading_color" value="<?php echo esc_attr($h_color); ?>" class="evapp-color-field" data-default-color="#1f2937">
        </span>
        <small>Si se deja vacío, se usa el color por defecto de la plantilla.</small>
      </p>

      <p><small>
        Si no eliges imagen o no agregas opciones, usaremos las predeterminadas del sistema.
      </small></p>

      <!-- ===================== -->
      <!-- NUEVO: Recordatorio   -->
      <!-- ===================== -->
      <div class="evapp-reminder">
        <h4>Recordatorio de Ticket</h4>

        <label style="display:block;margin-bottom:6px;">
          <input type="checkbox" id="evapp_reminder_enabled" name="eventosapp_reminder_enabled" value="1" <?php checked($rem_enable, '1'); ?>>
          <b>Habilitar recordatorio automático</b>
        </label>

        <div class="inline">
          <label for="evapp_reminder_amount" style="margin:0;"><b>Enviar</b></label>
          <input type="number" min="0" step="1" id="evapp_reminder_amount" name="eventosapp_reminder_amount" value="<?php echo esc_attr($rem_amount); ?>">
          <select id="evapp_reminder_unit" name="eventosapp_reminder_unit">
            <option value="minutes" <?php selected($rem_unit, 'minutes'); ?>>minutos</option>
            <option value="hours"   <?php selected($rem_unit, 'hours');   ?>>horas</option>
            <option value="days"    <?php selected($rem_unit, 'days');    ?>>días</option>
          </select>
          <span>antes del evento</span>
        </div>

        <div class="inline" style="margin-top:6px;">
          <label for="evapp_reminder_rate" style="margin:0;"><b>Ritmo por minuto</b></label>
          <input type="number" min="1" step="1" id="evapp_reminder_rate" name="eventosapp_reminder_rate" value="<?php echo esc_attr($rem_rate); ?>">
          <span>correos/min aprox.</span>
        </div>

        <span class="muted">Este recordatorio es independiente del envío automático del ticket. Solo se programará según esta configuración.</span>

        <?php
          $emoji        = '🔔';
          $event_title  = get_the_title($post);
          $rem_subject  = $emoji . ' RECORDATORIO: 🎟️ Hoy es el evento ' . $event_title;
        ?>
        <div class="preview-line"><strong>Asunto del recordatorio:</strong> <?php echo esc_html($rem_subject); ?></div>
        <span class="muted">El remitente (From) será: <strong><?php echo $from_name ? esc_html($from_name) : esc_html(get_bloginfo('name')); ?></strong></span>
      <!-- ===================================== -->
      <!-- Segmentación / filtros del recordatorio -->
      <!-- ===================================== -->
      <div class="evapp-reminder-filters">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
          <strong style="font-size:12px;">🎯 Segmentación (filtros)</strong>
          <button type="button" class="button button-small" id="evapp-add-rem-filter">+ Filtro</button>
        </div>
        <small style="color:#666;display:block;margin-bottom:7px;">
          Sin filtros → llega a <em>todos</em> los tickets con email válido.<br>
          Con filtros → solo a los tickets que cumplan <strong>todos</strong> (AND).
        </small>

        <div id="evapp-rem-filter-rows">
          <?php
          $no_val_ops = ['is_empty', 'is_not_empty'];
          foreach ( $rem_filters as $idx => $rf ) :
              $rf_field    = isset($rf['field'])    ? $rf['field']    : '';
              $rf_operator = isset($rf['operator']) ? $rf['operator'] : 'equals';
              $rf_value    = isset($rf['value'])    ? $rf['value']    : '';
          ?>
          <div class="evapp-rem-filter-row">
            <select name="evapp_rem_filters[<?php echo intval($idx); ?>][field]" class="evapp-rem-field">
              <?php foreach ( eventosapp_reminder_filter_fields() as $fkey => $flabel ) : ?>
                <option value="<?php echo esc_attr($fkey); ?>" <?php selected($rf_field, $fkey); ?>><?php echo esc_html($flabel); ?></option>
              <?php endforeach; ?>
            </select>
            <select name="evapp_rem_filters[<?php echo intval($idx); ?>][operator]" class="evapp-rem-operator">
              <?php foreach ( eventosapp_reminder_filter_operators() as $okey => $olabel ) : ?>
                <option value="<?php echo esc_attr($okey); ?>" <?php selected($rf_operator, $okey); ?>><?php echo esc_html($olabel); ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text"
                   name="evapp_rem_filters[<?php echo intval($idx); ?>][value]"
                   class="evapp-rem-value"
                   value="<?php echo esc_attr($rf_value); ?>"
                   placeholder="valor"
                   style="<?php echo in_array($rf_operator, $no_val_ops, true) ? 'display:none;' : ''; ?>">
            <button type="button" class="button button-small evapp-rem-del" style="color:#c00;" title="Eliminar filtro">✕</button>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <!-- /Segmentación filtros -->
      </div>
      <!-- /NUEVO: Recordatorio -->
    </div>

    <script>
    (function($){
      let frame;
      const $field   = $('#evapp_hdr_field');
      const $urlIn   = $('#evapp_hdr_url');
      const $prevBox = $('#evapp_hdr_preview');
      const $img     = $('#evapp_hdr_preview img');
      const $btnUp   = $('#evapp_hdr_upload');
      const $btnRm   = $('#evapp_hdr_remove');

      function setVal(url){
        $field.val(url || '');
        $urlIn.val(url || '');
        if(url){
          $img.attr('src', url);
          $prevBox.show();
          $btnRm.prop('disabled', false);
        }else{
          $prevBox.hide();
          $btnRm.prop('disabled', true);
        }
      }

      $btnUp.on('click', function(e){
        e.preventDefault();
        if(frame){ frame.open(); return; }
        frame = wp.media({
          title: 'Seleccionar imagen de encabezado',
          button: { text: 'Usar esta imagen' },
          library: { type: 'image' },
          multiple: false
        });
        frame.on('select', function(){
          const file = frame.state().get('selection').first().toJSON();
          setVal(file.url || '');
        });
        frame.open();
      });

      $btnRm.on('click', function(e){
        e.preventDefault();
        setVal('');
      });

      $urlIn.on('change blur', function(){
        setVal($(this).val().trim());
      });

      // ========================================
      // NUEVO: Media uploader para imagen del mensaje adicional
      // ========================================
      let frameMsgImg;
      const $msgImgField   = $('#evapp_msg_img_field');
      const $msgImgUrlIn   = $('#evapp_msg_img_url');
      const $msgImgPrevBox = $('#evapp_msg_img_preview');
      const $msgImgImg     = $('#evapp_msg_img_preview img');
      const $msgImgBtnUp   = $('#evapp_msg_img_upload');
      const $msgImgBtnRm   = $('#evapp_msg_img_remove');

      function setMsgImgVal(url){
        $msgImgField.val(url || '');
        $msgImgUrlIn.val(url || '');
        if(url){
          $msgImgImg.attr('src', url);
          $msgImgPrevBox.show();
          $msgImgBtnRm.prop('disabled', false);
        }else{
          $msgImgPrevBox.hide();
          $msgImgBtnRm.prop('disabled', true);
        }
      }

      $msgImgBtnUp.on('click', function(e){
        e.preventDefault();
        if(frameMsgImg){ frameMsgImg.open(); return; }
        frameMsgImg = wp.media({
          title: 'Seleccionar imagen del mensaje del organizador',
          button: { text: 'Usar esta imagen' },
          library: { type: 'image' },
          multiple: false
        });
        frameMsgImg.on('select', function(){
          const file = frameMsgImg.state().get('selection').first().toJSON();
          setMsgImgVal(file.url || '');
        });
        frameMsgImg.open();
      });

      $msgImgBtnRm.on('click', function(e){
        e.preventDefault();
        setMsgImgVal('');
      });

      $msgImgUrlIn.on('change blur', function(){
        setMsgImgVal($(this).val().trim());
      });
      // ========================================
      // /NUEVO: Media uploader para imagen del mensaje adicional
      // ========================================

      // Color picker
      $('#evapp_heading_color').wpColorPicker({
        palettes: ['#111827', '#1f2937', '#2563eb', '#dc2626', '#10b981', '#f59e0b', '#7c3aed']
      });

      // NUEVO: habilitar/deshabilitar campos de recordatorio segun checkbox
      function toggleReminderFields(){
        const on = $('#evapp_reminder_enabled').is(':checked');
        $('#evapp_reminder_amount, #evapp_reminder_unit, #evapp_reminder_rate').prop('disabled', !on);
      }
      $('#evapp_reminder_enabled').on('change', toggleReminderFields);
      toggleReminderFields();

        // =====================================
      // Filtros de segmentación del recordatorio
      // =====================================
      (function(){
        var remFilterIdx   = <?php echo max( count($rem_filters), 0 ); ?>;
        var remFields      = <?php echo json_encode( eventosapp_reminder_filter_fields() ); ?>;
        var remOps         = <?php echo json_encode( eventosapp_reminder_filter_operators() ); ?>;
        var noValueOps     = ['is_empty', 'is_not_empty'];

        function buildFieldOptions(selectedKey) {
          var out = '';
          $.each(remFields, function(k, v){
            out += '<option value="'+k+'"'+(k===selectedKey?' selected':'')+'>'+v+'</option>';
          });
          return out;
        }

        function buildOpOptions(selectedKey) {
          var out = '';
          $.each(remOps, function(k, v){
            out += '<option value="'+k+'"'+(k===selectedKey?' selected':'')+'>'+v+'</option>';
          });
          return out;
        }

        function buildFilterRow(idx) {
          return $(
            '<div class="evapp-rem-filter-row">' +
              '<select name="evapp_rem_filters['+idx+'][field]" class="evapp-rem-field">' + buildFieldOptions('') + '</select>' +
              '<select name="evapp_rem_filters['+idx+'][operator]" class="evapp-rem-operator">' + buildOpOptions('equals') + '</select>' +
              '<input type="text" name="evapp_rem_filters['+idx+'][value]" class="evapp-rem-value" placeholder="valor">' +
              '<button type="button" class="button button-small evapp-rem-del" style="color:#c00;" title="Eliminar">✕</button>' +
            '</div>'
          );
        }

        function syncValueVisibility($opSel) {
          var op       = $opSel.val();
          var $valInp  = $opSel.closest('.evapp-rem-filter-row').find('.evapp-rem-value');
          if (noValueOps.indexOf(op) >= 0) {
            $valInp.hide().val('');
          } else {
            $valInp.show();
          }
        }

        // Agregar fila
        $('#evapp-add-rem-filter').on('click', function(){
          var $row = buildFilterRow(remFilterIdx++);
          $('#evapp-rem-filter-rows').append($row);
          syncValueVisibility($row.find('.evapp-rem-operator'));
        });

        // Eliminar fila
        $(document).on('click', '.evapp-rem-del', function(){
          $(this).closest('.evapp-rem-filter-row').remove();
        });

        // Cambio de operador → mostrar/ocultar campo valor
        $(document).on('change', '.evapp-rem-operator', function(){
          syncValueVisibility($(this));
        });

        // Inicializar visibilidad en filas ya renderizadas
        $('.evapp-rem-filter-row .evapp-rem-operator').each(function(){
          syncValueVisibility($(this));
        });
      })();
      // /Filtros de segmentación

    })(jQuery);
    </script>
    <?php
}

/**
 * Guardado del metabox
 */
add_action('save_post_eventosapp_event', function($post_id){
    // Evitar autosaves y revisiones
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (!isset($_POST['eventosapp_email_header_nonce']) || !wp_verify_nonce($_POST['eventosapp_email_header_nonce'], 'eventosapp_email_header_guardar')) {
        return;
    }

    // Header image
    $url = isset($_POST['eventosapp_email_header_img']) ? trim(wp_unslash($_POST['eventosapp_email_header_img'])) : '';
    $url = esc_url_raw($url);
    if ($url) update_post_meta($post_id, '_eventosapp_email_header_img', $url);
    else      delete_post_meta($post_id, '_eventosapp_email_header_img');

    // Plantilla
    $tpl = isset($_POST['eventosapp_email_tpl']) ? basename(sanitize_text_field(wp_unslash($_POST['eventosapp_email_tpl']))) : '';
    $dir = eventosapp_email_templates_dir();
    $ok  = $tpl && is_readable($dir . $tpl);
    update_post_meta($post_id, '_eventosapp_email_tpl', $ok ? $tpl : 'email-ticket.html');

    // Asunto
    $subject = isset($_POST['eventosapp_email_subject']) ? wp_unslash($_POST['eventosapp_email_subject']) : '';
    $subject = wp_strip_all_tags($subject);
    if (strlen($subject) > 190) $subject = mb_substr($subject, 0, 190);
    if ($subject) update_post_meta($post_id, '_eventosapp_email_subject', $subject);
    else          delete_post_meta($post_id, '_eventosapp_email_subject');

    // From name
    $from = isset($_POST['eventosapp_email_fromname']) ? wp_unslash($_POST['eventosapp_email_fromname']) : '';
    $from = preg_replace("/[\r\n]+/u", ' ', trim($from));
    if (strlen($from) > 120) $from = mb_substr($from, 0, 120);
    if ($from) update_post_meta($post_id, '_eventosapp_email_fromname', $from);
    else       delete_post_meta($post_id, '_eventosapp_email_fromname');

    // Mensaje adicional (texto plano)
    $msg = isset($_POST['eventosapp_email_msg']) ? wp_unslash($_POST['eventosapp_email_msg']) : '';
    $msg = wp_strip_all_tags($msg);
    if ($msg) update_post_meta($post_id, '_eventosapp_email_msg', $msg);
    else      delete_post_meta($post_id, '_eventosapp_email_msg');

    // Imagen del mensaje adicional
    $msg_img = isset($_POST['eventosapp_email_msg_img']) ? trim(wp_unslash($_POST['eventosapp_email_msg_img'])) : '';
    $msg_img = esc_url_raw($msg_img);
    if ($msg_img) update_post_meta($post_id, '_eventosapp_email_msg_img', $msg_img);
    else          delete_post_meta($post_id, '_eventosapp_email_msg_img');

    // Color de encabezados
    $h_color = isset($_POST['eventosapp_email_heading_color']) ? wp_unslash($_POST['eventosapp_email_heading_color']) : '';
    $h_color = sanitize_hex_color($h_color);
    if ($h_color) update_post_meta($post_id, '_eventosapp_email_heading_color', $h_color);
    else          delete_post_meta($post_id, '_eventosapp_email_heading_color');

    // ========================
    // Recordatorio: habilitado
    // ========================
    $rem_enabled = isset($_POST['eventosapp_reminder_enabled']) ? '1' : '0';
    update_post_meta($post_id, '_eventosapp_reminder_enabled', $rem_enabled);

    $amount = isset($_POST['eventosapp_reminder_amount']) ? intval($_POST['eventosapp_reminder_amount']) : 0;
    if ($amount < 0) $amount = 0;
    if ($rem_enabled === '1') {
        update_post_meta($post_id, '_eventosapp_reminder_amount', $amount);
    } else {
        delete_post_meta($post_id, '_eventosapp_reminder_amount');
    }

    $unit = isset($_POST['eventosapp_reminder_unit']) ? sanitize_text_field($_POST['eventosapp_reminder_unit']) : 'hours';
    if (!in_array($unit, ['minutes','hours','days'], true)) $unit = 'hours';
    if ($rem_enabled === '1') {
        update_post_meta($post_id, '_eventosapp_reminder_unit', $unit);
    } else {
        delete_post_meta($post_id, '_eventosapp_reminder_unit');
    }

    $rate = isset($_POST['eventosapp_reminder_rate']) ? intval($_POST['eventosapp_reminder_rate']) : 0;
    if ($rate < 1) $rate = 0;
    if ($rem_enabled === '1' && $rate > 0) {
        if ($rate > 500) $rate = 500;
        update_post_meta($post_id, '_eventosapp_reminder_rate_per_minute', $rate);
    } else {
        delete_post_meta($post_id, '_eventosapp_reminder_rate_per_minute');
    }

    // =====================================================
    // NUEVO: Filtros de segmentación del recordatorio
    // =====================================================
    $raw_filters       = ( isset($_POST['evapp_rem_filters']) && is_array($_POST['evapp_rem_filters']) )
                         ? $_POST['evapp_rem_filters'] : [];
    $allowed_fields    = array_keys( eventosapp_reminder_filter_fields() );
    $allowed_operators = array_keys( eventosapp_reminder_filter_operators() );
    $clean_filters     = [];

    foreach ($raw_filters as $rf) {
        if (!is_array($rf)) continue;
        $field    = isset($rf['field'])    ? sanitize_key($rf['field'])                      : '';
        $operator = isset($rf['operator']) ? sanitize_key($rf['operator'])                   : '';
        $value    = isset($rf['value'])    ? sanitize_text_field(wp_unslash($rf['value']))   : '';

        if (!in_array($field,    $allowed_fields,    true)) continue;
        if (!in_array($operator, $allowed_operators, true)) continue;

        // Para operadores sin valor, forzar cadena vacía
        if (in_array($operator, ['is_empty','is_not_empty'], true)) $value = '';

        $clean_filters[] = compact('field', 'operator', 'value');
    }

    if (!empty($clean_filters)) {
        update_post_meta($post_id, '_eventosapp_reminder_filters', $clean_filters);
    } else {
        delete_post_meta($post_id, '_eventosapp_reminder_filters');
    }
    // /Filtros de segmentación

    // Programar/ajustar cron para el recordatorio de este evento
    if (function_exists('eventosapp_maybe_reschedule_event_reminder')) {
        eventosapp_maybe_reschedule_event_reminder($post_id);
    }
}, 25);
