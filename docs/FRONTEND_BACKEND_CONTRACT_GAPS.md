# Brechas de contrato Frontend (Expo) ↔ Backend (Laravel)

**Fecha:** 2026-07-14  
**Frontend:** referencia funcional conocida (Expo Router, Zustand, mocks). **No se inspeccionó el repo Expo en esta tarea.**  
**Backend:** código actual de `mining-api`.

| Concepto | Frontend (conocido) | Backend (código) | Diferencia | Acción necesaria |
| -------- | ------------------- | ---------------- | ---------- | ---------------- |
| Fuente de datos | Mocks en memoria | REST Laravel | Desconectado | Cliente HTTP + auth Bearer |
| Auth | Mock roles | Sanctum token | Sin sesión móvil real | Login → guardar token |
| Roles | strings mock | UserRole enum: admin, supervisor, geologist, driller, helper, geotechnical | Alinear nombres; no existe “safety” | Mapa de roles en app |
| IDs | posiblemente string | bigint numérico JSON | Type mismatch | Normalizar a string o number en cliente |
| Wrapper JSON | variable | Resources → `{ data: ... }`; login excepcional `{ token, user }` | Adaptadores distintos | Tipar en apiClient |
| Planes | mocks nombre/mina | DrillingPlan fields + purpose/status enums | OK semejante | Mapear purpose/status |
| Plataformas | MDR700-* etc. | DrillingPlatform | Sin índice global `/drilling-platforms` | Usar nested por plan |
| Pozos | hole codes RLEK-* | `hole_id` string único + id PK | No usar hole_id como PK | Navegar por `id` numérico |
| Estados pozo | mocks | planned, in_progress, paused, completed, risk, cancelled | Verificar labels UI | Diccionario UI |
| Avances | mock logs | POST progress; **sin GET** list | UI puede necesitar GET | Usar show hole (carga progressLogs) o nuevo endpoint |
| Asignaciones | mock | POST only; sin GET/DELETE | Parcial | show hole trae assignments |
| Observaciones | mock types | ObservationType + body | Si UI usa type risk en obs, hay endpoint dedicado `/risks` | Separar flujos |
| Riesgos | tabla o entidad | Observation type=risk | Modelo unificado | Adaptar store |
| Severidad | mock | low/medium/high/critical | Alinear | |
| Máquinas | mock | GET /machines (sin authz rol) | Disponible | Scope futuro |
| Archivos | mocks presentación/resultados | PlanFile metadata; path mock | Sin download | Upload/download fase 2 |
| Import CSV | mock UI | preview/confirm mock sin crear pozos | No esperar datos reales tras confirm | Implementar import real |
| Auditoría | posible UI | sin endpoints lectura | No mostrar timeline vía API | Endpoint audit |
| Personal / users | lista mock | **sin GET /users** | Bloqueante para dropdowns | Endpoint users scoped |
| Paginasción | N/A o local | sin paginar | Riesgo volúmenes grandes | page/per_page |
| Decimales | number JS | string decimal Laravel frecuente | Parse float | |
| Fechas | ISO? | Carbon JSON ISO aparente | Prueba manual | |
| Errores | toasts | 401/403/422 Laravel | Mapear ValidationException | |
| Offline | Expo local | sin sync API | Fuera de alcance MVP API | Sync design + tenant |
| Multi-tenant | no | no | Ambos single-tenant | Roadmap shared |

## Endpoints útiles para reemplazar mocks (existentes)

1. Auth: login / me / logout  
2. Dashboard summary  
3. Drilling plans + platforms + files + holes  
4. Progress / assignments / observations / risks  
5. Machines (lectura)  

## Bloqueantes de integración

1. Sin listado de usuarios para asignaciones desde UI sin hardcode.  
2. Import no materializa pozos.  
3. Sin download real de archivos.  
4. Sin multi-tenant si varias empresas comparten app.  
5. IDs numéricos vs strings en rutas Expo.

**No se modificó frontend ni backend en esta auditoría.**
