<?php

namespace App\Http\Controllers;

use App\Models\Asistencia;
use App\Models\Inscripcion;
use App\Models\InscripcionHorario;
use App\Models\Horario;
use App\Models\Estudiante;
use App\Models\PermisoJustificado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AsistenciaController extends Controller
{
    // Agrega esto al final del controlador, antes del cierre }
public function show($id)
{
    // Si alguien accede a /api/asistencias/dia y Laravel interpreta "dia" como {id}
    if ($id === 'dia' || $id === 'estadisticas' || $id === 'permisos' || $id === 'motivos' || $id === 'exportar') {
        // Redirige a la función correcta según el caso
        $request = request();
        
        if ($id === 'dia') {
            return $this->obtenerDia($request);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Accede a la ruta correcta: /api/asistencias/' . $id
        ], 404);
    }
    
    // Si es un ID numérico, buscar la asistencia
    $asistencia = Asistencia::with(['inscripcion.estudiante', 'horario'])->find($id);
    
    if (!$asistencia) {
        return response()->json([
            'success' => false,
            'message' => 'Asistencia no encontrada'
        ], 404);
    }
    
    return response()->json([
        'success' => true,
        'data' => $asistencia
    ]);
}
    public function obtenerDia(Request $request)
    {
        try {
            $fecha = $request->input('fecha', date('Y-m-d'));
            Log::info("📅 Obtener asistencias para: {$fecha}");
            
            // Obtener el día en español desde la fecha
            $numeroDia = date('N', strtotime($fecha)); // 1=Lunes, 7=Domingo
            $diasMap = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 
                        5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
            $diaSemana = $diasMap[$numeroDia];
            
            // 1. Obtener TODOS los horarios activos de ese día
            $horarios = Horario::where('dia_semana', $diaSemana)
                ->where('estado', 'activo')
                ->with(['modalidad:id,nombre', 'entrenador:id,nombres,apellidos', 'sucursal:id,nombre'])
                ->orderBy('hora_inicio')
                ->get()
                ->map(function($horario) use ($fecha) {
                    return $this->formatearHorario($horario, $fecha);
                });
            
            // 2. Calcular estadísticas rápidas
            $estadisticas = $this->calcularEstadisticasDia($fecha);
            
            return response()->json([
                'success' => true,
                'fecha' => $fecha,
                'dia_semana' => $diaSemana,
                'estadisticas' => $estadisticas,
                'horarios' => $horarios
            ]);
            
        } catch (\Exception $e) {
            Log::error("❌ Error obtenerDia: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar asistencias'
            ], 500);
        }
    }
    
    /**
     * 2. Marcar asistencia (UN SOLO CLICK)
     */
   public function marcar(Request $request)
{
    try {
        $request->validate([
            'inscripcion_id' => 'required|exists:inscripciones,id',
            'horario_id' => 'required|exists:horarios,id',
            'fecha' => 'required|date',
            'estado' => 'required|in:asistio,falto,permiso'  // Puede incluir 'permiso' pero sin crear permiso automático
        ]);
        
        Log::info("✅ Marcando asistencia: Insc={$request->inscripcion_id}, Hor={$request->horario_id}, Estado={$request->estado}");
        
        // Buscar o crear asistencia
        $asistencia = Asistencia::updateOrCreate(
            [
                'inscripcion_id' => $request->inscripcion_id,
                'horario_id' => $request->horario_id,
                'fecha' => $request->fecha
            ],
            [
                'estado' => $request->estado,
                'observacion' => $request->observacion ?? 'Marcado rápido'  // ← SIMPLIFICADO
                // Ya no hay distinción especial para 'permiso'
            ]
        );
        
        // ACTUALIZACIÓN: Ya NO llamar a registrarPermisoAutomatico
        // El permiso se crea SOLO con el método justificar()
        
        // Actualizar contadores en inscripcion_horario
        $this->actualizarContadorHorario($request->inscripcion_id, $request->horario_id);
        
        // Obtener datos actualizados para respuesta
        $estudiante = $this->obtenerDatosEstudiante($request->inscripcion_id, $request->horario_id, $request->fecha);
        
        return response()->json([
            'success' => true,
            'message' => $this->getMensajeEstado($request->estado),
            'asistencia' => $asistencia,
            'estudiante' => $estudiante
        ]);
        
    } catch (\Exception $e) {
        Log::error("❌ Error marcar: " . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error al marcar asistencia: ' . $e->getMessage()  // ← Mostrar mensaje real
        ], 500);
    }
}
    
    /**
     * 3. Justificar falta (MOTIVOS PREDEFINIDOS)
     */
// app/Http/Controllers/AsistenciaController.php

// AsistenciaController.php - método justificar()

public function justificar(Request $request)
{
    try {
        \Log::info('=== JUSTIFICAR LLAMADO ===');
        \Log::info('Datos recibidos:', $request->all());
        
        // Validación
        $request->validate([
            'inscripcion_id' => 'required|exists:inscripciones,id',
            'horario_id' => 'required|exists:horarios,id',
            'fecha' => 'required|date',
            'motivo' => 'required|string|max:500'
        ]);
        
        // Obtener usuario autenticado
        $usuarioId = auth()->check() ? auth()->id() : 1;
        
        // Usar el método del modelo Asistencia
        $resultado = Asistencia::justificarFalta(
            $request->inscripcion_id,
            $request->horario_id,
            $request->fecha,
            $request->motivo,
            $usuarioId
        );
        
        if (!$resultado['success']) {
            \Log::error('Error del modelo:', ['error' => $resultado['error']]);
            throw new \Exception($resultado['error']);
        }
        
        \Log::info('✅ Permiso justificado exitosamente:', [
            'asistencia_id' => $resultado['asistencia']->id,
            'permiso_id' => $resultado['permiso']->id,
            'permisos_restantes' => $resultado['permisos_restantes']
        ]);
        
        // AsistenciaController.php - método justificar()
return response()->json([
    'success' => true,
    'message' => 'Falta justificada correctamente',
    'data' => [ // ← ESTE "data" ES LO QUE BUSCA EL FRONTEND
        'asistencia' => [
            'id' => $resultado['asistencia']->id,
            'inscripcion_id' => $resultado['asistencia']->inscripcion_id,
            'estado' => $resultado['asistencia']->estado
        ],
        'permiso' => [
            'id' => $resultado['permiso']->id,
            'motivo' => $resultado['permiso']->motivo
        ],
        'permisos_restantes' => $resultado['permisos_restantes']
    ]
]);
        
    } catch (\Exception $e) {
        \Log::error('❌ Error en controlador justificar:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'data' => $request->all()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}
    
    /**
     * 4. Acciones en lote (PARA TODO EL HORARIO)
     */
    public function marcarLote(Request $request)
    {
        try {
            $request->validate([
                'horario_id' => 'required|exists:horarios,id',
                'fecha' => 'required|date',
                'accion' => 'required|in:presentes,faltos,limpiar'
            ]);
            
            Log::info("🎯 Acción en lote: Hor={$request->horario_id}, Acción={$request->accion}");
            
            // Obtener todas las inscripciones activas en este horario
            $inscripciones = InscripcionHorario::where('horario_id', $request->horario_id)
                ->where('estado', 'activo')
                ->whereDate('fecha_inicio', '<=', $request->fecha)
                ->whereDate('fecha_fin', '>=', $request->fecha)
                ->with('inscripcion')
                ->get();
            
            $estado = $request->accion == 'presentes' ? 'asistio' : 'falto';
            $contador = 0;
            
            DB::beginTransaction();
            
            foreach ($inscripciones as $inscHorario) {
                $asistencia = Asistencia::updateOrCreate(
                    [
                        'inscripcion_id' => $inscHorario->inscripcion_id,
                        'horario_id' => $request->horario_id,
                        'fecha' => $request->fecha
                    ],
                    [
                        'estado' => $estado,
                        'observacion' => "Marcado en lote ({$request->accion})"
                    ]
                );
                
                $contador++;
            }
            
            // Si es "limpiar", eliminar todas las asistencias de ese día/horario
            if ($request->accion == 'limpiar') {
                $eliminadas = Asistencia::where('horario_id', $request->horario_id)
                    ->where('fecha', $request->fecha)
                    ->delete();
                $contador = $eliminadas;
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => $this->getMensajeLote($request->accion, $contador),
                'total' => $contador
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error marcarLote: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error en acción en lote'
            ], 500);
        }
    }
    
    /**
     * 5. Obtener estadísticas del día (PARA EL DASHBOARD)
     */
    public function estadisticas(Request $request)
    {
        try {
            $fecha = $request->input('fecha', date('Y-m-d'));
            
            $estadisticas = $this->calcularEstadisticasDia($fecha);
            
            // Obtener resumen por horario
            $resumenHorarios = Asistencia::whereDate('fecha', $fecha)
                ->selectRaw('horario_id, estado, COUNT(*) as total')
                ->groupBy('horario_id', 'estado')
                ->with('horario:id,nombre,hora_inicio,hora_fin')
                ->get()
                ->groupBy('horario_id');
            
            return response()->json([
                'success' => true,
                'fecha' => $fecha,
                'estadisticas' => $estadisticas,
                'resumen_horarios' => $resumenHorarios
            ]);
            
        } catch (\Exception $e) {
            Log::error("❌ Error estadisticas: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al calcular estadísticas'
            ], 500);
        }
    }
    
    // ====================== FUNCIONES AUXILIARES ======================
    
    /**
     * Formatear datos del horario con estudiantes
     */
   private function formatearHorario($horario, $fecha)
{
    // Obtener estudiantes inscritos en este horario para esta fecha
    $inscripciones = InscripcionHorario::where('horario_id', $horario->id)
        ->where('estado', 'activo')
        ->whereDate('fecha_inicio', '<=', $fecha)
        ->whereDate('fecha_fin', '>=', $fecha)
        ->with(['inscripcion.estudiante'])
        ->get();
    
    $estudiantes = $inscripciones->map(function($inscHorario) use ($horario, $fecha) {
        $estudiante = $inscHorario->inscripcion->estudiante;
        
        // Buscar asistencia registrada
        $asistencia = Asistencia::where('inscripcion_id', $inscHorario->inscripcion_id)
            ->where('horario_id', $horario->id)
            ->where('fecha', $fecha)
            ->first();
        
        // Calcular datos del estudiante
        $clasesRestantes = $inscHorario->clases_restantes;
        $permisosDisponibles = $inscHorario->inscripcion->permisos_disponibles;
        $puedeRecuperar = $this->puedeRecuperarClase($inscHorario->inscripcion, $fecha);
        
        return [
            'id' => $estudiante->id,
            'nombres' => $estudiante->nombres,
            'apellidos' => $estudiante->apellidos,
            'ci' => $estudiante->ci,
            'telefono' => $estudiante->telefono,
            'email' => $estudiante->email,
            'inscripcion_id' => $inscHorario->inscripcion_id,
            'horario_id' => $horario->id, 
            'inscripcion_horario_id' => $inscHorario->id,
            'asistencia_id' => $asistencia ? $asistencia->id : null,
            'asistencia_estado' => $asistencia ? $asistencia->estado : null,
            'clases_asistidas' => $inscHorario->clases_asistidas,
            'clases_totales' => $inscHorario->clases_totales,
            'clases_restantes' => $clasesRestantes,
            'permisos_usados' => $inscHorario->permisos_usados,
            'permisos_disponibles' => $permisosDisponibles,
            'fecha_fin' => $inscHorario->fecha_fin,
            'puede_recuperar' => $puedeRecuperar,
            'en_periodo_recuperacion' => $this->enPeriodoRecuperacion($inscHorario->fecha_fin, $fecha),
            'color_alerta' => $this->getColorAlerta($clasesRestantes, $permisosDisponibles)
        ];
    });
    
    // Calcular contadores
    $totalEstudiantes = $estudiantes->count();
    $estudiantesPresentes = $estudiantes->where('asistencia_estado', 'asistio')->count();
    
    return [
        'id' => $horario->id,
        'nombre' => $horario->nombre,
        'dia_semana' => $horario->dia_semana,
        
        // CORRECIÓN: Formatear horas correctamente
        'hora_inicio' => $this->formatearHora($horario->hora_inicio),
        'hora_fin' => $this->formatearHora($horario->hora_fin),
        
        'modalidad' => $horario->modalidad,
        'entrenador' => $horario->entrenador,
        'sucursal' => $horario->sucursal,
        'cupo_maximo' => $horario->cupo_maximo,
        'cupo_actual' => $totalEstudiantes,
        'color' => $horario->color,
        'estado' => $horario->estado,
        'estudiantes' => $estudiantes,
        'total_estudiantes' => $totalEstudiantes,
        'estudiantes_presentes' => $estudiantesPresentes,
        'estudiantes_faltaron' => $estudiantes->where('asistencia_estado', 'falto')->count(),
        'estudiantes_permiso' => $estudiantes->where('asistencia_estado', 'permiso')->count(),
        'porcentaje_asistencia' => $totalEstudiantes > 0 ? round(($estudiantesPresentes / $totalEstudiantes) * 100) : 0
    ];
}

/**
 * Formatear hora correctamente (solo hora:minutos)
 */
private function formatearHora($hora)
{
    if (!$hora) return '--:--';
    
    // Si es un string con fecha completa (2026-01-09T19:00:00.000000Z)
    if (strpos($hora, 'T') !== false) {
        return Carbon::parse($hora)->format('H:i');
    }
    
    // Si ya es solo hora (19:00:00)
    if (strpos($hora, ':') !== false) {
        $partes = explode(':', $hora);
        return $partes[0] . ':' . $partes[1]; // Solo hora:minutos
    }
    
    return $hora;
}
    
    /**
     * Calcular estadísticas del día
     */
    private function calcularEstadisticasDia($fecha)
    {
        $total = Asistencia::whereDate('fecha', $fecha)->count();
        $asistencias = Asistencia::whereDate('fecha', $fecha)->where('estado', 'asistio')->count();
        $faltas = Asistencia::whereDate('fecha', $fecha)->where('estado', 'falto')->count();
        $permisos = Asistencia::whereDate('fecha', $fecha)->where('estado', 'permiso')->count();
        
        // Obtener total de estudiantes inscritos para hoy
        $diaSemana = $this->getDiaSemana($fecha);
        $horariosHoy = Horario::where('dia_semana', $diaSemana)
            ->where('estado', 'activo')
            ->count();
        
        return [
            'total_registros' => $total,
            'asistencias' => $asistencias,
            'faltas' => $faltas,
            'permisos' => $permisos,
            'horarios_activos' => $horariosHoy,
            'porcentaje_asistencia' => $total > 0 ? round(($asistencias / $total) * 100, 1) : 0
        ];
    }
    
    /**
     * Actualizar contador en inscripcion_horario
     */
    private function actualizarContadorHorario($inscripcionId, $horarioId)
    {
        try {
            $inscHorario = InscripcionHorario::where('inscripcion_id', $inscripcionId)
                ->where('horario_id', $horarioId)
                ->first();
            
            if ($inscHorario) {
                // Contar asistencias reales (no incluye permisos)
                $clasesAsistidas = Asistencia::where('inscripcion_id', $inscripcionId)
                    ->where('horario_id', $horarioId)
                    ->where('estado', 'asistio')
                    ->count();
                
                $inscHorario->update([
                    'clases_asistidas' => $clasesAsistidas,
                    'clases_restantes' => max(0, $inscHorario->clases_totales - $clasesAsistidas)
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Error actualizarContadorHorario: " . $e->getMessage());
        }
    }
    
    /**
     * Registrar permiso automático
     */
    private function registrarPermisoAutomatico($asistencia, $observacion = null)
    {
        try {
            $inscripcion = Inscripcion::find($asistencia->inscripcion_id);
            
            if ($inscripcion && $inscripcion->permisos_disponibles > 0) {
                $permiso = PermisoJustificado::create([
                    'inscripcion_id' => $asistencia->inscripcion_id,
                    'asistencia_id' => $asistencia->id,
                    'fecha_solicitud' => now()->toDateString(),
                    'fecha_falta' => $asistencia->fecha,
                    'motivo' => $observacion ?? 'Permiso automático',
                    'estado' => 'aprobado',
                    'administrador_id' => auth()->id() ?? 1
                ]);
                
                $asistencia->update(['permiso_id' => $permiso->id]);
                $inscripcion->decrement('permisos_disponibles');
                $inscripcion->increment('permisos_usados');
                
                // Actualizar inscripcion_horario
                $inscHorario = InscripcionHorario::where('inscripcion_id', $asistencia->inscripcion_id)
                    ->where('horario_id', $asistencia->horario_id)
                    ->first();
                
                if ($inscHorario) {
                    $inscHorario->increment('permisos_usados');
                }
            }
        } catch (\Exception $e) {
            Log::error("Error registrarPermisoAutomatico: " . $e->getMessage());
        }
    }
    
    /**
     * Verificar si puede recuperar clase
     */
    private function puedeRecuperarClase($inscripcion, $fecha)
    {
        if (!$inscripcion->fecha_fin) return false;
        
        $fechaFin = Carbon::parse($inscripcion->fecha_fin);
        $fechaActual = Carbon::parse($fecha);
        
        // Solo puede recuperar en los 7 días después del vencimiento
        $periodoRecuperacion = $fechaFin->copy()->addDays(7);
        
        return $fechaActual->between($fechaFin, $periodoRecuperacion);
    }
    
    /**
     * Verificar si está en periodo de recuperación
     */
    private function enPeriodoRecuperacion($fechaFin, $fechaActual)
    {
        if (!$fechaFin) return false;
        
        $fechaFin = Carbon::parse($fechaFin);
        $fechaActual = Carbon::parse($fechaActual);
        
        return $fechaActual->between($fechaFin, $fechaFin->copy()->addDays(7));
    }
    
    /**
     * Obtener color de alerta según estado
     */
    private function getColorAlerta($clasesRestantes, $permisosDisponibles)
    {
        if ($clasesRestantes <= 2) return 'danger';
        if ($clasesRestantes <= 5) return 'warning';
        if ($permisosDisponibles == 0) return 'info';
        return 'success';
    }
    
    /**
     * Obtener día de la semana en español
     */
    private function getDiaSemana($fecha)
    {
        $numeroDia = date('N', strtotime($fecha));
        $diasMap = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 
                    5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
        return $diasMap[$numeroDia];
    }
    
    /**
     * Obtener mensaje según estado
     */
    private function getMensajeEstado($estado)
    {
        $mensajes = [
            'asistio' => '¡Asistencia registrada! ✅',
            'falto' => 'Registrado como falta ❌',
            'permiso' => 'Falta justificada 📝'
        ];
        return $mensajes[$estado] ?? 'Estado registrado';
    }
    
    /**
     * Obtener mensaje para acción en lote
     */
    private function getMensajeLote($accion, $total)
    {
        $mensajes = [
            'presentes' => "✅ {$total} estudiantes marcados como presentes",
            'faltos' => "❌ {$total} estudiantes marcados como faltaron",
            'limpiar' => "🧹 {$total} registros eliminados"
        ];
        return $mensajes[$accion] ?? "Acción completada ({$total})";
    }
    
    /**
     * Obtener datos del estudiante actualizados
     */
    private function obtenerDatosEstudiante($inscripcionId, $horarioId, $fecha)
    {
        try {
            $asistencia = Asistencia::where('inscripcion_id', $inscripcionId)
                ->where('horario_id', $horarioId)
                ->where('fecha', $fecha)
                ->first();
            
            $inscHorario = InscripcionHorario::where('inscripcion_id', $inscripcionId)
                ->where('horario_id', $horarioId)
                ->first();
            
            if ($inscHorario && $inscHorario->inscripcion) {
                return [
                    'asistencia_id' => $asistencia ? $asistencia->id : null,
                    'estado' => $asistencia ? $asistencia->estado : null,
                    'clases_asistidas' => $inscHorario->clases_asistidas,
                    'clases_restantes' => $inscHorario->clases_restantes,
                    'permisos_disponibles' => $inscHorario->inscripcion->permisos_disponibles
                ];
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error("Error obtenerDatosEstudiante: " . $e->getMessage());
            return null;
        }
    }
    
    // ====================== ENDPOINTS ADICIONALES (OPCIONALES) ======================
    
    /**
     * Verificar permisos disponibles
     */
    public function verificarPermisos($inscripcionId)
    {
        try {
            $inscripcion = Inscripcion::findOrFail($inscripcionId);
            
            return response()->json([
                'success' => true,
                'permisos_disponibles' => $inscripcion->permisos_disponibles,
                'permisos_usados' => $inscripcion->permisos_usados,
                'puede_justificar' => $inscripcion->permisos_disponibles > 0
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar permisos'
            ], 500);
        }
    }
    
    /**
     * Obtener motivos predefinidos para justificaciones
     */
    public function motivosJustificacion()
    {
        return response()->json([
            'success' => true,
            'motivos' => [
                'Enfermedad',
                'Trabajo',
                'Estudios/Exámenes',
                'Viaje familiar',
                'Problemas personales',
                'Clima/Transporte',
                'Lesión deportiva',
                'Evento familiar',
                'Consulta médica',
                'Otro motivo'
            ]
        ]);
    }
    
    /**
     * Exportar reporte simple
     */
    public function exportar(Request $request)
    {
        try {
            $fecha = $request->input('fecha', date('Y-m-d'));
            $diaSemana = $this->getDiaSemana($fecha);
            
            // Obtener datos para exportar
            $horarios = Horario::where('dia_semana', $diaSemana)
                ->where('estado', 'activo')
                ->with(['modalidad', 'entrenador', 'sucursal'])
                ->get();
            
            $datosExportar = [];
            
            foreach ($horarios as $horario) {
                $inscripciones = InscripcionHorario::where('horario_id', $horario->id)
                    ->where('estado', 'activo')
                    ->whereDate('fecha_inicio', '<=', $fecha)
                    ->whereDate('fecha_fin', '>=', $fecha)
                    ->with(['inscripcion.estudiante'])
                    ->get();
                
                foreach ($inscripciones as $insc) {
                    $asistencia = Asistencia::where('inscripcion_id', $insc->inscripcion_id)
                        ->where('horario_id', $horario->id)
                        ->where('fecha', $fecha)
                        ->first();
                    
                    $datosExportar[] = [
                        'Horario' => $horario->nombre . ' (' . $horario->hora_inicio . ' - ' . $horario->hora_fin . ')',
                        'Estudiante' => $insc->inscripcion->estudiante->nombres . ' ' . $insc->inscripcion->estudiante->apellidos,
                        'CI' => $insc->inscripcion->estudiante->ci,
                        'Modalidad' => $horario->modalidad->nombre ?? 'N/A',
                        'Entrenador' => $horario->entrenador->nombres ?? 'N/A',
                        'Sucursal' => $horario->sucursal->nombre ?? 'N/A',
                        'Estado' => $asistencia ? ucfirst($asistencia->estado) : 'Sin registro',
                        'Observación' => $asistencia->observacion ?? '',
                        'Fecha' => $fecha
                    ];
                }
            }
            
            return response()->json([
                'success' => true,
                'fecha' => $fecha,
                'total_registros' => count($datosExportar),
                'data' => $datosExportar
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error exportar: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al exportar reporte'
            ], 500);
        }
    }
}