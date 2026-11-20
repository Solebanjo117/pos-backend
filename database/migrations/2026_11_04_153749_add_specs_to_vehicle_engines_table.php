<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ficha técnica completa del motor (válvulas, árbol de levas, cilindros y apodo comercial).
     *
     * Se elimina el índice único heredado (model_id, engine_code): los motores ya pertenecen a la
     * marca y se vinculan a varios modelos por pivote, y un mismo código puede tener varias
     * versiones (p. ej. 6G72 12V y 24V). La unicidad se valida en la aplicación por marca y ficha.
     */
    public function up(): void
    {
        Schema::table('vehicle_engines', function (Blueprint $table) {
            $table->string('valves', 10)->nullable()->after('displacement');
            $table->string('cam_type', 10)->nullable()->after('valves');
            $table->unsignedTinyInteger('cylinders')->nullable()->after('cam_type');
            $table->string('nickname', 50)->nullable()->after('engine_code');
            $table->dropUnique(['model_id', 'engine_code']);
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_engines', function (Blueprint $table) {
            $table->unique(['model_id', 'engine_code']);
            $table->dropColumn(['valves', 'cam_type', 'cylinders', 'nickname']);
        });
    }
};
