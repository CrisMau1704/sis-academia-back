<?php
// database/migrations/2024_01_19_000000_create_clases_programadas_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('clases_programadas', function (Blueprint $table) {
            $table->id();
            
            // Relación con inscripcion_horarios (ya tienes esta tabla)
            $table->foreignId('inscripcion_horario_id')
                  ->constrained('inscripcion_horarios')
                  ->onDelete('cascade');
            
            // Relación con horarios (para mantener la info del horario aunque cambie)
            $table->foreignId('horario_id')
                  ->constrained('horarios')
                  ->onDelete('cascade');
            
            // Relación con inscripciones (para búsquedas rápidas)
            $table->foreignId('inscripcion_id')
                  ->constrained('inscripciones')
                  ->onDelete('cascade');
            
            // Relación con estudiantes (para reportes)
            $table->foreignId('estudiante_id')
                  ->constrained('estudiantes')
                  ->onDelete('cascade');
            
            // Fecha y hora específica de esta clase
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            
            // Estado de la clase
            $table->enum('estado_clase', [
                'programada',     // Aún no se realiza
                'realizada',      // Se realizó con normalidad
                'ausente',        // No asistió sin justificación
                'justificada',    // No asistió con permiso
                'cancelada',      // Clase cancelada por el gimnasio
                'recuperacion',   // Es una clase de recuperación
                'feriado'         // Día festivo
            ])->default('programada');
            
            // Relación con asistencia (si se registró)
            $table->foreignId('asistencia_id')
                  ->nullable()
                  ->constrained('asistencias')
                  ->onDelete('set null');
            
            // Para clases de recuperación: referencia a la clase original que se perdió
            $table->foreignId('clase_original_id')
                  ->nullable()
                  ->constrained('clases_programadas')
                  ->onDelete('set null');
            
            // Campos de control
            $table->boolean('es_recuperacion')->default(false);
            $table->boolean('cuenta_para_asistencia')->default(true);
            $table->text('observaciones')->nullable();
            
            // Auditoría
            $table->timestamps();
            $table->softDeletes();
            
            // Índices para optimización
            $table->index(['fecha', 'estado_clase']);
            $table->index(['estudiante_id', 'fecha']);
            $table->index(['horario_id', 'fecha']);
            $table->index(['inscripcion_horario_id', 'estado_clase']);
            
            // Para evitar duplicados
            $table->unique(['inscripcion_horario_id', 'fecha'], 'unique_clase_inscripcion_fecha');
        });
    }

    public function down()
    {
        Schema::dropIfExists('clases_programadas');
    }
};