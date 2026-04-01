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
        Schema::table('ecommerce_order_options', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('option_status')
                  ->comment('구매확정 일시');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('ecommerce_order_options', 'confirmed_at')) {
            Schema::table('ecommerce_order_options', function (Blueprint $table) {
                $table->dropColumn('confirmed_at');
            });
        }
    }
};
