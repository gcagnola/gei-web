/*
===============================================================================
Proyecto       : Laravel GeI
Archivo        : 001_usuarios_autenticacion_web.sql
Descripción    : Agrega campos para autenticación web a public.usuarios.
Compatibilidad : PostgreSQL 9.4 o superior
===============================================================================
*/

BEGIN;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'usuarios'
          AND column_name = 'web_clave_hash'
    ) THEN
        ALTER TABLE public.usuarios
            ADD COLUMN web_clave_hash varchar(255);
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'usuarios'
          AND column_name = 'web_email'
    ) THEN
        ALTER TABLE public.usuarios
            ADD COLUMN web_email varchar(255);
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'usuarios'
          AND column_name = 'web_ultimo_acceso'
    ) THEN
        ALTER TABLE public.usuarios
            ADD COLUMN web_ultimo_acceso timestamp without time zone;
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'usuarios'
          AND column_name = 'web_intentos_fallidos'
    ) THEN
        ALTER TABLE public.usuarios
            ADD COLUMN web_intentos_fallidos integer NOT NULL DEFAULT 0;
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'usuarios'
          AND column_name = 'web_bloqueado_hasta'
    ) THEN
        ALTER TABLE public.usuarios
            ADD COLUMN web_bloqueado_hasta timestamp without time zone;
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'usuarios'
          AND column_name = 'web_recordar_token'
    ) THEN
        ALTER TABLE public.usuarios
            ADD COLUMN web_recordar_token varchar(100);
    END IF;
END
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'usuarios'
          AND column_name = 'web_clave_actualizada'
    ) THEN
        ALTER TABLE public.usuarios
            ADD COLUMN web_clave_actualizada timestamp without time zone;
    END IF;
END
$$;

COMMENT ON COLUMN public.usuarios.web_clave_hash IS
    'Hash moderno utilizado exclusivamente por Laravel. No reemplaza la clave MD5 usada por Visual FoxPro.';

COMMENT ON COLUMN public.usuarios.web_email IS
    'Correo electrónico utilizado exclusivamente por el sistema web.';

COMMENT ON COLUMN public.usuarios.web_ultimo_acceso IS
    'Fecha y hora del último acceso correcto desde Laravel.';

COMMENT ON COLUMN public.usuarios.web_intentos_fallidos IS
    'Cantidad de intentos fallidos consecutivos desde Laravel.';

COMMENT ON COLUMN public.usuarios.web_bloqueado_hasta IS
    'Fecha y hora hasta la cual el acceso web permanece bloqueado.';

COMMENT ON COLUMN public.usuarios.web_recordar_token IS
    'Token utilizado por Laravel para la opción Recordarme.';

COMMENT ON COLUMN public.usuarios.web_clave_actualizada IS
    'Fecha y hora de generación o actualización de web_clave_hash.';

COMMIT;