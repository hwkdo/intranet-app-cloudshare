<?php

use Flux\Flux;
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

    public bool $showExtendModal = false;

    public string $extendShareId = '';

    public string $extendShareName = '';

    public string $extendExpiresAt = '';

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
     * @return list<array{name: string, id: string, expiration: ?string, daysUntilExpiry: int, expired: bool}>
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
                if (! CloudshareShareExpiration::needsExpirationAttention($share, $withinDays)) {
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
                    'expired' => $days < 0,
                ];
            })
            ->filter()
            ->sortBy('daysUntilExpiry')
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, id: string, expiration: ?string, daysUntilExpiry: int, expired: bool}>
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

    public function openExtendModal(string $shareId, string $shareName): void
    {
        $this->extendShareId = $shareId;
        $this->extendShareName = $shareName;
        $this->extendExpiresAt = now()->addDays($this->expiringSoonDays())->toDateString();
        $this->resetErrorBag(['extendExpiresAt', 'extendShareId']);
        $this->showExtendModal = true;
    }

    public function extendShareExpiration(CloudshareServiceInterface $cloudshare): void
    {
        $this->validate([
            'extendShareId' => ['required', 'string'],
            'extendExpiresAt' => ['required', 'date', 'after:today'],
        ], [
            'extendExpiresAt.after' => 'Die Gültigkeit muss in der Zukunft liegen.',
        ], [
            'extendExpiresAt' => 'Gültigkeit',
        ]);

        try {
            $cloudshare->extendShareExpiration(Auth::user(), $this->extendShareId, $this->extendExpiresAt);
            $this->showExtendModal = false;
            $this->extendShareId = '';
            $this->extendShareName = '';
            $this->extendExpiresAt = '';
            unset($this->expiringShares, $this->items);
            Flux::toast(variant: 'success', text: 'Gültigkeit wurde verlängert.');
        } catch (MicrosoftDelegatedTokenMissingException $e) {
            $this->showExtendModal = false;
            $this->needsMicrosoftLogin = true;
            $this->errorMessage = $e->getMessage();
        } catch (Throwable $e) {
            $this->addError('extendExpiresAt', $e->getMessage());
        }
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
        title="Ablaufende Freigaben"
        :description="'Ihre Freigaben, die abgelaufen sind oder in den nächsten '.$this->expiringSoonDays().' Tagen ablaufen (max. '.$this->itemLimit().')'"
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
                <div
                    @class([
                        'rounded-md border px-3 py-2',
                        'border-red-200 bg-red-50/70 dark:border-red-500/40 dark:bg-red-950/30' => $row['expired'],
                        'border-amber-200 bg-amber-50/70 dark:border-amber-500/40 dark:bg-amber-950/30' => ! $row['expired'],
                    ])
                >
                    <div class="flex items-start justify-between gap-2">
                        <a
                            href="{{ route('apps.cloudshare.index') }}"
                            wire:navigate
                            class="group min-w-0 flex-1"
                        >
                            <div class="truncate font-medium text-zinc-900 group-hover:text-zinc-950 dark:text-white">
                                {{ $row['name'] }}
                            </div>
                            <div class="mt-0.5 text-xs text-zinc-500 dark:text-white/70">
                                Gültig bis {{ $row['expiration'] }}
                            </div>
                        </a>
                        <div
                            @class([
                                'shrink-0 text-right text-xs font-medium',
                                'text-red-700 dark:text-red-300' => $row['expired'],
                                'text-amber-700 dark:text-amber-300' => ! $row['expired'],
                            ])
                        >
                            {{ $this->remainingDaysLabel($row['daysUntilExpiry']) }}
                        </div>
                    </div>
                    <div class="mt-2">
                        <flux:button
                            size="sm"
                            variant="ghost"
                            icon="calendar-days"
                            wire:click="openExtendModal({{ \Illuminate\Support\Js::from($row['id']) }}, {{ \Illuminate\Support\Js::from($row['name']) }})"
                        >
                            Gültigkeit verlängern
                        </flux:button>
                    </div>
                </div>
            @empty
                <flux:text class="text-zinc-500 dark:text-white/80">
                    Keine abgelaufenen oder bald ablaufenden Freigaben.
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

    <flux:modal wire:model="showExtendModal" class="md:w-[32rem] space-y-6">
        <div>
            <flux:heading size="lg">Gültigkeit verlängern</flux:heading>
            <flux:text class="mt-1">
                Neues Ablaufdatum für die Freigabe „{{ $extendShareName }}“ setzen.
            </flux:text>
        </div>

        <form wire:submit="extendShareExpiration" class="space-y-4">
            <flux:input
                wire:model="extendExpiresAt"
                label="Gültigkeit"
                type="date"
                required
                min="{{ now()->addDay()->toDateString() }}"
                description="Die Freigabe endet um 00:00 Uhr am gewählten Tag."
            />
            <flux:error name="extendExpiresAt" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showExtendModal', false)">Abbrechen</flux:button>
                <flux:button type="submit" variant="primary">Gültigkeit setzen</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
