# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

## Estado del ciclo actual

- **Versión candidata:** `1.5.0-rc.13`
- **Fecha de corte:** 2026-08-10
- **Base de la rama:** `b0a4638f33c41bcf0117b5938c706bcb62da641d` (`main`)
- **Rama de trabajo:** `fix/checklist-edit-consumables-dark-branding-elementor-20260810`
- **Destino de promoción:** `theleadpartner/EventosApp`
- **Estado:** hotfix visual específico para Checklist de Evento, Edición de asistentes y las vistas internas de Consumibles: identidad corporativa completa, superficies y controles compatibles con Dark Mode y aislamiento frente al CSS global de Elementor/tema, sin modificar checklist, edición de tickets, búsquedas, cámara/QR, inventario, ledger, reversos, exportaciones ni landing pública; pendiente validación visual/funcional antes de promoción.

## Hotfix 1.5.0-rc.13 — Checklist, Edición y Consumibles: identidad, Dark Mode y aislamiento Elementor

### Incidencias detectadas

La revisión de `includes/admin/eventosapp-event-checklist.php`, `includes/frontend/eventosapp-frontend-edit.php`, `includes/functions/eventosapp-consumables-core.php`, `includes/functions/eventosapp-consumables-transactions.php` e `includes/functions/eventosapp-consumables.php` confirmó el mismo patrón encontrado en los módulos corregidos durante `1.5.0-rc.9` a `1.5.0-rc.12`: la lógica funcional ya está consolidada, pero varios renderizadores mantienen CSS inline histórico con superficies claras, azules anteriores y reglas suficientemente específicas para competir con el shell corporativo, Dark Mode y el Elementor Kit.

Los puntos principales fueron:

1. **Checklist de Evento:** `.evchk-app` todavía define `#3279bd`, `#255f96`, fondos blancos y superficies `#fbfdff/#f8fafc` para shell, opciones, formularios, archivos, cards y estados. En Dark Mode estos valores pueden dejar tarjetas claras dentro del canvas oscuro y hovers ajenos a la identidad vigente.
2. **Edición de asistentes:** `.evfe-app` conserva la misma generación cromática histórica en shell, búsqueda, resultados, editor, controles y botones. El input de búsqueda además debe conservar su padding protegido para que Elementor/tema no vuelva a acercar el icono al texto.
3. **Consumibles — configuración:** `eventosapp-consumables-core.php` genera `.evapp-cons-front-shell` y `.evapp-cons-editor` con blancos directos y aliases `--evc-*` anteriores a la paleta corporativa.
4. **Consumibles — Staff QR:** el lector `.evapp-cscan` conserva cards, evento, cantidades, resumen, inventario, actividad reciente y resultados con fondos claros directos. El visor de cámara, en cambio, debe permanecer deliberadamente oscuro por tratarse de una superficie técnica.
5. **Consumibles — transacciones:** `eventosapp-consumables-transactions.php` contiene pestañas, filtros, resumen, tabla administrativa, tarjetas de Staff, badges, paginación y mensajes con blancos directos y aliases históricos `--p/--pd/--bg/--b/--t/--m`.
6. `includes/functions/eventosapp-consumables.php` es el cargador seguro y el punto de protección del buffer de la landing pública; no contiene una UI interna que deba ser reestilizada. Alterarlo para resolver presentación introduciría riesgo funcional innecesario.

### Corrección aplicada

Se agrega `includes/frontend/eventosapp-checklist-edit-consumables-visual-compat.php` como capa visual final dedicada a este grupo de módulos. `includes/frontend/eventosapp-frontend-helpers.php` la carga después de branding, pulido general, aislamiento Elementor, compatibilidad QR y compatibilidad de Búsqueda/Facial. Su CSS se imprime mediante `wp_footer` con prioridad `1005`.

El criterio continúa siendo deliberadamente conservador: **los cinco archivos funcionales indicados se revisaron, pero no se reescribieron**. La nueva capa actúa sobre los wrappers y clases que ya producen y modifica únicamente presentación. Así se evita tocar lógica que actualmente funciona correctamente.

#### Imagen corporativa

- `.evchk-eyebrow` — Checklist de Evento — utiliza el icono oficial de EventosApp.
- `.evfe-eyebrow` — Edición de asistentes — utiliza la misma firma corporativa.
- Los encabezados de Control de Consumibles, Consumo de Consumibles y Transacciones incorporan el icono oficial sin cambiar su estructura HTML.
- Light Mode usa `eventosapp_icon.svg`; Dark Mode usa `eventosapp_icon_blanco.svg`.
- Los aliases históricos `--evapp-*`, `--evc-*` y `--p/--pd` quedan remapeados a `#171e37`, `#3683c5` y `#286291` y a las superficies comunes del shell activo.

#### Checklist de Evento

Dentro de `.evchk-app` se corrigen únicamente propiedades visuales:

- shell, contexto del evento, resumen, deadline, tareas, submit y empty state usan superficies del tema activo;
- títulos, textos auxiliares, labels y estados recuperan contraste Light/Dark;
- opciones de radio mantienen layout y selección, pero sus fondos/hover dejan de regresar a blanco;
- inputs, textarea, archivo, placeholders y disabled usan el input/surface común;
- botones primarios y secundarios usan la paleta oficial en normal, hover y focus;
- iconos de evento/tarea/empty state usan el acento corporativo;
- estados semánticos de progreso, advertencia, error y listo conservan su significado.

No se cambia ninguna validación del checklist, tareas, logs, evidencia, upload, permisos o guardado.

#### Edición de asistentes

Dentro de `.evfe-app` se corrigen:

- shell, contexto de evento, tarjeta de búsqueda, resultados, editor y secciones del formulario;
- títulos, subtítulos, metadatos, ayudas y avatar;
- inputs, selects, opciones, placeholders y estados de foco;
- filas de resultados y hover;
- botones principales, secundarios y ghost;
- chips, estados y empty states.

El input `type="search"` mantiene forzosamente `46px` de espacio a izquierda y derecha, `appearance:none` y ausencia de imagen de fondo externa. De esta forma una regeneración de CSS de Elementor/tema no puede volver a invadir el texto con decoraciones del control.

No se altera búsqueda AJAX, selección de asistente, carga del editor, datos, ticket, QR, correo, WhatsApp, permisos, nonces ni persistencia.

#### Consumibles — Configuración y editor

Dentro de `.evapp-cons-front-shell` y `.evapp-cons-editor`:

- shell, contexto de evento, introducción, reglas, items y empty state siguen el tema activo;
- el switch/estado de activación usa el azul corporativo;
- inputs, selects, textarea, opciones y placeholders usan las superficies de formulario comunes;
- botones de acción, añadir, guardar e icon buttons quedan aislados frente a estilos globales;
- chips y ayudas heredan los tokens correctos para Light/Dark.

No se modifican reglas de segmentación, inventario, cantidades, comportamiento compartido/diario, serialización ni guardado.

#### Consumibles — Lector QR de Staff

Dentro de `.evapp-cscan`:

- shell, evento, cards, selección de artículos, cantidades, resumen, resultado, inventario y actividad reciente adoptan las superficies del tema;
- controles y cantidades respetan normal/hover/focus/disabled;
- el botón de cámara mantiene sus estados funcionales y el estado rojo de detener cámara;
- éxito/error usan variantes semánticas legibles en Dark Mode;
- `.evapp-cscan-video-wrap` conserva su fondo técnico oscuro y únicamente sincroniza el borde.

No se modifica BarcodeDetector, fallback jsQR, MediaStream, canvas, selección múltiple, cantidades, idempotencia, vibración/beep ni descuento atómico.

#### Consumibles — Transacciones y pestañas

Dentro de `.evapp-cons-tabs-wrap` y `.evapp-tx-shell`:

- pestañas Configuración/Transacciones y Escanear/Mis transacciones respetan el tema activo;
- shell, resúmenes, filtros, tabla, tarjetas de Staff, empty states y paginación dejan de volver a blanco;
- inputs/selects y opciones quedan protegidos frente a Elementor;
- encabezados y celdas de tabla mantienen contraste en Dark Mode;
- solicitudes pendientes, badges, anulación/reverso y mensajes conservan semántica diferenciada;
- botones normales, secundarios y de peligro conservan jerarquía y estados de interacción corporativos.

No se modifica agrupación por batch, filtros, CSV, ledger, solicitudes de cancelación, `START TRANSACTION`, `SELECT ... FOR UPDATE`, reversos, rollback, auditoría ni permisos.

#### Aislamiento frente a Elementor y tema

La prioridad adicional se limita a páginas mapeadas por EventosApp y a los wrappers de este hotfix:

```text
body.eventosapp-app-page #eventosapp-app-root .evchk-app
body.eventosapp-app-page #eventosapp-app-root .evfe-app
body.eventosapp-app-page #eventosapp-app-root .evapp-cons-front-shell
body.eventosapp-app-page #eventosapp-app-root .evapp-cons-editor
body.eventosapp-app-page #eventosapp-app-root .evapp-cscan
body.eventosapp-app-page #eventosapp-app-root .evapp-tx-shell
body.eventosapp-app-page #eventosapp-app-root .evapp-cons-tabs-wrap
```

Los `!important` se concentran en tokens y propiedades de presentación que deben ganar frente a CSS inline histórico, Elementor Kit y tema. No se modifican atributos, listeners, grids funcionales, datasets ni reglas de negocio.

### Alcance conservado

No se modifican:

- `includes/admin/eventosapp-event-checklist.php` ni sus funciones de checklist, metabox, validación o guardado;
- `includes/frontend/eventosapp-frontend-edit.php` ni sus funciones de búsqueda/edición;
- `includes/functions/eventosapp-consumables-core.php` ni configuración, lector, inventario o lógica transaccional;
- `includes/functions/eventosapp-consumables-transactions.php` ni consulta, filtros, CSV, cancelaciones o reversos;
- `includes/functions/eventosapp-consumables.php` ni su loader, detección de landing, buffering o inyección segura;
- permisos, roles, capacidades, evento activo o alcances por evento;
- endpoints AJAX, nonces, consultas SQL, tablas o índices;
- validaciones de Checklist, evidencias o archivos;
- creación/actualización de tickets, QR o canales de comunicación desde Edición;
- cámara, BarcodeDetector, jsQR, selección/cantidades o descuento atómico de Consumibles;
- ledger, auditoría, solicitudes, reversos, restauración de saldo o exportación CSV;
- inventario e historial visibles en `/ticket/`, ni la exclusión de tickets virtuales;
- Dashboard, QR Check-In, Búsqueda Manual, Facial, Registro, Empresas, Métricas, Seguridad ni otros módulos;
- estilos Elementor de páginas WordPress no mapeadas por EventosApp.

### Archivos de 1.5.0-rc.13

```text
includes/frontend/eventosapp-checklist-edit-consumables-visual-compat.php  NUEVO
includes/frontend/eventosapp-frontend-helpers.php                         MODIFICADO
README.md                                                                  MODIFICADO
```

### Commits funcionales

```text
ff47cb5b01c357e80f6741a9013a0e7b6550d2ea  feat: add checklist edit and consumables visual compatibility
5006f06efb297335c5f85df67cfe887cd083bf41  feat: load checklist edit and consumables compatibility layer
```

## Validación funcional requerida para 1.5.0-rc.13

- Abrir **Checklist de Evento** en Light Mode y confirmar icono corporativo, paleta, evento, resumen, deadline, tareas y botones.
- Activar Dark Mode y confirmar que shell, cards, opciones, inputs, archivos, estados y empty state permanecen oscuros y legibles.
- Probar opciones, radio buttons, campos logísticos, evidencia y guardado; el comportamiento funcional debe ser idéntico.
- Abrir **Edición de asistentes** en Light/Dark y confirmar shell, buscador, resultados, avatar, editor, formularios y botones.
- Buscar con texto corto/largo y confirmar que el icono nunca invade placeholder ni texto ingresado.
- Seleccionar un asistente, editar y guardar datos para confirmar que AJAX, nonce y persistencia continúan funcionando.
- Abrir **Control de Consumibles > Configuración** y revisar pestañas, evento, editor, reglas, items, inputs/selects y Guardar en ambos temas.
- Cambiar selección/valores sin guardar y confirmar que la capa visual no altera serialización ni controles.
- Abrir **Consumo de Consumibles > Escanear**, seleccionar uno y varios artículos, cambiar cantidades y revisar disabled/hover/focus.
- Activar cámara y confirmar que visor/video sigue oscuro, QR funciona y el resultado éxito/error conserva contraste.
- Confirmar que una lectura duplicada sigue siendo idempotente y que una operación sin saldo no produce descuentos parciales.
- Abrir **Transacciones** como Administrador/Organizador y revisar resumen, filtros, tabla, badges, paginación y botones en Light/Dark.
- Probar filtros y descarga CSV.
- Probar una solicitud de cancelación y, en un entorno controlado, su reverso para confirmar que auditoría y restauración de saldo no cambian.
- Abrir **Mis transacciones** como Staff y revisar cards, badges, solicitud de cancelación y paginación.
- Abrir una landing presencial `/ticket/` con inventario/historial y confirmar que conserva su presentación pública actual; este hotfix no debe aplicar Dark Mode del dashboard allí.
- Abrir un ticket virtual y confirmar que Consumibles sigue excluido.
- Cambiar Light/Dark con cada módulo ya cargado y confirmar actualización inmediata de superficies y controles.
- Probar 320 px, 375 px, 430 px, tablet y desktop sin overflow ni pérdida de controles.
- Regenerar CSS de Elementor y volver a abrir Checklist, Edición y las vistas de Consumibles; la identidad EventosApp debe mantenerse.
- Abrir una página WordPress no mapeada por EventosApp y confirmar que su Elementor Kit permanece intacto.

---

## Hotfix 1.5.0-rc.12 — Búsqueda Manual y Check-In Facial: identidad, Dark Mode y aislamiento Elementor

### Incidencias detectadas

La revisión de `includes/frontend/eventosapp-frontend-search.php` y `includes/frontend/eventosapp-face-checkin.php` confirmó que ambos módulos conservan CSS inline histórico anterior al shell corporativo actual. Aunque la capa global ya aportaba parte de los tokens de Dark Mode, los renderizadores todavía fijaban superficies claras, azules anteriores y estados propios con suficiente especificidad para competir con Elementor/tema.

Los síntomas principales observados fueron:

1. **Búsqueda y Check-In Manual:** el shell general ya podía verse oscuro, pero inputs, chips, tarjetas, estados y botones seguían expuestos a reglas antiguas o globales. El caso más visible era el botón de check-in deshabilitado por fecha, que adoptaba un color magenta ajeno a la identidad de EventosApp.
2. **Búsqueda y Check-In Manual:** el encabezado `.evfs-eyebrow` aún no tenía la firma corporativa consistente con los módulos corregidos recientemente.
3. **Check-In Facial:** `.evapp-face-checkin-app` no estaba cubierto por todos los aliases visuales comunes y su CSS inline mantenía `--evapp-app-bg`, paneles y componentes en tonos claros. En Dark Mode el texto ya heredaba colores claros mientras el shell y los paneles seguían blancos, generando el contraste casi invisible mostrado en la validación visual.
4. **Check-In Facial:** botones deshabilitados por fecha/carga, chips, paneles, estadísticas y estados podían volver a valores claros del renderizador o del Elementor Kit.
5. Ambos módulos necesitan conservar exactamente su lógica actual; la corrección debía ganar prioridad visual sin tocar AJAX, cámara, face-api.js, impresión, nonces ni reglas del evento.

### Corrección aplicada

Se agrega `includes/frontend/eventosapp-search-face-visual-compat.php` como una capa visual final dedicada a estos dos módulos. `includes/frontend/eventosapp-frontend-helpers.php` la carga después de las capas corporativas, del aislamiento general de Elementor y de la compatibilidad QR. Su CSS se imprime en `wp_footer` con prioridad `1004`.

El criterio mantiene el patrón seguro utilizado en los hotfix anteriores: **no se reescriben los renderizadores funcionales**. La nueva capa actúa sobre los wrappers y clases que `eventosapp-frontend-search.php` y `eventosapp-face-checkin.php` ya producen, y se limita a propiedades de presentación.

#### Imagen corporativa

Los encabezados de ambos módulos reciben la firma oficial de EventosApp:

- `.evfs-eyebrow` — Búsqueda y Check-In Manual;
- `.evfc-eyebrow` — Check-In Facial.

Light Mode utiliza `eventosapp_icon.svg`; Dark Mode utiliza `eventosapp_icon_blanco.svg`. Los tokens históricos de azul se remapean a la paleta oficial `#171e37`, `#3683c5` y `#286291`.

#### Búsqueda y Check-In Manual

Dentro de `.evfs-app` se corrigen exclusivamente propiedades visuales:

- shell, contexto del evento, tarjeta de búsqueda y filas de asistentes adoptan las superficies comunes del tema activo;
- títulos, subtítulos, labels, ayudas y metadatos usan los tokens de texto correctos en Light/Dark;
- selector, input de búsqueda, opciones, placeholder, lupa y botón de limpieza quedan aislados frente a estilos globales de formularios;
- se conserva explícitamente el padding protegido del `type="search"`, por lo que la lupa no vuelve a invadir el placeholder o el texto escrito;
- botones primarios, secundarios, impresión y acompañantes usan la paleta corporativa en normal, hover y focus;
- el botón **Hacer check-in** deshabilitado por fecha conserva su estado funcional, pero deja de recibir el color magenta/externo observado en la captura;
- chips de modalidad/fecha, avatar, badges, estados vacíos, hover de resultados y panel de acompañantes siguen el tema activo;
- éxito, advertencia, error, virtual y check-in registrado conservan su semántica con contraste apropiado para Dark Mode.

#### Check-In Facial

Dentro de `.evapp-face-checkin-app` se corrigen exclusivamente propiedades visuales:

- `.evfc-shell`, contexto del evento y paneles de lector/resultado adoptan superficies oscuras cuando corresponde;
- títulos, subtítulos, ayudas y datos del resultado recuperan contraste correcto;
- chips de fecha, caché y cámara usan estados semánticos compatibles con ambos temas;
- Volver al dashboard, Activar cámara, Recargar perfiles y demás controles quedan protegidos frente a estilos Elementor/tema;
- el botón de cámara deshabilitado por fecha o mientras se cargan modelos mantiene `disabled` y su lógica intacta, pero con fondo, borde y texto legibles;
- progreso, guías, empty state, estadísticas y notas dejan de depender de blancos rígidos;
- estados de reconocimiento correcto, advertencia y error mantienen su significado visual.

El visor `.evfc-camera-wrap` permanece deliberadamente oscuro porque es una superficie técnica para vídeo, canvas y guías de reconocimiento. La compatibilidad solo sincroniza su borde; no sustituye el fondo ni modifica overlays, streams o detección.

#### Aislamiento frente a Elementor y tema

La prioridad adicional queda limitada a:

```text
body.eventosapp-app-page #eventosapp-app-root .evfs-app
body.eventosapp-app-page #eventosapp-app-root .evapp-face-checkin-app
```

Los `!important` se concentran en propiedades visuales que deben ganar frente al Elementor Kit y al tema: tokens, fondos, bordes, color, apariencia de controles, hover/focus y estados semánticos. No se modifican grids funcionales, atributos, listeners, datasets ni reglas de negocio.

### Alcance conservado

No se modifican:

- `includes/frontend/eventosapp-frontend-search.php` ni su lógica de búsqueda/check-in;
- `includes/frontend/eventosapp-face-checkin.php` ni su motor de reconocimiento facial;
- permisos, roles, capacidades, alcance por evento o evento activo;
- validación de fecha del evento;
- endpoints AJAX, nonces, consultas, índices de búsqueda o normalización de datos;
- búsqueda por cédula, celular, email, nombres o todos los datos;
- impresión de escarapela ni EventosApp Android Printer Bridge;
- acompañantes ni sus reglas de registro;
- face-api.js, modelos locales, descriptores, IndexedDB o caché de perfiles;
- acceso a cámara, MediaStream, vídeo, canvas, overlays o ciclos de reconocimiento;
- validación final del ticket y registro del check-in facial en servidor;
- Dashboard, QR, Registro Manual, Empresas con Check-In, Métricas, Seguridad ni otros módulos;
- estilos Elementor de páginas WordPress que no estén mapeadas como EventosApp.

### Archivos de 1.5.0-rc.12

```text
includes/frontend/eventosapp-search-face-visual-compat.php   NUEVO
includes/frontend/eventosapp-frontend-helpers.php            MODIFICADO
README.md                                                     MODIFICADO
```

### Commits funcionales

```text
10500dda5b0aaa2c6840d9e2e69bcaed8ca482b1  feat: add search and face visual compatibility layer
9d08142b96ff944c60b0be2a295443d967b47e67  feat: load search and face compatibility layer
```

## Validación funcional requerida para 1.5.0-rc.12

- Abrir **Búsqueda y Check-In Manual** en Light Mode y confirmar icono corporativo, paleta, evento activo, buscador y resultados.
- Activar Dark Mode y confirmar que shell, contexto, búsqueda, filas, avatar, datos y acciones permanecen oscuros y legibles.
- Repetir el escenario mostrado de **fuera de fecha** y confirmar que Hacer check-in continúa deshabilitado por lógica, pero ya no adopta magenta ni estilos externos.
- Buscar por cédula, celular, email, nombres y todos los datos; confirmar que el comportamiento AJAX, mínimos de caracteres y conteo de resultados no cambian.
- Verificar que la lupa nunca se superpone al placeholder o al texto y que el botón de limpiar continúa funcionando.
- Probar check-in permitido, quitar check-in, impresión y acompañantes cuando corresponda.
- Revisar éxito, advertencia, error, modalidad presencial/virtual y estados de fecha en ambos temas.
- Abrir **Check-In Facial** en Light Mode y confirmar icono, evento, panel de cámara, resultado, guías y estadísticas.
- Activar Dark Mode y confirmar que shell, paneles, chips, botones, empty state, estadísticas y notas permanecen oscuros y legibles.
- Repetir el escenario de **fuera de fecha**: la cámara debe continuar deshabilitada y el mensaje funcional debe mantenerse, pero sin panel blanco/texto invisible.
- En una fecha válida, cargar modelos/perfiles, activar cámara y confirmar que el visor técnico sigue oscuro y que vídeo/canvas no se alteran.
- Probar reconocimiento correcto, sin coincidencia, error y ticket ya procesado según los escenarios disponibles.
- Probar Recargar perfiles y confirmar que IndexedDB/caché siguen funcionando.
- Cambiar Light/Dark con la página cargada y confirmar que ambos módulos responden al tema sin recarga funcional.
- Probar 320 px, 375 px, 430 px, tablet y desktop; no debe aparecer overflow horizontal ni perderse ningún control.
- Regenerar CSS de Elementor y volver a abrir ambos módulos; la identidad EventosApp debe mantenerse.
- Abrir una página WordPress no mapeada por EventosApp y confirmar que su Elementor Kit permanece intacto.

---

## Hotfix 1.5.0-rc.11 — Lectores QR: identidad, Dark Mode y aislamiento Elementor

### Incidencias detectadas

La revisión de `includes/frontend/eventosapp-qr-checkin.php` y `includes/frontend/eventosapp-qr-double-auth.php` confirmó que los lectores QR ya están funcionalmente consolidados, pero sus renderizadores todavía conservan CSS inline histórico con valores claros como `#ffffff`, `#fbfdff`, `#f8fafc`, `#f8fbfe`, `#f5f8fc`, `#182230`, `#64748b`, `#3279bd` y `#255f96`.

Ese CSS histórico se renderiza dentro de cada shortcode y puede competir tanto con los tokens del shell corporativo como con reglas globales de Elementor/tema. En Dark Mode, el resultado era exactamente el observado en la validación visual: títulos casi invisibles, shells claros dentro del canvas oscuro, paneles blancos, controles deshabilitados con contraste insuficiente y estados/ayudas que no seguían el tema activo.

El alcance real dentro de los dos archivos incluye cuatro experiencias distintas:

1. **Check-In con QR** (`[eventosapp_qr_checkin]`): lector principal, resultado, ficha del ticket, acompañantes y estados de check-in.
2. **Validador de Localidad** (`[eventosapp_qr_localidad]`): lector informativo de solo lectura y tarjeta destacada de localidad.
3. **Control de acceso por sesión** (`[eventosapp_qr_sesion]`): selector de sesión/salón, lector QR y confirmación independiente del check-in general.
4. **Check-In QR Doble Autenticación**: lector principal, estados de validación y modal del segundo factor/código de acceso.

### Corrección aplicada

Se agrega `includes/frontend/eventosapp-qr-visual-compat.php` como una capa visual final dedicada a estos lectores. `includes/frontend/eventosapp-frontend-helpers.php` la carga después de `eventosapp-elementor-compat.php`, y su CSS se imprime en `wp_footer` con prioridad `1003`.

El criterio es deliberadamente conservador: **no se reescriben los dos renderizadores QR**. La corrección actúa sobre los selectores que ellos ya producen y solo remapea presentación. De esta forma la cámara, BarcodeDetector/jsQR, AJAX, nonces, consultas, reglas por evento, check-in, sesiones, localidad, acompañantes y segundo factor permanecen exactamente en su implementación actual.

#### Imagen corporativa

Los cuatro encabezados QR reciben la firma visual oficial de EventosApp:

- `.evqr-eyebrow` — Check-In QR;
- `.evqrl-eyebrow` — Validador de Localidad;
- `.evqrs-eyebrow` — Control por Sesión;
- `.evda-eyebrow` — Doble Autenticación.

Light Mode utiliza `eventosapp_icon.svg`; Dark Mode utiliza `eventosapp_icon_blanco.svg`. Los tokens históricos de azul se remapean a la paleta corporativa `#171e37`, `#3683c5` y `#286291`.

#### Dark Mode completo de los lectores

La nueva capa corrige exclusivamente propiedades visuales dentro de los wrappers de los cuatro lectores:

- shells y canvas internos adoptan las superficies del tema activo;
- contexto del evento, paneles de lector y resultado, grids, ayudas y tips dejan de quedar blancos en Dark Mode;
- títulos, subtítulos, etiquetas, texto auxiliar y estados recuperan contraste correcto;
- botones principales usan Azul / Azul oscuro corporativo y sus estados hover/focus quedan protegidos;
- botones secundarios, Volver al dashboard, reintentos y cancelar usan superficies del tema activo;
- inputs de acompañantes y código de acceso usan `--eventosapp-app-input` y bordes corporativos;
- el selector de sesión, sus opciones y su estado deshabilitado son compatibles con Light/Dark;
- chips de fecha, cámara, lectura, sesión y autenticación conservan su significado sin regresar a fondos claros;
- éxito, advertencia, error e información mantienen colores semánticos con variantes adecuadas para fondo oscuro;
- las áreas de cámara/video permanecen deliberadamente oscuras para conservar la lectura y el contraste del marco QR; solo se sincronizan sus bordes con el tema.

#### Validador de Localidad

La tarjeta principal de localidad usa el acento azul corporativo en estado válido y mantiene la semántica de advertencia cuando el ticket no tiene localidad. El grid de datos deja de alternar contra blancos rígidos y pasa a superficies compatibles con el tema.

#### Control de acceso por sesión

Se corrigen el selector de salón/sesión, la sesión actualmente seleccionada, el botón de cámara, estados deshabilitados por fecha, tarjetas de resultado y badges de acceso. La corrección **no habilita** controles que la lógica haya bloqueado por fecha o configuración; únicamente mantiene contraste y jerarquía visual.

#### Modal de Doble Autenticación

El modal de segundo factor necesita un tratamiento adicional porque puede renderizarse fuera de `#eventosapp-app-root`. La compatibilidad se limita a `.evda-auth-modal` dentro de `body.eventosapp-app-page` y sincroniza:

- diálogo, cabecera, cuerpo y footer;
- título, subtítulo y botón de cierre;
- código de acceso, inputs y ayudas;
- botones verificar/cancelar;
- ficha del ticket, estados y mensajes inline;
- acompañantes cuando corresponda.

Así el segundo factor no vuelve a una tarjeta blanca al abrirse desde Dark Mode.

#### Aislamiento frente a Elementor y tema

La prioridad adicional queda limitada a los wrappers funcionales de los lectores:

```text
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-checkin-app
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-localidad-app
body.eventosapp-app-page #eventosapp-app-root .evapp-qr-sesion-app
body.eventosapp-app-page #eventosapp-app-root .evapp-double-auth-app
body.eventosapp-app-page .evda-auth-modal
```

Los `!important` se concentran en propiedades visuales que deben ganar frente al Elementor Kit y al tema: fondos, bordes, colores, apariencia de controles, estados hover/focus y tokens de marca. No se cambian grids operativos, atributos, eventos JavaScript ni reglas de negocio.

### Alcance conservado

No se modifican:

- `includes/frontend/eventosapp-qr-checkin.php` ni sus funciones de lectura/check-in;
- `includes/frontend/eventosapp-qr-double-auth.php` ni su flujo de doble autenticación;
- permisos, roles, capacidades o evento activo;
- BarcodeDetector, jsQR, acceso a cámara, streams, canvas o ciclos de detección;
- búsqueda/resolución de tickets, QR Manager, QR legacy o QR preimpreso;
- nonces, AJAX, caché, consultas SQL o validaciones de pertenencia al evento;
- validación de fecha del evento ni check-in multidía;
- estado de check-in general;
- Validador de Localidad de solo lectura;
- sesiones/salones configurados ni registro independiente de acceso por sesión;
- código de seguridad, ventanas del segundo factor ni condiciones que exigen doble autenticación;
- acompañantes, recordatorios de pago ni metadatos del ticket;
- Dashboard, header común, drawer móvil, Registro Manual, Empresas con Check-In, Métricas, Seguridad ni otros módulos;
- estilos Elementor de páginas WordPress que no estén mapeadas como EventosApp.

### Archivos de 1.5.0-rc.11

```text
includes/frontend/eventosapp-qr-visual-compat.php    NUEVO
includes/frontend/eventosapp-frontend-helpers.php    MODIFICADO
README.md                                             MODIFICADO
```

### Commits funcionales

```text
a239b76c6710b8506b403c642151bfc6cf6adda8  feat: add QR reader visual compatibility layer
d3447c6b76c5b95a8ef44cd3a219f0cfe1ada9c9  feat: load QR reader compatibility layer
```

## Validación funcional requerida para 1.5.0-rc.11

- Abrir **Check-In con QR** en Light Mode y confirmar icono corporativo, paleta, evento activo, paneles, lector y resultado.
- Activar Dark Mode y confirmar que shell, contexto, paneles, ayudas, grids y estados permanecen oscuros y legibles; el visor de cámara debe seguir usando su fondo técnico oscuro.
- Probar Volver al dashboard, Activar cámara y escanear, detener/reiniciar cámara y Escanear otro QR en normal, hover, focus y disabled.
- Validar un QR correcto, QR incorrecto, ticket ya registrado y estados de advertencia/error sin cambios funcionales.
- Si el evento permite acompañantes, probar input, alta y mensajes de estado en ambos temas.
- Abrir **Validador de Localidad** y confirmar estado de solo lectura, localidad existente y localidad ausente en Light/Dark.
- Abrir **Control de acceso por sesión**, probar selector y opciones, sesión seleccionada, cámara, confirmación y estados de acceso.
- Validar también el escenario mostrado de **fuera de fecha del evento**: el botón debe continuar deshabilitado por lógica, pero con contraste correcto.
- Abrir **Check-In QR Doble Autenticación** y revisar evento activo, alerta de fecha, lector, resultado y estados en ambos temas.
- En un día/flujo que requiera segundo factor, abrir el modal y revisar diálogo, código, inputs, botones, mensajes y cierre en Light/Dark.
- Confirmar que éxito, advertencia, error e información mantienen su semántica en los cuatro lectores.
- Probar cámara en Chrome/Chromium y un navegador con fallback jsQR para confirmar que la capa visual no interfiere con detección.
- Probar 320 px, 375 px, 430 px, tablet y desktop; no debe aparecer overflow horizontal ni perderse ningún control.
- Regenerar CSS de Elementor y volver a abrir los cuatro lectores; los títulos, colores y superficies deben conservar la identidad EventosApp.
- Abrir una página WordPress no mapeada por EventosApp y confirmar que su Elementor Kit permanece intacto.

---

## Hotfix 1.5.0-rc.10 — Empresas con Check-In y Registro Manual: identidad, Dark Mode y aislamiento Elementor

### Incidencias detectadas

La revisión de `includes/frontend/eventosapp-company-checkin-monitor.php` y `includes/frontend/eventosapp-frontend-register.php` confirmó que ambos renderizadores conservan CSS inline histórico con colores claros y variables anteriores a la identidad corporativa actual. Al activar Dark Mode, esos valores competían con la capa común y con reglas globales de Elementor/tema.

Los síntomas principales eran:

1. **Empresas con Check-In:** shell, contexto, KPI, panel, tabla, filas móviles, chips, alias y estados podían conservar fondos claros mientras el texto ya heredaba la paleta oscura, provocando bajo contraste y componentes visualmente desconectados del Dashboard.
2. **Empresas con Check-In:** el encabezado `.evapp-company-eyebrow` no tenía la firma visual corporativa que ya existe en otros módulos modernos.
3. **Registro Manual:** inputs, selects y, especialmente, las tarjetas de **Entrega del ticket** conservaban blancos directos (`#fff`, `#fbfdff`, `#f8fbff`), generando tarjetas claras con textos preparados para Dark Mode.
4. Botones, hovers, focos, estados semánticos y controles podían volver a estilos del Elementor Kit o del tema porque los renderizadores tienen CSS inline propio y el builder puede generar reglas por instancia con mayor prioridad.

### Corrección aplicada

La solución amplía la capa final `includes/frontend/eventosapp-elementor-compat.php`, que se carga después de branding y del pulido visual. Se mantiene el mismo criterio de `1.5.0-rc.9`: corregir únicamente la presentación desde una capa posterior y aislada, evitando reescribir funciones operativas ya estables.

#### Imagen corporativa

- `.evapp-company-eyebrow` incorpora el icono oficial de EventosApp.
- `.evreg-eyebrow` refuerza el mismo icono corporativo para impedir que CSS externo lo reemplace.
- Light Mode usa la variante a color y Dark Mode la variante blanca.
- Los colores históricos de ambos módulos quedan remapeados a los tokens oficiales `#171e37`, `#3683c5` y `#286291`.

#### Empresas con Check-In

Dentro de `.evapp-company-monitor` se corrigen exclusivamente propiedades visuales:

- shell, contexto de evento, KPI, panel y tabla adoptan las superficies comunes de EventosApp;
- títulos, subtítulos, etiquetas, ayudas, empresa, NIT y fechas usan los tokens de texto del tema activo;
- búsqueda, selector, opciones, placeholder e icono mantienen contraste correcto en Light/Dark;
- botones Volver al dashboard, Cambiar evento, Limpiar filtros y Actualizar conservan estados normal, hover y focus corporativos;
- tabla, encabezados, hover de filas, ranking, conteos, alias y divisores dejan de regresar a fondos claros;
- loading, empty state, error y alerta de NIT mantienen su semántica con contraste apropiado;
- en móvil, las filas transformadas en tarjetas y la cabecera de empresa usan superficies del tema activo y no vuelven a blanco.

#### Registro Manual

Dentro de `.evreg-wrap` se refuerzan:

- secciones del formulario, contexto del evento y footer de envío;
- inputs, selects, opciones, placeholders y campos de solo lectura;
- tarjetas de **Entrega del ticket**, checkbox visual y estado seleccionado;
- botones primarios/secundarios y sus estados hover/focus;
- procesamiento del ticket;
- éxito, advertencia, error y resultados de envío por correo/WhatsApp;
- avisos inline de evento requerido o falta de permisos.

La corrección elimina específicamente el problema observado donde las opciones de correo y WhatsApp permanecían blancas dentro de Dark Mode.

#### Aislamiento frente a Elementor y tema

La prioridad adicional queda limitada a:

```text
body.eventosapp-app-page #eventosapp-app-root .evapp-company-monitor
body.eventosapp-app-page #eventosapp-app-root .evreg-wrap
```

Por ello Elementor y el tema continúan funcionando normalmente fuera de las páginas mapeadas por EventosApp. Los `!important` se concentran en propiedades visuales que deben respetar la identidad y el tema activo; no se alteran grids, eventos JavaScript ni reglas funcionales.

### Alcance conservado

No se modifican:

- `includes/frontend/eventosapp-company-checkin-monitor.php` ni su lógica de datos;
- `includes/frontend/eventosapp-frontend-register.php` ni su lógica de registro;
- permisos, roles, alcance por evento o evento activo;
- AJAX, nonce, caché, polling, filtros, ordenamiento ni agrupación de empresas;
- consultas de tickets, NIT, primera/última llegada ni optimizaciones del monitor;
- creación/actualización de tickets, deduplicación, idempotencia o validaciones del Registro Manual;
- QR preimpreso, localidades, modalidades ni campos adicionales;
- disponibilidad o segundo control de correo y WhatsApp;
- Dashboard, Métricas de Encuestas, header común, drawer móvil, login, Seguridad ni otros módulos.

### Archivos de 1.5.0-rc.10

```text
includes/frontend/eventosapp-elementor-compat.php    MODIFICADO
README.md                                             MODIFICADO
```

### Commit funcional

```text
087a4b7574719fcd75b5c3a6f9a7bee5ae276d13  fix: align company monitor and manual register with dark mode
```

## Validación funcional requerida para 1.5.0-rc.10

- Abrir **Empresas con Check-In** en Light Mode y confirmar icono corporativo junto a `EVENTOSAPP`, paleta oficial y ausencia de colores externos.
- Activar Dark Mode y confirmar que shell, contexto, KPI, panel, filtros y tabla permanecen oscuros y legibles.
- Confirmar que encabezados, filas, alias, ranking, conteos, loading, empty state y errores no regresan a blanco.
- Probar búsqueda por empresa/NIT, filtro, ordenamiento, Limpiar filtros y Actualizar; su comportamiento funcional debe permanecer idéntico.
- Revisar Volver al dashboard y Cambiar evento en normal, hover y focus.
- Probar la tabla responsive en 320 px, 375 px y 430 px y confirmar que las tarjetas móviles conservan el tema activo.
- Abrir **Registro Manual** en Light Mode y confirmar icono, paleta, inputs, selects, secciones y botones.
- Activar Dark Mode y confirmar que ningún input, select ni tarjeta de Entrega del ticket vuelve a blanco.
- Marcar/desmarcar Correo y WhatsApp y confirmar que checkbox, texto y estado seleccionado son legibles.
- Revisar procesamiento, éxito, advertencia y error en ambos temas.
- Crear y actualizar un ticket para confirmar que AJAX, idempotencia y deduplicación continúan funcionando.
- Si aplica, probar QR preimpreso, localidad, modalidad y campos adicionales.
- Si los canales están habilitados, probar los controles de correo y WhatsApp sin cambiar sus reglas backend.
- Regenerar CSS de Elementor y volver a abrir ambos módulos; la identidad corporativa debe mantenerse.
- Abrir una página WordPress no mapeada por EventosApp y confirmar que sus estilos Elementor permanecen intactos.

---

## Hotfix 1.5.0-rc.9 — Métricas de Encuestas: identidad, Dark Mode y aislamiento Elementor

### Incidencias detectadas

La validación de `includes/frontend/eventosapp-whatsapp-flow-metrics.php` mostró que el módulo ya heredaba parte del shell común, pero conservaba una capa visual histórica propia que todavía fijaba colores claros como `#3279bd`, `#255f96`, `#182230`, `#64748b`, `#ffffff`, `#f8fafc` y `#edf2f7` dentro de su CSS inline. En Dark Mode esto producía tablas y componentes excesivamente claros, textos con contraste irregular y controles que no coincidían con el Dashboard.

Además:

1. El encabezado del módulo utiliza `.evapp-flow-metrics-eyebrow`, selector que no estaba cubierto por la firma visual corporativa aplicada al resto de encabezados modernos.
2. Botones, chips, selector, estados, tablas, empty states y notas podían volver a colores del tema o Elementor porque el CSS inline del módulo y las reglas globales competían por prioridad.
3. Los gráficos Chart.js dibujan leyendas, ejes, grid, separadores y tooltips dentro de `canvas`; esos colores estaban definidos en JavaScript para Light Mode y no pueden corregirse únicamente con CSS.
4. Los gráficos se reconstruyen después de cada actualización AJAX, por lo que una corrección aplicada solo al cargar la página no era suficiente.

### Corrección aplicada

La solución amplía la capa final `includes/frontend/eventosapp-elementor-compat.php`, que ya se carga después del branding y del pulido de UI. No se reescribe el renderizador ni el motor de datos de Métricas de Encuestas.

#### Imagen corporativa

- `.evapp-flow-metrics-eyebrow` incorpora el icono oficial de EventosApp.
- Light Mode usa la variante corporativa a color.
- Dark Mode usa la variante blanca para conservar contraste.
- Los azules históricos del módulo quedan remapeados a `#3683c5` y `#286291` mediante los tokens centrales.

#### Dark Mode completo

Dentro de `.evapp-flow-metrics-app` se refuerzan únicamente propiedades visuales:

- shell, contexto del evento, toolbar, KPI y cards usan las superficies comunes de EventosApp;
- títulos, textos secundarios, etiquetas y ayudas usan los tokens de texto del tema activo;
- botones primarios y secundarios mantienen estados normal, hover y focus compatibles;
- chips, selector, estados de carga/éxito/error, barras de progreso, empty states y notas dejan de regresar a fondos blancos;
- tabla, encabezados y celdas adoptan superficies, bordes y texto compatibles con Dark Mode;
- éxito, advertencia, error y morado mantienen su semántica con variantes de contraste apropiadas para fondo oscuro.

#### Chart.js compatible con el tema

La capa de compatibilidad obtiene las instancias existentes mediante `Chart.getChart(canvas)` y sincroniza únicamente opciones visuales:

- color de leyendas;
- color de ticks y ejes;
- color del grid de barras;
- separador de segmentos doughnut;
- fondo, borde y texto de tooltips.

Un `MutationObserver` escucha el cambio de `data-evapp-theme` para actualizar gráficos sin recargar la página. Otro observa el contenido del módulo para volver a sincronizar los gráficos que el AJAX reconstruye al cambiar o actualizar la encuesta.

Los datos, porcentajes, datasets, paleta de series, tipo de gráfico y callbacks funcionales originales permanecen intactos.

#### Aislamiento frente a Elementor y tema

Los selectores se limitan a:

```text
body.eventosapp-app-page #eventosapp-app-root .evapp-flow-metrics-app
```

Por ello la prioridad adicional solo existe dentro de páginas mapeadas por EventosApp. El Elementor Kit y el tema continúan funcionando normalmente fuera de la aplicación.

### Alcance conservado

No se modifican:

- `includes/frontend/eventosapp-whatsapp-flow-metrics.php` ni su lógica operativa;
- permisos, roles o `flow_metrics`;
- evento activo y cambio de evento;
- selección del Flow configurado;
- consultas SQL, conteos, tasas o agregación de respuestas;
- procesamiento por lotes y transients de caché;
- endpoints AJAX, nonce ni mensajes de error;
- generación, autorización o descarga del CSV;
- datos, porcentajes, datasets o callbacks de Chart.js;
- Dashboard, login, Seguridad, drawer móvil ni otros módulos.

### Archivos de 1.5.0-rc.9

```text
includes/frontend/eventosapp-elementor-compat.php    MODIFICADO
README.md                                             MODIFICADO
```

### Commit funcional

```text
7d844026469c0a65ac9dcca882b129d851cbfc5d  fix: align survey metrics with dark mode and corporate styles
```

## Validación funcional requerida para 1.5.0-rc.9

- Abrir Métricas de Encuestas en Light Mode y confirmar icono corporativo junto a `EVENTOSAPP`, paleta oficial y ausencia de colores ajenos a EventosApp.
- Activar Dark Mode y confirmar que shell, contexto, toolbar, KPI, cards, tablas, selector, chips, estados, empty states y nota permanecen oscuros y legibles.
- Confirmar que ninguna fila o encabezado de tabla vuelve a blanco y que los bordes conservan contraste.
- Revisar botones Volver al dashboard, Cambiar evento, Actualizar y Descargar CSV en normal, hover y focus.
- Confirmar que éxito, advertencia, error y estados morados mantienen su semántica y contraste.
- Revisar gráficos doughnut y de barras: leyendas, ticks, grid, separadores y tooltips deben ser legibles en Light y Dark Mode.
- Cambiar Light/Dark con gráficos ya renderizados y confirmar que se actualizan sin recargar.
- Presionar Actualizar y confirmar que los gráficos reconstruidos por AJAX adoptan inmediatamente el tema vigente.
- Si existe más de un Flow, cambiar la encuesta y confirmar el mismo comportamiento.
- Descargar CSV y confirmar que autorización, nonce, columnas y resultados siguen funcionando.
- Regenerar CSS de Elementor y volver a abrir el módulo; la identidad corporativa debe mantenerse.
- Probar 320 px, 375 px, 430 px, tablet y desktop sin overflow ni pérdida de funcionalidad.
- Abrir una página WordPress no mapeada por EventosApp y confirmar que sus estilos Elementor permanecen intactos.

---

## Hotfix 1.5.0-rc.8 — Drawer utilizable y aislamiento de estilos Elementor

### Incidencias detectadas

La validación móvil de `1.5.0-rc.7` confirmó que el drawer lateral sí se abría, pero el backdrop oscuro terminaba visualmente y funcionalmente por encima del menú. La causa no estaba en el `z-index` del drawer hijo sino en el contexto de apilado creado por `.evapp-app-chrome`: el header tenía su propio `z-index`, mientras el backdrop se insertaba directamente como hermano en `body`. Un hijo no puede escapar del stacking context de su padre aunque tenga un `z-index` numéricamente mayor.

También se confirmó que reglas globales de Elementor/tema seguían alcanzando controles de EventosApp. El síntoma más visible era la aparición de colores genéricos en botones como **Modo claro/oscuro**, además de valores históricos del widget Dashboard (`#3279bd`, `#255f96`, `#182230`, `#64748b`) que podían competir con la paleta corporativa.

### Corrección aplicada

Se agrega `includes/frontend/eventosapp-elementor-compat.php` como una capa final de compatibilidad que se carga después de `eventosapp-branding.php` y `eventosapp-ui-polish.php`.

#### Drawer móvil y backdrop

- cuando el menú móvil está abierto se eleva el stacking context completo de `.evapp-app-chrome`;
- el backdrop permanece inmediatamente por debajo del header/drawer y por encima del contenido de la aplicación;
- los botones y enlaces del drawer recuperan interacción normal porque la capa oscura ya no captura sus clics;
- se conserva el cierre por `X`, backdrop y tecla `Escape` existente en `1.5.0-rc.7`;
- no se modifica el JavaScript del drawer ni las URLs de sus acciones.

#### Aislamiento frente a Elementor y tema

Dentro de páginas mapeadas por EventosApp:

- las variables globales principales de Elementor (`--e-global-color-primary`, `secondary`, `text`, `accent`) se remapean a la identidad corporativa únicamente dentro de `#eventosapp-app-root`;
- los aliases de color usados por las distintas generaciones de módulos (`--evapp-*`, `--ev-*`, `--esp-*`, `--evchk-*`, `--evc-*` y equivalentes) reciben prioridad mediante `!important` solo en propiedades de marca;
- el Dashboard fuerza los tokens corporativos que Elementor también genera por instancia, evitando que reaparezcan sus defaults históricos;
- títulos, textos secundarios, superficies, bordes, buscador y selector del Dashboard heredan los tokens reforzados;
- los botones principales del Dashboard conservan Azul / Azul oscuro corporativo;
- el header fija explícitamente fondos, bordes y colores de Modo, Administración y Cerrar sesión para impedir que reglas globales de `button` o `a` del tema/Elementor introduzcan acentos ajenos a EventosApp;
- el mismo refuerzo se aplica a los controles Menú/Cerrar del drawer móvil.

Este aislamiento no se aplica al resto del sitio WordPress. Las páginas que no están mapeadas como EventosApp continúan usando normalmente su Elementor Kit y estilos del tema.

### Prioridad de marca frente a controles Elementor

A partir de este hotfix, los **colores estructurales de marca** dentro de páginas EventosApp tienen prioridad sobre los valores predeterminados o generados por instancia de Elementor. Esto es intencional para evitar que un widget antiguo o un CSS regenerado vuelva a introducir la paleta anterior.

Los controles de contenido, columnas, espaciados, responsive y demás opciones funcionales del widget permanecen sin cambios. No se modifica el motor `eventosapp_render_dashboard()`.

### Alcance conservado

No se modifican:

- permisos, roles ni capacidades;
- login, logout, Seguridad, reCAPTCHA ni auditoría;
- evento activo ni selector funcional;
- URLs firmadas de Administración y Cerrar sesión;
- JavaScript del drawer;
- buscador, filtrado, conteos o tarjetas funcionales del Dashboard;
- renderizadores de módulos;
- Check-In, Registro, QR, Kiosko, Checklist, Soporte, Consumibles, Networking, Expositores ni Sorteo.

### Archivos de 1.5.0-rc.8

```text
includes/frontend/eventosapp-elementor-compat.php    NUEVO
includes/frontend/eventosapp-frontend-helpers.php    MODIFICADO
README.md                                             MODIFICADO
```

### Commits funcionales

```text
3ba758273e0bd726a978ad9f196024bd635b9bd9  fix: isolate EventosApp styles from Elementor and drawer backdrop
e1b9f6c38f382604ae6d3beb34bb8856cf1b9aa3  feat: load Elementor compatibility layer after UI polish
```

## Validación funcional requerida para 1.5.0-rc.8

- En 320 px, 375 px, 430 px y 760 px, abrir el menú y confirmar que el backdrop oscurece el contenido pero **no** el drawer.
- Confirmar que Usuario activo, Modo claro/oscuro, Dashboard cuando corresponda, Administración y Cerrar sesión son clicables.
- Cerrar el drawer pulsando el backdrop fuera del menú y confirmar que continúa funcionando.
- Probar cierre por `X` y `Escape`.
- Confirmar que el scroll del documento se bloquea únicamente mientras el drawer está abierto.
- En desktop, confirmar que **Modo claro/oscuro** ya no adopta colores genéricos de Elementor/tema.
- Revisar Administración y Cerrar sesión en normal, hover y focus.
- Revisar Dashboard en Light y Dark Mode y confirmar que títulos, textos, superficies, bordes, buscador, selector y botones utilizan la paleta corporativa.
- Regenerar CSS de Elementor y volver a cargar el Dashboard; la paleta corporativa debe mantenerse.
- Abrir al menos dos módulos adicionales y confirmar que sus aliases visuales continúan respetando Light/Dark Mode.
- Abrir una página WordPress no mapeada por EventosApp y confirmar que sus colores Elementor permanecen intactos.

---

## Hotfix 1.5.0-rc.7 — Dark Mode, header responsive y buscador del Dashboard

### Incidencias detectadas

La validación visual posterior a `1.5.0-rc.6` mostró cuatro incompatibilidades que no afectan la lógica operativa, pero sí la experiencia visual:

1. El Dashboard real utiliza `.evapp-dashboard-eyebrow`, mientras la capa corporativa anterior cubría `.evapp-eyebrow`; por ello el icono oficial no aparecía junto a la firma `EVENTOSAPP` del encabezado.
2. Algunos tokens visuales secundarios (`--evapp-primary-soft` y aliases equivalentes) continuaban apuntando a superficies claras en Dark Mode. Además, el Dashboard conserva un `background:#fff` histórico en `.evapp-card:hover`, provocando tarjetas blancas con texto preparado para modo oscuro.
3. El header responsive anterior envolvía usuario y acciones en varias filas. En teléfonos esto consumía una parte excesiva del viewport y hacía más lenta la navegación.
4. Aunque el Dashboard ya había eliminado las decoraciones nativas de `type="search"`, estilos globales de Elementor/tema podían volver a reducir el padding izquierdo del input y permitir que la lupa invadiera el placeholder o el texto escrito.

### Corrección aplicada

Se agrega `includes/frontend/eventosapp-ui-polish.php` como capa visual posterior a `eventosapp-branding.php`. La nueva capa solo se activa en páginas gestionadas por EventosApp y no modifica renderizadores, permisos ni lógica de negocio.

#### Dark Mode compatible

- Los aliases `soft` de las distintas generaciones de UI ahora reciben fondos azulados oscuros en Dark Mode.
- Dashboard, contexto de evento, tarjetas, selector, avisos y estados vacíos conservan superficies oscuras coherentes.
- `.evapp-card:hover` usa una superficie elevada oscura en lugar de volver a blanco.
- Flechas, chips e iconos secundarios utilizan fondos azules translúcidos y mantienen contraste.
- Cards interactivos de otros módulos no pueden regresar a blanco al hacer hover.
- El avatar del usuario y el avatar histórico de sesión usan el icono blanco oficial sobre un fondo azul translúcido cuando el tema es oscuro.
- Los hover del header mantienen contraste y el botón Administración pasa correctamente por la paleta Azul oscuro → Azul.

#### Imagen corporativa del Dashboard

El selector correcto `.evapp-dashboard-eyebrow` recibe el icono oficial de EventosApp:

- variante a color en Light Mode;
- variante blanca en Dark Mode.

Se conserva el wordmark oficial del header y el icono corporativo del usuario ya implementados por la capa principal.

#### Header móvil con drawer lateral

En anchos de hasta 760 px, cuando existen controles de cuenta:

- el header conserva una sola fila compacta con el wordmark y un botón **Menú**;
- las acciones se despliegan desde el lado derecho en un drawer corporativo;
- Usuario activo, Modo claro/oscuro, Dashboard cuando corresponda, Administración y Cerrar sesión mantienen sus mismas URLs y permisos;
- el drawer se cierra con botón, backdrop, tecla `Escape`, navegación o al volver a desktop;
- el estado `aria-expanded` se mantiene sincronizado;
- si JavaScript no está disponible, la mejora progresiva no se activa y permanece el responsive anterior, evitando dejar acciones inaccesibles.

#### Buscador del Dashboard

El input `.evapp-module-search` ahora reserva de forma obligatoria 52 px a la izquierda y 48 px a la derecha. La lupa queda aislada con `z-index`, `isolation` y posición fija dentro de su wrapper. El reset de decoraciones WebKit se refuerza con selectores de mayor prioridad.

Esto evita que estilos globales de Elementor o del tema vuelvan a superponer la lupa con el placeholder o el término ingresado.

### Alcance conservado

No se modifican:

- permisos, roles ni capacidades;
- selección o persistencia del evento activo;
- login, logout, reCAPTCHA, Seguridad ni auditoría;
- URLs firmadas de Administración y Cerrar sesión;
- renderizadores del Dashboard o de módulos;
- búsqueda, filtrado, conteos o JavaScript funcional del Dashboard;
- Check-In, Registro, QR, Kiosko, Checklist, Soporte, Consumibles, Networking, Expositores ni Sorteo.

### Archivos de 1.5.0-rc.7

```text
includes/frontend/eventosapp-ui-polish.php          NUEVO
includes/frontend/eventosapp-frontend-helpers.php   MODIFICADO
README.md                                            MODIFICADO
```

### Commits funcionales

```text
df14b014f0266c196c5f1b93c52625c5e7830189  fix: polish dark mode dashboard and responsive app header
abbf9e18a40399c32aa97b35a2d67bba0b4f804d  feat: load responsive dark mode polish layer
```

## Validación funcional requerida para 1.5.0-rc.7

- Abrir Dashboard en Light Mode y confirmar icono corporativo junto a `EVENTOSAPP`.
- Activar Dark Mode y confirmar que el icono cambia a la variante blanca.
- Pasar el cursor por todas las tarjetas del Dashboard y confirmar que ninguna vuelve a fondo blanco.
- Confirmar que flechas, chips, avatar de usuario y fondos secundarios conservan contraste en oscuro.
- Probar Modo claro/oscuro, Administración y Cerrar sesión en hover/focus.
- En 320 px, 375 px, 430 px y 760 px, confirmar que el header muestra wordmark + botón Menú sin ocupar varias filas.
- Abrir el drawer, probar Modo claro/oscuro y confirmar que el drawer continúa utilizable.
- Abrir un módulo, confirmar que Dashboard aparece dentro del drawer y regresa correctamente al panel.
- Confirmar que Administración solo aparece para usuarios con `manage_options`.
- Confirmar que Cerrar sesión conserva su acción firmada.
- Cerrar el drawer con botón, backdrop y `Escape`.
- Redimensionar de móvil a desktop con el drawer abierto y confirmar que el estado se limpia sin bloquear scroll.
- Probar el buscador vacío y con texto en Chrome/Chromium, Safari y Firefox; la lupa nunca debe tocar el placeholder ni el término escrito.
- Confirmar que el botón `×`, conteos, filtrado por módulos y estado sin resultados continúan funcionando.
- Revisar al menos un módulo adicional en Dark Mode para confirmar que cards interactivos no regresan a blanco en hover.

---

## Candidato 1.5.0-rc.6 — Shell de aplicación unificado, Dark Mode y páginas sin chrome del tema

### Objetivo

Cerrar las inconsistencias visuales detectadas después de `1.5.0-rc.5`: algunos módulos mostraban la marca y la barra de sesión dentro de posiciones distintas porque cada renderizador conserva una estructura histórica diferente, y las páginas mapeadas todavía podían heredar el header/footer del tema de WordPress.

Este candidato unifica la experiencia desde una capa superior común sin reescribir la lógica de cada módulo.

### Páginas consideradas parte de la aplicación

`includes/frontend/eventosapp-branding.php` detecta como páginas administradas por EventosApp:

- todos los IDs configurados en `eventosapp_pages`;
- los IDs históricos almacenados en `eventosapp_networking_pages`;
- la página de Login configurada por Seguridad;
- la página 404 configurada por Seguridad.

Las páginas ajenas a estos mapas conservan el template, header y footer del sitio sin cambios.

### Header corporativo único

Todas las páginas mapeadas pasan a tener un header propio de EventosApp con:

- wordmark oficial blanco;
- usuario activo y `@usuario` cuando corresponde;
- botón **Dashboard** cuando se navega dentro de un módulo;
- botón **Administración** únicamente para usuarios con `manage_options`;
- botón **Cerrar sesión**;
- selector **Modo oscuro / Modo claro**.

La antigua barra `.evapp-session-panel` continúa disponible internamente por compatibilidad con los renderizadores históricos, pero queda oculta dentro de las páginas tipo aplicación para evitar duplicidad. Sus mismas acciones se exponen ahora en el header común, por lo que Métricas, Check-In, Dashboard y demás módulos dejan de depender de la posición particular de cada shell para mostrar cuenta y sesión.

Autogestión/Kiosko, Sorteo Público y la experiencia 404 mantienen el criterio histórico de no exponer datos de la sesión operativa; allí el header conserva marca y selector de tema sin mostrar información privada del usuario.

### Light Mode y Dark Mode

El modo claro actual se conserva como predeterminado. El nuevo Dark Mode utiliza la paleta corporativa como base y añade superficies oscuras compatibles:

```text
Marca Oscuro      #171e37
Marca Azul        #3683c5
Marca Azul oscuro #286291
Canvas oscuro     #0e1424
Superficie        #171e37
Superficie elevada#1d2742
Texto             #f3f6fb
Borde             #2c3958
```

La capa dark no invierte imágenes ni aplica filtros globales. En su lugar:

- remapea los tokens visuales de las distintas generaciones de módulos;
- adapta superficies, shells, cards, formularios, tablas, textos secundarios y bordes;
- conserva los colores semánticos propios de éxito, alerta y peligro;
- cambia el logo del Login/404 a la variante blanca cuando el fondo es oscuro;
- mantiene el azul corporativo como acento, foco y acción principal.

### Persistencia de la preferencia

La elección visual se guarda de dos formas:

1. **Usuario autenticado:** user meta `_eventosapp_ui_theme`, sincronizado mediante AJAX protegido con nonce.
2. **Navegador:** `localStorage`, como respaldo inmediato y para visitantes sin sesión.

Al abrir nuevamente EventosApp, el script de bootstrap aplica la preferencia antes del render principal para reducir el cambio visual entre Light y Dark.

### Páginas sin header/footer del sitio

Se incorpora `templates/eventosapp-app-page.php` como template exclusivo para páginas mapeadas.

El template:

- no ejecuta `get_header()` ni `get_footer()`;
- mantiene `wp_head()`, `wp_body_open()`, `the_content()` y `wp_footer()`;
- conserva por ello Elementor, shortcodes, assets, AJAX y hooks existentes;
- renderiza únicamente el header de EventosApp y el contenido funcional de la página.

También existe una regla CSS de respaldo para ocultar ubicaciones comunes de header/footer si un builder fuerza su propio template.

### Compatibilidad y alcance conservado

No se modifican renderizadores de Check-In, Métricas, Registro, QR, Checklist, Soporte, Consumibles, Expositores, Networking, Sorteo ni Dashboard. Tampoco se cambian permisos, eventos activos, login, logout, reCAPTCHA, auditoría, Seguridad, endpoints AJAX ni acciones de negocio.

La solución se concentra en la capa visual compartida para que cualquier módulo mapeado herede automáticamente la misma experiencia.

### Archivos de 1.5.0-rc.6

```text
includes/frontend/eventosapp-branding.php   MODIFICADO
assets/css/eventosapp-branding.css          MODIFICADO
templates/eventosapp-app-page.php           NUEVO
README.md                                   MODIFICADO
```

### Commits funcionales

```text
7278bcdb964c18556ae621703fa91e95611e7377  feat: add unified app shell and persistent theme preference
a48be7d5ea8c212c439efb9bc271f5cc3282808c  feat: render mapped pages without theme header or footer
779e9e28e9af599cb34d6ef52622e768d784293c  feat: unify corporate chrome and add light dark themes
```

## Validación funcional requerida para 1.5.0-rc.6

- Abrir Dashboard, Métricas de Encuestas y Check-In Manual y confirmar que el header superior es exactamente la misma implementación en los tres.
- Confirmar logo oficial, Usuario activo, Administración solo para admin, Cerrar sesión y Dashboard dentro de módulos.
- Confirmar que la antigua barra de sesión no aparece duplicada dentro del contenido.
- Cambiar a Dark Mode, recargar y confirmar que permanece oscuro.
- Cerrar sesión, volver a ingresar con el mismo usuario y confirmar persistencia.
- Cambiar de nuevo a Light Mode y confirmar la misma persistencia.
- Probar Dashboard sin sesión y confirmar Login con logo, selector de tema y sin header/footer del sitio.
- Probar Kiosko/Autogestión y Sorteo Público y confirmar que no muestran usuario, Administración ni Cerrar sesión.
- Confirmar que páginas no mapeadas de WordPress siguen mostrando el header/footer normal del sitio.
- Probar 320 px, 375 px, 430 px, tablet, portátil y desktop; el header debe envolver sus acciones sin overflow horizontal.
- Revisar formularios, tablas, cards, empty states y estados semánticos en Dark Mode.
- Confirmar que Elementor y shortcodes continúan cargando CSS/JS porque el template conserva `wp_head()` y `wp_footer()`.

---

## Candidato 1.5.0-rc.5 — Identidad corporativa oficial de EventosApp

### Objetivo

Integrar la imagen corporativa suministrada a la UI existente de EventosApp **sin rediseñar los módulos ni modificar su lógica**, de forma que Dashboard, módulos frontend, acceso, sesión y pantallas administrativas compartan una identidad visual consistente.

La implementación se plantea como una capa de marca centralizada: los componentes conservan su estructura, distribución, responsive, funciones, permisos y estados actuales, mientras los colores e identificadores visuales adoptan la marca oficial.

### Paleta corporativa

Los colores entregados se normalizan a RGB hexadecimal opaco —el sufijo `ff` recibido corresponde a alfa 100%— y quedan disponibles como tokens globales:

```text
Oscuro      #171e37
Azul        #3683c5
Azul oscuro #286291
```

La capa de identidad mapea esta paleta sobre las distintas generaciones de variables visuales que ya existen en EventosApp (`--evapp-*`, `--ev-*`, `--esp-*`, `--evchk-*`) sin obligar a reescribir cada módulo.

### Activos oficiales integrados

Se incorporan cuatro variantes vectoriales derivadas directamente de los archivos corporativos suministrados:

```text
assets/branding/eventosapp_color.svg
assets/branding/eventosapp_blanco.svg
assets/branding/eventosapp_icon.svg
assets/branding/eventosapp_icon_blanco.svg
```

Los SVG originales fueron optimizados sin rasterizar ni alterar su apariencia para reducir peso de repositorio y transferencia. Se conserva la versión vectorial como activo de ejecución para obtener nitidez en pantallas retina, escalado responsive y una sola fuente visual por variante.

### Capa central de identidad

`includes/frontend/eventosapp-branding.php` centraliza:

- los colores oficiales de la marca;
- las URLs de los cuatro activos corporativos;
- la carga de la hoja `assets/css/eventosapp-branding.css` al final del render;
- la aplicación al frontend y únicamente a pantallas administrativas pertenecientes a EventosApp;
- la resolución absoluta de las rutas de los SVG cuando la hoja se imprime inline, evitando dependencias de la URL de la página actual.

`includes/frontend/eventosapp-frontend-helpers.php` conserva toda su lógica histórica y solamente carga esta nueva capa después de Seguridad y de la UI de sesión.

### Integración sobre la UI existente

La hoja corporativa no crea un segundo diseño. Actúa sobre los componentes existentes:

- **Dashboard:** adopta Azul, Azul oscuro y Oscuro en sus tokens principales, manteniendo tarjetas, buscador, categorías, grids, responsive y controles Elementor.
- **Login integrado:** utiliza el wordmark oficial de EventosApp y la paleta corporativa en foco, acción principal y enlaces secundarios, sin modificar autenticación, reCAPTCHA, nonce, límites ni redirecciones.
- **Panel de sesión:** conserva exactamente su distribución y reemplaza el pictograma genérico del avatar por el icono oficial de EventosApp dentro del mismo espacio; Administración usa el Oscuro corporativo.
- **Módulos frontend:** los shells de EventosApp reciben los aliases cromáticos oficiales y los controles primarios heredan Azul/Azul oscuro sin tocar acciones semánticas de peligro o éxito.
- **Administración:** las pantallas propias de EventosApp reciben el icono corporativo junto al encabezado y botones primarios con la paleta oficial, sin aplicar cambios al resto del backend.
- **Estados de foco:** los campos de EventosApp usan el Azul corporativo como acento de accesibilidad, sin modificar dimensiones, tipografía ni comportamiento.

### Compatibilidad con Elementor y módulos existentes

La hoja utiliza selectores de baja especificidad para reemplazar los valores visuales por defecto. Los selectores generados por Elementor para una personalización explícita del widget mantienen mayor especificidad, por lo que los controles de estilo ya existentes continúan funcionando.

No se modifican `eventosapp-frontend-dashboard.php`, `eventosapp-dashboard-elementor.php`, `eventosapp-session-ui.php`, `eventosapp-security.php` ni archivos funcionales de los módulos. Esto evita reescribir lógica ya validada y reduce el riesgo de regresiones.

Se conservan explícitamente:

- permisos y alcances por evento;
- sesión, login, logout y Seguridad;
- check-in, QR, Kiosko/Autogestión y edición;
- Checklist, Equipo de apoyo y sus métricas;
- Consumibles y transacciones;
- Networking;
- Expositores;
- Sorteo y pantalla pública;
- layouts, espaciados, tipografía y responsive existentes;
- estados semánticos de error, peligro, éxito y advertencia.

### Archivos de 1.5.0-rc.5

```text
assets/branding/eventosapp_color.svg                  NUEVO
assets/branding/eventosapp_blanco.svg                 NUEVO
assets/branding/eventosapp_icon.svg                   NUEVO
assets/branding/eventosapp_icon_blanco.svg            NUEVO
assets/css/eventosapp-branding.css                    NUEVO
includes/frontend/eventosapp-branding.php             NUEVO
includes/frontend/eventosapp-frontend-helpers.php     MODIFICADO
README.md                                              MODIFICADO
```

### Commits funcionales de identidad corporativa

```text
90ee7bd7f88ecafc585ec3f180a049ee3277b5cb  feat: add official EventosApp color icon asset
7a387e132b70929d957b412fe2df5a1c0f8af9ee  feat: add official EventosApp white icon asset
f32b3c84d97d6576b0d6fe123947fd4d3d8ccf2e  feat: add official EventosApp color wordmark
d283573a013eb24079ae907c610606d8656d4f50  feat: add official EventosApp white wordmark
5b30c5855370e807193edb998deaeaa22a0d7e4d  feat: add centralized corporate branding layer
2f36187555f5fc7939d61b381b878bec1af9c0b5  feat: load EventosApp corporate brand tokens and assets
e26498e040e46167ceaaa39ac24fd797f8420107  feat: load corporate branding after frontend helpers
```

## Validación funcional requerida para 1.5.0-rc.5

- Abrir el Dashboard autenticado y confirmar paleta, tarjetas, buscador, selector de evento, sesión y acciones sin cambios de layout.
- Abrir `/dashboard/` sin sesión y confirmar que el login muestra el wordmark oficial y conserva usuario, contraseña, mostrar/ocultar, reCAPTCHA y mensajes.
- Revisar Métricas, Registro, Check-In, QR, Edición, Checklist, Asistencia, Consumibles, Networking, Expositores y Sorteo según permisos disponibles.
- Confirmar que botones de peligro/éxito mantienen su semántica y no fueron convertidos al color principal.
- Revisar las pantallas de administración de EventosApp y confirmar que la marca no se extiende a páginas administrativas ajenas al plugin.
- Probar 320 px, 375 px, 430 px, tablet y desktop para confirmar que la capa de marca no altera el responsive existente.
- En el widget Dashboard de Elementor, cambiar temporalmente un color desde sus controles y confirmar que la personalización explícita continúa prevaleciendo sobre el valor corporativo por defecto.
- Verificar Kiosko/Autogestión y Sorteo Público para confirmar que no aparecen nuevos controles de sesión ni elementos estructurales.

---

## Hotfix 1.5.0-rc.4 — Corrección visual del buscador del Dashboard

### Incidencia detectada

El Dashboard ya dibuja un icono SVG propio dentro de `.evapp-module-search-wrap`, pero el campo usa `type="search"`. En navegadores Chromium/WebKit el control puede conservar decoraciones nativas del buscador, incluida la decoración de búsqueda y el botón de cancelación. Al coexistir con el SVG de EventosApp, esas decoraciones pueden superponerse y producir el efecto visual mostrado en el campo: un icono deformado/doble que invade el placeholder o el texto escrito.

La lógica de filtrado, el conteo de herramientas y el botón de limpieza personalizado funcionaban correctamente; el problema estaba limitado a la presentación nativa del input.

### Corrección aplicada

`includes/frontend/eventosapp-frontend-dashboard.php` mantiene un único icono visual —el SVG propio de EventosApp— y normaliza el control de búsqueda:

- se aplica `appearance:none` / `-webkit-appearance:none` al input;
- se ocultan `::-webkit-search-decoration`, `::-webkit-search-cancel-button`, `::-webkit-search-results-button` y `::-webkit-search-results-decoration`;
- se conserva el botón de limpieza propio de EventosApp, por lo que no se pierde ninguna acción;
- el SVG queda explícitamente aislado por encima del input con `z-index`, `display:block` y sin interacción de puntero;
- el wrapper recibe `min-width:0` para evitar desbordes dentro de layouts flexibles;
- se fija un `line-height` estable para que el texto permanezca centrado verticalmente.

No se modifican el JavaScript de búsqueda, permisos, módulos visibles, filtros, evento activo, sesión, URLs ni comportamiento de las tarjetas.

### Compatibilidad revisada

El widget `includes/frontend/eventosapp-dashboard-elementor.php` reutiliza `eventosapp_render_dashboard()` y sus controles del buscador solo personalizan fondo, color de texto, borde y radio. Por ello no necesita una corrección paralela y continúa heredando este hotfix desde el render central del Dashboard.

La solución también se aplica al shortcode histórico `[eventosapp_dashboard]`, ya que ambos caminos comparten exactamente el mismo CSS y motor de render.

### Archivos de 1.5.0-rc.4

```text
includes/frontend/eventosapp-frontend-dashboard.php  MODIFICADO
README.md                                             MODIFICADO
```

### Commit funcional

```text
8ada9578056c2da36493ec46905a6a7c607fbdf5  fix: isolate dashboard search icon from native search decoration
```

## Validación funcional requerida para 1.5.0-rc.4

- Abrir el Dashboard en Chrome/Chromium sobre macOS y confirmar que solo existe un icono de búsqueda.
- Repetir con el campo vacío y con texto escrito; el icono no debe tocar el placeholder ni el término ingresado.
- Confirmar que el botón `×` propio aparece únicamente cuando hay una búsqueda y limpia correctamente el filtro.
- Confirmar que el conteo de herramientas, categorías visibles y estado “sin resultados” siguen actualizándose.
- Probar desktop, tablet y móvil, incluyendo anchos de 320 px, 375 px y 430 px.
- Verificar Safari y Firefox para confirmar que el reset no altera foco, escritura ni accesibilidad del campo.
- Si el Dashboard se renderiza mediante Elementor, confirmar que los controles de fondo, texto, borde y radio del buscador continúan aplicándose.

---

## Hotfix 1.5.0-rc.3 — Integración real de sesión y login en los renderizadores frontend

### Incidencia detectada

La implementación `1.5.0-rc.2` sí contenía la barra de sesión y el reemplazo del mensaje de acceso por `[custom_login_basic]`, pero dependía principalmente de `do_shortcode_tag` y `the_content`.

El Dashboard y varios módulos actuales se muestran mediante widgets de Elementor que llaman directamente a funciones como `eventosapp_render_dashboard()` y `eventosapp_render_metrics()`. En ese flujo el HTML final del widget puede no pasar por `do_shortcode_tag`, y el contenido interno del widget tampoco queda disponible en el punto esperado de `the_content`. Por eso la lógica existía en el código pero no aparecía en la UI mostrada por Elementor.

### Corrección aplicada

`includes/frontend/eventosapp-session-ui.php` ahora cubre explícitamente los tres caminos de render existentes:

1. **Shortcodes tradicionales** mediante `do_shortcode_tag`.
2. **Widgets de Elementor** mediante `elementor/widget/render_content` sobre el HTML final del widget.
3. **Renderizadores/custom layouts** mediante `the_content` y un fallback de montaje dentro del shell visual de EventosApp.

El fallback nunca utiliza `position: fixed`: localiza el contenedor principal `evapp-*-shell` y monta el control dentro de la tarjeta/módulo, preferentemente inmediatamente después del encabezado.

### Dashboard sin sesión

Cuando `/dashboard/` se abre sin una sesión activa:

- el widget `eventosapp_dashboard` es interceptado después de su render real;
- se elimina de la salida el antiguo estado “Debes iniciar sesión / Iniciar sesión”;
- se muestra directamente el formulario completo de `[custom_login_basic]`;
- el POST continúa procesándose por `EventosApp_Security::process_login()`;
- se conservan nonce, reCAPTCHA, límite de intentos, auditoría y reglas por rol;
- los errores de login continúan regresando al Dashboard;
- `the_content` mantiene un segundo fallback para la página configurada como Dashboard cuando no interviene el widget.

### Sesión integrada en Dashboard y módulos

El control ahora se inserta dentro del shell visual real del Dashboard y de los módulos configurados, incluidos los renderizados por Elementor.

La barra muestra claramente:

- etiqueta **Usuario activo**;
- nombre visible;
- `@usuario`;
- botón **Dashboard** cuando se está dentro de un módulo;
- botón **Administración** solo cuando el usuario tiene capacidad administrativa;
- botón **Cerrar sesión** para cualquier usuario autenticado.

El diseño es compacto, responsive y forma parte del flujo del panel. En móvil las acciones se reorganizan en grilla y luego en una sola columna cuando el ancho es muy reducido.

Autogestión/Kiosko y Sorteo Público siguen excluidos para no exponer la sesión operativa.

### White-label

La capa visible mantiene la identidad de EventosApp:

- el acceso técnico visible se llama **Administración**;
- no se muestra el nombre de la plataforma subyacente en el control de sesión;
- el botón administrativo usa una acción firmada de EventosApp y redirección del lado del servidor;
- el cierre de sesión usa igualmente una acción firmada de EventosApp;
- la barra administrativa del frontend continúa oculta para todos los roles.

### Archivo funcional de 1.5.0-rc.3

```text
includes/frontend/eventosapp-session-ui.php  MODIFICADO
README.md                                     MODIFICADO
```

### Commit funcional

```text
87e3dd088fa39909c964d98b57618acc7e177d18  fix: render session controls inside dashboard and Elementor modules
```

## Validación funcional requerida para 1.5.0-rc.3

### Dashboard sin sesión

- Abrir `/dashboard/` en ventana incógnita.
- Confirmar que aparece el formulario visual completo de `[custom_login_basic]`.
- Confirmar que ya no aparece únicamente “Debes iniciar sesión / Iniciar sesión”.
- Probar mostrar/ocultar contraseña.
- Probar credenciales correctas e incorrectas.
- Probar reCAPTCHA cuando esté configurado.
- Confirmar que los errores permanecen en `/dashboard/`.

### Dashboard autenticado

- Confirmar **Usuario activo**, nombre y `@usuario` dentro del panel principal.
- Confirmar **Cerrar sesión**.
- Como Administrador, confirmar **Administración**.
- Como rol no administrativo, confirmar que **Administración** no existe.
- Confirmar que el control no flota ni tapa tarjetas del Dashboard.

### Módulos

- Abrir Métricas desde el Dashboard y confirmar el control dentro de `.evapp-metrics-shell`, debajo del encabezado.
- Repetir con Registro, Check-In Manual, QR, Edición, Checklist, Asistencia, Consumibles, Networking y Expositores según permisos.
- Confirmar botón **Dashboard** en módulos.
- Confirmar que no se duplica el control si el módulo se renderiza por Elementor.
- Confirmar que Kiosko/Autogestión y Sorteo Público continúan sin mostrar sesión.

### Responsive y white-label

- Probar 320 px, 375 px, 430 px, tablet y desktop.
- Confirmar que la barra no reduce el viewport ni utiliza posiciones flotantes.
- Confirmar que no aparece ninguna referencia visual a la plataforma subyacente.
- Confirmar que los enlaces visibles de **Administración** y **Cerrar sesión** usan rutas de EventosApp.

---

## Hotfix 1.5.0-rc.2 — Login en Dashboard, sesión integrada y frontend white-label

### Objetivo

Corregir tres puntos de experiencia detectados después de integrar Seguridad en `1.5.0-rc.1`, sin modificar el motor de autenticación, permisos, auditoría ni funciones operativas existentes.

### Dashboard sin sesión

Cuando una persona abre la página configurada como Dashboard y todavía no inició sesión:

- ya no se muestra únicamente el aviso “Debes iniciar sesión” con un enlace externo;
- el Dashboard renderiza directamente el formulario real de `[custom_login_basic]`;
- el formulario utiliza el mismo motor de autenticación de `EventosApp_Security`;
- se conserva nonce, límite de intentos, auditoría, validación de roles y reCAPTCHA;
- si reCAPTCHA está configurado, su script también se carga cuando el formulario aparece dinámicamente dentro del Dashboard;
- los errores de credenciales, reCAPTCHA, bloqueo, rol o nonce permanecen en `/dashboard/` para evitar sacar al usuario del contexto de acceso;
- un login correcto conserva las redirecciones por rol ya definidas en Seguridad.

No se duplicó la autenticación ni se creó un segundo sistema de credenciales.

### Usuario activo y acciones de sesión

El control flotante introducido en `1.5.0-rc.1` se elimina visualmente.

Ahora el usuario activo se presenta como una **barra integrada en el flujo de la UI**:

- dentro de `.evapp-dashboard-shell` en el Dashboard;
- al inicio del contenido de las páginas de módulos configuradas;
- nunca usa `position: fixed` ni se superpone a contenido;
- nombre visible y nombre de usuario permanecen disponibles;
- en módulos aparece **Panel** para regresar al Dashboard;
- todos los usuarios autenticados disponen de **Cerrar sesión**;
- solamente Administradores ven **Administración**;
- el responsive reorganiza las acciones como grilla y pasa a una columna en pantallas muy angostas.

Se mantienen excluidas las pantallas de Autogestión/Kiosko y Sorteo Público para no exponer la sesión operativa a asistentes o público.

### Frontend white-label

La UI pública de esta capa deja de mostrar referencias a la plataforma subyacente:

- se elimina la tarjeta anterior de backend del Dashboard;
- se elimina la tarjeta anterior de cierre de sesión porque ambas acciones pasan a la barra integrada;
- el acceso administrativo se llama **Administración**;
- el botón no enlaza directamente a una ruta técnica visible: usa una acción firmada de EventosApp y la redirección al backend ocurre del lado del servidor;
- el cierre de sesión usa igualmente una ruta firmada de EventosApp, evitando exponer la ruta nativa de autenticación en los enlaces del frontend;
- las acciones están protegidas con nonce y validación de capacidades.

La ocultación de la barra administrativa en el frontend continúa activa para todos los roles, incluido Administrador.

### Compatibilidad

La corrección se implementa como una capa pequeña posterior a `eventosapp-security.php`:

- `eventosapp-security.php` no se reescribe;
- autenticación, roles, hardening, auditoría, anti-spam y escáner permanecen intactos;
- el hook del dock flotante anterior se retira después de inicializar Seguridad;
- el filtro que agregaba las tarjetas de cuenta anteriores se retira antes de renderizar el Dashboard;
- los shortcodes y módulos existentes no cambian su función principal.

### Archivos de 1.5.0-rc.2

```text
includes/frontend/eventosapp-session-ui.php        NUEVO
includes/frontend/eventosapp-frontend-helpers.php  MODIFICADO
README.md                                           MODIFICADO
```

### Commits funcionales de la corrección

```text
e45300aff49ebff7c6c5a5075ea8a2977db20341  fix: integrate session controls and dashboard login
09629f0b6d43fd93fc280884191b629a62fbe29e  fix: load integrated session UI
29167ed4572f25369acfa76c1aeef84e7d5692d5  fix: keep dashboard login errors inline
```

## Validación funcional requerida para 1.5.0-rc.2

### Dashboard y login

- Abrir `/dashboard/` sin sesión y confirmar que aparece el formulario completo de inicio de sesión.
- Confirmar que no aparece el antiguo mensaje con enlace como única opción.
- Probar usuario/contraseña correctos e incorrectos desde `/dashboard/`.
- Confirmar que los errores permanecen en `/dashboard/`.
- Confirmar mostrar/ocultar contraseña.
- Confirmar reCAPTCHA correcto, vacío e inválido cuando está configurado.
- Confirmar nonce inválido/vencido.
- Confirmar límite de intentos y bloqueo temporal.
- Confirmar redirección posterior según rol.

### Panel de sesión integrado

- Confirmar que no existe ningún control flotante fijo en Dashboard ni módulos.
- Confirmar nombre visible y usuario en la barra integrada.
- Confirmar que en Dashboard la barra queda dentro del panel principal.
- Confirmar que en módulos la barra forma parte del flujo normal y no tapa contenido.
- Confirmar botón **Panel** en módulos.
- Confirmar botón **Cerrar sesión** para todos los usuarios autenticados.
- Confirmar botón **Administración** únicamente para Administradores.
- Probar responsive en 320 px, 375 px, 430 px, tablet y desktop.
- Confirmar que Kiosko/Autogestión y Sorteo Público no muestran datos de la sesión.

### White-label

- Revisar Dashboard y módulos y confirmar que la capa nueva no muestra el nombre de la plataforma subyacente.
- Pasar el cursor sobre **Administración** y **Cerrar sesión** y confirmar que los enlaces visibles pertenecen a EventosApp/Dashboard.
- Confirmar que **Administración** redirige correctamente al backend solo para Administradores.
- Confirmar que un usuario sin capacidad administrativa no puede forzar la acción administrativa mediante URL.
- Confirmar cierre de sesión y retorno a la página configurada de login.

---

## Candidato 1.5.0-rc.1 — Seguridad integrada, Login, 404 y sesión del frontend

### Objetivo

EventosApp integra las funciones que antes dependían del plugin independiente **Custom Login Basic** y agrega una capa propia de seguridad, auditoría y hardening. Después de validar esta versión, el plugin histórico puede desactivarse y eliminarse sin perder el login personalizado ni el control por roles.

La integración se diseñó para no alterar la lógica funcional de los módulos actuales ni aplicar límites globales que puedan bloquear operaciones reales durante un evento.

### Nueva sección `EventosApp > Seguridad`

Se agregó un panel central con cinco bloques:

1. **Acceso y roles**
   - Login integrado con shortcode `[custom_login_basic]`.
   - Migración automática de la opción histórica `clb_settings` cuando existe.
   - Migración de la auditoría `clb_login_audit_log`.
   - Google reCAPTCHA v2 “No soy un robot”.
   - Protección CSRF mediante nonce.
   - Límite de intentos configurable por combinación IP + usuario.
   - Matriz por rol para activar/desactivar login, permitir o negar `/wp-admin` y elegir una página de redirección.
   - El administrador real (`manage_options`) mantiene acceso al backend.

2. **Anti-spam y hardening**
   - Bloqueo de enumeración por `?author=` y `?author_name=`.
   - Ocultamiento de endpoints REST de usuarios para visitantes no autenticados.
   - Desactivación configurable de XML-RPC.
   - Desactivación de pingbacks/trackbacks y cabecera `X-Pingback`.
   - Honeypot ligero para formularios de comentarios.
   - Reducción de huellas técnicas en el `<head>`.
   - Headers seguros compatibles: `X-Content-Type-Options`, `Referrer-Policy` y `X-Permitted-Cross-Domain-Policies`.

3. **Anti-DDoS / control de abuso**
   - Límite de intentos de login configurable entre 3 y 20 intentos.
   - Tiempo de bloqueo configurable entre 1 y 120 minutos.
   - El bloqueo se aplica por IP + usuario para reducir el impacto de redes compartidas.
   - No se añadió un rate-limit global de peticiones porque en un evento cientos de asistentes pueden compartir la misma IP pública/NAT; un límite global podría bloquear check-in, QR y operaciones masivas legítimas.
   - Los ataques volumétricos deben seguir mitigándose antes de PHP mediante firewall/WAF/CDN/proveedor de infraestructura.

4. **Anti-malware**
   - Diagnóstico manual de archivos PHP del plugin EventosApp y del tema activo.
   - Búsqueda de firmas de alto riesgo: ejecución ofuscada, webshells conocidas, ejecución de entrada HTTP en funciones de sistema, payloads base64 sospechosos y patrones heredados peligrosos.
   - El escáner reporta ruta, severidad, regla, fecha de modificación y SHA-256.
   - No elimina, modifica ni pone archivos en cuarentena automáticamente.
   - El diagnóstico es complementario y no sustituye antivirus/EDR/WAF del servidor.

5. **Auditoría**
   - Conserva los últimos 250 intentos gestionados por el login integrado.
   - Registra fecha/hora, usuario, ID, IP, resultado y motivo.
   - Incluye limpieza manual protegida por nonce y capacidad administrativa.

### Configuración de páginas

La pantalla existente `EventosApp > Configuración` recibe una sección adicional **Acceso, Login y página 404** con:

- **Página de inicio de sesión:** debe contener `[custom_login_basic]`.
- **Página 404:** debe contener `[eventosapp_404]`.

Ambas definiciones también se incorporan al inventario de instalación automática existente. El instalador puede crear, detectar, mapear o reparar las páginas sin modificar el contenido ajeno.

Páginas propuestas por el instalador:

```text
Ingreso Staff      /ingreso-staff/   [custom_login_basic]
404 | EventosApp   /404/             [eventosapp_404]
```

### Login integrado

El login conserva:

- usuario o correo + contraseña;
- mostrar/ocultar contraseña;
- reCAPTCHA v2;
- nonce;
- límite de intentos;
- auditoría;
- validación de rol;
- redirección por rol o Dashboard;
- manejo de sesión ya iniciada.

### Protección de acceso técnico

- La ruta nativa de login queda fuera del flujo normal y deriva a la experiencia 404.
- Se conservan las acciones imprescindibles para no romper logout ni páginas protegidas.
- El backend se bloquea a usuarios sin permiso.
- AJAX, admin-post y cron permanecen disponibles para no romper acciones frontend existentes.

### Barra administrativa y 404

- La barra administrativa se oculta en todo el frontend para todos los usuarios.
- `[eventosapp_404]` ofrece una experiencia propia con imagen de EventosApp, animaciones, botones de recuperación y estado HTTP 404.
- Autogestión/Kiosko y Sorteo Público se mantienen fuera de cualquier control visual de sesión.

### Compatibilidad con Custom Login Basic

Mientras el plugin histórico continúe activo, EventosApp muestra una advertencia administrativa. El shortcode integrado se vuelve a registrar al final de `init` para que la implementación de EventosApp sea la que renderice el formulario.

Después de validar login, logout, roles, backend, 404 y reCAPTCHA, debe desactivarse **Custom Login Basic** para evitar que sus hooks antiguos sigan ejecutándose en paralelo.

### Archivos del candidato 1.5.0-rc.1

```text
includes/admin/eventosapp-security.php              NUEVO
includes/frontend/eventosapp-frontend-helpers.php   MODIFICADO
README.md                                            MODIFICADO
```

### Commits principales de 1.5.0-rc.1

```text
77673d8990932c9a38da811654407174d02a618e  feat: integrate EventosApp security and custom login
464368f3e05ad81bd0246a78949ab2db168a5d6c  feat: load integrated security before frontend modules
85cc68d7ef3ec9aa65b05adb280dcf0041f896be  docs: document security candidate 1.5.0-rc.1
1ed5f97cdfb4249f320baaba0a2367ef5b70ff93  fix: preserve safe login bootstrap and scanner accuracy
1cc7d46de022353b0aed41a03a1d82e4cfb51fd0  fix: keep admin session actions responsive on mobile
```

---

## Candidato 1.4.0-rc.1 — Transacciones y auditoría de Consumibles

La base previa documentada se centró en el ledger de Consumibles y su auditoría. El commit candidato identificado fue `8a7e1717351790ae2117120a8b4157e8895df46f`, posterior a `3975588693b03b1ed3643d3d6632897f791aeabb` (`1.3.0-rc.2`).

Archivos de ese candidato:

```text
includes/functions/eventosapp-consumables-transactions.php  NUEVO
includes/functions/eventosapp-consumables.php               MODIFICADO
```

### Control de Consumibles — Transacciones

- Pestaña **Transacciones** para Administradores y Organizadores con `consumables_manage`.
- Consulta agrupada por lectura/lote con estados activo, cancelación solicitada, anulación parcial y completa.
- Filtros por búsqueda, ítem, Staff, cédula, localidad, fecha, rango horario y estado.
- Resumen por ítem con cantidades brutas, anuladas, pendientes y netas.
- CSV completo del ledger con movimientos originales, solicitudes y reversos.
- Anulación administrativa mediante transacción SQL y bloqueo de filas.
- Los movimientos originales no se eliminan; el reverso queda como nueva entrada auditable.

### Consumo de Consumibles — Mis transacciones

- Pestaña **Mis transacciones** para Staff y Logístico con `consumables_staff`.
- Consulta limitada a transacciones propias del evento activo.
- Sumatoria neta de artículos entregados.
- Solicitud de cancelación para revisión administrativa.
- Una solicitud no restaura saldo por sí misma.

### Landing presencial del asistente

- Bloque **Movimientos de mi inventario** organizado por día.
- Cada movimiento conserva hora, artículos y estado.
- Las anulaciones permanecen visibles como trazabilidad.
- Los tickets virtuales continúan excluidos.

### Seguridad e integridad de Consumibles

- Endpoints AJAX con sesión, nonce, evento activo y permiso específico.
- Staff solo puede solicitar cancelaciones de transacciones propias.
- Administradores/Organizadores ejecutan reversos.
- Reversos con `START TRANSACTION`, `SELECT ... FOR UPDATE`, actualización condicionada y `ROLLBACK` ante inconsistencia.
- Identificadores determinísticos e índice único de `request_uuid` para idempotencia.

## Hotfix 1.3.0-rc.2 — Landing pública del ticket

### Incidencia corregida

La landing `/ticket/?ticket=...` podía quedar en blanco por iniciar un nuevo buffer dentro de un callback de buffer de salida.

### Solución

- `eventosapp-consumables-core.php` conserva la implementación completa.
- `eventosapp-consumables.php` actúa como cargador seguro.
- El HTML del inventario se calcula antes de iniciar el buffer.
- El callback solo inserta una cadena ya preparada.
- La intervención queda restringida a la landing presencial `/ticket/` y ruta heredada equivalente.
- Los tickets virtuales quedan excluidos.
- La landing `/virtual/` recupera su independencia.
- No se alteran WhatsApp, Meta, Wallet, PDF, ICS ni check-in virtual.

Archivos del hotfix:

```text
includes/functions/eventosapp-consumables.php
includes/functions/eventosapp-consumables-core.php
includes/frontend/eventosapp-virtual-landing-widget.php
README.md
```

## Alcance conservado desde 1.3.0

### Control de Consumibles

- Inventarios por evento con múltiples consumibles y cantidades.
- Segmentación para todos, por localidad o campo personalizado.
- Inventario único o reinicio por día.
- Balance por ticket y ledger transaccional.
- Selección simultánea de artículos y cantidades.
- Descuento atómico: si una línea no tiene saldo, no se descuenta ninguna.
- Idempotencia frente a lecturas duplicadas.
- Inventario visible en la landing presencial.
- Tickets virtuales explícitamente excluidos.

### Permisos por evento

- Administrador y Organizador: configuración + consumo.
- Staff y Logístico: solo consumo.
- Excepciones por usuario y evento.
- Configuraciones personalizadas antiguas heredan nuevas funciones cuando no contienen decisión explícita.
- Validación final del alcance del evento.
- Co-gestión, Staff operativo, Equipo de apoyo y Expositores conservan alcances independientes.

### Co-gestión y Staff operativo

- Selección múltiple de Staff y Logístico.
- Asignación a varios eventos.
- Vencimiento configurable o sin vencimiento.
- Sincronización de alcance entre evento y usuario.
- Eliminación individual o masiva.
- Limpieza automática de permisos vencidos.

### Kiosko Android y QR

- API de Kiosko Android `1.3.0`.
- Autenticación QR, escrita o combinada.
- Impresión automática opcional después de validar QR.
- La lectura QR resuelve el ticket sin alterar confirmaciones posteriores necesarias.

## Estado de promociones previas

### `1.3.0-rc.2`

La corrección de la landing pública fue integrada en `main` de producción mediante el merge `08aabcf1a8667cfe56a536b14edfd591202d02d3`.

### `1.3.0-rc.1`

La promoción anterior incluyó:

```text
includes/admin/eventosapp-access-staff-control-event.php
includes/admin/eventosapp-co-gestion.php
includes/admin/eventosapp-configuracion.php
includes/api/eventosapp-kiosk-api.php
includes/frontend/eventosapp-virtual-landing-widget.php
includes/functions/eventosapp-consumables.php
```

### `1.2.0`

Base promovida desde `048a91e1dc9c1bd9bf92a5a96870672e85d7de8e`, centrada en Kiosko Android, Staff QR, diagnóstico y permisos por evento.

## Procedimiento de promoción

1. Leer este README y el README/CHANGELOG del repositorio de producción.
2. Definir un commit exacto de origen.
3. Comparar la rama candidata contra su base documentada.
4. Verificar que no existan archivos fuera de alcance.
5. Validar sintaxis PHP de cada archivo modificado.
6. Probar funcionalmente la rama en un entorno controlado.
7. Crear una rama de promoción desde `main` de producción.
8. Copiar únicamente la lista cerrada de archivos aprobados.
9. Verificar hashes/blobs y comparar contra producción.
10. Actualizar README, CHANGELOG y versión en producción antes de fusionar.

## Historial resumido

### `1.5.0-rc.13` — 2026-08-10

Hotfix visual de Checklist de Evento, Edición de asistentes y las vistas internas de Consumibles: firma corporativa, superficies, formularios, lector QR, pestañas y transacciones compatibles con Dark Mode, protección del buscador de Edición y aislamiento final frente a Elementor/tema, sin reescribir los cinco archivos funcionales indicados ni alterar checklist, edición, inventario, ledger, reversos, cámara o landing pública.

### `1.5.0-rc.12` — 2026-08-10

Hotfix visual de Búsqueda/Check-In Manual y Check-In Facial: firma corporativa en encabezados, superficies, controles y estados compatibles con Dark Mode, corrección del botón de check-in deshabilitado que heredaba colores externos, protección del buscador y aislamiento final frente a Elementor/tema sin alterar AJAX, impresión, reconocimiento facial, cámara ni lógica operativa.

### `1.5.0-rc.11` — 2026-08-10

Hotfix visual de todos los lectores QR de `eventosapp-qr-checkin.php` y `eventosapp-qr-double-auth.php`: firma corporativa en encabezados, superficies/controles/estados compatibles con Dark Mode, selector de sesiones y modal de segundo factor adaptados, más aislamiento final frente a Elementor/tema sin modificar cámara, AJAX, permisos ni lógica de check-in.

### `1.5.0-rc.10` — 2026-08-10

Hotfix visual de Empresas con Check-In y Registro Manual: iconos y paleta corporativa, superficies, controles, tablas y tarjetas de entrega compatibles con Dark Mode, más aislamiento final frente a Elementor/tema sin alterar AJAX, permisos ni lógica de tickets.

### `1.5.0-rc.9` — 2026-08-10

Hotfix visual de Métricas de Encuestas: icono corporativo en el encabezado, paleta oficial, superficies y controles compatibles con Dark Mode, aislamiento frente a Elementor/tema y sincronización dinámica de colores de Chart.js sin alterar datos, AJAX ni exportación CSV.

### `1.5.0-rc.8` — 2026-08-10

Hotfix del drawer y aislamiento de estilos: el backdrop queda debajo del stacking context del menú móvil, se recupera la interacción de todas sus acciones y se refuerzan variables/tokens corporativos para impedir que Elementor o el tema reintroduzcan colores genéricos dentro de páginas EventosApp.

### `1.5.0-rc.7` — 2026-08-10

Hotfix visual del shell unificado: Dark Mode sin tarjetas blancas en hover, aliases secundarios compatibles, icono corporativo correcto en el encabezado del Dashboard, avatar ajustado al tema, buscador con espacio protegido para la lupa y header autenticado móvil mediante drawer lateral accesible.

### `1.5.0-rc.6` — 2026-08-09

Shell de aplicación unificado para páginas mapeadas: header corporativo común, Usuario/Dashboard/Administración/Logout centralizados, Light/Dark Mode persistente y template sin header/footer del tema, conservando Elementor, shortcodes y lógica operativa.

### `1.5.0-rc.5` — 2026-08-09

Identidad corporativa oficial: paleta `#171e37` / `#3683c5` / `#286291`, logos e iconos SVG optimizados y capa visual central para Dashboard, módulos, login, sesión y administración, sin rediseño ni cambios funcionales.

### `1.5.0-rc.4` — 2026-08-09

Hotfix visual del buscador del Dashboard: se elimina la decoración nativa de `type="search"` para evitar que el navegador duplique o superponga el icono propio; se conservan búsqueda, limpieza, conteos y estilos Elementor.

### `1.5.0-rc.3` — 2026-08-09

Corrección del punto de render real: Dashboard sin sesión muestra `[custom_login_basic]` también cuando se usa el widget Elementor; usuario activo, Dashboard, Administración y Cerrar sesión se integran dentro de los shells visuales de Dashboard/módulos sin controles flotantes.

### `1.5.0-rc.2` — 2026-08-09

Corrección de UX y white-label: formulario completo en `/dashboard/` sin sesión, errores conservados en Dashboard, eliminación del control flotante, barra de usuario integrada al panel, acceso **Administración** solo para administradores y rutas frontend limpias para administración/logout.

### `1.5.0-rc.1` — 2026-08-09

Seguridad integrada: reemplazo funcional de Custom Login Basic, login con visualización de contraseña, migración de configuración/auditoría, roles y backend, 404 propia, sesión visible y logout en Dashboard/módulos, hardening anti-spam, control de abuso y diagnóstico antimalware.

### `1.4.0-rc.1` — 2026-08-07

Consulta/exportación de transacciones de Consumibles, resumen por ítem, transacciones propias de Staff, solicitudes de cancelación, reversos auditables e historial de movimientos en landing presencial.

### `1.3.0-rc.2` — 2026-08-06

Hotfix de landing presencial: eliminación del buffer anidado, restricción de Consumibles a `/ticket/` y restauración de la landing virtual independiente.

### `1.3.0-rc.1` — 2026-08-06

Módulo de Consumibles, política de permisos por rol/usuario, Co-gestión y API de Kiosko Android.

### `1.2.0` — 2026-08-05

Base de Kiosko Android, Staff QR, diagnóstico y permisos por evento.