<?php
/**
 * EventosApp - QR Manager
 * Gestiona la generación y visualización de múltiples códigos QR por ticket
 * 
 * @package EventosApp
 * @version 1.2
 * Modificado: QR de badge usa eventosapp_ticketID en lugar de Post ID
 */

if (!defined('ABSPATH')) {
    exit;
}

class EventosApp_QR_Manager {
    
    /**
     * Tipos de QR disponibles
     */
    const QR_TYPES = array(
        'email' => 'Email',
        'google_wallet' => 'Google Wallet',
        'apple_wallet' => 'Apple Wallet',
        'pdf' => 'PDF Impreso',
        'whatsapp' => 'WhatsApp',
        'badge' => 'Escarapela Impresa'
    );

    /**
     * Constructor
     */
    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_qr_metabox'));
        add_action('save_post_eventosapp_ticket', array($this, 'generate_all_qr_codes'), 20, 1);
        add_action('wp_ajax_eventosapp_regenerate_qr', array($this, 'ajax_regenerate_qr'));
        
        // Cargar la librería QR si no está cargada
        $this->load_qr_library();
    }

    /**
     * Carga la librería QR
     */
    private function load_qr_library() {
        // Si la clase QRcode ya existe, no cargar de nuevo
        if (class_exists('QRcode')) {
            return true;
        }
        
        // Ruta correcta de la librería
        $qr_lib_path = plugin_dir_path(__FILE__) . 'includes/qrlib/qrlib.php';
        
        if (!file_exists($qr_lib_path)) {
            error_log('EventosApp QR Manager: No se encuentra la librería qrlib en: ' . $qr_lib_path);
            return false;
        }
        
        // Definir constantes necesarias antes de cargar la librería
        $upload = wp_upload_dir();
        $qr_cache_dir = trailingslashit($upload['basedir']) . 'eventosapp-qr-cache/';
        
        if (!file_exists($qr_cache_dir)) {
            wp_mkdir_p($qr_cache_dir);
        }
        
        if (!defined('QR_CACHEABLE')) {
            define('QR_CACHEABLE', true);
        }
        if (!defined('QR_CACHE_DIR')) {
            define('QR_CACHE_DIR', $qr_cache_dir);
        }
        
        require_once($qr_lib_path);
        
        return class_exists('QRcode');
    }

    /**
     * Detecta si un ticket corresponde a modalidad virtual.
     * En tickets virtuales no se generan QR, PDF ni Wallet.
     */
    private function is_virtual_ticket($ticket_id) {
        return function_exists('eventosapp_ticket_is_virtual') && eventosapp_ticket_is_virtual($ticket_id);
    }

    /**
     * Limpia metadata y archivos QR cuando un ticket es virtual.
     */
    private function cleanup_qr_codes_for_virtual_ticket($ticket_id) {
        $existing_qrs = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
        if (is_array($existing_qrs)) {
            foreach ($existing_qrs as $qr_data) {
                $this->maybe_delete_qr_file($qr_data);
            }
        }

        delete_post_meta($ticket_id, '_eventosapp_qr_codes');
        foreach (self::QR_TYPES as $type => $label) {
            delete_post_meta($ticket_id, '_eventosapp_qr_' . $type);
        }
    }

    /**
     * Agrega el metabox de QR codes
     */
    public function add_qr_metabox() {
        add_meta_box(
            'eventosapp_qr_codes',
            'Códigos QR por Medio',
            array($this, 'render_qr_metabox'),
            'eventosapp_ticket',
            'side',
            'high'
        );
    }

    /**
     * Renderiza el metabox con todos los QR codes
     */
    public function render_qr_metabox($post) {
        $ticket_id = $post->ID;
        
        echo '<div class="eventosapp-qr-container">';
        echo '<style>
            .eventosapp-qr-container { padding: 10px 0; }
            .eventosapp-qr-item { 
                margin-bottom: 20px; 
                padding: 15px;
                background: #f9f9f9;
                border-radius: 5px;
                border: 1px solid #ddd;
            }
            .eventosapp-qr-item h4 { 
                margin: 0 0 10px 0; 
                color: #23282d;
                font-size: 14px;
                font-weight: 600;
            }
            .eventosapp-qr-item img { 
                max-width: 100%; 
                height: auto;
                display: block;
                margin: 10px 0;
                background: white;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 3px;
            }
            .eventosapp-qr-info {
                font-size: 12px;
                color: #666;
                margin-top: 8px;
                padding: 8px;
                background: white;
                border-radius: 3px;
            }
            .eventosapp-qr-info strong {
                color: #23282d;
            }
            .eventosapp-qr-download {
                display: inline-block;
                margin-top: 8px;
                padding: 6px 12px;
                background: #0073aa;
                color: white;
                text-decoration: none;
                border-radius: 3px;
                font-size: 12px;
            }
            .eventosapp-qr-download:hover {
                background: #005177;
                color: white;
            }
            .eventosapp-qr-regenerate {
                margin-top: 15px;
                text-align: center;
            }
            .eventosapp-qr-regenerate .button {
                width: 100%;
            }
            .eventosapp-qr-badge-url {
                font-size: 11px;
                color: #10b981;
                background: #d1fae5;
                padding: 8px;
                border-radius: 3px;
                margin-top: 8px;
                word-break: break-all;
            }
        </style>';

        if ($this->is_virtual_ticket($ticket_id)) {
            $this->cleanup_qr_codes_for_virtual_ticket($ticket_id);
            echo '<div class="eventosapp-qr-item">';
            echo '<h4>🎥 Ticket virtual</h4>';
            echo '<p>Este ticket pertenece a modalidad virtual y no requiere códigos QR.</p>';
            echo '<p class="description">El acceso se gestiona desde la landing virtual del ticket y el anexo requerido es el archivo ICS.</p>';
            echo '</div>';
            echo '</div>';
            return;
        }
        
        foreach (self::QR_TYPES as $type => $label) {
            $qr_data = $this->get_qr_code($ticket_id, $type);
            
            echo '<div class="eventosapp-qr-item">';
            echo '<h4>📱 ' . esc_html($label) . '</h4>';
            
            if ($qr_data && isset($qr_data['url'])) {
                echo '<img src="' . esc_url($qr_data['url']) . '" alt="QR ' . esc_attr($label) . '" />';
                
                echo '<div class="eventosapp-qr-info">';
                echo '<strong>ID:</strong> ' . esc_html($qr_data['qr_id']) . '<br>';
                echo '<strong>Creado:</strong> ' . esc_html($qr_data['created_date']);
                echo '</div>';
                
                // Si es badge, mostrar la URL que contiene el QR
                if ($type === 'badge' && isset($qr_data['badge_url'])) {
                    echo '<div class="eventosapp-qr-badge-url">';
                    echo '<strong>🔗 URL del QR:</strong><br>';
                    echo esc_html($qr_data['badge_url']);
                    echo '</div>';
                }
                
                echo '<a href="' . esc_url($qr_data['url']) . '" download="qr-' . esc_attr($type) . '-' . $ticket_id . '.png" class="eventosapp-qr-download">⬇️ Descargar QR</a>';
            } else {
                echo '<p style="color: #999; font-style: italic;">QR no generado aún</p>';
            }
            
            echo '</div>';
        }
        
        echo '<div class="eventosapp-qr-regenerate">';
        echo '<button type="button" class="button button-secondary" onclick="eventosappRegenerateQR(' . $ticket_id . ')">🔄 Regenerar todos los QR</button>';
        echo '</div>';
        
        echo '</div>';
        
        // JavaScript para regenerar QR
        ?>
        <script>
        function eventosappRegenerateQR(ticketId) {
            if (!confirm('¿Estás seguro de que deseas regenerar todos los códigos QR? Esto sobrescribirá los existentes.')) {
                return;
            }
            
            var button = event.target;
            button.disabled = true;
            button.textContent = '⏳ Regenerando...';
            
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'eventosapp_regenerate_qr',
                    ticket_id: ticketId,
                    nonce: '<?php echo wp_create_nonce('eventosapp_regenerate_qr'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        alert('✅ Códigos QR regenerados exitosamente');
                        location.reload();
                    } else {
                        alert('❌ Error: ' + response.data.message);
                        button.disabled = false;
                        button.textContent = '🔄 Regenerar todos los QR';
                    }
                },
                error: function() {
                    alert('❌ Error de conexión');
                    button.disabled = false;
                    button.textContent = '🔄 Regenerar todos los QR';
                }
            });
        }
        </script>
        <?php
    }

    /**
     * Genera todos los códigos QR para un ticket
     */
    public function generate_all_qr_codes($ticket_id) {
        // Verificar que es un ticket válido
        if (get_post_type($ticket_id) !== 'eventosapp_ticket') {
            return;
        }

        if ($this->is_virtual_ticket($ticket_id)) {
            $this->cleanup_qr_codes_for_virtual_ticket($ticket_id);
            return;
        }

        // Verificar si ya existen QR codes
        $existing_qrs = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
        if (!empty($existing_qrs) && is_array($existing_qrs)) {
            // Ya existen QR codes, no regenerar automáticamente
            return;
        }

        // Generar código de seguridad si no existe
        $this->ensure_security_code($ticket_id);

        // Generar QR codes para cada tipo
        foreach (self::QR_TYPES as $type => $label) {
            $this->generate_qr_code($ticket_id, $type);
        }
    }

    /**
     * Asegura que el ticket tenga un código de seguridad
     */
    private function ensure_security_code($ticket_id) {
        $security_code = get_post_meta($ticket_id, '_eventosapp_badge_security_code', true);
        
        if (empty($security_code)) {
            // Generar código de 4 dígitos aleatorio
            $security_code = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            update_post_meta($ticket_id, '_eventosapp_badge_security_code', $security_code);
        }
        
        return $security_code;
    }


    /**
     * Valida que un registro de QR tenga contenido y archivo físico disponible.
     * Esto evita que un ticket parezca completo solo porque conserva metadata,
     * aunque el archivo PNG haya sido borrado, movido o no exista en uploads.
     *
     * @param array|string $qr_data Datos del QR guardados en _eventosapp_qr_codes.
     * @param string       $type    Tipo de QR esperado.
     * @return bool
     */
    private function qr_data_has_valid_file($qr_data, $type) {
        if (!is_array($qr_data)) {
            return false;
        }

        if (empty($qr_data['content'])) {
            return false;
        }

        if (!empty($qr_data['type']) && (string) $qr_data['type'] !== (string) $type) {
            return false;
        }

        $path = '';

        if (!empty($qr_data['path'])) {
            $path = (string) $qr_data['path'];
        } elseif (!empty($qr_data['url'])) {
            $upload  = wp_upload_dir();
            $baseurl = isset($upload['baseurl']) ? (string) $upload['baseurl'] : '';
            $basedir = isset($upload['basedir']) ? (string) $upload['basedir'] : '';
            $url     = strtok((string) $qr_data['url'], '?');

            if ($baseurl !== '' && $basedir !== '' && strpos($url, $baseurl) === 0) {
                $path = str_replace($baseurl, $basedir, $url);
            }
        }

        if ($path === '') {
            return false;
        }

        return file_exists($path) && is_file($path) && filesize($path) > 0;
    }

    /**
     * Elimina de forma segura el archivo físico de un QR inválido, si existe.
     * No borra archivos fuera del directorio de uploads.
     *
     * @param array $qr_data Datos del QR.
     * @return bool
     */
    private function maybe_delete_qr_file($qr_data) {
        if (!is_array($qr_data) || empty($qr_data['path'])) {
            return false;
        }

        $path   = (string) $qr_data['path'];
        $upload = wp_upload_dir();
        $basedir = isset($upload['basedir']) ? trailingslashit((string) $upload['basedir']) : '';

        if ($path === '' || $basedir === '' || strpos($path, $basedir) !== 0) {
            return false;
        }

        if (file_exists($path) && is_file($path)) {
            return @unlink($path);
        }

        return false;
    }

/**
     * Valida que el contenido del QR sea compatible con el sistema de check-in
     * MODIFICADO: Añade validación para formato GWALLET de Google Wallet
     */
    private function validate_qr_content($content, $type) {
        // Si es badge (URL), validar que sea una URL
        if ($type === 'badge') {
            return strpos($content, 'http') === 0;
        }
        
        // Si es Google Wallet, validar formato GWALLET:ticketID:qr_id
        if ($type === 'google_wallet') {
            // Debe empezar con GWALLET:
            if (strpos($content, 'GWALLET:') !== 0) {
                error_log("EventosApp QR Manager: QR de Google Wallet no tiene formato GWALLET:");
                return false;
            }
            
            // Debe tener al menos 3 partes separadas por :
            $parts = explode(':', $content);
            if (count($parts) < 3) {
                error_log("EventosApp QR Manager: QR de Google Wallet no tiene suficientes partes (formato: GWALLET:ticketID:qr_id)");
                return false;
            }
            
            // Validar que el ticketID no esté vacío
            if (empty($parts[1])) {
                error_log("EventosApp QR Manager: QR de Google Wallet tiene ticketID vacío");
                return false;
            }
            
            // Validar que el qr_id corto no esté vacío
            if (empty($parts[2])) {
                error_log("EventosApp QR Manager: QR de Google Wallet tiene qr_id vacío");
                return false;
            }
            
            return true;
        }
        
        // Para otros tipos, validar estructura base64 + JSON
        $decoded = base64_decode($content, true);
        if ($decoded === false) {
            error_log("EventosApp QR Manager: QR tipo $type no es base64 válido");
            return false;
        }
        
        $data = @json_decode($decoded, true);
        if (!is_array($data) || !isset($data['ticket_id']) || !isset($data['qr_id']) || !isset($data['type'])) {
            error_log("EventosApp QR Manager: QR tipo $type no contiene estructura JSON válida");
            return false;
        }
        
        if ($data['type'] !== $type) {
            error_log("EventosApp QR Manager: Tipo en JSON ({$data['type']}) no coincide con tipo esperado ($type)");
            return false;
        }
        
        return true;
    }

/**
     * Genera un código QR para un tipo específico
     * SIMPLIFICADO: Usa formato ticketID-tag en lugar de base64+JSON
     */
    public function generate_qr_code($ticket_id, $type) {
        if (!class_exists('QRcode')) {
            if (!$this->load_qr_library()) {
                error_log("EventosApp QR Manager: No se pudo cargar la librería QR");
                return false;
            }
        }
        
        // Asegurar que el ticket existe
        if (get_post_type($ticket_id) !== 'eventosapp_ticket') {
            error_log("EventosApp QR Manager: Ticket inválido - $ticket_id");
            return false;
        }

        if ($this->is_virtual_ticket($ticket_id)) {
            $this->cleanup_qr_codes_for_virtual_ticket($ticket_id);
            error_log("EventosApp QR Manager: ticket virtual $ticket_id no requiere QR tipo $type");
            return false;
        }
        
        // === BADGE: Sistema especial que NO se modifica ===
        if ($type === 'badge') {
            return $this->generate_badge_qr($ticket_id);
        }
        
        // === SISTEMA SIMPLIFICADO para Email, Google Wallet, Apple Wallet, PDF y WhatsApp ===
        
        // 1) Obtener el ticketID único
        $unique_ticket_id = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
        if (empty($unique_ticket_id)) {
            error_log("EventosApp QR Manager: No se encontró eventosapp_ticketID para ticket $ticket_id");
            return false;
        }
        
        // 2) Determinar el tag según el tipo
        $tags = array(
            'email' => 'email',
            'google_wallet' => 'gwallet',
            'apple_wallet' => 'awallet',
            'pdf' => 'pdf',
            'whatsapp' => 'whatsapp'
        );
        
        if (!isset($tags[$type])) {
            error_log("EventosApp QR Manager: Tipo de QR inválido - $type");
            return false;
        }
        
        $tag = $tags[$type];
        
        // 3) Crear el contenido del QR: ticketID-tag
        $qr_content = $unique_ticket_id . '-' . $tag;
        
        // 4) Generar ID único para este QR
        $qr_id = uniqid('qr_' . $type . '_', true);
        
        // 5) Generar la imagen del QR
        $upload = wp_upload_dir();
        $qr_dir = trailingslashit($upload['basedir']) . 'eventosapp-qr/';
        
        if (!file_exists($qr_dir)) {
            wp_mkdir_p($qr_dir);
        }
        
        $filename = 'qr-' . $type . '-' . $ticket_id . '-' . time() . '.png';
        $qr_path = $qr_dir . $filename;
        $qr_url = trailingslashit($upload['baseurl']) . 'eventosapp-qr/' . $filename;
        
        try {
            // Generar QR con tamaño 6 (mediano) y margen 2
            QRcode::png($qr_content, $qr_path, QR_ECLEVEL_M, 6, 2);
            
            if (!file_exists($qr_path)) {
                error_log("EventosApp QR Manager: No se pudo crear el archivo QR en $qr_path");
                return false;
            }
        } catch (Exception $e) {
            error_log("EventosApp QR Manager: Error generando QR - " . $e->getMessage());
            return false;
        }
        
        // 6) Preparar datos del QR
        $qr_data = array(
            'qr_id' => $qr_id,
            'type' => $type,
            'content' => $qr_content,
            'url' => $qr_url,
            'path' => $qr_path,
            'created_date' => current_time('mysql')
        );
        
        // 7) Guardar en meta (individual y en array consolidado)
        update_post_meta($ticket_id, '_eventosapp_qr_' . $type, $qr_id);
        
        $all_qr_codes = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
        if (!is_array($all_qr_codes)) {
            $all_qr_codes = array();
        }
        $all_qr_codes[$type] = $qr_data;
        update_post_meta($ticket_id, '_eventosapp_qr_codes', $all_qr_codes);
        
        error_log("EventosApp QR Manager: QR generado exitosamente - Tipo: $type, Content: $qr_content, Ticket: $ticket_id");
        
        return $qr_data;
    }
    
/**
     * Genera el QR para badge con URL correcta a networking/global
     */
    private function generate_badge_qr($ticket_id) {
        // Asegurar código de seguridad
        $this->ensure_security_code($ticket_id);
        
        $security_code = get_post_meta($ticket_id, '_eventosapp_badge_security_code', true);
        if (empty($security_code)) {
            error_log("EventosApp QR Manager: No se pudo generar código de seguridad para badge");
            return false;
        }
        
        // Obtener datos necesarios
        $evento_id = get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
        $unique_ticket_id = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
        
        if (!$evento_id || !$unique_ticket_id) {
            error_log("EventosApp QR Manager: Faltan datos para generar badge QR");
            return false;
        }
        
        // Construir URL del QR (formato especial para badge)
        // CORREGIDO: Apunta a networking/global donde está el shortcode
        $site_url = get_site_url();
        $qr_badge_url = add_query_arg(
            array(
                'event' => $evento_id . '-ticketid=' . $unique_ticket_id . '-' . $security_code
            ),
            trailingslashit($site_url) . 'networking/global'
        );
        
        // Generar imagen del QR
        $upload = wp_upload_dir();
        $qr_dir = trailingslashit($upload['basedir']) . 'eventosapp-qr/';
        
        if (!file_exists($qr_dir)) {
            wp_mkdir_p($qr_dir);
        }
        
        $filename = 'qr-badge-' . $ticket_id . '-' . time() . '.png';
        $qr_path = $qr_dir . $filename;
        $qr_url = trailingslashit($upload['baseurl']) . 'eventosapp-qr/' . $filename;
        
        try {
            QRcode::png($qr_badge_url, $qr_path, QR_ECLEVEL_M, 6, 2);
            
            if (!file_exists($qr_path)) {
                error_log("EventosApp QR Manager: No se pudo crear el archivo QR badge en $qr_path");
                return false;
            }
        } catch (Exception $e) {
            error_log("EventosApp QR Manager: Error generando QR badge - " . $e->getMessage());
            return false;
        }
        
        // Generar ID único
        $qr_id = uniqid('qr_badge_', true);
        
        // Preparar datos del QR
        $qr_data = array(
            'qr_id' => $qr_id,
            'type' => 'badge',
            'content' => $qr_badge_url,
            'badge_url' => $qr_badge_url,
            'url' => $qr_url,
            'path' => $qr_path,
            'created_date' => current_time('mysql')
        );
        
        // Guardar en meta
        update_post_meta($ticket_id, '_eventosapp_qr_badge', $qr_id);
        
        // CORREGIDO: Variable $all_qr_codes (no $all_qr_data)
        $all_qr_codes = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
        if (!is_array($all_qr_codes)) {
            $all_qr_codes = array();
        }
        $all_qr_codes['badge'] = $qr_data;
        update_post_meta($ticket_id, '_eventosapp_qr_codes', $all_qr_codes);
        
        error_log("EventosApp QR Manager: QR Badge generado exitosamente - Ticket: $ticket_id, URL: $qr_badge_url");
        
        return $qr_data;
    }

/**
     * Prepara el contenido del código QR
     * MODIFICADO: 
     * - Para tipo "badge" genera URL con eventosapp_ticketID
     * - Para tipo "google_wallet" genera formato corto: GWALLET:{ticketID}
     * - Para otros tipos usa base64(JSON)
     */
    private function prepare_qr_content($ticket_id, $type, $qr_id) {
        // Si es QR de badge (escarapela), generar URL
        if ($type === 'badge') {
            $evento_id = (int) get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true);
            $security_code = get_post_meta($ticket_id, '_eventosapp_badge_security_code', true);
            
            // Obtener el eventosapp_ticketID (ID único) en lugar del Post ID
            $unique_ticket_id = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
            
            // Si no existe el ID único, generarlo
            if (empty($unique_ticket_id)) {
                $unique_ticket_id = $this->generate_ticket_code($ticket_id);
            }
            
            // Construir URL: https://sitio.com/networking/global/?event=123-ticketid=ABC123-7890
            $site_url = home_url();
            $badge_url = $site_url . '/networking/global/?event=' . $evento_id . '-ticketid=' . $unique_ticket_id . '-' . $security_code;
            
            return $badge_url;
        }
        
        // Si es QR de Google Wallet, usar formato corto
        if ($type === 'google_wallet') {
            // Obtener el ticketID único
            $unique_ticket_id = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
            
            // Si no existe, generarlo
            if (empty($unique_ticket_id)) {
                $unique_ticket_id = $this->generate_ticket_code($ticket_id);
            }
            
            // Formato corto: GWALLET:{ticketID}:{qr_id_corto}
            // Usamos solo los primeros 8 caracteres del qr_id para mantenerlo corto
            $qr_id_short = substr($qr_id, -8);
            
            return 'GWALLET:' . $unique_ticket_id . ':' . $qr_id_short;
        }
        
        // Para otros tipos, usar el método tradicional (base64)
        $data = array(
            'ticket_id' => $ticket_id,
            'qr_id' => $qr_id,
            'type' => $type,
            'timestamp' => time()
        );
        
        // Convertir a JSON
        $json_data = json_encode($data);
        
        // Codificar en base64 para hacer el QR más compacto
        return base64_encode($json_data);
    }

    /**
     * Crea la imagen del código QR
     */
    private function create_qr_image($content, $qr_id) {
        // Verificar que la clase QRcode existe
        if (!class_exists('QRcode')) {
            error_log('EventosApp QR Manager: Clase QRcode no disponible');
            return false;
        }
        
        // Directorio para guardar QR codes
        $upload_dir = wp_upload_dir();
        $qr_dir = $upload_dir['basedir'] . '/eventosapp-qr/';
        
        if (!file_exists($qr_dir)) {
            wp_mkdir_p($qr_dir);
        }
        
        // Nombre del archivo
        $filename = 'qr-' . sanitize_file_name($qr_id) . '.png';
        $file_path = $qr_dir . $filename;
        
        // Generar el código QR usando los parámetros correctos
        try {
            // Parámetros: (data, filename, errorCorrectionLevel, pixelSize, frameSize)
            // 'L' = Low error correction (7% de recuperación)
            // 6 = tamaño del pixel
            // 2 = margen en módulos
            QRcode::png($content, $file_path, 'L', 6, 2);
            
            if (file_exists($file_path)) {
                return array(
                    'path' => $file_path,
                    'url' => $upload_dir['baseurl'] . '/eventosapp-qr/' . $filename . '?v=' . time()
                );
            }
        } catch (Exception $e) {
            error_log('EventosApp QR Manager Error: ' . $e->getMessage());
        }
        
        return false;
    }

/**
     * Genera o recupera el código único del ticket (eventosapp_ticketID)
     * Este código debe ser persistente y único por ticket
     */
    private function generate_ticket_code($ticket_id) {
        // Primero intentar obtener el eventosapp_ticketID existente
        $existing_code = get_post_meta($ticket_id, 'eventosapp_ticketID', true);
        
        if (!empty($existing_code)) {
            error_log("EventosApp QR Manager: Usando eventosapp_ticketID existente para ticket $ticket_id: $existing_code");
            return $existing_code;
        }
        
        // Si no existe, generar uno nuevo
        $code = 'TKT-' . strtoupper(substr(md5($ticket_id . microtime(true)), 0, 10));
        
        // Guardarlo en eventosapp_ticketID (el campo que usa el check-in)
        update_post_meta($ticket_id, 'eventosapp_ticketID', $code);
        
        error_log("EventosApp QR Manager: Generado nuevo eventosapp_ticketID para ticket $ticket_id: $code");
        
        return $code;
    }

    /**
     * Obtiene los datos de un código QR específico
     */
    public function get_qr_code($ticket_id, $type) {
        $all_qr_codes = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
        
        if (is_array($all_qr_codes) && isset($all_qr_codes[$type])) {
            return $all_qr_codes[$type];
        }
        
        return false;
    }

    /**
     * Obtiene todos los códigos QR de un ticket
     */
    public function get_all_qr_codes($ticket_id) {
        $all_qr_codes = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
        
        if (!is_array($all_qr_codes)) {
            return array();
        }
        
        return $all_qr_codes;
    }

    /**
     * AJAX: Regenerar todos los QR codes
     */
    public function ajax_regenerate_qr() {
        // Verificar nonce
        check_ajax_referer('eventosapp_regenerate_qr', 'nonce');
        
        // Verificar permisos
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Permisos insuficientes'));
        }
        
        $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
        
        if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
            wp_send_json_error(array('message' => 'Ticket inválido'));
        }
        
        // Eliminar QR codes anteriores
        $old_qr_codes = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
        if (is_array($old_qr_codes)) {
            foreach ($old_qr_codes as $type => $qr_data) {
                if (isset($qr_data['path']) && file_exists($qr_data['path'])) {
                    @unlink($qr_data['path']);
                }
            }
        }
        
        // Eliminar metadatos
        delete_post_meta($ticket_id, '_eventosapp_qr_codes');
        foreach (self::QR_TYPES as $type => $label) {
            delete_post_meta($ticket_id, '_eventosapp_qr_' . $type);
        }
        
        // Asegurar código de seguridad
        $this->ensure_security_code($ticket_id);
        
        // Regenerar QR codes
        $generated = 0;
        foreach (self::QR_TYPES as $type => $label) {
            if ($this->generate_qr_code($ticket_id, $type)) {
                $generated++;
            }
        }
        
        if ($generated > 0) {
            wp_send_json_success(array(
                'message' => 'QR codes regenerados exitosamente',
                'generated' => $generated
            ));
        } else {
            wp_send_json_error(array('message' => 'Error al regenerar QR codes'));
        }
    }

/**
     * Decodifica el contenido de un código QR
     * SIMPLIFICADO: Maneja formato ticketID-tag y URLs de badge
     */
    public static function decode_qr_content($qr_content) {
        global $wpdb;
        
        // === CASO 1: QR de Badge (URL) ===
        if (strpos($qr_content, 'http') === 0) {
            $url_parts = parse_url($qr_content);
            if (isset($url_parts['query'])) {
                parse_str($url_parts['query'], $params);
                
                if (isset($params['event'])) {
                    // Formato: event=123-ticketid=ABC123-7890
                    $parts = explode('-', $params['event']);
                    
                    if (count($parts) >= 3) {
                        $event_id = intval($parts[0]);
                        
                        $ticket_part = isset($parts[1]) ? $parts[1] : '';
                        $unique_ticket_id = '';
                        if (strpos($ticket_part, 'ticketid=') === 0) {
                            $unique_ticket_id = str_replace('ticketid=', '', $ticket_part);
                        }
                        
                        $security_code = isset($parts[2]) ? $parts[2] : '';
                        
                        return array(
                            'unique_ticket_id' => $unique_ticket_id,
                            'event_id' => $event_id,
                            'security_code' => $security_code,
                            'type' => 'badge',
                            'format' => 'url'
                        );
                    }
                }
            }
            
            return false;
        }
        
        // === CASO 2: Formato simplificado ticketID-tag ===
        if (strpos($qr_content, '-') !== false) {
            $parts = explode('-', $qr_content);
            
            // Debe tener exactamente 2 partes: ticketID y tag
            if (count($parts) === 2) {
                $unique_ticket_id = $parts[0];
                $tag = $parts[1];
                
                // Mapear tags a tipos
                $tag_to_type = array(
                    'email' => 'email',
                    'gwallet' => 'google_wallet',
                    'awallet' => 'apple_wallet',
                    'pdf' => 'pdf',
                    'whatsapp' => 'whatsapp'
                );
                
                if (isset($tag_to_type[$tag])) {
                    // Buscar el ticket ID (post ID) usando el ticketID único
                    $ticket_post_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} 
                        WHERE meta_key = 'eventosapp_ticketID' 
                        AND meta_value = %s 
                        LIMIT 1",
                        $unique_ticket_id
                    ));
                    
                    if ($ticket_post_id && get_post_type($ticket_post_id) === 'eventosapp_ticket') {
                        return array(
                            'unique_ticket_id' => $unique_ticket_id,
                            'ticket_id' => (int) $ticket_post_id,
                            'type' => $tag_to_type[$tag],
                            'tag' => $tag,
                            'format' => 'simple'
                        );
                    }
                }
            }
        }
        
        return false;
    }

/**
     * Valida un código QR
     * SIMPLIFICADO: Valida formato ticketID-tag y URLs de badge
     */
    public static function validate_qr($qr_content) {
        global $wpdb;
        
        $data = self::decode_qr_content($qr_content);
        
        if (!$data) {
            return array(
                'valid' => false,
                'message' => 'Código QR inválido o formato no reconocido'
            );
        }
        
        // === VALIDACIÓN PARA BADGE (URL) ===
        if (isset($data['format']) && $data['format'] === 'url' && $data['type'] === 'badge') {
            $unique_ticket_id = $data['unique_ticket_id'];
            
            // Buscar el Post ID por eventosapp_ticketID
            $ticket_post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = 'eventosapp_ticketID' 
                AND meta_value = %s 
                LIMIT 1",
                $unique_ticket_id
            ));
            
            if (!$ticket_post_id || get_post_type($ticket_post_id) !== 'eventosapp_ticket') {
                return array(
                    'valid' => false,
                    'message' => 'Ticket de badge no encontrado'
                );
            }
            
            // Validar código de seguridad
            $stored_security_code = get_post_meta($ticket_post_id, '_eventosapp_badge_security_code', true);
            
            if (empty($stored_security_code) || $stored_security_code !== $data['security_code']) {
                return array(
                    'valid' => false,
                    'message' => 'Código de seguridad del badge inválido'
                );
            }
            
            return array(
                'valid' => true,
                'ticket_id' => $ticket_post_id,
                'type' => 'badge',
                'format' => 'url',
                'type_label' => 'Escarapela Impresa'
            );
        }
        
        // === VALIDACIÓN PARA FORMATO SIMPLIFICADO ===
        if (isset($data['format']) && $data['format'] === 'simple' && isset($data['ticket_id'])) {
            $ticket_id = $data['ticket_id'];
            $type = $data['type'];
            
            // Verificar que el ticket existe
            if (get_post_type($ticket_id) !== 'eventosapp_ticket') {
                return array(
                    'valid' => false,
                    'message' => 'Ticket no encontrado'
                );
            }
            
            // Verificar que el QR ID existe para este tipo
            // (esto previene el uso de QR revocados/regenerados)
            $stored_qr_id = get_post_meta($ticket_id, '_eventosapp_qr_' . $type, true);
            
            if (empty($stored_qr_id)) {
                return array(
                    'valid' => false,
                    'message' => 'QR no válido o no generado'
                );
            }
            
            // Labels para tipos
            $type_labels = array(
                'email' => 'Email',
                'google_wallet' => 'Google Wallet',
                'apple_wallet' => 'Apple Wallet',
                'pdf' => 'PDF Impreso',
                'whatsapp' => 'WhatsApp'
            );
            
            return array(
                'valid' => true,
                'ticket_id' => $ticket_id,
                'type' => $type,
                'tag' => $data['tag'],
                'format' => 'simple',
                'type_label' => isset($type_labels[$type]) ? $type_labels[$type] : $type
            );
        }
        
        return array(
            'valid' => false,
            'message' => 'No se pudo validar el código QR'
        );
    }

/**
     * Elimina todos los códigos QR de un ticket (método público para batch)
     * 
     * @param int $ticket_id ID del ticket
     * @return bool True si se eliminaron, false si hubo error
     */
    public function delete_all_qr_codes_public($ticket_id) {
        if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
            return false;
        }

        // Eliminar archivos físicos
        $old_qr_codes = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
        if (is_array($old_qr_codes)) {
            foreach ($old_qr_codes as $type => $qr_data) {
                if (isset($qr_data['path']) && file_exists($qr_data['path'])) {
                    @unlink($qr_data['path']);
                }
            }
        }
        
        // Eliminar metadatos
        delete_post_meta($ticket_id, '_eventosapp_qr_codes');
        foreach (self::QR_TYPES as $type => $label) {
            delete_post_meta($ticket_id, '_eventosapp_qr_' . $type);
        }

        return true;
    }

    /**
     * Regenera todos los códigos QR de un ticket (forzado, sin verificación de existencia)
     * 
     * @param int $ticket_id ID del ticket
     * @param bool $force Si true, elimina los existentes antes de regenerar
     * @return int Cantidad de QR generados exitosamente
     */
    public function regenerate_all_qr_codes_forced($ticket_id, $force = true) {
        if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
            return 0;
        }

        if ($this->is_virtual_ticket($ticket_id)) {
            $this->cleanup_qr_codes_for_virtual_ticket($ticket_id);
            return 0;
        }

        // Si force = true, eliminar QR existentes
        if ($force) {
            $this->delete_all_qr_codes_public($ticket_id);
        }

        // Asegurar código de seguridad
        $this->ensure_security_code($ticket_id);

        // Regenerar QR codes para cada tipo
        $generated = 0;
        foreach (self::QR_TYPES as $type => $label) {
            if ($this->generate_qr_code($ticket_id, $type)) {
                $generated++;
            }
        }

        return $generated;
    }

    /**
     * Obtiene la instancia singleton de la clase (para uso en batch)
     * 
     * @return EventosApp_QR_Manager|null
     */
    public static function get_instance() {
        global $eventosapp_qr_manager_instance;
        
        if (!isset($eventosapp_qr_manager_instance) || !($eventosapp_qr_manager_instance instanceof self)) {
            $eventosapp_qr_manager_instance = new self();
        }
        
        return $eventosapp_qr_manager_instance;
    }

/**
     * Verifica si un ticket tiene todos los QR nuevos generados
     * 
     * @param int $ticket_id ID del ticket
     * @return bool True si tiene todos los QR, false si falta alguno
     */
    public function has_all_new_qr_codes($ticket_id) {
        if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
            return false;
        }

        $all_qr_codes = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
        
        if (!is_array($all_qr_codes) || empty($all_qr_codes)) {
            return false;
        }

        // Verificar que existan todos los tipos de QR y que el archivo físico exista.
        foreach (self::QR_TYPES as $type => $label) {
            if (!isset($all_qr_codes[$type]) || !$this->qr_data_has_valid_file($all_qr_codes[$type], $type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Genera solo los QR faltantes (no regenera los existentes)
     * 
     * @param int $ticket_id ID del ticket
     * @return array ['generated' => int, 'skipped' => int, 'failed' => int]
     */
    public function generate_missing_qr_codes($ticket_id) {
        if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
            return ['generated' => 0, 'skipped' => 0, 'failed' => 0, 'invalidated' => 0, 'regenerated_missing_file' => 0, 'skipped_virtual' => 0];
        }

        $stats = ['generated' => 0, 'skipped' => 0, 'failed' => 0, 'invalidated' => 0, 'regenerated_missing_file' => 0, 'skipped_virtual' => 0];

        if ($this->is_virtual_ticket($ticket_id)) {
            $this->cleanup_qr_codes_for_virtual_ticket($ticket_id);
            $stats['skipped_virtual'] = 1;
            return $stats;
        }
        
        // Asegurar código de seguridad
        $this->ensure_security_code($ticket_id);

        // Obtener QR existentes
        $existing_qrs = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
        if (!is_array($existing_qrs)) {
            $existing_qrs = [];
        }

        foreach (self::QR_TYPES as $type => $label) {
            $existing_for_type = isset($existing_qrs[$type]) && is_array($existing_qrs[$type]) ? $existing_qrs[$type] : [];
            $had_meta          = !empty($existing_for_type);

            // Si la metadata y el archivo físico están correctos, no tocar el QR existente.
            if ($had_meta && $this->qr_data_has_valid_file($existing_for_type, $type)) {
                $stats['skipped']++;
                continue;
            }

            // Si había metadata incompleta o el archivo físico no existe, limpiar solo ese tipo.
            if ($had_meta) {
                $this->maybe_delete_qr_file($existing_for_type);
                unset($existing_qrs[$type]);
                delete_post_meta($ticket_id, '_eventosapp_qr_' . $type);
                update_post_meta($ticket_id, '_eventosapp_qr_codes', $existing_qrs);
                $stats['invalidated']++;
            }

            $result = $this->generate_qr_code($ticket_id, $type);
            
            if ($result && is_array($result)) {
                $stats['generated']++;
                if ($had_meta) {
                    $stats['regenerated_missing_file']++;
                }
            } else {
                $stats['failed']++;
                error_log('EventosApp QR Manager: No se pudo generar QR faltante tipo ' . $type . ' para ticket ' . (int) $ticket_id);
            }

            // Refrescar el array después de cada generación para no pisar cambios hechos por generate_qr_code().
            $existing_qrs = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
            if (!is_array($existing_qrs)) {
                $existing_qrs = [];
            }
        }

        update_post_meta($ticket_id, '_eventosapp_qr_last_integrity_check', [
            'checked_at' => current_time('mysql'),
            'stats'      => $stats,
        ]);

        return $stats;
    }

    /**
     * Regenera TODOS los QR (forzado completo, incluyendo los existentes)
     * 
     * @param int $ticket_id ID del ticket
     * @return int Cantidad de QR generados exitosamente
     */
    public function regenerate_all_qr_codes_complete($ticket_id) {
        if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
            return 0;
        }

        if ($this->is_virtual_ticket($ticket_id)) {
            $this->cleanup_qr_codes_for_virtual_ticket($ticket_id);
            return 0;
        }

        // Eliminar QR existentes físicamente
        $old_qr_codes = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
        if (is_array($old_qr_codes)) {
            foreach ($old_qr_codes as $type => $qr_data) {
                if (isset($qr_data['path']) && file_exists($qr_data['path'])) {
                    @unlink($qr_data['path']);
                }
            }
        }
        
        // Eliminar metadatos
        delete_post_meta($ticket_id, '_eventosapp_qr_codes');
        foreach (self::QR_TYPES as $type => $label) {
            delete_post_meta($ticket_id, '_eventosapp_qr_' . $type);
        }

        // Asegurar código de seguridad
        $this->ensure_security_code($ticket_id);

        // Regenerar todos los QR
        $generated = 0;
        foreach (self::QR_TYPES as $type => $label) {
            if ($this->generate_qr_code($ticket_id, $type)) {
                $generated++;
            }
        }

        return $generated;
    }
    
}

// Inicializar la clase
function eventosapp_qr_manager_init() {
    global $eventosapp_qr_manager_instance;
    
    if (!isset($eventosapp_qr_manager_instance)) {
        $eventosapp_qr_manager_instance = new EventosApp_QR_Manager();
    }
    
    return $eventosapp_qr_manager_instance;
}
add_action('plugins_loaded', 'eventosapp_qr_manager_init');
