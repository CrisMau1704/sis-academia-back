<?php

namespace App\Http\Controllers;

use App\Models\Estudiante;
use App\Models\Inscripcion;
use Carbon\Carbon;
use Illuminate\Http\Request;

class EstudianteController extends Controller
{
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', 10);
            $page = $request->input('page', 1);
            $q = $request->input('q', '');
            $withInscripciones = filter_var($request->input('with_inscripciones', false), FILTER_VALIDATE_BOOLEAN);
            $withAntiguedad = filter_var($request->input('with_antiguedad', false), FILTER_VALIDATE_BOOLEAN);
            
            \Log::info('📋 Listando estudiantes', [
                'page' => $page,
                'limit' => $limit,
                'busqueda' => $q,
                'with_inscripciones' => $withInscripciones,
                'with_antiguedad' => $withAntiguedad
            ]);

            $query = Estudiante::query()->orderBy('id', 'desc');

            // Incluir inscripciones si se solicita
            if ($withInscripciones || $withAntiguedad) {
                $query->with(['inscripciones' => function($query) {
                    $query->orderBy('fecha_inicio', 'desc');
                }]);
            }

            // Búsqueda más completa
            if ($q) {
                $query->where(function($query) use ($q) {
                    $query->where('nombres', 'like', "%$q%")
                          ->orWhere('apellidos', 'like', "%$q%")
                          ->orWhere('ci', 'like', "%$q%")
                          ->orWhere('correo', 'like', "%$q%")
                          ->orWhere('telefono', 'like', "%$q%");
                });
            }

            // Paginación
            $estudiantes = $query->paginate($limit, ['*'], 'page', $page);

            // Si se solicita antigüedad, calcularla para cada estudiante
            if ($withAntiguedad && $estudiantes->count() > 0) {
                $estudiantes->getCollection()->transform(function ($estudiante) {
                    $estudiante->antiguedad = $this->calcularAntiguedad($estudiante);
                    return $estudiante;
                });
            }

            \Log::info('✅ Estudiantes encontrados: ' . $estudiantes->count() . ' de ' . $estudiantes->total());

            return response()->json([
                'success' => true,
                'data' => $estudiantes->items(),
                'meta' => [
                    'total' => $estudiantes->total(),
                    'per_page' => $estudiantes->perPage(),
                    'current_page' => $estudiantes->currentPage(),
                    'last_page' => $estudiantes->lastPage(),
                    'from' => $estudiantes->firstItem(),
                    'to' => $estudiantes->lastItem()
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('❌ Error al listar estudiantes: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estudiantes: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calcular antigüedad del estudiante
     */
   private function calcularAntiguedad($estudiante)
{
    if (!$estudiante->inscripciones || $estudiante->inscripciones->isEmpty()) {
        return [
            'texto' => 'Sin inscripción',
            'fecha_inicio' => null,
            'dias' => 0,
            'meses' => 0,
            'anos' => 0,
            'renovaciones' => 0,
            'inscripciones_totales' => 0,
            'estado_actual' => 'sin_inscripcion'
        ];
    }

    // Ordenar inscripciones por fecha de inicio
    $inscripcionesOrdenadas = $estudiante->inscripciones->sortBy('fecha_inicio');
    $primeraInscripcion = $inscripcionesOrdenadas->first();
    
    // Calcular tiempo desde la primera inscripción hasta hoy
    $fechaPrimera = Carbon::parse($primeraInscripcion->fecha_inicio);
    $hoy = Carbon::now();
    
    // Calcular días exactos
    $diasTotales = $fechaPrimera->diffInDays($hoy);
    $mesesTotales = $fechaPrimera->diffInMonths($hoy);
    $anosTotales = $fechaPrimera->diffInYears($hoy);
    
    // Calcular renovaciones (inscripciones con estado "renovado" o nuevas después de la primera)
    $renovaciones = 0;
    foreach ($inscripcionesOrdenadas as $index => $inscripcion) {
        if ($index > 0) {
            $renovaciones++;
        }
    }
    
    // Obtener estado de la última inscripción
    $inscripcionActiva = $estudiante->inscripciones->whereIn('estado', ['activo', 'en_mora'])->first();
    $estadoActual = $inscripcionActiva ? $inscripcionActiva->estado : 'finalizado';
    
    // Formatear texto de antigüedad
    $textoAntiguedad = '';
    if ($diasTotales < 1) {
        $horas = $fechaPrimera->diffInHours($hoy);
        $textoAntiguedad = $horas . ($horas === 1 ? ' hora' : ' horas');
    } elseif ($diasTotales < 30) {
        $textoAntiguedad = $diasTotales . ($diasTotales === 1 ? ' día' : ' días');
    } elseif ($mesesTotales < 12) {
        $textoAntiguedad = $mesesTotales . ($mesesTotales === 1 ? ' mes' : ' meses');
        $diasRestantes = $diasTotales - ($mesesTotales * 30);
        if ($diasRestantes > 0) {
            $textoAntiguedad .= ' ' . $diasRestantes . ($diasRestantes === 1 ? ' día' : ' días');
        }
    } else {
        $textoAntiguedad = $anosTotales . ($anosTotales === 1 ? ' año' : ' años');
        $mesesRestantes = $mesesTotales - ($anosTotales * 12);
        if ($mesesRestantes > 0) {
            $textoAntiguedad .= ' ' . $mesesRestantes . ($mesesRestantes === 1 ? ' mes' : ' meses');
        }
    }

    return [
        'texto' => $textoAntiguedad,
        'fecha_inicio' => $fechaPrimera->format('d/m/Y'),
        'dias' => $diasTotales,
        'meses' => $mesesTotales,
        'anos' => $anosTotales,
        'renovaciones' => $renovaciones,
        'inscripciones_totales' => $estudiante->inscripciones->count(),
        'estado_actual' => $estadoActual,
        'fecha_calculo' => $hoy->format('d/m/Y H:i:s')
    ];
}

    public function store(Request $request)
    {
        $request->validate([
            'nombres' => 'required|string|max:255',
            'apellidos' => 'required|string|max:255',
            'ci' => 'required|string|unique:estudiantes',
            'correo' => 'nullable|email|unique:estudiantes',
            'telefono' => 'nullable|string',
            'direccion' => 'nullable|string',
            'fecha_nacimiento' => 'nullable|date',
            'estado' => 'required|in:activo,inactivo',
            'sucursal_id' => 'nullable|exists:sucursales,id'
        ]);

        $estudiante = Estudiante::create($request->all());
        return response()->json($estudiante, 201);
    }

    public function show($id)
    {
        return Estudiante::with('sucursal')->findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $estudiante = Estudiante::findOrFail($id);

        $request->validate([
            'nombres' => 'required|string|max:255',
            'apellidos' => 'required|string|max:255',
            'ci' => 'required|string|unique:estudiantes,ci,' . $id,
            'correo' => 'nullable|email|unique:estudiantes,correo,' . $id,
            'telefono' => 'nullable|string',
            'direccion' => 'nullable|string',
            'fecha_nacimiento' => 'nullable|date',
            'estado' => 'required|in:activo,inactivo',
            'sucursal_id' => 'nullable|exists:sucursales,id'
        ]);

        $estudiante->update($request->all());
        return response()->json($estudiante);
    }

    public function destroy($id)
    {
        $estudiante = Estudiante::find($id);

        if (!$estudiante) {
            return response()->json(['message' => 'Estudiante no encontrado'], 404);
        }

        $estudiante->delete();

        return response()->json(['message' => 'Estudiante eliminado']);
    }

    /**
     * Endpoint específico para obtener antigüedad de un estudiante
     */
    public function antiguedad($id)
    {
        $estudiante = Estudiante::with('inscripciones')->find($id);
        
        if (!$estudiante) {
            return response()->json(['message' => 'Estudiante no encontrado'], 404);
        }

        return response()->json([
            'success' => true,
            'estudiante_id' => $estudiante->id,
            'nombre_completo' => $estudiante->nombres . ' ' . $estudiante->apellidos,
            'antiguedad' => $this->calcularAntiguedad($estudiante)
        ]);
    }
}