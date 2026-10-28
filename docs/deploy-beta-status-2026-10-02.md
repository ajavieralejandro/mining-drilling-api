# UndSurf — Deploy del backend y continuación de la beta — 2026-10-02

**Fecha:** 2 de octubre de 2026.
**Tipo:** estado. Esta actividad solo escribe este archivo. No hubo deploy, build, merge ni cambio funcional desde aquí.
**Evidencia de servidor:** la salida de terminal de la sesión de deploy, resumida en la sección 2. No se transcriben secretos, tokens, DSN ni contenidos de `.env`.

Tres capas, y no se mezclan:

- **Comprobado** en esa salida de terminal.
- **Reportado antes** en otro documento o en el historial git, y no reejecutado en esa sesión.
- **Pendiente.** Sin evidencia, no se declara hecho.

---

## 1. Resumen y semáforo

El checkout de Laravel en `/var/www/undsurf/mining-drilling-api` quedó en `deploy/request-access-revalidation`, commit `ee54d0a28140d14e419de727be5a40d18ebdf4c6`, con árbol limpio. PHP CLI es 8.3.6, Laravel usa MySQL, la migración de lifecycle quedó aplicada en el batch 2, y `config:cache` y `route:cache` terminaron bien. La API por HTTPS, sin token, responde 401 `UNAUTHENTICATED`. Hay un backup de archivos. El dump de MySQL no está confirmado.

Ese 401 comprueba que la cadena HTTPS llega a Laravel y que una llamada anónima no entra. No comprueba login, revalidación de usuario o membership, Connector, PostgreSQL ni Android.

```text
Backend Laravel actualizado en la ruta ya usada por el API.
Backup de archivos presente. Dump de MySQL sin confirmar.
Connector localizado como archivos. Circuito de beta todavía pendiente.
No hay un APK nuevo que este informe pueda afirmar.
```

| Pieza | Semáforo | Capa |
|---|---|---|
| Checkout, rama, HEAD `ee54d0a` y árbol limpio | Verde | Comprobado |
| PHP CLI 8.3.6 y Laravel sobre MySQL | Verde | Comprobado |
| Migración de lifecycle en batch 2 | Verde | Comprobado. El índice parcial de una sola membership activa no se consultó en el servidor |
| `config:cache` y `route:cache` | Verde | Comprobado |
| API HTTPS sin token → 401 `UNAUTHENTICATED` | Verde, alcance corto | Comprobado. No sustituye login ni revalidación |
| Backup de archivos | Verde | Comprobado, ruta en §4 |
| Dump MySQL y reversión de datos | Rojo | Pendiente |
| `composer install` | Ámbar | Comprobado que terminó. Queda la advertencia de desalineación `composer.json` / `composer.lock` |
| Binarios del Connector en las dos rutas | Ámbar | Comprobado que los archivos están. Versión, configuración y compatibilidad siguen sin revisar |
| Procesos Connector / PostgreSQL, `psql`, `docker` | Ámbar | La búsqueda no los mostró. Eso no prueba que no existan |
| `ConnectorLabSeeder.php` | Ámbar | El archivo existe. Contenido y efectos sin revisar |
| Login, listado, detalle, avance | Rojo | Pendiente |
| 401 `USER_INACTIVE`, 403 `NO_ACTIVE_MEMBERSHIP`, reintento sin duplicados | Rojo | Pendiente. El 401 anónimo no los cubre |
| APK instalado y recorrido Android | Rojo | Los informes del día dicen que el APK no se generó. Esta sesión no afirma uno nuevo |

---

## 2. Hechos comprobados en el servidor

Salida de la sesión de deploy. Cada fila es lo que esa salida mostró.

| Hecho | Valor |
|---|---|
| Backend | `/var/www/undsurf/mining-drilling-api` |
| Rama | `deploy/request-access-revalidation` |
| HEAD | `ee54d0a28140d14e419de727be5a40d18ebdf4c6` |
| Árbol de trabajo | Limpio |
| PHP CLI | 8.3.6 |
| Base de Laravel | MySQL |
| Migración de lifecycle | Aplicada, batch 2 |
| Caches | `config:cache` y `route:cache` exitosos |
| API HTTPS sin token | 401 `UNAUTHENTICATED` |
| Backup de archivos | `/home/deployer/undsurf-backups/20261002-120459/backend-files.tar.gz` |
| Backup MySQL | No confirmado |
| Dependencias PHP | `composer install` terminó. Advertencia de desalineación entre `composer.json` y `composer.lock` |
| Connector | `/opt/undsurf-connector/undersurf-connector` y `/home/deployer/undersurf-connector-linux-amd64` |
| Búsqueda de procesos y comandos | No mostró procesos Connector ni PostgreSQL, ni comandos `psql` o `docker` |
| Seeder | Existe `database/seeders/ConnectorLabSeeder.php` |

La desalineación de Composer queda registrada. En el servidor no corresponde correr `composer update` para “alinearla”.

La búsqueda vacía de procesos y de `psql` / `docker` no prueba ausencia total de instalación. Solo dice que esa búsqueda no los mostró.

El contenido de `ConnectorLabSeeder.php` y el efecto de ejecutarlo no se revisaron en esa sesión.

---

## 3. Cambios desplegados y límites de la verificación

### 3.1 Qué árbol quedó en el servidor

El HEAD comprobado es `ee54d0a` (`docs: record the blocked staging deploy and beta APK`, 2026-10-02 13:05 +0200). En el historial local de `mining-drilling-api`, ese commit está en `feat/request-access-revalidation` y en `origin/feat/request-access-revalidation`. El servidor lo tiene chequeado como `deploy/request-access-revalidation`.

Desde el SHA que el informe del 2026-09-09 daba por productivo (`dc3b519`) hasta `ee54d0a`, el historial local tiene:

| SHA | Mensaje |
|---|---|
| `a2d9e78` | `docs: record production deploy status` |
| `be32b6c` | `feat: add membership lifecycle management` |
| `e6c3f30` | `fix: read sqlite busy timeout and journal mode from the environment` |
| `3603466` | `feat: revalidate the user and membership on each request` |
| `ee54d0a` | `docs: record the blocked staging deploy and beta APK` |

`ee54d0a` solo agrega `docs/staging-apk-beta-2026-10-02.md`. El cambio de comportamiento de esa punta está en `3603466`: middleware `EnsureUserIsActive`, ajuste de `ResolveTenantContext`, toque en `MembershipLifecycleService`, rutas y `tests/Feature/RequestAccessRevalidationTest.php`. `be32b6c` es el lifecycle de memberships. Esos commits están en el árbol desplegado. Su comportamiento HTTP en este servidor no se volvió a medir, salvo el 401 anónimo.

El propio `ee54d0a` y `docs/staging-apk-beta-2026-10-02.md` describen un momento anterior: la rama publicada y el staging sin desplegar. Esa frase quedó vieja para el checkout. La salida de hoy muestra ese mismo SHA ya en `/var/www/undsurf/mining-drilling-api`.

### 3.2 Qué sí queda verificado

- El código de esa punta está en la ruta del API, la rama es la indicada y el árbol está limpio.
- Las caches de configuración y de rutas se regeneraron después de ese checkout.
- La migración de lifecycle corrió y figura en el batch 2, con Laravel en MySQL.
- Sin token, la API HTTPS responde 401 `UNAUTHENTICATED`.

### 3.3 Qué no queda verificado

- Login (`POST /api/auth/login`) y cualquier request con bearer.
- Revalidación HTTP: 401 `USER_INACTIVE` y 403 `NO_ACTIVE_MEMBERSHIP`. El código está en el árbol; esta sesión no los provocó en el servidor.
- Listado, detalle y avance de pozos.
- Enrolamiento, heartbeat, polling y consultas del Connector.
- PostgreSQL, datos ficticios y el seeder.
- Que el 401 haya sido sobre una ruta concreta. La salida usada aquí dice “API HTTPS, sin token”, no el path ni un `correlation_id`.
- El nombre de la base MySQL. El informe del 2026-09-09 nombraba `undsurf`. Esta salida solo confirma el driver MySQL.
- Un directorio de staging distinto, un FQDN nuevo o un VirtualHost nuevo. El plan de `docs/staging-apk-beta-2026-10-02.md` pedía eso, separado de esta ruta. La salida de hoy no lo muestra.
- El índice único parcial `memberships_one_active_per_user`. En el código de esta migración (`2026_09_12_000001_add_lifecycle_fields_to_memberships_table`) ese índice se crea solo si el driver es `pgsql` o `sqlite`. Con MySQL el código lo omite. No hubo `SHOW INDEX` en la sesión de servidor, así que el índice no está confirmado ni desmentido por una consulta: el fuente desplegado indica que en MySQL no se crea.

Los tests de `3603466` (informe `docs/request-access-revalidation-2026-10-02.md`: PHPUnit en SQLite `:memory:`) y el laboratorio de loopback del 2026-10-01 siguen siendo evidencia de otras máquinas. No se convierten en evidencia de este servidor.

---

## 4. Reportes anteriores (no reejecutados aquí)

| Documento | Qué decía | Cómo se lee ahora |
|---|---|---|
| `docs/deploy-status-2026-09-09.md` | Control plane en `https://api.undsurf.com`, HEAD `dc3b519`, MySQL, 401 anónimo en `GET /api/tenant/holes`. Connector copiado y sin configurar. PostgreSQL inactivo y `psql` ausente en esa fecha | El 401 anónimo de entonces no se reutiliza como prueba de hoy. El HEAD de hoy es `ee54d0a`. Host, DNS y certificado no se volvieron a medir en esta salida |
| `docs/local-gateway-lab-2026-10-01.md` | Circuito local en loopback: listado 200, Connector enrolado, 503 al detenerlo | Sigue siendo laboratorio local. No es este servidor |
| `docs/request-access-revalidation-2026-10-02.md` | Revalidación cubierta por tests en SQLite de memoria, sin contacto con `api.undsurf.com` | El código llegó al servidor dentro de `ee54d0a`. Los tests no se repitieron contra MySQL de ese host |
| `docs/staging-apk-beta-2026-10-02.md` (también dentro de `ee54d0a`) | Rama publicada. Staging no desplegado. SSH denegado. APK no generado. Último SHA productivo escrito: `dc3b519` | Foto previa a la sesión de servidor de hoy. El checkout ya no es `dc3b519` |
| `mining-drilling/docs/gateway-access-handling-2026-10-02.md` y `mining-drilling/docs/apk-pruebas-gateway-2026-10-02.md` | Manejo de acceso en `/pozos-real` y decisión de no generar APK | Siguen vigentes para el teléfono: no hay recorrido Android |

---

## 5. Estado móvil reportado

No hay evidencia, en la salida de servidor ni en estos informes, de un APK nuevo. Este documento no afirma que exista.

Lo reportado el 2026-10-02, en los documentos de `mining-drilling` y en el git local de esa app (lectura de esta actividad, sin build):

| Dato | Reporte |
|---|---|
| Rama | `feat/gateway-access-handling` |
| Punta local y `origin` al escribir este archivo | `d1d6c64a10061de43825c605176c0e1a6c16beed` |
| Commits de producto encima de `6f59b95` | `e11da37` cierra datos de gateway si el acceso se revoca. `d1041cf` conserva `idempotency_key` en el reintento de un avance y muestra «Pozos reales (pruebas)». `d1d6c64` es el informe de staging |
| Login principal | Sigue siendo el mock. El gateway real entra por `/pozos-real` |
| APK, Play Store, OTA | Los informes dicen que el APK no se generó, que la sesión EAS no pudo leer el proyecto y que no hay canal OTA |
| Pruebas | `tsc` y `npm test` en la máquina de desarrollo. Ninguna en un teléfono |

El informe guardado dentro del API (`ee54d0a`) nombra la app en `d1041cf`. La punta publicada después, `d1d6c64`, es documentación. Ninguno de los dos SHA es un binario instalable.

---

## 6. Backup y reversión pendientes de completar

### Comprobado

Archivos del backend:

```text
/home/deployer/undsurf-backups/20261002-120459/backend-files.tar.gz
```

Esa ruta es el respaldo de archivos de esta sesión. No se abrió el tar ni se hizo un restore de prueba.

### Sin confirmar

- Dump de MySQL. Sin ese archivo, la reversión de datos no está cerrada.
- Integridad del tar (tamaño, checksum, listado).
- SHA anterior todavía presente en el servidor como ref recuperable. El último SHA productivo escrito antes de hoy sigue siendo el del informe del 2026-09-09: `dc3b519`. Esta salida no lo releyó del reflog.
- Caches: un retorno de código tiene que volver a generar `config:cache` y `route:cache`. Esas caches ya se reescribieron sobre `ee54d0a`.
- Estado del Connector y de PostgreSQL anterior a esta sesión. La búsqueda no alcanzó para describirlo.

Hasta confirmar el dump, la reversión posible con lo ya visto es solo de archivos, y todavía sin ensayo. No se documenta aquí un procedimiento de restore.

---

## 7. Plan

Orden de lo que falta. La única actividad siguiente es la primera revisión del Connector y del seeder, sin ejecutarlos.

1. Confirmar el dump de MySQL (ruta, momento y que corresponde a la base que Laravel está usando). Sin eso, el backup de esta sesión queda incompleto.
2. Revisar documentación, `database/seeders/ConnectorLabSeeder.php` y la configuración del Connector. No ejecutarlo. No imprimir tokens, DSN ni `.env`.
3. Confirmar la versión del binario en `/opt/undsurf-connector/undersurf-connector` y en `/home/deployer/undersurf-connector-linux-amd64`, y si esa versión habla con el API de `ee54d0a`.
4. Preparar PostgreSQL y datos ficticios.
5. Crear usuario y membership con los mecanismos que ya existen en el API. No inventar un alta paralela.
6. Enrolar y ejecutar el Connector.
7. Probar login, listado, detalle y avance.
8. Probar 401 `USER_INACTIVE`, 403 `NO_ACTIVE_MEMBERSHIP` y un reintento de avance que no duplique la fila.
9. Generar e instalar un APK, y repetir ese recorrido en Android.

`composer update` no forma parte de este plan en el servidor.

### Próxima actividad, una sola

Revisar la instalación y la configuración del Connector, y leer el seeder, sin ejecutarlo.

---

## 8. Criterios de aprobación y evidencia a guardar

La beta de este circuito se aprueba solo cuando todas las filas siguientes tienen evidencia de la misma corrida, en el servidor (y, en la última, en un Android). Un 401 `UNAUTHENTICATED` sin token no alcanza para marcar ninguna fila de login, revalidación, Connector o teléfono.

| Criterio | Hecho cuando |
|---|---|
| Backup reversible | Dump de MySQL identificado, además del tar de archivos ya visto |
| Connector compatible | Versión de binario anotada y coherente con las operaciones `drill_holes.list@1`, `drill_holes.get@1`, `progress.create@1` y `progress.list@1` |
| Datos de prueba | PostgreSQL con filas ficticias, separado en el informe de cualquier base real de cliente |
| Identidad | Usuario y una membership activa creados por el mecanismo existente. Valores de token fuera de git y de este documento |
| Login | `POST /api/auth/login` con HTTP y código de éxito, sin copiar el bearer |
| Listado, detalle y avance | 200 con `request_id` y `correlation_id`, filas que coinciden con las ficticias |
| Acceso revocado | 401 `USER_INACTIVE` y 403 `NO_ACTIVE_MEMBERSHIP` provocados a propósito, con el token emitido antes del cambio de estado |
| Reintento | El segundo envío de la misma operación devuelve la fila ya creada y no inserta otra |
| Android | APK identificado (build, `versionCode`, SHA de la app) instalado, y el mismo recorrido repetido en el teléfono |

Evidencia a conservar, sin secretos:

- SHA del API, rama, `git status` y salida de `migrate:status` que muestre el batch de lifecycle.
- Ruta del tar y ruta del dump de MySQL, con fecha. Sin volcar el contenido.
- Versión o hash del binario del Connector y la ruta usada.
- Códigos HTTP y `error.code` de las pruebas. Los bearer, el enrollment token, el DSN y `APP_KEY` no se copian.
- Identificadores de comando (`request_id`, `correlation_id`) del listado, del detalle y del avance, y la fila de idempotencia del reintento.
- Para el APK: build ID o archivo, `versionCode` y el SHA de `mining-drilling` con el que se compiló.

Hasta entonces, el estado a comunicar es el del recuadro de la sección 1.
