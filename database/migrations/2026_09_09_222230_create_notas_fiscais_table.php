<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas_fiscais', function (Blueprint $table) {
            $table->id();
            $table->string('numero');
            $table->enum('tipo', ['entrada', 'saida']);
            $table->decimal('valor', 10, 2);
            $table->date('data_emissao');

            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->restrictOnDelete();
            $table->foreignId('fornecedor_id')->nullable()->constrained('fornecedores')->restrictOnDelete();
            $table->foreignId('lancamento_id')->nullable()->constrained('lancamentos')->restrictOnDelete();

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
        Schema::dropIfExists('notas_fiscais');
    }
};
