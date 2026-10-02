# UndSurf — Revalidación de acceso por request — 2026-10-02

**Ejecución:** 2 de octubre de 2026, 00:11 (+02), al cerrar esta sesión. La consigna llegó el 1 de octubre; el reloj de la máquina al verificar el laboratorio ya marcaba el día 2.
**Alcance:** revalidación de `users.active` y de la membership activa en `mining-drilling-api`, en una rama aislada. Análisis de la app móvil y del APK, sin modificarlos. Sin commit, push, merge, deploy, build de APK ni OTA. Sin contacto con `api.undsurf.com` ni con otros servidores remotos. El stash del API no se aplicó. CRABB y el resto de proyectos no se tocaron.

El laboratorio de `docs/local-gateway-lab-2026-10-01.md` sigue sirviendo el checkout de `main`. Esta sesión no cambió ese código.

## 1. Git verificado antes de modificar

| Repo | Rama | HEAD | Working tree | Worktrees |
|---|---|---|---|---|
| `mining-drilling-api` | `main` | `e6c3f305e6f9866bb2686c8ae06737d48f2b0f91` | Igual a `origin/main`, más `docs/local-gateway-lab-2026-10-01.md` sin trackear | Solo el checkout principal |
| `mining-drilling` | `feat/data-gateway` | `6f59b957673c87235706af210b8ecbef36fed268` | Igual a `origin/feat/data-gateway`, más `.env` sin trackear | Solo el checkout principal |

`stash@{0}` del API seguía presente y no se aplicó. El Connector y la web no se modificaron.

Cambiar de rama en el checkout de `main` habría cambiado el código que sirven los workers de los puertos 8011–8013. La rama se creó en un worktree:

```text
C:\Users\Dell\Desktop\Javi\UndSurf\.worktrees\api-request-access-revalidation
feat/request-access-revalidation
base e6c3f305e6f9866bb2686c8ae06737d48f2b0f91
```

Al cerrar, `mining-drilling-api` en `main` sigue en `e6c3f30`. Su único archivo sin trackear sigue siendo el informe del laboratorio.

## 2. Comportamiento anterior y nuevo

**Antes.** `users.active` se consultaba en el login. Un token Sanctum ya emitido seguía autorizando aunque el usuario pasara a inactivo. `ResolveTenantContext` exigía una membership `active` solo en `/api/tenant/*`, leyendo la fila en esa request. `MembershipLifecycleService::deactivate()` borra los tokens del usuario, pero un cambio directo de `users.active` o de `memberships.status` que no pase por ese método dejaba el token usable. El dominio legado (pozos, planes, máquinas y el resto del grupo `auth:sanctum`) no miraba ni el flag ni la membership.

**Ahora.** Cada request autenticada de usuario relee `users.active` desde la base. No se confía en el valor que tenía el modelo al emitir el token. Si el usuario ya no existe o `active` es falso, la respuesta es **401** `USER_INACTIVE` y el controlador no corre. En `/api/tenant/*`, además, `ResolveTenantContext` vuelve a leer la membership activa. Si no hay ninguna, la respuesta sigue siendo **403** `NO_ACTIVE_MEMBERSHIP`, antes de crear un `ConnectorCommand`.

El tenant sigue saliendo solo de esa membership. Un `tenant_id` de query, body o header no elige la minera. Una membership de otra minera no autoriza la que no está activa. Sigue vigente como máximo una membership `active` por usuario; no hubo migración.

### Códigos

| Situación | HTTP | `error.code` | `request_id` |
|---|---|---|---|
| Sin token o token ya revocado | 401 | `UNAUTHENTICATED` | null |
| Token presente y usuario inactivo | 401 | `USER_INACTIVE` | null |
| Usuario activo sin membership activa | 403 | `NO_ACTIVE_MEMBERSHIP` | null |

En `/api/tenant/*` el `correlation_id` de `ResolveRequestCorrelation` se conserva, incluido el `X-Correlation-Id` válido que envíe el cliente. En `/api/auth/me` y en el dominio legado ese middleware no corre, así que `correlation_id` queda null. El cuerpo sigue el contrato ya usado por `GatewayException`: `request_id`, `correlation_id`, `error.code`, `error.message`.

## 3. Rutas cubiertas y excepciones

`EnsureUserIsActive` corre después de `auth:sanctum` en:

- `GET /api/auth/me`
- el grupo legado: dashboard, planes, plataformas, pozos, asignaciones, avance, observaciones, riesgos, máquinas e importaciones CSV
- `GET /api/tenant/holes` y `GET /api/tenant/holes/{hole}`, antes de `ResolveTenantContext`

**No se exige membership** en `/me` ni en el grupo legado. Esas rutas siguen autorizadas por el rol global `users.role` y sus policies. Esas tablas todavía no tienen frontera de minera. Exigir membership ahí habría cerrado el dominio legado sin tenantizarlo.

**Logout** (`POST /api/auth/logout`) queda solo con `auth:sanctum`. Un usuario ya inactivo puede revocar el token actual. No se le pide membership. La request siguiente, con ese token borrado, responde `UNAUTHENTICATED`.

**Fuera de esta revalidación**, a propósito:

- `GET /api/health` sigue público.
- `POST /api/auth/login` no cambia: un usuario inactivo sigue recibiendo el 422 de validación y no obtiene token.
- `/api/connector/v1/*` sigue en `AuthenticateConnector`.
- `POST /api/internal/demo/list-holes` sigue en `AuthenticateDemoInternal`.

`deactivate()` sigue borrando los tokens del usuario afectado. La revalidación cubre el caso en que ese borrado no ocurre.

## 4. Tests

PHPUnit usa `phpunit.xml`: SQLite `:memory:`. No se usó el SQLite del laboratorio ni PostgreSQL. El worktree no tiene el `.env` del laboratorio. Se creó ahí un `.env` ignorado por git, con `DB_CONNECTION=sqlite` y `DB_DATABASE=:memory:`, solo para que el arranque de `php artisan test` no avise por un archivo ausente. No contiene secretos.

Los casos nuevos emiten el token **antes** del cambio de estado. La membership se cierra con un `UPDATE` directo, no con `deactivate()`, para que el token siga en la base.

| Caso | Resultado en esta corrida |
|---|---|
| Usuario activo y membership activa | 200, el listado sigue accesible |
| Login de usuario activo y `GET /api/health` sin token | 200 |
| Usuario desactivado después del token | 401 `USER_INACTIVE` en `/api/tenant/holes`, `/me`, pozos, planes y máquinas. Cero comandos |
| Membership desactivada después del token | 403 `NO_ACTIVE_MEMBERSHIP` en el tenant. Cero comandos. `/me` y máquinas siguen 200 |
| Usuario sin membership | 403 en `/api/tenant/holes`. Cero comandos |
| Membership de otra minera, con el connector de esa minera en línea | 503 `CONNECTOR_OFFLINE` del tenant propio. Cero comandos hacia la otra |
| Baja de la membership del usuario A | A recibe 403. El usuario B, con token previo, sigue llegando a su tenant (504 de espera, comando solo de B) |
| Cerrar de nuevo la membership histórica de A mientras B está activa | El comando sale hacia B, aunque el cliente envíe el id de A |
| Logout con usuario inactivo | 200 y el token queda borrado. La request siguiente es `UNAUTHENTICATED` |
| Rol global `admin` sin membership | `/me`, máquinas, planes y pozos del control plane en 200. El gateway de tenant en 403 |
| Heartbeat del Connector sin bearer de usuario | 401 `UNAUTHORIZED`, no `USER_INACTIVE` |

```text
php artisan test --filter=RequestAccessRevalidationTest
Tests:    11 passed (93 assertions)
```

Esa corrida se repitió después de Pint sobre el archivo nuevo. Mismo resultado.

```text
php artisan test
Tests:    83 passed (410 assertions)
Duration: 10.96s
```

La suite completa se ejecutó sobre la implementación, antes del ajuste de imports de Pint. Pint no tocó lógica. 72 tests ya existían en `e6c3f30`; los 11 nuevos son los de esta sesión. 317 + 93 = 410. En esta ejecución no hubo fallos nuevos ni fallos de tests preexistentes. No se reutiliza como resultado de hoy la corrida histórica de 72 tests del informe del laboratorio.

Pint, al cerrar, pasó sobre los cinco archivos PHP de esta sesión. El aviso de `composer.lock` desactualizado apareció al instalar dependencias en el worktree. Es la deuda ya anotada el 2026-09-09 y el 2026-10-01. El lock no se modificó.

## 5. Laboratorio

No se reinició ni se detuvo ningún proceso. Los listeners al cerrar coinciden con los PID del informe del laboratorio:

| Puerto | PID |
|---|---|
| 5432 PostgreSQL | 13776 |
| 8010 proxy | 24084 |
| 8011, 8012, 8013 workers | 26716, 30180, 16844 |
| Connector | 11384 |

Esos workers cargan `mining-drilling-api` en `main` / `e6c3f30`. No cargan `feat/request-access-revalidation`. Esta sesión no repitió el GET autenticado del laboratorio.

## 6. App móvil — análisis, sin cambios

Revisión de `mining-drilling` en `6f59b95`. No se editó ningún archivo. No se leyó la documentación versionada de Expo: `AGENTS.md` la exige antes de escribir código, y esta etapa no lo escribe.

Hay dos sesiones distintas. `authStore` y `app/login.tsx` son el login mock del producto. `gatewaySessionStore` es el token real, solo en memoria, solo para `app/pozos-real/*`. El token no se guarda en disco: no hay `AsyncStorage`, `SecureStore` ni `persist` en `src/`. No hay cola offline ni requests pendientes guardados. El `RefreshControl` de la lista es un pull-to-refresh, no una caché.

`httpClient` ya parsea `error.code`, `error.message`, el status y `request_id`. `describeError` traduce `CONNECTOR_OFFLINE`, `TIMEOUT`, `NOT_FOUND` y `NETWORK_ERROR`. Cualquier otro código, incluidos `USER_INACTIVE` y `NO_ACTIVE_MEMBERSHIP`, se muestra como texto `código: mensaje`. Ningún código borra el token ni navega al login.

La URL sale de `EXPO_PUBLIC_API_URL` o, si falta, de `http://127.0.0.1:8010`. El `.env` local, sin trackear, tiene una sola clave y apunta a `http` / `127.0.0.1` / puerto 8010. `.env.example` documenta el mismo destino. El archivo sigue sin estar en `.gitignore`.

`/pozos-real` no está en `navigationAccess.ts` ni en el menú. `app/index.tsx` manda al login mock o al dashboard mock. `app/_layout.tsx` no protege rutas con el token real.

### Problemas comprobados

1. Un 401 `USER_INACTIVE` o `UNAUTHENTICATED` en la lista o el detalle deja el token en memoria y muestra `ErrorState` con reintento. El botón «Salir» solo está en la lista cuando la carga fue bien. No hay llamada a `POST /api/auth/logout`.
2. Un 403 `NO_ACTIVE_MEMBERSHIP` tampoco distingue acceso revocado de un fallo de red. El token sigue. No existe selector de otra minera: el cliente no envía `tenant_id` y el servidor solo admite una membership activa.
3. `CONNECTOR_OFFLINE`, `TIMEOUT` y `NETWORK_ERROR` ya no se tratan como cierre de sesión. Hay que conservar eso.
4. `load` no aborta ni numera la request. Hoy, al poner el token en null, `HolesList` se desmonta, así que un `setState` tardío no pinta otra cuenta. Si la pantalla deja de desmontarse, o si dos cargas de la misma lista se pisan, la respuesta vieja puede escribir el estado nuevo. El detalle hace lo mismo con `Promise.all`.
5. El logout real no avisa al servidor. El logout del dashboard solo limpia el mock.
6. Los datos mock de pozos, planes y máquinas no son datos del gateway. Perder la membership no los borra, porque nunca salieron del tenant. Son otra sesión.

No hay caché de pozos reales entre cuentas. No se inventa un modo offline.

### Mejoras opcionales

- Unificar `authStore` y `gatewaySessionStore`.
- Entrada de menú hacia `/pozos-real`.
- Dejar de llamar `GET/POST /api/tenant/holes/{id}/progress`: esas rutas no existen en el API. Un 404 de avance no es una revocación; hoy tira abajo todo el detalle por el `Promise.all`.
- Ignorar `.env` antes de cualquier commit en la app.
- Persistir el token. Hoy no se persiste; agregarlo no hace falta para esta revalidación.

### Cambios propuestos

| Archivo / flujo | Problema | Cambio | Prioridad | Validación |
|---|---|---|---|---|
| `httpClient.ts`, `describeError` | 401 y 403 nuevos se muestran como texto genérico | Tratar `USER_INACTIVE` y `UNAUTHENTICATED` como sesión inválida. Tratar `NO_ACTIVE_MEMBERSHIP` como acceso al tenant, no como red. Dejar `NETWORK_ERROR`, `CONNECTOR_OFFLINE`, `TIMEOUT`, 502 y 504 fuera de ese grupo | Necesaria | Un 503 no borra el token. Un 401 sí lleva al login del gateway |
| `gatewaySessionStore.ts`, `gatewayAuthService.ts` | Logout solo borra memoria. El 401 no dispara logout | Ante sesión inválida, intentar `POST /api/auth/logout` y siempre limpiar token y email. El 403 no llama a ese cierre | Necesaria | Token inactivo: la pantalla vuelve al formulario. Token válido con membership cerrada: el token sigue y el mensaje no es el de red |
| `app/pozos-real/index.tsx`, `app/pozos-real/[id].tsx` | El error de acceso no ofrece salida. Una respuesta tardía puede escribir estado si el componente sigue montado | Limpiar pozos al perder el token o al recibir 403. Ignorar la respuesta si el token o la generación de la carga ya cambiaron. En el 401, mostrar el login del gateway, no solo «Reintentar» | Necesaria | Cambiar de cuenta y volver: no aparece la lista anterior. Una request lenta de la cuenta previa no la repone |
| `app/login.tsx`, `app/index.tsx`, `navigationAccess.ts` | El arranque sigue en el mock. `/pozos-real` no está en el menú | Camino visible hasta el login real | Opcional para esta revalidación. Necesaria para un recorrido de producto | Desde el arranque se llega al listado real sin escribir la ruta |
| `authStore.ts` y pantallas mock | Segunda sesión, datos demo | No mezclarlos con el cierre del gateway en este paso | Opcional | El dashboard mock no se vacía por un 403 del gateway, y tampoco muestra pozos reales |
| `.gitignore` | `.env` sin ignorar | Ignorarlo sin commitearlo | Necesaria antes de un commit de la app. Fuera de esta sesión | `git check-ignore` lo señala |

Antes de implementar esa tabla hay que leer la documentación de Expo SDK 54 en `https://docs.expo.dev/versions/v54.0.0/`.

## 7. APK

No hay evidencia local suficiente para decir qué contiene un APK.

- En el repo no hay `.apk` ni `.aab`. `git ls-files` no lista ninguno. El historial de mensajes no menciona un APK.
- No existe el directorio `android/`.
- `app.json` tiene `version` `1.0.0` y no tiene `versionCode`. `package.json` repite `1.0.0`. `eas.json` declara `appVersionSource: remote` y `autoIncrement` solo en el perfil `production`. El `versionCode` remoto no se consultó: esta sesión no llama a servicios externos.
- `expo-updates` no está en `package.json`. `app.json` no define `runtimeVersion` ni `updates`. No hay canal OTA en el código.

| Pregunta | Respuesta con este límite |
|---|---|
| ¿El APK comprobable incluye el Data Gateway de `6f59b95`? | No hay un APK comprobable en el disco de trabajo. No se afirma que sí ni que no |
| ¿Es compatible con `USER_INACTIVE` y `NO_ACTIVE_MEMBERSHIP`? | El código de `6f59b95` muestra esos códigos como texto y no cierra la sesión. Si un APK se construyó con ese commit, ese sería su comportamiento. Sin el binario, no se afirma |
| ¿APK, OTA o solo backend? | El backend puede desplegarse solo. La app actual no queda rota: muestra el error y permite reintentar. El manejo correcto de sesión es un cambio JavaScript. Con la configuración actual no hay OTA que lo entregue. Hace falta un build nuevo, o incorporar `expo-updates` en un build previo que hoy no existe |
| ¿Qué evidencia falta? | El archivo del último APK distribuido, su `versionCode` y el SHA con el que se compiló. La lista de builds de EAS, si se decide consultarla en otra sesión |

No se generó un build ni se publicó una actualización.

## 8. Pendientes para un despliegue

1. Revisar y, cuando se pida, commitear `feat/request-access-revalidation`. Hoy no está en `main` ni en el laboratorio.
2. El laboratorio solo verá este comportamiento si se arranca a propósito desde el worktree, sin reemplazar los workers actuales a ciegas.
3. Producción sigue sin SHA verificado. El último documentado es `dc3b519`, anterior a `e6c3f30` y a esta rama. No desplegar este cambio encima de un SHA desconocido.
4. La app tiene que aprender los dos códigos antes de dar por usable el corte de acceso. Puede ir en un segundo paso. El backend no la espera para rechazar.
5. Sigue sin endpoint HTTP de alta y baja. `deactivate()` no se expuso.
6. El dominio legado sigue sin aislamiento por minera. Esta sesión no lo cerró.
7. `composer.lock` sigue desfasado.
8. Ignorar `.env` de la app antes de cualquier commit móvil.

## 9. Confirmación

No hubo commit, push, merge, deploy, build de APK ni publicación OTA. No se configuró `origin` en el repositorio paraguas. No se imprimieron secretos, tokens ni DSN en este informe.
