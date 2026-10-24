<?php

use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'offline-synthetic';
    $settings->tmdb_language = 'de-DE';
    $settings->tmdb_rate_limit = 40;
    $settings->tmdb_confidence_threshold = 80;
    $this->tmdb = new TmdbService($settings);
    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->andReturn(1);
});

function identityPage(int $page, int $pages = 2): array
{
    return ['page' => $page, 'total_pages' => $pages, 'total_results' => 20 * $pages,
        'results' => array_map(fn ($id) => ['id' => $id, 'name' => 'Synthetic '.$id, 'original_name' => 'Synthetic '.$id, 'overview' => '', 'first_air_date' => '2020-01-01'], range(($page - 1) * 20 + 1, $page * 20))];
}

it('collects complete native pages with a hard budget and cached replay', function () {
    Http::fake(fn ($request) => Http::response(identityPage($request['page'])));
    $result = $this->tmdb->searchIdentityCandidates('tv', 'Synthetic 1984', null);
    expect($result['status'])->toBe('complete')->and($result['candidates'])->toHaveCount(40)
        ->and($result['candidates'][32]['tmdb_id'])->toBe(33);
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => $r['query'] === 'synthetic 1984' && ! isset($r['first_air_date_year']));
    expect($this->tmdb->searchIdentityCandidates('tv', 'SYNTHETIC 1984', null))->toBe($result);
    Http::assertSentCount(2);
});

it('distinguishes an incomplete budget from complete evidence and deduplicates IDs', function () {
    Http::fake(function ($request) {
        $page = identityPage($request['page'], 5);
        if ($request['page'] === 2) { $page['results'][0] = identityPage(1)['results'][0]; }
        return Http::response($page);
    });
    $result = $this->tmdb->searchIdentityCandidates('tv', 'Synthetic', null);
    expect($result['status'])->toBe('incomplete')->and($result['candidates'])->toHaveCount(79);
    Http::assertSentCount(4);
});

it('never treats faulty pagination as a unique complete candidate set', function ($fault) {
    Http::fake(function ($request) use ($fault) {
        if ($request['page'] === 1) { return Http::response(identityPage(1)); }
        return match ($fault) {
            '429' => Http::response([], 429),
            'timeout' => throw new RuntimeException('synthetic timeout'),
            'malformed' => Http::response(['results' => 'invalid']),
            'partial' => Http::response(array_replace(identityPage(2), ['total_pages' => 3])),
            'wrong-page' => Http::response(identityPage(1)),
            'short' => Http::response(array_replace(identityPage(2), ['results' => []])),
        };
    });
    expect($this->tmdb->searchIdentityCandidates('tv', 'Synthetic', null))->toBe(['status' => 'error', 'candidates' => []]);
    Http::assertSentCount($fault === 'timeout' ? 1 : 2);
})->with(['429', 'timeout', 'malformed', 'partial', 'wrong-page', 'short']);

it('reads bound native translated work names not language labels and caches the request', function () {
    Http::fake(['*/tv/992001/translations*' => Http::response(['id' => 992001, 'translations' => [
        ['iso_3166_1' => 'JP', 'iso_639_1' => 'ja', 'name' => 'Japanese', 'data' => ['name' => '合成作品']],
        ['iso_3166_1' => 'DE', 'iso_639_1' => 'de', 'name' => 'German', 'data' => ['name' => 'Synthetisches Werk']],
    ]])]);
    $result = $this->tmdb->getIdentityTranslations('tv', 992001);
    expect($result)->toBe(['status' => 'complete', 'titles' => ['合成作品', 'Synthetisches Werk']]);
    expect($this->tmdb->getIdentityTranslations('tv', 992001))->toBe($result);
    Http::assertSentCount(1);
});

it('rejects translations bound to the wrong ID or malformed work data', function ($payload) {
    Http::fake(['*' => Http::response($payload)]);
    expect($this->tmdb->getIdentityTranslations('tv', 992001))->toBe(['status' => 'error', 'titles' => []]);
    Http::assertSentCount(1);
})->with([
    'wrong ID' => [['id' => 992002, 'translations' => []]],
    'language label only' => [['id' => 992001, 'translations' => [['name' => 'German']]]],
    'wrong title type' => [['id' => 992001, 'translations' => [['iso_3166_1' => 'DE', 'iso_639_1' => 'de', 'data' => ['name' => []]]]]],
]);
