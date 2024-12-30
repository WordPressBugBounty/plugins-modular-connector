<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => 'root',

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been setup for each driver as an example of the required options.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [
        'root' => [
            'driver' => 'local',
            'root' => untrailingslashit(ABSPATH),
            'visibility' => 'public',
        ],
        'backup' => [
            'driver' => 'local',
            'root' => untrailingslashit(WP_CONTENT_DIR) . DIRECTORY_SEPARATOR . 'modular_backups',
            'visibility' => 'public',
        ],
        'upload' => [
            'driver' => 'local',
            'root' => _wp_upload_dir()['basedir'],
            'visibility' => 'public',
        ],
        'plugin' => [
            'driver' => 'local',
            'root' => untrailingslashit(WP_PLUGIN_DIR),
            'visibility' => 'public',
        ],
        'mu-plugin' => [
            'driver' => 'local',
            'root' => untrailingslashit(WPMU_PLUGIN_DIR),
            'visibility' => 'public',
        ],
        'theme' => [
            'driver' => 'local',
            'root' => untrailingslashit(get_theme_root()),
            'visibility' => 'public',
        ],
        'content' => [
            'driver' => 'local',
            'root' => untrailingslashit(WP_CONTENT_DIR),
            'visibility' => 'public',
        ],
    ],
];
