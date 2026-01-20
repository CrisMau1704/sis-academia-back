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
    Schema::create('notificaciones', function (Blueprint $table) {
        $table->id();
        $table->foreignId('estudiante_id')->constrained('estudiantes')->onDelete('cascade');
        $table->foreignId('inscripcion_id')->constrained('inscripciones')->onDelete('cascade');
        $table->string('tipo'); // clases_bajas, renovacion, pago, etc.
        $table->text('mensaje');
        $table->dateTime('fecha');
        $table->boolean('enviada')->default(false);
        $table->boolean('leida')->default(false);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
