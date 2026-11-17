<?php

namespace Azuriom\Plugin\TwitchLive\Twitch;

use Illuminate\Support\Collection;

/**
 * Generates fake but stable live streams, to try the plugin without Twitch credentials.
 */
class DemoStreams
{
    /**
     * Games of the demo streams, with their Twitch id and a few stream titles.
     */
    protected const GAMES = [
        [
            'id' => '263490',
            'name' => 'Rust',
            'titles' => ['Base building with the squad', 'Wipe day on the server!'],
        ],
        [
            'id' => '32982',
            'name' => 'Grand Theft Auto V',
            'titles' => ['Roleplay night on the server', 'Opening the new city shop'],
        ],
        [
            'id' => Settings::MINECRAFT_GAME_ID,
            'name' => 'Minecraft',
            'titles' => ['Hardcore survival, day 42', 'Building the new spawn of the server!'],
        ],
        [
            'id' => '32399',
            'name' => 'Counter-Strike',
            'titles' => ['Community matches tonight', 'Warming up before the tournament'],
        ],
    ];

    /**
     * @param  \Illuminate\Support\Collection<int, \Azuriom\Plugin\TwitchLive\Models\Streamer>  $streamers
     * @return array<int, array<string, mixed>>
     */
    public static function generate(Collection $streamers): array
    {
        // Stable during an hour, so the page does not change on every refresh.
        $slot = now()->format('YmdH');
        $streams = [];

        foreach ($streamers->values() as $index => $streamer) {
            $hash = crc32($streamer->login.'|'.$slot);

            if (! $streamer->is_featured && $hash % 10 < 4) {
                continue; // ~40% of the other streamers are offline
            }

            $chatting = ! $streamer->is_featured && $hash % 7 === 0;
            // Same order as the thumbnails, so each streamer keeps its game and picture.
            $game = self::GAMES[$index % count(self::GAMES)];

            $streams[] = [
                'id' => 'demo-'.$streamer->id,
                'user_id' => 'demo-'.$streamer->id,
                'login' => $streamer->login,
                'name' => $streamer->displayName(),
                'game_id' => $chatting ? '509658' : $game['id'],
                'game_name' => $chatting ? 'Just Chatting' : $game['name'],
                'title' => $chatting ? 'Chatting before the session' : $game['titles'][intdiv($hash, 7) % count($game['titles'])],
                'viewers' => ($streamer->is_featured ? 180 : 4) + $hash % 260,
                'started_at' => now()->startOfHour()->subMinutes(15 + $hash % 200)->toIso8601ZuluString(),
                'language' => 'en',
                'thumbnail' => plugin_asset('twitch-live', 'img/demo-'.(($index % 4) + 1).'.svg'),
            ];
        }

        return $streams;
    }
}
