<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserPrivilegeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AuthenticatesCustomers;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use AuthenticatesCustomers, DatabaseTransactions;

    private function registration(array $changes = []): array
    {
        return array_replace(['name' => 'Test Customer', 'email' => 'customer@example.test',
            'password' => 'Synthetic-Pass123', 'password_confirmation' => 'Synthetic-Pass123'], $changes);
    }

    public function test_registration_hashes_password_issues_token_and_has_no_privileges(): void
    {
        $response = $this->postJson('/api/register', $this->registration(['email' => 'CUSTOMER@example.test']))
            ->assertCreated()->assertJsonPath('data.user.email', 'customer@example.test')
            ->assertJsonPath('data.user.is_loyalty_member', false)->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token')->assertJsonMissingPath('data.user.is_admin');
        $user = User::where('email', 'customer@example.test')->sole();
        $this->assertTrue(Hash::check('Synthetic-Pass123', $user->password));
        $this->assertFalse($user->is_admin);
        $this->assertFalse($user->is_loyalty_member);
        $this->assertNotSame($response->json('data.token'), $user->tokens()->sole()->token);
        $this->assertNotNull($user->tokens()->sole()->expires_at);
        $this->withToken($response->json('data.token'))->getJson('/api/user')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public static function invalidRegistrations(): array
    {
        return [[['name' => ''], 'name'], [['email' => 'invalid'], 'email'], [['password' => 'short'], 'password'],
            [['password_confirmation' => 'mismatch'], 'password'], [['is_admin' => true], 'is_admin'],
            [['is_loyalty_member' => true], 'is_loyalty_member'],
            [['password' => 'A1'.str_repeat('é', 36), 'password_confirmation' => 'A1'.str_repeat('é', 36)], 'password']];
    }

    #[DataProvider('invalidRegistrations')]
    public function test_invalid_registration_and_privilege_escalation(array $changes, string $field): void
    {
        $this->postJson('/api/register', $this->registration($changes))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_duplicate_email_is_rejected_case_insensitively(): void
    {
        User::factory()->create(['email' => 'customer@example.test']);
        $this->postJson('/api/register', $this->registration(['email' => 'CUSTOMER@example.test']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_profile_and_logout_only_revoke_current_token(): void
    {
        $user = User::factory()->create(['email' => 'customer@example.test', 'password' => 'Synthetic-Pass123']);
        $other = $user->createToken('other-device')->plainTextToken;
        $login = $this->postJson('/api/login', ['email' => 'CUSTOMER@example.test', 'password' => 'Synthetic-Pass123'])
            ->assertOk()->assertJsonMissingPath('data.user.password');
        $token = $login->json('data.token');
        $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->postJson('/api/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->assertSame(1, $user->tokens()->count());
        $this->withToken($other);
        Auth::forgetGuards();
        $this->getJson('/api/user')->assertOk();
    }

    public function test_invalid_credentials_have_generic_validation_response(): void
    {
        User::factory()->create(['email' => 'customer@example.test']);
        foreach (['customer@example.test', 'unknown@example.test'] as $email) {
            $this->postJson('/api/login', ['email' => $email, 'password' => 'wrong'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->postJson('/api/login', ['email' => ['invalid'], 'password' => 'wrong'])->assertUnprocessable();
    }

    public function test_login_and_registration_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->postJson('/api/login', ['email' => ' UNKNOWN@example.test ', 'password' => 'wrong'])->assertTooManyRequests();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/register', [])->assertUnprocessable();
        }
        $this->postJson('/api/register', [])->assertTooManyRequests();
    }

    public function test_expired_and_invalid_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('expired', ['*'], now()->subSecond())->plainTextToken;
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken('invalid-token')->getJson('/api/user')->assertUnauthorized();
    }

    public function test_privileges_cannot_be_mass_assigned_and_internal_service_can_assign_them(): void
    {
        $user = User::factory()->create();
        $user->fill(['is_admin' => true, 'is_loyalty_member' => true])->save();
        $this->assertFalse($user->fresh()->is_admin);
        $this->assertFalse($user->fresh()->is_loyalty_member);
        $updated = app(UserPrivilegeService::class)->assign($user, true, true);
        $this->assertTrue($updated->is_admin);
        $this->assertTrue($updated->is_loyalty_member);
    }
}
