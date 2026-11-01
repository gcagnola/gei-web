<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Relaciones operativas de Clientes: cada consulta arranca en un ID de
 * clientes_cuentas previamente autorizado por el controlador y la sucursal.
 * Nunca se concede acceso por coincidencias de nombre, CUIT o cliente_id solo.
 */
final class ClienteMaestroRelacionesService
{
    /** @return array{inmuebles:Collection,repartos:Collection,contratos:Collection,inquilinos:Collection} */
    public function consultar(Collection $cuentas, array $prefijosVisibles): array
    {
        $idsPropietario = $cuentas->where('rol', 'PROPIETARIO')->pluck('id')->all();
        $idsInquilino = $cuentas->where('rol', 'INQUILINO')->pluck('id')->all();
        $cuentasPropietario = $cuentas->where('rol', 'PROPIETARIO')->pluck('cuenta')->unique()->all();

        $inmuebles = collect();
        $repartos = collect();
        $inquilinos = collect();
        $contratos = collect();

        if ($idsPropietario !== []) {
            $inmuebles = DB::table('inmuebles_propietarios as ip')
                ->join('inmuebles as i', 'i.id', '=', 'ip.inmueble_id')
                ->join('clientes_cuentas as cc', 'cc.id', '=', 'ip.cliente_cuenta_id')
                ->whereIn('ip.cliente_cuenta_id', $idsPropietario)
                ->select('i.id', 'i.domicilio', 'i.estado', 'ip.activo as vinculo_activo', 'cc.cuenta')
                ->orderBy('i.domicilio')->get()
                ->groupBy('id')->map(static function (Collection $filas): object {
                    $primera = $filas->first();
                    $activo = $filas->contains(static fn (object $fila): bool =>
                        $fila->estado === 'ACTIVO' && (bool) $fila->vinculo_activo);
                    return (object) [
                        'id' => $primera->id,
                        'domicilio' => $primera->domicilio,
                        'estado' => $activo ? 'ACTIVO' : ($primera->estado ?: 'DESCONOCIDO'),
                        'activo' => $activo,
                        'cuentas' => $filas->pluck('cuenta')->unique()->sort()->values(),
                    ];
                })->values();

            if ($cuentasPropietario !== []) {
                $repartos = DB::table('repartos_propietarios as rp')
                    ->whereIn('rp.cuenta', $cuentasPropietario)
                    ->where('rp.activo', true)
                    ->select('rp.id', 'rp.cuenta', 'rp.cuenta_impresa', 'rp.beneficiario',
                        'rp.porcentaje', 'rp.ultimo_periodo')
                    ->orderBy('rp.cuenta')->orderBy('rp.beneficiario')->get();
            }

            // Relación con el inmueble que pertenece a una cuenta PROPIETARIO
            // autorizada. La cuenta del contrato debe coincidir EXACTAMENTE.
            $inquilinos = DB::table('inmuebles_propietarios as ip')
                ->join('clientes_cuentas as pc', 'pc.id', '=', 'ip.cliente_cuenta_id')
                ->join('inmuebles as i', 'i.id', '=', 'ip.inmueble_id')
                ->join('contratos_inmuebles as cim', 'cim.inmueble_id', '=', 'i.id')
                ->join('contratos as c', function ($join): void {
                    $join->on('c.id', '=', 'cim.contrato_id')
                        ->on('c.cuenta_propietario', '=', 'pc.cuenta');
                })
                ->join('contratos_inquilinos as ci', 'ci.contrato_id', '=', 'c.id')
                ->join('clientes_cuentas as ic', function ($join): void {
                    $join->on('ic.id', '=', 'ci.cliente_cuenta_id')
                        ->on('ic.cuenta', '=', 'c.cuenta_inquilino')
                        ->where('ic.rol', '=', 'INQUILINO');
                })
                ->join('clientes as inquilino', 'inquilino.id', '=', 'ci.cliente_id')
                ->whereIn('ip.cliente_cuenta_id', $idsPropietario)
                ->whereIn(DB::raw('LEFT(c.cuenta_inquilino, 4)'), $prefijosVisibles)
                ->select('c.id as contrato_id', 'c.cuenta_inquilino', 'c.cuenta_propietario', 'c.fecha_inicio',
                    'c.fecha_fin', 'c.estado', 'ip.activo as vinculo_activo',
                    'i.id as inmueble_id', 'i.domicilio as inmueble_domicilio',
                    'inquilino.nombre as inquilino_nombre', 'inquilino.cuit as inquilino_cuit')
                ->orderByDesc('c.fecha_inicio')->get()
                ->unique(static fn (object $fila): string => $fila->contrato_id.'|'.$fila->inmueble_id.'|'.$fila->cuenta_inquilino)
                ->values();
        }

        if ($idsInquilino !== []) {
            $contratos = DB::table('contratos_inquilinos as ci')
                ->join('clientes_cuentas as ic', 'ic.id', '=', 'ci.cliente_cuenta_id')
                ->join('contratos as c', function ($join): void {
                    $join->on('c.id', '=', 'ci.contrato_id')
                        ->on('c.cuenta_inquilino', '=', 'ic.cuenta');
                })
                ->leftJoin('contratos_inmuebles as cim', 'cim.contrato_id', '=', 'c.id')
                ->leftJoin('inmuebles as i', 'i.id', '=', 'cim.inmueble_id')
                ->leftJoin('inmuebles_propietarios as ip', function ($join): void {
                    $join->on('ip.inmueble_id', '=', 'i.id')->where('ip.activo', '=', true);
                })
                ->leftJoin('clientes_cuentas as pc', function ($join) use ($prefijosVisibles): void {
                    $join->on('pc.id', '=', 'ip.cliente_cuenta_id')
                        ->on('pc.cuenta', '=', 'c.cuenta_propietario')
                        ->whereIn(DB::raw('LEFT(pc.cuenta, 4)'), $prefijosVisibles);
                })
                ->leftJoin('clientes as propietario', 'propietario.id', '=', 'pc.cliente_id')
                ->whereIn('ci.cliente_cuenta_id', $idsInquilino)
                ->select('c.id', 'c.codigo_origen', 'c.cuenta_inquilino',
                    'c.cuenta_propietario', 'c.fecha_inicio', 'c.fecha_fin',
                    'c.estado', 'i.domicilio as inmueble_domicilio',
                    'propietario.nombre as propietario_nombre')
                ->orderByDesc('c.fecha_inicio')->get()
                ->groupBy('id')->map(static function (Collection $filas): object {
                    $contrato = clone $filas->first();
                    $contrato->inmuebles = $filas->pluck('inmueble_domicilio')
                        ->filter()->unique()->sort()->values()->implode(' · ');
                    $contrato->propietarios = $filas->pluck('propietario_nombre')
                        ->filter()->unique()->sort()->values()->implode(' · ');
                    return $contrato;
                })->values();
        }

        return compact('inmuebles', 'repartos', 'inquilinos', 'contratos');
    }
}
