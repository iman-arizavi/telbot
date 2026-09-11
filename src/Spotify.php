<?php
declare(strict_types=1);

namespace TelBot;

use RuntimeException;

final class Spotify
{
    private ?string $accessToken = null;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret
    ) {
    }

    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $ch = curl_init('https://accounts.spotify.com/api/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode("{$this->clientId}:{$this->clientSecret}")],
            CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials']),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = json_decode((string) $body, true);

        if ($status >= 400 || empty($data['access_token'])) {
            throw new RuntimeException('Could not authenticate with Spotify.');
        }
        return $this->accessToken = $data['access_token'];
    }

    private function get(string $path, array $query = []): array
    {
        $url = 'https://api.spotify.com/v1/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token()],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = json_decode((string) $body, true);
        if ($status >= 400) {
            throw new RuntimeException((string) ($data['error']['message'] ?? 'Spotify API error.'));
        }
        return $data;
    }

    public function searchTracks(string $query, int $limit = 8): array
    {
        $data = $this->get('search', ['q' => $query, 'type' => 'track', 'limit' => $limit]);
        return $data['tracks']['items'] ?? [];
    }

    public function track(string $id): array
    {
        return $this->get('tracks/' . rawurlencode($id));
    }
}

