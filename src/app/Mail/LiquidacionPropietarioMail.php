<?php

namespace App\Mail;

use App\Models\Cliente;
use App\Models\LiquidacionPropietario;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LiquidacionPropietarioMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param array<int, array{disk: string, ruta: string, nombre: string}> $adjuntos
     */
    public function __construct(
        public Cliente $cliente,
        public LiquidacionPropietario $liquidacion,
        private readonly array $adjuntos,
        public readonly string $documentosEnvio,
    ) {
    }

    public function envelope(): Envelope
    {
        $titulo = match ($this->documentosEnvio) {
            'IMPUESTOS' => 'Impuestos garantizados',
            'AMBOS' => 'Liquidación de propietario e impuestos garantizados',
            'ARCA' => 'Comprobantes ARCA',
            'TODOS' => 'Liquidación, impuestos garantizados y comprobantes ARCA',
            default => 'Liquidación de propietario',
        };

        return new Envelope(
            subject: $titulo.' - '.$this->liquidacion->periodo_formateado
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.liquidacion-propietario'
        );
    }

    public function attachments(): array
    {
        return array_map(
            static fn (array $adjunto): Attachment => Attachment::fromStorageDisk(
                $adjunto['disk'],
                $adjunto['ruta']
            )
                ->as($adjunto['nombre'])
                ->withMime('application/pdf'),
            $this->adjuntos
        );
    }
}
