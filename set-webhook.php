<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use TelBot\Telegram;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$config = config();
if ($config['app_url'] === '' || $config['telegram_token'] === '' || $config['webhook_secret'] === '') {
    exit("APP_URL, TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET are required.\n");
}

$result = (new Telegram($config['telegram_token']))->call('setWebhook', [
    'url' => $config['app_url'] . '/index.php',
    'secret_token' => $config['webhook_secret'],
    'allowed_updates' => json_encode(['message', 'callback_query', 'inline_query']),
]);
echo "Webhook configured.\n";
