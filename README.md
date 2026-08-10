# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

> **Historial preservado:** el README detallado acumulado hasta `1.5.0-rc.16` se conserva, sin omitir avances anteriores, en [`docs/history/README-through-1.5.0-rc.16.md`](docs/history/README-through-1.5.0-rc.16.md). Este README queda como estado operativo actual; al intervenir módulos desarrollados en ciclos anteriores debe consultarse también ese historial.

## Estado del ciclo actual

- **Versión candidata:** `1.5.0-rc.17`
- **Fecha de corte:** 2026-08-10
- **Base integrada:** `3d508421f3bf2c1b8d2860d360b5b19724f67ca8` (`main`, posterior al merge de `1.5.0-rc.16`)
- **Rama de trabajo:** `fix/shortcode-page-normalization-20260810`
- **Destino de promoción:** `theleadpartner/EventosApp`
- **Estado:** hotfix funcional del instalador de páginas/shortcodes de Configuración. La validación ya no considera sana una página solo porque encuentre el shortcode: exige contenido canónico, detecta contenido ajeno y residuos de Elementor, y la reparación individual o la reinstalación automática normalizan la página antes de marcarla como correcta. Pendiente validación funcional en WordPress antes de promover.

## Hotfix 1.5.0-rc.17 — Reinstalación limpia de páginas y shortcodes

### Incidencia detectada

El instalador existente de `includes/admin/eventosapp-configuracion.php` resolvía correctamente creación, mapeo y ausencia de shortcodes, pero su criterio de salud era insuficiente para garantizar la UI de EventosApp:

1. Una página se marcaba como **Correcta** si `has_shortcode()` encontraba el shortcode en cualquier parte de `post_content`, aunque coexistieran HTML, bloques Gutenberg, texto, otros shortcodes o widgets ajenos.
2. Si la página mapeada no contenía el shortcode, `eventosapp_installation_append_shortcode_safely()` lo añadía al contenido existente en vez de reemplazarlo; esto podía dejar la UI de Elementor/tema y la UI de EventosApp en la misma página.
3. Elementor puede conservar su documento en metadatos `_elementor_*` incluso si `post_content` parece correcto. El diagnóstico anterior no revisaba ese estado.
4. La instalación masiva en Cola y Tareas reutilizaba el mismo criterio; por tanto una “reinstalación” podía completar sin normalizar páginas que visualmente no estaban limpias.

### Objetivo canónico

Para cada definición del inventario de instalación, el estado correcto queda definido de manera determinista:

- **Página con shortcode:** `post_content` debe ser exactamente el contenido canónico definido por el inventario, normalmente un único shortcode como `[eventosapp_dashboard]`.
- **Página estructural:** el contenido debe quedar vacío.
- **Elementor:** no pueden permanecer metadatos de página cuyo nombre comience por `_elementor_` ni un `_wp_page_template` de Elementor.
- **Mapeo:** debe seguir apuntando a una página válida publicada o privada.
- **Código:** el shortcode debe existir antes de modificar la página; si el módulo no está cargado, el instalador se detiene y no limpia contenido.

### Corrección aplicada

`includes/admin/eventosapp-configuracion.php` pasa a ser un bootstrap pequeño y explícito. La implementación anterior se conserva **sin cambios de contenido** en `includes/admin/eventosapp-configuracion-core.php`; antes de cargar ese core se precargan únicamente las funciones de instalación que el propio archivo histórico protege con `function_exists()`. De esta forma no se reescriben ni eliminan las funciones de configuración, getters, settings, inventario, UI, nonces o integración con Cola y Tareas que ya funcionaban.

La nueva política se divide en tres capas:

```text
includes/admin/eventosapp-configuracion-clean-policy.php     Diagnóstico y normalización canónica
includes/admin/eventosapp-configuracion-clean-installer.php  Instalación/reinstalación y Cola y Tareas
includes/admin/eventosapp-configuracion-clean-ui.php         Etiquetas administrativas del nuevo diagnóstico
```

#### 1. Detección real del diseño esperado

`eventosapp_installation_definition_status()` ya no toma la mera presencia del shortcode como sinónimo de salud. Ahora distingue:

- página mapeada y completamente limpia;
- página mapeada que conserva contenido ajeno o datos de Elementor;
- página mapeada sin el shortcode requerido;
- página existente detectada que debe mapearse;
- página existente detectada que primero debe limpiarse y luego mapearse, incluso cuando el shortcode solo estaba guardado dentro de `_elementor_data`;
- shortcode cuyo módulo no está cargado;
- página todavía inexistente.

La pantalla de **Estado de instalación de EventosApp** mantiene sus formularios y nonces existentes, pero comunica la nueva semántica con estados como **Mapeada · requiere limpieza**, **Página detectada · limpiar y mapear** y acciones como **Reinstalar en limpio**.

#### 2. Limpieza de cualquier contenido ajeno

`eventosapp_installation_normalize_managed_page()` aplica la definición canónica únicamente a páginas que el inventario ya controla o que fueron detectadas porque contienen el shortcode de EventosApp correspondiente.

En una reparación:

- reemplaza todo `post_content` por el contenido canónico;
- elimina HTML, bloques, texto, otros shortcodes o widgets que estuvieran mezclados en `post_content`;
- elimina metadatos `_elementor_*` de esa página;
- elimina `_wp_page_template` únicamente cuando el valor pertenece a Elementor;
- limpia la caché del post;
- vuelve a leer la página y verifica que haya quedado realmente limpia antes de devolver éxito.

No se hace un borrado indiscriminado de postmeta: metadatos de WordPress, EventosApp y otros módulos se conservan si no son estado de render/edición de Elementor.

#### 3. Página existente: conservar entidad, reinstalar contenido

Si una página ya está mapeada, la reinstalación conserva:

- ID de WordPress;
- título;
- slug y permalink;
- jerarquía/página padre;
- estado `publish` o `private`;
- mapeo existente.

Lo que se normaliza es exclusivamente la superficie de contenido que puede competir con la UI de EventosApp. Si WordPress devuelve un error durante la limpieza, la operación no se declara correcta.

Las páginas existentes no mapeadas siguen reutilizándose cuando contienen el shortcode canónico o un alias histórico conocido; ahora se limpian **antes** de quedar mapeadas. Esto evita duplicar páginas y, al mismo tiempo, evita heredar un layout viejo.

#### 4. Instalación nueva

Una página nueva se crea con el contenido canónico desde el primer momento. Después de `wp_insert_post()` se ejecuta la misma verificación de normalización antes de guardar el mapeo; si no puede quedar en estado válido, la creación se revierte para no dejar una página huérfana.

### Reinstalación automática organizada

La automatización existente de **Cola y Tareas** mantiene el `task_type` histórico `eventosapp_install_pages`, por lo que no se rompen tareas ni integraciones previas, pero su adaptador pasa a representar explícitamente una **Reinstalación limpia de páginas de EventosApp**.

El botón masivo ahora se presenta como **Reinstalar / normalizar todas en segundo plano** y la tarea se crea con:

```text
mode: clean_reinstall
content_policy: canonical_shortcode_only
```

La Cola sigue procesando el inventario por lotes de 4. Cada definición pasa por el mismo instalador determinista usado por la reparación individual, de modo que una reinstalación completa ejecuta, en orden:

1. validación de shortcode disponible;
2. resolución de dependencias/página padre;
3. localización de página mapeada o candidata;
4. limpieza de contenido y Elementor;
5. verificación del estado canónico;
6. persistencia/revalidación del mapeo;
7. registro de éxito o error en Cola y Tareas.

Las páginas que ya están limpias se verifican de forma idempotente y no necesitan reescritura de contenido.

### Alcance y protecciones

La limpieza es deliberadamente destructiva **solo para el contenido de las páginas administradas por este inventario**, porque esas páginas deben ser superficies exclusivas de EventosApp.

No se modifica automáticamente:

- ninguna página WordPress que no esté mapeada ni contenga un shortcode conocido del inventario;
- callbacks o lógica interna de los shortcodes;
- eventos, tickets, asistentes, clientes, expositores, consumibles o networking;
- permisos, roles, nonces o autenticación;
- consultas, AJAX, QR/cámara, métricas, CSV, Wallet, correo o WhatsApp;
- Cola y Tareas central fuera del adaptador `eventosapp_install_pages`;
- título, slug, permalink, jerarquía o estado de una página existente;
- postmeta funcional ajeno a Elementor.

Si el shortcode requerido no está registrado, la página no se limpia y se devuelve error. Esto mantiene la protección histórica contra rutas rotas.

### Archivos de 1.5.0-rc.17

```text
includes/admin/eventosapp-configuracion.php                    MODIFICADO — bootstrap
includes/admin/eventosapp-configuracion-core.php               NUEVO — core anterior preservado sin cambios
includes/admin/eventosapp-configuracion-clean-policy.php       NUEVO
includes/admin/eventosapp-configuracion-clean-installer.php    NUEVO
includes/admin/eventosapp-configuracion-clean-ui.php           NUEVO
docs/history/README-through-1.5.0-rc.16.md                     NUEVO — snapshot histórico exacto
README.md                                                       MODIFICADO
```

### Commits funcionales de la rama

```text
e9f549479bd1324a48d81915b263d580a00787ff  refactor: preserve configuration core for clean installer bootstrap
effcf006bae959b045942e8efc2435a2ec1bef2c  feat: add canonical page cleanup policy
5c7e8408c29eb0283eb2de3c052d6eb775c626e5  feat: make shortcode installation a clean reinstall
e69eae7614149425ebf69d3f9bf4fbe090100a95  feat: expose clean reinstall state in configuration UI
d350ab836b3143d60fa9d26db659663ed8c20ff0  feat: bootstrap clean shortcode page reinstall
7e75685137d38794bce7b93dd0a77f927a89a985  fix: detect shortcode widgets stored only in Elementor data
```

La rama incorporó posteriormente `main` mediante `07ee18f4b53b5a98746e5ef5619754e7f85da601` para incluir íntegramente `1.5.0-rc.16` antes de documentar y validar este hotfix.

## Validación técnica realizada

Se ejecutó validación de sintaxis PHP sobre los cuatro archivos activos de la nueva capa:

```text
No syntax errors detected in eventosapp-configuracion-clean-policy.php
No syntax errors detected in eventosapp-configuracion-clean-installer.php
No syntax errors detected in eventosapp-configuracion-clean-ui.php
No syntax errors detected in eventosapp-configuracion.php
```

También se ejecutó una prueba aislada de la política de limpieza con una página simulada que contenía:

- HTML adicional;
- `[eventosapp_dashboard]` mezclado con ese HTML;
- `_elementor_data`;
- `_elementor_edit_mode`;
- `_wp_page_template = elementor_canvas`;
- un metadato funcional `_eventosapp_keep`.

Resultado esperado y obtenido: `post_content` quedó exactamente en `[eventosapp_dashboard]`, se eliminaron los metadatos de Elementor y se conservó `_eventosapp_keep`. Una segunda prueba confirmó que una página cuyo shortcode existía únicamente dentro de `_elementor_data` también es detectada, reutilizada y normalizada; además se verificó que un shortcode no registrado detiene la operación antes de cualquier limpieza destructiva.

## Validación funcional requerida en WordPress

Antes de promover `1.5.0-rc.17`:

- En una página mapeada, agregar un párrafo antes/después del shortcode y confirmar que el diagnóstico deja de mostrar **Correcto**.
- Ejecutar **Reinstalar en limpio** y confirmar que `post_content` queda únicamente con el shortcode esperado.
- Editar una página con Elementor, conservar el shortcode y confirmar que los metadatos de Elementor hacen que la página aparezca como pendiente de limpieza.
- Repararla y confirmar que la página ya no abre/renderiza el documento anterior de Elementor y que EventosApp conserva su UI completa.
- Probar una página mapeada cuyo shortcode fue eliminado: debe reinstalar el shortcode **reemplazando** el contenido previo, no anexándolo.
- Probar una página no mapeada que ya contiene el shortcode más contenido ajeno: debe detectarse, limpiarse y mapearse sin crear un duplicado.
- Ejecutar **Reinstalar / normalizar todas en segundo plano**, cerrar la pantalla y confirmar que Cola y Tareas continúa procesando el inventario.
- Al finalizar la tarea masiva, revalidar y confirmar que todas las definiciones disponibles quedan en estado canónico.
- Confirmar que un módulo cuyo shortcode no esté registrado genera error y no modifica la página.
- Abrir páginas WordPress ajenas a EventosApp y confirmar que no fueron modificadas.
- Verificar Dashboard, Check-In, QR, Métricas, Edición, Consumibles, Asistencia, Networking, Sorteo y Expositores después de la reinstalación para confirmar que cada superficie queda exclusivamente bajo la UI de EventosApp.

## Estado acumulado reciente

Los hotfix visuales inmediatamente anteriores continúan integrados en la base de esta rama:

- **1.5.0-rc.16:** Expositor y Gestión de Expositores — identidad corporativa, Light/Dark y aislamiento frente a Elementor/tema sin alterar inventario, entregas, QR, CSV, AJAX ni permisos.
- **1.5.0-rc.15:** Asistencia/Métricas de apoyo, Ranking de Networking y panel administrativo del Sorteo en Vivo — compatibilidad visual y aislamiento; la pantalla pública del sorteo permanece independiente.
- **1.5.0-rc.14:** Transacciones/Mis transacciones de Consumibles — corrección definitiva de estados Activa, Cancelación solicitada y Anulada en Dark Mode.
- **1.5.0-rc.13:** Checklist, Edición de asistentes y Consumibles — identidad, Dark Mode y aislamiento Elementor sin reescribir la lógica operativa.
- **1.5.0-rc.12:** Búsqueda/Check-In Manual y Check-In Facial — compatibilidad de marca, Dark Mode y buscadores protegidos frente a estilos externos.

El detalle completo de esos ciclos y de todos los anteriores —seguridad integrada, shell sin header/footer del tema, branding, modo oscuro persistente, kiosko, networking, consumibles, landing virtual, colas, permisos y demás avances— permanece en [`docs/history/README-through-1.5.0-rc.16.md`](docs/history/README-through-1.5.0-rc.16.md).

## Regla de promoción

Este repositorio sigue siendo el entorno de pruebas. `1.5.0-rc.17` no debe promoverse a `theleadpartner/EventosApp` hasta completar la validación funcional indicada arriba, especialmente una reinstalación masiva sobre páginas con y sin residuos de Elementor.
