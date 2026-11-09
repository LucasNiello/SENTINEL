<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Descrição e preço são CÓPIAS do catálogo no momento do lançamento: mudar o catálogo não altera cobrança antiga.
        Schema::create('cobranca_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobranca_id')->constrained('cobrancas')->restrictOnDelete();
            $table->foreignId('catalogo_item_id')->constrained('catalogo_itens')->restrictOnDelete();
            // De qual item do prontuário veio (nulo: serviço do agendamento ou item avulso). Sem unique: o item
            // volta a ser cobrável se a cobrança for cancelada; uma vez por cobrança é regra do CobrancaService.
            $table->foreignId('prontuario_item_id')->nullable()->index()->constrained('prontuario_itens')->restrictOnDelete();
            $table->string('descricao');
            $table->unsignedInteger('preco_unitario_centavos');
            $table->unsignedInteger('quantidade');
            $table->unsignedBigInteger('subtotal_centavos');
            // Se esta linha baixou estoque: o estorno devolve por este registro, não pela configuração atual do item.
            $table->boolean('baixou_estoque')->default(false);
            // PENDÊNCIA: FK tenant_id -> tenants.id quando a tabela de tenants existir (CLAUDE.md > Pendências conhecidas).
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();
            // Resultados: itens mais vendidos.
            $table->index(['tenant_id', 'catalogo_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobranca_itens');
    }
};
