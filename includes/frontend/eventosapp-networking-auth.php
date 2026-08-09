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
    $dashboard_url = function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/');
    $event_title   = get_the_title($event_id) ?: 'Evento';

    ob_start(); ?>
    <style>
      .evapp-net-shell{
        --evapp-primary:#3279bd;
        --evapp-primary-dark:#255f96;
        --evapp-primary-soft:#eaf4ff;
        --evapp-app-bg:#f5f8fc;
        --evapp-surface:#fff;
        --evapp-border:#dfe7f1;
        --evapp-text:#182230;
        --evapp-muted:#64748b;
        --evapp-success:#15803d;
        --evapp-danger:#b42318;
        width:100%;
        max-width:760px;
        margin:0 auto;
        padding:0 10px;
        color:var(--evapp-text);
        font-family:inherit;
        line-height:1.45;
      }
      .evapp-net-shell,.evapp-net-shell *{box-sizing:border-box}
      .evapp-net-backbar{display:flex;align-items:center;justify-content:flex-start;margin:0 0 12px}
      .evapp-net-back{
        display:inline-flex;align-items:center;gap:8px;min-height:40px;padding:9px 13px;
        color:var(--evapp-primary)!important;background:var(--evapp-primary-soft);border:1px solid #cfe3f6;
        border-radius:12px;text-decoration:none!important;font-size:13px;font-weight:750;transition:.18s ease;
      }
      .evapp-net-back:hover{color:#fff!important;background:var(--evapp-primary);border-color:var(--evapp-primary);transform:translateY(-1px)}
      .evapp-net-back svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2.2}
      .evapp-net-card{
        padding:clamp(18px,4vw,30px);background:var(--evapp-app-bg);border:1px solid var(--evapp-border);
        border-radius:26px;box-shadow:0 18px 50px rgba(31,52,73,.08);color:var(--evapp-text);
      }
      .evapp-net-hero{display:grid;grid-template-columns:auto minmax(0,1fr);align-items:center;gap:14px;margin-bottom:18px}
      .evapp-net-hero-icon{
        display:flex;align-items:center;justify-content:center;width:54px;height:54px;color:#fff;
        background:linear-gradient(145deg,var(--evapp-primary),var(--evapp-primary-dark));border-radius:16px;
        box-shadow:0 8px 18px rgba(47,115,181,.22);
      }
      .evapp-net-hero-icon svg{width:27px;height:27px}
      .evapp-net-eyebrow{margin:0 0 3px;color:var(--evapp-primary);font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
      .evapp-net-title{margin:0;color:var(--evapp-text);font-weight:850;font-size:clamp(1.2rem,4vw,1.55rem);letter-spacing:-.02em;line-height:1.2}
      .evapp-net-event{margin:5px 0 0;color:var(--evapp-muted);font-size:.9rem}
      #evappIdentStep,#evappScanStep{padding:clamp(16px,3vw,22px);background:var(--evapp-surface);border:1px solid var(--evapp-border);border-radius:18px;box-shadow:0 8px 24px rgba(31,52,73,.045)}
      .evapp-net-field{margin:0 0 16px}
      .evapp-net-field label{display:block;margin-bottom:7px;color:var(--evapp-text);font-size:.92rem;font-weight:750}
      .evapp-net-field small{display:block!important;margin-top:6px!important;color:var(--evapp-muted)!important;font-size:.82rem!important;line-height:1.35!important}
      .evapp-net-input{
        width:100%;min-height:46px;padding:11px 13px;border:1px solid var(--evapp-border);border-radius:12px;
        background:#fff;color:var(--evapp-text);font:inherit;font-size:16px;transition:border-color .18s ease,box-shadow .18s ease;
      }
      .evapp-net-input:focus{outline:none;border-color:var(--evapp-primary);box-shadow:0 0 0 4px rgba(50,121,189,.13)}
      .evapp-net-btn,.evapp-qr-btn-secondary,.evapp-qr-btn-outline{
        display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:46px;padding:11px 16px;
        border-radius:12px;font:inherit;font-weight:800;cursor:pointer;text-align:center;text-decoration:none!important;transition:.18s ease;
      }
      .evapp-net-btn,.evapp-qr-btn-secondary{border:1px solid var(--evapp-primary);background:var(--evapp-primary)!important;color:#fff!important;box-shadow:0 7px 16px rgba(47,115,181,.18)}
      .evapp-net-btn:hover,.evapp-qr-btn-secondary:hover{background:var(--evapp-primary-dark)!important;border-color:var(--evapp-primary-dark);transform:translateY(-1px)}
      .evapp-net-btn:disabled{opacity:.62;cursor:not-allowed;transform:none}
      .evapp-net-btn.is-live{background:var(--evapp-danger)!important;border-color:var(--evapp-danger)}
      .evapp-net-help{color:var(--evapp-muted);font-size:.88rem;line-height:1.45;margin-top:10px}
      .evapp-net-bad,.evapp-net-ok{display:block;margin-top:12px;padding:11px 13px;border-radius:12px;font-size:.88rem;font-weight:750;text-align:center}
      .evapp-net-bad{color:var(--evapp-danger);background:#fff1f0;border:1px solid #f4c7c3}
      .evapp-net-ok{color:var(--evapp-success);background:#edf9f0;border:1px solid #c9e8d1}
      .evapp-qr-video-wrap{position:relative;margin-top:14px;border-radius:16px;overflow:hidden;background:#0b1020;aspect-ratio:3/4;display:none;box-shadow:inset 0 0 0 1px rgba(255,255,255,.08)}
      .evapp-qr-video{width:100%;height:100%;object-fit:cover;display:none}
      .evapp-qr-frame{position:absolute;inset:0;pointer-events:none;display:none}
      .evapp-qr-frame .mask{position:absolute;inset:0;background:radial-gradient(ellipse 60% 40% at 50% 50%,rgba(255,255,255,0) 62%,rgba(10,15,29,.55) 64%)}
      .evapp-qr-corner{position:absolute;width:44px;height:44px;border:4px solid #67a9e7;border-radius:10px}
      .evapp-qr-corner.tl{top:16px;left:16px;border-right:0;border-bottom:0}.evapp-qr-corner.tr{top:16px;right:16px;border-left:0;border-bottom:0}
      .evapp-qr-corner.bl{bottom:16px;left:16px;border-right:0;border-top:0}.evapp-qr-corner.br{bottom:16px;right:16px;border-left:0;border-top:0}
      .evapp-qr-video-wrap.is-immersive{aspect-ratio:auto;height:min(76vh,720px);width:100%;display:block}
      .evapp-qr-result{margin-top:14px;padding:14px;background:#f8fbff;border:1px solid var(--evapp-border);border-radius:14px;color:var(--evapp-text)}
      .evapp-qr-actions{display:grid;grid-template-columns:1fr;gap:10px;margin-top:14px}
      .evapp-qr-grid{display:grid;grid-template-columns:1fr;gap:8px;margin-top:10px}
      .evapp-qr-grid>div{min-width:0;overflow-wrap:anywhere}.evapp-qr-grid div b{color:var(--evapp-muted);font-weight:700}
      .evapp-qr-btn-outline{margin-top:0;border:1px solid var(--evapp-border)!important;background:#fff!important;color:var(--evapp-text)!important;box-shadow:none}
      .evapp-qr-btn-outline:hover{border-color:#b9d4ed!important;background:var(--evapp-primary-soft)!important;color:var(--evapp-primary)!important}
      @media(min-width:560px){.evapp-qr-grid{grid-template-columns:minmax(100px,auto) minmax(0,1fr)}.evapp-qr-grid div{display:contents}.evapp-qr-grid b{text-align:right}.evapp-qr-actions{grid-template-columns:1fr 1fr}}
      @media(max-width:520px){.evapp-net-shell{padding:0}.evapp-net-card{border-radius:20px;padding:16px}.evapp-net-hero{grid-template-columns:1fr}.evapp-net-hero-icon{width:48px;height:48px}.evapp-net-back{width:100%;justify-content:center}.evapp-qr-video-wrap.is-immersive{height:68vh}}
      @media(prefers-reduced-motion:reduce){.evapp-net-shell *{scroll-behavior:auto!important;transition:none!important}}
    </style>

    <div class="evapp-net-shell"
         data-event="<?php echo esc_attr($event_id); ?>"
         data-ident-nonce="<?php echo esc_attr($nonce_ident); ?>"
         data-log-nonce="<?php echo esc_attr($nonce_log); ?>">

      <div class="evapp-net-backbar">
        <a class="evapp-net-back" href="<?php echo esc_url($dashboard_url); ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
          Volver al dashboard
        </a>
      </div>

      <div class="evapp-net-card">
        <div class="evapp-net-hero">
          <span class="evapp-net-hero-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none"><path d="M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6zm14 0h2v6h-6v-2h4v-4z" stroke="currentColor" stroke-width="1.8"/></svg>
          </span>
          <div>
            <p class="evapp-net-eyebrow">EventosApp · Networking</p>
            <h2 class="evapp-net-title">Doble autenticación</h2>
            <p class="evapp-net-event"><?php echo esc_html($event_title); ?></p>
          </div>
        </div>

        <!-- Paso 1: Identidad -->
        <div id="evappIdentStep">
          <div class="evapp-net-field">
            <label>Cédula</label>
            <input type="text" id="evappIdentCC" class="evapp-net-input" placeholder="Ej: 1020304050" inputmode="numeric" autocomplete="off">
            <small style="display:block;margin-top:6px;color:#a9b6d3;font-size:0.85rem;line-height:1.3;">
              Escribe tal cual como está en tu inscripción.
            </small>
          </div>
          <div class="evapp-net-field">
            <label>Apellidos</label>
            <input type="text" id="evappIdentLast" class="evapp-net-input" placeholder="Ej: Pérez García" autocomplete="family-name">
            <small style="display:block;margin-top:6px;color:#a9b6d3;font-size:0.85rem;line-height:1.3;">
              Escribe tal cual como están en tu inscripción.
            </small>
          </div>
          <button type="button" id="evappIdentBtn" class="evapp-net-btn">Confirmar identidad</button>
          <div id="evappIdentMsg" class="evapp-net-help" aria-live="polite" style="margin-top:12px;text-align:center;">Para iniciar a escanear un QR debes autenticarte.</div>
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

          <div class="evapp-qr-result" id="evappResultNet" aria-live="polite">
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
      let stream=null, running=false, lastScan="", lastAt=0, lastFrameAt=0;
      const MAX_SCAN_WIDTH = 960;
      const SCAN_INTERVAL_MS = 90;
      let barcodeDetector = ('BarcodeDetector' in window) ? new window.BarcodeDetector({formats:['qr_code']}) : null;

      function setIdentMsg(html, good=false){
        msgIdent.innerHTML = html;
        msgIdent.className = good ? 'evapp-net-ok' : 'evapp-net-bad';
      }
      function setScanOutput(html){ out.innerHTML = html; }
      function escapeHtml(value){ const el=document.createElement('div'); el.textContent=String(value ?? ''); return el.innerHTML; }
      function row(label,value){ return `<div><b>${escapeHtml(label)}:</b></div><div>${escapeHtml(value || '-')}</div>`; }
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
        if (!ccVal || !lastVal) { setIdentMsg('Completa cédula y apellidos.'); return; }

        const fd = new FormData();
        fd.append('action',   'eventosapp_net2_identify');
        fd.append('security', identNonce);
        fd.append('event_id', String(eventID));
        fd.append('cc', ccVal);
        fd.append('last', lastVal);

        msgIdent.className = 'evapp-net-help';
        msgIdent.innerHTML = 'Validando…';
        btnIdent.disabled = true;

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
          .catch(()=> setIdentMsg('Error de red.'))
          .finally(()=>{ btnIdent.disabled = false; });
      });

      [cc, last].forEach((input)=>{
        input?.addEventListener('keydown', (event)=>{
          if (event.key === 'Enter') { event.preventDefault(); btnIdent.click(); }
        });
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
        const sourceWidth = video.videoWidth || 640;
        const sourceHeight = video.videoHeight || 480;
        const scale = Math.min(1, MAX_SCAN_WIDTH / sourceWidth);
        cvs.width  = Math.max(320, Math.round(sourceWidth * scale));
        cvs.height = Math.max(240, Math.round(sourceHeight * scale));
        running=true; lastFrameAt=0; setLiveUI(true);
        requestAnimationFrame(tick);
      }
      async function tick(timestamp){
        if (!running) return;
        if (timestamp && lastFrameAt && (timestamp - lastFrameAt) < SCAN_INTERVAL_MS){
          requestAnimationFrame(tick);
          return;
        }
        lastFrameAt = timestamp || performance.now();
        ctx.drawImage(video,0,0,cvs.width,cvs.height);
        if (barcodeDetector){
          let bmp = null;
          try{
            bmp = await createImageBitmap(cvs);
            const codes = await barcodeDetector.detect(bmp);
            if (codes && codes.length){ onScan(normalizeRaw(codes[0].rawValue||'')); return; }
          }catch(e){}
          finally{ if (bmp && typeof bmp.close === 'function') bmp.close(); }
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

      function buildVCF(d){
        // vCard 3.0 simple y compatible (Android/iOS)
        const safe = s => (String(s||'').replace(/\n+/g,' ').trim());
        const first = safe(d.first_name), last = safe(d.last_name);
        const full  = safe(d.full_name || (first + ' ' + last)).trim();
        const org   = safe(d.company);
        const title = safe(d.designation);
        const email = safe(d.email);
        const tel   = safe(d.phone);

        let v = '';
        v += 'BEGIN:VCARD\n';
        v += 'VERSION:3.0\n';
        v += 'N:'+(last)+';'+(first)+';;;\n';
        v += 'FN:'+(full||'Contacto EventosApp')+'\n';
        if (org)   v += 'ORG:'+org+'\n';
        if (title) v += 'TITLE:'+title+'\n';
        if (tel)   v += 'TEL;TYPE=CELL:'+tel+'\n';
        if (email) v += 'EMAIL;TYPE=INTERNET:'+email+'\n';
        v += 'PRODID:-//EventosApp//Networking//ES\n';
        v += 'END:VCARD\n';
        return v;
      }

      function onScan(data){
        const now = Date.now();
        if (data === lastScan && (now - lastAt) < 2500){ requestAnimationFrame(tick); return; }
        lastScan=data; lastAt=now; beep(); stop();

        setScanOutput('<div class="evapp-net-help">Procesando: '+ escapeHtml(data) +'…</div>');
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
            // Panel de éxito + datos del contacto
            let html = '<div class="evapp-net-ok">✔ Conexión registrada</div>';
            html += '<div class="evapp-qr-grid">';
            html += row('Nombre', d.full_name);
            html += row('Empresa', d.company);
            html += row('Cargo', d.designation);
            html += '</div>';

            // === Botones: Descargar contacto (.vcf) + Escanear otro ===
            // Creamos vCard al vuelo
            try{
              const vcf = buildVCF(d);
              const blob = new Blob([vcf], {type:'text/vcard;charset=utf-8'});
              const url  = URL.createObjectURL(blob);
              const fname = (d.full_name ? d.full_name.replace(/[^\w\-]+/g,'_') : 'contacto') + '.vcf';
              html += '<div class="evapp-qr-actions">';
              html +=   '<a id="evappVCFLink" class="evapp-qr-btn-secondary" href="'+url+'" download="'+fname+'">Descargar contacto (.vcf)</a>';
              html +=   '<button id="evappScanAgainInline" type="button" class="evapp-qr-btn-outline">Escanear otro QR</button>';
              html += '</div>';
              setScanOutput(html);
              // Bind del botón inline (además del que inyectamos como fallback)
              const againInline = document.getElementById('evappScanAgainInline');
              againInline?.addEventListener('click', async ()=>{
                await ensureJsQR(); await start();
                setScanOutput('<div class="evapp-net-help">Tip: coloca el QR dentro del marco.</div>');
              });
            }catch(e){
              // Si algo falla, mostramos solo los datos y el botón clásico de reintentar
              setScanOutput(html);
              injectScanAgainButton();
            }

            smoothScrollTo(out);
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
 * =========  BLOQUE DE FUNCIONES DEL MÓDULO DE NETWORKING  =============
 * =====================================================================*/

/**
 * Funciones de Networking (doble autenticación)
 * - Creación de tabla
 * - Resolución de tickets (scanned / cc+apellido)
 * - Registro de interacciones
 * - Métricas por localidad
 * - Envío de resumen post-evento (cron + manual)
 */

if ( ! function_exists('eventosapp_net2_table_name_internal') ) {
    function eventosapp_net2_table_name_internal(){
        global $wpdb;
        return $wpdb->prefix . 'eventosapp_networking';
    }
}

if ( ! function_exists('eventosapp_net2_maybe_create_table') ) {
    function eventosapp_net2_maybe_create_table(){
        global $wpdb;
        $table = eventosapp_net2_table_name_internal();

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME, $table
        ) );
        if ($exists) return;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            event_id BIGINT(20) UNSIGNED NOT NULL,
            reader_ticket_id BIGINT(20) UNSIGNED NOT NULL,
            read_ticket_id   BIGINT(20) UNSIGNED NOT NULL,
            reader_localidad VARCHAR(100) DEFAULT NULL,
            read_localidad   VARCHAR(100) DEFAULT NULL,
            ip  VARCHAR(45)  DEFAULT NULL,
            ua  VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            KEY reader_ticket_id (reader_ticket_id),
            KEY read_ticket_id (read_ticket_id),
            KEY event_reader_read (event_id, reader_ticket_id, read_ticket_id)
        ) {$charset_collate};";

        dbDelta($sql);
    }
    add_action('init', 'eventosapp_net2_maybe_create_table');
}

if ( ! function_exists('eventosapp_net2_get_ticket_by_cc_last_event') ) {
    function eventosapp_net2_get_ticket_by_cc_last_event($event_id, $cc, $last){
        global $wpdb;
        if (!$event_id || !$cc || !$last) return 0;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            '_eventosapp_asistente_cc', $cc
        ) );
        if (!$ids) return 0;

        $low_last = mb_strtolower($last, 'UTF-8');
        foreach ($ids as $cand){
            $cand = (int) $cand;
            if ((int) get_post_meta($cand, '_eventosapp_ticket_evento_id', true) !== (int) $event_id) continue;
            $db_last = (string) get_post_meta($cand, '_eventosapp_asistente_apellido', true);
            if ( mb_strtolower($db_last, 'UTF-8') === $low_last ) return $cand;
        }

        // Búsqueda normalizada
        $norm = function($s){
            $s = strtolower(trim($s));
            $s = iconv('UTF-8','ASCII//TRANSLIT', $s);
            $s = preg_replace('/\s+/', '', $s);
            return $s;
        };
        $needle = $norm($last);
        foreach ($ids as $cand){
            $cand = (int) $cand;
            if ((int) get_post_meta($cand, '_eventosapp_ticket_evento_id', true) !== (int) $event_id) continue;
            $db_last = (string) get_post_meta($cand, '_eventosapp_asistente_apellido', true);
            if ( strpos($norm($db_last), $needle) !== false ) return $cand;
        }
        return 0;
    }
}

if ( ! function_exists('eventosapp_net2_normalize_scanned') ) {
    function eventosapp_net2_normalize_scanned($raw){
        $s = trim((string)$raw);
        if ($s === '') return '';

        if (strpos($s, 'http://') === 0 || strpos($s, 'https://') === 0) {
            $parts = wp_parse_url($s);
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $params);
                if (!empty($params['event'])) {
                    return sanitize_text_field($params['event']);
                }
            }
            if (!empty($parts['path'])) {
                $s = basename($parts['path']);
            }
        } elseif (strpos($s, '/') !== false) {
            $s = basename($s);
        }

        $s = preg_replace('/\.(png|jpg|jpeg|pdf)$/i','', $s);
        $s = preg_replace('/-tn$/i','', $s);
        $s = ltrim($s, '#');
        return sanitize_text_field($s);
    }
}

if ( ! function_exists('eventosapp_net2_find_ticket_by_scanned') ) {
    function eventosapp_net2_find_ticket_by_scanned($event_id, $scanned){
        global $wpdb;
        $event_id = (int) $event_id;
        $raw = trim((string) $scanned);
        $normalized = eventosapp_net2_normalize_scanned($raw);
        if ((!$raw && !$normalized) || !$event_id) return 0;

        $candidates = array_values(array_unique(array_filter(array($raw, $normalized))));

        if (class_exists('EventosApp_QR_Manager')) {
            foreach ($candidates as $candidate_raw) {
                $validation = EventosApp_QR_Manager::validate_qr($candidate_raw);
                if (!empty($validation['valid']) && !empty($validation['ticket_id'])) {
                    $candidate_id = (int) $validation['ticket_id'];
                    if ((int) get_post_meta($candidate_id, '_eventosapp_ticket_evento_id', true) === $event_id) {
                        return $candidate_id;
                    }
                }
            }
        }

        $lookup_values = $candidates;
        foreach ($candidates as $candidate_raw) {
            if (preg_match('/^(.+)-(email|gwallet|awallet|pdf|whatsapp)$/i', $candidate_raw, $m)) {
                $lookup_values[] = sanitize_text_field($m[1]);
            }
            if (preg_match('/^(\d+)-ticketid=(.+)-([^-]+)$/', $candidate_raw, $m)) {
                $lookup_values[] = sanitize_text_field($m[2]);
            }
        }
        $lookup_values = array_values(array_unique(array_filter($lookup_values)));

        foreach ($lookup_values as $lookup) {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s",
                'eventosapp_ticketID', $lookup
            ) );
            if ($ids) {
                foreach ($ids as $cand){
                    if ((int) get_post_meta($cand, '_eventosapp_ticket_evento_id', true) === $event_id)
                        return (int)$cand;
                }
            }
        }

        // QR preimpresos
        $allow_preprinted = ( get_post_meta($event_id, '_eventosapp_ticket_use_preprinted_qr_networking', true) === '1' );
        if ($allow_preprinted) {
            $num = preg_replace('/\D+/', '', $normalized ?: $raw);
            if ($num !== '') {
                $ids2 = $wpdb->get_col( $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s",
                    'eventosapp_ticket_preprintedID', $num
                ) );
                if ($ids2) {
                    foreach ($ids2 as $cand){
                        if ((int) get_post_meta($cand, '_eventosapp_ticket_evento_id', true) === $event_id)
                            return (int)$cand;
                    }
                }
            }
        }
        return 0;
    }
}

if ( ! function_exists('eventosapp_net2_contact_payload') ) {
    function eventosapp_net2_contact_payload($ticket_id){
        $first = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
        $last  = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
        $comp  = get_post_meta($ticket_id, '_eventosapp_asistente_empresa', true);
        $role  = get_post_meta($ticket_id, '_eventosapp_asistente_cargo', true);
        $loc   = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);

        $email = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
        if (!$email) $email = get_post_meta($ticket_id, '_eventosapp_asistente_correo', true);

        $phone = get_post_meta($ticket_id, '_eventosapp_asistente_telefono', true);
        if (!$phone) $phone = get_post_meta($ticket_id, '_eventosapp_asistente_celular', true);
        if (!$phone) $phone = get_post_meta($ticket_id, '_eventosapp_asistente_movil', true);
        if (!$phone) $phone = get_post_meta($ticket_id, '_eventosapp_asistente_phone', true);

        return [
            'ticket_id'   => (int)$ticket_id,
            'first_name'  => $first,
            'last_name'   => $last,
            'full_name'   => trim($first.' '.$last),
            'company'     => $comp,
            'designation' => $role,
            'localidad'   => $loc,
            'email'       => $email,
            'phone'       => $phone,
        ];
    }
}

if ( ! function_exists('eventosapp_net2_record_interaction') ) {
    function eventosapp_net2_record_interaction($event_id, $reader_ticket_id, $scanned, $ctx = []){
        global $wpdb;
        eventosapp_net2_maybe_create_table();

        $reader_ticket_id = (int) $reader_ticket_id;
        $read_ticket_id   = eventosapp_net2_find_ticket_by_scanned($event_id, $scanned);
        if ( ! $read_ticket_id ) {
            return new WP_Error('not_found', 'No se encontró el asistente para ese QR en este evento.');
        }

        $ev_read = (int) get_post_meta($read_ticket_id, '_eventosapp_ticket_evento_id', true);
        if ($ev_read !== (int) $event_id) {
            return new WP_Error('invalid_event', 'El ticket leído no pertenece a este evento.');
        }
        if ($read_ticket_id === $reader_ticket_id) {
            return new WP_Error('self_scan', 'No puedes escanear tu propio QR.');
        }

        $table = eventosapp_net2_table_name_internal();
        $recent = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE event_id=%d AND reader_ticket_id=%d AND read_ticket_id=%d
               AND created_at > (NOW() - INTERVAL 10 SECOND)
             LIMIT 1",
            $event_id, $reader_ticket_id, $read_ticket_id
        ) );

        $reader_loc = get_post_meta($reader_ticket_id, '_eventosapp_asistente_localidad', true);
        $read_loc   = get_post_meta($read_ticket_id,   '_eventosapp_asistente_localidad', true);

        if ( ! $recent ) {
            $wpdb->insert($table, [
                'event_id'         => (int)$event_id,
                'reader_ticket_id' => (int)$reader_ticket_id,
                'read_ticket_id'   => (int)$read_ticket_id,
                'reader_localidad' => $reader_loc,
                'read_localidad'   => $read_loc,
                'ip'               => isset($ctx['ip']) ? substr($ctx['ip'], 0, 45) : null,
                'ua'               => isset($ctx['ua']) ? substr($ctx['ua'], 0, 255) : null,
            ], ['%d','%d','%d','%s','%s','%s','%s']);
        }

        update_post_meta($reader_ticket_id, '_eventosapp_networking_used', 1);
        update_post_meta($read_ticket_id,   '_eventosapp_networking_used', 1);

        if ( function_exists('eventosapp_net2_maybe_schedule_event_digest') )
            eventosapp_net2_maybe_schedule_event_digest($event_id);

        return eventosapp_net2_contact_payload($read_ticket_id);
    }
}

/**
 * Registrar interacción directa (sin escanear QR)
 * Función wrapper para networking-global que recibe directamente los IDs de tickets
 * 
 * @param int $event_id ID del evento
 * @param int $reader_ticket_id ID del ticket del lector
 * @param int $read_ticket_id ID del ticket leído
 * @return bool True si se registró correctamente, false si ya existía
 */
if ( ! function_exists('eventosapp_net2_log_interaction') ) {
    function eventosapp_net2_log_interaction($event_id, $reader_ticket_id, $read_ticket_id){
        global $wpdb;
        
        // Validar parámetros
        $event_id = (int) $event_id;
        $reader_ticket_id = (int) $reader_ticket_id;
        $read_ticket_id = (int) $read_ticket_id;
        
        if (!$event_id || !$reader_ticket_id || !$read_ticket_id) {
            return false;
        }
        
        // Validar que no sea auto-escaneo
        if ($reader_ticket_id === $read_ticket_id) {
            return false;
        }
        
        // Validar que ambos tickets existan y pertenezcan al evento
        $reader_event = (int) get_post_meta($reader_ticket_id, '_eventosapp_ticket_evento_id', true);
        $read_event = (int) get_post_meta($read_ticket_id, '_eventosapp_ticket_evento_id', true);
        
        if ($reader_event !== $event_id || $read_event !== $event_id) {
            return false;
        }
        
        // Asegurar que la tabla existe
        eventosapp_net2_maybe_create_table();
        
        // Obtener nombre de la tabla
        if (!function_exists('eventosapp_net2_table_name_internal')) {
            return false;
        }
        $table = eventosapp_net2_table_name_internal();
        
        // Verificar si ya existe una interacción reciente (últimos 10 segundos)
        $recent = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE event_id=%d AND reader_ticket_id=%d AND read_ticket_id=%d
               AND created_at > (NOW() - INTERVAL 10 SECOND)
             LIMIT 1",
            $event_id, $reader_ticket_id, $read_ticket_id
        ) );
        
        // Si ya existe, retornar false
        if ($recent) {
            return false;
        }
        
        // Obtener localidades
        $reader_localidad = get_post_meta($reader_ticket_id, '_eventosapp_asistente_localidad', true);
        $read_localidad = get_post_meta($read_ticket_id, '_eventosapp_asistente_localidad', true);
        
        // Obtener IP y User Agent
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
        
        // Insertar la interacción
        $inserted = $wpdb->insert($table, [
            'event_id'         => $event_id,
            'reader_ticket_id' => $reader_ticket_id,
            'read_ticket_id'   => $read_ticket_id,
            'reader_localidad' => $reader_localidad,
            'read_localidad'   => $read_localidad,
            'ip'               => substr($ip, 0, 45),
            'ua'               => substr($ua, 0, 255),
        ], ['%d','%d','%d','%s','%s','%s','%s']);
        
        // Si se insertó correctamente
        if ($inserted) {
            // Marcar que ambos tickets usaron networking
            update_post_meta($reader_ticket_id, '_eventosapp_networking_used', 1);
            update_post_meta($read_ticket_id, '_eventosapp_networking_used', 1);
            
            // Programar envío de digest si existe la función
            if (function_exists('eventosapp_net2_maybe_schedule_event_digest')) {
                eventosapp_net2_maybe_schedule_event_digest($event_id);
            }
            
            return true;
        }
        
        return false;
    }
}


/* ===================  DIGEST Y MÉTRICAS (PROTEGIDAS CONTRA DUPLICADOS) =================== */

if ( ! function_exists('eventosapp_net2_get_event_last_day') ) {
    function eventosapp_net2_get_event_last_day($event_id){
        if ( function_exists('eventosapp_get_event_days') ) {
            $days = (array) eventosapp_get_event_days($event_id);
            if ($days){
                sort($days);
                return end($days);
            }
        }
        $tipo = get_post_meta($event_id, '_eventosapp_tipo_fecha', true);
        if ($tipo === 'unica') {
            $f = get_post_meta($event_id, '_eventosapp_fecha_unica', true);
            return $f ? $f : gmdate('Y-m-d');
        }
        if ($tipo === 'consecutiva') {
            $fin = get_post_meta($event_id, '_eventosapp_fecha_fin', true);
            return $fin ? $fin : gmdate('Y-m-d');
        }
        return gmdate('Y-m-d');
    }
}

if ( ! function_exists('eventosapp_net2_event_timezone') ) {
    function eventosapp_net2_event_timezone($event_id){
        $tz = get_post_meta($event_id, '_eventosapp_zona_horaria', true);
        if (!$tz) {
            $tz = wp_timezone_string();
            if (!$tz || $tz === 'UTC') {
                $offset = get_option('gmt_offset');
                $tz = $offset ? timezone_name_from_abbr('', $offset * 3600, 0) ?: 'UTC' : 'UTC';
            }
        }
        return $tz;
    }
}

if ( ! function_exists('eventosapp_net2_maybe_schedule_event_digest') ) {
    function eventosapp_net2_maybe_schedule_event_digest($event_id){
        $flag = get_post_meta($event_id, '_eventosapp_net_digest_cron_scheduled', true);
        if ($flag) return;

        $last_day = eventosapp_net2_get_event_last_day($event_id);
        $tz = eventosapp_net2_event_timezone($event_id);
        try {
            $dt = new DateTime($last_day . ' 09:00:00', new DateTimeZone($tz));
            $dt->modify('+1 day');
        } catch(Exception $e){
            $dt = new DateTime('now', wp_timezone());
            $dt->modify('+1 day');
        }
        $ts = $dt->getTimestamp();

        if ( ! wp_next_scheduled('eventosapp_net2_digest_event', [$event_id]) ) {
            wp_schedule_single_event($ts, 'eventosapp_net2_digest_event', [$event_id]);
            update_post_meta($event_id, '_eventosapp_net_digest_cron_scheduled', 1);
        }
    }
}

if ( ! function_exists('eventosapp_net2_run_event_digest') ) {
    function eventosapp_net2_run_event_digest($event_id){
        global $wpdb;
        if ( ! function_exists('eventosapp_net2_table_name_internal') ) return;
        $table = eventosapp_net2_table_name_internal();

        if ( ! $wpdb->get_var( $wpdb->prepare("SELECT 1 FROM {$table} WHERE event_id=%d LIMIT 1", $event_id) ) ) {
            return;
        }

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT reader_ticket_id FROM {$table} WHERE event_id=%d", $event_id
        ) );
        $ids2= $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT read_ticket_id   FROM {$table} WHERE event_id=%d", $event_id
        ) );
        $all_ticket_ids = array_unique( array_map('intval', array_merge($ids ?: [], $ids2 ?: [])) );

        foreach ($all_ticket_ids as $ticket_id) {
            if ( function_exists('eventosapp_net2_send_digest_for_ticket') ) {
                eventosapp_net2_send_digest_for_ticket($ticket_id, $event_id);
            }
        }
        update_post_meta($event_id, '_eventosapp_net_digest_done', 1);
    }
}

/* Vinculamos el hook sólo si aquí definimos la función o si no estaba ya agregado */
if ( function_exists('eventosapp_net2_run_event_digest') ) {
    if ( ! has_action('eventosapp_net2_digest_event', 'eventosapp_net2_run_event_digest') ) {
        add_action('eventosapp_net2_digest_event', 'eventosapp_net2_run_event_digest');
    }
}

if ( ! function_exists('eventosapp_net2_send_digest_for_ticket') ) {
    function eventosapp_net2_send_digest_for_ticket($ticket_id, $event_id = 0, $args = []) {
        global $wpdb;

        $ticket_id = (int) $ticket_id;
        if (!$ticket_id) return false;

        $args = wp_parse_args($args, [
            'force'     => false,
            'mark_sent' => true,
        ]);

        if (!$args['force']) {
            $already = get_post_meta($ticket_id, '_eventosapp_net_digest_sent', true);
            if ($already) return false;
        }

        if (!$event_id) {
            $event_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
        }
        if (!$event_id) return false;

        if ( ! function_exists('eventosapp_net2_table_name_internal') ) return false;
        $table = eventosapp_net2_table_name_internal();

        $outgoing = $wpdb->get_col( $wpdb->prepare(
            "SELECT read_ticket_id FROM {$table} WHERE event_id=%d AND reader_ticket_id=%d",
            $event_id, $ticket_id
        ) );
        $incoming = $wpdb->get_col( $wpdb->prepare(
            "SELECT reader_ticket_id FROM {$table} WHERE event_id=%d AND read_ticket_id=%d",
            $event_id, $ticket_id
        ) );

        if ( empty($outgoing) && empty($incoming) ) {
            return false;
        }

        $email_to = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
        if (!$email_to) $email_to = get_post_meta($ticket_id, '_eventosapp_asistente_correo', true);
        if (!$email_to) return false;

        $evento_nombre = get_the_title($event_id);
        $as_first = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
        $as_last  = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
        $as_name  = trim($as_first.' '.$as_last);

        $contact = function($tid){
            $first = get_post_meta($tid, '_eventosapp_asistente_nombre', true);
            $last  = get_post_meta($tid, '_eventosapp_asistente_apellido', true);
            $comp  = get_post_meta($tid, '_eventosapp_asistente_empresa', true);
            $role  = get_post_meta($tid, '_eventosapp_asistente_cargo', true);
            $email = get_post_meta($tid, '_eventosapp_asistente_email', true);
            if (!$email) $email = get_post_meta($tid, '_eventosapp_asistente_correo', true);
            $phone = get_post_meta($tid, '_eventosapp_asistente_tel', true);
            if (!$phone) $phone = get_post_meta($tid, '_eventosapp_asistente_telefono', true);
            if (!$phone) $phone = get_post_meta($tid, '_eventosapp_asistente_cel', true);
            if (!$phone) $phone = get_post_meta($tid, '_eventosapp_asistente_celular', true);

            return [
                'full_name'   => trim($first.' '.$last),
                'designation' => $role,
                'company'     => $comp,
                'email'       => $email,
                'phone'       => $phone,
            ];
        };

        $build_table = function($title, $ids) use ($contact){
            if (!$ids) return '';
            $ids = array_unique(array_map('intval', $ids));

            $th = 'padding:10px 12px;border-bottom:1px solid #e5e7eb;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:13px;color:#111827';
            $td = 'padding:10px 12px;border-bottom:1px solid #f1f5f9;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:13px;color:#111827';

            $html  = '<h3 style="margin:16px 0 8px">'.esc_html($title).'</h3>';
            $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:720px;border:1px solid #e5e7eb">';
            $html .= '<thead><tr style="background:#f8fafc;text-align:left">';
            $html .= '<th style="'.$th.'">Nombre + Apellidos</th>';
            $html .= '<th style="'.$th.'">Cargo</th>';
            $html .= '<th style="'.$th.'">Empresa</th>';
            $html .= '<th style="'.$th.'">Teléfono</th>';
            $html .= '<th style="'.$th.'">Correo</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($ids as $tid){
                $p = $contact($tid);
                $name  = esc_html($p['full_name'] ?: '(Sin nombre)');
                $role  = esc_html($p['designation'] ?: '');
                $comp  = esc_html($p['company'] ?: '');
                $phone = esc_html($p['phone'] ?: '');
                $mail  = $p['email'] ? '<a href="mailto:'.esc_attr($p['email']).'">'.esc_html($p['email']).'</a>' : '';

                $html .= '<tr>';
                $html .= '<td style="'.$td.'">'.$name.'</td>';
                $html .= '<td style="'.$td.'">'.$role.'</td>';
                $html .= '<td style="'.$td.'">'.$comp.'</td>';
                $html .= '<td style="'.$td.'">'.$phone.'</td>';
                $html .= '<td style="'.$td.'">'.$mail.'</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            return $html;
        };

        $body  = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#111827">';
        $body .= '<p>Hola <b>'.esc_html($as_name).'</b>,</p>';
        $body .= '<p>¡Gracias por participar en el networking de <b>'.esc_html($evento_nombre).'</b>! Aquí tienes el resumen de tus nuevas conexiones:</p>';
        $body .= $build_table('Personas que tú escaneaste', $outgoing);
        $body .= $build_table('Personas que te escanearon', $incoming);
        $body .= '<p style="margin-top:16px;color:#6b7280">Este mensaje se envía automáticamente 24 h después del evento a quienes usaron el networking de doble autenticación.</p>';
        $body .= '</div>';

        $content_type_cb = function(){ return 'text/html'; };
        add_filter('wp_mail_content_type', $content_type_cb);
        $sent = wp_mail($email_to, 'Tus nuevas conexiones – ' . $evento_nombre, $body);
        remove_filter('wp_mail_content_type', $content_type_cb);

        if ($sent && $args['mark_sent']) {
            update_post_meta($ticket_id, '_eventosapp_net_digest_sent', 1);
        }
        return $sent;
    }
}

if ( ! function_exists('eventosapp_net2_metrics_by_localidad') ) {
    function eventosapp_net2_metrics_by_localidad($event_id){
        global $wpdb;
        if ( ! function_exists('eventosapp_net2_table_name_internal') ) return [
            'outgoing' => [], 'incoming' => []
        ];
        $table = eventosapp_net2_table_name_internal();
        $out = [
            'outgoing' => [],
            'incoming' => [],
        ];
        $rows1 = $wpdb->get_results( $wpdb->prepare(
            "SELECT COALESCE(reader_localidad,'') AS loc, COUNT(*) c FROM {$table} WHERE event_id=%d GROUP BY reader_localidad",
            $event_id
        ), ARRAY_A );
        foreach ((array)$rows1 as $r){ $out['outgoing'][$r['loc']] = (int)$r['c']; }

        $rows2 = $wpdb->get_results( $wpdb->prepare(
            "SELECT COALESCE(read_localidad,'') AS loc, COUNT(*) c FROM {$table} WHERE event_id=%d GROUP BY read_localidad",
            $event_id
        ), ARRAY_A );
        foreach ((array)$rows2 as $r){ $out['incoming'][$r['loc']] = (int)$r['c']; }

        return $out;
    }
}

/* ---------- Handlers admin_post (nombrados y protegidos) ---------- */

if ( ! function_exists('eventosapp_send_networking_digest_handler') ) {
    function eventosapp_send_networking_digest_handler(){
        if ( ! current_user_can('edit_posts') ) {
            wp_die('No autorizado', 403);
        }
        check_admin_referer('eventosapp_send_networking_digest');

        $ticket_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        if (!$ticket_id) wp_die('Falta post_id');

        $event_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
        if (!$event_id) wp_die('El ticket no pertenece a un evento');

        if ( function_exists('eventosapp_net2_send_digest_for_ticket') ) {
            $ok = eventosapp_net2_send_digest_for_ticket($ticket_id, $event_id);
        } else {
            $ok = false;
        }

        $redirect = admin_url('post.php?post='.$ticket_id.'&action=edit');
        $redirect = add_query_arg(['netdigest' => $ok ? 'ok' : 'skip'], $redirect);
        wp_safe_redirect($redirect);
        exit;
    }
}
if ( ! has_action('admin_post_eventosapp_send_networking_digest', 'eventosapp_send_networking_digest_handler') ) {
    add_action('admin_post_eventosapp_send_networking_digest', 'eventosapp_send_networking_digest_handler');
}

if ( ! function_exists('eventosapp_net2_admin_resend_digest') ) {
    function eventosapp_net2_admin_resend_digest(){
        if ( ! current_user_can('edit_posts') ) wp_die('Unauthorized');

        $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
        if ( ! wp_verify_nonce($nonce, 'eventosapp_net2_resend_digest') ) wp_die('Bad nonce');

        $ticket_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        if (!$ticket_id) wp_die('Missing ticket');

        $event_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);

        $ok = false;
        if ( function_exists('eventosapp_net2_send_digest_for_ticket') ) {
            $ok = eventosapp_net2_send_digest_for_ticket($ticket_id, $event_id, [
                'force'     => true,
                'mark_sent' => false,
            ]);
        }

        $url = add_query_arg([
            'post'                => $ticket_id,
            'action'              => 'edit',
            'evapp_netdigest'     => $ok ? 1 : 0,
            'evapp_netdigest_msg' => $ok ? 'Resumen enviado (manual) sin afectar el envío programado.' : 'No se envió. Verifica si existen interacciones.',
        ], admin_url('post.php'));

        wp_safe_redirect($url);
        exit;
    }
}
if ( ! has_action('admin_post_eventosapp_net2_resend_digest', 'eventosapp_net2_admin_resend_digest') ) {
    add_action('admin_post_eventosapp_net2_resend_digest', 'eventosapp_net2_admin_resend_digest');
}
