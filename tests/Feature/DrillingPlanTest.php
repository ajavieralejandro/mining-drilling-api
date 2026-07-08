<?php

namespace Tests\Feature;

use Tests\TestCase;

class DrillingPlanTest extends TestCase
{
    public function test_admin_sees_all_plans(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('admin@app.test');

        $response = $this->getJson('/api/drilling-plans');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_driller_sees_only_plans_with_assigned_holes(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('driller@app.test');

        $response = $this->getJson('/api/drilling-plans');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertStringContainsString('Infill', $response->json('data.0.name'));
    }
}
