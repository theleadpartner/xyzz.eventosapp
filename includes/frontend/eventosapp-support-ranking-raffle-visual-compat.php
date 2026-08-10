<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Compatibilidad visual final para Asistencia/Métricas de apoyo, Ranking de
 * Networking y panel administrativo del Sorteo en Vivo.
 *
 * Los tres módulos mantienen renderizadores y CSS inline históricos. Esta capa
 * se imprime al final de las páginas mapeadas de EventosApp para remapear solo
 * presentación: identidad corporativa, superficies Light/Dark, controles,
 * estados semánticos y aislamiento frente a Elementor/tema.
 *
 * No modifica permisos, nonces, AJAX, consultas, QR/cámara, registro de
 * atenciones, métricas, networking, selección aleatoria, ganadores ni la
 * pantalla pública/proyección del Sorteo en Vivo.
 */

if ( ! function_exists( 'eventosapp_support_ranking_raffle_visual_compat_is_active' ) ) {
    function eventosapp_support_ranking_raffle_visual_compat_is_active() {
        if ( function_exists( 'eventosapp_elementor_compat_is_active' ) ) {
            return eventosapp_elementor_compat_is_active();
        }

        return function_exists( 'eventosapp_branding_is_managed_page' )
            && eventosapp_branding_is_managed_page();
    }
}

if ( ! function_exists( 'eventosapp_support_ranking_raffle_visual_compat_print_styles' ) ) {
    function eventosapp_support_ranking_raffle_visual_compat_print_styles() {
        if ( ! eventosapp_support_ranking_raffle_visual_compat_is_active() ) return;

        $icon_color = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'icon_color' )
            : '';
        $icon_white = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'icon_white' )
            : '';
        ?>
<style id="eventosapp-support-ranking-raffle-visual-compat">
/* ==========================================================
 * EventosApp 1.5.0-rc.14 — Soporte + Ranking + Sorteo
 * Marca + Dark Mode + aislamiento Elementor/tema
 * ========================================================== */

body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-support-app,
    .evapp-ranking-wrapper,
    .evapp-raffle-admin
) {
    --evapp-primary: var(--eventosapp-brand-blue) !important;
    --evapp-primary-dark: var(--eventosapp-brand-blue-dark) !important;
    --evapp-primary-soft: var(--eventosapp-brand-blue-soft) !important;
    --evapp-app-bg: var(--eventosapp-app-surface-soft) !important;
    --evapp-surface: var(--eventosapp-app-surface) !important;
    --evapp-border: var(--eventosapp-app-border) !important;
    --evapp-text: var(--eventosapp-app-text) !important;
    --evapp-muted: var(--eventosapp-app-muted) !important;
    --evapp-compat-accent-text: var(--eventosapp-brand-blue-dark);
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-support-app,
    .evapp-ranking-wrapper,
    .evapp-raffle-admin
) {
    --evapp-primary-soft: rgba(54, 131, 197, .16) !important;
    --evapp-success: #62d4a5 !important;
    --evapp-success-soft: rgba(22, 133, 91, .20) !important;
    --evapp-warning: #f1c861 !important;
    --evapp-warning-soft: rgba(161, 98, 7, .22) !important;
    --evapp-danger: #f08d8d !important;
    --evapp-danger-soft: rgba(197, 58, 58, .20) !important;
    --evapp-compat-accent-text: #8ec2ed;
}

/* Firma corporativa en los encabezados de los tres módulos. */
body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-support-eyebrow,
    .evapp-ranking-eyebrow,
    .evapp-raffle-eyebrow
) {
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    color: var(--evapp-compat-accent-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-support-eyebrow,
    .evapp-ranking-eyebrow,
    .evapp-raffle-eyebrow
)::before {
    content: "";
    display: inline-block;
    width: 16px;
    height: 18px;
    flex: 0 0 16px;
    margin: 0 !important;
    background: url("<?php echo esc_url( $icon_color ); ?>") center / contain no-repeat;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-support-eyebrow,
    .evapp-ranking-eyebrow,
    .evapp-raffle-eyebrow
)::before {
    background-image: url("<?php echo esc_url( $icon_white ); ?>") !important;
}

/* Protección común frente a typography/forms globales de Elementor/tema. */
body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-support-app,
    .evapp-ranking-wrapper,
    .evapp-raffle-admin
) :where(button, input, select, textarea, a) {
    font-family: inherit !important;
}

body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-support-app,
    .evapp-ranking-wrapper,
    .evapp-raffle-admin
) :where(button, input[type="button"], input[type="submit"], input[type="reset"]) {
    -webkit-appearance: none !important;
    appearance: none !important;
    text-transform: none !important;
    text-decoration: none !important;
}

body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-support-app,
    .evapp-ranking-wrapper,
    .evapp-raffle-admin
) :where(input, select, textarea)::placeholder {
    color: var(--eventosapp-app-muted) !important;
    opacity: 1 !important;
}

body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-support-app,
    .evapp-ranking-wrapper,
    .evapp-raffle-admin
) :where(input, select, textarea):focus {
    border-color: var(--eventosapp-brand-blue) !important;
}

body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-support-app,
    .evapp-ranking-wrapper,
    .evapp-raffle-admin
) :where(a, button, input, select, textarea):focus-visible {
    outline-color: var(--eventosapp-brand-blue) !important;
}

/* ==========================================================
 * Asistencia + Métricas de equipo de apoyo
 * ========================================================== */
body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-shell,
body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-notice-shell {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app :where(
    .evapp-support-event-context,
    .evapp-support-card,
    .evapp-support-method,
    .evapp-support-result,
    .evapp-support-status,
    .evapp-support-kpi-card,
    .evapp-support-ranking-table,
    .evapp-support-ranking-table tr
) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app :where(
    .evapp-support-search,
    .evapp-support-empty,
    .evapp-support-ranking-table th,
    .evapp-support-bar-track
) {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-app-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app :where(
    .evapp-support-main-title,
    .evapp-support-section-title,
    .evapp-support-event-name,
    .evapp-support-method-title,
    .evapp-support-result-name,
    .evapp-support-selected-name,
    .evapp-support-field-label,
    .evapp-support-kpi-value,
    .evapp-support-bar-hour,
    .evapp-support-user-name,
    .evapp-support-download-copy strong
) {
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app :where(
    .evapp-support-subtitle,
    .evapp-support-section-desc,
    .evapp-support-event-kicker,
    .evapp-support-method-desc,
    .evapp-support-search-foot,
    .evapp-support-result-meta,
    .evapp-support-selected-meta,
    .evapp-support-kpi-label,
    .evapp-support-kpi-detail,
    .evapp-support-bar-total,
    .evapp-support-user-email,
    .evapp-support-download-copy span,
    .evapp-support-empty
) {
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app :where(
    .evapp-support-input,
    .evapp-support-textarea
) {
    background: var(--eventosapp-app-input) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app input.evapp-support-input[type="search"] {
    -webkit-appearance: none !important;
    appearance: none !important;
    padding: 10px 46px 10px 46px !important;
    background-image: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app input.evapp-support-input[type="search"]::-webkit-search-decoration,
body.eventosapp-app-page #eventosapp-app-root .evapp-support-app input.evapp-support-input[type="search"]::-webkit-search-cancel-button,
body.eventosapp-app-page #eventosapp-app-root .evapp-support-app input.evapp-support-input[type="search"]::-webkit-search-results-button,
body.eventosapp-app-page #eventosapp-app-root .evapp-support-app input.evapp-support-input[type="search"]::-webkit-search-results-decoration {
    -webkit-appearance: none !important;
    appearance: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app :where(
    .evapp-support-chip,
    .evapp-support-result-avatar,
    .evapp-support-result-arrow,
    .evapp-support-kpi-icon,
    .evapp-support-rank,
    .evapp-support-count-badge
) {
    background: var(--evapp-primary-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--evapp-compat-accent-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-selected,
body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-download-row {
    background: var(--evapp-primary-soft) !important;
    border-color: rgba(54, 131, 197, .34) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app :where(
    .evapp-support-method,
    .evapp-support-result
):hover {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-method.is-active {
    background: var(--evapp-primary-soft) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-method.is-danger {
    background: var(--evapp-danger-soft) !important;
    border-color: rgba(197, 58, 58, .35) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-btn-primary {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-btn-primary:hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-btn-primary:focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-btn-secondary {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--evapp-compat-accent-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-btn-secondary:hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-btn-secondary:focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--evapp-compat-accent-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-btn-danger {
    background: var(--evapp-danger, #b42318) !important;
    border-color: var(--evapp-danger, #b42318) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-clear:hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-clear:focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-ranking-table th {
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-ranking-table td {
    background: transparent !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-ranking-table tbody tr:hover {
    background: var(--eventosapp-app-surface-raised) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-bar-track {
    border-color: var(--eventosapp-app-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-bar {
    background: linear-gradient(90deg, var(--eventosapp-brand-blue-dark), var(--eventosapp-brand-blue)) !important;
}

/* La cámara mantiene intencionalmente su superficie técnica oscura. */
body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-camera {
    border-color: var(--eventosapp-app-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-status.is-success {
    background: #f0fdf4 !important;
    border-color: #86efac !important;
    color: #166534 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-status.is-error,
body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-notice.is-error {
    background: #fef2f2 !important;
    border-color: #fecaca !important;
    color: #991b1b !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-notice:not(.is-error) {
    background: #fffbeb !important;
    border-color: #fde68a !important;
    color: #92400e !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-status.is-success {
    background: rgba(22, 133, 91, .20) !important;
    border-color: rgba(98, 212, 165, .32) !important;
    color: #8be1bd !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-support-app :where(
    .evapp-support-status.is-error,
    .evapp-support-notice.is-error
) {
    background: rgba(197, 58, 58, .20) !important;
    border-color: rgba(240, 141, 141, .34) !important;
    color: #f4aaaa !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-notice:not(.is-error) {
    background: rgba(161, 98, 7, .22) !important;
    border-color: rgba(241, 200, 97, .30) !important;
    color: #f4d784 !important;
}

@media (max-width: 620px) {
    body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-ranking-table tr {
        background: var(--eventosapp-app-surface) !important;
        border-color: var(--eventosapp-app-border) !important;
    }

    body.eventosapp-app-page #eventosapp-app-root .evapp-support-app .evapp-support-ranking-table td {
        border-color: var(--eventosapp-app-border) !important;
    }
}

/* ==========================================================
 * Ranking de Networking
 * ========================================================== */
body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper :where(
    .evapp-ranking-event-context,
    .evapp-ranking-panel,
    .evapp-ranking-card
) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper :where(
    .evapp-ranking-card-header,
    .evapp-ranking-item:hover
) {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-app-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper :where(
    .evapp-ranking-head h1,
    .evapp-ranking-event-name,
    .evapp-ranking-panel h2,
    .evapp-ranking-panel h3,
    .evapp-ranking-card-header h2,
    .evapp-ranking-name,
    .evapp-ranking-chip strong
) {
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper :where(
    .evapp-ranking-subtitle,
    .evapp-ranking-event-kicker,
    .evapp-ranking-panel-intro,
    .evapp-ranking-empty,
    .evapp-ranking-chip
) {
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper .evapp-ranking-chip {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper .evapp-landing-url {
    background: var(--eventosapp-app-input) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper :where(
    .evapp-ranking-btn,
    .evapp-landing-btn:not(.evapp-landing-btn-copy)
) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--evapp-compat-accent-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper :where(
    .evapp-ranking-btn,
    .evapp-landing-btn:not(.evapp-landing-btn-copy)
):hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper :where(
    .evapp-ranking-btn,
    .evapp-landing-btn:not(.evapp-landing-btn-copy)
):focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--evapp-compat-accent-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper :where(
    .evapp-refresh-btn,
    .evapp-landing-btn-copy
) {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper :where(
    .evapp-refresh-btn,
    .evapp-landing-btn-copy
):hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper :where(
    .evapp-refresh-btn,
    .evapp-landing-btn-copy
):focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper .evapp-ranking-list {
    background: transparent !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper .evapp-ranking-item {
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper :where(
    .evapp-ranking-position,
    .evapp-ranking-count
) {
    background: var(--eventosapp-app-surface-raised) !important;
    color: var(--evapp-compat-accent-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper .evapp-ranking-count {
    background: var(--evapp-primary-soft) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper .evapp-ranking-item.rank-1 {
    background: rgba(245, 190, 45, .11) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper .evapp-ranking-item.rank-1 .evapp-ranking-position {
    background: rgba(245, 190, 45, .22) !important;
    color: #846400 !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper .evapp-ranking-item.rank-2 .evapp-ranking-position {
    background: var(--eventosapp-app-surface-raised) !important;
    color: var(--eventosapp-app-muted) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper .evapp-ranking-item.rank-3 .evapp-ranking-position {
    background: rgba(184, 115, 51, .16) !important;
    color: #8a5428 !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper .evapp-ranking-item.rank-1 .evapp-ranking-position {
    color: #f5d978 !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-ranking-wrapper .evapp-ranking-item.rank-3 .evapp-ranking-position {
    color: #e7b07e !important;
}

/* ==========================================================
 * Sorteo en Vivo — panel administrativo únicamente.
 * La pantalla pública/proyección no se toca.
 * ========================================================== */
body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin :where(
    .evapp-raffle-event-context,
    .evapp-raffle-panel,
    .evapp-raffle-stat,
    .evapp-raffle-check,
    .evapp-raffle-table-wrap,
    .evapp-raffle-winner,
    .evapp-raffle-empty,
    .evapp-raffle-live-toggle
) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin :where(
    .evapp-raffle-head h1,
    .evapp-raffle-event-name,
    .evapp-raffle-panel h2,
    .evapp-raffle-panel h3,
    .evapp-raffle-field > label,
    .evapp-raffle-label,
    .evapp-raffle-stat strong,
    .evapp-raffle-winner h4,
    .evapp-raffle-winner label,
    .evapp-raffle-chip strong
) {
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin :where(
    .evapp-raffle-head p,
    .evapp-raffle-event-kicker,
    .evapp-raffle-panel-intro,
    .evapp-raffle-help,
    .evapp-raffle-stat span,
    .evapp-raffle-winner small,
    .evapp-raffle-empty,
    .evapp-raffle-pagination,
    .evapp-raffle-live-toggle,
    .evapp-raffle-chip
) {
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-chip {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin :where(
    .evapp-raffle-select,
    .evapp-raffle-url,
    .evapp-raffle-search,
    .evapp-raffle-winner input,
    .evapp-raffle-winner textarea
) {
    background: var(--eventosapp-app-input) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-select option {
    background: var(--eventosapp-app-input) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin :where(
    .evapp-raffle-url,
    .evapp-raffle-search
) {
    background-image: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-check:hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-check:focus-within {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-btn:not(.primary):not(.success):not(.warning):not(.danger) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--evapp-compat-accent-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-btn:not(.primary):not(.success):not(.warning):not(.danger):hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-btn:not(.primary):not(.success):not(.warning):not(.danger):focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--evapp-compat-accent-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-btn.primary {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-btn.primary:hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-btn.primary:focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-btn.success {
    background: #16864a !important;
    border-color: #16864a !important;
    color: #fff !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-btn.warning {
    background: #c96d08 !important;
    border-color: #c96d08 !important;
    color: #fff !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-btn.danger {
    background: #c8372f !important;
    border-color: #c8372f !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-live-toggle.is-active {
    background: #f0fdf4 !important;
    border-color: #86efac !important;
    color: #166534 !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-live-toggle.is-active {
    background: rgba(22, 133, 91, .20) !important;
    border-color: rgba(98, 212, 165, .32) !important;
    color: #8be1bd !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-table-wrap {
    background: var(--eventosapp-app-surface) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-table th {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-table td {
    background: transparent !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-table tbody tr:hover {
    background: var(--eventosapp-app-surface-raised) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-badge {
    background: var(--eventosapp-app-surface-raised) !important;
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-badge.info {
    background: var(--evapp-primary-soft) !important;
    color: var(--evapp-compat-accent-text) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-badge.yes {
    background: #dcfce7 !important;
    color: #166534 !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-badge.no {
    background: #fee2e2 !important;
    color: #991b1b !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-badge.yes {
    background: rgba(22, 133, 91, .20) !important;
    color: #8be1bd !important;
}
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-badge.no {
    background: rgba(197, 58, 58, .20) !important;
    color: #f4aaaa !important;
}

/* El escenario conserva su función visual, pero usa la paleta oficial. */
body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-stage {
    background:
        radial-gradient(circle at 15% 12%, rgba(54, 131, 197, .30), transparent 34%),
        radial-gradient(circle at 88% 84%, rgba(40, 98, 145, .34), transparent 38%),
        linear-gradient(145deg, #171e37, #286291 58%, #3683c5) !important;
    border-color: rgba(255,255,255,.16) !important;
}

/* Dentro del escenario, los controles transparentes siguen siendo blancos. */
body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-stage .evapp-raffle-btn:not(.primary):not(.success):not(.warning):not(.danger) {
    background: rgba(255,255,255,.10) !important;
    border-color: rgba(255,255,255,.28) !important;
    color: #fff !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-stage .evapp-raffle-btn:not(.primary):not(.success):not(.warning):not(.danger):hover:not(:disabled) {
    background: rgba(255,255,255,.16) !important;
    border-color: rgba(255,255,255,.38) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-prize {
    color: #fff !important;
    background: rgba(255,255,255,.11) !important;
    border-color: rgba(255,255,255,.32) !important;
}
body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-prize::placeholder {
    color: rgba(255,255,255,.72) !important;
}

/* Empty states y ganadores dejan de volver a blanco en Dark Mode. */
body.eventosapp-app-page #eventosapp-app-root .evapp-raffle-admin .evapp-raffle-empty {
    background: var(--eventosapp-app-surface-raised) !important;
}

@media (max-width: 520px) {
    body.eventosapp-app-page #eventosapp-app-root :where(
        .evapp-support-eyebrow,
        .evapp-ranking-eyebrow,
        .evapp-raffle-eyebrow
    )::before {
        width: 15px;
        height: 17px;
        flex-basis: 15px;
    }
}
</style>
        <?php
    }
}
add_action( 'wp_footer', 'eventosapp_support_ranking_raffle_visual_compat_print_styles', 1010 );
