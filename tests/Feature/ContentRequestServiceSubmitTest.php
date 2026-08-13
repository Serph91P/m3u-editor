<?php

use App\Models\ArrIntegration;
use App\Models\MediaRequest;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\ContentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration = ArrIntegration::factory()->radarr()->guestEnabled()->create([
        'user_id' => $this->user->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/movies',
    ]);
    $this->auth = PlaylistAuth::factory()->create([
        'user_id' => $this->user->id,
        'auto_approve_requests' => true,
    ]);
    $this->auth->assignTo($this->playlist);
});

it('submits an auto-approved request and adds it to the provider', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/movie/lookup')) {
            return Http::response([
                ['tmdbId' => 550, 'title' => 'Fight Club', 'titleSlug' => 'fight-club'],
            ], 200);
        }

        if ($request->method() === 'GET') {
            return Http::response([], 200);
        }

        return Http::response(['id' => 1], 201);
    });

    $result = app(ContentRequestService::class)->submit($this->playlist, $this->auth, 'movie', $this->integration->id, 550);

    expect($result['ok'])->toBeTrue()
        ->and($result['status'])->toBe('approved');

    expect(MediaRequest::where('external_id', '550')->where('status', 'approved')->count())->toBe(1);
});

it('never calls the provider add endpoint when a concurrent submission wins the race to claim the request', function () {
    // Simulates two near-simultaneous submissions for the same title: both pass
    // the initial "already requested?" pre-check before either has written a
    // MediaRequest row. The competing request's row lands during this
    // submission's checkExists() call - i.e. before this submission reaches
    // its own atomic insert - which is exactly the gap the original race lived in.
    $addCalls = 0;

    Http::fake(function ($request) use (&$addCalls) {
        if (str_contains($request->url(), '/movie/lookup')) {
            return Http::response([
                ['tmdbId' => 550, 'title' => 'Fight Club', 'titleSlug' => 'fight-club'],
            ], 200);
        }

        if ($request->method() === 'GET') {
            MediaRequest::create([
                'playlist_auth_id' => test()->auth->id,
                'arr_integration_id' => test()->integration->id,
                'title' => 'Fight Club',
                'external_id' => '550',
                'request_type' => 'movie',
                'payload' => ['tmdbId' => 550],
                'status' => 'approved',
                'requested_at' => now(),
                'reviewed_at' => now(),
            ]);

            return Http::response([], 200);
        }

        $addCalls++;

        return Http::response(['id' => 1], 201);
    });

    $result = app(ContentRequestService::class)->submit($this->playlist, $this->auth, 'movie', $this->integration->id, 550);

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe('already_requested')
        ->and($addCalls)->toBe(0, 'The provider add endpoint must never be called for a request that loses the atomic-insert race.');

    expect(MediaRequest::where('external_id', '550')->where('status', 'approved')->count())->toBe(1);
});

it('deletes the claimed media request and reports failure when the provider rejects the add', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/movie/lookup')) {
            return Http::response([
                ['tmdbId' => 550, 'title' => 'Fight Club', 'titleSlug' => 'fight-club'],
            ], 200);
        }

        if ($request->method() === 'GET') {
            return Http::response([], 200);
        }

        return Http::response(['message' => 'Provider rejected the request'], 500);
    });

    $result = app(ContentRequestService::class)->submit($this->playlist, $this->auth, 'movie', $this->integration->id, 550);

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe('submission_failed');

    expect(MediaRequest::where('external_id', '550')->count())->toBe(0);
});
