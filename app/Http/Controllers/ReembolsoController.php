<?php

namespace App\Http\Controllers;

use App\Models\Reembolso;
use App\Models\Pago;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReembolsoController extends Controller
{
    // Listar todos los reembolsos
    public function index(Request $request)
    {
        $query = Reembolso::with(['pago', 'estudiante', 'inscripcion', 'usuario', 'aprobador']);
        
        // Filtros
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        
        if ($request->filled('estudiante_id')) {
            $query->where('estudiante_id', $request->estudiante_id);
        }
        
        if ($request->filled('inscripcion_id')) {
            $query->where('inscripcion_id', $request->inscripcion_id);
        }
        
        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha_solicitud', '>=', $request->fecha_desde);
        }
        
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha_solicitud', '<=', $request->fecha_hasta);
        }
        
        // Paginación
        $perPage = $request->get('per_page', 20);
        $reembolsos = $query->orderBy('fecha_solicitud', 'desc')->paginate($perPage);
        
        return response()->json([
            'success' => true,
            'data' => $reembolsos
        ]);
    }
    
    // Crear nueva solicitud de reembolso
    public function store(Request $request)
    {
        $request->validate([
            'pago_id' => 'required|exists:pagos,id',
            'porcentaje_reembolso' => 'required|numeric|min:1|max:100',
            'motivo' => 'required|string|min:10|max:500',
            'metodo' => 'required|in:efectivo,transferencia,tarjeta_credito,devolucion_tarjeta,credito_futuro',
            'tipo' => 'required|in:parcial,total,promocional'
        ]);
        
        try {
            DB::beginTransaction();
            
            $pago = Pago::findOrFail($request->pago_id);
            
            // Validar que el pago pueda ser reembolsado
            if (!$pago->puedeReembolsar()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este pago no puede ser reembolsado'
                ], 400);
            }
            
            // Calcular montos
            $montoReembolsado = $pago->monto * ($request->porcentaje_reembolso / 100);
            
            // Crear reembolso
            $reembolso = Reembolso::create([
                'pago_id' => $pago->id,
                'inscripcion_id' => $pago->inscripcion_id,
                'estudiante_id' => $pago->inscripcion->estudiante_id,
                'usuario_id' => auth()->id(),
                'monto_original' => $pago->monto,
                'monto_reembolsado' => $montoReembolsado,
                'porcentaje_reembolso' => $request->porcentaje_reembolso,
                'motivo' => $request->motivo,
                'metodo' => $request->metodo,
                'tipo' => $request->tipo,
                'estado' => 'pendiente',
                'observaciones' => $request->observaciones,
                'datos_originales' => $pago->toArray()
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Solicitud de reembolso creada exitosamente',
                'data' => $reembolso->load(['pago', 'estudiante'])
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear reembolso: ' . $e->getMessage()
            ], 500);
        }
    }
    
    // Aprobar reembolso (solo admin)
    public function aprobar($id, Request $request)
    {
        $request->validate([
            'observaciones' => 'nullable|string|max:500'
        ]);
        
        $reembolso = Reembolso::findOrFail($id);
        
        if (!$reembolso->puedeAprobar()) {
            return response()->json([
                'success' => false,
                'message' => 'Este reembolso no puede ser aprobado'
            ], 400);
        }
        
        $reembolso->aprobar(auth()->id(), $request->observaciones);
        
        return response()->json([
            'success' => true,
            'message' => 'Reembolso aprobado exitosamente',
            'data' => $reembolso
        ]);
    }
    
    // Rechazar reembolso
    public function rechazar($id, Request $request)
    {
        $request->validate([
            'razon_rechazo' => 'required|string|min:10|max:500'
        ]);
        
        $reembolso = Reembolso::findOrFail($id);
        
        if (!$reembolso->puedeRechazar()) {
            return response()->json([
                'success' => false,
                'message' => 'Este reembolso no puede ser rechazado'
            ], 400);
        }
        
        $reembolso->rechazar($request->razon_rechazo);
        
        return response()->json([
            'success' => true,
            'message' => 'Reembolso rechazado',
            'data' => $reembolso
        ]);
    }
    
    // Procesar reembolso (cuando se efectúa el pago)
    public function procesar($id, Request $request)
    {
        $request->validate([
            'comprobante' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'referencia_bancaria' => 'nullable|string|max:100'
        ]);
        
        $reembolso = Reembolso::findOrFail($id);
        
        if (!$reembolso->puedeProcesar()) {
            return response()->json([
                'success' => false,
                'message' => 'Este reembolso no puede ser procesado'
            ], 400);
        }
        
        // Subir comprobante si existe
        $comprobantePath = null;
        if ($request->hasFile('comprobante')) {
            $comprobantePath = $request->file('comprobante')->store('comprobantes/reembolsos', 'public');
        }
        
        $reembolso->procesar($comprobantePath, $request->referencia_bancaria);
        
        return response()->json([
            'success' => true,
            'message' => 'Reembolso procesado exitosamente',
            'data' => $reembolso
        ]);
    }
    
    // Marcar como completado
    public function completar($id)
{
    $reembolso = Reembolso::findOrFail($id);
    
    // CAMBIA ESTO: de 'procesado' a 'aprobado'
    // if ($reembolso->estado !== 'procesado') {
    if ($reembolso->estado !== 'aprobado') {
        return response()->json([
            'success' => false,
            'message' => 'Solo los reembolsos aprobados pueden completarse'
        ], 400);
    }
    
    $reembolso->completar();
    
    return response()->json([
        'success' => true,
        'message' => 'Reembolso completado',
        'data' => $reembolso
    ]);
}
    
    // Obtener reembolsos por estudiante
    public function porEstudiante($estudianteId)
    {
        $reembolsos = Reembolso::porEstudiante($estudianteId)
            ->with(['pago', 'inscripcion'])
            ->orderBy('fecha_solicitud', 'desc')
            ->paginate(20);
        
        return response()->json([
            'success' => true,
            'data' => $reembolsos
        ]);
    }
    
    // Obtener reembolsos por inscripción
    public function porInscripcion($inscripcionId)
    {
        $reembolsos = Reembolso::porInscripcion($inscripcionId)
            ->with(['pago', 'estudiante'])
            ->orderBy('fecha_solicitud', 'desc')
            ->paginate(20);
        
        return response()->json([
            'success' => true,
            'data' => $reembolsos
        ]);
    }
    
    // Estadísticas de reembolsos
    public function estadisticas()
    {
        $totalReembolsos = Reembolso::count();
        $pendientes = Reembolso::pendientes()->count();
        $aprobados = Reembolso::aprobados()->count();
        $completados = Reembolso::completados()->count();
        
        $montoTotalReembolsado = Reembolso::whereIn('estado', ['procesado', 'completado'])
            ->sum('monto_reembolsado');
        
        return response()->json([
            'success' => true,
            'data' => [
                'total_reembolsos' => $totalReembolsos,
                'pendientes' => $pendientes,
                'aprobados' => $aprobados,
                'completados' => $completados,
                'monto_total_reembolsado' => $montoTotalReembolsado,
                'promedio_reembolso' => $totalReembolsos > 0 ? $montoTotalReembolsado / $totalReembolsos : 0
            ]
        ]);
    }
}