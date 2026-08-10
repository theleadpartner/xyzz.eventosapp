# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

> **Historial preservado:** el estado detallado completo hasta `1.5.0-rc.18` se conserva sin omitir avances anteriores en [`docs/history/README-through-1.5.0-rc.18.md`](docs/history/README-through-1.5.0-rc.18.md). El historial acumulado anterior hasta `1.5.0-rc.16` continúa disponible en [`docs/history/README-through-1.5.0-rc.16.md`](docs/history/README-through-1.5.0-rc.16.md).

## Estado del ciclo actual

- **Versión candidata:** `1.5.0-rc.19`
- **Fecha de corte:** 2026-08-10
- **Base integrada:** `1bac5e162538fe4e910b1777d104d15f182e4477` (`main`, merge de `1.5.0-rc.18`)
- **Rama de trabajo:** `fix/404-dark-branding-elementor-20260810`
- **Destino de promoción:** `theleadpartner/EventosApp`
- **Estado:** hotfix visual de la página 404 integrada. Se unifican imagen corporativa, Light/Dark Mode y aislamiento frente al CSS global de Elementor/tema sin modificar la lógica de seguridad, el estado HTTP 404 ni la navegación existente. Pendiente validación visual/funcional en WordPress antes de promover.

## Hotfix 1.5.0-rc.19 — Página 404 corporativa, Dark Mode y aislamiento Elementor

### Incidencia detectada

La página generada por `[eventosapp_404]` conservaba CSS inline histórico dentro de `includes/admin/eventosapp-security.php`. La capa corporativa general ya reconocía la 404 como una página gestionada y aplicaba algunos tokens, pero existían reglas incompatibles entre sí:

1. El contenedor raíz `.evapp-404` mantenía un fondo claro hardcodeado incluso cuando `data-evapp-theme="dark"` estaba activo.
2. La capa global convertía `.evapp-404-card` en una superficie oscura, por lo que en Dark Mode aparecía una tarjeta oscura dentro de un canvas todavía claro, tal como se observa en la referencia reportada.
3. El shortcode conservaba colores, tipografía, botones y estados hover con especificidad insuficiente frente a Elementor/tema.
4. El logo original podía depender de la imagen configurada en WordPress, mientras que la identidad corporativa actual de EventosApp exige usar de forma determinista los wordmarks oficiales del plugin.
5. El layout original tenía poco espacio interno cuando la tarjeta era remapeada por la capa global, lo que hacía que botones y contenido quedaran visualmente comprimidos o al límite del contenedor.

### Corrección aplicada

Se agregó una capa final y estrictamente visual en:

```text
includes/frontend/eventosapp-404-visual-compat.php
```

Esta capa se carga al final del stack visual desde `includes/frontend/eventosapp-frontend-helpers.php` y se imprime en `wp_footer` con prioridad `1012`, después de branding, Dark Mode, aislamiento general de Elementor y los hotfix de módulos ya integrados.

La activación queda limitada a la página 404 configurada en `eventosapp_security['error_404_page_id']`; como respaldo, también reconoce una página que contenga directamente `[eventosapp_404]`. No se aplica globalmente al resto de WordPress.

### Imagen corporativa

La 404 utiliza ahora los activos oficiales mediante `eventosapp_brand_asset_url()`:

- **Light Mode:** `eventosapp_color.svg`.
- **Dark Mode:** `eventosapp_blanco.svg`.

La imagen de logo que pudiera suministrar el sitio queda oculta dentro de esta superficie para impedir variaciones accidentales de marca. La ilustración de ticket/QR se mantiene, pero sus azules quedan normalizados a la paleta vigente:

```text
#171e37  Oscuro corporativo
#3683c5  Azul corporativo
#286291  Azul oscuro corporativo
```

### Light/Dark Mode

Se corrige la causa del contraste inconsistente de la captura:

- el canvas de la 404 usa las superficies `--eventosapp-app-*` del tema activo;
- el fondo decorativo conserva sus gradientes, pero cambia correctamente entre Light y Dark;
- la tarjeta principal usa superficie, borde, texto, muted y sombra del shell de EventosApp;
- título y texto nunca heredan colores del tema externo;
- la ilustración del ticket permanece deliberadamente clara como objeto gráfico y fuerza texto oscuro para evitar blanco sobre blanco en Dark Mode;
- la etiqueta **Ruta no encontrada** tiene variante específica de contraste en Dark Mode;
- botones primario y secundarios tienen estados normal, hover, focus y focus-visible compatibles con ambos modos.

### Aislamiento frente a Elementor y tema

Los selectores quedan acotados a:

```text
body.eventosapp-app-page #eventosapp-app-root .evapp-404
```

La capa normaliza con especificidad final y `!important` únicamente las propiedades visuales necesarias: fondo, borde, color, tipografía, text-transform, shadow, padding, botones, hover/focus y elementos decorativos. De esta forma un Elementor Kit o el tema no pueden volver a introducir colores, subrayados, transformaciones, fondos o estados ajenos a la UI de EventosApp.

No se altera Elementor fuera de páginas administradas por EventosApp.

### Layout y responsive

La 404 deja de depender de la composición accidental producida por las reglas anteriores:

- canvas de ancho completo bajo el header propio de EventosApp;
- tarjeta centrada con máximo de `880px` y padding interno consistente;
- radio, borde y sombra sincronizados con el shell corporativo;
- botones flexibles en escritorio y apilados en móvil;
- ajustes dedicados para `760px` y `560px`;
- se conserva el soporte de reducción de movimiento ya presente en el shortcode original.

### Alcance funcional preservado

`includes/admin/eventosapp-security.php` **no se modifica**. Continúan intactos:

- registro y callback de `[eventosapp_404]`;
- respuesta HTTP `404` de la página configurada;
- redirección de URLs inexistentes hacia la experiencia 404;
- protección de `/wp-login.php` y `/wp-admin`;
- login, roles, permisos, nonces, auditoría y hardening;
- elección entre botón Dashboard o Login según sesión;
- enlace a la página principal;
- lógica JavaScript de **Volver a la página anterior**;
- exclusión de controles de cuenta dentro de la 404;
- normalización limpia de páginas de `1.5.0-rc.17`;
- boxed nativo del Dashboard de `1.5.0-rc.18`.

### Archivos de 1.5.0-rc.19

```text
includes/frontend/eventosapp-404-visual-compat.php      NUEVO — capa visual final exclusiva de la 404
includes/frontend/eventosapp-frontend-helpers.php       MODIFICADO — carga de la nueva capa al final del stack
docs/history/README-through-1.5.0-rc.18.md              NUEVO — snapshot histórico íntegro previo
README.md                                                 MODIFICADO — versión, alcance y validación
```

### Commits funcionales

```text
b4dc65e541646fa917635b6324c0f5ebb5fb7d8f  feat: add 404 visual compatibility layer
f4340023b771026699bd808f2285c7c5cd3de325  feat: load 404 visual compatibility layer
e6e0428e2d54da772ab6de9ca1b8db282a48a65d  docs: preserve detailed history through rc18
```

### Validación técnica realizada

- La rama fue reconstruida sobre `1bac5e162538fe4e910b1777d104d15f182e4477`, es decir, el `main` más reciente que ya contiene `1.5.0-rc.18`; no se omite el boxed del Dashboard ni la normalización de páginas anterior.
- Antes de documentar, la comparación `main...fix/404-dark-branding-elementor-20260810` estaba **2 commits adelante y 0 atrás**.
- El diff funcional se limitaba exactamente a la nueva capa 404 y a 11 líneas de loader en `eventosapp-frontend-helpers.php`.
- El commit del loader fue verificado y no contiene eliminaciones ni reescrituras de helpers existentes.
- No se modifica el renderizador funcional de la página 404 ni el módulo de Seguridad.

La validación visual final requiere el entorno WordPress real porque el resultado depende del documento generado por WordPress, del tema activo y de las hojas que Elementor inyecta en ejecución.

## Validación funcional requerida en WordPress

Antes de promover `1.5.0-rc.19`:

- Abrir la página 404 configurada en **Light Mode** y confirmar logo oficial a color, canvas claro, tarjeta legible y botones con la paleta corporativa.
- Cambiar a **Dark Mode** y confirmar logo blanco, canvas oscuro, tarjeta oscura, textos/etiqueta legibles y ausencia del gran fondo claro mostrado en la incidencia.
- Recargar la página y confirmar que se conserva el tema elegido.
- Probar como usuario autenticado y confirmar **Volver al dashboard**.
- Probar sin sesión y confirmar que aparece **Ir al inicio de sesión** cuando existe login configurado.
- Probar **Ir a la página principal** y **Volver a la página anterior**.
- Abrir una URL realmente inexistente y confirmar que termina en la experiencia EventosApp y conserva respuesta HTTP 404.
- Probar `/wp-login.php` y `/wp-admin` con un usuario sin backend para confirmar que el hardening previo no cambió.
- Regenerar CSS de Elementor y volver a revisar la 404 para confirmar que no reaparecen estilos externos en botones, tipografía o fondos.
- Probar 320px, 375px, 430px, tablet, 1366px, 1440px y 1920px, verificando que no exista scroll horizontal ni botones cortados.
- Abrir Dashboard y confirmar que conserva el boxed nativo de `1.5.0-rc.18`.
- Abrir al menos un módulo operativo y una página WordPress ajena a EventosApp para confirmar que la nueva capa no produce efectos colaterales.

## Estado acumulado reciente

La base de `1.5.0-rc.19` ya contiene los avances anteriores, entre ellos:

- **1.5.0-rc.18:** Dashboard boxed nativo de `1200px` sin depender de Elementor.
- **1.5.0-rc.17:** reinstalación/normalización canónica de páginas y eliminación de residuos de Elementor.
- **1.5.0-rc.16:** Expositores — identidad corporativa, Light/Dark y aislamiento Elementor/tema.
- **1.5.0-rc.15:** Asistencia, Ranking de Networking y Sorteo administrativo — compatibilidad visual final.
- **1.5.0-rc.14:** estados de Transacciones de Consumibles en Dark Mode.
- **1.5.0-rc.13:** Checklist, Edición y Consumibles.
- **1.5.0-rc.12:** Búsqueda Manual y Check-In Facial.

El detalle íntegro de `1.5.0-rc.17`, `1.5.0-rc.18` y todos los ciclos previos permanece en [`docs/history/README-through-1.5.0-rc.18.md`](docs/history/README-through-1.5.0-rc.18.md).

## Regla de promoción

Este repositorio sigue siendo el entorno de pruebas. `1.5.0-rc.19` no debe promoverse a `theleadpartner/EventosApp` hasta validar la 404 en Light/Dark, con y sin sesión, después de regenerar CSS de Elementor y en los breakpoints indicados, además de confirmar que los avances funcionales de `1.5.0-rc.17` y el boxed de `1.5.0-rc.18` permanecen intactos.
