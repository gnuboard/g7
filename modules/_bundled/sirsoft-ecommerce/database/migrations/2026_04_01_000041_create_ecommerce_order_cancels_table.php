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
        Schema::create('ecommerce_order_cancels', function (Blueprint $table) {
            $table->id()->comment('취소 ID');
            $table->foreignId('order_id')->comment('주문 FK')
                ->constrained('ecommerce_orders')->cascadeOnDelete();
            $table->string('cancel_number', 50)->unique()->comment('고유 취소번호');
            $table->string('cancel_type', 30)->comment('취소 유형 (CancelTypeEnum: full, partial)');
            $table->string('cancel_status', 30)->comment('취소 상태 (CancelStatusEnum: requested, completed)');
            $table->string('cancel_reason_type', 30)->comment('취소 사유 코드 (ecommerce_claim_reasons.code 참조)');
            $table->text('cancel_reason')->nullable()->comment('상세 사유 (기타 선택 시)');
            $table->mediumText('items_snapshot')->comment('취소 요청 시점 대상 옵션/수량 스냅샷');
            $table->foreignId('cancelled_by')->nullable()->comment('취소 요청자')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->comment('취소 완료 시각');
            $table->timestamps();

            $table->index('order_id', 'ecommerce_order_cancels_order_id_index');
            $table->index('cancel_status', 'ecommerce_order_cancels_cancel_status_index');
            $table->index('cancel_type', 'ecommerce_order_cancels_cancel_type_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_order_cancels` COMMENT '주문 취소 이력'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_order_cancels')) {
            Schema::dropIfExists('ecommerce_order_cancels');
        }
    }
};
