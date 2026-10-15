<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Background;

use Hwkdo\IntranetAppBase\Data\BackgroundActivityDefinition;
use Hwkdo\IntranetAppBase\Data\BackgroundActivityFrequency;
use Hwkdo\IntranetAppBase\Data\BackgroundActivityParameter;
use Hwkdo\IntranetAppBase\Enums\BackgroundActivityCadence;
use Hwkdo\IntranetAppCloudshare\IntranetAppCloudshare;
use Hwkdo\IntranetAppCloudshare\Mail\CloudsharePasswordSendMail;
use Hwkdo\IntranetAppCloudshare\Mail\CloudshareSharedMail;

final class CloudshareBackgroundActivities
{
    /**
     * @return list<BackgroundActivityDefinition>
     */
    public static function definitions(): array
    {
        $id = IntranetAppCloudshare::identifier();
        $name = IntranetAppCloudshare::app_name();

        return [
            BackgroundActivityDefinition::scheduled(
                key: 'cloudshare.purge-expired-shares',
                label: 'Abgelaufene Freigaben löschen',
                appIdentifier: $id,
                appName: $name,
                command: 'intranet-app-cloudshare:purge-expired-shares',
                frequency: BackgroundActivityFrequency::everyHours(24),
                description: 'Löscht abgelaufene Cloud-Share-Freigaben nach der Karenzzeit.',
                allowedCadences: BackgroundActivityCadence::cases(),
                minEvery: 1,
                maxEvery: 168,
                defaultEnabled: false,
                withoutOverlappingMinutes: 1440,
                parameters: [
                    new BackgroundActivityParameter(
                        key: 'after_days',
                        label: 'Karenz in Tagen',
                        type: 'int',
                        default: 30,
                        min: 0,
                        max: 365,
                    ),
                ],
                sort: 10,
            ),
            BackgroundActivityDefinition::mail(
                key: 'cloudshare.mail-shared',
                label: 'Mail: Freigabe',
                appIdentifier: $id,
                appName: $name,
                mailable: CloudshareSharedMail::class,
                sort: 20,
            ),
            BackgroundActivityDefinition::mail(
                key: 'cloudshare.mail-password',
                label: 'Mail: Passwort',
                appIdentifier: $id,
                appName: $name,
                mailable: CloudsharePasswordSendMail::class,
                sort: 30,
            ),
        ];
    }
}
