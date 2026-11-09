<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clínica vet: autorização de estorno. supervisor_setor é marcado só pelo dono (SupervisaoService);
        // pin_autorizacao é diferente da senha de login, definido pelo próprio supervisor e guardado só como hash.
        Schema::table('users', function (Blueprint $table) {
            $table->enum('supervisor_setor', ['clinica', 'petshop'])->nullable()->after('crmv');
            $table->string('pin_autorizacao')->nullable()->after('supervisor_setor');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['supervisor_setor', 'pin_autorizacao']);
        });
    }
};
