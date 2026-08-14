<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Data;

use Hwkdo\IntranetAppBase\Data\Attributes\Description;
use Hwkdo\IntranetAppBase\Data\BaseUserSettings;
use Hwkdo\IntranetAppCloudshare\Enums\ViewModeEnum;

class UserSettings extends BaseUserSettings
{
    public function __construct(
        #[Description('Standard-Anzeigemodus für die App')]
        public ViewModeEnum $defaultViewMode = ViewModeEnum::Grid,

        #[Description('Benachrichtigungen aktiviert')]
        public bool $notificationsEnabled = true,
    ) {}
}
