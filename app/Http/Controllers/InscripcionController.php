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
        DB::beginTransaction();
        
        // ========== VALIDACIONES BÁSICAS ==========
        $request->validate([
            'estudiante_id' => 'required|exists:estudiantes,id',
            'modalidad_id' => 'required|exists:modalidades,id',
            'fecha_inicio' => 'required|date',
            'horarios' => 'required|array',
            'distribucion_horarios' => 'sometimes|array',
            'distribucion_horarios.*.horario_id' => 'required|exists:horarios,id',
            'distribucion_horarios.*.clases_totales' => 'required|integer|min:1'
        ]);
        
        \Log::info('🔄 Iniciando creación de inscripción', [
            'estudiante_id' => $request->estudiante_id,
            'modalidad_id' => $request->modalidad_id,
            'horarios_count' => count($request->horarios)
        ]);
        
        // ========== VALIDACIÓN 1: Verificar inscripción activa en misma modalidad ==========
        $inscripcionActivaExistente = DB::table('inscripciones')
            ->where('estudiante_id', $request->estudiante_id)
            ->where('modalidad_id', $request->modalidad_id)
            ->where('estado', 'activo')
            ->first();
        
        if ($inscripcionActivaExistente) {
            return response()->json([
                'success' => false,
                'message' => 'El estudiante ya tiene una inscripción activa en esta modalidad',
                'inscripcion_existente_id' => $inscripcionActivaExistente->id
            ], 409);
        }
        
        // ========== VALIDACIÓN 2: Verificar conflictos de horarios ==========
        $conflictosHorarios = [];
        foreach ($request->horarios as $horarioId) {
            $horarioExistente = DB::table('inscripcion_horarios as ih')
                ->join('inscripciones as i', 'ih.inscripcion_id', '=', 'i.id')
                ->where('i.estudiante_id', $request->estudiante_id)
                ->where('i.estado', 'activo')
                ->where('ih.horario_id', $horarioId)
                ->select('ih.id', 'i.id as inscripcion_id')
                ->first();
            
            if ($horarioExistente) {
                $horarioInfo = DB::table('horarios')
                    ->where('id', $horarioId)
                    ->select('dia_semana', 'hora_inicio', 'hora_fin', 'nombre')
                    ->first();
                
                $conflictosHorarios[] = [
                    'horario_id' => $horarioId,
                    'dia_semana' => $horarioInfo->dia_semana ?? '',
                    'hora_inicio' => $horarioInfo->hora_inicio ?? '',
                    'hora_fin' => $horarioInfo->hora_fin ?? '',
                    'nombre_horario' => $horarioInfo->nombre ?? '',
                    'inscripcion_existente_id' => $horarioExistente->inscripcion_id
                ];
            }
        }
        
        if (!empty($conflictosHorarios)) {
            return response()->json([
                'success' => false,
                'message' => 'El estudiante ya está inscrito en algunos de los horarios seleccionados',
                'conflictos' => $conflictosHorarios
            ], 409);
        }
        
        // ========== VALIDACIÓN 3: Verificar cupo disponible ==========
        $horariosSinCupo = [];
        foreach ($request->horarios as $horarioId) {
            $horario = DB::table('horarios')
                ->where('id', $horarioId)
                ->select('id', 'cupo_maximo', 'cupo_actual', 'dia_semana', 'hora_inicio', 'nombre')
                ->first();
            
            if (!$horario) {
                return response()->json([
                    'success' => false,
                    'message' => "El horario ID {$horarioId} no existe"
                ], 404);
            }
            
            if ($horario->cupo_actual >= $horario->cupo_maximo) {
                $horariosSinCupo[] = [
                    'horario_id' => $horario->id,
                    'dia_semana' => $horario->dia_semana,
                    'hora_inicio' => $horario->hora_inicio,
                    'nombre_horario' => $horario->nombre,
                    'cupo_actual' => $horario->cupo_actual,
                    'cupo_maximo' => $horario->cupo_maximo
                ];
            }
        }
        
        if (!empty($horariosSinCupo)) {
            return response()->json([
                'success' => false,
                'message' => 'Algunos horarios seleccionados ya están llenos',
                'horarios_llenos' => $horariosSinCupo
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
        
        $clasesMensuales = $modalidad->clases_mensuales ?? 12;
        $permisosMaximos = $modalidad->permisos_maximos ?? 3;
        $precioMensual = $modalidad->precio_mensual ?? 0;
        $montoMensual = $request->monto_mensual ?? $precioMensual;
        
        // ========== OBTENER SUCURSAL Y ENTRENADOR ==========
        if (!$request->has('sucursal_id') || !$request->has('entrenador_id')) {
            $primerHorario = DB::table('horarios')
                ->where('id', $request->horarios[0])
                ->select('sucursal_id', 'entrenador_id')
                ->first();
            
            $sucursalId = $request->sucursal_id ?? ($primerHorario->sucursal_id ?? null);
            $entrenadorId = $request->entrenador_id ?? ($primerHorario->entrenador_id ?? null);
        } else {
            $sucursalId = $request->sucursal_id;
            $entrenadorId = $request->entrenador_id;
        }
        
        // ========== CALCULAR FECHAS Y DURACIÓN ==========
        $fechaInicio = Carbon::parse($request->fecha_inicio);
        $fechaFin = $request->fecha_fin 
            ? Carbon::parse($request->fecha_fin)
            : $fechaInicio->copy()->addMonth();
        
        if ($fechaFin <= $fechaInicio) {
            return response()->json([
                'success' => false,
                'message' => 'La fecha de fin debe ser posterior a la fecha de inicio'
            ], 422);
        }
        
        $mesesDuracion = $fechaInicio->floatDiffInMonths($fechaFin);
        \Log::info("📅 Período: {$fechaInicio->format('Y-m-d')} al {$fechaFin->format('Y-m-d')} ({$mesesDuracion} meses)");
        
        // ========== CALCULAR CLASES TOTALES REALES ==========
        $clasesTotalesReales = 0;
        
        if ($request->has('distribucion_horarios') && is_array($request->distribucion_horarios)) {
            \Log::info('📥 Distribución recibida desde frontend:', $request->distribucion_horarios);
            
            // Verificar que coincidan los IDs de horarios
            $horariosDistribucion = collect($request->distribucion_horarios)->pluck('horario_id')->toArray();
            $horariosRequest = $request->horarios;
            
            sort($horariosDistribucion);
            sort($horariosRequest);
            
            if ($horariosDistribucion != $horariosRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Los horarios en la distribución no coinciden con los horarios seleccionados'
                ], 422);
            }
            
            // Sumar clases totales REALES de la distribución
            $clasesTotalesReales = collect($request->distribucion_horarios)->sum('clases_totales');
            \Log::info("📊 Clases totales REALES calculadas: {$clasesTotalesReales}");
        } else {
            $clasesTotalesReales = ceil($clasesMensuales * max(1, $mesesDuracion));
            \Log::info("📊 Clases totales por modalidad: {$clasesTotalesReales}");
        }
        
        $clasesTotalesReales = max(1, $clasesTotalesReales);
        
        // ========== CREAR LA INSCRIPCIÓN ==========
        $inscripcionId = DB::table('inscripciones')->insertGetId([
            'estudiante_id' => $request->estudiante_id,
            'modalidad_id' => $request->modalidad_id,
            'sucursal_id' => $sucursalId,
            'entrenador_id' => $entrenadorId,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'clases_totales' => $clasesTotalesReales,
            'clases_asistidas' => 0,
            'permisos_usados' => 0,
            'permisos_disponibles' => $permisosMaximos,
            'monto_mensual' => $montoMensual,
            'estado' => 'activo',
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        \Log::info("✅ Inscripción creada con ID: {$inscripcionId}");
        
        // ========== DISTRIBUIR CLASES ENTRE HORARIOS ==========
        $totalClasesGeneradas = 0;
        $inscripcionHorariosIds = [];
        
        // Si tenemos distribución del frontend, usar esa (MODO AVANZADO)
        if ($request->has('distribucion_horarios') && is_array($request->distribucion_horarios)) {
            \Log::info('🎯 Usando distribución avanzada desde frontend');
            
            foreach ($request->distribucion_horarios as $distribucion) {
                $horarioId = $distribucion['horario_id'];
                $clasesParaEsteHorario = $distribucion['clases_totales'];
                
                // Obtener información del horario
                $horario = DB::table('horarios')
                    ->where('id', $horarioId)
                    ->select('id', 'dia_semana', 'hora_inicio', 'hora_fin', 'nombre', 'cupo_maximo', 'cupo_actual')
                    ->first();
                
                if (!$horario) {
                    \Log::warning("⚠️ Horario ID {$horarioId} no encontrado");
                    continue;
                }
                
                // 1. CREAR INSCRIPCION_HORARIO
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
                
                $inscripcionHorariosIds[$horarioId] = $inscripcionHorarioId;
                
                // 2. GENERAR CLASES PROGRAMADAS usando la función optimizada
                \Log::info("📅 Procesando horario: {$horario->nombre} ({$horario->dia_semana}) - {$clasesParaEsteHorario} clases");
                
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
                
                // 3. ACTUALIZAR CUPO DEL HORARIO
                DB::table('horarios')
                    ->where('id', $horarioId)
                    ->increment('cupo_actual');
                
                \Log::info("🎯 Total generado para horario {$horario->nombre}: {$clasesGeneradasParaEsteHorario}/{$clasesParaEsteHorario}");
            }
        } else {
            // MODO COMPATIBILIDAD: Distribución equitativa
            \Log::info('🔄 Usando distribución equitativa (modo compatibilidad)');
            
            $totalHorarios = count($request->horarios);
            $clasesPorHorario = floor($clasesTotalesReales / $totalHorarios);
            $clasesExtra = $clasesTotalesReales % $totalHorarios;
            
            foreach ($request->horarios as $index => $horarioId) {
                $clasesParaEsteHorario = $clasesPorHorario;
                if ($index < $clasesExtra) {
                    $clasesParaEsteHorario += 1;
                }
                
                // Obtener información del horario
                $horario = DB::table('horarios')
                    ->where('id', $horarioId)
                    ->select('id', 'dia_semana', 'hora_inicio', 'hora_fin', 'nombre', 'cupo_maximo', 'cupo_actual')
                    ->first();
                
                if (!$horario) {
                    \Log::warning("⚠️ Horario ID {$horarioId} no encontrado");
                    continue;
                }
                
                // 1. CREAR INSCRIPCION_HORARIO
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
                
                // 2. GENERAR CLASES PROGRAMADAS usando la función optimizada
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
                
                // 3. ACTUALIZAR CUPO DEL HORARIO
                DB::table('horarios')
                    ->where('id', $horarioId)
                    ->increment('cupo_actual');
                
                \Log::info("📊 Horario {$horario->nombre}: {$clasesGeneradasParaEsteHorario} clases generadas");
            }
        }
        
        // ========== OBTENER INFORMACIÓN COMPLETA PARA LA RESPUESTA ==========
        $inscripcionCreada = DB::table('inscripciones')
            ->where('id', $inscripcionId)
            ->first();
        
        $horariosAsignados = DB::table('inscripcion_horarios as ih')
            ->join('horarios as h', 'ih.horario_id', '=', 'h.id')
            ->where('ih.inscripcion_id', $inscripcionId)
            ->select(
                'h.id',
                'h.nombre',
                'h.dia_semana', 
                'h.hora_inicio',
                'h.hora_fin',
                'ih.clases_totales',
                'ih.clases_asistidas',
                'ih.clases_restantes'
            )
            ->get();
        
        DB::commit();
        
        \Log::info("🎉 Inscripción #{$inscripcionId} completada exitosamente", [
            'clases_totales' => $clasesTotalesReales,
            'clases_generadas' => $totalClasesGeneradas,
            'horarios_asignados' => $horariosAsignados->count()
        ]);
        
        // ========== RESPUESTA EXITOSA ==========
        return response()->json([
            'success' => true,
            'inscripcion_id' => $inscripcionId,
            'message' => 'Inscripción creada exitosamente con clases REALES programadas',
            'data' => [
                'inscripcion' => $inscripcionCreada,
                'horarios' => $horariosAsignados,
                'clases_totales_reales' => $clasesTotalesReales,
                'clases_generadas' => $totalClasesGeneradas,
                'clases_modalidad' => $clasesMensuales,
                'meses_duracion' => round($mesesDuracion, 2),
                'distribucion_por_horario' => $request->distribucion_horarios ?? null
            ]
        ]);
        
    } catch (\Exception $e) {
        DB::rollBack();
        
        // Revertir incrementos de cupo si hubo error
        if (isset($request->horarios) && is_array($request->horarios)) {
            foreach ($request->horarios as $horarioId) {
                try {
                    DB::table('horarios')
                        ->where('id', $horarioId)
                        ->where('cupo_actual', '>', 0)
                        ->decrement('cupo_actual');
                } catch (\Exception $e2) {
                    \Log::warning("No se pudo revertir cupo para horario {$horarioId}: " . $e2->getMessage());
                }
            }
        }
        
        \Log::error('❌ Error al crear inscripción: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'request' => $request->all()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Error al crear la inscripción: ' . $e->getMessage(),
            'error_details' => env('APP_DEBUG') ? [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ] : null
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
    $diasMap = [
        'lunes' => 1, 'martes' => 2, 'miércoles' => 3,
        'jueves' => 4, 'viernes' => 5, 'sábado' => 6, 'sabado' => 6,
        'domingo' => 0
    ];
    
    $diaHorario = strtolower($horario->dia_semana);
    $diaNumero = $diasMap[$diaHorario] ?? 1;
    
    $fechaActual = Carbon::parse($fechaInicio);
    $fechaFinObj = Carbon::parse($fechaFin);
    
    $clasesGeneradas = 0;
    
    \Log::info("📅 Generando {$clasesAGenerar} clases para {$horario->nombre} ({$horario->dia_semana})");
    \Log::info("  Período: {$fechaActual->format('Y-m-d')} al {$fechaFinObj->format('Y-m-d')}");
    \Log::info("  Día PHP a buscar: {$diaNumero} (0=domingo, 1=lunes, etc.)");
    
    // Primero: recolectar todos los días que coinciden
    $diasDisponibles = [];
    
    while ($fechaActual <= $fechaFinObj) {
        if ($fechaActual->dayOfWeek == $diaNumero) {
            $diasDisponibles[] = $fechaActual->format('Y-m-d');
        }
        $fechaActual->addDay();
    }
    
    \Log::info("  Días disponibles que coinciden: " . count($diasDisponibles));
    
    // Si no hay suficientes días, usar todos los disponibles
    if (count($diasDisponibles) < $clasesAGenerar) {
        \Log::warning("⚠️ No hay suficientes días en el período. Disponibles: " . count($diasDisponibles) . ", Necesarios: {$clasesAGenerar}");
        $clasesAGenerar = count($diasDisponibles);
    }
    
    // Tomar solo los primeros N días según las clases a generar
    $diasAGenerar = array_slice($diasDisponibles, 0, $clasesAGenerar);
    
    \Log::info("  Días seleccionados para generar: " . implode(', ', $diasAGenerar));
    
    // Generar clases para esos días
    foreach ($diasAGenerar as $fechaStr) {
        try {
            DB::table('clases_programadas')->insert([
                'inscripcion_id' => $inscripcionId,
                'inscripcion_horario_id' => $inscripcionHorarioId,
                'estudiante_id' => $estudianteId,
                'horario_id' => $horario->id,
                'fecha' => $fechaStr,
                'hora_inicio' => $horario->hora_inicio,
                'hora_fin' => $horario->hora_fin,
                'estado_clase' => 'programada',
                'cuenta_para_asistencia' => true,
                'es_recuperacion' => false,
                'observaciones' => 'Generada automáticamente',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            $clasesGeneradas++;
            \Log::info("    ✅ {$fechaStr} - {$horario->hora_inicio}");
        } catch (\Exception $e) {
            \Log::warning("    ⚠️ Error generando clase para {$fechaStr}: " . $e->getMessage());
        }
    }
    
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
                    'jueves' => 4, 'viernes' => 5, 'sábado' => 6, 'domingo' => 0
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
}