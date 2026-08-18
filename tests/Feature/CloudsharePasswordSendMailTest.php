<?php

declare(strict_types=1);

use App\Models\User;
use Hwkdo\IntranetAppCloudshare\Mail\CloudsharePasswordSendMail;

it('benennt die freigabe klar als cloud share und nennt den ordner', function (): void {
    $sender = User::factory()->create([
        'username' => 'bw.mail.sender',
        'vorname' => 'Erika',
        'nachname' => 'Muster',
        'email' => 'erika.muster@example.com',
    ]);

    $mailable = new CloudsharePasswordSendMail(
        'Projekt-X',
        'https://vault.example.com/send/abc',
        $sender,
    );

    $mailable->assertHasSubject('Passwort für den Cloud Share Ordner Projekt-X');
    $mailable->assertSeeInHtml('Cloud Share Ordner Projekt-X');
    $mailable->assertSeeInHtml('https://vault.example.com/send/abc');
    $mailable->assertDontSeeInHtml('Cloudshare');
    $mailable->assertDontSeeInHtml('Cloud Shift');
    $mailable->assertDontSeeInHtml('Zugangspasswort zur Cloudshare-Freigabe');

    $mailable->assertSeeInText('Cloud Share Ordner Projekt-X');
    $mailable->assertDontSeeInText('Cloudshare');
    $mailable->assertDontSeeInText('Cloud Shift');
});

it('erklaert die einmalige nutzung und den fehlerfall', function (): void {
    $sender = User::factory()->create([
        'username' => 'bw.mail.once',
        'vorname' => 'Erika',
        'nachname' => 'Muster',
        'email' => 'erika.muster.once@example.com',
    ]);

    $mailable = new CloudsharePasswordSendMail(
        'Projekt-X',
        'https://vault.example.com/send/abc',
        $sender,
    );

    $mailable->assertSeeInOrderInHtml([
        'Der Link ist nur einmal verwendbar',
        'nicht erneut öffnen',
        'Kopieren Sie das Passwort daher direkt',
        'Wenn der Link bereits verwendet wurde oder Sie das Passwort nicht kopiert haben',
        'Erika Muster',
        'erika.muster.once@example.com',
        'einen neuen Link beziehungsweise ein neues Passwort',
    ]);

    $mailable->assertSeeInOrderInText([
        'Der Link ist nur einmal verwendbar',
        'nicht erneut öffnen',
        'Kopieren Sie das Passwort daher direkt',
        'Wenn der Link bereits verwendet wurde oder Sie das Passwort nicht kopiert haben',
        'Erika Muster (erika.muster.once@example.com)',
        'einen neuen Link beziehungsweise ein neues Passwort',
    ]);
});
