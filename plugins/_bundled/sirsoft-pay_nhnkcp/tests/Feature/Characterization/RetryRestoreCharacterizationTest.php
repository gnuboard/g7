<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Characterization;

use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Contracts\Support\RetryRestoreScenarios;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\PayNhnkcp\Concerns\RecordsPaymentWindowClosure;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 재결제 복구 특성화 대조 — NHN KCP
 *
 * 계약 전환 전의 KCP 복구 코드로 녹화한 결과(이커머스 `tests/Fixtures/payment-retry-restore/nhnkcp-*.json`)와
 * 전환 뒤 이 플러그인의 복구 경로(재시도 가능 판정 → 이커머스 계약 `restoreForRetry`)가 남기는 DB 원문이 바이트 단위로
 * 같아야 한다. 의도된 차이는 주문 상품 상태 하나다 — 결제 실패 처리가 취소로 바꾼 상품을 주문 대기로 되돌린다(부록 A5-2).
 */
class RetryRestoreCharacterizationTest extends PluginTestCase
{
    /**
     * 녹화된 KCP 시나리오 목록입니다.
     *
     * @return array<string, array{0: string}> 시나리오
     */
    public static function scenarios(): array
    {
        $cases = [];
        foreach (RetryRestoreScenarios::all()['nhnkcp'] as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /**
     * 시계를 돌려놓습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 전환된 KCP 복구 경로의 결과가 녹화 원문과 같다(상품 상태만 의도대로 다르다).
     *
     * @param  string  $scenario  시나리오 이름
     * @return void 반환값 없음
     *
     * @scenario case=retry_restore_characterization
     *
     * @effects kcp_retry_restore_matches_the_recorded_bytes
     */
    #[DataProvider('scenarios')]
    public function test_restore_matches_the_recorded_fixture(string $scenario): void
    {
        $fixture = json_decode((string) file_get_contents(RetryRestoreScenarios::fixturePath('nhnkcp', $scenario)), true);
        $this->assertIsArray($fixture, "녹화 파일이 없습니다: nhnkcp-{$scenario}");

        $order = RetryRestoreScenarios::prepare('nhnkcp', $scenario);
        $bridge = app(PaymentGatewayBridge::class);
        $record = $bridge->findOrderByNumber((string) $order->order_number, 'nhnkcp');
        $this->assertInstanceOf(OrderRecord::class, $record);

        $restored = $this->host($bridge)->restore($record);

        $this->assertInstanceOf(OrderRecord::class, $restored, "시나리오 {$scenario} 를 복구해야 한다");
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $restored->orderStatus);

        $actual = RetryRestoreScenarios::snapshot($order);
        $expected = $fixture['snapshot'];

        // 의도된 차이(A5-2) — 녹화본은 상품이 취소로 남았고, 계약은 주문 대기로 되돌린다
        $this->assertSame(['cancelled', 'cancelled'], $expected['option_statuses']);
        $this->assertSame(['pending_order', 'pending_order'], $actual['option_statuses']);
        unset($expected['option_statuses'], $actual['option_statuses']);

        $this->assertSame($expected, $actual, "시나리오 {$scenario} 의 DB 원문이 녹화본과 달라졌다");
    }

    /**
     * 복구 트레이트를 담은 최소 호스트를 만듭니다(호스트 요건: `$bridge`).
     *
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 계약
     * @return object 복구 메서드를 노출한 호스트
     */
    private function host(PaymentGatewayBridge $bridge): object
    {
        return new class($bridge)
        {
            use RecordsPaymentWindowClosure;

            /**
             * 트레이트가 쓰는 이커머스 결제 계약을 주입받습니다.
             *
             * @param  PaymentGatewayBridge  $bridge  이커머스 결제 계약
             */
            public function __construct(
                private readonly PaymentGatewayBridge $bridge,
            ) {}

            /**
             * 금액 대조 없이(가상계좌 PC 경로와 같은 호출) 복구합니다.
             *
             * @param  OrderRecord  $order  복구할 주문
             * @return OrderRecord|null 복구한 주문
             */
            public function restore(OrderRecord $order): ?OrderRecord
            {
                return $this->restoreKcpOrderForRetry($order, null);
            }
        };
    }
}
