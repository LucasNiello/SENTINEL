<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funcionarios', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('cpf');
            $table->string('cargo')->nullable();
            $table->decimal('salario', 10, 2);
            $table->date('data_admissao');
            $table->date('data_demissao')->nullable();
            // PENDÊNCIA FASE 1: criar a FK (tenant_id -> tenants.id) quando a
            // tabela de tenants/empresas existir. Ver CLAUDE.md > Pendências conhecidas.
            $table->unsignedBigInteger('tenant_id');
            $table->timestamp('arquivado_em')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funcionarios');
    }
};
