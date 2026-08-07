<?php

namespace App\Services;

final class InmuebleCobolNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public function inquilino(object $fila): array
    {
        $cuentaInquilino = $this->cuenta($fila->cta_inquilino ?? null);
        $cuentaPropietario = $this->cuenta($fila->cta_propietario ?? null);
        $direccion = $this->texto($fila->direccion_finca ?? null) ?? '';
        $direccionNormalizada = $this->direccion($direccion);

        $partidas = [];
        foreach (range(1, 6) as $posicion) {
            $campo = 'partida_'.$posicion;
            $partida = $this->texto($fila->{$campo} ?? null);
            if ($partida !== null) {
                $partidas[$posicion] = $partida;
            }
        }

        return [
            'cuenta_inquilino' => $cuentaInquilino,
            'cuenta_propietario' => $cuentaPropietario,
            'direccion_finca' => $direccion,
            'direccion_normalizada' => $direccionNormalizada,
            'clave_inmueble' => $this->claveInmueble($cuentaPropietario, $direccionNormalizada),
            'destino' => $this->texto($fila->destino ?? null),
            'identificador_cochera' => $this->texto($fila->identificador_cochera ?? null),
            'partidas' => $partidas,
            'estado_origen' => strtoupper(trim((string) ($fila->marca_baja ?? ''))) === 'B'
                ? 'BAJA'
                : 'ACTIVO',
            'fecha_baja' => $this->texto($fila->fecha_baja ?? null),
            'archivo_origen_id' => isset($fila->archivo_id) ? (int) $fila->archivo_id : null,
            'numero_linea' => isset($fila->numero_linea) ? (int) $fila->numero_linea : null,
            'hash_origen' => $this->texto($fila->sha256_registro ?? null),
        ];
    }

    public function cuenta(mixed $valor): string
    {
        return preg_replace('/\D+/', '', (string) $valor) ?? '';
    }

    /**
     * Normalización deliberadamente conservadora.
     *
     * No elimina puntos, guiones, barras ni paréntesis: sin una clave específica
     * de INMUSEC.TXT hacerlo podría fusionar unidades distintas.
     */
    public function direccion(mixed $valor): string
    {
        $texto = mb_strtoupper(trim((string) $valor), 'UTF-8');

        return preg_replace('/\s+/u', ' ', $texto) ?? $texto;
    }

    public function claveInmueble(string $cuentaPropietario, string $direccionNormalizada): string
    {
        if ($cuentaPropietario === '' || $direccionNormalizada === '') {
            return '';
        }

        return hash('sha256', $cuentaPropietario.'|'.$direccionNormalizada);
    }

    private function texto(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' || preg_match('/^0+$/', $texto) ? null : $texto;
    }
}
