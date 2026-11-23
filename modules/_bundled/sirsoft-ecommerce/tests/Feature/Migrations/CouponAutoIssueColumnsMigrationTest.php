<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Migrations;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Coupon\AutoIssue\Concerns\CreatesAutoIssueCoupons;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 자동발급 컬럼 마이그레이션 (#952 §6)
 */
class CouponAutoIssueColumnsMigrationTest extends ModuleTestCase
{
    use CreatesAutoIssueCoupons;

    private const MIGRATION_DIR = 'modules/_bundled/sirsoft-ecommerce/database/migrations';

    /**
     * 왕복 검사할 두 마이그레이션과 각자 더하는 컬럼을 준다.
     *
     * @return array<string, array{string, string, array<int, string>}> 이름 => [경로, 테이블, 컬럼]
     */
    private function migrations(): array
    {
        return [
            'coupons' => [self::MIGRATION_DIR.'/2026_11_21_000001_add_auto_issue_columns_to_ecommerce_promotion_coupons_table.php', 'ecommerce_promotion_coupons', ['first_purchase_trigger_status', 'notify_enabled', 'notify_marketing_only']],
            'issues' => [self::MIGRATION_DIR.'/2026_11_21_000002_add_auto_issue_columns_to_ecommerce_promotion_coupon_issues_table.php', 'ecommerce_promotion_coupon_issues', ['issue_source', 'auto_issue_key', 'source_order_id', 'expiry_notified_at']],
        ];
    }

    /**
     * down → down → up → up 왕복이 오류 없이 끝나고 컬럼·인덱스가 제자리에 돌아온다.
     *
     * @scenario case=auto_issue_columns_round_trip
     *
     * @effects migration_round_trip_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_round_trip_and_idempotent(): void
    {
        foreach ($this->migrations() as $name => [$path, $table, $columns]) {
            $this->assertFileExists(base_path($path), $name);
            $migration = require base_path($path);

            $migration->down();
            foreach ($columns as $column) {
                $this->assertFalse(Schema::hasColumn($table, $column), "{$name}: down 뒤 {$column}");
            }
            $migration->down();

            $migration->up();
            $migration->up();
            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$name}: 왕복 뒤 {$column}");
            }
        }

        $this->assertTrue(Schema::hasIndex('ecommerce_promotion_coupon_issues', 'uq_coupon_issue_auto_key'));
        $this->assertTrue(Schema::hasIndex('ecommerce_promotion_coupon_issues', 'idx_coupon_issue_source_order'));
    }

    /**
     * 자동발급 키가 NULL 인 행(수동 발급)은 같은 회원·쿠폰에 여러 개 둘 수 있고, 같은 키는 두 번 넣을 수 없다.
     * 원천 주문이 지워지면 source_order_id 만 비워진다.
     *
     * @scenario case=auto_issue_columns_constraints
     *
     * @effects auto_issue_key_unique
     *
     * @return void 반환값 없음
     */
    public function test_unique_key_allows_multiple_nulls_and_fk_nulls_on_delete(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::FIRST_PURCHASE);
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create();
        $row = fn (?string $key, ?int $orderId = null) => CouponIssue::query()->create([
            'coupon_id' => $coupon->id, 'user_id' => $user->id, 'coupon_code' => 'AU-'.uniqid(), 'status' => 'available',
            'issued_at' => now(), 'auto_issue_key' => $key, 'source_order_id' => $orderId,
        ]);

        $row(null);
        $row(null);
        $keyed = $row('first_purchase', $order->id);
        $this->assertSame(3, CouponIssue::query()->where('coupon_id', $coupon->id)->count());

        try {
            $row('first_purchase');
            $this->fail('같은 자동발급 키가 두 번 들어갔다');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $order->forceDelete();
        $this->assertNull($keyed->fresh()->source_order_id);
    }

    /**
     * 마이그레이션은 모듈 클래스를 참조하지 않는다.
     *
     * @scenario case=auto_issue_columns_v1_safe
     *
     * @effects migration_round_trip_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_migrations_do_not_reference_module_classes(): void
    {
        foreach ($this->migrations() as $name => [$path]) {
            $this->assertStringNotContainsString('Modules\\Sirsoft', (string) file_get_contents(base_path($path)), $name);
        }
    }
}
