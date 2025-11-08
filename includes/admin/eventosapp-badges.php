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
 * NUEVA FUNCIÓN: Obtiene todos los campos disponibles para un evento (básicos + extras)
 */
function eventosapp_get_all_available_fields($evento_id = 0) {
    // Campos básicos del ticket
    $basic_fields = [
        'none'        => __('Ninguno', 'eventosapp'),
        'full_name'   => __('Nombres + Apellidos', 'eventosapp'),
        'nombre'      => __('Nombre', 'eventosapp'),
        'apellido'    => __('Apellido', 'eventosapp'),
        'company'     => __('Nombre de la Empresa', 'eventosapp'),
        'designation' => __('Cargo', 'eventosapp'),
        'cc_id'       => __('CC_ID', 'eventosapp'),
        'email'       => __('Email', 'eventosapp'),
        'telefono'    => __('Teléfono', 'eventosapp'),
        'nit'         => __('NIT', 'eventosapp'),
        'ciudad'      => __('Ciudad', 'eventosapp'),
        'pais'        => __('País', 'eventosapp'),
        'localidad'   => __('Localidad', 'eventosapp'),
        'qr'          => __('QR', 'eventosapp'),
    ];
    
    // Si hay un evento, agregar sus campos adicionales
    if ($evento_id && function_exists('eventosapp_get_event_extra_fields')) {
        $extra_fields = eventosapp_get_event_extra_fields($evento_id);
        if (!empty($extra_fields)) {
            foreach ($extra_fields as $field) {
                $key = 'extra_' . $field['key'];
                $basic_fields[$key] = $field['label'] . ' (campo adicional)';
            }
        }
    }
    
    return $basic_fields;
}

/**
 * Render del metabox
 */
function eventosapp_render_badge_metabox($post) {
    wp_nonce_field('eventosapp_save_badge_settings', 'eventosapp_badge_nonce');

    // Valores guardados (con defaults)
    $design   = get_post_meta($post->ID, 'eventosapp_badge_design', true) ?: 'manillas';

    $order_fields = [];
    for ($i=1; $i<=5; $i++){
        $order_fields[$i] = get_post_meta($post->ID, "eventosapp_field_order_{$i}", true) ?: 'none';
    }

    $width          = (int) (get_post_meta($post->ID, 'eventosapp_badge_width', true) ?: 200);
    $height         = (int) (get_post_meta($post->ID, 'eventosapp_badge_height', true) ?: 100);
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
    if ($border_width === '' || $border_width === null) $border_width = 1;
    $border_width   = (int) $border_width;

    // Campo para vista previa con el ID público del ticket (tk...)
    $preview_ticket_key = get_post_meta($post->ID, 'eventosapp_badge_ticket_key', true) ?: '';

    // Obtener todos los campos disponibles (básicos + extras del evento)
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
 * Guardado del metabox (priority independiente)
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
	// Antes guardabas ..._badge_ticket_id como absint
	if (isset($_POST['eventosapp_badge_ticket_key'])) {
		update_post_meta(
			$post_id,
			'eventosapp_badge_ticket_key',
			sanitize_text_field($_POST['eventosapp_badge_ticket_key'])
		);
	}
}, 22);

/**
 * Helper público para jalar la configuración desde cualquier parte
 */
function eventosapp_get_badge_settings($evento_id) {
    $get = function($k,$def=null) use ($evento_id){
        $v = get_post_meta($evento_id, $k, true);
        return ($v === '' || $v === null) ? $def : $v;
    };
    $order = [];
    for($i=1;$i<=5;$i++){
        $order[$i] = $get("eventosapp_field_order_{$i}", 'none');
    }
    return [
        'design'         => $get('eventosapp_badge_design', 'manillas'),
        'order'          => $order,
        'width'          => (int) $get('eventosapp_badge_width', 200),
        'height'         => (int) $get('eventosapp_badge_height', 100),
        'size_large'     => (int) $get('eventosapp_badge_size_large', 24),
        'size_medium'    => (int) $get('eventosapp_badge_size_medium', 18),
        'size_small'     => (int) $get('eventosapp_badge_size_small', 14),
        'weight_large'   => (int) $get('eventosapp_badge_weight_large', 600),
        'weight_medium'  => (int) $get('eventosapp_badge_weight_medium', 500),
        'weight_small'   => (int) $get('eventosapp_badge_weight_small', 400),
        'sep_vertical'   => (int) $get('eventosapp_badge_sep_vertical', 4),
        'sep_horizontal' => (int) $get('eventosapp_badge_sep_horizontal', 4),
        'qr_size'        => (int) $get('eventosapp_badge_qr_size', 72),
        'border_width'   => (int) $get('eventosapp_badge_border_width', 1),
    ];
}

/**
 * Helper para generar URL firmada de impresión desde admin
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
 * AJAX admin: imprimir/descargar escarapela
 * (protegido: requiere capacidad de editar el evento)
 */
add_action('wp_ajax_eventosapp_download_badge', 'eventosapp_download_badge_handler');
// Opcional: permite usarlo también sin sesión (si lo necesitas en front),
// pero recuerda que el nonce ya es obligatorio.
add_action('wp_ajax_nopriv_eventosapp_download_badge', 'eventosapp_download_badge_handler');

function eventosapp_download_badge_handler() {
    if ( empty($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'eventosapp_download_badge') ) {
        wp_die('Nonce inválido', '', 403);
    }

    $evento_id  = absint($_GET['post_id'] ?? 0);
    $ticket_id  = absint($_GET['ticket_id'] ?? 0);
    $ticket_key = isset($_GET['ticket_key']) ? sanitize_text_field(wp_unslash($_GET['ticket_key'])) : '';

    if (!$evento_id) wp_die('Evento inválido', '', 400);

    // Si viene desde admin-ajax, valida permiso; si lo llamas desde front y quieres permitir público, comenta esta línea.
    if (!current_user_can('edit_post', $evento_id)) wp_die('Sin permisos.', '', 403);

    // Si llega ticket_key (tk...), buscar el post del ticket por meta eventosapp_ticketID
    if (!$ticket_id && $ticket_key) {
        $found = get_posts([
            'post_type'   => 'eventosapp_ticket',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_key'    => 'eventosapp_ticketID',
            'meta_value'  => $ticket_key,
        ]);
        if (!empty($found)) {
            $ticket_id = (int)$found[0];
        }
    }

    // Config
    $cfg = eventosapp_get_badge_settings($evento_id);

    // Labels base - inicializar array vacío
    $labels = [];

    // Si viene ticket, tomar datos reales del CPT eventosapp_ticket
    if ($ticket_id && get_post_type($ticket_id) === 'eventosapp_ticket') {
        $nombre   = get_post_meta($ticket_id, '_eventosapp_asistente_nombre', true);
        $apell    = get_post_meta($ticket_id, '_eventosapp_asistente_apellido', true);
        $empresa  = get_post_meta($ticket_id, '_eventosapp_asistente_empresa', true);
        $cargo    = get_post_meta($ticket_id, '_eventosapp_asistente_cargo', true);
        $cc       = get_post_meta($ticket_id, '_eventosapp_asistente_cc', true);
        $email    = get_post_meta($ticket_id, '_eventosapp_asistente_email', true);
        $tel      = get_post_meta($ticket_id, '_eventosapp_asistente_tel', true);
        $nit      = get_post_meta($ticket_id, '_eventosapp_asistente_nit', true);
        $ciudad   = get_post_meta($ticket_id, '_eventosapp_asistente_ciudad', true);
        $pais     = get_post_meta($ticket_id, '_eventosapp_asistente_pais', true);
        $localidad= get_post_meta($ticket_id, '_eventosapp_asistente_localidad', true);
        $code     = get_post_meta($ticket_id, 'eventosapp_ticketID', true);

        // Campos básicos
        $labels['full_name']   = trim($nombre . ' ' . $apell) ?: 'Nombres + Apellidos';
        $labels['nombre']      = $nombre ?: 'Nombre';
        $labels['apellido']    = $apell ?: 'Apellido';
        $labels['company']     = $empresa ?: 'Nombre de la Empresa';
        $labels['designation'] = $cargo   ?: 'Cargo';
        $labels['cc_id']       = $cc      ?: 'CC_ID';
        $labels['email']       = $email   ?: 'Email';
        $labels['telefono']    = $tel     ?: 'Teléfono';
        $labels['nit']         = $nit     ?: 'NIT';
        $labels['ciudad']      = $ciudad  ?: 'Ciudad';
        $labels['pais']        = $pais    ?: 'País';
        $labels['localidad']   = $localidad ?: 'Localidad';

        // QR
        if ($code && function_exists('eventosapp_get_ticket_qr_url')) {
            $labels['qr'] = eventosapp_get_ticket_qr_url($code);
        } else {
            $labels['qr'] = '';
        }

        // Campos adicionales del evento
        if (function_exists('eventosapp_get_event_extra_fields')) {
            $extra_fields = eventosapp_get_event_extra_fields($evento_id);
            if (!empty($extra_fields)) {
                foreach ($extra_fields as $field) {
                    $key = 'extra_' . $field['key'];
                    $value = get_post_meta($ticket_id, '_eventosapp_extra_' . $field['key'], true);
                    $labels[$key] = $value ?: $field['label'];
                }
            }
        }
    } else {
        // Valores por defecto si no hay ticket
        $labels = [
            'full_name'   => 'Nombres + Apellidos',
            'nombre'      => 'Nombre',
            'apellido'    => 'Apellido',
            'company'     => 'Nombre de la Empresa',
            'designation' => 'Cargo',
            'cc_id'       => 'CC_ID',
            'email'       => 'Email',
            'telefono'    => 'Teléfono',
            'nit'         => 'NIT',
            'ciudad'      => 'Ciudad',
            'pais'        => 'País',
            'localidad'   => 'Localidad',
            'qr'          => '',
        ];
        
        // Campos adicionales con valores por defecto
        if (function_exists('eventosapp_get_event_extra_fields')) {
            $extra_fields = eventosapp_get_event_extra_fields($evento_id);
            if (!empty($extra_fields)) {
                foreach ($extra_fields as $field) {
                    $key = 'extra_' . $field['key'];
                    $labels[$key] = $field['label'];
                }
            }
        }
    }

    $flex_dir = ($cfg['design'] === 'escarapelas') ? 'column' : 'row';

    header('Content-Type: text/html; charset=UTF-8');
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
    <?php echo ($cfg['border_width']>0 ? "border:{$cfg['border_width']}px solid #000;" : "border:none;"); ?>
    display:flex; flex-direction:<?php echo esc_attr($flex_dir); ?>;
    align-items:stretch; justify-content:center;
    width:<?php echo (int)$cfg['width']; ?>px; height:<?php echo (int)$cfg['height']; ?>px;
    padding:4px; box-sizing:border-box;
  }
  .left,.right{display:flex; flex-direction:column; justify-content:center; height:100%;}
  .right{align-items:center;}
  .slot{line-height:1.15;}
  @media print {
    @page { margin: 8mm; }
  }
</style>
</head>
<body>
<div class="badge">
<?php
    $active = [];
    for ($i=1;$i<=5;$i++){
        $f = $cfg['order'][$i] ?? 'none';
        if ($f !== 'none') $active[] = $f;
    }

    if ($cfg['design'] === 'escarapelas_split') {
        // Diseño split 3 izq / 1 der (ORIGINAL)
        $left  = array_slice($active, 0, 3);
        $right = $active[3] ?? null;

        echo "<div class='left'>";
        foreach ($left as $idx=>$field) {
            $fs = ($idx===0) ? $cfg['size_large'] : (($idx===1) ? $cfg['size_medium'] : $cfg['size_small']);
            $fw = ($idx===0) ? $cfg['weight_large'] : (($idx===1) ? $cfg['weight_medium'] : $cfg['weight_small']);
            $m  = $cfg['sep_vertical'];
            if ($field === 'qr' && !empty($labels['qr'])) {
                echo "<div class='slot' style='margin:{$m}px'><img src='".esc_url($labels['qr'])."' width='{$cfg['qr_size']}' height='{$cfg['qr_size']}' alt='QR'></div>";
            } else {
                $label_text = isset($labels[$field]) ? $labels[$field] : '';
                echo "<div class='slot' style='margin:{$m}px; font-size:{$fs}px; font-weight:{$fw};'>".esc_html($label_text)."</div>";
            }
        }
        echo "</div>";

        $mh = $cfg['sep_horizontal'];
        echo "<div class='right' style='margin-left:{$mh}px'>";
        if ($right) {
            if ($right === 'qr' && !empty($labels['qr'])) {
                echo "<div class='slot' style='margin:{$cfg['sep_vertical']}px'><img src='".esc_url($labels['qr'])."' width='{$cfg['qr_size']}' height='{$cfg['qr_size']}' alt='QR'></div>";
            } else {
                $label_text = isset($labels[$right]) ? $labels[$right] : '';
                echo "<div class='slot' style='margin:{$cfg['sep_vertical']}px; font-size:{$cfg['size_medium']}px; font-weight:{$cfg['weight_medium']};'>".esc_html($label_text)."</div>";
            }
        }
        echo "</div>";

    } elseif ($cfg['design'] === 'escarapelas_split_4') {
        // NUEVO DISEÑO: split 4 izq / 1 der
        $left  = array_slice($active, 0, 4);
        $right = $active[4] ?? null;

        echo "<div class='left'>";
        foreach ($left as $idx=>$field) {
            // Tamaños: primero grande, segundo y tercero medianos, cuarto pequeño
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
            $m  = $cfg['sep_vertical'];
            
            if ($field === 'qr' && !empty($labels['qr'])) {
                echo "<div class='slot' style='margin:{$m}px'><img src='".esc_url($labels['qr'])."' width='{$cfg['qr_size']}' height='{$cfg['qr_size']}' alt='QR'></div>";
            } else {
                $label_text = isset($labels[$field]) ? $labels[$field] : '';
                echo "<div class='slot' style='margin:{$m}px; font-size:{$fs}px; font-weight:{$fw};'>".esc_html($label_text)."</div>";
            }
        }
        echo "</div>";

        $mh = $cfg['sep_horizontal'];
        echo "<div class='right' style='margin-left:{$mh}px'>";
        if ($right) {
            if ($right === 'qr' && !empty($labels['qr'])) {
                echo "<div class='slot' style='margin:{$cfg['sep_vertical']}px'><img src='".esc_url($labels['qr'])."' width='{$cfg['qr_size']}' height='{$cfg['qr_size']}' alt='QR'></div>";
            } else {
                $label_text = isset($labels[$right]) ? $labels[$right] : '';
                echo "<div class='slot' style='margin:{$cfg['sep_vertical']}px; font-size:{$cfg['size_medium']}px; font-weight:{$cfg['weight_medium']};'>".esc_html($label_text)."</div>";
            }
        }
        echo "</div>";

    } else {
        // Diseños normales (manillas o escarapelas vertical)
        foreach (array_values($active) as $idx=>$field) {
            $margin = ($cfg['design']==='escarapelas') ? $cfg['sep_vertical'] : $cfg['sep_horizontal'];
            if ($field==='qr' && !empty($labels['qr'])) {
                echo "<div class='slot' style='margin:{$margin}px'><img src='".esc_url($labels['qr'])."' width='{$cfg['qr_size']}' height='{$cfg['qr_size']}' alt='QR'></div>";
            } else {
                if ($cfg['design']==='escarapelas') {
                    $fs = ($idx===0) ? $cfg['size_large'] : (($idx<=2) ? $cfg['size_medium'] : $cfg['size_small']);
                    $fw = ($idx===0) ? $cfg['weight_large'] : (($idx<=2) ? $cfg['weight_medium'] : $cfg['weight_small']);
                } else {
                    $fs = $cfg['size_medium'];
                    $fw = $cfg['weight_medium'];
                }
                $label_text = isset($labels[$field]) ? $labels[$field] : '';
                echo "<div class='slot' style='margin:{$margin}px; font-size:{$fs}px; font-weight:{$fw};'>".esc_html($label_text)."</div>";
            }
        }
    }
?>
</div>
<script>window.print();</script>
</body>
</html>
<?php
    exit;
}
