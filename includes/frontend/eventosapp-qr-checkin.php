<?php
/**
 * EventosApp – QR Check-In (frontend)
 * Shortcode: [eventosapp_qr_checkin]
 * - Requiere sesión iniciada y evento activo (usa eventosapp_require_active_event()).
 * - Limita el check-in al evento activo del usuario.
 * - Usa BarcodeDetector (rápido) con fallback a jsQR.
 * - Muestra marco/guía, botón de cámara y ficha del asistente.
 */

if ( ! defined('ABSPATH') ) exit;

//
// === Permisos: quién puede usar el lector ===
//
if ( ! function_exists('eventosapp_current_user_can_checkin') ) {
    function eventosapp_current_user_can_checkin() {
        if ( ! is_user_logged_in() ) return false;
        $u = wp_get_current_user();
        $roles = (array) $u->roles;
        $allowed = ['administrator','organizador','staff','logistico'];
        return (bool) array_intersect($allowed, $roles);
    }
}

// === Shortcode: Check-In general ===
add_shortcode('eventosapp_qr_checkin', function($atts){
// 🔒 Requiere permiso para "qr" (botón Check-In con QR)
if ( function_exists('eventosapp_require_feature') ) {
    eventosapp_require_feature('qr'); // redirige al dashboard con mensaje si no puede
}

    // Debe existir un evento activo
    $active_event = function_exists('eventosapp_get_active_event') ? eventosapp_get_active_event() : 0;
    if ( ! $active_event ) {
        ob_start();
        if (function_exists('eventosapp_require_active_event')) {
            eventosapp_require_active_event();
        } else {
            echo '<p>Debes seleccionar un evento activo.</p>';
        }
        return ob_get_clean();
    }

    // Registrar jsQR para fallback (solo por feature-detect)
    add_action('wp_enqueue_scripts', function(){
        if (!wp_script_is('jsqr', 'registered')) {
            wp_register_script('jsqr', 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js', [], null, true);
        }
    });

    // Nonce para AJAX
    $nonce = wp_create_nonce('eventosapp_qr_checkin');

    ob_start(); ?>
    <style>
    .evapp-qr-shell {
      max-width: 520px; margin: 0 auto; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    }
    .evapp-qr-card {
      background:#0b1020; color:#eaf1ff; border-radius:16px; padding:18px; box-shadow:0 8px 24px rgba(0,0,0,.15);
    }
    .evapp-qr-title {
      display:flex; align-items:center; gap:.6rem; margin:0 0 10px 0; font-weight:700; font-size:1.05rem; letter-spacing:.2px;
    }
    .evapp-qr-title svg { opacity:.9 }
    .evapp-qr-btn {
      display:flex; align-items:center; justify-content:center; gap:.5rem;
      border:0; border-radius:12px; padding:.9rem 1.1rem; font-weight:700; cursor:pointer; width:100%;
      background:#4f7cff; color:#fff;
      transition: filter .15s ease, background .15s ease;
    }
    .evapp-qr-btn:hover { filter:brightness(.96); }
    .evapp-qr-btn.is-live { background:#e04f5f; color:#fff; } /* rojo cuando la cámara está encendida */

	/* Botón secundario (Escanear otro QR) */
	.evapp-qr-btn-secondary{
	  margin-top:12px; width:100%;
	  background:#4f7cff!important;
	  color:#fff; border:0; border-radius:10px; padding:.7rem 1rem; font-weight:800; cursor:pointer;
	  transition: filter .15s ease;
	}
	.evapp-qr-btn-secondary:hover{ filter:brightness(1.05); }

    .evapp-qr-video-wrap {
      position:relative; margin-top:12px; border-radius:14px; overflow:hidden; background:#0a0f1d;
      aspect-ratio: 3/4;
    }
    .evapp-qr-video { width:100%; height:100%; object-fit:cover; display:none; }
    .evapp-qr-frame { position:absolute; inset:0; pointer-events:none; display:none; }
    .evapp-qr-frame .mask {
      position:absolute; inset:0; backdrop-filter: none;
      background: radial-gradient(ellipse 60% 40% at 50% 50%, rgba(255,255,255,0) 62%, rgba(10,15,29,.55) 64%);
    }
    .evapp-qr-corner {
      position:absolute; width:44px; height:44px; border:4px solid #4f7cff; border-radius:10px;
    }
    .evapp-qr-corner.tl { top:16px; left:16px; border-right:0; border-bottom:0; }
    .evapp-qr-corner.tr { top:16px; right:16px; border-left:0; border-bottom:0; }
    .evapp-qr-corner.bl { bottom:16px; left:16px; border-right:0; border-top:0; }
    .evapp-qr-corner.br { bottom:16px; right:16px; border-left:0; border-top:0; }

    .evapp-qr-result {
      margin-top:14px; background:#0a0f1d; border:1px solid rgba(255,255,255,.06);
      border-radius:12px; padding:14px;
    }
    .evapp-qr-ok    { color:#7CFF8D; font-weight:800; }
    .evapp-qr-warn  { color:#ffd166; font-weight:700; }
    .evapp-qr-bad   { color:#ff6b6b; font-weight:700; }
    .evapp-qr-grid { display:grid; grid-template-columns: 1fr; gap:.2rem .8rem; margin-top:.4rem; }
    .evapp-qr-grid div b { color:#a7b8ff; font-weight:600; }
    .evapp-qr-help { color:#a9b6d3; font-size:.9rem; margin-top:.6rem; opacity:.8 }

    @media (min-width: 480px){
      .evapp-qr-grid { grid-template-columns: auto 1fr; }
      .evapp-qr-grid div { display:contents; }
      .evapp-qr-grid b { text-align:right; }
    }

    /* === MODO INMERSIVO DEL VISOR (auto-scroll) === */
    .evapp-qr-video-wrap.is-immersive{
      aspect-ratio:auto;
      height: calc(100vh - var(--evapp-offset, 56px));
      width: 100%;
    }
    </style>

    <div class="evapp-qr-shell" data-event="<?php echo esc_attr($active_event); ?>">
      <div class="evapp-qr-card">
        <div class="evapp-qr-title">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6zm14 0h2v6h-6v-2h4v-4z" stroke="#a7b8ff"/></svg>
          Lector de QR – Check-In (evento activo)
        </div>

        <button class="evapp-qr-btn" id="evappStartScan">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/><rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/></svg>
          Activar cámara y escanear
        </button>

        <div class="evapp-qr-video-wrap">
          <video id="evappVideo" class="evapp-qr-video" playsinline></video>
          <div class="evapp-qr-frame" id="evappFrame">
            <div class="mask"></div>
            <div class="evapp-qr-corner tl"></div>
            <div class="evapp-qr-corner tr"></div>
            <div class="evapp-qr-corner bl"></div>
            <div class="evapp-qr-corner br"></div>
          </div>
          <canvas id="evappCanvas" style="display:none;"></canvas>
        </div>

        <div class="evapp-qr-result" id="evappResult">
          <div class="evapp-qr-help">Tip: coloca el QR dentro del marco. La lectura vibra/emite sonido al capturar.</div>
        </div>
      </div>
    </div>

<script>
(function(){
  const ajaxURL   = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
  const ajaxNonce = "<?php echo esc_js( $nonce ); ?>";
  const eventID   = parseInt(document.querySelector('.evapp-qr-shell').dataset.event,10)||0;

  const btn   = document.getElementById('evappStartScan');
  const video = document.getElementById('evappVideo');
  const frame = document.getElementById('evappFrame');
  const cvs   = document.getElementById('evappCanvas');
  const ctx   = cvs.getContext('2d');
  const out   = document.getElementById('evappResult');
  const vwrap = video.closest('.evapp-qr-video-wrap') || video.parentElement;

  let stream = null;
  let running = false;
  let lastScan = "";      // evita dobles lecturas del mismo QR
  let lastAt   = 0;
  let barcodeDetector = ('BarcodeDetector' in window) ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null;

  // === Scroll helpers ===
  function getOffsetCompensation(){
    const adminBar = document.getElementById('wpadminbar');
    const adminH = adminBar ? adminBar.offsetHeight : 0;
    return adminH + 10;
  }
  function smoothScrollTo(el){
    if (!el) return;
    const offset = getOffsetCompensation();
    try { el.style.setProperty('--evapp-offset', offset + 'px'); } catch(e){}
    const y = el.getBoundingClientRect().top + window.pageYOffset - offset;
    window.scrollTo({ top: y, behavior: 'smooth' });
  }

  function setLiveUI(on){
    if (on) {
      btn.classList.add('is-live');
      btn.innerHTML = `
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
          <path d="M6 6h12v12H6z" stroke="white"/>
        </svg>
        Detener cámara
      `;
    } else {
      btn.classList.remove('is-live');
      btn.innerHTML = `
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
          <path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/>
          <rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/>
        </svg>
        Activar cámara y escanear
      `;
    }
  }

  function beep(){
    try { const a = new Audio(); a.src = 'data:audio/mp3;base64,//uQxAAAAAAAAAAAAAAAAAAAAAAAWGlinZwAAAA8AAAACAAACcQAA'; a.play().catch(()=>{}); } catch(e){}
    if (navigator.vibrate) navigator.vibrate(60);
  }

  function setOutput(html){ out.innerHTML = html; }
  function row(label, value){ return `<div><b>${label}:</b></div><div>${value || '-'}</div>`; }

  function normalizeRaw(raw){
    let s = String(raw||'').trim();
    // Si es una URL completa (badge/escarapela), devolverla sin modificar
    if (s.startsWith('http://') || s.startsWith('https://')) {
      return s;
    }
    // Para otros casos, aplicar normalización
    if (s.includes('/')) s = s.split('/').pop();
    s = s.replace(/\.(png|jpg|jpeg|pdf)$/i,'').replace(/-tn$/i,'').replace(/^#/, '');
    return s;
  }

  function stop(){
    running = false;
    if (stream) stream.getTracks().forEach(t=>t.stop());
    stream = null;
    video.style.display = 'none';
    frame.style.display = 'none';
    if (vwrap){ vwrap.classList.remove('is-immersive'); }
    setLiveUI(false);
  }

  async function ensureJsQR(){
    if ('BarcodeDetector' in window) return false;
    if (!window.jsQR) {
      await new Promise((resolve)=>{
        const s = document.createElement('script');
        s.src = (window.jsqr_src || 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js');
        s.onload = resolve;
        document.head.appendChild(s);
      });
    }
    return true;
  }

  async function start(){
    if (!eventID){
      setOutput('<div class="evapp-qr-bad">No hay evento activo.</div>');
      return;
    }
    try{
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal:'environment' } }, audio:false });
    }catch(e){
      setOutput('<div class="evapp-qr-bad">No se pudo acceder a la cámara.</div>');
      return;
    }
    video.srcObject = stream;
    await video.play();
    video.style.display = 'block';
    frame.style.display = 'block';

    // Modo inmersivo + scroll al visor
    if (vwrap){ vwrap.classList.add('is-immersive'); smoothScrollTo(vwrap); }

    cvs.width  = video.videoWidth  || 640;
    cvs.height = video.videoHeight || 480;

    running = true;
    setLiveUI(true);
    tick();
  }

  async function tick(){
    if (!running) return;
    ctx.drawImage(video, 0, 0, cvs.width, cvs.height);

    if (barcodeDetector) {
      try {
        const bitmap = await createImageBitmap(cvs);
        const codes  = await barcodeDetector.detect(bitmap);
        if (codes && codes.length){
          const data = normalizeRaw(codes[0].rawValue || '');
          onScan(data);
          return;
        }
      } catch(e){}
    } else if (window.jsQR) {
      const img  = ctx.getImageData(0,0,cvs.width,cvs.height);
      const code = window.jsQR(img.data, img.width, img.height);
      if (code && code.data) {
        const data = normalizeRaw(code.data);
        onScan(data);
        return;
      }
    }
    requestAnimationFrame(tick);
  }

  function injectScanAgainButton(){
    // Añade el botón "Escanear otro QR" bajo el resultado.
    const againBtn = document.createElement('button');
    againBtn.id = 'evappScanAnother';
    againBtn.type = 'button';
    againBtn.className = 'evapp-qr-btn-secondary';
    againBtn.textContent = 'Escanear otro QR';
    out.appendChild(againBtn);

    againBtn.addEventListener('click', async ()=>{
      // 1) Scroll hacia el visor/botón principal
      smoothScrollTo(vwrap || btn);

      // 2) Volver a activar cámara automáticamente
      await ensureJsQR();
      await start();
      setOutput('<div class="evapp-qr-help">Tip: coloca el QR dentro del marco. La lectura vibra/emite sonido al capturar.</div>');

      // Si prefieres que SOLO haga scroll y el usuario pulse el botón principal,
      // comenta las líneas de start() y setOutput() arriba, dejando solo el scroll.
    }, { once:false });
  }

  function onScan(data){
    const now = Date.now();
    if (data === lastScan && (now - lastAt) < 2500){
      requestAnimationFrame(tick);
      return;
    }
    lastScan = data; lastAt = now;
    beep();

    // Detener cámara para no leer de nuevo hasta que el usuario lo decida
    stop();

    setOutput('<div class="evapp-qr-help">Procesando: '+ data +'…</div>');
    smoothScrollTo(out);

    const fd = new FormData();
    fd.append('action','eventosapp_qr_checkin_toggle');
    fd.append('security','<?php echo esc_js( $nonce ); ?>');
    fd.append('event_id', String(eventID));
    fd.append('scanned',  data);

    fetch(ajaxURL, { method:'POST', body:fd, credentials:'same-origin' })
      .then(r=>r.json())
      .then(resp=>{
        if (!resp || !resp.success){
          const msg = (resp && resp.data && resp.data.error) ? resp.data.error : 'Error desconocido';
          setOutput('<div class="evapp-qr-bad">'+ msg +'</div>');
          injectScanAgainButton();
          smoothScrollTo(out);
          return;
        }
        const d = resp.data || {};
        const statusHtml = d.already
          ? '<span class="evapp-qr-warn">✔ Ticket ya estaba Checked In</span>'
          : '<span class="evapp-qr-ok">✔ Check In confirmado</span>';

        let html = statusHtml + '<div class="evapp-qr-grid">';
        html += row('Nombre', d.full_name);
        html += row('Evento', d.event_name);
        html += row('Fecha del check-in', d.checkin_date_label || d.checkin_date);
        html += row('Empresa', d.company);
        html += row('Cargo', d.designation);
        html += row('Localidad', d.localidad);
        html += '</div>';
        setOutput(html);
        injectScanAgainButton(); // <-- aquí agregamos el botón
        smoothScrollTo(out);
      })
      .catch(()=>{
        setOutput('<div class="evapp-qr-bad">No se pudo verificar el ticket.</div>');
        injectScanAgainButton();
        smoothScrollTo(out);
      });
  }

  // === Botón toggle: enciende/apaga la cámara ===
  btn.addEventListener('click', async ()=>{
    if (stream && stream.active) {
      stop();
      setOutput('<div class="evapp-qr-help">Cámara detenida. Haz clic para activar y escanear.</div>');
      smoothScrollTo(out);
      return;
    }
    await ensureJsQR();
    await start();
    setOutput('<div class="evapp-qr-help">Tip: coloca el QR dentro del marco. La lectura vibra/emite sonido al capturar.</div>');
  });
})();
</script>
    <?php
    return ob_get_clean();
});




//
// === AJAX: marcar Check-In del ticket para el EVENTO ACTIVO ===
// MODIFICADO: Agrega soporte para QR tipo URL (badge)
//
add_action('wp_ajax_eventosapp_qr_checkin_toggle', function(){
// 🔒 Seguridad por rol (feature: qr)
if ( ! is_user_logged_in() ) {
    wp_send_json_error(['error' => 'Debes iniciar sesión.'], 401);
}
if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('qr') ) {
    wp_send_json_error(['error' => 'Permisos insuficientes para Check-In con QR.'], 403);
}
    check_ajax_referer('eventosapp_qr_checkin','security');

    $scanned  = isset($_POST['scanned'])  ? sanitize_text_field( wp_unslash($_POST['scanned']) ) : '';
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;

    // 🔒 Forzar evento ACTIVO para no-admins
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $event_id !== $active ) {
            wp_send_json_error(['error' => 'Sin permisos para este evento.'], 403);
        }
    }

    if ( ! $scanned || ! $event_id ) wp_send_json_error(['error'=>'Datos incompletos']);

    global $wpdb;
    
    // === Variables iniciales ===
    $qr_type = 'unknown';
    $qr_type_label = 'QR Estándar';
    $ticket_post_id = 0;
    
    // === NUEVO: Verificar si el contenido escaneado es una URL (QR de badge) ===
    if (strpos($scanned, 'http') === 0) {
        // Es una URL, intentar extraer datos
        $url_parts = parse_url($scanned);
        if (isset($url_parts['query'])) {
            parse_str($url_parts['query'], $params);
            
            if (isset($params['event'])) {
                // Formato esperado: event=123-ticketid=ABC123-7890
                $parts = explode('-', $params['event']);
                
                if (count($parts) >= 3) {
                    // Extraer event_id del primer segmento
                    $url_event_id = intval($parts[0]);
                    
                    // Extraer unique_ticket_id del segundo segmento (después de "ticketid=")
                    $ticket_part = isset($parts[1]) ? $parts[1] : '';
                    $unique_ticket_id = '';
                    if (strpos($ticket_part, 'ticketid=') === 0) {
                        $unique_ticket_id = str_replace('ticketid=', '', $ticket_part);
                    }
                    
                    // Código de seguridad (último segmento)
                    $url_security_code = isset($parts[2]) ? $parts[2] : '';
                    
                    // Validar que el evento coincide con el evento activo
                    if ($url_event_id === $event_id && !empty($unique_ticket_id)) {
                        // Buscar el Post ID usando el eventosapp_ticketID
                        $found_post_id = $wpdb->get_var($wpdb->prepare(
                            "SELECT post_id FROM {$wpdb->postmeta} 
                            WHERE meta_key = 'eventosapp_ticketID' 
                            AND meta_value = %s 
                            LIMIT 1",
                            $unique_ticket_id
                        ));
                        
                        if ($found_post_id && get_post_type($found_post_id) === 'eventosapp_ticket') {
                            // Validar que el ticket pertenece al evento
                            $found_ticket_event = (int) get_post_meta($found_post_id, '_eventosapp_ticket_evento_id', true);
                            
                            if ($found_ticket_event === $event_id) {
                                // Validar código de seguridad
                                $stored_security_code = get_post_meta($found_post_id, '_eventosapp_badge_security_code', true);
                                
                                if (!empty($stored_security_code) && $stored_security_code === $url_security_code) {
                                    // Todo válido
                                    $ticket_post_id = (int) $found_post_id;
                                    $qr_type = 'badge';
                                    $qr_type_label = 'Escarapela Impresa (URL)';
                                } else {
                                    wp_send_json_error(['error' => 'Código de seguridad del badge inválido']);
                                }
                            } else {
                                wp_send_json_error(['error' => 'El QR del badge no corresponde a este evento']);
                            }
                        } else {
                            wp_send_json_error(['error' => 'Ticket del badge no encontrado (ID: ' . $unique_ticket_id . ')']);
                        }
                    } else {
                        wp_send_json_error(['error' => 'El QR del badge no corresponde a este evento']);
                    }
                }
            }
        }
    }
    
    // === Si no es URL, intentar decodificar como QR del sistema multi-medio (base64) ===
    if (!$ticket_post_id) {
        $decoded = base64_decode($scanned, true);
        if ($decoded !== false) {
            $qr_data = @json_decode($decoded, true);
            if (is_array($qr_data) && isset($qr_data['ticket_id']) && isset($qr_data['type']) && isset($qr_data['qr_id'])) {
                // Es un QR del nuevo sistema
                $ticket_post_id = absint($qr_data['ticket_id']);
                $qr_type = sanitize_text_field($qr_data['type']);
                
                // Validar que el ticket existe y el QR ID coincide
                if (get_post_type($ticket_post_id) === 'eventosapp_ticket') {
                    $stored_qr_id = get_post_meta($ticket_post_id, '_eventosapp_qr_' . $qr_type, true);
                    if ($stored_qr_id !== $qr_data['qr_id']) {
                        wp_send_json_error(['error' => 'QR no válido o revocado.']);
                    }
                    
                    // Obtener el label del tipo
                    $qr_types = array(
                        'email' => 'Email',
                        'google_wallet' => 'Google Wallet',
                        'apple_wallet' => 'Apple Wallet',
                        'pdf' => 'PDF Impreso',
                        'badge' => 'Escarapela Impresa'
                    );
                    $qr_type_label = isset($qr_types[$qr_type]) ? $qr_types[$qr_type] : $qr_type;
                } else {
                    $ticket_post_id = 0; // Ticket no válido
                }
            }
        }
    }
    
    // === Si tampoco es del nuevo sistema, usar el método legacy (tradicional) ===
    if (!$ticket_post_id) {
        // ¿El evento usa QR preimpreso?
        $use_preprinted = (get_post_meta($event_id, '_eventosapp_ticket_use_preprinted_qr', true) === '1');

        // Meta a consultar y normalización del valor escaneado
        $meta_key = $use_preprinted ? 'eventosapp_ticket_preprintedID' : 'eventosapp_ticketID';
        if ( $use_preprinted ) {
            $scan_val = preg_replace('/\D+/', '', $scanned); // solo dígitos
            if ($scan_val === '') {
                wp_send_json_error(['error' => 'QR inválido: se esperaba un número.']);
            }
        } else {
            $scan_val = $scanned;
        }

        // Busca TODOS los posibles y luego filtra por evento (evita colisiones entre eventos)
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            $meta_key, $scan_val
        ) );

        if ($ids) {
            foreach ($ids as $cand) {
                if ((int) get_post_meta($cand, '_eventosapp_ticket_evento_id', true) === (int) $event_id) {
                    $ticket_post_id = (int) $cand;
                    break;
                }
            }
        }
        
        // Marcar como QR legacy
        $qr_type = 'legacy';
        $qr_type_label = $use_preprinted ? 'QR Preimpreso' : 'QR Legacy';
    }

    if ( ! $ticket_post_id ) {
        wp_send_json_error(['error' => 'Ticket no encontrado']);
    }

    // Seguridad extra (una sola vez)
    $ticket_event = (int) get_post_meta($ticket_post_id, '_eventosapp_ticket_evento_id', true);
    if ( $ticket_event !== (int) $event_id ) {
        wp_send_json_error(['error'=>'El ticket no pertenece al evento activo']);
    }

    // ===== Reglas de fecha =====
    // 1) Obtener zona horaria del evento (o la del sitio)
    $event_tz = get_post_meta($event_id, '_eventosapp_zona_horaria', true);
    if (!$event_tz) {
        $event_tz = wp_timezone_string();
        if (!$event_tz || $event_tz === 'UTC') {
            $offset = get_option('gmt_offset');
            $event_tz = $offset ? timezone_name_from_abbr('', $offset * 3600, 0) ?: 'UTC' : 'UTC';
        }
    }

    // 2) "Hoy" en la TZ del evento
    try {
        $dt = new DateTime('now', new DateTimeZone($event_tz));
    } catch (Exception $e) {
        $dt = new DateTime('now', wp_timezone());
    }
    $today = $dt->format('Y-m-d');

    // 3) Días válidos del evento
    $days = function_exists('eventosapp_get_event_days') ? (array) eventosapp_get_event_days($event_id) : [];

    // 4) Si hoy NO es un día del evento => bloquear
    if (empty($days) || !in_array($today, $days, true)) {
        wp_send_json_error(['error' => 'El check-in solo está permitido en las fechas del evento. Hoy no corresponde.']);
    }

    // 5) Estado multidía
    $status_arr = get_post_meta($ticket_post_id, '_eventosapp_checkin_status', true);
    if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
    if (!is_array($status_arr)) $status_arr = [];

    $already = (isset($status_arr[$today]) && $status_arr[$today] === 'checked_in');

    if (!$already) {
        $status_arr[$today] = 'checked_in';
        update_post_meta($ticket_post_id, '_eventosapp_checkin_status', $status_arr);

        // Log - MODIFICADO: Agregar información del tipo de QR
        $log = get_post_meta($ticket_post_id, '_eventosapp_checkin_log', true);
        if (is_string($log)) $log = @unserialize($log);
        if (!is_array($log)) $log = [];

        $user = wp_get_current_user();
        $log[] = [
            'fecha'   => $dt->format('Y-m-d'),
            'hora'    => $dt->format('H:i:s'),
            'dia'     => $today,
            'status'  => 'checked_in',
            'usuario' => $user && $user->exists() ? ($user->display_name.' ('.$user->user_email.')') : 'Sistema',
            'qr_type' => $qr_type,              // NUEVO: Tipo de QR usado
            'qr_type_label' => $qr_type_label   // NUEVO: Label legible del tipo
        ];
        update_post_meta($ticket_post_id, '_eventosapp_checkin_log', $log);
        
        // NUEVO: Actualizar estadísticas de uso de QR por tipo
        eventosapp_update_qr_usage_stats($event_id, $qr_type);
    }

    // Datos para mostrar
    $first = get_post_meta($ticket_post_id, '_eventosapp_asistente_nombre', true);
    $last  = get_post_meta($ticket_post_id, '_eventosapp_asistente_apellido', true);
    $comp  = get_post_meta($ticket_post_id, '_eventosapp_asistente_empresa', true);
    $role  = get_post_meta($ticket_post_id, '_eventosapp_asistente_cargo', true);
    $loc   = get_post_meta($ticket_post_id, '_eventosapp_asistente_localidad', true);

    wp_send_json_success([
        'already'     => $already,
        'full_name'   => trim($first.' '.$last),
        'company'     => $comp,
        'designation' => $role,
        'localidad'   => $loc,
        'event_name'  => get_the_title($event_id),
        'ticket_id'   => $ticket_post_id,
        'checkin_date'       => $today,
        'checkin_date_label' => date_i18n('D, d M Y', strtotime($today)),
        'qr_type_label'      => $qr_type_label  // NUEVO: Enviar tipo de QR al frontend
    ]);
});

/**
 * ========================================================================
 * FUNCIÓN NUEVA: Actualizar estadísticas de uso de QR
 * ========================================================================
 * 
 * UBICACIÓN: Agregar AL FINAL del archivo eventosapp-qr-checkin.php
 * JUSTO ANTES del cierre: ?>
 * 
 * INSTRUCCIONES:
 * 1. Ve al final del archivo eventosapp-qr-checkin.php
 * 2. Busca la línea final que tiene: ?>
 * 3. ANTES de esa línea, agrega esta función COMPLETA
 */

if (!function_exists('eventosapp_update_qr_usage_stats')) {
    function eventosapp_update_qr_usage_stats($event_id, $qr_type) {
        if (!$event_id || !$qr_type) return;
        
        // Obtener estadísticas actuales
        $stats = get_post_meta($event_id, '_eventosapp_qr_usage_stats', true);
        if (!is_array($stats)) {
            $stats = array(
                'email' => 0,
                'google_wallet' => 0,
                'apple_wallet' => 0,
                'pdf' => 0,
                'badge' => 0,
                'legacy' => 0,
                'total' => 0
            );
        }
        
        // Incrementar contador del tipo
        if (!isset($stats[$qr_type])) {
            $stats[$qr_type] = 0;
        }
        $stats[$qr_type]++;
        
        // Actualizar total
        $stats['total'] = array_sum(array_filter($stats, 'is_numeric'));
        
        // Agregar timestamp de última actualización
        $stats['last_updated'] = current_time('mysql');
        
        // Guardar
        update_post_meta($event_id, '_eventosapp_qr_usage_stats', $stats);
    }
}

// === Shortcode: Validador de Localidad (solo lectura) ===
// Uso: [eventosapp_qr_localidad]
add_shortcode('eventosapp_qr_localidad', function($atts){
// 🔒 Requiere permiso para "qr" (redirige con mensaje si no puede)
if ( function_exists('eventosapp_require_feature') ) {
    eventosapp_require_feature('qr');
}


    // Debe existir un evento activo
    $active_event = function_exists('eventosapp_get_active_event') ? eventosapp_get_active_event() : 0;
    if ( ! $active_event ) {
        ob_start();
        if (function_exists('eventosapp_require_active_event')) {
            eventosapp_require_active_event();
        } else {
            echo '<p>Debes seleccionar un evento activo.</p>';
        }
        return ob_get_clean();
    }

    // Registrar jsQR para fallback
    add_action('wp_enqueue_scripts', function(){
        if (!wp_script_is('jsqr', 'registered')) {
            wp_register_script('jsqr', 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js', [], null, true);
        }
    });

    // Nonce para AJAX
    $nonce = wp_create_nonce('eventosapp_qr_localidad');

    ob_start(); ?>
    <style>
    .evapp-qr-shell { max-width: 520px; margin: 0 auto; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
    .evapp-qr-card { background:#0b1020; color:#eaf1ff; border-radius:16px; padding:18px; box-shadow:0 8px 24px rgba(0,0,0,.15); }
    .evapp-qr-title { display:flex; align-items:center; gap:.6rem; margin:0 0 10px 0; font-weight:700; font-size:1.05rem; letter-spacing:.2px; }
    .evapp-qr-title svg { opacity:.9 }

    /* Botón principal verde para este shortcode */
    .evapp-qr-localidad .evapp-qr-btn { background:#22c55e !important; color:#fff; }
    .evapp-qr-localidad .evapp-qr-btn:hover { background:#16a34a !important; }
    .evapp-qr-localidad .evapp-qr-btn:active { background:#15803d !important; }
    .evapp-qr-localidad .evapp-qr-btn.is-live { background:#e04f5f !important; }

    /* Botón secundario (Escanear otro QR) – mismo color que el principal */
    .evapp-qr-localidad .evapp-qr-btn-secondary{
      margin-top:12px; width:100%;
      background:#22c55e!important;
      color:#fff; border:0; border-radius:10px; padding:.7rem 1rem; font-weight:800; cursor:pointer;
      transition: filter .15s ease;
    }
    .evapp-qr-localidad .evapp-qr-btn-secondary:hover{ filter:brightness(1.05); }

    .evapp-qr-btn {
      display:flex; align-items:center; justify-content:center; gap:.5rem; border:0; border-radius:12px; padding:.9rem 1.1rem; font-weight:700; cursor:pointer; width:100%; transition: filter .15s ease, background .15s ease; color:#fff;
    }

    .evapp-qr-video-wrap { position:relative; margin-top:12px; border-radius:14px; overflow:hidden; background:#0a0f1d; aspect-ratio:3/4; }
    .evapp-qr-video { width:100%; height:100%; object-fit:cover; display:none; }
    .evapp-qr-frame { position:absolute; inset:0; pointer-events:none; display:none; }
    .evapp-qr-frame .mask { position:absolute; inset:0; backdrop-filter:none; background: radial-gradient(ellipse 60% 40% at 50% 50%, rgba(255,255,255,0) 62%, rgba(10,15,29,.55) 64%); }
    .evapp-qr-corner { position:absolute; width:44px; height:44px; border:4px solid #4f7cff; border-radius:10px; }
    .evapp-qr-corner.tl { top:16px; left:16px; border-right:0; border-bottom:0; }
    .evapp-qr-corner.tr { top:16px; right:16px; border-left:0; border-bottom:0; }
    .evapp-qr-corner.bl { bottom:16px; left:16px; border-right:0; border-top:0; }
    .evapp-qr-corner.br { bottom:16px; right:16px; border-left:0; border-top:0; }

    .evapp-qr-result { margin-top:14px; background:#0a0f1d; border:1px solid rgba(255,255,255,.06); border-radius:12px; padding:14px; }
    .evapp-qr-grid { display:grid; grid-template-columns: 1fr; gap:.2rem .8rem; margin-top:.4rem; }
    .evapp-qr-grid div b { color:#a7b8ff; font-weight:600; }
    .evapp-qr-help { color:#a9b6d3; font-size:.9rem; margin-top:.6rem; opacity:.8 }

    .evapp-loc-badge {
      display:inline-block; margin:6px 0 10px; padding:8px 12px;
      border-radius:12px; background:#ffd166; color:#1a202c;
      font-weight:900; font-size:1.4rem; letter-spacing:.5px; text-transform:uppercase;
    }

    .evapp-qr-ok   { color:#7CFF8D; font-weight:800; }
    .evapp-qr-bad  { color:#ff6b6b; font-weight:700; }
    .evapp-qr-warn { color:#ffd166; font-weight:700; }

    @media (min-width:480px){
      .evapp-qr-grid { grid-template-columns: auto 1fr; }
      .evapp-qr-grid div { display:contents; }
      .evapp-qr-grid b { text-align:right; }
    }

    .evapp-qr-video-wrap.is-immersive{
      aspect-ratio:auto;
      height: calc(100vh - var(--evapp-offset, 56px));
      width: 100%;
    }
    </style>

    <div class="evapp-qr-shell evapp-qr-localidad" data-event="<?php echo esc_attr($active_event); ?>">
      <div class="evapp-qr-card">
        <div class="evapp-qr-title">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6zm14 0h2v6h-6v-2h4v-4z" stroke="#a7b8ff"/></svg>
          Lector de QR – Validador de Localidad (solo lectura)
        </div>

        <button class="evapp-qr-btn" id="evappStartScanLoc">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/><rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/></svg>
          Activar cámara y escanear
        </button>

        <div class="evapp-qr-video-wrap">
          <video id="evappVideoLoc" class="evapp-qr-video" playsinline></video>
          <div class="evapp-qr-frame" id="evappFrameLoc">
            <div class="mask"></div>
            <div class="evapp-qr-corner tl"></div>
            <div class="evapp-qr-corner tr"></div>
            <div class="evapp-qr-corner bl"></div>
            <div class="evapp-qr-corner br"></div>
          </div>
          <canvas id="evappCanvasLoc" style="display:none;"></canvas>
        </div>

        <div class="evapp-qr-result" id="evappResultLoc">
          <div class="evapp-qr-help">Tip: escanea el QR para ver la localidad del asistente de forma destacada.</div>
        </div>
      </div>
    </div>

<script>
(function(){
  const ajaxURL   = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
  const ajaxNonce = "<?php echo esc_js( $nonce ); ?>";
  const wrap      = document.querySelector('.evapp-qr-localidad');
  const eventID   = parseInt(wrap?.dataset.event || '0', 10) || 0;

  const btn   = document.getElementById('evappStartScanLoc');
  const video = document.getElementById('evappVideoLoc');
  const frame = document.getElementById('evappFrameLoc');
  const cvs   = document.getElementById('evappCanvasLoc');
  const ctx   = cvs.getContext('2d');
  const out   = document.getElementById('evappResultLoc');
  const vwrap = video.closest('.evapp-qr-video-wrap') || video.parentElement;

  let stream = null;
  let running = false;
  let lastScan = "";
  let lastAt   = 0;
  let manualStop = false;
  let barcodeDetector = ('BarcodeDetector' in window) ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null;

  // === helpers scroll con compensación y css var
  function getOffsetCompensation(){
    const adminBar = document.getElementById('wpadminbar');
    const adminH = adminBar ? adminBar.offsetHeight : 0;
    return adminH + 10;
  }
  function smoothScrollTo(el){
    if (!el) return;
    const offset = getOffsetCompensation();
    try { el.style.setProperty('--evapp-offset', offset + 'px'); } catch(e){}
    const y = el.getBoundingClientRect().top + window.pageYOffset - offset;
    window.scrollTo({ top: y, behavior: 'smooth' });
  }

  function setLiveUI(on){
    if (on) {
      btn.classList.add('is-live');
      btn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6h12v12H6z" stroke="white"/></svg> Detener cámara`;
    } else {
      btn.classList.remove('is-live');
      btn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/><rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/></svg> Activar cámara y escanear`;
    }
  }

  function beep(){
    try { const a=new Audio(); a.src='data:audio/mp3;base64,//uQxAAAAAAAAAAAAAAAAAAAAAAAWGlinZwAAAA8AAAACAAACcQAA'; a.play().catch(()=>{});} catch(e){}
    if (navigator.vibrate) navigator.vibrate(60);
  }

  function setOutput(html){ out.innerHTML = html; }
  function row(label, value){ return `<div><b>${label}:</b></div><div>${value || '-'}</div>`; }

  function stop(){
    running = false;
    if (stream) stream.getTracks().forEach(t=>t.stop());
    stream = null;
    video.style.display = 'none';
    frame.style.display = 'none';
    vwrap?.classList.remove('is-immersive');
    setLiveUI(false);
  }

  async function ensureJsQR(){
    if ('BarcodeDetector' in window) return false;
    if (!window.jsQR) {
      await new Promise((resolve)=>{
        const s = document.createElement('script');
        s.src = (window.jsqr_src || 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js');
        s.onload = resolve;
        document.head.appendChild(s);
      });
    }
    return true;
  }

  async function start(){
    if (!eventID){
      setOutput('<div class="evapp-qr-bad">No hay evento activo.</div>');
      smoothScrollTo(out);
      return;
    }
    try{
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal:'environment' } }, audio:false });
    }catch(e){
      setOutput('<div class="evapp-qr-bad">No se pudo acceder a la cámara.</div>');
      smoothScrollTo(out);
      return;
    }
    video.srcObject = stream;
    await video.play();
    video.style.display = 'block';
    frame.style.display = 'block';

    // Modo inmersivo + scroll al visor (ahora aquí)
    if (vwrap){
      vwrap.classList.add('is-immersive');
      smoothScrollTo(vwrap);
    }

    cvs.width  = video.videoWidth  || 640;
    cvs.height = video.videoHeight || 480;

    running = true;
    manualStop = false;
    setLiveUI(true);

    // eliminar botón secundario si existía
    const again = document.getElementById('evappScanAgainLoc');
    if (again) again.remove();

    tick();
  }

  async function tick(){
    if (!running) return;
    ctx.drawImage(video, 0, 0, cvs.width, cvs.height);

    if (barcodeDetector) {
      try {
        const bitmap = await createImageBitmap(cvs);
        const codes  = await barcodeDetector.detect(bitmap);
        if (codes && codes.length){
          const data = (codes[0].rawValue || '').trim();
          onScan(data);
          return;
        }
      } catch(e){}
    } else if (window.jsQR) {
      const img  = ctx.getImageData(0,0,cvs.width,cvs.height);
      const code = window.jsQR(img.data, img.width, img.height);
      if (code && code.data) {
        onScan(String(code.data||'').trim());
        return;
      }
    }
    requestAnimationFrame(tick);
  }

  function injectScanAgainButton(){
    if (document.getElementById('evappScanAgainLoc')) return;
    const againBtn = document.createElement('button');
    againBtn.id = 'evappScanAgainLoc';
    againBtn.type = 'button';
    againBtn.className = 'evapp-qr-btn-secondary';
    againBtn.textContent = 'Escanear otro QR';
    out.appendChild(againBtn);

    againBtn.addEventListener('click', async ()=>{
      // Reactivar cámara; start() hará el scroll correcto
      await ensureJsQR();
      await start();
      setOutput('<div class="evapp-qr-help">Tip: escanea el QR para ver la localidad del asistente.</div>');
    });
  }

  function onScan(data){
    const now = Date.now();
    if (data === lastScan && (now - lastAt) < 2500){
      requestAnimationFrame(tick);
      return;
    }
    lastScan = data; lastAt = now;
    beep();

    manualStop = true;
    stop();

    setOutput('<div class="evapp-qr-help">Procesando: '+ data +'…</div>');
    smoothScrollTo(out);

    const fd = new FormData();
    fd.append('action','eventosapp_qr_localidad_lookup');
    fd.append('security', ajaxNonce);
    fd.append('event_id', String(eventID));
    fd.append('scanned',  data);

    fetch(ajaxURL, { method:'POST', body:fd, credentials:'same-origin' })
      .then(r=>r.json())
      .then(resp=>{
        if (!resp || !resp.success){
          const msg = (resp && resp.data && resp.data.error) ? resp.data.error : 'Error desconocido';
          setOutput('<div class="evapp-qr-bad">'+ msg +'</div>');
          injectScanAgainButton();
          smoothScrollTo(out);
          return;
        }
        const d = resp.data || {};
        const locText = d.localidad || '—';

        let html = `
          <div class="evapp-loc-badge">${locText}</div>
          <div class="evapp-qr-grid">
            ${row('Localidad', d.localidad)}
            ${row('Nombre', d.full_name)}
            ${row('Evento', d.event_name)}
            ${row('Empresa', d.company)}
            ${row('Cargo', d.designation)}
          </div>
        `;
        setOutput(html);
        injectScanAgainButton();
        smoothScrollTo(out);
      })
      .catch(()=>{
        setOutput('<div class="evapp-qr-bad">No se pudo obtener la información del asistente.</div>');
        injectScanAgainButton();
        smoothScrollTo(out);
      });
  }

  // Toggle cámara
  btn.addEventListener('click', async ()=>{
    if (stream && stream.active) {
      manualStop = true;
      stop();
      setOutput('<div class="evapp-qr-help">Cámara detenida. Haz clic para activar y escanear.</div>');
      smoothScrollTo(out);
      return;
    }
    await ensureJsQR();
    await start();
    setOutput('<div class="evapp-qr-help">Tip: escanea el QR para ver la localidad del asistente.</div>');
  });
})();
</script>
    <?php
    return ob_get_clean();
});





// === AJAX: Obtener datos del ticket (solo lectura) para mostrar localidad en grande ===
add_action('wp_ajax_eventosapp_qr_localidad_lookup', function(){
// 🔒 Seguridad por rol (feature: qr)
if ( ! is_user_logged_in() ) {
    wp_send_json_error(['error' => 'Debes iniciar sesión.'], 401);
}
if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('qr') ) {
    wp_send_json_error(['error' => 'Permisos insuficientes para Check-In con QR.'], 403);
}
    check_ajax_referer('eventosapp_qr_localidad','security');

    $scanned  = isset($_POST['scanned'])  ? sanitize_text_field( wp_unslash($_POST['scanned']) ) : '';
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;

    // 🔒 Forzar evento ACTIVO para no-admins
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $event_id !== $active ) {
            wp_send_json_error(['error' => 'Sin permisos para este evento.'], 403);
        }
    }


    if ( ! $scanned || ! $event_id ) wp_send_json_error(['error'=>'Datos incompletos']);

    global $wpdb;

    $ticket_post_id = 0;

    // === PASO 1: Intentar con el NUEVO sistema de QR (EventosApp_QR_Manager) ===
    if (class_exists('EventosApp_QR_Manager')) {
        $validation = EventosApp_QR_Manager::validate_qr($scanned);
        
        if (isset($validation['valid']) && $validation['valid'] === true && !empty($validation['ticket_id'])) {
            $candidate_id = (int) $validation['ticket_id'];
            
            // Verificar que el ticket pertenece al evento activo
            $ticket_event = (int) get_post_meta($candidate_id, '_eventosapp_ticket_evento_id', true);
            if ($ticket_event === (int) $event_id) {
                $ticket_post_id = $candidate_id;
            }
        }
    }

    // === PASO 2: Si no se encontró con el sistema nuevo, intentar con sistema LEGACY ===
    if (!$ticket_post_id) {
        // ¿El evento usa QR preimpreso?
        $use_preprinted = (get_post_meta($event_id, '_eventosapp_ticket_use_preprinted_qr', true) === '1');

        // Meta a consultar y normalización del valor escaneado
        $meta_key = $use_preprinted ? 'eventosapp_ticket_preprintedID' : 'eventosapp_ticketID';
        if ( $use_preprinted ) {
            $scan_val = preg_replace('/\D+/', '', $scanned); // solo dígitos
            if ($scan_val === '') {
                wp_send_json_error(['error' => 'QR inválido: se esperaba un número.']);
            }
        } else {
            $scan_val = $scanned;
        }

        // Busca TODOS los posibles y luego filtra por evento (evita colisiones entre eventos)
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            $meta_key, $scan_val
        ) );

        if ($ids) {
            foreach ($ids as $cand) {
                if ((int) get_post_meta($cand, '_eventosapp_ticket_evento_id', true) === (int) $event_id) {
                    $ticket_post_id = (int) $cand;
                    break;
                }
            }
        }
    }

    // === VALIDACIÓN FINAL ===
    if ( ! $ticket_post_id ) {
        wp_send_json_error(['error' => 'Ticket no encontrado para este evento.']);
    }

    // Seguridad extra (una sola vez)
    $ticket_event = (int) get_post_meta($ticket_post_id, '_eventosapp_ticket_evento_id', true);
    if ( $ticket_event !== (int) $event_id ) {
        wp_send_json_error(['error'=>'El ticket no pertenece al evento activo']);
    }

    // SOLO LECTURA: no se modifica estado alguno ni se valida contra fechas.
    $first = get_post_meta($ticket_post_id, '_eventosapp_asistente_nombre', true);
    $last  = get_post_meta($ticket_post_id, '_eventosapp_asistente_apellido', true);
    $comp  = get_post_meta($ticket_post_id, '_eventosapp_asistente_empresa', true);
    $role  = get_post_meta($ticket_post_id, '_eventosapp_asistente_cargo', true);
    $loc   = get_post_meta($ticket_post_id, '_eventosapp_asistente_localidad', true);

    wp_send_json_success([
        'full_name'   => trim($first.' '.$last),
        'company'     => $comp,
        'designation' => $role,
        'localidad'   => $loc,
        'event_name'  => get_the_title($event_id),
        'ticket_id'   => $ticket_post_id,
    ]);
});

// === Shortcode: Check-In por Sesión Interna ===
// Uso: [eventosapp_qr_sesion]
add_shortcode('eventosapp_qr_sesion', function($atts){
// 🔒 Requiere permiso para "qr" (redirige con mensaje si no puede)
if ( function_exists('eventosapp_require_feature') ) {
    eventosapp_require_feature('qr');
}


    // Evento activo
    $active_event = function_exists('eventosapp_get_active_event') ? eventosapp_get_active_event() : 0;
    if ( ! $active_event ) {
        ob_start();
        if (function_exists('eventosapp_require_active_event')) {
            eventosapp_require_active_event();
        } else {
            echo '<p>Debes seleccionar un evento activo.</p>';
        }
        return ob_get_clean();
    }

    // Sesiones del evento activo (solo nombres)
    $raw_ses = get_post_meta($active_event, '_eventosapp_sesiones_internas', true);
    if (!is_array($raw_ses)) $raw_ses = [];
    $sesiones = [];
    foreach ($raw_ses as $s) {
        if (is_array($s) && !empty($s['nombre'])) $sesiones[] = $s['nombre'];
        elseif (is_string($s) && $s !== '')       $sesiones[] = $s;
    }

    // Nonce
    $nonce = wp_create_nonce('eventosapp_qr_sesion');

    ob_start(); ?>
    <style>
      .evapp-qr-shell { max-width: 560px; margin: 0 auto; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
      .evapp-qr-card  { background:#0b1020; color:#eaf1ff; border-radius:16px; padding:18px; box-shadow:0 8px 24px rgba(0,0,0,.15); }
      .evapp-qr-title { display:flex; align-items:center; gap:.6rem; margin:0 0 10px; font-weight:700; font-size:1.05rem; letter-spacing:.2px; }
      .evapp-qr-title svg { opacity:.9 }
      .evapp-qr-field { margin-bottom:10px; }
      .evapp-qr-field label { display:block; font-size:.95rem; margin-bottom:6px; color:#c9d6ff; font-weight:600; }
      .evapp-qr-select { width:100%; padding:.6rem .7rem; border-radius:10px; border:1px solid rgba(255,255,255,.12); background:#0a0f1d; color:#eaf1ff; }

      .evapp-qr-btn { display:flex; align-items:center; justify-content:center; gap:.5rem; border:0; border-radius:12px; padding:.9rem 1.1rem; font-weight:700; cursor:pointer; width:100%; background:#4f7cff; color:#fff; transition: filter .15s ease, background .15s ease, opacity .15s; }
      .evapp-qr-btn:hover { filter:brightness(.96); }
      .evapp-qr-btn.is-live { background:#e04f5f; color:#fff; }
      .evapp-qr-btn:disabled { opacity:.6; cursor:not-allowed; }

      /* === Naranja para el botón principal del Control por Sesión === */
      .evapp-qr-sesion .evapp-qr-btn { background:#f59e0b; color:#fff; }
      .evapp-qr-sesion .evapp-qr-btn:hover { background:#d97706; }
      .evapp-qr-sesion .evapp-qr-btn:active { background:#b45309; }
      .evapp-qr-sesion .evapp-qr-btn.is-live { background:#e04f5f; color:#fff; }

      /* Botón secundario (Escanear otro QR) – mismo color que el principal */
      .evapp-qr-sesion .evapp-qr-btn-secondary{
        margin-top:12px; width:100%;
        background:#f59e0b!important;
        color:#fff; border:0; border-radius:10px; padding:.7rem 1rem; font-weight:800; cursor:pointer;
        transition: filter .15s ease;
      }
      .evapp-qr-sesion .evapp-qr-btn-secondary:hover{ filter:brightness(1.05); }

      .evapp-qr-video-wrap { position:relative; margin-top:12px; border-radius:14px; overflow:hidden; background:#0a0f1d; aspect-ratio:3/4; }
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
      .evapp-qr-grid b { color:#a7b8ff; font-weight:600; }
      @media(min-width:480px){ .evapp-qr-grid{ grid-template-columns:auto 1fr} .evapp-qr-grid div{display:contents} .evapp-qr-grid b{text-align:right} }
      .evapp-qr-help { color:#a9b6d3; font-size:.9rem; margin-top:.6rem; opacity:.8 }
      .evapp-qr-ok   { color:#7CFF8D; font-weight:800; }
      .evapp-qr-bad  { color:#ff6b6b; font-weight:700; }
      .evapp-qr-warn { color:#ffd166; font-weight:700; }

      /* Caja de sesión seleccionada */
      .evapp-sesion-box { margin-top:12px; background:#0a1329; border:1px solid rgba(255,255,255,.08); border-radius:12px; padding:12px; }
      .evapp-sesion-title { display:flex; align-items:center; justify-content:space-between; font-weight:800; letter-spacing:.3px; }
      .evapp-sesion-badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:.78rem; color:#0b1020; background:#ffd166; font-weight:900; }
      .evapp-sesion-note  { font-size:.9rem; color:#a9b6d3; margin-top:6px; }
      .evapp-sesion-actions { margin-top:10px; display:flex; gap:8px; }
      .evapp-sesion-check { background:#22c55e; color:#fff; border:0; border-radius:10px; padding:.65rem .9rem; font-weight:800; cursor:pointer; }
      .evapp-sesion-check:hover { background:#16a34a; }
      .evapp-sesion-check:active { background:#15803d; }
      .evapp-sesion-check:disabled { opacity:.6; cursor:not-allowed; }
      .evapp-hidden { display:none; }

      /* === MODO INMERSIVO DEL VISOR (auto-scroll) === */
      .evapp-qr-video-wrap.is-immersive{
        aspect-ratio:auto;
        height: calc(100vh - var(--evapp-offset, 56px));
        width: 100%;
      }
    </style>

    <div class="evapp-qr-shell evapp-qr-sesion" data-event="<?php echo esc_attr($active_event); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
      <div class="evapp-qr-card">
        <div class="evapp-qr-title">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6zm14 0h2v6h-6v-2h4v-4z" stroke="#a7b8ff"/></svg>
          Lector de QR – Control de acceso por sesión
        </div>

        <div class="evapp-qr-field">
          <label for="evappSesionSelect">Selecciona la sesión interna a controlar</label>
          <select id="evappSesionSelect" class="evapp-qr-select">
            <option value="">— Selecciona una sesión —</option>
            <?php foreach ($sesiones as $s): ?>
              <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($s); ?></option>
            <?php endforeach; ?>
          </select>
          <div class="evapp-qr-help">Esta lista se carga del evento activo.</div>
        </div>

        <div id="evappScanCard" class="<?php echo $sesiones ? '' : 'evapp-hidden'; ?>">
          <button class="evapp-qr-btn" id="evappStartScanSesion" disabled>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/><rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/></svg>
            Activar cámara y escanear
          </button>

          <div class="evapp-qr-video-wrap">
            <video id="evappVideoSesion" class="evapp-qr-video" playsinline></video>
            <div class="evapp-qr-frame" id="evappFrameSesion">
              <div class="mask"></div>
              <div class="evapp-qr-corner tl"></div>
              <div class="evapp-qr-corner tr"></div>
              <div class="evapp-qr-corner bl"></div>
              <div class="evapp-qr-corner br"></div>
            </div>
            <canvas id="evappCanvasSesion" style="display:none;"></canvas>
          </div>

          <div class="evapp-qr-result" id="evappResultSesion">
            <div class="evapp-qr-help">Selecciona una sesión y activa la cámara para escanear.</div>
          </div>
        </div>

        <?php if (!$sesiones): ?>
          <div class="evapp-qr-help">No hay sesiones internas configuradas para este evento.</div>
        <?php endif; ?>
      </div>
    </div>

<script>
(function(){
  const wrap      = document.querySelector('.evapp-qr-sesion');
  const ajaxURL   = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
  const ajaxNonce = wrap?.dataset.nonce || "";
  const eventID   = parseInt(wrap?.dataset.event || '0', 10) || 0;

  const sel   = document.getElementById('evappSesionSelect');
  const btn   = document.getElementById('evappStartScanSesion');
  const video = document.getElementById('evappVideoSesion');
  const frame = document.getElementById('evappFrameSesion');
  const cvs   = document.getElementById('evappCanvasSesion');
  const ctx   = cvs.getContext('2d');
  const out   = document.getElementById('evappResultSesion');
  const vwrap = video.closest('.evapp-qr-video-wrap') || video.parentElement;

  let selectedSession = "";
  let stream = null;
  let running = false;
  let lastScan = "";
  let lastAt   = 0;
  let lastTicketId = 0; // para el toggle posterior
  let barcodeDetector = ('BarcodeDetector' in window) ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null;

  // === Scroll helpers ===
  function getOffsetCompensation(){
    const adminBar = document.getElementById('wpadminbar');
    const adminH = adminBar ? adminBar.offsetHeight : 0;
    return adminH + 10;
  }
  function smoothScrollTo(el){
    if (!el) return;
    const offset = getOffsetCompensation();
    try { el.style.setProperty('--evapp-offset', offset + 'px'); } catch(e){}
    const y = el.getBoundingClientRect().top + window.pageYOffset - offset;
    window.scrollTo({ top: y, behavior: 'smooth' });
  }

  // Habilitar botón al elegir sesión
  sel?.addEventListener('change', ()=>{
    selectedSession = sel.value || "";
    btn.disabled = !selectedSession;
    if (!selectedSession) {
      setOutput('<div class="evapp-qr-help">Selecciona una sesión para comenzar.</div>');
      smoothScrollTo(out);
    }
  });

  function setLiveUI(on){
    if (on) {
      btn.classList.add('is-live');
      btn.innerHTML = `
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
          <path d="M6 6h12v12H6z" stroke="white"/>
        </svg>
        Detener cámara
      `;
    } else {
      btn.classList.remove('is-live');
      btn.innerHTML = `
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
          <path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/>
          <rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/>
        </svg>
        Activar cámara y escanear
      `;
    }
  }
  function beep(){
    try{ const a=new Audio(); a.src='data:audio/mp3;base64,//uQxAAAAAAAAAAAAAAAAAAAAAAAWGlinZwAAAA8AAAACAAACcQAA'; a.play().catch(()=>{});}catch(e){}
    if (navigator.vibrate) navigator.vibrate(60);
  }
  function setOutput(html){ out.innerHTML = html; }
  function row(label, value){ return `<div><b>${label}:</b></div><div>${value || '-'}</div>`; }
  function normalizeRaw(raw){
    let s = String(raw||'').trim();
    // Si es una URL completa (badge/escarapela), devolverla sin modificar
    if (s.startsWith('http://') || s.startsWith('https://')) {
      return s;
    }
    // Para otros casos, aplicar normalización
    if (s.includes('/')) s = s.split('/').pop();
    s = s.replace(/\.(png|jpg|jpeg|pdf)$/i,'').replace(/-tn$/i,'').replace(/^#/, '');
    return s;
  }
  function stop(){
    running = false;
    if (stream) stream.getTracks().forEach(t=>t.stop());
    stream = null;
    video.style.display = 'none';
    frame.style.display = 'none';
    if (vwrap){ vwrap.classList.remove('is-immersive'); }
    setLiveUI(false);
  }
  async function ensureJsQR(){
    if ('BarcodeDetector' in window) return false;
    if (!window.jsQR) {
      await new Promise((resolve)=>{
        const s = document.createElement('script');
        s.src = (window.jsqr_src || 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js');
        s.onload = resolve;
        document.head.appendChild(s);
      });
    }
    return true;
  }
  async function start(){
    if (!eventID){ setOutput('<div class="evapp-qr-bad">No hay evento activo.</div>'); smoothScrollTo(out); return; }
    if (!selectedSession){ setOutput('<div class="evapp-qr-bad">Selecciona una sesión primero.</div>'); smoothScrollTo(out); return; }
    try{
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal:'environment' } }, audio:false });
    }catch(e){
      setOutput('<div class="evapp-qr-bad">No se pudo acceder a la cámara.</div>'); smoothScrollTo(out); return;
    }
    video.srcObject = stream;
    await video.play();
    video.style.display = 'block';
    frame.style.display = 'block';

    // Modo inmersivo + scroll al visor
    if (vwrap){ vwrap.classList.add('is-immersive'); smoothScrollTo(vwrap); }

    cvs.width  = video.videoWidth  || 640;
    cvs.height = video.videoHeight || 480;
    running = true; setLiveUI(true);

    // eliminar botón secundario si existía
    const again = document.getElementById('evappScanAgainSesion');
    if (again) again.remove();

    tick();
  }
  async function tick(){
    if (!running) return;
    ctx.drawImage(video, 0, 0, cvs.width, cvs.height);
    if (barcodeDetector) {
      try {
        const bitmap = await createImageBitmap(cvs);
        const codes  = await barcodeDetector.detect(bitmap);
        if (codes && codes.length){
          const data = normalizeRaw(codes[0].rawValue || '');
          onScan(data); return;
        }
      } catch(e){}
    } else if (window.jsQR) {
      const img  = ctx.getImageData(0,0,cvs.width,cvs.height);
      const code = window.jsQR(img.data, img.width, img.height);
      if (code && code.data) { onScan(normalizeRaw(code.data)); return; }
    }
    requestAnimationFrame(tick);
  }

  function injectScanAgainButton(){
    if (document.getElementById('evappScanAgainSesion')) return;
    const againBtn = document.createElement('button');
    againBtn.id = 'evappScanAgainSesion';
    againBtn.type = 'button';
    againBtn.className = 'evapp-qr-btn-secondary';
    againBtn.textContent = 'Escanear otro QR';
    out.appendChild(againBtn);

    againBtn.addEventListener('click', async ()=>{
      // Reactivar cámara; start() valida la sesión y hace el scroll
      await ensureJsQR();
      await start();
      setOutput('<div class="evapp-qr-help">Tip: coloca el QR dentro del marco. La lectura vibra/emite sonido al capturar.</div>');
    });
  }

  function renderSesionUI(d){
    const acc = d.has_access;
    const checked = (d.session_status === 'checked_in');
    const badge = checked ? '<span class="evapp-sesion-badge">YA CHECK-IN</span>' : (acc ? '<span class="evapp-sesion-badge" style="background:#7CFF8D;color:#0a1329;">PUEDE ENTRAR</span>' : '<span class="evapp-sesion-badge" style="background:#ffb4b4;color:#0a1329;">SIN ACCESO</span>');
    let html = '';
    html += '<div class="evapp-qr-grid">';
    html += row('Nombre', d.full_name);
    html += row('Evento', d.event_name);
    html += row('Empresa', d.company);
    html += row('Cargo', d.designation);
    html += row('Localidad', d.localidad);
    html += '</div>';

    html += `
      <div class="evapp-sesion-box">
        <div class="evapp-sesion-title">
          <div>${d.session}</div>
          <div>${badge}</div>
        </div>
        <div class="evapp-sesion-note">
          ${acc ? 'Control de acceso para esta sesión.' : 'Este ticket no tiene acceso a esta sesión.'}
        </div>
        <div class="evapp-sesion-actions">
          <button id="evappSesionCheckBtn" class="evapp-sesion-check" ${(!acc || checked) ? 'disabled' : ''}>
            Hacer Check-In
          </button>
        </div>
      </div>
    `;

    setOutput(html);
    smoothScrollTo(out);
    injectScanAgainButton(); // mostrar botón debajo del resultado

    const checkBtn = document.getElementById('evappSesionCheckBtn');
    if (checkBtn) {
      checkBtn.addEventListener('click', ()=>{
        checkBtn.disabled = true;
        const fd = new FormData();
        fd.append('action','eventosapp_qr_sesion_toggle');
        fd.append('security', ajaxNonce);
        fd.append('event_id', String(eventID));
        fd.append('ticket_id', String(lastTicketId || 0));
        fd.append('session', selectedSession);

        fetch(ajaxURL, { method:'POST', body:fd, credentials:'same-origin' })
          .then(r=>r.json())
          .then(resp=>{
            if (!resp || !resp.success){
              const msg = (resp && resp.data && resp.data.error) ? resp.data.error : 'Error desconocido';
              setOutput('<div class="evapp-qr-bad">'+ msg +'</div>');
              injectScanAgainButton();
              smoothScrollTo(out);
              return;
            }
            const info = resp.data || {};
            if (info.already) {
              setOutput('<div class="evapp-qr-warn">Ese asistente ya confirmó asistencia para esta sesión.</div>');
            } else {
              setOutput('<div class="evapp-qr-ok">✔ Check-In de la sesión confirmado.</div>');
            }
            injectScanAgainButton();
            smoothScrollTo(out);
          })
          .catch(()=>{
            setOutput('<div class="evapp-qr-bad">No se pudo actualizar el estado de la sesión.</div>');
            injectScanAgainButton();
            smoothScrollTo(out);
          });
      });
    }
  }

  function onScan(data){
    const now = Date.now();
    if (data === lastScan && (now - lastAt) < 2500){ requestAnimationFrame(tick); return; }
    lastScan = data; lastAt = now; beep();
    stop(); // detenemos para operar con tranquilidad
    setOutput('<div class="evapp-qr-help">Procesando: '+ data +'…</div>');
    smoothScrollTo(out);

    const fd = new FormData();
    fd.append('action','eventosapp_qr_sesion_lookup');
    fd.append('security', ajaxNonce);
    fd.append('event_id', String(eventID));
    fd.append('scanned',  data);
    fd.append('session',  selectedSession);

    fetch(ajaxURL, { method:'POST', body:fd, credentials:'same-origin' })
      .then(r=>r.json())
      .then(resp=>{
        if (!resp || !resp.success){
          const msg = (resp && resp.data && resp.data.error) ? resp.data.error : 'Error desconocido';
          setOutput('<div class="evapp-qr-bad">'+ msg +'</div>');
          injectScanAgainButton();
          smoothScrollTo(out);
          return;
        }
        const d = resp.data || {};
        lastTicketId = d.ticket_id || 0;
        renderSesionUI(d);
      })
      .catch(()=>{
        setOutput('<div class="evapp-qr-bad">No se pudo obtener la información del asistente.</div>');
        injectScanAgainButton();
        smoothScrollTo(out);
      });
  }

  // Toggle cámara
  btn.addEventListener('click', async ()=>{
    if (!selectedSession){ setOutput('<div class="evapp-qr-bad">Selecciona una sesión primero.</div>'); smoothScrollTo(out); return; }
    if (stream && stream.active) {
      stop();
      setOutput('<div class="evapp-qr-help">Cámara detenida. Haz clic para activar y escanear.</div>');
      smoothScrollTo(out);
      return;
    }
    await ensureJsQR();
    await start();
    setOutput('<div class="evapp-qr-help">Tip: coloca el QR dentro del marco. La lectura vibra/emite sonido al capturar.</div>');
    smoothScrollTo(out);
  });
})();
</script>
    <?php
    return ob_get_clean();
});




// === AJAX: Lookup por sesión (lee QR, valida evento y acceso a sesión) ===
add_action('wp_ajax_eventosapp_qr_sesion_lookup', function(){
    if ( ! is_user_logged_in() ) wp_send_json_error(['error'=>'No autorizado']);
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('qr') ) {
        wp_send_json_error(['error'=>'Permisos insuficientes']);
    }
    check_ajax_referer('eventosapp_qr_sesion','security');

    $scanned  = isset($_POST['scanned'])  ? sanitize_text_field( wp_unslash($_POST['scanned']) ) : '';
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    $session  = isset($_POST['session'])  ? sanitize_text_field( wp_unslash($_POST['session']) ) : '';

    // 🔒 Forzar evento ACTIVO para no-admins
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $event_id !== $active ) {
            wp_send_json_error(['error'=>'Sin permisos para este evento.'], 403);
        }
    }


    if (!$scanned || !$event_id || !$session) wp_send_json_error(['error'=>'Datos incompletos']);

    global $wpdb;

    $ticket_post_id = 0;

    // === PASO 1: Intentar con el NUEVO sistema de QR (EventosApp_QR_Manager) ===
    if (class_exists('EventosApp_QR_Manager')) {
        $validation = EventosApp_QR_Manager::validate_qr($scanned);
        
        if (isset($validation['valid']) && $validation['valid'] === true && !empty($validation['ticket_id'])) {
            $candidate_id = (int) $validation['ticket_id'];
            
            // Verificar que el ticket pertenece al evento activo
            $ticket_event = (int) get_post_meta($candidate_id, '_eventosapp_ticket_evento_id', true);
            if ($ticket_event === (int) $event_id) {
                $ticket_post_id = $candidate_id;
            }
        }
    }

    // === PASO 2: Si no se encontró con el sistema nuevo, intentar con sistema LEGACY ===
    if (!$ticket_post_id) {
        // ¿Evento usa QR preimpreso?
        $use_preprinted = (get_post_meta($event_id, '_eventosapp_ticket_use_preprinted_qr', true) === '1');
        $meta_key = $use_preprinted ? 'eventosapp_ticket_preprintedID' : 'eventosapp_ticketID';
        if ($use_preprinted) {
            $scan_val = preg_replace('/\D+/', '', $scanned);
            if ($scan_val === '') wp_send_json_error(['error'=>'QR inválido: se esperaba un número.']);
        } else {
            $scan_val = $scanned;
        }

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            $meta_key, $scan_val
        ) );

        if ($ids) {
            foreach ($ids as $cand) {
                if ((int) get_post_meta($cand, '_eventosapp_ticket_evento_id', true) === (int) $event_id) {
                    $ticket_post_id = (int) $cand; break;
                }
            }
        }
    }

    // === VALIDACIÓN FINAL ===
    if ( ! $ticket_post_id ) {
        wp_send_json_error(['error'=> 'Ticket no encontrado para este evento.']);
    }

    // Seguridad extra
    if ((int) get_post_meta($ticket_post_id, '_eventosapp_ticket_evento_id', true) !== (int) $event_id) {
        wp_send_json_error(['error'=>'El ticket no pertenece al evento activo']);
    }

    // Datos
    $first = get_post_meta($ticket_post_id, '_eventosapp_asistente_nombre', true);
    $last  = get_post_meta($ticket_post_id, '_eventosapp_asistente_apellido', true);
    $comp  = get_post_meta($ticket_post_id, '_eventosapp_asistente_empresa', true);
    $role  = get_post_meta($ticket_post_id, '_eventosapp_asistente_cargo', true);
    $loc   = get_post_meta($ticket_post_id, '_eventosapp_asistente_localidad', true);

    // Acceso a sesión y estado
    $has_access = function_exists('eventosapp_ticket_tiene_acceso')
        ? eventosapp_ticket_tiene_acceso($ticket_post_id, $session)
        : false;

    $checkin_ses = get_post_meta($ticket_post_id, '_eventosapp_ticket_checkin_sesiones', true);
    if (!is_array($checkin_ses)) $checkin_ses = [];
    $status = isset($checkin_ses[$session]) ? $checkin_ses[$session] : 'not_checked_in';

    wp_send_json_success([
        'ticket_id'       => $ticket_post_id,
        'full_name'       => trim($first.' '.$last),
        'company'         => $comp,
        'designation'     => $role,
        'localidad'       => $loc,
        'event_name'      => get_the_title($event_id),
        'session'         => $session,
        'has_access'      => (bool) $has_access,
        'session_status'  => $status,
        'session_status_label' => ($status==='checked_in' ? 'SI' : 'NO'),
    ]);
});


// === AJAX: Toggle (solo marca "checked_in" para la sesión; si ya estaba, avisa) ===
add_action('wp_ajax_eventosapp_qr_sesion_toggle', function(){
// 🔒 Seguridad por rol (feature: qr)
if ( ! is_user_logged_in() ) {
    wp_send_json_error(['error' => 'Debes iniciar sesión.'], 401);
}
if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('qr') ) {
    wp_send_json_error(['error' => 'Permisos insuficientes para Check-In con QR.'], 403);
}
    check_ajax_referer('eventosapp_qr_sesion','security');

    $ticket_id = isset($_POST['ticket_id']) ? absint($_POST['ticket_id']) : 0;
    $event_id  = isset($_POST['event_id'])  ? absint($_POST['event_id'])  : 0;
    $session   = isset($_POST['session'])   ? sanitize_text_field( wp_unslash($_POST['session']) ) : '';

    // 🔒 Forzar evento ACTIVO para no-admins
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $event_id !== $active ) {
            wp_send_json_error(['error' => 'Sin permisos para este evento.'], 403);
        }
    }


    if (!$ticket_id || !$event_id || !$session) wp_send_json_error(['error'=>'Datos incompletos']);

    // Verificar pertenencia
    $ticket_event = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
    if ($ticket_event !== (int) $event_id) wp_send_json_error(['error'=>'El ticket no pertenece al evento activo']);

    // ¿Tiene acceso a la sesión?
    $has_access = function_exists('eventosapp_ticket_tiene_acceso')
        ? eventosapp_ticket_tiene_acceso($ticket_id, $session)
        : false;
    if (!$has_access) wp_send_json_error(['error'=>'El ticket no tiene acceso a esta sesión.']);

    // Estado actual
    $checkin_ses = get_post_meta($ticket_id, '_eventosapp_ticket_checkin_sesiones', true);
    if (!is_array($checkin_ses)) $checkin_ses = [];
    $already = (isset($checkin_ses[$session]) && $checkin_ses[$session] === 'checked_in');

    if ($already) {
        wp_send_json_success(['already'=>true]);
    }

    // Marcar como checked_in (idempotente)
    $checkin_ses[$session] = 'checked_in';
    update_post_meta($ticket_id, '_eventosapp_ticket_checkin_sesiones', $checkin_ses);

    // Log (reutilizamos _eventosapp_checkin_log con tipo 'session_checked_in')
    $log = get_post_meta($ticket_id, '_eventosapp_checkin_log', true);
    if (is_string($log)) $log = @unserialize($log);
    if (!is_array($log)) $log = [];

    try { $dt = new DateTime('now', wp_timezone()); } catch(Exception $e){ $dt = new DateTime('now'); }
    $u = wp_get_current_user();
    $usuario = ($u && $u->exists()) ? ($u->display_name.' ('.$u->user_email.')') : 'Sistema';

    $log[] = [
        'fecha'   => $dt->format('Y-m-d'),
        'hora'    => $dt->format('H:i:s'),
        'status'  => 'session_checked_in',
        'sesion'  => $session,
        'usuario' => $usuario,
        'origen'  => 'qr_sesion',
    ];
    update_post_meta($ticket_id, '_eventosapp_checkin_log', $log);

    wp_send_json_success(['already'=>false, 'now'=>'checked_in']);
});


/* ===========================================================
 * Shortcode público: [eventosapp_qr_contacto]
 * - Página pública (no requiere login ni evento activo)
 * - Lee QR (BarcodeDetector con fallback a jsQR)
 * - Muestra: Nombre y Apellido, Empresa, Cargo
 * - Botón "Agregar contacto" -> descarga .vcf (vCard 3.0)
 * =========================================================== */

add_shortcode('eventosapp_qr_contacto', function($atts){

    // Registrar jsQR (fallback) sólo si hace falta
    add_action('wp_enqueue_scripts', function(){
        if (!wp_script_is('jsqr', 'registered')) {
            wp_register_script('jsqr', 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js', [], null, true);
        }
    });

    $nonce = wp_create_nonce('eventosapp_qr_contacto');

    ob_start(); ?>
    <style>
      .evapp-qr-shell { max-width:560px; margin:0 auto; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
      .evapp-qr-card { background:#0b1020; color:#eaf1ff; border-radius:16px; padding:18px; box-shadow:0 8px 24px rgba(0,0,0,.15); }
      .evapp-qr-title { display:flex; align-items:center; gap:.6rem; margin:0 0 10px; font-weight:800; font-size:1.05rem; letter-spacing:.2px; }
      .evapp-qr-title svg { opacity:.9 }
      .evapp-qr-btn { display:flex; align-items:center; justify-content:center; gap:.5rem; border:0; border-radius:12px; padding:.9rem 1.1rem; font-weight:800; cursor:pointer; width:100%; background:#2563eb; color:#fff; transition:filter .15s,background .15s; }
      .evapp-qr-btn.is-live { background:#e04f5f; }
      .evapp-qr-btn:hover { filter:brightness(.98); }
      .evapp-qr-video-wrap { position:relative; margin-top:12px; border-radius:14px; overflow:hidden; background:#0a0f1d; aspect-ratio:3/4; }
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
      .evapp-qr-help{ color:#a9b6d3; font-size:.9rem; opacity:.85 }
      .evapp-contact-btn{ margin-top:12px; width:100%; display:flex; gap:.5rem; align-items:center; justify-content:center; border:0; border-radius:10px; padding:.8rem 1rem; font-weight:800; background:#16a34a; color:#fff; cursor:pointer; }
      .evapp-contact-btn:hover{ filter:brightness(1.05); }
      .evapp-qr-bad{ color:#ff6b6b; font-weight:700; }
      .evapp-qr-video-wrap.is-immersive{ aspect-ratio:auto; height: calc(100vh - var(--evapp-offset, 56px)); width:100%; }
    </style>

    <div class="evapp-qr-shell" data-nonce="<?php echo esc_attr($nonce); ?>">
      <div class="evapp-qr-card">
        <div class="evapp-qr-title">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6zm14 0h2v6h-6v-2h4v-4z" stroke="#a7b8ff"/></svg>
          Lector de QR – Tarjeta de contacto
        </div>

        <button class="evapp-qr-btn" id="evappStartScanContact">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/><rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/></svg>
          Activar cámara y escanear
        </button>

        <div class="evapp-qr-video-wrap">
          <video id="evappVideoContact" class="evapp-qr-video" playsinline></video>
          <div class="evapp-qr-frame" id="evappFrameContact">
            <div class="mask"></div>
            <div class="evapp-qr-corner tl"></div>
            <div class="evapp-qr-corner tr"></div>
            <div class="evapp-qr-corner bl"></div>
            <div class="evapp-qr-corner br"></div>
          </div>
          <canvas id="evappCanvasContact" style="display:none;"></canvas>
        </div>

        <div class="evapp-qr-result" id="evappResultContact">
          <div class="evapp-qr-help">Escanea el QR de una escarapela para ver los datos del asistente y agregarlo como contacto.</div>
        </div>
      </div>
    </div>

    <script>
    (function(){
      const ajaxURL = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
      const nonce   = document.querySelector('.evapp-qr-shell')?.dataset.nonce || "";
      const btn   = document.getElementById('evappStartScanContact');
      const video = document.getElementById('evappVideoContact');
      const frame = document.getElementById('evappFrameContact');
      const cvs   = document.getElementById('evappCanvasContact');
      const ctx   = cvs.getContext('2d');
      const out   = document.getElementById('evappResultContact');
      const vwrap = video.closest('.evapp-qr-video-wrap') || video.parentElement;

      let stream=null, running=false, lastScan="", lastAt=0;
      let barcodeDetector = ('BarcodeDetector' in window) ? new window.BarcodeDetector({formats:['qr_code']}) : null;
      let lastData = null; // para construir VCF

      // Helpers de UI/scroll
      function getOffset(){ const ab=document.getElementById('wpadminbar'); return (ab?ab.offsetHeight:0) + 10; }
      function smoothScrollTo(el){ if(!el) return; const off=getOffset(); try{el.style.setProperty('--evapp-offset',off+'px')}catch(e){} const y=el.getBoundingClientRect().top + window.pageYOffset - off; window.scrollTo({top:y,behavior:'smooth'}); }
      function setLiveUI(on){
        if(on){ btn.classList.add('is-live'); btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6h12v12H6z" stroke="white"/></svg> Detener cámara'; }
        else { btn.classList.remove('is-live'); btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/><rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/></svg> Activar cámara y escanear'; }
      }
      function beep(){ try{const a=new Audio(); a.src='data:audio/mp3;base64,//uQxAAAAAAAAAAAAAAAAAAAAAAAWGlinZwAAAA8AAAACAAACcQAA'; a.play().catch(()=>{});}catch(e){} if(navigator.vibrate) navigator.vibrate(60); }
      function setOutput(html){ out.innerHTML = html; }
      function row(label,value){ return `<div><b>${label}:</b></div><div>${value || '-'}</div>`; }
      function normalizeRaw(raw){ 
        let s=String(raw||'').trim(); 
        // Si es una URL completa (badge/escarapela), devolverla sin modificar
        if(s.startsWith('http://') || s.startsWith('https://')) return s;
        // Para otros casos, aplicar normalización
        if(s.includes('/')) s = s.split('/').pop(); 
        s = s.replace(/\.(png|jpg|jpeg|pdf)$/i,'').replace(/-tn$/i,'').replace(/^#/,''); 
        return s; 
      }

      function stop(){
        running=false;
        if(stream) stream.getTracks().forEach(t=>t.stop());
        stream=null;
        video.style.display='none';
        frame.style.display='none';
        vwrap?.classList.remove('is-immersive');
        setLiveUI(false);
      }
      async function ensureJsQR(){
        if('BarcodeDetector' in window) return false;
        if(!window.jsQR){
          await new Promise((resolve)=>{ const s=document.createElement('script'); s.src='https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js'; s.onload=resolve; document.head.appendChild(s); });
        }
        return true;
      }
      async function start(){
        try{
          stream = await navigator.mediaDevices.getUserMedia({ video:{ facingMode:{ideal:'environment'} }, audio:false });
        }catch(e){
          setOutput('<div class="evapp-qr-bad">No se pudo acceder a la cámara.</div>');
          smoothScrollTo(out);
          return;
        }
        video.srcObject = stream;
        await video.play();
        video.style.display='block';
        frame.style.display='block';
        vwrap?.classList.add('is-immersive'); smoothScrollTo(vwrap);
        cvs.width = video.videoWidth || 640;
        cvs.height= video.videoHeight|| 480;
        running=true; setLiveUI(true); tick();
      }
      async function tick(){
        if(!running) return;
        ctx.drawImage(video,0,0,cvs.width,cvs.height);
        if(barcodeDetector){
          try{
            const bmp = await createImageBitmap(cvs);
            const codes = await barcodeDetector.detect(bmp);
            if(codes && codes.length){ onScan(normalizeRaw(codes[0].rawValue||'')); return; }
          }catch(e){}
        }else if(window.jsQR){
          const img = ctx.getImageData(0,0,cvs.width,cvs.height);
          const code= window.jsQR(img.data,img.width,img.height);
          if(code && code.data){ onScan(normalizeRaw(code.data)); return; }
        }
        requestAnimationFrame(tick);
      }
      function onScan(data){
        const now = Date.now();
        if(data===lastScan && (now-lastAt)<2500){ requestAnimationFrame(tick); return; }
        lastScan=data; lastAt=now; beep(); stop();
        setOutput('<div class="evapp-qr-help">Procesando…</div>'); smoothScrollTo(out);

        const fd = new FormData();
        fd.append('action','eventosapp_qr_contact_lookup');
        fd.append('security', nonce);
        fd.append('scanned', data);

        fetch(ajaxURL, { method:'POST', body:fd, credentials:'same-origin' })
          .then(r=>r.json())
          .then(resp=>{
            if(!resp || !resp.success){
              const msg = (resp && resp.data && resp.data.error) ? resp.data.error : 'No se encontró el asistente.';
              setOutput('<div class="evapp-qr-bad">'+msg+'</div>'); return;
            }
            const d = resp.data || {};
            lastData = d;

            let html = '<div class="evapp-qr-grid">';
            html += row('Nombre', d.full_name);
            html += row('Empresa', d.company);
            html += row('Cargo', d.designation);
            html += '</div>';

            html += `<button class="evapp-contact-btn" id="evappDownloadVCF" type="button" aria-label="Agregar contacto">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                <path d="M12 5v8m0 0l3-3m-3 3L9 10M4 19h16" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              Agregar contacto
            </button>`;

            setOutput(html); smoothScrollTo(out);

            const btnVcf = document.getElementById('evappDownloadVCF');
            if(btnVcf){
              btnVcf.addEventListener('click', ()=>{
                const vcf = buildVCF(d);
                const blob = new Blob([vcf], { type: 'text/vcard;charset=utf-8' });
                const a = document.createElement('a');
                const fn = (d.full_name || 'contacto').replace(/[^\w\s\-\.]+/g,'').trim() || 'contacto';
                a.href = URL.createObjectURL(blob);
                a.download = fn + '.vcf';
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(()=>URL.revokeObjectURL(a.href), 1500);
              });
            }
          })
          .catch(()=>{
            setOutput('<div class="evapp-qr-bad">Error de red al consultar el contacto.</div>');
          });
      }

      // Construye VCF 3.0 con saltos CRLF y escapado correcto
      function buildVCF(d){
        function esc(v){ return String(v||'').replace(/\\/g,'\\\\').replace(/\n/g,'\\n').replace(/,/g,'\\,').replace(/;/g,'\\;'); }
        const first = (d.first_name || '').trim();
        const last  = (d.last_name  || '').trim();
        const fn    = (d.full_name  || (first + (last?' '+last:''))).trim();
        const org   = esc(d.company || '');
        const title = esc(d.designation || '');
        const email = (d.email || '').trim();
        const phone = (d.phone || '').trim();

        const CRLF = '\r\n';
        let v = '';
        v += 'BEGIN:VCARD'+CRLF;
        v += 'VERSION:3.0'+CRLF;
        v += 'N:'+esc(last)+';'+esc(first)+';;;'+CRLF;
        v += 'FN:'+esc(fn)+CRLF;
        v += 'X-ABShowAs:Person'+CRLF;
        if(org)   v += 'ORG:'+org+CRLF;
        if(title) v += 'TITLE:'+title+CRLF;
        if(email) v += 'EMAIL;TYPE=INTERNET:'+email+CRLF;
        if(phone) v += 'TEL;TYPE=CELL:'+phone+CRLF;
        v += 'END:VCARD'+CRLF;
        return v;
      }

      // Botón principal: toggle cámara
      btn.addEventListener('click', async ()=>{
        if(stream && stream.active){
          stop();
          setOutput('<div class="evapp-qr-help">Cámara detenida. Haz clic para volver a escanear.</div>');
          smoothScrollTo(out);
          return;
        }
        await ensureJsQR();
        await start();
        setOutput('<div class="evapp-qr-help">Apunta al QR de la escarapela.</div>');
      });
    })();
    </script>
    <?php
    return ob_get_clean();
});


/* ===========================================================
 * AJAX público: eventosapp_qr_contact_lookup
 * - Busca ticket por meta 'eventosapp_ticketID' o 'eventosapp_ticket_preprintedID'
 * - Devuelve datos de contacto (sin requerir sesión)
 * =========================================================== */
add_action('wp_ajax_nopriv_eventosapp_qr_contact_lookup', 'eventosapp_qr_contact_lookup_cb');
add_action('wp_ajax_eventosapp_qr_contact_lookup',       'eventosapp_qr_contact_lookup_cb');

function eventosapp_qr_contact_lookup_cb(){
    check_ajax_referer('eventosapp_qr_contacto','security');

    $raw = isset($_POST['scanned']) ? wp_unslash($_POST['scanned']) : '';
    $raw = sanitize_text_field($raw);
    if (!$raw) wp_send_json_error(['error'=>'QR vacío o inválido.']);

    // Normalización suave por si llega con path o extensión
    $scanned = trim($raw);
    if (strpos($scanned, '/') !== false) {
        $parts = explode('/', $scanned);
        $scanned = end($parts);
    }
    $scanned = preg_replace('/\.(png|jpg|jpeg|pdf)$/i','', $scanned);
    $scanned = preg_replace('/-tn$/i','', $scanned);
    $scanned = ltrim($scanned, '#');

    global $wpdb;

    $ticket_post_id = 0;

    // 1) Intento SIEMPRE permitido: buscar por ID público del sistema
    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s LIMIT 1",
        'eventosapp_ticketID', $scanned
    ) );
    if ($ids && !empty($ids[0])) {
        $ticket_post_id = (int) $ids[0];
    }

    // 2) Si no hay match y el código es numérico, intentar PREIMPRESO
    //    PERO solo aceptar si el evento del ticket tiene habilitado:
    //    _eventosapp_ticket_use_preprinted_qr_networking = '1'
    if (!$ticket_post_id) {
        $scan_num = preg_replace('/\D+/', '', $scanned);
        if ($scan_num !== '') {
            $ids2 = $wpdb->get_col( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s",
                'eventosapp_ticket_preprintedID', $scan_num
            ) );

            if ($ids2) {
                foreach ($ids2 as $cand) {
                    $event_id = (int) get_post_meta($cand, '_eventosapp_ticket_evento_id', true);
                    $net_on   = (get_post_meta($event_id, '_eventosapp_ticket_use_preprinted_qr_networking', true) === '1');
                    if ($net_on) { $ticket_post_id = (int) $cand; break; }
                }
            }
        }
    }

    if (!$ticket_post_id) {
        wp_send_json_error(['error'=>'No se encontró el asistente para ese QR.']);
    }

    // Metas de contacto (igual que antes)
    $first = get_post_meta($ticket_post_id, '_eventosapp_asistente_nombre', true);
    $last  = get_post_meta($ticket_post_id, '_eventosapp_asistente_apellido', true);
    $comp  = get_post_meta($ticket_post_id, '_eventosapp_asistente_empresa', true);
    $role  = get_post_meta($ticket_post_id, '_eventosapp_asistente_cargo', true);

    $email = get_post_meta($ticket_post_id, '_eventosapp_asistente_email', true);
    if (!$email) $email = get_post_meta($ticket_post_id, '_eventosapp_asistente_correo', true);

    $phone = get_post_meta($ticket_post_id, '_eventosapp_asistente_tel', true);
    if (!$phone) $phone = get_post_meta($ticket_post_id, '_eventosapp_asistente_telefono', true);
    if (!$phone) $phone = get_post_meta($ticket_post_id, '_eventosapp_asistente_cel', true);
    if (!$phone) $phone = get_post_meta($ticket_post_id, '_eventosapp_asistente_celular', true);

    wp_send_json_success([
        'ticket_id'   => $ticket_post_id,
        'first_name'  => $first,
        'last_name'   => $last,
        'full_name'   => trim($first.' '.$last),
        'company'     => $comp,
        'designation' => $role,
        'email'       => $email,
        'phone'       => $phone,
    ]);
}
