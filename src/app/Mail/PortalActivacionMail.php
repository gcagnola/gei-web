<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PortalActivacionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombreCliente,
        public string $identificador,
        public string $urlActivacion,
        public mixed $venceAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Acceso a Autogestión - Guastavino e Imbert');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.portal-activacion');
    }

    public function attachments(): array
    {
        return [];
    }
}
