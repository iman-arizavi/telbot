<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'TelBot\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

function env(string $key, ?string $default = null): ?string
{
    static $values;
    if ($values === null) {
        $values = [];
        $path = __DIR__ . '/.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$name, $value] = array_map('trim', explode('=', $line, 2));
                $values[$name] = trim($value, " \\t\\n\\r\\0\\x0B\\\"'");
            }
        }
    }

    return $_ENV[$key] ?? $_SERVER[$key] ?? $values[$key] ?? $default;
}

function config(): array
{
    return [
        'telegram_token' => (string) env('TELEGRAM_BOT_TOKEN', ''),
        'webhook_secret' => (string) env('TELEGRAM_WEBHOOK_SECRET', ''),
        'channel' => (string) env('TELEGRAM_CHANNEL', ''),
        'channel_url' => (string) env('TELEGRAM_CHANNEL_URL', ''),
        'spotify_id' => (string) env('SPOTIFY_CLIENT_ID', ''),
        'spotify_secret' => (string) env('SPOTIFY_CLIENT_SECRET', ''),
        'app_url' => rtrim((string) env('APP_URL', ''), '/'),
        'payments_enabled' => filter_var(env('PAYMENTS_ENABLED', 'false'), FILTER_VALIDATE_BOOL),
    ];
}

