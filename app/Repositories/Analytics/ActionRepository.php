<?php

namespace App\Repositories\Analytics;

use App\Contracts\Repositories\Analytics\ActionRepositoryInterface;
use App\Enums\Analytics\ActionType;
use App\Models\Analytics\AnalyticsAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 액션 원장 Repository
 */
class ActionRepository implements ActionRepositoryInterface
{
    /**
     * 액션을 기록합니다.
     *
     * @param  array<string, mixed>  $attributes  액션 속성
     * @return AnalyticsAction 생성된 액션
     */
    public function create(array $attributes): AnalyticsAction
    {
        return AnalyticsAction::query()->create($attributes);
    }

    /**
     * 액션을 갱신합니다.
     *
     * @param  AnalyticsAction  $action  대상 액션
     * @param  array<string, mixed>  $attributes  갱신할 속성
     * @return bool 갱신 성공 여부
     */
    public function update(AnalyticsAction $action, array $attributes): bool
    {
        return $action->fill($attributes)->save();
    }

    /**
     * 한 방문의 모든 액션에 복제된 방문자 키를 바꿉니다.
     *
     * @param  int  $visitId  방문 ID
     * @param  string  $visitorKey  새 방문자 키
     * @return int 갱신한 행 수
     */
    public function renameVisitorKey(int $visitId, string $visitorKey): int
    {
        return AnalyticsAction::query()
            ->where('visit_id', $visitId)
            ->update(['visitor_key' => $visitorKey]);
    }

    /**
     * 방문의 가장 최근 액션을 조회합니다.
     *
     * @param  int  $visitId  방문 ID
     * @param  string|null  $actionType  액션 종류로 좁히려면 지정
     * @return AnalyticsAction|null 최근 액션 (없으면 null)
     */
    public function findLatestForVisit(int $visitId, ?string $actionType = null): ?AnalyticsAction
    {
        return AnalyticsAction::query()
            ->where('visit_id', $visitId)
            ->when($actionType !== null, fn ($q) => $q->where('action_type', $actionType))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 서버 폴백으로 남긴 같은 URL 의 최근 액션을 찾습니다.
     *
     * 서버 폴백이 먼저 페이지뷰를 적고 곧이어 브라우저 비콘이 같은 페이지를 알리면,
     * 새 행을 만들 경우 페이지뷰가 두 배가 됩니다. 방금 서버가 적은 행을 찾아
     * 그 행을 비콘 정보로 채우는 것이 이 조회의 목적입니다.
     *
     * @param  int  $visitId  방문 ID
     * @param  int  $actionNameId  이름 사전 ID
     * @param  Carbon  $since  이 시각 이후 액션만 대상
     * @return AnalyticsAction|null 병합 대상 액션 (없으면 null)
     */
    public function findRecentServerPageview(int $visitId, int $actionNameId, Carbon $since): ?AnalyticsAction
    {
        return AnalyticsAction::query()
            ->where('visit_id', $visitId)
            ->where('action_name_id', $actionNameId)
            ->where('action_type', ActionType::Pageview->value)
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 방문 목록에 속한 액션을 조회합니다.
     *
     * @param  array<int, int>  $visitIds  방문 ID 목록
     * @return Collection<int, AnalyticsAction> 액션 목록
     */
    public function findForVisits(array $visitIds): Collection
    {
        if ($visitIds === []) {
            return new Collection;
        }

        return AnalyticsAction::query()
            ->whereIn('visit_id', $visitIds)
            ->orderBy('visit_id')
            ->orderBy('occurred_at')
            ->get();
    }

    /**
     * 보존기간이 지난 액션을 나눠서 삭제합니다.
     *
     * @param  Carbon  $before  이 시각 이전 액션이 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int
    {
        $deleted = 0;

        do {
            $count = AnalyticsAction::query()
                ->where('occurred_at', '<', $before)
                ->limit($chunkSize)
                ->delete();

            $deleted += $count;
        } while ($count > 0);

        return $deleted;
    }

    /**
     * 방문 ID 목록에 속한 액션을 삭제합니다.
     *
     * @param  array<int, int>  $visitIds  방문 ID 목록
     * @return int 삭제한 행 수
     */
    public function deleteForVisits(array $visitIds): int
    {
        if ($visitIds === []) {
            return 0;
        }

        return AnalyticsAction::query()->whereIn('visit_id', $visitIds)->delete();
    }

    /**
     * 액션 원장의 전체 행 수를 셉니다.
     *
     * @return int 행 수
     */
    public function countAll(): int
    {
        return AnalyticsAction::query()->count();
    }
}
