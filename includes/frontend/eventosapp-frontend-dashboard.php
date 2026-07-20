<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Iconos inline para el dashboard.
 * Todos usan currentColor para que Elementor pueda personalizarlos sin duplicar SVG.
 */
if ( ! function_exists('eventosapp_dashboard_icon') ) {
	function eventosapp_dashboard_icon( $name ) {
		switch ( $name ) {
			case 'metrics':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="10" width="4" height="10" rx="1"/><rect x="10" y="4" width="4" height="16" rx="1"/><rect x="17" y="7" width="4" height="13" rx="1"/></svg>';

			case 'flow-metrics':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v9H4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M7 18h10M9 21h6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M7 11l2-2 2 2 4-5 2 3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="18" cy="18" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>';

			case 'building-checkin':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21V5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v16M2 21h20M8 7h2M13 7h1M8 11h2M13 11h1M8 15h2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="m15 16 2 2 4-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

			case 'circle-user':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="9" r="3" fill="none" stroke="currentColor" stroke-width="2"/><path d="M6.5 17c1.2-2.4 3.6-3.5 5.5-3.5S16.8 14.6 18 17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

			case 'qrcode':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 14h2v2h-2zM16 18h2v2h-2zM20 18h2v2h-2z"/></svg>';

			case 'calendar-check':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M7 3v4M17 3v4M3 9h18M9 15l2 2 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

			case 'id-badge':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="5" width="12" height="16" rx="2"/><rect x="10" y="2" width="4" height="3" rx="1"/><circle cx="12" cy="11" r="2.5"/><path d="M9 16h6a.8.8 0 0 1 .8.8V18H8.2v-1.2A.8.8 0 0 1 9 16Z"/></svg>';

			case 'self-checkin':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="3" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="9" r="2.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 15c.8-2 2.2-3 4-3s3.2 1 4 3M8 18h8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

			case 'check-double':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 13l3 3 5-6M12 13l3 3 6-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

			case 'checklist':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 8h8M8 12h8M8 16h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M5 5l2 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

			case 'ticket':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 9V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><rect x="9" y="9" width="6" height="6" rx="1" fill="none" stroke="currentColor" stroke-width="2"/></svg>';

			case 'trophy':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v2a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5V5Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M9 12v2a3 3 0 0 0 6 0v-2M8 21h8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

			case 'shield-check':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 4 6v6c0 5.5 3.8 10.7 8 12 4.2-1.3 8-6.5 8-12V6l-8-4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

			case 'face-scan':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8V6a2 2 0 0 1 2-2h2M3 16v2a2 2 0 0 0 2 2h2M21 8V6a2 2 0 0 0-2-2h-2M21 16v2a2 2 0 0 1-2 2h-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="10" r="3" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 18c.8-2.3 2.2-3.5 4-3.5s3.2 1.2 4 3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

			case 'support-assistance':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M8 9h8M8 13h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="m16 15 1.5 1.5L21 13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

			case 'support-metrics':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="8" r="3" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17" cy="7" r="2.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M3 20c.8-3.2 2.5-5 5-5s4.2 1.8 5 5M13 19c.5-2.3 1.8-3.7 4-3.7 1.8 0 3.1 1 4 3.7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

			case 'expositor':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9h16l-1-4H5L4 9Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M5 9v10h14V9M8 19v-6h4v6M14 13h3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 9c.4 1.4 1.4 2 2.5 2S8.6 10.4 9 9c.4 1.4 1.4 2 2.5 2s2.1-.6 2.5-2c.4 1.4 1.4 2 2.5 2S18.6 10.4 19 9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

			case 'expositor-gestion':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9h16l-1-4H5L4 9Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M5 9v10h14V9M8 19v-5h4v5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="17" cy="16" r="3" fill="none" stroke="currentColor" stroke-width="2"/><path d="m16 16 1 1 2-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

			case 'live-raffle':
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v5a7 7 0 0 1-14 0V4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M8 20h8M12 16v4M5 7H2v1a4 4 0 0 0 4 4M19 7h3v1a4 4 0 0 1-4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="m12 6 .8 1.7 1.9.2-1.4 1.3.4 1.9-1.7-.9-1.7.9.4-1.9-1.4-1.3 1.9-.2L12 6Z"/></svg>';

			default:
				return '<svg class="evapp-ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg>';
		}
	}
}

/**
 * CSS del dashboard. Se imprime una sola vez por carga, incluso cuando hay
 * más de un widget en la misma página.
 */
if ( ! function_exists('eventosapp_print_dashboard_css') ) {
	function eventosapp_print_dashboard_css() {
		static $printed = false;
		if ( $printed ) return;
		$printed = true;
		?>
		<style id="eventosapp-dashboard-styles">
.evapp-dashboard{
  --evapp-primary:#3279bd;
  --evapp-primary-dark:#255f96;
  --evapp-primary-soft:#eaf4ff;
  --evapp-app-bg:#f5f8fc;
  --evapp-surface:#ffffff;
  --evapp-border:#dfe7f1;
  --evapp-text:#182230;
  --evapp-muted:#64748b;
  --evapp-success:#15803d;
  --evapp-danger:#b42318;
  --evapp-card-radius:18px;
  --evapp-shell-radius:26px;
  --evapp-grid-gap:16px;
  --evapp-section-gap:30px;
  --evapp-icon-size:28px;
  width:100%;
  color:var(--evapp-text);
  font-family:inherit;
  line-height:1.45;
}
.evapp-dashboard,
.evapp-dashboard *{box-sizing:border-box}
.evapp-dashboard a{text-decoration:none}
.evapp-dashboard .screen-reader-text{
  position:absolute!important;
  width:1px!important;
  height:1px!important;
  padding:0!important;
  margin:-1px!important;
  overflow:hidden!important;
  clip:rect(0,0,0,0)!important;
  white-space:nowrap!important;
  border:0!important;
}
.evapp-dashboard-shell{
  width:100%;
  padding:clamp(18px,3vw,36px);
  background:var(--evapp-app-bg);
  border:1px solid var(--evapp-border);
  border-radius:var(--evapp-shell-radius);
  box-shadow:0 18px 50px rgba(31,52,73,.08);
}
.evapp-dashboard-header{
  display:flex;
  align-items:flex-end;
  justify-content:space-between;
  gap:24px;
  margin-bottom:22px;
}
.evapp-dashboard-heading{min-width:0}
.evapp-dashboard-eyebrow{
  margin:0 0 6px;
  color:var(--evapp-primary);
  font-size:12px;
  font-weight:800;
  letter-spacing:.11em;
  text-transform:uppercase;
}
.evapp-dashboard-main-title{
  margin:0;
  color:var(--evapp-text);
  font-size:clamp(27px,4vw,42px);
  font-weight:850;
  line-height:1.08;
  letter-spacing:-.035em;
}
.evapp-dashboard-subtitle{
  max-width:760px;
  margin:10px 0 0;
  color:var(--evapp-muted);
  font-size:15px;
}
.evapp-module-total{
  flex:0 0 auto;
  display:inline-flex;
  align-items:center;
  gap:8px;
  min-height:36px;
  padding:8px 12px;
  color:var(--evapp-primary-dark);
  background:var(--evapp-primary-soft);
  border:1px solid color-mix(in srgb, var(--evapp-primary) 24%, transparent);
  border-radius:999px;
  font-size:13px;
  font-weight:750;
  white-space:nowrap;
}
.evapp-module-total-dot{width:8px;height:8px;border-radius:50%;background:var(--evapp-primary)}
.evapp-notice{
  display:flex;
  align-items:flex-start;
  gap:10px;
  margin:0 0 16px;
  padding:13px 15px;
  background:var(--evapp-surface);
  border:1px solid var(--evapp-border);
  border-left:4px solid var(--evapp-primary);
  border-radius:14px;
  color:var(--evapp-text);
  font-size:14px;
}
.evapp-notice.is-error{border-left-color:var(--evapp-danger)}
.evapp-notice.is-success{border-left-color:var(--evapp-success)}
.evapp-event-context{
  display:grid;
  grid-template-columns:auto minmax(0,1fr) auto;
  align-items:center;
  gap:14px;
  margin-bottom:20px;
  padding:14px;
  background:var(--evapp-surface);
  border:1px solid var(--evapp-border);
  border-radius:18px;
  box-shadow:0 8px 24px rgba(31,52,73,.05);
}
.evapp-event-context-icon,
.evapp-selector-icon{
  display:flex;
  align-items:center;
  justify-content:center;
  width:46px;
  height:46px;
  color:var(--evapp-primary);
  background:var(--evapp-primary-soft);
  border-radius:14px;
}
.evapp-event-context-icon svg,
.evapp-selector-icon svg{width:24px;height:24px;fill:none;stroke:currentColor;stroke-width:2}
.evapp-event-context-copy{min-width:0}
.evapp-event-label{
  display:block;
  margin-bottom:2px;
  color:var(--evapp-muted);
  font-size:12px;
  font-weight:700;
  letter-spacing:.04em;
  text-transform:uppercase;
}
.evapp-event-name{
  display:block;
  overflow:hidden;
  color:var(--evapp-text);
  font-size:16px;
  font-weight:800;
  text-overflow:ellipsis;
  white-space:nowrap;
}
.evapp-change-event,
.evapp-primary-button{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  min-height:42px;
  padding:10px 16px;
  border:1px solid var(--evapp-primary);
  border-radius:12px;
  background:var(--evapp-primary);
  color:#fff!important;
  font-size:14px;
  font-weight:750;
  line-height:1;
  cursor:pointer;
  transition:transform .18s ease,background-color .18s ease,box-shadow .18s ease;
}
.evapp-change-event:hover,
.evapp-primary-button:hover{background:var(--evapp-primary-dark);transform:translateY(-1px);box-shadow:0 8px 18px rgba(38,100,157,.22)}
.evapp-primary-button:disabled{opacity:.45;cursor:not-allowed;transform:none;box-shadow:none}
.evapp-dashboard-toolbar{
  display:flex;
  align-items:center;
  gap:12px;
  margin-bottom:24px;
}
.evapp-module-search-wrap{position:relative;flex:1 1 420px;max-width:620px}
.evapp-module-search-icon{
  position:absolute;
  top:50%;
  left:15px;
  width:20px;
  height:20px;
  color:var(--evapp-muted);
  transform:translateY(-50%);
  pointer-events:none;
}
.evapp-module-search{
  width:100%;
  min-height:48px;
  margin:0;
  padding:11px 46px 11px 45px;
  color:var(--evapp-text);
  background:var(--evapp-surface);
  border:1px solid var(--evapp-border);
  border-radius:14px;
  box-shadow:none;
  font:inherit;
  font-size:15px;
  outline:none;
  transition:border-color .18s ease,box-shadow .18s ease;
}
.evapp-module-search:focus{border-color:var(--evapp-primary);box-shadow:0 0 0 4px color-mix(in srgb,var(--evapp-primary) 15%,transparent)}
.evapp-module-search-clear{
  position:absolute;
  top:50%;
  right:9px;
  display:none;
  width:32px;
  height:32px;
  padding:0;
  border:0;
  border-radius:9px;
  color:var(--evapp-muted);
  background:transparent;
  font-size:20px;
  cursor:pointer;
  transform:translateY(-50%);
}
.evapp-module-search-clear:hover{color:var(--evapp-text);background:var(--evapp-primary-soft)}
.evapp-module-search-clear.is-visible{display:block}
.evapp-search-result-count{color:var(--evapp-muted);font-size:13px;font-weight:650;white-space:nowrap}
.evapp-sections{display:grid;gap:var(--evapp-section-gap)}
.evapp-section{min-width:0}
.evapp-section[hidden],.evapp-card[hidden]{display:none!important}
.evapp-section-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
  margin:0 0 12px;
}
.evapp-section-title{
  margin:0;
  color:var(--evapp-text);
  font-size:17px;
  font-weight:800;
  letter-spacing:-.01em;
}
.evapp-section-count{color:var(--evapp-muted);font-size:12px;font-weight:700}
.evapp-grid{
  display:grid;
  grid-template-columns:repeat(var(--evapp-section-columns,4),minmax(0,1fr));
  gap:var(--evapp-grid-gap);
  width:100%;
  margin:0;
}
.evapp-grid.is-single{max-width:560px}
.evapp-grid.is-double{max-width:1136px}
.evapp-card{
  position:relative;
  display:grid;
  grid-template-columns:auto minmax(0,1fr) auto;
  align-items:center;
  gap:14px;
  min-width:0;
  min-height:126px;
  padding:18px;
  overflow:hidden;
  color:var(--evapp-text)!important;
  background:var(--evapp-surface);
  border:1px solid var(--evapp-border);
  border-radius:var(--evapp-card-radius);
  box-shadow:0 8px 24px rgba(31,52,73,.055);
  text-align:left;
  transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease,background-color .18s ease;
}
.evapp-card:link,.evapp-card:visited{color:var(--evapp-text)!important}
.evapp-card:hover{
  color:var(--evapp-text)!important;
  background:#fff;
  border-color:color-mix(in srgb,var(--evapp-primary) 48%,var(--evapp-border));
  box-shadow:0 15px 34px rgba(31,73,112,.13);
  transform:translateY(-3px);
}
.evapp-card:focus-visible{outline:3px solid color-mix(in srgb,var(--evapp-primary) 32%,transparent);outline-offset:3px}
.evapp-card-icon{
  display:flex;
  align-items:center;
  justify-content:center;
  width:54px;
  height:54px;
  flex:0 0 54px;
  color:#fff;
  background:linear-gradient(145deg,var(--evapp-primary),var(--evapp-primary-dark));
  border-radius:16px;
  box-shadow:0 8px 18px rgba(47,115,181,.22);
  transition:transform .18s ease;
}
.evapp-card:hover .evapp-card-icon{transform:scale(1.04)}
.evapp-ico{display:block;width:var(--evapp-icon-size);height:var(--evapp-icon-size);fill:currentColor}
.evapp-card-copy{min-width:0}
.evapp-title{
  display:block;
  margin:0;
  color:var(--evapp-text);
  font-size:16px;
  font-weight:820;
  line-height:1.24;
  letter-spacing:-.012em;
}
.evapp-card-description{
  display:block;
  margin-top:6px;
  color:var(--evapp-muted);
  font-size:13px;
  line-height:1.38;
}
.evapp-card-arrow{
  display:flex;
  align-items:center;
  justify-content:center;
  width:32px;
  height:32px;
  color:var(--evapp-primary);
  background:var(--evapp-primary-soft);
  border-radius:10px;
  transition:transform .18s ease,background-color .18s ease,color .18s ease;
}
.evapp-card-arrow svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2.2}
.evapp-card:hover .evapp-card-arrow{color:#fff;background:var(--evapp-primary);transform:translateX(2px)}
.evapp-no-search-results{
  display:none;
  padding:32px 20px;
  color:var(--evapp-muted);
  background:var(--evapp-surface);
  border:1px dashed var(--evapp-border);
  border-radius:18px;
  text-align:center;
}
.evapp-no-search-results.is-visible{display:block}
.evapp-selector-card{
  display:grid;
  grid-template-columns:auto minmax(0,1fr);
  gap:16px;
  padding:clamp(18px,3vw,28px);
  background:var(--evapp-surface);
  border:1px solid var(--evapp-border);
  border-radius:20px;
  box-shadow:0 10px 28px rgba(31,52,73,.06);
}
.evapp-selector-content{min-width:0}
.evapp-selector-title{margin:0;color:var(--evapp-text);font-size:20px;font-weight:820}
.evapp-selector-help{margin:6px 0 18px;color:var(--evapp-muted);font-size:14px}
.evapp-selector-form{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center}
.evapp-event-select{
  width:100%;
  min-height:46px;
  margin:0;
  padding:10px 38px 10px 13px;
  color:var(--evapp-text);
  background-color:#fff;
  border:1px solid var(--evapp-border);
  border-radius:12px;
  font:inherit;
  font-size:14px;
  outline:none;
}
.evapp-event-select:focus{border-color:var(--evapp-primary);box-shadow:0 0 0 4px color-mix(in srgb,var(--evapp-primary) 14%,transparent)}
.evapp-selector-footnote{margin:12px 0 0;color:var(--evapp-muted);font-size:12px}
.evapp-empty-state{padding:28px;color:var(--evapp-muted);background:var(--evapp-surface);border:1px dashed var(--evapp-border);border-radius:18px;text-align:center}
@media (max-width:1099px){
  .evapp-grid{grid-template-columns:repeat(var(--evapp-section-columns-tablet,2),minmax(0,1fr))}
  .evapp-dashboard-header{align-items:flex-start}
}
@media (max-width:767px){
  .evapp-dashboard-shell{padding:16px;border-radius:20px}
  .evapp-dashboard-header{display:block;margin-bottom:18px}
  .evapp-module-total{margin-top:13px}
  .evapp-event-context{grid-template-columns:auto minmax(0,1fr)}
  .evapp-change-event{grid-column:1/-1;width:100%}
  .evapp-dashboard-toolbar{display:block}
  .evapp-module-search-wrap{max-width:none}
  .evapp-search-result-count{display:block;margin-top:8px}
  .evapp-grid{grid-template-columns:repeat(var(--evapp-section-columns-mobile,1),minmax(0,1fr))}
  .evapp-grid.is-single,.evapp-grid.is-double{max-width:none}
  .evapp-card{min-height:108px;padding:14px;gap:12px}
  .evapp-card-icon{width:48px;height:48px;flex-basis:48px;border-radius:14px}
  .evapp-title{font-size:15px}
  .evapp-card-description{font-size:12px}
  .evapp-selector-card{grid-template-columns:1fr}
  .evapp-selector-icon{display:none}
  .evapp-selector-form{grid-template-columns:1fr}
  .evapp-primary-button{width:100%}
}
@media (max-width:430px){
  .evapp-card{grid-template-columns:auto minmax(0,1fr)}
  .evapp-card-arrow{display:none}
  .evapp-card-description{margin-top:4px}
}
@media (prefers-reduced-motion:reduce){
  .evapp-dashboard *{scroll-behavior:auto!important;transition:none!important}
}
		</style>
		<?php
	}
}

/**
 * Helper central para validar si el usuario puede activar o conservar un evento.
 */
if ( ! function_exists('eventosapp_dashboard_user_can_select_event') ) {
	function eventosapp_dashboard_user_can_select_event( $event_id, $user_id = 0 ) {
		$event_id = absint( $event_id );
		$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $event_id || ! $user_id || get_post_type( $event_id ) !== 'eventosapp_event' ) {
			return false;
		}

		if ( function_exists('eventosapp_dashboard_user_can_access_event_scope') ) {
			return eventosapp_dashboard_user_can_access_event_scope( $event_id, $user_id );
		}

		if ( user_can( $user_id, 'manage_options' ) ) return true;

		if ( function_exists('eventosapp_staff_access_user_can_select_event_in_dashboard') && eventosapp_staff_access_user_can_select_event_in_dashboard( $event_id, $user_id ) ) return true;
		if ( function_exists('eventosapp_support_user_has_assignment_in_event') && eventosapp_support_user_has_assignment_in_event( $event_id, $user_id ) ) return true;
		if ( function_exists('eventosapp_expositor_user_can_select_event_in_dashboard') && eventosapp_expositor_user_can_select_event_in_dashboard( $event_id, $user_id ) ) return true;
		if ( function_exists('eventosapp_user_can_manage_event') && eventosapp_user_can_manage_event( $event_id, $user_id ) ) return true;

		$post = get_post( $event_id );
		return $post && (int) $post->post_author === $user_id;
	}
}

/**
 * Determina si el usuario tiene al menos un evento seleccionable.
 */
if ( ! function_exists('eventosapp_dashboard_user_has_any_selectable_event') ) {
	function eventosapp_dashboard_user_has_any_selectable_event( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $user_id ) return false;
		if ( user_can( $user_id, 'manage_options' ) ) return true;

		if ( function_exists('eventosapp_staff_access_user_has_any_dashboard_event') && eventosapp_staff_access_user_has_any_dashboard_event( $user_id ) ) return true;
		if ( function_exists('eventosapp_dashboard_user_has_any_cogestion_assignment') && eventosapp_dashboard_user_has_any_cogestion_assignment( $user_id ) ) return true;
		if ( function_exists('eventosapp_support_user_has_any_event') && eventosapp_support_user_has_any_event( $user_id ) ) return true;
		if ( function_exists('eventosapp_expositor_user_has_any_event') && eventosapp_expositor_user_has_any_event( $user_id ) ) return true;

		$event_ids = get_posts([
			'post_type'      => 'eventosapp_event',
			'post_status'    => [ 'publish', 'private', 'future', 'draft', 'pending' ],
			'posts_per_page' => 300,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);

		foreach ( (array) $event_ids as $event_id ) {
			if ( eventosapp_dashboard_user_can_select_event( $event_id, $user_id ) ) return true;
		}

		return false;
	}
}

if ( ! function_exists('eventosapp_dashboard_role_can') ) {
	function eventosapp_dashboard_role_can( $feature, $user_id = 0 ) {
		if ( ! function_exists('eventosapp_role_can') ) return false;
		return eventosapp_role_can( $feature, $user_id ?: null );
	}
}

/**
 * Categorías visuales. Los permisos siguen viniendo de la configuración existente.
 */
if ( ! function_exists('eventosapp_dashboard_categories') ) {
	function eventosapp_dashboard_categories() {
		$categories = [
			'analytics'  => 'Resultados y analítica',
			'checkin'    => 'Registro y Check-In',
			'access'     => 'Control de acceso',
			'operations' => 'Operación del evento',
			'engagement' => 'Participación y networking',
			'exhibitors' => 'Expositores y aliados',
		];
		return apply_filters( 'eventosapp_dashboard_categories', $categories );
	}
}

/**
 * Registro central de módulos del dashboard.
 *
 * Este filtro permite que módulos nuevos agreguen su tarjeta sin tener que volver
 * a construir el shortcode ni el widget de Elementor.
 */
if ( ! function_exists('eventosapp_dashboard_get_modules') ) {
	function eventosapp_dashboard_get_modules( $event_id, $user_id = 0 ) {
		$event_id = absint( $event_id );
		$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();

		$company_visible = function_exists('eventosapp_company_checkin_user_can_view')
			? eventosapp_company_checkin_user_can_view( $event_id, $user_id )
			: ( function_exists('eventosapp_company_checkin_is_enabled') && eventosapp_company_checkin_is_enabled( $event_id ) && eventosapp_dashboard_role_can( 'company_checkin', $user_id ) );

		$raffle_visible = function_exists('eventosapp_live_raffle_user_can_view')
			? eventosapp_live_raffle_user_can_view( $event_id, $user_id )
			: ( function_exists('eventosapp_live_raffle_is_enabled') && eventosapp_live_raffle_is_enabled( $event_id ) && eventosapp_dashboard_role_can( 'live_raffle', $user_id ) );

		$modules = [
			'metrics' => [
				'title' => 'Métricas', 'description' => 'Consulta resultados, asistencia y rendimiento general.',
				'icon' => 'metrics', 'category' => 'analytics', 'url' => function_exists('eventosapp_get_metrics_url') ? eventosapp_get_metrics_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'metrics', $user_id ), 'keywords' => 'estadísticas indicadores resultados asistencia',
			],
			'flow_metrics' => [
				'title' => 'Métricas de Encuestas', 'description' => 'Analiza respuestas y resultados de los formularios enviados.',
				'icon' => 'flow-metrics', 'category' => 'analytics', 'url' => function_exists('eventosapp_get_flow_metrics_url') ? eventosapp_get_flow_metrics_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'flow_metrics', $user_id ), 'keywords' => 'encuestas whatsapp flow respuestas',
			],
			'company_checkin' => [
				'title' => 'Empresas con Check-In', 'description' => 'Monitorea empresas y asistentes que ya ingresaron.',
				'icon' => 'building-checkin', 'category' => 'analytics', 'url' => function_exists('eventosapp_get_company_checkin_url') ? eventosapp_get_company_checkin_url() : '#',
				'visible' => $company_visible, 'keywords' => 'empresa nit asistentes ingreso monitor',
			],
			'register' => [
				'title' => 'Registro Manual de Asistentes', 'description' => 'Crea asistentes directamente desde el panel operativo.',
				'icon' => 'circle-user', 'category' => 'checkin', 'url' => function_exists('eventosapp_get_register_url') ? eventosapp_get_register_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'register', $user_id ), 'keywords' => 'registrar crear asistente manual',
			],
			'qr' => [
				'title' => 'Check-In con QR', 'description' => 'Escanea el código del ticket y registra el ingreso.',
				'icon' => 'qrcode', 'category' => 'checkin', 'url' => function_exists('eventosapp_get_qr_url') ? eventosapp_get_qr_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'qr', $user_id ), 'keywords' => 'scanner cámara código qr ingreso',
			],
			'search' => [
				'title' => 'Check-In Manual & Escarapela', 'description' => 'Busca asistentes, confirma el ingreso e imprime su escarapela.',
				'icon' => 'id-badge', 'category' => 'checkin', 'url' => function_exists('eventosapp_get_search_url') ? eventosapp_get_search_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'search', $user_id ), 'keywords' => 'buscar imprimir badge escarapela manual',
			],
			'self_checkin' => [
				'title' => 'Autogestión del Asistente', 'description' => 'Activa el kiosko para búsqueda e impresión autónoma.',
				'icon' => 'self-checkin', 'category' => 'checkin', 'url' => function_exists('eventosapp_get_self_checkin_url') ? eventosapp_get_self_checkin_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'self_checkin', $user_id ), 'keywords' => 'kiosko autogestión impresión asistente',
			],
			'face_checkin' => [
				'title' => 'Check-In Facial', 'description' => 'Identifica asistentes mediante reconocimiento facial.',
				'icon' => 'face-scan', 'category' => 'checkin', 'url' => function_exists('eventosapp_get_face_checkin_url') ? eventosapp_get_face_checkin_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'face_checkin', $user_id ), 'keywords' => 'rostro cara facial reconocimiento ingreso',
			],
			'qr_localidad' => [
				'title' => 'Validador de Localidad', 'description' => 'Comprueba la localidad o tipo de acceso del ticket.',
				'icon' => 'check-double', 'category' => 'access', 'url' => function_exists('eventosapp_get_qr_localidad_url') ? eventosapp_get_qr_localidad_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'qr_localidad', $user_id ), 'keywords' => 'localidad validar zona ticket',
			],
			'qr_sesion' => [
				'title' => 'Control de Acceso a Sesión', 'description' => 'Valida el acceso a salas, conferencias o sesiones.',
				'icon' => 'calendar-check', 'category' => 'access', 'url' => function_exists('eventosapp_get_qr_sesion_url') ? eventosapp_get_qr_sesion_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'qr_sesion', $user_id ), 'keywords' => 'sesión sala conferencia acceso agenda',
			],
			'qr_double_auth' => [
				'title' => 'Check-In QR Doble Autenticación', 'description' => 'Aplica una verificación adicional antes de autorizar el ingreso.',
				'icon' => 'shield-check', 'category' => 'access', 'url' => function_exists('eventosapp_get_qr_double_auth_url') ? eventosapp_get_qr_double_auth_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'qr_double_auth', $user_id ), 'keywords' => 'seguridad doble autenticación validar qr',
			],
			'edit' => [
				'title' => 'Edición de Tickets', 'description' => 'Actualiza datos de asistentes y tickets existentes.',
				'icon' => 'ticket', 'category' => 'operations', 'url' => function_exists('eventosapp_get_edit_url') ? eventosapp_get_edit_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'edit', $user_id ), 'keywords' => 'editar ticket datos asistente',
			],
			'checklist' => [
				'title' => 'Checklist de Evento', 'description' => 'Organiza y verifica tareas operativas del evento.',
				'icon' => 'checklist', 'category' => 'operations', 'url' => function_exists('eventosapp_get_checklist_url') ? eventosapp_get_checklist_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'checklist', $user_id ), 'keywords' => 'lista tareas operación coordinación',
			],
			'support_assistance' => [
				'title' => 'Asistencia', 'description' => 'Gestiona solicitudes y actividades del equipo de apoyo.',
				'icon' => 'support-assistance', 'category' => 'operations', 'url' => function_exists('eventosapp_get_support_assistance_url') ? eventosapp_get_support_assistance_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'support_assistance', $user_id ), 'keywords' => 'soporte ayuda equipo apoyo solicitudes',
			],
			'support_team_metrics' => [
				'title' => 'Métrica de equipo de apoyo', 'description' => 'Consulta productividad y cumplimiento del personal de apoyo.',
				'icon' => 'support-metrics', 'category' => 'operations', 'url' => function_exists('eventosapp_get_support_team_metrics_url') ? eventosapp_get_support_team_metrics_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'support_team_metrics', $user_id ), 'keywords' => 'métrica staff apoyo productividad',
			],
			'networking_ranking' => [
				'title' => 'Ranking Networking', 'description' => 'Consulta la actividad y participación del networking.',
				'icon' => 'trophy', 'category' => 'engagement', 'url' => function_exists('eventosapp_get_networking_ranking_url') ? eventosapp_get_networking_ranking_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'networking_ranking', $user_id ), 'keywords' => 'ranking networking contactos lectores',
			],
			'live_raffle' => [
				'title' => 'Sorteo en Vivo', 'description' => 'Administra el sorteo y la selección de ganadores en tiempo real.',
				'icon' => 'live-raffle', 'category' => 'engagement', 'url' => function_exists('eventosapp_get_live_raffle_url') ? eventosapp_get_live_raffle_url() : '#',
				'visible' => $raffle_visible, 'keywords' => 'sorteo premio ganador vivo rifa',
			],
			'expositor' => [
				'title' => 'Expositor', 'description' => 'Accede al módulo operativo asignado al expositor.',
				'icon' => 'expositor', 'category' => 'exhibitors', 'url' => function_exists('eventosapp_get_expositor_url') ? eventosapp_get_expositor_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'expositor', $user_id ), 'keywords' => 'stand entregas expositor aliado',
			],
			'expositor_gestion' => [
				'title' => 'Gestión de Expositores', 'description' => 'Autoriza, configura y supervisa los expositores del evento.',
				'icon' => 'expositor-gestion', 'category' => 'exhibitors', 'url' => function_exists('eventosapp_get_expositor_gestion_url') ? eventosapp_get_expositor_gestion_url() : '#',
				'visible' => eventosapp_dashboard_role_can( 'expositor_gestion', $user_id ), 'keywords' => 'gestionar autorizar expositor stand',
			],
		];

		$modules = apply_filters( 'eventosapp_dashboard_modules', $modules, $event_id, $user_id );
		$clean   = [];

		foreach ( (array) $modules as $key => $module ) {
			if ( ! is_array( $module ) || empty( $module['visible'] ) || empty( $module['title'] ) ) continue;
			$key = sanitize_key( is_string( $key ) ? $key : ( $module['key'] ?? '' ) );
			if ( $key === '' ) $key = 'module_' . count( $clean );

			$clean[ $key ] = wp_parse_args( $module, [
				'title'       => $key,
				'description' => '',
				'icon'        => 'apps',
				'category'    => 'operations',
				'url'         => '#',
				'keywords'    => '',
			] );
		}

		return $clean;
	}
}

if ( ! function_exists('eventosapp_dashboard_smart_columns') ) {
	function eventosapp_dashboard_smart_columns( $count, $requested = 0, $device = 'desktop' ) {
		$count     = max( 1, absint( $count ) );
		$requested = absint( $requested );
		if ( $requested > 0 ) return max( 1, min( 4, $requested ) );
		if ( $device === 'mobile' ) return 1;
		if ( $device === 'tablet' ) return min( 2, $count );
		if ( $count === 1 ) return 1;
		if ( $count === 2 ) return 2;
		if ( $count <= 6 ) return 3;
		return 4;
	}
}

if ( ! function_exists('eventosapp_dashboard_get_selectable_events') ) {
	function eventosapp_dashboard_get_selectable_events( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		$all_events = get_posts([
			'post_type'      => 'eventosapp_event',
			'posts_per_page' => 300,
			'post_status'    => [ 'publish', 'private', 'future', 'draft', 'pending' ],
			'orderby'        => 'title',
			'order'          => 'ASC',
		]);

		return array_values( array_filter( $all_events, static function( $event ) use ( $user_id ) {
			return eventosapp_dashboard_user_can_select_event( $event->ID, $user_id );
		} ) );
	}
}

if ( ! function_exists('eventosapp_dashboard_get_notice_html') ) {
	function eventosapp_dashboard_get_notice_html() {
		if ( isset( $_GET['evapp_err'] ) && $_GET['evapp_err'] !== '' ) {
			$message = rawurldecode( wp_unslash( $_GET['evapp_err'] ) );
			return '<div class="evapp-notice is-error" role="alert"><strong>Error:</strong><span>' . esc_html( $message ) . '</span></div>';
		}
		if ( isset( $_GET['set'] ) && (string) $_GET['set'] === '1' ) {
			return '<div class="evapp-notice is-success" role="status"><strong>Listo:</strong><span>Evento activado correctamente.</span></div>';
		}
		return '';
	}
}

if ( ! function_exists('eventosapp_dashboard_render_active_event') ) {
	function eventosapp_dashboard_render_active_event( $event_id, $module_count = 0, $show_count = true ) {
		$event_id = absint( $event_id );
		$base = function_exists('eventosapp_get_dashboard_url') ? eventosapp_get_dashboard_url() : home_url('/');
		$base = remove_query_arg( [ 'evapp', 'evapp_err', 'set' ], $base );
		$change_url = add_query_arg( [ 'evapp' => 'change_event' ], $base );
		?>
		<div class="evapp-event-context">
			<div class="evapp-event-context-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 9h18"/></svg>
			</div>
			<div class="evapp-event-context-copy">
				<span class="evapp-event-label">Evento activo</span>
				<span class="evapp-event-name"><?php echo esc_html( get_the_title( $event_id ) ); ?></span>
			</div>
			<a class="evapp-change-event" href="<?php echo esc_url( $change_url ); ?>">Cambiar evento</a>
			<?php if ( $show_count && $module_count > 0 ) : ?>
				<span class="screen-reader-text"><?php echo esc_html( sprintf( '%d módulos disponibles.', $module_count ) ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}
}

if ( ! function_exists('eventosapp_dashboard_render_card') ) {
	function eventosapp_dashboard_render_card( $key, $module, $show_descriptions = true ) {
		$search_text = implode( ' ', [ $module['title'], $module['description'], $module['keywords'], $key ] );
		?>
		<a
			class="evapp-card"
			href="<?php echo esc_url( $module['url'] ); ?>"
			data-evapp-module="<?php echo esc_attr( $key ); ?>"
			data-evapp-search="<?php echo esc_attr( remove_accents( strtolower( wp_strip_all_tags( $search_text ) ) ) ); ?>"
			aria-label="<?php echo esc_attr( $module['title'] ); ?>"
		>
			<span class="evapp-card-icon"><?php echo eventosapp_dashboard_icon( $module['icon'] ); ?></span>
			<span class="evapp-card-copy">
				<span class="evapp-title"><?php echo esc_html( $module['title'] ); ?></span>
				<?php if ( $show_descriptions && $module['description'] !== '' ) : ?>
					<span class="evapp-card-description"><?php echo esc_html( $module['description'] ); ?></span>
				<?php endif; ?>
			</span>
			<span class="evapp-card-arrow" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg></span>
		</a>
		<?php
	}
}

/**
 * Render compartido por el shortcode y el widget de Elementor.
 */
if ( ! function_exists('eventosapp_render_dashboard') ) {
	function eventosapp_render_dashboard( $args = [] ) {
		$defaults = [
			'show_header'          => 'yes',
			'eyebrow'              => 'EventosApp',
			'title'                => 'Panel de gestión',
			'subtitle'             => 'Accede rápidamente a las herramientas disponibles para el evento seleccionado.',
			'show_module_count'    => 'yes',
			'show_active_event'    => 'yes',
			'show_search'          => 'auto',
			'search_min_modules'   => 7,
			'search_placeholder'   => 'Buscar una herramienta…',
			'empty_search_text'    => 'No encontramos herramientas con ese término.',
			'show_section_titles'  => 'auto',
			'show_descriptions'    => 'yes',
			'columns_desktop'      => 0,
			'columns_tablet'       => 0,
			'columns_mobile'       => 1,
			'instance_id'          => '',
		];
		$args = wp_parse_args( is_array( $args ) ? $args : [], $defaults );

		ob_start();
		eventosapp_print_dashboard_css();
		$dashboard_css = ob_get_clean();

		if ( ! is_user_logged_in() ) {
			$login = wp_login_url( get_permalink() );
			return $dashboard_css . '<div class="evapp-dashboard"><div class="evapp-empty-state">Debes iniciar sesión. <a href="' . esc_url( $login ) . '">Iniciar sesión</a></div></div>';
		}

		$current_user_id = get_current_user_id();
		$active_event = function_exists('eventosapp_get_active_event') ? absint( eventosapp_get_active_event( $current_user_id ) ) : 0;
		$notice = eventosapp_dashboard_get_notice_html();

		if ( isset( $_GET['evapp'] ) && (string) $_GET['evapp'] === 'change_event' ) {
			if ( function_exists('eventosapp_clear_active_event') ) eventosapp_clear_active_event( $current_user_id );
			$active_event = 0;
		}

		if ( $active_event && ! eventosapp_dashboard_user_can_select_event( $active_event, $current_user_id ) ) {
			if ( function_exists('eventosapp_clear_active_event') ) eventosapp_clear_active_event( $current_user_id );
			$active_event = 0;
			if ( $notice === '' ) {
				$notice = '<div class="evapp-notice is-error" role="alert"><strong>Error:</strong><span>El evento activo anterior ya no está disponible para tu usuario. Selecciona un evento permitido.</span></div>';
			}
		}

		$can_view = eventosapp_dashboard_role_can( 'dashboard', $current_user_id );
		if ( ! $can_view ) $can_view = eventosapp_dashboard_user_has_any_selectable_event( $current_user_id );
		if ( ! $can_view && function_exists('eventosapp_staff_access_user_has_any_dashboard_event') ) $can_view = eventosapp_staff_access_user_has_any_dashboard_event( $current_user_id );
		if ( ! $can_view && function_exists('eventosapp_dashboard_user_has_any_cogestion_assignment') ) $can_view = eventosapp_dashboard_user_has_any_cogestion_assignment( $current_user_id );
		if ( ! $can_view && function_exists('eventosapp_support_user_has_any_event') ) $can_view = eventosapp_support_user_has_any_event( $current_user_id );
		if ( ! $can_view && function_exists('eventosapp_expositor_user_has_any_event') ) $can_view = eventosapp_expositor_user_has_any_event( $current_user_id );
		if ( ! $can_view ) return $dashboard_css . '<div class="evapp-dashboard"><div class="evapp-empty-state">No tienes permisos para ver este panel.</div></div>';

		ob_start();
		echo $dashboard_css;
		$instance_id = sanitize_html_class( $args['instance_id'] );
		if ( $instance_id === '' ) {
			$instance_id = function_exists('wp_unique_id') ? wp_unique_id( 'evapp-dashboard-' ) : uniqid( 'evapp-dashboard-', false );
		}

		$modules = $active_event ? eventosapp_dashboard_get_modules( $active_event, $current_user_id ) : [];
		$module_count = count( $modules );
		$show_header = $args['show_header'] === 'yes';
		$show_count = $args['show_module_count'] === 'yes';
		$show_descriptions = $args['show_descriptions'] === 'yes';
		$search_min = max( 1, absint( $args['search_min_modules'] ) );
		$show_search = $args['show_search'] === 'yes' || ( $args['show_search'] === 'auto' && $module_count >= $search_min );
		$show_sections = $args['show_section_titles'] === 'yes' || ( $args['show_section_titles'] === 'auto' && $module_count >= 6 );

		?>
		<div class="evapp-dashboard" id="<?php echo esc_attr( $instance_id ); ?>" data-evapp-dashboard>
			<div class="evapp-dashboard-shell">
				<?php if ( $show_header ) : ?>
					<header class="evapp-dashboard-header">
						<div class="evapp-dashboard-heading">
							<?php if ( trim( (string) $args['eyebrow'] ) !== '' ) : ?><p class="evapp-dashboard-eyebrow"><?php echo esc_html( $args['eyebrow'] ); ?></p><?php endif; ?>
							<?php if ( trim( (string) $args['title'] ) !== '' ) : ?><h2 class="evapp-dashboard-main-title"><?php echo esc_html( $args['title'] ); ?></h2><?php endif; ?>
							<?php if ( trim( (string) $args['subtitle'] ) !== '' ) : ?><p class="evapp-dashboard-subtitle"><?php echo esc_html( $args['subtitle'] ); ?></p><?php endif; ?>
						</div>
						<?php if ( $active_event && $show_count ) : ?>
							<div class="evapp-module-total"><span class="evapp-module-total-dot"></span><span data-evapp-total-label><?php echo esc_html( sprintf( '%d módulos disponibles', $module_count ) ); ?></span></div>
						<?php endif; ?>
					</header>
				<?php endif; ?>

				<?php echo $notice; ?>

				<?php if ( $active_event ) : ?>
					<?php if ( $args['show_active_event'] === 'yes' ) eventosapp_dashboard_render_active_event( $active_event, $module_count, $show_count ); ?>

					<?php if ( $module_count > 0 ) : ?>
						<?php if ( $show_search ) : ?>
							<div class="evapp-dashboard-toolbar">
								<div class="evapp-module-search-wrap">
									<svg class="evapp-module-search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="m20 20-3.5-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
									<label class="screen-reader-text" for="<?php echo esc_attr( $instance_id ); ?>-search">Buscar herramientas</label>
									<input id="<?php echo esc_attr( $instance_id ); ?>-search" class="evapp-module-search" type="search" autocomplete="off" placeholder="<?php echo esc_attr( $args['search_placeholder'] ); ?>" data-evapp-search-input>
									<button class="evapp-module-search-clear" type="button" aria-label="Limpiar búsqueda" data-evapp-search-clear>&times;</button>
								</div>
								<span class="evapp-search-result-count" data-evapp-result-count><?php echo esc_html( sprintf( '%d herramientas', $module_count ) ); ?></span>
							</div>
						<?php endif; ?>

						<div class="evapp-sections" role="navigation" aria-label="Panel de acciones del evento">
							<?php
							$categories = eventosapp_dashboard_categories();
							$groups = [];
							if ( $show_sections ) {
								foreach ( $modules as $key => $module ) {
									$category = sanitize_key( $module['category'] );
									if ( ! isset( $groups[ $category ] ) ) $groups[ $category ] = [];
									$groups[ $category ][ $key ] = $module;
								}
							} else {
								$groups['all'] = $modules;
							}

							foreach ( $groups as $category_key => $category_modules ) :
								$count = count( $category_modules );
								$desktop_columns = eventosapp_dashboard_smart_columns( $count, $args['columns_desktop'], 'desktop' );
								$tablet_columns  = eventosapp_dashboard_smart_columns( $count, $args['columns_tablet'], 'tablet' );
								$mobile_columns  = eventosapp_dashboard_smart_columns( $count, $args['columns_mobile'], 'mobile' );
								$grid_class = 'evapp-grid';
								if ( $count === 1 ) $grid_class .= ' is-single';
								if ( $count === 2 ) $grid_class .= ' is-double';
								?>
								<section class="evapp-section" data-evapp-section>
									<?php if ( $show_sections ) : ?>
										<div class="evapp-section-header">
											<h3 class="evapp-section-title"><?php echo esc_html( $categories[ $category_key ] ?? ucfirst( str_replace( '_', ' ', $category_key ) ) ); ?></h3>
											<span class="evapp-section-count" data-evapp-section-count><?php echo esc_html( sprintf( '%d módulos', $count ) ); ?></span>
										</div>
									<?php endif; ?>
									<div class="<?php echo esc_attr( $grid_class ); ?>" data-count="<?php echo esc_attr( $count ); ?>" style="--evapp-section-columns:<?php echo esc_attr( $desktop_columns ); ?>;--evapp-section-columns-tablet:<?php echo esc_attr( $tablet_columns ); ?>;--evapp-section-columns-mobile:<?php echo esc_attr( $mobile_columns ); ?>;">
										<?php foreach ( $category_modules as $key => $module ) eventosapp_dashboard_render_card( $key, $module, $show_descriptions ); ?>
									</div>
								</section>
							<?php endforeach; ?>
						</div>
						<div class="evapp-no-search-results" data-evapp-no-results><?php echo esc_html( $args['empty_search_text'] ); ?></div>
					<?php else : ?>
						<div class="evapp-empty-state">No hay módulos habilitados para tu usuario en este evento.</div>
					<?php endif; ?>
				<?php else : ?>
					<?php $events = eventosapp_dashboard_get_selectable_events( $current_user_id ); ?>
					<?php if ( ! empty( $events ) ) : ?>
						<div class="evapp-selector-card">
							<div class="evapp-selector-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 9h18"/></svg></div>
							<div class="evapp-selector-content">
								<h3 class="evapp-selector-title">Elige el evento que deseas gestionar</h3>
								<p class="evapp-selector-help">El panel cargará automáticamente las herramientas y permisos correspondientes al evento seleccionado.</p>
								<form method="post" class="evapp-selector-form" data-evapp-select-event-form>
									<?php wp_nonce_field('evapp_set_event'); ?>
									<input type="hidden" name="evapp_action" value="set_event">
									<label class="screen-reader-text" for="<?php echo esc_attr( $instance_id ); ?>-event">Selecciona un evento</label>
									<select name="eventosapp_event_id" id="<?php echo esc_attr( $instance_id ); ?>-event" class="evapp-event-select" data-evapp-event-selector>
										<option value="">— Selecciona tu evento —</option>
										<?php foreach ( $events as $event ) : ?><option value="<?php echo esc_attr( $event->ID ); ?>"><?php echo esc_html( $event->post_title ); ?></option><?php endforeach; ?>
									</select>
									<button type="submit" class="evapp-primary-button" disabled data-evapp-manage-button>Gestionar evento</button>
								</form>
								<p class="evapp-selector-footnote">Hasta que no elijas un evento, las demás herramientas permanecerán deshabilitadas.</p>
							</div>
						</div>
					<?php else : ?>
						<div class="evapp-empty-state">No tienes eventos disponibles para gestionar.</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<script>
		(function(){
		  var root = document.getElementById(<?php echo wp_json_encode( $instance_id ); ?>);
		  if (!root || root.dataset.evappReady === '1') return;
		  root.dataset.evappReady = '1';

		  var select = root.querySelector('[data-evapp-event-selector]');
		  var manageButton = root.querySelector('[data-evapp-manage-button]');
		  if (select && manageButton) {
		    var toggleManage = function(){ manageButton.disabled = !select.value; };
		    select.addEventListener('change', toggleManage);
		    toggleManage();
		  }

		  var search = root.querySelector('[data-evapp-search-input]');
		  if (!search) return;
		  var cards = Array.prototype.slice.call(root.querySelectorAll('[data-evapp-module]'));
		  var sections = Array.prototype.slice.call(root.querySelectorAll('[data-evapp-section]'));
		  var clear = root.querySelector('[data-evapp-search-clear]');
		  var resultCount = root.querySelector('[data-evapp-result-count]');
		  var noResults = root.querySelector('[data-evapp-no-results]');

		  function normalize(value){
		    value = String(value || '').toLowerCase();
		    if (value.normalize) value = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		    return value.trim();
		  }

		  function applySearch(){
		    var term = normalize(search.value);
		    var visibleTotal = 0;
		    cards.forEach(function(card){
		      var haystack = normalize(card.getAttribute('data-evapp-search'));
		      var visible = !term || haystack.indexOf(term) !== -1;
		      card.hidden = !visible;
		      if (visible) visibleTotal++;
		    });

		    sections.forEach(function(section){
		      var visibleCards = section.querySelectorAll('[data-evapp-module]:not([hidden])').length;
		      section.hidden = visibleCards === 0;
		      var sectionCount = section.querySelector('[data-evapp-section-count]');
		      if (sectionCount) sectionCount.textContent = visibleCards + (visibleCards === 1 ? ' módulo' : ' módulos');
		    });

		    if (clear) clear.classList.toggle('is-visible', term.length > 0);
		    if (resultCount) resultCount.textContent = visibleTotal + (visibleTotal === 1 ? ' herramienta' : ' herramientas');
		    if (noResults) noResults.classList.toggle('is-visible', visibleTotal === 0);
		  }

		  search.addEventListener('input', applySearch);
		  if (clear) clear.addEventListener('click', function(){ search.value = ''; applySearch(); search.focus(); });
		})();
		</script>
		<?php
		return ob_get_clean();
	}
}

/**
 * Shortcode histórico. Conserva exactamente el mismo identificador y ahora usa
 * el mismo motor de render del widget de Elementor.
 */
if ( ! function_exists('eventosapp_dashboard_shortcode') ) {
	function eventosapp_dashboard_shortcode( $atts = [] ) {
		$atts = shortcode_atts([
			'show_header'         => 'yes',
			'show_search'         => 'auto',
			'show_section_titles' => 'auto',
			'show_descriptions'   => 'yes',
		], $atts, 'eventosapp_dashboard' );
		return eventosapp_render_dashboard( $atts );
	}
}
add_shortcode( 'eventosapp_dashboard', 'eventosapp_dashboard_shortcode' );
