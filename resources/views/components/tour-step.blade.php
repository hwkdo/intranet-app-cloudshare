@props([
    'title' => 'Tour',
    'showBack' => false,
])

<x-intranet-app-cloudshare::note icon="map" :title="$title" {{ $attributes }}>
    <p>{{ $slot }}</p>
    <div class="mt-3 flex flex-wrap gap-2">
        @if ($showBack)
            <flux:button type="button" size="sm" variant="ghost" x-on:click="window.IntranetTours?.back?.()">
                Zurück
            </flux:button>
        @endif
        <flux:button type="button" size="sm" variant="primary" x-on:click="window.IntranetTours?.next?.()">
            Weiter
        </flux:button>
    </div>
</x-intranet-app-cloudshare::note>
