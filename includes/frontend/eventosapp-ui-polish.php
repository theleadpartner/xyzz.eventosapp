<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Hotfix visual posterior a la capa corporativa principal.
 *
 * Objetivos:
 * - corregir estados Dark Mode que todavía heredaban fondos claros;
 * - reforzar la presencia del icono corporativo en el Dashboard;
 * - aislar definitivamente el icono del buscador respecto del texto;
 * - convertir el header autenticado en un drawer lateral en móvil.
 *
 * Esta capa no modifica permisos, sesión, autenticación, eventos activos,
 * shortcodes ni lógica de negocio. Solo actúa sobre páginas ya gestionadas
 * por EventosApp y se carga después de eventosapp-branding.php.
 */

if ( ! function_exists( 'eventosapp_ui_polish_is_active' ) ) {
    function eventosapp_ui_polish_is_active() {
        return function_exists( 'eventosapp_branding_is_managed_page' )
            && eventosapp_branding_is_managed_page();
    }
}

if ( ! function_exists( 'eventosapp_ui_polish_print_styles' ) ) {
    function eventosapp_ui_polish_print_styles() {
        if ( ! eventosapp_ui_polish_is_active() ) return;

        $icon_color = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'icon_color' )
            : '';
        $icon_white = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'icon_white' )
            : '';
        ?>
        <style id="eventosapp-ui-polish">
/* ==========================================================
 * EventosApp 1.5.0-rc.7 — compatibilidad visual final
 * ========================================================== */

/* El Dashboard real utiliza .evapp-dashboard-eyebrow. La capa corporativa
 * anterior cubría .evapp-eyebrow, por lo que aquí se corrige el selector. */
.eventosapp-branding .evapp-dashboard-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 7px;
}
.eventosapp-branding .evapp-dashboard-eyebrow::before {
    content: "";
    display: inline-block;
    width: 16px;
    height: 18px;
    flex: 0 0 16px;
    background: url("<?php echo esc_url( $icon_color ); ?>") center / contain no-repeat;
}
html[data-evapp-theme="dark"] .eventosapp-branding .evapp-dashboard-eyebrow::before {
    background-image: url("<?php echo esc_url( $icon_white ); ?>");
}

/* El icono del usuario usa la variante apropiada para cada superficie. */
html[data-evapp-theme="dark"] .evapp-app-user-icon {
    background-color: rgba(54, 131, 197, .20) !important;
    background-image: url("<?php echo esc_url( $icon_white ); ?>") !important;
    border: 1px solid rgba(123, 177, 223, .22);
}
html[data-evapp-theme="dark"] .eventosapp-branding .evapp-session-panel-avatar {
    background-color: rgba(54, 131, 197, .16) !important;
    background-image: url("<?php echo esc_url( $icon_white ); ?>") !important;
    border-color: rgba(123, 177, 223, .20) !important;
}

/* Los aliases "soft" eran todavía claros y producían cuadros blancos en
 * flechas, avatares, chips y controles secundarios al activar Dark Mode. */
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-dashboard,
    .evapp-login-stage,
    .evreg-wrap,
    .evfe-app,
    .evfs-app,
    .evapp-qr-checkin-app,
    .evapp-double-auth-app,
    .evapp-metrics,
    .evapp-flow-metrics-app,
    .evapp-company-monitor,
    .evapp-support-app,
    .evchk-app,
    .evchk-admin,
    .evapp-raffle-admin,
    .evapp-expositor-module,
    .evapp-cons-editor,
    .evapp-cscan,
    .evapp-cons-front-shell,
    .evapp-tx-shell,
    .evapp-netglobal-shell,
    .evapp-ranking-wrapper,
    .evapp-404
) {
    --evapp-primary-soft: rgba(54, 131, 197, .16);
    --evapp-primary-softer: rgba(54, 131, 197, .09);
    --evapp-blue-soft: rgba(54, 131, 197, .16);
    --evapp-hover: #202c49;
    --ev-primary-soft: rgba(54, 131, 197, .16);
    --ev-soft: rgba(54, 131, 197, .12);
    --esp-primary-soft: rgba(54, 131, 197, .16);
    --esp-soft: rgba(54, 131, 197, .12);
    --evchk-primary-soft: rgba(54, 131, 197, .16);
    --evchk-soft: rgba(54, 131, 197, .12);
    --evc-soft: rgba(54, 131, 197, .16);
}

/* Dashboard: elimina fondos blancos hardcodeados en hover y conserva contraste. */
html[data-evapp-theme="dark"] body.eventosapp-app-page .evapp-dashboard .evapp-dashboard-shell {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page .evapp-dashboard :where(
    .evapp-event-context,
    .evapp-card,
    .evapp-selector-card,
    .evapp-notice,
    .evapp-no-search-results,
    .evapp-empty-state
) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page .evapp-dashboard .evapp-card:hover {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: rgba(106, 167, 218, .58) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: 0 15px 34px rgba(0, 0, 0, .24) !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page .evapp-dashboard :where(
    .evapp-title,
    .evapp-section-title,
    .evapp-event-name,
    .evapp-selector-title,
    .evapp-dashboard-main-title
) {
    color: var(--eventosapp-app-text) !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page .evapp-dashboard :where(
    .evapp-card-description,
    .evapp-dashboard-subtitle,
    .evapp-event-label,
    .evapp-section-count,
    .evapp-search-result-count,
    .evapp-selector-help,
    .evapp-selector-footnote
) {
    color: var(--eventosapp-app-muted) !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page .evapp-dashboard :where(
    .evapp-card-arrow,
    .evapp-event-context-icon,
    .evapp-selector-icon,
    .evapp-module-total
) {
    background: rgba(54, 131, 197, .16) !important;
    border-color: rgba(106, 167, 218, .25) !important;
    color: #7bb1df !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page .evapp-dashboard .evapp-card:hover .evapp-card-arrow {
    background: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page .evapp-dashboard .evapp-module-search-clear:hover {
    background: rgba(54, 131, 197, .16) !important;
    color: var(--eventosapp-app-text) !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page .evapp-dashboard .evapp-event-select {
    background-color: var(--eventosapp-app-input) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page .evapp-dashboard .evapp-event-select option {
    background: var(--eventosapp-app-input);
    color: var(--eventosapp-app-text);
}

/* Cards interactivos de otros módulos: el hover nunca debe volver a blanco. */
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    a[class*="-card"],
    button[class*="-card"],
    [role="button"][class*="-card"]
):hover {
    background-color: var(--eventosapp-app-surface-raised) !important;
    border-color: rgba(106, 167, 218, .50) !important;
    color: var(--eventosapp-app-text) !important;
}

/* Header: estados hover coherentes en Dark Mode. */
html[data-evapp-theme="dark"] .evapp-app-action:not(.is-logout):hover,
html[data-evapp-theme="dark"] .evapp-app-action:not(.is-logout):focus-visible {
    background: rgba(54, 131, 197, .24);
    border-color: rgba(123, 177, 223, .40);
}
html[data-evapp-theme="dark"] .evapp-app-action.is-admin:hover,
html[data-evapp-theme="dark"] .evapp-app-action.is-admin:focus-visible {
    background: var(--eventosapp-brand-blue);
    border-color: var(--eventosapp-brand-blue);
}

/* Buscador: reserva física obligatoria para la lupa. Esto prevalece sobre
 * estilos globales de Elementor/tema que reescriban el padding del input. */
body.eventosapp-app-page .evapp-dashboard .evapp-module-search-wrap {
    position: relative;
    isolation: isolate;
}
body.eventosapp-app-page .evapp-dashboard .evapp-module-search-icon {
    left: 17px !important;
    z-index: 5 !important;
    width: 19px !important;
    height: 19px !important;
}
body.eventosapp-app-page .evapp-dashboard input.evapp-module-search {
    padding-left: 52px !important;
    padding-right: 48px !important;
    text-indent: 0 !important;
    background-image: none !important;
}
body.eventosapp-app-page .evapp-dashboard input.evapp-module-search::-webkit-search-decoration,
body.eventosapp-app-page .evapp-dashboard input.evapp-module-search::-webkit-search-cancel-button,
body.eventosapp-app-page .evapp-dashboard input.evapp-module-search::-webkit-search-results-button,
body.eventosapp-app-page .evapp-dashboard input.evapp-module-search::-webkit-search-results-decoration {
    display: none !important;
    -webkit-appearance: none !important;
}

/* ==========================================================
 * Header responsive: drawer lateral autenticado
 * ========================================================== */
.evapp-app-menu-toggle,
.evapp-app-menu-close,
.evapp-app-menu-backdrop {
    display: none;
}

@media (max-width: 760px) {
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-chrome-inner {
        width: calc(100% - 24px);
        min-height: 64px;
        padding: 10px 0;
        flex-wrap: nowrap;
        gap: 12px;
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-brand {
        flex: 1 1 auto;
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-brand img {
        width: min(158px, 48vw);
        height: 40px;
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-toggle {
        appearance: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        flex: 0 0 auto;
        min-height: 42px;
        padding: 9px 12px;
        border: 1px solid rgba(255, 255, 255, .28);
        border-radius: 11px;
        background: rgba(255, 255, 255, .12);
        color: #fff;
        font: inherit;
        font-size: 12px;
        font-weight: 800;
        cursor: pointer;
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-toggle:hover,
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-toggle:focus-visible {
        background: rgba(255, 255, 255, .20);
        border-color: rgba(255, 255, 255, .42);
        outline: none;
    }
    .evapp-app-menu-toggle svg,
    .evapp-app-menu-close svg {
        width: 18px;
        height: 18px;
        fill: none;
        stroke: currentColor;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-chrome-actions {
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        z-index: 1003;
        width: min(350px, calc(100vw - 44px));
        max-width: 92vw;
        height: 100dvh;
        padding: 18px;
        display: flex;
        flex-direction: column;
        align-items: stretch;
        justify-content: flex-start;
        flex-wrap: nowrap;
        gap: 10px;
        overflow-y: auto;
        overscroll-behavior: contain;
        background: var(--eventosapp-brand-dark);
        border-left: 1px solid rgba(123, 177, 223, .22);
        box-shadow: -18px 0 48px rgba(0, 0, 0, .30);
        transform: translateX(104%);
        visibility: hidden;
        pointer-events: none;
        transition: transform .24s ease, visibility .24s ease;
    }
    .evapp-app-chrome.has-evapp-mobile-menu.is-menu-open .evapp-app-chrome-actions {
        transform: translateX(0);
        visibility: visible;
        pointer-events: auto;
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-close {
        appearance: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        align-self: flex-end;
        width: 42px;
        height: 42px;
        margin: -2px -2px 4px 0;
        padding: 0;
        border: 1px solid rgba(255, 255, 255, .18);
        border-radius: 11px;
        background: rgba(255, 255, 255, .08);
        color: #fff;
        cursor: pointer;
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-close:hover,
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-close:focus-visible {
        background: rgba(255, 255, 255, .16);
        outline: none;
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-user {
        width: 100%;
        max-width: none;
        flex: 0 0 auto;
        padding: 11px 12px;
        border-radius: 14px;
        background: rgba(255, 255, 255, .09);
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-user-icon {
        width: 38px;
        height: 38px;
        flex-basis: 38px;
        background-size: 24px auto;
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-user-label {
        font-size: 9px;
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-user-copy strong {
        font-size: 14px;
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-user-copy small {
        font-size: 10px;
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-action {
        width: 100%;
        min-height: 48px;
        padding: 11px 13px;
        justify-content: flex-start;
        border-radius: 12px;
        background: rgba(255, 255, 255, .08);
        border-color: rgba(255, 255, 255, .16);
        color: #fff !important;
        font-size: 12px;
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-action:hover,
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-action:focus-visible {
        background: rgba(54, 131, 197, .28);
        border-color: rgba(123, 177, 223, .40);
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-action.is-admin {
        background: var(--eventosapp-brand-blue-dark);
        border-color: rgba(123, 177, 223, .26);
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-action.is-admin:hover,
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-action.is-admin:focus-visible {
        background: var(--eventosapp-brand-blue);
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-action.is-logout {
        margin-top: auto;
        background: #f8fafc;
        border-color: #f8fafc;
        color: #9f1239 !important;
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-action.is-logout:hover,
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-action.is-logout:focus-visible {
        background: #fff;
        color: #881337 !important;
    }

    .evapp-app-menu-backdrop {
        position: fixed;
        inset: 0;
        z-index: 1002;
        display: block;
        background: rgba(5, 10, 22, .58);
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: opacity .20s ease, visibility .20s ease;
    }
    body.evapp-app-menu-open .evapp-app-menu-backdrop {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
    }
    body.evapp-app-menu-open {
        overflow: hidden;
    }
}

@media (max-width: 380px) {
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-toggle span {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-toggle {
        width: 42px;
        padding-inline: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-chrome-actions,
    .evapp-app-menu-backdrop {
        transition-duration: .01ms !important;
    }
}
        </style>
        <?php
    }
}
add_action( 'wp_footer', 'eventosapp_ui_polish_print_styles', 1000 );

if ( ! function_exists( 'eventosapp_ui_polish_print_script' ) ) {
    function eventosapp_ui_polish_print_script() {
        if ( ! eventosapp_ui_polish_is_active() ) return;
        ?>
        <script id="eventosapp-ui-polish-controller">
        (function(){
            var chrome = document.querySelector('[data-evapp-app-chrome]');
            if (!chrome || chrome.dataset.evappMobileMenuReady === '1') return;

            var inner = chrome.querySelector('.evapp-app-chrome-inner');
            var actions = chrome.querySelector('.evapp-app-chrome-actions');
            if (!inner || !actions) return;

            /* Para vistas públicas con solo el selector de tema se conserva el
             * header simple; el drawer se activa cuando existen controles de cuenta. */
            var hasAccountControls = !!actions.querySelector('.evapp-app-user, .is-admin, .is-logout, a.evapp-app-action');
            if (!hasAccountControls) return;

            chrome.dataset.evappMobileMenuReady = '1';
            chrome.classList.add('has-evapp-mobile-menu');

            if (!actions.id) actions.id = 'evapp-app-mobile-actions';

            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'evapp-app-menu-toggle';
            toggle.setAttribute('aria-controls', actions.id);
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', 'Abrir menú de EventosApp');
            toggle.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg><span>Menú</span>';
            inner.insertBefore(toggle, actions);

            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'evapp-app-menu-close';
            close.setAttribute('aria-label', 'Cerrar menú');
            close.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';
            actions.insertBefore(close, actions.firstChild);

            var backdrop = document.createElement('div');
            backdrop.className = 'evapp-app-menu-backdrop';
            backdrop.setAttribute('aria-hidden', 'true');
            document.body.appendChild(backdrop);

            function isMobile(){
                return window.matchMedia ? window.matchMedia('(max-width: 760px)').matches : window.innerWidth <= 760;
            }

            function setOpen(open, restoreFocus){
                if (!isMobile()) open = false;
                chrome.classList.toggle('is-menu-open', open);
                document.body.classList.toggle('evapp-app-menu-open', open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                toggle.setAttribute('aria-label', open ? 'Cerrar menú de EventosApp' : 'Abrir menú de EventosApp');

                if (open) {
                    window.setTimeout(function(){ close.focus(); }, 0);
                } else if (restoreFocus) {
                    window.setTimeout(function(){ toggle.focus(); }, 0);
                }
            }

            toggle.addEventListener('click', function(){
                setOpen(!chrome.classList.contains('is-menu-open'), false);
            });
            close.addEventListener('click', function(){ setOpen(false, true); });
            backdrop.addEventListener('click', function(){ setOpen(false, true); });

            actions.addEventListener('click', function(event){
                var link = event.target.closest ? event.target.closest('a.evapp-app-action') : null;
                if (link) setOpen(false, false);
            });

            document.addEventListener('keydown', function(event){
                if (event.key === 'Escape' && chrome.classList.contains('is-menu-open')) {
                    setOpen(false, true);
                }
            });

            window.addEventListener('resize', function(){
                if (!isMobile() && chrome.classList.contains('is-menu-open')) {
                    setOpen(false, false);
                }
            });
        })();
        </script>
        <?php
    }
}
add_action( 'wp_footer', 'eventosapp_ui_polish_print_script', 1001 );
