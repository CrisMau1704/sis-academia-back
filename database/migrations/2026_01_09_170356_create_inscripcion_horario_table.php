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
        Schema::create('inscripcion_horario', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('inscripcion_id');
            $table->unsignedBigInteger('horario_id');
            
            $table->integer('clases_asistidas')->default(0);
            $table->integer('clases_totales')->default(12);
            $table->integer('clases_restantes')->default(12);
            $table->integer('permisos_usados')->default(0);
            
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            
            $table->enum('estado', ['activo', 'pausado', 'finalizado', 'vencido'])
                  ->default('activo');
            
            $table->timestamps();
            
            // Claves foráneas
            $table->foreign('inscripcion_id')
                  ->references('id')
                  ->on('inscripciones')
                  ->onDelete('cascade');
                  
            $table->foreign('horario_id')
                  ->references('id')
                  ->on('horarios')
                  ->onDelete('cascade');
            
            // Índices
            $table->index('inscripcion_id');
            $table->index('horario_id');
            $table->index('clases_restantes');
            $table->index('fecha_fin');
            $table->index('estado');
            
            // Evitar duplicados
            $table->unique(['inscripcion_id', 'horario_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inscripcion_horario');
    }
};