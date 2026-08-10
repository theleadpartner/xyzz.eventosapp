# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

> **Historial preservado:** los estados anteriores continúan disponibles en `docs/history/`. `1.5.0-rc.22` introdujo el offline completo de Kiosko y `1.5.0-rc.21` el offline Staff QR.

## Estado actual: candidato 1.5.0-rc.23

La entrega **1.5.0-rc.23** agrega un **paquete offline único por evento** para Android 2.9.1. La nueva ruta consolida en una sola descarga los datos requeridos por todos los módulos móviles que el usuario tenga autorizados, sin eliminar las APIs específicas Staff/Kiosko existentes.

- **Fecha de corte:** 2026-08-10
- **Rama de trabajo:** `feature/mobile-event-offline-package-rc23`
- **Base:** `main` con `1.5.0-rc.22`
- **Destino de promoción:** `theleadpartner/EventosApp`
- **Compatibilidad Android nueva:** `theleadpartner/eventosapp-printer-android` **2.9.1** (`versionCode 29`)
- **Compatibilidad histórica:** Android 2.7.0 / 2.8.0 continúa usando las rutas existentes.

## Objetivo de rc.23

Cambiar la unidad de descarga offline de **módulo** a **evento**:

```text
Android selecciona evento
→ GET offline-package
→ EventosApp consulta tickets una sola vez por página
→ responde módulos autorizados + payloads de cada módulo
→ Android distribuye localmente los bloques a sus stores operativos
```

Esto permite que la aplicación prepare una sola vez un evento y después abra Kiosko, Check-in QR o módulos futuros sin tener que volver a descargar la misma base de asistentes por cada módulo.

## Nueva API unificada

Archivo:

```text
includes/api/eventosapp-mobile-event-offline-api.php
```

Versión interna:

```text
EVENTOSAPP_MOBILE_EVENT_OFFLINE_API_VERSION = 1.0.0
```

Ruta:

```text
GET /wp-json/eventosapp-kiosk/v1/events/{event_id}/offline-package
```

Parámetros:

```text
page
per_page   // máximo 100
```

### Estructura

La respuesta contiene:

```text
api_version
generated_at
event_id
timezone
event
modules
module_data
page
per_page
total
total_pages
```

`modules` enumera únicamente capacidades autorizadas. En rc.23 puede contener:

```text
staff_qr
kiosk
```

`module_data` es extensible y contiene un bloque por módulo. Esto permite sumar otros módulos móviles sin modificar el contrato base del paquete.

## Consulta única de tickets

`offline-package` ejecuta una sola `WP_Query` de tickets por página. A partir de esos IDs reutiliza los builders ya probados:

```php
eventosapp_mobile_offline_ticket_payload()
eventosapp_mobile_kiosk_offline_ticket_payload()
```

Por lo tanto se conservan:

- hashes SHA-256 de QR;
- validación de ticket presencial;
- control de pago;
- estado de check-in conocido;
- datos de asistente;
- escarapela autocontenida;
- recursos gráficos embebidos;
- configuración física y visual del Kiosko.

El paquete no guarda ni expone QR en texto claro.

## Permisos por módulo

Antes de construir la respuesta se calcula la lista autorizada para el usuario actual.

### Staff QR

Requiere:

```text
eventosapp_mobile_app_user_can_feature_in_event(event_id, user_id, 'qr')
```

### Kiosko

Reutiliza:

```text
eventosapp_mobile_kiosk_offline_user_can_event(event_id, user_id)
```

que exige permiso efectivo `self_checkin`, Kiosko habilitado y acceso físico compatible.

Si el usuario no tiene ningún módulo móvil autorizado para el evento, la ruta responde `403`.

## Sincronización

rc.23 **no fusiona artificialmente las escrituras** de Staff y Kiosko. Cada cola continúa utilizando su endpoint especializado porque allí viven sus validaciones e idempotencia:

```text
POST /wp-json/eventosapp-kiosk/v1/staff/events/{event_id}/offline-sync
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/offline-sync
```

Después de enviar las colas, Android 2.9.1 vuelve a consultar una sola vez `offline-package`. Así incorpora cambios del servidor sin ejecutar dos snapshots independientes.

Esto cubre:

- asistentes nuevos;
- cambios de información de asistentes;
- check-ins de otros dispositivos;
- cambios de pago/modalidad;
- cambios de configuración Kiosko;
- cambios de escarapela;
- actualización de módulos autorizados.

## APIs históricas preservadas

rc.23 no elimina ni altera:

```text
GET  /wp-json/eventosapp-kiosk/v1/staff/events/{event_id}/offline-snapshot
POST /wp-json/eventosapp-kiosk/v1/staff/events/{event_id}/offline-sync

GET  /wp-json/eventosapp-kiosk/v1/events/{event_id}/offline-snapshot
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/offline-sync
```

Por ello Android 2.7.0/2.8.0 puede seguir operando mientras 2.9.1 utiliza el paquete unificado.

Tampoco cambia la semántica online de:

```text
POST /eventosapp-kiosk/v1/auth/login
POST /eventosapp-kiosk/v1/auth/logout
GET  /eventosapp-kiosk/v1/events
GET  /eventosapp-kiosk/v1/events/{id}
POST /eventosapp-kiosk/v1/events/{id}/search
POST /eventosapp-kiosk/v1/tickets/{id}/print
GET  /eventosapp-kiosk/v1/badge
```

## Orden de bootstrap

`includes/api/eventosapp-mobile-kiosk-feature-permission.php` mantiene el orden:

```text
Kiosko base
→ Staff QR
→ contexto de permisos Kiosko
→ offline Staff
→ offline Kiosko
→ offline-package unificado
```

El paquete se carga al final porque reutiliza helpers de ambos módulos.

## Archivos de rc.23

Nuevo:

```text
includes/api/eventosapp-mobile-event-offline-api.php
```

Modificados:

```text
includes/api/eventosapp-mobile-kiosk-feature-permission.php
README.md
```

No se modifican dashboard, Elementor, seguridad, expositores, consumibles, networking, sorteos, impresión online ni otras áreas fuera del alcance móvil offline.

## Validación requerida

1. Confirmar que el índice REST publica `/events/{event_id}/offline-package`.
2. Probar usuario con solo `qr`: `modules` debe incluir únicamente `staff_qr`.
3. Probar usuario con solo `self_checkin`: debe incluir únicamente `kiosk`.
4. Probar usuario con ambos permisos: debe incluir ambos bloques.
5. Confirmar `403` si no tiene ningún módulo móvil autorizado.
6. Validar paginación con evento de más de 100 tickets.
7. Confirmar que una página ejecuta una sola consulta principal de tickets y construye ambos payloads desde los mismos IDs.
8. Validar Kiosko con logos, fondo, QR, escarapela e imágenes embebidas.
9. Validar que Staff conserva hashes QR y estados de check-in.
10. Crear un asistente mientras Android está offline; al reconectar debe aparecer tras `offline-package`.
11. Modificar datos de un asistente y confirmar actualización local.
12. Registrar check-in desde otro dispositivo y confirmar convergencia.
13. Confirmar que Android 2.8.0 sigue consumiendo `offline-snapshot` sin regresión.
14. Confirmar regresión cero en endpoints online.

## Límite inherente de varios dispositivos completamente offline

Dos dispositivos sin ningún canal de comunicación no pueden conocer en tiempo real el ingreso realizado por el otro. Durante una caída total, el mismo ticket puede ser admitido localmente en dos tablets diferentes.

Al recuperar conectividad, las rutas idempotentes convergen el estado: el primer registro fija `checked_in` y los posteriores pueden resolverse como `already=true`.

## Historial reciente

| Candidato | Cambio principal |
|---|---|
| **1.5.0-rc.23** | Paquete offline único por evento para Android 2.9.1, extensible a múltiples módulos. |
| **1.5.0-rc.22** | Snapshot y sincronización offline para Kiosko Android 2.8.0, incluida escarapela autocontenida. |
| **1.5.0-rc.21** | Snapshot y sincronización offline para Check-in QR Staff Android 2.7.0. |
| **1.5.0-rc.20** | Gutter unificado, diagnóstico de instalación persistente, recuperación de reinstalación y notificación terminal descartable. |
| **1.5.0-rc.19** | Página 404 corporativa, Light/Dark y aislamiento Elementor/tema. |
| **1.5.0-rc.18** | Dashboard boxed nativo. |

## Regla de promoción

`xyzz.eventosapp` continúa siendo el entorno de pruebas. **No promover `1.5.0-rc.23` al repositorio productivo hasta completar validación real WordPress + Android + impresora física**, incluyendo modo avión, paginación, reconexión, actualización inteligente, idempotencia y regresión del flujo online.
