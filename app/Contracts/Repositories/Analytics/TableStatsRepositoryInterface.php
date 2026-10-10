<?php

namespace App\Contracts\Repositories\Analytics;

/**
 * 표 용량 Repository 인터페이스
 *
 * 방문자 통계 표가 디스크를 얼마나 쓰는지 데이터베이스에 묻습니다. 이 값은 행이
 * 아니라 저장소 자체의 상태라, 모델로는 읽을 수 없습니다.
 */
interface TableStatsRepositoryInterface
{
    /**
     * 지금 드라이버가 표 용량 통계를 제공하는지 확인합니다.
     *
     * @return bool 제공하면 true
     */
    public function supportsTableStats(): bool;

    /**
     * 표별 행 수와 크기를 읽습니다.
     *
     * 행 수는 데이터베이스가 들고 있는 어림값입니다 — 정확한 값이 필요한 곳은
     * 해당 표의 Repository 로 직접 셉니다.
     *
     * @param  array<int, string>  $tables  접두 없는 표 이름 목록
     * @return array<string, array{rows: int, data_bytes: int, index_bytes: int}> 표 이름으로 묶은 현황
     */
    public function sizes(array $tables): array;
}
