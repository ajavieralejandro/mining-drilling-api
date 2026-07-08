<?php

namespace Tests\Feature;

use App\Models\DrillHole;
use Tests\TestCase;

class DrillHoleTest extends TestCase
{
    public function test_geologist_can_edit_technical_data(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('geologist@app.test');

        $hole = DrillHole::where('hole_id', 'RLEK-0523')->firstOrFail();

        $response = $this->patchJson("/api/drill-holes/{$hole->id}/technical-data", [
            'azimuth' => 185.5,
            'dip' => -80.0,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.azimuth', '185.50')
            ->assertJsonPath('data.dip', '-80.00');
    }

    public function test_driller_cannot_edit_technical_data(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('driller@app.test');

        $hole = DrillHole::where('hole_id', 'RLEK-0523')->firstOrFail();

        $response = $this->patchJson("/api/drill-holes/{$hole->id}/technical-data", [
            'azimuth' => 185.5,
        ]);

        $response->assertForbidden();
    }

    public function test_assigned_driller_can_view_hole(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('driller@app.test');

        $hole = DrillHole::where('hole_id', 'RLEK-0523')->firstOrFail();

        $response = $this->getJson("/api/drill-holes/{$hole->id}");

        $response->assertOk()
            ->assertJsonPath('data.hole_id', 'RLEK-0523');
    }

    public function test_assigned_helper_can_view_hole(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('helper@app.test');

        $hole = DrillHole::where('hole_id', 'RLEK-0523')->firstOrFail();

        $response = $this->getJson("/api/drill-holes/{$hole->id}");

        $response->assertOk()
            ->assertJsonPath('data.hole_id', 'RLEK-0523');
    }
}
