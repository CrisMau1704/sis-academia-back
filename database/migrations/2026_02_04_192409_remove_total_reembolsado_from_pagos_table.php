<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            // Solo eliminar total_reembolsado, mantener tiene_reembolso
            $table->dropColumn('total_reembolsado');
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->decimal('total_reembolsado', 10, 2)->default(0);
        });
    }
};