<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cobrança de um agendamento. Sem texto clínico, sem lixeira: cancela-se por status.
        Schema::create('cobrancas', function (Blueprint $table) {
            $table->id();
            // Sem unique: depois de cancelada, o agendamento pode ser cobrado de novo. "Uma cobrança não
            // cancelada por agendamento" é garantido pelo CobrancaService (com o agendamento travado).
            $table->foreignId('agendamento_id')->index()->constrained('agendamentos')->restrictOnDelete();
            // Cópia do setor do agendamento: RBAC por setor e Resultados por setor sem join.
            $table->enum('setor', ['clinica', 'petshop']);
            $table->enum('status', ['aberta', 'fechada', 'paga', 'cancelada'])->default('aberta');
            // Calculado pelo servidor (soma dos subtotais), nunca informado por ninguém.
            $table->unsignedBigInteger('total_centavos')->default(0);
            $table->dateTime('fechada_em')->nullable();
            $table->enum('forma_pagamento', ['dinheiro', 'pix', 'cartao'])->nullable();
            $table->dateTime('pago_em')->nullable();
            // Estorno (paga -> cancelada): quem pediu, quem autorizou, por quê, quanto e quando.
            $table->foreignId('estorno_solicitado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('estorno_autorizado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('estorno_motivo', 500)->nullable();
            $table->unsignedBigInteger('estorno_valor_centavos')->nullable();
            $table->dateTime('estornado_em')->nullable();
            // PENDÊNCIA: FK tenant_id -> tenants.id quando a tabela de tenants existir (CLAUDE.md > Pendências conhecidas).
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();
            // Resultados: faturamento por período (pago_em) e por setor; a receber (fechada sem pagamento).
            $table->index(['tenant_id', 'status', 'pago_em']);
            $table->index(['tenant_id', 'setor', 'pago_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobrancas');
    }
};
