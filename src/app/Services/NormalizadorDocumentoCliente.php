<?php

namespace App\Services;

class NormalizadorDocumentoCliente
{
    public static function documento(?string $tipo, mixed $numero): string
    {
        $numero = trim((string) $numero);

        if (in_array($tipo, ['DNI', 'LC', 'LE', 'CUIT'], true)) {
            return preg_replace('/\D+/', '', $numero) ?? '';
        }

        return $numero;
    }

    public static function cuit(mixed $cuit): string
    {
        return preg_replace('/\D+/', '', trim((string) $cuit)) ?? '';
    }

    public static function cuitFormateado(mixed $cuit): string
    {
        $digitos = self::cuit($cuit);

        if (strlen($digitos) !== 11) {
            return $digitos;
        }

        return substr($digitos, 0, 2)
            .'-'.substr($digitos, 2, 8)
            .'-'.substr($digitos, 10, 1);
    }
}
