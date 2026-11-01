<?php

namespace Tests\Unit;

use App\Services\EvidenciaActividadCobolService;
use Illuminate\Database\Connection;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EvidenciaActividadCobolServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_consulta_actividad_desde_archivos_originales_sin_identidades_gei_core(): void
    {
        $db = Mockery::mock(Connection::class);
        $sqlEjecutado = [];
        $db->shouldReceive('selectOne')->times(5)
            ->andReturnUsing(static function (string $sql, array $bindings = []) use (&$sqlEjecutado): object {
                $sqlEjecutado[] = $sql;
                if (str_contains($sql, 'migraciones_periodos')) {
                    // El snapshot se elige por nombre de tabla, no por max(archivo_id).
                    return (object) ['archivo_id' => ($bindings[1] === 'propietar' ? 25 : 26)];
                }
                if (str_contains($sql, 'count(*)')) {
                    return (object) ['total' => 100];
                }
                return (object) ['fecha' => '20261001'];
            });
        $db->shouldReceive('cursor')->once()
            ->withArgs(static function (string $sql, array $bindings) use (&$sqlEjecutado): bool {
                $sqlEjecutado[] = $sql;
                return str_contains($sql, 'cobol_staging.propietar')
                    && str_contains($sql, 'cobol_staging.inquilino')
                    && str_contains($sql, 'i.marca_baja')
                    && str_contains($sql, 'p.fecha_ultima_liquidacion')
                    && str_contains($sql, 'MATERIALIZED')
                    && $bindings === [25, '20261001', 26];
            })
            ->andReturn((static function (): \Generator {
                yield (object) ['rol' => 'PROPIETARIO', 'cuenta' => '1202/04663/08'];
                yield (object) ['rol' => 'PROPIETARIO', 'cuenta' => '1202/04663/08'];
                yield (object) ['rol' => 'INQUILINO', 'cuenta' => '21030314001'];
            })());

        $resultado = (new EvidenciaActividadCobolService())->consultarPeriodoDesde('202610', $db);
        self::assertSame(25, $resultado['archivo_propietar_id']);
        self::assertSame(26, $resultado['archivo_inquilino_id']);
        self::assertSame('20261001', $resultado['fecha_ultima_liquidacion']);
        self::assertSame([
            ['rol' => 'PROPIETARIO', 'cuenta' => '12020466308'],
            ['rol' => 'INQUILINO', 'cuenta' => '21030314001'],
        ], $resultado['cuentas']);
        self::assertStringNotContainsString('gei_core.', implode("\n", $sqlEjecutado));
    }

    public function test_no_infiere_bajas_si_falta_una_importacion_del_periodo(): void
    {
        $db = Mockery::mock(Connection::class);
        $db->shouldReceive('selectOne')->once()->andReturn((object) ['archivo_id' => null]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Falta propietar COBOL');
        (new EvidenciaActividadCobolService())->consultarPeriodoDesde('202610', $db);
    }

    public function test_no_infiere_bajas_si_el_archivo_cobol_esta_vacio(): void
    {
        $db = Mockery::mock(Connection::class);
        $db->shouldReceive('selectOne')->times(3)->andReturn(
            (object) ['archivo_id' => 25],
            (object) ['archivo_id' => 26],
            (object) ['total' => 0]
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No hay registros en COBOL propietar');
        (new EvidenciaActividadCobolService())->consultarPeriodoDesde('202610', $db);
    }

    public function test_no_infiere_inactividad_si_no_hay_cuentas_vigentes(): void
    {
        $db = Mockery::mock(Connection::class);
        $db->shouldReceive('selectOne')->times(5)->andReturn(
            (object) ['archivo_id' => 25],
            (object) ['archivo_id' => 26],
            (object) ['total' => 100],
            (object) ['total' => 200],
            (object) ['fecha' => '20261001']
        );
        $db->shouldReceive('cursor')->once()->andReturn((static function (): \Generator {
            if (false) {
                yield null;
            }
        })());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No hay cuentas activas COBOL');
        (new EvidenciaActividadCobolService())->consultarPeriodoDesde('202610', $db);
    }

    public function test_no_admite_periodos_invalidos(): void
    {
        $db = Mockery::mock(Connection::class);
        $db->shouldNotReceive('selectOne');
        $this->expectException(RuntimeException::class);
        (new EvidenciaActividadCobolService())->consultarPeriodoDesde('202613', $db);
    }
}
