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
use App\Services\CachedContentRetentionService;
use App\Services\ManagedCacheControlService;
use App\Settings\GeneralSettings;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('cache');
    config(['cache.default' => 'array']);
    $settings = Mockery::mock(GeneralSettings::class);
    $settings->shouldReceive('refresh')->zeroOrMoreTimes()->andReturnSelf();
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->integration = MediaServerIntegration::factory()->for($this->user)->createQuietly(['type' => 'emby']);
});

function makeManagedFile(Playlist $playlist): CachedContentFile
{
    return CachedContentFile::factory()->for($playlist)->createQuietly([
        'status' => CachedContentFileStatus::Completed,
        'managed_by' => CachedContentManagedBy::ManagedPublishing,
        'source' => 'provider',
        'disk' => 'cache',
        'file_path' => 'managed/movie.mp4',
        'file_size_bytes' => 11,
        'content_fingerprint' => 'fixture-fingerprint',
    ]);
}

it('sets release_after when cohort is complete and watched', function () {
    $file = makeManagedFile($this->playlist);
    $interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]);

    app(ManagedCacheControlService::class)->releaseWatched(
        $this->user,
        $this->integration,
        $interest->mapping_uuid,
        $interest->canonical_id,
        $interest->variant_key,
        $interest->consumer_id,
        watched: true,
        deleteAfterHours: 0,
        cohortComplete: true,
    );

    $interest->refresh();
    expect($interest->watched_at)->not->toBeNull()
        ->and($interest->release_after)->not->toBeNull()
        ->and($interest->release_after->isFuture())->toBeTrue();
});

it('cancels release when watched is false', function () {
    $file = makeManagedFile($this->playlist);
    $interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);

    app(ManagedCacheControlService::class)->releaseWatched(
        $this->user,
        $this->integration,
        $interest->mapping_uuid,
        $interest->canonical_id,
        $interest->variant_key,
        $interest->consumer_id,
        watched: false,
        deleteAfterHours: 24,
        cohortComplete: true,
    );

    $interest->refresh();
    expect($interest->watched_at)->toBeNull()
        ->and($interest->release_after)->toBeNull();
});

it('does not delete a managed file while a reader lease is active', function () {
    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put('managed/movie.mp4', 'video-bytes');
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);

    app(CachedContentActivityService::class)->acquireReaderForFile(
        $this->user,
        $this->integration,
        '33333333-3333-4333-8333-333333333333',
        $file,
    );

    dispatch_sync(new ReleaseWatchedManagedCache);

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists('managed/movie.mp4'))->toBeTrue();
});

it('does not delete a managed file that its playlist offers as a shared cache', function () {
    $this->playlist->update(['share_cache_across_playlists' => true]);
    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put('managed/movie.mp4', 'video-bytes');
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);

    dispatch_sync(new ReleaseWatchedManagedCache);

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists('managed/movie.mp4'))->toBeTrue();
});

it('does not delete a managed file while a playback lease for its interest target is active', function () {
    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put('managed/movie.mp4', 'video-bytes');
    $interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);
    app(CachedContentActivityService::class)->acquirePlayback(
        $this->user,
        $this->integration,
        '55555555-5555-4555-8555-555555555555',
        1,
        [
            'mapping_uuid' => $interest->mapping_uuid,
            'revision' => str_repeat('a', 64),
            'canonical_id' => $interest->canonical_id,
            'variant_key' => $interest->variant_key,
        ],
    );

    dispatch_sync(new ReleaseWatchedManagedCache);

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists('managed/movie.mp4'))->toBeTrue();
});

it('does not delete a releasable managed file while cache is disabled at execution', function () {
    $settings = Mockery::mock(GeneralSettings::class);
    $settings->shouldReceive('refresh')->once()->andReturnSelf();
    $settings->enable_cache = false;
    app()->instance(GeneralSettings::class, $settings);

    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put('managed/movie.mp4', 'video-bytes');
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);

    app(ReleaseWatchedManagedCache::class)->handle(app(CachedContentRetentionService::class));

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists('managed/movie.mp4'))->toBeTrue();
});

it('deletes a managed file when all interests are released and no lease is active', function () {
    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put('managed/movie.mp4', 'video-bytes');
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);

    dispatch_sync(new ReleaseWatchedManagedCache);

    expect(CachedContentFile::find($file->id))->toBeNull()
        ->and(Storage::disk('cache')->exists('managed/movie.mp4'))->toBeFalse();
});

it('keeps a managed file when not all interests are released', function () {
    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put('managed/movie.mp4', 'video-bytes');
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-b',
        'watched_at' => null,
        'release_after' => null,
    ]);

    dispatch_sync(new ReleaseWatchedManagedCache);

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists('managed/movie.mp4'))->toBeTrue();
});

it('keeps non-managed files untouched', function () {
    $file = CachedContentFile::factory()->for($this->playlist)->createQuietly([
        'status' => CachedContentFileStatus::Completed,
        'managed_by' => null,
        'source' => 'provider',
        'disk' => 'cache',
        'file_path' => 'manual/movie.mp4',
        'file_size_bytes' => 11,
        'content_fingerprint' => 'fixture-fingerprint',
    ]);
    Storage::disk('cache')->put('manual/movie.mp4', 'video-bytes');

    dispatch_sync(new ReleaseWatchedManagedCache);

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists('manual/movie.mp4'))->toBeTrue();
});

it('does not let generic retention delete managed publishing files', function () {
    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put('managed/movie.mp4', 'video-bytes');

    app(CachedContentRetentionService::class)->deleteIds(collect([$file->id]));

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists('managed/movie.mp4'))->toBeTrue();
});

it('retains managed metadata when the storage delete reports failure', function () {
    $file = makeManagedFile($this->playlist);
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);

    Storage::shouldReceive('disk')
        ->once()
        ->with('cache')
        ->andReturn(Mockery::mock(Filesystem::class, function ($disk): void {
            $disk->shouldReceive('delete')->once()->with('managed/movie.mp4')->andReturnFalse();
        }));

    dispatch_sync(new ReleaseWatchedManagedCache);

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(ManagedCacheInterest::query()->where('cached_content_file_id', $file->id)->exists())->toBeTrue();
});
