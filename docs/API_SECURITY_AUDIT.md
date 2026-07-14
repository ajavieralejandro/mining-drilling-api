# Auditoría de seguridad estática — Mining Drilling API

**Fecha:** 2026-07-14  
**Alcance:** revisión de código. Diferenciar vulnerabilidad evidente vs riesgo de diseño vs no verificable.

| ID | Hallazgo | Severidad | Evidencia | Impacto | Recomendación |
| -- | -------- | --------- | --------- | ------- | ------------- |
| SEC-001 | Endpoint de máquinas sin policy por rol | media | `MachineController::index` sin `authorize`; solo `auth:sanctum` | Cualquier autenticado lista todas las máquinas | Añadir MachinePolicy y/o scope por plan/tenant futuro |
| SEC-002 | Dashboard sin policy explícita | baja | `DashboardController` solo filtra queries | Comportamiento correcto aparente, pero inconsistente con patrón policy | Usar policy o Gate documentado |
| SEC-003 | IDOR potencial entre tenants (hoy single-tenant global) | alta (diseño) | Sin `tenant_id`; binding por id numérico global | Con multi-empresa futura, IDs predecibles filtran datos | Diseño multi-tenant obligatorio antes de multi-empresa |
| SEC-004 | StoreAssignment sin unicidad / sin soft revoke API | media | `StoreAssignmentRequest` no unique; sin DELETE | Asignaciones duplicadas; no hay desasignación por API | Validar unique activo + endpoint de baja |
| SEC-005 | Progress sin tope `length` | baja | `depth_current` min:0 sin max|lte:length | Datos inconsistentes | Validar contra `drill_holes.length` |
| SEC-006 | Import confirm no importa datos | informativa | `CsvImportController@confirm` solo cambia status | Falsa sensación de integración | Completar pipeline o documentar como mock |
| SEC-007 | Platform/plan ids no validados conjuntamente al crear pozo | media | StoreDrillHoleRequest exists independientes | Pozo puede asociar plataforma de otro plan | Validar pertenencia plataforma→plan |
| SEC-008 | Policies Assignment/ProgressLog no usadas | baja | Dead code | Confusión en auditorías futuras | Eliminar o cablear explícitamente |
| SEC-009 | CORS con patterns `exp://` y credentials | media | `config/cors.php` | Amplio para móvil; revisar antes de producción | Restringir orígenes reales de producción |
| SEC-010 | Sanctum expiration null | media | `config/sanctum.php` | Tokens de larga vida | Definir expiración + rotación |
| SEC-011 | APP_DEBUG=true en .env.example | informativa | `.env.example` | Stack traces si se copia sin cambio | Recordar DISABLE en prod |
| SEC-012 | Health y login públicos | informativa | routes/api.php | Esperado; health sin rate limit aparente | Rate limit login |
| SEC-013 | Rate limiting API | no verificable / baja | No se observó `throttle` en rutas api | Fuerza bruta login | Añadir middleware throttle |
| SEC-014 | Mass assignment | baja | fillable acotado en modelos | Riesgo controlado aparentamente | Mantener fillable estricto; no `$guarded = []` |
| SEC-015 | Password no expuesto | informativa | User `$hidden` | — | OK estático |
| SEC-016 | Audit logs sin API de consulta ni scope | media | Modelo existe, sin rutas | Imposible auditar vía API; futuras fugas sin tenants | Endpoint lectura con authz + tenant |
| SEC-017 | PlanFile file_path expone paths relativos | baja | PlanFileResource | Info disclosure menor | Servir vía signed URLs |
| SEC-018 | createObservation permite type=risk sin campos risk | baja | StoreObservationRequest | Riesgos “incompletos” por puerta trasera | Restringir type o derivar a storeRisk |
| SEC-019 | Usuario soft-deleted | no verificable | SoftDeletes en User | Comportamiento login tras soft delete no inspeccionado en runtime | Prueba manual |
| SEC-020 | Close risk sin verificar asignación al pozo | media (diseño) | ObservationPolicy::closeRisk solo rol | Supervisor puede cerrar cualquier riesgo global | Valorar scope por plan/asignación |

## Endpoints sin autenticación (evidencia)

- `GET /api/health`
- `POST /api/auth/login`

Todas las demás rutas de `routes/api.php` están bajo `auth:sanctum` excepto el grupo anterior.

## Categorías

- **Vulnerabilidad evidente en código:** SEC-001 (authz ausente por rol), SEC-007 (integridad relacional), patrones IDOR globales (SEC-003) cuando se multiplique el tenant.
- **Riesgo de diseño:** tenancy, tokens sin expiración, import mock.
- **No verificable sin prueba manual:** soft-delete auth, CORS en dispositivos reales, rate limit efectivo.
