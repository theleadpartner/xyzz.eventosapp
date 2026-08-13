# Registro de promoción a producción — 1.5.0-rc.28

Fecha de cierre: 2026-08-12

## Resultado

El estado final de pruebas `theleadpartner/xyzz.eventosapp@9d1f5e329cbeb6b8680c762104f1568db57738e2` fue promovido a `theleadpartner/EventosApp` mediante el PR de producción `#8`.

Commit squash integrado en producción:

```text
608352f4a567c15e34ae5ab9f370b15ef1a22d4c
```

Versión documentada en producción:

```text
1.5.0-rc.28
```

## Corte auditado

- Último SHA de pruebas previamente representado en producción: `8a7e1717351790ae2117120a8b4157e8895df46f` (`1.4.0-rc.1`).
- SHA final promovido: `9d1f5e329cbeb6b8680c762104f1568db57738e2`.
- Diferencia acumulada: 208 commits.
- Rutas acumuladas detectadas en pruebas: 77.
- Rutas sincronizadas directamente byte a byte: 76.
- Ruta restante: `README.md`, documentada de forma específica en producción para conservar su historial propio.
- Archivos adicionales de control creados/actualizados en producción: `CHANGELOG.md`, `VERSION` y `docs/production-sync-1.5.0-rc.28.md`.

## Validaciones completadas

- Fuente fijada a SHA inmutable.
- Manifiesto cerrado de 76 rutas.
- Copia y comprobación byte a byte de cada ruta.
- PHP 8.2 lint sin errores para todos los PHP promovidos.
- Control de alcance con enumeración completa de archivos nuevos/modificados.
- GitHub Actions de transferencia completado correctamente: run `31659567475`.
- PR de producción con 81 archivos finales revisados.
- Workflow permanente `PHP Lint` del PR productivo finalizado con éxito.
- Merge mediante squash del PR de producción `#8`.

## Estado después de la promoción

Al cerrar este registro, `main` de pruebas continuaba exactamente en `9d1f5e329cbeb6b8680c762104f1568db57738e2`. Por tanto, no se detectaron cambios funcionales adicionales pendientes de producción después del corte promovido.

Para la siguiente promoción funcional, la comparación debe comenzar desde `9d1f5e329cbeb6b8680c762104f1568db57738e2`. No se deben volver a incluir cambios anteriores a este SHA, salvo un rollback o corrección explícitamente documentada.

Los commits posteriores que integren este mismo registro y el bloque de estado en `README.md` son **exclusivamente documentación del cierre de promoción**. No representan una función pendiente de producción ni cambian la base funcional promovida `9d1f5e329cbeb6b8680c762104f1568db57738e2`.

El inventario exhaustivo de la promoción quedó almacenado en producción en `docs/production-sync-1.5.0-rc.28.md`.
