<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Histórico COBOL por cuenta exacta y rol, sin identidades reconstruidas.
 * El controlador debe comprobar previamente cliente canónico y alcance de sede.
 * Fuente: gei_exploracion.cobol_staging; sólo lectura.
 */
final class HistoricoCobolCuentaService
{
    /**
     * @return Collection<int, object>
     */
    public function consultar(string $rol, string $cuenta): Collection
    {
        if (! in_array($rol, ['INQUILINO', 'PROPIETARIO'], true)
            || preg_match('/^[0-9]{11}$/D', $cuenta) !== 1) {
            return collect();
        }

        return $this->consultarDesde($this->exploracion(), $rol, $cuenta);
    }

    /**
     * Entrada con conexión explícita para facilitar pruebas de integración.
     * Debe invocarse sólo después de autorizar rol + cuenta + cliente + sede.
     *
     * @return Collection<int, object>
     */
    public function consultarDesde(Connection $db, string $rol, string $cuenta): Collection
    {
        if (! in_array($rol, ['INQUILINO', 'PROPIETARIO'], true)
            || preg_match('/^[0-9]{11}$/D', $cuenta) !== 1) {
            return collect();
        }

        $propietario = $rol === 'PROPIETARIO';
        $tabla = $propietario ? 'propietar' : 'inquilino';
        $campoCuenta = $propietario ? 'nro_cta_prop' : 'cta_inquilino';
        $campoNombre = $propietario ? 'nombre_prop' : 'nombre_inquilino';
        $campoDomicilio = $propietario ? 'domicilio_prop' : 'domicilio_legal';

        // Una sola versión por período, de la última importación COBOL OK.
        // La misma regla de selección de archivo que usa la evidencia mensual.
        $archivos = $this->archivosPorPeriodo($db, $tabla);

        $q = $db->table("cobol_staging.{$tabla} as original")
            ->joinSub($archivos, 'elegido', function ($join): void {
                $join->on('elegido.archivo_id', '=', 'original.archivo_id');
            })
            ->whereRaw(
                "regexp_replace(coalesce(original.{$campoCuenta}, ''), '[^0-9]', '', 'g') = ?",
                [$cuenta]
            )
            ->select([
                'elegido.periodo', 'original.archivo_id', 'original.numero_linea',
            ])
            ->selectRaw("TRIM(original.{$campoNombre}) AS nombre_origen")
            ->selectRaw("TRIM(original.{$campoDomicilio}) AS domicilio_origen")
            ->selectRaw('TRIM(original.nro_iva) AS cuit_origen');

        // La vigencia se deriva de los datos originales de ESE período;
        // nunca del registro actual ni de identidades de otra base.
        if ($propietario) {
            $fechaVigente = $this->fechaUltimaLiquidacion('elegido');
            $q->selectRaw("(TRIM(CAST(original.fecha_ultima_liquidacion AS TEXT)) = {$fechaVigente}) AS activo_en_periodo");
        } else {
            $q->leftJoinSub($this->archivosPorPeriodo($db, 'propietar'), 'archivo_prop', function ($join): void {
                $join->on('archivo_prop.periodo', '=', 'elegido.periodo');
            });
            $fechaVigente = $this->fechaUltimaLiquidacion('archivo_prop');
            $q->selectRaw(<<<SQL
                CASE
                    WHEN archivo_prop.archivo_id IS NULL OR {$fechaVigente} IS NULL THEN NULL
                    ELSE (
                        NULLIF(TRIM(CAST(original.marca_baja AS TEXT)), '') IS NULL
                        AND EXISTS (
                            SELECT 1
                            FROM cobol_staging.propietar p
                            WHERE p.archivo_id = archivo_prop.archivo_id
                              AND TRIM(CAST(p.nro_cta_prop AS TEXT)) = TRIM(CAST(original.cta_propietario AS TEXT))
                              AND TRIM(CAST(p.fecha_ultima_liquidacion AS TEXT)) = {$fechaVigente}
                        )
                    )
                END AS activo_en_periodo
                SQL);
        }

        return $q->orderByDesc('elegido.periodo')
            ->orderByDesc('original.numero_linea')
            ->orderByDesc('original.id')
            ->get();
    }

    private function archivosPorPeriodo(Connection $db, string $tabla): \Illuminate\Database\Query\Builder
    {
        $ordenados = $db->table('cobol_staging.migraciones_periodos_archivos as pa')
            ->join('cobol_staging.migraciones_periodos as mp', 'mp.id', '=', 'pa.migracion_id')
            ->where('mp.estado', 'OK')
            ->where('pa.grupo', 'cobol')
            ->where('pa.procesado_en_tabla', $tabla)
            ->selectRaw('mp.periodo, pa.archivo_id, ROW_NUMBER() OVER (PARTITION BY mp.periodo ORDER BY mp.iniciada_en DESC, mp.id DESC) AS ordinal');

        return $db->query()->fromSub($ordenados, 'candidatos')
            ->where('candidatos.ordinal', 1)
            ->select('candidatos.periodo', 'candidatos.archivo_id');
    }

    private function fechaUltimaLiquidacion(string $alias): string
    {
        // Alias elegido internamente; ningún identificador procede del usuario.
        return "(SELECT MAX(NULLIF(TRIM(CAST(p2.fecha_ultima_liquidacion AS TEXT)), ''))
                   FROM cobol_staging.propietar p2
                  WHERE p2.archivo_id = {$alias}.archivo_id
                    AND TRIM(CAST(p2.fecha_ultima_liquidacion AS TEXT)) <> '00000000')";
    }

    private function exploracion(): Connection
    {
        $base = config('database.connections.pgsql');
        if (! is_array($base)) {
            throw new RuntimeException('No existe la conexión PostgreSQL base.');
        }
        foreach (['host', 'port', 'database', 'username', 'password'] as $campo) {
            $valor = config('gei.exploracion.'.$campo);
            if ($valor === null || $valor === '') {
                throw new RuntimeException("Falta gei.exploracion.{$campo} en la configuración.");
            }
            $base[$campo] = $valor;
        }
        $base['search_path'] = 'public';
        config(['database.connections.gei_exploracion' => $base]);
        DB::purge('gei_exploracion');

        return DB::connection('gei_exploracion');
    }
}
