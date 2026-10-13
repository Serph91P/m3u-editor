<?php

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Jobs\ReleaseWatchedManagedCache;
use App\Models\CachedContentFile;
use App\Models\ManagedCacheInterest;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\CachedContentActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('cache');
    config(['cache.default' => 'array']);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->integration = MediaServerIntegration::factory()->for($this->user)->createQuietly(['type' => 'emby']);
});

function lifecycleEpisode(Playlist $playlist, string $episode): CachedContentFile
{
    return CachedContentFile::factory()->for($playlist)->createQuietly([
        'status' => CachedContentFileStatus::Completed,
        'managed_by' => CachedContentManagedBy::ManagedPublishing,
        'source' => 'provider',
        'disk' => 'cache',
        'file_path' => "managed/{$episode}.mp4",
        'file_size_bytes' => 11,
        'content_fingerprint' => "fixture-{$episode}",
    ]);
}

function lifecycleInterest(CachedContentFile $file, MediaServerIntegration $integration, string $episode, string $consumer, ?\DateTimeInterface $releaseAfter): void
{
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => "episode:fixture:{$episode}",
        'variant_key' => '1080p',
        'consumer_id' => $consumer,
        'watched_at' => $releaseAfter ? now()->subHour() : null,
        'release_after' => $releaseAfter,
    ]);
}

it('removes only a watched completed episode while retaining an active next episode', function () {
    [$episodeOne, $episodeTwo, $episodeThree] = [
        lifecycleEpisode($this->playlist, 's01e01'),
        lifecycleEpisode($this->playlist, 's01e02'),
        lifecycleEpisode($this->playlist, 's01e03'),
    ];

    foreach ([$episodeOne, $episodeTwo, $episodeThree] as $episode) {
        Storage::disk('cache')->put($episode->file_path, 'video-bytes');
    }

    lifecycleInterest($episodeOne, $this->integration, 's01e01', 'profile-a', now()->subMinute());
    lifecycleInterest($episodeTwo, $this->integration, 's01e02', 'profile-a', now()->subMinute());
    lifecycleInterest($episodeThree, $this->integration, 's01e03', 'profile-a', null);

    app(CachedContentActivityService::class)->acquireReaderForFile($this->user, $this->integration, 'episode-two-reader', $episodeTwo);

    dispatch_sync(new ReleaseWatchedManagedCache);

    expect(CachedContentFile::find($episodeOne->id))->toBeNull()
        ->and(Storage::disk('cache')->exists($episodeOne->file_path))->toBeFalse()
        ->and(CachedContentFile::find($episodeTwo->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists($episodeTwo->file_path))->toBeTrue()
        ->and(CachedContentFile::find($episodeThree->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists($episodeThree->file_path))->toBeTrue();
});
