# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

> **Historial preservado:** el estado detallado completo hasta `1.5.0-rc.19` se conserva en [`docs/history/README-through-1.5.0-rc.19.md`](README-through-1.5.0-rc.19.md). Los snapshots anteriores continúan disponibles en `docs/history/`.

## Estado del ciclo actual

- **Versión candidata:** `1.5.0-rc.20`
- **Fecha de corte:** 2026-08-10
- **Base integrada:** `9dd985c36d0d12ab5a69b1bb14b29b77e6a79bdc` (`main`, documentación final de `1.5.0-rc.19`)
- **Rama de trabajo:** `fix/install-layout-status-queue-20260810`
- **Destino de promoción:** `theleadpartner/EventosApp`
- **Estado:** corrección integral del flujo de reinstalación limpia: separación visual uniforme bajo el header para páginas gestionadas, evaluación persistente del estado canónico después de recargar, recuperación del worker de reinstalación cuando REST loopback/WP-Cron no despiertan a tiempo y control para borrar la notificación de progreso una vez terminada o cancelada sin eliminar el historial técnico de la tarea.

## Hotfix 1.5.0-rc.20 — Layout unificado, diagnóstico estable y recuperación de reinstalación

### 1. Separación uniforme debajo del header de EventosApp

La normalización limpia de `1.5.0-rc.17` elimina correctamente cualquier contenedor externo de Elementor y deja cada página con su shortcode canónico. Como consecuencia, varios módulos dependían todavía del margen/padding que antes aportaba el builder y quedaban visualmente pegados al cabezote propio de EventosApp. El Dashboard no sufría el problema porque `1.5.0-rc.18` ya le había agregado un gutter nativo.

Se actualizó `templates/eventosapp-app-page.php` para que **todas las páginas gestionadas por EventosApp** reserven desde el template de aplicación el mismo espacio vertical y horizontal, independientemente de Elementor:

- escritorio: `clamp(12px, 1.4vw, 20px)` arriba, `16px` laterales y `clamp(24px, 2.4vw, 36px)` abajo;
- móvil hasta `767px`: `12px` uniformes;
- el Dashboard conserva su ancho boxed de `1200px` y su centrado nativo;
- no se vuelve a introducir ningún wrapper de Elementor ni contenido adicional en las páginas.

El espacio deja de depender del shortcode concreto y queda definido una sola vez en el shell de aplicación.

### 2. El evaluador ya no vuelve a marcar una página limpia como dañada al recargar

El diagnóstico de `eventosapp-configuracion-clean-policy.php` comparaba el estado limpio contra **cualquier** metadato `_elementor_*`. Esto era demasiado estricto para Elementor, porque el builder puede regenerar metadatos de cache al visitar una página aun cuando ya no exista ningún widget, contenedor ni documento Elementor activo. El resultado era el observado: inmediatamente después de reinstalar aparecía `Correcto`, pero una recarga podía volver a mostrar `Mapeada · requiere limpieza`.

La política de `1.5.0-rc.20` separa ahora dos conceptos:

- **limpieza física:** al reinstalar se siguen eliminando todos los metadatos `_elementor_*` y cualquier template Elementor asociado;
- **salud canónica:** al evaluar después de una recarga solo invalidan la página los residuos capaces de cambiar el documento renderizado: `_elementor_data` con contenido real, `_elementor_edit_mode=builder` o un template de página Elementor.

Además, la comparación del `post_content` normaliza únicamente saltos de línea y espacios exteriores inocuos. Sigue sin aceptar HTML, bloques, widgets o contenido extra: el único contenido funcional permitido continúa siendo el shortcode canónico de la definición.

### 3. Recuperación del automatizador de reinstalación

La cola central ya dispone de REST loopback y respaldo WP-Cron, pero en el entorno reportado la tarea `eventosapp_install_pages` podía permanecer en `queued` sin procesar ningún lote. Para no modificar el comportamiento de otras tareas masivas o programadas se añadió una recuperación **exclusiva** de la reinstalación de páginas en:

```text
includes/admin/eventosapp-configuracion-queue-recovery.php
```

La nueva capa mantiene a `includes/functions/eventosapp-task-queue-core.php` como autoridad de estados, locks, cursores, métricas y logs, pero incorpora tres mecanismos adicionales para esta tarea concreta:

1. Al crear `eventosapp_install_pages`, inicia un worker alterno firmado mediante `admin-ajax.php`, con un timeout de conexión razonable y sin bloquear la respuesta del administrador.
2. El worker alterno procesa exactamente los mismos lotes mediante `eventosapp_task_queue_process_task()`, respeta el lock central y se autoencadena usando `next_run_at` hasta llegar a un estado terminal.
3. Si el hosting bloquea también ese loopback, abrir **Configuración** o **Cola y Tareas**, y especialmente el polling de progreso que ya existía cada 2,5 segundos, intenta un lote seguro antes de devolver el estado. De esta forma una tarea vieja que quedó detenida puede recuperarse sin recrearla.

Un transient por tarea serializa el fallback para impedir cadenas paralelas. Si el worker normal REST/cron sí está activo, el lock central evita cualquier procesamiento duplicado.

### 4. La notificación de progreso terminada o cancelada se puede borrar

La tarjeta azul de progreso de Configuración permanecía visible porque `eventosapp_installation_task_id` seguía apuntando a la última tarea aunque ya estuviera `completed`, `failed`, `cancelled`, `expired` o `archived`.

Ahora, cuando la tarea ya no está activa, la tarjeta muestra **Borrar notificación**. La acción:

- exige `manage_options` y nonce;
- no está disponible mientras la tarea siga activa o pausada;
- elimina únicamente el puntero visual `eventosapp_installation_task_id`;
- **no elimina la tarea, logs, métricas ni historial técnico** de Cola y Tareas.

Si se necesita eliminar el registro técnico completo, se mantiene la acción existente de eliminación dentro de **Cola y Tareas**.

## Archivos de 1.5.0-rc.20

```text
templates/eventosapp-app-page.php                              MODIFICADO — gutter común para todas las páginas app y boxed del Dashboard preservado
includes/admin/eventosapp-configuracion-clean-policy.php      MODIFICADO — estado canónico estable y distinción entre cache y residuos renderizables de Elementor
includes/admin/eventosapp-configuracion-queue-recovery.php    NUEVO — recuperación específica del worker y borrado de notificación terminal
includes/admin/eventosapp-configuracion.php                   MODIFICADO — carga de la nueva capa de recuperación
docs/history/README-through-1.5.0-rc.19.md                    NUEVO — snapshot íntegro del ciclo anterior
README.md                                                      MODIFICADO — versión, alcance, diagnóstico y validación
```

## Commits funcionales

```text
96c3c1cf145842e04621e694bb47d6d16738ff90  fix: stabilize clean installation status
4689a3f4b51d6abe7584bc162fbcd83cf16e9fb9  fix: unify managed page spacing below app header
1599dbc9ad9efe8059ff59991a785ed4a1516789  fix: recover installation queue and dismiss finished progress
18050007b52f2bf467e43ffbf824012b1aafd311  fix: serialize fallback installation workers
a17c82268540e7142aedd5cbf75d02315be69298  feat: load installation queue recovery layer
0843c3af24de458668d8f03bcb7ab4435ef037d1  docs: preserve detailed history through rc19
```

## Alcance preservado

No se modifican:

- callbacks ni lógica funcional de los shortcodes;
- permisos, nonces, autenticación o seguridad de los módulos;
- lógica de creación/actualización de asistentes, check-in, métricas, consumibles, networking, expositores o sorteo;
- estructura de tablas de la cola central;
- procesamiento de tareas diferentes a `eventosapp_install_pages`;
- historial, métricas o logs de una tarea al borrar solamente su notificación;
- el aislamiento visual frente a Elementor/tema ni los hotfix Light/Dark ya integrados;
- la página 404 corporativa de `1.5.0-rc.19`;
- el boxed nativo del Dashboard de `1.5.0-rc.18`.

## Validación requerida en WordPress

Antes de promover `1.5.0-rc.20`:

- Abrir Dashboard y varios módulos operativos y confirmar el mismo espacio bajo el header, sin contenido pegado al cabezote y sin scroll horizontal.
- Confirmar que el Dashboard continúa centrado y limitado a `1200px`.
- Reinstalar en limpio una página que tenía Elementor, verificar `Correcto`, recargar varias veces y confirmar que permanece `Correcto` aunque Elementor regenere caches internos.
- Volver a introducir contenido Elementor real en una página gestionada y confirmar que el evaluador vuelve a exigir limpieza.
- Lanzar **Reinstalar / normalizar todas en segundo plano** y confirmar que `processed_items` avanza desde `0`, que la tarea completa los 28 elementos y que el detalle de Cola y Tareas registra los lotes.
- Probar la recuperación con la pantalla de Configuración abierta y confirmar que el polling hace avanzar una tarea previamente detenida.
- Cancelar una reinstalación, volver a Configuración y confirmar que aparece **Borrar notificación**.
- Completar una reinstalación y confirmar la misma acción. Al borrarla, verificar que la tarjeta desaparece pero el registro sigue disponible en Cola y Tareas.
- Confirmar que otras tareas masivas/programadas mantienen su comportamiento anterior.
- Revisar 320px, 375px, 430px, tablet, 1366px, 1440px y 1920px en Light/Dark Mode.

## Estado acumulado reciente

- **1.5.0-rc.20:** gutter unificado de páginas, diagnóstico limpio persistente, recuperación de la reinstalación y notificación terminal descartable.
- **1.5.0-rc.19:** página 404 corporativa, Light/Dark y aislamiento Elementor/tema.
- **1.5.0-rc.18:** Dashboard boxed nativo de `1200px`.
- **1.5.0-rc.17:** reinstalación/normalización canónica de páginas y limpieza de residuos Elementor.
- **1.5.0-rc.16:** Expositores — identidad corporativa, Light/Dark y aislamiento Elementor/tema.
- **1.5.0-rc.15:** Asistencia, Ranking de Networking y Sorteo administrativo — compatibilidad visual final.
- **1.5.0-rc.14:** estados de Transacciones de Consumibles en Dark Mode.
- **1.5.0-rc.13:** Checklist, Edición y Consumibles.
- **1.5.0-rc.12:** Búsqueda Manual y Check-In Facial.

## Regla de promoción

Este repositorio sigue siendo el entorno de pruebas. `1.5.0-rc.20` no debe promoverse a `theleadpartner/EventosApp` hasta validar en WordPress real el espaciado común, la persistencia del diagnóstico después de recargar, el avance real de la reinstalación masiva y el borrado independiente de su notificación terminal.
