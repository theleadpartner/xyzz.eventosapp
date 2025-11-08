<?php
/**
 * Frontend: Registro manual de asistentes (creación de tickets)
 * Shortcode: [eventosapp_front_register]
 * Requiere: evento activo elegido desde el dashboard (o event_id en el shortcode)
 */

if ( ! defined('ABSPATH') ) exit;

/** Permisos: organizador/staff/admin */
if ( ! function_exists('eventosapp_user_can_front_tools') ) {
    function eventosapp_user_can_front_tools() {
        if ( ! is_user_logged_in() ) return false;
        $u = wp_get_current_user();
        if ( user_can($u, 'manage_options') ) return true;
        $allowed = array('organizador','staff','logistico');
        return (bool) array_intersect($allowed, (array) $u->roles);
    }
}

/** Puede gestionar el evento (usa la feature "register" del dashboard) */
if ( ! function_exists('eventosapp_user_can_manage_event') ) {
    function eventosapp_user_can_manage_event( $evento_id ) {
        if ( ! is_user_logged_in() ) return false;
        $u = wp_get_current_user();

        // Admin siempre puede
        if ( user_can($u, 'manage_options') ) return true;

        // Si tu sistema de visibilidad le otorga la feature "register", puede gestionar
        if ( function_exists('eventosapp_role_can') && eventosapp_role_can('register', $u->ID) ) {
            return true;
        }

        // Fallback: autor del evento (compatibilidad con comportamiento anterior)
        $evento = get_post( $evento_id );
        if ( ! $evento || $evento->post_type !== 'eventosapp_event' ) return false;
        return (int) $evento->post_author === (int) $u->ID;
    }
}

/**
 * Detectar contexto Elementor/AJAX
 */
if ( ! function_exists('eventosapp_is_elementor_context') ) {
    function eventosapp_is_elementor_context() {
        if ( isset($_GET['elementor-preview']) || isset($_GET['elementor_library']) || isset($_GET['elementor']) ) return true;
        if ( function_exists('wp_doing_ajax') && wp_doing_ajax() ) return true;
        if ( did_action('elementor/loaded') && class_exists('\Elementor\Plugin') ) {
            try {
                $pl = \Elementor\Plugin::$instance;
                if ( method_exists($pl, 'editor') && $pl->editor && $pl->editor->is_edit_mode() ) return true;
                if ( property_exists($pl, 'preview') && $pl->preview && $pl->preview->is_preview_mode() ) return true;
            } catch (\Throwable $e) {}
        }
        return false;
    }
}

/**
 * ========= AJAX endpoint (sin recarga) =========
 * action: eventosapp_evreg_submit
 */
add_action('wp_ajax_eventosapp_evreg_submit', 'eventosapp_evreg_submit');
add_action('wp_ajax_nopriv_eventosapp_evreg_submit', 'eventosapp_evreg_submit');

function eventosapp_evreg_submit(){
    // JSON siempre
    header('Content-Type: application/json; charset=' . get_option('blog_charset'));

    // Nonce
    check_ajax_referer('eventosapp_evreg_ajax', 'nonce');

    // Permisos base
    if ( ! eventosapp_user_can_front_tools() ) {
        wp_send_json_error(['message'=>'No tienes permisos para usar esta herramienta.'], 403);
    }

    // Evento activo o pasado en POST
    $eid = isset($_POST['evreg_event_id']) ? absint($_POST['evreg_event_id']) : 0;
    if ( ! $eid && function_exists('eventosapp_get_active_event') ) {
        $eid = (int) eventosapp_get_active_event();
    }
    if ( ! $eid ) {
        wp_send_json_error(['message'=>'Debes seleccionar un evento activo.']);
    }
    if ( ! eventosapp_user_can_manage_event($eid) ) {
        wp_send_json_error(['message'=>'No tienes permisos sobre este evento.'], 403);
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

    // Localidades válidas
    $localidades = get_post_meta($eid, '_eventosapp_localidades', true);
    if (!is_array($localidades) || empty($localidades)) $localidades = ['General','VIP','Platino'];
    $localidades_allowed = array_fill_keys(array_map('strval', $localidades), true);

    // Idempotencia (token de un solo uso)
    $ev_token = isset($_POST['evreg_token']) ? sanitize_text_field( wp_unslash($_POST['evreg_token']) ) : '';
    $tval     = $ev_token ? get_transient('evreg_token_'.$ev_token) : false;
    if ( empty($ev_token) || false === $tval ) {
        wp_send_json_error(['message'=>'Esta solicitud ya fue procesada o el formulario expiró. Recarga e inténtalo de nuevo.', 'code'=>'token']);
    }
    delete_transient('evreg_token_'.$ev_token);

    // Campos
    $nombre    = sanitize_text_field( wp_unslash( $_POST['as_nombre']   ?? '' ) );
    $apellido  = sanitize_text_field( wp_unslash( $_POST['as_apellido'] ?? '' ) );
    $cc        = sanitize_text_field( wp_unslash( $_POST['as_cc']       ?? '' ) );
    $email     = sanitize_email(      wp_unslash( $_POST['as_email']    ?? '' ) );
    $tel       = sanitize_text_field( wp_unslash( $_POST['as_tel']      ?? '' ) );
    $empresa   = sanitize_text_field( wp_unslash( $_POST['as_empresa']  ?? '' ) );
    $nit       = sanitize_text_field( wp_unslash( $_POST['as_nit']      ?? '' ) );
    $cargo     = sanitize_text_field( wp_unslash( $_POST['as_cargo']    ?? '' ) );
    $ciudad    = sanitize_text_field( wp_unslash( $_POST['as_ciudad']   ?? '' ) );
    $pais      = sanitize_text_field( wp_unslash( $_POST['as_pais']     ?? 'Colombia' ) );
    $localidad = sanitize_text_field( wp_unslash( $_POST['as_localidad']?? '' ) );
    $preprinted_qr_id = sanitize_text_field( wp_unslash( $_POST['as_preprinted_qr_id'] ?? '' ) );

    if ( ! $nombre || ! $apellido || ! $email ) {
        wp_send_json_error(['message'=>'Completa al menos Nombre, Apellido y Email.']);
    }
    if ( $localidad !== '' && empty($localidades_allowed[$localidad]) ) {
        wp_send_json_error(['message'=>'La localidad seleccionada no existe en este evento.']);
    }

    // Extras requeridos
    $extras_in = [];
    if ( function_exists('eventosapp_get_event_extra_fields') ) {
        $extras_schema = eventosapp_get_event_extra_fields($eid) ?: [];
        $extras_in = isset($_POST['as_extra']) && is_array($_POST['as_extra']) ? $_POST['as_extra'] : [];
        foreach ($extras_schema as $f){
            if (!empty($f['required'])) {
                $k = $f['key'];
                $v = trim((string)($extras_in[$k] ?? ''));
                if ($v === '') {
                    wp_send_json_error(['message'=>'Falta el campo obligatorio: '.$f['label']]);
                }
            }
        }
    }

    // Preparar payload para hooks (compat con save_post existente)
    $_POST['eventosapp_ticket_nonce']       = wp_create_nonce('eventosapp_ticket_guardar');
    $_POST['eventosapp_ticket_evento_id']   = $eid;
    $_POST['eventosapp_ticket_user_id']     = get_current_user_id();
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
    $_POST['eventosapp_asistente_localidad']= $localidad;

    if ($use_preprinted_qr && $preprinted_qr_id !== '') {
        $_POST['eventosapp_ticket_preprintedID'] = preg_replace('/\D+/', '', (string) $preprinted_qr_id);
    }

    if (!empty($extras_in)) {
        $_POST['eventosapp_extra'] = array_map('sanitize_text_field', array_map('wp_unslash', $extras_in));
    }

    // Crear ticket
    $post_id = wp_insert_post([
        'post_type'    => 'eventosapp_ticket',
        'post_status'  => 'publish',
        'post_title'   => 'tmp',
        'post_author'  => get_current_user_id(),
    ], true);

    if ( is_wp_error($post_id) || ! $post_id ) {
        wp_send_json_error(['message'=>'Error al crear el ticket. Inténtalo de nuevo.']);
    }

    // ID público
    $ticket_pub = get_post_meta($post_id, 'eventosapp_ticketID', true);
    $tid        = $ticket_pub ?: '#'.$post_id;

    wp_send_json_success([
        'tid' => (string) $tid,
    ]);
}

/**
 * ========= Shortcode (con AJAX) =========
 * Uso: [eventosapp_front_register] o [eventosapp_front_register event_id="123"]
 */
add_shortcode('eventosapp_front_register', function($atts){
    if ( function_exists('eventosapp_require_feature') ) eventosapp_require_feature('register');

    // Debug
    $dbg = array();
    $dbg[] = 'Shortcode eventosapp_front_register start';

    // Instancias (por si Elementor evalúa más de una vez)
    if ( ! isset($GLOBALS['eventosapp_evreg_render_count']) ) $GLOBALS['eventosapp_evreg_render_count'] = 0;
    $GLOBALS['eventosapp_evreg_render_count']++;
    $instance = (int) $GLOBALS['eventosapp_evreg_render_count'];

    $in_elem = eventosapp_is_elementor_context();
    $dbg[] = 'instance='.$instance;
    $dbg[] = 'elementor_context='.($in_elem?'1':'0');
    $dbg[] = 'is_admin='.( is_admin() ? '1' : '0' );
    $dbg[] = 'doing_ajax='.( function_exists('wp_doing_ajax') && wp_doing_ajax() ? '1' : '0' );

    // Atributos
    $a   = shortcode_atts(['event_id'=>0], $atts);
    $eid = absint($a['event_id']);
    $dbg[] = 'eid_initial='.$eid;

    if ( ! $eid && function_exists('eventosapp_get_active_event') ) {
        $eid = (int) eventosapp_get_active_event();
        $dbg[] = 'eid_from_active='.$eid;
    }

    // Si no hay evento, obligar a elegirlo
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

    // Permisos
    if ( ! current_user_can('manage_options') && ! eventosapp_user_can_manage_event($eid) ) {
        return '<div style="padding:.8rem;border:1px solid #eee;background:#fff8f8;border-radius:8px;color:#a33;">
            No tienes permisos sobre este evento.
        </div>';
    }

    // QR preimpreso
    $use_preprinted_qr = false;
    $flag_meta = get_post_meta($eid, '_eventosapp_ticket_use_preprinted_qr', true);
    if ($flag_meta !== '' && $flag_meta !== null) {
        $use_preprinted_qr = (bool) intval($flag_meta);
    } else {
        $flag_opt = get_option('_eventosapp_ticket_use_preprinted_qr', 0);
        $use_preprinted_qr = (bool) intval($flag_opt);
    }
    $use_preprinted_qr = (bool) apply_filters('eventosapp_use_preprinted_qr', $use_preprinted_qr, $eid);
    $dbg[] = 'use_preprinted_qr='.($use_preprinted_qr?'1':'0');

    // Localidades
    $localidades = get_post_meta($eid, '_eventosapp_localidades', true);
    if (!is_array($localidades) || empty($localidades)) $localidades = ['General','VIP','Platino'];

    // URLs
    $url_search  = function_exists('eventosapp_get_search_url') ? eventosapp_get_search_url() : '';
    $current_url = ( function_exists('get_permalink') && is_singular() ) ? get_permalink() : home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ?? '' ) );
    $base_url    = remove_query_arg( array('evreg_ok','tid'), $current_url );
    $dbg[] = 'current_url='.$current_url;

    // Éxito por GET/flash (solo para visitas directas / refresh)
    $flash_key   = 'eventosapp_evreg_flash_' . get_current_user_id() . '_' . md5( $current_url . '|' . (int)$eid );
    $success     = false;
    $success_id  = '';

    if ( isset($_GET['evreg_ok']) && (int)$_GET['evreg_ok'] === 1 ) {
        $success = true;
        $success_id = sanitize_text_field( wp_unslash($_GET['tid'] ?? '') );
    } else {
        $flash_data = get_transient( $flash_key );
        if ( is_array($flash_data) && !empty($flash_data['tid']) ) {
            delete_transient( $flash_key );
            $success    = true;
            $success_id = (string) $flash_data['tid'];
        }
    }
    $dbg[] = 'success_pre='.($success?'1':'0').'; success_id='.$success_id;

    // UI
    $uid = function_exists('wp_unique_id') ? wp_unique_id('evreg-') : ('evreg-'.uniqid());
    ob_start();

    if ( function_exists('eventosapp_active_event_bar') ) eventosapp_active_event_bar();
    ?>
    <div id="<?php echo esc_attr($uid); ?>" class="evreg-wrap<?php echo $success ? ' evreg-success' : ''; ?>" style="max-width:840px;margin:0 auto;">
        <?php if ( $success ): ?>
            <div class="evreg-card-success">
                <div class="evreg-success-title">¡Ticket creado correctamente!</div>
                <?php if ( $success_id ): ?>
                    <div class="evreg-success-id">ID del Ticket: <b><?php echo esc_html($success_id); ?></b></div>
                <?php endif; ?>
                <div class="evreg-actions">
                    <a href="<?php echo esc_url( $base_url ); ?>" class="evbtn evbtn-green">Crear nuevo Ticket</a>
                    <?php if ( $url_search ): ?>
                        <a href="<?php echo esc_url( $url_search ); ?>" class="evbtn evbtn-blue">Ir a Check-In</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" class="evreg-form" autocomplete="off" <?php echo $success ? 'style="display:none;"' : ''; ?>>
            <?php
            // Token de idempotencia (5 min) solo UI
            $evreg_token = wp_generate_uuid4();
            set_transient('evreg_token_'.$evreg_token, 1, 5 * MINUTE_IN_SECONDS);
            ?>

            <?php wp_nonce_field('eventosapp_front_register'); ?>
            <input type="hidden" name="evreg_action" value="create_ticket" />
            <input type="hidden" name="evreg_event_id" value="<?php echo esc_attr($eid); ?>" />
            <input type="hidden" name="evreg_token" value="<?php echo esc_attr($evreg_token); ?>" />

            <h2 class="evreg-title">Registro manual de asistente</h2>
            <p class="evreg-sub">Crea un ticket para el evento activo. Los campos marcados con * son obligatorios.</p>

            <div class="evreg-grid">
                <div>
                    <label class="evreg-label">Nombre *</label>
                    <input class="evreg-input" type="text" name="as_nombre" required>
                </div>
                <div>
                    <label class="evreg-label">Apellido *</label>
                    <input class="evreg-input" type="text" name="as_apellido" required>
                </div>
                <div>
                    <label class="evreg-label">CC</label>
                    <input class="evreg-input" type="text" name="as_cc">
                </div>
                <div>
                    <label class="evreg-label">Email *</label>
                    <input class="evreg-input" type="email" name="as_email" required>
                </div>
                <div>
                    <label class="evreg-label">Teléfono</label>
                    <input class="evreg-input" type="text" name="as_tel">
                </div>
                <div>
                    <label class="evreg-label">Empresa</label>
                    <input class="evreg-input" type="text" name="as_empresa">
                </div>
                <div>
                    <label class="evreg-label">NIT</label>
                    <input class="evreg-input" type="text" name="as_nit">
                </div>
                <div>
                    <label class="evreg-label">Cargo</label>
                    <input class="evreg-input" type="text" name="as_cargo">
                </div>
                <div>
                    <label class="evreg-label">Ciudad</label>
                    <input class="evreg-input" type="text" name="as_ciudad">
                </div>
                <div>
                    <label class="evreg-label">País</label>
                    <select class="evreg-input" name="as_pais">
                        <?php
                        $countries = function_exists('eventosapp_get_countries') ? eventosapp_get_countries() : array('Colombia');
                        foreach ($countries as $c) {
                            $sel = ($c === 'Colombia') ? ' selected' : '';
                            echo '<option value="'.esc_attr($c).'"'.$sel.'>'.esc_html($c).'</option>';
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label class="evreg-label">Localidad</label>
                    <select class="evreg-input" name="as_localidad">
                        <option value="">Seleccione…</option>
                        <?php foreach($localidades as $loc): ?>
                            <option value="<?php echo esc_attr($loc); ?>"><?php echo esc_html($loc); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($use_preprinted_qr): ?>
                <div>
                    <label class="evreg-label">ID de QR preimpreso</label>
                    <input class="evreg-input" type="text" name="as_preprinted_qr_id" placeholder="Ej: 00012345">
                    <small class="evreg-help">Este campo aparece porque el evento usa QR preimpreso.</small>
                </div>
                <?php endif; ?>
            </div>

            <?php
            $extras_schema = function_exists('eventosapp_get_event_extra_fields') ? eventosapp_get_event_extra_fields($eid) : [];
            if ($extras_schema) {
                echo '<div class="evreg-extras-title"><b>Campos adicionales</b></div>';
                echo '<div class="evreg-grid evreg-grid--extras">';
                foreach ($extras_schema as $f){
                    $name = 'as_extra['.$f['key'].']';
                    echo '<div><label class="evreg-label">'.esc_html($f['label']).(!empty($f['required'])?' *':'').'</label>';
                    if (!empty($f['type']) && $f['type']==='number') {
                        echo '<input class="evreg-input" type="number" name="'.$name.'">';
                    } elseif (!empty($f['type']) && $f['type']==='select' && !empty($f['options']) && is_array($f['options'])) {
                        echo '<select class="evreg-input" name="'.$name.'"><option value="">Seleccione…</option>';
                        foreach ($f['options'] as $op){
                            echo '<option value="'.esc_attr($op).'">'.esc_html($op).'</option>';
                        }
                        echo '</select>';
                    } else {
                        echo '<input class="evreg-input" type="text" name="'.$name.'">';
                    }
                    echo '</div>';
                }
                echo '</div>';
            }
            ?>

            <div id="evreg-processing" class="evreg-processing" style="display:none;">
                <span class="evspinner" aria-hidden="true"></span>
                <span><b>Procesando…</b> No cierres esta página.</span>
            </div>

            <div class="evreg-submit">
                <button type="submit" class="evbtn evbtn-primary evbtn-submit">
                    Crear Ticket
                </button>
            </div>
        </form>
    </div>

    <style>
        /* ---------- Elementor compat: apaga pseudo-elementos decorativos en hosts cercanos ---------- */
        .elementor .evreg-safe::before,
        .elementor .evreg-safe::after{ content:none !important; display:none !important; }

        /* Aísla nuestro bloque */
        .evreg-wrap{ position:relative; isolation:isolate; z-index:0; }

        /* Reset local de pseudo-elementos dentro del bloque */
        .evreg-wrap *::before, .evreg-wrap *::after { content: normal !important; }
        .evreg-wrap blockquote, .evreg-wrap q { quotes: none; }
        .evreg-wrap blockquote::before, .evreg-wrap blockquote::after,
        .evreg-wrap q::before, .evreg-wrap q::after { content: none !important; }

        /* Tarjeta de éxito */
        .evreg-card-success{ padding:14px;border:1px solid #d1fae5;background:#ecfdf5;border-radius:12px;margin:10px 0; }
        .evreg-success-title{font-weight:700;color:#065f46}
        .evreg-success-id{margin-top:6px;color:#065f46}
        .evreg-actions{margin-top:10px;display:flex;gap:10px;flex-wrap:wrap}

        /* Formulario */
        .evreg-form{ background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;box-shadow:0 1px 5px rgba(120,140,160,.06) }
        .evreg-title{margin:0 0 12px;font-size:22px}
        .evreg-sub{margin:6px 0 16px;color:#555}
        .evreg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
        .evreg-grid--extras{margin-top:6px}
        .evreg-extras-title{margin-top:10px}

        .evreg-label{display:block;font-weight:600;color:#333;margin-bottom:6px}
        .evreg-input{
            width:100%;padding:.6rem .7rem;border:1px solid #dfe3e7;border-radius:10px;background:#f7f7f9;
            transition: box-shadow .15s ease, border-color .15s ease, background .15s ease;
        }
        .evreg-input:focus{outline:none;border-color:#93c5fd;box-shadow:0 0 0 3px rgba(147,197,253,.35);background:#fff}
        .evreg-help{color:#666;display:block;margin-top:4px}

        .evreg-processing{
            margin-top:10px;padding:.7rem;border:1px dashed #93c5fd;background:#eff6ff;border-radius:10px;color:#1e40af;
            align-items:center;gap:8px;display:flex
        }
        .evspinner{width:16px;height:16px;border:2px solid #93c5fd;border-top-color:transparent;border-radius:50%;display:inline-block;animation:evspin .9s linear infinite}
        @keyframes evspin { to { transform: rotate(360deg); } }

        .evreg-submit{margin-top:14px}

        /* Botones */
        .evbtn{
            display:inline-flex;align-items:center;justify-content:center;gap:8px;
            padding:.7rem 1.1rem;border-radius:10px;font-weight:700;text-decoration:none;border:1px solid transparent;cursor:pointer;
            transition: transform .06s ease, opacity .2s ease, box-shadow .2s ease;
        }
        .evbtn:hover{ box-shadow: 0 3px 10px rgba(0,0,0,.08); transform: translateY(-1px); }
        .evbtn:active{ transform: translateY(0); box-shadow: inset 0 2px 6px rgba(0,0,0,.12); }
        .evbtn[disabled], .evbtn:disabled { opacity:.65; pointer-events:none; cursor:not-allowed; }
        .evbtn-green{ background:#10b981; border-color:#10b981; color:#fff; }
        .evbtn-blue { background:#2563eb; border-color:#2563eb; color:#fff; }
        .evbtn-primary{ background:#2563eb; border-color:#2563eb; color:#fff; }

        .evbtn-submit.is-loading { position:relative; cursor:progress; }
        .evbtn-submit.is-loading .evspinner { margin-right:8px; }

        .evreg-alert{ padding:.8rem;border-radius:10px;margin:10px 0;font-weight:500 }
        .evreg-alert-error{border:1px solid #fca5a5;background:#fee2e2;color:#991b1b}
        .evreg-alert-warn{border:1px solid #fde68a;background:#fef3c7;color:#92400e}

        /* Oculta el formulario cuando hay éxito */
        .evreg-success .evreg-form{ display:none; }
    </style>

    <script>
    (function(){
        var root  = document.getElementById('<?php echo esc_js($uid); ?>');
        if (!root) return;
        var form  = root.querySelector('.evreg-form');
        if (!form) return;

        var btn   = form.querySelector('.evbtn-submit');
        var msg   = document.getElementById('evreg-processing');
        var ajax  = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
        var nonce = "<?php echo esc_js( wp_create_nonce('eventosapp_evreg_ajax') ); ?>";
        var baseUrl   = "<?php echo esc_js( $base_url ); ?>";
        var urlSearch = "<?php echo esc_js( $url_search ); ?>";

        // Marca contenedores Elementor como "seguros"
        try{
          var widget = root.closest('.elementor-widget'), container = root.closest('.evreg-safe') || root.closest('.elementor-widget-container'), column = widget ? widget.closest('.elementor-column') : null, section = widget ? widget.closest('.elementor-section') : null;
          [container, widget, column, section].forEach(function(el){ if(el && !el.classList.contains('evreg-safe')) el.classList.add('evreg-safe'); });
          console.groupCollapsed('%c[eventosapp] front_register mount','color:#2563eb;font-weight:bold');
          console.log('root', '#<?php echo esc_js($uid); ?>');
          console.log('ajax', ajax);
          console.groupEnd();
        }catch(e){}

        function showError(message){
            var m = root.querySelector('.evreg-msg');
            if (!m){
                m = document.createElement('div');
                m.className = 'evreg-alert evreg-alert-error evreg-msg';
                form.parentNode.insertBefore(m, form);
            }
            m.innerHTML = message || 'No fue posible procesar la solicitud.';
            m.scrollIntoView({behavior:'smooth', block:'start'});
        }

        form.addEventListener('submit', function(e){
            e.preventDefault();

            if (form.dataset.submitted === '1') return;
            form.dataset.submitted = '1';

            if (btn) {
                btn.disabled = true;
                btn.classList.add('is-loading');
                btn.innerHTML = '<span class="evspinner" aria-hidden="true"></span> Procesando…';
                btn.setAttribute('aria-busy','true');
            }
            if (msg) { msg.style.display = 'flex'; }

            var fd = new FormData(form);
            fd.append('action', 'eventosapp_evreg_submit');
            fd.append('nonce', nonce);

            fetch(ajax, { method:'POST', credentials:'same-origin', body: fd })
              .then(function(r){ return r.json(); })
              .then(function(resp){
                  if (resp && resp.success) {
                      var tid = resp.data && resp.data.tid ? resp.data.tid : '';
                      // Pintar tarjeta de éxito sobre la marcha
                      var card = root.querySelector('.evreg-card-success');
                      if (!card){
                          card = document.createElement('div');
                          card.className = 'evreg-card-success';
                          form.parentNode.insertBefore(card, form);
                      }
                      card.innerHTML =
                          '<div class="evreg-success-title">¡Ticket creado correctamente!</div>' +
                          (tid ? '<div class="evreg-success-id">ID del Ticket: <b>'+tid+'</b></div>' : '') +
                          '<div class="evreg-actions">' +
                            '<a href="'+baseUrl+'" class="evbtn evbtn-green">Crear nuevo Ticket</a>' +
                            (urlSearch ? '<a href="'+urlSearch+'" class="evbtn evbtn-blue">Ir a Check-In</a>' : '') +
                          '</div>';

                      root.classList.add('evreg-success');

                      // Limpia/ajusta URL (sin recargar)
                      try{
                        if (window.history && window.history.replaceState) {
                          var url = baseUrl + (baseUrl.indexOf('?')>-1 ? '&' : '?') + 'evreg_ok=1&tid=' + encodeURIComponent(tid);
                          history.replaceState({}, document.title, url);
                        }
                      }catch(e){}

                      card.scrollIntoView({behavior:'smooth', block:'start'});
                  } else {
                      var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'No fue posible procesar la solicitud.';
                      showError(msg);
                  }
              })
              .catch(function(){
                  showError('Error de red. Verifica tu conexión e inténtalo de nuevo.');
              })
              .finally(function(){
                  if (btn) {
                      btn.disabled = false;
                      btn.classList.remove('is-loading');
                      btn.innerHTML = 'Crear Ticket';
                      btn.removeAttribute('aria-busy');
                  }
                  if (msg) { msg.style.display = 'none'; }
                  form.dataset.submitted = '0';
              });
        }, { passive: false });
    })();
    </script>
    <?php
    // Debug a consola
    add_action('wp_footer', function() use($dbg){
        ?>
        <script>(function(){try{
            var msgs=<?php echo wp_json_encode($dbg); ?>;
            console.groupCollapsed('%c[eventosapp] front_register','color:#2563eb;font-weight:bold');
            msgs.forEach(function(m){console.log(m);});
            console.groupEnd();
        }catch(e){}})();</script>
        <?php
    });

    return ob_get_clean();
});
