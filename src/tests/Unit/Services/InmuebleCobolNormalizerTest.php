<?php

namespace Tests\Unit\Services;

use App\Services\InmuebleCobolNormalizer;
use PHPUnit\Framework\TestCase;

final class InmuebleCobolNormalizerTest extends TestCase
{
    public function test_normaliza_solo_mayusculas_y_espacios(): void
    {
        $normalizer = new InmuebleCobolNormalizer();

        self::assertSame(
            'SAN MARTIN 4041 P.A. DTO-B',
            $normalizer->direccion('  San Martin 4041   P.A.  DTO-B ')
        );
    }

    public function test_no_unifica_signos_que_pueden_identificar_una_unidad(): void
    {
        $normalizer = new InmuebleCobolNormalizer();

        self::assertNotSame(
            $normalizer->direccion('9 DE JULIO 2941 DTO.2'),
            $normalizer->direccion('9 DE JULIO 2941 DTO 2')
        );
    }

    public function test_la_clave_combina_cuenta_propietario_y_direccion(): void
    {
        $normalizer = new InmuebleCobolNormalizer();
        $direccion = $normalizer->direccion('San Martín 1234');

        self::assertSame(
            hash('sha256', '12020376309|'.$direccion),
            $normalizer->claveInmueble('12020376309', $direccion)
        );
        self::assertNotSame(
            $normalizer->claveInmueble('12020376309', $direccion),
            $normalizer->claveInmueble('12020735406', $direccion)
        );
    }

    public function test_normaliza_los_campos_de_inquilino(): void
    {
        $normalizer = new InmuebleCobolNormalizer();
        $fila = (object) [
            'cta_inquilino' => '1202/00001/01',
            'cta_propietario' => '1202/00002/02',
            'direccion_finca' => '  1ra Junta   2507 P.2 ',
            'marca_baja' => 'B',
            'partida_1' => ' 123 ',
            'partida_2' => '000000',
            'archivo_id' => 4,
            'numero_linea' => 25,
            'sha256_registro' => str_repeat('a', 64),
        ];

        $datos = $normalizer->inquilino($fila);

        self::assertSame('12020000101', $datos['cuenta_inquilino']);
        self::assertSame('12020000202', $datos['cuenta_propietario']);
        self::assertSame('1RA JUNTA 2507 P.2', $datos['direccion_normalizada']);
        self::assertSame('BAJA', $datos['estado_origen']);
        self::assertSame([1 => '123'], $datos['partidas']);
    }
}
