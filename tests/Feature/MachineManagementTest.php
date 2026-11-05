<?php

namespace Tests\Feature;

use App\Enums\MachineStatus;
use App\Enums\MachineType;
use App\Models\Machine;
use Tests\TestCase;

class MachineManagementTest extends TestCase
{
    public function test_authenticated_user_can_list_machines(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('admin@app.test');

        $response = $this->getJson('/api/machines');

        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'code', 'type', 'status', 'notes']]]);
    }

    public function test_authenticated_user_can_create_machine(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('admin@app.test');

        $response = $this->postJson('/api/machines', [
            'code' => 'MQ-NEW-01',
            'type' => 'superficie',
            'status' => 'activa',
            'notes' => 'Perforadora de superficie para Cerro Negro',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'MQ-NEW-01')
            ->assertJsonPath('data.type', MachineType::Surface->value)
            ->assertJsonPath('data.status', MachineStatus::Active->value);

        $this->assertDatabaseHas('machines', [
            'code' => 'MQ-NEW-01',
            'type' => MachineType::Surface->value,
            'status' => MachineStatus::Active->value,
        ]);
    }

    public function test_authenticated_user_can_update_machine(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('admin@app.test');

        $machine = Machine::firstOrFail();

        $response = $this->patchJson("/api/machines/{$machine->id}", [
            'status' => 'mantenimiento',
            'notes' => 'En mantenimiento preventivo',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', MachineStatus::Maintenance->value)
            ->assertJsonPath('data.notes', 'En mantenimiento preventivo');

        $this->assertDatabaseHas('machines', [
            'id' => $machine->id,
            'status' => MachineStatus::Maintenance->value,
            'notes' => 'En mantenimiento preventivo',
        ]);
    }

    public function test_machine_code_must_be_unique(): void
    {
        $this->seedMiningData();
        $this->actingAsRole('admin@app.test');

        $existing = Machine::firstOrFail();

        $response = $this->postJson('/api/machines', [
            'code' => $existing->code,
            'type' => 'underground',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }
}
