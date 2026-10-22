<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Etapa A2: nas atualizações, o "antes" dos campos que mudaram (só eles), com a
     * mesma máscara LGPD de parametros. Nulo em tudo o que não é atualização.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->json('valores_anteriores')->nullable()->after('parametros_propostos');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('valores_anteriores');
        });
    }
};
