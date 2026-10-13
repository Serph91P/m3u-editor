<?php

namespace App\Services;

use App\Models\EmbyLibraryMapping;
use App\Models\User;

class ManagedCacheControlService
{
    public function resolveActiveMapping(
        User $user,
        int $integrationId,
        string $mappingUuid,
        string $revision,
    ): ?EmbyLibraryMapping {
        return EmbyLibraryMapping::query()
            ->whereBelongsTo($user)
            ->where('media_server_integration_id', $integrationId)
            ->where('uuid', $mappingUuid)
            ->where('enabled', true)
            ->where('is_managed', true)
            ->where('last_applied_revision', $revision)
            ->whereHas('integration', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('type', 'emby')
                ->where('enabled', true))
            ->first();
    }

    /**
     * @return array{canonical_id: string, variant_key: string, item: array<string, mixed>, variant: array<string, mixed>}|null
     */
    public function resolveCatalogTarget(
        EmbyLibraryMapping $mapping,
        string $canonicalId,
        string $variantKey,
    ): ?array {
        $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($mapping);

        foreach ($this->catalogItems($catalog['items'] ?? []) as $item) {
            if (($item['canonical_id'] ?? null) !== $canonicalId) {
                continue;
            }

            foreach ($item['variants'] ?? [] as $variant) {
                if (($variant['key'] ?? null) === $variantKey) {
                    return [
                        'canonical_id' => $canonicalId,
                        'variant_key' => $variantKey,
                        'item' => $item,
                        'variant' => $variant,
                    ];
                }
            }

            return null;
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return iterable<array<string, mixed>>
     */
    private function catalogItems(array $items): iterable
    {
        foreach ($items as $item) {
            yield $item;

            foreach ($item['episodes'] ?? [] as $episode) {
                yield $episode;
            }
        }
    }
}
