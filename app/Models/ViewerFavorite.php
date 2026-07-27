<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViewerFavorite extends Model
{
    protected $table = 'viewer_favorites';

    protected $casts = [
        'stream_id' => 'integer',
        'favorited_at' => 'datetime',
    ];

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(PlaylistViewer::class, 'playlist_viewer_id');
    }

    /**
     * Get the Channel associated with this favorite (live/vod).
     * stream_id in viewer_favorites = Channel.id
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'stream_id');
    }

    /**
     * Get the Series associated with this favorite (series content_type).
     * stream_id in viewer_favorites = Series.id
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class, 'stream_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('content_type', 'live');
    }

    public function scopeVod(Builder $query): Builder
    {
        return $query->where('content_type', 'vod');
    }

    public function scopeSeries(Builder $query): Builder
    {
        return $query->where('content_type', 'series');
    }

    public function scopeAiostreams(Builder $query): Builder
    {
        return $query->where('content_type', 'aiostreams');
    }
}
