<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permisos_justificados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscripcion_id')
                  ->constrained('inscripciones')
                  ->onDelete('cascade');
            
            $table->foreignId('asistencia_id')
                  ->nullable()
                  ->constrained('asistencias')
                  ->onDelete('set null');
            
            $table->date('fecha_solicitud');
            $table->date('fecha_falta');
            $table->text('motivo');
            
            $table->enum('estado', ['pendiente', 'aprobado', 'rechazado'])
                  ->default('pendiente');
            
            $table->string('evidencia')->nullable();
            $table->foreignId('administrador_id')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');
            
            $table->timestamps();
            
            $table->index('estado');
            $table->index('fecha_falta');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permisos_justificados');
    }
};