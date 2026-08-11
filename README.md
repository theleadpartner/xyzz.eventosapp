# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

> **Historial preservado:** `rc.24` incorporó Localidad, Sesiones y Doble Auth a Android; `rc.23` introdujo el paquete offline único por evento; `rc.22` el offline completo de Kiosko y `rc.21` el offline Staff QR. Los estados anteriores continúan disponibles en Git y `docs/history/` cuando aplica.

## Estado actual: candidato 1.5.0-rc.25

La entrega **1.5.0-rc.25** incorpora a la API móvil el módulo web de **Consumo de Consumibles** y lo integra al paquete offline único por evento.

Datos de corte:

- **Fecha:** 2026-08-11
- **Rama:** `feature/mobile-consumables-offline-rc25`
- **Base:** `main` con `1.5.0-rc.24`
- **Android objetivo:** `theleadpartner/eventosapp-printer-android` **2.11.0** (`versionCode 32`)
- **Destino posterior de promoción:** `theleadpartner/EventosApp`, únicamente después de validación real.

## Objetivo de rc.25

Mantener en EventosApp toda la administración de consumibles y exponer a Android solo la operación de staff ya autorizada por el módulo web:

```text
Login
→ seleccionar evento aprobado
→ opcionalmente habilitar offline una sola vez
→ abrir Consumo de Consumibles
→ seleccionar ítem(s) y cantidad(es)
→ leer QR
→ descontar inventario
→ revisar transacciones / solicitar cancelación
```

No se duplica el motor contable. La API móvil reutiliza directamente:

```text
includes/functions/eventosapp-consumables-core.php
includes/functions/eventosapp-consumables-transactions.php
includes/functions/eventosapp-consumables.php
```

## Semántica preservada del módulo web

### Selección antes del QR

Android recibe los consumibles configurados para el evento. El operador debe seleccionar uno o varios ítems y sus cantidades antes de escanear al asistente.

### Descuento atómico

La escritura móvil usa:

```php
eventosapp_consumables_consume_items()
```

Por tanto se preserva la regla **todo o nada**: una transacción con varias líneas solo se confirma cuando todas tienen inventario suficiente y son válidas para el ticket/periodo.

### Resolución QR

La operación online usa:

```php
eventosapp_consumables_find_ticket_from_qr()
```

No se introduce un resolvedor paralelo.

### Inventario

El snapshot de cada ticket proviene de:

```php
eventosapp_consumables_get_ticket_inventory_snapshot()
```

La app recibe las cantidades asignadas, consumidas y restantes para el periodo efectivo según las reglas administrativas existentes.

## Historial móvil

La API expone únicamente las transacciones cuyo `staff_user_id` corresponde al usuario móvil autenticado.

Cada transacción conserva:

- `batch_id` / request UUID;
- ticket y asistente;
- líneas consumidas;
- cantidades;
- fecha/hora;
- estado;
- solicitud de cancelación, si existe.

La administración general de transacciones continúa en EventosApp.

## Solicitud de cancelación

Android no elimina ledger ni revierte saldos.

La ruta móvil reutiliza:

```php
eventosapp_consumables_tx_request_cancel()
```

Se mantienen las restricciones del flujo web:

1. solo el mismo staff que originó la transacción puede solicitar cancelación;
2. una transacción ya reversada no admite solicitud;
3. una solicitud duplicada es idempotente;
4. la reversión real sigue requiriendo la acción administrativa existente;
5. el ledger nunca se borra para simular una cancelación.

## Nueva API móvil de Consumibles

Archivo:

```text
includes/api/eventosapp-mobile-consumables-api.php
```

Versión interna:

```text
EVENTOSAPP_MOBILE_CONSUMABLES_API_VERSION = 1.0.0
```

### Eventos autorizados

```text
GET /wp-json/eventosapp-kiosk/v1/mobile/consumables/events
```

Devuelve únicamente eventos donde:

- Consumibles está habilitado;
- el usuario tiene permiso efectivo `consumables_staff`;
- el evento pertenece al ámbito autorizado del usuario.

### Registrar consumo online

```text
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/consumables/consume
```

Payload principal:

```json
{
  "scanned": "<qr>",
  "client_id": "<uuid>",
  "items": {
    "item_id_1": 2,
    "item_id_2": 1
  }
}
```

`client_id` se utiliza como request UUID del batch y conserva idempotencia ante reintentos.

### Consultar transacciones del staff

```text
GET /wp-json/eventosapp-kiosk/v1/events/{event_id}/consumables/transactions
```

### Solicitar cancelación

```text
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/consumables/cancel-request
```

### Sincronización offline

```text
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/consumables/offline-sync
```

Soporta dos tipos de operación:

```text
consume
cancel_request
```

El servidor procesa primero todos los `consume` y después los `cancel_request`. Esto permite que una tablet cree una transacción offline y también solicite su cancelación antes de reconectarse; al sincronizar, el consumo se materializa primero y la solicitud puede referenciar su mismo batch.

## Login móvil

Archivo:

```text
includes/api/eventosapp-mobile-consumables-auth.php
```

El interceptor conserva la autenticación/token existentes y amplía la elegibilidad para permitir usuarios cuyo único módulo móvil sea `consumables_staff`.

No otorga permisos nuevos: únicamente permite iniciar sesión cuando el usuario ya posee al menos un módulo móvil efectivo en algún evento.

## Paquete offline único 1.2.0

Archivo:

```text
includes/api/eventosapp-mobile-event-offline-api.php
```

Versión:

```text
EVENTOSAPP_MOBILE_EVENT_OFFLINE_API_VERSION = 1.2.0
```

Ruta preservada:

```text
GET /wp-json/eventosapp-kiosk/v1/events/{event_id}/offline-package
```

`modules` ahora puede contener:

```text
staff_qr
kiosk
qr_localidad
qr_session
qr_double_auth
consumables_staff
```

Consumibles se entrega en:

```text
module_data.consumables
```

Estructura:

```text
schema_version
event
config.items
config.transactions
tickets[]
```

Cada ticket contiene:

```text
ticket_id
ticket_public_id
attendee
lookup_keys     // hashes SHA-256, no QR en texto claro
inventory
```

La misma `WP_Query` paginada del paquete continúa consultando los tickets **una sola vez por página**. A partir de esos IDs se construyen los payloads de Staff, Kiosko, QR avanzado y Consumibles.

## Offline de Consumibles

Android utiliza el snapshot para validar localmente:

- pertenencia del QR al evento;
- fecha válida del evento;
- regla/inventario asignado;
- saldo suficiente de cada línea;
- descuento atómico de múltiples ítems.

Cada consumo offline se conserva con UUID y se revalida contra EventosApp al sincronizar.

### Concurrencia entre dispositivos desconectados

Dos dispositivos totalmente offline no pueden conocer los consumos realizados por el otro en tiempo real. Por ello el servidor vuelve a validar inventario al recibir cada operación. La sincronización converge el estado; exclusión global instantánea requiere conectividad.

## Bootstrap móvil

`includes/api/eventosapp-mobile-kiosk-feature-permission.php` carga ahora:

```text
Kiosko base
→ Staff QR
→ contexto de permisos Kiosko
→ offline Staff
→ offline Kiosko
→ QR avanzados
→ API Consumibles
→ extensión login Consumibles
→ offline-package unificado
```

## Compatibilidad preservada

rc.25 no modifica la lógica administrativa de:

- configuración de consumibles;
- reglas por localidad/custom/all;
- comportamiento shared/per_day;
- balances;
- ledger;
- aprobación/reversión administrativa.

Tampoco elimina las rutas móviles históricas:

```text
GET  /wp-json/eventosapp-kiosk/v1/staff/events/{event_id}/offline-snapshot
POST /wp-json/eventosapp-kiosk/v1/staff/events/{event_id}/offline-sync
GET  /wp-json/eventosapp-kiosk/v1/events/{event_id}/offline-snapshot
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/offline-sync
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/advanced-qr/offline-sync
```

Kiosko, impresión, Staff QR, Localidad, Sesiones y Doble Auth continúan con sus contratos actuales.

## Archivos de rc.25

Nuevos:

```text
includes/api/eventosapp-mobile-consumables-api.php
includes/api/eventosapp-mobile-consumables-auth.php
```

Modificados:

```text
includes/api/eventosapp-mobile-event-offline-api.php
includes/api/eventosapp-mobile-kiosk-feature-permission.php
README.md
```

## Checklist de validación

1. Usuario solo `consumables_staff`: debe poder iniciar sesión.
2. El listado móvil debe incluir únicamente eventos autorizados con Consumibles habilitado.
3. Consumir un ítem online con saldo suficiente.
4. Consumir varios ítems en una sola lectura.
5. Forzar saldo insuficiente en una línea y comprobar que ninguna línea se descuenta.
6. Validar QR inexistente/otro evento.
7. Verificar que el historial móvil devuelve solo transacciones del staff actual.
8. Solicitar cancelación propia.
9. Intentar solicitar cancelación de una transacción de otro staff y confirmar rechazo.
10. Confirmar que solicitar cancelación no revierte saldo.
11. Habilitar offline y comprobar `module_data.consumables` en `offline-package`.
12. Confirmar que los QR del snapshot están hasheados.
13. Consumir en modo avión.
14. Crear varias operaciones offline.
15. Crear una solicitud de cancelación offline.
16. Recuperar conectividad y sincronizar.
17. Repetir sincronización para comprobar idempotencia.
18. Confirmar que EventosApp vuelve a validar inventario del servidor.
19. Reversar desde administración y comprobar convergencia del siguiente paquete.
20. Regresión completa de Kiosko, Staff QR, Localidad, Sesiones y Doble Auth.

## Historial reciente

| Candidato | Cambio principal |
|---|---|
| **1.5.0-rc.25** | API móvil de Consumibles + historial/cancelación + offline dentro del paquete único. |
| **1.5.0-rc.24** | Localidad, Sesiones y Doble Auth para Android online/offline. |
| **1.5.0-rc.23** | Paquete offline único por evento. |
| **1.5.0-rc.22** | Offline completo de Kiosko. |
| **1.5.0-rc.21** | Offline de Check-in QR Staff. |

## Regla de promoción

`xyzz.eventosapp` sigue siendo el entorno de pruebas. **No promover rc.25 a producción hasta completar validación real WordPress + Android**, especialmente operaciones múltiples, saldos, solicitudes de cancelación, modo avión, idempotencia, concurrencia entre dispositivos y regresión de los módulos ya existentes.
