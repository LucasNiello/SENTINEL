<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tool');
            $table->string('acao');
            $table->string('entidade_tipo')->nullable();
            $table->unsignedBigInteger('entidade_id')->nullable();
            $table->json('parametros');
            $table->string('resultado');
            $table->string('mensagem')->nullable();
            // PENDÊNCIA FASE 1: criar a FK (tenant_id -> tenants.id) quando a
            // tabela de tenants/empresas existir. Ver CLAUDE.md > Pendências conhecidas.
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
