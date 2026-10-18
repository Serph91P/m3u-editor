<?php

namespace App\Filament\Actions;

use App\Filament\Resources\MergedPlaylists\MergedPlaylistResource;
use App\Models\Playlist;
use App\Services\MediaSourceMatchService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class CreateMergedWithLocalMediaAction
{
    public static function make(): Action
    {
        return Action::make('create-merged-with-local-media')
            ->label(__('Create Merged Playlist with Local Media'))
            ->icon('heroicon-o-rectangle-stack')
            ->requiresConfirmation()
            ->modalHeading(__('Create Merged Playlist with Local Media'))
            ->modalDescription(__('Creates a new merged playlist containing this playlist plus your media server libraries. Local items this playlist already matches are not listed twice. Assign your auths to the new playlist to start using it; this playlist and its auths are not changed.'))
            ->modalSubmitActionLabel(__('Create'))
            ->visible(fn (Playlist $record): bool => $record->prefer_media_server_sources
                && ! $record->is_network_playlist
                && ! $record->isMediaServerPlaylist())
            ->action(function (Playlist $record): void {
                $service = app(MediaSourceMatchService::class);

                $existing = $service->findMergedWithLocalMedia($record);
                if ($existing !== null) {
                    Notification::make()
                        ->info()
                        ->title(__('Merged playlist already exists'))
                        ->body(__('":name" already merges this playlist with your media server libraries.', ['name' => $existing->name]))
                        ->actions([
                            Action::make('open')
                                ->label(__('Open'))
                                ->url(MergedPlaylistResource::getUrl('edit', ['record' => $existing])),
                        ])
                        ->send();

                    return;
                }

                $merged = $service->createMergedWithLocalMedia($record);

                if ($merged === null) {
                    Notification::make()
                        ->warning()
                        ->title(__('No media server integrations available'))
                        ->body(__('Enable an Emby, Jellyfin, Plex, or local media integration that has synced a playlist, then try again.'))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('Merged playlist created'))
                    ->body(__('":name" was created. Assign auths to it to start using it.', ['name' => $merged->name]))
                    ->actions([
                        Action::make('open')
                            ->label(__('Open'))
                            ->url(MergedPlaylistResource::getUrl('edit', ['record' => $merged])),
                    ])
                    ->send();
            });
    }
}
