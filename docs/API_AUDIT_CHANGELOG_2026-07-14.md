# Changelog documental — auditoría API 2026-07-14

## Alcance de esta auditoría

Se realizaron **únicamente** cambios documentales y de Postman.

Declaraciones de cumplimiento:

- no se ejecutaron tests (`php artisan test` / PHPUnit / Pest);
- no se ejecutaron requests ni smoke tests;
- no se levantó el servidor (`php artisan serve`);
- no se ejecutaron migraciones ni seeders;
- no se modificó lógica funcional (modelos, controladores, rutas, middleware, policies, requests, resources, migraciones, seeders, config, auth, dependencias, tests);
- no se implementó multi-tenancy;
- no se hizo commit;
- no se imprimieron secretos del `.env`.

## Archivos creados o modificados

| Archivo | Acción | Motivo |
| ------- | ------ | ------ |
| `docs/API_STRUCTURE_INVENTORY.md` | creado | Inventario de estructura y estados aparentes |
| `docs/API_ENDPOINTS.md` | creado | Inventario y contratos de endpoints |
| `docs/API_DOMAIN_MODEL.md` | creado | Modelos y relaciones |
| `docs/API_PERMISSIONS_MATRIX.md` | creado | Roles y policies |
| `docs/API_AUTH.md` | creado | Sanctum / login / me / logout |
| `docs/API_SECURITY_AUDIT.md` | creado | Hallazgos de seguridad estática |
| `docs/TENANCY_GAP_ANALYSIS.md` | creado | Brecha multi-tenant |
| `docs/FRONTEND_BACKEND_CONTRACT_GAPS.md` | creado | Gaps Expo ↔ API |
| `docs/POSTMAN_GUIDE.md` | creado | Guía de pruebas manuales Postman |
| `docs/POSTMAN_MANUAL_TEST_RESULTS.md` | creado | Planilla vacía de resultados |
| `docs/API_AUDIT_CHANGELOG_2026-07-14.md` | creado | Este changelog |
| `postman/Mining-Drilling-API.postman_collection.json` | creado | Colección Postman manual |
| `postman/Mining-Drilling-Local.postman_environment.example.json` | creado | Environment de ejemplo (sin secretos de producción) |
| `README.md` | modificado | Se añadió sección de documentación de la API sin borrar contenido Laravel existente |
