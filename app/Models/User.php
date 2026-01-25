<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function pedidos(){
        return $this->hasMany(Pedido::class);
    }

    public function persona(){
        return $this->hasMany(Persona::class);
    }

    public function roles(){
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')
                    ->withTimestamps();
    }

    /**
     * Obtener todos los permisos del usuario a través de sus roles
     */
    public function permisos()
    {
        $permisos = collect();
        
        foreach ($this->roles as $role) {
            // Asegúrate de cargar los permisos de cada rol
            $permisos = $permisos->merge($role->permisos);
        }
        
        return $permisos->unique('id');
    }

    /**
     * Obtener solo los códigos de permisos del usuario
     * Esto es útil para enviar al frontend
     */
    public function getPermisosCodigosAttribute()
    {
        return $this->permisos()->pluck('codigo')->toArray();
    }

    /**
     * Verificar si el usuario tiene un permiso específico
     */
    public function tienePermiso($codigoPermiso)
    {
        // Si es super_admin, tiene todos los permisos
        if ($this->tieneRol('super_admin')) {
            return true;
        }
        
        return $this->permisos()->where('codigo', $codigoPermiso)->exists();
    }

    /**
     * Verificar si el usuario tiene al menos uno de los permisos
     */
    public function tieneAlgunPermiso(array $codigosPermisos)
    {
        // Si es super_admin, tiene todos los permisos
        if ($this->tieneRol('super_admin')) {
            return true;
        }
        
        return $this->permisos()->whereIn('codigo', $codigosPermisos)->exists();
    }

    /**
     * Verificar si el usuario tiene todos los permisos
     */
    public function tieneTodosPermisos(array $codigosPermisos)
    {
        // Si es super_admin, tiene todos los permisos
        if ($this->tieneRol('super_admin')) {
            return true;
        }
        
        $permisosUsuario = $this->permisos()->pluck('codigo')->toArray();
        
        foreach ($codigosPermisos as $permiso) {
            if (!in_array($permiso, $permisosUsuario)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Verificar si el usuario tiene un rol específico
     */
    public function tieneRol($nombreRol)
    {
        // Cargar roles si no están cargados
        if (!$this->relationLoaded('roles')) {
            $this->load('roles');
        }
        
        return $this->roles->contains('nombre', $nombreRol);
    }

    /**
     * Verificar si el usuario tiene alguno de los roles
     */
    public function tieneAlgunRol(array $nombresRoles)
    {
        // Cargar roles si no están cargados
        if (!$this->relationLoaded('roles')) {
            $this->load('roles');
        }
        
        return $this->roles->whereIn('nombre', $nombresRoles)->isNotEmpty();
    }

    /**
     * Asignar roles al usuario
     */
    public function asignarRoles($rolesIds)
    {
        return $this->roles()->sync($rolesIds);
    }

    /**
     * Agregar un rol al usuario
     */
    public function agregarRol($rolId)
    {
        if (!$this->roles->contains($rolId)) {
            $this->roles()->attach($rolId);
        }
        return $this;
    }

    /**
     * Remover un rol del usuario
     */
    public function removerRol($rolId)
    {
        $this->roles()->detach($rolId);
        return $this;
    }

    /**
     * Scope para buscar usuarios por permiso
     */
    public function scopeConPermiso($query, $codigoPermiso)
    {
        return $query->whereHas('roles.permisos', function ($q) use ($codigoPermiso) {
            $q->where('codigo', $codigoPermiso);
        });
    }

    /**
     * Scope para buscar usuarios por rol
     */
    public function scopeConRol($query, $nombreRol)
    {
        return $query->whereHas('roles', function ($q) use ($nombreRol) {
            $q->where('nombre', $nombreRol);
        });
    }

    /**
     * Método para obtener datos del usuario para el frontend
     * Incluye roles y permisos
     */
    public function toArrayWithPermissions()
    {
        $data = $this->toArray();
        $data['roles'] = $this->roles->pluck('nombre')->toArray();
        $data['permisos'] = $this->permisos_codigos;
        
        return $data;
    }
}