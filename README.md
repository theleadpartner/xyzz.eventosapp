# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

> **Historial preservado:** `rc.34` corrige el vínculo automático Flow local → Meta Flow ID en el builder unificado de plantillas Flow; `rc.33` amplía la compatibilidad de Preconfiguraciones de Eventos y corrige el comportamiento del menú sticky al guardar; `rc.32` añade segmentación y log al Flow programado del evento; `rc.31` unifica la configuración WhatsApp por evento y lleva la programación de Flows a Cola y Tareas respetando la zona horaria del evento; `rc.30` endurece el contexto REST de impresión del Kiosko Android; `rc.29` recalibra Cola y Tareas para aprovechar un KVM de 4 vCPU / 16 GB sin retirar protecciones; `rc.28` aceleró la importación masiva de Herramientas; `rc.27` endureció la operación online para 5.000+ asistentes; `rc.26` endureció el modo offline para 3.000–5.000 asistentes; `rc.25` incorporó Consumo de Consumibles; `rc.24` incorporó Localidad, Sesiones y Doble Auth; `rc.23` introdujo el paquete offline único por evento; `rc.22` el offline completo de Kiosko y `rc.21` el offline Staff QR.

## Estado actual: candidato 1.5.0-rc.34

La entrega **1.5.0-rc.34** corrige exclusivamente el vínculo del builder unificado de plantillas de WhatsApp Flow: el campo **Meta Flow ID** deja de conservar un valor anterior y pasa a resolverse automáticamente desde el **Flow local** seleccionado, tanto en la interfaz como al guardar.

Datos de corte:

- **Fecha:** 2026-08-21
- **Rama:** `fix/whatsapp-flow-template-meta-id-rc34`
- **Base:** `main` en `16b1513c4ff2360437d61ffeb1df6dc9a2655fda`
- **Versión binding Flow/plantilla:** `EVENTOSAPP_WHATSAPP_FLOW_TEMPLATE_BINDING_VERSION = 2026.08.21.1`
- **Destino posterior:** producción únicamente después de validar que cambiar Flow local actualiza y persiste el Meta Flow ID canónico y que un POST alterado no puede guardar una asociación inconsistente.

### Vínculo automático Flow local → Meta Flow ID

El builder unificado de `includes/admin/eventosapp-whatsapp-templates.php` ya mostraba ambos campos, pero no reutilizaba la sincronización del editor histórico. rc.34 toma como fuente de verdad el CPT local del Flow mediante `eventosapp_whatsapp_flows_get_all_for_select()` y `eventosapp_whatsapp_flows_get_flow_config()`.

`includes/admin/eventosapp-whatsapp-flows.php` agrega una capa acotada que:

- sincroniza **Meta Flow ID** al cargar y al cambiar **Flow local**;
- deja el campo de Meta en solo lectura para evitar ediciones inconsistentes;
- borra el valor si no hay Flow seleccionado o el Flow todavía no tiene ID de Meta;
- antes del save handler histórico, vuelve a resolver el ID en servidor y reemplaza cualquier valor obsoleto o manipulado recibido por POST.

Esto evita que una plantilla quede asociada visual o persistentemente al Meta Flow ID de otro Flow.

### Alcance cerrado rc.34

No se modifican construcción, publicación o sincronización de Flows, payloads de plantillas, WABA, número emisor, campañas masivas, programación por evento, Cola y Tareas, Tickets, Confirmación, Recordatorios, QR/PDF/ICS/Wallet, Kiosko, Staff, Consumibles ni APIs móviles. Tampoco se crean, ejecutan o modifican GitHub Actions.

Documentación técnica completa:

```text
docs/whatsapp-flow-template-meta-id-rc34.md
```

### Validación rc.34

1. Abrir una plantilla Flow con un Meta Flow ID histórico y escoger un Flow local diferente; comprobar el cambio inmediato del ID.
2. Confirmar que **Meta Flow ID** queda de solo lectura en el builder unificado.
3. Guardar y volver a abrir la plantilla; comprobar que Flow local y Meta Flow ID siguen correspondiendo.
4. Alterar manualmente `meta_flow_id` en el POST y confirmar que el servidor persiste el ID canónico del Flow local.
5. Escoger un Flow sin Meta Flow ID y confirmar que el campo queda vacío, sin conservar el ID anterior.
6. Ejecutar lint PHP y validación de sintaxis del JavaScript inline agregado.
7. Ejecutar regresión del editor histórico de Flow, campañas masivas y programación por evento.

---

## Base inmediata preservada: candidato 1.5.0-rc.33

La entrega **1.5.0-rc.33** corrige dos puntos administrativos del editor de eventos: amplía la cobertura de **Preconfiguraciones de Eventos** para metaboxes incorporados después del snapshot original y mejora el **menú sticky de metaboxes** para guardar/publicar sin depender de localizar el metabox Publicar ni dejar cajas ocultas después de una búsqueda.

Datos de corte:

- **Fecha:** 2026-08-20
- **Rama:** `agent/event-presets-sticky-menu-rc33`
- **Base:** `main` en `fa2127eaed2d30ef13fa6b2198f30fc5eca56f66`
- **Versión menú sticky:** `EventosApp_Admin_Metabox_Sticky_Menu::VERSION = 1.1.0`
- **Versión compatibilidad presets:** `EVENTOSAPP_EVENT_PRESETS_COMPAT_VERSION = 2026.08.20.1`
- **Destino posterior:** producción únicamente después de validar la creación/aplicación de presets y el guardado del evento con metaboxes filtrados.

### Compatibilidad ampliada de Preconfiguraciones

El motor existente de `includes/admin/eventosapp-event-presets.php` conserva su snapshot controlado y sus filtros públicos. rc.33 agrega una capa de compatibilidad no invasiva que incorpora configuraciones reutilizables añadidas posteriormente:

- Sesiones internas y política sin localidad;
- layout de Métricas personalizadas;
- diseño de Landing Virtual, excluyendo deliberadamente su path/slug único;
- opciones recientes de Funciones Extra del Ticket;
- programación/configuración de Confirmación de Asistencia;
- Tickets y Recordatorios WhatsApp;
- Control de Consumibles.

La compatibilidad diferencia configuración reutilizable de estado operativo. No se copian logs, ejecuciones, IDs de tareas ni referencias de runtime. La programación del Flow conserva sus filtros y reglas, pero se aplica desactivada y sin fecha/hora, `queue_task_id`, firmas o timestamps. Confirmación conserva canales, filtros y plantillas pero exige una nueva fecha/hora. Los recordatorios relativos se pueden reutilizar; los de fecha exacta se copian desactivados y sin la fecha absoluta del evento de origen. Consumibles conserva reglas pero no su `updated_at`.

También se sanea la aplicación de presets históricos para impedir que un snapshot anterior reintroduzca referencias runtime en una programación nueva.

### Menú sticky de metaboxes

`includes/admin/eventosapp-admin-metabox-sticky-menu.php` pasa a versión `1.1.0` y agrega:

- botón **Publicar/Actualizar** en el encabezado sticky;
- delegación al botón nativo `#publish` de WordPress, conservando nonces, hooks y comportamiento de guardado existentes;
- limpieza automática del filtro visual antes de cualquier `submit`;
- restauración de metaboxes ocultados únicamente por la búsqueda sticky al cargar o volver a la página;
- `autocomplete="off"` en el buscador para evitar que el navegador restaure filtros antiguos.

La limpieza solo retira la clase visual propia del navegador sticky. No modifica Screen Options ni las preferencias reales de visibilidad de WordPress.

### Alcance cerrado rc.33

No se modifican motores de WhatsApp, Flow, Confirmación, Recordatorios, Consumibles, QR/PDF/ICS/Wallet, Kiosko, Staff, APIs móviles, tablas de Cola y Tareas ni el gobernador de recursos. Tampoco se crean, ejecutan o modifican GitHub Actions.

### Validación rc.33

1. Ejecutar lint PHP sobre el menú sticky y la capa de compatibilidad de presets.
2. Validar sintaxis del JavaScript inline del sticky menu.
3. Crear una preconfiguración desde un evento con Sesiones, Métricas personalizadas, Landing Virtual, Confirmación, Recordatorios y Consumibles configurados.
4. Aplicarla sobre un evento nuevo y comprobar que se restauran ajustes reutilizables sin copiar paths, logs, IDs de tareas ni fechas absolutas.
5. Aplicar un preset histórico que contenga `_eventosapp_whatsapp_flow_schedule` y comprobar que no arrastra `queue_task_id` ni programación anterior.
6. Filtrar un único metabox, presionar **Publicar/Actualizar** desde el sticky y confirmar que, al recargar, vuelven a mostrarse los demás metaboxes permitidos por Screen Options.
7. Repetir el guardado usando el botón nativo de WordPress y confirmar el mismo resultado.

---

## Base inmediata preservada: candidato 1.5.0-rc.32

La entrega **1.5.0-rc.32** amplía exclusivamente el metabox **WhatsApp Flows — Configuración y Programación** de rc.31 con filtros de audiencia equivalentes a los del envío masivo de Flows y un log visible de la tarea programada. El motor de envío, la zona horaria y Cola y Tareas se conservan; la segmentación reutiliza el motor masivo existente.

Datos de corte:

- **Fecha:** 2026-08-19
- **Rama:** `agent/whatsapp-flow-schedule-filters-log-rc32`
- **Base:** `main` en `f47b7a4034563333bd559ee8aae802766371fabd`
- **Versión del hub:** `EVENTOSAPP_WHATSAPP_EVENT_METABOXES_VERSION = 2026.08.19.2`
- **Versión de controles:** `EVENTOSAPP_WHATSAPP_EVENT_FLOW_SCHEDULE_CONTROLS_VERSION = 2026.08.19.1`
- **Destino posterior:** producción únicamente después de validar filtros, check-in, log y ejecución real de la tarea `whatsapp_flow_scheduled`.

### Filtros del Flow programado

El metabox permite segmentar por:

- envío previo, recepción y respuesta del mismo Flow;
- **check-in del asistente**, incluyendo check-in presencial y virtual;
- estado de WhatsApp y estado de entrega;
- modalidad, localidad y día del evento;
- rangos de último WhatsApp y creación del ticket;
- campos adicionales configurados para el evento;
- reglas de envío de **WhatsApp — Tickets y Recordatorios**, de forma opcional.

La programación no mantiene un filtro paralelo. Reutiliza `eventosapp_whatsapp_flows_bulk_sanitize_filters()` y `eventosapp_whatsapp_flows_bulk_get_filtered_tickets()`, las mismas funciones del envío masivo de Flows.

La audiencia se resuelve al empezar la tarea, no cuando se guarda el evento. Así, un check-in, inscripción, entrega, respuesta o cambio de datos ocurrido después de programar pero antes de ejecutar sí afecta correctamente la audiencia.

Una programación sin filtros conserva el comportamiento de rc.31 y toma todos los tickets del evento; no se añade ninguna exclusión automática que cambie programaciones históricas.

Si se cambian los filtros antes de que la tarea procese tickets, el payload se sincroniza y queda registro. Si la tarea ya empezó, los filtros activos se congelan para impedir que una ejecución mezcle dos audiencias distintas y se registra la advertencia.

### Check-in

La opción usa exactamente el criterio del envío masivo de Flow:

```text
_eventosapp_checkin_status
_eventosapp_virtual_checkin_status
```

Permite:

- incluir solo asistentes con check-in;
- excluir asistentes con check-in, es decir, enviar solo a quienes todavía no han hecho check-in.

Cuando se selecciona un día del evento, el check-in se evalúa para ese día. Sin día explícito, se evalúan los días válidos configurados para el evento.

### Log de la programación

El propio metabox muestra:

- estado de la tarea;
- audiencia preparada;
- procesados;
- enviados;
- omitidos;
- errores;
- fecha/hora programada, inicio y finalización en zona horaria del evento;
- filtros activos;
- último error;
- hasta 40 entradas recientes del log central;
- acceso directo al detalle completo en **Cola y Tareas**.

Además se registran hitos explícitos de actualización de filtros, preparación de audiencia, bloqueo de cambios durante ejecución y resumen final.

### Alcance cerrado rc.32

No se modifican el constructor/publicación de Flows, plantillas, Graph API, envío histórico de Tickets WhatsApp, Confirmación de Asistencia, Recordatorios, QR/PDF/ICS/Wallet, Kiosko, Staff, Localidad, Sesiones, Doble Auth, Consumibles, API móvil online/offline, tablas de Cola y Tareas ni el gobernador global de recursos.

Documentación técnica completa:

```text
docs/whatsapp-flow-schedule-filters-log-rc32.md
```

### Validación rc.32

1. Guardar un Flow programado sin filtros y confirmar audiencia completa.
2. Probar **Incluir solo asistentes con check-in** con check-in presencial y virtual.
3. Probar **Excluir asistentes con check-in**.
4. Probar check-in por un día específico en eventos de varias fechas.
5. Combinar check-in con modalidad y localidad.
6. Probar filtros de enviado/recibido/respondido del Flow.
7. Probar estados WhatsApp/delivery, rangos de fecha y campos adicionales.
8. Activar **Respetar reglas** y confirmar omisiones esperadas.
9. Crear un check-in después de guardar la programación y confirmar que se refleja al ejecutarse.
10. Cambiar filtros antes de iniciar y confirmar actualización de payload/log.
11. Intentar cambiarlos después de iniciar y confirmar que la audiencia activa no se altera.
12. Validar contadores, fechas y entradas del log en el metabox contra la misma tarea de Cola y Tareas.
13. Ejecutar PHP lint completo y regresión funcional antes de promover.

---

## Base inmediata preservada: candidato 1.5.0-rc.31

La entrega **1.5.0-rc.31** reorganiza la configuración de WhatsApp a nivel del evento para reducir configuraciones duplicadas y facilitar su validación. La implementación conserva los metadatos, save handlers y motores existentes de Tickets, Flows, Confirmación de Asistencia y Recordatorios; el cambio principal es de experiencia administrativa y de programación trazable.

Datos de corte:

- **Fecha:** 2026-08-19
- **Rama:** `agent/whatsapp-event-metaboxes`
- **Base:** `main` en `5ba65a86926848fea97225fdfa564b4d79e36c9c`
- **Versión interna:** `EVENTOSAPP_WHATSAPP_EVENT_METABOXES_VERSION = 2026.08.19.1`
- **Destino posterior:** producción únicamente después de validar guardado de configuraciones existentes, Media Library, programaciones y ejecución real por Cola y Tareas.

### Metaboxes WhatsApp unificados por evento

La edición del CPT de eventos presenta ahora tres bloques principales:

1. **WhatsApp Flows — Configuración y Programación**: número emisor, plantilla Flow, Flow local, cabezote de imagen, fecha/hora y referencia a la tarea de cola.
2. **WhatsApp — Confirmación de Asistencia**: programación, canales, plantilla de confirmación, filtros existentes, diagnóstico y cabezote de WhatsApp.
3. **WhatsApp — Tickets y Recordatorios**: plantillas por modalidad, cabezotes de ticket/QR, virtual y landing, reglas de envío y recordatorios programados.

Los metaboxes históricos `Diseño WhatsApp y Landing` y `WhatsApp Tickets - Reglas de Envío` dejan de registrarse como cajas independientes. También se integran en los nuevos bloques las cajas separadas de Recordatorios y Programación de Confirmación. Sus funciones y claves de datos no se eliminan: se reutilizan para conservar compatibilidad con eventos ya configurados.

### Media Library y mapeo de Flow

Los nuevos campos de imagen abren la librería multimedia mediante `wp.media` restringida a imágenes. Se conservan las mismas URLs/metakeys utilizadas por los motores actuales.

Al seleccionar una plantilla Flow, la interfaz vuelve a sincronizar el Flow local asociado y propone el header definido en la plantilla cuando el evento todavía no tiene una imagen personalizada. La validación final continúa en servidor.

### Programaciones en Cola y Tareas

No se agregó un scheduler paralelo.

- Los **Flows programados** usan el nuevo tipo de tarea `whatsapp_flow_scheduled` y reutilizan el procesador existente `eventosapp_task_queue_process_flow_bulk()`.
- La **Confirmación de Asistencia** conserva `attendance_scheduled`.
- Los **Recordatorios WhatsApp** conservan `whatsapp_reminder_scheduled`.

La audiencia de un Flow programado se calcula cuando comienza la tarea, de modo que también incluye tickets registrados después de haber creado la programación.

### Zona horaria del evento

La fecha/hora escrita por el administrador se interpreta con la zona horaria canónica del evento. Solo el instante utilizado por la cola se convierte a UTC; el payload conserva `event_timezone`, `scheduled_local`, `scheduled_utc` y `planned_timestamp` para auditoría.

Si la zona horaria del evento cambia mientras existe un Flow pendiente, rc.31 fuerza la cancelación y recreación de esa tarea con el UTC correcto, evitando que se conserve una hora calculada con la zona anterior.

### Alcance cerrado rc.31

No se reemplazan ni modifican los motores de WhatsApp Cloud API, plantillas, construcción/publicación de Flows, confirmación masiva/inmediata, envío histórico de tickets, QR/PDF/ICS/Wallet, Kiosko, Staff, Localidad, Sesiones, Doble Auth, Consumibles, API móvil online/offline ni el gobernador global de Cola y Tareas.

Documentación técnica completa:

```text
docs/whatsapp-event-metaboxes-rc31.md
```

### Validación rc.31

1. Abrir un evento ya configurado y confirmar que las selecciones históricas aparecen en los nuevos metaboxes.
2. Guardar sin cambios y verificar que no se pierden plantillas, headers, reglas ni recordatorios.
3. Seleccionar imágenes desde Media Library en Ticket/QR, virtual, landing, Flow y Confirmación.
4. Programar un Flow futuro y comprobar tarea `whatsapp_flow_scheduled` en Cola y Tareas.
5. Crear un ticket después de programar el Flow y comprobar que entra en la audiencia al ejecutarse.
6. Cambiar la zona horaria con una tarea Flow pendiente y comprobar cancelación/recreación con UTC correcto.
7. Programar Confirmación y comprobar `attendance_scheduled`.
8. Programar Recordatorio WhatsApp y comprobar `whatsapp_reminder_scheduled`.
9. Probar reglas allow/deny de Tickets.
10. Ejecutar envíos reales de prueba de Ticket, Flow, Confirmación y Recordatorio.
11. Confirmar que no aparecen duplicados los metaboxes históricos absorbidos.
12. Ejecutar PHP lint completo y regresión funcional antes de promover.

---

## Promoción a producción completada — 2026-08-12

El candidato `1.5.0-rc.28` de este repositorio ya fue promovido de forma controlada a `theleadpartner/EventosApp`.

- **SHA fuente promovido:** `9d1f5e329cbeb6b8680c762104f1568db57738e2`.
- **PR de producción:** `theleadpartner/EventosApp#8`.
- **Commit integrado en producción:** `608352f4a567c15e34ae5ab9f370b15ef1a22d4c`.
- **Versión documentada en producción:** `1.5.0-rc.28`.
- **Último corte productivo anterior:** `8a7e1717351790ae2117120a8b4157e8895df46f` (`1.4.0-rc.1`).
- **Delta auditado:** 208 commits y 77 rutas acumuladas.
- **Transferencia directa:** 76 rutas sincronizadas byte a byte desde este SHA; el README de producción se preservó y documentó por separado.
- **Validación:** lint PHP completo, paridad byte a byte, control cerrado de alcance y `PHP Lint` del PR productivo en estado exitoso.
- **Cambios nuevos pendientes después de este corte:** `rc.29`, `rc.30`, `rc.31`, `rc.32`, `rc.33` y `rc.34` permanecen en el entorno de pruebas hasta completar sus validaciones específicas.
- **Nueva base obligatoria para próximas promociones:** `9d1f5e329cbeb6b8680c762104f1568db57738e2`.

El registro de cierre de este entorno queda en [`docs/production-promotion-1.5.0-rc.28.md`](docs/production-promotion-1.5.0-rc.28.md). En producción, el manifiesto exhaustivo quedó documentado en `docs/production-sync-1.5.0-rc.28.md`.

## Base inmediata preservada: candidato 1.5.0-rc.30

La entrega **1.5.0-rc.30** es un hotfix acotado al contrato REST de impresión del Kiosko Android sobre la base funcional `rc.29`. El diagnóstico de Android 2.13.0 confirmó que el mismo ticket podía ser localizado correctamente por Kiosko, Localidad y Check-in QR, mientras `POST /wp-json/eventosapp-kiosk/v1/tickets/{ticket_id}/print` devolvía `404 invalid_ticket` antes de generar la escarapela o crear un trabajo de impresión local.

Datos de corte:

- **Fecha:** 2026-08-13
- **Rama:** `fix/kiosk-print-ticket-context-rc30`
- **Base funcional:** `1.5.0-rc.29`
- **Android recomendado:** `2.13.1` / `versionCode 39`
- **Destino posterior:** producción únicamente después de validar impresión real y conservar además las pruebas de carga pendientes de rc.29.

### Diagnóstico de impresión Kiosko

El caso de campo aisló el fallo al endpoint de impresión:

```text
QR Kiosko -> búsqueda correcta -> ticket canónico válido
                           |
                           v
POST /tickets/{ticket_id}/print -> 404 invalid_ticket
```

La misma lectura fue aceptada por Staff QR y Localidad, y el dispositivo mantenía Bluetooth operativo y trabajos de impresión anteriores en estado `SENT`. El error ocurre antes de la generación de badge/escarapela y antes de encolar un trabajo local, por lo que rc.30 no modifica QR ni el motor Bluetooth.

### Normalización del contexto REST

La API histórica obtiene el ticket de impresión mediante `$request['id']`. El archivo `includes/api/eventosapp-mobile-kiosk-feature-permission.php` ya extraía el ticket directamente desde `get_route()` para resolver el permiso por evento. rc.30 reutiliza esa fuente inequívoca únicamente para `/tickets/{ticket_id}/print`.

El nuevo filtro `rest_pre_dispatch`:

1. obtiene el ticket directamente del patrón de ruta;
2. valida `ticket_id` redundante cuando Android 2.13.1 lo envía;
3. valida `event_id` contra la relación real ticket → evento;
4. normaliza `id` con `WP_REST_Request::set_param()` antes del callback histórico;
5. devuelve `409` explícito si ruta, ticket y evento no coinciden.

Android 2.13.1 mantiene el ticket en la ruta y envía además:

```json
{
  "request_id": "android-kiosk-...",
  "id": 21054,
  "ticket_id": 21054,
  "event_id": 15200
}
```

La ruta continúa siendo la fuente canónica; el cuerpo redundante sirve para detectar pérdida o inconsistencia de contexto antes de imprimir.

### Alcance cerrado rc.30

No se reemplaza ni duplica `eventosapp_kiosk_api_print()`. Permanecen sin cambios:

- permisos por evento y módulo;
- validación de ticket virtual;
- modalidad, fecha y control de pago;
- lock ticket+día;
- escritura del check-in y log;
- generación y firma del badge;
- configuración de papel;
- impresión Bluetooth y cola local Android;
- QR Staff, Localidad, Sesiones, Doble Auth y Consumibles;
- modo offline y sincronización;
- gobernador de Cola y Tareas rc.29.

Documentación técnica completa:

```text
docs/kiosk-print-context-rc30.md
```

### Validación rc.30

1. Kiosko QR con autoimpresión: localizar e imprimir un ticket válido.
2. Kiosko QR con confirmación manual: **Confirmar e imprimir escarapela**.
3. Repetir con ticket que ya tenga check-in del día.
4. Buscar por identificación/nombre y posteriormente imprimir.
5. Confirmar que **Volver** no reactive una impresión ya consumida.
6. Enviar intencionalmente un `ticket_id` de body distinto al de la ruta y confirmar rechazo sin impresión.
7. Enviar un `event_id` incorrecto y confirmar rechazo sin impresión.
8. Ejecutar regresión Staff QR, Localidad, Sesiones, Doble Auth, Consumibles y offline.
9. Ejecutar PHP lint completo.
10. Mantener además la validación de throughput real requerida por rc.29.

---

## Base inmediata preservada: candidato 1.5.0-rc.29

La entrega **1.5.0-rc.29** corrige el exceso de sensibilidad del gobernador de recursos de **Cola y Tareas** observado después de trasladar procesos masivos al segundo plano. El objetivo no es retirar las protecciones, sino aprovechar de forma sostenida la capacidad disponible del servidor de pruebas: **KVM 4 vCPU / 16 GB RAM**.

Datos de corte:

- **Fecha:** 2026-08-13
- **Rama:** `perf/task-queue-resource-governor-rc29`
- **Base:** `main` en `4799973518184d678cd3996b34b96c49986e0435`
- **Versión interna del perfil:** `EVENTOSAPP_TASK_QUEUE_PERFORMANCE_PROFILE_VERSION = 2026.08.13.1`
- **Destino posterior:** producción únicamente después de validar throughput real y estabilidad.

### Diagnóstico del gobernador

La cola central partía de `max_concurrent = 3`, `max_execution_seconds = 22`, `memory_stop_ratio = 0.72`, `load_stop_per_core = 1.30`, `normal_delay_seconds = 4` y `busy_delay_seconds = 15`.

El problema no era una sola protección, sino su efecto combinado:

1. `memory_stop_ratio` se calcula contra el `memory_limit` del proceso PHP, no contra los 16 GB físicos del VPS;
2. con 4 vCPU, `load_stop_per_core = 1.30` marcaba ocupado el servidor cerca de `load 1 = 5.2`;
3. el batch adaptativo histórico reduce lotes cuando memoria/load/tiempo suben y persiste el tamaño reducido en la tarea, por lo que procesos con APIs remotas podían degradarse progresivamente hasta batches mínimos;
4. cada batch pequeño volvía a pagar esperas normales de 4 s o de 15 s si el worker consideraba el servidor ocupado;
5. rc.28 crea hasta tres shards de anexos, pero con tres workers la tarea padre podía ocupar un cupo y dejar temporalmente solo dos shards trabajando;
6. si un dispatcher cedía por un pico puntual, el siguiente intento podía terminar dependiendo del cron de respaldo.

### Perfil efectivo rc.29

El ajuste vive en `includes/admin/eventosapp-herramientas-performance-controls.php` y usa las APIs públicas de Cola y Tareas, sin reemplazar el núcleo histórico.

```text
max_concurrent          = min(4, vCPU detectadas)
dispatcher_limit        >= 16
max_execution_seconds   = 35, limitado a max_execution_time PHP - 5 s
memory_stop_ratio       = 0.86
load_stop_per_core      = 2.00
min_delay_seconds       = 1
normal_delay_seconds    = 1
busy_delay_seconds      = 5
max_batch_size          >= 100
```

En 4 vCPU, el corte de `load 1` pasa aproximadamente de `5.2` a `8.0`. El worker sigue cediendo por tiempo, memoria o carga real; locks, heartbeat, errores consecutivos, pausa, reanudación y cancelación permanecen activos.

### Batches mínimos para procesos afectados

| Tarea | Batch objetivo | Piso | Máximo |
|---|---:|---:|---:|
| `ticket_import` | 60 | 30 | 80 |
| `ticket_import_assets` | 10 | 4 | 16 |
| `whatsapp_bulk` | 12 | 6 | 20 |
| `whatsapp_flow_bulk` | 10 | 5 | 16 |
| `attendance_bulk` | 12 | 4 | 24 |
| `attendance_scheduled` | 10 | 4 | 20 |

Los valores no crean ese número de requests simultáneos: siguen procesándose secuencialmente dentro de cada worker. El objetivo es impedir que una llamada remota lenta degrade el batch hasta 1 item y multiplique las pausas de reencolado.

### Reintento corto del dispatcher

Si el dispatcher encuentra recursos altos pero todavía existen tareas vencidas/listas, rc.29 programa un nuevo `kick` corto usando el `busy_delay_seconds` recalibrado. Así un pico puntual no deja el proceso esperando únicamente el cron periódico.

### Alcance cerrado

rc.29 **no modifica** los motores que envían WhatsApp, WhatsApp Flows o Confirmación de Asistencia; tampoco cambia QR, PDF, ICS, Wallet, deduplicación, API móvil online/offline, Consumibles, Staff QR, Localidad, Sesiones o Doble Auth. Se modifica únicamente el perfil de recursos, los adaptadores de batch de los procesos afectados y el reintento del dispatcher.

Documentación técnica completa:

```text
docs/task-queue-throughput-rc29.md
```

### Validación rc.29

1. Importar 100 tickets y revisar regresiones.
2. Importar 1.000 tickets y medir items/s de datos y anexos.
3. Ejecutar la prueba real de 4.000 tickets y medir duración completa.
4. Confirmar que la tarea padre y los tres shards de anexos pueden coexistir sin serializar innecesariamente el pipeline.
5. Probar WhatsApp Ticket masivo con al menos 100 destinatarios.
6. Probar WhatsApp Flow masivo con al menos 100 destinatarios.
7. Probar Confirmación de Asistencia masiva.
8. Confirmar que los batches no se degradan progresivamente hasta 1 item.
9. Revisar `resource_metrics.last_batch`, logs de cesión, CPU, RAM, PHP-FPM y MySQL.
10. Probar pausa, reanudación, cancelación e idempotencia.
11. Forzar carga concurrente y confirmar que la protección sigue cediendo cuando existe presión real.
12. Ejecutar PHP lint completo antes de promover.

---

## Base técnica inmediata: 1.5.0-rc.28

La entrega **1.5.0-rc.28** optimiza específicamente la importación manual masiva gestionada por **Herramientas** (`includes/admin/eventosapp-herramientas.php`). La implementación se corrigió para que esta responsabilidad no dependa de `eventosapp-configuracion.php`.

Datos de corte:

- **Fecha:** 2026-08-12
- **Rama:** `fix/ticket-import-herramientas-rc28`
- **Base:** `main` con `1.5.0-rc.27` y corrección de la primera integración rc.28
- **Destino posterior:** `theleadpartner/EventosApp` únicamente después de validación real.

## Corrección de alcance de rc.28

La importación masiva pertenece a `includes/admin/eventosapp-herramientas.php`. Por ello:

- `includes/admin/eventosapp-configuracion.php` fue restaurado a su contenido previo;
- se eliminó la capa externa `includes/admin/eventosapp-ticket-import-performance.php`;
- el archivo histórico de Herramientas se conserva sin cambios funcionales en `includes/admin/eventosapp-herramientas-core.php`;
- `includes/admin/eventosapp-herramientas.php` continúa siendo el punto de entrada y carga el núcleo histórico más la optimización específica del mismo módulo.

La separación del núcleo evita mezclar una optimización grande con 170 KB de lógica ya probada y permite revisar el cambio sin alterar funciones históricas fuera del alcance.

## Diagnóstico de la importación masiva

En el incidente analizado, una importación de 4.000 tickets llegó aproximadamente a 1.593 registros después de más de cuatro horas mientras la pantalla mostraba cerca de 25,8 % de memoria PHP y load 1 de 1,15.

El cuello de botella principal no era RAM agotada. El flujo histórico procesaba secuencialmente los datos del ticket y después esperaba QR/PDF/ICS/Wallet/WhatsApp/variantes antes de pasar a la siguiente fila. Parte de ese trabajo usa CPU, filesystem o servicios remotos, así que PHP podía permanecer esperando aun cuando el servidor tenía capacidad disponible.

Además, `max_concurrent = 3` de Cola y Tareas representa hasta tres tareas independientes; una sola tarea `ticket_import` no podía aprovechar los tres slots sobre el mismo cursor del CSV.

## Pipeline rápido dentro de Herramientas

Versión interna:

```text
EVENTOSAPP_TICKET_IMPORT_PERFORMANCE_VERSION = 2026.08.12.2
```

La ruta rápida se activa al usar **Enviar a Cola y Tareas · modo rápido**. El modo **Iniciar / Reanudar en esta ventana** conserva el procesamiento histórico.

### Fase 1 — datos

El adaptador `ticket_import` guarda primero los datos funcionales y difiere los anexos pesados.

Se preservan validación, fingerprint, deduplicación, actualización por cédula/evento, modalidad, extras, secuencia, sesiones/accesos, search blob y vinculación con Asistentes cuando está habilitada.

Cada ticket que requiere segunda fase queda marcado con:

```text
_eventosapp_import_assets_pending
```

Para tickets existentes no se llama al generador con flags artificialmente desactivados. Así no se borran PDF, Wallet, ICS u otros anexos válidos mientras la tarea espera la fase 2.

### Fase 2 — anexos paralelos

Al terminar la lectura del CSV, los tickets pendientes se distribuyen de forma determinista en hasta tres tareas:

```text
ticket_import_assets
```

Cada tarea reutiliza `evapp_import_generate_assets_now()` y la configuración real del evento. No se reemplazan los motores existentes de QR, PDF, ICS, Google Wallet, Apple Wallet, WhatsApp o variantes.

La cola central conserva sus límites de tiempo, memoria y carga; rc.28 aprovecha la concurrencia disponible sin retirar las protecciones del servidor.

### Progreso compuesto

La tarea padre no finaliza al terminar el CSV. Su total operativo incorpora la fase de anexos y consolida el avance de las tareas hijas.

Por tanto:

```text
100 % = datos terminados + anexos terminados
```

Esto evita notificaciones de finalización mientras todavía se están construyendo QR/PDF/Wallet.

### Pausa, reanudación y cancelación

Los controles de Herramientas coordinan también las tareas de anexos.

Si la fase de datos ya finalizó y quedan anexos pendientes, la importación debe continuar en Cola y Tareas; no se permite volver al modo ventana para evitar dos ejecutores sobre el mismo proceso.

### Menos escritura auxiliar

Los éxitos de la fase rápida se consolidan por lote. Errores y omisiones mantienen detalle individual. Esto reduce miles de escrituras de log en archivos grandes.

Documentación técnica:

```text
docs/ticket-import-throughput-rc28.md
```

## Archivos de rc.28

Punto de entrada y núcleo preservado:

```text
includes/admin/eventosapp-herramientas.php
includes/admin/eventosapp-herramientas-core.php
```

Optimización del mismo módulo:

```text
includes/admin/eventosapp-herramientas-performance.php
includes/admin/eventosapp-herramientas-performance-data.php
includes/admin/eventosapp-herramientas-performance-assets.php
includes/admin/eventosapp-herramientas-performance-queue.php
includes/admin/eventosapp-herramientas-performance-controls.php
```

Documentación y CI:

```text
docs/ticket-import-throughput-rc28.md
.github/workflows/php-lint.yml
README.md
```

Restaurado/eliminado respecto de la primera integración rc.28:

```text
includes/admin/eventosapp-configuracion.php             // restaurado
includes/admin/eventosapp-ticket-import-performance.php // eliminado
```

## Qué no cambia en rc.28

No se modifica:

- la cola central global ni sus umbrales;
- los motores de QR/PDF/ICS/Wallet/WhatsApp;
- email masivo, WhatsApp masivo o recordatorios;
- la API móvil;
- el modo offline/online;
- consumibles;
- Staff QR;
- Localidad;
- Sesiones;
- Doble Auth.

## Checklist de validación rc.28

1. Importar 100 tickets usando Cola y Tareas.
2. Confirmar avance rápido de la fase de datos.
3. Confirmar creación de hasta tres tasks `ticket_import_assets`.
4. Verificar que la tarea padre no llegue a 100 % antes de que terminen los shards.
5. Verificar QR y anexos en tickets del inicio, mitad y final.
6. Repetir con actualización por cédula y comprobar que los anexos existentes no se borran durante fase 1.
7. Probar PDF, ICS, Google Wallet, Apple Wallet y WhatsApp activos.
8. Probar pausa, reanudación y cancelación en ambas fases.
9. Repetir el mismo CSV y comprobar idempotencia.
10. Ejecutar 4.000 tickets y medir fase de datos, fase de anexos, items/s, memoria, load y errores.
11. Ejecutar regresión de Cola y Tareas para otros tipos de tarea.
12. Ejecutar regresión online/offline preservada de rc.27.

---

# Base técnica preservada: 1.5.0-rc.27

rc.27 complementó el hardening offline de rc.26 con un hardening específico de la **operación online para eventos de 5.000+ asistentes y múltiples dispositivos simultáneos**.

## Resultado de la auditoría online

La APP Android no carga 5.000 tickets en memoria para cada lectura online. Cada cámara envía el valor leído y el backend resuelve el ticket correspondiente.

Los cuellos de botella encontrados estaban en el servidor:

1. el resolvedor QR histórico terminaba consultando `wp_postmeta` por cada lectura única;
2. varias tablets con QRs distintos podían multiplicar esas consultas simultáneamente;
3. Staff QR online hacía read/modify/write sobre metadatos serializados de check-in sin lock por ticket/día;
4. Kiosko/impresión y algunos flujos QR avanzados podían coincidir en el mismo patrón de escritura;
5. la búsqueda textual histórica de Kiosko podía llegar a cargar miles de tickets y metadatos completos en PHP para una sola consulta;
6. varias tablets no debían reconstruir independientemente el mismo índice de 5.000 asistentes;
7. el warm-up no debía convertir 500 tickets en miles de `DELETE/REPLACE` individuales contra MySQL.

rc.27 corrigió estos puntos manteniendo los contratos funcionales de los módulos.

## Índice QR dedicado para operación online

Archivo principal:

```text
includes/api/eventosapp-mobile-online-performance.php
```

Versión interna:

```text
EVENTOSAPP_MOBILE_ONLINE_PERFORMANCE_VERSION = 1.0.0
EVENTOSAPP_MOBILE_ONLINE_LOOKUP_DB_VERSION = 1.0.0
```

La API crea mediante `dbDelta()` una tabla dedicada:

```text
{prefix}eventosapp_qr_lookup
```

Campos principales:

```text
event_id
lookup_hash      // SHA-256
ticket_id
qr_type
qr_type_label
updated_at
```

Índices:

```text
PRIMARY KEY (event_id, lookup_hash)
INDEX (event_id, ticket_id)
INDEX (ticket_id)
```

El QR **no se almacena en texto claro**. Las claves provienen del mismo generador de lookup utilizado por el paquete offline, por lo que se conservan QR legacy, preimpresos, badges y canales soportados actualmente.

### Lookup online

`eventosapp_qr_find_ticket_by_scanned_code()` conserva su contrato histórico pero aplica este orden:

```text
1. object cache de corto plazo
2. tabla QR dedicada por event_id + SHA-256
3. resolvedor QR histórico
4. consulta postmeta histórica como fallback final
```

Si un ticket es encontrado por el fallback, se autoindexa inmediatamente. Por tanto el índice puede calentarse progresivamente aun si el warm-up todavía no terminó.

Una fila indexada siempre se vuelve a validar contra:

- tipo de post;
- estado activo del ticket;
- evento actual del ticket.

Si una fila queda obsoleta, se elimina del índice y el resolvedor continúa con fallback.

## Mantenimiento automático del índice

Los tickets se reindexan cuando cambian metadatos que afectan QR, incluyendo:

```text
_eventosapp_ticket_evento_id
eventosapp_ticketID
eventosapp_ticket_preprintedID
_eventosapp_badge_security_code
_eventosapp_qr_email
_eventosapp_qr_google_wallet
_eventosapp_qr_apple_wallet
_eventosapp_qr_pdf
_eventosapp_qr_whatsapp
_eventosapp_qr_badge
```

También se eliminan sus filas al enviar el ticket a papelera o borrarlo.

Si cambia `_eventosapp_ticket_use_preprinted_qr` a nivel del evento, se invalida el índice del evento para reconstruirlo con la estrategia QR correcta.

## Warm-up online compartido

Ruta:

```text
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/online-index/warm
```

Android 2.11.2 la ejecuta silenciosamente al seleccionar el evento.

El proceso trabaja en lotes de hasta **500 tickets** y no es requisito para usar la APP: la cámara puede empezar a operar antes de que termine.

### Coordinación entre tablets

Archivo:

```text
includes/api/eventosapp-mobile-online-warm-coordinator.php
```

Todas las tablets del mismo evento comparten:

- cursor de avance;
- estado `complete`;
- versión de esquema;
- lock corto por lote.

Esto evita el escenario donde diez tablets recién encendidas intenten construir diez veces el índice completo de 5.000 asistentes.

Si una tablet encuentra el lote ocupado, recibe `busy=true`; Android espera brevemente y continúa. Otra tablet puede haber avanzado el cursor durante ese intervalo.

Una vez completo, los dispositivos siguientes reciben `complete=true` sin reconstruir el evento.

### Escritura masiva del índice

Archivo:

```text
includes/api/eventosapp-mobile-online-batch-index.php
```

El warm-up no llama `DELETE + REPLACE` individual para cada clave de cada ticket. Por lote:

1. elimina de una sola vez las filas de los tickets del lote;
2. genera las claves SHA-256 en PHP usando el mismo resolvedor offline;
3. agrupa las filas resultantes;
4. ejecuta `INSERT ... ON DUPLICATE KEY UPDATE` en bloques de hasta 300 claves.

La indexación individual se conserva únicamente para cambios incrementales posteriores de un ticket. Esto reduce de forma importante la cantidad de round-trips SQL durante la preparación inicial de un evento grande.

## Concurrencia de escrituras online

No se introduce un lock global del evento. Asistentes distintos pueden procesarse en paralelo.

Los locks se aplican únicamente a la unidad lógica que puede entrar en carrera:

```text
Staff QR        → evento + ticket + día
Kiosko/Print    → evento + ticket + día
Doble Auth      → evento + ticket + día
Sesiones        → evento + ticket + sesión
```

Para check-in general, rc.27 reutiliza cuando está disponible el mismo lock empleado por la sincronización offline. Esto coordina:

```text
operación online
vs.
otra tablet online
vs.
sync de una operación creada offline
```

sin serializar el resto del evento.

### Staff QR

El endpoint histórico se conserva:

```text
POST /wp-json/eventosapp-kiosk/v1/staff/events/{event_id}/checkin
```

La lectura usa el índice QR dedicado. La escritura queda protegida por ticket+día antes de modificar `_eventosapp_checkin_status` y `_eventosapp_checkin_log`.

### Kiosko / impresión

La lectura QR de Kiosko usa el mismo índice. El endpoint:

```text
POST /wp-json/eventosapp-kiosk/v1/tickets/{ticket_id}/print
```

queda protegido por ticket+día durante el check-in que precede a la generación de la escarapela. Desde rc.30, el ID capturado por la ruta se normaliza explícitamente antes del callback histórico y se contrasta con el contexto redundante de Android 2.13.1.

### Sesiones

La escritura:

```text
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/qr-session/checkin
```

usa lock por ticket+sesión. Un mismo ticket no puede corromper el estado de una sesión por dos escrituras simultáneas, pero otras sesiones y asistentes continúan en paralelo.

### Doble Autenticación

La aprobación final utiliza lock por ticket+día antes del check-in general. Se preservan control de pago, código de cinco dígitos, límites de intentos y demás validaciones existentes.

### Consumibles

No se reemplaza el motor transaccional existente. Consumibles ya utiliza tablas propias, request UUID, transacciones y bloqueos de balance.

rc.27 únicamente acelera su resolución QR porque `eventosapp_consumables_find_ticket_from_qr()` reutiliza el resolvedor global optimizado.

## Búsqueda textual de Kiosko Android

La búsqueda histórica de autogestión sigue disponible para la web y compatibilidad.

Para la ruta Android:

```text
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/search
```

cuando la consulta es textual, rc.27 utiliza `eventosapp_mobile_online_find_tickets_by_auth_fields()`.

La estrategia:

1. consulta por SQL únicamente los metadatos de campos habilitados;
2. limita el conjunto de candidatos;
3. hace `update_meta_cache()` únicamente de esos candidatos;
4. aplica el validador exacto histórico a los candidatos;
5. devuelve máximo 20 resultados al Kiosko.

Esto evita el fallback que podía cargar 6.000/10.000 tickets completos y todos sus metadatos en memoria PHP por búsqueda.

Las consultas QR de Kiosko no pasan por esta búsqueda textual; usan directamente el índice QR dedicado.

## Seguridad

El hardening conserva las comprobaciones existentes de token y permisos por evento.

La ruta de warm-up exige que el usuario autenticado tenga al menos un módulo móvil efectivo en el evento.

Los nuevos índices no almacenan:

- contraseñas;
- códigos de Doble Auth;
- QR en texto claro.

El índice QR almacena solo SHA-256, ticket, evento y metadatos de tipo de QR.

## Offline rc.26 preservado

rc.30 mantiene íntegro el hardening offline anterior:

- paquete único por evento;
- snapshot estable de IDs;
- páginas de 100 tickets;
- configuración estática solo en primera página optimizada;
- cache acotado de assets repetidos de Kiosko;
- máximo 500 operaciones por request de sincronización;
- compatibilidad con Android anteriores.

Ruta principal:

```text
GET /wp-json/eventosapp-kiosk/v1/events/{event_id}/offline-package
```

Android continúa enviando sincronizaciones en lotes de 200.

## Capacidad de 5.000 asistentes

Con rc.27+ y Android 2.11.2+, un evento de 5.000 asistentes deja de depender de una búsqueda general sobre `postmeta` en cada lectura QR una vez el índice está disponible.

La arquitectura queda preparada para que varias tablets procesen asistentes distintos simultáneamente. El número máximo de dispositivos/requests por segundo sigue siendo una propiedad conjunta del código y la infraestructura:

- número de workers PHP-FPM;
- CPU/RAM;
- MySQL/MariaDB;
- object cache;
- almacenamiento;
- TLS/red;
- otros procesos que compartan el servidor.

Por ello no se fija en código un número artificial de tablets simultáneas. La prueba real debe medir p50/p95/p99 y saturación del servidor.

## Checklist de validación online rc.27

1. Crear/usar evento de 5.000 tickets.
2. Confirmar creación de `{prefix}eventosapp_qr_lookup`.
3. Seleccionar el evento desde Android 2.11.2 con internet.
4. Confirmar warm-up por lotes sin bloquear la APP.
5. Confirmar que el lote usa bulk indexing y no miles de `REPLACE` individuales.
6. Seleccionar simultáneamente el evento desde varias tablets y verificar cursor compartido.
7. Confirmar que, una vez completo, otra tablet obtiene `complete=true` sin reconstruir el evento.
8. Leer QRs de tickets del inicio, mitad y final del dataset.
9. Medir latencia antes, durante y después del warm-up.
10. Probar varias tablets leyendo tickets distintos al mismo tiempo.
11. Probar dos tablets leyendo el mismo ticket Staff casi simultáneamente.
12. Repetir con Kiosko/impresión.
13. Repetir con Sesiones.
14. Repetir con Doble Auth.
15. Probar Consumibles concurrente sobre saldos compartidos.
16. Buscar en Kiosko por identificación, teléfono, nombre y apellido.
17. Agregar/modificar ticket y comprobar autoindexación.
18. Mover un ticket entre eventos y comprobar eliminación/reindexación de sus claves.
19. Cambiar modo preimpreso y comprobar invalidación/reconstrucción del índice.
20. Forzar fallo del warm-up y verificar que el fallback QR sigue operativo.
21. Ejecutar regresión completa online/offline.
22. Medir CPU, RAM, PHP-FPM, queries/conexiones MySQL y errores HTTP bajo concurrencia.
23. Registrar p50, p95 y p99 de los endpoints operativos.

## Historial reciente

| Candidato | Cambio principal |
|---|---|
| **1.5.0-rc.34** | Plantillas WhatsApp Flow: Meta Flow ID automático y canónico según Flow local, con protección en interfaz y servidor. |
| **1.5.0-rc.33** | Preconfiguraciones: compatibilidad con metaboxes recientes y saneamiento de estado operativo; menú sticky con Publicar/Actualizar y restauración de filtros al guardar. |
| **1.5.0-rc.32** | Flow programado por evento: filtros equivalentes al envío masivo, check-in presencial/virtual y log enlazado a Cola y Tareas. |
| **1.5.0-rc.31** | Configuración WhatsApp unificada por evento, Media Library, Flow programado en Cola y Tareas y resync seguro por zona horaria. |
| **1.5.0-rc.30** | Hotfix de impresión Kiosko Android: normalización inequívoca del ticket de ruta y validación redundante de `ticket_id` / `event_id`. |
| **1.5.0-rc.29** | Cola y Tareas: gobernador recalibrado para 4 vCPU / 16 GB, 4 workers, menos tiempo muerto y pisos de batch para importación/WhatsApp/Flow/confirmación. |
| **1.5.0-rc.28** | Herramientas: fase rápida de datos + hasta tres shards de anexos, progreso compuesto y restauración de Configuración. |
| **1.5.0-rc.27** | Hardening online 5.000+: índice QR dedicado, bulk warm-up compartido, búsqueda Kiosko acotada y locks multi-tablet. |
| **1.5.0-rc.26** | Hardening offline 3.000–5.000: snapshot estable, payload estático único, cache Kiosko y límites defensivos. |
| **1.5.0-rc.25** | Consumibles móvil + historial/cancelación + offline unificado. |
| **1.5.0-rc.24** | Localidad, Sesiones y Doble Auth Android. |
| **1.5.0-rc.23** | Paquete offline único por evento. |
| **1.5.0-rc.22** | Offline completo de Kiosko. |
| **1.5.0-rc.21** | Offline de Staff QR. |

## Regla de promoción

`xyzz.eventosapp` sigue siendo el entorno de pruebas. **No promover rc.34 a producción hasta validar que cambiar Flow local actualice inmediatamente el Meta Flow ID, que el campo permanezca no editable y que el servidor descarte valores POST inconsistentes.** También deben completarse las validaciones pendientes de rc.33, rc.32, rc.31, rc.30, rc.29 y rc.27: presets nuevos e históricos sin copiar estado operativo, guardado desde el menú sticky sin dejar metaboxes ocultos, filtrado/log del Flow programado, los tres metaboxes WhatsApp, Media Library, envíos reales Ticket/Flow/Confirmación/Recordatorio, impresión Kiosko Android 2.13.1, importación de 4.000 tickets con anexos activos, throughput, pausa/reanudación/cancelación, métricas CPU/RAM/PHP-FPM/MySQL y validación online/offline de 5.000 asistentes.
