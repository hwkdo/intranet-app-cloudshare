<?php

namespace Hwkdo\IntranetAppCloudshare\Commands;

use Illuminate\Console\Command;

class IntranetAppCloudshareCommand extends Command
{
    public $signature = 'intranet-app-cloudshare';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
