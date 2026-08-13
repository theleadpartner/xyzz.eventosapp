<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Configuración de EventosApp.
 *
 * El núcleo histórico se conserva sin cambios en
 * eventosapp-configuracion-core.php. Antes de cargarlo se aplica la política
 * de reinstalación limpia; después se ajusta únicamente la presentación del
 * diagnóstico administrativo y la recuperación específica de su tarea masiva.
 *
 * La aceleración del importador se carga desde este bootstrap liviano para no
 * modificar el archivo histórico de Herramientas. Su registro ocurre en init
 * 60, después de las integraciones de la cola (init 35), y solo sustituye el
 * adaptador `ticket_import` además de registrar `ticket_import_assets`.
 */
require_once __DIR__ . '/eventosapp-configuracion-clean-policy.php';
require_once __DIR__ . '/eventosapp-configuracion-clean-installer.php';
require_once __DIR__ . '/eventosapp-configuracion-core.php';
require_once __DIR__ . '/eventosapp-configuracion-clean-ui.php';
require_once __DIR__ . '/eventosapp-configuracion-queue-recovery.php';
require_once __DIR__ . '/eventosapp-ticket-import-performance.php';
