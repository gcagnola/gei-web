<?php

declare(strict_types=1);

namespace App\Support\Cobol;

use DateTimeImmutable;
use RuntimeException;

/**
 * Diagnostico de intereses basado en GIMB38.
 * NO utilizar para publicar saldos exigibles.
 */
final class Gimb38InteresDiagnostico
{
    private function __construct(
        private readonly array $tasas,
        private readonly int $ivaBase,
        private readonly int $sobreBase,
    ) {}

    public static function desdeDirectorio(string $ruta): self
    {
        $ruta = rtrim($ruta, '/').'/';

        $filas = @file($ruta.'INQTASAINT', FILE_IGNORE_NEW_LINES);
        $iva = @file_get_contents($ruta.'INQIVA');
        $redondeo = @file_get_contents($ruta.'INQREDONDE');

        if (!is_array($filas) || !$filas || count($filas) > 120 ||
            !is_string($iva) ||
            !preg_match('/^(\d{5})(\d{5})/', $iva, $m) ||
            !is_string($redondeo) ||
            !preg_match('/^(\d{6})/', $redondeo, $r)) {
            throw new RuntimeException(
                'Parametros COBOL incompletos o invalidos.'
            );
        }

        if ((int)$r[1] !== 1) {
            throw new RuntimeException(
                'Redondeo distinto de 1: aun no soportado.'
            );
        }

        $tasas = [];

        foreach ($filas as $n => $fila) {
            if (!preg_match('/^\d{6}(?:\d{6}[+-]){5}$/D', $fila) ||
                strlen($fila) !== 41) {
                throw new RuntimeException(
                    'Formato incorrecto INQTASAINT fila '.($n + 1)
                );
            }

            if ($fila[12] !== '+') {
                throw new RuntimeException(
                    'Tasa negativa: sin soporte diagnostico.'
                );
            }

            $tasas[] = [
                'periodo' => substr($fila, 0, 6),
                'milesimas' => (int)substr($fila, 6, 6),
            ];
        }

        return new self($tasas, (int)$m[1], (int)$m[2]);
    }

    public function calcular(
        int $capital,
        string $vence,
        string $corte,
        int $tipoIva
    ): array {
        if ($capital < 0 || !in_array($tipoIva, [0, 1, 2, 3, 4, 5, 6], true)) {
            throw new RuntimeException('Capital o TIPOIVA incorrecto.');
        }

        $v = self::fecha($vence);
        $c = self::fecha($corte);

        $diaV = min(30, (int)$v->format('d'));
        $diaC = min(30, (int)$c->format('d'));

        $d = (
            (int)$c->format('Y') * 360 +
            (int)$c->format('m') * 30 +
            $diaC
        ) - (
            (int)$v->format('Y') * 360 +
            (int)$v->format('m') * 30 +
            $diaV
        );

        $tramos = [];

        if ($d > 0 && $capital > 0) {
            if ($d <= $diaC - $diaV) {
                $tramos = [$d];
            } else {
                $resto = $d;
                $tamano = 30 - $diaV;

                do {
                    $resto -= $tamano;

                    if ($resto >= 0) {
                        $tramos[] = $tamano;
                        $tamano = 30;
                    } else {
                        $tramos[] = 30 + $resto;
                        break;
                    }
                } while (count($tramos) <= 120);
            }
        }

        if (count($tramos) > count($this->tasas) ||
            count($tramos) > 120) {
            throw new RuntimeException(
                'Faltan tasas para los tramos de intereses.'
            );
        }

        $interes = 0;
        $detalle = [];
        $tope = count($tramos);

        foreach ($tramos as $i => $dias) {
            // GIMB38 selecciona las tasas por posicion inversa.
            $pos = $tope - $i;
            $tasa = $this->tasas[$pos - 1];

            $producto = $capital * $tasa['milesimas'] * $dias;

            if (!is_int($producto)) {
                throw new RuntimeException(
                    'Desbordamiento de importe.'
                );
            }

            // SUMA COBOL: tres decimales.
            $sumaMilesimas = self::redondear(
                $producto,
                300000
            );

            // WIMPORT COBOL: dos decimales.
            $interes = self::redondear(
                $interes * 10 + $sumaMilesimas,
                10
            );

            $detalle[] = [
                'dias' => $dias,
                'fila' => $pos,
                'periodo' => $tasa['periodo'],
                'tasa_milesimas' => $tasa['milesimas'],
            ];
        }

        $iva = self::redondear(
            $interes * $this->ivaBase,
            10000
        );

        $sobre = $tipoIva === 2
            ? self::redondear($iva * $this->sobreBase, 10000)
            : 0;

        return [
            'capital' => $capital,
            'dias' => max(0, $d),
            'interes' => $interes,
            'iva' => $iva,
            'sobretasa' => $sobre,
            'adicional' => $interes + $iva + $sobre,
            'tramos' => $detalle,
        ];
    }

    private static function fecha(string $valor): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $valor
        );

        if (!$d || $d->format('Y-m-d') !== $valor) {
            throw new RuntimeException(
                'Fecha invalida: '.$valor
            );
        }

        return $d;
    }

    private static function redondear(
        int $numerador,
        int $denominador
    ): int {
        if ($numerador < 0 || $denominador <= 0) {
            throw new RuntimeException(
                'Importe negativo o divisor invalido.'
            );
        }

        return intdiv(
            $numerador + intdiv($denominador, 2),
            $denominador
        );
    }
}
