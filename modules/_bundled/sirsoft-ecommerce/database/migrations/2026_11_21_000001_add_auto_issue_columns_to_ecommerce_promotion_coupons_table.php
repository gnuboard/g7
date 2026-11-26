<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * comment 문자열·코드에 모듈 Enum 클래스를 참조하지 않는다.
 * `php artisan migrate` 는 모듈 오토로더가 등록되지 않은 상태에서도 실행될 수 있다(신규 설치, 모듈 비활성 상태 등).
 */
return new class extends Migration
{
    /** 대상 테이블 (프리픽스 제외) */
    private const TABLE = 'ecommerce_promotion_coupons';

    /**
     * 쿠폰에 자동발급·발급 알림 설정 컬럼을 추가합니다.
     *
     * - `first_purchase_trigger_status`: 첫구매 쿠폰의 발급 시점(주문 상태). NULL 이면 구매확정으로 본다
     * - `notify_enabled`: 발급 알림 사용 — 켜면 이 쿠폰 전용 알림 종류 `coupon_issued.{id}` 를 만든다
     * - `notify_marketing_only`: 광고성 안내 — 메일·문자·알림톡은 마케팅 수신동의자에게만 보낸다
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'first_purchase_trigger_status')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->string('first_purchase_trigger_status', 30)->nullable()->after('issue_condition')
                    ->comment('첫구매 발급 시점 주문 상태: payment_complete(결제완료), preparing(상품준비중), shipping(배송중), delivered(배송완료), confirmed(구매확정). NULL 이면 구매확정');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'notify_enabled')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->boolean('notify_enabled')->default(false)->after('target_scope')
                    ->comment('발급 알림 사용 여부 (채널·문구는 알림 종류 coupon_issued.{쿠폰ID} 의 템플릿에 있다)');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'notify_marketing_only')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->boolean('notify_marketing_only')->default(false)->after('notify_enabled')
                    ->comment('광고성 안내 여부 (켜면 메일·문자·알림톡은 마케팅 수신동의자에게만, 미동의자에게는 사이트 알림만)');
            });
        }
    }

    /**
     * 추가한 컬럼을 제거합니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (['notify_marketing_only', 'notify_enabled', 'first_purchase_trigger_status'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
