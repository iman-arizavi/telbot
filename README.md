# Telegram Spotify Search Bot (PHP)

A small PHP 8.1+ Telegram bot that searches Spotify, shows selectable results, enforces channel membership, and delivers only audio that you are authorized to distribute.

## Features

- Spotify track search with inline result buttons
- Mandatory Telegram channel membership check
- Delivery of licensed/local MP3 files
- Official Spotify preview when available
- Secure webhook secret validation
- Secrets kept outside Git in a local `.env`
- `PAYMENTS_ENABLED` placeholder for a future payment module

## Requirements

- PHP 8.1+ with cURL and JSON extensions
- HTTPS hosting reachable by Telegram
- Telegram bot token
- Spotify Developer app Client ID and Client Secret
- The bot must be an administrator in the required channel so `getChatMember` can reliably verify users

## Setup

1. Copy `.env.example` to `.env`.
2. Fill in the Telegram token, webhook secret, channel username/link, Spotify credentials and public `APP_URL`.
3. Upload the project to an HTTPS-enabled PHP host.
4. From the project directory run:

   ```bash
   php set-webhook.php
   ```

5. Open the bot in Telegram and send `/start`.

## Licensed full audio

Spotify's Web API supplies metadata and sometimes a short official preview; it does not provide full downloadable song files. To distribute a track you own or are licensed to share, put the MP3 at:

```
storage/tracks/SPOTIFY_TRACK_ID.mp3
```

The bot will prefer that file, then try the official preview, then fall back to the official Spotify listening link.

## Creating credentials

- Telegram: create/copy the bot token through BotFather.
- Spotify: create an app in the Spotify Developer Dashboard and copy its Client ID and Client Secret.
- Webhook secret: generate a long random string, for example:

  ```bash
  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
  ```

Never commit the real `.env` file.

## Future payments

Payment is intentionally separated from search and delivery. A later version can add a database, subscription status, a payment provider adapter and access checks without replacing the current bot flow.
