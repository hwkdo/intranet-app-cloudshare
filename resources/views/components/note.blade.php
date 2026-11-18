@props([
    'icon' => 'information-circle',
    'title' => null,
])

<div {{ $attributes->class('glass-card flex items-start gap-3 px-5 py-4') }}>
    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-[#073070]/10 text-[#073070] dark:bg-white/10 dark:text-white">
        <flux:icon :icon="$icon" variant="micro" class="size-5" />
    </span>
    <div class="min-w-0 flex-1 text-sm text-slate-600 dark:text-white/70">
        @if (filled($title))
            <p class="font-semibold text-[#073070] dark:text-white">{{ $title }}</p>
        @endif
        <div @class(['mt-0.5' => filled($title)])>{{ $slot }}</div>
    </div>
    @isset($actions)
        <div class="shrink-0">{{ $actions }}</div>
    @endisset
</div>
