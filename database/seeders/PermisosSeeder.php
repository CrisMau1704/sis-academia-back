<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permiso;
use App\Models\Role;

class PermisosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permisos = [
            // Dashboard
            ['codigo' => 'view_dashboard', 'descripcion' => 'Ver Dashboard', 'categoria' => 'dashboard'],
            
            // Configuración General
            ['codigo' => 'manage_branches', 'descripcion' => 'Gestionar Sucursales', 'categoria' => 'configuracion'],
            ['codigo' => 'manage_disciplines', 'descripcion' => 'Gestionar Disciplinas', 'categoria' => 'configuracion'],
            ['codigo' => 'manage_modalities', 'descripcion' => 'Gestionar Modalidades', 'categoria' => 'configuracion'],
            ['codigo' => 'manage_schedules', 'descripcion' => 'Gestionar Horarios', 'categoria' => 'configuracion'],
            ['codigo' => 'manage_trainers', 'descripcion' => 'Gestionar Entrenadores', 'categoria' => 'configuracion'],
            
            // Gestión de Estudiantes
            ['codigo' => 'manage_students', 'descripcion' => 'Gestionar Estudiantes', 'categoria' => 'gestion_estudiantes'],
            ['codigo' => 'manage_enrollments', 'descripcion' => 'Gestionar Inscripciones', 'categoria' => 'gestion_estudiantes'],
            
            // Control de Asistencia
            ['codigo' => 'manage_attendance', 'descripcion' => 'Gestionar Asistencias', 'categoria' => 'control_asistencia'],
            ['codigo' => 'manage_class_recovery', 'descripcion' => 'Gestionar Recuperación de Clases', 'categoria' => 'control_asistencia'],
            
            // Pagos y Mensualidades
            ['codigo' => 'view_payment_history', 'descripcion' => 'Ver Historial de Pagos', 'categoria' => 'pagos'],
            
            // Reportes
            ['codigo' => 'view_remaining_classes', 'descripcion' => 'Ver Clases Restantes', 'categoria' => 'reportes'],
            ['codigo' => 'view_monthly_attendance', 'descripcion' => 'Ver Asistencia Mensual', 'categoria' => 'reportes'],
            
            // Administración
            ['codigo' => 'manage_users', 'descripcion' => 'Gestionar Usuarios', 'categoria' => 'administracion'],
            ['codigo' => 'manage_roles', 'descripcion' => 'Gestionar Roles y Permisos', 'categoria' => 'administracion'],
        ];

        foreach ($permisos as $permiso) {
            Permiso::firstOrCreate(
                ['codigo' => $permiso['codigo']],
                $permiso
            );
        }

        // Asignar todos los permisos al rol super_admin (id 1)
        $superAdmin = Role::find(1);
        if ($superAdmin) {
            $todosPermisos = Permiso::all();
            $superAdmin->permisos()->sync($todosPermisos->pluck('id')->toArray());
        }
    }
}