<?php

namespace App\Filament\Widgets;

use App\Providers\VersionServiceProvider;
use Filament\Widgets\Widget;

class M3uTvWidget extends Widget
{
    protected string $view = 'filament.widgets.m3u-tv-widget';

    public string $latestVersion = '';

    public function mount(): void
    {
        $this->latestVersion = VersionServiceProvider::getRemoteTvVersion();
    }
}
