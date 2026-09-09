<?php
declare(strict_types=1);

namespace TelBot;

use Throwable;

final class Bot
{
    public function __construct(
        private readonly Telegram $telegram,
        private readonly Spotify $spotify,
        private readonly array $config
    ) {
    }

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }

        $message = $update['message'] ?? null;
        if (!$message || !isset($message['chat']['id'])) {
            return;
        }

        $chatId = $message['chat']['id'];
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '' || str_starts_with($text, '/start')) {
            $this->telegram->sendMessage($chatId, "🎵 نام آهنگ یا خواننده را بفرست تا در Spotify جست‌وجو کنم.\n\nبرای دریافت فایل‌های مجاز باید عضو کانال باشی.");
            return;
        }

        if (str_starts_with($text, '/')) {
            $this->telegram->sendMessage($chatId, 'نام آهنگ را بدون دستور بفرست.');
            return;
        }

        try {
            $tracks = $this->spotify->searchTracks($text);
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
        if (!$this->telegram->isChannelMember((int) $userId, $this->config['channel'])) {
            $this->telegram->answerCallback($callbackId, 'ابتدا عضو کانال شو');
            $this->telegram->sendMessage($chatId, 'برای دریافت فایل ابتدا عضو کانال شو و بعد دوباره روی آهنگ بزن.', [
                'inline_keyboard' => [[['text' => 'عضویت در کانال', 'url' => $this->config['channel_url']]]],
            ]);
            return;
        }

        $this->telegram->answerCallback($callbackId, 'در حال بررسی…');
        try {
            $track = $this->spotify->track($trackId);
            $artist = $track['artists'][0]['name'] ?? 'Unknown';
            $caption = htmlspecialchars("{$track['name']} — {$artist}", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $licensedFile = dirname(__DIR__) . '/storage/tracks/' . preg_replace('/[^A-Za-z0-9]/', '', $trackId) . '.mp3';

            if (is_file($licensedFile)) {
                $this->telegram->sendAudio($chatId, $licensedFile, "🎧 {$caption}");
                return;
            }
            if (!empty($track['preview_url'])) {
                $this->telegram->sendAudio($chatId, $track['preview_url'], "🎧 پیش‌نمایش رسمی: {$caption}");
                return;
            }

            $url = $track['external_urls']['spotify'] ?? 'https://open.spotify.com/';
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

