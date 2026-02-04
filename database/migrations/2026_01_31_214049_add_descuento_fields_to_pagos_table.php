<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->decimal('descuento_porcentaje', 5, 2)->nullable()->after('monto');
            $table->decimal('descuento_monto', 10, 2)->nullable()->after('descuento_porcentaje');
            $table->decimal('subtotal', 10, 2)->nullable()->after('descuento_monto');
            $table->decimal('total_final', 10, 2)->nullable()->after('subtotal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropColumn([
                'descuento_porcentaje',
                'descuento_monto',
                'subtotal',
                'total_final'
            ]);
        });
    }
};