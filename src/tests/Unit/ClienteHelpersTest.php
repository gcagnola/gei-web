<?php

namespace Tests\Unit;

use App\Models\Inmueble;
use App\Services\NormalizadorDocumentoCliente;
use PHPUnit\Framework\TestCase;

class ClienteHelpersTest extends TestCase
{
    public function test_normaliza_documentos_y_formatea_cuit(): void
    {
        $this->assertSame(
            '12345678',
            NormalizadorDocumentoCliente::documento('DNI', '12.345 678')
        );
        $this->assertSame(
            '30-71870630-7',
            NormalizadorDocumentoCliente::cuitFormateado('30718706307')
        );
    }

    public function test_construye_domicilio_heredado_sin_repetir_partes(): void
    {
        $inmueble = new Inmueble([
            'codigo_inmueble' => 1,
            'domicilio_calle' => 'San Martín 1234',
            'domicilio_nro' => '',
            'domicilio_edificio' => '',
            'domicilio_piso' => '2',
            'domicilio_dpto' => 'A',
            'localidad' => 'Santa Fe',
        ]);

        $this->assertSame(
            'San Martín 1234, 2, A, Santa Fe',
            $inmueble->domicilio_visible
        );
    }
}
