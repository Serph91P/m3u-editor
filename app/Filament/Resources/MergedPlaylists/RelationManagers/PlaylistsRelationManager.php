<?php

namespace App\Filament\Resources\MergedPlaylists\RelationManagers;

use App\Enums\Status;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlaylistsRelationManager extends RelationManager
{
    protected static string $relationship = 'playlists';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount([
                    'enabled_live_channels',
                    'enabled_vod_channels',
                    'enabled_series',
                    'groups',
                    'live_channels',
                    'vod_channels',
                    'series',
                ]);
            })
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('groups_count')
                    ->label(__('Groups'))
                    ->sortable(),
                TextColumn::make('live_channels_count')
                    ->label(__('Live'))
                    ->state(function (Playlist $record): int {
                        if (! $record->pivot->include_live) {
                            return 0;
                        }

                        return $record->is_network_playlist ? $record->networks()->count() : $record->live_channels_count;
                    })
                    ->description(function (Playlist $record): string {
                        if (! $record->pivot->include_live) {
                            return __('Excluded from merge');
                        }

                        return $record->is_network_playlist ? 'Enabled: '.($record->networks()->get()->filter(fn ($n) => $n->isBroadcasting())->count()) : "Enabled: {$record->enabled_live_channels_count}";
                    })
                    ->sortable(),
                TextColumn::make('vod_channels_count')
                    ->label(__('VOD'))
                    ->state(fn (Playlist $record): int => $record->pivot->include_vod ? $record->vod_channels_count : 0)
                    ->description(fn (Playlist $record): string => $record->pivot->include_vod ? "Enabled: {$record->enabled_vod_channels_count}" : __('Excluded from merge'))
                    ->sortable(),
                TextColumn::make('series_count')
                    ->label(__('Series'))
                    ->state(fn (Playlist $record): int => $record->pivot->include_series ? $record->series_count : 0)
                    ->description(fn (Playlist $record): string => $record->pivot->include_series ? "Enabled: {$record->enabled_series_count}" : __('Excluded from merge'))
                    ->sortable(),
                TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(fn (Status $state) => $state->getColor()),
                TextColumn::make('synced')
                    ->label(__('Last Synced'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name'])
                    ->recordSelectOptionsQuery(
                        fn (Builder $query, $livewire) => $query
                            ->select(['id', 'name'])
                            ->where('user_id', $livewire->ownerRecord->user_id)
                            ->orderBy('name')
                    )
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        ...self::contentTypeToggles(),
                    ]),
            ])
            ->recordActions([
                DetachAction::make()
                    ->icon('heroicon-o-x-circle')
                    ->button()
                    ->hiddenLabel(),
                Action::make('contentTypes')
                    ->label(__('Content Types'))
                    ->icon('heroicon-m-pencil-square')
                    ->button()
                    ->hiddenLabel()
                    ->tooltip(__('Edit content types'))
                    ->fillForm(fn (Playlist $record): array => [
                        'include_live' => $record->pivot->include_live,
                        'include_vod' => $record->pivot->include_vod,
                        'include_series' => $record->pivot->include_series,
                    ])
                    ->modalWidth('md')
                    ->modalHeading(fn (Playlist $record): string => __('Edit content types for :name', ['name' => $record->name]))
                    ->schema(self::contentTypeToggles())
                    ->action(function (array $data, Playlist $record) {
                        /** @var MergedPlaylist $ownerRecord */
                        $ownerRecord = $this->getOwnerRecord();
                        $ownerRecord->playlists()->updateExistingPivot($record->id, $data);
                    }),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()->color('warning'),
                ]),
            ]);
    }

    /**
     * Pivot toggles for which content types a source playlist contributes to the merge.
     *
     * @return array<int, Toggle>
     */
    protected static function contentTypeToggles(): array
    {
        return [
            Toggle::make('include_live')
                ->label(__('Include Live Channels'))
                ->default(true),
            Toggle::make('include_vod')
                ->label(__('Include VOD'))
                ->default(true),
            Toggle::make('include_series')
                ->label(__('Include Series'))
                ->default(true),
        ];
    }
}
