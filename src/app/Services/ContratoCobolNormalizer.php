<?php

namespace App\Services;

use DateTimeImmutable;

final class ContratoCobolNormalizer
{
    /** @return array<string, mixed> */
    public function inquilino(object $fila): array
    {
        $cuentaInquilino = $this->cuenta($fila->cta_inquilino ?? null);
        $fechaContrato = $this->fecha($fila->fecha_contrato ?? null);
        $fechaFin = $this->fecha($fila->fecha_vencimiento ?? null);
        $fechaInicio = $this->fecha($fila->fecha_inicio ?? null);
        $fechaCelebracion = $this->fecha($fila->fecha_celebracion_redefine ?? null);
        $advertencias = [];

        if ($this->tieneValor($fila->fecha_vencimiento ?? null) && $fechaFin === null) {
            $advertencias['FECHA_VENCIMIENTO_INVALIDA'] = [
                'valor' => trim((string) $fila->fecha_vencimiento),
            ];
        }
        if ($fechaContrato !== null && $fechaFin !== null && $fechaContrato > $fechaFin) {
            $advertencias['FECHAS_CONTRATO_INVERTIDAS'] = [
                'fecha_contrato' => $fechaContrato,
                'fecha_vencimiento' => $fechaFin,
            ];
            // Se conserva el original en la trazabilidad, pero no se persiste
            // una vigencia imposible en columnas tipadas.
            $fechaFin = null;
        }
        if ($fechaInicio !== null && $fechaFin !== null && $fechaInicio > $fechaFin) {
            $advertencias['FECHAS_VIGENCIA_INVERTIDAS'] = [
                'fecha_inicio' => $fechaInicio,
                'fecha_vencimiento' => $fechaFin,
            ];
            $fechaInicio = null;
        }

        $ajustes = [];
        foreach (range(1, 8) as $posicion) {
            $fechaOriginal = $fila->{'ajuste_'.$posicion.'_fecha'} ?? null;
            $porcentajeOriginal = $fila->{'ajuste_'.$posicion.'_porcentaje'} ?? null;
            $fecha = $this->fecha($fechaOriginal);
            $porcentaje = $this->decimalConSigno($porcentajeOriginal, 1);
            if ($fecha !== null || $porcentaje !== null) {
                $ajustes[] = [
                    'posicion' => $posicion,
                    'fecha' => $fecha,
                    'porcentaje' => $porcentaje,
                    'fecha_origen' => $this->texto($fechaOriginal),
                    'porcentaje_origen' => $this->texto($porcentajeOriginal),
                ];
            }
        }

        $impuestos = [];
        // IMPUESTOS y OTROS-DATOS ocupan los mismos 18 bytes en COBOL.
        // Sólo se interpretan como impuestos cuando no forman una fecha válida.
        if ($fechaCelebracion === null) {
            foreach (range(1, 6) as $posicion) {
                $valor = $this->entero($fila->{'impuesto_porcentaje_'.$posicion} ?? null);
                if ($valor !== null) {
                    $impuestos[$posicion] = $valor;
                }
            }
        }

        $estado = strtoupper(trim((string) ($fila->marca_baja ?? ''))) === 'B'
            ? 'BAJA'
            : 'ACTIVO';

        return [
            'cuenta_inquilino' => $cuentaInquilino,
            'cuenta_propietario' => $this->cuenta($fila->cta_propietario ?? null),
            'clave_contrato' => $cuentaInquilino === ''
                ? ''
                : hash('sha256', 'COBOL|INQUILINO|'.$cuentaInquilino),
            'fecha_contrato' => $fechaContrato,
            'fecha_celebracion' => $fechaCelebracion,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'fecha_primer_ajuste' => $this->fecha($fila->fecha_primer_ajuste ?? null),
            'fecha_baja' => $this->fecha($fila->fecha_baja ?? null),
            'plazo_meses' => $this->entero($fila->plazo ?? null),
            'plazo_dias' => $this->entero($fila->plazo_dias ?? null),
            'indice_ajuste' => $this->texto($fila->indice ?? null),
            'tipo_ajuste' => $this->texto($fila->tipo_ajuste ?? null),
            'cuota_1' => $this->decimalImplicito($fila->cuota_1 ?? null, 2),
            'cuota_2' => $this->decimalImplicito($fila->cuota_2 ?? null, 2),
            'cuota_2_dolar' => $this->decimalImplicito($fila->cuota_2_dolar ?? null, 2),
            'alquiler_inicial' => $this->decimalImplicito($fila->alquiler_inicial ?? null, 2),
            // La columna filler de staging corresponde a COTI-DOLAR PIC 9(3)V99.
            'cotizacion_dolar' => $this->decimalImplicito($fila->filler ?? null, 2),
            'administracion_responsable' => $this->booleanoSN($fila->administracion_responsable ?? null),
            'destino_codigo' => $this->textoNoCero($fila->destino ?? null),
            'penalidad_porcentaje' => $this->decimalImplicito($fila->penal_porcentaje ?? null, 0),
            'penalidad_importe' => $this->decimalImplicito($fila->penal_importe ?? null, 2),
            'acumulado_penalidad' => $this->decimalImplicito($fila->acumulado_penalidad ?? null, 2),
            'comision_anterior' => $this->decimalImplicito($fila->comision_anterior ?? null, 0),
            'comision_impuestos' => $this->decimalImplicito($fila->comision_importe ?? null, 2),
            'reparacion' => $this->booleanoSN($fila->reparacion ?? null),
            'dias_reparacion' => $this->entero($fila->dias_reparacion ?? null),
            'fecha_juicio' => $this->fecha($fila->fecha_juicio ?? null),
            'abogado_codigo' => $this->textoNoCero($fila->abogado ?? null),
            'marca_intimacion' => $this->texto($fila->marca_intimacion_1 ?? null)
                ?? $this->texto($fila->marca_intimacion ?? null),
            'estado' => $estado,
            'activo' => $estado === 'ACTIVO',
            'ajustes_adicionales' => $ajustes,
            'impuestos_porcentajes' => $impuestos,
            'copias_contrato' => $fechaCelebracion === null
                ? null
                : $this->entero($fila->copias_contrato_redefine ?? null),
            'advertencias' => $advertencias,
            'archivo_origen_id' => isset($fila->archivo_id) ? (int) $fila->archivo_id : null,
            'numero_linea' => isset($fila->numero_linea) ? (int) $fila->numero_linea : null,
            'hash_origen' => $this->texto($fila->sha256_registro ?? null),
            'datos_originales' => [
                'nombre_inquilino' => $this->texto($fila->nombre_inquilino ?? null),
                'direccion_finca' => $this->texto($fila->direccion_finca ?? null),
                'fecha_contrato' => $this->texto($fila->fecha_contrato ?? null),
                'fecha_vencimiento' => $this->texto($fila->fecha_vencimiento ?? null),
                'fecha_inicio' => $this->texto($fila->fecha_inicio ?? null),
                'fecha_celebracion_redefine' => $this->texto($fila->fecha_celebracion_redefine ?? null),
                'marca_baja' => $this->texto($fila->marca_baja ?? null),
                'nro_liquidacion' => $this->textoNoCero($fila->nro_liquidacion ?? null),
                'tipo_documento' => $this->textoNoCero($fila->tipo_documento ?? null),
                'nro_documento' => $this->textoNoCero($fila->nro_documento ?? null),
            ],
        ];
    }

    public function fecha(mixed $valor): ?string
    {
        $texto = preg_replace('/\D+/', '', (string) $valor) ?? '';
        if ($texto === '' || preg_match('/^0+$/', $texto) || strlen($texto) !== 8) {
            return null;
        }

        $fecha = DateTimeImmutable::createFromFormat('!dmY', $texto);
        $errores = DateTimeImmutable::getLastErrors();
        if ($fecha === false || (is_array($errores) && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))) {
            return null;
        }

        return $fecha->format('Y-m-d');
    }

    public function decimalImplicito(mixed $valor, int $decimales): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string) $valor) ?? '';
        if ($digitos === '' || preg_match('/^0+$/', $digitos)) {
            return null;
        }

        $entero = ltrim($digitos, '0');
        $entero = $entero === '' ? '0' : $entero;
        if ($decimales === 0) {
            return $entero.'.000';
        }
        $entero = str_pad($entero, $decimales + 1, '0', STR_PAD_LEFT);

        return substr($entero, 0, -$decimales).'.'.substr($entero, -$decimales);
    }

    private function decimalConSigno(mixed $valor, int $decimales): ?string
    {
        $texto = trim((string) $valor);
        $signo = str_ends_with($texto, '-') ? '-' : '';
        $numero = $this->decimalImplicito($texto, $decimales);

        return $numero === null ? null : $signo.$numero;
    }

    private function cuenta(mixed $valor): string
    {
        return preg_replace('/\D+/', '', (string) $valor) ?? '';
    }

    private function entero(mixed $valor): ?int
    {
        $digitos = preg_replace('/\D+/', '', (string) $valor) ?? '';

        return $digitos === '' || preg_match('/^0+$/', $digitos) ? null : (int) $digitos;
    }

    private function booleanoSN(mixed $valor): ?bool
    {
        return match (strtoupper(trim((string) $valor))) {
            'S' => true,
            'N' => false,
            default => null,
        };
    }

    private function texto(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function textoNoCero(mixed $valor): ?string
    {
        $texto = $this->texto($valor);

        return $texto === null || preg_match('/^0+$/', $texto) ? null : $texto;
    }

    private function tieneValor(mixed $valor): bool
    {
        $texto = trim((string) $valor);

        return $texto !== '' && ! preg_match('/^0+$/', $texto);
    }
}
