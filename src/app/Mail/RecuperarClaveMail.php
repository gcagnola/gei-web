<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecuperarClaveMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombreUsuario,
        public string $urlRecuperacion
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Recuperación de contraseña - Guastavino e Imbert'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.recuperar-clave'
        );
    }

    public function attachments(): array
    {
        return [];
    }
}