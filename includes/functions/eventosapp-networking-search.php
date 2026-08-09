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
    <style id="eventosapp-networking-search-ui">
        .evapp-netsearch-wrapper{
            --evapp-primary:#3279bd;--evapp-primary-dark:#255f96;--evapp-primary-soft:#eaf4ff;
            --evapp-app-bg:#f5f8fc;--evapp-surface:#ffffff;--evapp-border:#dfe7f1;--evapp-text:#182230;
            --evapp-muted:#64748b;--evapp-success:#15803d;--evapp-success-soft:#ecfdf3;--evapp-danger:#b42318;--evapp-danger-soft:#fff1f0;
            width:100%;max-width:820px;margin:0 auto;padding:clamp(18px,3vw,36px);color:var(--evapp-text);background:var(--evapp-app-bg);
            border:1px solid var(--evapp-border);border-radius:26px;box-shadow:0 18px 50px rgba(31,52,73,.08);font-family:inherit;line-height:1.45;box-sizing:border-box;isolation:isolate;
        }
        .evapp-netsearch-wrapper *,.evapp-netsearch-wrapper *::before,.evapp-netsearch-wrapper *::after{box-sizing:border-box}
        .evapp-netsearch-wrapper button,.evapp-netsearch-wrapper input{font:inherit}
        .evapp-netsearch-wrapper svg{display:block;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
        .evapp-netsearch-card{padding:0;background:transparent;border:0;box-shadow:none;color:var(--evapp-text)}
        .evapp-netsearch-header{margin-bottom:20px;text-align:left}
        .evapp-netsearch-eyebrow{margin:0 0 6px;color:var(--evapp-primary);font-size:12px;font-weight:800;letter-spacing:.11em;text-transform:uppercase}
        .evapp-netsearch-title{margin:0;color:var(--evapp-text);font-size:clamp(27px,5vw,42px);font-weight:850;line-height:1.08;letter-spacing:-.035em}
        .evapp-netsearch-subtitle{max-width:680px;margin:10px 0 0;color:var(--evapp-muted);font-size:15px;line-height:1.6}
        .evapp-netsearch-form,.evapp-netsearch-results{padding:20px;background:var(--evapp-surface);border:1px solid var(--evapp-border);border-radius:18px;box-shadow:0 8px 26px rgba(31,52,73,.05)}
        .evapp-netsearch-form{margin-top:0}.evapp-netsearch-field{margin-bottom:17px}
        .evapp-netsearch-field label{display:block;margin-bottom:7px;color:var(--evapp-text);font-size:12px;font-weight:800}
        .evapp-netsearch-field small{display:block!important;margin-top:6px!important;color:var(--evapp-muted)!important;font-size:11px!important;line-height:1.5!important}
        .evapp-netsearch-input{width:100%;min-height:46px;margin:0;padding:10px 12px;color:var(--evapp-text);background:#fff;border:1px solid var(--evapp-border);border-radius:13px;box-shadow:none;outline:none;font-size:16px;transition:border-color .16s ease,box-shadow .16s ease}
        .evapp-netsearch-input:focus{border-color:var(--evapp-primary);box-shadow:0 0 0 4px rgba(50,121,189,.12)}
        .evapp-netsearch-btn,.evapp-netsearch-event-btn{min-height:44px;display:inline-flex;align-items:center;justify-content:center;gap:8px;margin:0;padding:10px 15px;color:#fff!important;background:var(--evapp-primary);border:1px solid var(--evapp-primary);border-radius:12px;box-shadow:0 9px 20px rgba(50,121,189,.18);font-size:14px;font-weight:750;line-height:1.15;text-align:center;cursor:pointer;text-decoration:none!important;transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease,background .16s ease,color .16s ease,opacity .16s ease}
        .evapp-netsearch-btn{width:100%}.evapp-netsearch-btn:hover:not(:disabled),.evapp-netsearch-event-btn:hover{background:var(--evapp-primary-dark);border-color:var(--evapp-primary-dark);box-shadow:0 12px 24px rgba(50,121,189,.24);transform:translateY(-1px)}
        .evapp-netsearch-btn:disabled{opacity:.5;cursor:not-allowed;transform:none!important;box-shadow:none!important}
        .evapp-netsearch-btn:focus-visible,.evapp-netsearch-event-btn:focus-visible,.evapp-netsearch-input:focus-visible{outline:3px solid rgba(50,121,189,.20);outline-offset:2px}
        .evapp-netsearch-message{margin-top:14px;padding:12px 13px;border-radius:12px;font-size:13px;text-align:center;font-weight:730}
        .evapp-netsearch-message.success{background:var(--evapp-success-soft);color:var(--evapp-success);border:1px solid #c8ead4}.evapp-netsearch-message.error{background:var(--evapp-danger-soft);color:var(--evapp-danger);border:1px solid #f1c7c3}.evapp-netsearch-message.info{background:var(--evapp-primary-soft);color:var(--evapp-primary-dark);border:1px solid #cfe3f6}
        .evapp-netsearch-results{margin-top:16px;display:none}.evapp-netsearch-results.show{display:block}
        .evapp-netsearch-results-title{margin:0 0 13px;color:var(--evapp-text);font-size:17px;font-weight:820;line-height:1.3;text-align:left}
        .evapp-netsearch-event-card{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:14px;min-width:0;padding:15px;margin-bottom:10px;background:#fbfdff;border:1px solid var(--evapp-border);border-radius:14px;transition:border-color .16s ease,background .16s ease,box-shadow .16s ease,transform .16s ease}
        .evapp-netsearch-event-card:last-child{margin-bottom:0}.evapp-netsearch-event-card:hover{background:#fff;border-color:#c7d7e8;box-shadow:0 9px 20px rgba(31,65,99,.09);transform:translateY(-1px)}
        .evapp-netsearch-event-title{margin:0 0 5px;color:var(--evapp-text);font-size:15px;font-weight:820;line-height:1.3}.evapp-netsearch-event-date{display:flex;align-items:center;gap:6px;margin:0;color:var(--evapp-muted);font-size:12px;line-height:1.45}.evapp-netsearch-event-date svg{width:16px;height:16px;flex:0 0 16px}
        .evapp-netsearch-event-btn{grid-column:2;grid-row:1/span 2;white-space:nowrap}
        .evapp-netsearch-loading{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:evapp-spin .8s linear infinite}@keyframes evapp-spin{to{transform:rotate(360deg)}}
        @media(max-width:767px){.evapp-netsearch-wrapper{padding:16px;border-radius:20px}.evapp-netsearch-event-card{grid-template-columns:1fr}.evapp-netsearch-event-btn{grid-column:1;grid-row:auto;width:100%;margin-top:4px}}
        @media(max-width:520px){.evapp-netsearch-form,.evapp-netsearch-results{padding:16px}.evapp-netsearch-title{font-size:30px}}
        @media(prefers-reduced-motion:reduce){.evapp-netsearch-wrapper *,.evapp-netsearch-wrapper *::before,.evapp-netsearch-wrapper *::after{scroll-behavior:auto!important;animation:none!important;transition:none!important}}
    </style>

    <div class="evapp-netsearch-wrapper">
        <div class="evapp-netsearch-card">
            <header class="evapp-netsearch-header">
                <p class="evapp-netsearch-eyebrow">EVENTOSAPP</p>
                <h1 class="evapp-netsearch-title"><?php echo esc_html($atts['title']); ?></h1>
                <p class="evapp-netsearch-subtitle"><?php echo esc_html($atts['subtitle']); ?></p>
            </header>

            <form class="evapp-netsearch-form" id="evappNetSearchForm" data-nonce="<?php echo esc_attr($nonce); ?>">
                <div class="evapp-netsearch-field">
                    <label for="evappNetSearchCedula">Cédula / Documento de Identidad</label>
                    <input type="text" id="evappNetSearchCedula" class="evapp-netsearch-input" placeholder="Ej: 1020304050" inputmode="numeric" required autocomplete="off">
                    <small>Escribe tal cual como está en tu inscripción.</small>
                </div>
                <div class="evapp-netsearch-field">
                    <label for="evappNetSearchApellido">Apellidos</label>
                    <input type="text" id="evappNetSearchApellido" class="evapp-netsearch-input" placeholder="Ej: Pérez García" required autocomplete="family-name">
                    <small>Escribe tal cual como están en tu inscripción.</small>
                </div>

                <button type="submit" class="evapp-netsearch-btn" id="evappNetSearchBtn">
                    <svg width="18" height="18" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><path d="M21 21L16.65 16.65"></path></svg>
                    Buscar mis eventos
                </button>
                <div class="evapp-netsearch-message" style="display:none;" id="evappNetSearchMsg" aria-live="polite"></div>
            </form>

            <div class="evapp-netsearch-results" id="evappNetSearchResults" aria-live="polite">
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

    $today          = current_time('Y-m-d');
    $today_ts       = strtotime($today);
    $cedula_limpia  = trim((string) $cedula);
    $apellido_input = trim((string) $apellido);

    if ($cedula_limpia === '' || $apellido_input === '') {
        return [];
    }

    // Primero reducimos el universo a los tickets que coinciden con la cédula.
    // La versión anterior recorría todos los eventos publicados antes de saber
    // si el asistente tenía un ticket en ellos, lo que crecía innecesariamente.
    $tickets = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT p.ID AS ticket_id,
                CAST(pm_evento.meta_value AS UNSIGNED) AS evento_id,
                pm_apellido.meta_value AS apellido
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm_cc
                 ON p.ID = pm_cc.post_id
                AND pm_cc.meta_key = '_eventosapp_asistente_cc'
                AND pm_cc.meta_value = %s
         INNER JOIN {$wpdb->postmeta} pm_apellido
                 ON p.ID = pm_apellido.post_id
                AND pm_apellido.meta_key = '_eventosapp_asistente_apellido'
         INNER JOIN {$wpdb->postmeta} pm_evento
                 ON p.ID = pm_evento.post_id
                AND pm_evento.meta_key = '_eventosapp_ticket_evento_id'
         WHERE p.post_type = 'eventosapp_ticket'
           AND p.post_status = 'publish'",
        $cedula_limpia
    ), ARRAY_A);

    if (empty($tickets)) {
        return [];
    }

    $normalize_name = static function($value) {
        $value = remove_accents(trim((string) $value));
        $value = strtolower($value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim($value);
    };

    $apellido_normalizado = $normalize_name($apellido_input);
    $matching_events      = [];
    $processed_events     = [];

    foreach ($tickets as $ticket) {
        $evento_id = absint($ticket['evento_id'] ?? 0);
        if (!$evento_id || isset($processed_events[$evento_id])) {
            continue;
        }

        if ($normalize_name($ticket['apellido'] ?? '') !== $apellido_normalizado) {
            continue;
        }

        // Solo ahora verificamos si ese evento concreto está activo hoy.
        if (!eventosapp_is_event_today($evento_id, $today, $today_ts)) {
            continue;
        }

        $event_data = eventosapp_get_event_networking_data($evento_id, $today);
        if ($event_data) {
            $matching_events[] = $event_data;
            $processed_events[$evento_id] = true;
        }
    }

    return $matching_events;
}

/**
 * Obtiene todos los eventos que están activos en la fecha especificada
 * Considera los 3 tipos de fecha: única, consecutiva y no consecutiva
 * 
 * @param string $date Fecha en formato Y-m-d
 * @return array Array de IDs de eventos activos
 */
function eventosapp_get_all_events_today($date) {
    global $wpdb;
    
    // Obtener todos los eventos publicados
    $eventos = $wpdb->get_results("
        SELECT ID 
        FROM {$wpdb->posts} 
        WHERE post_type = 'eventosapp_event' 
        AND post_status = 'publish'
    ", ARRAY_A);
    
    if (empty($eventos)) {
        return [];
    }
    
    $today_events = [];
    $date_ts = strtotime($date);
    
    foreach ($eventos as $evento) {
        $event_id = (int) $evento['ID'];
        
        if (eventosapp_is_event_today($event_id, $date, $date_ts)) {
            $today_events[] = $event_id;
        }
    }
    
    return $today_events;
}

/**
 * Verifica si un evento se está ejecutando en la fecha especificada
 * Soporta los 3 tipos de fecha: única, consecutiva y no consecutiva
 * 
 * @param int $event_id ID del evento
 * @param string $date Fecha en formato Y-m-d
 * @param int $date_ts (Opcional) Timestamp de la fecha para optimización
 * @return bool
 */
function eventosapp_is_event_today($event_id, $date, $date_ts = null) {
    if ($date_ts === null) {
        $date_ts = strtotime($date);
    }
    
    // Obtener el tipo de fecha del evento
    $tipo_fecha = get_post_meta($event_id, '_eventosapp_tipo_fecha', true);
    
    if (empty($tipo_fecha)) {
        $tipo_fecha = 'unica'; // Default
    }
    
    switch ($tipo_fecha) {
        case 'unica':
            // Evento de fecha única
            $fecha_unica = get_post_meta($event_id, '_eventosapp_fecha_unica', true);
            if (empty($fecha_unica)) {
                return false;
            }
            $fecha_unica_ts = strtotime($fecha_unica);
            return ($date_ts === $fecha_unica_ts);
            
        case 'consecutiva':
            // Evento con fecha de inicio y fin consecutivas
            $fecha_inicio = get_post_meta($event_id, '_eventosapp_fecha_inicio', true);
            $fecha_fin = get_post_meta($event_id, '_eventosapp_fecha_fin', true);
            
            if (empty($fecha_inicio)) {
                return false;
            }
            
            // Si no hay fecha fin, usar la fecha inicio
            if (empty($fecha_fin)) {
                $fecha_fin = $fecha_inicio;
            }
            
            $inicio_ts = strtotime($fecha_inicio);
            $fin_ts = strtotime($fecha_fin);
            
            // Verificar si la fecha está dentro del rango
            return ($date_ts >= $inicio_ts && $date_ts <= $fin_ts);
            
        case 'noconsecutiva':
            // Evento con fechas no consecutivas (array de fechas)
            $fechas_noco = get_post_meta($event_id, '_eventosapp_fechas_noco', true);
            
            if (!is_array($fechas_noco) || empty($fechas_noco)) {
                return false;
            }
            
            // Verificar si la fecha actual está en el array
            foreach ($fechas_noco as $fecha_noco) {
                if (strtotime($fecha_noco) === $date_ts) {
                    return true;
                }
            }
            return false;
            
        default:
            return false;
    }
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
