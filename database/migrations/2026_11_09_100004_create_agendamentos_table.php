<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agendamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->restrictOnDelete();
            // O profissional é um usuário (papel, CRMV e "a própria agenda" vêm do login), não um funcionário.
            $table->foreignId('profissional_id')->constrained('users')->restrictOnDelete();
            // Copiado do serviço (catalogo_itens.setor) ao agendar.
            $table->enum('setor', ['clinica', 'petshop']);
            $table->foreignId('catalogo_item_id')->constrained('catalogo_itens')->restrictOnDelete();
            $table->dateTime('inicio');
            $table->dateTime('fim');
            $table->enum('status', ['agendado', 'confirmado', 'realizado', 'cancelado', 'faltou'])->default('agendado');
            $table->enum('origem', ['normal', 'encaixe_emergencia'])->default('normal');
            // PENDÊNCIA: FK tenant_id -> tenants.id quando a tabela de tenants existir (CLAUDE.md > Pendências conhecidas).
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();
            // Conflito de horário por profissional e agenda por setor.
            $table->index(['tenant_id', 'profissional_id', 'inicio']);
            $table->index(['tenant_id', 'setor', 'inicio']);
            $table->index(['tenant_id', 'pet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agendamentos');
    }
};
