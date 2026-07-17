/*
===============================================================================
Proyecto       : Laravel GeI
Archivo        : 002_web_password_reset_tokens.sql
Descripción    : Crea la tabla de tokens para recuperación de contraseña web.
Compatibilidad : PostgreSQL 9.4 o superior
===============================================================================
*/

BEGIN;

CREATE TABLE IF NOT EXISTS public.web_password_reset_tokens (
    email varchar(255) NOT NULL,
    token varchar(255) NOT NULL,
    created_at timestamp without time zone,
    CONSTRAINT web_password_reset_tokens_pkey
        PRIMARY KEY (email)
);

COMMENT ON TABLE public.web_password_reset_tokens IS
    'Tokens temporales para recuperación de contraseña del sistema Laravel GeI.';

COMMENT ON COLUMN public.web_password_reset_tokens.email IS
    'Correo asociado al usuario web.';

COMMENT ON COLUMN public.web_password_reset_tokens.token IS
    'Token seguro almacenado como hash.';

COMMENT ON COLUMN public.web_password_reset_tokens.created_at IS
    'Fecha y hora de creación del token.';

COMMIT;