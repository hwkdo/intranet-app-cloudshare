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
use Hwkdo\MsGraphLaravel\Interfaces\MsGraphOneDriveServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class CloudshareService implements CloudshareServiceInterface
{
    public function __construct(
        protected MsGraphOneDriveServiceInterface $oneDrive,
    ) {}

    public function listShares(Authenticatable $user): array
    {
        $upn = $this->upn($user);
        $root = $this->rootFolder();

        $this->oneDrive->makeFolder($upn, $root);

        $items = $this->oneDrive->getUserDriveContent($upn, $root) ?? [];

        $shares = collect($items)->filter(function ($item): bool {
            return (bool) $item->getFolder() && (bool) $item->getShared();
        });

        $storedIds = CloudshareShare::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->pluck('onedrive_item_id')
            ->all();

        $result = [];

        foreach ($shares as $share) {
            $shareData = $this->mapShare($share, $upn, $storedIds);

            if ($shareData !== null) {
                $result[] = $shareData;
            }
        }

        return $result;
    }

    public function createShare(Authenticatable $user, array $data): bool
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

        $expiresAt = (string) ($data['expires_at'] ?? '');

        if ($expiresAt === '') {
            throw new InvalidArgumentException('Gültigkeit ist erforderlich.');
        }

        if (Carbon::parse($expiresAt)->lessThanOrEqualTo(now())) {
            throw new InvalidArgumentException('Die Gültigkeit muss in der Zukunft liegen.');
        }

        $guestUpload = (bool) ($data['guest_upload'] ?? false);
        $upn = $this->upn($user);
        $path = $this->rootFolder().'/'.$name;

        $folder = $this->oneDrive->makeFolder($upn, $path);
        $folderId = $folder->getId();

        if ($guestUpload) {
            $url = $this->oneDrive->shareReadWrite($upn, $folderId, $password, $expiresAt);
        } else {
            $url = $this->oneDrive->shareReadOnly($upn, $folderId, $password, $expiresAt);
        }

        if (! $url) {
            return false;
        }

        if ($password !== null) {
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
        }

        return true;
    }

    public function listFiles(Authenticatable $user, string $folderName): array
    {
        $upn = $this->upn($user);
        $path = $this->rootFolder().'/'.$folderName;
        $items = $this->oneDrive->getUserDriveContent($upn, $path) ?? [];

        $files = [];

        foreach ($items as $item) {
            $modified = $item->getLastModifiedDateTime();

            $files[] = [
                'file' => $item->getName(),
                'href' => $item->getWebUrl(),
                'modified' => $modified ? Carbon::parse($modified)->format('d.m.Y H:i') : '',
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

        return $this->oneDrive->uploadItemToUserDrive($upn, $originalFilename, $localPath, $subdir);
    }

    public function deleteItem(Authenticatable $user, string $itemId): mixed
    {
        $upn = $this->upn($user);
        $driveId = $this->oneDrive->getUserDrive($upn)->getId();

        $result = $this->oneDrive->deleteItemById($driveId, $itemId);

        CloudshareShare::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('onedrive_item_id', $itemId)
            ->delete();

        return $result;
    }

    public function quota(Authenticatable $user): ?array
    {
        $upn = $this->upn($user);
        $drive = $this->oneDrive->getUserDrive($upn);

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

        try {
            $appSettings = $this->appSettings();

            $accessUrl = app(HwkAdminService::class)->createBitwardenSend(
                'Cloudshare: '.$share['name'],
                $stored->password,
                $appSettings->defaultBwSendMaxAccessCount,
                $appSettings->defaultBwSendDeleteInDays,
            );

            if (! is_string($accessUrl) || $accessUrl === '' || ! str_starts_with($accessUrl, 'http')) {
                throw new RuntimeException('Bitwarden Send lieferte keine gültige URL.');
            }

            Mail::to($email)
                ->cc($user->email)
                ->send(new CloudsharePasswordSendMail($share['name'], $accessUrl, $user));

            return [
                'bitwarden_sent' => true,
                'bitwarden_error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'bitwarden_sent' => false,
                'bitwarden_error' => 'Freigabe-Mail wurde gesendet, Bitwarden Send fehlgeschlagen: '.$e->getMessage(),
            ];
        }
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
     * @param  list<string>  $storedIds
     * @return array{
     *     name: string,
     *     id: string,
     *     url: string,
     *     created_at: string,
     *     password: bool,
     *     has_stored_password: bool,
     *     expiration: ?string,
     *     writeable: bool
     * }|null
     */
    protected function mapShare(mixed $share, string $upn, array $storedIds = []): ?array
    {
        $perms = $this->oneDrive->getDriveItemPermissions($upn, $share->getId(), 'anonymous');
        $perm = collect($perms)->first();

        if (! $perm || ! $perm->getLink()) {
            return null;
        }

        $expirationRaw = $perm->getExpirationDateTime();
        $expiration = $expirationRaw
            ? Carbon::parse($expirationRaw)->format('d.m.Y H:i').' Uhr'
            : null;

        $created = $share->getFileSystemInfo()?->getCreatedDateTime();
        $roles = $perm->getRoles() ?? [];
        $itemId = (string) $share->getId();
        $hasPassword = (bool) $perm->getHasPassword();

        return [
            'name' => $share->getName(),
            'id' => $itemId,
            'url' => $perm->getLink()->getWebUrl(),
            'created_at' => $created ? Carbon::parse($created)->format('d.m.Y H:i') : '',
            'password' => $hasPassword,
            'has_stored_password' => $hasPassword && in_array($itemId, $storedIds, true),
            'expiration' => $expiration,
            'writeable' => in_array('write', $roles, true),
        ];
    }
}
