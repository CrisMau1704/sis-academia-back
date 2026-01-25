<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{
    use HasFactory;

    protected $fillable = [
        'inscripcion_id',
        'monto',
        'metodo_pago',
        'fecha_pago',
        'fecha_vencimiento',
        'estado',
        'observacion',
       
        'es_parcial',
        'pago_grupo_id', // Asegúrate de que esté aquí
        'numero_cuota'
    ];

    protected $casts = [
        'es_parcial' => 'boolean',
        'pago_grupo_id' => 'integer', // Esto podría causar problemas si el valor es muy grande
        'numero_cuota' => 'integer',
        'monto' => 'decimal:2'
    ];


    // Relación con inscripción
    public function inscripcion()
    {
        return $this->belongsTo(Inscripcion::class);
    }

    // Scopes para filtros comunes
    public function scopePagados($query)
    {
        return $query->where('estado', 'pagado');
    }

    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }

    public function scopeAnulados($query)
    {
        return $query->where('estado', 'anulado');
    }

    public function scopePorMetodo($query, $metodo)
    {
        return $query->where('metodo_pago', $metodo);
    }

    public function scopeEntreFechas($query, $fechaInicio, $fechaFin)
    {
        return $query->whereBetween('fecha_pago', [$fechaInicio, $fechaFin]);
    }

    // Métodos de ayuda
    public function getMontoFormateadoAttribute()
    {
        return 'S/ ' . number_format($this->monto, 2);
    }

    public function getMetodoPagoTextoAttribute()
    {
        $metodos = [
            'efectivo' => 'Efectivo',
            'qr' => 'QR/Yape/Plin',
            'tarjeta' => 'Tarjeta',
            'transferencia' => 'Transferencia'
        ];

        return $metodos[$this->metodo_pago] ?? $this->metodo_pago;
    }

    public function getEstadoTextoAttribute()
    {
        $estados = [
            'pagado' => 'Pagado',
            'pendiente' => 'Pendiente',
            'anulado' => 'Anulado'
        ];

        return $estados[$this->estado] ?? $this->estado;
    }

    public function grupo()
    {
        return $this->hasMany(Pago::class, 'pago_grupo_id', 'pago_grupo_id');
    }
    
    /**
     * Verificar si este pago es completo
     */
    public function esCompleto()
    {
        return !$this->es_parcial || 
               ($this->grupo()->where('estado', 'pendiente')->count() === 0);
    }
    
    /**
     * Obtener el saldo pendiente del grupo
     */
    public function saldoPendiente()
    {
        if (!$this->es_parcial || !$this->pago_grupo_id) {
            return 0;
        }
        
        return $this->grupo()
            ->where('estado', 'pendiente')
            ->sum('monto');
    }
}