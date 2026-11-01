<?php

namespace Tests\Unit\Services;

use App\Services\IdentidadOperativaInmuebleResolver;
use App\Services\InmuebleCobolNormalizer;
use PHPUnit\Framework\TestCase;

final class IdentidadOperativaInmuebleResolverTest extends TestCase
{
    private function resolver(): IdentidadOperativaInmuebleResolver
    {
        return new IdentidadOperativaInmuebleResolver(new InmuebleCobolNormalizer());
    }

    private function datos(string $propietario = '12020881208', string $inquilino = '11030000101'): array
    {
        return [
            'cuenta_propietario' => $propietario,
            'cuenta_inquilino' => $inquilino,
            'direccion_normalizada' => '1RO.DE MAYO 1498',
            'partidas' => [],
        ];
    }

    public function test_la_clave_fisica_no_cambia_con_propietario_o_inquilino(): void
    {
        $s = $this->resolver();
        self::assertSame($s->claveFisica($this->datos()), $s->claveFisica($this->datos('12020423104', '11030234303')));
        self::assertSame($s->claveMigracionFisica($this->datos()), $s->claveMigracionFisica($this->datos('12020423104')));
    }

    public function test_reutiliza_id_unico_aunque_cambien_ambas_cuentas(): void
    {
        $s = $this->resolver();
        $r = $s->resolver($this->datos('12020423104', '11030234303'), ['SF|1RO.DE MAYO 1498' => [3566]]);
        self::assertSame(['estado' => 'EXISTENTE', 'id' => 3566, 'candidatos' => [3566]], $r);
    }

    public function test_no_elige_arbitrariamente_entre_dos_ids_existentes(): void
    {
        $s = $this->resolver();
        $r = $s->resolver($this->datos(), ['SF|1RO.DE MAYO 1498' => [5473, 3566]]);
        self::assertSame('DOMICILIO_CON_VARIOS_INMUEBLES', $r['estado']);
        self::assertSame([3566, 5473], $r['candidatos']);
    }

    public function test_no_confunde_una_sede_con_otra(): void
    {
        $s = $this->resolver();
        $r = $s->resolver($this->datos(), ['ST|1RO.DE MAYO 1498' => [33]]);
        self::assertSame('NUEVO', $r['estado']);
    }

    public function test_no_reutiliza_una_partida_incompatible(): void
    {
        $s = $this->resolver();
        $datos = $this->datos();
        $datos['partidas'] = ['222'];
        $r = $s->resolver($datos, ['SF|1RO.DE MAYO 1498' => [3566]], [3566 => ['111']]);
        self::assertSame('PARTIDAS_CONTRADICTORIAS', $r['estado']);
    }

    public function test_rechaza_cuentas_de_sedes_contradictorias(): void
    {
        $r = $this->resolver()->resolver($this->datos('12020881208', '21030000101'), []);
        self::assertSame('SIN_IDENTIDAD', $r['estado']);
    }

    public function test_varios_inquilinos_no_son_varios_inmuebles(): void
    {
        $s = $this->resolver();
        self::assertSame('EXISTENTE', $s->resolver($this->datos('12020881208', '11030000101'), ['SF|1RO.DE MAYO 1498' => [3]])['estado']);
        self::assertSame('EXISTENTE', $s->resolver($this->datos('12020881208', '11030000909'), ['SF|1RO.DE MAYO 1498' => [3]])['estado']);
    }
}
