<?php

namespace App\Services;

use App\Models\Epg;
use App\Plugins\Support\PluginExecutionContext;
use App\Rules\UrlIsAllowed;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Host-owned, conditional enrichment API for immutable EPG cache generations.
 *
 * Plugins receive canonical programme data and opaque locators only. The host
 * validates authorisation and patches, then builds and publishes a replacement
 * generation under the existing per-EPG mutation lock.
 */
class EpgCacheEnrichmentService
{
    private const MAX_PAGE_SIZE = 100;

    private const MAX_PATCHES = 100;

    private const LEGACY_GENERATION = 'legacy';

    /** @var list<string> */
    private const MUTABLE_FIELDS = [
        'title', 'subtitle', 'desc', 'category', 'episode_num', 'episode_nums',
        'rating', 'icon', 'images', 'new', 'previously_shown', 'premiere',
        'urls', 'production_year',
    ];

    /** @var list<string> */
    private const BOOLEAN_FIELDS = ['new', 'previously_shown', 'premiere'];

    /** @var list<string> */
    private const STRING_FIELDS = ['title', 'subtitle', 'desc', 'category', 'episode_num', 'rating'];

    /** @var list<string> */
    private const IMAGE_FIELDS = ['url', 'type', 'width', 'height', 'orient', 'size'];

    /** @var list<string> */
    private const IMAGE_TYPES = ['poster', 'banner', 'fanart', 'logo'];

    /** @var list<string> */
    private const IMAGE_ORIENTATIONS = ['P', 'L'];

    public function __construct(private readonly EpgCacheGenerationResolver $generations) {}

    /**
     * @param  array{limit?: int, cursor?: string}  $selection
     * @return array<string, mixed>
     */
    public function snapshot(PluginExecutionContext $context, Epg $epg, array $selection = []): array
    {
        if ($denial = $this->authorizationDenial($context, $epg)) {
            return ['status' => $denial];
        }

        $window = $this->resolvePageWindow($selection);
        if (is_string($window)) {
            return ['status' => $window];
        }

        $directory = $this->generations->resolve($epg);
        if (! $this->isImmutableGeneration($epg, $directory)) {
            return $this->snapshotLegacy($context, $epg, $directory, $window);
        }

        $generation = basename($directory);
        $rows = $this->readRows($directory, $window['after'], $window['limit'] + 1);
        $hasMore = count($rows) > $window['limit'];
        $rows = array_slice($rows, 0, $window['limit']);

        return [
            'status' => 'ok',
            'generation' => $generation,
            'cache_revision' => hash('sha256', $generation),
            'token' => $this->encryptToken($context, $epg, $generation, $rows),
            'programmes' => array_map($this->publicRow(...), $rows),
            'next_cursor' => $this->nextCursor($rows, $hasMore),
        ];
    }

    /**
     * @param  list<array{locator: string, row_revision: string, changes: array<string, mixed>}>  $patches
     * @return array{status: string}
     */
    public function apply(PluginExecutionContext $context, Epg $epg, string $token, array $patches): array
    {
        if ($denial = $this->authorizationDenial($context, $epg)) {
            return ['status' => $denial];
        }
        if (count($patches) > self::MAX_PATCHES) {
            return ['status' => 'invalid_patch'];
        }

        $snapshot = $this->decryptToken($token);
        if (! is_array($snapshot) || ($snapshot['epg_id'] ?? null) !== $epg->id || ($snapshot['plugin_id'] ?? null) !== $context->plugin->id) {
            return ['status' => 'invalid_snapshot'];
        }

        $directory = $this->generations->resolve($epg);
        if (! $this->isImmutableGeneration($epg, $directory)) {
            return ['status' => 'legacy_cache_read_only'];
        }
        if (basename($directory) !== ($snapshot['generation'] ?? null)) {
            return ['status' => 'stale_snapshot'];
        }

        $prevalidatedChanges = $this->prevalidatedChanges($directory, $snapshot, $patches);
        if ($prevalidatedChanges === null) {
            return ['status' => 'conflict'];
        }
        if ($prevalidatedChanges === []) {
            return ['status' => 'noop'];
        }

        $replacement = null;
        try {
            $replacement = $this->copyGeneration($epg, $directory);
            $this->writeChanges($replacement, $prevalidatedChanges);
        } catch (Throwable) {
            $this->discardGeneration($replacement);

            return ['status' => 'invalid_patch'];
        }

        try {
            $result = $this->generations->mutate($epg, fn (): array => $this->publishReplacement($context, $epg, $snapshot, $prevalidatedChanges, $replacement));
        } catch (Throwable) {
            $result = ['status' => 'invalid_patch'];
        }

        if ($result['status'] !== 'applied') {
            $this->discardGeneration($replacement);

            return $result;
        }

        $this->clearPlaylistCaches($epg);

        return $result;
    }

    /**
     * Re-check every precondition inside the mutation lock, then publish.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array{programme: array<string, mixed>, expected_revision: string}>  $prevalidatedChanges
     * @return array{status: string}
     */
    private function publishReplacement(PluginExecutionContext $context, Epg $epg, array $snapshot, array $prevalidatedChanges, string $replacement): array
    {
        if ($denial = $this->authorizationDenial($context, $epg)) {
            return ['status' => $denial];
        }

        $source = $this->generations->resolve($epg);
        if (! $this->isImmutableGeneration($epg, $source)) {
            return ['status' => 'legacy_cache_read_only'];
        }
        if (basename($source) !== ($snapshot['generation'] ?? null)) {
            return ['status' => 'stale_snapshot'];
        }
        if (! $this->hasExpectedRevisions($source, $prevalidatedChanges)) {
            return ['status' => 'conflict'];
        }

        $this->generations->publishWithinMutationLock($epg, $replacement);

        return ['status' => 'applied'];
    }

    /**
     * Best effort: the enrichment write already published successfully, so a
     * stale playlist cache file must not be reported as a failure.
     */
    private function clearPlaylistCaches(Epg $epg): void
    {
        foreach ($epg->getAllPlaylists() as $playlist) {
            try {
                EpgCacheService::clearPlaylistEpgCacheFile($playlist);
            } catch (Throwable) {
                continue;
            }
        }
    }

    private function authorizationDenial(PluginExecutionContext $context, Epg $epg): ?string
    {
        $plugin = $context->plugin->fresh();
        if (! $plugin || ! $plugin->enabled || ! $plugin->available || ! $plugin->isInstalled()) {
            return 'plugin_not_enabled';
        }
        if (! $plugin->isTrusted() || ! $plugin->hasVerifiedIntegrity() || $plugin->validation_status !== 'valid') {
            return 'plugin_not_trusted';
        }
        if (! in_array('epg_cache_enrichment', $plugin->capabilities ?? [], true)) {
            return 'capability_denied';
        }
        if (! $context->user || (! $context->user->isAdmin() && $context->user->id !== $epg->user_id)) {
            return 'ownership_denied';
        }

        return null;
    }

    /**
     * Validate the requested page, returning the resolved window or the error
     * status to report to the caller.
     *
     * @param  array{limit?: int, cursor?: string}  $selection
     * @return array{limit: int, after: int}|string
     */
    private function resolvePageWindow(array $selection): array|string
    {
        $limit = $selection['limit'] ?? self::MAX_PAGE_SIZE;
        if (! is_int($limit) || $limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            return 'invalid_selection';
        }

        $after = $this->decodeCursor($selection['cursor'] ?? null);
        if ($after === null) {
            return 'invalid_cursor';
        }

        return ['limit' => $limit, 'after' => $after];
    }

    /** @param list<array{rowid: int, programme: array<string, mixed>, revision: string}> $rows */
    private function nextCursor(array $rows, bool $hasMore): ?string
    {
        if (! $hasMore || $rows === []) {
            return null;
        }

        return $this->encodeCursor((int) $rows[array_key_last($rows)]['rowid']);
    }

    /** @return list<array{rowid: int, programme: array<string, mixed>, revision: string}> */
    private function readRows(string $directory, ?int $after, int $limit): array
    {
        $store = EpgProgrammeStore::openRead(Storage::disk('local')->path($directory.'/programmes.sqlite'));
        try {
            $raw = $store->readPage($after ?? 0, $limit);
        } finally {
            $store->close();
        }

        return array_map($this->hydrateRawRow(...), $raw);
    }

    /**
     * Fetch specific rows by rowid in one connection/query, keyed by rowid.
     *
     * @param  list<int>  $rowids
     * @return array<int, array{rowid: int, programme: array<string, mixed>, revision: string}>
     */
    private function readRowsByIds(string $directory, array $rowids): array
    {
        if ($rowids === []) {
            return [];
        }

        $store = EpgProgrammeStore::openRead(Storage::disk('local')->path($directory.'/programmes.sqlite'));
        try {
            $raw = $store->readRowsByIds($rowids);
        } finally {
            $store->close();
        }

        return array_map($this->hydrateRawRow(...), $raw);
    }

    /**
     * @param  array{rowid: int, channel_id: string, start_ts: int, stop_ts: ?int, data: string}  $row
     * @return array{rowid: int, programme: array<string, mixed>, revision: string}
     */
    private function hydrateRawRow(array $row): array
    {
        $programme = EpgProgrammeStore::hydrate(json_decode($row['data'], true) ?: [], $row['channel_id'], $row['start_ts'], $row['stop_ts']);

        return ['rowid' => $row['rowid'], 'programme' => $programme, 'revision' => $this->revision($programme)];
    }

    /**
     * @param  array{rowid: int, programme: array<string, mixed>, revision: string}  $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        return [
            'locator' => 'programme:'.base64_encode((string) $row['rowid']),
            'row_revision' => $row['revision'],
            'programme' => $row['programme'],
        ];
    }

    /** @param list<array{rowid: int, programme: array<string, mixed>, revision: string}> $rows */
    private function encryptToken(PluginExecutionContext $context, Epg $epg, string $generation, array $rows): string
    {
        $revisionsByRowid = [];
        foreach ($rows as $row) {
            $revisionsByRowid[(string) $row['rowid']] = $row['revision'];
        }

        return Crypt::encryptString(json_encode([
            'epg_id' => $epg->id,
            'plugin_id' => $context->plugin->id,
            'generation' => $generation,
            'rows' => $revisionsByRowid,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null */
    private function decryptToken(string $token): ?array
    {
        try {
            $value = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);

            return is_array($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function copyGeneration(Epg $epg, string $source): string
    {
        $target = $this->generations->createGenerationDirectory($epg);
        $disk = Storage::disk('local');
        foreach ($this->generations->generationFiles() as $file) {
            if (! $disk->copy("{$source}/{$file}", "{$target}/{$file}")) {
                throw new RuntimeException('Failed to copy EPG cache generation.');
            }
        }

        return $target;
    }

    private function discardGeneration(?string $directory): void
    {
        if ($directory !== null) {
            Storage::disk('local')->deleteDirectory($directory);
        }
    }

    /**
     * Validate every patch against the snapshot revisions and the generation on
     * disk, returning only the rows whose programme actually changes. Null means
     * the patch set must be rejected as a conflict.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<array<string, mixed>>  $patches
     * @return array<int, array{programme: array<string, mixed>, expected_revision: string}>|null
     */
    private function prevalidatedChanges(string $directory, array $snapshot, array $patches): ?array
    {
        $expected = $snapshot['rows'] ?? [];
        if (! is_array($expected)) {
            return null;
        }

        $pending = [];
        foreach ($patches as $patch) {
            $rowid = $this->decodeLocator($patch['locator'] ?? null);
            if ($rowid === null || isset($pending[$rowid])) {
                return null;
            }

            $expectedRevision = $expected[(string) $rowid] ?? null;
            if ($expectedRevision === null || ($patch['row_revision'] ?? null) !== $expectedRevision) {
                return null;
            }

            if (! is_array($patch['changes'] ?? null)) {
                return null;
            }
            $patchChanges = $this->validatePatch($patch['changes']);
            if ($patchChanges === null) {
                return null;
            }

            $pending[$rowid] = ['changes' => $patchChanges, 'expected_revision' => $expectedRevision];
        }

        $current = $this->readRowsByIds($directory, array_keys($pending));
        $changes = [];
        foreach ($pending as $rowid => $patch) {
            $row = $current[$rowid] ?? null;
            if ($row === null || $row['revision'] !== $patch['expected_revision']) {
                return null;
            }

            $programme = array_replace($row['programme'], $patch['changes']);
            if ($programme !== $row['programme']) {
                $changes[$rowid] = ['programme' => $programme, 'expected_revision' => $patch['expected_revision']];
            }
        }

        return $changes;
    }

    /** @param array<int, array{programme: array<string, mixed>, expected_revision: string}> $prevalidatedChanges */
    private function hasExpectedRevisions(string $directory, array $prevalidatedChanges): bool
    {
        $current = $this->readRowsByIds($directory, array_keys($prevalidatedChanges));
        foreach ($prevalidatedChanges as $rowid => $change) {
            if (! isset($current[$rowid]) || $current[$rowid]['revision'] !== $change['expected_revision']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>|null
     */
    protected function validatePatch(array $changes): ?array
    {
        if ($changes === [] || array_diff(array_keys($changes), self::MUTABLE_FIELDS) !== []) {
            return null;
        }

        foreach ($changes as $field => $value) {
            $valid = match (true) {
                in_array($field, self::BOOLEAN_FIELDS, true) => is_bool($value),
                in_array($field, self::STRING_FIELDS, true) => is_string($value),
                $field === 'production_year' => is_int($value) || $value === null,
                $field === 'icon' => is_string($value) && ($value === '' || $this->validUrl($value)),
                $field === 'urls' => $this->validUrls($value),
                $field === 'images' => $this->validImages($value),
                $field === 'episode_nums' => is_array($value),
                default => true,
            };

            if (! $valid) {
                return null;
            }
        }

        return $changes;
    }

    private function validUrls(mixed $urls): bool
    {
        if (! is_array($urls)) {
            return false;
        }

        foreach ($urls as $url) {
            if (! is_array($url) || array_diff(array_keys($url), ['system', 'value']) !== []) {
                return false;
            }
            if (! is_string($url['system'] ?? null) || ! $this->validUrl($url['value'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function validImages(mixed $images): bool
    {
        if (! is_array($images)) {
            return false;
        }

        foreach ($images as $image) {
            if (! is_array($image) || array_diff(array_keys($image), self::IMAGE_FIELDS) !== []) {
                return false;
            }
            if (! $this->validUrl($image['url'] ?? null)) {
                return false;
            }
            if (! in_array($image['type'] ?? null, self::IMAGE_TYPES, true) || ! in_array($image['orient'] ?? null, self::IMAGE_ORIENTATIONS, true)) {
                return false;
            }
            if (! is_int($image['width'] ?? null) || ! is_int($image['height'] ?? null) || ! is_int($image['size'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function validUrl(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false && $this->urlAllowed($url);
    }

    /** Reuse the same allowed-domain policy every other URL-accepting surface in the app enforces. */
    private function urlAllowed(string $url): bool
    {
        $denied = false;
        app(UrlIsAllowed::class)->validate('url', $url, function () use (&$denied): void {
            $denied = true;
        });

        return ! $denied;
    }

    /** @param array<int, array{programme: array<string, mixed>, expected_revision: string}> $changes */
    private function writeChanges(string $directory, array $changes): void
    {
        $dataByRowid = [];
        foreach ($changes as $rowid => $change) {
            $dataByRowid[$rowid] = json_encode(EpgProgrammeStore::dehydrate($change['programme']), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $store = EpgProgrammeStore::openRead(Storage::disk('local')->path($directory.'/programmes.sqlite'));
        try {
            $store->updateRows($dataByRowid);
        } finally {
            $store->close();
        }
    }

    private function isImmutableGeneration(Epg $epg, string $directory): bool
    {
        return $this->generations->isGenerationDirectory($epg, $directory);
    }

    /** @param array<string, mixed> $programme */
    private function revision(array $programme): string
    {
        return hash('sha256', json_encode($programme, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function encodeCursor(int $rowid): string
    {
        return Crypt::encryptString((string) $rowid);
    }

    private function decodeCursor(mixed $cursor): ?int
    {
        if ($cursor === null) {
            return 0;
        }

        try {
            $value = Crypt::decryptString($cursor);

            return ctype_digit($value) ? (int) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function decodeLocator(mixed $locator): ?int
    {
        if (! is_string($locator) || ! str_starts_with($locator, 'programme:')) {
            return null;
        }

        $value = base64_decode(substr($locator, 10), true);

        return $value !== false && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Read-only snapshot of a pre-generation (flat v2 or v1 JSONL) cache.
     *
     * @param  array{limit: int, after: int}  $window
     * @return array<string, mixed>
     */
    private function snapshotLegacy(PluginExecutionContext $context, Epg $epg, string $directory, array $window): array
    {
        $rows = Storage::disk('local')->exists($directory.'/programmes.sqlite')
            ? $this->readRows($directory, $window['after'], $window['limit'] + 1)
            : $this->readLegacyJsonlRows($directory, $window['after'], $window['limit'] + 1);
        $hasMore = count($rows) > $window['limit'];
        $rows = array_slice($rows, 0, $window['limit']);

        return [
            'status' => 'ok',
            'generation' => self::LEGACY_GENERATION,
            'cache_revision' => self::LEGACY_GENERATION,
            'token' => $this->encryptToken($context, $epg, self::LEGACY_GENERATION, []),
            'programmes' => array_map($this->publicRow(...), $rows),
            'next_cursor' => $this->nextCursor($rows, $hasMore),
        ];
    }

    /** @return list<array{rowid: int, programme: array<string, mixed>, revision: string}> */
    private function readLegacyJsonlRows(string $directory, int $after, int $limit): array
    {
        $disk = Storage::disk('local');
        $paths = array_values(array_filter($disk->files($directory), fn (string $path): bool => (bool) preg_match('/\/programmes-\d{4}-\d{2}-\d{2}\.jsonl$/', $path)));
        sort($paths, SORT_STRING);

        $rowid = 0;
        $rows = [];
        foreach ($paths as $path) {
            $handle = fopen($disk->path($path), 'r');
            if ($handle === false) {
                continue;
            }

            try {
                while (($line = fgets($handle)) !== false) {
                    $record = json_decode(trim($line), true);
                    if (! is_array($record) || ! is_string($record['channel'] ?? null) || ! is_array($record['programme'] ?? null)) {
                        continue;
                    }

                    $rowid++;
                    if ($rowid <= $after) {
                        continue;
                    }

                    $programme = $record['programme'];
                    $programme['channel'] ??= $record['channel'];
                    $rows[] = ['rowid' => $rowid, 'programme' => $programme, 'revision' => $this->revision($programme)];
                    if (count($rows) >= $limit) {
                        return $rows;
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        return $rows;
    }
}
