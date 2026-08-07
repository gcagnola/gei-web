# Liquidaciones de propietarios desde PostgreSQL

## Resultado

El flujo de propietarios queda contenido íntegramente en `gei-web`:

```text
archivos mensuales almacenados por Laravel
    -> parser copiado dentro de gei-web
    -> liquidaciones_propietarios
    -> liquidaciones_propietarios_items
    -> lectura desde PostgreSQL
    -> PDF
```

`gei-liquidaciones-python` se utilizó exclusivamente como referencia. No se
modificó y no es una dependencia de ejecución.

## Tablas nuevas

- `liquidaciones_propietarios`: cabecera, propietario, cuenta, comprobante,
  totales, control contra `pliqloc`, numeración y estado del PDF.
- `liquidaciones_propietarios_items`: renglones Debe/Haber, referencias y
  trazabilidad del movimiento.
- `liquidaciones_propietarios_procesos`: auditoría de cada ejecución por
  período.

La identidad funcional se conserva con `clave_origen` y una restricción por
período, sede, tipo, cuenta, comprobante y copropietario. La repetición del
mismo lote omite cabeceras sin cambios y conserva la numeración asignada.

## Preparación inicial

Aplicar las migraciones y completar las cuatro transformaciones que ya estaban
implementadas:

```bash
gei-artisan migrate
gei-artisan gei:migrar-clientes-cobol --confirmar
gei-artisan gei:migrar-inmuebles-cobol --confirmar
gei-artisan gei:migrar-contratos-cobol --confirmar
gei-artisan gei:migrar-cuentas-corrientes-cobol --confirmar
```

Estas operaciones leen `gei_exploracion.cobol_staging` y escriben el modelo
normalizado actual. Son idempotentes.

## Generación

Desde la aplicación:

```text
Propietarios -> Liquidaciones -> Generar Liquidación de Propietarios
```

La pantalla permite elegir un período ya cargado y ejecutar "Guardar datos y
generar PDF".

También se puede ejecutar por consola:

```bash
gei-artisan gei:procesar-liquidaciones-propietarios 202606 \
  --numero-inicial=25194 \
  --confirmar
```

`--numero-inicial` sólo se utiliza si la tabla está vacía. Después se toma el
máximo persistido más uno. También puede definirse:

```dotenv
GEI_LIQUIDACIONES_NUMERO_INICIAL=25194
```

## Ubicación de los PDF

Los PDF se guardan en el disco Laravel `liquidaciones`, por defecto:

```text
storage/app/private/liquidaciones/propietarios/AAAA/MM/
```

El nombre mantiene el formato existente:

```text
l0000-00025194.pdf
```

## Validaciones realizadas

Con el lote real de junio de 2026:

- período detectado: `202606`;
- liquidaciones estructuradas: `674`;
- controles `pliqloc` OK: `578`;
- totales/signos ajustados desde `pliqloc`: `96`;
- PDF generados desde representación equivalente a tablas: `674`;
- primer PDF: `l0000-00025194.pdf`;
- último PDF: `l0000-00025867.pdf`;
- errores Python: `0`.

También se renderizó visualmente un PDF A4 de prueba y se verificaron cabecera,
detalle, columnas Debe/Haber, totales, IVA, pago y firma.

## Reejecución e idempotencia

- Un registro idéntico conserva `numero_interno` y se informa como omitido.
- Un registro cuyo contenido cambió actualiza cabecera e ítems y regenera su PDF.
- Si primero se generaron liquidaciones y después se completaron maestros, una
  reejecución actualiza `cliente_id` y `cuenta_corriente_id` sin renumerar.
- Un bloqueo global impide procesar dos períodos en paralelo y protege la serie
  numérica.

## Rollback de estructura

La migración tiene `down()` y elimina únicamente las tres tablas nuevas:

```bash
gei-artisan migrate:rollback --step=1
```

No usar rollback si ya se generaron números que deban conservarse.
