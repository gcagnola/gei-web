<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Historial de movimientos COBOL, sin saldos ni intereses.
 * Consulta solo las cuentas autorizadas por el controlador (rol + cuenta).
 * Desglosa IVA 05/46 y 55/97 sin modificar los importes persistidos.
 */
final class CuentaCorrienteHistorialDesdeService
{
    public function consultar(Collection $cuentasAutorizadas, string $desde): LengthAwarePaginator
    {
        $claves = CuentaCorrienteSaldosClienteService::clavesAutorizadas($cuentasAutorizadas);
        if ($claves === []) {
            return new LengthAwarePaginator([], 0, 100, 1, ['pageName' => 'pagina_historial']);
        }

        $query = DB::table('cuentas_corrientes as cc')
            ->join('cuentas_corrientes_movimientos as m', 'm.cuenta_corriente_id', '=', 'cc.id')
            ->where(static function ($q) use ($claves): void {
                foreach ($claves as $clave) {
                    $q->orWhere(static function ($cuenta) use ($clave): void {
                        $cuenta->where('cc.dominio', $clave['rol'])
                            ->where('cc.cuenta', $clave['cuenta']);
                    });
                }
            })
            ->where('m.fecha', '>=', $desde)
            ->orderBy('cc.dominio')
            ->orderBy('cc.cuenta')
            ->orderBy('m.fecha')
            ->orderBy('m.codigo')
            ->orderBy('m.numero')
            ->orderBy('m.id');

        $pagina = $query->paginate(100, [
            'cc.dominio as rol', 'cc.cuenta as cuenta_cobol',
            'm.fecha', 'm.fecha_origen', 'm.codigo', 'm.numero',
            'm.descripcion', 'm.importe', 'm.importe_abonado',
            'm.debe', 'm.haber', 'm.iva',
        ], 'pagina_historial')->withQueryString();

        $filas = collect();
        foreach ($pagina->items() as $m) {
            $codigo = str_pad(trim((string) $m->codigo), 2, '0', STR_PAD_LEFT);
            $importe = self::centavos($m->importe);
            $abonado = self::centavos($m->importe_abonado);
            $pagado = $importe > 0 && $abonado >= $importe;
            $debe = self::centavos($m->debe);
            $haber = self::centavos($m->haber);
            $iva = self::centavos($m->iva);
            $fecha = substr((string) $m->fecha, 0, 10);
            $base = [
                'rol' => (string) $m->rol,
                'cuenta' => (string) $m->cuenta_cobol,
                'fecha' => $fecha,
                'numero' => (string) $m->numero,
                'pagado' => $pagado,
            ];

            // En INQCTACTE el importe incluye el IVA. COCCTE lo presenta
            // en dos renglones con el mismo comprobante, no en dos cargos.
            $separaDebito = $codigo === '05' && $debe > 0 && $iva > 0 && $iva <= $debe;
            $separaCredito = $codigo === '55' && $haber > 0 && $iva > 0 && $iva <= $haber;
            $filas->push($base + [
                'codigo' => $codigo,
                'descripcion' => (string) ($m->descripcion ?? ''),
                'debe' => $debe - ($separaDebito ? $iva : 0),
                'haber' => $haber - ($separaCredito ? $iva : 0),
            ]);
            if ($separaDebito || $separaCredito) {
                $filas->push($base + [
                    'codigo' => $separaDebito ? '46' : '97',
                    'descripcion' => 'IVA',
                    'debe' => $separaDebito ? $iva : 0,
                    'haber' => $separaCredito ? $iva : 0,
                ]);
            }
        }
        $pagina->setCollection($filas);
        return $pagina;
    }

    /**
     * GEI_HISTORIAL_SALDO_INICIAL_20261106. Arrastre contable anterior a DESDE, por cuenta COBOL autorizada.
     * Solamente movimientos que afectan saldo y cuya fecha es anterior al corte.
     * Es una proyección del libro histórico: no es deuda pendiente ni GIMB38.
     * @return Collection<string, array{rol:string,cuenta:string,saldo_centavos:int,movimientos_anteriores:int}>
     */
    public function saldosIniciales(Collection $cuentasAutorizadas, string $desde): Collection
    {
        $claves = CuentaCorrienteSaldosClienteService::clavesAutorizadas($cuentasAutorizadas);
        if ($claves === []) {
            return collect();
        }

        $datos = DB::table('cuentas_corrientes as cc')
            ->leftJoin('cuentas_corrientes_movimientos as m', static function ($join) use ($desde): void {
                $join->on('m.cuenta_corriente_id', '=', 'cc.id')
                    ->where('m.fecha', '<', $desde)
                    ->whereRaw('COALESCE(m.afecta_saldo, TRUE) = TRUE');
            })
            ->where(static function ($q) use ($claves): void {
                foreach ($claves as $clave) {
                    $q->orWhere(static function ($c) use ($clave): void {
                        $c->where('cc.dominio', $clave['rol'])
                            ->where('cc.cuenta', $clave['cuenta']);
                    });
                }
            })
            ->select('cc.dominio as rol', 'cc.cuenta as cuenta')
            ->selectRaw('COUNT(m.id) AS movimientos_anteriores')
            ->selectRaw('COALESCE(SUM(m.debe), 0) - COALESCE(SUM(m.haber), 0) AS saldo_anterior')
            ->groupBy('cc.dominio', 'cc.cuenta')
            ->orderBy('cc.dominio')->orderBy('cc.cuenta')
            ->get();

        return $datos->mapWithKeys(static function ($r): array {
            $clave = (string) $r->rol.'|'.(string) $r->cuenta;
            return [$clave => [
                'rol' => (string) $r->rol,
                'cuenta' => (string) $r->cuenta,
                'saldo_centavos' => self::centavos($r->saldo_anterior),
                'movimientos_anteriores' => (int) $r->movimientos_anteriores,
            ]];
        });
    }

    private static function centavos(mixed $valor): int
    {
        $raw = trim((string) ($valor ?? '0'));
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', $raw, $partes)) {
            throw new \UnexpectedValueException('Importe COBOL no convertible a centavos.');
        }
        $centavos = ((int) $partes[2] * 100)
            + (int) str_pad($partes[3] ?? '', 2, '0');
        return ($partes[1] === '-' ? -1 : 1) * $centavos;
    }
}
