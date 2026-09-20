<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class GeiCoreProcesarPeriodoService
{
    private const ARCHIVO_PROGRESO = 'progreso_migracion.json';
    private const CORE = 'gei_core';
    private const ROL_PROPIETARIO = 'PROPIETARIO';
    private const ROL_INQUILINO = 'INQUILINO';

    public function procesar(string $periodo): array
    {
        $this->validarPeriodo($periodo);

        $this->guardarProgreso($periodo, 'GEI_CORE_PREPARAR', 'Preparando estructuras de GeI-Core.', 74);

        $db = $this->conexionExploracion();
        $this->inicializarEstructura($db);

        $schema = $this->schemaOrigen();
        $archivoPropietar = $this->archivoDelPeriodo($db, $periodo, 'propietar');
        $archivoInquilino = $this->archivoDelPeriodo($db, $periodo, 'inquilino');
        $fechaLiquidacion = $this->fechaUltimaLiquidacion($db, $schema, $archivoPropietar);

        $db->statement(
            "insert into gei_core.periodos (
                periodo, estado, fecha_ultima_liquidacion,
                archivo_propietar_id, archivo_inquilino_id,
                iniciado_at, actualizado_at
             ) values (?, 'PROCESANDO', ?, ?, ?, now(), now())
             on conflict (periodo) do update set
                estado = 'PROCESANDO',
                fecha_ultima_liquidacion = excluded.fecha_ultima_liquidacion,
                archivo_propietar_id = excluded.archivo_propietar_id,
                archivo_inquilino_id = excluded.archivo_inquilino_id,
                iniciado_at = now(),
                finalizado_at = null,
                actualizado_at = now()",
            [$periodo, $fechaLiquidacion, $archivoPropietar, $archivoInquilino]
        );

        try {
            $resultado = $db->transaction(function () use (
                $db,
                $schema,
                $periodo,
                $archivoPropietar,
                $archivoInquilino,
                $fechaLiquidacion
            ): array {
                $this->guardarProgreso($periodo, 'GEI_CORE_LIMPIAR', 'Limpiando la foto anterior del período.', 75);
                $this->limpiarPeriodo($db, $periodo);

                $this->guardarProgreso($periodo, 'GEI_CORE_PERSONAS_FUENTE', 'Preparando propietarios e inquilinos desde PostgreSQL.', 76);
                $this->crearTemporalPersonas(
                    $db,
                    $schema,
                    $periodo,
                    $archivoPropietar,
                    $archivoInquilino,
                    $fechaLiquidacion
                );

                $totalPersonasFuente = (int) ($db->selectOne('select count(*) as total from tmp_gei_personas_fuente')->total ?? 0);
                $this->guardarProgreso(
                    $periodo,
                    'GEI_CORE_PERSONAS',
                    'Procesando personas y cuentas COBOL.',
                    83,
                    0,
                    $totalPersonasFuente
                );
                $personas = $this->procesarPersonas($db, $periodo);
                $this->guardarProgreso(
                    $periodo,
                    'GEI_CORE_PERSONAS',
                    'Personas procesadas.',
                    86,
                    $totalPersonasFuente,
                    $totalPersonasFuente
                );

                $totalContratosFuente = (int) ($db->selectOne(
                    "select count(*) as total from {$schema}.inquilino where archivo_id = ?",
                    [$archivoInquilino]
                )->total ?? 0);
                $this->guardarProgreso(
                    $periodo,
                    'GEI_CORE_CONTRATOS',
                    'Procesando inmuebles y contratos.',
                    88,
                    0,
                    $totalContratosFuente
                );
                $modelo = $this->procesarContratosEInmuebles(
                    $db,
                    $schema,
                    $periodo,
                    $archivoInquilino
                );

                $this->guardarProgreso(
                    $periodo,
                    'GEI_CORE_CONTRATOS',
                    'Inmuebles y contratos procesados.',
                    91,
                    $totalContratosFuente,
                    $totalContratosFuente
                );

                $this->guardarProgreso(
                    $periodo,
                    'GEI_CORE_CUENTAS',
                    'Preparando cuentas corrientes y movimientos.',
                    78,
                    0,
                    null
                );
                $cuentasCorrientes = $this->procesarCuentasCorrientes(
                    $db,
                    $schema,
                    $periodo
                );

                $this->guardarProgreso($periodo, 'GEI_CORE_CONFLICTOS', 'Generando controles y conflictos básicos.', 82);
                $this->generarConflictosBasicos($db, $periodo);

                $conflictos = (int) ($db->selectOne(
                    "select count(*) as total
                       from gei_core.conflictos
                      where periodo = ?
                        and estado = 'PENDIENTE'",
                    [$periodo]
                )->total ?? 0);

                $fuentes = $this->inventariarFuentesPeriodo($db, $schema, $periodo);
                $ctacte = $this->detectarFuentesCuentaCorriente($db, $schema, $fuentes);

                $estado = $conflictos > 0 ? 'CON_CONFLICTOS' : 'BASE_VALIDADA';

                $db->update(
                    "update gei_core.periodos
                        set estado = ?,
                            finalizado_at = now(),
                            actualizado_at = now()
                      where periodo = ?",
                    [$estado, $periodo]
                );

                $this->guardarProgreso($periodo, 'GEI_CORE_COMPLETO', 'GeI-Core actualizado.', 83);

                return [
                    'periodo' => $periodo,
                    'estado' => $estado,
                    'fecha_ultima_liquidacion' => $fechaLiquidacion,
                    'archivo_propietar' => $archivoPropietar,
                    'archivo_inquilino' => $archivoInquilino,
                    ...$personas,
                    ...$modelo,
                    ...$cuentasCorrientes,
                    'conflictos_pendientes' => $conflictos,
                    'fuentes_periodo' => $fuentes,
                    'ctacte_detectada' => $ctacte,
                ];
            });

            return $resultado;
        } catch (\Throwable $e) {
            $this->guardarProgreso($periodo, 'GEI_CORE_ERROR', 'Error en GeI-Core: '.$e->getMessage(), 83, null, null, 'ERROR');

            $db->update(
                "update gei_core.periodos
                    set estado = 'ERROR',
                        error = ?,
                        finalizado_at = now(),
                        actualizado_at = now()
                  where periodo = ?",
                [mb_substr($e->getMessage(), 0, 4000), $periodo]
            );

            throw $e;
        }
    }

    private function limpiarPeriodo(Connection $db, string $periodo): void
    {
        $db->delete('delete from gei_core.conflictos where periodo = ?', [$periodo]);
        $db->delete('delete from gei_core.personas_origenes where periodo = ?', [$periodo]);
        $db->delete('delete from gei_core.personas_periodos where periodo = ?', [$periodo]);
        $db->delete('delete from gei_core.contratos_origenes where periodo = ?', [$periodo]);
        $db->delete('delete from gei_core.contratos_periodos where periodo = ?', [$periodo]);
        $db->delete('delete from gei_core.inmuebles_origenes where periodo = ?', [$periodo]);
        $db->delete('delete from gei_core.inmuebles_periodos where periodo = ?', [$periodo]);
        // Los movimientos son canónicos entre snapshots. No se borran al reprocesar:
        // el UPSERT por clave COBOL actualiza su última presencia.
    }

    private function crearTemporalPersonas(
        Connection $db,
        string $schema,
        string $periodo,
        int $archivoPropietar,
        int $archivoInquilino,
        string $fechaLiquidacion
    ): void {
        $db->statement('drop table if exists tmp_gei_personas_fuente');

        $db->statement(<<<'SQL'
            create temporary table tmp_gei_personas_fuente (
                rol text not null,
                cuenta_cobol text not null,
                nombre text,
                domicilio text,
                cp text,
                localidad text,
                provincia text,
                tipo_documento text,
                nro_documento text,
                tipo_iva text,
                nro_iva text,
                telefono_1 text,
                telefono_2 text,
                activo boolean not null,
                archivo_id bigint not null,
                registro_origen_id bigint not null,
                numero_linea bigint,
                sha256_registro text,
                fingerprint text not null
            ) on commit drop
        SQL);

        $db->statement(<<<SQL
            insert into tmp_gei_personas_fuente (
                rol, cuenta_cobol, nombre, domicilio, cp, localidad, provincia,
                tipo_documento, nro_documento, tipo_iva, nro_iva,
                telefono_1, telefono_2, activo,
                archivo_id, registro_origen_id, numero_linea, sha256_registro,
                fingerprint
            )
            select
                'PROPIETARIO',
                btrim(p.nro_cta_prop::text),
                nullif(btrim(p.nombre_prop), ''),
                nullif(btrim(p.domicilio_prop), ''),
                nullif(btrim(p.encot_prop::text), ''),
                nullif(btrim(p.localidad_prop), ''),
                nullif(btrim(p.provincia_prop), ''),
                null,
                null,
                nullif(btrim(p.tipo_iva::text), ''),
                case
                    when nullif(btrim(p.nro_iva::text), '') is null then null
                    when btrim(p.nro_iva::text) = '000000000000' then null
                    else btrim(p.nro_iva::text)
                end,
                nullif(btrim(p.telefono_1), ''),
                nullif(btrim(p.telefono_2), ''),
                btrim(p.fecha_ultima_liquidacion::text) = ?,
                p.archivo_id,
                p.id,
                p.numero_linea,
                p.sha256_registro,
                md5(concat_ws('|',
                    'P',
                    {$this->sqlNormalizar('p.nombre_prop')},
                    {$this->sqlNormalizar("case when btrim(p.nro_iva::text) = '000000000000' then '' else p.nro_iva::text end")},
                    {$this->sqlNormalizar('p.domicilio_prop')},
                    {$this->sqlNormalizar('p.localidad_prop')},
                    {$this->sqlNormalizar('p.provincia_prop')}
                ))
            from {$schema}.propietar p
            where p.archivo_id = ?
              and nullif(btrim(p.nro_cta_prop::text), '') is not null
        SQL, [$fechaLiquidacion, $archivoPropietar]);

        // Un inquilino se considera operativo para la foto del período cuando
        // no tiene marca de baja y su cuenta de propietario pertenece a la
        // liquidación vigente del período.
        $db->statement(<<<SQL
            insert into tmp_gei_personas_fuente (
                rol, cuenta_cobol, nombre, domicilio, cp, localidad, provincia,
                tipo_documento, nro_documento, tipo_iva, nro_iva,
                telefono_1, telefono_2, activo,
                archivo_id, registro_origen_id, numero_linea, sha256_registro,
                fingerprint
            )
            select
                'INQUILINO',
                btrim(i.cta_inquilino::text),
                nullif(btrim(i.nombre_inquilino), ''),
                nullif(btrim(i.domicilio_legal), ''),
                nullif(btrim(i.encot_legal::text), ''),
                nullif(btrim(i.localidad_legal), ''),
                nullif(btrim(i.provincia_legal), ''),
                nullif(btrim(i.tipo_documento::text), ''),
                case
                    when nullif(btrim(i.nro_documento::text), '') is null then null
                    when regexp_replace(btrim(i.nro_documento::text), '[^0-9]', '', 'g') ~ '^0+$' then null
                    else btrim(i.nro_documento::text)
                end,
                nullif(btrim(i.tipo_iva::text), ''),
                case
                    when nullif(btrim(i.nro_iva::text), '') is null then null
                    when btrim(i.nro_iva::text) = '000000000000' then null
                    else btrim(i.nro_iva::text)
                end,
                nullif(btrim(i.telefono_particular::text), ''),
                nullif(btrim(i.telefono_laboral::text), ''),
                (
                    nullif(btrim(i.marca_baja::text), '') is null
                    and exists (
                        select 1
                        from {$schema}.propietar p
                        where p.archivo_id = ?
                          and btrim(p.fecha_ultima_liquidacion::text) = ?
                          and btrim(p.nro_cta_prop::text) = btrim(i.cta_propietario::text)
                    )
                ),
                i.archivo_id,
                i.id,
                i.numero_linea,
                i.sha256_registro,
                md5(concat_ws('|',
                    'I',
                    {$this->sqlNormalizar('i.nombre_inquilino')},
                    {$this->sqlNormalizar("case when regexp_replace(btrim(i.nro_documento::text), '[^0-9]', '', 'g') ~ '^0+$' then '' else i.nro_documento::text end")},
                    {$this->sqlNormalizar("case when btrim(i.nro_iva::text) = '000000000000' then '' else i.nro_iva::text end")},
                    {$this->sqlNormalizar('i.domicilio_legal')},
                    {$this->sqlNormalizar('i.localidad_legal')},
                    {$this->sqlNormalizar('i.provincia_legal')}
                ))
            from {$schema}.inquilino i
            where i.archivo_id = ?
              and nullif(btrim(i.cta_inquilino::text), '') is not null
        SQL, [$archivoPropietar, $fechaLiquidacion, $archivoInquilino]);

        $db->statement('create index on tmp_gei_personas_fuente (rol, cuenta_cobol)');
        $db->statement('create index on tmp_gei_personas_fuente (fingerprint)');
    }

    private function procesarPersonas(Connection $db, string $periodo): array
    {
        /*
         * 1) Personas nuevas:
         *    - una cuenta ya conocida conserva siempre su persona;
         *    - una cuenta nueva puede reutilizar una persona existente sólo si
         *      coincide exactamente el fingerprint completo.
         */
        $db->statement(<<<'SQL'
            insert into gei_core.personas (
                clave_inicial,
                fingerprint_actual,
                nombre,
                domicilio,
                cp,
                localidad,
                provincia,
                tipo_documento,
                nro_documento,
                tipo_iva,
                nro_iva,
                telefono_1,
                telefono_2,
                created_at,
                updated_at
            )
            select distinct on (f.fingerprint)
                f.rol || ':' || f.cuenta_cobol,
                f.fingerprint,
                f.nombre,
                f.domicilio,
                f.cp,
                f.localidad,
                f.provincia,
                f.tipo_documento,
                f.nro_documento,
                f.tipo_iva,
                f.nro_iva,
                f.telefono_1,
                f.telefono_2,
                now(),
                now()
            from tmp_gei_personas_fuente f
            where not exists (
                select 1
                from gei_core.personas_cuentas_cobol c
                where c.rol = f.rol
                  and c.cuenta_cobol = f.cuenta_cobol
            )
              and not exists (
                select 1
                from gei_core.personas p
                where p.fingerprint_actual = f.fingerprint
                  and p.fusionada_en_id is null
            )
            order by f.fingerprint, f.activo desc, f.registro_origen_id desc
        SQL);

        // Mapea cuentas nuevas a una persona con el mismo fingerprint.
        $db->statement(<<<SQL
            insert into gei_core.personas_cuentas_cobol (
                persona_id, rol, cuenta_cobol,
                primer_periodo, ultimo_periodo, activa,
                created_at, updated_at
            )
            select distinct on (f.rol, f.cuenta_cobol)
                p.id,
                f.rol,
                f.cuenta_cobol,
                ?,
                ?,
                bool_or(f.activo) over (partition by f.rol, f.cuenta_cobol),
                now(),
                now()
            from tmp_gei_personas_fuente f
            join gei_core.personas p
              on p.fingerprint_actual = f.fingerprint
             and p.fusionada_en_id is null
            where not exists (
                select 1
                from gei_core.personas_cuentas_cobol c
                where c.rol = f.rol
                  and c.cuenta_cobol = f.cuenta_cobol
            )
            order by f.rol, f.cuenta_cobol, p.id
        SQL, [$periodo, $periodo]);

        // Actualiza extremos y actividad de todas las cuentas presentes.
        $db->statement(<<<SQL
            update gei_core.personas_cuentas_cobol c
               set primer_periodo = least(c.primer_periodo, ?),
                   ultimo_periodo = greatest(c.ultimo_periodo, ?),
                   activa = x.activa,
                   updated_at = now()
              from (
                    select rol, cuenta_cobol, bool_or(activo) as activa
                    from tmp_gei_personas_fuente
                    group by rol, cuenta_cobol
              ) x
             where c.rol = x.rol
               and c.cuenta_cobol = x.cuenta_cobol
        SQL, [$periodo, $periodo]);

        // Datos actuales: manda la variante operativa; si no hay una activa,
        // toma la observación más completa del snapshot actual.
        $db->statement(<<<'SQL'
            with elegida as (
                select distinct on (c.persona_id)
                    c.persona_id,
                    f.*
                from tmp_gei_personas_fuente f
                join gei_core.personas_cuentas_cobol c
                  on c.rol = f.rol
                 and c.cuenta_cobol = f.cuenta_cobol
                order by
                    c.persona_id,
                    f.activo desc,
                    (
                        (f.nombre is not null)::int +
                        (f.nro_documento is not null)::int +
                        (f.nro_iva is not null)::int +
                        (f.domicilio is not null)::int +
                        (f.localidad is not null)::int +
                        (f.telefono_1 is not null)::int
                    ) desc,
                    f.registro_origen_id desc
            )
            update gei_core.personas p
               set fingerprint_actual = e.fingerprint,
                   nombre = e.nombre,
                   domicilio = e.domicilio,
                   cp = e.cp,
                   localidad = e.localidad,
                   provincia = e.provincia,
                   tipo_documento = e.tipo_documento,
                   nro_documento = e.nro_documento,
                   tipo_iva = e.tipo_iva,
                   nro_iva = e.nro_iva,
                   telefono_1 = e.telefono_1,
                   telefono_2 = e.telefono_2,
                   updated_at = now()
              from elegida e
             where p.id = e.persona_id
        SQL);

        $db->statement(<<<SQL
            insert into gei_core.personas_roles (
                persona_id, rol, primer_periodo, ultimo_periodo, activo,
                created_at, updated_at
            )
            select
                c.persona_id,
                f.rol,
                ?,
                ?,
                bool_or(f.activo),
                now(),
                now()
            from tmp_gei_personas_fuente f
            join gei_core.personas_cuentas_cobol c
              on c.rol = f.rol
             and c.cuenta_cobol = f.cuenta_cobol
            group by c.persona_id, f.rol
            on conflict (persona_id, rol) do update set
                primer_periodo = least(gei_core.personas_roles.primer_periodo, excluded.primer_periodo),
                ultimo_periodo = greatest(gei_core.personas_roles.ultimo_periodo, excluded.ultimo_periodo),
                activo = excluded.activo,
                updated_at = now()
        SQL, [$periodo, $periodo]);

        $db->statement(<<<SQL
            insert into gei_core.personas_periodos (
                persona_id, periodo, rol, activo,
                cantidad_origenes, created_at
            )
            select
                c.persona_id,
                ?,
                f.rol,
                bool_or(f.activo),
                count(*)::integer,
                now()
            from tmp_gei_personas_fuente f
            join gei_core.personas_cuentas_cobol c
              on c.rol = f.rol
             and c.cuenta_cobol = f.cuenta_cobol
            group by c.persona_id, f.rol
        SQL, [$periodo]);

        $db->statement(<<<SQL
            insert into gei_core.personas_origenes (
                persona_id, periodo, rol, cuenta_cobol,
                archivo_id, registro_origen_id, numero_linea,
                fingerprint, activo_en_periodo, sha256_registro,
                created_at
            )
            select
                c.persona_id,
                ?,
                f.rol,
                f.cuenta_cobol,
                f.archivo_id,
                f.registro_origen_id,
                f.numero_linea,
                f.fingerprint,
                f.activo,
                f.sha256_registro,
                now()
            from tmp_gei_personas_fuente f
            join gei_core.personas_cuentas_cobol c
              on c.rol = f.rol
             and c.cuenta_cobol = f.cuenta_cobol
        SQL, [$periodo]);

        $prop = $db->selectOne(
            "select
                count(distinct c.persona_id) filter (where f.rol = 'PROPIETARIO') as personas,
                count(distinct f.cuenta_cobol) filter (where f.rol = 'PROPIETARIO') as cuentas,
                count(distinct c.persona_id) filter (where f.rol = 'PROPIETARIO' and f.activo) as personas_activas,
                count(distinct f.cuenta_cobol) filter (where f.rol = 'PROPIETARIO' and f.activo) as cuentas_activas,
                count(distinct c.persona_id) filter (where f.rol = 'INQUILINO') as inquilinos,
                count(distinct f.cuenta_cobol) filter (where f.rol = 'INQUILINO') as cuentas_inquilino,
                count(distinct c.persona_id) filter (where f.rol = 'INQUILINO' and f.activo) as inquilinos_activos,
                count(distinct f.cuenta_cobol) filter (where f.rol = 'INQUILINO' and f.activo) as cuentas_inquilino_activas
             from tmp_gei_personas_fuente f
             join gei_core.personas_cuentas_cobol c
               on c.rol = f.rol
              and c.cuenta_cobol = f.cuenta_cobol"
        );

        return [
            'personas_propietarias' => (int) ($prop->personas ?? 0),
            'cuentas_propietario' => (int) ($prop->cuentas ?? 0),
            'personas_propietarias_activas' => (int) ($prop->personas_activas ?? 0),
            'cuentas_propietario_activas' => (int) ($prop->cuentas_activas ?? 0),
            'personas_inquilinas' => (int) ($prop->inquilinos ?? 0),
            'cuentas_inquilino' => (int) ($prop->cuentas_inquilino ?? 0),
            'personas_inquilinas_activas' => (int) ($prop->inquilinos_activos ?? 0),
            'cuentas_inquilino_activas' => (int) ($prop->cuentas_inquilino_activas ?? 0),
        ];
    }

    private function procesarContratosEInmuebles(
        Connection $db,
        string $schema,
        string $periodo,
        int $archivoInquilino
    ): array {
        $db->statement('drop table if exists tmp_gei_contratos_fuente');

        $db->statement(<<<'SQL'
            create temporary table tmp_gei_contratos_fuente (
                cuenta_inquilino text not null,
                cuenta_propietario text,
                direccion_finca text,
                clave_inmueble text not null,
                fecha_contrato text,
                fecha_vencimiento text,
                fecha_primer_ajuste text,
                fecha_inicio text,
                fecha_celebracion text,
                marca_baja text,
                fecha_baja text,
                nro_liquidacion text,
                marca_intimacion text,
                plazo text,
                plazo_dias text,
                indice text,
                tipo_ajuste text,
                cuota_1 numeric(18,2),
                cuota_2 numeric(18,2),
                alquiler_inicial numeric(18,2),
                cuota_2_dolar numeric(18,2),
                destino text,
                administracion_responsable text,
                penal_porcentaje text,
                penal_importe numeric(18,2),
                comision_anterior text,
                comision_importe numeric(18,2),
                reparacion text,
                dias_reparacion text,
                acumulado_penalidad numeric(18,2),
                fecha_juicio text,
                abogado text,
                ajuste_1_fecha text,
                ajuste_1_porcentaje numeric(10,1),
                ajuste_2_fecha text,
                ajuste_2_porcentaje numeric(10,1),
                ajuste_3_fecha text,
                ajuste_3_porcentaje numeric(10,1),
                ajuste_4_fecha text,
                ajuste_4_porcentaje numeric(10,1),
                ajuste_5_fecha text,
                ajuste_5_porcentaje numeric(10,1),
                ajuste_6_fecha text,
                ajuste_6_porcentaje numeric(10,1),
                ajuste_7_fecha text,
                ajuste_7_porcentaje numeric(10,1),
                ajuste_8_fecha text,
                ajuste_8_porcentaje numeric(10,1),
                activo boolean not null,
                partida_1 text,
                partida_2 text,
                partida_3 text,
                partida_4 text,
                partida_5 text,
                partida_6 text,
                archivo_id bigint not null,
                registro_origen_id bigint not null,
                numero_linea bigint,
                sha256_registro text
            ) on commit drop
        SQL);

        $partidas = [];
        for ($n = 1; $n <= 6; $n++) {
            $partidas[] = "case
                when nullif(btrim(i.partida_{$n}::text), '') is null then null
                when regexp_replace(btrim(i.partida_{$n}::text), '[^0-9A-Za-z]', '', 'g') ~ '^0+$' then null
                else btrim(i.partida_{$n}::text)
            end";
        }
        $partidasSql = implode(",\n                ", $partidas);

        $clavePartidas = "concat_ws('|', ".
            implode(', ', array_map(
                fn (int $n) => "nullif(regexp_replace(btrim(i.partida_{$n}::text), '\\s+', '', 'g'), '')",
                range(1, 6)
            )).")";

        $db->statement(<<<SQL
            insert into tmp_gei_contratos_fuente (
                cuenta_inquilino, cuenta_propietario, direccion_finca,
                clave_inmueble,
                fecha_contrato, fecha_vencimiento, fecha_primer_ajuste,
                fecha_inicio, fecha_celebracion,
                marca_baja, fecha_baja, nro_liquidacion, marca_intimacion,
                plazo, plazo_dias, indice, tipo_ajuste,
                cuota_1, cuota_2, alquiler_inicial, cuota_2_dolar,
                destino, administracion_responsable,
                penal_porcentaje, penal_importe,
                comision_anterior, comision_importe,
                reparacion, dias_reparacion, acumulado_penalidad,
                fecha_juicio, abogado,
                ajuste_1_fecha, ajuste_1_porcentaje,
                ajuste_2_fecha, ajuste_2_porcentaje,
                ajuste_3_fecha, ajuste_3_porcentaje,
                ajuste_4_fecha, ajuste_4_porcentaje,
                ajuste_5_fecha, ajuste_5_porcentaje,
                ajuste_6_fecha, ajuste_6_porcentaje,
                ajuste_7_fecha, ajuste_7_porcentaje,
                ajuste_8_fecha, ajuste_8_porcentaje,
                activo,
                partida_1, partida_2, partida_3, partida_4, partida_5, partida_6,
                archivo_id, registro_origen_id, numero_linea, sha256_registro
            )
            select
                btrim(i.cta_inquilino::text),
                nullif(btrim(i.cta_propietario::text), ''),
                nullif(btrim(i.direccion_finca), ''),
                case
                    when nullif(regexp_replace({$clavePartidas}, '[|]', '', 'g'), '') is not null
                        then 'PARTIDAS:' || md5({$clavePartidas})
                    else
                        'DIRECCION:' || md5(
                            {$this->sqlNormalizarDomicilio('i.direccion_finca')}
                            || '|PROP:' || coalesce(btrim(i.cta_propietario::text), '')
                        )
                end,
                nullif(lpad(btrim(i.fecha_contrato::text), 8, '0'), '00000000'),
                nullif(lpad(btrim(i.fecha_vencimiento::text), 8, '0'), '00000000'),
                nullif(lpad(btrim(i.fecha_primer_ajuste::text), 8, '0'), '00000000'),
                nullif(lpad(btrim(i.fecha_inicio::text), 8, '0'), '00000000'),
                nullif(lpad(btrim(i.fecha_celebracion_redefine::text), 8, '0'), '00000000'),
                nullif(btrim(i.marca_baja::text), ''),
                case when coalesce(btrim(i.fecha_baja::text), '') ~ '^0+$' then null else nullif(lpad(btrim(i.fecha_baja::text), 8, '0'), '00000000') end,
                nullif(btrim(i.nro_liquidacion::text), ''),
                nullif(btrim(i.marca_intimacion::text), ''),
                nullif(btrim(i.plazo::text), ''),
                nullif(btrim(i.plazo_dias::text), ''),
                nullif(btrim(i.indice::text), ''),
                nullif(btrim(i.tipo_ajuste::text), ''),
                {$this->sqlNumeroV99SinSigno('i.cuota_1')},
                {$this->sqlNumeroV99SinSigno('i.cuota_2')},
                {$this->sqlNumeroV99SinSigno('i.alquiler_inicial')},
                {$this->sqlNumeroV99SinSigno('i.cuota_2_dolar')},
                nullif(btrim(i.destino::text), ''),
                nullif(btrim(i.administracion_responsable::text), ''),
                nullif(btrim(i.penal_porcentaje::text), ''),
                {$this->sqlNumeroV99SinSigno('i.penal_importe')},
                nullif(btrim(i.comision_anterior::text), ''),
                {$this->sqlNumeroV99SinSigno('i.comision_importe')},
                nullif(btrim(i.reparacion::text), ''),
                nullif(btrim(i.dias_reparacion::text), ''),
                {$this->sqlNumeroV99SinSigno('i.acumulado_penalidad')},
                case when coalesce(btrim(i.fecha_juicio::text), '') ~ '^0+$' then null else nullif(lpad(btrim(i.fecha_juicio::text), 8, '0'), '00000000') end,
                nullif(btrim(i.abogado::text), ''),
                nullif(lpad(btrim(i.ajuste_1_fecha::text), 8, '0'), '00000000'),
                {$this->sqlNumeroV1ConSigno('i.ajuste_1_porcentaje')},
                nullif(lpad(btrim(i.ajuste_2_fecha::text), 8, '0'), '00000000'),
                {$this->sqlNumeroV1ConSigno('i.ajuste_2_porcentaje')},
                nullif(lpad(btrim(i.ajuste_3_fecha::text), 8, '0'), '00000000'),
                {$this->sqlNumeroV1ConSigno('i.ajuste_3_porcentaje')},
                nullif(lpad(btrim(i.ajuste_4_fecha::text), 8, '0'), '00000000'),
                {$this->sqlNumeroV1ConSigno('i.ajuste_4_porcentaje')},
                nullif(lpad(btrim(i.ajuste_5_fecha::text), 8, '0'), '00000000'),
                {$this->sqlNumeroV1ConSigno('i.ajuste_5_porcentaje')},
                nullif(lpad(btrim(i.ajuste_6_fecha::text), 8, '0'), '00000000'),
                {$this->sqlNumeroV1ConSigno('i.ajuste_6_porcentaje')},
                nullif(lpad(btrim(i.ajuste_7_fecha::text), 8, '0'), '00000000'),
                {$this->sqlNumeroV1ConSigno('i.ajuste_7_porcentaje')},
                nullif(lpad(btrim(i.ajuste_8_fecha::text), 8, '0'), '00000000'),
                {$this->sqlNumeroV1ConSigno('i.ajuste_8_porcentaje')},
                (
                    nullif(btrim(i.marca_baja::text), '') is null
                    and exists (
                        select 1
                        from gei_core.personas_cuentas_cobol pc
                        where pc.rol = 'PROPIETARIO'
                          and pc.cuenta_cobol = btrim(i.cta_propietario::text)
                          and pc.activa = true
                    )
                ),
                {$partidasSql},
                i.archivo_id,
                i.id,
                i.numero_linea,
                i.sha256_registro
            from {$schema}.inquilino i
            where i.archivo_id = ?
              and nullif(btrim(i.cta_inquilino::text), '') is not null
        SQL, [$archivoInquilino]);

        $db->statement('create index on tmp_gei_contratos_fuente (cuenta_inquilino)');
        $db->statement('create index on tmp_gei_contratos_fuente (clave_inmueble)');
        $db->statement('create index on tmp_gei_contratos_fuente (cuenta_propietario)');

        // Inmueble: primero partidas; si no hay partidas, dirección + cuenta propietaria.
        // Es deliberadamente conservador para evitar fusionar dos unidades distintas.
        $db->statement(<<<SQL
            insert into gei_core.inmuebles (
                clave_identidad, domicilio_actual,
                primer_periodo, ultimo_periodo, activo,
                created_at, updated_at
            )
            select distinct on (clave_inmueble)
                clave_inmueble,
                direccion_finca,
                ?,
                ?,
                activo,
                now(),
                now()
            from tmp_gei_contratos_fuente
            order by clave_inmueble, activo desc, registro_origen_id desc
            on conflict (clave_identidad) do update set
                domicilio_actual = excluded.domicilio_actual,
                primer_periodo = least(gei_core.inmuebles.primer_periodo, excluded.primer_periodo),
                ultimo_periodo = greatest(gei_core.inmuebles.ultimo_periodo, excluded.ultimo_periodo),
                activo = excluded.activo,
                updated_at = now()
        SQL, [$periodo, $periodo]);

        $db->statement(<<<SQL
            insert into gei_core.inmuebles_periodos (
                inmueble_id, periodo, activo, cantidad_contratos, created_at
            )
            select
                im.id,
                ?,
                bool_or(f.activo),
                count(distinct f.cuenta_inquilino)::integer,
                now()
            from tmp_gei_contratos_fuente f
            join gei_core.inmuebles im
              on im.clave_identidad = f.clave_inmueble
            group by im.id
        SQL, [$periodo]);

        $db->statement(<<<SQL
            insert into gei_core.inmuebles_origenes (
                inmueble_id, periodo, archivo_id, registro_origen_id,
                numero_linea, cuenta_inquilino_cobol, cuenta_propietario_cobol,
                direccion_original, activo_en_periodo, sha256_registro, created_at
            )
            select
                im.id,
                ?,
                f.archivo_id,
                f.registro_origen_id,
                f.numero_linea,
                f.cuenta_inquilino,
                f.cuenta_propietario,
                f.direccion_finca,
                f.activo,
                f.sha256_registro,
                now()
            from tmp_gei_contratos_fuente f
            join gei_core.inmuebles im
              on im.clave_identidad = f.clave_inmueble
        SQL, [$periodo]);

        // Partidas históricas del inmueble.
        for ($n = 1; $n <= 6; $n++) {
            $db->statement(<<<SQL
                insert into gei_core.inmuebles_partidas (
                    inmueble_id, partida, primer_periodo, ultimo_periodo, created_at, updated_at
                )
                select distinct
                    im.id,
                    f.partida_{$n},
                    ?,
                    ?,
                    now(),
                    now()
                from tmp_gei_contratos_fuente f
                join gei_core.inmuebles im
                  on im.clave_identidad = f.clave_inmueble
                where f.partida_{$n} is not null
                on conflict (inmueble_id, partida) do update set
                    primer_periodo = least(gei_core.inmuebles_partidas.primer_periodo, excluded.primer_periodo),
                    ultimo_periodo = greatest(gei_core.inmuebles_partidas.ultimo_periodo, excluded.ultimo_periodo),
                    updated_at = now()
            SQL, [$periodo, $periodo]);
        }

        // Contrato COBOL: la cuenta de inquilino es la continuidad operativa.
        $db->statement(<<<SQL
            insert into gei_core.contratos (
                cuenta_inquilino_cobol,
                inmueble_id,
                cuenta_propietario_cobol,
                fecha_contrato_original,
                fecha_vencimiento_original,
                fecha_inicio_original,
                marca_baja,
                fecha_baja_original,
                plazo_original,
                indice_original,
                tipo_ajuste_original,
                cuota_1_original,
                cuota_2_original,
                alquiler_inicial_original,
                destino_original,
                administracion_responsable,
                primer_periodo,
                ultimo_periodo,
                activo,
                created_at,
                updated_at
            )
            select distinct on (f.cuenta_inquilino)
                f.cuenta_inquilino,
                im.id,
                f.cuenta_propietario,
                f.fecha_contrato,
                f.fecha_vencimiento,
                f.fecha_inicio,
                f.marca_baja,
                f.fecha_baja,
                f.plazo,
                f.indice,
                f.tipo_ajuste,
                f.cuota_1,
                f.cuota_2,
                f.alquiler_inicial,
                f.destino,
                f.administracion_responsable,
                ?,
                ?,
                f.activo,
                now(),
                now()
            from tmp_gei_contratos_fuente f
            join gei_core.inmuebles im
              on im.clave_identidad = f.clave_inmueble
            order by f.cuenta_inquilino, f.activo desc, f.registro_origen_id desc
            on conflict (cuenta_inquilino_cobol) do update set
                inmueble_id = excluded.inmueble_id,
                cuenta_propietario_cobol = excluded.cuenta_propietario_cobol,
                fecha_contrato_original = excluded.fecha_contrato_original,
                fecha_vencimiento_original = excluded.fecha_vencimiento_original,
                fecha_inicio_original = excluded.fecha_inicio_original,
                marca_baja = excluded.marca_baja,
                fecha_baja_original = excluded.fecha_baja_original,
                plazo_original = excluded.plazo_original,
                indice_original = excluded.indice_original,
                tipo_ajuste_original = excluded.tipo_ajuste_original,
                cuota_1_original = excluded.cuota_1_original,
                cuota_2_original = excluded.cuota_2_original,
                alquiler_inicial_original = excluded.alquiler_inicial_original,
                destino_original = excluded.destino_original,
                administracion_responsable = excluded.administracion_responsable,
                primer_periodo = least(gei_core.contratos.primer_periodo, excluded.primer_periodo),
                ultimo_periodo = greatest(gei_core.contratos.ultimo_periodo, excluded.ultimo_periodo),
                activo = excluded.activo,
                updated_at = now()
        SQL, [$periodo, $periodo]);

        $db->statement(<<<SQL
            insert into gei_core.contratos_periodos (
                contrato_id, periodo, inmueble_id,
                persona_inquilino_id, persona_propietario_id,
                cuenta_inquilino_cobol, cuenta_propietario_cobol,
                activo, marca_baja,
                fecha_contrato_original, fecha_vencimiento_original,
                fecha_primer_ajuste_original, fecha_inicio_locacion_original,
                fecha_celebracion_original, fecha_baja_original,
                nro_liquidacion_original, marca_intimacion_original,
                plazo_meses, plazo_dias, indice, tipo_ajuste,
                cuota_1, cuota_2, alquiler_inicial, cuota_2_dolar,
                destino, administracion_responsable,
                penal_porcentaje, penal_importe,
                comision_anterior, comision_importe,
                reparacion, dias_reparacion, acumulado_penalidad,
                fecha_juicio_original, abogado,
                ajuste_1_fecha, ajuste_1_porcentaje,
                ajuste_2_fecha, ajuste_2_porcentaje,
                ajuste_3_fecha, ajuste_3_porcentaje,
                ajuste_4_fecha, ajuste_4_porcentaje,
                ajuste_5_fecha, ajuste_5_porcentaje,
                ajuste_6_fecha, ajuste_6_porcentaje,
                ajuste_7_fecha, ajuste_7_porcentaje,
                ajuste_8_fecha, ajuste_8_porcentaje,
                created_at
            )
            select
                c.id,
                ?,
                c.inmueble_id,
                ci.persona_id,
                cp.persona_id,
                f.cuenta_inquilino,
                f.cuenta_propietario,
                f.activo,
                f.marca_baja,
                f.fecha_contrato,
                f.fecha_vencimiento,
                f.fecha_primer_ajuste,
                f.fecha_inicio,
                f.fecha_celebracion,
                f.fecha_baja,
                f.nro_liquidacion,
                f.marca_intimacion,
                case
                    when nullif(btrim(f.plazo), '') is null then null
                    when btrim(f.plazo) ~ '^[0-9]+$' then btrim(f.plazo)::integer
                    else null
                end,
                case
                    when nullif(btrim(f.plazo_dias), '') is null then null
                    when btrim(f.plazo_dias) ~ '^[0-9]+$' then btrim(f.plazo_dias)::integer
                    else null
                end,
                f.indice,
                f.tipo_ajuste,
                f.cuota_1,
                f.cuota_2,
                f.alquiler_inicial,
                f.cuota_2_dolar,
                f.destino,
                f.administracion_responsable,
                case
                    when nullif(btrim(f.penal_porcentaje), '') is null then null
                    when replace(btrim(f.penal_porcentaje), ',', '.') ~ '^[0-9]+(\.[0-9]+)?$'
                        then replace(btrim(f.penal_porcentaje), ',', '.')::numeric
                    else null
                end,
                f.penal_importe,
                f.comision_anterior,
                f.comision_importe,
                f.reparacion,
                case
                    when nullif(btrim(f.dias_reparacion), '') is null then null
                    when btrim(f.dias_reparacion) ~ '^[0-9]+$' then btrim(f.dias_reparacion)::integer
                    else null
                end,
                f.acumulado_penalidad,
                f.fecha_juicio,
                f.abogado,
                f.ajuste_1_fecha, f.ajuste_1_porcentaje,
                f.ajuste_2_fecha, f.ajuste_2_porcentaje,
                f.ajuste_3_fecha, f.ajuste_3_porcentaje,
                f.ajuste_4_fecha, f.ajuste_4_porcentaje,
                f.ajuste_5_fecha, f.ajuste_5_porcentaje,
                f.ajuste_6_fecha, f.ajuste_6_porcentaje,
                f.ajuste_7_fecha, f.ajuste_7_porcentaje,
                f.ajuste_8_fecha, f.ajuste_8_porcentaje,
                now()
            from tmp_gei_contratos_fuente f
            join gei_core.contratos c
              on c.cuenta_inquilino_cobol = f.cuenta_inquilino
            left join gei_core.personas_cuentas_cobol ci
              on ci.rol = 'INQUILINO'
             and ci.cuenta_cobol = f.cuenta_inquilino
            left join gei_core.personas_cuentas_cobol cp
              on cp.rol = 'PROPIETARIO'
             and cp.cuenta_cobol = f.cuenta_propietario
        SQL, [$periodo]);

        $db->statement(<<<SQL
            insert into gei_core.contratos_origenes (
                contrato_id, periodo, archivo_id, registro_origen_id,
                numero_linea, sha256_registro, created_at
            )
            select
                c.id,
                ?,
                f.archivo_id,
                f.registro_origen_id,
                f.numero_linea,
                f.sha256_registro,
                now()
            from tmp_gei_contratos_fuente f
            join gei_core.contratos c
              on c.cuenta_inquilino_cobol = f.cuenta_inquilino
        SQL, [$periodo]);

        $fila = $db->selectOne(
            "select
                count(*) as contratos,
                count(*) filter (where activo) as contratos_activos,
                count(distinct clave_inmueble) as inmuebles,
                count(distinct clave_inmueble) filter (where activo) as inmuebles_activos,
                count(distinct cuenta_propietario) filter (where activo) as cuentas_prop_activo
             from tmp_gei_contratos_fuente"
        );

        return [
            'contratos' => (int) ($fila->contratos ?? 0),
            'contratos_activos' => (int) ($fila->contratos_activos ?? 0),
            'inmuebles' => (int) ($fila->inmuebles ?? 0),
            'inmuebles_activos' => (int) ($fila->inmuebles_activos ?? 0),
            'cuentas_propietario_con_contrato_activo' => (int) ($fila->cuentas_prop_activo ?? 0),
        ];
    }

    private function procesarCuentasCorrientes(
        Connection $db,
        string $schema,
        string $periodo
    ): array {
        $archivoProp = $this->archivoDelPeriodo($db, $periodo, 'ctactepro');
        $archivoInq = $this->archivoDelPeriodo($db, $periodo, 'inqctacte');

        // Las cuentas maestras se conservan entre períodos. La cuenta COBOL es
        // la clave operativa histórica; persona/contrato son relaciones.
        $db->statement(<<<SQL
            insert into gei_core.cuentas_corrientes (
                tipo, cuenta_cobol, persona_id, contrato_id,
                primer_periodo, ultimo_periodo, activa,
                created_at, updated_at
            )
            select
                'PROPIETARIO',
                btrim(c.cuenta::text),
                pc.persona_id,
                null,
                ?,
                ?,
                coalesce(pc.activa, false),
                now(),
                now()
            from {$schema}.ctactepro c
            left join gei_core.personas_cuentas_cobol pc
              on pc.rol = 'PROPIETARIO'
             and pc.cuenta_cobol = btrim(c.cuenta::text)
            where c.archivo_id = ?
              and nullif(btrim(c.cuenta::text), '') is not null
            group by btrim(c.cuenta::text), pc.persona_id, pc.activa
            on conflict (tipo, cuenta_cobol) do update set
                persona_id = coalesce(excluded.persona_id, gei_core.cuentas_corrientes.persona_id),
                primer_periodo = least(gei_core.cuentas_corrientes.primer_periodo, excluded.primer_periodo),
                ultimo_periodo = greatest(gei_core.cuentas_corrientes.ultimo_periodo, excluded.ultimo_periodo),
                activa = excluded.activa,
                updated_at = now()
        SQL, [$periodo, $periodo, $archivoProp]);

        $db->statement(<<<SQL
            insert into gei_core.cuentas_corrientes (
                tipo, cuenta_cobol, persona_id, contrato_id,
                primer_periodo, ultimo_periodo, activa,
                created_at, updated_at
            )
            select
                'INQUILINO',
                btrim(c.cuenta::text),
                pc.persona_id,
                ct.id,
                ?,
                ?,
                coalesce(pc.activa, false),
                now(),
                now()
            from {$schema}.inqctacte c
            left join gei_core.personas_cuentas_cobol pc
              on pc.rol = 'INQUILINO'
             and pc.cuenta_cobol = btrim(c.cuenta::text)
            left join gei_core.contratos ct
              on ct.cuenta_inquilino_cobol = btrim(c.cuenta::text)
            where c.archivo_id = ?
              and nullif(btrim(c.cuenta::text), '') is not null
            group by btrim(c.cuenta::text), pc.persona_id, pc.activa, ct.id
            on conflict (tipo, cuenta_cobol) do update set
                persona_id = coalesce(excluded.persona_id, gei_core.cuentas_corrientes.persona_id),
                contrato_id = coalesce(excluded.contrato_id, gei_core.cuentas_corrientes.contrato_id),
                primer_periodo = least(gei_core.cuentas_corrientes.primer_periodo, excluded.primer_periodo),
                ultimo_periodo = greatest(gei_core.cuentas_corrientes.ultimo_periodo, excluded.ultimo_periodo),
                activa = excluded.activa,
                updated_at = now()
        SQL, [$periodo, $periodo, $archivoInq]);

        // Los movimientos se procesan por lotes para que el progreso sea real y visible.
        $propMov = $this->totalMovimientosValidos($db, $schema, 'ctactepro', $archivoProp);
        $inqMov = $this->totalMovimientosValidos($db, $schema, 'inqctacte', $archivoInq);
        $totalMov = $propMov + $inqMov;
        $procesados = 0;

        $this->guardarProgreso(
            $periodo,
            'GEI_CORE_CUENTAS',
            'Procesando CTACTEPRO.',
            78,
            0,
            $totalMov,
            'PROCESANDO',
            'CTACTEPRO'
        );

        $procesados += $this->procesarMovimientosCtacteproPorLotes(
            $db,
            $schema,
            $periodo,
            $archivoProp,
            $procesados,
            $totalMov
        );

        $this->guardarProgreso(
            $periodo,
            'GEI_CORE_CUENTAS',
            'Procesando INQCTACTE.',
            80,
            $procesados,
            $totalMov,
            'PROCESANDO',
            'INQCTACTE'
        );

        $procesados += $this->procesarMovimientosInqctactePorLotes(
            $db,
            $schema,
            $periodo,
            $archivoInq,
            $procesados,
            $totalMov
        );

        $this->guardarProgreso(
            $periodo,
            'GEI_CORE_CUENTAS',
            'Cuentas corrientes y movimientos procesados.',
            82,
            $procesados,
            $totalMov
        );

        $propCuentas = (int) ($db->selectOne(
            "select count(distinct btrim(cuenta::text)) as total
             from {$schema}.ctactepro
             where archivo_id = ?",
            [$archivoProp]
        )->total ?? 0);

        $inqCuentas = (int) ($db->selectOne(
            "select count(distinct btrim(cuenta::text)) as total
             from {$schema}.inqctacte
             where archivo_id = ?",
            [$archivoInq]
        )->total ?? 0);

        return [
            'archivo_ctactepro' => $archivoProp,
            'archivo_inqctacte' => $archivoInq,
            'cuentas_corrientes_propietario' => $propCuentas,
            'cuentas_corrientes_inquilino' => $inqCuentas,
            'movimientos_ctactepro_fuente' => $propMov,
            'movimientos_inqctacte_fuente' => $inqMov,
        ];
    }

    private function totalMovimientosValidos(
        Connection $db,
        string $schema,
        string $tabla,
        int $archivoId
    ): int {
        return (int) ($db->selectOne(
            "select count(*) as total
               from {$schema}.{$tabla}
              where archivo_id = ?
                and nullif(btrim(fecha::text), '') is not null
                and nullif(btrim(codigo::text), '') is not null
                and nullif(btrim(numero::text), '') is not null",
            [$archivoId]
        )->total ?? 0);
    }

    private function procesarMovimientosCtacteproPorLotes(
        Connection $db,
        string $schema,
        string $periodo,
        int $archivoId,
        int $procesadosPrevios,
        int $totalGlobal
    ): int {
        return $this->procesarRangosFuente(
            $db,
            $schema,
            'ctactepro',
            $archivoId,
            function (int $desde, int $hasta) use ($db, $schema, $periodo, $archivoId): void {
                $db->statement(<<<SQL
                    insert into gei_core.cuentas_corrientes_movimientos (
                        cuenta_corriente_id, primer_periodo, ultimo_periodo, periodo_origen,
                        fecha_original, codigo, numero, fecha_vencimiento_original,
                        importe, importe_penal, importe_abonado, descripcion,
                        cuenta_inquilino_relacionada, liquidado, iva, no_iva,
                        archivo_id, registro_origen_id, numero_linea, sha256_registro,
                        created_at, updated_at
                    )
                    select
                        cc.id, ?, ?, ?, btrim(c.fecha::text), btrim(c.codigo::text),
                        btrim(c.numero::text), null, {$this->sqlNumero('c.importe')},
                        null, null, nullif(btrim(c.descripcion::text), ''),
                        nullif(btrim(c.inquilino::text), ''), nullif(btrim(c.liquidado::text), ''),
                        {$this->sqlNumero('c.iva')}, {$this->sqlNumero('c.no_iva')},
                        c.archivo_id, c.id, c.numero_linea, c.sha256_registro, now(), now()
                    from {$schema}.ctactepro c
                    join gei_core.cuentas_corrientes cc
                      on cc.tipo = 'PROPIETARIO'
                     and cc.cuenta_cobol = btrim(c.cuenta::text)
                    where c.archivo_id = ?
                      and c.id between ? and ?
                      and nullif(btrim(c.fecha::text), '') is not null
                      and nullif(btrim(c.codigo::text), '') is not null
                      and nullif(btrim(c.numero::text), '') is not null
                    on conflict (cuenta_corriente_id, fecha_original, codigo, numero)
                    do update set
                        ultimo_periodo = greatest(gei_core.cuentas_corrientes_movimientos.ultimo_periodo, excluded.ultimo_periodo),
                        periodo_origen = excluded.periodo_origen,
                        importe = excluded.importe,
                        descripcion = excluded.descripcion,
                        cuenta_inquilino_relacionada = excluded.cuenta_inquilino_relacionada,
                        liquidado = excluded.liquidado,
                        iva = excluded.iva,
                        no_iva = excluded.no_iva,
                        archivo_id = excluded.archivo_id,
                        registro_origen_id = excluded.registro_origen_id,
                        numero_linea = excluded.numero_linea,
                        sha256_registro = excluded.sha256_registro,
                        updated_at = now()
                SQL, [$periodo, $periodo, $periodo, $archivoId, $desde, $hasta]);
            },
            function (int $procesadosTabla) use ($periodo, $procesadosPrevios, $totalGlobal): void {
                $hechos = $procesadosPrevios + $procesadosTabla;
                $porcentaje = $totalGlobal > 0 ? 78 + (int) floor(($hechos / $totalGlobal) * 4) : 78;
                $this->guardarProgreso(
                    $periodo, 'GEI_CORE_CUENTAS', 'Procesando CTACTEPRO.',
                    min(82, $porcentaje), $hechos, $totalGlobal, 'PROCESANDO', 'CTACTEPRO'
                );
            }
        );
    }

    private function procesarMovimientosInqctactePorLotes(
        Connection $db,
        string $schema,
        string $periodo,
        int $archivoId,
        int $procesadosPrevios,
        int $totalGlobal
    ): int {
        return $this->procesarRangosFuente(
            $db,
            $schema,
            'inqctacte',
            $archivoId,
            function (int $desde, int $hasta) use ($db, $schema, $periodo, $archivoId): void {
                $db->statement(<<<SQL
                    insert into gei_core.cuentas_corrientes_movimientos (
                        cuenta_corriente_id, primer_periodo, ultimo_periodo, periodo_origen,
                        fecha_original, codigo, numero, fecha_vencimiento_original,
                        importe, importe_penal, importe_abonado, descripcion,
                        cuenta_inquilino_relacionada, liquidado, iva, no_iva,
                        archivo_id, registro_origen_id, numero_linea, sha256_registro,
                        created_at, updated_at
                    )
                    select
                        cc.id, ?, ?, ?, btrim(c.fecha::text), btrim(c.codigo::text),
                        btrim(c.numero::text), nullif(btrim(c.fecha_vencimiento::text), ''),
                        {$this->sqlNumero('c.importe')}, {$this->sqlNumero('c.importe_penalidad')},
                        {$this->sqlNumero('c.importe_abonado')}, nullif(btrim(c.descripcion::text), ''),
                        null, nullif(btrim(c.liquidado::text), ''),
                        {$this->sqlNumero('c.iva')}, {$this->sqlNumero('c.no_iva')},
                        c.archivo_id, c.id, c.numero_linea, c.sha256_registro, now(), now()
                    from {$schema}.inqctacte c
                    join gei_core.cuentas_corrientes cc
                      on cc.tipo = 'INQUILINO'
                     and cc.cuenta_cobol = btrim(c.cuenta::text)
                    where c.archivo_id = ?
                      and c.id between ? and ?
                      and nullif(btrim(c.fecha::text), '') is not null
                      and nullif(btrim(c.codigo::text), '') is not null
                      and nullif(btrim(c.numero::text), '') is not null
                    on conflict (cuenta_corriente_id, fecha_original, codigo, numero)
                    do update set
                        ultimo_periodo = greatest(gei_core.cuentas_corrientes_movimientos.ultimo_periodo, excluded.ultimo_periodo),
                        periodo_origen = excluded.periodo_origen,
                        fecha_vencimiento_original = excluded.fecha_vencimiento_original,
                        importe = excluded.importe,
                        importe_penal = excluded.importe_penal,
                        importe_abonado = excluded.importe_abonado,
                        descripcion = excluded.descripcion,
                        liquidado = excluded.liquidado,
                        iva = excluded.iva,
                        no_iva = excluded.no_iva,
                        archivo_id = excluded.archivo_id,
                        registro_origen_id = excluded.registro_origen_id,
                        numero_linea = excluded.numero_linea,
                        sha256_registro = excluded.sha256_registro,
                        updated_at = now()
                SQL, [$periodo, $periodo, $periodo, $archivoId, $desde, $hasta]);
            },
            function (int $procesadosTabla) use ($periodo, $procesadosPrevios, $totalGlobal): void {
                $hechos = $procesadosPrevios + $procesadosTabla;
                $porcentaje = $totalGlobal > 0 ? 78 + (int) floor(($hechos / $totalGlobal) * 4) : 80;
                $this->guardarProgreso(
                    $periodo, 'GEI_CORE_CUENTAS', 'Procesando INQCTACTE.',
                    min(82, $porcentaje), $hechos, $totalGlobal, 'PROCESANDO', 'INQCTACTE'
                );
            }
        );
    }

    private function procesarRangosFuente(
        Connection $db,
        string $schema,
        string $tabla,
        int $archivoId,
        callable $procesarRango,
        callable $avance
    ): int {
        $limites = $db->selectOne(
            "select min(id) as minimo, max(id) as maximo
               from {$schema}.{$tabla}
              where archivo_id = ?",
            [$archivoId]
        );

        if ($limites === null || $limites->minimo === null || $limites->maximo === null) {
            return 0;
        }

        $tamanoLote = 20000;
        $minimo = (int) $limites->minimo;
        $maximo = (int) $limites->maximo;
        $procesados = 0;

        for ($desde = $minimo; $desde <= $maximo; $desde += $tamanoLote) {
            $hasta = min($maximo, $desde + $tamanoLote - 1);
            $procesarRango($desde, $hasta);

            $procesados += (int) ($db->selectOne(
                "select count(*) as total
                   from {$schema}.{$tabla}
                  where archivo_id = ?
                    and id between ? and ?
                    and nullif(btrim(fecha::text), '') is not null
                    and nullif(btrim(codigo::text), '') is not null
                    and nullif(btrim(numero::text), '') is not null",
                [$archivoId, $desde, $hasta]
            )->total ?? 0);

            $avance($procesados);
        }

        return $procesados;
    }

    private function generarConflictosBasicos(Connection $db, string $periodo): void
    {
        // Contrato sin persona inquilina.
        $db->statement(<<<SQL
            insert into gei_core.conflictos (
                periodo, tipo, entidad, clave, severidad, detalle, estado, created_at
            )
            select
                ?,
                'CONTRATO_SIN_PERSONA_INQUILINO',
                'CONTRATO',
                cp.cuenta_inquilino_cobol,
                'ERROR',
                jsonb_build_object('contrato_id', cp.contrato_id),
                'PENDIENTE',
                now()
            from gei_core.contratos_periodos cp
            where cp.periodo = ?
              and cp.persona_inquilino_id is null
        SQL, [$periodo, $periodo]);

        // Contrato activo con propietario que no pertenece a la foto activa.
        $db->statement(<<<SQL
            insert into gei_core.conflictos (
                periodo, tipo, entidad, clave, severidad, detalle, estado, created_at
            )
            select
                ?,
                'CONTRATO_ACTIVO_SIN_PROPIETARIO_ACTIVO',
                'CONTRATO',
                cp.cuenta_inquilino_cobol,
                'ERROR',
                jsonb_build_object(
                    'contrato_id', cp.contrato_id,
                    'cuenta_propietario', cp.cuenta_propietario_cobol
                ),
                'PENDIENTE',
                now()
            from gei_core.contratos_periodos cp
            where cp.periodo = ?
              and cp.activo = true
              and not exists (
                  select 1
                  from gei_core.personas_cuentas_cobol pc
                  where pc.rol = 'PROPIETARIO'
                    and pc.cuenta_cobol = cp.cuenta_propietario_cobol
                    and pc.activa = true
              )
        SQL, [$periodo, $periodo]);

        // Una misma persona con dos IVA útiles diferentes en el mismo período.
        $db->statement(<<<SQL
            insert into gei_core.conflictos (
                periodo, tipo, entidad, clave, severidad, detalle, estado, created_at
            )
            select
                ?,
                'PERSONA_IVA_CONTRADICTORIO',
                'PERSONA',
                po.persona_id::text,
                'ERROR',
                jsonb_build_object(
                    'persona_id', po.persona_id,
                    'ivas', jsonb_agg(distinct nullif(btrim(f.nro_iva), ''))
                ),
                'PENDIENTE',
                now()
            from gei_core.personas_origenes po
            join tmp_gei_personas_fuente f
              on f.rol = po.rol
             and f.cuenta_cobol = po.cuenta_cobol
             and f.registro_origen_id = po.registro_origen_id
            where po.periodo = ?
              and f.nro_iva is not null
            group by po.persona_id
            having count(distinct f.nro_iva) > 1
        SQL, [$periodo, $periodo]);

        // Una misma persona con dos documentos útiles diferentes.
        $db->statement(<<<SQL
            insert into gei_core.conflictos (
                periodo, tipo, entidad, clave, severidad, detalle, estado, created_at
            )
            select
                ?,
                'PERSONA_DOCUMENTO_CONTRADICTORIO',
                'PERSONA',
                po.persona_id::text,
                'ERROR',
                jsonb_build_object(
                    'persona_id', po.persona_id,
                    'documentos', jsonb_agg(distinct nullif(btrim(f.nro_documento), ''))
                ),
                'PENDIENTE',
                now()
            from gei_core.personas_origenes po
            join tmp_gei_personas_fuente f
              on f.rol = po.rol
             and f.cuenta_cobol = po.cuenta_cobol
             and f.registro_origen_id = po.registro_origen_id
            where po.periodo = ?
              and f.nro_documento is not null
            group by po.persona_id
            having count(distinct f.nro_documento) > 1
        SQL, [$periodo, $periodo]);
    }

    private function inventariarFuentesPeriodo(
        Connection $db,
        string $schema,
        string $periodo
    ): array {
        return array_map(
            static fn ($r): array => [
                'archivo_id' => (int) $r->archivo_id,
                'tabla' => (string) $r->procesado_en_tabla,
            ],
            $db->select(<<<SQL
                select distinct mpa.archivo_id, mpa.procesado_en_tabla
                from {$schema}.migraciones_periodos mp
                join {$schema}.migraciones_periodos_archivos mpa
                  on mpa.migracion_id = mp.id
                where mp.periodo = ?
                  and mp.estado = 'OK'
                  and mpa.grupo = 'cobol'
                order by mpa.procesado_en_tabla, mpa.archivo_id
            SQL, [$periodo])
        );
    }

    private function detectarFuentesCuentaCorriente(
        Connection $db,
        string $schema,
        array $fuentes
    ): array {
        $resultado = [];

        foreach ($fuentes as $fuente) {
            $tabla = $fuente['tabla'];
            if (! preg_match('/cta|ctacte|cuenta/i', $tabla)) {
                continue;
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tabla) !== 1) {
                continue;
            }

            $existe = $db->selectOne(
                "select to_regclass(?) as tabla",
                [$schema.'.'.$tabla]
            )->tabla ?? null;

            if ($existe === null) {
                continue;
            }

            $columnas = array_map(
                static fn ($r): string => (string) $r->column_name,
                $db->select(
                    "select column_name
                       from information_schema.columns
                      where table_schema = ?
                        and table_name = ?
                      order by ordinal_position",
                    [$schema, $tabla]
                )
            );

            $resultado[] = [
                'archivo_id' => $fuente['archivo_id'],
                'tabla' => $tabla,
                'columnas' => $columnas,
            ];
        }

        return $resultado;
    }

    private function inicializarEstructura(Connection $db): void
    {
        $db->statement('create schema if not exists gei_core');

        $sentencias = [];

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.periodos (
                periodo char(6) primary key,
                estado text not null,
                fecha_ultima_liquidacion char(8),
                archivo_propietar_id bigint,
                archivo_inquilino_id bigint,
                error text,
                iniciado_at timestamptz,
                finalizado_at timestamptz,
                actualizado_at timestamptz not null default now(),
                check (periodo ~ '^(19|20)[0-9]{2}(0[1-9]|1[0-2])$')
            )
        SQL;

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.personas (
                id bigserial primary key,
                clave_inicial text not null,
                fingerprint_actual text not null,
                nombre text,
                domicilio text,
                cp text,
                localidad text,
                provincia text,
                tipo_documento text,
                nro_documento text,
                tipo_iva text,
                nro_iva text,
                telefono_1 text,
                telefono_2 text,
                fusionada_en_id bigint references gei_core.personas(id),
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                unique (clave_inicial)
            )
        SQL;

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.personas_cuentas_cobol (
                id bigserial primary key,
                persona_id bigint not null references gei_core.personas(id),
                rol text not null check (rol in ('PROPIETARIO','INQUILINO','GARANTE')),
                cuenta_cobol text not null,
                primer_periodo char(6) not null,
                ultimo_periodo char(6) not null,
                activa boolean not null default false,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                unique (rol, cuenta_cobol)
            )
        SQL;

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.personas_roles (
                persona_id bigint not null references gei_core.personas(id),
                rol text not null check (rol in ('PROPIETARIO','INQUILINO','GARANTE')),
                primer_periodo char(6) not null,
                ultimo_periodo char(6) not null,
                activo boolean not null default false,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                primary key (persona_id, rol)
            )
        SQL;

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.personas_periodos (
                persona_id bigint not null references gei_core.personas(id),
                periodo char(6) not null references gei_core.periodos(periodo),
                rol text not null,
                activo boolean not null default false,
                cantidad_origenes integer not null,
                created_at timestamptz not null default now(),
                primary key (persona_id, periodo, rol)
            )
        SQL;

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.personas_origenes (
                id bigserial primary key,
                persona_id bigint not null references gei_core.personas(id),
                periodo char(6) not null references gei_core.periodos(periodo),
                rol text not null,
                cuenta_cobol text not null,
                archivo_id bigint not null,
                registro_origen_id bigint not null,
                numero_linea bigint,
                fingerprint text not null,
                activo_en_periodo boolean not null,
                sha256_registro text,
                created_at timestamptz not null default now(),
                unique (periodo, rol, archivo_id, registro_origen_id)
            )
        SQL;

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.inmuebles (
                id bigserial primary key,
                clave_identidad text not null unique,
                domicilio_actual text,
                primer_periodo char(6) not null,
                ultimo_periodo char(6) not null,
                activo boolean not null default false,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now()
            )
        SQL;

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.inmuebles_partidas (
                id bigserial primary key,
                inmueble_id bigint not null references gei_core.inmuebles(id),
                partida text not null,
                primer_periodo char(6) not null,
                ultimo_periodo char(6) not null,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                unique (inmueble_id, partida)
            )
        SQL;

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.inmuebles_periodos (
                inmueble_id bigint not null references gei_core.inmuebles(id),
                periodo char(6) not null references gei_core.periodos(periodo),
                activo boolean not null default false,
                cantidad_contratos integer not null,
                created_at timestamptz not null default now(),
                primary key (inmueble_id, periodo)
            )
        SQL;

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.inmuebles_origenes (
                id bigserial primary key,
                inmueble_id bigint not null references gei_core.inmuebles(id),
                periodo char(6) not null references gei_core.periodos(periodo),
                archivo_id bigint not null,
                registro_origen_id bigint not null,
                numero_linea bigint,
                cuenta_inquilino_cobol text,
                cuenta_propietario_cobol text,
                direccion_original text,
                activo_en_periodo boolean not null,
                sha256_registro text,
                created_at timestamptz not null default now(),
                unique (periodo, archivo_id, registro_origen_id)
            )
        SQL;

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.contratos (
                id bigserial primary key,
                cuenta_inquilino_cobol text not null unique,
                inmueble_id bigint not null references gei_core.inmuebles(id),
                cuenta_propietario_cobol text,
                fecha_contrato_original text,
                fecha_vencimiento_original text,
                fecha_inicio_original text,
                marca_baja text,
                fecha_baja_original text,
                plazo_original text,
                indice_original text,
                tipo_ajuste_original text,
                cuota_1_original text,
                cuota_2_original text,
                alquiler_inicial_original text,
                destino_original text,
                administracion_responsable text,
                primer_periodo char(6) not null,
                ultimo_periodo char(6) not null,
                activo boolean not null default false,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now()
            )
        SQL;

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.contratos_periodos (
                contrato_id bigint not null references gei_core.contratos(id),
                periodo char(6) not null references gei_core.periodos(periodo),
                inmueble_id bigint not null references gei_core.inmuebles(id),
                persona_inquilino_id bigint references gei_core.personas(id),
                persona_propietario_id bigint references gei_core.personas(id),
                cuenta_inquilino_cobol text not null,
                cuenta_propietario_cobol text,
                activo boolean not null,
                marca_baja text,
                fecha_contrato_original text,
                fecha_vencimiento_original text,
                fecha_primer_ajuste_original text,
                fecha_inicio_locacion_original text,
                fecha_celebracion_original text,
                fecha_baja_original text,
                nro_liquidacion_original text,
                marca_intimacion_original text,
                plazo_meses integer,
                plazo_dias integer,
                indice text,
                tipo_ajuste text,
                cuota_1 numeric(18,2),
                cuota_2 numeric(18,2),
                alquiler_inicial numeric(18,2),
                cuota_2_dolar numeric(18,2),
                destino text,
                administracion_responsable text,
                penal_porcentaje numeric(10,3),
                penal_importe numeric(18,2),
                comision_anterior text,
                comision_importe numeric(18,2),
                reparacion text,
                dias_reparacion integer,
                acumulado_penalidad numeric(18,2),
                fecha_juicio_original text,
                abogado text,
                ajuste_1_fecha text, ajuste_1_porcentaje numeric(10,1),
                ajuste_2_fecha text, ajuste_2_porcentaje numeric(10,1),
                ajuste_3_fecha text, ajuste_3_porcentaje numeric(10,1),
                ajuste_4_fecha text, ajuste_4_porcentaje numeric(10,1),
                ajuste_5_fecha text, ajuste_5_porcentaje numeric(10,1),
                ajuste_6_fecha text, ajuste_6_porcentaje numeric(10,1),
                ajuste_7_fecha text, ajuste_7_porcentaje numeric(10,1),
                ajuste_8_fecha text, ajuste_8_porcentaje numeric(10,1),
                created_at timestamptz not null default now(),
                primary key (contrato_id, periodo)
            )
        SQL;

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.contratos_origenes (
                id bigserial primary key,
                contrato_id bigint not null references gei_core.contratos(id),
                periodo char(6) not null references gei_core.periodos(periodo),
                archivo_id bigint not null,
                registro_origen_id bigint not null,
                numero_linea bigint,
                sha256_registro text,
                created_at timestamptz not null default now(),
                unique (periodo, archivo_id, registro_origen_id)
            )
        SQL;

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.cuentas_corrientes (
                id bigserial primary key,
                tipo text not null check (tipo in ('PROPIETARIO','INQUILINO')),
                cuenta_cobol text not null,
                persona_id bigint references gei_core.personas(id),
                contrato_id bigint references gei_core.contratos(id),
                primer_periodo char(6) not null,
                ultimo_periodo char(6) not null,
                activa boolean not null default false,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                unique (tipo, cuenta_cobol)
            )
        SQL;

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.cuentas_corrientes_movimientos (
                id bigserial primary key,
                cuenta_corriente_id bigint not null references gei_core.cuentas_corrientes(id),
                primer_periodo char(6) not null references gei_core.periodos(periodo),
                ultimo_periodo char(6) not null references gei_core.periodos(periodo),
                periodo_origen char(6) not null references gei_core.periodos(periodo),
                fecha_original text not null,
                codigo text not null,
                numero text not null,
                fecha_vencimiento_original text,
                importe numeric(18,2),
                importe_penal numeric(18,2),
                importe_abonado numeric(18,2),
                descripcion text,
                cuenta_inquilino_relacionada text,
                liquidado text,
                iva numeric(18,2),
                no_iva numeric(18,2),
                archivo_id bigint not null,
                registro_origen_id bigint not null,
                numero_linea bigint,
                sha256_registro text,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                unique (
                    cuenta_corriente_id,
                    fecha_original,
                    codigo,
                    numero
                )
            )
        SQL;

        $sentencias[] = <<<'SQL'
            create table if not exists gei_core.conflictos (
                id bigserial primary key,
                periodo char(6) not null references gei_core.periodos(periodo),
                tipo text not null,
                entidad text not null,
                clave text,
                severidad text not null,
                detalle jsonb,
                estado text not null default 'PENDIENTE',
                created_at timestamptz not null default now(),
                resolved_at timestamptz
            )
        SQL;

        foreach ($sentencias as $sql) {
            $db->statement($sql);
        }

        // Evolución del snapshot contractual: conserva todos los datos operativos
        // del INQUILINO por período, sin duplicar el contrato maestro.
        $columnasContratoPeriodo = [
            'fecha_contrato_original text',
            'fecha_vencimiento_original text',
            'fecha_primer_ajuste_original text',
            'fecha_inicio_locacion_original text',
            'fecha_celebracion_original text',
            'fecha_baja_original text',
            'nro_liquidacion_original text',
            'marca_intimacion_original text',
            'plazo_meses integer',
            'plazo_dias integer',
            'indice text',
            'tipo_ajuste text',
            'cuota_1 numeric(18,2)',
            'cuota_2 numeric(18,2)',
            'alquiler_inicial numeric(18,2)',
            'cuota_2_dolar numeric(18,2)',
            'destino text',
            'administracion_responsable text',
            'penal_porcentaje numeric(10,3)',
            'penal_importe numeric(18,2)',
            'comision_anterior text',
            'comision_importe numeric(18,2)',
            'reparacion text',
            'dias_reparacion integer',
            'acumulado_penalidad numeric(18,2)',
            'fecha_juicio_original text',
            'abogado text',
            'ajuste_1_fecha text', 'ajuste_1_porcentaje numeric(10,1)',
            'ajuste_2_fecha text', 'ajuste_2_porcentaje numeric(10,1)',
            'ajuste_3_fecha text', 'ajuste_3_porcentaje numeric(10,1)',
            'ajuste_4_fecha text', 'ajuste_4_porcentaje numeric(10,1)',
            'ajuste_5_fecha text', 'ajuste_5_porcentaje numeric(10,1)',
            'ajuste_6_fecha text', 'ajuste_6_porcentaje numeric(10,1)',
            'ajuste_7_fecha text', 'ajuste_7_porcentaje numeric(10,1)',
            'ajuste_8_fecha text', 'ajuste_8_porcentaje numeric(10,1)',
        ];

        foreach ($columnasContratoPeriodo as $definicion) {
            $db->statement(
                'alter table gei_core.contratos_periodos add column if not exists '.$definicion
            );
        }

        // Compatibilidad con una instalación v1 donde la tabla de movimientos
        // ya fue creada pero todavía estaba vacía.
        $db->statement('alter table gei_core.cuentas_corrientes_movimientos add column if not exists primer_periodo char(6)');
        $db->statement('alter table gei_core.cuentas_corrientes_movimientos add column if not exists ultimo_periodo char(6)');
        $db->statement('alter table gei_core.cuentas_corrientes_movimientos add column if not exists updated_at timestamptz not null default now()');
        $db->statement(
            'create unique index if not exists gei_core_movimiento_clave_cobol_uq
             on gei_core.cuentas_corrientes_movimientos
             (cuenta_corriente_id, fecha_original, codigo, numero)'
        );

        $db->statement('create index if not exists gei_core_personas_fingerprint_idx on gei_core.personas (fingerprint_actual)');
        $db->statement('create index if not exists gei_core_personas_nombre_idx on gei_core.personas (nombre)');
        $db->statement('create index if not exists gei_core_personas_iva_idx on gei_core.personas (nro_iva)');
        $db->statement('create index if not exists gei_core_personas_documento_idx on gei_core.personas (nro_documento)');
        $db->statement('create index if not exists gei_core_cuentas_persona_idx on gei_core.personas_cuentas_cobol (persona_id)');
        $db->statement('create index if not exists gei_core_inmuebles_domicilio_idx on gei_core.inmuebles (domicilio_actual)');
        $db->statement('create index if not exists gei_core_contratos_prop_idx on gei_core.contratos (cuenta_propietario_cobol)');
        $db->statement('create index if not exists gei_core_movimientos_periodo_idx on gei_core.cuentas_corrientes_movimientos (periodo_origen)');
        $db->statement('create index if not exists gei_core_conflictos_periodo_idx on gei_core.conflictos (periodo, estado)');
    }

    private function archivoDelPeriodo(
        Connection $db,
        string $periodo,
        string $tabla
    ): int {
        $schema = $this->schemaOrigen();

        $fila = $db->selectOne(<<<SQL
            select mpa.archivo_id
            from {$schema}.migraciones_periodos mp
            join {$schema}.migraciones_periodos_archivos mpa
              on mpa.migracion_id = mp.id
            where mp.periodo = ?
              and mp.estado = 'OK'
              and mpa.grupo = 'cobol'
              and mpa.procesado_en_tabla = ?
            order by mp.iniciada_en desc, mp.id desc
            limit 1
        SQL, [$periodo, $tabla]);

        $archivoId = (int) ($fila->archivo_id ?? 0);

        if ($archivoId <= 0) {
            throw new RuntimeException(
                "No se encontró {$tabla} migrado correctamente para {$periodo}."
            );
        }

        return $archivoId;
    }

    private function fechaUltimaLiquidacion(
        Connection $db,
        string $schema,
        int $archivoPropietar
    ): string {
        $valor = $db->selectOne(
            "select max(nullif(btrim(fecha_ultima_liquidacion::text), '')) as fecha
               from {$schema}.propietar
              where archivo_id = ?
                and nullif(btrim(fecha_ultima_liquidacion::text), '') is not null
                and btrim(fecha_ultima_liquidacion::text) <> '00000000'",
            [$archivoPropietar]
        )->fecha ?? null;

        if ($valor === null || preg_match('/^\d{8}$/', (string) $valor) !== 1) {
            throw new RuntimeException(
                'No se pudo determinar automáticamente la última fecha de liquidación de PROPIETAR.'
            );
        }

        return (string) $valor;
    }

    private function sqlNormalizar(string $expresion): string
    {
        return "btrim(regexp_replace(regexp_replace(upper(coalesce({$expresion}::text, '')), '[[:punct:]]+', ' ', 'g'), '\\s+', ' ', 'g'))";
    }

    private function sqlNormalizarDomicilio(string $expresion): string
    {
        $normal = $this->sqlNormalizar($expresion);

        return "btrim(regexp_replace(".
            "regexp_replace(".
            "regexp_replace({$normal}, '\\m(DEPARTAMENTO|DEPTO|DTO)\\M', ' DTO ', 'g'), ".
            "'\\m(PISO)\\M', ' P ', 'g'), ".
            "'\\s+', ' ', 'g'))";
    }

    private function sqlNumeroV99SinSigno(string $expresion): string
    {
        return "case
            when nullif(btrim({$expresion}::text), '') is null then null
            when btrim({$expresion}::text) ~ '^[0-9]+$'
                then btrim({$expresion}::text)::numeric / 100
            when replace(btrim({$expresion}::text), ',', '.') ~ '^[0-9]+\\.[0-9]+$'
                then replace(btrim({$expresion}::text), ',', '.')::numeric
            else null
        end";
    }

    private function sqlNumeroV1ConSigno(string $expresion): string
    {
        return "case
            when nullif(btrim({$expresion}::text), '') is null then null
            when btrim({$expresion}::text) ~ '^[0-9]+[+-]$'
                then (
                    left(btrim({$expresion}::text), length(btrim({$expresion}::text)) - 1)::numeric / 10
                ) * case when right(btrim({$expresion}::text),1) = '-' then -1 else 1 end
            when btrim({$expresion}::text) ~ '^[+-]?[0-9]+$'
                then btrim({$expresion}::text)::numeric / 10
            else null
        end";
    }

    private function sqlNumero(string $expresion): string
    {
        /*
         * CTACTEPRO / INQCTACTE guardan importes COBOL como
         * PIC S9(9)V99 TRAILING SEPARATE.
         *
         * Ejemplos:
         *   00000123456+ =>  1234.56
         *   00000123456- => -1234.56
         *
         * V99 implica dos decimales aunque el texto no contenga separador.
         */
        return "case
            when nullif(btrim({$expresion}::text), '') is null then null

            -- Valor ya convertido con separador decimal explícito.
            when replace(btrim({$expresion}::text), ',', '.') ~ '^[+-]?[0-9]+\\.[0-9]+$'
                then replace(btrim({$expresion}::text), ',', '.')::numeric

            -- SIGN TRAILING SEPARATE: dígitos seguidos de + o -.
            when btrim({$expresion}::text) ~ '^[0-9]+[+-]$'
                then (
                    left(
                        btrim({$expresion}::text),
                        length(btrim({$expresion}::text)) - 1
                    )::numeric / 100
                ) * case
                    when right(btrim({$expresion}::text), 1) = '-' then -1
                    else 1
                end

            -- Compatibilidad con signo al comienzo y decimal implícito.
            when btrim({$expresion}::text) ~ '^[+-][0-9]+$'
                then (
                    substring(btrim({$expresion}::text) from 2)::numeric / 100
                ) * case
                    when left(btrim({$expresion}::text), 1) = '-' then -1
                    else 1
                end

            -- Tira de dígitos de un PIC ...V99 sin signo visible.
            when btrim({$expresion}::text) ~ '^[0-9]+$'
                then btrim({$expresion}::text)::numeric / 100

            else null
        end";
    }

    private function guardarProgreso(
        string $periodo,
        string $etapa,
        string $detalle,
        int $porcentaje,
        ?int $procesados = null,
        ?int $total = null,
        string $estado = 'PROCESANDO',
        ?string $archivo = null
    ): void {
        $ruta = "liquidaciones/periodos/{$periodo}/".self::ARCHIVO_PROGRESO;
        $actual = [];

        if (Storage::exists($ruta)) {
            try {
                $leido = json_decode(Storage::get($ruta), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($leido)) {
                    $actual = $leido;
                }
            } catch (\Throwable) {
                $actual = [];
            }
        }

        Storage::put(
            $ruta,
            json_encode(
                array_merge($actual, [
                    'periodo' => $periodo,
                    'estado' => $estado,
                    'etapa' => $etapa,
                    'detalle' => $detalle,
                    'porcentaje' => $porcentaje,
                    'archivo' => $archivo,
                    'procesados' => $procesados,
                    'total' => $total,
                    'actualizado_at' => now()->toIso8601String(),
                ]),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ).PHP_EOL
        );
    }

    private function validarPeriodo(string $periodo): void
    {
        if (preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            throw new RuntimeException('El período debe tener formato AAAAMM.');
        }
    }

    private function schemaOrigen(): string
    {
        $schema = (string) config('gei.exploracion.schema', 'cobol_staging');

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema) !== 1) {
            throw new RuntimeException(
                'GEI_EXPLORACION_SCHEMA no es válido.'
            );
        }

        return $schema;
    }

    private function conexionExploracion(): Connection
    {
        $base = config('database.connections.pgsql');

        if (! is_array($base)) {
            throw new RuntimeException(
                'No existe la conexión PostgreSQL base.'
            );
        }

        $base['host'] = config('gei.exploracion.host');
        $base['port'] = config('gei.exploracion.port');
        $base['database'] = config('gei.exploracion.database');
        $base['username'] = config('gei.exploracion.username');
        $base['password'] = config('gei.exploracion.password');
        $base['search_path'] = 'public';

        config(['database.connections.gei_exploracion' => $base]);
        DB::purge('gei_exploracion');

        return DB::connection('gei_exploracion');
    }
}
