<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RF06/RF07: além de tool/ação/entidade/parâmetros/resultado, cada registro
     * guarda o papel usado e se a ação foi permitida ou negada pelo RBAC.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('papel')->nullable()->after('mensagem');
            $table->boolean('permitido')->default(true)->after('papel');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['papel', 'permitido']);
        });
    }
};
