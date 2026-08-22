<?php

namespace Tests\Feature\Iam;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\ErpTestCase;

/**
 * The API token may travel in X-Api-Token as well as Authorization: Bearer.
 *
 * A deployment can sit behind an HTTP-level gate — Basic auth on a demo host, an
 * authenticating reverse proxy — and those own the Authorization header. A
 * browser that has satisfied a Basic challenge attaches its credential
 * automatically, but a fetch() that sets Authorization REPLACES it rather than
 * accompanying it. The gate then rejects the call, and the SPA reads that 401 as
 * an expired session and logs the user out on the first request it makes.
 *
 * This was not theoretical: it is exactly what happened when erp1.pi2.co.id was
 * first put behind a password gate — the shell loaded and every API call 401'd.
 */
class ApiTokenHeaderTest extends ErpTestCase
{
    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_token_in_the_x_api_token_header_authenticates(): void
    {
        $user = $this->adminUser();

        $this->withHeader('X-Api-Token', $this->tokenFor($user))
            ->getJson('/api/iam/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_the_standard_bearer_header_still_authenticates(): void
    {
        $user = $this->adminUser();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/iam/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    /**
     * The point of the change: a gate credential can occupy Authorization while
     * the app's own token rides alongside it, and BOTH are honoured.
     */
    public function test_the_app_token_works_while_authorization_carries_a_gate_credential(): void
    {
        $user = $this->adminUser();

        $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode('demo:whatever'),
            'X-Api-Token' => $this->tokenFor($user),
        ])
            ->getJson('/api/iam/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_a_bogus_x_api_token_is_still_rejected(): void
    {
        $this->withHeader('X-Api-Token', 'not-a-real-token')
            ->getJson('/api/iam/auth/me')
            ->assertUnauthorized();
    }

    /**
     * An empty X-Api-Token must fall through to Authorization rather than
     * shadowing it — otherwise a proxy that always sets the header blank would
     * break bearer clients.
     */
    public function test_an_empty_x_api_token_falls_back_to_the_bearer_header(): void
    {
        $user = $this->adminUser();

        $this->withHeaders([
            'X-Api-Token' => '',
            'Authorization' => 'Bearer '.$this->tokenFor($user),
        ])
            ->getJson('/api/iam/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_no_credential_at_all_is_unauthorized(): void
    {
        $this->getJson('/api/iam/auth/me')->assertUnauthorized();

        Sanctum::actingAs($this->adminUser());
        $this->getJson('/api/iam/auth/me')->assertOk();
    }
}
