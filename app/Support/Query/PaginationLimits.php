<?php

namespace App\Support\Query;

use App\Extension\HookManager;

/**
 * 목록 한계값의 단일 출처
 *
 * 총 건수 집계 상한과 페이지 번호 상한을 한 곳에서 해석한다. 값의 출처는
 * 관리자 환경설정(`g7_settings.core.pagination.*`) 이고, 미설정 시 `config('core.pagination.*')`
 * 로 폴백한다. 확장은 이 값을 리터럴로 다시 적지 않고 필터 훅으로만 조정한다.
 *
 * 필터 훅:
 * - `core.pagination.filter_result_cap` — 총 건수 집계 상한 (int, 0 이하 = 무제한)
 * - `core.pagination.filter_max_page` — 페이지 번호 상한 (int, 0 이하 = 무제한)
 */
class PaginationLimits
{
    /**
     * 총 건수 집계 상한 기본값 (환경설정·config 모두 부재 시)
     */
    private const FALLBACK_RESULT_CAP = 10000;

    /**
     * 페이지 번호 상한 기본값 (환경설정·config 모두 부재 시)
     */
    private const FALLBACK_MAX_PAGE = 1000;

    /**
     * 총 건수 집계 상한을 반환합니다.
     *
     * 이 값을 넘는 매칭은 정확히 세지 않고 "이상" 으로만 보고합니다.
     *
     * @param  string|null  $context  상한을 조정할 확장이 구분에 쓸 컨텍스트 키 (예: 'search', 'admin.users')
     * @return int|null 상한 (무제한이면 null)
     */
    public static function resultCap(?string $context = null): ?int
    {
        $value = self::setting('result_cap', self::FALLBACK_RESULT_CAP);
        $value = (int) HookManager::applyFilters('core.pagination.filter_result_cap', $value, $context);

        return $value > 0 ? $value : null;
    }

    /**
     * 페이지 번호 상한을 반환합니다.
     *
     * 정상 탐색은 `has_more_pages` 와 커서로 열려 있고, 이 값은 임의의 큰 페이지 번호를
     * 직접 던지는 남용을 막기 위한 것입니다.
     *
     * @param  string|null  $context  상한을 조정할 확장이 구분에 쓸 컨텍스트 키
     * @return int|null 상한 (무제한이면 null)
     */
    public static function maxPage(?string $context = null): ?int
    {
        $value = self::setting('max_page', self::FALLBACK_MAX_PAGE);
        $value = (int) HookManager::applyFilters('core.pagination.filter_max_page', $value, $context);

        return $value > 0 ? $value : null;
    }

    /**
     * 관리자 환경설정 값을 읽고, 없으면 config 기본값으로 폴백합니다.
     *
     * @param  string  $key  pagination 하위 키
     * @param  int  $fallback  둘 다 부재할 때 쓸 값
     * @return int 해석된 값
     */
    private static function setting(string $key, int $fallback): int
    {
        $configured = config('g7_settings.core.pagination.'.$key);

        if ($configured === null || $configured === '') {
            $configured = config('core.pagination.'.$key, $fallback);
        }

        return (int) $configured;
    }
}
