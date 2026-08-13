# EventosApp 1.5.0-rc.30 — hardening del contexto de impresión Kiosko Android

Fecha: 2026-08-13

## Incidente

Android 2.13.0 podía localizar correctamente un asistente desde Kiosko, Localidad y Check-in QR, pero el POST de impresión `POST /wp-json/eventosapp-kiosk/v1/tickets/{ticket_id}/print` devolvía `404 invalid_ticket` antes de generar la escarapela.

El diagnóstico de campo confirmó para el mismo ticket/evento:

- evento `15200`;
- ticket canónico `21054`;
- búsqueda Kiosko: 1 coincidencia válida;
- Check-in QR Staff: validación correcta;
- Localidad: validación correcta;
- endpoint Kiosko Print: `404 invalid_ticket`;
- impresora Bluetooth y trabajos locales previos: operativos.

Por tanto, el fallo estaba en el contexto REST que llega al callback de impresión, no en la lectura QR, el ticket almacenado, la generación local del trabajo ni Bluetooth.

## Causa técnica endurecida

La API base de Kiosko usa históricamente `$request['id']`. `WP_REST_Request` implementa ArrayAccess sobre parámetros combinados. Cuando distintas bolsas de parámetros comparten un nombre, el valor combinado puede no representar de forma inequívoca el parámetro capturado por la URL.

El archivo `includes/api/eventosapp-mobile-kiosk-feature-permission.php` ya extraía el ticket directamente de `get_route()` para resolver el contexto de permisos. rc.30 reutiliza esa fuente inequívoca para la operación `/tickets/{id}/print` y fuerza el ID de ruta antes del callback histórico.

## Cambio rc.30

Se agrega un `rest_pre_dispatch` específico y limitado a:

```text
/eventosapp-kiosk/v1/tickets/{ticket_id}/print
```

El filtro:

1. obtiene el ticket del patrón de ruta;
2. acepta `ticket_id` redundante enviado por Android y exige que coincida con la ruta;
3. acepta `event_id` redundante y exige que coincida con la relación real ticket → evento;
4. normaliza `id` mediante `WP_REST_Request::set_param()` antes de que `eventosapp_kiosk_api_print()` ejecute la validación histórica;
5. devuelve errores de conflicto explícitos si Android y la ruta no coinciden.

## Alcance preservado

No se reemplaza `eventosapp_kiosk_api_print()` ni se duplican sus reglas. Permanecen sin cambios:

- permisos por usuario/evento;
- control de ticket virtual;
- modalidad;
- fecha permitida;
- control de pago;
- lock ticket+día;
- check-in e historial;
- generación de badge/escarapela;
- firma y expiración de la URL de badge;
- configuración de papel;
- impresión Bluetooth en Android;
- QR Staff, Localidad, Sesiones, Doble Auth y Consumibles;
- modo offline y sincronización.

## Contrato Android recomendado

Android 2.13.1 debe mantener el ticket en la ruta y enviar también en JSON:

```json
{
  "request_id": "android-kiosk-...",
  "id": 21054,
  "ticket_id": 21054,
  "event_id": 15200
}
```

La redundancia es deliberada: la ruta sigue siendo la fuente canónica y el cuerpo permite comprobar que cliente, ticket y evento no perdieron contexto antes de imprimir.

## Validación

1. Kiosko QR: encontrar un ticket e imprimir automáticamente.
2. Kiosko QR: confirmar manualmente e imprimir.
3. Búsqueda textual: seleccionar asistente e imprimir.
4. Repetir con ticket que ya tenga check-in del día.
5. Confirmar que `Volver` no reactive una impresión consumida.
6. Confirmar que un `ticket_id` de body distinto al de la ruta devuelve conflicto y no imprime.
7. Confirmar que un `event_id` de body distinto al evento del ticket devuelve conflicto y no imprime.
8. Ejecutar regresión Staff QR, Localidad, Sesiones, Doble Auth, Consumibles y offline.
9. Ejecutar PHP lint completo.
