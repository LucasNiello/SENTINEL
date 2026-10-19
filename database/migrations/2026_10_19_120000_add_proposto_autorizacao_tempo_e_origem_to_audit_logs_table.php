<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 4/5: tudo o que a auditoria passa a registrar além do que já existe.
     * - parametros_propostos (RF04): o que a IA propôs; "parametros" continua sendo o
     *   que foi de fato executado/confirmado. Mascarado do mesmo jeito (LGPD).
     * - autorizado_por (RF10): admin que digitou a senha para autorizar (restaurar,
     *   exclusão física); user_id continua sendo quem estava logado.
     * - tempo_ia_ms (RNF04): tempo de resposta da chamada à IA, em milissegundos.
     * - origem: por onde a ação entrou (agente, formulario ou manual).
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('autorizado_por')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('origem', 20)->nullable()->after('tool');
            $table->json('parametros_propostos')->nullable()->after('parametros');
            $table->unsignedInteger('tempo_ia_ms')->nullable()->after('mensagem');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('autorizado_por');
            $table->dropColumn(['origem', 'parametros_propostos', 'tempo_ia_ms']);
        });
    }
};
