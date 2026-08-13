# EventosApp 1.5.0-rc.29 — ajuste del gobernador de recursos de Cola y Tareas

Fecha: 2026-08-13  
Repositorio: `theleadpartner/xyzz.eventosapp`  
Perfil de referencia: KVM 4 vCPU / 16 GB RAM / 200 GB disco / 16 TB transferencia.

## Problema observado

Los procesos masivos ya se ejecutan correctamente en segundo plano mediante Cola y Tareas, pero el rendimiento real quedó muy por debajo de la capacidad del servidor. Se reportaron importaciones de miles de tickets de varias horas y el mismo patrón en WhatsApp Ticket masivo, WhatsApp Flow y Confirmación de Asistencia.

La revisión del gobernador central encontró que la protección no estaba fallando en su objetivo de seguridad; estaba siendo demasiado conservadora para este servidor y además combinaba varias penalizaciones que podían multiplicarse.

## Causas encontradas

### 1. El corte de memoria se medía contra `memory_limit` de PHP, no contra los 16 GB del VPS

La cola considera ocupado el servidor cuando el proceso PHP alcanza `memory_stop_ratio`. El valor anterior era `0.72`, es decir, 72 % del `memory_limit` de ese worker PHP.

Ese porcentaje no representa el consumo total de RAM del KVM. Un worker podía ceder aun cuando el VPS tuviera varios GB disponibles.

### 2. El umbral de carga era conservador para un KVM de 4 vCPU

El valor anterior era:

```text
load_stop_per_core = 1.30
```

En 4 vCPU esto equivale a un corte de aproximadamente:

```text
load 1 = 5.2
```

`loadavg` no equivale exclusivamente a porcentaje de CPU; también incorpora procesos esperando I/O. Para trabajos con filesystem, generación de assets y llamadas remotas, un load alto no siempre significa que los cuatro núcleos estén realmente saturados.

### 3. El batch adaptativo podía degradarse acumulativamente

El algoritmo histórico reducía el batch si cualquiera de estas condiciones se cumplía:

- memoria del proceso >= 70 %;
- load >= 1.1 por núcleo;
- el lote tardaba 20 s o más.

Además, el batch reducido se guardaba otra vez en la tarea. Por ejemplo, una tarea de WhatsApp podía evolucionar progresivamente así:

```text
12 -> 7 -> 4 -> 2 -> 1
```

Una llamada remota lenta podía activar la reducción aunque el servidor tuviera CPU y RAM disponibles. El problema no era únicamente el batch pequeño: cada lote volvía a pagar la pausa de reencolado.

### 4. Las pausas entre lotes amplificaban el problema

Configuración anterior:

```text
normal_delay_seconds = 4
busy_delay_seconds   = 15
```

Con un batch que hubiera caído a 1 o 2 elementos, miles de registros podían acumular horas únicamente en esperas entre ejecuciones.

### 5. Tres workers no aprovechaban completamente el pipeline de importación rc.28

La importación rápida crea hasta tres tareas de anexos y mantiene una tarea padre que consolida el progreso. Con `max_concurrent = 3`, la tarea padre podía ocupar uno de los tres cupos y dejar temporalmente solo dos shards de anexos trabajando.

### 6. Un dispatcher que encontraba el servidor ocupado podía quedar esperando el cron de respaldo

El dispatcher central retorna cuando `eventosapp_task_queue_resources_busy()` es verdadero. Si ese intento coincidía con el último kick, el siguiente intento podía depender del cron periódico. rc.29 agrega un reintento corto únicamente cuando siguen existiendo tareas vencidas/listas para ejecutar.

## Implementación rc.29

Archivo ajustado:

```text
includes/admin/eventosapp-herramientas-performance-controls.php
```

Versión interna:

```text
EVENTOSAPP_TASK_QUEUE_PERFORMANCE_PROFILE_VERSION = 2026.08.13.1
```

El perfil usa exclusivamente APIs públicas existentes de la cola: el filtro `eventosapp_task_queue_config`, `eventosapp_task_queue_register_adapter()` y el hook del dispatcher. No reemplaza funciones de envío ni elimina protecciones.

### Gobernador para 4 vCPU / 16 GB

Valores efectivos objetivo:

```text
max_concurrent          = min(4, vCPU detectadas)
dispatcher_limit        >= 16
max_execution_seconds   = 35, limitado a max_execution_time PHP - 5 s
memory_stop_ratio       = 0.86
load_stop_per_core      = 2.00
min_delay_seconds       = 1
normal_delay_seconds    = 1
busy_delay_seconds      = 5
max_batch_size          >= 100
```

En el KVM de 4 vCPU el corte de load pasa de aproximadamente `5.2` a `8.0`.

La protección sigue activa:

- no se abren más de cuatro workers;
- no se abren más workers que vCPU detectadas;
- un worker sigue cediendo antes de agotar su `memory_limit`;
- el worker conserva `should_yield` por tiempo/recursos;
- errores consecutivos, locks, heartbeat, pausa y cancelación siguen intactos;
- el perfil no desactiva el freno por load, solo evita que sea excesivamente sensible.

### Pisos de batch para impedir degradación a 1 item

Se re-registran únicamente los adaptadores afectados después de las integraciones históricas.

| Tarea | Batch objetivo | Piso | Máximo |
|---|---:|---:|---:|
| `ticket_import` | 60 | 30 | 80 |
| `ticket_import_assets` | 10 | 4 | 16 |
| `whatsapp_bulk` | 12 | 6 | 20 |
| `whatsapp_flow_bulk` | 10 | 5 | 16 |
| `attendance_bulk` | 12 | 4 | 24 |
| `attendance_scheduled` | 10 | 4 | 20 |

Estos valores siguen siendo secuenciales dentro de cada worker. Aumentar el batch no crea 12 o 60 requests simultáneos; reduce cuántas veces el proceso debe salir, esperar y volver a entrar a la cola.

`should_yield` conserva la autoridad de terminar el lote antes del máximo si se alcanza el presupuesto de tiempo o el servidor realmente cruza los umbrales de protección.

## Carga del perfil

`eventosapp.php` carga `includes/admin/eventosapp-herramientas.php` de forma global, incluso en las llamadas REST usadas por los workers. Ese bootstrap carga la capa de rendimiento y, dentro de ella, `eventosapp-herramientas-performance-controls.php`:

```text
includes/admin/eventosapp-herramientas.php
  -> eventosapp-herramientas-performance.php
     -> eventosapp-herramientas-performance-controls.php
```

Por eso el perfil se registra también en los workers REST sin añadir otro bootstrap ni modificar el orden histórico de carga. No se modifica `eventosapp-task-queue-core.php`; se usan sus filtros, hooks y registro público de adaptadores.

## Qué no cambia

rc.29 no modifica:

- funciones que envían un ticket individual por WhatsApp;
- funciones que envían WhatsApp Flows;
- motor de Confirmación de Asistencia;
- generación de QR, PDF, ICS, Google Wallet o Apple Wallet;
- deduplicación/fingerprint del importador;
- reglas y segmentación de campañas;
- lógica de pausa, reanudación o cancelación;
- API móvil online/offline;
- Consumibles, Staff QR, Localidad, Sesiones o Doble Auth.

## Validación obligatoria

1. Ejecutar una importación de 100 tickets en Cola y Tareas y verificar que no haya regresiones.
2. Ejecutar 1.000 tickets y registrar items/s de fase de datos y anexos.
3. Ejecutar la prueba real de 4.000 tickets y medir tiempo total.
4. Confirmar que la importación crea los tres shards de anexos y que pueden trabajar junto con la tarea padre.
5. Probar WhatsApp Ticket masivo con al menos 100 destinatarios.
6. Probar WhatsApp Flow masivo con al menos 100 destinatarios.
7. Probar Confirmación de Asistencia por WhatsApp y, si aplica, canal mixto.
8. Verificar que los batches no se degraden progresivamente hasta 1 item.
9. Revisar `resource_metrics.last_batch` y logs de cesión del worker.
10. Durante una prueba grande, vigilar CPU, RAM, PHP-FPM y MySQL desde el VPS.
11. Forzar carga artificial o ejecutar tareas concurrentes y confirmar que el worker todavía cede cuando el load realmente supera el nuevo umbral.
12. Probar pausa, reanudación y cancelación durante una campaña activa.
13. Confirmar idempotencia al reintentar una tarea o repetir un CSV.
14. Ejecutar PHP lint completo del repositorio antes de promover rc.29.

## Criterio de aceptación

El objetivo no es mantener CPU baja. El objetivo es mantener al servidor trabajando de forma sostenida dentro de márgenes seguros.

En el KVM de 4 vCPU / 16 GB se considera normal que una operación masiva utilice de forma visible CPU y workers PHP. La cola debe ceder cuando exista presión real, no simplemente porque una llamada remota tarde, un proceso use una fracción moderada de su `memory_limit` o exista un pico de I/O.

No se fija un tiempo garantizado para 4.000 registros porque depende de los assets habilitados y de la latencia de Meta/correo/Wallet. La aceptación de rc.29 debe basarse en una mejora clara de items/s y en la eliminación del patrón de batches degradados + pausas largas.
