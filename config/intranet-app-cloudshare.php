<?php

declare(strict_types=1);

use App\Models\User;

return [
    'user_model' => env('CLOUDSHARE_USER_MODEL', User::class),

    'root_folder' => env('CLOUDSHARE_ROOT_FOLDER', 'Cloudshare'),

    /** Maximale Upload-Größe in Kilobyte (Graph Simple Upload ~250 MB). */
    'max_upload_kb' => (int) env('CLOUDSHARE_MAX_UPLOAD_KB', 256000),

    /** Kurzzeit-Cache für Graph-Listen (Sekunden). 0 deaktiviert den Cache. */
    'graph_cache_seconds' => (int) env('CLOUDSHARE_GRAPH_CACHE_SECONDS', 60),

    'roles' => [
        'admin' => [
            'name' => 'App-Cloudshare-Admin',
            'permissions' => [
                'see-app-cloudshare',
                'manage-app-cloudshare',
            ],            
        ],
        'user' => [
            'name' => 'App-Cloudshare-Benutzer',
            'permissions' => [
                'see-app-cloudshare',
            ],
            'all_users' => true
        ],
    ],
];
