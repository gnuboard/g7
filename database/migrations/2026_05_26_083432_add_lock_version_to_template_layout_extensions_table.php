<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('template_layout_extensions', function (Blueprint $table) {
            if (! Schema::hasColumn('template_layout_extensions', 'lock_version')) {
                $table->unsignedBigInteger('lock_version')->default(0)->after('is_active')->comment('낙관적 잠금 버전');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('template_layout_extensions', function (Blueprint $table) {
            if (Schema::hasColumn('template_layout_extensions', 'lock_version')) {
                $table->dropColumn('lock_version');
            }
        });
    }
};
