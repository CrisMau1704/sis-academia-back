<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   // En la migración
public function up()
{
    Schema::table('pagos', function (Blueprint $table) {
        // Cambiar de INT a BIGINT
        $table->bigInteger('pago_grupo_id')->nullable()->change();
    });
}

public function down()
{
    Schema::table('pagos', function (Blueprint $table) {
        $table->integer('pago_grupo_id')->nullable()->change();
    });
}
};
