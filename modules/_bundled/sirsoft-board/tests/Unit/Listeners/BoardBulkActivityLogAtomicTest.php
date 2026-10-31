<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Board\Listeners\BoardActivityLogListener;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use Psr\Log\LoggerInterface;

/**
 * 신고 일괄 처리 활동 로그 원자화 테스트 (#139)
 *
 * 큐로 도는 일괄 핸들러가 항목별 기록을 한 트랜잭션 안에서 남기는지 — 기록 순간의 트랜잭션 깊이가 모두
 * 「호출 전 + 1」인지 — 로 확인한다(잡이 중간에 끊겨 재실행되면 앞부분이 두 번 기록되던 결함).
 */
class BoardBulkActivityLogAtomicTest extends ModuleTestCase
{
    /**
     * 신고 일괄 상태 변경 로그는 한 트랜잭션 안에서 기록된다.
     *
     * @scenario handler=report_bulk_update_status
     *
     * @effects bulk_activity_log_atomic
     *
     * @return void 반환값 없음
     */
    public function test_report_bulk_status_rows_are_written_in_one_transaction(): void
    {
        $this->app->instance('request', Request::create('/api/admin/test'));
        $reports = new Collection;
        foreach ([31391, 31392, 31393] as $id) {
            $report = new Report;
            $report->id = $id;
            $reports->put($id, $report);
        }
        $repository = Mockery::mock(ReportRepositoryInterface::class);
        $repository->shouldReceive('findByIdsKeyed')->andReturn($reports);
        $this->app->instance(ReportRepositoryInterface::class, $repository);

        $levels = [];
        $channel = Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('info')->andReturnUsing(function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });
        Log::shouldReceive('channel')->with('activity')->andReturn($channel);
        Log::shouldReceive('error')->never();

        $base = DB::transactionLevel();
        app(BoardActivityLogListener::class)->handleReportAfterBulkUpdateStatus([31391, 31392, 31393], ['status' => 'resolved'], 3);

        $this->assertSame(array_fill(0, 3, $base + 1), $levels, '신고 일괄 처리 로그가 한 트랜잭션 안에서 기록되지 않았다');
    }
}
