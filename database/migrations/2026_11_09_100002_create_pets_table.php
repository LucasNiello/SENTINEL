<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_id')->constrained('tutores')->restrictOnDelete();
            $table->string('nome');
            $table->enum('especie', ['cao', 'gato', 'ave', 'roedor', 'reptil', 'outro']);
            $table->string('raca')->nullable();
            $table->enum('sexo', ['macho', 'femea'])->nullable();
            $table->date('data_nascimento')->nullable();
            $table->unsignedInteger('peso_gramas')->nullable();
            $table->string('microchip', 32)->nullable();
            // Aviso NÃO clínico, visível a todos (ex.: "morde", "medo de secador").
            $table->string('alertas', 500)->nullable();
            // PENDÊNCIA: FK tenant_id -> tenants.id quando a tabela de tenants existir (CLAUDE.md > Pendências conhecidas).
            $table->unsignedBigInteger('tenant_id');
            $table->timestamp('arquivado_em')->nullable();
            $table->timestamps();
            $table->softDeletes();
            // Microchip único por tenant; NULL repetido é permitido (MySQL e SQLite).
            $table->unique(['tenant_id', 'microchip']);
            $table->index(['tenant_id', 'tutor_id']);
            $table->index(['tenant_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pets');
    }
};
