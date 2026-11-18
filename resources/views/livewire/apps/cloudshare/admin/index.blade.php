<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cloud Share - Admin')] class extends Component
{
    public string $activeTab = 'einstellungen';

    public function mount(): void
    {
        $tab = request()->query('tab');

        if (is_string($tab) && in_array($tab, ['einstellungen', 'hintergrundbild', 'hintergrund'], true)) {
            $this->activeTab = $tab;
        }
    }
};
?>

<div>
    <x-intranet-app-cloudshare::cloudshare-layout heading="Administration" subheading="Einstellungen, Hintergrundbild und Hintergrundaktivitäten" icon="shield-check">
        <flux:tab.group>
            <div class="glass-card sticky top-3 z-20 p-3" style="--glass-opacity: 0.92">
                <flux:tabs variant="pills" class="hwk-pills" wire:model.live="activeTab">
                    <flux:tab name="einstellungen" icon="cog-6-tooth">Einstellungen</flux:tab>
                    <flux:tab name="hintergrundbild" icon="photo">Hintergrundbild</flux:tab>
                    <flux:tab name="hintergrund" icon="clock">Hintergrund</flux:tab>
                </flux:tabs>
            </div>

            <flux:tab.panel name="einstellungen">
                @if ($activeTab === 'einstellungen')
                    <div class="glass-card min-h-[400px] overflow-hidden p-5">
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
                    <div class="glass-card min-h-[400px] overflow-hidden p-5">
                        @livewire('intranet-app-base::app-background-image', [
                            'appIdentifier' => 'cloudshare',
                        ], key('cloudshare-admin-background'))
                    </div>
                @endif
            </flux:tab.panel>

            <flux:tab.panel name="hintergrund">
                @if ($activeTab === 'hintergrund')
                    <div class="glass-card min-h-[400px] overflow-hidden p-5">
                        @livewire('intranet-app-base::background-activities', ['appIdentifier' => 'cloudshare'], key('cloudshare-admin-hintergrund'))
                    </div>
                @endif
            </flux:tab.panel>
        </flux:tab.group>
    </x-intranet-app-cloudshare::cloudshare-layout>
</div>
