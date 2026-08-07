# Importación mensual desde la web

La operación mensual se realiza íntegramente desde Laravel.

## Flujo del usuario

1. Abrir `Archivo > Importar`.
2. Subir los cuatro TXT COBOL y los archivos de liquidaciones del período.
3. Pulsar `Migrar y actualizar tablas`.
4. Esperar a que la pantalla informe `Crudos migrados` y `Tablas actualizadas`.
5. Abrir `Propietarios > Liquidaciones` y generar los PDF del período.

El botón de migración ejecuta, en orden:

1. carga idempotente de los TXT en `gei_exploracion`;
2. actualización de clientes;
3. actualización de inmuebles;
4. actualización de contratos;
5. actualización de cuentas corrientes y movimientos.

Los comandos Artisan de migración de datos quedan disponibles sólo como
herramientas técnicas. No forman parte del procedimiento mensual del usuario.

## Instalación incremental

Después de copiar este parche sobre `~/proyectos/gei-web`, ejecutar una sola vez:

```bash
cd ~/proyectos/gei-web
gei-artisan migrate
gei-artisan optimize:clear
```

No es necesario volver a aplicar las migraciones anteriores ni ejecutar
manualmente `gei:migrar-clientes-cobol`, `gei:migrar-inmuebles-cobol`,
`gei:migrar-contratos-cobol` o `gei:migrar-cuentas-corrientes-cobol`.

## Reintentos

Cada transformación conserva su estado y el hash de los cuatro archivos COBOL.
Si una etapa falla, la pantalla muestra `Error en tablas`. El usuario puede
pulsar `Reintentar`; las rutinas existentes son idempotentes y no deben duplicar
clientes, inmuebles, contratos, cuentas ni movimientos.
