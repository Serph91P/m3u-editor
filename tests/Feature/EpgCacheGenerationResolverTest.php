<?php

use App\Models\Epg;
use App\Models\User;
use App\Services\EpgCacheGenerationResolver;
use App\Services\EpgCacheService;
use App\Services\EpgProgrammeStore;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Bus::fake();
    Storage::fake('local');
});

function writeCompleteEpgGeneration(Epg $epg, string $title): string
{
    $resolver = app(EpgCacheGenerationResolver::class);
    $directory = $resolver->createGenerationDirectory($epg);
    $date = now()->format('Y-m-d');

    Storage::disk('local')->put("{$directory}/metadata.json", json_encode([
        'cache_created' => time(),
        'cache_version' => 'v2',
        'epg_uuid' => $epg->uuid,
    ], JSON_THROW_ON_ERROR));

    $store = new EpgProgrammeStore;
    $store->beginWrite(Storage::disk('local')->path("{$directory}/programmes.sqlite"));
    $start = Carbon::parse("{$date} 01:00:00 UTC");
    $store->insert('channel.one', $date, $start->getTimestamp(), null, [
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => $start->toISOString(),
        'title' => $title,
    ]);
    $store->finish();

    Storage::disk('local')->put("{$directory}/channels.json", json_encode([
        'channel.one' => [
            'id' => 'channel.one',
            'display_name' => $title,
        ],
    ], JSON_THROW_ON_ERROR));

    return $directory;
}

it('falls back to the immutable legacy v2 layout when no active generation pointer exists', function () {
    $epg = Epg::factory()->for(User::factory())->create();
    $legacyDirectory = "epg-cache/{$epg->uuid}/v2";

    Storage::disk('local')->put("{$legacyDirectory}/metadata.json", '{"cache_version":"v2"}');

    expect(app(EpgCacheGenerationResolver::class)->resolve($epg))->toBe($legacyDirectory);
});

it('keeps an in-progress reader on its resolved generation while new readers use the published generation', function () {
    $epg = Epg::factory()->for(User::factory())->create();
    $resolver = app(EpgCacheGenerationResolver::class);
    $oldGeneration = writeCompleteEpgGeneration($epg, 'Old programme');
    $resolver->publish($epg, $oldGeneration);

    $oldReader = new EpgCacheService;
    expect($oldReader->getCachedProgrammes($epg, now()->format('Y-m-d'), ['channel.one'])['channel.one'][0]['title'])
        ->toBe('Old programme');
    expect($oldReader->getCachedChannels($epg)['channels']['channel.one']['display_name'])
        ->toBe('Old programme');

    $newGeneration = writeCompleteEpgGeneration($epg, 'New programme');
    $resolver->publish($epg, $newGeneration);

    expect($oldReader->getCachedProgrammes($epg, now()->format('Y-m-d'), ['channel.one'])['channel.one'][0]['title'])
        ->toBe('Old programme')
        ->and((new EpgCacheService)->getCachedProgrammes($epg, now()->format('Y-m-d'), ['channel.one'])['channel.one'][0]['title'])
        ->toBe('New programme')
        ->and($oldReader->getCachedChannels($epg)['channels']['channel.one']['display_name'])
        ->toBe('Old programme')
        ->and((new EpgCacheService)->getCachedChannels($epg)['channels']['channel.one']['display_name'])
        ->toBe('New programme')
        ->and(Storage::disk('local')->exists("{$oldGeneration}/metadata.json"))->toBeTrue()
        ->and(Storage::disk('local')->exists("{$oldGeneration}/programmes.sqlite"))->toBeTrue();
});

it('keeps non-SQLite readers on their resolved generation while new readers use the published generation', function () {
    $epg = Epg::factory()->for(User::factory())->create();
    $resolver = app(EpgCacheGenerationResolver::class);
    $oldGeneration = writeCompleteEpgGeneration($epg, 'Old programme');
    Storage::disk('local')->put("{$oldGeneration}/channels.json", json_encode([
        'channel.old' => ['display_name' => 'Old channel'],
    ], JSON_THROW_ON_ERROR));
    $resolver->publish($epg, $oldGeneration);

    $oldReader = app(EpgCacheService::class);
    expect($oldReader->getCachedChannels($epg)['channels'])->toHaveKey('channel.old');

    $newGeneration = writeCompleteEpgGeneration($epg, 'New programme');
    Storage::disk('local')->put("{$newGeneration}/channels.json", json_encode([
        'channel.new' => ['display_name' => 'New channel'],
    ], JSON_THROW_ON_ERROR));
    $resolver->publish($epg, $newGeneration);

    expect($oldReader->getCachedChannels($epg)['channels'])->toHaveKey('channel.old')
        ->and(app(EpgCacheService::class)->getCachedChannels($epg)['channels'])->toHaveKey('channel.new');
});

it('publishes a complete cache generation after parsing the EPG', function () {
    $epg = Epg::factory()->for(User::factory())->create(['url' => 'https://example.com/guide.xml']);
    $start = now()->startOfDay()->addHour();
    $xml = "<?xml version=\"1.0\"?><tv>\n".
        "<channel id=\"channel.one\"><display-name>Channel One</display-name></channel>\n".
        "<programme start=\"{$start->format('YmdHis O')}\" channel=\"channel.one\"><title>Published programme</title></programme>\n".
        '</tv>';
    Storage::disk('local')->put($epg->file_path, gzencode($xml));

    expect(app(EpgCacheService::class)->cacheEpgData($epg))->toBeTrue();

    $cacheRoot = "epg-cache/{$epg->uuid}/v2";
    $activeDirectory = app(EpgCacheGenerationResolver::class)->resolve($epg);

    expect(Storage::disk('local')->exists("{$cacheRoot}/active-generation.json"))->toBeTrue()
        ->and($activeDirectory)->toStartWith("{$cacheRoot}/generations/")
        ->and(Storage::disk('local')->exists("{$activeDirectory}/metadata.json"))->toBeTrue()
        ->and(Storage::disk('local')->exists("{$activeDirectory}/programmes.sqlite"))->toBeTrue();
});

it('fails safely to the legacy layout for malformed or incomplete active pointers', function () {
    $epg = Epg::factory()->for(User::factory())->create();
    $cacheRoot = "epg-cache/{$epg->uuid}/v2";
    Storage::disk('local')->put("{$cacheRoot}/metadata.json", '{"cache_version":"v2"}');
    Storage::disk('local')->put("{$cacheRoot}/active-generation.json", '{not json');

    expect(app(EpgCacheGenerationResolver::class)->resolve($epg))->toBe($cacheRoot);

    Storage::disk('local')->put("{$cacheRoot}/active-generation.json", json_encode([
        'generation' => str_repeat('a', 32),
    ], JSON_THROW_ON_ERROR));

    expect(app(EpgCacheGenerationResolver::class)->resolve($epg))->toBe($cacheRoot);
});

it('refuses to publish a generation until its metadata and SQLite store are complete', function () {
    $epg = Epg::factory()->for(User::factory())->create();
    $resolver = app(EpgCacheGenerationResolver::class);
    $generation = $resolver->createGenerationDirectory($epg);
    Storage::disk('local')->put("{$generation}/metadata.json", '{"cache_version":"v2"}');

    expect(fn () => $resolver->publish($epg, $generation))
        ->toThrow(RuntimeException::class);
});

it('serializes pointer replacement with a short per-EPG mutation lock', function () {
    $epg = Epg::factory()->for(User::factory())->create();
    $resolver = app(EpgCacheGenerationResolver::class);
    $generation = writeCompleteEpgGeneration($epg, 'Locked programme');
    $lock = Mockery::mock();

    Cache::shouldReceive('lock')
        ->once()
        ->with($resolver->mutationLockName($epg), 30)
        ->andReturn($lock);
    $lock->shouldReceive('block')
        ->once()
        ->with(10, Mockery::type(Closure::class))
        ->andReturnUsing(function (int $seconds, Closure $callback): void {
            $callback();
        });

    $resolver->publish($epg, $generation);

    expect($resolver->resolve($epg))->toBe($generation);
});
