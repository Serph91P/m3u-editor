<x-filament-panels::page>
    @if ($this->integration)
        <livewire:aio-streams-browse
            :integration-id="$this->integration->id"
            :guest-mode="true"
            :playlist-auth-id="$this->playlistAuthId"
        />
    @endif
</x-filament-panels::page>
