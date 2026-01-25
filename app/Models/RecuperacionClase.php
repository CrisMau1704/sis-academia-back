<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecuperacionClase extends Model
{
    protected $table = 'recuperacion_clases';
    
    protected $fillable = [
        'asistencia_id',
        'inscripcion_id',
        'estudiante_id',
        'permiso_justificado_id',
        'fecha_recuperacion',
        'horario_recuperacion_id',
        'motivo',
        'motivo_cancelacion',
        'estado',
        'en_periodo_valido',
        'administrador_id',
        'creado_por',
        'fecha_limite',
        'fecha_completada',
        'comentarios',
        'asistio_recuperacion',
        'asistencia_recuperacion_id'
    ];
    
    protected $casts = [
        'fecha_recuperacion' => 'date',
        'fecha_limite' => 'date',
        'fecha_completada' => 'datetime',
        'en_periodo_valido' => 'boolean',
        'asistio_recuperacion' => 'boolean'
    ];
    
    protected $appends = ['puede_recuperar'];
    
    // RELACIONES
    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(Inscripcion::class);
    }
    
    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(Estudiante::class);
    }
    
    public function permisoJustificado(): BelongsTo
    {
        return $this->belongsTo(PermisoJustificado::class, 'permiso_justificado_id');
    }
    
    public function asistenciaOriginal(): BelongsTo
    {
        return $this->belongsTo(Asistencia::class, 'asistencia_id');
    }
    
    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class, 'horario_recuperacion_id');
    }
    
    public function administrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administrador_id');
    }
    
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
    
    public function asistenciaRecuperacion(): BelongsTo
    {
        return $this->belongsTo(Asistencia::class, 'asistencia_recuperacion_id');
    }
    
    // SCOPES
    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente')
                    ->where('fecha_limite', '>=', now());
    }
    
    public function scopeProgramadas($query)
    {
        return $query->where('estado', 'programada')
                    ->where('fecha_recuperacion', '>=', now());
    }
    
    public function scopeCompletadas($query)
    {
        return $query->where('estado', 'completada');
    }
    
    public function scopePorEstudiante($query, $estudianteId)
    {
        return $query->where('estudiante_id', $estudianteId);
    }
    
    public function scopePorInscripcion($query, $inscripcionId)
    {
        return $query->where('inscripcion_id', $inscripcionId);
    }
    
    // ATTRIBUTES
    public function getPuedeRecuperarAttribute(): bool
    {
        if ($this->estado !== 'pendiente' && $this->estado !== 'programada') {
            return false;
        }
        
        if ($this->fecha_limite && now()->gt($this->fecha_limite)) {
            return false;
        }
        
        return true;
    }
    
    public function getDiasRestantesAttribute(): ?int
    {
        if (!$this->fecha_limite) {
            return null;
        }
        
        return now()->diffInDays($this->fecha_limite, false);
    }
    
    public function getEstaVencidaAttribute(): bool
    {
        if (!$this->fecha_limite) {
            return false;
        }
        
        return now()->gt($this->fecha_limite);
    }
    
    // MÉTODOS
    public function marcarComoCompletada($asistenciaId = null)
    {
        $this->estado = 'completada';
        $this->fecha_completada = now();
        $this->asistio_recuperacion = true;
        
        if ($asistenciaId) {
            $this->asistencia_recuperacion_id = $asistenciaId;
        }
        
        $this->save();
    }
    
    public function cancelar($motivo = null)
    {
        $this->estado = 'cancelada';
        
        if ($motivo) {
            $this->motivo_cancelacion = $motivo;
        }
        
        $this->save();
    }
    
    public function programar($fecha, $horarioId, $administradorId)
    {
        $this->fecha_recuperacion = $fecha;
        $this->horario_recuperacion_id = $horarioId;
        $this->administrador_id = $administradorId;
        $this->creado_por = $administradorId;
        $this->estado = 'programada';
        $this->save();
    }
}