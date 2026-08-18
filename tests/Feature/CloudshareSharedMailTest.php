<?php

declare(strict_types=1);

use App\Models\User;
use Hwkdo\IntranetAppCloudshare\Mail\CloudshareSharedMail;

if (! function_exists('cloudshareMailSender')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function cloudshareMailSender(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'username' => 'mail.sender',
            'vorname' => 'Max',
            'nachname' => 'Mustermann',
            'email' => 'max.mustermann@example.com',
            'title' => 'Beraterin Digitalisierung',
            'extension1' => 'Abteilung IT',
            'extension2' => null,
            'extension3' => '',
            'adresse' => 'Ardeystraße 93',
            'plz' => '44139',
            'ort' => 'Dortmund',
            'telefon' => '0231 5493-0',
            'fax' => null,
        ], $overrides));
    }
}

it('stellt freigabeangaben in html und text getrennt dar', function (): void {
    $sender = cloudshareMailSender();
    $share = cloudshareSampleShare([
        'name' => 'Projekt-X',
        'url' => 'https://1drv.ms/example',
        'password' => true,
        'expiration' => '31.12.2026 23:59 Uhr',
        'writeable' => false,
    ]);

    $mailable = new CloudshareSharedMail(
        $share,
        CloudshareSharedMail::subjectForShare('Projekt-X'),
        $sender,
    );

    $mailable->assertSeeInHtml('Max Mustermann hat den Cloud-Ordner');
    $mailable->assertSeeInHtml('alt="Cloud Share"');
    $mailable->assertSeeInHtml('Projekt-X');
    $mailable->assertSeeInHtml('https://1drv.ms/example');
    $mailable->assertDontSeeInHtml('Cloudshare');
    $mailable->assertDontSeeInHtml('Cloud-Share');
    $mailable->assertDontSeeInHtml('CloudShare');
    $mailable->assertDontSeeInHtml('Cloud Ordner');
    $mailable->assertSeeInOrderInHtml([
        'Freigabe:',
        'Projekt-X',
        'Link zur Freigabe:',
        'https://1drv.ms/example',
        'Passwortschutz: aktiviert',
        'Gültig bis: 31.12.2026 23:59 Uhr',
        'Gast-Upload: nicht aktiviert',
    ]);

    $mailable->assertSeeInText('Max Mustermann hat den Cloud-Ordner');
    $mailable->assertSeeInOrderInText([
        'Freigabe: Projekt-X',
        'Link zur Freigabe: https://1drv.ms/example',
        'Passwortschutz: aktiviert',
        'Gültig bis: 31.12.2026 23:59 Uhr',
        'Gast-Upload: nicht aktiviert',
    ]);
});

it('zeigt deaktivierte eigenschaften und fehlendes ablaufdatum explizit', function (): void {
    $sender = cloudshareMailSender([
        'username' => 'mail.sender.plain',
        'email' => 'mail.sender.plain@example.com',
    ]);
    $share = cloudshareSampleShare([
        'password' => false,
        'expiration' => null,
        'writeable' => true,
    ]);

    $mailable = new CloudshareSharedMail($share, 'Betreff', $sender);

    $mailable->assertSeeInHtml('Passwortschutz: nicht aktiviert');
    $mailable->assertSeeInHtml('Gültig bis: ohne Ablaufdatum');
    $mailable->assertSeeInHtml('Gast-Upload: aktiviert');

    $mailable->assertSeeInText('Passwortschutz: nicht aktiviert');
    $mailable->assertSeeInText('Gültig bis: ohne Ablaufdatum');
    $mailable->assertSeeInText('Gast-Upload: aktiviert');
});

it('stellt die signatur in html und text zeilenweise dar', function (): void {
    $sender = cloudshareMailSender();
    $mailable = new CloudshareSharedMail(cloudshareSampleShare(), 'Betreff', $sender);

    $mailable->assertSeeInOrderInHtml([
        'Bei Rückfragen wenden Sie sich bitte an:',
        'Max Mustermann',
        'Beraterin Digitalisierung',
        'Abteilung IT',
        'Handwerkskammer Dortmund',
        'Ardeystraße 93',
        '44139 Dortmund',
        'Tel.: 0231 5493-0',
        'E-Mail: max.mustermann@example.com',
    ]);
    $mailable->assertDontSeeInHtml('Max Mustermann Beraterin Digitalisierung');
    $mailable->assertDontSeeInHtml('Fax:');

    $mailable->assertSeeInOrderInText([
        'Bei Rückfragen wenden Sie sich bitte an:',
        'Max Mustermann',
        'Beraterin Digitalisierung',
        'Abteilung IT',
        'Handwerkskammer Dortmund',
        'Ardeystraße 93',
        '44139 Dortmund',
        'Tel.: 0231 5493-0',
        'E-Mail: max.mustermann@example.com',
    ]);
    $mailable->assertDontSeeInText('Max Mustermann Beraterin Digitalisierung Handwerkskammer Dortmund');
    $mailable->assertDontSeeInText('Fax:');
});

it('laesst leere signaturfelder weg', function (): void {
    $sender = cloudshareMailSender([
        'username' => 'mail.sender.minimal',
        'email' => 'mail.sender.minimal@example.com',
        'title' => null,
        'extension1' => '',
        'adresse' => null,
        'plz' => null,
        'ort' => null,
        'telefon' => null,
        'fax' => null,
    ]);

    $mailable = new CloudshareSharedMail(cloudshareSampleShare(), 'Betreff', $sender);

    expect($mailable->signatureLines())->toBe([
        'Max Mustermann',
        'Handwerkskammer Dortmund',
        'E-Mail: mail.sender.minimal@example.com',
    ]);
});
