@props([
    'label',
    'description' => null,
    'wide' => false,
])

<div {{ $attributes->class([
    'flex flex-col gap-3 py-4',
    'sm:flex-row sm:items-center sm:justify-between sm:gap-8' => ! $wide,
]) }}>
    <div class="min-w-0 flex-1">
        <p class="text-sm font-medium text-[#073070] dark:text-white">{{ $label }}</p>
        @if (filled($description))
            <p class="mt-0.5 text-sm text-slate-600 dark:text-white/70">{{ $description }}</p>
        @endif
    </div>

    @if ($slot->isNotEmpty())
        <div @class([
            'min-w-0 text-sm text-slate-600 dark:text-white/70',
            'w-full' => $wide,
            'w-full sm:w-80 sm:shrink-0' => ! $wide,
        ])>
            {{ $slot }}
        </div>
    @endif
</div>
