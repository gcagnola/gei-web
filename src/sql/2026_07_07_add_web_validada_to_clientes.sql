DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
            AND table_name = 'clientes'
            AND column_name = 'web_validada'
    ) THEN
        ALTER TABLE public.clientes
            ADD COLUMN web_validada boolean NOT NULL DEFAULT false;
    END IF;
END $$;

COMMENT ON COLUMN public.clientes.web_validada IS
    'Marca de validacion GeI Web para clientes reconciliados contra archivos COBOL/KNG en modo lectura.';
