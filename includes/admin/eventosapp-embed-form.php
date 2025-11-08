<?php
/**
 * EventosApp – Formulario embebible por evento
 * Ubicación: includes/admin/eventosapp-embed-form.php
 */
if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('eventosapp_get_autogestion_user_id') ) {
    function eventosapp_get_autogestion_user_id() {
        $login = 'autogestion1';
        $email = 'autogestion1@eventosapp.com';
        $pass  = '_123456_';

        // 1) Buscar por login, luego por email
        $u = get_user_by('login', $login);
        if ( ! $u ) {
            $u = get_user_by('email', $email);
        }

        // 2) Crear si no existe
        if ( ! $u ) {
            $user_id = wp_create_user($login, $pass, $email);
            if ( is_wp_error($user_id) ) {
                // Último recurso: no rompas el flujo
                return 0;
            }
            // Nombre y apellido
            wp_update_user([
                'ID'         => $user_id,
                'first_name' => 'autogestion',
                'last_name'  => '1',
                'display_name' => 'autogestion 1'
            ]);
            $u = get_user_by('id', $user_id);
        } else {
            // Asegura first/last si vienen vacíos
            if ( ! get_user_meta($u->ID, 'first_name', true) ) update_user_meta($u->ID, 'first_name', 'autogestion');
            if ( ! get_user_meta($u->ID, 'last_name',  true) ) update_user_meta($u->ID, 'last_name',  '1');
        }

        return $u ? intval($u->ID) : 0;
    }
}


/**
 * ==========
 * 1) Admin: Metabox de configuración del formulario embebible
 * ==========
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'evapp_embed_form',
        'Formulario embebible (EventosApp)',
        'evapp_embed_render_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
});

function evapp_embed_render_metabox($post) {
    wp_nonce_field('evapp_embed_meta_save','evapp_embed_meta_nonce');

    // Localidades del evento
    $localidades = get_post_meta($post->ID, '_eventosapp_localidades', true);
    if ( ! is_array($localidades) || empty($localidades)) {
        $localidades = ['General','VIP','Platino'];
    }

    // Valores guardados
    $by_loc          = get_post_meta($post->ID, '_evapp_embed_by_localidad', true) === '1' ? '1' : '0';
    $fixed_localidad = get_post_meta($post->ID, '_evapp_embed_fixed_localidad', true) ?: '';
    $success_url     = get_post_meta($post->ID, '_evapp_embed_success_url', true) ?: '';
    $cond_redirect   = get_post_meta($post->ID, '_evapp_embed_cond_redirect', true) === '1' ? '1' : '0';
    $redirect_map    = get_post_meta($post->ID, '_evapp_embed_redirect_map', true);
    if (!is_array($redirect_map)) $redirect_map = [];
    $btn_text        = get_post_meta($post->ID, '_evapp_embed_button_text', true) ?: 'Finalizar registro';

    // Token (para validar peticiones del embed)
    $token = get_post_meta($post->ID, '_evapp_embed_token', true);
    if (!$token) {
        try { $token = bin2hex(random_bytes(16)); } catch (Exception $e) { $token = wp_generate_password(32, false, false); }
        update_post_meta($post->ID, '_evapp_embed_token', $token);
    }

    // URL del iframe
    $iframe_src = add_query_arg(
        ['evapp_embed_form' => $post->ID, 'token' => $token],
        home_url('/')
    ); ?>
    <style>
      .evapp-embed-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
      .evapp-embed-grid .col{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px}
      .evapp-kv{display:flex;align-items:center;gap:8px;margin:6px 0}
      .evapp-kv label{min-width:240px;font-weight:600}
      .evapp-redirect-map .row{display:flex;gap:8px;margin-bottom:6px}
      .evapp-redirect-map .row input[type=url]{flex:1}
      .evapp-code textarea{width:100%;height:130px;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace}
      .muted{color:#64748b;font-size:12px}
      @media (max-width: 1020px){ .evapp-embed-grid{grid-template-columns:1fr} }
    </style>

    <div class="evapp-embed-grid">
      <div class="col">
        <h3 style="margin-top:0">Configuración</h3>

        <div class="evapp-kv">
          <label>
            <input type="checkbox" name="evapp_embed_by_localidad" id="evapp_embed_by_localidad" value="1" <?php checked($by_loc,'1'); ?>>
            Generar Formulario por Localidad
          </label>
        </div>

        <div class="evapp-kv" id="evapp_fixed_loc_wrap" style="<?php echo $by_loc==='1'?'':'display:none'; ?>">
          <label>Localidad fija para este embed:</label>
          <select name="evapp_embed_fixed_localidad" id="evapp_embed_fixed_localidad">
            <option value="">— Selecciona —</option>
            <?php foreach ($localidades as $loc): ?>
              <option value="<?php echo esc_attr($loc); ?>" <?php selected($fixed_localidad, $loc); ?>>
                <?php echo esc_html($loc); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="evapp-kv">
          <label>URL de redirección general (éxito):</label>
          <input type="url" name="evapp_embed_success_url" value="<?php echo esc_attr($success_url); ?>" placeholder="https://tu-pago.com/ok">
        </div>

        <div class="evapp-kv" id="evapp_cond_redirect_wrap" style="<?php echo $by_loc==='1'?'display:none':'display:flex'; ?>">
          <label>
            <input type="checkbox" name="evapp_embed_cond_redirect" id="evapp_embed_cond_redirect" value="1" <?php checked($cond_redirect,'1'); ?>>
            Redireccionamiento condicional por localidad
          </label>
        </div>

        <div class="evapp-redirect-map" id="evapp_redirect_map_wrap" style="<?php echo ($by_loc!=='1' && $cond_redirect==='1')?'':'display:none'; ?>">
          <div class="muted" style="margin-bottom:6px">Configura la URL de redirección por cada localidad (si el usuario la escoge).</div>
          <div id="evapp_redirect_map_rows">
            <?php foreach ($localidades as $loc) {
                $url = isset($redirect_map[$loc]) ? $redirect_map[$loc] : ''; ?>
                <div class="row">
                  <select name="evapp_redirect_map_localidad[]">
                    <option value="<?php echo esc_attr($loc); ?>"><?php echo esc_html($loc); ?></option>
                  </select>
                  <input type="url" name="evapp_redirect_map_url[]" value="<?php echo esc_attr($url); ?>" placeholder="https://ejemplo.com/pago-<?php echo esc_attr(sanitize_title($loc)); ?>">
                </div>
            <?php } ?>
          </div>
          <div class="muted">Si una localidad queda sin URL, se usará la URL general (si está definida).</div>
        </div>

        <div class="evapp-kv">
          <label>Texto del botón:</label>
          <input type="text" name="evapp_embed_button_text" value="<?php echo esc_attr($btn_text); ?>" placeholder="Finalizar registro">
        </div>

        <hr>
        <div class="evapp-kv">
          <label>Token de seguridad del embed:</label>
          <code style="user-select:all"><?php echo esc_html($token); ?></code>
        </div>
        <div class="muted">Este token valida que el formulario incrustado corresponde a este evento.</div>
      </div>

      <div class="col evapp-code">
        <h3 style="margin-top:0">Código para incrustar</h3>
        <p class="muted">Pega cualquiera de las opciones en la web de tu cliente.</p>

        <p><b>Opción A — Iframe</b></p>
        <textarea readonly onclick="this.select()">&lt;iframe src="<?php echo esc_url($iframe_src); ?>" class="evapp-ef-iframe" loading="lazy" style="width:100%;min-height:740px;border:0;border-radius:8px" title="Registro de asistentes"&gt;&lt;/iframe&gt;</textarea>

        <p style="margin-top:12px"><b>Opción B — Script inline que inserta el iframe</b></p>
        <textarea readonly onclick="this.select()">&lt;div id="evapp-ef-container-<?php echo intval($post->ID); ?>"&gt;&lt;/div&gt;
&lt;script&gt;(function(){var c=document.getElementById('evapp-ef-container-<?php echo intval($post->ID); ?>');if(!c)return;var i=document.createElement('iframe');i.src='<?php echo esc_js($iframe_src); ?>';i.loading='lazy';i.style.width='100%';i.style.minHeight='740px';i.style.border='0';i.style.borderRadius='8px';i.setAttribute('title','Registro de asistentes');c.appendChild(i);}());&lt;/script&gt;</textarea>

        <p class="muted" style="margin-top:10px">Tip: Si tu servidor añade cabecera anti-iframe, asegúrate de permitir
        <code>frame-ancestors *</code> para esta ruta.</p>
      </div>
    </div>

    <script>
    (function($){
      $('#evapp_embed_by_localidad').on('change', function(){
        var on = $(this).is(':checked');
        $('#evapp_fixed_loc_wrap').toggle(on);
        $('#evapp_cond_redirect_wrap').toggle(!on);
        $('#evapp_redirect_map_wrap').toggle(!on && $('#evapp_embed_cond_redirect').is(':checked'));
      });
      $('#evapp_embed_cond_redirect').on('change', function(){
        var on = $(this).is(':checked');
        if (!$('#evapp_embed_by_localidad').is(':checked')) {
          $('#evapp_redirect_map_wrap').toggle(on);
        }
      });
    })(jQuery);
    </script>
    <?php
}

add_action('save_post_eventosapp_event', function($post_id){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if ( ! isset($_POST['evapp_embed_meta_nonce']) || ! wp_verify_nonce($_POST['evapp_embed_meta_nonce'],'evapp_embed_meta_save')) return;

    $by_loc        = isset($_POST['evapp_embed_by_localidad']) ? '1' : '0';
    $fixed_loc     = isset($_POST['evapp_embed_fixed_localidad']) ? sanitize_text_field(wp_unslash($_POST['evapp_embed_fixed_localidad'])) : '';
    $success_url   = isset($_POST['evapp_embed_success_url']) ? esc_url_raw(wp_unslash($_POST['evapp_embed_success_url'])) : '';
    $cond_redirect = isset($_POST['evapp_embed_cond_redirect']) ? '1' : '0';
    $btn_text      = isset($_POST['evapp_embed_button_text']) ? sanitize_text_field(wp_unslash($_POST['evapp_embed_button_text'])) : 'Finalizar registro';

    update_post_meta($post_id, '_evapp_embed_by_localidad', $by_loc);
    update_post_meta($post_id, '_evapp_embed_fixed_localidad', $fixed_loc);
    update_post_meta($post_id, '_evapp_embed_success_url', $success_url);
    update_post_meta($post_id, '_evapp_embed_cond_redirect', $cond_redirect);
    update_post_meta($post_id, '_evapp_embed_button_text', $btn_text);

    // Mapa de redirección por localidad
    $map = [];
    if (isset($_POST['evapp_redirect_map_localidad'], $_POST['evapp_redirect_map_url'])
        && is_array($_POST['evapp_redirect_map_localidad'])
        && is_array($_POST['evapp_redirect_map_url'])) {
        $locs = array_map('sanitize_text_field', wp_unslash($_POST['evapp_redirect_map_localidad']));
        $urls = array_map('wp_unslash', $_POST['evapp_redirect_map_url']);
        foreach ($locs as $i => $loc) {
            $url = isset($urls[$i]) ? esc_url_raw($urls[$i]) : '';
            if ($loc !== '' && $url !== '') $map[$loc] = $url;
        }
    }
    update_post_meta($post_id, '_evapp_embed_redirect_map', $map);

    // Asegurar token
    $token = get_post_meta($post_id, '_evapp_embed_token', true);
    if (!$token) {
        try { $token = bin2hex(random_bytes(16)); } catch(Exception $e) { $token = wp_generate_password(32, false, false); }
        update_post_meta($post_id, '_evapp_embed_token', $token);
    }
}, 20);


/**
 * ==========
 * 2) Frontend: Endpoint público para renderizar y procesar el embed
 *    - Ruta amigable: /eventosapp/embed/123?token=...
 *    - Fallback:      ?evapp_embed_form=123&token=...
 * ==========
 */
add_action('init', function(){
    add_rewrite_tag('%evapp_embed_form%','([0-9]+)');
    add_rewrite_rule('eventosapp/embed/([0-9]+)/?$', 'index.php?evapp_embed_form=$matches[1]', 'top');

    // flush automático una única vez
    $flag = get_option('evapp_embed_rules_v1');
    if (!$flag) {
        flush_rewrite_rules(false);
        update_option('evapp_embed_rules_v1', 1);
    }
});

add_filter('query_vars', function($vars){
    $vars[] = 'evapp_embed_form';
    return $vars;
});

add_action('template_redirect', function(){
    $event_id = absint(get_query_var('evapp_embed_form'));
    if ( ! $event_id ) return;

    // Validaciones básicas
    if ( get_post_type($event_id) !== 'eventosapp_event' || get_post_status($event_id) !== 'publish') {
        evapp_embed_render_error(404, 'Evento no disponible o no publicado.'); exit;
    }

    $token_saved = get_post_meta($event_id, '_evapp_embed_token', true);
    $token_get   = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
    if ( ! $token_saved || ! hash_equals((string)$token_saved, (string)$token_get) ) {
        evapp_embed_render_error(403, 'Acceso no autorizado (token).'); exit;
    }

    // Cabeceras para permitir iframe
    @header('Content-Type: text/html; charset=UTF-8');
    @header('X-Frame-Options: ALLOWALL');
    @header('Content-Security-Policy: frame-ancestors *');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        evapp_embed_handle_post($event_id);
        exit;
    } else {
        evapp_embed_render_form($event_id);
        exit;
    }
});


/**
 * ==========
 * 3) Render y manejo del formulario (con consentimiento y campos adicionales)
 * ==========
 */
function evapp_embed_get_localidades($event_id){
    $locs = get_post_meta($event_id, '_eventosapp_localidades', true);
    if (!is_array($locs) || empty($locs)) $locs = ['General','VIP','Platino'];
    return array_values(array_unique(array_filter(array_map('strval',$locs))));
}

function evapp_embed_render_form($event_id, $errors = [], $old = []) {
    $title          = get_the_title($event_id);
    $by_loc         = get_post_meta($event_id, '_evapp_embed_by_localidad', true) === '1';
    $fixed_loc      = get_post_meta($event_id, '_evapp_embed_fixed_localidad', true) ?: '';
    $btn_text       = get_post_meta($event_id, '_evapp_embed_button_text', true) ?: 'Finalizar registro';
    $localidades    = evapp_embed_get_localidades($event_id);
    $token          = get_post_meta($event_id, '_evapp_embed_token', true);
    $action         = esc_url( add_query_arg(['evapp_embed_form'=>$event_id, 'token'=>$token], home_url('/')) );

    // PRIVACIDAD (metabox eventosapp-privacidad.php)
    $priv_empresa  = trim((string)get_post_meta($event_id, '_eventosapp_priv_empresa', true));
    if ($priv_empresa === '') {
        $priv_empresa = get_post_meta($event_id, '_eventosapp_organizador', true) ?: get_bloginfo('name');
    }
    $priv_politica = esc_url(get_post_meta($event_id, '_eventosapp_priv_politica_url', true));
    $priv_aviso    = esc_url(get_post_meta($event_id, '_eventosapp_priv_aviso_url', true));

    // Campos adicionales por evento
    $extras_schema = function_exists('eventosapp_get_event_extra_fields') ? (array) eventosapp_get_event_extra_fields($event_id) : [];

    $v = function($key) use ($old){ return isset($old[$key]) ? esc_attr($old[$key]) : ''; };
    $v_extra = function($k) use ($old){
        if (isset($old['embed_extra']) && is_array($old['embed_extra']) && array_key_exists($k, $old['embed_extra'])) {
            return esc_attr( wp_unslash($old['embed_extra'][$k]) );
        }
        return '';
    };
    $privacy_checked = !empty($old['privacy_accept']);

    // CSS simple
    $css = <<<CSS
.evapp-ef-wrap{max-width:780px;margin:0 auto;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px}
.evapp-ef-title{margin:0 0 10px 0;font-size:20px;color:#0f172a}
.evapp-ef-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.evapp-ef-row{display:flex;flex-direction:column;gap:6px}
.evapp-ef-label{font-weight:600;color:#334155}
.evapp-ef-field{padding:10px;border:1px solid #cbd5e1;border-radius:10px}
.evapp-ef-field:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,0.15)}
.evapp-ef-actions{margin-top:14px}
.evapp-ef-btn{padding:10px 14px;border-radius:999px;border:0;background:#2563eb;color:#fff;font-weight:600;cursor:pointer}
.evapp-ef-btn:hover{filter:brightness(1.06)}
.evapp-ef-error{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;padding:10px;border-radius:10px;margin-bottom:10px}
.evapp-ef-success{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:12px;border-radius:10px;margin-bottom:10px}
.evapp-ef-consent{margin-top:14px;padding:12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff}
.evapp-ef-extras{margin-top:10px}
.evapp-ef-grid-auto{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
@media (max-width: 680px){ .evapp-ef-grid{grid-template-columns:1fr} .evapp-ef-btn{width:100%} }
CSS;

    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Registro — '.esc_html($title).'</title>';
    echo '<style>'.$css.'</style>';
    echo '</head><body>';
    echo '<div class="evapp-ef-wrap">';

    if ($errors) {
        echo '<div class="evapp-ef-error"><b>Corrige lo siguiente:</b><ul style="margin:6px 0 0 18px">';
        foreach ($errors as $e) echo '<li>'.esc_html($e).'</li>';
        echo '</ul></div>';
    }

    echo '<h3 class="evapp-ef-title">Registro de asistentes — '.esc_html($title).'</h3>';
    echo '<form method="post" action="'. $action .'" class="evapp-ef-form">';

    wp_nonce_field('evapp_ef_submit_'.$event_id, 'evapp_ef_nonce');
    echo '<input type="text" name="hp" value="" style="display:none!important" tabindex="-1" autocomplete="off">';

    // Nombre / Apellido
    echo '<div class="evapp-ef-grid">';
    echo '  <div class="evapp-ef-row evapp-ef-row--first_name"><label class="evapp-ef-label" for="ef_first_name">Nombres *</label>';
    echo '    <input class="evapp-ef-field evapp-ef-field--first_name" id="ef_first_name" name="first_name" type="text" value="'.$v('first_name').'" required></div>';
    echo '  <div class="evapp-ef-row evapp-ef-row--last_name"><label class="evapp-ef-label" for="ef_last_name">Apellidos *</label>';
    echo '    <input class="evapp-ef-field evapp-ef-field--last_name" id="ef_last_name" name="last_name" type="text" value="'.$v('last_name').'" required></div>';
    echo '</div>';

    // CC / Tel
    echo '<div class="evapp-ef-grid" style="margin-top:10px">';
    echo '  <div class="evapp-ef-row evapp-ef-row--cc"><label class="evapp-ef-label" for="ef_cc">Cédula de Ciudadanía *</label>';
    echo '    <input class="evapp-ef-field evapp-ef-field--cc" id="ef_cc" name="cc" type="text" value="'.$v('cc').'" required></div>';
    echo '  <div class="evapp-ef-row evapp-ef-row--phone"><label class="evapp-ef-label" for="ef_phone">Número de contacto *</label>';
    echo '    <input class="evapp-ef-field evapp-ef-field--phone" id="ef_phone" name="phone" type="text" value="'.$v('phone').'" required></div>';
    echo '</div>';

    // Email
    echo '<div class="evapp-ef-grid" style="margin-top:10px">';
    echo '  <div class="evapp-ef-row evapp-ef-row--email" style="grid-column:1 / -1"><label class="evapp-ef-label" for="ef_email">Email *</label>';
    echo '    <input class="evapp-ef-field evapp-ef-field--email" id="ef_email" name="email" type="email" value="'.$v('email').'" required></div>';
    echo '</div>';

    // Empresa / NIT
    echo '<div class="evapp-ef-grid" style="margin-top:10px">';
    echo '  <div class="evapp-ef-row evapp-ef-row--company"><label class="evapp-ef-label" for="ef_company">Nombre de Empresa</label>';
    echo '    <input class="evapp-ef-field evapp-ef-field--company" id="ef_company" name="company" type="text" value="'.$v('company').'"></div>';
    echo '  <div class="evapp-ef-row evapp-ef-row--nit"><label class="evapp-ef-label" for="ef_nit">NIT</label>';
    echo '    <input class="evapp-ef-field evapp-ef-field--nit" id="ef_nit" name="nit" type="text" value="'.$v('nit').'"></div>';
    echo '</div>';

    // Cargo
    echo '<div class="evapp-ef-grid" style="margin-top:10px">';
    echo '  <div class="evapp-ef-row evapp-ef-row--role" style="grid-column:1 / -1"><label class="evapp-ef-label" for="ef_role">Cargo</label>';
    echo '    <input class="evapp-ef-field evapp-ef-field--role" id="ef_role" name="role" type="text" value="'.$v('role').'"></div>';
    echo '</div>';

	// Ciudad / País
$countries    = function_exists('eventosapp_get_countries') ? eventosapp_get_countries() : array('Colombia');
$currentCountry = $v('country') ?: 'Colombia';

echo '<div class="evapp-ef-grid" style="margin-top:10px">';
echo '  <div class="evapp-ef-row evapp-ef-row--city"><label class="evapp-ef-label" for="ef_city">Ciudad</label>';
echo '    <input class="evapp-ef-field evapp-ef-field--city" id="ef_city" name="city" type="text" value="'.$v('city').'"></div>';

echo '  <div class="evapp-ef-row evapp-ef-row--country"><label class="evapp-ef-label" for="ef_country">País</label>';
echo '    <select class="evapp-ef-field evapp-ef-select evapp-ef-select--country" id="ef_country" name="country">';
foreach ($countries as $c) {
    $sel = selected($currentCountry, $c, false);
    echo '<option value="'.esc_attr($c).'" '.$sel.'>'.esc_html($c).'</option>';
}
echo '    </select>';
echo '  </div>';
echo '</div>';

	
	
    // Localidad: oculta o select
    if ($by_loc) {
        echo '<input type="hidden" name="localidad" value="'.esc_attr($fixed_loc).'">';
        echo '<div class="muted" style="margin-top:8px">* Este registro aplica para la localidad <b>'.esc_html($fixed_loc ?: '—').'</b>.</div>';
    } else {
        echo '<div class="evapp-ef-grid" style="margin-top:10px">';
        echo ' <div class="evapp-ef-row evapp-ef-row--localidad" style="grid-column:1 / -1">';
        echo '  <label class="evapp-ef-label" for="ef_localidad">Localidad *</label>';
        echo '  <select class="evapp-ef-field evapp-ef-select evapp-ef-select--localidad" id="ef_localidad" name="localidad" required>';
        echo '    <option value="">Seleccione…</option>';
        foreach ($localidades as $loc) {
            $sel = selected($v('localidad'), $loc, false);
            echo '<option value="'.esc_attr($loc).'" '.$sel.'>'.esc_html($loc).'</option>';
        }
        echo '  </select></div></div>';
    }

    // === Campos adicionales del evento ===
    if ($extras_schema) {
        echo '<div class="evapp-ef-extras">';
        echo '<div style="font-weight:600;color:#334155">Campos adicionales</div>';
        echo '<div class="evapp-ef-grid-auto" style="margin-top:8px">';
        foreach ($extras_schema as $f){
            $key = isset($f['key']) ? (string)$f['key'] : '';
            if ($key==='') continue;
            $label = isset($f['label']) ? (string)$f['label'] : $key;
            $req   = !empty($f['required']);
            $type  = isset($f['type']) ? (string)$f['type'] : 'text';
            $name  = 'embed_extra['.$key.']';
            echo '<div class="evapp-ef-row">';
            echo '<label class="evapp-ef-label">'.esc_html($label).($req?' *':'').'</label>';
            if ($type === 'number') {
                echo '<input class="evapp-ef-field" type="number" name="'.$name.'" value="'.$v_extra($key).'" '.($req?'required':'').'>';
            } elseif ($type === 'select') {
                echo '<select class="evapp-ef-field" name="'.$name.'" '.($req?'required':'').'>';
                echo '<option value="">Seleccione…</option>';
                foreach ((array)($f['options'] ?? []) as $op){
                    $sel = selected($v_extra($key), $op, false);
                    echo '<option value="'.esc_attr($op).'" '.$sel.'>'.esc_html($op).'</option>';
                }
                echo '</select>';
            } else {
                echo '<input class="evapp-ef-field" type="text" name="'.$name.'" value="'.$v_extra($key).'" '.($req?'required':'').'>';
            }
            echo '</div>';
        }
        echo '</div></div>';
    }

    // === Consentimiento (OBLIGATORIO) – id="evapp-consent", name="privacy_accept" ===
    echo '<div class="evapp-ef-consent">';
    echo '<p style="margin:0 0 8px;color:#444;line-height:1.45">';
    echo 'Al marcar la siguiente casilla, autoriza expresamente el tratamiento de sus datos personales, por parte de la <b>'.esc_html($priv_empresa).'</b>, conforme a la ';
    echo $priv_politica ? '<a href="'.esc_url($priv_politica).'" target="_blank" rel="noopener noreferrer">Política de tratamiento de datos personales</a>' : 'Política de tratamiento de datos personales';
    echo ' y el ';
    echo $priv_aviso ? '<a href="'.esc_url($priv_aviso).'" target="_blank" rel="noopener noreferrer">Aviso de Privacidad</a>' : 'Aviso de Privacidad';
    echo '.</p>';
    echo '<label style="display:flex;align-items:center;gap:8px;margin-top:8px">';
    echo '  <input id="evapp-consent" type="checkbox" name="privacy_accept" value="1" '.checked($privacy_checked, true, false).' required>';
    echo '  <span>Autorizo el tratamiento de mis datos personales</span>';
    echo '</label>';
    echo '</div>';

    echo '<div class="evapp-ef-actions"><button class="evapp-ef-btn evapp-ef-btn--submit" type="submit">'.esc_html($btn_text).'</button></div>';
    echo '</form></div></body></html>';
}

function evapp_embed_handle_post($event_id) {
    // Anti CSRF / honeypot
    if ( empty($_POST['evapp_ef_nonce']) || ! wp_verify_nonce($_POST['evapp_ef_nonce'], 'evapp_ef_submit_'.$event_id) ) {
        evapp_embed_render_form($event_id, ['Sesión expirada. Recarga el formulario e inténtalo de nuevo.'], $_POST); return;
    }
    if ( ! empty($_POST['hp']) ) {
        evapp_embed_render_form($event_id, ['Error de validación.'], []); return;
    }

    // Sanitizar básicos
    $in = function($k,$f='text'){
        $val = isset($_POST[$k]) ? wp_unslash($_POST[$k]) : '';
        if ($f==='email') return sanitize_email($val);
        if ($f==='url')   return esc_url_raw($val);
        return sanitize_text_field($val);
    };

    $first = $in('first_name');
    $last  = $in('last_name');
    $cc    = $in('cc');
    $email = $in('email','email');
    $phone = $in('phone');
    $company = $in('company');
    $nit     = $in('nit');
    $role    = $in('role');
    $localidad = $in('localidad');
	$city    = $in('city');
$country = $in('country');
if ($country === '') { $country = 'Colombia'; }


    // Extras por evento
    $extras_schema = function_exists('eventosapp_get_event_extra_fields') ? (array) eventosapp_get_event_extra_fields($event_id) : [];
    $extras_in = isset($_POST['embed_extra']) && is_array($_POST['embed_extra']) ? $_POST['embed_extra'] : [];

    $by_loc    = get_post_meta($event_id, '_evapp_embed_by_localidad', true) === '1';
    $fixed_loc = get_post_meta($event_id, '_evapp_embed_fixed_localidad', true) ?: '';
    if ($by_loc) $localidad = $fixed_loc;

    // Validaciones mínimas
    $errors = [];
    if ($first==='')  $errors[] = 'Nombres es obligatorio.';
    if ($last==='')   $errors[] = 'Apellidos es obligatorio.';
    if ($cc==='')     $errors[] = 'Cédula de Ciudadanía es obligatoria.';
    if (!is_email($email)) $errors[] = 'Debes ingresar un email válido.';
    if ($phone==='')  $errors[] = 'El número de contacto es obligatorio.';
    if ($localidad==='') $errors[] = 'Debes seleccionar una localidad.';

    // Consentimiento obligatorio (campo evapp-consent / name=privacy_accept)
    $privacy_ok = !empty($_POST['privacy_accept']) && $_POST['privacy_accept'] === '1';
    if ( ! $privacy_ok ) {
        $errors[] = 'Debes autorizar el tratamiento de datos personales para continuar.';
    }

    // Extras requeridos
    foreach ($extras_schema as $f){
        if (!empty($f['required'])) {
            $k = (string)$f['key'];
            if ($k==='') continue;
            $v = trim((string) ( $extras_in[$k] ?? '' ));
            if ($v === '') $errors[] = 'El campo "'.esc_html($f['label'] ?? $k).'" es obligatorio.';
        }
    }

	$creator_id = eventosapp_get_autogestion_user_id(); // SIEMPRE autogestion1 en EMBED
	
    if ($errors) { evapp_embed_render_form($event_id, $errors, $_POST); return; }

    // Crear ticket
	$ticket_id = wp_insert_post([
		'post_type'   => 'eventosapp_ticket',
		'post_status' => 'publish',
		'post_title'  => 'temporal',
		'post_author' => $creator_id, // <--- AQUÍ
	], true);

    if ( is_wp_error($ticket_id) || ! $ticket_id ) {
        evapp_embed_render_form($event_id, ['No se pudo crear el ticket. Inténtalo nuevamente.'], $_POST); return;
    }

    // Guardar metadatos base
    update_post_meta($ticket_id, '_eventosapp_ticket_evento_id',   $event_id);
    update_post_meta($ticket_id, '_eventosapp_ticket_user_id', $creator_id);
    update_post_meta($ticket_id, '_eventosapp_asistente_nombre',   $first);
    update_post_meta($ticket_id, '_eventosapp_asistente_apellido', $last);
    update_post_meta($ticket_id, '_eventosapp_asistente_cc',       $cc);
    update_post_meta($ticket_id, '_eventosapp_asistente_email',    $email);
    update_post_meta($ticket_id, '_eventosapp_asistente_tel',      $phone);
    update_post_meta($ticket_id, '_eventosapp_asistente_empresa',  $company);
    update_post_meta($ticket_id, '_eventosapp_asistente_nit',      $nit);
    update_post_meta($ticket_id, '_eventosapp_asistente_cargo',    $role);
    update_post_meta($ticket_id, '_eventosapp_asistente_ciudad',   $city);
update_post_meta($ticket_id, '_eventosapp_asistente_pais',     $country); // antes estaba fijo en 'Colombia'
    update_post_meta($ticket_id, '_eventosapp_asistente_localidad', $localidad);

    // Evidencia de consentimiento
    update_post_meta($ticket_id, '_eventosapp_privacy_accepted', '1');
    update_post_meta($ticket_id, '_eventosapp_privacy_accepted_at', current_time('mysql'));
    if ( ! empty($_SERVER['REMOTE_ADDR']) ) {
        update_post_meta($ticket_id, '_eventosapp_privacy_ip', sanitize_text_field( wp_unslash($_SERVER['REMOTE_ADDR']) ) );
    }

    // Guardar extras
    if ($extras_schema) {
        foreach ($extras_schema as $f){
            $k   = (string)($f['key'] ?? '');
            if ($k==='') continue;
            $raw = isset($extras_in[$k]) ? wp_unslash($extras_in[$k]) : '';
            $val = function_exists('eventosapp_normalize_extra_value') ? eventosapp_normalize_extra_value($f, $raw) : sanitize_text_field($raw);
            update_post_meta($ticket_id, '_eventosapp_extra_'.$k, $val);
        }
    }

    // Canal de creación: Inscripción Usuario
    update_post_meta($ticket_id, '_eventosapp_creation_channel', 'public');

    // Generar ID público + secuencia + título
    if ( function_exists('eventosapp_generate_unique_ticket_id') ) {
        $public_id = eventosapp_generate_unique_ticket_id();
    } else { $public_id = wp_generate_uuid4(); }
    update_post_meta($ticket_id, 'eventosapp_ticketID', $public_id);

    if ( function_exists('eventosapp_next_event_sequence') ) {
        $seq = eventosapp_next_event_sequence($event_id);
        update_post_meta($ticket_id, '_eventosapp_ticket_seq', (int)$seq);
    }
    wp_update_post(['ID'=>$ticket_id, 'post_title'=>$public_id]);

    // Generar PDF/ICS si corresponde
    if ( function_exists('eventosapp_ticket_generar_pdf') ) eventosapp_ticket_generar_pdf($ticket_id);
    if ( function_exists('eventosapp_ticket_generar_ics') ) eventosapp_ticket_generar_ics($ticket_id);

    // Reindex
    if ( function_exists('eventosapp_ticket_build_search_blob') ) eventosapp_ticket_build_search_blob($ticket_id);

    // Email auto si está activo en el evento
    $auto_email = get_post_meta($event_id, '_eventosapp_ticket_auto_email_public', true) === '1';
    if ( $auto_email && function_exists('eventosapp_build_ticket_email_html') ) {
        $attachments = [];
        $pdf_on = get_post_meta($event_id, '_eventosapp_ticket_pdf', true) === '1';
        $ics_on = get_post_meta($event_id, '_eventosapp_ticket_ics', true) === '1';
        if ($pdf_on && function_exists('eventosapp_url_to_path')) {
            $pdf_url  = get_post_meta($ticket_id, '_eventosapp_ticket_pdf_url', true);
            $pdf_path = $pdf_url ? eventosapp_url_to_path($pdf_url) : '';
            if ($pdf_path && file_exists($pdf_path)) $attachments[] = $pdf_path;
        }
        if ($ics_on && function_exists('eventosapp_url_to_path')) {
            $ics_url  = get_post_meta($ticket_id, '_eventosapp_ticket_ics_url', true);
            $ics_path = $ics_url ? eventosapp_url_to_path($ics_url) : '';
            if ($ics_path && file_exists($ics_path)) $attachments[] = $ics_path;
        }
        $subject = 'Tu ticket para ' . get_the_title($event_id);
        $html    = eventosapp_build_ticket_email_html($ticket_id);
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $to      = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
        if ( is_email($to) ) { @wp_mail($to, $subject, $html, $headers, $attachments); }
    }

    // Redirecciones
    $by_loc    = get_post_meta($event_id, '_evapp_embed_by_localidad', true) === '1';
    $success_url = get_post_meta($event_id, '_evapp_embed_success_url', true) ?: '';
    $cond        = get_post_meta($event_id, '_evapp_embed_cond_redirect', true) === '1';
    $map         = get_post_meta($event_id, '_evapp_embed_redirect_map', true);
    if (!is_array($map)) $map = [];
    $target = '';
    if (!$by_loc && $cond && $localidad && isset($map[$localidad]) && $map[$localidad]) {
        $target = $map[$localidad];
    } elseif ($success_url) {
        $target = $success_url;
    }

    if ($target) {
        $target_js = esc_js($target);
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Redirigiendo…</title></head><body>';
        echo '<script>try{window.top.location.href="'.$target_js.'";}catch(e){window.location.href="'.$target_js.'";}</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url='.esc_attr($target).'"></noscript>';
        echo '</body></html>';
        exit;
    }

    // Fallback: mensaje de éxito dentro del iframe
    $title = get_the_title($event_id);
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Registro completado</title>';
    echo '<style>.evapp-ef-wrap{max-width:720px;margin:20px auto;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif}.evapp-ef-success{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:14px;border-radius:10px}</style>';
    echo '</head><body><div class="evapp-ef-wrap">';
    echo '<div class="evapp-ef-success"><b>¡Registro completado!</b><br>Pronto recibirás tu ticket por correo electrónico para <b>'.esc_html($title).'</b>.</div>';
    echo '</div></body></html>';
}

function evapp_embed_render_error($code = 400, $msg = 'Error') {
    status_header((int)$code);
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>EventosApp — Error</title>';
    echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;background:#f8fafc;margin:0;padding:24px}';
    echo '.box{max-width:720px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;color:#111827}';
    echo '.small{color:#6b7280;font-size:12px}</style></head><body>';
    echo '<div class="box"><h3 style="margin:0 0 8px 0">No se puede mostrar el formulario</h3><div>'.esc_html($msg).'</div>';
    echo '<div class="small" style="margin-top:10px">Si eres el organizador, revisa el token y la publicación del evento.</div></div>';
    echo '</body></html>';
}
