<?php
// app/Http/Controllers/Public/PublicModalidadController.php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Modalidad;
use Illuminate\Http\Request;

class PublicModalidadController extends Controller
{
    /**
     * Obtener modalidades activas para la landing page
     * Endpoint público - NO requiere autenticación
     */
    public function index()
    {
        $modalidades = Modalidad::where('estado', 'activo')
            ->select('id', 'nombre', 'descripcion', 'precio_mensual', 'clases_mensuales', 'permisos_maximos')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $modalidades
        ]);
    }

    /**
     * Obtener una modalidad específica
     */
    public function show($id)
    {
        $modalidad = Modalidad::where('estado', 'activo')
            ->find($id);

        if (!$modalidad) {
            return response()->json([
                'success' => false,
                'message' => 'Modalidad no encontrada'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $modalidad
        ]);
    }
}