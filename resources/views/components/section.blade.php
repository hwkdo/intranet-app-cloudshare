@props([
    'icon' => null,
    'title' => null,
    'description' => null,
])

<section {{ $attributes->class('glass-card overflow-hidden') }}>
    @if (filled($title) || filled($icon) || isset($actions))
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#d0e3f9] px-5 py-4 dark:border-white/10">
            <div class="flex min-w-0 items-center gap-3">
                @if (filled($icon))
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-[#073070]/10 text-[#073070] dark:bg-white/10 dark:text-white">
                        <flux:icon :icon="$icon" variant="micro" class="size-5" />
                    </span>
                @endif
                <div class="min-w-0">
                    @if (filled($title))
                        <h2 class="text-sm font-semibold text-[#073070] dark:text-white">{{ $title }}</h2>
                    @endif
                    @if (filled($description))
                        <p class="text-xs text-slate-600 dark:text-white/70">{{ $description }}</p>
                    @endif
                </div>
            </div>
            @isset($actions)
                <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    {{ $slot }}
</section>
