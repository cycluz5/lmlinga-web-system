<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Resident self-registration email verification code.
 * The code is passed only into the view; this mailable is not queued.
 */
class ResidentRegistrationOtpMail extends Mailable
{
    public function __construct(
        private readonly string $otpCode,
        private readonly int $expiryMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'LMLinga Registration Verification Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.resident-registration-otp',
            with: [
                'otpCode' => $this->otpCode,
                'expiryMinutes' => $this->expiryMinutes,
            ],
        );
    }
}
