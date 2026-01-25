<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\Permiso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserRoleController extends Controller
{
    // Obtener usuarios con roles
    public function index()
    {
        return User::with('roles')->get();
    }

    // Listar todos los roles disponibles
    public function getRoles()
    {
        $roles = Role::all();
        return response()->json($roles);
    }

    // Asignar roles a un usuario
    public function assignRoles(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'roles' => 'array',
            'roles.*' => 'exists:roles,id',
        ]);

        $user = User::findOrFail($request->user_id);
        $user->roles()->sync($request->roles);

        return response()->json([
            'message' => 'Roles actualizados correctamente',
            'user' => $user->load('roles'),
        ]);
    }

    // ===== MÉTODOS NUEVOS PARA PERMISOS =====

    /**
     * Obtener permisos del menú para el usuario autenticado
     */
    public function getMenuPermissions(Request $request)
    {
        $user = $request->user();
        
        // Si es super_admin, devolver todos los permisos
        if ($user->tieneRol('super_admin')) {
            $permisos = Permiso::all()->pluck('codigo')->toArray();
        } else {
            // Obtener permisos del usuario a través de sus roles
            $permisos = $user->permisos()->pluck('codigo')->toArray();
        }
        
        return response()->json([
            'success' => true,
            'permisos' => $permisos
        ]);
    }

    /**
     * Obtener todos los permisos del sistema
     */
    public function getAllPermissions()
    {
        $permisos = Permiso::orderBy('categoria')->orderBy('descripcion')->get();
        
        return response()->json([
            'success' => true,
            'data' => $permisos
        ]);
    }

    /**
     * Obtener permisos por rol
     */
    public function getPermissionsByRole($roleId)
    {
        $role = Role::with('permisos')->find($roleId);
        
        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Rol no encontrado'
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'role' => $role,
            'permisos' => $role->permisos->pluck('codigo')
        ]);
    }

    /**
     * Obtener roles con sus permisos incluidos
     */
    public function getRolesWithPermissions()
    {
        $roles = Role::with('permisos')->get();
        
        return response()->json([
            'success' => true,
            'data' => $roles
        ]);
    }

    /**
     * Actualizar permisos de un rol
     */
  public function updateRolePermissions(Request $request, $roleId)
{
    $request->validate([
        'permisos' => 'required|array',
        'permisos.*' => 'string' // Validar que sean strings (códigos)
    ]);
    
    $role = Role::find($roleId);
    
    if (!$role) {
        return response()->json([
            'success' => false,
            'message' => 'Rol no encontrado'
        ], 404);
    }
    
    // Obtener IDs de permisos basados en los códigos
    $permisosIds = Permiso::whereIn('codigo', $request->permisos)->pluck('id')->toArray();
    
    // Sincronizar permisos del rol
    $role->permisos()->sync($permisosIds);
    
    // Recargar el rol con permisos
    $role->load('permisos');
    
    return response()->json([
        'success' => true,
        'message' => 'Permisos actualizados correctamente',
        'role' => $role,
        'permisos' => $role->permisos->pluck('codigo') // Devolver códigos
    ]);
}

    /**
     * Crear un nuevo rol
     */
    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:50|unique:roles,nombre',
            'detalle' => 'nullable|string|max:255'
        ]);
        
        $role = Role::create([
            'nombre' => $request->nombre,
            'detalle' => $request->detalle
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Rol creado exitosamente',
            'data' => $role
        ]);
    }

    /**
     * Actualizar un rol
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'nombre' => 'required|string|max:50|unique:roles,nombre,' . $id,
            'detalle' => 'nullable|string|max:255'
        ]);
        
        $role = Role::find($id);
        
        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Rol no encontrado'
            ], 404);
        }
        
        $role->update([
            'nombre' => $request->nombre,
            'detalle' => $request->detalle
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Rol actualizado exitosamente',
            'data' => $role
        ]);
    }

    /**
     * Eliminar un rol
     */
    public function destroy($id)
    {
        $role = Role::find($id);
        
        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Rol no encontrado'
            ], 404);
        }
        
        // No permitir eliminar roles del sistema
        if (in_array($role->nombre, ['super_admin', 'admin', 'vendedor'])) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar un rol del sistema'
            ], 400);
        }
        
        $role->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Rol eliminado exitosamente'
        ]);
    }

    /**
     * Obtener estadísticas de roles y permisos
     */
    public function statistics()
    {
        $totalRoles = Role::count();
        $totalPermisos = Permiso::count();
        $totalUsuariosConRoles = User::has('roles')->count();
        
        $rolesConPermisos = Role::withCount('permisos')->get()->map(function($role) {
            return [
                'nombre' => $role->nombre,
                'total_permisos' => $role->permisos_count
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => [
                'total_roles' => $totalRoles,
                'total_permisos' => $totalPermisos,
                'total_usuarios_con_roles' => $totalUsuariosConRoles,
                'roles_con_permisos' => $rolesConPermisos
            ]
        ]);
    }
}