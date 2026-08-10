<?php

namespace Tests\Unit\Benchmark;

use App\Benchmark\SyntheticSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 합성 시더 단위 테스트.
 *
 * 계측이 재려는 것은 "정렬 컬럼 인덱스를 타는 깊은 OFFSET 비용" 이다. 그러려면 프로파일이
 * 정렬 기준으로 삼는 컬럼이 합성 행에도 실제로 채워져 있어야 한다. 그 컬럼이 전부 NULL 이면
 * 모든 합성 행이 동률이 되어 계측 대상이 tie-break 경로로 바뀐다.
 */
class SyntheticSeederTest extends TestCase
{
    // 합성 행을 직접 지우지 않고 트랜잭션 롤백으로 되돌린다. 수동 DELETE 는 쿼리가 늘고,
    // 단언이 실패해 예외로 빠지면 행이 남아 다음 실행을 오염시킨다.
    // (RefreshDatabase 는 마이그레이션을 다시 돌리므로 읽기 위주 검증에는 과하다.)
    use DatabaseTransactions;

    /**
     * 정렬 대상 컬럼이 nullable 이어도 합성 행에 값이 채워지는지 검증합니다.
     *
     * users.created_at 은 nullable 이라 "NOT NULL + 기본값 없음" 필터에 걸리지 않는다.
     * 요구 컬럼으로 넘겼는데도 비어 있으면 그 프로파일의 계측은 정렬 인덱스를 타지 않는다.
     */
    #[Test]
    public function it_fills_nullable_sort_columns_when_required(): void
    {
        $table = 'users';
        $maxId = (int) (DB::table($table)->max('id') ?? 0);

        app(SyntheticSeeder::class)->seed(
            table: $table,
            count: 5,
            requiredColumns: ['created_at'],
        );

        $rows = DB::table($table)->where('id', '>', $maxId)->get(['id', 'created_at']);

        $this->assertCount(5, $rows, '합성 행 5건이 삽입되어야 합니다.');
        $this->assertSame(
            0,
            $rows->whereNull('created_at')->count(),
            '정렬 기준 컬럼(created_at)이 NULL 인 합성 행이 있으면 계측이 동률로 무너집니다.'
        );
        $this->assertGreaterThan(
            1,
            $rows->pluck('created_at')->unique()->count(),
            '정렬 기준 컬럼 값이 행마다 분산되어야 합니다(전부 같은 값이면 filesort 가 지배).'
        );
    }

    /**
     * 요구 컬럼을 지정하지 않으면 기존 동작(NOT NULL 컬럼만 채움)이 유지되는지 검증합니다.
     */
    #[Test]
    public function it_keeps_existing_behaviour_without_required_columns(): void
    {
        $table = 'users';
        $maxId = (int) (DB::table($table)->max('id') ?? 0);

        app(SyntheticSeeder::class)->seed(table: $table, count: 3);

        $this->assertSame(
            3,
            DB::table($table)->where('id', '>', $maxId)->count(),
            '요구 컬럼 없이도 시딩 자체는 동작해야 합니다.'
        );
    }
}
