<?php
if (!defined('ABSPATH')) exit;

/**
 * Punto de entrada de Herramientas por evento.
 *
 * El código histórico del asistente/importador se conserva sin cambios en
 * eventosapp-herramientas-core.php. La optimización de rendimiento pertenece
 * a este mismo módulo y se carga después para ajustar únicamente la ejecución
 * masiva enviada a Cola y Tareas.
 */
require_once __DIR__ . '/eventosapp-herramientas-core.php';
require_once __DIR__ . '/eventosapp-herramientas-performance.php';
