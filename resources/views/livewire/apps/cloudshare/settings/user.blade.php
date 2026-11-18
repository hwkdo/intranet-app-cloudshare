<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cloud Share - Meine Einstellungen')] class extends Component
{
};
?>

<div>
    <x-intranet-app-cloudshare::cloudshare-layout heading="Meine Einstellungen" subheading="Persönliche Einstellungen für Cloud Share" icon="cog-6-tooth">
        <div class="glass-card overflow-hidden p-5">
            @livewire('intranet-app-base::user-settings', ['appIdentifier' => 'cloudshare'])
        </div>
    </x-intranet-app-cloudshare::cloudshare-layout>
</div>
