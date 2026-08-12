# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

> **Historial preservado:** `rc.26` endureció el modo offline para 3.000–5.000 asistentes; `rc.25` incorporó Consumo de Consumibles; `rc.24` incorporó Localidad, Sesiones y Doble Auth; `rc.23` introdujo el paquete offline único por evento; `rc.22` el offline completo de Kiosko y `rc.21` el offline Staff QR.

## Estado actual: candidato 1.5.0-rc.27

La entrega **1.5.0-rc.27** complementa el hardening offline de rc.26 con un hardening específico de la **operación online para eventos de 5.000+ asistentes y múltiples dispositivos simultáneos**.

Datos de corte:

- **Fecha:** 2026-08-11
- **Rama:** `perf/mobile-online-scale-rc27`
- **Base:** `main` con `1.5.0-rc.26`
- **Android objetivo:** `theleadpartner/eventosapp-printer-android` **2.11.2** (`versionCode 34`)
- **Destino posterior:** `theleadpartner/EventosApp` únicamente después de validación real.

## Resultado de la auditoría online

La APP Android no carga 5.000 tickets en memoria para cada lectura online. Cada cámara envía el valor leído y el backend resuelve el ticket correspondiente.

Los cuellos de botella encontrados estaban en el servidor:

1. el resolvedor QR histórico terminaba consultando `wp_postmeta` por cada lectura única;
2. varias tablets con QRs distintos podían multiplicar esas consultas simultáneamente;
3. Staff QR online hacía read/modify/write sobre metadatos serializados de check-in sin lock por ticket/día;
4. Kiosko/impresión y algunos flujos QR avanzados podían coincidir en el mismo patrón de escritura;
5. la búsqueda textual histórica de Kiosko podía llegar a cargar miles de tickets y metadatos completos en PHP para una sola consulta;
6. varias tablets no debían reconstruir independientemente el mismo índice de 5.000 asistentes.

rc.27 corrige estos puntos manteniendo los contratos funcionales de los módulos.

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

`eventosapp_qr_find_ticket_by_scanned_code()` conserva su contrato histórico pero ahora aplica este orden:

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

La nueva estrategia:

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

## Archivos de rc.27

Nuevos:

```text
includes/api/eventosapp-mobile-online-performance.php
includes/api/eventosapp-mobile-online-warm-coordinator.php
```

Modificados:

```text
includes/api/eventosapp-mobile-kiosk-feature-permission.php
README.md
```

No se modifica la administración de eventos, tickets, consumibles, balances, ledger ni reversión administrativa.

## Checklist de validación online

1. Crear/usar evento de 5.000 tickets.
2. Confirmar creación de `{prefix}eventosapp_qr_lookup`.
3. Seleccionar el evento desde Android 2.11.2 con internet.
4. Confirmar warm-up por lotes sin bloquear la APP.
5. Seleccionar simultáneamente el evento desde varias tablets y verificar cursor compartido.
6. Confirmar que, una vez completo, otra tablet obtiene `complete=true` sin reconstruir el índice.
7. Leer QRs de tickets del inicio, mitad y final del dataset.
8. Medir latencia antes, durante y después del warm-up.
9. Probar varias tablets leyendo tickets distintos al mismo tiempo.
10. Probar dos tablets leyendo el mismo ticket Staff casi simultáneamente.
11. Repetir con Kiosko/impresión.
12. Repetir con Sesiones.
13. Repetir con Doble Auth.
14. Probar Consumibles concurrente sobre saldos compartidos.
15. Buscar en Kiosko por identificación, teléfono, nombre y apellido.
16. Agregar/modificar ticket y comprobar autoindexación.
17. Mover un ticket entre eventos y comprobar eliminación/reindexación de sus claves.
18. Cambiar modo preimpreso y comprobar invalidación/reconstrucción del índice.
19. Forzar fallo del warm-up y verificar que el fallback QR sigue operativo.
20. Ejecutar regresión completa online/offline.
21. Medir CPU, RAM, PHP-FPM, queries/conexiones MySQL y errores HTTP bajo concurrencia.
22. Registrar p50, p95 y p99 de los endpoints operativos.

## Historial reciente

| Candidato | Cambio principal |
|---|---|
| **1.5.0-rc.27** | Hardening online 5.000+: índice QR dedicado, warm-up compartido, búsqueda Kiosko acotada y locks multi-tablet. |
| **1.5.0-rc.26** | Hardening offline 3.000–5.000: snapshot estable, payload estático único, cache Kiosko y límites defensivos. |
| **1.5.0-rc.25** | Consumibles móvil + historial/cancelación + offline unificado. |
| **1.5.0-rc.24** | Localidad, Sesiones y Doble Auth Android. |
| **1.5.0-rc.23** | Paquete offline único por evento. |
| **1.5.0-rc.22** | Offline completo de Kiosko. |
| **1.5.0-rc.21** | Offline de Staff QR. |

## Regla de promoción

`xyzz.eventosapp` sigue siendo el entorno de pruebas. **No promover rc.27 a producción hasta completar validación real WordPress + Android con 5.000 asistentes y concurrencia de múltiples tablets**, incluyendo latencias y métricas de PHP-FPM/MySQL.
