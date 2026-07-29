<?php

namespace App\Livewire;

use App\Models\MediaServerIntegration;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

/**
 * A single AIOStreams catalog section. Renders its section/heading immediately
 * (cheap — no HTTP), but defers the catalog fetch itself until the browser reports
 * (via Alpine's x-intersect) that the row has scrolled into view, so pages with many
 * catalogs don't pay for every catalog's round trip up front.
 */
class AioStreamsCatalogRow extends Component
{
    public int $integrationId;

    public string $catalogId;

    public string $catalogType;

    public string $catalogName;

    /** @var array<int, array<string, mixed>> */
    public array $items = [];

    public bool $loaded = false;

    public bool $loadFailed = false;

    public function mount(int $integrationId, string $catalogId, string $catalogType, string $catalogName): void
    {
        $this->integrationId = $integrationId;
        $this->catalogId = $catalogId;
        $this->catalogType = $catalogType;
        $this->catalogName = $catalogName;
    }

    /**
     * Triggered client-side once the row scrolls into view. Guarded so repeated
     * intersection events (e.g. scrolling past and back) don't refetch.
     */
    public function loadIfVisible(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        $cacheKey = "aiostreams_row_{$this->integrationId}_{$this->catalogId}_{$this->catalogType}";
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            $this->items = $cached;

            return;
        }

        $integration = MediaServerIntegration::query()
            ->where('id', $this->integrationId)
            ->where('type', 'aiostreams')
            ->first();

        if (! $integration || ! $integration->manifest_base_url) {
            $this->loadFailed = true;

            return;
        }

        try {
            $response = Http::timeout(15)
                ->get("{$integration->manifest_base_url}/catalog/{$this->catalogType}/{$this->catalogId}.json");
            $items = $response->successful()
                ? array_slice($response->json()['metas'] ?? [], 0, 20)
                : [];
        } catch (\Exception) {
            $this->loadFailed = true;

            return;
        }

        Cache::put($cacheKey, $items, now()->addMinutes(5));
        $this->items = $items;
    }

    public function render(): View
    {
        return view('livewire.aio-streams-catalog-row');
    }
}
