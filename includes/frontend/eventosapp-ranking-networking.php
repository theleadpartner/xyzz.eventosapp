<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Ranking de Networking - Top 10 usuarios más activos
 * Shortcode: [eventosapp_ranking_networking]
 */

add_shortcode('eventosapp_ranking_networking', function(){
    if ( ! is_user_logged_in() ) {
        $login = wp_login_url( get_permalink() );
        return '<p>Debes iniciar sesión. <a href="'.esc_url($login).'">Iniciar sesión</a></p>';
    }

    // ✅ CORREGIDO: Cambié 'ranking_networking' por 'networking_ranking'
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('networking_ranking') ) {
        return '<p>No tienes permisos para ver este ranking.</p>';
    }

    $active_event = function_exists('eventosapp_get_active_event') ? eventosapp_get_active_event() : 0;
    if ( ! $active_event ) {
        return '<p>No hay un evento activo seleccionado. Por favor, selecciona un evento desde el <a href="'.esc_url(eventosapp_get_dashboard_url()).'">dashboard</a>.</p>';
    }

    $evento_nombre = get_the_title($active_event);
    $fecha_actual = date_i18n('l, j \d\e F \d\e Y');
    $dashboard_url = function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/');

    ob_start();
    ?>
    <style>
    .evapp-ranking-wrapper{
        --evapp-primary:#3279bd;--evapp-primary-dark:#255f96;--evapp-primary-soft:#eaf4ff;
        --evapp-app-bg:#f5f8fc;--evapp-surface:#fff;--evapp-border:#dfe7f1;--evapp-text:#182230;
        --evapp-muted:#64748b;--evapp-success:#15803d;--evapp-danger:#b42318;
        --evapp-gold:#d6a815;--evapp-silver:#8a98a8;--evapp-bronze:#b5793e;
        width:100%;max-width:1240px;margin:0 auto;padding:0 10px;color:var(--evapp-text);font-family:inherit;line-height:1.45;
    }
    .evapp-ranking-wrapper,.evapp-ranking-wrapper *{box-sizing:border-box}
    .evapp-ranking-backbar{display:flex;margin:0 0 12px}
    .evapp-ranking-back{display:inline-flex;align-items:center;gap:8px;min-height:40px;padding:9px 13px;border:1px solid #cfe3f6;border-radius:12px;background:var(--evapp-primary-soft);color:var(--evapp-primary)!important;text-decoration:none!important;font-size:13px;font-weight:750;transition:.18s ease}
    .evapp-ranking-back:hover{background:var(--evapp-primary);border-color:var(--evapp-primary);color:#fff!important;transform:translateY(-1px)}
    .evapp-ranking-back svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2.2}
    .evapp-ranking-shell{padding:clamp(18px,3vw,34px);background:var(--evapp-app-bg);border:1px solid var(--evapp-border);border-radius:26px;box-shadow:0 18px 50px rgba(31,52,73,.08)}
    .evapp-ranking-header{display:grid;grid-template-columns:auto minmax(0,1fr);align-items:center;gap:15px;padding:0;margin:0 0 20px;background:transparent;color:var(--evapp-text);box-shadow:none}
    .evapp-ranking-header-icon{display:flex;align-items:center;justify-content:center;width:58px;height:58px;color:#fff;background:linear-gradient(145deg,var(--evapp-primary),var(--evapp-primary-dark));border-radius:17px;box-shadow:0 8px 18px rgba(47,115,181,.22);font-size:27px}
    .evapp-ranking-eyebrow{margin:0 0 3px;color:var(--evapp-primary);font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
    .evapp-ranking-header h1{margin:0;color:var(--evapp-text);font-size:clamp(1.35rem,3vw,1.9rem);font-weight:850;letter-spacing:-.025em;line-height:1.18}
    .evapp-ranking-header-meta{display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:7px;color:var(--evapp-muted);font-size:.88rem}.evapp-ranking-header-meta strong{color:var(--evapp-text)}
    .evapp-ranking-controls{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 18px;padding:14px;background:var(--evapp-surface);border:1px solid var(--evapp-border);border-radius:16px}
    .evapp-refresh-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:10px 15px;border:1px solid var(--evapp-primary);border-radius:11px;background:var(--evapp-primary);color:#fff;font:inherit;font-size:.9rem;font-weight:800;cursor:pointer;transition:.18s ease;box-shadow:0 6px 14px rgba(47,115,181,.16)}
    .evapp-refresh-btn:hover{background:var(--evapp-primary-dark);border-color:var(--evapp-primary-dark);transform:translateY(-1px)}.evapp-refresh-btn:disabled{opacity:.65;cursor:not-allowed;transform:none}.evapp-refresh-btn svg{width:18px;height:18px}.evapp-refresh-btn.loading svg{animation:evapp-rank-spin .9s linear infinite}@keyframes evapp-rank-spin{to{transform:rotate(360deg)}}
    .evapp-last-update{color:var(--evapp-muted);font-size:.84rem}
    .evapp-landing-section{background:var(--evapp-surface);border:1px solid var(--evapp-border);border-radius:18px;padding:18px;margin-bottom:18px;box-shadow:0 8px 24px rgba(31,52,73,.04)}
    .evapp-landing-title{font-size:.95rem;font-weight:800;color:var(--evapp-text);margin:0 0 10px}.evapp-landing-controls{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:9px;align-items:stretch}.evapp-landing-url-wrapper{min-width:0}.evapp-landing-url{width:100%;height:44px;padding:10px 12px;border:1px solid var(--evapp-border);border-radius:11px;font:inherit;font-size:.86rem;color:var(--evapp-text);background:#f8fbff}.evapp-landing-url:focus{outline:none;border-color:var(--evapp-primary);box-shadow:0 0 0 4px rgba(50,121,189,.12)}
    .evapp-landing-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:44px;padding:10px 13px;border:1px solid var(--evapp-border);border-radius:11px;font:inherit;font-size:.86rem;font-weight:800;cursor:pointer;transition:.18s ease;white-space:nowrap}.evapp-landing-btn svg{width:17px;height:17px}.evapp-landing-btn-copy{background:var(--evapp-primary);border-color:var(--evapp-primary);color:#fff}.evapp-landing-btn-copy:hover{background:var(--evapp-primary-dark);border-color:var(--evapp-primary-dark)}.evapp-landing-btn-copy.copied{background:var(--evapp-success);border-color:var(--evapp-success)}.evapp-landing-btn-open{background:#fff;color:var(--evapp-text)}.evapp-landing-btn-open:hover{background:var(--evapp-primary-soft);border-color:#b9d4ed;color:var(--evapp-primary)}
    .evapp-ranking-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:0}.evapp-ranking-card{min-width:0;background:var(--evapp-surface);border:1px solid var(--evapp-border);border-radius:18px;box-shadow:0 8px 24px rgba(31,52,73,.045);overflow:hidden}.evapp-ranking-card-header{padding:16px 18px;background:#f8fbff;border-bottom:1px solid var(--evapp-border)}.evapp-ranking-card-header h2{display:flex;align-items:center;gap:9px;margin:0;color:var(--evapp-text);font-size:1rem;font-weight:820;line-height:1.3}.evapp-ranking-card-header svg{width:21px;height:21px;color:var(--evapp-primary)}
    .evapp-ranking-list{padding:8px;margin:0;list-style:none}.evapp-ranking-item{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:12px;min-width:0;padding:11px 10px;border-bottom:1px solid #edf2f7;transition:background-color .15s ease}.evapp-ranking-item:last-child{border-bottom:0}.evapp-ranking-item:hover{background:#f8fbff}.evapp-ranking-position{display:flex;align-items:center;justify-content:center;flex-shrink:0;width:38px;height:38px;border-radius:12px;background:#eef3f8;color:var(--evapp-muted);font-weight:850;font-size:.9rem}.evapp-ranking-info{min-width:0}.evapp-ranking-name{font-weight:780;color:var(--evapp-text);font-size:.92rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.evapp-ranking-count{display:inline-flex;align-items:center;justify-content:center;min-width:42px;height:32px;padding:0 9px;border-radius:10px;background:var(--evapp-primary-soft);color:var(--evapp-primary-dark);font-weight:850;font-size:.9rem}
    .evapp-ranking-item.rank-1{background:#fffaf0}.evapp-ranking-item.rank-1 .evapp-ranking-position{background:#f6e6a6;color:#6f5700}.evapp-ranking-item.rank-2 .evapp-ranking-position{background:#e8edf2;color:#566474}.evapp-ranking-item.rank-3 .evapp-ranking-position{background:#f1dfcd;color:#7a4c24}.evapp-ranking-item.rank-1 .evapp-ranking-count,.evapp-ranking-item.rank-2 .evapp-ranking-count,.evapp-ranking-item.rank-3 .evapp-ranking-count{font-size:.95rem}
    .evapp-ranking-empty{padding:34px 18px;text-align:center;color:var(--evapp-muted);font-size:.9rem}.evapp-ranking-empty svg{width:40px;height:40px;margin-bottom:8px;opacity:.45}.evapp-ranking-empty p{margin:0}
    @media(max-width:900px){.evapp-ranking-grid{grid-template-columns:1fr}}
    @media(max-width:640px){.evapp-ranking-wrapper{padding:0}.evapp-ranking-shell{padding:16px;border-radius:20px}.evapp-ranking-back{width:100%;justify-content:center}.evapp-ranking-header{grid-template-columns:1fr}.evapp-ranking-header-icon{width:50px;height:50px}.evapp-ranking-controls{align-items:stretch}.evapp-refresh-btn{width:100%}.evapp-last-update{width:100%;text-align:center}.evapp-landing-controls{grid-template-columns:1fr}.evapp-landing-btn{width:100%}.evapp-ranking-item{gap:9px}.evapp-ranking-position{width:34px;height:34px}.evapp-ranking-name{white-space:normal;overflow:visible}}
    @media(prefers-reduced-motion:reduce){.evapp-ranking-wrapper *{scroll-behavior:auto!important;transition:none!important}}
    </style>

    <div class="evapp-ranking-wrapper" data-event-id="<?php echo esc_attr($active_event); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('eventosapp_ranking_networking')); ?>">
        <div class="evapp-ranking-backbar">
            <a class="evapp-ranking-back" href="<?php echo esc_url($dashboard_url); ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                Volver al dashboard
            </a>
        </div>
        <div class="evapp-ranking-shell">
        <div class="evapp-ranking-header">
            <span class="evapp-ranking-header-icon" aria-hidden="true">🏆</span>
            <div>
                <p class="evapp-ranking-eyebrow">EventosApp · Networking</p>
                <h1>Ranking de Networking</h1>
                <div class="evapp-ranking-header-meta">
                    <span><strong>Evento:</strong> <?php echo esc_html($evento_nombre); ?></span>
                    <span><?php echo esc_html($fecha_actual); ?></span>
                    <span>Conexiones únicas acumuladas</span>
                </div>
            </div>
        </div>

        <div class="evapp-ranking-controls">
            <button class="evapp-refresh-btn" id="evapp-refresh-ranking">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
                </svg>
                Actualizar Ranking
            </button>
            <div class="evapp-last-update">
                Última actualización: <span id="evapp-last-update-time">—</span>
            </div>
        </div>

        <?php
        // URL real de la landing de Networking del evento.
        // Evitamos reutilizar la página de Check-In QR con doble autenticación,
        // porque es un módulo diferente aunque comparta concepto de autenticación.
        $networking_landing_url = '';
        $networking_page_id = absint(get_post_meta($active_event, '_eventosapp_networking_page_id', true));
        if ($networking_page_id) {
            $networking_landing_url = get_permalink($networking_page_id);
        }
        if (!$networking_landing_url && function_exists('eventosapp_networking_build_url')) {
            $networking_landing_url = eventosapp_networking_build_url($active_event);
        }
        ?>

        <?php if (!empty($networking_landing_url) && $networking_landing_url !== '#'): ?>
        <div class="evapp-landing-section">
            <h3 class="evapp-landing-title">Landing del networking (evento)</h3>
            <div class="evapp-landing-controls">
                <div class="evapp-landing-url-wrapper">
                    <input 
                        type="text" 
                        class="evapp-landing-url" 
                        id="evapp-networking-url" 
                        value="<?php echo esc_attr($networking_landing_url); ?>" 
                        readonly
                    >
                </div>
                <button class="evapp-landing-btn evapp-landing-btn-copy" id="evapp-copy-url" type="button">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                    </svg>
                    <span id="evapp-copy-text">Copiar</span>
                </button>
                <button class="evapp-landing-btn evapp-landing-btn-open" id="evapp-open-url" type="button">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                        <polyline points="15 3 21 3 21 9"/>
                        <line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                    Abrir
                </button>
            </div>
        </div>
        <?php endif; ?>

        <div class="evapp-ranking-grid">
            <!-- Top Lectores -->
            <div class="evapp-ranking-card">
                <div class="evapp-ranking-card-header">
                    <h2>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                        </svg>
                        Top Lectores de Contactos
                    </h2>
                </div>
                <ul class="evapp-ranking-list" id="evapp-ranking-readers">
                    <li class="evapp-ranking-empty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 6v6l4 2"/>
                        </svg>
                        <p>Cargando datos del ranking...</p>
                    </li>
                </ul>
            </div>

            <!-- Top Leídos -->
            <div class="evapp-ranking-card">
                <div class="evapp-ranking-card-header">
                    <h2>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                        Top Contactos Más Leídos
                    </h2>
                </div>
                <ul class="evapp-ranking-list" id="evapp-ranking-read">
                    <li class="evapp-ranking-empty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 6v6l4 2"/>
                        </svg>
                        <p>Cargando datos del ranking...</p>
                    </li>
                </ul>
            </div>
        </div>

        </div>
    </div>

    <script>
    (function(){
        const wrapper = document.querySelector('.evapp-ranking-wrapper');
        if (!wrapper) return;

        const eventId = wrapper.dataset.eventId;
        const nonce = wrapper.dataset.nonce;
        const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        
        const readersContainer = document.getElementById('evapp-ranking-readers');
        const readContainer = document.getElementById('evapp-ranking-read');
        const refreshBtn = document.getElementById('evapp-refresh-ranking');
        const lastUpdateSpan = document.getElementById('evapp-last-update-time');
        let isLoading = false;
        let lastLoadedAt = 0;

        function escapeHtml(value) {
            const div = document.createElement('div');
            div.textContent = String(value ?? '');
            return div.innerHTML;
        }

        function formatTime() {
            const now = new Date();
            return now.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }

        function renderRanking(data, container, type) {
            if (!data || data.length === 0) {
                container.innerHTML = `
                    <li class="evapp-ranking-empty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M16 16s-1.5-2-4-2-4 2-4 2M9 9h.01M15 9h.01"/>
                        </svg>
                        <p>Aún no hay datos de ${type === 'readers' ? 'lecturas' : 'contactos leídos'}</p>
                    </li>
                `;
                return;
            }

            let html = '';
            data.forEach((item, index) => {
                const rank = index + 1;
                html += `
                    <li class="evapp-ranking-item rank-${rank}">
                        <div class="evapp-ranking-position">${rank}</div>
                        <div class="evapp-ranking-info">
                            <div class="evapp-ranking-name">${escapeHtml(item.nombre)}</div>
                        </div>
                        <div class="evapp-ranking-count">${item.cantidad}</div>
                    </li>
                `;
            });
            container.innerHTML = html;
        }

        function loadRanking() {
            if (isLoading) return;
            isLoading = true;
            refreshBtn.classList.add('loading');
            refreshBtn.disabled = true;

            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'eventosapp_get_ranking_networking',
                    security: nonce,
                    event_id: eventId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderRanking(data.data.top_readers, readersContainer, 'readers');
                    renderRanking(data.data.top_read, readContainer, 'read');
                    lastUpdateSpan.textContent = formatTime();
                    lastLoadedAt = Date.now();
                } else {
                    console.error('Error al cargar ranking:', data);
                    readersContainer.innerHTML = `<li class="evapp-ranking-empty"><p>Error al cargar datos</p></li>`;
                    readContainer.innerHTML = `<li class="evapp-ranking-empty"><p>Error al cargar datos</p></li>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                readersContainer.innerHTML = `<li class="evapp-ranking-empty"><p>Error de conexión</p></li>`;
                readContainer.innerHTML = `<li class="evapp-ranking-empty"><p>Error de conexión</p></li>`;
            })
            .finally(() => {
                isLoading = false;
                refreshBtn.classList.remove('loading');
                refreshBtn.disabled = false;
            });
        }

        // Event listeners
        refreshBtn.addEventListener('click', loadRanking);

        // Auto-refresh cada 30 segundos solo cuando la pestaña está visible.
        setInterval(() => { if (!document.hidden) loadRanking(); }, 30000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && (!lastLoadedAt || Date.now() - lastLoadedAt > 30000)) {
                loadRanking();
            }
        });

        // Funcionalidad de copiar URL
        const copyBtn = document.getElementById('evapp-copy-url');
        const openBtn = document.getElementById('evapp-open-url');
        const urlInput = document.getElementById('evapp-networking-url');
        const copyText = document.getElementById('evapp-copy-text');

        if (copyBtn && urlInput) {
            copyBtn.addEventListener('click', function() {
                // Seleccionar y copiar el texto
                urlInput.select();
                urlInput.setSelectionRange(0, 99999); // Para dispositivos móviles
                
                try {
                    // Intentar copiar con la API moderna
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(urlInput.value).then(function() {
                            // Cambiar el botón a estado "copiado"
                            copyBtn.classList.add('copied');
                            copyText.textContent = '¡Copiado!';
                            
                            // Restaurar después de 2 segundos
                            setTimeout(function() {
                                copyBtn.classList.remove('copied');
                                copyText.textContent = 'Copiar';
                            }, 2000);
                        }).catch(function(err) {
                            console.error('Error al copiar:', err);
                            fallbackCopy();
                        });
                    } else {
                        fallbackCopy();
                    }
                } catch (err) {
                    fallbackCopy();
                }
                
                function fallbackCopy() {
                    // Método fallback para navegadores antiguos
                    try {
                        document.execCommand('copy');
                        copyBtn.classList.add('copied');
                        copyText.textContent = '¡Copiado!';
                        
                        setTimeout(function() {
                            copyBtn.classList.remove('copied');
                            copyText.textContent = 'Copiar';
                        }, 2000);
                    } catch (err) {
                        console.error('Error al copiar:', err);
                        alert('No se pudo copiar la URL. Por favor, cópiala manualmente.');
                    }
                }
            });
        }

        // Funcionalidad de abrir URL
        if (openBtn && urlInput) {
            openBtn.addEventListener('click', function() {
                window.open(urlInput.value, '_blank');
            });
        }

        // Carga inicial
        loadRanking();
    })();
    </script>
    <?php
    return ob_get_clean();
});

/**
 * AJAX: Obtener ranking de networking
 */
add_action('wp_ajax_eventosapp_get_ranking_networking', 'eventosapp_get_ranking_networking_ajax');

function eventosapp_get_ranking_networking_ajax() {
    check_ajax_referer('eventosapp_ranking_networking', 'security');

    if ( ! is_user_logged_in() ) {
        wp_send_json_error(['message' => 'Debes iniciar sesión.'], 401);
    }
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('networking_ranking') ) {
        wp_send_json_error(['message' => 'No tienes permisos para consultar el ranking.'], 403);
    }

    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    
    if (!$event_id) {
        wp_send_json_error(['message' => 'ID de evento no válido']);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'eventosapp_networking';

    // Verificar que la tabla existe sin interpolar directamente el identificador.
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if (!$table_exists) {
        wp_send_json_error(['message' => 'Tabla de networking no encontrada']);
    }

    // Top 10 lectores (quienes más han leído)
    $top_readers_query = $wpdb->prepare("
        SELECT 
            reader_ticket_id as ticket_id,
            COUNT(DISTINCT read_ticket_id) as cantidad
        FROM {$table}
        WHERE event_id = %d
        GROUP BY reader_ticket_id
        ORDER BY cantidad DESC
        LIMIT 10
    ", $event_id);

    $top_readers_raw = $wpdb->get_results($top_readers_query);

    // Top 10 más leídos (quienes más han sido leídos)
    $top_read_query = $wpdb->prepare("
        SELECT 
            read_ticket_id as ticket_id,
            COUNT(DISTINCT reader_ticket_id) as cantidad
        FROM {$table}
        WHERE event_id = %d
        GROUP BY read_ticket_id
        ORDER BY cantidad DESC
        LIMIT 10
    ", $event_id);

    $top_read_raw = $wpdb->get_results($top_read_query);

    // Función helper para obtener nombre completo del ticket
    $get_nombre = function($ticket_id) {
        $nombre = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
        $apellido = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
        return trim($nombre . ' ' . $apellido) ?: 'Usuario #' . $ticket_id;
    };

    // Formatear datos de lectores
    $top_readers = [];
    foreach ($top_readers_raw as $row) {
        $top_readers[] = [
            'ticket_id' => (int)$row->ticket_id,
            'nombre' => $get_nombre($row->ticket_id),
            'cantidad' => (int)$row->cantidad
        ];
    }

    // Formatear datos de leídos
    $top_read = [];
    foreach ($top_read_raw as $row) {
        $top_read[] = [
            'ticket_id' => (int)$row->ticket_id,
            'nombre' => $get_nombre($row->ticket_id),
            'cantidad' => (int)$row->cantidad
        ];
    }

    wp_send_json_success([
        'top_readers' => $top_readers,
        'top_read' => $top_read,
        'timestamp' => current_time('mysql')
    ]);
}
