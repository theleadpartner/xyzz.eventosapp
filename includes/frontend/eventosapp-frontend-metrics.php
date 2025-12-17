<?php
// includes/frontend/eventosapp-frontend-metrics.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Shortcode: [eventosapp_front_metrics]
 * - Requiere login y rol organizador/administrator
 * - Requiere evento activo (usa eventosapp_require_active_event())
 * - Secciones:
 *   1) KPI total de tickets del evento activo
 *   2) Pie: Checked In vs Not Checked In (número y %)
 *   3) Barras por hora (00–23): check-in principal (azul) + sesiones (colores determinísticos)
 *      -> Con filtro: Acumulado (rango) o Por día
 *   4) Tabla por Localidad (Check-ins, Not check-ins, % Asistencia, Check-ins sesiones adicionales*, % asistentes a sesiones*)
 *   5) NUEVO: Gráfico de torta de tipos de QR
 *   6) NUEVO: Tabla de estadísticas por tipo de QR
 *   7) Botón descargar base de datos (XLSX compatible con Excel) con columna de medio de checkin
 *
 * (*) Por "sesiones adicionales", se contabiliza el número de asistentes ÚNICOS con al menos un check-in de sesión.
 */

//
// === Permiso de visualización ===
//
if ( ! function_exists('eventosapp_user_can_view_metrics') ) {
    function eventosapp_user_can_view_metrics() {
        if ( ! is_user_logged_in() ) return false;
        $u = wp_get_current_user();
        $roles = (array) $u->roles;
        return in_array('administrator', $roles, true) || in_array('organizador', $roles, true);
    }
}

// === Shortcode ===
add_shortcode('eventosapp_front_metrics', function(){
    if ( function_exists('eventosapp_require_feature') ) eventosapp_require_feature('metrics');

    // Evento activo
    $active_event = function_exists('eventosapp_get_active_event') ? (int) eventosapp_get_active_event() : 0;
    if ( ! $active_event ) {
        ob_start();
        if (function_exists('eventosapp_require_active_event')) {
            eventosapp_require_active_event();
        } else {
            echo '<p>Debes seleccionar un evento activo.</p>';
        }
        return ob_get_clean();
    }

    // Nonces
    $nonce_data   = wp_create_nonce('eventosapp_metrics_data');
    $nonce_export = wp_create_nonce('eventosapp_export_tickets');

    ob_start(); ?>
    <style>
      .evapp-metrics-wrap { max-width:1100px; margin:0 auto; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
      .evapp-m-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; }
      .evapp-m-title { font-weight:800; font-size:1.25rem; letter-spacing:.3px; color:#0b1020; }
      .evapp-m-actions a.button { text-decoration:none; }

      .evapp-filters { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin:10px 0 14px; }
      .evapp-filters .grp { display:flex; flex-direction:column; gap:4px; }
      .evapp-filters label { font-size:.9rem; color:#334155; }
      .evapp-filters input[type="date"], .evapp-filters select { min-height:34px; }
      .evapp-filters .hidden { display:none !important; }

      .evapp-m-grid { display:grid; grid-template-columns: repeat(12, 1fr); gap:12px; }
      .evapp-card { background:#0b1020; color:#eaf1ff; border-radius:16px; padding:16px; box-shadow:0 8px 24px rgba(0,0,0,.12); }
      .evapp-card h3 { margin:0 0 10px; font-size:1rem; letter-spacing:.2px; color:#cfe0ff; }

      .evapp-kpi { grid-column: span 12; display:flex; align-items:center; justify-content:space-between; }
      .evapp-kpi .big { font-size:2.8rem; font-weight:900; letter-spacing:.4px; }
      .evapp-kpi .sub { opacity:.85; }

      @media(min-width:740px){
        .evapp-kpi { grid-column: span 12; }
      }

      .evapp-pie { grid-column: span 12; }
      .evapp-bars { grid-column: span 12; }
      @media(min-width:740px){
        .evapp-pie { grid-column: span 5; }
        .evapp-bars{ grid-column: span 7; }
      }

      .evapp-table { grid-column: span 12; overflow:auto; }
      .evapp-table table { width:100%; border-collapse:separate; border-spacing:0; }
      .evapp-table th, .evapp-table td { text-align:left; padding:10px 12px; border-bottom:1px solid rgba(255,255,255,.08); }
      .evapp-table thead th { position:sticky; top:0; background:#0f1835; color:#cfe0ff; z-index:1; }
      .evapp-table tbody tr:nth-child(odd){ background:#0a1329; }
      .evapp-table tbody tr:nth-child(even){ background:#0c1733; }
      .evapp-pill-ok { background:#22c55e; color:#07120c; font-weight:900; border-radius:999px; padding:2px 8px; font-size:.85rem; }
      .evapp-footnote { color:#a9b6d3; font-size:.85rem; margin-top:8px; opacity:.9 }
      .evapp-hint { color:#a9b6d3; font-size:.9rem; margin:6px 0 0; opacity:.85 }

      .evapp-bad { color:#ffb4b4; }
      .evapp-ok { color:#7CFF8D; }
      .evapp-muted { color:#a9b6d3; }
		
		.evapp-table .evapp-total td{
          font-weight:700;
          border-top:2px solid rgba(255,255,255,.25);
        }
        
      /* NUEVO: Estilos para gráfico y tabla de QR */
      .evapp-qr-pie { grid-column: span 12; }
      .evapp-qr-table { grid-column: span 12; }
      @media(min-width:740px){
        .evapp-qr-pie { grid-column: span 6; }
        .evapp-qr-table { grid-column: span 6; }
      }
    </style>

    <div class="evapp-metrics-wrap" data-event="<?php echo esc_attr($active_event); ?>">
      <div class="evapp-m-head">
        <div class="evapp-m-title">Métricas en tiempo real — <span class="evapp-muted"><?php echo esc_html( get_the_title($active_event) ); ?></span></div>
        <div class="evapp-m-actions">
          <a class="button button-primary" id="evappExportBtn"
             href="<?php echo esc_url( add_query_arg([
                 'action'   => 'eventosapp_export_tickets',
                 'security' => $nonce_export,
             ], admin_url('admin-ajax.php')) ); ?>">
             Descargar base (Excel)
          </a>
        </div>
      </div>

      <!-- Filtros para el gráfico de barras -->
      <div class="evapp-filters" id="evappFilters">
        <div class="grp">
          <label for="evappMode">Modo</label>
          <select id="evappMode">
            <option value="sum">Acumulado por horas (rango)</option>
            <option value="day">Por día</option>
          </select>
        </div>

        <div class="grp" id="grpFrom">
          <label for="evappFrom">Desde</label>
          <input type="date" id="evappFrom" />
        </div>
        <div class="grp" id="grpTo">
          <label for="evappTo">Hasta</label>
          <input type="date" id="evappTo" />
        </div>

        <div class="grp hidden" id="grpDay">
          <label for="evappDay">Fecha</label>
          <input type="date" id="evappDay" />
        </div>

        <div class="grp">
          <label>&nbsp;</label>
          <button class="button" id="evappApply">Aplicar</button>
        </div>
      </div>

      <div class="evapp-m-grid">
        <!-- KPI -->
        <div class="evapp-card evapp-kpi">
          <div>
            <div class="big" id="evappKpiTotal">0</div>
            <div class="sub">Tickets totales del evento activo</div>
          </div>
          <div class="evapp-pill-ok" id="evappKpiChecked">0 Checked In</div>
        </div>

        <!-- Pie -->
        <div class="evapp-card evapp-pie">
          <h3>Asistencia — Principal</h3>
          <canvas id="evappPie"></canvas>
          <div class="evapp-hint" id="evappPieHint"></div>
        </div>

        <!-- Barras -->
        <div class="evapp-card evapp-bars">
          <h3 id="evappBarsTitle">Check-ins por hora (acumulado)</h3>
          <canvas id="evappBars"></canvas>
          <div class="evapp-hint">Azul = Check-in principal. Sesiones = colores variados.</div>
        </div>

        <!-- Tabla -->
        <div class="evapp-card evapp-table">
          <h3>Resumen por Localidad</h3>
          <div style="overflow:auto">
            <table>
              <thead>
                <tr>
                  <th>Localidad</th>
                  <th>Check-ins</th>
                  <th>Not Check-ins</th>
                  <th>% Asistencia</th>
                  <th>Check-ins sesiones adicionales (únicos)</th>
                  <th>% asistentes a sesiones</th>
                </tr>
              </thead>
              <tbody id="evappTableBody">
                <tr><td colspan="6" class="evapp-muted">Cargando…</td></tr>
              </tbody>
            </table>
          </div>
          <div class="evapp-footnote">
            * "Sesiones adicionales" cuenta asistentes únicos que confirmaron al menos una sesión.
          </div>
        </div>
        
        <!-- NUEVO: Gráfico de torta de tipos de QR -->
        <div class="evapp-card evapp-qr-pie">
          <h3>Check-ins por Tipo de QR</h3>
          <canvas id="evappQrPie"></canvas>
          <div class="evapp-hint" id="evappQrPieHint"></div>
        </div>
        
        <!-- NUEVO: Tabla de estadísticas de tipos de QR -->
        <div class="evapp-card evapp-qr-table">
          <h3>Estadísticas por Tipo de QR</h3>
          <div style="overflow:auto">
            <table>
              <thead>
                <tr>
                  <th>Tipo de QR</th>
                  <th>Check-ins</th>
                  <th>% del Total</th>
                </tr>
              </thead>
              <tbody id="evappQrTableBody">
                <tr><td colspan="3" class="evapp-muted">Cargando…</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <?php
    // ===== Carga de scripts al estilo WP =====

    // 1) Chart.js desde CDN (en el footer)
    wp_enqueue_script(
        'chartjs',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
        [],
        '4.4.1',
        true
    );

    // 2) Registra el "handle" de tu script (vacío) y pasa datos con localize
    wp_register_script('eventosapp-front-metrics', '', ['chartjs'], null, true);

    wp_localize_script('eventosapp-front-metrics', 'EventosAppMetrics', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => $nonce_data,
    ]);

// 3) Tu JS como NOWDOC (no interpola ${...} de los template strings)
$js = <<<'JS'
    (function(){
        const ajaxURL   = (window.EventosAppMetrics && EventosAppMetrics.ajaxUrl) || '';
        const ajaxNonce = (window.EventosAppMetrics && EventosAppMetrics.nonce)   || '';

        const kpiTotal   = document.getElementById('evappKpiTotal');
        const kpiChecked = document.getElementById('evappKpiChecked');
        const tableBody  = document.getElementById('evappTableBody');
        const pieHint    = document.getElementById('evappPieHint');
        const barsTitle  = document.getElementById('evappBarsTitle');
        
        // NUEVO: Referencias para gráfico y tabla de QR
        const qrPieHint = document.getElementById('evappQrPieHint');
        const qrTableBody = document.getElementById('evappQrTableBody');

        // Filtros
        const modeSel = document.getElementById('evappMode');
        const gFrom   = document.getElementById('grpFrom');
        const gTo     = document.getElementById('grpTo');
        const gDay    = document.getElementById('grpDay');
        const inFrom  = document.getElementById('evappFrom');
        const inTo    = document.getElementById('evappTo');
        const inDay   = document.getElementById('evappDay');
        const btnApply= document.getElementById('evappApply');

        function toggleInputs(){
            const mode = modeSel.value;
            if (mode === 'sum'){
                gFrom.classList.remove('hidden');
                gTo.classList.remove('hidden');
                gDay.classList.add('hidden');
            } else {
                gFrom.classList.add('hidden');
                gTo.classList.add('hidden');
                gDay.classList.remove('hidden');
            }
        }
        modeSel.addEventListener('change', toggleInputs);
        toggleInputs();

        let pieChart = null;
        let barChart = null;
        let qrPieChart = null; // NUEVO: Chart para tipos de QR

        // Color estable por nombre de sesión
        function colorFor(text){
            let h = 0;
            for(let i=0;i<text.length;i++){ h = (h * 31 + text.charCodeAt(i)) >>> 0; }
            const hue = h % 360, sat = 70, lig = 52;
            function hslToRgb(h, s, l){
                s/=100; l/=100;
                const k = n => (n + h/30) % 12;
                const a = s * Math.min(l, 1-l);
                const f = n => l - a * Math.max(-1, Math.min(k(n)-3, Math.min(9-k(n), 1)));
                return [Math.round(255*f(0)), Math.round(255*f(8)), Math.round(255*f(4))];
            }
            const [r,g,b] = hslToRgb(hue, sat, lig);
            return 'rgb(' + r + ', ' + g + ', ' + b + ')';
        }

        function fmt(n){ return (n||0).toLocaleString(); }
        function pct(n){ return (Math.round((n||0)*100)/100).toFixed(2) + '%'; }

        function renderPie(data){
            const ctx = document.getElementById('evappPie').getContext('2d');
            const checked = data.checked_in_total || 0;
            const notc    = data.not_checked_in_total || 0;
            const total   = Math.max(checked + notc, 0);

            const checkedPct = total ? (checked*100/total) : 0;
            const notPct     = total ? (notc   *100/total) : 0;
            pieHint.textContent = `Checked In: ${fmt(checked)} (${pct(checkedPct)}) · Not Checked In: ${fmt(notc)} (${pct(notPct)})`;

            const cfg = {
                type: 'doughnut',
                data: {
                    labels: ['Checked In', 'Not Checked In'],
                    datasets: [{ data:[checked, notc], backgroundColor: ['#4f7cff', '#94a3b8'], borderWidth: 0 }]
                },
                options: { responsive:true, plugins: { legend:{ position:'bottom', labels:{ color:'#eaf1ff' } } } }
            };
            if (pieChart){ pieChart.data = cfg.data; pieChart.update(); }
            else { pieChart = new Chart(ctx, cfg); }
        }

        function renderBars(data){
            const ctx = document.getElementById('evappBars').getContext('2d');
            const labels = (data.bar && data.bar.labels) ? data.bar.labels : [];
            const datasets = [];

            // Principal (azul)
            datasets.push({ label: 'Principal', data: (data.bar && data.bar.main) ? data.bar.main : [], backgroundColor: '#4f7cff', borderWidth: 0, stack: 'x' });

            // Sesiones
            const ses = (data.bar && data.bar.sessions) ? data.bar.sessions : {};
            Object.keys(ses).sort().forEach(name=>{
                datasets.push({ label: name, data: ses[name], backgroundColor: colorFor(name), borderWidth: 0, stack: 'x' });
            });

            // Título dinámico
            if (data.bar && data.bar.mode === 'day'){
                barsTitle.textContent = 'Check-ins por hora — ' + (data.bar.day || '');
            } else if (data.bar) {
                barsTitle.textContent = 'Check-ins por hora (acumulado) — ' + (data.bar.from || '') + ' a ' + (data.bar.to || '');
            }

            const cfg = {
                type: 'bar',
                data: { labels, datasets },
                options: {
                    responsive:true,
                    scales: {
                        x: { stacked:true, ticks:{ color:'#cfe0ff' }, grid:{ color:'rgba(255,255,255,.08)'} },
                        y: { stacked:true, beginAtZero:true, ticks:{ color:'#cfe0ff' }, grid:{ color:'rgba(255,255,255,.08)'} }
                    },
                    plugins: { legend:{ position:'bottom', labels:{ color:'#eaf1ff' } } }
                }
            };
            if (barChart){ barChart.data = cfg.data; barChart.update(); }
            else { barChart = new Chart(ctx, cfg); }
        }

        function escapeHTML(s){
            return String(s)
                .replace(/&/g,'&amp;')
                .replace(/</g,'&lt;')
                .replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;')
                .replace(/'/g,'&#039;');
        }

        function renderTable(rows){
            if (!rows || !rows.length){
                tableBody.innerHTML = '<tr><td colspan="6" class="evapp-muted">Sin datos.</td></tr>';
                return;
            }

            // Totales
            let sumChk = 0, sumNot = 0, sumSesUniq = 0;
            rows.forEach(r=>{
                sumChk     += (r.checkins || 0);
                sumNot     += (r.not_checkins || 0);
                sumSesUniq += (r.checkins_sesiones_unicos || 0);
            });
            const totalTickets = sumChk + sumNot;
            const pctAsisTotal = totalTickets ? (sumChk * 100 / totalTickets) : 0;
            const pctSesTotal  = totalTickets ? (sumSesUniq * 100 / totalTickets) : 0;

            // Filas por localidad
            const bodyHTML = rows.map(function(r){
                return (
                    '<tr>'
                    + '<td>' + escapeHTML(r.localidad || '—') + '</td>'
                    + '<td>' + fmt(r.checkins) + '</td>'
                    + '<td>' + fmt(r.not_checkins) + '</td>'
                    + '<td>' + (r.pct_asistencia != null ? (Math.round(r.pct_asistencia*100)/100).toFixed(2) : '0.00') + '%</td>'
                    + '<td>' + fmt(r.checkins_sesiones_unicos) + '</td>'
                    + '<td>' + (r.pct_sesiones != null ? (Math.round(r.pct_sesiones*100)/100).toFixed(2) : '0.00') + '%</td>'
                    + '</tr>'
                );
            }).join('');

            // Fila de totales
            const totalsHTML =
                '<tr class="evapp-total">'
                + '<td>Total</td>'
                + '<td>' + fmt(sumChk) + '</td>'
                + '<td>' + fmt(sumNot) + '</td>'
                + '<td>' + (pctAsisTotal.toFixed(2)) + '%</td>'
                + '<td>' + fmt(sumSesUniq) + '</td>'
                + '<td>' + (pctSesTotal.toFixed(2)) + '%</td>'
                + '</tr>';

            tableBody.innerHTML = bodyHTML + totalsHTML;
        }
        
        // NUEVO: Renderizar gráfico de torta de tipos de QR
        function renderQrPie(qrStats){
            if (!qrStats || !qrStats.types || Object.keys(qrStats.types).length === 0) {
                qrPieHint.textContent = 'No hay datos de tipos de QR disponibles';
                if (qrPieChart) {
                    qrPieChart.destroy();
                    qrPieChart = null;
                }
                return;
            }
            
            const ctx = document.getElementById('evappQrPie').getContext('2d');
            const types = qrStats.types || {};
            const labels = [];
            const dataValues = [];
            const colors = {
                'Email': '#4f7cff',
                'Google Wallet': '#34a853',
                'Apple Wallet': '#000000',
                'PDF Impreso': '#f59e0b',
                'Escarapela Impresa': '#8b5cf6',
                'QR Legacy': '#94a3b8',
                'QR Preimpreso': '#64748b'
            };
            
            const backgroundColors = [];
            
            Object.keys(types).sort().forEach(type => {
                labels.push(type);
                dataValues.push(types[type]);
                backgroundColors.push(colors[type] || colorFor(type));
            });
            
            const total = qrStats.total || 0;
            qrPieHint.textContent = `Total de check-ins: ${fmt(total)}`;
            
            const cfg = {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: dataValues,
                        backgroundColor: backgroundColors,
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: '#eaf1ff' }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const percentage = total ? ((value / total) * 100).toFixed(2) : 0;
                                    return `${label}: ${fmt(value)} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            };
            
            if (qrPieChart) {
                qrPieChart.data = cfg.data;
                qrPieChart.update();
            } else {
                qrPieChart = new Chart(ctx, cfg);
            }
        }
        
        // NUEVO: Renderizar tabla de tipos de QR
        function renderQrTable(qrStats){
            if (!qrStats || !qrStats.types || Object.keys(qrStats.types).length === 0) {
                qrTableBody.innerHTML = '<tr><td colspan="3" class="evapp-muted">Sin datos de tipos de QR.</td></tr>';
                return;
            }
            
            const types = qrStats.types || {};
            const total = qrStats.total || 0;
            
            // Crear array de tipos ordenado por cantidad (descendente)
            const typeArray = Object.keys(types).map(type => ({
                type: type,
                count: types[type],
                percentage: total ? ((types[type] / total) * 100) : 0
            })).sort((a, b) => b.count - a.count);
            
            let bodyHTML = typeArray.map(item => {
                return (
                    '<tr>'
                    + '<td>' + escapeHTML(item.type) + '</td>'
                    + '<td>' + fmt(item.count) + '</td>'
                    + '<td>' + item.percentage.toFixed(2) + '%</td>'
                    + '</tr>'
                );
            }).join('');
            
            // Agregar fila de totales
            bodyHTML += 
                '<tr class="evapp-total">'
                + '<td>Total</td>'
                + '<td>' + fmt(total) + '</td>'
                + '<td>100.00%</td>'
                + '</tr>';
            
            qrTableBody.innerHTML = bodyHTML;
        }

        function setKpis(total, checked){
            kpiTotal.textContent   = fmt(total||0);
            kpiChecked.textContent = fmt(checked||0) + ' Checked In';
        }

        async function fetchData(){
            try {
                const fd = new FormData();
                fd.append('action',   'eventosapp_metrics_data');
                fd.append('security', ajaxNonce);

                // Filtros actuales
                const mode = modeSel.value;
                fd.append('mode', mode);
                if (mode === 'sum'){
                    if (inFrom.value) fd.append('from', inFrom.value);
                    if (inTo.value)   fd.append('to',   inTo.value);
                } else {
                    if (inDay.value)  fd.append('day',  inDay.value);
                }

                const resp = await fetch(ajaxURL, { method:'POST', body:fd, credentials:'same-origin' });
                const j = await resp.json();
                if (!j || !j.success) throw new Error((j && j.data && j.data.error) ? j.data.error : 'Error');

                const d = j.data;

                // Autollenar inputs si vienen vacíos
                if (!inDay.value && d.bar && d.bar.day)   inDay.value  = d.bar.day;
                if (!inFrom.value && d.bar && d.bar.from) inFrom.value = d.bar.from;
                if (!inTo.value && d.bar && d.bar.to)     inTo.value   = d.bar.to;

                setKpis(d.total_tickets, d.checked_in_total);
                renderPie(d);
                renderBars(d);
                renderTable((d.table && d.table.rows) ? d.table.rows : []);
                
                // NUEVO: Renderizar gráfico y tabla de tipos de QR
                renderQrPie(d.qr_stats);
                renderQrTable(d.qr_stats);
            } catch(e){
                console.error(e);
                tableBody.innerHTML = '<tr><td colspan="6" class="evapp-bad">No se pudieron cargar las métricas.</td></tr>';
                qrTableBody.innerHTML = '<tr><td colspan="3" class="evapp-bad">Error al cargar datos de QR.</td></tr>';
            }
        }

        btnApply.addEventListener('click', function(e){ e.preventDefault(); fetchData(); });

        // Espera al DOM
        if (document.readyState !== 'loading') init();
        else document.addEventListener('DOMContentLoaded', init);

        function init(){
            fetchData();
            setInterval(function(){ if (!document.hidden) fetchData(); }, 15000);
        }
    })();
JS;


    wp_add_inline_script('eventosapp-front-metrics', $js, 'after');
    wp_enqueue_script('eventosapp-front-metrics');

    // Devolvemos el HTML
    return ob_get_clean();
});




//
// === AJAX: datos de métricas en tiempo real ===
//
add_action('wp_ajax_eventosapp_metrics_data', function(){
    // CSRF
    check_ajax_referer('eventosapp_metrics_data', 'security');

    if ( ! is_user_logged_in() ) wp_send_json_error(['error'=>'No autorizado']);
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('metrics') ) {
        wp_send_json_error(['error'=>'Permisos insuficientes']);
    }

    // Evento activo
    $event_id = function_exists('eventosapp_get_active_event') ? (int) eventosapp_get_active_event() : 0;
    if ( ! $event_id ) wp_send_json_error(['error'=>'No hay evento activo.']);

    // TZ del evento (o del sitio)
    $event_tz = get_post_meta($event_id, '_eventosapp_zona_horaria', true);
    if (!$event_tz) {
        $event_tz = wp_timezone_string();
        if (!$event_tz || $event_tz === 'UTC') {
            $offset = get_option('gmt_offset');
            $event_tz = $offset ? timezone_name_from_abbr('', $offset * 3600, 0) ?: 'UTC' : 'UTC';
        }
    }
    try { $now = new DateTime('now', new DateTimeZone($event_tz)); }
    catch(Exception $e){ $now = new DateTime('now', wp_timezone()); }
    $today = $now->format('Y-m-d');

    // --- Filtros ---
    $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'day';
    if ($mode !== 'sum' && $mode !== 'day') $mode = 'day';

    // Normalizador fecha
    $is_date = function($s){
        return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
    };

    $req_day  = isset($_POST['day'])  ? sanitize_text_field($_POST['day'])  : '';
    $req_from = isset($_POST['from']) ? sanitize_text_field($_POST['from']) : '';
    $req_to   = isset($_POST['to'])   ? sanitize_text_field($_POST['to'])   : '';

    // Inicializa SIEMPRE
    $day  = $today;
    $from = $today;
    $to   = $today;

    if ($mode === 'day') {
        if ($is_date($req_day)) $day = $req_day;
    } else {
        if ($is_date($req_from)) $from = $req_from;
        if ($is_date($req_to))   $to   = $req_to;
        if ($to < $from) { $tmp = $from; $from = $to; $to = $tmp; }
    }

    // Tickets del evento
    $q = new WP_Query([
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            ['key'=>'_eventosapp_ticket_evento_id', 'value'=>$event_id, 'compare'=>'='],
        ],
    ]);
    $ids = $q->posts;
    $total = count($ids);

    // Agregadores
    $checked_total    = 0;
    $hourly_main      = array_fill(0, 24, 0);
    $hourly_ses       = []; // nombre_sesion => [24]
    $loc_totals       = []; // localidad => total
    $loc_checked      = []; // localidad => checked_total
    $loc_ses_uniques  = []; // localidad => set(ticket_id => true)
    
    // NUEVO: Agregador para tipos de QR
    $qr_types_count = [
        'Email' => 0,
        'Google Wallet' => 0,
        'Apple Wallet' => 0,
        'PDF Impreso' => 0,
        'Escarapela Impresa' => 0,
        'QR Legacy' => 0,
        'QR Preimpreso' => 0
    ];

    $all_localidades = get_post_meta($event_id, '_eventosapp_localidades', true);
    if (!is_array($all_localidades)) $all_localidades = ['General','VIP','Platino'];

    foreach ($all_localidades as $L) {
        $loc_totals[$L] = 0;
        $loc_checked[$L] = 0;
        $loc_ses_uniques[$L] = [];
    }

    // Helper: fecha en filtro
    $in_filter = function($fecha) use ($mode, $day, $from, $to){
        if (!$fecha) return false;
        if ($mode === 'day') {
            return $fecha === $day;
        } else {
            return ($fecha >= $from && $fecha <= $to);
        }
    };

    // Procesar tickets
    foreach ($ids as $tid) {
        $loc = get_post_meta($tid, '_eventosapp_asistente_localidad', true);
        if ($loc === '' || $loc === null) $loc = '(Sin localidad)';
        if (!array_key_exists($loc, $loc_totals)) {
            $loc_totals[$loc] = 0;
            $loc_checked[$loc] = 0;
            $loc_ses_uniques[$loc] = [];
        }
        $loc_totals[$loc]++;

        // Estado principal (algún día checked_in)
        $status_arr = get_post_meta($tid, '_eventosapp_checkin_status', true);
        if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
        if (!is_array($status_arr)) $status_arr = [];
        $any_checked = in_array('checked_in', $status_arr, true);
        if ($any_checked) {
            $checked_total++;
            $loc_checked[$loc]++;
        }

        // Log para barras, sesiones únicas y NUEVO: tipos de QR
        $log = get_post_meta($tid, '_eventosapp_checkin_log', true);
        if (is_string($log)) $log = @unserialize($log);
        if (!is_array($log)) $log = [];

        foreach ($log as $row) {
            $fecha = isset($row['fecha']) ? $row['fecha'] : '';
            $hora  = isset($row['hora'])  ? $row['hora']  : '';
            $status= isset($row['status'])? $row['status']: '';
            $ses   = isset($row['sesion'])? $row['sesion']: '';
            $H     = ($hora && preg_match('/^\d{2}/', $hora)) ? intval(substr($hora,0,2)) : null;

            if ($H === null || $H < 0 || $H > 23) $H = null;

            if ($in_filter($fecha) && $H !== null) {
                if ($status === 'checked_in') {
                    $hourly_main[$H] = (isset($hourly_main[$H]) ? $hourly_main[$H] : 0) + 1;
                } elseif ($status === 'session_checked_in' && $ses) {
                    if (!isset($hourly_ses[$ses])) $hourly_ses[$ses] = array_fill(0,24,0);
                    $hourly_ses[$ses][$H] = (isset($hourly_ses[$ses][$H]) ? $hourly_ses[$ses][$H] : 0) + 1;
                }
            }

            // Un asistente cuenta 1 sola vez para sesiones (en cualquier fecha)
            if ($status === 'session_checked_in') {
                $loc_ses_uniques[$loc][$tid] = true;
            }
            
            // NUEVO: Contar tipos de QR (solo para check-ins principales)
            if ($status === 'checked_in' && isset($row['qr_type_label'])) {
                $qr_label = $row['qr_type_label'];
                if (isset($qr_types_count[$qr_label])) {
                    $qr_types_count[$qr_label]++;
                } else {
                    $qr_types_count[$qr_label] = 1;
                }
            }
        }
    }

    // Tabla por localidad (totales del evento)
    $rows = [];
    foreach ($loc_totals as $L => $tot) {
        $chk  = $loc_checked[$L] ?? 0;
        $not  = max($tot - $chk, 0);
        $pctA = $tot ? ($chk * 100 / $tot) : 0;

        $sesUniq = isset($loc_ses_uniques[$L]) ? count($loc_ses_uniques[$L]) : 0;
        $pctSes  = $tot ? ($sesUniq * 100 / $tot) : 0;

        $rows[] = [
            'localidad'                => $L,
            'checkins'                 => (int)$chk,
            'not_checkins'             => (int)$not,
            'pct_asistencia'           => round($pctA, 2),
            'checkins_sesiones_unicos' => (int)$sesUniq,
            'pct_sesiones'             => round($pctSes, 2),
        ];
    }
    usort($rows, function($a,$b){ return $b['checkins'] <=> $a['checkins']; });

    // Respuesta (meta de barras según modo)
    $bar_meta = [
        'labels'   => array_map(function($h){ return str_pad((string)$h, 2, '0', STR_PAD_LEFT); }, range(0,23)),
        'main'     => array_values($hourly_main),
        'sessions' => $hourly_ses,
        'mode'     => $mode,
    ];
    if ($mode === 'day') {
        $bar_meta['day']  = $day;
        $bar_meta['from'] = $day;
        $bar_meta['to']   = $day;
    } else {
        $bar_meta['from'] = $from;
        $bar_meta['to']   = $to;
        $bar_meta['day']  = $today;
    }
    
    // NUEVO: Preparar estadísticas de tipos de QR (filtrar los que tienen 0)
    $qr_stats_filtered = [];
    $qr_total = 0;
    foreach ($qr_types_count as $type => $count) {
        if ($count > 0) {
            $qr_stats_filtered[$type] = $count;
            $qr_total += $count;
        }
    }

    $out = [
        'total_tickets'        => $total,
        'checked_in_total'     => $checked_total,
        'not_checked_in_total' => max($total - $checked_total, 0),
        'bar'   => $bar_meta,
        'table' => [ 'rows' => $rows ],
        'qr_stats' => [ // NUEVO: Estadísticas de tipos de QR
            'types' => $qr_stats_filtered,
            'total' => $qr_total
        ]
    ];

    wp_send_json_success($out);
});


//
// === Helper: generar y transmitir XLSX en vivo (sin librerías externas) ===
//
if ( ! function_exists('eventosapp_stream_xlsx') ) {
    function eventosapp_stream_xlsx($filename, $sheetName, $headers, $rows){
        if ( ! class_exists('ZipArchive') ) {
            wp_die('El servidor no tiene ZipArchive habilitado (requerido para .xlsx).', '', 500);
        }

        // util: col 0 -> A, 1 -> B, ...
        $colLetter = function($i){
            $s = '';
            while ($i >= 0) {
                $s = chr($i % 26 + 65) . $s;
                $i = intdiv($i, 26) - 1;
            }
            return $s;
        };
        $xmlEsc = function($s){
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $cols = count($headers);
        $rowsCount = count($rows) + 1; // + encabezado
        $lastCol = $colLetter($cols - 1);
        $dimension = "A1:{$lastCol}{$rowsCount}";

        // ===== xl/worksheets/sheet1.xml (usamos inlineStr para conservar formato texto) =====
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
               . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
               . '<dimension ref="'.$dimension.'"/>'
               . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
               . '<sheetFormatPr defaultRowHeight="15"/>'
               . '<sheetData>';

        // Fila encabezados
        $sheet .= '<row r="1">';
        foreach ($headers as $ci => $h) {
            $ref = $colLetter($ci) . '1';
            $sheet .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$xmlEsc($h).'</t></is></c>';
        }
        $sheet .= '</row>';

        // Filas de datos
        for ($ri = 0; $ri < count($rows); $ri++) {
            $sheet .= '<row r="'.($ri+2).'">';
            $row = $rows[$ri];
            for ($ci = 0; $ci < $cols; $ci++) {
                $val = isset($row[$ci]) ? $row[$ci] : '';
                $ref = $colLetter($ci) . ($ri+2);
                // Todo como texto para no dañar CC / teléfonos / IDs / horas
                $sheet .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$xmlEsc($val).'</t></is></c>';
            }
            $sheet .= '</row>';
        }

        $sheet .= '</sheetData></worksheet>';

        // ===== Resto de partes mínimas del paquete XLSX =====
        $contentTypes =
'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>';

        $rels =
'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>';

        $workbook =
'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
 xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="'.htmlspecialchars($sheetName, ENT_QUOTES, 'UTF-8').'" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';

        $workbookRels =
'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

        // Estilos mínimos
        $styles =
'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="1"><font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font></fonts>
  <fills count="1"><fill><patternFill patternType="none"/></fill></fills>
  <borders count="1"><border/></borders>
  <cellStyleXfs count="1"><xf/></cellStyleXfs>
  <cellXfs count="1"><xf xfId="0"/></cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>';

        $nowIso = gmdate('Y-m-d\TH:i:s\Z');
        $core =
'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"
 xmlns:dc="http://purl.org/dc/elements/1.1/"
 xmlns:dcterms="http://purl.org/dc/terms/"
 xmlns:dcmitype="http://purl.org/dc/dcmitype/"
 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:creator>EventosApp</dc:creator>
  <cp:lastModifiedBy>EventosApp</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">'.$nowIso.'</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">'.$nowIso.'</dcterms:modified>
</cp:coreProperties>';

        $app =
'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"
 xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>EventosApp</Application>
  <DocSecurity>0</DocSecurity>
  <ScaleCrop>false</ScaleCrop>
  <Company>EventosApp</Company>
  <LinksUpToDate>false</LinksUpToDate>
  <SharedDoc>false</SharedDoc>
  <HyperlinksChanged>false</HyperlinksChanged>
  <AppVersion>16.0000</AppVersion>
</Properties>';

        // Crear zip en archivo temporal
        if ( function_exists('wp_tempnam') ) {
            $tmp = wp_tempnam('eventosapp_xlsx');
        } else {
            $tmp = tempnam(sys_get_temp_dir(), 'evxlsx_');
        }
        if ( ! $tmp ) wp_die('No se pudo crear archivo temporal para .xlsx', '', 500);

        $zip = new ZipArchive();
        if (true !== $zip->open($tmp, ZipArchive::OVERWRITE)) {
            @unlink($tmp);
            wp_die('No se pudo inicializar el contenedor .xlsx', '', 500);
        }

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('docProps/core.xml', $core);
        $zip->addFromString('docProps/app.xml', $app);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/styles.xml', $styles);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        // Enviar al navegador
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}

//
// === AJAX: Exportar base (XLSX para Excel) ===
add_action('wp_ajax_eventosapp_export_tickets', function(){
    // CSRF (sí, también en GET)
    check_ajax_referer('eventosapp_export_tickets', 'security');

    if ( ! is_user_logged_in() ) wp_die('No autorizado', '', 403);
    if ( ! function_exists('eventosapp_role_can') || ! eventosapp_role_can('metrics') ) {
        wp_die('Permisos insuficientes', '', 403);
    }

    $event_id = function_exists('eventosapp_get_active_event') ? (int) eventosapp_get_active_event() : 0;
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
        wp_die('No hay evento activo válido (eventosapp_event).', '', 400);
    }

    // --- Utilidades ---
    $is_date = function($s){
        return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
    };

    // === 1) DÍAS DEL EVENTO ===
    $event_days = [];
    $tipo_fecha   = get_post_meta($event_id, '_eventosapp_tipo_fecha', true) ?: 'unica';
    $fecha_unica  = get_post_meta($event_id, '_eventosapp_fecha_unica', true) ?: '';
    $fecha_inicio = get_post_meta($event_id, '_eventosapp_fecha_inicio', true) ?: '';
    $fecha_fin    = get_post_meta($event_id, '_eventosapp_fecha_fin', true) ?: '';
    $fechas_noco  = get_post_meta($event_id, '_eventosapp_fechas_noco', true);

    if (!is_array($fechas_noco)) {
        $fechas_noco = is_string($fechas_noco) && $fechas_noco ? array_map('trim', explode(',', $fechas_noco)) : [];
    }

    try {
        if ($tipo_fecha === 'unica' && $is_date($fecha_unica)) {
            $event_days = [$fecha_unica];
        } elseif ($tipo_fecha === 'consecutiva' && $is_date($fecha_inicio) && $is_date($fecha_fin) && $fecha_fin >= $fecha_inicio) {
            $d1 = new DateTime($fecha_inicio);
            $d2 = new DateTime($fecha_fin);
            while ($d1 <= $d2) {
                $event_days[] = $d1->format('Y-m-d');
                $d1->modify('+1 day');
            }
        } elseif ($tipo_fecha === 'noconsecutiva' && !empty($fechas_noco)) {
            foreach ($fechas_noco as $f) if ($is_date($f)) $event_days[] = $f;
            sort($event_days);
        }
    } catch (\Throwable $e) {
        $event_days = [];
    }

    if (!$event_days) {
        $q_tmp = new WP_Query([
            'post_type'      => 'eventosapp_ticket',
            'post_status'    => 'any',
            'posts_per_page' => 250,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key'=>'_eventosapp_ticket_evento_id', 'value'=>$event_id, 'compare'=>'='],
            ],
        ]);
        $days_set = [];
        foreach ($q_tmp->posts as $tid) {
            $status_arr = get_post_meta($tid, '_eventosapp_checkin_status', true);
            if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
            if (is_array($status_arr)) foreach ($status_arr as $d => $st) if ($is_date($d)) $days_set[$d] = true;

            $log = get_post_meta($tid, '_eventosapp_checkin_log', true);
            if (is_string($log)) $log = @unserialize($log);
            if (is_array($log)) foreach ($log as $row) {
                $f = isset($row['fecha']) ? $row['fecha'] : '';
                if ($is_date($f)) $days_set[$f] = true;
            }
        }
        $event_days = array_keys($days_set);
        sort($event_days);
    }

    // === 2) SESIONES (detección robusta) ===
    $extract_names = function($raw){
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (is_string($v) && $v !== '') { $out[$v] = true; continue; }
                if (is_array($v)) {
                    if (!empty($v['nombre']) && is_string($v['nombre'])) $out[$v['nombre']] = true;
                    elseif (!empty($v['name']) && is_string($v['name'])) $out[$v['name']] = true;
                    elseif (!empty($v['title']) && is_string($v['title'])) $out[$v['title']] = true;
                    elseif (!empty($v['slug']) && is_string($v['slug'])) $out[$v['slug']] = true;
                }
            }
        }
        return array_keys($out);
    };

    $all_sessions = [];
    foreach (['_eventosapp_sesiones','_eventosapp_sesiones_def','_eventosapp_lista_sesiones','_eventosapp_sessions','_eventosapp_sesiones_internas'] as $meta_key) {
        $raw = get_post_meta($event_id, $meta_key, true);
        if (!empty($raw)) { $names = $extract_names($raw); if (!empty($names)) { $all_sessions = $names; break; } }
    }
    if (empty($all_sessions)) {
        $q_tmp2 = new WP_Query([
            'post_type'      => 'eventosapp_ticket',
            'post_status'    => 'any',
            'posts_per_page' => 250,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key'=>'_eventosapp_ticket_evento_id', 'value'=>$event_id, 'compare'=>'='],
            ],
        ]);
        $set = [];
        foreach ($q_tmp2->posts as $tid) {
            $acc = get_post_meta($tid, '_eventosapp_ticket_sesiones_acceso', true);
            if (!is_array($acc)) $acc = [];
            foreach ($acc as $sname) if (is_string($sname) && $sname!=='') $set[$sname] = true;

            $ses = get_post_meta($tid, '_eventosapp_ticket_checkin_sesiones', true);
            if (is_string($ses)) $ses = @unserialize($ses);
            if (!is_array($ses)) $ses = [];
            foreach ($ses as $sname => $st) if (is_string($sname) && $sname!=='') $set[$sname] = true;
        }
        $all_sessions = array_keys($set);
    }
    sort($all_sessions, SORT_NATURAL | SORT_FLAG_CASE);

    // === 3) Tickets ===
    $q = new WP_Query([
        'post_type'      => 'eventosapp_ticket',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            ['key'=>'_eventosapp_ticket_evento_id', 'value'=>$event_id, 'compare'=>'='],
        ],
    ]);
    $ids = $q->posts;

    $extra_prefix = defined('EVAPP_PUBLIC_EXTRA_PREFIX') ? EVAPP_PUBLIC_EXTRA_PREFIX : 'eventosapp_extra';
    $extra_fields = function_exists('eventosapp_get_event_extra_fields')
        ? eventosapp_get_event_extra_fields($event_id)
        : [];

    // === 4) Encabezados ===
    $headers = [
        'Ticket Public ID','Ticket Post ID','Evento ID','Evento',
        'Secuencia Interna',
        'Nombre','Apellido','CC','Email','Teléfono','Empresa','NIT','Cargo','Localidad',
        'Checked-In (algún día)'
    ];
    if (!empty($extra_fields)) {
        foreach ($extra_fields as $f) { $headers[] = 'Extra: ' . ($f['label'] ?? ''); }
    }

    // Check-in por día (SI/NO) + Hora por día
    foreach ($event_days as $d) {
        $headers[] = 'Check-in — '.$d;
        $headers[] = 'Hora check-in — '.$d;
    }

    // Sesiones (acceso y check-in por sesión)
    foreach ($all_sessions as $sname) { $headers[] = 'Sesión: '.$sname.' (Acceso)'; }
    foreach ($all_sessions as $sname) { $headers[] = 'Sesión: '.$sname.' (Check-in)'; }

    // Hora por sesión y día
    foreach ($all_sessions as $sname) {
        foreach ($event_days as $d) {
            $headers[] = 'Hora sesión: '.$sname.' — '.$d;
        }
    }

    $headers[] = 'Fecha creación';
    
    // Encabezados de estado de correo
    $headers[] = 'Estado Correo Ticket';
    $headers[] = 'Fecha del Primer Envío';
    $headers[] = 'Fecha del Último Envío';
    
    // NUEVO: Encabezado para medio de check-in
    $headers[] = 'Medio de Check-in';

    // === 5) Filas
    $dataRows = [];

    // util para comparar horas HH:MM o HH:MM:SS
    $min_time = function($a, $b){
        if ($a === '') return $b;
        if ($b === '') return $a;
        // normalizar a HH:MM:SS
        $norm = function($t){
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) return $t;
            if (preg_match('/^\d{2}:\d{2}$/', $t)) return $t.':00';
            return $t;
        };
        $na = $norm($a); $nb = $norm($b);
        return ($na <= $nb) ? $a : $b;
    };

    foreach ($ids as $tid) {
        $row = [];
        $pub_id   = get_post_meta($tid, 'eventosapp_ticketID', true);
        $seq      = get_post_meta($tid, '_eventosapp_ticket_seq', true);
        $first    = get_post_meta($tid, '_eventosapp_asistente_nombre', true);
        $last     = get_post_meta($tid, '_eventosapp_asistente_apellido', true);
        $cc       = get_post_meta($tid, '_eventosapp_asistente_cc', true);
        $email    = get_post_meta($tid, '_eventosapp_asistente_email', true);
        $tel      = get_post_meta($tid, '_eventosapp_asistente_tel', true);
        $comp     = get_post_meta($tid, '_eventosapp_asistente_empresa', true);
        $nit      = get_post_meta($tid, '_eventosapp_asistente_nit', true);
        $role     = get_post_meta($tid, '_eventosapp_asistente_cargo', true);
        $loc      = get_post_meta($tid, '_eventosapp_asistente_localidad', true);
        $ev_title = get_the_title($event_id);

        $status_arr = get_post_meta($tid, '_eventosapp_checkin_status', true);
        if (is_string($status_arr)) $status_arr = @unserialize($status_arr);
        if (!is_array($status_arr)) $status_arr = [];
        $any_checked = (in_array('checked_in', $status_arr, true) || in_array('checked-in', $status_arr, true)) ? 'SI' : 'NO';

        $acc = get_post_meta($tid, '_eventosapp_ticket_sesiones_acceso', true);
        if (!is_array($acc)) $acc = [];
        $acc_set = [];
        foreach ($acc as $sname) { if (is_string($sname) && $sname!=='') $acc_set[$sname] = true; }

        $ses = get_post_meta($tid, '_eventosapp_ticket_checkin_sesiones', true);
        if (is_string($ses)) $ses = @unserialize($ses);
        if (!is_array($ses)) $ses = [];

        $created = get_post_time('Y-m-d H:i:s', true, $tid);

        // Mapear horas por día (principal) y por sesión x día
        $main_time_by_day = [];
        foreach ($event_days as $d) $main_time_by_day[$d] = '';

        $session_time_by_day = []; // [sname][day] => 'HH:MM[:SS]'
        foreach ($all_sessions as $sname) {
            $session_time_by_day[$sname] = [];
            foreach ($event_days as $d) $session_time_by_day[$sname][$d] = '';
        }
        
        // NUEVO: Variable para almacenar el tipo de QR del primer check-in
        $qr_type_checkin = '';

        $log = get_post_meta($tid, '_eventosapp_checkin_log', true);
        if (is_string($log)) $log = @unserialize($log);
        if (!is_array($log)) $log = [];

        foreach ($log as $entry) {
            $f = isset($entry['fecha']) ? $entry['fecha'] : '';
            $h = isset($entry['hora'])  ? $entry['hora']  : '';
            $st= isset($entry['status'])? $entry['status']: '';
            $sn= isset($entry['sesion'])? $entry['sesion']: '';

            if (!$f || !$h) continue;

            // principal por día: tomar la PRIMERA hora del día
            if (in_array($f, $event_days, true) && ($st === 'checked_in' || $st === 'checked-in')) {
                $main_time_by_day[$f] = $min_time($main_time_by_day[$f], $h);
                
                // NUEVO: Capturar el tipo de QR del primer check-in principal
                if ($qr_type_checkin === '' && isset($entry['qr_type_label'])) {
                    $qr_type_checkin = $entry['qr_type_label'];
                }
            }

            // sesión por día
            if ($sn && in_array($f, $event_days, true) && ($st === 'session_checked_in')) {
                if (isset($session_time_by_day[$sn])) {
                    $session_time_by_day[$sn][$f] = $min_time($session_time_by_day[$sn][$f], $h);
                }
            }
        }

        // base
        $row[] = (string)$pub_id;
        $row[] = (string)$tid;
        $row[] = (string)$event_id;
        $row[] = (string)$ev_title;
        $row[] = (string)$seq;
        $row[] = (string)$first;
        $row[] = (string)$last;
        $row[] = (string)$cc;
        $row[] = (string)$email;
        $row[] = (string)$tel;
        $row[] = (string)$comp;
        $row[] = (string)$nit;
        $row[] = (string)$role;
        $row[] = (string)$loc;
        $row[] = (string)$any_checked;

        // extras
        if (!empty($extra_fields)) {
            $extras_map = get_post_meta($tid, '_'.$extra_prefix, true);
            if (!is_array($extras_map) || empty($extras_map)) {
                $extras_map = get_post_meta($tid, $extra_prefix, true);
                if (!is_array($extras_map)) $extras_map = [];
            }
            foreach ($extra_fields as $f) {
                $key = $f['key'] ?? '';
                $val = '';
                if ($key !== '') {
                    if (isset($extras_map[$key]) && $extras_map[$key] !== '') {
                        $val = $extras_map[$key];
                    } else {
                        foreach ([$extra_prefix . '_' . $key, '_' . $extra_prefix . '_' . $key] as $mkey) {
                            $tmp = get_post_meta($tid, $mkey, true);
                            if ($tmp !== '' && $tmp !== null) { $val = $tmp; break; }
                        }
                    }
                    if (function_exists('eventosapp_normalize_extra_value')) {
                        $val = eventosapp_normalize_extra_value($f, $val);
                    } else {
                        $val = is_scalar($val) ? (string)$val : '';
                    }
                }
                $row[] = (string)$val;
            }
        }

        // por día: SI/NO + HORA
        foreach ($event_days as $d) {
            $st  = isset($status_arr[$d]) ? $status_arr[$d] : '';
            $row[] = ($st === 'checked_in' || $st === 'checked-in') ? 'SI' : 'NO';
            $row[] = (string)$main_time_by_day[$d];
        }

        // sesiones acceso
        foreach ($all_sessions as $sname) { $row[] = isset($acc_set[$sname]) ? 'SI' : 'NO'; }
        // sesiones check-in (SI/NO)
        foreach ($all_sessions as $sname) {
            $st  = isset($ses[$sname]) ? $ses[$sname] : '';
            $row[] = ($st === 'checked_in' || $st === 'checked-in') ? 'SI' : 'NO';
        }
        // sesiones x día — HORA
        foreach ($all_sessions as $sname) {
            foreach ($event_days as $d) {
                $row[] = (string)$session_time_by_day[$sname][$d];
            }
        }

        $row[] = (string)$created;
        
        // Datos de estado de correo
        $email_status = get_post_meta($tid, '_eventosapp_ticket_email_sent_status', true);
        $row[] = ($email_status === 'enviado') ? 'Enviado' : 'No Enviado';
        
        $first_sent = get_post_meta($tid, '_eventosapp_ticket_email_first_sent', true);
        $row[] = $first_sent ? date_i18n('Y-m-d H:i:s', strtotime($first_sent)) : '';
        
        $last_sent = get_post_meta($tid, '_eventosapp_ticket_last_email_at', true);
        $row[] = $last_sent ? date_i18n('Y-m-d H:i:s', strtotime($last_sent)) : '';
        
        // NUEVO: Agregar medio de check-in
        $row[] = (string)$qr_type_checkin;
        
        $dataRows[] = $row;
    }

    // === 6) Enviar XLSX ===
    $filename = 'tickets_evento_'.$event_id.'_'.date('Ymd_His').'.xlsx';
    eventosapp_stream_xlsx($filename, 'Tickets', $headers, $dataRows);
});
