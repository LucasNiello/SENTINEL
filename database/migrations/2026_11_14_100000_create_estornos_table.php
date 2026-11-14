<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // T2 (14/11): estorno por itens, de uma cobrança paga OU de uma venda paga (resposta E: mesma regra). Vários
        // estornos parciais por registro, até esgotar. As colunas estorno_* de cobrancas continuam (estado final).
        Schema::create('estornos', function (Blueprint $table) {
            $table->id();
            // Um dos dois, nunca os dois (garantido pelo service que grava).
            $table->foreignId('cobranca_id')->nullable()->constrained('cobrancas')->restrictOnDelete();
            $table->foreignId('venda_id')->nullable()->constrained('vendas')->restrictOnDelete();
            $table->foreignId('solicitado_por')->constrained('users')->restrictOnDelete();
            $table->foreignId('autorizado_por')->constrained('users')->restrictOnDelete();
            $table->string('motivo', 500);
            // Soma dos itens deste estorno: preço COPIADO no lançamento × quantidade (nunca o preço atual do catálogo).
            $table->unsignedBigInteger('valor_centavos');
            // PENDÊNCIA: FK tenant_id -> tenants.id quando a tabela de tenants existir (CLAUDE.md > Pendências conhecidas).
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();
            $table->index(['tenant_id', 'cobranca_id']);
            $table->index(['tenant_id', 'venda_id']);
        });

        Schema::create('estorno_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estorno_id')->constrained('estornos')->restrictOnDelete();
            // Linha estornada: cobranca_itens.id (estorno de cobrança) ou venda_itens.id (estorno de venda).
            $table->unsignedBigInteger('item_id')->index();
            $table->unsignedInteger('quantidade');
            $table->unsignedBigInteger('valor_centavos');
            // Só item avulso que baixou estoque devolve; item do prontuário nunca (resposta D).
            $table->boolean('devolveu_estoque')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estorno_itens');
        Schema::dropIfExists('estornos');
    }
};
