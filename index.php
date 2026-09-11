<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use TelBot\Bot;
use TelBot\ITunes;
use TelBot\Telegram;

$config = config();
if ($config['telegram_token'] === '') {
    http_response_code(500);
    exit('Missing configuration.');
}

$expectedSecret = $config['webhook_secret'];
$receivedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($expectedSecret === '' || !hash_equals($expectedSecret, $receivedSecret)) {
    http_response_code(403);
    exit('Forbidden');
}

$raw = file_get_contents('php://input');
$update = json_decode($raw ?: '{}', true);
if (!is_array($update)) {
    http_response_code(400);
    exit('Invalid JSON');
}

try {
    (new Bot(
        new Telegram($config['telegram_token']),
        new ITunes(),
        $config
    ))->handle($update);
    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    echo 'Error';
}
