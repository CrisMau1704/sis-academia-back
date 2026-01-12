<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecuperacionClase extends Model
{
    use HasFactory;

    protected $table = 'recuperacion_clases';
    
    protected $fillable = [
        'asistencia_id',
        'fecha_recuperacion',
        'horario_recuperacion_id',
        'motivo',
        'estado',
        'en_periodo_valido',
        'administrador_id'
    ];

    protected $casts = [
        'fecha_recuperacion' => 'date',
        'en_periodo_valido' => 'boolean'
    ];

    // Relaciones
    public function asistencia()
    {
        return $this->belongsTo(Asistencia::class);
    }

    public function horarioRecuperacion()
    {
        return $this->belongsTo(Horario::class, 'horario_recuperacion_id');
    }

    public function administrador()
    {
        return $this->belongsTo(User::class, 'administrador_id');
    }

    // Scopes
    public function scopeProgramadas($query)
    {
        return $query->where('estado', 'programada');
    }

    public function scopeCompletadas($query)
    {
        return $query->where('estado', 'completada');
    }

    public function scopeCanceladas($query)
    {
        return $query->where('estado', 'cancelada');
    }

    public function scopeEnPeriodoValido($query)
    {
        return $query->where('en_periodo_valido', true);
    }
}