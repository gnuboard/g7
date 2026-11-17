<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Report;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 신고 케이스 영구 삭제 시 신고 로그의 명시 삭제 (#888)
 *
 * 신고 로그(`boards_report_logs.report_id`)는 케이스(`boards_reports`)에 삭제 연쇄 외래키로 걸려 있다. 영구 삭제는
 * 그 연쇄에 기대지 않고 로그를 먼저 지워야 한다 — 외래키 검사를 끈 채 지워도 고아 로그가 남지 않아야 하고,
 * 다른 케이스의 로그는 그대로 남아야 한다.
 */
#[Group('board')]
#[Group('report')]
class ReportForceDeleteTest extends BoardTestCase
{
    /**
     * 외래키 검사를 끈 채 케이스를 영구 삭제해도 그 케이스의 로그가 고아로 남지 않고, 다른 케이스의 로그는 남는다.
     *
     * @scenario case=force_delete
     *
     * @effects force_delete_removes_case_logs_without_fk_cascade,force_delete_keeps_other_case_logs
     *
     * @return void 반환값 없음
     */
    public function test_force_delete_removes_report_logs_without_fk_cascade(): void
    {
        $target = $this->createReportWithLogs(2);
        $other = $this->createReportWithLogs(1);

        Schema::disableForeignKeyConstraints();
        try {
            $deleted = app(ReportRepositoryInterface::class)->forceDelete($target->id);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->assertTrue($deleted);
        $this->assertNull(Report::withTrashed()->find($target->id), '케이스가 영구 삭제되지 않았습니다');
        $this->assertSame(0, DB::table('boards_report_logs')->where('report_id', $target->id)->count(), '신고 로그가 고아로 남았습니다');
        $this->assertSame(1, DB::table('boards_report_logs')->where('report_id', $other->id)->count(), '다른 케이스의 로그까지 지워졌습니다');
    }

    /**
     * 신고 케이스와 로그를 만듭니다.
     *
     * @param  int  $logCount  만들 로그 수
     * @return Report 만든 케이스
     */
    private function createReportWithLogs(int $logCount): Report
    {
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'author_id' => $this->createUser()->id,
        ]);

        for ($i = 0; $i < $logCount; $i++) {
            ReportLog::factory()->create([
                'report_id' => $report->id,
                'reporter_id' => $this->createUser()->id,
            ]);
        }

        return $report;
    }
}
