<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 목록 "발송일" 정렬 회귀 테스트 (#492 D-19).
 *
 * 화면은 "최근 발송순 / 오래된 발송순" 정렬 옵션을 제공하는데 `shipped_at` 은 주문이 아니라
 * 배송 테이블(`ecommerce_order_shippings`)의 컬럼이다. 게이트가 이 값을 막고 있어 옵션을
 * 고르면 422 가 나고 목록은 그대로 남아 정렬이 적용된 것처럼 보였다.
 *
 * 관계 테이블 컬럼 정렬은 `SortsByRelatedColumn` 의 상관 서브쿼리로 해석한다. 이 테스트가
 * 지키는 불변식은 셋이다.
 *
 *   1. 주문별 **가장 늦은/이른** 발송일 기준으로 정렬된다
 *   2. 배송 행이 여러 건이어도 총 건수가 부풀지 않는다 (1:N 조인 금지)
 *   3. 발송 이력이 없는 주문도 목록에 남는다 (INNER JOIN 금지)
 *
 * 부하 설계: 픽스처를 **한 벌만** 만들고 한 메서드에서 세 불변식을 모두 단언한다. 사용자는
 * 1명을 재사용하고(팩토리 기본값은 주문마다 User 를 새로 만든다), 배송 행 6건은 INSERT 1회로
 * 심는다. 케이스마다 픽스처를 다시 만들면 같은 주문·배송을 반복해서 심게 된다.
 */
class OrderShippedAtSortTest extends ModuleTestCase
{
    /**
     * 주문번호 → 배송 행의 발송일시(사이트 저장값) 목록.
     *
     * SORT-NONE 은 배송 이력이 없는 주문이다 — INNER JOIN 으로 좁혀지면 목록에서 사라진다.
     */
    private const SHIPMENTS = [
        'SORT-A' => ['2026-07-05 10:00:00', '2026-07-20 10:00:00'],
        'SORT-B' => ['2026-07-10 10:00:00'],
        'SORT-C' => ['2026-07-01 10:00:00', '2026-07-15 10:00:00', '2026-07-08 10:00:00'],
        'SORT-NONE' => [],
    ];

    /**
     * 주문 4건과 배송 6건을 최소 쿼리로 심습니다.
     *
     * 주문은 팩토리를 쓰되 사용자를 1명으로 고정하고(기본값은 주문마다 User 생성),
     * 배송 행은 모델 이벤트가 필요 없으므로 쿼리 빌더로 한 번에 넣는다.
     */
    private function seedOrders(): void
    {
        $user = User::factory()->create();
        $shippingRows = [];

        foreach (self::SHIPMENTS as $orderNumber => $shippedAts) {
            $order = Order::factory()->create([
                'user_id' => $user->id,
                'order_number' => $orderNumber,
                'ordered_at' => '2026-07-01 00:00:00',
            ]);

            if ($shippedAts === []) {
                continue;
            }

            // 배송 행은 주문 옵션을 FK 로 요구한다. 옵션 자체는 정렬 판정과 무관하므로
            // 주문당 1건만 만들고 그 주문의 배송 행이 공유한다.
            $option = OrderOption::factory()->create(['order_id' => $order->id]);

            foreach ($shippedAts as $index => $shippedAt) {
                $shippingRows[] = [
                    'order_id' => $order->id,
                    'order_option_id' => $option->id,
                    'shipping_type' => 'delivery',
                    'shipping_status' => 'shipped',
                    'tracking_number' => $orderNumber.'-'.$index,
                    'shipped_at' => $shippedAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::table((new OrderShipping)->getTable())->insert($shippingRows);
    }

    /**
     * 목록 조회 결과의 주문번호 순서를 반환합니다.
     *
     * @param  string  $direction  정렬 방향 (asc|desc)
     * @return array{numbers: array<int, string>, total: int} 주문번호 순서와 총 건수
     */
    private function listSortedByShippedAt(string $direction): array
    {
        $paginator = app(OrderRepositoryInterface::class)
            ->getListWithFilters(['sort_by' => 'shipped_at', 'sort_order' => $direction], 20);

        return [
            'numbers' => array_map(fn ($row) => $row->order_number, $paginator->items()),
            'total' => $paginator->total(),
        ];
    }

    /**
     * 발송 이력이 있는 주문만 남긴 순서를 반환합니다.
     *
     * 발송 이력이 없는 주문의 `NULL` 정렬 위치는 DB 마다 다르므로(선행/후행) 순서 판정에서
     * 제외한다 — 존재 여부는 별도로 단언한다 (데이터베이스 독립성 원칙).
     *
     * @param  array<int, string>  $numbers  주문번호 순서
     * @return array<int, string> 발송 이력이 있는 주문번호 순서
     */
    private function withShipmentsOnly(array $numbers): array
    {
        return array_values(array_filter($numbers, fn ($n) => self::SHIPMENTS[$n] !== []));
    }

    /**
     * 관계 테이블 컬럼(발송일) 정렬의 세 불변식을 함께 검증한다.
     */
    public function test_발송일_정렬은_집계기준_정확하고_총건수와_미발송주문을_보존한다(): void
    {
        $this->seedOrders();

        $desc = $this->listSortedByShippedAt('desc');
        $asc = $this->listSortedByShippedAt('asc');

        // (1) 최근 발송순 — 주문별 가장 늦은 발송일 A(07-20) > C(07-15) > B(07-10)
        $this->assertSame(
            ['SORT-A', 'SORT-C', 'SORT-B'],
            $this->withShipmentsOnly($desc['numbers']),
            '주문별 최신 발송일 기준 내림차순이어야 합니다.'
        );

        // (1) 오래된 발송순 — 주문별 가장 이른 발송일 C(07-01) < A(07-05) < B(07-10)
        $this->assertSame(
            ['SORT-C', 'SORT-A', 'SORT-B'],
            $this->withShipmentsOnly($asc['numbers']),
            '주문별 최초 발송일 기준 오름차순이어야 합니다.'
        );

        // (2) 배송 행이 6건이어도 주문은 4건 — 총 건수가 늘면 1:N 조인으로 원 행이 부푼 것
        $this->assertSame(4, $desc['total'], '배송 행 수만큼 총 건수가 부풀면 안 됩니다.');
        $this->assertCount(4, $desc['numbers'], '페이지에 실린 행 수도 주문 수와 같아야 합니다.');

        // (3) 발송 이력이 없는 주문이 빠지면 INNER JOIN 으로 좁혀진 것
        $this->assertContains('SORT-NONE', $desc['numbers'], '미발송 주문이 내림차순 목록에서 사라졌습니다.');
        $this->assertContains('SORT-NONE', $asc['numbers'], '미발송 주문이 오름차순 목록에서 사라졌습니다.');
    }

    /**
     * 화면이 제공하는 발송일 정렬 옵션이 게이트에서 422 가 되지 않아야 한다.
     */
    public function test_발송일_정렬_요청이_422가_아니다(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders?sort_by=shipped_at&sort_order=desc&per_page=20')
            ->assertOk();
    }
}
