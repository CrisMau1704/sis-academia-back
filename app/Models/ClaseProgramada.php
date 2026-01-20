<?php
// app/Models/ClaseProgramada.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClaseProgramada extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'clases_programadas';

    protected $fillable = [
        'inscripcion_horario_id',
        'horario_id',
        'inscripcion_id',
        'estudiante_id',
        'fecha',
        'hora_inicio',
        'hora_fin',
        'estado_clase',
        'asistencia_id',
        'clase_original_id',
        'es_recuperacion',
        'cuenta_para_asistencia',
        'observaciones'
    ];

    protected $casts = [
        'fecha' => 'date',
        'es_recuperacion' => 'boolean',
        'cuenta_para_asistencia' => 'boolean',
    ];

    /**
     * Relaciones
     */
    public function inscripcionHorario()
    {
        return $this->belongsTo(InscripcionHorario::class);
    }

    public function horario()
    {
        return $this->belongsTo(Horario::class);
    }

    public function inscripcion()
    {
        return $this->belongsTo(Inscripcion::class);
    }

    public function estudiante()
    {
        return $this->belongsTo(Estudiante::class);
    }

    public function asistencia()
    {
        return $this->belongsTo(Asistencia::class);
    }

    public function claseOriginal()
    {
        return $this->belongsTo(ClaseProgramada::class, 'clase_original_id');
    }

    public function recuperaciones()
    {
        return $this->hasMany(ClaseProgramada::class, 'clase_original_id');
    }

    /**
     * Scopes útiles
     */
    public function scopeProgramadas($query)
    {
        return $query->where('estado_clase', 'programada');
    }

    public function scopeRealizadas($query)
    {
        return $query->where('estado_clase', 'realizada');
    }

    public function scopeEntreFechas($query, $inicio, $fin)
    {
        return $query->whereBetween('fecha', [$inicio, $fin]);
    }

    public function scopeDelEstudiante($query, $estudianteId)
    {
        return $query->where('estudiante_id', $estudianteId);
    }

    /**
     * Métodos de utilidad
     */
    public function marcarComoRealizada($asistenciaId = null)
    {
        $this->update([
            'estado_clase' => 'realizada',
            'asistencia_id' => $asistenciaId
        ]);
    }

    public function marcarComoAusente($justificada = false)
    {
        $this->update([
            'estado_clase' => $justificada ? 'justificada' : 'ausente'
        ]);
    }

    public function esPasada()
    {
        return now()->startOfDay() > $this->fecha;
    }

    public function esHoy()
    {
        return now()->toDateString() === $this->fecha->toDateString();
    }
}