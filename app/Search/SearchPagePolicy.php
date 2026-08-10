<?php

namespace App\Search;

use App\Support\Query\KeysetPaginator;

/**
 * 검색 목록이 커서(키셋)로 응답할 수 있는지 판정하는 단일 지점
 *
 * 이 판정을 도메인마다 각자 구현하면 같은 규칙이 검색 모듈 수만큼 복제되고, 한 곳만
 * 고치면 다른 도메인은 조용히 옛 규칙으로 남는다. 코어가 규칙을 소유하고, 확장은
 * "내 정렬 이름이 어떤 실제 컬럼인가" 만 선언한다.
 *
 * 커서는 정렬 키를 WHERE 절 경계로 삼으므로 정렬 키가 전부 실제 컬럼이어야 한다.
 * 관련도순처럼 계산값(FULLTEXT 점수)으로 정렬하는 경우에는 쓸 수 없고 offset 을 유지한다.
 */
final class SearchPagePolicy
{
    /**
     * 이 요청을 커서로 응답할지 판정합니다.
     *
     * 커서를 받은 요청만 커서로 처리하면 첫 커서가 만들어질 자리가 없다. 서버는 커서
     * 모드에서만 다음 커서를 내보내므로, 화면은 건넬 커서가 없어 영원히 offset 에 머문다.
     * 첫 페이지에 커서가 없는 것은 정상이므로 "커서 없음" 이 아니라 "시작점" 으로 읽는다.
     *
     * 다만 커서 없이 깊은 페이지를 직접 지목한 요청(주소로 열어 둔 딥링크·북마크)은
     * 그 페이지를 그대로 보여줘야 한다. 커서로 바꾸면 첫 페이지로 되돌아가 링크가
     * 가리키던 자리를 잃으므로, 그 경우에만 offset 을 유지한다.
     *
     * @param  string|null  $cursor  요청이 보낸 커서 (첫 페이지면 없음)
     * @param  array<int, array{0: string, 1: string}>  $sortKeys  [[컬럼, 방향], ...]
     * @param  array<int, string>  $cursorColumns  이 도메인에서 커서로 허용한 실제 컬럼
     * @param  int  $page  요청이 지목한 페이지 번호 (없으면 1)
     * @return bool 커서로 응답할 수 있으면 true
     */
    public static function usesCursor(?string $cursor, array $sortKeys, array $cursorColumns, int $page = 1): bool
    {
        if (! KeysetPaginator::supports($sortKeys, $cursorColumns)) {
            return false;
        }

        if ($cursor !== null && $cursor !== '') {
            return true;
        }

        return $page <= 1;
    }

    /**
     * 정렬 이름을 정렬 키 배열로 바꿉니다.
     *
     * 확장이 선언한 `정렬이름 => [컬럼, 방향]` 맵을 코어가 해석합니다. 맵에 없는 이름은
     * 커서로 처리할 수 없는 정렬(관련도순 등)로 보고 빈 배열을 돌려주며,
     * {@see self::usesCursor()} 가 이를 "커서 불가" 로 판정합니다.
     *
     * @param  string  $sort  요청이 보낸 정렬 이름
     * @param  array<string, array{0: string, 1: string}>  $sortMap  정렬 이름 → [컬럼, 방향]
     * @return array<int, array{0: string, 1: string}> 정렬 키 배열 (해석 불가 시 빈 배열)
     */
    public static function sortKeys(string $sort, array $sortMap): array
    {
        if (! array_key_exists($sort, $sortMap)) {
            return [];
        }

        return [$sortMap[$sort]];
    }
}
