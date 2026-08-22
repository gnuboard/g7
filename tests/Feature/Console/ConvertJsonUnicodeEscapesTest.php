<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * json:convert-unicode 커맨드의 변환 누락 회귀 테스트.
 *
 * 재현 대상 결함: `WHERE name LIKE '%\u%'` 로 필터한 결과를 `chunk()`(OFFSET
 * 페이지네이션)로 순회하면서 콜백이 그 컬럼을 UTF-8 로 다시 써 필터 조건에서
 * 이탈시킨다. 처리된 행이 다음 페이지 조회 결과에서 빠지므로 OFFSET 이 아직
 * 변환되지 않은 행을 건너뛴다.
 *
 * 250건 / 청크 100 기준으로 100건이 변환되지 않은 채 남는다.
 */
class ConvertJsonUnicodeEscapesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 시드 건수 (청크 크기 100 보다 충분히 커야 skip 이 드러남)
     */
    private const SEED_COUNT = 250;

    /**
     * `\uXXXX` 이스케이프가 포함된 boards 행을 시드합니다.
     *
     * @return void
     */
    private function seedEscapedBoards(): void
    {
        $rows = [];

        for ($i = 0; $i < self::SEED_COUNT; $i++) {
            $rows[] = [
                // 기본 json_encode 는 멀티바이트를 \uXXXX 로 인코딩한다 (변환 대상 상태)
                'name' => json_encode(['ko' => '게시판 '.$i, 'en' => 'Board '.$i]),
                'slug' => 'chunkskip-'.$i,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('boards')->insert($batch);
        }
    }

    /**
     * `\uXXXX` 이스케이프가 남아 있는 boards 건수를 반환합니다.
     *
     * @return int 잔여 건수
     */
    private function remainingEscapedCount(): int
    {
        return DB::table('boards')->where('name', 'LIKE', '%\\\\u%')->count();
    }

    public function test_conversion_leaves_no_escaped_rows_when_filter_column_is_mutated_during_iteration(): void
    {
        $this->seedEscapedBoards();

        $this->assertSame(
            self::SEED_COUNT,
            $this->remainingEscapedCount(),
            '시드 직후에는 \\uXXXX 이스케이프 행이 시드 건수만큼 존재해야 합니다.'
        );

        $this->artisan('json:convert-unicode', ['--table' => 'boards', '--chunk' => 100])
            ->assertExitCode(0);

        $this->assertSame(
            0,
            $this->remainingEscapedCount(),
            'OFFSET 순회로 인해 변환되지 않고 남은 행이 있습니다 (chunk → chunkById 필요).'
        );
    }

    public function test_converted_values_preserve_original_payload(): void
    {
        $this->seedEscapedBoards();

        $this->artisan('json:convert-unicode', ['--table' => 'boards', '--chunk' => 100])
            ->assertExitCode(0);

        $rows = DB::table('boards')->orderBy('id')->get(['slug', 'name']);

        foreach ($rows as $row) {
            $index = (int) str_replace('chunkskip-', '', $row->slug);

            $this->assertSame(
                ['ko' => '게시판 '.$index, 'en' => 'Board '.$index],
                json_decode($row->name, true),
                '변환 후에도 원본 값이 그대로 보존되어야 합니다.'
            );
        }
    }

    public function test_dry_run_does_not_modify_rows(): void
    {
        $this->seedEscapedBoards();

        $this->artisan('json:convert-unicode', ['--table' => 'boards', '--chunk' => 100, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(
            self::SEED_COUNT,
            $this->remainingEscapedCount(),
            'dry-run 은 실제 데이터를 변경하지 않아야 합니다.'
        );
    }
}
