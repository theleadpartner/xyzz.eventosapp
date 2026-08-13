# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

> **Historial preservado:** `rc.27` endureció la operación online para 5.000+ asistentes; `rc.26` endureció el modo offline para 3.000–5.000 asistentes; `rc.25` incorporó Consumo de Consumibles; `rc.24` incorporó Localidad, Sesiones y Doble Auth; `rc.23` introdujo el paquete offline único por evento; `rc.22` el offline completo de Kiosko y `rc.21` el offline Staff QR.

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
- **Cambios nuevos pendientes después de este corte:** ninguno detectado al cerrar la promoción; `main` de pruebas seguía en `9d1f5e3...`.
- **Nueva base obligatoria para próximas promociones:** `9d1f5e329cbeb6b8680c762104f1568db57738e2`.

El registro de cierre de este entorno queda en [`docs/production-promotion-1.5.0-rc.28.md`](docs/production-promotion-1.5.0-rc.28.md). En producción, el manifiesto exhaustivo quedó documentado en `docs/production-sync-1.5.0-rc.28.md`.

## Estado actual: candidato 1.5.0-rc.28

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
| **1.5.0-rc.28** | Herramientas: fase rápida de datos + hasta tres shards de anexos, progreso compuesto y restauración de Configuración. |
| **1.5.0-rc.27** | Hardening online 5.000+: índice QR dedicado, bulk warm-up compartido, búsqueda Kiosko acotada y locks multi-tablet. |
| **1.5.0-rc.26** | Hardening offline 3.000–5.000: snapshot estable, payload estático único, cache Kiosko y límites defensivos. |
| **1.5.0-rc.25** | Consumibles móvil + historial/cancelación + offline unificado. |
| **1.5.0-rc.24** | Localidad, Sesiones y Doble Auth Android. |
| **1.5.0-rc.23** | Paquete offline único por evento. |
| **1.5.0-rc.22** | Offline completo de Kiosko. |
| **1.5.0-rc.21** | Offline de Staff QR. |

## Regla de promoción

`xyzz.eventosapp` sigue siendo el entorno de pruebas. **No promover rc.28 a producción hasta validar una importación real grande**, incluida una prueba de 4.000 tickets con anexos activos, controles de pausa/reanudación/cancelación, integridad de tickets existentes y métricas de PHP-FPM/MySQL. También se mantiene pendiente la validación real online/offline de 5.000 asistentes definida en rc.27.
