<?php

use Flux\Flux;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Data\AppSettings;
use Hwkdo\IntranetAppCloudshare\Mail\CloudshareSharedMail;
use Hwkdo\IntranetAppCloudshare\Models\IntranetAppCloudshareSettings;
use Hwkdo\MsGraphLaravel\Exceptions\MicrosoftDelegatedTokenMissingException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Cloudshare - Freigaben')] class extends Component
{
    use WithFileUploads;

    /** @var list<array{name: string, id: string, url: string, created_at: string, password: bool, has_stored_password: bool, expiration: ?string, writeable: bool, file_count?: int}> */
    public array $shares = [];

    /** @var array{quota_free: int|float|null, quota_used: int|float|null, quota_total: int|float|null, quota_relative: float}|null */
    public ?array $quota = null;

    /** @var array<string, list<array{file: string, href: string, modified: string, size: int|string, id: string}>> */
    public array $filesByShareId = [];

    public bool $showCreateModal = false;

    public bool $showUploadModal = false;

    public bool $showShareModal = false;

    public string $newName = '';

    public string $newPassword = '';

    public string $newExpiresAt = '';

    public bool $newGuestUpload = false;

    public string $uploadFolderName = '';

    public string $uploadShareId = '';

    public ?TemporaryUploadedFile $uploadFile = null;

    public string $shareIdForMail = '';

    public string $shareMailSubject = '';

    public string $shareMailEmail = '';

    public string $shareMailPreview = '';

    public bool $sendPasswordViaBitwarden = false;

    public string $errorMessage = '';

    public bool $needsMicrosoftLogin = false;

    public string $hinweisText = '';

    public string $search = '';

    /** @var list<string> */
    public array $fileIdsSeenOnOpen = [];

    /** @var list<string> */
    public array $newFileIdsSinceOpen = [];

    /** @var list<string> */
    public array $updatedShareIdsSinceOpen = [];

    public function mount(CloudshareServiceInterface $cloudshare): void
    {
        $this->hinweisText = $this->appSettings()->hinweisText;

        $this->refreshData($cloudshare);
        $this->fileIdsSeenOnOpen = $this->currentFileIds();
    }

    public function refreshData(?CloudshareServiceInterface $cloudshare = null): void
    {
        $cloudshare ??= app(CloudshareServiceInterface::class);
        $user = Auth::user();

        try {
            $this->shares = $cloudshare->listShares($user);
            $this->quota = $cloudshare->quota($user);
            $this->errorMessage = '';
            $this->needsMicrosoftLogin = false;

            foreach ($this->shares as $share) {
                $this->filesByShareId[$share['id']] = $cloudshare->listFiles($user, $share['name']);
            }

            $this->pruneHighlightsToExistingFiles();
        } catch (MicrosoftDelegatedTokenMissingException $e) {
            $this->markMicrosoftLoginRequired($e);
        } catch (\Throwable $e) {
            $this->needsMicrosoftLogin = false;
            $this->errorMessage = 'OneDrive konnte nicht geladen werden: '.$e->getMessage();
            $this->shares = [];
            $this->quota = null;
            $this->clearHighlights();
        }
    }

    public function openCreateModal(): void
    {
        $this->resetCreateForm();
        $this->showCreateModal = true;
    }

    public function createShare(CloudshareServiceInterface $cloudshare): void
    {
        $this->validate([
            'newName' => ['required', 'string', 'max:200'],
            'newPassword' => ['nullable', 'string', 'min:8'],
            'newExpiresAt' => ['required', 'date', 'after:today'],
            'newGuestUpload' => ['boolean'],
        ], [
            'newExpiresAt.after' => 'Die Gültigkeit muss in der Zukunft liegen.',
        ], [
            'newName' => 'Name',
            'newPassword' => 'Passwort',
            'newExpiresAt' => 'Gültigkeit',
            'newGuestUpload' => 'Gast-Upload',
        ]);

        try {
            $cloudshare->createShare(Auth::user(), [
                'name' => $this->newName,
                'password' => $this->newPassword !== '' ? $this->newPassword : null,
                'expires_at' => $this->newExpiresAt,
                'guest_upload' => $this->newGuestUpload,
            ]);

            $this->showCreateModal = false;
            $this->resetCreateForm();
            $this->refreshData($cloudshare);
            Flux::toast(variant: 'success', text: 'Freigabe wurde erstellt.');
        } catch (MicrosoftDelegatedTokenMissingException $e) {
            $this->showCreateModal = false;
            $this->markMicrosoftLoginRequired($e);
        } catch (\Throwable $e) {
            $this->addError('newName', $e->getMessage());
        }
    }

    public function openUploadModal(string $shareId, string $folderName): void
    {
        if ($this->quotaRelative() >= 90) {
            return;
        }

        $this->uploadShareId = $shareId;
        $this->uploadFolderName = $folderName;
        $this->uploadFile = null;
        $this->resetErrorBag('uploadFile');
        $this->showUploadModal = true;
    }

    public function removeUploadFile(): void
    {
        if ($this->uploadFile instanceof TemporaryUploadedFile) {
            $this->uploadFile->delete();
        }

        $this->uploadFile = null;
        $this->resetErrorBag('uploadFile');
    }

    public function uploadToShare(CloudshareServiceInterface $cloudshare): void
    {
        $maxKb = (int) config('intranet-app-cloudshare.max_upload_kb', 256000);

        $this->validate([
            'uploadFile' => ['required', 'file', 'max:'.$maxKb],
            'uploadFolderName' => ['required', 'string'],
        ], [], [
            'uploadFile' => 'Datei',
        ]);

        try {
            $cloudshare->uploadFile(
                Auth::user(),
                $this->uploadFolderName,
                $this->uploadFile->getRealPath(),
                $this->uploadFile->getClientOriginalName(),
            );

            $this->showUploadModal = false;
            $this->uploadFile = null;
            $this->refreshData($cloudshare);
            Flux::toast(variant: 'success', text: 'Datei wurde hochgeladen.');
        } catch (\Throwable $e) {
            $this->addError('uploadFile', $e->getMessage());
        }
    }

    public function deleteItem(CloudshareServiceInterface $cloudshare, string $itemId): void
    {
        try {
            $cloudshare->deleteItem(Auth::user(), $itemId);
            $this->refreshData($cloudshare);
            Flux::toast(variant: 'success', text: 'Element wurde gelöscht.');
        } catch (\Throwable $e) {
            $this->errorMessage = 'Löschen fehlgeschlagen: '.$e->getMessage();
        }
    }

    public function refreshGuestUploads(?CloudshareServiceInterface $cloudshare = null): void
    {
        if (! $this->shouldPollGuestUploads()) {
            return;
        }

        $cloudshare ??= app(CloudshareServiceInterface::class);
        $user = Auth::user();

        try {
            foreach ($this->shares as $index => $share) {
                if (! ($share['writeable'] ?? false)) {
                    continue;
                }

                $previousIds = collect($this->filesByShareId[$share['id']] ?? [])
                    ->pluck('id')
                    ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
                    ->values()
                    ->all();

                $files = $cloudshare->listFiles($user, $share['name']);
                $this->filesByShareId[$share['id']] = $files;
                $this->shares[$index]['file_count'] = count($files);

                $arrivedIds = collect($files)
                    ->pluck('id')
                    ->filter(fn (mixed $id): bool => is_string($id) && $id !== '' && ! in_array($id, $previousIds, true) && ! in_array($id, $this->fileIdsSeenOnOpen, true))
                    ->values()
                    ->all();

                foreach ($arrivedIds as $arrivedId) {
                    if (! in_array($arrivedId, $this->newFileIdsSinceOpen, true)) {
                        $this->newFileIdsSinceOpen[] = $arrivedId;
                    }
                }

                if ($arrivedIds !== []) {
                    if (! in_array($share['id'], $this->updatedShareIdsSinceOpen, true)) {
                        $this->updatedShareIdsSinceOpen[] = $share['id'];
                    }

                    $newCount = count($arrivedIds);

                    if ($newCount === 1) {
                        Flux::toast(variant: 'success', text: 'Neue Datei in „'.$share['name'].'“.');
                    } else {
                        Flux::toast(variant: 'success', text: $newCount.' neue Dateien in „'.$share['name'].'“.');
                    }
                }
            }

            $this->pruneHighlightsToExistingFiles();
        } catch (MicrosoftDelegatedTokenMissingException $e) {
            $this->markMicrosoftLoginRequired($e);
        } catch (\Throwable) {
            // Bestehende Dateiliste bei Polling-Fehlern behalten.
        }
    }

    public function guestUploadPollSeconds(): int
    {
        $seconds = $this->appSettings()->guestUploadPollSeconds;

        if ($seconds <= 0) {
            return 0;
        }

        return max(3, min(60, $seconds));
    }

    public function shouldPollGuestUploads(): bool
    {
        if ($this->guestUploadPollSeconds() <= 0) {
            return false;
        }

        if ($this->needsMicrosoftLogin || $this->errorMessage !== '') {
            return false;
        }

        if ($this->showCreateModal || $this->showUploadModal || $this->showShareModal) {
            return false;
        }

        return collect($this->shares)->contains(
            fn (array $share): bool => (bool) ($share['writeable'] ?? false),
        );
    }

    public function openShareModal(CloudshareServiceInterface $cloudshare, string $shareId): void
    {
        $share = collect($this->shares)->firstWhere('id', $shareId);

        if (! $share) {
            return;
        }

        $this->shareIdForMail = $shareId;
        $this->shareMailSubject = CloudshareSharedMail::subjectForShare((string) ($share['name'] ?? ''));
        $this->shareMailEmail = '';
        $this->sendPasswordViaBitwarden = false;
        $this->resetErrorBag(['shareMailEmail', 'shareMailSubject', 'sendPasswordViaBitwarden']);

        try {
            $this->shareMailPreview = $cloudshare->previewShareMail(
                Auth::user(),
                $share,
                $this->shareMailSubject,
            );
            $this->showShareModal = true;
        } catch (\Throwable $e) {
            $this->errorMessage = 'Vorschau fehlgeschlagen: '.$e->getMessage();
        }
    }

    public function updatedShareMailSubject(): void
    {
        $share = collect($this->shares)->firstWhere('id', $this->shareIdForMail);

        if (! $share || ! $this->showShareModal) {
            return;
        }

        try {
            $this->shareMailPreview = app(CloudshareServiceInterface::class)->previewShareMail(
                Auth::user(),
                $share,
                $this->shareMailSubject,
            );
        } catch (\Throwable) {
            // Vorschau optional aktualisieren
        }
    }

    public function sendShareMail(CloudshareServiceInterface $cloudshare): void
    {
        $this->validate([
            'shareMailEmail' => ['required', 'email'],
            'shareMailSubject' => ['required', 'string', 'max:255'],
        ], [], [
            'shareMailEmail' => 'Empfänger',
            'shareMailSubject' => 'Betreff',
        ]);

        $share = collect($this->shares)->firstWhere('id', $this->shareIdForMail);

        if (! $share) {
            return;
        }

        try {
            $result = $cloudshare->sendShareMail(
                Auth::user(),
                $share,
                $this->shareMailEmail,
                $this->shareMailSubject,
                $this->sendPasswordViaBitwarden,
            );
            $this->shareMailEmail = '';
            $this->showShareModal = false;
            $this->sendPasswordViaBitwarden = false;

            if ($result['bitwarden_error']) {
                Flux::toast(variant: 'warning', text: $result['bitwarden_error']);
            } elseif ($result['bitwarden_sent']) {
                Flux::toast(variant: 'success', text: 'Freigabe-Mail und Bitwarden-Send-Mail wurden gesendet.');
            } else {
                Flux::toast(variant: 'success', text: 'E-Mail wurde gesendet.');
            }
        } catch (\Throwable $e) {
            $this->addError('shareMailEmail', $e->getMessage());
        }
    }

    public function quotaRelative(): float
    {
        return (float) ($this->quota['quota_relative'] ?? 0);
    }

    public function formatBytes(int|float|null $bytes): string
    {
        $bytes = (float) ($bytes ?? 0);

        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / 1024 / 1024 / 1024, 2, ',', '.').' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 2, ',', '.').' MB';
        }

        return number_format($bytes / 1024, 2, ',', '.').' KB';
    }

    public function formatFileSize(int|float|string|null $bytes): string
    {
        $bytes = (float) ($bytes ?? 0);

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 2, ',', '.').' MB';
        }

        return number_format($bytes / 1024, 2, ',', '.').' KB';
    }

    public function maxUploadDescription(): string
    {
        $maxKb = (int) config('intranet-app-cloudshare.max_upload_kb', 256000);
        $mb = (int) round($maxKb / 1024);

        if ($mb >= 1) {
            return 'Eine Datei, maximal '.$mb.' MB';
        }

        return 'Eine Datei, maximal '.$maxKb.' KB';
    }

    /**
     * @return list<array{file: string, href: string, modified: string, size: int|string, id: string}>
     */
    public function filesForShare(string $shareId): array
    {
        return collect($this->filesByShareId[$shareId] ?? [])
            ->sortByDesc(fn (array $file): bool => $this->fileIsNewSinceOpen((string) ($file['id'] ?? '')))
            ->values()
            ->all();
    }

    public function fileIsNewSinceOpen(string $fileId): bool
    {
        return $fileId !== '' && in_array($fileId, $this->newFileIdsSinceOpen, true);
    }

    public function shareHasUpdatesSinceOpen(string $shareId): bool
    {
        return $shareId !== '' && in_array($shareId, $this->updatedShareIdsSinceOpen, true);
    }

    public function newFileCountForShare(string $shareId): int
    {
        return collect($this->filesByShareId[$shareId] ?? [])
            ->filter(fn (array $file): bool => $this->fileIsNewSinceOpen((string) ($file['id'] ?? '')))
            ->count();
    }

    public function searchTerm(): string
    {
        return mb_strtolower(trim($this->search));
    }

    /**
     * @return list<array{name: string, id: string, url: string, created_at: string, password: bool, has_stored_password: bool, expiration: ?string, writeable: bool, file_count?: int}>
     */
    #[Computed]
    public function filteredShares(): array
    {
        $term = $this->searchTerm();

        if ($term === '') {
            return $this->shares;
        }

        return collect($this->shares)
            ->filter(fn (array $share): bool => $this->shareMatchesSearch($share, $term))
            ->values()
            ->all();
    }

    /**
     * @param  array{name: string, id: string}  $share
     */
    protected function shareMatchesSearch(array $share, string $term): bool
    {
        if (str_contains(mb_strtolower((string) $share['name']), $term)) {
            return true;
        }

        return collect($this->filesByShareId[$share['id']] ?? [])
            ->contains(function (array $file) use ($term): bool {
                return str_contains(mb_strtolower((string) ($file['file'] ?? '')), $term);
            });
    }

    public function currentShareForMail(): ?array
    {
        $share = collect($this->shares)->firstWhere('id', $this->shareIdForMail);

        return is_array($share) ? $share : null;
    }

    public function currentShareHasStoredPassword(): bool
    {
        return (bool) ($this->currentShareForMail()['has_stored_password'] ?? false);
    }

    public function currentShareHasPasswordFlag(): bool
    {
        return (bool) ($this->currentShareForMail()['password'] ?? false);
    }

    protected function markMicrosoftLoginRequired(MicrosoftDelegatedTokenMissingException $e): void
    {
        $this->needsMicrosoftLogin = true;
        $this->errorMessage = $e->getMessage();
        $this->shares = [];
        $this->quota = null;
        $this->clearHighlights();
    }

    /**
     * @return list<string>
     */
    protected function currentFileIds(): array
    {
        return collect($this->filesByShareId)
            ->flatten(1)
            ->map(fn (mixed $file): mixed => is_array($file) ? ($file['id'] ?? null) : null)
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function pruneHighlightsToExistingFiles(): void
    {
        $currentIds = $this->currentFileIds();
        $this->newFileIdsSinceOpen = array_values(array_intersect($this->newFileIdsSinceOpen, $currentIds));

        $this->updatedShareIdsSinceOpen = collect($this->updatedShareIdsSinceOpen)
            ->filter(fn (string $shareId): bool => $this->newFileCountForShare($shareId) > 0)
            ->values()
            ->all();
    }

    protected function clearHighlights(): void
    {
        $this->newFileIdsSinceOpen = [];
        $this->updatedShareIdsSinceOpen = [];
    }

    protected function appSettings(): AppSettings
    {
        $settings = IntranetAppCloudshareSettings::current()?->settings;

        return $settings instanceof AppSettings
            ? $settings
            : new AppSettings;
    }

    protected function resetCreateForm(): void
    {
        $this->newName = '';
        $this->newPassword = '';
        $this->newExpiresAt = '';
        $this->newGuestUpload = false;
        $this->resetErrorBag(['newName', 'newPassword', 'newExpiresAt', 'newGuestUpload']);
    }
};
?>

<div>
    <x-intranet-app-cloudshare::cloudshare-layout heading="Cloudshare" subheading="Temporäre OneDrive-Freigaben für Externe">
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg">Freigaben</flux:heading>
                <div class="flex w-full flex-wrap items-center justify-end gap-2 sm:w-auto">
                    <div class="w-full sm:w-72">
                        <flux:input
                            wire:model.live.debounce.300ms="search"
                            type="search"
                            icon="magnifying-glass"
                            placeholder="Freigaben und Dateien suchen"
                            clearable
                            aria-label="Freigaben und Dateien suchen"
                            :disabled="$needsMicrosoftLogin || count($shares) === 0"
                        />
                    </div>
                    <flux:button variant="primary" icon="plus" wire:click="openCreateModal" :disabled="$needsMicrosoftLogin">Neu</flux:button>
                </div>
            </div>

            @if ($this->searchTerm() !== '' && count($shares) > 0)
                <flux:text class="text-sm">{{ count($this->filteredShares) }} von {{ count($shares) }} Freigaben</flux:text>
            @endif

            @if ($needsMicrosoftLogin)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.heading>Microsoft-Anmeldung erforderlich</flux:callout.heading>
                    <flux:callout.text>
                        Cloudshare nutzt Ihr Microsoft-Konto für OneDrive-Freigaben.
                        {{ $errorMessage }}
                    </flux:callout.text>
                </flux:callout>
                <flux:button :href="route('auth.microsoft.redirect')" variant="primary">
                    Mit Microsoft anmelden
                </flux:button>
            @elseif ($errorMessage)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.heading>Fehler</flux:callout.heading>
                    <flux:callout.text>{{ $errorMessage }}</flux:callout.text>
                </flux:callout>
            @endif

            @if ($hinweisText !== '')
                <flux:callout icon="information-circle">
                    <flux:callout.text>{{ $hinweisText }}</flux:callout.text>
                </flux:callout>
            @endif

            @if ($quota)
                @php
                    $relative = $this->quotaRelative();
                    $quotaVariant = $relative >= 90 ? 'danger' : ($relative >= 70 ? 'warning' : 'success');
                    $quotaText = $relative >= 90
                        ? 'Es stehen nur noch '.$this->formatBytes($quota['quota_free']).' zur Verfügung. Keine Uploads möglich.'
                        : ($relative >= 70
                            ? 'Es stehen nur noch '.$this->formatBytes($quota['quota_free']).' zur Verfügung.'
                            : 'Es stehen noch '.$this->formatBytes($quota['quota_free']).' zur Verfügung.');
                @endphp
                <flux:callout :variant="$quotaVariant" icon="circle-stack">
                    <flux:callout.heading>OneDrive-Speicher</flux:callout.heading>
                    <flux:callout.text>
                        Sie verwenden aktuell {{ $this->formatBytes($quota['quota_used']) }} von insgesamt
                        {{ $this->formatBytes($quota['quota_total']) }} Speicherplatz
                        ({{ number_format($relative, 2, ',', '.') }} %).
                        {{ $quotaText }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div wire:loading.flex wire:target="refreshData,createShare,uploadToShare,deleteItem" class="hidden items-center gap-2 text-sm text-zinc-500">
                <flux:icon.arrow-path class="size-4 animate-spin" />
                Wird geladen …
            </div>

            @if (count($shares) === 0 && $errorMessage === '')
                <flux:card class="text-center py-10">
                    <flux:heading size="md">Sie haben noch keine Freigaben</flux:heading>
                    <flux:text class="mt-2">Legen Sie eine neue Freigabe an, um Dateien mit Externen zu teilen.</flux:text>
                </flux:card>
            @elseif (count($this->filteredShares) === 0 && $this->searchTerm() !== '')
                <flux:card class="text-center py-10">
                    <flux:heading size="md">Keine Treffer</flux:heading>
                    <flux:text class="mt-2">Keine Freigaben oder Dateien zu „{{ $search }}“.</flux:text>
                </flux:card>
            @endif

            <div
                class="space-y-4"
                @if ($this->shouldPollGuestUploads())
                    wire:poll.{{ $this->guestUploadPollSeconds() }}s.visible="refreshGuestUploads"
                @endif
            >
                @if ($this->shouldPollGuestUploads())
                    <flux:text class="text-sm text-zinc-500">Freigaben mit Gast-Upload werden automatisch aktualisiert.</flux:text>
                @endif
                @foreach ($this->filteredShares as $share)
                    <flux:card
                        wire:key="share-{{ $share['id'] }}"
                        @class([
                            'space-y-4',
                            'ring-2 ring-blue-500 bg-blue-50! dark:bg-blue-900! dark:ring-sky-400' => $this->shareHasUpdatesSinceOpen($share['id']),
                        ])
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <flux:heading size="md">{{ $share['name'] }}</flux:heading>
                                <flux:text class="text-sm">erstellt {{ $share['created_at'] }}</flux:text>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if (is_string($share['url']) && $share['url'] !== '')
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="clipboard"
                                        tooltip="Link kopieren"
                                        aria-label="Link kopieren"
                                        x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($share['url']) }}).then(() => $flux.toast({ text: 'Link kopiert', variant: 'success' })).catch(() => $flux.toast({ text: 'Kopieren fehlgeschlagen', variant: 'danger' }))"
                                    />
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="arrow-top-right-on-square"
                                        :href="$share['url']"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        tooltip="Link öffnen"
                                        aria-label="Link öffnen"
                                    />
                                @endif
                                @if ($this->quotaRelative() < 90)
                                    <flux:button size="sm" variant="primary" icon="arrow-up-tray" wire:click="openUploadModal('{{ $share['id'] }}', {{ \Illuminate\Support\Js::from($share['name']) }})">
                                        Upload
                                    </flux:button>
                                @endif
                                <flux:button size="sm" variant="ghost" icon="envelope" wire:click="openShareModal({{ \Illuminate\Support\Js::from($share['id']) }})">
                                    Teilen
                                </flux:button>
                                <flux:button
                                    size="sm"
                                    variant="danger"
                                    icon="trash"
                                    wire:click="deleteItem({{ \Illuminate\Support\Js::from($share['id']) }})"
                                    wire:confirm="Freigabe und alle Dateien wirklich löschen?"
                                >
                                    Löschen
                                </flux:button>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <div class="flex flex-wrap gap-2">
                                @if ($this->shareHasUpdatesSinceOpen($share['id']))
                                    <flux:badge color="lime">Aktualisiert</flux:badge>
                                @endif
                                @if ($share['password'])
                                    <flux:badge color="red">Passwortgeschützt</flux:badge>
                                @endif
                                @if (is_string($share['expiration']))
                                    <flux:badge color="amber">Gültig bis {{ $share['expiration'] }}</flux:badge>
                                @endif
                                @if ($share['writeable'])
                                    <flux:badge color="blue">Gast-Upload</flux:badge>
                                @endif
                            </div>
                        </div>

                        @if (count($this->filesForShare($share['id'])) > 0)
                            <div>
                                <flux:heading size="sm" class="mb-2">Dateien</flux:heading>
                                <flux:table>
                                    <flux:table.columns>
                                        <flux:table.column>Name</flux:table.column>
                                        <flux:table.column>Größe</flux:table.column>
                                        <flux:table.column></flux:table.column>
                                    </flux:table.columns>
                                    <flux:table.rows>
                                        @foreach ($this->filesForShare($share['id']) as $file)
                                            <flux:table.row
                                                wire:key="file-{{ $file['id'] }}"
                                                @class([
                                                    'bg-blue-50! dark:bg-blue-800!' => $this->fileIsNewSinceOpen((string) $file['id']),
                                                ])
                                            >
                                                <flux:table.cell>
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span>{{ $file['file'] }}</span>
                                                        @if ($this->fileIsNewSinceOpen((string) $file['id']))
                                                            <flux:badge color="lime">Neu</flux:badge>
                                                        @endif
                                                    </div>
                                                </flux:table.cell>
                                                <flux:table.cell>{{ $this->formatFileSize($file['size']) }}</flux:table.cell>
                                                <flux:table.cell class="text-right">
                                                    <div class="flex justify-end gap-2">
                                                        <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="$file['href']" target="_blank" />
                                                        <flux:button
                                                            size="sm"
                                                            variant="danger"
                                                            icon="trash"
                                                            wire:click="deleteItem({{ \Illuminate\Support\Js::from($file['id']) }})"
                                                            wire:confirm="Datei wirklich löschen?"
                                                        />
                                                    </div>
                                                </flux:table.cell>
                                            </flux:table.row>
                                        @endforeach
                                    </flux:table.rows>
                                </flux:table>
                            </div>
                        @else
                            <flux:text class="text-sm text-zinc-500">Keine Dateien</flux:text>
                        @endif
                    </flux:card>
                @endforeach
            </div>
        </div>

        <flux:modal wire:model="showCreateModal" class="md:w-[32rem] space-y-6">
            <div>
                <flux:heading size="lg">Neue Freigabe</flux:heading>
                <flux:text class="mt-1">Ordner in OneDrive anlegen und anonymen Link erzeugen.</flux:text>
            </div>

            <form wire:submit="createShare" class="space-y-4">
                <flux:input wire:model="newName" label="Name" required />
                <flux:input wire:model="newPassword" label="Passwort" type="text" placeholder="Optional, mindestens 8 Zeichen" />
                <flux:input
                    wire:model="newExpiresAt"
                    label="Gültigkeit"
                    type="date"
                    required
                    min="{{ now()->addDay()->toDateString() }}"
                    description="Die Freigabe endet um 00:00 Uhr am gewählten Tag."
                />
                <flux:error name="newExpiresAt" />
                <flux:checkbox wire:model="newGuestUpload" label="Gast-Upload erlauben" />

                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="ghost" wire:click="$set('showCreateModal', false)">Abbrechen</flux:button>
                    <flux:button type="submit" variant="primary">Absenden</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal wire:model="showUploadModal" class="md:w-[32rem] space-y-6">
            <div>
                <flux:heading size="lg">Upload</flux:heading>
                <flux:text class="mt-1">Datei in die Freigabe „{{ $uploadFolderName }}“ hochladen.</flux:text>
            </div>

            <form wire:submit="uploadToShare" class="space-y-4">
                <flux:file-upload wire:model="uploadFile" label="Datei">
                    <flux:file-upload.dropzone
                        heading="Datei hierher ziehen oder klicken"
                        :text="$this->maxUploadDescription()"
                        with-progress
                    />
                </flux:file-upload>

                @if ($uploadFile)
                    <flux:file-item
                        :heading="$uploadFile->getClientOriginalName()"
                        :size="$uploadFile->getSize()"
                        :invalid="$errors->has('uploadFile')"
                    >
                        <x-slot name="actions">
                            <flux:file-item.remove wire:click="removeUploadFile" aria-label="Datei entfernen" />
                        </x-slot>
                    </flux:file-item>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="ghost" wire:click="$set('showUploadModal', false)">Abbrechen</flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Hochladen</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal wire:model="showShareModal" class="w-full max-w-5xl space-y-6 md:w-[56rem]">
            <div>
                <flux:heading size="lg">Freigabe teilen</flux:heading>
                <flux:text class="mt-1">E-Mail-Vorschau und Versand an einen Empfänger.</flux:text>
            </div>

            @if ($shareMailPreview !== '')
                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <iframe
                        title="E-Mail-Vorschau"
                        src="data:text/html;charset=utf-8;base64,{{ base64_encode($shareMailPreview) }}"
                        class="h-[28rem] w-full bg-white"
                        wire:key="mail-preview-{{ md5($shareMailPreview) }}"
                    ></iframe>
                </div>
            @endif

            <form wire:submit="sendShareMail" class="space-y-4">
                <flux:input wire:model.live.debounce.500ms="shareMailSubject" label="Betreff" required />
                <flux:input wire:model="shareMailEmail" label="Empfänger" type="email" required />

                @if ($this->currentShareHasStoredPassword())
                    <flux:checkbox
                        wire:model.live="sendPasswordViaBitwarden"
                        label="Passwort per Bitwarden Send sicher übermitteln"
                        description="Zusätzlich zur Freigabe-Mail wird eine separate Mail mit Bitwarden-Send-Link versendet."
                    />
                    @if ($sendPasswordViaBitwarden)
                        <flux:callout icon="shield-check">
                            <flux:callout.text>
                                Das Passwort wird separat per Bitwarden Send übermittelt (nicht in der Freigabe-Mail).
                            </flux:callout.text>
                        </flux:callout>
                    @endif
                @elseif ($this->currentShareHasPasswordFlag())
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.text>
                            Kein hinterlegtes Passwort — Bitwarden Send ist für diese ältere Freigabe nicht verfügbar.
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="ghost" wire:click="$set('showShareModal', false)">Schließen</flux:button>
                    <flux:button type="submit" variant="primary">Senden</flux:button>
                </div>
            </form>
        </flux:modal>
    </x-intranet-app-cloudshare::cloudshare-layout>
</div>
