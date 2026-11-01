<?php

namespace App\Services;

/**
 * La cuenta COBOL identifica una relación/origen, no una propiedad física.
 * Los candidatos de un mismo domicilio no se unifican si ya tienen IDs distintos.
 */
final class IdentidadOperativaInmuebleResolver
{
    public function __construct(private readonly InmuebleCobolNormalizer $normalizer)
    {
    }

    public function sede(array $datos): ?string
    {
        $prop = match (substr((string) ($datos['cuenta_propietario'] ?? ''), 0, 4)) {
            '1202' => 'SF', '2202' => 'ST', default => null,
        };
        $inq = match (substr((string) ($datos['cuenta_inquilino'] ?? ''), 0, 4)) {
            '1103' => 'SF', '2103' => 'ST', default => null,
        };

        return $prop !== null && $prop === $inq ? $prop : null;
    }

    public function claveFisica(array $datos): ?string
    {
        $sede = $this->sede($datos);
        $domicilio = $this->normalizer->direccion($datos['direccion_normalizada'] ?? '');

        return $sede !== null && $domicilio !== '' ? $sede.'|'.$domicilio : null;
    }

    public function claveMigracionFisica(array $datos): ?string
    {
        $clave = $this->claveFisica($datos);

        return $clave === null ? null : hash('sha256', 'GEI-INMUEBLE-FISICO|'.$clave);
    }

    /**
     * @param array<string, list<int>> $candidatosPorDomicilio IDs canónicos, no cuentas.
     * @param array<int, list<string>> $partidasPorInmueble Partidas vigentes conocidas.
     * @return array{estado:string, id:?int, candidatos:list<int>}
     */
    public function resolver(
        array $datos,
        array $candidatosPorDomicilio,
        array $partidasPorInmueble = []
    ): array {
        $clave = $this->claveFisica($datos);
        if ($clave === null) {
            return ['estado' => 'SIN_IDENTIDAD', 'id' => null, 'candidatos' => []];
        }
        $ids = array_values(array_unique(array_map('intval', $candidatosPorDomicilio[$clave] ?? [])));
        sort($ids, SORT_NUMERIC);
        if (count($ids) > 1) {
            return ['estado' => 'DOMICILIO_CON_VARIOS_INMUEBLES', 'id' => null, 'candidatos' => $ids];
        }
        if ($ids === []) {
            return ['estado' => 'NUEVO', 'id' => null, 'candidatos' => []];
        }
        $actuales = array_values(array_unique(array_filter(array_map('strval', $datos['partidas'] ?? []))));
        $conocidas = array_values(array_unique(array_filter(array_map('strval', $partidasPorInmueble[$ids[0]] ?? []))));
        if ($actuales !== [] && $conocidas !== [] && array_intersect($actuales, $conocidas) === []) {
            return ['estado' => 'PARTIDAS_CONTRADICTORIAS', 'id' => null, 'candidatos' => $ids];
        }

        return ['estado' => 'EXISTENTE', 'id' => $ids[0], 'candidatos' => $ids];
    }
}
