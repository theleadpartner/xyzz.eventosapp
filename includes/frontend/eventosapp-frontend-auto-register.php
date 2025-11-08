<?php
/**
 * Frontend: Auto-Registro público por evento (con creación automática de landing)
 * - Crea/gestiona una página hija de /autoregistro/ para cada evento
 * - Shortcode: [eventosapp_front_auto_register event_id="123"]
 * - Formulario público con campos: Nombres, Apellidos, CC, Email, Tel, Empresa, NIT, Cargo, Ciudad, País (Colombia por defecto)
 * - Envío 100% por AJAX: crea ticket y muestra confirmación en la misma página (sin recargar)
 *
 * NOTA sobre correo duplicado:
 *  El envío de correo lo maneja el flujo central. Si necesitas enviar también desde aquí:
 *      define('EVENTOSAPP_FRONTEND_SENDS_EMAIL', true);
 */

if ( ! defined('ABSPATH') ) exit;

/* ============================================================
 *  Helper: obtener/crear el usuario "autogestion1"
 * ============================================================ */
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

/* ============================================================
 *  Helpers de Landing (/autoregistro/{slug})
 * ============================================================ */

if ( ! function_exists('eventosapp_autoreg_parent_slug') ) {
    function eventosapp_autoreg_parent_slug() { return 'autoregistro'; }
}
if ( ! function_exists('eventosapp_autoreg_parent_title') ) {
    function eventosapp_autoreg_parent_title() { return 'Autoregistro'; }
}

/** Obtiene o crea (si falta) la página padre /autoregistro/ */
if ( ! function_exists('eventosapp_autoreg_ensure_parent') ) {
    function eventosapp_autoreg_ensure_parent() {
        $parent_slug  = eventosapp_autoreg_parent_slug();
        $parent       = get_page_by_path($parent_slug);
        if ( $parent && $parent->post_type === 'page' && $parent->post_status !== 'trash' ) {
            return (int) $parent->ID;
        }
        $parent_id = wp_insert_post([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => eventosapp_autoreg_parent_title(),
            'post_name'   => $parent_slug,
            'post_parent' => 0,
            'post_content'=> '',
        ], true);
        return is_wp_error($parent_id) ? 0 : (int) $parent_id;
    }
}

/** Slug por defecto del evento (nombre con guiones) */
if ( ! function_exists('eventosapp_autoreg_default_slug_for_event') ) {
    function eventosapp_autoreg_default_slug_for_event($event_id) {
        $title = get_the_title($event_id);
        return sanitize_title($title ?: "evento-$event_id");
    }
}

/** Lee el slug guardado (o devuelve el default) */
if ( ! function_exists('eventosapp_autoreg_get_slug') ) {
    function eventosapp_autoreg_get_slug($event_id) {
        $slug = get_post_meta($event_id, '_eventosapp_autoreg_slug', true);
        if ( ! $slug ) $slug = eventosapp_autoreg_default_slug_for_event($event_id);
        return sanitize_title($slug);
    }
}

/** Construye la URL esperada a partir del slug */
if ( ! function_exists('eventosapp_autoreg_build_url') ) {
    function eventosapp_autoreg_build_url($event_id) {
        $parent_slug = eventosapp_autoreg_parent_slug();
        $slug        = eventosapp_autoreg_get_slug($event_id);
        return home_url( '/' . $parent_slug . '/' . $slug . '/' );
    }
}

/** Asegura (crea/actualiza) la página hija del evento y devuelve su ID */
if ( ! function_exists('eventosapp_autoreg_ensure_child_page') ) {
    function eventosapp_autoreg_ensure_child_page($event_id) {
        $event_id = (int) $event_id;
        if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) return 0;

        $parent_id = eventosapp_autoreg_ensure_parent();
        if ( ! $parent_id ) return 0;

        $page_id   = (int) get_post_meta($event_id, '_eventosapp_autoreg_page_id', true);
        $slug      = eventosapp_autoreg_get_slug($event_id);
        $title     = 'Registro: ' . get_the_title($event_id);
        $content   = '[eventosapp_front_auto_register event_id="'. $event_id .'"]';

        if ( $page_id ) {
            $p = get_post($page_id);
            if ( $p && $p->post_status !== 'trash' ) {
                $updates = ['ID' => $page_id]; $needs_update = false;
                if ( (int)$p->post_parent !== (int)$parent_id ) { $updates['post_parent'] = $parent_id; $needs_update = true; }
                if ( $p->post_name !== $slug )                { $updates['post_name']   = $slug;      $needs_update = true; }
                if ( $p->post_content !== $content )          { $updates['post_content']= $content;   $needs_update = true; }
                if ( $p->post_title !== $title )              { $updates['post_title']  = $title;     $needs_update = true; }
                if ( $p->post_status !== 'publish' )          { $updates['post_status'] = 'publish';  $needs_update = true; }
                if ( $needs_update ) wp_update_post($updates);
                update_post_meta($event_id, '_eventosapp_autoreg_url', get_permalink($page_id));
                return $page_id;
            }
        }

        $new_id = wp_insert_post([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'post_parent'    => $parent_id,
            'post_title'     => $title,
            'post_name'      => $slug,
            'post_content'   => $content,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ], true);

        if ( is_wp_error($new_id) || ! $new_id ) return 0;

        update_post_meta($event_id, '_eventosapp_autoreg_page_id', (int) $new_id);
        update_post_meta($event_id, '_eventosapp_autoreg_url', get_permalink($new_id));
        return (int) $new_id;
    }
}

/* ============================================================
 *  Metabox en el CPT evento: URL editable (slug) + botón copiar
 * ============================================================ */

add_action('add_meta_boxes', function(){
    add_meta_box(
        'eventosapp_event_autoreg',
        'Landing de Autoregistro',
        'eventosapp_render_metabox_autoreg',
        'eventosapp_event',
        'side',
        'high'
    );
});

function eventosapp_render_metabox_autoreg($post){
    $event_id = (int) $post->ID;
    $slug     = eventosapp_autoreg_get_slug($event_id);
    $page_id  = (int) get_post_meta($event_id, '_eventosapp_autoreg_page_id', true);
    $url_meta = get_post_meta($event_id, '_eventosapp_autoreg_url', true);
    $url_calc = eventosapp_autoreg_build_url($event_id);
    $url      = $url_meta ?: $url_calc;

    wp_nonce_field('eventosapp_autoreg_save', 'eventosapp_autoreg_nonce');
    ?>
    <style>
    .evapp-autoreg .muted{font-size:12px;color:#666}
    .evapp-autoreg input[type="text"]{width:100%}
    .evapp-autoreg .row{margin-bottom:8px}
    .evapp-autoreg .copy-btn{display:inline-block;margin-top:6px}
    </style>
    <div class="evapp-autoreg">
        <div class="row">
            <label><b>Slug del evento:</b></label>
            <input type="text" name="eventosapp_autoreg_slug" value="<?php echo esc_attr($slug); ?>" />
            <div class="muted">Se publicará como <code>/<?php echo esc_html(eventosapp_autoreg_parent_slug().'/'.$slug.'/'); ?></code></div>
        </div>

        <div class="row">
            <label><b>URL:</b></label>
            <input type="text" id="evapp_autoreg_url" value="<?php echo esc_url($url); ?>" readonly />
            <button type="button" class="button copy-btn" id="evapp_autoreg_copy">Copiar URL</button>
        </div>

        <div class="row">
            <?php if ($page_id): ?>
                <a href="<?php echo esc_url( get_permalink($page_id) ); ?>" class="button" target="_blank" rel="noopener">Ver página</a>
            <?php endif; ?>
            <button type="button" class="button" id="evapp_autoreg_ensure">Crear/Asegurar landing</button>
        </div>

        <p class="muted">La landing se crea/actualiza automáticamente al guardar el evento. El botón "Crear/Asegurar" la genera de inmediato.</p>
    </div>
    <script>
    (function($){
        $('#evapp_autoreg_copy').on('click', function(){
            var $i = $('#evapp_autoreg_url');
            $i[0].select(); $i[0].setSelectionRange(0, 99999);
            try { document.execCommand('copy'); } catch(e){}
            $(this).text('¡Copiado!').prop('disabled', true);
            setTimeout(()=>{ $('#evapp_autoreg_copy').text('Copiar URL').prop('disabled', false); }, 1200);
        });

        $('#evapp_autoreg_ensure').on('click', function(e){
            e.preventDefault();
            var data = {
                action: 'eventosapp_autoreg_ensure_now',
                event_id: <?php echo (int) $event_id; ?>,
                _wpnonce: '<?php echo esc_js( wp_create_nonce('eventosapp_autoreg_ensure_now') ); ?>',
                slug: jQuery('input[name="eventosapp_autoreg_slug"]').val() || ''
            };
            var $btn = $(this).prop('disabled', true).text('Procesando…');
            $.post(ajaxurl, data, function(resp){
                if (resp && resp.success) {
                    $('#evapp_autoreg_url').val(resp.data.url);
                    alert('Landing OK: ' + resp.data.url);
                } else {
                    alert('No se pudo asegurar la landing.');
                }
            }).always(function(){ $btn.prop('disabled', false).text('Crear/Asegurar landing'); });
        });
    })(jQuery);
    </script>
    <?php
}

/** AJAX: Crear/Asegurar landing desde el metabox */
add_action('wp_ajax_eventosapp_autoreg_ensure_now', function(){
    if ( ! current_user_can('edit_posts') ) wp_send_json_error('Unauthorized', 403);
    $ok = check_ajax_referer('eventosapp_autoreg_ensure_now', '_wpnonce', false);
    if ( ! $ok ) wp_send_json_error('Bad nonce', 403);

    $event_id = (int) ($_POST['event_id'] ?? 0);
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event') {
        wp_send_json_error('Evento inválido', 400);
    }

    $slug = isset($_POST['slug']) ? sanitize_title( wp_unslash($_POST['slug']) ) : '';
    if ($slug) update_post_meta($event_id, '_eventosapp_autoreg_slug', $slug);

    $page_id = eventosapp_autoreg_ensure_child_page($event_id);
    if ( ! $page_id ) wp_send_json_error('Error creando landing', 500);

    $url = get_permalink($page_id);
    update_post_meta($event_id, '_eventosapp_autoreg_url', $url);
    wp_send_json_success(['page_id' => $page_id, 'url' => $url]);
});

/* ============================================================
 *  ======== AJAX público: envío del formulario sin recarga ========
 *  action: eventosapp_evauto_submit
 * ============================================================ */
add_action('wp_ajax_eventosapp_evauto_submit',        'eventosapp_evauto_submit');
add_action('wp_ajax_nopriv_eventosapp_evauto_submit', 'eventosapp_evauto_submit');

function eventosapp_evauto_submit(){
    header('Content-Type: application/json; charset=' . get_option('blog_charset'));

    // Nonce AJAX
    check_ajax_referer('eventosapp_evauto_ajax', 'nonce');

    $eid = isset($_POST['evauto_event_id']) ? absint($_POST['evauto_event_id']) : 0;
    if ( ! $eid || get_post_type($eid) !== 'eventosapp_event' ) {
        wp_send_json_error(['message'=>'Evento no válido.']);
    }

    // Idempotencia
    $ev_token = isset($_POST['evauto_token']) ? sanitize_text_field( wp_unslash($_POST['evauto_token']) ) : '';
    $tval     = $ev_token ? get_transient('evauto_token_'.$ev_token) : false;
    if ( empty($ev_token) || false === $tval ) {
        wp_send_json_error(['message'=>'Esta solicitud ya fue procesada o expiró. Recarga la página e inténtalo de nuevo.', 'code'=>'token']);
    }
    delete_transient('evauto_token_'.$ev_token);

    // Campos
    $nombre    = sanitize_text_field( wp_unslash($_POST['as_nombre']   ?? '') );
    $apellido  = sanitize_text_field( wp_unslash($_POST['as_apellido'] ?? '') );
    $cc        = sanitize_text_field( wp_unslash($_POST['as_cc']       ?? '') );
    $email     = sanitize_email(      wp_unslash($_POST['as_email']    ?? '') );
    $tel       = sanitize_text_field( wp_unslash($_POST['as_tel']      ?? '') );
    $empresa   = sanitize_text_field( wp_unslash($_POST['as_empresa']  ?? '') );
    $nit       = sanitize_text_field( wp_unslash($_POST['as_nit']      ?? '') );
    $cargo     = sanitize_text_field( wp_unslash($_POST['as_cargo']    ?? '') );
    $ciudad    = sanitize_text_field( wp_unslash($_POST['as_ciudad']   ?? '') );
    $pais      = sanitize_text_field( wp_unslash($_POST['as_pais']     ?? 'Colombia') );

    // Validación básica
    if ( ! $nombre )  wp_send_json_error(['message'=>'El nombre es obligatorio.']);
    if ( ! $apellido )wp_send_json_error(['message'=>'El apellido es obligatorio.']);
    if ( ! $email || !is_email($email) ) wp_send_json_error(['message'=>'El email es obligatorio y debe ser válido.']);

    // Autor forzado
    $creator_id = eventosapp_get_autogestion_user_id();

    // Crear Ticket
    $post_id = wp_insert_post([
        'post_type'   => 'eventosapp_ticket',
        'post_status' => 'publish',
        'post_title'  => 'tmp',
        'post_author' => $creator_id,
    ], true);

    if ( is_wp_error($post_id) || ! $post_id ) {
        wp_send_json_error(['message'=>'No se pudo crear el ticket. Intenta nuevamente.']);
    }

    // Payload para hooks centrales
    $_POST['eventosapp_ticket_nonce']       = wp_create_nonce('eventosapp_ticket_guardar');
    $_POST['eventosapp_ticket_evento_id']   = $eid;
    $_POST['eventosapp_ticket_user_id']     = $creator_id;

    $_POST['eventosapp_asistente_nombre']   = $nombre;
    $_POST['eventosapp_asistente_apellido'] = $apellido;
    $_POST['eventosapp_asistente_cc']       = $cc;
    $_POST['eventosapp_asistente_email']    = $email;
    $_POST['eventosapp_asistente_tel']      = $tel;
    $_POST['eventosapp_asistente_empresa']  = $empresa;
    $_POST['eventosapp_asistente_nit']      = $nit;
    $_POST['eventosapp_asistente_cargo']    = $cargo;
    $_POST['eventosapp_asistente_ciudad']   = $ciudad;
    $_POST['eventosapp_asistente_pais']     = $pais;
    $_POST['eventosapp_asistente_localidad']= '';

    update_post_meta($post_id, '_eventosapp_creation_channel', 'public');
    update_post_meta($post_id, '_eventosapp_ticket_user_id',   $creator_id);

    // Disparar hooks (envíos centrales, etc.)
    do_action('save_post_eventosapp_ticket', $post_id, get_post($post_id), true);

    // Asegurar autor
    $curr_author = (int) get_post_field('post_author', $post_id);
    if ( $creator_id && $curr_author !== $creator_id ) {
        wp_update_post(['ID' => $post_id, 'post_author' => $creator_id]);
    }

    // Envío opcional desde este frontend (idempotente)
    if ( defined('EVENTOSAPP_FRONTEND_SENDS_EMAIL') && EVENTOSAPP_FRONTEND_SENDS_EMAIL && function_exists('eventosapp_send_ticket_email_now') ) {
        $sent_key = '_eventosapp_ticket_email_sent';
        $lock_key = '_eventosapp_ticket_email_sending';
        if ( ! get_post_meta($post_id, $sent_key, true) && ! get_post_meta($post_id, $lock_key, true) ) {
            add_post_meta($post_id, $lock_key, 1, true);
            try {
                eventosapp_send_ticket_email_now($post_id, [
                    'recipient' => $email,
                    'source'    => 'public',
                    'force'     => true,
                ]);
                update_post_meta($post_id, $sent_key, '1');
            } finally {
                delete_post_meta($post_id, $lock_key);
            }
        }
    }

    // Botones Wallet
    $wa_on = (get_post_meta($eid, '_eventosapp_ticket_wallet_android', true) === '1');
    $wi_on = (get_post_meta($eid, '_eventosapp_ticket_wallet_apple', true)   === '1');
    if ( $wa_on && function_exists('eventosapp_generar_enlace_wallet_android') ) {
        eventosapp_generar_enlace_wallet_android($post_id, false);
    }
    if ( $wi_on ) {
        if ( function_exists('eventosapp_apple_generate_pass') ) {
            eventosapp_apple_generate_pass($post_id, false);
        } elseif ( function_exists('eventosapp_generar_enlace_wallet_apple') ) {
            eventosapp_generar_enlace_wallet_apple($post_id);
        }
    }

    $ticket_code = get_post_meta($post_id, 'eventosapp_ticketID', true);
    $qr_url      = $ticket_code && function_exists('eventosapp_get_ticket_qr_url')
                    ? eventosapp_get_ticket_qr_url($ticket_code) : '';

    $wa_url = get_post_meta($post_id, '_eventosapp_ticket_wallet_android_url', true);
    $wi_url = get_post_meta($post_id, '_eventosapp_ticket_wallet_apple', true);
    if (!$wi_url) $wi_url = get_post_meta($post_id, '_eventosapp_ticket_wallet_apple_url', true);
    if (!$wi_url) $wi_url = get_post_meta($post_id, '_eventosapp_ticket_pkpass_url', true);

    $wallet_google_img = function_exists('eventosapp_asset_url_with_version')
        ? eventosapp_asset_url_with_version('assets/graphics/wallet_icons/google_wallet_btn.png')
        : '';
    $wallet_apple_img  = function_exists('eventosapp_asset_url_with_version')
        ? eventosapp_asset_url_with_version('assets/graphics/wallet_icons/apple_wallet_btn.png')
        : '';

    // HTML de éxito (igual al del flujo previo)
    ob_start(); ?>
    <div class="evauto-success" style="padding:14px;border:1px solid #bbf7d0;background:#ecfdf5;border-radius:12px;margin:12px 0;">
        <div style="font-weight:700;color:#065f46;margin-bottom:6px;">Inscripción confirmada</div>
        <div style="color:#064e3b">
            Ya puedes acercarte a realizar tu Check In. Hemos enviado el ticket a tu correo o también puedes mostrar la siguiente información:
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:12px;">
            <div><b>Nombre y Apellidos:</b><br><?php echo esc_html($nombre.' '.$apellido); ?></div>
            <?php if ($cc): ?><div><b>Cédula de Ciudadanía:</b><br><?php echo esc_html($cc); ?></div><?php endif; ?>
            <div><b>Email:</b><br><?php echo esc_html($email); ?></div>
            <?php if ($tel): ?><div><b>Número de Teléfono:</b><br><?php echo esc_html($tel); ?></div><?php endif; ?>
            <div><b>Número de Ticket:</b><br><?php echo esc_html($ticket_code ?: '#'.$post_id); ?></div>
            <div style="grid-column:1/-1;display:flex;align-items:center;gap:18px;margin-top:8px;">
                <?php if ($qr_url): ?>
                    <div>
                        <b>QR del ticket:</b><br>
                        <img src="<?php echo esc_url($qr_url); ?>" alt="QR Ticket" style="max-width:160px;border:1px solid #e5e7eb;border-radius:8px;padding:6px;background:#fff;">
                    </div>
                <?php endif; ?>

                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                    <?php if ($wa_url && $wallet_google_img): ?>
                        <a href="<?php echo esc_url($wa_url); ?>" target="_blank" rel="noopener" class="wallet-google" style="line-height:0;border-radius:12px;display:inline-block;">
                            <img src="<?php echo esc_url($wallet_google_img); ?>" alt="Añadir a Google Wallet" width="200" style="display:block;height:auto;border:0;outline:none;">
                        </a>
                    <?php endif; ?>
                    <?php if ($wi_url && $wallet_apple_img): ?>
                        <a href="<?php echo esc_url($wi_url); ?>" target="_blank" rel="noopener" class="wallet-apple" style="line-height:0;border-radius:12px;display:inline-block;">
                            <img src="<?php echo esc_url($wallet_apple_img); ?>" alt="Agregar a Apple Wallet" width="200" style="display:block;height:auto;border:0;outline:none;">
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    $success_html = ob_get_clean();

    wp_send_json_success([
        'tid'  => (string) ($ticket_code ?: ('#'.$post_id)),
        'html' => $success_html,
    ]);
}

/* ============================================================
 *  ======== Shortcode: [eventosapp_front_auto_register] ========
 * ============================================================ */
add_shortcode('eventosapp_front_auto_register', function($atts){
    $a   = shortcode_atts(['event_id' => 0], $atts);
    $eid = absint($a['event_id']);
    if ( ! $eid || get_post_type($eid) !== 'eventosapp_event' ) {
        return '<div style="padding:.8rem;border:1px solid #eee;background:#fff8f8;border-radius:8px;color:#a33;">Evento no válido.</div>';
    }

    // Encabezado del evento
    $title = get_the_title($eid);

    $tipo_fecha  = get_post_meta($eid, '_eventosapp_tipo_fecha', true) ?: 'unica';
    $fecha_label = '';
    if ($tipo_fecha === 'unica') {
        $f = get_post_meta($eid, '_eventosapp_fecha_unica', true);
        if ($f) $fecha_label = date_i18n('D, d M Y', strtotime($f));
    } elseif ($tipo_fecha === 'consecutiva') {
        $i = get_post_meta($eid, '_eventosapp_fecha_inicio', true);
        $f = get_post_meta($eid, '_eventosapp_fecha_fin', true);
        if ($i && $f) $fecha_label = date_i18n('D, d M Y', strtotime($i)).' → '.date_i18n('D, d M Y', strtotime($f));
    } else {
        $arr = get_post_meta($eid, '_eventosapp_fechas_noco', true);
        if(!is_array($arr)) $arr = (is_string($arr) && $arr) ? (array) $arr : [];
        if ($arr) {
            $lbl = [];
            foreach ($arr as $x) { if ($x) $lbl[] = date_i18n('D, d M Y', strtotime($x)); }
            $fecha_label = implode(' · ', $lbl);
        }
    }
    $hora_inicio = get_post_meta($eid, '_eventosapp_hora_inicio', true) ?: '';
    $hora_cierre = get_post_meta($eid, '_eventosapp_hora_cierre', true) ?: '';
    $lugar       = get_post_meta($eid, '_eventosapp_direccion', true) ?: '';

    // GET success (por si llega con ?evauto_ok=1&tid=...)
    $success = (isset($_GET['evauto_ok']) && (int)$_GET['evauto_ok'] === 1);
    $success_tid = sanitize_text_field( wp_unslash($_GET['tid'] ?? '') );

    // UI
    $uid = function_exists('wp_unique_id') ? wp_unique_id('evauto-') : ('evauto-'.uniqid());
    $current_url = ( function_exists('get_permalink') && is_singular() ) ? get_permalink() : home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ?? '' ) );
    $base_url    = remove_query_arg( array('evauto_ok','tid'), $current_url );

    ob_start(); ?>
    <div id="<?php echo esc_attr($uid); ?>" class="evauto-wrap" style="max-width:880px;margin:0 auto;">

        <style>
        .evauto-form label{ font-weight:700; display:block; margin-bottom:4px; }
        .evauto-form input[type="text"],
        .evauto-form input[type="email"],
        .evauto-form input[type="tel"],
        .evauto-form input[type="number"],
        .evauto-form select{
            background:#f3f4f6;
            border:1px solid #dfe3e7;
            border-radius:10px;
            padding:.6rem;
            width:100%;
        }
        .evauto-submit-btn{ position:relative; transition:transform .04s ease, box-shadow .2s ease, opacity .2s ease, padding-right .2s ease; }
        .evauto-submit-btn:active{ transform:translateY(1px) scale(.99); }
        .evauto-submit-btn.is-loading{ opacity:.9; cursor:progress; pointer-events:none; padding-right:2.2rem; }
        .evauto-submit-btn.is-loading::after{ content:""; position:absolute; right:.7rem; top:50%; margin-top:-8px; width:16px; height:16px; border:2px solid rgba(255,255,255,.55); border-top-color:#fff; border-radius:50%; animation:evspin .7s linear infinite; }
        @keyframes evspin{ to{ transform:rotate(360deg); } }
        .evauto-feedback{ display:none; align-items:center; gap:8px; margin-top:8px; color:#475569; font-size:14px; }
        .evauto-feedback .evauto-spinner{ width:16px; height:16px; border:2px solid #94a3b8; border-top-color:#0ea5e9; border-radius:50%; animation:evspin .8s linear infinite; }

        /* Aislar de Elementor (si aplica) */
        .evauto-wrap{ position:relative; isolation:isolate; z-index:0; }
        .evauto-wrap *::before, .evauto-wrap *::after { content: normal !important; }
        </style>

        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;box-shadow:0 1px 5px rgba(120,140,160,.06);margin-bottom:14px;">
            <h1 style="margin:0 0 6px;font-size:26px;"><?php echo esc_html($title); ?></h1>
            <div style="color:#475569;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                <div><b>Fecha del Evento:</b><br><?php echo $fecha_label ? esc_html($fecha_label) : '<span style="color:#94a3b8">—</span>'; ?></div>
                <div><b>Hora de Inicio y Cierre:</b><br><?php echo esc_html(($hora_inicio ?: '—').' a '.($hora_cierre ?: '—')); ?></div>
                <div><b>Lugar del evento:</b><br><?php echo $lugar ? esc_html($lugar) : '<span style="color:#94a3b8">—</span>'; ?></div>
            </div>
        </div>

        <?php if ( $success ): ?>
            <div class="evauto-success" style="padding:14px;border:1px solid #bbf7d0;background:#ecfdf5;border-radius:12px;margin:12px 0;">
                <div style="font-weight:700;color:#065f46;margin-bottom:6px;">Inscripción confirmada</div>
                <?php if ($success_tid): ?>
                    <div style="color:#064e3b">Tu número de ticket es <b><?php echo esc_html($success_tid); ?></b>.</div>
                <?php else: ?>
                    <div style="color:#064e3b">Tu inscripción fue registrada correctamente.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" class="evauto-form" <?php echo $success ? 'style="display:none;"' : ''; ?> style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;box-shadow:0 1px 5px rgba(120,140,160,.06)">
            <?php
            // Token de idempotencia (5 min) solo UI
            $evauto_token = wp_generate_uuid4();
            set_transient('evauto_token_'.$evauto_token, 1, 5 * MINUTE_IN_SECONDS);
            ?>
            <input type="hidden" name="evauto_token" value="<?php echo esc_attr($evauto_token); ?>"/>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
                <div>
                    <label>Nombres *</label>
                    <input type="text" name="as_nombre" required>
                </div>
                <div>
                    <label>Apellidos *</label>
                    <input type="text" name="as_apellido" required>
                </div>
                <div>
                    <label>Cédula de Ciudadanía</label>
                    <input type="text" name="as_cc">
                </div>
                <div>
                    <label>Email *</label>
                    <input type="email" name="as_email" required>
                </div>
                <div>
                    <label>Número de Contacto</label>
                    <input type="text" name="as_tel">
                </div>
                <div>
                    <label>Nombre de la Empresa</label>
                    <input type="text" name="as_empresa">
                </div>
                <div>
                    <label>NIT</label>
                    <input type="text" name="as_nit">
                </div>
                <div>
                    <label>Cargo</label>
                    <input type="text" name="as_cargo">
                </div>
                <div>
                    <label>Ciudad</label>
                    <input type="text" name="as_ciudad">
                </div>
                <div>
                    <label>País</label>
                    <select name="as_pais">
                        <?php
                        $countries = function_exists('eventosapp_get_countries') ? eventosapp_get_countries() : array('Colombia');
                        foreach ($countries as $c) {
                            $sel = ($c === 'Colombia') ? ' selected' : '';
                            echo '<option value="'.esc_attr($c).'"'.$sel.'>'.esc_html($c).'</option>';
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div style="margin-top:14px">
                <button type="submit"
                        class="button button-primary evauto-submit-btn"
                        style="padding:.7rem 1.1rem;border-radius:10px;font-weight:700">
                    Registrarme
                </button>
                <div class="evauto-feedback" aria-live="polite">
                    <span class="evauto-spinner" aria-hidden="true"></span>
                    <span>Procesando tu registro, por favor espera…</span>
                </div>
            </div>
        </form>

        <script>
        (function(){
          var root = document.getElementById('<?php echo esc_js($uid); ?>');
          if(!root) return;
          var form = root.querySelector('form.evauto-form');
          if(!form) return;

          var btn  = form.querySelector('.evauto-submit-btn');
          var fb   = form.querySelector('.evauto-feedback');
          var ajax = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
          var nonce = "<?php echo esc_js( wp_create_nonce('eventosapp_evauto_ajax') ); ?>";
          var baseUrl = "<?php echo esc_js( $base_url ); ?>";
          var eid = "<?php echo (int) $eid; ?>";

          function showError(message){
            var m = root.querySelector('.evauto-msg');
            if (!m){
                m = document.createElement('div');
                m.className = 'evauto-msg';
                m.style.cssText = 'padding:.8rem;border:1px solid #fecaca;background:#fee2e2;border-radius:10px;color:#991b1b;margin:10px 0;';
                root.insertBefore(m, form);
            }
            m.innerHTML = message || 'No fue posible procesar el registro.';
            m.scrollIntoView({behavior:'smooth', block:'start'});
          }

          form.addEventListener('submit', function(e){
            e.preventDefault();

            if (btn){
              btn.disabled = true;
              btn.classList.add('is-loading');
              btn.setAttribute('aria-busy','true');
              btn.dataset._txt = btn.textContent;
              btn.textContent = 'Registrando…';
            }
            if (fb) fb.style.display = 'flex';

            var fd = new FormData(form);
            fd.append('action', 'eventosapp_evauto_submit');
            fd.append('nonce',  nonce);
            fd.append('evauto_event_id', eid);

            fetch(ajax, { method:'POST', credentials:'same-origin', body: fd })
              .then(r => r.json())
              .then(function(resp){
                if (resp && resp.success){
                    // Insertar HTML de éxito y ocultar el formulario
                    var holder = document.createElement('div');
                    holder.innerHTML = resp.data && resp.data.html ? resp.data.html : '<div class="evauto-success">¡Registro completado!</div>';
                    root.insertBefore(holder, form);
                    form.style.display = 'none';

                    // Reflejar en URL (sin recargar)
                    try{
                      if (history && history.replaceState){
                        var url = baseUrl + (baseUrl.indexOf('?')>-1 ? '&' : '?') + 'evauto_ok=1' + (resp.data.tid ? '&tid='+encodeURIComponent(resp.data.tid) : '');
                        history.replaceState({}, document.title, url);
                      }
                    }catch(e){}
                    holder.scrollIntoView({behavior:'smooth', block:'start'});
                } else {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'No fue posible procesar el registro.';
                    showError(msg);
                }
              })
              .catch(function(){
                showError('Error de red. Verifica tu conexión e inténtalo de nuevo.');
              })
              .finally(function(){
                if (btn){
                  btn.disabled = false;
                  btn.classList.remove('is-loading');
                  btn.textContent = btn.dataset._txt || 'Registrarme';
                  btn.removeAttribute('aria-busy');
                }
                if (fb) fb.style.display = 'none';
              });
          }, { passive:false });
        })();
        </script>
    </div>
    <?php
    return ob_get_clean();
});
