<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cloudshare - Admin')] class extends Component
{
    public string $activeTab = 'einstellungen';

    public function mount(): void
    {
        $tab = request()->query('tab');

        if (is_string($tab) && in_array($tab, ['einstellungen', 'hintergrundbild'], true)) {
            $this->activeTab = $tab;
        }
    }
};
?>

<div>
    <x-intranet-app-cloudshare::cloudshare-layout heading="Cloudshare" subheading="Administration">
        <flux:tab.group>
            <flux:tabs wire:model.live="activeTab">
                <flux:tab name="einstellungen" icon="cog-6-tooth">Einstellungen</flux:tab>
                <flux:tab name="hintergrundbild" icon="photo">Hintergrundbild</flux:tab>
            </flux:tabs>

            <flux:tab.panel name="einstellungen">
                @if ($activeTab === 'einstellungen')
                    <div class="min-h-[400px]">
                        @livewire('intranet-app-base::admin-settings', [
                            'appIdentifier' => 'cloudshare',
                            'settingsModelClass' => '\Hwkdo\IntranetAppCloudshare\Models\IntranetAppCloudshareSettings',
                            'appSettingsClass' => '\Hwkdo\IntranetAppCloudshare\Data\AppSettings',
                        ], key('cloudshare-admin-settings'))
                    </div>
                @endif
            </flux:tab.panel>

            <flux:tab.panel name="hintergrundbild">
                @if ($activeTab === 'hintergrundbild')
                    <div class="min-h-[400px]">
                        @livewire('intranet-app-base::app-background-image', [
                            'appIdentifier' => 'cloudshare',
                        ], key('cloudshare-admin-background'))
                    </div>
                @endif
            </flux:tab.panel>
        </flux:tab.group>
    </x-intranet-app-cloudshare::cloudshare-layout>
</div>
