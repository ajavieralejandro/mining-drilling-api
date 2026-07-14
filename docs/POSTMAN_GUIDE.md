# Guía Postman — Mining Drilling API

**Fecha:** 2026-07-14

## Advertencia

> **Antes de ejecutar requests POST, PATCH o DELETE, confirmar que `base_url` apunta a una API local o de testing.**

No usar producción. Esta guía no ejecuta requests automáticamente.

---

## 1. Requisitos

- PHP 8.2+, Composer, Laravel del repo  
- Base de datos local (`.env.example` declara `pgsql`; el entorno auditado también puede usar sqlite según `.env` local — no se inspeccionó `.env` real)  
- Extensiones PHP según Laravel  
- Postman Desktop o web  
- Colección: `postman/Mining-Drilling-API.postman_collection.json`  
- Environment ejemplo: `postman/Mining-Drilling-Local.postman_environment.example.json`

## 2. Configurar Laravel localmente (manual)

1. Clonar el repositorio.  
2. `composer install` (ejecutado por el responsable, no por esta auditoría).  
3. Copiar `.env.example` → `.env`, generar `APP_KEY`.  
4. Configurar DB local.  
5. `php artisan migrate:fresh --seed` (manual; **no** se ejecutó en la auditoría).  
6. Confirmar usuarios `*@app.test`.

## 3. Levantar servidor (manual)

```bash
php artisan serve
```

Por defecto: `http://127.0.0.1:8000`.  
`base_url` Postman: `http://127.0.0.1:8000/api`

## 4–5. Importar colección y environment

1. Postman → Import → seleccionar ambos JSON.  
2. Duplicar el environment de ejemplo y renombrar (p. ej. `Mining Local`).  
3. No commitear environments con tokens reales.

## 6. Configurar `base_url`

Variable `base_url` = `http://127.0.0.1:8000/api` (sin slash final).

## 7–8. Login y token

1. Carpeta `01 Auth` → `Login`.  
2. Body usa `{{email}}` / `{{password}}`.  
3. Tests script guarda `token` si `body.token` existe.  
4. Requests protegidos envían `Authorization: Bearer {{token}}`.

## 9. Seleccionar IDs

Tras login como admin:

1. `GET /drilling-plans` → copiar `data[0].id` → `plan_id`.  
2. `GET /drilling-plans/{{plan_id}}/platforms` → `platform_id`.  
3. `GET /drill-holes` → `drill_hole_id`.  
4. Observaciones/riesgos → `observation_id` / usar el mismo id para close-risk.  
5. Imports preview → `import_id`.

Los IDs dependen del seed; no están hardcodeados como definitivos.

## 10. Orden recomendado de pruebas

1. Health  
2. Login / Me  
3. Dashboard  
4. Lecturas: plans, platforms, holes, machines, risks, files  
5. Escrituras controladas en local: technical-data, assignment, progress, observation, risk, close-risk  
6. Imports (mock)  
7. Carpeta 15 Authorization Manual Tests (cambiar email/rol)  
8. Carpeta 16 Validation Manual Tests  
9. Carpeta 17 Future (solo lectura de descripciones — **no implementado**)

## 11. Endpoints de lectura (menor impacto)

Health, me, dashboard, plans, platforms, holes show/index, observations GET, risks GET, machines, files, imports GET.

## 12. Endpoints que modifican datos

Login (crea token), logout (borra token), POST holes, PATCH technical-data, assignments, progress, observations, risks, close-risk, imports preview/confirm.

## 13. Pruebas por rol

Cambiar `email` en environment y re-login:

| Rol | Foco |
| --- | ---- |
| admin | acceso total aparente |
| supervisor | assign + close risk |
| geologist | technical-data |
| driller | progress + ver asignados; 403 technical/assign |
| helper | risk en asignado; 403 progress |
| geotechnical | close risk; ver todo aparente |

## 14. Registrar resultados

Usar `docs/POSTMAN_MANUAL_TEST_RESULTS.md`.

## 15. Códigos HTTP (interpretación general Laravel)

| Código | Significado típico |
| ------ | ------------------ |
| 200 | OK |
| 201 | Creado (varios POSTs del API) |
| 204 | No usado aparentamente |
| 401 | No autenticado |
| 403 | Policy / authorize denegado |
| 404 | Modelo no encontrado (binding) |
| 422 | Validación / login inválido |
| 500 | Error servidor — revisar logs locales |

El código HTTP **exacto** de cada caso negativo puede requerir prueba manual.

## 16. Multi-tenant

Las pruebas de carpeta 17 **no pueden ejecutarse** como API real hasta implementar tenancy.

## 17. Evitar apuntar a producción

1. Verificar `base_url` siempre.  
2. No usar DNS productivos.  
3. No pegar tokens productivos en Postman Cloud.  
4. Preferir environment local desconectado.
