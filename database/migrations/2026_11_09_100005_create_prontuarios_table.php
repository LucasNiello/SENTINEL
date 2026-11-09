<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // IMUTÁVEL depois de assinado (guarda no model App\Models\Prontuario): correção só por adendo.
        // Sem lixeira e sem exclusão (guarda mínima de 5 anos). NENHUM campo de valor.
        Schema::create('prontuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->restrictOnDelete();
            $table->foreignId('veterinario_id')->constrained('users')->restrictOnDelete();
            // Nulo: emergência sem agendamento, vinculada depois a um encaixe_emergencia, uma única vez.
            $table->foreignId('agendamento_id')->nullable()->unique()->constrained('agendamentos')->restrictOnDelete();
            // Quem vinculou e quando (o vínculo é a única mudança aceita depois da assinatura).
            $table->foreignId('vinculado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('vinculado_em')->nullable();
            $table->enum('tipo', ['consulta', 'retorno', 'vacinacao', 'exame', 'cirurgia', 'internacao', 'emergencia']);
            $table->text('anamnese')->nullable();
            $table->text('exame_fisico')->nullable();
            $table->text('diagnostico')->nullable();
            $table->text('conduta')->nullable();
            $table->dateTime('entrada_em')->nullable();
            $table->dateTime('alta_em')->nullable();
            $table->dateTime('assinado_em')->nullable();
            // PENDÊNCIA: FK tenant_id -> tenants.id quando a tabela de tenants existir (CLAUDE.md > Pendências conhecidas).
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();
            $table->index(['tenant_id', 'pet_id']);
            $table->index(['tenant_id', 'veterinario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prontuarios');
    }
};
