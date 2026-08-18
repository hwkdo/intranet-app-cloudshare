<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare;

use Hwkdo\IntranetAppBase\Interfaces\IntranetAppInterface;
use Illuminate\Support\Collection;

class IntranetAppCloudshare implements IntranetAppInterface
{
    public static function app_name(): string
    {
        return 'Cloud Share';
    }

    public static function app_icon(): string
    {
        return 'cloud';
    }

    public static function identifier(): string
    {
        return 'cloudshare';
    }

    public static function roles_admin(): Collection
    {
        return collect(config('intranet-app-cloudshare.roles.admin'));
    }

    public static function roles_user(): Collection
    {
        return collect(config('intranet-app-cloudshare.roles.user'));
    }

    public static function userSettingsClass(): ?string
    {
        return \Hwkdo\IntranetAppCloudshare\Data\UserSettings::class;
    }

    public static function appSettingsClass(): ?string
    {
        return \Hwkdo\IntranetAppCloudshare\Data\AppSettings::class;
    }

    public static function mcpServers(): array
    {
        return [];
    }
}
