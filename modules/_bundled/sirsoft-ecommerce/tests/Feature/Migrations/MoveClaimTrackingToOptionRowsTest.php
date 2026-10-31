<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * 헤더 송장 → 상품 행 백필 마이그레이션 테스트 (`2026_09_25_000003_move_claim_tracking_to_option_rows`)
 *
 * **트랜잭션 격리 — DDL 을 돌리지 않는다.** 테스트 DB 는 이미 이 마이그레이션까지 적용돼 헤더 송장 칸이
 * 없다. 헤더 칸을 되살리는 `down()` 과 헤더 칸이 있을 때의 `up()` 은 ALTER TABLE 을 내므로(MySQL 암묵
 * 커밋 — 테스트 트랜잭션과 공유 테스트 DB 스키마를 깨뜨린다) 여기서 부르지 않는다. 대신
 *
 *  - 백필 규칙은 마이그레이션이 쓰는 **데이터 이동 함수**(`copyDirection`·`copyReturnAddress`)를 같은
 *    인스턴스에서 직접 불러 잰다 — 헤더 행은 마이그레이션이 읽는 것과 같은 모양의 객체다.
 *  - 멱등은 ⓐ 같은 백필을 두 번 돌려도 이미 적힌 행이 바뀌지 않음 ⓑ 이미 적용된 스키마에서 `up()` 이
 *    DDL·데이터 변경 없이 끝남으로 잰다.
 */
class MoveClaimTrackingToOptionRowsTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 통화 설정과 클레임 사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
    }

    /**
     * 통화 설정을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 대상 마이그레이션 인스턴스를 가져옵니다.
     *
     * @return object 마이그레이션
     */
    private function migration(): object
    {
        return require base_path('modules/_bundled/sirsoft-ecommerce/database/migrations/2026_09_25_000003_move_claim_tracking_to_option_rows.php');
    }

    /**
     * 마이그레이션의 비공개 메서드를 호출합니다.
     *
     * @param  object  $migration  마이그레이션
     * @param  string  $method  메서드명
     * @param  mixed  ...$args  인수
     * @return mixed 반환값
     */
    private function invoke(object $migration, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod($migration, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($migration, ...$args);
    }

    /**
     * 마이그레이션의 비공개 속성을 읽습니다.
     *
     * @param  object  $migration  마이그레이션
     * @param  string  $property  속성명
     * @return mixed 값
     */
    private function property(object $migration, string $property): mixed
    {
        $reflection = new ReflectionProperty($migration, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($migration);
    }

    /**
     * 수량 3 교환을 승인해 행 3개(1·1·1)로 나눈 뒤, 행 상태를 회수도착·승인·반려로 둡니다.
     *
     * @return array{0: OrderExchange, 1: array<int, int>} [교환, 행 ID (회수도착·승인·반려 순)]
     */
    private function exchangeWithThreeRows(): array
    {
        $order = $this->deliveredClaimOrder(quantity: 3, exchangeFee: 0);
        $service = app(OrderExchangeService::class);
        $exchange = $service->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 3]],
            'reason_code' => 'size_mismatch',
        ]);
        $exchange = $service->transition($exchange->fresh(), ExchangeStatusEnum::APPROVED);

        $first = (int) $exchange->fresh('exchangeOptions')->exchangeOptions->first()->id;
        $second = $service->writeItemTracking($exchange->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [['id' => $first, 'quantity' => 1]], null, null)['split'][0]['remainder_id'];
        $third = $service->writeItemTracking($exchange->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [['id' => $second, 'quantity' => 1]], null, null)['split'][0]['remainder_id'];

        DB::table('ecommerce_order_exchange_options')->where('id', $first)->update(['option_status' => 'collected']);
        DB::table('ecommerce_order_exchange_options')->where('id', $second)->update(['option_status' => 'approved']);
        DB::table('ecommerce_order_exchange_options')->where('id', $third)->update(['option_status' => 'rejected']);
        DB::table('ecommerce_order_exchange_options')->whereIn('id', [$first, $second, $third])->update(['return_address_snapshot' => null]);

        return [$exchange->fresh(), [$first, $second, $third]];
    }

    /**
     * 백필 전 헤더 행과 같은 모양의 객체를 만듭니다. (마이그레이션이 `DB::table()->select()` 로 읽는 값)
     *
     * @param  int  $id  신청서 ID
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return object 헤더 행
     */
    private function header(int $id, array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => $id,
            'updated_at' => '2026-09-01 10:00:00',
            'return_address_snapshot' => json_encode(['recipient_name' => '반품센터', 'zipcode' => '06236', 'address1' => '서울 강남구 1'], JSON_UNESCAPED_UNICODE),
            'collection_carrier_id' => $this->testClaimCarrier()->id,
            'collection_tracking_number' => 'HDR-CL-1',
            'collection_completed_at' => '2026-09-03 15:00:00',
            'redelivery_carrier_id' => $this->testClaimCarrier()->id,
            'redelivery_tracking_number' => 'HDR-RD-1',
            'redelivered_at' => null,
        ]);
    }

    /**
     * 헤더 회수 송장은 **비종결** 행 전부로 복사되고(반려 행 제외), 등록 시각은 헤더 `updated_at`, 회수도착
     * 이후 행의 도착 시각은 헤더 `collection_completed_at` 이다. 재발송 송장도 같은 규칙이다.
     *
     * @return void 반환값 없음
     *
     * @effects backfill_copies_header_tracking_to_open_rows
     */
    public function test_header_tracking_is_copied_to_open_rows_with_arrival(): void
    {
        [$exchange, [$collected, $approved, $rejected]] = $this->exchangeWithThreeRows();
        $migration = $this->migration();
        $header = $this->header((int) $exchange->id);
        $collectedStatuses = $this->property($migration, 'collectedStatuses');

        $this->invoke($migration, 'copyDirection', 'ecommerce_order_exchange_options', 'order_exchange_id', $header, 'collection', $collectedStatuses['exchange']);
        $this->invoke($migration, 'copyDirection', 'ecommerce_order_exchange_options', 'order_exchange_id', $header, 'redelivery', $this->property($migration, 'redeliveredStatuses'));

        $rows = DB::table('ecommerce_order_exchange_options')->whereIn('id', [$collected, $approved, $rejected])->get()->keyBy('id');

        foreach ([$collected, $approved] as $open) {
            $this->assertSame('HDR-CL-1', $rows[$open]->collection_tracking_number);
            $this->assertSame((int) $header->collection_carrier_id, (int) $rows[$open]->collection_carrier_id);
            $this->assertSame('2026-09-01 10:00:00', (string) $rows[$open]->collection_shipped_at);
            $this->assertSame('HDR-RD-1', $rows[$open]->redelivery_tracking_number);
        }

        $this->assertNull($rows[$rejected]->collection_tracking_number, '반려 행에 송장이 복사됐습니다.');
        $this->assertNull($rows[$rejected]->redelivery_tracking_number);

        $this->assertSame('2026-09-03 15:00:00', (string) $rows[$collected]->collected_at);
        $this->assertNull($rows[$approved]->collected_at, '회수도착 전 행에 도착 시각이 찍혔습니다.');
        $this->assertNull($rows[$collected]->redelivered_at);
    }

    /**
     * 완료된 상품 행도 헤더 송장을 받는다 — 완료 전에 그 송장으로 회수·발송됐던 행이다. 계획 §3-1 문언은
     * 「비종결 행」 이지만, 완료 행을 빼면 이미 끝난 신청서의 송장 이력이 전부 사라져 고객·운영자 화면의
     * 송장 묶음이 「등록 전」 으로 되돌아간다. 빼는 것은 송장과 무관한 반려·철회 행뿐이다.
     *
     * @return void 반환값 없음
     *
     * @effects backfill_copies_header_tracking_to_completed_rows
     */
    public function test_completed_rows_keep_the_waybill_they_travelled_with(): void
    {
        [$exchange, [$collected]] = $this->exchangeWithThreeRows();
        DB::table('ecommerce_order_exchange_options')->where('id', $collected)->update(['option_status' => 'completed']);
        $migration = $this->migration();
        $header = $this->header((int) $exchange->id, ['redelivered_at' => '2026-09-05 11:00:00']);

        $this->invoke($migration, 'copyDirection', 'ecommerce_order_exchange_options', 'order_exchange_id', $header, 'collection', $this->property($migration, 'collectedStatuses')['exchange']);
        $this->invoke($migration, 'copyDirection', 'ecommerce_order_exchange_options', 'order_exchange_id', $header, 'redelivery', $this->property($migration, 'redeliveredStatuses'));

        $row = DB::table('ecommerce_order_exchange_options')->where('id', $collected)->first();

        $this->assertSame('HDR-CL-1', $row->collection_tracking_number, '완료 행에 회수 송장이 옮겨지지 않았습니다.');
        $this->assertSame('HDR-RD-1', $row->redelivery_tracking_number, '완료 행에 재발송 송장이 옮겨지지 않았습니다.');
        $this->assertSame('2026-09-03 15:00:00', (string) $row->collected_at);
        $this->assertSame('2026-09-05 11:00:00', (string) $row->redelivered_at);
    }

    /**
     * 백필은 멱등이다 — 다시 돌려도, 헤더 값이 달라져도 이미 송장·회수지가 있는 행은 바뀌지 않는다.
     * 헤더에 송장이 없으면 송장은 옮기지 않고 도착 시각만 옮긴다(송장 없이 도착한 행).
     *
     * @return void 반환값 없음
     *
     * @effects backfill_is_idempotent_and_keeps_existing_row_values
     */
    public function test_backfill_is_idempotent_and_keeps_existing_row_values(): void
    {
        [$exchange, [$collected, $approved]] = $this->exchangeWithThreeRows();
        $migration = $this->migration();
        $statuses = $this->property($migration, 'collectedStatuses')['exchange'];

        // 둘째 행은 이미 자기 송장을 갖고 있다.
        DB::table('ecommerce_order_exchange_options')->where('id', $approved)->update([
            'collection_carrier_id' => $this->testClaimCarrier()->id,
            'collection_tracking_number' => 'ROW-OWN',
        ]);

        $header = $this->header((int) $exchange->id);
        $this->invoke($migration, 'copyDirection', 'ecommerce_order_exchange_options', 'order_exchange_id', $header, 'collection', $statuses);
        $this->invoke($migration, 'copyReturnAddress', 'ecommerce_order_exchange_options', 'order_exchange_id', $header);

        $changed = $this->header((int) $exchange->id, [
            'collection_tracking_number' => 'HDR-CL-2',
            'collection_completed_at' => '2026-09-09 09:00:00',
            'return_address_snapshot' => json_encode(['recipient_name' => '다른 센터'], JSON_UNESCAPED_UNICODE),
        ]);
        $this->invoke($migration, 'copyDirection', 'ecommerce_order_exchange_options', 'order_exchange_id', $changed, 'collection', $statuses);
        $this->invoke($migration, 'copyReturnAddress', 'ecommerce_order_exchange_options', 'order_exchange_id', $changed);

        $rows = DB::table('ecommerce_order_exchange_options')->whereIn('id', [$collected, $approved])->get()->keyBy('id');
        $this->assertSame('HDR-CL-1', $rows[$collected]->collection_tracking_number);
        $this->assertSame('ROW-OWN', $rows[$approved]->collection_tracking_number, '행이 가진 송장을 헤더 값이 덮었습니다.');
        $this->assertSame('2026-09-03 15:00:00', (string) $rows[$collected]->collected_at);
        $this->assertSame('반품센터', json_decode((string) $rows[$collected]->return_address_snapshot, true)['recipient_name']);

        // 송장 없는 헤더 — 송장은 없고 도착 시각만 옮긴다.
        [$bare, [$bareCollected]] = $this->exchangeWithThreeRows();
        $noTracking = $this->header((int) $bare->id, ['collection_carrier_id' => null, 'collection_tracking_number' => null]);
        $this->invoke($migration, 'copyDirection', 'ecommerce_order_exchange_options', 'order_exchange_id', $noTracking, 'collection', $statuses);

        $row = DB::table('ecommerce_order_exchange_options')->where('id', $bareCollected)->first();
        $this->assertNull($row->collection_tracking_number);
        $this->assertSame('2026-09-03 15:00:00', (string) $row->collected_at);
    }

    /**
     * 반품도 같은 규칙이다 — 회수 송장·회수지가 반품 행으로 옮겨지고 회수도착 행에 도착 시각이 찍힌다.
     *
     * @return void 반환값 없음
     *
     * @effects backfill_copies_header_tracking_to_open_rows
     */
    public function test_return_header_tracking_is_copied_to_return_rows(): void
    {
        $order = $this->deliveredClaimOrder();
        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);
        $return = app(OrderReturnService::class)->transition($return, ReturnStatusEnum::APPROVED);
        $itemId = (int) $return->fresh('returnOptions')->returnOptions->first()->id;
        DB::table('ecommerce_order_return_options')->where('id', $itemId)->update(['option_status' => 'collected', 'return_address_snapshot' => null]);

        $migration = $this->migration();
        $header = $this->header((int) $return->id);
        $this->invoke($migration, 'copyDirection', 'ecommerce_order_return_options', 'order_return_id', $header, 'collection', $this->property($migration, 'collectedStatuses')['return']);
        $this->invoke($migration, 'copyReturnAddress', 'ecommerce_order_return_options', 'order_return_id', $header);

        $row = DB::table('ecommerce_order_return_options')->where('id', $itemId)->first();
        $this->assertSame('HDR-CL-1', $row->collection_tracking_number);
        $this->assertSame('2026-09-03 15:00:00', (string) $row->collected_at);
        $this->assertSame('반품센터', json_decode((string) $row->return_address_snapshot, true)['recipient_name']);
    }

    /**
     * 이미 적용된 스키마(헤더 송장 칸 없음)에서 `up()` 을 다시 돌려도 DDL·데이터 변경 없이 끝난다 — 재실행 멱등.
     *
     * 헤더 칸이 남아 있는 스키마라면 `up()` 이 ALTER TABLE 을 내므로 돌리지 않고 건너뛴다(공유 테스트 DB 보호).
     *
     * @return void 반환값 없음
     *
     * @effects backfill_rerun_on_applied_schema_is_a_no_op
     */
    public function test_up_on_an_applied_schema_is_a_no_op(): void
    {
        if (Schema::hasColumn('ecommerce_order_exchanges', 'collection_tracking_number')
            || Schema::hasColumn('ecommerce_order_returns', 'collection_tracking_number')
            || Schema::hasColumn('ecommerce_order_returns', 'refund_id')) {
            $this->markTestSkipped('헤더 송장 칸이 남은 스키마 — up() 이 DDL 을 내므로 트랜잭션 격리 테스트에서 돌리지 않는다.');
        }

        [$exchange, [$collected]] = $this->exchangeWithThreeRows();
        $before = DB::table('ecommerce_order_exchange_options')->where('order_exchange_id', $exchange->id)->orderBy('id')->get()->toArray();

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $this->migration()->up();

        $ddl = array_values(array_filter($statements, fn (string $sql) => preg_match('/^\s*(alter|create|drop|rename)\s/i', $sql) === 1));
        $writes = array_values(array_filter($statements, fn (string $sql) => preg_match('/^\s*(update|insert|delete)\s/i', $sql) === 1));

        $this->assertSame([], $ddl, '적용된 스키마에서 up() 이 DDL 을 냈습니다.');
        $this->assertSame([], $writes, '적용된 스키마에서 up() 이 데이터를 바꿨습니다.');
        $this->assertEquals($before, DB::table('ecommerce_order_exchange_options')->where('order_exchange_id', $exchange->id)->orderBy('id')->get()->toArray());
        $this->assertNotNull($collected);
    }
}
