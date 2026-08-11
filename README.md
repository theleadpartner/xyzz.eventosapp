# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

> **Historial preservado:** `1.5.0-rc.23` introdujo el paquete offline único por evento; `rc.22` el offline completo de Kiosko y `rc.21` el offline Staff QR. Los estados anteriores continúan disponibles en Git y `docs/history/` cuando aplica.

## Estado actual: candidato 1.5.0-rc.24

La entrega **1.5.0-rc.24** extiende la API móvil y el paquete offline unificado para incorporar los módulos web:

- `[eventosapp_qr_localidad]` → **Validador de Localidad**;
- `[eventosapp_qr_sesion]` → **Check-in de Sesiones Internas**;
- `[qr_checkin_doble_auth]` → **Check-in con Doble Autenticación**.

Datos de corte:

- **Fecha:** 2026-08-10
- **Rama:** `feature/mobile-advanced-qr-offline-rc24`
- **Base:** `main` con `1.5.0-rc.23`
- **Android objetivo:** `theleadpartner/eventosapp-printer-android` **2.10.0** (`versionCode 30`)
- **Destino posterior de promoción:** `theleadpartner/EventosApp`, únicamente después de validación real.

## Objetivo de rc.24

Mantener el flujo estructural de 2.9.1:

```text
Login
→ seleccionar evento aprobado
→ habilitar offline una sola vez
→ seleccionar cualquiera de los módulos autorizados
```

La incorporación de tres lectores QR nuevos **no crea tres descargas adicionales**. `offline-package` sigue consultando la base del evento una sola vez por página y entrega un bloque compartido `advanced_qr` para Localidad, Sesiones y Doble Auth.

## Nueva API móvil QR avanzada

Archivo nuevo:

```text
includes/api/eventosapp-mobile-advanced-qr-api.php
```

Versión interna:

```text
EVENTOSAPP_MOBILE_ADVANCED_QR_API_VERSION = 1.0.0
```

### Eventos y módulos autorizados

```text
GET /wp-json/eventosapp-kiosk/v1/mobile/events
```

La respuesta contiene los eventos accesibles y `modules` con las capacidades efectivas del usuario. Los permisos se resuelven en EventosApp; Android no infiere acceso por nombre de rol.

Reglas:

- `qr_localidad` requiere feature efectiva `qr_localidad`;
- `qr_session` conserva la semántica histórica del shortcode y requiere feature `qr` + sesiones configuradas;
- `qr_double_auth` requiere feature `qr_double_auth` + `_eventosapp_ticket_double_auth_enabled = 1`.

El interceptor de login se ejecuta antes de la extensión Staff existente para permitir usuarios que solo tengan Localidad o Doble Auth, sin obligarlos a poseer Kiosko o Check-in QR estándar.

## Validador de Localidad

Ruta:

```text
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/qr-localidad/lookup
```

Replica el objetivo de `[eventosapp_qr_localidad]`:

- resuelve el mismo QR/ticket del ecosistema existente;
- valida que pertenezca al evento;
- devuelve asistente, empresa, cargo y localidad;
- **no modifica** `_eventosapp_checkin_status`;
- **no registra ingreso**;
- funciona offline usando los hashes QR incluidos en el paquete del evento.

## Check-in de Sesiones Internas

Rutas online:

```text
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/qr-session/lookup
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/qr-session/checkin
```

Replica `[eventosapp_qr_sesion]`:

1. el operador selecciona una sesión configurada en `_eventosapp_sesiones_internas`;
2. se valida que el día actual sea fecha del evento;
3. se resuelve el ticket;
4. `eventosapp_ticket_tiene_acceso(ticket, session)` decide acceso;
5. Android muestra el resultado y requiere confirmación explícita;
6. únicamente se actualiza `_eventosapp_ticket_checkin_sesiones`;
7. el ingreso se registra en `_eventosapp_checkin_log` con sesión y origen;
8. **no se modifica el check-in general** del evento.

Offline, el paquete contiene por ticket:

```text
session_access
session_status
```

Los ingresos de sesión se guardan con UUID y se sincronizan mediante la cola avanzada idempotente.

## Check-in Doble Autenticación

Rutas online:

```text
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/qr-double-auth/lookup
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/qr-double-auth/checkin
```

Replica `[qr_checkin_doble_auth]`:

- fecha válida del evento;
- control de pago del ticket;
- estado de check-in ya existente;
- configuración diaria/multidía de segundo factor;
- código numérico de 5 dígitos cuando corresponde;
- límite temporal de intentos del flujo web: 8 intentos y bloqueo de 5 minutos;
- registro final mediante `eventosapp_register_ticket_checkin()` cuando está disponible.

### Seguridad del segundo factor offline

El código de 5 dígitos **no se entrega ni se almacena en texto claro en Android**.

Para cada ticket/día que requiere segundo factor, EventosApp genera en el snapshot:

- `salt` aleatorio;
- PBKDF2-HMAC-SHA256 de 256 bits;
- `iterations = 120000`;
- `proof` HMAC del payload firmado con un secreto derivado de `wp_salt('auth')`.

Android verifica localmente el código digitado contra PBKDF2 y conserva únicamente el verificador + prueba firmada para la cola. Al reconectar, EventosApp valida la firma del snapshot antes de aceptar la operación offline.

Android aplica además el mismo límite operativo de **8 intentos / 5 minutos** en su base local.

## Paquete offline único 1.1.0

Archivo:

```text
includes/api/eventosapp-mobile-event-offline-api.php
```

Versión interna actualizada:

```text
EVENTOSAPP_MOBILE_EVENT_OFFLINE_API_VERSION = 1.1.0
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
```

Para evitar duplicar la base de asistentes, los tres módulos QR nuevos comparten:

```text
module_data.advanced_qr
```

Estructura principal:

```text
schema_version
event
enabled_modules
config
tickets
```

Cada ticket avanzado reutiliza los mismos hashes QR seguros del snapshot Staff e incorpora únicamente los campos requeridos por los módulos autorizados.

## Sincronización offline avanzada

Ruta:

```text
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/advanced-qr/offline-sync
```

Módulos con escritura:

```text
qr_session
qr_double_auth
```

`qr_localidad` no tiene cola porque es solo lectura.

Antes de aceptar cada operación el servidor vuelve a validar:

- usuario y permiso actual;
- pertenencia del ticket al evento;
- fecha del evento;
- sesión vigente y acceso, cuando aplica;
- control de pago, cuando aplica;
- prueba firmada del segundo factor offline, cuando aplica;
- UUID ya procesado para idempotencia.

Después de enviar las colas, Android vuelve a descargar **una sola vez** `offline-package` para converger el estado completo del evento.

## Bootstrap

`includes/api/eventosapp-mobile-kiosk-feature-permission.php` carga ahora:

```text
Kiosko base
→ Staff QR
→ contexto de permisos Kiosko
→ offline Staff
→ offline Kiosko
→ API móvil QR avanzada
→ offline-package unificado
```

La API avanzada se carga antes del paquete porque `offline-package` reutiliza sus builders y resolución de módulos.

## Compatibilidad preservada

No se eliminan ni cambian las rutas históricas:

```text
GET  /wp-json/eventosapp-kiosk/v1/staff/events/{event_id}/offline-snapshot
POST /wp-json/eventosapp-kiosk/v1/staff/events/{event_id}/offline-sync
GET  /wp-json/eventosapp-kiosk/v1/events/{event_id}/offline-snapshot
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/offline-sync
```

Tampoco se modifica la semántica de Kiosko, impresión, Bluetooth, escarapelas, Elementor, dashboard, expositores, networking, sorteos u otras áreas fuera del alcance solicitado.

## Archivos de rc.24

Nuevo:

```text
includes/api/eventosapp-mobile-advanced-qr-api.php
```

Modificados:

```text
includes/api/eventosapp-mobile-event-offline-api.php
includes/api/eventosapp-mobile-kiosk-feature-permission.php
README.md
```

## Validación requerida

1. Usuario solo `qr_localidad`: debe poder iniciar sesión y ver únicamente Localidad en el evento.
2. Localidad online: escanear QR y confirmar que muestra la localidad sin modificar check-in.
3. Localidad offline: repetir en modo avión con la misma semántica de solo lectura.
4. Sesiones: verificar selector, acceso permitido/no permitido y confirmación explícita.
5. Confirmar que Sesiones modifica únicamente `_eventosapp_ticket_checkin_sesiones`.
6. Repetir Sesiones offline y comprobar cola + sincronización idempotente.
7. Doble Auth online: validar pago, fecha, código correcto/incorrecto y bloqueo temporal.
8. Evento multidía: validar código correspondiente al día actual.
9. Doble Auth offline: comprobar que no existe código de 5 dígitos en texto claro dentro del payload.
10. Validar vector PBKDF2 PHP/Android y prueba HMAC del snapshot.
11. Repetir Doble Auth offline con código incorrecto hasta alcanzar el límite temporal.
12. Recuperar internet y confirmar envío de colas avanzadas seguido por una sola redescarga `offline-package`.
13. Confirmar que Kiosko y Staff QR continúan funcionando online/offline sin regresión.
14. Confirmar que Android 2.7/2.8 puede seguir usando sus endpoints históricos.

## Límite inherente de dispositivos totalmente offline

Dos dispositivos sin comunicación no pueden conocer en tiempo real lo registrado por el otro. Un mismo ingreso podría ser admitido localmente en dos tablets durante una caída total. La sincronización idempotente converge el estado al recuperar conectividad; exclusión global instantánea exige comunicación entre dispositivos o con EventosApp.

## Historial reciente

| Candidato | Cambio principal |
|---|---|
| **1.5.0-rc.24** | API Android para Localidad, Sesiones y Doble Auth + compatibilidad offline dentro del paquete único. |
| **1.5.0-rc.23** | Paquete offline único por evento para Android 2.9.1. |
| **1.5.0-rc.22** | Snapshot/sync offline para Kiosko Android 2.8.0. |
| **1.5.0-rc.21** | Snapshot/sync offline para Check-in QR Staff Android 2.7.0. |
| **1.5.0-rc.20** | Gutter unificado, diagnóstico de instalación persistente y recuperación. |
| **1.5.0-rc.19** | Página 404 corporativa Light/Dark y aislamiento Elementor/tema. |
| **1.5.0-rc.18** | Dashboard boxed nativo. |

## Regla de promoción

`xyzz.eventosapp` sigue siendo el entorno de pruebas. **No promover rc.24 a producción hasta completar validación real WordPress + Android**, especialmente modo avión, sesiones, segundo factor, multidía, control de pago, sincronización y regresión Kiosko/Staff.
