<?php

namespace App\Mail\Notification;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GenericNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $title,
        public string $body,
        public ?string $imageUrl = null,
        public array $data = [],
        public ?string $userName = null
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->title
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notifications.generic',
            with: [
                'title' => $this->title,
                'body' => $this->body,
                'imageUrl' => $this->imageUrl,
                'data' => $this->data,
                'userName' => $this->userName,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}