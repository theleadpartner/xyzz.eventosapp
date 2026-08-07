# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

## Estado del ciclo actual

- **Versión candidata:** `1.4.0-rc.1`
- **Fecha de corte:** 2026-08-07
- **Commit candidato de pruebas:** `8a7e1717351790ae2117120a8b4157e8895df46f`
- **Base inmediatamente anterior:** `3975588693b03b1ed3643d3d6632897f791aeabb` (`1.3.0-rc.2`)
- **Rama de documentación:** `docs/consumables-transactions-progress-20260807`
- **Destino de promoción:** `theleadpartner/EventosApp`
- **Rama de promoción en producción:** `sync/consumables-transactions-audit-20260807`
- **Estado:** candidato identificado y acotado a dos archivos de Consumibles. La promoción a producción se realiza mediante lista cerrada, validación de sintaxis y verificación de hashes antes de documentar la rama de destino.

## Candidato 1.4.0-rc.1 — Transacciones y auditoría de Consumibles

El commit `8a7e1717351790ae2117120a8b4157e8895df46f` es el único commit de `main` posterior al último estado de pruebas ya sincronizado con producción. La comparación contra `3975588693b03b1ed3643d3d6632897f791aeabb` arroja exactamente dos archivos pendientes:

```text
includes/functions/eventosapp-consumables-transactions.php  54b8989b3890099d008f46929230bdc96ccfa0e8  NUEVO
includes/functions/eventosapp-consumables.php               638d7d4ac9a38d9c26e5c4cec6a535d5881e30be  MODIFICADO
```

El núcleo existente `includes/functions/eventosapp-consumables-core.php` conserva el blob `4f8c9e04fe28c1ffec2123505ae63a6bff6c639c`, idéntico al núcleo de producción, por lo que no forma parte de esta promoción.

### Control de Consumibles — Transacciones

- Nueva pestaña **Transacciones** para Administradores y Organizadores con permiso `consumables_manage`.
- Consulta de transacciones agrupadas por lectura/lote, con estado activo, cancelación solicitada, anulación parcial o anulación completa.
- Filtros por búsqueda general, ítem, Staff, cédula, localidad, fecha, rango horario y estado.
- Resumen general por ítem con cantidades brutas, anuladas, pendientes y netas.
- Exportación CSV completa del ledger del evento, incluyendo movimientos originales, solicitudes de cancelación y reversos.
- El CSV incluye ticket, nombre, apellido, cédula, localidad, configuraciones asignadas/aplicadas, ítem, cantidad, Staff, fecha, hora, periodo, saldo posterior, origen y nota de auditoría.
- Anulación administrativa de una transacción con restauración de saldos mediante transacción SQL y bloqueo de filas.
- Los movimientos originales nunca se eliminan; el reverso se registra como una nueva entrada del ledger.

### Consumo de Consumibles — Mis transacciones

- Nueva pestaña **Mis transacciones** para Staff y Logístico con permiso `consumables_staff`.
- Consulta limitada a las transacciones registradas por el usuario autenticado en el evento activo.
- Sumatoria neta de artículos entregados por el usuario.
- Solicitud de cancelación de una transacción propia para revisión del administrador.
- Las solicitudes no restauran saldo por sí mismas y quedan registradas en el ledger.

### Landing presencial del asistente

- El inventario público conserva la integración segura introducida en `1.3.0-rc.2`.
- Se agrega **Movimientos de mi inventario**, organizado por día del evento.
- Cada movimiento muestra hora, artículos y estado de consumo o anulación.
- Las anulaciones permanecen visibles como parte de la trazabilidad.
- Los tickets virtuales continúan explícitamente excluidos.

### Seguridad e integridad

- Los endpoints AJAX exigen sesión, nonce, evento activo y permiso específico por función.
- Staff solo puede solicitar cancelación de transacciones creadas por su propio usuario.
- Administradores/Organizadores con `consumables_manage` son quienes pueden ejecutar el reverso.
- El reverso usa `START TRANSACTION`, `SELECT ... FOR UPDATE`, actualización condicionada del saldo y `ROLLBACK` ante cualquier inconsistencia.
- Los identificadores de solicitud/reverso son determinísticos y el índice único de `request_uuid` preserva la idempotencia.
- La promoción no reemplaza `eventosapp-consumables-core.php` ni modifica módulos ajenos a Consumibles.

## Hotfix 1.3.0-rc.2 — Landing pública del ticket

### Incidencia corregida

La landing pública `/ticket/?ticket=...` podía quedar completamente en blanco al abrir el botón **Ver mi ticket** de WhatsApp. La integración inicial del inventario de Consumibles activaba un búfer de salida con callback y, desde ese callback, intentaba generar el bloque de inventario mediante otro `ob_start()`. PHP no permite iniciar un nuevo búfer mientras se ejecuta un manejador de salida.

### Solución aplicada

- La implementación completa de Consumibles se conserva sin eliminar funciones en `eventosapp-consumables-core.php`.
- `eventosapp-consumables.php` actúa como cargador seguro y define únicamente la integración corregida antes de incluir el núcleo.
- El HTML del inventario se calcula antes de iniciar el búfer.
- El callback del búfer se limita a insertar una cadena ya preparada; no consulta datos, no genera plantillas y no abre otro búfer.
- La intervención queda restringida a la landing presencial `/ticket/` y a la ruta heredada equivalente.
- Los tickets virtuales quedan excluidos antes de generar inventario.
- La landing `/virtual/` se restauró a su implementación independiente anterior a Consumibles.
- No se modificaron el envío de WhatsApp, las plantillas de Meta, Wallet, PDF, ICS ni el registro de check-in virtual.

### Archivos del hotfix

```text
includes/functions/eventosapp-consumables.php
includes/functions/eventosapp-consumables-core.php
includes/frontend/eventosapp-virtual-landing-widget.php
README.md
```

## Alcance conservado de 1.3.0

### Control de Consumibles

- Configuración de inventarios por evento con múltiples consumibles y cantidades.
- Segmentación para todos los asistentes, por localidad o por campo personalizado.
- Inventario único para todo el evento o reinicio por cada día configurado.
- Inventario calculado por ticket y registro transaccional de consumos.
- Selección simultánea de varios consumibles y cantidades antes de leer el QR.
- Descuento atómico: si una línea no tiene saldo, no se descuenta ninguna.
- Protección frente a lecturas duplicadas mediante identificadores idempotentes.
- Visualización del inventario en la landing pública del ticket presencial.
- Exclusión explícita del inventario en tickets y experiencias virtuales.

### Permisos y alcance por evento

- Administrador y Organizador: acceso a **Control de Consumibles** y **Consumo de Consumibles**.
- Staff y Logístico: acceso únicamente a **Consumo de Consumibles**.
- Excepciones individuales configurables por usuario y evento.
- Las configuraciones personalizadas antiguas heredan las funciones nuevas cuando no contienen una decisión explícita.
- Validación final del alcance del evento para impedir que permisos globales reabran eventos no asignados.
- Co-gestión, Staff operativo, Equipo de apoyo y Expositores conservan sus alcances independientes.

### Co-gestión y Staff operativo

- Selección múltiple de usuarios Staff y Logístico.
- Asignación a varios eventos simultáneamente.
- Vencimiento configurable o permisos sin fecha de expiración.
- Actualización sincronizada del alcance guardado en el evento y en el usuario.
- Eliminación individual o masiva de asignaciones.
- Limpieza automática de permisos vencidos.

### Kiosko Android y QR

- API de Kiosko Android actualizada a `1.3.0`.
- Configuración de autenticación QR, escrita o combinada.
- Opción de impresión automática después de validar el QR.
- La lectura QR resuelve el ticket sin alterar los flujos de confirmación que siguen siendo necesarios.

## Estado de promociones previas

### `1.3.0-rc.2`

La corrección de la landing pública ya fue integrada en `main` de producción mediante el commit de merge `08aabcf1a8667cfe56a536b14edfd591202d02d3`.

### `1.3.0-rc.1`

La comparación previa identificó seis archivos pendientes y fueron promovidos conservando los blobs validados del repositorio de pruebas:

```text
includes/admin/eventosapp-access-staff-control-event.php  39db9c0ceaa5d648f9c0a67cfd45b8bf1742edad
includes/admin/eventosapp-co-gestion.php                  27d0164a3e50ff3f16de1a952d82d51827426add
includes/admin/eventosapp-configuracion.php               c376ef77faefb6b151cdd8014c9f6e16d513e4d0
includes/api/eventosapp-kiosk-api.php                     d30efe9d9623e578873fc264f948737b2c19c705
includes/frontend/eventosapp-virtual-landing-widget.php   c81e1682b602aedc749597b77f0ec0af77d89527
includes/functions/eventosapp-consumables.php             4f8c9e04fe28c1ffec2123505ae63a6bff6c639c
```

## Procedimiento de promoción

1. Leer el README de pruebas y el README/CHANGELOG de producción.
2. Definir un commit exacto de origen en este repositorio.
3. Comparar el origen contra el último commit de pruebas documentado en producción.
4. Verificar si producción contiene cambios propios en los archivos candidatos.
5. Crear una rama desde `main` de producción.
6. Copiar únicamente los archivos pendientes mediante lista cerrada.
7. Confirmar igualdad byte a byte mediante el SHA de cada blob.
8. Comparar la rama completa contra `main` para detectar archivos fuera de alcance.
9. Probar la rama antes de abrir y fusionar el pull request.
10. Actualizar README, CHANGELOG y versión del repositorio de producción.

## Validación funcional requerida antes de producción

### Transacciones y auditoría 1.4.0-rc.1

- Generar consumos de uno y varios artículos y confirmar su agrupación como una sola transacción por lectura.
- Verificar la pestaña **Transacciones** con Administrador y Organizador.
- Probar búsqueda y filtros por ítem, Staff, cédula, localidad, fecha, hora y estado.
- Descargar el CSV y confirmar que contiene tanto consumos como solicitudes de cancelación y reversos.
- Verificar el resumen bruto, anulado, pendiente y neto por ítem.
- Iniciar sesión como Staff/Logístico y confirmar que **Mis transacciones** solo muestra operaciones propias.
- Solicitar la cancelación de una transacción propia y comprobar que todavía no restaura el saldo.
- Confirmar que otro Staff no puede solicitar la cancelación de una transacción ajena.
- Anular la transacción desde Control de Consumibles y confirmar que el saldo se restablece exactamente una vez.
- Repetir el intento de anulación y confirmar que no genera un segundo reverso.
- Confirmar que el consumo original y el reverso continúan visibles en auditoría y CSV.
- Revisar la landing presencial y confirmar el historial por día, hora, artículos y estado.
- Confirmar que la landing virtual no muestra ni calcula inventario o historial de Consumibles.

### Hotfix de landing 1.3.0-rc.2

- Abrir `/ticket/?ticket={ID_PUBLICO}` para un ticket presencial con Consumibles activo.
- Confirmar que la landing carga QR, datos del evento, Wallet, PDF e ICS como antes.
- Confirmar que el inventario se muestra antes del bloque de acciones.
- Abrir un ticket presencial sin inventario asignado y comprobar el mensaje de segmentación.
- Abrir `/virtual/{evento}?ticket_pub={ID_PUBLICO}` para un ticket virtual.
- Confirmar que la landing virtual conserva el botón de acceso y el registro de check-in virtual.
- Confirmar que la landing virtual no muestra ni calcula inventario de Consumibles.
- Revisar el log PHP y confirmar que no aparece el error de búfer de salida anidado.

### Regresión del módulo base

- Crear, editar, ordenar y eliminar configuraciones de inventario.
- Verificar segmentación por localidad y campo personalizado.
- Confirmar inventario único y reinicio diario.
- Probar descuento de un artículo, varios artículos y varias cantidades.
- Confirmar que una falta de saldo impide todo el descuento del lote.
- Confirmar que repetir la misma lectura no genera descuentos adicionales.
- Probar permisos de Administrador, Organizador, Staff y Logístico.
- Probar una excepción individual de acceso por usuario.
- Validar usuarios con configuraciones personalizadas creadas antes de Consumibles.
- Probar asignaciones y vencimientos desde Co-gestión.
- Probar autenticación QR e impresión automática en el Kiosko Android.
- Confirmar creación de las tablas de balance y ledger en una instalación controlada.

## Historial resumido

### `1.4.0-rc.1` — 2026-08-07

Candidato con consulta y exportación de transacciones, resumen por ítem, vista de transacciones propias del Staff, solicitudes de cancelación, reversos auditables con restauración de saldo e historial de movimientos en la landing presencial.

### `1.3.0-rc.2` — 2026-08-06

Hotfix de la landing pública del ticket presencial: eliminación del búfer anidado que dejaba la página en blanco, restricción de la integración de Consumibles a `/ticket/` y restauración de la independencia de la landing virtual.

### `1.3.0-rc.1` — 2026-08-06

Candidato de promoción con la versión final del módulo de Consumibles, política de permisos por rol y usuario, mejoras de Co-gestión y actualización de la API del Kiosko Android.

### `1.2.0` — 2026-08-05

Base previamente promovida a producción desde el commit `048a91e1dc9c1bd9bf92a5a96870672e85d7de8e`, centrada en Kiosko Android, Staff QR, diagnóstico y permisos por evento.
