<?php
/**
 * Central Configuration Loader
 * Merges config.example.php defaults with config.local.php overrides if present.
 */

$defaultConfig = [
    'app_name'    => 'SwiftDrive Rentals',
    'app_env'     => 'local',
    'base_url'    => '',
    'timezone'    => 'Asia/Colombo',
    'db_host'     => '127.0.0.1',
    'db_port'     => 3306,
    'db_name'     => 'swiftdrive_rentals',
    'db_user'     => 'root',
    'db_pass'     => '',
    'db_charset'  => 'utf8mb4',
    'deposit_rate'              => 0.25,
    'cancellation_cutoff_hours' => 24,
    'late_Cancel_fee_rate'      => 0.10,
    'late_penalty_hourly_pct'   => 0.15,
    'excess_km_daily_allowance' => 150,
    'fuel_refill_unit_charge'   => 3500.00,
];
$localConfigFile = __DIR__ . '/config.local.php';

if (file_exists($localConfigFile)) {
    $localConfig = require $localConfigFile;
    if (is_array($localConfig)) {
        $defaultConfig = array_merge($defaultConfig, $localConfig);
    }
}

date_default_timezone_set($defaultConfig['timezone'] ?? 'Asia/Colombo');

return $defaultConfig;

