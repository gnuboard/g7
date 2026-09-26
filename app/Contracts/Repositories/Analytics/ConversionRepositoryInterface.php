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
     * 방문들의 차원 칸에서 지정한 이름을 지웁니다.
     *
     * 개인정보로 선언된 확장 차원을 소급 익명화 때 걷어 낼 때 씁니다. 데이터베이스마다 JSON
     * 함수가 달라 행을 읽어 고쳐 씁니다 — 익명화는 청크 단위 배치라 비용을 감당합니다.
     *
     * @param  array<int, int>  $visitIds  방문 ID 목록
     * @param  array<int, string>  $keys  지울 차원 이름
     * @return int 고친 행 수
     */
    public function forgetDimensionsForVisits(array $visitIds, array $keys): int;

    /**
     * 확장이 남긴 전환을 삭제합니다.
     *
     * 확장을 데이터까지 지우며 제거할 때 씁니다.
     *
     * @param  string  $extensionIdentifier  확장 식별자
     * @return int 삭제한 행 수
     */
    public function deleteForExtension(string $extensionIdentifier): int;

    /**
     * 같은 목표의 전환이 이미 있는지 확인합니다.
     *
     * 목표 선언의 `dedupe` 판정에 씁니다 — 방문 기준(`once_per_visit`)이면 방문 ID 로,
     * 방문자 기준(`once_per_visitor`)이면 방문자 키로 찾습니다.
     *
     * @param  string  $goalKey  정규화 목표 키
     * @param  int|null  $visitId  방문 ID (방문 기준일 때)
     * @param  string|null  $visitorKey  방문자 키 (방문자 기준일 때)
     * @return bool 이미 있으면 true
     */
    public function existsForGoal(string $goalKey, ?int $visitId = null, ?string $visitorKey = null): bool;

    /**
     * 기준 시각보다 오래된 전환 수를 셉니다.
     *
     * 마른 실행이 「무엇을 얼마나 지울지」를 미리 보이는 데 씁니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countOlderThan(Carbon $before): int;
}
