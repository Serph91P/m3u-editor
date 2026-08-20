<?php

/**
 * Regression tests for Series::isMetadataFresh() / scopeNeedsMetadataRefresh(),
 * which let ProcessM3uImportSeriesEpisodes skip the throttled provider call
 * for series that are already fresh, without any extra provider round trip.
 */

use App\Models\Episode;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('excludes a fresh series with episodes from needsMetadataRefresh', function () {
    $series = Series::factory()->create([
        'last_modified' => now()->subDay(),
        'last_metadata_fetch' => now(),
    ]);
    Episode::factory()->create(['series_id' => $series->id]);

    expect($series->fresh()->isMetadataFresh())->toBeTrue();
    expect(Series::query()->needsMetadataRefresh()->whereKey($series->id)->exists())->toBeFalse();
});

it('includes a series whose provider last_modified is newer than our last fetch', function () {
    $series = Series::factory()->create([
        'last_modified' => now(),
        'last_metadata_fetch' => now()->subDay(),
    ]);
    Episode::factory()->create(['series_id' => $series->id]);

    expect($series->fresh()->isMetadataFresh())->toBeFalse();
    expect(Series::query()->needsMetadataRefresh()->whereKey($series->id)->exists())->toBeTrue();
});

it('includes a series with matching timestamps but no episodes', function () {
    $series = Series::factory()->create([
        'last_modified' => now()->subDay(),
        'last_metadata_fetch' => now(),
    ]);

    expect($series->fresh()->isMetadataFresh())->toBeFalse();
    expect(Series::query()->needsMetadataRefresh()->whereKey($series->id)->exists())->toBeTrue();
});

it('includes a never-fetched series', function () {
    $series = Series::factory()->create([
        'last_modified' => null,
        'last_metadata_fetch' => null,
    ]);

    expect(Series::query()->needsMetadataRefresh()->whereKey($series->id)->exists())->toBeTrue();
});

it('ignores freshness entirely when a refresh is forced', function () {
    $series = Series::factory()->create([
        'last_modified' => now()->subDay(),
        'last_metadata_fetch' => now(),
    ]);
    Episode::factory()->create(['series_id' => $series->id]);

    expect(Series::query()->needsMetadataRefresh(refresh: true)->whereKey($series->id)->exists())->toBeTrue();
});
