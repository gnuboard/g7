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
    private const TABLE = 'ecommerce_promotion_coupon_issues';

    /** 자동발급 1회 보장 유니크 인덱스명 */
    private const AUTO_KEY_UNIQUE = 'uq_coupon_issue_auto_key';

    /** 원천 주문 인덱스명 */
    private const SOURCE_ORDER_INDEX = 'idx_coupon_issue_source_order';

    /** 원천 주문 외래키명 */
    private const SOURCE_ORDER_FOREIGN = 'fk_coupon_issue_source_order';

    /**
     * 발급 내역에 발급 경로·자동발급 키·원천 주문·만료 임박 알림 시각을 추가합니다. (#952)
     *
     * `(coupon_id, user_id, auto_issue_key)` 유니크가 「조건마다 1회(생일은 해마다 1회)」를 DB 에서 지킨다.
     * MySQL 유니크 인덱스는 NULL 을 서로 다른 값으로 보므로 수동 다운로드·직접발급(키 NULL)과 기존 행은 제약을 받지 않는다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'issue_source')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->string('issue_source', 20)->nullable()->after('coupon_code')
                    ->comment('발급 경로: download(다운로드), direct(직접발급), signup(가입), first_purchase(첫구매), birthday(생일). 이 컬럼 이전 행은 NULL');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'auto_issue_key')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->string('auto_issue_key', 40)->nullable()->after('issue_source')
                    ->comment('자동발급 1회 키: signup, first_purchase, birthday:YYYY. 수동 발급은 NULL, 회수되면 NULL');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'source_order_id')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedBigInteger('source_order_id')->nullable()->after('auto_issue_key')
                    ->comment('첫구매 쿠폰을 발급하게 한 주문 ID (사용 주문 order_id 와 다름)');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'expiry_notified_at')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dateTime('expiry_notified_at')->nullable()->after('used_at')
                    ->comment('만료 임박 알림 발송 일시 (발급 건당 1회)');
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::AUTO_KEY_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique(['coupon_id', 'user_id', 'auto_issue_key'], self::AUTO_KEY_UNIQUE);
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::SOURCE_ORDER_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index('source_order_id', self::SOURCE_ORDER_INDEX);
            });
        }

        if (Schema::hasTable('ecommerce_orders') && ! $this->hasForeignKey(self::SOURCE_ORDER_FOREIGN)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->foreign('source_order_id', self::SOURCE_ORDER_FOREIGN)
                    ->references('id')->on('ecommerce_orders')->nullOnDelete();
            });
        }
    }

    /**
     * 추가한 인덱스·외래키·컬럼을 제거합니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->hasForeignKey(self::SOURCE_ORDER_FOREIGN)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropForeign(self::SOURCE_ORDER_FOREIGN);
            });
        }

        if (Schema::hasIndex(self::TABLE, self::SOURCE_ORDER_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::SOURCE_ORDER_INDEX);
            });
        }

        if (Schema::hasIndex(self::TABLE, self::AUTO_KEY_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(self::AUTO_KEY_UNIQUE);
            });
        }

        foreach (['expiry_notified_at', 'source_order_id', 'auto_issue_key', 'issue_source'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /**
     * 이 테이블에 이름이 같은 외래키가 있는지 확인합니다.
     *
     * @param  string  $name  외래키 이름 (프리픽스 제외)
     * @return bool 있으면 true
     */
    private function hasForeignKey(string $name): bool
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreign) {
            $foreignName = (string) ($foreign['name'] ?? '');
            if ($foreignName === $name || str_ends_with($foreignName, $name)) {
                return true;
            }
        }

        return false;
    }
};
