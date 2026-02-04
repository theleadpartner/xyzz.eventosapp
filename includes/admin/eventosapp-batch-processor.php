<?php
/**
 * EventosApp - Batch Processor
 * Sistema de actualización por lote en segundo plano
 * 
 * @package EventosApp
 * @version 3.0
 */

if (!defined('ABSPATH')) exit;

class EventosApp_Batch_Processor {
    
    private static $instance = null;
    
    const OPTION_PREFIX = 'eventosapp_batch_';
    const BATCH_SIZES = [10, 25, 50, 100, 200];
    const DEFAULT_BATCH_SIZE = 50;
    
    /**
     * Singleton
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu'], 10); // Prioridad 10 para ejecutar después del menú principal (prioridad 9)
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // AJAX Handlers
        add_action('wp_ajax_eventosapp_batch_start', [$this, 'ajax_start_batch']);
        add_action('wp_ajax_eventosapp_batch_process', [$this, 'ajax_process_batch']);
        add_action('wp_ajax_eventosapp_batch_status', [$this, 'ajax_get_status']);
        add_action('wp_ajax_eventosapp_batch_cancel', [$this, 'ajax_cancel_batch']);
    }
    
    /**
     * Agregar menú de administración
     */
    public function add_admin_menu() {
        add_submenu_page(
            'eventosapp_dashboard',          // Parent slug del menú principal de EventosApp
            'Actualización por Lote',        // Page title
            'Actualización por Lote',        // Menu title
            'manage_options',                // Capability
            'eventosapp-batch-update',       // Menu slug
            [$this, 'render_admin_page']    // Callback
        );
    }
    
    /**
     * Encolar scripts y estilos
     */
    public function enqueue_scripts($hook) {
        // El hook de la página será: eventosapp_dashboard_page_eventosapp-batch-update
        if ($hook !== 'eventosapp_dashboard_page_eventosapp-batch-update') {
            return;
        }
        
        wp_enqueue_style(
            'eventosapp-batch-processor',
            plugin_dir_url(__FILE__) . 'css/batch-processor.css',
            [],
            '3.0.0'
        );
        
        wp_enqueue_script(
            'eventosapp-batch-processor',
            plugin_dir_url(__FILE__) . 'js/batch-processor.js',
            ['jquery'],
            '3.0.0',
            true
        );
        
        wp_localize_script('eventosapp-batch-processor', 'eventosappBatch', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('eventosapp_batch_processor'),
            'strings' => [
                'confirmCancel' => '¿Estás seguro de cancelar el proceso? El progreso se perderá.',
                'processing' => 'Procesando...',
                'completed' => 'Completado',
                'cancelled' => 'Cancelado',
                'error' => 'Error'
            ]
        ]);
    }
    
    /**
     * Renderizar página de administración
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos suficientes para acceder a esta página.'));
        }
        
        // Obtener todos los eventos
        $eventos = get_posts([
            'post_type' => 'eventosapp_event',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        
        // Verificar si hay un proceso en curso
        $current_process = $this->get_current_process();
        
        ?>
        <div class="wrap eventosapp-batch-wrapper">
            <h1>
                <span class="dashicons dashicons-update"></span>
                Actualización por Lote de Tickets
            </h1>
            
            <div class="eventosapp-batch-container">
                
                <!-- Panel de Configuración -->
                <div class="eventosapp-batch-config" id="batch-config-panel">
                    <div class="card">
                        <h2>Configuración de Actualización</h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="batch-event-select">Seleccionar Evento</label>
                                </th>
                                <td>
                                    <select id="batch-event-select" class="regular-text" <?php echo $current_process ? 'disabled' : ''; ?>>
                                        <option value="">-- Selecciona un evento --</option>
                                        <?php foreach ($eventos as $evento): ?>
                                            <option value="<?php echo esc_attr($evento->ID); ?>">
                                                <?php echo esc_html($evento->post_title); ?> 
                                                (ID: <?php echo $evento->ID; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        Selecciona el evento cuyos tickets deseas actualizar
                                    </p>
                                    
                                    <div id="ticket-count-info" style="margin-top: 10px; display: none;">
                                        <span class="dashicons dashicons-tickets-alt"></span>
                                        <strong>Tickets encontrados:</strong> 
                                        <span id="ticket-count-number">0</span>
                                    </div>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="batch-mode-select">Modo de Actualización</label>
                                </th>
                                <td>
                                    <fieldset <?php echo $current_process ? 'disabled' : ''; ?>>
                                        <label style="display: block; margin-bottom: 10px;">
                                            <input type="radio" name="batch_mode" id="mode-complete" value="complete" checked>
                                            <strong>Regeneración Completa</strong>
                                            <p class="description" style="margin-left: 25px;">
                                                Regenera TODO: Wallets (Google + Apple), PDF, ICS, QR codes y búsqueda
                                            </p>
                                        </label>
                                        
                                        <label style="display: block;">
                                            <input type="radio" name="batch_mode" id="mode-qr-missing" value="qr_missing">
                                            <strong>Solo QR Faltantes</strong>
                                            <p class="description" style="margin-left: 25px;">
                                                Genera únicamente los QR nuevos que no existen. No toca QR existentes ni legacy.
                                            </p>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="batch-size-select">Tamaño de Lote</label>
                                </th>
                                <td>
                                    <select id="batch-size-select" <?php echo $current_process ? 'disabled' : ''; ?>>
                                        <?php foreach (self::BATCH_SIZES as $size): ?>
                                            <option value="<?php echo $size; ?>" <?php selected($size, self::DEFAULT_BATCH_SIZE); ?>>
                                                <?php echo $size; ?> tickets
                                                <?php if ($size === 10): echo ' (muy seguro)'; ?>
                                                <?php elseif ($size === 25): echo ' (seguro)'; ?>
                                                <?php elseif ($size === 50): echo ' (recomendado)'; ?>
                                                <?php elseif ($size === 100): echo ' (rápido)'; ?>
                                                <?php elseif ($size === 200): echo ' (muy rápido, más carga)'; ?>
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        Número de tickets a procesar por cada lote. Lotes más pequeños son más seguros para el servidor.
                                    </p>
                                </td>
                            </tr>
                        </table>
                        
                        <div class="batch-actions">
                            <button type="button" id="batch-start-btn" class="button button-primary button-hero" <?php echo $current_process ? 'disabled' : ''; ?>>
                                <span class="dashicons dashicons-controls-play"></span>
                                Iniciar Actualización
                            </button>
                            
                            <button type="button" id="batch-cancel-btn" class="button button-secondary" style="display: none;">
                                <span class="dashicons dashicons-no"></span>
                                Cancelar Proceso
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Panel de Progreso -->
                <div class="eventosapp-batch-progress" id="batch-progress-panel" style="<?php echo $current_process ? '' : 'display: none;'; ?>">
                    <div class="card">
                        <h2>
                            <span class="dashicons dashicons-chart-line"></span>
                            Progreso de Actualización
                        </h2>
                        
                        <div class="progress-info">
                            <div class="progress-stat">
                                <span class="label">Estado:</span>
                                <span id="progress-status" class="status-badge status-running">Procesando</span>
                            </div>
                            <div class="progress-stat">
                                <span class="label">Evento:</span>
                                <strong id="progress-event-name">-</strong>
                            </div>
                            <div class="progress-stat">
                                <span class="label">Modo:</span>
                                <strong id="progress-mode">-</strong>
                            </div>
                        </div>
                        
                        <div class="progress-bar-container">
                            <div class="progress-bar-wrapper">
                                <div id="progress-bar" class="progress-bar" style="width: 0%;">
                                    <span id="progress-percentage">0%</span>
                                </div>
                            </div>
                            <div class="progress-numbers">
                                <span id="progress-current">0</span> / <span id="progress-total">0</span> tickets procesados
                            </div>
                        </div>
                        
                        <div class="progress-details">
                            <div class="detail-row">
                                <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                                <strong>Exitosos:</strong> <span id="progress-success">0</span>
                            </div>
                            <div class="detail-row">
                                <span class="dashicons dashicons-warning" style="color: #f56e28;"></span>
                                <strong>Omitidos:</strong> <span id="progress-skipped">0</span>
                            </div>
                            <div class="detail-row">
                                <span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span>
                                <strong>Errores:</strong> <span id="progress-failed">0</span>
                            </div>
                        </div>
                        
                        <div class="time-info">
                            <div class="time-stat">
                                <span class="dashicons dashicons-clock"></span>
                                <strong>Tiempo transcurrido:</strong> <span id="elapsed-time">00:00</span>
                            </div>
                            <div class="time-stat">
                                <span class="dashicons dashicons-backup"></span>
                                <strong>Tiempo estimado:</strong> <span id="estimated-time">Calculando...</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Panel de Logs -->
                <div class="eventosapp-batch-logs" id="batch-logs-panel">
                    <div class="card">
                        <h2>
                            <span class="dashicons dashicons-text-page"></span>
                            Registro de Actividad
                        </h2>
                        
                        <div class="log-controls">
                            <button type="button" id="log-clear-btn" class="button button-small">
                                <span class="dashicons dashicons-trash"></span>
                                Limpiar Log
                            </button>
                            <button type="button" id="log-auto-scroll-btn" class="button button-small active">
                                <span class="dashicons dashicons-arrow-down-alt"></span>
                                Auto-scroll: ON
                            </button>
                        </div>
                        
                        <div id="batch-log-container" class="log-container">
                            <div class="log-entry log-info">
                                <span class="log-time"><?php echo current_time('H:i:s'); ?></span>
                                <span class="log-message">Sistema de actualización por lote listo. Selecciona un evento para comenzar.</span>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
        
        <style>
        .eventosapp-batch-wrapper {
            margin: 20px 20px 20px 0;
        }
        
        .eventosapp-batch-wrapper h1 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 23px;
            font-weight: 400;
            margin: 0 0 20px 0;
            padding: 9px 0;
        }
        
        .eventosapp-batch-wrapper h1 .dashicons {
            font-size: 28px;
            width: 28px;
            height: 28px;
        }
        
        .eventosapp-batch-container {
            display: grid;
            gap: 20px;
        }
        
        .card {
            background: #fff;
            border: 1px solid #c3c4c7;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 20px;
        }
        
        .card h2 {
            margin: 0 0 20px 0;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .batch-actions {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #dcdcde;
            display: flex;
            gap: 10px;
        }
        
        .button-hero {
            padding: 8px 16px !important;
            height: auto !important;
            font-size: 14px !important;
        }
        
        .button .dashicons {
            margin-top: 2px;
        }
        
        #ticket-count-info {
            padding: 10px;
            background: #f0f6fc;
            border-left: 4px solid #0073aa;
            border-radius: 3px;
        }
        
        .progress-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f6f7f7;
            border-radius: 4px;
        }
        
        .progress-stat {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .progress-stat .label {
            font-size: 12px;
            color: #646970;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-running {
            background: #fff8e5;
            color: #996800;
        }
        
        .status-completed {
            background: #d1fae5;
            color: #047857;
        }
        
        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .progress-bar-container {
            margin: 20px 0;
        }
        
        .progress-bar-wrapper {
            position: relative;
            height: 30px;
            background: #f0f0f1;
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid #c3c4c7;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #2271b1 0%, #135e96 100%);
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .progress-bar span {
            color: #fff;
            font-weight: 600;
            font-size: 13px;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }
        
        .progress-numbers {
            margin-top: 8px;
            text-align: center;
            font-size: 14px;
            color: #50575e;
        }
        
        .progress-details {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 20px 0;
            padding: 15px;
            background: #f6f7f7;
            border-radius: 4px;
        }
        
        .detail-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .time-info {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            padding-top: 15px;
            border-top: 1px solid #dcdcde;
        }
        
        .time-stat {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }
        
        .log-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .log-controls .button-small {
            font-size: 12px;
            height: 28px;
            line-height: 26px;
            padding: 0 10px;
        }
        
        #log-auto-scroll-btn.active {
            background: #2271b1;
            color: #fff;
            border-color: #2271b1;
        }
        
        .log-container {
            max-height: 400px;
            overflow-y: auto;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 15px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.6;
        }
        
        .log-entry {
            margin-bottom: 5px;
            padding: 4px 0;
        }
        
        .log-time {
            color: #858585;
            margin-right: 10px;
        }
        
        .log-info .log-message {
            color: #4ec9b0;
        }
        
        .log-success .log-message {
            color: #6a9955;
        }
        
        .log-warning .log-message {
            color: #dcdcaa;
        }
        
        .log-error .log-message {
            color: #f48771;
        }
        
        .log-batch .log-message {
            color: #9cdcfe;
            font-weight: bold;
        }
        
        /* Scrollbar personalizado para el log */
        .log-container::-webkit-scrollbar {
            width: 8px;
        }
        
        .log-container::-webkit-scrollbar-track {
            background: #2d2d2d;
        }
        
        .log-container::-webkit-scrollbar-thumb {
            background: #555;
            border-radius: 4px;
        }
        
        .log-container::-webkit-scrollbar-thumb:hover {
            background: #777;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Variables globales
            let currentProcess = null;
            let autoScroll = true;
            let startTime = null;
            let timerInterval = null;
            
            // Verificar si hay un proceso en curso al cargar
            <?php if ($current_process): ?>
            loadCurrentProcess(<?php echo json_encode($current_process); ?>);
            <?php endif; ?>
            
            // Evento: Seleccionar evento
            $('#batch-event-select').on('change', function() {
                const eventId = $(this).val();
                if (!eventId) {
                    $('#ticket-count-info').hide();
                    return;
                }
                
                // Obtener cantidad de tickets
                $.post(eventosappBatch.ajaxUrl, {
                    action: 'eventosapp_batch_status',
                    nonce: eventosappBatch.nonce,
                    task: 'count_tickets',
                    event_id: eventId
                }, function(response) {
                    if (response.success) {
                        $('#ticket-count-number').text(response.data.count);
                        $('#ticket-count-info').show();
                    }
                });
            });
            
            // Evento: Iniciar proceso
            $('#batch-start-btn').on('click', function() {
                const eventId = $('#batch-event-select').val();
                const mode = $('input[name="batch_mode"]:checked').val();
                const batchSize = $('#batch-size-select').val();
                
                if (!eventId) {
                    alert('Por favor selecciona un evento');
                    return;
                }
                
                if (!confirm('¿Iniciar actualización por lote?\n\nEvento seleccionado: ' + $('#batch-event-select option:selected').text() + '\nModo: ' + (mode === 'complete' ? 'Regeneración Completa' : 'Solo QR Faltantes'))) {
                    return;
                }
                
                startBatchProcess(eventId, mode, batchSize);
            });
            
            // Evento: Cancelar proceso
            $('#batch-cancel-btn').on('click', function() {
                if (!confirm(eventosappBatch.strings.confirmCancel)) {
                    return;
                }
                
                cancelBatchProcess();
            });
            
            // Evento: Limpiar log
            $('#log-clear-btn').on('click', function() {
                $('#batch-log-container').empty();
                addLog('info', 'Log limpiado manualmente');
            });
            
            // Evento: Toggle auto-scroll
            $('#log-auto-scroll-btn').on('click', function() {
                autoScroll = !autoScroll;
                $(this).toggleClass('active', autoScroll);
                $(this).html('<span class="dashicons dashicons-arrow-down-alt"></span> Auto-scroll: ' + (autoScroll ? 'ON' : 'OFF'));
            });
            
            /**
             * Iniciar proceso de actualización por lote
             */
            function startBatchProcess(eventId, mode, batchSize) {
                addLog('batch', '═══════════════════════════════════════════════════');
                addLog('batch', 'INICIANDO PROCESO DE ACTUALIZACIÓN POR LOTE');
                addLog('batch', '═══════════════════════════════════════════════════');
                
                $.post(eventosappBatch.ajaxUrl, {
                    action: 'eventosapp_batch_start',
                    nonce: eventosappBatch.nonce,
                    event_id: eventId,
                    mode: mode,
                    batch_size: batchSize
                }, function(response) {
                    if (response.success) {
                        currentProcess = response.data;
                        startTime = new Date();
                        
                        addLog('success', 'Proceso iniciado correctamente');
                        addLog('info', 'Evento: ' + $('#batch-event-select option:selected').text());
                        addLog('info', 'Modo: ' + (mode === 'complete' ? 'Regeneración Completa' : 'Solo QR Faltantes'));
                        addLog('info', 'Total de tickets: ' + currentProcess.total);
                        addLog('info', 'Tamaño de lote: ' + batchSize);
                        
                        updateUI();
                        processNextBatch();
                    } else {
                        addLog('error', 'Error al iniciar: ' + (response.data ? response.data.message : 'Desconocido'));
                    }
                }).fail(function() {
                    addLog('error', 'Error de conexión al iniciar el proceso');
                });
            }
            
            /**
             * Procesar siguiente lote
             */
            function processNextBatch() {
                if (!currentProcess) return;
                
                const batchNumber = Math.floor(currentProcess.processed / currentProcess.batch_size) + 1;
                addLog('batch', '--- Procesando lote ' + batchNumber + ' ---');
                
                $.post(eventosappBatch.ajaxUrl, {
                    action: 'eventosapp_batch_process',
                    nonce: eventosappBatch.nonce,
                    process_id: currentProcess.id
                }, function(response) {
                    if (response.success) {
                        currentProcess = response.data;
                        
                        // Actualizar estadísticas
                        updateProgress();
                        
                        // Logs del lote
                        if (response.data.batch_log && response.data.batch_log.length > 0) {
                            response.data.batch_log.forEach(function(log) {
                                addLog(log.type, log.message);
                            });
                        }
                        
                        addLog('success', 'Lote ' + batchNumber + ' completado: ' + response.data.last_batch_processed + ' tickets procesados');
                        
                        // Continuar o finalizar
                        if (currentProcess.status === 'completed') {
                            finishProcess();
                        } else if (currentProcess.status === 'processing') {
                            // Pequeña pausa entre lotes para no saturar
                            setTimeout(processNextBatch, 500);
                        }
                    } else {
                        addLog('error', 'Error procesando lote: ' + (response.data ? response.data.message : 'Desconocido'));
                        currentProcess.status = 'error';
                        updateUI();
                    }
                }).fail(function() {
                    addLog('error', 'Error de conexión durante el procesamiento');
                    currentProcess.status = 'error';
                    updateUI();
                });
            }
            
            /**
             * Cancelar proceso
             */
            function cancelBatchProcess() {
                if (!currentProcess) return;
                
                addLog('warning', 'Cancelando proceso...');
                
                $.post(eventosappBatch.ajaxUrl, {
                    action: 'eventosapp_batch_cancel',
                    nonce: eventosappBatch.nonce,
                    process_id: currentProcess.id
                }, function(response) {
                    if (response.success) {
                        addLog('warning', 'Proceso cancelado correctamente');
                        currentProcess = null;
                        if (timerInterval) {
                            clearInterval(timerInterval);
                        }
                        updateUI();
                    }
                });
            }
            
            /**
             * Finalizar proceso
             */
            function finishProcess() {
                if (timerInterval) {
                    clearInterval(timerInterval);
                }
                
                addLog('batch', '═══════════════════════════════════════════════════');
                addLog('batch', 'PROCESO COMPLETADO');
                addLog('batch', '═══════════════════════════════════════════════════');
                addLog('success', 'Total procesados: ' + currentProcess.processed);
                addLog('success', 'Exitosos: ' + currentProcess.success);
                addLog('warning', 'Omitidos: ' + currentProcess.skipped);
                addLog('error', 'Errores: ' + currentProcess.failed);
                
                const duration = Math.floor((new Date() - startTime) / 1000);
                const minutes = Math.floor(duration / 60);
                const seconds = duration % 60;
                addLog('info', 'Tiempo total: ' + minutes + 'm ' + seconds + 's');
                
                updateUI();
            }
            
            /**
             * Cargar proceso en curso
             */
            function loadCurrentProcess(process) {
                currentProcess = process;
                startTime = new Date(process.start_time * 1000);
                updateUI();
                updateProgress();
                
                if (process.status === 'processing') {
                    processNextBatch();
                }
            }
            
            /**
             * Actualizar UI
             */
            function updateUI() {
                if (currentProcess) {
                    // Deshabilitar configuración
                    $('#batch-event-select, input[name="batch_mode"], #batch-size-select, #batch-start-btn').prop('disabled', true);
                    $('#batch-cancel-btn').show();
                    $('#batch-progress-panel').show();
                    
                    // Actualizar información del evento
                    $('#progress-event-name').text($('#batch-event-select option:selected').text());
                    $('#progress-mode').text(currentProcess.mode === 'complete' ? 'Regeneración Completa' : 'Solo QR Faltantes');
                    
                    // Estado
                    const statusBadge = $('#progress-status');
                    statusBadge.removeClass('status-running status-completed status-cancelled status-error');
                    
                    if (currentProcess.status === 'processing') {
                        statusBadge.addClass('status-running').text('Procesando');
                        startTimer();
                    } else if (currentProcess.status === 'completed') {
                        statusBadge.addClass('status-completed').text('Completado');
                    } else if (currentProcess.status === 'cancelled') {
                        statusBadge.addClass('status-cancelled').text('Cancelado');
                    } else if (currentProcess.status === 'error') {
                        statusBadge.addClass('status-error').text('Error');
                    }
                } else {
                    // Habilitar configuración
                    $('#batch-event-select, input[name="batch_mode"], #batch-size-select, #batch-start-btn').prop('disabled', false);
                    $('#batch-cancel-btn').hide();
                    
                    // Resetear progreso
                    $('#progress-bar').css('width', '0%');
                    $('#progress-percentage').text('0%');
                    $('#progress-current, #progress-total, #progress-success, #progress-skipped, #progress-failed').text('0');
                }
            }
            
            /**
             * Actualizar progreso
             */
            function updateProgress() {
                if (!currentProcess) return;
                
                const percentage = currentProcess.total > 0 ? Math.round((currentProcess.processed / currentProcess.total) * 100) : 0;
                
                $('#progress-bar').css('width', percentage + '%');
                $('#progress-percentage').text(percentage + '%');
                $('#progress-current').text(currentProcess.processed);
                $('#progress-total').text(currentProcess.total);
                $('#progress-success').text(currentProcess.success);
                $('#progress-skipped').text(currentProcess.skipped);
                $('#progress-failed').text(currentProcess.failed);
                
                // Calcular tiempo estimado
                if (currentProcess.processed > 0 && startTime) {
                    const elapsed = (new Date() - startTime) / 1000;
                    const rate = currentProcess.processed / elapsed;
                    const remaining = currentProcess.total - currentProcess.processed;
                    const estimatedSeconds = Math.ceil(remaining / rate);
                    
                    const estMinutes = Math.floor(estimatedSeconds / 60);
                    const estSeconds = estimatedSeconds % 60;
                    $('#estimated-time').text(estMinutes + 'm ' + estSeconds + 's');
                }
            }
            
            /**
             * Iniciar timer
             */
            function startTimer() {
                if (timerInterval) {
                    clearInterval(timerInterval);
                }
                
                timerInterval = setInterval(function() {
                    if (!startTime) return;
                    
                    const elapsed = Math.floor((new Date() - startTime) / 1000);
                    const minutes = Math.floor(elapsed / 60);
                    const seconds = elapsed % 60;
                    $('#elapsed-time').text(
                        (minutes < 10 ? '0' : '') + minutes + ':' + 
                        (seconds < 10 ? '0' : '') + seconds
                    );
                }, 1000);
            }
            
            /**
             * Agregar entrada al log
             */
            function addLog(type, message) {
                const time = new Date().toTimeString().split(' ')[0];
                const entry = $('<div class="log-entry log-' + type + '">' +
                    '<span class="log-time">[' + time + ']</span>' +
                    '<span class="log-message">' + message + '</span>' +
                    '</div>');
                
                $('#batch-log-container').append(entry);
                
                if (autoScroll) {
                    const container = document.getElementById('batch-log-container');
                    container.scrollTop = container.scrollHeight;
                }
            }
        });
        </script>
        <?php
    }
    
    /**
     * AJAX: Iniciar proceso por lote
     */
    public function ajax_start_batch() {
        check_ajax_referer('eventosapp_batch_processor', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
        }
        
        $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
        $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'complete';
        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : self::DEFAULT_BATCH_SIZE;
        
        if (!$event_id) {
            wp_send_json_error(['message' => 'ID de evento inválido']);
        }
        
        if (!in_array($mode, ['complete', 'qr_missing'], true)) {
            $mode = 'complete';
        }
        
        if (!in_array($batch_size, self::BATCH_SIZES, true)) {
            $batch_size = self::DEFAULT_BATCH_SIZE;
        }
        
        // Verificar si ya hay un proceso activo
        $active = $this->get_current_process();
        if ($active && $active['status'] === 'processing') {
            wp_send_json_error(['message' => 'Ya hay un proceso en ejecución']);
        }
        
        // Contar tickets del evento
        $total = $this->count_tickets_by_event($event_id);
        
        if ($total === 0) {
            wp_send_json_error(['message' => 'No se encontraron tickets para este evento']);
        }
        
        // Crear nuevo proceso
        $process_id = 'batch_' . $event_id . '_' . time();
        $process_data = [
            'id' => $process_id,
            'event_id' => $event_id,
            'mode' => $mode,
            'batch_size' => $batch_size,
            'total' => $total,
            'processed' => 0,
            'success' => 0,
            'skipped' => 0,
            'failed' => 0,
            'current_offset' => 0,
            'status' => 'processing',
            'start_time' => time(),
            'last_update' => time()
        ];
        
        update_option(self::OPTION_PREFIX . 'current', $process_data);
        
        wp_send_json_success($process_data);
    }
    
    /**
     * AJAX: Procesar siguiente lote
     */
    public function ajax_process_batch() {
        check_ajax_referer('eventosapp_batch_processor', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
        }
        
        $process = $this->get_current_process();
        
        if (!$process) {
            wp_send_json_error(['message' => 'No hay proceso activo']);
        }
        
        if ($process['status'] !== 'processing') {
            wp_send_json_error(['message' => 'El proceso no está en ejecución']);
        }
        
        // Obtener tickets del lote actual
        $tickets = $this->get_tickets_batch(
            $process['event_id'],
            $process['current_offset'],
            $process['batch_size']
        );
        
        $batch_log = [];
        $batch_success = 0;
        $batch_skipped = 0;
        $batch_failed = 0;
        
        foreach ($tickets as $ticket_id) {
            $result = $this->process_ticket($ticket_id, $process['mode']);
            
            if ($result['status'] === 'success') {
                $batch_success++;
                $batch_log[] = [
                    'type' => 'success',
                    'message' => "✓ Ticket #{$ticket_id} procesado correctamente"
                ];
            } elseif ($result['status'] === 'skipped') {
                $batch_skipped++;
                $batch_log[] = [
                    'type' => 'warning',
                    'message' => "⊘ Ticket #{$ticket_id} omitido: " . $result['message']
                ];
            } else {
                $batch_failed++;
                $batch_log[] = [
                    'type' => 'error',
                    'message' => "✗ Ticket #{$ticket_id} falló: " . $result['message']
                ];
            }
        }
        
        // Actualizar proceso
        $process['processed'] += count($tickets);
        $process['success'] += $batch_success;
        $process['skipped'] += $batch_skipped;
        $process['failed'] += $batch_failed;
        $process['current_offset'] += $process['batch_size'];
        $process['last_update'] = time();
        $process['last_batch_processed'] = count($tickets);
        $process['batch_log'] = $batch_log;
        
        // Verificar si terminamos
        if ($process['processed'] >= $process['total']) {
            $process['status'] = 'completed';
            $process['end_time'] = time();
        }
        
        update_option(self::OPTION_PREFIX . 'current', $process);
        
        wp_send_json_success($process);
    }
    
    /**
     * AJAX: Obtener estado del proceso
     */
    public function ajax_get_status() {
        check_ajax_referer('eventosapp_batch_processor', 'nonce');
        
        $task = isset($_POST['task']) ? sanitize_text_field($_POST['task']) : '';
        
        if ($task === 'count_tickets') {
            $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
            $count = $this->count_tickets_by_event($event_id);
            wp_send_json_success(['count' => $count]);
        }
        
        $process = $this->get_current_process();
        wp_send_json_success($process);
    }
    
    /**
     * AJAX: Cancelar proceso
     */
    public function ajax_cancel_batch() {
        check_ajax_referer('eventosapp_batch_processor', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
        }
        
        delete_option(self::OPTION_PREFIX . 'current');
        wp_send_json_success(['message' => 'Proceso cancelado']);
    }
    
    /**
     * Obtener proceso actual
     */
    private function get_current_process() {
        return get_option(self::OPTION_PREFIX . 'current', null);
    }
    
    /**
     * Contar tickets por evento
     */
    private function count_tickets_by_event($event_id) {
        global $wpdb;
        
        $count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_type = 'eventosapp_ticket'
            AND p.post_status <> 'auto-draft'
            AND pm.meta_key = '_eventosapp_ticket_evento_id'
            AND pm.meta_value = %d
        ", $event_id));
        
        return absint($count);
    }
    
    /**
     * Obtener lote de tickets
     */
    private function get_tickets_batch($event_id, $offset, $limit) {
        global $wpdb;
        
        $tickets = $wpdb->get_col($wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_type = 'eventosapp_ticket'
            AND p.post_status <> 'auto-draft'
            AND pm.meta_key = '_eventosapp_ticket_evento_id'
            AND pm.meta_value = %d
            ORDER BY p.ID ASC
            LIMIT %d OFFSET %d
        ", $event_id, $limit, $offset));
        
        return array_map('absint', $tickets);
    }
    
    /**
     * Procesar un ticket individual
     */
    private function process_ticket($ticket_id, $mode) {
        // Importar la función de batch-refresh si existe
        if (function_exists('eventosapp_refresh_ticket_full')) {
            try {
                $result = eventosapp_refresh_ticket_full($ticket_id, $mode);
                
                if ($result === true) {
                    return ['status' => 'success'];
                } elseif (is_array($result)) {
                    // Modo qr_missing retorna estadísticas
                    if (isset($result['generated']) && $result['generated'] > 0) {
                        return ['status' => 'success'];
                    } elseif (isset($result['skipped']) && $result['skipped'] > 0) {
                        return ['status' => 'skipped', 'message' => 'QR ya existían'];
                    }
                }
                
                return ['status' => 'success'];
                
            } catch (Exception $e) {
                return ['status' => 'error', 'message' => $e->getMessage()];
            }
        }
        
        return ['status' => 'error', 'message' => 'Función de procesamiento no disponible'];
    }
}

// Inicializar
EventosApp_Batch_Processor::get_instance();
