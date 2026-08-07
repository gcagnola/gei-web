# Corrección de nombres y pie de liquidaciones de propietarios

Esta actualización corrige el adaptador PostgreSQL → PDF de `gei-web`:

- los valores SQL `NULL` ya no se imprimen como `None`;
- `PAGAR A` usa el propietario cuando no existe copropietario;
- la forma de pago omite campos bancarios vacíos;
- el archivo se genera como `Nombre Propietario L0000-00000000.pdf`.

## Instalación

Desde la raíz de `~/proyectos/gei-web`:

```bash
tar -xzf gei-web-correccion-pdf-nombres-20260804-v2.tar.gz
gei-artisan optimize:clear
```

No requiere migraciones ni volver a cargar los TXT. Desde la pantalla
`Propietarios → Liquidaciones`, procesar nuevamente el mismo período regenera
los PDF conservando los números internos existentes.
