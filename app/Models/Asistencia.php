<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asistencia extends Model
{
    use HasFactory;

    protected $table = 'asistencias';

    // COLUMNAS QUE SE PUEDEN LLENAR MASIVAMENTE - ¡PERFECTO!
    protected $fillable = [
        'inscripcion_id',
        'horario_id', 
        'fecha',
        'estado',
        'observacion',
        'recuperada',
        'permiso_id',
        'recuperacion_id',
        // No es necesario incluir created_at y updated_at aquí
        // Laravel los maneja automáticamente
    ];

    // CASTS - MEJORA CON VALORES POR DEFECTO
    protected $casts = [
        'fecha' => 'date:Y-m-d', // Formato específico
        'recuperada' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // VALORES POR DEFECTO (NUEVO - ÚTIL)
    protected $attributes = [
        'recuperada' => false,
        'estado' => 'falto' // Valor por defecto si no se especifica
    ];

    // RELACIONES - ¡PERFECTAS!
    public function permiso()
{
    return $this->hasOne(PermisoJustificado::class, 'asistencia_id'); // ← CLAVE IMPORTANTE
}

    public function inscripcion()
    {
        return $this->belongsTo(Inscripcion::class)->with('estudiante');
    }

    public function horario()
    {
        return $this->belongsTo(Horario::class)->with(['modalidad', 'entrenador', 'sucursal']);
    }

    public function recuperacion()
    {
        return $this->belongsTo(RecuperacionClase::class, 'recuperacion_id');
    }

    // SCOPES ÚTILES - AGREGA ALGUNOS NUEVOS
    public function scopeAsistidas($query)
    {
        return $query->where('estado', 'asistio');
    }

    public function scopeFaltas($query)
    {
        return $query->where('estado', 'falto');
    }

    public function scopePermisos($query)
    {
        return $query->where('estado', 'permiso');
    }

    public function scopePorRecuperar($query)
    {
        return $query->where('estado', 'falto')
                    ->where('recuperada', false);
    }

    public function scopeDelDia($query, $fecha = null)
    {
        if (!$fecha) {
            $fecha = now()->toDateString();
        }
        
        return $query->whereDate('fecha', $fecha);
    }

    public function scopeDeInscripcion($query, $inscripcionId)
    {
        return $query->where('inscripcion_id', $inscripcionId);
    }

    public function scopeDeHorario($query, $horarioId)
    {
        return $query->where('horario_id', $horarioId);
    }

    // SCOPES NUEVOS PARA EL CONTROLADOR SIMPLIFICADO
    public function scopeConEstudiante($query)
    {
        return $query->with(['inscripcion.estudiante']);
    }

    public function scopeConDatosCompletos($query)
    {
        return $query->with(['horario.modalidad', 'horario.entrenador', 'inscripcion.estudiante']);
    }

    public function scopeDeFecha($query, $fecha)
    {
        return $query->whereDate('fecha', $fecha);
    }

    public function scopeDeFechaYHorario($query, $fecha, $horarioId)
    {
        return $query->whereDate('fecha', $fecha)
                    ->where('horario_id', $horarioId);
    }

    // MÉTODOS DE UTILIDAD - ¡PERFECTOS!
    public function esRecuperable()
    {
        return $this->estado === 'falto' && !$this->recuperada;
    }

    public function esAsistenciaValida()
    {
        return $this->estado === 'asistio' || $this->estado === 'permiso';
    }

    public function marcarComoRecuperada()
    {
        $this->update(['recuperada' => true]);
        return $this;
    }

    // MÉTODOS NUEVOS PARA EL CONTROLADOR SIMPLIFICADO
    public function marcar($nuevoEstado, $observacion = null)
    {
        $data = ['estado' => $nuevoEstado];
        
        if ($observacion) {
            $data['observacion'] = $observacion;
        }
        
        // Si cambia de permiso a otro estado, eliminar permiso_id
        if ($this->estado === 'permiso' && $nuevoEstado !== 'permiso') {
            $data['permiso_id'] = null;
        }
        
        return $this->update($data);
    }

    public function esPresente()
    {
        return $this->estado === 'asistio';
    }

    public function esFalta()
    {
        return $this->estado === 'falto';
    }

    public function esPermiso()
    {
        return $this->estado === 'permiso';
    }

    public function tienePermiso()
    {
        return !is_null($this->permiso_id);
    }

    // ACCESORES - MEJORADOS
    public function getEstadoFormateadoAttribute()
    {
        $estados = [
            'asistio' => 'Presente',
            'falto' => 'Ausente', 
            'permiso' => 'Justificado'
        ];
        
        return $estados[$this->estado] ?? $this->estado;
    }

    public function getEstadoIconoAttribute()
    {
        $iconos = [
            'asistio' => '✅',
            'falto' => '❌',
            'permiso' => '📝'
        ];
        
        return $iconos[$this->estado] ?? '❓';
    }

    public function getEstadoColorAttribute()
    {
        $colores = [
            'asistio' => 'success',
            'falto' => 'danger',
            'permiso' => 'warning'
        ];
        
        return $colores[$this->estado] ?? 'secondary';
    }

    public function getPuedeRecuperarAttribute()
    {
        return $this->esRecuperable();
    }

    // MÉTODO PARA REGISTRO RÁPIDO (STATIC)
    public static function registrarRapido($inscripcionId, $horarioId, $fecha, $estado, $observacion = null)
    {
        return self::updateOrCreate(
            [
                'inscripcion_id' => $inscripcionId,
                'horario_id' => $horarioId,
                'fecha' => $fecha
            ],
            [
                'estado' => $estado,
                'observacion' => $observacion ?? 'Registro rápido'
            ]
        );
    }

    // MÉTODO PARA VERIFICAR SI YA EXISTE
    public static function yaRegistrada($inscripcionId, $horarioId, $fecha)
    {
        return self::where('inscripcion_id', $inscripcionId)
                  ->where('horario_id', $horarioId)
                  ->whereDate('fecha', $fecha)
                  ->exists();
    }

    // MÉTODO PARA OBTENER O CREAR
    public static function obtenerOCrear($inscripcionId, $horarioId, $fecha)
    {
        return self::firstOrCreate(
            [
                'inscripcion_id' => $inscripcionId,
                'horario_id' => $horarioId,
                'fecha' => $fecha
            ],
            [
                'estado' => 'falto', // Valor por defecto
                'observacion' => 'Creado automáticamente'
            ]
        );
    }

    // Asistencia.php - AGREGA ESTE MÉTODO
// En el modelo Asistencia.php, agrega este método:

/**
 * Método para justificar una falta rápidamente
 */
// En app/Models/Asistencia.php - método justificarFalta()

public static function justificarFalta($inscripcionId, $horarioId, $fecha, $motivo, $usuarioId = null)
{
    try {
        \DB::beginTransaction();
        
        \Log::info('🔄 Iniciando justificación rápida:', [
            'inscripcion_id' => $inscripcionId,
            'horario_id' => $horarioId,
            'fecha' => $fecha,
            'usuario_id' => $usuarioId
        ]);
        
        // 1. Obtener la INSCRIPCIÓN (aquí están los permisos)
        $inscripcion = \App\Models\Inscripcion::find($inscripcionId);
        
        if (!$inscripcion) {
            \Log::error('❌ Inscripción no encontrada:', ['id' => $inscripcionId]);
            throw new \Exception('Inscripción no encontrada');
        }
        
        \Log::info('📋 Datos de inscripción:', [
            'id' => $inscripcion->id,
            'estudiante_id' => $inscripcion->estudiante_id,
            'permisos_disponibles' => $inscripcion->permisos_disponibles,
            'permisos_usados' => $inscripcion->permisos_usados
        ]);
        
        // 2. Verificar permisos disponibles en la INSCRIPCIÓN (¡NO en estudiante!)
        if ($inscripcion->permisos_disponibles <= 0) {
            \Log::warning('❌ Inscripción sin permisos disponibles:', [
                'inscripcion_id' => $inscripcionId,
                'permisos_disponibles' => $inscripcion->permisos_disponibles
            ]);
            throw new \Exception('No hay permisos disponibles para esta inscripción');
        }
        
        // 3. Obtener o crear la asistencia
        $asistencia = self::obtenerOCrear($inscripcionId, $horarioId, $fecha);
        
        \Log::info('✅ Asistencia encontrada/creada:', ['id' => $asistencia->id]);
        
        // 4. Verificar si ya tiene un permiso
        if ($asistencia->tienePermiso()) {
            \Log::warning('⚠️ La asistencia ya tiene permiso:', ['permiso_id' => $asistencia->permiso_id]);
            throw new \Exception('Esta falta ya fue justificada anteriormente');
        }
        
        // 5. Crear el permiso justificado
        $permiso = \App\Models\PermisoJustificado::create([
            'inscripcion_id' => $inscripcionId,
            'asistencia_id' => $asistencia->id,
            'fecha_solicitud' => now()->format('Y-m-d'),
            'fecha_falta' => $fecha,
            'motivo' => $motivo,
            'estado' => 'aprobado', // Justificación rápida = aprobado automático
            'administrador_id' => $usuarioId,
            'evidencia' => 'Justificación rápida desde asistencia diaria'
        ]);
        
        \Log::info('📝 Permiso creado:', ['permiso_id' => $permiso->id]);
        
        // 6. Actualizar la asistencia
        $asistencia->update([
            'estado' => 'permiso',
            'permiso_id' => $permiso->id,
            'observacion' => "Justificado: {$motivo}"
        ]);
        
        \Log::info('🔄 Asistencia actualizada:', [
            'estado' => 'permiso',
            'permiso_id' => $permiso->id
        ]);
        
        // 7. ACTUALIZAR PERMISOS EN LA INSCRIPCIÓN (¡IMPORTANTE!)
        $inscripcion->decrement('permisos_disponibles');
        $inscripcion->increment('permisos_usados');
        
        \Log::info('📊 Permisos actualizados en inscripción:', [
            'nuevos_disponibles' => $inscripcion->permisos_disponibles,
            'nuevos_usados' => $inscripcion->permisos_usados
        ]);
        
        // 8. Si usas inscripcion_horario, actualízalo también
        if (class_exists('\App\Models\InscripcionHorario')) {
            $inscripcionHorario = \App\Models\InscripcionHorario::where('inscripcion_id', $inscripcionId)
                ->where('horario_id', $horarioId)
                ->first();
                
            if ($inscripcionHorario) {
                $inscripcionHorario->increment('permisos_usados');
                \Log::info('📅 Permisos en inscripcion_horario actualizados:', [
                    'permisos_usados' => $inscripcionHorario->permisos_usados
                ]);
            }
        }
        
        \DB::commit();
        
        // Recargar relaciones
        $asistencia->load(['permiso', 'inscripcion.estudiante']);
        
        return [
            'success' => true,
            'asistencia' => $asistencia,
            'permiso' => $permiso,
            'permisos_restantes' => $inscripcion->permisos_disponibles,
            'inscripcion' => $inscripcion
        ];
        
    } catch (\Exception $e) {
        \DB::rollBack();
        \Log::error('💥 Error en justificarFalta:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'data' => [
                'inscripcion_id' => $inscripcionId,
                'horario_id' => $horarioId,
                'fecha' => $fecha
            ]
        ]);
        
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// En app/Models/Asistencia.php - agrega este método

/**
 * Método para justificar usando el método de la inscripción
 */
public function justificarConPermiso($motivo, $usuarioId = null)
{
    // Obtener la inscripción
    $inscripcion = $this->inscripcion;
    
    if (!$inscripcion) {
        throw new \Exception('No se encontró la inscripción');
    }
    
    // Usar el método de la inscripción
    $inscripcion->usarPermiso();
    
    // Crear el permiso justificado
    $permiso = \App\Models\PermisoJustificado::create([
        'inscripcion_id' => $this->inscripcion_id,
        'asistencia_id' => $this->id,
        'fecha_solicitud' => now()->format('Y-m-d'),
        'fecha_falta' => $this->fecha,
        'motivo' => $motivo,
        'estado' => 'aprobado',
        'administrador_id' => $usuarioId,
        'evidencia' => 'Justificación desde asistencia'
    ]);
    
    // Actualizar la asistencia
    $this->update([
        'estado' => 'permiso',
        'permiso_id' => $permiso->id,
        'observacion' => "Justificado: {$motivo}"
    ]);
    
    return [
        'asistencia' => $this,
        'permiso' => $permiso,
        'inscripcion' => $inscripcion
    ];
}
}