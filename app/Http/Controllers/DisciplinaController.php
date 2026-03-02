<?php
namespace App\Http\Controllers;

use App\Models\Disciplina;
use Illuminate\Http\Request;

class DisciplinaController extends Controller
{
    public function index(Request $request)
{
    $limit = $request->input('limit', 10);
    $q = $request->input('q', '');

    $query = Disciplina::query();

    if ($q) {
        $query->where('nombre', 'like', "%$q%");
    }

    $disciplinas = $query->paginate($limit);

    return response()->json([
        'data' => $disciplinas->items(),
        'total' => $disciplinas->total()
    ]);
}
    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'descripcion' => 'required|string|max:255',
            
        ]);

        $disciplina = Disciplina::create($request->all());
        return response()->json($disciplina, 201);
    }

    public function show($id){ 
        return Disciplina::findOrFail($id); 
        }


    public function update(Request $request,$id){
        $disciplina=Disciplina::findOrFail($id);
        $disciplina->update($request->all());
        return $disciplina;
    }
    
    public function destroy($id){
        Disciplina::findOrFail($id)->delete();
        return response()->noContent();
    }
}
