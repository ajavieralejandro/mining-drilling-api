<?php

namespace Tests\Feature;

use App\Enums\RoleInHole;
use App\Models\DrillHole;
use App\Models\User;
use Tests\TestCase;

class DrillHoleAssignmentTest extends TestCase
{
    public function test_supervisor_can_assign_personnel(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('supervisor@app.test');

        $hole = DrillHole::where('hole_id', 'RLEK-0525')->firstOrFail();
        $geologist = User::where('email', 'geologist@app.test')->firstOrFail();

        $response = $this->postJson("/api/drill-holes/{$hole->id}/assignments", [
            'user_id' => $geologist->id,
            'role_in_hole' => RoleInHole::Geologist->value,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user_id', $geologist->id);
    }

    public function test_driller_cannot_assign_personnel(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('driller@app.test');

        $hole = DrillHole::where('hole_id', 'RLEK-0523')->firstOrFail();
        $helper = User::where('email', 'helper@app.test')->firstOrFail();

        $response = $this->postJson("/api/drill-holes/{$hole->id}/assignments", [
            'user_id' => $helper->id,
            'role_in_hole' => RoleInHole::Helper->value,
        ]);

        $response->assertForbidden();
    }
}
