<?php
/**
 * Autocompletar por API (global + override por evento) — dinámico por REGLAS
 */
if ( ! defined('ABSPATH') ) exit;

/* =========================
 *  Constantes / option keys
 * ========================= */
define('EVAPP_AC_OPTION', '_eventosapp_api_ac_global'); // option (array)
define('EVAPP_AC_TTL_DEFAULT', 600); // 10 minutos


/** ============================
 *  Gestor de secretos (webhook)
 * ============================ */

// Opción donde guardaremos el secreto para el webhook de AC
if (!defined('EVAPP_INTAKE_OPT')) {
    define('EVAPP_INTAKE_OPT', '_eventosapp_intake_key');
}

/** Genera un token seguro (base64url, sin =) */
function evapp_random_token($bytes = 48){
    try {
        $b = random_bytes($bytes);
    } catch (\Throwable $e) {
        $b = openssl_random_pseudo_bytes($bytes);
    }
    return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
}

/** Obtiene o crea (una vez) el secreto del webhook */
function evapp_get_or_create_intake_key(){
    $k = get_option(EVAPP_INTAKE_OPT, '');
    if (!$k) {
        $k = evapp_random_token(48);
        update_option(EVAPP_INTAKE_OPT, $k, false);
    }
    return $k;
}

/** Define la constante usada por el endpoint si no existe */
if (!defined('EVENTOSAPP_INTAKE_KEY')) {
    define('EVENTOSAPP_INTAKE_KEY', evapp_get_or_create_intake_key());
}


// Prefijo de los campos extra del formulario público (ajústalo si tu form usa otro)
if (!defined('EVAPP_PUBLIC_EXTRA_PREFIX')) {
    define('EVAPP_PUBLIC_EXTRA_PREFIX', 'eventosapp_extra');
}

// Helper UI: icono de ayuda con tooltip
function evapp_help($text){
    return '<span class="evapp-help" tabindex="0" aria-label="'.esc_attr($text).'" data-tip="'.esc_attr($text).'">?</span>';
}


/**
 * Devuelve un listado plano de "name" => "Etiqueta bonita (name)"
 * Incluye campos base y los "Campos adicionales del asistente" del evento.
 */
function eventosapp_ac_field_catalog($event_id = 0){
    // Campos base (ajusta/añade los que uses en tu formulario público)
    $fields = [
        'cc'                 => 'Cédula (cc)',
        'nit'                => 'NIT (nit)',
        'first_name'         => 'Nombre (first_name)',
        'last_name'          => 'Apellido (last_name)',
        'email'              => 'Email (email)',
        'phone'              => 'Teléfono (phone)',
        'company'            => 'Empresa (company)',
        'cargo'              => 'Cargo (cargo)',
        'city'               => 'Ciudad (city)',
        'country'            => 'País (country)',
        'localidad'          => 'Localidad (localidad)',

        // Variantes que a veces se usan como name en forms
        'asistente_nombre'   => 'Nombre (asistente_nombre)',
        'asistente_apellido' => 'Apellido (asistente_apellido)',
        'asistente_email'    => 'Email (asistente_email)',
        'asistente_tel'      => 'Teléfono (asistente_tel)',
        'asistente_empresa'  => 'Empresa (asistente_empresa)',
        'asistente_nit'      => 'NIT (asistente_nit)',
        'asistente_cargo'    => 'Cargo (asistente_cargo)',
        'asistente_ciudad'   => 'Ciudad (asistente_ciudad)',
        'asistente_pais'     => 'País (asistente_pais)',
        'asistente_localidad'=> 'Localidad (asistente_localidad)',
    ];

    // Extras dinámicos del evento
    if ($event_id && function_exists('eventosapp_get_event_extra_fields')) {
        foreach ((array) eventosapp_get_event_extra_fields($event_id) as $f) {
            if (empty($f['key'])) continue;
            $name = EVAPP_PUBLIC_EXTRA_PREFIX.'['.$f['key'].']'; // ej: eventosapp_extra[camisa_talla]
            $fields[$name] = 'Extra: '.$f['label'].' ('.$name.')';
        }
    }

    // Permite personalizar desde otros módulos/tema
    return apply_filters('eventosapp_ac_field_catalog', $fields, $event_id);
}

/* ==========================================
 *  Submenú admin: configuración global (JSON)
 * ========================================== */
add_action('admin_menu', function () {
    add_submenu_page(
        'eventosapp_dashboard',
        'Config API',
        'Config API',
        'manage_options',
        'eventosapp_api_autocomplete',
        'eventosapp_render_ac_global_page'
    );
}, 20);

function eventosapp_render_ac_global_page() {
    if ( ! current_user_can('manage_options') ) return;

    // Asegurar jQuery en esta página del admin
    wp_enqueue_script('jquery');


    // Guardado
    if ( isset($_POST['evapp_ac_nonce']) && wp_verify_nonce($_POST['evapp_ac_nonce'], 'evapp_ac_save') ) {
        // ¿Pidió regenerar el secreto del webhook?
        if ( !empty($_POST['evapp_regen_intake_key']) ) {
            if ( current_user_can('manage_options') ) {
                $new = evapp_random_token(48);
                update_option(EVAPP_INTAKE_OPT, $new, false);
                // La constante ya está definida para esta petición; tomará el nuevo valor en la siguiente carga
                echo '<div class="updated notice"><p>Se regeneró el secreto del webhook.</p></div>';
            }
        }

        $data = eventosapp_ac_sanitize_global_post($_POST);
        update_option(EVAPP_AC_OPTION, $data, false);
        echo '<div class="updated notice"><p>Configuración guardada.</p></div>';
    }

    $opt = get_option(EVAPP_AC_OPTION, []);
    $d = wp_parse_args($opt, [
        'enabled'     => '0',
        'base_url'    => '',
        'timeout'     => 10,
        'headers'     => [ [ 'name' => 'X-API-Key', 'value' => '' ] ],
        // Reglas en JSON (string) — para máxima flexibilidad
        'rules_json'  => eventosapp_ac_default_rules_json(),
    ]);

    ?>
    <div class="wrap">
      <h1>Configuración API (Global)</h1>
      <form method="post">
		      <?php
      $intake_key = evapp_get_or_create_intake_key();
      $hook_url   = home_url('/wp-json/eventosapp/v1/ac-webhook?key='.rawurlencode($intake_key));
    ?>
    <div class="postbox" style="padding:12px;margin:12px 0;border:1px solid #e2e8f0">
      <h2 style="margin-top:0">Webhook de ActiveCampaign → EventosApp</h2>
      <p class="description">Este secreto autentica las llamadas del webhook que envías desde ActiveCampaign a tu sitio.</p>

      <table class="form-table" role="presentation">
        <tr>
          <th scope="row">Secreto del webhook</th>
          <td>
            <input type="password" id="evapp-intake-key" class="regular-text" style="max-width:520px" value="<?php echo esc_attr($intake_key); ?>" readonly>
            <button type="button" class="button" id="evapp-toggle-intake">Ver/Ocultar</button>
            <button type="button" class="button" id="evapp-copy-intake">Copiar</button>
            <button type="submit" class="button button-secondary" name="evapp_regen_intake_key" value="1" onclick="return confirm('¿Regenerar el secreto? Deberás actualizarlo en ActiveCampaign.');">Regenerar</button>
            <p class="description">Si lo regeneras, recuerda actualizarlo en la Automation de ActiveCampaign.</p>
          </td>
        </tr>
        <tr>
          <th scope="row">URL del webhook (AC)</th>
          <td>
            <input type="text" id="evapp-hook-url" class="regular-text" style="width:100%;max-width:720px" value="<?php echo esc_attr($hook_url); ?>" readonly>
            <button type="button" class="button" id="evapp-copy-hook">Copiar URL</button>
            <p class="description">En tu Automation de AC, usa esta URL en la acción <em>Webhook</em>.<br>Alternativamente, puedes enviar el secreto por header: <code>X-Webhook-Secret: &lt;este mismo valor&gt;</code>.</p>
          </td>
        </tr>
      </table>

      <script>
        (function($){
          $('#evapp-toggle-intake').on('click', function(){
            var $f = $('#evapp-intake-key');
            $f.attr('type', $f.attr('type') === 'password' ? 'text' : 'password');
          });
          $('#evapp-copy-intake').on('click', function(){
            var $f=$('#evapp-intake-key'); $f[0].select(); document.execCommand('copy');
          });
          $('#evapp-copy-hook').on('click', function(){
            var $f=$('#evapp-hook-url'); $f[0].select(); document.execCommand('copy');
          });
        })(jQuery);
      </script>
    </div>

		  
        <?php wp_nonce_field('evapp_ac_save', 'evapp_ac_nonce'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Habilitar global <?php echo evapp_help("Lo pones TÚ (admin).\nEnciende el autocompletar para todos los eventos, salvo que un evento lo sobreescriba."); ?></th>

            <td><label><input type="checkbox" name="enabled" value="1" <?php checked($d['enabled'], '1'); ?>> Activar autocompletar global</label></td>
          </tr>
          <tr>
            <th scope="row">Base URL <?php echo evapp_help("Lo pone el admin o integrador.\nURL base de tu API: si el Endpoint es una ruta, se concatena a esta base."); ?></th>

            <td><input type="url" class="regular-text" name="base_url" value="<?php echo esc_attr($d['base_url']); ?>" placeholder="https://api.tu-dominio.com/v1"></td>
          </tr>
          <tr>
            <th scope="row">Timeout <?php echo evapp_help("Lo pone el admin.\nSegundos máximos de espera de la API antes de abortar."); ?></th>

            <td><input type="number" min="3" max="30" name="timeout" value="<?php echo esc_attr($d['timeout']); ?>"> <span class="description">segundos</span></td>
          </tr>
          <tr>
            <th scope="row">Headers (globales) <?php echo evapp_help("Lo pone el admin/integración.\nSe envían en TODAS las reglas (p.ej. X-API-Key, Authorization)."); ?></th>

            <td>
              <div id="evapp-headers-wrap">
                <?php
                $headers = is_array($d['headers']) ? $d['headers'] : [];
                if (empty($headers)) $headers = [ [ 'name'=>'X-API-Key', 'value'=>'' ] ];
                foreach ($headers as $i => $h) {
                  $n = isset($h['name']) ? $h['name'] : '';
                  $v = isset($h['value']) ? $h['value'] : '';
                  echo '<div style="margin-bottom:6px">';
                  echo 'Nombre: <input type="text" name="headers['.$i.'][name]" value="'.esc_attr($n).'" placeholder="X-API-Key"> ';
                  echo 'Valor: <input type="text" name="headers['.$i.'][value]" value="'.esc_attr($v).'" style="min-width:280px" placeholder="...">';
                  echo '</div>';
                }
                ?>
              </div>
              <button type="button" class="button" id="evapp-add-header">Agregar header</button>
              <script>
              (function($){
                $('#evapp-add-header').on('click', function(){
                  var i = $('#evapp-headers-wrap > div').length;
                  $('#evapp-headers-wrap').append(
                    '<div style="margin-bottom:6px">Nombre: <input type="text" name="headers['+i+'][name]" placeholder="X-API-Key"> '+
                    'Valor: <input type="text" name="headers['+i+'][value]" style="min-width:280px" placeholder="..."></div>'
                  );
                });
              })(jQuery);
              </script>
            </td>
          </tr>
        </table>

        <h2 class="title">Reglas</h2>
        <p class="description">Crea tantas reglas como necesites. No es necesario editar JSON.</p>
        <?php
          $rules_current = eventosapp_ac_decode_rules($d['rules_json']);
          eventosapp_ac_render_rules_builder('rules', $rules_current); // sin $event_id (catálogo base)
        ?>
        <p class="submit"><button class="button button-primary">Guardar cambios</button></p>
      </form>
    </div>
    <?php
}

function eventosapp_ac_default_rules_json() {
    // Sin reglas por defecto (se mostrará una fila vacía en el builder).
    return wp_json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function eventosapp_ac_sanitize_global_post($src) {
    $out = [];
    $out['enabled']  = !empty($src['enabled']) ? '1' : '0';
    $out['base_url'] = esc_url_raw( trim((string)($src['base_url'] ?? '')) );
    $out['timeout']  = max(3, min(30, intval($src['timeout'] ?? 10)));

    $out['headers'] = [];
    if ( isset($src['headers']) && is_array($src['headers']) ) {
        foreach ($src['headers'] as $h) {
            $name = sanitize_text_field($h['name'] ?? '');
            $val  = sanitize_text_field($h['value'] ?? '');
            if ($name !== '') $out['headers'][] = ['name'=>$name, 'value'=>$val];
        }
    }

    // Si viene del builder (preferido)
    if (!empty($src['rules']) && is_array($src['rules'])) {
        $rules = eventosapp_ac_rules_from_post($src, 'rules');
        $out['rules_json'] = wp_json_encode(eventosapp_ac_normalize_rules($rules), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return $out; // ya terminamos
    }

    // Respaldo: textarea JSON
    $json = (string)($src['rules_json'] ?? '');
    $try  = json_decode( stripslashes($json), true );
    if ( is_array($try) ) {
        $out['rules_json'] = wp_json_encode( eventosapp_ac_normalize_rules($try), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    } else {
        $out['rules_json'] = $json;
    }
    return $out;
}

/* =============================================
 *  Metabox por evento (override: base + reglas)
 * ============================================= */
add_action('add_meta_boxes', function(){
    add_meta_box(
        'eventosapp_api_event_rules',
        'Configuración de API (este evento)',
        'eventosapp_render_ac_event_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
});

function eventosapp_render_ac_event_metabox($post) {
    // Asegurar jQuery en el editor del evento
    wp_enqueue_script('jquery');

    wp_nonce_field('evapp_ac_event_save', 'evapp_ac_event_nonce');


    $enabled  = get_post_meta($post->ID, '_evapp_ac_enabled', true) === '1' ? '1' : '0';
    $base_url = get_post_meta($post->ID, '_evapp_ac_base_url', true);
    $timeout  = intval(get_post_meta($post->ID, '_evapp_ac_timeout', true));
    if (!$timeout) $timeout = 10;
    $headers  = get_post_meta($post->ID, '_evapp_ac_headers', true);
    if (!is_array($headers)) $headers = [];

    $rules_json = get_post_meta($post->ID, '_evapp_ac_rules_json', true);
    ?>
    <p>
      <label>
  <input type="checkbox" name="evapp_ac_enabled" value="1" <?php checked($enabled, '1'); ?>>
  <strong>Usar configuración propia para este evento</strong>
  <?php echo evapp_help("Lo pone el organizador del evento o admin.\nActiva overrides; si está apagado se usan los valores globales."); ?>
</label>
<br>
      <span class="description">Si está desmarcado, se usará la configuración global.</span>
    </p>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row">Base URL (override) <?php echo evapp_help("Lo pone organizador/admin.\nSobrescribe la Base URL global para este evento."); ?></th>

        <td><input type="url" class="regular-text" name="evapp_ac_base_url" value="<?php echo esc_attr($base_url); ?>" placeholder="https://api.tu-dominio.com/v1"></td>
      </tr>
      <tr>
        <th scope="row">Timeout <?php echo evapp_help("Lo pone organizador/admin.\nTiempo de espera de esta integración sólo para este evento."); ?></th>

        <td><input type="number" min="3" max="30" name="evapp_ac_timeout" value="<?php echo esc_attr($timeout); ?>"> <span class="description">segundos</span></td>
      </tr>
      <tr>
        <th scope="row">Headers (override) <?php echo evapp_help("Lo pone organizador/admin.\nHeaders adicionales o diferentes para este evento; se suman/reemplazan a los globales."); ?></th>

        <td>
          <div id="evapp-ev-headers">
            <?php
            if (empty($headers)) $headers = [ [ 'name'=>'X-API-Key', 'value'=>'' ] ];
            foreach ($headers as $i => $h) {
              $n = isset($h['name']) ? $h['name'] : '';
              $v = isset($h['value']) ? $h['value'] : '';
              echo '<div style="margin-bottom:6px">';
              echo 'Nombre: <input type="text" name="ev_headers['.$i.'][name]" value="'.esc_attr($n).'"> ';
              echo 'Valor: <input type="text" name="ev_headers['.$i.'][value]" value="'.esc_attr($v).'" style="min-width:280px">';
              echo '</div>';
            }
            ?>
          </div>
          <button type="button" class="button" id="evapp-ev-add-header">Agregar header</button>
          <script>
          (function($){
            $('#evapp-ev-add-header').on('click', function(){
              var i = $('#evapp-ev-headers > div').length;
              $('#evapp-ev-headers').append(
                '<div style="margin-bottom:6px">Nombre: <input type="text" name="ev_headers['+i+'][name]"> '+
                'Valor: <input type="text" name="ev_headers['+i+'][value]" style="min-width:280px"></div>'
              );
            });
          })(jQuery);
          </script>
        </td>
      </tr>
    </table>

    <h3 class="title">Reglas (override)</h3>
    <p class="description">Opcional. Si lo dejas vacío se usarán las reglas globales.</p>
    <?php
      $rules_event = eventosapp_ac_decode_rules($rules_json ?: '');
      // IMPORTANTE: pasar $post->ID para que el catálogo incluya los campos extra de ESTE evento
      eventosapp_ac_render_rules_builder('ev_rules', $rules_event, $post->ID);
    ?>
    <?php
}

add_action('save_post_eventosapp_event', function($post_id){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if ( ! isset($_POST['evapp_ac_event_nonce']) || ! wp_verify_nonce($_POST['evapp_ac_event_nonce'], 'evapp_ac_event_save') ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;

    update_post_meta($post_id, '_evapp_ac_enabled', !empty($_POST['evapp_ac_enabled']) ? '1' : '0');
    update_post_meta($post_id, '_evapp_ac_base_url', esc_url_raw($_POST['evapp_ac_base_url'] ?? ''));
    update_post_meta($post_id, '_evapp_ac_timeout', max(3, min(30, intval($_POST['evapp_ac_timeout'] ?? 10))));

    // Headers
    $headers = [];
    if (isset($_POST['ev_headers']) && is_array($_POST['ev_headers'])) {
        foreach ($_POST['ev_headers'] as $h) {
            $name = sanitize_text_field($h['name'] ?? '');
            $val  = sanitize_text_field($h['value'] ?? '');
            if ($name !== '') $headers[] = ['name'=>$name, 'value'=>$val];
        }
    }
    update_post_meta($post_id, '_evapp_ac_headers', $headers);

    // Reglas (builder preferido)
    if ( ! empty($_POST['ev_rules']) && is_array($_POST['ev_rules']) ) {
        $rules = eventosapp_ac_rules_from_post($_POST, 'ev_rules');
        $json  = wp_json_encode( eventosapp_ac_normalize_rules($rules), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        update_post_meta($post_id, '_evapp_ac_rules_json', $json);
    } else {
        // Respaldo: textarea manual (si existiera)
        $json = (string)($_POST['evapp_ac_rules_json'] ?? '');
        $try  = json_decode( stripslashes($json), true );
        if ( is_array($try) ) {
            $json = wp_json_encode( eventosapp_ac_normalize_rules($try), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        }
        update_post_meta($post_id, '_evapp_ac_rules_json', $json);
    }
}, 30);

/* =========================
 *  Helpers de configuración
 * ========================= */
function eventosapp_ac_get_effective_config($event_id) {
    $global = get_option(EVAPP_AC_OPTION, []);
    $g = wp_parse_args($global, [
        'enabled'=>'0',
        'base_url'=>'',
        'timeout'=>10,
        'headers'=>[],
        'rules_json'=>eventosapp_ac_default_rules_json(),
    ]);

    $enabled = get_post_meta($event_id, '_evapp_ac_enabled', true) === '1';
    if ($enabled) {
        $cfg = [
            'enabled'  => '1',
            'base_url' => get_post_meta($event_id, '_evapp_ac_base_url', true) ?: $g['base_url'],
            'timeout'  => intval(get_post_meta($event_id, '_evapp_ac_timeout', true) ?: $g['timeout']),
            'headers'  => get_post_meta($event_id, '_evapp_ac_headers', true),
        ];
        if (!is_array($cfg['headers'])) $cfg['headers'] = [];
        $rules_json = get_post_meta($event_id, '_evapp_ac_rules_json', true);
        $cfg['rules'] = eventosapp_ac_decode_rules($rules_json ?: $g['rules_json']);
        return $cfg;
    }

    return [
        'enabled'  => $g['enabled'],
        'base_url' => $g['base_url'],
        'timeout'  => $g['timeout'],
        'headers'  => $g['headers'],
        'rules'    => eventosapp_ac_decode_rules($g['rules_json']),
    ];
}

function eventosapp_ac_decode_rules($json) {
    $arr = json_decode( (string)$json, true );
    if (!is_array($arr)) return [];
    return eventosapp_ac_normalize_rules($arr);
}

function eventosapp_ac_normalize_rules(array $rules) {
    $out = [];
    foreach ($rules as $r) {
        $rule = [];

        $rule['id']            = sanitize_key($r['id'] ?? '');
        if (!$rule['id']) continue;
        $rule['label']         = sanitize_text_field($r['label'] ?? $rule['id']);
        $rule['enabled']       = !empty($r['enabled']);
        $rule['context']       = sanitize_key($r['context'] ?? 'public_register');
        $rule['trigger_field'] = preg_replace('/[^a-zA-Z0-9_\-\[\]]/','', (string)($r['trigger_field'] ?? ''));
        $rule['trigger_event'] = in_array(($r['trigger_event'] ?? 'blur'), ['blur','change','input'], true) ? $r['trigger_event'] : 'blur';
        $rule['min_length']    = max(1, intval($r['min_length'] ?? 1));
        $rule['debounce']      = max(0, intval($r['debounce'] ?? 300));
        $rule['method']        = strtoupper( (string)($r['method'] ?? 'GET') );
        if (!in_array($rule['method'], ['GET','POST'], true)) $rule['method'] = 'GET';
        $rule['endpoint']      = sanitize_text_field($r['endpoint'] ?? '');

        // --- params: lista [{key,value}] -> asociativo [key=>value]
        $rule['params'] = [];
        if (!empty($r['params']) && is_array($r['params'])) {
            $is_list = isset($r['params'][0]) && is_array($r['params'][0]) && array_key_exists('key', $r['params'][0]);
            if ($is_list) {
                foreach ($r['params'] as $p) {
                    $k = sanitize_text_field($p['key'] ?? '');
                    $v = sanitize_text_field($p['value'] ?? '');
                    if ($k !== '') $rule['params'][$k] = $v;
                }
            } else {
                foreach ($r['params'] as $k => $v) {
                    $kk = sanitize_text_field((string)$k);
                    $vv = sanitize_text_field((string)$v);
                    if ($kk !== '') $rule['params'][$kk] = $vv;
                }
            }
        }

        // --- headers: lista [{name,value}]
        $rule['headers'] = [];
        if (!empty($r['headers']) && is_array($r['headers'])) {
            foreach ($r['headers'] as $h) {
                $name = sanitize_text_field($h['name'] ?? '');
                $val  = sanitize_text_field($h['value'] ?? '');
                if ($name !== '') $rule['headers'][] = ['name'=>$name, 'value'=>$val];
            }
        }

        // --- map: lista [{target,path}] -> asociativo [target=>path]
        $clean_map = [];
        if (!empty($r['map']) && is_array($r['map'])) {
            $is_list = isset($r['map'][0]) && is_array($r['map'][0]) && array_key_exists('target', $r['map'][0]);
            if ($is_list) {
                foreach ($r['map'] as $m) {
                    $t = preg_replace('/[^a-zA-Z0-9_\-\[\]]/','', (string)($m['target'] ?? ''));
                    $p = sanitize_text_field($m['path'] ?? '');
                    if ($t !== '' && $p !== '') $clean_map[$t] = $p;
                }
            } else {
                foreach ($r['map'] as $target => $path) {
                    $t = preg_replace('/[^a-zA-Z0-9_\-\[\]]/','', (string)$target);
                    $clean_map[$t] = sanitize_text_field((string)$path);
                }
            }
        }
        $rule['map']       = $clean_map;
        $rule['fill_mode'] = ( ($r['fill_mode'] ?? 'only_empty') === 'overwrite' ) ? 'overwrite' : 'only_empty';
        $rule['cache_ttl'] = max(0, intval($r['cache_ttl'] ?? EVAPP_AC_TTL_DEFAULT));

        $out[] = $rule;
    }
    return $out;
}

/* =========================
 *  AJAX: ejecutar una regla
 * ========================= */
add_action('wp_ajax_eventosapp_lookup_rule',        'eventosapp_ac_ajax_lookup');
add_action('wp_ajax_nopriv_eventosapp_lookup_rule', 'eventosapp_ac_ajax_lookup');

function eventosapp_ac_ajax_lookup() {
    check_ajax_referer('evapp_lookup', 'security');

    $event_id = absint($_POST['event_id'] ?? 0);
    $rule_id  = sanitize_key($_POST['rule'] ?? '');
    $state_in = isset($_POST['state']) && is_array($_POST['state']) ? $_POST['state'] : [];

    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
        wp_send_json_error(['message'=>'Evento inválido'], 400);
    }

    $cfg = eventosapp_ac_get_effective_config($event_id);
    if (empty($cfg['enabled']) || $cfg['enabled'] !== '1') {
        wp_send_json_error(['message'=>'Autocompletar deshabilitado'], 403);
    }

    // Normalizar estado (clave cruda + clave saneada)
    $state = [];
    foreach ($state_in as $k => $v) {
        $val = is_scalar($v) ? sanitize_text_field(wp_unslash($v)) : '';
        $state[$k] = $val;                    // permite {{asistente[email]}}, {{eventosapp_extra[foo]}}
        $state[sanitize_key($k)] = $val;      // compat: {{asistenteemail}}, {{eventosapp_extrafoo}}
    }
    $state['event_id'] = (string)$event_id;

    // Buscar regla
    $rule = null;
    foreach ((array)$cfg['rules'] as $r) {
        if (!empty($r['enabled']) && $r['id'] === $rule_id && ($r['context'] ?? 'public_register') === 'public_register') {
            $rule = $r; break;
        }
    }
    if (!$rule) {
        wp_send_json_error(['message'=>'Regla no encontrada o deshabilitada'], 404);
    }

    // Construir URL
    $url = eventosapp_ac_apply_tpl($rule['endpoint'], $state);
    if ($url && !preg_match('~^https?://~i', $url)) {
        $base = rtrim((string)$cfg['base_url'], '/');
        $url  = $base ? $base.'/'.ltrim($url,'/') : $url;
    }
    if (!$url) wp_send_json_error(['message'=>'Endpoint inválido'], 400);

    // Construir headers (globales + regla)
    $headers = [];
    if (!empty($cfg['headers'])) {
        foreach ($cfg['headers'] as $h) {
            if (!empty($h['name'])) $headers[$h['name']] = $h['value'];
        }
    }
    if (!empty($rule['headers'])) {
        foreach ($rule['headers'] as $h) {
            if (!empty($h['name'])) $headers[$h['name']] = $h['value'];
        }
    }

    // Cache
    $cache_key = 'evapp_ac_'.md5($event_id.'|'.$rule['id'].'|'.wp_json_encode($state));
    if ($rule['cache_ttl'] > 0) {
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            wp_send_json_success(['fields'=>$cached]);
        }
    }

    // Preparar params/body
    $timeout = max(3, intval($cfg['timeout'] ?? 10));
    $resp    = null;
    if ($rule['method'] === 'POST') {
        $body = [];
        foreach ((array)$rule['params'] as $k => $tpl) {
            $body[$k] = eventosapp_ac_apply_tpl((string)$tpl, $state);
        }
        if (!isset($headers['Content-Type'])) $headers['Content-Type'] = 'application/json; charset=utf-8';
        $resp = wp_remote_post($url, [
            'headers' => $headers,
            'timeout' => $timeout,
            'body'    => wp_json_encode($body),
        ]);
    } else { // GET
        $query = [];
        foreach ((array)$rule['params'] as $k => $tpl) {
            $val = eventosapp_ac_apply_tpl((string)$tpl, $state);
            if ($val !== '' && $val !== null) $query[$k] = $val;
        }
        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }
        $resp = wp_remote_get($url, [
            'headers' => $headers,
            'timeout' => $timeout,
        ]);
    }

    if ( is_wp_error($resp) ) {
        wp_send_json_error(['message'=>'Error de red: '.$resp->get_error_message()], 502);
    }
    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code >= 400) {
        wp_send_json_error(['message'=>'HTTP '.$code,'body'=>$body], $code);
    }
    $json = json_decode($body, true);
    if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error(['message'=>'Respuesta no es JSON válido'], 500);
    }

    // Mapear -> campos del formulario
    $fields = [];
    foreach ((array)$rule['map'] as $target => $path) {
        $val = eventosapp_ac_dot_get($json, (string)$path);
        if (is_scalar($val)) {
            $fields[$target] = (string)$val;
        }
    }

    if ($rule['cache_ttl'] > 0) {
        set_transient($cache_key, $fields, $rule['cache_ttl']);
    }

    wp_send_json_success(['fields'=>$fields, 'fill_mode'=>$rule['fill_mode']]);
}

/* ===============
 *  Utilidades
 * =============== */
function eventosapp_ac_apply_tpl($tpl, array $state) {
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\-\[\]]+)\s*\}\}/', function($m) use ($state){
        $k = $m[1];
        return isset($state[$k]) ? $state[$k] : '';
    }, (string)$tpl);
}
function eventosapp_ac_dot_get($arr, $path) {
    if ($path === '' || $path === null) return null;
    $cur = $arr;
    foreach (explode('.', (string)$path) as $seg) {
        if ($seg === '') continue;
        if (is_array($cur) && array_key_exists($seg, $cur)) $cur = $cur[$seg];
        else return null;
    }
    return $cur;
}

/* ============================================================
 *  Frontend helper: inyecta JS que engancha reglas al formulario
 * ============================================================ */
function eventosapp_enqueue_lookup_js($event_id, $form_selector = '.evapp-public-form') {
    $cfg = eventosapp_ac_get_effective_config($event_id);
    if (empty($cfg['enabled']) || $cfg['enabled'] !== '1') return;

    $rules = [];
    foreach ((array)$cfg['rules'] as $r) {
        if (!empty($r['enabled']) && ($r['context'] ?? 'public_register') === 'public_register') {
            $rules[] = [
                'id'            => $r['id'],
                'trigger_field' => $r['trigger_field'],
                'trigger_event' => $r['trigger_event'],
                'min_length'    => $r['min_length'],
                'debounce'      => $r['debounce'],
                'fill_mode'     => $r['fill_mode'],
            ];
        }
    }
    if (empty($rules)) return;

    wp_enqueue_script('jquery');
    wp_register_script('eventosapp-ac-js', false, ['jquery'], null, true);
    wp_localize_script('eventosapp-ac-js', 'EvLookup', [
        'ajax_url'      => admin_url('admin-ajax.php'),
        'nonce'         => wp_create_nonce('evapp_lookup'),
        'event_id'      => $event_id,
        'form_selector' => $form_selector,
        'rules'         => $rules,
    ]);

    $inline = <<<JS
jQuery(function($){
  var cfg = window.EvLookup || {};
  var \$form = $(cfg.form_selector || '.evapp-public-form');
  if (!\$form.length || !Array.isArray(cfg.rules)) return;

  function getState(){
    var o = {};
    \$form.find('input[name],select[name],textarea[name]').each(function(){
      var \$el = $(this), n = \$el.attr('name'), v = \$el.val();
      if (/\[\]$/.test(n)) return;
      o[n] = (v==null?'':String(v));
    });
    return o;
  }

  function attachRule(r){
    if (!r || !r.trigger_field) return;
    var \$input = \$form.find('[name="'+r.trigger_field+'"]');
    if (!\$input.length) return;
    var eventName = r.trigger_event || 'blur';
    var minLen    = r.min_length || 1;
    var timer;

    \$input.on(eventName, function(){
      var val = (\$(this).val() || '').trim();
      if (val.length < minLen) return;
      clearTimeout(timer);
      timer = setTimeout(function(){
        var state = getState();
        $.post(cfg.ajax_url, {
          action: 'eventosapp_lookup_rule',
          security: cfg.nonce,
          event_id: cfg.event_id,
          rule: r.id,
          state: state
        }, function(res){
          if (res && res.success && res.data && res.data.fields){
            var fields = res.data.fields || {};
            var mode   = res.data.fill_mode || r.fill_mode || 'only_empty';
            Object.keys(fields).forEach(function(name){
              var val = fields[name];
              var \$f = \$form.find('[name="'+name+'"]');
              if (!\$f.length || val===undefined || val===null) return;
              if (mode==='only_empty' && \$f.val()) return;
              \$f.val(String(val)).trigger('change');
            });
          }
        }, 'json');
      }, r.debounce || 300);
    });
  }

  cfg.rules.forEach(attachRule);
});
JS;
    wp_add_inline_script('eventosapp-ac-js', $inline);
    wp_enqueue_script('eventosapp-ac-js');
}

/** Defaults para una regla nueva en el builder */
function eventosapp_ac_rule_defaults() {
    return [
        'id'            => '',
        'label'         => '',
        'enabled'       => true,
        'context'       => 'public_register',
        'trigger_field' => '',
        'trigger_event' => 'blur',
        'min_length'    => 5,
        'debounce'      => 300,
        'method'        => 'GET',
        'endpoint'      => '',
        'params'        => [],
        'headers'       => [],
        'map'           => [],
        'fill_mode'     => 'only_empty',
        'cache_ttl'     => EVAPP_AC_TTL_DEFAULT,
    ];
}

/** Convierte POST (arrays) → arreglo de reglas */
function eventosapp_ac_rules_from_post(array $src, string $key){
    if (empty($src[$key]) || !is_array($src[$key])) return [];
    $rules = [];
    foreach ($src[$key] as $row) {
        $r = eventosapp_ac_rule_defaults();

        $r['id']            = sanitize_key($row['id'] ?? '');
        if (!$r['id']) continue; // id obligatorio
        $r['label']         = sanitize_text_field($row['label'] ?? $r['id']);
        $r['enabled']       = !empty($row['enabled']);
        $r['context']       = sanitize_key($row['context'] ?? 'public_register');
        $r['trigger_field'] = preg_replace('/[^a-zA-Z0-9_\-\[\]]/','', (string)($row['trigger_field'] ?? ''));
        $r['trigger_event'] = in_array(($row['trigger_event'] ?? 'blur'), ['blur','change','input'], true) ? $row['trigger_event'] : 'blur';
        $r['min_length']    = max(1, intval($row['min_length'] ?? 1));
        $r['debounce']      = max(0, intval($row['debounce'] ?? 300));
        $r['method']        = strtoupper( (string)($row['method'] ?? 'GET') );
        if (!in_array($r['method'], ['GET','POST'], true)) $r['method'] = 'GET';
        $r['endpoint']      = sanitize_text_field($row['endpoint'] ?? '');

        // params
        $r['params'] = [];
        if (!empty($row['params']) && is_array($row['params'])) {
            foreach ($row['params'] as $p) {
                $k = sanitize_text_field($p['key'] ?? '');
                $v = sanitize_text_field($p['value'] ?? '');
                if ($k !== '') $r['params'][] = ['key'=>$k, 'value'=>$v];
            }
        }

        // headers
        $r['headers'] = [];
        if (!empty($row['headers']) && is_array($row['headers'])) {
            foreach ($row['headers'] as $h) {
                $n = sanitize_text_field($h['name'] ?? '');
                $v = sanitize_text_field($h['value'] ?? '');
                if ($n !== '') $r['headers'][] = ['name'=>$n, 'value'=>$v];
            }
        }

        // map
        $r['map'] = [];
        if (!empty($row['map']) && is_array($row['map'])) {
            foreach ($row['map'] as $m) {
                $t = preg_replace('/[^a-zA-Z0-9_\-\[\]]/', '', (string)($m['target'] ?? ''));
                $p = sanitize_text_field($m['path'] ?? '');
                if ($t !== '' && $p !== '') $r['map'][] = ['target'=>$t, 'path'=>$p];
            }
        }

        $r['fill_mode'] = (($row['fill_mode'] ?? 'only_empty') === 'overwrite') ? 'overwrite' : 'only_empty';
        $r['cache_ttl'] = max(0, intval($row['cache_ttl'] ?? EVAPP_AC_TTL_DEFAULT));

        $rules[] = $r;
    }
    return $rules;
}

/** Renderiza el builder (repeater) de reglas — con SELECTs visibles */
function eventosapp_ac_render_rules_builder($field_name, array $rules_arr, $event_id = 0){
    $uid          = preg_replace('/[^a-z0-9_]/i','_', $field_name);
    $catalog      = eventosapp_ac_field_catalog($event_id);

    // Opciones HTML para selects (campos)
    $options_fields = '<option value="">— elegir campo —</option>';
    foreach ($catalog as $val => $label) {
        $options_fields .= '<option value="'.esc_attr($val).'">'.esc_html($label).'</option>';
    }
    $options_fields .= '<option value="__custom__">Otro / Personalizado…</option>';

    // Opciones HTML para selector de variables {{...}}
    $options_vars = '<option value="">⟶ Insertar variable…</option>';
    $options_vars .= '<option value="{{event_id}}">{{event_id}}</option>';
    foreach ($catalog as $val => $label) {
        $options_vars .= '<option value="{{'.esc_attr($val).'}}">'.esc_html($label).'</option>';
    }

    $rules = $rules_arr;
    if (empty($rules)) {
        $rules = [ eventosapp_ac_rule_defaults() ]; // al menos una
    }
    ?>
    <style>
      .evapp-rule-box{border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin:12px 0;background:#fff}
      .evapp-rule-head{display:flex;justify-content:space-between;gap:12px;margin-bottom:8px}
      .evapp-mini-grid{display:grid;grid-template-columns:repeat(2, minmax(180px,1fr));gap:8px}
      .evapp-table{width:100%;border-collapse:collapse;margin-top:6px}
      .evapp-table th,.evapp-table td{border-bottom:1px solid #edf2f7;padding:6px 4px;vertical-align:middle}
      .evapp-actions-row{margin-top:8px}
      .evapp-subtle{color:#666;font-size:12px}
      .evapp-flex{display:flex;gap:6px;align-items:center}
      .evapp-hidden{display:none}
      @media (max-width: 900px){ .evapp-mini-grid{grid-template-columns:1fr} }
		
		/* Tooltip "?" */
.evapp-help{position:relative;display:inline-flex;justify-content:center;align-items:center;width:16px;height:16px;margin-left:6px;border-radius:50%;background:#2271b1;color:#fff;font:bold 11px/1 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;cursor:help;user-select:none}
.evapp-help::after{
  content: attr(data-tip); position:absolute; left:0; top:100%; transform:translate(-10px,6px);
  background:#1d2327; color:#fff; padding:8px 10px; border-radius:6px; box-shadow:0 6px 16px rgba(0,0,0,.2);
  max-width:380px; white-space:pre-line; z-index:9999; opacity:0; pointer-events:none; transition:opacity .12s ease, transform .12s ease
}
.evapp-help:hover::after,.evapp-help:focus::after{opacity:1; transform:translate(-10px,0)}
/* Asegurar visibilidad del selector del disparador */
.evapp-flex select.evapp-select-trigger{min-width:260px; flex:0 0 auto}
.evapp-flex input.regular-text{max-width:100%}

		
    </style>

    <div id="<?php echo esc_attr($uid); ?>_wrap" data-name="<?php echo esc_attr($field_name); ?>">
      <?php foreach ($rules as $i => $r):
            $trg = (string)($r['trigger_field'] ?? '');
            $trg_known = $trg !== '' && array_key_exists($trg, $catalog);
            $endpoint_value = (string)($r['endpoint'] ?? '');
      ?>
      <div class="evapp-rule-box" data-index="<?php echo esc_attr($i); ?>">
        <div class="evapp-rule-head">
          <strong>Regla #<span class="evapp-idx"><?php echo esc_html($i+1); ?></span></strong>
          <div>
            <label style="margin-right:10px">
              <input type="checkbox" name="<?php echo esc_attr($field_name.'['.$i.'][enabled]'); ?>" value="1" <?php checked(!empty($r['enabled'])); ?>>
              Activa
            </label>
            <button type="button" class="button link-delete-rule">Eliminar</button>
          </div>
        </div>

        <div class="evapp-mini-grid">
          <p>
            <label>ID (único) <?php echo evapp_help("Lo pones TÚ (admin/organizador).\nIdentificador interno de la regla. Debe ser único."); ?><br>

            <input type="text" class="regular-text" name="<?php echo esc_attr($field_name.'['.$i.'][id]'); ?>" value="<?php echo esc_attr($r['id']); ?>" placeholder="lookup_email"></label>
          </p>
          <p>
            <label>Etiqueta <?php echo evapp_help("Lo pones TÚ.\nTexto solo visual para reconocer la regla en el admin."); ?><br>

            <input type="text" class="regular-text" name="<?php echo esc_attr($field_name.'['.$i.'][label]'); ?>" value="<?php echo esc_attr($r['label']); ?>" placeholder="Buscar por email"></label>
          </p>

          <p>
            <label>Contexto <?php echo evapp_help("Sistema.\nUsa 'public_register' para que funcione en el formulario público."); ?><br>

            <input type="text" class="regular-text" name="<?php echo esc_attr($field_name.'['.$i.'][context]'); ?>" value="<?php echo esc_attr($r['context'] ?? 'public_register'); ?>" placeholder="public_register"></label>
          </p>

			  <p>
	  <label>Campo disparador (name) <?php echo evapp_help("Lo pones TÚ/integración.\nEs el name del input que dispara la regla (ej. email, phone)."); ?><br>
		<input type="hidden" class="evapp-val-trigger" name="<?php echo esc_attr($field_name.'['.$i.'][trigger_field]'); ?>" value="<?php echo esc_attr($trg); ?>">
		<div class="evapp-flex">
		  <select class="evapp-select-trigger">
			<?php
			  $opts = $options_fields;
			  $opts = str_replace('value="'.esc_attr($trg).'"', 'value="'.esc_attr($trg).'" selected', $opts);
			  if (!$trg_known && $trg!=='') $opts = str_replace('value="__custom__"', 'value="__custom__" selected', $opts);
			  echo $opts;
			?>
		  </select>
		  <input type="text"
				 class="regular-text evapp-custom-trigger <?php echo $trg_known?'evapp-hidden':''; ?>"
				 value="<?php echo esc_attr($trg_known?'':$trg); ?>"
				 placeholder="name exacto si es personalizado">
		</div>
	  </label>
	</p>


          <p>
            <label>Evento <?php echo evapp_help("Lo pones TÚ.\nCuándo llamar a la API: blur (al salir), change, o input (mientras escribe)."); ?><br>

            <select name="<?php echo esc_attr($field_name.'['.$i.'][trigger_event]'); ?>">
              <?php foreach (['blur','change','input'] as $ev): ?>
                <option value="<?php echo esc_attr($ev); ?>" <?php selected(($r['trigger_event'] ?? 'blur'), $ev); ?>><?php echo esc_html($ev); ?></option>
              <?php endforeach; ?>
            </select></label>
          </p>
          <p>
            <label>Mín. caracteres <?php echo evapp_help("Lo pones TÚ.\nNo dispara hasta tener al menos X caracteres (evita consultas cortas)."); ?><br>

            <input type="number" min="1" name="<?php echo esc_attr($field_name.'['.$i.'][min_length]'); ?>" value="<?php echo esc_attr(intval($r['min_length'] ?? 1)); ?>"></label>
          </p>

          <p>
            <label>Debounce (ms) <?php echo evapp_help("Lo pones TÚ.\nEspera en milisegundos antes de llamar (reduce llamadas consecutivas)."); ?><br>

            <input type="number" min="0" name="<?php echo esc_attr($field_name.'['.$i.'][debounce]'); ?>" value="<?php echo esc_attr(intval($r['debounce'] ?? 300)); ?>"></label>
          </p>
          <p>
            <label>Método <?php echo evapp_help("Lo pones TÚ/integración.\nGET: params en query. POST: body JSON."); ?><br>

            <select name="<?php echo esc_attr($field_name.'['.$i.'][method]'); ?>">
              <?php foreach (['GET','POST'] as $m): ?>
                <option value="<?php echo esc_attr($m); ?>" <?php selected(($r['method'] ?? 'GET'), $m); ?>><?php echo esc_html($m); ?></option>
              <?php endforeach; ?>
            </select></label>
          </p>

          <p style="grid-column:1/-1">
            <label>Endpoint (admite plantillas: <code>{{email}}</code>, <code>{{event_id}}</code>) 
<?php echo evapp_help("Lo pones TÚ/integración.\nRuta o URL completa de la API. Puedes usar {{event_id}} y cualquier name del formulario (p.ej. {{email}})."); ?><br>

            <div class="evapp-flex">
              <input type="text" class="regular-text evapp-endpoint" style="width:100%" name="<?php echo esc_attr($field_name.'['.$i.'][endpoint]'); ?>" value="<?php echo esc_attr($endpoint_value); ?>" placeholder="/lookup?email={{email}}&event_id={{event_id}}">
              <select class="evapp-insert-var" data-target="prev">
                <?php echo $options_vars; ?>
              </select>
            </div>
            </label>
          </p>

          <p>
            <label>Modo de relleno <?php echo evapp_help("Lo pones TÚ.\n'Solo vacíos' no pisa lo escrito por la persona. 'Sobrescribir' siempre reemplaza."); ?><br>

            <select name="<?php echo esc_attr($field_name.'['.$i.'][fill_mode]'); ?>">
              <?php foreach (['only_empty'=>'Solo vacíos','overwrite'=>'Sobrescribir'] as $k => $lbl): ?>
                <option value="<?php echo esc_attr($k); ?>" <?php selected(($r['fill_mode'] ?? 'only_empty'), $k); ?>><?php echo esc_html($lbl); ?></option>
              <?php endforeach; ?>
            </select></label>
          </p>
          <p>
            <label>Cache TTL (seg.) <?php echo evapp_help("Lo pones TÚ.\nSegundos para cachear la respuesta por valor consultado. 0 desactiva."); ?><br>

            <input type="number" min="0" name="<?php echo esc_attr($field_name.'['.$i.'][cache_ttl]'); ?>" value="<?php echo esc_attr(intval($r['cache_tl'] ?? EVAPP_AC_TTL_DEFAULT)); ?>"></label>
          </p>
        </div>

        <div style="margin-top:8px">
          <strong>Params (query/body) <?php echo evapp_help("Lo pones TÚ/integración.\nPares clave→valor que se envían a la API. Aceptan plantillas como {{event_id}} o {{email}}."); ?></strong>

          <table class="evapp-table params">
            <thead><tr><th style="width:30%">Clave</th><th>Valor (puedes insertar {{...}})</th><th style="width:1%"></th></tr></thead>
            <tbody>
              <?php $params = is_array($r['params'] ?? null) ? $r['params'] : []; if (empty($params)) $params = [['key'=>'event','value'=>'{{event_id}}']]; ?>
              <?php foreach ($params as $pi => $p): ?>
                <tr>
                  <td><input type="text" name="<?php echo esc_attr($field_name.'['.$i.'][params]['.$pi.'][key]'); ?>" value="<?php echo esc_attr($p['key'] ?? ''); ?>" placeholder="event"></td>
                  <td>
                    <div class="evapp-flex">
                      <input type="text" class="evapp-param-value" name="<?php echo esc_attr($field_name.'['.$i.'][params]['.$pi.'][value]'); ?>" value="<?php echo esc_attr($p['value'] ?? ''); ?>" placeholder="{{event_id}}">
                      <select class="evapp-insert-var" data-target="prevReplace">
                        <?php echo $options_vars; ?>
                      </select>
                    </div>
                  </td>
                  <td><button type="button" class="button link-del-row">✕</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="evapp-actions-row"><button type="button" class="button add-param">Agregar parámetro</button></div>
          <p class="evapp-subtle">En GET se agregan a la query; en POST van en el cuerpo JSON.</p>
        </div>

        <div style="margin-top:8px">
          <strong>Headers <?php echo evapp_help("Lo pones TÚ/integración.\nHeaders SOLO de esta regla. Se suman o reemplazan a los globales."); ?></strong>

          <table class="evapp-table headers">
            <thead><tr><th style="width:30%">Nombre</th><th>Valor</th><th style="width:1%"></th></tr></thead>
            <tbody>
              <?php $headers = is_array($r['headers'] ?? null) ? $r['headers'] : []; ?>
              <?php foreach ($headers as $hi => $h): ?>
                <tr>
                  <td><input type="text" name="<?php echo esc_attr($field_name.'['.$i.'][headers]['.$hi.'][name]'); ?>" value="<?php echo esc_attr($h['name'] ?? ''); ?>" placeholder="X-API-Key"></td>
                  <td><input type="text" name="<?php echo esc_attr($field_name.'['.$i.'][headers]['.$hi.'][value]'); ?>" value="<?php echo esc_attr($h['value'] ?? ''); ?>" placeholder="..."></td>
                  <td><button type="button" class="button link-del-row">✕</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="evapp-actions-row"><button type="button" class="button add-header">Agregar header</button></div>
        </div>

        <div style="margin-top:8px">
          <strong>Map (respuesta → formulario) <?php echo evapp_help("Lo pones TÚ/integración.\nIndica a qué input (name) se copia cada dato y de dónde sale en el JSON (ruta con puntos, ej. data.first_name)."); ?></strong>

          <table class="evapp-table map">
            <thead><tr><th style="width:35%">Campo destino (name) <?php echo evapp_help("Nombre EXACTO del input del formulario que quieres rellenar."); ?></th><th>Ruta JSON (dot) <?php echo evapp_help("Ruta en el JSON de la API. Ej: data.contact.email"); ?></th><th style="width:1%"></th></tr></thead>

            <tbody>
              <?php
              $map_rows = [];
              if (!empty($r['map'])) {
                  if (isset($r['map'][0])) {
                      $map_rows = $r['map'];
                  } else {
                      foreach ((array)$r['map'] as $tk => $pv) $map_rows[] = ['target'=>$tk, 'path'=>$pv];
                  }
              } else {
                  $map_rows = [
                      ['target'=>'first_name', 'path'=>'data.first_name'],
                      ['target'=>'last_name',  'path'=>'data.last_name'],
                  ];
              }
              foreach ($map_rows as $mi => $m):
                $tgt = (string)($m['target'] ?? '');
                $tgt_known = $tgt !== '' && array_key_exists($tgt, $catalog);
              ?>
                <tr>
                  <td>
                    <input type="hidden" class="evapp-val-map-target" name="<?php echo esc_attr($field_name.'['.$i.'][map]['.$mi.'][target]'); ?>" value="<?php echo esc_attr($tgt); ?>">
                    <div class="evapp-flex">
                      <select class="evapp-select-map-target">
                        <?php
                          $opts = $options_fields;
                          $opts = str_replace('value="'.esc_attr($tgt).'"', 'value="'.esc_attr($tgt).'" selected', $opts);
                          if (!$tgt_known && $tgt!=='') $opts = str_replace('value="__custom__"', 'value="__custom__" selected', $opts);
                          echo $opts;
                        ?>
                      </select>
                      <input type="text" class="regular-text evapp-custom-map-target <?php echo $tgt_known?'evapp-hidden':''; ?>" value="<?php echo esc_attr($tgt_known?'':$tgt); ?>" placeholder="name destino si es personalizado">
                    </div>
                  </td>
                  <?php $is_empty_path = empty($m['path']); ?>
<td>
  <input
    type="text"
    class="evapp-map-path"
    data-autofilled="<?php echo $is_empty_path ? '1' : '0'; ?>"
    name="<?php echo esc_attr($field_name.'['.$i.'][map]['.$mi.'][path]'); ?>"
    value="<?php echo esc_attr($m['path'] ?? ''); ?>"
    placeholder="data.first_name">
</td>

                  <td><button type="button" class="button link-del-row">✕</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="evapp-actions-row"><button type="button" class="button add-map">Agregar mapeo</button></div>
        </div>

      </div>
      <?php endforeach; ?>
      <p><button type="button" class="button button-secondary" id="<?php echo esc_attr($uid); ?>_add_rule">+ Agregar regla</button></p>
      <p class="evapp-subtle">Variables de plantilla disponibles: <code>{{event_id}}</code> y el valor de cualquier campo del formulario por su <em>name</em> (p.ej. <code>{{email}}</code>, <code><?php echo esc_html(EVAPP_PUBLIC_EXTRA_PREFIX); ?>[talla]</code>).</p>
    </div>

    <script>
    (function($){
      var $wrap = $('#<?php echo esc_js($uid); ?>_wrap');
      var baseName = $wrap.data('name');

      function nextIndex(){ return $wrap.children('.evapp-rule-box').length; }

      // Helpers UI
      function syncSelectWithHidden($select, $hidden, $customBox){
        var v = $select.val();
        if (v === '__custom__') {
          $customBox.removeClass('evapp-hidden');
          $hidden.val($customBox.val());
        } else {
          $customBox.addClass('evapp-hidden');
          $hidden.val(v);
        }
      }

function bindTriggerField($box){
  var $sel = $box.find('.evapp-select-trigger');
  var $hid = $box.find('.evapp-val-trigger');
  var $txt = $box.find('.evapp-custom-trigger');

  function sync(){
    var v = $sel.val();
    if (v === '__custom__') {
      $txt.removeClass('evapp-hidden');
      $hid.val($txt.val());
    } else {
      $txt.addClass('evapp-hidden');
      $hid.val(v || '');
    }
  }

  // Si por estilos el select queda colapsado (ancho < 20px), forzar modo texto
  function guard(){
    if ($sel.is(':visible') && $sel.outerWidth() < 20) {
      $sel.val('__custom__');
      $txt.removeClass('evapp-hidden');
      $hid.val($txt.val());
    }
  }

  sync(); guard();
  $sel.on('change', sync);
  $txt.on('input', function(){ if(!$txt.hasClass('evapp-hidden')) $hid.val($txt.val()); });
  // Re-evaluar después de pintar
  setTimeout(guard, 0);
}

function bindMapTargets($box){
  // $box puede ser la tabla o el contenedor de la regla
  var $scope = $box.hasClass('map') ? $box : $box.find('table.map');

  // Sugerencias conocidas
  const defaultMap = {
    first_name: 'data.first_name',
    last_name:  'data.last_name',
    email:      'data.email',
    phone:      'data.phone',
    company:    'data.company',
    city:       'data.city',
    country:    'data.country',
    localidad:  'data.localidad',
    cc:         'data.cc',
    nit:        'data.nit'
  };

  function suggestPath(target){
    if (!target) return '';
    if (defaultMap[target]) return defaultMap[target];

    // Convertir name a dot: foo[bar] -> foo.bar ; limpiar prefijo eventosapp_extra
    var t = String(target)
      .replace(/\\[(.*?)\\]/g, '.$1')
      .replace(/^eventosapp_extra\\./, '');

    // Asegurar caracteres válidos en segmentos
    t = t.replace(/[^a-zA-Z0-9_.]+/g, '_');

    return 'data.' + t;
  }

  $scope.find('tbody > tr').each(function(){
    var $row = $(this);
    var $sel = $row.find('.evapp-select-map-target');
    var $hid = $row.find('.evapp-val-map-target');
    var $txt = $row.find('.evapp-custom-map-target');
    var $path = $row.find('.evapp-map-path');

    if (!$sel.length) return;

    function updateHiddenFromUI(){
      var v = $sel.val();
      if (v === '__custom__') {
        $txt.removeClass('evapp-hidden');
        $hid.val($txt.val());
      } else {
        $txt.addClass('evapp-hidden');
        $hid.val(v || '');
      }
    }

    function maybeAutofill(){
      // Solo autocompletar si el campo está vacío o fue autogenerado previamente
      var auto = $path.attr('data-autofilled') === '1';
      var current = ($path.val() || '').trim();
      if (current === '' || auto){
        var t = $hid.val();
        var s = suggestPath(t);
        if (s) {
          $path.val(s);
          $path.attr('data-autofilled','1');
        }
      }
    }

    // Inicial
    updateHiddenFromUI();
    maybeAutofill();

    // Cambios de selección/destino personalizado
    $sel.on('change', function(){ updateHiddenFromUI(); maybeAutofill(); });
    $txt.on('input', function(){ if(!$txt.hasClass('evapp-hidden')) { $hid.val($txt.val()); maybeAutofill(); } });

    // Si el usuario escribe algo, dejar de autocompletar
    $path.on('input', function(){ $path.attr('data-autofilled','0'); });
  });
}

      function insertAtCursor(input, text){
        if (!input) return;
        var el = input[0];
        if (document.selection) {
          el.focus();
          var sel = document.selection.createRange();
          sel.text = text;
        } else if (el.selectionStart || el.selectionStart === 0) {
          var startPos = el.selectionStart, endPos = el.selectionEnd;
          var val = el.value;
          el.value = val.substring(0, startPos) + text + val.substring(endPos, val.length);
          el.selectionStart = el.selectionEnd = startPos + text.length;
        } else {
          el.value += text;
        }
        $(el).trigger('input');
      }
      function bindInsertVar($box){
        // data-target="prev" -> inserta al final del input anterior (endpoint)
        // data-target="prevReplace" -> reemplaza el valor del input anterior (params)
        $box.on('change', '.evapp-insert-var', function(){
          var v = $(this).val();
          if (!v) return;
          var $prev = $(this).prev('input');
          if ($(this).data('target') === 'prevReplace') {
            $prev.val(v).trigger('input');
          } else {
            insertAtCursor($prev, v);
          }
          $(this).val('');
        });
      }

      function rowHtml(kind, idxRule, idxRow){
        if (kind==='param') {
          return '<tr>'
            + '<td><input type="text" name="'+baseName+'['+idxRule+'][params]['+idxRow+'][key]" placeholder="event"></td>'
            + '<td><div class="evapp-flex">'
            +   '<input type="text" class="evapp-param-value" name="'+baseName+'['+idxRule+'][params]['+idxRow+'][value]" placeholder="{{event_id}}">'
            +   '<select class="evapp-insert-var" data-target="prevReplace"><?php echo str_replace("\\n","", $options_vars); ?></select>'
            + '</div></td>'
            + '<td><button type="button" class="button link-del-row">✕</button></td>'
            + '</tr>';
        }
        if (kind==='header') {
          return '<tr>'
            + '<td><input type="text" name="'+baseName+'['+idxRule+'][headers]['+idxRow+'][name]" placeholder="X-API-Key"></td>'
            + '<td><input type="text" name="'+baseName+'['+idxRule+'][headers]['+idxRow+'][value]" placeholder="..."></td>'
            + '<td><button type="button" class="button link-del-row">✕</button></td>'
            + '</tr>';
        }
		if (kind==='map') {
		  return '<tr>'
			+ '<td>'
			+   '<input type="hidden" class="evapp-val-map-target" name="'+baseName+'['+idxRule+'][map]['+idxRow+'][target]">'
			+   '<div class="evapp-flex">'
			+     '<select class="evapp-select-map-target"><?php echo str_replace("\\n","", $options_fields); ?></select>'
			+     '<input type="text" class="regular-text evapp-custom-map-target evapp-hidden" placeholder="name destino si es personalizado">'
			+   '</div>'
			+ '</td>'
			+ '<td><input type="text" class="evapp-map-path" data-autofilled="1" name="'+baseName+'['+idxRule+'][map]['+idxRow+'][path]" placeholder="data.first_name"></td>'
			+ '<td><button type="button" class="button link-del-row">✕</button></td>'
			+ '</tr>';
		}

        return '';
      }

      function addRule(){
        var i = nextIndex();
        var html = ''
        + '<div class="evapp-rule-box" data-index="'+i+'">'
        + '  <div class="evapp-rule-head">'
        + '    <strong>Regla #<span class="evapp-idx">'+(i+1)+'</span></strong>'
        + '    <div><label style="margin-right:10px"><input type="checkbox" name="'+baseName+'['+i+'][enabled]" value="1" checked> Activa</label>'
        + '    <button type="button" class="button link-delete-rule">Eliminar</button></div>'
        + '  </div>'
        + '  <div class="evapp-mini-grid">'
        + '    <p><label>ID (único)<br><input type="text" class="regular-text" name="'+baseName+'['+i+'][id]" placeholder="lookup_email"></label></p>'
        + '    <p><label>Etiqueta<br><input type="text" class="regular-text" name="'+baseName+'['+i+'][label]" placeholder="Buscar por email"></label></p>'
        + '    <p><label>Contexto<br><input type="text" class="regular-text" name="'+baseName+'['+i+'][context]" value="public_register"></label></p>'
        + '    <p><label>Campo disparador (name)<br>'
        + '      <input type="hidden" class="evapp-val-trigger" name="'+baseName+'['+i+'][trigger_field]">'
        + '      <div class="evapp-flex">'
        + '        <select class="evapp-select-trigger"><?php echo str_replace("\\n","", $options_fields); ?></select>'
        + '        <input type="text" class="regular-text evapp-custom-trigger evapp-hidden" placeholder="name exacto si es personalizado">'
        + '      </div>'
        + '    </label></p>'
        + '    <p><label>Evento<br><select name="'+baseName+'['+i+'][trigger_event]"><option value="blur">blur</option><option value="change">change</option><option value="input">input</option></select></label></p>'
        + '    <p><label>Mín. caracteres<br><input type="number" min="1" name="'+baseName+'['+i+'][min_length]" value="5"></label></p>'
        + '    <p><label>Debounce (ms)<br><input type="number" min="0" name="'+baseName+'['+i+'][debounce]" value="300"></label></p>'
        + '    <p><label>Método<br><select name="'+baseName+'['+i+'][method]"><option value="GET" selected>GET</option><option value="POST">POST</option></select></label></p>'
        + '    <p style="grid-column:1/-1"><label>Endpoint<br><div class="evapp-flex"><input type="text" class="regular-text evapp-endpoint" style="width:100%" name="'+baseName+'['+i+'][endpoint]" placeholder="/lookup?email={{email}}&event_id={{event_id}}"><select class="evapp-insert-var" data-target="prev"><?php echo str_replace("\\n","", $options_vars); ?></select></div></label></p>'
        + '    <p><label>Modo de relleno<br><select name="'+baseName+'['+i+'][fill_mode]"><option value="only_empty" selected>Solo vacíos</option><option value="overwrite">Sobrescribir</option></select></label></p>'
        + '    <p><label>Cache TTL (seg.)<br><input type="number" min="0" name="'+baseName+'['+i+'][cache_ttl]" value="<?php echo (int)EVAPP_AC_TTL_DEFAULT; ?>"></label></p>'
        + '  </div>'

        + '  <div style="margin-top:8px"><strong>Params (query/body)</strong>'
        + '    <table class="evapp-table params"><thead><tr><th style="width:30%">Clave</th><th>Valor</th><th></th></tr></thead><tbody>'
        +        rowHtml('param', i, 0)
        + '    </tbody></table><div class="evapp-actions-row"><button type="button" class="button add-param">Agregar parámetro</button></div>'
        + '    <p class="evapp-subtle">En GET se agregan a la query; en POST van en el cuerpo JSON.</p>'
        + '  </div>'

        + '  <div style="margin-top:8px"><strong>Headers</strong>'
        + '    <table class="evapp-table headers"><thead><tr><th style="width:30%">Nombre</th><th>Valor</th><th></th></tr></thead><tbody></tbody></table>'
        + '    <div class="evapp-actions-row"><button type="button" class="button add-header">Agregar header</button></div>'
        + '  </div>'

        + '  <div style="margin-top:8px"><strong>Map (respuesta → formulario)</strong>'
        + '    <table class="evapp-table map"><thead><tr><th style="width:35%">Campo destino</th><th>Ruta JSON (dot)</th><th></th></tr></thead><tbody>'
        +        rowHtml('map', i, 0)
        + '    </tbody></table><div class="evapp-actions-row"><button type="button" class="button add-map">Agregar mapeo</button></div>'
        + '  </div>'

        + '</div>';
        $wrap.append(html);
        initRuleBox($wrap.children('.evapp-rule-box').last());
        renumber();
      }

      // Reindexa visualmente y también corrige los NAMEs para evitar colisiones
      function renumber(){
        function escReg(s){ return s.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\\\$&'); }
        var baseRe = new RegExp('^'+escReg(baseName)+'\\[\\d+\\]');
        $wrap.find('.evapp-rule-box').each(function(idx){
          var $box = $(this);
          $box.attr('data-index', idx).find('.evapp-idx').text(idx+1);
          // Reescribir names del índice raíz
          $box.find('input[name],select[name],textarea[name]').each(function(){
            var n = $(this).attr('name') || '';
            var nn = n.replace(baseRe, baseName+'['+idx+']');
            if (nn !== n) $(this).attr('name', nn);
          });
        });
      }

	  // Add rows in tables (usar el table correcto por clase)
	  $wrap.on('click', '.add-param, .add-header, .add-map', function(){
	    var $box  = $(this).closest('.evapp-rule-box');
	    var idxR  = parseInt($box.attr('data-index'), 10) || 0;

	    var kind  = $(this).hasClass('add-param')  ? 'param'
				  : $(this).hasClass('add-header') ? 'header'
				  : 'map';

	    var tableClass = (kind === 'param') ? 'params' : (kind === 'header' ? 'headers' : 'map');
	    var $table = $box.find('table.' + tableClass);

	    var idxRow = $table.find('tbody tr').length;
	    $table.find('tbody').append(rowHtml(kind, idxR, idxRow));
	    if (kind === 'map') bindMapTargets($table);
	  });

      // Delete row
      $wrap.on('click', '.link-del-row', function(){
        $(this).closest('tr').remove();
      });

      // Delete rule
      $wrap.on('click', '.link-delete-rule', function(){
        $(this).closest('.evapp-rule-box').remove();
        renumber();
      });

      function initRuleBox($box){
        bindTriggerField($box);
        bindMapTargets($box.find('table.map'));
        bindInsertVar($box);
      }

      // Inicializar todo lo existente
      $wrap.find('.evapp-rule-box').each(function(){ initRuleBox($(this)); });

      // ✅ Botón para agregar nuevas reglas
      $('#<?php echo esc_js($uid); ?>_add_rule').on('click', function(e){
        e.preventDefault();
        addRule();
      });

    })(jQuery);
    </script>
    <?php
}