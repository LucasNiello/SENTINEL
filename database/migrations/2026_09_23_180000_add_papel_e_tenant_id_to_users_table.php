<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RF10: o papel (RBAC) e o tenant passam a vir do usuário logado.
     *
     * As duas colunas são nullable de propósito: um default seria um papel ou
     * tenant "padrão", e o sistema falha fechado (ContextoUsuario lança
     * AuthenticationException e o login recusa usuário sem papel válido ou
     * sem tenant).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('papel')->nullable()->after('password');
            // PENDÊNCIA FASE 1: criar a FK (tenant_id -> tenants.id) quando a
            // tabela de tenants/empresas existir. Ver CLAUDE.md > Pendências conhecidas.
            $table->unsignedBigInteger('tenant_id')->nullable()->after('papel');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['papel', 'tenant_id']);
        });
    }
};
