<?php
// app/Http/Controllers/Public/PublicSucursalController.php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Sucursal;
use Illuminate\Http\Request;

class PublicSucursalController extends Controller
{
    /**
     * Obtener todas las sucursales activas para la landing page
     */
    public function index()
    {
        try {
            $sucursales = Sucursal::where('estado', 'activa')  // 👈 NOTA: es 'activa' no 'activo'
                ->select('id', 'nombre', 'direccion', 'telefono')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $sucursales
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar sucursales',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener una sucursal específica
     */
    public function show($id)
    {
        try {
            $sucursal = Sucursal::where('estado', 'activa')
                ->select('id', 'nombre', 'direccion', 'telefono')
                ->find($id);

            if (!$sucursal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sucursal no encontrada'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $sucursal
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar la sucursal',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}