<?php

namespace Database\Seeders;

use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Dashboard
            ['name' => 'dashboard.view_global', 'module' => 'dashboard', 'description' => 'Ver métricas globales de toda la plataforma'],
            ['name' => 'dashboard.view_operational', 'module' => 'dashboard', 'description' => 'Ver métricas operativas de faena y perforación'],
            ['name' => 'dashboard.view_technical', 'module' => 'dashboard', 'description' => 'Ver métricas técnicas y litológicas'],
            ['name' => 'dashboard.view_personal', 'module' => 'dashboard', 'description' => 'Ver métricas personales de turno y cuadrilla'],
            ['name' => 'dashboard.view_risks', 'module' => 'dashboard', 'description' => 'Ver métricas y panel de riesgos geotécnicos'],

            // Proyectos y Minas por Tenant
            ['name' => 'projects.view', 'module' => 'proyectos', 'description' => 'Ver proyectos mineros de la organización'],
            ['name' => 'projects.manage', 'module' => 'proyectos', 'description' => 'Crear, renombrar y administrar proyectos mineros'],
            ['name' => 'mines.view', 'module' => 'minas', 'description' => 'Ver minas y faenas de la organización'],
            ['name' => 'mines.manage', 'module' => 'minas', 'description' => 'Crear, renombrar y vincular pozos a minas'],

            // Planes y Plataformas
            ['name' => 'plans.view', 'module' => 'planes', 'description' => 'Ver planes de perforación'],
            ['name' => 'plans.manage', 'module' => 'planes', 'description' => 'Crear y administrar planes de perforación'],
            ['name' => 'platforms.view', 'module' => 'plataformas', 'description' => 'Ver plataformas y abanicos de pozos'],

            // Pozos
            ['name' => 'drill_holes.view_all', 'module' => 'pozos', 'description' => 'Ver todos los pozos de la organización'],
            ['name' => 'drill_holes.view_assigned', 'module' => 'pozos', 'description' => 'Ver únicamente los pozos asignados'],
            ['name' => 'drill_holes.create', 'module' => 'pozos', 'description' => 'Crear nuevos pozos'],
            ['name' => 'drill_holes.update', 'module' => 'pozos', 'description' => 'Actualizar información general de pozos'],
            ['name' => 'drill_holes.update_technical_data', 'module' => 'pozos', 'description' => 'Modificar datos técnicos (azimut, buzamiento, cota)'],

            // Cuadrillas / Asignaciones
            ['name' => 'assignments.view', 'module' => 'cuadrillas', 'description' => 'Ver cuadrillas y personal asignado a pozos'],
            ['name' => 'assignments.manage', 'module' => 'cuadrillas', 'description' => 'Asignar y desvincular personal a pozos'],

            // Avance
            ['name' => 'progress.view', 'module' => 'avances', 'description' => 'Ver historial y planillas de avance de perforación'],
            ['name' => 'progress.create', 'module' => 'avances', 'description' => 'Registrar avance de perforación diario'],
            ['name' => 'progress.approve', 'module' => 'avances', 'description' => 'Revisar, aprobar o rechazar partes diarios de perforación'],
            ['name' => 'progress.initialize_depth', 'module' => 'avances', 'description' => 'Inicializar o recalibrar profundidad base de pozo'],

            // Geología & Loguera
            ['name' => 'geology.log_cores', 'module' => 'geologia', 'description' => 'Asentar corridas de testigos y cálculo de RQD en loguera'],

            // Observaciones
            ['name' => 'observations.view', 'module' => 'observaciones', 'description' => 'Ver libro de novedades y notas geológicas'],
            ['name' => 'observations.create', 'module' => 'observaciones', 'description' => 'Registrar observaciones en pozo'],

            // Riesgos
            ['name' => 'risks.view', 'module' => 'riesgos', 'description' => 'Ver matriz y listado de riesgos'],
            ['name' => 'risks.create', 'module' => 'riesgos', 'description' => 'Reportar alerta de riesgo operativo o geotécnico'],
            ['name' => 'risks.close', 'module' => 'riesgos', 'description' => 'Cerrar y validar mitigación de riesgos'],

            // Maquinaria
            ['name' => 'machines.view', 'module' => 'maquinas', 'description' => 'Ver catálogo de máquinas y estados operativos'],
            ['name' => 'machines.manage', 'module' => 'maquinas', 'description' => 'Administrar maquinaria y disponibilidad'],

            // Personal y Membresías
            ['name' => 'personnel.view', 'module' => 'personal', 'description' => 'Ver nómina de personal de la organización'],
            ['name' => 'personnel.manage', 'module' => 'personal', 'description' => 'Gestionar integrantes de la organización y bajas'],

            // Grupos de Usuarios
            ['name' => 'groups.view', 'module' => 'grupos', 'description' => 'Ver grupos de usuarios y cuadrillas'],
            ['name' => 'groups.manage', 'module' => 'grupos', 'description' => 'Crear y administrar grupos de usuarios'],

            // Importaciones
            ['name' => 'imports.manage', 'module' => 'importaciones', 'description' => 'Importar archivos CSV y planos de perforación'],

            // Conexión y Origen
            ['name' => 'source_config.view', 'module' => 'conexion', 'description' => 'Ver configuración de fuente de datos del cliente'],
            ['name' => 'source_config.manage', 'module' => 'conexion', 'description' => 'Configurar parámetros de origen del Connector'],

            // Auditoría y Sistema
            ['name' => 'audit.view', 'module' => 'auditoria', 'description' => 'Consultar bitácora de auditoría forense'],
            ['name' => 'roles.view', 'module' => 'roles', 'description' => 'Consultar roles y permisos del sistema y de la organización'],
            ['name' => 'roles.manage', 'module' => 'roles', 'description' => 'Crear roles y configurar matriz de permisos'],
            ['name' => 'users.manage', 'module' => 'usuarios', 'description' => 'Administrar cuentas de usuario a nivel sistema'],
            ['name' => 'tenants.manage', 'module' => 'organizaciones', 'description' => 'Administrar organizaciones de la plataforma'],
        ];

        $permissionModels = [];
        foreach ($permissions as $perm) {
            $permissionModels[$perm['name']] = Permission::updateOrCreate(
                ['name' => $perm['name']],
                [
                    'module' => $perm['module'],
                    'description' => $perm['description'],
                ]
            );
        }

        $allPermissionIds = collect($permissionModels)->pluck('id')->all();

        // Roles del Sistema
        $rolesDefinition = [
            'super_admin' => [
                'name' => 'Super Administrador',
                'description' => 'Administrador global de la plataforma con acceso irrestricto.',
                'permissions' => array_keys($permissionModels),
            ],
            'admin' => [
                'name' => 'Administrador de Organización',
                'description' => 'Gestión completa de la empresa, personal, maquinaria y configuración.',
                'permissions' => [
                    'dashboard.view_global',
                    'dashboard.view_operational',
                    'dashboard.view_technical',
                    'dashboard.view_personal',
                    'dashboard.view_risks',
                    'projects.view',
                    'projects.manage',
                    'mines.view',
                    'mines.manage',
                    'plans.view',
                    'plans.manage',
                    'platforms.view',
                    'drill_holes.view_all',
                    'drill_holes.view_assigned',
                    'drill_holes.create',
                    'drill_holes.update',
                    'drill_holes.update_technical_data',
                    'assignments.view',
                    'assignments.manage',
                    'progress.view',
                    'progress.create',
                    'progress.approve',
                    'progress.initialize_depth',
                    'geology.log_cores',
                    'observations.view',
                    'observations.create',
                    'risks.view',
                    'risks.create',
                    'risks.close',
                    'machines.view',
                    'machines.manage',
                    'personnel.view',
                    'personnel.manage',
                    'groups.view',
                    'groups.manage',
                    'roles.view',
                    'roles.manage',
                    'imports.manage',
                    'source_config.view',
                    'source_config.manage',
                    'audit.view',
                ],
            ],
            'supervisor' => [
                'name' => 'Supervisor de Faena',
                'description' => 'Coordinación operativa, asignación de cuadrillas y control de avance.',
                'permissions' => [
                    'dashboard.view_operational',
                    'projects.view',
                    'mines.view',
                    'plans.view',
                    'platforms.view',
                    'drill_holes.view_all',
                    'assignments.view',
                    'assignments.manage',
                    'roles.view',
                    'progress.view',
                    'progress.create',
                    'progress.approve',
                    'progress.initialize_depth',
                    'observations.view',
                    'observations.create',
                    'risks.view',
                    'risks.create',
                    'risks.close',
                    'machines.view',
                    'personnel.view',
                    'personnel.manage',
                    'groups.view',
                    'groups.manage',
                    'imports.manage',
                ],
            ],
            'geologist' => [
                'name' => 'Geólogo',
                'description' => 'Supervisión técnica geológica, litología y edición técnica de pozos.',
                'permissions' => [
                    'dashboard.view_technical',
                    'projects.view',
                    'mines.view',
                    'plans.view',
                    'platforms.view',
                    'drill_holes.view_all',
                    'drill_holes.create',
                    'drill_holes.update',
                    'drill_holes.update_technical_data',
                    'progress.view',
                    'geology.log_cores',
                    'observations.view',
                    'observations.create',
                    'risks.view',
                    'risks.create',
                    'audit.view',
                ],
            ],
            'driller' => [
                'name' => 'Perforista',
                'description' => 'Operador principal a cargo de equipo y registro de avance en sus pozos.',
                'permissions' => [
                    'dashboard.view_personal',
                    'projects.view',
                    'mines.view',
                    'plans.view',
                    'platforms.view',
                    'drill_holes.view_assigned',
                    'assignments.view',
                    'progress.view',
                    'progress.create',
                    'observations.view',
                    'observations.create',
                    'risks.view',
                    'risks.create',
                ],
            ],
            'helper' => [
                'name' => 'Ayudante de Perforación',
                'description' => 'Asistente de perforación y reporte de novedades de turno.',
                'permissions' => [
                    'dashboard.view_personal',
                    'projects.view',
                    'mines.view',
                    'drill_holes.view_assigned',
                    'assignments.view',
                    'observations.view',
                    'observations.create',
                    'risks.view',
                    'risks.create',
                ],
            ],
            'geotechnical' => [
                'name' => 'Geotécnico',
                'description' => 'Control de estabilidad geomecánica y mitigación de riesgos.',
                'permissions' => [
                    'dashboard.view_risks',
                    'projects.view',
                    'mines.view',
                    'drill_holes.view_all',
                    'observations.view',
                    'observations.create',
                    'risks.view',
                    'risks.create',
                    'risks.close',
                ],
            ],
        ];

        foreach ($rolesDefinition as $slug => $data) {
            $role = Role::updateOrCreate(
                ['slug' => $slug, 'tenant_id' => null],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'is_system' => true,
                ]
            );

            $permIds = [];
            foreach ($data['permissions'] as $permName) {
                if (isset($permissionModels[$permName])) {
                    $permIds[] = $permissionModels[$permName]->id;
                }
            }

            $role->permissions()->sync($permIds);
        }

        // Marcar Super Admin por defecto si existe admin@app.test
        User::where('email', 'admin@app.test')->update(['is_super_admin' => true]);

        // Sincronizar membresías existentes con su rol del sistema
        $allSystemRoles = Role::whereNull('tenant_id')->get()->keyBy('slug');
        foreach (Membership::cursor() as $membership) {
            if ($membership->role && isset($allSystemRoles[$membership->role])) {
                $membership->roles()->syncWithoutDetaching([$allSystemRoles[$membership->role]->id]);
            }
        }
    }
}
