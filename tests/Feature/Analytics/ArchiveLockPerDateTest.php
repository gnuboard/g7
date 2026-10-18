<?php

namespace Tests\Feature\Analytics;

use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Services\Analytics\ArchiveService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 방문자 통계 집계 잠금 — 같은 날짜는 실행 종류와 무관하게 한 번에 하나 (7.1.0 전수 실측 O-EF1 · 결정 P9)
 *
 * 잠금 키가 실행 종류별(`analytics:archive:{run_type}:{date}`)이라 같은 날의 시간 집계와 구간 재집계가
 * 서로를 「다른 집계」로 보지 않고 동시에 돌았다. 둘 다 같은 날의 일 집계 행을 지우고 다시 쓰므로
 * 한쪽이 다른 쪽의 결과를 덮을 수 있다. 같은 날짜를 다루는 집계는 종류와 상관없이 같은 잠금을 쓴다.
 */
#[Group('analytics')]
class ArchiveLockPerDateTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 한 날짜의 잠금이 잡혀 있으면 시간 집계·일 집계·구간 재집계 모두 건너뛴다.
     *
     * @scenario lock_holder=other_run_type, run_type=all
     *
     * @effects archive_lock_is_shared_across_run_types_for_a_date
     *
     * @return void 반환값 없음
     */
    public function test_a_held_date_lock_blocks_every_run_type(): void
    {
        $date = '2026-09-21';
        $lock = Cache::lock('analytics:archive:'.$date, 60);
        $this->assertTrue($lock->get());

        try {
            $service = $this->app->make(ArchiveService::class);

            foreach ([ArchiveRunType::Hour, ArchiveRunType::Day, ArchiveRunType::Reaggregate] as $type) {
                $run = $service->archiveDay($date, ['run_type' => $type->value, 'trigger' => 'manual']);

                $this->assertInstanceOf(AnalyticsArchiveRun::class, $run, $type->value);
                $status = $run->status instanceof ArchiveRunStatus ? $run->status->value : (string) $run->status;
                $this->assertSame(ArchiveRunStatus::Skipped->value, $status, $type->value.' 는 같은 날짜 잠금에 막혀야 한다');
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * 잠금 키는 날짜 하나로 정해지고 실행 종류가 끼지 않는다.
     *
     * @scenario lock_holder=other_run_type, run_type=all
     *
     * @effects archive_lock_is_shared_across_run_types_for_a_date
     *
     * @return void 반환값 없음
     */
    public function test_lock_key_depends_on_date_only(): void
    {
        $this->assertSame('analytics:archive:2026-09-21', ArchiveService::lockKey('2026-09-21'));
    }

    /**
     * 다른 날짜의 잠금은 막지 않는다.
     *
     * @scenario lock_holder=other_date, run_type=all
     *
     * @effects archive_lock_does_not_block_other_dates
     *
     * @return void 반환값 없음
     */
    public function test_another_date_lock_does_not_block(): void
    {
        $lock = Cache::lock('analytics:archive:2026-09-20', 60);
        $this->assertTrue($lock->get());

        try {
            $run = $this->app->make(ArchiveService::class)->archiveDay('2026-09-21', ['run_type' => ArchiveRunType::Reaggregate->value, 'trigger' => 'manual']);
            $status = $run->status instanceof ArchiveRunStatus ? $run->status->value : (string) $run->status;

            $this->assertNotSame(ArchiveRunStatus::Skipped->value, $status);
        } finally {
            $lock->release();
        }
    }
}
