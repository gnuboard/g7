<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Validate;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderExchangeFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderExchangeOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ShippingPolicyClaimAddressFactory;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\OrderRepository;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyService;
use Modules\Sirsoft\Ecommerce\Services\StockService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 삭제 경로가 FK CASCADE 에 기대지 않는지 검증 (#599 validate P4)
 *
 * CASCADE 가 걸린 DB 에서는 명시 삭제가 없어도 자식 행이 사라져 결함이 드러나지 않는다. 그래서
 * 외래키 검사를 끈 세션(`SET FOREIGN_KEY_CHECKS=0` — DDL 이 아니라 세션 변수라 테스트 트랜잭션을
 * 커밋하지 않는다)에서 삭제를 돌려 **코드가 직접 지운 것만** 남는지 본다.
 */
class ValidateP4DeletionIntegrityTest extends ModuleTestCase
{
    /**
     * 테스트가 끝나면 외래키 검사를 되돌리고 목을 닫습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Schema::enableForeignKeyConstraints();
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 회수지가 딸린 배송정책을 만듭니다.
     *
     * @return ShippingPolicy 배송정책
     */
    private function policyWithClaimAddresses(): ShippingPolicy
    {
        $address = ShippingPolicyClaimAddressFactory::new()->forReturn()->create();
        ShippingPolicyClaimAddressFactory::new()->forExchange()->create([
            'shipping_policy_id' => $address->shipping_policy_id,
        ]);

        return ShippingPolicy::findOrFail($address->shipping_policy_id);
    }

    /**
     * 배송정책의 회수지 행 수를 셉니다.
     *
     * @param  int  $policyId  배송정책 ID
     * @return int 회수지 행 수
     */
    private function claimAddressCount(int $policyId): int
    {
        return DB::table('ecommerce_shipping_policy_claim_addresses')->where('shipping_policy_id', $policyId)->count();
    }

    /**
     * 배송정책 단건 삭제가 회수지를 명시적으로 지운다.
     *
     * 수정 전 실패 이유: `delete()` 가 국가별 설정만 지우고 회수지는 FK CASCADE 에 맡겨, 외래키가
     * 꺼진 환경에서는 삭제된 정책의 회수지가 남았다.
     *
     * @scenario defect=cascade_dependency
     *
     * @effects shipping_policy_delete_removes_claim_addresses_without_cascade
     *
     * @return void 반환값 없음
     */
    public function test_shipping_policy_delete_removes_claim_addresses_without_cascade(): void
    {
        $policy = $this->policyWithClaimAddresses();
        $this->assertSame(2, $this->claimAddressCount($policy->id));

        Schema::disableForeignKeyConstraints();
        app(ShippingPolicyService::class)->delete($policy);
        Schema::enableForeignKeyConstraints();

        $this->assertSame(0, $this->claimAddressCount($policy->id), '삭제된 배송정책의 회수지가 남았습니다');
    }

    /**
     * 배송정책 일괄 삭제도 회수지를 명시적으로 지운다.
     *
     * 수정 전 실패 이유: `bulkDelete()` 역시 회수지를 지우지 않았다.
     *
     * @scenario defect=cascade_dependency
     *
     * @effects shipping_policy_bulk_delete_removes_claim_addresses_without_cascade
     *
     * @return void 반환값 없음
     */
    public function test_shipping_policy_bulk_delete_removes_claim_addresses_without_cascade(): void
    {
        $first = $this->policyWithClaimAddresses();
        $second = $this->policyWithClaimAddresses();

        Schema::disableForeignKeyConstraints();
        app(ShippingPolicyService::class)->bulkDelete([$first->id, $second->id]);
        Schema::enableForeignKeyConstraints();

        $this->assertSame(0, $this->claimAddressCount($first->id));
        $this->assertSame(0, $this->claimAddressCount($second->id));
    }

    /**
     * 교환·반품 신청서가 딸린 주문을 만듭니다.
     *
     * @return array{order: Order, exchange: OrderExchange, returnId: int} 주문과 신청서
     */
    private function orderWithClaims(): array
    {
        $order = Order::factory()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create();

        $exchange = OrderExchangeFactory::new()->create(['order_id' => $order->id]);
        OrderExchangeOptionFactory::new()->create([
            'order_exchange_id' => $exchange->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
        ]);

        $return = OrderReturnFactory::new()->create(['order_id' => $order->id]);
        OrderReturnOptionFactory::new()->create([
            'order_return_id' => $return->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
        ]);

        return ['order' => $order->fresh(), 'exchange' => $exchange, 'returnId' => $return->id];
    }

    /**
     * 주문 삭제가 교환·반품 신청 상품 행을 명시적으로 지운다.
     *
     * 수정 전 실패 이유: 신청서 헤더만 지우고 신청 상품 행은 헤더 FK CASCADE 에 맡겨, 외래키가 꺼진
     * 환경에서는 헤더 없는 상품 행이 남았다.
     *
     * @scenario defect=cascade_dependency
     *
     * @effects order_delete_removes_claim_item_rows_without_cascade
     *
     * @return void 반환값 없음
     */
    public function test_order_delete_removes_claim_item_rows_without_cascade(): void
    {
        ['order' => $order, 'exchange' => $exchange, 'returnId' => $returnId] = $this->orderWithClaims();

        Schema::disableForeignKeyConstraints();
        app(OrderService::class)->delete($order);
        Schema::enableForeignKeyConstraints();

        $this->assertSame(0, DB::table('ecommerce_order_exchange_options')->where('order_exchange_id', $exchange->id)->count());
        $this->assertSame(0, DB::table('ecommerce_order_return_options')->where('order_return_id', $returnId)->count());
    }

    /**
     * 주문 삭제의 DB 정리가 한 트랜잭션이다 — 마지막 단계가 실패하면 앞서 지운 신청서도 되돌아간다.
     *
     * 수정 전 실패 이유: 관계 삭제가 트랜잭션 없이 차례로 실행돼, 주문 행 삭제가 실패해도 이미
     * 지운 교환 신청서는 사라진 채 남았다.
     *
     * @scenario defect=delete_atomicity
     *
     * @effects order_delete_rolls_back_claim_records_when_a_later_step_fails
     *
     * @return void 반환값 없음
     */
    public function test_order_delete_rolls_back_claim_records_when_a_later_step_fails(): void
    {
        ['order' => $order, 'exchange' => $exchange] = $this->orderWithClaims();

        $repository = Mockery::mock(OrderRepository::class, [new Order])->makePartial();
        $repository->shouldReceive('delete')->andThrow(new \RuntimeException('주문 행 삭제 실패'));

        $service = new OrderService(
            $repository,
            app(UserAddressRepositoryInterface::class),
            app(StockService::class),
            app(ClaimAttachmentService::class),
        );

        try {
            $service->delete($order);
            $this->fail('주문 행 삭제 실패가 전파되어야 합니다');
        } catch (\RuntimeException $e) {
            $this->assertSame('주문 행 삭제 실패', $e->getMessage());
        }

        $this->assertNotNull(OrderExchange::find($exchange->id), '실패한 삭제가 교환 신청서만 지운 채 남겼습니다');
        $this->assertSame(1, DB::table('ecommerce_order_exchange_options')->where('order_exchange_id', $exchange->id)->count());
    }
}
