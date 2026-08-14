<x-mail::message>
{{ $user->name }} stellt Ihnen das Zugangspasswort zur Cloudshare-Freigabe **{{ $shareName }}** sicher über Bitwarden Send bereit.

Das Passwort selbst steht nicht in dieser E-Mail. Öffnen Sie den folgenden Link, um es abzurufen:

<x-mail::button :url="$accessUrl">
Passwort sicher abrufen
</x-mail::button>

Bei Rückfragen wenden Sie sich bitte an {{ $user->name }} ({{ $user->email }}).
</x-mail::message>
