<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare;

use Hwkdo\IntranetAppBase\Data\TourDefinition;
use Hwkdo\IntranetAppBase\Interfaces\IntranetAppInterface;
use Hwkdo\IntranetAppBase\Interfaces\ProvidesDashboardWidgetsInterface;
use Hwkdo\IntranetAppBase\Interfaces\ProvidesToursInterface;
use Hwkdo\IntranetAppCloudshare\Dashboard\CloudshareDashboardWidgetProvider;
use Illuminate\Support\Collection;

class IntranetAppCloudshare implements IntranetAppInterface, ProvidesDashboardWidgetsInterface, ProvidesToursInterface
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

    public static function dashboardWidgetProviders(): array
    {
        return [
            CloudshareDashboardWidgetProvider::class,
        ];
    }

    public static function tours(): array
    {
        return [
            new TourDefinition(
                key: 'cloudshare.index',
                title: 'Cloud Share – Einstieg',
                description: 'Freigabe anlegen, Dateien hochladen, per E-Mail teilen und wieder löschen – inkl. Beispieldaten für die Tour.',
                group: 'app',
                appIdentifier: self::identifier(),
                appName: self::app_name(),
                routeName: 'apps.cloudshare.index',
                stepsModule: 'cloudshare/index',
                sort: 100,
                version: 1,
            ),
        ];
    }
}
