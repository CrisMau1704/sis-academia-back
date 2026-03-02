<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EstudianteController;
use App\Http\Controllers\ModalidadController;
use App\Http\Controllers\HorarioController;
use App\Http\Controllers\InscripcionController;
use App\Http\Controllers\AsistenciaController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\EntrenadorController;
use App\Http\Controllers\DisciplinaController;
use App\Http\Controllers\PagoController;
use App\Http\Controllers\UserRoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PermisoController;
use App\Http\Controllers\RecuperacionController;
use App\Http\Controllers\ClaseProgramadaController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\ReembolsoController;
use App\Http\Controllers\PreinscripcionController;

use App\Http\Controllers\Public\PublicModalidadController;
use App\Http\Controllers\Public\PublicHorarioController;
use App\Http\Controllers\Public\PublicSucursalController; 

/*
|--------------------------------------------------------------------------
| RUTAS PÚBLICAS (NO REQUIEREN AUTENTICACIÓN)
|--------------------------------------------------------------------------
*/

// Endpoint de estado del servidor
Route::get('/estado-servidor', function () {
    return response()->json([
        'status' => 'online',
        'timestamp' => now()->toDateTimeString(),
        'version' => '1.0.0'
    ]);
});

// Auth público
Route::prefix('v1/auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
});

// 🟢 RUTAS PÚBLICAS PARA LANDING PAGE
Route::prefix('public')->group(function () {
    // Modalidades públicas
    Route::get('/modalidades', [PublicModalidadController::class, 'index']);
    Route::get('/modalidades/{id}', [PublicModalidadController::class, 'show']);
    
    // Horarios públicos
    Route::get('/horarios', [PublicHorarioController::class, 'index']);
    Route::get('/horarios/modalidad/{modalidadId}', [PublicHorarioController::class, 'porModalidad']);
    
    // 👇 NUEVA RUTA DE SUCURSALES
    Route::get('/sucursales', [PublicSucursalController::class, 'index']);
    Route::get('/sucursales/{id}', [PublicSucursalController::class, 'show']);
    
    // Preinscripción (también pública)
    Route::post('/preinscripciones', [PreinscripcionController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| RUTAS PROTEGIDAS (REQUIEREN AUTENTICACIÓN)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // Auth privado
    Route::prefix('v1/auth')->group(function () {
        Route::get('profile', [AuthController::class, 'profile']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    // Test email
    Route::post('/test-email', [TestController::class, 'sendTestEmail']);

    /*
    |--------------------------------------------------------------------------
    | USUARIOS
    |--------------------------------------------------------------------------
    */
    Route::prefix('usuarios')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('/{id}', [UserController::class, 'show']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::delete('/{id}', [UserController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ESTUDIANTES
    |--------------------------------------------------------------------------
    */
    Route::prefix('estudiantes')->group(function () {
        Route::get('/', [EstudianteController::class, 'index']);
        Route::post('/', [EstudianteController::class, 'store']);
        Route::get('/{id}', [EstudianteController::class, 'show']);
        Route::put('/{id}', [EstudianteController::class, 'update']);
        Route::delete('/{id}', [EstudianteController::class, 'destroy']);
        
        // Rutas adicionales
        Route::get('/{id}/inscripciones', [EstudianteController::class, 'inscripciones']);
        Route::get('/{id}/asistencias', [EstudianteController::class, 'asistencias']);
        Route::get('/{id}/pagos', [EstudianteController::class, 'pagos']);
        Route::get('/con-inscripciones-activas', [EstudianteController::class, 'conInscripcionesActivas']);
    });

    /*
    |--------------------------------------------------------------------------
    | SUCURSALES
    |--------------------------------------------------------------------------
    */
    Route::apiResource('sucursales', SucursalController::class);

    /*
    |--------------------------------------------------------------------------
    | ENTRENADORES
    |--------------------------------------------------------------------------
    */
    Route::apiResource('entrenadores', EntrenadorController::class);

    /*
    |--------------------------------------------------------------------------
    | MODALIDADES
    |--------------------------------------------------------------------------
    */
    Route::apiResource('modalidades', ModalidadController::class);

    /*
    |--------------------------------------------------------------------------
    | DISCIPLINAS
    |--------------------------------------------------------------------------
    */
    Route::apiResource('disciplinas', DisciplinaController::class);

    /*
    |--------------------------------------------------------------------------
    | HORARIOS
    |--------------------------------------------------------------------------
    */
    Route::prefix('horarios')->group(function () {
        Route::get('/', [HorarioController::class, 'index']);
        Route::post('/', [HorarioController::class, 'store']);
        Route::get('/disponibles', [HorarioController::class, 'horariosDisponibles']);
        Route::get('/por-dia', [HorarioController::class, 'horariosPorDia']);
        Route::get('/estadisticas', [HorarioController::class, 'estadisticas']);
        Route::get('/{id}', [HorarioController::class, 'show']);
        Route::put('/{id}', [HorarioController::class, 'update']);
        Route::delete('/{id}', [HorarioController::class, 'destroy']);
        Route::put('/{id}/estado', [HorarioController::class, 'cambiarEstado']);
        Route::post('/{id}/incrementar-cupo', [HorarioController::class, 'incrementarCupo']);
        Route::post('/{id}/decrementar-cupo', [HorarioController::class, 'decrementarCupo']);
        Route::get('/modalidad/{modalidadId}', [HorarioController::class, 'porModalidad']);
        
        // Nuevas rutas para el sistema de asistencias
        Route::get('/estudiante/{estudianteId}', [HorarioController::class, 'porEstudiante']);
        Route::get('/disponibles-fecha', [HorarioController::class, 'disponiblesPorFecha']);
        Route::get('/horarios/por-modalidad/{modalidadId}', [HorarioController::class, 'getPorModalidad']);
    });

    Route::prefix('preinscripciones')->group(function () {
        Route::get('/', [PreinscripcionController::class, 'index']);           // Listar
        Route::get('/estadisticas', [PreinscripcionController::class, 'estadisticas']); // Estadísticas
        Route::get('/{id}', [PreinscripcionController::class, 'show']);        // Ver una
        Route::put('/{id}', [PreinscripcionController::class, 'update']);      // Actualizar
        Route::post('/{id}/approve', [PreinscripcionController::class, 'approve']); // Aprobar
        Route::post('/{id}/reject', [PreinscripcionController::class, 'reject']);    // Rechazar
    });

    /*
    |--------------------------------------------------------------------------
    | INSCRIPCIONES
    |--------------------------------------------------------------------------
    */
    Route::prefix('inscripciones')->group(function () {
        Route::get('/', [InscripcionController::class, 'index']);
        Route::get('/preinscripciones', [InscripcionController::class, 'preinscripciones']);
        // 👇 RUTA PARA APROBAR PREINSCRIPCIÓN
        Route::post('/{id}/aprobar', [InscripcionController::class, 'aprobarPreinscripcion']);
        Route::get('/todas', [InscripcionController::class, 'obtenerTodos']);
        Route::post('/', [InscripcionController::class, 'store']);
        Route::get('/{id}', [InscripcionController::class, 'show']);
        Route::put('/{id}', [InscripcionController::class, 'update']);
        Route::delete('/{id}', [InscripcionController::class, 'destroy']);
        Route::post('/{id}/horarios', [InscripcionController::class, 'asociarHorario']);
        Route::delete('/{inscripcionId}/horarios/{horarioId}', [InscripcionController::class, 'desasociarHorario']);
        Route::post('/{id}/renovar', [InscripcionController::class, 'renovar']);
        Route::post('/verificar-vencimientos', [InscripcionController::class, 'verificarVencimientos']);
        
        // Nuevas rutas para asistencias
        Route::get('/activas-hoy', [InscripcionController::class, 'activasHoy']);
        Route::get('/estudiante/{estudianteId}/activas', [InscripcionController::class, 'activasPorEstudiante']);
        Route::get('/{id}/control-clases', [InscripcionController::class, 'controlClases']);
        Route::put('/{id}/actualizar-clases', [InscripcionController::class, 'actualizarContadorClases']);
        Route::get('/estudiante/{estudianteId}/activa', [InscripcionController::class, 'inscripcionActiva']);
        Route::get('/inscripciones/{id}/estado-financiero', [InscripcionController::class, 'estadoFinanciero']);
        
        // Rutas clave para actualizar asistencias
        Route::post('/{id}/incrementar-asistencia', [InscripcionController::class, 'incrementarAsistencia']);
        Route::get('/{id}/horarios', [InscripcionController::class, 'getHorarios']);
        Route::put('/{inscripcionId}/horarios/{horarioId}', [InscripcionController::class, 'actualizarHorarioEspecifico']);
        Route::get('/{id}/estadisticas', [InscripcionController::class, 'estadisticasInscripcion']);
        Route::post('/{id}/completar', [InscripcionController::class, 'completarInscripcion']);
        Route::get('/{id}/clases-restantes', [InscripcionController::class, 'clasesRestantes']);
      
    });

    // Ruta para generar clases al crear inscripción
    Route::post('/inscripciones/{id}/generar-clases-programadas', function ($id) {
        try {
            $inscripcion = \App\Models\Inscripcion::with(['horarios', 'estudiante'])->findOrFail($id);
            
            $clasesExistentes = \App\Models\ClaseProgramada::where('inscripcion_id', $id)->count();
            
            if ($clasesExistentes > 0) {
                return response()->json([
                    'success' => true,
                    'message' => "La inscripción ya tiene {$clasesExistentes} clases generadas",
                    'total_clases' => $clasesExistentes,
                    'inscripcion_id' => $id
                ]);
            }
            
            $totalGeneradas = 0;
            
            $fechaInicio = \Carbon\Carbon::parse($inscripcion->fecha_inicio);
            $fechaFin = \Carbon\Carbon::parse($inscripcion->fecha_fin);
            
            $diasMap = [
                'lunes' => 1, 'martes' => 2, 'miércoles' => 3, 'miercoles' => 3,
                'jueves' => 4, 'viernes' => 5, 'sábado' => 6, 'sabado' => 6,
                'domingo' => 0
            ];
            
            foreach ($inscripcion->horarios as $horario) {
                $diaHorario = strtolower($horario->dia_semana);
                $diaNumero = $diasMap[$diaHorario] ?? 1;
                
                $fechaActual = $fechaInicio->copy();
                while ($fechaActual <= $fechaFin) {
                    if ($fechaActual->dayOfWeek == $diaNumero) {
                        $existe = \App\Models\ClaseProgramada::where('inscripcion_id', $inscripcion->id)
                            ->where('horario_id', $horario->id)
                            ->whereDate('fecha', $fechaActual)
                            ->exists();
                        
                        if (!$existe) {
                            \App\Models\ClaseProgramada::create([
                                'inscripcion_id' => $inscripcion->id,
                                'horario_id' => $horario->id,
                                'estudiante_id' => $inscripcion->estudiante_id,
                                'fecha' => $fechaActual->format('Y-m-d'),
                                'hora_inicio' => $horario->hora_inicio,
                                'hora_fin' => $horario->hora_fin,
                                'estado_clase' => 'programada',
                                'cuenta_para_asistencia' => true,
                                'es_recuperacion' => false,
                                'observaciones' => 'Generada automáticamente al crear la inscripción'
                            ]);
                            
                            $totalGeneradas++;
                        }
                    }
                    $fechaActual->addDay();
                }
            }
            
            \Log::info("Se generaron {$totalGeneradas} clases para inscripción #{$inscripcion->id}");
            
            return response()->json([
                'success' => true,
                'message' => "Se generaron {$totalGeneradas} clases programadas para la inscripción",
                'total_clases' => $totalGeneradas,
                'inscripcion_id' => $id
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error generando clases: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error generando clases: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    });

    /*
    |--------------------------------------------------------------------------
    | CLASES PROGRAMADAS
    |--------------------------------------------------------------------------
    */
    Route::prefix('clases-programadas')->group(function () {
        Route::get('/', [ClaseProgramadaController::class, 'index']);
        Route::post('/', [ClaseProgramadaController::class, 'store']);
        Route::get('/{clase}', [ClaseProgramadaController::class, 'show']);
        Route::put('/{clase}', [ClaseProgramadaController::class, 'update']);
        Route::delete('/{clase}', [ClaseProgramadaController::class, 'destroy']);
        Route::get('/calendario/mes', [ClaseProgramadaController::class, 'calendario']);
        Route::post('/generar/automatico', [ClaseProgramadaController::class, 'generar']);
        Route::post('/{clase}/estado', [ClaseProgramadaController::class, 'cambiarEstado']);
        Route::post('/{clase}/marcar-asistencia', [ClaseProgramadaController::class, 'marcarAsistencia']);
        Route::get('/buscar', [ClaseProgramadaController::class, 'buscarClase']);
        Route::post('/recuperacion/nueva', [ClaseProgramadaController::class, 'crearRecuperacion']);
        Route::get('/estudiante/{estudianteId}/pendientes-recuperacion', 
            [ClaseProgramadaController::class, 'clasesParaRecuperacion']);
        Route::get('/reporte/asistencias', [ClaseProgramadaController::class, 'reporteAsistencias']);
        Route::get('/fecha/{fecha}', [ClaseProgramadaController::class, 'porFecha']);
        Route::get('/estadisticas/hoy', [ClaseProgramadaController::class, 'estadisticasHoy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ASISTENCIAS
    |--------------------------------------------------------------------------
    */
    Route::prefix('asistencias')->group(function () {
        Route::get('/', [AsistenciaController::class, 'index']);
        Route::post('/marcar', [AsistenciaController::class, 'marcar']);
        Route::post('/registrar', [AsistenciaController::class, 'marcar']);
        Route::post('/justificar', [AsistenciaController::class, 'justificar']);
        Route::post('/lote', [AsistenciaController::class, 'marcarLote']);
        Route::get('/estadisticas', [AsistenciaController::class, 'estadisticas']);
        Route::get('/exportar', [AsistenciaController::class, 'exportar']);
        Route::get('/permisos/{inscripcionId}', [AsistenciaController::class, 'verificarPermisos']);
        Route::get('/motivos', [AsistenciaController::class, 'motivosJustificacion']);
        Route::get('/dia', [AsistenciaController::class, 'obtenerDia']);
    });

    /*
    |--------------------------------------------------------------------------
    | PERMISOS JUSTIFICADOS
    |--------------------------------------------------------------------------
    */
    Route::prefix('permisos-justificados')->group(function () {
        Route::get('/por-inscripcion', [PermisoController::class, 'justificadosPorInscripcion']);
        Route::get('/recuperables', [PermisoController::class, 'permisosRecuperables']);
        Route::get('/{id}/tiene-recuperacion', [PermisoController::class, 'tieneRecuperacion']);
        Route::post('/', [PermisoController::class, 'crearJustificacion']);
        Route::get('/{id}', [PermisoController::class, 'mostrarJustificado']);
        Route::put('/{id}', [PermisoController::class, 'actualizarJustificado']);
        Route::delete('/{id}', [PermisoController::class, 'eliminarJustificado']);
        Route::get('/justificados/por-inscripcion', [PermisoController::class, 'justificadosPorInscripcion']);
        Route::get('/justificados/recuperables', [PermisoController::class, 'permisosRecuperables']);
        Route::get('/justificados/{id}/tiene-recuperacion', [PermisoController::class, 'tieneRecuperacion']);
        Route::post('/justificados', [PermisoController::class, 'crearJustificacion']);
        Route::get('/estadisticas', [PermisoController::class, 'estadisticas']);
        Route::get('/proximos-a-vencer', [PermisoController::class, 'proximosAVencer']);
        Route::post('/justificar-ausencia', [PermisoController::class, 'justificarAusencia']);
        Route::get('/por-estudiante-fecha', [PermisoController::class, 'porEstudianteYFecha']);
    });

    /*
    |--------------------------------------------------------------------------
    | RECUPERACIONES
    |--------------------------------------------------------------------------
    */
    Route::prefix('recuperaciones')->group(function () {
        Route::get('/', [RecuperacionController::class, 'index']);
        Route::post('/', [RecuperacionController::class, 'store']);
        Route::get('/{id}', [RecuperacionController::class, 'show']);
        Route::put('/{id}', [RecuperacionController::class, 'update']);
        Route::delete('/{id}', [RecuperacionController::class, 'destroy']);
        Route::post('/{id}/completar', [RecuperacionController::class, 'completar']);
        Route::post('/{id}/cancelar', [RecuperacionController::class, 'cancelar']);
        Route::get('/inscripcion/{inscripcionId}', [RecuperacionController::class, 'porInscripcion']);
        Route::get('/estudiante/{estudianteId}', [RecuperacionController::class, 'porEstudiante']);
        Route::get('/{inscripcionId}/permisos-recuperables', [RecuperacionController::class, 'permisosRecuperables']);
        Route::get('/horarios/disponibles', [RecuperacionController::class, 'horariosDisponibles']);
        Route::get('/{inscripcionId}/verificar-periodo', [RecuperacionController::class, 'verificarPeriodo']);
        Route::get('/reporte/mensual', [RecuperacionController::class, 'reporteMensual']);
    });

    /*
    |--------------------------------------------------------------------------
    | PAGOS
    |--------------------------------------------------------------------------
    */
    Route::prefix('pagos')->group(function () {
        Route::get('/', [PagoController::class, 'index']);
        Route::post('/', [PagoController::class, 'store']);
        Route::get('/{id}', [PagoController::class, 'show']);
        Route::put('/{id}', [PagoController::class, 'update']);
        Route::delete('/{id}', [PagoController::class, 'destroy']);
        Route::get('/por-estudiante/{estudianteId}', [PagoController::class, 'porEstudiante']);
        Route::get('/inscripcion/{inscripcion_id}', [PagoController::class, 'porInscripcion']);
        Route::put('/{id}/anular', [PagoController::class, 'anular']);
        Route::put('/{id}/confirmar', [PagoController::class, 'confirmar']);
        Route::get('/reporte/mensual', [PagoController::class, 'reporteMensual']);
        Route::get('/estadisticas', [PagoController::class, 'estadisticas']);
        Route::get('/pendientes', [PagoController::class, 'pendientes']);
        Route::get('pagos/por-estudiante/{estudianteId}', [PagoController::class, 'porEstudiante']);
    });

    /*
    |--------------------------------------------------------------------------
    | REEMBOLSOS
    |--------------------------------------------------------------------------
    */
    Route::prefix('reembolsos')->group(function () {
        Route::get('/', [ReembolsoController::class, 'index']);
        Route::post('/', [ReembolsoController::class, 'store']);
        Route::get('/{id}', [ReembolsoController::class, 'show']);
        Route::put('/{id}', [ReembolsoController::class, 'update']);
        Route::delete('/{id}', [ReembolsoController::class, 'destroy']);
        Route::post('/{id}/aprobar', [ReembolsoController::class, 'aprobar']);
        Route::post('/{id}/rechazar', [ReembolsoController::class, 'rechazar']);
        Route::post('/{id}/procesar', [ReembolsoController::class, 'procesar']);
        Route::post('/{id}/completar', [ReembolsoController::class, 'completar']);
        Route::get('/estudiante/{estudianteId}', [ReembolsoController::class, 'porEstudiante']);
        Route::get('/inscripcion/{inscripcionId}', [ReembolsoController::class, 'porInscripcion']);
        Route::get('/estadisticas/dashboard', [ReembolsoController::class, 'estadisticas']);
    });

    /*
    |--------------------------------------------------------------------------
    | NOTIFICACIONES
    |--------------------------------------------------------------------------
    */
    Route::prefix('notificaciones')->group(function () {
        Route::post('/clases-bajas', function (\Illuminate\Http\Request $request) {
            try {
                $data = $request->validate([
                    'estudiante_id' => 'required|integer',
                    'estudiante_email' => 'required|email',
                    'estudiante_nombre' => 'required|string',
                    'inscripcion_id' => 'required|integer',
                    'clases_restantes' => 'required|integer',
                    'clases_totales' => 'required|integer',
                    'clases_asistidas' => 'required|integer',
                    'modalidad' => 'nullable|string',
                    'sucursal' => 'nullable|string'
                ]);
                
                \Log::info('Notificación de clases bajas enviada', $data);
                
                \App\Models\Notificacion::create([
                    'estudiante_id' => $data['estudiante_id'],
                    'inscripcion_id' => $data['inscripcion_id'],
                    'tipo' => 'clases_bajas',
                    'mensaje' => "Te quedan {$data['clases_restantes']} clases en tu inscripción",
                    'fecha' => now(),
                    'enviada' => true
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Notificación registrada exitosamente',
                    'data' => $data
                ]);
                
            } catch (\Exception $e) {
                \Log::error('Error enviando notificación: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Error enviando notificación',
                    'error' => $e->getMessage()
                ], 500);
            }
        });
        
        Route::get('/hoy', function () {
            $hoy = now()->toDateString();
            $notificaciones = \App\Models\Notificacion::whereDate('fecha', $hoy)
                ->with(['estudiante'])
                ->get();
            
            return response()->json([
                'success' => true,
                'total' => $notificaciones->count(),
                'notificaciones' => $notificaciones
            ]);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | ROLES Y PERMISOS DEL SISTEMA
    |--------------------------------------------------------------------------
    */
    Route::get('/users-with-roles', [UserRoleController::class, 'index']);
    Route::get('/roles', [UserRoleController::class, 'getRoles']);
    Route::post('/assign-roles', [UserRoleController::class, 'assignRoles']);

    /*
    |--------------------------------------------------------------------------
    | DASHBOARD Y REPORTES
    |--------------------------------------------------------------------------
    */
    Route::prefix('dashboard')->group(function () {
        Route::get('/estadisticas', function() {
            return response()->json([
                'success' => true,
                'data' => [
                    'total_estudiantes' => \App\Models\Estudiante::count(),
                    'total_inscripciones_activas' => \App\Models\Inscripcion::where('estado', 'activo')->count(),
                    'total_pagos_mes' => \App\Models\Pago::whereMonth('fecha_pago', now()->month)->sum('monto'),
                    'asistencias_hoy' => \App\Models\Asistencia::whereDate('fecha', now()->toDateString())
                        ->where('estado', 'asistio')->count()
                ]
            ]);
        });
        
        Route::get('/notificaciones-vencimientos', [InscripcionController::class, 'notificacionesVencimientos']);
        Route::get('/alertas-pagos', [PagoController::class, 'alertasPagos']);
    });

    /*
    |--------------------------------------------------------------------------
    | PERMISOS DEL SISTEMA
    |--------------------------------------------------------------------------
    */
    Route::prefix('permisos-sistema')->group(function () {
        Route::get('/menu', [UserRoleController::class, 'getMenuPermissions']);
        Route::get('/', [UserRoleController::class, 'getAllPermissions']);
        Route::get('/rol/{id}', [UserRoleController::class, 'getPermissionsByRole']);
        Route::put('/rol/{id}', [UserRoleController::class, 'updateRolePermissions']);
    });

    /*
    |--------------------------------------------------------------------------
    | ROLES CON PERMISOS
    |--------------------------------------------------------------------------
    */
    Route::prefix('roles')->group(function () {
        Route::get('/usuarios', [UserRoleController::class, 'index']);
        Route::get('/', [UserRoleController::class, 'getRoles']);
        Route::post('/asignar', [UserRoleController::class, 'assignRoles']);
        Route::get('/{id}/permisos', [UserRoleController::class, 'getPermissionsByRole']);
        Route::get('/con-permisos', [UserRoleController::class, 'getRolesWithPermissions']);
        Route::put('/{id}/permisos', [UserRoleController::class, 'updateRolePermissions']);
        Route::post('/', [UserRoleController::class, 'store']);
        Route::put('/{id}', [UserRoleController::class, 'update']);
        Route::delete('/{id}', [UserRoleController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| NO AUTORIZADO
|--------------------------------------------------------------------------
*/
Route::get('/no-autorizado', function () {
    return response()->json([
        'message' => 'No estás autorizado para ver este recurso'
    ], 403);
})->name('login');