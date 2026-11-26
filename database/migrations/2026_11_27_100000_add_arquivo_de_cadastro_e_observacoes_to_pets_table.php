<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 27/11: pet arquivado NO CADASTRO (falecido, mudou...), sem apagar nada. Não é o `arquivado_em` da lixeira
 * (esse marca registro já excluído que expirou): `inativado_em` convive com o pet ativo na lista e some dos seletores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->timestamp('inativado_em')->nullable()->after('alertas');
            $table->string('observacoes', 500)->nullable()->after('alertas');
            $table->index(['tenant_id', 'tutor_id', 'inativado_em']);
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'tutor_id', 'inativado_em']);
            $table->dropColumn(['inativado_em', 'observacoes']);
        });
    }
};
