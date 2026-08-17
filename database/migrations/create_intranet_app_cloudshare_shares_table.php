<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intranet_app_cloudshare_shares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('onedrive_item_id');
            $table->string('folder_name');
            $table->text('password')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'onedrive_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intranet_app_cloudshare_shares');
    }
};
