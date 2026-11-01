<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Actividad OPERATIVA por cuenta/rol/período, sólo desde gei_db.
 * No determina bajas ni modifica clientes.activo o permisos de Autogestión.
 * Los vínculos se resuelven por cuenta + rol EXACTOS y cliente canónico.
 */
final class ClienteActividadOperativaPeriodoService
{
    /** @return array{0: string, 1: list<string>} */
    public function seleccionarPeriodo(Request $request): array
    {
        $periodos = DB::table('cuentas_actividad_periodos_control')
            ->orderByDesc('periodo')->pluck('periodo')
            ->map(static fn ($p): string => (string) $p)->all();

        abort_if($periodos === [], 503,
            'No hay actividad de cuentas calculada. Ejecutar gei:actualizar-actividad-cuentas AAAAMM --confirmar.');

        $periodo = trim((string) $request->query('periodo_actividad', $periodos[0]));
        abort_unless(in_array($periodo, $periodos, true), 422,
            'La actividad del período solicitado aún no está calculada.');

        return [$periodo, $periodos];
    }

    /**
     * Aplica el filtro en SQL ANTES de paginar y sin reconstruir actividad.
     * 'inactivos' significa sin actividad en el período, NO una baja COBOL.
     * Una persona sin cuentas visibles no se etiqueta como inactiva.
     *
     * @param list<string> $prefijos Cuentas permitidas según sede, p.ej. 1103,1202.
     */
    public function filtrar(
        Builder $consulta,
        string $estado,
        string $periodo,
        array $prefijos,
        string $rol
    ): void {
        if (! in_array($estado, ['activos', 'inactivos'], true)) {
            return;
        }

        $condicionCuentas = static function ($sub) use ($prefijos, $rol): void {
            $sub->selectRaw('1')
                ->from('clientes_cuentas as cc')
                ->join('clientes as origen', 'origen.id', '=', 'cc.cliente_id')
                ->whereRaw('COALESCE(origen.id_cliente_canonico, origen.id) = clientes.id')
                ->whereIn(DB::raw('LEFT(cc.cuenta, 4)'), $prefijos)
                ->whereIn('cc.rol', $rol === 'TODOS' ? ['INQUILINO', 'PROPIETARIO'] : [$rol]);
        };

        $condicionActivas = static function ($sub) use ($prefijos, $rol, $periodo): void {
            $sub->selectRaw('1')
                ->from('clientes_cuentas as cc')
                ->join('clientes as origen', 'origen.id', '=', 'cc.cliente_id')
                ->join('cuentas_actividad_periodos as actividad', static function ($join) use ($periodo): void {
                    $join->on('actividad.cuenta', '=', 'cc.cuenta')
                        ->on('actividad.dominio', '=', 'cc.rol')
                        ->where('actividad.periodo', '=', $periodo)
                        ->where('actividad.con_actividad', '=', true);
                })
                ->whereRaw('COALESCE(origen.id_cliente_canonico, origen.id) = clientes.id')
                ->whereIn(DB::raw('LEFT(cc.cuenta, 4)'), $prefijos)
                ->whereIn('cc.rol', $rol === 'TODOS' ? ['INQUILINO', 'PROPIETARIO'] : [$rol]);
        };

        if ($estado === 'activos') {
            $consulta->whereExists($condicionActivas);
        } else {
            $consulta->whereExists($condicionCuentas)
                ->whereNotExists($condicionActivas);
        }
    }

    /**
     * Anota el estado de la página completa con UNA consulta; sin N+1.
     * Se espera la relación 'cuentas' YA CARGADA y limitada por sucursal.
     *
     * @param Collection<int, object> $clientes
     */
    public function anotar(Collection $clientes, string $periodo, string $rol): void
    {
        if ($clientes->isEmpty()) {
            return;
        }
        $cuentas = $clientes->flatMap(static fn ($c) => $c->cuentas)->values();
        $numeros = $cuentas->pluck('cuenta')->unique()->values()->all();

        $clavesActivas = $numeros === [] ? [] : DB::table('cuentas_actividad_periodos')
            ->where('periodo', $periodo)
            ->where('con_actividad', true)
            ->whereIn('cuenta', $numeros)
            ->whereIn('dominio', ['PROPIETARIO', 'INQUILINO'])
            ->get(['dominio', 'cuenta'])
            ->mapWithKeys(static fn ($fila): array => [
                (string) $fila->dominio.'|'.(string) $fila->cuenta => true,
            ])->all();

        foreach ($clientes as $cliente) {
            $conCuenta = false;
            $tieneActividad = false;
            foreach ($cliente->cuentas as $cuenta) {
                $activa = isset($clavesActivas[(string) $cuenta->rol.'|'.(string) $cuenta->cuenta]);
                $cuenta->setAttribute('actividad_periodo', $activa);
                if (($rol === 'TODOS' && in_array((string) $cuenta->rol, ['PROPIETARIO', 'INQUILINO'], true))
                    || (string) $cuenta->rol === $rol) {
                    $conCuenta = true;
                    $tieneActividad = $tieneActividad || $activa;
                }
            }
            $cliente->setAttribute('actividad_periodo', $conCuenta ? $tieneActividad : null);
        }
    }
}
