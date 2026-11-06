<?php

namespace App\Console\Commands;

use App\Services\CuentaCorrienteLccinqCompartidaService;
use Illuminate\Console\Command;
use Throwable;

/** Solo lectura. Puede ejecutarse en Produccion, no altera datos. */
final class ConciliarCuentaLccinqCommand extends Command
{
    protected $signature = 'gei:conciliar-cuenta-lccinq
        {cuenta : Cuenta COBOL de inquilino, 11 digitos}
        {--fecha=2026-11-06 : Corte del calculo en formato YYYY-MM-DD}';
    protected $description = 'Concilia importes pendientes e intereses COBOL GIMB38 (SOLO LECTURA).';

    public function handle(CuentaCorrienteLccinqCompartidaService $service): int
    {
        try {
            $cuenta = (string) $this->argument('cuenta');
            $fecha = (string) $this->option('fecha');
            $r = $service->consultar($cuenta, 'INQUILINO', $fecha, true);
            $this->line('Fuente: '.$r['fuente'].' | cierre: '.$r['cierre'].' | fecha calculo: '.$r['fecha_calculo']);
            $this->line('DEBITOS: '.self::pesos($r['total_debitos']).' ('.count($r['debitos']).' conceptos)');
            $this->line('CREDITOS: '.self::pesos($r['total_creditos']).' ('.count($r['creditos']).' conceptos)');
            $this->line('INTERESES + IVA: '.($r['total_intereses'] === null ? 'SIN PARAMETROS VALIDADOS' : self::pesos($r['total_intereses'])));
            $this->line('DEUDA: '.($r['deuda_total'] === null ? 'NO CALCULABLE' : self::pesos($r['deuda_total'])));
            // Diagnostico completo: la misma numeracion continua para debitOs y creditos.
            // Un total coincidente NO garantiza que coincida la cantidad de renglones.
            $filas = [];
            foreach ($r['debitos'] as $n => $d) {
                $filas[] = [
                    str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT),
                    'DEBITO', $d['codigo'].'/'.$d['numero'], $d['detalle'],
                    $d['vence'], self::pesos($d['importe']), $d['tasa'] ?? '—',
                    $d['dias'] ?? '—', $d['interes'] === null ? '—' : self::pesos($d['interes']),
                ];
            }
            foreach ($r['creditos'] as $n => $d) {
                $filas[] = [
                    str_pad((string) (count($r['debitos']) + $n + 1), 3, '0', STR_PAD_LEFT),
                    'CREDITO', $d['codigo'].'/'.$d['numero'], $d['detalle'],
                    '—', self::pesos($d['importe']), '—', '—', '—',
                ];
            }
            $this->table(
                ['Nro', 'Tipo', 'Codigo/Numero', 'Concepto', 'Vence', 'Capital', 'Tasa', 'Dias', 'Interes + IVA'],
                $filas
            );
            $this->line('RENGLONES: '.count($filas).' (COBOL caso de control: 16)');
            if ($cuenta === '11032187705' && $fecha === '2026-11-06') {
                $ok = count($r['debitos']) === 13
                    && count($r['creditos']) === 3
                    && $r['total_debitos'] === 104113446
                    && $r['total_creditos'] === 35685200
                    && $r['total_intereses'] === 4467423
                    && $r['deuda_total'] === 72895669;
                $this->{$ok ? 'info' : 'warn'}($ok
                    ? 'COBOL_CASO_CONTROL_OK: capital, creditos e intereses coinciden.'
                    : 'COBOL_CASO_CONTROL_DIFIERE: NO habilitar intereses en la web.');
                return $ok ? self::SUCCESS : self::FAILURE;
            }
            $this->warn('DIAGNOSTICO: sin referencia independiente para esta cuenta. NO autoriza deuda exigible.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('CONCILIACION_ERROR: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    private static function pesos(int $centavos): string
    {
        return ($centavos < 0 ? '-' : '').number_format(abs($centavos) / 100, 2, ',', '.');
    }
}
