<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Evidencia de actividad de cuentas COBOL para un período.
 *
 * Fuente única: gei_exploracion.cobol_staging (archivos originales).
 * la vinculación con clientes canónicos se resuelve exclusivamente con
 * gei_db.public.clientes_cuentas en ActividadConsolidadaClientesService.
 *
 * Las reglas de vigencia reproducen la clasificación de la reconstrucción
 * histórica: propietarios con fecha de última liquidación vigente e inquilinos
 * sin marca de baja vinculados a un propietario vigente del mismo snapshot.
 */
final class EvidenciaActividadCobolService
{
    /**
     * @return array{periodo:string,archivo_propietar_id:int,archivo_inquilino_id:int,fecha_ultima_liquidacion:string,cuentas:list<array{rol:string,cuenta:string}>}
     */
    public function consultarPeriodo(string $periodo): array
    {
        $this->validarPeriodo($periodo);

        return $this->consultarPeriodoDesde($periodo, $this->exploracion());
    }

    /**
     * Punto de entrada con conexión explícita para probar contra un snapshot
     * controlado sin cambiar ninguna configuración ni tocar PostgreSQL.
     * @return array{periodo:string,archivo_propietar_id:int,archivo_inquilino_id:int,fecha_ultima_liquidacion:string,cuentas:list<array{rol:string,cuenta:string}>}
     */
    public function consultarPeriodoDesde(string $periodo, Connection $db): array
    {
        $this->validarPeriodo($periodo);
        $propietarId = $this->archivoPeriodo($db, $periodo, 'propietar');
        $inquilinoId = $this->archivoPeriodo($db, $periodo, 'inquilino');

        // No declarar clientes inactivos cuando hay una importación vacía o
        // incompleta. La ausencia de observaciones NO prueba una baja.
        foreach (['propietar' => $propietarId, 'inquilino' => $inquilinoId] as $tabla => $archivoId) {
            $fila = $db->selectOne("SELECT count(*) AS total FROM cobol_staging.{$tabla} WHERE archivo_id = ?", [$archivoId]);
            if ((int) ($fila->total ?? 0) === 0) {
                throw new RuntimeException("No hay registros en COBOL {$tabla} para {$periodo} (archivo {$archivoId}).");
            }
        }

        // Misma fecha y criterio que la reconstrucción COBOL existente, sin
        $fecha = $db->selectOne(
            "SELECT max(nullif(btrim(fecha_ultima_liquidacion::text), '')) AS fecha
               FROM cobol_staging.propietar
              WHERE archivo_id = ?
                AND nullif(btrim(fecha_ultima_liquidacion::text), '') IS NOT NULL
                AND btrim(fecha_ultima_liquidacion::text) <> '00000000'",
            [$propietarId]
        )->fecha ?? null;

        if (! is_string($fecha) || preg_match('/^\d{8}$/D', $fecha) !== 1) {
            throw new RuntimeException("Fecha de última liquidación COBOL inválida para {$periodo}.");
        }

        // Cuenta y rol provienen EXCLUSIVAMENTE de los archivos originales.
        // Se usan los mismos predicados de actividad que la reconstrucción,
        $sql = <<<'SQL'
            WITH propietarios_vigentes AS MATERIALIZED (
                SELECT DISTINCT btrim(p.nro_cta_prop::text) AS cuenta
                  FROM cobol_staging.propietar p
                 WHERE p.archivo_id = ?
                   AND nullif(btrim(p.nro_cta_prop::text), '') IS NOT NULL
                   AND btrim(p.fecha_ultima_liquidacion::text) = ?
            )
            SELECT 'PROPIETARIO'::text AS rol, p.cuenta
              FROM propietarios_vigentes p
            UNION ALL
            SELECT 'INQUILINO'::text AS rol, btrim(i.cta_inquilino::text) AS cuenta
              FROM cobol_staging.inquilino i
             WHERE i.archivo_id = ?
               AND nullif(btrim(i.cta_inquilino::text), '') IS NOT NULL
               AND nullif(btrim(i.marca_baja::text), '') IS NULL
               AND EXISTS (
                   SELECT 1
                     FROM propietarios_vigentes p
                    WHERE p.cuenta = btrim(i.cta_propietario::text)
               )
            SQL;

        $unicas = [];
        foreach ($db->cursor($sql, [$propietarId, $fecha, $inquilinoId]) as $fila) {
            $rol = (string) $fila->rol;
            $cuenta = preg_replace('/\D+/', '', (string) $fila->cuenta) ?? '';
            if ($cuenta === '') {
                throw new RuntimeException("Cuenta COBOL sin dígitos en {$periodo}: no inferir bajas.");
            }
            // Distintas filas del mismo archivo pueden referirse a la misma
            // cuenta. Su presencia se contabiliza sólo una vez por rol.
            $unicas[$rol.'|'.$cuenta] = ['rol' => $rol, 'cuenta' => $cuenta];
        }

        if ($unicas === []) {
            throw new RuntimeException("No hay cuentas activas COBOL para {$periodo}; se evita una baja masiva.");
        }

        return [
            'periodo' => $periodo,
            'archivo_propietar_id' => $propietarId,
            'archivo_inquilino_id' => $inquilinoId,
            'fecha_ultima_liquidacion' => $fecha,
            'cuentas' => array_values($unicas),
        ];
    }

    private function validarPeriodo(string $periodo): void
    {
        if (! preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/D', $periodo)) {
            throw new RuntimeException('Período COBOL inválido: utilizar AAAAMM.');
        }
    }

    private function archivoPeriodo(Connection $db, string $periodo, string $tabla): int
    {
        // Archivo de la última migración completa y exitosa de ESE período,
        // no el máximo archivo_id (que podría corresponder a otro período).
        $fila = $db->selectOne(
            <<<'SQL'
            SELECT mpa.archivo_id
              FROM cobol_staging.migraciones_periodos mp
              JOIN cobol_staging.migraciones_periodos_archivos mpa
                ON mpa.migracion_id = mp.id
             WHERE mp.periodo = ?
               AND mp.estado = 'OK'
               AND mpa.grupo = 'cobol'
               AND mpa.procesado_en_tabla = ?
             ORDER BY mp.iniciada_en DESC, mp.id DESC
             LIMIT 1
            SQL,
            [$periodo, $tabla]
        );

        $archivoId = (int) ($fila->archivo_id ?? 0);
        if ($archivoId <= 0) {
            throw new RuntimeException("Falta {$tabla} COBOL importado correctamente para {$periodo}.");
        }

        return $archivoId;
    }

    private function exploracion(): Connection
    {
        $base = config('database.connections.pgsql');
        if (! is_array($base)) {
            throw new RuntimeException('No existe la conexión PostgreSQL principal.');
        }
        foreach (['host', 'port', 'database', 'username', 'password'] as $campo) {
            $valor = config('gei.exploracion.'.$campo);
            if ($valor === null || $valor === '') {
                throw new RuntimeException("Falta gei.exploracion.{$campo} en la configuración del entorno.");
            }
            $base[$campo] = $valor;
        }
        $base['search_path'] = 'public';
        config(['database.connections.gei_exploracion' => $base]);
        DB::purge('gei_exploracion');

        return DB::connection('gei_exploracion');
    }
}
