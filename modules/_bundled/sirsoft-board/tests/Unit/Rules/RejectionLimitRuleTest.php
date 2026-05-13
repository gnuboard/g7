<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Rules;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';
require_once __DIR__.'/../../../src/Repositories/Contracts/ReportRepositoryInterface.php';
require_once __DIR__.'/../../../src/Rules/RejectionLimitRule.php';

use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Rules\RejectionLimitRule;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * RejectionLimitRule 단위 테스트
 *
 * 반려 누적 제한 규칙의 동작을 테스트합니다.
 */
class RejectionLimitRuleTest extends ModuleTestCase
{
    /**
     * limit이 0일 때 제한 없음 (항상 통과)
     */
    public function test_passes_when_limit_is_zero(): void
    {
        $repository = $this->createMock(ReportRepositoryInterface::class);
        $repository->expects($this->never())->method('countRejectedReportsByUser');

        $rule = new RejectionLimitRule($repository, 1, 0, 30);
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
        $repository->expects($this->never())->method('countRejectedReportsByUser');

        $rule = new RejectionLimitRule($repository, 1, -1, 30);
        $failed = false;

        $rule->validate('reason_type', 'spam', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * 반려 건수가 limit 미만이면 통과
     */
    public function test_passes_when_under_limit(): void
    {
        $repository = $this->createMock(ReportRepositoryInterface::class);
        $repository->method('countRejectedReportsByUser')->with(1, 30)->willReturn(4);

        $rule = new RejectionLimitRule($repository, 1, 5, 30);
        $failed = false;

        $rule->validate('reason_type', 'spam', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * 반려 건수가 limit과 같으면 실패
     */
    public function test_fails_when_at_limit(): void
    {
        $repository = $this->createMock(ReportRepositoryInterface::class);
        $repository->method('countRejectedReportsByUser')->with(1, 30)->willReturn(5);

        $rule = new RejectionLimitRule($repository, 1, 5, 30);
        $failed = false;

        $rule->validate('reason_type', 'spam', function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed);
    }

    /**
     * 반려 건수가 limit을 초과하면 실패
     */
    public function test_fails_when_over_limit(): void
    {
        $repository = $this->createMock(ReportRepositoryInterface::class);
        $repository->method('countRejectedReportsByUser')->with(1, 30)->willReturn(8);

        $rule = new RejectionLimitRule($repository, 1, 5, 30);
        $failed = false;

        $rule->validate('reason_type', 'spam', function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed);
    }

    /**
     * 실패 시 메시지에 days와 count가 포함되는지 테스트
     */
    public function test_failure_message_contains_days_and_count(): void
    {
        $repository = $this->createMock(ReportRepositoryInterface::class);
        $repository->method('countRejectedReportsByUser')->with(1, 30)->willReturn(5);

        $rule = new RejectionLimitRule($repository, 1, 5, 30);
        $message = '';

        $rule->validate('reason_type', 'spam', function ($msg) use (&$message) {
            $message = $msg;
        });

        $this->assertNotEmpty($message);
    }
}
