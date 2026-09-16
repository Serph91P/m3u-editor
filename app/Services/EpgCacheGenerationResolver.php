<?php

namespace App\Services;

use App\Models\Epg;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Resolves and publishes immutable EPG cache generations beneath the v2 root.
 *
 * A generation is complete only when its metadata and SQLite programme store
 * exist. Readers resolve the active generation once and continue using that
 * immutable directory for the remainder of their operation.
 */
class EpgCacheGenerationResolver
{
    private const CACHE_VERSION = 'v2';

    private const GENERATIONS_DIRECTORY = 'generations';

    private const ACTIVE_POINTER_FILE = 'active-generation.json';

    private const METADATA_FILE = 'metadata.json';

    private const PROGRAMMES_DB_FILE = 'programmes.sqlite';

    private const CHANNELS_FILE = 'channels.json';

    private const PREVIOUS_CACHE_VERSIONS = ['v1'];

    /**
     * How long a superseded generation is kept before pruning. Must comfortably
     * exceed the longest a reader can hold an already-resolved generation open,
     * since {@see resolve()} hands out a directory path that a caller may not
     * finish reading from until well after a newer generation is published.
     */
    private const GENERATION_RETENTION_SECONDS = 3600;

    /**
     * Resolve the active complete generation, or the legacy v2 flat directory
     * when no usable pointer exists.
     */
    public function resolve(Epg $epg): string
    {
        $cacheDirectory = $this->cacheDirectory($epg);
        $pointerPath = $cacheDirectory.'/'.self::ACTIVE_POINTER_FILE;

        if (! Storage::disk('local')->exists($pointerPath)) {
            return $this->legacyFallbackDirectory($epg);
        }

        try {
            $pointer = json_decode(Storage::disk('local')->get($pointerPath), true, flags: JSON_THROW_ON_ERROR);
            $generation = is_array($pointer) ? $pointer['generation'] ?? null : null;
            if (! is_string($generation) || ! preg_match('/^[a-f0-9]{32}$/', $generation)) {
                return $this->legacyFallbackDirectory($epg);
            }

            $generationDirectory = $this->generationDirectory($epg, $generation);

            return $this->isComplete($generationDirectory) ? $generationDirectory : $this->legacyFallbackDirectory($epg);
        } catch (\Throwable) {
            return $this->legacyFallbackDirectory($epg);
        }
    }

    /**
     * Allocate a new immutable generation directory below the EPG's v2 root.
     */
    public function createGenerationDirectory(Epg $epg): string
    {
        do {
            $generation = bin2hex(random_bytes(16));
            $directory = $this->generationDirectory($epg, $generation);
        } while (Storage::disk('local')->exists($directory));

        Storage::disk('local')->makeDirectory($directory);
        Storage::disk('local')->put($directory.'/'.self::CHANNELS_FILE, '{}');

        return $directory;
    }

    /**
     * Atomically make a complete generation visible to newly-started readers.
     * Existing readers retain their already-resolved immutable directory.
     */
    public function publish(Epg $epg, string $generationDirectory): void
    {
        $generation = $this->generationName($epg, $generationDirectory);
        if (! $this->isComplete($generationDirectory)) {
            throw new RuntimeException("Cannot publish incomplete EPG cache generation {$generationDirectory}");
        }

        $this->mutate($epg, function () use ($epg, $generation): void {
            $this->publishGeneration($epg, $generation);
        });
        $this->pruneStaleGenerations($epg, $generationDirectory);
    }

    /**
     * Run a short mutation that observes and publishes one EPG generation.
     * Callers must do all remote work before entering this critical section.
     */
    public function mutate(Epg $epg, \Closure $callback): mixed
    {
        return Cache::lock($this->mutationLockName($epg), 30)->block(10, $callback);
    }

    /**
     * Publish a complete generation while {@see mutate()} already holds the lock.
     */
    public function publishWithinMutationLock(Epg $epg, string $generationDirectory): void
    {
        $generation = $this->generationName($epg, $generationDirectory);
        if (! $this->isComplete($generationDirectory)) {
            throw new RuntimeException("Cannot publish incomplete EPG cache generation {$generationDirectory}");
        }

        $this->publishGeneration($epg, $generation);
        $this->pruneStaleGenerations($epg, $generationDirectory);
    }

    /**
     * Delete generation directories other than the active one, once they are
     * old enough that no reader could plausibly still be using them. Best
     * effort: any deletion failure (already gone, concurrent pruning by
     * another process) is ignored rather than surfaced.
     */
    private function pruneStaleGenerations(Epg $epg, string $activeDirectory): void
    {
        try {
            $disk = Storage::disk('local');
            $generationsRoot = $this->cacheDirectory($epg).'/'.self::GENERATIONS_DIRECTORY;
            if (! $disk->exists($generationsRoot)) {
                return;
            }

            $cutoff = now()->subSeconds(self::GENERATION_RETENTION_SECONDS)->getTimestamp();
            foreach ($disk->directories($generationsRoot) as $directory) {
                if ($directory === $activeDirectory) {
                    continue;
                }
                try {
                    $modifiedAt = $disk->lastModified($directory);
                } catch (\Throwable) {
                    continue;
                }
                if ($modifiedAt < $cutoff) {
                    $disk->deleteDirectory($directory);
                }
            }
        } catch (\Throwable) {
            // Best effort: pruning must never fail the publish it runs after.
        }
    }

    /**
     * Whether `$directory` is an immutable generation directory belonging to
     * `$epg`, i.e. something {@see resolve()} could hand back as the active
     * generation. Callers must use this rather than re-deriving the shape
     * themselves, so it keeps tracking {@see CACHE_VERSION} automatically.
     */
    public function isGenerationDirectory(Epg $epg, string $directory): bool
    {
        $prefix = $this->cacheDirectory($epg).'/'.self::GENERATIONS_DIRECTORY.'/';

        return str_starts_with($directory, $prefix) && preg_match('/^[a-f0-9]{32}$/', substr($directory, strlen($prefix))) === 1;
    }

    /** @return list<string> Filenames that make up one generation directory. */
    public function generationFiles(): array
    {
        return [self::METADATA_FILE, self::CHANNELS_FILE, self::PROGRAMMES_DB_FILE];
    }

    private function publishGeneration(Epg $epg, string $generation): void
    {
        $cacheDirectory = $this->cacheDirectory($epg);
        $cachePath = Storage::disk('local')->path($cacheDirectory);
        if (! is_dir($cachePath) && ! mkdir($cachePath, 0755, true) && ! is_dir($cachePath)) {
            throw new RuntimeException("Failed to create EPG cache directory {$cachePath}");
        }

        $pointerPath = $cachePath.'/'.self::ACTIVE_POINTER_FILE;
        $temporaryPointerPath = $pointerPath.'.building-'.bin2hex(random_bytes(8));
        $pointer = json_encode(['generation' => $generation], JSON_THROW_ON_ERROR);

        if (file_put_contents($temporaryPointerPath, $pointer, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write EPG cache pointer {$temporaryPointerPath}");
        }

        if (! @rename($temporaryPointerPath, $pointerPath)) {
            @unlink($temporaryPointerPath);

            throw new RuntimeException("Failed to publish EPG cache pointer {$pointerPath}");
        }
    }

    public function mutationLockName(Epg $epg): string
    {
        return 'epg-cache-generation-publish:'.$epg->uuid;
    }

    private function cacheDirectory(Epg $epg): string
    {
        return "epg-cache/{$epg->uuid}/".self::CACHE_VERSION;
    }

    private function generationDirectory(Epg $epg, string $generation): string
    {
        return $this->cacheDirectory($epg).'/'.self::GENERATIONS_DIRECTORY.'/'.$generation;
    }

    private function generationName(Epg $epg, string $generationDirectory): string
    {
        $prefix = $this->cacheDirectory($epg).'/'.self::GENERATIONS_DIRECTORY.'/';
        if (! str_starts_with($generationDirectory, $prefix)) {
            throw new RuntimeException("Invalid EPG cache generation directory {$generationDirectory}");
        }

        $generation = substr($generationDirectory, strlen($prefix));
        if (! preg_match('/^[a-f0-9]{32}$/', $generation)) {
            throw new RuntimeException("Invalid EPG cache generation {$generation}");
        }

        return $generation;
    }

    private function isComplete(string $generationDirectory): bool
    {
        $disk = Storage::disk('local');
        $metadataPath = $generationDirectory.'/'.self::METADATA_FILE;

        if (! $disk->exists($metadataPath)
            || ! $disk->exists($generationDirectory.'/'.self::CHANNELS_FILE)
            || ! $disk->exists($generationDirectory.'/'.self::PROGRAMMES_DB_FILE)) {
            return false;
        }

        try {
            if (! is_array(json_decode($disk->get($metadataPath), true, flags: JSON_THROW_ON_ERROR))
                || ! is_array(json_decode($disk->get($generationDirectory.'/'.self::CHANNELS_FILE), true, flags: JSON_THROW_ON_ERROR))) {
                return false;
            }

            $store = EpgProgrammeStore::openRead($disk->path($generationDirectory.'/'.self::PROGRAMMES_DB_FILE));
            try {
                if (! $store->quickCheck()) {
                    return false;
                }
            } finally {
                $store->close();
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function legacyFallbackDirectory(Epg $epg): string
    {
        $cacheDirectory = $this->cacheDirectory($epg);
        if (Storage::disk('local')->exists($cacheDirectory.'/'.self::METADATA_FILE)) {
            return $cacheDirectory;
        }

        foreach (self::PREVIOUS_CACHE_VERSIONS as $version) {
            $legacyDirectory = "epg-cache/{$epg->uuid}/{$version}";
            if (Storage::disk('local')->exists($legacyDirectory.'/'.self::METADATA_FILE)) {
                return $legacyDirectory;
            }
        }

        return $cacheDirectory;
    }
}
