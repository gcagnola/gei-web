# Migraciones de ajuste COBOL para liquidacion de propietarios

## Alcance

Se agregaron migraciones Laravel borrador complementarias para cubrir faltantes
detectados al comparar el DDL v2 contra el flujo COBOL:

```text
liqloc
  -> GIMB132 / GIMB133 / GIMB134
  -> GIMB23
```

y el aporte de impuestos/servicios de `GIMB98`.

No se ejecuto `php artisan migrate`.
No se ejecuto `migrate --pretend`.
No se ejecuto SQL contra PostgreSQL.
No se modificaron tablas heredadas.

## Migraciones agregadas

### Soporte COBOL de liquidacion

```text
2026_07_17_130000_create_web_modelo_nuevo_liquidacion_cobol_support_tables.php
```

Crea:

- `web_monedas`
- `web_cotizaciones`
- `web_corridas_liquidacion`
- `web_ordenes_no_liquidar`
- `web_correlativos`
- `web_liquidaciones_movimientos`

### Campos de soporte en tablas existentes del modelo nuevo

```text
2026_07_17_131000_add_cobol_liquidacion_support_fields_to_web_modelo_nuevo_tables.php
```

Agrega campos a:

- `web_liquidaciones_propietarios`
- `web_liquidaciones_propietarios_items`
- `web_movimientos_cuenta`

## Relacion con COBOL

| COBOL | Necesidad | Migracion |
| --- | --- | --- |
| `liqloc` | Registrar variante, parametros, sede, moneda, forma de pago o cuentas. | `web_corridas_liquidacion` |
| `GIMB132` | Corrida todos los propietarios, mensual/quincenal, sede y moneda. | `web_corridas_liquidacion` |
| `GIMB134` | Corrida por forma de pago. | `web_corridas_liquidacion.forma_pago_codigo` |
| `GIMB133` | Corrida por cuentas seleccionadas. | `web_corridas_liquidacion.cuentas_seleccionadas` |
| `NOLIQ.PROPI` | Excluir cuenta o movimiento. | `web_ordenes_no_liquidar` |
| `/tmp/LIQ.COTIZA.CON`, `.VALORU$S`, `INQPARIDAD` | Cotizacion/paridad aplicada. | `web_monedas`, `web_cotizaciones`, columnas de cotizacion |
| `GIMB23` | Marcar movimiento liquidado. | `web_liquidaciones_movimientos`, campos en `web_movimientos_cuenta` |
| `GIMB23 SORT` | Orden COBOL/impresion. | columnas `orden_cobol`, `orden_liquidacion`, `orden_impresion`, `secuencia_item` |
| `GIMB23 LLETRA` | Letra fiscal A/B. | `letra_fiscal` |
| `GIMB98 PROCORREL` | Correlativo de movimientos de impuestos. | `web_correlativos` |

## Orden futuro de aplicacion

Las migraciones son posteriores a las seis migraciones borrador del modelo
nuevo:

1. importacion y trazabilidad;
2. personas y roles;
3. inmuebles y contratos;
4. cuentas y movimientos;
5. liquidaciones;
6. pagos/facturas/auditoria;
7. soporte COBOL de liquidacion;
8. campos de soporte COBOL.

## Riesgos antes de migrate

- Validar `migrate --pretend` en una base descartable antes de ejecutar.
- Confirmar si `web_liquidaciones_movimientos` debe permitir mas de un registro
  historico por movimiento con estado distinto de `CONSUMIDO`.
- Confirmar layout de `NOLIQ.PROPI`.
- Confirmar catalogo inicial de monedas (`ARS`, `USD`, codigos COBOL `A`, `D`).
- Confirmar si `PROCORREL` y `INQCORREL` se migran como valores operativos o
  solo historicos.
- Confirmar si `MCCPRO.IMP` requiere tabla propia en una fase posterior.

## Comando que no se ejecuto

```bash
php artisan migrate
```

Tampoco se ejecuto:

```bash
php artisan migrate --pretend
```

## Estado

```text
MIGRACIONES_AJUSTE_COBOL_BORRADOR_GENERADAS
NO_EJECUTADAS
```

