<?php

namespace App\Http\Controllers;

use App\Models\Pago;
use App\Models\Inscripcion; // Si necesitas esta relación
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log; // Para depuración

class PagoController extends Controller
{
   public function index(Request $request)
{
    $query = Pago::query();
    
    // FILTRO por inscripción_id
    if ($request->has('inscripcion_id')) {
        $query->where('inscripcion_id', $request->inscripcion_id);
    }
    
    // Otros filtros...
    
    $pagos = $query->orderBy('created_at', 'desc')->get();
    
    return response()->json([
        'success' => true,
        'data' => $pagos  // ¡IMPORTANTE: devolver como array en 'data'!
    ]);
}
    
public function store(Request $request)
{
    // Para depurar lo que recibes
    Log::info('Datos recibidos en PagoController@store:', $request->all());
    
    // ========== VALIDACIÓN MEJORADA ==========
    $validated = $request->validate([
        'inscripcion_id' => 'required|exists:inscripciones,id',
        'monto' => 'required|numeric|min:0.01',
        'metodo_pago' => 'nullable|string|in:efectivo,qr,tarjeta,transferencia',
        'fecha_pago' => 'nullable|date',
        'fecha_vencimiento' => 'nullable|date',
        'estado' => 'required|string|in:pagado,pendiente,anulado,vencido',
        'observacion' => 'nullable|string|max:500',
        
        // Campos para pagos parciales
        'es_parcial' => 'nullable|boolean',
        'pago_grupo_id' => 'nullable|string', // Cambiar a string
        'numero_cuota' => 'nullable|integer|min:1|max:2'
    ]);
    
    try {
        // ========== 1. OBTENER INSCRIPCIÓN ==========
        $inscripcion = Inscripcion::with('pagos')->find($request->inscripcion_id);
        
        if (!$inscripcion) {
            return response()->json([
                'success' => false,
                'message' => 'La inscripción no existe'
            ], 422);
        }
        
        // ========== 2. VALIDACIÓN ESPECÍFICA PARA SEGUNDA CUOTA ==========
        if ($request->es_parcial && $request->numero_cuota == 2) {
            // Buscar la primera cuota
            $primeraCuota = Pago::where('inscripcion_id', $request->inscripcion_id)
                ->where('es_parcial', true)
                ->where('numero_cuota', 1)
                ->where('estado', 'pagado')
                ->first();
            
            if (!$primeraCuota) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró la primera cuota pagada. Debe pagar la primera cuota primero.'
                ], 422);
            }
            
            // ========== ¡¡¡CORRECCIÓN IMPORTANTE!!! ==========
            // Calcular el monto CORRECTO para la segunda cuota
            $montoPrimeraCuota = floatval($primeraCuota->monto);
            $montoTotalInscripcion = floatval($inscripcion->monto_mensual); // O el campo correcto
            
            // El monto de la segunda cuota DEBE SER: Total - PrimeraCuota
            $montoEsperadoSegundaCuota = $montoTotalInscripcion - $montoPrimeraCuota;
            
            Log::info('Validación segunda cuota:', [
                'monto_total' => $montoTotalInscripcion,
                'primera_cuota' => $montoPrimeraCuota,
                'segunda_cuota_esperada' => $montoEsperadoSegundaCuota,
                'segunda_cuota_recibida' => $request->monto
            ]);
            
            // Validar que el monto sea correcto
            if (abs(floatval($request->monto) - $montoEsperadoSegundaCuota) > 0.01) {
                return response()->json([
                    'success' => false,
                    'message' => "Monto incorrecto para segunda cuota. Debe ser: $".number_format($montoEsperadoSegundaCuota, 2) .
                                 ". Primera cuota: $".number_format($montoPrimeraCuota, 2) .
                                 ". Total: $".number_format($montoTotalInscripcion, 2)
                ], 422);
            }
            
            // Verificar que el pago_grupo_id coincida
            if ($request->pago_grupo_id != $primeraCuota->pago_grupo_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'El grupo de pago no coincide con la primera cuota'
                ], 422);
            }
            
            // Verificar que no exista ya la segunda cuota
            $segundaCuotaExistente = Pago::where('inscripcion_id', $request->inscripcion_id)
                ->where('es_parcial', true)
                ->where('numero_cuota', 2)
                ->where('estado', 'pagado')
                ->exists();
            
            if ($segundaCuotaExistente) {
                return response()->json([
                    'success' => false,
                    'message' => 'La segunda cuota ya fue pagada anteriormente'
                ], 422);
            }
        }
        
        // ========== 3. VALIDACIÓN PARA PRIMERA CUOTA ==========
        if ($request->es_parcial && $request->numero_cuota == 1) {
            // Verificar que no exista ya una primera cuota pagada
            $primeraCuotaExistente = Pago::where('inscripcion_id', $request->inscripcion_id)
                ->where('es_parcial', true)
                ->where('numero_cuota', 1)
                ->where('estado', 'pagado')
                ->first();
            
            if ($primeraCuotaExistente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe una primera cuota pagada para esta inscripción'
                ], 422);
            }
            
            // Validar que la primera cuota no sea mayor al total
            if (floatval($request->monto) >= floatval($inscripcion->monto_mensual)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Si el pago es igual o mayor al total, no marque como parcial. Marque como pago completo.'
                ], 422);
            }
        }
        
        // ========== 4. CALCULAR SALDO PENDIENTE ACTUAL ==========
        $totalPagado = $inscripcion->pagos()
            ->where('estado', 'pagado')
            ->sum('monto');
        
        $saldoPendiente = max(0, $inscripcion->monto_mensual - $totalPagado);
        
        Log::info('Estado financiero actual:', [
            'total_inscripcion' => $inscripcion->monto_mensual,
            'total_pagado' => $totalPagado,
            'saldo_pendiente' => $saldoPendiente,
            'nuevo_pago' => $request->monto
        ]);
        
        // ========== 5. VALIDAR QUE NO SE PAGUE DE MÁS ==========
        $nuevoTotalPagado = $totalPagado + floatval($request->monto);
        
        if ($nuevoTotalPagado > $inscripcion->monto_mensual) {
            return response()->json([
                'success' => false,
                'message' => "No puede pagar más del total. Total: $".$inscripcion->monto_mensual.
                             ", Ya pagado: $".$totalPagado.
                             ", Máximo permitido: $".($inscripcion->monto_mensual - $totalPagado)
            ], 422);
        }
        
        // ========== 6. PREPARAR DATOS DEL PAGO ==========
        $pagoData = [
            'inscripcion_id' => $request->inscripcion_id,
            'monto' => $request->monto,
            'metodo_pago' => $request->metodo_pago,
            'fecha_pago' => $request->fecha_pago,
            'fecha_vencimiento' => $request->fecha_vencimiento,
            'estado' => $request->estado,
            'observacion' => $request->observacion,
            'es_parcial' => $request->es_parcial ?? false,
            'numero_cuota' => $request->numero_cuota ?? 1
        ];
        
        // ========== 7. MANEJAR PAGO_GRUPO_ID ==========
        if ($request->filled('pago_grupo_id')) {
            $pagoData['pago_grupo_id'] = (string) $request->pago_grupo_id;
        } elseif ($request->es_parcial && $request->numero_cuota == 1) {
            // Generar ID único para el grupo
            $pagoData['pago_grupo_id'] = (string) (time() . rand(1000, 9999));
        }
        
        // ========== 8. CREAR EL PAGO ==========
        $pago = Pago::create($pagoData);
        
        Log::info('Pago creado exitosamente:', $pago->toArray());
        
        // ========== 9. SI ES SEGUNDA CUOTA, ACTUALIZAR OBSERVACIÓN ==========
        if ($request->es_parcial && $request->numero_cuota == 2) {
            $pago->observacion = "Segunda cuota pagada. " . ($pago->observacion ?? '');
            $pago->save();
            
            Log::info('Segunda cuota registrada:', [
                'inscripcion_id' => $inscripcion->id,
                'monto' => $pago->monto,
                'total_pagado' => $nuevoTotalPagado,
                'completado' => $nuevoTotalPagado >= $inscripcion->monto_mensual
            ]);
        }
        
        // ========== 10. ACTUALIZAR ESTADO DE LA INSCRIPCIÓN ==========
        $this->actualizarEstadoInscripcion($inscripcion->id);
        
        // ========== 11. RESPONDER CON DATOS COMPLETOS ==========
        return response()->json([
            'success' => true,
            'message' => 'Pago registrado exitosamente',
            'data' => [
                'pago' => $pago,
                'inscripcion' => $inscripcion->fresh(),
                'estado_financiero' => [
                    'total_inscripcion' => $inscripcion->monto_mensual,
                    'total_pagado' => $nuevoTotalPagado,
                    'saldo_pendiente' => max(0, $inscripcion->monto_mensual - $nuevoTotalPagado),
                    'completado' => $nuevoTotalPagado >= $inscripcion->monto_mensual
                ]
            ]
        ], 201);
        
    } catch (\Illuminate\Validation\ValidationException $e) {
        Log::error('Error de validación en pago: ' . json_encode($e->errors()));
        return response()->json([
            'success' => false,
            'message' => 'Errores de validación',
            'errors' => $e->errors()
        ], 422);
        
    } catch (\Exception $e) {
        Log::error('Error al crear pago: ' . $e->getMessage());
        Log::error('Trace completo:', ['trace' => $e->getTraceAsString()]);
        
        return response()->json([
            'success' => false,
            'message' => 'Error al registrar el pago: ' . $e->getMessage(),
            'error_details' => env('APP_DEBUG') ? [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ] : null
        ], 500);
    }
}

// Método para actualizar estado de inscripción
// Método para actualizar estado de inscripción
private function actualizarEstadoInscripcion($inscripcionId)
{
    try {
        $inscripcion = Inscripcion::with('pagos')->find($inscripcionId);
        
        if (!$inscripcion) return;
        
        // Calcular total pagado
        $totalPagado = $inscripcion->pagos()
            ->where('estado', 'pagado')
            ->sum('monto');
        
        $montoTotal = $inscripcion->monto_mensual;
        $saldoPendiente = max(0, $montoTotal - $totalPagado);
        
        Log::info('Actualizando estado inscripción:', [
            'id' => $inscripcionId,
            'total_pagado' => $totalPagado,
            'monto_total' => $montoTotal,
            'saldo_pendiente' => $saldoPendiente
        ]);
        
        // Determinar nuevo estado
        $nuevoEstado = $inscripcion->estado;
        
        if ($saldoPendiente <= 0) {
            // Completamente pagado
            $nuevoEstado = 'activo';
            Log::info('Inscripción completamente pagada, estado: activo');
        } else {
            // Hay saldo pendiente
            // Verificar si hay pagos vencidos
            $pagosVencidos = $inscripcion->pagos()
                ->where('estado', 'pendiente')
                ->where('fecha_vencimiento', '<', now())
                ->exists();
            
            if ($pagosVencidos) {
                $nuevoEstado = 'en_mora';
                Log::info('Hay pagos vencidos, estado: en_mora');
            } elseif ($inscripcion->estado === 'activo' && $saldoPendiente > 0) {
                // Si estaba activa pero ahora tiene saldo
                $nuevoEstado = 'en_mora';
                Log::info('Saldo pendiente detectado, estado: en_mora');
            }
        }
        
        // Actualizar si cambió
        if ($nuevoEstado !== $inscripcion->estado) {
            $inscripcion->estado = $nuevoEstado;
            $inscripcion->save();
            
            Log::info('Estado de inscripción actualizado:', [
                'id' => $inscripcionId,
                'estado_anterior' => $inscripcion->getOriginal('estado'),
                'estado_nuevo' => $nuevoEstado
            ]);
        }
        
    } catch (\Exception $e) {
        Log::error('Error actualizando estado de inscripción: ' . $e->getMessage());
    }
}

// ========== MÉTODO PARA ACTUALIZAR ESTADO DE INSCRIPCIÓN ==========

    
    public function show($id)
    { 
        $pago = Pago::with('inscripcion')->findOrFail($id);
        
        return response()->json([
            'success' => true,
            'data' => $pago
        ]);
    }

    // En App/Http/Controllers/PagoController.php
public function porInscripcion($inscripcion_id)
{
    try {
        Log::info("Obteniendo pagos para inscripción: $inscripcion_id");
        
        // Obtener pagos SIMPLES - SIN relaciones
        $pagos = Pago::where('inscripcion_id', $inscripcion_id)
                    ->orderBy('created_at', 'desc')
                    ->get();
        
        Log::info("Encontrados " . $pagos->count() . " pagos");
        
        return response()->json([
            'success' => true,
            'data' => $pagos
        ]);
        
    } catch (\Exception $e) {
        Log::error('Error en porInscripcion: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener pagos: ' . $e->getMessage()
        ], 500);
    }
}
    
    public function update(Request $request, $id)
    {
        $pago = Pago::findOrFail($id);
        
        $request->validate([
            'monto' => 'numeric|min:0.01',
            'metodo_pago' => 'string|in:efectivo,qr,tarjeta,transferencia',
            'fecha_pago' => 'date',
            'estado' => 'string',
            'observacion' => 'nullable|string|max:500'
        ]);
        
        $pago->update($request->only([
            'monto', 'metodo_pago', 'fecha_pago', 'estado', 'observacion'
        ]));
        
        return response()->json([
            'success' => true,
            'message' => 'Pago actualizado exitosamente',
            'data' => $pago
        ]);
    }
    
    public function destroy($id)
    {
        $pago = Pago::findOrFail($id);
        $pago->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Pago eliminado exitosamente'
        ]);
    }
}