<?php
declare(strict_types=1);

namespace TelBot;

use Throwable;

final class Bot
{
    public function __construct(
        private readonly Telegram $telegram,
        private readonly ITunes $music,
        private readonly array $config
    ) {
    }

    public function handle(array $update): void
    {
        if (isset($update['inline_query'])) {
            $this->handleInlineQuery($update['inline_query']);
            return;
        }

        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }

        $message = $update['message'] ?? null;
        if (!$message || !isset($message['chat']['id'])) {
            return;
        }

        $chatId = $message['chat']['id'];
        $userId = (int) ($message['from']['id'] ?? $chatId);
        $text = trim((string) ($message['text'] ?? ''));
        if (preg_match('/^\/start(?:@\\w+)?\\s+download_([id])_(\\d+)$/', $text, $match)) {
            $this->deliverTrack($chatId, $userId, $match[2], null, $match[1]);
            return;
        }
        if ($text === '' || str_starts_with($text, '/start')) {
            $username = ltrim((string) ($this->config['bot_username'] ?? ''), '@');
            $inlineHelp = $username !== '' ? "\n\nدر هر چت نیز بنویس: <code>@{$username} نام آهنگ</code>" : '';
            $this->telegram->sendMessage($chatId, "🎵 نام آهنگ یا خواننده را بفرست تا جست‌وجو کنم.\nبرای دریافت فایل‌های مجاز باید عضو کانال باشی.{$inlineHelp}", $this->mainMenu());
            return;
        }

        if ($text === '/search' || $text === '🔎 جستجوی آهنگ') {
            $this->telegram->sendMessage($chatId, 'روی دکمه زیر بزن و بعد از نام ربات، نام آهنگ یا خواننده را تایپ کن:', [
                'inline_keyboard' => [[[
                    'text' => '🔎 شروع جستجو',
                    'switch_inline_query_current_chat' => '',
                ]]],
            ]);
            return;
        }
        if ($text === '/channel' || $text === '📢 ورود به کانال') {
            $this->telegram->sendMessage($chatId, 'برای ورود به کانال روی دکمه زیر بزن:', [
                'inline_keyboard' => [[['text' => '📢 ورود به کانال', 'url' => $this->config['channel_url']]]],
            ]);
            return;
        }
        if ($text === '/help' || $text === 'ℹ️ راهنما') {
            $this->telegram->sendMessage($chatId, "نام آهنگ را مستقیم بفرست یا از دکمه جستجو استفاده کن.\nبرای جستجو در هر چت هم بنویس: <code>@" . ltrim((string) ($this->config['bot_username'] ?? ''), '@') . " نام آهنگ</code>", $this->mainMenu());
            return;
        }

        if (str_starts_with($text, '/')) {
            $this->telegram->sendMessage($chatId, 'نام آهنگ را بدون دستور بفرست.');
            return;
        }

        try {
            $tracks = $this->music->searchTracks($text);
            if ($tracks === []) {
                $this->telegram->sendMessage($chatId, 'نتیجه‌ای پیدا نشد. نام آهنگ یا خواننده را دقیق‌تر بنویس.');
                return;
            }

            $buttons = [];
            foreach ($tracks as $track) {
                $artist = $track['artists'][0]['name'] ?? 'Unknown';
                $buttons[] = [[
                    'text' => mb_strimwidth("{$track['name']} — {$artist}", 0, 58, '…'),
                    'callback_data' => 'track:' . $track['id'],
                ]];
            }
            $this->telegram->sendMessage($chatId, 'یکی از نتایج را انتخاب کن:', ['inline_keyboard' => $buttons]);
        } catch (Throwable $e) {
            $this->log($e);
            $this->telegram->sendMessage($chatId, 'فعلاً جست‌وجو انجام نشد. چند لحظه دیگر دوباره امتحان کن.');
        }
    }

    private function mainMenu(): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => '🔎 جستجوی آهنگ',
                    'switch_inline_query_current_chat' => '',
                ]],
                [['text' => '📢 ورود به کانال', 'url' => $this->config['channel_url']]],
                [['text' => 'ℹ️ راهنما', 'callback_data' => 'help']],
            ],
        ];
    }

    private function handleInlineQuery(array $inlineQuery): void
    {
        $id = (string) ($inlineQuery['id'] ?? '');
        $query = trim((string) ($inlineQuery['query'] ?? ''));
        if ($id === '') {
            return;
        }
        if ($query === '') {
            $this->telegram->answerInlineQuery($id, []);
            return;
        }

        try {
            $tracks = $this->searchDeezer($query, 10);
            $username = ltrim((string) ($this->config['bot_username'] ?? ''), '@');
            $results = [];
            foreach ($tracks as $track) {
                if (empty($track['preview_url']) || $username === '') {
                    continue;
                }
                $artist = (string) ($track['artists'][0]['name'] ?? 'Unknown');
                $trackId = preg_replace('/\\D+/', '', (string) $track['id']);
                if ($trackId === '') {
                    continue;
                }
                $results[] = [
                    'type' => 'audio',
                    'id' => 'deezer_' . $trackId,
                    'audio_url' => $track['preview_url'],
                    'title' => (string) $track['name'],
                    'performer' => $artist,
                    'caption' => "🎧 پیش‌نمایش رسمی\n" . $track['name'] . ' — ' . $artist,
                    'reply_markup' => [
                        'inline_keyboard' => [[[
                            'text' => '⬇️ دریافت موزیک',
                            'url' => "https://t.me/{$username}?start=download_d_{$trackId}",
                        ]]],
                    ],
                ];
            }
            $this->telegram->answerInlineQuery($id, $results);
        } catch (Throwable $e) {
            $this->log($e);
            $this->telegram->answerInlineQuery($id, []);
        }
    }

    private function searchDeezer(string $query, int $limit): array
    {
        $data = $this->httpJson('https://api.deezer.com/search?' . http_build_query([
            'q' => $query,
            'limit' => $limit,
            'strict' => 'on',
        ]));
        $tracks = [];
        foreach ($data['data'] ?? [] as $track) {
            if (empty($track['id']) || empty($track['preview'])) {
                continue;
            }
            $tracks[] = [
                'id' => (string) $track['id'],
                'name' => (string) ($track['title'] ?? 'Unknown'),
                'artists' => [['name' => (string) ($track['artist']['name'] ?? 'Unknown')]],
                'preview_url' => (string) $track['preview'],
                'external_urls' => ['music' => (string) ($track['link'] ?? 'https://www.deezer.com/')],
            ];
        }
        return $tracks;
    }

    private function deezerTrack(string $trackId): array
    {
        $track = $this->httpJson('https://api.deezer.com/track/' . rawurlencode($trackId));
        if (empty($track['id']) || isset($track['error'])) {
            throw new \RuntimeException('Deezer track not found.');
        }
        return [
            'id' => (string) $track['id'],
            'name' => (string) ($track['title'] ?? 'Unknown'),
            'artists' => [['name' => (string) ($track['artist']['name'] ?? 'Unknown')]],
            'preview_url' => $track['preview'] ?? null,
            'external_urls' => ['music' => (string) ($track['link'] ?? 'https://www.deezer.com/')],
        ];
    }

    private function httpJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'SpotTDownBot/1.0',
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $status >= 400) {
            throw new \RuntimeException("Music service error ({$status}): {$error}");
        }
        $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    }

    private function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $chatId = $callback['message']['chat']['id'] ?? null;
        $userId = $callback['from']['id'] ?? null;
        if ($chatId && $data === 'help') {
            $this->telegram->answerCallback($callbackId);
            $this->telegram->sendMessage($chatId, 'نام آهنگ را مستقیم بفرست یا روی دکمه جستجو بزن. برای دریافت فایل باید عضو کانال باشی.');
            return;
        }
        if (!$chatId || !$userId || !str_starts_with($data, 'track:')) {
            return;
        }

        $parts = explode(':', $data);
        $provider = count($parts) >= 3 && in_array($parts[1], ['i', 'd'], true) ? $parts[1] : 'i';
        $trackId = count($parts) >= 3 ? $parts[2] : ($parts[1] ?? '');
        if (!ctype_digit($trackId)) {
            $this->telegram->answerCallback($callbackId, 'شناسه آهنگ نامعتبر است');
            return;
        }
        $this->deliverTrack($chatId, (int) $userId, $trackId, $callbackId, $provider);
    }

    private function deliverTrack(int|string $chatId, int $userId, string $trackId, ?string $callbackId = null, string $provider = 'i'): void
    {
        if (!$this->telegram->isChannelMember((int) $userId, $this->config['channel'])) {
            if ($callbackId !== null) {
                $this->telegram->answerCallback($callbackId, 'ابتدا عضو کانال شو');
            }
            $this->telegram->sendMessage($chatId, 'برای دریافت فایل ابتدا عضو کانال شو و بعد دوباره روی آهنگ بزن.', [
                'inline_keyboard' => [
                    [['text' => 'عضویت در کانال', 'url' => $this->config['channel_url']]],
                    [['text' => '✅ عضو شدم؛ دریافت', 'callback_data' => 'track:' . $provider . ':' . $trackId]],
                ],
            ]);
            return;
        }

        if ($callbackId !== null) {
            $this->telegram->answerCallback($callbackId, 'در حال بررسی…');
        }
        try {
            $track = $provider === 'd' ? $this->deezerTrack($trackId) : $this->music->track($trackId);
            $artist = $track['artists'][0]['name'] ?? 'Unknown';
            $caption = htmlspecialchars("{$track['name']} — {$artist}", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $licensedFile = dirname(__DIR__) . '/storage/tracks/' . $provider . '_' . preg_replace('/[^A-Za-z0-9]/', '', $trackId) . '.mp3';

            if (is_file($licensedFile)) {
                $this->telegram->sendAudio($chatId, $licensedFile, "🎧 {$caption}");
                return;
            }
            if (!empty($track['preview_url'])) {
                $url = $track['external_urls']['music'] ?? 'https://music.apple.com/';
                $this->telegram->sendAudio($chatId, $track['preview_url'], "🎧 پیش‌نمایش رسمی: {$caption}\n\nنسخه کامل مجاز روی سرور موجود نیست.", [
                    'inline_keyboard' => [[['text' => 'گوش‌دادن از منبع رسمی', 'url' => $url]]],
                ]);
                return;
            }

            $url = $track['external_urls']['music'] ?? 'https://music.apple.com/';
            $this->telegram->sendMessage($chatId, "نسخه مجاز این آهنگ روی سرور موجود نیست. از لینک رسمی گوش کن:\n{$url}");
        } catch (Throwable $e) {
            $this->log($e);
            $this->telegram->sendMessage($chatId, 'دریافت اطلاعات آهنگ انجام نشد. دوباره امتحان کن.');
        }
    }

    private function log(Throwable $e): void
    {
        $dir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($dir . '/bot.log', '[' . date('c') . '] ' . $e . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
