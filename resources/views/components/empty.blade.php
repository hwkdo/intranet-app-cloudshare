@props([
    'icon' => 'cloud',
    'title' => 'Keine Einträge',
])

<div {{ $attributes->class('glass-card flex flex-col items-center gap-3 px-6 py-14 text-center') }}>
    <span class="grid size-12 place-items-center rounded-2xl bg-[#073070]/10 text-[#073070] dark:bg-white/10 dark:text-white">
        <flux:icon :icon="$icon" class="size-6" />
    </span>
    <p class="text-base font-semibold text-[#073070] dark:text-white">{{ $title }}</p>
    @if ($slot->isNotEmpty())
        <div class="max-w-md text-sm text-slate-600 dark:text-white/70">{{ $slot }}</div>
    @endif
</div>
