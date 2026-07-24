from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TARGET = ROOT / "includes/admin/eventosapp-badges.php"


def replace_once(old: str, new: str) -> None:
    content = TARGET.read_text(encoding="utf-8")
    count = content.count(old)
    if count != 1:
        raise RuntimeError(f"Expected one match, found {count}: {old[:140]!r}")
    TARGET.write_text(content.replace(old, new, 1), encoding="utf-8")


replace_once(
    '''    $border_width = (int) $border_width;

    $preview_ticket_key = get_post_meta($post->ID, 'eventosapp_badge_ticket_key', true) ?: '';
''',
    '''    $border_width = (int) $border_width;
    $android_print_preview = (string) get_post_meta($post->ID, 'eventosapp_badge_android_print_preview', true) === '1';

    $preview_ticket_key = get_post_meta($post->ID, 'eventosapp_badge_ticket_key', true) ?: '';
'''
)

replace_once(
    '''              <span class="evapp-badge-help"><?php _e('Las medidas y márgenes se crean o editan en la nueva página Biblioteca de Escarapelas.', 'eventosapp'); ?></span>
            </div>

            <div class="evapp-paper-row">
''',
    '''              <span class="evapp-badge-help"><?php _e('Las medidas y márgenes se crean o editan en la nueva página Biblioteca de Escarapelas.', 'eventosapp'); ?></span>
            </div>

            <div class="evapp-badge-field">
              <label for="eventosapp_badge_android_print_preview">
                <input type="checkbox"
                       name="eventosapp_badge_android_print_preview"
                       id="eventosapp_badge_android_print_preview"
                       value="1" <?php checked($android_print_preview); ?>>
                <?php _e('Mostrar vista previa en la app Android antes de imprimir', 'eventosapp'); ?>
              </label>
              <span class="evapp-badge-help">
                <?php _e('Cuando está activa, la app muestra la interpretación final de la escarapela y exige confirmación antes de enviar datos por Bluetooth. Al desactivarla, la impresión continúa directamente como hasta ahora.', 'eventosapp'); ?>
              </span>
            </div>

            <div class="evapp-paper-row">
'''
)

replace_once(
    '''    if ($paper_template === 'legacy_event' || isset($templates[$paper_template])) {
        update_post_meta($post_id, 'eventosapp_badge_paper_template', $paper_template);
    }

    if (isset($_POST['eventosapp_badge_design'])) {
''',
    '''    if ($paper_template === 'legacy_event' || isset($templates[$paper_template])) {
        update_post_meta($post_id, 'eventosapp_badge_paper_template', $paper_template);
    }

    update_post_meta(
        $post_id,
        'eventosapp_badge_android_print_preview',
        !empty($_POST['eventosapp_badge_android_print_preview']) ? '1' : '0'
    );

    if (isset($_POST['eventosapp_badge_design'])) {
'''
)

replace_once(
    '''        'border_width'        => max(0, (int) $get('eventosapp_badge_border_width', 0)),
        'text_align'          => $text_align,
    ];
''',
    '''        'border_width'        => max(0, (int) $get('eventosapp_badge_border_width', 0)),
        'text_align'          => $text_align,
        'android_print_preview' => (string) $get('eventosapp_badge_android_print_preview', '0') === '1',
    ];
'''
)

replace_once(
    '''<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Escarapela</title>
''',
    '''<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="eventosapp-print-preview" content="<?php echo !empty($cfg['android_print_preview']) ? '1' : '0'; ?>">
<meta name="eventosapp-badge-render-contract" content="2">
<title>Escarapela</title>
'''
)

replace_once(
    '''     data-paper-page-orientation="<?php echo esc_attr($paper_page_orientation); ?>"
     data-content-rotation="<?php echo esc_attr($paper_content_rotation); ?>">
''',
    '''     data-paper-page-orientation="<?php echo esc_attr($paper_page_orientation); ?>"
     data-content-rotation="<?php echo esc_attr($paper_content_rotation); ?>"
     data-android-print-preview="<?php echo !empty($cfg['android_print_preview']) ? '1' : '0'; ?>"
     data-render-contract-version="2">
'''
)

# The workflow and patch script are temporary implementation helpers.
for temporary in [
    ROOT / ".github/workflows/apply-android-badge-preview.yml",
    ROOT / ".github/scripts/apply_android_badge_preview.py",
]:
    if temporary.exists():
        temporary.unlink()
