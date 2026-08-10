<?php

namespace Modules\Sirsoft\Ecommerce\Benchmark;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\TempOrderService;

/**
 * 주문 생성 계측 대상 — `g7:bench` write 축이 실행하는 저장 경로
 *
 * 주문 생성은 재고 차감·쿠폰 소진·마일리지 적립·알림 발행이 한 트랜잭션에 얽힌 저장
 * 경로이므로, 계측 커맨드가 쿼리를 스스로 조립해 재는 방식으로는 실제 비용을 알 수 없습니다.
 * 그래서 이 클래스가 실제 서비스 경로(`OrderProcessingService::createFromTempOrder`)를
 * 호출하고, 커맨드는 시간·쿼리 건수만 잽니다.
 *
 * 임시 주문을 팩토리로 직접 조립하지 않고 실제 서비스(`TempOrderService`)로 만드는 이유는,
 * `createFromTempOrder` 가 저장된 계산 파라미터로 금액을 **재계산해 저장값과 대조**하기
 * 때문입니다. 손으로 만든 계산 결과는 재계산값과 어긋나 `OrderAmountChangedException` 으로
 * 막히며, 어긋나지 않게 맞추더라도 그때는 실제 결제 경로가 아닌 다른 것을 재게 됩니다.
 *
 * 준비(`prepare`)는 계측 구간 밖에서 회차마다 실행됩니다 — 임시 주문은 한 번만 주문으로
 * 전환되므로 회차마다 새로 필요하고, 그 준비 비용이 측정값에 섞이면 안 됩니다. 여기서 만든
 * 상품·회원·주문은 계측 커맨드가 감싼 트랜잭션 롤백으로 전부 되돌아갑니다.
 */
class OrderCreationBenchmark
{
    public function __construct(
        private readonly TempOrderService $tempOrderService,
        private readonly OrderProcessingService $orderProcessingService,
    ) {}

    /**
     * 계측 회차 1건에 필요한 선행 상태를 준비합니다. (계측 구간 밖)
     *
     * 기존 상품을 골라 쓰지 않고 매 회차 새로 만드는 이유는, 설치본마다 상품 구성·재고·
     * 판매상태가 달라 같은 조건에서 재고 있는 단일 옵션 상품 1건을 스스로 세우는 편이
     * 회차 간·환경 간 비교 가능성을 보장하기 때문입니다.
     *
     * @param  int  $run  계측 회차 번호
     * @return array{temp_order: TempOrder, user: User, expected_total_amount: float} 계측 컨텍스트
     */
    public function prepare(int $run): array
    {
        $user = User::factory()->create();

        $product = Product::factory()->create([
            'stock_quantity' => 10000,
            'has_options' => false,
            'option_groups' => null,
            // 기본 배송정책 해석에 맡긴다 — 특정 정책을 지목하면 그 정책의 비용만 재게 된다
            'shipping_policy_id' => null,
        ]);

        $option = ProductOption::factory()->forProduct($product)->create([
            'stock_quantity' => 10000,
            'price_adjustment' => 0,
            'is_default' => true,
            'is_active' => true,
        ]);

        // 구매 대상 제한 검증이 Auth::user() 의 역할을 읽으므로 인증 주체를 세운다.
        // 콘솔에는 세션이 없어 login() 대신 setUser() 를 쓴다.
        Auth::setUser($user);

        $tempOrder = $this->tempOrderService->createTempOrderFromDirectItems(
            items: [[
                'product_id' => $product->id,
                'product_option_id' => $option->id,
                'quantity' => 1,
            ]],
            userId: $user->id,
            cartKey: null,
        );

        return [
            'temp_order' => $tempOrder,
            'user' => $user,
            // 프론트엔드가 보내는 결제예정금액에 해당 — 임시 주문의 최종 금액을 그대로 쓴다
            'expected_total_amount' => (float) $tempOrder->getFinalAmount(),
        ];
    }

    /**
     * 계측 대상 — 임시 주문을 실제 주문으로 생성합니다.
     *
     * @param  array{temp_order: TempOrder, user: User, expected_total_amount: float}  $context  prepare 산출물
     * @return Order 생성된 주문
     */
    public function create(array $context): Order
    {
        return $this->orderProcessingService->createFromTempOrder(
            tempOrder: $context['temp_order'],
            ordererInfo: [
                'name' => '성능계측',
                'phone' => '01000000000',
                'email' => $context['user']->email,
            ],
            shippingInfo: [
                'recipient_name' => '성능계측',
                'recipient_phone' => '01000000000',
                'country_code' => 'KR',
                'zipcode' => '06236',
                'address' => '서울특별시 강남구 테헤란로 1',
                'address_detail' => '1층',
            ],
            paymentMethod: 'card',
            expectedTotalAmount: $context['expected_total_amount'],
        );
    }
}
