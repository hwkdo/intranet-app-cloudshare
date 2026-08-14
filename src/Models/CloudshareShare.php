<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CloudshareShare extends Model
{
    protected $table = 'intranet_app_cloudshare_shares';

    protected $guarded = [];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        $userModel = config('intranet-app-cloudshare.user_model');

        return $this->belongsTo($userModel, 'user_id');
    }
}
