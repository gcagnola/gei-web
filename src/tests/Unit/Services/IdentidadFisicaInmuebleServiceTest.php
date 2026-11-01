<?php

namespace Tests\Unit\Services;

use App\Services\IdentidadFisicaInmuebleService;
use App\Services\InmuebleCobolNormalizer;
use PHPUnit\Framework\TestCase;

final class IdentidadFisicaInmuebleServiceTest extends TestCase
{
    private function service(): IdentidadFisicaInmuebleService
    {
        return new IdentidadFisicaInmuebleService(new InmuebleCobolNormalizer());
    }

    private function fila(string $prop, string $inq, string $direccion, array $partidas = []): array
    {
        return [
            'cuenta_propietario' => $prop,
            'cuenta_inquilino' => $inq,
            'direccion_normalizada' => $direccion,
            'partidas' => $partidas,
        ];
    }

    public function test_julio_es_base_y_agosto_registra_cambio_de_cuenta_sin_nueva_unidad(): void
    {
        $s = $this->service();
        $julio = $s->incorporarPeriodo('202607', [
            $this->fila('12020881208', '11030000101', '1RO.DE MAYO 1498'),
        ]);
        $agosto = $s->incorporarPeriodo('202608', [
            $this->fila('12020423104', '11030000101', '1RO.DE MAYO 1498'),
        ]);

        self::assertSame(1, $julio['unidades_nuevas']);
        self::assertSame(0, $agosto['unidades_nuevas']);
        self::assertSame(1, $agosto['unidades_existentes']);
        self::assertSame(1, $agosto['cuentas_propietario_nuevas']);
        self::assertCount(1, $s->unidades());
        $unidades = $s->unidades();
        self::assertCount(2, reset($unidades)['cuentas_propietarios']);
    }

    public function test_unidades_diferentes_y_sedes_diferentes_no_se_mezclan(): void
    {
        $s = $this->service();
        $julio = $s->incorporarPeriodo('202607', [
            $this->fila('12020881208', '11030000101', 'SAN MARTIN 123 DTO 1'),
            $this->fila('12020881208', '11030000202', 'SAN MARTIN 123 DTO 2'),
            $this->fila('22020881208', '21030000101', 'SAN MARTIN 123 DTO 1'),
        ]);
        self::assertSame(3, $julio['unidades_nuevas']);
    }

    public function test_cuentas_de_sedes_contradictorias_no_se_identifican(): void
    {
        $s = $this->service();
        $r = $s->incorporarPeriodo('202607', [
            $this->fila('12020881208', '21030000101', 'SAN MARTIN 123'),
        ]);
        self::assertSame(1, $r['registros_sin_identidad_inequivoca']);
        self::assertSame(0, $r['unidades_nuevas']);
    }

    public function test_mismo_domicilio_con_partidas_disjuntas_no_se_fusiona(): void
    {
        $s = $this->service();
        $r = $s->incorporarPeriodo('202607', [
            $this->fila('12020881208', '11030000101', 'EDIFICIO 123', [1 => '111']),
            $this->fila('12020423104', '11030000202', 'EDIFICIO 123', [1 => '222']),
        ]);
        self::assertSame(1, $r['unidades_a_revisar']);
        self::assertSame(0, $r['unidades_nuevas']);
    }

    public function test_partida_contradictoria_en_otro_mes_bloquea_actualizacion(): void
    {
        $s = $this->service();
        $s->incorporarPeriodo('202607', [
            $this->fila('12020881208', '11030000101', 'LOCAL SIN NUMERO', [1 => '111']),
        ]);
        $agosto = $s->incorporarPeriodo('202608', [
            $this->fila('12020423104', '11030000202', 'LOCAL SIN NUMERO', [1 => '222']),
        ]);
        self::assertSame(1, $agosto['unidades_a_revisar']);
        self::assertSame(0, $agosto['unidades_existentes']);
        $unidades = $s->unidades();
        self::assertCount(1, reset($unidades)['cuentas_propietarios']);
    }

    public function test_dos_cuentas_inquilinas_activas_son_dos_contratos_no_dos_inmuebles(): void
    {
        $servicio = $this->service();
        $resumen = $servicio->incorporarPeriodo('202607', [
            $this->fila('12020881208', '11030000101', 'EDIFICIO SIN UNIDAD'),
            $this->fila('12020423104', '11030000202', 'EDIFICIO SIN UNIDAD'),
        ]);

        self::assertSame(0, $resumen['unidades_a_revisar']);
        self::assertSame(1, $resumen['domicilios_con_contratos_simultaneos']);
        self::assertSame(1, $resumen['unidades_nuevas']);
        self::assertSame(0, $resumen['unidades_existentes']);
        self::assertCount(1, $servicio->unidades());
    }

    public function test_inquilino_de_baja_y_otro_activo_no_acreditan_simultaneidad(): void
    {
        $servicio = $this->service();
        $baja = $this->fila('12020881208', '11030000101', 'UNA SOLA UNIDAD');
        $baja['estado_origen'] = 'BAJA';
        $activo = $this->fila('12020423104', '11030000202', 'UNA SOLA UNIDAD');
        $activo['estado_origen'] = 'ACTIVO';
        $resumen = $servicio->incorporarPeriodo('202607', [$baja, $activo]);

        self::assertSame(0, $resumen['unidades_a_revisar']);
        self::assertSame(1, $resumen['unidades_nuevas']);
        self::assertCount(1, $servicio->unidades());
    }

    public function test_periodos_deben_ser_incrementales(): void
    {
        $s = $this->service();
        $s->incorporarPeriodo('202607', []);
        $this->expectException(\InvalidArgumentException::class);
        $s->incorporarPeriodo('202607', []);
    }
}
