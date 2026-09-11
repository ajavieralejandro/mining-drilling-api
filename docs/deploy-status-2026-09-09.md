# UndSurf — Estado del deploy productivo (2026-09-09)

**Fecha:** 2026-09-09
**Repos:** `mining-drilling-api` (este documento), `undersurf-connector`, `mining-drilling`
**Tipo de documento:** estado, no runbook. Continúa `p0-p1-handoff-wilson-2026-09-05.md`.

---

## 1. Resumen ejecutivo

Hoy se ejecutó el primer deploy productivo controlado de UndSurf / App Minería. El Control Plane Laravel quedó **desplegado y operativo** en `https://api.undsurf.com`, con DNS, HTTPS, Apache, MySQL y migraciones confirmados. El Connector Go quedó **instalado parcialmente** en el mismo servidor (binario verificado por hash, usuario de sistema y directorios creados) pero **no configurado ni ejecutado**.

El circuito end-to-end (`App → Laravel → Connector → PostgreSQL del cliente → Connector → Laravel → App`) **todavía no se puede completar**: el servidor no tiene PostgreSQL instalado y el Connector solo soporta PostgreSQL (`github.com/lib/pq`) contra la tabla `demo_drill_holes`. Queda pendiente una decisión entre un laboratorio provisional en el mismo servidor o el PostgreSQL real del cliente.

La formulación correcta del estado es:

```text
Control Plane Laravel desplegado.
Connector instalado parcialmente.
Circuito end-to-end todavía pendiente.
```

No se declara el deploy completo.

---

## 2. Contradicción con documentación anterior

`docs/p0-p1-handoff-wilson-2026-09-05.md`, sección "Gate lunes 2026-09-07", registra que el deploy se **detuvo en FASE 5** porque:

- `api.undsurf.com` no resolvía desde la máquina que hizo el gate, y
- el acceso SSH fue denegado (`publickey`) para los usuarios probados.

Ese bloqueo **quedó resuelto y desactualizado**: hoy se confirmó DNS operativo para `api.undsurf.com`, sesión en el servidor, VirtualHost propio, HTTPS válido y Laravel respondiendo JSON. La sección de gate del 2026-09-07 debe leerse como histórica (el estado en ese momento), no como estado vigente. No se modifica ese documento en esta tarea: no hay convención explícita de reescribir gates pasados, solo de agregar el estado nuevo en un documento propio.

---

## 3. Componentes desplegados

### 3.1 Servidor

```text
Host: ubuntu-s-2vcpu-4gb-amd-sfo3-01
SO:   Ubuntu 24.04.4 LTS
Apache:   2.4.58 (mod_php 8.3, mod_rewrite; sin PHP-FPM)
PHP:      8.3.6
Composer: 2.7.1
Git:      2.43.0
MySQL:    8.0.46
```

Servidor compartido con otros proyectos; no se tocaron sus directorios ni VirtualHosts existentes.

### 3.2 Control Plane Laravel

- Repo remoto: `https://github.com/ajavieralejandro/mining-drilling-api.git`, rama `main`.
- Ruta productiva: `/var/www/undsurf/mining-drilling-api`.
- HEAD desplegado: `dc3b519` — coincide con `main`/`origin/main` verificado hoy en local (ver §7).
- Base de datos MySQL dedicada (`undsurf`) y usuario de aplicación dedicado (`undsurf_app`); credencial no documentada.
- `.env` productivo generado fuera de Git (`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://api.undsurf.com`, `DB_CONNECTION=mysql`, `DB_DATABASE=undsurf`) y `APP_KEY` productiva nueva generada en el servidor.
- Dependencias: `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader` — OK, con advertencia de `composer.lock` desactualizado respecto de `composer.json` (deuda a revisar, no bloqueante).
- Migraciones aplicadas correctamente; tablas relevantes: `users`, `personal_access_tokens`, `tenants`, `memberships`, `connectors`, `connector_enrollment_tokens`, `connector_sessions`, `connector_commands`, `platform_audit_logs`.
- Caches productivos generados: config, route, view.
- `php artisan about`: Laravel 12.63.0, Environment production, Debug OFF, DB mysql, Cache/Queue/Session en `database`.
- Observación: `public/storage` figura `NOT LINKED`. No confirmado si es necesario para el vertical actual (listar pozos); queda como observación abierta, no resuelta en esta tarea.

### 3.3 DNS

```text
api.undsurf.com → 137.184.238.209   (confirmado por nslookup)
```

`undsurf.com` y `www.undsurf.com` no se tocaron; siguen sirviendo el sitio institucional.

### 3.4 VirtualHost y HTTPS

- `/etc/apache2/sites-available/api.undsurf.com.conf`: `ServerName api.undsurf.com`, `DocumentRoot /var/www/undsurf/mining-drilling-api/public`. Sitio habilitado, `apache2ctl configtest` → Syntax OK.
- Certbot 2.9.0 emitió e instaló certificado para `api.undsurf.com`, vence 2026-12-08, renovación automática configurada. Config SSL generado en `/etc/apache2/sites-available/api.undsurf.com-le-ssl.conf`.

### 3.5 Smoke test Laravel

```http
GET https://api.undsurf.com/api/tenant/holes
Accept: application/json
```

Sin autenticación:

```text
HTTP 401 Unauthorized
Content-Type: application/json
error.code = UNAUTHENTICATED
(incluye correlation_id)
```

Confirma la cadena completa DNS → HTTPS → Apache → Laravel → routing → contrato JSON → autenticación. El 401 es el resultado esperado sin bearer Sanctum, no una falla.

---

## 4. Estado del Connector Go

### 4.1 Validación local (Windows)

- Go 1.27.0 instalado.
- `go test ./...` bloqueado por una directiva de Control de aplicaciones de Windows que impidió ejecutar `vet.exe`.
- Se corrió `go test -vet=off ./...` → **PASS**. El código y los tests pasaron; `go vet` no pudo ejecutarse por política del sistema operativo, no por un fallo del proyecto.

### 4.2 Cross-compilation a Linux

```text
GOOS=linux GOARCH=amd64 CGO_ENABLED=0
Paquete: ./cmd/connector
Binario: undersurf-connector-linux-amd64
```

Verificado: ELF 64-bit, x86-64, statically linked, stripped.
SHA-256 registrado en el runbook de deploy (no se reproduce aquí por no ser necesario para este estado).

### 4.3 Instalación en el servidor

- Usuario de sistema sin shell interactiva: `undersurf-connector` (uid=999, gid=988).
- Binario instalado en `/opt/undsurf-connector/undersurf-connector`, propietario `root:root`, permisos `755`. El SHA-256 del binario instalado coincide con el compilado.
- Directorios creados: `/etc/undsurf-connector`, `/var/lib/undsurf-connector`, `/var/log/undsurf-connector`.

### 4.4 Pendiente del Connector

```text
NO fue ejecutado
NO fue enrolado
NO tiene EnvironmentFile productivo
NO tiene servicio systemd
NO envía heartbeat
NO realiza polling
```

---

## 5. Bloqueo actual — PostgreSQL

```text
postgresql: inactive
psql: command not found
```

El Connector requiere `UNDSURF_PG_DSN` y el MVP actual solo implementa PostgreSQL (`github.com/lib/pq`), contra la tabla `demo_drill_holes`. Sin una base PostgreSQL accesible para el Connector, el circuito end-to-end no puede completarse.

---

## 6. Decisión pendiente

### Alternativa A — Laboratorio provisional en el mismo servidor

```text
base:   undsurf_client_lab
usuario: undersurf_ro
tabla:  demo_drill_holes
```

- Ventajas: permite cerrar el smoke end-to-end hoy; valida Laravel ↔ Connector ↔ PostgreSQL; no requiere infraestructura externa inmediata.
- Limitación: no representa la ubicación definitiva del Connector; Control Plane y base cliente compartirían máquina de forma provisoria.
- Debe etiquetarse explícitamente como `LABORATORIO / VALIDACIÓN PROVISIONAL`, nunca como arquitectura productiva definitiva.

### Alternativa B — PostgreSQL real del cliente

Ejecutar el Connector dentro de la infraestructura del cliente o en una máquina con acceso a su PostgreSQL real. Requiere host, puerto, base, usuario de solo lectura, contraseña, acceso de red y esquema/vista compatible — ninguno de estos datos fue provisto todavía. Valida el escenario arquitectónico real, pero está bloqueada por falta de acceso/credenciales.

### Recomendación operativa

Usar la Alternativa A como laboratorio controlado para cerrar el circuito técnico, y repetir la validación contra la infraestructura real del cliente antes de declarar un despliegue productivo definitivo. **No se instaló PostgreSQL en esta tarea documental** — queda como decisión y ejecución futuras.

---

## 7. Verificación de repos locales (solo lectura, hoy)

```text
mining-drilling-api   rama main   HEAD dc3b519   limpio, main...origin/main sincronizado
undersurf-connector   rama master HEAD 1d16a5c   sin remoto Git
mining-drilling       rama main   HEAD 6f59b95   main...origin/main [ahead 1], .env sin trackear
```

`dc3b519` y `1d16a5c` coinciden con los HEADs desplegados/compilados descritos en §3.2 y §4. No se modificó ningún working tree para producir este documento.

---

## 8. Configuración confirmada del Connector (sin secretos)

Variables requeridas para operar:

```text
UNDSURF_CONTROL_PLANE_URL
UNDSURF_PG_DSN
```

Solo para el primer enrolamiento:

```text
UNDSURF_ENROLLMENT_TOKEN
```

Tras enrolar, la identidad persistida contiene `connector_id` y `connector_token` (no se documentan valores).

Variables adicionales del runtime:

```text
UNDSURF_TENANT_ID
UNDSURF_CONNECTOR_ID
UNDSURF_CONNECTOR_TOKEN
UNDSURF_HEARTBEAT_SECONDS
UNDSURF_POLL_WAIT_SECONDS
UNDSURF_IDENTITY_PATH
UNDSURF_AUDIT_PATH
UNDSURF_CONNECTOR_VERSION
```

Valores productivos conceptuales ya definidos (no secretos):

```text
UNDSURF_CONTROL_PLANE_URL=https://api.undsurf.com
UNDSURF_HEARTBEAT_SECONDS=15
UNDSURF_POLL_WAIT_SECONDS=2
UNDSURF_IDENTITY_PATH=/var/lib/undsurf-connector/identity.json
UNDSURF_AUDIT_PATH=/var/log/undsurf-connector/connector-audit.jsonl
UNDSURF_CONNECTOR_VERSION=0.1.0
```

`UNDSURF_PG_DSN`, `UNDSURF_ENROLLMENT_TOKEN`, `connector_token` y `APP_KEY` productivos no se incluyen en ningún documento de este repositorio.

---

## 9. Próximos pasos (orden recomendado)

1. Decidir entre PostgreSQL de laboratorio (Alternativa A) o PostgreSQL real del cliente (Alternativa B).
2. Preparar PostgreSQL y la tabla/vista requerida.
3. Crear usuario PostgreSQL de solo lectura.
4. Crear tenant productivo/controlado en Laravel.
5. Emitir enrollment token de un solo uso: `php artisan connector:issue-enrollment-token`.
6. Guardar el enrollment token directamente en el servidor, nunca en chats ni documentos.
7. Crear `/etc/undsurf-connector/connector.env` con permisos restrictivos.
8. Ejecutar manualmente el Connector.
9. Comprobar enrolamiento, sesión, heartbeat y polling.
10. Confirmar que `identity.json` quedó persistido y protegido.
11. Retirar el enrollment token del archivo de entorno después del enrolamiento.
12. Crear y habilitar `undersurf-connector.service` (systemd).
13. Crear un usuario real/controlado en Laravel.
14. Crear una membership activa para el tenant.
15. Iniciar sesión vía `/api/auth/login`.
16. Probar autenticado: `GET /api/tenant/holes`.
17. Confirmar `HTTP 200`, `data [...]`, `request_id`, `correlation_id`.
18. Probar casos adversariales: sin autenticación → 401; sin membership → 403; Connector apagado → 503; timeout → 504; tenant spoofing ignorado.
19. Revisar logs y confirmar ausencia de secretos.
20. Conectar la app móvil al dominio productivo.
21. Probar que la app lista los pozos reales.

---

## 10. Checklist de cierre (estado al 2026-09-09)

| Componente | Estado |
|---|---|
| Laravel Control Plane | **DESPLEGADO Y OPERATIVO** |
| DNS `api.undsurf.com` | **OPERATIVO** |
| HTTPS | **OPERATIVO** |
| MySQL Control Plane | **OPERATIVO** |
| Migraciones | **COMPLETAS** |
| Contrato 401 JSON | **VALIDADO** |
| Connector binario | **INSTALADO, NO CONFIGURADO** |
| PostgreSQL cliente/laboratorio | **PENDIENTE** |
| Enrolamiento | **PENDIENTE** |
| Heartbeat/poll | **PENDIENTE** |
| Usuario/membership | **PENDIENTE** |
| `GET /api/tenant/holes` autenticado = 200 | **PENDIENTE** |
| App móvil contra producción | **PENDIENTE** |

---

## 11. Riesgos y observaciones

- **`composer.lock` desactualizado** respecto de `composer.json` — deuda a revisar antes del próximo `composer install` productivo; no bloqueó el deploy de hoy.
- **`public/storage` `NOT LINKED`** — no confirmado si es necesario para el vertical de listar pozos; revisar si algún flujo futuro depende de `storage:link`.
- **`go vet` no se pudo ejecutar en Windows** por política de Control de aplicaciones; los tests (`go test -vet=off`) sí pasaron. Repetir `go vet` en un entorno sin esa restricción (por ejemplo Linux/CI) antes de confiar en esa señal.
- **Sin remoto Git para `undersurf-connector`** — el HEAD `1d16a5c` solo existe localmente; no hay respaldo remoto del código del Connector.
- **Servidor compartido** con otros proyectos — cualquier cambio futuro (paquetes, servicios) debe evitar tocar VirtualHosts o directorios ajenos a `/var/www/undsurf` y a los directorios propios del Connector.
- **Ninguna credencial** (DB, `APP_KEY`, tokens de enrolamiento, DSN de PostgreSQL) se documenta en este archivo ni en ningún otro archivo de este repositorio.
- El gate de 2026-09-07 en `p0-p1-handoff-wilson-2026-09-05.md` quedó **desactualizado** por lo descrito en §2; no se reescribió ese documento.

---

## 12. Criterio preciso de éxito

El circuito se considera completo únicamente cuando **todas** las filas de la checklist (§10) están en estado `OPERATIVO` / `COMPLETO` / `VALIDADO`, en particular:

```text
GET /api/tenant/holes (autenticado, con membership activa) → HTTP 200
Connector: heartbeat y polling activos y visibles en logs/estado
App móvil: lista pozos reales contra https://api.undsurf.com
```

Hasta entonces, el estado correcto a comunicar es:

```text
Control Plane Laravel desplegado.
Connector instalado parcialmente.
Circuito end-to-end todavía pendiente.
```

No se declara "deploy completo" ni "P0/P1 validado en producción" antes de cumplir ese criterio.
