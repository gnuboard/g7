<?php

namespace Tests\Unit\Repositories;

use App\Models\ActivityLog;
use App\Repositories\Concerns\DeletesInBatches;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 배치 삭제 트레이트 테스트
 *
 * 정리 예약은 도입 직후 첫 실행에서 이미 쌓여 있던 과거 데이터를 전부 지운다.
 * 한 문장으로 지우면 그 한 건이 테이블을 오래 잠그므로 배치로 끊어 지운다 —
 * 끊어 지우면서도 대상이 남김없이 사라지는지를 고정한다.
 *
 * @scenario row_age=beyond_retention
 *
 * @effects large_purge_is_split_into_batches
 */
class DeletesInBatchesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 배치 크기를 넘는 대상도 남김없이 삭제된다.
     *
     * 단일 배치만 처리하고 끝내는 구현이면 나머지가 남아 red 가 된다.
     */
    public function test_deletes_every_matching_row_across_multiple_batches(): void
    {
        foreach (range(1, 7) as $i) {
            $log = ActivityLog::create([
                'log_type' => 'system',
                'action' => 'test.batch',
                'description' => "오래된 기록 {$i}",
            ]);
            $log->forceFill(['created_at' => now()->subDays(400)])->saveQuietly();
        }

        $deleted = $this->runner()->purge(
            ActivityLog::where('created_at', '<', now()->subDays(365))
        );

        $this->assertSame(7, $deleted);
        $this->assertSame(0, ActivityLog::where('action', 'test.batch')->count());
    }

    /**
     * 조건 밖의 행은 배치가 몇 회전을 돌아도 건드리지 않는다.
     */
    public function test_keeps_rows_outside_the_condition(): void
    {
        foreach (range(1, 5) as $i) {
            $old = ActivityLog::create([
                'log_type' => 'system',
                'action' => 'test.batch.old',
                'description' => "오래된 기록 {$i}",
            ]);
            $old->forceFill(['created_at' => now()->subDays(400)])->saveQuietly();
        }

        $recent = ActivityLog::create([
            'log_type' => 'system',
            'action' => 'test.batch.recent',
            'description' => '최근 기록',
        ]);

        $this->runner()->purge(
            ActivityLog::where('created_at', '<', now()->subDays(365))
        );

        $this->assertDatabaseHas('activity_logs', ['id' => $recent->id]);
        $this->assertSame(0, ActivityLog::where('action', 'test.batch.old')->count());
    }

    /**
     * 대상이 없으면 0 을 돌려주고 끝난다 (무한 루프 방지).
     */
    public function test_returns_zero_when_nothing_matches(): void
    {
        $deleted = $this->runner()->purge(
            ActivityLog::where('created_at', '<', now()->subDays(365))
        );

        $this->assertSame(0, $deleted);
    }

    /**
     * 배치 크기를 작게 잡은 실행기를 만듭니다.
     *
     * 기본 배치(1000)로는 여러 회전을 만들려면 그만큼 행을 쌓아야 해서,
     * 회전 자체를 검증하려면 크기를 줄이는 편이 정확하다.
     *
     * @return object purge(Builder): int 를 제공하는 실행기
     */
    private function runner(): object
    {
        return new class
        {
            use DeletesInBatches;

            /**
             * 조건에 맞는 행을 배치로 삭제합니다.
             *
             * 회전을 강제하려고 배치 크기를 2 로 낮춰 호출합니다.
             *
             * @param  Builder  $query  삭제 대상 쿼리
             * @return int 삭제된 건수
             */
            public function purge(Builder $query): int
            {
                return $this->deleteInBatches($query, 2);
            }
        };
    }
}
