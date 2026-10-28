# UndSurf — Laboratorio local del listado de pozos — 2026-10-01

**Alcance:** circuito real en loopback, `GET /api/tenant/holes`. Sin commit, push, merge, deploy ni contacto con `api.undsurf.com`. La app móvil no se modificó y no se implementó `/progress`.

**Producción no fue verificada.**

## Semáforo

| Pieza | Estado |
|---|---|
| PostgreSQL de laboratorio en `127.0.0.1:5432` | Verde. Tres filas ficticias, rol `undersurf_ro` con solo `SELECT` |
| Control plane Laravel en SQLite | Verde. `DB_CONNECTION=sqlite`, sin `UNDSURF_PG_DSN` |
| Connector enrolado y leyendo esa base | Verde |
| `GET` sin token | Verde. 401 `UNAUTHENTICATED` |
| `GET` con membership activa | Verde. 200 con las tres filas, `request_id` y `correlation_id` |
| Connector detenido, pasado el TTL de 60 s | Verde. 503 `CONNECTOR_OFFLINE` |
| Reinicio del Connector | Verde. Vuelve a 200 sin un segundo enrolamiento |
| `php artisan test` | Verde. 72 passed, 317 assertions |
| `go test -vet=off ./...` | Verde en los paquetes que tienen tests. `-vet=off` no ejecuta `go vet` y no lo valida |
| Un solo `php artisan serve` | No alcanza en Windows: el proceso es de un solo hilo y el listado expira en 504. El laboratorio usa tres workers detrás de un proxy en el puerto 8010 |

## 1. Git al empezar

| Repo | HEAD | Working tree | Remoto |
|---|---|---|---|
| `mining-drilling-api` | `e6c3f30` en `main`, igual a `origin/main` | Limpio. Stash `stash@{0}` conservado, no aplicado | El de GitHub, sin cambios en esta sesión |
| `undersurf-connector` | `1d16a5c` en `master` | Limpio | No tiene |

`e6c3f30` (`fix: read sqlite busy timeout and journal mode from the environment`) ya estaba en `origin/main` antes de esta corrida. Esta sesión no creó commits. El `.env` local del API define `DB_BUSY_TIMEOUT=5000` y `DB_JOURNAL_MODE=WAL`. Esas claves no están en git.

## 2. Configuración

Todo escucha solo en `127.0.0.1`.

| Servicio | Puerto | Notas |
|---|---|---|
| PostgreSQL 17 | 5432 | Datos en `%LOCALAPPDATA%\undsurf-lab\pgdata`. No está dentro del repositorio. Binarios: `C:\Program Files\PostgreSQL\17` |
| Proxy Node | 8010 | Reparte hacia los workers con menos conexiones activas. Script en `%LOCALAPPDATA%\undsurf-lab\proxy.js` |
| `php artisan serve` | 8011, 8012, 8013 | Tres procesos. El cliente y el Connector hablan con el 8010 |
| Connector | solo sale hacia 8010 y 5432 | `UNDSURF_CONTROL_PLANE_URL=http://127.0.0.1:8010` |

El puerto público del API es **8010**, el mismo que usa por defecto la app móvil. No se tocó la app.

PostgreSQL 17 ya estaba instalado, con el directorio de datos del instalador vacío y sin servicio de Windows. El clúster de laboratorio se inicializó con `initdb` en el perfil del usuario y `listen_addresses = '127.0.0.1'`.

Base cliente: `undersurf_client_lab`. Tabla `demo_drill_holes` cargada con `undersurf-connector/docs/lab_postgres.sql`:

| id | code | status |
|---|---|---|
| 1001 | RLEK-01 | in_progress |
| 1002 | RLEK-02 | planned |
| 1003 | RLEK-03 | completed |

El SQL del repo crea `undersurf_ro` con una contraseña de ejemplo. Esa contraseña se reemplazó fuera de git. El rol solo tiene `SELECT` sobre `demo_drill_holes`. El DSN vive en `undersurf-connector/.env`, ignorado por git, con la forma `postgres://<user>:<password>@127.0.0.1:5432/<database>`. No está en el `.env` de Laravel.

Laravel: `APP_ENV=local`, `DB_CONNECTION=sqlite`. La migración pendiente `2026_09_12_000001_add_lifecycle_fields_to_memberships_table` se aplicó sobre ese SQLite en la preparación del laboratorio. `php artisan tinker` confirmó `env=local`, `db=sqlite`, `journal=wal`, `busy=5000`, TTL del Connector `60`.

Usuario de prueba ya existente, creado antes por `TenantVerticalSliceSeeder` (no se inventó otro alta ni se volvió a sembrar):

- Tenant de laboratorio, nombre `Minera Demo A`
- `supervisor-a@undsurf.test`, activo, una sola membership `active` con rol `supervisor`

El enrollment token de un solo uso se emitió con `php artisan connector:issue-enrollment-token --tenant=<tenant_id> --hours=24`. El valor del token no se copia aquí. La identidad quedó en `undersurf-connector/data/identity.json` (git la ignora). El log de arranque redacta el DSN y no se transcriben los identificadores:

```text
control_plane=http://127.0.0.1:8010 pg_dsn=postgres://<user>:<password>@127.0.0.1:5432/<database>?sslmode=disable
enrolled connector_id=<connector_id> tenant_id=<tenant_id>
session opened session_id=<session_id>
```

El binario Go no lee `.env` por su cuenta. El arranque exporta ese archivo al proceso y luego ejecuta `bin\connector.exe`.

## 3. Por qué hacen falta tres workers

`php artisan serve` atiende un request a la vez. `GET /api/tenant/holes` se queda hasta 10 s en `CommandDispatcher::waitForResult` esperando al Connector, y el Connector necesita otro request (`/api/connector/v1/poll`) para enterarse del comando. Con un solo proceso, el poll queda detrás de esa espera y el listado responde 504 `TIMEOUT`.

Eso ocurrió en el primer intento autenticado de esta sesión, con un proxy de turnos. El Connector igual ejecutó la consulta (log `result sent ... status=ok`) pero el resultado llegó cuando Laravel ya había cerrado el comando como timeout. No se insertó ningún resultado a mano.

El proxy se cambió para elegir el worker con menos requests en curso. A partir de ahí el circuito completó solo.

## 4. Evidencia HTTP

Base: `http://127.0.0.1:8010`.

### Sin token

`GET /api/tenant/holes` → **401**

```json
{"request_id":null,"correlation_id":"cor_01M3WANPCDP2HV76XSAXK7SDEX","error":{"code":"UNAUTHENTICATED","message":"Authentication required."}}
```

### Con token y membership activa

Login de `supervisor-a@undsurf.test` contra `/api/auth/login`. El token no se registra.

`GET /api/tenant/holes` → **200** en 835 ms

```json
{"data":[{"id":"1001","code":"RLEK-01","status":"in_progress"},{"id":"1002","code":"RLEK-02","status":"planned"},{"id":"1003","code":"RLEK-03","status":"completed"}],"request_id":"req_01M3WAV57SNPZ7KVE7J7ZSNXV4","correlation_id":"cor_01M3WAV54QD7AAVKN9AZZXMSH5"}
```

Esas tres filas son las de `demo_drill_holes`. Trazabilidad del mismo `request_id`:

| Dónde | Qué quedó |
|---|---|
| `connector_commands` | `status=completed`, `op=drill_holes.list@1`, mismo `correlation_id`, tenant del usuario autenticado |
| `platform_audit_logs` | eventos `dispatched` y `completed` |
| `logs/connector-audit.jsonl` | `adapter=postgres`, `status=ok`, `duration_ms=3`, `item_count=3` |
| stderr del Connector | `result sent`, mismo `request_id`, `status=ok` |

### Connector detenido

Proceso detenido a las 20:15:49 (+02). `CONNECTOR_ONLINE_TTL_SECONDS=60` (`Connector::isOnline`). Consulta a las 20:17:22, unos 93 s después.

`GET /api/tenant/holes` autenticado → **503**

```json
{"request_id":null,"correlation_id":"cor_01M3WAYX4GJYC1RD7B9BA81TGQ","error":{"code":"CONNECTOR_OFFLINE","message":"Gateway dispatch failed"}}
```

`request_id` es null porque el despacho ni siquiera crea el comando cuando el Connector ya no cuenta como en línea.

### Recuperación

Nuevo proceso, misma identidad, sin enrolar otra vez:

```text
starting connector_id=<connector_id>
session opened session_id=<session_id>
```

`GET /api/tenant/holes` → **200** en 1109 ms, las mismas tres filas.

```json
{"data":[{"id":"1001","code":"RLEK-01","status":"in_progress"},{"id":"1002","code":"RLEK-02","status":"planned"},{"id":"1003","code":"RLEK-03","status":"completed"}],"request_id":"req_01M3WB08M04D58M12BEXNFG0FJ","correlation_id":"cor_01M3WB08FQYDQG2SFTZBJSG0MA"}
```

## 5. Tests

```text
php artisan test
Tests:    72 passed (317 assertions)
Duration: 10.64s
```

SQLite en memoria (`phpunit.xml`). No usa la base del laboratorio.

```text
go test -vet=off ./...
ok  internal/adapter/postgres
ok  internal/audit
ok  internal/config
ok  internal/ops
```

`-vet=off` desactiva el análisis de `go vet`. Esta corrida no valida `go vet`.

Sin tests: `cmd/connector`, `internal/channel`, `internal/identity`, `internal/protocol`. El test de Postgres que sí existe abre un DSN inválido en `127.0.0.1:1`; no toca `undersurf_client_lab`. En esta corrida no hubo bloqueo de Control de aplicaciones.

## 6. Archivos y git al cerrar

No hay diff de código en esta sesión.

| Ruta | Git |
|---|---|
| `mining-drilling-api/.env` | Ignorado. Sigue en sqlite. Añadidas `DB_BUSY_TIMEOUT` y `DB_JOURNAL_MODE`. Sin `UNDSURF_PG_DSN` |
| `mining-drilling-api/config/database.php` | Ya commiteado en `e6c3f30`, sin cambios nuevos |
| `undersurf-connector/.env` | Ignorado. URL `http://127.0.0.1:8010` y DSN local |
| `undersurf-connector/data/identity.json` | Ignorado |
| `undersurf-connector/bin/connector.exe` | Ignorado |
| `undersurf-connector/logs/` | Ignorado |
| `%LOCALAPPDATA%\undsurf-lab\` | Fuera del repo: datos de Postgres, proxy, contraseña del rol |

```text
mining-drilling-api: ## main...origin/main
undersurf-connector: ## master
stash@{0} sigue presente y sin aplicar
```

## 7. Cómo volver a levantarlo y cómo pararlo

Los procesos de abajo quedaron en marcha al cerrar este informe.

Arranque, si hiciera falta repetirlo:

```powershell
& "C:\Program Files\PostgreSQL\17\bin\pg_ctl.exe" -D "$env:LOCALAPPDATA\undsurf-lab\pgdata" -l "$env:LOCALAPPDATA\undsurf-lab\postgres.log" -w start

# En mining-drilling-api, un proceso por puerto:
php artisan serve --host=127.0.0.1 --port=8011
php artisan serve --host=127.0.0.1 --port=8012
php artisan serve --host=127.0.0.1 --port=8013

node "$env:LOCALAPPDATA\undsurf-lab\proxy.js"

# En undersurf-connector. El binario no lee .env solo.
# Exportar al proceso, sin pegar secretos:
#   UNDSURF_CONTROL_PLANE_URL=http://127.0.0.1:8010
#   UNDSURF_PG_DSN=postgres://<user>:<password>@127.0.0.1:5432/<database>
#   UNDSURF_ENROLLMENT_TOKEN=<enrollment_token>   # solo si todavía no hay identidad
.\bin\connector.exe
```

Parada:

```powershell
Get-Process connector -ErrorAction SilentlyContinue | Stop-Process
foreach ($p in 8010,8011,8012,8013) {
  $own = Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue |
    Select-Object -ExpandProperty OwningProcess -Unique
  if ($own) { Stop-Process -Id $own -Force }
}
& "C:\Program Files\PostgreSQL\17\bin\pg_ctl.exe" -D "$env:LOCALAPPDATA\undsurf-lab\pgdata" stop
```

PIDs observados al escribir este documento: Postgres `13776`, proxy `24084`, workers `26716` / `30180` / `16844`, Connector `11384`. Pueden cambiar si se reinician.

## 8. Pendientes

- Producción no se probó. Este 200 no dice nada de `api.undsurf.com`.
- `GET /api/tenant/holes/{id}/progress` sigue sin ruta en Laravel. Esta sesión no lo implementó.
- La app móvil no se abrió. Su URL por defecto ya es el puerto 8010; falta un recorrido manual de `/pozos-real` contra este laboratorio.
- Un único `php artisan serve` no puede cerrar el circuito en Windows. Cualquier repetición tiene que usar el proxy o un servidor con más de un worker.
- Sigue pendiente la revalidación de usuario activo y membership en cada request. El listado de este laboratorio no la cubre.

## 9. Próximo paso

Revalidar `users.active` y la membership activa en cada request autenticada del API, con tests, sin tocar este laboratorio ni producción. El circuito de listado ya tiene una base local contra la cual no romper el 200.
