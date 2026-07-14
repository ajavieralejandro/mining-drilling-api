# API Endpoints — Mining Drilling API

**Fecha:** 2026-07-14  
**Fuentes de verdad:** `php artisan route:list --path=api`, `routes/api.php`, controladores, Form Requests, Resources, Policies.  
**Sin ejecución de requests ni tests.**

## Resumen de rutas registradas (26)

| Método | URI | Nombre | Controlador | Middleware | Request | Resource | Policy / authz | Estado |
| ------ | --- | ------ | ----------- | ---------- | ------- | -------- | -------------- | ------ |
| GET | `/api/health` | — | HealthController | api | — | plain JSON | ninguna | implementado aparentemente |
| POST | `/api/auth/login` | — | AuthController@login | api | LoginRequest | token + UserResource | pública | implementado aparentemente |
| GET | `/api/auth/me` | — | AuthController@me | api, auth:sanctum | — | UserResource | autenticación | implementado aparentemente |
| POST | `/api/auth/logout` | — | AuthController@logout | api, auth:sanctum | — | JSON message | autenticación | implementado aparentemente |
| GET | `/api/dashboard/summary` | — | DashboardController@summary | api, auth:sanctum | — | plain JSON | sin policy; filtro por rol | implementado aparentemente |
| GET | `/api/drilling-plans` | — | DrillingPlanController@index | api, auth:sanctum | — | DrillingPlanResource | DrillingPlanPolicy::viewAny + filtro | implementado aparentemente |
| GET | `/api/drilling-plans/{drillingPlan}` | — | DrillingPlanController@show | api, auth:sanctum | — | DrillingPlanResource | DrillingPlanPolicy::view | implementado aparentemente |
| GET | `/api/drilling-plans/{drillingPlan}/platforms` | — | DrillingPlatformController@byPlan | api, auth:sanctum | — | DrillingPlatformResource | view plan | implementado aparentemente |
| GET | `/api/drilling-plans/{drillingPlan}/available-machines` | — | MachineController@availableByPlan | api, auth:sanctum | — | MachineResource | view plan | implementado aparentemente |
| GET | `/api/drilling-plans/{drillingPlan}/files` | — | PlanFileController@byPlan | api, auth:sanctum | — | PlanFileResource | view plan | implementado aparentemente |
| GET | `/api/drilling-platforms/{drillingPlatform}` | — | DrillingPlatformController@show | api, auth:sanctum | — | DrillingPlatformResource | view plan padre | implementado aparentemente |
| GET | `/api/drill-holes` | — | DrillHoleController@index | api, auth:sanctum | — | DrillHoleResource | viewAny + filtro | implementado aparentemente |
| POST | `/api/drill-holes` | — | DrillHoleController@store | api, auth:sanctum | StoreDrillHoleRequest | DrillHoleResource | create | implementado aparentemente |
| GET | `/api/drill-holes/{drillHole}` | — | DrillHoleController@show | api, auth:sanctum | — | DrillHoleResource | view | implementado aparentemente |
| PATCH | `/api/drill-holes/{drillHole}/technical-data` | — | DrillHoleController@updateTechnicalData | api, auth:sanctum | UpdateTechnicalDataRequest | DrillHoleResource | updateTechnicalData | implementado aparentemente |
| POST | `/api/drill-holes/{drillHole}/assignments` | — | DrillHoleAssignmentController@store | api, auth:sanctum | StoreAssignmentRequest | DrillHoleAssignmentResource | assignPersonnel | implementado aparentemente |
| POST | `/api/drill-holes/{drillHole}/progress` | — | DrillHoleProgressController@store | api, auth:sanctum | StoreProgressRequest | DrillHoleProgressLogResource | logProgress | implementado aparentemente |
| GET | `/api/drill-holes/{drillHole}/observations` | — | ObservationController@index | api, auth:sanctum | — | ObservationResource | view drillHole | implementado aparentemente |
| POST | `/api/drill-holes/{drillHole}/observations` | — | ObservationController@store | api, auth:sanctum | StoreObservationRequest | ObservationResource | createObservation | implementado aparentemente |
| POST | `/api/drill-holes/{drillHole}/risks` | — | ObservationController@storeRisk | api, auth:sanctum | StoreRiskRequest | ObservationResource | createRisk | implementado aparentemente |
| PATCH | `/api/observations/{observation}/close-risk` | — | ObservationController@closeRisk | api, auth:sanctum | CloseRiskRequest | ObservationResource | ObservationPolicy::closeRisk | implementado aparentemente |
| GET | `/api/risks` | — | RiskController@index | api, auth:sanctum | — | ObservationResource | viewAny Observation + filtro | implementado aparentemente |
| GET | `/api/machines` | — | MachineController@index | api, auth:sanctum | — | MachineResource | **sin policy visible** | implementado aparentemente / sin autorización visible |
| GET | `/api/imports` | — | CsvImportController@index | api, auth:sanctum | — | CsvImportResource | CsvImportPolicy::viewAny | implementado aparentemente |
| POST | `/api/imports/preview` | — | CsvImportController@preview | api, auth:sanctum | PreviewImportRequest | CsvImportResource | preview | parcial (mock) |
| POST | `/api/imports/confirm` | — | CsvImportController@confirm | api, auth:sanctum | ConfirmImportRequest | CsvImportResource | confirm | parcial (solo cambia status) |

---

## Rutas esperadas por dominio — hallazgo

| Ruta esperada | Estado |
| ------------- | ------ |
| `GET /api/health` | **encontrada** |
| `POST /api/auth/login`, `GET /api/auth/me`, `POST /api/auth/logout` | **encontradas** |
| `GET /api/dashboard/summary` | **encontrada** |
| Planes listados en checklist | **encontradas** (5) |
| `GET /api/drilling-platforms` (índice global) | **no encontrada** |
| `GET /api/drilling-platforms/{id}` | **encontrada** |
| `GET/POST /api/drill-holes`, `GET {id}`, `PATCH .../technical-data` | **encontradas** |
| `PATCH /api/drill-holes/{id}` (genérico) | **no encontrada** |
| `GET /api/drill-holes/{id}/assignments` | **no encontrada** |
| `POST .../assignments` | **encontrada** |
| `DELETE .../assignments/{assignmentId}` | **no encontrada** |
| `GET .../progress` | **no encontrada** |
| `POST .../progress` | **encontrada** |
| Observaciones GET/POST | **encontradas** |
| `POST .../risks`, `GET /api/risks`, `PATCH close-risk` | **encontradas** |
| `GET /api/risks/{id}` | **no encontrada** |
| `GET /api/machines` | **encontrada** |
| `GET /api/machines/{id}` | **no encontrada** |
| `GET /api/users`, `GET /api/personnel` | **no encontradas** |
| Imports preview/confirm/index | **encontradas** |
| `GET /api/imports/{id}` | **no encontrada** |
| `GET /api/audit-events`, `GET .../audit-events` | **no encontradas** (modelo `AuditLog` existe) |

---

## Convenciones de respuesta aparentes

- Resource único o colección Laravel: típicamente envuelve en `{ "data": ... }`.
- Login: `{ "token": "...", "user": { ... } }` — según test Feature leído, `user` **sin** wrapper `data` en login; `me` **con** wrapper `data`.
- IDs: enteros (`bigint`) en JSON.
- Enums: se exponen como **strings** (valor del backed enum).
- Decimales: Laravel `decimal:N` suele serializar como **string** (p. ej. `"15.00"`). Cubierto por tests Feature leídos; pendiente de validación manual en Postman.
- Paginación: **no** implementada en listados (devuelven colección completa).
- Query filters / sort: **no** hay parámetros de query documentados en controladores.

---

## Contratos por endpoint

### GET `/api/health`

```text
Método: GET
URI: /api/health
Controlador: HealthController
Método del controlador: __invoke
Middleware: api (público; sin auth:sanctum)
Autenticación: no
Roles o permisos aparentes: cualquiera
Path parameters: —
Query parameters: —
Body: —
Validaciones: —
Respuesta aparente: { "status": "ok", "app": "Mining Drilling API" }
Resource: ninguno
Posibles errores: no verificable sin ejecución
Archivos relacionados: app/Http/Controllers/Api/HealthController.php
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### POST `/api/auth/login`

```text
Método: POST
URI: /api/auth/login
Controlador: AuthController
Método del controlador: login
Middleware: api (público)
Autenticación: no (emite token)
Roles o permisos aparentes: usuario existente y active=true
Path parameters: —
Query parameters: —
Body: { "email": string, "password": string }
Validaciones: LoginRequest — email required|email; password required|string
Respuesta aparente (200): { "token": string, "user": UserResource fields }
Resource: UserResource anidado bajo "user"
Posibles errores: 422 ValidationException (credenciales incorrectas / cuenta inactiva)
Archivos relacionados: AuthController, LoginRequest, UserResource
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

Token: `createToken('api-token')`. Nombre fijo `api-token`. `config/sanctum.php` → `expiration` = null (sin expiración global aparente).

### GET `/api/auth/me`

```text
Método: GET
URI: /api/auth/me
Controlador: AuthController@me
Middleware: api, auth:sanctum
Autenticación: Bearer token Sanctum
Roles o permisos aparentes: cualquier autenticado
Path parameters: —
Query parameters: —
Body: —
Validaciones: —
Respuesta aparente: UserResource → { "data": { id, name, email, role, shift, active } }
Resource: UserResource
Posibles errores: 401 sin token / token inválido
Archivos relacionados: AuthController, UserResource
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### POST `/api/auth/logout`

```text
Método: POST
URI: /api/auth/logout
Controlador: AuthController@logout
Middleware: api, auth:sanctum
Autenticación: Bearer token
Roles o permisos aparentes: autenticado
Path parameters: —
Query parameters: —
Body: —
Validaciones: —
Respuesta aparente: { "message": "Logged out successfully." }
Resource: —
Posibles errores: 401
Archivos relacionados: AuthController — currentAccessToken()->delete()
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### GET `/api/dashboard/summary`

```text
Método: GET
URI: /api/dashboard/summary
Controlador: DashboardController@summary
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: autenticado; si !canViewAll() filtra por pozos asignados
Path parameters: —
Query parameters: —
Body: —
Validaciones: —
Respuesta aparente: {
  "plans_total": int,
  "holes_total": int,
  "holes_in_progress": int,
  "open_risks": int
}
Resource: plain JsonResponse
Posibles errores: 401
Archivos relacionados: DashboardController
Estado: implementado aparentemente (sin policy explícita)
Prueba manual pendiente: sí
```

### GET `/api/drilling-plans`

```text
Método: GET
URI: /api/drilling-plans
Controlador: DrillingPlanController@index
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: viewAny=true para todos; driller/helper ven solo planes con pozos asignados
Path parameters: —
Query parameters: ninguno en código
Body: —
Validaciones: —
Respuesta aparente: colección DrillingPlanResource + platforms_count, drill_holes_count
Resource: DrillingPlanResource
Posibles errores: 401
Archivos relacionados: DrillingPlanController, DrillingPlanPolicy
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### GET `/api/drilling-plans/{drillingPlan}`

```text
Método: GET
URI: /api/drilling-plans/{drillingPlan}
Controlador: DrillingPlanController@show
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: DrillingPlanPolicy::view
Path parameters: drillingPlan (route model binding, id numérico)
Query parameters: —
Body: —
Validaciones: —
Respuesta aparente: plan con platforms, drillHoles, files cargados
Resource: DrillingPlanResource
Posibles errores: 401, 403, 404
Archivos relacionados: DrillingPlanController, DrillingPlanPolicy
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### GET `/api/drilling-plans/{drillingPlan}/platforms`

```text
Método: GET
URI: /api/drilling-plans/{drillingPlan}/platforms
Controlador: DrillingPlatformController@byPlan
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: authorize view sobre el plan
Path parameters: drillingPlan
Query parameters: —
Body: —
Validaciones: —
Respuesta aparente: colección plataformas (orden code) con drillHoles
Resource: DrillingPlatformResource
Posibles errores: 401, 403, 404
Archivos relacionados: DrillingPlatformController
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### GET `/api/drilling-plans/{drillingPlan}/available-machines`

```text
Método: GET
URI: /api/drilling-plans/{drillingPlan}/available-machines
Controlador: MachineController@availableByPlan
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: view plan
Path parameters: drillingPlan
Query parameters: —
Body: —
Validaciones: —
Respuesta aparente: máquinas con availability del plan O status active (lógica orWhere; revisar en prueba manual)
Resource: MachineResource
Posibles errores: 401, 403, 404
Archivos relacionados: MachineController
Estado: implementado aparentemente (consulta potencialmente amplia por orWhere)
Prueba manual pendiente: sí
```

### GET `/api/drilling-plans/{drillingPlan}/files`

```text
Método: GET
URI: /api/drilling-plans/{drillingPlan}/files
Controlador: PlanFileController@byPlan
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: view plan
Path parameters: drillingPlan
Query parameters: —
Body: —
Validaciones: —
Respuesta aparente: metadata de archivos (file_path mock; sin streaming)
Resource: PlanFileResource
Posibles errores: 401, 403, 404
Archivos relacionados: PlanFileController
Estado: implementado aparentemente (sin descarga/upload)
Prueba manual pendiente: sí
```

### GET `/api/drilling-platforms/{drillingPlatform}`

```text
Método: GET
URI: /api/drilling-platforms/{drillingPlatform}
Controlador: DrillingPlatformController@show
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: view sobre plan padre
Path parameters: drillingPlatform
Query parameters: —
Body: —
Validaciones: —
Respuesta aparente: plataforma + drillHoles + plan
Resource: DrillingPlatformResource
Posibles errores: 401, 403, 404
Archivos relacionados: DrillingPlatformController
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### GET `/api/drill-holes`

```text
Método: GET
URI: /api/drill-holes
Controlador: DrillHoleController@index
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: viewAny; !canViewAll() → solo asignados
Path parameters: —
Query parameters: —
Body: —
Validaciones: —
Respuesta aparente: colección con plan y platform
Resource: DrillHoleResource
Posibles errores: 401
Archivos relacionados: DrillHoleController, DrillHolePolicy
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### POST `/api/drill-holes`

```text
Método: POST
URI: /api/drill-holes
Controlador: DrillHoleController@store
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: admin | supervisor | geologist (create)
Path parameters: —
Query parameters: —
Body: ver StoreDrillHoleRequest (length, azimuth, dip required; resto opcional)
Validaciones: StoreDrillHoleRequest
Respuesta aparente: 201 + DrillHoleResource
Resource: DrillHoleResource
Posibles errores: 401, 403, 422
Archivos relacionados: DrillHoleController, StoreDrillHoleRequest, AuditLogger drill_hole.created
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### GET `/api/drill-holes/{drillHole}`

```text
Método: GET
URI: /api/drill-holes/{drillHole}
Controlador: DrillHoleController@show
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: canViewAll o asignación activa
Path parameters: drillHole
Query parameters: —
Body: —
Validaciones: —
Respuesta aparente: pozo + plan, platform, assignments.user, progressLogs, holeObservations
Resource: DrillHoleResource
Posibles errores: 401, 403, 404
Archivos relacionados: DrillHoleController
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### PATCH `/api/drill-holes/{drillHole}/technical-data`

```text
Método: PATCH
URI: /api/drill-holes/{drillHole}/technical-data
Controlador: DrillHoleController@updateTechnicalData
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: admin | geologist
Path parameters: drillHole
Query parameters: —
Body: campos sometimes (target, length, azimuth, dip, hole_type, status, coordenadas, observations)
Validaciones: UpdateTechnicalDataRequest
Respuesta aparente: 200 + DrillHoleResource
Resource: DrillHoleResource
Posibles errores: 401, 403, 404, 422
Archivos relacionados: UpdateTechnicalDataRequest, AuditLogger
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### POST `/api/drill-holes/{drillHole}/assignments`

```text
Método: POST
URI: /api/drill-holes/{drillHole}/assignments
Controlador: DrillHoleAssignmentController@store
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: admin | supervisor (assignPersonnel)
Path parameters: drillHole
Query parameters: —
Body: { user_id, role_in_hole, assigned_from?, assigned_to? }
Validaciones: StoreAssignmentRequest — no valida unicidad de asignación duplicada
Respuesta aparente: 201 + DrillHoleAssignmentResource
Resource: DrillHoleAssignmentResource
Posibles errores: 401, 403, 404, 422
Archivos relacionados: StoreAssignmentRequest, DrillHolePolicy::assignPersonnel
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### POST `/api/drill-holes/{drillHole}/progress`

```text
Método: POST
URI: /api/drill-holes/{drillHole}/progress
Controlador: DrillHoleProgressController@store
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: admin | driller asignado
Path parameters: drillHole
Query parameters: —
Body: { depth_from, depth_to, depth_current, shift?, logged_at?, observations? }
Validaciones: StoreProgressRequest — depth_to gte depth_from; min 0; no valida vs length del pozo
Respuesta aparente: 201 + progress resource; side-effect: actualiza drill_holes.current_depth
Resource: DrillHoleProgressLogResource
Posibles errores: 401, 403, 404, 422
Archivos relacionados: StoreProgressRequest, AuditLogger
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### GET `/api/drill-holes/{drillHole}/observations`

```text
Método: GET
URI: /api/drill-holes/{drillHole}/observations
Controlador: ObservationController@index
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: view drillHole
Path parameters: drillHole
Query parameters: —
Body: —
Validaciones: —
Respuesta aparente: colección ObservationResource (incluye riesgos del pozo)
Resource: ObservationResource
Posibles errores: 401, 403, 404
Archivos relacionados: ObservationController
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### POST `/api/drill-holes/{drillHole}/observations`

```text
Método: POST
URI: /api/drill-holes/{drillHole}/observations
Controlador: ObservationController@store
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: createObservation (admin / canViewAll / asignado)
Path parameters: drillHole
Query parameters: —
Body: { type: ObservationType, body: string }
Validaciones: StoreObservationRequest — type puede ser "risk" por este endpoint también (sin forzar campos de riesgo)
Respuesta aparente: 201 + ObservationResource
Resource: ObservationResource
Posibles errores: 401, 403, 404, 422
Archivos relacionados: StoreObservationRequest
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### POST `/api/drill-holes/{drillHole}/risks`

```text
Método: POST
URI: /api/drill-holes/{drillHole}/risks
Controlador: ObservationController@storeRisk
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: createRisk — admin/supervisor/geologist/geotechnical; driller/helper solo si asignados
Path parameters: drillHole
Query parameters: —
Body: { body, risk_level, depth_detected?, critical_distance?, recommended_action? }
Validaciones: StoreRiskRequest
Respuesta aparente: 201; type forzado a risk; risk_status forzado a open
Resource: ObservationResource
Posibles errores: 401, 403, 404, 422
Archivos relacionados: StoreRiskRequest, AuditLogger risk.reported
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### PATCH `/api/observations/{observation}/close-risk`

```text
Método: PATCH
URI: /api/observations/{observation}/close-risk
Controlador: ObservationController@closeRisk
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: ObservationPolicy::closeRisk — debe ser type risk; admin | supervisor | geotechnical
Path parameters: observation
Query parameters: —
Body: { risk_status? } default closed
Validaciones: CloseRiskRequest
Respuesta aparente: 200; closed_at=now; reviewed_by=auth id
Resource: ObservationResource
Posibles errores: 401, 403 (también si no es risk según policy), 404, 422
Archivos relacionados: CloseRiskRequest, ObservationPolicy
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### GET `/api/risks`

```text
Método: GET
URI: /api/risks
Controlador: RiskController@index
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: viewAny Observation (true); filtro asignados si !canViewAll
Path parameters: —
Query parameters: —
Body: —
Validaciones: —
Respuesta aparente: observaciones type=risk con user y drillHole
Resource: ObservationResource
Posibles errores: 401
Archivos relacionados: RiskController
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### GET `/api/machines`

```text
Método: GET
URI: /api/machines
Controlador: MachineController@index
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: **ninguna policy**; todo autenticado ve todas
Path parameters: —
Query parameters: —
Body: —
Validaciones: —
Respuesta aparente: colección MachineResource ordenada por code
Resource: MachineResource
Posibles errores: 401
Archivos relacionados: MachineController
Estado: implementado aparentemente / sin autorización visible por rol
Prueba manual pendiente: sí
```

### GET `/api/imports`

```text
Método: GET
URI: /api/imports
Controlador: CsvImportController@index
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: admin | supervisor | geologist
Path parameters: —
Query parameters: —
Body: —
Validaciones: —
Respuesta aparente: colección CsvImportResource
Resource: CsvImportResource
Posibles errores: 401, 403
Archivos relacionados: CsvImportPolicy
Estado: implementado aparentemente
Prueba manual pendiente: sí
```

### POST `/api/imports/preview`

```text
Método: POST
URI: /api/imports/preview
Controlador: CsvImportController@preview
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: preview (mismo set que viewAny)
Path parameters: —
Query parameters: —
Body: { filename, source_type?, strategy?, rows?[] }
Validaciones: PreviewImportRequest
Respuesta aparente: crea CsvImport status=previewed; si rows vacío usa conteos mock fijos
Resource: CsvImportResource
Posibles errores: 401, 403, 422
Archivos relacionados: CsvImportController — **no crea DrillHole**
Estado: parcial (mock / preparado)
Prueba manual pendiente: sí
```

### POST `/api/imports/confirm`

```text
Método: POST
URI: /api/imports/confirm
Controlador: CsvImportController@confirm
Middleware: api, auth:sanctum
Autenticación: Bearer
Roles o permisos aparentes: confirm
Path parameters: —
Query parameters: —
Body: { import_id }
Validaciones: ConfirmImportRequest — exists:csv_imports,id
Respuesta aparente: status → confirmed; **no importa filas a drill_holes**
Resource: CsvImportResource
Posibles errores: 401, 403, 422
Archivos relacionados: CsvImportController
Estado: parcial
Prueba manual pendiente: sí
```

---

## Form Requests — inventario de campos

| Request | Endpoint | Campo | Tipo | Requerido | Regla | Observación |
| ------- | -------- | ----- | ---- | --------- | ----- | ----------- |
| LoginRequest | POST /auth/login | email | string | sí | email | |
| LoginRequest | POST /auth/login | password | string | sí | string | |
| StoreDrillHoleRequest | POST /drill-holes | length | numeric | sí | min:0 | |
| StoreDrillHoleRequest | POST /drill-holes | azimuth | numeric | sí | | |
| StoreDrillHoleRequest | POST /drill-holes | dip | numeric | sí | | |
| StoreDrillHoleRequest | POST /drill-holes | hole_id | string | no | unique | no es PK |
| StoreDrillHoleRequest | POST /drill-holes | drilling_plan_id | id | no | exists | |
| StoreDrillHoleRequest | POST /drill-holes | drilling_platform_id | id | no | exists | no valida pertenencia plan↔plataforma |
| UpdateTechnicalDataRequest | PATCH .../technical-data | * | varios | sometimes | enums HoleType/HoleStatus | |
| StoreAssignmentRequest | POST .../assignments | user_id | id | sí | exists:users | |
| StoreAssignmentRequest | POST .../assignments | role_in_hole | enum | sí | RoleInHole | |
| StoreProgressRequest | POST .../progress | depth_from/to/current | numeric | sí | min 0; to gte from | sin techo = length |
| StoreObservationRequest | POST .../observations | type | enum | sí | ObservationType | |
| StoreObservationRequest | POST .../observations | body | string | sí | | |
| StoreRiskRequest | POST .../risks | body | string | sí | | |
| StoreRiskRequest | POST .../risks | risk_level | enum | sí | RiskLevel | |
| CloseRiskRequest | PATCH close-risk | risk_status | enum | no | RiskStatus | default closed en controller |
| PreviewImportRequest | POST imports/preview | filename | string | sí | max:255 | |
| PreviewImportRequest | POST imports/preview | rows.*.hole_id | string | cond. | required_with:rows | |
| ConfirmImportRequest | POST imports/confirm | import_id | id | sí | exists | |

---

## API Resources — inventario

| Resource | Modelo | Campos expuestos | Relaciones | Campos sensibles | Inconsistencias |
| -------- | ------ | ---------------- | ---------- | ---------------- | --------------- |
| UserResource | User | id, name, email, role, shift, active | — | email expuesto (aceptable API internal) | password oculto en model |
| DrillingPlanResource | DrillingPlan | id, name, mine, level, sector, purpose, meters, status, description, created_by, counts | platforms, drill_holes, files | — | |
| DrillingPlatformResource | DrillingPlatform | id, plan_id, code, name, coords, gallery, level, status | drill_holes | — | |
| DrillHoleResource | DrillHole | technical + coords + observations (columna) | plan, platform, assignments, progress_logs, hole_observations | — | columna `observations` ≠ relación `holeObservations` |
| DrillHoleAssignmentResource | DrillHoleAssignment | ids, role_in_hole, fechas, active | user | — | |
| DrillHoleProgressLogResource | DrillHoleProgressLog | depths, shift, logged_at, observations | user | — | |
| ObservationResource | Observation | type, body, risk_*, depths, closed_at, reviewed_by | user, drill_hole | — | |
| MachineResource | Machine | id, code, type, status, notes | — | — | |
| PlanFileResource | PlanFile | name, type, version, description, file_path, uploaded_by | — | file_path paths internos | sin URL firmada |
| CsvImportResource | CsvImport | filename, status, counts, errors, strategy, source_type | — | — | |

---

## Referencia tests Feature (solo lectura; no ejecutados)

Los archivos en `tests/Feature/` documentan comportamientos esperados (auth, planes visibles por rol, technical-data, assignments, progress, risks).  
**Prueba manual pendiente** para confirmación en runtime.
