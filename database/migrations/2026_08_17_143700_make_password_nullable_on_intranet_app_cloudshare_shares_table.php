<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('intranet_app_cloudshare_shares')) {
            return;
        }

        Schema::table('intranet_app_cloudshare_shares', function (Blueprint $table): void {
            $table->text('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('intranet_app_cloudshare_shares')) {
            return;
        }

        Schema::table('intranet_app_cloudshare_shares', function (Blueprint $table): void {
            $table->text('password')->nullable(false)->change();
        });
    }
};
