# EventosApp 1.5.0-rc.32 — Filtros y log de Flows programados

Fecha: 2026-08-19  
Rama: `agent/whatsapp-flow-schedule-filters-log-rc32`  
Base: `main` en `f47b7a4034563333bd559ee8aae802766371fabd`

## Objetivo

Extender el metabox **WhatsApp Flows — Configuración y Programación** incorporado en rc.31 para que una programación pueda segmentar su audiencia con el mismo motor del envío masivo de Flows y mostrar en el propio evento un log de ejecución ligado a Cola y Tareas.

## Filtros de audiencia

La programación reutiliza `eventosapp_whatsapp_flows_bulk_sanitize_filters()` y `eventosapp_whatsapp_flows_bulk_get_filtered_tickets()`. No existe un segundo motor de segmentación.

Filtros expuestos en el metabox:

- envío previo del mismo Flow;
- recepción del mismo Flow;
- respuesta del mismo Flow;
- check-in del asistente;
- estado general de WhatsApp;
- estado de entrega;
- modalidad;
- localidad;
- día del evento;
- fecha del último WhatsApp, desde/hasta;
- fecha de creación del ticket, desde/hasta;
- campos adicionales configurados en el evento;
- respeto opcional de las reglas de envío configuradas en **WhatsApp — Tickets y Recordatorios**.

### Check-in

El filtro de check-in usa exactamente la lógica del envío masivo de Flows:

- `_eventosapp_checkin_status` para operación presencial;
- `_eventosapp_virtual_checkin_status` para operación virtual;
- si se selecciona un día del evento, solo cuenta el check-in de ese día;
- sin día explícito, evalúa los días válidos configurados para el evento.

Las opciones visibles son:

- incluir solo asistentes con check-in;
- excluir asistentes con check-in, es decir, enviar solo a quienes todavía no tienen check-in.

## Momento de evaluación

Los filtros se guardan con la programación, pero la audiencia concreta se resuelve **cuando comienza la tarea**, no al guardar el evento.

Esto es importante porque entre la configuración y la hora programada pueden ocurrir:

- nuevas inscripciones;
- nuevos check-ins;
- cambios de modalidad o localidad;
- entregas/lecturas de WhatsApp;
- respuestas al Flow;
- cambios en campos adicionales.

Por tanto, el envío usa el estado real de los asistentes a la hora de ejecución.

## Compatibilidad rc.31

Una programación sin filtros conserva el comportamiento de rc.31: toma todos los tickets del evento. No se introduce por defecto `exclude_sent` ni otra exclusión que pudiera cambiar silenciosamente una programación histórica.

Los filtros se guardan dentro de `_eventosapp_whatsapp_flow_schedule` y se copian también al payload de la tarea `whatsapp_flow_scheduled`.

Si los filtros cambian mientras la tarea todavía no ha procesado tickets, el payload se actualiza de forma segura y queda constancia en el log.

Si la tarea ya comenzó a procesar, los filtros activos se congelan para impedir que una misma ejecución combine dos audiencias distintas. El metabox muestra una advertencia y Cola y Tareas registra el intento.

## Cola y Tareas

Se conserva el tipo:

```text
whatsapp_flow_scheduled
```

El adaptador rc.32 sigue reutilizando:

```text
eventosapp_task_queue_process_flow_bulk()
```

Solo se añade una fase previa de preparación de audiencia mediante el mismo filtro masivo. Se conservan batch, deduplicación, reglas opcionales, envío real, estados y protección de recursos existentes.

## Log dentro del metabox

El metabox muestra la tarea asociada con:

- estado actual;
- audiencia total una vez preparada;
- procesados;
- enviados;
- omitidos;
- errores;
- fecha/hora programada;
- inicio;
- finalización;
- resumen de filtros activos;
- último error cuando existe;
- últimas entradas del log central;
- acceso al detalle completo de **Cola y Tareas**.

Se agregan además hitos explícitos:

1. actualización de filtros antes de iniciar;
2. preparación de audiencia y cantidad de tickets seleccionados;
3. advertencia si se intenta cambiar la audiencia durante una ejecución activa;
4. resumen final con procesados, enviados, omitidos y errores.

Las fechas del log se presentan en la zona horaria del evento, mientras la cola continúa almacenando su contrato interno en UTC.

## Archivos

```text
includes/admin/eventosapp-whatsapp-event-flow-schedule-controls.php
includes/admin/eventosapp-whatsapp-event-metaboxes-runtime.php
README.md
docs/whatsapp-flow-schedule-filters-log-rc32.md
```

## Alcance cerrado

rc.32 no modifica:

- constructor o publicación de WhatsApp Flows;
- plantillas Flow;
- Graph API;
- motores de ticket WhatsApp;
- Confirmación de Asistencia;
- Recordatorios;
- QR/PDF/ICS/Wallet;
- Kiosko/Staff/Localidad/Sesiones/Doble Auth/Consumibles;
- API móvil online/offline;
- tablas de Cola y Tareas;
- gobernador global de recursos.

## Validación antes de promover

1. Abrir un evento y confirmar que el metabox rc.31 conserva plantilla, Flow, header y programación.
2. Guardar una programación sin filtros y confirmar que selecciona toda la audiencia del evento.
3. Programar con **Incluir solo asistentes con check-in** y comprobar audiencia presencial/virtual.
4. Repetir con **Excluir asistentes con check-in**.
5. Repetir el check-in filtrando un día específico de un evento con varias fechas.
6. Combinar check-in con modalidad y localidad.
7. Probar filtros de enviado/recibido/respondido del mismo Flow.
8. Probar estado de WhatsApp y delivery.
9. Probar rangos de fechas y campos adicionales.
10. Activar **Respetar reglas** y confirmar omisiones según las reglas de Tickets WhatsApp.
11. Crear una programación, hacer check-in después de guardarla y antes de ejecutarla, y confirmar que la audiencia se calcula con ese estado nuevo.
12. Cambiar filtros antes de que la tarea empiece y confirmar actualización del payload/log.
13. Intentar cambiar filtros con una tarea ya iniciada y confirmar que la audiencia no cambia y queda advertencia.
14. Confirmar en el metabox los contadores de procesados/enviados/omitidos/errores y las entradas del log.
15. Abrir la tarea en Cola y Tareas y comprobar que el log mostrado corresponde a la misma tarea.
16. Ejecutar PHP lint completo y regresión funcional de Flow programado antes de promover a producción.
