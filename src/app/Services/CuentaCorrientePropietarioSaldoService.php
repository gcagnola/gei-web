<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Proyeccion de lectura CTACTEPRO desde el mismo snapshot COBOL del portal.
 * NO es GIMB68 (exclusivo inquilinos), NO es liquidacion ni cierre conciliado.
 * Sentido del saldo: Debe - Haber; si es negativo hay saldo acreedor.
 */
final class CuentaCorrientePropietarioSaldoService
{
    /** @param array<int,array<string,mixed>> $movimientos */
    public static function calcular(array $movimientos, string $mes): array
    {
        if (! preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/D', $mes)) {
            throw new InvalidArgumentException('Periodo invalido.');
        }
        $inicio = substr($mes, 0, 4).'-'.substr($mes, 4, 2).'-01';
        $fin = (new \DateTimeImmutable($inicio))->modify('first day of next month')->format('Y-m-d');
        $debeAnterior = $haberAnterior = 0;
        $seleccionados = [];

        foreach ($movimientos as $indice => $mov) {
            if (! is_array($mov)) {
                throw new RuntimeException('Movimiento COBOL invalido.');
            }
            $fecha = substr((string) ($mov['fecha'] ?? ''), 0, 10);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fecha)
                || ! checkdate((int) substr($fecha,5,2), (int) substr($fecha,8,2), (int) substr($fecha,0,4))) {
                // No inventar ni imputar a meses arbitrarios movimientos sin fecha.
                throw new RuntimeException('Existe un movimiento CTACTEPRO sin fecha valida.');
            }
            if (! (bool) ($mov['afecta_saldo'] ?? true)) {
                continue;
            }
            $debe = self::centavos((string) ($mov['debe'] ?? '0.00'));
            $haber = self::centavos((string) ($mov['haber'] ?? '0.00'));
            if ($fecha < $inicio) {
                $debeAnterior += $debe;
                $haberAnterior += $haber;
            } elseif ($fecha < $fin) {
                $mov['fecha'] = $fecha;
                $mov['__orden'] = $indice;
                $mov['__debeCentavos'] = $debe;
                $mov['__haberCentavos'] = $haber;
                $seleccionados[] = $mov;
            }
        }

        // Orden cronologico estable: los movimientos de un mismo dia
        // mantienen el orden en el archivo COBOL, sin cambiarles la identidad.
        usort($seleccionados, static fn (array $a, array $b): int =>
            strcmp($a['fecha'], $b['fecha']) ?: $a['__orden'] <=> $b['__orden']);

        $saldo = $debeAnterior - $haberAnterior;
        foreach ($seleccionados as &$mov) {
            $saldo += $mov['__debeCentavos'] - $mov['__haberCentavos'];
            $mov['saldo_centavos'] = $saldo;
            unset($mov['__orden'], $mov['__debeCentavos'], $mov['__haberCentavos']);
        }
        unset($mov);

        return [
            'debitos_anteriores' => $debeAnterior,
            'creditos_anteriores' => $haberAnterior,
            'saldo_anterior' => $debeAnterior - $haberAnterior,
            'saldo_actual' => $saldo,
            'movimientos' => $seleccionados,
        ];
    }

    private static function centavos(string $importe): int
    {
        if (! preg_match('/^([0-9]+)\.([0-9]{2})$/D', $importe, $m)) {
            throw new RuntimeException('Importe COBOL invalido: no se calcula el saldo.');
        }
        return ((int) $m[1] * 100) + (int) $m[2];
    }
}
