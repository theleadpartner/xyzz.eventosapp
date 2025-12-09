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

    ob_start();
    ?>
    <style>
    /* Variables */
    .evapp-ranking-wrapper {
        --evapp-blue: #2F73B5;
        --evapp-gold: #FFD700;
        --evapp-silver: #C0C0C0;
        --evapp-bronze: #CD7F32;
        --evapp-radius: 16px;
        font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
    }

    /* Header */
    .evapp-ranking-header {
        background: linear-gradient(135deg, var(--evapp-blue) 0%, #1F4B77 100%);
        color: #fff;
        padding: 30px;
        border-radius: var(--evapp-radius);
        margin-bottom: 30px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    }

    .evapp-ranking-header h1 {
        margin: 0 0 10px;
        font-size: 2rem;
        font-weight: 800;
        letter-spacing: 0.5px;
    }

    .evapp-ranking-header .evento-info {
        font-size: 1.1rem;
        opacity: 0.95;
        margin-bottom: 5px;
    }

    .evapp-ranking-header .fecha-info {
        font-size: 0.95rem;
        opacity: 0.85;
    }

    /* Controles */
    .evapp-ranking-controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        gap: 15px;
        flex-wrap: wrap;
    }

    .evapp-refresh-btn {
        display: flex;
        align-items: center;
        gap: 8px;
        background: var(--evapp-blue);
        color: #fff;
        border: none;
        padding: 12px 24px;
        border-radius: 10px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
        box-shadow: 0 2px 8px rgba(47,115,181,0.3);
    }

    .evapp-refresh-btn:hover {
        background: #275F95;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(47,115,181,0.4);
    }

    .evapp-refresh-btn:active {
        transform: translateY(0);
    }

    .evapp-refresh-btn svg {
        width: 18px;
        height: 18px;
        fill: currentColor;
    }

    .evapp-refresh-btn.loading svg {
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    .evapp-last-update {
        color: #6b7280;
        font-size: 0.9rem;
    }

    /* Grid de Rankings */
    .evapp-ranking-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
        gap: 30px;
        margin-bottom: 30px;
    }

    @media (max-width: 768px) {
        .evapp-ranking-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Tarjeta de Ranking */
    .evapp-ranking-card {
        background: #fff;
        border-radius: var(--evapp-radius);
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        overflow: hidden;
    }

    .evapp-ranking-card-header {
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        padding: 20px 25px;
        border-bottom: 2px solid #e5e7eb;
    }

    .evapp-ranking-card-header h2 {
        margin: 0;
        font-size: 1.4rem;
        font-weight: 800;
        color: #111827;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .evapp-ranking-card-header svg {
        width: 24px;
        height: 24px;
    }

    /* Items del Ranking */
    .evapp-ranking-list {
        padding: 0;
        margin: 0;
        list-style: none;
    }

    .evapp-ranking-item {
        display: flex;
        align-items: center;
        padding: 18px 25px;
        border-bottom: 1px solid #f3f4f6;
        transition: background-color 0.2s ease;
    }

    .evapp-ranking-item:hover {
        background: #f9fafb;
    }

    .evapp-ranking-item:last-child {
        border-bottom: none;
    }

    /* Posiciones con jerarquía */
    .evapp-ranking-position {
        flex-shrink: 0;
        font-weight: 800;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 20px;
    }

    /* Posición #1 - Más grande */
    .evapp-ranking-item.rank-1 {
        background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        border-left: 4px solid var(--evapp-gold);
    }

    .evapp-ranking-item.rank-1 .evapp-ranking-position {
        width: 60px;
        height: 60px;
        background: var(--evapp-gold);
        color: #78350f;
        font-size: 1.8rem;
        box-shadow: 0 4px 12px rgba(255,215,0,0.4);
    }

    .evapp-ranking-item.rank-1 .evapp-ranking-name {
        font-size: 1.4rem;
    }

    .evapp-ranking-item.rank-1 .evapp-ranking-count {
        font-size: 1.8rem;
    }

    /* Posiciones #2 al #5 - Grandes */
    .evapp-ranking-item.rank-2,
    .evapp-ranking-item.rank-3,
    .evapp-ranking-item.rank-4,
    .evapp-ranking-item.rank-5 {
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    }

    .evapp-ranking-item.rank-2 {
        border-left: 4px solid var(--evapp-silver);
    }

    .evapp-ranking-item.rank-3 {
        border-left: 4px solid var(--evapp-bronze);
    }

    .evapp-ranking-item.rank-2 .evapp-ranking-position,
    .evapp-ranking-item.rank-3 .evapp-ranking-position,
    .evapp-ranking-item.rank-4 .evapp-ranking-position,
    .evapp-ranking-item.rank-5 .evapp-ranking-position {
        width: 50px;
        height: 50px;
        font-size: 1.5rem;
    }

    .evapp-ranking-item.rank-2 .evapp-ranking-position {
        background: var(--evapp-silver);
        color: #374151;
        box-shadow: 0 3px 10px rgba(192,192,192,0.4);
    }

    .evapp-ranking-item.rank-3 .evapp-ranking-position {
        background: var(--evapp-bronze);
        color: #fff;
        box-shadow: 0 3px 10px rgba(205,127,50,0.4);
    }

    .evapp-ranking-item.rank-4 .evapp-ranking-position,
    .evapp-ranking-item.rank-5 .evapp-ranking-position {
        background: linear-gradient(135deg, var(--evapp-blue) 0%, #1F4B77 100%);
        color: #fff;
        box-shadow: 0 2px 8px rgba(47,115,181,0.3);
    }

    .evapp-ranking-item.rank-2 .evapp-ranking-name,
    .evapp-ranking-item.rank-3 .evapp-ranking-name,
    .evapp-ranking-item.rank-4 .evapp-ranking-name,
    .evapp-ranking-item.rank-5 .evapp-ranking-name {
        font-size: 1.2rem;
    }

    .evapp-ranking-item.rank-2 .evapp-ranking-count,
    .evapp-ranking-item.rank-3 .evapp-ranking-count,
    .evapp-ranking-item.rank-4 .evapp-ranking-count,
    .evapp-ranking-item.rank-5 .evapp-ranking-count {
        font-size: 1.5rem;
    }

    /* Posiciones #6 al #10 - Tamaño normal */
    .evapp-ranking-item.rank-6 .evapp-ranking-position,
    .evapp-ranking-item.rank-7 .evapp-ranking-position,
    .evapp-ranking-item.rank-8 .evapp-ranking-position,
    .evapp-ranking-item.rank-9 .evapp-ranking-position,
    .evapp-ranking-item.rank-10 .evapp-ranking-position {
        width: 40px;
        height: 40px;
        background: #6b7280;
        color: #fff;
        font-size: 1.2rem;
    }

    .evapp-ranking-item.rank-6 .evapp-ranking-name,
    .evapp-ranking-item.rank-7 .evapp-ranking-name,
    .evapp-ranking-item.rank-8 .evapp-ranking-name,
    .evapp-ranking-item.rank-9 .evapp-ranking-name,
    .evapp-ranking-item.rank-10 .evapp-ranking-name {
        font-size: 1rem;
    }

    .evapp-ranking-item.rank-6 .evapp-ranking-count,
    .evapp-ranking-item.rank-7 .evapp-ranking-count,
    .evapp-ranking-item.rank-8 .evapp-ranking-count,
    .evapp-ranking-item.rank-9 .evapp-ranking-count,
    .evapp-ranking-item.rank-10 .evapp-ranking-count {
        font-size: 1.2rem;
    }

    /* Info del usuario */
    .evapp-ranking-info {
        flex-grow: 1;
    }

    .evapp-ranking-name {
        font-weight: 700;
        color: #111827;
        margin-bottom: 2px;
    }

    /* Contador */
    .evapp-ranking-count {
        flex-shrink: 0;
        font-weight: 800;
        color: var(--evapp-blue);
        margin-left: 15px;
    }

    /* Estado vacío */
    .evapp-ranking-empty {
        padding: 40px 25px;
        text-align: center;
        color: #6b7280;
    }

    .evapp-ranking-empty svg {
        width: 48px;
        height: 48px;
        margin-bottom: 15px;
        opacity: 0.5;
    }

    /* Landing URL Section */
    .evapp-landing-section {
        background: #fff;
        border-radius: var(--evapp-radius);
        padding: 25px;
        margin-bottom: 30px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    }

    .evapp-landing-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: #374151;
        margin: 0 0 15px;
    }

    .evapp-landing-controls {
        display: flex;
        gap: 10px;
        align-items: stretch;
    }

    .evapp-landing-url-wrapper {
        flex: 1;
        position: relative;
    }

    .evapp-landing-url {
        width: 100%;
        padding: 14px 16px;
        border: 2px solid #e5e7eb;
        border-radius: 10px;
        font-size: 0.95rem;
        color: #374151;
        background: #f9fafb;
        transition: all 0.2s ease;
    }

    .evapp-landing-url:focus {
        outline: none;
        border-color: var(--evapp-blue);
        background: #fff;
    }

    .evapp-landing-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 14px 24px;
        border: none;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 0.2s ease;
        white-space: nowrap;
    }

    .evapp-landing-btn svg {
        width: 18px;
        height: 18px;
    }

    .evapp-landing-btn-copy {
        background: #3b82f6;
        color: #fff;
    }

    .evapp-landing-btn-copy:hover {
        background: #2563eb;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(37,99,235,0.3);
    }

    .evapp-landing-btn-copy.copied {
        background: #10b981;
    }

    .evapp-landing-btn-open {
        background: #6b7280;
        color: #fff;
    }

    .evapp-landing-btn-open:hover {
        background: #4b5563;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(75,85,99,0.3);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .evapp-ranking-header h1 {
            font-size: 1.5rem;
        }

        .evapp-ranking-controls {
            flex-direction: column;
            align-items: stretch;
        }

        .evapp-refresh-btn {
            width: 100%;
            justify-content: center;
        }

        .evapp-ranking-item.rank-1 .evapp-ranking-position {
            width: 50px;
            height: 50px;
            font-size: 1.5rem;
        }

        .evapp-ranking-item.rank-1 .evapp-ranking-name {
            font-size: 1.2rem;
        }

        .evapp-ranking-item.rank-1 .evapp-ranking-count {
            font-size: 1.5rem;
        }

        .evapp-landing-controls {
            flex-direction: column;
        }

        .evapp-landing-btn {
            width: 100%;
        }
    }
    </style>

    <div class="evapp-ranking-wrapper" data-event-id="<?php echo esc_attr($active_event); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('eventosapp_ranking_networking')); ?>">
        
        <div class="evapp-ranking-header">
            <h1>🏆 Ranking de Networking</h1>
            <div class="evento-info">
                <strong>Evento:</strong> <?php echo esc_html($evento_nombre); ?>
            </div>
            <div class="fecha-info">
                <?php echo esc_html($fecha_actual); ?>
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
        // Generar URL de la landing de networking
        $networking_landing_url = '';
        if (function_exists('eventosapp_get_qr_double_auth_url')) {
            $base_url = eventosapp_get_qr_double_auth_url();
            if ($base_url && $base_url !== '#') {
                // Si la URL base no tiene parámetros, agregamos el event ID
                $networking_landing_url = add_query_arg('event', $active_event, $base_url);
            }
        }
        
        // Alternativamente, buscar si hay una meta específica del evento
        if (empty($networking_landing_url) || $networking_landing_url === '#') {
            $networking_page_id = get_post_meta($active_event, '_eventosapp_networking_page_id', true);
            if ($networking_page_id) {
                $networking_landing_url = get_permalink($networking_page_id);
            }
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
                            <div class="evapp-ranking-name">${item.nombre}</div>
                        </div>
                        <div class="evapp-ranking-count">${item.cantidad}</div>
                    </li>
                `;
            });
            container.innerHTML = html;
        }

        function loadRanking() {
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
                refreshBtn.classList.remove('loading');
                refreshBtn.disabled = false;
            });
        }

        // Event listeners
        refreshBtn.addEventListener('click', loadRanking);

        // Auto-refresh cada 30 segundos
        setInterval(loadRanking, 30000);

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
add_action('wp_ajax_nopriv_eventosapp_get_ranking_networking', 'eventosapp_get_ranking_networking_ajax');

function eventosapp_get_ranking_networking_ajax() {
    check_ajax_referer('eventosapp_ranking_networking', 'security');

    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    
    if (!$event_id) {
        wp_send_json_error(['message' => 'ID de evento no válido']);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'eventosapp_networking';

    // Verificar que la tabla existe
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
    if (!$table_exists) {
        wp_send_json_error(['message' => 'Tabla de networking no encontrada']);
    }

    // Top 10 lectores (quienes más han leído)
    $top_readers_query = $wpdb->prepare("
        SELECT 
            reader_ticket_id as ticket_id,
            COUNT(*) as cantidad
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
            COUNT(*) as cantidad
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
