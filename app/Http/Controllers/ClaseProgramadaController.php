<?php

namespace App\Http\Controllers;

use App\Models\ClaseProgramada;
use App\Models\Inscripcion;
use App\Models\Horario;
use App\Models\Estudiante;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClaseProgramadaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $request->validate([
            'inscripcion_id' => 'nullable|exists:inscripciones,id',
            'estudiante_id' => 'nullable|exists:estudiantes,id',
            'fecha' => 'nullable|date',
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date|after_or_equal:fecha_desde',
            'estado_clase' => 'nullable|in:programada,realizada,ausente,justificada,cancelada,feriado,recuperacion',
            'es_recuperacion' => 'nullable|boolean',
            'dia_semana' => 'nullable|in:lunes,martes,miercoles,jueves,viernes,sabado,domingo',
            'paginate' => 'nullable|integer|min:1|max:100',
        ]);

        $query = ClaseProgramada::with([
            'estudiante:id,nombres,apellidos,ci',
            'horario:id,nombre_horario,dia_semana,hora_inicio,hora_fin',
            'inscripcion:id,estado,fecha_inicio,fecha_fin',
            'asistencia:id,observacion,estado'
        ]);

        // Filtro por inscripción
        if ($request->inscripcion_id) {
            $query->where('inscripcion_id', $request->inscripcion_id);
        }

        // Filtro por estudiante
        if ($request->estudiante_id) {
            $query->where('estudiante_id', $request->estudiante_id);
        }

        // Filtro por fecha específica
        if ($request->fecha) {
            $query->whereDate('fecha', $request->fecha);
        }

        // Filtro por rango de fechas
        if ($request->fecha_desde) {
            $query->where('fecha', '>=', $request->fecha_desde);
        }
        if ($request->fecha_hasta) {
            $query->where('fecha', '<=', $request->fecha_hasta);
        }

        // Filtro por estado
        if ($request->estado_clase) {
            $query->where('estado_clase', $request->estado_clase);
        }

        // Filtro por recuperaciones
        if ($request->has('es_recuperacion')) {
            $query->where('es_recuperacion', $request->es_recuperacion);
        }

        // Filtro por día de la semana
        if ($request->dia_semana) {
            $diasMap = [
                'lunes' => 1, 'martes' => 2, 'miercoles' => 3,
                'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 0
            ];
            $diaNumero = $diasMap[strtolower($request->dia_semana)];
            
            $query->whereHas('horario', function ($q) use ($diaNumero) {
                $q->whereRaw("FIELD(LOWER(dia_semana), 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo') = ?", [$diaNumero + 1]);
            });
        }

        // Ordenamiento
        $query->orderBy('fecha', 'asc')
              ->orderBy('hora_inicio', 'asc');

        $perPage = $request->paginate ?? 20;
        $clases = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $clases,
            'total' => $clases->total(),
            'estadisticas' => [
                'programadas' => $clases->where('estado_clase', 'programada')->count(),
                'realizadas' => $clases->where('estado_clase', 'realizada')->count(),
                'ausentes' => $clases->where('estado_clase', 'ausente')->count(),
                'justificadas' => $clases->where('estado_clase', 'justificada')->count(),
            ]
        ]);
    }

    /**
     * Generar clases automáticamente para una inscripción
     */
    public function generar(Request $request)
    {
        $request->validate([
            'inscripcion_id' => 'required|exists:inscripciones,id',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
        ]);

        $inscripcion = Inscripcion::with(['horarios', 'estudiante'])->findOrFail($request->inscripcion_id);
        
        $fechaInicio = $request->fecha_inicio ? Carbon::parse($request->fecha_inicio) : Carbon::parse($inscripcion->fecha_inicio);
        $fechaFin = $request->fecha_fin ? Carbon::parse($request->fecha_fin) : Carbon::parse($inscripcion->fecha_fin);

        // Verificar si ya tiene clases generadas
        $clasesExistentes = ClaseProgramada::where('inscripcion_id', $inscripcion->id)->count();
        
        if ($clasesExistentes > 0) {
            return response()->json([
                'success' => true,
                'message' => "La inscripción ya tiene {$clasesExistentes} clases generadas",
                'total_clases' => $clasesExistentes
            ]);
        }

        DB::beginTransaction();
        $totalGeneradas = 0;

        try {
            $diasMap = [
                'lunes' => 1, 'martes' => 2, 'miércoles' => 3, 'miercoles' => 3,
                'jueves' => 4, 'viernes' => 5, 'sábado' => 6, 'sabado' => 6,
                'domingo' => 0
            ];

            foreach ($inscripcion->horarios as $horario) {
                $diaHorario = strtolower($horario->dia_semana);
                $diaNumero = $diasMap[$diaHorario] ?? 1;
                
                $fechaActual = $fechaInicio->copy();
                
                while ($fechaActual <= $fechaFin) {
                    if ($fechaActual->dayOfWeek == $diaNumero) {
                        // Verificar si ya existe esta clase
                        $existe = ClaseProgramada::where('inscripcion_id', $inscripcion->id)
                            ->where('horario_id', $horario->id)
                            ->whereDate('fecha', $fechaActual)
                            ->exists();
                        
                        if (!$existe) {
                            ClaseProgramada::create([
                                'inscripcion_id' => $inscripcion->id,
                                'horario_id' => $horario->id,
                                'estudiante_id' => $inscripcion->estudiante_id,
                                'fecha' => $fechaActual->format('Y-m-d'),
                                'hora_inicio' => $horario->hora_inicio,
                                'hora_fin' => $horario->hora_fin,
                                'estado_clase' => 'programada',
                                'cuenta_para_asistencia' => true,
                                'es_recuperacion' => false,
                                'observaciones' => 'Generada automáticamente al crear la inscripción'
                            ]);
                            
                            $totalGeneradas++;
                        }
                    }
                    $fechaActual->addDay();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Se generaron {$totalGeneradas} clases programadas",
                'total_clases' => $totalGeneradas,
                'inscripcion_id' => $inscripcion->id
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Error generando clases: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'inscripcion_id' => 'required|exists:inscripciones,id',
            'horario_id' => 'required|exists:horarios,id',
            'estudiante_id' => 'required|exists:estudiantes,id',
            'fecha' => 'required|date',
            'hora_inicio' => 'required',
            'hora_fin' => 'required',
            'estado_clase' => 'required|in:programada,realizada,ausente,justificada,cancelada,feriado,recuperacion',
            'observaciones' => 'nullable|string'
        ]);

        // Verificar que no exista duplicado
        $existe = ClaseProgramada::where('inscripcion_id', $request->inscripcion_id)
            ->where('horario_id', $request->horario_id)
            ->where('fecha', $request->fecha)
            ->exists();

        if ($existe) {
            return response()->json([
                'success' => false,
                'message' => 'Ya existe una clase programada para esta fecha y horario'
            ], 422);
        }

        $clase = ClaseProgramada::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Clase programada creada exitosamente',
            'data' => $clase->load(['estudiante', 'horario'])
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $clase = ClaseProgramada::with(['estudiante', 'horario', 'inscripcion', 'asistencia'])->findOrFail($id);
        
        return response()->json([
            'success' => true,
            'data' => $clase
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $clase = ClaseProgramada::findOrFail($id);
        
        $request->validate([
            'fecha' => 'sometimes|date',
            'hora_inicio' => 'sometimes',
            'hora_fin' => 'sometimes',
            'estado_clase' => 'sometimes|in:programada,realizada,ausente,justificada,cancelada,feriado,recuperacion',
            'observaciones' => 'nullable|string'
        ]);

        $clase->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Clase actualizada exitosamente',
            'data' => $clase
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $clase = ClaseProgramada::findOrFail($id);
        $clase->delete();

        return response()->json([
            'success' => true,
            'message' => 'Clase eliminada exitosamente'
        ]);
    }

    /**
     * Cambiar estado de una clase
     */
    public function cambiarEstado(Request $request, $id)
    {
        $clase = ClaseProgramada::findOrFail($id);
        
        $request->validate([
            'estado_clase' => 'required|in:programada,realizada,ausente,justificada,cancelada,feriado,recuperacion',
            'observaciones' => 'nullable|string'
        ]);

        $clase->update([
            'estado_clase' => $request->estado_clase,
            'observaciones' => $request->observaciones
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Estado de clase actualizado',
            'data' => $clase
        ]);
    }

    /**
     * Calendario del mes
     */
    public function calendario(Request $request)
    {
        $request->validate([
            'mes' => 'required|integer|min:1|max:12',
            'anio' => 'required|integer',
            'estudiante_id' => 'nullable|exists:estudiantes,id'
        ]);

        $fechaInicio = Carbon::create($request->anio, $request->mes, 1)->startOfMonth();
        $fechaFin = $fechaInicio->copy()->endOfMonth();

        $query = ClaseProgramada::whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->with(['estudiante', 'horario', 'inscripcion']);

        if ($request->estudiante_id) {
            $query->where('estudiante_id', $request->estudiante_id);
        }

        $clases = $query->orderBy('fecha', 'asc')
                       ->orderBy('hora_inicio', 'asc')
                       ->get();

        // Agrupar por día
        $calendario = [];
        foreach ($clases as $clase) {
            $dia = $clase->fecha;
            if (!isset($calendario[$dia])) {
                $calendario[$dia] = [];
            }
            $calendario[$dia][] = $clase;
        }

        return response()->json([
            'success' => true,
            'mes' => $request->mes,
            'anio' => $request->anio,
            'fecha_inicio' => $fechaInicio->format('Y-m-d'),
            'fecha_fin' => $fechaFin->format('Y-m-d'),
            'calendario' => $calendario,
            'total_clases' => $clases->count()
        ]);
    }

    /**
     * Reporte de asistencias
     */
    public function reporteAsistencias(Request $request)
    {
        $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            'estudiante_id' => 'nullable|exists:estudiantes,id'
        ]);

        $query = ClaseProgramada::whereBetween('fecha', [$request->fecha_inicio, $request->fecha_fin])
            ->with(['estudiante', 'horario']);

        if ($request->estudiante_id) {
            $query->where('estudiante_id', $request->estudiante_id);
        }

        $clases = $query->get();

        $estadisticas = [
            'total' => $clases->count(),
            'programadas' => $clases->where('estado_clase', 'programada')->count(),
            'realizadas' => $clases->where('estado_clase', 'realizada')->count(),
            'ausentes' => $clases->where('estado_clase', 'ausente')->count(),
            'justificadas' => $clases->where('estado_clase', 'justificada')->count(),
            'canceladas' => $clases->where('estado_clase', 'cancelada')->count(),
            'recuperaciones' => $clases->where('estado_clase', 'recuperacion')->count(),
        ];

        return response()->json([
            'success' => true,
            'fecha_inicio' => $request->fecha_inicio,
            'fecha_fin' => $request->fecha_fin,
            'estadisticas' => $estadisticas,
            'clases' => $clases
        ]);
    }

    /**
     * Crear recuperación
     */
    public function crearRecuperacion(Request $request)
    {
        $request->validate([
            'clase_original_id' => 'required|exists:clases_programadas,id',
            'fecha' => 'required|date|after_or_equal:today',
            'hora_inicio' => 'required',
            'hora_fin' => 'required',
            'horario_id' => 'required|exists:horarios,id',
            'observaciones' => 'nullable|string'
        ]);

        $claseOriginal = ClaseProgramada::with(['inscripcion', 'estudiante'])->findOrFail($request->clase_original_id);

        // Verificar que la clase original sea recuperable
        if (!in_array($claseOriginal->estado_clase, ['ausente', 'justificada'])) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden recuperar clases ausentes o justificadas'
            ], 400);
        }

        $claseRecuperacion = ClaseProgramada::create([
            'inscripcion_id' => $claseOriginal->inscripcion_id,
            'horario_id' => $request->horario_id,
            'estudiante_id' => $claseOriginal->estudiante_id,
            'fecha' => $request->fecha,
            'hora_inicio' => $request->hora_inicio,
            'hora_fin' => $request->hora_fin,
            'estado_clase' => 'recuperacion',
            'es_recuperacion' => true,
            'clase_original_id' => $claseOriginal->id,
            'observaciones' => $request->observaciones . ' (Recuperación de clase del ' . $claseOriginal->fecha . ')',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Clase de recuperación creada exitosamente',
            'data' => $claseRecuperacion->load(['estudiante', 'horario'])
        ]);
    }

    // En ClaseProgramadaController.php
public function buscarClaseProgramada(Request $request)
{
    $request->validate([
        'estudiante_id' => 'required|exists:estudiantes,id',
        'horario_id' => 'required|exists:horarios,id',
        'fecha' => 'required|date'
    ]);
    
    $clase = DB::table('clases_programadas')
        ->where('estudiante_id', $request->estudiante_id)
        ->where('horario_id', $request->horario_id)
        ->where('fecha', $request->fecha)
        ->where('estado_clase', 'programada') // Solo buscar clases programadas
        ->first();
    
    return response()->json([
        'success' => true,
        'data' => $clase
    ]);
}

public function marcarAsistencia($id, Request $request)
{
    $request->validate([
        'estado_clase' => 'required|in:realizada,ausente,justificada,cancelada'
    ]);
    
    $clase = DB::table('clases_programadas')->find($id);
    
    if (!$clase) {
        return response()->json([
            'success' => false,
            'message' => 'Clase programada no encontrada'
        ], 404);
    }
    
    // Actualizar estado de la clase
    DB::table('clases_programadas')
        ->where('id', $id)
        ->update([
            'estado_clase' => $request->estado_clase,
            'updated_at' => now()
        ]);
    
    // Si es realizada, también actualizar contadores
    if ($request->estado_clase === 'realizada') {
        // Actualizar inscripcion_horarios
        DB::table('inscripcion_horarios')
            ->where('id', $clase->inscripcion_horario_id)
            ->increment('clases_asistidas');
        
        DB::table('inscripcion_horarios')
            ->where('id', $clase->inscripcion_horario_id)
            ->decrement('clases_restantes');
        
        // Actualizar inscripciones
        DB::table('inscripciones')
            ->where('id', $clase->inscripcion_id)
            ->increment('clases_asistidas');
    }
    
    // Si es justificada, restar permiso
    if ($request->estado_clase === 'justificada') {
        DB::table('inscripciones')
            ->where('id', $clase->inscripcion_id)
            ->decrement('permisos_disponibles');
        
        DB::table('inscripciones')
            ->where('id', $clase->inscripcion_id)
            ->increment('permisos_usados');
    }
    
    return response()->json([
        'success' => true,
        'message' => 'Estado de clase actualizado',
        'estado_clase' => $request->estado_clase
    ]);
}
}