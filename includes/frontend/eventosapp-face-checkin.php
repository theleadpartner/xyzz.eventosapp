<?php
/**
 * EventosApp – Check-In por Reconocimiento Facial
 *
 * Shortcode: [eventosapp_face_checkin]
 *
 * Flujo:
 *  1. Staff abre el módulo → se activa la cámara del dispositivo.
 *  2. Via AJAX se cargan todos los CPT eventosapp_asistente que tienen foto.
 *  3. face-api.js compara el rostro en vivo contra los descriptores cargados.
 *  4. Al encontrar coincidencia se extrae la cédula del asistente reconocido.
 *  5. Otro AJAX busca el ticket (cedula + evento activo) y ejecuta el check-in
 *     usando la misma lógica que el check-in por QR.
 *
 * Dependencias externas (CDN):
 *  - face-api.js  (TensorFlow.js, sin costo, 100% cliente)
 *    https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js
 *
 * Modelos face-api.js (deben estar accesibles en /wp-content/plugins/eventosapp/assets/face-models/):
 *  - ssd_mobilenetv1_model-weights_manifest.json  + shards
 *  - face_landmark_68_model-weights_manifest.json + shards
 *  - face_recognition_model-weights_manifest.json + shards
 *
 * NOTA: Los modelos (~6 MB en total) se descargan desde el CDN de face-api.js
 *       automáticamente usando MODEL_URL definido en el JS del shortcode.
 *
 * @package EventosApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// 1. SHORTCODE PRINCIPAL
// ============================================================

add_shortcode( 'eventosapp_face_checkin', function ( $atts ) {

    // 🔒 Verificar sesión
    if ( ! is_user_logged_in() ) {
        return '<p style="color:red;">Debes iniciar sesión para usar este módulo.</p>';
    }

    // 🔒 Verificar rol permitido (reutiliza la función del módulo QR)
    if ( function_exists( 'eventosapp_current_user_can_checkin' ) && ! eventosapp_current_user_can_checkin() ) {
        return '<p style="color:red;">No tienes permisos para realizar check-in.</p>';
    }

    // Debe existir evento activo
    $active_event = function_exists( 'eventosapp_get_active_event' ) ? eventosapp_get_active_event() : 0;
    if ( ! $active_event ) {
        ob_start();
        if ( function_exists( 'eventosapp_require_active_event' ) ) {
            eventosapp_require_active_event();
        } else {
            echo '<p>Debes seleccionar un evento activo para usar el reconocimiento facial.</p>';
        }
        return ob_get_clean();
    }

    // Nonces para las dos llamadas AJAX
    $nonce_load  = wp_create_nonce( 'evapp_face_load_asistentes' );
    $nonce_checkin = wp_create_nonce( 'evapp_face_checkin_process' );

    // URL de modelos face-api.js (CDN de justadudewhohacks)
$models_url = esc_url( plugins_url( 'assets/face-models', dirname(__FILE__, 2) ) );
  
    $event_name = esc_js( get_the_title( $active_event ) );
    $event_id   = (int) $active_event;
    $ajax_url   = esc_js( admin_url( 'admin-ajax.php' ) );

    ob_start(); ?>
<!-- face-api.js local -->
<script src="<?php echo esc_url( plugins_url( 'assets/js/face-api.min.js', dirname(__FILE__, 2) ) ); ?>"></script>

    <style>
    /* ── Contenedor general ─────────────────────────────────────── */
    .evapp-face-shell {
        max-width: 560px;
        margin: 0 auto;
        font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    }
    .evapp-face-card {
        background: #0b1020;
        color: #eaf1ff;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 8px 24px rgba(0,0,0,.18);
    }
    .evapp-face-title {
        display: flex;
        align-items: center;
        gap: .6rem;
        margin: 0 0 14px 0;
        font-weight: 700;
        font-size: 1.05rem;
        letter-spacing: .2px;
    }
    /* ── Cámara ─────────────────────────────────────────────────── */
    .evapp-face-cam-wrap {
        position: relative;
        width: 100%;
        border-radius: 12px;
        overflow: hidden;
        background: #000;
        aspect-ratio: 4/3;
        display: none; /* oculto hasta activar */
    }
    .evapp-face-cam-wrap.is-active { display: block; }
    #evapp-face-video {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    #evapp-face-canvas {
        position: absolute;
        top: 0; left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
    }
    /* Marco guía */
    .evapp-face-guide {
        position: absolute;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        width: 55%;
        aspect-ratio: 3/4;
        border: 3px dashed rgba(79,124,255,.7);
        border-radius: 50% 50% 46% 46% / 42% 42% 58% 58%;
        pointer-events: none;
    }
    /* Overlay de estado sobre la cámara */
    .evapp-face-overlay {
        position: absolute;
        bottom: 10px; left: 0; right: 0;
        text-align: center;
        font-size: .8rem;
        color: #eaf1ff;
        text-shadow: 0 1px 4px #000;
        pointer-events: none;
    }
    /* ── Botones ─────────────────────────────────────────────────── */
    .evapp-face-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .5rem;
        border: 0;
        border-radius: 12px;
        padding: .9rem 1.1rem;
        font-weight: 700;
        cursor: pointer;
        width: 100%;
        background: #4f7cff;
        color: #fff;
        transition: filter .15s ease;
        font-size: 1rem;
        margin-top: 14px;
    }
    .evapp-face-btn:hover { filter: brightness(.92); }
    .evapp-face-btn.is-live { background: #e04f5f; }
    .evapp-face-btn:disabled { opacity: .6; cursor: not-allowed; }
    /* ── Ficha del asistente reconocido ──────────────────────────── */
    .evapp-face-result {
        margin-top: 14px;
        border-radius: 12px;
        padding: 14px 16px;
        background: #13203a;
        display: none;
    }
    .evapp-face-result.is-ok   { border-left: 4px solid #2ecc71; }
    .evapp-face-result.is-warn { border-left: 4px solid #f39c12; }
    .evapp-face-result.is-err  { border-left: 4px solid #e74c3c; }
    .evapp-face-result-name {
        font-weight: 700;
        font-size: 1.1rem;
        margin-bottom: 4px;
    }
    .evapp-face-result-meta {
        font-size: .82rem;
        opacity: .75;
        line-height: 1.5;
    }
    /* ── Estado / loader ─────────────────────────────────────────── */
    .evapp-face-status {
        margin-top: 10px;
        font-size: .82rem;
        opacity: .65;
        text-align: center;
        min-height: 1.2em;
    }
    .evapp-face-spinner {
        display: inline-block;
        width: 14px; height: 14px;
        border: 2px solid rgba(255,255,255,.3);
        border-top-color: #fff;
        border-radius: 50%;
        animation: evapp-spin .7s linear infinite;
        vertical-align: middle;
        margin-right: 4px;
    }
    @keyframes evapp-spin { to { transform: rotate(360deg); } }
    /* ── Badge de progreso de carga ─────────────────────────────── */
    .evapp-face-progress-wrap {
        margin-top: 10px;
        background: rgba(255,255,255,.08);
        border-radius: 8px;
        height: 6px;
        overflow: hidden;
        display: none;
    }
    .evapp-face-progress-bar {
        height: 100%;
        background: #4f7cff;
        transition: width .3s ease;
        border-radius: 8px;
    }
    /* ── Contador ───────────────────────────────────────────────── */
    .evapp-face-counter {
        display: flex;
        gap: 12px;
        margin-top: 12px;
    }
    .evapp-face-counter-item {
        flex: 1;
        background: rgba(255,255,255,.05);
        border-radius: 10px;
        padding: 8px 10px;
        text-align: center;
        font-size: .78rem;
    }
    .evapp-face-counter-num {
        font-size: 1.4rem;
        font-weight: 700;
        color: #4f7cff;
        display: block;
    }
    </style>

    <div class="evapp-face-shell">
        <div class="evapp-face-card">

            <!-- Título -->
            <div class="evapp-face-title">
                <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M15 10a3 3 0 1 1-6 0 3 3 0 0 1 6 0ZM5 20a7 7 0 0 1 14 0"/>
                    <rect x="2" y="2" width="20" height="20" rx="5" stroke-width="1.5"/>
                </svg>
                Check-In Reconocimiento Facial
            </div>

            <!-- Sub-info evento -->
            <div style="font-size:.8rem;opacity:.55;margin-bottom:12px;">
                Evento: <strong><?php echo esc_html( get_the_title( $active_event ) ); ?></strong>
            </div>

            <!-- Barra de progreso de carga de modelos -->
            <div class="evapp-face-progress-wrap" id="evapp-face-progress-wrap">
                <div class="evapp-face-progress-bar" id="evapp-face-progress-bar" style="width:0%"></div>
            </div>

            <!-- Cámara -->
            <div class="evapp-face-cam-wrap" id="evapp-face-cam-wrap">
                <video id="evapp-face-video" autoplay muted playsinline></video>
                <canvas id="evapp-face-canvas"></canvas>
                <div class="evapp-face-guide"></div>
                <div class="evapp-face-overlay" id="evapp-face-cam-label">Posiciona el rostro en el óvalo</div>
            </div>

            <!-- Botón activar/detener cámara -->
            <button class="evapp-face-btn" id="evapp-face-toggle-btn" disabled>
                <span id="evapp-face-btn-icon">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 20a7 7 0 0 1 14 0"/>
                    </svg>
                </span>
                <span id="evapp-face-btn-label">Cargando modelos…</span>
            </button>

            <!-- Estado / log -->
            <div class="evapp-face-status" id="evapp-face-status">Iniciando sistema de reconocimiento facial…</div>

            <!-- Ficha de resultado -->
            <div class="evapp-face-result" id="evapp-face-result">
                <div class="evapp-face-result-name" id="evapp-face-result-name"></div>
                <div class="evapp-face-result-meta" id="evapp-face-result-meta"></div>
            </div>

            <!-- Contadores -->
            <div class="evapp-face-counter">
                <div class="evapp-face-counter-item">
                    <span class="evapp-face-counter-num" id="evapp-face-db-count">0</span>
                    Perfiles cargados
                </div>
                <div class="evapp-face-counter-item">
                    <span class="evapp-face-counter-num" id="evapp-face-checkin-count">0</span>
                    Check-ins hoy
                </div>
            </div>

        </div><!-- .evapp-face-card -->
    </div><!-- .evapp-face-shell -->

    <script>
    (function () {
        'use strict';

        /* ── Configuración ──────────────────────────────────────────── */
        const AJAX_URL     = '<?php echo $ajax_url; ?>';
        const EVENT_ID     = <?php echo $event_id; ?>;
        const NONCE_LOAD   = '<?php echo esc_js( $nonce_load ); ?>';
        const NONCE_CI     = '<?php echo esc_js( $nonce_checkin ); ?>';
        const MODELS_URL   = '<?php echo esc_js( $models_url ); ?>';

        /* Umbral de distancia facial (menor = más estricto)
           0.55 es conservador; sube a 0.6 si hay falsos negativos */
        const MATCH_THRESHOLD = 0.55;

        /* Milisegundos mínimos entre detecciones para evitar spam */
        const DETECT_INTERVAL_MS = 1200;

        /* ── Estado ──────────────────────────────────────────────────── */
        let faceDB        = [];   // [{ cedula, nombre, descriptor: Float32Array }]
        let isLive        = false;
        let stream        = null;
        let detectTimer   = null;
        let lastCedula    = null; // evita re-checkin inmediato de la misma persona
        let lastCedulaTs  = 0;
        let checkinCount  = 0;
        let isProcessing  = false;

        /* ── DOM ─────────────────────────────────────────────────────── */
        const video      = document.getElementById('evapp-face-video');
        const canvas     = document.getElementById('evapp-face-canvas');
        const camWrap    = document.getElementById('evapp-face-cam-wrap');
        const toggleBtn  = document.getElementById('evapp-face-toggle-btn');
        const btnLabel   = document.getElementById('evapp-face-btn-label');
        const statusEl   = document.getElementById('evapp-face-status');
        const resultEl   = document.getElementById('evapp-face-result');
        const resultName = document.getElementById('evapp-face-result-name');
        const resultMeta = document.getElementById('evapp-face-result-meta');
        const camLabel   = document.getElementById('evapp-face-cam-label');
        const dbCount    = document.getElementById('evapp-face-db-count');
        const ciCount    = document.getElementById('evapp-face-checkin-count');
        const progWrap   = document.getElementById('evapp-face-progress-wrap');
        const progBar    = document.getElementById('evapp-face-progress-bar');

        /* ── Helpers UI ──────────────────────────────────────────────── */
        function setStatus(msg, spin) {
            statusEl.innerHTML = spin
                ? '<span class="evapp-face-spinner"></span>' + msg
                : msg;
        }

        function showResult(type, name, meta) {
            resultEl.className = 'evapp-face-result is-' + type;
            resultEl.style.display = 'block';
            resultName.textContent = name;
            resultMeta.innerHTML   = meta;
        }

        function setProgress(pct) {
            progWrap.style.display = 'block';
            progBar.style.width    = pct + '%';
            if (pct >= 100) {
                setTimeout(function() { progWrap.style.display = 'none'; }, 800);
            }
        }

        /* ── 1. Cargar modelos face-api.js ───────────────────────────── */
        async function loadModels() {
            setStatus('Cargando modelos de reconocimiento facial (puede tomar unos segundos)…', true);
            setProgress(5);
            try {
                await faceapi.nets.ssdMobilenetv1.loadFromUri(MODELS_URL);
                setProgress(40);
                await faceapi.nets.faceLandmark68Net.loadFromUri(MODELS_URL);
                setProgress(70);
                await faceapi.nets.faceRecognitionNet.loadFromUri(MODELS_URL);
                setProgress(90);
                setStatus('Modelos cargados. Cargando perfiles de asistentes…', true);
                await loadAsistentes();
                setProgress(100);
            } catch (e) {
                setStatus('❌ Error cargando modelos: ' + e.message);
                console.error('[FaceCheckin] Error modelos:', e);
            }
        }

        /* ── 2. Cargar asistentes con foto vía AJAX ──────────────────── */
        async function loadAsistentes() {
            try {
                const res = await fetch(AJAX_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action:   'evapp_get_asistentes_face_data',
                        security: NONCE_LOAD,
                        event_id: EVENT_ID
                    })
                });
                const data = await res.json();

                if (!data.success || !data.data.asistentes || !data.data.asistentes.length) {
                    setStatus('⚠️ No se encontraron asistentes con foto registrada.');
                    enableButton();
                    return;
                }

                const lista = data.data.asistentes;
                setStatus('Procesando ' + lista.length + ' fotos…', true);

                let procesados = 0;
                for (const a of lista) {
                    try {
                        const img = await faceapi.fetchImage(a.foto_url);
                        const detection = await faceapi
                            .detectSingleFace(img, new faceapi.SsdMobilenetv1Options({ minConfidence: 0.5 }))
                            .withFaceLandmarks()
                            .withFaceDescriptor();

                        if (detection) {
                            faceDB.push({
                                cedula:     a.cedula,
                                nombre:     a.nombre,
                                descriptor: detection.descriptor
                            });
                        }
                    } catch (imgErr) {
                        console.warn('[FaceCheckin] No se pudo procesar foto de:', a.nombre, imgErr);
                    }
                    procesados++;
                    setStatus('Procesando ' + procesados + '/' + lista.length + ' fotos…', true);
                }

                dbCount.textContent = faceDB.length;

                if (faceDB.length === 0) {
                    setStatus('⚠️ Ninguna foto procesada. Verifica que las fotos tengan rostros detectables.');
                } else {
                    setStatus('✅ ' + faceDB.length + ' perfiles cargados. Listo para reconocimiento facial.');
                }

                enableButton();

            } catch (e) {
                setStatus('❌ Error cargando asistentes: ' + e.message);
                console.error('[FaceCheckin] Error asistentes:', e);
                enableButton();
            }
        }

        function enableButton() {
            toggleBtn.disabled = false;
            btnLabel.textContent = 'Activar Cámara';
        }

        /* ── 3. Controlar cámara ─────────────────────────────────────── */
        toggleBtn.addEventListener('click', async function () {
            if (!isLive) {
                await startCamera();
            } else {
                stopCamera();
            }
        });

        async function startCamera() {
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
                    audio: false
                });
                video.srcObject = stream;
                await video.play();

                camWrap.classList.add('is-active');
                toggleBtn.classList.add('is-live');
                btnLabel.textContent  = 'Detener Cámara';
                isLive = true;

                setStatus('Cámara activa. Posiciona el rostro frente a la cámara.', false);
                resultEl.style.display = 'none';

                // Ajustar canvas al video
                video.addEventListener('loadedmetadata', function () {
                    canvas.width  = video.videoWidth;
                    canvas.height = video.videoHeight;
                }, { once: true });

                startDetection();
            } catch (e) {
                setStatus('❌ No se pudo acceder a la cámara: ' + e.message);
                console.error('[FaceCheckin] Cámara:', e);
            }
        }

        function stopCamera() {
            clearInterval(detectTimer);
            if (stream) {
                stream.getTracks().forEach(t => t.stop());
                stream = null;
            }
            video.srcObject = null;
            camWrap.classList.remove('is-active');
            toggleBtn.classList.remove('is-live');
            btnLabel.textContent = 'Activar Cámara';
            isLive = false;
            lastCedula = null;
            setStatus('Cámara detenida.');

            // Limpiar canvas
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }

        /* ── 4. Loop de detección facial ─────────────────────────────── */
        function startDetection() {
            detectTimer = setInterval(runDetection, DETECT_INTERVAL_MS);
        }

        async function runDetection() {
            if (!isLive || isProcessing || faceDB.length === 0) return;
            if (video.readyState < 2) return;

            isProcessing = true;
            try {
                const detection = await faceapi
                    .detectSingleFace(video, new faceapi.SsdMobilenetv1Options({ minConfidence: 0.5 }))
                    .withFaceLandmarks()
                    .withFaceDescriptor();

                // Dibujar en canvas
                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);

                if (!detection) {
                    camLabel.textContent = 'Posiciona el rostro en el óvalo';
                    isProcessing = false;
                    return;
                }

                // Dibujar detección
                const dims = faceapi.matchDimensions(canvas, video, true);
                const resized = faceapi.resizeResults(detection, dims);
                faceapi.draw.drawDetections(canvas, resized);

                // Comparar con base de datos
                const matcher = new faceapi.FaceMatcher(
                    faceDB.map(f => new faceapi.LabeledFaceDescriptors(f.cedula, [f.descriptor])),
                    MATCH_THRESHOLD
                );
                const match = matcher.findBestMatch(detection.descriptor);

                if (match.label !== 'unknown') {
                    const cedula = match.label;
                    const now = Date.now();

                    // Evitar re-procesar la misma persona en menos de 8 segundos
                    if (cedula === lastCedula && (now - lastCedulaTs) < 8000) {
                        camLabel.textContent = '✅ Ya identificado';
                        isProcessing = false;
                        return;
                    }

                    lastCedula  = cedula;
                    lastCedulaTs = now;

                    const perfil = faceDB.find(f => f.cedula === cedula);
                    camLabel.textContent = '🔍 Identificando: ' + (perfil ? perfil.nombre : cedula) + '…';
                    setStatus('Rostro reconocido. Buscando ticket…', true);

                    await procesarCheckin(cedula, match.distance);
                } else {
                    camLabel.textContent = 'Rostro no reconocido. Acércate o mejora la iluminación.';
                }
            } catch (e) {
                console.warn('[FaceCheckin] Error detección:', e);
            }
            isProcessing = false;
        }

        /* ── 5. Ejecutar check-in vía AJAX ───────────────────────────── */
        async function procesarCheckin(cedula, distance) {
            try {
                const res = await fetch(AJAX_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action:   'evapp_face_checkin_process',
                        security: NONCE_CI,
                        event_id: EVENT_ID,
                        cedula:   cedula
                    })
                });
                const data = await res.json();

                if (data.success) {
                    const d = data.data;
                    const tipo = d.already ? 'warn' : 'ok';
                    const titulo = d.already
                        ? '⚠️ Ya hizo check-in hoy'
                        : '✅ Check-In Exitoso';

                    let metaHtml = '';
                    if (d.full_name)    metaHtml += '<strong>' + escapeHtml(d.full_name) + '</strong><br>';
                    if (d.company)      metaHtml += '🏢 ' + escapeHtml(d.company) + '<br>';
                    if (d.designation)  metaHtml += '💼 ' + escapeHtml(d.designation) + '<br>';
                    if (d.localidad)    metaHtml += '📍 ' + escapeHtml(d.localidad) + '<br>';
                    metaHtml += '📅 ' + escapeHtml(d.checkin_date_label) + '<br>';
                    metaHtml += '🎯 Reconocimiento facial (conf: ' + (1 - distance).toFixed(2) + ')';
                    if (d.payment_message) metaHtml += '<br>' + escapeHtml(d.payment_message);

                    showResult(tipo, titulo, metaHtml);
                    setStatus(tipo === 'ok' ? '✅ Check-in registrado.' : '⚠️ El asistente ya había ingresado hoy.');
                    camLabel.textContent = tipo === 'ok' ? '✅ Check-in OK' : '⚠️ Ya ingresó';

                    if (!d.already) {
                        checkinCount++;
                        ciCount.textContent = checkinCount;
                    }

                } else {
                    const errMsg = data.data && data.data.error ? data.data.error : 'Error desconocido';
                    showResult('err', '❌ No se pudo hacer Check-In', escapeHtml(errMsg));
                    setStatus('❌ ' + errMsg);
                    camLabel.textContent = '❌ Sin ticket para este evento';
                }

            } catch (e) {
                setStatus('❌ Error de conexión: ' + e.message);
                console.error('[FaceCheckin] Error check-in AJAX:', e);
            }
        }

        /* ── Utilidad ─────────────────────────────────────────────────── */
        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        /* ── Arrancar ─────────────────────────────────────────────────── */
        loadModels();

    })();
    </script>
    <?php
    return ob_get_clean();
} );


// ============================================================
// 2. AJAX: Cargar asistentes con foto para el evento activo
// ============================================================

add_action( 'wp_ajax_evapp_get_asistentes_face_data', function () {

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( ['error' => 'Debes iniciar sesión.'], 401 );
    }

    if ( function_exists('eventosapp_current_user_can_checkin') && ! eventosapp_current_user_can_checkin() ) {
        wp_send_json_error( ['error' => 'Permisos insuficientes.'], 403 );
    }

    check_ajax_referer( 'evapp_face_load_asistentes', 'security' );

    $event_id = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;

    // Validar que el evento activo coincide (no-admins)
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $event_id !== $active ) {
            wp_send_json_error( ['error' => 'Sin permisos para este evento.'], 403 );
        }
    }

    // Obtener TODOS los asistentes que tengan foto
    $asistentes_query = new WP_Query( [
        'post_type'      => 'eventosapp_asistente',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [
            [
                'key'     => '_asistente_foto_id',
                'value'   => '',
                'compare' => '!=',
            ],
            [
                'key'     => '_asistente_cedula',
                'value'   => '',
                'compare' => '!=',
            ],
        ],
    ] );

    $resultado = [];

    if ( ! empty( $asistentes_query->posts ) ) {
        foreach ( $asistentes_query->posts as $asistente_id ) {
            $cedula  = get_post_meta( $asistente_id, '_asistente_cedula', true );
            $foto_id = (int) get_post_meta( $asistente_id, '_asistente_foto_id', true );
            $nombres   = get_post_meta( $asistente_id, '_asistente_nombres', true );
            $apellidos = get_post_meta( $asistente_id, '_asistente_apellidos', true );

            if ( ! $cedula || ! $foto_id ) continue;

            $foto_url = wp_get_attachment_url( $foto_id );
            if ( ! $foto_url ) continue;

            $resultado[] = [
                'asistente_id' => $asistente_id,
                'cedula'       => $cedula,
                'nombre'       => trim( $nombres . ' ' . $apellidos ),
                'foto_url'     => $foto_url,
            ];
        }
    }

    wp_send_json_success( [
        'asistentes' => $resultado,
        'total'      => count( $resultado ),
    ] );
} );


// ============================================================
// 3. AJAX: Procesar Check-In por cédula (reconocimiento facial)
// ============================================================

add_action( 'wp_ajax_evapp_face_checkin_process', function () {

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( ['error' => 'Debes iniciar sesión.'], 401 );
    }

    if ( function_exists('eventosapp_current_user_can_checkin') && ! eventosapp_current_user_can_checkin() ) {
        wp_send_json_error( ['error' => 'Permisos insuficientes para Check-In.'], 403 );
    }

    check_ajax_referer( 'evapp_face_checkin_process', 'security' );

    $cedula   = isset( $_POST['cedula'] )   ? sanitize_text_field( wp_unslash( $_POST['cedula'] ) ) : '';
    $event_id = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;

    // Validar evento activo para no-admins
    if ( ! current_user_can('manage_options') && function_exists('eventosapp_get_active_event') ) {
        $active = (int) eventosapp_get_active_event();
        if ( ! $active || $event_id !== $active ) {
            wp_send_json_error( ['error' => 'Sin permisos para este evento.'], 403 );
        }
    }

    if ( ! $cedula || ! $event_id ) {
        wp_send_json_error( ['error' => 'Datos incompletos (cédula o evento).'] );
    }

    error_log( "[EventosApp FaceCheckin] Cédula: $cedula | Evento: $event_id" );

    // Buscar ticket por cédula + evento usando la función existente
    $ticket_post_id = false;
    if ( function_exists( 'evapp_find_ticket_by_cedula_evento' ) ) {
        $ticket_post_id = evapp_find_ticket_by_cedula_evento( $cedula, $event_id );
    }

    if ( ! $ticket_post_id ) {
        // Intento directo por meta si la función no está disponible
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT pm.post_id
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key   = '_eventosapp_asistente_cc'
                AND pm.meta_value = %s
                AND p.post_type   = 'eventosapp_ticket'
                AND p.post_status != 'trash'",
            $cedula
        ) );
        if ( $ids ) {
            foreach ( $ids as $cand ) {
                if ( (int) get_post_meta( $cand, '_eventosapp_ticket_evento_id', true ) === $event_id ) {
                    $ticket_post_id = (int) $cand;
                    break;
                }
            }
        }
    }

    if ( ! $ticket_post_id ) {
        error_log( "[EventosApp FaceCheckin] Ticket no encontrado para cédula $cedula en evento $event_id" );
        wp_send_json_error( ['error' => 'No se encontró ticket para esta persona en el evento activo.'] );
    }

    // ── Control de pago (misma lógica que QR checkin) ───────────────────────
    $control_pago_activo = ( get_post_meta( $event_id, '_eventosapp_ticket_control_pago', true ) === '1' );
    $mensaje_pago = '';

    if ( $control_pago_activo ) {
        $estado_pago = get_post_meta( $ticket_post_id, '_eventosapp_estado_pago', true );
        if ( empty( $estado_pago ) ) $estado_pago = 'no_pagado';

        if ( $estado_pago === 'no_pagado' ) {
            wp_send_json_error( [
                'error'            => '❌ Check-In rechazado: el ticket no ha sido pagado.',
                'payment_required' => true,
                'ticket_id'        => $ticket_post_id,
            ] );
        }
        $mensaje_pago = '💳 Ticket verificado como Pagado';
    }

    // ── Validación de fecha del evento ───────────────────────────────────────
    $event_tz = get_post_meta( $event_id, '_eventosapp_zona_horaria', true );
    if ( ! $event_tz ) {
        $event_tz = wp_timezone_string();
        if ( ! $event_tz || $event_tz === 'UTC' ) {
            $offset   = get_option( 'gmt_offset' );
            $event_tz = $offset ? timezone_name_from_abbr( '', $offset * 3600, 0 ) ?: 'UTC' : 'UTC';
        }
    }

    try {
        $dt = new DateTime( 'now', new DateTimeZone( $event_tz ) );
    } catch ( Exception $e ) {
        $dt = new DateTime( 'now', wp_timezone() );
    }
    $today = $dt->format( 'Y-m-d' );

    $days = function_exists( 'eventosapp_get_event_days' )
        ? (array) eventosapp_get_event_days( $event_id )
        : [];

    if ( empty( $days ) || ! in_array( $today, $days, true ) ) {
        wp_send_json_error( ['error' => 'El check-in solo está permitido en las fechas del evento. Hoy no corresponde.'] );
    }

    // ── Marcar check-in ──────────────────────────────────────────────────────
    $status_arr = get_post_meta( $ticket_post_id, '_eventosapp_checkin_status', true );
    if ( is_string( $status_arr ) ) $status_arr = @unserialize( $status_arr );
    if ( ! is_array( $status_arr ) ) $status_arr = [];

    $already = ( isset( $status_arr[ $today ] ) && $status_arr[ $today ] === 'checked_in' );

    if ( ! $already ) {
        $status_arr[ $today ] = 'checked_in';
        update_post_meta( $ticket_post_id, '_eventosapp_checkin_status', $status_arr );

        // Log de check-in
        $log = get_post_meta( $ticket_post_id, '_eventosapp_checkin_log', true );
        if ( is_string( $log ) ) $log = @unserialize( $log );
        if ( ! is_array( $log ) ) $log = [];

        $user   = wp_get_current_user();
        $log[]  = [
            'fecha'          => $dt->format( 'Y-m-d' ),
            'hora'           => $dt->format( 'H:i:s' ),
            'dia'            => $today,
            'status'         => 'checked_in',
            'usuario'        => $user && $user->exists()
                ? $user->display_name . ' (' . $user->user_email . ')'
                : 'Sistema',
            'qr_type'        => 'face_recognition',
            'qr_type_label'  => 'Reconocimiento Facial',
            'cedula'         => $cedula,
        ];
        update_post_meta( $ticket_post_id, '_eventosapp_checkin_log', $log );

        // Actualizar estadísticas de QR si la función existe
        if ( function_exists( 'eventosapp_update_qr_usage_stats' ) ) {
            eventosapp_update_qr_usage_stats( $event_id, 'face_recognition' );
        }

        error_log( "[EventosApp FaceCheckin] Check-in realizado - Ticket: $ticket_post_id | Cédula: $cedula | Fecha: $today" );
    } else {
        error_log( "[EventosApp FaceCheckin] Ya tenía check-in hoy - Ticket: $ticket_post_id | Cédula: $cedula" );
    }

    // ── Respuesta ────────────────────────────────────────────────────────────
    $first = get_post_meta( $ticket_post_id, '_eventosapp_asistente_nombre',   true );
    $last  = get_post_meta( $ticket_post_id, '_eventosapp_asistente_apellido',  true );
    $comp  = get_post_meta( $ticket_post_id, '_eventosapp_asistente_empresa',   true );
    $role  = get_post_meta( $ticket_post_id, '_eventosapp_asistente_cargo',     true );
    $loc   = get_post_meta( $ticket_post_id, '_eventosapp_asistente_localidad', true );

    wp_send_json_success( [
        'already'            => $already,
        'full_name'          => trim( $first . ' ' . $last ),
        'company'            => $comp,
        'designation'        => $role,
        'localidad'          => $loc,
        'event_name'         => get_the_title( $event_id ),
        'ticket_id'          => $ticket_post_id,
        'cedula'             => $cedula,
        'checkin_date'       => $today,
        'checkin_date_label' => date_i18n( 'D, d M Y', strtotime( $today ) ),
        'qr_type_label'      => 'Reconocimiento Facial',
        'payment_message'    => $mensaje_pago,
    ] );
} );
