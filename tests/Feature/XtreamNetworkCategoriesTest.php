<?php

use App\Models\Network;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Support\Str;

function networkCategoriesUrl(string $username, string $password, string $action): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ]);
}

it('serves stable numeric category ids on the network playlist Xtream surface', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['is_network_playlist' => true]);
    $username = 'testuser_'.Str::random(5);
    $password = 'testpass';

    $auth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => $username,
        'password' => $password,
        'enabled' => true,
        'user_id' => $user->id,
    ]);
    $playlist->playlistAuths()->attach($auth);

    Network::factory()->for($user)->create([
        'name' => 'Network One',
        'network_playlist_id' => $playlist->id,
        'group_name' => 'Jellyfin',
        'channel_number' => 1,
    ]);
    Network::factory()->for($user)->create([
        'name' => 'Network Two',
        'network_playlist_id' => $playlist->id,
        'group_name' => 'Jellyfin',
        'channel_number' => 2,
    ]);
    Network::factory()->for($user)->create([
        'name' => 'Network Three',
        'network_playlist_id' => $playlist->id,
        'group_name' => 'Documentaries',
        'channel_number' => 3,
    ]);

    $categories = $this->getJson(networkCategoriesUrl($username, $password, 'get_live_categories'))
        ->assertOk()
        ->json();

    expect($categories)->toHaveCount(2);
    collect($categories)->each(fn (array $category) => expect($category['category_id'])->toBeNumeric());
    expect(collect($categories)->pluck('category_name')->sort()->values()->all())->toBe(['Documentaries', 'Jellyfin']);

    $streams = $this->getJson(networkCategoriesUrl($username, $password, 'get_live_streams'))
        ->assertOk()
        ->json();

    $categoryIds = collect($categories)->pluck('category_id')->all();
    collect($streams)->each(function (array $stream) use ($categoryIds) {
        expect($stream['category_id'])->toBeIn($categoryIds)
            ->and($stream['category_ids'])->toBe([$stream['category_id']]);
    });
    expect($streams)->toHaveCount(3);

    // IDs are stable across requests.
    $secondRun = $this->getJson(networkCategoriesUrl($username, $password, 'get_live_categories'))->json();
    expect($secondRun)->toBe($categories);
});
