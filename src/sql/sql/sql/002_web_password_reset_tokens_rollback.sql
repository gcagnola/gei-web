/*
===============================================================================
Proyecto       : Laravel GeI
Archivo        : 002_web_password_reset_tokens_rollback.sql
Descripción    : Elimina la tabla de tokens de recuperación web.
===============================================================================
*/

BEGIN;

DROP TABLE IF EXISTS public.web_password_reset_tokens;

COMMIT;