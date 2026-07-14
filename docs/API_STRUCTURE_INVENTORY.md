# Inventario de estructura — Mining Drilling API

**Fecha de auditoría:** 2026-07-14  
**Alcance:** inspección estática del código. Sin ejecución de tests ni requests.

## Carpetas ausentes (no existen en el repositorio)

| Carpeta esperada | Estado |
| ---------------- | ------ |
| `app/Actions/` | no existe |
| `app/Jobs/` | no existe |
| `app/Events/` | no existe |
| `app/Listeners/` | no existe |
| `app/Observers/` | no existe |

---

## Inventario

| Tipo | Archivo | Responsabilidad | Estado aparente | Observaciones |
| ---- | ------- | --------------- | --------------- | ------------- |
| Enum | `app/Enums/UserRole.php` | Roles de usuario | implementado | 6 roles; `canViewAll()` |
| Enum | `app/Enums/PlanPurpose.php` | Propósito de plan | implementado | |
| Enum | `app/Enums/PlanStatus.php` | Estado de plan | implementado | |
| Enum | `app/Enums/PlatformStatus.php` | Estado de plataforma | implementado | |
| Enum | `app/Enums/MachineType.php` | Tipo de máquina | implementado | |
| Enum | `app/Enums/MachineStatus.php` | Estado de máquina | implementado | |
| Enum | `app/Enums/HoleType.php` | Tipo de pozo | implementado | |
| Enum | `app/Enums/HoleStatus.php` | Estado de pozo | implementado | |
| Enum | `app/Enums/RoleInHole.php` | Rol en asignación | implementado | |
| Enum | `app/Enums/ObservationType.php` | Tipo observación | implementado | incluye `risk` |
| Enum | `app/Enums/RiskLevel.php` | Severidad riesgo | implementado | |
| Enum | `app/Enums/RiskStatus.php` | Estado riesgo | implementado | |
| Enum | `app/Enums/PlanFileType.php` | Tipo archivo plan | implementado | |
| Enum | `app/Enums/CsvImportStatus.php` | Estado importación | implementado | |
| Enum | `app/Enums/CsvSourceType.php` | Origen CSV/Excel | implementado | |
| Enum | `app/Enums/AuditSource.php` | Origen auditoría | implementado | |
| Model | `app/Models/User.php` | Usuario + Sanctum | implementado | SoftDeletes; helpers de asignación |
| Model | `app/Models/DrillingPlan.php` | Plan de perforación | implementado | SoftDeletes |
| Model | `app/Models/DrillingPlatform.php` | Plataforma / estocada | implementado | SoftDeletes |
| Model | `app/Models/Machine.php` | Máquina | implementado | SoftDeletes |
| Model | `app/Models/MachineAvailability.php` | Disponibilidad | implementado | tabla `machine_availability` |
| Model | `app/Models/DrillHole.php` | Pozo | implementado | SoftDeletes; relación `holeObservations` (no `observations` para evitar colisión con columna) |
| Model | `app/Models/DrillHoleMachine.php` | Pivot pozo-máquina | implementado | sin endpoints CRUD dedicados |
| Model | `app/Models/DrillHoleAssignment.php` | Asignación personal | implementado | solo POST |
| Model | `app/Models/DrillHoleProgressLog.php` | Avance | implementado | solo POST |
| Model | `app/Models/Observation.php` | Observación / riesgo | implementado | SoftDeletes; riesgo = type risk |
| Model | `app/Models/PlanFile.php` | Archivo de plan | implementado | SoftDeletes; sin upload real |
| Model | `app/Models/CsvImport.php` | Importación mock | implementado | preview/confirm sin persistir pozos |
| Model | `app/Models/AuditLog.php` | Log de auditoría | implementado | sin endpoints de lectura |
| Service | `app/Services/AuditLogger.php` | Helper estático de auditoría | implementado | usado desde varios controladores |
| Controller | `app/Http/Controllers/Controller.php` | Base + AuthorizesRequests | implementado | |
| Controller | `Api/HealthController.php` | Healthcheck | implementado | público |
| Controller | `Api/AuthController.php` | Login / me / logout | implementado | |
| Controller | `Api/DashboardController.php` | Resumen | implementado | sin policy explícita; filtra por rol |
| Controller | `Api/DrillingPlanController.php` | Listado/detalle planes | implementado | |
| Controller | `Api/DrillingPlatformController.php` | Detalle/listado por plan | implementado | |
| Controller | `Api/DrillHoleController.php` | CRUD parcial pozos | implementado | sin PATCH genérico |
| Controller | `Api/DrillHoleAssignmentController.php` | Alta asignación | implementado | sin GET/DELETE |
| Controller | `Api/DrillHoleProgressController.php` | Alta avance | implementado | sin GET |
| Controller | `Api/ObservationController.php` | Observaciones y riesgos | implementado | |
| Controller | `Api/RiskController.php` | Listado riesgos | implementado | |
| Controller | `Api/MachineController.php` | Máquinas | implementado | index sin policy |
| Controller | `Api/PlanFileController.php` | Archivos por plan | implementado | solo lectura metadata |
| Controller | `Api/CsvImportController.php` | Preview/confirm/list | parcial | no importa filas reales a pozos |
| Policy | `DrillingPlanPolicy.php` | viewAny / view | implementado | |
| Policy | `DrillHolePolicy.php` | view/create/técnico/risk/assign/progress/obs | implementado | |
| Policy | `ObservationPolicy.php` | viewAny/view/create/closeRisk | implementado | `create` posiblemente no usado por Requests |
| Policy | `CsvImportPolicy.php` | viewAny/preview/confirm | implementado | |
| Policy | `DrillHoleAssignmentPolicy.php` | create | no utilizado | authz real en `DrillHolePolicy::assignPersonnel` |
| Policy | `DrillHoleProgressLogPolicy.php` | create | no utilizado | authz real en `DrillHolePolicy::logProgress` |
| Request | `Auth/LoginRequest.php` | Validación login | implementado | |
| Request | `DrillHole/*.php` (4) | Store/update/assign/progress | implementado | |
| Request | `Observation/*.php` (3) | Store/risk/close | implementado | |
| Request | `Import/*.php` (2) | Preview/confirm | implementado | |
| Resource | 10 Resources bajo `Http/Resources/` | Serialización JSON | implementado | wrapper `data` en Resource directo |
| Seeder | `UserSeeder.php` | Usuarios por rol | implementado | |
| Seeder | `MiningDataSeeder.php` | Datos mock de dominio | implementado | |
| Seeder | `DatabaseSeeder.php` | Orquestación | implementado | |
| Factory | `UserFactory.php` | Factory User | implementado | |
| Migration | 13 migraciones de dominio + framework | Schema | implementado | ver `database/migrations/` |
| Test | Feature/* (7) + Unit/Example | Documentación viva | no verificable | no se ejecutaron en esta auditoría |
| Route | `routes/api.php` | 26 rutas API | implementado | fuente primaria de rutas |
| Config | `config/cors.php` | CORS Expo-oriented | implementado | |
| Config | `config/sanctum.php` | Sanctum | implementado | `expiration` = null |

---

## Notas

- Los tests Feature se tratan solo como referencia documental. **No se ejecutaron** en esta auditoría.
- No hay modelo `Risk` separado: los riesgos son `Observation` con `type = risk`.
- No hay endpoints HTTP de auditoría pese a existir `AuditLog` + `AuditLogger`.
