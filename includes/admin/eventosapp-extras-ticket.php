<?php
// includes/admin/eventosapp-extras-ticket.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Metabox: Funciones Extra del Ticket
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'eventosapp_extras_ticket',
        'Funciones Extra del Ticket',
        'eventosapp_render_metabox_extras_ticket',
        'eventosapp_event',
        'side',
        'default'
    );
});

/**
 * ÚNICA definición (unificada) del render del metabox
 */
function eventosapp_render_metabox_extras_ticket($post) {
    // Recupera valores (por defecto OFF)
    $pdf         = get_post_meta($post->ID, '_eventosapp_ticket_pdf', true);
    $ics         = get_post_meta($post->ID, '_eventosapp_ticket_ics', true);
    $walleta     = get_post_meta($post->ID, '_eventosapp_ticket_wallet_android', true);
    $walleti     = get_post_meta($post->ID, '_eventosapp_ticket_wallet_apple', true);
    $verify      = get_post_meta($post->ID, '_eventosapp_ticket_verify_email', true);

    // NUEVO: envío auto para registro público
    $auto_public = get_post_meta($post->ID, '_eventosapp_ticket_auto_email_public', true);

    // NUEVO: flag “usar QR preimpreso”
    $use_preprinted = get_post_meta($post->ID, '_eventosapp_ticket_use_preprinted_qr', true);
	// NUEVO: flag “usar QR preimpreso SOLO para Networking”
	$use_preprinted_net = get_post_meta($post->ID, '_eventosapp_ticket_use_preprinted_qr_networking', true);


    wp_nonce_field('eventosapp_extras_ticket_guardar', 'eventosapp_extras_ticket_nonce');
    ?>
    <label>
        <input type="checkbox" name="eventosapp_ticket_pdf" value="1" <?php checked($pdf, '1'); ?>>
        Ticket en PDF Adjunto
    </label><br>
    <label>
        <input type="checkbox" name="eventosapp_ticket_ics" value="1" <?php checked($ics, '1'); ?>>
        Archivo ICS para Calendarios
    </label><br>
    <label>
        <input type="checkbox" name="eventosapp_ticket_wallet_android" value="1" <?php checked($walleta, '1'); ?>>
        Ticket en Wallet Android
    </label><br>
    <label>
        <input type="checkbox" name="eventosapp_ticket_wallet_apple" value="1" <?php checked($walleti, '1'); ?>>
        Ticket en Wallet Apple/iPhone
    </label><br>
    <label>
        <input type="checkbox" name="eventosapp_ticket_verify_email" value="1" <?php checked($verify, '1'); ?>>
        Verificar Correo Electrónico de Asistente
    </label>

    <hr>
    <label>
        <input type="checkbox" name="eventosapp_ticket_auto_email_public" value="1" <?php checked($auto_public, '1'); ?>>
        <strong>Enviar ticket automáticamente en el registro público</strong>
    </label>
    <br>
    <small style="color:#666">No afecta el envío manual desde el panel del staff/organizador.</small>

    <hr>
    <label>
        <input type="checkbox" name="eventosapp_ticket_use_preprinted_qr" value="1" <?php checked($use_preprinted, '1'); ?>>
        <strong>Usar QR preimpreso para Check In</strong>
    </label>
    <br>
    <small style="color:#666">Si está activo, el lector QR buscará por el campo numérico para realizar Checkin <code>eventosapp_ticket_preprintedID</code> del ticket.</small>
<hr>
<label>
  <input type="checkbox" name="eventosapp_ticket_use_preprinted_qr_networking" value="1" <?php checked($use_preprinted_net, '1'); ?>>
  <strong>Usar QR Preimpreso en Networking</strong>
</label>
<br>
<small style="color:#666">
  Aplica únicamente al lector de <code>[eventosapp_qr_contacto]</code>. Al activarlo, buscará por el campo numérico
  <code>eventosapp_ticket_preprintedID</code> en lugar de <code>eventosapp_ticketID</code>.
</small>

    <?php
}

/**
 * Guardar metadatos del metabox de extras
 */
add_action('save_post_eventosapp_event', function($post_id){
    // Evitar autosaves y revisiones
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;

    // Verifica capacidades mínimas
    if (!current_user_can('edit_post', $post_id)) return;

    // Nonce del metabox
    if (!isset($_POST['eventosapp_extras_ticket_nonce']) || !wp_verify_nonce($_POST['eventosapp_extras_ticket_nonce'], 'eventosapp_extras_ticket_guardar')) {
        return;
    }

    // Guardar flags
    update_post_meta($post_id, '_eventosapp_ticket_pdf',                isset($_POST['eventosapp_ticket_pdf']) ? '1' : '0');
    update_post_meta($post_id, '_eventosapp_ticket_ics',                isset($_POST['eventosapp_ticket_ics']) ? '1' : '0');
    update_post_meta($post_id, '_eventosapp_ticket_wallet_android',     isset($_POST['eventosapp_ticket_wallet_android']) ? '1' : '0');
    update_post_meta($post_id, '_eventosapp_ticket_wallet_apple',       isset($_POST['eventosapp_ticket_wallet_apple']) ? '1' : '0');
    update_post_meta($post_id, '_eventosapp_ticket_verify_email',       isset($_POST['eventosapp_ticket_verify_email']) ? '1' : '0');

    // NUEVO: solo para registro público
    update_post_meta($post_id, '_eventosapp_ticket_auto_email_public',  isset($_POST['eventosapp_ticket_auto_email_public']) ? '1' : '0');
	// NUEVO: usar QR preimpreso (por evento)
    update_post_meta($post_id, '_eventosapp_ticket_use_preprinted_qr', isset($_POST['eventosapp_ticket_use_preprinted_qr']) ? '1' : '0');
	
	// NUEVO: usar QR preimpreso SOLO para networking
update_post_meta(
  $post_id,
  '_eventosapp_ticket_use_preprinted_qr_networking',
  isset($_POST['eventosapp_ticket_use_preprinted_qr_networking']) ? '1' : '0'
);

	
}, 25); // prioridad > 20 para correr después del guardado base