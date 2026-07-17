BEGIN;

ALTER TABLE public.usuarios
    DROP COLUMN IF EXISTS web_clave_actualizada;

ALTER TABLE public.usuarios
    DROP COLUMN IF EXISTS web_recordar_token;

ALTER TABLE public.usuarios
    DROP COLUMN IF EXISTS web_bloqueado_hasta;

ALTER TABLE public.usuarios
    DROP COLUMN IF EXISTS web_intentos_fallidos;

ALTER TABLE public.usuarios
    DROP COLUMN IF EXISTS web_ultimo_acceso;

ALTER TABLE public.usuarios
    DROP COLUMN IF EXISTS web_email;

ALTER TABLE public.usuarios
    DROP COLUMN IF EXISTS web_clave_hash;

COMMIT;