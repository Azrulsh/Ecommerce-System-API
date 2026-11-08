<?php

namespace App\Services\Discount;

use Illuminate\Contracts\Auth\Authenticatable;

class LoyaltyEligibility
{
    public function eligible(?Authenticatable $customer): bool
    {
        // Fail closed until Phase 3 supplies authenticated, server-managed eligibility.
        return false;
    }
}
