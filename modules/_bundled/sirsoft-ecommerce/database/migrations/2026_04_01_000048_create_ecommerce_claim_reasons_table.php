<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ecommerce_claim_reasons', function (Blueprint $table) {
            $table->id()->comment('클래임 사유 ID');
            $table->string('type', 20)->comment('사유 유형 (refund, exchange, return 등)');
            $table->string('code', 50)->comment('고유 코드 (order_mistake 등)');
            $table->text('name')->comment('다국어 사유명 {"ko":"주문 실수","en":"Order Mistake"}');
            $table->string('fault_type', 20)->comment('귀책 구분 (customer, seller, carrier)');
            $table->boolean('is_user_selectable')->default(true)->comment('고객 선택 가능 여부');
            $table->boolean('is_active')->default(true)->comment('활성화 여부');
            $table->integer('sort_order')->default(0)->comment('정렬 순서');
            $table->unsignedBigInteger('created_by')->nullable()->comment('생성자');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('수정자');
            $table->timestamps();

            $table->unique(['type', 'code'], 'ecommerce_claim_reasons_type_code_unique');
            $table->index('is_active', 'ecommerce_claim_reasons_is_active_index');
            $table->index('sort_order', 'ecommerce_claim_reasons_sort_order_index');
            $table->index(['type', 'is_active', 'fault_type'], 'ecommerce_claim_reasons_type_active_fault_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_claim_reasons` COMMENT '클래임 사유 템플릿'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_claim_reasons')) {
            Schema::dropIfExists('ecommerce_claim_reasons');
        }
    }
};
