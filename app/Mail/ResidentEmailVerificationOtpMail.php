<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Resident email verification OTP (registration and existing accounts).
 * OTP is passed only into the view; this mailable is not queued.
 */
class ResidentEmailVerificationOtpMail extends Mailable
{
    public function __construct(
        private readonly string $otpCode,
        private readonly int $expiryMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'LMLINGA Email Verification Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.resident-email-verification-otp',
            with: [
                'otpCode' => $this->otpCode,
                'expiryMinutes' => $this->expiryMinutes,
            ],
        );
    }
}
