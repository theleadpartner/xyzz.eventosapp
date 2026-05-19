<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * EventosApp - Networking Global
 * Shortcode: [eventosapp_networking_global]
 * 
 * Permite que asistentes escaneen QR de escarapelas con cualquier cámara
 * y accedan a datos de otros asistentes mediante autenticación
 * 
 * URL esperada: /networking/global/?event=123-ticketid=ABC123-7890
 * 
 * @package EventosApp
 * @version 1.7
 * CORREGIDO: Scanner ahora decodifica QR sin redirigir cuando hay sesión activa
 */

add_shortcode('eventosapp_networking_global', function($atts){
    global $wpdb;
    
    // Obtener parámetros de la URL
    $url_params = isset($_GET['event']) ? sanitize_text_field($_GET['event']) : '';
    
    // Variables para el ticket escaneado
    $event_id = 0;
    $scanned_ticket_post_id = 0;
    $has_qr_params = false;
    
    // Si hay parámetros, procesarlos.
    // Compatibilidad: escarapela/networking, QR simples por medio y QR WhatsApp.
    if (!empty($url_params)) {
        $validation = false;

        if (class_exists('EventosApp_QR_Manager')) {
            $validation = EventosApp_QR_Manager::validate_qr($url_params);
        }

        if (!empty($validation['valid']) && !empty($validation['ticket_id'])) {
            $scanned_ticket_post_id = (int) $validation['ticket_id'];
            $event_id = (int) get_post_meta($scanned_ticket_post_id, '_eventosapp_ticket_evento_id', true);
            $has_qr_params = true;
        } elseif (preg_match('/^(\d+)-ticketid=(.+)-([^-]+)$/', $url_params, $matches)) {
            // Fallback para URLs antiguas de badge cuando QR Manager no pueda validar.
            $event_id         = absint($matches[1]);
            $unique_ticket_id = sanitize_text_field($matches[2]);
            $security_code    = sanitize_text_field($matches[3]);

            $scanned_ticket_post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = 'eventosapp_ticketID'
                 AND meta_value = %s
                 LIMIT 1",
                $unique_ticket_id
            ));

            if (!$scanned_ticket_post_id || get_post_type($scanned_ticket_post_id) !== 'eventosapp_ticket') {
                return '<div style="max-width:520px;margin:2rem auto;padding:2rem;background:#fee2e2;border:1px solid #ef4444;border-radius:12px;text-align:center;">
                    <h3 style="color:#dc2626;margin:0 0 1rem;">⚠️ Ticket no encontrado</h3>
                    <p style="color:#991b1b;margin:0;">El ticket escaneado no existe en el sistema. (ID: ' . esc_html($unique_ticket_id) . ')</p>
                </div>';
            }

            $stored_security_code = get_post_meta($scanned_ticket_post_id, '_eventosapp_badge_security_code', true);
            if (empty($stored_security_code) || $stored_security_code !== $security_code) {
                return '<div style="max-width:520px;margin:2rem auto;padding:2rem;background:#fee2e2;border:1px solid #ef4444;border-radius:12px;text-align:center;">
                    <h3 style="color:#dc2626;margin:0 0 1rem;">🔒 Código de seguridad inválido</h3>
                    <p style="color:#991b1b;margin:0;">El código de seguridad no coincide. Este QR puede estar revocado.</p>
                </div>';
            }

            $ticket_event_id = (int) get_post_meta($scanned_ticket_post_id, '_eventosapp_ticket_evento_id', true);
            if ($ticket_event_id !== $event_id) {
                return '<div style="max-width:520px;margin:2rem auto;padding:2rem;background:#fee2e2;border:1px solid #ef4444;border-radius:12px;text-align:center;">
                    <h3 style="color:#dc2626;margin:0 0 1rem;">⚠️ Evento no coincide</h3>
                    <p style="color:#991b1b;margin:0;">Este ticket no pertenece al evento indicado.</p>
                    <p style="color:#991b1b;margin-top:0.5rem;font-size:0.9rem;">Ticket evento: ' . esc_html($ticket_event_id) . ' | URL evento: ' . esc_html($event_id) . '</p>
                </div>';
            }

            $has_qr_params = true;
        } elseif (preg_match('/^(.+)-(email|gwallet|awallet|pdf|whatsapp)$/i', $url_params, $matches)) {
            // Fallback para QR simples cuando el metadato del QR todavía no existe o fue migrado.
            $unique_ticket_id = sanitize_text_field($matches[1]);
            $scanned_ticket_post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = 'eventosapp_ticketID'
                 AND meta_value = %s
                 LIMIT 1",
                $unique_ticket_id
            ));

            if ($scanned_ticket_post_id && get_post_type($scanned_ticket_post_id) === 'eventosapp_ticket') {
                $event_id = (int) get_post_meta($scanned_ticket_post_id, '_eventosapp_ticket_evento_id', true);
                $has_qr_params = true;
            } else {
                return '<div style="max-width:520px;margin:2rem auto;padding:2rem;background:#fee2e2;border:1px solid #ef4444;border-radius:12px;text-align:center;">
                    <h3 style="color:#dc2626;margin:0 0 1rem;">⚠️ Ticket no encontrado</h3>
                    <p style="color:#991b1b;margin:0;">El ticket escaneado no existe en el sistema. (ID: ' . esc_html($unique_ticket_id) . ')</p>
                </div>';
            }
        }
    }
    
    // Nonces para AJAX
    $nonce_auth = wp_create_nonce('eventosapp_netglobal_auth');
    $nonce_log  = wp_create_nonce('eventosapp_netglobal_log');
    
    ob_start(); ?>
    <style>
      .evapp-netglobal-shell { max-width:560px; margin:0 auto; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
      .evapp-netglobal-card  { background:#0b1020; color:#eaf1ff; border-radius:16px; padding:18px; box-shadow:0 8px 24px rgba(0,0,0,.15); }
      .evapp-netglobal-title { display:flex; align-items:center; gap:.6rem; margin:0 0 10px; font-weight:800; font-size:1.05rem; letter-spacing:.2px; }
      .evapp-netglobal-field { margin:10px 0; }
      .evapp-netglobal-field label { display:block; font-size:.95rem; margin-bottom:6px; color:#c9d6ff; font-weight:600; }
      .evapp-netglobal-input { width:100%; padding:.7rem .8rem; border-radius:10px; border:1px solid rgba(255,255,255,.12); background:#0a0f1d; color:#eaf1ff; }
      .evapp-netglobal-btn   { display:flex; align-items:center; justify-content:center; gap:.5rem; border:0; border-radius:12px; padding:.9rem 1.1rem; font-weight:800; cursor:pointer; width:100%; background:#2563eb; color:#fff; transition:filter .15s, background .15s; }
      .evapp-netglobal-btn:hover{ filter:brightness(.98); }
      .evapp-netglobal-btn.is-live{ background:#e04f5f; }
      .evapp-netglobal-help  { color:#a9b6d3; font-size:.9rem; opacity:.85; margin-top:6px; }
      .evapp-netglobal-msg   { padding:12px; border-radius:8px; margin-top:12px; text-align:center; font-weight:600; }
      .evapp-netglobal-bad   { background:#fee2e2; color:#dc2626; border:1px solid #ef4444; }
      .evapp-netglobal-ok    { background:#d1fae5; color:#047857; border:1px solid #10b981; }
      
      .evapp-netglobal-result { display:none; margin-top:1.5rem; }
      .evapp-netglobal-avatar { width:100px; height:100px; border-radius:50%; margin:0 auto 1rem; display:block; object-fit:cover; border:3px solid #4f7cff; }
      .evapp-netglobal-name { text-align:center; font-size:1.5rem; font-weight:800; margin:0 0 .5rem; color:#eaf1ff; }
      .evapp-netglobal-role { text-align:center; font-size:1rem; color:#a7b8ff; margin:0 0 1.5rem; }
      .evapp-netglobal-grid { display:grid; grid-template-columns:1fr; gap:.6rem; margin:1rem 0; }
      .evapp-netglobal-grid-item { background:#0a0f1d; padding:12px; border-radius:8px; border:1px solid rgba(255,255,255,.06); }
      .evapp-netglobal-grid-item b { display:block; color:#a7b8ff; font-size:.85rem; margin-bottom:4px; }
      .evapp-netglobal-grid-item span { color:#eaf1ff; font-size:1rem; }
      .evapp-netglobal-actions { display:flex; flex-direction:column; gap:12px; margin-top:1.5rem; }
      .evapp-netglobal-download { background:#10b981!important; }
      .evapp-netglobal-back { background:transparent!important; border:1px solid rgba(255,255,255,.18)!important; }
      
      /* Scanner QR */
      .evapp-qr-video-wrap { position:relative; margin-top:12px; border-radius:14px; overflow:hidden; background:#0a0f1d; aspect-ratio:3/4; display:none; }
      .evapp-qr-video { width:100%; height:100%; object-fit:cover; display:none; }
      .evapp-qr-frame { position:absolute; inset:0; pointer-events:none; display:none; }
      .evapp-qr-frame .mask { position:absolute; inset:0; background: radial-gradient(ellipse 60% 40% at 50% 50%, rgba(255,255,255,0) 62%, rgba(10,15,29,.55) 64%); }
      .evapp-qr-corner { position:absolute; width:44px; height:44px; border:4px solid #4f7cff; border-radius:10px; }
      .evapp-qr-corner.tl{top:16px;left:16px;border-right:0;border-bottom:0}
      .evapp-qr-corner.tr{top:16px;right:16px;border-left:0;border-bottom:0}
      .evapp-qr-corner.bl{bottom:16px;left:16px;border-right:0;border-top:0}
      .evapp-qr-corner.br{bottom:16px;right:16px;border-left:0;border-top:0}
      .evapp-qr-video-wrap.is-immersive{ aspect-ratio:auto; height: calc(100vh - var(--evapp-offset, 56px)); width:100%; display:block; }
      
      .evapp-qr-result-box { margin-top:14px; background:#0a0f1d; border:1px solid rgba(255,255,255,.06); border-radius:12px; padding:14px; }
    </style>

    <div class="evapp-netglobal-shell"
         data-event="<?php echo esc_attr($event_id); ?>"
         data-scanned-ticket="<?php echo esc_attr($scanned_ticket_post_id); ?>"
         data-has-qr-params="<?php echo $has_qr_params ? '1' : '0'; ?>"
         data-auth-nonce="<?php echo esc_attr($nonce_auth); ?>"
         data-log-nonce="<?php echo esc_attr($nonce_log); ?>">

      <div class="evapp-netglobal-card">
        <div class="evapp-netglobal-title">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6zm14 0h2v6h-6v-2h4v-4z" stroke="#a7b8ff"/></svg>
          Networking – Doble autenticación
        </div>

        <!-- Paso 1: Autenticación -->
        <div id="evappNetGlobalAuth">
          <div class="evapp-netglobal-field">
            <label>Cédula</label>
            <input type="text" id="evappAuthCC" class="evapp-netglobal-input" placeholder="Ej: 1020304050">
            <small class="evapp-netglobal-help">
              Escribe tal cual como está en tu inscripción.
            </small>
          </div>
          
          <div class="evapp-netglobal-field">
            <label>Apellido</label>
            <input type="text" id="evappAuthLast" class="evapp-netglobal-input" placeholder="Ej: Pérez o García">
            <small class="evapp-netglobal-help">
              Escribe tal cual como está en tu inscripción.
            </small>
          </div>
          
          <button type="button" id="evappAuthBtn" class="evapp-netglobal-btn">Confirmar identidad</button>
          
          <p id="evappAuthMsg" class="evapp-netglobal-help"></p>
        </div>

        <!-- Paso 2: Scanner -->
        <div id="evappNetGlobalScan" style="display:none;">
          <p id="evappScanWelcome" class="evapp-netglobal-help" style="text-align:center;margin-bottom:1rem;">
            Activa la cámara para escanear.
          </p>
          
          <button type="button" id="evappStartScanGlobal" class="evapp-netglobal-btn">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
              <path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/>
              <rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/>
            </svg>
            Activar cámara y escanear
          </button>
          
          <div id="evappVideoWrapGlobal" class="evapp-qr-video-wrap">
            <video id="evappVideoGlobal" class="evapp-qr-video" playsinline></video>
            <div id="evappFrameGlobal" class="evapp-qr-frame">
              <div class="mask"></div>
              <div class="evapp-qr-corner tl"></div>
              <div class="evapp-qr-corner tr"></div>
              <div class="evapp-qr-corner bl"></div>
              <div class="evapp-qr-corner br"></div>
            </div>
            <canvas id="evappCanvasGlobal" style="display:none;"></canvas>
          </div>
          
          <div id="evappResultBoxGlobal" class="evapp-qr-result-box"></div>
        </div>

        <!-- Paso 3: Resultado -->
        <div id="evappNetGlobalResult" class="evapp-netglobal-result">
          <!-- Se llenará dinámicamente -->
        </div>
      </div>
    </div>

    <script>
    (function(){
      const shell = document.querySelector('.evapp-netglobal-shell');
      const ajaxURL    = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
      let eventID      = parseInt(shell?.dataset.event || '0', 10) || 0;
      const scannedTicketID = parseInt(shell?.dataset.scannedTicket || '0', 10) || 0;
      const hasQRParams = shell?.dataset.hasQrParams === '1';
      const authNonce  = shell?.dataset.authNonce || '';
      const logNonce   = shell?.dataset.logNonce || '';

      // Elementos del DOM
      const authSection = document.getElementById('evappNetGlobalAuth');
      const scanSection = document.getElementById('evappNetGlobalScan');
      const resultSection = document.getElementById('evappNetGlobalResult');
      const ccInput = document.getElementById('evappAuthCC');
      const lastInput = document.getElementById('evappAuthLast');
      const authBtn = document.getElementById('evappAuthBtn');
      const authMsg = document.getElementById('evappAuthMsg');
      const scanWelcome = document.getElementById('evappScanWelcome');

      // Scanner elements
      const btnScan = document.getElementById('evappStartScanGlobal');
      const video = document.getElementById('evappVideoGlobal');
      const frame = document.getElementById('evappFrameGlobal');
      const cvs = document.getElementById('evappCanvasGlobal');
      const ctx = cvs?.getContext('2d');
      const vwrap = document.getElementById('evappVideoWrapGlobal');
      const resultBox = document.getElementById('evappResultBoxGlobal');

      let readerTicketId = 0;
      const SESSION_DURATION = 4 * 60 * 60 * 1000; // 4 horas en milisegundos

      // Scanner state
      let stream = null;
      let running = false;
      let lastScan = "";
      let lastAt = 0;
      let barcodeDetector = ('BarcodeDetector' in window) ? new window.BarcodeDetector({formats:['qr_code']}) : null;

      // ========== SISTEMA DE SESIÓN PERSISTENTE ==========
      
      function getStorageKey(evId) {
        return 'eventosapp_net_session_' + evId;
      }

      function saveSession(ticketId, evId) {
        if (!evId || !ticketId) return;
        
        const session = {
          reader_ticket_id: ticketId,
          event_id: evId,
          timestamp: Date.now(),
          expires: Date.now() + SESSION_DURATION
        };
        
        try {
          localStorage.setItem(getStorageKey(evId), JSON.stringify(session));
        } catch(e) {
          console.error('Error guardando sesión:', e);
        }
      }

      function getSession(evId) {
        if (!evId) return null;
        
        try {
          const stored = localStorage.getItem(getStorageKey(evId));
          if (!stored) return null;
          
          const session = JSON.parse(stored);
          
          // Verificar si la sesión ha expirado
          if (Date.now() > session.expires) {
            clearSession(evId);
            return null;
          }
          
          // Verificar que es del mismo evento
          if (session.event_id !== evId) {
            return null;
          }
          
          return session;
        } catch(e) {
          console.error('Error leyendo sesión:', e);
          return null;
        }
      }

      function clearSession(evId) {
        if (!evId) return;
        try {
          localStorage.removeItem(getStorageKey(evId));
        } catch(e) {
          console.error('Error limpiando sesión:', e);
        }
      }

      // Buscar sesión activa en localStorage
      function findActiveSession() {
        try {
          for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (key && key.startsWith('eventosapp_net_session_')) {
              const stored = localStorage.getItem(key);
              if (stored) {
                const session = JSON.parse(stored);
                // Verificar si no ha expirado
                if (Date.now() <= session.expires) {
                  return session;
                }
              }
            }
          }
        } catch(e) {
          console.error('Error buscando sesión:', e);
        }
        return null;
      }

      // ========== SCANNER QR ==========
      
      function normalizeRaw(raw) {
        let s = String(raw || '').trim();
        
        console.log('normalizeRaw - entrada:', s);
        
        // Si contiene URL completa, extraer solo los parámetros
        // Buscar patrón: /networking/global?event= o /networking/global/?event=
        if (s.includes('/networking/global')) {
          // Extraer todo después de ?event=
          const match = s.match(/\?event=(.+)$/);
          if (match && match[1]) {
            console.log('normalizeRaw - URL detectada, extrayendo:', match[1]);
            return match[1]; // Retorna: "14564-ticketid=ABC123-5389"
          }
        }
        
        // Si tiene '/', tomar la última parte
        if (s.includes('/')) {
          s = s.split('/').pop();
        }
        
        // Eliminar el prefijo ?event= si existe
        if (s.startsWith('?event=')) {
          s = s.substring(7); // Elimina "?event="
        }
        
        // Limpiar extensiones y caracteres
        s = s.replace(/\.(png|jpg|jpeg|pdf)$/i, '').replace(/-tn$/i, '').replace(/^#/, '');
        
        console.log('normalizeRaw - salida:', s);
        return s;
      }

      function beep() {
        try {
          const a = new Audio();
          a.src = 'data:audio/mp3;base64,//uQxAAAAAAAAAAAAAAAAAAAAAAAWGluZwAAAA8AAAACAAACcQAA';
          a.play().catch(() => {});
        } catch(e) {}
        if (navigator.vibrate) navigator.vibrate(60);
      }

      function getOffset() {
        const ab = document.getElementById('wpadminbar');
        return (ab ? ab.offsetHeight : 0) + 10;
      }

      function smoothScrollTo(el) {
        if (!el) return;
        const off = getOffset();
        try {
          el.style.setProperty('--evapp-offset', off + 'px');
        } catch(e) {}
        const y = el.getBoundingClientRect().top + window.pageYOffset - off;
        window.scrollTo({top: y, behavior: 'smooth'});
      }

      function setLiveUI(on) {
        if (on) {
          btnScan.classList.add('is-live');
          btnScan.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6h12v12H6z" stroke="white"/></svg> Detener cámara';
        } else {
          btnScan.classList.remove('is-live');
          btnScan.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 7V3h-4M3 7V3h4M21 17v4h-4M3 17v4h4" stroke="white"/><rect x="7" y="7" width="10" height="10" rx="2" stroke="white"/></svg> Activar cámara y escanear';
        }
      }

      function stopScanner() {
        running = false;
        if (stream) stream.getTracks().forEach(t => t.stop());
        stream = null;
        video.style.display = 'none';
        frame.style.display = 'none';
        vwrap?.classList.remove('is-immersive');
        setLiveUI(false);
      }

      async function ensureJsQR() {
        if ('BarcodeDetector' in window) return false;
        if (!window.jsQR) {
          await new Promise((resolve) => {
            const s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';
            s.onload = resolve;
            document.head.appendChild(s);
          });
        }
        return true;
      }

      async function startScanner() {
        try {
          stream = await navigator.mediaDevices.getUserMedia({
            video: {facingMode: {ideal: 'environment'}},
            audio: false
          });
        } catch(e) {
          resultBox.innerHTML = '<div class="evapp-netglobal-bad">No se pudo acceder a la cámara.</div>';
          smoothScrollTo(resultBox);
          return;
        }
        
        video.srcObject = stream;
        await video.play();
        video.style.display = 'block';
        frame.style.display = 'block';
        vwrap?.classList.add('is-immersive');
        smoothScrollTo(vwrap);
        cvs.width = video.videoWidth || 640;
        cvs.height = video.videoHeight || 480;
        running = true;
        setLiveUI(true);
        tick();
      }

      async function tick() {
        if (!running) return;
        ctx.drawImage(video, 0, 0, cvs.width, cvs.height);
        
        if (barcodeDetector) {
          try {
            const bmp = await createImageBitmap(cvs);
            const codes = await barcodeDetector.detect(bmp);
            if (codes && codes.length) {
              onScan(normalizeRaw(codes[0].rawValue || ''));
              return;
            }
          } catch(e) {}
        } else if (window.jsQR) {
          const img = ctx.getImageData(0, 0, cvs.width, cvs.height);
          const code = window.jsQR(img.data, img.width, img.height);
          if (code && code.data) {
            onScan(normalizeRaw(code.data));
            return;
          }
        }
        
        requestAnimationFrame(tick);
      }

      function onScan(raw) {
        const now = Date.now();
        if (raw === lastScan && (now - lastAt) < 3000) {
          requestAnimationFrame(tick);
          return;
        }
        lastScan = raw;
        lastAt = now;
        
        stopScanner();
        beep();
        
        console.log('QR escaneado (normalizado):', raw);
        
        // raw ya viene limpio: "14564-ticketid=ABC123-5389"
        
        // NUEVO: Si ya tenemos sesión activa, decodificar sin redirigir
        if (readerTicketId && eventID) {
          console.log('Sesión activa detectada, decodificando QR sin redirigir');
          decodeQRAndLoadData(raw);
        } else {
          console.log('Sin sesión activa, redirigiendo para autenticación');
          // No hay sesión, redirigir para autenticarse
          const baseUrl = window.location.origin + window.location.pathname;
          window.location.href = baseUrl + '?event=' + raw;
        }
      }

      // ========== DECODIFICAR QR Y CARGAR DATOS SIN REDIRIGIR ==========
      
      function decodeQRAndLoadData(qrContent) {
        console.log('decodeQRAndLoadData - contenido:', qrContent);
        
        resultBox.innerHTML = '<div class="evapp-netglobal-help">⏳ Validando código QR...</div>';
        smoothScrollTo(resultBox);
        
        fetch(ajaxURL, {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: new URLSearchParams({
            action: 'eventosapp_netglobal_decode_qr',
            _wpnonce: authNonce,
            qr_content: qrContent
          })
        })
        .then(r => r.json())
        .then(data => {
          console.log('Respuesta decode_qr:', data);
          
          if (data.success) {
            const scannedTicket = data.data.ticket_id;
            
            console.log('QR válido, cargando datos del ticket:', scannedTicket);
            
            // Cargar datos del ticket
            fetch(ajaxURL, {
              method: 'POST',
              headers: {'Content-Type': 'application/x-www-form-urlencoded'},
              body: new URLSearchParams({
                action: 'eventosapp_netglobal_get_ticket_data',
                _wpnonce: authNonce,
                ticket_id: scannedTicket
              })
            })
            .then(r => r.json())
            .then(ticketData => {
              console.log('Datos del ticket:', ticketData);
              
              if (ticketData.success) {
                const info = ticketData.data;
                displayTicketDataDirect(info, scannedTicket);
                logInteractionDirect(scannedTicket);
                resultBox.innerHTML = ''; // Limpiar mensaje de carga
              } else {
                resultBox.innerHTML = '<div class="evapp-netglobal-bad">❌ Error al cargar datos del ticket</div>';
              }
            })
            .catch((err) => {
              console.error('Error cargando datos:', err);
              resultBox.innerHTML = '<div class="evapp-netglobal-bad">❌ Error de conexión al cargar datos</div>';
            });
          } else {
            console.error('QR inválido:', data.data?.message);
            resultBox.innerHTML = '<div class="evapp-netglobal-bad">❌ ' + (data.data?.message || 'QR inválido') + '</div>';
            smoothScrollTo(resultBox);
            
            // Reactivar scanner después de 3 segundos
            setTimeout(() => {
              resultBox.innerHTML = '';
            }, 3000);
          }
        })
        .catch((err) => {
          console.error('Error validando QR:', err);
          resultBox.innerHTML = '<div class="evapp-netglobal-bad">❌ Error de conexión al validar QR</div>';
          smoothScrollTo(resultBox);
          
          setTimeout(() => {
            resultBox.innerHTML = '';
          }, 3000);
        });
      }
      
      function displayTicketDataDirect(info, ticketId){
        console.log('Mostrando datos del ticket:', ticketId);
        
        let html = '';
        
        // Avatar si existe
        if (info.foto_url) {
          html += '<img src="' + escapeHtml(info.foto_url) + '" alt="Foto" class="evapp-netglobal-avatar">';
        }
        
        // Nombre completo
        html += '<h2 class="evapp-netglobal-name">' + escapeHtml(info.full_name) + '</h2>';
        
        // Cargo y empresa
        if (info.designation || info.company) {
          let role = '';
          if (info.designation) role += escapeHtml(info.designation);
          if (info.company) role += (role ? ' en ' : '') + escapeHtml(info.company);
          html += '<p class="evapp-netglobal-role">' + role + '</p>';
        }
        
        // Grid de información
        html += '<div class="evapp-netglobal-grid">';
        
        if (info.email) {
          html += '<div class="evapp-netglobal-grid-item"><b>✉️ Email</b><span>' + escapeHtml(info.email) + '</span></div>';
        }
        
        if (info.phone) {
          html += '<div class="evapp-netglobal-grid-item"><b>📱 Teléfono</b><span>' + escapeHtml(info.phone) + '</span></div>';
        }
        
        if (info.localidad) {
          html += '<div class="evapp-netglobal-grid-item"><b>🎫 Localidad</b><span>' + escapeHtml(info.localidad) + '</span></div>';
        }
        
        html += '</div>';
        
        // Botones de acción
        html += '<div class="evapp-netglobal-actions">';
        html += '<button type="button" class="evapp-netglobal-btn evapp-netglobal-download" onclick="evappDownloadVCard(' + ticketId + ')">📥 Descargar contacto (vCard)</button>';
        html += '<button type="button" class="evapp-netglobal-btn evapp-netglobal-back" onclick="evappScanAnotherDirect()">📱 Escanear otro QR</button>';
        html += '</div>';
        
        resultSection.innerHTML = html;
        authSection.style.display = 'none';
        scanSection.style.display = 'none';
        resultSection.style.display = 'block';
        
        smoothScrollTo(resultSection);
      }
      
      function logInteractionDirect(scannedTicket){
        if (!readerTicketId || !scannedTicket) return;

        console.log('Registrando interacción:', {readerTicketId, scannedTicket, eventID});

        fetch(ajaxURL, {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: new URLSearchParams({
            action: 'eventosapp_netglobal_log_interaction',
            _wpnonce: logNonce,
            event_id: eventID,
            reader_ticket_id: readerTicketId,
            read_ticket_id: scannedTicket
          })
        });
      }

      // Event listener para el botón de scanner
      if (btnScan) {
        btnScan.addEventListener('click', async function() {
          if (running) {
            stopScanner();
          } else {
            await ensureJsQR();
            await startScanner();
          }
        });
      }

      // ========== INICIALIZACIÓN ==========
      
      function init() {
        // Si no hay eventID en la URL, buscar sesión activa
        if (!eventID) {
          const activeSession = findActiveSession();
          if (activeSession) {
            eventID = activeSession.event_id;
            readerTicketId = activeSession.reader_ticket_id;
          }
        }

        const session = eventID ? getSession(eventID) : null;
        
        if (session) {
          // Ya hay sesión válida
          readerTicketId = session.reader_ticket_id;
          
          if (hasQRParams && scannedTicketID) {
            // Hay sesión Y hay QR escaneado -> cargar datos automáticamente
            authSection.style.display = 'none';
            scanSection.style.display = 'none';
            loadScannedTicketData();
          } else {
            // Hay sesión pero NO hay QR -> mostrar scanner
            authSection.style.display = 'none';
            scanSection.style.display = 'block';
            resultSection.style.display = 'none';
            
            // Obtener nombre del usuario
            fetchReaderName();
          }
        } else {
          // No hay sesión válida
          if (hasQRParams && scannedTicketID) {
            // No hay sesión pero hay QR -> mostrar formulario de autenticación
            authSection.style.display = 'block';
            scanSection.style.display = 'none';
            resultSection.style.display = 'none';
            authMsg.textContent = 'Para iniciar a escanear un QR debes autenticarte.';
          } else {
            // No hay sesión ni QR -> mostrar mensaje
            authSection.style.display = 'block';
            scanSection.style.display = 'none';
            resultSection.style.display = 'none';
            authMsg.innerHTML = '<span class="evapp-netglobal-bad">⚠️ Por favor escanea un código QR para continuar</span>';
            authBtn.style.display = 'none';
            ccInput.parentElement.style.display = 'none';
            lastInput.parentElement.style.display = 'none';
          }
        }
      }

      function fetchReaderName() {
        if (!readerTicketId) return;
        
        fetch(ajaxURL, {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: new URLSearchParams({
            action: 'eventosapp_netglobal_get_ticket_data',
            _wpnonce: authNonce,
            ticket_id: readerTicketId
          })
        })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            scanWelcome.textContent = 'Hola, ' + (data.data.full_name || 'asistente') + '. Activa la cámara para escanear.';
          }
        })
        .catch(() => {
          scanWelcome.textContent = 'Activa la cámara para escanear.';
        });
      }

      // ========== FUNCIONES DE UI ==========

      function setMsg(container, html, isGood = false){
        container.innerHTML = '<div class="evapp-netglobal-msg ' + (isGood ? 'evapp-netglobal-ok' : 'evapp-netglobal-bad') + '">' + html + '</div>';
      }

      // ========== AUTENTICACIÓN ==========

      if (authBtn) {
        authBtn.addEventListener('click', function(){
          const cc = ccInput.value.trim();
          const last = lastInput.value.trim();

          if (!cc || !last) {
            authMsg.textContent = 'Completa cédula y apellido.';
            authMsg.className = 'evapp-netglobal-bad';
            return;
          }

          authBtn.disabled = true;
          authMsg.className = 'evapp-netglobal-help';
          authMsg.textContent = 'Validando…';

          fetch(ajaxURL, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
              action: 'eventosapp_netglobal_identify',
              _wpnonce: authNonce,
              event_id: eventID,
              cc: cc,
              last_name: last
            })
          })
          .then(r => r.json())
          .then(data => {
            if (data.success) {
              readerTicketId = data.data.ticket_id;
              
              // Guardar sesión en localStorage
              saveSession(readerTicketId, eventID);
              
              authMsg.className = 'evapp-netglobal-ok';
              authMsg.textContent = 'Identidad confirmada.';
              
              setTimeout(() => {
                if (hasQRParams && scannedTicketID) {
                  loadScannedTicketData();
                } else {
                  // Mostrar scanner
                  authSection.style.display = 'none';
                  scanSection.style.display = 'block';
                  fetchReaderName();
                }
              }, 800);
            } else {
              authMsg.className = 'evapp-netglobal-bad';
              authMsg.textContent = data.data?.message || 'No se encontró tu registro';
              authBtn.disabled = false;
            }
          })
          .catch(err => {
            authMsg.className = 'evapp-netglobal-bad';
            authMsg.textContent = 'Error de red.';
            authBtn.disabled = false;
          });
        });
      }

      // ========== CARGA DE DATOS DEL TICKET ESCANEADO ==========

      function loadScannedTicketData(){
        if (!scannedTicketID) {
          authMsg.className = 'evapp-netglobal-bad';
          authMsg.textContent = 'No hay ticket para cargar';
          return;
        }

        fetch(ajaxURL, {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: new URLSearchParams({
            action: 'eventosapp_netglobal_get_ticket_data',
            _wpnonce: authNonce,
            ticket_id: scannedTicketID
          })
        })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            const info = data.data;
            displayTicketData(info);
            logInteraction();
          } else {
            authMsg.className = 'evapp-netglobal-bad';
            authMsg.textContent = data.data?.message || 'Error al cargar datos';
          }
        })
        .catch(err => {
          authMsg.className = 'evapp-netglobal-bad';
          authMsg.textContent = 'Error al cargar información del asistente';
        });
      }

      function displayTicketData(info){
        let html = '';
        
        // Avatar si existe
        if (info.foto_url) {
          html += '<img src="' + escapeHtml(info.foto_url) + '" alt="Foto" class="evapp-netglobal-avatar">';
        }
        
        // Nombre completo
        html += '<h2 class="evapp-netglobal-name">' + escapeHtml(info.full_name) + '</h2>';
        
        // Cargo y empresa
        if (info.designation || info.company) {
          let role = '';
          if (info.designation) role += escapeHtml(info.designation);
          if (info.company) role += (role ? ' en ' : '') + escapeHtml(info.company);
          html += '<p class="evapp-netglobal-role">' + role + '</p>';
        }
        
        // Grid de información
        html += '<div class="evapp-netglobal-grid">';
        
        if (info.email) {
          html += '<div class="evapp-netglobal-grid-item"><b>✉️ Email</b><span>' + escapeHtml(info.email) + '</span></div>';
        }
        
        if (info.phone) {
          html += '<div class="evapp-netglobal-grid-item"><b>📱 Teléfono</b><span>' + escapeHtml(info.phone) + '</span></div>';
        }
        
        if (info.localidad) {
          html += '<div class="evapp-netglobal-grid-item"><b>🎫 Localidad</b><span>' + escapeHtml(info.localidad) + '</span></div>';
        }
        
        html += '</div>';
        
        // Botones de acción
        html += '<div class="evapp-netglobal-actions">';
        html += '<button type="button" class="evapp-netglobal-btn evapp-netglobal-download" onclick="evappDownloadVCard(' + scannedTicketID + ')">📥 Descargar contacto (vCard)</button>';
        html += '<button type="button" class="evapp-netglobal-btn evapp-netglobal-back" onclick="evappScanAnother()">📱 Escanear otro QR</button>';
        html += '</div>';
        
        resultSection.innerHTML = html;
        authSection.style.display = 'none';
        scanSection.style.display = 'none';
        resultSection.style.display = 'block';
      }

      function logInteraction(){
        if (!readerTicketId || !scannedTicketID) return;

        fetch(ajaxURL, {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: new URLSearchParams({
            action: 'eventosapp_netglobal_log_interaction',
            _wpnonce: logNonce,
            event_id: eventID,
            reader_ticket_id: readerTicketId,
            read_ticket_id: scannedTicketID
          })
        });
      }

      function escapeHtml(text){
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }

      // ========== FUNCIONES GLOBALES ==========

      // Función global para descargar vCard
      window.evappDownloadVCard = function(ticketId){
        window.location.href = ajaxURL + '?action=eventosapp_netglobal_download_vcard&ticket_id=' + ticketId + '&_wpnonce=' + authNonce;
      };

      // Función global para escanear otro QR
      window.evappScanAnother = function(){
        // Obtener la URL base sin parámetros
        const baseUrl = window.location.origin + window.location.pathname;
        // Redirigir a URL limpia (mantiene la sesión en localStorage)
        window.location.href = baseUrl;
      };

      // Función global para escanear otro QR sin perder la sesión (no redirige)
      window.evappScanAnotherDirect = function(){
        console.log('Escaneando otro QR (sin redirigir)');
        resultSection.style.display = 'none';
        scanSection.style.display = 'block';
        smoothScrollTo(scanSection);
      };

      // ========== INICIAR ==========
      init();

    })();
    </script>
    <?php
    return ob_get_clean();
});

/**
 * AJAX: Identificar al usuario lector
 */
add_action('wp_ajax_eventosapp_netglobal_identify', 'eventosapp_netglobal_identify_handler');
add_action('wp_ajax_nopriv_eventosapp_netglobal_identify', 'eventosapp_netglobal_identify_handler');

function eventosapp_netglobal_identify_handler(){
    check_ajax_referer('eventosapp_netglobal_auth');

    $event_id  = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    $cc        = isset($_POST['cc']) ? sanitize_text_field($_POST['cc']) : '';
    $last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';

    if (!$event_id || !$cc || !$last_name) {
        wp_send_json_error(['message' => 'Datos incompletos']);
    }

    // Buscar ticket que coincida
    $args = [
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'   => '_eventosapp_ticket_evento_id',
                'value' => $event_id,
                'type'  => 'NUMERIC'
            ],
            [
                'key'     => '_eventosapp_asistente_cc',
                'value'   => $cc,
                'compare' => '='
            ],
        ]
    ];

    $query = new WP_Query($args);

    if (!$query->have_posts()) {
        wp_send_json_error(['message' => 'No se encontró registro con esa cédula en este evento']);
    }

    $ticket = $query->posts[0];
    $ticket_id = $ticket->ID;

    // Validar apellido usando el campo correcto (singular)
    $stored_last = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
    
    // Comparación flexible: normalizar y comparar
    $stored_last_normalized = strtolower(trim($stored_last));
    $input_last_normalized = strtolower(trim($last_name));
    
    if ($stored_last_normalized !== $input_last_normalized) {
        wp_send_json_error(['message' => 'El apellido no coincide']);
    }

    wp_send_json_success([
        'ticket_id' => $ticket_id,
        'message'   => 'Identificación exitosa'
    ]);
}

/**
 * AJAX: Obtener datos del ticket escaneado
 */
add_action('wp_ajax_eventosapp_netglobal_get_ticket_data', 'eventosapp_netglobal_get_ticket_data_handler');
add_action('wp_ajax_nopriv_eventosapp_netglobal_get_ticket_data', 'eventosapp_netglobal_get_ticket_data_handler');

function eventosapp_netglobal_get_ticket_data_handler(){
    check_ajax_referer('eventosapp_netglobal_auth');

    $ticket_id = isset($_POST['ticket_id']) ? absint($_POST['ticket_id']) : 0;

    if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
        wp_send_json_error(['message' => 'Ticket inválido']);
    }

    // Obtener datos del ticket
    $first_name = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
    $last_name  = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
    $email      = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
    $phone      = get_post_meta($ticket_id, '_eventosapp_asistente_tel', true);
    $company    = get_post_meta($ticket_id, '_eventosapp_asistente_empresa', true);
    $designation = get_post_meta($ticket_id, '_eventosapp_asistente_cargo', true);
    $localidad  = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);
    $foto_url   = get_post_meta($ticket_id, '_eventosapp_asistente_foto', true);

    $full_name = trim($first_name . ' ' . $last_name);

    wp_send_json_success([
        'full_name'   => $full_name ?: '(Sin nombre)',
        'email'       => $email ?: '',
        'phone'       => $phone ?: '',
        'company'     => $company ?: '',
        'designation' => $designation ?: '',
        'localidad'   => $localidad ?: '',
        'foto_url'    => $foto_url ?: ''
    ]);
}

/**
 * AJAX: Registrar interacción de networking
 */
add_action('wp_ajax_eventosapp_netglobal_log_interaction', 'eventosapp_netglobal_log_interaction_handler');
add_action('wp_ajax_nopriv_eventosapp_netglobal_log_interaction', 'eventosapp_netglobal_log_interaction_handler');

function eventosapp_netglobal_log_interaction_handler(){
    check_ajax_referer('eventosapp_netglobal_log');

    $event_id         = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    $reader_ticket_id = isset($_POST['reader_ticket_id']) ? absint($_POST['reader_ticket_id']) : 0;
    $read_ticket_id   = isset($_POST['read_ticket_id']) ? absint($_POST['read_ticket_id']) : 0;

    if (!$event_id || !$reader_ticket_id || !$read_ticket_id) {
        wp_send_json_error(['message' => 'Datos incompletos']);
    }

    // Verificar que la función de logging existe
    if (!function_exists('eventosapp_net2_log_interaction')) {
        wp_send_json_success(['message' => 'Sistema de logging no disponible']);
    }

    // Registrar la interacción usando el sistema existente
    $result = eventosapp_net2_log_interaction($event_id, $reader_ticket_id, $read_ticket_id);

    if ($result) {
        wp_send_json_success(['message' => 'Interacción registrada']);
    } else {
        wp_send_json_success(['message' => 'Interacción ya registrada anteriormente']);
    }
}

/**
 * AJAX: Descargar vCard del asistente
 */
add_action('wp_ajax_eventosapp_netglobal_download_vcard', 'eventosapp_netglobal_download_vcard_handler');
add_action('wp_ajax_nopriv_eventosapp_netglobal_download_vcard', 'eventosapp_netglobal_download_vcard_handler');

function eventosapp_netglobal_download_vcard_handler(){
    check_ajax_referer('eventosapp_netglobal_auth');

    $ticket_id = isset($_GET['ticket_id']) ? absint($_GET['ticket_id']) : 0;

    if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
        wp_die('Ticket inválido');
    }

    // Obtener datos del ticket
    $first_name  = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
    $last_name   = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
    $email       = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
    $phone       = get_post_meta($ticket_id, '_eventosapp_asistente_tel', true);
    $company     = get_post_meta($ticket_id, '_eventosapp_asistente_empresa', true);
    $designation = get_post_meta($ticket_id, '_eventosapp_asistente_cargo', true);

    $full_name = trim($first_name . ' ' . $last_name);

    // Generar vCard
    $vcard = "BEGIN:VCARD\r\n";
    $vcard .= "VERSION:3.0\r\n";
    $vcard .= "FN:" . $full_name . "\r\n";
    $vcard .= "N:" . $last_name . ";" . $first_name . ";;;\r\n";
    
    if ($email) {
        $vcard .= "EMAIL;TYPE=INTERNET:" . $email . "\r\n";
    }
    
    if ($phone) {
        $vcard .= "TEL;TYPE=CELL:" . $phone . "\r\n";
    }
    
    if ($company) {
        $vcard .= "ORG:" . $company . "\r\n";
    }
    
    if ($designation) {
        $vcard .= "TITLE:" . $designation . "\r\n";
    }
    
    $vcard .= "END:VCARD\r\n";

    // Enviar headers para descarga
    $filename = sanitize_file_name($full_name ?: 'contacto') . '.vcf';
    
    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($vcard));
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo $vcard;
    exit;
}

/**
 * AJAX: Decodificar QR y obtener ticket ID
 */
add_action('wp_ajax_eventosapp_netglobal_decode_qr', 'eventosapp_netglobal_decode_qr_handler');
add_action('wp_ajax_nopriv_eventosapp_netglobal_decode_qr', 'eventosapp_netglobal_decode_qr_handler');

function eventosapp_netglobal_decode_qr_handler(){
    check_ajax_referer('eventosapp_netglobal_auth');
    global $wpdb;

    $qr_content = isset($_POST['qr_content']) ? sanitize_text_field($_POST['qr_content']) : '';

    if (empty($qr_content)) {
        wp_send_json_error(['message' => 'Contenido QR vacío']);
    }

    error_log('EventosApp Networking Global - Decodificando QR: ' . $qr_content);

    if (class_exists('EventosApp_QR_Manager')) {
        $validation = EventosApp_QR_Manager::validate_qr($qr_content);
        if (!empty($validation['valid']) && !empty($validation['ticket_id'])) {
            $ticket_id = (int) $validation['ticket_id'];
            $event_id  = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);

            error_log('EventosApp Networking Global - QR validado con QR Manager. Ticket ID: ' . $ticket_id . ', tipo: ' . ($validation['type'] ?? ''));

            wp_send_json_success([
                'ticket_id' => $ticket_id,
                'event_id'  => $event_id,
                'qr_type'   => isset($validation['type']) ? sanitize_key($validation['type']) : '',
                'qr_type_label' => isset($validation['type_label']) ? sanitize_text_field($validation['type_label']) : '',
                'message'   => 'QR validado exitosamente'
            ]);
        }
    }

    // Fallback 1: formato badge/networking: 14564-ticketid=TKT-ABC-123-5389
    if (preg_match('/^(\d+)-ticketid=(.+)-([^-]+)$/', $qr_content, $matches)) {
        $event_id         = absint($matches[1]);
        $unique_ticket_id = sanitize_text_field($matches[2]);
        $security_code    = sanitize_text_field($matches[3]);

        error_log('EventosApp Networking Global - Fallback badge: event=' . $event_id . ', ticket=' . $unique_ticket_id . ', security=' . $security_code);

        if (empty($unique_ticket_id)) {
            wp_send_json_error(['message' => 'Ticket ID no encontrado en QR']);
        }

        $scanned_ticket_post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = 'eventosapp_ticketID'
             AND meta_value = %s
             LIMIT 1",
            $unique_ticket_id
        ));

        if (!$scanned_ticket_post_id || get_post_type($scanned_ticket_post_id) !== 'eventosapp_ticket') {
            wp_send_json_error(['message' => 'Ticket no encontrado']);
        }

        $stored_security_code = get_post_meta($scanned_ticket_post_id, '_eventosapp_badge_security_code', true);
        if (empty($stored_security_code) || $stored_security_code !== $security_code) {
            wp_send_json_error(['message' => 'Código de seguridad inválido']);
        }

        $ticket_event_id = (int) get_post_meta($scanned_ticket_post_id, '_eventosapp_ticket_evento_id', true);
        if ($ticket_event_id !== $event_id) {
            wp_send_json_error(['message' => 'Este ticket no pertenece al evento indicado']);
        }

        wp_send_json_success([
            'ticket_id' => (int) $scanned_ticket_post_id,
            'event_id'  => $event_id,
            'qr_type'   => 'badge',
            'qr_type_label' => 'Escarapela Impresa',
            'message'   => 'QR validado exitosamente'
        ]);
    }

    // Fallback 2: QR simple por medio, incluido WhatsApp.
    if (preg_match('/^(.+)-(email|gwallet|awallet|pdf|whatsapp)$/i', $qr_content, $matches)) {
        $unique_ticket_id = sanitize_text_field($matches[1]);
        $tag = strtolower($matches[2]);
        $type_labels = [
            'email'    => ['type' => 'email', 'label' => 'Email'],
            'gwallet'  => ['type' => 'google_wallet', 'label' => 'Google Wallet'],
            'awallet'  => ['type' => 'apple_wallet', 'label' => 'Apple Wallet'],
            'pdf'      => ['type' => 'pdf', 'label' => 'PDF Impreso'],
            'whatsapp' => ['type' => 'whatsapp', 'label' => 'WhatsApp'],
        ];

        $scanned_ticket_post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = 'eventosapp_ticketID'
             AND meta_value = %s
             LIMIT 1",
            $unique_ticket_id
        ));

        if (!$scanned_ticket_post_id || get_post_type($scanned_ticket_post_id) !== 'eventosapp_ticket') {
            wp_send_json_error(['message' => 'Ticket no encontrado']);
        }

        $event_id = (int) get_post_meta($scanned_ticket_post_id, '_eventosapp_ticket_evento_id', true);
        $type_info = $type_labels[$tag] ?? ['type' => $tag, 'label' => $tag];

        wp_send_json_success([
            'ticket_id' => (int) $scanned_ticket_post_id,
            'event_id'  => $event_id,
            'qr_type'   => $type_info['type'],
            'qr_type_label' => $type_info['label'],
            'message'   => 'QR validado exitosamente'
        ]);
    }

    error_log('EventosApp Networking Global - Formato QR inválido');
    wp_send_json_error(['message' => 'Formato de QR inválido']);
}
