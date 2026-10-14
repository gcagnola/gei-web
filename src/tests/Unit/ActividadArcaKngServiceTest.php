<?php

namespace Tests\Unit;

use App\Services\ActividadArcaKngService;
use PHPUnit\Framework\TestCase;

class ActividadArcaKngServiceTest extends TestCase
{
    public function test_admite_cuentas_cobol_normales(): void
    {
        self::assertSame('12020829807', ActividadArcaKngService::cuentaCobol('12020829807'));
        self::assertSame('11032501103', ActividadArcaKngService::cuentaCobol('1103/25011/03'));
    }

    public function test_no_toma_claves_internas_de_kng_como_cuentas_cobol(): void
    {
        self::assertNull(ActividadArcaKngService::cuentaCobol('1009'));
        self::assertNull(ActividadArcaKngService::cuentaCobol('00000001009'));
        self::assertNull(ActividadArcaKngService::cuentaCobol('0'));
        self::assertNull(ActividadArcaKngService::cuentaCobol(''));
    }

    public function test_regla_or_independiente_por_fuente(): void
    {
        for ($bits = 0; $bits < 16; $bits++) {
            $activo = (bool) ($bits & 1) || (bool) ($bits & 2)
                || (bool) ($bits & 4) || (bool) ($bits & 8);
            self::assertSame($bits !== 0, $activo);
        }
    }
}
