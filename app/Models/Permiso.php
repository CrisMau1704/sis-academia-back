<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permiso extends Model
{
    use HasFactory;

    protected $table = 'permisos';

    protected $fillable = [
        'codigo',
        'descripcion',
        'categoria'
    ];

    public $timestamps = true;

    /**
     * Relación muchos a muchos con Role
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permiso', 'permiso_id', 'role_id')
                    ->withTimestamps();
    }

    /**
     * Scope para buscar permisos por categoría
     */
    public function scopePorCategoria($query, $categoria)
    {
        return $query->where('categoria', $categoria);
    }

    /**
     * Scope para buscar permisos por código
     */
    public function scopePorCodigo($query, $codigo)
    {
        return $query->where('codigo', $codigo);
    }
}