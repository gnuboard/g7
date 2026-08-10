<?php

namespace App\Support\Query;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

/**
 * 커서(키셋) 기반 페이지네이터
 *
 * OFFSET 방식은 페이지가 깊어질수록 건너뛸 행을 실제로 읽어야 해서 마지막 페이지에 갈수록
 * 느려진다. 커서 방식은 직전 페이지 마지막 행의 정렬 키를 WHERE 절 경계로 삼으므로 깊이와
 * 무관하게 일정하다.
 *
 * 커서 문자열의 인코딩·디코딩은 전부 이 클래스를 통한다. 각 목록이 자기 방식으로 커서를
 * 만들면 형식이 갈라져 한 화면의 커서를 다른 화면이 해석하지 못한다.
 *
 * 적용 조건: 정렬 키가 모두 **실제 컬럼**이어야 한다. 계산값(예: FULLTEXT 관련도 점수)은
 * WHERE 절 경계로 쓸 수 없으므로 그 정렬에서는 커서를 쓰지 못하고 OFFSET 을 유지한다.
 * 판정은 {@see self::supports()} 가 담당한다.
 */
class KeysetPaginator
{
    /**
     * 커서 쿼리 파라미터의 표준 이름
     */
    public const CURSOR_PARAM = 'cursor';

    /**
     * 커서 기반으로 한 페이지를 조회합니다.
     *
     * 정렬 키 마지막에 고유 컬럼(기본키)이 없으면 자동으로 덧붙입니다. 동률 구간에서
     * 전순서가 보장되지 않으면 인접 페이지가 같은 행을 중복 노출하고 다른 행을 누락합니다.
     *
     * @param  EloquentBuilder|QueryBuilder  $query  대상 빌더 (정렬 미적용 상태로 전달)
     * @param  int  $perPage  페이지당 건수
     * @param  array<int, array{0: string, 1: string}>  $sortKeys  [[컬럼, 방향], ...] 순서대로 적용
     * @param  string  $uniqueKey  전순서 보장용 고유 컬럼 (보통 기본키)
     * @param  string|null  $cursor  인코딩된 커서 문자열 (첫 페이지면 null)
     * @param  array<int, string>  $columns  조회 컬럼
     * @return CursorPaginator 커서 페이지 결과
     */
    public static function paginate(
        EloquentBuilder|QueryBuilder $query,
        int $perPage,
        array $sortKeys,
        string $uniqueKey,
        ?string $cursor = null,
        array $columns = ['*']
    ): CursorPaginator {
        $perPage = max(1, $perPage);

        $applied = [];

        foreach ($sortKeys as [$column, $direction]) {
            $query->orderBy($column, self::normalizeDirection($direction));
            $applied[] = $column;
        }

        // 전순서 보장 — 마지막 정렬 키가 고유하지 않으면 페이지 경계에서 행이 새거나 겹친다.
        if (! in_array($uniqueKey, $applied, true)) {
            $lastDirection = $sortKeys === [] ? 'desc' : self::normalizeDirection(end($sortKeys)[1]);
            $query->orderBy($uniqueKey, $lastDirection);
        }

        return $query->cursorPaginate($perPage, $columns, self::CURSOR_PARAM, self::decode($cursor));
    }

    /**
     * 정렬 키 전부가 커서로 쓸 수 있는 실제 컬럼인지 판정합니다.
     *
     * 계산값·표현식·별칭은 WHERE 절 경계로 쓸 수 없습니다.
     *
     * @param  array<int, array{0: string, 1: string}>  $sortKeys  [[컬럼, 방향], ...]
     * @param  array<int, string>  $columnWhitelist  커서로 허용된 실제 컬럼 목록
     * @return bool 전부 허용 컬럼이면 true
     */
    public static function supports(array $sortKeys, array $columnWhitelist): bool
    {
        if ($sortKeys === []) {
            return false;
        }

        foreach ($sortKeys as [$column]) {
            if (! in_array($column, $columnWhitelist, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 커서 문자열을 디코딩합니다.
     *
     * 형식이 깨진 값은 예외 대신 null 로 취급해 첫 페이지로 되돌립니다. 사용자가 URL 을
     * 손으로 고쳤다는 이유로 목록 화면이 오류를 띄우게 두지 않습니다.
     *
     * @param  string|null  $cursor  인코딩된 커서 문자열
     * @return Cursor|null 디코딩된 커서 (부재·해석 실패 시 null)
     */
    public static function decode(?string $cursor): ?Cursor
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        try {
            return Cursor::fromEncoded($cursor);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 커서 페이지에서 다음 페이지 커서 문자열을 뽑아냅니다.
     *
     * @param  CursorPaginator  $page  커서 페이지 결과
     * @return string|null 다음 페이지 커서 (마지막 페이지면 null)
     */
    public static function nextCursor(CursorPaginator $page): ?string
    {
        return $page->nextCursor()?->encode();
    }

    /**
     * 커서 페이지에서 이전 페이지 커서 문자열을 뽑아냅니다.
     *
     * @param  CursorPaginator  $page  커서 페이지 결과
     * @return string|null 이전 페이지 커서 (첫 페이지면 null)
     */
    public static function previousCursor(CursorPaginator $page): ?string
    {
        return $page->previousCursor()?->encode();
    }

    /**
     * 정렬 방향을 asc/desc 로 정규화합니다.
     *
     * @param  string  $direction  입력 방향
     * @return string 'asc' 또는 'desc'
     */
    private static function normalizeDirection(string $direction): string
    {
        return strtolower($direction) === 'asc' ? 'asc' : 'desc';
    }
}
