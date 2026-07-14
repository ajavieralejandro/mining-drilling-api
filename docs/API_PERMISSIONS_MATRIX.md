# Matriz de permisos — Mining Drilling API

**Fecha:** 2026-07-14  
**Fuente:** Policies, Form Requests `authorize()`, filtros en controladores.  
**Sin ejecución.** Leyenda de evidencia al pie.

## Roles existentes en código

| Rol | Evidencia |
| --- | --------- |
| admin | `UserRole::Admin` |
| supervisor | `UserRole::Supervisor` |
| geologist | `UserRole::Geologist` |
| driller | `UserRole::Driller` |
| helper | `UserRole::Helper` |
| geotechnical | `UserRole::Geotechnical` (comentario MVP: puede revisarse) |

**No encontrados:** safety, superadmin.

`canViewAll()` = admin | supervisor | geologist | geotechnical.

---

## Matriz de acciones

| Acción | Admin | Supervisor | Geologist | Driller | Helper | Geotechnical | Evidencia |
| ------ | ----- | ---------- | --------- | ------- | ------ | ------------ | --------- |
| Ver todos los planes | sí | sí | sí | no¹ | no¹ | sí | DrillingPlanPolicy + filtro index |
| Ver plan con pozos asignados | sí | sí | sí | sí¹ | sí¹ | sí | DrillingPlanPolicy::view |
| Ver plataformas (vía plan/id) | según plan | según plan | según plan | según plan | según plan | según plan | authorize view plan |
| Ver todos los pozos | sí | sí | sí | no¹ | no¹ | sí | DrillHolePolicy + filtro |
| Ver pozo asignado | sí | sí | sí | sí | sí | sí | view / isAssignedToHole |
| Crear pozo | sí | sí | sí | no | no | no | DrillHolePolicy::create |
| Editar datos técnicos | sí | no | sí | no | no | no | updateTechnicalData |
| Asignar personal | sí | sí | no | no | no | no | assignPersonnel (no usa AssignmentPolicy) |
| Asignar maquinaria (API) | — | — | — | — | — | — | **sin endpoint** de alta máquina-pozo |
| Registrar avance | sí | no | no | sí² | no | no | logProgress |
| Agregar observación | sí | sí³ | sí³ | sí² | sí² | sí³ | createObservation |
| Informar riesgo | sí | sí | sí | sí² | sí² | sí | createRisk |
| Cerrar riesgo | sí | sí | no | no | no | sí | ObservationPolicy::closeRisk |
| Listar máquinas | sí⁴ | sí⁴ | sí⁴ | sí⁴ | sí⁴ | sí⁴ | auth:sanctum solamente |
| Importar preview/confirm/list | sí | sí | sí | no | no | no | CsvImportPolicy |
| Consultar usuarios / personal | — | — | — | — | — | — | **sin endpoint** |
| Consultar auditoría HTTP | — | — | — | — | — | — | **sin endpoint**; AuditLogger escribe |

¹ Filtrado por asignaciones activas.  
² Solo si `isAssignedToHole`.  
³ `canViewAll` → permitidos en cualquier pozo (según código).  
⁴ Sin policy por rol: cualquier autenticado.

---

## Policies y uso real

| Policy | Métodos | ¿Usada en runtime? |
| ------ | ------- | ------------------ |
| DrillingPlanPolicy | viewAny, view | sí (controllers) |
| DrillHolePolicy | viewAny, view, create, updateTechnicalData, createRisk, assignPersonnel, logProgress, createObservation | sí (controllers + FormRequests) |
| ObservationPolicy | viewAny, view, create, closeRisk | sí (viewAny Risks; closeRisk Request); `create` del policy posiblemente no usado (Requests usan DrillHolePolicy::createObservation) |
| CsvImportPolicy | viewAny, preview, confirm | sí |
| DrillHoleAssignmentPolicy | create | **no utilizado** (dead code aparente) |
| DrillHoleProgressLogPolicy | create | **no utilizado** (dead code aparente) |

Registro: auto-discovery Laravel 12 (`AppServiceProvider` vacío).

---

## Controles sin policy explícita

| Endpoint | Control |
| -------- | ------- |
| GET /api/dashboard/summary | filtro query por rol |
| GET /api/machines | solo `auth:sanctum` |
| GET /api/health | público |
| POST /api/auth/login | público + active check |

---

## Advertencia

Que el frontend oculte un botón **no** constituye autorización. La matriz anterior refleja solo el backend.
