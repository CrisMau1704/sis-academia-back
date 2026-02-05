<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('reembolsos', function (Blueprint $table) {
            // Verificar si las columnas ya existen antes de agregarlas
            if (!Schema::hasColumn('reembolsos', 'created_at')) {
                $table->timestamp('created_at')->nullable()->after('fecha_completado');
            }
            
            if (!Schema::hasColumn('reembolsos', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });
    }

    public function down()
    {
        Schema::table('reembolsos', function (Blueprint $table) {
            // No eliminar las columnas en rollback por seguridad
            // $table->dropColumn(['created_at', 'updated_at']);
        });
    }
};