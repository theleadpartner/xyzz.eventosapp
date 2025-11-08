<?php
// includes/frontend/eventosapp-public-register.php
if ( ! defined('ABSPATH') ) exit;

/* ======================== Helpers ======================== */
if ( ! function_exists('eventosapp_get_autogestion_user_id') ) {
    function eventosapp_get_autogestion_user_id() {
        $login = 'autogestion1';
        $email = 'autogestion1@eventosapp.com';
        $pass  = '_123456_';

        $u = get_user_by('login', $login);
        if ( ! $u ) $u = get_user_by('email', $email);

        if ( ! $u ) {
            $user_id = wp_create_user($login, $pass, $email);
            if ( is_wp_error($user_id) ) return 0;
            wp_update_user([
                'ID'           => $user_id,
                'first_name'   => 'autogestion',
                'last_name'    => '1',
                'display_name' => 'autogestion 1'
            ]);
            $u = get_user_by('id', $user_id);
        } else {
            if ( ! get_user_meta($u->ID, 'first_name', true) ) update_user_meta($u->ID, 'first_name', 'autogestion');
            if ( ! get_user_meta($u->ID, 'last_name',  true) ) update_user_meta($u->ID, 'last_name',  '1');
        }
        return $u ? intval($u->ID) : 0;
    }
}

if ( ! function_exists('eventosapp_public_success_box') ) {
    function eventosapp_public_success_box() {
        ob_start(); ?>
        <div class="evapp-success" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:14px;border-radius:10px;margin:10px 0;">
            <strong>¡Registro completado!</strong><br>
            Próximamente recibirás tu ticket por correo electrónico.
            <br><small style="color:#0f5132;">Si no lo ves en unos minutos, revisa tu carpeta de spam o promociones.</small>
        </div>
        <?php
        return ob_get_clean();
    }
}

/* ================== AJAX: envío sin recarga ================== */
add_action('wp_ajax_eventosapp_pubreg_submit',        'eventosapp_pubreg_submit');
add_action('wp_ajax_nopriv_eventosapp_pubreg_submit', 'eventosapp_pubreg_submit');

function eventosapp_pubreg_submit(){
    header('Content-Type: application/json; charset=' . get_option('blog_charset'));

    // Nonce
    check_ajax_referer('eventosapp_pubreg_ajax','nonce');

    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' || get_post_status($event_id) !== 'publish' ) {
        wp_send_json_error(['message'=>'Error: evento no válido.']);
    }

    // Idempotencia
    $ev_token = isset($_POST['evpub_token']) ? sanitize_text_field( wp_unslash($_POST['evpub_token']) ) : '';
    $tval     = $ev_token ? get_transient('evpub_token_'.$ev_token) : false;
    if ( empty($ev_token) || false === $tval ) {
        wp_send_json_error(['message'=>'Esta solicitud ya fue procesada o el formulario expiró. Recarga e inténtalo de nuevo.', 'code'=>'token']);
    }
    delete_transient('evpub_token_'.$ev_token);

    // Honeypot
    if ( ! empty($_POST['evapp_hp']) ) {
        wp_send_json_error(['message'=>'Error de validación.']);
    }

    // Datos
    $nombre   = sanitize_text_field( wp_unslash($_POST['first_name'] ?? '') );
    $apellido = sanitize_text_field( wp_unslash($_POST['last_name']  ?? '') );
    $cc       = sanitize_text_field( wp_unslash($_POST['cc']         ?? '') );
    $email    = sanitize_email(      wp_unslash($_POST['email']      ?? '') );
    $tel      = sanitize_text_field( wp_unslash($_POST['phone']      ?? '') );
    $empresa  = sanitize_text_field( wp_unslash($_POST['company']    ?? '') );
    $nit      = sanitize_text_field( wp_unslash($_POST['nit']        ?? '') );
    $cargo    = sanitize_text_field( wp_unslash($_POST['role']       ?? '') );
    $ciudad   = sanitize_text_field( wp_unslash($_POST['city']       ?? '') );
    $pais     = sanitize_text_field( wp_unslash($_POST['country']    ?? 'Colombia') );
    $localidad_override = sanitize_text_field( wp_unslash($_POST['localidad_override'] ?? '') );
    $privacy_ok = !empty($_POST['privacy_accept']) && $_POST['privacy_accept'] === '1';

    // Validación
    $errors = [];
    if ($nombre==='')   $errors[] = 'El campo Nombres es obligatorio.';
    if ($apellido==='') $errors[] = 'El campo Apellidos es obligatorio.';
    if ($cc==='')       $errors[] = 'La Cédula de Ciudadanía es obligatoria.';
    if ( ! is_email($email) ) $errors[] = 'Debes ingresar un email válido.';
    if ($tel==='')      $errors[] = 'El número de contacto es obligatorio.';
    if ( ! $privacy_ok ) $errors[] = 'Debes autorizar el tratamiento de datos personales para continuar.';

    // Extras
    $extras_schema = function_exists('eventosapp_get_event_extra_fields') ? eventosapp_get_event_extra_fields($event_id) : [];
    $extras_in = isset($_POST['pub_extra']) && is_array($_POST['pub_extra']) ? $_POST['pub_extra'] : [];
    foreach ((array)$extras_schema as $f){
        if (!empty($f['required'])) {
            $k = $f['key']; $v = trim((string)($extras_in[$k] ?? ''));
            if ($v === '') $errors[] = 'El campo "'.esc_html($f['label']).'" es obligatorio.';
        }
    }

    if ( ! empty($errors) ) {
        $html = '<div style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;padding:12px;border-radius:10px;margin:10px 0;">'
              . '<strong>Revisa el formulario:</strong><ul style="margin:6px 0 0 18px;">';
        foreach ($errors as $e) $html .= '<li>'.esc_html($e).'</li>';
        $html .= '</ul></div>';
        wp_send_json_error(['message'=>'Hay errores en el formulario.','errors_html'=>$html]);
    }

    // Autor: si está logueado usarlo; si no, autogestion1
    $creator_id = is_user_logged_in() ? get_current_user_id() : eventosapp_get_autogestion_user_id();

    // Crear ticket
    $post_id = wp_insert_post([
        'post_type'   => 'eventosapp_ticket',
        'post_status' => 'publish',
        'post_title'  => 'temporal',
        'post_author' => $creator_id,
    ], true);

    if ( is_wp_error($post_id) || ! $post_id ) {
        wp_send_json_error(['message'=>'No se pudo crear el ticket. Inténtalo nuevamente.']);
    }

    // Metas base
    update_post_meta($post_id, '_eventosapp_ticket_evento_id',    $event_id);
    update_post_meta($post_id, '_eventosapp_ticket_user_id',      $creator_id);
    update_post_meta($post_id, '_eventosapp_asistente_nombre',    $nombre);
    update_post_meta($post_id, '_eventosapp_asistente_apellido',  $apellido);
    update_post_meta($post_id, '_eventosapp_asistente_cc',        $cc);
    update_post_meta($post_id, '_eventosapp_asistente_email',     $email);
    update_post_meta($post_id, '_eventosapp_asistente_tel',       $tel);
    update_post_meta($post_id, '_eventosapp_asistente_empresa',   $empresa);
    update_post_meta($post_id, '_eventosapp_asistente_nit',       $nit);
    update_post_meta($post_id, '_eventosapp_asistente_cargo',     $cargo);
    update_post_meta($post_id, '_eventosapp_asistente_ciudad',    $ciudad);
    update_post_meta($post_id, '_eventosapp_asistente_pais',      $pais);
    update_post_meta($post_id, '_eventosapp_creation_channel',    'public');

    // Consentimiento
    update_post_meta($post_id, '_eventosapp_privacy_accepted', '1');
    update_post_meta($post_id, '_eventosapp_privacy_accepted_at', current_time('mysql'));
    if ( ! empty($_SERVER['REMOTE_ADDR']) ) {
        update_post_meta($post_id, '_eventosapp_privacy_ip', sanitize_text_field( wp_unslash($_SERVER['REMOTE_ADDR']) ) );
    }

    // Localidad fija (si aplica)
    if ($localidad_override !== '') {
        update_post_meta($post_id, '_eventosapp_asistente_localidad', $localidad_override);
    }

    // Guardar extras
    if ($extras_schema) {
        foreach ($extras_schema as $f){
            $k   = $f['key'];
            $raw = isset($extras_in[$k]) ? wp_unslash($extras_in[$k]) : '';
            $val = function_exists('eventosapp_normalize_extra_value') ? eventosapp_normalize_extra_value($f, $raw) : sanitize_text_field($raw);
            update_post_meta($post_id, '_eventosapp_extra_'.$k, $val);
        }
    }

    // ID público / secuencia / título
    if ( function_exists('eventosapp_generate_unique_ticket_id') && function_exists('eventosapp_next_event_sequence') ) {
        $public_id = eventosapp_generate_unique_ticket_id();
        update_post_meta($post_id, 'eventosapp_ticketID', $public_id);
        $seq = eventosapp_next_event_sequence($event_id);
        update_post_meta($post_id, '_eventosapp_ticket_seq', $seq);
        wp_update_post(['ID'=>$post_id, 'post_title'=>$public_id]);
    } else {
        $public_id = get_post_meta($post_id, 'eventosapp_ticketID', true);
        if ( ! $public_id ) $public_id = '#'.$post_id;
    }

    // PDF/ICS, índice
    if ( function_exists('eventosapp_ticket_generar_pdf') ) eventosapp_ticket_generar_pdf($post_id);
    if ( function_exists('eventosapp_ticket_generar_ics') )  eventosapp_ticket_generar_ics($post_id);
    if ( function_exists('eventosapp_ticket_build_search_blob') ) eventosapp_ticket_build_search_blob($post_id);

    // Email automático si está activo
    $auto_email = get_post_meta($event_id, '_eventosapp_ticket_auto_email_public', true) === '1';
    if ( $auto_email && function_exists('eventosapp_build_ticket_email_html') ) {
        $attachments = [];
        $pdf_on = get_post_meta($event_id, '_eventosapp_ticket_pdf', true) === '1';
        $ics_on = get_post_meta($event_id, '_eventosapp_ticket_ics', true) === '1';
        if ($pdf_on && function_exists('eventosapp_url_to_path')) {
            $pdf_url = get_post_meta($post_id, '_eventosapp_ticket_pdf_url', true);
            $pdf_path = $pdf_url ? eventosapp_url_to_path($pdf_url) : '';
            if ($pdf_path && file_exists($pdf_path)) $attachments[] = $pdf_path;
        }
        if ($ics_on && function_exists('eventosapp_url_to_path')) {
            $ics_url = get_post_meta($post_id, '_eventosapp_ticket_ics_url', true);
            $ics_path = $ics_url ? eventosapp_url_to_path($ics_url) : '';
            if ($ics_path && file_exists($ics_path)) $attachments[] = $ics_path;
        }
        $subject = 'Tu ticket para ' . get_the_title($event_id);
        $html    = eventosapp_build_ticket_email_html($post_id);
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $to      = get_post_meta($post_id, '_eventosapp_asistente_email', true);
        if ( is_email($to) ) { @wp_mail($to, $subject, $html, $headers, $attachments); }
    }

    $success_html = eventosapp_public_success_box();
    wp_send_json_success(['tid'=>$public_id, 'html'=>$success_html]);
}

/* ================== Shortcode público ================== */
/**
 * Uso: [eventosapp_public_register event_id="123" localidad="VIP"]
 */
add_shortcode('eventosapp_public_register', function( $atts ){
    $a = shortcode_atts(['event_id'=>0,'localidad'=>''], $atts, 'eventosapp_public_register');

    $event_id = absint($a['event_id']);
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' || get_post_status($event_id) !== 'publish' ) {
        return '<div class="notice" style="background:#fff3f3;border:1px solid #e5d0d0;padding:12px;border-radius:8px;color:#a33;">
            Error: debes configurar correctamente el <b>event_id</b> del evento.
        </div>';
    }

    $localidad_override = trim((string)$a['localidad']);

    // Metadatos de privacidad
    $priv_empresa  = trim((string) get_post_meta($event_id, '_eventosapp_priv_empresa', true));
    if ($priv_empresa==='') $priv_empresa = get_post_meta($event_id, '_eventosapp_organizador', true) ?: get_bloginfo('name');
    $priv_politica = esc_url( get_post_meta($event_id, '_eventosapp_priv_politica_url', true) );
    $priv_aviso    = esc_url( get_post_meta($event_id, '_eventosapp_priv_aviso_url', true) );

    // Success por URL (compartible)
    if ( isset($_GET['evapp_ok']) && $_GET['evapp_ok'] === '1' ) {
        return eventosapp_public_success_box();
    }

    // CSS (incluye hover/active y estado "cargando")
    $rwd_css = <<<CSS
.evapp-form{max-width:720px;margin:0 auto;background:#f9fafb;border:1px solid #e5e7eb;padding:16px;border-radius:12px}
.evapp-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.evapp-grid-1{display:grid;grid-template-columns:1fr;gap:12px}
.evapp-grid-auto{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.evapp-field{width:100%}
.evapp-actions{margin-top:16px}
.evapp-consent{margin-top:18px;padding:12px 12px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff}

/* Botón con estados */
.evapp-btn{
  position:relative;
  transition:transform .06s ease, box-shadow .2s ease, opacity .2s ease, padding-right .2s ease, filter .2s ease;
}
.evapp-btn:not(.is-loading):hover{
  box-shadow:0 6px 18px rgba(0,0,0,.12);
  filter:brightness(1.02);
  transform:translateY(-1px);
}
.evapp-btn:not(.is-loading):active{
  transform:translateY(1px) scale(.99);
  box-shadow:inset 0 2px 6px rgba(0,0,0,.16);
}
.evapp-btn.is-loading{
  opacity:.9;
  cursor:progress;
  pointer-events:none;
  padding-right:2.2rem;
}
.evapp-btn.is-loading::after{
  content:"";
  position:absolute;
  right:.7rem;
  top:50%; margin-top:-8px;
  width:16px; height:16px;
  border:2px solid rgba(255,255,255,.55);
  border-top-color:#fff;
  border-radius:50%;
  animation:evspin .7s linear infinite;
}

@keyframes evspin{to{transform:rotate(360deg)}}

.evapp-feedback{display:none;align-items:center;gap:8px;margin-top:8px;color:#475569;font-size:14px}
.evapp-feedback .spin{width:16px;height:16px;border:2px solid #94a3b8;border-top-color:#0ea5e9;border-radius:50%;animation:evspin .8s linear infinite}

@media (max-width:680px){
  .evapp-form{padding:14px}
  .evapp-grid-2{grid-template-columns:1fr}
  .evapp-actions .button{width:100%}
}
CSS;

    // Token de idempotencia (5 min) solo UI
    $evpub_token = wp_generate_uuid4();
    set_transient('evpub_token_'.$evpub_token, 1, 5 * MINUTE_IN_SECONDS);

    ob_start(); ?>

    <style><?php echo $rwd_css; ?></style>

    <div class="evapp-wrapper" id="<?php echo esc_attr('evpub-'.wp_generate_uuid4()); ?>">
      <div class="evapp-msg"></div>

      <form method="post" class="evapp-form" autocomplete="off">
        <?php wp_nonce_field('evapp_pubreg', 'evapp_pubreg_nonce'); /* fallback no-JS */ ?>
        <input type="hidden" name="evpub_token" value="<?php echo esc_attr($evpub_token); ?>">
        <input type="text" name="evapp_hp" value="" style="display:none!important;" tabindex="-1" autocomplete="off">

        <h3 style="margin-top:0;">Registro de Asistente</h3>

        <div class="evapp-grid-2">
            <div>
                <label>Nombres *</label>
                <input type="text" name="first_name" required class="evapp-field" style="padding:8px;border:1px solid #d1d5db;border-radius:8px;">
            </div>
            <div>
                <label>Apellidos *</label>
                <input type="text" name="last_name" required class="evapp-field" style="padding:8px;border:1px solid #d1d5db;border-radius:8px;">
            </div>
        </div>

        <div class="evapp-grid-2" style="margin-top:10px;">
            <div>
                <label>Cédula de Ciudadanía *</label>
                <input type="text" name="cc" required class="evapp-field" style="padding:8px;border:1px solid #d1d5db;border-radius:8px;">
            </div>
            <div>
                <label>Número de contacto *</label>
                <input type="text" name="phone" required class="evapp-field" style="padding:8px;border:1px solid #d1d5db;border-radius:8px;">
            </div>
        </div>

        <div class="evapp-grid-1" style="margin-top:10px;">
            <div>
                <label>Email *</label>
                <input type="email" name="email" required class="evapp-field" style="padding:8px;border:1px solid #d1d5db;border-radius:8px;">
            </div>
        </div>

        <div class="evapp-grid-2" style="margin-top:10px;">
            <div>
                <label>Nombre de Empresa</label>
                <input type="text" name="company" class="evapp-field" style="padding:8px;border:1px solid #d1d5db;border-radius:8px;">
            </div>
            <div>
                <label>NIT</label>
                <input type="text" name="nit" class="evapp-field" style="padding:8px;border:1px solid #d1d5db;border-radius:8px;">
            </div>
        </div>

        <div class="evapp-grid-1" style="margin-top:10px;">
            <div>
                <label>Cargo</label>
                <input type="text" name="role" class="evapp-field" style="padding:8px;border:1px solid #d1d5db;border-radius:8px;">
            </div>
        </div>

        <div class="evapp-grid-2" style="margin-top:10px;">
            <div>
                <label>Ciudad</label>
                <input type="text" name="city" class="evapp-field" style="padding:8px;border:1px solid #d1d5db;border-radius:8px;">
            </div>
            <div>
                <label>País</label>
                <select name="country" class="evapp-field" style="padding:8px;border:1px solid #d1d5db;border-radius:8px;">
                    <?php
                    $countries = function_exists('eventosapp_get_countries') ? eventosapp_get_countries() : array('Colombia');
                    foreach ($countries as $c) {
                        $sel = ($c==='Colombia') ? ' selected' : '';
                        echo '<option value="'.esc_attr($c).'"'.$sel.'>'.esc_html($c).'</option>';
                    }
                    ?>
                </select>
            </div>
        </div>

        <?php if ( $localidad_override !== '' ): ?>
            <p class="muted" style="margin:12px 0 0;color:#6b7280;font-size:13px">
                * Este registro aplica para la localidad <strong><?php echo esc_html($localidad_override); ?></strong>.
            </p>
        <?php endif; ?>

        <?php
        $extras_schema = function_exists('eventosapp_get_event_extra_fields') ? eventosapp_get_event_extra_fields($event_id) : [];
        if ($extras_schema) {
            echo '<div style="margin-top:8px"><b>Campos adicionales</b></div>';
            echo '<div class="evapp-grid-auto" style="margin-top:8px">';
            foreach ($extras_schema as $f){
                $name = 'pub_extra['.$f['key'].']';
                echo '<div><label>'.esc_html($f['label']).(!empty($f['required'])?' *':'').'</label>';
                if (($f['type'] ?? '')==='number') {
                    echo '<input type="number" name="'.$name.'" class="evapp-field" style="padding:.6rem;border:1px solid #dfe3e7;border-radius:10px">';
                } elseif (($f['type'] ?? '')==='select') {
                    echo '<select name="'.$name.'" class="evapp-field" style="padding:.6rem;border:1px solid #dfe3e7;border-radius:10px"><option value="">Seleccione…</option>';
                    foreach ((array)($f['options'] ?? []) as $op){
                        echo '<option value="'.esc_attr($op).'">'.esc_html($op).'</option>';
                    }
                    echo '</select>';
                } else {
                    echo '<input type="text" name="'.$name.'" class="evapp-field" style="padding:.6rem;border:1px solid #dfe3e7;border-radius:10px">';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        ?>

        <!-- Consentimiento -->
        <div class="evapp-consent">
            <p style="margin:0 0 8px;color:#444;line-height:1.45">
                Al marcar la siguiente casilla, autoriza expresamente el tratamiento de sus datos personales,
                por parte de la <strong><?php echo esc_html($priv_empresa); ?></strong>, conforme a la
                <?php
                echo $priv_politica
                    ? '<a href="'.esc_url($priv_politica).'" target="_blank" rel="noopener noreferrer">Política de tratamiento de datos personales</a>'
                    : 'Política de tratamiento de datos personales';
                ?>
                y el
                <?php
                echo $priv_aviso
                    ? '<a href="'.esc_url($priv_aviso).'" target="_blank" rel="noopener noreferrer">Aviso de Privacidad</a>'
                    : 'Aviso de Privacidad';
                ?>.
            </p>
            <label style="display:flex;align-items:center;gap:8px;margin-top:8px;">
                <input type="checkbox" name="privacy_accept" value="1" required>
                <span>Autorizo el tratamiento de mis datos personales</span>
            </label>
        </div>

        <div class="evapp-actions">
            <button type="submit" class="button button-primary evapp-btn">Finalizar registro</button>
            <div class="evapp-feedback"><span class="spin" aria-hidden="true"></span><span>Procesando tu registro…</span></div>
        </div>

        <?php if ( $localidad_override !== '' ): ?>
            <input type="hidden" name="localidad_override" value="<?php echo esc_attr($localidad_override); ?>">
        <?php endif; ?>
      </form>

      <script>
      (function(){
        var root = document.currentScript.parentNode.parentNode;
        var form = root.querySelector('form.evapp-form');
        if(!form) return;

        var btn  = form.querySelector('.evapp-btn');
        var fb   = form.querySelector('.evapp-feedback');
        var msg  = root.querySelector('.evapp-msg');

        var ajax = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
        var nonce = "<?php echo esc_js( wp_create_nonce('eventosapp_pubreg_ajax') ); ?>";
        var baseUrl = "<?php
            $current_url = ( function_exists('get_permalink') && is_singular() ) ? get_permalink() : home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ?? '' ) );
            echo esc_js( remove_query_arg( array('evapp_ok','tid'), $current_url ) );
        ?>";
        var eventId = "<?php echo (int) $event_id; ?>";

        function showMessage(html){
          if(!msg){ msg = document.createElement('div'); root.insertBefore(msg, form); }
          msg.innerHTML = html || '';
          msg.scrollIntoView({behavior:'smooth', block:'start'});
        }

        form.addEventListener('submit', function(e){
          e.preventDefault();

          if(btn){
            // Cambia texto mientras envía
            btn.dataset.originalText = btn.textContent;
            btn.textContent = 'Enviando…';
            btn.disabled = true;
            btn.classList.add('is-loading');
            btn.setAttribute('aria-busy','true');
          }
          if(fb) fb.style.display = 'flex';
          if(msg) msg.innerHTML = '';

          var fd = new FormData(form);
          fd.append('action', 'eventosapp_pubreg_submit');
          fd.append('nonce',  nonce);
          fd.append('event_id', eventId);

          fetch(ajax, { method:'POST', credentials:'same-origin', body:fd })
            .then(r => r.json())
            .then(function(resp){
              if(resp && resp.success){
                // Mostrar éxito y ocultar formulario
                showMessage(resp.data && resp.data.html ? resp.data.html : '<div class="evapp-success">¡Registro completado!</div>');
                form.style.display = 'none';
                // Reflejar estado en URL (compartible)
                try{
                  if(history && history.replaceState){
                    var url = baseUrl + (baseUrl.indexOf('?')>-1 ? '&' : '?') + 'evapp_ok=1' + (resp.data.tid ? '&tid='+encodeURIComponent(resp.data.tid) : '');
                    history.replaceState({}, document.title, url);
                  }
                }catch(e){}
              } else {
                var html = (resp && resp.data && resp.data.errors_html) ? resp.data.errors_html :
                           (resp && resp.data && resp.data.message) ? '<div style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:10px;border-radius:10px;margin:10px 0;">'+resp.data.message+'</div>' :
                           '<div style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:10px;border-radius:10px;margin:10px 0;">No fue posible procesar el registro.</div>';
                showMessage(html);
              }
            })
            .catch(function(){
              showMessage('<div style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:10px;border-radius:10px;margin:10px 0;">Error de red. Inténtalo nuevamente.</div>');
            })
            .finally(function(){
              if(btn){
                btn.disabled = false;
                btn.classList.remove('is-loading');
                btn.removeAttribute('aria-busy');
                if(btn.dataset.originalText){ btn.textContent = btn.dataset.originalText; }
              }
              if(fb) fb.style.display = 'none';
            });
        }, {passive:false});
      })();
      </script>
    </div>

    <?php
    if ( function_exists('eventosapp_enqueue_lookup_js') ) {
        eventosapp_enqueue_lookup_js($event_id); // autocompletado si aplica
    }
    return ob_get_clean();
});
