<?php

return [
    'default' => 'phantom',

    'source' => [
        /*
         * The compression algorithm to be used for creating the zip archive.
         *
         * If backing up only database, you may choose gzip compression for db dump and no compression at zip.
         *
         * Some common algorithms are listed below:
         * ZipArchive::CM_STORE (no compression at all; set 0 as compression level)
         * ZipArchive::CM_DEFAULT
         * ZipArchive::CM_DEFLATE
         * ZipArchive::CM_BZIP2
         * ZipArchive::CM_XZ
         *
         * For more check https://www.php.net/manual/zip.constants.php and confirm it's supported by your system.
         */
        'compression_method' => class_exists('ZipArchive') ? \ZipArchive::CM_DEFAULT : null,

        /*
         * The compression level corresponding to the used algorithm; an integer between 0 and 9.
         *
         * Check supported levels for the chosen algorithm, usually 1 means the fastest and weakest compression,
         * while 9 the slowest and strongest one.
         *
         * Setting of 0 for some algorithms may switch to the strongest compression.
         */
        'compression_level' => 9,

        'exclude' => [
            untrailingslashit(ABSPATH) . DIRECTORY_SEPARATOR . '.wp-cli',

            // Error logs
            untrailingslashit(ABSPATH) . DIRECTORY_SEPARATOR . 'error_log',
            untrailingslashit(WP_CONTENT_DIR) . DIRECTORY_SEPARATOR . 'error_log',
            untrailingslashit(WP_CONTENT_DIR) . DIRECTORY_SEPARATOR . 'debug.log',

            // Default caches
            untrailingslashit(WP_CONTENT_DIR) . DIRECTORY_SEPARATOR . 'cache',
            untrailingslashit(WP_CONTENT_DIR) . DIRECTORY_SEPARATOR . 'lscache',
            untrailingslashit(WP_CONTENT_DIR) . DIRECTORY_SEPARATOR . 'et-cache',

            // Default backups
            untrailingslashit(WP_CONTENT_DIR) . DIRECTORY_SEPARATOR . 'updraft',
            untrailingslashit(WP_CONTENT_DIR) . DIRECTORY_SEPARATOR . 'aiowps_backups',
            untrailingslashit(WP_CONTENT_DIR) . DIRECTORY_SEPARATOR . 'ai1wm-backups',
            untrailingslashit(WP_CONTENT_DIR) . DIRECTORY_SEPARATOR . 'backups-dup-pro',
        ],
    ],

    /*
     * The number of attempts, in case the backup command encounters an exception
     */
    'tries' => 1,
];
