# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

> **Historial preservado:** `rc.27` endureció la operación online para 5.000+ asistentes; `rc.26` endureció el modo offline para 3.000–5.000 asistentes; `rc.25` incorporó Consumo de Consumibles; `rc.24` incorporó Localidad, Sesiones y Doble Auth; `rc.23` introdujo el paquete offline único por evento; `rc.22` el offline completo de Kiosko y `rc.21` el offline Staff QR.

## Estado actual: candidato 1.5.0-rc.28

La entrega **1.5.0-rc.28** optimiza específicamente la **importación manual masiva de tickets enviada a Cola y Tareas**. No aumenta de forma artificial los límites de memoria/CPU ni elimina las guardas defensivas de la cola: cambia el orden del trabajo para aprovechar la concurrencia que ya existe.

Datos de corte:

- **Fecha:** 2026-08-12
- **Rama:** `perf/ticket-import-throughput-rc28`
- **Base:** `main` con `1.5.0-rc.27`
- **Destino posterior:** `theleadpartner/EventosApp` únicamente después de validación real.

## Diagnóstico de la importación masiva

El cuello de botella no estaba principalmente en la memoria disponible. En el incidente analizado, una tarea de 4.000 tickets avanzó aproximadamente a 1.593 registros en más de cuatro horas mientras la pantalla mostraba alrededor de 25,8 % de memoria PHP y load 1 de 1,15.

La causa principal era el flujo secuencial del importador: por cada fila se guardaban los datos del ticket y, antes de avanzar a la siguiente, se generaban todos los anexos activos del evento (QR/PDF/ICS/Wallet/WhatsApp/variantes). Una parte de ese trabajo depende de filesystem o servicios remotos, por lo que PHP puede pasar tiempo esperando sin consumir mucha memoria ni saturar CPU.

Además, `max_concurrent = 3` en la cola central significa hasta tres **tareas independientes** simultáneas. Una sola tarea `ticket_import` conserva un lock/cursor único y no utiliza tres workers sobre el mismo CSV.

## Pipeline rápido de importación rc.28

Archivo principal:

```text
includes/admin/eventosapp-ticket-import-performance.php
```

Versión interna:

```text
EVENTOSAPP_TICKET_IMPORT_PERFORMANCE_VERSION = 2026.08.12.1
```

La ruta rápida se activa únicamente al usar **Enviar a Cola y Tareas · modo rápido**. El modo “en esta ventana” se conserva intacto como compatibilidad.

### Fase 1 — datos del CSV

El task `ticket_import` sigue reutilizando el procesador histórico de filas para conservar validación, fingerprint, deduplicación, actualización por cédula/evento, modalidad, extras, sesiones, secuencias, search blob y demás metadatos funcionales.

Durante esta fase se desactiva temporalmente solo la generación pesada de anexos. Cada ticket creado/actualizado queda marcado con:

```text
_eventosapp_import_assets_pending
```

La fila puede entonces terminar sin esperar QR/PDF/ICS/Wallet/WhatsApp.

### Fase 2 — anexos paralelos

Al terminar el CSV, los tickets pendientes se dividen de forma determinista en hasta tres shards. Cada shard crea una tarea independiente:

```text
ticket_import_assets
```

Los workers reutilizan `evapp_import_generate_assets_now()` con la configuración real del evento. Por tanto no se reemplaza ni duplica el motor de QR/PDF/ICS/Wallet/WhatsApp/variantes; únicamente puede trabajar sobre tickets distintos en paralelo.

La concurrencia efectiva continúa gobernada por la cola central. Las guardas de memoria y load permanecen activas, y el pipeline nunca solicita más de tres shards aunque el servidor permita un valor global mayor.

### Menos escritura auxiliar

Los éxitos dejan de escribir un log técnico por cada ticket. Errores y omisiones conservan su detalle individual; los éxitos se consolidan en resúmenes por lote. Esto evita miles de escrituras de log en importaciones grandes.

### Recuperación

Si un ticket fue escrito pero PHP cayó antes del checkpoint, el retry puede reconocerlo por fingerprint. Si `_eventosapp_import_assets_pending` sigue presente, rc.28 lo reincorpora a la fase paralela para que no quede sin anexos.

Los IDs de los shards se persisten inmediatamente después de crearlos para impedir duplicar tareas ya creadas durante una recuperación.

### Compatibilidad con tareas ya iniciadas

El adaptador de `ticket_import` se resuelve en cada nueva petición del worker. Una importación ya creada puede continuar desde su cursor con rc.28 después de desplegar el código. Los registros que ya fueron completados antes del despliegue no se reprocesan; la optimización se aplica a las filas que todavía estén pendientes.

Documentación completa:

```text
docs/ticket-import-throughput-rc28.md
```

## Qué no cambia en rc.28

No se modifica:

- la estructura de tickets;
- la regla de deduplicación;
- los generadores de QR/PDF/ICS/Wallet/WhatsApp;
- los umbrales globales de memoria/load;
- `max_concurrent` global;
- la cola de email, WhatsApp, recordatorios u otros módulos;
- la API móvil;
- la operación offline/online de Android;
- consumibles, check-in, sesiones o doble autenticación.

## Expectativa de rendimiento

La mejora proviene de retirar del camino crítico del CSV el trabajo pesado por ticket y de ejecutar después ese trabajo en shards independientes. Si los anexos son la mayor parte del costo, esa fase puede acercarse a una mejora de hasta aproximadamente 3x en condiciones ideales. No es una garantía: servicios Wallet, almacenamiento, CPU, MySQL y otras tareas concurrentes pueden reducir la ganancia real.

La prueba de aceptación debe medir por separado:

```text
tiempo de fase CSV
tiempo de fase de anexos
items/s
memoria máxima
load 1
errores por shard
```

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

queda protegido por ticket+día durante el check-in que precede a la generación de la escarapela.

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

rc.27 mantiene íntegro el hardening offline anterior:

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

Con rc.27 + Android 2.11.2, un evento de 5.000 asistentes deja de depender de una búsqueda general sobre `postmeta` en cada lectura QR una vez el índice está disponible.

La arquitectura queda preparada para que varias tablets procesen asistentes distintos simultáneamente. El número máximo de dispositivos/requests por segundo sigue siendo una propiedad conjunta del código y la infraestructura:

- número de workers PHP-FPM;
- CPU/RAM;
- MySQL/MariaDB;
- object cache;
- almacenamiento;
- TLS/red;
- otros procesos que compartan el servidor.

Por ello no se fija en código un número artificial de tablets simultáneas. La prueba real debe medir p50/p95/p99 y saturación del servidor.

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

## Checklist de validación rc.28

1. Importar 100 tickets usando Cola y Tareas.
2. Confirmar avance rápido de la fase CSV sin generación pesada por fila.
3. Confirmar creación de hasta tres tasks `ticket_import_assets`.
4. Verificar QR y anexos en tickets del inicio, mitad y final.
5. Confirmar que `_eventosapp_import_assets_pending` desaparece después de un resultado correcto.
6. Repetir con actualización de tickets existentes por cédula.
7. Probar evento con PDF/ICS/Wallet/WhatsApp activos.
8. Pausar/reanudar un shard.
9. Ejecutar 4.000 tickets y medir fase CSV + fase anexos.
10. Comparar items/s, memoria, load 1 y errores con la línea base.
11. Ejecutar regresión de Cola y Tareas para email/WhatsApp/recordatorios.
12. Ejecutar regresión completa online/offline.

## Checklist de validación online rc.27

1. Crear/usar evento de 5.000 tickets.
2. Confirmar creación de `{prefix}eventosapp_qr_lookup`.
3. Seleccionar el evento desde Android 2.11.2 con internet.
4. Confirmar warm-up por lotes sin bloquear la APP.
5. Confirmar que el lote usa bulk indexing y no miles de `REPLACE` individuales.
6. Seleccionar simultáneamente el evento desde varias tablets y verificar cursor compartido.
7. Confirmar que, una vez completo, otra tablet obtiene `complete=true` sin reconstruir el índice.
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
| **1.5.0-rc.28** | Pipeline rápido de importación: datos primero, anexos en hasta tres shards y logs agregados por lote. |
| **1.5.0-rc.27** | Hardening online 5.000+: índice QR dedicado, bulk warm-up compartido, búsqueda Kiosko acotada y locks multi-tablet. |
| **1.5.0-rc.26** | Hardening offline 3.000–5.000: snapshot estable, payload estático único, cache Kiosko y límites defensivos. |
| **1.5.0-rc.25** | Consumibles móvil + historial/cancelación + offline unificado. |
| **1.5.0-rc.24** | Localidad, Sesiones y Doble Auth Android. |
| **1.5.0-rc.23** | Paquete offline único por evento. |
| **1.5.0-rc.22** | Offline completo de Kiosko. |
| **1.5.0-rc.21** | Offline de Staff QR. |

## Regla de promoción

`xyzz.eventosapp` sigue siendo el entorno de pruebas. **No promover rc.28 a producción hasta validar una importación real grande**, incluida una prueba de 4.000 tickets con anexos activos, revisión de las tres tareas de anexos, regresión de datos/QR y métricas de recursos. También se mantiene pendiente la validación real online/offline de 5.000 asistentes definida en rc.27.
