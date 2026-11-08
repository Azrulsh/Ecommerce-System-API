<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait AuthenticatesCustomers
{
    private function asCustomer(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $this->withToken($user->createToken('test-client')->plainTextToken);
        Auth::forgetGuards();

        return $user;
    }

    private function forgetAuthentication(): void
    {
        $this->flushHeaders();
        Auth::forgetGuards();
    }
}
