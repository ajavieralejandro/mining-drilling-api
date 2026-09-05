<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->ensureEphemeralAppKey();

        parent::setUp();
    }

    /**
     * PHPUnit must not version a real APP_KEY. If CI or the shell already
     * provides one, honor it; otherwise generate an ephemeral key for this
     * process so Laravel can boot without a secret in Git.
     */
    private function ensureEphemeralAppKey(): void
    {
        $existing = $_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '';

        if (is_string($existing) && $existing !== '') {
            return;
        }

        $key = 'base64:'.base64_encode(random_bytes(32));
        putenv('APP_KEY='.$key);
        $_ENV['APP_KEY'] = $key;
        $_SERVER['APP_KEY'] = $key;
    }

    protected function actingAsRole(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function seedMiningData(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }
}
