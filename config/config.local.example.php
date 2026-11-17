<?php
/**
 * Copy to config.local.php for local testing only.
 * Never commit config.local.php or a real database password.
 */
return [
    'db_host' => getenv('SWIFTDRIVE_DB_HOST') ?: '127.0.0.1',
    'db_port' => (int)(getenv('SWIFTDRIVE_DB_PORT') ?: 3306),
    'db_name' => getenv('SWIFTDRIVE_DB_NAME') ?: 'swiftdrive_rentals',
    'db_user' => getenv('SWIFTDRIVE_DB_USER') ?: 'root',
    'db_pass' => getenv('SWIFTDRIVE_DB_PASS') ?: '',
    'db_charset' => 'utf8mb4',
];
