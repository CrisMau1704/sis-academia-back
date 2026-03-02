<?php
// app/Http/Controllers/PreinscripcionController.php

namespace App\Http\Controllers;

use App\Models\Estudiante;
use App\Models\Inscripcion;
use App\Models\InscripcionHorario;
use App\Models\ClaseProgramada;
use App\Models\Pago;
use App\Models\Horario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PreinscripcionController extends Controller
{


public function store(Request $request)
{
    Log::info('📝 Preinscripción recibida:', $request->all());

    // ✅ VALIDAR TAMBIÉN LAS FECHAS
    $validator = Validator::make($request->all(), [
        'nombres' => 'required|string|max:255',
        'apellidos' => 'required|string|max:255',
        'ci' => 'required|string|max:20|unique:estudiantes,ci',
        'telefono' => 'required|string|max:20',
        'correo' => 'required|email|max:255|unique:estudiantes,correo',
        'sucursal_id' => 'required|exists:sucursales,id',
        'modalidad_id' => 'required|exists:modalidades,id',
        'fecha_inicio' => 'required|date',
        'fecha_fin' => 'required|date|after:fecha_inicio',
        'horarios' => 'required|array|min:1',
        'horarios.*' => 'exists:horarios,id',
        'observaciones' => 'nullable|string'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        DB::beginTransaction();

        // 1. Crear estudiante
        $estudiante = Estudiante::create([
            'nombres' => $request->nombres,
            'apellidos' => $request->apellidos,
            'ci' => $request->ci,
            'telefono' => $request->telefono,
            'correo' => $request->correo,
            'estado' => 'preinscrito'
        ]);

        // 2. Obtener modalidad
        $modalidad = \App\Models\Modalidad::find($request->modalidad_id);
        if (!$modalidad) {
            throw new \Exception('Modalidad no encontrada');
        }

        // 3. CALCULAR DISTRIBUCIÓN DE CLASES (IGUAL QUE EN INSCRIPCIONES)
        $fechaInicio = \Carbon\Carbon::parse($request->fecha_inicio);
        $fechaFin = \Carbon\Carbon::parse($request->fecha_fin);
        
        // Mapeo de días
        $diasMap = [
            'lunes' => 1, 'martes' => 2, 'miércoles' => 3, 'miercoles' => 3,
            'jueves' => 4, 'viernes' => 5, 'sábado' => 6, 'sabado' => 6, 'domingo' => 0
        ];

        $distribucionClases = [];
        $totalClases = 0;
        $horariosDetalle = [];

        foreach ($request->horarios as $horarioId) {
            $horario = Horario::with(['entrenador', 'sucursal'])->find($horarioId);
            if (!$horario) continue;

            // Calcular clases en el período
            $diaHorario = $diasMap[strtolower($horario->dia_semana)] ?? -1;
            $clasesEnPeriodo = 0;
            
            if ($diaHorario !== -1) {
                $fechaActual = clone $fechaInicio;
                while ($fechaActual <= $fechaFin) {
                    if ($fechaActual->dayOfWeek == $diaHorario) {
                        $clasesEnPeriodo++;
                    }
                    $fechaActual->addDay();
                }
            }
            
            // Guardar distribución
            $distribucionClases[] = [
                'horario_id' => $horarioId,
                'clases_totales' => $clasesEnPeriodo
            ];
            
            $totalClases += $clasesEnPeriodo;
            
            $horariosDetalle[] = [
                'id' => $horario->id,
                'dia_semana' => $horario->dia_semana,
                'hora_inicio' => $horario->hora_inicio,
                'hora_fin' => $horario->hora_fin,
                'entrenador' => $horario->entrenador->nombres ?? 'Sin entrenador',
                'sucursal' => $horario->sucursal->nombre ?? 'Sin sucursal',
                'clases_calculadas' => $clasesEnPeriodo
            ];
        }

        Log::info('📊 Distribución calculada:', $distribucionClases);
        Log::info('📊 Total clases: ' . $totalClases);

        // 4. Crear inscripción (usando el mismo método que store)
        $inscripcionId = DB::table('inscripciones')->insertGetId([
            'estudiante_id' => $estudiante->id,
            'modalidad_id' => $request->modalidad_id,
            'sucursal_id' => $request->sucursal_id,
            'entrenador_id' => null,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'clases_totales' => $totalClases,
            'clases_asistidas' => 0,
            'permisos_usados' => 0,
            'permisos_disponibles' => 0,
            'monto_mensual' => 0,
            'estado' => 'preinscripcion',
            'observaciones' => json_encode([
                'tipo' => 'preinscripcion',
                'horarios_ids' => $request->horarios,
                'horarios_detalle' => $horariosDetalle,
                'distribucion_clases' => $distribucionClases,
                'total_clases' => $totalClases,
                'fecha_preinscripcion' => now()->toDateTimeString(),
                'observaciones_estudiante' => $request->observaciones,
                'user_agent' => $request->userAgent(),
                'ip' => $request->ip()
            ]),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 5. Crear inscripcion_horarios (con la distribución correcta)
        foreach ($distribucionClases as $dist) {
            DB::table('inscripcion_horarios')->insert([
                'inscripcion_id' => $inscripcionId,
                'horario_id' => $dist['horario_id'],
                'clases_totales' => $dist['clases_totales'],
                'clases_asistidas' => 0,
                'clases_restantes' => $dist['clases_totales'],
                'permisos_usados' => 0,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'estado' => 'reservado',
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        // 6. Generar clases programadas (opcional, pueden generarse al confirmar)
        // Por ahora no generamos clases, solo reservamos los horarios

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => '¡Preinscripción exitosa!',
            'data' => [
                'inscripcion_id' => $inscripcionId,
                'estudiante_id' => $estudiante->id,
                'horarios' => count($request->horarios),
                'clases_totales' => $totalClases,
                'distribucion' => $distribucionClases
            ]
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('❌ Error:', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'line' => $e->getLine(),
            'file' => $e->getFile()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Error al procesar la preinscripción: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Reservar clases para un horario (estado = reservada)
 */
private function reservarClasesParaHorario($inscripcionId, $inscripcionHorarioId, $horario, $estudianteId)
{
    // Por ahora, reservamos clases para 1 mes (4 semanas)
    $fechaActual = Carbon::now();
    $fechaLimite = Carbon::now()->addWeeks(4);
    
    $diasMap = [
        'lunes' => 1, 'martes' => 2, 'miércoles' => 3, 'miercoles' => 3,
        'jueves' => 4, 'viernes' => 5, 'sábado' => 6, 'sabado' => 6, 'domingo' => 0
    ];
    
    $diaHorario = $diasMap[strtolower($horario->dia_semana)] ?? -1;
    
    if ($diaHorario === -1) return 0;
    
    $clasesReservadas = 0;
    $fechaActual = clone $fechaActual;
    
    while ($fechaActual <= $fechaLimite) {
        if ($fechaActual->dayOfWeek == $diaHorario) {
            ClaseProgramada::create([
                'inscripcion_horario_id' => $inscripcionHorarioId,
                'horario_id' => $horario->id,
                'inscripcion_id' => $inscripcionId,
                'estudiante_id' => $estudianteId,
                'fecha' => $fechaActual->format('Y-m-d'),
                'hora_inicio' => $horario->hora_inicio,
                'hora_fin' => $horario->hora_fin,
                'estado_clase' => 'reservada', // ← NUEVO ESTADO
                'es_recuperacion' => false,
                'cuenta_para_asistencia' => true,
                'observaciones' => 'Clase reservada - pendiente de confirmación'
            ]);
            $clasesReservadas++;
        }
        $fechaActual->addDay();
    }
    
    return $clasesReservadas;
}

    /**
     * Approve a preinscripcion - ¡Aquí SÍ se crean todos los registros!
     */
    public function approve($id, Request $request)
    {
        try {
            DB::beginTransaction();

            // Buscar la preinscripción
            $preinscripcion = Inscripcion::with(['estudiante', 'modalidad'])
                ->where('estado', 'preinscripcion')
                ->findOrFail($id);

            // Validar datos para la confirmación
            $validator = Validator::make($request->all(), [
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date|after:fecha_inicio',
                'entrenador_id' => 'nullable|exists:users,id',
                'horarios_confirmados' => 'required|array|min:1',
                'horarios_confirmados.*' => 'exists:horarios,id',
                'clases_totales' => 'required|integer|min:1',
                'permisos_disponibles' => 'required|integer|min:0',
                'monto_mensual' => 'required|numeric|min:0',
                'metodo_pago' => 'required|in:efectivo,qr,tarjeta,transferencia',
                'monto_pagado' => 'required|numeric|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // Obtener observaciones originales
            $observacionesOriginales = json_decode($preinscripcion->observaciones, true) ?: [];

            // ===== 1. ACTUALIZAR INSCRIPCIÓN =====
            $preinscripcion->update([
                'estado' => 'activo',
                'fecha_inicio' => $request->fecha_inicio,
                'fecha_fin' => $request->fecha_fin,
                'entrenador_id' => $request->entrenador_id,
                'clases_totales' => $request->clases_totales,
                'permisos_disponibles' => $request->permisos_disponibles,
                'monto_mensual' => $request->monto_mensual,
                'observaciones' => json_encode(array_merge($observacionesOriginales, [
                    'fecha_confirmacion' => now()->toDateTimeString(),
                    'admin_confirmador_id' => auth()->id(),
                    'admin_confirmador' => auth()->user()->name ?? 'Admin',
                    'horarios_confirmados' => $request->horarios_confirmados
                ]))
            ]);

            // ===== 2. CREAR INSCRIPCION_HORARIOS =====
            $clasesPorHorario = floor($request->clases_totales / count($request->horarios_confirmados));
            $horariosConClaseExtra = $request->clases_totales % count($request->horarios_confirmados);

            foreach ($request->horarios_confirmados as $index => $horarioId) {
                $clasesParaEsteHorario = $clasesPorHorario + ($index < $horariosConClaseExtra ? 1 : 0);

                $inscripcionHorario = InscripcionHorario::create([
                    'inscripcion_id' => $preinscripcion->id,
                    'horario_id' => $horarioId,
                    'clases_totales' => $clasesParaEsteHorario,
                    'clases_asistidas' => 0,
                    'clases_restantes' => $clasesParaEsteHorario,
                    'permisos_usados' => 0,
                    'fecha_inicio' => $request->fecha_inicio,
                    'fecha_fin' => $request->fecha_fin,
                    'estado' => 'activo'
                ]);

                // ===== 3. GENERAR CLASES PROGRAMADAS =====
                $this->generarClasesProgramadas(
                    $preinscripcion->id,
                    $inscripcionHorario->id,
                    $horarioId,
                    $preinscripcion->estudiante_id,
                    $request->fecha_inicio,
                    $request->fecha_fin
                );
            }

            // ===== 4. CREAR PAGO =====
            $pago = Pago::create([
                'inscripcion_id' => $preinscripcion->id,
                'estudiante_id' => $preinscripcion->estudiante_id,
                'monto' => $request->monto_pagado,
                'metodo_pago' => $request->metodo_pago,
                'fecha_pago' => now(),
                'estado' => 'pagado',
                'observacion' => 'Pago de inscripción - Confirmación de preinscripción',
                'referencia' => 'CONF-' . $preinscripcion->id . '-' . time()
            ]);

            // ===== 5. ACTUALIZAR ESTADO DEL ESTUDIANTE =====
            $preinscripcion->estudiante->update(['estado' => 'activo']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => '✅ Preinscripción confirmada exitosamente',
                'data' => [
                    'inscripcion_id' => $preinscripcion->id,
                    'estudiante' => $preinscripcion->estudiante->nombres . ' ' . $preinscripcion->estudiante->apellidos,
                    'clases_generadas' => $request->clases_totales,
                    'horarios_asignados' => count($request->horarios_confirmados),
                    'pago_registrado' => $pago->monto
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('❌ Error aprobando preinscripción:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al confirmar la preinscripción',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generar clases programadas para un horario
     */
    private function generarClasesProgramadas($inscripcionId, $inscripcionHorarioId, $horarioId, $estudianteId, $fechaInicio, $fechaFin)
    {
        $horario = Horario::find($horarioId);
        if (!$horario) return;

        $fechaActual = Carbon::parse($fechaInicio);
        $fechaFinDate = Carbon::parse($fechaFin);
        
        // Mapeo de días de semana
        $diasMap = [
            'lunes' => 1, 'martes' => 2, 'miércoles' => 3, 'miercoles' => 3,
            'jueves' => 4, 'viernes' => 5, 'sábado' => 6, 'sabado' => 6, 'domingo' => 0
        ];
        
        $diaHorario = $diasMap[strtolower($horario->dia_semana)] ?? -1;
        
        $clasesGeneradas = 0;
        
        while ($fechaActual <= $fechaFinDate) {
            if ($fechaActual->dayOfWeek == $diaHorario) {
                ClaseProgramada::create([
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
            
            $fechaActual->addDay();
        }
        
        Log::info("📅 Clases generadas para horario {$horarioId}: {$clasesGeneradas}");
        
        return $clasesGeneradas;
    }

    /**
     * Display the specified preinscripcion.
     */
    public function show($id)
    {
        try {
            $preinscripcion = Inscripcion::with([
                'estudiante',
                'modalidad',
                'sucursal'
            ])->where('estado', 'preinscripcion')
              ->findOrFail($id);

            // Decodificar observaciones para mostrar horarios
            $observaciones = json_decode($preinscripcion->observaciones, true);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $preinscripcion->id,
                    'estudiante' => $preinscripcion->estudiante,
                    'modalidad' => $preinscripcion->modalidad,
                    'sucursal' => $preinscripcion->sucursal,
                    'horarios_preferidos' => $observaciones['horarios_detalle'] ?? [],
                    'fecha_preinscripcion' => $preinscripcion->created_at,
                    'observaciones' => $observaciones['observaciones_estudiante'] ?? ''
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Preinscripción no encontrada'
            ], 404);
        }
    }

    /**
     * Index modificado para mostrar horarios desde JSON
     */
    public function index(Request $request)
    {
        try {
            Log::info('📋 Listando preinscripciones');

            $query = Inscripcion::with([
                'estudiante',
                'modalidad',
                'sucursal'
            ])->where('estado', 'preinscripcion');

            // Filtro por búsqueda
            if ($request->has('buscar') && $request->buscar) {
                $search = $request->buscar;
                $query->whereHas('estudiante', function($q) use ($search) {
                    $q->where('nombres', 'LIKE', "%{$search}%")
                      ->orWhere('apellidos', 'LIKE', "%{$search}%")
                      ->orWhere('ci', 'LIKE', "%{$search}%");
                });
            }

            $query->orderBy('created_at', 'desc');

            $perPage = $request->get('per_page', 15);
            $preinscripciones = $query->paginate($perPage);

            // Transformar para incluir horarios decodificados
            $preinscripciones->getCollection()->transform(function ($item) {
                $observaciones = json_decode($item->observaciones, true);
                $item->horarios_preferidos = $observaciones['horarios_detalle'] ?? [];
                return $item;
            });

            return response()->json([
                'success' => true,
                'data' => $preinscripciones,
                'message' => 'Preinscripciones obtenidas correctamente'
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Error al listar preinscripciones:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener preinscripciones',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}