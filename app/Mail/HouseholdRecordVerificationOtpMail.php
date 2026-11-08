<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Household record verification OTP email.
 * OTP is passed only into the view; this mailable is not queued.
 */
class HouseholdRecordVerificationOtpMail extends Mailable
{
    public function __construct(
        private readonly string $otpCode,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'LMLINGA Household Record Verification Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.household-record-verification-otp',
            with: [
                'otpCode' => $this->otpCode,
            ],
        );
    }
}
