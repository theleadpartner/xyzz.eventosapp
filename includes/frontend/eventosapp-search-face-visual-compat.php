<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Compatibilidad visual final para Búsqueda / Check-In Manual y Check-In Facial.
 *
 * Ambos módulos conservan CSS inline histórico porque su lógica operativa ya está
 * consolidada. Esta capa se imprime después del branding, del aislamiento de
 * Elementor y de la compatibilidad QR para remapear únicamente presentación:
 * identidad corporativa, superficies, controles, estados y contraste Light/Dark.
 *
 * No modifica permisos, nonces, AJAX, consultas, búsqueda, impresión, acompañantes,
 * reglas de fecha, face-api.js, IndexedDB, cámara, detección ni lógica de check-in.
 */

if ( ! function_exists( 'eventosapp_search_face_visual_compat_is_active' ) ) {
    function eventosapp_search_face_visual_compat_is_active() {
        if ( function_exists( 'eventosapp_elementor_compat_is_active' ) ) {
            return eventosapp_elementor_compat_is_active();
        }

        return function_exists( 'eventosapp_branding_is_managed_page' )
            && eventosapp_branding_is_managed_page();
    }
}

if ( ! function_exists( 'eventosapp_search_face_visual_compat_print_styles' ) ) {
    function eventosapp_search_face_visual_compat_print_styles() {
        if ( ! eventosapp_search_face_visual_compat_is_active() ) return;

        $icon_color = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'icon_color' )
            : '';
        $icon_white = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'icon_white' )
            : '';
        ?>
<style id="eventosapp-search-face-visual-compat">
/* ==========================================================
 * EventosApp 1.5.0-rc.12 — Búsqueda Manual + Check-In Facial
 * Marca + Dark Mode + aislamiento Elementor/tema
 * ========================================================== */

body.eventosapp-app-page #eventosapp-app-root :where(
    .evfs-app,
    .evapp-face-checkin-app
) {
    --evapp-primary: var(--eventosapp-brand-blue) !important;
    --evapp-primary-dark: var(--eventosapp-brand-blue-dark) !important;
    --evapp-primary-soft: var(--eventosapp-brand-blue-soft) !important;
    --evapp-app-bg: var(--eventosapp-app-surface-soft) !important;
    --evapp-surface: var(--eventosapp-app-surface) !important;
    --evapp-border: var(--eventosapp-app-border) !important;
    --evapp-text: var(--eventosapp-app-text) !important;
    --evapp-muted: var(--eventosapp-app-muted) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    .evfs-app,
    .evapp-face-checkin-app
) {
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

/* Firma corporativa de ambos encabezados. */
body.eventosapp-app-page #eventosapp-app-root :where(
    .evfs-eyebrow,
    .evfc-eyebrow
) {
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root :where(
    .evfs-eyebrow,
    .evfc-eyebrow
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
    .evfs-eyebrow,
    .evfc-eyebrow
) {
    color: #7bb1df !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    .evfs-eyebrow,
    .evfc-eyebrow
)::before {
    background-image: url("<?php echo esc_url( $icon_white ); ?>") !important;
}

/* ==========================================================
 * Búsqueda y Check-In Manual
 * ========================================================== */
body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-shell {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    .evfs-event-context,
    .evfs-search-card,
    .evfs-row
) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    .evfs-main-title,
    .evfs-search-title,
    .evfs-event-name,
    .evfs-person-name,
    .evfs-state strong,
    .evfs-info-value
) {
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    .evfs-subtitle,
    .evfs-search-description,
    .evfs-event-kicker,
    .evfs-search-foot,
    .evfs-result-count,
    .evfs-state,
    .evfs-info-item,
    .evfs-acomp-status
) {
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-info-item strong {
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    .evfs-select,
    input.evfs-input[type="search"],
    .evfs-acomp-input
) {
    background-color: var(--eventosapp-app-input) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
    box-shadow: none;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-select {
    -webkit-appearance: auto !important;
    appearance: auto !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app input.evfs-input[type="search"] {
    -webkit-appearance: none !important;
    appearance: none !important;
    padding: 10px 46px 10px 46px !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-select option {
    background: var(--eventosapp-app-input) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    input.evfs-input[type="search"],
    .evfs-acomp-input
)::placeholder {
    color: var(--evapp-muted) !important;
    opacity: 1 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    .evfs-search-icon,
    .evfs-clear
) {
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-clear:hover,
body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-clear:focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    .evfs-btn,
    .evfs-acomp-btn
) {
    -webkit-appearance: none !important;
    appearance: none !important;
    text-decoration: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    .evfs-btn-secondary,
    .evfs-print
) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    .evfs-btn-secondary,
    .evfs-print
):hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    .evfs-btn-secondary,
    .evfs-print
):focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    .evfs-btn-primary,
    .evfs-check,
    .evfs-acomp-btn
) {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    .evfs-btn-primary,
    .evfs-check,
    .evfs-acomp-btn
):hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    .evfs-btn-primary,
    .evfs-check,
    .evfs-acomp-btn
):focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

/* No sustituye la regla de negocio de fecha: solo evita que Elementor pinte el
 * botón deshabilitado con un color ajeno a la marca (caso visible en captura). */
body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-check:disabled {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-muted) !important;
    box-shadow: none !important;
    opacity: 1 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-check.is-checked:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-check[aria-checked="true"]:not(:disabled) {
    background: var(--evapp-success) !important;
    border-color: var(--evapp-success) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    .evfs-chip,
    .evfs-state,
    .evfs-avatar,
    .evfs-mode-badge,
    .evfs-acomp-panel
) {
    border-color: var(--evapp-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-chip {
    background: var(--eventosapp-app-surface-soft) !important;
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-chip.is-valid {
    background: var(--evapp-success-soft) !important;
    border-color: rgba(22, 133, 91, .30) !important;
    color: var(--evapp-success) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-chip.is-warning {
    background: var(--evapp-warning-soft) !important;
    border-color: rgba(161, 98, 7, .30) !important;
    color: var(--evapp-warning) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-state {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-state-icon,
body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-avatar,
body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-mode-badge,
body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-acomp-panel {
    background: var(--evapp-primary-soft) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-avatar,
body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-mode-badge,
body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-acomp-label {
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-mode-badge.is-virtual,
body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-virtual-badge {
    background: var(--evapp-purple-soft) !important;
    border-color: rgba(109, 75, 195, .30) !important;
    color: var(--evapp-purple) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-virtual-badge small {
    color: inherit !important;
    opacity: .82;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    .evfs-virtual-badge.is-checked,
    .evfs-note.ok
) {
    background: var(--evapp-success-soft) !important;
    border-color: rgba(22, 133, 91, .30) !important;
    color: var(--evapp-success) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-checkin-status.is-checked {
    color: var(--evapp-success) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-checkin-status.is-disabled {
    color: var(--evapp-warning) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-note,
body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-inline-notice--error {
    background: var(--evapp-danger-soft) !important;
    border-color: rgba(197, 58, 58, .30) !important;
    color: var(--evapp-danger) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-inline-notice:not(.evfs-inline-notice--error) {
    background: var(--evapp-warning-soft) !important;
    border-color: rgba(161, 98, 7, .30) !important;
    color: var(--evapp-warning) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfs-app .evfs-row:hover {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    .evfs-btn-secondary,
    .evfs-print,
    .evfs-avatar,
    .evfs-mode-badge:not(.is-virtual),
    .evfs-acomp-label
) {
    color: #8fc0e8 !important;
}

/* ==========================================================
 * Check-In por Reconocimiento Facial
 * ========================================================== */
body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evfc-shell {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app :where(
    .evfc-event-context,
    .evfc-panel
) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app :where(
    .evfc-title,
    .evfc-event-name,
    .evfc-panel-title,
    .evapp-face-result-name
) {
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app :where(
    .evfc-subtitle,
    .evfc-event-kicker,
    .evfc-panel-desc,
    .evapp-face-status,
    .evapp-face-result-meta,
    .evfc-stat-label,
    .evfc-note
) {
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evfc-event-icon {
    background: var(--evapp-primary-soft) !important;
    color: var(--eventosapp-brand-blue) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app :where(
    .evfc-chip,
    .evfc-camera-state,
    .evfc-guide-item,
    .evfc-result-empty,
    .evfc-stat,
    .evfc-note
) {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evfc-chip.is-valid,
body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evfc-chip.is-cache.is-visible,
body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evfc-camera-state.is-live {
    background: var(--evapp-success-soft) !important;
    border-color: rgba(22, 133, 91, .30) !important;
    color: var(--evapp-success) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evfc-chip.is-warning {
    background: var(--evapp-warning-soft) !important;
    border-color: rgba(161, 98, 7, .30) !important;
    color: var(--evapp-warning) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evfc-camera-state.is-busy {
    background: var(--evapp-primary-soft) !important;
    border-color: rgba(54, 131, 197, .30) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app :where(
    .evfc-btn,
    .evapp-face-btn,
    .evapp-face-btn-cache
) {
    -webkit-appearance: none !important;
    appearance: none !important;
    text-decoration: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app :where(
    .evfc-btn-secondary,
    .evapp-face-btn-cache
) {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app :where(
    .evfc-btn-secondary,
    .evapp-face-btn-cache
):hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app :where(
    .evfc-btn-secondary,
    .evapp-face-btn-cache
):focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evapp-face-btn {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evapp-face-btn:hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evapp-face-btn:focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evapp-face-btn.is-live:not(:disabled) {
    background: var(--evapp-danger) !important;
    border-color: var(--evapp-danger) !important;
    color: #fff !important;
}

/* Fuera de fecha o mientras se cargan modelos: conserva disabled funcional,
 * pero impide el azul lavado/blanco del CSS histórico y reglas globales. */
body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evapp-face-btn:disabled {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-muted) !important;
    box-shadow: none !important;
    opacity: 1 !important;
}

/* El visor de cámara es una superficie técnica deliberadamente oscura. Solo se
 * sincroniza su borde; no se reemplaza su fondo, vídeo, canvas ni overlays. */
body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evfc-camera-wrap {
    border-color: var(--evapp-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evfc-progress {
    background: var(--eventosapp-app-surface-raised) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evfc-progress-bar {
    background: linear-gradient(90deg, var(--eventosapp-brand-blue), #5ca8e8) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evapp-face-result {
    background: var(--evapp-surface) !important;
    border-color: var(--evapp-border) !important;
    color: var(--evapp-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evapp-face-result.is-ok {
    background: var(--evapp-success-soft) !important;
    border-color: rgba(22, 133, 91, .30) !important;
    border-left-color: var(--evapp-success) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evapp-face-result.is-warn {
    background: var(--evapp-warning-soft) !important;
    border-color: rgba(161, 98, 7, .30) !important;
    border-left-color: var(--evapp-warning) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evapp-face-result.is-err {
    background: var(--evapp-danger-soft) !important;
    border-color: rgba(197, 58, 58, .30) !important;
    border-left-color: var(--evapp-danger) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app .evfc-stat-number {
    color: var(--eventosapp-brand-blue-dark) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app :where(
    .evfc-btn-secondary,
    .evapp-face-btn-cache,
    .evfc-stat-number,
    .evfc-camera-state.is-busy
) {
    color: #8fc0e8 !important;
}

/* Foco accesible y aislado del Elementor Kit. */
body.eventosapp-app-page #eventosapp-app-root .evfs-app :where(
    button,
    a,
    input,
    select
):focus-visible,
body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app :where(
    button,
    a
):focus-visible {
    outline: 3px solid var(--eventosapp-brand-focus) !important;
    outline-offset: 2px !important;
}
</style>
        <?php
    }
}
add_action( 'wp_footer', 'eventosapp_search_face_visual_compat_print_styles', 1004 );
