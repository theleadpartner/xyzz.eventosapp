# EventosApp 1.5.0-rc.31 — Configuración WhatsApp por evento

Fecha: 2026-08-19  
Rama: `agent/whatsapp-event-metaboxes`  
Base: `main` en `5ba65a86926848fea97225fdfa564b4d79e36c9c`

## Objetivo

Reorganizar la configuración WhatsApp del CPT de eventos para que cada evento tenga una experiencia única y verificable, sin reemplazar los motores existentes ni migrar metadatos históricos.

## Metaboxes nuevos

### WhatsApp Flows — Configuración y Programación

Centraliza:

- número emisor;
- plantilla Flow aprobable/aprobada;
- Flow local asociado;
- cabezote de imagen;
- fecha y hora de envío;
- zona horaria efectiva del evento;
- referencia directa a la tarea creada en Cola y Tareas.

La programación usa el nuevo tipo de tarea `whatsapp_flow_scheduled`. La audiencia de tickets se resuelve al comenzar la tarea, no al guardar el evento, para incluir inscripciones creadas después de programar el Flow.

### WhatsApp — Confirmación de Asistencia

Reutiliza íntegramente el motor existente de confirmación y reúne en un solo metabox:

- activación/programación;
- canales;
- plantilla WhatsApp con botones Sí/No;
- filtros existentes;
- cabezote de confirmación seleccionado desde la librería multimedia;
- diagnóstico y trazabilidad existentes.

La programación continúa sincronizándose con el adaptador `attendance_scheduled` de la cola central.

### WhatsApp — Tickets y Recordatorios

Centraliza:

- número emisor del evento;
- plantillas de ticket por modalidad;
- cabezote de ticket/QR;
- cabezote de ticket virtual;
- cabezote de la landing vinculada al ticket;
- reglas de envío históricas;
- recordatorios programados por WhatsApp y sus reglas.

Los recordatorios continúan usando `whatsapp_reminder_scheduled` en Cola y Tareas.

## Metaboxes retirados de la UI

Se eliminan únicamente sus registros visuales, no sus funciones ni metadatos:

- `Diseño WhatsApp y Landing` (`eventosapp_event_whatsapp_visuals`);
- `WhatsApp Tickets - Reglas de Envío` (`eventosapp_whatsapp_rules`);
- `Recordatorios de Ticket por WhatsApp` como caja separada;
- `Confirmación de Asistencia — Programación` como caja separada.

Sus renderizadores, validadores y save handlers se reutilizan dentro de la nueva experiencia para evitar regresiones.

## Compatibilidad de datos

Se conservan, entre otras, las claves históricas:

```text
_eventosapp_whatsapp_sender_phone_number_id
_eventosapp_whatsapp_satisfaction_flow_template_id
_eventosapp_whatsapp_satisfaction_flow_post_id
_eventosapp_whatsapp_satisfaction_flow_sender_phone_number_id
_eventosapp_whatsapp_satisfaction_flow_header_img
_eventosapp_whatsapp_qr_header_img
_eventosapp_whatsapp_virtual_message_img
_eventosapp_whatsapp_landing_header_img
_eventosapp_whatsapp_rules
_eventosapp_ticket_whatsapp_enabled
_eventosapp_attendance_confirmation_schedule
_eventosapp_attendance_confirmation_whatsapp_image
_eventosapp_ticket_reminders_enabled
_eventosapp_ticket_reminders
_eventosapp_ticket_reminder_rules
```

La nueva programación de Flow usa únicamente una clave adicional:

```text
_eventosapp_whatsapp_flow_schedule
```

## Imágenes

Todos los campos de imagen nuevos abren `wp.media` con `library.type = image`. La URL se conserva en los metadatos existentes para mantener compatibilidad con los motores de envío.

## Zona horaria

La fecha/hora introducida por el administrador se interpreta como hora local del evento. La zona se obtiene desde la configuración canónica de EventosApp/Cola y Tareas y solo se convierte a UTC para `scheduled_at`/`next_run_at`.

El payload de la tarea conserva:

```text
planned_timestamp
event_timezone
scheduled_local
scheduled_utc
```

Si la zona horaria del evento cambia mientras existe una tarea Flow pendiente, la tarea anterior se cancela y se recrea con el nuevo UTC para impedir ejecuciones desplazadas.

## Cola y Tareas

No se crea un scheduler paralelo.

- Flow programado: `whatsapp_flow_scheduled`.
- Confirmación programada: `attendance_scheduled` existente.
- Recordatorio WhatsApp: `whatsapp_reminder_scheduled` existente.

El Flow programado reutiliza `eventosapp_task_queue_process_flow_bulk()` y, por tanto, conserva validación por ticket, reglas del evento, deduplicación, envío real y métricas de la cola central.

## Alcance cerrado

No se modifican los motores de:

- WhatsApp Cloud API;
- creación/edición/publicación de Flows;
- plantillas de WhatsApp;
- confirmación inmediata o masiva;
- envío de tickets existente;
- QR/PDF/ICS/Wallet;
- Kiosko/Staff/Localidad/Sesiones/Doble Auth/Consumibles;
- API móvil online/offline;
- gobernador global de recursos de Cola y Tareas.

## Validación requerida antes de promover

1. Abrir un evento existente y confirmar que las selecciones históricas aparecen en los tres metaboxes nuevos.
2. Guardar el evento sin cambios y confirmar que no se pierden metadatos.
3. Seleccionar/cambiar plantillas de ticket presencial, virtual y Flow.
4. Seleccionar todos los cabezotes desde la Biblioteca multimedia y validar las vistas previas.
5. Programar un Flow con una hora futura y confirmar tarea `whatsapp_flow_scheduled` en Cola y Tareas.
6. Agregar un ticket después de crear la programación y confirmar que entra en la audiencia al ejecutarse.
7. Cambiar la zona horaria del evento con una tarea Flow pendiente y confirmar cancelación/recreación con UTC correcto.
8. Programar Confirmación de Asistencia y confirmar tarea `attendance_scheduled`.
9. Programar un recordatorio WhatsApp y confirmar tarea `whatsapp_reminder_scheduled`.
10. Probar reglas allow/deny de Tickets.
11. Ejecutar envíos de prueba de Ticket, Flow, Confirmación y Recordatorio.
12. Confirmar que no reaparecen los metaboxes antiguos duplicados.
13. Ejecutar PHP lint completo y regresión funcional antes de promover a producción.
