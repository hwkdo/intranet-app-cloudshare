<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Support;

use Illuminate\Support\Facades\Cache;

class CloudshareGraphCache
{
    public function ttl(): int
    {
        return max(0, (int) config('intranet-app-cloudshare.graph_cache_seconds', 60));
    }

    public function enabled(): bool
    {
        return $this->ttl() > 0;
    }

    public function sharesKey(int|string $userId): string
    {
        return 'cloudshare:shares:'.$userId;
    }

    public function filesKey(int|string $userId, string $shareId): string
    {
        return 'cloudshare:files:'.$userId.':'.$shareId;
    }

    public function quotaKey(int|string $userId): string
    {
        return 'cloudshare:quota:'.$userId;
    }

    public function remember(string $key, callable $callback, bool $forceRefresh = false): mixed
    {
        if (! $this->enabled() || $forceRefresh) {
            $value = $callback();

            if ($this->enabled()) {
                Cache::put($key, $value, $this->ttl());
            }

            return $value;
        }

        return Cache::remember($key, $this->ttl(), $callback);
    }

    public function forgetShares(int|string $userId): void
    {
        Cache::forget($this->sharesKey($userId));
    }

    public function forgetFiles(int|string $userId, string $shareId): void
    {
        Cache::forget($this->filesKey($userId, $shareId));
    }

    public function forgetQuota(int|string $userId): void
    {
        Cache::forget($this->quotaKey($userId));
    }

    /**
     * @param  list<string>  $shareIds
     */
    public function forgetUser(int|string $userId, array $shareIds = []): void
    {
        $this->forgetShares($userId);
        $this->forgetQuota($userId);

        foreach ($shareIds as $shareId) {
            if ($shareId !== '') {
                $this->forgetFiles($userId, $shareId);
            }
        }
    }
}
