<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clínica vet: CRMV do veterinário (e do dono, que é veterinário). Obrigatório para assinar prontuário.
        Schema::table('users', function (Blueprint $table) {
            $table->string('crmv', 20)->nullable()->after('tenant_id');
            $table->unique(['tenant_id', 'crmv']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'crmv']);
            $table->dropColumn('crmv');
        });
    }
};
