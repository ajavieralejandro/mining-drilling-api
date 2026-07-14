# Planilla de resultados manuales Postman

**Instrucciones:** completar solo tras ejecución manual. No inventar resultados.

Estados: `pendiente` | `aprobado` | `falló` | `bloqueado` | `no aplica`

Plantilla de fila:

| ID | Fecha | Rol | Método | Endpoint | Payload | Esperado | Obtenido | Estado | Evidencia |
| -- | ----- | --- | ------ | -------- | ------- | -------- | -------- | ------ | --------- |

---

## Autenticación

| ID | Fecha | Rol | Método | Endpoint | Payload | Esperado | Obtenido | Estado | Evidencia |
| -- | ----- | --- | ------ | -------- | ------- | -------- | -------- | ------ | --------- |
| AUTH-01 | | | GET | /health | — | 200 status ok | | pendiente | |
| AUTH-02 | | admin | POST | /auth/login | email/password | 200 + token | | pendiente | |
| AUTH-03 | | | POST | /auth/login | password incorrecta | 422 | | pendiente | |
| AUTH-04 | | | GET | /auth/me | sin token | 401 | | pendiente | |
| AUTH-05 | | admin | GET | /auth/me | Bearer | 200 user | | pendiente | |
| AUTH-06 | | admin | POST | /auth/logout | Bearer | 200 message | | pendiente | |
| AUTH-07 | | | GET | /auth/me | token post-logout | 401 | | pendiente | |
| AUTH-08 | | inactive | POST | /auth/login | user active=false | 422 inactive | | pendiente | |

## Permisos

| ID | Fecha | Rol | Método | Endpoint | Payload | Esperado | Obtenido | Estado | Evidencia |
| -- | ----- | --- | ------ | -------- | ------- | -------- | -------- | ------ | --------- |
| PERM-01 | | driller | PATCH | .../technical-data | azimuth | 403 según código | | pendiente | |
| PERM-02 | | geologist | PATCH | .../technical-data | azimuth | 200 según código | | pendiente | |
| PERM-03 | | driller | POST | .../assignments | | 403 según código | | pendiente | |
| PERM-04 | | supervisor | POST | .../assignments | | 201 según código | | pendiente | |
| PERM-05 | | helper | POST | .../progress | | 403 según código | | pendiente | |
| PERM-06 | | driller assigned | POST | .../progress | | 201 según código | | pendiente | |
| PERM-07 | | helper assigned | POST | .../risks | | 201 según código | | pendiente | |
| PERM-08 | | geologist | PATCH | close-risk | | 403 según código | | pendiente | |
| PERM-09 | | driller | GET | hole no asignado | | 403 según código | | pendiente | |

## Planes

| ID | Fecha | Rol | Método | Endpoint | Payload | Esperado | Obtenido | Estado | Evidencia |
| -- | ----- | --- | ------ | -------- | ------- | -------- | -------- | ------ | --------- |
| PLAN-01 | | admin | GET | /drilling-plans | | lista | | pendiente | |
| PLAN-02 | | driller | GET | /drilling-plans | | filtrada | | pendiente | |
| PLAN-03 | | admin | GET | /drilling-plans/{id} | | detalle | | pendiente | |

## Plataformas

| ID | Fecha | Rol | Método | Endpoint | Payload | Esperado | Obtenido | Estado | Evidencia |
| -- | ----- | --- | ------ | -------- | ------- | -------- | -------- | ------ | --------- |
| PLAT-01 | | admin | GET | /drilling-plans/{id}/platforms | | lista | | pendiente | |
| PLAT-02 | | admin | GET | /drilling-platforms/{id} | | detalle | | pendiente | |

## Pozos

| ID | Fecha | Rol | Método | Endpoint | Payload | Esperado | Obtenido | Estado | Evidencia |
| -- | ----- | --- | ------ | -------- | ------- | -------- | -------- | ------ | --------- |
| HOLE-01 | | admin | GET | /drill-holes | | lista | | pendiente | |
| HOLE-02 | | admin | GET | /drill-holes/{id} | | detalle | | pendiente | |
| HOLE-03 | | geologist | POST | /drill-holes | body válido | 201 | | pendiente | |

## Asignaciones

| ID | Fecha | Rol | Método | Endpoint | Payload | Esperado | Obtenido | Estado | Evidencia |
| -- | ----- | --- | ------ | -------- | ------- | -------- | -------- | ------ | --------- |
| ASG-01 | | supervisor | POST | .../assignments | user_id+role | 201 | | pendiente | |

## Avances

| ID | Fecha | Rol | Método | Endpoint | Payload | Esperado | Obtenido | Estado | Evidencia |
| -- | ----- | --- | ------ | -------- | ------- | -------- | -------- | ------ | --------- |
| PRG-01 | | driller | POST | .../progress | depths | 201 + current_depth | | pendiente | |

## Observaciones

| ID | Fecha | Rol | Método | Endpoint | Payload | Esperado | Obtenido | Estado | Evidencia |
| -- | ----- | --- | ------ | -------- | ------- | -------- | -------- | ------ | --------- |
| OBS-01 | | geologist | GET | .../observations | | lista | | pendiente | |
| OBS-02 | | helper | POST | .../observations | type+body | 201 | | pendiente | |

## Riesgos

| ID | Fecha | Rol | Método | Endpoint | Payload | Esperado | Obtenido | Estado | Evidencia |
| -- | ----- | --- | ------ | -------- | ------- | -------- | -------- | ------ | --------- |
| RSK-01 | | admin | GET | /risks | | lista | | pendiente | |
| RSK-02 | | helper | POST | .../risks | body+level | 201 | | pendiente | |
| RSK-03 | | supervisor | PATCH | close-risk | | closed | | pendiente | |

## Importaciones

| ID | Fecha | Rol | Método | Endpoint | Payload | Esperado | Obtenido | Estado | Evidencia |
| -- | ----- | --- | ------ | -------- | ------- | -------- | -------- | ------ | --------- |
| IMP-01 | | admin | POST | /imports/preview | filename | previewed | | pendiente | |
| IMP-02 | | admin | POST | /imports/confirm | import_id | confirmed | | pendiente | |
| IMP-03 | | admin | GET | /imports | | lista | | pendiente | |

## Auditoría

| ID | Fecha | Rol | Método | Endpoint | Payload | Esperado | Obtenido | Estado | Evidencia |
| -- | ----- | --- | ------ | -------- | ------- | -------- | -------- | ------ | --------- |
| AUD-01 | | | GET | /audit-events | | **no aplica** (ruta no existe) | | no aplica | |

## Multi-tenancy futuro

| ID | Fecha | Rol | Método | Endpoint | Payload | Esperado | Obtenido | Estado | Evidencia |
| -- | ----- | --- | ------ | -------- | ------- | -------- | -------- | ------ | --------- |
| TEN-01 | | | | contratos futuros | | **no aplica** hasta implementar | | no aplica | |
