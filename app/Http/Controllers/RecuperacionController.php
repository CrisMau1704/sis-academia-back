<?php

namespace App\Http\Controllers;

use App\Models\RecuperacionClase;
use App\Models\PermisoJustificado;
use App\Models\Inscripcion;
use App\Models\Horario;
use App\Models\Asistencia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class RecuperacionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $query = RecuperacionClase::with([
                'inscripcion:id,estudiante_id,modalidad_id',
                'estudiante:id,nombres,apellidos,ci',
                'permisoJustificado:id,motivo,fecha_falta',
                'horario:id,dia_semana,hora_inicio,hora_fin,entrenador_id,sucursal_id',
                'horario.entrenador:id,nombres,apellidos',
                'horario.sucursal:id,nombre',
                'administrador:id,nombres,apellidos',
                'asistenciaRecuperacion:id,estado,hora_registro'
            ]);

            // FILTROS
            if ($request->has('estado')) {
                $query->where('estado', $request->estado);
            }

            if ($request->has('estudiante_id')) {
                $query->where('estudiante_id', $request->estudiante_id);
            }

            if ($request->has('inscripcion_id')) {
                $query->where('inscripcion_id', $request->inscripcion_id);
            }

            if ($request->has('fecha_desde')) {
                $query->whereDate('fecha_recuperacion', '>=', $request->fecha_desde);
            }

            if ($request->has('fecha_hasta')) {
                $query->whereDate('fecha_recuperacion', '<=', $request->fecha_hasta);
            }

            // Solo recuperaciones válidas (no canceladas y en período)
            if ($request->has('validas') && $request->validas == 'true') {
                $query->where('estado', '!=', 'cancelada')
                      ->where(function($q) {
                          $q->whereNull('fecha_limite')
                            ->orWhere('fecha_limite', '>=', Carbon::today());
                      });
            }

            // PAGINACIÓN
            $perPage = $request->get('per_page', 15);
            $recuperaciones = $query->orderBy('fecha_recuperacion', 'desc')
                                   ->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Recuperaciones obtenidas exitosamente',
                'data' => $recuperaciones
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener recuperaciones',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            // VALIDACIÓN
            $validator = Validator::make($request->all(), [
                'inscripcion_id' => 'required|exists:inscripciones,id',
                'estudiante_id' => 'required|exists:estudiantes,id',
                'permiso_justificado_id' => 'required|exists:permisos_justificados,id',
                'horario_recuperacion_id' => 'required|exists:horarios,id',
                'fecha_recuperacion' => 'required|date|after_or_equal:today',
                'motivo' => 'nullable|string|max:500',
                'administrador_id' => 'required|exists:users,id',
                'fecha_limite' => 'nullable|date|after_or_equal:fecha_recuperacion'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // 1. VERIFICAR QUE EL PERMISO EXISTA Y ESTÉ APROBADO
            $permiso = PermisoJustificado::findOrFail($request->permiso_justificado_id);
            
            if ($permiso->estado !== 'aprobado') {
                return response()->json([
                    'success' => false,
                    'message' => 'El permiso no está aprobado para recuperación'
                ], 400);
            }

            // 2. VERIFICAR QUE NO TENGA YA UNA RECUPERACIÓN PARA ESTE PERMISO
            $existeRecuperacion = RecuperacionClase::where('permiso_justificado_id', $request->permiso_justificado_id)
                ->whereIn('estado', ['pendiente', 'programada', 'completada'])
                ->exists();

            if ($existeRecuperacion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este permiso ya tiene una recuperación asignada'
                ], 400);
            }

            // 3. VERIFICAR HORARIO DISPONIBLE
            $horario = Horario::findOrFail($request->horario_recuperacion_id);
            
            // Verificar cupo disponible
            if ($horario->cupo_actual >= $horario->cupo_maximo) {
                return response()->json([
                    'success' => false,
                    'message' => 'El horario seleccionado ya está lleno'
                ], 400);
            }

            // 4. VERIFICAR QUE EL ESTUDIANTE NO TENGA OTRA CLASE EN EL MISMO HORARIO
            $conflictoHorario = RecuperacionClase::where('estudiante_id', $request->estudiante_id)
                ->where('fecha_recuperacion', $request->fecha_recuperacion)
                ->where('horario_recuperacion_id', $request->horario_recuperacion_id)
                ->whereIn('estado', ['pendiente', 'programada'])
                ->exists();

            if ($conflictoHorario) {
                return response()->json([
                    'success' => false,
                    'message' => 'El estudiante ya tiene una recuperación programada en este horario'
                ], 400);
            }

            // 5. CALCULAR FECHA LÍMITE (15 días por defecto desde la fecha de falta del permiso)
            $fechaLimite = $request->fecha_limite ?? 
                Carbon::parse($permiso->fecha_falta)->addDays(15);

            // 6. CREAR LA RECUPERACIÓN
            $recuperacion = RecuperacionClase::create([
                'inscripcion_id' => $request->inscripcion_id,
                'estudiante_id' => $request->estudiante_id,
                'permiso_justificado_id' => $request->permiso_justificado_id,
                'asistencia_id' => $permiso->asistencia_id,
                'horario_recuperacion_id' => $request->horario_recuperacion_id,
                'fecha_recuperacion' => $request->fecha_recuperacion,
                'fecha_limite' => $fechaLimite,
                'motivo' => $request->motivo ?? 'Recuperación de clase justificada',
                'estado' => 'programada',
                'en_periodo_valido' => true,
                'administrador_id' => $request->administrador_id,
                'creado_por' => $request->administrador_id,
                'comentarios' => $request->comentarios
            ]);

            // 7. ACTUALIZAR EL PERMISO PARA INDICAR QUE TIENE RECUPERACIÓN
            $permiso->update([
                'tiene_recuperacion' => true,
                'recuperacion_id' => $recuperacion->id
            ]);

            // 8. INCREMENTAR CUPO DEL HORARIO
            $horario->increment('cupo_actual');

            DB::commit();

            // Cargar relaciones para la respuesta
            $recuperacion->load([
                'inscripcion',
                'estudiante',
                'permisoJustificado',
                'horario',
                'administrador'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Recuperación programada exitosamente',
                'data' => $recuperacion
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Error al programar la recuperación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $recuperacion = RecuperacionClase::with([
                'inscripcion:id,estudiante_id,modalidad_id,fecha_inicio,fecha_fin',
                'inscripcion.modalidad:id,nombre,clases_mensuales',
                'estudiante:id,nombres,apellidos,ci,telefono,correo',
                'permisoJustificado:id,motivo,fecha_falta,evidencia,created_at',
                'horario:id,dia_semana,hora_inicio,hora_fin,entrenador_id,sucursal_id',
                'horario.entrenador:id,nombres,apellidos',
                'horario.sucursal:id,nombre,direccion',
                'administrador:id,nombres,apellidos,email',
                'creador:id,nombres,apellidos',
                'asistenciaRecuperacion:id,estado,hora_registro,observaciones'
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Recuperación obtenida exitosamente',
                'data' => $recuperacion
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la recuperación',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        DB::beginTransaction();
        
        try {
            $recuperacion = RecuperacionClase::findOrFail($id);

            // Validar que se pueda modificar (solo pendientes o programadas)
            if (!in_array($recuperacion->estado, ['pendiente', 'programada'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede modificar una recuperación ' . $recuperacion->estado
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'horario_recuperacion_id' => 'nullable|exists:horarios,id',
                'fecha_recuperacion' => 'nullable|date|after_or_equal:today',
                'motivo' => 'nullable|string|max:500',
                'comentarios' => 'nullable|string',
                'fecha_limite' => 'nullable|date|after_or_equal:fecha_recuperacion'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Si se cambia el horario, actualizar cupos
            if ($request->has('horario_recuperacion_id') && 
                $request->horario_recuperacion_id != $recuperacion->horario_recuperacion_id) {
                
                // Liberar cupo del horario anterior
                $horarioAnterior = Horario::find($recuperacion->horario_recuperacion_id);
                if ($horarioAnterior) {
                    $horarioAnterior->decrement('cupo_actual');
                }

                // Asignar nuevo horario y verificar cupo
                $nuevoHorario = Horario::findOrFail($request->horario_recuperacion_id);
                if ($nuevoHorario->cupo_actual >= $nuevoHorario->cupo_maximo) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El nuevo horario ya está lleno'
                    ], 400);
                }

                // Incrementar cupo del nuevo horario
                $nuevoHorario->increment('cupo_actual');
            }

            // Actualizar recuperación
            $recuperacion->update($request->only([
                'horario_recuperacion_id',
                'fecha_recuperacion',
                'motivo',
                'comentarios',
                'fecha_limite'
            ]));

            DB::commit();

            $recuperacion->refresh();
            $recuperacion->load(['horario', 'permisoJustificado', 'estudiante']);

            return response()->json([
                'success' => true,
                'message' => 'Recuperación actualizada exitosamente',
                'data' => $recuperacion
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la recuperación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        DB::beginTransaction();
        
        try {
            $recuperacion = RecuperacionClase::findOrFail($id);

            // Solo se pueden eliminar recuperaciones pendientes o programadas
            if (!in_array($recuperacion->estado, ['pendiente', 'programada'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar una recuperación ' . $recuperacion->estado
                ], 400);
            }

            // 1. Liberar cupo del horario
            if ($recuperacion->horario) {
                $recuperacion->horario->decrement('cupo_actual');
            }

            // 2. Actualizar el permiso justificado
            if ($recuperacion->permisoJustificado) {
                $recuperacion->permisoJustificado->update([
                    'tiene_recuperacion' => false,
                    'recuperacion_id' => null
                ]);
            }

            // 3. Eliminar la recuperación
            $recuperacion->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Recuperación eliminada exitosamente'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la recuperación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * MÉTODOS ADICIONALES PARA EL SISTEMA DE RECUPERACIONES
     */

    /**
     * Marcar recuperación como completada
     */
    public function completar(Request $request, string $id)
    {
        DB::beginTransaction();
        
        try {
            $recuperacion = RecuperacionClase::findOrFail($id);

            // Validar que esté programada
            if ($recuperacion->estado !== 'programada') {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden completar recuperaciones programadas'
                ], 400);
            }

            // Validar que la fecha de recuperación sea hoy o en el pasado
            if (Carbon::parse($recuperacion->fecha_recuperacion)->gt(Carbon::today())) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede completar una recuperación futura'
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'asistio' => 'required|boolean',
                'observaciones' => 'nullable|string|max:500',
                'hora_registro' => 'nullable|date_format:H:i'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($request->asistio) {
                // 1. Crear registro de asistencia para la recuperación
                $asistencia = Asistencia::create([
                    'inscripcion_id' => $recuperacion->inscripcion_id,
                    'estudiante_id' => $recuperacion->estudiante_id,
                    'horario_id' => $recuperacion->horario_recuperacion_id,
                    'fecha' => $recuperacion->fecha_recuperacion,
                    'hora_registro' => $request->hora_registro ?? now()->format('H:i'),
                    'estado' => 'asistio',
                    'es_recuperacion' => true,
                    'recuperacion_id' => $recuperacion->id,
                    'observaciones' => $request->observaciones ?? 'Recuperación completada'
                ]);

                // 2. Marcar recuperación como completada
                $recuperacion->marcarComoCompletada($asistencia->id);

                // 3. Actualizar clases asistidas en la inscripción
                $inscripcion = Inscripcion::find($recuperacion->inscripcion_id);
                if ($inscripcion) {
                    $inscripcion->increment('clases_asistidas');
                    
                    // Si tiene inscripcion_horarios, actualizar también
                    if ($inscripcion->inscripcion_horarios) {
                        $inscripcionHorario = $inscripcion->inscripcion_horarios()
                            ->where('horario_id', $recuperacion->horario_recuperacion_id)
                            ->first();
                        
                        if ($inscripcionHorario) {
                            $inscripcionHorario->increment('clases_asistidas');
                            $inscripcionHorario->decrement('clases_restantes');
                        }
                    }
                }

            } else {
                // Si no asistió, cancelar la recuperación
                $recuperacion->cancelar('El estudiante no asistió a la recuperación programada');
            }

            DB::commit();

            $recuperacion->refresh();
            $recuperacion->load(['asistenciaRecuperacion', 'estudiante', 'horario']);

            return response()->json([
                'success' => true,
                'message' => $request->asistio ? 
                    'Recuperación marcada como completada' : 
                    'Recuperación cancelada por inasistencia',
                'data' => $recuperacion
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la recuperación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancelar recuperación
     */
    public function cancelar(Request $request, string $id)
    {
        DB::beginTransaction();
        
        try {
            $recuperacion = RecuperacionClase::findOrFail($id);

            // Validar que se pueda cancelar
            if (!in_array($recuperacion->estado, ['pendiente', 'programada'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede cancelar una recuperación ' . $recuperacion->estado
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'motivo' => 'required|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // 1. Liberar cupo del horario
            if ($recuperacion->horario) {
                $recuperacion->horario->decrement('cupo_actual');
            }

            // 2. Actualizar permiso justificado
            if ($recuperacion->permisoJustificado) {
                $recuperacion->permisoJustificado->update([
                    'tiene_recuperacion' => false,
                    'recuperacion_id' => null
                ]);
            }

            // 3. Cancelar recuperación
            $recuperacion->cancelar($request->motivo);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Recuperación cancelada exitosamente',
                'data' => $recuperacion
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Error al cancelar la recuperación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener recuperaciones por inscripción
     */
     public function porInscripcion($inscripcionId)
    {
        try {
            $recuperaciones = RecuperacionClase::with([
                'estudiante:id,nombres,apellidos',
                'permisoJustificado:id,motivo,fecha_falta',
                'horario:id,dia_semana,hora_inicio,hora_fin,entrenador_id,sucursal_id',
                'horario.entrenador:id,nombres,apellidos',
                'horario.sucursal:id,nombre',
                'administrador:id,nombres'
            ])
            ->where('inscripcion_id', $inscripcionId)
            ->orderBy('fecha_recuperacion', 'desc')
            ->get();

            return response()->json([
                'success' => true,
                'message' => 'Recuperaciones obtenidas exitosamente',
                'data' => $recuperaciones
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener recuperaciones',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener recuperaciones por estudiante
     */
    public function porEstudiante(string $estudianteId)
    {
        try {
            $recuperaciones = RecuperacionClase::with([
                'inscripcion:id,modalidad_id,fecha_inicio,fecha_fin',
                'inscripcion.modalidad:id,nombre',
                'permisoJustificado:id,motivo,fecha_falta',
                'horario:id,dia_semana,hora_inicio,hora_fin,entrenador_id,sucursal_id',
                'horario.entrenador:id,nombres',
                'horario.sucursal:id,nombre',
                'administrador:id,nombres'
            ])
            ->where('estudiante_id', $estudianteId)
            ->orderBy('fecha_recuperacion', 'desc')
            ->get();

            return response()->json([
                'success' => true,
                'message' => 'Recuperaciones obtenidas exitosamente',
                'data' => $recuperaciones
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener recuperaciones',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener permisos recuperables por inscripción
     */
    public function permisosRecuperables(string $inscripcionId)
    {
        try {
            // 1. Obtener la inscripción
            $inscripcion = Inscripcion::with(['estudiante', 'permisosJustificados'])
                ->findOrFail($inscripcionId);

            // 2. Filtrar permisos aprobados que no tengan recuperación
            $permisosRecuperables = $inscripcion->permisosJustificados()
                ->where('estado', 'aprobado')
                ->where(function($query) {
                    $query->whereNull('tiene_recuperacion')
                          ->orWhere('tiene_recuperacion', false);
                })
                ->whereDate('fecha_falta', '>=', Carbon::today()->subDays(30)) // Solo últimos 30 días
                ->get()
                ->map(function($permiso) {
                    // Calcular fecha límite para recuperar (15 días desde la falta)
                    $fechaLimite = Carbon::parse($permiso->fecha_falta)->addDays(15);
                    
                    return [
                        'id' => $permiso->id,
                        'motivo' => $permiso->motivo,
                        'fecha_falta' => $permiso->fecha_falta,
                        'evidencia' => $permiso->evidencia,
                        'estado' => $permiso->estado,
                        'fecha_limite_recuperacion' => $fechaLimite->toDateString(),
                        'dias_restantes' => $fechaLimite->diffInDays(Carbon::today(), false),
                        'puede_recuperar' => $fechaLimite->gte(Carbon::today())
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Permisos recuperables obtenidos',
                'data' => [
                    'inscripcion' => $inscripcion->only(['id', 'estudiante_id', 'modalidad_id']),
                    'estudiante' => $inscripcion->estudiante,
                    'permisos_recuperables' => $permisosRecuperables,
                    'total' => $permisosRecuperables->count()
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permisos recuperables',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Horarios disponibles para recuperación
     */
    public function horariosDisponibles(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'modalidad_id' => 'nullable|exists:modalidades,id',
                'fecha' => 'nullable|date',
                'excluir_horarios_estudiante' => 'nullable|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $query = Horario::with(['entrenador', 'sucursal', 'modalidad'])
                ->where('estado', 'activo')
                ->where(function($q) {
                    $q->whereNull('cupo_maximo')
                      ->orWhereColumn('cupo_actual', '<', 'cupo_maximo');
                });

            // Filtrar por modalidad si se especifica
            if ($request->has('modalidad_id')) {
                $query->where('modalidad_id', $request->modalidad_id);
            }

            // Filtrar por fecha si se especifica (para ver disponibilidad ese día)
            if ($request->has('fecha')) {
                $fecha = Carbon::parse($request->fecha);
                $diaSemana = strtolower($fecha->locale('es')->dayName);
                
                $query->where('dia_semana', 'like', "%{$diaSemana}%");
            }

            // Excluir horarios donde el estudiante ya está inscrito
            if ($request->has('estudiante_id') && $request->excluir_horarios_estudiante) {
                $horariosEstudiante = Inscripcion::where('estudiante_id', $request->estudiante_id)
                    ->where('estado', 'activo')
                    ->with('horarios')
                    ->get()
                    ->flatMap(function($inscripcion) {
                        return $inscripcion->horarios->pluck('id');
                    })
                    ->unique()
                    ->toArray();

                if (!empty($horariosEstudiante)) {
                    $query->whereNotIn('id', $horariosEstudiante);
                }
            }

            $horarios = $query->get()
                ->map(function($horario) {
                    return [
                        'id' => $horario->id,
                        'nombre' => $horario->nombre,
                        'dia_semana' => $horario->dia_semana,
                        'hora_inicio' => $horario->hora_inicio,
                        'hora_fin' => $horario->hora_fin,
                        'entrenador_nombre' => $horario->entrenador ? 
                            "{$horario->entrenador->nombres} {$horario->entrenador->apellidos}" : 
                            'Sin entrenador',
                        'sucursal_nombre' => $horario->sucursal->nombre ?? 'Sin sucursal',
                        'modalidad_nombre' => $horario->modalidad->nombre ?? 'Sin modalidad',
                        'cupo_maximo' => $horario->cupo_maximo,
                        'cupo_actual' => $horario->cupo_actual,
                        'cupo_disponible' => $horario->cupo_maximo - $horario->cupo_actual,
                        'esta_lleno' => $horario->cupo_actual >= $horario->cupo_maximo
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Horarios disponibles obtenidos',
                'data' => $horarios
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener horarios disponibles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verificar período válido para recuperación
     */
    public function verificarPeriodo(string $inscripcionId)
    {
        try {
            $inscripcion = Inscripcion::findOrFail($inscripcionId);
            
            // Verificar que la inscripción esté activa
            if ($inscripcion->estado !== 'activo') {
                return response()->json([
                    'success' => false,
                    'message' => 'La inscripción no está activa',
                    'puede_recuperar' => false,
                    'razon' => 'Inscripción inactiva'
                ], 400);
            }

            // Verificar que tenga permisos disponibles para recuperar
            $permisosRecuperables = $inscripcion->permisosJustificados()
                ->where('estado', 'aprobado')
                ->where(function($query) {
                    $query->whereNull('tiene_recuperacion')
                          ->orWhere('tiene_recuperacion', false);
                })
                ->count();

            $puedeRecuperar = $permisosRecuperables > 0;

            return response()->json([
                'success' => true,
                'message' => 'Período verificado',
                'data' => [
                    'puede_recuperar' => $puedeRecuperar,
                    'inscripcion_activa' => true,
                    'permisos_recuperables' => $permisosRecuperables,
                    'fecha_actual' => Carbon::today()->toDateString(),
                    'fecha_fin_inscripcion' => $inscripcion->fecha_fin
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar período',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reporte de recuperaciones
     */
    public function reporteMensual(Request $request)
    {
        try {
            $mes = $request->get('mes', Carbon::now()->month);
            $anio = $request->get('anio', Carbon::now()->year);

            $recuperaciones = RecuperacionClase::with(['estudiante', 'inscripcion.modalidad'])
                ->whereYear('fecha_recuperacion', $anio)
                ->whereMonth('fecha_recuperacion', $mes)
                ->get()
                ->groupBy('estado');

            $estadisticas = [
                'total' => RecuperacionClase::whereYear('fecha_recuperacion', $anio)
                    ->whereMonth('fecha_recuperacion', $mes)
                    ->count(),
                'programadas' => $recuperaciones->get('programada', collect())->count(),
                'completadas' => $recuperaciones->get('completada', collect())->count(),
                'canceladas' => $recuperaciones->get('cancelada', collect())->count(),
                'por_estudiante' => $recuperaciones->flatten()
                    ->groupBy('estudiante_id')
                    ->map(function($recups, $estId) {
                        return [
                            'estudiante_id' => $estId,
                            'estudiante_nombre' => $recups->first()->estudiante->nombres ?? 'Desconocido',
                            'total' => $recups->count(),
                            'completadas' => $recups->where('estado', 'completada')->count()
                        ];
                    })->values()
            ];

            return response()->json([
                'success' => true,
                'message' => 'Reporte mensual generado',
                'data' => [
                    'mes' => $mes,
                    'anio' => $anio,
                    'estadisticas' => $estadisticas,
                    'recuperaciones' => $recuperaciones->flatten()->take(50) // Limitar para no sobrecargar
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar reporte',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}