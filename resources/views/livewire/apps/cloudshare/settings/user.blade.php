<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cloud Share - Meine Einstellungen')] class extends Component
{
};
?>

<div>
    <x-intranet-app-cloudshare::cloudshare-layout heading="Meine Einstellungen" subheading="Persönliche Einstellungen für Cloud Share">
        @livewire('intranet-app-base::user-settings', ['appIdentifier' => 'cloudshare'])
    </x-intranet-app-cloudshare::cloudshare-layout>
</div>
