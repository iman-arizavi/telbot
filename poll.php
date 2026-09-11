<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use TelBot\Bot;
use TelBot\ITunes;
use TelBot\Telegram;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$config = config();
if ($config['telegram_token'] === '') {
    exit("TELEGRAM_BOT_TOKEN is required in .env.\n");
}

$telegram = new Telegram($config['telegram_token']);
$me = $telegram->call('getMe');
$config['bot_username'] = (string) ($me['username'] ?? '');
$bot = new Bot(
    $telegram,
    new ITunes(),
    $config
);

$offsetFile = __DIR__ . '/storage/update-offset.txt';
$offset = is_file($offsetFile) ? (int) trim((string) file_get_contents($offsetFile)) : 0;

// Long polling and webhooks cannot be active at the same time.
$telegram->call('deleteWebhook', ['drop_pending_updates' => false]);
echo "Bot is running with long polling. Press Ctrl+C to stop.\n";

while (true) {
    try {
        $updates = $telegram->call('getUpdates', [
            'offset' => $offset,
            'timeout' => 25,
            'allowed_updates' => json_encode(['message', 'callback_query', 'inline_query']),
        ]);

        foreach ($updates as $update) {
            $bot->handle($update);
            $offset = ((int) ($update['update_id'] ?? $offset)) + 1;
            file_put_contents($offsetFile, (string) $offset, LOCK_EX);
        }
    } catch (Throwable $e) {
        file_put_contents(
            __DIR__ . '/storage/logs/bot.log',
            '[' . date('c') . '] ' . $e . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        fwrite(STDERR, "Connection error; retrying in 3 seconds.\n");
        sleep(3);
    }
}
