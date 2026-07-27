<?php

namespace App\Events;

use App\Models\PlaylistViewer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the TV app's playlist channel whenever a viewer favorites or
 * unfavorites an item via toggle_favorite, so other devices signed into the
 * same viewer (owner or child profile) update immediately instead of waiting
 * for their next get_favorites pull.
 *
 * Reuses the same `tv.{type}.{uuid}[.{playlistAuthId}]` channel scheme as
 * TvNotificationEvent/DvrRecordingStatusEvent — a viewer's channel is the
 * owner's base channel when it has no playlist_auth_id (admin/owner-auth
 * viewer), or its own per-child-profile channel otherwise. The TV app is
 * already subscribed to exactly one of these after login.
 */
class ViewerFavoriteEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $notifiableType,
        public readonly string $notifiableUuid,
        public readonly ?int $playlistAuthId,
        public readonly string $viewerUlid,
        public readonly string $contentType,
        public readonly ?int $streamId,
        public readonly ?string $aioItemId,
        public readonly bool $favorited,
        public readonly ?string $title = null,
        public readonly ?string $thumbnailUrl = null,
        public readonly ?string $itemType = null,
        public readonly ?int $aioIntegrationId = null,
    ) {}

    /**
     * $aioMetadata (title, thumbnail_url, item_type, aio_integration_id) is only
     * meaningful — and only sent by the caller — when $contentType is
     * 'aiostreams' and $favorited is true. The client needs it to reconstruct
     * the favorite locally without a live re-fetch from the addon.
     *
     * @param  array{title?: ?string, thumbnail_url?: ?string, item_type?: ?string, aio_integration_id?: ?int}  $aioMetadata
     */
    public static function build(
        Model $playlist,
        PlaylistViewer $viewer,
        string $contentType,
        ?int $streamId,
        ?string $aioItemId,
        bool $favorited,
        array $aioMetadata = [],
    ): self {
        return new self(
            notifiableType: $playlist->getMorphClass(),
            notifiableUuid: $playlist->uuid,
            playlistAuthId: $viewer->playlist_auth_id,
            viewerUlid: $viewer->ulid,
            contentType: $contentType,
            streamId: $streamId,
            aioItemId: $aioItemId,
            favorited: $favorited,
            title: $aioMetadata['title'] ?? null,
            thumbnailUrl: $aioMetadata['thumbnail_url'] ?? null,
            itemType: $aioMetadata['item_type'] ?? null,
            aioIntegrationId: $aioMetadata['aio_integration_id'] ?? null,
        );
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $suffix = $this->playlistAuthId === null ? '' : ".{$this->playlistAuthId}";

        return [new PrivateChannel("tv.{$this->notifiableType}.{$this->notifiableUuid}{$suffix}")];
    }

    public function broadcastAs(): string
    {
        return 'favorite.toggled';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'viewer_id' => $this->viewerUlid,
            'content_type' => $this->contentType,
            'stream_id' => $this->streamId,
            'aio_item_id' => $this->aioItemId,
            'favorited' => $this->favorited,
            'title' => $this->title,
            'thumbnail_url' => $this->thumbnailUrl,
            'item_type' => $this->itemType,
            'aio_integration_id' => $this->aioIntegrationId,
        ];
    }
}
