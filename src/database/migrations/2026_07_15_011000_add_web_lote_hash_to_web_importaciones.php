<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_importaciones', function (Blueprint $table) {
            $table->string('web_lote_hash', 64)->nullable()->after('web_tipo');
            $table->unique('web_lote_hash');
        });
    }

    public function down(): void
    {
        Schema::table('web_importaciones', function (Blueprint $table) {
            $table->dropUnique(['web_lote_hash']);
            $table->dropColumn('web_lote_hash');
        });
    }
};
