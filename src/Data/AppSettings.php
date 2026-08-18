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

        #[Description('Automatisch Löschen aktivieren')]
        public bool $autoDeleteExpiredEnabled = false,

        #[Description('Automatisch Löschen nach X Tagen (0 = sobald die Freigabe abgelaufen ist)')]
        public int $autoDeleteExpiredAfterDays = 30,

        #[Description('Prüfung Automatische Löschung alle X Stunden (1–168)')]
        public int $autoDeleteCheckEveryHours = 24,
    ) {}

    public function normalizedAutoDeleteAfterDays(): int
    {
        return min(max($this->autoDeleteExpiredAfterDays, 0), 365);
    }

    public function normalizedAutoDeleteCheckEveryHours(): int
    {
        return min(max($this->autoDeleteCheckEveryHours, 1), 168);
    }
}
