<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   // En el archivo de migración creado
public function up()
{
    Schema::table('inscripciones', function (Blueprint $table) {
        $table->text('observaciones')->nullable()->after('estado');
    });
}

public function down()
{
    Schema::table('inscripciones', function (Blueprint $table) {
        $table->dropColumn('observaciones');
    });
}
};
