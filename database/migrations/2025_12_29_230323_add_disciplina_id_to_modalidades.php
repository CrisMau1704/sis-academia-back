<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Verificar si la columna ya existe
        if (!Schema::hasColumn('modalidades', 'disciplina_id')) {
            Schema::table('modalidades', function (Blueprint $table) {
                $table->foreignId('disciplina_id')
                    ->after('id')
                    ->nullable()
                    ->constrained('disciplinas')
                    ->onDelete('set null')
                    ->comment('ID de la disciplina relacionada');
            });
        }
    }

    public function down(): void
    {
        Schema::table('modalidades', function (Blueprint $table) {
            $table->dropForeign(['disciplina_id']);
            $table->dropColumn('disciplina_id');
        });
    }
};