<?php
// == Metabox: cURL de prueba del Webhook → EventosApp =========================
if ( ! defined('ABSPATH') ) exit;

/** Construye el JSON de ejemplo con campos base + extras reales del evento */
function eventosapp_build_ac_webhook_body_template($event_id){
    $body = [
        'event_id'    => (int) $event_id,
        'external_id' => '<<opcional: id_unico_envio>>',
        'email'       => '<<email>>',
        'first_name'  => '<<nombre>>',
        'last_name'   => '<<apellido>>',
        'cc'          => '<<cedula>>',
        'phone'       => '<<telefono>>',
        'company'     => '<<empresa>>',
        'nit'         => '<<nit>>',
        'cargo'       => '<<cargo>>',
        'city'        => '<<ciudad>>',
        'country'     => 'Colombia',
        'localidad'   => '<<localidad>>',
    ];

    // Extras del evento (si existen)
    if ( function_exists('eventosapp_get_event_extra_fields') ) {
        $schema = (array) eventosapp_get_event_extra_fields($event_id);
        if ($schema) {
            $body['eventosapp_extra'] = [];
            foreach ($schema as $fld) {
                if (empty($fld['key'])) continue;
                $k = (string) $fld['key'];
                $label = isset($fld['label']) && $fld['label'] !== '' ? $fld['label'] : $k;
                $body['eventosapp_extra'][$k] = '<<'.$label.'>>';
            }
        }
    }
    return $body;
}

/** Metabox que muestra la plantilla cURL + plantilla “enviar a cliente” */
function eventosapp_render_curl_template_metabox($post){
    wp_enqueue_script('jquery');

    $event_id   = (int) $post->ID;
    $body_arr   = eventosapp_build_ac_webhook_body_template($event_id);
    $json       = wp_json_encode($body_arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Para shell POSIX dentro de -d '...'
    $json_sh = str_replace("'", "'\"'\"'", $json);

    // Placeholders (editables)
    $placeholder_url_generic = "https://TU-DOMINIO/wp-json/eventosapp/v1/webhook";    // recomendado (agnóstico)
    $placeholder_url_legacy  = "https://TU-DOMINIO/wp-json/eventosapp/v1/ac-webhook"; // compat
    $placeholder_secret      = "PEGAR_AQUI_TU_SECRETO";

    // Valores reales del sitio
    $site_key = defined('EVENTOSAPP_INTAKE_KEY') ? EVENTOSAPP_INTAKE_KEY
                : ( function_exists('evapp_get_or_create_intake_key') ? evapp_get_or_create_intake_key() : '' );
    $site_url_gen    = home_url('/wp-json/eventosapp/v1/webhook');
    $site_url_legacy = home_url('/wp-json/eventosapp/v1/ac-webhook');
    ?>
    <style>
      .evapp-curl-wrap .description{margin:6px 0 10px;color:#666}
      .evapp-curl-wrap .codebox{width:100%;min-height:220px;font:12px/1.45 Menlo,Consolas,monospace;background:#0b1021;color:#e6edf3;border-radius:8px;border:1px solid #202b3d;padding:10px;white-space:pre;overflow:auto}
      .evapp-curl-wrap .toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:8px}
      .evapp-curl-wrap .group{margin-right:14px}
      .evapp-curl-wrap .variant{margin:0 10px 0 0}
      .evapp-curl-wrap input[type=text]{width:100%}
      .evapp-curl-wrap small.note{color:#888}
      .evapp-hidden{display:none}
    </style>

    <div class="evapp-curl-wrap" id="evapp-curl"
         data-site-url-generic="<?php echo esc_attr($site_url_gen); ?>"
         data-site-url-legacy="<?php echo esc_attr($site_url_legacy); ?>"
         data-site-key="<?php echo esc_attr($site_key); ?>">

      <p class="description">
        Esta herramienta genera:
        <br>• <strong>cURL (prueba)</strong> para testear el endpoint.
        <br>• <strong>Enviar a cliente</strong>: texto listo para copiar en un correo con instrucciones y el payload de ejemplo (con los campos de este evento).
      </p>

      <div class="toolbar">
        <div class="group">
          <strong>Modo:</strong>
          <label class="variant"><input type="radio" name="evapp_mode" value="curl" checked> cURL (prueba)</label>
          <label class="variant"><input type="radio" name="evapp_mode" value="client"> Enviar a cliente</label>
        </div>

        <div class="group">
          <strong>Endpoint:</strong>
          <label class="variant"><input type="radio" name="evapp_endpoint" value="generic" checked> <code>/webhook</code> (recomendado)</label>
          <label class="variant"><input type="radio" name="evapp_endpoint" value="legacy"> <code>/ac-webhook</code> (compatibilidad)</label>
        </div>

        <div class="group">
          <strong>Auth:</strong>
          <label class="variant"><input type="radio" name="evapp_auth" value="header" checked> Header <code>X-Webhook-Secret</code></label>
          <label class="variant"><input type="radio" name="evapp_auth" value="bearer"> <code>Authorization: Bearer</code></label>
          <label class="variant"><input type="radio" name="evapp_auth" value="xapikey"> Header <code>X-API-Key</code></label>
          <label class="variant"><input type="radio" name="evapp_auth" value="urlkey"> Parámetro <code>?key=</code></label>
        </div>

        <button type="button" class="button" id="evapp-curl-fill">Rellenar con valores del sitio</button>
        <button type="button" class="button button-primary" id="evapp-curl-copy">Copiar</button>
      </div>

      <p><strong>URL del webhook</strong> <small class="note">(puedes cambiarla)</small></p>
      <input type="text" id="evapp-curl-url" value="<?php echo esc_attr($placeholder_url_generic); ?>">

      <p style="margin-top:8px"><strong>Secreto</strong></p>
      <input type="text" id="evapp-curl-secret" value="<?php echo esc_attr($placeholder_secret); ?>">

      <p style="margin-top:12px;margin-bottom:6px"><strong>Salida</strong></p>

      <!-- cURL -->
      <textarea id="evapp-curl-code" class="codebox" readonly></textarea>

      <!-- Guía para cliente -->
      <textarea id="evapp-client-guide" class="codebox evapp-hidden" readonly></textarea>

      <!-- JSON literal para cada modo (ocultos) -->
      <textarea id="evapp-curl-json" class="evapp-hidden"><?php echo esc_textarea($json_sh); ?></textarea>
      <textarea id="evapp-json-raw" class="evapp-hidden"><?php echo esc_textarea($json); ?></textarea>

      <p class="description" style="margin-top:10px">
        Notas: <code>HTTP 403</code> = secreto inválido; <code>HTTP 400</code> = falta <code>event_id</code> o <code>email</code>.
      </p>
    </div>

    <script>
    (function($){
      var $box    = $('#evapp-curl');
      var $url    = $('#evapp-curl-url');
      var $sec    = $('#evapp-curl-secret');
      var $curl   = $('#evapp-curl-code');
      var $guide  = $('#evapp-client-guide');
      var $btnF   = $('#evapp-curl-fill');
      var $btnC   = $('#evapp-curl-copy');

      function cleanUrl(u){ return String(u||'').replace(/\?key=.*$/,''); }

      function buildCurl(url, secret, auth, bodyShell){
        var lines = [];
        lines.push("curl -X POST '"+url+"' \\");
        if (auth === 'header')       lines.push("  -H 'X-Webhook-Secret: "+secret+"' \\");
        else if (auth === 'bearer')  lines.push("  -H 'Authorization: Bearer "+secret+"' \\");
        else if (auth === 'xapikey') lines.push("  -H 'X-API-Key: "+secret+"' \\");
        lines.push("  -H 'Content-Type: application/json' \\");
        if (auth === 'urlkey') lines[0] = "curl -X POST '"+url+"?key="+secret+"' \\";
        lines.push("  -d '"+bodyShell+"'");
        return lines.join("\n");
      }

      function buildGuide(url, secret, auth, bodyRaw){
        var authLine  = "";
        var urlLine   = url;
        if (auth === 'urlkey') {
          urlLine = url + "?key=" + secret;
          authLine = "- No necesitas header de autenticación (la clave va en la URL).";
        } else if (auth === 'header') {
          authLine = "- Autenticación por header: X-Webhook-Secret: " + secret;
        } else if (auth === 'bearer') {
          authLine = "- Autenticación por header: Authorization: Bearer " + secret;
        } else if (auth === 'xapikey') {
          authLine = "- Autenticación por header: X-API-Key: " + secret;
        }

        var txt = [];
        txt.push("Webhook → EventosApp — Guía de integración");
        txt.push("");
        txt.push("1) Endpoint (POST):");
        txt.push("   " + urlLine);
        txt.push(authLine);
        txt.push("");
        txt.push("2) Formato del envío:");
        txt.push("- Preferido: JSON con Content-Type: application/json");
        txt.push("- Alternativo: application/x-www-form-urlencoded (la API lo acepta; en AC los campos suelen llamarse contact[email], contact[first_name], etc.)");
        txt.push("");
        txt.push("3) Campos mínimos:");
        txt.push("- event_id (ID numérico del evento en EventosApp)");
        txt.push("- email");
        txt.push("");
        txt.push("4) Campos recomendados (si los tienes):");
        txt.push("- first_name, last_name, phone, company, cc, nit, cargo, city, country, localidad");
        txt.push("- external_id (opcional para deduplicar si tu plataforma genera un ID del envío)");
        txt.push("- extras específicos del evento (usa la key exacta del campo, o eventosapp_extra[key])");
        txt.push("");
        txt.push("5) Ejemplo de JSON:");
        txt.push(bodyRaw);
        txt.push("");
        txt.push("Respuestas comunes:");
        txt.push("- 200 OK: recibido/actualizado.");
        txt.push("- 400 Bad Request: falta event_id o email.");
        txt.push("- 403 Forbidden: secreto inválido.");
        return txt.join("\n");
      }

      function rebuild(){
        var bodyShell = $('#evapp-curl-json').val() || '';
        var bodyRaw   = $('#evapp-json-raw').val() || '';
        var url       = cleanUrl($url.val());
        var secret    = ($sec.val() || '').trim();
        var auth      = $('input[name=evapp_auth]:checked').val();

        $curl.val( buildCurl(url, secret, auth, bodyShell) );
        $guide.val( buildGuide(url, secret, auth, bodyRaw) );
      }

      // Cambiar endpoint → propone URL adecuada si el campo sigue “cerca” del placeholder
      $('input[name=evapp_endpoint]').on('change', function(){
        var which = this.value;
        var current = $url.val();
        var gen  = $box.data('site-url-generic') || '';
        var leg  = $box.data('site-url-legacy') || '';
        if (/\/eventosapp\/v1\/(webhook|ac-webhook)$/.test(current) || /TU-DOMINIO/.test(current)) {
          $url.val(which === 'generic' ? (gen || 'https://TU-DOMINIO/wp-json/eventosapp/v1/webhook')
                                       : (leg || 'https://TU-DOMINIO/wp-json/eventosapp/v1/ac-webhook'));
        } else {
          $url.val(current.replace(/\/(ac-webhook|webhook)(\?.*)?$/i, '/'+ (which==='generic'?'webhook':'ac-webhook')));
        }
        rebuild();
      });

      // Cambiar modo (mostrar/ocultar áreas)
      $('input[name=evapp_mode]').on('change', function(){
        var mode = $('input[name=evapp_mode]:checked').val();
        if (mode === 'curl') { $curl.removeClass('evapp-hidden'); $guide.addClass('evapp-hidden'); }
        else { $guide.removeClass('evapp-hidden'); $curl.addClass('evapp-hidden'); }
      });

      // Autollenar con valores reales del sitio
      $btnF.on('click', function(){
        var which = $('input[name=evapp_endpoint]:checked').val();
        var su = which === 'generic' ? ($box.data('site-url-generic') || '') : ($box.data('site-url-legacy') || '');
        var sk = $box.data('site-key') || '';
        if (su) $url.val(su);
        if (sk) $sec.val(sk);
        rebuild();
      });

      // Recalcular al editar manualmente / cambiar auth
      $url.on('input', rebuild);
      $sec.on('input', rebuild);
      $('input[name=evapp_auth]').on('change', rebuild);

      // Copiar contenido del modo activo
      $btnC.on('click', function(){
        var mode = $('input[name=evapp_mode]:checked').val();
        var $src = (mode === 'curl') ? $curl : $guide;
        $src[0].select();
        document.execCommand('copy');
        $(this).text('¡Copiado!').prop('disabled', true);
        setTimeout(() => { $(this).text('Copiar'); $(this).prop('disabled', false); }, 900);
      });

      // Inicial
      rebuild();
    })(jQuery);
    </script>
    <?php
}

/** Registrar metabox en el editor del CPT */
add_action('add_meta_boxes', function(){
    add_meta_box(
        'eventosapp_curl_template',
        'Webhook → EventosApp: Prueba y Guía para cliente',
        'eventosapp_render_curl_template_metabox',
        'eventosapp_event',
        'normal',
        'high'
    );
}, 40);
