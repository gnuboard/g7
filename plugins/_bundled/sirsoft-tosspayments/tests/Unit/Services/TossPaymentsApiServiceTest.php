<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\Tosspayments\Services\TossPaymentsApiService;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * TossPaymentsApiService 단위 테스트
 */
class TossPaymentsApiServiceTest extends PluginTestCase
{
    /**
     * PluginSettingsService mock을 생성합니다.
     *
     * @param  array  $settings  반환할 설정값
     */
    private function mockSettingsService(array $settings): PluginSettingsService
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')
            ->with('sirsoft-tosspayments')
            ->willReturn($settings);

        return $mock;
    }

    /**
     * 테스트 모드에서 테스트 시크릿 키를 사용하는지 확인
     */
    public function test_constructor_uses_test_secret_key_in_test_mode(): void
    {
        $service = new TossPaymentsApiService($this->mockSettingsService([
            'is_test_mode' => true,
            'test_secret_key' => 'test_sk_abc123',
            'live_secret_key' => 'live_sk_xyz789',
        ]));

        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty('secretKey');
        $prop->setAccessible(true);

        $this->assertEquals('test_sk_abc123', $prop->getValue($service));
    }

    /**
     * 라이브 모드에서 라이브 시크릿 키를 사용하는지 확인
     */
    public function test_constructor_uses_live_secret_key_in_live_mode(): void
    {
        $service = new TossPaymentsApiService($this->mockSettingsService([
            'is_test_mode' => false,
            'test_secret_key' => 'test_sk_abc123',
            'live_secret_key' => 'live_sk_xyz789',
        ]));

        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty('secretKey');
        $prop->setAccessible(true);

        $this->assertEquals('live_sk_xyz789', $prop->getValue($service));
    }

    /**
     * 설정이 비어있을 때 기본값(테스트 모드, 빈 키)으로 초기화되는지 확인
     */
    public function test_constructor_defaults_to_test_mode_with_empty_key(): void
    {
        $service = new TossPaymentsApiService($this->mockSettingsService([]));

        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty('secretKey');
        $prop->setAccessible(true);

        $this->assertEquals('', $prop->getValue($service));
    }

    /**
     * 플러그인 설정이 null일 때 기본값으로 초기화되는지 확인
     */
    public function test_constructor_handles_null_settings_gracefully(): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')
            ->with('sirsoft-tosspayments')
            ->willReturn(null);

        $service = new TossPaymentsApiService($mock);

        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty('secretKey');
        $prop->setAccessible(true);

        $this->assertEquals('', $prop->getValue($service));
    }

    /**
     * 현금영수증 API 호출용 서비스를 만듭니다.
     */
    private function makeService(): TossPaymentsApiService
    {
        return new TossPaymentsApiService($this->mockSettingsService([
            'test_mode' => true,
            'test_secret_key' => 'test_sk_dummy',
        ]));
    }

    /**
     * 발급은 토스 현금영수증 엔드포인트로 요청 본문을 그대로 보내야 합니다.
     */
    public function test_issue_cash_receipt_posts_to_cash_receipts_endpoint(): void
    {
        Http::fake(['*' => Http::response(['receiptKey' => 'RK_1', 'issueNumber' => '123'], 200)]);

        $this->makeService()->issueCashReceipt(['orderId' => 'ORD-1', 'amount' => 1000]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.tosspayments.com/v1/cash-receipts'
                && $request->method() === 'POST'
                && $request->data()['orderId'] === 'ORD-1';
        });
    }

    /**
     * 취소는 cancelReason 을 반드시 실어야 합니다.
     *
     * 토스의 필수 파라미터이며, 누락 시 400 INVALID_REQUEST 로 거부된다.
     * Http::fake 는 본문을 검증하지 않으므로 "보냈다" 가 아니라 "필수 필드가 들어있다" 를 단언한다.
     */
    public function test_cancel_cash_receipt_sends_cancel_reason(): void
    {
        Http::fake(['*' => Http::response(['receiptKey' => 'c_RK_1'], 200)]);

        $this->makeService()->cancelCashReceipt('RK_1', '금액 변경');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.tosspayments.com/v1/cash-receipts/RK_1/cancel'
                && ! empty($request->data()['cancelReason']);
        });
    }

    /**
     * 멱등키를 넘기면 Idempotency-Key 헤더로 전송되어야 합니다.
     *
     * 이 헤더가 없으면 네트워크 재시도가 국세청에 이중 신고된다.
     */
    public function test_idempotency_key_is_sent_as_header(): void
    {
        Http::fake(['*' => Http::response(['receiptKey' => 'RK_1'], 200)]);

        $this->makeService()->issueCashReceipt(['orderId' => 'ORD-1'], 'ORD-1-cr1');

        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', 'ORD-1-cr1'));
    }

    /**
     * 멱등키를 넘기지 않으면 Idempotency-Key 헤더가 붙지 않아야 합니다.
     */
    public function test_idempotency_key_header_absent_when_not_provided(): void
    {
        Http::fake(['*' => Http::response(['receiptKey' => 'RK_1'], 200)]);

        $this->makeService()->issueCashReceipt(['orderId' => 'ORD-1']);

        Http::assertSent(fn ($request) => ! $request->hasHeader('Idempotency-Key'));
    }
}
