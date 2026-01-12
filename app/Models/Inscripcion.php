<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany; // ← Agrega esta importación

class Inscripcion extends Model
{
    use HasFactory;

    // AGREGA ESTO para evitar carga automática
    protected $with = []; // ← ¡VACÍO!
    

    
    protected $table = 'inscripciones';
    
    protected $fillable = [
        'estudiante_id',
        'modalidad_id',
        'sucursal_id',
        'entrenador_id',
        'fecha_inicio',
        'fecha_fin',
        'clases_totales',
        'clases_asistidas',
        'permisos_disponibles',
        'permisos_usados',
        'monto_mensual',
        'estado'
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'clases_totales' => 'integer',
        'clases_asistidas' => 'integer',
        'permisos_disponibles' => 'integer',
        'permisos_usados' => 'integer',
        'monto_mensual' => 'decimal:2'
    ];

    protected $attributes = [
        'estado' => 'activo',
        'clases_totales' => 12,
        'clases_asistidas' => 0,
        'permisos_usados' => 0
    ];

    // Relaciones
    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class);
    }

    public function modalidad(): BelongsTo
    {
        return $this->belongsTo(Modalidad::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function entrenador(): BelongsTo
    {
        return $this->belongsTo(Entrenador::class, 'entrenador_id');
    }

    // ========== AGREGA ESTA RELACIÓN ==========
    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'inscripcion_id');
    }


public function horarios()
{
    return $this->belongsToMany(Horario::class, 'inscripcion_horarios')
        ->withPivot([
            'id', 
            'clases_asistidas',
            'clases_totales', 
            'clases_restantes',
            'permisos_usados',
            'fecha_inicio', 
            'fecha_fin',
            'estado'
        ])
        ->withTimestamps();
        // ← Sin ->select(), sin ->as(), sin ->using()
}
    public function inscripcionHorarios()
    {
        return $this->hasMany(InscripcionHorario::class, 'inscripcion_id');
    }

    // Scopes
    public function scopeActivas($query)
    {
        return $query->where('estado', 'activo');
    }

    public function scopePorVencer($query, $dias = 7)
    {
        return $query->where('estado', 'activo')
                    ->where('fecha_fin', '<=', now()->addDays($dias))
                    ->where('fecha_fin', '>=', now());
    }

    public function scopeVencidas($query)
    {
        return $query->where('estado', 'activo')
                    ->where('fecha_fin', '<', now());
    }

    // Métodos de ayuda
    public function calcularDiasRestantes()
    {
        if (!$this->fecha_fin) return 0;
        
        $hoy = now();
        $fin = $this->fecha_fin;
        
        return $hoy->diffInDays($fin, false);
    }
    
    public function getDiasRestantesAttribute()
    {
        return $this->calcularDiasRestantes();
    }
    
    public function getClasesRestantesTotalesAttribute()
    {
        return $this->inscripcionHorarios()->sum('clases_restantes');
    }

     public function permisosJustificados()
    {
        return $this->hasMany(PermisoJustificado::class);
    }

    // AGREGAR ESTOS MÉTODOS
    public function permisosAprobados()
    {
        return $this->permisosJustificados()->where('estado', 'aprobado')->count();
    }

    public function permisosPendientes()
    {
        return $this->permisosJustificados()->where('estado', 'pendiente')->count();
    }

    public function puedeSolicitarPermiso()
    {
        $permisosAprobados = $this->permisosAprobados();
        return $permisosAprobados < 3;
    }

    public function registrarPermiso($datosPermiso)
    {
        if (!$this->puedeSolicitarPermiso()) {
            return ['success' => false, 'message' => 'Límite de permisos alcanzado (3 máximo)'];
        }

        $permiso = $this->permisosJustificados()->create([
            'fecha_solicitud' => now(),
            'fecha_falta' => $datosPermiso['fecha_falta'],
            'motivo' => $datosPermiso['motivo'],
            'estado' => 'pendiente',
            'evidencia' => $datosPermiso['evidencia'] ?? null
        ]);

        return ['success' => true, 'permiso' => $permiso];
    }

     public function usarPermiso()
    {
        if ($this->permisos_disponibles <= 0) {
            throw new \Exception('No hay permisos disponibles');
        }
        
        $this->decrement('permisos_disponibles');
        $this->increment('permisos_usados');
        
        return $this;
    }

    // Verificar si tiene permisos
    public function tienePermisosDisponibles()
    {
        return $this->permisos_disponibles > 0;
    }

}