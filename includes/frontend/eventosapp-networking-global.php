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
 * @version 1.1
 * CORREGIDO: Busca por eventosapp_ticketID en lugar de Post ID
 */

add_shortcode('eventosapp_networking_global', function($atts){
    global $wpdb;
    
    // Obtener parámetros de la URL
    $url_params = isset($_GET['event']) ? sanitize_text_field($_GET['event']) : '';
    
    if (empty($url_params)) {
        return '<div style="max-width:520px;margin:2rem auto;padding:2rem;background:#fee2e2;border:1px solid #ef4444;border-radius:12px;text-align:center;">
            <h3 style="color:#dc2626;margin:0 0 1rem;">⚠️ Parámetros inválidos</h3>
            <p style="color:#991b1b;margin:0;">Esta página requiere parámetros válidos desde un código QR.</p>
        </div>';
    }
    
    // Parsear parámetros: event=123-ticketid=ABC123-7890
    $parts = explode('-', $url_params);
    
    if (count($parts) < 3) {
        return '<div style="max-width:520px;margin:2rem auto;padding:2rem;background:#fee2e2;border:1px solid #ef4444;border-radius:12px;text-align:center;">
            <h3 style="color:#dc2626;margin:0 0 1rem;">⚠️ Formato de URL inválido</h3>
            <p style="color:#991b1b;margin:0;">El código QR escaneado no tiene el formato correcto.</p>
        </div>';
    }
    
    // Extraer datos
    $event_id = intval($parts[0]);
    
    // El segundo elemento tiene formato "ticketid=ABC123"
    $ticket_part = isset($parts[1]) ? $parts[1] : '';
    $unique_ticket_id = '';
    if (strpos($ticket_part, 'ticketid=') === 0) {
        $unique_ticket_id = str_replace('ticketid=', '', $ticket_part);
    }
    
    $security_code = isset($parts[2]) ? $parts[2] : '';
    
    // CORRECCIÓN: Buscar el Post ID usando el eventosapp_ticketID
    $scanned_ticket_post_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} 
        WHERE meta_key = 'eventosapp_ticketID' 
        AND meta_value = %s 
        LIMIT 1",
        $unique_ticket_id
    ));
    
    // Validar que el ticket existe
    if (!$scanned_ticket_post_id || get_post_type($scanned_ticket_post_id) !== 'eventosapp_ticket') {
        return '<div style="max-width:520px;margin:2rem auto;padding:2rem;background:#fee2e2;border:1px solid #ef4444;border-radius:12px;text-align:center;">
            <h3 style="color:#dc2626;margin:0 0 1rem;">⚠️ Ticket no encontrado</h3>
            <p style="color:#991b1b;margin:0;">El ticket escaneado no existe en el sistema. (ID: ' . esc_html($unique_ticket_id) . ')</p>
        </div>';
    }
    
    // Validar código de seguridad
    $stored_security_code = get_post_meta($scanned_ticket_post_id, '_eventosapp_badge_security_code', true);
    
    if (empty($stored_security_code) || $stored_security_code !== $security_code) {
        return '<div style="max-width:520px;margin:2rem auto;padding:2rem;background:#fee2e2;border:1px solid #ef4444;border-radius:12px;text-align:center;">
            <h3 style="color:#dc2626;margin:0 0 1rem;">🔒 Código de seguridad inválido</h3>
            <p style="color:#991b1b;margin:0;">El código de seguridad no coincide. Este QR puede estar revocado.</p>
        </div>';
    }
    
    // Validar que el ticket pertenece al evento
    $ticket_event_id = (int) get_post_meta($scanned_ticket_post_id, '_eventosapp_ticket_evento_id', true);
    if ($ticket_event_id !== $event_id) {
        return '<div style="max-width:520px;margin:2rem auto;padding:2rem;background:#fee2e2;border:1px solid #ef4444;border-radius:12px;text-align:center;">
            <h3 style="color:#dc2626;margin:0 0 1rem;">⚠️ Evento no coincide</h3>
            <p style="color:#991b1b;margin:0;">Este ticket no pertenece al evento indicado.</p>
        </div>';
    }
    
    // Nonces para AJAX
    $nonce_auth = wp_create_nonce('eventosapp_netglobal_auth');
    $nonce_log  = wp_create_nonce('eventosapp_netglobal_log');
    
    ob_start(); ?>
    <style>
      .evapp-netglobal-shell { max-width:560px; margin:2rem auto; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
      .evapp-netglobal-card  { background:#0b1020; color:#eaf1ff; border-radius:16px; padding:24px; box-shadow:0 8px 24px rgba(0,0,0,.15); }
      .evapp-netglobal-title { display:flex; align-items:center; gap:.6rem; margin:0 0 1.5rem; font-weight:800; font-size:1.2rem; letter-spacing:.2px; }
      .evapp-netglobal-field { margin:16px 0; }
      .evapp-netglobal-field label { display:block; font-size:.95rem; margin-bottom:8px; color:#c9d6ff; font-weight:600; }
      .evapp-netglobal-input { width:100%; padding:.8rem; border-radius:10px; border:1px solid rgba(255,255,255,.12); background:#0a0f1d; color:#eaf1ff; font-size:1rem; }
      .evapp-netglobal-btn   { display:flex; align-items:center; justify-content:center; gap:.5rem; border:0; border-radius:12px; padding:1rem 1.2rem; font-weight:800; cursor:pointer; width:100%; background:#2563eb; color:#fff; transition:filter .15s, background .15s; font-size:1rem; }
      .evapp-netglobal-btn:hover{ filter:brightness(1.05); }
      .evapp-netglobal-btn:disabled{ opacity:.5; cursor:not-allowed; }
      .evapp-netglobal-help  { color:#a9b6d3; font-size:.9rem; opacity:.85; margin-top:8px; line-height:1.4; }
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
    </style>

    <div class="evapp-netglobal-shell"
         data-event="<?php echo esc_attr($event_id); ?>"
         data-scanned-ticket="<?php echo esc_attr($scanned_ticket_post_id); ?>"
         data-auth-nonce="<?php echo esc_attr($nonce_auth); ?>"
         data-log-nonce="<?php echo esc_attr($nonce_log); ?>">

      <div class="evapp-netglobal-card">
        <div class="evapp-netglobal-title">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" stroke="#a7b8ff" stroke-width="2"/></svg>
          Networking – Identificación
        </div>

        <!-- Paso 1: Autenticación -->
        <div id="evappNetGlobalAuth">
          <p class="evapp-netglobal-help" style="margin-bottom:1.5rem;">Para ver la información de este asistente, primero debes identificarte:</p>
          
          <div class="evapp-netglobal-field">
            <label>Tu Cédula</label>
            <input type="text" id="evappAuthCC" class="evapp-netglobal-input" placeholder="Ej: 1020304050">
            <small class="evapp-netglobal-help">
              Escribe tal cual como está en tu inscripción.
            </small>
          </div>
          
          <div class="evapp-netglobal-field">
            <label>Tus Apellidos</label>
            <input type="text" id="evappAuthLast" class="evapp-netglobal-input" placeholder="Ej: Pérez García">
            <small class="evapp-netglobal-help">
              Escribe tal cual como están en tu inscripción.
            </small>
          </div>
          
          <button type="button" id="evappAuthBtn" class="evapp-netglobal-btn">
            🔐 Confirmar mi identidad
          </button>
          
          <div id="evappAuthMsg"></div>
        </div>

        <!-- Paso 2: Datos del asistente escaneado -->
        <div class="evapp-netglobal-result" id="evappNetGlobalResult">
          <!-- Se llenará con JavaScript -->
        </div>
      </div>
    </div>

    <script>
    (function(){
      const shell = document.querySelector('.evapp-netglobal-shell');
      const ajaxURL    = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
      const eventID    = parseInt(shell?.dataset.event || '0', 10) || 0;
      const scannedTicketID = parseInt(shell?.dataset.scannedTicket || '0', 10) || 0;
      const authNonce  = shell?.dataset.authNonce || '';
      const logNonce   = shell?.dataset.logNonce || '';

      // Elementos del formulario
      const authSection = document.getElementById('evappNetGlobalAuth');
      const resultSection = document.getElementById('evappNetGlobalResult');
      const ccInput = document.getElementById('evappAuthCC');
      const lastInput = document.getElementById('evappAuthLast');
      const authBtn = document.getElementById('evappAuthBtn');
      const authMsg = document.getElementById('evappAuthMsg');

      let readerTicketId = 0;

      function setMsg(container, html, isGood = false){
        container.innerHTML = '<div class="evapp-netglobal-msg ' + (isGood ? 'evapp-netglobal-ok' : 'evapp-netglobal-bad') + '">' + html + '</div>';
      }

      // Autenticación
      authBtn.addEventListener('click', function(){
        const cc = ccInput.value.trim();
        const last = lastInput.value.trim();

        if (!cc || !last) {
          setMsg(authMsg, '⚠️ Por favor completa ambos campos');
          return;
        }

        authBtn.disabled = true;
        authBtn.textContent = '⏳ Verificando...';

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
            setMsg(authMsg, '✅ Identidad confirmada. Cargando información...', true);
            
            setTimeout(() => {
              loadScannedTicketData();
            }, 800);
          } else {
            setMsg(authMsg, '❌ ' + (data.data?.message || 'No se encontró tu registro'));
            authBtn.disabled = false;
            authBtn.textContent = '🔐 Confirmar mi identidad';
          }
        })
        .catch(err => {
          setMsg(authMsg, '❌ Error de conexión');
          authBtn.disabled = false;
          authBtn.textContent = '🔐 Confirmar mi identidad';
        });
      });

      function loadScannedTicketData(){
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
            setMsg(authMsg, '❌ ' + (data.data?.message || 'Error al cargar datos'));
          }
        })
        .catch(err => {
          setMsg(authMsg, '❌ Error al cargar información del asistente');
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
        html += '<button type="button" class="evapp-netglobal-btn evapp-netglobal-back" onclick="location.reload()">← Escanear otro QR</button>';
        html += '</div>';
        
        resultSection.innerHTML = html;
        authSection.style.display = 'none';
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

      // Función global para descargar vCard
      window.evappDownloadVCard = function(ticketId){
        window.location.href = ajaxURL + '?action=eventosapp_netglobal_download_vcard&ticket_id=' + ticketId + '&_wpnonce=' + authNonce;
      };

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

    // Validar apellidos
    $stored_last = get_post_meta($ticket_id, '_eventosapp_asistente_apellidos', true);
    
    if (strtolower(trim($stored_last)) !== strtolower(trim($last_name))) {
        wp_send_json_error(['message' => 'Los apellidos no coinciden']);
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
    $last_name  = get_post_meta($ticket_id, '_eventosapp_asistente_apellidos', true);
    $email      = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
    $phone      = get_post_meta($ticket_id, '_eventosapp_asistente_telefono', true);
    $company    = get_post_meta($ticket_id, '_eventosapp_asistente_empresa', true);
    $designation = get_post_meta($ticket_id, '_eventosapp_asistente_cargo', true);
    $localidad  = get_post_meta($ticket_id, '_eventosapp_ticket_localidad', true);
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
    $last_name   = get_post_meta($ticket_id, '_eventosapp_asistente_apellidos', true);
    $email       = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
    $phone       = get_post_meta($ticket_id, '_eventosapp_asistente_telefono', true);
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
