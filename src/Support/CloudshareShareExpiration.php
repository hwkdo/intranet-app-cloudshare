<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Support;

use Hwkdo\IntranetAppCloudshare\Data\UserSettings;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Throwable;

final class CloudshareShareExpiration
{
    public const DEFAULT_EXPIRING_SOON_DAYS = 7;

    public const MIN_EXPIRING_SOON_DAYS = 1;

    public const MAX_EXPIRING_SOON_DAYS = 90;

    /**
     * @param  array{expiration?: ?string}  $share
     */
    public static function isExpired(array $share, ?Carbon $now = null): bool
    {
        $days = self::daysUntilExpiry($share['expiration'] ?? null, $now);

        return $days !== null && $days < 0;
    }

    /**
     * @param  array{expiration?: ?string}  $share
     */
    public static function isExpiringSoon(array $share, int $withinDays, ?Carbon $now = null): bool
    {
        $days = self::daysUntilExpiry($share['expiration'] ?? null, $now);

        return $days !== null && $days >= 0 && $days <= self::normalizeDays($withinDays);
    }

    /**
     * @param  array{expiration?: ?string}  $share
     */
    public static function needsExpirationAttention(array $share, int $withinDays, ?Carbon $now = null): bool
    {
        return self::isExpired($share, $now) || self::isExpiringSoon($share, $withinDays, $now);
    }

    public static function daysUntilExpiry(?string $expiration, ?Carbon $now = null): ?int
    {
        $expiresAt = self::parseExpiration($expiration);

        if ($expiresAt === null) {
            return null;
        }

        $now = ($now ?? Carbon::now(self::timezone()))->copy()->timezone(self::timezone())->startOfDay();
        $expiresAt = $expiresAt->copy()->startOfDay();
        $days = (int) round($now->diffInDays($expiresAt, false));

        if ($expiresAt->lessThan($now) && $days > 0) {
            return -$days;
        }

        if ($expiresAt->greaterThan($now) && $days < 0) {
            return abs($days);
        }

        return $days;
    }

    public static function parseExpiration(?string $expiration): ?Carbon
    {
        if (! is_string($expiration)) {
            return null;
        }

        $normalized = trim(preg_replace('/\s*Uhr$/u', '', trim($expiration)) ?? '');

        if ($normalized === '') {
            return null;
        }

        $timezone = self::timezone();

        foreach (['d.m.Y H:i', 'd.m.Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $normalized, $timezone);
            } catch (Throwable) {
                $parsed = false;
            }

            if ($parsed instanceof Carbon) {
                return $parsed;
            }
        }

        try {
            return Carbon::parse($normalized, $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    public static function remainingDaysLabel(?int $days): string
    {
        if ($days === null) {
            return 'ohne Ablaufdatum';
        }

        if ($days < 0) {
            $expired = abs($days);

            return $expired === 1 ? 'seit 1 Tag abgelaufen' : 'seit '.$expired.' Tagen abgelaufen';
        }

        if ($days === 0) {
            return 'läuft heute ab';
        }

        return $days === 1 ? 'noch 1 Tag' : 'noch '.$days.' Tage';
    }

    public static function normalizeDays(int $days): int
    {
        return min(max($days, self::MIN_EXPIRING_SOON_DAYS), self::MAX_EXPIRING_SOON_DAYS);
    }

    public static function expiringSoonDaysFor(?Authenticatable $user): int
    {
        $settings = data_get($user, 'settings.app.cloudshare');

        if ($settings instanceof UserSettings) {
            return self::normalizeDays($settings->expiringSoonDays);
        }

        return self::DEFAULT_EXPIRING_SOON_DAYS;
    }

    private static function timezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }
}
