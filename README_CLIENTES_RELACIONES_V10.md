# Clientes: relaciones completas (v10)

Parche incremental para instalar después de `gei-web-clientes-roles-20260805-v9`.

## Correcciones

- Orden alfabético normalizado del listado de clientes.
- Inmuebles del propietario agrupados por domicilio, conservando las cuentas COBOL.
- Inmueble alquilado y propietario en cada contrato del inquilino.
- Inquilinos vinculados a los inmuebles del propietario.
- Liquidaciones de propietario recuperadas por `cliente_id`, CUIT o cuenta COBOL.

Los cambios son exclusivamente de consulta y presentación. No modifican datos ni
requieren migraciones o reprocesamiento de períodos.

## Instalación

```bash
cd ~/proyectos/gei-web
tar -xzf gei-web-clientes-relaciones-20260805-v10.tar.gz
gei-artisan optimize:clear
```

## Casos de control

- CUIT `20168801492`: debe mostrar su inmueble alquilado, propietario y vigencia
  del contrato. También debe aparecer en el detalle del propietario relacionado.
- CUIT `20232285894`: debe agrupar domicilios repetidos, mostrar las cuentas COBOL
  asociadas, los inquilinos relacionados y sus liquidaciones de propietario.

