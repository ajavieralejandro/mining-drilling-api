# Staging y APK beta — 2026-10-02

**Ejecución:** 2 de octubre de 2026, 13:04 (+02).
**Resultado:** el código de esta entrega quedó en sus ramas. Staging no se desplegó y el APK no se generó. No hubo merge a `main`, ni cambio del servicio o la base de producción, ni Play Store, ni OTA.

## 1. Commits publicados

| Repo | Rama | SHA | Remoto |
|---|---|---|---|
| `mining-drilling-api` | `feat/request-access-revalidation` | `3603466d61b854b716af5349c459dc91084c2a1e` | `origin/feat/request-access-revalidation` |
| `mining-drilling` | `feat/gateway-access-handling` | `d1041cf08d702be0947622e4108c21da9e6529f6` | `origin/feat/gateway-access-handling` |
| `undersurf-connector` | `master` | `1d16a5c81b511548001ac7d4dc162c1d18ac84a9` | sin commit nuevo |

La referencia anterior de la app era `e11da37`. El checkout de `mining-drilling-api` que usa el laboratorio sigue en `main` `e6c3f30`, con el stash sin aplicar y `docs/local-gateway-lab-2026-10-01.md` sin trackear. El `.env` de la app sigue sin trackear y no se editó.

Publicar la rama no despliega el servidor. `3603466` no está en el laboratorio ni hay evidencia de que esté en `api.undsurf.com`. El último SHA productivo escrito sigue siendo `dc3b519`, sin verificación en vivo en esta sesión.

## 2. Qué hacen esos commits

Backend, sobre `e6c3f30`: cada request autenticada relee `users.active`. Un usuario inactivo responde 401 `USER_INACTIVE`. En `/api/tenant/*`, la membership activa se relee antes de crear un comando al Connector. Sin membership, 403 `NO_ACTIVE_MEMBERSHIP`. Logout sigue pudiendo revocar el token.

App, sobre `e11da37`:

- El reintento de un avance conserva la `idempotency_key` si la respuesta se pierde y no cambian profundidad ni observación.
- El dashboard muestra «Pozos reales (pruebas)» para todos los perfiles mock. Abre `/pozos-real`. El login principal no se reemplazó.
- El resto de módulos del menú queda marcado como simulación: planes, plataformas, pozos, máquinas, personal, riesgos e importaciones.
- El perfil EAS `preview` pide un APK interno y el entorno `preview`. No tiene URL.

Connector en `1d16a5c`, sin cambios: `drill_holes.list@1`, `drill_holes.get@1`, `progress.create@1` y `progress.list@1`. La escritura de avance deduplica por `idempotency_key` en PostgreSQL. `go.mod` pide Go 1.27.0. En el informe del 2026-09-09 el binario del servidor estaba copiado y sin configurar. Esta sesión no lo volvió a ver.

## 3. Pruebas ejecutadas

Backend, en el worktree, SQLite `:memory:`:

```text
php artisan test --compact
Tests: 83 passed (410 assertions)
Duration: 11.42s
```

App:

```text
npx tsc --noEmit --pretty false
exit 0

npm test
tests 13
pass 13
fail 0
```

Nada de esto se ejecutó en Android ni contra `api.undsurf.com`.

## 4. Servidor

El host documentado es `137.184.238.209` (`api.undsurf.com`). La clave local no autentica como `ubuntu` ni como `root`: `Permission denied (publickey,password)`. No se inspeccionaron rutas, SHA, versiones, Apache, bases, puertos ni disco. No se hizo backup porque no se modificó nada.

Hace falta el usuario SSH que acepte la clave ya instalada en esta máquina, sin pegar contraseñas en el chat. Con esa sesión, la lectura previa al staging es:

```text
whoami; hostname; date -u
php -v
node -v
go version
apache2ctl -v
df -h /
systemctl list-units --type=service --state=running --no-pager
ls /etc/apache2/sites-enabled
ss -lnt
git -C /var/www/undsurf/mining-drilling-api rev-parse HEAD
git -C /var/www/undsurf/mining-drilling-api status -sb
sudo mysql -N -e "SHOW DATABASES;"
ls -l /opt/undsurf-connector
```

No imprimir `.env` ni usuarios de base. No tocar el VirtualHost ni la base `undsurf` de producción.

No hay un nombre DNS de staging en la documentación. El dato que falta es el FQDN que debe resolver a ese servidor y quedar separado de `api.undsurf.com`, `undsurf.com` y `www.undsurf.com`. Sin ese nombre no hay URL HTTPS para el APK y no se inventa una.

Cuando exista el acceso y el FQDN, el staging previsto es:

- código `3603466` en un directorio distinto de `/var/www/undsurf/mining-drilling-api`;
- base MySQL nueva, no `undsurf`;
- usuarios y memberships ficticios;
- VirtualHost y certificado propios;
- Connector y PostgreSQL aparte, con pozos ficticios, apuntando al API de staging y no al de producción ni al laboratorio local.

Reversión, todavía no ejecutada: deshabilitar solo ese VirtualHost, borrar solo esa base y ese directorio, y dejar producción como estaba. Antes de crearlos hay que copiar los archivos de Apache que se vayan a tocar y anotar el SHA que ya corre en producción.

## 5. APK

No se generó. La sesión EAS sigue siendo `club_villa_mitre`, sin permiso de lectura sobre el proyecto `818054d7-cc88-416f-bdba-3b0d9cf60be0`. No se cambió `projectId`, propietario ni package.

Falta entrar con la cuenta dueña, sin compartir la contraseña:

```text
npx eas-cli login
npx eas-cli build:version:get --platform android --json --non-interactive
```

Después, y solo con la URL HTTPS real de staging:

```text
npx eas-cli env:set --name EXPO_PUBLIC_API_URL --value <URL-HTTPS-STAGING> --environment preview --visibility plaintext
npx eas-cli build --platform android --profile preview
```

`version` local: `1.0.0`. `versionCode` remoto: no leído. El perfil `preview` no incrementa la versión. El paquete sigue siendo `com.javier_alejandro.miningdrillingapp`. No hay build ID ni enlace.

## 6. Recorrido Android, pendiente

1. Entrar con el login mock y abrir «Pozos reales (pruebas)».
2. Iniciar sesión en el Gateway y ver el listado.
3. Desactivar el usuario de prueba y confirmar 401, formulario de nuevo y pozos vacíos.
4. Cerrar la membership y confirmar 403, token conservado y aviso de acceso.
5. Cortar la red o el Connector y confirmar que la sesión sigue y que «Reintentar» recupera.
6. Registrar un avance, perder la respuesta y reintentar la misma operación. El servidor debe devolver la fila existente, no otra.

Esas seis pruebas no se hicieron en un teléfono.
