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

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/
Route::prefix('v1/auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('profile', [AuthController::class, 'profile']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

/*
|--------------------------------------------------------------------------
| RUTAS PROTEGIDAS
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

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
        // O en routes/api.php:
Route::get('/horarios/por-modalidad/{modalidadId}', [HorarioController::class, 'getPorModalidad']);
    });

    /*
    |--------------------------------------------------------------------------
    | INSCRIPCIONES
    |--------------------------------------------------------------------------
    */
    Route::prefix('inscripciones')->group(function () {
        Route::get('/', [InscripcionController::class, 'index']);
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
        // Ruta corregida - solo una vez
        Route::get('/estudiante/{estudianteId}/activa', [InscripcionController::class, 'inscripcionActiva']);
    });

    /*
    |--------------------------------------------------------------------------
    | ASISTENCIAS - SISTEMA COMPLETO
    |--------------------------------------------------------------------------
    */
/*
|--------------------------------------------------------------------------
| ASISTENCIAS - SISTEMA COMPLETO
|--------------------------------------------------------------------------
*/
Route::prefix('asistencias')->group(function () {
    // RUTAS ESPECÍFICAS PRIMERO (estas se evaluarán primero)
    Route::get('/dia', [AsistenciaController::class, 'obtenerDia']);
    Route::post('/marcar', [AsistenciaController::class, 'marcar']);
    Route::post('/justificar', [AsistenciaController::class, 'justificar']);
    Route::post('/lote', [AsistenciaController::class, 'marcarLote']);
    Route::get('/estadisticas', [AsistenciaController::class, 'estadisticas']);
    Route::get('/exportar', [AsistenciaController::class, 'exportar']);
    Route::get('/permisos/{inscripcionId}', [AsistenciaController::class, 'verificarPermisos']);
    Route::get('/motivos', [AsistenciaController::class, 'motivosJustificacion']);
    
    // ALIAS para compatibilidad
    Route::get('/por-fecha', [AsistenciaController::class, 'obtenerDia']);
    Route::post('/registrar', [AsistenciaController::class, 'marcar']);
    
    // RUTAS CRUD SOLO SI LAS NECESITAS - PONLAS AL FINAL
    // Route::get('/{id}', [AsistenciaController::class, 'show'])->where('id', '[0-9]+');
    // Route::put('/{id}', [AsistenciaController::class, 'update']);
    // Route::delete('/{id}', [AsistenciaController::class, 'destroy']);
});

// Si tienes esto, ELIMÍNALO o ponlo DESPUÉS:
// Route::apiResource('asistencias', AsistenciaController::class);

    /*
    |--------------------------------------------------------------------------
    | PERMISOS JUSTIFICADOS
    |--------------------------------------------------------------------------
    */
    Route::prefix('permisos')->group(function () {
        // CRUD básico
        Route::get('/', [PermisoController::class, 'index']);
        Route::post('/', [PermisoController::class, 'store']);
        Route::get('/{id}', [PermisoController::class, 'show']);
        Route::put('/{id}', [PermisoController::class, 'update']);
        Route::delete('/{id}', [PermisoController::class, 'destroy']);
        
        // Estados
        Route::put('/{id}/aprobar', [PermisoController::class, 'aprobar']);
        Route::put('/{id}/rechazar', [PermisoController::class, 'rechazar']);
        
        // Filtros específicos
        Route::get('/pendientes', [PermisoController::class, 'pendientes']);
        Route::get('/aprobados', [PermisoController::class, 'aprobados']);
        Route::get('/rechazados', [PermisoController::class, 'rechazados']);
        
        // Por relación
        Route::get('/inscripcion/{inscripcionId}', [PermisoController::class, 'porInscripcion']);
        Route::get('/estudiante/{estudianteId}', [PermisoController::class, 'porEstudiante']);
        
        // Verificación y control
        Route::get('/verificar-disponibilidad/{inscripcionId}', [PermisoController::class, 'verificarDisponibilidad']);
        Route::get('/contador-mensual/{inscripcionId}', [PermisoController::class, 'contadorMensual']);
        
        // Acciones en lote
        Route::post('/aprobar-lote', [PermisoController::class, 'aprobarLote']);
        Route::post('/rechazar-lote', [PermisoController::class, 'rechazarLote']);
        
        // Reportes
        Route::get('/reporte/mensual', [PermisoController::class, 'reporteMensual']);
        Route::get('/reporte/estudiante/{estudianteId}', [PermisoController::class, 'reporteEstudiante']);
    });

    /*
    |--------------------------------------------------------------------------
    | RECUPERACIONES DE CLASES
    |--------------------------------------------------------------------------
    */
    Route::prefix('recuperaciones')->group(function () {
        // CRUD básico
        Route::get('/', [RecuperacionController::class, 'index']);
        Route::post('/', [RecuperacionController::class, 'store']);
        Route::get('/{id}', [RecuperacionController::class, 'show']);
        Route::put('/{id}', [RecuperacionController::class, 'update']);
        Route::delete('/{id}', [RecuperacionController::class, 'destroy']);
        
        // Estados
        Route::put('/{id}/completar', [RecuperacionController::class, 'completar']);
        Route::put('/{id}/cancelar', [RecuperacionController::class, 'cancelar']);
        
        // Filtros
        Route::get('/pendientes', [RecuperacionController::class, 'pendientes']);
        Route::get('/completadas', [RecuperacionController::class, 'completadas']);
        Route::get('/canceladas', [RecuperacionController::class, 'canceladas']);
        
        // Por relación
        Route::get('/estudiante/{estudianteId}', [RecuperacionController::class, 'porEstudiante']);
        Route::get('/inscripcion/{inscripcionId}', [RecuperacionController::class, 'porInscripcion']);
        
        // Horarios disponibles
        Route::get('/horarios-disponibles', [RecuperacionController::class, 'horariosDisponibles']);
        
        // Verificación
        Route::get('/verificar-periodo/{inscripcionId}', [RecuperacionController::class, 'verificarPeriodoRecuperacion']);
        Route::get('/faltas-recuperables/{inscripcionId}', [RecuperacionController::class, 'faltasRecuperables']);
        
        // Reportes
        Route::get('/reporte/mensual', [RecuperacionController::class, 'reporteMensual']);
        Route::get('/reporte/vencimientos', [RecuperacionController::class, 'reporteVencimientos']);
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
        Route::get('/inscripcion/{inscripcion_id}', [PagoController::class, 'porInscripcion']);
        Route::put('/{id}/anular', [PagoController::class, 'anular']);
        Route::put('/{id}/confirmar', [PagoController::class, 'confirmar']);
        
        // Nuevas rutas
        Route::get('/reporte/mensual', [PagoController::class, 'reporteMensual']);
        Route::get('/estadisticas', [PagoController::class, 'estadisticas']);
        Route::get('/pendientes', [PagoController::class, 'pendientes']);
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
});

/*
|--------------------------------------------------------------------------
| RUTAS PÚBLICAS (si las necesitas)
|--------------------------------------------------------------------------
*/
Route::get('/estado-servidor', function () {
    return response()->json([
        'status' => 'online',
        'timestamp' => now()->toDateTimeString(),
        'version' => '1.0.0'
    ]);
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