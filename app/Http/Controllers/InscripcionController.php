<?php

namespace App\Http\Controllers;

use App\Models\Inscripcion;
use App\Models\Estudiante;
use App\Models\Modalidad;
use App\Models\Horario;
use App\Models\InscripcionHorario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class InscripcionController extends Controller
{
   public function index(Request $request)
{
    try {
        $query = Inscripcion::with([
            'estudiante', 
            'modalidad', 
            'sucursal',
            'entrenador', 
            'horarios.disciplina', 
            'horarios.entrenador',
            'inscripcionHorarios'
        ])->latest();
        
        // Filtros...
        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }
        
        // SIEMPRE devolver todas sin paginación (más simple para Vue)
        $inscripciones = $query->get();
        
        // Calcular campos dinámicos
        foreach ($inscripciones as $inscripcion) {
            $inscripcion->clases_restantes_calculadas = $this->calcularClasesRestantes($inscripcion);
            $inscripcion->dias_restantes = $this->calcularDiasRestantes($inscripcion->fecha_fin);
        }
        
        return response()->json([
            'success' => true,
            'data' => $inscripciones  // ← Array directo, no paginado
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener inscripciones: ' . $e->getMessage()
        ], 500);
    }
}

public function store(Request $request)
{
    try {
        // SOLO lo absolutamente necesario
        $request->validate([
            'estudiante_id' => 'required|exists:estudiantes,id',
            'modalidad_id' => 'required|exists:modalidades,id',
            'fecha_inicio' => 'required|date',
            'horarios' => 'required|array'
        ]);
        
        // Obtener la modalidad para saber las clases mensuales
        $modalidad = DB::table('modalidades')
            ->where('id', $request->modalidad_id)
            ->first();
        
        $clasesMensuales = $modalidad->clases_mensuales ?? 12;
        $permisosMaximos = $modalidad->permisos_maximos ?? 3;
        
        // Calcular meses de duración
        $fechaInicio = Carbon::parse($request->fecha_inicio);
        $fechaFin = $request->fecha_fin 
            ? Carbon::parse($request->fecha_fin)
            : $fechaInicio->copy()->addMonth();
        
        $mesesDuracion = $fechaInicio->floatDiffInMonths($fechaFin);
        $clasesTotales = ceil($clasesMensuales * max(1, $mesesDuracion));
        
        // Insertar INSCRIPCIÓN con los campos CORRECTOS
        $inscripcionId = DB::table('inscripciones')->insertGetId([
            'estudiante_id' => $request->estudiante_id,
            'modalidad_id' => $request->modalidad_id,
            'sucursal_id' => $request->sucursal_id ?? null, // si no viene, null
            'entrenador_id' => $request->entrenador_id ?? null, // si no viene, null
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'clases_totales' => $clasesTotales,
            'clases_asistidas' => 0, // ← EMPIEZA EN 0
            'permisos_usados' => 0, // ← EMPIEZA EN 0
            'permisos_disponibles' => $permisosMaximos, // ← DE LA MODALIDAD
            'monto_mensual' => $request->monto_mensual ?? 0,
            'estado' => 'activo',
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        // Calcular distribución de clases entre horarios
        $totalHorarios = count($request->horarios);
        $clasesPorHorario = floor($clasesTotales / $totalHorarios);
        $clasesExtra = $clasesTotales % $totalHorarios;
        
        // Insertar HORARIOS
        foreach ($request->horarios as $index => $horarioId) {
            $clasesParaEsteHorario = $clasesPorHorario;
            if ($index < $clasesExtra) {
                $clasesParaEsteHorario += 1;
            }
            
            DB::table('inscripcion_horarios')->insert([
                'inscripcion_id' => $inscripcionId,
                'horario_id' => $horarioId,
                'clases_totales' => $clasesParaEsteHorario,
                'clases_asistidas' => 0,
                'clases_restantes' => $clasesParaEsteHorario, // ← AQUÍ SÍ va clases_restantes
                'permisos_usados' => 0,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'estado' => 'activo',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            // Actualizar cupo del horario
            DB::table('horarios')
                ->where('id', $horarioId)
                ->increment('cupo_actual');
        }
        
        return response()->json([
            'success' => true,
            'inscripcion_id' => $inscripcionId,
            'message' => 'Inscripción creada exitosamente',
            'data' => [
                'clases_totales' => $clasesTotales,
                'clases_por_mes' => $clasesMensuales,
                'meses_duracion' => round($mesesDuracion, 2),
                'distribucion' => [
                    'clases_por_horario_base' => $clasesPorHorario,
                    'horarios_con_extra' => $clasesExtra
                ]
            ]
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'trace' => env('APP_DEBUG') ? $e->getTraceAsString() : null
        ], 500);
    }
}

/**
 * Obtiene detalle de distribución de clases por horario
 */
private function getDetalleDistribucion($horarios, $clasesPorHorario, $clasesExtra)
{
    $detalle = [];
    
    foreach ($horarios as $index => $horarioId) {
        $clases = $clasesPorHorario;
        if ($index < $clasesExtra) {
            $clases += 1;
        }
        
        // Obtener nombre del horario
        $horario = DB::table('horarios')
            ->select('nombre', 'dia_semana')
            ->where('id', $horarioId)
            ->first();
        
        $detalle[] = [
            'horario_id' => $horarioId,
            'horario_nombre' => $horario->nombre ?? 'Sin nombre',
            'dia_semana' => $horario->dia_semana ?? 'Sin día',
            'clases_asignadas' => $clases
        ];
    }
    
    return $detalle;
}
      

// ========== FUNCIONES AUXILIARES ==========

/**
 * Distribuir clases entre horarios seleccionados
 */
private function calcularClasesPorHorario($clasesTotales, $cantidadHorarios)
{
    // Ejemplo: 12 clases, 3 horarios = 4 clases por horario
    // Ejemplo: 12 clases, 5 horarios = 2-3-2-3-2 (distribución inteligente)
    
    if ($cantidadHorarios <= 0) return 0;
    
    // Si es divisible exactamente
    if ($clasesTotales % $cantidadHorarios === 0) {
        return $clasesTotales / $cantidadHorarios;
    }
    
    // Distribución inteligente (para casos como 12 clases en 5 horarios)
    $base = floor($clasesTotales / $cantidadHorarios);
    $extra = $clasesTotales % $cantidadHorarios;
    
    // Los primeros $extra horarios tendrán una clase extra
    return $base; // En el frontend manejaremos la distribución exacta
}

/**
 * Verificar disponibilidad del estudiante
 */
private function verificarDisponibilidadEstudiante($estudianteId, $nuevosHorarios)
{
    $inscripcionesActivas = Inscripcion::where('estudiante_id', $estudianteId)
        ->where('estado', 'activo')
        ->where('fecha_fin', '>=', now())
        ->with(['horarios' => function($query) {
            $query->select('id', 'dia_semana', 'hora_inicio', 'hora_fin');
        }])
        ->get();
    
    foreach ($nuevosHorarios as $nuevo) {
        foreach ($inscripcionesActivas as $inscripcion) {
            foreach ($inscripcion->horarios as $existente) {
                // Mismo día
                if ($existente->dia_semana === $nuevo->dia_semana) {
                    // Convertir a minutos desde medianoche para comparación
                    $inicioExistente = $this->horaAMinutos($existente->hora_inicio);
                    $finExistente = $this->horaAMinutos($existente->hora_fin);
                    $inicioNuevo = $this->horaAMinutos($nuevo->hora_inicio);
                    $finNuevo = $this->horaAMinutos($nuevo->hora_fin);
                    
                    // Verificar solapamiento
                    if (($inicioNuevo >= $inicioExistente && $inicioNuevo < $finExistente) ||
                        ($finNuevo > $inicioExistente && $finNuevo <= $finExistente) ||
                        ($inicioNuevo <= $inicioExistente && $finNuevo >= $finExistente)) {
                        return false;
                    }
                }
            }
        }
    }
    
    return true;
}

/**
 * Convertir hora HH:MM:SS a minutos desde medianoche
 */
private function horaAMinutos($hora)
{
    $partes = explode(':', $hora);
    return ($partes[0] * 60) + ($partes[1] ?? 0);
}

/**
 * Crear seguimiento mensual por horario
 */
private function crearSeguimientoMensual($inscripcion, $horarios, $fechaInicio)
{
    $mes = $fechaInicio->month;
    $anio = $fechaInicio->year;
    
    foreach ($horarios as $horario) {
        // Calcular clases programadas para este mes
        // (dependiendo de cuántos días de ese horario hay en el mes)
        $clasesProgramadas = $this->calcularClasesEnMes($horario->dia_semana, $fechaInicio);
        
        DB::table('seguimiento_clases')->insert([
            'inscripcion_id' => $inscripcion->id,
            'horario_id' => $horario->id,
            'mes' => $mes,
            'anio' => $anio,
            'clases_programadas' => $clasesProgramadas,
            'clases_asistidas' => 0,
            'clases_faltadas' => 0,
            'clases_recuperadas' => 0,
            'estado' => 'pendiente',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
}

/**
 * Calcular cuántas clases hay en un mes para un día específico
 */
private function calcularClasesEnMes($diaSemana, $fechaInicio)
{
    $mes = $fechaInicio->month;
    $anio = $fechaInicio->year;
    
    // Mapear días en español a inglés
    $diasMap = [
        'Lunes' => 'Monday',
        'Martes' => 'Tuesday',
        'Miércoles' => 'Wednesday',
        'Jueves' => 'Thursday',
        'Viernes' => 'Friday',
        'Sábado' => 'Saturday',
        'Domingo' => 'Sunday'
    ];
    
    $diaIngles = $diasMap[$diaSemana] ?? $diaSemana;
    
    // Contar cuántos días de ese tipo hay en el mes
    $contador = 0;
    $diasEnMes = Carbon::create($anio, $mes)->daysInMonth;
    
    for ($dia = 1; $dia <= $diasEnMes; $dia++) {
        $fecha = Carbon::create($anio, $mes, $dia);
        if ($fecha->englishDayOfWeek === $diaIngles) {
            $contador++;
        }
    }
    
    return $contador;
}

/**
 * Registrar pago inicial
 */
private function registrarPagoInicial($inscripcion, $datosPago)
{
    \App\Models\Pago::create([
        'inscripcion_id' => $inscripcion->id,
        'monto' => $datosPago['monto'] ?? $inscripcion->monto_mensual,
        'metodo_pago' => $datosPago['metodo_pago'] ?? 'efectivo',
        'fecha_pago' => $datosPago['fecha_pago'] ?? now(),
        'estado' => 'pagado',
        'observacion' => $datosPago['observacion'] ?? 'Pago inicial de inscripción',
        'mes_cubierto' => Carbon::now()->month,
        'anio_cubierto' => Carbon::now()->year
    ]);
}

public function show($id)
{
    $inscripcion = Inscripcion::with([
        'estudiante', 
        'modalidad', 
        'sucursal',
        'entrenador',
        'horarios.disciplina', 
        'horarios.entrenador',
        'horarios.sucursal'
    ])->findOrFail($id);
    
    // Accede a los datos del pivot directamente
    $totalClasesAsistidas = 0;
    $totalClasesRestantes = 0;
    $totalPermisosUsados = 0;
    
    // Calcular estadísticas desde el pivot
    foreach ($inscripcion->horarios as $horario) {
        $totalClasesAsistidas += $horario->pivot->clases_asistidas ?? 0;
        $totalClasesRestantes += $horario->pivot->clases_restantes ?? 0;
        $totalPermisosUsados += $horario->pivot->permisos_usados ?? 0;
    }
    
    $inscripcion->estadisticas = [
        'clases_asistidas' => $totalClasesAsistidas,
        'clases_restantes' => $totalClasesRestantes,
        'permisos_usados' => $totalPermisosUsados,
        'porcentaje_asistencia' => $inscripcion->clases_totales > 0 
            ? round(($totalClasesAsistidas / $inscripcion->clases_totales) * 100, 2)
            : 0
    ];
    
    return response()->json([
        'success' => true,
        'data' => $inscripcion
    ]);
}

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        
        try {
            $inscripcion = Inscripcion::findOrFail($id);
            
            $request->validate([
                'estado' => 'sometimes|in:activo,suspendida,en_mora,vencida', // ← según tus enum
                'fecha_fin' => 'sometimes|date',
                'clases_asistidas' => 'sometimes|integer|min:0', // ← este sí existe
                'permisos_usados' => 'sometimes|integer|min:0',
                'horarios' => 'sometimes|array',
                'horarios.*' => 'exists:horarios,id'
            ]);
            
            // Actualizar solo campos que existen en `inscripciones`
            $camposPermitidos = [
                'estado', 'fecha_fin', 'clases_asistidas', 'permisos_usados'
            ];
            
            $datosActualizar = $request->only($camposPermitidos);
            $inscripcion->update($datosActualizar);
            
            // Si se envían horarios, actualizarlos
            if ($request->has('horarios')) {
                $this->actualizarHorarios($inscripcion, $request->horarios);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Inscripción actualizada exitosamente',
                'data' => $inscripcion->load(['estudiante', 'modalidad', 'horarios'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la inscripción: ' . $e->getMessage()
            ], 500);
        }
    }

    public function renovar($id, Request $request)
    {
        try {
            $inscripcion = Inscripcion::findOrFail($id);
            
            $request->validate([
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin' => 'sometimes|date|after:fecha_inicio',
                'motivo' => 'nullable|string'
            ]);
            
            // Actualizar fechas
            $fechaInicio = $request->has('fecha_inicio') 
                ? Carbon::parse($request->fecha_inicio)
                : now();
                
            $fechaFin = $request->has('fecha_fin')
                ? Carbon::parse($request->fecha_fin)
                : $fechaInicio->copy()->addMonth();
            
            // Actualizar inscripción principal
            $inscripcion->update([
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'clases_asistidas' => 0, // ← Reiniciar contadores
                'permisos_usados' => 0,
                'estado' => 'activo'
            ]);
            
            // Actualizar también los inscripcion_horarios
            $inscripcion->inscripcionHorarios()->update([
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'clases_asistidas' => 0,
                'clases_restantes' => DB::raw('clases_totales'), // ← Reiniciar clases restantes
                'permisos_usados' => 0,
                'estado' => 'activo'
            ]);
            
            // Cargar relaciones
            $inscripcion->load(['estudiante', 'modalidad']);
            
            return response()->json([
                'success' => true,
                'message' => 'Inscripción renovada exitosamente',
                'data' => $inscripcion
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al renovar inscripción: ' . $e->getMessage()
            ], 500);
        }
    }

    // ========== MÉTODOS PRIVADOS CORREGIDOS ==========

    private function asociarHorarios($inscripcion, $horariosIds, $modalidad)
    {
        $totalHorarios = count($horariosIds);
        if ($totalHorarios === 0) return;
        
        // Distribuir clases equitativamente entre horarios
        $clasesPorHorario = floor(($modalidad->clases_mensuales ?? 12) / $totalHorarios);
        
        foreach ($horariosIds as $horarioId) {
            $horario = Horario::findOrFail($horarioId);
            
            // Verificar cupo
            if ($horario->cupo_actual >= $horario->cupo_maximo) {
                throw new \Exception("El horario {$horario->nombre} no tiene cupo disponible");
            }
            
            // ========== Crear inscripcion_horario ==========
            InscripcionHorario::create([
                'inscripcion_id' => $inscripcion->id,
                'horario_id' => $horarioId,
                'clases_totales' => $clasesPorHorario,
                'clases_asistidas' => 0,
                'clases_restantes' => $clasesPorHorario, // ← ¡AQUÍ SÍ VA clases_restantes!
                'permisos_usados' => 0,
                'fecha_inicio' => $inscripcion->fecha_inicio,
                'fecha_fin' => $inscripcion->fecha_fin,
                'estado' => 'activo'
            ]);
            
            // Incrementar cupo del horario
            $horario->increment('cupo_actual');
        }
    }

    private function actualizarHorarios($inscripcion, $horariosIds)
    {
        // Obtener horarios actuales
        $horariosActuales = $inscripcion->horarios()->pluck('horarios.id')->toArray();
        
        // Horarios a eliminar
        $horariosAEliminar = array_diff($horariosActuales, $horariosIds);
        
        // Horarios a agregar
        $horariosAAgregar = array_diff($horariosIds, $horariosActuales);
        
        // Eliminar horarios
        foreach ($horariosAEliminar as $horarioId) {
            $this->desasociarHorario($inscripcion->id, $horarioId);
        }
        
        // Agregar nuevos horarios
        if (count($horariosAAgregar) > 0) {
            $modalidad = $inscripcion->modalidad;
            $this->asociarHorarios($inscripcion, $horariosAAgregar, $modalidad);
        }
    }

    private function recalcularDistribucionClases($inscripcion)
    {
        $totalHorarios = $inscripcion->horarios()->count();
        
        if ($totalHorarios === 0) return;
        
        // Calcular nuevas clases por horario
        $clasesPorHorario = floor($inscripcion->clases_totales / $totalHorarios);
        
        foreach ($inscripcion->inscripcionHorarios as $inscripcionHorario) {
            // Mantener las clases asistidas, ajustar el resto
            $clasesAsistidas = $inscripcionHorario->clases_asistidas;
            $nuevasClasesTotales = $clasesPorHorario;
            $nuevasClasesRestantes = max(0, $nuevasClasesTotales - $clasesAsistidas);
            
            $inscripcionHorario->update([
                'clases_totales' => $nuevasClasesTotales,
                'clases_restantes' => $nuevasClasesRestantes // ← CORREGIDO
            ]);
        }
    }

    // Métodos auxiliares nuevos
    private function calcularClasesRestantes($inscripcion)
    {
        // Sumar clases restantes de todos los horarios
        return $inscripcion->inscripcionHorarios->sum('clases_restantes');
    }

    // En tu InscripcionController.php
public function inscripcionActiva($estudianteId)
{
    try {
        // Buscar la última inscripción activa
        $inscripcion = Inscripcion::where('estudiante_id', $estudianteId)
            ->where('estado', 'activo')
            ->orderBy('created_at', 'desc') // La más reciente
            ->first();
        
        if (!$inscripcion) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró inscripción activa para este estudiante'
            ], 404);
        }
        
        // Cargar relaciones si las necesitas
        $inscripcion->load(['estudiante', 'modalidad', 'sucursal', 'entrenador']);
        
        return response()->json([
            'success' => true,
            'data' => $inscripcion
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error interno del servidor',
            'error' => $e->getMessage()
        ], 500);
    }
}
    
    private function calcularDiasRestantes($fechaFin)
    {
        if (!$fechaFin) return 0;
        
        $hoy = Carbon::now();
        $fin = Carbon::parse($fechaFin);
        
        return $hoy->diffInDays($fin, false); // negativo si ya pasó
    }

    // Método para registrar asistencia (agregar al controlador)
    public function registrarAsistencia($inscripcionId, $horarioId)
    {
        DB::beginTransaction();
        
        try {
            $inscripcionHorario = InscripcionHorario::where('inscripcion_id', $inscripcionId)
                ->where('horario_id', $horarioId)
                ->firstOrFail();
            
            // Verificar si hay clases disponibles
            if ($inscripcionHorario->clases_restantes <= 0) {
                throw new \Exception('No hay clases disponibles en este horario');
            }
            
            // Actualizar inscripcion_horario
            $inscripcionHorario->increment('clases_asistidas');
            $inscripcionHorario->decrement('clases_restantes');
            
            // Actualizar inscripción principal (sumar totales)
            $inscripcion = $inscripcionHorario->inscripcion;
            $inscripcion->increment('clases_asistidas');
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Asistencia registrada exitosamente'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar asistencia: ' . $e->getMessage()
            ], 500);
        }
    }
}