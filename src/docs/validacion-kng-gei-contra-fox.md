# Validacion KNG/GeI contra resultado Fox

## Alcance

La importacion `4` se valida contra PostgreSQL historico generado por Visual FoxPro.
El validador no escribe tablas heredadas: solo registra auditoria en tablas `web_`.

Tablas heredadas controladas:

- `clientes`
- `movimientos_de_cuentas`
- `liquidaciones_de_clientes`
- `liquidaciones_de_clientes_items`
- `liquidaciones_impuestos_servicios`

## Reglas reconstruidas

### Cuentas corrientes

`CTACTEPRO.TXT` e `INQCTACTE.TXT` son fuente intermedia KNG/DBF consumida por GeI.
No existe persistencia historica individual en `movimientos_de_cuentas` para este lote: la tabla esta vacia en el resultado Fox.

El validador clasifica esos registros como fuente intermedia reconstruida, no como faltantes en PostgreSQL.

### Cabeceras

La planilla `pliqloc.sf.txt` / `pliqloc.st.txt` identifica cabeceras por:

- fecha;
- tipo A/B;
- numero impreso de 8 digitos;
- cuenta;
- importe.

El numero impreso corresponde a `liquidaciones_de_clientes.numero_de_comprobante`.
`liquidaciones_de_clientes.numero` es numero interno asignado por GeI/PostgreSQL.

El importe impreso se compara contra el valor funcional mas cercano entre:

- `total`;
- `subtotal`;
- `total_liquidado`.

### Items

Los archivos `liquida.sf.txt`, `liquida.st.txt`, `liquidb.sf.txt` y `liquidb.st.txt`
son listados paginados duplicados horizontalmente.

Las paginas se agrupan por:

- cuenta;
- numero de comprobante impreso.

Los items se leen por columnas fijas del listado:

- 0-36: referencia/persona;
- 37-77: detalle/inmueble/vencimiento;
- 78-95: Debe;
- 96-113: Haber.

Las referencias de movimientos se toman del bloque inferior del listado y se asignan en orden.
La comparacion contra PostgreSQL prioriza `numero_detalle`; el orden fisico se usa solo si no hay referencia.

Para importes con IVA, el listado puede imprimir base o total. PostgreSQL conserva base e IVA separados.
El validador compara contra el valor funcional mas cercano entre base neta y `total`.

### Dailoc

`dailoc.SF.txt` y `dailoc2.SF.txt` aportan impuestos y servicios.
La linea `TOTAL.........:` tiene tres importes:

1. total bruto del detalle;
2. comision/base;
3. importe llevado al item `Pago Imptos del mes s/detalle`.

La comparacion usa la tercera columna contra `liquidaciones_de_clientes_items.total`.
Cuando una cuenta tiene varias liquidaciones/copropietarios con el mismo importe, el resultado se clasifica como `AMBIGUO` porque `dailoc` no trae numero de comprobante.

## Resultado verificado

La validacion completa se ejecuto dos veces con resultados identicos.

```text
propietarios: fuente 4084, exactos 1653, diferencias 605, no encontrados 1711, ambiguos 115
inquilinos: fuente 16938, exactos 997, diferencias 12029, no encontrados 3290, ambiguos 622
cuentas propietarios: fuente 239686, exactos 239686
cuentas inquilinos: fuente 360876, exactos 360876
cabeceras: fuente 674, exactas 658, diferencias 12, no encontradas 4
items: fuente 4289, exactos 4185, diferencias 6, no encontrados 98
dailoc Santa Fe: fuente 254, exactos 231, diferencias 5, no encontrados 3, ambiguos 15
dailoc Santo Tome/complementario: fuente 53, exactos 49, no encontrados 2, ambiguos 2
```

Los conteos heredados antes y despues fueron identicos:

```text
clientes: 16046
movimientos_de_cuentas: 0
liquidaciones_de_clientes: 23887
liquidaciones_de_clientes_items: 147190
liquidaciones_impuestos_servicios: 0
```

## Comandos

```bash
docker exec -i --user "$(id -u):$(id -g)" -e HOME=/tmp -w /var/www/html gei-app \
  php artisan gei:validar-kng-gei-postgresql 4 --completo

docker exec -i --user "$(id -u):$(id -g)" -e HOME=/tmp -w /var/www/html gei-app \
  php artisan gei:validar-kng-gei-postgresql 4 --solo=liquidaciones

docker exec -i --user "$(id -u):$(id -g)" -e HOME=/tmp -w /var/www/html gei-app \
  php artisan gei:validar-kng-gei-postgresql 4 --solo=items

docker exec -i --user "$(id -u):$(id -g)" -e HOME=/tmp -w /var/www/html gei-app \
  php artisan gei:validar-kng-gei-postgresql 4 --solo=dailoc
```

## Estado

El circuito de liquidaciones ya no queda sin reconstruccion: cuentas, cabeceras, items y dailoc tienen reglas funcionales comprobadas contra PostgreSQL.

El estado global permanece `VALIDACION_PARCIAL` porque la conciliacion de maestros completos (`PROPIETAR.TXT` e `INQUILINO.TXT`) aun contiene registros historicos no usados, ambiguos o sin relacion directa unica en `clientes`.
