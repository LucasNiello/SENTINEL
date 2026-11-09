<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clínica vet: o "cliente" do domínio novo. Fica ao lado de clientes até a remoção do domínio antigo.
        Schema::create('tutores', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('documento', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('endereco')->nullable();
            // PENDÊNCIA: FK tenant_id -> tenants.id quando a tabela de tenants existir (CLAUDE.md > Pendências conhecidas).
            $table->unsignedBigInteger('tenant_id');
            $table->timestamp('arquivado_em')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutores');
    }
};
