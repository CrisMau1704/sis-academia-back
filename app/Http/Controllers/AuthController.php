<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
public function login(Request $request)
{
    $credenciales = $request->validate([
        "email" => "required|email",
        "password" => "required"
    ]);

    if (!Auth::attempt($credenciales)) {
        return response()->json(["message" => "Credenciales incorrectas"], 401);
    }

    $usuario = $request->user();
    $token = $usuario->createToken("Token personal")->plainTextToken;

    // Cargar relaciones con permisos
    $usuario->load(['roles.permisos']);
    
    // Obtener el primer rol (para compatibilidad)
    $rol = $usuario->roles->first();
    $rolNombre = $rol ? $rol->nombre : null;
    
    // Obtener TODOS los permisos del usuario (de todos sus roles)
    $permisos = $usuario->roles->flatMap(function($role) {
        return $role->permisos->pluck('codigo');
    })->unique()->values();
    
    // Obtener los permisos separados por menú/categoría
    $permisosPorCategoria = $usuario->roles->flatMap(function($role) {
        return $role->permisos->map(function($permiso) {
            return [
                'codigo' => $permiso->codigo,
                'descripcion' => $permiso->descripcion,
                'categoria' => $permiso->categoria
            ];
        });
    })->unique('codigo')->values();

    return response()->json([
        "access_token" => $token,
        "usuario" => $usuario,
        "rol" => $rolNombre,
        "permisos" => $permisos, // ← PERMISOS COMO ARRAY DE CÓDIGOS
        "permisos_detallados" => $permisosPorCategoria // ← PERMISOS CON CATEGORÍA
    ], 201);
}

    public function funRegistro(Request $request)
    {
        // Implementa la lógica de registro aquí
    }

   public function funPerfil(Request $request)
{
    $usuario = $request->user();
    $usuario->load(['roles.permisos']);
    
    $permisos = $usuario->roles->flatMap(function($role) {
        return $role->permisos->pluck('codigo');
    })->unique()->values();
    
    return response()->json([
        'usuario' => $usuario,
        'permisos' => $permisos
    ], 200);
}

    public function funSalir(Request $request)
    {
       $request->user()->tokens()->delete();
       return response()->json(["message"=>"salio"],200);
    }
}
