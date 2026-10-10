<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * 게시판 샘플 시드 한 번의 실행에서 시더가 만든 행의 범위를 기록한다
 *
 * 활동 로그 샘플은 「이번 실행에서 시더가 만든」 게시판·글·댓글·첨부·반응·스크랩에만 만든다.
 * 운영 중인 사이트에서 `--sample` 을 다시 돌리면 샘플 slug 게시판에 이미 운영 글이 있을 수 있는데,
 * 그 글에 생성·수정 로그를 지어내면 실제 회원·운영자 명의로 하지 않은 행동의 감사 기록이 생긴다.
 *
 * 범위는 시더가 쓰기 전후의 최대 id 로 잡는다 — 시드는 한 프로세스에서 순서대로 돌므로 그 사이 id 는
 * 시더가 만든 행이다. 게시판 샘플 시더(첫 단계)가 실행마다 초기화한다. 시더를 하나만 따로 돌리면
 * 그 시더가 기록한 범위만 남는다.
 */
final class SampleRunRegistry
{
    /**
     * 테이블별 [시작 직전 최대 id, 끝난 뒤 최대 id]
     *
     * @var array<string, array{0: int, 1: int|null}>
     */
    private static array $ranges = [];

    /**
     * 이번 실행에서 만든 샘플 게시판 ID
     *
     * @var array<int, int>
     */
    private static array $boardIds = [];

    /**
     * 기록을 비웁니다 (실행 시작).
     *
     * @return void 반환값 없음
     */
    public static function reset(): void
    {
        self::$ranges = [];
        self::$boardIds = [];
    }

    /**
     * 테이블에 쓰기 시작 직전의 최대 id 를 기록합니다.
     *
     * @param  string  $table  테이블명 (접두사 없이)
     * @return void 반환값 없음
     */
    public static function markBefore(string $table): void
    {
        self::$ranges[$table] = [(int) DB::table($table)->max('id'), null];
    }

    /**
     * 테이블에 쓰기를 마친 뒤의 최대 id 를 기록합니다.
     *
     * @param  string  $table  테이블명 (접두사 없이)
     * @return void 반환값 없음
     */
    public static function markAfter(string $table): void
    {
        if (! isset(self::$ranges[$table])) {
            return;
        }

        self::$ranges[$table][1] = (int) DB::table($table)->max('id');
    }

    /**
     * 이번 실행에서 그 테이블에 만든 행의 id 범위를 돌려줍니다.
     *
     * @param  string  $table  테이블명
     * @return array{0: int, 1: int}|null [초과 하한, 이하 상한], 기록이 없거나 만든 행이 없으면 null
     */
    public static function range(string $table): ?array
    {
        $range = self::$ranges[$table] ?? null;

        if ($range === null || $range[1] === null || $range[1] <= $range[0]) {
            return null;
        }

        return [$range[0], $range[1]];
    }

    /**
     * 이번 실행에서 만든 샘플 게시판 ID 를 더합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @return void 반환값 없음
     */
    public static function addBoard(int $boardId): void
    {
        self::$boardIds[] = $boardId;
    }

    /**
     * 이번 실행에서 만든 샘플 게시판 ID 목록.
     *
     * @return array<int, int> 게시판 ID
     */
    public static function boardIds(): array
    {
        return array_values(array_unique(self::$boardIds));
    }
}
