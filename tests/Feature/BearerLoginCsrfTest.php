<?php

namespace Tests\Feature;

use App\Http\Middleware\ExcludePublicSiteFromSanctumState;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

class BearerLoginCsrfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sanctum.stateful' => [
                'https://undsurf.com',
                'www.undsurf.com',
                'localhost:8081',
            ],
        ]);

        $this->seedMiningData();
    }

    public function test_public_site_origins_receive_a_token_without_a_csrf_cookie(): void
    {
        foreach ([
            ['https://undsurf.com', 'https://undsurf.com/acceso'],
            ['https://www.undsurf.com', 'https://www.undsurf.com/acceso'],
        ] as [$origin, $referer]) {
            $this->withHeaders([
                'Origin' => $origin,
                'Referer' => $referer,
            ])->postJson('/api/auth/login', [
                'email' => 'admin@app.test',
                'password' => 'password',
            ])->assertOk()
                ->assertJsonStructure(['token', 'user' => ['email']]);
        }
    }

    public function test_invalid_credentials_from_the_public_site_are_not_a_csrf_failure(): void
    {
        $this->withHeaders([
            'Origin' => 'https://undsurf.com',
            'Referer' => 'https://undsurf.com/acceso',
        ])->postJson('/api/auth/login', [
            'email' => 'admin@app.test',
            'password' => 'wrong-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_without_an_origin_still_issues_a_token(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@app.test',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_protected_route_without_a_token_stays_unauthenticated(): void
    {
        $this->withHeaders([
            'Origin' => 'https://undsurf.com',
            'Referer' => 'https://undsurf.com/pozos',
        ])->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_logout_rejects_the_previous_token(): void
    {
        $user = User::query()->where('email', 'admin@app.test')->firstOrFail();
        $issued = $user->createToken('api-token');

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withToken($issued->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@app.test');

        $this->withToken($issued->plainTextToken)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $issued->accessToken->id,
        ]);

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withToken($issued->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_public_site_is_not_a_cookie_frontend_and_other_stateful_domains_remain(): void
    {
        $this->getJson('/api/health')->assertOk();

        $stateful = config('sanctum.stateful');
        $this->assertNotContains('undsurf.com', $stateful);
        $this->assertNotContains('https://undsurf.com', $stateful);
        $this->assertNotContains('www.undsurf.com', $stateful);
        $this->assertContains('localhost:8081', $stateful);

        $site = Request::create('https://api.undsurf.com/api/auth/login', 'POST');
        $site->headers->set('Referer', 'https://undsurf.com/acceso');
        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($site));

        $www = Request::create('https://api.undsurf.com/api/auth/login', 'POST');
        $www->headers->set('Origin', 'https://www.undsurf.com');
        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($www));

        $expo = Request::create('https://api.undsurf.com/api/auth/login', 'POST');
        $expo->headers->set('Referer', 'http://localhost:8081/');
        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($expo));
    }

    public function test_session_routes_keep_csrf_and_the_api_keeps_sanctum_state(): void
    {
        $groups = app('router')->getMiddlewareGroups();

        $this->assertContains(ValidateCsrfToken::class, $groups['web']);
        $this->assertContains(EnsureFrontendRequestsAreStateful::class, $groups['api']);
        $this->assertContains(ExcludePublicSiteFromSanctumState::class, $groups['api']);
    }
}
