<?php

namespace App\Jobs;

use App\Mail\LiquidacionPropietarioMail;
use App\Models\LiquidacionPropietarioEnvio;
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

    public function handle(): void
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

            if (! $liquidacion->pdf_ruta) {
                throw new RuntimeException('La liquidación no tiene un PDF asociado.');
            }

            if (! Storage::disk('liquidaciones')->exists($liquidacion->pdf_ruta)) {
                throw new RuntimeException('El archivo PDF no existe en el almacenamiento.');
            }

            Mail::to($envio->email_destino)->send(
                new LiquidacionPropietarioMail(
                    cliente: $cliente,
                    liquidacion: $liquidacion,
                    rutaRelativa: $liquidacion->pdf_ruta,
                    nombreArchivo: basename($liquidacion->pdf_ruta)
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
