<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notificacion extends Model
{
    use HasFactory;

    protected $fillable = [
        'estudiante_id',
        'inscripcion_id',
        'tipo',
        'mensaje',
        'fecha',
        'enviada',
        'leida'
    ];

    protected $casts = [
        'fecha' => 'datetime',
        'enviada' => 'boolean',
        'leida' => 'boolean'
    ];

    // Relaciones
    public function estudiante()
    {
        return $this->belongsTo(Estudiante::class);
    }

    public function inscripcion()
    {
        return $this->belongsTo(Inscripcion::class);
    }

    // Scopes
    public function scopeNoEnviadas($query)
    {
        return $query->where('enviada', false);
    }

    public function scopePendientes($query)
    {
        return $query->where('enviada', false)
                     ->where('fecha', '<=', now());
    }

    public function scopePorTipo($query, $tipo)
    {
        return $query->where('tipo', $tipo);
    }
}