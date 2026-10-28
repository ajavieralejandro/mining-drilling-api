# UndSurf — Estado de la beta y próximo paso — 2026-10-06

**Fecha de esta nota:** 6 de octubre de 2026.
**Tipo:** estado y preparación. No hubo contacto con el servidor, ni instalación, seeder, enrolamiento, arranque del Connector, APK, merge, push ni cambio funcional.
**Histórico que se conserva:** `docs/deploy-beta-status-2026-10-02.md`. No se reescribe.

Tres capas:

- **Reportado por el usuario** el 6 de octubre, a partir de salidas de servidor. Esta nota no las reejecutó.
- **Leído en el working tree local** el 6 de octubre.
- **Histórico** de informes anteriores, no reejecutado.

El circuito de login y listado no está operativo y no está probado. Un 401 `UNAUTHENTICATED` sin token no cubre login, revalidación, Connector ni Android.

---

## 1. Resumen y semáforo

El API que el usuario reporta en `/var/www/undsurf/mining-drilling-api` sigue en `deploy/request-access-revalidation`, commit `ee54d0a`, con la migración de lifecycle en el batch 2 y las caches de configuración y de rutas hechas. `GET /api/tenant/holes` sin token responde 401 `UNAUTHENTICATED`. Hay backup de archivos y un dump de MySQL de 43035 bytes. Ese dump no se restauró. El binario del Connector está instalado y, al invocarlo con `--help`, exige configuración en vez de mostrar ayuda. No hay `sudo` usable: el usuario no recuerda la contraseña. Root por SSH sigue restringido.

En local quedó escrito el runbook `docs/runbook-listado-beta-2026-10-06.md`, el SQL de pozos ficticios y dos fragmentos de consola. No se ejecutaron.

```text
Backend reportado en ee54d0a, con dump de MySQL sin restaurar.
Connector instalado y sin enrolar. PostgreSQL de laboratorio sin instalar.
Login y listado todavía no se pueden dar por hechos.
Sudo de deployer bloqueado. La próxima corrida es solo el bloque A.
```

| Pieza | Semáforo | Capa |
|---|---|---|
| Checkout, rama, HEAD `ee54d0a`, árbol limpio, lifecycle en batch 2, caches | Verde reportado | Usuario, 6 de octubre. No reejecutado aquí |
| `GET /api/tenant/holes` sin token → 401 `UNAUTHENTICATED` | Verde de alcance corto | Usuario. No es login ni listado autenticado |
| Backup de archivos y dump MySQL de 43035 bytes | Ámbar | Usuario. La restauración no se probó |
| Binario en `/opt/undsurf-connector/undersurf-connector` | Ámbar | Usuario. `--help` carga configuración. Versión y operaciones del binario siguen sin leer |
| Ausencia de `psql`, `docker`, `go` y de procesos Connector/PostgreSQL | Ámbar | Usuario, en las comprobaciones que hizo. No prueba ausencia total |
| `sudo` de `deployer` y root por SSH | Rojo | Usuario. Sin `sudo` no se instala PostgreSQL |
| Preparación local del runbook y del SQL | Verde local | Esta actividad. No es una corrida en el servidor |
| Login, listado, Connector enrolado, Android | Rojo | Pendiente |

---

## 2. Qué se implementó, qué se publicó y qué se desplegó

### Working tree local, leído el 6 de octubre

| Repo | Rama | HEAD | Cambios que se preservan |
|---|---|---|---|
| `mining-drilling-api` | `main`, igual a `origin/main` | `e6c3f305e6f9866bb2686c8ae06737d48f2b0f91` | `stash@{0}` sin aplicar: refactor de repositorio legado, fuera del alcance P0. Documentos sin trackear, entre ellos el informe del 2 de octubre |
| Worktree `feat/request-access-revalidation` | la misma rama, igual a `origin` | `ee54d0a28140d14e419de727be5a40d18ebdf4c6` | Árbol limpio |
| `undersurf-connector` | `master` | `1d16a5c81b511548001ac7d4dc162c1d18ac84a9` | Árbol limpio. Sin remoto |
| `mining-drilling` | `feat/gateway-access-handling`, igual a `origin` | `d1d6c64a10061de43825c605176c0e1a6c16beed` | `.env` sin trackear. No se editó |

`composer.lock` no cambia entre `e6c3f30` y `ee54d0a`.

### Publicado en las ramas, leído en git local

En `feat/request-access-revalidation`, desde `dc3b519` hasta `ee54d0a`: `a2d9e78` registra el deploy del 9 de septiembre; `be32b6c` agrega el ciclo de vida de memberships; `e6c3f30` lee el timeout de SQLite desde el entorno; `3603466` revalida usuario y membership en cada request; `ee54d0a` solo agrega `docs/staging-apk-beta-2026-10-02.md`.

En la app, encima de `6f59b95`: `e11da37` descarta datos de gateway si el acceso se revoca; `d1041cf` conserva `idempotency_key` en el reintento de un avance; `d1d6c64` es documentación. Ninguno es un APK.

El Connector local `1d16a5c` implementa `drill_holes.list@1`, `drill_holes.get@1`, `progress.create@1` y `progress.list@1`. El enrolamiento y el heartbeat anuncian solo `drill_holes.list@1` y `adapter.postgres`. El mensaje del commit habla del listado; el dispatcher del mismo commit ya tiene las cuatro operaciones. El README todavía describe solo el listado.

### Desplegado, solo según el usuario

Ruta `/var/www/undsurf/mining-drilling-api`, rama `deploy/request-access-revalidation`, HEAD `ee54d0a28140d14e419de727be5a40d18ebdf4c6`, árbol limpio, lifecycle en el batch 2, configuración y rutas cacheadas, Laravel sobre MySQL. Esta actividad no abrió esa sesión.

---

## 3. Pruebas históricas y verificaciones recientes

| Qué | Fuente | Qué alcanza a afirmar |
|---|---|---|
| Control plane en `https://api.undsurf.com`, HEAD de entonces `dc3b519`, MySQL, 401 anónimo en `GET /api/tenant/holes` | `docs/deploy-status-2026-09-09.md` | Histórico. El HEAD de hoy, reportado, es `ee54d0a` |
| Laboratorio de loopback: listado 200, Connector enrolado, 503 al detenerlo | `docs/local-gateway-lab-2026-10-01.md` | Otra máquina. No es este servidor |
| `php artisan test` en SQLite `:memory:`, sin contacto con `api.undsurf.com` | `docs/request-access-revalidation-2026-10-02.md`, dentro de `3603466` | Tests del worktree. No se repitieron contra el MySQL del servidor |
| Staging no desplegado, SSH denegado, APK no generado | `docs/staging-apk-beta-2026-10-02.md`, dentro de `ee54d0a` | Foto anterior al checkout que el usuario reporta ahora |
| `tsc` y `npm test` en la máquina de desarrollo. APK no generado. EAS sin lectura del proyecto | `mining-drilling/docs/apk-pruebas-gateway-2026-10-02.md` y `gateway-access-handling-2026-10-02.md` | No hay prueba en un teléfono |
| Checkout `ee54d0a`, 401 de `GET /api/tenant/holes` sin token, caches, batch 2, backup de archivos, binarios vistos, búsqueda sin procesos | Informe del 2 de octubre y, de nuevo, el usuario el 6 de octubre | Hecho reportado. El 401 no prueba el listado autenticado |
| Dump `/home/deployer/undsurf-backups/undsurf-20261006-092536.sql`, 43035 bytes, generado correctamente | Usuario, 6 de octubre | No se restauró y esta actividad no lo abrió |
| `--help` del binario exige `UNDSURF_CONTROL_PLANE_URL`, `UNDSURF_PG_DSN` y token de enrolamiento o id+token | Usuario, 6 de octubre | Coincide con `config.Load` en `1d16a5c`, que corre antes de cualquier flag. No identifica el binario con ese commit |
| Sin `psql`, `docker`, `go`, ni procesos Connector/PostgreSQL en las comprobaciones hechas. Sin `sudo`. Root SSH restringido | Usuario, 6 de octubre | Bloqueo para instalar PostgreSQL. No es un inventario completo del host |

---

## 4. Backups

| Respaldo | Estado |
|---|---|
| `/home/deployer/undsurf-backups/20261002-120459/backend-files.tar.gz` | Reportado el 2 de octubre y otra vez el 6. No se abrió ni se restauró |
| `/home/deployer/undsurf-backups/undsurf-20261006-092536.sql` | Reportado el 6 de octubre, 43035 bytes, generado correctamente. Restauración no probada |

No restaurar ese SQL sobre la base que Laravel está usando. El bloque A del runbook solo comprueba tamaño, modo, SHA-256 y la marca `-- Dump completed`, sin volcar filas.

---

## 5. Estado móvil

No hay un APK que esta nota pueda afirmar. Los informes del 2 de octubre dicen que no se generó, que la sesión EAS no pudo leer el proyecto y que no hay canal OTA. La punta local y `origin/feat/gateway-access-handling` es `d1d6c64`, un commit de documentación. El login principal de la app sigue siendo el mock. El gateway real entra por `/pozos-real`. El `.env` de la app sigue sin trackear y no se modificó.

---

## 6. Bloqueo administrativo y qué puede seguir sin sudo

`deployer` no tiene `sudo` usable porque la contraseña no está a mano. Root por SSH permanece restringido. Sin `sudo` no corresponde instalar PostgreSQL ni cambiar `listen_addresses`.

Sin `sudo`, en el servidor, solo caben lecturas: git, `migrate:status`, tinker de conteos, tamaño del dump, `grep` de cadenas en el binario y el 401 anónimo. Eso es la primera parte del bloque A. El bloque se cierra recién cuando `sudo -n true` termina en 0. Los bloques B a G no empiezan antes.

En local, esta actividad ya dejó el runbook, el SQL y los fragmentos de consola. No hace falta otro cambio de código para poder ejecutar el bloque A.

---

## 7. Pendientes para login y listado

1. Cerrar el bloque A, incluida la recuperación de `sudo`.
2. Instalar PostgreSQL solo en localhost.
3. Crear `undersurf_client_lab`, el rol `undersurf_ro` y las tres filas ficticias.
4. Emitir un token con `php artisan connector:issue-enrollment-token` y guardar el entorno en un archivo privado.
5. Arrancar el binario de `/opt`. El enrolamiento ocurre en ese arranque, después de abrir PostgreSQL.
6. Crear el usuario y la membership con `MembershipLifecycleService::activate`.
7. `POST /api/auth/login` y `GET /api/tenant/holes?limit=10`, y ver las tres filas junto con `request_id` y `correlation_id`.

`ConnectorLabSeeder` no hace ese alta. Crea o reutiliza `Lab Tenant` y agrega un token nuevo en cada ejecución. No crea usuarios, memberships ni pozos. `UserSeeder` y `TenantVerticalSliceSeeder` tampoco son el mecanismo de esta corrida: no pasan por el servicio de lifecycle.

Hasta el paso 7, el circuito sigue sin declarar.

---

## 8. Etapas posteriores

No forman parte del runbook actual.

| Etapa | Qué ya está en el código | Qué falta |
|---|---|---|
| Detalle | `GET /api/tenant/holes/{hole}` despacha `drill_holes.get@1` | Probarlo contra un id ficticio, con el Connector en marcha |
| Avances | El Connector inserta en `demo_drill_hole_progress` y deduplica por `idempotency_key` | En `ee54d0a` no hay ruta de tenant para crear o listar avances. `POST /api/drill-holes/{drillHole}/progress` escribe la base de Laravel |
| Idempotencia | `ON CONFLICT (idempotency_key) DO NOTHING` y la app conserva la clave en el reintento | Hace falta la ruta de tenant y una segunda respuesta que devuelva la misma fila |
| Revocación | `EnsureUserIsActive` responde 401 `USER_INACTIVE`. `ResolveTenantContext` responde 403 `NO_ACTIVE_MEMBERSHIP`. `deactivate` revoca los tokens Sanctum | Provocar los dos códigos con un token emitido antes del cambio. No están cubiertos por el 401 anónimo |
| Android | La app publica el manejo de acceso y el reintento | Generar e instalar un APK, y repetir el recorrido. Hoy no hay un APK confirmado |

---

## 9. Runbook

`docs/runbook-listado-beta-2026-10-06.md`

SQL y fragmentos, sin secretos:

- `docs/sql/lab-listado-pozos-2026-10-06.sql`
- `docs/lab-listado/activar-usuario.php`
- `docs/lab-listado/revertir-membership.php`

Los SHA-256 están en el runbook. La próxima ejecución es una sola: recuperar el `sudo` de `deployer` y correr el bloque A. Los bloques B a G quedan escritos y no se autorizan al terminar esta nota.
