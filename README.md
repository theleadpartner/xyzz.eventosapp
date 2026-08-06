# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

## Estado del ciclo actual

- **Versión candidata:** `1.3.0-rc.1`
- **Fecha de corte:** 2026-08-06
- **Commit validado de pruebas:** `7eaa1ca363068bc7d6262f0ffedfb8c9c4912ce6`
- **Destino de promoción:** `theleadpartner/EventosApp`
- **Rama preparada en producción:** `sync/consumables-permissions-20260806`
- **Commit de sincronización en producción:** `4b424bad614cd1e27b97a49df17cc51b983def9b`
- **Estado:** código sincronizado en rama independiente; `main` de producción permanece sin cambios hasta revisión e integración.

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

## Archivos promovidos a producción

La comparación entre el último punto documentado de producción y este commit identificó exactamente seis archivos pendientes. Cada archivo se copió conservando el mismo SHA de blob de este repositorio:

```text
includes/admin/eventosapp-access-staff-control-event.php  39db9c0ceaa5d648f9c0a67cfd45b8bf1742edad
includes/admin/eventosapp-co-gestion.php                  27d0164a3e50ff3f16de1a952d82d51827426add
includes/admin/eventosapp-configuracion.php               c376ef77faefb6b151cdd8014c9f6e16d513e4d0
includes/api/eventosapp-kiosk-api.php                     d30efe9d9623e578873fc264f948737b2c19c705
includes/frontend/eventosapp-virtual-landing-widget.php   c81e1682b602aedc749597b77f0ec0af77d89527
includes/functions/eventosapp-consumables.php             4f8c9e04fe28c1ffec2123505ae63a6bff6c639c
```

`eventosapp.php` ya era idéntico entre pruebas y producción, por lo que no fue reemplazado.

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

- Crear, editar, ordenar y eliminar configuraciones de inventario.
- Verificar segmentación por localidad y campo personalizado.
- Confirmar inventario único y reinicio diario.
- Probar descuento de un artículo, varios artículos y varias cantidades.
- Confirmar que una falta de saldo impide todo el descuento del lote.
- Confirmar que repetir la misma lectura no genera descuentos adicionales.
- Validar la landing del ticket presencial y la exclusión en asistentes virtuales.
- Probar permisos de Administrador, Organizador, Staff y Logístico.
- Probar una excepción individual de acceso por usuario.
- Validar usuarios con configuraciones personalizadas creadas antes de Consumibles.
- Probar asignaciones y vencimientos desde Co-gestión.
- Probar autenticación QR e impresión automática en el Kiosko Android.
- Confirmar creación de las tablas de balance y ledger en una instalación controlada.

## Historial resumido

### `1.3.0-rc.1` — 2026-08-06

Candidato de promoción con la versión final del módulo de Consumibles, política de permisos por rol y usuario, mejoras de Co-gestión y actualización de la API del Kiosko Android.

### `1.2.0` — 2026-08-05

Base previamente promovida a producción desde el commit `048a91e1dc9c1bd9bf92a5a96870672e85d7de8e`, centrada en Kiosko Android, Staff QR, diagnóstico y permisos por evento.
