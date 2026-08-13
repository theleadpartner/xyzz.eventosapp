<?php
if (!defined('ABSPATH')) exit;

/**
 * Capa de rendimiento del importador masivo de Herramientas.
 *
 * Se separa por responsabilidad para mantener el archivo histórico intacto:
 * datos, anexos, ejecución de cola y controles del asistente.
 */
require_once __DIR__ . '/eventosapp-herramientas-performance-data.php';
require_once __DIR__ . '/eventosapp-herramientas-performance-assets.php';
require_once __DIR__ . '/eventosapp-herramientas-performance-queue.php';
require_once __DIR__ . '/eventosapp-herramientas-performance-controls.php';
