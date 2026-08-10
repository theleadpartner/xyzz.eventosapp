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

        $icon_color = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'icon_color' )
            : '';
        $icon_white = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'icon_white' )
            : '';
        ?>
        <style id="eventosapp-elementor-compat">
/* ==========================================================
 * EventosApp 1.5.0-rc.10 — aislamiento final de módulos app
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

/* ==========================================================
 * Métricas de Encuestas: marca, Dark Mode y aislamiento
 * ========================================================== */
body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app {
    --evapp-primary: var(--eventosapp-brand-blue) !important;
    --evapp-primary-dark: var(--eventosapp-brand-blue-dark) !important;
    --evapp-primary-soft: var(--eventosapp-brand-blue-soft) !important;
    --evapp-app-bg: var(--eventosapp-app-surface-soft) !important;
    --evapp-surface: var(--eventosapp-app-surface) !important;
    --evapp-border: var(--eventosapp-app-border) !important;
    --evapp-text: var(--eventosapp-app-text) !important;
    --evapp-muted: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-eyebrow {
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-eyebrow::before {
    content: "";
    display: inline-block;
    width: 16px;
    height: 18px;
    flex: 0 0 16px;
    background: url("<?php echo esc_url( $icon_color ); ?>") center / contain no-repeat;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-eyebrow {
    color: #7bb1df !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-eyebrow::before {
    background-image: url("<?php echo esc_url( $icon_white ); ?>");
}

/* Los controles del módulo contienen fondos claros históricos. Se fijan a los
 * tokens comunes para que Elementor/tema y el CSS inline antiguo no puedan
 * reintroducir blancos, grises claros o colores de texto incompatibles. */
body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app :where(
    .evapp-flow-metrics-shell,
    .evapp-flow-metrics-event-context,
    .evapp-flow-metrics-toolbar,
    .evapp-flow-metrics-kpi,
    .evapp-flow-metrics-card,
    .evapp-flow-metrics-table-wrap
) {
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app :where(
    .evapp-flow-metrics-event-context,
    .evapp-flow-metrics-toolbar,
    .evapp-flow-metrics-kpi,
    .evapp-flow-metrics-card
) {
    background: var(--evapp-surface) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app :where(
    .evapp-flow-metrics-title,
    .evapp-flow-metrics-event-name,
    .evapp-flow-metrics-kpi strong,
    .evapp-flow-metrics-kpi small b,
    .evapp-flow-metrics-section-title,
    .evapp-flow-metrics-card h3
) {
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app :where(
    .evapp-flow-metrics-subtitle,
    .evapp-flow-metrics-event-kicker,
    .evapp-flow-metrics-event-flow,
    .evapp-flow-metrics-field-label,
    .evapp-flow-metrics-kpi-label,
    .evapp-flow-metrics-kpi small,
    .evapp-flow-metrics-section-copy,
    .evapp-flow-metrics-question-meta,
    .evapp-flow-metrics-note
) {
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-btn {
    -webkit-appearance: none !important;
    appearance: none !important;
    text-decoration: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-btn-secondary {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-btn-secondary:hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-btn-secondary:focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-btn-primary {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-btn-primary:hover:not(:disabled):not(.is-disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-btn-primary:focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-chip {
    background-color: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app :where(
    .evapp-flow-metrics-flow-static,
    .evapp-flow-metrics-status,
    .evapp-flow-metrics-question-meta span,
    .evapp-flow-metrics-chart-empty,
    .evapp-flow-metrics-note
) {
    background-color: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}

/* Conserva los textos auxiliares discretos y, en Light Mode, la semántica
 * cromática original de modalidad/estado aunque las reglas generales usen
 * !important para bloquear interferencias de Elementor. */
body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app :where(
    .evapp-flow-metrics-question-meta span,
    .evapp-flow-metrics-chart-empty,
    .evapp-flow-metrics-note
) {
    color: var(--evapp-muted) !important;
}

html:not([data-evapp-theme="dark"]) body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-chip.is-active {
    background: var(--evapp-success-soft) !important;
    border-color: #cfeadf !important;
    color: var(--evapp-success) !important;
}

html:not([data-evapp-theme="dark"]) body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-status.is-loading {
    background: var(--evapp-warning-soft) !important;
    border-color: #f1dfad !important;
    color: var(--evapp-warning) !important;
}

html:not([data-evapp-theme="dark"]) body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-status.is-ok {
    background: var(--evapp-success-soft) !important;
    border-color: #cfeadf !important;
    color: var(--evapp-success) !important;
}

html:not([data-evapp-theme="dark"]) body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-status.is-error {
    background: var(--evapp-danger-soft) !important;
    border-color: #f2cccc !important;
    color: var(--evapp-danger) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-select {
    -webkit-appearance: auto !important;
    appearance: auto !important;
    background-color: var(--eventosapp-app-input) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-select option {
    background: var(--eventosapp-app-input) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-table {
    background: var(--evapp-surface) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-table :where(th, td) {
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-table th {
    background: var(--eventosapp-app-surface-raised) !important;
    color: var(--evapp-muted) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app {
    --evapp-primary-soft: rgba(54, 131, 197, .16) !important;
    --evapp-app-bg: var(--eventosapp-app-surface-soft) !important;
    --evapp-surface: var(--eventosapp-app-surface) !important;
    --evapp-border: var(--eventosapp-app-border) !important;
    --evapp-text: var(--eventosapp-app-text) !important;
    --evapp-muted: var(--eventosapp-app-muted) !important;
    --evapp-success: #62d4a5 !important;
    --evapp-success-soft: rgba(22, 133, 91, .20) !important;
    --evapp-warning: #f1c861 !important;
    --evapp-warning-soft: rgba(161, 98, 7, .22) !important;
    --evapp-danger: #f08d8d !important;
    --evapp-danger-soft: rgba(197, 58, 58, .20) !important;
    --evapp-purple: #b8a1f0 !important;
    --evapp-purple-soft: rgba(109, 75, 195, .22) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-shell {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app :where(
    .evapp-flow-metrics-event-icon,
    .evapp-flow-metrics-kpi-icon
) {
    border: 1px solid rgba(123, 177, 223, .18);
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-chip.is-active {
    background: var(--evapp-success-soft) !important;
    border-color: rgba(98, 212, 165, .30) !important;
    color: var(--evapp-success) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-status.is-loading {
    background: var(--evapp-warning-soft) !important;
    border-color: rgba(241, 200, 97, .30) !important;
    color: var(--evapp-warning) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-status.is-ok {
    background: var(--evapp-success-soft) !important;
    border-color: rgba(98, 212, 165, .30) !important;
    color: var(--evapp-success) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app .evapp-flow-metrics-status.is-error {
    background: var(--evapp-danger-soft) !important;
    border-color: rgba(240, 141, 141, .30) !important;
    color: var(--evapp-danger) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app :where(
    .evapp-flow-metrics-kpi-progress,
    .evapp-flow-metrics-chart-empty
) {
    background: var(--eventosapp-app-surface-raised) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-empty {
    background: var(--evapp-warning-soft) !important;
    border-color: rgba(241, 200, 97, .28) !important;
    color: var(--evapp-warning) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-empty :where(h3, p) {
    color: var(--evapp-warning) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-empty-icon {
    background: rgba(241, 200, 97, .14) !important;
    color: var(--evapp-warning) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-note {
    background: rgba(29, 39, 66, .82) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-muted) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-btn-secondary:hover:not(:disabled),
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-btn-secondary:focus-visible {
    background: rgba(54, 131, 197, .16) !important;
    border-color: rgba(123, 177, 223, .45) !important;
    color: #8fc0e8 !important;
}

/* ==========================================================
 * Empresas con Check-In: marca, Dark Mode y aislamiento
 * ========================================================== */
body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor {
    --evapp-primary: var(--eventosapp-brand-blue) !important;
    --evapp-primary-dark: var(--eventosapp-brand-blue-dark) !important;
    --evapp-primary-soft: var(--eventosapp-brand-blue-soft) !important;
    --evapp-app-bg: var(--eventosapp-app-surface-soft) !important;
    --evapp-surface: var(--eventosapp-app-surface) !important;
    --evapp-border: var(--eventosapp-app-border) !important;
    --evapp-text: var(--eventosapp-app-text) !important;
    --evapp-muted: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-eyebrow {
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-eyebrow::before {
    content: "";
    display: inline-block;
    width: 16px;
    height: 18px;
    flex: 0 0 16px;
    margin: 0 !important;
    background: url("<?php echo esc_url( $icon_color ); ?>") center / contain no-repeat;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-company-eyebrow {
    color: #7bb1df !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-company-eyebrow::before {
    background-image: url("<?php echo esc_url( $icon_white ); ?>");
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-shell {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor :where(
    .evapp-company-event-context,
    .evapp-company-kpi,
    .evapp-company-panel,
    .evapp-company-table-wrap
) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor :where(
    .evapp-company-title,
    .evapp-company-event-name,
    .evapp-company-kpi strong,
    .evapp-company-name,
    .evapp-company-nit,
    .evapp-company-state strong
) {
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor :where(
    .evapp-company-subtitle,
    .evapp-company-event-kicker,
    .evapp-company-kpi span,
    .evapp-company-control-label,
    .evapp-company-results,
    .evapp-company-updated,
    .evapp-company-company-hint,
    .evapp-company-arrival,
    .evapp-company-state p
) {
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-btn {
    -webkit-appearance: none !important;
    appearance: none !important;
    text-decoration: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor :where(
    .evapp-company-btn-secondary,
    .evapp-company-btn-ghost,
    .evapp-company-change-event,
    .evapp-company-chip
) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-btn-secondary {
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-btn-ghost,
body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-chip {
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-change-event {
    color: var(--eventosapp-brand-blue-dark) !important;
    text-decoration: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor :where(
    .evapp-company-btn-secondary,
    .evapp-company-btn-ghost,
    .evapp-company-change-event
):hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor :where(
    .evapp-company-btn-secondary,
    .evapp-company-btn-ghost,
    .evapp-company-change-event
):focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-btn-primary {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-btn-primary:hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-btn-primary:focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor :where(
    .evapp-company-control input,
    .evapp-company-control select
) {
    background-color: var(--eventosapp-app-input) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
    box-shadow: none;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-control select option {
    background: var(--eventosapp-app-input) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-control input::placeholder {
    color: var(--evapp-muted) !important;
    opacity: 1 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-search-icon {
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-meta,
body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-table :where(th, td) {
    border-color: var(--evapp-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-table {
    background: var(--evapp-surface) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-table th {
    background: var(--eventosapp-app-surface-raised) !important;
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-table tbody tr:hover {
    background: var(--eventosapp-app-surface-raised) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor :where(
    .evapp-company-rank,
    .evapp-company-count
) {
    background: var(--evapp-primary-soft) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-alias {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor :where(
    .evapp-company-state,
    .evapp-company-loading
) {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-error {
    background: var(--evapp-danger-soft) !important;
    border-color: color-mix(in srgb, var(--evapp-danger) 34%, transparent) !important;
    border-left-color: var(--evapp-danger) !important;
    color: var(--evapp-danger) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-nit-warning {
    color: var(--evapp-warning) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-spinner {
    border-color: var(--evapp-border) !important;
    border-top-color: var(--eventosapp-brand-blue) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor {
    --evapp-primary-soft: rgba(54, 131, 197, .16) !important;
    --evapp-app-bg: var(--eventosapp-app-surface-soft) !important;
    --evapp-success: #62d4a5 !important;
    --evapp-success-soft: rgba(22, 133, 91, .20) !important;
    --evapp-warning: #f1c861 !important;
    --evapp-warning-soft: rgba(161, 98, 7, .22) !important;
    --evapp-danger: #f08d8d !important;
    --evapp-danger-soft: rgba(197, 58, 58, .20) !important;
    --evapp-purple: #b8a1f0 !important;
    --evapp-purple-soft: rgba(109, 75, 195, .22) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor :where(
    .evapp-company-btn-secondary:hover:not(:disabled),
    .evapp-company-btn-secondary:focus-visible,
    .evapp-company-btn-ghost:hover:not(:disabled),
    .evapp-company-btn-ghost:focus-visible,
    .evapp-company-change-event:hover,
    .evapp-company-change-event:focus-visible
) {
    background: rgba(54, 131, 197, .16) !important;
    border-color: rgba(123, 177, 223, .45) !important;
    color: #8fc0e8 !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor :where(
    .evapp-company-rank,
    .evapp-company-count,
    .evapp-company-event-icon,
    .evapp-company-kpi-icon,
    .evapp-company-state-icon
) {
    border-color: rgba(123, 177, 223, .18) !important;
    color: #8fc0e8 !important;
}

@media (max-width: 760px) {
    body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-table tr {
        background: var(--evapp-surface) !important;
        border-color: var(--evapp-border) !important;
    }

    body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-table td {
        border-color: var(--evapp-border) !important;
    }

    body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-table td::before {
        color: var(--evapp-muted) !important;
    }

    body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor .evapp-company-table .evapp-company-cell-company {
        background: var(--eventosapp-app-surface-raised) !important;
    }
}

/* ==========================================================
 * Registro Manual: marca, Dark Mode y aislamiento
 * ========================================================== */
body.eventosapp-app-page #eventosapp-app-root .evreg-wrap {
    --evapp-primary: var(--eventosapp-brand-blue) !important;
    --evapp-primary-dark: var(--eventosapp-brand-blue-dark) !important;
    --evapp-primary-soft: var(--eventosapp-brand-blue-soft) !important;
    --evapp-app-bg: var(--eventosapp-app-surface-soft) !important;
    --evapp-surface: var(--eventosapp-app-surface) !important;
    --evapp-border: var(--eventosapp-app-border) !important;
    --evapp-text: var(--eventosapp-app-text) !important;
    --evapp-muted: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-eyebrow {
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-eyebrow::before {
    width: 16px !important;
    height: 18px !important;
    flex: 0 0 16px !important;
    margin: 0 !important;
    vertical-align: 0 !important;
    background: url("<?php echo esc_url( $icon_color ); ?>") center / contain no-repeat !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evreg-eyebrow {
    color: #7bb1df !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evreg-eyebrow::before {
    background-image: url("<?php echo esc_url( $icon_white ); ?>") !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap :where(
    .evreg-event-context,
    .evreg-form-section,
    .evreg-submit
) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap :where(
    .evreg-main-title,
    .evreg-event-name,
    .evreg-title,
    .evreg-label,
    .evreg-submit-copy strong,
    .evreg-delivery-option-copy strong
) {
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap :where(
    .evreg-main-subtitle,
    .evreg-event-label,
    .evreg-event-meta,
    .evreg-sub,
    .evreg-help,
    .evreg-submit-copy span,
    .evreg-delivery-option-copy small,
    .evreg-delivery-note
) {
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-input {
    background: var(--eventosapp-app-input) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
    box-shadow: none;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-input:hover,
body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-input:focus {
    background: var(--eventosapp-app-input) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-input::placeholder {
    color: var(--evapp-muted) !important;
    opacity: 1 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap select.evreg-input option {
    background: var(--eventosapp-app-input) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-readonly-value {
    background: var(--evapp-primary-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-delivery-option {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-delivery-option:hover {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-delivery-option:has(.evreg-delivery-checkbox:checked) {
    background: var(--evapp-primary-soft) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-delivery-check {
    background: var(--eventosapp-app-input) !important;
    border-color: var(--evapp-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-delivery-checkbox:checked + .evreg-delivery-check {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-btn {
    -webkit-appearance: none !important;
    appearance: none !important;
    text-decoration: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-btn-primary {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-btn-primary:hover,
body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-btn-primary:focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-btn-secondary {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-btn-secondary:hover,
body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-btn-secondary:focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-processing {
    background: var(--evapp-primary-soft) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-card-success,
body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-delivery-result--sent {
    background: var(--evapp-success-soft) !important;
    border-color: var(--evapp-success) !important;
    color: var(--evapp-success) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap :where(
    .evreg-success-title,
    .evreg-success-id
) {
    color: var(--evapp-success) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap :where(
    .evreg-alert-warn,
    .evreg-delivery-result--skipped,
    .evreg-delivery-result--processed
) {
    background: var(--evapp-warning-soft) !important;
    border-color: var(--evapp-warning) !important;
    color: var(--evapp-warning) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-wrap :where(
    .evreg-alert-error,
    .evreg-delivery-result--error
) {
    background: var(--evapp-danger-soft) !important;
    border-color: var(--evapp-danger) !important;
    color: var(--evapp-danger) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-inline-notice {
    color: var(--eventosapp-app-text) !important;
    background: var(--eventosapp-app-surface) !important;
    border: 1px solid var(--eventosapp-app-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-inline-notice--warning {
    color: #8a4b08 !important;
    background: #fff8e7 !important;
    border-color: #f3d49b !important;
}

body.eventosapp-app-page #eventosapp-app-root .evreg-inline-notice--error {
    color: #8b1e17 !important;
    background: #fff1f0 !important;
    border-color: #f2b8b5 !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evreg-wrap {
    --evapp-primary-soft: rgba(54, 131, 197, .16) !important;
    --evapp-app-bg: var(--eventosapp-app-surface-soft) !important;
    --evapp-success: #62d4a5 !important;
    --evapp-success-soft: rgba(21, 128, 61, .20) !important;
    --evapp-warning: #f1c861 !important;
    --evapp-warning-soft: rgba(180, 83, 9, .22) !important;
    --evapp-danger: #f08d8d !important;
    --evapp-danger-soft: rgba(180, 35, 24, .20) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-readonly-value,
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-processing,
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-btn-secondary:hover,
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evreg-wrap .evreg-btn-secondary:focus-visible {
    color: #8fc0e8 !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evreg-inline-notice--warning {
    color: #f1c861 !important;
    background: rgba(180, 83, 9, .22) !important;
    border-color: rgba(241, 200, 97, .34) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evreg-inline-notice--error {
    color: #f08d8d !important;
    background: rgba(180, 35, 24, .20) !important;
    border-color: rgba(240, 141, 141, .34) !important;
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
        <script id="eventosapp-flow-metrics-chart-theme-compat">
(function(){
    'use strict';

    var root = document.querySelector('#eventosapp-app-root .evapp-flow-metrics-app');
    if (!root) return;

    function cssVar(name, fallback) {
        var value = window.getComputedStyle(root).getPropertyValue(name);
        value = value ? value.trim() : '';
        return value || fallback;
    }

    function syncCharts() {
        if (!window.Chart || typeof window.Chart.getChart !== 'function') return;

        var text = cssVar('--evapp-text', '#171e37');
        var muted = cssVar('--evapp-muted', '#66758f');
        var border = cssVar('--evapp-border', '#dfe7f1');
        var surface = cssVar('--evapp-surface', '#ffffff');
        var raised = window.getComputedStyle(document.documentElement).getPropertyValue('--eventosapp-app-surface-raised');
        raised = raised ? raised.trim() : surface;
        var isDark = document.documentElement.getAttribute('data-evapp-theme') === 'dark';

        root.querySelectorAll('.evapp-flow-metrics-chart-box canvas').forEach(function(canvas){
            var chart = window.Chart.getChart(canvas);
            if (!chart || !chart.options) return;

            if (chart.data && Array.isArray(chart.data.datasets)) {
                chart.data.datasets.forEach(function(dataset){
                    if (chart.config && chart.config.type === 'doughnut') {
                        dataset.borderColor = surface;
                    }
                });
            }

            if (chart.options.plugins && chart.options.plugins.legend && chart.options.plugins.legend.labels) {
                chart.options.plugins.legend.labels.color = muted;
            }

            if (chart.options.plugins && chart.options.plugins.tooltip) {
                chart.options.plugins.tooltip.backgroundColor = isDark ? raised : 'rgba(23, 30, 55, .92)';
                chart.options.plugins.tooltip.titleColor = '#ffffff';
                chart.options.plugins.tooltip.bodyColor = '#ffffff';
                chart.options.plugins.tooltip.borderColor = isDark ? border : 'rgba(255,255,255,.16)';
                chart.options.plugins.tooltip.borderWidth = 1;
            }

            if (chart.options.scales) {
                if (chart.options.scales.x) {
                    if (chart.options.scales.x.grid) chart.options.scales.x.grid.color = border;
                    if (chart.options.scales.x.ticks) chart.options.scales.x.ticks.color = muted;
                }
                if (chart.options.scales.y && chart.options.scales.y.ticks) {
                    chart.options.scales.y.ticks.color = text;
                }
            }

            try { chart.update('none'); } catch (error) {}
        });
    }

    var themeObserver = new MutationObserver(function(mutations){
        var changed = mutations.some(function(mutation){
            return mutation.type === 'attributes' && mutation.attributeName === 'data-evapp-theme';
        });
        if (changed) window.requestAnimationFrame(syncCharts);
    });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-evapp-theme'] });

    var contentObserver = new MutationObserver(function(){
        window.requestAnimationFrame(syncCharts);
    });
    contentObserver.observe(root, { childList: true, subtree: true });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncCharts, { once: true });
    } else {
        syncCharts();
    }

    window.setTimeout(syncCharts, 350);
    window.setTimeout(syncCharts, 900);
})();
        </script>
        <?php
    }
}
add_action( 'wp_footer', 'eventosapp_elementor_compat_print_styles', 1002 );
