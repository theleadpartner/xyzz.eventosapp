<?php
// includes/admin/eventosapp-privacidad.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Metabox: Privacidad y Tratamiento de Datos (por evento)
 * Meta keys:
 *  - _eventosapp_priv_empresa
 *  - _eventosapp_priv_politica_url
 *  - _eventosapp_priv_aviso_url
 */

// Registrar metabox
add_action('add_meta_boxes', function() {
    add_meta_box(
        'eventosapp_privacidad_evento',
        'Privacidad y Tratamiento de Datos',
        'eventosapp_render_metabox_privacidad_evento',
        'eventosapp_event',
        'side',
        'default'
    );
});

// Render del metabox
function eventosapp_render_metabox_privacidad_evento($post){
    $empresa  = get_post_meta($post->ID, '_eventosapp_priv_empresa', true);
    $politica = get_post_meta($post->ID, '_eventosapp_priv_politica_url', true);
    $aviso    = get_post_meta($post->ID, '_eventosapp_priv_aviso_url', true);

    wp_nonce_field('eventosapp_privacidad_guardar', 'eventosapp_privacidad_nonce'); ?>
    <p>
        <label><strong>Empresa organizadora</strong></label><br>
        <input type="text" class="widefat" name="eventosapp_priv_empresa"
               value="<?php echo esc_attr($empresa); ?>"
               placeholder="Ej: EventosApp SAS">
    </p>
    <p>
        <label><strong>URL Política de Privacidad</strong></label><br>
        <input type="url" class="widefat" name="eventosapp_priv_politica_url"
               value="<?php echo esc_url($politica); ?>"
               placeholder="https://…/politica-de-privacidad">
    </p>
    <p>
        <label><strong>URL Aviso de Privacidad / Tratamiento de datos</strong></label><br>
        <input type="url" class="widefat" name="eventosapp_priv_aviso_url"
               value="<?php echo esc_url($aviso); ?>"
               placeholder="https://…/aviso-de-privacidad">
    </p>
    <p style="color:#666;font-size:12px;margin-top:6px">
        Estos datos se usan en el formulario público para el texto y enlaces del consentimiento.
    </p>
<?php }

// Guardado seguro de los campos del metabox
add_action('save_post_eventosapp_event', function($post_id){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Verifica el nonce propio del metabox
    if ( ! isset($_POST['eventosapp_privacidad_nonce']) ||
         ! wp_verify_nonce($_POST['eventosapp_privacidad_nonce'], 'eventosapp_privacidad_guardar') ) {
        return;
    }

    update_post_meta($post_id, '_eventosapp_priv_empresa',
        sanitize_text_field( $_POST['eventosapp_priv_empresa'] ?? '' )
    );
    update_post_meta($post_id, '_eventosapp_priv_politica_url',
        esc_url_raw( $_POST['eventosapp_priv_politica_url'] ?? '' )
    );
    update_post_meta($post_id, '_eventosapp_priv_aviso_url',
        esc_url_raw( $_POST['eventosapp_priv_aviso_url'] ?? '' )
    );
}, 35); // prioridad > 20 para quedar después del guardado base
