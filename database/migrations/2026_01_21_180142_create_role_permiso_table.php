<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('role_permiso', function (Blueprint $table) {
            $table->foreignId('role_id')
                  ->constrained('roles')
                  ->onDelete('cascade');
                  
            $table->foreignId('permiso_id')
                  ->constrained('permisos')
                  ->onDelete('cascade');
                  
            $table->timestamps();
            
            $table->primary(['role_id', 'permiso_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_permiso');
    }
};