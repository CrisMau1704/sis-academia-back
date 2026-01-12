<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recuperacion_clases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asistencia_id')
                ->constrained('asistencias')
                ->onDelete('cascade');
            
            $table->date('fecha_recuperacion');
            $table->foreignId('horario_recuperacion_id')
                ->constrained('horarios')
                ->onDelete('cascade');
            
            $table->text('motivo')->nullable();
            $table->enum('estado', ['programada', 'completada', 'cancelada'])->default('programada');
            $table->boolean('en_periodo_valido')->default(true);
            $table->foreignId('administrador_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            
            $table->timestamps();
            
            $table->index('fecha_recuperacion');
            $table->index('estado');
            $table->index('en_periodo_valido');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recuperacion_clases');
    }
};