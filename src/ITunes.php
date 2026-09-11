<?php
declare(strict_types=1);

namespace TelBot;

use RuntimeException;

final class ITunes
{
    private function get(string $endpoint, array $query): array
    {
        $url = 'https://itunes.apple.com/' . ltrim($endpoint, '/') . '?' . http_build_query($query);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'TelBot/1.0',
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new RuntimeException("Music search error ({$status}): {$error}");
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    }

    public function searchTracks(string $query, int $limit = 8): array
    {
        $data = $this->get('search', [
            'term' => $query,
            'media' => 'music',
            'entity' => 'song',
            'limit' => $limit,
            'explicit' => 'Yes',
        ]);

        return array_map([$this, 'normalize'], $data['results'] ?? []);
    }

    public function track(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new RuntimeException('Invalid track ID.');
        }
        $data = $this->get('lookup', ['id' => $id, 'entity' => 'song']);
        if (empty($data['results'][0])) {
            throw new RuntimeException('Track not found.');
        }
        return $this->normalize($data['results'][0]);
    }

    private function normalize(array $track): array
    {
        return [
            'id' => (string) ($track['trackId'] ?? ''),
            'name' => (string) ($track['trackName'] ?? 'Unknown'),
            'artists' => [['name' => (string) ($track['artistName'] ?? 'Unknown')]],
            'preview_url' => $track['previewUrl'] ?? null,
            'external_urls' => [
                'music' => $track['trackViewUrl'] ?? $track['collectionViewUrl'] ?? 'https://music.apple.com/',
            ],
        ];
    }
}
