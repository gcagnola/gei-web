<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Portal\PortalCuentaCorrienteService;
use App\Support\Cobol\Gimb38InteresDiagnostico;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class GeiDiagnosticarCuentaIntereses extends Command
{
    protected $signature = 'gei:diagnosticar-cuenta-intereses
        {cuenta : Cuenta COBOL del inquilino}
        {--fecha= : Fecha de simulacion YYYY-MM-DD}
        {--parametros=/tmp/gei-parametros-intereses}';

    protected $description = 'Diagnostico de deuda e intereses COBOL';

    public function handle(PortalCuentaCorrienteService $servicio): int
    {
        if (!app()->environment('local', 'testing')) {
            $this->error('Disponible solamente en desarrollo.');
            return self::FAILURE;
        }

        try {
            $cuenta = (string) $this->argument('cuenta');
            $fecha = (string) $this->option('fecha');

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fecha)) {
                throw new RuntimeException('Indicar --fecha=YYYY-MM-DD');
            }

            // Movimientos reconstruidos desde PostgreSQL.
            $snapshot = $servicio->consultarEnPrueba(
                $cuenta,
                'INQUILINO'
            );

            // TIPOIVA obtenido automaticamente desde INQUILINO.
            $iva = $servicio->tipoIvaInquilinoEnPrueba($cuenta);

            if ($iva['cierre'] !== $snapshot['cierre']) {
                throw new RuntimeException(
                    'Los cierres de movimientos y TIPOIVA no coinciden.'
                );
            }

            if ($fecha < $snapshot['cierre']) {
                throw new RuntimeException(
                    'La simulacion no puede preceder al cierre.'
                );
            }

            $calculador = Gimb38InteresDiagnostico::desdeDirectorio(
                (string) $this->option('parametros')
            );

            $capital = 0;
            $intereses = 0;
            $impuesto = 0;
            $sobretasa = 0;
            $cantidad = 0;

            $this->info('===== CUENTA CORRIENTE COBOL =====');
            $this->line("Cuenta: $cuenta");
            $this->line("Cierre: {$snapshot['cierre']}");
            $this->line("TIPOIVA: {$iva['tipo_iva']}");
            $this->line("Fecha calculo: $fecha");

            foreach ($snapshot['movimientos'] as $m) {

                if (($m['tipo_movimiento'] ?? '') !== 'DEBITO') {
                    continue;
                }

                $importe = self::centavos($m['importe']);
                $abonado = self::centavos(
                    $m['importe_abonado'] ?? '0.00'
                );

                $pendiente = $importe - $abonado;

                if ($pendiente < 0) {
                    throw new RuntimeException(
                        'Importe abonado mayor al cargo.'
                    );
                }

                if ($pendiente === 0) {
                    continue;
                }

                $resultado = $calculador->calcular(
                    $pendiente,
                    $m['fecha_vencimiento'],
                    $fecha,
                    (int) $iva['tipo_iva']
                );

                // Diagnostico multitramos.
                // Pendiente de conciliacion con el ejecutable GIMB38.

                $capital += $pendiente;
                $intereses += $resultado['interes'];
                $impuesto += $resultado['iva'];
                $sobretasa += $resultado['sobretasa'];
                $cantidad++;

                $this->line(sprintf(
                    '%s/%s | Vence=%s | Capital=%s | Interes=%s | IVA=%s',
                    $m['codigo'],
                    $m['numero'],
                    $m['fecha_vencimiento'],
                    self::pesos($pendiente),
                    self::pesos($resultado['interes']),
                    self::pesos($resultado['iva'])
                ));
            }

            $this->info('===== RESULTADO =====');
            $this->line("Cargos pendientes: $cantidad");
            $this->line('Capital: '.self::pesos($capital));
            $this->line('Intereses: '.self::pesos($intereses));
            $this->line('IVA: '.self::pesos($impuesto));
            $this->line('Sobretasa: '.self::pesos($sobretasa));

            $this->info(
                'TOTAL SIMULADO: '.self::pesos(
                    $capital + $intereses + $impuesto + $sobretasa
                )
            );

            $this->warn(
                'DIAGNOSTICO: NO ES SALDO EXIGIBLE.'
            );

            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private static function centavos(string $valor): int
    {
        if (!preg_match('/^([0-9]+)\.([0-9]{2})$/D', $valor, $m)) {
            throw new RuntimeException('Importe invalido: '.$valor);
        }

        return (int) $m[1] * 100 + (int) $m[2];
    }

    private static function pesos(int $centavos): string
    {
        return intdiv($centavos, 100).'.'.
            str_pad(
                (string) ($centavos % 100),
                2,
                '0',
                STR_PAD_LEFT
            );
    }
}
