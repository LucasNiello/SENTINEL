<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 24/11: identificador do e-mail de origem ("mailpit:<id>"). O índice único é a trava contra duplicar: vale mesmo
        // se marcar o e-mail como lido falhar ou se duas importações correrem juntas. Mensagem do seed fica null.
        Schema::table('mensagens', function (Blueprint $table) {
            $table->string('origem_id', 120)->nullable()->after('remetente');
            $table->unique(['tenant_id', 'origem_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mensagens', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'origem_id']);
            $table->dropColumn('origem_id');
        });
    }
};
