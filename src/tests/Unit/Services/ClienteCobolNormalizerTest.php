<?php

namespace Tests\Unit\Services;

use App\Services\ClienteCobolNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClienteCobolNormalizerTest extends TestCase
{
    private ClienteCobolNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ClienteCobolNormalizer();
    }

    #[DataProvider('cuits')]
    public function test_normaliza_cuit(string $entrada, ?string $esperado): void
    {
        self::assertSame($esperado, $this->normalizer->cuit($entrada));
    }

    public static function cuits(): array
    {
        return [
            'COBOL con cero inicial' => ['020237580436', '20237580436'],
            'once dígitos' => ['30-68203263-0', '30682032630'],
            'ceros' => ['000000000000', null],
            'inválido' => ['1234', null],
        ];
    }

    #[DataProvider('documentos')]
    public function test_normaliza_documento(string $entrada, ?string $esperado): void
    {
        self::assertSame($esperado, $this->normalizer->documento($entrada));
    }

    public static function documentos(): array
    {
        return [
            'relleno con ceros' => ['006209048', '6209048'],
            'espacios' => ['4155066  ', '4155066'],
            'vacío COBOL' => ['000000000', null],
        ];
    }

    #[DataProvider('tiposDocumento')]
    public function test_mapea_tipo_documento(string $codigo, ?string $esperado): void
    {
        self::assertSame($esperado, $this->normalizer->tipoDocumento($codigo));
    }

    public static function tiposDocumento(): array
    {
        return [
            ['1', 'LE'],
            ['2', 'LC'],
            ['3', 'DNI'],
            ['4', 'CEDULA'],
            ['5', 'PASAPORTE'],
            ['6', null],
            ['', null],
        ];
    }

    public function test_normaliza_cuenta_quitando_barras(): void
    {
        self::assertSame('12020826805', $this->normalizer->cuenta('1202/08268/05'));
    }

    #[DataProvider('tiposPersona')]
    public function test_determina_tipo_persona(
        ?string $cuit,
        ?string $documento,
        string $esperado
    ): void {
        self::assertSame($esperado, $this->normalizer->tipoPersona($cuit, $documento));
    }

    public static function tiposPersona(): array
    {
        return [
            'CUIT persona física' => ['20237580436', null, 'FISICA'],
            'CUIT persona jurídica' => ['30682032630', null, 'JURIDICA'],
            'documento sin CUIT' => [null, '6209048', 'FISICA'],
            'sin identificación' => [null, null, 'DESCONOCIDA'],
            'prefijo no clasificable' => ['11234567890', null, 'DESCONOCIDA'],
        ];
    }

    public function test_propietario_conserva_codigo_iva_y_aplica_mapeo_cobol(): void
    {
        $fila = (object) [
            'nro_cta_prop' => '1202/08268/05',
            'nombre_prop' => 'CLIENTE',
            'tipo_iva' => '3',
            'nro_iva' => '020237580436',
        ];

        $datos = $this->normalizer->propietario($fila);

        self::assertSame('EXENTO', $datos['condicion_iva']);
        self::assertSame('3', $datos['tipo_iva_origen']);
        self::assertSame('020237580436', $datos['nro_iva_origen']);
    }

    public function test_propietario_codigo_iva_cuatro_es_no_responsable(): void
    {
        $datos = $this->normalizer->propietario((object) [
            'nro_cta_prop' => '1202/00001/00',
            'nombre_prop' => 'CLIENTE',
            'tipo_iva' => '4',
        ]);

        self::assertSame('NO_RESPONSABLE', $datos['condicion_iva']);
    }

    public function test_inquilino_conserva_la_cuenta_del_propietario(): void
    {
        $fila = (object) [
            'cta_inquilino' => '3765/09123/20',
            'cta_propietario' => '1202/03763/09',
            'nombre_inquilino' => 'CLIENTE',
        ];

        $datos = $this->normalizer->inquilino($fila);

        self::assertSame('37650912320', $datos['cuenta']);
        self::assertSame('12020376309', $datos['cuenta_propietario']);
    }
}
