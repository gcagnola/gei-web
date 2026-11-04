<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AutogestionParidadProduccionTest extends TestCase
{
    private function fuente(string $ruta): string
    {
        $contenido = file_get_contents(dirname(__DIR__, 2).'/'.$ruta);
        self::assertNotFalse($contenido);
        return (string) $contenido;
    }

    public function test_liquidaciones_inician_en_julio_e_impuestos_en_agosto(): void
    {
        $codigo = $this->fuente('app/Http/Controllers/PortalDocumentosController.php');
        self::assertStringContainsString("->where('periodo', '>=', '202607')", $codigo);
        self::assertStringContainsString("->where('periodo', '>=', '202608')", $codigo);
        self::assertStringContainsString("abort_unless((string) \$registro->periodo >= '202608', 404);", $codigo);
    }

    public function test_impuestos_muestran_solo_pdfs_verificados_y_paginan(): void
    {
        $codigo = $this->fuente('app/Http/Controllers/PortalDocumentosController.php');
        self::assertStringContainsString('return $liquidacion->impuestos_pdf_disponible;', $codigo);
        self::assertStringContainsString('new \\Illuminate\\Pagination\\LengthAwarePaginator(', $codigo);
    }

    public function test_inicio_muestra_impuestos_solo_si_hay_documentos_y_conserva_cuenta_corriente(): void
    {
        $controller = $this->fuente('app/Http/Controllers/PortalController.php');
        $vista = $this->fuente('resources/views/portal/inicio.blade.php');
        self::assertStringContainsString("compact('portalUsuario', 'tieneImpuestos')", $controller);
        self::assertStringContainsString('@if($tieneImpuestos)', $vista);
        self::assertStringContainsString('portal.cuenta-corriente.index', $vista);
        self::assertStringContainsString('window.geiPageLoading?.show()', $vista);
    }

    public function test_fechas_arca_formato_dia_mes_anio_y_spinner_unificado(): void
    {
        $vista = $this->fuente('resources/views/portal/documentos/comprobantes-arca.blade.php');
        self::assertStringContainsString("->format('d/m/Y')", $vista);
        self::assertStringContainsString('window.geiPageLoading?.show()', $vista);
        $layout = $this->fuente('resources/views/layouts/portal.blade.php');
        self::assertStringContainsString("@include('components.gei-loading-overlay')", $layout);
    }
}
