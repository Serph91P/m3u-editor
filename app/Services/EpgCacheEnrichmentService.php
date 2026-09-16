<?php

namespace App\Services;

use App\Models\Epg;
use App\Plugins\Support\PluginExecutionContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use PDO;
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

    /** @var list<string> */
    private const MUTABLE_FIELDS = [
        'title', 'subtitle', 'desc', 'category', 'episode_num', 'episode_nums',
        'rating', 'icon', 'images', 'new', 'previously_shown', 'premiere',
        'urls', 'production_year',
    ];

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

        $directory = $this->generations->resolve($epg);
        if (! $this->isImmutableGeneration($epg, $directory)) {
            return $this->snapshotLegacy($context, $epg, $directory, $selection);
        }

        $limit = $selection['limit'] ?? self::MAX_PAGE_SIZE;
        if (! is_int($limit) || $limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            return ['status' => 'invalid_selection'];
        }
        $cursor = $selection['cursor'] ?? null;
        $after = $this->decodeCursor($cursor);
        if ($cursor !== null && $after === null) {
            return ['status' => 'invalid_cursor'];
        }

        $rows = $this->readRows($directory, $after, $limit + 1);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $programmes = array_map(fn (array $row): array => $this->publicRow($row), $rows);

        return [
            'status' => 'ok',
            'generation' => basename($directory),
            'cache_revision' => hash('sha256', basename($directory)),
            'token' => $this->encryptToken($context, $epg, basename($directory), $rows),
            'programmes' => $programmes,
            'next_cursor' => $hasMore ? $this->encodeCursor((int) $rows[array_key_last($rows)]['rowid']) : null,
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

        try {
            return $this->generations->mutate($epg, function () use ($context, $epg, $snapshot, $patches): array {
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

                $changes = $this->validatedChanges($source, $snapshot, $patches);
                if ($changes === null) {
                    return ['status' => 'conflict'];
                }
                if ($changes === []) {
                    return ['status' => 'noop'];
                }

                $replacement = $this->copyGeneration($epg, $source);
                $this->writeChanges($replacement, $changes);
                $this->generations->publishWithinMutationLock($epg, $replacement);
                $epg->getAllPlaylists()->each(fn ($playlist): bool => EpgCacheService::clearPlaylistEpgCacheFile($playlist));

                return ['status' => 'applied'];
            });
        } catch (Throwable) {
            return ['status' => 'invalid_patch'];
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

    /** @return list<array{rowid: int, programme: array<string, mixed>, revision: string}> */
    private function readRows(string $directory, ?int $after, int $limit): array
    {
        $pdo = new PDO('sqlite:'.Storage::disk('local')->path($directory.'/programmes.sqlite'));
        $statement = $pdo->prepare('SELECT rowid, channel_id, start_ts, stop_ts, data FROM programmes WHERE rowid > ? ORDER BY rowid LIMIT ?');
        $statement->execute([$after ?? 0, $limit]);
        $rows = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $programme = EpgProgrammeStore::hydrate(
                json_decode($row['data'], true) ?: [],
                $row['channel_id'],
                (int) $row['start_ts'],
                $row['stop_ts'] === null ? null : (int) $row['stop_ts'],
            );
            $rows[] = ['rowid' => (int) $row['rowid'], 'programme' => $programme, 'revision' => $this->revision($programme)];
        }

        return $rows;
    }

    /** @param array{rowid: int, programme: array<string, mixed>, revision: string} $row */
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
        return Crypt::encryptString(json_encode([
            'epg_id' => $epg->id,
            'plugin_id' => $context->plugin->id,
            'generation' => $generation,
            'rows' => collect($rows)->mapWithKeys(fn (array $row): array => [(string) $row['rowid'] => $row['revision']])->all(),
        ], JSON_THROW_ON_ERROR));
    }

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
        foreach (['metadata.json', 'channels.json', 'programmes.sqlite'] as $file) {
            if (! $disk->copy("{$source}/{$file}", "{$target}/{$file}")) {
                throw new \RuntimeException('Failed to copy EPG cache generation.');
            }
        }

        return $target;
    }

    /** @param array<string, mixed> $snapshot @param list<array<string, mixed>> $patches @return array<int, array{programme: array<string,mixed>, changes: array<string,mixed>}>|null */
    private function validatedChanges(string $directory, array $snapshot, array $patches): ?array
    {
        $expected = $snapshot['rows'] ?? [];
        if (! is_array($expected)) {
            return null;
        }
        $changes = [];
        $seen = [];
        foreach ($patches as $patch) {
            $rowid = $this->decodeLocator($patch['locator'] ?? null);
            if ($rowid === null || isset($seen[$rowid]) || ! isset($expected[(string) $rowid]) || ($patch['row_revision'] ?? null) !== $expected[(string) $rowid] || ! is_array($patch['changes'] ?? null)) {
                return null;
            }
            $seen[$rowid] = true;
            $rows = $this->readExactRow($directory, $rowid);
            if (count($rows) !== 1 || $rows[0]['revision'] !== $expected[(string) $rowid]) {
                return null;
            }
            $patchChanges = $this->validatePatch($patch['changes']);
            if ($patchChanges === null) {
                return null;
            }
            $programme = array_replace($rows[0]['programme'], $patchChanges);
            if ($programme !== $rows[0]['programme']) {
                $changes[$rowid] = ['programme' => $programme, 'changes' => $patchChanges];
            }
        }

        return $changes;
    }

    /** @return list<array{rowid: int, programme: array<string, mixed>, revision: string}> */
    private function readExactRow(string $directory, int $rowid): array
    {
        return array_values(array_filter($this->readRows($directory, $rowid - 1, 1), fn (array $row): bool => $row['rowid'] === $rowid));
    }

    /** @param array<string, mixed> $changes @return array<string, mixed>|null */
    private function validatePatch(array $changes): ?array
    {
        if ($changes === [] || array_diff(array_keys($changes), self::MUTABLE_FIELDS) !== []) {
            return null;
        }
        foreach ($changes as $field => $value) {
            if (in_array($field, ['new', 'previously_shown', 'premiere'], true) && ! is_bool($value)) {
                return null;
            }
            if (in_array($field, ['production_year'], true) && ! (is_int($value) || $value === null)) {
                return null;
            }
            if (in_array($field, ['title', 'subtitle', 'desc', 'category', 'episode_num', 'rating', 'icon'], true) && ! is_string($value)) {
                return null;
            }
            if ($field === 'icon' && $value !== '' && ! filter_var($value, FILTER_VALIDATE_URL)) {
                return null;
            }
            if ($field === 'urls' && ! $this->validUrls($value)) {
                return null;
            }
            if ($field === 'images' && ! $this->validImages($value)) {
                return null;
            }
            if ($field === 'episode_nums' && ! is_array($value)) {
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
            if (! is_array($url) || array_diff(array_keys($url), ['system', 'value']) !== [] || ! is_string($url['system'] ?? null) || ! is_string($url['value'] ?? null) || ! filter_var($url['value'], FILTER_VALIDATE_URL)) {
                return false;
            }
        }

        return is_array($urls);
    }

    private function validImages(mixed $images): bool
    {
        if (! is_array($images)) {
            return false;
        }
        $roles = ['poster', 'banner', 'fanart', 'logo'];
        foreach ($images as $image) {
            if (! is_array($image) || array_diff(array_keys($image), ['url', 'type', 'width', 'height', 'orient', 'size']) !== [] || ! is_string($image['url'] ?? null) || ! filter_var($image['url'], FILTER_VALIDATE_URL) || ! in_array($image['type'] ?? null, $roles, true) || ! is_int($image['width'] ?? null) || ! is_int($image['height'] ?? null) || ! in_array($image['orient'] ?? null, ['P', 'L'], true) || ! is_int($image['size'] ?? null)) {
                return false;
            }
        }

        return is_array($images);
    }

    /** @param array<int, array{programme: array<string, mixed>, changes: array<string, mixed>}> $changes */
    private function writeChanges(string $directory, array $changes): void
    {
        $pdo = new PDO('sqlite:'.Storage::disk('local')->path($directory.'/programmes.sqlite'));
        $pdo->beginTransaction();
        $statement = $pdo->prepare('UPDATE programmes SET data = ? WHERE rowid = ?');
        foreach ($changes as $rowid => $change) {
            $data = json_encode(EpgProgrammeStore::dehydrate($change['programme']), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $statement->execute([$data, $rowid]);
        }
        $pdo->commit();
    }

    private function isImmutableGeneration(Epg $epg, string $directory): bool
    {
        return (bool) preg_match('#^epg-cache/'.preg_quote($epg->uuid, '#').'/v2/generations/[a-f0-9]{32}$#', $directory);
    }

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

    /** @return array<string, mixed> */
    private function snapshotLegacy(PluginExecutionContext $context, Epg $epg, string $directory, array $selection): array
    {
        $limit = $selection['limit'] ?? self::MAX_PAGE_SIZE;
        if (! is_int($limit) || $limit < 1 || $limit > self::MAX_PAGE_SIZE || ! Storage::disk('local')->exists($directory.'/programmes.sqlite')) {
            return ['status' => 'invalid_selection'];
        }
        $rows = $this->readRows($directory, 0, $limit);

        return [
            'status' => 'ok',
            'generation' => 'legacy',
            'cache_revision' => 'legacy',
            'token' => Crypt::encryptString(json_encode(['epg_id' => $epg->id, 'plugin_id' => $context->plugin->id, 'generation' => 'legacy', 'rows' => []], JSON_THROW_ON_ERROR)),
            'programmes' => array_map(fn (array $row): array => $this->publicRow($row), $rows),
            'next_cursor' => null,
        ];
    }
}
