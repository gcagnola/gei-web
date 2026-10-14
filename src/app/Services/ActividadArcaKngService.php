<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Evidencia de ACTIVIDAD por factura KNG, no identidad del destinatario.
 *
 * La autorización y la ruta del PDF siguen siendo responsabilidad exclusiva
 * de ComprobantesArcaService / PortalDocumentosController.
 * No reescribir ID_INQ, CTA_ORIG, clientes_cuentas ni kng_facturas.
 */
final class ActividadArcaKngService
{
    /**
     * @return array{total_facturas:int, facturas_id_inq_cobol:int, facturas_cta_orig_cobol:int,
     *     facturas_sin_cuenta_cobol:int, cuentas:array<string, array{destinatario:bool, origen:bool}>}
     */
    public function consultarPeriodo(string $periodo): array
    {
        if (! preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo)) {
            throw new RuntimeException('Período ARCA inválido (AAAAMM).');
        }
        if (! Schema::hasTable('kng_facturas')) {
            throw new RuntimeException('No existe kng_facturas: no inferir actividad ARCA en FALSE.');
        }

        $inicio = DateTimeImmutable::createFromFormat('!Ym', $periodo);
        if ($inicio === false) {
            throw new RuntimeException('Período ARCA inválido.');
        }
        $desde = $inicio->format('Y-m-d');
        $hasta = $inicio->modify('+1 month')->format('Y-m-d');

        $resultado = [
            'total_facturas' => 0,
            'facturas_id_inq_cobol' => 0,
            'facturas_cta_orig_cobol' => 0,
            'facturas_sin_cuenta_cobol' => 0,
            'cuentas' => [],
        ];

        // Consulta exclusivamente PostgreSQL, nunca DBF ni directorios de PDFs.
        foreach (DB::table('kng_facturas as kf')
            ->selectRaw("kf.datos->>'ID_INQ' AS id_inq, kf.datos->>'CTA_ORIG' AS cta_orig")
            ->where('kf.eliminado', false)
            ->whereRaw("kf.datos->>'FECHA' >= ?", [$desde])
            ->whereRaw("kf.datos->>'FECHA' < ?", [$hasta])
            ->cursor() as $factura) {
            $resultado['total_facturas']++;
            $destinatario = self::cuentaCobol((string) ($factura->id_inq ?? ''));
            $origen = self::cuentaCobol((string) ($factura->cta_orig ?? ''));

            if ($destinatario !== null) {
                $resultado['facturas_id_inq_cobol']++;
                $resultado['cuentas'][$destinatario] ??= ['destinatario' => false, 'origen' => false];
                $resultado['cuentas'][$destinatario]['destinatario'] = true;
            }
            if ($origen !== null) {
                $resultado['facturas_cta_orig_cobol']++;
                $resultado['cuentas'][$origen] ??= ['destinatario' => false, 'origen' => false];
                $resultado['cuentas'][$origen]['origen'] = true;
            }
            if ($destinatario === null && $origen === null) {
                $resultado['facturas_sin_cuenta_cobol']++;
            }
        }

        if ($resultado['total_facturas'] === 0) {
            throw new RuntimeException("No hay facturas KNG para {$periodo}; no se puede declarar inactividad ARCA.");
        }

        return $resultado;
    }

    /**
     * Una clave KNG corta (1009) o rellenada (00000001009) NO es cuenta COBOL.
     * Tampoco se identifica un cliente de GeI por coincidencia numérica con KNG.
     */
    public static function cuentaCobol(string $valor): ?string
    {
        $digitos = preg_replace('/\D+/', '', $valor) ?? '';

        return preg_match('/^[1-9][0-9]{10}$/D', $digitos) === 1 ? $digitos : null;
    }
}
