<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Venda de balcão (loja ou farmácia). Sem lixeira: cancela-se por status, devolvendo o estoque.
        Schema::create('vendas', function (Blueprint $table) {
            $table->id();
            // Uma venda é de um setor só (todos os itens do mesmo setor).
            $table->enum('setor', ['clinica', 'petshop']);
            $table->foreignId('vendedor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('tutor_id')->nullable()->constrained('tutores')->restrictOnDelete();
            $table->enum('status', ['aberta', 'paga', 'cancelada'])->default('aberta');
            // Calculado pelo servidor (soma dos subtotais), nunca informado por ninguém.
            $table->unsignedBigInteger('total_centavos')->default(0);
            $table->enum('forma_pagamento', ['dinheiro', 'pix', 'cartao'])->nullable();
            $table->dateTime('pago_em')->nullable();
            // PENDÊNCIA: FK tenant_id -> tenants.id quando a tabela de tenants existir (CLAUDE.md > Pendências conhecidas).
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'pago_em']);
            $table->index(['tenant_id', 'setor', 'pago_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendas');
    }
};
