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

class CloudshareSharedMail extends Mailable
{
    use Queueable, SerializesModels;

    public const DEFAULT_SUBJECT = 'Ein Cloud Ordner wurde für Sie freigegeben';

    /**
     * @param  array{name?: string, url: string, password?: bool, expiration?: ?string, writeable?: bool}  $share
     */
    public function __construct(
        public array $share,
        public string $mailSubject,
        public Authenticatable $sender,
    ) {}

    public function envelope(): Envelope
    {
        $fromEmail = (string) ($this->sender->email ?? config('mail.from.address'));
        $fromName = (string) ($this->sender->name ?? config('mail.from.name'));

        return new Envelope(
            from: new Address($fromEmail, $fromName),
            subject: $this->mailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'intranet-app-cloudshare::mail.shared',
            with: [
                'user' => $this->sender,
                'share' => $this->share,
            ],
        );
    }
}
