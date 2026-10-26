<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'portal_gei_exploracion';

    public function up(): void
    {
        $db = DB::connection('portal_gei_exploracion');

        if ($db->getDatabaseName() !== 'gei_exploracion') {
            throw new RuntimeException('Migracion COBOL: base incorrecta.');
        }

        $historico = $db->selectOne(
            "SELECT to_regclass('cobol_staging.migraciones_periodos') AS tabla"
        );

        if ($historico === null || $historico->tabla === null) {
            throw new RuntimeException(
                'Falta cobol_staging.migraciones_periodos.'
            );
        }

        $db->statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS cobol_staging.actualizacion_ultimo_periodo (
    sede varchar(2) NOT NULL,
    archivo varchar(12) NOT NULL,
    clave_cobol varchar(27) NOT NULL,
    tipo_cambio varchar(12) NOT NULL,
    registro_raw bytea,
    sha256_registro varchar(64),
    periodo_base text NOT NULL,
    fecha_cierre date NOT NULL,
    actualizado_en timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (sede, archivo, clave_cobol),
    CONSTRAINT gei_overlay_archivo_ck CHECK (
        archivo IN ('PROPIETAR','INQUILINO','CTACTEPRO','INQCTACTE')
    ),
    CONSTRAINT gei_overlay_tipo_ck CHECK (
        tipo_cambio IN ('ALTA','MODIFICACION','BAJA')
    ),
    CONSTRAINT gei_overlay_sede_ck CHECK (
        sede IN ('SF','ST')
    ),
    CONSTRAINT gei_overlay_clave_ck CHECK (
        (archivo IN ('PROPIETAR','INQUILINO')
            AND clave_cobol ~ '^[0-9]{11}$')
        OR
        (archivo IN ('CTACTEPRO','INQCTACTE')
            AND clave_cobol ~ '^[0-9]{27}$')
    ),
    CONSTRAINT gei_overlay_raw_ck CHECK (
        (tipo_cambio='BAJA'
            AND registro_raw IS NULL
            AND sha256_registro IS NULL)
        OR
        (tipo_cambio <> 'BAJA'
            AND registro_raw IS NOT NULL
            AND sha256_registro ~ '^[0-9a-f]{64}$'
            AND octet_length(registro_raw) = CASE archivo
                WHEN 'PROPIETAR' THEN 200
                WHEN 'INQUILINO' THEN 560
                WHEN 'CTACTEPRO' THEN 112
                WHEN 'INQCTACTE' THEN 134
            END)
    )
)
SQL);

        $db->statement(<<<'SQL'
CREATE INDEX IF NOT EXISTS actualizacion_ultimo_periodo_fecha_idx
ON cobol_staging.actualizacion_ultimo_periodo
(sede, fecha_cierre DESC, archivo)
SQL);

        $db->statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS cobol_staging.actualizacion_ultimo_periodo_control (
    sede varchar(2) PRIMARY KEY CHECK (sede IN ('SF','ST')),
    periodo_base char(6) NOT NULL,
    fecha_cierre date NOT NULL,
    estado varchar(10) NOT NULL
        CHECK (estado IN ('PRUEBA','PUBLICADO')),
    cantidad_diferencias bigint NOT NULL,
    resumen jsonb NOT NULL,
    sha256_diferencias varchar(64) NOT NULL,
    actualizado_en timestamptz NOT NULL DEFAULT clock_timestamp()
)
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Rollback bloqueado: estas tablas pueden contener datos COBOL.'
        );
    }
};
