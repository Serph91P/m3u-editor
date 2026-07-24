<?php

namespace App\Filament\Resources\PushDeviceTokens\Pages;

use App\Filament\Resources\PushDeviceTokens\PushDeviceTokenResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListPushDeviceTokens extends ListRecords
{
    protected static string $resource = PushDeviceTokenResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Mobile devices registered to receive push notifications through the relay. Devices are added automatically when the app registers for push, and pruned automatically after :days days without a check-in.', ['days' => config('services.push_relay.stale_days', 60)]);
    }

    public function getHeaderActions(): array
    {
        return [];
    }
}
