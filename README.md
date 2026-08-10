# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

> **Historial preservado:** el estado completo de `1.5.0-rc.21` se conserva en [`docs/history/README-through-1.5.0-rc.21.md`](docs/history/README-through-1.5.0-rc.21.md). Los snapshots anteriores continúan disponibles en `docs/history/`.

## Estado actual: candidato 1.5.0-rc.22

La entrega **1.5.0-rc.22** extiende el modo offline móvil al **Kiosko / Autogestión Android 2.8.0**, manteniendo intactos los endpoints online históricos y el modo offline Staff introducido en `1.5.0-rc.21`.

- **Fecha de corte:** 2026-08-10
- **Rama de trabajo:** `feature/mobile-kiosk-offline-rc22`
- **Base:** `main @ c5bf7bdec0cb2a932bff37085b2ae4e5fb9e316e`
- **Destino de promoción:** `theleadpartner/EventosApp`
- **Compatibilidad Android:** `theleadpartner/eventosapp-printer-android` **2.8.0** (`versionCode 27`)
- **Estado:** implementación en entorno de pruebas; requiere validación física de Kiosko, modo avión, sincronización e impresión antes de promover a producción.

## Objetivo de rc.22

Permitir que una tablet previamente preparada pueda continuar con el flujo de Kiosko aunque pierda internet:

1. mostrar el evento y su personalización;
2. buscar o identificar asistentes;
3. validar reglas locales descargadas;
4. marcar el check-in en el dispositivo;
5. imprimir la escarapela por Bluetooth sin pedir HTML al servidor;
6. sincronizar los ingresos al recuperar conectividad;
7. refrescar el snapshot completo después de sincronizar.

## Nueva API offline del Kiosko

Archivo:

```text
includes/api/eventosapp-mobile-kiosk-offline-api.php
```

Versión interna de API:

```text
EVENTOSAPP_MOBILE_KIOSK_OFFLINE_API_VERSION = 1.0.0
```

Rutas:

```text
GET  /wp-json/eventosapp-kiosk/v1/events/{event_id}/offline-snapshot
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/offline-sync
```

Ambas rutas requieren el token móvil existente y vuelven a verificar que el usuario tenga acceso efectivo a `self_checkin` para el evento.

### Offline snapshot

`offline-snapshot` es paginado (`page`, `per_page`, máximo 100) y devuelve:

- evento autorizado;
- zona horaria;
- configuración completa construida por la API estable del Kiosko;
- diseño, textos y método(s) de autenticación;
- configuración física de papel;
- tickets del evento;
- estado de check-in conocido;
- validación de modalidad y pago;
- hashes SHA-256 de claves QR válidas;
- HTML final de la escarapela por ticket.

El HTML se genera con el mismo helper estable:

```php
eventosapp_get_badge_html_from_event($event_id, $ticket_id, false)
```

Las imágenes utilizadas en la escarapela se convierten a `data:` URI antes de entregar el snapshot. El helper intenta primero resolver archivos locales en `uploads` y, como fallback seguro, utiliza `wp_safe_remote_get()` con límite estricto de 2 MB por recurso.

Los recursos visuales principales del Kiosko (`background_image_url`, `main_logo_url` y `extra_logos`) también se entregan embebidos cuando pueden resolverse, para que Android pueda materializarlos dentro de su almacenamiento privado.

### Offline sync

`offline-sync` recibe hasta 500 operaciones por solicitud. Cada elemento incluye:

```text
client_id
ticket_id
checkin_date
request_id
created_at
```

Antes de aceptar cada ingreso EventosApp vuelve a validar:

- que el ticket exista y pertenezca al evento;
- que siga activo;
- que sea presencial;
- control de pago;
- fecha válida del evento;
- que la fecha no esté en el futuro;
- idempotencia por `client_id`;
- concurrencia por ticket + evento + fecha.

El registro del log utiliza:

```text
qr_type: self_checkin_android
qr_type_label: Autogestión Android
origen: android_kiosk_offline_sync
client_operation_id: <uuid>
request_id: <request_id Android>
```

Si el ticket ya había sido marcado, responde `already=true` sin duplicar el estado de check-in.

## Reutilización de seguridad e idempotencia Staff

La extensión del Kiosko se carga después de:

```text
includes/api/eventosapp-mobile-staff-offline-api.php
```

para reutilizar helpers ya probados de:

- generación de hashes de lookup QR;
- lectura de días registrados;
- detección de `client_operation_id` previamente sincronizados;
- locks temporales para evitar carreras de sincronización;
- respuestas REST `no-store`.

El bootstrap permanece en:

```text
includes/api/eventosapp-mobile-kiosk-feature-permission.php
```

Orden efectivo:

```text
Kiosko base
→ Staff QR
→ contexto de permisos Kiosko
→ offline Staff
→ offline Kiosko
```

## Qué no cambia

rc.22 no reemplaza ni modifica la semántica de:

```text
POST /eventosapp-kiosk/v1/auth/login
POST /eventosapp-kiosk/v1/auth/logout
GET  /eventosapp-kiosk/v1/events
GET  /eventosapp-kiosk/v1/events/{id}
POST /eventosapp-kiosk/v1/events/{id}/search
POST /eventosapp-kiosk/v1/tickets/{id}/print
GET  /eventosapp-kiosk/v1/badge
```

Tampoco cambia:

- la generación normal de escarapelas;
- la impresión online;
- las reglas históricas de Autogestión;
- la API Staff QR;
- el snapshot/sync Staff de rc.21;
- la cola Bluetooth de Android;
- la UI del dashboard, Elementor, seguridad, expositores, consumibles, networking, sorteos ni otras funciones fuera de este alcance.

## Permisos

Para descargar o sincronizar un paquete Kiosko offline se exige:

- usuario autenticado por la capa móvil;
- evento `eventosapp_event` válido;
- acceso efectivo `self_checkin` para ese usuario/evento;
- Kiosko habilitado;
- evento con acceso físico (`presencial` o `presencial_virtual`).

Cuando Android vuelve a conectarse, consulta nuevamente la lista de eventos permitidos. Un evento revocado deja de ser utilizable offline.

## Archivos de rc.22

Nuevos:

```text
includes/api/eventosapp-mobile-kiosk-offline-api.php
docs/history/README-through-1.5.0-rc.21.md
```

Modificados:

```text
includes/api/eventosapp-mobile-kiosk-feature-permission.php
README.md
```

## Compatibilidad Android

Backend requerido por:

```text
EventosApp Android 2.8.0 (versionCode 27)
```

Android 2.7.0 continúa utilizando únicamente las rutas Staff offline de rc.21 y no necesita las nuevas rutas Kiosko.

## Validación requerida antes de promoción

1. Confirmar que el índice REST publica `offline-snapshot` y `offline-sync` para Kiosko.
2. Iniciar sesión desde Android 2.8.0 con un usuario que tenga `self_checkin` en un evento presencial/híbrido con Kiosko habilitado.
3. Habilitar modo offline y confirmar que descarga configuración, tickets, escarapelas y papel.
4. Verificar que la cantidad local de tickets coincide con el paquete entregado.
5. Activar modo avión antes de abrir el Kiosko y confirmar que carga el evento y su diseño.
6. Buscar un asistente por cada campo configurado que aplique al evento.
7. Leer un QR válido y confirmar la misma resolución que en modo online.
8. Registrar un check-in offline y confirmar que la escarapela imprime por Bluetooth sin solicitar recursos de red.
9. Repetir el mismo ticket en la misma fecha y confirmar que el dispositivo no crea otro pendiente.
10. Cerrar y abrir la app sin internet y confirmar persistencia del paquete y de los pendientes.
11. Recuperar internet y confirmar sincronización automática y actualización de **Última sincronización**.
12. Revisar `_eventosapp_checkin_status` y `_eventosapp_checkin_log`; el log debe incluir `origen=android_kiosk_offline_sync` y `client_operation_id`.
13. Reenviar el mismo `client_id` y confirmar idempotencia.
14. Marcar el ticket primero desde otro dispositivo y luego sincronizar el pendiente offline; debe converger con `already=true`.
15. Revocar `self_checkin` y confirmar que un nuevo snapshot/sync responde `403` y que Android deja de ofrecer el evento como utilizable.
16. Activar control de pago y confirmar que un ticket no pagado no puede registrarse offline.
17. Validar escarapelas con logo, QR, fondos/imágenes y tamaños de papel usados en producción.
18. Confirmar regresión cero en Kiosko online, Check-in QR online/offline Staff, cancelación de impresión, cola Bluetooth y protocolos existentes.

## Límite inherente de varios dispositivos completamente offline

Dos dispositivos sin ningún canal de comunicación no pueden conocer en tiempo real el ingreso realizado por el otro. Durante una caída total, el mismo ticket puede ser admitido localmente en dos tablets diferentes.

Al recuperar conectividad, la sincronización converge: el primer registro fija `checked_in` y los posteriores se resuelven como `already=true`. Evitar ese doble uso antes de volver a tener comunicación exigiría una arquitectura adicional de coordinación local entre dispositivos.

## Historial reciente

| Candidato | Cambio principal |
|---|---|
| **1.5.0-rc.22** | Snapshot y sincronización offline para Kiosko Android 2.8.0, incluida escarapela autocontenida. |
| **1.5.0-rc.21** | Snapshot y sincronización offline para Check-in QR Staff Android 2.7.0. |
| **1.5.0-rc.20** | Gutter unificado, diagnóstico de instalación persistente, recuperación de reinstalación y notificación terminal descartable. |
| **1.5.0-rc.19** | Página 404 corporativa, Light/Dark y aislamiento Elementor/tema. |
| **1.5.0-rc.18** | Dashboard boxed nativo. |

## Regla de promoción

`xyzz.eventosapp` continúa siendo el entorno de pruebas. **No promover `1.5.0-rc.22` a `theleadpartner/EventosApp` hasta completar la validación real de WordPress + Android + impresora física**, en especial modo avión, persistencia, impresión de escarapela, sincronización idempotente, revocación de permisos y regresión del flujo online.