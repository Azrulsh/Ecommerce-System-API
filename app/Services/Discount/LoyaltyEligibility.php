<?php

namespace App\Services\Discount;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class LoyaltyEligibility
{
    public function eligible(?Authenticatable $customer): bool
    {
        // Read authoritative stored membership; never trust in-memory/request attributes.
        return $customer instanceof User && $customer->exists
            && User::query()->whereKey($customer->id)->where('is_loyalty_member', true)->exists();
    }
}
