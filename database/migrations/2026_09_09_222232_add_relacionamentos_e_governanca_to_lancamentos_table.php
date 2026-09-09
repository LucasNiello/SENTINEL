<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->foreignId('categoria_lancamento_id')->nullable()->after('tenant_id')
                ->constrained('categorias_lancamento')->restrictOnDelete();
            $table->foreignId('cliente_id')->nullable()->after('categoria_lancamento_id')
                ->constrained('clientes')->restrictOnDelete();
            $table->foreignId('fornecedor_id')->nullable()->after('cliente_id')
                ->constrained('fornecedores')->restrictOnDelete();
            $table->foreignId('funcionario_id')->nullable()->after('fornecedor_id')
                ->constrained('funcionarios')->restrictOnDelete();
            $table->timestamp('arquivado_em')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('categoria_lancamento_id');
            $table->dropConstrainedForeignId('cliente_id');
            $table->dropConstrainedForeignId('fornecedor_id');
            $table->dropConstrainedForeignId('funcionario_id');
            $table->dropColumn(['arquivado_em', 'deleted_at']);
        });
    }
};
