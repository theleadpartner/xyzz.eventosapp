# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

## Estado del ciclo actual

- **Versión candidata:** `1.5.0-rc.2`
- **Fecha de corte:** 2026-08-09
- **Base de la rama:** `a424a2cdab5af2f9a9d95c23a788a2911b43ab64` (`main`)
- **Rama de trabajo:** `fix/dashboard-login-session-panel-whitelabel-20260809`
- **Destino de promoción:** `theleadpartner/EventosApp`
- **Estado:** corrección de UX del login y sesión implementada sobre la seguridad integrada de `1.5.0-rc.1`; pendiente validación funcional antes de promoción.

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
