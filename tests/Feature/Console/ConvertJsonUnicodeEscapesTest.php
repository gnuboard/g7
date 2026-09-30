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
 *
 * 대상 탐색: 코어가 확장 테이블 이름을 적어 두지 않고 DB 스키마의 FULLTEXT 인덱스에서 찾는다
 * (2026-09-28 결정). 게시판 테이블은 게시판 모듈 소유이므로, 테이블 목록 없이도 찾아지는지가
 * 곧 코어가 확장을 모르는 채로 동작하는지다.
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
     * @return void 반환값 없음
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

    /**
     * 순회 중 필터 컬럼이 바뀌어도 이스케이프 행이 하나도 남지 않는다.
     *
     * @scenario target=single_table,mode=apply
     *
     * @effects keyset_iteration_converts_every_escaped_row
     *
     * @return void 반환값 없음
     */
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

    /**
     * 변환 뒤에도 원래 값이 그대로 보존된다.
     *
     * @scenario target=single_table,mode=apply
     *
     * @effects converted_value_preserves_original_payload
     *
     * @return void 반환값 없음
     */
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

    /**
     * 드라이런은 데이터를 바꾸지 않는다.
     *
     * @scenario target=single_table,mode=dry_run
     *
     * @effects dry_run_leaves_rows_untouched
     *
     * @return void 반환값 없음
     */
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

    /**
     * 테이블을 지정하지 않아도 FULLTEXT 인덱스로 확장 테이블을 찾아 변환한다.
     *
     * @scenario target=discovered,mode=apply
     *
     * @effects targets_are_discovered_from_fulltext_indexes_without_table_list
     *
     * @return void 반환값 없음
     */
    public function test_extension_table_is_discovered_from_fulltext_index(): void
    {
        $this->seedEscapedBoards();

        $this->artisan('json:convert-unicode', ['--chunk' => 100])
            ->assertExitCode(0);

        $this->assertSame(0, $this->remainingEscapedCount(), '게시판 테이블이 탐색 대상에 들지 않았습니다.');
    }

    /**
     * FULLTEXT 인덱스가 없는 테이블을 지정하면 실패하고 아무것도 바꾸지 않는다.
     *
     * @scenario target=unknown_table,mode=apply
     *
     * @effects unknown_or_unindexed_table_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_table_without_fulltext_index_is_rejected(): void
    {
        $this->artisan('json:convert-unicode', ['--table' => 'migrations'])
            ->assertExitCode(1);
    }
}
