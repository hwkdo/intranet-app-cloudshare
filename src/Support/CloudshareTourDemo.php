<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Support;

class CloudshareTourDemo
{
    public const SESSION_KEY = 'intranet_cloudshare_tour_demo';

    public const DEMO_SHARE_ID = 'cloudshare-tour-demo-share';

    public const DEMO_SHARE_NAME = 'Tour-Demo Freigabe';

    public static function isActive(): bool
    {
        return (bool) session(self::SESSION_KEY, false);
    }

    public static function enable(): void
    {
        session([self::SESSION_KEY => true]);
    }

    public static function disable(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    public static function isDemoShareId(string $shareId): bool
    {
        return $shareId === self::DEMO_SHARE_ID;
    }

    /**
     * @return array{
     *     name: string,
     *     id: string,
     *     url: string,
     *     created_at: string,
     *     password: bool,
     *     has_stored_password: bool,
     *     expiration: string,
     *     writeable: bool,
     *     file_count: int
     * }
     */
    public static function demoShare(): array
    {
        return [
            'name' => self::DEMO_SHARE_NAME,
            'id' => self::DEMO_SHARE_ID,
            'url' => 'https://1drv.ms/cloudshare-tour-demo',
            'created_at' => now()->format('d.m.Y H:i'),
            'password' => true,
            'has_stored_password' => true,
            'expiration' => now()->addDays(14)->format('d.m.Y').' 00:00 Uhr',
            'writeable' => true,
            'file_count' => 1,
        ];
    }

    /**
     * @return list<array{file: string, href: string, modified: string, size: int, id: string}>
     */
    public static function demoFiles(): array
    {
        return [
            [
                'file' => 'beispiel-dokument.pdf',
                'href' => 'https://1drv.ms/cloudshare-tour-demo/beispiel-dokument.pdf',
                'modified' => now()->format('d.m.Y H:i'),
                'size' => 1024 * 240,
                'id' => 'cloudshare-tour-demo-file-1',
            ],
        ];
    }

    /**
     * @return array{quota_free: int, quota_used: int, quota_total: int, quota_relative: float}
     */
    public static function demoQuota(): array
    {
        return [
            'quota_free' => 5 * 1024 * 1024 * 1024,
            'quota_used' => 2 * 1024 * 1024 * 1024,
            'quota_total' => 7 * 1024 * 1024 * 1024,
            'quota_relative' => 28.5,
        ];
    }

    public static function demoMailPreview(): string
    {
        $name = e(self::DEMO_SHARE_NAME);
        $url = e('https://1drv.ms/cloudshare-tour-demo');

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><title>Freigabe</title></head>
<body style="font-family: sans-serif; line-height: 1.5; color: #18181b;">
    <p>Guten Tag,</p>
    <p>der Cloud-Ordner <strong>{$name}</strong> wurde für Sie freigegeben.</p>
    <p><a href="{$url}">Zum Cloudshare öffnen</a></p>
    <p>Mit freundlichen Grüßen<br>Ihre Handwerkskammer Dortmund</p>
</body>
</html>
HTML;
    }

    public static function demoMailSubject(): string
    {
        return 'Der Cloud-Ordner '.self::DEMO_SHARE_NAME.' wurde für Sie freigegeben';
    }
}
