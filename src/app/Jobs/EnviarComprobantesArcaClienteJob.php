<?php

namespace App\Jobs;

use App\Models\Cliente;
use App\Services\ComprobantesArcaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class EnviarComprobantesArcaClienteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 180;

    /**
     * @param list<string> $archivos
     */
    public function __construct(
        public readonly int $clienteId,
        public readonly string $periodo,
        public readonly string $emailDestino,
        public readonly array $archivos,
    ) {
    }

    public function handle(ComprobantesArcaService $service): void
    {
        $cliente = Cliente::query()->findOrFail($this->clienteId);
        $email = mb_strtolower(trim($this->emailDestino));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('El email de destino no es válido.');
        }

        $disk = Storage::disk('arca_facturas');
        $adjuntos = [];

        foreach (array_values(array_unique($this->archivos)) as $archivo) {
            $ruta = $service->rutaRelativa($this->periodo, (string) $archivo);

            if (
                $ruta === null
                || ! $service->archivoDisponible($this->periodo, (string) $archivo)
            ) {
                continue;
            }

            $adjuntos[] = [
                'ruta' => $disk->path($ruta),
                'nombre' => basename((string) $archivo),
            ];
        }

        if ($adjuntos === []) {
            throw new RuntimeException('No hay comprobantes ARCA disponibles para adjuntar.');
        }

        $periodoVisible = substr($this->periodo, 4, 2).'/'.substr($this->periodo, 0, 4);
        $nombre = trim((string) $cliente->nombre);
        $cantidad = count($adjuntos);

        $cuerpo = implode(PHP_EOL.PHP_EOL, [
            $nombre !== '' ? 'Estimado/a '.$nombre.':' : 'Estimado/a:',
            sprintf(
                'Adjuntamos %d comprobante(s) ARCA correspondientes al período %s.',
                $cantidad,
                $periodoVisible
            ),
            'Saludos.',
            'Guastavino e Imbert',
        ]);

        Mail::raw($cuerpo, function (Message $message) use (
            $email,
            $nombre,
            $periodoVisible,
            $adjuntos
        ): void {
            $message
                ->to($email, $nombre !== '' ? $nombre : null)
                ->subject(
                    'Comprobantes ARCA '.$periodoVisible
                    .($nombre !== '' ? ' - '.$nombre : '')
                );

            foreach ($adjuntos as $adjunto) {
                $message->attach(
                    $adjunto['ruta'],
                    [
                        'as' => $adjunto['nombre'],
                        'mime' => 'application/pdf',
                    ]
                );
            }
        });
    }
}
