<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Dashboard;

use Hwkdo\IntranetAppBase\Data\DashboardWidgetDefinition;
use Hwkdo\IntranetAppBase\Interfaces\DashboardWidgetProviderInterface;

class CloudshareDashboardWidgetProvider implements DashboardWidgetProviderInterface
{
    public static function widgets(): array
    {
        return [
            new DashboardWidgetDefinition(
                key: 'ablaufende-freigaben',
                title: 'Ablaufende Freigaben',
                description: 'Ihre Cloud-Share-Freigaben, die abgelaufen sind oder bald ablaufen',
                component: 'intranet-app-cloudshare::apps.cloudshare.widgets.ablaufende-freigaben',
                defaultW: 6,
                defaultH: 5,
                minW: 4,
                minH: 4,
                defaultEnabled: true,
            ),
        ];
    }
}
