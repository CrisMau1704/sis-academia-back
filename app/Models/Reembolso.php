<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Reembolso extends Model
{
    use HasFactory;

    protected $table = 'reembolsos';
    
    protected $fillable = [
        'pago_id',
        'estudiante_id',
        'usuario_id',
        'monto_original',
        'monto_reembolsado',
        'porcentaje_reembolso',
        'tipo',
        'metodo',
        'estado',
        'motivo',
        'fecha_solicitud',
        'fecha_aprobacion',
        'fecha_completado',
        'created_at',
        'updated_at'
    ];
    
    protected $casts = [
        'monto_original' => 'decimal:2',
        'monto_reembolsado' => 'decimal:2',
        'porcentaje_reembolso' => 'decimal:2',
        'fecha_solicitud' => 'datetime',
        'fecha_aprobacion' => 'datetime',
        'fecha_completado' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
    
    // COMENTA TEMPORALMENTE LOS APPENDS hasta que definas los métodos
    // protected $appends = ['estado_label', 'metodo_label'];
    protected $appends = []; // Vacío por ahora
    
    // RELACIONES
    public function pago()
    {
        return $this->belongsTo(Pago::class);
    }
    
    public function estudiante()
    {
        return $this->belongsTo(Estudiante::class);
    }
    
    public function usuario()
    {
        return $this->belongsTo(User::class);
    }
    
    // AGREGA ESTA RELACIÓN
    public function inscripcion()
    {
        return $this->belongsTo(Inscripcion::class);
    }
    
    // Si también necesitas aprobador, agrega:
    public function aprobador()
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }
    
    // SCOPES
    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }
    
    public function scopeAprobados($query)
    {
        return $query->where('estado', 'aprobado');
    }
    
    public function scopeCompletados($query)
    {
        return $query->where('estado', 'completado');
    }
    
    public function scopePorEstudiante($query, $estudianteId)
    {
        return $query->where('estudiante_id', $estudianteId);
    }

    
    // ========== AGREGAR ESTOS MÉTODOS ==========
    
    /**
     * Obtener el label del estado
     */
    public function getEstadoLabelAttribute()
    {
        $labels = [
            'pendiente' => '⏳ Pendiente',
            'aprobado' => '✅ Aprobado',
            'rechazado' => '❌ Rechazado',
            'completado' => '🎉 Completado'
        ];
        
        return $labels[$this->estado] ?? $this->estado;
    }
    
    /**
     * Obtener el label del método
     */
    public function getMetodoLabelAttribute()
    {
        $labels = [
            'efectivo' => '💰 Efectivo',
            'transferencia' => '🏦 Transferencia',
            'tarjeta_credito' => '💳 Tarjeta Crédito',
            'devolucion_tarjeta' => '↩️ Devolución Tarjeta',
            'credito_futuro' => '📅 Crédito Futuro'
        ];
        
        return $labels[$this->metodo] ?? $this->metodo;
    }
    
    // MÉTODOS
    public function puedeAprobar()
    {
        return $this->estado === 'pendiente';
    }
    
    public function puedeRechazar()
    {
        return $this->estado === 'pendiente';
    }
    
    public function aprobar($userId)
    {
        $this->update([
            'estado' => 'aprobado',
            'fecha_aprobacion' => now(),
            'updated_at' => now()
        ]);
        
        return $this;
    }
    
    public function rechazar($razon)
    {
        $this->update([
            'estado' => 'rechazado',
            'motivo' => $this->motivo . "\nRazón rechazo: " . $razon,
            'fecha_aprobacion' => now(),
            'updated_at' => now()
        ]);
        
        return $this;
    }
    
    public function completar()
    {
        $this->update([
            'estado' => 'completado',
            'fecha_completado' => now(),
            'updated_at' => now()
        ]);
        
        return $this;
    }
    
    /**
     * Verificar si puede ser procesado
     */
    public function puedeProcesar()
    {
        return $this->estado === 'aprobado';
    }
}