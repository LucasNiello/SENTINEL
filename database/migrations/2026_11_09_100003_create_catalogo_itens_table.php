<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogo_itens', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['servico', 'produto']);
            $table->enum('setor', ['clinica', 'petshop']);
            // Lista fechada em App\Models\CatalogoItem::CATEGORIAS (string: categoria nova não pede migration).
            $table->string('categoria', 30);
            $table->string('nome_comercial');
            $table->unsignedInteger('preco_centavos');
            $table->enum('cobranca_por', ['unidade', 'diaria'])->default('unidade');
            $table->boolean('controla_estoque')->default(false);
            // Nunca negativo: a baixa é um UPDATE condicional no service (CatalogoService::baixarEstoque).
            $table->unsignedInteger('estoque')->default(0);
            $table->boolean('exige_receita')->default(false);
            // PENDÊNCIA: FK tenant_id -> tenants.id quando a tabela de tenants existir (CLAUDE.md > Pendências conhecidas).
            $table->unsignedBigInteger('tenant_id');
            $table->timestamp('arquivado_em')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'setor', 'categoria']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogo_itens');
    }
};
