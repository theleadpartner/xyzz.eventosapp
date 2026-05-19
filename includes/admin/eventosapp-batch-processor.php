<?php
/**
 * EventosApp - Batch Processor
 * Sistema de actualización por lote en segundo plano
 * 
 * @package EventosApp
 * @version 4.1.0 - Procesamiento resiliente en segundo plano con pausa y reanudación
 */

if (!defined('ABSPATH')) exit;

class EventosApp_Batch_Processor {
    
    private static $instance = null;
    
    const OPTION_PREFIX = 'eventosapp_batch_';
    const BATCH_SIZES = [10, 25, 50, 100, 200];
    const DEFAULT_BATCH_SIZE = 50;
    const CRON_HOOK = 'eventosapp_batch_processor_cron_run';
    const LOCK_OPTION = 'eventosapp_batch_processor_lock';
    const LOCK_TTL = 120;
    const LOG_LIMIT = 200;
    
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
        add_action('wp_ajax_eventosapp_batch_pause', [$this, 'ajax_pause_batch']);
        add_action('wp_ajax_eventosapp_batch_resume', [$this, 'ajax_resume_batch']);
        add_action('wp_ajax_eventosapp_batch_cancel', [$this, 'ajax_cancel_batch']);

        // Ejecutor en segundo plano. Permite que el proceso continúe aunque se recargue
        // la pantalla o se pierda temporalmente la conexión del navegador.
        add_action(self::CRON_HOOK, [$this, 'cron_process_batch'], 10, 1);
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
                'whatsapp_assets'=> ['label' => 'Piezas WhatsApp', 'description' => 'Prepara landing, QR WhatsApp e imagen del mensaje.'],
                'qr_whatsapp'    => ['label' => 'QR WhatsApp', 'description' => 'Regenera únicamente el QR usado por WhatsApp.'],
                'search_blob'    => ['label' => 'Índice de búsqueda', 'description' => 'Reconstruye el índice interno de búsqueda.'],
            ];

        $custom_asset_keys = ['qrs', 'pdf', 'ics', 'android_wallet', 'apple_wallet', 'whatsapp_assets', 'qr_whatsapp', 'search_blob'];
        
        // Crear nonce
        $nonce = wp_create_nonce('eventosapp_batch_processor');
        $ajax_url = admin_url('admin-ajax.php');
        
        ?>
        <div class="wrap eventosapp-batch-wrapper">
            <h1>
                <span class="dashicons dashicons-update"></span>
                Actualización por Lote de Tickets
            </h1>

            <div class="notice notice-info eventosapp-batch-background-notice">
                <p>
                    <strong>Procesamiento resiliente:</strong> al iniciar una actualización, el avance queda guardado en la base de datos y se agenda un ejecutor en segundo plano. Puedes pausar, continuar, recargar la pantalla o volver después de una caída de conexión sin perder el progreso.
                </p>
            </div>

            <div id="batch-connection-notice" class="notice notice-warning eventosapp-batch-connection-notice" style="display:none;">
                <p><strong>Conexión inestable:</strong> se conservará el progreso y el sistema intentará continuar cuando vuelva la conexión.</p>
            </div>
            
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
                                                Regenera TODO lo activo para el evento: Wallets (Google + Apple), PDF, ICS, QR codes, piezas WhatsApp e índice de búsqueda. Si ya existe un enlace, se conserva.
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
                                                Permite escoger exactamente qué recursos regenerar: QRs, PDF, ICS, Android Wallet, Apple Wallet, piezas WhatsApp, QR WhatsApp e índice de búsqueda.
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
                            
                            <button type="button" id="batch-pause-btn" class="button button-secondary" style="display: none;">
                                <span class="dashicons dashicons-controls-pause"></span>
                                Pausar
                            </button>

                            <button type="button" id="batch-resume-btn" class="button button-primary" style="display: none;">
                                <span class="dashicons dashicons-controls-play"></span>
                                Continuar
                            </button>

                            <button type="button" id="batch-background-btn" class="button button-secondary" style="display: none;">
                                <span class="dashicons dashicons-admin-generic"></span>
                                Dejar en segundo plano
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

        .status-paused {
            background: #e0f2fe;
            color: #075985;
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

        .eventosapp-batch-background-notice,
        .eventosapp-batch-connection-notice {
            margin: 0 0 16px 0;
        }

        .batch-actions .button {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            console.log('EventosApp Batch Processor - Iniciando modo resiliente...');

            let currentProcess = null;
            let autoScroll = true;
            let startTime = null;
            let timerInterval = null;
            let monitorInterval = null;
            let kickInterval = null;
            let statusAjaxBusy = false;
            let processAjaxBusy = false;
            let connectionWarningVisible = false;
            let processedLogUids = {};
            let backgroundOnlyMode = false;

            const ajaxUrl = <?php echo json_encode($ajax_url); ?>;
            const nonce = <?php echo json_encode($nonce); ?>;
            const availableAssets = <?php echo wp_json_encode($available_assets); ?>;

            <?php if ($current_process): ?>
            loadCurrentProcess(<?php echo wp_json_encode($current_process); ?>);
            <?php endif; ?>

            $('#batch-event-select').on('change', function() {
                const eventId = $(this).val();

                if (!eventId) {
                    $('#ticket-count-info').hide();
                    return;
                }

                $.post(ajaxUrl, {
                    action: 'eventosapp_batch_status',
                    nonce: nonce,
                    task: 'count_tickets',
                    event_id: eventId
                }, function(response) {
                    if (response.success) {
                        $('#ticket-count-number').text(response.data.count);
                        $('#ticket-count-info').show();
                    }
                }).fail(function(xhr, status, error) {
                    showConnectionWarning(true);
                    addLog('error', 'Error al contar tickets: ' + error);
                });
            });

            $('input[name="batch_mode"]').on('change', function() {
                toggleCustomAssets();
            });

            toggleCustomAssets();

            $('#batch-start-btn').on('click', function() {
                const eventId = $('#batch-event-select').val();
                const mode = $('input[name="batch_mode"]:checked').val();
                const batchSize = $('#batch-size-select').val();
                const selectedAssets = mode === 'custom' ? getSelectedAssets() : [];

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

                confirmMessage += '\n\nEl proceso quedará guardado y podrá continuar aunque recargues la pantalla o pierdas conexión.';

                if (!confirm(confirmMessage)) {
                    return;
                }

                startBatchProcess(eventId, mode, batchSize, selectedAssets);
            });

            $('#batch-pause-btn').on('click', function() {
                pauseBatchProcess();
            });

            $('#batch-resume-btn').on('click', function() {
                resumeBatchProcess();
            });

            $('#batch-background-btn').on('click', function() {
                backgroundOnlyMode = true;
                stopKickLoop();
                addLog('info', 'El proceso seguirá en segundo plano. Puedes cerrar o recargar esta pantalla; al volver se cargará el progreso guardado.');
                updateUI();
            });

            $('#batch-cancel-btn').on('click', function() {
                if (!confirm('¿Estás seguro de cancelar el proceso? El progreso se detendrá y podrás iniciar uno nuevo.')) {
                    return;
                }
                cancelBatchProcess(false);
            });

            $('#batch-new-btn').on('click', function() {
                resetProcess();
            });

            $('#log-clear-btn').on('click', function() {
                $('#batch-log-container').empty();
                processedLogUids = {};
                addLog('info', 'Log limpiado manualmente');
            });

            $('#log-auto-scroll-btn').on('click', function() {
                autoScroll = !autoScroll;
                $(this).toggleClass('active', autoScroll);
                $(this).html('<span class="dashicons dashicons-arrow-down-alt"></span> Auto-scroll: ' + (autoScroll ? 'ON' : 'OFF'));
            });

            window.addEventListener('offline', function() {
                showConnectionWarning(true);
                addLog('warning', 'Se perdió la conexión del navegador. El progreso ya guardado no se perderá.');
            });

            window.addEventListener('online', function() {
                showConnectionWarning(false);
                addLog('success', 'Conexión recuperada. Consultando estado y reactivando ejecución.');
                pollStatus(true);
                if (!backgroundOnlyMode) {
                    kickProcessor();
                    startKickLoop();
                }
            });

            function startBatchProcess(eventId, mode, batchSize, selectedAssets) {
                selectedAssets = Array.isArray(selectedAssets) ? selectedAssets : [];
                backgroundOnlyMode = false;

                addLog('batch', '═══════════════════════════════════════════════════');
                addLog('batch', 'INICIANDO PROCESO DE ACTUALIZACIÓN POR LOTE');
                addLog('batch', '═══════════════════════════════════════════════════');

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
                        showConnectionWarning(false);

                        if (response.success) {
                            currentProcess = response.data;
                            startTime = new Date((currentProcess.start_time || Math.floor(Date.now() / 1000)) * 1000);

                            addLog('success', 'Proceso iniciado correctamente');
                            addLog('info', 'Evento: ' + $('#batch-event-select option:selected').text());
                            addLog('info', 'Modo: ' + getModeName(currentProcess.mode, currentProcess.assets || selectedAssets));
                            if (currentProcess.mode === 'custom') {
                                addLog('info', 'Recursos seleccionados: ' + getAssetLabels(currentProcess.assets || selectedAssets).join(', '));
                            }
                            addLog('info', 'Total de tickets: ' + currentProcess.total);
                            addLog('info', 'Tamaño de lote: ' + batchSize);
                            addLog('info', 'Ejecución en segundo plano activada. Puedes recargar la pantalla sin perder progreso.');

                            renderProcessLogs(currentProcess.logs || []);
                            updateUI();
                            updateProgress();
                            startMonitorLoop();
                            startKickLoop();
                            kickProcessor();
                        } else {
                            addLog('error', 'Error al iniciar: ' + getResponseMessage(response));
                            $('#batch-start-btn').prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> Iniciar Actualización');
                        }
                    },
                    error: function(xhr, status, error) {
                        showConnectionWarning(true);
                        addLog('error', 'Error de conexión al iniciar el proceso: ' + error);
                        $('#batch-start-btn').prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> Iniciar Actualización');
                    }
                });
            }

            function kickProcessor() {
                if (!currentProcess || currentProcess.status !== 'processing' || processAjaxBusy || !navigator.onLine) {
                    return;
                }

                processAjaxBusy = true;

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
                        processAjaxBusy = false;
                        showConnectionWarning(false);

                        if (response.success && response.data) {
                            currentProcess = response.data;
                            renderProcessLogs(currentProcess.logs || []);
                            updateUI();
                            updateProgress();

                            if (currentProcess.status === 'completed') {
                                finishProcess();
                            }
                        } else {
                            const message = getResponseMessage(response);
                            if (message && message !== 'El proceso no está en ejecución') {
                                addLog('warning', 'No se pudo ejecutar el lote ahora: ' + message);
                            }
                            pollStatus(true);
                        }
                    },
                    error: function(xhr, status, error) {
                        processAjaxBusy = false;
                        showConnectionWarning(true);
                        addLogOncePerMinute('connection_process_error', 'error', 'Error de conexión durante el procesamiento. Se reintentará automáticamente: ' + error);
                    }
                });
            }

            function pollStatus(force) {
                if (statusAjaxBusy && !force) {
                    return;
                }

                statusAjaxBusy = true;

                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'eventosapp_batch_status',
                        nonce: nonce
                    },
                    success: function(response) {
                        statusAjaxBusy = false;
                        showConnectionWarning(false);

                        if (response.success && response.data) {
                            const oldStatus = currentProcess ? currentProcess.status : '';
                            currentProcess = response.data;
                            if (!startTime && currentProcess.start_time) {
                                startTime = new Date(currentProcess.start_time * 1000);
                            }
                            renderProcessLogs(currentProcess.logs || []);
                            updateUI();
                            updateProgress();

                            if (oldStatus !== 'completed' && currentProcess.status === 'completed') {
                                finishProcess();
                            }
                        } else if (response.success && !response.data) {
                            currentProcess = null;
                            updateUI();
                        }
                    },
                    error: function(xhr, status, error) {
                        statusAjaxBusy = false;
                        showConnectionWarning(true);
                        addLogOncePerMinute('connection_status_error', 'warning', 'No se pudo consultar el estado. Se reintentará automáticamente: ' + error);
                    }
                });
            }

            function pauseBatchProcess() {
                if (!currentProcess || currentProcess.status !== 'processing') return;

                addLog('warning', 'Pausando proceso...');

                $.post(ajaxUrl, {
                    action: 'eventosapp_batch_pause',
                    nonce: nonce,
                    process_id: currentProcess.id
                }, function(response) {
                    if (response.success) {
                        currentProcess = response.data;
                        renderProcessLogs(currentProcess.logs || []);
                        stopKickLoop();
                        updateUI();
                        updateProgress();
                    } else {
                        addLog('error', 'No se pudo pausar: ' + getResponseMessage(response));
                    }
                }).fail(function(xhr, status, error) {
                    showConnectionWarning(true);
                    addLog('error', 'Error de conexión al pausar: ' + error);
                });
            }

            function resumeBatchProcess() {
                if (!currentProcess) return;

                backgroundOnlyMode = false;
                addLog('success', 'Reanudando proceso...');

                $.post(ajaxUrl, {
                    action: 'eventosapp_batch_resume',
                    nonce: nonce,
                    process_id: currentProcess.id
                }, function(response) {
                    if (response.success) {
                        currentProcess = response.data;
                        renderProcessLogs(currentProcess.logs || []);
                        updateUI();
                        updateProgress();
                        startMonitorLoop();
                        startKickLoop();
                        kickProcessor();
                    } else {
                        addLog('error', 'No se pudo continuar: ' + getResponseMessage(response));
                    }
                }).fail(function(xhr, status, error) {
                    showConnectionWarning(true);
                    addLog('error', 'Error de conexión al continuar: ' + error);
                });
            }

            function cancelBatchProcess(hardReset) {
                if (!currentProcess && !hardReset) return;

                addLog('warning', hardReset ? 'Limpiando proceso...' : 'Cancelando proceso...');

                $.post(ajaxUrl, {
                    action: 'eventosapp_batch_cancel',
                    nonce: nonce,
                    process_id: currentProcess ? currentProcess.id : '',
                    hard_reset: hardReset ? 1 : 0
                }, function(response) {
                    if (response.success) {
                        stopMonitorLoop();
                        stopKickLoop();

                        if (hardReset || (response.data && response.data.cleared)) {
                            currentProcess = null;
                        } else {
                            currentProcess = response.data;
                            renderProcessLogs(currentProcess.logs || []);
                        }

                        updateUI();
                        updateProgress();
                    } else {
                        addLog('error', 'No se pudo cancelar: ' + getResponseMessage(response));
                    }
                }).fail(function(xhr, status, error) {
                    showConnectionWarning(true);
                    addLog('error', 'Error de conexión al cancelar: ' + error);
                });
            }

            function resetProcess() {
                stopMonitorLoop();
                stopKickLoop();

                if (currentProcess && currentProcess.id) {
                    cancelBatchProcess(true);
                }

                if (timerInterval) {
                    clearInterval(timerInterval);
                    timerInterval = null;
                }

                currentProcess = null;
                startTime = null;
                backgroundOnlyMode = false;
                processedLogUids = {};

                $('#batch-event-select').val('').trigger('change');
                $('#ticket-count-info').hide();
                $('#mode-complete').prop('checked', true);
                $('.batch-custom-asset').prop('checked', true);
                toggleCustomAssets();
                $('#batch-size-select').val('50');

                $('#batch-log-container').empty();
                addLog('info', 'Sistema reseteado. Listo para procesar un nuevo evento.');

                $('#batch-progress-panel').fadeOut(300, function() {
                    $('#progress-bar').css('width', '0%');
                    $('#progress-percentage').text('0%');
                    $('#progress-current, #progress-total, #progress-success, #progress-skipped, #progress-failed').text('0');
                    $('#elapsed-time').text('00:00');
                    $('#estimated-time').text('Calculando...');
                    $('#progress-event-name').text('-');
                    $('#progress-mode').text('-');
                });

                updateUI();
            }

            function finishProcess() {
                stopKickLoop();
                stopMonitorLoop(false);
                if (timerInterval) {
                    clearInterval(timerInterval);
                    timerInterval = null;
                }
                updateUI();
                updateProgress();
            }

            function loadCurrentProcess(process) {
                currentProcess = process;
                startTime = new Date((process.start_time || Math.floor(Date.now() / 1000)) * 1000);

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
                renderProcessLogs(process.logs || []);
                updateUI();
                updateProgress();

                if (process.status === 'processing') {
                    addLog('info', 'Proceso existente detectado. Se continúa desde el progreso guardado.');
                    startMonitorLoop();
                    startKickLoop();
                    kickProcessor();
                }
            }

            function updateUI() {
                if (currentProcess) {
                    $('#batch-event-select, input[name="batch_mode"], #batch-size-select, #batch-start-btn, .batch-custom-asset').prop('disabled', true);
                    $('#batch-progress-panel').show();

                    $('#progress-event-name').text($('#batch-event-select option:selected').text() || ('Evento ID: ' + currentProcess.event_id));
                    $('#progress-mode').text(getModeName(currentProcess.mode, currentProcess.assets || []));

                    const statusBadge = $('#progress-status');
                    statusBadge.removeClass('status-running status-paused status-completed status-cancelled status-error');

                    $('#batch-pause-btn, #batch-resume-btn, #batch-background-btn, #batch-cancel-btn, #batch-new-btn').hide();

                    if (currentProcess.status === 'processing') {
                        statusBadge.addClass('status-running').text(backgroundOnlyMode ? 'Segundo plano' : 'Procesando');
                        $('#batch-pause-btn').show();
                        $('#batch-background-btn').show();
                        $('#batch-cancel-btn').show();
                        startTimer();
                    } else if (currentProcess.status === 'paused') {
                        statusBadge.addClass('status-paused').text('Pausado');
                        $('#batch-resume-btn').show();
                        $('#batch-cancel-btn').show();
                        stopKickLoop();
                    } else if (currentProcess.status === 'completed') {
                        statusBadge.addClass('status-completed').text('Completado');
                        $('#batch-new-btn').show();
                    } else if (currentProcess.status === 'cancelled') {
                        statusBadge.addClass('status-cancelled').text('Cancelado');
                        $('#batch-new-btn').show();
                    } else if (currentProcess.status === 'error') {
                        statusBadge.addClass('status-error').text('Error');
                        $('#batch-resume-btn').show();
                        $('#batch-new-btn').show();
                    }
                } else {
                    $('#batch-event-select, input[name="batch_mode"], #batch-size-select, #batch-start-btn, .batch-custom-asset').prop('disabled', false);
                    $('#batch-start-btn').html('<span class="dashicons dashicons-controls-play"></span> Iniciar Actualización');
                    toggleCustomAssets();
                    $('#batch-pause-btn, #batch-resume-btn, #batch-background-btn, #batch-cancel-btn, #batch-new-btn').hide();
                    $('#progress-bar').css('width', '0%');
                    $('#progress-percentage').text('0%');
                    $('#progress-current, #progress-total, #progress-success, #progress-skipped, #progress-failed').text('0');
                }
            }

            function updateProgress() {
                if (!currentProcess) return;

                const total = parseInt(currentProcess.total || 0, 10);
                const processed = parseInt(currentProcess.processed || 0, 10);
                const percentage = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;

                $('#progress-bar').css('width', percentage + '%');
                $('#progress-percentage').text(percentage + '%');
                $('#progress-current').text(processed);
                $('#progress-total').text(total);
                $('#progress-success').text(currentProcess.success || 0);
                $('#progress-skipped').text(currentProcess.skipped || 0);
                $('#progress-failed').text(currentProcess.failed || 0);

                if (processed > 0 && startTime && currentProcess.status === 'processing') {
                    const elapsed = (new Date() - startTime) / 1000;
                    const rate = processed / Math.max(elapsed, 1);
                    const remaining = Math.max(total - processed, 0);
                    const estimatedSeconds = Math.ceil(remaining / Math.max(rate, 0.001));
                    const estMinutes = Math.floor(estimatedSeconds / 60);
                    const estSeconds = estimatedSeconds % 60;
                    $('#estimated-time').text(estMinutes + 'm ' + estSeconds + 's');
                } else if (currentProcess.status === 'paused') {
                    $('#estimated-time').text('Pausado');
                } else if (currentProcess.status === 'completed') {
                    $('#estimated-time').text('Completado');
                }
            }

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

            function startMonitorLoop() {
                stopMonitorLoop(false);
                monitorInterval = setInterval(function() {
                    pollStatus(false);
                }, 3000);
            }

            function stopMonitorLoop(clearTimer) {
                if (monitorInterval) {
                    clearInterval(monitorInterval);
                    monitorInterval = null;
                }
                if (clearTimer && timerInterval) {
                    clearInterval(timerInterval);
                    timerInterval = null;
                }
            }

            function startKickLoop() {
                if (backgroundOnlyMode) return;
                stopKickLoop();
                kickInterval = setInterval(function() {
                    kickProcessor();
                }, 1800);
            }

            function stopKickLoop() {
                if (kickInterval) {
                    clearInterval(kickInterval);
                    kickInterval = null;
                }
            }

            function toggleCustomAssets() {
                const mode = $('input[name="batch_mode"]:checked').val();
                $('#custom-assets-row').toggle(mode === 'custom');
            }

            function getSelectedAssets() {
                const assets = [];
                $('.batch-custom-asset:checked').each(function() {
                    assets.push($(this).val());
                });
                return assets;
            }

            function getAssetLabels(assets) {
                assets = Array.isArray(assets) ? assets : [];
                return assets.map(function(asset) {
                    if (availableAssets && availableAssets[asset] && availableAssets[asset].label) {
                        return availableAssets[asset].label;
                    }
                    return asset;
                });
            }

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

            function renderProcessLogs(logs) {
                if (!Array.isArray(logs)) return;
                logs.forEach(function(log) {
                    if (!log || !log.uid || processedLogUids[log.uid]) return;
                    processedLogUids[log.uid] = true;
                    addLog(log.type || 'info', log.message || '', log.time || null);
                });
            }

            const logThrottle = {};
            function addLogOncePerMinute(key, type, message) {
                const now = Date.now();
                if (logThrottle[key] && (now - logThrottle[key]) < 60000) {
                    return;
                }
                logThrottle[key] = now;
                addLog(type, message);
            }

            function addLog(type, message, timestamp) {
                const time = timestamp ? formatLogTime(timestamp) : new Date().toTimeString().split(' ')[0];
                const entry = $('<div class="log-entry log-' + type + '">' +
                    '<span class="log-time">[' + time + ']</span>' +
                    '<span class="log-message"></span>' +
                    '</div>');

                entry.find('.log-message').text(message);
                $('#batch-log-container').append(entry);

                if (autoScroll) {
                    const container = document.getElementById('batch-log-container');
                    container.scrollTop = container.scrollHeight;
                }
            }

            function formatLogTime(timestamp) {
                const numeric = parseInt(timestamp, 10);
                if (!numeric) return new Date().toTimeString().split(' ')[0];
                return new Date(numeric * 1000).toTimeString().split(' ')[0];
            }

            function getResponseMessage(response) {
                if (response && response.data && response.data.message) return response.data.message;
                if (response && response.message) return response.message;
                return 'Desconocido';
            }

            function showConnectionWarning(show) {
                if (show === connectionWarningVisible) return;
                connectionWarningVisible = show;
                $('#batch-connection-notice').toggle(show);
            }

            console.log('EventosApp Batch Processor - Listo en modo resiliente');
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

        $active = $this->get_current_process();
        if ($active && isset($active['status']) && in_array($active['status'], ['processing', 'paused'], true)) {
            wp_send_json_error(['message' => 'Ya hay un proceso activo. Debes pausarlo, continuarlo, cancelarlo o finalizarlo antes de iniciar otro.']);
        }

        $total = $this->count_tickets_by_event($event_id);

        if ($total === 0) {
            wp_send_json_error(['message' => 'No se encontraron tickets para este evento']);
        }

        $process_id = 'batch_' . $event_id . '_' . time() . '_' . wp_generate_password(6, false, false);
        $now = time();

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
            'background_enabled' => true,
            'resume_count' => 0,
            'start_time' => $now,
            'last_update' => $now,
            'last_heartbeat' => $now,
            'last_runner' => 'start',
            'last_batch_processed' => 0,
            'batch_log' => [],
            'logs' => [],
        ];

        $initial_logs = [
            $this->make_process_log('batch', 'Proceso creado y guardado en base de datos.'),
            $this->make_process_log('info', 'Ejecución en segundo plano activada mediante WP-Cron y refuerzo AJAX mientras la pantalla esté abierta.'),
        ];
        $process_data = $this->append_process_logs($process_data, $initial_logs);

        update_option(self::OPTION_PREFIX . 'current', $process_data, false);
        $this->release_process_lock($process_id);
        $this->schedule_background_runner($process_data, 1);

        wp_send_json_success($process_data);
    }

    /**
     * AJAX: Procesar siguiente lote. Este endpoint ahora es un refuerzo de ejecución,
     * no el único motor. Si la pantalla se cierra, el runner de WP-Cron continúa.
     */
    public function ajax_process_batch() {
        check_ajax_referer('eventosapp_batch_processor', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
        }

        $process_id = isset($_POST['process_id']) ? sanitize_text_field(wp_unslash($_POST['process_id'])) : '';
        $result = $this->process_next_batch_internal($process_id, 'ajax');

        if (is_wp_error($result)) {
            $current = $this->get_current_process();
            $error_code = $result->get_error_code();

            if ($error_code === 'locked' && $current) {
                wp_send_json_success($current);
            }

            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Obtener estado del proceso o contar tickets.
     */
    public function ajax_get_status() {
        check_ajax_referer('eventosapp_batch_processor', 'nonce');

        $task = isset($_POST['task']) ? sanitize_text_field(wp_unslash($_POST['task'])) : '';

        if ($task === 'count_tickets') {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'Permisos insuficientes']);
            }

            $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
            $count = $this->count_tickets_by_event($event_id);
            wp_send_json_success(['count' => $count]);
        }

        $process = $this->get_current_process();

        if ($process && isset($process['status']) && $process['status'] === 'processing') {
            // Si el usuario vuelve después de recargar o perder conexión, esta consulta
            // reasegura que exista un runner agendado.
            $this->schedule_background_runner($process, 1);
            $process = $this->get_current_process();
        }

        wp_send_json_success($process);
    }

    /**
     * AJAX: Pausar proceso sin perder progreso.
     */
    public function ajax_pause_batch() {
        check_ajax_referer('eventosapp_batch_processor', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
        }

        $process_id = isset($_POST['process_id']) ? sanitize_text_field(wp_unslash($_POST['process_id'])) : '';
        $process = $this->get_current_process();

        if (!$process) {
            wp_send_json_error(['message' => 'No hay proceso activo']);
        }

        if (!$this->process_id_matches($process, $process_id)) {
            wp_send_json_error(['message' => 'El proceso solicitado no coincide con el proceso activo']);
        }

        if (!isset($process['status']) || $process['status'] !== 'processing') {
            wp_send_json_error(['message' => 'Solo se puede pausar un proceso en ejecución']);
        }

        $process['status'] = 'paused';
        $process['paused_at'] = time();
        $process['last_update'] = time();
        $process['last_runner'] = 'paused_by_user';
        $process['batch_log'] = [
            ['type' => 'warning', 'message' => 'Proceso pausado manualmente. Puedes continuarlo después sin perder progreso.'],
        ];
        $process = $this->append_process_logs($process, [
            $this->make_process_log('warning', 'Proceso pausado manualmente. Puedes continuarlo después sin perder progreso.'),
        ]);

        update_option(self::OPTION_PREFIX . 'current', $process, false);
        $this->unschedule_background_runner($process['id']);
        $this->release_process_lock($process['id']);

        wp_send_json_success($process);
    }

    /**
     * AJAX: Continuar proceso pausado o reactivar un proceso que quedó en error recuperable.
     */
    public function ajax_resume_batch() {
        check_ajax_referer('eventosapp_batch_processor', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
        }

        $process_id = isset($_POST['process_id']) ? sanitize_text_field(wp_unslash($_POST['process_id'])) : '';
        $process = $this->get_current_process();

        if (!$process) {
            wp_send_json_error(['message' => 'No hay proceso activo']);
        }

        if (!$this->process_id_matches($process, $process_id)) {
            wp_send_json_error(['message' => 'El proceso solicitado no coincide con el proceso activo']);
        }

        $status = isset($process['status']) ? $process['status'] : '';
        if (!in_array($status, ['paused', 'error'], true)) {
            wp_send_json_error(['message' => 'Solo se puede continuar un proceso pausado o en error']);
        }

        $process['status'] = 'processing';
        $process['last_update'] = time();
        $process['last_heartbeat'] = time();
        $process['last_runner'] = 'resumed_by_user';
        $process['resume_count'] = isset($process['resume_count']) ? absint($process['resume_count']) + 1 : 1;
        unset($process['paused_at'], $process['end_time']);
        $process['batch_log'] = [
            ['type' => 'success', 'message' => 'Proceso reanudado. Se continuará desde el último lote guardado.'],
        ];
        $process = $this->append_process_logs($process, [
            $this->make_process_log('success', 'Proceso reanudado. Se continuará desde el último lote guardado.'),
        ]);

        update_option(self::OPTION_PREFIX . 'current', $process, false);
        $this->release_process_lock($process['id']);
        $this->schedule_background_runner($process, 1);

        wp_send_json_success($process);
    }

    /**
     * AJAX: Cancelar proceso o limpiar estado para iniciar uno nuevo.
     */
    public function ajax_cancel_batch() {
        check_ajax_referer('eventosapp_batch_processor', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
        }

        $hard_reset = !empty($_POST['hard_reset']);
        $process_id = isset($_POST['process_id']) ? sanitize_text_field(wp_unslash($_POST['process_id'])) : '';
        $process = $this->get_current_process();

        if ($hard_reset) {
            if ($process && isset($process['id'])) {
                $this->unschedule_background_runner($process['id']);
                $this->release_process_lock($process['id']);
            }
            delete_option(self::OPTION_PREFIX . 'current');
            delete_option(self::LOCK_OPTION);
            wp_send_json_success(['message' => 'Proceso limpiado', 'cleared' => true]);
        }

        if (!$process) {
            delete_option(self::OPTION_PREFIX . 'current');
            wp_send_json_success(['message' => 'No había proceso activo', 'cleared' => true]);
        }

        if (!$this->process_id_matches($process, $process_id)) {
            wp_send_json_error(['message' => 'El proceso solicitado no coincide con el proceso activo']);
        }

        $process['status'] = 'cancelled';
        $process['end_time'] = time();
        $process['last_update'] = time();
        $process['last_runner'] = 'cancelled_by_user';
        $process['batch_log'] = [
            ['type' => 'warning', 'message' => 'Proceso cancelado manualmente.'],
        ];
        $process = $this->append_process_logs($process, [
            $this->make_process_log('warning', 'Proceso cancelado manualmente.'),
        ]);

        update_option(self::OPTION_PREFIX . 'current', $process, false);
        $this->unschedule_background_runner($process['id']);
        $this->release_process_lock($process['id']);

        wp_send_json_success($process);
    }

    /**
     * Runner de WP-Cron para ejecutar lotes aunque la pantalla no esté abierta.
     */
    public function cron_process_batch($process_id = '') {
        $process_id = is_string($process_id) ? sanitize_text_field($process_id) : '';
        $result = $this->process_next_batch_internal($process_id, 'cron');

        if (is_wp_error($result)) {
            return;
        }

        if (isset($result['status']) && $result['status'] === 'processing') {
            $this->schedule_background_runner($result, 2);
        }
    }

    /**
     * Ejecuta un lote de forma segura, con lock, estado persistente y logs persistentes.
     */
    private function process_next_batch_internal($process_id = '', $source = 'ajax') {
        $process = $this->get_current_process();

        if (!$process) {
            return new WP_Error('no_process', 'No hay proceso activo');
        }

        if (!$this->process_id_matches($process, $process_id)) {
            return new WP_Error('stale_process', 'El proceso solicitado no coincide con el proceso activo');
        }

        $status = isset($process['status']) ? $process['status'] : '';
        if ($status !== 'processing') {
            return new WP_Error('not_processing', 'El proceso no está en ejecución');
        }

        $process_id = isset($process['id']) ? (string) $process['id'] : '';
        if (!$this->acquire_process_lock($process_id)) {
            return new WP_Error('locked', 'Otro runner ya está procesando un lote');
        }

        try {
            $batch_number = (isset($process['batch_size']) && absint($process['batch_size']) > 0)
                ? (int) floor(absint($process['processed']) / absint($process['batch_size'])) + 1
                : 1;

            $tickets = $this->get_tickets_batch(
                absint($process['event_id']),
                absint($process['current_offset']),
                absint($process['batch_size'])
            );

            $batch_log = [];
            $persistent_logs = [];
            $batch_success = 0;
            $batch_skipped = 0;
            $batch_failed = 0;

            $batch_log[] = [
                'type' => 'batch',
                'message' => '--- Procesando lote ' . $batch_number . ' (' . strtoupper($source) . ') ---',
            ];
            $persistent_logs[] = $this->make_process_log('batch', '--- Procesando lote ' . $batch_number . ' (' . strtoupper($source) . ') ---');

            if (empty($tickets)) {
                $process['status'] = 'completed';
                $process['end_time'] = time();
                $process['last_update'] = time();
                $process['last_heartbeat'] = time();
                $process['last_runner'] = $source;
                $process['last_batch_processed'] = 0;
                $batch_log[] = ['type' => 'warning', 'message' => 'No se encontraron más tickets en el lote actual. El proceso se marca como completado.'];
                $persistent_logs[] = $this->make_process_log('warning', 'No se encontraron más tickets en el lote actual. El proceso se marca como completado.');
                $process['batch_log'] = $batch_log;
                $process = $this->append_process_logs($process, $persistent_logs);
                update_option(self::OPTION_PREFIX . 'current', $process, false);
                $this->unschedule_background_runner($process_id);
                $this->release_process_lock($process_id);
                return $process;
            }

            $process_assets = isset($process['assets']) && is_array($process['assets']) ? $process['assets'] : [];

            foreach ($tickets as $ticket_id) {
                $result = $this->process_ticket($ticket_id, $process['mode'], $process_assets);
                $result_message = isset($result['message']) && $result['message'] !== '' ? ': ' . $result['message'] : '';

                if ($result['status'] === 'success') {
                    $batch_success++;
                    $message = "✓ Ticket #{$ticket_id} procesado correctamente" . $result_message;
                    $batch_log[] = ['type' => 'success', 'message' => $message];
                    $persistent_logs[] = $this->make_process_log('success', $message);
                } elseif ($result['status'] === 'skipped') {
                    $batch_skipped++;
                    $message = "⊘ Ticket #{$ticket_id} omitido" . $result_message;
                    $batch_log[] = ['type' => 'warning', 'message' => $message];
                    $persistent_logs[] = $this->make_process_log('warning', $message);
                } else {
                    $batch_failed++;
                    $message = "✗ Ticket #{$ticket_id} falló" . $result_message;
                    $batch_log[] = ['type' => 'error', 'message' => $message];
                    $persistent_logs[] = $this->make_process_log('error', $message);
                }
            }

            $processed_now = count($tickets);
            $process['processed'] = absint($process['processed']) + $processed_now;
            $process['success'] = absint($process['success']) + $batch_success;
            $process['skipped'] = absint($process['skipped']) + $batch_skipped;
            $process['failed'] = absint($process['failed']) + $batch_failed;
            $process['current_offset'] = absint($process['current_offset']) + $processed_now;
            $process['last_update'] = time();
            $process['last_heartbeat'] = time();
            $process['last_runner'] = $source;
            $process['last_batch_processed'] = $processed_now;

            $summary = 'Lote ' . $batch_number . ' completado: ' . $processed_now . ' tickets procesados.';
            $batch_log[] = ['type' => 'success', 'message' => $summary];
            $persistent_logs[] = $this->make_process_log('success', $summary);

            if (absint($process['processed']) >= absint($process['total'])) {
                $process['status'] = 'completed';
                $process['end_time'] = time();
                $batch_log[] = ['type' => 'batch', 'message' => '═══════════════════════════════════════════════════'];
                $batch_log[] = ['type' => 'batch', 'message' => 'PROCESO COMPLETADO'];
                $batch_log[] = ['type' => 'batch', 'message' => '═══════════════════════════════════════════════════'];
                $persistent_logs[] = $this->make_process_log('batch', '═══════════════════════════════════════════════════');
                $persistent_logs[] = $this->make_process_log('batch', 'PROCESO COMPLETADO');
                $persistent_logs[] = $this->make_process_log('batch', '═══════════════════════════════════════════════════');
                $this->unschedule_background_runner($process_id);
            }

            // Si el usuario pausó o canceló mientras este lote estaba corriendo,
            // respetamos esa orden al terminar el lote actual para no sobrescribirla
            // con una copia anterior del proceso.
            $latest_process = $this->get_current_process();
            if (
                is_array($latest_process)
                && isset($latest_process['id'], $process['id'])
                && (string) $latest_process['id'] === (string) $process['id']
            ) {
                if (isset($latest_process['logs']) && is_array($latest_process['logs'])) {
                    $process['logs'] = $latest_process['logs'];
                }

                $latest_status = isset($latest_process['status']) ? (string) $latest_process['status'] : '';
                if ($process['status'] !== 'completed' && in_array($latest_status, ['paused', 'cancelled'], true)) {
                    $process['status'] = $latest_status;
                    $process['last_runner'] = isset($latest_process['last_runner']) ? $latest_process['last_runner'] : $process['last_runner'];

                    if ($latest_status === 'paused') {
                        $process['paused_at'] = isset($latest_process['paused_at']) ? absint($latest_process['paused_at']) : time();
                        $message = 'Se completó el lote en curso y se respetó la pausa solicitada.';
                        $batch_log[] = ['type' => 'warning', 'message' => $message];
                        $persistent_logs[] = $this->make_process_log('warning', $message);
                    } elseif ($latest_status === 'cancelled') {
                        $process['end_time'] = isset($latest_process['end_time']) ? absint($latest_process['end_time']) : time();
                        $message = 'Se completó el lote en curso y se respetó la cancelación solicitada.';
                        $batch_log[] = ['type' => 'warning', 'message' => $message];
                        $persistent_logs[] = $this->make_process_log('warning', $message);
                    }

                    $this->unschedule_background_runner($process_id);
                }
            }

            $process['batch_log'] = $batch_log;
            $process = $this->append_process_logs($process, $persistent_logs);

            update_option(self::OPTION_PREFIX . 'current', $process, false);

            if (isset($process['status']) && $process['status'] === 'processing') {
                $this->schedule_background_runner($process, 2);
            }

            $this->release_process_lock($process_id);
            return $process;

        } catch (Exception $e) {
            $this->release_process_lock($process_id);
            return $this->mark_process_error($process, $e->getMessage(), $source);
        } catch (\Throwable $e) {
            $this->release_process_lock($process_id);
            return $this->mark_process_error($process, $e->getMessage(), $source);
        }
    }

    /**
     * Marcar proceso como error real de servidor, conservando la posibilidad de continuarlo.
     */
    private function mark_process_error($process, $message, $source = 'system') {
        if (!is_array($process)) {
            return new WP_Error('process_error', $message);
        }

        $process['status'] = 'error';
        $process['last_update'] = time();
        $process['last_heartbeat'] = time();
        $process['last_runner'] = $source;
        $process['batch_log'] = [
            ['type' => 'error', 'message' => 'Error del proceso: ' . $message],
        ];
        $process = $this->append_process_logs($process, [
            $this->make_process_log('error', 'Error del proceso: ' . $message),
        ]);

        update_option(self::OPTION_PREFIX . 'current', $process, false);

        if (isset($process['id'])) {
            $this->unschedule_background_runner($process['id']);
            $this->release_process_lock($process['id']);
        }

        return $process;
    }

    /**
     * Obtener proceso actual.
     */
    private function get_current_process() {
        $process = get_option(self::OPTION_PREFIX . 'current', null);
        return is_array($process) ? $process : null;
    }

    /**
     * Validar si el ID enviado coincide con el proceso activo.
     */
    private function process_id_matches($process, $process_id) {
        if (!is_array($process) || empty($process['id'])) {
            return false;
        }

        $process_id = (string) $process_id;
        if ($process_id === '') {
            return true;
        }

        return hash_equals((string) $process['id'], $process_id);
    }

    /**
     * Agendar el runner de segundo plano.
     */
    private function schedule_background_runner($process = null, $delay = 2) {
        if (!$process) {
            $process = $this->get_current_process();
        }

        if (!$process || empty($process['id']) || empty($process['status']) || $process['status'] !== 'processing') {
            return false;
        }

        $process_id = (string) $process['id'];
        $args = [$process_id];
        $next = wp_next_scheduled(self::CRON_HOOK, $args);

        if (!$next) {
            $timestamp = time() + max(1, absint($delay));
            wp_schedule_single_event($timestamp, self::CRON_HOOK, $args);
            $process['next_run'] = $timestamp;
            update_option(self::OPTION_PREFIX . 'current', $process, false);
        }

        if (function_exists('spawn_cron')) {
            @spawn_cron(time());
        }

        return true;
    }

    /**
     * Desagendar runner de segundo plano.
     */
    private function unschedule_background_runner($process_id = '') {
        $process_id = (string) $process_id;

        if ($process_id !== '') {
            wp_clear_scheduled_hook(self::CRON_HOOK, [$process_id]);
            return;
        }

        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Lock simple para evitar que AJAX y WP-Cron procesen el mismo lote a la vez.
     */
    private function acquire_process_lock($process_id) {
        $process_id = (string) $process_id;
        $now = time();
        $lock = get_option(self::LOCK_OPTION, null);

        if (is_array($lock)) {
            $expires = isset($lock['expires']) ? absint($lock['expires']) : 0;
            $locked_process = isset($lock['process_id']) ? (string) $lock['process_id'] : '';

            if ($expires > $now && $locked_process === $process_id) {
                return false;
            }
        }

        update_option(self::LOCK_OPTION, [
            'process_id' => $process_id,
            'expires' => $now + self::LOCK_TTL,
            'created' => $now,
        ], false);

        return true;
    }

    /**
     * Liberar lock del proceso.
     */
    private function release_process_lock($process_id = '') {
        $lock = get_option(self::LOCK_OPTION, null);
        if (!is_array($lock)) {
            return;
        }

        if ($process_id === '' || (isset($lock['process_id']) && (string) $lock['process_id'] === (string) $process_id)) {
            delete_option(self::LOCK_OPTION);
        }
    }

    /**
     * Crear entrada de log persistente.
     */
    private function make_process_log($type, $message) {
        static $counter = 0;
        $counter++;
        $time = time();

        return [
            'uid' => $time . '_' . $counter . '_' . wp_generate_password(4, false, false),
            'time' => $time,
            'type' => sanitize_key($type ?: 'info'),
            'message' => wp_strip_all_tags((string) $message),
        ];
    }

    /**
     * Agregar logs persistentes al proceso, limitando su tamaño para no inflar wp_options.
     */
    private function append_process_logs($process, $logs) {
        if (!is_array($process)) {
            $process = [];
        }

        if (!isset($process['logs']) || !is_array($process['logs'])) {
            $process['logs'] = [];
        }

        foreach ((array) $logs as $log) {
            if (!is_array($log) || empty($log['message'])) {
                continue;
            }
            $process['logs'][] = $log;
        }

        if (count($process['logs']) > self::LOG_LIMIT) {
            $process['logs'] = array_slice($process['logs'], -self::LOG_LIMIT);
        }

        return $process;
    }

    /**
     * Contar tickets por evento.
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
     * Obtener lote de tickets.
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

        $allowed = ['qrs', 'pdf', 'ics', 'android_wallet', 'apple_wallet', 'whatsapp_assets', 'qr_whatsapp', 'search_blob'];
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
