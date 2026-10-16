<?php

use App\Models\MediaServerIntegration;
use App\Models\User;
use App\Services\EmbyManagedLibraryProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function embyPrepareUrl(): string
{
    return 'https://emby.test:8096/M3uEditor/Managed/Libraries/V1/Prepare';
}

function embyCommitUrl(): string
{
    return 'https://emby.test:8096/M3uEditor/Managed/Libraries/V1/Commit';
}

function embyManagedRoot(): string
{
    return '/config/plugins/Emby.M3uEditor.Plugin/managed-publishing';
}

/** The companion derives a hash-based direct child of the managed root, not a slug of the name. */
function embyPreparedPath(): string
{
    return embyManagedRoot().'/movies-0123456789abcdef01234567';
}

beforeEach(function () {
    $user = User::factory()->create();
    $this->integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_managed_setup_binding_id' => null,
        'emby_managed_setup_root' => embyManagedRoot(),
        'emby_publisher_writable_paths' => [embyManagedRoot()],
    ]);
    $this->integration->updateQuietly(['emby_managed_setup_binding_id' => $this->integration->id]);
    $this->service = app(EmbyManagedLibraryProvisioningService::class);
});

/** @return array<string, mixed> */
function embyPreparedEnvelope(MediaServerIntegration $integration, string $operationId, array $overrides = []): array
{
    return array_merge([
        'CapabilityVersion' => 1,
        'IntegrationId' => $integration->id,
        'OperationId' => $operationId,
        'PreparedPath' => embyPreparedPath(),
        'State' => 'prepared',
        'Success' => true,
        'Duplicate' => false,
        'ErrorClass' => null,
        'Message' => null,
    ], $overrides);
}

/** Captures the operation ID the service derives for a request, without asserting on it. */
function embyOperationIdFor(MediaServerIntegration $integration, string $name, string $collectionType): string
{
    $method = new ReflectionMethod(EmbyManagedLibraryProvisioningService::class, 'operationId');
    $method->setAccessible(true);

    return $method->invoke(app(EmbyManagedLibraryProvisioningService::class), $integration, $name, $collectionType);
}

it('prepares the library root folder through the companion contract and returns its path', function () {
    $operationId = embyOperationIdFor($this->integration, 'Trending', 'movies');
    Http::preventStrayRequests();
    Http::fake([
        embyPrepareUrl() => Http::response(embyPreparedEnvelope($this->integration, $operationId)),
    ]);

    expect($this->service->prepare($this->integration, 'Trending', 'movies'))->toBe([
        'success' => true,
        'supported' => true,
        'path' => embyPreparedPath(),
        'operation_id' => $operationId,
        'message' => 'Prepared',
    ])->and(Str::isUuid($operationId))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === embyPrepareUrl()
        && $request->hasHeader('X-Emby-Token', 'emby-secret')
        && $request->data() === [
            'IntegrationId' => $this->integration->id,
            'OperationId' => $operationId,
            'Name' => 'Trending',
            'CollectionType' => 'movies',
        ]);
    Http::assertSentCount(1);
});

it('derives a stable operation ID per integration, collection type, and name so retries resume the same folder', function () {
    $first = embyOperationIdFor($this->integration, 'Trending', 'movies');
    $other = MediaServerIntegration::factory()->for($this->integration->user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
    ]);

    expect($first)->toBe(embyOperationIdFor($this->integration, 'Trending', 'movies'))
        ->and($first)->not->toBe(embyOperationIdFor($this->integration, 'Trending', 'tvshows'))
        ->and($first)->not->toBe(embyOperationIdFor($this->integration, 'Trending 2', 'movies'))
        ->and($first)->not->toBe(embyOperationIdFor($other, 'Trending', 'movies'));
});

it('accepts an already prepared or committed folder when the companion reports a duplicate operation', function (string $state) {
    $operationId = embyOperationIdFor($this->integration, 'Trending', 'movies');
    Http::preventStrayRequests();
    Http::fake([
        embyPrepareUrl() => Http::response(embyPreparedEnvelope($this->integration, $operationId, [
            'State' => $state,
            'Duplicate' => true,
        ])),
    ]);

    $result = $this->service->prepare($this->integration, 'Trending', 'movies');

    expect($result['success'])->toBeTrue()
        ->and($result['path'])->toBe(embyPreparedPath())
        ->and($result['operation_id'])->toBe($operationId);
})->with(['prepared', 'committed']);

it('reports an older companion without the contract as unsupported instead of failing closed', function () {
    Http::preventStrayRequests();
    Http::fake([
        embyPrepareUrl() => Http::response('', 404),
    ]);

    expect($this->service->prepare($this->integration, 'Trending', 'movies'))->toBe([
        'success' => false,
        'supported' => false,
        'path' => null,
        'operation_id' => null,
        'message' => 'The Emby companion does not support managed library preparation. Update the companion, then retry.',
    ]);
});

it('fails closed with a sanitized message for rejected, partial, or foreign prepare responses', function (Closure $body, int $status, string $message) {
    $operationId = embyOperationIdFor($this->integration, 'Trending', 'movies');
    Http::preventStrayRequests();
    Http::fake([
        embyPrepareUrl() => Http::response($body($this->integration, $operationId), $status),
    ]);

    $result = $this->service->prepare($this->integration, 'Trending', 'movies');

    expect($result)->toBe([
        'success' => false,
        'supported' => true,
        'path' => null,
        'operation_id' => null,
        'message' => $message,
    ])->and($result['message'])->not->toContain('emby-secret', embyManagedRoot(), 'managed-publishing');
    Http::assertSentCount(1);
})->with([
    'unauthorized' => [fn () => [], 401, 'Emby rejected the managed library preparation. Check the administrator credential and permissions, then retry.'],
    'forbidden' => [fn () => [], 403, 'Emby rejected the managed library preparation. Check the administrator credential and permissions, then retry.'],
    'redirect' => [fn () => [], 302, 'Emby rejected the managed library preparation. Check the administrator credential and permissions, then retry.'],
    'server fault' => [fn () => [], 500, 'Emby rejected the managed library preparation. Check the administrator credential and permissions, then retry.'],
    'scalar JSON' => [fn () => '"prepared"', 200, 'Emby returned an invalid managed library response. Check the companion configuration, then retry.'],
    'malformed JSON' => [fn () => '{', 200, 'Emby returned an invalid managed library response. Check the companion configuration, then retry.'],
    'unsupported capability version' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['CapabilityVersion' => 2]), 200, 'The Emby companion does not support managed library preparation. Update the companion, then retry.'],
    'string capability version' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['CapabilityVersion' => '1']), 200, 'The Emby companion does not support managed library preparation. Update the companion, then retry.'],
    'foreign integration binding' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['IntegrationId' => $integration->id + 1]), 200, 'Emby reported a managed library binding conflict. Reconnect the integration, then retry.'],
    'foreign operation' => [fn ($integration) => embyPreparedEnvelope($integration, '11111111-2222-4333-8444-555555555555'), 200, 'Emby returned an invalid managed library response. Check the companion configuration, then retry.'],
    'missing operation' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['OperationId' => null]), 200, 'Emby returned an invalid managed library response. Check the companion configuration, then retry.'],
    'collision' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['Success' => false, 'State' => null, 'PreparedPath' => null, 'ErrorClass' => 'collision', 'Message' => 'The managed library destination already exists and is not owned by this operation.']), 200, 'A managed library folder for this name already exists on the Emby server. Choose a different library name, then retry.'],
    'ownership conflict' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['Success' => false, 'State' => null, 'PreparedPath' => null, 'ErrorClass' => 'conflict']), 200, 'A managed library folder for this name already exists on the Emby server. Choose a different library name, then retry.'],
    'filesystem' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['Success' => false, 'State' => null, 'PreparedPath' => null, 'ErrorClass' => 'filesystem', 'Message' => 'The managed library parent is not safely writable.']), 200, 'The Emby companion could not create the managed library folder. Check the managed root permissions, then retry.'],
    'validation' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['Success' => false, 'State' => null, 'PreparedPath' => null, 'ErrorClass' => 'validation']), 200, 'The Emby companion could not prepare the managed library folder. Check the companion configuration, then retry.'],
    'state' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['Success' => false, 'State' => null, 'PreparedPath' => null, 'ErrorClass' => 'state']), 200, 'The Emby companion could not prepare the managed library folder. Check the companion configuration, then retry.'],
    'persistence' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['Success' => false, 'State' => null, 'PreparedPath' => null, 'ErrorClass' => 'persistence']), 200, 'The Emby companion could not prepare the managed library folder. Check the companion configuration, then retry.'],
    'unknown error class' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['Success' => false, 'State' => null, 'PreparedPath' => null, 'ErrorClass' => 'surprise']), 200, 'The Emby companion could not prepare the managed library folder. Check the companion configuration, then retry.'],
    'success without a state' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['State' => null]), 200, 'Emby returned an invalid managed library response. Check the companion configuration, then retry.'],
    'aborted state' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['State' => 'aborted']), 200, 'Emby returned an invalid managed library response. Check the companion configuration, then retry.'],
    'string success' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['Success' => 'true']), 200, 'The Emby companion could not prepare the managed library folder. Check the companion configuration, then retry.'],
    'missing path' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['PreparedPath' => null]), 200, 'Emby returned an invalid managed library response. Check the companion configuration, then retry.'],
    'path is the root itself' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['PreparedPath' => embyManagedRoot()]), 200, 'Emby returned an invalid managed library response. Check the companion configuration, then retry.'],
    'path outside the root' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['PreparedPath' => '/config/plugins/other/movies-abc']), 200, 'Emby returned an invalid managed library response. Check the companion configuration, then retry.'],
    'path two levels deep' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['PreparedPath' => embyManagedRoot().'/movies-abc/nested']), 200, 'Emby returned an invalid managed library response. Check the companion configuration, then retry.'],
    'path escaping by traversal' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['PreparedPath' => embyManagedRoot().'/../escape']), 200, 'Emby returned an invalid managed library response. Check the companion configuration, then retry.'],
    'path with a sibling prefix' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['PreparedPath' => embyManagedRoot().'-other/movies-abc']), 200, 'Emby returned an invalid managed library response. Check the companion configuration, then retry.'],
    'relative path' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['PreparedPath' => 'movies-abc']), 200, 'Emby returned an invalid managed library response. Check the companion configuration, then retry.'],
]);

it('returns a sanitized connection error when the companion is unreachable during prepare', function () {
    Http::preventStrayRequests();
    Http::fake([
        embyPrepareUrl() => Http::failedConnection(),
    ]);

    expect($this->service->prepare($this->integration, 'Trending', 'movies')['message'])
        ->toBe('Emby managed library preparation could not connect. Check that Emby is reachable, then retry.');
});

it('does not prepare before the managed setup confirmed a root', function () {
    $this->integration->updateQuietly(['emby_managed_setup_root' => null]);
    Http::preventStrayRequests();

    expect($this->service->prepare($this->integration, 'Trending', 'movies')['message'])
        ->toBe('Emby managed setup is not ready. Run the managed setup, then retry.');
    Http::assertNothingSent();
});

it('blocks prepare and commit on a non-approved transport without sending the administrator credential', function () {
    $this->integration->update([
        'host' => 'emby.example.com',
        'ssl' => false,
    ]);
    Http::preventStrayRequests();

    expect($this->service->prepare($this->integration, 'Trending', 'movies')['message'])
        ->toBe('Emby managed library preparation was blocked by the integration security policy.')
        ->and($this->service->commit($this->integration, '11111111-2222-4333-8444-555555555555')['success'])->toBeFalse();
    Http::assertNothingSent();
});

it('does not prepare or commit for non-Emby integrations', function () {
    $this->integration->updateQuietly(['type' => 'jellyfin']);
    Http::preventStrayRequests();

    expect($this->service->prepare($this->integration, 'Trending', 'movies')['success'])->toBeFalse()
        ->and($this->service->commit($this->integration, '11111111-2222-4333-8444-555555555555')['success'])->toBeFalse();
    Http::assertNothingSent();
});

it('commits a prepared folder after Emby accepted the library', function () {
    $operationId = embyOperationIdFor($this->integration, 'Trending', 'movies');
    Http::preventStrayRequests();
    Http::fake([
        embyCommitUrl() => Http::response(embyPreparedEnvelope($this->integration, $operationId, ['State' => 'committed'])),
    ]);

    expect($this->service->commit($this->integration, $operationId))->toBe([
        'success' => true,
        'message' => 'Committed',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === embyCommitUrl()
        && $request->hasHeader('X-Emby-Token', 'emby-secret')
        && $request->data() === [
            'IntegrationId' => $this->integration->id,
            'OperationId' => $operationId,
        ]);
});

it('treats a duplicate commit as settled', function () {
    $operationId = embyOperationIdFor($this->integration, 'Trending', 'movies');
    Http::preventStrayRequests();
    Http::fake([
        embyCommitUrl() => Http::response(embyPreparedEnvelope($this->integration, $operationId, ['State' => 'committed', 'Duplicate' => true])),
    ]);

    expect($this->service->commit($this->integration, $operationId)['success'])->toBeTrue();
});

it('reports a retryable failure when the commit is rejected, ambiguous, or not committed', function (Closure $body, int $status) {
    $operationId = embyOperationIdFor($this->integration, 'Trending', 'movies');
    Http::preventStrayRequests();
    Http::fake([
        embyCommitUrl() => Http::response($body($this->integration, $operationId), $status),
    ]);

    expect($this->service->commit($this->integration, $operationId))->toBe([
        'success' => false,
        'message' => 'Emby created the managed library, but the companion could not confirm its folder. Retry to complete the publication.',
    ]);
})->with([
    'unauthorized' => [fn () => [], 401],
    'missing endpoint' => [fn () => [], 404],
    'server fault' => [fn () => [], 500],
    'malformed JSON' => [fn () => '{', 200],
    'still prepared' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['State' => 'prepared']), 200],
    'not owned' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['Success' => false, 'State' => null, 'ErrorClass' => 'ownership']), 200],
    'foreign integration' => [fn ($integration, $operationId) => embyPreparedEnvelope($integration, $operationId, ['State' => 'committed', 'IntegrationId' => $integration->id + 1]), 200],
    'foreign operation' => [fn ($integration) => embyPreparedEnvelope($integration, '11111111-2222-4333-8444-555555555555', ['State' => 'committed']), 200],
]);

it('returns a retryable failure when the companion is unreachable during commit', function () {
    $operationId = embyOperationIdFor($this->integration, 'Trending', 'movies');
    Http::preventStrayRequests();
    Http::fake([
        embyCommitUrl() => Http::failedConnection(),
    ]);

    expect($this->service->commit($this->integration, $operationId)['success'])->toBeFalse();
});

it('recognizes only direct children of a managed root', function (string $path, string $root, bool $expected) {
    expect(MediaServerIntegration::isDirectChildOfWritableRoot($path, $root))->toBe($expected);
})->with([
    'unix child' => ['/srv/managed/movies-abc', '/srv/managed', true],
    'unix child with trailing root slash' => ['/srv/managed/movies-abc', '/srv/managed/', true],
    'unix root itself' => ['/srv/managed', '/srv/managed', false],
    'unix grandchild' => ['/srv/managed/movies-abc/nested', '/srv/managed', false],
    'unix sibling prefix' => ['/srv/managed-other/movies-abc', '/srv/managed', false],
    'unix traversal' => ['/srv/managed/../movies-abc', '/srv/managed', false],
    'windows child' => ['C:\\Managed\\Movies-abc', 'c:\\managed', true],
    'windows grandchild' => ['C:\\Managed\\Movies-abc\\nested', 'C:\\Managed', false],
    'unc child' => ['\\\\nas\\managed\\movies-abc', '\\\\nas\\managed', true],
    'mixed styles' => ['/srv/managed/movies-abc', 'C:\\managed', false],
    'relative path' => ['movies-abc', '/srv/managed', false],
]);
