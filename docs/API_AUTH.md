# Autenticación y Sanctum — Mining Drilling API

**Fecha:** 2026-07-14  
**Fuente:** `AuthController`, `LoginRequest`, `User`, `config/sanctum.php`, `config/auth.php`, migraciones de tokens.  
**Sin ejecución de login real.**

## Resumen estático

| Tema | Hallazgo aparente |
| ---- | ----------------- |
| Paquete | `laravel/sanctum` ^4.3 en `composer.json` |
| Trait User | `HasApiTokens` |
| Guard auth default | `web` (sesión) en `config/auth.php`; API usa bearer Sanctum |
| Middleware protegidos | `auth:sanctum` en `routes/api.php` |
| Login | `POST /api/auth/login` |
| Me | `GET /api/auth/me` |
| Logout | `POST /api/auth/logout` |
| Nombre de token | `api-token` (literal en `createToken`) |
| Expiración global | `config/sanctum.php` → `'expiration' => null` |
| Usuario inactivo | login rechazado tras Auth::attempt si `active` es false |
| Password incorrecta | ValidationException 422 sobre campo `email` |
| Múltiples dispositivos | aparente: cada login crea nuevo personal access token; no se hace purge previo |
| Stateful SPA | `$middleware->statefulApi()` en `bootstrap/app.php`; dominios en CORS/Sanctum orientados a Expo |

## Campos usuario (UserResource)

`id`, `name`, `email`, `role`, `shift`, `active`

Password y `remember_token` están en `$hidden` del modelo.

## Ejemplos para Postman (no ejecutar aquí)

### Login

```http
POST {{base_url}}/auth/login
Accept: application/json
Content-Type: application/json

{
  "email": "{{email}}",
  "password": "{{password}}"
}
```

Respuesta aparente:

```json
{
  "token": "<plainTextToken>",
  "user": {
    "id": 1,
    "name": "...",
    "email": "...",
    "role": "admin",
    "shift": "A",
    "active": true
  }
}
```

Script Postman sugerido (adaptado al contrato real):

```javascript
const body = pm.response.json();
if (body.token) {
  pm.environment.set("token", body.token);
}
if (body.user && body.user.id) {
  pm.environment.set("user_id", String(body.user.id));
}
```

### Me

```http
GET {{base_url}}/auth/me
Accept: application/json
Authorization: Bearer {{token}}
```

### Logout

```http
POST {{base_url}}/auth/logout
Accept: application/json
Authorization: Bearer {{token}}
```

Efecto aparente: elimina el token actual (`currentAccessToken()->delete()`).

## Credenciales de seeder (solo entornos locales)

Documentadas en `UserSeeder` / README de auditoría. Emails `*@app.test`. Password de desarrollo sembrada. **No son secretos de producción.**

## Prueba manual pendiente

- Login ok / incorrecto / inactivo  
- Me con y sin token  
- Logout y reutilización del token  
- Emisión de múltiples tokens  
