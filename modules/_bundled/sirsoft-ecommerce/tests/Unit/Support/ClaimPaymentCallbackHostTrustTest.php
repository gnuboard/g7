<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentCallback;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentRedirect;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ForgesRequestHost;

/**
 * 클레임 결제 공용 콜백의 신청서 복귀 주소가 요청 Host 를 따르지 않는지 검증합니다.
 *
 * 복귀 경로 빌더(ClaimPaymentRedirect)는 브라우저용 상대 경로를 돌려준다 — 그 값을 그대로 redirect() 에
 * 넘기면 요청 Host 로 절대화되므로, 공용 콜백이 사이트 주소로 감싸는지 본다.
 */
class ClaimPaymentCallbackHostTrustTest extends ModuleTestCase
{
    use ForgesRequestHost;

    /**
     * 사이트 주소를 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->pinForgedHostSite();
    }

    /**
     * 신뢰 프록시 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->resetForgedHostProxies();
        parent::tearDown();
    }

    /**
     * 승인 처리 예외의 신청서 복귀는 사이트 주소다.
     *
     * @scenario host_header=forged, sink=claim_result_redirect
     *
     * @effects claim_result_redirect_uses_site_address
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_approval_failure_returns_to_site_address(string $root, array $server): void
    {
        $this->bindForgedRequest($root, $server, '/plugins/vendor-pg/payment/callback');

        $order = OrderFactory::new()->create(['user_id' => User::factory()->create()->id, 'order_number' => 'ORD-HOST-5', 'currency' => 'KRW']);
        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-HOST-5',
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 3000,
        ]);
        $payment = ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-HOST-5',
            'pg_provider' => 'tosspayments',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);

        $service = $this->createMock(ClaimPaymentService::class);
        $service->method('approvePgPayment')->willThrowException(new \RuntimeException('probe'));
        $this->app->instance(ClaimPaymentService::class, $service);

        $response = ClaimPaymentCallback::approveAndRedirect($payment, []);

        $this->assertSame(
            self::forgedHostSite().ClaimPaymentRedirect::fail($payment, 'approve_failed'),
            $response->headers->get('Location'),
        );
    }

    /**
     * 승인 성공의 신청서 복귀는 사이트 주소다.
     *
     * @scenario host_header=forged, sink=claim_result_redirect
     *
     * @effects claim_result_redirect_uses_site_address
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_approval_success_returns_to_site_address(string $root, array $server): void
    {
        $this->bindForgedRequest($root, $server, '/plugins/vendor-pg/payment/callback');
        $payment = $this->createPendingReturnPayment('6');

        $service = $this->createMock(ClaimPaymentService::class);
        $service->method('approvePgPayment')->willReturn($payment);
        $this->app->instance(ClaimPaymentService::class, $service);

        $response = ClaimPaymentCallback::approveAndRedirect($payment, []);

        $this->assertSame(
            self::forgedHostSite().ClaimPaymentRedirect::success($payment),
            $response->headers->get('Location'),
        );
    }

    /**
     * 모듈이 승인을 거절(OrderClaimException)했을 때의 신청서 복귀는 사이트 주소다.
     *
     * @scenario host_header=forged, sink=claim_result_redirect
     *
     * @effects claim_result_redirect_uses_site_address
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_approval_rejection_returns_to_site_address(string $root, array $server): void
    {
        $this->bindForgedRequest($root, $server, '/plugins/vendor-pg/payment/callback');
        $payment = $this->createPendingReturnPayment('7');

        $service = $this->createMock(ClaimPaymentService::class);
        $service->method('approvePgPayment')->willThrowException(OrderClaimException::paymentFailed('PG_DECLINED'));
        $this->app->instance(ClaimPaymentService::class, $service);

        $response = ClaimPaymentCallback::approveAndRedirect($payment, []);

        $this->assertSame(
            self::forgedHostSite().ClaimPaymentRedirect::fail($payment, 'PG_DECLINED'),
            $response->headers->get('Location'),
        );
    }

    /**
     * 반품 신청서에 걸린 결제 대기 클레임 결제 건을 만듭니다.
     *
     * @param  string  $suffix  주문·신청서·결제 번호 접미사
     * @return ClaimPayment 결제 대기 건
     */
    private function createPendingReturnPayment(string $suffix): ClaimPayment
    {
        $order = OrderFactory::new()->create(['user_id' => User::factory()->create()->id, 'order_number' => 'ORD-HOST-'.$suffix, 'currency' => 'KRW']);
        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-HOST-'.$suffix,
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 3000,
        ]);

        return ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-HOST-'.$suffix,
            'pg_provider' => 'tosspayments',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);
    }
}
