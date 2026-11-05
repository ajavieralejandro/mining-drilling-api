<?php

namespace App\Http\Controllers\Api;

use App\Enums\MachineStatus;
use App\Enums\MachineType;
use App\Http\Controllers\Controller;
use App\Http\Resources\MachineResource;
use App\Models\DrillingPlan;
use App\Models\Machine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MachineController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return MachineResource::collection(Machine::query()->orderBy('code')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:machines,code'],
            'type' => ['required', 'string'],
            'status' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $type = match (strtolower($validated['type'])) {
            'superficie', 'surface' => MachineType::Surface,
            default => MachineType::Underground,
        };

        $status = match (strtolower($validated['status'] ?? 'active')) {
            'mantenimiento', 'maintenance' => MachineStatus::Maintenance,
            'fuera_servicio', 'out_of_service' => MachineStatus::OutOfService,
            default => MachineStatus::Active,
        };

        $machine = Machine::create([
            'code' => strtoupper($validated['code']),
            'type' => $type,
            'status' => $status,
            'notes' => $validated['notes'] ?? null,
        ]);

        return (new MachineResource($machine))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Machine $machine): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:50', 'unique:machines,code,' . $machine->id],
            'type' => ['sometimes', 'string'],
            'status' => ['sometimes', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $data = [];
        if (isset($validated['code'])) {
            $data['code'] = strtoupper($validated['code']);
        }
        if (isset($validated['type'])) {
            $data['type'] = match (strtolower($validated['type'])) {
                'superficie', 'surface' => MachineType::Surface,
                default => MachineType::Underground,
            };
        }
        if (isset($validated['status'])) {
            $data['status'] = match (strtolower($validated['status'])) {
                'mantenimiento', 'maintenance' => MachineStatus::Maintenance,
                'fuera_servicio', 'out_of_service' => MachineStatus::OutOfService,
                default => MachineStatus::Active,
            };
        }
        if (array_key_exists('notes', $validated)) {
            $data['notes'] = $validated['notes'];
        }

        $machine->update($data);

        return (new MachineResource($machine->fresh()))
            ->response()
            ->setStatusCode(200);
    }

    public function availableByPlan(Request $request, DrillingPlan $drillingPlan): AnonymousResourceCollection
    {
        $this->authorize('view', $drillingPlan);

        $machines = Machine::query()
            ->whereHas('availability', function ($q) use ($drillingPlan) {
                $q->where('drilling_plan_id', $drillingPlan->id)->where('active', true);
            })
            ->orWhere('status', 'active')
            ->orderBy('code')
            ->get();

        return MachineResource::collection($machines);
    }
}
