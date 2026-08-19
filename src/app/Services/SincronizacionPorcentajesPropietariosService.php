<?php

namespace App\Services;

use RuntimeException;

/**
 * @deprecated El porcentaje de REPARTO no representa titularidad del inmueble.
 */
final class SincronizacionPorcentajesPropietariosService
{
    public function ejecutar(mixed ...$argumentos): never
    {
        throw new RuntimeException(
            'Este sincronizador fue deshabilitado. Usá gei:sincronizar-repartos-propietarios; '.
            'el reparto de cobro ya no se escribe en inmuebles_propietarios.porcentaje.'
        );
    }
}
