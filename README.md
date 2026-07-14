<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

## Mining Drilling API (App-UndSurf)

Backend Laravel de la **Mining Drilling App / App-UndSurf**: API REST para planes de perforación, plataformas, pozos, asignaciones, avances, observaciones/riesgos, máquinas, archivos de plan, importaciones (mock) y auditoría interna.

### Requisitos

- PHP ^8.2
- Composer
- Extensiones PHP requeridas por Laravel 12
- Base de datos: `.env.example` declara **PostgreSQL** (`DB_CONNECTION=pgsql`). El entorno local puede diferir según el `.env` del desarrollador (no se documentan secretos).

### Dependencias principales

- `laravel/framework` ^12
- `laravel/sanctum` ^4.3 (tokens de API)

### Configuración local (manual)

1. Copiar `.env.example` a `.env` y generar `APP_KEY`.
2. Configurar variables de base de datos locales.
3. Instalar dependencias y migrar/seedear en el entorno del desarrollador (fuera del alcance de la auditoría documental).
4. Levantar el servidor **manualmente**:

```bash
php artisan serve
```

API base típica: `http://127.0.0.1:8000/api`

### Variables esperadas (ver `.env.example`)

- `APP_NAME`, `APP_URL`
- `DB_*` (ejemplo pgsql)
- `SANCTUM_STATEFUL_DOMAINS` (incluye localhost:8081 para Expo futuro)
- Drivers de `SESSION_*`, `CACHE_STORE`, `QUEUE_CONNECTION`, `FILESYSTEM_DISK`

No versionar secretos reales.

### Autenticación

- Sanctum personal access tokens.
- `POST /api/auth/login` → `token` + `user`
- `GET /api/auth/me`, `POST /api/auth/logout` (protegidos)
- Header: `Authorization: Bearer {token}`

### Roles existentes (`users.role`)

`admin`, `supervisor`, `geologist`, `driller`, `helper`, `geotechnical`

El rol `geotechnical` es MVP y puede revisarse más adelante.

Usuarios de seeder (solo desarrollo local): `*@app.test` (ver `database/seeders/UserSeeder.php`).

### Tenancy

**Estado actual: single-tenant (sin multi-tenancy).**  
No hay `tenant_id` / companies / memberships. Ver `docs/TENANCY_GAP_ANALYSIS.md`.

### Documentación de auditoría

| Documento | Contenido |
| --------- | --------- |
| [docs/API_ENDPOINTS.md](docs/API_ENDPOINTS.md) | Rutas y contratos |
| [docs/API_DOMAIN_MODEL.md](docs/API_DOMAIN_MODEL.md) | Modelos y relaciones |
| [docs/API_PERMISSIONS_MATRIX.md](docs/API_PERMISSIONS_MATRIX.md) | Roles y policies |
| [docs/API_AUTH.md](docs/API_AUTH.md) | Sanctum |
| [docs/API_SECURITY_AUDIT.md](docs/API_SECURITY_AUDIT.md) | Seguridad estática |
| [docs/TENANCY_GAP_ANALYSIS.md](docs/TENANCY_GAP_ANALYSIS.md) | Multi-tenant gap |
| [docs/FRONTEND_BACKEND_CONTRACT_GAPS.md](docs/FRONTEND_BACKEND_CONTRACT_GAPS.md) | Gaps Expo |
| [docs/POSTMAN_GUIDE.md](docs/POSTMAN_GUIDE.md) | Cómo probar en Postman |
| [docs/POSTMAN_MANUAL_TEST_RESULTS.md](docs/POSTMAN_MANUAL_TEST_RESULTS.md) | Planilla de resultados |
| [docs/API_AUDIT_CHANGELOG_2026-07-14.md](docs/API_AUDIT_CHANGELOG_2026-07-14.md) | Changelog documental |

### Postman

- Colección: `postman/Mining-Drilling-API.postman_collection.json`
- Environment ejemplo: `postman/Mining-Drilling-Local.postman_environment.example.json`

> **Antes de ejecutar requests POST, PATCH o DELETE, confirmar que `base_url` apunta a una API local o de testing.**

Las pruebas automatizadas de Collection Runner / smoke **no** forman parte de la aceptación de la auditoría documental. La validación manual queda a cargo del responsable del proyecto.

### Advertencia de seguridad

- No desplegar con `APP_DEBUG=true` en producción.
- No confiar en ocultar botones del frontend como control de autorización.
- Tokens Sanctum: revisar expiración antes de producción (`config/sanctum.php`).
- Frontend Expo **aún no está conectado** como cliente oficial de esta API.
