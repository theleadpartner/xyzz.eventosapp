<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Compatibilidad visual final para Checklist de Evento, Edición de asistentes
 * y Control de Consumibles.
 *
 * Estos módulos conservan CSS inline histórico porque su lógica operativa ya
 * está consolidada. Esta capa se imprime después del branding y del aislamiento
 * general de Elementor para remapear únicamente presentación: identidad
 * corporativa, superficies, controles, estados y contraste Light/Dark.
 *
 * No modifica permisos, nonces, AJAX, persistencia, búsquedas, edición de
 * tickets, checklist, uploads, cámara/QR, inventario, ledger, reversos,
 * exportaciones ni la inyección segura de la landing pública de Consumibles.
 */

if ( ! function_exists( 'eventosapp_checklist_edit_consumables_visual_compat_is_active' ) ) {
    function eventosapp_checklist_edit_consumables_visual_compat_is_active() {
        if ( function_exists( 'eventosapp_elementor_compat_is_active' ) ) {
            return eventosapp_elementor_compat_is_active();
        }

        return function_exists( 'eventosapp_branding_is_managed_page' )
            && eventosapp_branding_is_managed_page();
    }
}

if ( ! function_exists( 'eventosapp_checklist_edit_consumables_visual_compat_print_styles' ) ) {
    function eventosapp_checklist_edit_consumables_visual_compat_print_styles() {
        if ( ! eventosapp_checklist_edit_consumables_visual_compat_is_active() ) return;

        $icon_color = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'icon_color' )
            : '';
        $icon_white = function_exists( 'eventosapp_brand_asset_url' )
            ? eventosapp_brand_asset_url( 'icon_white' )
            : '';
        ?>
<style id="eventosapp-checklist-edit-consumables-visual-compat">
/* ==========================================================
 * EventosApp 1.5.0-rc.13 — Checklist + Edición + Consumibles
 * Marca + Dark Mode + aislamiento Elementor/tema
 * ========================================================== */

body.eventosapp-app-page #eventosapp-app-root :where(
    .evchk-app,
    .evfe-app,
    .evapp-cons-front-shell,
    .evapp-cons-editor,
    .evapp-cscan,
    .evapp-tx-shell,
    .evapp-cons-tabs-wrap
) {
    --evapp-primary: var(--eventosapp-brand-blue) !important;
    --evapp-primary-dark: var(--eventosapp-brand-blue-dark) !important;
    --evapp-primary-soft: var(--eventosapp-brand-blue-soft) !important;
    --evapp-app-bg: var(--eventosapp-app-surface-soft) !important;
    --evapp-surface: var(--eventosapp-app-surface) !important;
    --evapp-border: var(--eventosapp-app-border) !important;
    --evapp-text: var(--eventosapp-app-text) !important;
    --evapp-muted: var(--eventosapp-app-muted) !important;

    --evc-primary: var(--eventosapp-brand-blue) !important;
    --evc-primary-dark: var(--eventosapp-brand-blue-dark) !important;
    --evc-dark: var(--eventosapp-brand-blue-dark) !important;
    --evc-soft: var(--eventosapp-brand-blue-soft) !important;
    --evc-bg: var(--eventosapp-app-surface-soft) !important;
    --evc-surface: var(--eventosapp-app-surface) !important;
    --evc-border: var(--eventosapp-app-border) !important;
    --evc-text: var(--eventosapp-app-text) !important;
    --evc-muted: var(--eventosapp-app-muted) !important;

    --p: var(--eventosapp-brand-blue) !important;
    --pd: var(--eventosapp-brand-blue-dark) !important;
    --bg: var(--eventosapp-app-surface-soft) !important;
    --b: var(--eventosapp-app-border) !important;
    --t: var(--eventosapp-app-text) !important;
    --m: var(--eventosapp-app-muted) !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    .evchk-app,
    .evfe-app,
    .evapp-cons-front-shell,
    .evapp-cons-editor,
    .evapp-cscan,
    .evapp-tx-shell,
    .evapp-cons-tabs-wrap
) {
    --evapp-primary-soft: rgba(54, 131, 197, .16) !important;
    --evapp-success: #62d4a5 !important;
    --evapp-success-soft: rgba(22, 133, 91, .20) !important;
    --evapp-warning: #f1c861 !important;
    --evapp-warning-soft: rgba(161, 98, 7, .22) !important;
    --evapp-danger: #f08d8d !important;
    --evapp-danger-soft: rgba(197, 58, 58, .20) !important;
    --evapp-purple: #b8a1f0 !important;
    --evapp-purple-soft: rgba(109, 75, 195, .22) !important;

    --evc-soft: rgba(54, 131, 197, .16) !important;
    --evc-bg: var(--eventosapp-app-surface-soft) !important;
    --evc-surface: var(--eventosapp-app-surface) !important;
    --evc-border: var(--eventosapp-app-border) !important;
    --evc-text: var(--eventosapp-app-text) !important;
    --evc-muted: var(--eventosapp-app-muted) !important;
    --evc-success: #62d4a5 !important;
    --evc-danger: #f08d8d !important;

    --bg: var(--eventosapp-app-surface-soft) !important;
    --b: var(--eventosapp-app-border) !important;
    --t: var(--eventosapp-app-text) !important;
    --m: var(--eventosapp-app-muted) !important;
}

/* Firma corporativa de los módulos. */
body.eventosapp-app-page #eventosapp-app-root :where(
    .evchk-eyebrow,
    .evfe-eyebrow
) {
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root :where(
    .evchk-eyebrow,
    .evfe-eyebrow
)::before {
    content: "";
    display: inline-block;
    width: 16px;
    height: 18px;
    flex: 0 0 16px;
    margin: 0 !important;
    background: url("<?php echo esc_url( $icon_color ); ?>") center / contain no-repeat;
}

body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-cons-front-head h2,
    .evapp-cscan-head h2,
    .evapp-tx-head h2,
    .evapp-tx-staff > h2
) {
    display: flex !important;
    align-items: center !important;
    gap: 9px !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-cons-front-head h2,
    .evapp-cscan-head h2,
    .evapp-tx-head h2,
    .evapp-tx-staff > h2
)::before {
    content: "";
    display: inline-block;
    width: 20px;
    height: 23px;
    flex: 0 0 20px;
    background: url("<?php echo esc_url( $icon_color ); ?>") center / contain no-repeat;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    .evchk-eyebrow,
    .evfe-eyebrow
) {
    color: #7bb1df !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    .evchk-eyebrow,
    .evfe-eyebrow,
    .evapp-cons-front-head h2,
    .evapp-cscan-head h2,
    .evapp-tx-head h2,
    .evapp-tx-staff > h2
)::before {
    background-image: url("<?php echo esc_url( $icon_white ); ?>") !important;
}

/* ==========================================================
 * Checklist de Evento
 * ========================================================== */
body.eventosapp-app-page #eventosapp-app-root .evchk-app .evchk-shell {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evchk-app :where(
    .evchk-event-context,
    .evchk-summary-card,
    .evchk-deadline-card,
    .evchk-task,
    .evchk-submit-card,
    .evchk-empty-card
) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evchk-app :where(
    .evchk-main-title,
    .evchk-event-name,
    .evchk-summary-title,
    .evchk-deadline-date,
    .evchk-task-title,
    .evchk-label,
    .evchk-empty-title
) {
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evchk-app :where(
    .evchk-subtitle,
    .evchk-event-kicker,
    .evchk-summary-copy,
    .evchk-task-desc,
    .evchk-empty-copy,
    .evchk-empty-note
) {
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evchk-app :where(
    .evchk-option,
    .evchk-file-wrap,
    .evchk-empty-note
) {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evchk-app .evchk-option:hover {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evchk-app .evchk-option:has(input:checked) {
    background: var(--evapp-primary-soft) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evchk-app :where(
    .evchk-input,
    .evchk-textarea,
    .evchk-file
) {
    background: var(--eventosapp-app-input) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evchk-app :where(
    .evchk-input,
    .evchk-textarea
)::placeholder {
    color: var(--eventosapp-app-muted) !important;
    opacity: 1 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evchk-app :where(
    .evchk-input,
    .evchk-textarea
):disabled {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evchk-app .evchk-btn-secondary {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evchk-app .evchk-btn-secondary:hover,
body.eventosapp-app-page #eventosapp-app-root .evchk-app .evchk-btn-secondary:focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evchk-app .evchk-btn-primary {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evchk-app .evchk-btn-primary:hover,
body.eventosapp-app-page #eventosapp-app-root .evchk-app .evchk-btn-primary:focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evchk-app :where(
    .evchk-event-icon,
    .evchk-task-icon,
    .evchk-empty-icon
) {
    background: var(--evapp-primary-soft) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

/* ==========================================================
 * Edición de asistentes
 * ========================================================== */
body.eventosapp-app-page #eventosapp-app-root .evfe-app .evfe-shell {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app :where(
    .evfe-event-context,
    .evfe-search-card,
    .evfe-form-section,
    .evfe-editor-head,
    .evfe-submit-bar,
    .evfe-row,
    .evfe-state
) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app .evfe-row:hover {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app :where(
    .evfe-main-title,
    .evfe-event-name,
    .evfe-card-title,
    .evfe-person-name,
    .evfe-section-title
) {
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app :where(
    .evfe-subtitle,
    .evfe-event-kicker,
    .evfe-card-desc,
    .evfe-result-count,
    .evfe-person-meta,
    .evfe-help,
    .evfe-hint
) {
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app :where(
    .evfe-select,
    .evfe-input,
    .evfe-control
) {
    background-color: var(--eventosapp-app-input) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: none;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app .evfe-select {
    -webkit-appearance: auto !important;
    appearance: auto !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app .evfe-select option {
    background: var(--eventosapp-app-input) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app input.evfe-input[type="search"] {
    -webkit-appearance: none !important;
    appearance: none !important;
    padding: 10px 46px 10px 46px !important;
    text-indent: 0 !important;
    background-image: none !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app :where(
    .evfe-input,
    .evfe-control
)::placeholder {
    color: var(--eventosapp-app-muted) !important;
    opacity: 1 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app :where(
    .evfe-search-icon,
    .evfe-clear
) {
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app .evfe-clear:hover,
body.eventosapp-app-page #eventosapp-app-root .evfe-app .evfe-clear:focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app :where(
    .evfe-btn-secondary,
    .evfe-btn-ghost
) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app :where(
    .evfe-btn-secondary,
    .evfe-btn-ghost
):hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evfe-app :where(
    .evfe-btn-secondary,
    .evfe-btn-ghost
):focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app .evfe-btn-primary {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app .evfe-btn-primary:hover,
body.eventosapp-app-page #eventosapp-app-root .evfe-app .evfe-btn-primary:focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app :where(
    .evfe-chip,
    .evfe-avatar,
    .evfe-state-icon
) {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evfe-app .evfe-avatar {
    color: var(--eventosapp-brand-blue-dark) !important;
}

/* ==========================================================
 * Consumibles — pestañas comunes
 * ========================================================== */
body.eventosapp-app-page #eventosapp-app-root .evapp-cons-tabs-wrap .evapp-cons-tabs {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-tabs-wrap .evapp-cons-tab {
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-tabs-wrap .evapp-cons-tab:hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-cons-tabs-wrap .evapp-cons-tab:focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-tabs-wrap .evapp-cons-tab.is-active {
    background: var(--eventosapp-app-surface) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}

/* ==========================================================
 * Consumibles — configuración / editor
 * ========================================================== */
body.eventosapp-app-page #eventosapp-app-root .evapp-cons-front-shell {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-front-shell :where(
    .evapp-cons-back,
    .evapp-cons-front-event
) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-front-shell .evapp-cons-back:hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-cons-front-shell .evapp-cons-back:focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor {
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor :where(
    .evapp-cons-intro,
    .evapp-cons-rule,
    .evapp-cons-item,
    .evapp-cons-add-rule,
    .evapp-cons-empty
) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor .evapp-cons-intro {
    background: var(--eventosapp-app-surface-soft) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor .evapp-cons-enable {
    background: var(--evc-soft) !important;
    border-color: rgba(54, 131, 197, .34) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor .evapp-cons-enable small {
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor .evapp-cons-item {
    background: var(--eventosapp-app-surface-soft) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor :where(
    .evapp-cons-field input,
    .evapp-cons-field select,
    .evapp-cons-field textarea
) {
    background: var(--eventosapp-app-input) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor .evapp-cons-field select option {
    background: var(--eventosapp-app-input) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor :where(
    .evapp-cons-field input,
    .evapp-cons-field textarea
)::placeholder {
    color: var(--eventosapp-app-muted) !important;
    opacity: 1 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor :where(
    .evapp-cons-icon-btn,
    .evapp-cons-secondary-btn
) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor :where(
    .evapp-cons-icon-btn,
    .evapp-cons-secondary-btn
):hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor :where(
    .evapp-cons-icon-btn,
    .evapp-cons-secondary-btn
):focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor .evapp-cons-icon-btn.is-danger {
    color: var(--evc-danger) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor :where(
    .evapp-cons-primary-btn,
    .evapp-cons-save
) {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor :where(
    .evapp-cons-primary-btn,
    .evapp-cons-save
):hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor :where(
    .evapp-cons-primary-btn,
    .evapp-cons-save
):focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor .evapp-cons-chip {
    background: var(--evc-soft) !important;
    border-color: rgba(54, 131, 197, .34) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

/* El botón Guardar está fuera de .evapp-cons-editor en el manager. */
body.eventosapp-app-page #eventosapp-app-root .evapp-cons-front-shell .evapp-cons-save {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cons-front-shell .evapp-cons-save:hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-cons-front-shell .evapp-cons-save:focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
}

/* ==========================================================
 * Consumibles — lector QR de Staff
 * ========================================================== */
body.eventosapp-app-page #eventosapp-app-root .evapp-cscan {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cscan :where(
    .evapp-cscan-back,
    .evapp-cscan-event,
    .evapp-cscan-card,
    .evapp-cscan-item,
    .evapp-cscan-result,
    .evapp-cscan-inventory-item,
    .evapp-cscan-notice
) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cscan .evapp-cscan-back:hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-cscan .evapp-cscan-back:focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cscan .evapp-cscan-item:has([data-item-check]:checked) {
    background: var(--evc-soft) !important;
    border-color: var(--eventosapp-brand-blue) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cscan .evapp-cscan-qty {
    background: var(--eventosapp-app-input) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cscan .evapp-cscan-qty:disabled {
    background: var(--eventosapp-app-surface-raised) !important;
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cscan :where(
    .evapp-cscan-selection-summary,
    .evapp-cscan-recent-row,
    .evapp-cscan-line
) {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cscan .evapp-cscan-btn:not(.is-stop) {
    background: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cscan .evapp-cscan-btn:not(.is-stop):hover:not(:disabled),
body.eventosapp-app-page #eventosapp-app-root .evapp-cscan .evapp-cscan-btn:not(.is-stop):focus-visible:not(:disabled) {
    background: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cscan .evapp-cscan-btn:disabled {
    background: var(--eventosapp-app-surface-raised) !important;
    border: 1px solid var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-muted) !important;
    opacity: 1 !important;
}

/* El visor conserva intencionalmente su fondo técnico oscuro. */
body.eventosapp-app-page #eventosapp-app-root .evapp-cscan .evapp-cscan-video-wrap {
    border: 1px solid var(--eventosapp-app-border) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-cscan .evapp-cscan-event-icon {
    background: var(--evc-soft) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

/* ==========================================================
 * Consumibles — transacciones Administrador/Organizador/Staff
 * ========================================================== */
body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell {
    background: var(--eventosapp-app-surface-soft) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
    box-shadow: var(--eventosapp-app-shadow) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell :where(
    .evapp-tx-summary-card,
    .evapp-tx-filters,
    .evapp-tx-table-wrap,
    .evapp-tx-card,
    .evapp-tx-empty
) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell :where(
    .evapp-tx-head h2,
    .evapp-tx-summary-card span,
    .evapp-tx-card h4
) {
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell :where(
    .evapp-tx-head p,
    .evapp-tx-muted,
    .evapp-tx-summary-card small,
    .evapp-tx-summary-card em,
    .lead
) {
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-summary-card strong {
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell :where(
    .evapp-tx-field input,
    .evapp-tx-field select
) {
    background: var(--eventosapp-app-input) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-field select option {
    background: var(--eventosapp-app-input) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-field input::placeholder {
    color: var(--eventosapp-app-muted) !important;
    opacity: 1 !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-table th {
    background: var(--eventosapp-app-surface-raised) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-table td {
    background: transparent !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-table tr.is-pending td,
body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-card.is-pending {
    background: var(--evapp-warning-soft, rgba(161, 98, 7, .14)) !important;
    border-color: rgba(161, 98, 7, .34) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-btn:not(.is-danger) {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-btn:not(.is-danger):hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-btn:not(.is-danger):focus-visible {
    background: var(--eventosapp-brand-blue-dark) !important;
    border-color: var(--eventosapp-brand-blue-dark) !important;
    color: #fff !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-btn.is-secondary {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-btn.is-secondary:hover,
body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-btn.is-secondary:focus-visible {
    background: var(--eventosapp-app-surface-raised) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-btn.is-danger {
    background: var(--eventosapp-app-surface) !important;
    border-color: rgba(197, 58, 58, .42) !important;
    color: var(--evapp-danger, #b42318) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell :where(
    .evapp-tx-card-item,
    .evapp-tx-badge:not(.is-pending):not(.is-reversed)
) {
    background: var(--evapp-primary-soft) !important;
    color: var(--eventosapp-brand-blue-dark) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-badge.is-reversed {
    background: var(--eventosapp-app-surface-raised) !important;
    color: var(--eventosapp-app-muted) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-pages :where(a, span) {
    background: var(--eventosapp-app-surface) !important;
    border-color: var(--eventosapp-app-border) !important;
    color: var(--eventosapp-app-text) !important;
}

body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-pages .current {
    background: var(--eventosapp-brand-blue) !important;
    border-color: var(--eventosapp-brand-blue) !important;
    color: #fff !important;
}

/* Estados semánticos en Dark Mode: conservan significado sin volver a blanco. */
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evchk-app :where(
    .evchk-notice,
    .evchk-status-pill,
    .evchk-field-error
),
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evfe-app :where(
    .evfe-notice,
    .evfe-chip
),
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    .evapp-cons-notice,
    .evapp-cscan-result,
    .evapp-cscan-notice,
    .evapp-tx-message,
    .evapp-tx-badge
) {
    color: inherit;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-cscan .evapp-cscan-result.is-success,
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-message:not(.is-error),
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-cons-notice:not(.is-error) {
    background: rgba(22, 133, 91, .20) !important;
    border-color: rgba(98, 212, 165, .32) !important;
    color: #8be1bd !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-cscan .evapp-cscan-result.is-error,
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-cscan .evapp-cscan-notice.is-error,
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell .evapp-tx-message.is-error,
html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root .evapp-cons-notice.is-error {
    background: rgba(197, 58, 58, .20) !important;
    border-color: rgba(240, 141, 141, .34) !important;
    color: #f4aaaa !important;
}

html[data-evapp-theme="dark"] body.eventosapp-app-page #eventosapp-app-root :where(
    .evchk-app,
    .evfe-app,
    .evapp-cons-front-shell,
    .evapp-cons-editor,
    .evapp-cscan,
    .evapp-tx-shell,
    .evapp-cons-tabs-wrap
) :where(a, button, input, select, textarea):focus-visible {
    outline-color: var(--eventosapp-brand-blue) !important;
}

@media (max-width: 520px) {
    body.eventosapp-app-page #eventosapp-app-root :where(
        .evapp-cons-front-head h2,
        .evapp-cscan-head h2,
        .evapp-tx-head h2,
        .evapp-tx-staff > h2
    )::before {
        width: 18px;
        height: 21px;
        flex-basis: 18px;
    }
}
</style>
        <?php
    }
}
add_action( 'wp_footer', 'eventosapp_checklist_edit_consumables_visual_compat_print_styles', 1005 );
