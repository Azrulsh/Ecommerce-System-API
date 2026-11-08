<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function create(User $user): bool
    {
        return $user->is_admin === true;
    }

    public function update(User $user, Product $product): bool
    {
        return $user->is_admin === true;
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->is_admin === true;
    }
}
