<?php

namespace Tests\Feature;

use App\Models\DrillHole;
use Tests\TestCase;

class DrillHoleProgressTest extends TestCase
{
    public function test_assigned_driller_can_log_progress(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('driller@app.test');

        $hole = DrillHole::where('hole_id', 'RLEK-0523')->firstOrFail();

        $response = $this->postJson("/api/drill-holes/{$hole->id}/progress", [
            'depth_from' => 12.5,
            'depth_to' => 15.0,
            'depth_current' => 15.0,
            'shift' => 'B',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.depth_current', '15.00');
    }

    public function test_helper_cannot_log_progress(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('helper@app.test');

        $hole = DrillHole::where('hole_id', 'RLEK-0523')->firstOrFail();

        $response = $this->postJson("/api/drill-holes/{$hole->id}/progress", [
            'depth_from' => 12.5,
            'depth_to' => 15.0,
            'depth_current' => 15.0,
        ]);

        $response->assertForbidden();
    }

    public function test_progress_updates_current_depth(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('driller@app.test');

        $hole = DrillHole::where('hole_id', 'RLEK-0523')->firstOrFail();

        $this->postJson("/api/drill-holes/{$hole->id}/progress", [
            'depth_from' => 12.5,
            'depth_to' => 18.0,
            'depth_current' => 18.0,
        ])->assertCreated();

        $hole->refresh();
        $this->assertEquals('18.00', $hole->current_depth);
    }
}
