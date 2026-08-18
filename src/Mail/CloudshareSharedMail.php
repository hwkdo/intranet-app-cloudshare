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

    public const DEFAULT_SUBJECT = 'Der Cloud Ordner wurde für Sie freigegeben';

    /**
     * @param  array{name?: string, url: string, password?: bool, expiration?: ?string, writeable?: bool}  $share
     */
    public function __construct(
        public array $share,
        public string $mailSubject,
        public Authenticatable $sender,
    ) {}

    public static function subjectForShare(string $shareName): string
    {
        $shareName = trim($shareName);

        if ($shareName === '') {
            return self::DEFAULT_SUBJECT;
        }

        return 'Der Cloud Ordner '.$shareName.' wurde für Sie freigegeben';
    }

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
                'shareName' => $this->shareName(),
                'shareUrl' => $this->shareUrl(),
                'passwordProtectionLabel' => $this->passwordProtectionLabel(),
                'expirationLabel' => $this->expirationLabel(),
                'guestUploadLabel' => $this->guestUploadLabel(),
                'signatureMarkdown' => implode("  \n", $this->signatureLines()),
            ],
        );
    }

    /**
     * @return list<string>
     */
    public function signatureLines(): array
    {
        $name = trim($this->senderAttribute('vorname').' '.$this->senderAttribute('nachname'));

        if ($name === '') {
            $name = $this->senderAttribute('name');
        }

        $cityLine = trim($this->senderAttribute('plz').' '.$this->senderAttribute('ort'));
        $telefon = $this->senderAttribute('telefon');
        $fax = $this->senderAttribute('fax');
        $email = $this->senderAttribute('email');

        return array_values(array_filter([
            $name,
            $this->senderAttribute('title'),
            $this->senderAttribute('extension1'),
            $this->senderAttribute('extension2'),
            $this->senderAttribute('extension3'),
            'Handwerkskammer Dortmund',
            $this->senderAttribute('adresse'),
            $cityLine,
            $telefon !== '' ? 'Tel.: '.$telefon : '',
            $fax !== '' ? 'Fax: '.$fax : '',
            $email !== '' ? 'E-Mail: '.$email : '',
        ], fn (string $line): bool => $line !== ''));
    }

    public function shareName(): string
    {
        return trim((string) ($this->share['name'] ?? ''));
    }

    public function shareUrl(): string
    {
        return trim((string) ($this->share['url'] ?? ''));
    }

    public function passwordProtectionLabel(): string
    {
        return ! empty($this->share['password']) ? 'aktiviert' : 'nicht aktiviert';
    }

    public function expirationLabel(): string
    {
        $expiration = $this->share['expiration'] ?? null;

        if (is_string($expiration) && trim($expiration) !== '') {
            return trim($expiration);
        }

        return 'ohne Ablaufdatum';
    }

    public function guestUploadLabel(): string
    {
        return ! empty($this->share['writeable']) ? 'aktiviert' : 'nicht aktiviert';
    }

    private function senderAttribute(string $attribute): string
    {
        return trim((string) data_get($this->sender, $attribute, ''));
    }
}
