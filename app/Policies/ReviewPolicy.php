<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ReviewPolicy
{
    public function update(User $user, Review $review): Response
    {
        return $review->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, Review $review): Response
    {
        return $user->is_admin || $review->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
