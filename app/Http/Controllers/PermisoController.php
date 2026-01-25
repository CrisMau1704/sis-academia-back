<?php

namespace App\Http\Controllers;

use App\Models\PermisoJustificado;
use App\Models\Inscripcion;
use App\Models\Asistencia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PermisoController extends Controller
{
    /**
     * Solo para consulta - Permisos registrados
     */
    public function index(Request $request)
    {
        try {
            $query = PermisoJustificado::with(['inscripcion.estudiante', 'asistencia.horario']);
            
            if ($request->has('estado')) {
                $query->where('estado', $request->estado);
            }
            
            if ($request->has('inscripcion_id')) {
                $query->where('inscripcion_id', $request->inscripcion_id);
            }
            
            $permisos = $query->orderBy('fecha_falta', 'desc')
                             ->paginate($request->get('per_page', 15));
            
            return response()->json([
                'success' => true,
                'data' => $permisos,
                'message' => 'Permisos obtenidos'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Registrar permiso directamente (sin aprobación)
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $request->validate([
                'inscripcion_id' => 'required|exists:inscripciones,id',
                'horario_id' => 'required|exists:horarios,id',
                'fecha_falta' => 'required|date|before_or_equal:today',
                'motivo' => 'required|string|min:5|max:500'
            ]);

            $inscripcion = Inscripcion::findOrFail($request->inscripcion_id);
            
            // Verificar límite de permisos (3 máximo por mes)
            $permisosMes = $inscripcion->permisosJustificados()
                ->whereMonth('fecha_falta', date('m', strtotime($request->fecha_falta)))
                ->whereYear('fecha_falta', date('Y', strtotime($request->fecha_falta)))
                ->count();
                
            if ($permisosMes >= 3) {
                return response()->json([
                    'success' => false,
                    'message' => 'Límite de 3 permisos por mes alcanzado'
                ], 400);
            }

            // Buscar o crear asistencia
            $asistencia = Asistencia::where('inscripcion_id', $request->inscripcion_id)
                ->where('horario_id', $request->horario_id)
                ->whereDate('fecha', $request->fecha_falta)
                ->first();

            if (!$asistencia) {
                $asistencia = Asistencia::create([
                    'inscripcion_id' => $request->inscripcion_id,
                    'horario_id' => $request->horario_id,
                    'fecha' => $request->fecha_falta,
                    'estado' => 'permiso', // ¡Directamente como permiso!
                    'recuperada' => false,
                    'observacion' => 'Permiso justificado: ' . $request->motivo
                ]);
            } else {
                // Si ya existe, actualizar a permiso
                $asistencia->update([
                    'estado' => 'permiso',
                    'observacion' => 'Permiso justificado: ' . $request->motivo
                ]);
            }

            // Crear registro de permiso (solo para historial)
            $permiso = PermisoJustificado::create([
                'inscripcion_id' => $request->inscripcion_id,
                'asistencia_id' => $asistencia->id,
                'fecha_solicitud' => now(),
                'fecha_falta' => $request->fecha_falta,
                'motivo' => $request->motivo,
                'estado' => 'aprobado', // Siempre aprobado
                'evidencia' => null,
                'usuario_id' => $request->user()?->id // Usuario que registra
            ]);

            // Actualizar contadores de la inscripción
            $inscripcion->increment('permisos_usados');
            $inscripcion->decrement('permisos_disponibles');

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => [
                    'asistencia' => $asistencia->load(['inscripcion.estudiante', 'horario']),
                    'permiso' => $permiso
                ],
                'message' => 'Permiso registrado exitosamente'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar permiso: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verificar disponibilidad de permisos
     */
    public function verificarDisponibilidad($inscripcionId)
    {
        try {
            $inscripcion = Inscripcion::findOrFail($inscripcionId);
            
            $permisosMes = $inscripcion->permisosJustificados()
                ->whereMonth('fecha_falta', date('m'))
                ->whereYear('fecha_falta', date('Y'))
                ->count();
                
            $puedeSolicitar = $permisosMes < 3;
            $permisosRestantes = 3 - $permisosMes;
            
            return response()->json([
                'success' => true,
                'data' => [
                    'inscripcion_id' => $inscripcionId,
                    'permisos_usados_mes' => $permisosMes,
                    'permisos_disponibles_mes' => $permisosRestantes,
                    'puede_solicitar' => $puedeSolicitar,
                    'limite_mensual' => 3
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Eliminar método pendientes() o simplificarlo
     */
    public function pendientes()
    {
        // Si no quieres esta funcionalidad, retornar vacío
        return response()->json([
            'success' => true,
            'data' => [],
            'count' => 0,
            'message' => 'Sistema sin aprobación de permisos'
        ]);
    }
    




    public function show($id)
    {
        $permiso = PermisoJustificado::with([
            'inscripcion.estudiante',
            'asistencia.horario.modalidad',
            'asistencia.horario.entrenador',
            'asistencia.horario.sucursal',
            'administrador'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $permiso,
            'message' => 'Permiso obtenido exitosamente'
        ]);
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $request->validate([
                'accion' => 'required|in:aprobar,rechazar',
                'motivo_rechazo' => 'required_if:accion,rechazo|string|min:10|max:500',
                'administrador_id' => 'required|exists:users,id'
            ]);

            $permiso = PermisoJustificado::with(['inscripcion', 'asistencia'])->findOrFail($id);

            if ($permiso->estado !== 'pendiente') {
                return response()->json([
                    'success' => false,
                    'message' => 'El permiso ya fue procesado'
                ], 400);
            }

            if ($request->accion === 'aprobar') {
                $permisosAprobados = $permiso->inscripcion->permisosJustificados()
                    ->where('estado', 'aprobado')
                    ->count();
                    
                if ($permisosAprobados >= 3) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El estudiante ya alcanzó el límite de 3 permisos aprobados'
                    ], 400);
                }

                $permiso->estado = 'aprobado';
                $permiso->administrador_id = $request->administrador_id;
                $permiso->save();

                if ($permiso->asistencia) {
                    $permiso->asistencia->update([
                        'estado' => 'permiso',
                        'observacion' => 'Falta justificada y aprobada: ' . $permiso->motivo
                    ]);
                }

                $inscripcion = $permiso->inscripcion;
                $inscripcion->increment('permisos_usados');
                $inscripcion->decrement('permisos_disponibles');

                $mensaje = 'Permiso aprobado exitosamente';
            } else {
                $permiso->estado = 'rechazado';
                $permiso->administrador_id = $request->administrador_id;
                if ($request->motivo_rechazo) {
                    $permiso->motivo = $request->motivo_rechazo;
                }
                $permiso->save();

                if ($permiso->asistencia) {
                    $permiso->asistencia->update([
                        'estado' => 'falto',
                        'observacion' => 'Permiso rechazado: ' . $request->motivo_rechazo,
                        'permiso_id' => null
                    ]);
                }

                $mensaje = 'Permiso rechazado exitosamente';
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $permiso->fresh(['inscripcion.estudiante', 'administrador']),
                'message' => $mensaje
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar permiso: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        
        try {
            $permiso = PermisoJustificado::findOrFail($id);

            if ($permiso->estado !== 'pendiente') {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden eliminar permisos pendientes'
                ], 400);
            }

            if ($permiso->asistencia) {
                $permiso->asistencia->update([
                    'permiso_id' => null,
                    'observacion' => 'Permiso eliminado'
                ]);
            }

            $permiso->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Permiso eliminado exitosamente'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar permiso: ' . $e->getMessage()
            ], 500);
        }
    }

    // Métodos adicionales
    public function porInscripcion($inscripcionId)
    {
        $permisos = PermisoJustificado::with(['asistencia.horario', 'administrador'])
            ->where('inscripcion_id', $inscripcionId)
            ->orderBy('fecha_solicitud', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $permisos,
            'message' => 'Permisos de la inscripción obtenidos'
        ]);
    }

    // ========== MÉTODOS PARA PERMISOS JUSTIFICADOS Y RECUPERACIONES ==========

/**
 * Obtener permisos justificados por inscripción (para recuperaciones)
 */
public function justificadosPorInscripcion(Request $request)
{
    $request->validate([
        'inscripcion_id' => 'required|integer|exists:inscripciones,id',
        'estado' => 'nullable|in:aprobado,rechazado,pendiente'
    ]);
    
    try {
        $query = PermisoJustificado::where('inscripcion_id', $request->inscripcion_id)
            ->with(['inscripcion.estudiante', 'asistencia.horario.modalidad', 'administrador']);
        
        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }
        
        $permisos = $query->orderBy('fecha_falta', 'desc')->get();
        
        // Calcular fecha límite de recuperación (15 días después de fecha_fin de inscripción)
        $inscripcion = Inscripcion::find($request->inscripcion_id);
        $fechaFinInscripcion = Carbon::parse($inscripcion->fecha_fin);
        $fechaLimiteRecuperacion = $fechaFinInscripcion->copy()->addDays(15);
        
        $permisosConFechaLimite = $permisos->map(function ($permiso) use ($fechaLimiteRecuperacion, $inscripcion) {
            $permiso->fecha_limite_recuperacion = $fechaLimiteRecuperacion->format('Y-m-d');
            $permiso->fecha_fin_inscripcion = $inscripcion->fecha_fin;
            $permiso->puede_recuperar = Carbon::now() <= $fechaLimiteRecuperacion && 
                                        $permiso->estado === 'aprobado' && 
                                        !$this->tieneRecuperacion($permiso->id);
            return $permiso;
        });
        
        return response()->json([
            'success' => true,
            'data' => $permisosConFechaLimite,
            'total' => $permisosConFechaLimite->count(),
            'fechas' => [
                'fecha_fin_inscripcion' => $inscripcion->fecha_fin,
                'fecha_limite_recuperacion' => $fechaLimiteRecuperacion->format('Y-m-d'),
                'dias_restantes' => Carbon::now()->diffInDays($fechaLimiteRecuperacion, false)
            ]
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error obteniendo permisos justificados: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Obtener permisos recuperables (aprobados sin recuperación)
 */
public function permisosRecuperables(Request $request)
{
    $request->validate([
        'inscripcion_id' => 'required|integer|exists:inscripciones,id'
    ]);
    
    try {
        // Obtener inscripción
        $inscripcion = Inscripcion::with(['estudiante'])->findOrFail($request->inscripcion_id);
        
        // Calcular fecha límite de recuperación
        $fechaFinInscripcion = Carbon::parse($inscripcion->fecha_fin);
        $fechaLimiteRecuperacion = $fechaFinInscripcion->copy()->addDays(15);
        
        // Obtener permisos aprobados
        $permisos = PermisoJustificado::where('inscripcion_id', $request->inscripcion_id)
            ->where('estado', 'aprobado')
            ->with(['asistencia.horario.modalidad', 'asistencia.horario.entrenador'])
            ->orderBy('fecha_falta', 'desc')
            ->get();
        
        // Filtrar permisos que no tengan recuperación y estén dentro del plazo
        $hoy = Carbon::now();
        $permisosRecuperables = [];
        
        foreach ($permisos as $permiso) {
            // Verificar si ya tiene recuperación
            $tieneRecuperacion = DB::table('recuperacion_clases')
                ->where('permiso_justificado_id', $permiso->id)
                ->whereIn('estado', ['programada', 'completada'])
                ->exists();
            
            // Solo incluir si no tiene recuperación y está dentro del plazo
            if (!$tieneRecuperacion && $hoy <= $fechaLimiteRecuperacion) {
                $permisosRecuperables[] = [
                    'id' => $permiso->id,
                    'inscripcion_id' => $permiso->inscripcion_id,
                    'estudiante_id' => $inscripcion->estudiante_id,
                    'estudiante_nombre' => $inscripcion->estudiante->nombres . ' ' . $inscripcion->estudiante->apellidos,
                    'asistencia_id' => $permiso->asistencia_id,
                    'fecha_falta' => $permiso->fecha_falta,
                    'motivo' => $permiso->motivo,
                    'evidencia' => $permiso->evidencia,
                    'horario_falta' => $permiso->asistencia->horario ?? null,
                    'fecha_limite_recuperacion' => $fechaLimiteRecuperacion->format('Y-m-d'),
                    'dias_restantes' => $hoy->diffInDays($fechaLimiteRecuperacion, false),
                    'puede_recuperar' => true,
                    'estado' => 'disponible_para_recuperacion'
                ];
            }
        }
        
        return response()->json([
            'success' => true,
            'data' => $permisosRecuperables,
            'total' => count($permisosRecuperables),
            'inscripcion' => [
                'id' => $inscripcion->id,
                'estudiante' => $inscripcion->estudiante,
                'fecha_fin' => $inscripcion->fecha_fin,
                'fecha_limite_recuperacion' => $fechaLimiteRecuperacion->format('Y-m-d'),
                'clases_asistidas' => $inscripcion->clases_asistidas,
                'clases_totales' => $inscripcion->clases_totales
            ]
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error obteniendo permisos recuperables: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Verificar si un permiso tiene recuperación
 */
public function tieneRecuperacion($id)
{
    try {
        $permiso = PermisoJustificado::findOrFail($id);
        
        $tieneRecuperacion = DB::table('recuperacion_clases')
            ->where('permiso_justificado_id', $id)
            ->whereIn('estado', ['programada', 'completada'])
            ->exists();
        
        return response()->json([
            'success' => true,
            'tiene_recuperacion' => $tieneRecuperacion,
            'permiso' => $permiso
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error verificando recuperación: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Crear permiso justificado (para justificaciones rápidas)
 */
public function crearJustificacion(Request $request)
{
    DB::beginTransaction();
    
    try {
        $request->validate([
            'inscripcion_id' => 'required|exists:inscripciones,id',
            'estudiante_id' => 'required|exists:estudiantes,id',
            'horario_id' => 'required|exists:horarios,id',
            'fecha_falta' => 'required|date|before_or_equal:today',
            'motivo' => 'required|string|min:5|max:500',
            'observacion' => 'nullable|string|max:1000',
            'usuario_id' => 'required|exists:users,id'
        ]);
        
        // Verificar que la fecha de falta no sea futura
        if (Carbon::parse($request->fecha_falta)->isFuture()) {
            return response()->json([
                'success' => false,
                'message' => 'La fecha de falta no puede ser futura'
            ], 400);
        }
        
        // Obtener inscripción
        $inscripcion = Inscripcion::findOrFail($request->inscripcion_id);
        
        // Verificar límite de permisos (3 por mes)
        $permisosMes = $inscripcion->permisosJustificados()
            ->whereMonth('fecha_falta', Carbon::parse($request->fecha_falta)->month)
            ->whereYear('fecha_falta', Carbon::parse($request->fecha_falta)->year)
            ->count();
            
        if ($permisosMes >= 3) {
            return response()->json([
                'success' => false,
                'message' => 'Límite de 3 permisos por mes alcanzado'
            ], 400);
        }
        
        // Buscar o crear asistencia
        $asistencia = Asistencia::where('inscripcion_id', $request->inscripcion_id)
            ->where('horario_id', $request->horario_id)
            ->whereDate('fecha', $request->fecha_falta)
            ->first();
        
        if (!$asistencia) {
            $asistencia = Asistencia::create([
                'inscripcion_id' => $request->inscripcion_id,
                'horario_id' => $request->horario_id,
                'fecha' => $request->fecha_falta,
                'estado' => 'permiso',
                'observacion' => 'Justificado: ' . $request->motivo,
                'recuperada' => false
            ]);
        } else {
            // Actualizar asistencia existente
            $asistencia->update([
                'estado' => 'permiso',
                'observacion' => 'Justificado: ' . $request->motivo
            ]);
        }
        
        // Crear permiso justificado
        $permiso = PermisoJustificado::create([
            'inscripcion_id' => $request->inscripcion_id,
            'asistencia_id' => $asistencia->id,
            'fecha_solicitud' => now(),
            'fecha_falta' => $request->fecha_falta,
            'motivo' => $request->motivo,
            'estado' => 'aprobado', // Directamente aprobado
            'evidencia' => $request->observacion,
            'administrador_id' => $request->usuario_id
        ]);
        
        // Actualizar contadores de la inscripción
        $inscripcion->increment('permisos_usados');
        $inscripcion->decrement('permisos_disponibles');
        
        DB::commit();
        
        return response()->json([
            'success' => true,
            'data' => [
                'permiso' => $permiso->load(['inscripcion.estudiante', 'asistencia.horario', 'administrador']),
                'asistencia' => $asistencia,
                'inscripcion' => $inscripcion->fresh()
            ],
            'message' => 'Permiso justificado creado exitosamente'
        ], 201);
        
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Error creando permiso justificado: ' . $e->getMessage()
        ], 500);
    }
}

// ========== MÉTODOS PARA ESTADÍSTICAS ==========

/**
 * Obtener estadísticas de permisos
 */
public function estadisticas(Request $request)
{
    try {
        $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio'
        ]);
        
        $estadisticas = DB::table('permisos_justificados')
            ->select(
                DB::raw('COUNT(*) as total_permisos'),
                DB::raw('SUM(CASE WHEN estado = "aprobado" THEN 1 ELSE 0 END) as aprobados'),
                DB::raw('SUM(CASE WHEN estado = "rechazado" THEN 1 ELSE 0 END) as rechazados'),
                DB::raw('SUM(CASE WHEN estado = "pendiente" THEN 1 ELSE 0 END) as pendientes')
            )
            ->whereBetween('fecha_falta', [$request->fecha_inicio, $request->fecha_fin])
            ->first();
        
        // Permisos por motivo (top 5)
        $motivosMasComunes = DB::table('permisos_justificados')
            ->select('motivo', DB::raw('COUNT(*) as total'))
            ->whereBetween('fecha_falta', [$request->fecha_inicio, $request->fecha_fin])
            ->whereNotNull('motivo')
            ->groupBy('motivo')
            ->orderBy('total', 'desc')
            ->limit(5)
            ->get();
        
        // Permisos por día de la semana
        $permisosPorDia = DB::table('permisos_justificados')
            ->select(
                DB::raw('DAYNAME(fecha_falta) as dia_semana'),
                DB::raw('COUNT(*) as total')
            )
            ->whereBetween('fecha_falta', [$request->fecha_inicio, $request->fecha_fin])
            ->groupBy(DB::raw('DAYNAME(fecha_falta)'))
            ->orderBy(DB::raw('FIELD(dia_semana, "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado", "Domingo")'))
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => [
                'periodo' => [
                    'fecha_inicio' => $request->fecha_inicio,
                    'fecha_fin' => $request->fecha_fin
                ],
                'estadisticas_generales' => $estadisticas,
                'motivos_mas_comunes' => $motivosMasComunes,
                'permisos_por_dia_semana' => $permisosPorDia
            ]
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error obteniendo estadísticas: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Obtener permisos próximos a vencer (para recuperaciones)
 */
public function proximosAVencer(Request $request)
{
    try {
        $dias = $request->get('dias', 7);
        
        $hoy = Carbon::now();
        $fechaLimite = $hoy->copy()->addDays($dias);
        
        // Obtener inscripciones con permisos aprobados
        $inscripciones = Inscripcion::whereHas('permisosJustificados', function ($query) use ($hoy, $fechaLimite) {
            $query->where('estado', 'aprobado')
                  ->whereDate('fecha_fin_inscripcion', '>=', $hoy)
                  ->whereDate('fecha_fin_inscripcion', '<=', $fechaLimite);
        })
        ->with(['permisosJustificados' => function ($query) {
            $query->where('estado', 'aprobado');
        }, 'estudiante'])
        ->get();
        
        $permisosPorVencer = [];
        
        foreach ($inscripciones as $inscripcion) {
            $fechaFin = Carbon::parse($inscripcion->fecha_fin);
            $diasRestantes = $hoy->diffInDays($fechaFin, false);
            
            if ($diasRestantes >= 0 && $diasRestantes <= $dias) {
                foreach ($inscripcion->permisosJustificados as $permiso) {
                    $permisosPorVencer[] = [
                        'inscripcion_id' => $inscripcion->id,
                        'estudiante' => $inscripcion->estudiante,
                        'permiso_id' => $permiso->id,
                        'fecha_falta' => $permiso->fecha_falta,
                        'motivo' => $permiso->motivo,
                        'fecha_fin_inscripcion' => $inscripcion->fecha_fin,
                        'fecha_limite_recuperacion' => $fechaFin->copy()->addDays(15)->format('Y-m-d'),
                        'dias_restantes_inscripcion' => $diasRestantes,
                        'dias_restantes_recuperacion' => $hoy->diffInDays($fechaFin->copy()->addDays(15), false)
                    ];
                }
            }
        }
        
        return response()->json([
            'success' => true,
            'data' => $permisosPorVencer,
            'total' => count($permisosPorVencer),
            'periodo_dias' => $dias
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error obteniendo permisos por vencer: ' . $e->getMessage()
        ], 500);
    }
}

// ========== MÉTODOS PARA EL SISTEMA DE ASISTENCIAS ==========

/**
 * Justificar ausencia (para uso desde asistencia.vue)
 */
public function justificarAusencia(Request $request)
{
    return $this->crearJustificacion($request);
}

/**
 * Obtener permisos por estudiante y fecha
 */
public function porEstudianteYFecha(Request $request)
{
    $request->validate([
        'estudiante_id' => 'required|exists:estudiantes,id',
        'fecha' => 'required|date'
    ]);
    
    try {
        // Buscar inscripciones activas del estudiante
        $inscripciones = Inscripcion::where('estudiante_id', $request->estudiante_id)
            ->where('estado', 'activo')
            ->whereDate('fecha_inicio', '<=', $request->fecha)
            ->whereDate('fecha_fin', '>=', $request->fecha)
            ->get();
        
        $permisos = [];
        
        foreach ($inscripciones as $inscripcion) {
            $permisosInscripcion = PermisoJustificado::where('inscripcion_id', $inscripcion->id)
                ->whereDate('fecha_falta', $request->fecha)
                ->where('estado', 'aprobado')
                ->with(['asistencia.horario'])
                ->get();
            
            foreach ($permisosInscripcion as $permiso) {
                $permisos[] = $permiso;
            }
        }
        
        return response()->json([
            'success' => true,
            'data' => $permisos,
            'total' => count($permisos),
            'fecha' => $request->fecha,
            'estudiante_id' => $request->estudiante_id
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error obteniendo permisos: ' . $e->getMessage()
        ], 500);
    }
}




    
}