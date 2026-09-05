# UndSurf — Handoff P0/P1 para Wilson

**Fecha:** 2026-09-05  
**Para:** Wilson  
**De:** cierre de jornada (sábado)  
**Repos:** `mining-drilling-api` (este documento), `undersurf-connector`, `mining-drilling`  
**Deploy:** no realizado. Reservado para el lunes.

---

## 1. Resumen ejecutivo

UndSurf ya tiene operativo el primer circuito real de datos del cliente, de punta a punta:

```text
App
→ API Laravel
→ Connector Go
→ Store / DB del cliente
→ Connector
→ API Laravel
→ App
```

Caso de uso cerrado:

```text
listar pozos
GET /api/tenant/holes
operación Connector: drill_holes.list@1
```

El tenant lo resuelve el servidor a partir de la membership activa del usuario autenticado. El cliente **no** elige tenant. La base del cliente **no** vive en Laravel: el Connector Go, en infraestructura del cliente, es el único proceso que habla con esa DB.

P0 está cerrado. P1 (hardening mínimo sobre ese mismo circuito) está técnicamente consolidado en `origin/main`. El lunes toca gate final + smoke test real + deploy. No hay funcionalidad nueva abierta (avance, riesgos, turnos, pantallas nuevas).

---

## 2. Estado de P0

```text
P0 — CERRADO
```

Qué garantiza:

- El flujo App → Laravel → Connector → DB cliente → Laravel → App funciona para `listar pozos`.
- El aislamiento entre tenants es estructural (un Connector / una DB por cliente), no un filtro sobre una tabla compartida.
- Un usuario del tenant A no despacha comandos al Connector del tenant B.
- Fallas controladas: Connector ausente → `503 CONNECTOR_OFFLINE`; Connector que no responde a tiempo → `504 TIMEOUT`.
- La app móvil de pozos reales consume `/api/tenant/holes` (sin enviar `tenant_id`).

Commit P0: `86bd723` — `feat(connector): wire tenant holes through Data Gateway (drill_holes.list@1)`.

---

## 3. Estado de P1

```text
P1 — CERRADO a nivel técnico (código + tests en origin/main)
P1 — pendiente de gate adversarial final + smoke + deploy (lunes)
```

### Paso 1 — Cierre del endpoint demo (`653a05a`)

`POST /api/internal/demo/list-holes` responde **404** fuera de `local`/`testing`. No reabrir. El token estático `DEMO_INTERNAL_TOKEN` no es una superficie de tenant en entornos no locales.

### Paso 2 — Correlación (`8f10c40`)

- `request_id`: lo genera el servidor al crear el `ConnectorCommand`. El cliente no lo impone. Es la clave de matching en `/results`.
- `correlation_id`: identificador HTTP. Opcional vía `X-Correlation-Id` (token opaco acotado); si falta o es inválido, Laravel genera uno.
- Ambos vuelven en 200 y en errores de Gateway cuando existen.
- `heartbeat` / `poll` / `results` son HTTP **distintos** (el Connector llama a Laravel). Un 401 de bearer del Connector **no** lleva el `request_id` de una request de usuario. Eso es el modelo actual, no un bug.

### Paso 3 — Tenant server-side (`8fb5cdf`)

Tests adversariales: spoofing por query, body, headers y combinado. El `ConnectorCommand` creado siempre nombra el tenant de la membership, nunca el valor que manda el cliente. No hizo falta cambiar código de producción en ese paso.

### Paso 4 — Autenticación Connector → Laravel (`eba0d9a`)

Canal `Authorization: Bearer <connector_token>` sobre `/connector/v1/{heartbeat,poll,results}`. Cubierto: ausente, vacío, malformado, incorrecto. El plaintext se devuelve una sola vez en enroll; en DB solo el hash SHA-256 (`$hidden` en el modelo).

### FASE 2 — Contrato de errores (`10ec5e5`)

`/api/*` responde JSON aunque no haya `Accept: application/json` (antes: 500 HTML por `Route [login] not defined`).

| Situación | HTTP | code |
|---|---|---|
| Usuario no autenticado | 401 | `UNAUTHENTICATED` |
| Sin membership activa | 403 | `NO_ACTIVE_MEMBERSHIP` |
| Recurso ausente en la DB del tenant | 404 | `NOT_FOUND` |
| Payload inválido del Connector | 422 | `INVALID_PAYLOAD` |
| Fallo / result `ok` malformado | 502 | `ADAPTER_ERROR` / `INVALID_RESULT` |
| Connector no disponible | 503 | `CONNECTOR_OFFLINE` |
| Timeout | 504 | `TIMEOUT` |
| Error interno | 500 | `INTERNAL_ERROR` |

Forma:

```json
{
  "request_id": null,
  "correlation_id": "...",
  "error": { "code": "...", "message": "..." }
}
```

`request_id` es `null` hasta que existe un command. Un SQL u otra `RuntimeException` desconocida **no** se copia a `error.code`. Un list `ok` sin `items` lista válida es 502, no 200 vacío.

---

## 4. Seguridad incorporada

- Tenant resuelto solo desde `activeMembership()` (server-side).
- Spoofing de `tenant_id` / headers / body ignorado en `/api/tenant/holes`.
- Bearer del Connector; hash never serializes.
- Errores de API sin stack, SQL, rutas internas ni secretos.
- JSON consistente en `/api/*` con o sin `Accept`.
- `correlation_id` también en 401/403 del flujo tenant.
- Endpoint demo cerrado fuera de local/testing.
- Resultado upstream de `drill_holes.list@1` validado (shape `{items:[{id,code,status},...]}`; lista vacía válida).
- `APP_KEY` de testing **ya no** se versiona en `phpunit.xml` (ver §5).

---

## 5. APP_KEY detectado por GitGuardian

- GitGuardian alertó un Laravel `APP_KEY` en `phpunit.xml`.
- Era la clave que PHPUnit usaba para **testing** (mismo patrón que el skeleton de Laravel). No hay evidencia de que sea la clave de producción, y **este ciclo todavía no se desplegó**.
- No se asume exposición de producción.
- Se eliminó del estado actual del repo: `phpunit.xml` ya no declara `APP_KEY`. `Tests\TestCase` genera una clave **efímera** por proceso si el entorno no aporta una. CI puede inyectar `APP_KEY` por variable de entorno. `.env.example` solo tiene placeholder vacío.
- El valor histórico **sigue en commits anteriores**. GitGuardian puede seguir alertando el hallazgo histórico hasta que se evalúe una limpieza de history (no se reescribe Git hoy).
- El valor concreto **no** se reproduce en esta documentación.

---

## 6. Tests

Cierre de esta jornada (`php artisan test`):

```text
Laravel: 56 passed (278 assertions)
```

Baseline inmediatamente anterior a la limpieza de `APP_KEY`:

```text
Laravel: 55 passed (275 assertions)
```

El delta es el test que impide volver a embeber `APP_KEY` en `phpunit.xml`.

```text
go test ./...  → PASS
```

P0 (aislamiento, 503, 504, demo 404) sigue cubierto por la misma suite.

---

## 7. Git (`mining-drilling-api`, branch `main`)

| Commit | Qué |
|---|---|
| `86bd723` | P0 — Data Gateway `drill_holes.list@1` |
| `653a05a` | P1 Paso 1 — demo 404 fuera de local/testing |
| `8f10c40` | P1 Paso 2 — correlación |
| `8fb5cdf` | P1 Paso 3 — tests de aislamiento |
| `eba0d9a` | P1 Paso 4 — bearer Connector |
| `10ec5e5` | P1 FASE 2 — contrato de errores |
| `52c16bc` | quitar `APP_KEY` versionado + test de `phpunit.xml` |
| *(este documento)* | handoff Wilson / deploy lunes |

```text
stash legacy intacto
stash@{0}: On main: fuera de alcance P0: refactor repo-pattern legacy (avance/riesgos) + progress-logs endpoint
```

No se hizo `stash pop` ni `stash drop`. No se reescribió history. No hay force push.

---

## 8. Qué NO se hizo

```text
NO deploy
NO cambios en producción
NO nuevas funcionalidades
NO selector multi-tenant
NO provisioning avanzado de Connectors
NO rotación/revocación operativa de credenciales Connector
NO drilling_progress
NO risks
NO turnos
NO reescritura de history Git
NO limpieza del APP_KEY histórico en commits viejos
```

---

## 9. Pendientes para el lunes

### Gate final (antes de tocar el servidor)

- Auditoría adversarial final del circuito P0/P1 junto (no solo paso a paso).
- Smoke test real: App → Laravel → Connector vivo → DB cliente → respuesta 200.
- Revisión de logs en esa corrida (sin Bearer, sin DSN, sin `APP_KEY`).
- Confirmar que el `APP_KEY` **de producción** (cuando exista) no es el valor que estuvo en `phpunit.xml`, y rotarlo si hubiera duda.
- No hay CI GitHub Actions en este repo todavía: el gate es local + el smoke del lunes.

### Deploy (solo después del gate)

Preparar, en este orden:

```text
Laravel API
Connector Go
configuración .env (APP_KEY generado en el servidor, nunca copiado de phpunit.xml)
migraciones
caches
servicios (php-fpm / process manager del Connector)
dominio / API si corresponde
```

Luego probar de extremo a extremo:

```text
App → Laravel → Connector → DB cliente → Connector → Laravel → App
```

Limitación ya conocida de desarrollo local: `php artisan serve` es un solo worker; `dispatch()` + `waitForResult()` bloquea. En local se usaron dos procesos. Un app server real (PHP-FPM, etc.) no necesita ese workaround; **eso no está verificado en producción** porque no hubo deploy.

---

## 10. Decisiones futuras (no son bugs de P1)

Tratarlas aparte, con el socio, cuando toque:

| Tema | Estado |
|---|---|
| Usuarios en varios tenants a la vez (`activeMembership()` sin `orderBy`) | Ambiguo si hay dos memberships `active`. No es spoofing. |
| Provisioning de Connectors | Hoy: comando Artisan + enrollment token de un solo uso. |
| Rotación / revocación | El dato `status=revoked` existe; no hay flujo operativo. |
| Varios Connectors por cliente | El código toma el de `last_seen_at` más reciente. |
| Quién configura la DB del cliente | Credencial solo en el Connector, nunca en Laravel. |
| mTLS | Fuera de alcance P1. El Connector no autentica al control plane hoy. |

---

## Cómo retomar el lunes

1. `git pull` en `mining-drilling-api` (`main`) y confirmar HEAD = `origin/main`.
2. `php artisan test` y `go test ./...` como smoke de suite.
3. Gate adversarial + smoke con Connector y Postgres reales.
4. Recién entonces deploy. No antes.
