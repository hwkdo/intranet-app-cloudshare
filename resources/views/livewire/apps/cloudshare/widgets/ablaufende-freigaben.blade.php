<?php

use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Support\CloudshareShareExpiration;
use Hwkdo\MsGraphLaravel\Exceptions\MicrosoftDelegatedTokenMissingException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

new class extends Component
{
    public bool $needsMicrosoftLogin = false;

    public string $errorMessage = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->can('see-app-cloudshare'), 403);
    }

    public function itemLimit(): int
    {
        $counts = Auth::user()?->settings->dashboard->personalGrid?->widgetItemCounts ?? [];
        $value = $counts['cloudshare.ablaufende-freigaben']
            ?? $counts['ablaufende-freigaben']
            ?? 5;

        return min(max((int) $value, 1), 30);
    }

    public function expiringSoonDays(): int
    {
        return CloudshareShareExpiration::expiringSoonDaysFor(Auth::user());
    }

    /**
     * @return list<array{name: string, id: string, expiration: ?string, daysUntilExpiry: int}>
     */
    #[Computed]
    public function expiringShares(): array
    {
        $this->needsMicrosoftLogin = false;
        $this->errorMessage = '';

        try {
            $shares = app(CloudshareServiceInterface::class)->listShares(Auth::user());
        } catch (MicrosoftDelegatedTokenMissingException $e) {
            $this->needsMicrosoftLogin = true;
            $this->errorMessage = $e->getMessage();

            return [];
        } catch (Throwable $e) {
            $this->errorMessage = 'Freigaben konnten nicht geladen werden.';

            return [];
        }

        $withinDays = $this->expiringSoonDays();

        return collect($shares)
            ->map(function (array $share) use ($withinDays): ?array {
                if (! CloudshareShareExpiration::isExpiringSoon($share, $withinDays)) {
                    return null;
                }

                $days = CloudshareShareExpiration::daysUntilExpiry($share['expiration'] ?? null);

                if ($days === null) {
                    return null;
                }

                return [
                    'name' => (string) ($share['name'] ?? ''),
                    'id' => (string) ($share['id'] ?? ''),
                    'expiration' => is_string($share['expiration'] ?? null) ? $share['expiration'] : null,
                    'daysUntilExpiry' => $days,
                ];
            })
            ->filter()
            ->sortBy('daysUntilExpiry')
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, id: string, expiration: ?string, daysUntilExpiry: int}>
     */
    #[Computed]
    public function items(): array
    {
        return array_values(array_slice($this->expiringShares, 0, $this->itemLimit()));
    }

    public function remainingDaysLabel(int $days): string
    {
        return CloudshareShareExpiration::remainingDaysLabel($days);
    }
};
?>

@placeholder
    <flux:card class="h-full">
        <div class="mb-3 space-y-2">
            <flux:skeleton class="h-4 w-52" />
            <flux:skeleton class="h-3 w-72" />
        </div>
        <div class="space-y-2">
            <flux:skeleton class="h-14 w-full rounded-md" />
            <flux:skeleton class="h-14 w-full rounded-md" />
            <flux:skeleton class="h-14 w-full rounded-md" />
        </div>
    </flux:card>
@endplaceholder

<div class="h-full min-h-0 flex flex-col">
    <x-intranet-app-base::dashboard.widget-card
        title="Bald ablaufende Freigaben"
        :description="'Ihre Freigaben, die in den nächsten '.$this->expiringSoonDays().' Tagen ablaufen (max. '.$this->itemLimit().')'"
    >
        @if ($needsMicrosoftLogin)
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>Microsoft-Anmeldung erforderlich</flux:callout.heading>
                <flux:callout.text>
                    Cloud Share nutzt Ihr Microsoft-Konto für OneDrive-Freigaben.
                </flux:callout.text>
            </flux:callout>
            <flux:button :href="route('auth.microsoft.redirect')" variant="primary" size="sm">
                Mit Microsoft anmelden
            </flux:button>
        @elseif ($errorMessage !== '')
            <flux:text class="text-zinc-500 dark:text-white/80">{{ $errorMessage }}</flux:text>
        @else
            @forelse ($this->items as $row)
                <a
                    href="{{ route('apps.cloudshare.index') }}"
                    wire:navigate
                    class="group block cursor-pointer rounded-md border border-amber-200 bg-amber-50/70 px-3 py-2 transition-colors duration-150 hover:bg-amber-100 dark:border-amber-500/40 dark:bg-amber-950/30 dark:hover:bg-amber-900/40"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium text-zinc-900 group-hover:text-zinc-950 dark:text-white">
                                {{ $row['name'] }}
                            </div>
                            <div class="mt-0.5 text-xs text-zinc-500 dark:text-white/70">
                                Gültig bis {{ $row['expiration'] }}
                            </div>
                        </div>
                        <div class="shrink-0 text-right text-xs font-medium text-amber-700 dark:text-amber-300">
                            {{ $this->remainingDaysLabel($row['daysUntilExpiry']) }}
                        </div>
                    </div>
                </a>
            @empty
                <flux:text class="text-zinc-500 dark:text-white/80">
                    Keine Freigaben laufen in den nächsten {{ $this->expiringSoonDays() }} Tagen ab.
                </flux:text>
            @endforelse

            @if (count($this->items) > 0)
                <div class="pt-1">
                    <flux:button variant="ghost" size="sm" :href="route('apps.cloudshare.index')" wire:navigate>
                        Alle Freigaben anzeigen
                    </flux:button>
                </div>
            @endif
        @endif
    </x-intranet-app-base::dashboard.widget-card>
</div>
