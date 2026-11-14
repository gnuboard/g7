<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Rules;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';
// 앱을 먼저 부팅한 개발 환경 프로세스(VS Code 시험 탐색)는 활성 디렉토리 사본으로 이 심볼을 이미 선언해 두므로,
// 경로가 다른 원본을 다시 읽으면 재선언 Fatal 이 난다 — 아직 없을 때만 _bundled 원본을 읽는다.
// 이름은 문자열로 적는다 — 아래 use 선언보다 앞이라 `X::class` 짧은 이름은 이 시험 네임스페이스로 풀려 가드가 늘 거짓이 된다.
if (! interface_exists('Modules\\Sirsoft\\Board\\Repositories\\Contracts\\ReportRepositoryInterface', false)) {
    require_once __DIR__.'/../../../src/Repositories/Contracts/ReportRepositoryInterface.php';
}
if (! class_exists('Modules\\Sirsoft\\Board\\Rules\\DailyReportLimitRule', false)) {
    require_once __DIR__.'/../../../src/Rules/DailyReportLimitRule.php';
}

use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Rules\DailyReportLimitRule;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * DailyReportLimitRule 단위 테스트
 *
 * 일일 신고 횟수 제한 규칙의 동작을 테스트합니다.
 */
class DailyReportLimitRuleTest extends ModuleTestCase
{
    /**
     * limit이 0일 때 제한 없음 (항상 통과)
     *
     * @scenario guarded_input=report_flow, input_case=skipped_input
     *
     * @effects daily_report_limit_passes_when_disabled
     *
     * @return void 반환값 없음
     */
    public function test_passes_when_limit_is_zero(): void
    {
        $repository = $this->createMock(ReportRepositoryInterface::class);
        $repository->expects($this->never())->method('countTodayReportsByUser');

        $rule = new DailyReportLimitRule($repository, 1, 0);
        $failed = false;

        $rule->validate('reason_type', 'spam', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * limit이 음수일 때 제한 없음 (항상 통과)
     *
     * @scenario guarded_input=report_flow, input_case=skipped_input
     *
     * @effects daily_report_limit_passes_when_disabled
     *
     * @return void 반환값 없음
     */
    public function test_passes_when_limit_is_negative(): void
    {
        $repository = $this->createMock(ReportRepositoryInterface::class);
        $repository->expects($this->never())->method('countTodayReportsByUser');

        $rule = new DailyReportLimitRule($repository, 1, -1);
        $failed = false;

        $rule->validate('reason_type', 'spam', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * 오늘 건수가 limit 미만이면 통과
     *
     * @scenario guarded_input=report_flow, input_case=conforming
     *
     * @effects daily_report_limit_passes_under_limit
     *
     * @return void 반환값 없음
     */
    public function test_passes_when_under_limit(): void
    {
        $repository = $this->createMock(ReportRepositoryInterface::class);
        $repository->method('countTodayReportsByUser')->with(1)->willReturn(9);

        $rule = new DailyReportLimitRule($repository, 1, 10);
        $failed = false;

        $rule->validate('reason_type', 'spam', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * 오늘 건수가 limit과 같으면 실패
     *
     * @scenario guarded_input=report_flow, input_case=violating
     *
     * @effects daily_report_limit_fails_at_or_over_limit
     *
     * @return void 반환값 없음
     */
    public function test_fails_when_at_limit(): void
    {
        $repository = $this->createMock(ReportRepositoryInterface::class);
        $repository->method('countTodayReportsByUser')->with(1)->willReturn(10);

        $rule = new DailyReportLimitRule($repository, 1, 10);
        $failed = false;

        $rule->validate('reason_type', 'spam', function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed);
    }

    /**
     * 오늘 건수가 limit을 초과하면 실패
     *
     * @scenario guarded_input=report_flow, input_case=violating
     *
     * @effects daily_report_limit_fails_at_or_over_limit
     *
     * @return void 반환값 없음
     */
    public function test_fails_when_over_limit(): void
    {
        $repository = $this->createMock(ReportRepositoryInterface::class);
        $repository->method('countTodayReportsByUser')->with(1)->willReturn(15);

        $rule = new DailyReportLimitRule($repository, 1, 10);
        $failed = false;

        $rule->validate('reason_type', 'spam', function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed);
    }
}
