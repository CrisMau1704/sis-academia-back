<?php

namespace App\Http\Controllers;

use App\Models\RecuperacionClase;
use App\Models\Asistencia;
use App\Models\Inscripcion;
use App\Models\Horario;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RecuperacionController extends Controller
{
    // Programar recuperación
    public function store(Request $request)
    {
        $request->validate([
            'asistencia_id' => 'required|exists:asistencias,id',
            'fecha_recuperacion' => 'required|date',
            'horario_recuperacion_id' => 'required|exists:horarios,id',
            'motivo' => 'nullable|string'
        ]);
        
        $asistencia = Asistencia::find($request->asistencia_id);
        
        // Verificar que la falta no haya sido ya recuperada
        if ($asistencia->recuperada) {
            return response()->json([
                'success' => false,
                'message' => 'Esta falta ya fue recuperada'
            ], 400);
        }
        
        // Verificar que esté en periodo válido
        $inscripcion = $asistencia->inscripcion;
        $enPeriodoValido = $this->verificarPeriodoRecuperacion($inscripcion, $request->fecha_recuperacion);
        
        if (!$enPeriodoValido) {
            return response()->json([
                'success' => false,
                'message' => 'Fuera del periodo de recuperación. Solo se permite en la primera semana después del vencimiento.'
            ], 400);
        }
        
        // Verificar cupo disponible en horario
        $horario = Horario::find($request->horario_recuperacion_id);
        if ($horario->cupo_actual >= $horario->cupo_maximo) {
            return response()->json([
                'success' => false,
                'message' => 'No hay cupo disponible en este horario'
            ], 400);
        }
        
        // Crear recuperación
        $recuperacion = RecuperacionClase::create([
            'asistencia_id' => $request->asistencia_id,
            'fecha_recuperacion' => $request->fecha_recuperacion,
            'horario_recuperacion_id' => $request->horario_recuperacion_id,
            'motivo' => $request->motivo,
            'estado' => 'programada',
            'en_periodo_valido' => true,
            'administrador_id' => auth()->id()
        ]);
        
        // Marcar asistencia como recuperada
        $asistencia->update(['recuperada' => true]);
        
        // Actualizar cupo del horario
        $horario->increment('cupo_actual');
        
        return response()->json([
            'success' => true,
            'message' => 'Recuperación programada correctamente',
            'data' => $recuperacion
        ]);
    }
    
    // Completar recuperación
    public function completar($id)
    {
        $recuperacion = RecuperacionClase::findOrFail($id);
        
        // Registrar nueva asistencia como recuperada
        Asistencia::create([
            'inscripcion_id' => $recuperacion->asistencia->inscripcion_id,
            'horario_id' => $recuperacion->horario_recuperacion_id,
            'fecha' => $recuperacion->fecha_recuperacion,
            'estado' => 'asistio',
            'observacion' => 'Clase recuperada - ' . $recuperacion->motivo
        ]);
        
        $recuperacion->update([
            'estado' => 'completada',
            'administrador_id' => auth()->id()
        ]);
        
        // Liberar cupo del horario
        $recuperacion->horarioRecuperacion->decrement('cupo_actual');
        
        return response()->json([
            'success' => true,
            'message' => 'Recuperación completada'
        ]);
    }
    
    // Obtener recuperaciones pendientes
    public function index(Request $request)
    {
        $query = RecuperacionClase::with(['asistencia.inscripcion.estudiante', 'horarioRecuperacion'])
            ->where('estado', 'programada');
        
        if ($request->has('solo_periodo_valido') && $request->solo_periodo_valido) {
            $query->where('en_periodo_valido', true);
        }
        
        $recuperaciones = $query->get()->map(function($recuperacion) {
            return [
                'id' => $recuperacion->id,
                'estudiante_nombres' => $recuperacion->asistencia->inscripcion->estudiante->nombres . ' ' . 
                                       $recuperacion->asistencia->inscripcion->estudiante->apellidos,
                'fecha_falta' => $recuperacion->asistencia->fecha,
                'fecha_recuperacion' => $recuperacion->fecha_recuperacion,
                'horario_original' => $recuperacion->asistencia->horario->hora_inicio . ' - ' . 
                                     $recuperacion->asistencia->horario->hora_fin,
                'horario_recuperacion' => $recuperacion->horarioRecuperacion->hora_inicio . ' - ' . 
                                         $recuperacion->horarioRecuperacion->hora_fin,
                'estado' => $recuperacion->estado,
                'en_periodo_valido' => $recuperacion->en_periodo_valido,
                'dias_restantes_periodo' => $this->calcularDiasRestantesPeriodo($recuperacion)
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => $recuperaciones
        ]);
    }
    
    // Horarios disponibles para recuperación
    public function horariosDisponibles(Request $request)
    {
        $request->validate([
            'fecha' => 'required|date',
            'sucursal_id' => 'nullable|exists:sucursales,id',
            'disciplina_id' => 'nullable|exists:disciplinas,id'
        ]);
        
        $fecha = Carbon::parse($request->fecha);
        $diaSemana = $fecha->locale('es')->dayName;
        
        $query = Horario::where('dia_semana', $diaSemana)
            ->where('estado', 'activo')
            ->where('cupo_actual', '<', 'cupo_maximo');
        
        if ($request->sucursal_id) {
            $query->where('sucursal_id', $request->sucursal_id);
        }
        
        if ($request->disciplina_id) {
            $query->where('disciplina_id', $request->disciplina_id);
        }
        
        $horarios = $query->get()->map(function($horario) {
            return [
                'id' => $horario->id,
                'hora_inicio' => $horario->hora_inicio,
                'hora_fin' => $horario->hora_fin,
                'modalidad_nombre' => $horario->modalidad->nombre ?? 'N/A',
                'cupo_disponible' => $horario->cupo_maximo - $horario->cupo_actual,
                'cupo_maximo' => $horario->cupo_maximo,
                'sucursal_nombre' => $horario->sucursal->nombre ?? 'N/A'
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => [
                'horarios_disponibles' => $horarios,
                'fecha' => $request->fecha,
                'dia_semana' => $diaSemana
            ]
        ]);
    }
    
    private function verificarPeriodoRecuperacion($inscripcion, $fechaRecuperacion)
    {
        $fechaFin = Carbon::parse($inscripcion->fecha_fin);
        $fechaRecuperacion = Carbon::parse($fechaRecuperacion);
        
        // Primera semana después del vencimiento
        $inicioPeriodo = $fechaFin->copy()->addDay();
        $finPeriodo = $fechaFin->copy()->addDays(7);
        
        return $fechaRecuperacion->between($inicioPeriodo, $finPeriodo);
    }
    
    private function calcularDiasRestantesPeriodo($recuperacion)
    {
        $fechaRecuperacion = Carbon::parse($recuperacion->fecha_recuperacion);
        $fechaFinInscripcion = Carbon::parse($recuperacion->asistencia->inscripcion->fecha_fin);
        
        $finPeriodo = $fechaFinInscripcion->copy()->addDays(7);
        
        return $fechaRecuperacion->diffInDays($finPeriodo, false);
    }
}