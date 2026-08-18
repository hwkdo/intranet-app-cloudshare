<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Data;

use Hwkdo\IntranetAppBase\Data\Attributes\Description;
use Hwkdo\IntranetAppBase\Data\BaseUserSettings;

class UserSettings extends BaseUserSettings
{
    public function __construct(
        #[Description('Benachrichtigungen aktiviert')]
        public bool $notificationsEnabled = true,

        #[Description('Anzahl der Tage, bevor eine Freigabe als „läuft bald ab“ gilt (1–90). Standard: 7.')]
        public int $expiringSoonDays = 7,
    ) {}
}
