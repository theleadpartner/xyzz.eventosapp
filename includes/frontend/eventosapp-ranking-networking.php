<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Ranking de Networking - Top 10 usuarios más activos
 * Shortcode: [eventosapp_ranking_networking]
 */

if ( ! function_exists('eventosapp_ranking_networking_notice') ) {
    function eventosapp_ranking_networking_notice($title, $message, $button_url = '', $button_label = '') {
        $button = '';
        if ($button_url && $button_label) {
            $button = '<a href="' . esc_url($button_url) . '" style="min-height:44px;display:inline-flex;align-items:center;justify-content:center;margin-top:14px;padding:10px 15px;color:#182230;text-decoration:none;background:#fff;border:1px solid #dfe7f1;border-radius:12px;box-shadow:0 5px 15px rgba(31,65,99,.05);font-size:14px;font-weight:750;">' . esc_html($button_label) . '</a>';
        }
        return '<div style="width:100%;max-width:1180px;margin:0 auto;padding:clamp(18px,3vw,36px);color:#182230;background:#f5f8fc;border:1px solid #dfe7f1;border-radius:26px;box-shadow:0 18px 50px rgba(31,52,73,.08);font-family:inherit;line-height:1.45;box-sizing:border-box;">'
            . '<header style="margin-bottom:20px;"><p style="margin:0 0 6px;color:#3279bd;font-size:12px;font-weight:800;letter-spacing:.11em;text-transform:uppercase;">EVENTOSAPP</p><h1 style="margin:0;color:#182230;font-size:clamp(27px,4vw,42px);font-weight:850;line-height:1.08;letter-spacing:-.035em;">Ranking de Networking</h1></header>'
            . '<div style="padding:20px;background:#fff;border:1px solid #dfe7f1;border-radius:18px;box-shadow:0 8px 26px rgba(31,52,73,.05);"><strong style="display:block;margin-bottom:6px;color:#182230;font-size:17px;line-height:1.3;">' . esc_html($title) . '</strong><div style="color:#64748b;font-size:13px;line-height:1.6;">' . wp_kses_post($message) . '</div>' . $button . '</div></div>';
    }
}

add_shortcode('eventosapp_ranking_networking', function(){
    if ( ! is_user_logged_in() ) {
        $login = wp_login_url( get_permalink() );
        return eventosapp_ranking_networking_notice('Debes iniciar sesión', 'Inicia sesión para consultar el ranking del evento.', $login, 'Iniciar sesión');
    }

    // ✅ CORREGIDO: Cambié 'ranking_networking' por 'networking_ranking'
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('networking_ranking') ) {
        $dashboard = function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/');
        return eventosapp_ranking_networking_notice('Acceso restringido', 'No tienes permisos para ver este ranking.', $dashboard, 'Volver al dashboard');
    }

    $active_event = function_exists('eventosapp_get_active_event') ? eventosapp_get_active_event() : 0;
    if ( ! $active_event ) {
        $dashboard = function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/');
        return eventosapp_ranking_networking_notice('No hay un evento activo', 'Selecciona un evento desde el dashboard antes de consultar el ranking.', $dashboard, 'Volver al dashboard');
    }

    $evento_nombre = get_the_title($active_event);
    $fecha_actual = date_i18n('l, j \d\e F \d\e Y');
    $dashboard_url = function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/');

    ob_start();
    ?>
    <style id="eventosapp-ranking-networking-ui">
    .evapp-ranking-wrapper{
        --evapp-primary:#3279bd;--evapp-primary-dark:#255f96;--evapp-primary-soft:#eaf4ff;
        --evapp-app-bg:#f5f8fc;--evapp-surface:#ffffff;--evapp-border:#dfe7f1;--evapp-text:#182230;
        --evapp-muted:#64748b;--evapp-success:#15803d;--evapp-success-soft:#ecfdf3;--evapp-danger:#b42318;
        width:100%;max-width:1180px;margin:0 auto;padding:clamp(18px,3vw,36px);color:var(--evapp-text);background:var(--evapp-app-bg);
        border:1px solid var(--evapp-border);border-radius:26px;box-shadow:0 18px 50px rgba(31,52,73,.08);font-family:inherit;line-height:1.45;box-sizing:border-box;isolation:isolate;
    }
    .evapp-ranking-wrapper *,.evapp-ranking-wrapper *::before,.evapp-ranking-wrapper *::after{box-sizing:border-box}
    .evapp-ranking-wrapper button,.evapp-ranking-wrapper input{font:inherit}
    .evapp-ranking-wrapper a{text-decoration:none}
    .evapp-ranking-wrapper svg{display:block;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
    .evapp-ranking-head{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin-bottom:20px}
    .evapp-ranking-heading{min-width:0}.evapp-ranking-eyebrow{margin:0 0 6px;color:var(--evapp-primary);font-size:12px;font-weight:800;letter-spacing:.11em;text-transform:uppercase}
    .evapp-ranking-head h1{margin:0;color:var(--evapp-text);font-size:clamp(27px,4vw,42px);font-weight:850;line-height:1.08;letter-spacing:-.035em}
    .evapp-ranking-head p.evapp-ranking-subtitle{max-width:760px;margin:10px 0 0;color:var(--evapp-muted);font-size:15px;line-height:1.6}
    .evapp-ranking-head-actions{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:10px}
    .evapp-ranking-btn,.evapp-refresh-btn,.evapp-landing-btn{
        min-height:44px;display:inline-flex;align-items:center;justify-content:center;gap:8px;margin:0;padding:10px 15px;appearance:none;
        color:var(--evapp-text)!important;background:var(--evapp-surface);border:1px solid var(--evapp-border);border-radius:12px;box-shadow:0 5px 15px rgba(31,65,99,.05);
        font-size:14px;font-weight:750;line-height:1.15;text-align:center;cursor:pointer;transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease,background .16s ease,color .16s ease,opacity .16s ease;
    }
    .evapp-ranking-btn svg,.evapp-refresh-btn svg,.evapp-landing-btn svg{width:18px;height:18px;flex:0 0 18px}
    .evapp-ranking-btn:hover,.evapp-landing-btn:hover,.evapp-refresh-btn:hover:not(:disabled){transform:translateY(-1px);border-color:#c7d7e8;box-shadow:0 9px 20px rgba(31,65,99,.09)}
    .evapp-ranking-btn:focus-visible,.evapp-refresh-btn:focus-visible,.evapp-landing-btn:focus-visible,.evapp-landing-url:focus-visible{outline:3px solid rgba(50,121,189,.20);outline-offset:2px}
    .evapp-refresh-btn{color:#fff!important;background:var(--evapp-primary);border-color:var(--evapp-primary);box-shadow:0 9px 20px rgba(50,121,189,.18)}
    .evapp-refresh-btn:hover:not(:disabled){background:var(--evapp-primary-dark);border-color:var(--evapp-primary-dark);box-shadow:0 12px 24px rgba(50,121,189,.24)}
    .evapp-refresh-btn:disabled{opacity:.5;cursor:not-allowed;transform:none!important;box-shadow:none!important}.evapp-refresh-btn.loading svg{animation:evapp-rank-spin .9s linear infinite}@keyframes evapp-rank-spin{to{transform:rotate(360deg)}}
    .evapp-ranking-event-context{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px;padding:15px 16px;background:var(--evapp-surface);border:1px solid var(--evapp-border);border-radius:18px;box-shadow:0 8px 24px rgba(31,52,73,.045)}
    .evapp-ranking-event-main{min-width:0;display:flex;align-items:center;gap:13px}.evapp-ranking-event-icon{width:46px;height:46px;flex:0 0 46px;display:grid;place-items:center;color:var(--evapp-primary);background:var(--evapp-primary-soft);border-radius:14px}.evapp-ranking-event-icon svg{width:23px;height:23px}.evapp-ranking-event-copy{min-width:0}.evapp-ranking-event-kicker{display:block;margin-bottom:2px;color:var(--evapp-muted);font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.evapp-ranking-event-name{display:block;overflow:hidden;color:var(--evapp-text);font-size:15px;font-weight:820;line-height:1.3;text-overflow:ellipsis;white-space:nowrap}
    .evapp-ranking-event-meta{display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:8px}.evapp-ranking-chip{min-height:30px;display:inline-flex;align-items:center;gap:6px;padding:6px 10px;color:var(--evapp-muted);background:#fff;border:1px solid var(--evapp-border);border-radius:999px;font-size:12px;font-weight:730;white-space:nowrap}.evapp-ranking-chip strong{color:var(--evapp-text);font-weight:850}
    .evapp-ranking-panel{min-width:0;margin-bottom:16px;padding:20px;background:var(--evapp-surface);border:1px solid var(--evapp-border);border-radius:18px;box-shadow:0 8px 26px rgba(31,52,73,.05)}
    .evapp-ranking-panel-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.evapp-ranking-panel h2,.evapp-ranking-panel h3{margin:0 0 5px;color:var(--evapp-text);font-size:17px;font-weight:820;line-height:1.3}.evapp-ranking-panel-intro{margin:0;color:var(--evapp-muted);font-size:13px;line-height:1.5}
    .evapp-landing-controls{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:8px;align-items:stretch;margin-top:15px}.evapp-landing-url-wrapper{min-width:0}.evapp-landing-url{width:100%;min-height:46px;margin:0;padding:10px 12px;color:var(--evapp-text);background:#fbfdff;border:1px solid var(--evapp-border);border-radius:13px;box-shadow:none;outline:none;font-size:14px;transition:border-color .16s ease,box-shadow .16s ease}.evapp-landing-url:focus{border-color:var(--evapp-primary);box-shadow:0 0 0 4px rgba(50,121,189,.12)}
    .evapp-landing-btn-copy{color:#fff!important;background:var(--evapp-primary);border-color:var(--evapp-primary);box-shadow:0 9px 20px rgba(50,121,189,.18)}.evapp-landing-btn-copy:hover{background:var(--evapp-primary-dark);border-color:var(--evapp-primary-dark);box-shadow:0 12px 24px rgba(50,121,189,.24)}.evapp-landing-btn-copy.copied{background:var(--evapp-success);border-color:var(--evapp-success)}
    .evapp-ranking-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:0}.evapp-ranking-card{min-width:0;background:var(--evapp-surface);border:1px solid var(--evapp-border);border-radius:18px;box-shadow:0 8px 26px rgba(31,52,73,.05);overflow:hidden}.evapp-ranking-card-header{padding:17px 18px;background:#fbfdff;border-bottom:1px solid var(--evapp-border)}.evapp-ranking-card-header h2{display:flex;align-items:center;gap:9px;margin:0;color:var(--evapp-text);font-size:17px;font-weight:820;line-height:1.3}.evapp-ranking-card-header svg{width:21px;height:21px;color:var(--evapp-primary)}
    .evapp-ranking-list{padding:8px;margin:0;list-style:none}.evapp-ranking-item{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:12px;min-width:0;padding:12px 10px;border-bottom:1px solid #edf2f7;transition:background-color .15s ease}.evapp-ranking-item:last-child{border-bottom:0}.evapp-ranking-item:hover{background:#fbfdff}.evapp-ranking-position{display:flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:12px;background:#eef3f8;color:var(--evapp-muted);font-weight:850;font-size:.9rem}.evapp-ranking-info{min-width:0}.evapp-ranking-name{font-weight:780;color:var(--evapp-text);font-size:.92rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.evapp-ranking-count{display:inline-flex;align-items:center;justify-content:center;min-width:42px;height:32px;padding:0 9px;border-radius:10px;background:var(--evapp-primary-soft);color:var(--evapp-primary-dark);font-weight:850;font-size:.9rem}
    .evapp-ranking-item.rank-1{background:#fffaf0}.evapp-ranking-item.rank-1 .evapp-ranking-position{background:#f6e6a6;color:#6f5700}.evapp-ranking-item.rank-2 .evapp-ranking-position{background:#e8edf2;color:#566474}.evapp-ranking-item.rank-3 .evapp-ranking-position{background:#f1dfcd;color:#7a4c24}.evapp-ranking-empty{padding:42px 18px;text-align:center;color:var(--evapp-muted);font-size:13px}.evapp-ranking-empty svg{width:42px;height:42px;margin:0 auto 10px;opacity:.45}.evapp-ranking-empty p{margin:0}
    @media(max-width:900px){.evapp-ranking-grid{grid-template-columns:1fr}}
    @media(max-width:767px){.evapp-ranking-wrapper{padding:16px;border-radius:20px}.evapp-ranking-head{display:block;margin-bottom:18px}.evapp-ranking-head-actions{justify-content:flex-start;margin-top:14px}.evapp-ranking-head-actions .evapp-ranking-btn{width:100%}.evapp-ranking-event-context{align-items:flex-start;flex-direction:column;padding:14px}.evapp-ranking-event-meta{width:100%;justify-content:flex-start}.evapp-ranking-event-name{white-space:normal;overflow:visible}.evapp-ranking-panel-head{align-items:stretch}.evapp-ranking-panel-head .evapp-refresh-btn{width:100%}.evapp-landing-controls{grid-template-columns:1fr}.evapp-landing-btn{width:100%}}
    @media(max-width:520px){.evapp-ranking-panel{padding:16px}.evapp-ranking-card-header{padding:15px}.evapp-ranking-name{white-space:normal;overflow:visible}}
    @media(prefers-reduced-motion:reduce){.evapp-ranking-wrapper *,.evapp-ranking-wrapper *::before,.evapp-ranking-wrapper *::after{scroll-behavior:auto!important;animation:none!important;transition:none!important}}
    </style>

    <div class="evapp-ranking-wrapper" data-event-id="<?php echo esc_attr($active_event); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('eventosapp_ranking_networking')); ?>">
        <header class="evapp-ranking-head">
            <div class="evapp-ranking-heading">
                <p class="evapp-ranking-eyebrow">EVENTOSAPP</p>
                <h1>Ranking de Networking</h1>
                <p class="evapp-ranking-subtitle">Consulta la participación del networking y visualiza las conexiones únicas acumuladas durante el evento.</p>
            </div>
            <div class="evapp-ranking-head-actions">
                <a class="evapp-ranking-btn" href="<?php echo esc_url($dashboard_url); ?>" aria-label="Volver al dashboard">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg>
                    <span>Volver al dashboard</span>
                </a>
            </div>
        </header>

        <section class="evapp-ranking-event-context" aria-label="Evento activo">
            <div class="evapp-ranking-event-main">
                <span class="evapp-ranking-event-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M4 5h16v2a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5V5Z"></path><path d="M9 12v2a3 3 0 0 0 6 0v-2M8 21h8"></path></svg>
                </span>
                <div class="evapp-ranking-event-copy">
                    <span class="evapp-ranking-event-kicker">Evento activo</span>
                    <strong class="evapp-ranking-event-name"><?php echo esc_html($evento_nombre); ?></strong>
                </div>
            </div>
            <div class="evapp-ranking-event-meta">
                <span class="evapp-ranking-chip"><?php echo esc_html($fecha_actual); ?></span>
                <span class="evapp-ranking-chip">Actualizado <strong id="evapp-last-update-time">—</strong></span>
            </div>
        </section>

        <section class="evapp-ranking-panel">
            <div class="evapp-ranking-panel-head">
                <div>
                    <h2>Actividad del networking</h2>
                    <p class="evapp-ranking-panel-intro">Los rankings consideran conexiones únicas del evento para evitar duplicar múltiples lecturas del mismo contacto.</p>
                </div>
                <button class="evapp-refresh-btn" id="evapp-refresh-ranking" type="button">
                    <svg viewBox="0 0 24 24"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"></path></svg>
                    Actualizar ranking
                </button>
            </div>
        </section>

        <?php
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
        <section class="evapp-ranking-panel">
            <h2>Landing del networking</h2>
            <p class="evapp-ranking-panel-intro">Comparte esta dirección con los asistentes o ábrela para validar la experiencia pública del evento.</p>
            <div class="evapp-landing-controls">
                <div class="evapp-landing-url-wrapper">
                    <input type="text" class="evapp-landing-url" id="evapp-networking-url" value="<?php echo esc_attr($networking_landing_url); ?>" readonly aria-label="URL de la landing de networking">
                </div>
                <button class="evapp-landing-btn evapp-landing-btn-copy" id="evapp-copy-url" type="button">
                    <svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                    <span id="evapp-copy-text">Copiar URL</span>
                </button>
                <button class="evapp-landing-btn" id="evapp-open-url" type="button">
                    <svg viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                    Abrir landing
                </button>
            </div>
        </section>
        <?php endif; ?>

        <div class="evapp-ranking-grid">
            <section class="evapp-ranking-card">
                <div class="evapp-ranking-card-header">
                    <h2><svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path></svg>Top Lectores de Contactos</h2>
                </div>
                <ul class="evapp-ranking-list" id="evapp-ranking-readers">
                    <li class="evapp-ranking-empty"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg><p>Cargando datos del ranking...</p></li>
                </ul>
            </section>
            <section class="evapp-ranking-card">
                <div class="evapp-ranking-card-header">
                    <h2><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"></path></svg>Top Contactos Más Leídos</h2>
                </div>
                <ul class="evapp-ranking-list" id="evapp-ranking-read">
                    <li class="evapp-ranking-empty"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg><p>Cargando datos del ranking...</p></li>
                </ul>
            </section>
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
                                copyText.textContent = 'Copiar URL';
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
                            copyText.textContent = 'Copiar URL';
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
