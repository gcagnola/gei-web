<?php

namespace App\Services;

/** Control informativo: no consulta ARCA ni modifica la identidad. */
final class ValidacionCuitService
{
    public static function evaluar(mixed $cuit, mixed $tipoDocumento = null, mixed $documento = null): array
    {
        $original = trim((string) $cuit);
        if ($original === '') {
            return ['estado' => 'SIN_CUIT', 'clase' => 'secondary', 'texto' => 'SIN CUIT', 'detalle' => 'No informado.'];
        }
        if (! preg_match('/^(?:[0-9]{11}|[0-9]{2}-[0-9]{8}-[0-9])$/D', $original)) {
            return ['estado' => 'FORMATO_INVALIDO', 'clase' => 'danger', 'texto' => 'CUIT INVÁLIDO', 'detalle' => 'El CUIT debe tener 11 dígitos.'];
        }
        $numero = str_replace('-', '', $original);
        $suma = 0;
        foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $peso) {
            $suma += (int) $numero[$i] * $peso;
        }
        $dv = (11 - $suma % 11) % 11;
        if ($dv !== (int) $numero[10] || preg_match('/^([0-9])\1{10}$/D', $numero)) {
            return ['estado' => 'DV_INVALIDO', 'clase' => 'danger', 'texto' => 'CUIT INVÁLIDO', 'detalle' => 'Dígito verificador incorrecto o numeración inválida.'];
        }
        $dni = preg_replace('/[.\s]/', '', trim((string) $documento));
        if (strtoupper(trim((string) $tipoDocumento)) === 'DNI' && preg_match('/^[0-9]{1,8}$/D', $dni)
            && ltrim(substr($numero, 2, 8), '0') !== ltrim($dni, '0')) {
            return ['estado' => 'DNI_NO_COINCIDE', 'clase' => 'warning', 'texto' => 'DNI NO COINCIDE', 'detalle' => 'Dígito verificador correcto, pero los ocho dígitos centrales no coinciden con el DNI.'];
        }
        return ['estado' => 'VALIDO', 'clase' => 'success', 'texto' => 'CUIT VÁLIDO', 'detalle' => 'Formato y dígito verificador correctos. No verificado contra ARCA.'];
    }
}
