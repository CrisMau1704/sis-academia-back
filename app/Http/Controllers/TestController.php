<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;  // ¡ESTO FALTABA!
use App\Mail\NotificacionClasesBajas;
use Illuminate\Support\Facades\Mail;

class TestController extends Controller  // ¡ESTO FALTABA!
{
    /**
     * Envía un correo de prueba
     */
    public function sendTestEmail(Request $request)
    {
        try {
            // 1. Usa datos de prueba
            $datos = [
                'nivel' => 'critico',
                'clases_restantes' => 2,
                'asunto' => 'PRUEBA desde Render - ' . config('app.name')
            ];

            // 2. Crea objetos de prueba
            $estudiante = (object) [
                'id' => 1,  // ¡AGREGA ESTO!
                'nombres' => 'Usuario',
                'apellidos' => 'Prueba',
                'correo' => 'typ.infoactiva@gmail.com', // ← CAMBIA POR TU CORREO
                'ci' => '0000000'
            ];

            $inscripcion = (object) [
                'id' => 1,  // ¡AGREGA ESTO!
                'clases_asistidas' => 10,
                'clases_totales' => 12,
                'modalidad' => (object) ['nombre' => 'Modalidad Test'],
                'fecha_inicio' => now()->format('Y-m-d'),
                'fecha_fin' => now()->addMonth()->format('Y-m-d'),
                'estado' => 'activo',
                'permisos_disponibles' => 3
            ];

            // 3. Envía el correo
            Mail::to($estudiante->correo)
                ->send(new NotificacionClasesBajas($estudiante, $inscripcion, $datos));

            return response()->json([
                'success' => true, 
                'message' => '✅ Correo de prueba enviado a: ' . $estudiante->correo
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en sendTestEmail: ' . $e->getMessage());
            return response()->json([
                'success' => false, 
                'message' => '❌ Error: ' . $e->getMessage()
            ], 500);
        }
    }
}