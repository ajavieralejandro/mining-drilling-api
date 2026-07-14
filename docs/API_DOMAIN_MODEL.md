# Modelo de dominio — Mining Drilling API

**Fecha:** 2026-07-14  
**Fuente:** modelos Eloquent + migraciones. Sin ejecución de migraciones en esta auditoría.

## Modelos reales

| Modelo | Tabla | Fillable | Casts | Relaciones | Soft delete | Observadores |
| ------ | ----- | -------- | ----- | ---------- | ----------- | ------------ |
| User | users | name, email, password, role, shift, active | role:UserRole, active:bool, password:hashed | assignments, progressLogs, observations | sí | no |
| DrillingPlan | drilling_plans | name, mine, level, sector, purpose, planned_meters, executed_meters, status, description, created_by | purpose, status, decimals | creator, platforms, drillHoles, files, machineAvailability | sí | no |
| DrillingPlatform | drilling_platforms | drilling_plan_id, code, name, easting, northing, elevation, gallery, level, status | status, coords | plan, drillHoles, machineAvailability | sí | no |
| Machine | machines | code, type, status, notes | type, status | drillHoles (BTM), availability | sí | no |
| MachineAvailability | machine_availability | machine_id, drilling_plan_id, drilling_platform_id, available_from, available_to, active | datetimes, active | machine, plan, platform | no | no |
| DrillHole | drill_holes | plan/platform ids, order_number, rec_id, hole_id, target, length, current_depth, azimuth, dip, hole_type, status, coords, coordinate_system, observations | enums + decimals | plan, platform, machines, assignments, progressLogs, holeObservations, riskObservations | sí | no |
| DrillHoleMachine | drill_hole_machines | drill_hole_id, machine_id, assigned_from, assigned_to, active | datetimes, active | drillHole, machine | no | no |
| DrillHoleAssignment | drill_hole_assignments | drill_hole_id, user_id, role_in_hole, assigned_from, assigned_to, active | RoleInHole, datetimes, active | drillHole, user | no | no |
| DrillHoleProgressLog | drill_hole_progress_logs | drill_hole_id, user_id, depths, shift, logged_at, observations | decimals, datetime | drillHole, user | no | no |
| Observation | observations | drill_hole_id, user_id, type, body, risk_*, depths, closed_at, reviewed_by | enums, decimals, datetime | drillHole, user, reviewer | sí | no |
| PlanFile | plan_files | drilling_plan_id, name, type, version, description, file_path, uploaded_by | PlanFileType | plan, uploader | sí | no |
| CsvImport | csv_imports | filename, imported_by, status, counts, errors, strategy, source_type | enums, errors:array | importer | no | no |
| AuditLog | audit_logs | user_id, entity_type, entity_id, action, old_values, new_values, source, created_at | arrays, AuditSource; timestamps=false | user | no | no |

**No existe** modelo `Risk` separado. Un riesgo es una `Observation` con `type = risk`.

**No existe** `AuditEvent`; el nombre real es `AuditLog`.

---

## Diagrama textual (relaciones reales)

```text
User
  ├── DrillHoleAssignment ──► DrillHole
  ├── DrillHoleProgressLog ──► DrillHole
  └── Observation ──► DrillHole

DrillingPlan
  ├── created_by ──► User (opcional)
  ├── DrillingPlatform
  │     └── DrillHole
  ├── DrillHole (también directo)
  ├── PlanFile
  └── MachineAvailability ──► Machine

DrillHole
  ├── machines (BTM via drill_hole_machines)
  ├── assignments ──► User
  ├── progressLogs ──► User
  ├── holeObservations ──► Observation  (type libre)
  └── riskObservations ──► Observation  (type = risk)

CsvImport ──► User (imported_by)
AuditLog ──► User (nullable)
```

---

## Enums de dominio (valores)

| Enum | Valores |
| ---- | ------- |
| UserRole | admin, supervisor, geologist, driller, helper, geotechnical |
| PlanPurpose | infill, ventilation, exploration, production |
| PlanStatus | planned, in_progress, paused, completed |
| PlatformStatus | planned, active, completed, paused |
| HoleStatus | planned, in_progress, paused, completed, risk, cancelled |
| HoleType | surface, underground |
| MachineType | surface, underground |
| MachineStatus | active, maintenance, out_of_service |
| RoleInHole | geologist, driller, helper, supervisor, geotechnical |
| ObservationType | operational, geology, safety, general, risk |
| RiskLevel | low, medium, high, critical |
| RiskStatus | open, mitigated, closed |
| PlanFileType | powerpoint, excel, pdf, image, result, other |
| CsvImportStatus | previewed, confirmed, failed |
| CsvSourceType | csv, excel |
| AuditSource | mobile, web, csv_import, system |

---

## Usuarios sembrados (UserSeeder — emails de ejemplo)

- admin@app.test  
- supervisor@app.test  
- geologist@app.test  
- driller@app.test  
- helper@app.test  
- geotechnical@app.test  

Password sembrado: valor común de desarrollo documentado en seeders (`password`). **No usar en producción.**

## Datos sembrados (MiningDataSeeder — resumen)

- Planes: Infill y Ventilación (Mina Mariana Norte, nivel 450).
- Plataformas: MDR700-6, GTW 554, MDR700-7.
- Pozos: RLEK-0523 … RLEK-0528, LEG-0101, LEG-0102.
- Riesgo critical abierto en RLEK-0526.
- Archivos mock V1/V2/V4 + powerpoint/excel.
