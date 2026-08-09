# EventosApp — Entorno de pruebas

Repositorio de desarrollo y validación previa de la plataforma EventosApp. Las funciones nuevas se construyen y prueban aquí antes de promoverse de forma controlada al repositorio de producción `theleadpartner/EventosApp`.

## Estado del ciclo actual

- **Versión candidata:** `1.5.0-rc.1`
- **Fecha de corte:** 2026-08-09
- **Base de la rama:** `cf67e7a09180277e2097768976371462e8dd1e07` (`main`)
- **Rama de trabajo:** `feature/security-login-404-session-ui-20260809`
- **Destino de promoción:** `theleadpartner/EventosApp`
- **Estado:** integración de seguridad, login, 404 y controles de sesión implementada en rama aislada; pendiente validación funcional en WordPress antes de promoción.

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
   - Reducción de huellas de WordPress en el `<head>`.
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

El nuevo login conserva y mejora el flujo histórico:

- Usuario o correo + contraseña.
- Botón para **mostrar/ocultar la contraseña** sin alterar el valor escrito.
- reCAPTCHA v2 cuando Site Key y Secret Key están configuradas simultáneamente.
- Nonce de WordPress.
- Límite de intentos.
- Auditoría de acceso.
- Validación de rol activo.
- Redirección a la página definida para el rol; si no hay una específica, usa el Dashboard de EventosApp.
- Si un usuario ya está autenticado y su rol está habilitado, la página de login lo envía al destino correspondiente.
- Si está autenticado pero ningún rol está habilitado, se muestra un mensaje de acceso no permitido y la opción de cerrar sesión.

### Protección de `/wp-login.php` y `/wp-admin`

- `/wp-login.php` queda oculto para el flujo normal y deriva a la experiencia 404.
- Se conservan las acciones necesarias de `logout` y `postpass` para no romper el cierre de sesión ni páginas protegidas por contraseña.
- `/wp-admin` se bloquea a usuarios sin permiso de backend.
- `admin-ajax.php`, `admin-post.php` y procesos cron permanecen disponibles para no romper acciones frontend existentes de EventosApp.

### Barra de WordPress, usuario activo y cierre de sesión

La barra administrativa de WordPress se oculta en todo el frontend **para todos los usuarios, incluido Administrador**.

En páginas de gestión configuradas de EventosApp se incorpora un control de sesión compacto que muestra:

- nombre del usuario activo;
- nombre de usuario;
- acceso al Dashboard cuando se está dentro de otro módulo;
- botón **WordPress** solamente para administradores;
- botón **Cerrar sesión** para todos los usuarios autenticados.

Se excluyen expresamente del control flotante las pantallas de Autogestión/Kiosko y la pantalla pública del Sorteo para no mostrar datos de la sesión operativa a asistentes o público.

El Dashboard también recibe, mediante su registro central de módulos:

- tarjeta **Cerrar sesión**, con el usuario activo en la descripción;
- tarjeta **Backend de WordPress**, visible únicamente para administradores y solo cuando el Dashboard está mostrando módulos para un evento activo.

El control de sesión flotante mantiene ambos accesos disponibles incluso cuando el Dashboard todavía está en la pantalla de selección de evento.

### Nueva página 404 de EventosApp

El shortcode `[eventosapp_404]` crea una experiencia responsive y dinámica con lenguaje visual de tickets/QR:

- usa el logo personalizado del sitio o el icono del sitio como imagen de marca;
- animación de ticket, escáner y elementos flotantes con soporte `prefers-reduced-motion`;
- mensaje contextual de EventosApp;
- botón para regresar al Dashboard si existe sesión;
- botón para ir al login cuando corresponde;
- botón de página principal;
- botón para volver a la página anterior;
- la página configurada responde con estado HTTP 404;
- las rutas 404 normales pueden redirigirse a esta experiencia.

### Compatibilidad con Custom Login Basic

Mientras el plugin histórico continúe activo, EventosApp muestra una advertencia administrativa. El shortcode integrado se vuelve a registrar al final de `init` para que la implementación de EventosApp sea la que renderice el formulario.

Después de validar login, logout, roles, `/wp-admin`, 404 y reCAPTCHA, debe desactivarse **Custom Login Basic** para evitar que sus hooks antiguos sigan ejecutándose en paralelo.

### Archivos del candidato 1.5.0-rc.1

```text
includes/admin/eventosapp-security.php              NUEVO
includes/frontend/eventosapp-frontend-helpers.php   MODIFICADO
README.md                                            MODIFICADO
```

La carga del nuevo módulo se realiza desde `eventosapp-frontend-helpers.php`, que ya forma parte del bootstrap existente y se carga después de Configuración. De esta forma no se altera el orden histórico del archivo principal `eventosapp.php`.

### Commits de implementación

```text
77673d8990932c9a38da811654407174d02a618e  feat: integrate EventosApp security and custom login
464368f3e05ad81bd0246a78949ab2db168a5d6c  feat: load integrated security before frontend modules
```

## Validación funcional requerida para 1.5.0-rc.1

### Instalación y migración

- Abrir `EventosApp > Configuración` y confirmar los nuevos selectores de Login y 404.
- Ejecutar la instalación/reparación de páginas y confirmar que no duplica páginas válidas.
- Verificar que `Ingreso Staff` contiene `[custom_login_basic]`.
- Verificar que `404 | EventosApp` contiene `[eventosapp_404]`.
- Con Custom Login Basic todavía activo, confirmar que EventosApp importa configuración y auditoría existentes.
- Después de validar el nuevo flujo, desactivar Custom Login Basic y repetir todas las pruebas críticas.

### Login y roles

- Probar Administrador, Organizador, Staff, Logístico, Coordinador y Expositor.
- Confirmar usuario/contraseña correctos e incorrectos.
- Confirmar que el ojo muestra y vuelve a ocultar la contraseña.
- Confirmar nonce vencido/incorrecto.
- Confirmar reCAPTCHA correcto, vacío e inválido.
- Confirmar bloqueo después del número configurado de intentos.
- Confirmar que un rol deshabilitado no conserva la sesión.
- Confirmar redirección al Dashboard o página específica configurada por rol.
- Abrir la página de login estando autenticado y confirmar redirección automática.

### Backend y cierre de sesión

- Administrador: confirmar acceso directo a `/wp-admin` y botón de WordPress.
- Usuario sin backend: confirmar que `/wp-admin` lleva a la página 404 configurada.
- Confirmar que `admin-ajax.php` y `admin-post.php` siguen funcionando en módulos frontend.
- Confirmar cierre de sesión desde Dashboard y desde un módulo.
- Confirmar retorno a la página de login después del logout.
- Confirmar que la barra de WordPress no aparece en frontend, incluso como Administrador.

### UI de sesión

- Confirmar nombre y usuario activo en Dashboard y módulos configurados.
- Confirmar botón Panel al navegar por módulos.
- Confirmar que el botón WordPress solo aparece para Administrador.
- Confirmar responsive del control en móvil.
- Confirmar que Autogestión/Kiosko y Sorteo Público no muestran el control de sesión.

### 404

- Abrir una URL inexistente y confirmar la experiencia EventosApp.
- Confirmar estado HTTP 404 de la página final.
- Probar “Volver al dashboard”, “Página principal” y “Página anterior”.
- Abrir `/wp-login.php` y confirmar que no muestra el login nativo.
- Abrir `/wp-admin` como usuario sin backend y confirmar redirección segura.

### Seguridad adicional

- Confirmar bloqueo de `?author=1`.
- Confirmar que `/wp-json/wp/v2/users` no enumera usuarios para visitantes.
- Confirmar XML-RPC bloqueado cuando la opción está activa.
- Confirmar que los headers seguros no interfieren con QR, reconocimiento facial, Elementor o integraciones.
- Ejecutar el diagnóstico antimalware y revisar cualquier hallazgo antes de producción.
- Confirmar que no se aplica rate-limit global a check-in, QR o APIs operativas.

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
6. Probar funcionalmente la rama en WordPress.
7. Crear una rama de promoción desde `main` de producción.
8. Copiar únicamente la lista cerrada de archivos aprobados.
9. Verificar hashes/blobs y comparar contra producción.
10. Actualizar README, CHANGELOG y versión en producción antes de fusionar.

## Historial resumido

### `1.5.0-rc.1` — 2026-08-09

Seguridad integrada: reemplazo funcional de Custom Login Basic, login con visualización de contraseña, migración de configuración/auditoría, roles y backend, 404 propia, sesión visible y logout en Dashboard/módulos, botón administrativo, hardening anti-spam, control de abuso y diagnóstico antimalware.

### `1.4.0-rc.1` — 2026-08-07

Consulta/exportación de transacciones de Consumibles, resumen por ítem, transacciones propias de Staff, solicitudes de cancelación, reversos auditables e historial de movimientos en landing presencial.

### `1.3.0-rc.2` — 2026-08-06

Hotfix de landing presencial: eliminación del buffer anidado, restricción de Consumibles a `/ticket/` y restauración de la landing virtual independiente.

### `1.3.0-rc.1` — 2026-08-06

Módulo de Consumibles, política de permisos por rol/usuario, Co-gestión y API de Kiosko Android.

### `1.2.0` — 2026-08-05

Base de Kiosko Android, Staff QR, diagnóstico y permisos por evento.
