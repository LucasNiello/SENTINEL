<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobrancas', function (Blueprint $table) {
            $table->unsignedBigInteger('desconto_centavos')->default(0);
            $table->string('desconto_motivo', 200)->nullable();
            $table->foreignId('desconto_autorizado_por')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cobrancas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('desconto_autorizado_por');
            $table->dropColumn(['desconto_centavos', 'desconto_motivo']);
        });
    }
};
