# Scripts SQL — Laravel GeI

Esta carpeta contiene todas las modificaciones de base de datos requeridas
por el sistema Laravel GeI.

## Compatibilidad con Visual FoxPro

La base PostgreSQL es compartida con el sistema Visual FoxPro existente.

Reglas obligatorias:

- No modificar columnas heredadas.
- No eliminar columnas heredadas.
- No cambiar tipos de datos heredados.
- No renombrar tablas, columnas, índices o restricciones existentes.
- Toda ampliación debe ser compatible con Visual FoxPro.
- Cada cambio debe contar con un script SQL reproducible.
- Siempre que sea posible, cada script tendrá su correspondiente rollback.

## Convención

Los scripts se ejecutan según el prefijo numérico:

1. `001_...sql`
2. `002_...sql`
3. `003_...sql`

## Ejecución

```bash
psql -v ON_ERROR_STOP=1 \
    -U USUARIO_POSTGRESQL \
    -d BASE_DE_DATOS \
    -f sql/001_usuarios_autenticacion_web.sql