<?php

namespace App\Support\Cobol;

final class Gimb68Saldos
{
    /**
     * Reproduce los acumuladores de GIMB68:
     * código <= 50: débito; código > 50: crédito.
     * No aplica IMPABONA: esto es saldo contable, no liquidación.
     *
     * @param array<int,array<string,mixed>> $movimientos
     * @return array<string,mixed>
     */
    public static function calcular(array $movimientos, string $periodo): array
    {
        usort($movimientos, static fn (array $a, array $b): int =>
            strcmp(
                ($a['fecha_origen'] ?? '').($a['codigo'] ?? '').($a['numero'] ?? ''),
                ($b['fecha_origen'] ?? '').($b['codigo'] ?? '').($b['numero'] ?? '')
            )
        );

        $debitosAnteriores = 0;
        $creditosAnteriores = 0;
        $saldo = 0;
        $filas = [];

        foreach ($movimientos as $m) {
            $fecha = (string) ($m['fecha_origen'] ?? '');
            $codigo = (string) ($m['codigo'] ?? '');
            $importe = (string) ($m['importe'] ?? '');

            if (! preg_match('/^[0-9]{8}$/D', $fecha)
                || ! preg_match('/^[0-9]{2}$/D', $codigo)
                || ! preg_match('/^(-?)([0-9]+)\.([0-9]{2})$/D', $importe, $partes)) {
                throw new \RuntimeException('Movimiento COBOL inválido para calcular saldos.');
            }

            $centavos = ((int) $partes[2]) * 100 + (int) $partes[3];
            if ($partes[1] === '-') {
                $centavos = -$centavos;
            }

            $esDebito = (int) $codigo <= 50;
            $variacion = $esDebito ? $centavos : -$centavos;

            if ($periodo !== '' && substr($fecha, 0, 6) < $periodo) {
                if ($esDebito) {
                    $debitosAnteriores += $centavos;
                } else {
                    $creditosAnteriores += $centavos;
                }
                $saldo += $variacion;
                continue;
            }

            if ($periodo !== '' && substr($fecha, 0, 6) !== $periodo) {
                continue;
            }

            // GIMB68: separar el IVA para mostrar las mismas líneas
            // que COBOL. El importe original ya incluye el IVA.
            // Los acumulados anteriores conservan el importe bruto.
            $detalle = [[$m, $centavos]];

            if (($m['dominio'] ?? '') === 'INQUILINO') {
                $aCentavos = static function (mixed $valor): int {
                    $texto = trim((string) $valor);

                    if ($texto === '') {
                        return 0;
                    }

                    if (! preg_match('/^([0-9]+)\.([0-9]{2})$/D', $texto, $n)) {
                        throw new \RuntimeException(
                            'Componente IVA COBOL inválido.'
                        );
                    }

                    return ((int) $n[1]) * 100 + (int) $n[2];
                };

                $iva = $aCentavos($m['iva'] ?? '0.00');
                $adicional = $aCentavos($m['no_gravado'] ?? '0.00');

                if ($iva > 0 && $centavos >= $iva + $adicional) {
                    $detalle = [[$m, $centavos - $iva - $adicional]];

                    $lineaIva = $m;
                    $lineaIva['codigo'] = $esDebito ? '46' : '97';
                    $lineaIva['descripcion'] = 'I.V.A.';
                    $lineaIva['linea_virtual_cobol'] = true;

                    $detalle[] = [$lineaIva, $iva];

                    if ($adicional > 0) {
                        $lineaAdicional = $m;
                        $lineaAdicional['codigo'] = $esDebito ? '47' : '98';
                        $lineaAdicional['descripcion'] = 'IVA adicional';
                        $lineaAdicional['linea_virtual_cobol'] = true;

                        $detalle[] = [$lineaAdicional, $adicional];
                    }
                }
            }

            foreach ($detalle as [$fila, $parteCentavos]) {
                $fila['importe_bruto_cobol'] = $m['importe'];

                $fila['importe'] = sprintf(
                    '%d.%02d',
                    intdiv($parteCentavos, 100),
                    $parteCentavos % 100
                );

                $fila['debe'] = $esDebito
                    ? $fila['importe'] : '0.00';

                $fila['haber'] = $esDebito
                    ? '0.00' : $fila['importe'];

                $fila['iva'] = '0.00';
                $fila['no_gravado'] = '0.00';

                $saldo += $esDebito
                    ? $parteCentavos : -$parteCentavos;

                $fila['saldo_centavos'] = $saldo;

                $filas[] = $fila;
            }
        }

        return [
            'movimientos' => $filas,
            'debitos_anteriores' => $debitosAnteriores,
            'creditos_anteriores' => $creditosAnteriores,
            'saldo_anterior' => $debitosAnteriores - $creditosAnteriores,
            'saldo_actual' => $saldo,
        ];
    }
}
