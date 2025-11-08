<?php
if (!defined('ABSPATH')) exit;

/**
 * Devuelve el esquema de campos extra para un evento.
 * Estructura normalizada por campo:
 * ['key'=>string,'label'=>string,'type'=>'text|number|select','required'=>0|1,'options'=>[]]
 */
if (!function_exists('eventosapp_get_event_extra_fields')) {
    function eventosapp_get_event_extra_fields($event_id){
        $fields = get_post_meta($event_id, '_eventosapp_extra_fields', true);
        if (!is_array($fields)) $fields = [];
        $out = []; $used = [];

        foreach ($fields as $f){
            $label = isset($f['label']) ? trim(wp_strip_all_tags($f['label'])) : '';
            if ($label === '') continue;

            $key = isset($f['key']) ? sanitize_key($f['key']) : '';
            if (!$key) {
                $key = sanitize_key(
                    remove_accents(strtolower(preg_replace('/\W+/', '_', $label)))
                );
            }
            if (!$key) continue;
            if (isset($used[$key])) { $k=1; while(isset($used[$key.'_'.$k])) $k++; $key = $key.'_'.$k; }
            $used[$key]=1;

            $type = in_array($f['type'] ?? 'text', ['text','number','select'], true) ? $f['type'] : 'text';
            $req  = !empty($f['required']) ? 1 : 0;

            $opts = [];
            if ($type === 'select') {
                $raw = $f['options'] ?? [];
                if (is_string($raw)) $raw = preg_split("/\r\n|\n|\r/", $raw);
                if (is_array($raw)) {
                    foreach ($raw as $o){
                        $o = trim(wp_strip_all_tags($o));
                        if ($o!=='') $opts[] = $o;
                    }
                }
            }
            $out[] = ['key'=>$key,'label'=>$label,'type'=>$type,'required'=>$req,'options'=>$opts];
        }
        return $out;
    }
}

/** Normaliza/sanea un valor según el tipo del campo */
if (!function_exists('eventosapp_normalize_extra_value')) {
    function eventosapp_normalize_extra_value($field, $value){
        $t = $field['type'] ?? 'text';
        if ($t === 'number') {
            return (string)(is_numeric($value) ? 0 + $value : '');
        }
        if ($t === 'select') {
            $opts = array_map('strval', $field['options'] ?? []);
            $val  = (string)$value;
            return in_array($val, $opts, true) ? $val : '';
        }
        return sanitize_text_field($value);
    }
}

/** Metabox en la edición del evento: define los campos */
add_action('add_meta_boxes', function(){
    add_meta_box(
        'eventosapp_extra_fields',
        'Campos adicionales del asistente (este evento)',
        'eventosapp_render_metabox_extra_fields',
        'eventosapp_event',
        'normal',
        'default'
    );
});

function eventosapp_render_metabox_extra_fields($post){
    wp_nonce_field('eventosapp_extra_fields_save','eventosapp_extra_fields_nonce');
    $fields = eventosapp_get_event_extra_fields($post->ID);
    ?>
    <style>
      .evapp-extras .row{border:1px solid #e5e5e5;padding:10px;border-radius:10px;margin-bottom:8px;background:#fff}
      .evapp-extras .row .grid{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
      .evapp-extras .row .opt{margin-top:8px}
      .evapp-extras input[type=text]{width:220px}
      .evapp-extras textarea{width:100%}
    </style>
    <div class="evapp-extras">
      <p style="color:#555;margin:6px 0 12px">
        Agrega campos que se pedirán por ticket para <b>este evento</b>. Tipos: Texto, Número o Lista (opciones una por línea).
      </p>
      <div id="evapp-extras-list">
        <?php if (!$fields) $fields = []; ?>
        <?php foreach ($fields as $i=>$f): ?>
          <div class="row" data-i="<?php echo esc_attr($i); ?>">
            <div class="grid">
              <label>Etiqueta:
                <input type="text" name="evapp_extra[label][]" value="<?php echo esc_attr($f['label']); ?>">
              </label>
              <label>Clave:
                <input type="text" class="evapp-key" name="evapp_extra[key][]" value="<?php echo esc_attr($f['key']); ?>">
              </label>
              <label>Tipo:
                <select name="evapp_extra[type][]" class="evapp-type">
                  <option value="text"   <?php selected($f['type'],'text'); ?>>Texto</option>
                  <option value="number" <?php selected($f['type'],'number'); ?>>Número</option>
                  <option value="select" <?php selected($f['type'],'select'); ?>>Lista</option>
                </select>
              </label>
              <label style="margin-left:8px">
                <input type="checkbox" name="evapp_extra[required][<?php echo esc_attr($i); ?>]" value="1" <?php checked($f['required'],1); ?>>
                Obligatorio
              </label>
              <a href="#" class="button link-delete" style="margin-left:auto">Eliminar</a>
            </div>
            <div class="opt" style="<?php echo $f['type']==='select'?'':'display:none'; ?>">
              <label>Opciones (una por línea):<br>
                <textarea name="evapp_extra[options][]" rows="3"><?php echo esc_textarea(implode("\n",$f['options'])); ?></textarea>
              </label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <p><a href="#" id="evapp-extras-add" class="button">Agregar campo</a></p>
    </div>
    <script>
    (function($){
      function slug(s){ return (s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9_]+/g,'_').replace(/^_+|_+$/g,''); }
      $(document).on('input','input[name="evapp_extra[label][]"]', function(){
        var $row=$(this).closest('.row'), $key=$row.find('.evapp-key');
        if (!$key.val()) $key.val(slug($(this).val()));
      });
      $(document).on('change','.evapp-type', function(){
        var $row=$(this).closest('.row');
        if ($(this).val()==='select') $row.find('.opt').slideDown(120); else $row.find('.opt').slideUp(120);
      });
      $('#evapp-extras-add').on('click', function(e){
        e.preventDefault();
        var idx = $('#evapp-extras-list .row').length;
        var html = '<div class="row" data-i="'+idx+'">'
          + '<div class="grid">'
          + ' <label>Etiqueta: <input type="text" name="evapp_extra[label][]" value=""></label>'
          + ' <label>Clave: <input type="text" class="evapp-key" name="evapp_extra[key][]" value=""></label>'
          + ' <label>Tipo: <select name="evapp_extra[type][]" class="evapp-type"><option value="text">Texto</option><option value="number">Número</option><option value="select">Lista</option></select></label>'
          + ' <label style="margin-left:8px"><input type="checkbox" name="evapp_extra[required]['+idx+']" value="1"> Obligatorio</label>'
          + ' <a href="#" class="button link-delete" style="margin-left:auto">Eliminar</a>'
          + '</div>'
          + '<div class="opt" style="display:none"><label>Opciones (una por línea):<br><textarea name="evapp_extra[options][]" rows="3"></textarea></label></div>'
          + '</div>';
        $('#evapp-extras-list').append(html);
      });
      $(document).on('click','.link-delete', function(e){ e.preventDefault(); $(this).closest('.row').remove(); });
    })(jQuery);
    </script>
    <?php
}

/** Guardado del esquema */
add_action('save_post_eventosapp_event', function($post_id){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['eventosapp_extra_fields_nonce']) || !wp_verify_nonce($_POST['eventosapp_extra_fields_nonce'], 'eventosapp_extra_fields_save')) return;

    $F = $_POST['evapp_extra'] ?? null;
    $result = [];
    if (is_array($F) && isset($F['label'],$F['key'],$F['type'])) {
        $labels = (array)$F['label'];
        $keys   = (array)$F['key'];
        $types  = (array)$F['type'];
        $reqs   = isset($F['required']) ? (array)$F['required'] : [];
        $opts   = isset($F['options']) ? (array)$F['options']  : [];
        $n = max(count($labels),count($keys),count($types));
        $opt_i = 0; $used=[];

        for($i=0;$i<$n;$i++){
            $label = trim(wp_unslash($labels[$i] ?? ''));
            if ($label==='') continue;

            $key = sanitize_key($keys[$i] ?? '');
            if (!$key) $key = sanitize_key(remove_accents(strtolower(preg_replace('/\W+/', '_', $label))));
            if (!$key) continue;
            if (isset($used[$key])) { $k=1; while(isset($used[$key.'_'.$k])) $k++; $key=$key.'_'.$k; }
            $used[$key]=1;

            $type = in_array($types[$i] ?? 'text', ['text','number','select'], true) ? $types[$i] : 'text';
            $required = isset($reqs[$i]) ? 1 : 0;

            $options = [];
            if ($type==='select') {
                $raw = $opts[$opt_i] ?? '';
                $opt_i++;
                if (is_string($raw)) $raw = preg_split("/\r\n|\n|\r/", $raw);
                if (is_array($raw)) {
                    foreach($raw as $o){
                        $o = trim(wp_strip_all_tags($o));
                        if ($o!=='') $options[]=$o;
                    }
                }
            }

            $result[] = ['label'=>$label,'key'=>$key,'type'=>$type,'required'=>$required,'options'=>$options];
        }
    }
    update_post_meta($post_id, '_eventosapp_extra_fields', $result);
}, 20);
