<?php

namespace App\Contracts\Repositories\Analytics;

use App\Models\Analytics\AnalyticsConversion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 전환 원장 Repository 인터페이스
 */
interface ConversionRepositoryInterface
{
    /**
     * 전환을 기록합니다.
     *
     * @param  array<string, mixed>  $attributes  전환 속성
     * @return AnalyticsConversion 생성된 전환
     */
    public function create(array $attributes): AnalyticsConversion;

    /**
     * 방문 목록에 속한 전환을 조회합니다.
     *
     * @param  array<int, int>  $visitIds  방문 ID 목록
     * @return Collection<int, AnalyticsConversion> 전환 목록
     */
    public function findForVisits(array $visitIds): Collection;

    /**
     * 보존기간이 지난 전환을 나눠서 삭제합니다.
     *
     * @param  Carbon  $before  이 시각 이전 전환이 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int;

    /**
     * 방문 ID 목록에 속한 전환을 삭제합니다.
     *
     * @param  array<int, int>  $visitIds  방문 ID 목록
     * @return int 삭제한 행 수
     */
    public function deleteForVisits(array $visitIds): int;

    /**
     * 확장이 남긴 전환을 삭제합니다.
     *
     * 확장을 데이터까지 지우며 제거할 때 씁니다.
     *
     * @param  string  $extensionIdentifier  확장 식별자
     * @return int 삭제한 행 수
     */
    public function deleteForExtension(string $extensionIdentifier): int;
}
