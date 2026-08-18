<?php

use App\Filament\Resources\CustomPlaylists\RelationManagers\ChannelsRelationManager;
use App\Jobs\AddItemsToCustomPlaylist;
use App\Jobs\DetachItemsFromCustomPlaylist;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Spatie\Tags\Tag;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->customPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);
    $this->actingAs($this->user);
});

it('can display channels grouped by custom tags in filament table', function () {
    // Create channels with different custom groups
    $sportsChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'ESPN',
        'is_vod' => false,
    ]);

    $newsChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'CNN',
        'is_vod' => false,
    ]);

    $uncategorizedChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'Random Channel',
        'is_vod' => false,
    ]);

    // Create tags for different groups
    $sportsTag = Tag::create([
        'name' => ['en' => 'Sports'],
        'type' => $this->customPlaylist->uuid,
    ]);

    $newsTag = Tag::create([
        'name' => ['en' => 'News'],
        'type' => $this->customPlaylist->uuid,
    ]);

    // Attach tags to channels
    $sportsChannel->attachTag($sportsTag);
    $newsChannel->attachTag($newsTag);
    // Leave uncategorizedChannel without tags

    // Attach channels to the custom playlist
    $this->customPlaylist->channels()->attach([$sportsChannel->id, $newsChannel->id, $uncategorizedChannel->id]);

    // Test the relation manager
    $relationManager = Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $this->customPlaylist,
        'pageClass' => 'App\\Filament\\Resources\\CustomPlaylistResource\\Pages\\EditCustomPlaylist',
    ]);

    // Check that the table contains all channels
    $relationManager
        ->assertCanSeeTableRecords([$sportsChannel, $newsChannel, $uncategorizedChannel]);

    // Test that grouping works by verifying the group names are computed correctly
    expect($sportsChannel->getCustomGroupName($this->customPlaylist->uuid))->toBe('Sports');
    expect($newsChannel->getCustomGroupName($this->customPlaylist->uuid))->toBe('News');
    expect($uncategorizedChannel->getCustomGroupName($this->customPlaylist->uuid))->toBe('Uncategorized');
});

it('shows the source epg for selected epg channels in custom playlists', function () {
    $epg = Epg::factory()->for($this->user)->create([
        'name' => 'Jessmann XML',
    ]);

    $epgChannel = EpgChannel::factory()->for($this->user)->for($epg)->create([
        'name' => 'BBC One EPG',
    ]);

    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'BBC One',
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
    ]);

    $this->customPlaylist->channels()->attach($channel->id);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $this->customPlaylist,
        'pageClass' => 'App\\Filament\\Resources\\CustomPlaylistResource\\Pages\\EditCustomPlaylist',
    ])
        ->loadTable()
        ->assertTableColumnExists('epgChannel.epg.name')
        ->assertSee('Jessmann XML');
});

it('dispatches DetachItemsFromCustomPlaylist with the selected record IDs when the detach bulk action is called', function () {
    Bus::fake();

    $channelA = Channel::factory()->create(['user_id' => $this->user->id, 'is_vod' => false]);
    $channelB = Channel::factory()->create(['user_id' => $this->user->id, 'is_vod' => false]);
    $this->customPlaylist->channels()->attach([$channelA->id, $channelB->id]);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $this->customPlaylist,
        'pageClass' => 'App\\Filament\\Resources\\CustomPlaylistResource\\Pages\\EditCustomPlaylist',
    ])->callTableBulkAction('detach', [$channelA, $channelB]);

    Bus::assertDispatched(DetachItemsFromCustomPlaylist::class, function (DetachItemsFromCustomPlaylist $job) use ($channelA, $channelB) {
        return $job->customPlaylistId === $this->customPlaylist->id
            && $job->type === 'channel'
            && collect($job->itemIds)->sort()->values()->all() === collect([$channelA->id, $channelB->id])->sort()->values()->all();
    });
});

it('dispatches AddItemsToCustomPlaylist with the selected record IDs when the add_to_group bulk action is called', function () {
    Bus::fake();

    $tag = Tag::create(['name' => ['en' => 'Sports'], 'type' => $this->customPlaylist->uuid]);
    $this->customPlaylist->attachTag($tag);

    $channel = Channel::factory()->create(['user_id' => $this->user->id, 'is_vod' => false]);
    $this->customPlaylist->channels()->attach($channel->id);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $this->customPlaylist,
        'pageClass' => 'App\\Filament\\Resources\\CustomPlaylistResource\\Pages\\EditCustomPlaylist',
    ])->callTableBulkAction('add_to_group', [$channel], ['group' => 'Sports']);

    Bus::assertDispatched(AddItemsToCustomPlaylist::class, function (AddItemsToCustomPlaylist $job) use ($channel) {
        return $job->customPlaylistId === $this->customPlaylist->id
            && $job->itemIds === [$channel->id]
            && $job->data['category'] === 'Sports';
    });
});
