<x-mail::message>
<img src="https://www.hwk-do.de/wp-content/uploads/2024/06/cloudshare-e1718175949841.png" alt="Cloud Share">

{{ $user->name }} hat den Cloud-Ordner {{ $shareName }} für Sie freigegeben.

Freigabe: {{ $shareName }}

Link zur Freigabe: {{ $shareUrl }}

<x-mail::button :url="$shareUrl">
Zur Freigabe
</x-mail::button>

Eigenschaften der Freigabe:

- Passwortschutz: {{ $passwordProtectionLabel }}
- Gültig bis: {{ $expirationLabel }}
- Gast-Upload: {{ $guestUploadLabel }}

Bei Rückfragen wenden Sie sich bitte an:

{{ $signatureMarkdown }}
</x-mail::message>
