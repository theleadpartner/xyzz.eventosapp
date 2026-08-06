# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

## Estado del ciclo actual

- **Versión candidata:** `1.3.0-rc.2`
- **Fecha de corte:** 2026-08-06
- **Commit base de pruebas:** `47ac48e00ab90868d49ece5e2790fa1bca52eb9d`
- **Rama del hotfix:** `fix/whatsapp-ticket-consumables-landing-20260806`
- **Destino de promoción:** `theleadpartner/EventosApp`
- **Estado:** corrección aislada en rama independiente; requiere validación de la landing presencial y virtual antes de promoverse a producción.

## Hotfix de la landing pública del ticket

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

## Alcance de la versión candidata 1.3.0

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

## Archivos promovidos previamente a producción

La comparación entre el último punto documentado de producción y el candidato `1.3.0-rc.1` identificó seis archivos pendientes. Cada archivo fue copiado conservando el mismo SHA de blob del repositorio de pruebas:

```text
includes/admin/eventosapp-access-staff-control-event.php  39db9c0ceaa5d648f9c0a67cfd45b8bf1742edad
includes/admin/eventosapp-co-gestion.php                  27d0164a3e50ff3f16de1a952d82d51827426add
includes/admin/eventosapp-configuracion.php               c376ef77faefb6b151cdd8014c9f6e16d513e4d0
includes/api/eventosapp-kiosk-api.php                     d30efe9d9623e578873fc264f948737b2c19c705
includes/frontend/eventosapp-virtual-landing-widget.php   c81e1682b602aedc749597b77f0ec0af77d89527
includes/functions/eventosapp-consumables.php             4f8c9e04fe28c1ffec2123505ae63a6bff6c639c
```

El hotfix `1.3.0-rc.2` reemplaza únicamente la integración de landing de los dos últimos archivos antes de una nueva promoción.

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

### Hotfix de landing

- Abrir `/ticket/?ticket={ID_PUBLICO}` para un ticket presencial con Consumibles activo.
- Confirmar que la landing carga QR, datos del evento, Wallet, PDF e ICS como antes.
- Confirmar que el inventario se muestra antes del bloque de acciones.
- Abrir un ticket presencial sin inventario asignado y comprobar el mensaje de segmentación.
- Abrir `/virtual/{evento}?ticket_pub={ID_PUBLICO}` para un ticket virtual.
- Confirmar que la landing virtual conserva el botón de acceso y el registro de check-in virtual.
- Confirmar que la landing virtual no muestra ni calcula inventario de Consumibles.
- Revisar el log PHP y confirmar que no aparece el error de búfer de salida anidado.

### Módulo de Consumibles

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

### `1.3.0-rc.2` — 2026-08-06

Hotfix de la landing pública del ticket presencial: eliminación del búfer anidado que dejaba la página en blanco, restricción de la integración de Consumibles a `/ticket/` y restauración de la independencia de la landing virtual.

### `1.3.0-rc.1` — 2026-08-06

Candidato de promoción con la versión final del módulo de Consumibles, política de permisos por rol y usuario, mejoras de Co-gestión y actualización de la API del Kiosko Android.

### `1.2.0` — 2026-08-05

Base previamente promovida a producción desde el commit `048a91e1dc9c1bd9bf92a5a96870672e85d7de8e`, centrada en Kiosko Android, Staff QR, diagnóstico y permisos por evento.
