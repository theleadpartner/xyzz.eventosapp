# EventosApp 1.5.0-rc.28 — rendimiento de importación masiva desde Herramientas

## Alcance corregido

La importación manual masiva pertenece al módulo `includes/admin/eventosapp-herramientas.php`. La primera implementación de rc.28 había cargado una capa de rendimiento desde `eventosapp-configuracion.php`; esa integración fue revertida. Configuración vuelve a su responsabilidad anterior y no participa en el importador.

Para reducir riesgo de regresión, el archivo histórico de Herramientas se conserva sin cambios funcionales en `includes/admin/eventosapp-herramientas-core.php`. El punto de entrada `includes/admin/eventosapp-herramientas.php` carga ese núcleo y, después, la capa de rendimiento del mismo módulo.

Versión interna:

```text
EVENTOSAPP_TICKET_IMPORT_PERFORMANCE_VERSION = 2026.08.12.2
```

## Problema observado

En una importación real de 4.000 registros, la tarea llegó aproximadamente a 1.593/4.000 después de más de cuatro horas. La pantalla mostraba al mismo tiempo cerca de 25,8 % de memoria PHP y load 1 de 1,15. El servidor no estaba agotando memoria ni CPU.

El cuello de botella estaba en el camino crítico de cada fila: después de guardar el ticket, el importador esperaba la generación de QR y de todos los anexos activos antes de continuar con la siguiente fila. QR/PDF/ICS/Wallet/WhatsApp pueden implicar CPU, filesystem o servicios remotos; por eso una tarea podía pasar mucho tiempo esperando aun con recursos disponibles.

La cola central permite hasta tres tareas independientes simultáneas. Una sola tarea `ticket_import` no podía utilizar esos tres slots sobre un único cursor del CSV.

## Implementación

### 1. Fase rápida de datos

Solo cuando se usa **Enviar a Cola y Tareas · modo rápido**, el adaptador `ticket_import` del módulo Herramientas procesa primero los datos del CSV sin ejecutar anexos pesados por fila.

Se mantienen:

- validaciones mínimas;
- fingerprint e idempotencia;
- deduplicación por cédula/evento;
- actualización de tickets existentes;
- modalidad;
- extras;
- sesiones y accesos calculados por el creador histórico;
- secuencia del evento;
- search blob;
- vinculación con Asistentes cuando está habilitada.

Los tickets que requieren la segunda fase quedan marcados con:

```text
_eventosapp_import_assets_pending
```

Para tickets existentes, la fase rápida solo actualiza datos. No envía una configuración falsa de anexos al generador y, por tanto, no elimina PDF/Wallet/ICS válidos mientras espera la fase 2.

El modo **Iniciar / Reanudar en esta ventana** conserva el procesamiento histórico sin cambios.

### 2. Fase de anexos en paralelo

Al terminar el CSV, los IDs pendientes se reparten de forma determinista en hasta tres tareas independientes:

```text
ticket_import_assets
```

Cada shard reutiliza `evapp_import_generate_assets_now()` con la configuración real del evento. No se reemplazan los motores existentes de:

- QR;
- PDF;
- ICS;
- Google Wallet;
- Apple Wallet;
- WhatsApp;
- variantes.

La cola central sigue aplicando sus límites de tiempo, memoria y carga. El cambio permite aprovechar la concurrencia disponible sin quitar las protecciones del servidor.

### 3. Progreso compuesto y finalización real

La tarea padre no se marca como finalizada al terminar de leer el CSV. Al comenzar la fase 2, su total operativo incorpora también los tickets pendientes de anexos.

El progreso de los shards se consolida en la tarea padre. El 100 % y la notificación final solo ocurren cuando datos **y** anexos terminaron. Esto evita un falso “completado” mientras todavía se generan QR/PDF/Wallet.

### 4. Controles coherentes

Pausar, cancelar o reanudar desde Herramientas también coordina las tareas hijas de anexos.

Si la fase de datos ya terminó y quedan anexos pendientes, no se permite volver al modo ventana: la continuación debe hacerse desde Cola y Tareas para no crear dos ejecutores sobre el mismo proceso.

Al confirmar de nuevo una importación, cualquier shard anterior se cancela antes de limpiar su estado.

### 5. Menos escritura de logs

Los éxitos de la fase rápida se resumen por lote. Errores y filas omitidas conservan detalle individual. Esto reduce escrituras auxiliares en importaciones de miles de registros.

## Archivos rc.28

Punto de entrada y núcleo preservado:

```text
includes/admin/eventosapp-herramientas.php
includes/admin/eventosapp-herramientas-core.php
```

Capa del mismo módulo:

```text
includes/admin/eventosapp-herramientas-performance.php
includes/admin/eventosapp-herramientas-performance-data.php
includes/admin/eventosapp-herramientas-performance-assets.php
includes/admin/eventosapp-herramientas-performance-queue.php
includes/admin/eventosapp-herramientas-performance-controls.php
```

Documentación/CI:

```text
docs/ticket-import-throughput-rc28.md
.github/workflows/php-lint.yml
README.md
```

`includes/admin/eventosapp-configuracion.php` fue restaurado a su estado previo y `includes/admin/eventosapp-ticket-import-performance.php` fue eliminado.

## Qué no cambia

No se modifica la cola central global ni sus umbrales, tampoco los generadores de anexos, email/WhatsApp masivos, recordatorios, API móvil, modo online/offline, consumibles, check-in, Localidad, Sesiones o Doble Auth.

## Validación requerida

1. Importar 100 tickets desde Herramientas usando Cola y Tareas.
2. Confirmar que la fase de datos avanza sin generar anexos pesados por fila.
3. Confirmar creación de hasta tres tareas `ticket_import_assets`.
4. Verificar que la tarea padre no llega a 100 % antes que los shards.
5. Verificar QR y anexos en tickets del inicio, mitad y final.
6. Repetir con tickets existentes por cédula y comprobar que sus anexos previos no son borrados durante la fase de datos.
7. Probar PDF, ICS, Google Wallet, Apple Wallet y WhatsApp activos.
8. Probar pausa, reanudación y cancelación durante la fase de datos y la fase de anexos.
9. Repetir el mismo CSV y confirmar idempotencia/fingerprint.
10. Ejecutar 4.000 tickets y medir tiempo de datos, tiempo de anexos, items/s, memoria, load y errores.
11. Ejecutar regresión de Cola y Tareas para otros tipos de tarea.
12. Ejecutar regresión de los módulos online/offline preservados de rc.27.

## Criterio de promoción

No promover rc.28 a producción hasta completar una importación real grande y verificar el resultado funcional de tickets y anexos, además de las métricas de servidor. El objetivo es mejorar throughput utilizando capacidad disponible, no eliminar límites de seguridad.
