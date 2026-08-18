<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Commands;

use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Models\IntranetAppCloudshareSettings;
use Illuminate\Console\Command;

class PurgeExpiredSharesCommand extends Command
{
    protected $signature = 'intranet-app-cloudshare:purge-expired-shares
                            {--force : Auch ausführen, wenn automatisches Löschen deaktiviert ist}';

    protected $description = 'Löscht abgelaufene Cloud-Share-Freigaben nach der konfigurierten Karenzzeit.';

    public function handle(CloudshareServiceInterface $cloudshare): int
    {
        $settings = IntranetAppCloudshareSettings::resolved();

        if (! $settings->autoDeleteExpiredEnabled && ! $this->option('force')) {
            $this->info('Automatisches Löschen ist deaktiviert.');

            return self::SUCCESS;
        }

        $afterDays = $settings->normalizedAutoDeleteAfterDays();
        $result = $cloudshare->purgeExpiredShares($afterDays);

        $this->info(sprintf(
            'Abgelaufene Freigaben geprüft (Karenz: %d Tage). Gelöscht: %d, Benutzer ohne Zugriff: %d, Fehler: %d.',
            $afterDays,
            $result['deleted'],
            $result['skipped_users'],
            $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
