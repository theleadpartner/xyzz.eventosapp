<?php
/**
 * EventosApp – QR Check-In con Doble Autenticación
 * Shortcode: [qr_checkin_doble_auth]
 * 
 * Flujo:
 * 1. Escanea el QR del ticket
 * 2. Muestra info del ticket y solicita código de 5 dígitos
 * 3. Valida el código ingresado
 * 4. Si es correcto, hace check-in (mismo sistema que QR check-in normal)
 * 
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ========================================
// SHORTCODE: QR Check-In con Doble Autenticación
// ========================================

add_shortcode( 'qr_checkin_doble_auth', function( $atts ) {
    // 🔒 Requiere permiso para "qr_double_auth"
    if ( function_exists( 'eventosapp_require_feature' ) ) {
        eventosapp_require_feature( 'qr_double_auth' );
    }
    
    // Debe existir un evento activo
    $active_event = function_exists( 'eventosapp_get_active_event' ) ? eventosapp_get_active_event() : 0;
    if ( ! $active_event ) {
        ob_start();
        if ( function_exists( 'eventosapp_require_active_event' ) ) {
            eventosapp_require_active_event();
        } else {
            echo '<p>Debes seleccionar un evento activo.</p>';
        }
        return ob_get_clean();
    }
    
    // Verificar si el evento tiene doble autenticación activada
    $double_auth_enabled = get_post_meta( $active_event, '_eventosapp_ticket_double_auth_enabled', true );
    if ( $double_auth_enabled !== '1' ) {
        return '<div style="padding:20px;background:#fee;border:1px solid #fcc;border-radius:8px;color:#c33;">⚠️ Este evento no tiene activada la Doble Autenticación. Por favor actívala en la configuración del evento.</div>';
    }
    
    // Registrar jsQR para fallback
    add_action( 'wp_enqueue_scripts', function() {
        if ( ! wp_script_is( 'jsqr', 'registered' ) ) {
            wp_register_script( 'jsqr', 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js', [], null, true );
        }
    });
    
    // Nonces para AJAX
    $nonce_search = wp_create_nonce( 'eventosapp_qr_search' );
    $nonce_verify = wp_create_nonce( 'eventosapp_verify_checkin' );
    
    ob_start();
    ?>
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
    .evapp-qr-btn.is-live { background:#e04f5f; color:#fff; }

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

    .evapp-qr-video-wrap.is-immersive{
      aspect-ratio:auto;
      height: calc(100vh - var(--evapp-offset, 56px));
      width: 100%;
    }
    
    /* === FORMULARIO DE CÓDIGO === */
    .evapp-auth-form {
      margin-top: 16px;
      padding: 16px;
      background: #1a2332;
      border-radius: 12px;
      border: 2px solid #4f7cff;
    }
    .evapp-auth-label {
      display: block;
      color: #a7b8ff;
      font-weight: 600;
      margin-bottom: 8px;
      font-size: 0.95rem;
    }
    .evapp-auth-input {
      width: 100%;
      padding: 12px;
      font-size: 24px;
      font-weight: 700;
      text-align: center;
      letter-spacing: 8px;
      font-family: monospace;
      border: 2px solid #4f7cff;
      border-radius: 8px;
      background: #0b1020;
      color: #eaf1ff;
      margin-bottom: 12px;
    }
    .evapp-auth-input:focus {
      outline: none;
      border-color: #7CFF8D;
      box-shadow: 0 0 0 3px rgba(124,255,141,0.2);
    }
    .evapp-auth-buttons {
      display: flex;
      gap: 10px;
    }
    .evapp-auth-btn-verify {
      flex: 1;
      padding: 12px;
      border: 0;
      border-radius: 8px;
      background: #28a745;
      color: white;
      font-weight: 700;
      cursor: pointer;
      transition: background .15s ease;
    }
    .evapp-auth-btn-verify:hover {
      background: #218838;
    }
    .evapp-auth-btn-verify:disabled {
      background: #6c757d;
      cursor: not-allowed;
    }
    .evapp-auth-btn-cancel {
      padding: 12px 20px;
      border: 0;
      border-radius: 8px;
      background: #6c757d;
      color: white;
      font-weight: 700;
      cursor: pointer;
      transition: background .15s ease;
    }
    .evapp-auth-btn-cancel:hover {
      background: #5a6268;
    }
    </style>

    <div class="evapp-qr-shell" data-event="<?php echo esc_attr( $active_event ); ?>">
      <div class="evapp-qr-card">
        <div class="evapp-qr-title">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
            <path d="M12 2L4 6v6c0 5.5 3.8 10.7 8 12 4.2-1.3 8-6.5 8-12V6l-8-4Z" fill="none" stroke="#a7b8ff" stroke-width="2"/>
            <path d="M9 12l2 2 4-4" fill="none" stroke="#a7b8ff" stroke-width="2" stroke-linecap="round"/>
          </svg>
          🔐 Check-In con QR y Doble Autenticación
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
  const ajaxURL = "<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>";
  const nonceSearch = "<?php echo esc_js( $nonce_search ); ?>";
  const nonceVerify = "<?php echo esc_js( $nonce_verify ); ?>";
  const eventID = parseInt(document.querySelector('.evapp-qr-shell').dataset.event,10)||0;

  const btn   = document.getElementById('evappStartScan');
  const video = document.getElementById('evappVideo');
  const frame = document.getElementById('evappFrame');
  const cvs   = document.getElementById('evappCanvas');
  const ctx   = cvs.getContext('2d');
  const out   = document.getElementById('evappResult');
  const vwrap = video.closest('.evapp-qr-video-wrap') || video.parentElement;

  let stream = null;
  let running = false;
  let lastScan = "";
  let lastAt   = 0;
  let currentTicketData = null;
  let barcodeDetector = ('BarcodeDetector' in window) ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null;

  // === Helpers ===
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
        s.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';
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
    const againBtn = document.createElement('button');
    againBtn.id = 'evappScanAnother';
    againBtn.type = 'button';
    againBtn.className = 'evapp-qr-btn-secondary';
    againBtn.textContent = 'Escanear otro QR';
    out.appendChild(againBtn);

    againBtn.addEventListener('click', async ()=>{
      smoothScrollTo(vwrap || btn);
      await ensureJsQR();
      await start();
      setOutput('<div class="evapp-qr-help">Tip: coloca el QR dentro del marco. La lectura vibra/emite sonido al capturar.</div>');
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
    stop();

    setOutput('<div class="evapp-qr-help">Procesando: '+ data +'…</div>');
    smoothScrollTo(out);

    // Fase 1: Buscar el ticket
    const fd = new FormData();
    fd.append('action','eventosapp_search_ticket_by_qr');
    fd.append('nonce', nonceSearch);
    fd.append('event_id', String(eventID));
    fd.append('qr_code', data);

    fetch(ajaxURL, { method:'POST', body:fd, credentials:'same-origin' })
      .then(r=>r.json())
      .then(resp=>{
        if (!resp || !resp.success){
          const msg = (resp && resp.data) ? resp.data : 'Ticket no encontrado';
          setOutput('<div class="evapp-qr-bad">'+ msg +'</div>');
          injectScanAgainButton();
          smoothScrollTo(out);
          return;
        }
        
        // Ticket encontrado
        const ticket = resp.data.ticket;
        currentTicketData = ticket;
        
        // Verificar si ya hizo check-in HOY
        if (ticket.checked_in) {
          let html = '<span class="evapp-qr-warn">⚠️ Este ticket ya hizo check-in hoy</span>';
          html += '<div class="evapp-qr-grid">';
          html += row('Nombre', ticket.nombre);
          html += row('Email', ticket.email);
          html += row('Ticket ID', ticket.ticket_id);
          if (ticket.qr_type_label) html += row('Medio QR', ticket.qr_type_label);
          html += row('Localidad', ticket.localidad);
          html += row('Check-in realizado', ticket.checkin_date);
          html += '</div>';
          setOutput(html);
          injectScanAgainButton();
          smoothScrollTo(out);
          return;
        }
        
        // Mostrar formulario de código
        showAuthForm(ticket);
      })
      .catch(()=>{
        setOutput('<div class="evapp-qr-bad">Error de conexión al buscar el ticket.</div>');
        injectScanAgainButton();
        smoothScrollTo(out);
      });
  }

  function showAuthForm(ticket){
    let html = '<div class="evapp-qr-ok">✔ Ticket encontrado</div>';
    html += '<div class="evapp-qr-grid">';
    html += row('Nombre', ticket.nombre);
    html += row('Email', ticket.email);
    html += row('Ticket ID', ticket.ticket_id);
    if (ticket.qr_type_label) html += row('Medio QR', ticket.qr_type_label);
    html += row('Localidad', ticket.localidad);
    html += '</div>';
    
    html += '<div class="evapp-auth-form">';
    html += '<label class="evapp-auth-label">🔐 Solicita al asistente su código de verificación:</label>';
    html += '<input type="text" id="evappAuthCode" class="evapp-auth-input" placeholder="00000" maxlength="5" inputmode="numeric" pattern="[0-9]*">';
    html += '<div class="evapp-auth-buttons">';
    html += '<button type="button" id="evappVerifyBtn" class="evapp-auth-btn-verify">Verificar y Aprobar Check-In</button>';
    html += '<button type="button" id="evappCancelBtn" class="evapp-auth-btn-cancel">Cancelar</button>';
    html += '</div>';
    html += '</div>';
    
    setOutput(html);
    smoothScrollTo(out);
    
    // Agregar eventos
    const input = document.getElementById('evappAuthCode');
    const verifyBtn = document.getElementById('evappVerifyBtn');
    const cancelBtn = document.getElementById('evappCancelBtn');
    
    // Auto-focus en el input
    setTimeout(() => input.focus(), 100);
    
    // Solo permitir números
    input.addEventListener('input', (e) => {
      e.target.value = e.target.value.replace(/[^0-9]/g, '');
    });
    
    // Verificar al presionar Enter
    input.addEventListener('keypress', (e) => {
      if (e.key === 'Enter' && input.value.length === 5) {
        verifyBtn.click();
      }
    });
    
    // Botón Verificar
    verifyBtn.addEventListener('click', () => {
      const code = input.value.trim();
      if (code.length !== 5) {
        alert('Por favor ingresa un código de 5 dígitos');
        return;
      }
      verifyAndCheckin(ticket.id, code, ticket.qr_type || '', ticket.qr_type_label || '');
    });
    
    // Botón Cancelar
    cancelBtn.addEventListener('click', () => {
      setOutput('<div class="evapp-qr-help">Operación cancelada.</div>');
      injectScanAgainButton();
      smoothScrollTo(out);
    });
  }

  function verifyAndCheckin(ticketId, code, qrType, qrTypeLabel){
    setOutput('<div class="evapp-qr-help">Verificando código...</div>');
    smoothScrollTo(out);
    
    const fd = new FormData();
    fd.append('action','eventosapp_verify_and_checkin');
    fd.append('nonce', nonceVerify);
    fd.append('event_id', String(eventID));
    fd.append('ticket_id', String(ticketId));
    fd.append('auth_code', code);
    fd.append('qr_type', qrType || '');
    fd.append('qr_type_label', qrTypeLabel || '');

    fetch(ajaxURL, { method:'POST', body:fd, credentials:'same-origin' })
      .then(r=>r.json())
      .then(resp=>{
        if (!resp || !resp.success){
          const msg = (resp && resp.data) ? resp.data : 'Código incorrecto';
          setOutput('<div class="evapp-qr-bad">❌ ' + msg + '</div>');
          injectScanAgainButton();
          smoothScrollTo(out);
          return;
        }
        
        // Check-in exitoso
        const d = resp.data || {};
        const statusHtml = d.already_checked
          ? '<span class="evapp-qr-warn">✔ Check-in confirmado (ya había ingresado hoy)</span>'
          : '<span class="evapp-qr-ok">✅ Check-in exitoso</span>';
        
        let html = statusHtml + '<div class="evapp-qr-grid">';
        html += row('Mensaje', d.message);
        if (d.qr_type_label) html += row('Medio QR', d.qr_type_label);
        html += row('Fecha del check-in', d.checkin_date_label || d.checkin_date);
        html += '</div>';
        setOutput(html);
        injectScanAgainButton();
        smoothScrollTo(out);
      })
      .catch(()=>{
        setOutput('<div class="evapp-qr-bad">Error de conexión al verificar el código.</div>');
        injectScanAgainButton();
        smoothScrollTo(out);
      });
  }

  // Botón toggle: enciende/apaga la cámara
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

// ========================================
// AJAX: Buscar ticket por QR
// ========================================

add_action( 'wp_ajax_eventosapp_search_ticket_by_qr', 'eventosapp_ajax_search_ticket_by_qr' );
add_action( 'wp_ajax_nopriv_eventosapp_search_ticket_by_qr', 'eventosapp_ajax_search_ticket_by_qr' );

function eventosapp_ajax_search_ticket_by_qr() {
    check_ajax_referer( 'eventosapp_qr_search', 'nonce' );
    
    $qr_code  = isset( $_POST['qr_code'] ) ? sanitize_text_field( $_POST['qr_code'] ) : '';
    $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
    
    if ( ! $qr_code || ! $event_id ) {
        wp_send_json_error( 'Datos incompletos' );
    }
    
if ( ! $qr_code || ! $event_id ) {
        wp_send_json_error( 'Datos incompletos' );
    }
    
    $ticket_id = 0;
    $qr_type = 'legacy';
    $qr_type_label = 'QR Legacy';
    
    // === PASO 1: Intentar con el NUEVO sistema simplificado (EventosApp_QR_Manager) ===
    if ( class_exists( 'EventosApp_QR_Manager' ) ) {
        $validation = EventosApp_QR_Manager::validate_qr( $qr_code );
        
        if ( isset( $validation['valid'] ) && $validation['valid'] === true && ! empty( $validation['ticket_id'] ) ) {
            $candidate_id = (int) $validation['ticket_id'];
            
            // Verificar que el ticket pertenece al evento activo
            $ticket_event = (int) get_post_meta( $candidate_id, '_eventosapp_ticket_evento_id', true );
            if ( $ticket_event === (int) $event_id ) {
                $ticket_id = $candidate_id;
                $qr_type = isset( $validation['type'] ) ? sanitize_key( $validation['type'] ) : 'unknown';
                $qr_type_label = isset( $validation['type_label'] ) ? sanitize_text_field( $validation['type_label'] ) : $qr_type;
            }
        }
    }
    
    // === PASO 2: Si no se encontró con el sistema nuevo, intentar con sistema LEGACY ===
    if ( ! $ticket_id ) {
        // Verificar si el evento usa QR preimpreso
        $use_preprinted = get_post_meta( $event_id, '_eventosapp_ticket_use_preprinted_qr', true ) === '1';
        $meta_key = $use_preprinted ? 'eventosapp_ticket_preprintedID' : 'eventosapp_ticketID';
        $qr_type = $use_preprinted ? 'preprinted' : 'legacy';
        $qr_type_label = $use_preprinted ? 'QR Preimpreso' : 'QR Legacy';
        
        // Normalizar valor según el tipo
        if ( $use_preprinted ) {
            $qr_code = preg_replace( '/\D+/', '', $qr_code );
        }
        
        // Buscar ticket
        $tickets = get_posts([
            'post_type'      => 'eventosapp_ticket',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => $meta_key,
                    'value' => $qr_code,
                ],
                [
                    'key'   => '_eventosapp_ticket_evento_id',
                    'value' => $event_id,
                ],
            ],
        ]);
        
        if ( ! empty( $tickets ) ) {
            $ticket_id = $tickets[0]->ID;
        }
    }
    
    // === VALIDACIÓN FINAL ===
    if ( ! $ticket_id ) {
        wp_send_json_error( 'Ticket no encontrado o no pertenece a este evento' );
    }
    
    // === VALIDACIÓN FINAL ===
    if ( ! $ticket_id ) {
        wp_send_json_error( 'Ticket no encontrado o no pertenece a este evento' );
    }
    
    // Obtener zona horaria del evento para determinar "hoy"
    $event_tz = get_post_meta( $event_id, '_eventosapp_zona_horaria', true );
    if ( ! $event_tz ) {
        $event_tz = wp_timezone_string();
        if ( ! $event_tz || $event_tz === 'UTC' ) {
            $offset = get_option( 'gmt_offset' );
            $event_tz = $offset ? timezone_name_from_abbr( '', $offset * 3600, 0 ) ?: 'UTC' : 'UTC';
        }
    }
    
    try {
        $dt = new DateTime( 'now', new DateTimeZone( $event_tz ) );
    } catch ( Exception $e ) {
        $dt = new DateTime( 'now', wp_timezone() );
    }
    $today = $dt->format( 'Y-m-d' );
    
    // Obtener datos del asistente
    $nombre       = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
    $apellido     = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );
    $email        = get_post_meta( $ticket_id, '_eventosapp_asistente_email', true );
    $localidad    = get_post_meta( $ticket_id, '_eventosapp_asistente_localidad', true );
    $ticket_public_id = get_post_meta( $ticket_id, 'eventosapp_ticketID', true );
    
    // Verificar estado de check-in usando el sistema correcto (por día)
    $status_arr = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
    if ( is_string( $status_arr ) ) {
        $status_arr = @unserialize( $status_arr );
    }
    if ( ! is_array( $status_arr ) ) {
        $status_arr = [];
    }
    
    $checked_in = ( isset( $status_arr[$today] ) && $status_arr[$today] === 'checked_in' );
    $checkin_date = $checked_in ? date_i18n( 'd/m/Y', strtotime( $today ) ) : '';
    
    wp_send_json_success([
        'ticket' => [
            'id'           => $ticket_id,
            'nombre'       => trim( $nombre . ' ' . $apellido ),
            'email'        => $email,
            'ticket_id'    => $ticket_public_id,
            'localidad'    => $localidad,
            'qr_type'      => $qr_type,
            'qr_type_label'=> $qr_type_label,
            'checked_in'   => $checked_in,
            'checkin_date' => $checkin_date,
        ],
    ]);
}

// ========================================
// AJAX: Verificar código y hacer check-in
// ========================================

add_action( 'wp_ajax_eventosapp_verify_and_checkin', 'eventosapp_ajax_verify_and_checkin' );
add_action( 'wp_ajax_nopriv_eventosapp_verify_and_checkin', 'eventosapp_ajax_verify_and_checkin' );

function eventosapp_ajax_verify_and_checkin() {
    check_ajax_referer( 'eventosapp_verify_checkin', 'nonce' );
    
    $ticket_id = isset( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
    $auth_code = isset( $_POST['auth_code'] ) ? sanitize_text_field( $_POST['auth_code'] ) : '';
    $event_id  = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
    $qr_type = isset( $_POST['qr_type'] ) ? sanitize_key( $_POST['qr_type'] ) : 'legacy';
    $qr_type_label = isset( $_POST['qr_type_label'] ) ? sanitize_text_field( $_POST['qr_type_label'] ) : '';
    if ( $qr_type_label === '' ) {
        if ( class_exists( 'EventosApp_QR_Manager' ) && method_exists( 'EventosApp_QR_Manager', 'get_qr_type_label' ) ) {
            $qr_type_label = EventosApp_QR_Manager::get_qr_type_label( $qr_type );
        } elseif ( $qr_type === 'whatsapp' ) {
            $qr_type_label = 'WhatsApp';
        } elseif ( $qr_type === 'preprinted' ) {
            $qr_type_label = 'QR Preimpreso';
        } else {
            $qr_type_label = 'QR Legacy';
        }
    }
    
    if ( ! $ticket_id || ! $auth_code || ! $event_id ) {
        wp_send_json_error( 'Datos incompletos' );
    }
    
    // Verificar que el ticket pertenece al evento
    $ticket_event = get_post_meta( $ticket_id, '_eventosapp_ticket_evento_id', true );
    if ( absint( $ticket_event ) !== $event_id ) {
        wp_send_json_error( 'El ticket no pertenece a este evento' );
    }
    
    // Validar código
    if ( ! function_exists( 'eventosapp_validate_auth_code' ) ) {
        wp_send_json_error( 'Sistema de autenticación no disponible' );
    }
    
    $is_valid = eventosapp_validate_auth_code( $ticket_id, $auth_code );
    
    if ( ! $is_valid ) {
        wp_send_json_error( 'Código de verificación incorrecto. Por favor verifica e intenta nuevamente.' );
    }
    
    // ===== Código válido, proceder con check-in =====
    
    // 1) Obtener zona horaria del evento
    $event_tz = get_post_meta( $event_id, '_eventosapp_zona_horaria', true );
    if ( ! $event_tz ) {
        $event_tz = wp_timezone_string();
        if ( ! $event_tz || $event_tz === 'UTC' ) {
            $offset = get_option( 'gmt_offset' );
            $event_tz = $offset ? timezone_name_from_abbr( '', $offset * 3600, 0 ) ?: 'UTC' : 'UTC';
        }
    }
    
    // 2) "Hoy" en la TZ del evento
    try {
        $dt = new DateTime( 'now', new DateTimeZone( $event_tz ) );
    } catch ( Exception $e ) {
        $dt = new DateTime( 'now', wp_timezone() );
    }
    $today = $dt->format( 'Y-m-d' );
    
    // 3) Días válidos del evento
    $days = function_exists( 'eventosapp_get_event_days' ) ? (array) eventosapp_get_event_days( $event_id ) : [];
    
    // 4) Si hoy NO es un día del evento => bloquear
    if ( empty( $days ) || ! in_array( $today, $days, true ) ) {
        wp_send_json_error( 'El check-in solo está permitido en las fechas del evento. Hoy no corresponde.' );
    }
    
    // 5) Estado multidía - usar el sistema correcto de EventosApp
    $status_arr = get_post_meta( $ticket_id, '_eventosapp_checkin_status', true );
    if ( is_string( $status_arr ) ) {
        $status_arr = @unserialize( $status_arr );
    }
    if ( ! is_array( $status_arr ) ) {
        $status_arr = [];
    }
    
    $already_checked = ( isset( $status_arr[$today] ) && $status_arr[$today] === 'checked_in' );
    
    // 6) Si NO está chequeado, hacer check-in
    if ( ! $already_checked ) {
        $status_arr[$today] = 'checked_in';
        update_post_meta( $ticket_id, '_eventosapp_checkin_status', $status_arr );
        
        // Log de check-in
        $log = get_post_meta( $ticket_id, '_eventosapp_checkin_log', true );
        if ( is_string( $log ) ) {
            $log = @unserialize( $log );
        }
        if ( ! is_array( $log ) ) {
            $log = [];
        }
        
        $user = wp_get_current_user();
        $log[] = [
            'fecha'   => $dt->format( 'Y-m-d' ),
            'hora'    => $dt->format( 'H:i:s' ),
            'dia'     => $today,
            'status'  => 'checked_in',
            'origen'  => 'QR Doble Autenticación',
            'qr_type' => $qr_type,
            'qr_type_label' => $qr_type_label,
            'usuario' => $user && $user->exists() ? ( $user->display_name . ' (' . $user->user_email . ')' ) : 'Sistema'
        ];
        update_post_meta( $ticket_id, '_eventosapp_checkin_log', $log );

        if ( function_exists( 'eventosapp_update_qr_usage_stats' ) ) {
            eventosapp_update_qr_usage_stats( $event_id, $qr_type );
        }
    }
    
    // 7) Obtener datos del asistente para respuesta
    $nombre   = get_post_meta( $ticket_id, '_eventosapp_asistente_nombre', true );
    $apellido = get_post_meta( $ticket_id, '_eventosapp_asistente_apellido', true );
    
    if ( $already_checked ) {
        $message = sprintf( 'Check-in confirmado para %s %s (ya había ingresado hoy anteriormente)', $nombre, $apellido );
    } else {
        $message = sprintf( 'Check-in exitoso para %s %s', $nombre, $apellido );
    }
    
    wp_send_json_success([
        'message'            => $message,
        'already_checked'    => $already_checked,
        'checkin_date'       => $today,
        'checkin_date_label' => date_i18n( 'D, d M Y', strtotime( $today ) ),
        'qr_type'            => $qr_type,
        'qr_type_label'      => $qr_type_label,
    ]);
}
