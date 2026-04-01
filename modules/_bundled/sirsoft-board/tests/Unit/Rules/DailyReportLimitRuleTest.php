<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Rules;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';
require_once __DIR__.'/../../../src/Repositories/Contracts/ReportRepositoryInterface.php';
require_once __DIR__.'/../../../src/Rules/DailyReportLimitRule.php';

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
