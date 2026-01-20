<?php

namespace App\Http\Controllers;

use App\Models\Horario;
use App\Models\Disciplina;
use App\Models\Sucursal;
use App\Models\Entrenador;
use App\Models\Modalidad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class HorarioController extends Controller
{
    public function index(Request $request)
{
    try {
        $query = Horario::with(['disciplina', 'sucursal', 'entrenador', 'modalidad'])
            ->where('estado', 'activo'); // Solo horarios activos por defecto
        
        // FILTROS BÁSICOS
        if ($request->has('sucursal_id')) {
            $query->where('sucursal_id', $request->sucursal_id);
        }
        
        if ($request->has('disciplina_id')) {
            $query->where('disciplina_id', $request->disciplina_id);
        }
        
        if ($request->has('dia_semana')) {
            $query->where('dia_semana', $request->dia_semana);
        }
        
        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }
        
        if ($request->has('con_cupo')) {
            $query->whereRaw('cupo_actual < cupo_maximo');
        }
        
        // NUEVOS FILTROS PARA INSCRIPCIONES
        if ($request->has('modalidad_id')) {
            $query->where('modalidad_id', $request->modalidad_id);
        }
        
        if ($request->has('entrenador_id')) {
            $query->where('entrenador_id', $request->entrenador_id);
        }
        
        // Búsqueda por texto (nombre o descripción)
        if ($request->has('q') && !empty($request->q)) {
            $searchTerm = $request->q;
            $query->where(function($q) use ($searchTerm) {
                $q->where('nombre', 'like', "%{$searchTerm}%")
                  ->orWhere('descripcion', 'like', "%{$searchTerm}%");
            });
        }
        
        // Ordenar por día de la semana (en orden lógico) y luego por hora
        $query->orderByRaw("
            CASE 
                WHEN dia_semana = 'Lunes' THEN 1
                WHEN dia_semana = 'Martes' THEN 2
                WHEN dia_semana = 'Miércoles' THEN 3
                WHEN dia_semana = 'Jueves' THEN 4
                WHEN dia_semana = 'Viernes' THEN 5
                WHEN dia_semana = 'Sábado' THEN 6
                WHEN dia_semana = 'Domingo' THEN 7
                ELSE 8
            END
        ")->orderBy('hora_inicio');
        
        // Paginación o todos
        if ($request->has('per_page')) {
            return $query->paginate($request->per_page);
        }
        
        // Si se pide con parámetro 'all', devolver todos
        if ($request->has('all') && $request->all == 'true') {
            return $query->get();
        }
        
        // Por defecto, paginar con 100 items
        return $query->paginate($request->get('limit', 100));
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener horarios: ' . $e->getMessage()
        ], 500);
    }
}

    public function store(Request $request)
    {
        $request->validate([
        'nombre' => 'required|string|max:255',
        'dia_semana' => 'required|in:Lunes,Martes,Miércoles,Jueves,Viernes,Sábado,Domingo',
        'hora_inicio' => 'required|date_format:H:i',
        'hora_fin' => 'required|date_format:H:i|after:hora_inicio',
        'disciplina_id' => 'required|exists:disciplinas,id',
        'sucursal_id' => 'required|exists:sucursales,id',
        'entrenador_id' => 'required|exists:entrenadores,id',
        'modalidad_id' => 'nullable|exists:modalidades,id',
        'cupo_maximo' => 'required|integer|min:1|max:50',
        'estado' => 'nullable|in:activo,inactivo,completo',
        // ELIMINA o SIMPLIFICA esta validación de color:
        // 'color' => 'nullable|string|max:7|regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/',
        'color' => 'nullable|string|max:7', // Solo valida longitud
        'descripcion' => 'nullable|string|max:500',
    ]);

        // Calcular duración automáticamente
        $inicio = Carbon::createFromFormat('H:i', $request->hora_inicio);
        $fin = Carbon::createFromFormat('H:i', $request->hora_fin);
        $duracion_minutos = $inicio->diffInMinutes($fin);

        // Verificar conflictos de horario para el entrenador
        $conflictoEntrenador = Horario::where('entrenador_id', $request->entrenador_id)
            ->where('dia_semana', $request->dia_semana)
            ->where(function($query) use ($inicio, $fin) {
                $query->where(function($q) use ($inicio, $fin) {
                    $q->where('hora_inicio', '<', $fin->format('H:i'))
                      ->where('hora_fin', '>', $inicio->format('H:i'));
                });
            })
            ->where('estado', '!=', 'inactivo')
            ->exists();

        if ($conflictoEntrenador) {
            return response()->json([
                'error' => 'El entrenador ya tiene un horario asignado en ese intervalo de tiempo'
            ], 422);
        }

        // Verificar disponibilidad de la sala en la sucursal
        $conflictoSala = Horario::where('sucursal_id', $request->sucursal_id)
            ->where('dia_semana', $request->dia_semana)
            ->where(function($query) use ($inicio, $fin) {
                $query->where(function($q) use ($inicio, $fin) {
                    $q->where('hora_inicio', '<', $fin->format('H:i'))
                      ->where('hora_fin', '>', $inicio->format('H:i'));
                });
            })
            ->where('estado', '!=', 'inactivo')
            ->exists();

        if ($conflictoSala) {
            return response()->json([
                'error' => 'La sala ya está ocupada en ese horario'
            ], 422);
        }

        // Crear el horario
        $horario = Horario::create([
            'nombre' => $request->nombre,
            'dia_semana' => $request->dia_semana,
            'hora_inicio' => $request->hora_inicio,
            'hora_fin' => $request->hora_fin,
            'duracion_minutos' => $duracion_minutos,
            'disciplina_id' => $request->disciplina_id,
            'sucursal_id' => $request->sucursal_id,
            'entrenador_id' => $request->entrenador_id,
            'modalidad_id' => $request->modalidad_id,
            'cupo_maximo' => $request->cupo_maximo,
            'cupo_actual' => 0,
            'estado' => $request->estado ?? 'activo',
            'color' => $request->color ?? $this->generarColorAleatorio(),
            'descripcion' => $request->descripcion,
        ]);

        return response()->json([
            'message' => 'Horario creado exitosamente',
            'data' => $horario->load(['disciplina', 'sucursal', 'entrenador', 'modalidad'])
        ], 201);
    }

    public function show($id)
    {
        $horario = Horario::with(['disciplina', 'sucursal', 'entrenador', 'modalidad', 'inscripciones.estudiante'])
            ->findOrFail($id);
        
        // Agregar estadísticas
        $horario->estadisticas = [
            'ocupacion_porcentaje' => $horario->cupo_maximo > 0 
                ? round(($horario->cupo_actual / $horario->cupo_maximo) * 100, 2) 
                : 0,
            'disponibilidad' => $horario->cupo_maximo - $horario->cupo_actual,
            'inscripciones_activas' => $horario->inscripciones->where('pivot.estado', 'activo')->count(),
        ];
        
        return $horario;
    }

    // Método para obtener horarios disponibles (con cupo)
public function disponibles(Request $request)
{
    try {
        $query = Horario::with(['disciplina', 'sucursal', 'entrenador', 'modalidad'])
            ->where('estado', 'activo')
            ->whereRaw('cupo_actual < cupo_maximo'); // Solo con cupo disponible
        
        // Filtros
        if ($request->has('modalidad_id')) {
            $query->where('modalidad_id', $request->modalidad_id);
        }
        
        if ($request->has('sucursal_id')) {
            $query->where('sucursal_id', $request->sucursal_id);
        }
        
        // Ordenar
        $query->orderByRaw("
            CASE 
                WHEN dia_semana = 'Lunes' THEN 1
                WHEN dia_semana = 'Martes' THEN 2
                WHEN dia_semana = 'Miércoles' THEN 3
                WHEN dia_semana = 'Jueves' THEN 4
                WHEN dia_semana = 'Viernes' THEN 5
                WHEN dia_semana = 'Sábado' THEN 6
                WHEN dia_semana = 'Domingo' THEN 7
                ELSE 8
            END
        ")->orderBy('hora_inicio');
        
        return $query->get();
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener horarios disponibles: ' . $e->getMessage()
        ], 500);
    }
}

// Método para obtener horarios por modalidad
public function porModalidad($modalidadId, Request $request)
{
    try {
        $query = Horario::with(['disciplina', 'sucursal', 'entrenador', 'modalidad'])
            ->where('modalidad_id', $modalidadId)
            ->where('estado', 'activo');
        
        // Filtro por sucursal
        if ($request->has('sucursal_id')) {
            $query->where('sucursal_id', $request->sucursal_id);
        }
        
        // Solo con cupo disponible
        if ($request->has('con_cupo') && $request->con_cupo == 'true') {
            $query->whereRaw('cupo_actual < cupo_maximo');
        }
        
        // Ordenar
        $query->orderByRaw("
            CASE 
                WHEN dia_semana = 'Lunes' THEN 1
                WHEN dia_semana = 'Martes' THEN 2
                WHEN dia_semana = 'Miércoles' THEN 3
                WHEN dia_semana = 'Jueves' THEN 4
                WHEN dia_semana = 'Viernes' THEN 5
                WHEN dia_semana = 'Sábado' THEN 6
                WHEN dia_semana = 'Domingo' THEN 7
                ELSE 8
            END
        ")->orderBy('hora_inicio');
        
        return $query->get();
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener horarios por modalidad: ' . $e->getMessage()
        ], 500);
    }
}

    public function update(Request $request, $id)
    {
        $horario = Horario::findOrFail($id);
        
        $request->validate([
        'nombre' => 'sometimes|required|string|max:255',
        'dia_semana' => 'sometimes|required|in:Lunes,Martes,Miércoles,Jueves,Viernes,Sábado,Domingo',
        'hora_inicio' => 'sometimes|required|date_format:H:i',
        'hora_fin' => 'sometimes|required|date_format:H:i|after:hora_inicio',
        'disciplina_id' => 'sometimes|required|exists:disciplinas,id',
        'sucursal_id' => 'sometimes|required|exists:sucursales,id',
        'entrenador_id' => 'sometimes|required|exists:entrenadores,id',
        'modalidad_id' => 'nullable|exists:modalidades,id',
        'cupo_maximo' => 'sometimes|required|integer|min:'.$horario->cupo_actual.'|max:50',
        'estado' => 'sometimes|in:activo,inactivo,completo',
        'color' => 'nullable|string|max:7', // Simplificado
        'descripcion' => 'nullable|string|max:500',
    ]);

        // Verificar conflictos solo si se cambian datos críticos
        if ($request->hasAny(['hora_inicio', 'hora_fin', 'dia_semana', 'entrenador_id', 'sucursal_id'])) {
            $inicio = $request->has('hora_inicio') 
                ? Carbon::createFromFormat('H:i', $request->hora_inicio)
                : Carbon::createFromFormat('H:i', $horario->hora_inicio);
            
            $fin = $request->has('hora_fin') 
                ? Carbon::createFromFormat('H:i', $request->hora_fin)
                : Carbon::createFromFormat('H:i', $horario->hora_fin);
            
            $diaSemana = $request->dia_semana ?? $horario->dia_semana;
            $entrenadorId = $request->entrenador_id ?? $horario->entrenador_id;
            $sucursalId = $request->sucursal_id ?? $horario->sucursal_id;

            // Verificar conflicto entrenador (excluyendo este mismo horario)
            $conflictoEntrenador = Horario::where('entrenador_id', $entrenadorId)
                ->where('dia_semana', $diaSemana)
                ->where('id', '!=', $id)
                ->where(function($query) use ($inicio, $fin) {
                    $query->where(function($q) use ($inicio, $fin) {
                        $q->where('hora_inicio', '<', $fin->format('H:i'))
                          ->where('hora_fin', '>', $inicio->format('H:i'));
                    });
                })
                ->where('estado', '!=', 'inactivo')
                ->exists();

            if ($conflictoEntrenador) {
                return response()->json([
                    'error' => 'El entrenador ya tiene otro horario asignado en ese intervalo'
                ], 422);
            }

            // Verificar conflicto sala (excluyendo este mismo horario)
            $conflictoSala = Horario::where('sucursal_id', $sucursalId)
                ->where('dia_semana', $diaSemana)
                ->where('id', '!=', $id)
                ->where(function($query) use ($inicio, $fin) {
                    $query->where(function($q) use ($inicio, $fin) {
                        $q->where('hora_inicio', '<', $fin->format('H:i'))
                          ->where('hora_fin', '>', $inicio->format('H:i'));
                    });
                })
                ->where('estado', '!=', 'inactivo')
                ->exists();

            if ($conflictoSala) {
                return response()->json([
                    'error' => 'La sala ya está ocupada en ese horario'
                ], 422);
            }

            // Actualizar duración si cambió la hora
            if ($request->has('hora_inicio') || $request->has('hora_fin')) {
                $request->merge(['duracion_minutos' => $inicio->diffInMinutes($fin)]);
            }
        }

        $horario->update($request->all());

        // Actualizar estado según cupo
        if ($horario->cupo_actual >= $horario->cupo_maximo) {
            $horario->update(['estado' => 'completo']);
        }

        return response()->json([
            'message' => 'Horario actualizado exitosamente',
            'data' => $horario->load(['disciplina', 'sucursal', 'entrenador', 'modalidad'])
        ]);
    }

    public function destroy($id)
    {
        $horario = Horario::findOrFail($id);
        
        // Verificar si hay inscripciones activas
        $inscripcionesActivas = $horario->inscripciones()
            ->wherePivot('estado', 'activo')
            ->count();
        
        if ($inscripcionesActivas > 0) {
            return response()->json([
                'error' => 'No se puede eliminar el horario porque tiene inscripciones activas'
            ], 422);
        }
        
        $horario->delete();
        
        return response()->json([
            'message' => 'Horario eliminado exitosamente'
        ]);
    }

    public function cambiarEstado($id, Request $request)
    {
        $request->validate([
            'estado' => 'required|in:activo,inactivo'
        ]);
        
        $horario = Horario::findOrFail($id);
        $horario->update(['estado' => $request->estado]);
        
        return response()->json([
            'message' => 'Estado del horario actualizado',
            'data' => $horario
        ]);
    }

    public function horariosPorDia(Request $request)
    {
        $request->validate([
            'dia_semana' => 'required|in:Lunes,Martes,Miércoles,Jueves,Viernes,Sábado,Domingo',
            'sucursal_id' => 'nullable|exists:sucursales,id'
        ]);
        
        $query = Horario::with(['disciplina', 'entrenador'])
            ->where('dia_semana', $request->dia_semana)
            ->where('estado', 'activo')
            ->orderBy('hora_inicio');
        
        if ($request->has('sucursal_id')) {
            $query->where('sucursal_id', $request->sucursal_id);
        }
        
        return $query->get();
    }

// HorarioController.php
public function horariosDisponibles(Request $request)
{
    try {
        $query = Horario::with([
            'modalidad:id,nombre,precio_mensual,permisos_maximos',
            'entrenador:id,nombres,apellidos',
            'sucursal:id,nombre',
            'disciplina:id,nombre'
        ]);
        
        // Filtro por estado activo
        $query->where('estado', 'activo');
        
        // Filtro por cupo disponible (si se solicita)
        if ($request->boolean('con_cupo', true)) {
            $query->whereRaw('cupo_actual < cupo_maximo');
        }
        
        // Filtros opcionales
        if ($request->filled('modalidad_id')) {
            $query->where('modalidad_id', $request->modalidad_id);
        }
        
        if ($request->filled('disciplina_id')) {
            $query->where('disciplina_id', $request->disciplina_id);
        }
        
        if ($request->filled('sucursal_id')) {
            $query->where('sucursal_id', $request->sucursal_id);
        }
        
        if ($request->filled('entrenador_id')) {
            $query->where('entrenador_id', $request->entrenador_id);
        }
        
        // Ordenar por día y hora
        $horarios = $query->orderByRaw("
            FIELD(dia_semana, 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'),
            hora_inicio
        ")->get();
        
        return response()->json([
            'success' => true,
            'data' => $horarios
        ]);
        
    } catch (\Exception $e) {
        \Log::error('Error en horariosDisponibles:', ['error' => $e->getMessage()]);
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener horarios disponibles',
            'error' => $e->getMessage()
        ], 500);
    }
}

    public function estadisticas()
    {
        $totalHorarios = Horario::count();
        $horariosActivos = Horario::where('estado', 'activo')->count();
        $horariosCompletos = Horario::where('estado', 'completo')->count();
        
        $ocupacionPromedio = Horario::where('cupo_maximo', '>', 0)
            ->select(DB::raw('AVG((cupo_actual / cupo_maximo) * 100) as promedio'))
            ->first()->promedio ?? 0;
        
        $horariosPorDia = Horario::select('dia_semana', DB::raw('COUNT(*) as total'))
            ->groupBy('dia_semana')
            ->get();
        
        return response()->json([
            'total_horarios' => $totalHorarios,
            'horarios_activos' => $horariosActivos,
            'horarios_completos' => $horariosCompletos,
            'ocupacion_promedio' => round($ocupacionPromedio, 2),
            'distribucion_por_dia' => $horariosPorDia,
            'horarios_por_disciplina' => Horario::with('disciplina')
                ->select('disciplina_id', DB::raw('COUNT(*) as total'))
                ->groupBy('disciplina_id')
                ->get()
        ]);
    }

    // HorarioController.php
public function getPorModalidad($modalidadId)
{
    try {
        $horarios = Horario::where('modalidad_id', $modalidadId)
            ->where('estado', 'activo')
            ->with(['modalidad', 'entrenador', 'sucursal'])
            ->get();
            
        return response()->json([
            'success' => true,
            'data' => $horarios
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al cargar horarios'
        ], 500);
    }
}



    public function incrementarCupo($id)
    {
        $horario = Horario::findOrFail($id);
        
        if ($horario->cupo_actual >= $horario->cupo_maximo) {
            return response()->json(['error' => 'Cupo máximo alcanzado'], 422);
        }
        
        $horario->increment('cupo_actual');
        
        // Actualizar estado si se llenó
        if ($horario->cupo_actual >= $horario->cupo_maximo) {
            $horario->update(['estado' => 'completo']);
        }
        
        return response()->json([
            'message' => 'Cupo incrementado',
            'cupo_actual' => $horario->cupo_actual
        ]);
    }

    public function decrementarCupo($id)
    {
        $horario = Horario::findOrFail($id);
        
        if ($horario->cupo_actual <= 0) {
            return response()->json(['error' => 'Cupo ya está en 0'], 422);
        }
        
        $horario->decrement('cupo_actual');
        
        // Cambiar estado si ya no está completo
        if ($horario->estado === 'completo' && $horario->cupo_actual < $horario->cupo_maximo) {
            $horario->update(['estado' => 'activo']);
        }
        
        return response()->json([
            'message' => 'Cupo decrementado',
            'cupo_actual' => $horario->cupo_actual
        ]);
    }

    private function generarColorAleatorio(): string
    {
        $coloresDisciplinas = [
            '#3B82F6', // Azul - MMA
            '#EF4444', // Rojo - King Boxer
            '#10B981', // Verde - Karate
            '#8B5CF6', // Violeta - Boxeo
            '#F59E0B', // Naranja - Taekwondo
            '#EC4899', // Rosa - Kickboxing
        ];
        
        return $coloresDisciplinas[array_rand($coloresDisciplinas)];
    }
}