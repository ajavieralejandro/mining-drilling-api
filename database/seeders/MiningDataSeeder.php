<?php

namespace Database\Seeders;

use App\Enums\HoleStatus;
use App\Enums\HoleType;
use App\Enums\MachineStatus;
use App\Enums\MachineType;
use App\Enums\ObservationType;
use App\Enums\PlanFileType;
use App\Enums\PlanPurpose;
use App\Enums\PlanStatus;
use App\Enums\PlatformStatus;
use App\Enums\RiskLevel;
use App\Enums\RiskStatus;
use App\Enums\RoleInHole;
use App\Models\DrillHole;
use App\Models\DrillHoleAssignment;
use App\Models\DrillingPlan;
use App\Models\DrillingPlatform;
use App\Models\Machine;
use App\Models\MachineAvailability;
use App\Models\Observation;
use App\Models\PlanFile;
use App\Models\User;
use Illuminate\Database\Seeder;

class MiningDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@app.test')->first();
        $supervisor = User::where('email', 'supervisor@app.test')->first();
        $geologist = User::where('email', 'geologist@app.test')->first();
        $driller = User::where('email', 'driller@app.test')->first();
        $helper = User::where('email', 'helper@app.test')->first();

        $infillPlan = DrillingPlan::create([
            'name' => 'Plan Infill Drilling - Nivel 450 - Mina Mariana Norte',
            'mine' => 'Mina Mariana Norte',
            'level' => '450',
            'sector' => 'Norte',
            'purpose' => PlanPurpose::Infill,
            'planned_meters' => 1250.50,
            'executed_meters' => 420.75,
            'status' => PlanStatus::InProgress,
            'description' => 'Plan de infill para nivel 450.',
            'created_by' => $admin?->id,
        ]);

        $ventilationPlan = DrillingPlan::create([
            'name' => 'Plan Ventilación - Nivel 450 - Mina Mariana Norte',
            'mine' => 'Mina Mariana Norte',
            'level' => '450',
            'sector' => 'Ventilación',
            'purpose' => PlanPurpose::Ventilation,
            'planned_meters' => 800.00,
            'executed_meters' => 120.00,
            'status' => PlanStatus::Planned,
            'description' => 'Pozos de ventilación nivel 450.',
            'created_by' => $admin?->id,
        ]);

        $platformMdr6 = DrillingPlatform::create([
            'drilling_plan_id' => $infillPlan->id,
            'code' => 'MDR700-6',
            'name' => 'MDR700-6',
            'easting' => 512345.1234,
            'northing' => 6789012.5678,
            'elevation' => 450.50,
            'gallery' => 'G-12',
            'level' => '450',
            'status' => PlatformStatus::Active,
        ]);

        $platformGtw = DrillingPlatform::create([
            'drilling_plan_id' => $infillPlan->id,
            'code' => 'GTW 554',
            'name' => 'GTW 554',
            'easting' => 512400.0000,
            'northing' => 6789050.0000,
            'elevation' => 451.00,
            'gallery' => 'G-14',
            'level' => '450',
            'status' => PlatformStatus::Active,
        ]);

        $platformMdr7 = DrillingPlatform::create([
            'drilling_plan_id' => $ventilationPlan->id,
            'code' => 'MDR700-7',
            'name' => 'MDR700-7',
            'easting' => 512500.0000,
            'northing' => 6789100.0000,
            'elevation' => 450.00,
            'gallery' => 'G-20',
            'level' => '450',
            'status' => PlatformStatus::Planned,
        ]);

        $machines = [
            Machine::create(['code' => 'UG-DRILL-01', 'type' => MachineType::Underground, 'status' => MachineStatus::Active, 'notes' => 'Jumbo principal']),
            Machine::create(['code' => 'UG-DRILL-02', 'type' => MachineType::Underground, 'status' => MachineStatus::Active, 'notes' => 'Jumbo secundario']),
            Machine::create(['code' => 'SF-DRILL-01', 'type' => MachineType::Surface, 'status' => MachineStatus::Maintenance, 'notes' => 'Perforadora superficie']),
            Machine::create(['code' => 'UG-DRILL-03', 'type' => MachineType::Underground, 'status' => MachineStatus::OutOfService, 'notes' => 'Fuera de servicio']),
        ];

        foreach ($machines as $machine) {
            MachineAvailability::create([
                'machine_id' => $machine->id,
                'drilling_plan_id' => $infillPlan->id,
                'drilling_platform_id' => $platformMdr6->id,
                'available_from' => now()->subDays(30),
                'active' => $machine->status === MachineStatus::Active,
            ]);
        }

        $holesData = [
            ['hole_id' => 'RLEK-0523', 'platform' => $platformMdr6, 'plan' => $infillPlan, 'status' => HoleStatus::InProgress, 'depth' => 12.5, 'length' => 45.0],
            ['hole_id' => 'RLEK-0524', 'platform' => $platformMdr6, 'plan' => $infillPlan, 'status' => HoleStatus::InProgress, 'depth' => 8.0, 'length' => 40.0],
            ['hole_id' => 'RLEK-0525', 'platform' => $platformGtw, 'plan' => $infillPlan, 'status' => HoleStatus::Planned, 'depth' => 0, 'length' => 35.0],
            ['hole_id' => 'RLEK-0526', 'platform' => $platformGtw, 'plan' => $infillPlan, 'status' => HoleStatus::Risk, 'depth' => 15.0, 'length' => 50.0],
            ['hole_id' => 'RLEK-0527', 'platform' => $platformMdr7, 'plan' => $ventilationPlan, 'status' => HoleStatus::Planned, 'depth' => 0, 'length' => 60.0],
            ['hole_id' => 'RLEK-0528', 'platform' => $platformMdr7, 'plan' => $ventilationPlan, 'status' => HoleStatus::Planned, 'depth' => 0, 'length' => 55.0],
            ['hole_id' => 'LEG-0101', 'platform' => $platformMdr6, 'plan' => $infillPlan, 'status' => HoleStatus::Completed, 'depth' => 42.0, 'length' => 42.0],
            ['hole_id' => 'LEG-0102', 'platform' => $platformGtw, 'plan' => $infillPlan, 'status' => HoleStatus::Cancelled, 'depth' => 5.0, 'length' => 30.0],
        ];

        $createdHoles = [];
        foreach ($holesData as $index => $data) {
            $createdHoles[] = DrillHole::create([
                'drilling_plan_id' => $data['plan']->id,
                'drilling_platform_id' => $data['platform']->id,
                'order_number' => $index + 1,
                'rec_id' => 'REC-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'hole_id' => $data['hole_id'],
                'target' => 'Target '.($index + 1),
                'length' => $data['length'],
                'current_depth' => $data['depth'],
                'azimuth' => 180 + $index,
                'dip' => -75 - $index,
                'hole_type' => HoleType::Underground,
                'status' => $data['status'],
                'easting' => 512345.1234 + $index,
                'northing' => 6789012.5678 + $index,
                'elevation' => 450.50,
                'coordinate_system' => 'UTM 19S',
            ]);
        }

        $mainHole = $createdHoles[0];
        $riskHole = $createdHoles[3];

        DrillHoleAssignment::create([
            'drill_hole_id' => $mainHole->id,
            'user_id' => $driller->id,
            'role_in_hole' => RoleInHole::Driller,
            'assigned_from' => now()->subDays(5),
            'active' => true,
        ]);

        DrillHoleAssignment::create([
            'drill_hole_id' => $mainHole->id,
            'user_id' => $helper->id,
            'role_in_hole' => RoleInHole::Helper,
            'assigned_from' => now()->subDays(5),
            'active' => true,
        ]);

        DrillHoleAssignment::create([
            'drill_hole_id' => $riskHole->id,
            'user_id' => $helper->id,
            'role_in_hole' => RoleInHole::Helper,
            'assigned_from' => now()->subDays(2),
            'active' => true,
        ]);

        DrillHoleAssignment::create([
            'drill_hole_id' => $mainHole->id,
            'user_id' => $geologist->id,
            'role_in_hole' => RoleInHole::Geologist,
            'assigned_from' => now()->subDays(10),
            'active' => true,
        ]);

        Observation::create([
            'drill_hole_id' => $riskHole->id,
            'user_id' => $helper->id,
            'type' => ObservationType::Risk,
            'body' => 'Zona de fractura detectada a 14.5m. Riesgo de colapso.',
            'risk_level' => RiskLevel::Critical,
            'risk_status' => RiskStatus::Open,
            'depth_detected' => 14.5,
            'critical_distance' => 0.5,
            'recommended_action' => 'Detener perforación y evaluar estabilidad con geotecnia.',
        ]);

        Observation::create([
            'drill_hole_id' => $mainHole->id,
            'user_id' => $geologist->id,
            'type' => ObservationType::Geology,
            'body' => 'Alteración moderada en core 10-12m.',
        ]);

        Observation::create([
            'drill_hole_id' => $mainHole->id,
            'user_id' => $supervisor->id,
            'type' => ObservationType::Operational,
            'body' => 'Cambio de turno sin incidencias.',
        ]);

        $files = [
            ['name' => 'Presentación Plan Infill', 'type' => PlanFileType::Powerpoint, 'version' => '1.0'],
            ['name' => 'Planilla Pozos Excel', 'type' => PlanFileType::Excel, 'version' => '2.1'],
            ['name' => 'Resultado V1', 'type' => PlanFileType::Result, 'version' => 'V1'],
            ['name' => 'Resultado V2', 'type' => PlanFileType::Result, 'version' => 'V2'],
            ['name' => 'Resultado V4', 'type' => PlanFileType::Result, 'version' => 'V4'],
        ];

        foreach ($files as $file) {
            PlanFile::create([
                'drilling_plan_id' => $infillPlan->id,
                'name' => $file['name'],
                'type' => $file['type'],
                'version' => $file['version'],
                'description' => 'Archivo mock para MVP',
                'file_path' => 'plans/'.$infillPlan->id.'/'.strtolower(str_replace(' ', '-', $file['name'])).'.mock',
                'uploaded_by' => $admin?->id,
            ]);
        }
    }
}
