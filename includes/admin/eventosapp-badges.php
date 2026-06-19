<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Metabox: Configuración de Escarapela (por evento)
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'eventosapp_badge_settings',
        __('Configuración de Escarapela', 'eventosapp'),
        'eventosapp_render_badge_metabox',
        'eventosapp_event',
        'normal',
        'default'
    );
});

/**
 * Obtiene todos los campos disponibles para un evento (básicos + extras).
 */
function eventosapp_get_all_available_fields($evento_id = 0) {
    // Campos básicos del ticket.
    $basic_fields = [
        'none'           => __('Ninguno', 'eventosapp'),
        'full_name'      => __('Nombres + Apellidos', 'eventosapp'),
        'nombre'         => __('Nombre', 'eventosapp'),
        'apellido'       => __('Apellido', 'eventosapp'),
        'company'        => __('Nombre de la Empresa', 'eventosapp'),
        'designation'    => __('Cargo', 'eventosapp'),
        'cc_id'          => __('CC_ID', 'eventosapp'),
        'email'          => __('Email', 'eventosapp'),
        'telefono'       => __('Teléfono', 'eventosapp'),
        'nit'            => __('NIT', 'eventosapp'),
        'ciudad'         => __('Ciudad', 'eventosapp'),
        'pais'           => __('País', 'eventosapp'),
        'localidad'      => __('Localidad', 'eventosapp'),
        'qr'             => __('QR', 'eventosapp'),
        'qr_networking'  => __('QR Networking', 'eventosapp'),
    ];

    // Si hay un evento, agregar sus campos adicionales.
    if ($evento_id && function_exists('eventosapp_get_event_extra_fields')) {
        $extra_fields = eventosapp_get_event_extra_fields($evento_id);
        if (!empty($extra_fields)) {
            foreach ($extra_fields as $field) {
                if (empty($field['key']) || empty($field['label'])) {
                    continue;
                }
                $key = 'extra_' . sanitize_key($field['key']);
                $basic_fields[$key] = $field['label'] . ' (campo adicional)';
            }
        }
    }

    return $basic_fields;
}

/**
 * Detecta los campos del mapeo que deben renderizarse como imagen QR.
 */
function eventosapp_badge_is_qr_field($field) {
    return in_array((string) $field, ['qr', 'qr_networking'], true);
}

/**
 * Obtiene la instancia actual del QR Manager sin depender de un singleton.
 */
function eventosapp_badge_get_qr_manager_instance() {
    global $eventosapp_qr_manager_instance;

    if (isset($eventosapp_qr_manager_instance) && $eventosapp_qr_manager_instance instanceof EventosApp_QR_Manager) {
        return $eventosapp_qr_manager_instance;
    }

    if (class_exists('EventosApp_QR_Manager')) {
        $eventosapp_qr_manager_instance = new EventosApp_QR_Manager();
        return $eventosapp_qr_manager_instance;
    }

    return null;
}

/**
 * Lee la URL pública de la imagen PNG de un QR guardado en _eventosapp_qr_codes.
 */
function eventosapp_badge_read_stored_qr_image_url($ticket_id, $type = 'badge') {
    $ticket_id = absint($ticket_id);
    $type      = sanitize_key($type);

    if (!$ticket_id || $type === '') {
        return '';
    }

    $all_qr_codes = get_post_meta($ticket_id, '_eventosapp_qr_codes', true);
    if (!is_array($all_qr_codes) || empty($all_qr_codes[$type]) || !is_array($all_qr_codes[$type])) {
        return '';
    }

    $qr_data = $all_qr_codes[$type];
    if (empty($qr_data['url'])) {
        return '';
    }

    $qr_url  = (string) $qr_data['url'];
    $qr_path = !empty($qr_data['path']) ? (string) $qr_data['path'] : '';

    if ($qr_path !== '' && !file_exists($qr_path)) {
        return '';
    }

    if ($qr_path !== '' && file_exists($qr_path)) {
        $separator = (strpos($qr_url, '?') === false) ? '?' : '&';
        return $qr_url . $separator . 'v=' . filemtime($qr_path);
    }

    return $qr_url;
}

/**
 * Obtiene el QR de networking/escarapela generado por el QR Manager.
 *
 * Este QR corresponde al tipo interno "badge" del QR Manager y codifica una URL
 * hacia /networking/global con event + ticketid + código de seguridad.
 */
function eventosapp_get_ticket_networking_qr_url($ticket_id) {
    $ticket_id = absint($ticket_id);
    if (!$ticket_id || get_post_type($ticket_id) !== 'eventosapp_ticket') {
        return '';
    }

    $existing_url = eventosapp_badge_read_stored_qr_image_url($ticket_id, 'badge');
    if ($existing_url !== '') {
        return $existing_url;
    }

    $manager = eventosapp_badge_get_qr_manager_instance();
    if ($manager && method_exists($manager, 'generate_qr_code')) {
        $generated = $manager->generate_qr_code($ticket_id, 'badge');
        if (is_array($generated) && !empty($generated['url'])) {
            $qr_url  = (string) $generated['url'];
            $qr_path = !empty($generated['path']) ? (string) $generated['path'] : '';
            if ($qr_path !== '' && file_exists($qr_path)) {
                $separator = (strpos($qr_url, '?') === false) ? '?' : '&';
                return $qr_url . $separator . 'v=' . filemtime($qr_path);
            }
            return $qr_url;
        }
    }

    return eventosapp_badge_read_stored_qr_image_url($ticket_id, 'badge');
}

/**
 * Busca un ticket por su ID público eventosapp_ticketID.
 */
function eventosapp_badge_find_ticket_by_public_key($ticket_key) {
    $ticket_key = trim((string) $ticket_key);
    if ($ticket_key === '') {
        return 0;
    }

    $found = get_posts([
        'post_type'   => 'eventosapp_ticket',
        'post_status' => 'any',
        'numberposts' => 1,
        'fields'      => 'ids',
        'meta_key'    => 'eventosapp_ticketID',
        'meta_value'  => $ticket_key,
    ]);

    return !empty($found) ? absint($found[0]) : 0;
}

/**
 * Resuelve el evento desde post_id/event_id/evento_id/event o desde el ticket.
 */
function eventosapp_badge_resolve_event_id($ticket_id = 0) {
    $event_candidates = ['post_id', 'event_id', 'evento_id', 'event'];

    foreach ($event_candidates as $key) {
        if (!isset($_GET[$key])) {
            continue;
        }

        $raw = sanitize_text_field(wp_unslash($_GET[$key]));
        if ($raw === '') {
            continue;
        }

        if (preg_match('/^(\d+)/', $raw, $matches)) {
            $event_id = absint($matches[1]);
            if ($event_id) {
                return $event_id;
            }
        }
    }

    $ticket_id = absint($ticket_id);
    if ($ticket_id) {
        $event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if ($event_id) {
            return $event_id;
        }
    }

    return 0;
}

/**
 * Resuelve el ticket recibido por ID numérico o por ID público tk...
 */
function eventosapp_badge_resolve_ticket_id() {
    $ticket_id_raw = isset($_GET['ticket_id']) ? sanitize_text_field(wp_unslash($_GET['ticket_id'])) : '';
    $ticket_id     = absint($ticket_id_raw);

    if ($ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket') {
        return $ticket_id;
    }

    if ($ticket_id_raw !== '') {
        $found_by_public = eventosapp_badge_find_ticket_by_public_key($ticket_id_raw);
        if ($found_by_public) {
            return $found_by_public;
        }
    }

    $ticket_key = isset($_GET['ticket_key']) ? sanitize_text_field(wp_unslash($_GET['ticket_key'])) : '';
    if ($ticket_key !== '') {
        return eventosapp_badge_find_ticket_by_public_key($ticket_key);
    }

    return 0;
}

/**
 * Arma los valores que se pueden imprimir en la escarapela.
 */
function eventosapp_badge_build_labels($evento_id, $ticket_id = 0) {
    $evento_id = absint($evento_id);
    $ticket_id = absint($ticket_id);

    $labels = [
        'full_name'      => 'Nombres + Apellidos',
        'nombre'         => 'Nombre',
        'apellido'       => 'Apellido',
        'company'        => 'Nombre de la Empresa',
        'designation'    => 'Cargo',
        'cc_id'          => 'CC_ID',
        'email'          => 'Email',
        'telefono'       => 'Teléfono',
        'nit'            => 'NIT',
        'ciudad'         => 'Ciudad',
        'pais'           => 'País',
        'localidad'      => 'Localidad',
        'qr'             => '',
        'qr_networking'  => '',
    ];

    if ($ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket') {
        $nombre    = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
        $apell     = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
        $empresa   = get_post_meta($ticket_id, '_eventosapp_asistente_empresa', true);
        $cargo     = get_post_meta($ticket_id, '_eventosapp_asistente_cargo', true);
        $cc        = get_post_meta($ticket_id, '_eventosapp_asistente_cc', true);
        $email     = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
        $tel       = get_post_meta($ticket_id, '_eventosapp_asistente_tel', true);
        $nit       = get_post_meta($ticket_id, '_eventosapp_asistente_nit', true);
        $ciudad    = get_post_meta($ticket_id, '_eventosapp_asistente_ciudad', true);
        $pais      = get_post_meta($ticket_id, '_eventosapp_asistente_pais', true);
        $localidad = get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);
        $code      = get_post_meta($ticket_id, 'eventosapp_ticketID', true);

        $labels['full_name']   = trim($nombre . ' ' . $apell) ?: $labels['full_name'];
        $labels['nombre']      = $nombre    ?: $labels['nombre'];
        $labels['apellido']    = $apell     ?: $labels['apellido'];
        $labels['company']     = $empresa   ?: $labels['company'];
        $labels['designation'] = $cargo     ?: $labels['designation'];
        $labels['cc_id']       = $cc        ?: $labels['cc_id'];
        $labels['email']       = $email     ?: $labels['email'];
        $labels['telefono']    = $tel       ?: $labels['telefono'];
        $labels['nit']         = $nit       ?: $labels['nit'];
        $labels['ciudad']      = $ciudad    ?: $labels['ciudad'];
        $labels['pais']        = $pais      ?: $labels['pais'];
        $labels['localidad']   = $localidad ?: $labels['localidad'];

        // QR existente / legacy del ticket. Se conserva sin cambios.
        if ($code && function_exists('eventosapp_get_ticket_qr_url')) {
            $labels['qr'] = eventosapp_get_ticket_qr_url($code);
        }

        // QR Networking: imagen PNG del QR tipo badge que apunta a /networking/global.
        $labels['qr_networking'] = eventosapp_get_ticket_networking_qr_url($ticket_id);
    }

    if ($evento_id && function_exists('eventosapp_get_event_extra_fields')) {
        $extra_fields = eventosapp_get_event_extra_fields($evento_id);
        if (!empty($extra_fields)) {
            foreach ($extra_fields as $field) {
                if (empty($field['key']) || empty($field['label'])) {
                    continue;
                }
                $key = 'extra_' . sanitize_key($field['key']);
                $value = ($ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket')
                    ? get_post_meta($ticket_id, '_eventosapp_extra_' . sanitize_key($field['key']), true)
                    : '';
                $labels[$key] = $value ?: $field['label'];
            }
        }
    }

    return $labels;
}

/**
 * Renderiza un campo de la escarapela como texto o como imagen QR según corresponda.
 */
function eventosapp_badge_render_field_slot($field, $labels, $font_size, $font_weight, $margin, $qr_size) {
    $field       = (string) $field;
    $font_size   = absint($font_size);
    $font_weight = absint($font_weight);
    $margin      = absint($margin);
    $qr_size     = absint($qr_size);

    if (eventosapp_badge_is_qr_field($field)) {
        $qr_src = isset($labels[$field]) ? (string) $labels[$field] : '';
        if ($qr_src !== '') {
            $alt = ($field === 'qr_networking') ? __('QR Networking', 'eventosapp') : __('QR', 'eventosapp');
            echo "<div class='slot slot-qr slot-" . esc_attr($field) . "' style='margin:" . $margin . "px'><img src='" . esc_url($qr_src) . "' width='" . $qr_size . "' height='" . $qr_size . "' alt='" . esc_attr($alt) . "'></div>";
            return;
        }

        echo "<div class='slot slot-qr-empty slot-" . esc_attr($field) . "' style='margin:" . $margin . "px'></div>";
        return;
    }

    $label_text = isset($labels[$field]) ? $labels[$field] : '';
    echo "<div class='slot' style='margin:" . $margin . "px; font-size:" . $font_size . "px; font-weight:" . $font_weight . ";'>" . esc_html($label_text) . "</div>";
}

/**
 * Render del metabox.
 */
function eventosapp_render_badge_metabox($post) {
    wp_nonce_field('eventosapp_save_badge_settings', 'eventosapp_badge_nonce');

    // Valores guardados (con defaults).
    $design   = get_post_meta($post->ID, 'eventosapp_badge_design', true) ?: 'manillas';

    $order_fields = [];
    for ($i=1; $i<=5; $i++){
        $order_fields[$i] = get_post_meta($post->ID, "eventosapp_field_order_{$i}", true) ?: 'none';
    }

    $width          = (int) (get_post_meta($post->ID, 'eventosapp_badge_width', true) ?: 374);
    $height         = (int) (get_post_meta($post->ID, 'eventosapp_badge_height', true) ?: 208);
    $size_large     = (int) (get_post_meta($post->ID, 'eventosapp_badge_size_large', true) ?: 24);
    $size_medium    = (int) (get_post_meta($post->ID, 'eventosapp_badge_size_medium', true) ?: 18);
    $size_small     = (int) (get_post_meta($post->ID, 'eventosapp_badge_size_small', true) ?: 14);
    $weight_large   = (int) (get_post_meta($post->ID, 'eventosapp_badge_weight_large', true) ?: 600);
    $weight_medium  = (int) (get_post_meta($post->ID, 'eventosapp_badge_weight_medium', true) ?: 500);
    $weight_small   = (int) (get_post_meta($post->ID, 'eventosapp_badge_weight_small', true) ?: 400);
    $sep_vertical   = (int) (get_post_meta($post->ID, 'eventosapp_badge_sep_vertical', true) ?: 4);
    $sep_horizontal = (int) (get_post_meta($post->ID, 'eventosapp_badge_sep_horizontal', true) ?: 4);
    $qr_size        = (int) (get_post_meta($post->ID, 'eventosapp_badge_qr_size', true) ?: 72);
    $border_width   = get_post_meta($post->ID, 'eventosapp_badge_border_width', true);
    if ($border_width === '' || $border_width === null) $border_width = 0;
    $border_width   = (int) $border_width;

    // Campo para vista previa con el ID público del ticket (tk...).
    $preview_ticket_key = get_post_meta($post->ID, 'eventosapp_badge_ticket_key', true) ?: '';

    // Obtener todos los campos disponibles (básicos + extras del evento).
    $options = eventosapp_get_all_available_fields($post->ID);

    $weights = [100,200,300,400,500,600,700,800];
    ?>
    <table class="form-table"><tbody>

      <tr>
        <th><?php _e('Número de ticket (ID) para vista previa', 'eventosapp'); ?></th>
        <td>
          <input type="text"
                 name="eventosapp_badge_ticket_key"
                 value="<?php echo esc_attr($preview_ticket_key); ?>"
                 style="width:16rem;"
                 placeholder="p. ej. tkcdG7ejZvDjWAD"
                 pattern="^[A-Za-z0-9_-]{6,}$"
                 title="<?php esc_attr_e('Pegue aquí el ID público del ticket (tk...)', 'eventosapp'); ?>">
          <br><small><?php _e('Opcional. Use un ID público del ticket (formato tk...).', 'eventosapp'); ?></small>
        </td>
      </tr>

      <tr>
        <th><?php _e('Tipo de Diseño', 'eventosapp'); ?></th>
        <td>
          <label><input type="radio" name="eventosapp_badge_design" value="manillas" <?php checked($design,'manillas'); ?>>
            <?php _e('Diseño para Manillas (horizontal)', 'eventosapp'); ?></label><br>
          <small><?php _e('Campo 1–3 medianos — orientación horizontal', 'eventosapp'); ?></small><br><br>

          <label><input type="radio" name="eventosapp_badge_design" value="escarapelas" <?php checked($design,'escarapelas'); ?>>
            <?php _e('Diseño para Escarapelas (vertical)', 'eventosapp'); ?></label><br>
          <small><?php _e('Campo 1 grande · 2–3 medianos — orientación vertical', 'eventosapp'); ?></small><br><br>

          <label><input type="radio" name="eventosapp_badge_design" value="escarapelas_split" <?php checked($design,'escarapelas_split'); ?>>
            <?php _e('Escarapela Split (3 izq / 1 der)', 'eventosapp'); ?></label><br>
          <small><?php _e('Campos 1-3 a la izquierda y el siguiente a la derecha', 'eventosapp'); ?></small><br><br>

          <label><input type="radio" name="eventosapp_badge_design" value="escarapelas_split_4" <?php checked($design,'escarapelas_split_4'); ?>>
            <?php _e('Escarapela Split 4 Campos (4 izq / 1 der)', 'eventosapp'); ?></label><br>
          <small><?php _e('Campos 1-4 a la izquierda y el siguiente a la derecha', 'eventosapp'); ?></small>
        </td>
      </tr>

      <?php for ($i=1; $i<=5; $i++): ?>
        <tr>
          <th><label for="eventosapp_field_order_<?php echo $i; ?>"><?php printf(__('Campo %d', 'eventosapp'), $i); ?></label></th>
          <td>
            <select name="eventosapp_field_order_<?php echo $i; ?>" id="eventosapp_field_order_<?php echo $i; ?>">
              <?php foreach($options as $val=>$lab): ?>
                <option value="<?php echo esc_attr($val); ?>" <?php selected($order_fields[$i], $val); ?>><?php echo esc_html($lab); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
      <?php endfor; ?>

      <tr><th><?php _e('Ancho (px)', 'eventosapp'); ?></th>
          <td><input type="number" name="eventosapp_badge_width" value="<?php echo esc_attr($width); ?>" style="width:6rem;"> px</td></tr>
      <tr><th><?php _e('Alto (px)', 'eventosapp'); ?></th>
          <td><input type="number" name="eventosapp_badge_height" value="<?php echo esc_attr($height); ?>" style="width:6rem;"> px</td></tr>

      <tr><th><?php _e('Tamaño grande (px)', 'eventosapp'); ?></th>
        <td>
          <input type="number" name="eventosapp_badge_size_large" value="<?php echo esc_attr($size_large); ?>" style="width:4rem;"> px
          <select name="eventosapp_badge_weight_large">
            <?php foreach($weights as $w) echo '<option value="'.$w.'"'.selected($weight_large,$w,false).'>'.$w.'</option>'; ?>
          </select>
        </td>
      </tr>

      <tr><th><?php _e('Tamaño mediano (px)', 'eventosapp'); ?></th>
        <td>
          <input type="number" name="eventosapp_badge_size_medium" value="<?php echo esc_attr($size_medium); ?>" style="width:4rem;"> px
          <select name="eventosapp_badge_weight_medium">
            <?php foreach($weights as $w) echo '<option value="'.$w.'"'.selected($weight_medium,$w,false).'>'.$w.'</option>'; ?>
          </select>
        </td>
      </tr>

      <tr><th><?php _e('Tamaño pequeño (px)', 'eventosapp'); ?></th>
        <td>
          <input type="number" name="eventosapp_badge_size_small" value="<?php echo esc_attr($size_small); ?>" style="width:4rem;"> px
          <select name="eventosapp_badge_weight_small">
            <?php foreach($weights as $w) echo '<option value="'.$w.'"'.selected($weight_small,$w,false).'>'.$w.'</option>'; ?>
          </select>
        </td>
      </tr>

      <tr><th><?php _e('Separación vertical (px)', 'eventosapp'); ?></th>
          <td><input type="number" name="eventosapp_badge_sep_vertical" value="<?php echo esc_attr($sep_vertical); ?>" style="width:6rem;"> px</td></tr>
      <tr><th><?php _e('Separación horizontal (px)', 'eventosapp'); ?></th>
          <td><input type="number" name="eventosapp_badge_sep_horizontal" value="<?php echo esc_attr($sep_horizontal); ?>" style="width:6rem;"> px</td></tr>

      <tr><th><?php _e('Tamaño del QR (px)', 'eventosapp'); ?></th>
          <td><input type="number" name="eventosapp_badge_qr_size" value="<?php echo esc_attr($qr_size); ?>" style="width:6rem;"> px</td></tr>

      <tr><th><?php _e('Grosor del borde', 'eventosapp'); ?></th>
          <td><input type="number" name="eventosapp_badge_border_width" value="<?php echo esc_attr($border_width); ?>" style="width:6rem;" min="0"> px
              <br><small><?php _e('Usa 0 para sin borde.', 'eventosapp'); ?></small></td></tr>
    </tbody></table>

    <p>
      <button type="button" class="button button-primary" id="eventosapp_download_badge"><?php _e('Descargar / Imprimir Escarapela', 'eventosapp'); ?></button>
    </p>

    <script>
    jQuery(function($){
      $('#eventosapp_download_badge').on('click', function(e){
        e.preventDefault();
        var ticketKey = $('input[name="eventosapp_badge_ticket_key"]').val() || '';
        var url = '<?php echo admin_url('admin-ajax.php'); ?>' +
                  '?action=eventosapp_download_badge' +
                  '&post_id=<?php echo (int)$post->ID; ?>' +
                  '&ticket_key=' + encodeURIComponent(ticketKey) +
                  '&_wpnonce=<?php echo wp_create_nonce('eventosapp_download_badge'); ?>';
        window.open(url, '_blank');
      });
    });
    </script>
    <?php
}

/**
 * Guardado del metabox.
 */
add_action('save_post_eventosapp_event', function($post_id){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;

    if (empty($_POST['eventosapp_badge_nonce']) || !wp_verify_nonce($_POST['eventosapp_badge_nonce'], 'eventosapp_save_badge_settings')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['eventosapp_badge_design'])) {
        $d = sanitize_key($_POST['eventosapp_badge_design']);
        if (in_array($d, ['manillas','escarapelas','escarapelas_split','escarapelas_split_4'], true)) {
            update_post_meta($post_id, 'eventosapp_badge_design', $d);
        }
    }
    for ($i=1; $i<=5; $i++){
        $k = "eventosapp_field_order_{$i}";
        update_post_meta($post_id, $k, sanitize_key($_POST[$k] ?? 'none'));
    }
    foreach (['width','height'] as $dim){
        if (isset($_POST["eventosapp_badge_{$dim}"])) {
            update_post_meta($post_id, "eventosapp_badge_{$dim}", absint($_POST["eventosapp_badge_{$dim}"]));
        }
    }
    foreach (['large','medium','small'] as $s){
        if (isset($_POST["eventosapp_badge_size_{$s}"])) {
            update_post_meta($post_id, "eventosapp_badge_size_{$s}", absint($_POST["eventosapp_badge_size_{$s}"]));
        }
        if (isset($_POST["eventosapp_badge_weight_{$s}"])) {
            update_post_meta($post_id, "eventosapp_badge_weight_{$s}", absint($_POST["eventosapp_badge_weight_{$s}"]));
        }
    }
    foreach (['vertical','horizontal'] as $dir){
        if (isset($_POST["eventosapp_badge_sep_{$dir}"])) {
            update_post_meta($post_id, "eventosapp_badge_sep_{$dir}", absint($_POST["eventosapp_badge_sep_{$dir}"]));
        }
    }
    if (isset($_POST['eventosapp_badge_qr_size'])) {
        update_post_meta($post_id, 'eventosapp_badge_qr_size', absint($_POST['eventosapp_badge_qr_size']));
    }
    if (isset($_POST['eventosapp_badge_border_width'])) {
        update_post_meta($post_id, 'eventosapp_badge_border_width', max(0, absint($_POST['eventosapp_badge_border_width'])));
    }
    if (isset($_POST['eventosapp_badge_ticket_key'])) {
        update_post_meta(
            $post_id,
            'eventosapp_badge_ticket_key',
            sanitize_text_field($_POST['eventosapp_badge_ticket_key'])
        );
    }
}, 22);

/**
 * Helper público para jalar la configuración desde cualquier parte.
 */
function eventosapp_get_badge_settings($evento_id) {
    $evento_id = absint($evento_id);

    $get = function($k, $def = null) use ($evento_id) {
        if (!$evento_id) {
            return $def;
        }

        $v = get_post_meta($evento_id, $k, true);
        return ($v === '' || $v === null) ? $def : $v;
    };

    $allowed_designs = ['manillas', 'escarapelas', 'escarapelas_split', 'escarapelas_split_4'];
    $design = sanitize_key($get('eventosapp_badge_design', 'manillas'));
    if (!in_array($design, $allowed_designs, true)) {
        $design = 'manillas';
    }

    $order = [];
    for ($i = 1; $i <= 5; $i++) {
        $field = sanitize_key($get("eventosapp_field_order_{$i}", 'none'));
        $order[$i] = $field !== '' ? $field : 'none';
    }

    /*
     * Estos defaults deben coincidir con los valores que se muestran en el metabox.
     * El kiosko usa este helper para imprimir; si aquí se usan otros tamaños, la
     * autogestión termina jalando una escarapela distinta a la configurada/esperada
     * para el evento cuando todavía no se ha guardado algún campo opcional.
     */
    return [
        'design'         => $design,
        'order'          => $order,
        'width'          => max(1, (int) $get('eventosapp_badge_width', 374)),
        'height'         => max(1, (int) $get('eventosapp_badge_height', 208)),
        'size_large'     => max(1, (int) $get('eventosapp_badge_size_large', 24)),
        'size_medium'    => max(1, (int) $get('eventosapp_badge_size_medium', 18)),
        'size_small'     => max(1, (int) $get('eventosapp_badge_size_small', 14)),
        'weight_large'   => max(100, (int) $get('eventosapp_badge_weight_large', 600)),
        'weight_medium'  => max(100, (int) $get('eventosapp_badge_weight_medium', 500)),
        'weight_small'   => max(100, (int) $get('eventosapp_badge_weight_small', 400)),
        'sep_vertical'   => max(0, (int) $get('eventosapp_badge_sep_vertical', 4)),
        'sep_horizontal' => max(0, (int) $get('eventosapp_badge_sep_horizontal', 4)),
        'qr_size'        => max(1, (int) $get('eventosapp_badge_qr_size', 72)),
        'border_width'   => max(0, (int) $get('eventosapp_badge_border_width', 0)),
    ];
}

/**
 * Helper para generar URL firmada de impresión desde admin.
 */
function eventosapp_badge_print_url($evento_id, $ticket = '') {
    $args = [
        'action'  => 'eventosapp_download_badge',
        'post_id' => (int)$evento_id,
    ];
    if (is_numeric($ticket)) {
        $args['ticket_id'] = (int)$ticket;
    } elseif (is_string($ticket) && $ticket !== '') {
        $args['ticket_key'] = $ticket;
    }
    $url = add_query_arg($args, admin_url('admin-ajax.php'));
    return wp_nonce_url($url, 'eventosapp_download_badge');
}

/**
 * Imprime el HTML final de la escarapela usando la configuración guardada.
 */
function eventosapp_get_badge_html_from_event($evento_id, $ticket_id = 0, $auto_print = true) {
    $evento_id  = absint($evento_id);
    $ticket_id  = absint($ticket_id);
    $auto_print = (bool) $auto_print;

    if (!$evento_id || get_post_type($evento_id) !== 'eventosapp_event') {
        return '';
    }

    if ($ticket_id && get_post_type($ticket_id) !== 'eventosapp_ticket') {
        $ticket_id = 0;
    }

    if ($ticket_id) {
        $ticket_event_id = absint(get_post_meta($ticket_id, '_eventosapp_ticket_evento_id', true));
        if ($ticket_event_id && $ticket_event_id !== $evento_id) {
            $evento_id = $ticket_event_id;
        }
    }

    $cfg      = eventosapp_get_badge_settings($evento_id);
    $labels   = eventosapp_badge_build_labels($evento_id, $ticket_id);
    $flex_dir = ($cfg['design'] === 'escarapelas') ? 'column' : 'row';

    ob_start();
    ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Escarapela</title>
<style>
  html,body{margin:0;padding:0;height:100%}
  body{display:flex;align-items:center;justify-content:center;font-family:Arial,Helvetica,sans-serif}
  .badge{
    <?php echo ($cfg['border_width'] > 0 ? "border:" . (int) $cfg['border_width'] . "px solid #000;" : "border:none;"); ?>
    display:flex; flex-direction:<?php echo esc_attr($flex_dir); ?>;
    align-items:stretch; justify-content:center;
    width:<?php echo (int) $cfg['width']; ?>px; height:<?php echo (int) $cfg['height']; ?>px;
    padding:4px; box-sizing:border-box;
  }
  .left,.right{display:flex; flex-direction:column; justify-content:center; height:100%;}
  .right{align-items:center;}
  .slot{line-height:1.15;}
  .slot-qr{display:flex;align-items:center;justify-content:center;}
  .slot-qr img{display:block;max-width:100%;height:auto;}
  @media print {
    @page { margin: 8mm; }
  }
</style>
</head>
<body>
<div class="badge" data-event-id="<?php echo esc_attr($evento_id); ?>" data-ticket-id="<?php echo esc_attr($ticket_id); ?>">
<?php
    $active = [];
    for ($i = 1; $i <= 5; $i++) {
        $f = $cfg['order'][$i] ?? 'none';
        if ($f !== 'none') {
            $active[] = $f;
        }
    }

    if ($cfg['design'] === 'escarapelas_split') {
        // Diseño split 3 izq / 1 der.
        $left  = array_slice($active, 0, 3);
        $right = $active[3] ?? null;

        echo "<div class='left'>";
        foreach ($left as $idx => $field) {
            $fs = ($idx === 0) ? $cfg['size_large'] : (($idx === 1) ? $cfg['size_medium'] : $cfg['size_small']);
            $fw = ($idx === 0) ? $cfg['weight_large'] : (($idx === 1) ? $cfg['weight_medium'] : $cfg['weight_small']);
            eventosapp_badge_render_field_slot($field, $labels, $fs, $fw, $cfg['sep_vertical'], $cfg['qr_size']);
        }
        echo "</div>";

        $mh = absint($cfg['sep_horizontal']);
        echo "<div class='right' style='margin-left:{$mh}px'>";
        if ($right) {
            eventosapp_badge_render_field_slot($right, $labels, $cfg['size_medium'], $cfg['weight_medium'], $cfg['sep_vertical'], $cfg['qr_size']);
        }
        echo "</div>";

    } elseif ($cfg['design'] === 'escarapelas_split_4') {
        // Diseño split 4 izq / 1 der.
        $left  = array_slice($active, 0, 4);
        $right = $active[4] ?? null;

        echo "<div class='left'>";
        foreach ($left as $idx => $field) {
            if ($idx === 0) {
                $fs = $cfg['size_large'];
                $fw = $cfg['weight_large'];
            } elseif ($idx === 1 || $idx === 2) {
                $fs = $cfg['size_medium'];
                $fw = $cfg['weight_medium'];
            } else {
                $fs = $cfg['size_small'];
                $fw = $cfg['weight_small'];
            }
            eventosapp_badge_render_field_slot($field, $labels, $fs, $fw, $cfg['sep_vertical'], $cfg['qr_size']);
        }
        echo "</div>";

        $mh = absint($cfg['sep_horizontal']);
        echo "<div class='right' style='margin-left:{$mh}px'>";
        if ($right) {
            eventosapp_badge_render_field_slot($right, $labels, $cfg['size_medium'], $cfg['weight_medium'], $cfg['sep_vertical'], $cfg['qr_size']);
        }
        echo "</div>";

    } else {
        // Diseños normales (manillas o escarapelas vertical).
        foreach (array_values($active) as $idx => $field) {
            $margin = ($cfg['design'] === 'escarapelas') ? $cfg['sep_vertical'] : $cfg['sep_horizontal'];
            if ($cfg['design'] === 'escarapelas') {
                $fs = ($idx === 0) ? $cfg['size_large'] : (($idx <= 2) ? $cfg['size_medium'] : $cfg['size_small']);
                $fw = ($idx === 0) ? $cfg['weight_large'] : (($idx <= 2) ? $cfg['weight_medium'] : $cfg['weight_small']);
            } else {
                $fs = $cfg['size_medium'];
                $fw = $cfg['weight_medium'];
            }
            eventosapp_badge_render_field_slot($field, $labels, $fs, $fw, $margin, $cfg['qr_size']);
        }
    }
?>
</div>
<?php if ($auto_print): ?>
<script>window.print();</script>
<?php endif; ?>
</body>
</html>
<?php
    return ob_get_clean();
}

/**
 * Imprime el HTML final de la escarapela usando la configuración guardada.
 */
function eventosapp_badge_output_html($evento_id, $ticket_id = 0, $auto_print = true) {
    $evento_id = absint($evento_id);
    $ticket_id = absint($ticket_id);

    if (!$evento_id) {
        wp_die('Evento inválido', '', 400);
    }

    $html = eventosapp_get_badge_html_from_event($evento_id, $ticket_id, $auto_print);
    if ($html === '') {
        wp_die('No fue posible generar la escarapela para este evento.', '', 500);
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }

    echo $html;
    exit;
}

/**
 * AJAX admin: imprimir/descargar escarapela desde el metabox del evento.
 */
add_action('wp_ajax_eventosapp_download_badge', 'eventosapp_download_badge_handler');
add_action('wp_ajax_nopriv_eventosapp_download_badge', 'eventosapp_download_badge_handler');

function eventosapp_download_badge_handler() {
    if ( empty($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'eventosapp_download_badge') ) {
        wp_die('Nonce inválido', '', 403);
    }

    $ticket_id = eventosapp_badge_resolve_ticket_id();
    $evento_id = eventosapp_badge_resolve_event_id($ticket_id);

    if (!$evento_id) wp_die('Evento inválido', '', 400);

    // El botón del metabox es administrativo y mantiene validación por capacidad.
    if (!current_user_can('edit_post', $evento_id)) wp_die('Sin permisos.', '', 403);

    eventosapp_badge_output_html($evento_id, $ticket_id, true);
}

/**
 * AJAX compatible con el render usado por búsqueda/impresión y kiosko.
 *
 * Se registra con prioridad 1 para que este render centralizado use la misma
 * configuración del metabox y también soporte QR Networking.
 */
add_action('wp_ajax_eventosapp_render_badge', 'eventosapp_render_badge_handler', 1);
add_action('wp_ajax_nopriv_eventosapp_render_badge', 'eventosapp_render_badge_handler', 1);

function eventosapp_render_badge_handler() {
    $ticket_id = eventosapp_badge_resolve_ticket_id();
    $evento_id = eventosapp_badge_resolve_event_id($ticket_id);

    if (!$evento_id) wp_die('Evento inválido', '', 400);

    $nonce = '';
    if (isset($_GET['nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_GET['nonce']));
    } elseif (isset($_GET['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
    }

    $nonce_ok = false;
    if ($nonce !== '') {
        $nonce_actions = [
            'eventosapp_render_badge',
            'eventosapp_download_badge',
            'eventosapp_self_checkin',
            'eventosapp_self_checkin_nonce',
            'eventosapp_badge',
        ];
        foreach ($nonce_actions as $action) {
            if (wp_verify_nonce($nonce, $action)) {
                $nonce_ok = true;
                break;
            }
        }
    }

    $can_edit_event  = current_user_can('edit_post', $evento_id);
    $can_edit_ticket = ($ticket_id && current_user_can('edit_post', $ticket_id));

    if (!$nonce_ok && !$can_edit_event && !$can_edit_ticket) {
        wp_die('Nonce inválido', '', 403);
    }

    eventosapp_badge_output_html($evento_id, $ticket_id, true);
}
