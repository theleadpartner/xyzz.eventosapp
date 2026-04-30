<?php
/**
 * EventosApp - Batch Processor
 * Sistema de actualización por lote en segundo plano
 * 
 * @package EventosApp
 * @version 4.0.0 - Refresh personalizado con selección de recursos
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
        add_action('admin_menu', [$this, 'add_admin_menu'], 10);
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
            'eventosapp_dashboard',
            'Actualización por Lote',
            'Actualización por Lote',
            'manage_options',
            'eventosapp-batch-update',
            [$this, 'render_admin_page']
        );
    }
    
    /**
     * Encolar scripts y estilos
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'eventosapp_dashboard_page_eventosapp-batch-update') {
            return;
        }
        
        wp_enqueue_script('jquery');
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
        
        // Recursos disponibles para refresh personalizado.
        // La función principal vive en eventosapp-batch-refresh.php; se deja fallback para evitar errores si el archivo aún no cargó.
        $available_assets = function_exists('eventosapp_batch_refresh_available_assets')
            ? eventosapp_batch_refresh_available_assets()
            : [
                'qrs'            => ['label' => 'Todos los QR', 'description' => 'Regenera todos los QR del ticket.'],
                'pdf'            => ['label' => 'PDF', 'description' => 'Regenera el PDF del ticket.'],
                'ics'            => ['label' => 'ICS', 'description' => 'Regenera el archivo calendario ICS.'],
                'android_wallet' => ['label' => 'Android Wallet / Google Wallet', 'description' => 'Regenera el enlace de Google Wallet.'],
                'apple_wallet'   => ['label' => 'Apple Wallet', 'description' => 'Regenera el archivo PKPASS de Apple Wallet.'],
                'search_blob'    => ['label' => 'Índice de búsqueda', 'description' => 'Reconstruye el índice interno de búsqueda.'],
            ];

        $custom_asset_keys = ['qrs', 'pdf', 'ics', 'android_wallet', 'apple_wallet', 'search_blob'];
        
        // Crear nonce
        $nonce = wp_create_nonce('eventosapp_batch_processor');
        $ajax_url = admin_url('admin-ajax.php');
        
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
                                                Regenera TODO lo activo para el evento: Wallets (Google + Apple), PDF, ICS, QR codes e índice de búsqueda. Si ya existe un enlace, se conserva.
                                            </p>
                                        </label>
                                        
                                        <label style="display: block; margin-bottom: 10px;">
                                            <input type="radio" name="batch_mode" id="mode-qr-missing" value="qr_missing">
                                            <strong>Solo QR Faltantes</strong>
                                            <p class="description" style="margin-left: 25px;">
                                                Crea únicamente los QR que falten o cuyo archivo físico ya no exista. No toca QR existentes válidos.
                                            </p>
                                        </label>

                                        <label style="display: block;">
                                            <input type="radio" name="batch_mode" id="mode-custom" value="custom">
                                            <strong>Refresh Personalizado</strong>
                                            <p class="description" style="margin-left: 25px;">
                                                Permite escoger exactamente qué recursos regenerar: QRs, PDF, ICS, Android Wallet, Apple Wallet e índice de búsqueda.
                                            </p>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>

                            <tr id="custom-assets-row" style="display: none;">
                                <th scope="row">
                                    <label>Recursos a regenerar</label>
                                </th>
                                <td>
                                    <div id="batch-custom-assets-panel" class="batch-custom-assets-panel">
                                        <?php foreach ($custom_asset_keys as $asset_key): ?>
                                            <?php if (empty($available_assets[$asset_key])) continue; ?>
                                            <?php
                                            $asset_label = isset($available_assets[$asset_key]['label']) ? $available_assets[$asset_key]['label'] : $asset_key;
                                            $asset_description = isset($available_assets[$asset_key]['description']) ? $available_assets[$asset_key]['description'] : '';
                                            ?>
                                            <label class="batch-asset-option">
                                                <input type="checkbox" class="batch-custom-asset" name="batch_assets[]" value="<?php echo esc_attr($asset_key); ?>" checked <?php echo $current_process ? 'disabled' : ''; ?>>
                                                <span>
                                                    <strong><?php echo esc_html($asset_label); ?></strong>
                                                    <?php if ($asset_description): ?>
                                                        <small><?php echo esc_html($asset_description); ?></small>
                                                    <?php endif; ?>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="description">
                                        En refresh personalizado solo se procesarán los recursos marcados. Si el archivo no existe se crea; si ya existe se regenera conservando la URL pública previa.
                                    </p>
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
                            
                            <button type="button" id="batch-new-btn" class="button button-primary button-hero" style="display: none;">
                                <span class="dashicons dashicons-update"></span>
                                Procesar Nuevo Evento
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
        
        .batch-custom-assets-panel {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 10px;
            margin: 8px 0 10px;
        }

        .batch-asset-option {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 12px;
            background: #f6f7f7;
            border: 1px solid #dcdcde;
            border-radius: 4px;
        }

        .batch-asset-option input {
            margin-top: 2px;
        }

        .batch-asset-option span {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .batch-asset-option small {
            color: #646970;
            line-height: 1.35;
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
            console.log('EventosApp Batch Processor - Iniciando...');
            
            // Variables globales
            let currentProcess = null;
            let autoScroll = true;
            let startTime = null;
            let timerInterval = null;
            
            const ajaxUrl = <?php echo json_encode($ajax_url); ?>;
            const nonce = <?php echo json_encode($nonce); ?>;
            const availableAssets = <?php echo wp_json_encode($available_assets); ?>;
            
            console.log('AJAX URL:', ajaxUrl);
            console.log('Nonce:', nonce);
            
            // Verificar si hay un proceso en curso al cargar
            <?php if ($current_process): ?>
            console.log('Proceso existente encontrado:', <?php echo json_encode($current_process); ?>);
            loadCurrentProcess(<?php echo json_encode($current_process); ?>);
            <?php endif; ?>
            
            // Evento: Seleccionar evento
            $('#batch-event-select').on('change', function() {
                const eventId = $(this).val();
                console.log('Evento seleccionado:', eventId);
                
                if (!eventId) {
                    $('#ticket-count-info').hide();
                    return;
                }
                
                // Obtener cantidad de tickets
                $.post(ajaxUrl, {
                    action: 'eventosapp_batch_status',
                    nonce: nonce,
                    task: 'count_tickets',
                    event_id: eventId
                }, function(response) {
                    console.log('Respuesta count tickets:', response);
                    if (response.success) {
                        $('#ticket-count-number').text(response.data.count);
                        $('#ticket-count-info').show();
                    }
                }).fail(function(xhr, status, error) {
                    console.error('Error contando tickets:', error);
                    addLog('error', 'Error al contar tickets: ' + error);
                });
            });

            // Evento: Cambiar modo para mostrar/ocultar recursos personalizados
            $('input[name="batch_mode"]').on('change', function() {
                toggleCustomAssets();
            });

            toggleCustomAssets();
            
            // Evento: Iniciar proceso
            $('#batch-start-btn').on('click', function() {
                console.log('Click en Iniciar Actualización');
                
                const eventId = $('#batch-event-select').val();
                const mode = $('input[name="batch_mode"]:checked').val();
                const batchSize = $('#batch-size-select').val();
                const selectedAssets = mode === 'custom' ? getSelectedAssets() : [];
                
                console.log('Parámetros:', { eventId, mode, batchSize, selectedAssets });
                
                if (!eventId) {
                    alert('Por favor selecciona un evento');
                    return;
                }

                if (mode === 'custom' && selectedAssets.length === 0) {
                    alert('Selecciona al menos un recurso para ejecutar el refresh personalizado.');
                    return;
                }
                
                const eventName = $('#batch-event-select option:selected').text();
                const modeName = getModeName(mode, selectedAssets);
                let confirmMessage = '¿Iniciar actualización por lote?\n\nEvento: ' + eventName + '\nModo: ' + modeName;

                if (mode === 'custom') {
                    confirmMessage += '\nRecursos: ' + getAssetLabels(selectedAssets).join(', ');
                }
                
                if (!confirm(confirmMessage)) {
                    return;
                }
                
                startBatchProcess(eventId, mode, batchSize, selectedAssets);
            });
            
            // Evento: Cancelar proceso
            $('#batch-cancel-btn').on('click', function() {
                if (!confirm('¿Estás seguro de cancelar el proceso? El progreso se perderá.')) {
                    return;
                }
                
                cancelBatchProcess();
            });
            
            // Evento: Procesar Nuevo Evento
            $('#batch-new-btn').on('click', function() {
                console.log('Click en Procesar Nuevo Evento');
                resetProcess();
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
            function startBatchProcess(eventId, mode, batchSize, selectedAssets) {
                selectedAssets = Array.isArray(selectedAssets) ? selectedAssets : [];
                console.log('=== INICIANDO PROCESO ===');
                console.log('Event ID:', eventId);
                console.log('Mode:', mode);
                console.log('Batch Size:', batchSize);
                console.log('Selected Assets:', selectedAssets);
                
                addLog('batch', '═══════════════════════════════════════════════════');
                addLog('batch', 'INICIANDO PROCESO DE ACTUALIZACIÓN POR LOTE');
                addLog('batch', '═══════════════════════════════════════════════════');
                
                // Deshabilitar botón mientras procesa
                $('#batch-start-btn').prop('disabled', true).text('Iniciando...');
                
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'eventosapp_batch_start',
                        nonce: nonce,
                        event_id: eventId,
                        mode: mode,
                        batch_size: batchSize,
                        assets: selectedAssets
                    },
                    success: function(response) {
                        console.log('Respuesta de inicio:', response);
                        
                        if (response.success) {
                            currentProcess = response.data;
                            startTime = new Date();
                            
                            addLog('success', 'Proceso iniciado correctamente');
                            addLog('info', 'Evento: ' + $('#batch-event-select option:selected').text());
                            addLog('info', 'Modo: ' + getModeName(currentProcess.mode, currentProcess.assets || selectedAssets));
                            if (currentProcess.mode === 'custom') {
                                addLog('info', 'Recursos seleccionados: ' + getAssetLabels(currentProcess.assets || selectedAssets).join(', '));
                            }
                            addLog('info', 'Total de tickets: ' + currentProcess.total);
                            addLog('info', 'Tamaño de lote: ' + batchSize);
                            
                            console.log('Mostrando UI y comenzando proceso...');
                            updateUI();
                            
                            // Pequeña pausa antes de comenzar el primer lote
                            setTimeout(function() {
                                processNextBatch();
                            }, 500);
                        } else {
                            addLog('error', 'Error al iniciar: ' + (response.data && response.data.message ? response.data.message : 'Desconocido'));
                            $('#batch-start-btn').prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> Iniciar Actualización');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error AJAX al iniciar:', error);
                        console.error('XHR:', xhr);
                        console.error('Status:', status);
                        console.error('Response Text:', xhr.responseText);
                        
                        addLog('error', 'Error de conexión al iniciar el proceso: ' + error);
                        $('#batch-start-btn').prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> Iniciar Actualización');
                    }
                });
            }
            
            /**
             * Procesar siguiente lote
             */
            function processNextBatch() {
                if (!currentProcess) {
                    console.error('No hay proceso activo');
                    return;
                }
                
                const batchNumber = Math.floor(currentProcess.processed / currentProcess.batch_size) + 1;
                console.log('Procesando lote ' + batchNumber);
                addLog('batch', '--- Procesando lote ' + batchNumber + ' ---');
                
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'eventosapp_batch_process',
                        nonce: nonce,
                        process_id: currentProcess.id
                    },
                    success: function(response) {
                        console.log('Respuesta de lote:', response);
                        
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
                                console.log('Proceso completado');
                                finishProcess();
                            } else if (currentProcess.status === 'processing') {
                                // Pequeña pausa entre lotes para no saturar
                                setTimeout(processNextBatch, 500);
                            }
                        } else {
                            addLog('error', 'Error procesando lote: ' + (response.data && response.data.message ? response.data.message : 'Desconocido'));
                            currentProcess.status = 'error';
                            updateUI();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error AJAX procesando lote:', error);
                        console.error('Response Text:', xhr.responseText);
                        
                        addLog('error', 'Error de conexión durante el procesamiento: ' + error);
                        if (currentProcess) {
                            currentProcess.status = 'error';
                            updateUI();
                        }
                    }
                });
            }
            
            /**
             * Cancelar proceso
             */
            function cancelBatchProcess() {
                if (!currentProcess) return;
                
                console.log('Cancelando proceso...');
                addLog('warning', 'Cancelando proceso...');
                
                $.post(ajaxUrl, {
                    action: 'eventosapp_batch_cancel',
                    nonce: nonce,
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
             * Resetear proceso para comenzar uno nuevo
             */
            function resetProcess() {
                console.log('Reseteando proceso para iniciar uno nuevo...');
                
                // Cancelar proceso actual en el servidor
                if (currentProcess && currentProcess.id) {
                    $.post(ajaxUrl, {
                        action: 'eventosapp_batch_cancel',
                        nonce: nonce,
                        process_id: currentProcess.id
                    });
                }
                
                // Limpiar timer si existe
                if (timerInterval) {
                    clearInterval(timerInterval);
                    timerInterval = null;
                }
                
                // Resetear variables
                currentProcess = null;
                startTime = null;
                
                // Limpiar selección de evento
                $('#batch-event-select').val('').trigger('change');
                $('#ticket-count-info').hide();
                
                // Resetear modo a completo
                $('#mode-complete').prop('checked', true);
                $('.batch-custom-asset').prop('checked', true);
                toggleCustomAssets();
                
                // Resetear tamaño de lote al recomendado
                $('#batch-size-select').val('50');
                
                // Limpiar log
                $('#batch-log-container').empty();
                addLog('info', 'Sistema reseteado. Listo para procesar un nuevo evento.');
                
                // Ocultar panel de progreso con animación suave
                $('#batch-progress-panel').fadeOut(300, function() {
                    // Resetear valores del progreso
                    $('#progress-bar').css('width', '0%');
                    $('#progress-percentage').text('0%');
                    $('#progress-current, #progress-total, #progress-success, #progress-skipped, #progress-failed').text('0');
                    $('#elapsed-time').text('00:00');
                    $('#estimated-time').text('Calculando...');
                    $('#progress-event-name').text('-');
                    $('#progress-mode').text('-');
                });
                
                // Actualizar UI para mostrar estado inicial
                updateUI();
                
                console.log('Reset completado');
            }
            
            /**
             * Finalizar proceso
             */
            function finishProcess() {
                console.log('Finalizando proceso...');
                
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
                console.log('Cargando proceso existente:', process);
                currentProcess = process;
                startTime = new Date(process.start_time * 1000);

                if (process.event_id) {
                    $('#batch-event-select').val(String(process.event_id));
                }

                if (process.mode) {
                    $('input[name="batch_mode"][value="' + process.mode + '"]').prop('checked', true);
                }

                if (process.mode === 'custom' && Array.isArray(process.assets)) {
                    $('.batch-custom-asset').prop('checked', false);
                    process.assets.forEach(function(asset) {
                        $('.batch-custom-asset[value="' + asset + '"]').prop('checked', true);
                    });
                }

                toggleCustomAssets();
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
                console.log('Actualizando UI. Proceso actual:', currentProcess);
                
                if (currentProcess) {
                    console.log('Mostrando panel de progreso. Estado:', currentProcess.status);
                    
                    // Deshabilitar configuración
                    $('#batch-event-select, input[name="batch_mode"], #batch-size-select, #batch-start-btn, .batch-custom-asset').prop('disabled', true);
                    $('#batch-progress-panel').show(); // FORZAR mostrar el panel
                    
                    // Actualizar información del evento
                    $('#progress-event-name').text($('#batch-event-select option:selected').text());
                    $('#progress-mode').text(getModeName(currentProcess.mode, currentProcess.assets || []));
                    
                    // Estado y manejo de botones según estado del proceso
                    const statusBadge = $('#progress-status');
                    statusBadge.removeClass('status-running status-completed status-cancelled status-error');
                    
                    if (currentProcess.status === 'processing') {
                        // PROCESANDO: Mostrar botón cancelar, ocultar botón nuevo
                        statusBadge.addClass('status-running').text('Procesando');
                        $('#batch-cancel-btn').show();
                        $('#batch-new-btn').hide();
                        startTimer();
                    } else if (currentProcess.status === 'completed') {
                        // COMPLETADO: Ocultar botón cancelar, mostrar botón nuevo
                        statusBadge.addClass('status-completed').text('Completado');
                        $('#batch-cancel-btn').hide();
                        $('#batch-new-btn').show();
                    } else if (currentProcess.status === 'cancelled') {
                        // CANCELADO: Ocultar botón cancelar, mostrar botón nuevo
                        statusBadge.addClass('status-cancelled').text('Cancelado');
                        $('#batch-cancel-btn').hide();
                        $('#batch-new-btn').show();
                    } else if (currentProcess.status === 'error') {
                        // ERROR: Ocultar botón cancelar, mostrar botón nuevo
                        statusBadge.addClass('status-error').text('Error');
                        $('#batch-cancel-btn').hide();
                        $('#batch-new-btn').show();
                    }
                } else {
                    console.log('Ocultando panel de progreso');
                    
                    // Habilitar configuración
                    $('#batch-event-select, input[name="batch_mode"], #batch-size-select, #batch-start-btn, .batch-custom-asset').prop('disabled', false);
                    $('#batch-start-btn').html('<span class="dashicons dashicons-controls-play"></span> Iniciar Actualización');
                    toggleCustomAssets();
                    $('#batch-cancel-btn').hide();
                    $('#batch-new-btn').hide();
                    
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
                
                console.log('Actualizando progreso:', currentProcess.processed + '/' + currentProcess.total);
                
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
             * Mostrar u ocultar el panel de recursos del refresh personalizado.
             */
            function toggleCustomAssets() {
                const mode = $('input[name="batch_mode"]:checked').val();
                const shouldShow = mode === 'custom';
                $('#custom-assets-row').toggle(shouldShow);
            }

            /**
             * Obtener recursos personalizados seleccionados.
             */
            function getSelectedAssets() {
                const assets = [];
                $('.batch-custom-asset:checked').each(function() {
                    assets.push($(this).val());
                });
                return assets;
            }

            /**
             * Obtener etiquetas legibles para recursos seleccionados.
             */
            function getAssetLabels(assets) {
                assets = Array.isArray(assets) ? assets : [];
                return assets.map(function(asset) {
                    if (availableAssets && availableAssets[asset] && availableAssets[asset].label) {
                        return availableAssets[asset].label;
                    }
                    return asset;
                });
            }

            /**
             * Nombre legible del modo.
             */
            function getModeName(mode, assets) {
                if (mode === 'complete') {
                    return 'Regeneración Completa';
                }
                if (mode === 'qr_missing') {
                    return 'Solo QR Faltantes';
                }
                if (mode === 'custom') {
                    const labels = getAssetLabels(assets || []);
                    return labels.length ? 'Refresh Personalizado (' + labels.join(', ') + ')' : 'Refresh Personalizado';
                }
                return mode || '-';
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
            
            console.log('EventosApp Batch Processor - Listo');
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
        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'complete';
        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : self::DEFAULT_BATCH_SIZE;
        $assets = [];
        
        if (!$event_id) {
            wp_send_json_error(['message' => 'ID de evento inválido']);
        }
        
        if (!in_array($mode, ['complete', 'qr_missing', 'custom'], true)) {
            $mode = 'complete';
        }

        if ($mode === 'custom') {
            $assets = $this->sanitize_assets_from_request(isset($_POST['assets']) ? wp_unslash($_POST['assets']) : []);

            if (empty($assets)) {
                wp_send_json_error(['message' => 'Selecciona al menos un recurso para ejecutar el refresh personalizado']);
            }
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
            'assets' => $assets,
            'asset_labels' => $this->get_asset_labels($assets),
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
        
        $process_assets = isset($process['assets']) && is_array($process['assets']) ? $process['assets'] : [];

        foreach ($tickets as $ticket_id) {
            $result = $this->process_ticket($ticket_id, $process['mode'], $process_assets);
            $result_message = isset($result['message']) && $result['message'] !== '' ? ': ' . $result['message'] : '';
            
            if ($result['status'] === 'success') {
                $batch_success++;
                $batch_log[] = [
                    'type' => 'success',
                    'message' => "✓ Ticket #{$ticket_id} procesado correctamente" . $result_message
                ];
            } elseif ($result['status'] === 'skipped') {
                $batch_skipped++;
                $batch_log[] = [
                    'type' => 'warning',
                    'message' => "⊘ Ticket #{$ticket_id} omitido" . $result_message
                ];
            } else {
                $batch_failed++;
                $batch_log[] = [
                    'type' => 'error',
                    'message' => "✗ Ticket #{$ticket_id} falló" . $result_message
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
        if (function_exists('eventosapp_count_tickets_by_event')) {
            return eventosapp_count_tickets_by_event($event_id);
        }
        
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
     * Sanitizar recursos recibidos desde AJAX.
     */
    private function sanitize_assets_from_request($assets) {
        if (function_exists('eventosapp_batch_refresh_sanitize_assets')) {
            return eventosapp_batch_refresh_sanitize_assets($assets);
        }

        if (is_string($assets)) {
            $assets = preg_split('/[\s,;|]+/', $assets);
        }

        if (!is_array($assets)) {
            return [];
        }

        $allowed = ['qrs', 'pdf', 'ics', 'android_wallet', 'apple_wallet', 'search_blob'];
        $clean = [];

        foreach ($assets as $asset) {
            if (is_array($asset)) {
                continue;
            }

            $asset = sanitize_key((string) $asset);
            if (in_array($asset, $allowed, true) && !in_array($asset, $clean, true)) {
                $clean[] = $asset;
            }
        }

        return $clean;
    }

    /**
     * Etiquetas legibles de recursos para guardar en el estado del proceso.
     */
    private function get_asset_labels($assets) {
        $assets = is_array($assets) ? $assets : [];
        $available = function_exists('eventosapp_batch_refresh_available_assets') ? eventosapp_batch_refresh_available_assets() : [];
        $labels = [];

        foreach ($assets as $asset) {
            if (isset($available[$asset]['label'])) {
                $labels[] = $available[$asset]['label'];
            } else {
                $labels[] = $asset;
            }
        }

        return $labels;
    }

    /**
     * Resume estadísticas retornadas por eventosapp-batch-refresh.php.
     */
    private function summarize_refresh_stats($stats) {
        if (!is_array($stats)) {
            return '';
        }

        $parts = [];
        $map = [
            'generated'   => 'creados',
            'regenerated' => 'regenerados',
            'preserved'   => 'enlaces conservados',
            'skipped'     => 'omitidos',
            'failed'      => 'errores',
        ];

        foreach ($map as $key => $label) {
            $value = isset($stats[$key]) ? absint($stats[$key]) : 0;
            if ($value > 0) {
                $parts[] = $value . ' ' . $label;
            }
        }

        return $parts ? implode(', ', $parts) : '';
    }

    /**
     * Procesar un ticket individual.
     */
    private function process_ticket($ticket_id, $mode, $assets = []) {
        // Importar la función de batch-refresh si existe
        if (function_exists('eventosapp_refresh_ticket_full')) {
            try {
                if ($mode === 'custom') {
                    $result = eventosapp_refresh_ticket_full($ticket_id, 'custom', ['assets' => $assets]);
                } else {
                    $result = eventosapp_refresh_ticket_full($ticket_id, $mode);
                }
                
                if ($result === true) {
                    return ['status' => 'success'];
                }

                if ($result === false) {
                    return ['status' => 'error', 'message' => 'El refresco devolvió false. Verifica el ticket y el evento asociado.'];
                }

                if (is_array($result)) {
                    $summary = $this->summarize_refresh_stats($result);
                    $failed = isset($result['failed']) ? absint($result['failed']) : 0;
                    $generated = isset($result['generated']) ? absint($result['generated']) : 0;
                    $regenerated = isset($result['regenerated']) ? absint($result['regenerated']) : 0;
                    $preserved = isset($result['preserved']) ? absint($result['preserved']) : 0;
                    $skipped = isset($result['skipped']) ? absint($result['skipped']) : 0;

                    if ($failed > 0) {
                        return ['status' => 'error', 'message' => $summary ?: 'Uno o más recursos fallaron'];
                    }

                    if (($generated + $regenerated + $preserved) > 0) {
                        return ['status' => 'success', 'message' => $summary];
                    }

                    if ($skipped > 0) {
                        return ['status' => 'skipped', 'message' => $summary ?: 'Sin recursos nuevos que procesar'];
                    }

                    return ['status' => 'success', 'message' => $summary];
                }
                
                return ['status' => 'success'];
                
            } catch (Exception $e) {
                return ['status' => 'error', 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                return ['status' => 'error', 'message' => $e->getMessage()];
            }
        }
        
        return ['status' => 'error', 'message' => 'Función de procesamiento no disponible'];
    }
}

// Inicializar
EventosApp_Batch_Processor::get_instance();
