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
        if (preg_match('/^\/start(?:@\\w+)?\\s+download_i_(\\d+)$/', $text, $match)) {
            $this->deliverTrack($chatId, $userId, $match[1]);
            return;
        }
        if ($text === '' || str_starts_with($text, '/start')) {
            $username = ltrim((string) ($this->config['bot_username'] ?? ''), '@');
            $inlineHelp = $username !== '' ? "\n\nدر هر چت نیز بنویس: <code>@{$username} نام آهنگ</code>" : '';
            $this->telegram->sendMessage($chatId, "🎵 نام آهنگ یا خواننده را بفرست تا جست‌وجو کنم.\nبرای دریافت فایل‌های مجاز باید عضو کانال باشی.{$inlineHelp}");
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
            $tracks = $this->music->searchTracks($query, 5);
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
                $fileId = $this->cacheInlineAudio((int) ($inlineQuery['from']['id'] ?? 0), $track);
                if ($fileId === null) {
                    continue;
                }
                $results[] = [
                    'type' => 'audio',
                    'id' => 'itunes_' . $trackId,
                    'audio_file_id' => $fileId,
                    'caption' => "🎧 پیش‌نمایش رسمی\n" . $track['name'] . ' — ' . $artist,
                    'reply_markup' => [
                        'inline_keyboard' => [[[
                            'text' => '⬇️ دریافت موزیک',
                            'url' => "https://t.me/{$username}?start=download_i_{$trackId}",
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

    private function cacheInlineAudio(int $userId, array $track): ?string
    {
        if ($userId <= 0 || empty($track['preview_url']) || empty($track['id'])) {
            return null;
        }

        $cacheFile = dirname(__DIR__) . '/storage/inline-cache.json';
        $cache = [];
        if (is_file($cacheFile)) {
            $decoded = json_decode((string) file_get_contents($cacheFile), true);
            $cache = is_array($decoded) ? $decoded : [];
        }
        $key = 'itunes_' . preg_replace('/\\D+/', '', (string) $track['id']);
        if (!empty($cache[$key])) {
            return (string) $cache[$key];
        }

        try {
            $artist = (string) ($track['artists'][0]['name'] ?? 'Unknown');
            $message = $this->telegram->sendAudio(
                $userId,
                (string) $track['preview_url'],
                'در حال آماده‌سازی پیش‌نمایش: ' . htmlspecialchars((string) $track['name'] . ' — ' . $artist, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
            $fileId = $message['audio']['file_id'] ?? null;
            if (!empty($message['message_id'])) {
                $this->telegram->deleteMessage($userId, (int) $message['message_id']);
            }
            if (!$fileId) {
                return null;
            }
            $cache[$key] = $fileId;
            file_put_contents(
                $cacheFile,
                json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
            return (string) $fileId;
        } catch (Throwable $e) {
            $this->log($e);
            return null;
        }
    }

    private function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $chatId = $callback['message']['chat']['id'] ?? null;
        $userId = $callback['from']['id'] ?? null;
        if (!$chatId || !$userId || !str_starts_with($data, 'track:')) {
            return;
        }

        $trackId = substr($data, 6);
        $this->deliverTrack($chatId, (int) $userId, $trackId, $callbackId);
    }

    private function deliverTrack(int|string $chatId, int $userId, string $trackId, ?string $callbackId = null): void
    {
        if (!$this->telegram->isChannelMember((int) $userId, $this->config['channel'])) {
            if ($callbackId !== null) {
                $this->telegram->answerCallback($callbackId, 'ابتدا عضو کانال شو');
            }
            $this->telegram->sendMessage($chatId, 'برای دریافت فایل ابتدا عضو کانال شو و بعد دوباره روی آهنگ بزن.', [
                'inline_keyboard' => [
                    [['text' => 'عضویت در کانال', 'url' => $this->config['channel_url']]],
                    [['text' => '✅ عضو شدم؛ دریافت', 'callback_data' => 'track:' . $trackId]],
                ],
            ]);
            return;
        }

        if ($callbackId !== null) {
            $this->telegram->answerCallback($callbackId, 'در حال بررسی…');
        }
        try {
            $track = $this->music->track($trackId);
            $artist = $track['artists'][0]['name'] ?? 'Unknown';
            $caption = htmlspecialchars("{$track['name']} — {$artist}", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $licensedFile = dirname(__DIR__) . '/storage/tracks/' . preg_replace('/[^A-Za-z0-9]/', '', $trackId) . '.mp3';

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
