"""Genera una colección Postman v2.1 por rol a partir de los permisos de RbacSeeder.

Uso: python gen_roles.py <mining-drilling-api>
Escribe postman/roles/UndSurf-Rol-<Rol>.postman_collection.json
"""
import json
import os
import re
import sys
import uuid

API = sys.argv[1]
seeder = open(os.path.join(API, 'database/seeders/RbacSeeder.php'), encoding='utf-8').read()


def perms_of(slug):
    block = re.search(r"'%s' => \[\s*'name'.*?'permissions' => \[(.*?)\]," % slug, seeder, re.S).group(1)
    return sorted(re.findall(r"'([a-z_]+\.[a-z_]+)'", block))


ROLES = [
    ('admin', 'Administrador', 'admin'),
    ('supervisor', 'Supervisor', 'supervisor'),
    ('geologist', 'Geólogo', 'geologist'),
    ('geotechnical', 'Geotécnico', 'geotechnical'),
    ('driller', 'Perforista', 'driller'),
    ('helper', 'Ayudante', 'helper'),
]

TOKEN = 'r_token'  # prefijo propio: los entornos ya traen token_<rol> vacíos, que tienen prioridad.


def url(path):
    p, _, q = path.partition('?')
    u = {'raw': '{{base_url}}' + path, 'host': ['{{base_url}}'], 'path': [s for s in p.split('/') if s]}
    if q:
        u['query'] = [{'key': k, 'value': v} for k, v in (kv.split('=') for kv in q.split('&'))]
    return u


def req(name, method, path, body=None, tests=(), pre=(), auth=True):
    headers = [{'key': 'Accept', 'value': 'application/json'}]
    if body is not None:
        headers.append({'key': 'Content-Type', 'value': 'application/json'})
    if auth:
        headers.append({'key': 'Authorization', 'value': 'Bearer {{%s}}' % TOKEN})
    item = {'name': name, 'event': [], 'request': {'method': method, 'header': headers, 'url': url(path)}}
    if body is not None:
        item['request']['body'] = {'mode': 'raw', 'raw': json.dumps(body, ensure_ascii=False, indent=2)}
    if pre:
        item['event'].append({'listen': 'prerequest', 'script': {'type': 'text/javascript', 'exec': list(pre)}})
    if tests:
        item['event'].append({'listen': 'test', 'script': {'type': 'text/javascript', 'exec': list(tests)}})
    return item


def status(code):
    return 'pm.test("Código %d", function () { pm.expect(pm.response.code).to.eql(%d); });' % (code, code)


def expect(allowed, ok_code, what):
    """Nombre y prueba de una petición según si el rol tiene el permiso."""
    code = ok_code if allowed else 403
    return ('%s (%d)' % (what, code)), [status(code)]


def build(slug, label, seeder_slug):
    perms = set(perms_of(seeder_slug))
    is_admin = slug == 'admin'

    def has(*ps):
        return is_admin or any(p in perms for p in ps)

    sees_all = has('drill_holes.view_all')
    sees_assigned = has('drill_holes.view_assigned')
    items = []

    # 1. Login
    items.append(req('01. Login %s' % label, 'POST', '/auth/login', auth=False,
        body={'email': '{{email_%s}}' % slug, 'password': '{{password_%s}}' % slug},
        tests=[status(200),
               'const json = pm.response.json();',
               'pm.test("Retorna token", function () { pm.expect(json.token).to.be.a("string"); });',
               'if (json.token) { pm.collectionVariables.set("%s", json.token); pm.collectionVariables.set("r_user_id", String(json.user && json.user.id)); }' % TOKEN]))

    # 2. Sesión: rol y permisos exactos de la matriz
    me_tests = [status(200), 'const data = pm.response.json().data || {};', 'const perms = (data.permissions || []).slice().sort();',
                'pm.test("role es %s", function () { pm.expect(data.role).to.eql("%s"); });' % (slug, slug)]
    if is_admin:
        me_tests.append('pm.test("Tiene los permisos de administración", function () { ["personnel.manage", "roles.manage", "source_config.manage", "machines.manage"].forEach(function (p) { pm.expect(perms).to.include(p); }); });')
    else:
        expected = sorted(perms)
        me_tests += [
            'const expected = %s;' % json.dumps(expected),
            'const extra = perms.filter(function (p) { return expected.indexOf(p) === -1; });',
            'const missing = expected.filter(function (p) { return perms.indexOf(p) === -1; });',
            'pm.test("Sin permisos de más (%d esperados)", function () { pm.expect(extra, "de más: " + extra.join(", ")).to.eql([]); });' % len(expected),
            'pm.test("Sin permisos de menos", function () { pm.expect(missing, "faltan: " + missing.join(", ")).to.eql([]); });',
        ]
    items.append(req('02. /auth/me: rol y permisos de la matriz', 'GET', '/auth/me', tests=me_tests))

    # 3. Mis asignaciones (base para comprobar el alcance de pozos)
    items.append(req('03. Mis asignaciones (200)', 'GET', '/tenant/me/assignments', tests=[
        status(200),
        'const list = ((pm.response.json().data || {}).assignments || []).map(function (a) { return String(a.hole_id); });',
        'pm.collectionVariables.set("r_my_hole_ids", JSON.stringify(list));']))

    n, t = expect(has('progress.view'), 200, '04. Mis partes')
    items.append(req(n, 'GET', '/tenant/me/progress?limit=10', tests=t + ([
        'const rows = pm.response.json().data || [];',
        'const me = Number(pm.collectionVariables.get("r_user_id"));',
        'pm.test("Todos los partes son propios", function () { rows.forEach(function (r) { pm.expect(r.user_id).to.eql(me); }); });']
        if has('progress.view') else [])))

    # 4. Pozos
    if sees_all or sees_assigned:
        hole_tests = [status(200), 'const ids = (pm.response.json().data || []).map(function (h) { return String(h.id); });']
        if not sees_all:
            hole_tests += [
                'const mine = JSON.parse(pm.collectionVariables.get("r_my_hole_ids") || "[]");',
                'pm.test("Solo pozos de su cuadrilla", function () { ids.forEach(function (id) { pm.expect(mine).to.include(id); }); });']
        items.append(req('05. Listado de pozos (200%s)' % ('' if sees_all else ', solo los suyos'), 'GET', '/tenant/holes?limit=50', tests=hole_tests))
    else:
        items.append(req('05. Listado de pozos (403)', 'GET', '/tenant/holes?limit=50', tests=[status(403)]))

    skip_no_hole = ['if (!pm.environment.get("hole_id")) { console.log("Se omite: el entorno no tiene hole_id."); pm.execution.skipRequest(); }']
    if sees_all:
        items.append(req('06. Ficha del pozo {{hole_id}} (200 o 404 si no existe)', 'GET', '/tenant/holes/{{hole_id}}', pre=skip_no_hole, tests=[
            'pm.test("Pasa el permiso (200 o 404)", function () { pm.expect([200, 404]).to.include(pm.response.code); });']))
    elif sees_assigned:
        items.append(req('06. Ficha del pozo {{hole_id}} (200 si es suyo, 403 si no)', 'GET', '/tenant/holes/{{hole_id}}', pre=skip_no_hole, tests=[
            'const mine = JSON.parse(pm.collectionVariables.get("r_my_hole_ids") || "[]");',
            'const propio = mine.indexOf(String(pm.environment.get("hole_id"))) !== -1;',
            'pm.test(propio ? "Es suyo: 200" : "No es suyo: 403", function () { pm.expect(pm.response.code).to.eql(propio ? 200 : 403); });']))

    # 5. Lecturas por permiso
    reads = [
        ('07. Personal', '/tenant/personnel', has('personnel.view', 'personnel.manage')),
        ('08. Grupos', '/tenant/groups', has('groups.view', 'personnel.view')),
        ('09. Roles', '/tenant/roles', has('roles.view', 'roles.manage')),
        ('10. Planes', '/tenant/plans', has('plans.view', 'platforms.view')),
        ('11. Bandeja de partes pendientes', '/tenant/progress/pending', has('progress.approve')),
        ('12. Riesgos de la empresa', '/tenant/risks', has('risks.view')),
    ]
    for name, path, allowed in reads:
        n, t = expect(allowed, 200, name)
        items.append(req(n, 'GET', path, tests=t))

    # 6. Escrituras con cuerpo vacío: 422 = pasa el permiso y no crea nada; 403 = sin permiso
    writes = [
        ('13. Alta de personal, cuerpo vacío', '/tenant/personnel', has('personnel.manage')),
        ('14. Alta de grupo, cuerpo vacío', '/tenant/groups', has('groups.manage', 'personnel.manage')),
        ('15. Alta de plan, cuerpo vacío', '/tenant/plans', has('plans.manage')),
        ('16. Alta de pozo, cuerpo vacío', '/tenant/holes', has('drill_holes.create')),
    ]
    for name, path, allowed in writes:
        n, t = expect(allowed, 422, name)
        items.append(req(n, 'POST', path, body={}, tests=t))

    return {
        'info': {
            '_postman_id': str(uuid.uuid5(uuid.NAMESPACE_URL, 'undsurf-rol-' + slug)),
            'name': 'UndSurf — Rol %s' % label,
            'description': (
                'Qué puede y qué no puede hacer el rol %s, según la matriz de permisos (plan 15, sección 4) '
                'y RbacSeeder. Generada desde los permisos del seeder.\n\n'
                'Usa email_%s, password_%s y, opcional, hole_id del entorno (UndSurf-Production o UndSurf-Local). '
                'No crea ni modifica datos: las altas mandan un cuerpo vacío y esperan 422 (con permiso) o 403 (sin permiso).\n\n'
                'Requiere la API en 806e73c o posterior (/tenant/me/*) y el conector en línea para pozos, partes y riesgos.'
            ) % (label, slug, slug),
            'schema': 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
        },
        'item': items,
        'variable': [{'key': k, 'value': ''} for k in [TOKEN, 'r_user_id', 'r_my_hole_ids']],
    }


out = os.path.join(API, 'postman', 'roles')
os.makedirs(out, exist_ok=True)
for slug, label, seeder_slug in ROLES:
    name = 'UndSurf-Rol-%s.postman_collection.json' % label.replace('ó', 'o').replace('é', 'e')
    coll = build(slug, label, seeder_slug)
    with open(os.path.join(out, name), 'w', encoding='utf-8', newline='\n') as f:
        json.dump(coll, f, ensure_ascii=False, indent=2)
    print(name, len(coll['item']), 'peticiones', '| permisos esperados:', len(perms_of(seeder_slug)))
