<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // PRIMERO: Verificar que tenemos datos para migrar
        Schema::table('dias_asignados', function (Blueprint $table) {
            // 1. Agregar columna temporal para backup
            $table->bigInteger('inscripcion_id_backup')->unsigned()->nullable()->after('id');
            
            // 2. Agregar la nueva columna horario_id (temporalmente nullable)
            $table->bigInteger('horario_id')->unsigned()->nullable()->after('inscripcion_id');
        });

        // SEGUNDO: Copiar datos de respaldo
        DB::statement('UPDATE dias_asignados SET inscripcion_id_backup = inscripcion_id');

        // TERCERO: Intentar mapear inscripcion_id a horario_id automáticamente
        // Esto depende de tu estructura. ¿Tienes relación entre inscripciones y horarios?
        // Si no, necesitaremos otro enfoque
    }

    public function down()
    {
        Schema::table('dias_asignados', function (Blueprint $table) {
            // Restaurar en caso de rollback
            $table->dropColumn('horario_id');
            $table->dropColumn('inscripcion_id_backup');
        });
    }
};