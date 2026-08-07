# Corrección Clientes v9

Corrige la relación muchos-a-muchos entre `clientes` y `roles` para usar los
nombres reales de la tabla intermedia `clientes_roles`:

- `cliente_id`
- `rol_id`

## Instalación

Desde la raíz de `gei-web`:

```bash
tar -xzf gei-web-clientes-roles-20260805-v9.tar.gz
gei-artisan optimize:clear
```

No requiere migraciones ni modifica datos.

## Estado "Tablas pendientes"

La generación de PDF y la actualización de las tablas definitivas son etapas
independientes. Si julio de 2026 ya tiene PDF pero figura con tablas pendientes,
usar una vez `Archivo -> Importar -> Migrar y actualizar tablas` para `07/2026`.
El proceso es idempotente.
