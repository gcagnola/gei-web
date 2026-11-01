<?php

namespace Tests\Unit\Services;

use App\Services\TitularidadesInmuebleService;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TitularidadesInmuebleServiceTest extends TestCase
{
    private function servicio(): TitularidadesInmuebleService
    {
        return new TitularidadesInmuebleService();
    }

    private function anterior(
        int $id = 100,
        int $cuenta = 501,
        int $cliente = 5,
        ?string $porcentaje = '100.000000',
        ?string $desde = '2026-07-01'
    ): array {
        return [
            'id' => $id,
            'inmueble_id' => 3566,
            'cliente_cuenta_id' => $cuenta,
            'cliente_id' => $cliente,
            'porcentaje' => $porcentaje,
            'vigencia_desde' => $desde,
            'vigencia_hasta' => null,
            'activo' => true,
        ];
    }

    private function nuevo(int $cuenta = 501, int $cliente = 5, int|float|string|null $porcentaje = 100): array
    {
        return ['cliente_cuenta_id' => $cuenta, 'cliente_id' => $cliente, 'porcentaje' => $porcentaje];
    }

    public function test_cambio_de_cuenta_cierra_la_antigua_y_abre_nueva_sin_crear_otro_inmueble(): void
    {
        $p = $this->servicio()->planificar('202608', [$this->anterior()], [$this->nuevo(702, 7)]);

        self::assertSame('2026-08-01', $p['desde']);
        self::assertSame('2026-07-31', $p['hasta_anterior']);
        self::assertSame([100], $p['cerrar']);
        self::assertSame([['cliente_cuenta_id' => 702, 'cliente_id' => 7, 'porcentaje' => '100.000000']], $p['crear']);
        // Sólo planifica relaciones: el inmueble maestro 3566 nunca se crea ni se modifica.
    }

    public function test_repetir_una_nomina_confirmada_es_idempotente(): void
    {
        $p = $this->servicio()->planificar('202609', [$this->anterior()], [$this->nuevo()]);

        self::assertSame([], $p['cerrar']);
        self::assertSame([], $p['crear']);
        self::assertSame(1, $p['sin_cambios']);
    }

    public function test_copropietarios_comparten_inmueble_con_dos_relaciones(): void
    {
        $p = $this->servicio()->planificar('202607', [], [
            $this->nuevo(501, 5, '40.000000'),
            $this->nuevo(502, 6, '60.000000'),
        ]);

        self::assertCount(2, $p['crear']);
        self::assertSame([], $p['cerrar']);
    }

    public function test_baja_de_un_copropietario_y_cambio_de_porcentajes_conserva_historia(): void
    {
        $p = $this->servicio()->planificar('202608', [
            $this->anterior(100, 501, 5, '40.000000'),
            $this->anterior(101, 502, 6, '60.000000'),
        ], [$this->nuevo(501, 5, 100)]);

        self::assertSame([100, 101], $p['cerrar']);
        self::assertSame([['cliente_cuenta_id' => 501, 'cliente_id' => 5, 'porcentaje' => '100.000000']], $p['crear']);
    }

    public function test_porcentajes_desconocidos_permanecen_nulos(): void
    {
        $p = $this->servicio()->planificar('202608', [], [$this->nuevo(501, 5, null)]);
        self::assertNull($p['crear'][0]['porcentaje']);
    }

    public function test_no_inventa_porcentaje_de_una_cuenta_cobol(): void
    {
        $p = $this->servicio()->planificar('202608', [$this->anterior(100, 501, 5, null)], [
            $this->nuevo(501, 5, null),
        ]);
        self::assertSame([], $p['cerrar']);
        self::assertSame([], $p['crear']);
        self::assertSame(1, $p['sin_cambios']);
    }

    public function test_porcentaje_no_informado_no_borra_un_porcentaje_previamente_acreditado(): void
    {
        $p = $this->servicio()->planificar('202608', [$this->anterior()], [$this->nuevo(501, 5, null)]);
        self::assertSame([], $p['cerrar']);
        self::assertSame([], $p['crear']);
        self::assertSame(1, $p['sin_cambios']);
    }

    public function test_valida_100_por_ciento_solo_si_se_informaron_todos_los_porcentajes(): void
    {
        $p = $this->servicio()->planificar('202608', [], [
            $this->nuevo(501, 5, 30),
            $this->nuevo(502, 6, null),
        ]);
        self::assertCount(2, $p['crear']);
    }

    public function test_rechaza_porcentajes_completos_que_no_suman_100(): void
    {
        $this->expectException(DomainException::class);
        $this->servicio()->planificar('202608', [], [
            $this->nuevo(501, 5, 40),
            $this->nuevo(502, 6, 40),
        ]);
    }

    public function test_rechaza_cuenta_repetida_en_nomina(): void
    {
        $this->expectException(DomainException::class);
        $this->servicio()->planificar('202608', [], [
            $this->nuevo(501, 5, 50),
            $this->nuevo(501, 6, 50),
        ]);
    }

    public function test_rechaza_multiples_relaciones_abiertas_para_misma_cuenta(): void
    {
        $this->expectException(DomainException::class);
        $this->servicio()->planificar('202608', [
            $this->anterior(100),
            $this->anterior(101),
        ], [$this->nuevo()]);
    }

    public function test_rechaza_sobrescribir_titularidad_del_mismo_periodo(): void
    {
        $this->expectException(DomainException::class);
        $this->servicio()->planificar('202608', [$this->anterior(100, 501, 5, '100.000000', '2026-08-01')], [
            $this->nuevo(502, 6),
        ]);
    }

    public function test_rechaza_nomina_vacia_que_daria_de_baja_a_todos(): void
    {
        $this->expectException(DomainException::class);
        $this->servicio()->planificar('202608', [$this->anterior()], []);
    }

    public function test_rechaza_porcentajes_negativos(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->servicio()->planificar('202608', [], [$this->nuevo(501, 5, -10)]);
    }

    public function test_rechaza_periodos_invalidos(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->servicio()->planificar('202613', [], [$this->nuevo()]);
    }

    public function test_rechaza_vigencia_cerrada_como_si_fuera_actual(): void
    {
        $anterior = $this->anterior();
        $anterior['vigencia_hasta'] = '2026-07-31';
        $this->expectException(InvalidArgumentException::class);
        $this->servicio()->planificar('202608', [$anterior], [$this->nuevo()]);
    }

    public function test_se_registra_cambio_de_identidad_del_cliente_sin_perder_relacion_anterior(): void
    {
        $p = $this->servicio()->planificar('202608', [$this->anterior()], [$this->nuevo(501, 80)]);
        self::assertSame([100], $p['cerrar']);
        self::assertSame(80, $p['crear'][0]['cliente_id']);
    }
}
