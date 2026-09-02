<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class SincronizacionActividadClientesService
{
    public function __construct(
        private readonly ComprobantesArcaService $comprobantesArca,
        private readonly ImpuestosGarantizadosPdfService $impuestosGarantizados,
    ) {
    }

    /**
     * Simulación de sólo lectura. Devuelve exactamente el universo que usaría
     * sincronizar(), sin modificar clientes.activo.
     *
     * @return array<string, mixed>
     */
    public function analizar(string $periodo): array
    {
        return $this->calcular($periodo, false);
    }

    /**
     * Aplica la regla operativa:
     *
     * ACTIVO si el cliente aparece en al menos una de estas fuentes del último
     * período operativo:
     * - liquidaciones de propietarios;
     * - impuestos garantizados (DAILOC);
     * - comprobantes ARCA.
     *
     * Todo cliente canónico que no aparezca en ninguna de esas fuentes queda
     * PASIVO. Los clientes absorbidos (id_cliente_canonico no nulo) no se usan
     * como universo operativo; sus cuentas resuelven hacia el canónico.
     *
     * @return array<string, mixed>
     */
    public function sincronizar(string $periodo): array
    {
        return $this->calcular($periodo, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function calcular(string $periodo, bool $aplicar): array
    {
        $this->validarPeriodo($periodo);
        $this->validarEsquema();

        $ultimoPeriodo = (string) (
            DB::table('liquidaciones_propietarios')->max('periodo') ?? ''
        );

        if ($ultimoPeriodo !== $periodo) {
            return $this->omitido(
                $periodo,
                $ultimoPeriodo === ''
                    ? 'No hay liquidaciones guardadas para determinar el último período operativo.'
                    : "El período {$periodo} no es el último período operativo ({$ultimoPeriodo})."
            );
        }

        if (! $this->comprobantesArca->periodoDisponible($periodo)) {
            return $this->omitido(
                $periodo,
                "No está disponible el directorio ARCA del período {$periodo}. No se modifica clientes.activo."
            );
        }

        $liquidaciones = DB::table('liquidaciones_propietarios')
            ->where('periodo', $periodo)
            ->get(['cliente_id', 'cuenta']);

        if ($liquidaciones->isEmpty()) {
            return $this->omitido(
                $periodo,
                "No hay liquidaciones de propietarios para {$periodo}. No se modifica clientes.activo."
            );
        }

        $cuentasLiquidaciones = $liquidaciones
            ->pluck('cuenta')
            ->map(fn (mixed $cuenta): string => $this->normalizarCuenta((string) $cuenta))
            ->filter()
            ->unique()
            ->values();

        $cuentasImpuestos = collect($this->impuestosGarantizados->cuentasPeriodo($periodo))
            ->map(fn (mixed $cuenta): string => $this->normalizarCuenta((string) $cuenta))
            ->filter()
            ->unique()
            ->values();

        $arcaPorCuenta = $this->comprobantesArca->porPeriodo($periodo);
        $cuentasArca = $arcaPorCuenta
            ->keys()
            ->map(fn (mixed $cuenta): string => $this->normalizarCuenta((string) $cuenta))
            ->filter()
            ->unique()
            ->values();

        $cuentasActividad = $cuentasLiquidaciones
            ->concat($cuentasImpuestos)
            ->concat($cuentasArca)
            ->unique()
            ->values();

        if ($cuentasActividad->isEmpty()) {
            return $this->omitido(
                $periodo,
                "Las fuentes del período {$periodo} no contienen cuentas de actividad. No se modifica clientes.activo."
            );
        }

        $clientes = DB::table('clientes')
            ->get(['id', 'id_cliente_canonico', 'activo'])
            ->keyBy(fn (object $cliente): int => (int) $cliente->id);

        if ($clientes->isEmpty()) {
            throw new RuntimeException('La tabla clientes está vacía.');
        }

        $resolverCanonico = function (int $clienteId) use ($clientes): ?int {
            $visitados = [];

            while ($clienteId > 0 && isset($clientes[$clienteId])) {
                if (isset($visitados[$clienteId])) {
                    return null;
                }

                $visitados[$clienteId] = true;
                $siguiente = $clientes[$clienteId]->id_cliente_canonico;

                if ($siguiente === null) {
                    return $clienteId;
                }

                $clienteId = (int) $siguiente;
            }

            return null;
        };

        $clientesCanonicos = $clientes
            ->filter(fn (object $cliente): bool => $cliente->id_cliente_canonico === null)
            ->keys()
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ($clientesCanonicos === []) {
            throw new RuntimeException('No hay clientes canónicos para sincronizar actividad.');
        }

        $cuentasActivasSet = array_fill_keys($cuentasActividad->all(), true);
        $cuentasResueltasSet = [];
        $cuentasResueltasDirectasSet = [];
        $cuentasResueltasResolucionSet = [];
        $cuentasResueltasConflictoSet = [];
        $cuentasResueltasCodigoClienteSet = [];
        $clientesActivosSet = [];

        // 1) Relación normal y definitiva cuenta COBOL -> cliente.
        foreach (
            DB::table('clientes_cuentas')
                ->orderBy('id')
                ->cursor(['cliente_id', 'cuenta']) as $relacion
        ) {
            $cuenta = $this->normalizarCuenta((string) $relacion->cuenta);

            if ($cuenta === '' || ! isset($cuentasActivasSet[$cuenta])) {
                continue;
            }

            $canonicoId = $resolverCanonico((int) $relacion->cliente_id);
            if ($canonicoId === null) {
                continue;
            }

            $clientesActivosSet[$canonicoId] = true;
            $cuentasResueltasSet[$cuenta] = true;
            $cuentasResueltasDirectasSet[$cuenta] = true;
        }

        // 2) Resoluciones humanas explícitas. Sólo usamos las que apuntan a un
        // cliente. La decisión de identidad ya fue tomada en el circuito de
        // unificación; aquí únicamente se deriva el estado operativo.
        $cuentasPendientes = fn (): array => $cuentasActividad
            ->reject(fn (string $cuenta): bool => isset($cuentasResueltasSet[$cuenta]))
            ->values()
            ->all();

        $pendientes = $cuentasPendientes();

        if ($pendientes !== []) {
            foreach (
                DB::table('clientes_resoluciones_origen')
                    ->whereIn('clave_origen', $pendientes)
                    ->whereNotNull('cliente_id')
                    ->orderBy('id_cliente_resolucion_origen')
                    ->get(['clave_origen', 'cliente_id']) as $resolucion
            ) {
                $cuenta = $this->normalizarCuenta((string) $resolucion->clave_origen);
                if ($cuenta === '' || isset($cuentasResueltasSet[$cuenta])) {
                    continue;
                }

                $canonicoId = $resolverCanonico((int) $resolucion->cliente_id);
                if ($canonicoId === null) {
                    continue;
                }

                $clientesActivosSet[$canonicoId] = true;
                $cuentasResueltasSet[$cuenta] = true;
                $cuentasResueltasResolucionSet[$cuenta] = true;
            }
        }

        // 3) Conflictos COBOL pendientes: para Activo/Pasivo NO resolvemos el
        // conflicto. Activamos conservadoramente todos los candidatos para no
        // pasar a pasivo al cliente correcto mientras espera revisión humana.
        $pendientes = $cuentasPendientes();

        if ($pendientes !== []) {
            foreach (
                DB::table('clientes_conflictos')
                    ->whereIn('clave_origen', $pendientes)
                    ->where('estado', 'PENDIENTE')
                    ->orderBy('id')
                    ->get(['clave_origen', 'clientes_candidatos', 'cliente_resuelto_id']) as $conflicto
            ) {
                $cuenta = $this->normalizarCuenta((string) $conflicto->clave_origen);
                if ($cuenta === '' || isset($cuentasResueltasSet[$cuenta])) {
                    continue;
                }

                $ids = [];

                if ($conflicto->cliente_resuelto_id !== null) {
                    $ids[] = (int) $conflicto->cliente_resuelto_id;
                }

                foreach ($this->idsJson($conflicto->clientes_candidatos) as $id) {
                    $ids[] = $id;
                }

                $resolvio = false;

                foreach (array_values(array_unique($ids)) as $clienteId) {
                    $canonicoId = $resolverCanonico((int) $clienteId);
                    if ($canonicoId === null) {
                        continue;
                    }

                    $clientesActivosSet[$canonicoId] = true;
                    $resolvio = true;
                }

                if ($resolvio) {
                    $cuentasResueltasSet[$cuenta] = true;
                    $cuentasResueltasConflictoSet[$cuenta] = true;
                }
            }
        }

        // 4) Algunas facturas históricas ARCA usan 000000xxxxx, donde xxxxx
        // corresponde al código histórico del cliente y no a una cuenta COBOL.
        // Se acepta sólo para ARCA, con 11 dígitos, seis ceros iniciales y un
        // cliente existente. No se crea clientes_cuentas ni se altera identidad.
        $cuentasArcaSet = array_fill_keys($cuentasArca->all(), true);

        foreach ($cuentasPendientes() as $cuenta) {
            if (
                ! isset($cuentasArcaSet[$cuenta])
                || preg_match('/^000000\d{5}$/', $cuenta) !== 1
            ) {
                continue;
            }

            $clienteId = (int) ltrim($cuenta, '0');
            if ($clienteId <= 0 || ! isset($clientes[$clienteId])) {
                continue;
            }

            $canonicoId = $resolverCanonico($clienteId);
            if ($canonicoId === null) {
                continue;
            }

            $clientesActivosSet[$canonicoId] = true;
            $cuentasResueltasSet[$cuenta] = true;
            $cuentasResueltasCodigoClienteSet[$cuenta] = true;
        }

        // La liquidación puede conservar cliente_id ya resuelto. Se suma como
        // evidencia operativa, pero no se usa para dar por resuelta una cuenta.
        foreach ($liquidaciones as $liquidacion) {
            if ($liquidacion->cliente_id === null) {
                continue;
            }

            $canonicoId = $resolverCanonico((int) $liquidacion->cliente_id);
            if ($canonicoId !== null) {
                $clientesActivosSet[$canonicoId] = true;
            }
        }

        if ($clientesActivosSet === []) {
            return $this->omitido(
                $periodo,
                'Ninguna cuenta de actividad pudo resolverse a un cliente. No se modifica clientes.activo.'
            );
        }

        $clientesActivos = array_map('intval', array_keys($clientesActivosSet));
        sort($clientesActivos, SORT_NUMERIC);

        $activosLookup = array_fill_keys($clientesActivos, true);
        $clientesPasivos = array_values(array_filter(
            $clientesCanonicos,
            static fn (int $id): bool => ! isset($activosLookup[$id])
        ));

        $cuentasNoResueltas = $cuentasActividad
            ->reject(fn (string $cuenta): bool => isset($cuentasResueltasSet[$cuenta]))
            ->values();

        $comprobantesArca = $arcaPorCuenta
            ->sum(fn (Collection $items): int => $items->count());

        $clientesActivadosCambio = 0;
        foreach ($clientesActivos as $id) {
            $actual = $clientes[$id]->activo ?? null;
            if ($actual === null || $actual === false) {
                $clientesActivadosCambio++;
            }
        }

        $clientesDesactivadosCambio = 0;
        foreach ($clientesPasivos as $id) {
            $actual = $clientes[$id]->activo ?? null;
            if ($actual === null || $actual === true) {
                $clientesDesactivadosCambio++;
            }
        }

        $puedeAplicar = $cuentasNoResueltas->isEmpty();

        $resultado = [
            'aplicada' => false,
            'puede_aplicar' => $puedeAplicar,
            'simulacion' => ! $aplicar,
            'periodo' => $periodo,
            'ultimo_periodo_operativo' => $ultimoPeriodo,
            'fuentes' => [
                'liquidaciones' => $liquidaciones->count(),
                'cuentas_liquidaciones' => $cuentasLiquidaciones->count(),
                'cuentas_impuestos_garantizados' => $cuentasImpuestos->count(),
                'cuentas_arca' => $cuentasArca->count(),
                'comprobantes_arca' => $comprobantesArca,
            ],
            'cuentas_actividad' => $cuentasActividad->count(),
            'cuentas_resueltas' => count($cuentasResueltasSet),
            'cuentas_resueltas_directas' => count($cuentasResueltasDirectasSet),
            'cuentas_resueltas_por_resolucion' => count($cuentasResueltasResolucionSet),
            'cuentas_resueltas_por_conflicto' => count($cuentasResueltasConflictoSet),
            'cuentas_arca_codigo_cliente' => count($cuentasResueltasCodigoClienteSet),
            'cuentas_no_resueltas' => $cuentasNoResueltas->count(),
            'muestra_cuentas_no_resueltas' => $cuentasNoResueltas->take(20)->all(),
            'clientes_canonicos_totales' => count($clientesCanonicos),
            'clientes_activos' => count($clientesActivos),
            'clientes_pasivos' => count($clientesPasivos),
            'clientes_activados_cambio' => $clientesActivadosCambio,
            'clientes_desactivados_cambio' => $clientesDesactivadosCambio,
        ];

        if (! $puedeAplicar) {
            $resultado['motivo'] =
                'Quedan cuentas con actividad sin relación segura a clientes. '
                .'No se modifica clientes.activo hasta resolverlas o clasificarlas.';

            return $resultado;
        }

        if (! $aplicar) {
            return $resultado;
        }

        $activados = 0;
        $desactivados = 0;
        $origenesEstadoActualizados = 0;
        $conflictosEstadoActualizados = 0;

        DB::transaction(function () use (
            $clientesActivos,
            $clientesPasivos,
            $cuentasActivasSet,
            &$activados,
            &$desactivados,
            &$origenesEstadoActualizados,
            &$conflictosEstadoActualizados
        ): void {
            // Evita dos sincronizaciones de actividad simultáneas aun si se
            // invocaran desde circuitos web distintos.
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                ['gei:sincronizacion-actividad-clientes']
            );

            $ahora = now();

            foreach (array_chunk($clientesActivos, 1000) as $ids) {
                $activados += DB::table('clientes')
                    ->whereNull('id_cliente_canonico')
                    ->whereIn('id', $ids)
                    ->where(function ($query): void {
                        $query->whereNull('activo')->orWhere('activo', false);
                    })
                    ->update([
                        'activo' => true,
                        'updated_at' => $ahora,
                    ]);
            }

            foreach (array_chunk($clientesPasivos, 1000) as $ids) {
                $desactivados += DB::table('clientes')
                    ->whereNull('id_cliente_canonico')
                    ->whereIn('id', $ids)
                    ->where(function ($query): void {
                        $query->whereNull('activo')->orWhere('activo', true);
                    })
                    ->update([
                        'activo' => false,
                        'updated_at' => $ahora,
                    ]);
            }

            // estado_origen representa la actividad de la cuenta COBOL concreta,
            // no la identidad ni el estado global del cliente canónico.
            //
            // Sólo corregimos DESCONOCIDO. Los ACTIVO/BAJA ya clasificados se
            // preservan y los conflictos de identidad continúan PENDIENTE/RESUELTO
            // sin alteración.
            foreach (
                DB::table('clientes_origenes')
                    ->where('sistema_origen', 'COBOL')
                    ->whereIn('entidad_origen', ['PROPIETAR', 'INQUILINO'])
                    ->where('estado_origen', 'DESCONOCIDO')
                    ->orderBy('id')
                    ->cursor(['id', 'clave_origen']) as $origen
            ) {
                $cuenta = $this->normalizarCuenta((string) $origen->clave_origen);
                $nuevoEstado = $cuenta !== '' && isset($cuentasActivasSet[$cuenta])
                    ? 'ACTIVO'
                    : 'BAJA';

                $origenesEstadoActualizados += DB::table('clientes_origenes')
                    ->where('id', $origen->id)
                    ->where('estado_origen', 'DESCONOCIDO')
                    ->update([
                        'estado_origen' => $nuevoEstado,
                        'updated_at' => $ahora,
                    ]);
            }

            foreach (
                DB::table('clientes_conflictos')
                    ->where('sistema_origen', 'COBOL')
                    ->whereIn('entidad_origen', ['PROPIETAR', 'INQUILINO'])
                    ->where('estado_origen', 'DESCONOCIDO')
                    ->orderBy('id')
                    ->cursor(['id', 'clave_origen']) as $conflicto
            ) {
                $cuenta = $this->normalizarCuenta((string) $conflicto->clave_origen);
                $nuevoEstado = $cuenta !== '' && isset($cuentasActivasSet[$cuenta])
                    ? 'ACTIVO'
                    : 'BAJA';

                $conflictosEstadoActualizados += DB::table('clientes_conflictos')
                    ->where('id', $conflicto->id)
                    ->where('estado_origen', 'DESCONOCIDO')
                    ->update([
                        'estado_origen' => $nuevoEstado,
                        'updated_at' => $ahora,
                    ]);
            }
        }, 3);

        $resultado['aplicada'] = true;
        $resultado['simulacion'] = false;
        $resultado['clientes_activados_cambio'] = $activados;
        $resultado['clientes_desactivados_cambio'] = $desactivados;
        $resultado['clientes_origenes_estado_actualizados'] = $origenesEstadoActualizados;
        $resultado['clientes_conflictos_estado_actualizados'] = $conflictosEstadoActualizados;

        return $resultado;
    }

    /** @return array<string, mixed> */
    private function omitido(string $periodo, string $motivo): array
    {
        return [
            'aplicada' => false,
            'puede_aplicar' => false,
            'simulacion' => true,
            'periodo' => $periodo,
            'motivo' => $motivo,
        ];
    }

    private function validarPeriodo(string $periodo): void
    {
        if (preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            throw new RuntimeException('El período debe tener formato AAAAMM.');
        }
    }

    private function validarEsquema(): void
    {
        foreach ([
            'clientes',
            'clientes_cuentas',
            'clientes_origenes',
            'liquidaciones_propietarios',
            'clientes_conflictos',
            'clientes_resoluciones_origen',
        ] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                throw new RuntimeException("Falta la tabla {$tabla} para sincronizar actividad de clientes.");
            }
        }

        foreach (['activo', 'id_cliente_canonico'] as $columna) {
            if (! Schema::hasColumn('clientes', $columna)) {
                throw new RuntimeException("Falta clientes.{$columna} para sincronizar actividad.");
            }
        }

        foreach (['sistema_origen', 'entidad_origen', 'clave_origen', 'estado_origen'] as $columna) {
            if (! Schema::hasColumn('clientes_origenes', $columna)) {
                throw new RuntimeException(
                    "Falta clientes_origenes.{$columna} para sincronizar actividad."
                );
            }

            if (! Schema::hasColumn('clientes_conflictos', $columna)) {
                throw new RuntimeException(
                    "Falta clientes_conflictos.{$columna} para sincronizar actividad."
                );
            }
        }
    }

    /**
     * @return list<int>
     */
    private function idsJson(mixed $valor): array
    {
        if ($valor === null || $valor === '') {
            return [];
        }

        if (is_string($valor)) {
            try {
                $valor = json_decode($valor, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        } elseif (is_object($valor)) {
            $valor = (array) $valor;
        }

        if (! is_array($valor)) {
            return [];
        }

        return collect($valor)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizarCuenta(string $cuenta): string
    {
        return preg_replace('/\D+/', '', $cuenta) ?? '';
    }
}
