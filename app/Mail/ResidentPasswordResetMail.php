<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ResidentPasswordResetMail extends Mailable
{
    public function __construct(
        public readonly string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'LMLinga Resident Chatbot Password Reset',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.resident-password-reset',
        );
    }
}
