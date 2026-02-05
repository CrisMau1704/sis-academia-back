<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reembolsos', function (Blueprint $table) {
            $table->id();
            
            // RELACIONES ESENCIALES
            $table->foreignId('pago_id')->constrained('pagos')->onDelete('cascade');
            $table->foreignId('estudiante_id')->constrained('estudiantes')->onDelete('cascade');
            $table->foreignId('usuario_id')->constrained('users')->onDelete('cascade');
            
            // DATOS MONETARIOS
            $table->decimal('monto_original', 10, 2);
            $table->decimal('monto_reembolsado', 10, 2);
            $table->decimal('porcentaje_reembolso', 5, 2)->default(100.00);
            
            // INFORMACIÓN DEL PROCESO
            $table->enum('tipo', ['parcial', 'total'])->default('parcial');
            $table->enum('metodo', ['efectivo', 'transferencia', 'tarjeta_credito', 'devolucion_tarjeta', 'credito_futuro'])->default('efectivo');
            $table->enum('estado', ['pendiente', 'aprobado', 'rechazado', 'completado'])->default('pendiente');
            
            // MOTIVO (único campo de texto requerido)
            $table->text('motivo');
            
            // FECHAS CLAVE
            $table->timestamp('fecha_solicitud')->useCurrent();
            $table->timestamp('fecha_aprobacion')->nullable();
            $table->timestamp('fecha_completado')->nullable();
            
            // SIN campos: comprobante_path, referencia_bancaria, razon_rechazo, observaciones, created_at, updated_at
            
            // ÍNDICES PARA RENDIMIENTO
            $table->index('estado');
            $table->index('estudiante_id');
            $table->index('fecha_solicitud');
            $table->index(['pago_id', 'estado']);
        });
        
        // También agregar campos mínimos a tabla pagos
        Schema::table('pagos', function (Blueprint $table) {
            if (!Schema::hasColumn('pagos', 'tiene_reembolso')) {
                $table->boolean('tiene_reembolso')->default(false)->after('numero_cuota');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropColumn('tiene_reembolso');
        });
        
        Schema::dropIfExists('reembolsos');
    }
};