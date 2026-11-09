<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Adendo: a correção de um prontuário assinado. Nasce assinado e nunca muda.
        Schema::create('prontuario_adendos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prontuario_id')->constrained('prontuarios')->restrictOnDelete();
            $table->foreignId('autor_id')->constrained('users')->restrictOnDelete();
            $table->text('texto');
            $table->dateTime('assinado_em');
            // PENDÊNCIA: FK tenant_id -> tenants.id quando a tabela de tenants existir (CLAUDE.md > Pendências conhecidas).
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();
            $table->index(['tenant_id', 'prontuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prontuario_adendos');
    }
};
