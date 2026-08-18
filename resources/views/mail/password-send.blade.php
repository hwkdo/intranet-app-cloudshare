<x-mail::message>
{{ $user->name }} stellt Ihnen über Cloud Share das Passwort für den Cloud-Ordner {{ $shareName }} bereit.

Das Passwort steht nicht in dieser E-Mail. Öffnen Sie den folgenden Link, um es über Bitwarden Send abzurufen:

<x-mail::button :url="$accessUrl">
Passwort abrufen
</x-mail::button>

Link zum Passwort: {{ $accessUrl }}

Der Link ist nur einmal verwendbar. Nach dem Abruf können Sie ihn nicht erneut öffnen. Kopieren Sie das Passwort daher direkt und bewahren Sie es sicher auf.

Wenn der Link bereits verwendet wurde oder Sie das Passwort nicht kopiert haben, fordern Sie bitte bei {{ $user->name }} ({{ $user->email }}) einen neuen Link beziehungsweise ein neues Passwort an.
</x-mail::message>
