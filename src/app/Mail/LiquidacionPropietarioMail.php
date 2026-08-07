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

    public function __construct(
        public Cliente $cliente,
        public LiquidacionPropietario $liquidacion,
        private readonly string $rutaRelativa,
        private readonly string $nombreArchivo
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Liquidación de propietario - '.$this->liquidacion->periodo_formateado
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
        return [
            Attachment::fromStorageDisk('liquidaciones', $this->rutaRelativa)
                ->as($this->nombreArchivo)
                ->withMime('application/pdf'),
        ];
    }
}
