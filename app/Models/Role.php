<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['nombre', 'detalle'];
    
    public $timestamps = true;

    /**
     * Relación muchos a muchos con User
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id')
                    ->withTimestamps();
    }

    /**
     * Relación muchos a muchos con Permiso
     */
    public function permisos()
    {
        return $this->belongsToMany(Permiso::class, 'role_permiso', 'role_id', 'permiso_id')
                    ->withTimestamps();
    }

    /**
     * Método helper para verificar si un rol tiene un permiso específico
     */
    public function tienePermiso($codigoPermiso)
    {
        return $this->permisos()->where('codigo', $codigoPermiso)->exists();
    }

    /**
     * Método para asignar permisos al rol
     */
    public function asignarPermisos($permisosIds)
    {
        return $this->permisos()->sync($permisosIds);
    }

    /**
     * Método para obtener solo los códigos de permisos
     */
    public function getPermisosCodigosAttribute()
    {
        return $this->permisos->pluck('codigo')->toArray();
    }

    /**
     * Scope para buscar roles que tengan un permiso específico
     */
    public function scopeConPermiso($query, $codigoPermiso)
    {
        return $query->whereHas('permisos', function ($q) use ($codigoPermiso) {
            $q->where('codigo', $codigoPermiso);
        });
    }
}