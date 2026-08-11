# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

> **Historial preservado:** `rc.25` incorporó Consumo de Consumibles; `rc.24` incorporó Localidad, Sesiones y Doble Auth; `rc.23` introdujo el paquete offline único por evento; `rc.22` el offline completo de Kiosko y `rc.21` el offline Staff QR.

## Estado actual: candidato 1.5.0-rc.26

La entrega **1.5.0-rc.26** endurece la API móvil para eventos de **3.000 a 5.000 asistentes**, manteniendo intactos los contratos online y offline anteriores.

Datos de corte:

- **Fecha:** 2026-08-11
- **Rama:** `perf/mobile-offline-scale-rc26`
- **Base:** `main` con `1.5.0-rc.25`
- **Android objetivo:** `theleadpartner/eventosapp-printer-android` **2.11.1** (`versionCode 33`)
- **Destino posterior:** `theleadpartner/EventosApp` únicamente después de validación real.

## Resultado de la auditoría de escala

La arquitectura existente ya tenía bases correctas para concurrencia:

- Staff y Kiosko sincronizan con UUID/idempotencia;
- ambos usan bloqueo por ticket/día para evitar carreras entre tablets;
- QR avanzado admite lotes de hasta 500;
- Consumibles usa transacciones SQL, bloqueo de filas y request UUID;
- Android envía operaciones en lotes de 200 y serializa la sincronización por dispositivo.

Los riesgos encontrados estaban principalmente en la **preparación del paquete offline**, no en la capacidad de SQLite para almacenar 5.000 asistentes:

1. el paquete consultaba/paginaba el conjunto de tickets nuevamente en cada request;
2. la configuración estática de módulos se reconstruía en todas las páginas;
3. Kiosko podía volver a leer y codificar repetidamente logos/fondos compartidos para muchas escarapelas;
4. Consumibles no tenía el mismo límite defensivo de tamaño de lote que Staff/Kiosko/QR avanzado.

rc.26 corrige estos puntos.

## Paquete offline único 1.3.0

Archivo:

```text
includes/api/eventosapp-mobile-event-offline-api.php
```

Versión interna:

```text
EVENTOSAPP_MOBILE_EVENT_OFFLINE_API_VERSION = 1.3.0
```

Ruta:

```text
GET /wp-json/eventosapp-kiosk/v1/events/{event_id}/offline-package
```

### Modo optimizado con snapshot estable

Android 2.11.1 solicita:

```text
snapshot=1
per_page=100
```

La primera página:

1. resuelve una sola vez los IDs de tickets del evento;
2. crea un UUID `snapshot_id` ligado al evento y usuario;
3. guarda únicamente la lista de IDs en un transient temporal;
4. devuelve la primera página y el `snapshot_id`.

Las páginas siguientes envían:

```text
snapshot_id=<uuid>
```

y usan `array_slice()` sobre el mismo conjunto de IDs. Esto evita repetir la consulta paginada/conteo y evita saltos o duplicados si el conjunto de tickets cambia mientras una tablet está descargando.

El snapshot vence a las **2 horas**. Si ya no existe o no corresponde al evento/usuario, la API responde `offline_snapshot_expired` con HTTP 409 para que Android reinicie la descarga una sola vez.

### Compatibilidad hacia atrás

El modo optimizado solo se activa cuando el cliente envía `snapshot=1` o `snapshot_id`.

Clientes Android anteriores que no envían esos parámetros mantienen el flujo paginado legado, incluyendo el mismo contrato de respuesta y las rutas existentes.

### Configuración estática una sola vez

En modo optimizado, `event`, configuración de Kiosko, configuración QR avanzada y configuración de Consumibles se construyen en la primera página. Las páginas siguientes transportan principalmente `tickets`.

Esto reduce CPU, serialización JSON y tamaño de respuesta sin afectar a Android 2.11.1, cuyo instalador toma la configuración desde la primera página.

## Módulos dentro del paquete

`modules` puede contener:

```text
staff_qr
kiosk
qr_localidad
qr_session
qr_double_auth
consumables_staff
```

Bloques:

```text
module_data.staff_qr
module_data.kiosk
module_data.advanced_qr
module_data.consumables
```

Los QR continúan entregándose como hashes SHA-256; no se agrega QR en texto claro al snapshot.

## Optimización de Kiosko para eventos grandes

Archivo:

```text
includes/api/eventosapp-mobile-kiosk-offline-api.php
```

La función que convierte imágenes a `data:` ahora mantiene un cache por request **solo para URLs que realmente se repiten** y con máximo 32 entradas.

Consecuencia:

- logos/fondos compartidos dejan de leerse y codificarse cientos de veces dentro de una misma página;
- recursos únicos por asistente no llenan el cache;
- se mantiene el límite existente de 2 MB por recurso.

Android 2.11.1 complementa esta mejora escribiendo inmediatamente las escarapelas Base64 a staging en disco, evitando retener miles de HTML en memoria.

## Sincronización masiva

La APP continúa enviando lotes de **200 operaciones**.

Límites defensivos del backend:

```text
Staff offline        <= 500 por request
Kiosko offline       <= 500 por request
QR avanzado offline  <= 500 por request
Consumibles offline  <= 500 por request
```

rc.26 agrega la defensa de 500 a Consumibles antes de ejecutar su callback. Esto evita que un cliente defectuoso intente procesar miles de operaciones dentro de una sola ejecución PHP.

### Staff y Kiosko

La sincronización conserva:

- `client_operation_id`;
- idempotencia por operación;
- lock temporal por evento/ticket/día;
- resolución segura cuando otro dispositivo ya marcó el mismo ticket.

### QR avanzado

Mantiene el límite de 500 por request y las validaciones específicas de Sesiones/Doble Auth. Android 2.11.1 corrige además el cliente para no detener una cola avanzada en los primeros 200 pendientes.

### Consumibles

Se preserva el motor administrativo/contable existente:

```text
includes/functions/eventosapp-consumables-core.php
includes/functions/eventosapp-consumables-transactions.php
includes/functions/eventosapp-consumables.php
```

La API continúa usando transacciones, bloqueo de balances y request UUID. Una operación se vuelve a validar contra el saldo real del servidor al sincronizar.

## API móvil de Consumibles preservada

```text
GET  /wp-json/eventosapp-kiosk/v1/mobile/consumables/events
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/consumables/consume
GET  /wp-json/eventosapp-kiosk/v1/events/{event_id}/consumables/transactions
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/consumables/cancel-request
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/consumables/offline-sync
```

Se conservan selección previa, operaciones atómicas multiítem, historial por staff y solicitud administrativa de cancelación.

## Rutas históricas preservadas

```text
GET  /wp-json/eventosapp-kiosk/v1/staff/events/{event_id}/offline-snapshot
POST /wp-json/eventosapp-kiosk/v1/staff/events/{event_id}/offline-sync
GET  /wp-json/eventosapp-kiosk/v1/events/{event_id}/offline-snapshot
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/offline-sync
POST /wp-json/eventosapp-kiosk/v1/events/{event_id}/advanced-qr/offline-sync
```

Kiosko, impresión, Staff QR, Localidad, Sesiones, Doble Auth y Consumibles mantienen sus objetivos y contratos funcionales.

## Archivos de rc.26

Modificados:

```text
includes/api/eventosapp-mobile-event-offline-api.php
includes/api/eventosapp-mobile-kiosk-offline-api.php
README.md
```

No se modifica la administración de eventos, tickets, consumibles, balances, ledger ni reversión administrativa.

## Validación de escala requerida

Antes de promover rc.26:

1. Crear un evento de prueba con 5.000 tickets.
2. Habilitar simultáneamente Kiosko, Staff QR, Localidad, Sesiones, Doble Auth y Consumibles.
3. Descargar con Android 2.11.1 y comprobar 50 páginas de 100.
4. Confirmar que todas las páginas comparten el mismo `snapshot_id`.
5. Modificar el conjunto de tickets durante la descarga y comprobar que el snapshot no duplica/omite IDs.
6. Forzar expiración/invalidación del snapshot y comprobar reinicio controlado del cliente.
7. Medir tiempo y memoria de generación de Kiosko con escarapelas reales.
8. Generar más de 200 operaciones QR avanzadas offline y comprobar sincronización completa.
9. Sincronizar lotes amplios de Staff/Kiosko/Consumibles desde varias tablets.
10. Repetir UUIDs y confirmar idempotencia.
11. Probar consumos concurrentes del mismo saldo desde diferentes tablets.
12. Ejecutar regresión de impresión y módulos existentes.
13. Medir PHP-FPM, MySQL, CPU, memoria, latencias p95/p99 y errores 5xx/429 durante concurrencia.

## Interpretación de capacidad

Con rc.26 + Android 2.11.1, **5.000 asistentes ya no representan un problema estructural por volumen de dataset**: el paquete se pagina, el conjunto se fija una vez y la sincronización está batcheada e idempotente.

La cantidad de tablets concurrentes que una instalación puede sostener no puede fijarse únicamente desde el código del plugin. Depende también de la capacidad del servidor, configuración de PHP-FPM, MySQL, cache de objetos, CPU, memoria y ancho de banda. Por eso la promoción exige prueba de carga en una infraestructura equivalente a producción.

## Historial reciente

| Candidato | Cambio principal |
|---|---|
| **1.5.0-rc.26** | Hardening 3.000–5.000 asistentes: snapshot estable, payload estático único, cache Kiosko y límites defensivos. |
| **1.5.0-rc.25** | Consumibles móvil + historial/cancelación + offline unificado. |
| **1.5.0-rc.24** | Localidad, Sesiones y Doble Auth Android. |
| **1.5.0-rc.23** | Paquete offline único por evento. |
| **1.5.0-rc.22** | Offline completo de Kiosko. |
| **1.5.0-rc.21** | Offline de Staff QR. |

## Regla de promoción

`xyzz.eventosapp` sigue siendo el entorno de pruebas. **No promover rc.26 a producción hasta completar validación real WordPress + Android y una prueba de carga representativa de 5.000 asistentes/múltiples tablets.**
