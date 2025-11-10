<?php
/**
 * Admin: Auto-Creación de Páginas de Networking con Autenticación por evento
 * - Crea/gestiona una página hija de /networking/ para cada evento
 * - Shortcode: [eventosapp_qr_networking_auth event="123"]
 * - Se crea automáticamente al publicar un evento
 * - Permite editar el slug desde un metabox en el CPT evento
 */

if ( ! defined('ABSPATH') ) exit;

/* ============================================================
 *  Helpers de Landing (/networking/{slug})
 * ============================================================ */

if ( ! function_exists('eventosapp_networking_parent_slug') ) {
    function eventosapp_networking_parent_slug() { 
        return 'networking'; 
    }
}

if ( ! function_exists('eventosapp_networking_parent_title') ) {
    function eventosapp_networking_parent_title() { 
        return 'Networking'; 
    }
}

/** Obtiene o crea (si falta) la página padre /networking/ */
if ( ! function_exists('eventosapp_networking_ensure_parent') ) {
    function eventosapp_networking_ensure_parent() {
        $parent_slug  = eventosapp_networking_parent_slug();
        $parent       = get_page_by_path($parent_slug);
        
        if ( $parent && $parent->post_type === 'page' && $parent->post_status !== 'trash' ) {
            return (int) $parent->ID;
        }
        
        $parent_id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => eventosapp_networking_parent_title(),
            'post_name'    => $parent_slug,
            'post_parent'  => 0,
            'post_content' => '',
        ], true);
        
        return is_wp_error($parent_id) ? 0 : (int) $parent_id;
    }
}

/** Slug por defecto del evento (prefijo "networking" + nombre con guiones) */
if ( ! function_exists('eventosapp_networking_default_slug_for_event') ) {
    function eventosapp_networking_default_slug_for_event($event_id) {
        $title = get_the_title($event_id);
        $base_slug = sanitize_title($title ?: "evento-$event_id");
        
        // Agregar prefijo "networking-" 
        return 'networking-' . $base_slug;
    }
}

/** Lee el slug guardado (o devuelve el default) */
if ( ! function_exists('eventosapp_networking_get_slug') ) {
    function eventosapp_networking_get_slug($event_id) {
        $slug = get_post_meta($event_id, '_eventosapp_networking_slug', true);
        if ( ! $slug ) {
            $slug = eventosapp_networking_default_slug_for_event($event_id);
        }
        return sanitize_title($slug);
    }
}

/** Construye la URL esperada a partir del slug */
if ( ! function_exists('eventosapp_networking_build_url') ) {
    function eventosapp_networking_build_url($event_id) {
        $parent_slug = eventosapp_networking_parent_slug();
        $slug        = eventosapp_networking_get_slug($event_id);
        return home_url( '/' . $parent_slug . '/' . $slug . '/' );
    }
}

/** Verifica si existe otra página con el mismo slug (excluyendo la propia página del evento) */
if ( ! function_exists('eventosapp_networking_slug_exists') ) {
    function eventosapp_networking_slug_exists($slug, $event_id) {
        $parent_id = eventosapp_networking_ensure_parent();
        if ( ! $parent_id ) return false;
        
        $page_id_stored = (int) get_post_meta($event_id, '_eventosapp_networking_page_id', true);
        
        global $wpdb;
        $query = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_name = %s 
             AND post_parent = %d 
             AND post_type = 'page' 
             AND post_status != 'trash'
             AND ID != %d
             LIMIT 1",
            $slug,
            $parent_id,
            $page_id_stored
        );
        
        $existing = $wpdb->get_var($query);
        return $existing ? true : false;
    }
}

/** Asegura (crea/actualiza) la página hija del evento y devuelve su ID */
if ( ! function_exists('eventosapp_networking_ensure_child_page') ) {
    function eventosapp_networking_ensure_child_page($event_id) {
        $event_id = (int) $event_id;
        if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
            return 0;
        }

        $parent_id = eventosapp_networking_ensure_parent();
        if ( ! $parent_id ) {
            return 0;
        }

        $page_id   = (int) get_post_meta($event_id, '_eventosapp_networking_page_id', true);
        $slug      = eventosapp_networking_get_slug($event_id);
        $title     = 'Networking: ' . get_the_title($event_id);
        $content   = '[eventosapp_qr_networking_auth event="'. $event_id .'"]';

        // Verificar si el slug está duplicado
        if ( eventosapp_networking_slug_exists($slug, $event_id) ) {
            // Guardar error en meta temporal
            update_post_meta($event_id, '_eventosapp_networking_slug_error', 'duplicate');
            return 0;
        } else {
            // Limpiar error si existía
            delete_post_meta($event_id, '_eventosapp_networking_slug_error');
        }

        // Si ya existe una página asignada
        if ( $page_id ) {
            $p = get_post($page_id);
            if ( $p && $p->post_status !== 'trash' ) {
                $updates = ['ID' => $page_id]; 
                $needs_update = false;
                
                if ( (int)$p->post_parent !== (int)$parent_id ) { 
                    $updates['post_parent'] = $parent_id; 
                    $needs_update = true; 
                }
                if ( $p->post_name !== $slug ) { 
                    $updates['post_name'] = $slug; 
                    $needs_update = true; 
                }
                if ( $p->post_content !== $content ) { 
                    $updates['post_content'] = $content; 
                    $needs_update = true; 
                }
                if ( $p->post_title !== $title ) { 
                    $updates['post_title'] = $title; 
                    $needs_update = true; 
                }
                if ( $p->post_status !== 'publish' ) { 
                    $updates['post_status'] = 'publish'; 
                    $needs_update = true; 
                }
                
                if ( $needs_update ) {
                    wp_update_post($updates);
                }
                
                update_post_meta($event_id, '_eventosapp_networking_url', get_permalink($page_id));
                return $page_id;
            }
        }

        // Crear nueva página
        $new_id = wp_insert_post([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'post_parent'    => $parent_id,
            'post_title'     => $title,
            'post_name'      => $slug,
            'post_content'   => $content,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ], true);

        if ( is_wp_error($new_id) || ! $new_id ) {
            return 0;
        }

        update_post_meta($event_id, '_eventosapp_networking_page_id', (int) $new_id);
        update_post_meta($event_id, '_eventosapp_networking_url', get_permalink($new_id));
        
        return (int) $new_id;
    }
}

/* ============================================================
 *  Metabox en el CPT evento: URL editable (slug) + botón copiar
 * ============================================================ */

add_action('add_meta_boxes', function(){
    add_meta_box(
        'eventosapp_event_networking',
        'Landing de Networking',
        'eventosapp_render_metabox_networking',
        'eventosapp_event',
        'side',
        'high'
    );
});

function eventosapp_render_metabox_networking($post){
    $event_id = (int) $post->ID;
    $slug     = eventosapp_networking_get_slug($event_id);
    $page_id  = (int) get_post_meta($event_id, '_eventosapp_networking_page_id', true);
    $url_meta = get_post_meta($event_id, '_eventosapp_networking_url', true);
    $url_calc = eventosapp_networking_build_url($event_id);
    $url      = $url_meta ?: $url_calc;
    $error    = get_post_meta($event_id, '_eventosapp_networking_slug_error', true);

    wp_nonce_field('eventosapp_networking_save', 'eventosapp_networking_nonce');
    ?>
    <style>
        .evnet-wrap { font-size:13px; line-height:1.5; }
        .evnet-label { display:block; font-weight:600; margin-bottom:6px; color:#1e293b; }
        .evnet-input { width:100%; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; }
        .evnet-url-preview { background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px; margin:8px 0; word-break:break-all; font-size:12px; color:#475569; }
        .evnet-help { font-size:12px; color:#64748b; margin:6px 0; }
        .evnet-actions { display:flex; gap:8px; margin-top:10px; }
        .evnet-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 12px; border:0; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; color:#fff; transition:background .15s; }
        .evnet-btn-primary { background:#2563eb; }
        .evnet-btn-primary:hover { background:#1d4ed8; color:#fff; }
        .evnet-btn-secondary { background:#64748b; }
        .evnet-btn-secondary:hover { background:#475569; color:#fff; }
        .evnet-error { background:#fee; border:1px solid #fcc; border-radius:6px; padding:10px; margin:10px 0; color:#c00; font-size:12px; font-weight:600; }
        .evnet-success { background:#d4edda; border:1px solid #c3e6cb; border-radius:6px; padding:10px; margin:10px 0; color:#155724; font-size:12px; font-weight:600; }
    </style>

    <div class="evnet-wrap">
        <?php if ( $error === 'duplicate' ): ?>
            <div class="evnet-error">
                ⚠️ <strong>URL duplicada:</strong> Ya existe otra página con este slug. Por favor, cambia el slug a continuación.
            </div>
        <?php endif; ?>

        <?php if ( $page_id && !$error ): ?>
            <div class="evnet-success">
                ✓ Página de networking creada correctamente
            </div>
        <?php endif; ?>

        <div style="margin-bottom:14px">
            <label class="evnet-label">Slug de la página (modificable)</label>
            <input type="text" 
                   name="eventosapp_networking_slug" 
                   class="evnet-input" 
                   value="<?php echo esc_attr($slug); ?>"
                   placeholder="networking-nombre-evento">
            <div class="evnet-help">
                Puedes editar el slug para personalizar la URL. Usa solo letras, números y guiones.
            </div>
        </div>

        <div>
            <label class="evnet-label">URL de la página</label>
            <div class="evnet-url-preview" id="evnetUrlPreview">
                <?php echo esc_html($url); ?>
            </div>
        </div>

        <div class="evnet-actions">
            <button type="button" 
                    class="evnet-btn evnet-btn-primary" 
                    id="evnetCopyUrl"
                    title="Copiar URL al portapapeles">
                <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"/>
                    <path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5h3zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3z"/>
                </svg>
                Copiar URL
            </button>

            <?php if ( $page_id ): ?>
                <a href="<?php echo esc_url($url); ?>" 
                   class="evnet-btn evnet-btn-secondary" 
                   target="_blank"
                   title="Ver página en nueva pestaña">
                    <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M8.636 3.5a.5.5 0 0 0-.5-.5H1.5A1.5 1.5 0 0 0 0 4.5v10A1.5 1.5 0 0 0 1.5 16h10a1.5 1.5 0 0 0 1.5-1.5V7.864a.5.5 0 0 0-1 0V14.5a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h6.636a.5.5 0 0 0 .5-.5z"/>
                        <path d="M16 .5a.5.5 0 0 0-.5-.5h-5a.5.5 0 0 0 0 1h3.793L6.146 9.146a.5.5 0 1 0 .708.708L15 1.707V5.5a.5.5 0 0 0 1 0v-5z"/>
                    </svg>
                    Visitar
                </a>
            <?php endif; ?>
        </div>

        <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e2e8f0;">
            <button type="button" 
                    class="button button-secondary" 
                    id="evnetEnsureNow"
                    style="width:100%">
                <span class="dashicons dashicons-admin-page" style="margin-top:3px"></span>
                Crear/Asegurar landing ahora
            </button>
            <div class="evnet-help" style="margin-top:8px">
                <strong>Nota:</strong> La página se crea/actualiza automáticamente al guardar el evento. Usa este botón para crear la landing de inmediato sin guardar.
            </div>
        </div>
    </div>

    <script>
    (function($){
        // Preview en tiempo real del slug
        $('input[name="eventosapp_networking_slug"]').on('input', function(){
            var slug = $(this).val().trim() || 'networking-evento';
            var base = '<?php echo esc_js(home_url('/' . eventosapp_networking_parent_slug() . '/')); ?>';
            $('#evnetUrlPreview').text(base + slug + '/');
        });

        // Copiar URL al portapapeles
        $('#evnetCopyUrl').on('click', function(){
            var url = $('#evnetUrlPreview').text().trim();
            var btn = $(this);
            
            navigator.clipboard.writeText(url).then(function(){
                var originalHtml = btn.html();
                btn.html('<svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/></svg>¡Copiado!');
                btn.css('background', '#059669');
                
                setTimeout(function(){
                    btn.html(originalHtml);
                    btn.css('background', '');
                }, 2000);
            }).catch(function(){
                alert('No se pudo copiar. Selecciona y copia manualmente.');
            });
        });

        // Crear/Asegurar landing ahora (AJAX)
        $('#evnetEnsureNow').on('click', function(e){
            e.preventDefault();
            
            var $btn = $(this);
            var originalText = $btn.html();
            var eventId = <?php echo (int) $event_id; ?>;
            var slug = $('input[name="eventosapp_networking_slug"]').val() || '';
            
            // Deshabilitar botón y mostrar estado de carga
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt" style="animation:rotation 1s infinite linear"></span> Procesando...');
            
            // Hacer petición AJAX
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'eventosapp_networking_ensure_now',
                    event_id: eventId,
                    slug: slug,
                    _wpnonce: '<?php echo esc_js( wp_create_nonce('eventosapp_networking_ensure_now') ); ?>'
                },
                success: function(response){
                    if (response && response.success) {
                        // Actualizar URL en el preview
                        $('#evnetUrlPreview').text(response.data.url);
                        
                        // Mostrar mensaje de éxito
                        alert('¡Landing creada exitosamente!\n\nURL: ' + response.data.url);
                        
                        // Recargar página para actualizar el metabox
                        location.reload();
                    } else {
                        var errorMsg = response.data && response.data.message ? response.data.message : 'No se pudo crear la landing.';
                        alert('Error: ' + errorMsg);
                    }
                },
                error: function(){
                    alert('Error de conexión. Por favor, intenta nuevamente.');
                },
                complete: function(){
                    // Restaurar botón
                    $btn.prop('disabled', false).html(originalText);
                }
            });
        });
    })(jQuery);
    </script>
    <style>
        @keyframes rotation {
            from { transform: rotate(0deg); }
            to { transform: rotate(359deg); }
        }
    </style>
    <?php
}

/* ============================================================
 *  AJAX: Crear/Asegurar landing desde el metabox (creación manual)
 * ============================================================ */

add_action('wp_ajax_eventosapp_networking_ensure_now', function(){
    // Verificar permisos
    if ( ! current_user_can('edit_posts') ) {
        wp_send_json_error(['message' => 'No tienes permisos para realizar esta acción.'], 403);
    }
    
    // Verificar nonce
    $nonce_check = check_ajax_referer('eventosapp_networking_ensure_now', '_wpnonce', false);
    if ( ! $nonce_check ) {
        wp_send_json_error(['message' => 'Error de seguridad. Recarga la página e intenta nuevamente.'], 403);
    }

    // Obtener y validar event_id
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    if ( ! $event_id || get_post_type($event_id) !== 'eventosapp_event' ) {
        wp_send_json_error(['message' => 'ID de evento inválido.'], 400);
    }

    // Obtener y guardar slug si se proporcionó
    $slug = isset($_POST['slug']) ? sanitize_title( wp_unslash($_POST['slug']) ) : '';
    if ( $slug ) {
        update_post_meta($event_id, '_eventosapp_networking_slug', $slug);
    }

    // Crear/asegurar la página
    $page_id = eventosapp_networking_ensure_child_page($event_id);
    
    // Verificar si hubo error de slug duplicado
    $error = get_post_meta($event_id, '_eventosapp_networking_slug_error', true);
    if ( $error === 'duplicate' ) {
        wp_send_json_error([
            'message' => 'Ya existe otra página con este slug. Por favor, cambia el slug e intenta nuevamente.'
        ], 400);
    }
    
    if ( ! $page_id ) {
        wp_send_json_error([
            'message' => 'No se pudo crear la página de networking. Verifica los permisos y la configuración.'
        ], 500);
    }

    // Obtener URL de la página creada
    $url = get_permalink($page_id);
    update_post_meta($event_id, '_eventosapp_networking_url', $url);

    // Respuesta exitosa
    wp_send_json_success([
        'page_id' => $page_id,
        'url' => $url,
        'message' => 'Página de networking creada exitosamente.'
    ]);
});

/* ============================================================
 *  Guardar slug desde el metabox
 * ============================================================ */

add_action('save_post_eventosapp_event', function($post_id, $post, $update) {
    // Verificar nonce
    if ( ! isset($_POST['eventosapp_networking_nonce']) || 
         ! wp_verify_nonce($_POST['eventosapp_networking_nonce'], 'eventosapp_networking_save') ) {
        return;
    }

    // Verificar autosave
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
        return;
    }

    // Verificar permisos
    if ( ! current_user_can('edit_post', $post_id) ) {
        return;
    }

    // Solo para eventos publicados
    if ( $post->post_status !== 'publish' ) {
        return;
    }

    // Guardar slug si se envió
    if ( isset($_POST['eventosapp_networking_slug']) ) {
        $new_slug = sanitize_title($_POST['eventosapp_networking_slug']);
        if ( $new_slug ) {
            update_post_meta($post_id, '_eventosapp_networking_slug', $new_slug);
        }
    }

    // Crear/actualizar página de networking
    eventosapp_networking_ensure_child_page($post_id);

}, 20, 3);

/* ============================================================
 *  Hook para crear automáticamente la página al publicar evento
 * ============================================================ */

add_action('transition_post_status', function($new_status, $old_status, $post) {
    // Solo para eventos que se publican por primera vez o se vuelven a publicar
    if ( $post->post_type !== 'eventosapp_event' ) {
        return;
    }

    if ( $new_status !== 'publish' ) {
        return;
    }

    // Evitar crear en autosave o revisiones
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
        return;
    }

    if ( wp_is_post_revision($post->ID) ) {
        return;
    }

    // Verificar si ya tiene página asignada
    $page_id = (int) get_post_meta($post->ID, '_eventosapp_networking_page_id', true);
    
    // Si no tiene página o la página no existe, crear
    if ( ! $page_id || ! get_post($page_id) ) {
        eventosapp_networking_ensure_child_page($post->ID);
    }

}, 10, 3);

/* ============================================================
 *  Mensaje admin notice si hay error de slug duplicado
 * ============================================================ */

add_action('admin_notices', function() {
    global $post;
    
    if ( ! $post || $post->post_type !== 'eventosapp_event' ) {
        return;
    }

    $error = get_post_meta($post->ID, '_eventosapp_networking_slug_error', true);
    
    if ( $error === 'duplicate' ) {
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <strong>EventosApp:</strong> No se pudo crear la página de networking porque el slug está duplicado. 
                Por favor, edita el slug en el metabox "Landing de Networking" y guarda el evento nuevamente.
            </p>
        </div>
        <?php
    }
});
