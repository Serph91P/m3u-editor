<?php

namespace App\Policies;

use App\Models\EmbyLibraryMapping;
use App\Models\User;

class EmbyLibraryMappingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canUseIntegrations();
    }

    public function view(User $user, EmbyLibraryMapping $embyLibraryMapping): bool
    {
        return $this->canManage($user, $embyLibraryMapping);
    }

    public function create(User $user): bool
    {
        return $user->canUseIntegrations();
    }

    public function update(User $user, EmbyLibraryMapping $embyLibraryMapping): bool
    {
        return $this->canManage($user, $embyLibraryMapping);
    }

    public function delete(User $user, EmbyLibraryMapping $embyLibraryMapping): bool
    {
        return $this->canManage($user, $embyLibraryMapping);
    }

    public function restore(User $user, EmbyLibraryMapping $embyLibraryMapping): bool
    {
        return $this->canManage($user, $embyLibraryMapping);
    }

    public function forceDelete(User $user, EmbyLibraryMapping $embyLibraryMapping): bool
    {
        return $this->canManage($user, $embyLibraryMapping);
    }

    /**
     * Admins may act on any mapping; everyone else only on their own.
     */
    private function canManage(User $user, EmbyLibraryMapping $embyLibraryMapping): bool
    {
        return $user->canUseIntegrations()
            && ($user->isAdmin() || $user->id === $embyLibraryMapping->user_id);
    }
}
