<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mesmo padrão de cópia da cobrança: descrição e preço do catálogo no momento da venda.
        Schema::create('venda_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venda_id')->constrained('vendas')->restrictOnDelete();
            $table->foreignId('catalogo_item_id')->constrained('catalogo_itens')->restrictOnDelete();
            $table->string('descricao');
            $table->unsignedInteger('preco_unitario_centavos');
            $table->unsignedInteger('quantidade');
            $table->unsignedBigInteger('subtotal_centavos');
            // Se esta linha baixou estoque: o cancelamento devolve por este registro, não pela configuração atual do item.
            $table->boolean('baixou_estoque')->default(false);
            // PENDÊNCIA: FK tenant_id -> tenants.id quando a tabela de tenants existir (CLAUDE.md > Pendências conhecidas).
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();
            $table->index(['tenant_id', 'catalogo_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venda_itens');
    }
};
