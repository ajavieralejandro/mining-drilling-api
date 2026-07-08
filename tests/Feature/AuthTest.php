<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_login_ok(): void
    {
        $this->seedMiningData();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@app.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'role']])
            ->assertJsonPath('user.email', 'admin@app.test');
    }

    public function test_me_ok(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('geologist@app.test');

        $response = $this->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.email', 'geologist@app.test')
            ->assertJsonPath('data.role', 'geologist');
    }
}
