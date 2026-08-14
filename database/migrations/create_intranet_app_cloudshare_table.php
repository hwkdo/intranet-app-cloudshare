<?php

use Hwkdo\IntranetAppCloudshare\Data\AppSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('intranet_app_cloudshare_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('version');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        DB::table('intranet_app_cloudshare_settings')->insert([
            'version' => 1,
            'settings' => json_encode(new AppSettings()),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intranet_app_cloudshare_settings');
    }
};

