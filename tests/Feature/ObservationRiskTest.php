<?php

namespace Tests\Feature;

use App\Enums\RiskLevel;
use App\Models\DrillHole;
use App\Models\Observation;
use Tests\TestCase;

class ObservationRiskTest extends TestCase
{
    public function test_assigned_helper_can_report_risk(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('helper@app.test');

        $hole = DrillHole::where('hole_id', 'RLEK-0523')->firstOrFail();

        $response = $this->postJson("/api/drill-holes/{$hole->id}/risks", [
            'body' => 'Falla estructural detectada.',
            'risk_level' => RiskLevel::High->value,
            'depth_detected' => 11.0,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'risk')
            ->assertJsonPath('data.risk_level', 'high');
    }

    public function test_risk_appears_in_risks_index(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('admin@app.test');

        $response = $this->getJson('/api/risks');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
        $this->assertTrue(
            collect($response->json('data'))->contains(fn ($risk) => $risk['risk_level'] === 'critical')
        );
    }

    public function test_close_risk_changes_risk_status(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('supervisor@app.test');

        $risk = Observation::where('type', 'risk')->firstOrFail();

        $response = $this->patchJson("/api/observations/{$risk->id}/close-risk", [
            'risk_status' => 'closed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.risk_status', 'closed');

        $this->assertNotNull($response->json('data.closed_at'));
    }
}
