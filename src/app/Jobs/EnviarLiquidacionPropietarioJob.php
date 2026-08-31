<?php

namespace App\Jobs;

use App\Mail\LiquidacionPropietarioMail;
use App\Models\LiquidacionPropietarioEnvio;
use App\Services\ImpuestosGarantizadosPdfService;
use App\Services\ComprobantesArcaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class EnviarLiquidacionPropietarioJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public int $envioId)
    {
        $this->onConnection('liquidaciones_emails');
        $this->onQueue('liquidaciones-emails');
    }

    public function handle(
        ImpuestosGarantizadosPdfService $impuestosGarantizados,
        ComprobantesArcaService $comprobantesArca,
    ): void
    {
        $envio = LiquidacionPropietarioEnvio::query()
            ->with(['liquidacion.cliente'])
            ->find($this->envioId);

        if ($envio === null || $envio->estado === 'ENVIADO') {
            return;
        }

        $envio->forceFill([
            'estado' => 'PROCESANDO',
            'intentado_at' => now(),
            'intentos' => $envio->intentos + 1,
            'mensaje_error' => null,
        ])->save();

        try {
            $liquidacion = $envio->liquidacion;
            $cliente = $liquidacion?->cliente;

            if ($liquidacion === null || $cliente === null) {
                throw new RuntimeException('La liquidación no tiene un cliente asociado.');
            }

            if (! filter_var($envio->email_destino, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('El email de destino no es válido.');
            }

            $documentos = strtoupper(trim((string) ($envio->documentos ?? 'LIQUIDACION')));
            if (! in_array($documentos, ['LIQUIDACION', 'IMPUESTOS', 'AMBOS', 'ARCA', 'TODOS'], true)) {
                $documentos = 'LIQUIDACION';
            }

            $adjuntos = [];

            if (in_array($documentos, ['LIQUIDACION', 'AMBOS', 'TODOS'], true)) {
                if (! $liquidacion->pdf_ruta) {
                    throw new RuntimeException('La liquidación no tiene un PDF asociado.');
                }

                if (! Storage::disk('liquidaciones')->exists($liquidacion->pdf_ruta)) {
                    throw new RuntimeException('El archivo PDF de la liquidación no existe en el almacenamiento.');
                }

                $adjuntos[] = [
                    'disk' => 'liquidaciones',
                    'ruta' => $liquidacion->pdf_ruta,
                    'nombre' => basename($liquidacion->pdf_ruta),
                ];
            }

            if (in_array($documentos, ['IMPUESTOS', 'AMBOS', 'TODOS'], true)) {
                $rutaImpuestos = $impuestosGarantizados->rutaPdfParaLiquidacion(
                    (string) $liquidacion->periodo,
                    $liquidacion->pdf_ruta,
                    (string) ($liquidacion->cuenta_impresa ?: $liquidacion->cuenta),
                );

                if ($rutaImpuestos === null) {
                    throw new RuntimeException('No se pudo determinar el PDF de impuestos garantizados.');
                }

                if (! Storage::disk('liquidaciones')->exists($rutaImpuestos)) {
                    throw new RuntimeException('El archivo PDF de impuestos garantizados no existe en el almacenamiento.');
                }

                $adjuntos[] = [
                    'disk' => 'liquidaciones',
                    'ruta' => $rutaImpuestos,
                    'nombre' => basename($rutaImpuestos),
                ];
            }

            if (in_array($documentos, ['ARCA', 'TODOS'], true)) {
                $cuenta = (string) ($liquidacion->cuenta_impresa ?: $liquidacion->cuenta);
                $comprobantes = $comprobantesArca->paraCuentaPeriodo(
                    $cuenta,
                    (string) $liquidacion->periodo,
                );

                if ($comprobantes->isEmpty()) {
                    throw new RuntimeException('No hay comprobantes ARCA para esta cuenta y período.');
                }

                foreach ($comprobantes as $comprobante) {
                    $rutaArca = (string) ($comprobante->ruta_relativa ?? '');
                    $nombreArca = (string) ($comprobante->nombre_archivo ?? '');

                    if (
                        $rutaArca === ''
                        || $nombreArca === ''
                        || ! Storage::disk('arca_facturas')->exists($rutaArca)
                        || (int) Storage::disk('arca_facturas')->size($rutaArca) <= 0
                    ) {
                        throw new RuntimeException(
                            'No se puede leer el comprobante ARCA '.($nombreArca !== '' ? $nombreArca : $rutaArca).'.'
                        );
                    }

                    $adjuntos[] = [
                        'disk' => 'arca_facturas',
                        'ruta' => $rutaArca,
                        'nombre' => $nombreArca,
                    ];
                }
            }

            if ($adjuntos === []) {
                throw new RuntimeException('No hay documentos disponibles para adjuntar al email.');
            }

            Mail::to($envio->email_destino)->send(
                new LiquidacionPropietarioMail(
                    cliente: $cliente,
                    liquidacion: $liquidacion,
                    adjuntos: $adjuntos,
                    documentosEnvio: $documentos,
                )
            );

            $envio->forceFill([
                'estado' => 'ENVIADO',
                'enviado_at' => now(),
                'mensaje_error' => null,
            ])->save();
        } catch (Throwable $error) {
            $envio->forceFill([
                'estado' => 'PENDIENTE',
                'mensaje_error' => mb_substr($error->getMessage(), 0, 2000),
            ])->save();

            throw $error;
        }
    }

    public function failed(?Throwable $error): void
    {
        LiquidacionPropietarioEnvio::query()
            ->whereKey($this->envioId)
            ->update([
                'estado' => 'ERROR',
                'mensaje_error' => mb_substr(
                    $error?->getMessage() ?? 'El envío falló definitivamente.',
                    0,
                    2000
                ),
                'updated_at' => now(),
            ]);
    }
}
