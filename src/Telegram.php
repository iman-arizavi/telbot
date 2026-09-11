<?php
declare(strict_types=1);

namespace TelBot;

use RuntimeException;

final class Telegram
{
    public function __construct(private readonly string $token)
    {
    }

    public function call(string $method, array $params = []): mixed
    {
        $ch = curl_init("https://api.telegram.org/bot{$this->token}/{$method}");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => $params,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new RuntimeException("Telegram API error ({$status}): {$error} {$body}");
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!($data['ok'] ?? false)) {
            throw new RuntimeException((string) ($data['description'] ?? 'Unknown Telegram error'));
        }
        return $data['result'] ?? [];
    }

    public function sendMessage(int|string $chatId, string $text, ?array $keyboard = null): array
    {
        $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($keyboard !== null) {
            $params['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $this->call('sendMessage', $params);
    }

    public function answerCallback(string $callbackId, string $text = ''): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text]);
    }

    public function answerInlineQuery(string $inlineQueryId, array $results): void
    {
        $this->call('answerInlineQuery', [
            'inline_query_id' => $inlineQueryId,
            'results' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'cache_time' => 30,
            'is_personal' => true,
        ]);
    }

    public function deleteMessage(int|string $chatId, int $messageId): void
    {
        $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    public function isChannelMember(int $userId, string $channel): bool
    {
        try {
            $member = $this->call('getChatMember', ['chat_id' => $channel, 'user_id' => $userId]);
            return in_array($member['status'] ?? '', ['creator', 'administrator', 'member', 'restricted'], true)
                && (($member['is_member'] ?? true) !== false);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function sendAudio(int|string $chatId, string $audio, string $caption, ?array $keyboard = null): array
    {
        $params = ['chat_id' => $chatId, 'caption' => $caption, 'parse_mode' => 'HTML'];
        $params['audio'] = is_file($audio) ? new \CURLFile($audio, 'audio/mpeg') : $audio;
        if ($keyboard !== null) {
            $params['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $this->call('sendAudio', $params);
    }
}
