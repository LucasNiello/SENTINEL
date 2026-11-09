<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Caixa de entrada SIMULADA (só seed): nada é enviado nem recebido de verdade.
        Schema::create('mensagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_id')->nullable()->constrained('tutores')->restrictOnDelete();
            // Quem escreveu, quando o tutor não foi identificado.
            $table->string('remetente')->nullable();
            $table->text('texto');
            $table->enum('status', ['nova', 'tratada'])->default('nova');
            // PENDÊNCIA: FK tenant_id -> tenants.id quando a tabela de tenants existir (CLAUDE.md > Pendências conhecidas).
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensagens');
    }
};
