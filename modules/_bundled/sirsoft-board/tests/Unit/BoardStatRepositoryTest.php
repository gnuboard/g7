<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use Modules\Sirsoft\Board\Models\BoardStat;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardStatRepositoryInterface;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * BoardStatRepository 통합 테스트
 */
class BoardStatRepositoryTest extends ModuleTestCase
{
    private BoardStatRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        BoardStat::query()->delete();
        $this->repository = $this->app->make(BoardStatRepositoryInterface::class);
    }

    #[Test]
    public function test_upsert_for_date_creates_new_row(): void
    {
        $row = $this->repository->upsertForDate('2026-05-01', 10, 20);

        $this->assertSame(10, $row->post_count);
        $this->assertSame(20, $row->comment_count);
        $this->assertSame(1, BoardStat::count());
    }

    #[Test]
    public function test_upsert_for_date_is_idempotent_on_same_date(): void
    {
        $this->repository->upsertForDate('2026-05-01', 10, 20);
        $this->repository->upsertForDate('2026-05-01', 33, 44);
        $this->repository->upsertForDate('2026-05-01', 55, 66);

        // 행 수는 1 (중복 없음)
        $this->assertSame(1, BoardStat::count());

        // 최종 값으로 덮어쓰기
        $row = $this->repository->findByDate('2026-05-01');
        $this->assertSame(55, $row->post_count);
        $this->assertSame(66, $row->comment_count);
    }

    #[Test]
    public function test_get_by_date_range_returns_rows_in_ascending_date_order(): void
    {
        $this->repository->upsertForDate('2026-05-03', 3, 0);
        $this->repository->upsertForDate('2026-05-01', 1, 0);
        $this->repository->upsertForDate('2026-05-02', 2, 0);
        // 범위 밖
        $this->repository->upsertForDate('2026-04-30', 99, 0);
        $this->repository->upsertForDate('2026-05-04', 99, 0);

        $rows = $this->repository->getByDateRange('2026-05-01', '2026-05-03');

        $this->assertCount(3, $rows);
        $this->assertSame('2026-05-01', $rows[0]->date->toDateString());
        $this->assertSame('2026-05-02', $rows[1]->date->toDateString());
        $this->assertSame('2026-05-03', $rows[2]->date->toDateString());
    }

    #[Test]
    public function test_find_by_date_returns_null_when_missing(): void
    {
        $this->assertNull($this->repository->findByDate('2099-12-31'));
    }
}
