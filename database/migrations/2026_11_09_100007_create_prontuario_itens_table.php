<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // O que foi realizado no atendimento. SEM preço: o preço só aparece na cobrança (cópia do catálogo).
        Schema::create('prontuario_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prontuario_id')->constrained('prontuarios')->restrictOnDelete();
            $table->foreignId('catalogo_item_id')->constrained('catalogo_itens')->restrictOnDelete();
            $table->unsignedInteger('quantidade')->default(1);
            // PENDÊNCIA: FK tenant_id -> tenants.id quando a tabela de tenants existir (CLAUDE.md > Pendências conhecidas).
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();
            $table->index(['tenant_id', 'prontuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prontuario_itens');
    }
};
