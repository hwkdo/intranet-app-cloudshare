<x-mail::message>
<img src="https://www.hwk-do.de/wp-content/uploads/2024/06/cloudshare-e1718175949841.png" alt="Cloudshare">

<x-mail::panel>
{{ $user->name }} hat einen Cloud Ordner für Sie freigegeben
</x-mail::panel>

<x-mail::button :url="$share['url']">
Hier geht's direkt zur Freigabe
</x-mail::button>

@if(! empty($share['password']) || (! empty($share['expiration']) && is_string($share['expiration'])) || ! empty($share['writeable']))
<x-mail::panel>
@if(! empty($share['password']))
Die Freigabe ist Passwortgeschützt
@endif
@if(! empty($share['expiration']) && is_string($share['expiration']))
Die Freigabe ist gültig bis {{ $share['expiration'] }}
@endif
@if(! empty($share['writeable']))
Gast-Upload ist aktiviert: Sie können Daten hinzufügen
@endif
</x-mail::panel>
@endif

<x-mail::panel>
Bei Rückfragen wenden Sie sich bitte an:

{{ $user->vorname }} {{ $user->nachname }}
@if(! empty($user->title))
{{ $user->title }}
@endif
@if(! empty($user->extension1))
{{ $user->extension1 }}
@endif
@if(! empty($user->extension2))
{{ $user->extension2 }}
@endif
@if(! empty($user->extension3))
{{ $user->extension3 }}
@endif
Handwerkskammer Dortmund
@if(! empty($user->adresse))
{{ $user->adresse }}
@endif
@if(! empty($user->plz) || ! empty($user->ort))
{{ $user->plz }} {{ $user->ort }}
@endif
@if(! empty($user->telefon))
Tel.: {{ $user->telefon }}
@endif
@if(! empty($user->fax))
Fax: {{ $user->fax }}
@endif
E-Mail: {{ $user->email }}
</x-mail::panel>
</x-mail::message>
