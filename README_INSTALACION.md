# Corrección del período en PDF

Convierte el período interno `AAAAMM` al formato con el mes en español que utiliza la liquidación.

Ejemplos:

- `202607` pasa a `Julio/2026`.
- `202601` pasa a `Enero/2026`.

La corrección se aplica tanto al encabezado `Periodo liquidado` como al período mostrado en el detalle junto al número y la fecha del movimiento.

## Instalación

```bash
cd ~/proyectos/gei-web
tar -xzf gei-web-liquidaciones-periodo-mes-20260804-v7.tar.gz
gei-artisan optimize:clear
```

No requiere migraciones ni volver a cargar los archivos TXT. Para actualizar los PDF ya creados, reprocesar el período desde `Propietarios → Liquidaciones` conservando su primer número.
