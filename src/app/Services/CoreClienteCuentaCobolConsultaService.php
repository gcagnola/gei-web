<?php

namespace App\Services;

use App\Services\Portal\PortalCuentaCorrienteService;
use App\Support\Cobol\Gimb68Saldos;
use RuntimeException;
use Throwable;

final class CoreClienteCuentaCobolConsultaService
{
    public function __construct(
        private readonly PortalCuentaCorrienteService $portal
    ) {}

    public function consultar(
        iterable $cuentasAutorizadas,
        string $mes,
        array $sedesPermitidas
    ): array {
        $salida = [];
        $sedes = array_map('strtoupper', $sedesPermitidas);

        $puedePrueba = app()->environment('local', 'testing')
            && (bool) config('portal.cuenta_corriente.web_prueba', false);

        foreach ($cuentasAutorizadas as $registro) {
            $cuenta = preg_replace(
                '/\D+/',
                '',
                (string) ($registro->cuenta_cobol ?? '')
            );

            $rol = strtoupper(trim((string) ($registro->tipo ?? '')));

            if (! preg_match('/^[0-9]{11}$/D', $cuenta)
                || ! in_array($rol, ['INQUILINO', 'PROPIETARIO'], true)) {
                continue;
            }

            $sede = match (substr($cuenta, 0, 4)) {
                '1103', '1202' => 'SF',
                '2103', '2202' => 'ST',
                default => null,
            };

            if ($sede === null || ! in_array($sede, $sedes, true)) {
                continue;
            }

            $fila = [
                'cuenta' => $cuenta,
                'rol' => $rol,
                'sede' => $sede,
                'fuente' => null,
                'cierre' => null,
                'ultimo_mes' => null,
                'saldos' => null,
                'error' => null,
            ];

            try {
                if ($puedePrueba) {
                    try {
                        $snapshot = $this->portal->consultarEnPrueba(
                            $cuenta,
                            $rol
                        );
                    } catch (RuntimeException $e) {
                        if ($e->getMessage() !==
                            'No hay cierre COBOL en estado PRUEBA para esta sede.') {
                            throw $e;
                        }

                        $snapshot = $this->portal->consultar(
                            $cuenta,
                            $rol
                        );
                    }
                } else {
                    $snapshot = $this->portal->consultar(
                        $cuenta,
                        $rol
                    );
                }

                $fila['fuente'] = $snapshot['fuente'] ?? 'HISTORICO';
                $fila['cierre'] = $snapshot['cierre'] ?? null;

                $cierre = (string) $fila['cierre'];

                $fila['ultimo_mes'] =
                    preg_match('/^\d{4}-\d{2}-\d{2}$/D', $cierre)
                    ? substr($cierre, 0, 4).substr($cierre, 5, 2)
                    : collect($snapshot['movimientos'])
                        ->pluck('periodo')
                        ->filter(static fn ($p) =>
                            preg_match('/^\d{6}$/D', (string) $p) === 1
                        )
                        ->max();

                try {
                    $fila['saldos'] = Gimb68Saldos::calcular(
                        $snapshot['movimientos'],
                        $mes
                    );
                } catch (Throwable $e) {
                    // No ocultar movimientos si falla la conciliación.
                    $movimientos = array_values(array_filter(
                        $snapshot['movimientos'],
                        static fn (array $m): bool =>
                            (string) ($m['periodo'] ?? '') === $mes
                    ));

                    $fila['saldos'] = [
                        'movimientos' => $movimientos,
                        'saldo_anterior' => null,
                        'saldo_actual' => null,
                        'debitos_anteriores' => null,
                        'creditos_anteriores' => null,
                    ];

                    $fila['error'] =
                        'Movimientos visibles, saldo pendiente de conciliación: '
                        .$e->getMessage();
                }
            } catch (Throwable $e) {
                $fila['error'] =
                    'No se pudo consultar esta cuenta: '.$e->getMessage();
            }

            $salida[] = $fila;
        }

        return $salida;
    }
}
