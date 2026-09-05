<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentRequestReminder extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $businessName,
        public readonly string $clientName,
        public readonly ?string $requestMessage,
        public readonly ?string $dueAt,
        public readonly string $link,
        public readonly array $missingItemNames,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Reminder: {$this->businessName} is still waiting for documents",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.document-request-reminder',
        );
    }
}
