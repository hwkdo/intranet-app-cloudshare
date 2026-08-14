@props([
    'heading' => '',
    'subheading' => '',
    'navItems' => [],
])

@php
    $defaultNavItems = [
        ['label' => 'Freigaben', 'href' => route('apps.cloudshare.index'), 'icon' => 'cloud', 'description' => 'OneDrive-Freigaben verwalten', 'buttonText' => 'Freigaben anzeigen'],
        ['label' => 'Meine Einstellungen', 'href' => route('apps.cloudshare.settings.user'), 'icon' => 'cog-6-tooth', 'description' => 'Persönliche Einstellungen anpassen', 'buttonText' => 'Einstellungen öffnen', 'welcomeSection' => 'settings'],
        ['label' => 'App-Info', 'href' => route('apps.cloudshare.info'), 'icon' => 'information-circle', 'description' => 'Installierte Version und Release-Historie', 'buttonText' => 'App-Info anzeigen', 'welcomeSection' => 'settings'],
        ['label' => 'Admin', 'href' => route('apps.cloudshare.admin.index'), 'icon' => 'shield-check', 'description' => 'Administrationsbereich verwalten', 'buttonText' => 'Admin öffnen', 'permission' => 'manage-app-cloudshare', 'welcomeSection' => 'settings'],
    ];

    $navItems = ! empty($navItems) ? $navItems : $defaultNavItems;
    $customBgUrl = \Hwkdo\IntranetAppBase\Models\AppBackground::getCustomBackgroundUrl('cloudshare');
@endphp

@if($customBgUrl)
    @push('app-styles')
    <style data-app-bg data-ts="{{ uniqid() }}">
        :root { --app-bg-image: url('{{ $customBgUrl }}'); }
    </style>
    @endpush
@endif

<x-intranet-app-base::app-layout
    app-identifier="cloudshare"
    :heading="$heading"
    :subheading="$subheading"
    :nav-items="$navItems"
    :wrap-in-card="true"
>
    {{ $slot }}
</x-intranet-app-base::app-layout>
