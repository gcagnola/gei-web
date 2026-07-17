# Importador piloto COBOL web_*

## Objetivo

Se implemento un importador piloto Laravel para cargar una muestra limitada de los cuatro archivos COBOL principales en el modelo nuevo `web_*`:

- `PROPIETAR.TXT`
- `INQUILINO.TXT`
- `CTACTEPRO.TXT`
- `INQCTACTE.TXT`

El piloto no es el importador definitivo. Sirve para validar lectura real, trazabilidad, idempotencia y restricciones de seguridad sobre la base temporal `db_gei_web_migraciones_test`.

Decision: **APTO_PARA_LOTE_COMPLETO_TEMPORAL**.

## Seguridad

El comando aborta si:

- la base destino es `db_gei`;
- la base destino no es `db_gei_web_migraciones_test`.

No ejecuta `DELETE`, `TRUNCATE` ni `DROP`. Solo escribe en tablas `web_*` y usa `updateOrInsert` sobre claves funcionales del piloto.

No se guardan credenciales en codigo. La base se selecciona por `DB_DATABASE`.

## Comando

```bash
php artisan gei:web-importar-cobol-piloto \
  --base-dir=storage/app/private/liquidaciones/cobol \
  --limite-propietarios=5 \
  --limite-inquilinos=5 \
  --limite-movimientos-propietario=20 \
  --limite-movimientos-inquilino=20 \
  --cuenta-propietario=12020240300 \
  --cuenta-inquilino=11032433700
```

Modo sin escritura:

```bash
php artisan gei:web-importar-cobol-piloto \
  --base-dir=storage/app/private/liquidaciones/cobol \
  --limite-propietarios=5 \
  --limite-inquilinos=5 \
  --limite-movimientos-propietario=20 \
  --limite-movimientos-inquilino=20 \
  --dry-run
```

En Docker:

```bash
docker exec -i --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  -e DB_DATABASE=db_gei_web_migraciones_test \
  -w /var/www/html gei-app \
  php artisan gei:web-importar-cobol-piloto \
    --base-dir=storage/app/private/liquidaciones/cobol \
    --limite-propietarios=5 \
    --limite-inquilinos=5 \
    --limite-movimientos-propietario=20 \
    --limite-movimientos-inquilino=20 \
    --cuenta-propietario=12020240300 \
    --cuenta-inquilino=11032433700
```

## Parametros

- `--base-dir`: directorio donde estan los cuatro TXT COBOL.
- `--limite-propietarios`: cantidad maxima de propietarios candidatos, salvo cuenta explicita.
- `--limite-inquilinos`: cantidad maxima de inquilinos candidatos, salvo cuenta explicita.
- `--limite-movimientos-propietario`: cantidad maxima de movimientos desde `CTACTEPRO.TXT`.
- `--limite-movimientos-inquilino`: cantidad maxima de movimientos desde `INQCTACTE.TXT`.
- `--cuenta-propietario`: restringe la prueba a una cuenta de propietario.
- `--cuenta-inquilino`: restringe la prueba a una cuenta de inquilino.
- `--dry-run`: lee y clasifica candidatos sin insertar.

## Archivos leidos

Directorio usado:

```text
storage/app/private/liquidaciones/cobol
```

Archivos:

```text
PROPIETAR.TXT
INQUILINO.TXT
CTACTEPRO.TXT
INQCTACTE.TXT
```

## Prueba ejecutada

Base temporal:

```text
db_gei_web_migraciones_test
```

Primero se confirmo:

```text
DB_DATABASE=db_gei_web_migraciones_test
```

Tambien se valido que el comando estuviera registrado:

```bash
php artisan list gei
```

## Dry-run

Comando ejecutado con limites sin cuentas explicitas:

```bash
php artisan gei:web-importar-cobol-piloto \
  --base-dir=storage/app/private/liquidaciones/cobol \
  --limite-propietarios=5 \
  --limite-inquilinos=5 \
  --limite-movimientos-propietario=20 \
  --limite-movimientos-inquilino=20 \
  --dry-run
```

Resultado:

```json
{
  "propietarios": 10,
  "inquilinos": 5,
  "movimientos_propietario": 20,
  "movimientos_inquilino": 0
}
```

La cantidad de propietarios subio de 5 a 10 porque el piloto agrega propietarios referenciados por los inquilinos seleccionados para mantener relaciones consistentes.

Para validar movimientos de ambos dominios se ejecuto la carga real con cuentas conocidas:

```text
cuenta_propietario: 12020240300
cuenta_inquilino:  11032433700
```

## Conteos primera ejecucion

```json
{
  "web_lotes_importacion": 1,
  "web_archivos_importados": 4,
  "web_registros_origen": 42,
  "web_personas": 2,
  "web_propietarios": 1,
  "web_inquilinos": 1,
  "web_inmuebles": 1,
  "web_contratos": 1,
  "web_contrato_inquilinos": 1,
  "web_contrato_propietarios": 1,
  "web_contrato_inmuebles": 1,
  "web_inmuebles_propietarios": 1,
  "web_cuentas_corrientes": 2,
  "web_conceptos_movimiento": 18,
  "web_movimientos_cuenta": 40
}
```

Delta primera ejecucion:

```json
{
  "web_lotes_importacion": 1,
  "web_archivos_importados": 4,
  "web_registros_origen": 42,
  "web_personas": 2,
  "web_propietarios": 1,
  "web_inquilinos": 1,
  "web_inmuebles": 1,
  "web_contratos": 1,
  "web_contrato_inquilinos": 1,
  "web_contrato_propietarios": 1,
  "web_contrato_inmuebles": 1,
  "web_inmuebles_propietarios": 1,
  "web_cuentas_corrientes": 2,
  "web_conceptos_movimiento": 18,
  "web_movimientos_cuenta": 40
}
```

## Conteos segunda ejecucion

La segunda ejecucion se hizo con los mismos parametros.

Conteos despues:

```json
{
  "web_lotes_importacion": 1,
  "web_archivos_importados": 4,
  "web_registros_origen": 42,
  "web_personas": 2,
  "web_propietarios": 1,
  "web_inquilinos": 1,
  "web_inmuebles": 1,
  "web_contratos": 1,
  "web_contrato_inquilinos": 1,
  "web_contrato_propietarios": 1,
  "web_contrato_inmuebles": 1,
  "web_inmuebles_propietarios": 1,
  "web_cuentas_corrientes": 2,
  "web_conceptos_movimiento": 18,
  "web_movimientos_cuenta": 40
}
```

Delta segunda ejecucion:

```json
{
  "web_lotes_importacion": 0,
  "web_archivos_importados": 0,
  "web_registros_origen": 0,
  "web_personas": 0,
  "web_propietarios": 0,
  "web_inquilinos": 0,
  "web_inmuebles": 0,
  "web_contratos": 0,
  "web_contrato_inquilinos": 0,
  "web_contrato_propietarios": 0,
  "web_contrato_inmuebles": 0,
  "web_inmuebles_propietarios": 0,
  "web_cuentas_corrientes": 0,
  "web_conceptos_movimiento": 0,
  "web_movimientos_cuenta": 0
}
```

Resultado de idempotencia: **sin duplicados**.

## Integridad basica

```json
{
  "movimientos_sin_cuenta": 0,
  "movimientos_propietario": 20,
  "movimientos_inquilino": 20,
  "registros_con_archivo": 42,
  "contratos_con_relaciones": 1
}
```

## Limpieza final

Se ejecuto:

```bash
php artisan migrate:rollback --step=8 --force
```

Siempre con:

```text
DB_DATABASE=db_gei_web_migraciones_test
```

Verificacion final:

```text
web_tables=0
```

## Diferencias con el seeder minimo

- El seeder minimo usa cuentas fijas y carga 3 movimientos por dominio.
- El importador piloto acepta limites y cuentas por opcion.
- El piloto puede seleccionar los primeros registros del archivo o restringir por cuenta.
- El piloto agrega propietarios referenciados por inquilinos para sostener relaciones.
- El piloto devuelve JSON con candidatos, conteos antes/despues y delta.

## Limitaciones

- Sigue siendo experimental.
- No reemplaza al importador definitivo.
- No procesa liquidaciones ni genera PDFs.
- No resuelve todavia reglas completas de actualizacion historica.
- No tiene UI.
- No ejecuta carga historica completa.
- La seleccion sin cuentas explicitas puede no encontrar movimientos de inquilino en limites bajos.

## Estado final

- `db_gei` tocada: no.
- Tablas heredadas tocadas: no.
- Generador PDF modificado: no.
- Base temporal limpia al final: si.
- Carga historica completa ejecutada: no.

Decision: **APTO_PARA_LOTE_COMPLETO_TEMPORAL**.
