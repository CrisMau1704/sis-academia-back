<?php
// app/Http/Controllers/Public/PublicHorarioController.php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Horario;
use Illuminate\Http\Request;

class PublicHorarioController extends Controller
{
    /**
     * Obtener horarios activos para la landing page
     * Endpoint público - NO requiere autenticación
     */
    public function index(Request $request)
    {
        $query = Horario::where('estado', 'activo')
            ->with(['modalidad:id,nombre', 'entrenador:id,nombres,apellidos', 'sucursal:id,nombre'])
            ->select('id', 'modalidad_id', 'entrenador_id', 'sucursal_id', 'dia_semana', 'hora_inicio', 'hora_fin', 'nombre', 'cupo_maximo', 'cupo_actual');

        // Filtrar por modalidad si se especifica
        if ($request->has('modalidad_id')) {
            $query->where('modalidad_id', $request->modalidad_id);
        }

        $horarios = $query->get();

        // Calcular cupo disponible
        $horarios = $horarios->map(function($horario) {
            $horario->cupo_disponible = $horario->cupo_maximo - $horario->cupo_actual;
            $horario->nombre_horario = $horario->nombre ?: 
                $horario->dia_semana . ' ' . substr($horario->hora_inicio, 0, 5) . ' - ' . substr($horario->hora_fin, 0, 5);
            return $horario;
        });

        return response()->json([
            'success' => true,
            'data' => $horarios
        ]);
    }

    /**
     * Obtener horarios por modalidad
     */
    public function porModalidad($modalidadId)
    {
        $horarios = Horario::where('estado', 'activo')
            ->where('modalidad_id', $modalidadId)
            ->with(['entrenador:id,nombres,apellidos', 'sucursal:id,nombre'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $horarios
        ]);
    }
}