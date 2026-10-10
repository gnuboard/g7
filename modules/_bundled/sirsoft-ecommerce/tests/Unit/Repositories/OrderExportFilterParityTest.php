<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\OrderRepository;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 주문 목록 필터 ↔ 엑셀 내려받기 필터 파리티 테스트
 *
 * 목록(`getListWithFilters`)과 내려받기(`getForExport`)는 같은 필터 경로를 써야 한다.
 * 축이 한쪽에만 늘면 화면은 걸러진 건수인데 내려받은 파일은 전건이 되고, 오류도 경고도
 * 없이 파일 내용만 달라져 운영자가 알아챌 방법이 없다.
 *
 * 모집단은 손으로 적지 않는다 — 필터 축은 `applyListFilters` 의 **소스에서 파생**하며,
 * 새 축이 그 메서드에 추가되면 대응 케이스가 없다는 이유로 이 테스트가 붉어진다.
 */
class OrderExportFilterParityTest extends ModuleTestCase
{
    protected OrderRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new OrderRepository(new Order);
    }

    /**
     * 축마다 "그 주문을 제외하는" 필터 payload.
     *
     * 어느 축이든 목록이 0건이면 내려받기도 0건이어야 한다. 축이 내려받기에서 무시되면
     * 그 축의 케이스만 1건을 돌려주므로 어떤 축이 빠졌는지 실패 메시지가 지목한다.
     *
     * @return array<string, array<string, mixed>> 필터 키 => payload
     */
    private function excludingPayloads(): array
    {
        return [
            'order_status' => ['order_status' => [OrderStatusEnum::CANCELLED->value]],
            'user_id' => ['user_id' => 999999],
            'orderer_uuid' => ['orderer_uuid' => 'no-such-uuid'],
            'member_type' => ['member_type' => 'guest'],
            'search_keyword' => ['search_keyword' => 'zzz-no-such-order-zzz', 'search_field' => 'all'],
            'date_type' => ['date_type' => 'ordered_at', 'start_date' => '2999-01-01'],
            'claim_refund_status' => ['claim_refund_status' => ['refund_complete']],
            'claim_return_status' => ['claim_return_status' => ['return_requested']],
            'claim_exchange_status' => ['claim_exchange_status' => ['exchange_requested']],
            'payment_method' => ['payment_method' => ['no_such_method']],
            'shipping_type' => ['shipping_type' => ['no_such_type']],
            'category_id' => ['category_id' => 999999],
            'min_amount' => ['min_amount' => 99999999],
            'max_amount' => ['max_amount' => 1],
            'country_codes' => ['country_codes' => ['ZZ']],
            'min_shipping_amount' => ['min_shipping_amount' => 99999999],
            'max_shipping_amount' => ['max_shipping_amount' => -1],
            'shipping_policy_id' => ['shipping_policy_id' => 999999],
            'order_device' => ['order_device' => ['no_such_device']],
            'claim_presence' => ['claim_presence' => 'any'],
            'claim_exchange_statuses' => ['claim_exchange_statuses' => ['requested']],
            'claim_return_statuses' => ['claim_return_statuses' => ['requested']],
            'claim_refund_statuses' => ['claim_refund_statuses' => ['requested']],
            'claim_cancel_types' => ['claim_cancel_types' => ['full']],
            'claim_fault_types' => ['claim_fault_types' => ['seller']],
            'claim_requested_from' => ['claim_requested_from' => '2999-01-01'],
            'claim_requested_to' => ['claim_requested_to' => '1999-01-01'],
        ];
    }

    /**
     * 단독으로는 행을 거르지 못해 다른 케이스의 payload 안에서만 도달하는 키.
     *
     * @return array<string, string> 키 => 어느 케이스가 덮는지
     */
    private function coveredByOtherCases(): array
    {
        return [
            // 숨김 상태 표시 여부를 정하는 수식어 — order_status 케이스와 같은 분기다.
            'include_pending_order' => 'order_status',
            'search_field' => 'search_keyword',
            'start_date' => 'date_type',
            'end_date' => 'date_type',
        ];
    }

    /**
     * 필터 축 모집단을 `applyListFilters` 와 그것이 부르는 자기 클래스 메서드의 소스에서 파생합니다.
     *
     * @return array<int, string> 필터 키 목록
     */
    private function derivedFilterKeys(): array
    {
        $class = new \ReflectionClass(OrderRepository::class);
        $source = file($class->getFileName());

        $keys = [];
        $visited = [];
        $queue = ['applyListFilters'];

        while ($queue !== []) {
            $name = array_shift($queue);

            if (isset($visited[$name]) || ! $class->hasMethod($name)) {
                continue;
            }

            $visited[$name] = true;
            $method = $class->getMethod($name);

            // 자기 클래스에 선언된 메서드만 따라간다 (trait 헬퍼는 필터 축을 정의하지 않는다).
            if ($method->getDeclaringClass()->getName() !== OrderRepository::class) {
                continue;
            }

            $body = implode('', array_slice(
                $source,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            ));

            if (preg_match_all("/\\\$filters\['([a-z_]+)'\]/", $body, $m)) {
                $keys = array_merge($keys, $m[1]);
            }

            if (preg_match_all('/\$this->([a-zA-Z]+)\(/', $body, $m)) {
                $queue = array_merge($queue, $m[1]);
            }
        }

        return array_values(array_unique($keys));
    }

    #[Test]
    public function derived_filter_axes_all_have_a_parity_case(): void
    {
        // 파생이 비면 `array_diff([], $covered)` 도 빈 배열이라 아래 단언이 **조용히 통과**한다.
        // `applyListFilters` 개명·접근 형태 변경(`Arr::get($filters, …)` 등)이 전부 그 경로다.
        $this->assertGreaterThanOrEqual(
            25,
            count($this->derivedFilterKeys()),
            '목록 필터 축을 소스에서 파생하지 못했다 — 이 검사가 아무것도 재지 않고 있다'
        );

        $covered = array_merge(
            array_keys($this->excludingPayloads()),
            array_keys($this->coveredByOtherCases()),
        );

        $missing = array_diff($this->derivedFilterKeys(), $covered);

        $this->assertSame(
            [],
            array_values($missing),
            '목록 필터에 새 축이 추가됐는데 내려받기 파리티 케이스가 없다: '.implode(', ', $missing)
        );
    }

    #[Test]
    public function export_applies_every_list_filter_axis(): void
    {
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value,
        ]);

        // 통제군 — 필터가 없으면 두 경로 모두 그 주문을 싣는다.
        $this->assertSame([$order->id], $this->listIds([]));
        $this->assertSame([$order->id], $this->exportIds([]));

        $leaked = [];

        foreach ($this->excludingPayloads() as $axis => $filters) {
            $listIds = $this->listIds($filters);
            $exportIds = $this->exportIds($filters);

            $this->assertSame([], $listIds, "목록이 {$axis} 축으로 걸러내지 못했다 (케이스 payload 재검토 필요)");

            if ($exportIds !== []) {
                $leaked[] = $axis;
            }
        }

        $this->assertSame(
            [],
            $leaked,
            '내려받기가 무시한 필터 축: '.implode(', ', $leaked)
        );
    }

    /**
     * @param  array  $filters  필터
     * @return array<int, int> 목록 결과 주문 ID
     */
    private function listIds(array $filters): array
    {
        return collect($this->repository->getListWithFilters($filters, 100)->items())
            ->pluck('id')->sort()->values()->all();
    }

    /**
     * @param  array  $filters  필터
     * @return array<int, int> 내려받기 결과 주문 ID
     */
    private function exportIds(array $filters): array
    {
        return $this->repository->getForExport($filters)
            ->pluck('id')->sort()->values()->all();
    }
}
