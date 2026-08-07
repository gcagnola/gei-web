# Menú Archivo y módulo Clientes

Este parche:

- conserva `Archivo → Importar`;
- elimina el módulo web obsoleto `Archivo → Actualizar DB`;
- conecta `Archivo → Clientes` con las tablas definitivas `clientes`,
  `clientes_roles` y `clientes_cuentas`;
- permite buscar por nombre, documento, CUIT, teléfono, correo o cuenta COBOL;
- muestra roles, cuentas, inmuebles, contratos y liquidaciones vinculadas;
- adapta el alta y la modificación al esquema definitivo.

No agrega migraciones y no reprocesa períodos ni PDF.
