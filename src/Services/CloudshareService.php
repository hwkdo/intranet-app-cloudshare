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
use Hwkdo\MsGraphLaravel\Interfaces\MsGraphDelegatedOneDriveFactoryInterface;
use Hwkdo\MsGraphLaravel\Interfaces\MsGraphOneDriveServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class CloudshareService implements CloudshareServiceInterface
{
    public function __construct(
        protected MsGraphDelegatedOneDriveFactoryInterface $oneDriveFactory,
    ) {}

    public function listShares(Authenticatable $user): array
    {
        $upn = $this->upn($user);
        $root = $this->rootFolder();
        $oneDrive = $this->driveFor($user);

        $oneDrive->makeFolder($upn, $root);

        $items = $oneDrive->getUserDriveContent($upn, $root) ?? [];

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
        foreach ($this->listShares($user) as $share) {
            if ($share['id'] === $id) {
                return $share;
            }
        }

        return null;
    }

    public function listFiles(Authenticatable $user, string $folderName): array
    {
        $upn = $this->upn($user);
        $path = $this->rootFolder().'/'.$folderName;
        $items = $this->driveFor($user)->getUserDriveContent($upn, $path) ?? [];

        $files = [];

        foreach ($items as $item) {
            $modified = $item->getLastModifiedDateTime();

            $files[] = [
                'file' => $item->getName(),
                'href' => $item->getWebUrl(),
                'modified' => $modified ? $this->formatAppDateTime($modified, 'd.m.Y H:i') : '',
                'size' => $item->getSize() ?? 0,
                'id' => $item->getId(),
            ];
        }

        return $files;
    }

    public function uploadFile(Authenticatable $user, string $folderName, string $localPath, string $originalFilename): mixed
    {
        $upn = $this->upn($user);
        $subdir = $this->rootFolder().'/'.$folderName;

        return $this->driveFor($user)->uploadItemToUserDrive($upn, $originalFilename, $localPath, $subdir);
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

        return $result;
    }

    public function quota(Authenticatable $user): ?array
    {
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

    protected function driveFor(Authenticatable $user): MsGraphOneDriveServiceInterface
    {
        return $this->oneDriveFactory->forUser($user);
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
        $perms = $oneDrive->getDriveItemPermissions($upn, $share->getId(), 'anonymous');
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
