<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Compatibilidad visual final para Expositor y Gestión de Expositores.
 *
 * includes/admin/eventosapp-expositores.php conserva un renderizador frontend
 * consolidado con CSS inline histórico. Esta capa se imprime después del
 * branding, del aislamiento general de Elementor y de los hotfix visuales
 * anteriores para remapear únicamente presentación: identidad corporativa,
 * superficies, controles, tablas, estados y contraste Light/Dark.
 *
 * No modifica productos, inventario, entregas, QR/cámara, permisos de descarga,
 * AJAX, nonces, CSV, usuarios asignados, consultas ni persistencia.
 */

if ( ! function_exists( 'eventosapp_expositores_visual_compat_is_active' ) ) {
    function eventosapp_expositores_visual_compat_is_active() {
        if ( function_exists( 'eventosapp_elementor_compat_is_active' ) ) {
            return eventosapp_elementor_compat_is_active();
        }

        return function_exists( 'eventosapp_branding_is_managed_page' )
            && eventosapp_branding_is_managed_page();
    }
}

if ( ! function_exists( 'eventosapp_expositores_visual_compat_print_styles' ) ) {
    function eventosapp_expositores_visual_compat_print_styles() {
        if ( ! eventosapp_expositores_visual_compat_is_active() ) return;

        $icon_color = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'icon_color' )
            : '';
        $icon_white = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'icon_white' )
            : '';
        ?>
<style id="eventosapp-expositores-visual-compat">
/* ==========================================================
 * EventosApp 1.5.0-rc.16 — Expositores
 * Marca + Dark Mode + aislamiento Elementor/tema
 * ========================================================== */

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module {
    --evapp-primary: var(--eventosapp-brand-blue) !important;
    --evapp-primary-dark: var(--eventosapp-brand-blue-dark) !important;
    --evapp-primary-soft: var(--eventosapp-brand-blue-soft) !important;
    --evapp-app-bg: var(--eventosapp-app-surface-soft) !important;
    --evapp-surface: var(--eventosapp-app-surface) !important;
    --evapp-border: var(--eventosapp-app-border) !important;
    --evapp-text: var(--eventosapp-app-text) !important;
    --evapp-muted: var(--eventosapp-app-muted) !important;
    color: var(--eventosapp-app-text) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module {
    --evapp-primary-soft: rgba(54, 131, 197, .16) !important;
    --evapp-success: #62d4a5 !important;
    --evapp-success-soft: rgba(22, 133, 91, .20) !important;
    --evapp-danger: #f08d8d !important;
    --evapp-danger-soft: rgba(197, 58, 58, .20) !important;
    --evapp-warning: #f1c861 !important;
    --evapp-warning-soft: rgba(161, 98, 7, .22) !important;
    --evapp-app-bg: var(--eventosapp-app-surface-soft) !important;
    --evapp-surface: var(--eventosapp-app-surface) !important;
    --evapp-border: var(--eventosapp-app-border) !important;
    --evapp-text: var(--eventosapp-app-text) !important;
    --evapp-muted: var(--eventosapp-app-muted) !important;
}

/* Firma corporativa oficial del módulo. */
body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-eyebrow {
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-eyebrow::before {
    content: "";
    display: inline-block;
    width: 16px;
    height: 18px;
    flex: 0 0 16px;
    margin: 0 !important;
    background: url("<?php echo esc_url( $icon_color ); ?>") center / contain no-repeat;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-eyebrow {
    color: #7bb1df !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-eyebrow::before {
    background-image: url("<?php echo esc_url( $icon_white ); ?>") !important;
}

/* Shell, contexto, métricas y paneles. */
body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-shell {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module :where(
    .evapp-expositor-event-context,
    .evapp-expositor-stat-card,
    .evapp-expositor-app,
    .evapp-expositor-panels,
    .evapp-expositor-card,
    .evapp-expositor-product-card,
    .evapp-expositor-chip,
    .evapp-expositor-attendee,
    .evapp-expositor-empty,
    .evapp-expositor-table-wrap,
    .evapp-expositor-table
) {
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module :where(
    .evapp-expositor-event-context,
    .evapp-expositor-stat-card,
    .evapp-expositor-app,
    .evapp-expositor-panels,
    .evapp-expositor-card,
    .evapp-expositor-chip,
    .evapp-expositor-table-wrap,
    .evapp-expositor-table
) {
    background: var(--eventosapp-app-surface) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module :where(
    .evapp-expositor-product-card,
    .evapp-expositor-empty
) {
    background: var(--eventosapp-app-surface-soft) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-attendee {
    background: var(--evapp-primary-soft) !important;
    border-color: rgba(54, 131, 197, .28) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module :where(
    .evapp-expositor-header h1,
    .evapp-expositor-header h2,
    .evapp-expositor-event-name,
    .evapp-expositor-stat,
    .evapp-expositor-panel-head h3,
    .evapp-expositor-card h3,
    .evapp-expositor-product-title,
    .evapp-expositor-chip strong,
    .evapp-expositor-attendee h3,
    .evapp-expositor-table-name
) {
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module :where(
    .evapp-expositor-subtitle,
    .evapp-expositor-muted,
    .evapp-expositor-event-label,
    .evapp-expositor-event-detail,
    .evapp-expositor-stat-card h3,
    .evapp-expositor-stat-detail,
    .evapp-expositor-card-desc,
    .evapp-expositor-field small,
    .evapp-expositor-product-code,
    .evapp-expositor-chip span,
    .evapp-expositor-stock-copy,
    .evapp-expositor-table-sub,
    .evapp-expositor-toggle-text,
    .evapp-expositor-save-copy
) {
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module :where(
    .evapp-expositor-event-icon,
    .evapp-expositor-stat-icon,
    .evapp-expositor-table-number
) {
    background: var(--evapp-primary-soft) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module :where(
    .evapp-expositor-event-icon,
    .evapp-expositor-stat-icon,
    .evapp-expositor-table-number
) {
    color: #7bb1df !important;
}

/* Pestañas del Expositor. */
body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-tabs {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-tab {
    appearance: none !important;
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
    text-transform: none !important;
    text-decoration: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-tab:hover:not(.is-active),
body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-tab:focus-visible:not(.is-active) {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-tab:hover:not(.is-active),
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-tab:focus-visible:not(.is-active) {
    color: #7bb1df !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-tab.is-active {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

/* Formularios: neutraliza estilos globales de Elementor/tema. */
body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module :where(
    input.evapp-expositor-input,
    select.evapp-expositor-select
) {
    min-height: 46px !important;
    margin: 0 !important;
    padding: 10px 12px !important;
    background: var(--eventosapp-app-input) !important;
    border: 1px solid var(--eventosapp-app-border) !important;
    border-radius: 12px !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: none !important;
    font: inherit !important;
    font-size: 15px !important;
    line-height: 1.35 !important;
    text-transform: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module input.evapp-expositor-input::placeholder {
    color: var(--eventosapp-app-muted) !important;
    opacity: 1 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module select.evapp-expositor-select option {
    background: var(--eventosapp-app-input) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module :where(
    input.evapp-expositor-input,
    select.evapp-expositor-select
):focus {
    border-color: var(--eventosapp-brand-blue) !important;
    box-shadow: 0 0 0 4px var(--eventosapp-brand-focus) !important;
    outline: 0 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-field label,
body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-switch label {
    color: var(--eventosapp-app-muted) !important;
}

/* Botones y enlaces de acción. */
body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-btn {
    appearance: none !important;
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
    font: inherit !important;
    font-weight: 750 !important;
    line-height: 1.15 !important;
    text-transform: none !important;
    text-decoration: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-btn:hover:not(:disabled),
body.eventosapp-app-root .evapp-expositor-module .evapp-expositor-btn:focus-visible:not(:disabled) {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-btn.secondary {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-btn.secondary:hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-btn.secondary:focus-visible:not(:disabled) {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-btn.secondary:hover:not(:disabled),
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-btn.secondary:focus-visible:not(:disabled) {
    color: #7bb1df !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-btn.danger {
    background: var(--evapp-danger) !important;
    border-color: var(--evapp-danger) !important;
    color: #fff !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-btn.danger {
    background: rgba(197, 58, 58, .20) !important;
    border-color: rgba(240, 141, 141, .42) !important;
    color: #f4aaaa !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-btn:disabled {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-muted) !important;
    box-shadow: none !important;
    opacity: 1 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module :where(
    .evapp-expositor-btn,
    .evapp-expositor-tab,
    .evapp-expositor-input,
    .evapp-expositor-select
):focus-visible,
body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-toggle input:focus-visible + .evapp-expositor-toggle-ui {
    outline: 3px solid var(--eventosapp-brand-focus) !important;
    outline-offset: 2px !important;
}

/* Inventario y estados. */
body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-stock-track {
    background: var(--eventosapp-app-surface-raised) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-badge {
    background: var(--eventosapp-app-surface-raised) !important;
    border: 1px solid var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-badge.is-active {
    background: var(--evapp-success-soft) !important;
    border-color: rgba(21, 128, 61, .24) !important;
    color: var(--evapp-success) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-badge.is-active {
    border-color: rgba(98, 212, 165, .32) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-badge.is-inactive {
    background: var(--evapp-danger-soft) !important;
    border-color: rgba(180, 35, 24, .24) !important;
    color: var(--evapp-danger) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-badge.is-inactive {
    border-color: rgba(240, 141, 141, .32) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-notice {
    background: var(--evapp-warning-soft) !important;
    border-color: #f1dfad !important;
    border-left-color: var(--evapp-warning) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-notice.error {
    background: var(--evapp-danger-soft) !important;
    border-color: #f2b8b5 !important;
    border-left-color: var(--evapp-danger) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-notice.success {
    background: var(--evapp-success-soft) !important;
    border-color: #b7e4c7 !important;
    border-left-color: var(--evapp-success) !important;
    color: var(--eventosapp-app-text) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-notice {
    border-color: rgba(241, 200, 97, .32) !important;
    border-left-color: var(--evapp-warning) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-notice.error {
    border-color: rgba(240, 141, 141, .32) !important;
    border-left-color: var(--evapp-danger) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-notice.success {
    border-color: rgba(98, 212, 165, .32) !important;
    border-left-color: var(--evapp-success) !important;
}

/* La cámara es una superficie técnica y permanece deliberadamente oscura. */
body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-scanner {
    border-color: #344764 !important;
    background: #0f172a !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-scanner video {
    background: #020617 !important;
}

/* Tabla de Gestión de Expositores. */
body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-table th {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-table td {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-table tbody tr:hover td {
    background: var(--eventosapp-app-surface-raised) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-toggle-ui {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-app-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-toggle-ui::after {
    background: var(--eventosapp-app-surface) !important;
    border: 1px solid var(--eventosapp-app-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-toggle input:checked + .evapp-expositor-toggle-ui {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-toggle input:checked + .evapp-expositor-toggle-ui::after {
    background: #fff !important;
    border-color: rgba(255,255,255,.65) !important;
}

/* La vista móvil convierte cada fila en tarjeta; evita el blanco histórico y
 * sincroniza sus divisores con el tema activo. */
@media (max-width: 767px) {
    body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-table tr {
        background: var(--eventosapp-app-surface) !important;
        border-color: var(--eventosapp-app-border) !important;
    }

    body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-table td {
        background: transparent !important;
        border-bottom-color: var(--eventosapp-app-border) !important;
    }

    body.eventosapp-app-page #eventosapp-app-root .evapp-expositor-module .evapp-expositor-table tbody tr:hover td {
        background: transparent !important;
    }
}
</style>
        <?php
    }
}
add_action( 'wp_footer', 'eventosapp_expositores_visual_compat_print_styles', 1011 );
