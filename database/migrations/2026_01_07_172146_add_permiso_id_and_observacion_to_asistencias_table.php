<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistencias', function (Blueprint $table) {
            // Agregar permiso_id
            $table->foreignId('permiso_id')
                  ->after('horario_id')
                  ->nullable()
                  ->constrained('permisos_justificados')
                  ->onDelete('set null');
            
            // Agregar observación
            $table->text('observacion')
                  ->after('estado')
                  ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('asistencias', function (Blueprint $table) {
            $table->dropForeign(['permiso_id']);
            $table->dropColumn(['permiso_id', 'observacion']);
        });
    }
};