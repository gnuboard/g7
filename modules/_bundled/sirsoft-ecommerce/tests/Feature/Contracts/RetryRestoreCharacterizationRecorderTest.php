<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Contracts;

use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Contracts\Support\LegacyKcpRestoreHost;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Contracts\Support\RetryRestoreScenarios;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * 재결제 복구 특성화 녹화기 
 *
 * 결제 계약으로 옮기기 전의 결제사 복구 코드(NHN KCP `RecordsPaymentWindowClosure::restoreRetryableKcpOrder`,
 * 나이스페이먼츠 `PaymentCallbackController::restoreRetryableNicepayOrder`)를 그대로 돌려 결과를 녹화 파일로 남긴다.
 * `G7_CHARACTERIZE=record` 일 때만 파일을 쓰고, 그 밖에는 건너뛴다(대조는 PaymentGatewayBridgeTest).
 * 결제사 코드가 계약으로 옮겨진 뒤에는 원본 메서드가 없으므로 이 녹화기도 건너뛴다 — 녹화 파일이 기준이다.
 */
class RetryRestoreCharacterizationRecorderTest extends ModuleTestCase
{
    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 녹화 대상 시나리오 목록입니다.
     *
     * @return array<string, array{0: string, 1: string}> 결제사 id, 시나리오
     */
    public static function scenarios(): array
    {
        $cases = [];
        foreach (RetryRestoreScenarios::all() as $pg => $names) {
            foreach ($names as $name) {
                $cases["{$pg}:{$name}"] = [$pg, $name];
            }
        }

        return $cases;
    }

    /**
     * 결제사 현행 복구 코드를 돌려 결과를 녹화합니다.
     *
     * @param  string  $pg  결제사 id
     * @param  string  $scenario  시나리오 이름
     *
     * @scenario case=record_legacy_restore
     *
     * @effects legacy_retry_restore_is_recorded_as_fixture
     *
     * @return void 반환값 없음
     */
    #[DataProvider('scenarios')]
    public function test_record_legacy_restore(string $pg, string $scenario): void
    {
        if (getenv('G7_CHARACTERIZE') !== 'record') {
            $this->markTestSkipped('녹화 모드(G7_CHARACTERIZE=record)에서만 녹화 파일을 씁니다.');
        }

        $order = RetryRestoreScenarios::prepare($pg, $scenario);
        $restored = $pg === 'nhnkcp' ? $this->runLegacyKcp($order) : $this->runLegacyNicepay($order);
        $this->assertTrue($restored, "현행 {$pg} 복구가 시나리오 {$scenario} 를 복구해야 녹화할 수 있습니다.");

        $snapshot = RetryRestoreScenarios::snapshot($order);
        if (! is_dir(RetryRestoreScenarios::FIXTURE_DIR)) {
            mkdir(RetryRestoreScenarios::FIXTURE_DIR, 0775, true);
        }

        file_put_contents(
            RetryRestoreScenarios::fixturePath($pg, $scenario),
            json_encode([
                'source' => $pg === 'nhnkcp'
                    ? 'Plugins\\Sirsoft\\PayNhnkcp\\Concerns\\RecordsPaymentWindowClosure::restoreRetryableKcpOrder'
                    : 'Plugins\\Sirsoft\\PayNicepayments\\Controllers\\PaymentCallbackController::restoreRetryableNicepayOrder',
                'pg' => $pg,
                'scenario' => $scenario,
                'restored_at' => RetryRestoreScenarios::RESTORED_AT,
                'snapshot' => $snapshot,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
        );
    }

    /**
     * NHN KCP 현행 복구(트레이트 메서드)를 돌립니다.
     *
     * @param  Order  $order  주문
     * @return bool 복구 여부
     */
    private function runLegacyKcp(Order $order): bool
    {
        $trait = 'Plugins\\Sirsoft\\PayNhnkcp\\Concerns\\RecordsPaymentWindowClosure';
        if (! trait_exists($trait) || ! method_exists($trait, 'restoreRetryableKcpOrder')) {
            $this->markTestSkipped('NHN KCP 현행 복구 코드가 없습니다(계약 전환 뒤).');
        }

        $host = new LegacyKcpRestoreHost;
        $method = (new ReflectionClass($host))->getMethod('restoreRetryableKcpOrder');

        return (bool) $method->invoke($host, $order, null);
    }

    /**
     * 나이스페이먼츠 현행 복구(컨트롤러 비공개 메서드)를 돌립니다.
     *
     * @param  Order  $order  주문
     * @return bool 복구 여부
     */
    private function runLegacyNicepay(Order $order): bool
    {
        $class = 'Plugins\\Sirsoft\\PayNicepayments\\Controllers\\PaymentCallbackController';
        if (! class_exists($class) || ! method_exists($class, 'restoreRetryableNicepayOrder')) {
            $this->markTestSkipped('나이스페이먼츠 현행 복구 코드가 없습니다(계약 전환 뒤).');
        }

        $reflection = new ReflectionClass($class);
        $controller = $reflection->newInstanceWithoutConstructor();

        return (bool) $reflection->getMethod('restoreRetryableNicepayOrder')->invoke($controller, $order, $order->payment);
    }
}
