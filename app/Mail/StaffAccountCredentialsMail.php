<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Health-worker account credentials (new account or admin password reset).
 * Carries the admin-issued temporary password. Passed only into the view; this mailable is not queued.
 */
class StaffAccountCredentialsMail extends Mailable
{
    public function __construct(
        private readonly string $workerName,
        private readonly string $loginIdentity,
        private readonly string $loginEmail,
        private readonly string $temporaryPassword,
        private readonly string $loginUrl,
        private readonly bool $isReset = false,
        private readonly int $expiryMinutes = 5,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isReset
                ? 'LMLinga Health Worker Account Password Reset'
                : 'Your LMLinga Health Worker Account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.staff-account-credentials',
            with: [
                'workerName' => $this->workerName,
                'loginIdentity' => $this->loginIdentity,
                'loginEmail' => $this->loginEmail,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl' => $this->loginUrl,
                'isReset' => $this->isReset,
                'expiryMinutes' => $this->expiryMinutes,
            ],
        );
    }
}
