<?php
namespace App\Http\Controllers;

use App\Models\Modalidad;
use Illuminate\Http\Request;

class ModalidadController extends Controller
{
    public function index() 
{ 
    // Especificar explícitamente los campos que necesitas
    return Modalidad::with('disciplina')
        ->select([
            'id',
            'disciplina_id',
            'nombre',
            'precio_mensual',
            'descripcion',
            'clases_mensuales',
            'permisos_maximos', // ← ASEGURAR que está aquí
            'estado',
            'created_at',
            'updated_at'
        ])
        ->get()
        ->map(function ($modalidad) {
            // Asegurar que los accessors no interfieran
            return [
                'id' => $modalidad->id,
                'disciplina_id' => $modalidad->disciplina_id,
                'nombre' => $modalidad->nombre,
                'precio_mensual' => $modalidad->precio_mensual,
                'descripcion' => $modalidad->descripcion,
                'clases_mensuales'=> $modalidad->clases_mensuales,
                'permisos_maximos' => $modalidad->permisos_maximos, // ← Valor directo de DB
                'estado' => $modalidad->estado,
                'disciplina' => $modalidad->disciplina,
                // Campos calculados si los necesitas
                
               
            ];
        });
}
    
    public function store(Request $request) 
    {
        $request->validate([
            'disciplina_id' => 'required|exists:disciplinas,id',
            'nombre' => 'required|string|max:255',
            'precio_mensual' => 'required|numeric|min:0',
            'clases_mensuales' => 'required|integer|min:0|max:100',
            'permisos_maximos' => 'required|integer|min:0|max:10',
            'estado' => 'required|in:activo,inactivo'
        ]);
        
        return Modalidad::create($request->all());
    }
    
    public function show($id) 
    { 
        return Modalidad::with('disciplina')->findOrFail($id); 
    }
    
    public function update(Request $request, $id)
    {
        $modalidad = Modalidad::findOrFail($id);
        
        $request->validate([
            'disciplina_id' => 'required|exists:disciplinas,id',
            'nombre' => 'required|string|max:255',
            'precio_mensual' => 'required|numeric|min:0',
            'clases_mensuales' => 'required|integer|min:0|max:100',
            'permisos_maximos' => 'required|integer|min:0|max:10',
            'estado' => 'required|in:activo,inactivo'
        ]);
        
        $modalidad->update($request->all());
        return $modalidad;
    }
    
    public function destroy($id)
    {
        Modalidad::findOrFail($id)->delete();
        return response()->noContent();
    }
}