<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 마이그레이션을 실행합니다.
     */
    public function up(): void
    {
        Schema::table('ecommerce_orders', function (Blueprint $table) {
            $table->decimal('total_vat_amount', 12, 2)
                ->default(0)
                ->after('total_tax_amount')
                ->comment('총 부가세금액');
        });
    }

    /**
     * 마이그레이션을 롤백합니다.
     */
    public function down(): void
    {
        if (Schema::hasColumn('ecommerce_orders', 'total_vat_amount')) {
            Schema::table('ecommerce_orders', function (Blueprint $table) {
                $table->dropColumn('total_vat_amount');
            });
        }
    }
};
