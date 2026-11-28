<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobranca_itens', function (Blueprint $table) {
            $table->boolean('excluido_financeiramente')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('cobranca_itens', fn (Blueprint $table) => $table->dropColumn('excluido_financeiramente'));
    }
};
