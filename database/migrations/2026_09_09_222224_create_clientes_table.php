<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('documento')->nullable();
            $table->string('email')->nullable();
            $table->string('telefone')->nullable();
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
        Schema::dropIfExists('clientes');
    }
};
