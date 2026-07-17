DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'clientes'
          AND column_name = 'web_operativo'
    ) THEN
        ALTER TABLE public.clientes
            ADD COLUMN web_operativo boolean NOT NULL DEFAULT false;
    END IF;
END $$;

COMMENT ON COLUMN public.clientes.web_operativo IS
    'Marca operativa GeI Web calculada desde contratos vigentes y evidencia en archivos COBOL/liquidaciones, sin modificar datos heredados.';
