<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Commands;

use Hwkdo\IntranetAppBase\Services\BackgroundActivityCatalog;
use Hwkdo\IntranetAppBase\Services\BackgroundActivityConfigStore;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Illuminate\Console\Command;

class PurgeExpiredSharesCommand extends Command
{
    protected $signature = 'intranet-app-cloudshare:purge-expired-shares
                            {--force : Auch ausführen, wenn automatisches Löschen deaktiviert ist}';

    protected $description = 'Löscht abgelaufene Cloud-Share-Freigaben nach der konfigurierten Karenzzeit.';

    public function handle(CloudshareServiceInterface $cloudshare): int
    {
        $definition = app(BackgroundActivityCatalog::class)->find('cloudshare.purge-expired-shares');
        $store = app(BackgroundActivityConfigStore::class);

        if ($definition !== null && ! $store->isEnabled($definition) && ! $this->option('force')) {
            $this->info('Automatisches Löschen ist deaktiviert.');

            return self::SUCCESS;
        }

        $afterDays = (int) $store->parameter('cloudshare.purge-expired-shares', 'after_days', 30);
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
