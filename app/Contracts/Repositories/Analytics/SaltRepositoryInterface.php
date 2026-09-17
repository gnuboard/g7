<?php

namespace App\Contracts\Repositories\Analytics;

use Illuminate\Support\Carbon;

/**
 * 일자별 솔트 Repository 인터페이스
 */
interface SaltRepositoryInterface
{
    /**
     * 지정한 날짜의 솔트를 가져오고 없으면 만듭니다.
     *
     * 방문마다 이 값을 조회하므로 구현은 요청 안에서 캐시해야 합니다.
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @return string 그날의 솔트
     */
    public function forDate(string $date): string;

    /**
     * 지정한 날짜의 솔트를 조회합니다. 없으면 만들지 않습니다.
     *
     * 지난 방문을 정보주체 기준으로 찾을 때, 없는 날의 솔트를 새로 만들어 버리면
     * 그 자체가 무의미한 행을 남기므로 조회 전용 경로를 따로 둡니다.
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @return string|null 그날의 솔트 (없으면 null)
     */
    public function findForDate(string $date): ?string;

    /**
     * 보존기간이 지난 솔트를 삭제합니다.
     *
     * @param  Carbon  $before  이 일자 이전 솔트가 대상
     * @return int 삭제한 행 수
     */
    public function deleteOlderThan(Carbon $before): int;

    /**
     * 기준 시각보다 오래된 솔트 수를 셉니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countOlderThan(Carbon $before): int;
}
