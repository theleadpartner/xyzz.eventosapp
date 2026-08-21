# EventosApp 1.5.0-rc.34 — vínculo automático Flow local → Meta Flow ID

Fecha: 2026-08-21

## Objetivo

Corregir exclusivamente el builder unificado de plantillas de WhatsApp Flow para impedir que una plantilla conserve el `Meta Flow ID` de un Flow anterior después de cambiar el campo `Flow local`.

## Diagnóstico

El builder unificado de `includes/admin/eventosapp-whatsapp-templates.php` muestra `Flow local` y `Meta Flow ID`, pero esa vista no reutilizaba la sincronización JavaScript que ya existía en el editor histórico de plantillas Flow. Por eso era posible escoger otro Flow local y mantener en pantalla —y posteriormente guardar— un Meta Flow ID anterior.

La fuente canónica ya existe en el módulo de Flows: cada CPT interno `eventosapp_wa_flow` conserva su ID de Meta en `_eventosapp_wa_flow_meta_id`, expuesto por `eventosapp_whatsapp_flows_get_all_for_select()` y `eventosapp_whatsapp_flows_get_flow_config()`.

## Corrección rc.34

`includes/admin/eventosapp-whatsapp-flows.php` incorpora una capa acotada de enlace para el builder unificado:

- publica `EVENTOSAPP_WHATSAPP_FLOW_TEMPLATE_BINDING_VERSION = 2026.08.21.1`;
- al abrir el builder Flow, obtiene el mapa `Flow local -> Meta Flow ID` desde el motor existente;
- al cambiar `Flow local`, reemplaza inmediatamente el valor visible de `Meta Flow ID`;
- el campo `Meta Flow ID` queda de solo lectura en este builder porque deja de ser una entrada independiente;
- al cargar una plantilla existente, también se corrige visualmente cualquier valor obsoleto usando el Flow local actualmente seleccionado;
- antes del save handler histórico, un callback con prioridad 1 vuelve a resolver el Meta Flow ID desde el Flow local y sobrescribe el valor recibido por POST.

La validación en servidor evita que JavaScript deshabilitado, autofill del navegador o un POST manipulado puedan guardar una combinación Flow/Meta ID inconsistente.

Si el Flow seleccionado todavía no tiene Meta Flow ID, el valor automático queda vacío. rc.34 no inventa IDs ni modifica el proceso existente de creación, sincronización o publicación de Flows.

## Alcance preservado

No se modifica:

- el builder o JSON de los Flows;
- creación, sincronización o publicación en Meta;
- payloads de plantillas ni proceso de aprobación;
- WABA o número emisor;
- campañas masivas de Flow;
- programación de Flow por evento;
- Cola y Tareas ni su gobernador;
- Tickets, Confirmación de Asistencia, Recordatorios, QR, Kiosko, Staff, Consumibles u operación online/offline.

No se crean, ejecutan ni modifican GitHub Actions.

## Validación requerida

1. Abrir una plantilla Flow que tenga un Meta Flow ID histórico y escoger un Flow local diferente.
2. Confirmar que `Meta Flow ID` cambia inmediatamente al ID del nuevo Flow.
3. Confirmar que `Meta Flow ID` no puede editarse manualmente en el builder unificado.
4. Guardar y volver a abrir la plantilla; verificar que Flow local y Meta Flow ID siguen correspondiendo.
5. Alterar manualmente el valor `meta_flow_id` del POST y confirmar que el servidor guarda el ID canónico del Flow local.
6. Escoger un Flow sin Meta Flow ID y confirmar que el campo queda vacío, sin copiar el ID anterior.
7. Confirmar que el editor histórico de Flow, campañas masivas y programación por evento mantienen su comportamiento.
8. Ejecutar `php -l` sobre `includes/admin/eventosapp-whatsapp-flows.php` y validar la sintaxis del JavaScript inline agregado.
