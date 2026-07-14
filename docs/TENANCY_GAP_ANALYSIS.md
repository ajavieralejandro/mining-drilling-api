# Análisis de brechas multi-tenant — Mining Drilling API

**Fecha:** 2026-07-14  
**Búsqueda:** `tenant`, `tenant_id`, `company`, `company_id`, `organization`, `organization_id`, `landlord`, `membership`, `active_tenant`, `central`, `database_name`, `domain`, `subdomain` en `app/` y `database/`.

## Estado actual

**Clasificación: sin tenancy.**

No hay coincidencias en modelos, migraciones, middleware, policies ni servicios. El sistema es un **tenant único implícito**: todos los usuarios autenticados operan sobre el mismo conjunto global de planes/pozos (con filtros por rol/asignación, no por empresa).

## Brechas

| Capacidad | ¿Existe? |
| --------- | -------- |
| Base landlord | no |
| Tabla tenants | no |
| Usuarios centrales vs por empresa | no |
| Memberships | no |
| Rol por empresa | no (rol global en `users.role`) |
| Empresa activa | no |
| Resolución de tenant (header/subdomain) | no |
| Conexión dinámica / schema / DB por tenant | no |
| Filesystem por tenant | no (`FILESYSTEM_DISK=local` global) |
| Cache / queues por tenant | no (drivers globales database) |
| Auditoría con tenant | no (`audit_logs` sin tenant_id) |
| Revocación de membresías | no |

## Riesgo de producto

Diferentes empresas mineras **no** pueden compartir esta API con aislamiento estricto hoy. Cualquier dato sembrado es visible (según rol) en el mismo universo de IDs.

## Comparación de arquitecturas (recomendación; no implementada)

| Opción | Pros | Contras | Ajuste minero |
| ------ | ---- | ------- | ------------- |
| 1. DB compartida + `tenant_id` | Simple, menos ops | Riesgo filtración por bug de query; backups mixtos | OK para MVP multi-empresa temprano **si** scopes estrictos + tests |
| 2. Schema PostgreSQL por tenant | Aislamiento medio; un cluster | Migraciones N schemas; tools | Compromiso razonable |
| 3. **Base PostgreSQL por tenant** | Backups/restauración por empresa; menor riesgo de leakage | Más costo ops; pooling | **Recomendada** para confidencialidad minera y offline sync por contrato |
| 4. Despliegue independiente | Máximo aislamiento | Peor mantenimiento | Solo clientes regulados |

### Recomendación propuesta

Adoptar **base landlord (usuentes, memberships, tenants)** + **base de datos PostgreSQL por tenant** para datos operativos (planes, pozos, observaciones, archivos, auditoría).

Flujo futuro:

1. Login central → lista memberships.  
2. Selección de empresa activa (servidor emite token scoped o claim `tenant_id` **firmado**, no confiar en body del APK).  
3. Middleware resuelve conexión tenant.  
4. Policies + global scopes refuerzan.  
5. Filesystem prefix `/tenants/{id}/`.  
6. Jobs/queues etiquetados por tenant.

### Regla futura (obligatoria)

> El backend **no** deberá confiar en un `tenant_id` arbitrario enviado por el APK. El tenant debe resolverse desde el token/sesión autenticada y membership vigente.

## Pruebas futuras

Ver carpeta Postman `17 Multi-Tenant Future Tests` — marcadas **NO IMPLEMENTADO**.
