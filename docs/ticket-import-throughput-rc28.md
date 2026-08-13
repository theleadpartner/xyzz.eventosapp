# EventosApp 1.5.0-rc.28 — rendimiento de importación masiva de tickets

## Problema observado

En una importación real de 4.000 registros la tarea llegó aproximadamente a 1.593/4.000 después de más de cuatro horas. Eso equivale a unos 9 segundos por ticket y proyecta alrededor de 10 horas para completar el archivo si el ritmo se mantiene.

La captura del incidente mostraba al mismo tiempo aproximadamente 25,8 % de la memoria PHP y carga de 1 minuto de 1,15. Por tanto el síntoma no corresponde a un servidor agotando los 512 MB de PHP ni a la cola deteniéndose constantemente por memoria.

## Causa técnica

La cola central está diseñada de forma conservadora: una tarea tiene un único lock, procesa un lote, cede el turno por tiempo/recursos y vuelve a programarse. `max_concurrent = 3` significa hasta tres tareas independientes simultáneas; no significa tres workers sobre el mismo cursor de una importación.

El importador histórico además ejecutaba por cada fila, de forma secuencial:

1. validación y deduplicación;
2. creación/actualización del ticket y sus metadatos;
3. QR presencial cuando aplica;
4. PDF cuando está activo;
5. ICS cuando está activo;
6. Google Wallet cuando está activo;
7. Apple Wallet cuando está activo;
8. recursos de WhatsApp cuando están activos;
9. reglas de variantes y otros anexos activos;
10. escritura de un log técnico de éxito por ticket.

Los anexos pueden incluir CPU, filesystem y llamadas remotas. Mientras una petición espera ese trabajo, el proceso puede mostrar memoria baja y carga moderada, pero el siguiente registro no comienza. Aumentar `memory_limit` o quitar las guardas de la cola no resuelve ese patrón secuencial.

## Cambio de rc.28

Archivo nuevo:

```text
includes/admin/eventosapp-ticket-import-performance.php
```

El cambio se limita a las importaciones enviadas a **Cola y Tareas**. El modo histórico “en esta ventana” se conserva sin cambios como ruta de compatibilidad.

### Fase 1 — datos del CSV

El task `ticket_import` continúa usando el procesador histórico de cada fila para conservar:

- validación;
- mapeo de columnas;
- fingerprint;
- deduplicación;
- actualización por cédula/evento;
- campos base;
- modalidad;
- campos extra;
- sesiones;
- índice de búsqueda;
- secuencias y metadatos funcionales existentes.

Durante esta fase se pasa temporalmente una configuración de anexos desactivados. De esta forma la fila termina en cuanto los datos funcionales están persistidos y el ticket se marca con `_eventosapp_import_assets_pending`.

La vinculación con Asistentes se ejecuta en esta fase cuando el evento la tiene activa, porque no pertenece al generador de anexos.

### Fase 2 — anexos paralelos

Al terminar el CSV se toman únicamente los tickets creados/actualizados por la fase rápida y se dividen de forma determinista en hasta tres shards.

Cada shard crea una tarea independiente:

```text
ticket_import_assets
```

Cada worker reutiliza `evapp_import_generate_assets_now()` con la configuración real del evento. Por tanto QR/PDF/ICS/Wallet/WhatsApp/variantes siguen usando el mismo código ya probado; solo cambia que ahora tickets distintos pueden generar anexos simultáneamente.

La cantidad efectiva de workers sigue limitada por `eventosapp_task_queue_config()['max_concurrent']` y nunca supera tres dentro de este pipeline. Las guardas centrales de memoria y carga permanecen activas. Si el servidor se acerca a sus umbrales, la cola continúa cediendo turnos como antes.

## Reducción de escritura de logs

El modo rápido ya no genera un `INSERT` de log de éxito por cada ticket. Errores y omisiones conservan detalle individual; los éxitos se registran como resumen de lote.

Esto reduce miles de escrituras auxiliares en importaciones de varios miles de registros sin perder trazabilidad de fallos.

## Recuperación e idempotencia

Si PHP cae después de escribir un ticket pero antes de guardar el checkpoint del CSV, el retry puede reconocer la fila por fingerprint. rc.28 revisa `_eventosapp_import_assets_pending`: un ticket duplicado por recuperación que todavía deba anexos se reincorpora al conjunto de la fase 2.

Los IDs de los shards se guardan inmediatamente después de crear cada task. Si la creación de un shard posterior falla, un nuevo intento no duplica los shards que ya existen.

## Tareas que ya estaban en ejecución

El adaptador de la cola se resuelve en cada petición del worker. Una tarea `ticket_import` ya creada puede continuar desde su cursor existente con el nuevo procesador después de desplegar rc.28.

Los registros que ya habían sido completados con el procesador anterior no se vuelven a generar. Solo las filas todavía pendientes pasan al pipeline diferido/paralelo.

## Qué no se cambia

rc.28 no modifica:

- estructura de tickets;
- reglas de deduplicación;
- QR/PDF/ICS/Wallet/WhatsApp existentes;
- cola de email/WhatsApp/recordatorios;
- umbrales globales de memoria/carga;
- `max_concurrent` global;
- límites de PHP;
- API móvil;
- modo offline/online de Android;
- consumibles;
- check-in;
- sesiones;
- doble autenticación.

## Expectativa de rendimiento

El beneficio principal no proviene de “dar más memoria” sino de eliminar el cuello de botella de una sola fila esperando todos sus anexos y luego usar los slots de concurrencia que ya existían.

Si los anexos representan la mayor parte del tiempo por ticket, tres shards pueden acercar esa parte del trabajo a una mejora de hasta aproximadamente 3x en condiciones ideales. No se considera una garantía: Wallet remoto, almacenamiento, CPU, MySQL, otros tasks y los límites del hosting pueden reducir la ganancia real.

La fase de lectura/creación del CSV debería avanzar mucho más rápido que antes porque deja de esperar anexos por registro.

## Validación recomendada

1. Ejecutar PHP lint del nuevo archivo y del bootstrap.
2. Importar 100 tickets con QR solamente y validar conteos.
3. Repetir con PDF/ICS/Wallet/WhatsApp activos según un evento real.
4. Confirmar que al terminar la fase CSV aparecen hasta tres tareas “Anexos importación”.
5. Confirmar que cada ticket termina sin `_eventosapp_import_assets_pending` cuando sus anexos quedan correctos.
6. Pausar/reanudar un shard y verificar que los demás mantienen su estado.
7. Cancelar una prueba y verificar que la cola respeta el estado administrativo.
8. Probar 4.000 tickets y registrar tiempo de fase CSV, tiempo de anexos, items/s, memoria máxima y load 1.
9. Validar tickets del inicio, mitad y final: datos, QR y anexos.
10. Ejecutar regresión de actualización de tickets existentes por cédula.

## Archivos de rc.28

Nuevos:

```text
includes/admin/eventosapp-ticket-import-performance.php
docs/ticket-import-throughput-rc28.md
```

Modificados:

```text
includes/admin/eventosapp-configuracion.php
.github/workflows/php-lint.yml
README.md
```
