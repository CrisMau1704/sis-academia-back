<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('recuperacion_clases', function (Blueprint $table) {
            // 1. AGREGAR COLUMNAS FALTANTES PARA INTEGRACIÓN
            if (!Schema::hasColumn('recuperacion_clases', 'inscripcion_id')) {
                $table->unsignedBigInteger('inscripcion_id')->nullable()->after('asistencia_id');
            }
            
            if (!Schema::hasColumn('recuperacion_clases', 'estudiante_id')) {
                $table->unsignedBigInteger('estudiante_id')->nullable()->after('inscripcion_id');
            }
            
            if (!Schema::hasColumn('recuperacion_clases', 'permiso_justificado_id')) {
                $table->unsignedBigInteger('permiso_justificado_id')->nullable()->after('asistencia_id');
            }
            
            if (!Schema::hasColumn('recuperacion_clases', 'fecha_limite')) {
                $table->date('fecha_limite')->nullable()->after('fecha_recuperacion');
            }
            
            // 2. MEJORAR COLUMNA ESTADO (si es necesario)
            // $table->enum('estado', ['programada', 'completada', 'cancelada', 'pendiente'])->default('pendiente')->change();
            
            // 3. AGREGAR COLUMNA PARA REGISTRO DE QUIÉN CREÓ LA RECUPERACIÓN
            if (!Schema::hasColumn('recuperacion_clases', 'creado_por')) {
                $table->unsignedBigInteger('creado_por')->nullable()->after('administrador_id')
                    ->comment('Usuario que creó la recuperación');
            }
            
            // 4. AGREGAR COLUMNA PARA MOTIVO DE CANCELACIÓN (si se cancela)
            if (!Schema::hasColumn('recuperacion_clases', 'motivo_cancelacion')) {
                $table->text('motivo_cancelacion')->nullable()->after('motivo');
            }
            
            // 5. AGREGAR COLUMNA PARA FECHA DE COMPLETACIÓN
            if (!Schema::hasColumn('recuperacion_clases', 'fecha_completada')) {
                $table->timestamp('fecha_completada')->nullable()->after('fecha_recuperacion');
            }
            
            // 6. AGREGAR ÍNDICES PARA MEJOR RENDIMIENTO
            $table->index(['inscripcion_id', 'estado'], 'idx_inscripcion_estado');
            $table->index(['estudiante_id', 'fecha_recuperacion'], 'idx_estudiante_fecha');
            $table->index(['fecha_limite', 'estado'], 'idx_fecha_limite_estado');
            
            // 7. AGREGAR CLAVES FORÁNEAS
            if (Schema::hasColumn('recuperacion_clases', 'inscripcion_id')) {
                $table->foreign('inscripcion_id')
                    ->references('id')
                    ->on('inscripciones')
                    ->onDelete('cascade');
            }
            
            if (Schema::hasColumn('recuperacion_clases', 'estudiante_id')) {
                $table->foreign('estudiante_id')
                    ->references('id')
                    ->on('estudiantes')
                    ->onDelete('cascade');
            }
            
            if (Schema::hasColumn('recuperacion_clases', 'permiso_justificado_id')) {
                $table->foreign('permiso_justificado_id')
                    ->references('id')
                    ->on('permisos_justificados')
                    ->onDelete('set null');
            }
            
            // 8. AGREGAR COMENTARIOS PARA DOCUMENTACIÓN
            if (!Schema::hasColumn('recuperacion_clases', 'comentarios')) {
                $table->text('comentarios')->nullable()->after('motivo')
                    ->comment('Comentarios adicionales sobre la recuperación');
            }
            
            // 9. AGREGAR COLUMNA PARA CONTROL DE ASISTENCIA A LA RECUPERACIÓN
            if (!Schema::hasColumn('recuperacion_clases', 'asistio_recuperacion')) {
                $table->boolean('asistio_recuperacion')->default(false)->after('estado')
                    ->comment('Indica si el estudiante asistió a la recuperación');
            }
            
            // 10. AGREGAR COLUMNA PARA REGISTRAR ASISTENCIA A LA RECUPERACIÓN
            if (!Schema::hasColumn('recuperacion_clases', 'asistencia_recuperacion_id')) {
                $table->unsignedBigInteger('asistencia_recuperacion_id')->nullable()->after('asistio_recuperacion')
                    ->comment('ID de la asistencia registrada para esta recuperación');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recuperacion_clases', function (Blueprint $table) {
            // ELIMINAR ÍNDICES
            $table->dropIndex('idx_inscripcion_estado');
            $table->dropIndex('idx_estudiante_fecha');
            $table->dropIndex('idx_fecha_limite_estado');
            
            // ELIMINAR CLAVES FORÁNEAS
            $table->dropForeign(['inscripcion_id']);
            $table->dropForeign(['estudiante_id']);
            $table->dropForeign(['permiso_justificado_id']);
            
            // ELIMINAR COLUMNAS AGREGADAS (solo si existen)
            $columnsToDrop = [
                'inscripcion_id',
                'estudiante_id',
                'permiso_justificado_id',
                'fecha_limite',
                'creado_por',
                'motivo_cancelacion',
                'fecha_completada',
                'comentarios',
                'asistio_recuperacion',
                'asistencia_recuperacion_id'
            ];
            
            foreach ($columnsToDrop as $column) {
                if (Schema::hasColumn('recuperacion_clases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};