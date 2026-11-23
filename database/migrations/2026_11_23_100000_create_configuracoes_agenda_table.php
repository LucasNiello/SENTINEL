<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Painel do dono (23/11): expediente, intervalo e vagas por bloco, uma linha por tenant. Sem linha, vale a
        // config('sentinel.agenda') (o padrão). Os dias da semana e as regras por categoria continuam na config.
        Schema::create('configuracoes_agenda', function (Blueprint $table) {
            $table->id();
            // PENDÊNCIA: FK tenant_id -> tenants.id quando a tabela de tenants existir (CLAUDE.md > Pendências conhecidas).
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->string('expediente_inicio', 5);
            $table->string('expediente_fim', 5);
            $table->string('intervalo_inicio', 5)->nullable();
            $table->string('intervalo_fim', 5)->nullable();
            $table->unsignedSmallInteger('vagas_por_bloco');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes_agenda');
    }
};
