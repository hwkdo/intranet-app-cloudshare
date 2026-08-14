<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CloudsharePasswordSendMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $shareName,
        public string $accessUrl,
        public Authenticatable $sender,
    ) {}

    public function envelope(): Envelope
    {
        $fromEmail = (string) ($this->sender->email ?? config('mail.from.address'));
        $fromName = (string) ($this->sender->name ?? config('mail.from.name'));

        return new Envelope(
            from: new Address($fromEmail, $fromName),
            subject: 'Zugangspasswort zur Cloudshare-Freigabe '.$this->shareName,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'intranet-app-cloudshare::mail.password-send',
            with: [
                'user' => $this->sender,
                'shareName' => $this->shareName,
                'accessUrl' => $this->accessUrl,
            ],
        );
    }
}
