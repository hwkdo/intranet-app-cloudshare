<?php

declare(strict_types=1);

use Hwkdo\IntranetAppBase\Enums\BackgroundActivityCadence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('intranet_background_activity_configs') || ! Schema::hasTable('intranet_app_cloudshare_settings')) {
            return;
        }

        $row = DB::table('intranet_app_cloudshare_settings')->orderByDesc('id')->first();

        if ($row === null) {
            return;
        }

        $settings = json_decode((string) $row->settings, true);

        if (! is_array($settings)) {
            return;
        }

        if (! array_key_exists('autoDeleteExpiredEnabled', $settings) && ! array_key_exists('autoDeleteCheckEveryHours', $settings) && ! array_key_exists('autoDeleteExpiredAfterDays', $settings)) {
            return;
        }

        DB::table('intranet_background_activity_configs')->updateOrInsert(
            ['activity_key' => 'cloudshare.purge-expired-shares'],
            [
                'enabled' => (bool) ($settings['autoDeleteExpiredEnabled'] ?? false),
                'cadence' => BackgroundActivityCadence::EveryNHours->value,
                'every' => max(1, (int) ($settings['autoDeleteCheckEveryHours'] ?? 24)),
                'offset' => 0,
                'parameters' => json_encode([
                    'after_days' => (int) ($settings['autoDeleteExpiredAfterDays'] ?? 30),
                ]),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('intranet_background_activity_configs')) {
            return;
        }

        DB::table('intranet_background_activity_configs')->where('activity_key', 'cloudshare.purge-expired-shares')->delete();
    }
};
