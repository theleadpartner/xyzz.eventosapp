<?php
/**
 * Admin – Generación Masiva de QR (solo HTML para imprimir)
 * - Submenú: "Generación Masiva de QR" (hija de eventosapp_dashboard)
 * - Usuario define: número inicial y cantidad total
 * - Maqueta fija tamaño Carta (Letter, 21.59×27.94 cm), margen 1 cm, grilla 4×6 (24 por página)
 * - Sin Dompdf: se imprime con el navegador (ventana aislada + @page size:letter)
 */

if (!defined('ABSPATH')) exit;

/* ===============================
 * Submenú (hija de eventosapp.php)
 * =============================== */
add_action('admin_menu', function () {
    add_submenu_page(
        'eventosapp_dashboard',
        'Generación Masiva de QR',
        'Generación Masiva de QR',
        'manage_options',
        'eventosapp_generador_masivo_qr',
        'eventosapp_qrmasivo_render_page'
    );
}, 11);

/** Persistencia simple de últimos valores */
if (!defined('EVENTOSAPP_QRMASIVO_OPTION')) {
    define('EVENTOSAPP_QRMASIVO_OPTION', 'eventosapp_qrmasivo_settings');
}

/* ====================
 * Pantalla de la página
 * ==================== */
function eventosapp_qrmasivo_render_page() {
    // (Opcional) librería QR si la tienes; si no, se usa placeholder
    $includes_dir = dirname(__DIR__) . '/';
    if (!class_exists('QRcode') && file_exists($includes_dir . 'qrlib/qrlib.php')) {
        @require_once $includes_dir . 'qrlib/qrlib.php';
    }

    $defaults = ['start_num' => 1, 'count' => 24];
    $settings = get_option(EVENTOSAPP_QRMASIVO_OPTION, []);
    if (!is_array($settings)) $settings = [];
    $settings = array_merge($defaults, $settings);

    $error_msg  = '';
    $notice_msg = '';
    $generated  = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eventosapp_qrmasivo_action'])) {
        check_admin_referer('eventosapp_qrmasivo_nonce', 'eventosapp_qrmasivo_nonce');

        $incoming = [
            'start_num' => intval($_POST['start_num'] ?? 1),
            'count'     => max(1, intval($_POST['count'] ?? 0)),
        ];
        if ($incoming['count'] <= 0) {
            $error_msg = 'La "Cantidad total" debe ser mayor a 0.';
        }

        if (!$error_msg) {
            $settings = array_merge($settings, $incoming);
            update_option(EVENTOSAPP_QRMASIVO_OPTION, $settings, false);
            $generated  = true;
            $notice_msg = 'Vista previa lista. Pulsa "Imprimir" para abrir el diálogo del navegador.';
        }
    }

    // Preparamos datos
    $codes_all = [];
    if ($generated && !$error_msg) {
        for ($i = 0; $i < (int)$settings['count']; $i++) {
            $codes_all[] = (string) ($settings['start_num'] + $i);
        }
    }
    // Vista previa: solo la primera hoja (24)
    $preview_html = $generated && !$error_msg
        ? eventosapp_qrmasivo_build_html_letter_4x6(array_slice($codes_all, 0, 24), true)
        : '';

    // HTML completo para imprimir (todas las páginas), oculto en el DOM
    $print_html = $generated && !$error_msg
        ? eventosapp_qrmasivo_build_html_letter_4x6($codes_all, false)
        : '';
    ?>
    <div class="wrap">
      <h1>Generación Masiva de QR</h1>

      <?php if ($error_msg): ?>
        <div class="notice notice-error"><p><?php echo esc_html($error_msg); ?></p></div>
      <?php elseif ($notice_msg): ?>
        <div class="notice notice-success"><p><?php echo esc_html($notice_msg); ?></p></div>
      <?php endif; ?>

      <form method="post">
        <?php wp_nonce_field('eventosapp_qrmasivo_nonce', 'eventosapp_qrmasivo_nonce'); ?>
        <table class="form-table" role="presentation">
          <tbody>
            <tr>
              <th scope="row">Número inicial</th>
              <td><input type="number" step="1" name="start_num" value="<?php echo esc_attr($settings['start_num']); ?>" class="regular-text" required></td>
            </tr>
            <tr>
              <th scope="row">Cantidad total</th>
              <td><input type="number" step="1" min="1" name="count" value="<?php echo esc_attr($settings['count']); ?>" class="regular-text" required></td>
            </tr>
          </tbody>
        </table>

        <p class="description" style="margin-top:-10px">
          Se imprime en <b>Carta (Letter, 21.59×27.94 cm)</b>, margen interno 1 cm, grilla fija <b>4 × 6</b> (24 por página).
          Usa el botón <b>Imprimir</b> y en el diálogo del navegador pon: <i>Escala 100%</i>, <i>Márgenes: ninguno</i>, <i>Tamaño: Carta</i>.
        </p>

        <p style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <button type="submit" class="button button-primary" name="eventosapp_qrmasivo_action" value="generate">Generar vista previa</button>
          <button type="button" class="button" onclick="evqrOpenPrint()" <?php disabled(!$generated || (bool)$error_msg); ?>>Imprimir</button>
        </p>
      </form>

      <?php if ($generated && !$error_msg): ?>
        <hr>
        <h2>Vista previa (primera hoja)</h2>
        <div style="border:1px solid #ccd0d4; background:#fff; padding:12px; overflow:auto;">
          <div style="width:21.59cm; min-height:27.94cm; background:#fafafa; outline:1px dashed #e2e4e7;">
            <?php echo $preview_html; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($generated && !$error_msg): ?>
        <div id="evqr-print-html" style="display:none"><?php echo $print_html; ?></div>
      <?php endif; ?>
    </div>

    <script>
      function evqrOpenPrint(){
        var container = document.getElementById('evqr-print-html');
        if(!container){ alert('Genera la vista previa primero.'); return; }
        var html = container.innerHTML;

        var w = window.open('', '_blank');
        if(!w){ alert('El navegador bloqueó la ventana de impresión. Habilita pop-ups.'); return; }

        w.document.open();
        w.document.write(
          '<!doctype html><html><head><meta charset="utf-8">' +
          '<title>Generación Masiva de QR</title>' +
          // Reglas de impresión y tipografía base
          '<style>@page{size: letter portrait; margin:0} html,body{margin:0;padding:0;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}</style>' +
          '</head><body>' + html +
          '<script>window.onload=function(){window.focus();window.print(); setTimeout(function(){ window.close(); }, 400);};<\/script>' +
          '</body></html>'
        );
        w.document.close();
      }
    </script>
    <?php
}

/* ================================================================
 * Constructor de páginas (Letter, margen 1 cm, grilla 4×6, con QR o placeholder)
 * - $only_first_page = true => fuerza a 1 hoja (para vista previa)
 * - Si no existe la clase QRcode, se dibuja un recuadro punteado (placeholder)
 * ================================================================ */
function eventosapp_qrmasivo_build_html_letter_4x6(array $codes, bool $only_first_page = false): string {
    // Parámetros (cm)
    $pageW = 21.59; $pageH = 27.94; $margin = 1.00;
    $cols = 4; $rows = 6; $perPage = $cols * $rows;
    $gap = 0.25; $borderPx = 1; $pad = 0.20; $labelH = 0.50;

    // Celdas (cm)
    $cellW = ( $pageW - 2*$margin - ($cols-1)*$gap ) / $cols;   // ≈ 4.71
    $cellH = ( $pageH - 2*$margin - ($rows-1)*$gap ) / $rows;   // ≈ 4.115

    // QR (o placeholder) – tamaño visual
    $qrSide = 3.16; // cm

    $pages = array_chunk($codes, $perPage);
    if ($only_first_page) $pages = array_slice($pages, 0, 1);

    $html = '';

    // Estilos de la maqueta (inline y mínimos)
    $html .= '<style>
      .evqr-page{ width:'.$pageW.'cm; height:'.$pageH.'cm; padding:'.$margin.'cm; box-sizing:border-box; }
      .evqr-sheet{ border-collapse:separate; border-spacing:'.$gap.'cm '.$gap.'cm; table-layout:fixed; margin:0; }
      .evqr-td{ width:'.$cellW.'cm; height:'.$cellH.'cm; padding:0; vertical-align:top; }
      .evqr-box{ width:100%; height:100%; box-sizing:border-box; background:#fff; border:'.$borderPx.'px solid #000; padding:'.$pad.'cm; display:flex; flex-direction:column; align-items:center; justify-content:flex-start; }
      .evqr-qr{ width:'.$qrSide.'cm; height:'.$qrSide.'cm; display:flex; align-items:center; justify-content:center; margin:0.1cm auto 0.2cm auto; }
      .evqr-label{ width:100%; height:'.$labelH.'cm; display:table; }
      .evqr-label span{ display:table-cell; vertical-align:middle; text-align:center; font-size:10pt; line-height:1; }
    </style>';

    $total = count($pages);
    foreach ($pages as $pi => $chunk) {
        $pageBreak = ($pi < $total - 1) ? ' style="page-break-after:always;"' : '';
        $html .= '<div class="evqr-page"'.$pageBreak.'><table class="evqr-sheet"><tbody>';

        $i = 0;
        for ($r = 0; $r < $rows; $r++) {
            $html .= '<tr>';
            for ($c = 0; $c < $cols; $c++) {
                if ($i < count($chunk)) {
                    $code = htmlspecialchars((string)$chunk[$i++], ENT_QUOTES, 'UTF-8');

                    // Si hay librería QR, generamos PNG embebido; si no, recuadro punteado
                    $qrImg = eventosapp_qrmasivo_data_uri_or_placeholder($code, $qrSide);

                    $html .= '<td class="evqr-td">
                      <div class="evqr-box">
                        <div class="evqr-qr">'.$qrImg.'</div>
                        <div class="evqr-label"><span>'.$code.'</span></div>
                      </div>
                    </td>';
                } else {
                    $html .= '<td class="evqr-td"></td>';
                }
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
    }

    return $html;
}

/* =====================================================
 * QR embebido si existe QRlib; si no, placeholder visual
 * ===================================================== */
function eventosapp_qrmasivo_data_uri_or_placeholder(string $text, float $side_cm): string {
    if (class_exists('QRcode')) {
        // Generamos PNG temporal
        $tmp = function_exists('wp_tempnam') ? wp_tempnam('evqr.png') : (tempnam(sys_get_temp_dir(), 'evqr_') . '.png');
        if ($tmp) {
            QRcode::png($text, $tmp, 'M', 5, 2);
            $png = @file_get_contents($tmp); @unlink($tmp);
            if ($png) {
                $base64 = 'data:image/png;base64,'.base64_encode($png);
                // Fijamos tamaño exacto del PNG en cm para impresión fiel
                return '<img src="'.$base64.'" alt="'.esc_attr($text).'" style="width:'.$side_cm.'cm; height:'.$side_cm.'cm; display:block; image-rendering:crisp-edges;">';
            }
        }
    }
    // Placeholder (sin QRlib)
    return '<div style="width:'.$side_cm.'cm; height:'.$side_cm.'cm; border:1px dashed #999;"></div>';
}
