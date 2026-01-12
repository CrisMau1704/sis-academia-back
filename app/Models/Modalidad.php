<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Modalidad extends Model
{
    use HasFactory;

    protected $table = 'modalidades';

    protected $fillable = [
        'disciplina_id',
        'nombre',
        'precio_mensual',
        'descripcion',
        'permisos_maximos',
        'clases_mensuales', // ← TÚ definirás este valor
        'estado'
    ];

    protected $casts = [
        'precio_mensual' => 'decimal:2',
        'permisos_maximos' => 'integer',
        'clases_mensuales' => 'integer',
        'estado' => 'string'
    ];

    protected $attributes = [
        'estado' => 'activo',
        'permisos_maximos' => 3,
        'precio_mensual' => 0,
        // REMOVÍ el valor por defecto de clases_mensuales
    ];

    // Relaciones
    public function disciplina()
    {
        return $this->belongsTo(Disciplina::class);
    }

    public function inscripciones()
    {
        return $this->hasMany(Inscripcion::class);
    }

    public function horarios()
    {
        return $this->hasMany(Horario::class);
    }

    // Accesores - Actualizados para usar clases_mensuales
    public function getClasesTotalesAttribute()
    {
        // Usa el valor de la BD, sin valor por defecto
        return $this->clases_mensuales;
    }

    public function getClasesMensualesAttribute()
    {
        // Devuelve el valor directamente de la BD
        return $this->attributes['clases_mensuales'] ?? null;
    }

    public function getFormattedPriceAttribute()
    {
        return '$' . number_format($this->precio_mensual, 2);
    }

    public function getDisciplinaNombreAttribute()
    {
        return $this->disciplina ? $this->disciplina->nombre : 'Sin disciplina';
    }

    // Nuevo accesor para mostrar información de clases
    public function getClasesInfoAttribute()
    {
        if ($this->clases_mensuales) {
            return "{$this->clases_mensuales} clases / {$this->permisos_maximos} permisos";
        }
        return "{$this->permisos_maximos} permisos";
    }

    // Scopes
    public function scopeActivas($query)
    {
        return $query->where('estado', 'activo');
    }

    public function scopePorDisciplina($query, $disciplina_id)
    {
        return $query->where('disciplina_id', $disciplina_id);
    }

    // Validaciones - Hacer obligatorio el campo
       public static function rules($id = null)
    {
        return [
            'disciplina_id' => 'required|exists:disciplinas,id',
            'nombre' => 'required|string|max:255|unique:modalidades,nombre,' . $id,
            'precio_mensual' => 'required|numeric|min:0',
            'descripcion' => 'nullable|string|max:500',
            'permisos_maximos' => 'required|integer|min:0|max:100', // Máximo 10 permisos
            'clases_mensuales' => 'required|integer|min:1|max:31', // CAMBIAR de max:10 a max:31 o más
            'estado' => 'required|in:activo,inactivo,suspendido'
        ];
    }

    // Validaciones para actualización (puede ser opcional)
    public static function updateRules($id = null)
    {
        return [
            'disciplina_id' => 'sometimes|exists:disciplinas,id',
            'nombre' => 'sometimes|string|max:255|unique:modalidades,nombre,' . $id,
            'precio_mensual' => 'sometimes|numeric|min:0',
            'descripcion' => 'nullable|string|max:500',
            'permisos_maximos' => 'sometimes|integer|min:0|max:31',
            'clases_mensuales' => 'sometimes|integer|min:1|max:31', // ← A veces requerido
            'estado' => 'sometimes|in:activo,inactivo,suspendido'
        ];
    }

    // Métodos de ayuda
    public function tieneClasesDefinidas()
    {
        return !is_null($this->clases_mensuales) && $this->clases_mensuales > 0;
    }

    public function getClasesPorSemana()
    {
        if (!$this->tieneClasesDefinidas()) {
            return null;
        }
        // 4.33 semanas promedio por mes
        return ceil($this->clases_mensuales / 4.33);
    }

    public function getDistribucionClases($cantidadHorarios)
    {
        if (!$this->tieneClasesDefinidas() || $cantidadHorarios <= 0) {
            return [];
        }
        
        $clasesPorHorario = floor($this->clases_mensuales / $cantidadHorarios);
        $clasesExtra = $this->clases_mensuales % $cantidadHorarios;
        
        $distribucion = array_fill(0, $cantidadHorarios, $clasesPorHorario);
        
        // Distribuir las clases extra
        for ($i = 0; $i < $clasesExtra; $i++) {
            $distribucion[$i]++;
        }
        
        return $distribucion;
    }
}