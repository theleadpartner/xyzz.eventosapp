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
    $tpl  = isset($_POST['eventosapp_email_tpl']) ? basename( sanitize_text_field( wp_unslash($_POST['eventosapp_email_tpl']) ) ) : '';
    $dir  = eventosapp_email_templates_dir();
    $ok   = $tpl && is_readable($dir . $tpl);
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

    // ==========================
    // NUEVO: Imagen del mensaje adicional
    // ==========================
    $msg_img = isset($_POST['eventosapp_email_msg_img']) ? trim(wp_unslash($_POST['eventosapp_email_msg_img'])) : '';
    $msg_img = esc_url_raw($msg_img);
    if ($msg_img) update_post_meta($post_id, '_eventosapp_email_msg_img', $msg_img);
    else          delete_post_meta($post_id, '_eventosapp_email_msg_img');

    // Color de encabezados
    $h_color = isset($_POST['eventosapp_email_heading_color']) ? wp_unslash($_POST['eventosapp_email_heading_color']) : '';
    $h_color = sanitize_hex_color($h_color);
    if ($h_color) update_post_meta($post_id, '_eventosapp_email_heading_color', $h_color);
    else          delete_post_meta($post_id, '_eventosapp_email_heading_color');

    // ==========================
    // NUEVO: Guardado recordatorio
    // ==========================
    $rem_enabled = isset($_POST['eventosapp_reminder_enabled']) ? '1' : '0';
    update_post_meta($post_id, '_eventosapp_reminder_enabled', $rem_enabled);

    $amount = isset($_POST['eventosapp_reminder_amount']) ? intval($_POST['eventosapp_reminder_amount']) : 0;
    if ($amount < 0) $amount = 0; // no negativos
    if ($rem_enabled === '1') {
        update_post_meta($post_id, '_eventosapp_reminder_amount', $amount);
    } else {
        delete_post_meta($post_id, '_eventosapp_reminder_amount');
    }

    $unit = isset($_POST['eventosapp_reminder_unit']) ? sanitize_text_field($_POST['eventosapp_reminder_unit']) : 'hours';
    $allowed = ['minutes','hours','days'];
    if (!in_array($unit, $allowed, true)) $unit = 'hours';
    if ($rem_enabled === '1') {
        update_post_meta($post_id, '_eventosapp_reminder_unit', $unit);
    } else {
        delete_post_meta($post_id, '_eventosapp_reminder_unit');
    }

    // NUEVO: ritmo por minuto
    $rate = isset($_POST['eventosapp_reminder_rate']) ? intval($_POST['eventosapp_reminder_rate']) : 0;
    if ($rate < 1) $rate = 0;
    if ($rem_enabled === '1' && $rate > 0) {
        // límite sano
        if ($rate > 500) $rate = 500;
        update_post_meta($post_id, '_eventosapp_reminder_rate_per_minute', $rate);
    } else {
        delete_post_meta($post_id, '_eventosapp_reminder_rate_per_minute');
    }

    // Programar/ajustar cron para el recordatorio de este evento
    if (function_exists('eventosapp_maybe_reschedule_event_reminder')) {
        eventosapp_maybe_reschedule_event_reminder($post_id);
    }

    // Nota: El asunto del recordatorio se define así (cuando se envíe el recordatorio):
    // '🔔 RECORDATORIO: Hoy es el evento ' . get_the_title($evento_id)
    // y el From name será el mismo de _eventosapp_email_fromname.
}, 25);
