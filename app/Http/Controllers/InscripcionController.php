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
use Illuminate\Support\Facades\Log;

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
            
            // ========== AÑADIR ESTO (igual que en show()) ==========
            $totalClasesAsistidas = 0;
            $totalClasesRestantes = 0;
            $totalPermisosUsados = 0;
            
            // Calcular estadísticas desde inscripcion_horarios
            foreach ($inscripcion->inscripcionHorarios as $inscripcionHorario) {
                $totalClasesAsistidas += $inscripcionHorario->clases_asistidas ?? 0;
                $totalClasesRestantes += $inscripcionHorario->clases_restantes ?? 0;
                $totalPermisosUsados += $inscripcionHorario->permisos_usados ?? 0;
            }
            
            // Crear objeto estadísticas (opcional, pero consistente con show())
            $inscripcion->estadisticas = [
                'clases_asistidas' => $totalClasesAsistidas,
                'clases_restantes' => $totalClasesRestantes,
                'permisos_usados' => $totalPermisosUsados,
                'porcentaje_asistencia' => $inscripcion->clases_totales > 0 
                    ? round(($totalClasesAsistidas / $inscripcion->clases_totales) * 100, 2)
                    : 0
            ];
            
            // También agregar el porcentaje directamente al objeto principal
            // para que Vue pueda acceder a inscripcion.porcentaje_asistencia
            $inscripcion->porcentaje_asistencia = $inscripcion->estadisticas['porcentaje_asistencia'];
        }
        
        return response()->json([
            'success' => true,
            'data' => $inscripciones
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
        DB::beginTransaction();
        
        // ========== VALIDACIONES BÁSICAS ==========
        $request->validate([
            'estudiante_id' => 'required|exists:estudiantes,id',
            'modalidad_id' => 'required|exists:modalidades,id',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date',
            'horarios' => 'required|array',
            'distribucion_horarios' => 'required|array', // ← CAMBIADO A REQUERIDO
            'distribucion_horarios.*.horario_id' => 'required|exists:horarios,id',
            'distribucion_horarios.*.clases_totales' => 'required|integer|min:1',
        ]);
        
        \Log::info('🔄 Iniciando creación de inscripción con distribución REAL', [
            'estudiante_id' => $request->estudiante_id,
            'modalidad_id' => $request->modalidad_id,
            'fecha_inicio' => $request->fecha_inicio,
            'fecha_fin' => $request->fecha_fin,
            'horarios_count' => count($request->horarios),
            'distribucion_count' => count($request->distribucion_horarios)
        ]);
        
        // ========== VALIDAR QUE DISTRIBUCIÓN COINCIDA CON HORARIOS ==========
        \Log::info('🔍 Validando distribución recibida:', $request->distribucion_horarios);
        
        $horariosDistribucion = collect($request->distribucion_horarios)->pluck('horario_id')->toArray();
        $horariosRequest = $request->horarios;
        
        sort($horariosDistribucion);
        sort($horariosRequest);
        
        \Log::info('📊 Horarios distribución:', $horariosDistribucion);
        \Log::info('📊 Horarios request:', $horariosRequest);
        
        if ($horariosDistribucion != $horariosRequest) {
            \Log::error('❌ Los horarios en la distribución no coinciden');
            return response()->json([
                'success' => false,
                'message' => 'Los horarios en la distribución no coinciden con los horarios seleccionados'
            ], 422);
        }
        
        // ========== CALCULAR CLASES TOTALES REALES ==========
        // ¡USAR LA SUMA DIRECTA DE LA DISTRIBUCIÓN!
        $clasesTotalesReales = collect($request->distribucion_horarios)->sum('clases_totales');
        
        \Log::info("📊 Clases totales REALES calculadas desde distribución: {$clasesTotalesReales}");
        
        // ========== VERIFICAR QUE SEA AL MENOS 1 ==========
        if ($clasesTotalesReales < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Debe haber al menos 1 clase por horario'
            ], 422);
        }
        
        // ========== OBTENER INFORMACIÓN DE LA MODALIDAD ==========
        $modalidad = DB::table('modalidades')
            ->where('id', $request->modalidad_id)
            ->first();
        
        if (!$modalidad) {
            return response()->json([
                'success' => false,
                'message' => 'La modalidad seleccionada no existe'
            ], 404);
        }
        
        $permisosMaximos = $modalidad->permisos_maximos ?? 3;
        $precioMensual = $modalidad->precio_mensual ?? 0;
        $montoMensual = $request->monto_mensual ?? $precioMensual;
        
        // ========== CALCULAR FECHAS ==========
        $fechaInicio = Carbon::parse($request->fecha_inicio);
        $fechaFin = Carbon::parse($request->fecha_fin);
        
        if ($fechaFin <= $fechaInicio) {
            return response()->json([
                'success' => false,
                'message' => 'La fecha de fin debe ser posterior a la fecha de inicio'
            ], 422);
        }
        
        $mesesDuracion = $fechaInicio->floatDiffInMonths($fechaFin);
        \Log::info("📅 Período: {$fechaInicio->format('Y-m-d')} al {$fechaFin->format('Y-m-d')} ({$mesesDuracion} meses)");
        
        // ========== DETERMINAR ESTADO ==========
        $estadoFinal = $request->estado ?? 'activo';
        if ($request->es_pago_parcial ?? false) {
            $estadoFinal = 'en_mora';
        }
        
        // ========== CREAR LA INSCRIPCIÓN ==========
        $inscripcionId = DB::table('inscripciones')->insertGetId([
            'estudiante_id' => $request->estudiante_id,
            'modalidad_id' => $request->modalidad_id,
            'sucursal_id' => $request->sucursal_id ?? null,
            'entrenador_id' => $request->entrenador_id ?? null,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'clases_totales' => $clasesTotalesReales, // ← ¡20 debería venir del frontend!
            'clases_asistidas' => 0,
            'permisos_usados' => 0,
            'permisos_disponibles' => $permisosMaximos,
            'monto_mensual' => $montoMensual,
            'estado' => $estadoFinal,
            'observaciones' => $request->observaciones,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        \Log::info("✅ Inscripción creada con ID: {$inscripcionId}, Clases: {$clasesTotalesReales}");
        
        // ========== DISTRIBUIR CLASES ENTRE HORARIOS ==========
        $totalClasesGeneradas = 0;
        
        foreach ($request->distribucion_horarios as $distribucion) {
            $horarioId = $distribucion['horario_id'];
            $clasesParaEsteHorario = $distribucion['clases_totales'];
            
            \Log::info("📅 Procesando horario ID {$horarioId}: {$clasesParaEsteHorario} clases");
            
            // Obtener información del horario
            $horario = DB::table('horarios')
                ->where('id', $horarioId)
                ->first();
            
            if (!$horario) {
                \Log::warning("⚠️ Horario ID {$horarioId} no encontrado");
                continue;
            }
            
            // Crear inscripcion_horario
            $inscripcionHorarioId = DB::table('inscripcion_horarios')->insertGetId([
                'inscripcion_id' => $inscripcionId,
                'horario_id' => $horarioId,
                'clases_totales' => $clasesParaEsteHorario,
                'clases_asistidas' => 0,
                'clases_restantes' => $clasesParaEsteHorario,
                'permisos_usados' => 0,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'estado' => 'activo',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            // Generar clases programadas
            $clasesGeneradasParaEsteHorario = $this->generarClasesParaHorario(
                $inscripcionId,
                $inscripcionHorarioId,
                $horario,
                $request->estudiante_id,
                $fechaInicio->format('Y-m-d'),
                $fechaFin->format('Y-m-d'),
                $clasesParaEsteHorario
            );
            
            $totalClasesGeneradas += $clasesGeneradasParaEsteHorario;
            
            // Actualizar cupo del horario
            DB::table('horarios')
                ->where('id', $horarioId)
                ->increment('cupo_actual');
        }
        
        DB::commit();
        
        \Log::info("🎉 Inscripción #{$inscripcionId} completada", [
            'clases_totales_registradas' => $clasesTotalesReales,
            'clases_generadas' => $totalClasesGeneradas
        ]);
        
        return response()->json([
            'success' => true,
            'inscripcion_id' => $inscripcionId,
            'message' => 'Inscripción creada exitosamente con clases REALES',
            'data' => [
                'clases_totales' => $clasesTotalesReales,
                'clases_generadas' => $totalClasesGeneradas
            ]
        ]);
        
    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('❌ Error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ], 500);
    }
}

// En App\Http\Controllers\InscripcionController.php
public function incrementarAsistencia($id, Request $request)
{
    \Log::info("🔔 Método incrementarAsistencia llamado para inscripción #{$id}");
    \Log::info("📦 Datos recibidos:", $request->all());
    
    try {
        $request->validate([
            'estudiante_id' => 'required|integer',
            'fecha' => 'required|date',
            'horario_id' => 'required|integer',
            'clase_programada_id' => 'nullable|integer'
        ]);

        $inscripcion = Inscripcion::find($id);
        
        if (!$inscripcion) {
            \Log::error("❌ Inscripción #{$id} no encontrada");
            return response()->json([
                'success' => false,
                'message' => 'Inscripción no encontrada'
            ], 404);
        }

        \Log::info("📊 Estado actual de inscripción #{$id}:", [
            'clases_asistidas' => $inscripcion->clases_asistidas,
            'clases_totales' => $inscripcion->clases_totales,
            'estado' => $inscripcion->estado
        ]);

        // INCREMENTAR clases_asistidas
        $nuevasClasesAsistidas = $inscripcion->clases_asistidas + 1;
        
        $inscripcion->clases_asistidas = $nuevasClasesAsistidas;
        $inscripcion->save();

        \Log::info("✅ Inscripción #{$id} actualizada exitosamente");
        \Log::info("📈 Nuevas clases asistidas: {$inscripcion->clases_asistidas}");

        return response()->json([
            'success' => true,
            'message' => 'Asistencia registrada en inscripción',
            'data' => [
                'inscripcion_id' => $inscripcion->id,
                'clases_asistidas' => $inscripcion->clases_asistidas,
                'clases_totales' => $inscripcion->clases_totales,
                'permisos_disponibles' => $inscripcion->permisos_disponibles,
                'clases_restantes' => $inscripcion->clases_totales - $inscripcion->clases_asistidas,
                'estado' => $inscripcion->estado
            ]
        ]);

    } catch (\Exception $e) {
        \Log::error('💥 Error en incrementarAsistencia: ' . $e->getMessage());
        \Log::error('💥 Stack trace: ' . $e->getTraceAsString());
        
        return response()->json([
            'success' => false,
            'message' => 'Error al registrar asistencia en inscripción',
            'error' => $e->getMessage()
        ], 500);
    }
}

public function estadisticasInscripcion($id)
{
    $inscripcion = Inscripcion::find($id);
    
    if (!$inscripcion) {
        return response()->json([
            'success' => false,
            'message' => 'Inscripción no encontrada'
        ], 404);
    }

    // Calcular días transcurridos
    $fechaInicio = \Carbon\Carbon::parse($inscripcion->fecha_inicio);
    $fechaFin = \Carbon\Carbon::parse($inscripcion->fecha_fin);
    $hoy = \Carbon\Carbon::now();
    
    $diasTotales = $fechaInicio->diffInDays($fechaFin) + 1;
    $diasTranscurridos = min($fechaInicio->diffInDays($hoy) + 1, $diasTotales);
    $diasRestantes = max($diasTotales - $diasTranscurridos, 0);

    return response()->json([
        'success' => true,
        'data' => [
            'inscripcion_id' => $inscripcion->id,
            'clases_asistidas' => $inscripcion->clases_asistidas,
            'clases_totales' => $inscripcion->clases_totales,
            'clases_restantes' => $inscripcion->clases_totales - $inscripcion->clases_asistidas,
            'permisos_disponibles' => $inscripcion->permisos_disponibles,
            'permisos_usados' => $inscripcion->permisos_usados,
            'fecha_inicio' => $inscripcion->fecha_inicio,
            'fecha_fin' => $inscripcion->fecha_fin,
            'dias_totales' => $diasTotales,
            'dias_transcurridos' => $diasTranscurridos,
            'dias_restantes' => $diasRestantes,
            'estado' => $inscripcion->estado,
            'progreso_clases' => $inscripcion->clases_totales > 0 
                ? round(($inscripcion->clases_asistidas / $inscripcion->clases_totales) * 100, 1)
                : 0
        ]
    ]);
}



/**
 * Función optimizada para generar clases programadas para un horario específico
 */
private function generarClasesParaHorario($inscripcionId, $inscripcionHorarioId, $horario, $estudianteId, $fechaInicio, $fechaFin, $clasesAGenerar)
{
    \Log::info("📅 Generando clases para horario {$horario->id} ({$horario->dia_semana})");
    \Log::info("  Período: {$fechaInicio} al {$fechaFin}");
    \Log::info("  Clases a generar: {$clasesAGenerar}");
    
    // MAPEO CORREGIDO (Carbon usa 1-7, 7=Domingo)
    $diasMap = [
        'lunes' => 1, 'martes' => 2, 'miércoles' => 3, 'miercoles' => 3,
        'jueves' => 4, 'viernes' => 5, 'sábado' => 6, 'sabado' => 6,
        'domingo' => 7  // ¡IMPORTANTE! 7 no 0
    ];
    
    $diaHorario = strtolower(trim($horario->dia_semana));
    $diaNumero = $diasMap[$diaHorario] ?? 1;
    
    // Calcular días coincidentes
    $inicio = Carbon::parse($fechaInicio);
    $fin = Carbon::parse($fechaFin);
    
    $diasCoincidentes = [];
    $fechaActual = $inicio->copy();
    
    while ($fechaActual <= $fin) { // <= para incluir fecha final
        if ($fechaActual->dayOfWeek == $diaNumero) {
            $diasCoincidentes[] = $fechaActual->format('Y-m-d');
        }
        $fechaActual->addDay();
    }
    
    \Log::info("  Días coincidentes encontrados: " . count($diasCoincidentes));
    
    // Limitar a las clases solicitadas
    $diasAGenerar = array_slice($diasCoincidentes, 0, $clasesAGenerar);
    
    // Generar clases
    $clasesGeneradas = 0;
    foreach ($diasAGenerar as $fecha) {
        try {
            DB::table('clases_programadas')->insert([
                'inscripcion_id' => $inscripcionId,
                'inscripcion_horario_id' => $inscripcionHorarioId,
                'estudiante_id' => $estudianteId,
                'horario_id' => $horario->id,
                'fecha' => $fecha,
                'hora_inicio' => $horario->hora_inicio,
                'hora_fin' => $horario->hora_fin,
                'estado_clase' => 'programada',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            $clasesGeneradas++;
        } catch (\Exception $e) {
            \Log::error("Error generando clase: " . $e->getMessage());
        }
    }
    
    \Log::info("  Clases generadas: {$clasesGeneradas}");
    return $clasesGeneradas;
}
// Función mejorada para generar clases


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
        'horarios.sucursal',
        'inscripcionHorarios.horario' // ¡IMPORTANTE! Cargar la relación horario
    ])->findOrFail($id);
    
    // Accede a los datos de inscripcion_horarios
    $totalClasesAsistidas = 0;
    $totalClasesRestantes = 0;
    $totalPermisosUsados = 0;
    
    // Calcular estadísticas desde inscripcion_horarios
    foreach ($inscripcion->inscripcionHorarios as $inscripcionHorario) {
        $totalClasesAsistidas += $inscripcionHorario->clases_asistidas ?? 0;
        $totalClasesRestantes += $inscripcionHorario->clases_restantes ?? 0;
        $totalPermisosUsados += $inscripcionHorario->permisos_usados ?? 0;
    }
    
    $inscripcion->estadisticas = [
        'clases_asistidas' => $totalClasesAsistidas,
        'clases_restantes' => $totalClasesRestantes,
        'permisos_usados' => $totalPermisosUsados,
        'porcentaje_asistencia' => $inscripcion->clases_totales > 0 
            ? round(($totalClasesAsistidas / $inscripcion->clases_totales) * 100, 2)
            : 0
    ];
    
    // También agregar el porcentaje directamente
    $inscripcion->porcentaje_asistencia = $inscripcion->estadisticas['porcentaje_asistencia'];
    
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
    DB::beginTransaction();
    
    try {
        // 1. Obtener inscripción actual con todas las relaciones
        $inscripcionActual = Inscripcion::with([
            'estudiante',
            'modalidad',
            'inscripcionHorarios.horario',
            'horarios'
        ])->findOrFail($id);
        
        // 2. Validar que la inscripción esté activa
        if ($inscripcionActual->estado !== 'activo') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden renovar inscripciones activas'
            ], 400);
        }
        
        // 3. Validar datos de entrada CON TODOS LOS CAMPOS NECESARIOS
        $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after:fecha_inicio',
            'motivo' => 'nullable|string|max:500',
            'metodo_pago' => 'required|in:efectivo,tarjeta,transferencia,qr',
            'monto_pago' => 'required|numeric|min:0',
            
            // CAMPOS DE DESCUENTO (NUEVOS)
            'descuento_porcentaje' => 'nullable|numeric|min:0|max:100',
            'descuento_monto' => 'nullable|numeric|min:0',
            'subtotal' => 'required|numeric|min:0',
            'total_final' => 'required|numeric|min:0'
        ]);
        
        // 4. Calcular fechas
        $fechaInicio = Carbon::parse($request->fecha_inicio);
        $fechaFin = Carbon::parse($request->fecha_fin);
        
        // 5. Crear NUEVA inscripción (NUEVO registro en la tabla)
        $nuevaInscripcion = Inscripcion::create([
            'estudiante_id' => $inscripcionActual->estudiante_id,
            'modalidad_id' => $inscripcionActual->modalidad_id,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            
            // Clases se calcularán después
            'clases_totales' => 0,
            'clases_asistidas' => 0,
            'clases_restantes' => 0,
            
            'permisos_usados' => 0,
            'permisos_disponibles' => $inscripcionActual->modalidad->permisos_maximos ?? 3,
            'monto_mensual' => $inscripcionActual->monto_mensual ?? $inscripcionActual->modalidad->precio_mensual,
            'estado' => 'activo',
            'observaciones' => 'Renovación de inscripción #' . $inscripcionActual->id . 
                             ($request->motivo ? '. Motivo: ' . $request->motivo : ''),
            
            // Mantener misma sucursal y entrenador
            'sucursal_id' => $inscripcionActual->sucursal_id,
            'entrenador_id' => $inscripcionActual->entrenador_id,
            
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        Log::info("✅ NUEVA inscripción creada #{$nuevaInscripcion->id} como renovación de #{$id}");
        
        // 6. Copiar horarios de la inscripción anterior
        $totalClasesGeneradas = 0;
        
        foreach ($inscripcionActual->inscripcionHorarios as $inscripcionHorario) {
            // Calcular cuántas clases corresponden para este período
            $clasesParaEsteHorario = $this->calcularClasesParaHorarioRenovacion(
                $fechaInicio,
                $fechaFin,
                $inscripcionHorario->horario
            );
            
            // Verificar que tenga al menos 1 clase
            if ($clasesParaEsteHorario < 1) {
                throw new \Exception("El horario {$inscripcionHorario->horario->nombre} no tiene clases en el período seleccionado. Por favor, extienda la fecha de fin.");
            }
            
            // Crear NUEVO inscripcion_horario para la NUEVA inscripción
            $nuevoInscripcionHorario = InscripcionHorario::create([
                'inscripcion_id' => $nuevaInscripcion->id,
                'horario_id' => $inscripcionHorario->horario_id,
                'clases_totales' => $clasesParaEsteHorario,
                'clases_asistidas' => 0,
                'clases_restantes' => $clasesParaEsteHorario,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'permisos_usados' => 0,
                'estado' => 'activo',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            Log::info("📅 Horario copiado: {$inscripcionHorario->horario->nombre} - {$clasesParaEsteHorario} clases");
            
            // 7. GENERAR NUEVAS CLASES PROGRAMADAS para la NUEVA inscripción
            $clasesGeneradasParaEsteHorario = $this->generarClasesParaHorarioRenovacion(
                $nuevaInscripcion->id,
                $nuevoInscripcionHorario->id,
                $inscripcionHorario->horario,
                $inscripcionActual->estudiante_id,
                $fechaInicio->format('Y-m-d'),
                $fechaFin->format('Y-m-d')
            );
            
            $totalClasesGeneradas += $clasesGeneradasParaEsteHorario;
            
            // 8. Incrementar cupo del horario (porque es un nuevo estudiante en el horario)
            DB::table('horarios')
                ->where('id', $inscripcionHorario->horario_id)
                ->increment('cupo_actual');
        }
        
        // 9. Actualizar totales de la nueva inscripción
        $nuevaInscripcion->update([
            'clases_totales' => $totalClasesGeneradas,
            'clases_restantes' => $totalClasesGeneradas
        ]);
        
        // 10. Registrar NUEVO PAGO CON TODOS LOS CAMPOS NECESARIOS
        $montoPago = $request->monto_pago ?? 
                    ($inscripcionActual->monto_mensual ?? $inscripcionActual->modalidad->precio_mensual);
        
        // Calcular subtotal y total si no vienen en la request
        $subtotal = $request->subtotal ?? $montoPago;
        $totalFinal = $request->total_final ?? $montoPago;
        
        // Asegurar que no sean null
        $descuentoPorcentaje = $request->descuento_porcentaje ?? 0;
        $descuentoMonto = $request->descuento_monto ?? 0;
        
        // Crear la observación con toda la información
        $observacion = 'Pago por renovación de inscripción #' . $inscripcionActual->id . 
                      ' a nueva inscripción #' . $nuevaInscripcion->id . 
                      '. Período: ' . $fechaInicio->format('d/m/Y') . ' - ' . $fechaFin->format('d/m/Y');
        
        // Agregar información de descuento si aplica
        if ($descuentoPorcentaje > 0 || $descuentoMonto > 0) {
            $observacion .= '. Descuento: ';
            
            if ($descuentoPorcentaje > 0) {
                $observacion .= $descuentoPorcentaje . '%';
            }
            
            if ($descuentoMonto > 0) {
                $observacion .= ($descuentoPorcentaje > 0 ? ' ($' : '$') . $descuentoMonto . ')';
            }
        }
        
        // Agregar motivo si existe
        if ($request->motivo) {
            $observacion .= '. Motivo: ' . $request->motivo;
        }
        
        // Crear el pago con TODOS los campos
        $pago = \App\Models\Pago::create([
            'inscripcion_id' => $nuevaInscripcion->id,
            'estudiante_id' => $inscripcionActual->estudiante_id,
            
            // MONTO PRINCIPAL
            'monto' => $montoPago,
            
            // CAMPOS DE DESCUENTO (COMPLETOS)
            'descuento_porcentaje' => $descuentoPorcentaje,
            'descuento_monto' => $descuentoMonto,
            'subtotal' => $subtotal,
            'total_final' => $totalFinal,
            
            // INFORMACIÓN DEL PAGO
            'metodo_pago' => $request->metodo_pago ?? 'efectivo',
            'fecha_pago' => now(),
            'estado' => 'pagado',
            'observacion' => $observacion,
            'es_parcial' => 0,
            'numero_cuota' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        Log::info("💰 Nuevo pago registrado #{$pago->id}", [
            'monto' => $montoPago,
            'descuento_porcentaje' => $descuentoPorcentaje,
            'descuento_monto' => $descuentoMonto,
            'subtotal' => $subtotal,
            'total_final' => $totalFinal
        ]);
        
        // 11. Actualizar inscripción anterior (marcar como renovada)
        $inscripcionActual->update([
            'estado' => 'renovado',
            'fecha_fin' => $fechaInicio->copy()->subDay(), // Termina un día antes de la nueva
            'observaciones' => 'Renovada el ' . now()->format('d/m/Y') . 
                             '. Nueva inscripción: #' . $nuevaInscripcion->id
        ]);
        
        // 12. Cargar relaciones para la respuesta
        $nuevaInscripcion->load(['estudiante', 'modalidad', 'inscripcionHorarios.horario']);
        
        DB::commit();
        
        Log::info("🎉 Renovación COMPLETADA: Antigua #{$id} → Nueva #{$nuevaInscripcion->id}");
        
        return response()->json([
            'success' => true,
            'message' => 'Inscripción renovada exitosamente con nueva inscripción',
            'data' => [
                'inscripcion_anterior_id' => $inscripcionActual->id,
                'nueva_inscripcion_id' => $nuevaInscripcion->id,
                'nueva_inscripcion' => $nuevaInscripcion,
                'pago_id' => $pago->id,
                'clases_generadas' => $totalClasesGeneradas,
                'monto_pagado' => $montoPago,
                'metodo_pago' => $pago->metodo_pago,
                'descuento_aplicado' => [
                    'porcentaje' => $descuentoPorcentaje,
                    'monto' => $descuentoMonto
                ],
                'subtotal' => $subtotal,
                'total_final' => $totalFinal,
                'nuevo_periodo' => $fechaInicio->format('Y-m-d') . ' al ' . $fechaFin->format('Y-m-d')
            ]
        ]);
        
    } catch (\Exception $e) {
        DB::rollBack();
        
        Log::error('❌ Error al renovar inscripción: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Error al renovar inscripción: ' . $e->getMessage(),
            'error_details' => env('APP_DEBUG') ? [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ] : null
        ], 500);
    }
}

/**
 * Función específica para generar clases en renovaciones
 */
private function generarClasesParaHorarioRenovacion($inscripcionId, $inscripcionHorarioId, $horario, $estudianteId, $fechaInicio, $fechaFin)
{
    Log::info("📅 Generando clases para renovación - Horario: {$horario->nombre} ({$horario->dia_semana})");
    Log::info("  Período: {$fechaInicio} al {$fechaFin}");
    
    // MAPEO CORREGIDO para Carbon
    $diasMap = [
        'lunes' => 1, 
        'martes' => 2, 
        'miércoles' => 3, 'miercoles' => 3,
        'jueves' => 4, 
        'viernes' => 5, 
        'sábado' => 6, 'sabado' => 6,
        'domingo' => 7  // Carbon usa 7 para domingo
    ];
    
    $diaHorario = strtolower(trim($horario->dia_semana));
    $diaNumero = $diasMap[$diaHorario] ?? 1;
    
    // Calcular días coincidentes
    $inicio = Carbon::parse($fechaInicio);
    $fin = Carbon::parse($fechaFin);
    
    $clasesGeneradas = 0;
    $fechaActual = $inicio->copy();
    
    // Incluir fecha final
    while ($fechaActual <= $fin) {
        if ($fechaActual->dayOfWeek == $diaNumero) {
            try {
                DB::table('clases_programadas')->insert([
                    'inscripcion_id' => $inscripcionId,
                    'inscripcion_horario_id' => $inscripcionHorarioId,
                    'estudiante_id' => $estudianteId,
                    'horario_id' => $horario->id,
                    'fecha' => $fechaActual->format('Y-m-d'),
                    'hora_inicio' => $horario->hora_inicio,
                    'hora_fin' => $horario->hora_fin,
                    'estado_clase' => 'programada',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                $clasesGeneradas++;
                
                Log::debug("  ✓ Clase generada: {$fechaActual->format('Y-m-d')} {$horario->hora_inicio}");
            } catch (\Exception $e) {
                Log::error("  ✗ Error generando clase: " . $e->getMessage());
            }
        }
        $fechaActual->addDay();
    }
    
    Log::info("  Total clases generadas: {$clasesGeneradas}");
    return $clasesGeneradas;
}

// Agrega esta función auxiliar para calcular clases por horario
private function calcularClasesParaHorarioRenovacion($fechaInicio, $fechaFin, $horario)
{
    // ¡CORRECCIÓN IMPORTANTE! Carbon usa: 1=Lunes, 2=Martes, 3=Miércoles, 4=Jueves, 5=Viernes, 6=Sábado, 7=Domingo
    $diasSemana = [
        'lunes' => 1, 
        'martes' => 2, 
        'miércoles' => 3, 'miercoles' => 3,
        'jueves' => 4, 
        'viernes' => 5, 
        'sábado' => 6, 'sabado' => 6,
        'domingo' => 7  // <-- ¡CORREGIDO! 7 en lugar de 0
    ];
    
    $diaHorario = strtolower($horario->dia_semana);
    $diaNumero = $diasSemana[$diaHorario] ?? 1;
    
    $contador = 0;
    $fechaActual = $fechaInicio->copy();
    
    // ¡IMPORTANTE! Usar <= para INCLUIR la fecha final
    while ($fechaActual <= $fechaFin) {
        if ($fechaActual->dayOfWeek == $diaNumero) {
            $contador++;
        }
        $fechaActual->addDay();
    }
    
    Log::info("📅 Calculando clases para {$horario->nombre} ({$horario->dia_semana}): {$contador} clases en período {$fechaInicio->format('Y-m-d')} al {$fechaFin->format('Y-m-d')}");
    
    return $contador;
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

   // En InscripcionController.php
public function generarClasesProgramadas($inscripcionId, Request $request)
{
    try {
        $inscripcion = Inscripcion::findOrFail($inscripcionId);
        $horarios = $inscripcion->horarios;
        
        $fechaInicio = Carbon::parse($request->fecha_inicio);
        $fechaFin = Carbon::parse($request->fecha_fin);
        
        $clasesGeneradas = [];
        
        // Para cada horario en la inscripción
        foreach ($horarios as $horario) {
            $fechaActual = $fechaInicio->copy();
            
            // Encontrar todos los días que coinciden con este horario
            while ($fechaActual <= $fechaFin) {
                // Convertir día de la semana (ej: "lunes" => 1)
                $diasSemana = [
                    'lunes' => 1, 'martes' => 2, 'miércoles' => 3,
                    'jueves' => 4, 'viernes' => 5, 'sábado' => 6, 'domingo' => 7
                ];
                
                $diaHorario = strtolower($horario->dia_semana);
                $diaNumero = $diasSemana[$diaHorario] ?? 1;
                
                // Si el día coincide con el horario
                if ($fechaActual->dayOfWeek == $diaNumero) {
                    $claseProgramada = ClaseProgramada::create([
                        'inscripcion_horario_id' => $inscripcion->inscripcionHorarios()
                            ->where('horario_id', $horario->id)->first()->id,
                        'horario_id' => $horario->id,
                        'inscripcion_id' => $inscripcion->id,
                        'estudiante_id' => $inscripcion->estudiante_id,
                        'fecha' => $fechaActual->format('Y-m-d'),
                        'hora_inicio' => $horario->hora_inicio,
                        'hora_fin' => $horario->hora_fin,
                        'estado_clase' => 'programada',
                        'es_recuperacion' => false,
                        'cuenta_para_asistencia' => true,
                        'observaciones' => null
                    ]);
                    
                    $clasesGeneradas[] = $claseProgramada;
                }
                
                $fechaActual->addDay();
            }
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Clases programadas generadas exitosamente',
            'total_clases' => count($clasesGeneradas),
            'clases' => $clasesGeneradas
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error generando clases: ' . $e->getMessage()
        ], 500);
    }
}

// En InscripcionController.php
public function estadoFinanciero($id)
{
    try {
        $inscripcion = Inscripcion::with(['pagos' => function($query) {
            $query->orderBy('created_at', 'desc');
        }])->findOrFail($id);
        
        // Calcular total pagado
        $totalPagado = $inscripcion->pagos
            ->where('estado', 'pagado')
            ->sum('monto');
        
        // Identificar pagos pendientes
        $pagosPendientes = $inscripcion->pagos
            ->whereIn('estado', ['pendiente', 'vencido'])
            ->values();
        
        // Buscar primera y segunda cuota
        $primeraCuota = $inscripcion->pagos
            ->where('es_parcial', true)
            ->where('numero_cuota', 1)
            ->where('estado', 'pagado')
            ->first();
            
        $segundaCuotaPendiente = $inscripcion->pagos
            ->where('es_parcial', true)
            ->where('numero_cuota', 2)
            ->where('estado', 'pendiente')
            ->first();
        
        $montoTotal = $inscripcion->monto_mensual;
        $saldoPendiente = max(0, $montoTotal - $totalPagado);
        
        return response()->json([
            'success' => true,
            'data' => [
                'inscripcion_id' => $inscripcion->id,
                'total_inscripcion' => (float) $montoTotal,
                'total_pagado' => (float) $totalPagado,
                'saldo_pendiente' => (float) $saldoPendiente,
                'pagos_pendientes' => $pagosPendientes,
                'primera_cuota' => $primeraCuota,
                'segunda_cuota_pendiente' => $segundaCuotaPendiente,
                'estado_actual' => $inscripcion->estado
            ]
        ]);
        
    } catch (\Exception $e) {
        Log::error('Error obteniendo estado financiero: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Error obteniendo estado financiero',
            'error' => env('APP_DEBUG') ? $e->getMessage() : null
        ], 500);
    }
}


  public function registrarAsistencia($id, Request $request)
{
    \Log::info("🔔 Método registrarAsistencia llamado para inscripción #{$id}");
    \Log::info("📦 Datos recibidos:", $request->all());
    
    // AÑADIR ESTO ↓
    DB::beginTransaction();
    
    try {
        $request->validate([
            'estudiante_id' => 'required|integer',
            'fecha' => 'required|date',
            'horario_id' => 'required|integer',
            'clase_programada_id' => 'nullable|integer',
            'estado' => 'required|in:asistio,falto,permiso'
        ]);

        $inscripcion = Inscripcion::find($id);
        
        if (!$inscripcion) {
            \Log::error("❌ Inscripción #{$id} no encontrada");
            DB::rollBack(); // ← AÑADIR
            return response()->json([
                'success' => false,
                'message' => 'Inscripción no encontrada'
            ], 404);
        }

        \Log::info("📊 Estado actual de inscripción #{$id}:", [
            'clases_asistidas' => $inscripcion->clases_asistidas,
            'clases_totales' => $inscripcion->clases_totales,
            'estado' => $inscripcion->estado
        ]);

        $datosActualizados = [];

        // 1. Actualizar inscripción principal SOLO si es asistencia
        if ($request->estado === 'asistio') {
            // INCREMENTAR clases_asistidas
            $inscripcion->clases_asistidas = $inscripcion->clases_asistidas + 1;
            $datosActualizados['clases_asistidas'] = $inscripcion->clases_asistidas;
            
            \Log::info("📈 Incrementando asistencia: {$inscripcion->clases_asistidas}");
        }
        
        // 2. Si es justificación, actualizar permisos
        if ($request->estado === 'permiso') {
            if ($inscripcion->permisos_disponibles > 0) {
                $inscripcion->permisos_usados = $inscripcion->permisos_usados + 1;
                $inscripcion->permisos_disponibles = $inscripcion->permisos_disponibles - 1;
                $datosActualizados['permisos_usados'] = $inscripcion->permisos_usados;
                $datosActualizados['permisos_disponibles'] = $inscripcion->permisos_disponibles;
                
                \Log::info("📝 Registrando permiso - Usados: {$inscripcion->permisos_usados}, Disponibles: {$inscripcion->permisos_disponibles}");
            } else {
                \Log::warning("⚠️ No hay permisos disponibles para inscripción #{$id}");
                DB::rollBack(); // ← AÑADIR
                return response()->json([
                    'success' => false,
                    'message' => 'No hay permisos disponibles'
                ], 400);
            }
        }
        
        // Guardar cambios en la inscripción
        if (!empty($datosActualizados)) {
            $inscripcion->save();
        }

        // 3. Actualizar inscripcion_horarios si existe
        $inscripcionHorario = InscripcionHorario::where('inscripcion_id', $id)
            ->where('horario_id', $request->horario_id)
            ->first();
        
        if ($inscripcionHorario) {
            \Log::info("📊 Estado actual de inscripcion_horario:", [
                'id' => $inscripcionHorario->id,
                'clases_asistidas' => $inscripcionHorario->clases_asistidas,
                'clases_totales' => $inscripcionHorario->clases_totales,
                'clases_restantes' => $inscripcionHorario->clases_restantes
            ]);
            
            if ($request->estado === 'asistio') {
                // Incrementar asistencia en el horario específico
                $inscripcionHorario->clases_asistidas = $inscripcionHorario->clases_asistidas + 1;
                $inscripcionHorario->clases_restantes = max(0, $inscripcionHorario->clases_totales - $inscripcionHorario->clases_asistidas);
                
                \Log::info("✅ Inscripcion_horario actualizado - Asistencias: {$inscripcionHorario->clases_asistidas}, Restantes: {$inscripcionHorario->clases_restantes}");
                
                $inscripcionHorario->save();
            }
        } else {
            \Log::warning("⚠️ No se encontró inscripcion_horario para inscripción #{$id}, horario #{$request->horario_id}");
            // No es crítico, continuar
        }

        // AÑADIR ESTO ↓
        DB::commit();

        \Log::info("✅ Inscripción #{$id} actualizada exitosamente");

        // 4. Verificar si quedan pocas clases para notificación
        $clasesRestantes = $inscripcion->clases_totales - $inscripcion->clases_asistidas;
        if ($request->estado === 'asistio' && $clasesRestantes <= 3 && $clasesRestantes > 0) {
            \Log::info("🔔 Notificación: Quedan {$clasesRestantes} clases");
            // Aquí podrías llamar a un servicio de notificaciones
        }
        
        // 5. Verificar si se completaron todas las clases
        if ($request->estado === 'asistio' && $clasesRestantes <= 0) {
            $inscripcion->estado = 'completada';
            $inscripcion->save();
            \Log::info("🎉 Inscripción #{$id} completada - Todas las clases asistidas");
        }

        return response()->json([
            'success' => true,
            'message' => 'Asistencia registrada exitosamente',
            'data' => [
                'inscripcion_id' => $inscripcion->id,
                'clases_asistidas' => $inscripcion->clases_asistidas,
                'clases_totales' => $inscripcion->clases_totales,
                'clases_restantes' => $clasesRestantes,
                'permisos_disponibles' => $inscripcion->permisos_disponibles,
                'permisos_usados' => $inscripcion->permisos_usados,
                'estado' => $inscripcion->estado
            ]
        ]);

    } catch (\Exception $e) {
        // AÑADIR ESTO ↓
        DB::rollBack();
        
        \Log::error('💥 Error en registrarAsistencia: ' . $e->getMessage());
        \Log::error('💥 Stack trace: ' . $e->getTraceAsString());
        
        return response()->json([
            'success' => false,
            'message' => 'Error al registrar asistencia',
            'error' => $e->getMessage()
        ], 500);
    }
}

// Método para obtener horarios de una inscripción
public function getHorarios($id)
{
    try {
        $inscripcion = Inscripcion::findOrFail($id);
        
        $horarios = $inscripcion->inscripcionHorarios()
            ->with(['horario' => function($query) {
                $query->select('id', 'nombre', 'dia_semana', 'hora_inicio', 'hora_fin');
            }])
            ->get()
            ->map(function($inscripcionHorario) {
                return [
                    'id' => $inscripcionHorario->id,
                    'horario_id' => $inscripcionHorario->horario_id,
                    'clases_totales' => $inscripcionHorario->clases_totales,
                    'clases_asistidas' => $inscripcionHorario->clases_asistidas,
                    'clases_restantes' => $inscripcionHorario->clases_restantes,
                    'horario' => $inscripcionHorario->horario
                ];
            });
        
        return response()->json([
            'success' => true,
            'data' => $horarios
        ]);
        
    } catch (\Exception $e) {
        \Log::error('Error obteniendo horarios de inscripción: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener horarios'
        ], 500);
    }
}

// Método para actualizar un inscripcion_horario específico
public function actualizarHorarioEspecifico($inscripcionId, $horarioId, Request $request)
{
    try {
        $inscripcionHorario = InscripcionHorario::where('inscripcion_id', $inscripcionId)
            ->where('horario_id', $horarioId)
            ->firstOrFail();
        
        $request->validate([
            'clases_asistidas' => 'sometimes|integer|min:0',
            'clases_restantes' => 'sometimes|integer|min:0'
        ]);
        
        if ($request->has('clases_asistidas')) {
            $inscripcionHorario->clases_asistidas = $request->clases_asistidas;
        }
        
        if ($request->has('clases_restantes')) {
            $inscripcionHorario->clases_restantes = $request->clases_restantes;
        }
        
        $inscripcionHorario->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Horario actualizado exitosamente',
            'data' => $inscripcionHorario
        ]);
        
    } catch (\Exception $e) {
        \Log::error('Error actualizando horario específico: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error al actualizar horario'
        ], 500);
    }
}
// app/Http/Controllers/InscripcionController.php

/**
 * Obtener solo preinscripciones (estado = preinscripcion)
 */
public function preinscripciones(Request $request)
{
    try {
        $query = Inscripcion::with([
            'estudiante', 
            'modalidad', 
            'sucursal'
        ])->where('estado', 'preinscripcion') // 👈 FILTRO CLAVE
        ->latest(); // Ordenar por fecha descendente
        
        // Filtro por búsqueda (nombre o CI)
        if ($request->has('buscar') && $request->buscar) {
            $search = $request->buscar;
            $query->whereHas('estudiante', function($q) use ($search) {
                $q->where('nombres', 'LIKE', "%{$search}%")
                  ->orWhere('apellidos', 'LIKE', "%{$search}%")
                  ->orWhere('ci', 'LIKE', "%{$search}%");
            });
        }
        
        // Filtro por modalidad
        if ($request->has('modalidad_id') && $request->modalidad_id) {
            $query->where('modalidad_id', $request->modalidad_id);
        }
        
        // Filtro por sucursal
        if ($request->has('sucursal_id') && $request->sucursal_id) {
            $query->where('sucursal_id', $request->sucursal_id);
        }
        
        // Filtro por fechas
        if ($request->has('fecha_desde') && $request->fecha_desde) {
            $query->whereDate('created_at', '>=', $request->fecha_desde);
        }
        
        if ($request->has('fecha_hasta') && $request->fecha_hasta) {
            $query->whereDate('created_at', '<=', $request->fecha_hasta);
        }
        
        // Paginación
        $perPage = $request->get('per_page', 15);
        $preinscripciones = $query->paginate($perPage);
        
        return response()->json([
            'success' => true,
            'data' => $preinscripciones,
            'message' => 'Preinscripciones obtenidas correctamente'
        ]);
        
    } catch (\Exception $e) {
        Log::error('Error al obtener preinscripciones: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Error al cargar preinscripciones',
            'error' => $e->getMessage()
        ], 500);
    }
}



public function aprobarPreinscripcion($id, Request $request)
{
    DB::beginTransaction();
    
    try {
        // 1. Buscar la preinscripción con sus relaciones
        $inscripcion = Inscripcion::with([
            'estudiante', 
            'modalidad',
            'inscripcionHorarios' => function($q) {
                $q->where('estado', 'reservado'); // Solo horarios reservados
            }
        ])->where('estado', 'preinscripcion')->findOrFail($id);
        
        // 2. Validar datos
        $request->validate([
            'metodo_pago' => 'required|in:efectivo,qr,tarjeta,transferencia',
            'monto_pagado' => 'required|numeric|min:0',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after:fecha_inicio',
            'entrenador_id' => 'required|exists:users,id',
            'observacion_pago' => 'nullable|string',
            'horarios_confirmados' => 'sometimes|array',
            'horarios_confirmados.*' => 'exists:horarios,id'
        ]);

        // 3. OBTENER HORARIOS DESDE LA TABLA PIVOT
        $horariosReservados = $inscripcion->inscripcionHorarios->pluck('horario_id')->toArray();
        
        // 4. Determinar horarios a confirmar
        $horariosAConfirmar = $request->horarios_confirmados ?? $horariosReservados;
        
        if (empty($horariosAConfirmar)) {
            throw new \Exception('No hay horarios reservados para confirmar');
        }

        \Log::info('✅ Horarios a confirmar:', $horariosAConfirmar);

        // 5. ACTUALIZAR INSCRIPCIÓN PRINCIPAL
        $clasesMensuales = $inscripcion->modalidad->clases_mensuales ?? 12;
        $precioMensual = $inscripcion->modalidad->precio_mensual ?? 0;
        $permisosMaximos = $inscripcion->modalidad->permisos_maximos ?? 3;
        
        $inscripcion->update([
            'estado' => 'activo',
            'fecha_inicio' => $request->fecha_inicio,
            'fecha_fin' => $request->fecha_fin,
            'entrenador_id' => $request->entrenador_id,
            'clases_totales' => $clasesMensuales,
            'permisos_disponibles' => $permisosMaximos,
            'monto_mensual' => $precioMensual
        ]);

        // 6. CREAR PAGO
        $pago = \App\Models\Pago::create([
            'inscripcion_id' => $inscripcion->id,
            'estudiante_id' => $inscripcion->estudiante_id,
            'monto' => $request->monto_pagado,
            'metodo_pago' => $request->metodo_pago,
            'fecha_pago' => now(),
            'estado' => 'pagado',
            'observacion' => $request->observacion_pago ?? 'Pago de inscripción',
            'referencia' => 'CONF-' . $inscripcion->id . '-' . time()
        ]);

        $totalClasesActualizadas = 0;
        $horariosProcesados = [];

        // 7. PROCESAR CADA INSCRIPCION_HORARIO
        foreach ($inscripcion->inscripcionHorarios as $inscripcionHorario) {
            // Verificar si este horario está en la lista de confirmados
            if (in_array($inscripcionHorario->horario_id, $horariosAConfirmar)) {
                // Calcular distribución de clases
                $totalHorarios = count($horariosAConfirmar);
                $clasesPorHorario = floor($clasesMensuales / $totalHorarios);
                $horariosConExtra = $clasesMensuales % $totalHorarios;
                
                $indiceHorario = array_search($inscripcionHorario->horario_id, $horariosAConfirmar);
                $clasesParaEsteHorario = $clasesPorHorario + ($indiceHorario < $horariosConExtra ? 1 : 0);
                
                // Actualizar a ACTIVO
                $inscripcionHorario->update([
                    'estado' => 'activo',
                    'fecha_inicio' => $request->fecha_inicio,
                    'fecha_fin' => $request->fecha_fin,
                    'clases_totales' => $clasesParaEsteHorario,
                    'clases_restantes' => $clasesParaEsteHorario
                ]);
                
                $horariosProcesados[] = [
                    'id' => $inscripcionHorario->id,
                    'horario_id' => $inscripcionHorario->horario_id,
                    'clases' => $clasesParaEsteHorario
                ];
                
                // 8. ACTUALIZAR CLASES PROGRAMADAS
                $clasesProgramadas = \App\Models\ClaseProgramada::where('inscripcion_horario_id', $inscripcionHorario->id)
                    ->where('estado_clase', 'reservada')
                    ->get();
                
                $clasesEnPeriodo = 0;
                
                foreach ($clasesProgramadas as $clase) {
                    $fechaClase = \Carbon\Carbon::parse($clase->fecha);
                    
                    if ($fechaClase->between($request->fecha_inicio, $request->fecha_fin)) {
                        $clase->update([
                            'estado_clase' => 'programada',
                            'observaciones' => 'Clase programada'
                        ]);
                        $clasesEnPeriodo++;
                        $totalClasesActualizadas++;
                    } else {
                        $clase->update([
                            'estado_clase' => 'cancelada',
                            'observaciones' => 'Fuera de período'
                        ]);
                    }
                }
                
                // 9. GENERAR CLASES FALTANTES
                if ($clasesEnPeriodo < $clasesParaEsteHorario) {
                    $clasesFaltantes = $clasesParaEsteHorario - $clasesEnPeriodo;
                    $this->generarClasesFaltantes(
                        $inscripcion->id,
                        $inscripcionHorario->id,
                        $inscripcionHorario->horario_id,
                        $inscripcion->estudiante_id,
                        $request->fecha_inicio,
                        $request->fecha_fin,
                        $clasesFaltantes
                    );
                    $totalClasesActualizadas += $clasesFaltantes;
                }
                
            } else {
                // Cancelar horarios no confirmados
                $inscripcionHorario->update(['estado' => 'cancelado']);
                
                \App\Models\ClaseProgramada::where('inscripcion_horario_id', $inscripcionHorario->id)
                    ->where('estado_clase', 'reservada')
                    ->update([
                        'estado_clase' => 'cancelada',
                        'observaciones' => 'Horario no confirmado'
                    ]);
            }
        }

        // 10. Actualizar estudiante
        $inscripcion->estudiante->update(['estado' => 'activo']);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => '✅ Inscripción confirmada exitosamente',
            'data' => [
                'inscripcion_id' => $inscripcion->id,
                'estudiante' => $inscripcion->estudiante->nombres . ' ' . $inscripcion->estudiante->apellidos,
                'horarios_activos' => count($horariosAConfirmar),
                'clases_programadas' => $totalClasesActualizadas,
                'pago' => $pago->monto,
                'detalle' => $horariosProcesados
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('❌ Error:', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'line' => $e->getLine()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Error al confirmar: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Generar clases faltantes
 */
private function generarClasesFaltantes($inscripcionId, $inscripcionHorarioId, $horarioId, $estudianteId, $fechaInicio, $fechaFin, $cantidad)
{
    $horario = \App\Models\Horario::find($horarioId);
    if (!$horario) return 0;
    
    $diasMap = [
        'lunes' => 1, 'martes' => 2, 'miércoles' => 3, 'miercoles' => 3,
        'jueves' => 4, 'viernes' => 5, 'sábado' => 6, 'sabado' => 6, 'domingo' => 0
    ];
    
    $diaHorario = $diasMap[strtolower($horario->dia_semana)] ?? -1;
    if ($diaHorario === -1) return 0;
    
    $fechaActual = \Carbon\Carbon::parse($fechaInicio);
    $fechaFinDate = \Carbon\Carbon::parse($fechaFin);
    $clasesGeneradas = 0;
    
    while ($fechaActual <= $fechaFinDate && $clasesGeneradas < $cantidad) {
        if ($fechaActual->dayOfWeek == $diaHorario) {
            // Verificar que no exista ya
            $existe = \App\Models\ClaseProgramada::where('inscripcion_horario_id', $inscripcionHorarioId)
                ->where('fecha', $fechaActual->format('Y-m-d'))
                ->exists();
            
            if (!$existe) {
                \App\Models\ClaseProgramada::create([
                    'inscripcion_horario_id' => $inscripcionHorarioId,
                    'horario_id' => $horarioId,
                    'inscripcion_id' => $inscripcionId,
                    'estudiante_id' => $estudianteId,
                    'fecha' => $fechaActual->format('Y-m-d'),
                    'hora_inicio' => $horario->hora_inicio,
                    'hora_fin' => $horario->hora_fin,
                    'estado_clase' => 'programada',
                    'es_recuperacion' => false,
                    'cuenta_para_asistencia' => true
                ]);
                $clasesGeneradas++;
            }
        }
        $fechaActual->addDay();
    }
    
    return $clasesGeneradas;
}







}