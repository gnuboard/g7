<?php

namespace App\Contracts\Repositories\Analytics;

use App\Models\Analytics\AnalyticsAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 액션 원장 Repository 인터페이스
 */
interface ActionRepositoryInterface
{
    /**
     * 액션을 기록합니다.
     *
     * @param  array<string, mixed>  $attributes  액션 속성
     * @return AnalyticsAction 생성된 액션
     */
    public function create(array $attributes): AnalyticsAction;

    /**
     * 액션을 갱신합니다.
     *
     * @param  AnalyticsAction  $action  대상 액션
     * @param  array<string, mixed>  $attributes  갱신할 속성
     * @return bool 갱신 성공 여부
     */
    public function update(AnalyticsAction $action, array $attributes): bool;

    /**
     * 액션 한 건을 지웁니다.
     *
     * 페이지 요청과 첫 비콘의 신원이 어긋났을 때, 서버가 다른 신원의 방문에 남긴 자리표시
     * 페이지뷰를 거둘 때 씁니다 — 같은 페이지는 비콘 쪽 방문에 한 번만 남아야 합니다.
     *
     * @param  AnalyticsAction  $action  대상 액션
     * @return bool 삭제 성공 여부
     */
    public function delete(AnalyticsAction $action): bool;

    /**
     * 한 방문의 모든 액션에 복제된 방문자 키를 바꿉니다.
     *
     * 서버 폴백의 임시 키 방문을 첫 비콘이 이어받을 때 씁니다 — 액션 행이 옛 키를
     * 들고 있으면 방문자별 조회가 그 액션을 잃습니다.
     *
     * @param  int  $visitId  방문 ID
     * @param  string  $visitorKey  새 방문자 키
     * @return int 갱신한 행 수
     */
    public function renameVisitorKey(int $visitId, string $visitorKey): int;

    /**
     * 방문의 가장 최근 액션을 조회합니다.
     *
     * 직전 페이지의 체류 시간을 확정하고 이탈 페이지를 갱신하는 데 씁니다.
     *
     * @param  int  $visitId  방문 ID
     * @param  string|null  $actionType  액션 종류로 좁히려면 지정
     * @return AnalyticsAction|null 최근 액션 (없으면 null)
     */
    public function findLatestForVisit(int $visitId, ?string $actionType = null): ?AnalyticsAction;

    /**
     * 서버 폴백으로 남긴 같은 URL 의 최근 액션을 찾습니다.
     *
     * 서버 폴백과 첫 비콘이 같은 페이지를 두 번 세지 않도록, 비콘이 도착하면 방금
     * 서버가 적은 행을 찾아 그 행을 채웁니다.
     *
     * @param  int  $visitId  방문 ID
     * @param  int  $actionNameId  이름 사전 ID
     * @param  Carbon  $since  이 시각 이후 액션만 대상
     * @return AnalyticsAction|null 병합 대상 액션 (없으면 null)
     */
    public function findRecentServerPageview(int $visitId, int $actionNameId, Carbon $since): ?AnalyticsAction;

    /**
     * 방문 목록에 속한 액션을 조회합니다.
     *
     * @param  array<int, int>  $visitIds  방문 ID 목록
     * @return Collection<int, AnalyticsAction> 액션 목록
     */
    public function findForVisits(array $visitIds): Collection;

    /**
     * 보존기간이 지난 액션을 나눠서 삭제합니다.
     *
     * @param  Carbon  $before  이 시각 이전 액션이 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int;

    /**
     * 방문 ID 목록에 속한 액션을 삭제합니다.
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
     * 액션 원장의 전체 행 수를 셉니다.
     *
     * @return int 행 수
     */
    public function countAll(): int;

    /**
     * 여러 방문의 액션을 방문별로 묶어 시간순으로 읽습니다.
     *
     * @param  array<int, int>  $visitIds  방문 ID 목록
     * @return Collection<int|string, Collection<int, AnalyticsAction>> 방문 ID 로 묶인 액션
     */
    public function groupedForVisits(array $visitIds): Collection;

    /**
     * 기준 시각보다 오래된 액션 수를 셉니다.
     *
     * 마른 실행이 「무엇을 얼마나 지울지」를 미리 보이는 데 씁니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countOlderThan(Carbon $before): int;
}
