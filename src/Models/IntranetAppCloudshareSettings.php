<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Models;

use Hwkdo\IntranetAppCloudshare\Data\AppSettings;
use Illuminate\Database\Eloquent\Model;

class IntranetAppCloudshareSettings extends Model
{
    protected $table = 'intranet_app_cloudshare_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings' => AppSettings::class.':default',
        ];
    }

    public static function current(): ?IntranetAppCloudshareSettings
    {
        return self::query()->orderByDesc('version')->first();
    }

    public static function resolved(): AppSettings
    {
        $settings = self::current()?->settings;

        return $settings instanceof AppSettings
            ? $settings
            : new AppSettings;
    }
}
