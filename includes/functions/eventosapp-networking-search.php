<?php
/**
 * EventosApp - Networking Search Shortcode
 * 
 * Shortcode: [eventosapp_networking_search]
 * Permite a los asistentes buscar sus eventos del día actual mediante cédula y apellido
 * y acceder directamente a las landing de networking de cada evento.
 * 
 * Archivo: includes/functions/eventosapp-networking-search.php
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Shortcode principal: [eventosapp_networking_search]
 * Muestra un formulario de búsqueda por cédula y apellido
 */
add_shortcode('eventosapp_networking_search', function($atts) {
    $atts = shortcode_atts([
        'title' => 'Acceso a Networking',
        'subtitle' => 'Ingresa tus datos para acceder a tus eventos',
    ], $atts, 'eventosapp_networking_search');

    $nonce = wp_create_nonce('eventosapp_networking_search');

    ob_start();
    ?>
    <style>
        .evapp-netsearch-wrapper {
            max-width: 600px;
            margin: 0 auto;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        }
        .evapp-netsearch-card {
            background: #0b1020;
            color: #eaf1ff;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 8px 24px rgba(0,0,0,.15);
        }
        .evapp-netsearch-header {
            text-align: center;
            margin-bottom: 24px;
        }
        .evapp-netsearch-title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: 0 0 8px;
            font-weight: 800;
            font-size: 1.4rem;
            letter-spacing: .3px;
            color: #eaf1ff;
        }
        .evapp-netsearch-subtitle {
            color: #a9b6d3;
            font-size: 0.95rem;
            margin: 0;
            opacity: .9;
        }
        .evapp-netsearch-form {
            margin-top: 24px;
        }
        .evapp-netsearch-field {
            margin-bottom: 18px;
        }
        .evapp-netsearch-field label {
            display: block;
            font-size: 0.95rem;
            margin-bottom: 8px;
            color: #c9d6ff;
            font-weight: 600;
        }
        .evapp-netsearch-input {
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,.12);
            background: #0a0f1d;
            color: #eaf1ff;
            font-size: 1rem;
            transition: border-color .2s;
            box-sizing: border-box;
        }
        .evapp-netsearch-input:focus {
            outline: none;
            border-color: #2563eb;
        }
        .evapp-netsearch-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            border: 0;
            border-radius: 12px;
            padding: 14px 20px;
            font-weight: 800;
            font-size: 1rem;
            cursor: pointer;
            background: #2563eb;
            color: #fff;
            transition: filter .15s, transform .1s;
            margin-top: 8px;
        }
        .evapp-netsearch-btn:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
        }
        .evapp-netsearch-btn:active {
            transform: translateY(0);
        }
        .evapp-netsearch-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .evapp-netsearch-message {
            margin-top: 16px;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.95rem;
            text-align: center;
        }
        .evapp-netsearch-message.success {
            background: rgba(124, 255, 141, 0.15);
            color: #7CFF8D;
            border: 1px solid rgba(124, 255, 141, 0.3);
        }
        .evapp-netsearch-message.error {
            background: rgba(255, 107, 107, 0.15);
            color: #ff6b6b;
            border: 1px solid rgba(255, 107, 107, 0.3);
        }
        .evapp-netsearch-message.info {
            background: rgba(37, 99, 235, 0.15);
            color: #a9b6d3;
            border: 1px solid rgba(37, 99, 235, 0.3);
        }
        .evapp-netsearch-results {
            margin-top: 24px;
            display: none;
        }
        .evapp-netsearch-results.show {
            display: block;
        }
        .evapp-netsearch-results-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 16px;
            color: #eaf1ff;
            text-align: center;
        }
        .evapp-netsearch-event-card {
            background: #0a0f1d;
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 12px;
            transition: border-color .2s, transform .2s;
        }
        .evapp-netsearch-event-card:hover {
            border-color: rgba(37, 99, 235, 0.5);
            transform: translateX(4px);
        }
        .evapp-netsearch-event-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #eaf1ff;
            margin: 0 0 8px;
        }
        .evapp-netsearch-event-date {
            font-size: 0.9rem;
            color: #a9b6d3;
            margin: 0 0 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .evapp-netsearch-event-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 18px;
            background: #2563eb;
            color: #fff;
            border: 0;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.9rem;
            text-decoration: none;
            transition: filter .15s, transform .1s;
            cursor: pointer;
        }
        .evapp-netsearch-event-btn:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
            color: #fff;
            text-decoration: none;
        }
        .evapp-netsearch-loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: evapp-spin 0.8s linear infinite;
        }
        @keyframes evapp-spin {
            to { transform: rotate(360deg); }
        }
        .evapp-netsearch-icon {
            display: inline-block;
            width: 18px;
            height: 18px;
        }
    </style>

    <div class="evapp-netsearch-wrapper">
        <div class="evapp-netsearch-card">
            <div class="evapp-netsearch-header">
                <h2 class="evapp-netsearch-title">
                    <svg class="evapp-netsearch-icon" viewBox="0 0 24 24" fill="none">
                        <path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M2 17L12 22L22 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M2 12L12 17L22 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <?php echo esc_html($atts['title']); ?>
                </h2>
                <p class="evapp-netsearch-subtitle">
                    <?php echo esc_html($atts['subtitle']); ?>
                </p>
            </div>

            <form class="evapp-netsearch-form" id="evappNetSearchForm" data-nonce="<?php echo esc_attr($nonce); ?>">
                <div class="evapp-netsearch-field">
                    <label for="evappNetSearchCedula">Cédula / Documento de Identidad</label>
                    <input 
                        type="text" 
                        id="evappNetSearchCedula" 
                        class="evapp-netsearch-input" 
                        placeholder="Ej: 1020304050"
                        required
                        autocomplete="off"
                    >
                </div>
                
                <div class="evapp-netsearch-field">
                    <label for="evappNetSearchApellido">Apellido</label>
                    <input 
                        type="text" 
                        id="evappNetSearchApellido" 
                        class="evapp-netsearch-input" 
                        placeholder="Ej: Pérez"
                        required
                        autocomplete="off"
                    >
                </div>

                <button type="submit" class="evapp-netsearch-btn" id="evappNetSearchBtn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                        <circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/>
                        <path d="M21 21L16.65 16.65" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Buscar mis eventos
                </button>

                <div class="evapp-netsearch-message info" style="display:none;" id="evappNetSearchMsg">
                    Ingresa tus datos tal como fueron registrados en el ticket.
                </div>
            </form>

            <div class="evapp-netsearch-results" id="evappNetSearchResults">
                <div class="evapp-netsearch-results-title">Tus eventos de hoy</div>
                <div id="evappNetSearchResultsContent"></div>
            </div>
        </div>
    </div>

    <script>
    (function(){
        const form = document.getElementById('evappNetSearchForm');
        const btn = document.getElementById('evappNetSearchBtn');
        const msg = document.getElementById('evappNetSearchMsg');
        const results = document.getElementById('evappNetSearchResults');
        const resultsContent = document.getElementById('evappNetSearchResultsContent');
        const inputCedula = document.getElementById('evappNetSearchCedula');
        const inputApellido = document.getElementById('evappNetSearchApellido');

        if (!form) return;

        const ajaxURL = '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>';
        const nonce = form.dataset.nonce || '';

        function showMessage(text, type = 'info') {
            msg.textContent = text;
            msg.className = 'evapp-netsearch-message ' + type;
            msg.style.display = 'block';
        }

        function hideMessage() {
            msg.style.display = 'none';
        }

        function showResults(events) {
            if (!events || events.length === 0) {
                showMessage('No se encontraron eventos para hoy con los datos ingresados.', 'error');
                results.classList.remove('show');
                return;
            }

            hideMessage();
            
            let html = '';
            events.forEach(function(event) {
                html += '<div class="evapp-netsearch-event-card">';
                html += '<h3 class="evapp-netsearch-event-title">' + escapeHtml(event.title) + '</h3>';
                html += '<div class="evapp-netsearch-event-date">';
                html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="2"/></svg>';
                html += escapeHtml(event.date);
                html += '</div>';
                html += '<a href="' + escapeHtml(event.url) + '" class="evapp-netsearch-event-btn">';
                html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2"/><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="2"/></svg>';
                html += 'Acceder al Networking';
                html += '</a>';
                html += '</div>';
            });

            resultsContent.innerHTML = html;
            results.classList.add('show');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const cedula = inputCedula.value.trim();
            const apellido = inputApellido.value.trim();

            if (!cedula || !apellido) {
                showMessage('Por favor completa todos los campos.', 'error');
                return;
            }

            // Deshabilitar formulario
            btn.disabled = true;
            btn.innerHTML = '<span class="evapp-netsearch-loading"></span> Buscando...';
            hideMessage();
            results.classList.remove('show');

            // Hacer petición AJAX
            const formData = new FormData();
            formData.append('action', 'eventosapp_networking_search');
            formData.append('cedula', cedula);
            formData.append('apellido', apellido);
            formData.append('nonce', nonce);

            fetch(ajaxURL, {
                method: 'POST',
                body: formData
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (data.success && data.data && data.data.events) {
                    showResults(data.data.events);
                } else {
                    const errorMsg = data.data && data.data.message 
                        ? data.data.message 
                        : 'No se encontraron eventos para los datos ingresados.';
                    showMessage(errorMsg, 'error');
                    results.classList.remove('show');
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                showMessage('Error de conexión. Por favor, intenta nuevamente.', 'error');
                results.classList.remove('show');
            })
            .finally(function() {
                // Restaurar botón
                btn.disabled = false;
                btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/><path d="M21 21L16.65 16.65" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg> Buscar mis eventos';
            });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
});

/**
 * Handler AJAX para buscar eventos por cédula y apellido
 */
add_action('wp_ajax_eventosapp_networking_search', 'eventosapp_networking_search_handler');
add_action('wp_ajax_nopriv_eventosapp_networking_search', 'eventosapp_networking_search_handler');

function eventosapp_networking_search_handler() {
    // Verificar nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'eventosapp_networking_search')) {
        wp_send_json_error(['message' => 'Error de seguridad. Recarga la página e intenta nuevamente.']);
    }

    // Obtener y limpiar datos
    $cedula = isset($_POST['cedula']) ? sanitize_text_field(wp_unslash($_POST['cedula'])) : '';
    $apellido = isset($_POST['apellido']) ? sanitize_text_field(wp_unslash($_POST['apellido'])) : '';

    if (empty($cedula) || empty($apellido)) {
        wp_send_json_error(['message' => 'Por favor completa todos los campos.']);
    }

    // Buscar eventos del día actual
    $events = eventosapp_find_today_events_by_attendee($cedula, $apellido);

    if (empty($events)) {
        wp_send_json_error(['message' => 'No se encontraron eventos para hoy con los datos ingresados. Verifica que la cédula y el apellido sean correctos.']);
    }

    wp_send_json_success(['events' => $events]);
}

/**
 * Busca eventos del día actual donde el asistente está inscrito
 * 
 * @param string $cedula Cédula del asistente
 * @param string $apellido Apellido del asistente
 * @return array Array de eventos con información básica
 */
function eventosapp_find_today_events_by_attendee($cedula, $apellido) {
    global $wpdb;

    // Fecha actual en formato Y-m-d
    $today = current_time('Y-m-d');
    
    // Buscar todos los tickets que coincidan con la cédula y apellido
    $query = $wpdb->prepare("
        SELECT DISTINCT p.ID as ticket_id, 
               pm_evento.meta_value as evento_id
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm_campos ON p.ID = pm_campos.post_id 
            AND pm_campos.meta_key = '_eventosapp_campos_adicionales'
        INNER JOIN {$wpdb->postmeta} pm_evento ON p.ID = pm_evento.post_id 
            AND pm_evento.meta_key = '_eventosapp_ticket_evento_id'
        WHERE p.post_type = 'eventosapp_ticket'
        AND p.post_status = 'publish'
    ");

    $tickets = $wpdb->get_results($query, ARRAY_A);

    if (empty($tickets)) {
        return [];
    }

    $matching_events = [];
    $processed_events = []; // Para evitar duplicados

    foreach ($tickets as $ticket) {
        $ticket_id = (int) $ticket['ticket_id'];
        $evento_id = (int) $ticket['evento_id'];

        // Evitar procesar el mismo evento múltiples veces
        if (in_array($evento_id, $processed_events)) {
            continue;
        }

        // Obtener campos adicionales del ticket
        $campos = get_post_meta($ticket_id, '_eventosapp_campos_adicionales', true);
        
        if (!is_array($campos)) {
            continue;
        }

        // Verificar cédula
        $ticket_cedula = isset($campos['documento_de_identificacion']) 
            ? trim($campos['documento_de_identificacion']) 
            : '';
        
        // Verificar apellido
        $ticket_apellido = isset($campos['apellidos']) 
            ? trim($campos['apellidos']) 
            : '';

        // Comparar (case insensitive para apellido)
        if (
            $ticket_cedula === trim($cedula) && 
            strcasecmp($ticket_apellido, trim($apellido)) === 0
        ) {
            // Verificar que el evento sea de hoy
            if (eventosapp_is_event_today($evento_id, $today)) {
                $event_data = eventosapp_get_event_networking_data($evento_id, $today);
                
                if ($event_data) {
                    $matching_events[] = $event_data;
                    $processed_events[] = $evento_id;
                }
            }
        }
    }

    return $matching_events;
}

/**
 * Verifica si un evento se está ejecutando en la fecha especificada
 * 
 * @param int $event_id ID del evento
 * @param string $date Fecha en formato Y-m-d
 * @return bool
 */
function eventosapp_is_event_today($event_id, $date) {
    // Obtener fechas del evento
    $fecha_inicio = get_post_meta($event_id, '_eventosapp_fecha_inicio', true);
    $fecha_fin = get_post_meta($event_id, '_eventosapp_fecha_fin', true);

    if (empty($fecha_inicio)) {
        return false;
    }

    // Si no hay fecha fin, usar la fecha inicio
    if (empty($fecha_fin)) {
        $fecha_fin = $fecha_inicio;
    }

    // Convertir a timestamps para comparación
    $date_ts = strtotime($date);
    $inicio_ts = strtotime($fecha_inicio);
    $fin_ts = strtotime($fecha_fin);

    // Verificar si la fecha está dentro del rango del evento
    return ($date_ts >= $inicio_ts && $date_ts <= $fin_ts);
}

/**
 * Obtiene los datos del evento para networking
 * 
 * @param int $event_id ID del evento
 * @param string $date Fecha del evento
 * @return array|false Array con datos del evento o false si no hay URL de networking
 */
function eventosapp_get_event_networking_data($event_id, $date) {
    // Obtener URL de networking
    $networking_url = get_post_meta($event_id, '_eventosapp_networking_url', true);

    // Si no hay URL de networking, intentar generarla
    if (empty($networking_url)) {
        // Verificar si existe la función para construir la URL
        if (function_exists('eventosapp_networking_build_url')) {
            $networking_url = eventosapp_networking_build_url($event_id);
        }
    }

    // Si aún no hay URL, no incluir este evento
    if (empty($networking_url)) {
        return false;
    }

    // Obtener título del evento
    $title = get_the_title($event_id);

    // Formatear fecha para mostrar
    $fecha_inicio = get_post_meta($event_id, '_eventosapp_fecha_inicio', true);
    $fecha_fin = get_post_meta($event_id, '_eventosapp_fecha_fin', true);

    $date_display = '';
    if ($fecha_inicio) {
        $fecha_inicio_obj = date_create($fecha_inicio);
        if ($fecha_inicio_obj) {
            if ($fecha_fin && $fecha_fin !== $fecha_inicio) {
                $fecha_fin_obj = date_create($fecha_fin);
                if ($fecha_fin_obj) {
                    $date_display = date_i18n('j \d\e F', $fecha_inicio_obj->getTimestamp()) . 
                                  ' - ' . 
                                  date_i18n('j \d\e F \d\e Y', $fecha_fin_obj->getTimestamp());
                } else {
                    $date_display = date_i18n('j \d\e F \d\e Y', $fecha_inicio_obj->getTimestamp());
                }
            } else {
                $date_display = date_i18n('j \d\e F \d\e Y', $fecha_inicio_obj->getTimestamp());
            }
        }
    }

    return [
        'id' => $event_id,
        'title' => $title,
        'url' => $networking_url,
        'date' => $date_display ?: $date,
    ];
}
