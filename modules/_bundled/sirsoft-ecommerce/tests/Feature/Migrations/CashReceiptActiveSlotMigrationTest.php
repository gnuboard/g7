<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;

/**
 * 현금영수증 활성 슬롯 키 마이그레이션 백필 테스트 (`2026_11_13_000001_add_active_slot_keys_to_ecommerce_order_cash_receipts_table`)
 *
 * **트랜잭션 격리 — DDL 을 돌리지 않는다.** ALTER TABLE 은 MySQL 에서 암묵 커밋을 일으켜 테스트 트랜잭션과 공유 테스트
 * DB 스키마를 깨뜨리므로(로컬 MySQL 의 migrate:fresh 재구축 부담 포함), 마이그레이션이 쓰는 **백필 함수**(`backfill`)를
 * 같은 인스턴스에서 직접 불러 잰다(선례 `MoveClaimTrackingToOptionRowsTest`).
 *
 * - 제약과 충돌하지 않는 백필(취소 대상 연결·처리 중 정리·키 없는 완료 해제)은 **실제 테이블**에서 잰다 — 백필 뒤
 *   생성 컬럼(`active_issue_order_key`·`open_cancel_target_key`)이 기대대로 계산되는지까지 확인한다.
 * - 제약 도입 전에만 있을 수 있는 데이터(같은 주문의 활성 발급 2건, 같은 발급의 완료 취소 2건)는 실제 테이블에 넣을
 *   수 없으므로, 같은 이름의 **TEMPORARY 테이블**(유니크 없음)로 세션 안에서 실제 테이블을 가린 뒤 잰다.
 *   `CREATE/DROP TEMPORARY TABLE` 은 MySQL 에서 암묵 커밋을 일으키지 않는다. 테스트가 끝나면 반드시 지운다.
 */
class CashReceiptActiveSlotMigrationTest extends ModuleTestCase
{
    private const TABLE = 'ecommerce_order_cash_receipts';

    /** 실제 테이블을 가리는 임시 테이블을 만들었는지 */
    private bool $shadowed = false;

    /**
     * 임시 테이블이 남아 있으면 지운 뒤 부모 정리를 진행합니다. (같은 연결의 다음 테스트가 가려진 테이블을 보지 않도록)
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->dropShadowTable();

        parent::tearDown();
    }

    /**
     * 대상 마이그레이션 인스턴스를 가져옵니다.
     *
     * @return object 마이그레이션
     */
    private function migration(): object
    {
        return require base_path('modules/_bundled/sirsoft-ecommerce/database/migrations/2026_11_13_000001_add_active_slot_keys_to_ecommerce_order_cash_receipts_table.php');
    }

    /**
     * 마이그레이션의 백필(제약 생성 전 데이터 정리)만 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runBackfill(): void
    {
        $migration = $this->migration();
        $method = new ReflectionMethod($migration, 'backfill');
        $method->setAccessible(true);
        $method->invoke($migration);
    }

    /**
     * 같은 이름의 TEMPORARY 테이블로 실제 이력 테이블을 가립니다. (유니크·생성 컬럼 없음 — 제약 도입 전 상태)
     *
     * @return void 반환값 없음
     */
    private function shadowWithUnconstrainedTable(): void
    {
        $table = '`'.DB::getTablePrefix().self::TABLE.'`';

        DB::statement("CREATE TEMPORARY TABLE {$table} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `order_id` BIGINT UNSIGNED NOT NULL,
            `provider` VARCHAR(50) NOT NULL DEFAULT '',
            `receipt_key` VARCHAR(200) NULL,
            `transaction_type` VARCHAR(20) NOT NULL,
            `receipt_type` VARCHAR(20) NOT NULL DEFAULT 'income',
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `issue_status` VARCHAR(20) NOT NULL,
            `error_code` VARCHAR(100) NULL,
            `error_message` TEXT NULL,
            `slot_released_at` TIMESTAMP NULL,
            `cancel_target_id` BIGINT UNSIGNED NULL,
            `created_at` TIMESTAMP NULL,
            `updated_at` TIMESTAMP NULL
        )");

        $this->shadowed = true;
    }

    /**
     * 실제 테이블을 가리던 TEMPORARY 테이블을 지웁니다.
     *
     * @return void 반환값 없음
     */
    private function dropShadowTable(): void
    {
        if (! $this->shadowed) {
            return;
        }

        DB::statement('DROP TEMPORARY TABLE IF EXISTS `'.DB::getTablePrefix().self::TABLE.'`');
        $this->shadowed = false;
    }

    /**
     * 이력 행을 직접 기록합니다. (마이그레이션이 읽는 것과 같은 저장 형태)
     *
     * @param  int  $orderId  주문 ID
     * @param  string  $type  거래 유형 (issue / cancel)
     * @param  string  $status  발급 상태 (IN_PROGRESS / COMPLETED / FAILED)
     * @param  string|null  $receiptKey  영수증 키
     * @param  string  $createdAt  기록 시각
     * @return int 생성된 이력 ID
     */
    private function row(int $orderId, string $type, string $status, ?string $receiptKey, string $createdAt = '2026-10-01 10:00:00'): int
    {
        return (int) DB::table(self::TABLE)->insertGetId([
            'order_id' => $orderId,
            'provider' => 'tosspayments',
            'receipt_key' => $receiptKey,
            'transaction_type' => $type,
            'receipt_type' => 'income',
            'amount' => 11000,
            'issue_status' => $status,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /**
     * 이력 행 하나를 읽습니다.
     *
     * @param  int  $id  이력 ID
     * @return object 이력 행
     */
    private function find(int $id): object
    {
        return DB::table(self::TABLE)->where('id', $id)->first();
    }

    /**
     * 같은 주문에 슬롯을 쥔 발급 행이 둘 이상이면(과거 경합으로 생긴 중복) 최신 1건만 슬롯을 남기고 나머지는 해제하며,
     * 행은 지우지 않고 주문·행 ID 를 warning 로그로 남긴다. 중복이 없는 주문은 건드리지 않는다.
     *
     * @scenario case=duplicate_slot_holders
     *
     * @effects migration_keeps_latest_duplicate_slot_holder_and_logs
     *
     * @return void 반환값 없음
     */
    public function test_duplicate_slot_holders_keep_latest_and_are_logged(): void
    {
        $duplicated = Order::factory()->create();
        $single = Order::factory()->create();

        $this->shadowWithUnconstrainedTable();
        Log::spy();

        $older = $this->row((int) $duplicated->id, 'issue', 'COMPLETED', 'rk-dup-1');
        $newer = $this->row((int) $duplicated->id, 'issue', 'COMPLETED', 'rk-dup-2');
        $alone = $this->row((int) $single->id, 'issue', 'COMPLETED', 'rk-single');

        $this->runBackfill();

        $this->assertNotNull($this->find($older)->slot_released_at, '오래된 중복 발급의 슬롯이 해제되지 않았습니다');
        $this->assertNull($this->find($newer)->slot_released_at, '최신 발급은 슬롯을 유지해야 한다');
        $this->assertNull($this->find($alone)->slot_released_at, '중복이 없는 주문을 건드렸습니다');
        $this->assertSame(3, DB::table(self::TABLE)->count(), '중복 정리가 행을 지웠습니다');

        // 백필 뒤 주문당 슬롯 보유 행은 1건 이하 — 유니크 인덱스를 걸 수 있는 상태다.
        $holdersPerOrder = DB::table(self::TABLE)
            ->where('transaction_type', 'issue')
            ->whereIn('issue_status', ['IN_PROGRESS', 'COMPLETED'])
            ->whereNull('slot_released_at')
            ->groupBy('order_id')
            ->selectRaw('COUNT(*) as holders')
            ->pluck('holders')
            ->map(fn ($n) => (int) $n)
            ->all();
        $this->assertSame([1, 1], $holdersPerOrder);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($duplicated, $newer, $older) {
                return str_contains($message, '활성 발급 중복')
                    && ($context['order_id'] ?? null) === (int) $duplicated->id
                    && ($context['kept_receipt_id'] ?? null) === $newer
                    && ($context['released_receipt_ids'] ?? null) === [$older];
            })
            ->once();
    }

    /**
     * 같은 발급 행을 가리키는 완료 취소가 둘 이상이면(과거 취소 경합) 먼저 기록된 취소만 대상으로 연결하고 나머지는 비워
     * 로그로 남긴다. 취소 대상은 「같은 주문·같은 키·더 작은 ID 의 최신 발급」 이다.
     *
     * @scenario case=duplicate_open_cancels
     *
     * @effects migration_links_only_first_completed_cancel, migration_backfills_cancel_target_and_releases_slot
     *
     * @return void 반환값 없음
     */
    public function test_duplicate_completed_cancels_link_only_the_first(): void
    {
        $order = Order::factory()->create();

        $this->shadowWithUnconstrainedTable();
        Log::spy();

        // 같은 키로 두 번 발급된 과거 이력 — 취소 대상은 취소보다 앞선 최신 발급이어야 한다.
        $firstIssue = $this->row((int) $order->id, 'issue', 'FAILED', 'rk-1', '2026-10-01 09:00:00');
        $issue = $this->row((int) $order->id, 'issue', 'COMPLETED', 'rk-1', '2026-10-01 10:00:00');
        $cancelA = $this->row((int) $order->id, 'cancel', 'COMPLETED', 'rk-1', '2026-10-02 10:00:00');
        $cancelB = $this->row((int) $order->id, 'cancel', 'COMPLETED', 'rk-1', '2026-10-02 10:00:01');

        $this->runBackfill();

        $this->assertSame($issue, (int) $this->find($cancelA)->cancel_target_id, '취소 대상은 더 작은 ID 의 최신 발급이어야 한다');
        $this->assertNull($this->find($cancelB)->cancel_target_id, '두 번째 완료 취소가 같은 발급에 연결됐습니다');
        $this->assertSame('2026-10-02 10:00:00', (string) $this->find($issue)->slot_released_at, '해제 시각은 연결된 취소의 기록 시각이다');
        $this->assertNull($this->find($firstIssue)->slot_released_at);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, '완료 취소가 여러 건')
                && ($context['linked_cancel_id'] ?? null) === $cancelA
                && ($context['unlinked_cancel_id'] ?? null) === $cancelB)
            ->once();
    }

    /**
     * 실제 테이블에서 취소된 발급은 취소 대상이 채워지고 슬롯이 해제돼, 생성 컬럼상 활성 발급 키는 NULL·열린 취소 키는 발급 ID 가 된다.
     * 취소되지 않은 다른 주문의 발급은 슬롯을 유지한다.
     *
     * @scenario case=cancelled_issue_backfill
     *
     * @effects migration_backfills_cancel_target_and_releases_slot
     *
     * @return void 반환값 없음
     */
    public function test_cancelled_issue_is_backfilled_on_real_table(): void
    {
        $cancelledOrder = Order::factory()->create();
        $activeOrder = Order::factory()->create();

        $issue = $this->row((int) $cancelledOrder->id, 'issue', 'COMPLETED', 'rk-c1', '2026-10-01 10:00:00');
        $cancel = $this->row((int) $cancelledOrder->id, 'cancel', 'COMPLETED', 'rk-c1', '2026-10-03 12:00:00');
        $active = $this->row((int) $activeOrder->id, 'issue', 'COMPLETED', 'rk-a1');

        $this->runBackfill();

        $issueRow = $this->find($issue);
        $cancelRow = $this->find($cancel);
        $activeRow = $this->find($active);

        $this->assertSame($issue, (int) $cancelRow->cancel_target_id);
        $this->assertSame('2026-10-03 12:00:00', (string) $issueRow->slot_released_at);
        $this->assertNull($issueRow->active_issue_order_key, '취소된 발급이 여전히 활성 슬롯을 쥐고 있습니다');
        $this->assertSame($issue, (int) $cancelRow->open_cancel_target_key);

        $this->assertNull($activeRow->slot_released_at);
        $this->assertSame((int) $activeOrder->id, (int) $activeRow->active_issue_order_key);
    }

    /**
     * 실제 테이블에서 남은 처리 중 이력은 발급·취소 모두 FAILED(IN_PROGRESS_STALE)로 정리되고, 영수증 키 없이 완료된 발급은
     * 슬롯을 놓는다 — 두 경우 모두 생성 컬럼상 슬롯·열린 취소에서 빠진다.
     *
     * @scenario case=stale_and_keyless_backfill
     *
     * @effects migration_fails_leftover_in_progress_and_releases_keyless
     *
     * @return void 반환값 없음
     */
    public function test_leftover_in_progress_fails_and_keyless_issue_releases_slot(): void
    {
        $inProgressOrder = Order::factory()->create();
        $keylessOrder = Order::factory()->create();

        $inProgressIssue = $this->row((int) $inProgressOrder->id, 'issue', 'IN_PROGRESS', null);
        $keyless = $this->row((int) $keylessOrder->id, 'issue', 'COMPLETED', null);

        $this->runBackfill();

        $staleRow = $this->find($inProgressIssue);
        $this->assertSame('FAILED', $staleRow->issue_status);
        $this->assertSame('IN_PROGRESS_STALE', $staleRow->error_code);
        $this->assertNotEmpty($staleRow->error_message);
        $this->assertNull($staleRow->active_issue_order_key);

        $keylessRow = $this->find($keyless);
        $this->assertSame('COMPLETED', $keylessRow->issue_status, '키 없는 완료 발급의 상태를 바꾸면 안 된다');
        $this->assertNotNull($keylessRow->slot_released_at);
        $this->assertNull($keylessRow->active_issue_order_key);
    }
}
