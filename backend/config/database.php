<?php
declare(strict_types=1);

$envPath = dirname(__DIR__).'/.env';
$settings = [];
// Environment variables override the local .env file, which supports managed hosts.
if (is_file($envPath)) {
    $settings = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    if ($settings === false) {
        throw new RuntimeException('Could not read backend/.env.');
    }
}

$setting = static function (string $key, string $default = '') use ($settings): string {
    $value = getenv($key);
    return $value !== false ? $value : (string)($settings[$key] ?? $default);
};

// Production must use a dedicated database account instead of the local XAMPP root user.
$environment = strtolower($setting('APP_ENV', 'development'));
if (!in_array($environment, ['development', 'production'], true)) {
    throw new RuntimeException('APP_ENV must be either development or production.');
}
if (!is_file($envPath) && $environment !== 'production') {
    throw new RuntimeException('Missing backend/.env. Copy backend/.env.example and configure the database settings.');
}
$host = $setting('DB_HOST', '127.0.0.1');
$port = $setting('DB_PORT', '3306');
$name = $setting('DB_NAME', 'cihrm_db');
$user = $setting('DB_USER', 'root');
$password = $setting('DB_PASSWORD');

if ($environment === 'production' &&
    ($host === '' || $name === '' || $user === '' || $password === '' || strtolower($user) === 'root')) {
    throw new RuntimeException(
        'Production database settings must use a dedicated database user and a non-empty password.'
    );
}

return new PDO(
    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]
);
