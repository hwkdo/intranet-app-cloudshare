<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cloud Share - App-Info')] class extends Component
{
};
?>

<div>
    <x-intranet-app-cloudshare::cloudshare-layout heading="App-Info" subheading="Installierte Version und Release-Historie">
        @livewire('intranet-app-base::app-info', ['appIdentifier' => 'cloudshare'])
    </x-intranet-app-cloudshare::cloudshare-layout>
</div>
