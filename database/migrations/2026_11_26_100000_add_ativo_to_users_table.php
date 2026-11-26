<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 26/11 (decisão do Lucas): conta ativa ou inativa. O dono dá a ordem e o Administrador/RH liga ou desliga.
     * Inativa não entra, perde a sessão e não aparece como profissional nem autoriza nada; o histórico dela continua
     * ligado aos registros (por isso desativa em vez de apagar). Aditiva: tudo o que existe fica ativo.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('ativo')->default(true)->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ativo');
        });
    }
};
