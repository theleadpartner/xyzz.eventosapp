<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Configuración de EventosApp.
 *
 * El núcleo histórico se conserva sin cambios en
 * eventosapp-configuracion-core.php. Antes de cargarlo se aplica la política
 * de reinstalación limpia; después se ajusta únicamente la presentación del
 * diagnóstico administrativo.
 */
require_once __DIR__ . '/eventosapp-configuracion-clean-policy.php';
require_once __DIR__ . '/eventosapp-configuracion-clean-installer.php';
require_once __DIR__ . '/eventosapp-configuracion-core.php';
require_once __DIR__ . '/eventosapp-configuracion-clean-ui.php';
