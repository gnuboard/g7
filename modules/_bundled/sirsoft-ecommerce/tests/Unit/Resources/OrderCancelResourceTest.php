<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderCancelResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderResource;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderCancelResource / OrderResource 취소 이력 노출 테스트
 *
 * 주문상세 화면(관리자/유저)에 취소 사유·상세 사유·취소일시를 표시하기 위한
 * cancels 직렬화 검증.
 */
class OrderCancelResourceTest extends ModuleTestCase
{
    /**
     * 클레임 사유(refund)를 생성합니다.
     *
     * @param  string  $code  사유 코드
     * @param  array<string, string>  $name  다국어 이름
     * @return ClaimReason 생성된 사유
     */
    private function makeReason(string $code, array $name): ClaimReason
    {
        // 일부 코드(etc 등)는 시더가 이미 생성하므로 firstOrCreate 로 중복 INSERT 회피.
        return ClaimReason::firstOrCreate(
            ['type' => 'refund', 'code' => $code],
            [
                'name' => $name,
                'fault_type' => 'customer',
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );
    }

    /**
     * 취소 이력 한 건을 생성합니다.
     *
     * @param  Order  $order  주문
     * @param  string  $cancelNumber  취소번호
     * @param  string  $reasonType  사유 코드
     * @param  string|null  $reasonDetail  상세 사유
     * @param  CancelTypeEnum  $type  취소 유형
     * @return OrderCancel 생성된 취소 이력
     */
    private function makeCancel(
        Order $order,
        string $cancelNumber,
        string $reasonType,
        ?string $reasonDetail = null,
        CancelTypeEnum $type = CancelTypeEnum::FULL,
        ?string $cancelledAt = null,
    ): OrderCancel {
        return OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => $cancelNumber,
            'cancel_type' => $type,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => $reasonType,
            'cancel_reason' => $reasonDetail,
            'items_snapshot' => [],
            'cancelled_at' => $cancelledAt ?? now(),
        ]);
    }

    /**
     * 사유 코드가 ClaimReason 다국어 라벨로 변환되고 상세 사유가 노출되는지 확인합니다.
     */
    public function test_resource_resolves_reason_label_and_detail(): void
    {
        app()->setLocale('ko');
        $this->makeReason('change_of_mind', ['ko' => '단순 변심', 'en' => 'Change of mind']);

        $order = OrderFactory::new()->create();
        $cancel = $this->makeCancel($order, 'CXL-1', 'change_of_mind', '색상이 마음에 안 듦');

        $resource = (new OrderCancelResource($cancel))->resolve();

        $this->assertSame('단순 변심', $resource['cancel_reason_label']);
        $this->assertSame('색상이 마음에 안 듦', $resource['cancel_reason_detail']);
        $this->assertSame('change_of_mind', $resource['cancel_reason_type']);
        $this->assertNotNull($resource['cancel_type_label']);
        $this->assertNotNull($resource['cancelled_at_formatted']);
    }

    /**
     * 매칭되는 ClaimReason 이 없으면 코드 원문으로 폴백하고 상세 사유는 null 일 수 있음을 확인합니다.
     */
    public function test_resource_falls_back_to_raw_code_when_reason_missing(): void
    {
        $order = OrderFactory::new()->create();
        $cancel = $this->makeCancel($order, 'CXL-2', 'unknown_code', null);

        $resource = (new OrderCancelResource($cancel))->resolve();

        $this->assertSame('unknown_code', $resource['cancel_reason_label']);
        $this->assertNull($resource['cancel_reason_detail']);
    }

    /**
     * OrderResource 가 cancels 관계를 최근순으로 직렬화하는지 확인합니다.
     */
    public function test_order_resource_includes_cancels_list_recent_first(): void
    {
        $this->makeReason('change_of_mind', ['ko' => '단순 변심', 'en' => 'Change of mind']);
        $this->makeReason('etc', ['ko' => '기타', 'en' => 'Etc']);

        $order = OrderFactory::new()->create();
        $this->makeCancel($order, 'CXL-OLD', 'change_of_mind', null, CancelTypeEnum::PARTIAL, '2026-06-01 10:00:00');
        // 두 번째 취소가 더 최근(cancelled_at 큼)
        $this->makeCancel($order, 'CXL-NEW', 'etc', '기타 상세 사유', CancelTypeEnum::FULL, '2026-06-02 10:00:00');

        /** @var OrderRepositoryInterface $repository */
        $repository = app(OrderRepositoryInterface::class);
        $loaded = $repository->findWithRelations($order->id);

        // 실제 HTTP 응답 직렬화 형태로 변환 (AnonymousResourceCollection → 배열)
        $array = (new OrderResource($loaded))->response(request())->getData(true)['data'];

        $this->assertArrayHasKey('cancels', $array);
        $cancels = $array['cancels'];
        $this->assertCount(2, $cancels);
        // 최근순 정렬: 첫 항목이 CXL-NEW
        $this->assertSame('CXL-NEW', $cancels[0]['cancel_number']);
        $this->assertSame('기타 상세 사유', $cancels[0]['cancel_reason_detail']);
        $this->assertSame('CXL-OLD', $cancels[1]['cancel_number']);
    }

    /**
     * 취소 이력이 없으면 cancels 가 빈 배열인지 확인합니다.
     */
    public function test_order_resource_cancels_empty_when_no_cancellation(): void
    {
        $order = OrderFactory::new()->create();

        /** @var OrderRepositoryInterface $repository */
        $repository = app(OrderRepositoryInterface::class);
        $loaded = $repository->findWithRelations($order->id);

        $array = (new OrderResource($loaded))->response(request())->getData(true)['data'];

        $this->assertArrayHasKey('cancels', $array);
        $this->assertSame([], $array['cancels']);
    }
}
