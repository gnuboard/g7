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
        Schema::create('ecommerce_promotion_coupons', function (Blueprint $table) {
            $table->id()->comment('쿠폰 ID');
            $table->text('name')->comment('쿠폰명 (다국어)');
            $table->mediumText('description')->nullable()->comment('설명 (다국어)');
            $table->enum('target_type', ['product_amount', 'order_amount', 'shipping_fee'])->comment('적용대상: product_amount(상품금액), order_amount(주문금액), shipping_fee(배송비)');
            $table->enum('discount_type', ['fixed', 'rate'])->comment('혜택유형: fixed(정액), rate(정률)');
            $table->decimal('discount_value', 15, 2)->comment('혜택값 (정액: 금액, 정률: %)');
            $table->decimal('discount_max_amount', 15, 2)->nullable()->comment('최대 할인액 (정률 시)');
            $table->decimal('min_order_amount', 15, 2)->default(0)->comment('최소 주문금액');
            $table->enum('issue_method', ['direct', 'download', 'auto'])->comment('발급방법: direct(직접발급), download(다운로드), auto(자동발급)');
            $table->enum('issue_condition', ['manual', 'signup', 'first_purchase', 'birthday'])->comment('발급조건: manual(수동), signup(회원가입), first_purchase(첫구매), birthday(생일)');
            $table->enum('issue_status', ['issuing', 'stopped'])->default('issuing')->comment('발급상태: issuing(발급중), stopped(발급중단)');
            $table->unsignedInteger('total_quantity')->nullable()->comment('총 발급 수량 (NULL=무제한)');
            $table->unsignedInteger('issued_count')->default(0)->comment('현재 발급된 수량');
            $table->unsignedInteger('per_user_limit')->default(1)->comment('회원당 발급 제한');
            $table->enum('valid_type', ['period', 'days_from_issue'])->default('period')->comment('유효기간 유형: period(기간지정), days_from_issue(발급일로부터)');
            $table->unsignedInteger('valid_days')->nullable()->comment('발급일로부터 N일 (valid_type=days_from_issue)');
            $table->dateTime('valid_from')->nullable()->comment('유효기간 시작');
            $table->dateTime('valid_to')->nullable()->comment('유효기간 종료');
            $table->dateTime('issue_from')->nullable()->comment('발급기간 시작');
            $table->dateTime('issue_to')->nullable()->comment('발급기간 종료');
            $table->boolean('is_combinable')->default(false)->comment('다른 쿠폰과 중복 사용 가능 여부');
            $table->enum('target_scope', ['all', 'products', 'categories'])->default('all')->comment('적용 범위: all(전체), products(특정상품), categories(특정카테고리)');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes()->comment('삭제일시 (Soft Delete)');

            $table->index('issue_status', 'idx_issue_status');
            $table->index('target_type', 'idx_target_type');
            $table->index('issue_method', 'idx_issue_method');
            $table->index('issue_condition', 'idx_issue_condition');
            $table->index(['valid_from', 'valid_to'], 'idx_valid_period');
            $table->index(['issue_from', 'issue_to'], 'idx_issue_period');
            $table->index('created_at', 'idx_created_at');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_promotion_coupons` COMMENT '프로모션 쿠폰 정보'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_promotion_coupons')) {
            Schema::dropIfExists('ecommerce_promotion_coupons');
        }
    }
};
