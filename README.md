# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

> **Historial preservado:** el estado detallado completo hasta `1.5.0-rc.20` se conserva en [`docs/history/README-through-1.5.0-rc.20.md`](docs/history/README-through-1.5.0-rc.20.md). Los snapshots anteriores continúan disponibles en `docs/history/`.

## Estado del ciclo actual

- **Versión candidata:** `1.5.0-rc.21`
- **Fecha de corte:** 2026-08-10
- **Base integrada:** `79b2fba305a5d8630ad89d282fed00ef938228f5` (`main`, integración final de `1.5.0-rc.20`)
- **Rama de trabajo:** `feature/mobile-offline-checkin-rc21`
- **Destino de promoción:** `theleadpartner/EventosApp`
- **Compatibilidad Android:** `theleadpartner/eventosapp-printer-android` `2.7.0` (`versionCode 26`)
- **Estado:** soporte de servidor para Check-in QR Staff offline: descarga paginada de snapshot, hashes SHA-256 de QR válidos, sincronización idempotente por lote y resolución segura al recuperar conectividad.

## 1.5.0-rc.21 — API offline para Check-in QR Staff

### Objetivo

EventosApp Android 2.7.0 puede habilitar una copia offline por evento para continuar el control de acceso cuando el dispositivo pierde internet. El servidor sigue siendo la fuente de verdad cuando existe conexión, pero ahora proporciona los datos mínimos necesarios para validar tickets localmente y recibe posteriormente los ingresos pendientes.

El modo offline **no reemplaza** el flujo online existente. Solo extiende el módulo Staff QR y conserva aislados Kiosko, impresión, Bluetooth, escarapelas y la cola de impresión.

## Nuevas rutas REST

La extensión `includes/api/eventosapp-mobile-staff-offline-api.php` añade:

```text
GET  /eventosapp-kiosk/v1/staff/events/{event_id}/offline-snapshot
POST /eventosapp-kiosk/v1/staff/events/{event_id}/offline-sync
```

Ambas rutas reutilizan el token móvil existente y el permiso efectivo `qr` por usuario/evento mediante `eventosapp_mobile_app_permission()` y `eventosapp_mobile_app_user_can_feature_in_event()`.

### `offline-snapshot`

Entrega la base descargable por páginas de hasta 500 tickets. Incluye:

- datos básicos del evento y zona horaria;
- datos del asistente necesarios para mostrar el resultado del check-in;
- modalidad presencial/virtual;
- estado de pago cuando el evento controla pagos;
- fechas del evento;
- días ya marcados como `checked_in`;
- claves de búsqueda QR como **SHA-256**, nunca el contenido QR en texto claro.

La respuesta envía `Cache-Control: no-store` y `Pragma: no-cache` para evitar almacenamiento accidental por navegador, proxy o CDN.

Tickets en `trash`, `auto-draft` o `inherit` no se distribuyen al dispositivo.

## Paridad con los QR online

El snapshot no inventa formatos nuevos. Construye las mismas representaciones aceptadas por el resolvedor QR actual:

- `eventosapp_ticketID` para QR Legacy cuando el evento no usa preimpresos;
- ID numérico preimpreso cuando esa modalidad está habilitada;
- `ticketID-email`;
- `ticketID-gwallet`;
- `ticketID-awallet`;
- `ticketID-pdf`;
- `ticketID-whatsapp`;
- URL de escarapela/networking con evento, ticket público y código de seguridad.

Para la escarapela se replica la forma actualmente generada por `EventosApp_QR_Manager::generate_badge_qr()` mediante `add_query_arg()` sobre `networking/global`.

Android normaliza la lectura y calcula SHA-256 localmente; la base descargada no necesita conservar el contenido original del QR.

## Sincronización offline

Android envía lotes de máximo 500 operaciones. Cada elemento contiene un `client_id` UUID persistente, ticket, fecha y tipo de QR.

Antes de aceptar cada registro, EventosApp vuelve a validar:

1. que el ticket siga existiendo y pertenezca al evento;
2. que el ticket no esté eliminado/inactivo;
3. que siga siendo presencial;
4. que siga cumpliendo el control de pago;
5. que el tipo de QR continúe siendo válido para ese ticket;
6. que la fecha pertenezca al evento;
7. que la fecha no esté en el futuro respecto a la zona horaria del evento.

El permiso `qr` también se comprueba nuevamente para el evento antes de procesar el lote.

### Idempotencia

Cada operación conserva `client_operation_id` dentro de `_eventosapp_checkin_log`.

Si Android reintenta el mismo UUID porque perdió la respuesta HTTP, EventosApp responde como ya sincronizado y no duplica el check-in.

Si el ticket ya había sido marcado por otro dispositivo, la sincronización devuelve `already=true`, conserva un solo estado `checked_in` para esa fecha y registra la llegada de la operación offline para auditoría.

### Concurrencia

La sincronización usa un lock atómico temporal por `evento + ticket + fecha`, implementado con una option no autoload. Esto serializa procesos PHP concurrentes para impedir que dos dispositivos que recuperan internet al mismo tiempo incrementen métricas o escriban el mismo estado como si ambos fueran el primero.

Los locks vencidos por una interrupción anormal se pueden recuperar después de 30 segundos.

## Límite inherente de dos dispositivos completamente offline

Si dos dispositivos están simultáneamente sin conexión y ambos reciben el mismo ticket, no existe un canal que les permita conocer el ingreso realizado por el otro en tiempo real. Ambos pueden admitirlo localmente.

Al recuperar internet, la sincronización converge correctamente:

- el primer registro establece `checked_in`;
- los posteriores se resuelven como `already=true`;
- no se duplica el estado del ticket;
- el log conserva trazabilidad de cada operación recibida.

Evitar ese duplicado **antes** de recuperar conectividad requeriría comunicación entre dispositivos o un coordinador local, arquitectura diferente al modo offline autónomo solicitado.

## Carga ordenada de la extensión

El bootstrap principal ya carga, en este orden:

1. API base del Kiosko;
2. API móvil Staff QR;
3. contexto de permisos del Kiosko.

Para no alterar el archivo principal ni duplicar responsabilidades de autenticación, `includes/api/eventosapp-mobile-kiosk-feature-permission.php` actúa ahora como último bootstrap móvil y carga de forma opcional:

```text
includes/api/eventosapp-mobile-staff-offline-api.php
```

La carga usa `is_readable()` + `require_once`, por lo que un despliegue parcial no provoca un fatal error: simplemente las rutas offline no existirán hasta que el nuevo archivo esté presente.

Orden efectivo:

```text
eventosapp-kiosk-api.php
eventosapp-mobile-staff-checkin-api.php
eventosapp-mobile-kiosk-feature-permission.php
eventosapp-mobile-staff-offline-api.php
```

## Archivos de 1.5.0-rc.21

```text
includes/api/eventosapp-mobile-staff-offline-api.php             NUEVO — snapshot y sincronización offline Staff QR
includes/api/eventosapp-mobile-kiosk-feature-permission.php     MODIFICADO — bootstrap final de la extensión offline
docs/history/README-through-1.5.0-rc.20.md                      NUEVO — snapshot íntegro del ciclo anterior
README.md                                                        MODIFICADO — versión, arquitectura, seguridad y validación
```

## Commits funcionales

```text
55a9a2019d1932576a8ab372eb1239840e4c5db6  feat: add offline staff check-in API
ddf791dfc08c314c163ea70855d27e510f225908  feat: load offline staff API after mobile permissions
19e83b1558e82a6679ece9f3e67a22230532f91b  docs: preserve detailed history through rc20
```

## Alcance preservado

No se modifican:

- `includes/api/eventosapp-kiosk-api.php` ni sus rutas históricas;
- `includes/api/eventosapp-mobile-staff-checkin-api.php` ni el check-in online;
- callbacks o UI de `includes/frontend/eventosapp-qr-checkin.php`;
- Kiosko, búsqueda, escarapelas, impresión o cola Bluetooth;
- permisos globales, matriz por rol, acceso personalizado o alcance de eventos;
- creación/edición de tickets, consumibles, networking, expositores o sorteo;
- cola central de tareas ni las correcciones de `1.5.0-rc.20`;
- UI, Light/Dark Mode, aislamiento de Elementor, página 404 o layout boxed existente.

## Validación requerida

Antes de promover `1.5.0-rc.21` a producción:

1. Confirmar que el índice REST contiene `offline-snapshot` y `offline-sync`.
2. Iniciar sesión con un usuario con permiso `qr` y descargar un evento desde Android 2.7.0.
3. Verificar que la cantidad de tickets descargados coincide con el evento y que tickets eliminados no aparecen.
4. Activar modo avión y registrar un QR válido.
5. Repetir el mismo QR en el mismo dispositivo y confirmar que Android no crea otro pendiente.
6. Cerrar y abrir la app sin conexión y confirmar que el pendiente persiste.
7. Recuperar internet y confirmar sincronización automática.
8. Revisar `_eventosapp_checkin_status` y `_eventosapp_checkin_log`; el log debe incluir `origen=android_staff_qr_offline_sync` y `client_operation_id`.
9. Reenviar el mismo UUID y confirmar respuesta idempotente sin nuevo log duplicado.
10. Marcar primero el ticket desde otro dispositivo y luego sincronizar el pendiente offline; debe responder `already=true`.
11. Probar dos sincronizaciones concurrentes del mismo ticket/fecha y confirmar que solo una actualiza métricas como primer ingreso.
12. Revocar el permiso `qr` al usuario y confirmar que snapshot/sync responden `403`.
13. Activar control de pago, dejar un ticket no pagado y confirmar que no puede sincronizarse como ingreso válido.
14. Probar QR Legacy, preimpreso, Email, Google Wallet, Apple Wallet, PDF, WhatsApp y Badge disponibles en el evento.
15. Confirmar que Kiosko, impresión y Check-in QR online continúan funcionando sin cambios.

## Estado acumulado reciente

- **1.5.0-rc.21:** API offline Staff QR, snapshot SHA-256, sincronización idempotente y control de concurrencia.
- **1.5.0-rc.20:** gutter unificado de páginas, diagnóstico limpio persistente, recuperación de reinstalación y notificación terminal descartable.
- **1.5.0-rc.19:** página 404 corporativa, Light/Dark y aislamiento Elementor/tema.
- **1.5.0-rc.18:** Dashboard boxed nativo de `1200px`.
- **1.5.0-rc.17:** reinstalación/normalización canónica de páginas y limpieza de residuos Elementor.
- **1.5.0-rc.16:** Expositores — identidad corporativa, Light/Dark y aislamiento Elementor/tema.
- **1.5.0-rc.15:** Asistencia, Ranking de Networking y Sorteo administrativo — compatibilidad visual final.

## Regla de promoción

Este repositorio continúa siendo el entorno de pruebas. `1.5.0-rc.21` no debe promoverse a `theleadpartner/EventosApp` hasta validar en WordPress real la descarga del snapshot, operación en modo avión, persistencia local, sincronización al recuperar internet, idempotencia, concurrencia y revocación de permisos.
