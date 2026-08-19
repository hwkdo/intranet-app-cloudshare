<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface CloudshareServiceInterface
{
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
    public function listShares(Authenticatable $user, bool $forceRefresh = false): array;

    /**
     * @param  array{name: string, password?: ?string, expires_at: string, guest_upload: bool}  $data
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
     * }
     */
    public function createShare(Authenticatable $user, array $data): array;

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
    public function findShare(Authenticatable $user, string $id): ?array;

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
     * }
     */
    public function extendShareExpiration(Authenticatable $user, string $shareId, string $expiresAt): array;

    /**
     * @return list<array{file: string, href: string, modified: string, size: int|string, id: string}>
     */
    public function listFiles(Authenticatable $user, string $folderName, bool $forceRefresh = false): array;

    /**
     * @param  list<array{id: string, name: string}>  $shares
     * @return array<string, list<array{file: string, href: string, modified: string, size: int|string, id: string}>>
     */
    public function listFilesForShares(Authenticatable $user, array $shares, bool $forceRefresh = false): array;

    public function uploadFile(Authenticatable $user, string $folderName, string $localPath, string $originalFilename): mixed;

    public function deleteItem(Authenticatable $user, string $itemId): mixed;

    /**
     * @return array{deleted: int, skipped_users: int, failed: int}
     */
    public function purgeExpiredShares(?int $afterDays = null): array;

    /**
     * @return array{quota_free: int|float|null, quota_used: int|float|null, quota_total: int|float|null, quota_relative: float}|null
     */
    public function quota(Authenticatable $user, bool $forceRefresh = false): ?array;

    /**
     * @param  array{name: string, url: string, password?: bool, has_stored_password?: bool, expiration?: ?string, writeable?: bool}  $share
     */
    public function previewShareMail(Authenticatable $user, array $share, string $subject): string;

    /**
     * @param  array{name: string, id?: string, url: string, password?: bool, has_stored_password?: bool, expiration?: ?string, writeable?: bool}  $share
     * @return array{bitwarden_sent: bool, bitwarden_error: ?string}
     */
    public function sendShareMail(
        Authenticatable $user,
        array $share,
        string $email,
        string $subject,
        bool $sendPasswordViaBitwarden = false,
    ): array;

    /**
     * @param  array{name: string, id?: string, url?: string, password?: bool, has_stored_password?: bool, expiration?: ?string, writeable?: bool}  $share
     * @param  list<string>|string  $emails
     * @return array{bitwarden_sent: bool, bitwarden_error: ?string}
     */
    public function sendPasswordViaBitwarden(Authenticatable $user, array $share, array|string $emails): array;
}
