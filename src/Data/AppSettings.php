<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Data;

use Hwkdo\IntranetAppBase\Data\Attributes\Description;
use Hwkdo\IntranetAppBase\Data\BaseAppSettings;

class AppSettings extends BaseAppSettings
{
    public function __construct(
        #[Description('Hinweistext unter der Freigaben-Liste (leer = kein Hinweis)')]
        public string $hinweisText = '',

        #[Description('Bitwarden Send: maximale Anzahl Zugriffe (Standard beim Teilen per Bitwarden Send)')]
        public int $defaultBwSendMaxAccessCount = 1,

        #[Description('Bitwarden Send: automatische Löschung nach so vielen Tagen')]
        public int $defaultBwSendDeleteInDays = 7,

        #[Description('Aktualisierungsintervall für Freigaben mit Gast-Upload in Sekunden (3–60). 0 deaktiviert die automatische Aktualisierung.')]
        public int $guestUploadPollSeconds = 30,
    ) {}
}
