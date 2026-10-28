# Runbook — login del Gateway y listado de pozos ficticios — 2026-10-06

Procedimiento para una corrida futura. Esta actividad no lo ejecutó y no contactó el servidor.

El circuito que se quiere habilitar es: usuario con membership activa hace `POST /api/auth/login`, después `GET /api/tenant/holes` y, para el detalle, `GET /api/tenant/holes/{id}`. Laravel despacha `drill_holes.list@1` y `drill_holes.get@1`. El Connector lee `demo_drill_holes`.

Hay dos motores. No se elige uno reescribiendo `UNDSURF_PG_DSN`.

- **MySQL, camino de la beta mínima.** Usa el MySQL que el informe del 2026-09-09 ya registró en el servidor (8.0.46, no reconsultado después). No instala PostgreSQL. El bloque M describe ese camino. Hace falta la rama `feat/mysql-lab-read-support` del Connector, no el binario que hoy está en `/opt`.
- **PostgreSQL, alternativa.** Los bloques B y C instalan PostgreSQL cuando `sudo` esté recuperado y siguen siendo válidos para un Connector en motor `postgres`.

El detalle `GET /api/tenant/holes/{id}` entra en el bloque G cuando el motor es MySQL. Con PostgreSQL el mismo bloque G cubre el listado; el detalle usa la misma sesión y la ruta que ya existe en `ee54d0a`.

Contratos leídos en el código local, no en el servidor:

- API desplegado según el reporte del usuario: commit `ee54d0a`. Ese árbol está en el worktree local `feat/request-access-revalidation`.
- Connector local: `undersurf-connector` `master` `1d16a5c`, sin remoto. El binario de `/opt/undsurf-connector/undersurf-connector` no quedó identificado contra ese commit.
- `undersurf-connector/docs/RUNBOOK_LOCAL.md` no es este procedimiento. Allí se usa `php artisan serve`, `POST /api/internal/demo/list-holes` y un DSN de ejemplo. En `ee54d0a`, ese endpoint responde 404 si el entorno no es `local` ni `testing`. No arrancar `php artisan serve` en el servidor. No llamar al endpoint demo.

Cada bloque se ejecuta en Bash del servidor, como `deployer`, salvo que el bloque diga `sudo`. Ningún bloque se ejecuta en el PowerShell local. No tocar el `.env` local, el stash `stash@{0}` de `mining-drilling-api`, ni el laboratorio de loopback.

Secretos: se escriben solo bajo `/home/deployer/undsurf-private/`, modo `600`, fuera de Git. No pegarlos en el chat, en el log del informe ni en la historia de shell. Antes de leer una contraseña: `set +o history`. Después: `unset` de la variable y `set -o history`.

Detenerse significa no seguir al bloque siguiente. No improvisar `composer update`, seeders, `config:cache` ni un restore del dump sobre la base en uso.

Archivos de esta preparación y su SHA-256 en el working tree local:

| Archivo | SHA-256 |
|---|---|
| `docs/sql/lab-listado-pozos-2026-10-06.sql` | `f17f0e6158ab66c9373c6048fd301699b8e66823d74c7b21f079dba23a375d1d` |
| `docs/lab-listado/activar-usuario.php` | `268534c05d456aa955366b216af090f76ed2fc1e12fc46c59330e546b6708163` |
| `docs/lab-listado/revertir-membership.php` | `ecdd609f145f54855318bdcc7635c59b54bbd8ae98da1dac57b5146c04accb81` |

Si al copiarlos cambia el hash, detenerse. No aplicar un archivo distinto.

---

## A. Comprobaciones previas y respaldo

**Dónde:** Bash en el servidor.

**Prerrequisitos:** sesión SSH como `deployer`. No hace falta `sudo` para leer. El cierre del bloque comprueba si `sudo` ya responde.

**Resultado esperado:** el checkout sigue en el SHA reportado, el dump mide 43035 bytes y declara el cierre de `mysqldump`, el binario contiene `drill_holes.list@1`, y no hay un Connector ni un PostgreSQL ya en marcha. `sudo -n true` termina en 0.

**Detenerse si:** el HEAD, la rama o el árbol no coinciden; hay stash; el driver no es `mysql`; el dump no está o no mide 43035 bytes; no aparece la marca de dump completado; el binario no es ejecutable o no contiene `drill_holes.list@1`; ya hay un proceso del Connector o de PostgreSQL; algún usuario tiene más de una membership `active`.

```bash
set -euo pipefail
umask 077
mkdir -p /home/deployer/undsurf-private
cd /var/www/undsurf/mining-drilling-api

git rev-parse --abbrev-ref HEAD
git rev-parse HEAD
git status -sb
git stash list

php artisan migrate:status
php artisan db:table memberships --json

php artisan tinker --execute='
$c = config("database.default");
echo $c.PHP_EOL;
echo config("database.connections.".$c.".driver").PHP_EOL;
echo config("database.connections.".$c.".database").PHP_EOL;
echo config("connector.protocol_version").PHP_EOL;
echo config("connector.online_ttl_seconds").PHP_EOL;
echo config("connector.demo_command_timeout_seconds").PHP_EOL;
echo App\Models\Membership::query()->selectRaw("status, count(*) as n")->groupBy("status")->get()->toJson().PHP_EOL;
echo Illuminate\Support\Facades\DB::table("memberships")->select("user_id")->where("status", "active")->groupBy("user_id")->havingRaw("count(*) > 1")->get()->count().PHP_EOL;
'

DUMP=/home/deployer/undsurf-backups/undsurf-20261006-092536.sql
TAR=/home/deployer/undsurf-backups/20261002-120459/backend-files.tar.gz
stat -c '%s %a %n' "$DUMP" "$TAR"
sha256sum "$DUMP" "$TAR"
completed="$(grep -c '^-- Dump completed' "$DUMP" || true)"
test "$completed" = "1"

test -x /opt/undsurf-connector/undersurf-connector
file /opt/undsurf-connector/undersurf-connector
grep -a -F -o 'drill_holes.list@1' /opt/undsurf-connector/undersurf-connector | sort -u
grep -a -F -o 'drill_holes.get@1' /opt/undsurf-connector/undersurf-connector | sort -u || true
grep -a -F -o 'progress.create@1' /opt/undsurf-connector/undersurf-connector | sort -u || true
grep -a -F -o 'progress.list@1' /opt/undsurf-connector/undersurf-connector | sort -u || true

command -v psql || true
command -v docker || true
command -v go || true
ps -ef | grep -E '[u]ndersurf-connector|[p]ostgres' || true

curl --silent --show-error \
  --header 'Accept: application/json' \
  --output /home/deployer/undsurf-private/holes-anon.json \
  --write-out '%{http_code}\n' \
  https://api.undsurf.com/api/tenant/holes
php -r '$j=json_decode(file_get_contents("/home/deployer/undsurf-private/holes-anon.json"), true); echo ($j["error"]["code"] ?? "missing").PHP_EOL;'

sudo -n true
```

Lectura de esa salida, sin tratarla como hecha desde aquí:

- Rama `deploy/request-access-revalidation`, HEAD `ee54d0a28140d14e419de727be5a40d18ebdf4c6`, `git status -sb` sin cambios, `git stash list` vacío.
- `migrate:status` muestra `2026_09_12_000001_add_lifecycle_fields_to_memberships_table` en el batch 2.
- Tinker imprime el driver `mysql` y el nombre de la base. Anotarlo. No imprime usuario ni contraseña. El nombre tiene que ser el de la base que produjo el dump; si no se puede afirmar eso, detenerse.
- Protocolo `1.0`. Anotar TTL y timeout que salgan de la config cacheada. En el fuente, si no hay valor de entorno, son 60 y 10 segundos.
- El último número de tinker es cuántos `user_id` tienen más de una membership `active`. Tiene que ser `0`.
- `db:table memberships --json` puede no listar `memberships_one_active_per_user`. En MySQL la migración no lo crea. Eso no detiene el bloque.
- Dump: tamaño `43035`. La marca `-- Dump completed` aparece una sola vez. Guardar los dos SHA-256. No abrir el SQL.
- `grep` del binario muestra `drill_holes.list@1`. Las otras tres cadenas se anotan y no son requisito de este listado.
- `psql`, `docker` y `go` siguen ausentes. `ps` no muestra Connector ni PostgreSQL.
- HTTP `401` y código `UNAUTHENTICATED`. El host `api.undsurf.com` sale del informe del 2026-09-09; esta actividad no lo resolvió de nuevo.
- `sudo -n true` termina en 0. Si pide contraseña o devuelve otro código, `sudo` sigue sin estar recuperado: detenerse. No instalar nada.

**Reversión:** no hay cambios. No restaurar el dump. Borrar solo `/home/deployer/undsurf-private/holes-anon.json` si se quiere dejar el directorio vacío.

---

## B. Instalación de PostgreSQL, cuando sudo esté recuperado — alternativa PostgreSQL

**Dónde:** Bash en el servidor.

**Prerrequisitos:** bloque A cerrado, incluido `sudo -n true`. En A no había proceso PostgreSQL ni comando `psql`.

**Resultado esperado:** el paquete `postgresql` queda instalado, el servicio activo, y `listen_addresses` es `localhost` o `127.0.0.1`. El puerto 5432 acepta solo loopback.

**Detenerse si:** A no cerró; `psql` o el servicio ya existían antes de instalar; después de instalar el puerto queda en `0.0.0.0`, `*` o en otra interfaz; `pg_hba.conf` tiene una línea `host` que no sea `127.0.0.1/32` ni `::1/128`.

```bash
set -euo pipefail
systemctl is-active postgresql || true
command -v psql || true

sudo apt-get update
sudo apt-get install -y postgresql

systemctl is-active postgresql
sudo -u postgres psql -d postgres -c 'SHOW listen_addresses;'
sudo -u postgres psql -d postgres -c 'SHOW port;'
sudo -u postgres psql -d postgres -c 'SHOW config_file;'
sudo -u postgres psql -d postgres -c 'SHOW hba_file;'
ss -lnt | grep 5432
```

Si `listen_addresses` no es `localhost` ni `127.0.0.1`, editar solo esa clave en el archivo que imprimió `SHOW config_file`, dejar `listen_addresses = 'localhost'`, y ejecutar `sudo systemctl restart postgresql`. Volver a leer `SHOW listen_addresses` y `ss -lnt`. No agregar `listen_addresses = '*'`.

Revisar el archivo de `SHOW hba_file`. No agregar reglas `host` para redes distintas de localhost.

No instalar `go`. El binario ya está en `/opt/undsurf-connector/undersurf-connector`.

**Reversión:** no desinstalar el paquete en un servidor compartido. `sudo systemctl stop postgresql` y `sudo systemctl disable postgresql` solo si este bloque lo instaló y el `ss` anterior no mostraba 5432. No borrar clústeres que no haya creado este procedimiento.

---

## C. Base de laboratorio y pozos ficticios — alternativa PostgreSQL

**Dónde:** Bash en el servidor. El SQL se aplica con `psql` del bloque B.

**Prerrequisitos:** bloque B cerrado. PostgreSQL escucha solo en localhost. Copiar `docs/sql/lab-listado-pozos-2026-10-06.sql` a `/home/deployer/undsurf-private/lab-listado-pozos.sql`. El `sha256sum` tiene que ser `f17f0e6158ab66c9373c6048fd301699b8e66823d74c7b21f079dba23a375d1d`.

El esquema coincide con las consultas de `undersurf-connector/internal/adapter/postgres/store.go` en `1d16a5c`: `demo_drill_holes (id, code, status)` y, para una etapa posterior, `demo_drill_hole_progress`. El rol de este listado es `undersurf_ro`, con `SELECT` solo sobre `demo_drill_holes`.

**Resultado esperado:** existen la base `undersurf_client_lab`, el rol `undersurf_ro` y tres filas: `1001/RLEK-01/in_progress`, `1002/RLEK-02/planned`, `1003/RLEK-03/completed`. `undersurf_ro` puede hacer `SELECT` de pozos y no puede hacer `INSERT` en avances.

**Detenerse si:** la base o el rol ya existen; el hash del SQL no coincide; `listen_addresses` dejó de ser localhost; algún `GRANT` o `INSERT` falla.

```bash
set -euo pipefail
umask 077
sudo -u postgres psql -d postgres -c 'SHOW listen_addresses;'
found_db="$(sudo -u postgres psql -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = 'undersurf_client_lab'")"
found_role="$(sudo -u postgres psql -d postgres -tAc "SELECT 1 FROM pg_roles WHERE rolname = 'undersurf_ro'")"
test -z "$found_db"
test -z "$found_role"
```

Si alguna variable no queda vacía, el `test` corta el bloque. No hacer `DROP` de una base o un rol que este procedimiento no acaba de crear.

```bash
sudo -u postgres psql -d postgres -c 'CREATE DATABASE undersurf_client_lab;'
sudo -u postgres psql -d postgres -c 'CREATE ROLE undersurf_ro LOGIN;'
sudo -u postgres psql -d postgres
```

Dentro de esa sesión, sin escribir la contraseña en un archivo del repositorio:

```text
\password undersurf_ro
\q
```

Guardar el DSN fuera de Git. La forma que acepta el conector, con el host fijo en loopback, es la de `docs/lab_postgres.sql`: `postgres://undersurf_ro:<contraseña>@127.0.0.1:5432/undersurf_client_lab?sslmode=disable`. Escribirla en `/home/deployer/undsurf-private/pg-dsn`, modo `600`, sin eco y sin commitearla. Si el host no es `127.0.0.1`, detenerse.

```bash
chmod 600 /home/deployer/undsurf-private/pg-dsn
sha256sum /home/deployer/undsurf-private/lab-listado-pozos.sql
sudo -u postgres psql -d undersurf_client_lab -v ON_ERROR_STOP=1 -f /home/deployer/undsurf-private/lab-listado-pozos.sql
sudo -u postgres psql -d undersurf_client_lab -c 'SELECT id, code, status FROM demo_drill_holes ORDER BY code;'
sudo -u postgres psql -d undersurf_client_lab -tAc "SELECT has_table_privilege('undersurf_ro', 'demo_drill_holes', 'SELECT');"
sudo -u postgres psql -d undersurf_client_lab -tAc "SELECT has_table_privilege('undersurf_ro', 'demo_drill_hole_progress', 'INSERT');"
```

La primera privilegio imprime `t`. La segunda imprime `f`.

**Reversión:** solo si este bloque creó esos nombres y el Connector no está usando la base.

```bash
sudo -u postgres psql -d postgres -c 'DROP DATABASE IF EXISTS undersurf_client_lab;'
sudo -u postgres psql -d postgres -c 'DROP ROLE IF EXISTS undersurf_ro;'
rm -f /home/deployer/undsurf-private/pg-dsn
```

No ejecutar ese `DROP` si la comprobación inicial encontró la base ya creada.

---

## D. Configuración privada y token de enrolamiento

**Dónde:** Bash en el servidor, en `/var/www/undsurf/mining-drilling-api`.

**Prerrequisitos:** bloques A y C cerrados. El binario no lee `.env`; solo el entorno del proceso. El enrolamiento HTTP ocurre al arrancar en el bloque E, no aquí. No ejecutar `ConnectorLabSeeder`: cada corrida crea otro token de 7 días para el tenant llamado `Lab Tenant` y no crea usuarios, memberships ni pozos.

El comando existente es `connector:issue-enrollment-token`, con `--tenant`, `--name` y `--hours`. Sin `--tenant`, hace `firstOrCreate` por nombre. Por eso el nombre de esta corrida no es el default `Lab Tenant`.

**Resultado esperado:** no existe aún un tenant `UndSurf Lab Listado 2026-10-06`. El comando imprime `tenant_id` y un token `enr_` de un solo uso, con vencimiento a 24 horas, solo dentro de un archivo `600`. Queda escrito `/home/deployer/undsurf-private/connector.env`, también `600`. El proceso del Connector todavía no se arrancó.

**Detenerse si:** el tenant de ese nombre ya existe; el comando no imprime `tenant_id` o no imprime una línea `enr_` más 48 hexadecimales; el DSN no empieza por `postgres://undersurf_ro:` y no contiene `@127.0.0.1:5432/undersurf_client_lab`.

```bash
set -euo pipefail
umask 077
cd /var/www/undsurf/mining-drilling-api

php artisan tinker --execute='
if (App\Models\Tenant::query()->where("name", "UndSurf Lab Listado 2026-10-06")->exists()) { fwrite(STDERR, "exists\n"); exit(2); }
echo "absent\n";
'
```

Tiene que imprimir `absent`. Si el tenant ya existe, tinker termina en 2 y no se emite otro token.

```bash
php artisan connector:issue-enrollment-token --name='UndSurf Lab Listado 2026-10-06' --hours=24 \
  > /home/deployer/undsurf-private/enrollment.txt
chmod 600 /home/deployer/undsurf-private/enrollment.txt

php -r '
$lines = file("/home/deployer/undsurf-private/enrollment.txt", FILE_IGNORE_NEW_LINES);
$tenant = null; $token = null;
foreach ($lines as $line) {
    if (str_starts_with($line, "tenant_id=")) { $tenant = substr($line, 10); }
    if (preg_match("/^enr_[0-9a-f]{48}$/", $line) === 1) { $token = $line; }
}
$dsn = trim((string) file_get_contents("/home/deployer/undsurf-private/pg-dsn"));
if ($tenant === null || $token === null) { fwrite(STDERR, "enrollment output incomplete\n"); exit(1); }
if (! str_starts_with($dsn, "postgres://undersurf_ro:") || ! str_contains($dsn, "@127.0.0.1:5432/undersurf_client_lab")) {
    fwrite(STDERR, "dsn rejected\n"); exit(1);
}
$env = implode("\n", [
    "UNDSURF_CONTROL_PLANE_URL=https://api.undsurf.com",
    "UNDSURF_TENANT_ID=".$tenant,
    "UNDSURF_ENROLLMENT_TOKEN=".$token,
    "UNDSURF_CONNECTOR_ID=",
    "UNDSURF_CONNECTOR_TOKEN=",
    "UNDSURF_PG_DSN=".$dsn,
    "UNDSURF_HEARTBEAT_SECONDS=15",
    "UNDSURF_POLL_WAIT_SECONDS=2",
    "UNDSURF_IDENTITY_PATH=/home/deployer/undsurf-connector-runtime/data/identity.json",
    "UNDSURF_AUDIT_PATH=/home/deployer/undsurf-connector-runtime/logs/connector-audit.jsonl",
])."\n";
file_put_contents("/home/deployer/undsurf-private/connector.env", $env);
chmod("/home/deployer/undsurf-private/connector.env", 0600);
echo "env_written tenant_id=".$tenant."\n";
'

mkdir -p /home/deployer/undsurf-connector-runtime
chmod 700 /home/deployer/undsurf-connector-runtime
grep -E '^[A-Z0-9_]+=' /home/deployer/undsurf-private/connector.env | cut -d= -f1
```

El `grep` final solo lista nombres de variables. Tienen que ser estas diez: `UNDSURF_CONTROL_PLANE_URL`, `UNDSURF_TENANT_ID`, `UNDSURF_ENROLLMENT_TOKEN`, `UNDSURF_CONNECTOR_ID`, `UNDSURF_CONNECTOR_TOKEN`, `UNDSURF_PG_DSN`, `UNDSURF_HEARTBEAT_SECONDS`, `UNDSURF_POLL_WAIT_SECONDS`, `UNDSURF_IDENTITY_PATH`, `UNDSURF_AUDIT_PATH`. No definir `UNDSURF_CONNECTOR_VERSION`: el binario anunciará la suya. En el fuente, el default es `0.1.0`.

El host del control plane es el del informe del 2026-09-09. El código exige `UNDSURF_CONTROL_PLANE_URL`, `UNDSURF_PG_DSN` y un token de enrolamiento o la pareja id+token. Eso coincide con lo que el binario ya exigió al invocarlo con `--help`. No volver a invocarlo con `--help`: `main` carga la configuración y, si el entorno está completo, abre PostgreSQL.

**Reversión:** el token ya quedó hasheado en `connector_enrollment_tokens` y todavía no se consumió. No arrancar el Connector. Borrar `/home/deployer/undsurf-private/connector.env` y `/home/deployer/undsurf-private/enrollment.txt`. Dejar la fila del token hasta una limpieza revisada. No borrar el tenant si la comprobación inicial lo encontró existente. Si este bloque lo creó y no hay Connector asociado, dejar también esa fila; no hay un comando Artisan de borrado que este runbook pueda citar.

---

## E. Arranque manual y comprobación de heartbeat

**Dónde:** Bash en el servidor.

**Prerrequisitos:** bloque D cerrado y el token todavía dentro de las 24 horas. No hay otro `undersurf-connector` en `ps`. PostgreSQL sigue en localhost.

El binario, al no tener id+token y no encontrar `identity.json`, abre PostgreSQL primero y solo después llama a `POST /api/connector/v1/enroll`. Si PostgreSQL falla, el token no se consume. Si el enrolamiento responde y falla el guardado de `identity.json`, el token queda consumido: detenerse y no reutilizarlo. Un token ya usado responde `UNAUTHORIZED`.

El cliente anuncia en el enrolamiento y en el heartbeat solo `drill_holes.list@1` y `adapter.postgres`. El dispatcher local también implementa `drill_holes.get@1`, `progress.create@1` y `progress.list@1`, pero el listado de esta beta no los necesita. El API acepta el listado si la operación está en `config/connector.php`, no porque el heartbeat enumere las cuatro.

**Resultado esperado:** el log muestra `enrolled`, `session opened` y `starting`. Tinker muestra `status=online` e `is_online=yes` para el `tenant_id` de D. Existe `identity.json` modo `600`. El env queda con `UNDSURF_CONNECTOR_ID` y `UNDSURF_CONNECTOR_TOKEN`, y con `UNDSURF_ENROLLMENT_TOKEN` vacío. `config.Load` exige una de las dos formas antes de mirar el archivo de identidad; por eso un reinicio no puede depender solo de borrar el token.

**Detenerse si:** el proceso termina al abrir PostgreSQL o al enrolar; no aparece `session opened`; `is_online` no es `yes`; ya existía `identity.json` antes de este arranque.

```bash
set -euo pipefail
test ! -e /home/deployer/undsurf-connector-runtime/data/identity.json
: > /home/deployer/undsurf-connector-runtime/connector.log
set -a
# shellcheck disable=SC1091
source /home/deployer/undsurf-private/connector.env
set +a
nohup /opt/undsurf-connector/undersurf-connector \
  >> /home/deployer/undsurf-connector-runtime/connector.log \
  2>&1 &
echo $! > /home/deployer/undsurf-connector-runtime/connector.pid
unset UNDSURF_ENROLLMENT_TOKEN UNDSURF_PG_DSN UNDSURF_CONNECTOR_TOKEN

log=/home/deployer/undsurf-connector-runtime/connector.log
opened=0
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
  if grep -F -q 'session opened' "$log"; then opened=1; break; fi
  if grep -F -q -e 'config:' -e 'enroll:' -e 'identity save:' -e 'identity load:' -e 'no identity and no enrollment token' "$log"; then
    echo 'connector failed; stop' >&2
    exit 1
  fi
  sleep 1
done
test "$opened" = "1"
kill -0 "$(cat /home/deployer/undsurf-connector-runtime/connector.pid)"
```

No copiar el log al informe. La línea de arranque redacta el DSN y no contiene el token. Comprobar el estado sin imprimir hashes ni el archivo de identidad:

```bash
cd /var/www/undsurf/mining-drilling-api
export LAB_TENANT_ID="$(grep '^UNDSURF_TENANT_ID=' /home/deployer/undsurf-private/connector.env | cut -d= -f2-)"
php artisan tinker --execute='
$tenantId = getenv("LAB_TENANT_ID");
$c = App\Models\Connector::query()->where("tenant_id", $tenantId)->orderByDesc("last_seen_at")->first();
if ($c === null) { fwrite(STDERR, "connector missing\n"); exit(1); }
echo "connector_id=".$c->id.PHP_EOL;
echo "status=".$c->status.PHP_EOL;
echo "version=".($c->version ?? "").PHP_EOL;
echo "is_online=".($c->isOnline() ? "yes" : "no").PHP_EOL;
echo "capabilities=".implode(",", $c->capabilities ?? []).PHP_EOL;
'
stat -c '%a %n' /home/deployer/undsurf-connector-runtime/data/identity.json
```

Tiene que imprimir `status=online` e `is_online=yes`. Después, quitar el token del env sin mostrarlo:

```bash
php -r '
$p="/home/deployer/undsurf-private/connector.env";
$id=json_decode((string) file_get_contents("/home/deployer/undsurf-connector-runtime/data/identity.json"), true);
if (! is_array($id) || ($id["connector_id"] ?? "") === "" || ($id["connector_token"] ?? "") === "") {
    fwrite(STDERR, "identity incomplete\n");
    exit(1);
}
$out=[];
foreach (file($p, FILE_IGNORE_NEW_LINES) as $line) {
    if (str_starts_with($line, "UNDSURF_ENROLLMENT_TOKEN=")) { $out[]="UNDSURF_ENROLLMENT_TOKEN="; }
    elseif (str_starts_with($line, "UNDSURF_CONNECTOR_ID=")) { $out[]="UNDSURF_CONNECTOR_ID=".$id["connector_id"]; }
    elseif (str_starts_with($line, "UNDSURF_CONNECTOR_TOKEN=")) { $out[]="UNDSURF_CONNECTOR_TOKEN=".$id["connector_token"]; }
    else { $out[]=$line; }
}
file_put_contents($p, implode("\n", $out)."\n");
chmod($p, 0600);
echo "env_ready_for_restart\n";
'
```

Con id y token en el entorno, el proceso siguiente no vuelve a enrolar. Si el token de enrolamiento quedara vacío y el id también, `config.Load` fallaría antes de abrir `identity.json`. Parar el proceso con `SIGTERM`, que el binario sí atiende: `kill -TERM "$(cat /home/deployer/undsurf-connector-runtime/connector.pid)"`. No usar `KILL` como primer intento.

**Reversión:** `SIGTERM`, confirmar que `ps` ya no muestra el proceso, borrar `identity.json` y el log privado. El token, si el log llegó a `enrolled`, quedó consumido. No emitir otro token en la misma corrida. No borrar la fila `connectors` desde SQL en este bloque.

---

## F. Usuario y membership por los mecanismos existentes

**Dónde:** Bash en el servidor, en `/var/www/undsurf/mining-drilling-api`.

**Prerrequisitos:** bloque E con `is_online=yes`. El conteo de memberships activas duplicadas del bloque A sigue en 0. Copiar `docs/lab-listado/activar-usuario.php` a `/home/deployer/undsurf-private/activar-usuario.php` con SHA-256 `268534c05d456aa955366b216af090f76ed2fc1e12fc46c59330e546b6708163`.

No correr `DatabaseSeeder`, `UserSeeder`, `TenantVerticalSliceSeeder` ni `ConnectorLabSeeder`. `UserSeeder` no crea memberships. `TenantVerticalSliceSeeder` las escribe a mano, sin pasar por `MembershipLifecycleService`, y deja una contraseña fija en el fuente. `ConnectorLabSeeder` no crea usuarios, memberships ni pozos. No hay endpoint HTTP de alta. El servicio documenta la consola como el mecanismo vigente. `User::$fillable` acepta `name`, `email`, `password`, `role`, `shift` y `active`. `shift` es nullable. El cast `hashed` guarda la contraseña. El rol de usuario `App\Enums\UserRole::Supervisor` vale `supervisor`, el mismo texto que recibe `activate`.

**Resultado esperado:** un usuario nuevo, activo, con una sola membership `active` en el tenant de D. La salida muestra `user_id`, `membership_id` y `membership_status=active`. No muestra la contraseña ni el token de enrolamiento.

**Detenerse si:** el email ya existe; el tenant no existe; `activate` lanza `MEMBERSHIP_ALREADY_ACTIVE`; el hash del fragmento no coincide.

Elegir un email de laboratorio que no sea una cuenta real. Ejemplo de forma: `lab-listado@undsurf.test`. La contraseña se ingresa en la terminal y queda solo en el archivo privado.

```bash
set -euo pipefail
umask 077
cd /var/www/undsurf/mining-drilling-api
set +o history
read -r -s -p 'Lab user password: ' LAB_PW
printf '\n' 
printf '%s' "$LAB_PW" > /home/deployer/undsurf-private/lab-user.password
unset LAB_PW
chmod 600 /home/deployer/undsurf-private/lab-user.password
export LAB_EMAIL='lab-listado@undsurf.test'
printf '%s\n' "$LAB_EMAIL" > /home/deployer/undsurf-private/lab-user.email
chmod 600 /home/deployer/undsurf-private/lab-user.email
export LAB_TENANT_ID="$(grep '^UNDSURF_TENANT_ID=' /home/deployer/undsurf-private/connector.env | cut -d= -f2-)"
export LAB_PASSWORD="$(cat /home/deployer/undsurf-private/lab-user.password)"
php artisan tinker --execute="$(cat /home/deployer/undsurf-private/activar-usuario.php)"
unset LAB_PASSWORD
set -o history
```

**Reversión:** copiar `docs/lab-listado/revertir-membership.php` (SHA-256 `ecdd609f145f54855318bdcc7635c59b54bbd8ae98da1dac57b5146c04accb81`) y ejecutarlo con `LAB_EMAIL` y `LAB_TENANT_ID`. `deactivate` exige un motivo no vacío, cierra la membership y borra los tokens Sanctum de ese usuario. No borra el usuario ni el tenant. No hace falta la contraseña.

Si `activate` llega a crear el usuario y después falla, la membership no existe: el fragmento de reversión sale en 3. No borrar el usuario a mano. Anotar el `user_id` y detenerse.

```bash
export LAB_EMAIL="$(tr -d '\n' < /home/deployer/undsurf-private/lab-user.email)"
export LAB_TENANT_ID="$(grep '^UNDSURF_TENANT_ID=' /home/deployer/undsurf-private/connector.env | cut -d= -f2-)"
php artisan tinker --execute="$(cat /home/deployer/undsurf-private/revertir-membership.php)"
```

---

## G. Login y listado autenticado

**Dónde:** Bash en el servidor, contra `https://api.undsurf.com`. El host sale del informe del 2026-09-09.

**Prerrequisitos:** E con el proceso vivo e `is_online=yes`, y F con membership `active`. El Connector tiene que poder hacer `POST` de salida hacia ese host. El timeout cacheado, leído en A, es el tiempo máximo del listado; el default del fuente es 10 segundos.

`POST /api/auth/login` exige JSON `email` y `password`. Si las credenciales no sirven, o si `users.active` es falso en el login, la respuesta es de validación, no `401 UNAUTHENTICATED`. Un `401 USER_INACTIVE` aparece en una request posterior si el usuario se desactiva con el token ya emitido. `GET /api/tenant/holes` sin membership activa responde `403 NO_ACTIVE_MEMBERSHIP`. Sin Connector, o con el heartbeat fuera del TTL, responde `503 CONNECTOR_OFFLINE`. Si el Connector no alcanza a responder, `504 TIMEOUT`.

**Resultado esperado:** login HTTP 200 con clave `token`. El archivo de respuesta queda en modo `600` y no se imprime. El listado HTTP 200 trae `data` con las tres filas de C, más `request_id` y `correlation_id`.

**Detenerse si:** el login no es 200; el listado no es 200; `data` no son esas tres filas; aparece `UNAUTHENTICATED`, `USER_INACTIVE`, `NO_ACTIVE_MEMBERSHIP`, `CONNECTOR_OFFLINE`, `TIMEOUT`, `ADAPTER_ERROR` o `INVALID_RESULT`. No reintentar el listado en ráfaga. No declarar el circuito probado desde el 401 anónimo.

```bash
set -euo pipefail
umask 077
export LAB_EMAIL="$(tr -d '\n' < /home/deployer/undsurf-private/lab-user.email)"
php -r '
$email = getenv("LAB_EMAIL");
$pw = trim((string) file_get_contents("/home/deployer/undsurf-private/lab-user.password"));
if ($email === "" || $pw === "") { fwrite(STDERR, "login material missing\n"); exit(1); }
file_put_contents("/home/deployer/undsurf-private/login.json", json_encode(["email" => $email, "password" => $pw]));
chmod("/home/deployer/undsurf-private/login.json", 0600);
'

curl --silent --show-error \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data '@/home/deployer/undsurf-private/login.json' \
  --output /home/deployer/undsurf-private/login-response.json \
  --write-out '%{http_code}\n' \
  https://api.undsurf.com/api/auth/login

php -r '
$j = json_decode(file_get_contents("/home/deployer/undsurf-private/login-response.json"), true);
if (! is_array($j) || ! isset($j["token"]) || ! is_string($j["token"]) || $j["token"] === "") {
    fwrite(STDERR, "login without token\n");
    exit(1);
}
file_put_contents("/home/deployer/undsurf-private/bearer", $j["token"]);
chmod("/home/deployer/undsurf-private/bearer", 0600);
echo "token_saved\n";
'

curl --silent --show-error \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer $(cat /home/deployer/undsurf-private/bearer)" \
  --output /home/deployer/undsurf-private/holes.json \
  --write-out '%{http_code}\n' \
  'https://api.undsurf.com/api/tenant/holes?limit=10'

php -r '
$j = json_decode(file_get_contents("/home/deployer/undsurf-private/holes.json"), true);
$want = [
    ["id" => "1001", "code" => "RLEK-01", "status" => "in_progress"],
    ["id" => "1002", "code" => "RLEK-02", "status" => "planned"],
    ["id" => "1003", "code" => "RLEK-03", "status" => "completed"],
];
$got = $j["data"] ?? null;
if (($j["request_id"] ?? "") === "" || ($j["correlation_id"] ?? "") === "" || $got !== $want) {
    fwrite(STDERR, "list mismatch\n");
    exit(1);
}
echo "request_id=".$j["request_id"].PHP_EOL;
echo "correlation_id=".$j["correlation_id"].PHP_EOL;
echo "rows=3\n";
'
```

Anotar `request_id` y `correlation_id`. No anotar el bearer. Borrar `login.json` y `login-response.json` cuando el `bearer` ya esté guardado, si no hacen falta para la corrida.

**Reversión:** parar el Connector con `SIGTERM` (bloque E) y cerrar la membership (bloque F). Eso corta el listado. No restaurar `/home/deployer/undsurf-backups/undsurf-20261006-092536.sql` encima de la base en uso. Los pozos ficticios se quitan con el `DROP DATABASE` del bloque C, después de parar el Connector.

---

## M. MySQL en el servidor, sin instalar PostgreSQL

**Dónde:** Bash en el servidor, después de reemplazar el binario con el build de `feat/mysql-lab-read-support`. Este bloque no se ejecuta en esta actividad.

**Prerrequisitos:** bloque A leído. No hace falta el `sudo` del bloque B. Hace falta una cuenta administrativa de MySQL distinta de `undsurf_app`. El informe del 2026-09-09 presentó `undsurf_app` como usuario de la base `undsurf`. No hay evidencia de que pueda crear otra base o un usuario. Si `CREATE DATABASE` o `CREATE USER` responden access denied, detenerse y no aplicar el SQL con esa cuenta.

El archivo `undersurf-connector/docs/lab_mysql.sql` solo crea `demo_drill_holes` y carga tres filas. No trae contraseñas ni `DROP`. La base se llama `undersurf_client_lab`. No es `undsurf`.

**Resultado esperado:** el Connector arranca con `UNDSURF_DB_ENGINE=mysql`, anuncia `drill_holes.list@1`, `drill_holes.get@1` y `adapter.mysql`, y no anuncia `progress.create@1`. El listado y el detalle devuelven las tres filas ficticias. Un comando de avance, si llegara, vuelve `UNSUPPORTED_OP`.

**Detenerse si:** el binario no contiene `adapter.mysql`; la base efectiva de Laravel es la misma que `UNDSURF_MYSQL_DATABASE`; el host MySQL no es `127.0.0.1`; la cuenta de lectura puede escribir.

Comprobación del binario nuevo, sin ejecutarlo todavía:

```bash
grep -a -F -o 'adapter.mysql' /opt/undsurf-connector/undersurf-connector | sort -u
grep -a -F -o 'drill_holes.get@1' /opt/undsurf-connector/undersurf-connector | sort -u
grep -a -F -o 'progress.create@1' /opt/undsurf-connector/undersurf-connector | sort -u || true
```

`adapter.mysql` y `drill_holes.get@1` tienen que aparecer. Si `progress.create@1` aparece en el binario, puede ser el texto de rechazo; el proceso MySQL no debe anunciarla. Eso se confirma en el heartbeat del bloque E, en `capabilities`.

Variables del entorno, escritas en `/home/deployer/undsurf-private/connector.env` con modo `600`. No incluir `UNDSURF_PG_DSN` en un arranque MySQL. La contraseña se ingresa como en el bloque F, no dentro del SQL.

```text
UNDSURF_DB_ENGINE=mysql
UNDSURF_MYSQL_HOST=127.0.0.1
UNDSURF_MYSQL_PORT=3306
UNDSURF_MYSQL_DATABASE=undersurf_client_lab
UNDSURF_MYSQL_USERNAME=<cuenta de lectura>
UNDSURF_MYSQL_PASSWORD=<ingresada fuera de Git>
```

El resto del enrolamiento, del heartbeat, del usuario y del login es el de los bloques D, E, F y G, con el entorno MySQL de arriba. No usar el `php -r` del bloque D que exige un DSN `postgres://`: ese fragmento es de la alternativa PostgreSQL. En G, repetir el GET autenticado de `https://api.undsurf.com/api/tenant/holes/1001` y esperar HTTP 200 con `data.id=1001`, `data.code=RLEK-01` y `data.status=in_progress`, más `request_id` y `correlation_id`.

**Reversión:** parar el proceso con `SIGTERM`, volver a colocar el binario guardado antes del reemplazo y restaurar el `connector.env` anterior. No borrar la base `undsurf`. `DROP DATABASE undersurf_client_lab` solo si este bloque la creó y el nombre se comprobó antes.

---

## Qué no entra en esta corrida

Detalle `GET /api/tenant/holes/{hole}`, avances, idempotencia, revocación y Android quedan fuera. En `ee54d0a` el detalle sí tiene ruta. No hay ruta de tenant para crear o listar avances; el avance legado `POST /api/drill-holes/{drillHole}/progress` escribe la base de Laravel, no PostgreSQL. El Connector sí implementa `progress.create@1` y deduplica por `idempotency_key`, pero esta beta no lo prueba. `401 USER_INACTIVE` y `403 NO_ACTIVE_MEMBERSHIP` tampoco se provocan aquí.
