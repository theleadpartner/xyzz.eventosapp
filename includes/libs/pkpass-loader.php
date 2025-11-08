<?php
/**
 * includes/libs/pkpass-loader.php
 *
 * Carga manual de la librería PKPass sin Composer.
 * Estructura esperada: /includes/libs/pkpass/*.php  (copiaste /src del repo aquí)
 *
 * Objetivo:
 * - Mantener orden de carga (PKPass.php primero).
 * - Soportar forks con namespaces \PKPass\PKPass y \PhpPkpass\PKPass mediante class_alias().
 * - Fijar la ruta base en /includes/libs/pkpass/ para evitar sorpresas.
 * - Versión de producción: sin logs ni inyección a consola.
 */

if (!defined('ABSPATH')) exit;

/* ============================================================
 * =================== HELPER LOG CONSOLA =====================
 * ============================================================ */

/**
 * No-op en producción: mantiene la firma para compatibilidad,
 * pero no escribe en error_log ni guarda nada en opciones.
 */
if (!function_exists('eventosapp_pkpass_loader_log')) {
    function eventosapp_pkpass_loader_log($title, $data = []) {
        return;
    }
}

/**
 * Mantiene la firma pero no inyecta nada en el admin.
 */
if (!function_exists('eventosapp_pkpass_loader_console_bootstrap')) {
    function eventosapp_pkpass_loader_console_bootstrap() {
        return;
    }
    // Nota: NO registramos ningún hook aquí para evitar inyección en consola.
}

/* ============================================================
 * ======================= LOADER PKPASS ======================
 * ============================================================ */

if ( ! function_exists('eventosapp_include_pkpass') ) {
    function eventosapp_include_pkpass() {
        static $done = false;

        // Llamada repetida
        if ($done) {
            eventosapp_pkpass_loader_log('Loader: ya ejecutado (return temprano)', [
                '__FILE__'      => __FILE__,
                'php_version'   => PHP_VERSION,
                'include_path'  => ini_get('include_path'),
                'cwd'           => getcwd(),
            ]);
            return;
        }

        // Estado antes de cargar
        $pre = [
            'PKPass global existe?'        => class_exists('PKPass', false) ? 'sí' : 'no',
            '\PKPass\PKPass existe?'       => class_exists('\PKPass\PKPass', false) ? 'sí' : 'no',
            '\PhpPkpass\PKPass existe?'    => class_exists('\PhpPkpass\PKPass', false) ? 'sí' : 'no',
            '__FILE__'                     => __FILE__,
            '__DIR__'                      => __DIR__,
            'realpath(__DIR__)'            => realpath(__DIR__),
            'php_version'                  => PHP_VERSION,
            'include_path'                 => ini_get('include_path'),
            'cwd'                          => getcwd(),
        ];
        eventosapp_pkpass_loader_log('Loader: estado previo', $pre);

        // Si ya está cargada en cualquiera de sus variantes, salir (marcando done).
        if ($pre['PKPass global existe?'] === 'sí' || $pre['\PKPass\PKPass existe?'] === 'sí' || $pre['\PhpPkpass\PKPass existe?'] === 'sí') {
            $done = true;
            eventosapp_pkpass_loader_log('Loader: clases ya presentes, no se requiere carga adicional', $pre);
            return;
        }

        // Ruta fija al folder de la librería (sin Composer).
        $base = __DIR__ . '/pkpass/';
        $base_info = [
            'base'             => $base,
            'realpath(base)'   => realpath($base) ?: '(n/a)',
            'is_dir(base)?'    => is_dir($base) ? 'sí' : 'no',
            'is_readable(base)?'=> is_readable($base) ? 'sí' : 'no',
        ];
        eventosapp_pkpass_loader_log('Loader: base de librería', $base_info);

        // MUY IMPORTANTE: cargar primero PKPass.php (clase base), luego el resto si existen.
        $to_load = ['PKPass.php','PKPassException.php','PKPassBundle.php','FinanceOrder.php'];
        foreach ($to_load as $f) {
            $p = $base . $f;
            $info = [
                'archivo'        => $f,
                'path'           => $p,
                'realpath'       => realpath($p) ?: '(n/a)',
                'file_exists?'   => file_exists($p) ? 'sí' : 'no',
                'is_readable?'   => is_readable($p) ? 'sí' : 'no',
                'incluido'       => 'no',
            ];
            if (is_readable($p)) {
                try {
                    require_once $p;
                    $info['incluido'] = 'sí';
                } catch (\Throwable $e) {
                    $info['incluido'] = 'error';
                    $info['exception'] = $e->getMessage();
                }
            }
            eventosapp_pkpass_loader_log('Loader: intento include', $info);
        }

        // Alias global si la clase viene con namespace en algún fork.
        $post_include = [
            'PKPass global'         => class_exists('PKPass', false) ? 'sí' : 'no',
            '\PKPass\PKPass'        => class_exists('\PKPass\PKPass', false) ? 'sí' : 'no',
            '\PhpPkpass\PKPass'     => class_exists('\PhpPkpass\PKPass', false) ? 'sí' : 'no',
        ];
        if (!class_exists('PKPass', false) && class_exists('\PKPass\PKPass', false)) {
            class_alias('\PKPass\PKPass', 'PKPass');
            $post_include['alias_aplicado'] = 'class_alias(\\PKPass\\PKPass => PKPass)';
        } elseif (!class_exists('PKPass', false) && class_exists('\PhpPkpass\PKPass', false)) {
            class_alias('\PhpPkpass\PKPass', 'PKPass');
            $post_include['alias_aplicado'] = 'class_alias(\\PhpPkpass\\PKPass => PKPass)';
        } else {
            $post_include['alias_aplicado'] = 'ninguno';
        }
        $post_include['PKPass final existe?'] = class_exists('PKPass', false) ? 'sí' : 'no';
        eventosapp_pkpass_loader_log('Loader: estado tras include/alias', $post_include);

        if ($post_include['PKPass final existe?'] !== 'sí') {
            eventosapp_pkpass_loader_log('Loader: ERROR, clase PKPass no disponible tras carga', [
                'sugerencia' => 'Verifica estructura /includes/libs/pkpass/ y permisos de lectura.'
            ]);
        }

        $done = true;
        eventosapp_pkpass_loader_log('Loader: completado', [
            'done' => $done ? 'true' : 'false'
        ]);
    }
}
