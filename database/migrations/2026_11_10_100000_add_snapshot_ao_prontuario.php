<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prontuário assinado não muda quando o cadastro muda: o que ele diz fica gravado nele.
     * - prontuario_itens.nome_item: o nome do item no momento do lançamento (renomear ou excluir o item do
     *   catálogo não altera o prontuário);
     * - prontuarios.assinado_por_*: nome e CRMV de quem assinou, no momento da assinatura;
     * - prontuario_adendos.autor_*: nome e CRMV de quem registrou o adendo, no momento do adendo.
     * Colunas nulas: registros anteriores a esta migration não têm a cópia (a leitura cai no cadastro).
     */
    public function up(): void
    {
        Schema::table('prontuario_itens', function (Blueprint $table) {
            $table->string('nome_item')->nullable()->after('catalogo_item_id');
        });

        Schema::table('prontuarios', function (Blueprint $table) {
            $table->string('assinado_por_nome')->nullable()->after('assinado_em');
            $table->string('assinado_por_crmv', 32)->nullable()->after('assinado_por_nome');
        });

        Schema::table('prontuario_adendos', function (Blueprint $table) {
            $table->string('autor_nome')->nullable()->after('autor_id');
            $table->string('autor_crmv', 32)->nullable()->after('autor_nome');
        });
    }

    public function down(): void
    {
        Schema::table('prontuario_adendos', function (Blueprint $table) {
            $table->dropColumn(['autor_nome', 'autor_crmv']);
        });

        Schema::table('prontuarios', function (Blueprint $table) {
            $table->dropColumn(['assinado_por_nome', 'assinado_por_crmv']);
        });

        Schema::table('prontuario_itens', function (Blueprint $table) {
            $table->dropColumn('nome_item');
        });
    }
};
