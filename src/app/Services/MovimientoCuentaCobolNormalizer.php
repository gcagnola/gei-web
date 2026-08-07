<?php

namespace App\Services;

use DateTimeImmutable;

final class MovimientoCuentaCobolNormalizer
{
    /** @return array<string, mixed> */
    public function propietario(object $fila): array
    {
        return $this->normalizar($fila, 'PROPIETARIO');
    }

    /** @return array<string, mixed> */
    public function inquilino(object $fila): array
    {
        return $this->normalizar($fila, 'INQUILINO');
    }

    /** @return array<string, mixed> */
    private function normalizar(object $fila, string $dominio): array
    {
        $cuenta = $this->digitos($fila->cuenta ?? null);
        $fechaOrigen = $this->digitos($fila->fecha ?? null);
        $codigoDigitos = $this->digitos($fila->codigo ?? null);
        $numeroDigitos = $this->digitos($fila->numero ?? null);
        $codigo = $codigoDigitos === '' ? '' : str_pad($codigoDigitos, 2, '0', STR_PAD_LEFT);
        $numero = $numeroDigitos === '' ? '' : str_pad($numeroDigitos, 6, '0', STR_PAD_LEFT);
        $fecha = $this->fechaCobol($fechaOrigen);
        $importe = $this->decimalConSigno($fila->importe ?? null, 2) ?? '0.00';
        $esDebito = $dominio === 'INQUILINO'
            ? ((int) $codigo <= 50)
            : ((int) $codigo >= 21);
        $afectaSaldo = ! ($dominio === 'PROPIETARIO' && $codigo === '38');
        $fechaVencimientoOrigen = $dominio === 'INQUILINO'
            ? $this->digitos($fila->fecha_vencimiento ?? null)
            : '';
        $advertencias = [];

        if ($fecha === null && $fechaOrigen !== '' && ! preg_match('/^0+$/', $fechaOrigen)) {
            $advertencias['FECHA_MOVIMIENTO_INVALIDA'] = ['valor' => $fechaOrigen];
        }
        if (
            $fechaVencimientoOrigen !== ''
            && ! preg_match('/^0+$/', $fechaVencimientoOrigen)
            && $this->fechaCobol($fechaVencimientoOrigen) === null
        ) {
            $advertencias['FECHA_VENCIMIENTO_INVALIDA'] = ['valor' => $fechaVencimientoOrigen];
        }

        return [
            'dominio' => $dominio,
            'cuenta' => $cuenta,
            'fecha' => $fecha,
            'fecha_origen' => $fechaOrigen,
            'periodo' => $fecha === null ? null : substr(str_replace('-', '', $fecha), 0, 6),
            'codigo' => $codigo,
            'numero' => $numero,
            'fecha_vencimiento' => $this->fechaCobol($fechaVencimientoOrigen),
            'fecha_vencimiento_origen' => $fechaVencimientoOrigen === '' ? null : $fechaVencimientoOrigen,
            'importe' => $importe,
            'debe' => $afectaSaldo && $esDebito ? $importe : '0.00',
            'haber' => $afectaSaldo && ! $esDebito ? $importe : '0.00',
            'tipo_movimiento' => ! $afectaSaldo ? 'SIN_EFECTO' : ($esDebito ? 'DEBITO' : 'CREDITO'),
            'importe_penalidad' => $dominio === 'INQUILINO' ? $this->decimalConSigno($fila->importe_penalidad ?? null, 2) : null,
            'importe_abonado' => $dominio === 'INQUILINO' ? $this->decimalConSigno($fila->importe_abonado ?? null, 2) : null,
            'iva' => $this->decimalImplicito($fila->iva ?? null, 2),
            'no_gravado' => $this->decimalImplicito($fila->no_iva ?? null, 2),
            'descripcion' => $this->texto($fila->descripcion ?? null),
            'cuenta_inquilino_referencia' => $dominio === 'PROPIETARIO'
                ? $this->cuentaNoCero($fila->inquilino ?? null)
                : null,
            'liquidado_origen' => $this->texto($fila->liquidado ?? null),
            'afecta_saldo' => $afectaSaldo,
            'clave_origen' => hash('sha256', implode('|', [
                'COBOL', $dominio, $cuenta, $fechaOrigen, $codigo, $numero,
            ])),
            'archivo_origen_id' => isset($fila->archivo_id) ? (int) $fila->archivo_id : null,
            'numero_linea' => isset($fila->numero_linea) ? (int) $fila->numero_linea : null,
            'hash_origen' => $this->texto($fila->sha256_registro ?? null),
            'advertencias' => $advertencias,
            'datos_origen' => [
                'filler' => $this->texto($fila->filler ?? null),
                'longitud_linea' => isset($fila->longitud_linea) ? (int) $fila->longitud_linea : null,
            ],
        ];
    }

    public function fechaCobol(mixed $valor): ?string
    {
        $texto = $this->digitos($valor);
        if (strlen($texto) !== 8 || preg_match('/^0+$/', $texto)) {
            return null;
        }
        $fecha = DateTimeImmutable::createFromFormat('!Ymd', $texto);
        $errores = DateTimeImmutable::getLastErrors();
        if ($fecha === false || (is_array($errores) && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))) {
            return null;
        }

        return $fecha->format('Y-m-d');
    }

    public function decimalConSigno(mixed $valor, int $decimales): ?string
    {
        $texto = trim((string) $valor);
        if ($texto === '') {
            return null;
        }
        $signo = str_ends_with($texto, '-') ? '-' : '';
        $numero = $this->decimalImplicito($texto, $decimales);

        return $numero === null ? null : $signo.$numero;
    }

    public function decimalImplicito(mixed $valor, int $decimales): ?string
    {
        $digitos = $this->digitos($valor);
        if ($digitos === '') {
            return null;
        }
        $digitos = str_pad($digitos, $decimales + 1, '0', STR_PAD_LEFT);

        return substr($digitos, 0, -$decimales).'.'.substr($digitos, -$decimales);
    }

    private function digitos(mixed $valor): string
    {
        return preg_replace('/\D+/', '', (string) $valor) ?? '';
    }

    private function cuentaNoCero(mixed $valor): ?string
    {
        $cuenta = $this->digitos($valor);

        return $cuenta === '' || preg_match('/^0+$/', $cuenta) ? null : $cuenta;
    }

    private function texto(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
