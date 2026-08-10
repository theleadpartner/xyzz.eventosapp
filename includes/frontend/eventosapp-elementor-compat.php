<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Compatibilidad visual reforzada para páginas tipo aplicación de EventosApp.
 *
 * Esta capa se carga después del branding y del pulido visual para impedir que
 * reglas globales del tema/Elementor vuelvan a introducir colores, fondos o
 * estados hover ajenos a la identidad corporativa. También corrige el orden de
 * apilado del drawer móvil para que el backdrop nunca capture clics sobre el menú.
 *
 * No modifica contenido, permisos, navegación, autenticación ni lógica funcional.
 */

if ( ! function_exists( 'eventosapp_elementor_compat_is_active' ) ) {
    function eventosapp_elementor_compat_is_active() {
        return function_exists( 'eventosapp_branding_is_managed_page' )
            && eventosapp_branding_is_managed_page();
    }
}

if ( ! function_exists( 'eventosapp_elementor_compat_print_styles' ) ) {
    function eventosapp_elementor_compat_print_styles() {
        if ( ! eventosapp_elementor_compat_is_active() ) return;
        ?>
        <style id="eventosapp-elementor-compat">
/* ==========================================================
 * EventosApp 1.5.0-rc.8 — aislamiento de Elementor + drawer
 * ========================================================== */

/* Elementor Kit puede inyectar sus variables globales dentro de la página.
 * En las rutas de EventosApp las neutralizamos a la paleta corporativa, sin
 * afectar ninguna página WordPress que esté fuera del mapa de la aplicación. */
body.eventosapp-app-page #eventosapp-app-root,
body.eventosapp-app-page #eventosapp-app-root .elementor,
body.eventosapp-app-page #eventosapp-app-root .elementor-element {
    --e-global-color-primary: var(--eventosapp-brand-blue) !important;
    --e-global-color-secondary: var(--eventosapp-brand-blue-dark) !important;
    --e-global-color-text: var(--eventosapp-app-text) !important;
    --e-global-color-accent: var(--eventosapp-brand-blue) !important;
}

/* Refuerzo de tokens corporativos. Elementor genera selectores por instancia
 * con mayor especificidad; los tokens importantes garantizan que sus valores
 * predeterminados no reemplacen la identidad de EventosApp. */
body.eventosapp-app-page #eventosapp-app-root :where(
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
    --evapp-primary: var(--eventosapp-brand-blue) !important;
    --evapp-primary-dark: var(--eventosapp-brand-blue-dark) !important;
    --evapp-primary-hover: var(--eventosapp-brand-blue-dark) !important;
    --evapp-accent: var(--eventosapp-brand-blue) !important;
    --evapp-blue: var(--eventosapp-brand-blue) !important;
    --evapp-blue-dark: var(--eventosapp-brand-blue-dark) !important;
    --evapp-bg: var(--eventosapp-app-canvas) !important;
    --evapp-background: var(--eventosapp-app-canvas) !important;
    --evapp-surface: var(--eventosapp-app-surface) !important;
    --evapp-card: var(--eventosapp-app-surface) !important;
    --evapp-card-bg: var(--eventosapp-app-surface) !important;
    --evapp-border: var(--eventosapp-app-border) !important;
    --evapp-border-color: var(--eventosapp-app-border) !important;
    --evapp-text: var(--eventosapp-app-text) !important;
    --evapp-ink: var(--eventosapp-app-text) !important;
    --evapp-heading: var(--eventosapp-app-text) !important;
    --evapp-muted: var(--eventosapp-app-muted) !important;

    --ev-primary: var(--eventosapp-brand-blue) !important;
    --ev-dark: var(--eventosapp-brand-blue-dark) !important;
    --ev-bg: var(--eventosapp-app-canvas) !important;
    --ev-surface: var(--eventosapp-app-surface) !important;
    --ev-card: var(--eventosapp-app-surface) !important;
    --ev-border: var(--eventosapp-app-border) !important;
    --ev-text: var(--eventosapp-app-text) !important;
    --ev-muted: var(--eventosapp-app-muted) !important;

    --esp-primary: var(--eventosapp-brand-blue) !important;
    --esp-primary-dark: var(--eventosapp-brand-blue-dark) !important;
    --esp-bg: var(--eventosapp-app-canvas) !important;
    --esp-card: var(--eventosapp-app-surface) !important;
    --esp-border: var(--eventosapp-app-border) !important;
    --esp-text: var(--eventosapp-app-text) !important;
    --esp-muted: var(--eventosapp-app-muted) !important;

    --evchk-primary: var(--eventosapp-brand-blue) !important;
    --evchk-primary-dark: var(--eventosapp-brand-blue-dark) !important;
    --evchk-bg: var(--eventosapp-app-canvas) !important;
    --evchk-card: var(--eventosapp-app-surface) !important;
    --evchk-border: var(--eventosapp-app-border) !important;
    --evchk-text: var(--eventosapp-app-text) !important;
    --evchk-muted: var(--eventosapp-app-muted) !important;

    --evc-primary: var(--eventosapp-brand-blue) !important;
    --evc-primary-dark: var(--eventosapp-brand-blue-dark) !important;
    --evc-dark: var(--eventosapp-brand-blue-dark) !important;
    --evc-bg: var(--eventosapp-app-canvas) !important;
    --evc-card: var(--eventosapp-app-surface) !important;
    --evc-border: var(--eventosapp-app-border) !important;
    --evc-text: var(--eventosapp-app-text) !important;
    --evc-muted: var(--eventosapp-app-muted) !important;

    --bg: var(--eventosapp-app-canvas) !important;
    --surface: var(--eventosapp-app-surface) !important;
    --card: var(--eventosapp-app-surface) !important;
    --border: var(--eventosapp-app-border) !important;
    --text: var(--eventosapp-app-text) !important;
    --muted: var(--eventosapp-app-muted) !important;

    --p: var(--eventosapp-brand-blue) !important;
    --pd: var(--eventosapp-brand-blue-dark) !important;
    --t: var(--eventosapp-app-text) !important;
    --ev: var(--eventosapp-brand-blue) !important;
    --ev2: var(--eventosapp-brand-blue-dark) !important;
    --ink: var(--eventosapp-app-text) !important;
}

/* Dashboard: estos son los tokens que el widget Elementor también escribe por
 * instancia. Se fijan aquí a la marca para que no reaparezcan los defaults
 * históricos #3279bd / #255f96 / #182230 / #64748b. */
body.eventosapp-app-page #eventosapp-app-root .evapp-dashboard {
    --evapp-primary: var(--eventosapp-brand-blue) !important;
    --evapp-primary-dark: var(--eventosapp-brand-blue-dark) !important;
    --evapp-primary-soft: var(--eventosapp-brand-blue-soft) !important;
    --evapp-app-bg: var(--eventosapp-app-surface-soft) !important;
    --evapp-surface: var(--eventosapp-app-surface) !important;
    --evapp-border: var(--eventosapp-app-border) !important;
    --evapp-text: var(--eventosapp-app-text) !important;
    --evapp-muted: var(--eventosapp-app-muted) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-dashboard {
    --evapp-primary-soft: rgba(54, 131, 197, .16) !important;
    --evapp-app-bg: var(--eventosapp-app-surface-soft) !important;
    --evapp-surface: var(--eventosapp-app-surface) !important;
    --evapp-border: var(--eventosapp-app-border) !important;
    --evapp-text: var(--eventosapp-app-text) !important;
    --evapp-muted: var(--eventosapp-app-muted) !important;
}

/* Componentes visuales principales del Dashboard. Los !important se limitan a
 * propiedades de marca para no interferir con grid, responsive ni funciones. */
body.eventosapp-app-page #eventosapp-app-root .evapp-dashboard :where(
    .evapp-dashboard-shell,
    .evapp-event-context,
    .evapp-card,
    .evapp-selector-card,
    .evapp-notice,
    .evapp-no-search-results,
    .evapp-empty-state
) {
    border-color: var(--evapp-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-dashboard :where(
    .evapp-event-context,
    .evapp-card,
    .evapp-selector-card,
    .evapp-notice,
    .evapp-no-search-results,
    .evapp-empty-state
) {
    background-color: var(--evapp-surface) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-dashboard :where(
    .evapp-dashboard-main-title,
    .evapp-section-title,
    .evapp-event-name,
    .evapp-title,
    .evapp-selector-title
) {
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-dashboard :where(
    .evapp-dashboard-subtitle,
    .evapp-card-description,
    .evapp-event-label,
    .evapp-section-count,
    .evapp-search-result-count,
    .evapp-selector-help,
    .evapp-selector-footnote
) {
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-dashboard :where(
    input.evapp-module-search,
    select.evapp-event-select
) {
    background-color: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
    box-shadow: none;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-dashboard input.evapp-module-search::placeholder {
    color: var(--evapp-muted) !important;
    opacity: 1 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-dashboard :where(
    .evapp-change-event,
    .evapp-primary-button
) {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
    text-decoration: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-dashboard :where(
    .evapp-change-event,
    .evapp-primary-button
):hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-dashboard :where(
    .evapp-change-event,
    .evapp-primary-button
):focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

/* El header está fuera del árbol de Elementor, pero las reglas globales del Kit
 * y del tema todavía pueden alcanzar button/a. Se fijan los estados para evitar
 * acentos rosados o colores genéricos sobre Modo, Administración y Logout. */
body.eventosapp-app-page .evapp-app-chrome .evapp-app-action:not(.is-admin):not(.is-logout) {
    -webkit-appearance: none !important;
    appearance: none !important;
    background: rgba(255, 255, 255, .11) !important;
    border-color: rgba(255, 255, 255, .24) !important;
    color: #fff !important;
    box-shadow: none !important;
    text-decoration: none !important;
}

body.eventosapp-app-page .evapp-app-chrome .evapp-app-action:not(.is-admin):not(.is-logout):hover,
body.eventosapp-app-page .evapp-app-chrome .evapp-app-action:not(.is-admin):not(.is-logout):focus-visible {
    background: rgba(255, 255, 255, .20) !important;
    border-color: rgba(255, 255, 255, .38) !important;
    color: #fff !important;
}

body.eventosapp-app-page .evapp-app-chrome .evapp-app-action.is-admin {
    background: var(--eventosapp-brand-dark) !important;
    border-color: rgba(255, 255, 255, .18) !important;
    color: #fff !important;
    box-shadow: none !important;
}

body.eventosapp-app-page .evapp-app-chrome .evapp-app-action.is-admin:hover,
body.eventosapp-app-page .evapp-app-chrome .evapp-app-action.is-admin:focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page .evapp-app-chrome .evapp-app-action.is-admin {
    background: var(--eventosapp-brand-blue-dark) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page .evapp-app-chrome .evapp-app-action.is-admin:hover,
html[data-evapp-theme="dark"] body.eventosapp-app-page .evapp-app-chrome .evapp-app-action.is-admin:focus-visible {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}

body.eventosapp-app-page .evapp-app-chrome .evapp-app-action.is-logout {
    background: #f8fafc !important;
    border-color: #f8fafc !important;
    color: #9f1239 !important;
    box-shadow: none !important;
}

body.eventosapp-app-page .evapp-app-chrome .evapp-app-action.is-logout:hover,
body.eventosapp-app-page .evapp-app-chrome .evapp-app-action.is-logout:focus-visible {
    background: #fff !important;
    border-color: #fff !important;
    color: #881337 !important;
}

/* ==========================================================
 * Drawer móvil: corrige el stacking context
 * ========================================================== */
@media (max-width: 760px) {
    /* .evapp-app-chrome ya crea un stacking context por su z-index. El backdrop
     * se inserta como hermano directo en body; por eso un z-index alto en el
     * drawer hijo no podía superar al backdrop. Elevamos el contexto completo
     * del header cuando el menú está abierto y dejamos el backdrop justo debajo. */
    body.eventosapp-app-page.evapp-app-menu-open .evapp-app-chrome.has-evapp-mobile-menu.is-menu-open {
        z-index: 999999 !important;
    }

    body.eventosapp-app-page.evapp-app-menu-open .evapp-app-menu-backdrop {
        z-index: 999998 !important;
    }

    body.eventosapp-app-page .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-chrome-actions {
        z-index: 2 !important;
    }

    body.eventosapp-app-page .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-toggle,
    body.eventosapp-app-page .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-close {
        -webkit-appearance: none !important;
        appearance: none !important;
        color: #fff !important;
        box-shadow: none !important;
    }

    body.eventosapp-app-page .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-toggle {
        background: rgba(255, 255, 255, .12) !important;
        border-color: rgba(255, 255, 255, .28) !important;
    }

    body.eventosapp-app-page .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-toggle:hover,
    body.eventosapp-app-page .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-toggle:focus-visible {
        background: rgba(255, 255, 255, .20) !important;
        border-color: rgba(255, 255, 255, .42) !important;
    }

    body.eventosapp-app-page .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-close {
        background: rgba(255, 255, 255, .08) !important;
        border-color: rgba(255, 255, 255, .18) !important;
    }

    body.eventosapp-app-page .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-close:hover,
    body.eventosapp-app-page .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-menu-close:focus-visible {
        background: rgba(255, 255, 255, .16) !important;
        border-color: rgba(255, 255, 255, .28) !important;
    }

    body.eventosapp-app-page .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-action:not(.is-admin):not(.is-logout),
    body.eventosapp-app-page .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-action:not(.is-admin):not(.is-logout):visited {
        background: rgba(255, 255, 255, .08) !important;
        border-color: rgba(255, 255, 255, .16) !important;
        color: #fff !important;
    }

    body.eventosapp-app-page .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-action:not(.is-admin):not(.is-logout):hover,
    body.eventosapp-app-page .evapp-app-chrome.has-evapp-mobile-menu .evapp-app-action:not(.is-admin):not(.is-logout):focus-visible {
        background: rgba(54, 131, 197, .28) !important;
        border-color: rgba(123, 177, 223, .40) !important;
        color: #fff !important;
    }
}
        </style>
        <?php
    }
}
add_action( 'wp_footer', 'eventosapp_elementor_compat_print_styles', 1002 );
