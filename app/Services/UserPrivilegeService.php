<?php

namespace App\Services;

use App\Models\User;

// Trusted internal code only. There are deliberately no HTTP routes to this service.
class UserPrivilegeService
{
    public function assign(User $user, bool $isAdmin, bool $isLoyaltyMember): User
    {
        $user->forceFill(['is_admin' => $isAdmin, 'is_loyalty_member' => $isLoyaltyMember])->save();

        return $user->refresh();
    }
}
