<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Shortcode público: [eventosapp_qr_networking_auth event="123"]
 * - Paso 1: Identificar asistente (cc + apellido) contra el evento
 * - Paso 2: Escanear QR y registrar interacción lector -> leído
 *
 * Requiere helpers de: includes/functions/eventosapp-networking.php
 */

add_shortcode('eventosapp_qr_networking_auth', function($atts){
    $atts = shortcode_atts([
        'event' => 0,
    ], $atts, 'eventosapp_qr_networking_auth');

    $event_id = absint($atts['event']);
    if ( ! $event_id ) {
        return '<div style="color:#b33">Falta el ID de evento. Usa <code>[eventosapp_qr_networking_auth event="123"]</code>.</div>';
    }

    // Nonces
    $nonce_ident = wp_create_nonce('eventosapp_net2_identify');
    $nonce_log   = wp_create_nonce('eventosapp_net2_log');

    ob_start(); ?>
    <style>
      .evapp-net-shell { max-width:560px; margin:0 auto; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
      .evapp-net-card  { background:#0b1020; color:#eaf1ff; border-radius:16px; padding:18px; box-shadow:0 8px 24px rgba(0,0,0,.15); }
      .evapp-net-title { display:flex; align-items:center; gap:.6rem; margin:0 0 10px; font-weight:800; font-size:1.05rem; letter-spacing:.2px; }
      .evapp-net-field { margin:10px 0; }
      .evapp-net-field label { display:block; font-size:.95rem; margin-bottom:6px; color:#c9d6ff; font-weight:600; }
      .evapp-net-input { width:100%; padding:.7rem .8rem; border-radius:10px; border:1px solid rgba(255,255,255,.12); background:#0a0f1d; color:#eaf1ff; }
      .evapp-net-btn   { display:flex; align-items:center; justify-content:center; gap:.5rem; border:0; border-radius:12px; padding:.9rem 1.1rem; font-weight:800; cursor:pointer; width:100%; background:#2563eb; color:#fff; transition:filter .15s, background .15s; }
      .evapp-net-btn:hover{ filter:brightness(.98); }
      .evapp-net-btn.is-live{ background:#e04f5f; }
      .evapp-net-help  { color:#a9b6d3; font-size:.9rem; opacity:.85; margin-top:6px; }
      .evapp-net-bad   { color:#ff6b6b; font-weight:700; }
      .evapp-net-ok    { color:#7CFF8D; font-weight:800; }

      .evapp-qr-video-wrap { position:relative; margin-top:12px; border-radius:14px; overflow:hidden; background:#0a0f1d; aspect-ratio:3/4; display:none; }
      .evapp-qr-video { width:100%; height:100%; object-fit:cover; display:none; }
      .evapp-qr-frame { position:absolute; inset:0; pointer-events:none; display:none; }
      .evapp-qr-frame .mask { position:absolute; inset:0; background: radial-gradient(ellipse 60% 40% at 50% 50%, rgba(255,255,255,0) 62%, rgba(10,15,29,.55) 64%); }
      .evapp-qr-corner { position:absolute; width:44px; height:44px; border:4px solid #4f7cff; border-radius:10px; }
      .evapp-qr-corner.tl{top:16px;left:16px;border-right:0;border-bottom:0}
      .evapp-qr-corner.tr{top:16px;right:16px;border-left:0;border-bottom:0}
      .evapp-qr-corner.bl{bottom:16px;left:16px;border-right:0;border-top:0}
      .evapp-qr-corner.br{bottom:16px;right:16px;border-left:0;border-top:0}

      .evapp-qr-result { margin-top:14px; background:#0a0f1d; border:1px solid rgba(255,255,255,.06); border-radius:12px; padding:14px; }
      .evapp-qr-grid { display:grid; grid-template-columns: 1fr; gap:.2rem .8rem; margin-top:.4rem; }
      .evapp-qr-grid div b { color:#a7b8ff; font-weight:600; }
      @media(min-width:480px){ .evapp-qr-grid{ grid-template-columns:auto 1fr } .evapp-qr-grid div{ display:contents } .evapp-qr-grid b{ text-align:right } }

      .evapp-qr-video-wrap.is-immersive{ aspect-ratio:auto; height: calc(100vh - var(--evapp-offset, 56px)); width:100%; display:block; }
      .evapp-qr-btn-secondary{ margin-top:12px; width:100%; background:#2563eb!important; color:#fff; border:0; border-radius:10px; padding:.7rem 1rem; font-weight:800; cursor:pointer; transition:filter .15s; }
      .evapp-qr-btn-secondary:hover{ filter:brightness(1.05); }
    </style>

    <div class="evapp-net-shell"
         data-event="<?php echo esc_attr($event_id); ?>"
         data-ident-nonce="<?php echo esc_attr($nonce_ident); ?>"
         data-log-nonce="<?php echo esc_attr($nonce_log); ?>">

      <div class="evapp-net-card">
        <div class="evapp-net-title">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6zm14 0h2v6h-6v-2h4v-4z" stroke="#a7b8ff"/></svg>
          Networking – Doble autenticación
        </div>

        <!-- Paso 1: Identidad -->
        <div id="evappIdentStep">
          <div class="evapp-net-field">
            <label>Cédula</label>
            <input type="text" id="evappIdentCC" class="evapp-net-input" placeholder="Ej: 1020304050">
          </div>
          <div class="evapp-net-field">
            <label>Apellido</label>
            <input type="text" id="evappIdentLast" class="evapp-net-input" placeholder="Ej: Pérez">
          </div>
          <button type="button" id="evappIdentBtn" class="evapp-net-btn">Confirmar identidad</button>
          <div id="evappIdentMsg" class="evapp-net-help">Ingresa los datos tal como fueron registrados.</div>
        </div>

        <!-- Paso 2: Scanner -->
        <div id="evappScanStep" style="display:none">
          <div class="evapp-net-help" id="evappScanWelcome"></div>

          <button class="evapp-net-btn" id="evappStartScanNet">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/><rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/></svg>
            Activar cámara y escanear
          </button>

          <div class="evapp-qr-video-wrap">
            <video id="evappVideoNet" class="evapp-qr-video" playsinline></video>
            <div class="evapp-qr-frame" id="evappFrameNet">
              <div class="mask"></div>
              <div class="evapp-qr-corner tl"></div>
              <div class="evapp-qr-corner tr"></div>
              <div class="evapp-qr-corner bl"></div>
              <div class="evapp-qr-corner br"></div>
            </div>
            <canvas id="evappCanvasNet" style="display:none;"></canvas>
          </div>

          <div class="evapp-qr-result" id="evappResultNet">
            <div class="evapp-net-help">Tip: coloca el QR dentro del marco.</div>
          </div>
        </div>
      </div>
    </div>

    <script>
    (function(){
      const shell = document.querySelector('.evapp-net-shell');
      const ajaxURL    = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
      const eventID    = parseInt(shell?.dataset.event || '0', 10) || 0;
      const identNonce = shell?.dataset.identNonce || '';
      const logNonce   = shell?.dataset.logNonce   || '';

      // Paso 1
      const cc   = document.getElementById('evappIdentCC');
      const last = document.getElementById('evappIdentLast');
      const btnIdent = document.getElementById('evappIdentBtn');
      const msgIdent = document.getElementById('evappIdentMsg');

      // Paso 2
      const scanStep  = document.getElementById('evappScanStep');
      const scanWelcome = document.getElementById('evappScanWelcome');
      const btnScan   = document.getElementById('evappStartScanNet');
      const video = document.getElementById('evappVideoNet');
      const frame = document.getElementById('evappFrameNet');
      const cvs   = document.getElementById('evappCanvasNet'); const ctx = cvs.getContext('2d');
      const out   = document.getElementById('evappResultNet');
      const vwrap = video.closest('.evapp-qr-video-wrap') || video.parentElement;

      let readerTicketId = 0;

      // Scanner state
      let stream=null, running=false, lastScan="", lastAt=0;
      let barcodeDetector = ('BarcodeDetector' in window) ? new window.BarcodeDetector({formats:['qr_code']}) : null;

      function setIdentMsg(html, good=false){
        msgIdent.innerHTML = html;
        msgIdent.className = good ? 'evapp-net-ok' : 'evapp-net-bad';
      }
      function setScanOutput(html){ out.innerHTML = html; }
      function row(label,value){ return `<div><b>${label}:</b></div><div>${value || '-'}</div>`; }
      function normalizeRaw(raw){
        let s=String(raw||'').trim();
        if (s.includes('/')) s = s.split('/').pop();
        s = s.replace(/\.(png|jpg|jpeg|pdf)$/i,'').replace(/-tn$/i,'').replace(/^#/,'');
        return s;
      }
      function beep(){ try{const a=new Audio(); a.src='data:audio/mp3;base64,//uQxAAAAAAAAAAAAAAAAAAAAAAAWGlinZwAAAA8AAAACAAACcQAA'; a.play().catch(()=>{});}catch(e){} if(navigator.vibrate) navigator.vibrate(60); }
      function getOffset(){ const ab=document.getElementById('wpadminbar'); return (ab?ab.offsetHeight:0) + 10; }
      function smoothScrollTo(el){ if(!el) return; const off=getOffset(); try{el.style.setProperty('--evapp-offset',off+'px')}catch(e){} const y=el.getBoundingClientRect().top + window.pageYOffset - off; window.scrollTo({top:y,behavior:'smooth'}); }

      // === Paso 1: confirmar identidad
      btnIdent.addEventListener('click', ()=>{
        const ccVal   = (cc.value || '').trim();
        const lastVal = (last.value || '').trim();
        if (!ccVal || !lastVal) { setIdentMsg('Completa cédula y apellido.'); return; }

        const fd = new FormData();
        fd.append('action',   'eventosapp_net2_identify');
        fd.append('security', identNonce);
        fd.append('event_id', String(eventID));
        fd.append('cc', ccVal);
        fd.append('last', lastVal);

        msgIdent.className = 'evapp-net-help';
        msgIdent.innerHTML = 'Validando…';

        fetch(ajaxURL, { method:'POST', body:fd, credentials:'same-origin' })
          .then(r=>r.json())
          .then(resp=>{
            if (!resp || !resp.success){
              const txt = (resp && resp.data && resp.data.error) ? resp.data.error : 'No pudimos validar tus datos.';
              setIdentMsg(txt);
              return;
            }
            const d = resp.data || {};
            readerTicketId = parseInt(d.ticket_id || 0, 10) || 0;
            if (!readerTicketId){ setIdentMsg('No se reconoció tu identidad.'); return; }

            // OK -> pasar a escaneo
            msgIdent.className = 'evapp-net-ok';
            msgIdent.innerHTML = 'Identidad confirmada.';
            document.getElementById('evappIdentStep').style.display='none';
            scanStep.style.display='block';
            scanWelcome.textContent = 'Hola, ' + (d.full_name || 'asistente') + '. Activa la cámara para escanear.';
          })
          .catch(()=> setIdentMsg('Error de red.'));
      });

      // === Scanner ===
      function setLiveUI(on){
        if (on) {
          btnScan.classList.add('is-live');
          btnScan.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6h12v12H6z" stroke="white"/></svg> Detener cámara';
        } else {
          btnScan.classList.remove('is-live');
          btnScan.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/><rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/></svg> Activar cámara y escanear';
        }
      }
      function stop(){
        running=false;
        if (stream) stream.getTracks().forEach(t=>t.stop());
        stream=null;
        video.style.display='none';
        frame.style.display='none';
        vwrap?.classList.remove('is-immersive');
        setLiveUI(false);
      }
      async function ensureJsQR(){
        if ('BarcodeDetector' in window) return false;
        if (!window.jsQR) {
          await new Promise((resolve)=>{
            const s=document.createElement('script');
            s.src='https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';
            s.onload=resolve; document.head.appendChild(s);
          });
        }
        return true;
      }
      async function start(){
        try{
          stream = await navigator.mediaDevices.getUserMedia({ video:{ facingMode:{ideal:'environment'} }, audio:false });
        }catch(e){
          setScanOutput('<div class="evapp-net-bad">No se pudo acceder a la cámara.</div>');
          smoothScrollTo(out);
          return;
        }
        video.srcObject=stream; await video.play();
        video.style.display='block'; frame.style.display='block';
        vwrap?.classList.add('is-immersive'); smoothScrollTo(vwrap);
        cvs.width  = video.videoWidth  || 640;
        cvs.height = video.videoHeight || 480;
        running=true; setLiveUI(true);
        tick();
      }
      async function tick(){
        if (!running) return;
        ctx.drawImage(video,0,0,cvs.width,cvs.height);
        if (barcodeDetector){
          try{
            const bmp = await createImageBitmap(cvs);
            const codes = await barcodeDetector.detect(bmp);
            if (codes && codes.length){ onScan(normalizeRaw(codes[0].rawValue||'')); return; }
          }catch(e){}
        } else if (window.jsQR){
          const img = ctx.getImageData(0,0,cvs.width,cvs.height);
          const code= window.jsQR(img.data,img.width,img.height);
          if (code && code.data){ onScan(normalizeRaw(code.data)); return; }
        }
        requestAnimationFrame(tick);
      }
      function injectScanAgainButton(){
        if (document.getElementById('evappScanAgainNet')) return;
        const againBtn = document.createElement('button');
        againBtn.id='evappScanAgainNet'; againBtn.type='button';
        againBtn.className='evapp-qr-btn-secondary';
        againBtn.textContent='Escanear otro QR';
        out.appendChild(againBtn);
        againBtn.addEventListener('click', async ()=>{
          await ensureJsQR();
          await start();
          setScanOutput('<div class="evapp-net-help">Tip: coloca el QR dentro del marco.</div>');
        });
      }
      function onScan(data){
        const now = Date.now();
        if (data === lastScan && (now - lastAt) < 2500){ requestAnimationFrame(tick); return; }
        lastScan=data; lastAt=now; beep(); stop();

        setScanOutput('<div class="evapp-net-help">Procesando: '+ data +'…</div>');
        smoothScrollTo(out);

        const fd = new FormData();
        fd.append('action',   'eventosapp_net2_log');
        fd.append('security', logNonce);
        fd.append('event_id', String(eventID));
        fd.append('reader_ticket_id', String(readerTicketId));
        fd.append('scanned', data);

        fetch(ajaxURL, { method:'POST', body:fd, credentials:'same-origin' })
          .then(r=>r.json())
          .then(resp=>{
            if (!resp || !resp.success){
              const msg = (resp && resp.data && resp.data.error) ? resp.data.error : 'No se pudo registrar la interacción.';
              setScanOutput('<div class="evapp-net-bad">'+msg+'</div>');
              injectScanAgainButton(); smoothScrollTo(out);
              return;
            }
            const d = resp.data || {};
            let html = '<div class="evapp-net-ok">✔ Conexión registrada</div>';
            html += '<div class="evapp-qr-grid">';
            html += row('Nombre', d.full_name);
            html += row('Empresa', d.company);
            html += row('Cargo', d.designation);
            html += row('Localidad', d.localidad);
            html += '</div>';
            setScanOutput(html);
            injectScanAgainButton(); smoothScrollTo(out);
          })
          .catch(()=>{
            setScanOutput('<div class="evapp-net-bad">Error de red.</div>');
            injectScanAgainButton(); smoothScrollTo(out);
          });
      }

      // Toggle cámara
      btnScan.addEventListener('click', async ()=>{
        if (!readerTicketId){
          setScanOutput('<div class="evapp-net-bad">Primero confirma tu identidad.</div>');
          return;
        }
        if (stream && stream.active){
          stop();
          setScanOutput('<div class="evapp-net-help">Cámara detenida. Haz clic para volver a escanear.</div>');
          smoothScrollTo(out);
          return;
        }
        await ensureJsQR();
        await start();
        setScanOutput('<div class="evapp-net-help">Tip: coloca el QR dentro del marco.</div>');
      });
    })();
    </script>
    <?php
    return ob_get_clean();
});

/** ============================================================================
 * AJAX: Identificar por CC+Apellido (público)
 * ========================================================================== */
add_action('wp_ajax_nopriv_eventosapp_net2_identify', 'eventosapp_net2_identify_cb');
add_action('wp_ajax_eventosapp_net2_identify',       'eventosapp_net2_identify_cb');

function eventosapp_net2_identify_cb(){
    check_ajax_referer('eventosapp_net2_identify','security');

    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    $cc       = isset($_POST['cc'])       ? sanitize_text_field( wp_unslash($_POST['cc']) )   : '';
    $last     = isset($_POST['last'])     ? sanitize_text_field( wp_unslash($_POST['last']) ) : '';

    if ( ! $event_id || ! $cc || ! $last ) wp_send_json_error(['error'=>'Datos incompletos.']);

    if ( ! function_exists('eventosapp_net2_get_ticket_by_cc_last_event') ) {
        wp_send_json_error(['error'=>'Módulo de networking no disponible.']);
    }

    $ticket_id = eventosapp_net2_get_ticket_by_cc_last_event($event_id, $cc, $last);
    if ( ! $ticket_id ) {
        wp_send_json_error(['error'=>'No encontramos un asistente con esos datos para este evento.']);
    }

    $first = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
    $apell = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);

    wp_send_json_success([
        'ticket_id' => (int)$ticket_id,
        'full_name' => trim($first . ' ' . $apell),
    ]);
}

/** ============================================================================
 * AJAX: Registrar interacción lector -> leído (público)
 * ========================================================================== */
add_action('wp_ajax_nopriv_eventosapp_net2_log', 'eventosapp_net2_log_cb');
add_action('wp_ajax_eventosapp_net2_log',       'eventosapp_net2_log_cb');

function eventosapp_net2_log_cb(){
    check_ajax_referer('eventosapp_net2_log','security');

    $event_id        = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    $reader_ticket_id= isset($_POST['reader_ticket_id']) ? absint($_POST['reader_ticket_id']) : 0;
    $scanned         = isset($_POST['scanned']) ? sanitize_text_field( wp_unslash($_POST['scanned']) ) : '';

    if ( ! $event_id || ! $reader_ticket_id || ! $scanned ) {
        wp_send_json_error(['error'=>'Datos incompletos.']);
    }

    if ( ! function_exists('eventosapp_net2_record_interaction') ) {
        wp_send_json_error(['error'=>'Módulo de networking no disponible.']);
    }

    // Seguridad extra: validar que el reader_ticket_id pertenece al evento
    $ev_reader = (int) get_post_meta($reader_ticket_id, '_eventosapp_ticket_evento_id', true);
    if ($ev_reader !== (int) $event_id) {
        wp_send_json_error(['error'=>'El ticket lector no pertenece a este evento.']);
    }

    // Resolver el ticket leído y registrar
    $result = eventosapp_net2_record_interaction($event_id, $reader_ticket_id, $scanned, [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua' => substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250 ),
    ]);

    if ( is_wp_error($result) ) {
        wp_send_json_error(['error' => $result->get_error_message()]);
    }

    wp_send_json_success($result);
}

/* ======================================================================
 * RANKING NETWORKING (Top lectores / Top leídos del día)
 * =====================================================================*/

/**
 * Permite sobre-escribir el nombre de la tabla de logs desde un filtro externo
 * sin tocar el core.
 */
function eventosapp_net2_logs_table_name(){
    global $wpdb;
    $default = $wpdb->prefix . 'eventosapp_net2_logs';
    /**
     * Filtro: eventosapp_net2_logs_table
     * Permite retornar otro nombre de tabla si tu esquema difiere.
     */
    return apply_filters('eventosapp_net2_logs_table', $default);
}

/**
 * Resuelve nombre completo del asistente (ticket -> meta).
 */
function eventosapp_ticket_full_name($ticket_id){
    $first = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
    $last  = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
    $name  = trim( sprintf('%s %s', (string)$first, (string)$last) );
    return $name ?: ('Ticket #'.(int)$ticket_id);
}

/**
 * Top lectores (quienes MÁS han leído contactos) del día actual (timezone WP).
 * Devuelve array: [['ticket_id'=>int,'count'=>int], ...]
 */
function eventosapp_net2_get_top_readers_today($event_id, $limit = 10){
    global $wpdb;
    $table = eventosapp_net2_logs_table_name();
    $event_id = (int) $event_id;
    $limit = (int) $limit;

    // Filtramos por fecha actual (según la zona horaria de la DB; si tus timestamps están en UTC, adapta aquí)
    $sql = "
        SELECT reader_ticket_id AS ticket_id, COUNT(*) AS cnt
        FROM {$table}
        WHERE event_id = %d
          AND DATE(created_at) = CURDATE()
        GROUP BY reader_ticket_id
        ORDER BY cnt DESC, reader_ticket_id ASC
        LIMIT %d
    ";
    $rows = $wpdb->get_results( $wpdb->prepare($sql, $event_id, $limit), ARRAY_A );

    $out = [];
    foreach ((array)$rows as $r){
        $out[] = [
            'ticket_id' => (int)$r['ticket_id'],
            'count'     => (int)$r['cnt'],
        ];
    }
    return $out;
}

/**
 * Top leídos (contactos que MÁS han sido leídos) del día actual.
 * Usa COALESCE(read_ticket_id, target_ticket_id) por compatibilidad.
 */
function eventosapp_net2_get_top_read_targets_today($event_id, $limit = 10){
    global $wpdb;
    $table = eventosapp_net2_logs_table_name();
    $event_id = (int) $event_id;
    $limit = (int) $limit;

    $sql = "
        SELECT COALESCE(read_ticket_id, target_ticket_id) AS ticket_id, COUNT(*) AS cnt
        FROM {$table}
        WHERE event_id = %d
          AND DATE(created_at) = CURDATE()
        GROUP BY COALESCE(read_ticket_id, target_ticket_id)
        ORDER BY cnt DESC, ticket_id ASC
        LIMIT %d
    ";
    $rows = $wpdb->get_results( $wpdb->prepare($sql, $event_id, $limit), ARRAY_A );

    $out = [];
    foreach ((array)$rows as $r){
        $out[] = [
            'ticket_id' => (int)$r['ticket_id'],
            'count'     => (int)$r['cnt'],
        ];
    }
    return $out;
}

/**
 * AJAX: obtener ranking del día (solo autenticados y con permisos).
 */
add_action('wp_ajax_eventosapp_net2_ranking_today', function(){
    if ( ! is_user_logged_in() ) {
        wp_send_json_error(['error'=>'No autenticado.']);
    }
    // Protección por feature/página
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('networking_ranking') ) {
        wp_send_json_error(['error'=>'Sin permisos.']);
    }
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    if ( ! $event_id ) {
        // Si no llega, tratamos de usar el evento activo
        if ( function_exists('eventosapp_get_active_event') ) {
            $event_id = (int) eventosapp_get_active_event();
        }
    }
    if ( ! $event_id ) {
        wp_send_json_error(['error'=>'No hay evento activo.']);
    }

    $readers = eventosapp_net2_get_top_readers_today($event_id, 10);
    $targets = eventosapp_net2_get_top_read_targets_today($event_id, 10);

    // Enriquecer con nombres
    foreach ($readers as &$r){
        $r['name'] = eventosapp_ticket_full_name($r['ticket_id']);
    }
    foreach ($targets as &$t){
        $t['name'] = eventosapp_ticket_full_name($t['ticket_id']);
    }

    wp_send_json_success([
        'event_id' => $event_id,
        'readers'  => $readers,
        'targets'  => $targets,
        'date'     => date_i18n('l, d \d\e F \d\e Y'),
    ]);
});

/**
 * Shortcode: [eventosapp_networking_ranking]
 * Requiere estar logueado y tener permiso/feature 'networking_ranking'.
 * Muestra: título evento, fecha actual, botón actualizar y dos rankings (Top lectores / Top leídos).
 */
add_shortcode('eventosapp_networking_ranking', function(){
    if ( ! function_exists('eventosapp_require_feature') ) {
        return '<div style="color:#b33">Módulo base no disponible.</div>';
    }
    // Protegemos la feature
    eventosapp_require_feature('networking_ranking');

    // Event ID: usamos el evento activo
    $event_id = function_exists('eventosapp_get_active_event') ? (int) eventosapp_get_active_event() : 0;
    if ( ! $event_id ) {
        return '<div style="color:#b33">No hay evento activo.</div>';
    }

    $event_title = get_the_title($event_id) ?: 'Evento';
    $today_label = date_i18n('l, d \d\e F \d\e Y');

    ob_start(); ?>
    <style>
      .evapp-rank-wrap{ max-width:1100px; margin:0 auto; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
      .evapp-rank-head{ display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px; margin:0 0 16px; }
      .evapp-rank-title{ font-weight:800; font-size:1.1rem; letter-spacing:.2px; }
      .evapp-rank-date{ color:#5a6475; font-weight:600; }
      .evapp-rank-btn{ background:#2563eb; color:#fff; border:0; border-radius:12px; padding:.6rem 1rem; font-weight:800; cursor:pointer; }
      .evapp-rank-grid{ display:grid; grid-template-columns:1fr; gap:18px; margin-top:6px; }
      @media(min-width:960px){ .evapp-rank-grid{ grid-template-columns:1fr 1fr; } }

      .evapp-panel{ background:#0b1020; color:#eaf1ff; border-radius:16px; padding:16px; box-shadow:0 8px 24px rgba(0,0,0,.15); }
      .evapp-panel h3{ margin:0 0 10px; font-size:1rem; letter-spacing:.2px; }

      .evapp-podium{ display:grid; gap:10px; }
      .evapp-item{ display:flex; align-items:center; gap:12px; background:#0a0f1d; border:1px solid rgba(255,255,255,.06); border-radius:12px; padding:10px 12px; }
      .evapp-rank{ display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; background:#1f2a44; font-weight:900; }
      .evapp-name{ font-weight:800; }
      .evapp-count{ margin-left:auto; font-weight:900; color:#7CFF8D; }

      /* Jerarquía de tamaños */
      .tier-1  .evapp-item:nth-child(1){ transform:scale(1.12); border-width:2px; border-color:#ffd25a; }
      .tier-2  .evapp-item:nth-child(n+2):nth-child(-n+5){ transform:scale(1.06); }
      /* Del 6 al 10 normal (sin transform) */

      .evapp-empty{ color:#b2bed6; font-size:.95rem; }
    </style>

    <div class="evapp-rank-wrap" data-event="<?php echo esc_attr($event_id); ?>">
      <div class="evapp-rank-head">
        <div>
          <div class="evapp-rank-title">Ranking Networking — <strong><?php echo esc_html($event_title); ?></strong></div>
          <div class="evapp-rank-date" id="evappRankDate"><?php echo esc_html($today_label); ?></div>
        </div>
        <div>
          <button type="button" class="evapp-rank-btn" id="evappRankRefresh">Actualizar</button>
        </div>
      </div>

      <div class="evapp-rank-grid">
        <div class="evapp-panel">
          <h3>Top 10 — Usuarios que más han <strong>leído contactos</strong> (hoy)</h3>
          <div id="evappReaders" class="evapp-podium"></div>
        </div>
        <div class="evapp-panel">
          <h3>Top 10 — Contactos que más han sido <strong>leídos</strong> (hoy)</h3>
          <div id="evappTargets" class="evapp-podium"></div>
        </div>
      </div>
    </div>

    <script>
    (function(){
      const root   = document.querySelector('.evapp-rank-wrap');
      const eventId= parseInt(root?.dataset.event || '0', 10) || 0;
      const ajaxURL= "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
      const readersBox = document.getElementById('evappReaders');
      const targetsBox = document.getElementById('evappTargets');
      const dateBox    = document.getElementById('evappRankDate');
      const btnRefresh = document.getElementById('evappRankRefresh');

      function renderList(container, rows){
        container.innerHTML = '';
        if (!rows || !rows.length){
          container.innerHTML = '<div class="evapp-empty">Sin datos por ahora.</div>';
          return;
        }
        // Tiers: 1 (primero), 2-5, 6-10
        container.classList.remove('tier-1', 'tier-2');
        if (rows.length >= 1) container.classList.add('tier-1');
        if (rows.length >= 2) container.classList.add('tier-2');

        rows.forEach((r, i)=>{
          const item = document.createElement('div');
          item.className = 'evapp-item';
          item.innerHTML = `
            <span class="evapp-rank">${i+1}</span>
            <span class="evapp-name">${(r.name||'—')}</span>
            <span class="evapp-count">${(r.count||0)}</span>
          `;
          container.appendChild(item);
        });
      }

      async function loadData(){
        const fd = new FormData();
        fd.append('action', 'eventosapp_net2_ranking_today');
        fd.append('event_id', String(eventId||0));
        try{
          const res = await fetch(ajaxURL, {method:'POST', body:fd, credentials:'same-origin'});
          const j = await res.json();
          if (!j || !j.success){
            const msg = (j && j.data && j.data.error) ? j.data.error : 'No se pudo cargar el ranking.';
            readersBox.innerHTML = '<div class="evapp-empty">'+msg+'</div>';
            targetsBox.innerHTML = '<div class="evapp-empty">'+msg+'</div>';
            return;
          }
          const d = j.data || {};
          renderList(readersBox, d.readers || []);
          renderList(targetsBox, d.targets || []);
          if (d.date) dateBox.textContent = d.date;
        }catch(e){
          readersBox.innerHTML = '<div class="evapp-empty">Error de red.</div>';
          targetsBox.innerHTML = '<div class="evapp-empty">Error de red.</div>';
        }
      }

      btnRefresh?.addEventListener('click', loadData);
      loadData();
      // Autorefresco cada 30s
      setInterval(loadData, 30000);
    })();
    </script>
    <?php
    return ob_get_clean();
});

