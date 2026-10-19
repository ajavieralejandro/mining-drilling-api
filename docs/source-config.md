# Configuración de la base de origen

UndSurf guarda la configuración que el Connector va a usar. No abre la base del cliente. El comando solo lleva `config_id`. El Connector, autenticado con su token, pide el material con `GET /api/connector/v1/source-config/{id}`.

## Quién puede verla

Solo una membership con rol `admin` del tenant. `ResolveTenantContext` ignora un `tenant_id` enviado por el cliente. Un supervisor recibe `can_manage_source: false` y no recibe host, usuario ni contraseña. La contraseña no sale en ninguna respuesta de usuario: solo `password_configured`.

## Endpoints

Bajo la sesión Sanctum y el tenant de la membership:

- `GET /api/tenant/connection` incluye `source` para un administrador.
- `POST /api/tenant/connection/source` guarda un borrador. No lo aplica.
- `POST /api/tenant/connection/source/test` pide al Connector que pruebe ese borrador.
- `POST /api/tenant/connection/source/apply` exige `confirm: true` y una prueba exitosa de los últimos 15 minutos.
- `POST /api/tenant/connection/source/cancel` descarta el borrador.

La configuración queda `active` solo cuando el resultado del Connector trae `applied: true` con la misma versión y la misma huella. Si la prueba falla, la fila activa anterior sigue igual.

## Secretos y auditoría

`password_ciphertext` y `ca_ciphertext` se cifran con `Crypt::encryptString` y `APP_KEY`. La auditoría registra usuario, momento, versión, motor, host, puerto, base, esquema, modo TLS y resultado. No registra usuario de la base ni contraseña.

Un heartbeat que incluya un campo secreto se descarta entero. Un resultado que repita la contraseña guardada se vacía y queda como `SECRET_ECHO`.

## Despliegue

1. Migrar: `php artisan migrate`. Crea `tenant_source_policies` y `tenant_source_configs`.
2. Conservar el `APP_KEY`. Rotarlo deja ilegibles las contraseñas ya cifradas.
3. Opcional: `SOURCE_COMMAND_TIMEOUT_SECONDS` (15 por defecto).
4. Publicar esta API antes que el binario del Connector que entiende `source_config.test@1` y `source_config.apply@1`.
5. No hace falta reiniciar el Connector para aplicar una configuración. El archivo aplicado en el Connector tiene prioridad sobre las variables de entorno; si ese archivo no existe, siguen usándose.

La política de destinos no se edita desde el navegador. Por defecto permite redes privadas y rechaza loopback, metadatos de nube y la base `undsurf`. Un laboratorio en `127.0.0.1` necesita una fila `tenant_source_policies` con `allow_loopback = true`.

## Qué no transfiere datos

Cambiar host, puerto o base cambia el origen de las consultas. No hay copia, réplica ni escritura entre bases. Los pozos se identifican dentro de un origen: la respuesta de pozos incluye `origin_id` y la web descarta el listado en memoria cuando ese origen cambia.
