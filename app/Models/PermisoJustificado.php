<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class PermisoJustificado extends Model
{
    use HasFactory;

    protected $table = 'permisos_justificados';

    protected $fillable = [
        'inscripcion_id',
        'asistencia_id',
        'fecha_solicitud',
        'fecha_falta',
        'motivo',
        'evidencia',
        'estado',
        'administrador_id',
        'motivo_rechazo'
    ];

    protected $casts = [
        'fecha_solicitud' => 'datetime',
        'fecha_falta' => 'date',
    ];

    // VALORES POR DEFECTO
    protected $attributes = [
        'estado' => 'aprobado', // O 'pendiente' según tu lógica
        'fecha_solicitud' => null,
    ];

    // BOOT METHOD para llenar automáticamente
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($permiso) {
            if (empty($permiso->fecha_solicitud)) {
                $permiso->fecha_solicitud = now();
            }
            
            // Si no tiene administrador_id y el usuario está autenticado
            if (empty($permiso->administrador_id) && auth()->check()) {
                $permiso->administrador_id = auth()->id();
            }
            
            // Si es una justificación rápida, marcar como aprobado
            if (empty($permiso->estado)) {
                $permiso->estado = 'aprobado'; // Para justificación rápida
            }
        });
    }

    // Relaciones
    public function inscripcion()
    {
        return $this->belongsTo(Inscripcion::class)->with('estudiante');
    }

    public function asistencia()
    {
        return $this->belongsTo(Asistencia::class);
    }

    public function administrador()
    {
        return $this->belongsTo(User::class, 'administrador_id');
    }

    // MÉTODO PARA CREACIÓN RÁPIDA
    public static function crearRapido($inscripcionId, $asistenciaId, $motivo, $fechaFalta, $usuarioId = null)
    {
        return self::create([
            'inscripcion_id' => $inscripcionId,
            'asistencia_id' => $asistenciaId,
            'fecha_solicitud' => now(),
            'fecha_falta' => $fechaFalta,
            'motivo' => $motivo,
            'estado' => 'aprobado', // En justificación rápida, se aprueba automáticamente
            'administrador_id' => $usuarioId,
            'evidencia' => 'Justificación rápida desde asistencia'
        ]);
    }

    // SCOPES
    public function scopeAprobados($query)
    {
        return $query->where('estado', 'aprobado');
    }
    
    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }
    
    public function scopeRechazados($query)
    {
        return $query->where('estado', 'rechazado');
    }
    
    public function scopeDeFecha($query, $fecha)
    {
        return $query->whereDate('fecha_falta', $fecha);
    }
    
    public function scopeDeInscripcion($query, $inscripcionId)
    {
        return $query->where('inscripcion_id', $inscripcionId);
    }

    // MÉTODOS DE ESTADO
    public function esAprobado()
    {
        return $this->estado === 'aprobado';
    }
    
    public function esPendiente()
    {
        return $this->estado === 'pendiente';
    }
    
    public function esRechazado()
    {
        return $this->estado === 'rechazado';
    }

    // ACCESORES
    public function getEstadoFormateadoAttribute()
    {
        $estados = [
            'pendiente' => 'Pendiente',
            'aprobado' => 'Aprobado',
            'rechazado' => 'Rechazado'
        ];
        
        return $estados[$this->estado] ?? $this->estado;
    }

    public function getEstadoColorAttribute()
    {
        $colores = [
            'pendiente' => 'warning',
            'aprobado' => 'success',
            'rechazado' => 'danger'
        ];
        
        return $colores[$this->estado] ?? 'secondary';
    }
}