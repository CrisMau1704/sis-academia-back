<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            // Campos para manejar pagos parciales/divididos
            $table->boolean('es_parcial')->default(false)->after('observacion');
            $table->unsignedInteger('pago_grupo_id')->nullable()->after('es_parcial');
            $table->tinyInteger('numero_cuota')->default(1)->after('pago_grupo_id');
            
            // Índices para mejorar performance
            $table->index('pago_grupo_id');
            $table->index(['es_parcial', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropIndex(['pago_grupo_id']);
            $table->dropIndex(['es_parcial', 'estado']);
            
            $table->dropColumn('es_parcial');
            $table->dropColumn('pago_grupo_id');
            $table->dropColumn('numero_cuota');
        });
    }
};