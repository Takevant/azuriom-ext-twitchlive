<?php

namespace Azuriom\Plugin\TwitchLive\Twitch;

use Illuminate\Support\Collection;

/**
 * Generates fake but stable live streams, to try the plugin without Twitch credentials.
 */
class DemoStreams
{
    protected const TITLES = [
        'Building the new capital on the server!',
        'Hardcore survival – day 42',
        'Skyblock: aiming for the top island',
        'Faction war tonight on the server!',
        'Redstone and automatic farms',
        'Chill & chatting with the community',
        'Build event: the Christmas spawn of the server',
        'End speedrun on the server',
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

            $streams[] = [
                'id' => 'demo-'.$streamer->id,
                'user_id' => 'demo-'.$streamer->id,
                'login' => $streamer->login,
                'name' => $streamer->displayName(),
                'game_id' => $chatting ? '509658' : Settings::MINECRAFT_GAME_ID,
                'game_name' => $chatting ? 'Just Chatting' : 'Minecraft',
                'title' => $chatting ? 'Chatting before the session' : self::TITLES[$hash % count(self::TITLES)],
                'viewers' => ($streamer->is_featured ? 180 : 4) + $hash % 260,
                'started_at' => now()->startOfHour()->subMinutes(15 + $hash % 200)->toIso8601ZuluString(),
                'language' => 'en',
                'thumbnail' => plugin_asset('twitch-live', 'img/demo-'.(($index % 4) + 1).'.svg'),
            ];
        }

        return $streams;
    }
}
