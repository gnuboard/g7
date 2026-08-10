<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Repositories\BizppurioDispatchRepository;
use Plugins\Sirsoft\MessageBizppurio\Services\DispatchResultService;
use Plugins\Sirsoft\MessageBizppurio\Services\ResultCodeResolver;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * DispatchResultService — 코어 로그 id 배열 → 결과 표시 맵 변환 검증 (A-2 표시 주입).
 *
 * ResultCodeResolver 재사용으로 상태·`사유 (코드)`·잔액부족·대체발송을 파생하고, 매칭되지 않는
 * 로그 id 는 맵에서 빠지는지(빈 셀) 확인한다. 전화번호 등 민감정보는 결과에 포함하지 않는다.
 */
class DispatchResultServiceTest extends PluginTestCase
{
    private DispatchResultService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DispatchResultService(
            new BizppurioDispatchRepository,
            new ResultCodeResolver,
        );
    }

    /**
     * 코어 로그 id 에 연결된 dispatch 1건을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  dispatch 오버라이드
     */
    private function linkedDispatch(int $logId, array $overrides = []): BizppurioDispatch
    {
        return BizppurioDispatch::create(array_merge([
            'refkey' => 'r'.uniqid(),
            'channel' => 'sms',
            'to_number' => '01011112222',
            'content' => 'x',
            'notification_type' => 'welcome',
            'notification_log_id' => $logId,
            'status' => 'success',
            'result_code' => '4100',
            'source' => 'auto',
            'sent_at' => now(),
        ], $overrides));
    }

    public function test_성공_결과를_로그id_키_맵으로_반환한다(): void
    {
        $this->linkedDispatch(10);

        $results = $this->service->resultsForLogIds([10]);

        $this->assertArrayHasKey(10, $results);
        $this->assertSame('success', $results[10]['status']);
        $this->assertSame('4100', $results[10]['result_code']);
        $this->assertFalse($results[10]['is_low_balance']);
        // 민감정보(전화번호)는 결과에 포함하지 않는다.
        $this->assertArrayNotHasKey('to_number', $results[10]);
    }

    public function test_잔액부족_코드는_is_low_balance_true다(): void
    {
        $this->linkedDispatch(20, ['channel' => 'alimtalk', 'status' => 'failed', 'result_code' => '7436']);

        $results = $this->service->resultsForLogIds([20]);

        $this->assertTrue($results[20]['is_low_balance']);
    }

    public function test_대체발송_상태가_결과에_포함된다(): void
    {
        $this->linkedDispatch(30, ['fallback_status' => '성공']);

        $results = $this->service->resultsForLogIds([30]);

        $this->assertSame('성공', $results[30]['fallback_status']);
    }

    public function test_result_code_없으면_result_label_null이다(): void
    {
        $this->linkedDispatch(40, ['status' => 'sent', 'result_code' => null]);

        $results = $this->service->resultsForLogIds([40]);

        $this->assertNull($results[40]['result_label']);
        $this->assertNotNull($results[40]['status_label']); // 상태 라벨은 있음(발송중)
    }

    public function test_검수_모드_스냅샷이_결과에_포함된다(): void
    {
        $this->linkedDispatch(70, ['is_test_mode' => true]);

        $results = $this->service->resultsForLogIds([70]);

        $this->assertTrue($results[70]['is_test_mode']);
    }

    public function test_검수_모드_스냅샷이_없는_과거_이력은_null이다(): void
    {
        $this->linkedDispatch(71, ['is_test_mode' => null]);

        $results = $this->service->resultsForLogIds([71]);

        $this->assertNull($results[71]['is_test_mode']);
    }

    public function test_매칭되지_않는_로그id는_맵에서_빠진다(): void
    {
        $this->linkedDispatch(50);

        $results = $this->service->resultsForLogIds([50, 999]);

        $this->assertArrayHasKey(50, $results);
        $this->assertArrayNotHasKey(999, $results);
    }

    public function test_recent_results_는_연결된_최근_결과를_로그id_키_맵으로_반환한다(): void
    {
        $this->linkedDispatch(60, ['result_code' => '4100']);
        $this->linkedDispatch(61, ['channel' => 'alimtalk', 'status' => 'failed', 'result_code' => '7436']);

        $results = $this->service->recentResults();

        $this->assertSame('success', $results[60]['status']);
        $this->assertTrue($results[61]['is_low_balance']);
    }

    /**
     * 실제 발송 본문(dispatch.content)이 결과에 포함된다 — 알림톡은 코어
     * notification_logs.body(대체발송용 코어 템플릿 값)와 실제 카카오 발송 내용이 달라,
     * 화면이 채널별로 구분해 실제 발송 내용을 보여줄 수 있도록 결과에 담아야 한다.
     */
    public function test_실제_발송_본문이_결과에_포함된다(): void
    {
        $this->linkedDispatch(80, ['content' => '[그누보드7] 회원가입을 환영합니다\n\n김으네님, 가입이 완료되었습니다.']);

        $results = $this->service->resultsForLogIds([80]);

        $this->assertSame('[그누보드7] 회원가입을 환영합니다\n\n김으네님, 가입이 완료되었습니다.', $results[80]['content']);
    }
}
