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



    
}