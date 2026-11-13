<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent instead of a code when someone registers with an address that already has a resident account,
 * so the registration page never reveals whether an email is registered. Not queued.
 */
class ResidentRegistrationAccountExistsMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'LMLINGA Registration Attempt',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.resident-registration-account-exists',
            with: [
                'loginUrl' => route('chatbot.login'),
                'forgotPasswordUrl' => route('chatbot.password.request'),
            ],
        );
    }
}
