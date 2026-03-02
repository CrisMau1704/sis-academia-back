<?php
// database/migrations/2024_xx_xx_xxxxxx_add_preinscrito_to_estudiantes_enum.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddPreinscritoToEstudiantesEnum extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Cambiar el ENUM para incluir 'preinscrito'
        DB::statement("ALTER TABLE estudiantes MODIFY COLUMN estado ENUM('activo', 'inactivo', 'preinscrito') DEFAULT 'activo'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revertir al ENUM original (sin 'preinscrito')
        DB::statement("ALTER TABLE estudiantes MODIFY COLUMN estado ENUM('activo', 'inactivo') DEFAULT 'activo'");
    }
}