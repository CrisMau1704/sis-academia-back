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
        'data' => $pagos  
    ]);
}
    
public function store(Request $request)
{
    // Para depurar lo que recibes
    Log::info('Datos recibidos en PagoController@store:', $request->all());
    
    // ========== VALIDACIÓN MEJORADA CON CAMPOS DE DESCUENTO ==========
    $validated = $request->validate([
        'inscripcion_id' => 'required|exists:inscripciones,id',
        'monto' => 'required|numeric|min:0.01',
        
        // CAMPOS DE DESCUENTO NUEVOS
        'descuento_porcentaje' => 'nullable|numeric|min:0|max:100',
        'descuento_monto' => 'nullable|numeric|min:0',
        'subtotal' => 'nullable|numeric|min:0',
        'total_final' => 'nullable|numeric|min:0',
        
        'metodo_pago' => 'nullable|string|in:efectivo,qr,tarjeta,transferencia',
        'fecha_pago' => 'nullable|date',
        'fecha_vencimiento' => 'nullable|date',
        'estado' => 'required|string|in:pagado,pendiente,anulado,vencido',
        'observacion' => 'nullable|string|max:500',
        
        // Campos para pagos parciales
        'es_parcial' => 'nullable|boolean',
        'pago_grupo_id' => 'nullable|string',
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
        
        // ========== 2. CALCULAR MONTO REAL A PAGAR (CONSIDERANDO DESCUENTOS EXISTENTES) ==========
        $montoRealAPagar = $inscripcion->monto_mensual; // Precio original por defecto
        
        // Verificar si ya hay pagos con descuento para esta inscripción
        $pagoConDescuentoExistente = Pago::where('inscripcion_id', $request->inscripcion_id)
            ->where(function($query) {
                $query->where('descuento_monto', '>', 0)
                    ->orWhere('descuento_porcentaje', '>', 0);
            })
            ->first();
        
        if ($pagoConDescuentoExistente) {
            // Si ya existe un pago con descuento, usar ESE total_final
            $montoRealAPagar = $pagoConDescuentoExistente->total_final;
            Log::info('Usando descuento existente de pago #' . $pagoConDescuentoExistente->id, [
                'total_final_existente' => $montoRealAPagar,
                'monto_original' => $inscripcion->monto_mensual
            ]);
        }
        
        // ========== 3. CALCULAR CAMPOS DE DESCUENTO PARA ESTE PAGO ==========
        $descuentoPorcentaje = $request->descuento_porcentaje ?? 0;
        $descuentoMonto = $request->descuento_monto ?? 0;
        
        // Determinar subtotal (precio sin descuento)
        $subtotal = $request->subtotal ?? $inscripcion->monto_mensual;
        
        // Si el frontend NO envió subtotal, calcularlo
        if (!$request->has('subtotal')) {
            $subtotal = $inscripcion->monto_mensual;
        }
        
        // Calcular descuento si se proporcionó porcentaje
        if ($descuentoPorcentaje > 0 && $descuentoMonto == 0) {
            $descuentoMonto = $subtotal * ($descuentoPorcentaje / 100);
        }
        
        // Calcular porcentaje si se proporcionó monto
        if ($descuentoMonto > 0 && $descuentoPorcentaje == 0) {
            $descuentoPorcentaje = ($descuentoMonto / $subtotal) * 100;
        }
        
        // Calcular total final
        $totalFinal = $subtotal - $descuentoMonto;
        
        // Si el frontend envió total_final, usarlo (puede tener diferencias por redondeo)
        if ($request->has('total_final') && $request->total_final > 0) {
            $totalFinal = $request->total_final;
        }
        
        Log::info('Cálculos de descuento:', [
            'subtotal' => $subtotal,
            'descuento_porcentaje' => $descuentoPorcentaje,
            'descuento_monto' => $descuentoMonto,
            'total_final_calculado' => $totalFinal,
            'monto_recibido' => $request->monto,
            'monto_real_a_pagar' => $montoRealAPagar
        ]);
        
        // ========== 4. VALIDAR QUE EL MONTO SEA CORRECTO ==========
        // Si hay descuento en ESTE pago, el monto debe ser <= total_final
        if ($descuentoMonto > 0 || $descuentoPorcentaje > 0) {
            if (abs(floatval($request->monto) - $totalFinal) > 0.01) {
                Log::warning('Posible error: El monto no coincide con el total final calculado', [
                    'monto_recibido' => $request->monto,
                    'total_final_calculado' => $totalFinal,
                    'diferencia' => abs(floatval($request->monto) - $totalFinal)
                ]);
            }
        }
        
        // ========== 5. VALIDACIÓN ESPECÍFICA PARA SEGUNDA CUOTA ==========
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
            
            // Calcular el monto CORRECTO para la segunda cuota
            $montoPrimeraCuota = floatval($primeraCuota->monto);
            
            // IMPORTANTE: Usar el montoRealAPagar que ya considera descuentos
            $montoEsperadoSegundaCuota = $montoRealAPagar - $montoPrimeraCuota;
            
            Log::info('Validación segunda cuota:', [
                'monto_real_a_pagar' => $montoRealAPagar,
                'primera_cuota' => $montoPrimeraCuota,
                'segunda_cuota_esperada' => $montoEsperadoSegundaCuota,
                'segunda_cuota_recibida' => $request->monto
            ]);
            
            // Validar que el monto sea correcto
            $tolerancia = 0.01;
            if (abs(floatval($request->monto) - $montoEsperadoSegundaCuota) > $tolerancia) {
                return response()->json([
                    'success' => false,
                    'message' => "Monto incorrecto para segunda cuota. Debe ser: $".number_format($montoEsperadoSegundaCuota, 2) .
                                 ". Primera cuota: $".number_format($montoPrimeraCuota, 2) .
                                 ". Total con descuento: $".number_format($montoRealAPagar, 2)
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
        
        // ========== 6. VALIDACIÓN PARA PRIMERA CUOTA ==========
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
            
            // Validar que la primera cuota no sea mayor al total REAL (con descuento)
            if (floatval($request->monto) >= $montoRealAPagar) {
                return response()->json([
                    'success' => false,
                    'message' => 'Si el pago es igual o mayor al total, no marque como parcial. Marque como pago completo.'
                ], 422);
            }
        }
        
        // ========== 7. CALCULAR SALDO PENDIENTE ACTUAL ==========
        $totalPagado = $inscripcion->pagos()
            ->where('estado', 'pagado')
            ->sum('monto');
        
        // IMPORTANTE: Usar montoRealAPagar en lugar de monto_mensual
        $saldoPendiente = max(0, $montoRealAPagar - $totalPagado);
        
        Log::info('Estado financiero actual:', [
            'monto_original' => $inscripcion->monto_mensual,
            'monto_real_a_pagar' => $montoRealAPagar,
            'total_pagado' => $totalPagado,
            'saldo_pendiente' => $saldoPendiente,
            'nuevo_pago' => $request->monto,
            'tiene_descuento_este_pago' => $descuentoMonto > 0 || $descuentoPorcentaje > 0,
            'tiene_descuento_existente' => $pagoConDescuentoExistente ? 'SI' : 'NO'
        ]);
        
        // ========== 8. VALIDAR QUE NO SE PAGUE DE MÁS ==========
        $nuevoTotalPagado = $totalPagado + floatval($request->monto);
        
        // Usar montoRealAPagar que ya considera descuentos
        if ($nuevoTotalPagado > $montoRealAPagar) {
            return response()->json([
                'success' => false,
                'message' => "No puede pagar más del total. " . 
                             ($pagoConDescuentoExistente ? "Total con descuento" : "Total") . 
                             ": $".number_format($montoRealAPagar, 2).
                             ", Ya pagado: $".number_format($totalPagado, 2).
                             ", Máximo permitido: $".number_format(($montoRealAPagar - $totalPagado), 2)
            ], 422);
        }
        
        // ========== 9. PREPARAR DATOS COMPLETOS DEL PAGO ==========
        $pagoData = [
            'inscripcion_id' => $request->inscripcion_id,
            'monto' => $request->monto,
            
            // CAMPOS DE DESCUENTO
            'descuento_porcentaje' => $descuentoPorcentaje,
            'descuento_monto' => $descuentoMonto,
            'subtotal' => $subtotal,
            'total_final' => $totalFinal,
            
            'metodo_pago' => $request->metodo_pago,
            'fecha_pago' => $request->fecha_pago,
            'fecha_vencimiento' => $request->fecha_vencimiento,
            'estado' => $request->estado,
            'observacion' => $request->observacion,
            'es_parcial' => $request->es_parcial ?? false,
            'numero_cuota' => $request->numero_cuota ?? 1
        ];
        
        // ========== 10. MANEJAR PAGO_GRUPO_ID ==========
        if ($request->filled('pago_grupo_id')) {
            $pagoData['pago_grupo_id'] = (string) $request->pago_grupo_id;
        } elseif ($request->es_parcial && $request->numero_cuota == 1) {
            // Generar ID único para el grupo
            $pagoData['pago_grupo_id'] = (string) (time() . rand(1000, 9999));
        }
        
        // ========== 11. AGREGAR INFO DE DESCUENTO A LA OBSERVACIÓN ==========
        if ($descuentoMonto > 0 || $descuentoPorcentaje > 0) {
            $observacionDescuento = "Descuento aplicado: ";
            
            if ($descuentoPorcentaje > 0) {
                $observacionDescuento .= "{$descuentoPorcentaje}% (-$".number_format($descuentoMonto, 2).")";
            } else {
                $observacionDescuento .= "$".number_format($descuentoMonto, 2);
            }
            
            $observacionDescuento .= ". Subtotal: $".number_format($subtotal, 2);
            
            $pagoData['observacion'] = ($pagoData['observacion'] ?? '') . " " . $observacionDescuento;
            $pagoData['observacion'] = trim($pagoData['observacion']);
            
            // Si este es el PRIMER pago con descuento, actualizar montoRealAPagar para esta inscripción
            if (!$pagoConDescuentoExistente) {
                Log::info('Primer pago con descuento para inscripción #' . $inscripcion->id, [
                    'nuevo_total_final' => $totalFinal,
                    'monto_original' => $inscripcion->monto_mensual
                ]);
            }
        }
        
        // ========== 12. CREAR EL PAGO ==========
        $pago = Pago::create($pagoData);
        
        Log::info('Pago creado exitosamente:', [
            'id' => $pago->id,
            'monto' => $pago->monto,
            'descuento_monto' => $pago->descuento_monto,
            'descuento_porcentaje' => $pago->descuento_porcentaje,
            'subtotal' => $pago->subtotal,
            'total_final' => $pago->total_final,
            'observacion' => $pago->observacion
        ]);
        
        // ========== 13. SI ES SEGUNDA CUOTA, ACTUALIZAR OBSERVACIÓN ==========
        if ($request->es_parcial && $request->numero_cuota == 2) {
            $pago->observacion = "Segunda cuota pagada. " . ($pago->observacion ?? '');
            $pago->save();
        }
        
        // ========== 14. ACTUALIZAR ESTADO DE LA INSCRIPCIÓN ==========
        $this->actualizarEstadoInscripcion($inscripcion->id);
        
        // ========== 15. RESPONDER CON DATOS COMPLETOS ==========
        return response()->json([
            'success' => true,
            'message' => 'Pago registrado exitosamente',
            'data' => [
                'pago' => $pago->refresh(), // Recargar con relaciones
                'inscripcion' => $inscripcion->fresh(),
                'descuento_aplicado' => [
                    'porcentaje' => $descuentoPorcentaje,
                    'monto' => $descuentoMonto,
                    'subtotal' => $subtotal,
                    'total_final' => $totalFinal
                ],
                'estado_financiero' => [
                    'monto_original' => $inscripcion->monto_mensual,
                    'monto_real_a_pagar' => $montoRealAPagar,
                    'total_pagado' => $nuevoTotalPagado,
                    'saldo_pendiente' => max(0, $montoRealAPagar - $nuevoTotalPagado),
                    'completado' => ($nuevoTotalPagado >= $montoRealAPagar)
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

private function actualizarEstadoInscripcion($inscripcionId)
{
    try {
        $inscripcion = Inscripcion::with(['pagos' => function($query) {
            $query->where('estado', 'pagado')
                  ->orderBy('created_at', 'desc');
        }])->find($inscripcionId);
        
        if (!$inscripcion) return;
        
        // ========== 1. CALCULAR MONTO REAL A PAGAR ==========
        $montoRealAPagar = $inscripcion->monto_mensual;
        
        // Buscar pagos que indiquen "PAGO COMPLETO" en la observación
        $pagoCompleto = $inscripcion->pagos
            ->first(function($pago) {
                return stripos($pago->observacion, 'pago completo') !== false ||
                       stripos($pago->observacion, 'completamente pagado') !== false;
            });
        
        if ($pagoCompleto) {
            // Si hay un pago marcado como completo, usar ESE monto como total
            $montoRealAPagar = $pagoCompleto->monto;
            Log::info('Encontrado pago completo:', [
                'pago_id' => $pagoCompleto->id,
                'observacion' => $pagoCompleto->observacion,
                'monto' => $pagoCompleto->monto
            ]);
        }
        
        // ========== 2. CALCULAR TOTAL PAGADO ==========
        $totalPagado = $inscripcion->pagos->sum('monto');
        $saldoPendiente = max(0, $montoRealAPagar - $totalPagado);
        
        // ========== 3. DETERMINAR ESTADO ==========
        $nuevoEstado = 'activo';
        
        // Si hay un pago marcado como "COMPLETO" y se pagó ese monto exacto
        if ($pagoCompleto && abs($totalPagado - $montoRealAPagar) <= 0.01) {
            $nuevoEstado = 'activo';
            Log::info('Inscripción completamente pagada (con descuento)');
        }
        // Si no hay pago completo marcado pero se pagó el monto original
        elseif (!$pagoCompleto && $totalPagado >= $inscripcion->monto_mensual) {
            $nuevoEstado = 'activo';
            Log::info('Inscripción completamente pagada (sin descuento)');
        }
        // Si hay saldo pendiente
        elseif ($saldoPendiente > 0.01) {
            $nuevoEstado = 'en_mora';
            Log::info('Saldo pendiente: ' . $saldoPendiente);
        }
        
        // ========== 4. ACTUALIZAR ==========
        if ($nuevoEstado !== $inscripcion->estado) {
            $inscripcion->estado = $nuevoEstado;
            $inscripcion->save();
        }
        
    } catch (\Exception $e) {
        Log::error('Error actualizando estado: ' . $e->getMessage());
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

   public function porEstudiante($estudianteId)
{
    try {
        // Obtener inscripciones del estudiante
        $inscripciones = Inscripcion::where('estudiante_id', $estudianteId)
            ->pluck('id');
        
        if ($inscripciones->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => []
            ]);
        }
        
        // Consulta CORREGIDA - sin total_reembolsado
        $pagos = Pago::whereIn('inscripcion_id', $inscripciones)
            ->where('estado', 'pagado') // Solo pagos pagados
            ->where(function($query) {
                // Solo pagos que NO tengan reembolso
                $query->where('tiene_reembolso', false)
                      ->orWhereNull('tiene_reembolso');
            })
            ->orderBy('fecha_pago', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $pagos
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener pagos del estudiante: ' . $e->getMessage()
        ], 500);
    }
}


}