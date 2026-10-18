<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Support\ShippingPolicySnapshot;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송지 스냅샷 백필 마이그레이션 테스트 (S2c)
 *
 * 1.0.0-beta.5 이전에 생성된 주문은 `shipping_policy_applied_snapshot.address` 가 비어 있어
 * 환불 재계산(폴백 없음 → 도서산간 0원)과 클레임 배송비 산정(주문 주소 폴백 → 도서산간 가산)이
 * 갈린다. 백필로 두 경로를 같은 값에 수렴시키는지 검증한다.
 *
 * 검증 축:
 * - 배송지가 빈 스냅샷을 주문 배송지로 채우는지
 * - 이미 배송지가 있는 스냅샷은 건드리지 않는지 (멱등)
 * - 주소 행이 없는 주문은 건너뛰고 스냅샷을 훼손하지 않는지
 * - 주소 행은 있으나 국가 코드가 빈 주문도 건너뛰는지 — 확인된 적 없는 국가를 데이터로
 *   확정하지 않는다. 이 구간은 백필 후에도 두 경로가 갈릴 수 있어 스텝이 앞의 "주소 행 없음"
 *   과 **따로 세어 로그에 남긴다**(마이그레이션 클래스 docblock 참조)
 * - 국내 우편번호 없으면 해외 우편번호로 폴백하는지 (클레임 경로 폴백과 같은 규칙)
 * - 항목 목록을 보존하는지
 */
class BackfillShippingSnapshotAddressTest extends ModuleTestCase
{
    private const MIGRATION_NS = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_3_0\\Migrations\\BackfillShippingSnapshotAddress';

    protected function setUp(): void
    {
        parent::setUp();

        // DataMigration 클래스는 오토로드 밖 — 직접 로드
        require_once dirname(__DIR__, 3).'/upgrades/data/1.3.0/migrations/04_BackfillShippingSnapshotAddress.php';
    }

    private function migration(): object
    {
        $class = self::MIGRATION_NS;

        return new $class;
    }

    private function context(): UpgradeContext
    {
        return new UpgradeContext(
            fromVersion: '1.1.1',
            toVersion: '1.3.0',
            currentStep: '1.3.0',
        );
    }

    /**
     * 배송지 행을 가진 주문을 만듭니다.
     *
     * @param  array|null  $snapshot  배송정책 스냅샷
     * @param  array  $addressOverrides  주소 행 오버라이드
     * @return Order 주문
     */
    private function makeOrder(?array $snapshot, array $addressOverrides = []): Order
    {
        $order = Order::factory()->create([
            'shipping_policy_applied_snapshot' => $snapshot,
        ]);

        OrderAddress::factory()->create(array_merge([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'recipient_country_code' => 'KR',
            'zipcode' => '63000',
            'intl_postal_code' => null,
        ], $addressOverrides));

        return $order;
    }

    /**
     * 저장된 스냅샷을 배열로 읽습니다.
     *
     * @param  Order  $order  주문
     * @return array 스냅샷
     */
    private function storedSnapshot(Order $order): array
    {
        $raw = DB::table('ecommerce_orders')
            ->where('id', $order->id)
            ->value('shipping_policy_applied_snapshot');

        return json_decode((string) $raw, true) ?: [];
    }

    public function test_empty_address_is_filled_from_the_order_shipping_address(): void
    {
        $order = $this->makeOrder(ShippingPolicySnapshot::make([['product_option_id' => 1]], []));

        $this->migration()->run($this->context());

        $snapshot = $this->storedSnapshot($order);

        $this->assertSame('KR', $snapshot['address']['country_code']);
        $this->assertSame('63000', $snapshot['address']['zipcode']);
    }

    public function test_items_are_preserved(): void
    {
        $items = [['product_option_id' => 7, 'policy_id' => 3]];
        $order = $this->makeOrder(ShippingPolicySnapshot::make($items, []));

        $this->migration()->run($this->context());

        $this->assertSame($items, $this->storedSnapshot($order)['items']);
    }

    public function test_existing_address_is_left_untouched(): void
    {
        $existing = ['country_code' => 'US', 'zipcode' => '10001'];
        $order = $this->makeOrder(ShippingPolicySnapshot::make([], $existing));

        $this->migration()->run($this->context());

        $this->assertSame($existing, $this->storedSnapshot($order)['address']);
    }

    public function test_running_twice_changes_nothing_further(): void
    {
        $order = $this->makeOrder(ShippingPolicySnapshot::make([], []));

        $this->migration()->run($this->context());
        $first = $this->storedSnapshot($order);

        $this->migration()->run($this->context());

        $this->assertSame($first, $this->storedSnapshot($order));
    }

    public function test_order_without_a_shipping_address_row_is_skipped(): void
    {
        $order = Order::factory()->create([
            'shipping_policy_applied_snapshot' => ShippingPolicySnapshot::make([['product_option_id' => 1]], []),
        ]);

        $this->migration()->run($this->context());

        $snapshot = $this->storedSnapshot($order);

        $this->assertSame([], $snapshot['address']);
        $this->assertSame([['product_option_id' => 1]], $snapshot['items']);
    }

    public function test_missing_country_code_is_treated_as_unresolvable(): void
    {
        $order = $this->makeOrder(
            ShippingPolicySnapshot::make([], []),
            ['recipient_country_code' => null],
        );

        $this->migration()->run($this->context());

        $this->assertSame([], $this->storedSnapshot($order)['address']);
    }

    /**
     * 국내 우편번호가 비면 해외 우편번호로 떨어진다 — 클레임 경로 폴백과 같은 규칙이다.
     */
    public function test_international_postal_code_is_used_when_zipcode_is_empty(): void
    {
        $order = $this->makeOrder(
            ShippingPolicySnapshot::make([], []),
            ['recipient_country_code' => 'us', 'zipcode' => '', 'intl_postal_code' => '10001'],
        );

        $this->migration()->run($this->context());

        $snapshot = $this->storedSnapshot($order);

        $this->assertSame('US', $snapshot['address']['country_code']);
        $this->assertSame('10001', $snapshot['address']['zipcode']);
    }

    public function test_zipcode_is_null_when_neither_postal_code_exists(): void
    {
        $order = $this->makeOrder(
            ShippingPolicySnapshot::make([], []),
            ['zipcode' => '', 'intl_postal_code' => null],
        );

        $this->migration()->run($this->context());

        $this->assertNull($this->storedSnapshot($order)['address']['zipcode']);
    }

    public function test_null_snapshot_orders_are_not_touched(): void
    {
        $order = $this->makeOrder(null);

        $this->migration()->run($this->context());

        $this->assertNull(
            DB::table('ecommerce_orders')->where('id', $order->id)->value('shipping_policy_applied_snapshot')
        );
    }

    /**
     * 구형(정수 키 + 'address' 문자열 키 혼합) 스냅샷도 배송지만 채우고 형태는 바꾸지 않는다.
     *
     * 형태 정규화는 1.0.5 스텝의 책임이다 — 여기서 모양까지 바꾸면 그 스텝을 통과한 저장소와
     * 통과하지 않은 저장소에서 결과가 갈린다.
     */
    public function test_legacy_shaped_snapshot_keeps_its_shape(): void
    {
        $order = $this->makeOrder([0 => ['product_option_id' => 5]]);

        $this->migration()->run($this->context());

        $snapshot = $this->storedSnapshot($order);

        $this->assertSame('KR', $snapshot['address']['country_code']);
        $this->assertArrayNotHasKey('items', $snapshot);
        $this->assertSame(['product_option_id' => 5], $snapshot['0']);
    }

    /**
     * 백필 후에는 환불 재계산 경로와 클레임 산정 경로가 같은 배송지를 읽는다.
     *
     * 이 수렴이 백필의 목적이다 — 두 경로가 갈리면 취소 가능 금액(D19)의 ① 축과
     * 클레임 청구액이 서로 다른 전제 위에 서게 된다.
     */
    public function test_both_read_paths_converge_after_the_backfill(): void
    {
        $order = $this->makeOrder(ShippingPolicySnapshot::make([], []));

        $this->migration()->run($this->context());

        $snapshot = $this->storedSnapshot($order);

        // 환불 재계산 경로 (폴백 없음)
        $adjustmentAddress = ShippingPolicySnapshot::address($snapshot);

        // 클레임 산정 경로 (폴백 있음 — 백필 후에는 폴백이 발동하지 않는다)
        $claimAddress = ShippingPolicySnapshot::deliveryAddress(
            $snapshot,
            fn () => ['country_code' => 'XX', 'zipcode' => '99999'],
        );

        $this->assertSame('KR', $adjustmentAddress['country_code']);
        $this->assertSame('KR', $claimAddress['country_code']);
        $this->assertSame($adjustmentAddress['zipcode'], $claimAddress['zipcode']);
    }
}
