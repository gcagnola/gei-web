<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Comprueba la secuencia de alta inicial + actualizaciones por período.
 * Solo SELECT sobre gei_exploracion.cobol_staging y gei_db.public.
 */
final class AuditoriaIncrementalInmueblesService
{
    public function __construct(
        private readonly IdentidadFisicaInmuebleService $identidad,
        private readonly InmuebleCobolNormalizer $normalizer,
    ) {
    }

    /** @return array<string, mixed> */
    public function ejecutar(string $desde, string $hasta): array
    {
        if (! preg_match('/^20\d{2}(0[1-9]|1[0-2])$/', $desde)
            || ! preg_match('/^20\d{2}(0[1-9]|1[0-2])$/', $hasta)
            || $desde > $hasta
        ) {
            throw new RuntimeException('Rango de períodos inválido.');
        }

        $origen = $this->conexionExploracion();
        $schema = (string) config('gei.exploracion.schema', 'cobol_staging');
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $schema)) {
            throw new RuntimeException('Esquema de exploración inválido.');
        }

        $fuentes = $origen->table($schema.'.migraciones_periodos as p')
            ->join($schema.'.migraciones_periodos_archivos as pa', 'pa.migracion_id', '=', 'p.id')
            ->join($schema.'.archivos_importados as a', 'a.id', '=', 'pa.archivo_id')
            ->where('p.estado', 'OK')
            ->whereBetween('p.periodo', [$desde, $hasta])
            ->where(function ($q): void {
                $q->whereRaw('LOWER(pa.procesado_en_tabla) IN (?, ?)', ['inquilino', 'cobol_staging.inquilino'])
                    ->orWhereRaw("UPPER(a.nombre_archivo) LIKE 'INQUILINO%'");
            })
            ->select('p.periodo', 'p.id as migracion_id', 'pa.archivo_id', 'p.finalizada_en')
            ->orderBy('p.periodo')->orderByDesc('p.finalizada_en')->orderByDesc('p.id')->get();

        $archivos = [];
        foreach ($fuentes as $fila) {
            $periodo = trim((string) $fila->periodo);
            $archivos[$periodo] ??= (int) $fila->archivo_id;
        }
        if ($archivos === []) {
            throw new RuntimeException('No se encontraron archivos INQUILINO para los períodos indicados.');
        }
        ksort($archivos, SORT_STRING);

        $resumenes = [];
        foreach ($archivos as $periodo => $archivoId) {
            $filas = [];
            foreach ($origen->table($schema.'.inquilino')
                ->where('archivo_id', $archivoId)
                ->orderBy('numero_linea')->cursor() as $fila
            ) {
                $filas[] = $this->normalizer->inquilino($fila);
            }
            $resultado = $this->identidad->incorporarPeriodo($periodo, $filas);
            $resultado['archivo_id'] = $archivoId;
            $resumenes[] = $resultado;
        }

        $unidades = $this->identidad->unidades();
        $cuentasVariantes = 0;
        foreach ($unidades as $unidad) {
            if (count($unidad['cuentas_propietarios']) > 1) {
                $cuentasVariantes++;
            }
        }

        return [
            'modo' => 'SOLO_LECTURA',
            'origen' => 'gei_exploracion.cobol_staging',
            'maestro' => 'gei_db.public.inmuebles',
            'periodos' => $resumenes,
            'unidades_fisicas_en_plan' => count($unidades),
            'unidades_con_multiples_cuentas_propietarias' => $cuentasVariantes,
            'inmuebles_canonicos_actuales' => DB::table('inmuebles')->whereNull('id_inmueble_canonico')->count(),
            'nota' => 'Comparación informativa; NO reconstruye ni fusiona inmuebles. No se garantiza unicidad física cuando el domicilio no distingue unidades.',
        ];
    }

    private function conexionExploracion(): ConnectionInterface
    {
        $base = config('database.connections.pgsql');
        if (! is_array($base)) {
            throw new RuntimeException('Falta conexión PostgreSQL base.');
        }
        foreach (['host', 'port', 'database', 'username', 'password'] as $campo) {
            $base[$campo] = config('gei.exploracion.'.$campo);
        }
        $base['search_path'] = 'public';
        config(['database.connections.gei_exploracion' => $base]);
        DB::purge('gei_exploracion');

        return DB::connection('gei_exploracion');
    }
}
