<?php

namespace App\Filament\Resources\MediaServerIntegrations\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

/**
 * Not a real Eloquent relation manager — AIOStreams has nothing synced to list.
 * This reuses Filament's relation-manager tab mechanism purely to embed the
 * AioStreamsBrowse Livewire component inline on the edit page, in the same tab
 * strip as the Movies/Series relation managers show for other integration types,
 * instead of linking out to a separate page.
 *
 * render() bypasses the table entirely, but Livewire still boots every trait's
 * booted{Trait} hook regardless — InteractsWithRelationshipTable::bootedInteractsWithTable()
 * unconditionally builds a full table config, and several of its derived label
 * lookups (getModelLabel(), etc.) chain into getRelationshipName(), which throws
 * without a real $relationship/related resource. makeTable() is overridden below
 * to skip that chain and return a bare, never-rendered Table instance instead.
 */
class AioStreamsRelationManager extends RelationManager
{
    protected static ?string $title = 'Browse Catalog';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-play';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === 'aiostreams';
    }

    protected function makeTable(): Table
    {
        return Table::make($this);
    }

    public function render(): View
    {
        return view('filament.resources.media-server-integrations.relation-managers.aiostreams-relation-manager');
    }
}
