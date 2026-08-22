<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Support;

use App\Contracts\ApiDoc\ApiDocSampleSeeder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayKginicis\Support\ApiDoc\ApiDocSampleService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * sirsoft-pay_kginicis API 문서 실측용 완전 샘플 시더 테스트.
 *
 * KG 이니시스 결제 도메인 대표 샘플(에스크로 + CBT 편의점 메타를 담은 kginicis
 * 결제 주문 + 배송지)이 생성되고, orders/{orderNumber} 계열 GET 실측을 위한
 * 도메인별 path_params 맵이 반환되며, 재실행 시 멱등한지 검증한다.
 */
class ApiDocSampleServiceTest extends PluginTestCase
{
    /**
     * @var string 샘플 주문번호 마커
     */
    private const SAMPLE_ORDER_NUMBER = 'APIDOC-KGINICIS-000001';

    /**
     * 실제 docgen 실행에서는 코어 ApiDocSampleService 가 먼저 실측 사용자
     * (apidoc-sample-user@example.com)를 시드하고, 그 뒤에 확장 시더가 병합된다.
     * 단위 테스트에서도 이 선행 조건(주문 소유자)을 만족시키기 위해 샘플 사용자를 만든다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create([
            'email' => 'apidoc-sample-user@example.com',
            'name' => 'API 문서 샘플 사용자',
        ]);
    }

    /**
     * 시더가 계약을 구현한다.
     */
    public function test_seeder_implements_contract(): void
    {
        $this->assertInstanceOf(ApiDocSampleSeeder::class, new ApiDocSampleService);
    }

    /**
     * 시더가 kginicis 결제 도메인 대표 샘플을 멱등 생성한다.
     */
    public function test_seed_creates_kginicis_escrow_cbt_order(): void
    {
        (new ApiDocSampleService)->seed();

        $order = Order::query()->where('order_number', self::SAMPLE_ORDER_NUMBER)->first();
        $this->assertNotNull($order, '샘플 kginicis 주문이 생성되어야 한다');

        $payment = OrderPayment::query()
            ->where('order_id', $order->id)
            ->where('pg_provider', 'kginicis')
            ->first();
        $this->assertNotNull($payment, 'kginicis 결제 레코드가 생성되어야 한다');

        // escrow-delivery formData 의 findEscrowPayment 조건
        $this->assertTrue((bool) $payment->is_escrow, '에스크로 결제여야 한다');
        $this->assertNotEmpty($payment->transaction_id, 'TID 가 존재해야 한다');

        // cbt-cvs summary 의 isCbtCvsMeta 판정 조건 (is_cbt=true + pay_method=CVS)
        $meta = is_array($payment->payment_meta) ? $payment->payment_meta : [];
        $this->assertTrue(($meta['is_cbt'] ?? false) === true, 'CBT 결제 메타여야 한다');
        $this->assertSame('CVS', strtoupper((string) ($meta['pay_method'] ?? '')), 'CVS 결제수단이어야 한다');

        // escrow-delivery 배송지 prefill 실측용 shipping 주소
        $hasShipping = DB::table('ecommerce_order_addresses')
            ->where('order_id', $order->id)
            ->where('address_type', 'shipping')
            ->exists();
        $this->assertTrue($hasShipping, '배송지가 생성되어야 한다');
    }

    /**
     * 시더가 orders/{orderNumber} 실측용 path_params 맵을 도메인 그룹별로 반환한다.
     */
    public function test_seed_returns_path_params_map_for_order_domains(): void
    {
        $map = (new ApiDocSampleService)->seed();

        // 5개 라우트 도메인 그룹이 {orderNumber} 를 공유
        foreach (['orders', 'vbank', 'transaction', 'cbt', 'payment'] as $domain) {
            $this->assertArrayHasKey($domain, $map, "{$domain} 도메인 맵이 있어야 한다");
            $this->assertSame('order_number', $map[$domain]['key']);
            $this->assertSame(self::SAMPLE_ORDER_NUMBER, $map[$domain]['value']);
            $this->assertArrayHasKey('path_params', $map[$domain]);
            $this->assertSame(
                self::SAMPLE_ORDER_NUMBER,
                $map[$domain]['path_params']['orderNumber'],
                "{$domain} 의 orderNumber 치환값이 실제 주문번호여야 한다",
            );
        }
    }

    /**
     * 시더는 멱등하다 — 재실행 시 중복 레코드를 만들지 않는다.
     */
    public function test_seed_is_idempotent(): void
    {
        $service = new ApiDocSampleService;
        $service->seed();
        $service->seed();

        $orderCount = Order::query()->where('order_number', self::SAMPLE_ORDER_NUMBER)->count();
        $this->assertSame(1, $orderCount, '주문은 1건만 존재해야 한다');

        $order = Order::query()->where('order_number', self::SAMPLE_ORDER_NUMBER)->first();
        $paymentCount = OrderPayment::query()
            ->where('order_id', $order->id)
            ->where('pg_provider', 'kginicis')
            ->count();
        $this->assertSame(1, $paymentCount, 'kginicis 결제는 1건만 존재해야 한다');

        $addressCount = DB::table('ecommerce_order_addresses')
            ->where('order_id', $order->id)
            ->where('address_type', 'shipping')
            ->count();
        $this->assertSame(1, $addressCount, '배송지는 1건만 존재해야 한다');
    }

    /**
     * 샘플 사용자가 없으면(코어 샘플/기존 사용자 부재) 빈 맵을 반환한다.
     */
    public function test_seed_returns_empty_map_when_no_user(): void
    {
        // RefreshDatabase + 시더로 생성된 사용자를 모두 제거해 폴백 경로를 검증.
        User::query()->delete();

        $map = (new ApiDocSampleService)->seed();

        $this->assertSame([], $map, '사용자가 없으면 빈 맵이어야 한다');
    }
}
