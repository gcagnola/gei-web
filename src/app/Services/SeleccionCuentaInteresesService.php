<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Seleccion de cuenta para diagnosticos GIMB38 de la ficha administrativa.
 * La lista de cuentas llega previamente filtrada por cliente canonico, sede y rol.
 * Este servicio no permite ampliar esa lista ni modifica datos contables.
 */
final class SeleccionCuentaInteresesService
{
    /**
     * Si hay varias cuentas, priorizar actividad del mes consultado y, ante
     * igualdad, saldo contable absoluto; ante empate, mantener el orden.
     * Los saldos se expresan en centavos, tal como entrega GIMB68.
     *
     * @param Collection<int, string> $cuentasAutorizadas
     * @param Collection<string, array<string,mixed>> $cierresPorCuenta
     */
    public static function elegir(Collection $cuentasAutorizadas, Collection $cierresPorCuenta): string
    {
        $primera = (string) ($cuentasAutorizadas->first() ?? '');
        $mejor = $primera;
        $mejorMovimientos = -1;
        $mejorSaldo = -1;

        foreach ($cuentasAutorizadas as $cuenta) {
            $cuenta = (string) $cuenta;
            $cierre = $cierresPorCuenta->get('INQUILINO|'.$cuenta);
            if (! is_array($cierre) || ! isset($cierre['saldos']) || ! is_array($cierre['saldos'])) {
                continue;
            }
            $movimientos = $cierre['saldos']['movimientos'] ?? [];
            $cantidad = is_countable($movimientos) ? count($movimientos) : 0;
            $saldo = abs((int) ($cierre['saldos']['saldo_actual'] ?? 0));

            if ($cantidad > $mejorMovimientos ||
                ($cantidad === $mejorMovimientos && $saldo > $mejorSaldo)) {
                $mejor = $cuenta;
                $mejorMovimientos = $cantidad;
                $mejorSaldo = $saldo;
            }
        }

        return $mejor;
    }
}
