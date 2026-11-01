<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Una sola clasificación por cliente canónico y período.
 * ACTIVO = COBOL || ARCA || LIQUIDACIONES || IMPUESTOS.
 * El resultado consolidado se calcula; NO se almacena en clientes_actividad_periodos.
 *
 * La actividad COBOL se lee directamente de cobol_staging por cuenta y rol;
 * Tampoco asigna cuentas a clientes por semejanza de nombres.
 */
final class ActividadConsolidadaClientesService
{
    public function __construct(
        private readonly ActividadArcaKngService $arcaKng,
        private readonly ImpuestosGarantizadosPdfService $impuestos,
        private readonly EvidenciaActividadCobolService $cobolOrigen,
    ) {
    }

    /** @return array<string, mixed> */
    public function analizar(string $periodo): array
    {
        return $this->procesar($periodo, 'analizar');
    }

    /**
     * @param bool $soloAltas true: permite activar sólo positivos indudables aunque existan pendientes.
     * @return array<string, mixed>
     */
    public function sincronizar(string $periodo, bool $soloAltas = false): array
    {
        return $this->procesar($periodo, $soloAltas ? 'solo_altas' : 'completa');
    }

    /** @return array<string, mixed> */
    private function procesar(string $periodo, string $modo): array
    {
        // Esta primera entrega valida únicamente la nueva fuente COBOL.
        // Ningún --aplicar / --solo-altas puede actualizar clientes.activo
        // hasta conciliar los resultados con un snapshot real de Desarrollo.
        if ($modo !== 'analizar') {
            throw new RuntimeException(
                'Sincronización de actividad temporalmente bloqueada: validar primero la nueva fuente COBOL en Desarrollo.'
            );
        }
        if (! preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo)) {
            throw new RuntimeException('Período inválido: utilizar AAAAMM.');
        }
        foreach (['clientes', 'clientes_cuentas', 'liquidaciones_propietarios', 'clientes_actividad_periodos'] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                throw new RuntimeException("Falta tabla {$tabla}.");
            }
        }
        $ultimo = (string) (DB::table('liquidaciones_propietarios')->max('periodo') ?? '');
        if ($ultimo !== $periodo && $modo !== 'analizar') {
            throw new RuntimeException("No se puede modificar clientes.activo para {$periodo}: último período operativo {$ultimo}.");
        }
        // Obtener evidencia de ARCA de KNG sin localizar PDFs y sin confundir
        // ID_INQ (destinatario) con CTA_ORIG (origen operativo).
        $datosArca = $this->arcaKng->consultarPeriodo($periodo);
        // Único origen de actividad COBOL: archivos originales por período.
        $datosCobol = $this->cobolOrigen->consultarPeriodo($periodo);
        if (! DB::table('liquidaciones_propietarios')->where('periodo', $periodo)->exists()) {
            throw new RuntimeException("No existen liquidaciones del período {$periodo}.");
        }

        $clientes = DB::table('clientes')->get(['id', 'id_cliente_canonico', 'activo'])->keyBy('id');
        $canonicos = $clientes->filter(fn (object $c): bool => $c->id_cliente_canonico === null)->keys()->all();
        if ($canonicos === []) {
            throw new RuntimeException('No se encontraron clientes canónicos.');
        }
        $resolver = function (int $id) use ($clientes): ?int {
            $vistos = [];
            while ($id > 0 && $clientes->has($id)) {
                if (isset($vistos[$id])) {
                    return null;
                }
                $vistos[$id] = true;
                $sig = $clientes->get($id)->id_cliente_canonico;
                if ($sig === null) {
                    return $id;
                }
                $id = (int) $sig;
            }
            return null;
        };

        // Se respeta el ROL para COBOL. Para facturas sin rol se usa sólo una
        // correspondencia inequívoca, sin asumir que los IDs coinciden.
        $porCuenta = [];
        $porRolCuenta = [];
        foreach (DB::table('clientes_cuentas')->cursor(['cliente_id', 'cuenta', 'rol']) as $cc) {
            $cuenta = $this->normalizar((string) $cc->cuenta);
            $rol = strtoupper(trim((string) $cc->rol));
            $id = $resolver((int) $cc->cliente_id);
            if ($cuenta === '' || $id === null) {
                continue;
            }
            $porCuenta[$cuenta][$id] = true;
            $porRolCuenta[$rol.'|'.$cuenta][$id] = true;
        }

        $flags = [];
        $pendientes = [];
        $evidencias = ['COBOL' => 0, 'ARCA' => 0, 'LIQUIDACIONES' => 0, 'IMPUESTOS' => 0, 'MOVIMIENTOS' => 0];
        $marcar = function (int $id, string $campo) use (&$flags): void {
            $flags[$id][$campo] = true;
        };
        $registrar = function (string $fuente, string $cuenta, string $motivo) use (&$pendientes): void {
            $pendientes[$fuente.'|'.$cuenta] = compact('fuente', 'cuenta', 'motivo');
        };
        $unico = static function (array $ids): ?int {
            return count($ids) === 1 ? (int) array_key_first($ids) : null;
        };

        // A: actividad desde COBOL original, vinculada al maestro público
        // únicamente por clientes_cuentas (cuenta + rol exactos).
        foreach ($datosCobol['cuentas'] as $origen) {
            $rol = $origen['rol'];
            $cuenta = $origen['cuenta'];
            $evidencias['COBOL']++;
            $ids = $porRolCuenta[$rol.'|'.$cuenta] ?? [];
            if (($id = $unico($ids)) !== null) {
                $marcar($id, 'activo_cobol');
            } else {
                $registrar('COBOL', $rol.'|'.$cuenta, $ids === [] ? 'SIN_CLIENTE' : 'AMBIGUA');
            }
        }

        // B: ARCA. ID_INQ de 11 dígitos identifica al destinatario COBOL;
        // CTA_ORIG de 11 dígitos acredita actividad de la cuenta de origen.
        // Ambas fuentes cuentan como EVIDENCIA, pero CTA_ORIG jamás concede
        // al titular permiso para abrir el PDF del destinatario ID_INQ.
        // La clave corta de KNG NO se convierte en clientes.id.
        foreach ($datosArca['cuentas'] as $cuenta => $fuentes) {
            $evidencias['ARCA']++;
            $ids = $porCuenta[$cuenta] ?? [];
            if (($id = $unico($ids)) !== null) {
                $marcar($id, 'activo_arca');
            } else {
                $fuente = ! empty($fuentes['origen']) ? 'CTA_ORIG' : 'ID_INQ';
                $registrar('ARCA', $cuenta, ($ids === [] ? 'SIN_CLIENTE' : 'AMBIGUA')." ({$fuente})");
            }
        }

        // C: liquidaciones: cliente_id explícito si es canónico y consistente.
        foreach (DB::table('liquidaciones_propietarios')->where('periodo', $periodo)
            ->cursor(['cliente_id', 'cuenta']) as $liq) {
            $evidencias['LIQUIDACIONES']++;
            $cuenta = $this->normalizar((string) $liq->cuenta);
            $ids = $cuenta !== '' ? ($porCuenta[$cuenta] ?? []) : [];
            $directo = $liq->cliente_id === null ? null : $resolver((int) $liq->cliente_id);
            if ($directo !== null && ($ids === [] || isset($ids[$directo]))) {
                $marcar($directo, 'activo_liquidaciones');
            } elseif ($directo === null && ($id = $unico($ids)) !== null) {
                $marcar($id, 'activo_liquidaciones');
            } else {
                $registrar('LIQUIDACIONES', $cuenta ?: '(sin cuenta)', 'IDENTIDAD_NO_UNIVOCA');
            }
        }

        foreach ($this->impuestos->cuentasPeriodo($periodo) as $cuentaOriginal) {
            $cuenta = $this->normalizar((string) $cuentaOriginal);
            if ($cuenta === '') {
                continue;
            }
            $evidencias['IMPUESTOS']++;
            $ids = $porCuenta[$cuenta] ?? [];
            if (($id = $unico($ids)) !== null) {
                $marcar($id, 'activo_impuestos');
            } else {
                $registrar('IMPUESTOS', $cuenta, $ids === [] ? 'SIN_CLIENTE' : 'AMBIGUA');
            }
        }

        // Evidencia de movimientos por cuenta y rol exactos.
        // No implica vigencia contractual ni elimina bajas COBOL.
        foreach (DB::table('cuentas_corrientes_movimientos as m')
            ->join('cuentas_corrientes as cc', 'cc.id', '=', 'm.cuenta_corriente_id')
            ->where('m.periodo', $periodo)
            ->whereIn('cc.dominio', ['PROPIETARIO', 'INQUILINO'])
            ->select(['cc.dominio', 'cc.cuenta'])
            ->distinct()
            ->cursor() as $m) {
            $rol = strtoupper(trim((string) $m->dominio));
            $cuenta = $this->normalizar((string) $m->cuenta);
            if ($cuenta === '') {
                continue;
            }
            $evidencias['MOVIMIENTOS']++;
            $ids = $porRolCuenta[$rol.'|'.$cuenta] ?? [];
            if (($id = $unico($ids)) !== null) {
                $marcar($id, 'activo_movimientos');
            } else {
                $registrar(
                    'MOVIMIENTOS',
                    $rol.'|'.$cuenta,
                    $ids === [] ? 'SIN_CLIENTE' : 'AMBIGUA'
                );
            }
        }

        $resumen = [
            'periodo' => $periodo,
            'ultimo_periodo_operativo' => $ultimo,
            'modo' => $modo,
            'clientes_canonicos' => count($canonicos),
            'evidencias_procesadas' => $evidencias,
            'cobol_origen' => array_diff_key($datosCobol, ['cuentas' => true]),
            'kng_arca' => array_diff_key($datosArca, ['cuentas' => true]),
            'pendientes' => count($pendientes),
            'muestra_pendientes' => array_slice(array_values($pendientes), 0, 50),
            'activos_cobol' => 0,
            'activos_arca' => 0,
            'activos_liquidaciones' => 0,
            'activos_impuestos' => 0,
            'activos_movimientos' => 0,
            'activos_consolidados' => 0,
            'activaciones_pendientes' => 0,
            'desactivaciones_pendientes' => 0,
            'detalle_activaciones' => [],
            'detalle_desactivaciones' => [],
            'aplicada' => false,
        ];
        $filas = [];
        $ahora = now();
        foreach ($canonicos as $idOriginal) {
            $id = (int) $idOriginal;
            $f = $flags[$id] ?? [];
            $cobol = isset($f['activo_cobol']);
            $arca = isset($f['activo_arca']);
            $liquidaciones = isset($f['activo_liquidaciones']);
            $impuestos = isset($f['activo_impuestos']);
            $movimientos = isset($f['activo_movimientos']);
            $fila = [
                'cliente_id' => $id,
                'periodo' => $periodo,
                'activo_cobol' => $cobol,
                'activo_arca' => $arca,
                'activo_liquidaciones' => $liquidaciones,
                'activo_impuestos' => $impuestos,
                // Calculado; no persistir sin migración explícita.
                'activo_movimientos' => $movimientos,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
            $activo = self::estaActivo($fila);
            $resumen['activos_cobol'] += (int) $cobol;
            $resumen['activos_arca'] += (int) $arca;
            $resumen['activos_liquidaciones'] += (int) $liquidaciones;
            $resumen['activos_impuestos'] += (int) $impuestos;
            $resumen['activos_movimientos'] += (int) $movimientos;
            $resumen['activos_consolidados'] += (int) $activo;
            $actual = $clientes->get($id)->activo;
            $detalleActividad = [
                'cliente_id' => $id,
                'estado_actual' => $actual,
                'activo_calculado' => $activo,
                'cobol' => $cobol,
                'arca' => $arca,
                'liquidaciones' => $liquidaciones,
                'impuestos' => $impuestos,
                'movimientos' => $movimientos ?? false,
            ];
            if ($activo && $actual !== true && $actual !== 1) {
                $resumen['activaciones_pendientes']++;
                $resumen['detalle_activaciones'][] = $detalleActividad;
            }
            if (! $activo && $actual !== false && $actual !== 0) {
                $resumen['desactivaciones_pendientes']++;
                $resumen['detalle_desactivaciones'][] = $detalleActividad;
            }
            $filas[] = $fila;
        }

        if ($modo === 'analizar') {
            return $resumen;
        }
        if ($pendientes !== [] && $modo === 'completa') {
            throw new RuntimeException('Hay '.count($pendientes).' referencias sin identidad segura. No se aplican bajas; revisar muestra_pendientes mediante ANALIZAR.');
        }

        DB::transaction(function () use ($filas, $modo): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['gei:actividad-consolidada-clientes']);
            foreach (array_chunk($filas, 500) as $grupo) {
                if ($modo === 'solo_altas') {
                    $grupo = array_values(array_filter($grupo, static fn (array $f): bool => self::estaActivo($f)));
                }
                if ($grupo === []) {
                    continue;
                }
                DB::table('clientes_actividad_periodos')->upsert(
                    $grupo,
                    ['cliente_id', 'periodo'],
                    ['activo_cobol', 'activo_arca', 'activo_liquidaciones', 'activo_impuestos', 'updated_at']
                );
                $activosIds = array_column(array_filter($grupo, static fn (array $f): bool => self::estaActivo($f)), 'cliente_id');
                if ($activosIds !== []) {
                    DB::table('clientes')->whereIn('id', $activosIds)
                        ->where(function ($q): void { $q->whereNull('activo')->orWhere('activo', false); })
                        ->update(['activo' => true, 'updated_at' => now()]);
                }
                if ($modo === 'completa') {
                    $inactivosIds = array_column(array_filter($grupo, static fn (array $f): bool => ! self::estaActivo($f)), 'cliente_id');
                    if ($inactivosIds !== []) {
                        DB::table('clientes')->whereIn('id', $inactivosIds)
                            ->where(function ($q): void { $q->whereNull('activo')->orWhere('activo', true); })
                            ->update(['activo' => false, 'updated_at' => now()]);
                    }
                }
            }
        }, 3);
        $resumen['aplicada'] = true;
        $resumen['aplicacion_parcial'] = $modo === 'solo_altas';

        return $resumen;
    }

    /** Resultado derivado de las cuatro evidencias; no persiste un quinto indicador. */
    private static function estaActivo(array $fila): bool
    {
        return (bool) ($fila['activo_cobol'] || $fila['activo_arca'] || $fila['activo_liquidaciones'] || $fila['activo_impuestos'] || ($fila['activo_movimientos'] ?? false));
    }

    private function normalizar(string $cuenta): string
    {
        return preg_replace('/\D+/', '', $cuenta) ?? '';
    }
}
