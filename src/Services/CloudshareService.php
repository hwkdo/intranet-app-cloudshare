<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Services;

use Hwkdo\HwkAdminLaravel\HwkAdminService;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Data\AppSettings;
use Hwkdo\IntranetAppCloudshare\Mail\CloudsharePasswordSendMail;
use Hwkdo\IntranetAppCloudshare\Mail\CloudshareSharedMail;
use Hwkdo\IntranetAppCloudshare\Models\CloudshareShare;
use Hwkdo\IntranetAppCloudshare\Models\IntranetAppCloudshareSettings;
use Hwkdo\IntranetAppCloudshare\Support\CloudshareGraphCache;
use Hwkdo\IntranetAppCloudshare\Support\CloudshareShareExpiration;
use Hwkdo\MsGraphLaravel\Exceptions\MicrosoftDelegatedTokenMissingException;
use Hwkdo\MsGraphLaravel\Interfaces\MsGraphDelegatedOneDriveFactoryInterface;
use Hwkdo\MsGraphLaravel\Interfaces\MsGraphOneDriveServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class CloudshareService implements CloudshareServiceInterface
{
    protected ?MsGraphOneDriveServiceInterface $cachedDrive = null;

    protected int|string|null $cachedDriveUserId = null;

    public function __construct(
        protected MsGraphDelegatedOneDriveFactoryInterface $oneDriveFactory,
        protected CloudshareGraphCache $graphCache = new CloudshareGraphCache,
    ) {}

    public function listShares(Authenticatable $user, bool $forceRefresh = false): array
    {
        $userId = $user->getAuthIdentifier();

        return $this->graphCache->remember(
            $this->graphCache->sharesKey($userId),
            fn (): array => $this->fetchSharesFromGraph($user),
            $forceRefresh,
        );
    }

    public function createShare(Authenticatable $user, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('Name der Freigabe ist erforderlich.');
        }

        $password = isset($data['password']) && is_string($data['password']) && $data['password'] !== ''
            ? $data['password']
            : null;

        if ($password !== null && strlen($password) < 8) {
            throw new InvalidArgumentException('Passwort muss mindestens 8 Zeichen haben.');
        }

        $expiresAt = $this->normalizeShareExpiresAt((string) ($data['expires_at'] ?? ''));
        $expiresAtValue = $expiresAt->toIso8601String();

        $guestUpload = (bool) ($data['guest_upload'] ?? false);
        $upn = $this->upn($user);
        $path = $this->rootFolder().'/'.$name;
        $oneDrive = $this->driveFor($user);

        $folder = $oneDrive->makeFolder($upn, $path);
        $folderId = (string) $folder->getId();

        if ($guestUpload) {
            $url = $oneDrive->shareReadWrite($upn, $folderId, $password, $expiresAtValue);
        } else {
            $url = $oneDrive->shareReadOnly($upn, $folderId, $password, $expiresAtValue);
        }

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Die Freigabe konnte nicht erstellt werden.');
        }

        CloudshareShare::query()->updateOrCreate(
            [
                'user_id' => $user->getAuthIdentifier(),
                'onedrive_item_id' => $folderId,
            ],
            [
                'folder_name' => $name,
                'password' => $password,
            ],
        );

        $this->forgetUserGraphCache($user, $folderId);

        $storedByItemId = CloudshareShare::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->get()
            ->keyBy('onedrive_item_id');

        $shareData = $this->mapShare($folder, $upn, $storedByItemId, $oneDrive);

        if ($shareData !== null) {
            $shareData['url'] = $url;

            return $shareData;
        }

        return [
            'name' => $name,
            'id' => $folderId,
            'url' => $url,
            'created_at' => now()->format('d.m.Y H:i'),
            'password' => $password !== null,
            'has_stored_password' => $password !== null,
            'expiration' => $expiresAt->format('d.m.Y H:i').' Uhr',
            'writeable' => $guestUpload,
            'file_count' => 0,
        ];
    }

    public function findShare(Authenticatable $user, string $id): ?array
    {
        $stored = CloudshareShare::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('onedrive_item_id', $id)
            ->first();

        if ($stored !== null) {
            $hydrated = $this->findShareFromStoredRecord($user, $stored);

            if ($hydrated !== null) {
                return $hydrated;
            }
        }

        return $this->findShareViaGraph($user, $id);
    }

    public function extendShareExpiration(Authenticatable $user, string $shareId, string $expiresAt): array
    {
        $shareId = trim($shareId);

        if ($shareId === '') {
            throw new InvalidArgumentException('Freigabe nicht gefunden.');
        }

        $expiration = $this->normalizeShareExpiresAt($expiresAt);
        $upn = $this->upn($user);
        $oneDrive = $this->driveFor($user);
        $root = $this->rootFolder();

        $oneDrive->makeFolder($upn, $root);

        $folder = collect($oneDrive->getUserDriveContent($upn, $root) ?? [])->first(
            function (mixed $item) use ($shareId): bool {
                if (! is_object($item) || ! method_exists($item, 'getId')) {
                    return false;
                }

                return (string) $item->getId() === $shareId
                    && (bool) $item->getFolder()
                    && (bool) $item->getShared();
            },
        );

        if ($folder === null) {
            throw new InvalidArgumentException('Freigabe nicht gefunden.');
        }

        $this->applyShareExpiration($user, $oneDrive, $upn, $shareId, $expiration);

        $this->forgetUserGraphCache($user);

        $storedByItemId = CloudshareShare::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->get()
            ->keyBy('onedrive_item_id');

        $shareData = $this->mapShare($folder, $upn, $storedByItemId, $oneDrive);

        if ($shareData === null) {
            throw new RuntimeException('Die Gültigkeit konnte nicht verlängert werden.');
        }

        return $shareData;
    }

    public function listFiles(Authenticatable $user, string $folderName, bool $forceRefresh = false): array
    {
        return $this->graphCache->remember(
            $this->filesCacheKey($user, $folderName),
            fn (): array => $this->fetchFilesFromGraph($user, $folderName),
            $forceRefresh,
        );
    }

    /**
     * @param  list<array{id: string, name: string}>  $shares
     * @return array<string, list<array{file: string, href: string, modified: string, size: int|string, id: string}>>
     */
    public function listFilesForShares(Authenticatable $user, array $shares, bool $forceRefresh = false): array
    {
        if ($shares === []) {
            return [];
        }

        $userId = $user->getAuthIdentifier();
        $result = [];
        $missing = [];

        foreach ($shares as $share) {
            $shareId = (string) ($share['id'] ?? '');
            $folderName = (string) ($share['name'] ?? '');

            if ($shareId === '' || $folderName === '') {
                continue;
            }

            if (! $forceRefresh && $this->graphCache->enabled()) {
                $cached = Cache::get($this->graphCache->filesKey($userId, $shareId));

                if (is_array($cached)) {
                    $result[$shareId] = $cached;

                    continue;
                }
            }

            $missing[] = $share;
        }

        if ($missing === []) {
            return $result;
        }

        $upn = $this->upn($user);
        $root = $this->rootFolder();
        $oneDrive = $this->driveFor($user);
        $paths = [];
        $shareByPath = [];

        foreach ($missing as $share) {
            $path = $root.'/'.$share['name'];
            $paths[] = $path;
            $shareByPath[$path] = $share;
        }

        $contents = $oneDrive->batchGetUserDriveContents($upn, $paths);

        foreach ($shareByPath as $path => $share) {
            $shareId = (string) $share['id'];
            $files = $this->mapDriveItemsToFiles($contents[$path] ?? []);
            $result[$shareId] = $files;

            if ($this->graphCache->enabled()) {
                Cache::put(
                    $this->graphCache->filesKey($userId, $shareId),
                    $files,
                    $this->graphCache->ttl(),
                );
            }
        }

        return $result;
    }

    public function uploadFile(Authenticatable $user, string $folderName, string $localPath, string $originalFilename): mixed
    {
        $upn = $this->upn($user);
        $subdir = $this->rootFolder().'/'.$folderName;

        $result = $this->driveFor($user)->uploadItemToUserDrive($upn, $originalFilename, $localPath, $subdir);

        $this->forgetUserGraphCache($user, $this->shareIdForFolderName($user, $folderName));
        Cache::forget($this->filesCacheKey($user, $folderName));

        return $result;
    }

    public function deleteItem(Authenticatable $user, string $itemId): mixed
    {
        $upn = $this->upn($user);
        $oneDrive = $this->driveFor($user);
        $driveId = $oneDrive->getUserDrive($upn)->getId();

        $result = $oneDrive->deleteItemById($driveId, $itemId);

        CloudshareShare::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('onedrive_item_id', $itemId)
            ->delete();

        $this->forgetUserGraphCache($user, $itemId);

        return $result;
    }

    /**
     * @return array{deleted: int, skipped_users: int, failed: int}
     */
    public function purgeExpiredShares(?int $afterDays = null): array
    {
        $afterDays ??= $this->appSettings()->normalizedAutoDeleteAfterDays();
        $deleted = 0;
        $skippedUsers = 0;
        $failed = 0;

        foreach ($this->usersForExpiredSharePurge() as $user) {
            if (! $user instanceof Authenticatable) {
                continue;
            }

            try {
                $shares = $this->listShares($user, forceRefresh: true);
            } catch (MicrosoftDelegatedTokenMissingException) {
                $skippedUsers++;

                continue;
            } catch (Throwable $e) {
                $skippedUsers++;
                Log::warning('Cloud Share: Freigaben für automatisches Löschen konnten nicht geladen werden.', [
                    'user_id' => $user->getAuthIdentifier(),
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($shares as $share) {
                if (! CloudshareShareExpiration::isDueForAutoDelete($share, $afterDays)) {
                    continue;
                }

                try {
                    $this->deleteItem($user, (string) $share['id']);
                    $deleted++;
                    Log::info('Cloud Share: Abgelaufene Freigabe automatisch gelöscht.', [
                        'user_id' => $user->getAuthIdentifier(),
                        'share_id' => $share['id'] ?? null,
                        'share_name' => $share['name'] ?? null,
                    ]);
                } catch (Throwable $e) {
                    $failed++;
                    Log::warning('Cloud Share: Automatisches Löschen einer Freigabe ist fehlgeschlagen.', [
                        'user_id' => $user->getAuthIdentifier(),
                        'share_id' => $share['id'] ?? null,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }

            $this->forgetUserGraphCache($user);
        }

        return [
            'deleted' => $deleted,
            'skipped_users' => $skippedUsers,
            'failed' => $failed,
        ];
    }

    public function quota(Authenticatable $user, bool $forceRefresh = false): ?array
    {
        $userId = $user->getAuthIdentifier();

        return $this->graphCache->remember(
            $this->graphCache->quotaKey($userId),
            function () use ($user): ?array {
                $upn = $this->upn($user);
                $drive = $this->driveFor($user)->getUserDrive($upn);

                if (! $drive) {
                    return null;
                }

                $quota = $drive->getQuota();

                if (! $quota) {
                    return null;
                }

                $total = $quota->getTotal() ?: 0;
                $used = $quota->getUsed() ?: 0;
                $remaining = $quota->getRemaining();

                return [
                    'quota_free' => $remaining,
                    'quota_used' => $used,
                    'quota_total' => $total,
                    'quota_relative' => $total > 0 ? ($used / $total) * 100 : 0.0,
                ];
            },
            $forceRefresh,
        );
    }

    public function previewShareMail(Authenticatable $user, array $share, string $subject): string
    {
        return (new CloudshareSharedMail($share, $subject, $user))->render();
    }

    public function sendShareMail(
        Authenticatable $user,
        array $share,
        string $email,
        string $subject,
        bool $sendPasswordViaBitwarden = false,
    ): array {
        $mailable = new CloudshareSharedMail($share, $subject, $user);

        Mail::to($email)
            ->cc($user->email)
            ->send($mailable);

        if (! $sendPasswordViaBitwarden) {
            return [
                'bitwarden_sent' => false,
                'bitwarden_error' => null,
            ];
        }

        $result = $this->sendPasswordViaBitwarden($user, $share, $email);

        if ($result['bitwarden_error'] !== null) {
            $result['bitwarden_error'] = 'Freigabe-Mail wurde gesendet, '.$result['bitwarden_error'];
        }

        return $result;
    }

    public function sendPasswordViaBitwarden(Authenticatable $user, array $share, array|string $emails): array
    {
        $shareId = (string) ($share['id'] ?? '');
        $stored = CloudshareShare::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('onedrive_item_id', $shareId)
            ->first();

        if (! $stored) {
            return [
                'bitwarden_sent' => false,
                'bitwarden_error' => 'Kein hinterlegtes Passwort für diese Freigabe.',
            ];
        }

        $recipients = collect(is_array($emails) ? $emails : [$emails])
            ->map(fn (mixed $email): string => is_string($email) ? trim($email) : '')
            ->filter(fn (string $email): bool => $email !== '')
            ->unique(fn (string $email): string => strtolower($email))
            ->values()
            ->all();

        if ($recipients === []) {
            return [
                'bitwarden_sent' => false,
                'bitwarden_error' => 'Keine Empfänger-E-Mail angegeben.',
            ];
        }

        try {
            $appSettings = $this->appSettings();
            $maxAccessCount = max(
                (int) $appSettings->defaultBwSendMaxAccessCount,
                count($recipients),
            );

            $accessUrl = app(HwkAdminService::class)->createBitwardenSend(
                'Cloud Share: '.$share['name'],
                $stored->password,
                $maxAccessCount,
                $appSettings->defaultBwSendDeleteInDays,
            );

            if (! is_string($accessUrl) || $accessUrl === '' || ! str_starts_with($accessUrl, 'http')) {
                throw new RuntimeException('Bitwarden Send lieferte keine gültige URL.');
            }

            Mail::to($recipients)
                ->cc($user->email)
                ->send(new CloudsharePasswordSendMail($share['name'], $accessUrl, $user));

            return [
                'bitwarden_sent' => true,
                'bitwarden_error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'bitwarden_sent' => false,
                'bitwarden_error' => 'Bitwarden Send fehlgeschlagen: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return list<array{
     *     name: string,
     *     id: string,
     *     url: string,
     *     created_at: string,
     *     password: bool,
     *     has_stored_password: bool,
     *     expiration: ?string,
     *     writeable: bool,
     *     file_count: int
     * }>
     */
    protected function fetchSharesFromGraph(Authenticatable $user): array
    {
        $upn = $this->upn($user);
        $root = $this->rootFolder();
        $oneDrive = $this->driveFor($user);

        $oneDrive->makeFolder($upn, $root);

        $items = $this->listShareDriveItems($oneDrive, $upn, $root);

        $shares = collect($items)->filter(function ($item): bool {
            return (bool) $item->getFolder() && (bool) $item->getShared();
        });

        $storedByItemId = CloudshareShare::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->get()
            ->keyBy('onedrive_item_id');

        $result = [];

        foreach ($shares as $share) {
            $createdAt = $this->shareCreatedAt($share);
            $shareData = $this->mapShare($share, $upn, $storedByItemId, $oneDrive, $createdAt);

            if ($shareData !== null) {
                $result[] = [
                    'share' => $shareData,
                    'created_at' => $createdAt,
                ];
            }
        }

        return collect($result)
            ->sortByDesc(fn (array $item): int => $item['created_at']?->getTimestamp() ?? 0)
            ->pluck('share')
            ->values()
            ->all();
    }

    /**
     * Graph unterstützt $expand=permissions beim Listen von Kindern in manchen Tenants nicht
     * (OData: notSupported / „Operation not supported“). Dann Fallback ohne Expand.
     *
     * @return list<mixed>
     */
    protected function listShareDriveItems(MsGraphOneDriveServiceInterface $oneDrive, string $upn, string $root): array
    {
        try {
            return $oneDrive->getUserDriveContent($upn, $root, ['expand' => ['permissions']]) ?? [];
        } catch (Throwable) {
            return $oneDrive->getUserDriveContent($upn, $root) ?? [];
        }
    }

    /**
     * @return list<array{file: string, href: string, modified: string, size: int|string, id: string}>
     */
    protected function fetchFilesFromGraph(Authenticatable $user, string $folderName): array
    {
        $upn = $this->upn($user);
        $path = $this->rootFolder().'/'.$folderName;
        $items = $this->driveFor($user)->getUserDriveContent($upn, $path) ?? [];

        return $this->mapDriveItemsToFiles($items);
    }

    /**
     * @param  list<object>|array<int, mixed>  $items
     * @return list<array{file: string, href: string, modified: string, size: int|string, id: string}>
     */
    protected function mapDriveItemsToFiles(array $items): array
    {
        $files = [];

        foreach ($items as $item) {
            if (! is_object($item)) {
                continue;
            }

            $modified = method_exists($item, 'getLastModifiedDateTime')
                ? $item->getLastModifiedDateTime()
                : null;

            $files[] = [
                'file' => (string) (method_exists($item, 'getName') ? $item->getName() : ''),
                'href' => (string) (method_exists($item, 'getWebUrl') ? ($item->getWebUrl() ?? '') : ''),
                'modified' => $modified ? $this->formatAppDateTime($modified, 'd.m.Y H:i') : '',
                'size' => method_exists($item, 'getSize') ? ($item->getSize() ?? 0) : 0,
                'id' => (string) (method_exists($item, 'getId') ? $item->getId() : ''),
            ];
        }

        return $files;
    }

    /**
     * @return array{
     *     name: string,
     *     id: string,
     *     url: string,
     *     created_at: string,
     *     password: bool,
     *     has_stored_password: bool,
     *     expiration: ?string,
     *     writeable: bool,
     *     file_count: int
     * }|null
     */
    protected function findShareFromStoredRecord(Authenticatable $user, CloudshareShare $stored): ?array
    {
        $upn = $this->upn($user);
        $oneDrive = $this->driveFor($user);
        $storedByItemId = collect([$stored->onedrive_item_id => $stored]);

        $folder = $this->storedShareDriveItemStub($stored);

        try {
            return $this->mapShare($folder, $upn, $storedByItemId, $oneDrive, $stored->created_at);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     name: string,
     *     id: string,
     *     url: string,
     *     created_at: string,
     *     password: bool,
     *     has_stored_password: bool,
     *     expiration: ?string,
     *     writeable: bool,
     *     file_count: int
     * }|null
     */
    protected function findShareViaGraph(Authenticatable $user, string $id): ?array
    {
        foreach ($this->listShares($user, forceRefresh: true) as $share) {
            if ($share['id'] === $id) {
                return $share;
            }
        }

        return null;
    }

    protected function storedShareDriveItemStub(CloudshareShare $stored): object
    {
        $folderFacet = new class
        {
            public function getChildCount(): int
            {
                return 0;
            }
        };

        return new class($stored, $folderFacet)
        {
            public function __construct(
                private CloudshareShare $stored,
                private object $folderFacet,
            ) {}

            public function getId(): string
            {
                return (string) $this->stored->onedrive_item_id;
            }

            public function getName(): string
            {
                return (string) $this->stored->folder_name;
            }

            public function getFolder(): object
            {
                return $this->folderFacet;
            }

            public function getCreatedDateTime(): mixed
            {
                return $this->stored->created_at;
            }

            public function getFileSystemInfo(): null
            {
                return null;
            }

            public function getPermissions(): null
            {
                return null;
            }
        };
    }

    protected function shareIdForFolderName(Authenticatable $user, string $folderName): ?string
    {
        $stored = CloudshareShare::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('folder_name', $folderName)
            ->first();

        return $stored?->onedrive_item_id;
    }

    protected function filesCacheKey(Authenticatable $user, string $folderName): string
    {
        $shareId = $this->shareIdForFolderName($user, $folderName);
        $userId = $user->getAuthIdentifier();

        if ($shareId !== null) {
            return $this->graphCache->filesKey($userId, $shareId);
        }

        return 'cloudshare:files:'.$userId.':name:'.md5($folderName);
    }

    protected function forgetUserGraphCache(Authenticatable $user, ?string $extraShareId = null): void
    {
        $shareIds = CloudshareShare::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->pluck('onedrive_item_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();

        if (is_string($extraShareId) && $extraShareId !== '') {
            $shareIds[] = $extraShareId;
        }

        $this->graphCache->forgetUser($user->getAuthIdentifier(), $shareIds);
    }

    protected function driveFor(Authenticatable $user): MsGraphOneDriveServiceInterface
    {
        $userId = $user->getAuthIdentifier();

        if ($this->cachedDrive === null || $this->cachedDriveUserId !== $userId) {
            $this->cachedDrive = $this->oneDriveFactory->forUser($user);
            $this->cachedDriveUserId = $userId;
        }

        return $this->cachedDrive;
    }

    protected function upn(Authenticatable $user): string
    {
        $upn = $user->upn ?? null;

        if (! is_string($upn) || $upn === '') {
            throw new InvalidArgumentException('Benutzer hat keinen UPN für OneDrive.');
        }

        return $upn;
    }

    protected function rootFolder(): string
    {
        return (string) config('intranet-app-cloudshare.root_folder', 'Cloudshare');
    }

    protected function appSettings(): AppSettings
    {
        $settings = IntranetAppCloudshareSettings::current()?->settings;

        return $settings instanceof AppSettings
            ? $settings
            : new AppSettings;
    }

    /**
     * @return Collection<int, mixed>
     */
    protected function usersForExpiredSharePurge(): Collection
    {
        $userClass = config('intranet-app-cloudshare.user_model');

        if (! is_string($userClass) || ! class_exists($userClass)) {
            return collect();
        }

        $query = $userClass::query();

        if (method_exists($userClass, 'scopePermission')) {
            $query->permission('see-app-cloudshare');
        } else {
            $query->whereIn(
                'id',
                CloudshareShare::query()->distinct()->pluck('user_id'),
            );
        }

        return $query->get();
    }

    protected function applyShareExpiration(
        Authenticatable $user,
        MsGraphOneDriveServiceInterface $oneDrive,
        string $upn,
        string $shareId,
        Carbon $expiration,
    ): void {
        $iso = $expiration->toIso8601String();
        $perms = $oneDrive->getDriveItemPermissions($upn, $shareId, 'anonymous');
        $perm = collect($perms)->first();
        $permId = is_object($perm) && method_exists($perm, 'getId') ? $perm->getId() : null;
        $roles = is_object($perm) && method_exists($perm, 'getRoles') ? ($perm->getRoles() ?? []) : [];
        $writeable = in_array('write', $roles, true);

        if (is_string($permId) && $permId !== '') {
            try {
                $oneDrive->updateLink($upn, $shareId, $permId, [
                    'expirationDateTime' => $iso,
                ]);

                return;
            } catch (Throwable) {
            }
        }

        $password = $this->storedPasswordForShare($user, $shareId);
        $url = $writeable
            ? $oneDrive->shareReadWrite($upn, $shareId, $password, $iso)
            : $oneDrive->shareReadOnly($upn, $shareId, $password, $iso);

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Die Gültigkeit konnte nicht verlängert werden.');
        }
    }

    protected function storedPasswordForShare(Authenticatable $user, string $shareId): ?string
    {
        $stored = CloudshareShare::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('onedrive_item_id', $shareId)
            ->first();

        $password = $stored?->password;

        return is_string($password) && $password !== '' ? $password : null;
    }

    protected function normalizeShareExpiresAt(string $expiresAt): Carbon
    {
        $raw = trim($expiresAt);

        if ($raw === '') {
            throw new InvalidArgumentException('Gültigkeit ist erforderlich.');
        }

        $timezone = $this->appTimezone();
        $date = preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $matches) === 1
            ? $matches[1]
            : Carbon::parse($raw, $timezone)->timezone($timezone)->toDateString();

        $expiration = Carbon::createFromFormat('Y-m-d', $date, $timezone);

        if ($expiration === false) {
            throw new InvalidArgumentException('Gültigkeit ist ungültig.');
        }

        $expiration = $expiration->startOfDay();

        if ($expiration->lessThanOrEqualTo(now())) {
            throw new InvalidArgumentException('Die Gültigkeit muss in der Zukunft liegen.');
        }

        return $expiration;
    }

    protected function formatAppDateTime(mixed $value, string $format): string
    {
        return Carbon::parse($value)
            ->timezone($this->appTimezone())
            ->format($format);
    }

    protected function appTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    /**
     * @param  Collection<string, CloudshareShare>  $storedByItemId
     * @param  Carbon|null  $createdAt  Graph-Erstellungszeitpunkt des DriveItems
     * @return array{
     *     name: string,
     *     id: string,
     *     url: string,
     *     created_at: string,
     *     password: bool,
     *     has_stored_password: bool,
     *     expiration: ?string,
     *     writeable: bool,
     *     file_count: int
     * }|null
     */
    protected function mapShare(mixed $share, string $upn, Collection $storedByItemId, MsGraphOneDriveServiceInterface $oneDrive, ?Carbon $createdAt = null): ?array
    {
        $perms = $this->anonymousPermissionsForShare($share, $upn, $oneDrive);
        $perm = collect($perms)->first();

        if (! $perm || ! $perm->getLink()) {
            return null;
        }

        $expirationRaw = $perm->getExpirationDateTime();
        $expiration = $expirationRaw
            ? $this->formatAppDateTime($expirationRaw, 'd.m.Y H:i').' Uhr'
            : null;

        $createdAt ??= $this->shareCreatedAt($share);
        $roles = $perm->getRoles() ?? [];
        $itemId = (string) $share->getId();
        $hasPassword = (bool) $perm->getHasPassword();
        $stored = $storedByItemId->get($itemId);
        $storedPassword = $stored?->password;
        $hasStoredPassword = $hasPassword && is_string($storedPassword) && $storedPassword !== '';

        return [
            'name' => $share->getName(),
            'id' => $itemId,
            'url' => $this->shareUrlFromPermission($perm),
            'created_at' => $createdAt ? $this->formatAppDateTime($createdAt, 'd.m.Y H:i') : '',
            'password' => $hasPassword,
            'has_stored_password' => $hasStoredPassword,
            'expiration' => $expiration,
            'writeable' => in_array('write', $roles, true),
            'file_count' => $this->folderChildCount($share),
        ];
    }

    /**
     * @return Collection<int, mixed>|list<mixed>
     */
    protected function anonymousPermissionsForShare(mixed $share, string $upn, MsGraphOneDriveServiceInterface $oneDrive): Collection|array
    {
        $expanded = [];

        try {
            if (is_object($share) && method_exists($share, 'getPermissions')) {
                $expanded = $share->getPermissions() ?? [];
            }
        } catch (Throwable) {
            $expanded = [];
        }

        if (is_array($expanded) && $expanded !== []) {
            return collect($expanded)->filter(function ($perm): bool {
                return is_object($perm)
                    && method_exists($perm, 'getLink')
                    && $perm->getLink()
                    && method_exists($perm->getLink(), 'getScope')
                    && $perm->getLink()->getScope() === 'anonymous';
            })->values();
        }

        return $oneDrive->getDriveItemPermissions($upn, $share->getId(), 'anonymous');
    }

    /**
     * DriveItem.createdDateTime, Fallback fileSystemInfo.createdDateTime.
     */
    protected function shareCreatedAt(mixed $share): ?Carbon
    {
        foreach ($this->shareCreatedAtCandidates($share) as $candidate) {
            if ($candidate === null) {
                continue;
            }

            try {
                return Carbon::parse($candidate)->timezone($this->appTimezone());
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    protected function shareCreatedAtCandidates(mixed $share): array
    {
        if (! is_object($share)) {
            return [];
        }

        $candidates = [];

        try {
            if (method_exists($share, 'getCreatedDateTime')) {
                $candidates[] = $share->getCreatedDateTime();
            }
        } catch (Throwable) {
        }

        try {
            if (method_exists($share, 'getFileSystemInfo')) {
                $info = $share->getFileSystemInfo();

                if (is_object($info) && method_exists($info, 'getCreatedDateTime')) {
                    $candidates[] = $info->getCreatedDateTime();
                }
            }
        } catch (Throwable) {
        }

        return $candidates;
    }

    protected function folderChildCount(mixed $share): int
    {
        if (! is_object($share) || ! method_exists($share, 'getFolder')) {
            return 0;
        }

        try {
            $folder = $share->getFolder();
        } catch (Throwable) {
            return 0;
        }

        if (! is_object($folder) || ! method_exists($folder, 'getChildCount')) {
            return 0;
        }

        try {
            $count = $folder->getChildCount();
        } catch (Throwable) {
            return 0;
        }

        return is_numeric($count) ? max(0, (int) $count) : 0;
    }

    protected function shareUrlFromPermission(mixed $perm): string
    {
        $url = $perm->getLink()?->getWebUrl();

        return is_string($url) ? $url : '';
    }
}
