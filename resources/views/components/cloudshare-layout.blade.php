@props([
    'heading' => '',
    'subheading' => '',
    'icon' => 'cloud',
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
    $currentPath = trim(\Livewire\Livewire::originalPath(), '/');

    $isCurrent = function (string $href) use ($currentPath): bool {
        $path = trim((string) parse_url($href, PHP_URL_PATH), '/');

        if ($path === 'apps/cloudshare') {
            return $currentPath === 'apps/cloudshare';
        }

        return $currentPath === $path || str_starts_with($currentPath, $path.'/');
    };
@endphp

@if ($customBgUrl)
    @push('app-styles')
        <style data-app-bg data-ts="{{ uniqid() }}">
            :root { --app-bg-image: url('{{ $customBgUrl }}'); }
        </style>
    @endpush
@endif

<div class="hwk-cloudshare space-y-4">
    <style>
        .hwk-cloudshare {
            --color-accent: #073070;
            --color-accent-content: #073070;
            --color-accent-foreground: #ffffff;
        }

        .dark .hwk-cloudshare {
            --color-accent: #d0e3f9;
            --color-accent-content: #d0e3f9;
            --color-accent-foreground: #04214e;
        }

        .hwk-cloudshare ui-tabs.hwk-pills {
            height: auto !important;
            flex-wrap: wrap;
            gap: 0.375rem;
            border-bottom-width: 0 !important;
        }

        .hwk-cloudshare ui-tabs.hwk-pills [data-flux-tab] {
            height: auto;
            margin-bottom: 0 !important;
            border-bottom-width: 0 !important;
            border-radius: 9999px;
            padding: 0.375rem 0.75rem;
            background: rgb(255 255 255 / 0.8) !important;
            color: #073070 !important;
            box-shadow: inset 0 0 0 1px #d0e3f9;
        }

        .dark .hwk-cloudshare ui-tabs.hwk-pills [data-flux-tab] {
            background: rgb(255 255 255 / 0.1) !important;
            color: #fff !important;
            box-shadow: inset 0 0 0 1px rgb(255 255 255 / 0.15);
        }

        .hwk-cloudshare ui-tabs.hwk-pills [data-flux-tab][data-selected],
        .dark .hwk-cloudshare ui-tabs.hwk-pills [data-flux-tab][data-selected] {
            background: #073070 !important;
            color: #fff !important;
            box-shadow: 0 4px 6px rgb(7 48 112 / 0.25);
        }
    </style>

    <section class="hwk-hero relative overflow-hidden rounded-2xl text-white shadow-xl shadow-[#073070]/25 ring-1 ring-white/10 motion-safe:animate-rise-in">
        <div aria-hidden="true" class="pointer-events-none absolute -top-28 -right-20 size-96 rounded-full bg-[#a0c7f2]/40 blur-3xl"></div>
        <div aria-hidden="true" class="pointer-events-none absolute -bottom-36 -left-20 size-80 rounded-full bg-[#d0e3f9]/30 blur-3xl"></div>
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-0 opacity-10 [background-image:linear-gradient(to_right,white_1px,transparent_1px),linear-gradient(to_bottom,white_1px,transparent_1px)] [background-size:2rem_2rem] [mask-image:radial-gradient(ellipse_at_top_right,black,transparent_70%)]"
        ></div>

        <div class="relative flex flex-wrap items-center gap-x-8 gap-y-5 p-5 sm:px-7 sm:py-6">
            <div class="flex min-w-0 flex-1 basis-80 items-center gap-4">
                <span class="hidden size-14 shrink-0 place-items-center rounded-2xl bg-white/10 ring-1 ring-white/20 backdrop-blur-sm sm:grid">
                    <flux:icon :icon="$icon" class="size-7" />
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold tracking-[0.2em] text-[#d0e3f9] uppercase">Cloud Share</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">{{ $heading }}</h1>
                    @if (filled($subheading))
                        <p class="mt-1 max-w-2xl text-sm text-[#d0e3f9]/90">{{ $subheading }}</p>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <div class="flex items-start gap-6 max-lg:flex-col">
        <nav
            aria-label="Cloud Share"
            class="glass-card w-full shrink-0 p-2 lg:sticky lg:top-4 lg:w-64"
            style="--glass-opacity: 0.92"
        >
            <ul class="space-y-0.5">
                @foreach ($navItems as $navItem)
                    @if (($navItem['type'] ?? '') === 'separator')
                        @continue
                    @endif
                    @if (isset($navItem['permission']) && ! auth()->user()?->can($navItem['permission']))
                        @continue
                    @endif
                    @php
                        $current = isset($navItem['href']) && $isCurrent((string) $navItem['href']);
                    @endphp
                    <li>
                        <a
                            href="{{ $navItem['href'] }}"
                            wire:navigate
                            @class([
                                'flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#073070]',
                                'bg-[#073070] text-white shadow-md shadow-[#073070]/25' => $current,
                                'text-[#073070] hover:bg-[#d0e3f9]/80 dark:text-white dark:hover:bg-white/10' => ! $current,
                            ])
                            @if ($current) aria-current="page" @endif
                        >
                            <flux:icon :icon="$navItem['icon'] ?? 'document-text'" variant="micro" class="size-4 shrink-0" />
                            <span class="truncate">{{ $navItem['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="min-w-0 flex-1 space-y-4">
            {{ $slot }}
        </div>
    </div>
</div>

@push('nav-items')
    @json($navItems)
@endpush
