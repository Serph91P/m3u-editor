<?php

use App\Models\Category;
use App\Models\Channel;
use App\Models\EmbyLibraryMapping;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\EmbyPublicationCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('assigns distinct deterministic folders to different movie identities with colliding slugs', function (array $collidingTitles) {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['name' => 'Synthetic movies', 'type' => 'vod']);
    foreach ([...$collidingTitles, 'Unique Film'] as $index => $title) {
        Channel::factory()->for($user)->for($playlist)->for($group)->createQuietly([
            'enabled' => true, 'is_vod' => true, 'title' => $title,
            'title_custom' => null, 'year' => 2026, 'tmdb_id' => 900001 + $index,
            'tvdb_id' => null, 'imdb_id' => null, 'info' => null,
            'movie_data' => null, 'url' => 'https://source.invalid/movie.mkv',
        ]);
    }
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'vod_group', 'source_identifier' => (string) $group->id,
        'source_label' => $group->name, 'collection_type' => 'movies',
        'options' => ['nfo' => true, 'versions' => true],
    ]);
    $service = app(EmbyPublicationCatalogService::class);
    $first = $service->buildMapping($mapping);
    $second = $service->buildMapping($mapping);
    $items = collect($first['items']);
    expect($items)->toHaveCount(3)
        ->and($items->pluck('relative_folder')->map('strtolower')->unique())->toHaveCount(3)
        ->and($items->pluck('base_filename')->map('strtolower')->unique())->toHaveCount(3)
        ->and($items->firstWhere('display_title', 'Unique Film')['relative_folder'])->toBe('unique-film-2026')
        ->and($first)->toBe($second);
})->with([
    'normalized titles' => [['Sample / Film', 'Sample Film']],
    'truncated titles' => [[str_repeat('a', 160).' First', str_repeat('a', 160).' Second']],
    'case-insensitive titles' => [['SAMPLE FILM', 'Sample Film']],
]);

it('keeps distinct series identities with colliding folders separate', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $category = Category::factory()->for($user)->for($playlist)->create(['name' => 'Synthetic series']);
    foreach (['Sample / Series', 'Sample Series'] as $index => $title) {
        Series::factory()->for($user)->for($playlist)->for($category)->createQuietly([
            'enabled' => true, 'name' => $title, 'tmdb_id' => 900101 + $index,
            'release_date' => '2026-01-01', 'metadata' => [],
        ]);
    }
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'series_category', 'source_identifier' => (string) $category->id,
        'source_label' => $category->name, 'collection_type' => 'tvshows',
    ]);
    $service = app(EmbyPublicationCatalogService::class);
    $catalog = $service->buildMapping($mapping);
    expect($catalog['items'])->toHaveCount(2)
        ->and(array_unique(array_column($catalog['items'], 'relative_folder')))->toHaveCount(2)
        ->and($catalog)->toBe($service->buildMapping($mapping));
});
